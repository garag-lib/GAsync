<?php

declare(strict_types=1);

namespace GaragLib\MysqliPDO;

/** Lazy PDO facade plus an explicitly independent asynchronous MySQLi session. */
class MysqliPDO
{
    private ?\PDO $pdo = null;
    private ?\mysqli $mysqli = null;
    private bool $pending = false;
    private mixed $semaphore = null;
    private int $leases = 0;
    private int $attempts;
    private ?int $ownerPid = null;

    public function __construct(
        private string $dbhost,
        private int $dbport,
        private string $dbuser,
        private string $dbpass,
        private string $dbname,
        ?int $maxIntentos = null,
        private ?int $maxConnections = null,
        private array $pdoOptions = [],
    ) {
        if ($dbport < 1 || $dbport > 65535 || strpbrk($dbhost . $dbname, ";\0") !== false) {
            throw new \InvalidArgumentException('Invalid database host, port or database name');
        }
        if (($maxIntentos !== null && $maxIntentos < 0) || ($maxConnections !== null && $maxConnections < 0)) {
            throw new \InvalidArgumentException('Connection limits and retry counts must be nonnegative');
        }
        $this->attempts = max(1, $maxIntentos ?? 1);
    }

    public function __destruct()
    {
        // Destructors must not hide the original exception during stack unwinding.
        try {
            $this->close();
        } catch (\Throwable) {
        }
    }

    private function checkProcess(): void
    {
        if ($this->ownerPid !== null && $this->ownerPid !== getmypid()) {
            throw new \LogicException('Database connections cannot be reused after fork; create a new facade in the child');
        }
    }

    private function acquire(): void
    {
        $this->checkProcess();
        if ($this->maxConnections) {
            if (!extension_loaded('sysvsem')) {
                throw new \RuntimeException('The sysvsem extension is required for maxConnections');
            }
            if ($this->semaphore === null) {
                $pool = $this->dbhost . ':' . $this->dbport . '/' . $this->dbname . '/' . $this->dbuser;
                $key = (int) (crc32('GAsync:' . $pool) & 0x7fffffff) ?: 1;
                $this->semaphore = sem_get($key, $this->maxConnections, 0600, false);
                if ($this->semaphore === false) {
                    $this->semaphore = null;
                    throw new \RuntimeException('Cannot create connection semaphore');
                }
            }
            if (!sem_acquire($this->semaphore, true)) {
                throw new \RuntimeException('Database connection limit reached');
            }
        }
        $this->ownerPid = getmypid();
        ++$this->leases;
    }

    private function release(): void
    {
        if ($this->leases <= 0) {
            return;
        }
        if ($this->maxConnections && $this->semaphore !== null) {
            sem_release($this->semaphore);
        }
        --$this->leases;
    }

    private function open(callable $connect): mixed
    {
        for ($attempt = 1; ; ++$attempt) {
            $this->acquire();
            try {
                return $connect();
            } catch (\Throwable $error) {
                $this->release();
                if ($attempt >= $this->attempts) {
                    throw $error;
                }
                sleep(1);
            }
        }
    }

    public function connect(): void
    {
        $this->checkProcess();
        if ($this->pdo !== null) {
            return;
        }
        if (!extension_loaded('pdo_mysql')) {
            throw new \RuntimeException('The pdo_mysql extension is required');
        }
        $this->pdo = $this->open(fn () => new \PDO(
            "mysql:host={$this->dbhost};port={$this->dbport};dbname={$this->dbname};charset=utf8mb4",
            $this->dbuser,
            $this->dbpass,
            $this->pdoOptions + [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC],
        ));
    }

    public function getPdo(): \PDO
    {
        $this->connect();
        return $this->pdo;
    }

    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        return $this->getPdo()->prepare($query, $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        return $this->getPdo()->query($query, $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $query): int|false
    {
        return $this->getPdo()->exec($query);
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->getPdo()->lastInsertId($name);
    }

    public function beginTransaction(): bool
    {
        return $this->getPdo()->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->getPdo()->commit();
    }

    public function rollBack(): bool
    {
        return $this->getPdo()->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->getPdo()->inTransaction();
    }

    public function errorCode(): ?string
    {
        return $this->getPdo()->errorCode();
    }

    public function errorInfo(): array
    {
        return $this->getPdo()->errorInfo();
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        return $this->getPdo()->setAttribute($attribute, $value);
    }

    public function getAttribute(int $attribute): mixed
    {
        return $this->getPdo()->getAttribute($attribute);
    }

    public function quote(string $string, int $type = \PDO::PARAM_STR): string|false
    {
        return $this->getPdo()->quote($string, $type);
    }

    public static function getAvailableDrivers(): array
    {
        return \PDO::getAvailableDrivers();
    }

    private function connectAsync(): void
    {
        $this->checkProcess();
        if ($this->mysqli !== null) {
            return;
        }
        if (!extension_loaded('mysqli') || !extension_loaded('mysqlnd')) {
            throw new \RuntimeException('Asynchronous queries require mysqli with mysqlnd');
        }
        $this->mysqli = $this->open(function (): \mysqli {
            $connection = new \mysqli();
            try {
                if (!$connection->real_connect($this->dbhost, $this->dbuser, $this->dbpass, $this->dbname, $this->dbport)) {
                    throw new \RuntimeException('MySQLi connection failed: ' . $connection->connect_error);
                }
                if (!$connection->set_charset('utf8mb4')) {
                    throw new \RuntimeException('Cannot set MySQLi charset');
                }
                return $connection;
            } catch (\Throwable $error) {
                $connection->close();
                throw $error;
            }
        });
    }

    public function asyncQuery(string $query): void
    {
        if ($this->pending) {
            throw new \LogicException('An asynchronous query is already pending');
        }
        $this->connectAsync();
        if (!$this->mysqli->query($query, MYSQLI_ASYNC)) {
            throw new \RuntimeException('Async query failed: ' . $this->mysqli->error);
        }
        $this->pending = true;
    }

    public function fetchAsyncResults(): \mysqli_result|bool
    {
        $this->checkProcess();
        if (!$this->pending) {
            return false;
        }
        $read = $error = $reject = [$this->mysqli];
        try {
            $ready = \mysqli::poll($read, $error, $reject, 0, 0);
            if ($ready === false) {
                throw new \RuntimeException('MySQLi polling failed');
            }
            if ($reject !== []) {
                throw new \RuntimeException('Pending MySQLi query was rejected');
            }
            if ($ready === 0) {
                return false;
            }
            $this->pending = false;
            $result = $this->mysqli->reap_async_query();
            if ($result === false) {
                throw new \RuntimeException('Async query failed: ' . $this->mysqli->error);
            }
            return $result;
        } catch (\Throwable $failure) {
            $this->closeAsync();
            throw $failure;
        }
    }

    private function closeAsync(): void
    {
        if ($this->mysqli !== null) {
            try {
                $this->mysqli->close();
            } finally {
                $this->mysqli = null;
                $this->pending = false;
                $this->release();
            }
        }
    }

    public function close(): void
    {
        // An inherited facade must never release semaphore slots owned by its parent.
        if ($this->ownerPid !== null && $this->ownerPid !== getmypid()) {
            return;
        }
        try {
            $this->closeAsync();
        } finally {
            if ($this->pdo !== null) {
                $this->pdo = null;
                $this->release();
            }
        }
    }
}

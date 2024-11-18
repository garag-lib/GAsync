<?php

namespace GaragLib\MysqliPDO;

class MysqliPDO {

    private $mysqli = null;
    private $dbhost;
    private $dbport;
    private $dbuser;
    private $dbpass;
    private $dbname;
    private $maxIntentos;
    private $maxConnections;
    private $semKey;

    public function __construct($dbhost, $dbport, $dbuser, $dbpass, $dbname, $maxIntentos = null, $maxConnections = null) {
        $this->dbhost = $dbhost;
        $this->dbport = $dbport;
        $this->dbuser = $dbuser;
        $this->dbpass = $dbpass;
        $this->dbname = $dbname;
        $this->maxIntentos = $maxIntentos;
        $this->maxConnections = $maxConnections;
        $this->semKey = ftok(__FILE__, 'p'); // Genera una clave única basada en el archivo y un proyecto específico
    }

    public function __destruct() {
        $this->close();
    }

    public function close() {
        if ($this->mysqli) {
            $this->mysqli->close();
            $this->mysqli = null;
            $this->unlook();
        }
    }

    private function look() {
        if ($this->maxConnections) {
            $semId = sem_get($this->semKey, $this->maxConnections, 0666, 1);
            if (!$semId) {
                throw new \Exception('Failed to get semaphore');
            }
            if (!sem_acquire($semId, true)) { // Adquiere el semáforo de manera no bloqueante
                return false;
            }
            return true;
        }
        return true;
    }

    private function unlook() {
        if ($this->maxConnections) {
            $semId = sem_get($this->semKey);
            if (!$semId) {
                throw new \Exception('Failed to get semaphore');
            }
            sem_release($semId); // Libera el semáforo
        }
    }

    public function connect() {
        if (!is_null($this->mysqli)) {
            return;
        }
        $intentoActual = 0;
        do {
            try {
                if (!$this->look()) {
                    throw new \Exception('Max connections reached');
                }
                $this->mysqli = new \mysqli($this->dbhost, $this->dbuser, $this->dbpass, $this->dbname, $this->dbport);
                if ($this->mysqli->connect_error) {
                    throw new \Exception("Connect Error ({$this->mysqli->connect_errno}) {$this->mysqli->connect_error}");
                }
                $this->mysqli->set_charset("utf8mb4");
                return; // Conexión exitosa, salir del bucle
            } catch (\Exception $e) {
                $this->mysqli = null;
                $this->unlook(); // Asegurar la liberación del semáforo si hay un error en la conexión
                if ($this->maxIntentos) {
                    $intentoActual++;
                    if ($intentoActual >= $this->maxIntentos) {
                        throw $e;
                    }
                    sleep(1);
                } else {
                    throw $e;
                }
            }
        } while (is_null($this->mysqli));
    }

    public function prepare($query) {
        $this->connect();
        $stmt = $this->mysqli->prepare($query);
        if ($stmt === false) {
            throw new \Exception("Prepare failed: {$this->mysqli->error}");
        }
        return new MysqliStatementPDO($stmt, true);
    }

    public function query($query, $fetchMode = \PDO::FETCH_ASSOC, $classnameOrFunc = null, $ctorargs = []) {
        $this->connect();
        $result = $this->mysqli->query($query);
        if ($result === false) {
            throw new \Exception("Query failed: {$this->mysqli->error}");
        }
        return new MysqliStatementPDO($result, false, $fetchMode, $classnameOrFunc, $ctorargs);
    }

    public function exec($query) {
        $this->connect();
        if ($this->mysqli->query($query) === false) {
            throw new \Exception("Exec failed: {$this->mysqli->error}");
        }
        return $this->mysqli->affected_rows;
    }

    public function lastInsertId() {
        $this->connect();
        return $this->mysqli->insert_id;
    }

    public function beginTransaction() {
        $this->connect();
        $this->mysqli->begin_transaction();
    }

    public function commit() {
        $this->connect();
        $this->mysqli->commit();
    }

    public function rollBack() {
        $this->connect();
        $this->mysqli->rollback();
    }

    public function errorCode() {
        return $this->mysqli->errno;
    }

    public function errorInfo() {
        return [$this->mysqli->errno, $this->mysqli->sqlstate, $this->mysqli->error];
    }

    public function setAttribute($attribute, $value) {
        $this->connect();
        return $this->mysqli->options($attribute, $value);
    }

    public function getAttribute($attribute) {
        $this->connect();
        switch ($attribute) {
            case \PDO::ATTR_SERVER_VERSION:
                return $this->mysqli->server_version;
            case \PDO::ATTR_CLIENT_VERSION:
                return $this->mysqli->client_version;
            case \PDO::ATTR_SERVER_INFO:
                return $this->mysqli->server_info;
            case \PDO::ATTR_PERSISTENT:
                return false;
            default:
                return null;
        }
    }

    public function asyncQuery($query) {
        $this->connect();
        if (!$this->mysqli->query($query, MYSQLI_ASYNC)) {
            throw new \Exception("Async query failed: {$this->mysqli->error}");
        }
        $this->asyncQueries[] = $this->mysqli;
    }

    public function fetchAsyncResults() {
        if (empty($this->asyncQueries)) {
            return false;
        }
        $links = $this->asyncQueries;
        $errors = $reject = [];
        if (!mysqli_poll($links, $errors, $reject, 1)) {
            return false;
        }
        foreach ($links as $link) {
            $result = $link->reap_async_query();
            if ($result === false) {
                throw new \Exception("Reap async query failed: {$link->error}");
            }
            $this->asyncQueries = array_filter($this->asyncQueries, function ($item) use ($link) {
                return $item !== $link;
            });
            return new MysqliStatementPDO($result, false);
        }
        return false;
    }
}

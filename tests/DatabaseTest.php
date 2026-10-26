<?php

declare(strict_types=1);

use GaragLib\MysqliPDO\MysqliPDO;
use PHPUnit\Framework\TestCase;

final class FetchTarget
{
    public int $n;
    public string $label;
}

final class DatabaseTest extends TestCase
{
    private function database(?int $limit = null, array $options = []): MysqliPDO
    {
        if (!getenv('GASYNC_MYSQL_PORT')) {
            self::markTestSkipped('Set GASYNC_MYSQL_PORT to enable MySQL integration tests');
        }
        return new MysqliPDO('127.0.0.1', (int) getenv('GASYNC_MYSQL_PORT'), getenv('GASYNC_MYSQL_USER') ?: 'root', getenv('GASYNC_MYSQL_PASSWORD') ?: '', 'gasync', 1, $limit, $options);
    }

    public function testLoadingDatabaseClassesHasNoSideEffects(): void
    {
        self::assertTrue(class_exists(MysqliPDO::class));
        self::assertFalse(class_exists('GaragLib\\MysqliPDO\\MysqliStatementPDO'));
    }

    public function testNativePdoStatementsBindingsAndFetchModes(): void
    {
        $db = $this->database();
        try {
            self::assertInstanceOf(PDO::class, $db->getPdo());
            $stmt = $db->prepare('SELECT ? AS n, ? AS label');
            self::assertInstanceOf(PDOStatement::class, $stmt);
            $number = 2;
            $stmt->bindParam(1, $number, PDO::PARAM_INT);
            $stmt->bindValue(2, 'hello');
            $number = 7;
            self::assertTrue($stmt->execute());
            self::assertSame(['n' => 7, 'label' => 'hello'], $stmt->fetch(PDO::FETCH_ASSOC));
            self::assertFalse($stmt->fetch());
            self::assertTrue($stmt->execute([3, 'second']));
            self::assertSame(['n' => '3', 'label' => 'second'], $stmt->fetch(PDO::FETCH_ASSOC));
            $sql = "SELECT 1 AS n, 'first' AS label UNION ALL SELECT 2, 'second'";
            foreach ([PDO::FETCH_ASSOC, PDO::FETCH_NUM, PDO::FETCH_BOTH, PDO::FETCH_OBJ, PDO::FETCH_NAMED, PDO::FETCH_KEY_PAIR, PDO::FETCH_GROUP | PDO::FETCH_ASSOC, PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC] as $mode) {
                self::assertEquals($db->getPdo()->query($sql)->fetchAll($mode), $db->query($sql)->fetchAll($mode));
            }
            self::assertSame([1, 2], $db->query($sql)->fetchAll(PDO::FETCH_COLUMN));
            self::assertSame(['first', 'second'], $db->query($sql, PDO::FETCH_COLUMN, 1)->fetchAll());
            $target = $db->query($sql)->fetchAll(PDO::FETCH_CLASS, FetchTarget::class);
            self::assertInstanceOf(FetchTarget::class, $target[0]);
            self::assertSame(1, $target[0]->n);
            self::assertSame(['1:first', '2:second'], $db->query($sql)->fetchAll(PDO::FETCH_FUNC, static fn ($n, $label) => "$n:$label"));
            self::assertSame($db->getPdo()->quote("a'b"), $db->quote("a'b"));
            self::assertSame($db->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION), $db->getAttribute(PDO::ATTR_SERVER_VERSION));
        } finally {
            $db->close();
        }
    }

    public function testTransactionsAndErrorsArePdoNative(): void
    {
        $db = $this->database();
        try {
            $db->exec('CREATE TEMPORARY TABLE gasync_test (id INT PRIMARY KEY) ENGINE=InnoDB');
            self::assertTrue($db->beginTransaction());
            self::assertTrue($db->inTransaction());
            $db->exec('INSERT INTO gasync_test VALUES (1)');
            self::assertTrue($db->rollBack());
            self::assertSame(0, $db->query('SELECT COUNT(*) FROM gasync_test')->fetchColumn());
            self::assertTrue($db->beginTransaction());
            $db->exec('INSERT INTO gasync_test VALUES (2)');
            self::assertTrue($db->commit());
            self::assertSame(1, $db->query('SELECT COUNT(*) FROM gasync_test')->fetchColumn());
            try {
                $db->query('SELECT * FROM gasync_missing_table');
                self::fail('Query should fail');
            } catch (PDOException $e) {
                self::assertSame('42S02', $e->errorInfo[0]);
            }
            self::assertSame('42S02', $db->errorCode());
            self::assertSame('42S02', $db->errorInfo()[0]);
        } finally {
            $db->close();
        }
    }

    public function testAsyncPollingIsolationAndNoRowResults(): void
    {
        if (!extension_loaded('mysqli')) {
            self::markTestSkipped('mysqli is required');
        }
        $db = $this->database();
        try {
            $pdoId = (int) $db->query('SELECT CONNECTION_ID()')->fetchColumn();
            // Hold a session lock so readiness does not depend on a short sleep.
            $lock = $db->quote('gasync-poll-' . getmypid());
            $db->query("SELECT GET_LOCK($lock, 5)")->fetchColumn();
            $db->asyncQuery("SELECT GET_LOCK($lock, 5), CONNECTION_ID() AS id");
            $start = hrtime(true);
            self::assertFalse($db->fetchAsyncResults());
            self::assertLessThan(0.5, (hrtime(true) - $start) / 1e9);
            try {
                $db->asyncQuery('SELECT 2');
                self::fail('Concurrent query should be rejected');
            } catch (LogicException) {
                self::assertTrue(true);
            }
            $db->query("SELECT RELEASE_LOCK($lock)")->fetchColumn();
            $deadline = microtime(true) + 2;
            do {
                $result = $db->fetchAsyncResults();
                if ($result === false) {
                    usleep(1000);
                }
            } while ($result === false && microtime(true) < $deadline);
            self::assertInstanceOf(mysqli_result::class, $result);
            self::assertNotSame($pdoId, (int) $result->fetch_assoc()['id']);
            $result->free();
            $db->asyncQuery('SET @gasync_async = 42');
            do {
                $result = $db->fetchAsyncResults();
                if ($result === false) {
                    usleep(1000);
                }
            } while ($result === false && microtime(true) < $deadline);
            self::assertTrue($result);
            self::assertNull($db->query('SELECT @gasync_async')->fetchColumn());
        } finally {
            $db->close();
        }
    }

    public function testBothConnectionsConsumeTheLimitAndReleaseIt(): void
    {
        if (!extension_loaded('sysvsem') || !extension_loaded('mysqli')) {
            self::markTestSkipped('sysvsem and mysqli are required');
        }
        $db = $this->database(1);
        try {
            $db->connect();
            try {
                $db->asyncQuery('SELECT 1');
                self::fail('Limit should apply to both connections');
            } catch (RuntimeException $e) {
                self::assertStringContainsString('limit', $e->getMessage());
            }
            $db->close();
            $db->asyncQuery('SELECT 1');
            self::assertTrue(true);
        } finally {
            $db->close();
        }
    }

    public function testAsyncFailureReleasesItsConnectionLease(): void
    {
        if (!extension_loaded('sysvsem') || !extension_loaded('mysqli')) {
            self::markTestSkipped('sysvsem and mysqli are required');
        }
        $db = $this->database(1);
        try {
            $db->asyncQuery('SELECT * FROM gasync_missing_table');
            $deadline = hrtime(true) + 2000000000;
            try {
                while ($db->fetchAsyncResults() === false) {
                    if (hrtime(true) >= $deadline) {
                        self::fail('Query stalled');
                    }
                    usleep(1000);
                }
                self::fail('Query should fail');
            } catch (mysqli_sql_exception $error) {
                self::assertSame('42S02', $error->getSqlState());
            }
            // A new PDO connection must fit in the one-slot pool after the failure.
            self::assertSame(42, $db->query('SELECT 42')->fetchColumn());
        } finally {
            $db->close();
        }
    }
}

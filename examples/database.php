<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GaragLib\GAsync\GAsync;
use GaragLib\MysqliPDO\MysqliPDO;

if (!getenv('GASYNC_MYSQL_USER') || !getenv('GASYNC_MYSQL_DATABASE')) {
    throw new RuntimeException('Set GASYNC_MYSQL_USER and GASYNC_MYSQL_DATABASE (optionally HOST, PORT and PASSWORD)');
}
$db = new MysqliPDO(
    getenv('GASYNC_MYSQL_HOST') ?: '127.0.0.1',
    (int) (getenv('GASYNC_MYSQL_PORT') ?: 3306),
    getenv('GASYNC_MYSQL_USER'),
    getenv('GASYNC_MYSQL_PASSWORD') ?: '',
    getenv('GASYNC_MYSQL_DATABASE'),
);
GAsync::init(getenv('GASYNC_DRIVER') ?: 'GStream');
try {
    $statement = $db->prepare('SELECT ? AS value');
    $statement->execute(['native PDO']);
    echo $statement->fetchColumn(), "\n";
    GAsync::async(static function () use ($db): void {
        $db->asyncQuery('SELECT 42 AS value');
        $deadline = hrtime(true) + 2000000000;
        while (($result = $db->fetchAsyncResults()) === false) {
            if (hrtime(true) >= $deadline) {
                throw new RuntimeException('Async query timed out');
            }
            GAsync::msleep(1);
        }
        if ($result instanceof mysqli_result) {
            echo $result->fetch_assoc()['value'], "\n";
            $result->free();
        }
    });
    GAsync::run();
} finally {
    $db->close();
}

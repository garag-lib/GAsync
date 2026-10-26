<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GaragLib\GAsync\GAsync;
use GaragLib\GAsync\GTimer;
use GaragLib\GSocketManager\GSocketManager;

GAsync::init(getenv('GASYNC_DRIVER') ?: 'GStream');
$manager = new GSocketManager();
$port = 0;
$manager->createServerSocket('127.0.0.1', $port);
$watchdog = new GTimer(2000000, false, static function () use ($manager): void {
    $manager->end();
    throw new RuntimeException('Example timed out');
});
$manager->setOnConnectCallback(static function ($socket) use ($manager): void {
    $manager->send($socket, 'hello');
});
$manager->setOnReceiveCallback(static function ($socket) use ($manager, $watchdog): void {
    echo $manager->read($socket), "\n";
    $watchdog->clear();
    $manager->end();
});
$manager->createClientSocket('127.0.0.1', $port);
GAsync::run();

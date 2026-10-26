<?php

declare(strict_types=1);

use GaragLib\GAsync\GAsync;
use GaragLib\GAsync\GTimer;
use GaragLib\GSocketManager\GSocketManager;
use PHPUnit\Framework\TestCase;

final class SocketTest extends TestCase
{
    protected function setUp(): void
    {
        GAsync::init(getenv('GASYNC_DRIVER') ?: 'GStream');
    }

    public function testPartialWritesPreservePayloadAndDisconnectResource(): void
    {
        [$left, $right] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $manager = new GSocketManager(10);
        $manager->addSocket($left);
        $payload = str_repeat('abcdefgh', 262144);
        $received = '';
        $disconnected = 0;
        $manager->setOnDisconnectCallback(function ($stream) use (&$disconnected) {
            self::assertIsResource($stream);
            ++$disconnected;
        });
        $manager->send($left, $payload);
        $manager->close($left);
        $manager->end();
        GAsync::async(function () use ($right, &$received) {
            while (($data = GAsync::fread($right, 65536)) !== '') {
                $received .= $data;
            }
            GAsync::fclose($right);
        });
        $watchdog = new GTimer(3000000, false, static function () {
            throw new RuntimeException('socket test timed out');
        });
        $done = new GTimer(1000, true, function (GTimer $timer) use (&$disconnected, &$received, $payload, $watchdog) {
            if ($disconnected === 1 && $received === $payload) {
                $timer->clear();
                $watchdog->clear();
            }
        });
        GAsync::run(0, 10000);
        self::assertSame($payload, $received);
        self::assertSame(1, $disconnected);
    }

    public function testUdpKeepsEmptyAndSeparateDatagrams(): void
    {
        $manager = new GSocketManager(10);
        $port = 0;
        $server = $manager->createServerSocket('127.0.0.1', $port, 'udp');
        self::assertGreaterThan(0, $port);
        $client = $manager->createClientSocket('127.0.0.1', $port, 'udp');
        $messages = [];
        $watchdog = new GTimer(1000000, false, static function () {
            throw new RuntimeException('UDP test timed out');
        });
        $manager->setOnReceiveCallback(function ($socket, $data, $peer) use ($manager, &$messages, $watchdog) {
            $messages[] = $data;
            self::assertNotEmpty($peer);
            if (count($messages) === 3) {
                $watchdog->clear();
                $manager->end();
            }
        });
        $manager->send($client, '');
        $manager->send($client, 'one');
        $manager->send($client, 'two');
        GAsync::run(0, 10000);
        self::assertSame(['', 'one', 'two'], $messages);
        self::assertFalse(is_resource($server));
    }

    public function testTcpConnectAcceptAndRead(): void
    {
        $manager = new GSocketManager(10);
        $port = 0;
        $manager->createServerSocket('127.0.0.1', $port);
        $connected = $accepted = false;
        $watchdog = new GTimer(1000000, false, static function () {
            throw new RuntimeException('TCP test timed out');
        });
        $manager->setOnAcceptCallback(function ($socket) use (&$accepted) {
            self::assertFalse(stream_get_meta_data($socket)['blocked']);
            $accepted = true;
        });
        $manager->setOnConnectCallback(function ($socket) use ($manager, &$connected) {
            $connected = true;
            $manager->send($socket, 'hello');
        });
        $manager->setOnReceiveCallback(function ($socket) use ($manager, $watchdog) {
            self::assertSame('hello', $manager->read($socket));
            $watchdog->clear();
            $manager->end();
        });
        $manager->createClientSocket('127.0.0.1', $port);
        GAsync::run(0, 10000);
        self::assertTrue($connected);
        self::assertTrue($accepted);
    }

    public function testRefusedConnectionIsReportedAndClosed(): void
    {
        $reservation = stream_socket_server('tcp://127.0.0.1:0');
        $address = stream_socket_get_name($reservation, false);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        fclose($reservation);
        $manager = new GSocketManager(5);
        $errors = $disconnections = 0;
        $watchdog = new GTimer(1000000, false, static function () {
            throw new RuntimeException('Refused connection stalled');
        });
        $manager->setOnErrorCallback(function ($socket, Throwable $error) use (&$errors) {
            self::assertIsResource($socket);
            self::assertNotSame('', $error->getMessage());
            ++$errors;
        });
        $manager->setOnDisconnectCallback(function ($socket) use ($manager, $watchdog, &$disconnections) {
            self::assertIsResource($socket);
            ++$disconnections;
            $watchdog->clear();
            $manager->end();
        });
        $manager->createClientSocket('127.0.0.1', $port, 'tcp', 0.1);
        GAsync::run(0, 10000);
        self::assertSame(1, $errors);
        self::assertSame(1, $disconnections);
    }

    public function testRepeatedSocketCreationDoesNotLeakDescriptors(): void
    {
        if (!is_dir('/proc/self/fd')) {
            self::markTestSkipped('Linux procfs required');
        }
        $before = count(scandir('/proc/self/fd'));
        for ($i = 0; $i < 20; ++$i) {
            $manager = new GSocketManager();
            $port = 0;
            $socket = $manager->createServerSocket('127.0.0.1', $port);
            $manager->end();
            GAsync::run(0, 1000);
            self::assertFalse(is_resource($socket));
        }
        gc_collect_cycles();
        self::assertLessThanOrEqual($before + 1, count(scandir('/proc/self/fd')));
    }

    public function testConnectionDeadlineIsEnforced(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($server, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        $manager = new GSocketManager();
        $error = null;
        $manager->setOnErrorCallback(function ($socket, Throwable $failure) use (&$error) {
            $error = $failure;
        });
        $manager->createClientSocket('127.0.0.1', $port, 'tcp', 0.000000001);
        GAsync::run(0, 1000);
        self::assertInstanceOf(RuntimeException::class, $error);
        self::assertStringContainsString('timed out', $error->getMessage());
        fclose($server);
    }
}

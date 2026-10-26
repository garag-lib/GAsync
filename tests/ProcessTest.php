<?php

declare(strict_types=1);

use GaragLib\GAsync\GAsync;
use GaragLib\GForkManager\GForkManager;
use GaragLib\GForkManager\GWorker;
use PHPUnit\Framework\TestCase;

final class SuccessfulWorker extends GWorker
{
    public function run()
    {
        $this->smngr->send($this->root_sock, 'worked');
    }
}
final class FailingWorker extends GWorker
{
    public function run()
    {
        throw new RuntimeException('worker failed');
    }
}

final class SlowWorker extends GWorker
{
    public function run()
    {
        sleep(5);
    }
}

final class ProcessTest extends TestCase
{
    public function testWorkersAreBoundedReportedAndReaped(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_getpid')) {
            self::markTestSkipped('pcntl and posix are required');
        }
        GAsync::init(getenv('GASYNC_DRIVER') ?: 'GStream');
        $manager = new GForkManager(2, 5);
        $manager->add(SuccessfulWorker::class);
        $manager->add(FailingWorker::class);
        $manager->add(SuccessfulWorker::class);
        $messages = [];
        $exits = [];
        $errors = [];
        $manager->setCallback('receive', function ($pid, $socket, $data) use (&$messages) {
            $messages[$pid] = ($messages[$pid] ?? '') . $data;
        });
        $manager->setCallback('error', function ($pid, Throwable $error) use (&$errors) {
            $errors[$pid] = $error->getMessage();
        });
        $manager->setCallback('exit', function ($pid, $code, $signal) use (&$exits) {
            $exits[$pid] = [$code, $signal];
        });
        $initial = $manager->run();
        self::assertCount(2, $initial);
        $manager->wait(0, 10000);
        self::assertCount(3, $exits);
        self::assertCount(1, $errors);
        self::assertStringContainsString('worker failed', array_values($errors)[0]);
        $codes = array_column($exits, 0);
        sort($codes);
        self::assertSame([0, 0, 1], $codes);
        self::assertCount(2, array_filter($messages, static fn ($value) => $value === 'worked'));
        foreach ($exits as $pid => $status) {
            self::assertSame(-1, pcntl_waitpid($pid, $unused, WNOHANG));
        }
        self::assertSame([], $manager->run());
    }

    public function testInvalidWorkerAndLimitAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GForkManager(0);
    }

    public function testCallbackFailureTerminatesAndReapsOwnedChildren(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_getpid')) {
            self::markTestSkipped('pcntl and posix are required');
        }
        GAsync::init(getenv('GASYNC_DRIVER') ?: 'GStream');
        $manager = new GForkManager(2);
        $manager->add(SlowWorker::class);
        $manager->add(SlowWorker::class);
        $manager->setCallback('connect', static function () {
            throw new RuntimeException('parent callback error');
        });
        $pids = array_keys($manager->run());
        try {
            $manager->wait();
            self::fail('Callback should fail');
        } catch (RuntimeException $error) {
            self::assertSame('parent callback error', $error->getMessage());
        }
        foreach ($pids as $pid) {
            self::assertSame(-1, pcntl_waitpid($pid, $status, WNOHANG));
        }
        // Scheduled events belonging to closed sockets must be harmless.
        GAsync::run(0, 0);
    }
}

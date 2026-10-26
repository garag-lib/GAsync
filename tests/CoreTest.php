<?php

declare(strict_types=1);

use GaragLib\GAsync\GAsync;
use GaragLib\GAsync\GTimer;
use PHPUnit\Framework\TestCase;

final class CoreTest extends TestCase
{
    protected function setUp(): void
    {
        GAsync::init(getenv('GASYNC_DRIVER') ?: 'GStream');
    }

    public function testRunCanBeRepeatedAndNestedTasksComplete(): void
    {
        $seen = [];
        GAsync::async(function () use (&$seen) {
            $seen[] = 1;
            GAsync::async(function () use (&$seen) {
                $seen[] = 2;
            }, false);
        });
        GAsync::run(0, 1000);
        GAsync::async(function () use (&$seen) {
            $seen[] = 3;
        });
        GAsync::run(0, 1000);
        self::assertSame([1, 2, 3], $seen);
    }

    public function testExceptionDoesNotRetainFiber(): void
    {
        GAsync::async(static function () {
            throw new RuntimeException('task error');
        });
        try {
            GAsync::run(0, 1000);
            self::fail('Exception was swallowed');
        } catch (RuntimeException $e) {
            self::assertSame('task error', $e->getMessage());
        }
        $fibers = new ReflectionProperty(GAsync::class, 'fibers');
        self::assertCount(0, $fibers->getValue());
        GAsync::run(0, 0);
    }

    public function testTimerRestartAndRepeatCancellation(): void
    {
        $count = 0;
        $timer = new GTimer(1000, true, function (GTimer $timer, float $elapsed) use (&$count) {
            self::assertGreaterThanOrEqual(0.001, $elapsed);
            if (++$count === 3) {
                $timer->clear();
            }
        });
        $timer->stop();
        $timer->start();
        GAsync::run(1, 0);
        self::assertSame(3, $count);
    }

    public function testIndependentWritesAndEof(): void
    {
        $stream = tmpfile();
        $results = [];
        GAsync::fwrite($stream, 'abc', function ($n) use (&$results) {
            $results[] = $n;
        });
        GAsync::fwrite($stream, 'defg', function ($n) use (&$results) {
            $results[] = $n;
        });
        GAsync::fwrite($stream, '', function ($n) use (&$results) {
            $results[] = $n;
        });
        GAsync::run(0, 1000);
        rewind($stream);
        self::assertSame('abcdefg', stream_get_contents($stream));
        self::assertSame([3, 4, 0], $results);
        GAsync::async(function () use ($stream) {
            self::assertSame('', GAsync::fread($stream, 10));
        });
        GAsync::run(0, 1000);
        GAsync::fclose($stream);
    }

    public function testConcurrentReadRequestsRemainIndependent(): void
    {
        $stream = tmpfile();
        fwrite($stream, 'abcdef');
        rewind($stream);
        $results = [];
        GAsync::fread($stream, 2, function ($data) use (&$results) {
            $results[] = $data;
        });
        GAsync::fread($stream, 4, function ($data) use (&$results) {
            $results[] = $data;
        });
        GAsync::run(0, 1000);
        self::assertSame(['ab', 'cdef'], $results);
        GAsync::fclose($stream);
    }

    public function testCloseThrowsIntoWaitingFiber(): void
    {
        [$left, $right] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $caught = false;
        GAsync::async(function () use ($left, &$caught) {
            try {
                GAsync::fread($left, 1);
            } catch (RuntimeException) {
                $caught = true;
            }
        });
        GAsync::runOnce(0, 0);
        GAsync::fclose($left);
        GAsync::run(0, 1000);
        self::assertTrue($caught);
        fclose($right);
    }

    public function testNestedLoopIsRejected(): void
    {
        GAsync::async(static function () {
            GAsync::run();
        });
        $this->expectException(LogicException::class);
        GAsync::run();
    }

    public function testFileOpenUpdateModeAndShortSleep(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'gasync-');
        try {
            GAsync::async(function () use ($path) {
                $stream = GAsync::fopen($path, 'w+b');
                self::assertSame(3, GAsync::fwrite($stream, 'abc'));
                rewind($stream);
                self::assertSame('abc', GAsync::fread($stream, 3));
                self::assertGreaterThanOrEqual(0.001, GAsync::usleep(1000));
                GAsync::fclose($stream);
            });
            $start = hrtime(true);
            GAsync::run(1, 0);
            self::assertLessThan(0.5, (hrtime(true) - $start) / 1e9);
        } finally {
            unlink($path);
        }
    }

    public function testStopDoesNotPoisonTheNextRun(): void
    {
        GAsync::async(static function () {
            GAsync::stop();
        });
        GAsync::run();
        $done = false;
        GAsync::async(function () use (&$done) {
            GAsync::msleep(1);
            $done = true;
        });
        GAsync::run();
        self::assertTrue($done);
    }

    public function testBufferedSocketReadsDoNotRequireAnotherNetworkEvent(): void
    {
        [$left, $right] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        fwrite($right, 'abcdef');
        $timer = new GTimer(1000000, false, static function () {
            throw new RuntimeException('Buffered read stalled');
        });
        GAsync::async(function () use ($left, $right, $timer) {
            self::assertSame('a', GAsync::fread($left, 1));
            self::assertSame('bcdef', GAsync::fread($left, 5));
            $timer->clear();
            GAsync::fclose($left);
            fclose($right);
        });
        GAsync::run(1, 0);
    }

    public function testCallbackExceptionAndRecovery(): void
    {
        GAsync::usleep(0, static function () {
            throw new RuntimeException('callback error');
        });
        try {
            GAsync::run();
            self::fail('Callback should throw');
        } catch (RuntimeException $e) {
            self::assertSame('callback error', $e->getMessage());
        }
        $done = false;
        GAsync::async(function () use (&$done) {
            $done = true;
        }, false);
        GAsync::run();
        self::assertTrue($done);
    }

    public function testUnregisterCancelsAllWaiters(): void
    {
        [$left, $right] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $cancelled = 0;
        for ($i = 0; $i < 2; ++$i) {
            GAsync::async(function () use ($left, &$cancelled) {
                try {
                    GAsync::fread($left, 1);
                } catch (RuntimeException) {
                    ++$cancelled;
                }
            });
        }
        GAsync::runOnce(0, 0);
        GAsync::removeStream($left, GAsync::STREAM_READ);
        GAsync::run();
        self::assertSame(2, $cancelled);
        fclose($left);
        fclose($right);
    }

    public function testExternalCloseDoesNotRetainPendingOperations(): void
    {
        [$left, $right] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $caught = false;
        GAsync::async(function () use ($left, &$caught) {
            try {
                GAsync::fread($left, 1);
            } catch (RuntimeException) {
                $caught = true;
            }
        });
        GAsync::runOnce(0, 0);
        fclose($left);
        GAsync::run();
        self::assertTrue($caught);
        fclose($right);
    }

    public function testReinitializationReleasesInternalDescriptors(): void
    {
        if (!is_dir('/proc/self/fd')) {
            self::markTestSkipped('Linux procfs required');
        }
        $before = count(scandir('/proc/self/fd'));
        for ($i = 0; $i < 30; ++$i) {
            GAsync::init(getenv('GASYNC_DRIVER') ?: 'GStream');
        }
        gc_collect_cycles();
        self::assertLessThanOrEqual($before + 1, count(scandir('/proc/self/fd')));
    }

    public function testReadOnlyStreamRejectsWriting(): void
    {
        $stream = fopen(__FILE__, 'rb');
        try {
            $this->expectException(InvalidArgumentException::class);
            GAsync::fwrite($stream, 'x', static function () {
            });
        } finally {
            fclose($stream);
        }
    }

    public function testSleepWithoutFiberFailsBeforeRegisteringAnything(): void
    {
        try {
            GAsync::usleep(1);
            self::fail('A fiber is required');
        } catch (LogicException) {
            self::assertTrue(true);
        }
        GAsync::run();
    }

    public function testUnknownDriverIsExplicit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        GAsync::init('does-not-exist');
    }

    public function testEmptyWriteCompletesWithoutSocketCapacity(): void
    {
        [$left, $right] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        stream_set_blocking($left, false);
        $buffer = str_repeat('x', 65536);
        while (fwrite($left, $buffer) > 0) {
        }
        $timer = new GTimer(1000000, false, static function () {
            throw new RuntimeException('Empty write stalled');
        });
        GAsync::async(function () use ($left, $timer) {
            self::assertSame(0, GAsync::fwrite($left, ''));
            $timer->clear();
        });
        GAsync::run();
        fclose($left);
        fclose($right);
    }

    public function testOpenFailureIsThrownIntoFiber(): void
    {
        GAsync::async(static function () {
            GAsync::fopen(__DIR__ . '/missing-directory/file', 'rb');
        });
        $this->expectException(RuntimeException::class);
        GAsync::run();
    }

    public function testZeroAndOneShotTimersDoNotKeepLoopAlive(): void
    {
        $seen = [];
        new GTimer(0, false, function () use (&$seen) {
            $seen[] = 'timer';
        });
        GAsync::usleep(0, function ($elapsed) use (&$seen) {
            self::assertGreaterThanOrEqual(0, $elapsed);
            $seen[] = 'sleep';
        });
        GAsync::run(1, 0);
        self::assertSame(['timer', 'sleep'], $seen);
    }

    public function testMissingNativeDriversHaveActionableErrors(): void
    {
        foreach (['ev' => 'GEV', 'event' => 'GEVENT'] as $extension => $name) {
            if (extension_loaded($extension)) {
                $driver = \GaragLib\GAsync\GDriver\GDriver::getDriver($name);
                self::assertSame($name, $driver->getName());
                $driver->close();
            } else {
                try {
                    \GaragLib\GAsync\GDriver\GDriver::getDriver($name);
                    self::fail('Extension is required');
                } catch (RuntimeException $e) {
                    self::assertStringContainsString($extension, $e->getMessage());
                }
            }
        }
    }

    public function testRawWatcherKeepsLoopAliveAndCanBeReplaced(): void
    {
        [$left, $right] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $wrong = new class () extends \GaragLib\GAsync\GStream {
            public function dispatch($stream = null, ?int $type = null): bool
            {
                throw new RuntimeException('Old watcher ran');
            }
        };
        $watcher = new class () extends \GaragLib\GAsync\GStream {
            public string $data = '';

            public function dispatch($stream = null, ?int $type = null): bool
            {
                $this->data .= fread($stream, 10);
                return true;
            }
        };
        GAsync::addStream($left, GAsync::STREAM_READ, $wrong);
        GAsync::addStream($left, GAsync::STREAM_READ, $watcher);
        GAsync::async(function () use ($right) {
            GAsync::msleep(1);
            fwrite($right, 'ready');
        });
        GAsync::run();
        self::assertSame('ready', $watcher->data);
        fclose($left);
        fclose($right);
    }

    public function testSignalInterruptionDoesNotBecomeAnIoError(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            self::markTestSkipped('pcntl and posix are required');
        }
        $previousAsync = pcntl_async_signals(true);
        $previousHandler = pcntl_signal_get_handler(SIGUSR1);
        $received = false;
        pcntl_signal(SIGUSR1, function () use (&$received) {
            $received = true;
        });
        $parent = getmypid();
        $pid = pcntl_fork();
        if ($pid === 0) {
            GAsync::fork();
            usleep(5000);
            posix_kill($parent, SIGUSR1);
            exit(0);
        }
        try {
            GAsync::dispatch(0, 100000);
            while (pcntl_waitpid($pid, $status) === -1 && pcntl_get_last_error() === PCNTL_EINTR) {
            }
            self::assertTrue($received);
        } finally {
            pcntl_signal(SIGUSR1, $previousHandler);
            pcntl_async_signals($previousAsync);
        }
    }

    public function testTimerStopDoesNotWaitForUnrelatedSocket(): void
    {
        [$left, $right] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        GAsync::addStream($left, GAsync::STREAM_READ, new \GaragLib\GAsync\GStream());
        new GTimer(0, false, static function () {
            GAsync::stop();
        });
        $start = hrtime(true);
        GAsync::run(1, 0);
        self::assertLessThan(0.1, (hrtime(true) - $start) / 1e9);
        GAsync::removeStream($left);
        fclose($left);
        fclose($right);
    }

    public function testSubprocessPipeIsPolledAsADescriptor(): void
    {
        $process = proc_open([PHP_BINARY, '-n', '-r', 'usleep(5000); echo "pipe";'], [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        try {
            self::assertTrue(\GaragLib\GAsync\GDriver\GDriver::isPollable($pipes[1]));
            GAsync::async(function () use ($pipes) {
                self::assertSame('pipe', GAsync::fread($pipes[1], 4));
            });
            GAsync::run();
            GAsync::fclose($pipes[1]);
        } finally {
            self::assertSame(0, proc_close($process));
        }
    }

    public function testRepeatingFloatZeroIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new GTimer(0.0, true);
    }

    public function testAdditionalUpdateModesRetainNativeSemantics(): void
    {
        foreach (['x+', 'c+', 'a+'] as $mode) {
            $path = tempnam(sys_get_temp_dir(), 'gasync-mode-');
            if ($mode === 'x+') {
                unlink($path);
            } else {
                file_put_contents($path, 'old');
            }
            try {
                GAsync::async(function () use ($path, $mode) {
                    $stream = GAsync::fopen($path, $mode);
                    self::assertSame(3, GAsync::fwrite($stream, 'abc'));
                    rewind($stream);
                    self::assertSame($mode === 'a+' ? 'oldabc' : 'abc', GAsync::fread($stream, 20));
                    GAsync::fclose($stream);
                });
                GAsync::run();
            } finally {
                unlink($path);
            }
        }
    }

    public function testQueuedAsyncWritesWithBackpressureKeepOrderAndTotals(): void
    {
        [$left, $right] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $payload = str_repeat('abcdefgh', 262144);
        $totals = [];
        $received = '';
        $timer = new GTimer(3000000, false, static function () {
            throw new RuntimeException('Async writes stalled');
        });
        try {
            GAsync::fwrite($left, $payload, function ($total) use (&$totals) {
                $totals[] = $total;
            });
            GAsync::fwrite($left, 'END', function ($total) use (&$totals, $left) {
                $totals[] = $total;
                GAsync::fclose($left);
            });
            GAsync::async(function () use ($right, &$received, $timer) {
                while (($data = GAsync::fread($right, 65536)) !== '') {
                    $received .= $data;
                }
                $timer->clear();
                GAsync::fclose($right);
            });
            GAsync::run();
            self::assertSame([strlen($payload), 3], $totals);
            self::assertSame($payload . 'END', $received);
        } finally {
            $timer->clear();
            if (is_resource($left)) {
                GAsync::fclose($left);
            }
            if (is_resource($right)) {
                GAsync::fclose($right);
            }
        }
    }
}

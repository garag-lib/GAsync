<?php

declare(strict_types=1);

namespace GaragLib\GAsync;

use GaragLib\GResourceStorage;
use GaragLib\GAsync\GDriver\GDriver;

class GAsync
{
    public const STREAM_READ = 1;
    public const STREAM_WRITE = 2;

    private static mixed $cmd_stream_A = null;
    private static mixed $cmd_stream_B = null;
    private static ?GResourceStorage $readers = null;
    private static ?GResourceStorage $writers = null;
    private static ?\SplObjectStorage $fibers = null;
    private static ?\SplObjectStorage $idles = null;
    private static ?\SplObjectStorage $timers = null;
    private static ?\SplQueue $ready = null;
    private static ?GDriver $driver = null;
    private static bool $stop = false;
    private static bool $running = false;
    private static bool $ticking = false;

    public static function init(?string $driver = null): void
    {
        if (self::$running || self::$ticking) {
            throw new \LogicException('Cannot initialize a running event loop');
        }
        if (self::$ready !== null && (!self::$ready->isEmpty() || self::$fibers->count() || self::hasOperations())) {
            throw new \LogicException('Drain or close pending operations before reinitializing the event loop');
        }
        $backend = GDriver::getDriver($driver);
        self::reset($backend);
    }

    private static function reset(GDriver $driver): void
    {
        self::$driver?->close();
        foreach ([self::$cmd_stream_A, self::$cmd_stream_B] as $stream) {
            if (is_resource($stream)) {
                \fclose($stream);
            }
        }
        if (self::$timers !== null) {
            foreach (clone self::$timers as $timer) {
                $timer->clear();
            }
        }
        self::$readers = new GResourceStorage();
        self::$writers = new GResourceStorage();
        self::$fibers = new \SplObjectStorage();
        self::$idles = new \SplObjectStorage();
        self::$timers = new \SplObjectStorage();
        self::$ready = new \SplQueue();
        self::$driver = $driver;
        self::$stop = self::$running = self::$ticking = false;
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false) {
            throw new \RuntimeException('Cannot create event loop control sockets');
        }
        [self::$cmd_stream_A, self::$cmd_stream_B] = $pair;
        foreach ($pair as $stream) {
            stream_set_blocking($stream, false);
        }
        self::addStream($pair[0], self::STREAM_READ, new GStreamCommand($pair[0]));
    }

    /** Call only in the child immediately after pcntl_fork(). */
    public static function fork(): void
    {
        $name = self::getDriver()->getName();
        self::$driver->fork();
        self::reset(GDriver::getDriver($name));
    }

    public static function getDriver(): GDriver
    {
        if (self::$driver === null) {
            self::init();
        }
        return self::$driver;
    }

    /** Convert a PHP I/O warning into an exception, restoring the caller's handler. */
    public static function io(callable $call): mixed
    {
        set_error_handler(static function (int $severity, string $message): never {
            throw new \RuntimeException($message);
        }, E_WARNING);
        try {
            return $call();
        } finally {
            restore_error_handler();
        }
    }

    public static function assertStream(mixed $stream): void
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new \InvalidArgumentException('Expected an open stream resource');
        }
    }

    public static function NOP(): void
    {
        self::sendCMD('NOP');
    }

    public static function sendCMD(string $cmd): void
    {
        self::getDriver();
        if (!in_array($cmd, ['NOP', 'STOP'], true)) {
            throw new \InvalidArgumentException('Unknown event loop command');
        }
        if ($cmd === 'STOP') {
            self::$stop = true;
        }
        // A full nonblocking control pipe already contains a wakeup.
        self::io(fn () => \fwrite(self::$cmd_stream_B, "NOP\n"));
    }

    public static function addStream($stream, ?int $type, GStream $assoc): void
    {
        self::getDriver();
        self::assertStream($stream);
        if ($type !== null && $type !== self::STREAM_READ && $type !== self::STREAM_WRITE) {
            throw new \InvalidArgumentException('Invalid stream direction');
        }
        if ($type === null || $type === self::STREAM_READ) {
            self::$driver->addReadStream(self::$readers, $stream, $assoc);
        }
        if ($type === null || $type === self::STREAM_WRITE) {
            self::$driver->addWriteStream(self::$writers, $stream, $assoc);
        }
    }

    public static function removeStream($stream, ?int $type = null): void
    {
        self::getDriver();
        if ($type !== null && $type !== self::STREAM_READ && $type !== self::STREAM_WRITE) {
            throw new \InvalidArgumentException('Invalid stream direction');
        }
        foreach ([self::STREAM_READ => self::$readers, self::STREAM_WRITE => self::$writers] as $direction => $storage) {
            if ($type === null || $type === $direction) {
                $watcher = $storage[$stream];
                if ($watcher instanceof GStreamRead || $watcher instanceof GStreamWrite) {
                    $watcher->cancel(new \RuntimeException('Stream unregistered with pending operations'));
                }
            }
        }
        if ($type === null || $type === self::STREAM_READ) {
            self::$driver->removeReadStream(self::$readers, $stream);
        }
        if ($type === null || $type === self::STREAM_WRITE) {
            self::$driver->removeWriteStream(self::$writers, $stream);
        }
    }

    public static function dispatch(int $timeoutSec, int $timeoutMicro): void
    {
        self::getDriver();
        if ($timeoutSec < 0 || $timeoutMicro < 0) {
            throw new \InvalidArgumentException('Negative dispatch timeout');
        }
        self::$driver->dispatch(self::$readers, self::$writers, $timeoutSec, $timeoutMicro);
    }

    public static function readEvent($stream): void
    {
        self::streamEvent($stream, self::STREAM_READ);
    }

    public static function writeEvent($stream): void
    {
        self::streamEvent($stream, self::STREAM_WRITE);
    }

    private static function streamEvent($stream, int $type): void
    {
        $storage = $type === self::STREAM_READ ? self::$readers : self::$writers;
        $watcher = $storage[$stream];
        if ($watcher !== null && $watcher->dispatch($stream, $type) && $storage[$stream] === $watcher) {
            self::removeStream($stream, $type);
        }
    }

    public static function defer(callable $callback): void
    {
        self::getDriver();
        self::$ready->enqueue(\Closure::fromCallable($callback));
    }

    private static function drainReady(): void
    {
        // Snapshot for fairness: callbacks queued by these callbacks run next turn.
        $count = self::$ready->count();
        for ($i = 0; $i < $count && !self::$stop; ++$i) {
            (self::$ready->dequeue())();
        }
    }

    private static function tick(int $sec, int $usec): void
    {
        if ($sec < 0 || $usec < 0) {
            throw new \InvalidArgumentException('Negative loop timeout');
        }
        self::$ticking = true;
        try {
            self::drainReady();
            if (self::$stop) {
                return;
            }
            foreach (clone self::$timers as $timer) {
                $timer->dispatch();
            }
            foreach (clone self::$idles as $idle) {
                if (self::$idles->offsetExists($idle)) {
                    $callback = self::$idles[$idle];
                    $callback !== null ? $callback($idle) : $idle->idle();
                }
            }
            self::poll($sec, $usec);
            if (self::$stop) {
                return;
            }
            foreach (clone self::$timers as $timer) {
                $timer->dispatch();
            }
            self::drainReady();
        } finally {
            self::$ticking = false;
        }
    }

    /** Re-evaluate stop state after user timer/idle callbacks before blocking. */
    private static function poll(int $sec, int $usec): void
    {
        if (self::$stop) {
            return;
        }
        $timeout = $sec + $usec / 1e6;
        foreach (self::$timers as $timer) {
            $timeout = min($timeout, $timer->remaining());
        }
        if (!self::$ready->isEmpty() || !self::hasWork()) {
            $timeout = 0.0;
        }
        self::dispatch((int) $timeout, (int) (($timeout - (int) $timeout) * 1e6));
    }

    public static function runOnce(int $sec = 0, int $usec = 100000): void
    {
        self::getDriver();
        if (self::$running || self::$ticking) {
            throw new \LogicException('Nested event loops are not supported');
        }
        self::$stop = false;
        self::tick($sec, $usec);
    }

    public static function run(int $sec = 0, int $usec = 100000): void
    {
        self::getDriver();
        if (self::$running || self::$ticking) {
            throw new \LogicException('Nested event loops are not supported');
        }
        self::$running = true;
        self::$stop = false;
        try {
            while (!self::$stop && self::hasWork()) {
                self::tick($sec, $usec);
            }
        } finally {
            self::$running = false;
        }
    }

    private static function hasWork(): bool
    {
        return !self::$ready->isEmpty() || self::$fibers->count() > 0 || self::$timers->count() > 0
            || self::$idles->count() > 0 || self::$readers->count() > 1 || self::$writers->count() > 0;
    }

    private static function hasOperations(): bool
    {
        foreach ([self::$readers, self::$writers] as $storage) {
            if ($storage !== null) {
                foreach ($storage->toArray(true) as $watcher) {
                    if ($watcher instanceof GStreamRead || $watcher instanceof GStreamWrite) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    public static function exit(): void
    {
        self::$stop = true;
    }

    public static function stop(): void
    {
        self::sendCMD('STOP');
    }

    public static function async(callable $callback, bool $bfiber = true): void
    {
        self::getDriver();
        if (!$bfiber) {
            self::defer($callback);
            return;
        }
        $fiber = new \Fiber(static function () use ($callback): void {
            try {
                $callback();
            } finally {
                self::$fibers->offsetUnset(\Fiber::getCurrent());
            }
        });
        self::$fibers->offsetSet($fiber);
        self::defer(static fn () => $fiber->start());
    }

    private static function operation(mixed $input, ?callable $callback): GOperation
    {
        self::getDriver();
        if ($callback !== null) {
            return new GOperation($input, static function ($result, ?\Throwable $error) use ($callback): void {
                if ($error !== null) {
                    throw $error;
                }
                $callback($result);
            });
        }
        $fiber = \Fiber::getCurrent();
        if ($fiber === null || !self::$fibers->offsetExists($fiber)) {
            throw new \LogicException('Operation requires a GAsync fiber or a callback');
        }
        return new GOperation($input, static function ($result, ?\Throwable $error) use ($fiber): void {
            if (!$fiber->isSuspended()) {
                return;
            }
            $error !== null ? $fiber->throw($error) : $fiber->resume($result);
        });
    }

    public static function usleep(int $uxec, ?callable $callback = null): mixed
    {
        if ($uxec < 0) {
            throw new \InvalidArgumentException('Negative sleep duration');
        }
        $operation = self::operation(null, $callback);
        new GTimer($uxec, false, static fn (GTimer $timer, float $elapsed) => $operation->resolve($elapsed));
        return $callback === null ? \Fiber::suspend() : null;
    }

    public static function msleep(int $msec, ?callable $callback = null): mixed
    {
        if ($msec < 0 || $msec > intdiv(PHP_INT_MAX, 1000)) {
            throw new \InvalidArgumentException('Invalid sleep duration');
        }
        return self::usleep($msec * 1000, $callback);
    }

    public static function sleep(int $sec, ?callable $callback = null): mixed
    {
        if ($sec < 0 || $sec > intdiv(PHP_INT_MAX, 1000000)) {
            throw new \InvalidArgumentException('Invalid sleep duration');
        }
        return self::usleep($sec * 1000000, $callback);
    }

    public static function fopen(string $filename, string $mode, bool $use_include_path = false, $context = null, ?callable $callback = null): mixed
    {
        $operation = self::operation(null, $callback);
        try {
            $stream = self::io(static fn () => \fopen($filename, $mode, $use_include_path, $context));
            if ($stream === false) {
                throw new \RuntimeException('Unable to open stream');
            }
            stream_set_blocking($stream, false);
            $operation->resolve($stream);
        } catch (\Throwable $error) {
            $operation->resolve(null, $error);
        }
        return $callback === null ? \Fiber::suspend() : null;
    }

    private static function streamOperation($stream, mixed $input, int $type, ?callable $callback): mixed
    {
        self::assertStream($stream);
        $mode = stream_get_meta_data($stream)['mode'];
        $readable = str_contains($mode, 'r') || str_contains($mode, '+');
        $writable = strpbrk($mode, 'waxc+') !== false;
        if (($type === self::STREAM_READ && !$readable) || ($type === self::STREAM_WRITE && !$writable)) {
            throw new \InvalidArgumentException('Stream mode does not support this operation');
        }
        $operation = self::operation($input, $callback);
        stream_set_blocking($stream, false);
        $storage = $type === self::STREAM_READ ? self::$readers : self::$writers;
        $watcher = $storage[$stream];
        $class = $type === self::STREAM_READ ? GStreamRead::class : GStreamWrite::class;
        if ($watcher !== null && !$watcher instanceof $class) {
            throw new \LogicException('Stream direction is owned by a different watcher');
        }
        if ($watcher === null) {
            $watcher = new $class($stream);
            self::addStream($stream, $type, $watcher);
        }
        $watcher->enqueue($operation);
        return $callback === null ? \Fiber::suspend() : null;
    }

    public static function fread($stream, int $length, ?callable $callback = null): mixed
    {
        if ($length <= 0) {
            throw new \InvalidArgumentException('Read length must be positive');
        }
        return self::streamOperation($stream, $length, self::STREAM_READ, $callback);
    }

    public static function fwrite($stream, string $data, ?callable $callback = null): mixed
    {
        return self::streamOperation($stream, $data, self::STREAM_WRITE, $callback);
    }

    public static function fclose($stream): void
    {
        self::assertStream($stream);
        self::getDriver();
        foreach ([self::$readers, self::$writers] as $storage) {
            $watcher = $storage[$stream];
            if ($watcher instanceof GStreamRead || $watcher instanceof GStreamWrite) {
                $watcher->cancel(new \RuntimeException('Stream closed with pending operations'));
            }
        }
        self::removeStream($stream);
        \fclose($stream);
    }

    public static function removeIdle(GStream $objStream): void
    {
        self::$idles?->offsetUnset($objStream);
    }

    public static function addIdle(GStream $objStream, ?callable $callback = null): void
    {
        self::getDriver();
        self::$idles[$objStream] = $callback;
    }

    public static function registerTimer(GTimer $timer): void
    {
        self::getDriver();
        self::$timers->offsetSet($timer);
    }

    public static function unregisterTimer(GTimer $timer): void
    {
        self::$timers?->offsetUnset($timer);
    }
}

<?php

declare(strict_types=1);

namespace GaragLib\GForkManager;

use GaragLib\GAsync\GAsync;
use GaragLib\GAsync\GTimer;
use GaragLib\GSocketManager\GSocketManager;

class GForkManager
{
    private bool $isRoot = true;
    private \SplQueue $wait_workers;
    private array $children = [];
    private array $callbacks = [];
    private GSocketManager $smngr;
    private ?GWorker $running_worker = null;
    private ?GTimer $reaper = null;
    private bool $waiting = false;
    private bool $finished = false;
    private bool $aborted = false;

    public function __construct(private int $max_num_proc, ?int $timeout = null)
    {
        if ($max_num_proc <= 0) {
            throw new \InvalidArgumentException('Worker limit must be positive');
        }
        if (!function_exists('pcntl_fork') || !function_exists('posix_getpid')) {
            throw new \RuntimeException('GForkManager requires pcntl and posix');
        }
        $this->wait_workers = new \SplQueue();
        $this->smngr = new GSocketManager($timeout ?? 10);
        $this->smngr->setOnConnectCallback([$this, 'onConnect']);
        $this->smngr->setOnAcceptCallback([$this, 'onAccept']);
        $this->smngr->setOnDisconnectCallback([$this, 'onDisconnect']);
        $this->smngr->setOnReceiveCallback([$this, 'onReceive']);
        $this->smngr->setOnIdleCallback([$this, 'onIdle']);
        $this->smngr->setOnErrorCallback(function ($socket, \Throwable $error): void {
            if ($this->isRoot) {
                $this->emit('error', $this->pidFor($socket), $error);
            }
        });
    }

    private function rootOnly(): void
    {
        if (!$this->isRoot) {
            throw new \LogicException('Operation is only available in the parent process');
        }
    }

    public function isRoot(): bool
    {
        return $this->isRoot;
    }

    public function setCallback(string $type, ?callable $callback): void
    {
        $this->rootOnly();
        $this->callbacks[$type] = $callback;
    }

    private function pidFor($socket): ?int
    {
        foreach ($this->children as $pid => $child) {
            if ($child['socket'] === $socket) {
                return $pid;
            }
        }
        return null;
    }

    private function emit(string $type, ?int $pid, mixed ...$args): void
    {
        if (!isset($this->callbacks[$type])) {
            return;
        }
        $callback = $this->callbacks[$type];
        if (\Fiber::getCurrent() !== null) {
            $callback($pid, ...$args);
        } else {
            GAsync::async(function () use ($callback, $pid, $args): void {
                if (!$this->aborted) {
                    $callback($pid, ...$args);
                }
            });
        }
    }

    public function call(string $type, mixed ...$args): void
    {
        $this->rootOnly();
        $pid = isset($args[0]) && (is_resource($args[0]) || gettype($args[0]) === 'resource (closed)') ? $this->pidFor($args[0]) : null;
        $this->emit($type, $pid, ...$args);
    }

    public function add(string $classname, mixed ...$args): void
    {
        $this->rootOnly();
        if ($this->finished) {
            throw new \LogicException('Worker manager has finished; create a new manager');
        }
        if (!is_subclass_of($classname, GWorker::class)) {
            throw new \InvalidArgumentException('Worker must extend ' . GWorker::class);
        }
        $this->wait_workers->enqueue(new GFactoryWorker($classname, ...$args));
    }

    public function kill($pid): bool
    {
        $this->rootOnly();
        if (is_resource($pid)) {
            $pid = $this->pidFor($pid);
        }
        if (!is_int($pid) || !isset($this->children[$pid]) || $this->children[$pid]['reaped']) {
            return false;
        }
        return posix_kill($pid, SIGTERM);
    }

    public function onAccept($sock): void
    {
        $this->isRoot ? $this->call('accept', $sock) : $this->running_worker->onAccept($sock);
    }

    public function onConnect($sock): void
    {
        $this->isRoot ? $this->call('connect', $sock) : $this->running_worker->onConnect($sock);
    }

    public function onDisconnect($sock): void
    {
        if (!$this->isRoot) {
            $this->running_worker->onDisconnect($sock);
            $this->smngr->end();
            return;
        }
        $pid = $this->pidFor($sock);
        if ($pid !== null) {
            $this->children[$pid]['disconnected'] = true;
            $this->emit('disconnect', $pid, $sock, $this->pidMap());
        }
    }

    public function onReceive($sock): void
    {
        $data = $this->smngr->read($sock);
        $this->isRoot ? $this->call('receive', $sock, $data) : $this->running_worker->onReceive($sock, $data);
    }

    public function onIdle(): void
    {
        $this->isRoot ? $this->call('idle') : $this->running_worker->onIdle();
    }

    private function pidMap(): array
    {
        $map = [];
        foreach ($this->children as $pid => $child) {
            if (!$child['disconnected']) {
                $map[$pid] = $child['socket'];
            }
        }
        return $map;
    }

    public function run(): array|false
    {
        if (!$this->isRoot) {
            return false;
        }
        if ($this->finished) {
            return [];
        }
        $active = count(array_filter($this->children, static fn ($child) => !$child['reaped']));
        while ($active < $this->max_num_proc && !$this->wait_workers->isEmpty()) {
            [$parent, $child] = $this->smngr->createSocketPair();
            [$statusParent, $statusChild] = $this->smngr->createSocketPair();
            $pid = pcntl_fork();
            if ($pid === -1) {
                foreach ([$parent, $child, $statusParent, $statusChild] as $socket) {
                    fclose($socket);
                }
                throw new \RuntimeException('Cannot fork worker');
            }
            if ($pid === 0) {
                $factory = $this->wait_workers->dequeue();
                fclose($parent);
                fclose($statusParent);
                foreach ($this->children as $state) {
                    if (is_resource($state['status'])) {
                        fclose($state['status']);
                    }
                }
                $this->isRoot = false;
                $this->children = $this->callbacks = [];
                $this->reaper?->clear();
                $this->reaper = null;
                GAsync::fork();
                $this->smngr->fork();
                $code = 0;
                try {
                    $this->running_worker = $factory->getInstance();
                    $this->running_worker->setSocketManager($this->smngr, $child);
                    $this->smngr->addSocket($child);
                    $this->running_worker->onConnect($child);
                    $this->running_worker->run();
                    $this->smngr->end();
                    GAsync::run(0, 10000);
                } catch (\Throwable $error) {
                    $code = 1;
                    // Dedicated control channel: never mix exceptions with application data.
                    $payload = json_encode(['class' => get_class($error), 'message' => substr($error->getMessage(), 0, 2000)], JSON_INVALID_UTF8_SUBSTITUTE);
                    fwrite($statusChild, $payload);
                    $this->smngr->dellAllSockets();
                }
                fclose($statusChild);
                exit($code);
            }
            fclose($child);
            fclose($statusChild);
            $this->wait_workers->dequeue();
            $this->children[$pid] = ['socket' => $parent, 'status' => $statusParent, 'disconnected' => false, 'reaped' => false];
            $this->smngr->addSocket($parent);
            $this->emit('connect', $pid, $parent);
            ++$active;
        }
        if ($this->reaper === null && $this->children !== []) {
            $this->reaper = new GTimer(5000, true, fn () => $this->checkZombies());
        }
        return $this->pidMap();
    }

    private function checkZombies(): void
    {
        foreach (array_keys($this->children) as $pid) {
            if (!$this->children[$pid]['reaped']) {
                $result = pcntl_waitpid($pid, $status, WNOHANG);
                if ($result === -1 && pcntl_get_last_error() === PCNTL_EINTR) {
                    continue;
                }
                if ($result === $pid || $result === -1) {
                    $this->children[$pid]['reaped'] = true;
                    $detail = stream_get_contents($this->children[$pid]['status']);
                    fclose($this->children[$pid]['status']);
                    $error = $detail === '' ? null : json_decode($detail, true);
                    $code = $result === $pid && pcntl_wifexited($status) ? pcntl_wexitstatus($status) : null;
                    $signal = $result === $pid && pcntl_wifsignaled($status) ? pcntl_wtermsig($status) : null;
                    if (is_array($error)) {
                        $this->emit('error', $pid, new \RuntimeException($error['class'] . ': ' . $error['message']));
                    }
                    $this->emit('exit', $pid, $code, $signal);
                }
            }
            if ($this->children[$pid]['reaped'] && $this->children[$pid]['disconnected']) {
                unset($this->children[$pid]);
            }
        }
        $this->run();
        if ($this->children === [] && $this->wait_workers->isEmpty()) {
            $this->reaper?->clear();
            $this->reaper = null;
            if ($this->waiting) {
                $this->smngr->end();
                $this->finished = true;
            }
        }
    }

    public function wait(int $sec = 0, int $usec = 100000): void
    {
        $this->rootOnly();
        $this->waiting = true;
        $this->run();
        try {
            GAsync::run($sec, $usec);
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    /** Abort queued work and terminate/reap all children owned by this manager. */
    public function close(): void
    {
        $this->rootOnly();
        $this->finished = $this->aborted = true;
        $this->reaper?->clear();
        $this->reaper = null;
        foreach ($this->children as $pid => $child) {
            if (!$child['reaped']) {
                posix_kill($pid, SIGTERM);
            }
        }
        $deadline = hrtime(true) + 100000000;
        foreach ($this->children as $pid => $child) {
            if (!$child['reaped']) {
                do {
                    $result = pcntl_waitpid($pid, $status, WNOHANG);
                    if ($result !== 0) {
                        break;
                    }
                    usleep(1000);
                } while (hrtime(true) < $deadline);
                if ($result === 0) {
                    posix_kill($pid, SIGKILL);
                    do {
                        $result = pcntl_waitpid($pid, $status);
                    } while ($result === -1 && pcntl_get_last_error() === PCNTL_EINTR);
                }
            }
            if (is_resource($child['status'])) {
                fclose($child['status']);
            }
        }
        $this->children = [];
        $this->wait_workers = new \SplQueue();
        $this->smngr->dellAllSockets();
    }
}

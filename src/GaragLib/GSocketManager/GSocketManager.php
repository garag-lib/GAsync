<?php

declare(strict_types=1);

namespace GaragLib\GSocketManager;

use GaragLib\GAsync\GAsync;
use GaragLib\GAsync\GStream;
use GaragLib\GAsync\GTimer;

class GSocketManager extends GStream
{
    public const default_buffer_size = 1024;
    private array $sockets = [];
    private array $callbacks = [];
    private ?GTimer $timer = null;
    private bool $finish = false;
    private float $lastIdle = 0.0;
    private int $idleMilliseconds;

    public function __construct(?int $timeout = 1000)
    {
        $this->idleMilliseconds = $timeout === null || $timeout <= 0 ? 1000 : $timeout;
    }

    private function track($socket, bool $server, bool $udp, int $buffersize): void
    {
        GAsync::assertStream($socket);
        if ($this->finish) {
            throw new \LogicException('Socket manager has ended');
        }
        if ($buffersize <= 0) {
            throw new \InvalidArgumentException('Buffer size must be positive');
        }
        stream_set_blocking($socket, false);
        $this->sockets[get_resource_id($socket)] = [
            'socket' => $socket, 'server' => $server, 'udp' => $udp, 'bufferSize' => $buffersize,
            'connecting' => false, 'deadline' => 0.0, 'closing' => false, 'disconnecting' => false,
            'send' => new \SplQueue(), 'receive' => '',
        ];
        $this->startTimer();
    }

    private function startTimer(): void
    {
        if ($this->timer === null) {
            $this->lastIdle = hrtime(true) / 1e9;
            $this->timer = new GTimer(min(10, $this->idleMilliseconds) * 1000, true, fn () => $this->idle());
        }
    }

    private static function address(string $ip, int $port, string $type): string
    {
        if (!in_array($type, ['tcp', 'udp'], true)) {
            throw new \InvalidArgumentException('Only tcp and udp transports are supported');
        }
        if ($port < 0 || $port > 65535) {
            throw new \InvalidArgumentException('Invalid port');
        }
        if (str_contains($ip, ':') && !str_starts_with($ip, '[')) {
            $ip = '[' . $ip . ']';
        }
        return "$type://$ip:$port";
    }

    public function createServerSocket(string $ip, int &$port = 0, string $type = 'tcp', int $buffersize = self::default_buffer_size, ?int $backlog = null)
    {
        $options = ['so_reuseaddr' => true];
        if ($backlog !== null) {
            if ($backlog <= 0) {
                throw new \InvalidArgumentException('Backlog must be positive');
            }
            $options['backlog'] = $backlog;
        }
        $context = stream_context_create(['socket' => $options]);
        $socket = GAsync::io(fn () => stream_socket_server(
            self::address($ip, $port, $type),
            $errno,
            $errstr,
            $type === 'udp' ? STREAM_SERVER_BIND : STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $context
        ));
        if ($socket === false) {
            throw new \RuntimeException('Cannot create server socket');
        }
        try {
            $name = stream_socket_get_name($socket, false);
            if ($name === false) {
                throw new \RuntimeException('Cannot obtain server address');
            }
            $port = (int) substr($name, strrpos($name, ':') + 1);
            $this->track($socket, $type !== 'udp', $type === 'udp', $buffersize);
            GAsync::addStream($socket, GAsync::STREAM_READ, $this);
            return $socket;
        } catch (\Throwable $error) {
            fclose($socket);
            throw $error;
        }
    }

    public function createClientSocket(string $ip, int $port, string $type = 'tcp', int|float $timeout = 30, int $buffersize = self::default_buffer_size)
    {
        if ($timeout <= 0 || !is_finite((float) $timeout)) {
            throw new \InvalidArgumentException('Connection timeout must be positive');
        }
        $socket = GAsync::io(fn () => stream_socket_client(
            self::address($ip, $port, $type),
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
        ));
        if ($socket === false) {
            throw new \RuntimeException('Cannot create client socket');
        }
        try {
            $this->track($socket, false, $type === 'udp', $buffersize);
            if ($type === 'udp') {
                GAsync::addStream($socket, GAsync::STREAM_READ, $this);
            } else {
                $id = get_resource_id($socket);
                $this->sockets[$id]['connecting'] = true;
                $this->sockets[$id]['deadline'] = hrtime(true) / 1e9 + $timeout;
                GAsync::addStream($socket, GAsync::STREAM_WRITE, $this);
            }
            return $socket;
        } catch (\Throwable $error) {
            $this->delSocket($socket);
            throw $error;
        }
    }

    public function createSocketPair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        if ($pair === false) {
            throw new \RuntimeException('Cannot create socket pair');
        }
        foreach ($pair as $socket) {
            stream_set_blocking($socket, false);
        }
        return $pair;
    }

    public function addSocket($socket, int $buffersize = self::default_buffer_size): void
    {
        GAsync::assertStream($socket);
        $id = get_resource_id($socket);
        if (isset($this->sockets[$id])) {
            return;
        }
        $udp = stream_get_meta_data($socket)['stream_type'] === 'udp_socket';
        $this->track($socket, false, $udp, $buffersize);
        GAsync::addStream($socket, GAsync::STREAM_READ, $this);
    }

    public function getSockets(): array
    {
        return array_map(static fn ($state) => $state['socket'], $this->sockets);
    }

    public function delSocket($socket): void
    {
        $id = get_resource_id($socket);
        if (!isset($this->sockets[$id])) {
            return;
        }
        GAsync::removeStream($socket);
        unset($this->sockets[$id]);
        if (is_resource($socket)) {
            fclose($socket);
        }
        $this->stopIfEmpty();
    }

    public function dellAllSockets(): void
    {
        foreach ($this->getSockets() as $socket) {
            $this->delSocket($socket);
        }
    }

    public function fork(): void
    {
        $this->timer?->clear();
        $this->timer = null;
        $this->dellAllSockets();
        $this->finish = false;
    }

    public function send($socket, string $data, ?string $peer = null): void
    {
        GAsync::assertStream($socket);
        $id = get_resource_id($socket);
        if (!isset($this->sockets[$id]) || $this->sockets[$id]['closing']) {
            throw new \LogicException('Socket is not open for sending');
        }
        if (!$this->sockets[$id]['udp'] && $peer !== null) {
            throw new \InvalidArgumentException('Peer address is only supported for UDP');
        }
        if ($data === '' && !$this->sockets[$id]['udp']) {
            return;
        }
        $this->sockets[$id]['send']->enqueue(['data' => $data, 'offset' => 0, 'peer' => $peer]);
        GAsync::addStream($socket, GAsync::STREAM_WRITE, $this);
    }

    public function read($socket, bool $clear = true): ?string
    {
        $id = get_resource_id($socket);
        if (!isset($this->sockets[$id]) || $this->sockets[$id]['receive'] === '') {
            return null;
        }
        $data = $this->sockets[$id]['receive'];
        if ($clear) {
            $this->sockets[$id]['receive'] = '';
        }
        return $data;
    }

    public function close($socket): void
    {
        $id = get_resource_id($socket);
        if (isset($this->sockets[$id])) {
            $this->sockets[$id]['closing'] = true;
            $this->checkClose($id);
        }
    }

    public function end(): void
    {
        $this->finish = true;
        foreach ($this->getSockets() as $socket) {
            $this->close($socket);
        }
        $this->stopIfEmpty();
    }

    private function stopIfEmpty(): void
    {
        if ($this->sockets === []) {
            $this->timer?->clear();
            $this->timer = null;
        }
    }

    private function checkClose(int $id): void
    {
        if (!isset($this->sockets[$id])) {
            return;
        }
        $state = &$this->sockets[$id];
        if (!$state['closing'] || $state['disconnecting'] || !$state['send']->isEmpty()) {
            return;
        }
        $state['disconnecting'] = true;
        $socket = $state['socket'];
        GAsync::removeStream($socket);
        GAsync::async(function () use ($socket): void {
            try {
                if (isset($this->callbacks['disconnect'])) {
                    ($this->callbacks['disconnect'])($socket);
                }
            } finally {
                $this->delSocket($socket);
            }
        });
    }

    private function fail(int $id, \Throwable $error): void
    {
        if (!isset($this->sockets[$id]) || $this->sockets[$id]['disconnecting']) {
            return;
        }
        $socket = $this->sockets[$id]['socket'];
        $this->sockets[$id]['send'] = new \SplQueue();
        $this->sockets[$id]['closing'] = true;
        if (isset($this->callbacks['error'])) {
            $this->emit('error', $socket, $error);
        } else {
            GAsync::defer(static function () use ($error): never {
                throw $error;
            });
        }
        $this->checkClose($id);
    }

    private function connected(int $id): void
    {
        $socket = $this->sockets[$id]['socket'];
        if (hrtime(true) / 1e9 >= $this->sockets[$id]['deadline']) {
            throw new \RuntimeException('Socket connection timed out');
        }
        if (extension_loaded('sockets')) {
            $native = GAsync::io(fn () => socket_import_stream($socket));
            if ($native === false) {
                throw new \RuntimeException('Cannot inspect connecting socket');
            }
            $error = socket_get_option($native, SOL_SOCKET, SO_ERROR);
            if ($error !== 0) {
                throw new \RuntimeException('Socket connection failed: ' . socket_strerror($error ?: SOCKET_ECONNREFUSED));
            }
        }
        if (GAsync::io(fn () => stream_socket_get_name($socket, true)) === false) {
            throw new \RuntimeException('Socket connection failed');
        }
        $this->sockets[$id]['connecting'] = false;
        GAsync::addStream($socket, GAsync::STREAM_READ, $this);
        if ($this->sockets[$id]['send']->isEmpty()) {
            GAsync::removeStream($socket, GAsync::STREAM_WRITE);
        }
        $this->emit('connect', $socket);
    }

    private function receive(int $id): void
    {
        $state = $this->sockets[$id];
        $socket = $state['socket'];
        if ($state['server']) {
            $client = GAsync::io(fn () => stream_socket_accept($socket, 0));
            if ($client !== false) {
                $this->addSocket($client, $state['bufferSize']);
                $this->emit('accept', $client);
            }
            return;
        }
        $peer = null;
        $data = GAsync::io(static function () use ($socket, $state, &$peer) {
            return stream_socket_recvfrom($socket, $state['bufferSize'], 0, $peer);
        });
        if ($data === false) {
            throw new \RuntimeException('Socket receive failed');
        }
        if ($state['udp']) {
            $this->emit('receive', $socket, $data, $peer);
            return;
        }
        if ($data === '') {
            if (feof($socket)) {
                $this->close($socket);
            }
            return;
        }
        $this->sockets[$id]['receive'] .= $data;
        $this->emit('receive', $socket);
    }

    private function write(int $id): void
    {
        $state = &$this->sockets[$id];
        if ($state['send']->isEmpty()) {
            GAsync::removeStream($state['socket'], GAsync::STREAM_WRITE);
            return;
        }
        $item = $state['send']->bottom();
        $socket = $state['socket'];
        $sent = GAsync::io(static function () use ($state, $item, $socket) {
            if ($state['udp']) {
                return $item['peer'] === null ? stream_socket_sendto($socket, $item['data']) : stream_socket_sendto($socket, $item['data'], 0, $item['peer']);
            }
            return fwrite($socket, substr($item['data'], $item['offset'], 65536));
        });
        if ($sent === false) {
            throw new \RuntimeException('Socket write failed');
        }
        $item['offset'] += $sent;
        if ($state['udp'] && $sent !== strlen($item['data'])) {
            throw new \RuntimeException('Incomplete UDP datagram write');
        }
        if ($item['offset'] === strlen($item['data'])) {
            $state['send']->dequeue();
        } else {
            $state['send']->offsetSet(0, $item);
        }
        if ($state['send']->count() === 0) {
            GAsync::removeStream($socket, GAsync::STREAM_WRITE);
        }
        $this->checkClose($id);
    }

    public function dispatch($stream = null, ?int $type = null): bool
    {
        if (!is_resource($stream)) {
            return false;
        }
        $id = get_resource_id($stream);
        if (!isset($this->sockets[$id]) || $this->sockets[$id]['disconnecting']) {
            return false;
        }
        try {
            if ($this->sockets[$id]['connecting']) {
                $this->connected($id);
            }
            if ($type === GAsync::STREAM_READ) {
                $this->receive($id);
            }
            if ($type === GAsync::STREAM_WRITE) {
                $this->write($id);
            }
        } catch (\Throwable $error) {
            $this->fail($id, $error);
        }
        return false;
    }

    public function idle(): void
    {
        $now = hrtime(true) / 1e9;
        foreach ($this->sockets as $id => $state) {
            if (!is_resource($state['socket'])) {
                $this->delSocket($state['socket']);
                continue;
            }
            if ($state['connecting'] && $now >= $state['deadline']) {
                $this->fail($id, new \RuntimeException('Socket connection timed out'));
            }
            $this->checkClose($id);
        }
        if ($now - $this->lastIdle >= $this->idleMilliseconds / 1000) {
            $this->lastIdle = $now;
            $this->emit('idle', $this);
        }
        $this->stopIfEmpty();
    }

    private function emit(string $event, mixed ...$args): void
    {
        if (isset($this->callbacks[$event])) {
            $callback = $this->callbacks[$event];
            GAsync::async(static function () use ($event, $callback, $args): void {
                if ($event !== 'idle' && !is_resource($args[0])) {
                    return;
                }
                $callback(...$args);
            });
        }
    }

    public function setOnIdleCallback(?callable $callback): void
    {
        $this->callbacks['idle'] = $callback;
    }

    public function setOnReceiveCallback(?callable $callback): void
    {
        $this->callbacks['receive'] = $callback;
    }

    public function setOnConnectCallback(?callable $callback): void
    {
        $this->callbacks['connect'] = $callback;
    }

    public function setOnAcceptCallback(?callable $callback): void
    {
        $this->callbacks['accept'] = $callback;
    }

    public function setOnDisconnectCallback(?callable $callback): void
    {
        $this->callbacks['disconnect'] = $callback;
    }

    public function setOnErrorCallback(?callable $callback): void
    {
        $this->callbacks['error'] = $callback;
    }
}

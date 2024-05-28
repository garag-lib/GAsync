<?php

namespace GaragLib\GSocketManager;

/**
 * Class GSocketManager for socket management.
 */
class GSocketManager extends \GaragLib\GAsync\GStream {

    const default_buffer_size = 1024;

    /**
     * 
     * @var GResourceStorage
     */
    private $sockets;

    /**
     * 
     * @var GResourceStorage
     */
    private $events;

    /**
     * 
     * @var array
     */
    private $callbacks = [];

    /**
     * 
     * @var GResourceStorage
     */
    private $connectSockets;

    /**
     * 
     * @var GResourceStorage
     */
    private $bufferSockets;

    /**
     * 
     * @var GResourceStorage
     */
    private $bufferReceiveSockets;

    /**
     * 
     * @var GResourceStorage
     */
    private $bufferSocketsSize;

    /**
     * 
     * @var GResourceStorage
     */
    private $closeSockets;

    /**
     * 
     * @var boolean
     */
    private $finish = false;

    /**
     * 
     * @var \GaragLib\GAsync\GTimer
     */
    private $timer;

    public function __construct($timeout = 1000) {
        if ($timeout === null || $timeout <= 0)
            $timeout = 1000;
        $this->timer = new \GaragLib\GAsync\GTimer($timeout * 1000, true);
        $this->sockets = new \GaragLib\GResourceStorage();
        $this->events = new \GaragLib\GResourceStorage();
        $this->connectSockets = new \GaragLib\GResourceStorage();
        $this->bufferSockets = new \GaragLib\GResourceStorage();
        $this->bufferReceiveSockets = new \GaragLib\GResourceStorage();
        $this->closeSockets = new \GaragLib\GResourceStorage();
        $this->bufferSocketsSize = new \GaragLib\GResourceStorage();
        \GaragLib\GAsync\GAsync::addIdle($this);
    }

    /**
     * @todo 
     */
    private function log(...$args) {
        echo implode(' ', $args) . PHP_EOL;
    }

    //--------------------------------------------------------------------------

    /**
     * Registers an event for a given socket.
     *
     * @param resource $socket Socket to which the event will be added.
     * @param string $type Type of event ('connect', 'receive', 'disconnect', etc.).
     * @param callable $callback Callback function to be executed when the event occurs.
     * @return void
     */
    private function addEvent($socket, $type) {
        if ($type == 'connect') {
            $this->connectSockets->attach($socket);
        }
        $arr = null;
        if (!$this->events->contains($socket)) {
            $arr[$type] = true;
            $this->events->attach($socket, $arr);
        } else {
            $arr = $this->events->offsetGet($socket);
            if (!isset($arr[$type])) {
                $arr[$type] = true;
                $this->events->offsetSet($socket, $arr);
            }
        }
        if ($type == 'accept' || $type == 'receive') {
            \GaragLib\GAsync\GAsync::addStream($socket, \GaragLib\GAsync\GAsync::STREAM_READ, $this);
        }
        if ($type == 'write') {
            \GaragLib\GAsync\GAsync::addStream($socket, \GaragLib\GAsync\GAsync::STREAM_WRITE, $this);
        }
    }

    /**
     * Removes an event from a socket.
     *
     * @param resource $socket Socket from which the event will be removed.
     * @param string|null $type Type of event to remove (optional).
     * @return void
     */
    private function removeEvent($socket, $type = null) {
        if ($this->events->contains($socket)) {
            if ($type === null) {
                $this->events->detach($socket);
                $this->connectSockets->detach($socket);
                \GaragLib\GAsync\GAsync::removeStream($socket);
            } else {
                $arr = $this->events->offsetGet($socket);
                unset($arr[$type]);
                if (empty($arr)) {
                    $this->events->detach($socket);
                }
                if ($type == 'connect') {
                    $this->connectSockets->detach($socket);
                }
                if ($type == 'accept' || $type == 'receive') {
                    \GaragLib\GAsync\GAsync::removeStream($socket, \GaragLib\GAsync\GAsync::STREAM_READ);
                }
                if ($type == 'write') {
                    \GaragLib\GAsync\GAsync::removeStream($socket, \GaragLib\GAsync\GAsync::STREAM_WRITE);
                }
            }
        }
    }

    //--------------------------------------------------------------------------

    /**
     * Assigns a dynamic port for a given IP address.
     *
     * @param string $ip IP address for which a dynamic port will be assigned.
     * @return int Assigned dynamic port.
     * @throws \Exception If there's an error assigning the dynamic port.
     */
    private function assignDynamicPort($ip) {
        $port = 0;
        $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($sock) {
            socket_bind($sock, $ip, $port);
            socket_getsockname($sock, $addr, $port);
            socket_close($sock);
            return $port;
        }
        throw new \Exception('assignDynamicPort ' . socket_strerror(socket_last_error()));
    }

    /**
     * Creates a server socket.
     *
     * @param string $ip IP address on which the server socket will listen.
     * @param int &$port Port on which the server socket will listen (assigned dynamically if 0).
     * @param int $buffersize Size of the buffer for reading data from the socket.
     * @param int $backlog Maximum number of pending connections.
     * @return resource Created server socket.
     * @throws \Exception If there's an error creating the server socket.
     */
    public function createServerSocket($ip, & $port = 0, $buffersize = self::default_buffer_size, $backlog = null) {

        if ($port == 0) {
            $port = $this->assignDynamicPort($ip);
        }

        $context = stream_context_create([
            'socket' => [
                'blocking' => false,
                'backlog' => $backlog,
                'so_reuseport' => 1,
                'so_keepalive' => 1,
                'send_buffer' => $buffersize,
                'recv_buffer' => $buffersize,
            ],
        ]);

        $socket = stream_socket_server("tcp://$ip:$port", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

        if (!$socket) {
            throw new \Exception('stream_socket_server error');
        }

        stream_set_blocking($socket, 0);

        $this->sockets->attach($socket);

        if ($buffersize != self::default_buffer_size) {
            $this->bufferSocketsSize->attach($socket, $buffersize);
        }

        $this->addEvent($socket, 'accept');

        return $socket;
    }

    /**
     * Creates a client socket.
     *
     * @param string $ip IP address of the server to connect.
     * @param int $port Port of the server to connect.
     * @param int $timeout Connection timeout in seconds.
     * @param int $buffersize Size of the buffer for reading data from the socket.
     * @return resource Created client socket.
     * @throws \Exception If there's an error creating the client socket.
     */
    public function createClientSocket($ip, $port, $timeout = 30, $buffersize = self::default_buffer_size) {

        $context = stream_context_create([
            'socket' => [
                'blocking' => false,
                'so_reuseport' => 1,
                'so_keepalive' => 1,
                'send_buffer' => $buffersize,
                'recv_buffer' => $buffersize,
            ],
        ]);

        $socket = stream_socket_client("tcp://$ip:$port", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT, $context);

        if (!$socket) {
            throw new \Exception('stream_socket_client error');
        }

        stream_set_blocking($socket, 0);

        $this->sockets->attach($socket);

        if ($buffersize != self::default_buffer_size) {
            $this->bufferSocketsSize->attach($socket, $buffersize);
        }

        $this->addEvent($socket, 'connect');

        \GaragLib\GAsync\GAsync::dispatch(0, 1);

        return $socket;
    }

    /**
     * Creates a pair of connected sockets.
     *
     * @return array Pair of connected sockets.
     * @throws \Exception If there's an error creating the socket pair.
     */
    public function createSocketPair() {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (!$sockets) {
            throw new \Exception('stream_socket_pair error');
        }
        return $sockets;
    }

    /**
     * Deletes all sockets from the manager.
     *
     * @return void
     */
    public function dellAllSockets() {
        foreach ($this->sockets as $sock) {
            $this->delSocket($sock);
        }
    }

    /**
     * Deletes a socket from the manager.
     *
     * @param resource $socket Socket to be deleted.
     * @return void
     */
    public function delSocket($socket) {
        $this->removeEvent($socket);
        $this->closeSockets->detach($socket);
        $this->connectSockets->detach($socket);
        $this->bufferSockets->detach($socket);
        $this->bufferReceiveSockets->detach($socket);
        $this->sockets->detach($socket);
    }

    /**
     * Adds a socket to the manager.
     *
     * @param resource $socket Socket to be added.
     * @param int $buffersize Size of the buffer for reading data from the socket.
     * @return void
     */
    public function addSocket($socket, $buffersize = self::default_buffer_size) {
        stream_set_blocking($socket, 0);
        stream_set_read_buffer($socket, $buffersize);
        stream_set_write_buffer($socket, $buffersize);
        if ($buffersize != self::default_buffer_size) {
            $this->bufferSocketsSize->attach($socket, $buffersize);
        }
        $this->sockets->attach($socket);
        $this->addEvent($socket, 'receive');
        $this->addEvent($socket, 'disconnect');
    }

    /**
     * Gets all sockets managed by the manager.
     *
     * @return array All managed sockets.
     */
    public function getSockets() {
        return $this->sockets->toArray();
    }

    //--------------------------------------------------------------------------

    private function handleConnect($socket) {
        if (feof($socket)) {
            $this->log('handleConnect', 'error', 'eof');
            $this->close($socket);
            return;
        }
        $meta = stream_get_meta_data($socket);
        if ($meta['timed_out']) {
            $this->log('handleConnect', 'error', 'timed_out');
            $this->close($socket);
            return;
        }
        if ($meta['eof']) {
            $this->log('handleConnect', 'error', 'eof');
            $this->close($socket);
            return;
        }
        if ($meta['blocked']) {
            $this->log('handleConnect', 'error', 'blocked');
            $this->close($socket);
            return;
        }
        $name = stream_socket_get_name($socket, true);
        if ($name) {
            $this->removeEvent($socket, 'connect');
            $this->addEvent($socket, 'receive');
            $this->addEvent($socket, 'disconnect');
            $this->call('connect', $socket);
        }
    }

    private function handleAccept($socket) {
        $clientSocket = stream_socket_accept($socket);
        if ($clientSocket) {
            $this->sockets->attach($clientSocket);
            $this->addEvent($clientSocket, 'receive');
            $this->addEvent($clientSocket, 'disconnect');
            $this->call('accept', $clientSocket);
        } else {
            $this->log('handleAccept', 'error', 'stream_socket_accept');
        }
    }

    private function handleReceive($socket) {
        $buffersize = self::default_buffer_size;
        if ($this->bufferSocketsSize->contains($socket)) {
            $buffersize = $this->bufferSocketsSize->offsetGet($socket);
        }
        $data = stream_socket_recvfrom($socket, $buffersize, 0);
        if ($data === false) {
            $this->log('handleReceive', 'error', 'stream_socket_recvfrom');
            $this->close($socket);
            return;
        }
        if (strlen($data) === 0) {
            $this->close($socket);
            return;
        }
        if ($this->bufferReceiveSockets->contains($socket)) {
            $this->bufferReceiveSockets->offsetSet($socket, $this->bufferReceiveSockets->offsetGet($socket) . $data);
        } else {
            $this->bufferReceiveSockets->attach($socket, $data);
        }
        $this->call('receive', $socket);
    }

    private function handleException($socket) {
        $this->log('handleException', 'Exception occurred on socket');
        $this->close($socket);
    }

    private function handleDisconnect($socket) {
        if ($this->closeSockets->contains($socket)) {
            $this->call('disconnect', $socket);
            $this->delSocket($socket);
            fclose($socket);
        }
    }

    private function handleWrite($socket) {
        if ($this->bufferSockets->contains($socket)) {
            $data = $this->bufferSockets->offsetGet($socket);
            $sent = fwrite($socket, $data);
            if ($sent === false) {
                $this->log('handleWrite', 'error', 'fwrite');
                $this->close($socket);
                return;
            } else {
                $diff = strlen($data) - $sent;
                if ($diff == 0) {
                    $this->bufferSockets->detach($socket);
                    $this->removeEvent($socket, 'write');
                } else {
                    $this->bufferSockets->offsetSet($socket, substr($data, $data));
                }
            }
        }
        return true;
    }

    //--------------------------------------------------------------------------

    private function call($type, ...$args) {
        if (isset($this->callbacks[$type])) {
            call_user_func($this->callbacks[$type], ...$args);
        }
    }

    public function setOnIdleCallback($callback) {
        $this->callbacks['idle'] = $callback;
    }

    public function setOnReceiveCallback($callback) {
        $this->callbacks['receive'] = $callback;
    }

    public function setOnConnectCallback($callback) {
        $this->callbacks['connect'] = $callback;
    }

    public function setOnAcceptCallback($callback) {
        $this->callbacks['accept'] = $callback;
    }

    public function setOnDisconnectCallback($callback) {
        $this->callbacks['disconnect'] = $callback;
    }

    //--------------------------------------------------------------------------

    /**
     * Reads data from a socket's receive buffer.
     *
     * @param resource $socket Socket from which to read data.
     * @param bool $clear Whether to clear the receive buffer after reading.
     * @return string|null Data read from the socket's receive buffer, or null if no data available.
     */
    public function read($socket, $clear = true) {
        $data = null;
        if ($this->bufferReceiveSockets->contains($socket)) {
            $data = $this->bufferReceiveSockets->offsetGet($socket);
        }
        if ($clear) {
            $this->bufferReceiveSockets->detach($socket);
        }
        return $data;
    }

    /**
     * Sends data through a socket.
     *
     * @param resource $socket Socket through which to send data.
     * @param string $data Data to send.
     * @return void
     */
    public function send($socket, $data) {
        if ($this->bufferSockets->contains($socket)) {
            $this->bufferSockets->offsetSet($socket, $this->bufferSockets->offsetGet($socket) . $data);
        } else {
            $this->bufferSockets->attach($socket, $data);
        }
        $this->addEvent($socket, 'write');
    }

    /**
     * Closes a socket.
     *
     * @param resource $socket Socket to close.
     * @return void
     */
    public function close($socket) {
        $this->closeSockets->attach($socket);
    }

    /**
     * Ends all connections and stops the event loop.
     *
     * @return void
     */
    public function end() {
        $this->finish = true;
        foreach ($this->sockets as $sock) {
            $this->close($sock);
        }
    }

    protected function checkConnectAndClose() {

        if ($this->connectSockets->count()) {
            $cevents = $this->connectSockets->toArray();
            foreach ($cevents as $socket) {
                $this->handleConnect($socket);
            }
        }

        if ($this->closeSockets->count()) {
            $closeSockets = $this->closeSockets->toArray();
            foreach ($closeSockets as $socket) {
                if (!$this->bufferSockets->contains($socket)) {
                    $this->handleDisconnect($socket);
                }
            }
        }
    }

    public function dispatch($stream = null, int $type = null) {

        if ($type == \GaragLib\GAsync\GAsync::STREAM_READ) {
            $events = $this->events->contains($stream) ? $this->events->offsetGet($stream) : [];
            if (isset($events['accept'])) {
                $this->handleAccept($stream);
            }
            if (isset($events['receive'])) {
                $this->handleReceive($stream);
            }
        }

        if ($type == \GaragLib\GAsync\GAsync::STREAM_WRITE) {
            $events = $this->events->contains($stream) ? $this->events->offsetGet($stream) : [];
            if (isset($events['write'])) {
                $this->handleWrite($stream);
            }
        }

        $this->checkConnectAndClose();

        return false;
    }

    public function idle() {
        $this->call('idle', $this);
        $this->checkConnectAndClose();
        if ($this->finish && !$this->sockets->count()) {
            $this->timer->clear();
            \GaragLib\GAsync\GAsync::removeIdle($this);
        }
    }

    //--------------------------------------------------------------------------
}

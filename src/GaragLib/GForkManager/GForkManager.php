<?php

namespace GaragLib\GForkManager;

class GForkManager {

    /**
     * @var int
     */
    private $pid;

    /**
     * @var bool
     */
    private $isRoot;

    /**
     * @var int
     */
    private $running = 0;

    /**
     * @var GaragLib\GSocketManager\GSocketManager
     */
    private $smngr;

    /**
     * @var int
     */
    private $max_num_proc;

    /**
     * @var \SplObjectStorage
     */
    private $wait_workers = null;

    /**
     * @var mixed
     */
    private $pid_sock_map = null;

    /**
     * @var mixed
     */
    private $pid_terminate = null;

    /**
     * @var GFactoryWorker
     */
    private $running_worker = null;

    /**
     * @var mixed
     */
    private $callbacks = [];

    /**
     * @var boolean
     */
    private $waiting = false;

    public function __construct($maxnumproc, $timeout = null) {
        if (gc_enabled() === false) {
            gc_enable();
        }
        $this->pid = posix_getpid();
        $this->isRoot = true;
        $this->max_num_proc = $maxnumproc;
        $this->wait_workers = new \SplObjectStorage();
        $this->smngr = new \GaragLib\GSocketManager\GSocketManager($timeout);
        $this->smngr->setOnConnectCallback([$this, 'onConnect']);
        $this->smngr->setOnAcceptCallback([$this, 'onAccept']);
        $this->smngr->setOnDisconnectCallback([$this, 'onDisconnect']);
        $this->smngr->setOnIdleCallback([$this, 'onIdle']);
        $this->smngr->setOnReceiveCallback([$this, 'onReceive']);
    }

    private function resetVarsForChild() {
        $this->pid = posix_getpid();
        $this->isRoot = false;
        unset($this->max_num_proc);
        unset($this->wait_workers);
        unset($this->pid_terminate);
        unset($this->pid_sock_map);
        unset($this->running);
        unset($this->callbacks);
        unset($this->waiting);
    }

    public function isRoot() {
        return $this->isRoot;
    }

    public function setCallback($type, $callback) {
        //---
        if (!$this->isRoot)
            throw new \Exception('not root');
        //---
        $this->callbacks[$type] = $callback;
    }

    public function call($type, ...$args) {
        //---
        if (!$this->isRoot)
            throw new \Exception('not root');
        //---
        if (isset($this->callbacks[$type])) {
            $pid = null;
            if (in_array($sock, $this->pid_sock_map)) {
                $pid = array_search($sock, $this->pid_sock_map);
            }
            call_user_func($this->callbacks[$type], $pid, ...$args);
        }
    }

    public function add($classname, ...$args) {
        //---
        if (!$this->isRoot)
            throw new \Exception('not root');
        //---
        $this->wait_workers->attach(new GFactoryWorker($classname, ...$args));
        //---
    }

    public function kill($pid) {
        //---
        if (!$this->isRoot)
            throw new \Exception('not root');
        //---
        if (is_resource($pid)) {
            $pid = null;
            if (in_array($sock, $this->pid_sock_map)) {
                $pid = array_search($sock, $this->pid_sock_map);
            } else {
                return false;
            }
        }
        //---
        if (isset($this->pid_sock_map[$pid])) {
            $this->smngr->close($this->pid_sock_map[$pid]);
        }
        //---
        //return posix_kill($pid, SIGTERM);
        //---
    }

    //--------------------------------------------------------------------------

    public function onAccept($sock) {
        if ($this->isRoot) {
            $this->call('accept', $sock);
        } else {
            $this->running_worker->onAccept($sock);
        }
    }

    public function onConnect($sock) {
        if ($this->isRoot) {
            $this->call('connect', $sock);
        } else {
            $this->running_worker->onConnect($sock);
        }
    }

    public function onDisconnect($sock) {
        if ($this->isRoot) {
            if (in_array($sock, $this->pid_sock_map)) {
                $pid = array_search($sock, $this->pid_sock_map);
                $this->pid_terminate[] = $pid;
                unset($this->pid_sock_map[$pid]);
                $this->running--;
                $ret = $this->run();
                if ($this->isRoot) {
                    $this->call('disconnect', $sock, $ret);
                }
            }
        } else {
            $this->running_worker->onDisconnect($sock);
            $this->smngr->end();
        }
    }

    public function onIdle() {
        if ($this->isRoot) {
            $this->checkZombies();
            $this->call('idle');
        } else {
            $this->running_worker->onIdle();
        }
    }

    public function onReceive($sock) {
        $data = $this->smngr->read($sock);
        if ($this->isRoot) {
            $this->call('receive', $sock, $data);
        } else {
            $this->running_worker->onReceive($sock, $data);
        }
    }

    //--------------------------------------------------------------------------

    private function checkZombies() {
        if ($this->pid_terminate) {
            $del = [];
            foreach ($this->pid_terminate as $pid) {
                $result = pcntl_waitpid($pid, $status, WNOHANG);
                if ($result > 0) {
                    if (pcntl_wifexited($status)) {
                        $exitCode = pcntl_wexitstatus($status);
                        $del[] = $pid;
                    } else {
                        $signal = pcntl_wtermsig($status);
                        $del[] = $pid;
                    }
                }
            }
            foreach ($del as $pid) {
                unset($this->pid_terminate[array_search($pid, $this->pid_terminate)]);
            }
        }
    }

    public function run() {
        if ($this->isRoot) {
            $this->wait_workers->rewind();
            while ($this->wait_workers->valid()) {
                //---
                if ($this->running >= $this->max_num_proc) {
                    break;
                }
                //---
                $this->running++;
                $facworker = $this->wait_workers->current();
                $this->wait_workers->detach($facworker);
                $this->wait_workers->rewind();
                //---
                list($sock1, $sock2) = $this->smngr->createSocketPair();
                //---
                $pid = pcntl_fork();
                if ($pid == -1) {
                    throw new \Exception("Can't fork process.");
                } else if ($pid) {
                    $this->pid_sock_map[$pid] = $sock1;
                    $this->smngr->addSocket($sock1);
                    $this->onConnect($sock1);
                } else {
                    //---
                    \GaragLib\GAsync\GAsync::fork();
                    $this->resetVarsForChild();
                    $this->smngr->fork();
                    //---
                    $this->smngr->addSocket($sock2);
                    $this->running_worker = $facworker->getInstance();
                    $this->running_worker->setSocketManager($this->smngr, $sock2);
                    gc_collect_cycles();
                    //---
                    try {
                        $this->onConnect($sock2);
                        $this->running_worker->run();
                    } catch (\Exception $ex) {
                        
                    }
                    //---
                    $this->smngr->end();
                    //---
                    break;
                }
                //---
            }
            if ($this->isRoot) {
                if ($this->waiting && $this->running == 0) {
                    $this->smngr->end();
                }
                return $this->pid_sock_map;
            }
        }
        return false;
    }

    public function wait($sec = 0, $usec = 100000) {
        if ($this->isRoot) {
            $this->waiting = true;
            \GaragLib\GAsync\GAsync::run($sec, $usec);
        }
    }
}

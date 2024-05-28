<?php

namespace GaragLib\GForkManager;

abstract class GWorker {

    /**
     * @var GSocket\GSocketManager\GSocketManager
     */
    private $smngr;
    private $root_sock;

    public function setSocketManager($smgr, $sock) {
        $this->smngr = $smgr;
        $this->root_sock = $sock;
    }

    public function wait($sec = 0, $usec = 100000) {
        \GaragLib\GAsync\GAsync::run($sec, $usec);
    }

    public function onAccept($sock) {
        
    }

    public function onConnect($sock) {
        
    }

    public function onDisconnect($sock) {
        
    }

    public function onReceive($sock, $data) {
        
    }

    public function onIdle() {
        
    }

    abstract public function run();
}

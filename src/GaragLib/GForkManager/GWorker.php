<?php

namespace GaragLib;

abstract class GWorker {

    /**
     * @var GSocket\GSocketManager\GSocketManager
     */
    private $smngr;

    public function setSocketManager($smgr) {
        $this->smngr = $smgr;
    }

    public function processEvents($timeout = 0) {
        $this->smngr->processEvents($timeout);
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

    public function run() {
        
    }
}

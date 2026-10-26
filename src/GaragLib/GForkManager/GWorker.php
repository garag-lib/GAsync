<?php

declare(strict_types=1);

namespace GaragLib\GForkManager;

use GaragLib\GAsync\GAsync;
use GaragLib\GSocketManager\GSocketManager;

abstract class GWorker
{
    protected GSocketManager $smngr;
    protected mixed $root_sock;

    public function setSocketManager(GSocketManager $smgr, $sock): void
    {
        $this->smngr = $smgr;
        $this->root_sock = $sock;
    }

    public function wait(int $sec = 0, int $usec = 100000): void
    {
        GAsync::run($sec, $usec);
    }

    public function onAccept($sock)
    {
    }

    public function onConnect($sock)
    {
    }

    public function onDisconnect($sock)
    {
    }

    public function onReceive($sock, $data)
    {
    }

    public function onIdle()
    {
    }

    abstract public function run();
}

<?php

namespace GaragLib\GAsync\GDriver;

class GEV extends GDriver {

    private $streamEventMap;

    public function __construct() {
        if (!extension_loaded('ev')) {
            throw new \Exception('Ev extension is not loaded');
        }
    }

    public function getName() {
        return 'GEV';
    }

    public function addReadStream(\GaragLib\GResourceStorage $readers, $stream, \GaragLib\GAsync\GStream $assoc) {
        if (!$readers->contains($stream)) {
            $readers->attach($stream, $assoc);
            //---
            $obj = new \stdClass();
            $obj->stream = $stream;
            $event = new \EvIo($stream, \Ev::READ, [$this, 'eventRead'], $obj);
            $obj->event = $event;
            //---
            $id = get_resource_id($stream);
            $this->streamEventMap[$id][0] = $event;
            //---
        }
    }

    public function addWriteStream(\GaragLib\GResourceStorage $writers, $stream, \GaragLib\GAsync\GStream $assoc) {
        if (!$writers->contains($stream)) {
            $writers->attach($stream, $assoc);
            //---
            $obj = new \stdClass();
            $obj->stream = $stream;
            $event = new \EvIo($stream, \Ev::WRITE, [$this, 'eventWrite'], $obj);
            $obj->event = $event;
            //---
            $id = get_resource_id($stream);
            $this->streamEventMap[$id][1] = $event;
            //---
        }
    }

    public function removeReadStream(\GaragLib\GResourceStorage $readers, $stream) {
        if ($readers->contains($stream)) {
            //---
            $id = get_resource_id($stream);
            $event = $this->streamEventMap[$id][0];
            $event->stop();
            $event->clear();
            unset($this->streamEventMap[$id][0]);
            if (!count($this->streamEventMap[$id]))
                unset($this->streamEventMap[$id]);
            //---
            $readers->detach($stream);
        }
    }

    public function removeWriteStream(\GaragLib\GResourceStorage $writers, $stream) {
        if ($writers->contains($stream)) {
            //---
            $id = get_resource_id($stream);
            $event = $this->streamEventMap[$id][1];
            $event->stop();
            $event->clear();
            unset($this->streamEventMap[$id][1]);
            if (!count($this->streamEventMap[$id]))
                unset($this->streamEventMap[$id]);
            //---
            $writers->detach($stream);
        }
    }

    public function eventRead($watcher, $revents) {
        \GaragLib\GAsync\GAsync::readEvent($watcher->data->stream);
    }

    public function eventWrite($watcher, $revents) {
        \GaragLib\GAsync\GAsync::writeEvent($watcher->data->stream);
    }

    public function dispatch(\GaragLib\GResourceStorage $readers, \GaragLib\GResourceStorage $writers, $timeoutSec, $timeoutMicro) {
        \Ev::run(\Ev::RUN_ONCE);
        //\Ev::run(\Ev::RUN_NOWAIT);
        /*
          $diff = microtime(true) - $this->lastDispatch;
          $this->lastDispatch = microtime(true);
          usleep(($timeoutSec + $timeoutMicro / 1000000 ) * 1000000);
         */
    }
}

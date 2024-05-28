<?php

namespace GaragLib\GAsync\GDriver;

class GEVENT extends GDriver {

    public $base;
    private $streamEventMap;

    public function __construct() {
        if (!extension_loaded('event')) {
            throw new \Exception('Event extension is not loaded');
        }
        $this->base = new \EventBase();
    }

    public function getName() {
        return 'GEVENT';
    }

    public function addReadStream(\GaragLib\GResourceStorage $readers, $stream, \GaragLib\GAsync\GStream $assoc) {
        if (!$readers->contains($stream)) {
            $readers->attach($stream, $assoc);
            //---
            $obj = new \stdClass();
            $obj->stream = $stream;
            //---
            $event = new \Event($this->base, $stream, \Event::READ | \Event::PERSIST, [$this, 'eventRead'], $obj);
            $event->add();
            //---
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
            //---
            $event = new \Event($this->base, $stream, \Event::WRITE | \Event::PERSIST, [$this, 'eventWrite'], $obj);
            $event->add();
            //---
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
            $event->del();
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
            $event->del();
            unset($this->streamEventMap[$id][1]);
            if (!count($this->streamEventMap[$id]))
                unset($this->streamEventMap[$id]);
            //---
            $writers->detach($stream);
        }
    }

    public function eventRead($stream, $flag, $data) {
        \GaragLib\GAsync\GAsync::readEvent($data->stream);
    }

    public function eventWrite($stream, $flag, $data) {
        \GaragLib\GAsync\GAsync::writeEvent($data->stream);
    }

    public function dispatch(\GaragLib\GResourceStorage $readers, \GaragLib\GResourceStorage $writers, $timeoutSec, $timeoutMicro) {
        $this->base->loop(\EventBase::LOOP_ONCE);
    }

    public function fork() {
        $this->base->reInit();
        //$this->base = new \EventBase();
    }
}

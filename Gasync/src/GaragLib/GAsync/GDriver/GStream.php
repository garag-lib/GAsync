<?php

namespace GaragLib\GAsync\GDriver;

class GStream extends GDriver {

    public function getName() {
        return 'GStream';
    }

    public function addReadStream(\GaragLib\GResourceStorage $readers, $stream, \GaragLib\GAsync\GStream $assoc) {
        $readers->attach($stream, $assoc);
    }

    public function addWriteStream(\GaragLib\GResourceStorage $writers, $stream, \GaragLib\GAsync\GStream $assoc) {
        $writers->attach($stream, $assoc);
        \GaragLib\GAsync\GAsync::NOP();
    }

    public function removeReadStream(\GaragLib\GResourceStorage $readers, $stream) {
        $readers->detach($stream);
    }

    public function removeWriteStream(\GaragLib\GResourceStorage $writers, $stream) {
        $writers->detach($stream);
    }

    public function dispatch(\GaragLib\GResourceStorage $readers, \GaragLib\GResourceStorage $writers, $timeoutSec, $timeoutMicro) {
        //---
        $readStreams = $readers->toArray();
        $writeStreams = [];
        $exceptionStreams = [];
        //---
        $result = stream_select($readStreams, $writeStreams, $exceptionStreams, $timeoutSec, $timeoutMicro);
        //---
        if ($result === false) {
            throw new \Exception('stream_select error');
        }
        //---
        foreach ($readStreams as $stream) {
            \GaragLib\GAsync\GAsync::readEvent($stream);
        }
        //---
        if ($writers->count()) {
            $writeStreams = $writers->toArray();
            foreach ($writeStreams as $stream) {
                \GaragLib\GAsync\GAsync::writeEvent($stream);
            }
            if ($writers->count()) {
                \GaragLib\GAsync\GAsync::NOP();
            }
        }
        //---
    }
}

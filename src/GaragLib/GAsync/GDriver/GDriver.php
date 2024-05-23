<?php

namespace GaragLib\GAsync\GDriver;

abstract class GDriver {

    public static function getDriver($name = null) {
        if ($name == 'GStream' || $name === null) {
            return new GStream();
        }
        if ($name == 'GEV') {
            return new GEV();
        }
        if ($name == 'GEVENT') {
            return new GEVENT();
        }
    }

    abstract public function getName();

    abstract public function addReadStream(\GaragLib\GResourceStorage $readers, $stream, \GaragLib\GAsync\GStream $assoc);

    abstract public function addWriteStream(\GaragLib\GResourceStorage $writers, $stream, \GaragLib\GAsync\GStream $assoc);

    abstract public function removeReadStream(\GaragLib\GResourceStorage $readers, $stream);

    abstract public function removeWriteStream(\GaragLib\GResourceStorage $writers, $stream);

    abstract public function dispatch(\GaragLib\GResourceStorage $readers, \GaragLib\GResourceStorage $writers, $timeoutSec, $timeoutMicro);
}

<?php

namespace GaragLib\GAsync;

class GTimer extends GStream {

    private $start;
    private $duration;
    private $repeat = false;
    private $callback;
    private $stop = false;
    private $timer;

    public function __construct($duration, $repeat, $callback = null) {
        //---
        $this->callback = $callback;
        //---
        if (GAsync::getDriver()->getName() == 'GStream') {
            $this->start = microtime(true);
            $this->duration = ((float) $duration ) / 1000000.0;
            $this->repeat = $repeat;
        }
        //---
        if (GAsync::getDriver()->getName() == 'GEV') {
            $this->start = microtime(true);
            $this->duration = ((float) $duration ) / 1000000.0;
            $this->repeat = $repeat;
            $this->timer = new \EvTimer($this->duration, $repeat ? $this->duration : 0, [$this, 'eventTimer']);
        }
        //---
        if (GAsync::getDriver()->getName() == 'GUV') {
            $this->start = microtime(true);
            $this->duration = ((float) $duration ) / 1000000.0;
            $this->repeat = $repeat;
            $this->timer = \uv_timer_init(GAsync::getDriver()->loop);
            \uv_timer_start($this->timer, $this->duration, $repeat ? $this->duration : 0, [$this, 'eventTimer']);
        }
        //---
        if ($this->repeat) {
            \GaragLib\GAsync\GAsync::addIdle($this);
        }
        //---
    }

    public function setCallback($callback) {
        $this->callback = $callback;
    }

    public function eventTimer() {
        $this->start = microtime(true);
        $this->result = microtime(true) - $this->start;
        if ($this->callback) {
            call_user_func($this->callback, $this, $this->getResult());
        }
        if (!$this->repeat) {
            $this->stop();
            $this->timer->clear();
        }
    }

    public function hasResult() {
        //---
        $drivername = GAsync::getDriver()->getName();
        //---
        if ($drivername == 'GStream') {
            if ($this->stop) {
                return false;
            }
            $diff = microtime(true) - $this->start;
            if ($diff >= $this->duration) {
                $this->result = $diff;
                return true;
            }
            return false;
        }
        //---
        return parent::hasResult();
        //---
    }

    public function idle() {
        $this->dispatch();
    }

    public function dispatch($stream = null, int $type = null) {
        if (GAsync::getDriver()->getName() == 'GStream') {
            if (!$this->stop && $this->hasResult()) {
                $this->start = microtime(true);
                if ($this->callback) {
                    call_user_func($this->callback, $this, $this->getResult());
                }
                if (!$this->repeat) {
                    $this->stop = true;
                }
            }
        }
    }

    public function stop() {
        $this->stop = true;
        if (GAsync::getDriver()->getName() != 'GStream') {
            $this->timer->stop();
        }
    }

    public function start() {
        $this->stop = true;
        if (GAsync::getDriver()->getName() != 'GStream') {
            $this->timer->start();
        }
    }

    public function clear() {
        $this->stop = true;
        if (GAsync::getDriver()->getName() != 'GStream') {
            $this->timer->stop();
        }
        \GaragLib\GAsync\GAsync::removeIdle($this);
    }
}

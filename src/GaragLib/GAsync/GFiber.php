<?php

namespace GaragLib\GAsync;

class GFiber {

    private $callback;

    public function __construct($callback = null) {
        $this->callback = $callback;
    }

    public function setCallback($callback) {
        $this->callback = $callback;
    }

    public function resume(...$args) {
        call_user_func($this->callback, ...$args);
    }
}

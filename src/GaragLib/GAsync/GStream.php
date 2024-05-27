<?php

namespace GaragLib\GAsync;

class GStream {

    protected $result = null;

    public function hasResult() {
        return $this->result !== null;
    }

    public function getResult() {
        $temp = $this->result;
        $this->result = null;
        return $temp;
    }

    public function dispatch($stream = null, int $type = null) {
        
    }

    public function idle() {
        
    }
}

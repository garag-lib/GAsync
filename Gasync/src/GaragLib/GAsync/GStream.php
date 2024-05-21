<?php

namespace GaragLib\GAsync;

class GStream {

    protected $result = null;
    protected $userData = null;

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

    public function setUserData($data) {
        $this->userData = $data;
    }

    public function getUserData() {
        return $this->userData;
    }
}

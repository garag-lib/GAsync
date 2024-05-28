<?php

namespace GaragLib\GForkManager;

class GFactoryWorker {

    private $className;
    private $args;

    public function __construct($name, ...$args) {
        $this->className = $name;
        $this->args = $args;
    }

    public function getInstance() {
        if (class_exists($this->className)) {
            return new $this->className(...$this->args);
        } else {
            throw new Exception("La clase {$this->className} no existe.");
        }
    }
}

<?php

namespace GaragLib\GAsync;

class GStreamWrite extends GStream {

    private $stream;
    private $open;
    private $data;

    public function __construct($stream, $open = false, $data = null) {
        $this->stream = $stream;
        $this->open = $open;
        $this->data = $data;
        $this->result = new \SplQueue();
    }

    public function hasResult() {
        return $this->result->count();
    }

    public function getResult() {
        return $this->result->dequeue();
    }

    public function setData($data) {
        $this->data .= $data;
    }

    public function dispatch($stream = null, int $type = null) {
        if ($this->result->count())
            return true;
        if (!$this->open) {
            $this->open = true;
            $this->result->enqueue($this->stream);
            return true;
        }
        $write = fwrite($this->stream, $this->data);
        if ($write === false) {
            $this->result->enqueue(false);
            return true;
        }
        if ($write === 0) {
            return false;
        }
        if ($write < strlen($this->data)) {
            $this->data = substr($this->data, $write);
            return false;
        }
        $this->data = '';
        $this->result->enqueue($write);
        return true;
    }
}

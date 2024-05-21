<?php

namespace GaragLib\GAsync;

class GStreamRead extends GStream {

    private $stream;
    private $open;
    private $length;

    public function __construct($stream, $length = null) {
        $this->stream = $stream;
        $this->open = false;
        $this->length = $length;
        $this->result = new \SplQueue();
    }

    public function hasResult() {
        return $this->result->count();
    }

    public function getResult() {
        return $this->result->dequeue();
    }

    public function setLength($length) {
        $this->length = $length;
    }

    public function dispatch($stream = null, int $type = null) {
        if ($this->result->count())
            return false;
        if (feof($this->stream)) {
            $this->result->enqueue(false);
            return true;
        }
        if (!$this->open) {
            $this->open = true;
            $this->result->enqueue($this->stream);
            return false;
        }
        if ($this->length) {
            $temp = \fread($this->stream, $this->length);
            if ($temp === false) {
                $this->result->enqueue(false);
                return false;
            }
            if (strlen($temp)) {
                $this->result->enqueue($temp);
                $temp = null;
                return false;
            }
        }
        return false;
    }
}

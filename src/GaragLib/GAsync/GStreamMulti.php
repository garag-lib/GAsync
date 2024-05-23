<?php

namespace GaragLib\GAsync;

class GStreamMulti extends GStream {

    private $gstreams;

    public function __construct($stream) {
        $this->stream = $stream;
        $this->gstreams = [];
    }

    public function addGStream($gstream) {
        $this->gstreams[] = $gstream;
    }

    public function hasResult() {
        foreach ($this->gstreams as $gstream) {
            if (!$gstream->hasResult()) {
                return false;
            }
        }
        return true;
    }

    public function getResult() {
        return $this->gstreams[0]->getResult();
    }
}

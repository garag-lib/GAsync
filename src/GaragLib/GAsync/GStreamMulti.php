<?php

namespace GaragLib\GAsync;

class GStreamMulti extends GStream {

    private $gstreams;
    private $returnAll;

    public function __construct($stream, $returnAll = false) {
        $this->gstreams = [];
        $this->stream = $stream;
        $this->returnAll = $returnAll;
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
        if ($this->returnAll) {
            $ret = [];
            foreach ($this->gstreams as $gstream) {
                $ret[] = $gstream->getResult();
            }
            return $ret;
        }
        return $this->gstreams[0]->getResult();
    }
}

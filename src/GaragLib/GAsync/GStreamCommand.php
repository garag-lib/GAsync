<?php

namespace GaragLib\GAsync;

class GStreamCommand extends GStream {

    private $stream;

    public function __construct($stream, $length = null) {
        $this->stream = $stream;
    }

    public function dispatch($stream = null, int $type = null) {
        static $buffer = '';
        $cmd = fread($this->stream, 128);
        if (strlen($cmd)) {
            $buffer .= $cmd;
            $cmds = explode("\n", $buffer);
            foreach ($cmds as $cmd) {
                switch ($cmd) {
                    case 'STOP':
                        $buffer = '';
                        GAsync::exit();
                        break;
                    case 'NOP':
                        $buffer = '';
                        break;
                    default:
                        $buffer = $cmd;
                        break;
                }
            }
        }
        return false;
    }
}

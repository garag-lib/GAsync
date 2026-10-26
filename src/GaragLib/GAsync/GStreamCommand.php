<?php

declare(strict_types=1);

namespace GaragLib\GAsync;

/** @internal */
class GStreamCommand extends GStream
{
    private string $buffer = '';

    public function __construct(private mixed $stream)
    {
    }

    public function dispatch($stream = null, ?int $type = null): bool
    {
        $data = GAsync::io(fn () => \fread($this->stream, 8192));
        if ($data === false) {
            throw new \RuntimeException('Cannot read control socket');
        }
        $this->buffer .= $data;
        while (($end = strpos($this->buffer, "\n")) !== false) {
            $cmd = substr($this->buffer, 0, $end);
            $this->buffer = substr($this->buffer, $end + 1);
            if ($cmd === 'STOP') {
                GAsync::exit();
            }
        }
        return false;
    }
}

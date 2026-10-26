<?php

declare(strict_types=1);

namespace GaragLib\GAsync;

/** @internal FIFO of read requests for a single stream. */
class GStreamRead extends GStream
{
    private \SplQueue $operations;

    public function __construct(private mixed $stream)
    {
        $this->operations = new \SplQueue();
    }

    public function enqueue(GOperation $operation): void
    {
        $this->operations->enqueue($operation);
    }

    public function cancel(\Throwable $error): void
    {
        while (!$this->operations->isEmpty()) {
            $this->operations->dequeue()->resolve(null, $error);
        }
    }

    public function dispatch($stream = null, ?int $type = null): bool
    {
        if ($this->operations->isEmpty()) {
            return true;
        }
        $operation = $this->operations->bottom();
        try {
            if (!is_resource($this->stream)) {
                throw new \RuntimeException('Read stream was closed');
            }
            $data = GAsync::io(fn () => \fread($this->stream, min($operation->input, 65536)));
            if ($data === false) {
                throw new \RuntimeException('Stream read failed');
            }
            if ($data === '' && !feof($this->stream)) {
                return false;
            }
            $this->operations->dequeue();
            $operation->resolve($data);
        } catch (\Throwable $error) {
            $this->operations->dequeue();
            $operation->resolve(null, $error);
        }
        return $this->operations->isEmpty();
    }
}

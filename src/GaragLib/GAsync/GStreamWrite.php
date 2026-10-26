<?php

declare(strict_types=1);

namespace GaragLib\GAsync;

/** @internal FIFO of complete-buffer write requests for a single stream. */
class GStreamWrite extends GStream
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

    public function hasImmediateWork(): bool
    {
        return !$this->operations->isEmpty() && $this->operations->bottom()->input === '';
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
                throw new \RuntimeException('Write stream was closed');
            }
            $remaining = strlen($operation->input) - $operation->offset;
            if ($remaining > 0) {
                $written = GAsync::io(fn () => \fwrite($this->stream, substr($operation->input, $operation->offset, 65536)));
                if ($written === false) {
                    throw new \RuntimeException('Stream write failed');
                }
                if ($written === 0) {
                    return false;
                }
                $operation->offset += $written;
            }
            if ($operation->offset === strlen($operation->input)) {
                $this->operations->dequeue();
                $operation->resolve($operation->offset);
            }
        } catch (\Throwable $error) {
            $this->operations->dequeue();
            $operation->resolve(null, $error);
        }
        return $this->operations->isEmpty();
    }
}

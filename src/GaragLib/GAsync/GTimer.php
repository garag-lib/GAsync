<?php

declare(strict_types=1);

namespace GaragLib\GAsync;

class GTimer extends GStream
{
    private float $started;
    private float $deadline;
    private float $duration;
    private bool $active = false;
    private ?\Closure $callback;

    /** Duration is expressed in microseconds; getDuration() returns seconds. */
    public function __construct(int|float $duration, private bool $repeat, ?callable $callback = null)
    {
        if (!is_finite((float) $duration) || $duration < 0 || ($repeat && $duration <= 0)) {
            throw new \InvalidArgumentException('Timer duration must be finite and nonnegative (positive for repeating timers)');
        }
        $this->duration = $duration / 1e6;
        $this->setCallback($callback);
        $this->start();
    }

    public function getDuration(): float
    {
        return $this->duration;
    }

    public function setCallback(?callable $callback): void
    {
        $this->callback = $callback === null ? null : \Closure::fromCallable($callback);
    }

    public function remaining(): float
    {
        return max(0.0, $this->deadline - hrtime(true) / 1e9);
    }

    public function start(): void
    {
        $this->result = null;
        $this->started = hrtime(true) / 1e9;
        $this->deadline = $this->started + $this->duration;
        $this->active = true;
        GAsync::registerTimer($this);
    }

    public function stop(): void
    {
        $this->active = false;
        GAsync::unregisterTimer($this);
    }

    public function clear(): void
    {
        $this->stop();
        $this->callback = null;
        $this->result = null;
    }

    public function eventTimer(): void
    {
        $this->dispatch();
    }

    public function idle(): void
    {
        $this->dispatch();
    }

    public function dispatch($stream = null, ?int $type = null): bool
    {
        if (!$this->active || $this->remaining() > 0) {
            return false;
        }
        $now = hrtime(true) / 1e9;
        $this->result = $now - $this->started;
        if ($this->repeat) {
            $this->started = $now;
            $this->deadline = $now + $this->duration;
        } else {
            $this->stop();
        }
        if ($this->callback !== null) {
            ($this->callback)($this, $this->getResult());
        }
        return false;
    }
}

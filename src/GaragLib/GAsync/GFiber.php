<?php

declare(strict_types=1);

namespace GaragLib\GAsync;

/** Callback compatibility helper; new scheduling uses GAsync::defer(). */
class GFiber
{
    private ?\Closure $callback;

    public function __construct(?callable $callback = null)
    {
        $this->setCallback($callback);
    }

    public function setCallback(?callable $callback): void
    {
        $this->callback = $callback === null ? null : \Closure::fromCallable($callback);
    }

    public function resume(mixed ...$args): mixed
    {
        if ($this->callback === null) {
            throw new \LogicException('No callback configured');
        }
        return ($this->callback)(...$args);
    }
}

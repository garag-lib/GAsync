<?php

declare(strict_types=1);

namespace GaragLib\GAsync;

/** @internal One completion per call, never shared by concurrent callers. */
final class GOperation
{
    public int $offset = 0;
    public bool $complete = false;

    public function __construct(public mixed $input, private readonly \Closure $continuation)
    {
    }

    public function resolve(mixed $value = null, ?\Throwable $error = null): void
    {
        if ($this->complete) {
            return;
        }
        $this->complete = true;
        GAsync::defer(fn () => ($this->continuation)($value, $error));
    }
}

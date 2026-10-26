<?php

declare(strict_types=1);

namespace GaragLib\GAsync;

class GStream
{
    protected mixed $result = null;

    public function hasResult(): bool
    {
        return $this->result !== null;
    }

    public function getResult(): mixed
    {
        $value = $this->result;
        $this->result = null;
        return $value;
    }

    public function dispatch($stream = null, ?int $type = null): bool
    {
        return false;
    }

    public function idle(): void
    {
    }
}

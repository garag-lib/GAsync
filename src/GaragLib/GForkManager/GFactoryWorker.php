<?php

declare(strict_types=1);

namespace GaragLib\GForkManager;

class GFactoryWorker
{
    private array $args;

    public function __construct(private string $className, mixed ...$args)
    {
        $this->args = $args;
    }

    public function getInstance(): GWorker
    {
        if (!is_subclass_of($this->className, GWorker::class)) {
            throw new \InvalidArgumentException('Worker must extend ' . GWorker::class);
        }
        return new ($this->className)(...$this->args);
    }
}

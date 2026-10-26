<?php

declare(strict_types=1);

namespace GaragLib\GAsync\GDriver;

use GaragLib\GAsync\GAsync;

class GEV extends GDriver
{
    private object $loop;
    private array $watchers = [];
    private array $events = [];

    public function __construct()
    {
        if (!extension_loaded('ev')) {
            throw new \RuntimeException('The ev extension is required for GEV');
        }
        $this->loop = new \EvLoop();
    }

    public function getName(): string
    {
        return 'GEV';
    }

    protected function watch($stream, int $type): void
    {
        $this->watchers[get_resource_id($stream)][$type] = $this->loop->io(
            $stream,
            $type === GAsync::STREAM_READ ? \Ev::READ : \Ev::WRITE,
            function () use ($stream, $type): void {
                $this->events[] = [$stream, $type];
            }
        );
    }

    protected function unwatch(int $id, int $type): void
    {
        if (isset($this->watchers[$id][$type])) {
            $this->watchers[$id][$type]->stop();
            $this->watchers[$id][$type]->clear();
            unset($this->watchers[$id][$type]);
            if ($this->watchers[$id] === []) {
                unset($this->watchers[$id]);
            }
        }
    }

    public function fork(): void
    {
        $this->loop->loopFork();
        parent::fork();
    }

    protected function poll(float $timeout): array
    {
        $this->events = [];
        $timer = $timeout > 0 ? $this->loop->timer($timeout, 0, static function (): void {
        }) : null;
        try {
            $this->loop->run($timeout > 0 ? \Ev::RUN_ONCE : \Ev::RUN_NOWAIT);
        } finally {
            $timer?->stop();
            $timer?->clear();
        }
        return $this->events;
    }
}

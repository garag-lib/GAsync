<?php

declare(strict_types=1);

namespace GaragLib\GAsync\GDriver;

use GaragLib\GAsync\GAsync;

class GEVENT extends GDriver
{
    public readonly object $base;
    private array $watchers = [];
    private array $events = [];

    public function __construct()
    {
        if (!extension_loaded('event')) {
            throw new \RuntimeException('The event extension is required for GEVENT');
        }
        $this->base = new \EventBase();
    }

    public function getName(): string
    {
        return 'GEVENT';
    }

    protected function watch($stream, int $type): void
    {
        $event = new \Event(
            $this->base,
            $stream,
            ($type === GAsync::STREAM_READ ? \Event::READ : \Event::WRITE) | \Event::PERSIST,
            function () use ($stream, $type): void {
                $this->events[] = [$stream, $type];
            }
        );
        if (!$event->add()) {
            throw new \RuntimeException('Unable to register stream event');
        }
        $this->watchers[get_resource_id($stream)][$type] = $event;
    }

    protected function unwatch(int $id, int $type): void
    {
        if (isset($this->watchers[$id][$type])) {
            $this->watchers[$id][$type]->del();
            $this->watchers[$id][$type]->free();
            unset($this->watchers[$id][$type]);
            if ($this->watchers[$id] === []) {
                unset($this->watchers[$id]);
            }
        }
    }

    public function fork(): void
    {
        // Detach the inherited kernel poll set before deleting child watchers.
        if (!$this->base->reInit()) {
            throw new \RuntimeException('Cannot reinitialize libevent after fork');
        }
        parent::fork();
    }

    protected function poll(float $timeout): array
    {
        $this->events = [];
        $timer = $timeout > 0 ? \Event::timer($this->base, static function (): void {
        }) : null;
        $timer?->add($timeout);
        try {
            $this->base->loop(\EventBase::LOOP_ONCE | ($timeout > 0 ? 0 : \EventBase::LOOP_NONBLOCK));
        } finally {
            $timer?->del();
            $timer?->free();
        }
        return $this->events;
    }
}

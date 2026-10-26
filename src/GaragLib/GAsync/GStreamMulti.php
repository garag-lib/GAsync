<?php

declare(strict_types=1);

namespace GaragLib\GAsync;

/** Combines results of explicitly supplied GStream objects. */
class GStreamMulti extends GStream
{
    private array $gstreams = [];

    public function __construct(mixed $stream, private bool $returnAll = false)
    {
        GAsync::assertStream($stream);
    }

    public function addGStream(GStream $gstream): void
    {
        $this->gstreams[] = $gstream;
    }

    public function hasResult(): bool
    {
        if ($this->gstreams === []) {
            return false;
        }
        foreach ($this->gstreams as $stream) {
            if (!$stream->hasResult()) {
                return false;
            }
        }
        return true;
    }

    public function getResult(): mixed
    {
        if (!$this->hasResult()) {
            throw new \LogicException('Results are not ready');
        }
        $results = array_map(static fn (GStream $stream) => $stream->getResult(), $this->gstreams);
        return $this->returnAll ? $results : $results[0];
    }
}

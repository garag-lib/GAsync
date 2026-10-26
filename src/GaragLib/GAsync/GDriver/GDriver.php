<?php

declare(strict_types=1);

namespace GaragLib\GAsync\GDriver;

use GaragLib\GAsync\GAsync;
use GaragLib\GAsync\GStream as Watcher;
use GaragLib\GAsync\GStreamRead;
use GaragLib\GAsync\GStreamWrite;
use GaragLib\GResourceStorage;

abstract class GDriver
{
    protected array $readStreams = [];
    protected array $writeStreams = [];

    public static function getDriver(?string $name = null): self
    {
        return match ($name ?? 'GStream') {
            'GStream' => new GStream(), 'GEV' => new GEV(), 'GEVENT' => new GEVENT(),
            default => throw new \InvalidArgumentException('Unknown driver: ' . $name),
        };
    }

    abstract public function getName(): string;

    abstract protected function watch($stream, int $type): void;

    abstract protected function unwatch(int $id, int $type): void;

    /** @return array<array{mixed, int}> */
    abstract protected function poll(float $timeout): array;

    public static function isPollable($stream): bool
    {
        $meta = stream_get_meta_data($stream);
        $stat = fstat($stream);
        return $stat !== false && in_array($stat['mode'] & 0170000, [0010000, 0140000], true)
            && !str_contains($meta['stream_type'], 'user-space');
    }

    private function add(GResourceStorage $storage, $stream, Watcher $assoc, int $type): void
    {
        GAsync::assertStream($stream);
        $old = $storage[$stream];
        if ($old === $assoc) {
            return;
        }
        if ($old instanceof GStreamRead || $old instanceof GStreamWrite) {
            throw new \LogicException('Cannot replace a watcher with pending operations');
        }
        $this->remove($storage, $stream, $type);
        if (self::isPollable($stream)) {
            $this->watch($stream, $type);
            if ($type === GAsync::STREAM_READ) {
                $this->readStreams[get_resource_id($stream)] = $stream;
            } else {
                $this->writeStreams[get_resource_id($stream)] = $stream;
            }
        }
        $storage->attach($stream, $assoc);
    }

    private function remove(GResourceStorage $storage, $stream, int $type): void
    {
        $id = get_resource_id($stream);
        $this->unwatch($id, $type);
        if ($type === GAsync::STREAM_READ) {
            unset($this->readStreams[$id]);
        } else {
            unset($this->writeStreams[$id]);
        }
        $storage->detach($stream);
    }

    public function addReadStream(GResourceStorage $readers, $stream, Watcher $assoc): void
    {
        $this->add($readers, $stream, $assoc, GAsync::STREAM_READ);
    }

    public function addWriteStream(GResourceStorage $writers, $stream, Watcher $assoc): void
    {
        $this->add($writers, $stream, $assoc, GAsync::STREAM_WRITE);
    }

    public function removeReadStream(GResourceStorage $readers, $stream): void
    {
        $this->remove($readers, $stream, GAsync::STREAM_READ);
    }

    public function removeWriteStream(GResourceStorage $writers, $stream): void
    {
        $this->remove($writers, $stream, GAsync::STREAM_WRITE);
    }

    public function dispatch(GResourceStorage $readers, GResourceStorage $writers, int $timeoutSec, int $timeoutMicro): void
    {
        $immediate = false;
        foreach ([[ $readers, GAsync::STREAM_READ ], [ $writers, GAsync::STREAM_WRITE ]] as [$storage, $type]) {
            foreach ($storage->toArray() as $stream) {
                if (!is_resource($stream)) {
                    $watcher = $storage[$stream];
                    if ($watcher instanceof GStreamRead || $watcher instanceof GStreamWrite) {
                        $watcher->cancel(new \RuntimeException('Stream was closed outside GAsync'));
                    }
                    $this->remove($storage, $stream, $type);
                    $immediate = true;
                } elseif (!self::isPollable($stream)
                    || ($type === GAsync::STREAM_READ && (stream_get_meta_data($stream)['unread_bytes'] > 0 || ($storage[$stream] instanceof GStreamRead && feof($stream))))
                    || ($storage[$stream] instanceof GStreamWrite && $storage[$stream]->hasImmediateWork())) {
                    $type === GAsync::STREAM_READ ? GAsync::readEvent($stream) : GAsync::writeEvent($stream);
                    $immediate = true;
                }
            }
        }
        $timeout = $immediate ? 0.0 : $timeoutSec + $timeoutMicro / 1e6;
        foreach ($this->poll($timeout) as [$stream, $type]) {
            if (!is_resource($stream)) {
                continue;
            }
            $type === GAsync::STREAM_READ ? GAsync::readEvent($stream) : GAsync::writeEvent($stream);
        }
    }

    public function close(): void
    {
        foreach (array_keys($this->readStreams) as $id) {
            $this->unwatch($id, GAsync::STREAM_READ);
        }
        foreach (array_keys($this->writeStreams) as $id) {
            $this->unwatch($id, GAsync::STREAM_WRITE);
        }
        $this->readStreams = $this->writeStreams = [];
    }

    public function fork(): void
    {
        $this->close();
    }
}

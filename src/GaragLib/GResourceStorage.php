<?php

declare(strict_types=1);

namespace GaragLib;

/** Resource-keyed storage. It does not own or close the stored resources. */
class GResourceStorage implements \Countable, \Iterator, \ArrayAccess
{
    private array $resources = [];
    private array $resources_data = [];

    private static function id(mixed $resource): int
    {
        // Closed resources still have an identity, needed for unregistering them.
        if (!is_resource($resource) && gettype($resource) !== 'resource (closed)') {
            throw new \InvalidArgumentException('Expected a resource');
        }
        return get_resource_id($resource);
    }

    public function toArray(bool $data = false): array
    {
        return $data ? $this->resources_data : $this->resources;
    }

    public function attach($resource, mixed $assoc = null): void
    {
        $this->offsetSet($resource, $assoc);
    }

    public function detach($resource): void
    {
        $this->offsetUnset(self::id($resource));
    }

    public function contains($resource): bool
    {
        return isset($this->resources[self::id($resource)]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        $id = is_int($offset) ? $offset : self::id($offset);
        return $this->resources_data[$id] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (!is_resource($offset)) {
            throw new \InvalidArgumentException('Expected an open resource');
        }
        $id = self::id($offset);
        $this->resources[$id] = $offset;
        $this->resources_data[$id] = $value;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->resources[is_int($offset) ? $offset : self::id($offset)]);
    }

    public function offsetUnset(mixed $offset): void
    {
        $id = is_int($offset) ? $offset : self::id($offset);
        unset($this->resources[$id], $this->resources_data[$id]);
    }

    public function count(): int
    {
        return count($this->resources);
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->resources);
    }

    public function current(): mixed
    {
        return current($this->resources);
    }

    public function key(): mixed
    {
        return key($this->resources);
    }

    public function next(): void
    {
        next($this->resources);
    }

    public function rewind(): void
    {
        reset($this->resources);
    }

    public function valid(): bool
    {
        return key($this->resources) !== null;
    }

    public function setInfo(mixed $info): void
    {
        if ($this->valid()) {
            $this->resources_data[$this->key()] = $info;
        }
    }

    public function getInfo(): mixed
    {
        return $this->valid() ? $this->resources_data[$this->key()] : null;
    }

    public function removeAll(iterable $storage): int
    {
        $count = 0;
        foreach ($storage as $resource) {
            if ($this->contains($resource)) {
                $this->detach($resource);
                ++$count;
            }
        }
        return $count;
    }

    public function removeAllExcept(iterable $storage): int
    {
        $keep = [];
        foreach ($storage as $resource) {
            $keep[self::id($resource)] = true;
        }
        $count = 0;
        foreach ($this->resources as $id => $resource) {
            if (!isset($keep[$id])) {
                $this->detach($resource);
                ++$count;
            }
        }
        return $count;
    }

    public function __serialize(): array
    {
        throw new \LogicException('Open resources cannot be serialized');
    }

    public function __unserialize(array $data): void
    {
        throw new \LogicException('Resources cannot be unserialized');
    }

    public function serialize(): never
    {
        throw new \LogicException('Open resources cannot be serialized');
    }

    public function unserialize(string $data): never
    {
        throw new \LogicException('Resources cannot be unserialized');
    }
}

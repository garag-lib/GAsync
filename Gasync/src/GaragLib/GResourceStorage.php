<?php

namespace GaragLib;

class GResourceStorage implements \Countable, \Iterator, \Serializable, \ArrayAccess {

    private $resources = [];
    private $resources_data = [];

    public function toArray($data = false) {
        return $data ? $this->resources_data : $this->resources;
    }

    public function attach($resource, $assoc = null) {
        $id = get_resource_id($resource);
        $this->resources[$id] = $resource;
        $this->resources_data[$id] = $assoc;
    }

    public function detach($resource) {
        $id = get_resource_id($resource);
        unset($this->resources[$id]);
        unset($this->resources_data[$id]);
    }

    public function contains($resource) {
        $id = get_resource_id($resource);
        return isset($this->resources[$id]);
    }

    public function offsetGet($resource) {
        $id = get_resource_id($resource);
        return $this->resources_data[$id] ?? null;
    }

    public function count(): int {
        return count($this->resources);
    }

    public function getIterator(): \ArrayIterator {
        return new \ArrayIterator($this->resources);
    }

    public function current(): mixed {
        return current($this->resources);
    }

    public function setInfo(mixed $info): void {
        $key = $this->key();
        if ($key !== null) {
            $this->resources_data[$key] = $info;
        }
    }

    public function getInfo(): mixed {
        $key = $this->key();
        return $key !== null ? $this->resources_data[$key] : null;
    }

    public function key(): mixed {
        return key($this->resources);
    }

    public function next(): void {
        next($this->resources);
    }

    public function offsetSet($resource, $assoc = null): void {
        if (is_null($resource) || !is_resource($resource)) {
            throw new \InvalidArgumentException('not Resource');
        }
        $id = get_resource_id($resource);
        $this->resources[$id] = $resource;
        $this->resources_data[$id] = $assoc;
    }

    public function offsetExists(mixed $offset): bool {
        if (is_resource($offset)) {
            $id = get_resource_id($offset);
            return isset($this->resources[$id]);
        }
        return isset($this->resources[$offset]);
    }

    public function offsetUnset(mixed $offset): void {
        if (is_resource($offset)) {
            $id = get_resource_id($offset);
            unset($this->resources[$id]);
            unset($this->resources_data[$id]);
        } else {
            unset($this->resources[$offset]);
            unset($this->resources_data[$offset]);
        }
    }

    public function rewind(): void {
        reset($this->resources);
    }

    public function serialize(): ?string {
        return serialize([$this->resources, $this->resources_data]);
    }

    public function unserialize(string $data): void {
        [$this->resources, $this->resources_data] = unserialize($data, ['allowed_classes' => false]);
    }

    public function valid(): bool {
        return key($this->resources) !== null;
    }

    public function removeAll(\SplObjectStorage $storage): int {
        $removedCount = 0;
        foreach ($storage as $object) {
            if ($this->contains($object)) {
                $this->detach($object);
                $removedCount++;
            }
        }
        return $removedCount;
    }

    public function removeAllExcept(\SplObjectStorage $storage): int {
        $removedCount = 0;
        foreach ($this->resources as $resource) {
            if (!$storage->contains($resource)) {
                $this->detach($resource);
                $removedCount++;
            }
        }
        return $removedCount;
    }
}

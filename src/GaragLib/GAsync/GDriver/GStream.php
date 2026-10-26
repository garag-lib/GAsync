<?php

declare(strict_types=1);

namespace GaragLib\GAsync\GDriver;

use GaragLib\GAsync\GAsync;

class GStream extends GDriver
{
    public function getName(): string
    {
        return 'GStream';
    }

    protected function watch($stream, int $type): void
    {
    }

    protected function unwatch(int $id, int $type): void
    {
    }

    protected function poll(float $timeout): array
    {
        $read = array_values($this->readStreams);
        $write = array_values($this->writeStreams);
        if ($read === [] && $write === []) {
            if ($timeout > 0) {
                usleep((int) min(PHP_INT_MAX, ceil($timeout * 1e6)));
            }
            return [];
        }
        $except = [];
        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;
            return true;
        }, E_WARNING);
        try {
            $result = stream_select($read, $write, $except, (int) $timeout, (int) (($timeout - (int) $timeout) * 1e6));
        } finally {
            restore_error_handler();
        }
        if ($result === false) {
            if ($warning !== null && (str_contains($warning, 'Interrupted system call') || str_contains($warning, '[4]'))) {
                return [];
            }
            throw new \RuntimeException($warning ?? 'stream_select failed');
        }
        $events = [];
        foreach ($read as $stream) {
            $events[] = [$stream, GAsync::STREAM_READ];
        }
        foreach ($write as $stream) {
            $events[] = [$stream, GAsync::STREAM_WRITE];
        }
        return $events;
    }
}

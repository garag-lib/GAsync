<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GaragLib\GAsync\GAsync;

GAsync::init(getenv('GASYNC_DRIVER') ?: 'GStream');
$source = tmpfile();
$destination = tmpfile();
if ($source === false || $destination === false) {
    throw new RuntimeException('Cannot create temporary files');
}
$payload = random_bytes(1024 * 1024);
fwrite($source, $payload);
rewind($source);
try {
    GAsync::async(static function (): void {
        GAsync::msleep(10);
        echo "Timer completed\n";
    });
    GAsync::async(static function () use ($source, $destination): void {
        while (($data = GAsync::fread($source, 65536)) !== '') {
            GAsync::fwrite($destination, $data);
        }
    });
    GAsync::run();
    rewind($destination);
    if (hash('sha256', stream_get_contents($destination)) !== hash('sha256', $payload)) {
        throw new RuntimeException('Copy failed');
    }
    echo "Copied 1 MiB; SHA-256 verified\n";
} finally {
    GAsync::fclose($source);
    GAsync::fclose($destination);
}

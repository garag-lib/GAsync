<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GaragLib\GAsync\GAsync;

GAsync::init(getenv('GASYNC_DRIVER') ?: 'GStream');
$stream = tmpfile();
if ($stream === false) {
    throw new RuntimeException('Cannot create temporary file');
}
try {
    GAsync::fwrite($stream, 'Callbacks work', function (int $written) use ($stream): void {
        rewind($stream);
        GAsync::fread($stream, $written, static function (string $data): void {
            echo $data, "\n";
        });
    });
    GAsync::msleep(5, static function (float $elapsed): void {
        printf("Timer: %.3f s\n", $elapsed);
    });
    GAsync::run();
} finally {
    GAsync::fclose($stream);
}

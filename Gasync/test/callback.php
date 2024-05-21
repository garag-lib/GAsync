<?php

require_once(realpath(__DIR__ . '/../vendor/autoload.php'));

use GaragLib\GAsync\GAsync;

//GAsync::init();
GAsync::init('GEV');

const FILE_SIZE = 1024 * 1024 * 10;
const BUFFER_SIZE = 1024;
echo 'create file ' . ( FILE_SIZE / 1024 / 1024 ) . ' MB' . PHP_EOL;
$file = fopen('original.bin', 'wb');
for ($cont = 0, $num = FILE_SIZE / BUFFER_SIZE; $cont < $num; $cont++) {
    fwrite($file, $randomBytes = random_bytes(BUFFER_SIZE));
}
fclose($file);
echo 'file create' . PHP_EOL;

GAsync::async(function () {
    echo '1' . PHP_EOL;
    GAsync::async(function () {
        echo '2' . PHP_EOL;
        GAsync::async(function () {
            echo '3' . PHP_EOL;
        }, false);
    }, false);
}, false);

GAsync::async(function () {
    echo '4' . PHP_EOL;
    GAsync::sleep(2, function ($diff) {
        echo '*** ' . $diff . PHP_EOL;
        echo '4.2' . PHP_EOL;
    });
}, false);

GAsync::async(function () {
    echo '5' . PHP_EOL;
    GAsync::sleep(3, function ($diff) {
        echo '*** ' . $diff . PHP_EOL;
        echo '5.3' . PHP_EOL;
    });
}, false);

GAsync::async(function () {
    echo '6' . PHP_EOL;
    GAsync::fopen('./copy.bin', 'wb', false, null, function ($destino) {
        GAsync::fopen('original.bin', 'r', false, null, function ($origen) use ($destino) {
            $cont = 0;
            $bucle = function () use (&$bucle, $origen, $destino, & $cont) {
                if (!feof($origen)) {
                    $cont++;
                    GAsync::fread($origen, BUFFER_SIZE * BUFFER_SIZE, function ($data) use (&$bucle, $origen, $destino, & $cont) {
                        echo $cont . ' leido: ' . strlen($data) . PHP_EOL;
                        GAsync::fwrite($destino, $data, function ($escrito) use (&$bucle, $origen, $destino, & $cont) {
                            echo $cont . ' escrito: ' . $escrito . PHP_EOL;
                            $bucle();
                        });
                    });
                } else {
                    GAsync::fclose($origen);
                    GAsync::fclose($destino);
                }
            };
            $bucle();
        });
    });
}, false);

$cont = 0;
$timer = new \GaragLib\GAsync\GTimer(100000, true, function ($timer, $diff) use (&$cont) {
            $cont++;
            echo '(' . $cont . ') timer ' . $diff . PHP_EOL;
            if ($cont >= 10) {
                $timer->clear();
                unset($timer);
            }
        });

GAsync::run();

echo PHP_EOL;

echo 'memory_get_peak_usage: ' . ((memory_get_peak_usage() / 1024 ) / 1024) . PHP_EOL;

echo 'memory_get_peak_usage total: ' . ((memory_get_peak_usage(true) / 1024 ) / 1024) . PHP_EOL;

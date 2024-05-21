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
    fwrite($file, random_bytes(BUFFER_SIZE));
    //fwrite($file, bin2hex(random_bytes(BUFFER_SIZE)));
}
fclose($file);
echo 'file create' . PHP_EOL;

/*
$timer = new \GaragLib\GAsync\GTimer(1000, true, function ($timer, $diff) {
            static $cont = 0;
            $cont++;
            echo '(' . $cont . ') timer ' . $diff . PHP_EOL;
            if ($cont >= 100) {
                $timer->clear();
            }
        });
*/

GAsync::async(function () {
    echo '1' . PHP_EOL;
    GAsync::async(function () {
        echo '2' . PHP_EOL;
        GAsync::async(function () {
            echo '3' . PHP_EOL;
        });
    });
});

GAsync::async(function () {
    echo '4' . PHP_EOL;
    echo '*** ' . GAsync::sleep(2) . PHP_EOL;
    echo '4.2' . PHP_EOL;
});

GAsync::async(function () {
    echo '5' . PHP_EOL;
    echo '*** ' . GAsync::sleep(3) . PHP_EOL;
    echo '5.3' . PHP_EOL;
});

GAsync::async(function () {
    echo '6' . PHP_EOL;
    //$origen = GAsync::fopen('./original.bin', 'rb');
    $origen = GAsync::fopen('https://ash-speed.hetzner.com/100MB.bin', 'r');
    echo '$origen ok' . PHP_EOL;
    $destino = GAsync::fopen('./copy.bin', 'wb');
    echo '$destino ok' . PHP_EOL;
    exit(0);
    $cont = 0;
    while (!feof($origen)) {
        $cont++;
        $data = GAsync::fread($origen, BUFFER_SIZE * BUFFER_SIZE);
        echo $cont . ' leido: ' . strlen($data) . PHP_EOL;
        $escritos = GAsync::fwrite($destino, $data);
        echo $cont . ' escrito: ' . $escritos . PHP_EOL;
        //GAsync::msleep(1);
    }
    GAsync::fclose($origen);
    GAsync::fclose($destino);
});

GAsync::run();

echo PHP_EOL;

echo 'memory_get_peak_usage: ' . ((memory_get_peak_usage() / 1024 ) / 1024) . PHP_EOL;

echo 'memory_get_peak_usage total: ' . ((memory_get_peak_usage(true) / 1024 ) / 1024) . PHP_EOL;

function getMemoryUsageRSS() {
    $pid = getmypid();
    $output = shell_exec("ps -o rss= -p $pid");
    return (int) trim($output) * 1024; // Convertimos KB a bytes
}

echo 'RSS memory usage: ' . number_format(getMemoryUsageRSS() / 1024 / 1024, 2) . ' MB' . PHP_EOL;

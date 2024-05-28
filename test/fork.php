<?php

require_once(realpath(__DIR__ . '/../vendor/autoload.php'));

use GaragLib\GAsync\GAsync;

GAsync::init();

//GAsync::init('GEV');
//GAsync::init('GEVENT');

class Work1 extends \GaragLib\GForkManager\GWorker {

    public function run() {
        echo 'Work1...' . PHP_EOL;
        sleep(2);
        echo 'Work1...end' . PHP_EOL;
    }
}

class Work2 extends \GaragLib\GForkManager\GWorker {

    public function run() {
        echo 'Work2...' . PHP_EOL;
        sleep(4);
        echo 'Work2...end' . PHP_EOL;
    }
}

class Work3 extends \GaragLib\GForkManager\GWorker {

    public function run() {
        echo 'Work3...' . PHP_EOL;
        sleep(16);
        echo 'Work3...end' . PHP_EOL;
    }
}

$fork = new \GaragLib\GForkManager\GForkManager(2);
$fork->add(new Work1());
$fork->add(new Work2());
$fork->add(new Work3());
echo posix_getpid() . ' START' . PHP_EOL;
$fork->run();
$fork->wait(1, 0);
if ($fork->isRoot()) {
    echo posix_getpid() . ' FIN' . PHP_EOL;
}

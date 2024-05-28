<?php

require_once(realpath(__DIR__ . '/../vendor/autoload.php'));

//GaragLib\GAsync\GAsync::init();
GaragLib\GAsync\GAsync::init('GEV');
//GaragLib\GAsync\GAsync::init('GEVENT');

class Work1 extends \GaragLib\GForkManager\GWorker {

    public function run() {
        sleep(1);
    }
}

class Work2 extends \GaragLib\GForkManager\GWorker {

    public function run() {
        sleep(2);
    }
}

class Work3 extends \GaragLib\GForkManager\GWorker {

    public function run() {
        sleep(3);
    }
}

$fork = new \GaragLib\GForkManager\GForkManager(2);
$fork->add('\\Work1');
$fork->add('\\Work2');
$fork->add('\\Work3');
echo posix_getpid() . ' START ROOT' . PHP_EOL;
$fork->run();
$fork->wait(1, 0);
if ($fork->isRoot()) {
    echo posix_getpid() . ' FIN ROOT' . PHP_EOL;
}

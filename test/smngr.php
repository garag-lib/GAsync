<?php

require_once(realpath(__DIR__ . '/../vendor/autoload.php'));

use GaragLib\GAsync\GAsync;

//GAsync::init();
//GAsync::init('GEV');
GAsync::init('GEVENT');

$smngr = new \GaragLib\GSocketManager\GSocketManager();

$port = 0;

$serversocket = $smngr->createServerSocket('0.0.0.0', $port);

echo 'SERVER: 0.0.0.0:' . $port . PHP_EOL;

$clients = [];

$smngr->setOnAcceptCallback(function ($newsocket) use ($smngr, $serversocket, & $clients) {
    static $numclientes = 0;
    $numclientes++;
    echo 'server Accept client ' . $numclientes . PHP_EOL;
});

$smngr->setOnConnectCallback(function ($sock) use ($smngr, $serversocket, & $clients) {
    static $numclientes = 0;
    if (in_array($sock, $clients)) {
        $numclientes++;
        echo 'client connet ' . $numclientes . PHP_EOL;
    } else {
        echo 'server client connet' . PHP_EOL;
    }
});

$smngr->setOnDisconnectCallback(function ($sock) use ($smngr, $serversocket, & $clients) {
    if (in_array($sock, $clients)) {
        echo 'client diconnet' . PHP_EOL;
    } else {
        echo 'server client diconnet' . PHP_EOL;
    }
});

$time = time();
$smngr->setOnIdleCallback(function () use ($smngr, $serversocket, & $clients, $time) {
    echo 'idle ' . time() . ' ' . memory_get_usage() / 1024 . PHP_EOL;
    if (( time() - $time ) > 5 && count($clients)) {
        foreach ($clients as $index => $clientsocket) {
            $smngr->send($clientsocket, 'HI FROM CLIENT ' . $index);
            $smngr->close($clientsocket);
        }
        $clients = [];
        $smngr->end();
    }
    /* if ($cont % 10 == 0) {
      foreach ($smngr->getSockets() as $sock) {
      if ($sock != $serversocket && !in_array($sock, $clients, true)) {
      $smngr->send($sock, 'HI FROM SERVER ' . $cont);
      }
      }
      } */
});

$smngr->setOnReceiveCallback(function ($sock) use ($smngr, $serversocket, & $clients) {
    echo 'setOnReceiveCallback' . PHP_EOL;
    if (in_array($sock, $clients)) {
        echo 'client receive: ' . $smngr->read($sock) . PHP_EOL;
    } else {
        echo 'server client receive: ' . $smngr->read($sock) . PHP_EOL;
    }
});

//$clients[] = $smngr->createClientSocket('0.0.0.0', $port);

for ($cont = 0; $cont < 250; $cont++) {
    $clients[] = $smngr->createClientSocket('0.0.0.0', $port);
}

GAsync::run(1, 0);

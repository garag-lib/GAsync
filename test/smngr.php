<?php

require_once(realpath(__DIR__ . '/../vendor/autoload.php'));

use GaragLib\GAsync\GAsync;

GAsync::init();
//GAsync::init('GEV');
//GAsync::init('GEVENT');

$smngr = new \GaragLib\GSocketManager\GSocketManager();

$port = 0;

$portTCP = 2222;
$smngr->createServerSocket('0.0.0.0', $portTCP, 'tcp');

$portUDP = 1111;
$smngr->createServerSocket('0.0.0.0', $portUDP, 'udp');

$cliente = $smngr->createClientSocket('127.0.0.1', 1111, 'udp');

$clients = [];

$smngr->setOnAcceptCallback(function ($newsocket) use ($smngr, & $clients, $cliente) {
    $clients[] = $newsocket;
    static $numclientes = 0;
    $numclientes++;
    echo 'server Accept client ' . $numclientes . PHP_EOL;
});

$smngr->setOnConnectCallback(function ($sock) use ($smngr, & $clients) {
    if (in_array($sock, $clients)) {
        echo 'server: client connet' . PHP_EOL;
    } else {
        echo 'cliente connet' . PHP_EOL;
    }
});

$smngr->setOnDisconnectCallback(function ($sock) use ($smngr, & $clients) {
    if (in_array($sock, $clients)) {
        echo 'server: client diconnet' . PHP_EOL;
    } else {
        echo 'client diconnet' . PHP_EOL;
    }
});

$smngr->setOnReceiveCallback(function ($sock, $data = null, $peer = null) use ($smngr, & $clients, $cliente) {
    if ($peer) {
        $data = trim($data);
        echo 'udp receive: ' . $data . ' from ' . $peer . PHP_EOL;
        if ($data[0] == '<') {
            $smngr->send($cliente, chr(27) . chr(17));
        }
    } else {
        if (in_array($sock, $clients)) {
            echo 'server: receive: ' . trim($smngr->read($sock)) . PHP_EOL;
        } else {
            echo 'client receive: ' . trim($smngr->read($sock)) . PHP_EOL;
        }
    }
});

GAsync::async(function () use ($smngr, $cliente) {
    GAsync::msleep(1000);
    $smngr->send($cliente, chr(27) . chr(7));
    GAsync::msleep(1000);
    $smngr->send($cliente, chr(27) . chr(5));
});

GAsync::run(1, 0);

/*
  $smngr->setOnIdleCallback(function () use ($smngr, & $clients) {
  echo 'idle ' . time() . ' ' . memory_get_usage() / 1024 . PHP_EOL;
  if (( time() - $time ) > 5 && count($clients)) {
  foreach ($clients as $index => $clientsocket) {
  $smngr->send($clientsocket, 'HI FROM CLIENT ' . $index);
  $smngr->close($clientsocket);
  }
  $clients = [];
  //$smngr->end();
  }
  if (( time() - $time ) > 5 && count($clients)) {
  foreach ($smngr->getSockets() as $sock) {
  if ($sock != $serversocket && !in_array($sock, $clients, true)) {
  $smngr->send($sock, 'HI FROM SERVER ');
  }
  }
  $smngr->end();
  }
  });
 */

//$clients[] = $smngr->createClientSocket('0.0.0.0', $port);
//for ($cont = 0; $cont < 5; $cont++) {
//    $clients[] = $smngr->createClientSocket('0.0.0.0', $port);
//}
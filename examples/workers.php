<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use GaragLib\GAsync\GAsync;
use GaragLib\GForkManager\GForkManager;
use GaragLib\GForkManager\GWorker;

class ExampleWorker extends GWorker
{
    public function __construct(private string $message)
    {
    }
    public function run()
    {
        $this->smngr->send($this->root_sock, $this->message);
    }
}

GAsync::init(getenv('GASYNC_DRIVER') ?: 'GStream');
$manager = new GForkManager(2);
foreach (['one', 'two', 'three'] as $message) {
    $manager->add(ExampleWorker::class, $message);
}
$manager->setCallback('receive', static function ($pid, $socket, $data): void {
    echo $pid, ': ', $data, "\n";
});
$manager->setCallback('error', static function ($pid, Throwable $error): void {
    fwrite(STDERR, "$pid: {$error->getMessage()}\n");
});
$manager->wait();

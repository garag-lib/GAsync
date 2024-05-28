<?php

namespace GaragLib\GAsync;

class GAsync {

    public const STREAM_READ = 1;
    public const STREAM_WRITE = 2;

    private static $cmd_stream_A;
    private static $cmd_stream_B;

    /**
     * @var \GaragLib\GResourceStorage
     */
    private static $readers;

    /**
     * @var \GaragLib\GResourceStorage
     */
    private static $writers;

    /**
     * @var \SplObjectStorage
     */
    private static $fibers;

    /**
     * @var \WeakMap
     */
    private static $idles;

    /**
     * @var GDriver\GDriver
     */
    private static $driver;

    /**
     * @var bool
     */
    private static $stop = false;

    public static function init($driver = null): void {
        static::$readers = new \GaragLib\GResourceStorage();
        static::$writers = new \GaragLib\GResourceStorage();
        static::$fibers = new \SplObjectStorage();
        static::$idles = new \WeakMap();
        static::$driver = GDriver\GDriver::getDriver($driver);
        list(static::$cmd_stream_A, static::$cmd_stream_B) = static::createSocketPair();
        static::addStream(static::$cmd_stream_A, static::STREAM_READ, new GStreamCommand(static::$cmd_stream_A));
    }

    public static function fork(): void {
        //---
        static::$readers = new \GaragLib\GResourceStorage();
        static::$writers = new \GaragLib\GResourceStorage();
        static::$fibers = new \SplObjectStorage();
        static::$idles = new \WeakMap();
        //---
        static::$driver->fork();
        //---
        list(static::$cmd_stream_A, static::$cmd_stream_B) = static::createSocketPair();
        static::addStream(static::$cmd_stream_A, static::STREAM_READ, new GStreamCommand(static::$cmd_stream_A));
        //---
    }

    public static function getDriver() {
        return static::$driver;
    }

    private static function prepareSocket($socket, int $buffersize): void {
        stream_set_blocking($socket, 0);
        stream_set_read_buffer($socket, $buffersize);
        stream_set_write_buffer($socket, $buffersize);
    }

    private static function createSocketPair(int $buffersize = 128) {
        $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (!$sockets) {
            throw new \Exception('stream_socket_pair error');
        }
        static::prepareSocket($sockets[0], $buffersize);
        static::prepareSocket($sockets[1], $buffersize);
        return $sockets;
    }

    public static function NOP(): void {
        static::sendCMD('NOP');
    }

    public static function sendCMD($cmd): void {
        if (fwrite(static::$cmd_stream_B, $cmd . "\n") === false) {
            throw new \Exception('sendCMD ' . $cmd . ' error');
        }
    }

    //--------------------------------------------------------------------------

    public static function addStream($stream, int $type, $assoc) {
        if ($type == GAsync::STREAM_READ || $type === null) {
            static::$driver->addReadStream(static::$readers, $stream, $assoc);
        }
        if ($type == GAsync::STREAM_WRITE || $type === null) {
            static::$driver->addWriteStream(static::$writers, $stream, $assoc);
        }
    }

    public static function removeStream($stream, int $type = null) {
        if ($type == GAsync::STREAM_READ || $type === null) {
            static::$driver->removeReadStream(static::$readers, $stream);
        }
        if ($type == GAsync::STREAM_WRITE || $type === null) {
            static::$driver->removeWriteStream(static::$writers, $stream);
        }
    }

    public static function dispatch($timeoutSec, $timeoutMicro) {
        static::$driver->dispatch(static::$readers, static::$writers, $timeoutSec, $timeoutMicro);
    }

    public static function readEvent($stream) {
        //---
        if (static::$readers->contains($stream)) {
            $gstream = static::$readers->offsetGet($stream);
            if ($gstream) {
                if ($gstream->dispatch($stream, static::STREAM_READ)) {
                    static::removeStream($stream, static::STREAM_READ);
                }
                return;
            }
        }
        //---
    }

    public static function writeEvent($stream) {
        //---
        if (static::$writers->contains($stream)) {
            $gstream = static::$writers->offsetGet($stream);
            if ($gstream) {
                if ($gstream->dispatch($stream, static::STREAM_WRITE)) {
                    static::removeStream($stream, static::STREAM_WRITE);
                }
            }
        }
        //---
    }

    //--------------------------------------------------------------------------

    public static function runOnce($sec = 0, $usec = 100000) {
        static::dispatch($sec, $usec);
        static::handleFibers();
        static::handleIdles();
    }

    public static function run($sec = 0, $usec = 100000) {
        while (!static::$stop) {
            static::runOnce($sec, $usec);
            if (!static::$fibers->count() && !count(static::$idles)) {
                static::$stop = true;
                static::NOP();
            }
        }
    }

    public static function exit() {
        static::$stop = true;
    }

    public static function stop() {
        static::sendCMD('STOP');
    }

    private static function handleFibers() {
        //---
        $resume = [];
        //---
        static::$fibers->rewind();
        while (static::$fibers->valid()) {
            $fiber = static::$fibers->current();
            $queue = static::$fibers->getInfo();
            if ($queue->count()) {
                if ($queue->bottom() === false) {
                    $queue->dequeue();
                } elseif ($queue->bottom() === null) {
                    $queue->dequeue();
                    $resume[] = [[$fiber, 'resume']];
                } elseif ($queue->bottom()->hasResult()) {
                    $gstream = $queue->dequeue();
                    $resume[] = [[$fiber, 'resume'], $gstream->getResult()];
                }
            }
            static::$fibers->next();
        }
        //---
        foreach ($resume as $arguments) {
            call_user_func(...$arguments);
        }
        //---
    }

    private static function addFiber($currentFiber, $assoc) {
        $queue = null;
        if (static::$fibers->contains($currentFiber)) {
            $queue = static::$fibers->offsetGet($currentFiber);
        } else {
            $queue = new \SplQueue();
            static::$fibers->attach($currentFiber, $queue);
        }
        $queue->enqueue($assoc);
    }

    private static function removeFiber($currentFiber) {
        static::$fibers->detach($currentFiber);
    }

    private static function handleIdles() {
        foreach (static::$idles as $gstream => $callback) {
            if ($callback) {
                call_user_func($callback, $gstream);
            } else {
                $gstream->idle();
            }
        }
    }

    //--------------------------------------------------------------------------

    public static function async($callback, $bfiber = true) {
        //---
        $currentFiber = null;
        if ($bfiber) {
            $currentFiber = new \Fiber(function () use ($callback) {
                        \Fiber::suspend();
                        call_user_func($callback);
                        static::removeFiber(\Fiber::getCurrent());
                    });
            $currentFiber->start();
        } else {
            $currentFiber = new GFiber();
            $fibercall = function () use ($callback, $currentFiber) {
                call_user_func($callback);
                static::removeFiber($currentFiber);
            };
            $currentFiber->setCallback($fibercall);
        }
        //---
        static::addFiber($currentFiber, null);
        static::NOP();
        //---
    }

    //--------------------------------------------------------------------------

    public static function usleep(int $uxec, $callback = null) {
        //---
        $currentFiber = null;
        if (!$callback) {
            $currentFiber = \Fiber::getCurrent();
            if (!$currentFiber)
                throw new \Exception('Not in Fiber');
        } else {
            $currentFiber = new GFiber();
            $fibercall = function (...$args) use ($callback, $currentFiber) {
                call_user_func($callback, ...$args);
                static::removeFiber($currentFiber);
            };
            $currentFiber->setCallback($fibercall);
        }
        //---
        static::addFiber($currentFiber, new GTimer($uxec, false));
        //---
        if (!$callback)
            return \Fiber::suspend();
        //---
    }

    public static function msleep(int $msec, $callback = null) {
        return static::usleep($msec * 1000, $callback);
    }

    public static function sleep(int $sec, $callback = null) {
        return static::usleep($sec * 1000000, $callback);
    }

    //--------------------------------------------------------------------------

    public static function fopen($filename, $mode, $use_include_path = false, $context = null, $callback = null) {
        //---
        $currentFiber = null;
        if (!$callback) {
            $currentFiber = \Fiber::getCurrent();
            if (!$currentFiber)
                throw new \Exception('Not in Fiber');
        } else {
            $currentFiber = new GFiber();
            $fibercall = function (...$args) use ($callback, $currentFiber) {
                call_user_func($callback, ...$args);
                static::removeFiber($currentFiber);
            };
            $currentFiber->setCallback($fibercall);
        }
        //---
        $scheme = parse_url($filename, \PHP_URL_SCHEME);
        if (!$context)
            $context = stream_context_create();
        //stream_context_set_option($context, $scheme, 'blocking', false);
        $stream = fopen($filename, $mode, $use_include_path, $context);
        if ($stream === false)
            throw new \Exception('fopen error');
        stream_set_blocking($stream, false);
        //stream_set_chunk_size($stream, 1024*1024);
        //---
        $readMode = strpos($mode, 'r') !== false;
        $writeMode = strpos($mode, 'w') !== false || strpos($mode, 'a') !== false;
        $gsmulti = new GStreamMulti($stream);
        if ($readMode) {
            $gstreamread = new GStreamRead($stream);
            static::addStream($stream, static::STREAM_READ, $gstreamread);
            $gsmulti->addGStream($gstreamread);
        }
        if ($writeMode) {
            $gstreamwrite = new GStreamWrite($stream);
            static::addStream($stream, static::STREAM_WRITE, $gstreamwrite);
            $gsmulti->addGStream($gstreamwrite);
        }
        static::addFiber($currentFiber, $gsmulti);
        //---
        //static::dispatch(0, 1);
        //---
        if (!$callback)
            return \Fiber::suspend();
        //---
    }

    public static function fread($stream, $length, $callback = null) {
        //---
        $currentFiber = null;
        if (!$callback) {
            $currentFiber = \Fiber::getCurrent();
            if (!$currentFiber)
                throw new \Exception('Not in Fiber');
        } else {
            $currentFiber = new GFiber();
            $fibercall = function (...$args) use ($callback, $currentFiber) {
                call_user_func($callback, ...$args);
                static::removeFiber($currentFiber);
            };
            $currentFiber->setCallback($fibercall);
        }
        //---
        $gstreamread = static::$readers->offsetGet($stream);
        $gstreamread->setLength($length);
        static::addFiber($currentFiber, $gstreamread);
        //---
        if (!$callback)
            return \Fiber::suspend();
        //---
    }

    public static function fwrite($stream, $data, $callback = null) {
        //---
        $currentFiber = null;
        if (!$callback) {
            $currentFiber = \Fiber::getCurrent();
            if (!$currentFiber)
                throw new \Exception('Not in Fiber');
        } else {
            $currentFiber = new GFiber();
            $fibercall = function (...$args) use ($callback, $currentFiber) {
                call_user_func($callback, ...$args);
                static::removeFiber($currentFiber);
            };
            $currentFiber->setCallback($fibercall);
        }
        //---
        $gstreamwrite = null;
        if (static::$writers->contains($stream)) {
            $gstreamwrite = static::$writers->offsetGet($stream);
            $gstreamwrite->setData($data);
        } else {
            $gstreamwrite = new GStreamWrite($stream, true);
            static::addStream($stream, static::STREAM_WRITE, $gstreamwrite);
        }
        $gstreamwrite->setData($data);
        static::addFiber($currentFiber, $gstreamwrite);
        //---
        if (!$callback)
            return \Fiber::suspend();
        //---
    }

    public static function fclose($stream) {
        static::removeStream($stream);
        fclose($stream);
    }

    //--------------------------------------------------------------------------

    public static function removeIdle(GStream $objStream) {
        unset(static::$idles[$objStream]);
    }

    public static function addIdle(GStream $objStream, $callback = null) {
        static::$idles[$objStream] = $callback;
    }

    /*

      public static function addStream($stream, GStream $objStream, int $type, bool $bfiber = false, $callback = null) {
      //---
      $currentFiber = null;
      if ($bfiber) {
      if (!$callback) {
      $currentFiber = \Fiber::getCurrent();
      if (!$currentFiber)
      throw new \Exception('Not in Fiber');
      } else {
      $currentFiber = new GFiber();
      $fibercall = function (...$args) use ($callback, $currentFiber) {
      call_user_func($callback, ...$args);
      static::removeFiber($currentFiber);
      };
      $currentFiber->setCallback($fibercall);
      }
      }
      //---
      static::addStream($stream, $type);
      //---
      if ($bfiber) {
      static::addFiber($currentFiber, $objStream);
      if (!$callback)
      return \Fiber::suspend();
      }
      //---
      }

      public function cleanStreamFromFibers($stream) {
      $obwrite = static::$writers->offsetGet($stream);
      $objread = static::$readers->offsetGet($stream);
      if ($obwrite || $objread) {
      static::$fibers->rewind();
      while (static::$fibers->valid()) {
      $fiber = static::$fibers->current();
      $queue = static::$fibers->getInfo();
      if ($queue->count()) {
      $size = $queue->count();
      for ($i = 0; $i < $size; $i++) {
      if ($obwrite && $item === $obwrite) {
      $queue->offsetSet(false);
      continue;
      }
      if ($objread && $item === $objread) {
      $queue->offsetSet(false);
      continue;
      }
      }
      }
      static::$fibers->next();
      }
      }
      }

     */

    //--------------------------------------------------------------------------
}

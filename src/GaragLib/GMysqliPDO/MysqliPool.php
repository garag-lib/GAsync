<?php

class MiSemafoto {

    public static $key = [];
    public static $estado = [];
    public static $sem = [];

    public static function procesoterminado() {
        static::liberar();
    }

    public static function capturar($clave, $num = 1, $nowait = false) {
        if (count(static::$key) <= 0 && function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, "MiSemafoto::procesoterminado");
            pcntl_signal(SIGHUP, "MiSemafoto::procesoterminado");
        }
        static::$key[$clave] = crc32($clave); /* ftok($path, $nms); */
        if (static::$key[$clave]) {
            static::$sem[$clave] = sem_get(static::$key[$clave], $num);
            if (!static::$sem[$clave]) {
                static::$estado[$clave] = false;
                return static::$estado[$clave];
            }
            static::$estado[$clave] = sem_acquire(static::$sem[$clave], $nowait);
            return static::$estado[$clave];
        }
    }

    public static function liberar($clave = null) {
        if ($clave == null) {
            foreach (static::$sem as $clave => $sem) {
                if (static::$estado[$clave] && static::$sem[$clave])
                    static::$estado[$clave] = !sem_release(static::$sem[$clave]);
            }
        } else {
            if (static::$estado[$clave] && static::$sem[$clave])
                static::$estado[$clave] = !sem_release(static::$sem[$clave]);
            return static::$estado[$clave];
        }
    }

}

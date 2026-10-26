# GAsync

**Proyecto de experimentación con asincronía en PHP**, orientado a entender cómo se construyen un bucle de eventos y sus abstracciones. Permite comparar fibras y callbacks, observar cómo se intercalan tareas de I/O y explorar el uso de procesos cuando hace falta paralelismo.

La versión actual está orientada a **PHP 8.3, 8.4 y 8.5 en Linux CLI**. Su propósito es educativo y experimental: las pruebas cubren los escenarios descritos en la [auditoría](docs/audit.md), pero el proyecto no se presenta como un framework de producción.

## Qué incluye

| Módulo | Funcionalidad | Qué permite experimentar |
| --- | --- | --- |
| `GaragLib\GAsync` | Bucle de eventos, tareas con fibras o callbacks, temporizadores y lectura/escritura de streams | Concurrencia cooperativa, suspensión y reanudación, colas de operaciones y gestión de errores |
| Drivers `GStream`, `GEV`, `GEVENT` | Espera de disponibilidad mediante `stream_select()`, libev o libevent | Ejecutar la misma API sobre distintos mecanismos de espera |
| `GResourceStorage` | Almacén de recursos con datos asociados, iteración y operaciones de conjuntos | Identidad y seguimiento de recursos abiertos |
| `GSocketManager` | Servidores y clientes TCP/UDP, buffers, eventos y cierres | Conexiones no bloqueantes, escrituras parciales y fronteras de datagramas |
| `GForkManager`, `GWorker`, `GFactoryWorker` | Cola de trabajadores, límite de hijos, comunicación y recogida de procesos | Paralelismo Unix y coordinación entre padre e hijos |
| `GaragLib\MysqliPDO\MysqliPDO` | Fachada de PDO real y una conexión MySQLi independiente para consultas async | Comparar consultas síncronas con polling asíncrono y observar el aislamiento entre sesiones |

En el modelo cooperativo, una tarea deja avanzar a las demás al suspenderse mediante las operaciones de la biblioteca. Una llamada bloqueante nativa puede detener todo ese proceso. Los trabajadores de `GForkManager` se ejecutan en procesos separados y sí permiten trabajo en paralelo.

## Instalación y pruebas

```sh
composer install
composer test
composer lint
php examples/fibers.php
php examples/callbacks.php
php examples/sockets.php
php examples/workers.php
```

Las pruebas de desarrollo requieren las extensiones que necesita PHPUnit 12, incluidas DOM, XML y mbstring. El núcleo no exige extensiones PECL. `composer.json` limita la resolución de dependencias a PHP 8.3 para conservar la versión mínima.

| Función | Requisito adicional |
| --- | --- |
| Driver `GStream`, predeterminado | Streams y `stream_select()` de PHP |
| Driver `GEV` | PECL `ev` |
| Driver `GEVENT` | PECL `event` |
| Procesos | `pcntl` y `posix` |
| Consultas normales | `PDO` y `pdo_mysql` |
| Consultas async MySQL | `mysqli` con `mysqlnd` |
| Límite de conexiones SQL | `sysvsem` |
| Diagnóstico de conexión TCP con `SO_ERROR` | `sockets` (opcional; hay comprobación mediante streams si falta) |

Elegir otro driver no cambia la API:

```sh
GASYNC_DRIVER=GEV php examples/fibers.php
GASYNC_DRIVER=GEVENT composer test
```

Las pruebas SQL se habilitan con `GASYNC_MYSQL_PORT`, contra una base de datos de pruebas llamada `gasync`. Usan `GASYNC_MYSQL_USER` (predeterminado `root`) y `GASYNC_MYSQL_PASSWORD` (predeterminado vacío). No apuntarlas a una base de datos de producción. CI crea un servicio MySQL 8.4 desechable y prueba las nueve combinaciones de PHP/driver, además del núcleo sin extensiones opcionales.

## Fibras y callbacks

```php
<?php
require __DIR__ . '/vendor/autoload.php';

use GaragLib\GAsync\GAsync;

GAsync::init();
GAsync::async(function () {
    $stream = GAsync::fopen('php://temp', 'w+b');
    try {
        $bytes = GAsync::fwrite($stream, 'hello');
        rewind($stream);
        echo GAsync::fread($stream, $bytes);
        GAsync::msleep(10);
    } finally {
        GAsync::fclose($stream);
    }
});
GAsync::run();
```

Sin una fibra creada por `GAsync::async()`, las operaciones requieren un callback:

```php
GAsync::msleep(10, function (float $elapsed) {
    printf("Han pasado %.3f segundos\n", $elapsed);
});
GAsync::run();
```

`async($callback, false)` agenda un callback sin fibra. Ese callback puede encadenar otras operaciones con callbacks; para suspenderse debe ejecutarse dentro de una fibra de GAsync. `sleep()` recibe segundos, `msleep()` milisegundos y `usleep()` microsegundos; su resultado o callback recibe el tiempo transcurrido en segundos.

Cada lectura o escritura tiene su propia solicitud. Las solicitudes del mismo stream y dirección se procesan por orden; lectura y escritura son independientes. `fread()` devuelve hasta el tamaño solicitado, limitado a 64 KiB por resultado, y `''` al llegar a EOF. `fwrite()` completa todo el buffer y devuelve el total de bytes, incluyendo las escrituras parciales. Una cadena vacía devuelve `0`.

Los errores se lanzan dentro de la fibra que espera. Para operaciones con callback, se propagan desde `run()`/`runOnce()` y no se llama al callback de éxito. Una excepción interrumpe esa ejecución del bucle; el resto del trabajo conserva su estado para la siguiente llamada. El cierre o la desinscripción de un stream cancela sus solicitudes pendientes con `RuntimeException`.

## Ciclo de vida y temporizadores

- `run()` termina al agotarse tareas, watchers de usuario, temporizadores activos e idles; el canal interno de control no mantiene vivo el bucle.
- `stop()`/`exit()` interrumpen la ejecución actual. Se puede volver a llamar a `run()` para continuar.
- `runOnce()` ejecuta un turno. Las llamadas anidadas a `run()` o `runOnce()` se rechazan.
- `init()` libera los watchers, timers y sockets internos anteriores, sin cerrar streams propiedad del usuario. Se rechaza si quedan tareas o solicitudes pendientes: cerrarlas y procesar sus cancelaciones antes de reinicializar.
- Los watchers manuales se añaden con `addStream($resource, $direction, $watcher)`; un `dispatch()` que devuelve `true` desinscribe esa dirección.
- `addIdle()` mantiene una referencia fuerte al objeto hasta `removeIdle()`. Un idle activo mantiene vivo el bucle.

```php
use GaragLib\GAsync\GTimer;

$count = 0;
$timer = new GTimer(100_000, true, function (GTimer $timer, float $elapsed) use (&$count) {
    if (++$count === 10) {
        $timer->clear();
    }
});
GAsync::run();
```

La duración del constructor es en **microsegundos**; `getDuration()` y el tiempo transcurrido entregado al callback están en **segundos**. Se usa `hrtime()`, independiente de cambios del reloj civil. `stop()` pausa; `start()` comienza un intervalo nuevo; `clear()` además libera el callback y el resultado. Los timers activos conservan una referencia fuerte. Los intervalos repetidos deben ser positivos; un timer de una sola ejecución puede tener duración cero. Si se retrasa un intervalo, no se ejecutan ráfagas para recuperar los vencimientos perdidos.

## Almacenamiento de recursos

`GaragLib\GResourceStorage` asocia cada recurso PHP con un valor usando su identidad, permite comprobar pertenencia, recorrerlo y acceder mediante `ArrayAccess`. `attach()`/`detach()` añaden o eliminan referencias; `toArray()` devuelve los recursos y `toArray(true)` sus datos asociados. `removeAll()` y `removeAllExcept()` operan con iterables de recursos.

El almacén **no es propietario de los recursos**: eliminarlos del almacén no los cierra. Quien los abre debe cerrarlos. La serialización se rechaza porque un recurso abierto no puede reconstruirse a partir de sus datos serializados.

## Sockets y trabajadores

`examples/sockets.php` utiliza TCP sobre loopback y un puerto asignado por el sistema. `createServerSocket()` actualiza el argumento `$port` tras enlazar el socket. Los clientes tienen un timeout positivo en segundos y anuncian `connect` después de comprobar la conexión.

`send()` conserva el orden de los mensajes. En TCP son bytes, sin fronteras de mensaje. En UDP cada llamada es un datagrama, incluso si está vacío; se puede indicar el destino con `send($socket, $data, $peer)`. El buffer de recepción configurable debe dimensionarse para el datagrama esperado; un datagrama que lo exceda puede truncarse.

Los callbacks de TCP reciben el socket; `read($socket)` extrae su buffer acumulado. Los de recepción UDP reciben `($socket, $data, $peer)`. `setOnErrorCallback()` recibe `($socket, Throwable $error)`; sin manejador, el error se propaga desde el bucle. `close()` espera a vaciar los envíos; ante errores de transporte se descartan los envíos pendientes para poder cerrar. `end()` cierra todos los sockets y finaliza el manager. El callback de desconexión puede usar el recurso hasta terminar; después se cierra exactamente una vez. `delSocket()` fuerza la eliminación y cierre sin callback de desconexión. Crear un nuevo manager después de `end()`.

`GForkManager` limita los hijos simultáneos y recoge su estado con `waitpid()`. `GWorker` expone `$this->smngr` y `$this->root_sock` como propiedades protegidas. Los callbacks del padre reciben el PID primero:

- `connect`/`accept`: `($pid, $socket)`.
- `receive`: `($pid, $socket, $data)`.
- `disconnect`: `($pid, $socket, $remainingPidMap)`.
- `idle`: `($pid)`, con PID `null`.
- `error`: `($pid, Throwable $error)`.
- `exit`: `($pid, ?int $exitCode, ?int $signal)`.

Un trabajador que lanza una excepción termina con código `1`; el padre recibe su clase y mensaje por un canal separado de los datos de aplicación. `kill()` acepta un PID o socket y envía `SIGTERM`. `close()` aborta el trabajo pendiente, termina y recoge los hijos; tras un breve plazo envía `SIGKILL` a los que no terminen. `wait()` realiza esa limpieza si falla un callback. Un manager finalizado es de un solo uso. Los hijos no deben ser recogidos también por otro manejador de la aplicación.

## SQL

`GaragLib\MysqliPDO\MysqliPDO` conserva los argumentos de conexión originales y añade un array de opciones PDO:

```php
$db = new \GaragLib\MysqliPDO\MysqliPDO(
    $host, $port, $user, $password, $database,
    maxIntentos: 1,
    maxConnections: null,
    pdoOptions: [\PDO::ATTR_EMULATE_PREPARES => false],
);
$statement = $db->prepare('SELECT ? AS value');
$statement->execute([42]);
echo $statement->fetchColumn();
$db->close();
```

La conexión es diferida. `prepare()` y `query()` devuelven `PDOStatement` nativos. Parámetros por referencia, tipos, modos de fetch, atributos, transacciones y errores siguen el comportamiento de PDO MySQL de la versión utilizada. Por defecto se configura `ERRMODE_EXCEPTION`, `FETCH_ASSOC` y `utf8mb4`. `getPdo()` permite acceder a toda la API del objeto real; la fachada no hereda de PDO.

Las consultas asíncronas usan **otra conexión y otra sesión**:

```php
GAsync::async(function () use ($db) {
    $db->asyncQuery('SELECT 42 AS value');
    while (($result = $db->fetchAsyncResults()) === false) {
        GAsync::msleep(1);
    }
    if ($result instanceof \mysqli_result) {
        echo $result->fetch_assoc()['value'];
        $result->free();
    }
});
GAsync::run();
```

Solo se admite una consulta async pendiente por fachada. `fetchAsyncResults()` no espera: devuelve `false` si no hay resultado disponible, un `mysqli_result` para filas o `true` para una operación terminada sin filas. Los errores lanzan excepciones y liberan esa conexión. Añadir un plazo máximo al polling en las aplicaciones; `examples/database.php` muestra uno.

La sesión async **no comparte transacciones, variables de sesión, tablas temporales ni opciones de conexión de PDO**. El límite de conexiones contabiliza tanto la conexión PDO como la MySQLi. Un límite de `1` impide mantener ambas abiertas simultáneamente. Las fachadas del mismo host/puerto/base/usuario deben usar el mismo límite. Se adquieren slots sin bloquear y se liberan al fallar o cerrar; una adquisición fallida no libera slots ajenos.

`close()` libera las referencias y slots propiedad de la fachada. Los objetos PDO/statement que conserve el llamante pueden prolongar la conexión nativa: no mantenerlos después de `close()` si se depende del límite. Las conexiones persistentes de PDO también tienen su propio ciclo de vida. Crear la fachada SQL dentro del trabajador; reutilizar una conexión heredada por `fork()` se rechaza.

## Ejemplos disponibles

Los ejemplos PHP completos cargan el autoloader, utilizan archivos temporales o puertos efímeros y pueden ejecutarse desde la raíz del proyecto:

| Archivo | Experimento |
| --- | --- |
| [examples/fibers.php](examples/fibers.php) | Copia de 1 MiB con lecturas/escrituras por fibras y un temporizador concurrente; comprueba el hash |
| [examples/callbacks.php](examples/callbacks.php) | Encadenamiento de operaciones y temporizador mediante callbacks |
| [examples/sockets.php](examples/sockets.php) | Cliente y servidor TCP locales con cierre tras recibir datos |
| [examples/workers.php](examples/workers.php) | Tres trabajos ejecutados con un máximo de dos procesos hijos simultáneos |
| [examples/database.php](examples/database.php) | Consulta PDO y consulta MySQLi async con un plazo máximo de espera |
| [test/fiber.js](test/fiber.js) | Comparación opcional con una copia asíncrona en Node.js |

Para el ejemplo SQL, configurar `GASYNC_MYSQL_USER` y `GASYNC_MYSQL_DATABASE`, y opcionalmente `GASYNC_MYSQL_HOST`, `GASYNC_MYSQL_PORT` y `GASYNC_MYSQL_PASSWORD`; después ejecutar `php examples/database.php`. Los antiguos puntos de entrada `test/fiber.php`, `test/callback.php`, `test/smngr.php` y `test/fork.php` redirigen a los ejemplos mantenidos.

## Límites y migración

Las fibras aportan concurrencia cooperativa, no paralelismo ni interrupción de código PHP que consume CPU. Los procesos sí permiten paralelismo.

Los archivos regulares, `php://memory` y otros streams no vigilables se procesan en fragmentos acotados por turno. Eso permite intercalar tareas, pero **no garantiza I/O de disco no bloqueante**. `fopen()` —incluidos wrappers HTTP, TLS y resolución DNS— y las conexiones/consultas normales PDO siguen pudiendo bloquear. Los reintentos SQL esperan un segundo entre fallos y también son síncronos. No se ofrece un cliente HTTP async. El núcleo no sustituye llamadas nativas como `sleep()` o `file_get_contents()`.

Cambios de API y resultados de la revisión: [migración](docs/migration.md) y [auditoría](docs/audit.md). Estado de las APIs nativas de PHP/Linux: [informe](docs/php-linux-async.md). Las versiones futuras de PHP quedan pendientes de añadirlas y verificarlas en la matriz.

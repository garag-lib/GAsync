# Migración desde los experimentos originales

## Núcleo y drivers

- PHP mínimo: 8.3. La sintaxis compartida no requiere 8.4 ni 8.5.
- Los errores no se devuelven como EOF. `fread()` devuelve `''` en EOF y lanza ante fallos; `fwrite()` devuelve el total escrito.
- Lecturas y escrituras simultáneas ya no comparten longitud, buffer o resultado. Se conservan sus fronteras y el orden FIFO por dirección.
- No se puede sustituir un watcher que tenga solicitudes pendientes. `removeStream()` o `fclose()` cancelan esas solicitudes; las fibras reciben el error y los callbacks de éxito no se ejecutan.
- Las operaciones sin callback deben ejecutarse en una fibra creada mediante `GAsync::async()`. Suspender manualmente una fibra con `Fiber::suspend()` sin una operación que la reactive no es un mecanismo de espera de esta biblioteca.
- `init()` rechaza tareas/operaciones pendientes; no las abandona silenciosamente. Cerrar los streams implicados y ejecutar el bucle para entregar sus cancelaciones antes de reinicializar. Los recursos de usuario siguen perteneciendo al usuario.
- `GStream::dispatch()` y sus overrides devuelven `bool` y aceptan `?int $type`. Actualizar los watchers propios para respetar esta firma.
- `GStreamRead` y `GStreamWrite` pasan a ser colas internas. Se retiran sus antiguos métodos de mutación y el protocolo de apertura como primer resultado; utilizar las operaciones de `GAsync`.
- Los drivers nativos tienen bucles privados. Ya no se comparten watchers con el bucle global de otras bibliotecas.
- Los timers usan un único scheduler monotónico, independiente del driver. Un timer activo conserva su referencia; `stop()` y `clear()` lo desregistran. No crear timers repetidos de duración cero.
- `GResourceStorage` acepta iterables de recursos en `removeAll()`/`removeAllExcept()`. La antigua firma `SplObjectStorage` no podía contener recursos. Serializar o reconstruir un almacén lanza `LogicException`.
- `GFiber` permanece como ayuda para callbacks; el scheduler ya no lo utiliza para compartir resultados.

## Sockets y procesos

- Los puertos dinámicos se asignan durante el enlace del servidor. Los ejemplos usan loopback y puertos efímeros.
- `delSocket()` fuerza cierre y eliminación. `close()` conserva el cierre tras vaciar envíos; `end()` finaliza el manager. Los errores de transporte descartan envíos pendientes.
- Cada llamada UDP a `send()` representa un datagrama. Para responder desde un servidor, añadir el `$peer` recibido.
- Los errores de conexión/escritura se entregan a `setOnErrorCallback()` o se propagan desde el bucle si no hay manejador. La biblioteca ya no imprime mensajes de diagnóstico por su cuenta.
- Los callbacks de desconexión finalizan antes de cerrar el recurso. `GWorker::$smngr` y `$root_sock` pasan a ser protegidos para que los trabajadores puedan utilizarlos.
- El hijo termina al completar su trabajador; no vuelve a ejecutar el código del padre. Se notifican excepciones y estados de salida. Usar `wait()` o `close()` para recoger todos los hijos.
- Después de `fork()`, los backends nativos reinicializan su mecanismo de espera antes de eliminar registros heredados. No compartir conexiones SQL entre padre e hijo.

## PDO y MySQLi

Se conserva el namespace declarado `GaragLib\MysqliPDO`; Composer añade su ruta PSR-4 explícita a `src/GaragLib/GMysqliPDO/`. Ejecutar `composer install` o `composer dump-autoload`.

Se elimina `MysqliStatementPDO`. `prepare()` y `query()` normales devuelven **PDOStatement nativos**. Las aplicaciones que lo referencien deben utilizar `PDOStatement` para resultados normales y `mysqli_result` para resultados async.

`query($sql, $fetchMode, ...$arguments)` sigue ahora la firma variádica de PDO. Por ejemplo:

```php
$db->query($sql, PDO::FETCH_CLASS, MyClass::class, $constructorArguments);
$db->query($sql, PDO::FETCH_COLUMN, 1);
```

Los códigos de error, tipos de retorno y transacciones siguen a PDO: SQLSTATE en `errorCode()`, información `[SQLSTATE, código, mensaje]` en `errorInfo()`, identificador de inserción como cadena y resultados booleanos de transacciones. Los atributos PDO ya no se pasan como opciones MySQLi.

`fetchAsyncResults()` devuelve `false`, `true` o `mysqli_result`; no un statement que emule PDO. La consulta async no pertenece a la transacción de PDO ni comparte su configuración de sesión. El código que antes mezclaba esos estados debe separarlos expresamente.

Las referencias nativas obtenidas mediante `getPdo()` y los statements deben liberarse antes de depender de que `close()` termine la conexión física. `maxConnections` controla los slots arrendados por la fachada y no puede controlar referencias nativas externas ni el pool persistente de PDO.

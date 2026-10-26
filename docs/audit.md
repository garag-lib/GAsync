# Auditoría y validación de GAsync

Revisión realizada el **26 de octubre de 2026**, orientada a corregir el proyecto experimental y mantener una base común para PHP 8.3–8.5. Se revisaron todos los módulos PHP y las demostraciones originales. Las pruebas de regresión cubren los fallos identificados; no constituyen una garantía de ausencia de cualquier error ni de aptitud para producción.

## Problemas encontrados y solución

| Área | Fallo encontrado | Corrección y evidencia |
| --- | --- | --- |
| Bucle | El indicador de parada impedía una segunda ejecución | Estado de ejecución separado, reinicio de parada y rechazo de bucles anidados; pruebas de repetición y recuperación |
| Bucle | El canal de control podía conservar una orden STOP que afectaba a la siguiente ejecución | Parada inmediata y wakeup sin una orden STOP residual; prueba con espera en la segunda ejecución |
| Bucle | Se ignoraban watchers de usuario al decidir cuándo terminar | Cómputo de trabajo activo sin contar el canal interno; prueba con watcher manual y datos diferidos |
| Fibras | Una excepción evitaba eliminar la fibra | Limpieza con `finally` y propagación; prueba de ausencia de fibras retenidas |
| Solicitudes | Lecturas/escrituras compartían longitud, buffer y resultado | Solicitudes independientes en colas FIFO por dirección; pruebas de llamadas simultáneas |
| Escritura | Se añadía el mismo buffer dos veces | Una inserción por llamada; comprobación exacta de contenido y totales |
| Escritura | Se informaba solo del último fragmento escrito | Offset y total por solicitud; prueba con 2 MiB, contrapresión y otra escritura encolada |
| Lectura | EOF se confundía con error y las esperas podían quedar sin completar | EOF como `''`, excepciones diferenciadas y cancelación al cerrar/desinscribir |
| Lectura | Un backend nativo podía esperar otro evento aunque PHP ya tuviera bytes en su buffer | Comprobación de bytes almacenados antes de esperar; prueba de dos lecturas sobre una sola llegada de datos |
| Streams | Apertura interpretada como primer resultado de lectura/escritura y modos incompletos | Apertura separada y semántica nativa; pruebas de `w+b`, `x+`, `c+` y `a+` |
| Drivers | `GStream` no esperaba disponibilidad de escritura | Lectores y escritores incluidos en `stream_select()` |
| Drivers | `ev`/`event` podían bloquear ignorando el plazo del scheduler | Espera acotada mediante temporizador de backend; pruebas de temporizadores cortos |
| Drivers | Falsa detección de errores al interrumpir `select()` con señales | Tratamiento explícito de EINTR; prueba con `SIGUSR1` |
| Drivers | Archivos regulares no son vigilables con todos los backends | Operaciones acotadas por turno fuera del poller; pruebas y ejemplos de copia |
| Drivers | Tuberías PHP podían clasificarse como streams inmediatos | Clasificación por tipo de descriptor; prueba con una tubería de subproceso |
| Timers | `start()` mantenía el estado detenido y la duración transcurrida podía ser casi cero | Scheduler monotónico común y rearme correcto; pruebas de reinicio, repetición y cancelación |
| Timers | Una duración repetida `0.0` podía eludir una comprobación estricta de cero | Rechazo de cero tanto entero como flotante |
| Recursos | `Serializable`, firmas internas y propiedades dinámicas generaban avisos | Firmas explícitas y propiedades declaradas; serialización de recursos rechazada |
| Recursos | Operaciones de conjuntos exigían `SplObjectStorage`, que no almacena recursos | Iterables de recursos y mantenimiento de identidad incluso durante la eliminación de recursos cerrados |
| Sockets | `substr($data, $data)` fallaba en escrituras parciales | Conservación del offset numérico; transferencia exacta de 2 MiB |
| Sockets | La asignación de puerto cerraba una reserva antes de abrir el servidor | Enlace directo al puerto cero y consulta del puerto realmente asignado |
| Sockets | Conexiones y plazos no se confirmaban mediante disponibilidad y estado | Watcher de escritura, comprobación de conexión y deadline; pruebas de conexión, rechazo y timeout |
| Sockets | Un datagrama UDP vacío se interpretaba como desconexión y podían mezclarse mensajes | Colas de datagramas individuales; prueba de vacío seguido de dos mensajes |
| Sockets | Callbacks de desconexión podían recibir un recurso ya cerrado | Cierre después de finalizar el callback, con una única desconexión |
| Procesos | Variables indefinidas, PID/socket ambiguo y límites mal contabilizados | Estado explícito por hijo, colas y callbacks con PID estable |
| Procesos | Descriptores heredados mantenían conexiones abiertas y podían impedir EOF | Cierre de extremos no utilizados e IPC independiente para errores |
| Procesos | El hijo podía volver al flujo del padre o silenciar errores | Finalización explícita y notificación de excepción/código de salida |
| Procesos | Solo se recogían algunos hijos | Seguimiento independiente de salida y desconexión; pruebas de ausencia de hijos pendientes |
| Procesos | El hijo podía eliminar registros del poller heredado y bloquear al padre | Reinicialización del backend después de fork **antes** de eliminar watchers; probado con libev y libevent |
| Procesos | Un callback del padre podía dejar hijos vivos al lanzar | `wait()` aborta y recoge todos sus hijos ante errores; regresión específica |
| SQL | Namespace y ruta incompatibles con PSR-4 | Mapeo explícito y pruebas usando el autoloader generado por Composer |
| SQL | Cargar una clase ejecutaba consultas de demostración | Retirada de la emulación y eliminación completa de esos efectos secundarios |
| SQL | Parámetros por referencia, fetch y atributos no cumplían el contrato de PDO | Delegación a PDO real y resultados `PDOStatement`; comparación con la API nativa |
| SQL | Se permitían consultas async solapadas y el polling esperaba hasta un segundo | Una consulta pendiente y polling con timeout cero; pruebas de rechazo y retorno inmediato |
| SQL | Resultados async sin filas se trataban como si fueran conjuntos de filas | Distinción explícita entre `false`, `true` y `mysqli_result` |
| SQL | El semáforo podía liberar slots no adquiridos o usar un identificador diferente | Identificador conservado, contador de leases y liberación ante cierre/fallo; pruebas con un único slot |
| Compatibilidad | PHP 8.5 depreca los alias `attach`/`detach`/`contains` de `SplObjectStorage` | Uso de operaciones de offset, sin suprimir deprecaciones |
| Ejemplos | Dependencias implícitas, credenciales de ejemplo, puertos/archivos fijos y salidas anticipadas | Ejemplos completos, temporales, configuración por entorno y plazos máximos |

## Validación ejecutada localmente

Se obtuvieron runtimes y paquetes en `/tmp`, sin reemplazar el PHP 8.1 instalado en el sistema. Las extensiones `ev` **1.2.3** y `event` **3.1.6** se compilaron para cada versión. MySQL **8.4.13** se ejecutó con directorio de datos propio y puerto de loopback dedicado; no se utilizó una base de datos existente.

| PHP | GStream | GEV | GEVENT |
| --- | --- | --- | --- |
| 8.3.36 | 45 pruebas, 164 aserciones | 45 pruebas, 164 aserciones | 45 pruebas, 164 aserciones |
| 8.4.27 | 45 pruebas, 164 aserciones | 45 pruebas, 164 aserciones | 45 pruebas, 164 aserciones |
| 8.5.12 | 45 pruebas, 164 aserciones | 45 pruebas, 164 aserciones | 45 pruebas, 164 aserciones |

Las nueve ejecuciones completas pasan con `E_ALL` y PHPUnit configurado para fallar ante warnings, notices, deprecaciones y pruebas arriesgadas. Las pruebas SQL y de procesos se ejecutan en todas ellas; no hay pruebas omitidas en esa matriz.

También se ejecuta la batería en las tres versiones desactivando `ev`, `event`, `PDO`, `pdo_mysql`, `mysqli`, `posix`, `sockets` y `sysvsem`. Pasan las pruebas aplicables al núcleo y se omiten expresamente ocho pruebas que necesitan SQL o funciones Unix opcionales. La prueba que valida un límite inválido de trabajadores sigue funcionando antes de comprobar extensiones.

Verificaciones adicionales:

- Los cinco ejemplos PHP se ejecutan en cada combinación: **45 ejecuciones** verificadas.
- El ejemplo comparativo Node.js termina con la copia y su SHA-256 verificados.
- Lint de todos los PHP de producción, pruebas y ejemplos en cada versión.
- Composer valida el manifiesto con `--strict` y resuelve dependencias con PHP 8.3 como plataforma mínima.
- PHPStan **2.3.2**, nivel **5**, analiza todo `src/` sin errores, usando el runtime con las extensiones nativas cargadas.
- Se comprobaron ciclos repetidos de inicialización y de sockets mediante `/proc/self/fd`, ausencia de hijos pendientes con `waitpid()` y contenido exacto bajo contrapresión.
- El README fue revisado por un subagente a petición del usuario; su ejemplo introductorio se ejecutó en las tres versiones.

La configuración de GitHub Actions reproduce la matriz PHP/driver con MySQL 8.4 y añade tres jobs sin extensiones opcionales. **La ejecución remota de GitHub Actions no se ha realizado desde esta sesión**; los resultados anteriores corresponden a ejecuciones locales reales.

## Cambios públicos y límites

La [guía de migración](migration.md) detalla las rupturas: `PDOStatement` reales en lugar de `MysqliStatementPDO`, resultados MySQLi nativos para async, firmas explícitas de watchers, semántica de EOF, cancelaciones y ciclos de vida. El manifiesto Composer refleja la licencia LGPL 2.1 del archivo `LICENSE`, que no se modifica.

No se ha convertido el I/O de archivos o la apertura de conexiones en operaciones garantizadamente no bloqueantes. PDO normal y los reintentos de conexión siguen siendo síncronos. La conexión async usa otra sesión y queda fuera de las transacciones de PDO. Los slots SQL pertenecen a la fachada: referencias nativas externas o conexiones persistentes pueden prolongar la conexión física más allá de `close()`.

La compatibilidad verificada es Linux CLI en las versiones de la tabla. No se ha certificado Windows, macOS, FPM, wrappers HTTP/TLS como clientes async ni una carga de producción. El [informe PHP/Linux](php-linux-async.md) diferencia las APIs implementadas de las propuestas futuras; no se ha añadido un backend experimental de PHP 8.6/8.7 ni io_uring.

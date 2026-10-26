# PHP, Linux y asincronía nativa

Comprobado el **26 de octubre de 2026**. Hay varias propuestas relacionadas, con estados distintos; no existe una única función futura que vuelva asíncrona toda la biblioteca.

| Pieza | Estado consultado | Consecuencia para GAsync |
| --- | --- | --- |
| Fibers | Disponibles desde PHP 8.1 | Permiten suspender flujos; GAsync aporta la planificación y la espera de I/O. |
| PHP 8.5 | Versión estable; sin async/await general en el núcleo | Mantener el scheduler propio y corregir compatibilidad; no exigir sintaxis exclusiva de 8.5. |
| Polling API, `Io\Poll` | RFC implementada y fusionada para PHP 8.6 | Candidata a un driver futuro con `epoll` en Linux, manteniendo la misma semántica de solicitudes. |
| Concurrency Support in the PHP Engine | En discusión; el texto apunta a una versión posterior, 8.7 | Es una interfaz interna para schedulers; no proporciona por sí sola un event loop ni una API PHP general de async/await. |
| IO Hooks and Operations | Propuesta en discusión | Podría permitir que llamadas de I/O nativas cooperen con un proveedor/scheduler. No debe darse por disponible en 8.5. |
| Ring API / `ior` | Diseño/propuesta separada, todavía experimental | Plantea completado de operaciones con `io_uring` en Linux, IOCP en Windows y un pool de hilos como alternativa. No es una dependencia actual. |

## Qué cambia para Linux

`stream_select()` y `epoll` indican **disponibilidad**: por ejemplo, que un socket puede aceptar bytes. La aplicación todavía debe efectuar la operación y gestionar escrituras parciales, EOF y errores. Un `epoll` más escalable no corrige por sí solo un scheduler ni convierte una lectura de archivo regular en I/O de completado.

Una API de completado basada en `io_uring` puede ejecutar operaciones de archivos y entregar su resultado posteriormente. Su disponibilidad depende también del kernel, configuración y restricciones del entorno. El diseño de Ring contempla alternativas cuando ese backend no puede utilizarse.

Por ello, la preparación aplicada mantiene tres separaciones: solicitudes individuales, espera de disponibilidad en el driver y planificación de tareas/temporizadores. La inferencia de diseño es que un futuro driver Poll puede reemplazar la espera actual sin cambiar la API de fibras y callbacks. La integración de hooks o Ring requerirá evaluar sus contratos finales, el ciclo de vida de recursos, las cancelaciones y el comportamiento después de `fork()`.

Esta revisión no añade esos backends ni promete una mejora de rendimiento sin medirla. Para PHP 8.3–8.5 se mantienen `GStream`, `GEV` y `GEVENT`. Los archivos regulares siguen teniendo las limitaciones descritas en el README.

## Fuentes primarias

- [Anuncio de PHP 8.5](https://www.php.net/releases/8.5/en.php).
- [Manual de Fibers](https://www.php.net/manual/en/language.fibers.php).
- [RFC Polling API: estado, backends e implementación para PHP 8.6](https://wiki.php.net/rfc/poll_api).
- [RFC Concurrency Support in the PHP Engine](https://wiki.php.net/rfc/async_scheduler_abi).
- [RFC True Async, propuesta de API de concurrencia](https://wiki.php.net/rfc/true_async).
- [RFC IO Hooks and Operations](https://wiki.php.net/rfc/io_hooks) y [presentación/discusión en php.internals](https://news-web.php.net/php.internals/132763).
- [Diseño del autor sobre hooks, colas de operaciones y Ring](https://gist.github.com/bukka/87359261a4bfaa572ce43c93c0554b55).

Los estados de las propuestas y sus versiones objetivo pueden cambiar. Revisarlos antes de implementar un backend posterior.

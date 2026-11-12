# Informe del reviewer: el registrador comercial en la transacción de pagos y facturas (I-1), y los tres cambios del lead en `1b91dc5b`

Fecha: 2026-11-11 · Rama `feat/ultron-studio-h9` · Árbol `_worktrees/ultron-h8/Iron-Body/backend`
Revisado: `181075b3` (completo) y en `1b91dc5b` solo `OutreachConfig::complaintDays()/complaintPurposes()`, `OutreachReplay::lacksLadderKey()` y sus tres pruebas.

## Veredicto

**APROBADO CON CONDICIONES.** No hay bloqueantes. La corrección se puede desplegar esta noche: cierra los caminos silenciosos de `record()` y de la consulta propia del observador de facturas. Lo he comprobado en PG 14 con los dos códigos, el anterior y el nuevo. No cambia qué se registra, ni cuándo, ni qué se escribe en el log.

Condiciones:
1. Que no se dé I-1 por cerrado en los **observadores**. El commit protege al registrador, pero las consultas del `CommercialSubjectResolver` siguen fuera del punto de guardado. En el camino de la membresía de un pago Wompi, una captura que ya existía se traga su fallo dentro de la transacción del pago (IMPORTANTE 1). No lo trae este commit, que tampoco lo empeora: hay que abrirlo como seguimiento con dueño.
2. Los dos menores (ramas de `isolated()` que ninguna prueba fija) pueden ir en la misma tanda de seguimiento.

## Verificado ejecutando

| Qué | Resultado |
|---|---|
| PG 14 local (`ultron_studio_rev_pagos`, creada para esto): `CommercialEventRecorderPostgresTest` + `CommercialEventRecorderContainmentTest` | **OK, 14 pruebas y 116 aserciones** |
| SQLite, los mismos dos ficheros | OK: 6 en verde y 8 omitidas, todas con un motivo escrito y cierto (uuid, `lock_timeout`, `LOCK TABLE`, interbloqueo, transacción abortada: nada de eso existe en SQLite) |
| **Rojo con el código anterior**: copia del backend con `cp -c -R` fuera del árbol, `CommercialEventRecorder.php` y `ElectronicInvoiceCommercialObserver.php` devueltos a `1ef1d72e`, en PG | **11 de 14 fallan**: los 5 escenarios de pago (`'created'` en vez de `'approved'` tras el COMMIT), la factura que desaparece (`null` en vez de `'pending'`), la factura cuya sentencia siguiente da 25P02, los 3 de contención (25P02) y «el siguiente hecho de la misma transacción». Las 3 que pasan con el código viejo son de no regresión: sin transacción, el duplicado normal y `afterCommit`. |
| `tests/Feature/Commercial` completo en SQLite | OK, 185 pruebas, 8 omitidas |
| `tests/Feature/Commercial` completo en PG, código nuevo frente al viejo | Con el nuevo dan error 31, que son **un subconjunto exacto** de los 42 que fallan con el viejo: con el código nuevo no falla nada que antes pasara, y los 11 que solo fallan con el viejo son las pruebas de I-1. Los 31 que comparten son de antes del commit: las pruebas meten en el `Context` un `correlation_id` que no es uuid (`"test"`, `"e2e"`, `"test-correlation"`) → 22P02. (En el árbol hay 5 pruebas más, del otro agente, `ScanCommercialFactsCeilingTest`, que la copia no tenía.) |
| PG en `psql`: puntos de guardado con el mismo nombre anidados | Se apilan: `ROLLBACK TO`/`RELEASE` actúan sobre el más reciente, así que entrar dos veces en `isolated()` es seguro. `SAVEPOINT` fuera de una transacción → `ERROR 25P01`. |
| `pdo_pgsql`: `inTransaction()` | `true` tras un `BEGIN` hecho con SQL directo y también con la transacción abortada (refleja `PQtransactionStatus`), así que la guarda detecta una transacción abierta por fuera de Laravel. `SAVEPOINT` fuera de transacción lanza `PDOException` con código 25P01. |
| Las 3 pruebas de los cambios del lead, en SQLite y en PG | OK (3 + 1 pruebas) |

### Mutaciones sobre `isolated()` (copia, PG, los dos ficheros)

| Mutación | Resultado |
|---|---|
| M1: no vuelve al punto de guardado (el `catch` solo relanza) | **muere** (11 rojas) |
| M2: vuelve, pero no relanza (`return null`) | **muere** (6 rojas: fallan las aserciones de `record_failed` y la de `lock timeout`/`deadlock` en el mensaje) |
| M3: usa siempre el punto de guardado, aunque no haya transacción | **muere** (falla `test_sin_transaccion_abierta…`: 25P01) |
| M4: la guarda mira solo `transactionLevel()` y no `inTransaction()` | **sobrevive** → MENOR 1 |
| M5: no hace `RELEASE` en el camino feliz | **sobrevive** → MENOR 2 |

### Mutaciones sobre los cambios del lead (copia, SQLite)

| Mutación | Resultado |
|---|---|
| L1: `complaintDays()` con `$v > 0` (el 0 deja de apagar el freno) | muere («30 is identical to 0») |
| L2: `complaintPurposes()` deja de exigir la finalidad comercial | muere |
| L3: el replay vuelve a decir siempre `ladder_input_outdated` | muere |

## Bloqueantes

Ninguno.

## Importantes

### 1. Las consultas del resolver siguen fuera del punto de guardado, y en el camino de la membresía de un pago Wompi su fallo se traga en silencio

`app/Services/Commercial/CommercialSubjectResolver.php:64-93`, llamado desde `app/Observers/Commercial/MembershipCommercialObserver.php:65`. Responsables: **database-specialist** y **backend**.

Esto lo he LEÍDO; no lo he reproducido. La cadena:

`WompiTransactionService::transitionTo()` abre `DB::transaction` (`:253`) → `$fresh->save()` con `approved` → `PaymentMembershipActivator::activate()` (`:368`), cuyo cuerpo entero va en `try { … } catch (Throwable)` (`PaymentMembershipActivator.php:116`) → `extendMembership()` → `MembershipPeriod::apply()` → `$user->save()` (`MembershipPeriod.php:272`) → `MembershipCommercialObserver::updated()` → `resolver->fromUser()`. Este último hace `Schema::hasTable('members')`, un SELECT a `members`, `Schema::hasTable('marketing_leads')` y un SELECT a `marketing_leads`, todo **antes** de `record()` y **fuera** de `isolated()`.

**Escenario de fallo:** una de esas lecturas falla (la víctima de un interbloqueo durante un despliegue con un ALTER sobre `members`/`marketing_leads`, una cancelación, o un `statement_timeout` si el rol lo tuviera en producción; esto último no lo puedo comprobar desde el repositorio). La excepción sube por el observador y el `catch` de `activate()` la traga con un `warning`. La transacción del pago queda abortada (25P02), y las capturas de las suscripciones (`:383`, `:399`, `:416`) se tragan los 25P02 siguientes. `DB::transaction` hace COMMIT y el servidor responde ROLLBACK sin lanzar: es el mismo síntoma de I-1, el pago que se queda sin aprobar y sin ninguna excepción. La reconciliación de cada cinco minutos lo atenuaría, pero no lo he comprobado.

- **No es una regresión**: el resolver ya iba sin protección antes. El commit no lo empeora; lo que pasa es que su título y su mensaje («registrar un hecho ya no puede abortar la transacción del pago») se pueden leer como si el camino de los observadores quedara cerrado, y no lo está.
- **Ruidoso frente a silencioso en lo que queda.** Todas las lecturas del resolver en los observadores (Payment `fromMember`, Membership `fromUser`, Attendance `fromMember` + `entriesFor()` en `AttendanceCommercialObserver.php:67`, Member, MarketingConversation, MarketingAppointment y las dos de facturas tras el `find`) son **ruidosas** si nadie captura por encima: la excepción sale de `save()` y `DB::transaction` revierte y relanza, y eso es aceptable. Son **silenciosas** cuando alguien se la traga dentro de la transacción. Ese caso lo he confirmado leyendo `activate()`. No he recorrido si `InvoicingService` o las altas de asistencia (torniquete) tienen capturas parecidas: **queda por confirmar**. `MarketingAiActionCommercialObserver` registra en `DB::afterCommit`, ya fuera de la transacción: sin riesgo.
- **Corrección propuesta (seguimiento, no esta noche):** resolver el sujeto dentro de la misma protección, por ejemplo `try { $subject = $this->recorder->isolated(fn () => $this->resolver->fromUser($id)); } catch (\Throwable) { log; return; }` en cada observador que consulta, o mejor un único punto de entrada del registrador que reciba el sujeto como cierre y lo resuelva dentro de `isolated()`. Más una prueba en PG que pase por `transitionTo()` → `activate()` con la lectura del resolver fallando (con `LOCK TABLE members IN ACCESS EXCLUSIVE MODE` + `lock_timeout`, igual que las de facturas), y que compruebe que el pago queda `approved` tras el COMMIT.

## Menores

### 1. Ninguna prueba fija la rama «transacción abierta por fuera de Laravel»

`app/Services/Commercial/CommercialEventRecorder.php:217`. Si se quita `&& ! $db->getPdo()->inTransaction()` (M4), todo sigue en verde. **Escenario de fallo:** con un `BEGIN` hecho con SQL directo (`transactionLevel() === 0`), `record()` correría sin punto de guardado y un INSERT fallido abortaría esa transacción, que es justo I-1. Hoy no hay código de producción que abra transacciones así (busqué `getPdo()->beginTransaction`, `statement('BEGIN`, `unprepared('BEGIN` y `exec('BEGIN`: nada), así que solo es un hueco de las pruebas. Propuesta: una prueba en PG con `DB::connection()->getPdo()->exec('BEGIN')`, un `correlation_id` inválido y una sentencia después. → **tester**.

### 2. Ninguna prueba fija el `RELEASE` del camino feliz

`CommercialEventRecorder.php:233`. Sin él (M5), todo sigue en verde. **Escenario de fallo:** los puntos de guardado del mismo nombre se apilan hasta el final de la transacción de quien llama. No cambia el resultado, pero sí el coste de una transacción larga que registre muchos hechos. Propuesta: después de un `record()` con éxito dentro de una transacción, que `ROLLBACK TO SAVEPOINT commercial_event_record` falle con «no existe» (dentro de un punto de guardado de la propia prueba). → **tester**.

## Respuestas a lo que pidió el lead

### 1. ¿La protección es correcta y completa? Recorrido de `isolated()`

Leído, salvo donde dice «comprobado».

- **Sin transacción**: nivel 0 y `inTransaction()` falso → `work()` sin más, igual que antes. Comprobado en PG y SQLite (prueba c; M3 lo confirma). Los dos `getPdo()` solo fuerzan una conexión que `exists()` iba a abrir de todos modos.
- **Con transacción de Laravel / anidada en otra `DB::transaction` / dentro de `RefreshDatabase`**: `SAVEPOINT commercial_event_record` en la PDO de escritura. El nombre no choca con los `trans<N>` de Laravel. `ROLLBACK TO` solo deshace lo que vino después del punto de guardado y no toca el `trans2` de Laravel. El contador de Laravel no se toca (comprobado: las pruebas comprueban el nivel antes y después). Las afirmaciones del docblock sobre Laravel 12 son ciertas: `handleTransactionException`, con nivel > 1 y un error de concurrencia, baja el contador y lanza `DeadlockException` sin `ROLLBACK TO` (`ManagesTransactions.php`), y `handleQueryException` no reintenta con `transactions >= 1`.
- **Transacción abierta fuera de Laravel**: la detecta `inTransaction()` (comprobado con `pdo_pgsql`). Ninguna prueba la fija (MENOR 1). Con nivel 0, Laravel podría reconectar y repetir una sentencia si se pierde la conexión: es un caso de borde de otro caso de borde, y hoy no hay código así.
- **Reentrada**: los nombres repetidos se apilan en PG (comprobado en `psql`) y en SQLite. Además, `CommercialEvent` no tiene observadores registrados, así que no hay reentrada real.
- **Conexión de lectura y escritura separadas**: `config/database.php` no tiene `read`/`write`. Aunque los tuviera, con nivel ≥ 1 Laravel lee de la PDO de escritura (`getReadPdo()`), donde está el punto de guardado.
- **Conexión perdida a mitad**: `SAVEPOINT` o `ROLLBACK TO` con SQL directo lanzan `PDOException`, que la captura de `record()` registra y convierte en `null`. Quien llama falla en voz alta en su siguiente sentencia o en el COMMIT (Laravel: `handleCommitTransactionException`), y eso es aceptable. Detalle: en ese caso el log muestra el error del `ROLLBACK TO`, no el original (no se encadena). Solo afecta al diagnóstico.
- **`afterCommit`**: `queueEvaluation()` corre después del `RELEASE`, al mismo nivel de Laravel que antes, así que el despacho sigue colgado del COMMIT de quien llama. Comprobado: la prueba con la cola `database` de verdad pasa en PG y en SQLite. Sale en verde también con el código viejo, como debe ser en una prueba de no regresión.
- **¿Puede el propio `SAVEPOINT`/`RELEASE` romper algo?** Fuera de transacción es un error en PG, pero la guarda lo evita (comprobado, M3). Si Laravel creyera que hay transacción y la PDO no, el `SAVEPOINT` fallaría con 25P01: el hecho se perdería con un `record_failed`, sin dañar a nadie, porque no hay transacción que abortar. Si la transacción ya venía abortada por otra cosa, el `SAVEPOINT` falla con 25P02, se contiene, y no empeora nada. `SAVEPOINT` no necesita privilegios, así que el rol de producción que solo puede escribir datos (DML) basta.
- **Lo que queda dentro de `record()`**: nada. La lectura previa y el INSERT van dentro. Lo de fuera (`ChannelLog`, que no escribe en la base, y `dispatch()`) no hace consultas en la transacción. **Fuera de `record()`**: el resolver y el `entriesFor()` de asistencias (IMPORTANTE 1).

### 2. ¿Cambia algo de lo que hacía?

Lo he leído y lo he comprobado con las pruebas que pasan con los dos códigos. Mismos eventos, claves, payload, mensajes de log y campos (la prueba del uuid fija `event`, `exception=QueryException` y el mensaje), y mismo momento. El duplicado normal sigue parándose en la lectura, sin INSERT y sin errores (comprobado con `DB::listen`). La carrera de unicidad sigue sin dejar nada en el log. El rendimiento: dos sentencias más por `record()` cuando hay transacción (`SAVEPOINT` y `RELEASE`) y otras dos en la consulta del observador de facturas. Sin transacción, ninguna. Un dato para saberlo, sin más: cada INSERT dentro de un punto de guardado consume un subxid, y más de 64 en una sola transacción desbordan su caché en PG. No he encontrado ningún llamador que registre tantos en una sola transacción: el escáner no abre ninguna, y los observadores registran de 1 a 3 hechos por escritura.

### 3. ¿Prueban las pruebas lo que dicen?

Sí. Las de PG reproducen I-1 con un COMMIT de verdad, sin `RefreshDatabase`, y leen desde otra sesión lo que quedó confirmado. La carrera usa dos sesiones y comprueba que hubo espera (≥ 0,3 s). El interbloqueo se fuerza con `deadlock_timeout` asimétricos. Rojo con el código anterior y verde con el nuevo, comprobado por mí. Las omisiones en SQLite están justificadas y no esconden nada: todas son mecanismos que SQLite no tiene. Huecos: los MENORES 1 y 2. Una nota de lectura: `tearDown` borra **todo** `commercial_events`. Es correcto porque `pgunit.sh` exige una base `ultron_studio_*` desechable, pero esa prueba no se debe apuntar nunca a otra base.

### 4. Los tres cambios del lead

Correctos, y sus pruebas los fijan (L1, L2 y L3 mueren).
- `complaintDays()`: solo un entero ≥ 0 manda (tope 365); el 0 apaga; negativo, texto, decimal, booleano o nada → 30. La ventana es independiente de `human_requested` (comprobado en la prueba). Nota para cuando la clave exista: si se lee de `env()` sin `(int)`, un `"0"` en texto **no** apagará el freno (falla cerrado, y la prueba lo fija con `'7'` → 30). Que la cree quien conozca esto.
- `complaintPurposes()`: lista de textos del catálogo que incluya la finalidad comercial; si no, el valor por defecto. Con `array_intersect` sobre el catálogo se quitan los repetidos y queda el orden del catálogo. Correcto.
- `lacksLadderKey()`: refleja exactamente la comprobación de claves de `PolicyLadder::normalize()` (`array_key_exists` y solo entra en lo que es un objeto). Un objeto que llega con otro tipo se queda en `invalid`, que es lo correcto. Las otras dos `InvalidArgumentException` de la escalera (`phone` y `voicePolicyReasons`) son de valor, no de clave, así que salen `invalid`. Caso límite sin importancia: si a una entrada le falta una clave y además trae un tipo malo antes en el SPEC, se clasifica como `outdated`.
- `test_una_queja_nunca_es_disparador_aunque_el_evento_tenga_emisor`: pasa en verde, y lo he leído: recorre un caso válido por finalidad y comprueba `trigger_not_allowed` y `canonical = null`.

### 5. Despliegue

Nada lo impide: solo código (2 ficheros de `app/` y 2 de pruebas), sin migraciones, rutas ni configuración. Hace falta la recarga habitual de PHP-FPM/opcache. **Vuelta atrás**: `git revert 181075b3` devuelve exactamente al comportamiento anterior, sin restos de esquema ni de datos; los hechos registrados mientras tanto siguen siendo filas válidas. Pero revertir **reabre I-1** mientras `COMMERCIAL_EVENTS_ENABLED` esté encendido. La alternativa de emergencia sin tocar código es apagar `COMMERCIAL_EVENTS_ENABLED` y regenerar la caché de configuración: los observadores dejan de escribir y el riesgo desaparece, a cambio de no registrar hechos.

## Lo que no pude revisar

- Si `InvoicingService`, las altas de asistencia o otros llamadores tienen capturas que se tragan errores dentro de la transacción (forma parte del IMPORTANTE 1; por confirmar).
- El `statement_timeout`/`lock_timeout` del rol de producción y si hay pgbouncer en modo transacción: no está en el repositorio y no he tocado producción.
- No he reproducido la cadena del IMPORTANTE 1 con una prueba: es lectura.

## Estado del árbol

No he tocado nada salvo este informe. Los cambios sin commit de `app/Console/Commands/ScanCommercialFacts.php` y `tests/Feature/Commercial/ScanCommercialFactsCeilingTest.php` son del otro agente. Las mutaciones y el rojo los hice en una copia en el scratchpad de la sesión. He dejado creada la base local desechable `ultron_studio_rev_pagos` en el clúster de pruebas (puerto 55432).

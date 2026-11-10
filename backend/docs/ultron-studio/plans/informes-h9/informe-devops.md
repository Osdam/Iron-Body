# Hito 9 · paquete OPS9-a · informe del `devops`

Fecha: 09/10-11-2026 (ensayos en el banco entre las 01:44 y las 01:51 UTC del 10-11). Contrato: `docs/ultron-studio/ULTRON_STUDIO_H9_CONTRACT.md` (§9, §11 fila OPS9, §12 paso 1, §14, L9-4, L9-6, L9-29, L9-34; Paquete SSE9). Worktree `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h8-voice`, HEAD `ab54a25`. **Sin commit, nada en el VPS, sin credenciales.** Todo lo que sigue se ha ejecutado en local o en el banco; lo que no, se dice.

## 0. Resumen en cifras

| Qué | Cifra |
|---|---|
| Ficheros nuevos (exclusivos de OPS9) | 11: `ops/{h9-outreach-scope.sh, deploy-h9-backend-fase1.sh, h9-schema-check.sql, smoke-h9.py, smoke-run-h9.sh}` y `ops/banco-h9/{comun,montar,preparar,sembrar,ensayar}.sh` + `README.md` — 3 320 líneas |
| Fichero compartido tocado | `routes/console.php`: +48 líneas, el bloque del programador del H9, **comentado** (php -l OK; Pint marca dos fixers que ya fallaban en HEAD) |
| `h9-schema-check.sql` | ejecutado contra la base de DB9 (`ultron_studio_h9_db_roles`) y contra el banco del H8: los recuentos de L9-34 cuadran en «después» (123/137/9/25 · 51/75/3/8 · 33 funciones · libro 11 sujetos · 4 índices) y en «antes» (68/84/6/18 · 0) |
| `deploy-h9-backend-fase1.sh` | 707 líneas; sus 16 consultas `Q_*` cuadran contra la base de DB9 (privilegios EXACTOS por tabla); ensayado entero en el banco: paso 0 EN VERDE, despliegue con **5 desvíos, los 5 por piezas de B9-run aún no entregadas**; `--indices`: «nada que hacer» (la 100003 entró en la misma pasada) |
| `smoke-h9.py` + `smoke-run-h9.sh` | contra un simulador: 14 OK / 21 OK; en el banco (API real con el código del H9): **15 OK · 2 FALLO · 2 OMITIDO**, los 2 FALLO y las 30 líneas ERROR por la misma causa (las 30 rutas de S9 sin entrada en `AuthorizationMap`: 403 `forbidden`, B9-run) |
| `h9-outreach-scope.sh` | 693 líneas, 19 fragmentos python y 3 de tinker compilan; en el banco: `estado` OK, `gobierno on` y `gobierno off` ESCRITOS con 1 desvío cada uno (404 de `commercial-automation/status`: las rutas de B9-run no están) — copia previa, escritura atómica, parseo, caché, comparación antes/después, programador y workers, todo en verde |
| Banco del H9 | montado en 1 min 22 s; origen con **dos commits** (32 ficheros del cierre del H8 → PREVIO `5d9cfd9`; 117 del H9 → ESPERADO `fde15b6`); valla 7/7; `ensayar.sh todo` repetible |
| Supuestos sobre B9-run | las 12 claves de `config/outreach.php` que el mando y el smoke leen **coinciden** con las que B9-run escribió; `outreach:stop --all --reason=` y `outreach:monitor` existen con esa forma; faltan las rutas, `AuthorizationMap` y los nombres del barrido y el despacho |

## 1. Ficheros (lista exacta)

| Fichero | Qué es | Calcado de |
|---|---|---|
| `docs/ultron-studio/ops/h9-outreach-scope.sh` | el MANDO de las banderas del H9 por niveles (§9.2), con copia previa, 600/640, escritura atómica, PARADA GLOBAL y la negativa a abrir envíos sin el commit `outreach(sends-open):` | `h8-voice-scope.sh` |
| `docs/ultron-studio/ops/h9-schema-check.sql` | solo lectura: recuentos de L9-34 antes/después, dueños, privilegios por tabla, cerrojos sobre `identities`, transacciones largas, tamaños de las 4 tablas de la 100003, filas | `h8-schema-check.sql` |
| `docs/ultron-studio/ops/deploy-h9-backend-fase1.sh` | la fase 1 (código + 3 migraciones + 15 variables apagadas), `--solo-comprobar`, `--acepta-main`, y el paso aparte `--indices [--acepta-hora]` | `deploy-h8-backend-fase1.sh` |
| `docs/ultron-studio/ops/smoke-h9.py` | el smoke sin filas positivas: 30 rutas de S9, 13 de B9-run, canal SSE, presupuestos de PERF9, fugas | `smoke-h8.py` |
| `docs/ultron-studio/ops/smoke-run-h9.sh` | el lanzador: configuración efectiva, programador, base exacta antes/después, incidentes, colas, registros, valla/vigía, y la pasada en sombra sin envíos (fase 2) | `smoke-run-h8.sh` |
| `docs/ultron-studio/ops/banco-h9/*` | el banco local (sin simuladores: cero red) | `banco-h8/*` (la valla se reutiliza tal cual) |
| `routes/console.php` (bloque) | el programador del H9, comentado hasta que B9-run nombre las órdenes | el bloque de voz del H8 |
| `docs/ultron-studio/plans/informes-h9/informe-devops.md` | este informe | — |

No he tocado `ops/supresion-1581.sql` (la sección del H9 va con la especificación de §7 cuando el lead decida el diseño, Q-4) ni `ironbody-migrate-propuesta.sh` (de OPS7; ver Q-1).

## 2. Qué ensayé, con la salida real

### 2.1 `h9-schema-check.sql`

```
psql -v owner=ultron_studio_h9_owner -v app=ultron_studio_h9_app -d ultron_studio_h9_db_roles < h9-schema-check.sql
disparadores_studio 123 · check_studio 137 · parciales_studio 9 · tablas_studio 25 · disparadores_gobierno_h9 55 · check_gobierno_h9 53
disparadores_outreach 51 · check_outreach 75 · parciales_outreach 3 · tablas_outreach 8 · disparadores_apagados 0
funciones_h9 33 · funciones_h9_definer 0 · duenos_distintos_funciones_h9 1 · huella_funcion_f 4ad748b5f17f09b398f7f641c197d7ee · funcion_es_la_de_f t
libro_11_sujetos t · indices_lectura_h9_validos 4 · indices_lectura_h9_invalidos 0 · migraciones_h9_registradas 2 · migracion_indices_registrada 1
privilegios: ra---s en outreach_decisions, outreach_outcomes, ultron_studio_strategy_publications, _reviews, _simulations;
             raw--s en las otras 10 (outreach_cursors raw--?: no tiene secuencia)
```
Contra `ultron_studio_banco_h8_ops8c` (el «antes»): 68/84/6/18, outreach 0/0/0/0, funciones 0, libro `f`, índices 0, migraciones 0. Dos consultas que el H8 no tenía: cerrojos sobre `identities` (la FK de `person_bindings`, L9-6) y transacciones > 30 s + tamaño de `users`, `payment_transactions`, `class_reservations`, `receivables` (§13 de DB9, para el paso aparte).

### 2.2 Las consultas del despliegue

Las 16 `Q_*` de `deploy-h9-backend-fase1.sh`, extraídas y ejecutadas contra `ultron_studio_h9_db_roles` (con `iron_app` → el rol de esa base): `Q_ESQUEMA` = `123 137 9 25 51 75 3 8` (= `ESQ_DESPUES`), `Q_FUNC` = `33 0 1 <dueño>`, `Q_LIBRO` = `t`, `Q_INDICES` = `4 0`, `Q_PRIV_H9` **exactamente** igual a `PRIV_H9_ESPERADO`, `Q_APAGADOS` 0, `Q_COLA_H9` 0.

### 2.3 El banco del H9 (`ops/banco-h9/`), de punta a punta

`montar.sh ~/.cache/ironbody-banco-h9` (1 min 22 s): origen con el cierre del H8 sin confirmar en `wip(ultron-studio-h8)` (32 ficheros → PREVIO `5d9cfd9`) y el H9 en `wip(ultron-studio-h9)` (117 ficheros → ESPERADO `fde15b6`); «producción» migrada hasta el H8 con REVOKE (77 rutas del Studio, 18 tablas, voz `ELEVENLABS_NOT_CONFIGURED`); respaldo con 238 tablas. `arrancar` (valla :8504, API :8501), `sembrar --personas=5` (cuenta de smoke id 1 y 5 socios sintéticos `+57 300 00 000xx` / `@banco.invalid`), `valla` 7/7 OK.

`ensayar.sh todo` (registro completo en el scratchpad de la sesión; los del despliegue en `$B/root/h9-fase1-*.log`):

| Paso | Resultado | Detalle |
|---|---|---|
| 1 paso 0 (`--solo-comprobar`) | **EN VERDE** | 3 migraciones en el diff y pendientes, puerta de diff solo con lo permitido (console.php y api.php añaden; el controlador SSE), constante de envío **cerrada** en `backend/app/Services/Outreach/Run/OutreachSendGate.php`, esquema de partida `68 84 6 18 0 0 0 0`, F intacta, sin escritores de `identities`, 27 tareas / 0 de outreach, workers 13/0 |
| 2 despliegue | **5 desvíos**, todos de B9-run | código `fde15b6`, 15 variables añadidas, `ironbody-migrate` código 0 → esquema `123 137 9 25 51 75 3 8` al primer intento, **la 100003 también quedó aplicada** (4 índices válidos), funciones/apagados/F/libro/dueños/privilegios/filas en verde, cachés y colas OK. Desvíos: rutas `108 30 0 0 0 1` frente a `107 30 13 7 6 1` (la ejecución no existe: 0; y el Studio da 108 porque el canal SSE cuelga de su prefijo: **corregido**, ahora se espera constante + SSE); `auth:coverage --unmapped` con las 30 rutas de S9; `HEAD commercial-automation/status` y `/brakes` → 404; el 413 de `POST brakes` |
| 2b muleta `migrar` | OK | sin nada que migrar (el despliegue ya lo había hecho) |
| 3 `--indices --acepta-hora` | **NADA QUE HACER** | la 100003 ya registrada y 4 índices válidos / 0 inválidos |
| 4 `esquema` | **14/14 OK** | los 13 recuentos de L9-34 y los índices |
| 5 `smoke` | 15 OK · 2 FALLO · 2 OMITIDO; lanzador: 1 FALLO | ver 2.4 |
| 6 `cierre` | 2/2 OK | la valla no vio ningún host; nada local por ella |

### 2.4 El smoke, en el banco (API real con el H9)

```
OK  configuración del H9 de la fase 1: {"deployed":true,"enabled":false,"mode":"off","scheduler":false,"monitor":false,
    "assisted":false,"whatsapp":false,"voice":false,"classifier":false,"sends":false,"strategies":false,"publishing":false,"stream":false}
OK  el programador: 27 tareas, 0 del Studio, 0 de voz y 0 de outreach · INFO órdenes outreach:* desplegadas: outreach:monitor outreach:stop
OK  25 tablas ultron_studio_* y 8 outreach_* · OK login
OMITIDO la ejecución del H9 (GET commercial-automation/status y las otras 12) [HTTP 404: las rutas de B9-run no están en este despliegue]
FALLO GET strategies/catalog con la bandera maestra apagada → 409 strategies_disabled [HTTP 403 forbidden]
FALLO las otras 29 rutas del gobierno con sesión → 409 strategies_disabled [… 403 forbidden en todas: faltan las 30 entradas de AuthorizationMap]
OK  GET ultron-studio/stream con tique y la bandera apagada → 409 stream_disabled   (tique real de GET admin/stream-ticket)
OK  las 30 rutas del gobierno sin sesión → 401 (10 GET y 20 POST/PUT) · OK GET ultron-studio/stream sin tique → 401 admin_token_required
OK  cuerpos (63): sin claves del H6 ni del H9, sin secretos (12 huellas saladas), sin teléfonos, sin la conversación canaria · no-store en 65/65 · 0×429 · 0×5xx
OK  33 tablas iguales antes y después · ninguna fila nombra a la cuenta · incidentes 0 0 · colas 0/0 · valla 2 → 2
FALLO laravel*.log: 30 líneas ERROR durante el smoke        → las 30 son `auth:admin:unmapped_route` (una por ruta de S9 sin mapear)
OK  token tras desactivar → 403 · sesiones 0
```
Las dos pasadas contra el simulador HTTP (antes del banco) dieron 14 OK / 21 OK con el único fallo del propio simulador (no ponía `no-store` en el login).

### 2.5 El mando, en el banco

`alcance estado` → fase deducida **1 (todo apagado)**, constante de envío **cerrada** (`App\Services\Outreach\Run\OutreachSendGate`), nivel 0 / asistido / canales / horario / gobierno de S9 (ventana 07:00-19:00, canario ≥ 24 h y ≥ 3, `required`) / SSE (apagado) / base (0 frenos, 0 trabajos, 0 vínculos, 2/2 tablas) / órdenes (`outreach:monitor outreach:stop`) / programador `27 0 0 0` / workers `13 0` / fichero `600`.
`alcance gobierno on` → copia previa `env-pre-outreach-gobierno-on-…` (600), `ULTRON_STUDIO_STRATEGIES_ENABLED sustituida`, parsea, caché 600, `strategies: false → true`, resto sin cambios, fichero = copia salvo 1 clave, programador `27 0 0 0 → 27 0 0 0`, workers `[13 0] [13 0]`, ERROR `30 → 30`; **1 desvío**: `admin/commercial-automation/status` → 404 (B9-run). `alcance gobierno off` → `true → false`, mismo desvío. Las dos salidas imprimen la vuelta exacta, la funcional y la orden de PARADA GLOBAL.

## 3. El mando (`h9-outreach-scope.sh`)

Órdenes por niveles (§9.2 → §14): `estado` · `apagar` (nivel 0: 7 claves) · `parada-global --motivo=` (nivel 0b: `outreach:stop --all --reason=`, sin tocar el entorno; comprueba que queda un freno global abierto) · `sombra on` (fase 2) · `asistido on --cupo=N | off` (fase 3) · `canal whatsapp on --cupo=N --presupuesto=USD | off` (fases 4–5) · `canal voz on | off` (fase 6) · `programador | monitor | clasificador on|off` · `horario set HH:MM-HH:MM | legal` · `gobierno | publicar on|off` · `ventana set HH:MM-HH:MM | ninguna --orden-escrita=` · `canario set --horas=N --contactos=N` · `simulacion required | waived --orden-escrita=` · `stream on|off`.

Negativas (código 2, sin tocar nada): sin `config/outreach.php`, sin el bloque `strategies`, sin `config/ultron_studio_stream.php`; encender sin `outreach:monitor` y `outreach:stop` desplegados; `asistido on` con la maestra apagada; `canal … on` sin la constante **abierta por un commit `outreach(sends-open):`** (el prefijo literal de §14 fase 4), sin `MODE=live`, sin el código del canal (W9 `WhatsappTemplateChannel`, V9 un canal de voz) o, en voz, con las palancas del H8 cerradas; `horario set` fuera de [08:00, 19:00) (L9-32); `ventana ninguna` y `simulacion waived` sin `--orden-escrita` (su referencia queda en la salida y en la copia previa). Lo que no toca: motor comercial, Legacy, H4–H7, la voz del H8 (eso es de `h8-voice-scope.sh`), los frenos (nunca los suelta).

Supuestos que el ensayo **confirmó**: las claves de `config/outreach.php` (`enabled`, `mode`, `scheduler.enabled`, `monitor.enabled`, `assisted.{enabled,daily_cap}`, `whatsapp.{enabled,daily_cap,daily_budget_usd}`, `voice.enabled`, `classifier.enabled`, `own_hours`) y la forma de `outreach:stop`. Lo que sigue siendo supuesto: `TAREAS_PLANIFICADOR=2` y `TAREAS_MONITOR=1` (el bloque de §6) y los nombres `outreach:sweep` / `outreach:dispatch`; todo vive en un bloque único al principio del script.

## 4. El despliegue (`deploy-h9-backend-fase1.sh`)

Calcado del H8: `PREVIO=pendiente` (L9-34: producción se moverá con el cierre del H8; `--solo-comprobar` lo lee y se fija en un commit), paso 0 con los commits del hito por `tipo(ultron-studio-h9):` y **aborto si viaja un `outreach(sends-open):`**, puerta de diff de §9.3 (Marketing, Commercial, Automation, Jobs, Controllers/Api, Sms, Otp, Voice, Telephony, api/marketing/voice/console.php, config de marketing/meta/otp/commercial/automation/voice, StudioCatalog, composer; cruzan solo console.php y api.php **añadiendo** y el controlador SSE), constante de envío cerrada en `origin/main` antes del merge, migraciones ya aplicadas por otro (0/1/2/3 registradas) sin fallar, 15 variables, 55P03 con reintento, aserciones de esquema y privilegios uno a uno, rutas por la constante del código (`STUDIO_ROUTE_COUNT_WITH_STRATEGIES` + el canal SSE), 401/no-store/413, entorno = respaldo salvo las 15 claves, incidentes, instantáneas, ERROR.

**La 100003 como paso aparte (`--indices [--acepta-hora]`):** exige HEAD = ESPERADO y las 100001/100002 registradas; se niega de 06:00 a 22:00 de Bogotá salvo `--acepta-hora`; aborta con transacciones > 30 s; enseña tamaños y espacio libre; `lock_timeout=60s` (el envoltorio lo admite: `-c lock_timeout=N(ms|s)`); 3 intentos; comprueba 4 válidos / 0 inválidos.

**Limitación honesta:** `ironbody-migrate` no admite `--path` (solo `--pretend|--status|--check`), así que en el `ironbody-migrate` de la fase 1 la 100003 también está pendiente y **se aplica en la misma pasada si el tráfico lo permite** (en el banco pasó así). Si cae por lock_timeout, el despliegue sigue (son índices de lectura), lo dice y deja la orden del paso aparte. La separación dura exige `--solo=<migración>` en el migrador (Q-1); mientras, desplegar la fase 1 en la franja de poco tráfico (como el H8, 07:03 de Bogotá) la cumple de hecho.

## 5. El smoke (`smoke-h9.py`, `smoke-run-h9.sh`)

Política «sin filas positivas» del H8. Fase 1: las 30 rutas de S9 sin sesión → 401; con sesión y la maestra apagada → **409 `strategies_disabled` en las 30, también las lecturas**; las 13 de B9-run (status con `mode` off|shadow y la constante cerrada; listas vacías; inventados 404; `POST brakes {}` → 422, nunca 2xx; 404 → bloque OMITIDO); canal SSE con tique real → 409 `stream_disabled`, sin tique 401|409; presupuestos de PERF9 (`ops/perf-h9/presupuestos-h9.json`, mismo formato que PERF8; sin él, OMITIDO explícito; D9-16: historial p95 < 300 ms, métricas p95 < 500 ms); fugas del H6 más `subject_key`, `member_id`, documento, correo y cuerpo de mensaje (P9-9). Lanzador: 13 banderas apagadas (`sends`/`stream` pueden faltar → AVISO, nunca `true`), programador con 0 tareas outreach:*, las 33 tablas exactas antes/después, incidentes `ultron_studio`+`outreach`, colas, `laravel*.log` y `outreach*.log`, valla o vigía de sockets hacia Meta/ElevenLabs/Twilio.

Fase 2 (`SMOKE_PHASE=2`): además una pasada `outreach:sweep` + `outreach:dispatch` (`SMOKE_SWEEP_CMD`/`SMOKE_DISPATCH_CMD`) y la prueba de **sin envíos**: `marketing_messages`, `voice_calls`, `voice_call_events` iguales; colas sin trabajos nuevos; 0 `outreach_jobs` en modo `live`; las 25 del Studio iguales; valla igual; 0 ERROR. Sin las órdenes → OMITIDO (código 3).

`SMOKE_ALLOW_MISSING_EXEC=1` existe solo para el banco antes de B9-run (relaja la lectura de las banderas de la ejecución; sale como AVISO). En producción no se usa. En el banco de hoy no hizo falta: `config/outreach.php` ya está.

## 6. `routes/console.php`

Bloque propio del H9, como el de voz (E2-6), dentro de `/* */`: `if (outreach.scheduler.enabled) { outreach:sweep cada 10 min (barrido, etapas 1–5, tope 500); outreach:dispatch cada minuto (despacho, etapas 6–9, ≤ 50; la PARADA GLOBAL promete ≤ 60 s); if (outreach.monitor.enabled) outreach:monitor cada 10 min }`, todas con `withoutOverlapping(10)->onOneServer()`. Comentado hasta que B9-run nombre las órdenes: con el bloque vivo y las órdenes sin registrar, `schedule:run` fallaría al resolverlas. `schedule:list` del banco: 27 tareas, 0 de outreach (igual que antes).

## 7. Especificación de `outreach:suppress-subject` (supresión Ley 1581 del H9; lo implementa B9-run)

**Qué no es:** la supresión *comercial* (`opted_out` / `wrong_person`, D9-10), que B9-run ya tiene en `App\Services\Outreach\Run\SubjectSuppressions` leyendo el libro de resultados. Esto es la **supresión de datos personales** (habeas data), por redacción y nunca por borrado de filas: la pide una persona con radicado y la ejecuta OPS con el rol dueño, porque la aplicación (`iron_app`) no puede: `ra---` en decisiones y resultados, sin DELETE en ninguna, y los disparadores de DB9 paran los UPDATE.

**Base:** L9-5 (columna «una vez» `redacted_at` en `outreach_enrollments`, `outreach_jobs`, `outreach_decisions`, `outreach_outcomes`; el CHECK de `subject_key` admite la forma suprimida `'x:' || id` con `subject_ref = repeat('0', 64)` y sin chocar con los UNIQUE), L9-4 (`subject_ref` derivado con `outreach-subject/1`), L9-30 (`phone_ref` con `outreach-phone/1`; los contadores `outreach_budget_counters` con `scope = 'phone:<ref>'`), y el SQL de `informe-db.md` §13.2 (ensayado por DB9 en §8.5).

**Diseño en dos pasos (recomendado, Q-4):**

1. `php artisan outreach:suppress-subject --subject=<m:ID|l:ID> --radicado=<[A-Za-z0-9._-]{3,40}> [--json]` — **solo lectura, como la aplicación**. Resuelve la persona (`Facts\SubjectResolver`), deriva `subject_ref` y **todos** sus `phone_refs` (L9-30), cuenta por tabla (inscripciones, trabajos, decisiones, resultados, contadores `phone:<ref>`), y escribe en un fichero 600 de `storage/app/private/supresion/<radicado>.psql` (nunca por la salida estándar) las `\set` para psql: `clave`, `telefonos` (array de `phone:<ref>`), `radicado`, `owner`, `dry`. Imprime solo recuentos y la ruta. Si la persona tiene trabajos vivos (`planned|claimed|dispatching`) lo dice: primero el freno (`outreach:stop --strategy`/`--all`) o esperar a que venzan; redactar un trabajo vivo lo saca de la UNIQUE parcial `ojob_one_live` sin cancelarlo.
2. OPS, como root, con el fichero del paso 1 concatenado DELANTE de la sección H9 de `ops/supresion-1581.sql` (la escribo cuando el lead confirme el diseño): una transacción con `SET LOCAL ROLE :owner` y `lock_timeout 5 s` que (a) en seco (`:dry 1`, por defecto) cuenta y hace ROLLBACK; (b) con `:dry 0` desactiva **solo** los disparadores de UPDATE de las 4 tablas y los de DELETE de `outreach_budget_counters` (`ALTER TABLE … DISABLE TRIGGER USER`, transaccional), redacta con exactamente los UPDATE de §13.2 (`subject_key = 'x:' || id`, `subject_ref = repeat('0', 64)`, `member_id`/`lead_id`/`phone_ref` a NULL, `redacted_at = now()`), borra los contadores `phone:<ref>`, reactiva los disparadores, comprueba 0 filas con la clave y 0 disparadores apagados en `outreach_%` y `ultron_studio_%` (la consulta de `h9-schema-check.sql`) y deja UNA fila en el libro `ultron_studio_audit_events` (`system.personal_data_redacted`, con recuentos y radicado, nunca el dato; Q-5: el libro es de solo inserción y el CHECK de sujetos la admite como `system`). Si algo falla, todo se deshace.
3. `php artisan outreach:suppress-subject --subject=… --verify` — comprueba después, como la aplicación: 0 filas con la clave en las 4 tablas, 0 contadores de sus teléfonos, y que `SubjectSuppressions::isSuppressed` sigue respondiendo (la redacción no debe convertir a una persona que optó por no recibir en una «sin historial»: Q-6 para SEC9/B9-run, porque el `opted_out` redactado deja de encontrarse por `subject_key`).

**Lo que la redacción NO toca:** la cadena de publicaciones del Studio, las decisiones de otras personas, `marketing_*` y `commercial_*` (fuera del H9: su supresión es la del runbook del H7, `ops/supresion-1581.sql`).

**Runbook (para DOC9):** respaldo fresco antes (las copias de seguridad siguen llevando el dato hasta que caducan); `log_statement` en `none` o `ddl`; el fichero de `\set` se destruye con `shred -u` al terminar; una solicitud = una orden; y la nota de L9-4: **rotar la clave de la aplicación deja obsoletos todos los `subject_ref` y `phone_ref`** → rederivar en lote con una orden propia (`outreach:rederive-refs`, B9-run, pendiente de diseño), nunca a mano.

**Ensayo futuro (banco del H9, fase 2):** `sembrar --personas=N` → una pasada en sombra que inscriba a una persona sintética (filas en las 4 tablas y un contador `phone:`) → paso 1 (recuentos > 0) → paso 2 en seco → paso 2 real como `h5_owner` → `ensayar.sh esquema` (`disparadores_apagados = 0`) → paso 3 (`--verify` 0 filas) → el smoke de fase 2 sigue en verde (nada escribe fuera de la sombra).

## 8. Hallazgos para otros dueños (pendientes, con dueño y momento)

| # | Hallazgo | Dueño | Cuándo |
|---|---|---|---|
| H-1 | Las 30 rutas de S9 responden **403 `forbidden`** con sesión y cada petición deja un ERROR `auth:admin:unmapped_route`: faltan las 30 entradas de `AuthorizationMap::OVERRIDES` (`informe-studio.md` §2 las trae literales). Hoy `auth:coverage --unmapped` lista exactamente esas 30 | B9-run (dueño de `app/Support/Access/**`) | antes de la puerta 2 |
| H-2 | Las 13 rutas de la ejecución (`routes/commercial-automation.php`, montaje en `bootstrap/app.php`, tope de 64 KB, `no-store`): el despliegue espera `13 7 6` y 401/413; el smoke las deja en OMITIDO mientras den 404 | B9-run | puerta 2 |
| H-3 | Nombres del barrido y el despacho (`outreach:sweep`, `outreach:dispatch` son propuesta mía) → descomentar el bloque de `routes/console.php` y fijar `TAREAS_*` del mando y `SMOKE_EXPECT_OUTREACH_TASKS` | B9-run → OPS9 | puerta 2 |
| H-4 | `GET status` de la ejecución: el smoke busca `mode`, `enabled` y `sendsEnabledInCode` de forma tolerante; cuando B9-run fije el DTO, cerrarlo a claves exactas | B9-run → OPS9 | puerta 2 |
| H-5 | `ops/perf-h9/presupuestos-h9.json` (formato de PERF8; rutas `/admin/ultron-studio/strategies…` y `/admin/commercial-automation/…`) | PERF9 | puerta 3 |
| H-6 | `PREVIO` del despliegue: leerlo en producción con `--solo-comprobar` tras confirmar el cierre del H8 (L9-34) y fijarlo en un commit | lead + devops | antes de la fase 1 |
| H-7 | El árbol compartido mezcla el cierre del H8 y el H9 sin confirmar: el banco lo separa por rutas (`comun.sh::es_h9`, 32 + 117 ficheros) y los ficheros tocados por los dos (`config/ultron_studio.php`, `StudioAuditEvent.php`, `UltronStudioVoiceController.php`, `routes/ultron-studio.php`) van con el H9. En el repo lo separa el orden de commits de L9-34 | lead | al confirmar |
| H-8 | La sección H9 de `ops/supresion-1581.sql` y el runbook (§7) | OPS9 (tras Q-4/Q-5) → DOC9 | antes de la fase 2 |
| H-9 | DEPLOYMENT_PLAN y ROLLBACK del H9 (§4 y la cabecera del despliegue tienen el texto), `ops/README.md` | DOC9 | puerta 4 |

## 9. Q-n para el lead

- **Q-1** ¿Añadimos `--solo=<migración>` a `ironbody-migrate-propuesta.sh` (fichero de OPS7) para separar de verdad la 100003 de la fase 1, o basta con la franja de poco tráfico (como el H8)? Sin ello, la 100003 viaja en el mismo `ironbody-migrate` cuando el tráfico lo permite (en el banco entró a la primera).
- **Q-2** Nombres de las órdenes del barrido y el despacho (hoy `outreach:sweep` y `outreach:dispatch` en el bloque comentado).
- **Q-3** Con la maestra apagada, ¿`GET commercial-automation/status` y las listas responden 200 (observabilidad siempre) o 409? El smoke admite ambas; lo cierro a una cuando B9-run lo fije.
- **Q-4** Supresión 1581 (§7): ¿dos pasos (la orden solo lee y prepara; OPS redacta como dueño con psql) o una orden con conexión propia del dueño? Recomiendo los dos pasos: el rol de la aplicación no puede ni debe redactar.
- **Q-5** ¿La redacción deja una fila `system.personal_data_redacted` en el libro del Studio (como la del H7) o un registro propio en `outreach_%`? El libro ya es de solo inserción y admite `system`.
- **Q-6** (con SEC9/B9-run) Tras redactar, el `opted_out` de esa persona deja de encontrarse por `subject_key`: ¿se conserva la supresión comercial por `phone_ref`/`subject_ref` antes de redactar, o se acepta que una persona suprimida ya no está en ningún universo (nunca se la inscribe porque sus datos tampoco están en `users`/`marketing_leads` tras SU supresión)?
- **Q-7** `apagar` (nivel 0) pone 7 claves a false/off y deja topes y clasificador como estén; ¿quieres que también ponga `CLASSIFIER_ENABLED=false` y los topes a 0?
- **Q-8** El paso aparte se niega de 06:00 a 22:00 de Bogotá salvo `--acepta-hora`: ¿es la franja que quieres?

## 10. Reproducir

```bash
cd Iron-Body/backend
bash -n docs/ultron-studio/ops/{h9-outreach-scope.sh,deploy-h9-backend-fase1.sh,smoke-run-h9.sh} docs/ultron-studio/ops/banco-h9/*.sh
python3 -m py_compile docs/ultron-studio/ops/smoke-h9.py
PGGSSENCMODE=disable psql -X -v ON_ERROR_STOP=1 -v owner=ultron_studio_h9_owner -v app=ultron_studio_h9_app -x -h 127.0.0.1 -p 55432 -U ironbody -d ultron_studio_h9_db_roles < docs/ultron-studio/ops/h9-schema-check.sql
B=${XDG_CACHE_HOME:-$HOME/.cache}/ironbody-banco-h9
bash docs/ultron-studio/ops/banco-h9/montar.sh "$B" && bash docs/ultron-studio/ops/banco-h9/ensayar.sh "$B" arrancar \
  && bash docs/ultron-studio/ops/banco-h9/sembrar.sh "$B" --personas=5 && bash docs/ultron-studio/ops/banco-h9/ensayar.sh "$B" valla \
  && bash docs/ultron-studio/ops/banco-h9/ensayar.sh "$B" todo; bash docs/ultron-studio/ops/banco-h9/ensayar.sh "$B" alcance estado
bash docs/ultron-studio/ops/banco-h9/ensayar.sh "$B" parar
```
El banco de este informe queda montado y parado en `~/.cache/ironbody-banco-h9` (registros del despliegue en `root/`).

---

## 11. OPS9-b (10-11-2026, 04:00–04:40 UTC): los guiones conocen el árbol integrado y el banco los ensaya en verde

Paquete abierto por L9-63 (M-2 de REV9, B-2 de SEC9, m-5 de REV9, R-3 de SEC9), cerrado en L9-67 (decisiones Q-9..Q-12) y completado con el re-ensayo de la puerta de L9-68 (H-15). Árbol `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h8-voice`, HEAD **`307ad51`** con el H9 integrado y sin confirmar (L9-59/L9-60). **Sin commit, nada en el VPS, sin red, sin credenciales.** Tocado: los guiones de `ops/` del H9, `ops/supresion-1581.sql`, el runbook nuevo `ops/ROTACION_APP_KEY_H9.md`, `ops/banco-h9/{comun.sh,README.md}` y esta sección; ni código, ni pruebas, ni el contrato. El árbol lleva además ficheros de `ops/` de otros agentes (`banco-h8/montar.sh` modificado, `banco-h8/{ayudante-ops8d.php,ensayar-ops8d.sh}`, `modelos-root/`, `nginx-h8-voz.conf.propuesta`, `supresion-1581-voz.propuesta.sql`): no son míos y no los toqué.

### 11.0 Cifras

| Qué | Cifra |
|---|---|
| `routes/console.php` frente a HEAD | `git diff --numstat HEAD -- routes/console.php` → **`48 0`** (solo añade: el bloque del programador; M-2a cerrado por el lead) |
| Rutas reales (`route:list`) | **121** bajo `api/admin/ultron-studio` = `StudioRouteCount::TOTAL` (77 + 30 + 1 + 13); `outreach/**` **13** (9 GET · 4 POST); `stream` 1; `commercial-automation` **0**; `Api/Admin/UltronStudio/` no existe; `routes/api.php` sin diff |
| Programador | apagado **0** tareas `outreach:*`; con `COMMERCIAL_AUTOMATION_SCHEDULER_ENABLED=true` **3** (`outreach:sweep`, `outreach:dispatch --inline`, `outreach:monitor`), con o sin el monitor: medido en el banco con el mando (27 0 0 0 → 30 0 0 3 → 30 0 0 3 → 27 0 0 0) |
| PREVIO | **`a608450`** (el despliegue 1b del H8, E16-30/E16-31); entre él y el HEAD del que nacerá la rama hay dos commits solo de `docs/` (`a921fbb`, `307ad51`) que el paso 0 lista como ajenos |
| Banco A (`PREVIO=HEAD`) | paso 0 EN VERDE · despliegue COMPLETADO (0 desvíos) · índices NADA QUE HACER (4 0) · esquema **14/14** · smoke **28 OK · 0 FALLO · 1 OMITIDO** (los presupuestos de PERF9) + lanzador 8 OK · cierre 2/2 · mando `estado`, `parada-global` APLICADO, `programador on`/`monitor on`/`apagar` APLICADOS. **Re-ensayo con la puerta de L9-68** (banco remontado desde el árbol de las 04:45 UTC): paso 0 EN VERDE · despliegue COMPLETADO · esquema 14/14 · smoke **27 OK · 0 FALLO · 1 OMITIDO** (`status` 200; **las otras 12 rutas → 409 `outreach_disabled`**) · `parada-global` APLICADO con la maestra apagada · cierre 2/2 |
| Banco B (`BANCO_PREVIO=a608450`) | paso 0 sin `--acepta-main` → código 3, `FALTA DECIDIR [307ad51,a921fbb]`; con `--acepta-main=a921fbb,307ad51` → **EN VERDE**; despliegue COMPLETADO (121 30 13 9 4 1 · 27 0 0 0 · 0 ERROR); esquema 14/14 |
| `supresion-1581.sql` | +276/−16; en seco contra la base de DB9: 0 columnas del H9 sin política; redacción REAL en el banco A: aborto 55000 con filas vivas, después **1/2/1/1 filas + 1 contador** en forma suprimida y 3 campos de `ultron_studio_strategies`, 174 disparadores activos antes y después, 2 filas en el libro, `outreach:suppress-subject` → 0 filas |
| Runbook | `ops/ROTACION_APP_KEY_H9.md`, 95 líneas |
| Evidencia | `ops/evidencia-h9/2026-11-10/ops9b/` (60 ficheros más `SHA256SUMS`, barrido de secretos 0) |

### 11.1 Qué cambió, fichero a fichero

**`deploy-h9-backend-fase1.sh`** (719 líneas): `PREVIO=a608450` con su nota (:81-88); puerta: `PERMITIDOS_EN_PUERTA` = `routes/console.php` + **`app/Jobs/Outreach/DispatchOutreachJob.php` como fichero exacto** (el trabajo del carril `commercial` de B9-run, L9-58, inerte hasta la fase en vivo; `app/Jobs` está en la puerta por los emisores hacia n8n y el H9 lo cruza con un solo fichero: sin esta admisión el paso 0 abortaba, primera pasada del banco), `routes/api.php` **fuera** de los permitidos y de `SOLO_ANADEN` (intocable: B-2), `SOLO_ANADEN` = solo `console.php`; `rutas()` cuenta la ejecución bajo `/api/admin/ultron-studio/outreach/` y el esperado es **`121 30 13 9 4 1`** por `StudioRouteCount::TOTAL` del código desplegado (`leer_h9` lee `studio_routes_total`; `RUTAS_EJECUCION_GET=9`, `RUTAS_EJECUCION_POST=4`, `RUTAS_STREAM=1`); sanidad: `HEAD admin/ultron-studio/outreach/{status,brakes}` → 401 + `no-store`, 413 a `POST …/outreach/brakes`; cabecera y comentarios (ESQ_ANTES = a608450, hint `a608450..$ACTUAL`).

**`smoke-h9.py`** (715): `C = '/admin/ultron-studio/outreach'`; `RUTA_PRESUPUESTO` solo admite `strategies`/`outreach` bajo el Studio; el bloque de la ejecución se cierra a las claves exactas del DTO (H-4): `data.config.{mode,enabled,sendsEnabledInCode,scheduler,monitor,channels.*.{enabled,live}}`, `data.brakes.open`, `data.chain.head.verified`; **un 404 de `GET status` es FALLO** (las 13 viajan en la fase 1), nunca OMITIDO; `people/{ref}/timeline` y `assisted/queue` ya no admiten 404; las listas paginadas (`enrollments`, `jobs`: `data.data`) y `brakes` (`data`) se comprueban vacías en la fase 1 (segunda corrección de la primera pasada). **H-15, cerrado por L9-68:** en la primera pasada la ejecución no tenía puerta por bandera (`OutreachException::disabled()` sin llamadores: 200/404/422 con la maestra apagada); el lead implementó `requireEnabled()` tras la llave en los dos controladores (antes de validar y de buscar nada) y el smoke exige ahora, en la fase 1, **409 `outreach_disabled` en las 12 rutas distintas de `status`** (también las lecturas, los cuerpos vacíos y los uuids inventados; `status` 200 siempre) y, con la maestra encendida, las respuestas de antes (listas 200, inventados 404, cuerpos vacíos 422); en los presupuestos un 409 `outreach_disabled` queda OMITIDO como el `strategies_disabled`. El mando lo dice en `estado` (nivel 0), en el aviso de `apagar` y en el de `sombra on`.

**`smoke-run-h9.sh`** (349): el despacho de la fase 2 es **`outreach:dispatch --inline`** (L9-58: sin `--inline` encolaría en el carril `commercial` y, sin consumidor, lo reclamado quedaría `claimed`); la validación admite `outreach:<nombre> [--opción…]`, la existencia se mira por el nombre y la orden se ejecuta con sus opciones.

**`h9-outreach-scope.sh`** (690): `TAREAS_PROGRAMADOR=3` (sustituye a `TAREAS_PLANIFICADOR=2` + `TAREAS_MONITOR=1`): el esperado del programador depende solo de la bandera del programador (L9-60c: el monitor va plano); el 401 de sanidad en `admin/ultron-studio/outreach/status`; cabecera.

**`banco-h9/comun.sh`**: `es_h9` reconoce `StudioRouteCount.php`, `UltronStudioOutreach*Controller.php`, `UltronStudioStreamController.php` y `app/Jobs/Outreach/*` (antes irían al commit del H8: la «producción» del banco habría llevado código del H9) y deja de llamar H9 a `routes/api.php`, `routes/commercial-automation.php` y `Api/Admin/UltronStudio/*`; con el árbol integrado **N8 = 0, N9 = 154**: la «producción» del banco es HEAD limpio. `banco-h9/README.md`: nota de que las dos muletas de OPS9-a ya no se activan.

**`supresion-1581.sql`** (+276/−16, ahora 763 líneas), sin cambiar la forma de uso: (A) el bloque de texto conoce las 7 tablas del gobierno (política +26 columnas, de ellas 4 solo se informan: `content_json` de plantillas y versiones, `result_json`, `effective_json`), la autoría del personal en las 7 y en `outreach_brakes/jobs/outcomes` (+14 entradas) y 12 nombres neutros más; **selección de disparadores por columna** (se apagan solo los `BEFORE UPDATE` que saltarían: los de toda la fila o los que nombran una columna que se redacta; los `ultron_studio_chk_*` del H9 solo miran ids y fechas y no se tocan), `outreach_forbid` admitido como guarda conocida, el recuento de activos cubre `outreach_%`, y con solo `:subject` el bloque A cede el paso; (B) un bloque nuevo **por persona** (`:subject` = `m:<id>`|`l:<id>`, `:phone_refs` opcional): cuenta, aborta con trabajos vivos o inscripciones abiertas (la aplicación los para antes con `outreach:suppress-subject --apply`), toma los seudónimos de teléfono de sus trabajos antes de redactar, deja las filas en la forma suprimida de L9-5 (`x:<id>`, 64 ceros, sin socio ni lead, `phone_ref` NULL, `redacted_at`) y borra los contadores `phone:`, con los disparadores que saltarían apagados solo en la transacción, comprueba 0 filas y los mismos disparadores activos, y deja su fila `system.personal_data_redacted` en el libro (sin la clave ni los seudónimos). Es el diseño de §7 de OPS9-a con las decisiones ya tomadas por el código de B9-run (la orden existe y es de solo lectura/`--apply`).

**`ROTACION_APP_KEY_H9.md`** (nuevo): qué deriva de `APP_KEY` en el H9 (`subject_ref` `outreach-subject/1`, `phone_ref` `outreach-phone/1`, la firma de la cadena `ultron-studio-strategy-chain`), qué le pasa a cada pieza al rotar, lo que ya dependía antes (H5, H6/H7, H8 y fuera del Studio), lo que `APP_PREVIOUS_KEYS` cubre (solo `Crypt`) y lo que no (ningún HMAC), la regla, el procedimiento si no hay más remedio y los pendientes con dueño. **Hallazgo central:** la cadena de estrategias es **global** (`StudioStrategyPublications::head()` = el mayor `seq` de todas; `OutreachPublishedStrategies::headVerified()`), así que tras rotar la cabeza queda `signature_mismatch` para siempre, el monitor abre el freno global `chain_unreadable` en cada pasada y publicar da 409 `chain_unreadable` **también para estrategias nuevas**: sin una herramienta de re-anclaje por época de clave (que no existe y sería una enmienda del contrato), rotar con filas en `ultron_studio_strategy_publications` cierra el H9 sin vuelta atrás por software. La ventana segura es la fase 1 (nada publicado). `subject_ref` se rederivaría con `outreach:rederive-refs` (no existe; B9-run); la supresión 1581 no depende de la clave (va por `subject_key`); los contadores por teléfono son por día.

### 11.2 Banco A: la secuencia completa sobre el árbol integrado (`evidencia-h9/2026-11-10/ops9b/banco-a/`)

`montar.sh` (14 s; origen 307ad51 + 154 ficheros del H9 en `wip(ultron-studio-h9)` d6d9d7f; **ningún** commit `wip(ultron-studio-h8)`: la «producción» es HEAD), `arrancar`, `sembrar --personas=5`, `valla` 7/7.

| Paso | Resultado | Detalle |
|---|---|---|
| paso 0 (`--solo-comprobar`) | **EN VERDE** | constante cerrada en `origin/main` (`Run/OutreachSendGate.php`); 3 migraciones en el diff y pendientes; puerta `[app/Jobs/Outreach/DispatchOutreachJob.php routes/console.php]`, console.php solo añade; esquema de partida `68 84 6 18 0 0 0 0`; rutas `77 0 0 0 0 0`; programador 27 0 0 0; workers 13/0; H9 antes del merge `deployed:false` |
| despliegue | **COMPLETADO, 0 desvíos** | 15 variables; esquema `123 137 9 25 51 75 3 8` al primer intento y la 100003 en la misma pasada (4 índices válidos); funciones `33 0 1`; privilegios de la aplicación exactos en las 15 tablas; 15 tablas vacías; **rutas `121 30 13 9 4 1` = esperado**; `auth:coverage --unmapped` vacío; configuración efectiva toda apagada con la constante cerrada; programador 27 0 0 0; workers 13/0 dos veces; `HEAD …/outreach/status` y `…/outreach/brakes` → 401 + `no-store`; stream sin tique 401; 70 000 bytes a `POST …/outreach/brakes` → 413 `payload_too_large`; OTP 403; `publication-check` 0; monitor del H7 `[ok]` → `[ok]`; entorno = respaldo salvo las 15 claves; 0 ERROR |
| `--indices --acepta-hora` | NADA QUE HACER | 100003 registrada, 4 0 |
| `esquema` | **14/14** | los 13 recuentos de L9-34 y los índices |
| `smoke` | **28 OK · 0 FALLO · 1 OMITIDO** (código 3) | la única OMITIDA es la de siempre (presupuestos de PERF9); la ejecución: `GET status` 200 con `mode off`, `enabled false`, `sendsEnabledInCode false`, canales apagados y sin `live`, programador y monitor apagados, 0 frenos, cabeza de la cadena verificada; **las 12 restantes**: listas 200 vacías, inventados 404, cuerpos vacíos 422, ningún 2xx en POST; las 30 de S9 → 409 `strategies_disabled` con sesión y 401 sin ella; stream 409/401; 88 cuerpos sin fugas; `no-store` 90/90; 0×429, 0×5xx; lanzador: 33 tablas iguales, incidentes 0 0, colas 0/0, 0 ERROR, valla 2 → 2, token 403, sesiones 0 |
| `cierre` | 2/2 | la valla no vio más hosts que sus sondas |
| mando | todos **APLICADOS** | `estado`: fase 1, constante cerrada, programador 27 0 0 0 «3 con el programador encendido», workers 13 0, fichero 600; `parada-global --motivo=…`: `outreach:stop --all` rc 0, frenos globales 0 → 1; `programador on`: 27 0 0 0 → **30 0 0 3** (esperado 30 0 0 3), 401 en `…/outreach/status`; `monitor on`: 30 0 0 3 → 30 0 0 3; `apagar`: → 27 0 0 0 |

La **primera pasada** (`banco-a-primera-pasada/`) sacó las dos correcciones de §11.1: el paso 0 abortaba por el trabajo de `app/Jobs/Outreach` en la puerta y el smoke daba 1 FALLO en «listas vacías» (paginación). Queda como traza.

**Re-ensayo con la puerta de L9-68** (`banco-a-L9-68/`, 04:45–04:47 UTC; banco remontado desde el árbol actual, ESPERADO 162876c): paso 0 EN VERDE; despliegue COMPLETADO (esquema `123 137 9 25 51 75 3 8` al primer intento, rutas `121 30 13 9 4 1`, programador 27 0 0 0, 0 ERROR); índices NADA QUE HACER; esquema 14/14; smoke **27 OK · 0 FALLO · 1 OMITIDO** (`GET status` 200 con `enabled false`, `mode off`, constante cerrada, canales apagados, cadena verificada; **las otras 12 → 409 `outreach_disabled` en todas**; sin sesión 13 → 401; S9 409/401; stream 409/401; 88 cuerpos sin fugas; `no-store` 90/90; lanzador 8 OK: 33 tablas iguales, 0 incidentes, 0 ERROR, valla 2 → 2); `estado` enseña «las 12 rutas de la ejecución salvo GET status responden 409 outreach_disabled (L9-47)»; `parada-global` APLICADO **con la maestra apagada** (frenos globales 0 → 1: la consola funciona siempre, L9-68); `apagar` → «nada que escribir»; cierre 2/2. Es una comprobación menos que la pasada anterior (28 → 27 OK) porque con la maestra apagada ya no hay listas que leer.

### 11.3 Banco B: la «producción» en `a608450`, el commit real (`banco-b/`)

`BANCO_PREVIO=a608450c` (base `ultron_studio_banco_h9_ops9b_prev`, :8511/:8514): el merge trae `a921fbb` y `307ad51` (solo `docs/`) más el H9. Paso 0 **sin** `--acepta-main` → código 3, `PENDIENTE DE DECISIÓN: 307ad51 … a921fbb …` y `FALTA DECIDIR los commits ajenos [307ad51,a921fbb]`, sin tocar nada; **con** `--acepta-main=a921fbb,307ad51` → `COMPROBACIONES PREVIAS EN VERDE`; el despliegue → COMPLETADO (HEAD 9cbf658 del banco; rutas 121 30 13 9 4 1; 27 0 0 0; 13/0; 0 ERROR); `esquema` 14/14. Es exactamente lo que verá el servidor: o el propietario acepta esos dos commits con la bandera (como el `7c84cb5` del H8) o la rama del H9 nace sin ellos.

### 11.4 Supresión 1581: ensayos (`sql/`)

- En seco contra la base de DB9 (`ultron_studio_h9_db_roles`, READ ONLY): con `term`, **ninguna columna de las 7 tablas del gobierno sin política**; los 32 avisos que quedan son todos de `ultron_studio_voice_{agents,lab_sessions,profiles,publications}` (H8; ver H-11); con `subject` recorre el bloque B con 0 filas; `phone_refs=zz` y `subject=x:1` abortan con 22023.
- Redacción REAL en el banco A (`fixture-1581.sh`: filas sintéticas en las 5 tablas y una estrategia, insertadas como superusuario con los disparadores apagados solo durante la inserción, y el SQL del repo como `h5_owner`): en seco cuenta 1/2/1/1 + 1 contador y avisa de lo vivo; con `dry 0` y filas vivas **aborta (55000) sin cambiar nada** (0 disparadores apagados); tras parar lo vivo, la redacción real deja **1/2/1/1 filas en forma suprimida** (la CHECK de DB9 la acepta), **0 contadores**, apaga exactamente `oenr_closed_is_final`, `oenr_forbid_immutable_update`, `ojob_forbid_immutable_update`, `odec_forbid_update`, `oout_forbid_update`, `obud_forbid_delete` y los reactiva; el bloque A sobre `ultron_studio_strategies` redacta `name`, `draft_json` (ida y vuelta JSON) y `created_by_name` apagando solo `usst_archived_is_final`, `usst_draft_revision_next`, `usst_forbid_immutable_update` (no el `ultron_studio_chk_usst_archive_without_live_versions`); 174 disparadores activos antes y después; 2 filas en el libro; `php artisan outreach:suppress-subject m:<id> --json` → 0 filas, 0 vivos, 0 seudónimos.

### 11.5 Hallazgos para otros dueños

| # | Hallazgo | Dueño | Cuándo |
|---|---|---|---|
| H-10 | `OutreachPersonalDataGuardTest::test_la_supresion_1581_del_h9…` caía por diseño al nombrar el guion las 4 tablas. **Cerrado por el lead (L9-67): `SUPPRESSION_PENDING_OPS9 = []`, guarda 4/4.** No toqué pruebas | lead | hecho |
| H-11 | Las 4 tablas `ultron_studio_voice_*` del H8 no tienen política en `supresion-1581.sql` (32 avisos «columna sin política» en cada pasada). Hay un `supresion-1581-voz.propuesta.sql` sin seguimiento de otro agente: no lo toqué | OPS8 → lead | antes de una solicitud real |
| H-12 | `Q_COLA_H9` (despliegue) y `Q_COLAS` (lanzador) cuentan `queue LIKE 'outreach%'`, pero el carril del H9 es `commercial` (`config/outreach.php` `queue.lane`, compartido con el motor): con `--inline` nada se encola; en la fase en vivo habría que vigilar `commercial` sin confundirlo con los trabajos del motor | OPS9 (decisión del lead) | antes de la fase en vivo |
| H-13 | El despliegue real desde `a608450` lista `a921fbb` y `307ad51` como ajenos: `--acepta-main=a921fbb,307ad51` por orden del propietario, o la rama del H9 sin ellos | lead / propietario | antes de la fase 1 |
| H-14 | Rotación de `APP_KEY`: cadena global → sin re-anclaje no hay vuelta; `outreach:rederive-refs` no existe (R-1/R-2 del runbook) | lead → S9 + B9-run + DB9 + SEC9 | antes de que haga falta |
| H-15 | La API no emitía el 409 `outreach_disabled` de L9-47 (`OutreachException::disabled()` sin llamadores). **Cerrado por el lead (L9-68):** `requireEnabled()` tras la llave en `UltronStudioOutreachController` y `UltronStudioOutreachAssistedController`; `status` 200 siempre; `OutreachApiTest` 5/5. Smoke y mando adaptados (§11.1) y re-ensayados en el banco (§11.2) | lead | hecho |
| H-16 | El paso 0 admite `app/Jobs/Outreach/DispatchOutreachJob.php` por su ruta exacta: si B9-run añade otro trabajo bajo `app/Jobs/Outreach`, el despliegue aborta hasta que OPS9 lo admita a mano (deliberado) | B9-run → OPS9 | si cambia |

### 11.6 Reproducir

```bash
cd Iron-Body/backend
git diff --numstat HEAD -- routes/console.php                                   # 48 0
bash -n docs/ultron-studio/ops/{deploy-h9-backend-fase1.sh,smoke-run-h9.sh,h9-outreach-scope.sh} docs/ultron-studio/ops/banco-h9/*.sh
python3 -m py_compile docs/ultron-studio/ops/smoke-h9.py
B=${XDG_CACHE_HOME:-$HOME/.cache}/ironbody-banco-h9; O=docs/ultron-studio/ops/banco-h9
bash $O/ensayar.sh "$B" parar; rm -rf "$B"; bash $O/montar.sh "$B" && bash $O/ensayar.sh "$B" arrancar && bash $O/sembrar.sh "$B" --personas=5 \
  && bash $O/ensayar.sh "$B" valla && bash $O/ensayar.sh "$B" todo && bash $O/ensayar.sh "$B" alcance estado \
  && bash $O/ensayar.sh "$B" alcance parada-global --motivo="ensayo" && bash $O/ensayar.sh "$B" alcance programador on && bash $O/ensayar.sh "$B" alcance apagar
BANCO_DB=ultron_studio_banco_h9_ops9b_prev BANCO_PORT=8511 BANCO_PUERTO_VALLA=8514 BANCO_PREVIO=a608450c bash $O/montar.sh "$B-prev" \
  && bash $O/ensayar.sh "$B-prev" arrancar && bash $O/ensayar.sh "$B-prev" desplegar --solo-comprobar; echo "código $? (3 = FALTA DECIDIR)" \
  ; bash $O/ensayar.sh "$B-prev" desplegar --solo-comprobar --acepta-main=a921fbb,307ad51
bash docs/ultron-studio/ops/evidencia-h9/2026-11-10/ops9b/sql/fixture-1581.sh "$B" ultron_studio_banco_h9_ops <id de la cuenta de smoke> 8504   # solo en el banco
```
Los dos bancos quedan montados y parados (`~/.cache/ironbody-banco-h9`, `~/.cache/ironbody-banco-h9-prev`).

### 11.7 Q-n para el lead (decididas en L9-67)

- **Q-9** La admisión de `app/Jobs/Outreach/DispatchOutreachJob.php` en la puerta de diff (fichero exacto). **Decidido: sí, como enmienda de §9.3.**
- **Q-10** La autoría del personal en `outreach_brakes/jobs/outcomes` en el bloque A de la supresión (3 tablas, por `admin_id`; patrón del libro, m-6 de REV9). **Decidido: se mantiene.**
- **Q-11** Los dos commits de documentación del H8 (`a921fbb`, `307ad51`). **Decidido: la rama del H9 nace de `main` con ellos y en el despliegue real se pasan con `--acepta-main=a921fbb,307ad51` (decisión del propietario).** Ensayado en el banco B (§11.3).
- **Q-12** Los dos pendientes del runbook de rotación (re-anclaje de la cadena por época de clave y `outreach:rederive-refs`). **Decidido: riesgo aceptado y registrado hasta la fase 2.**

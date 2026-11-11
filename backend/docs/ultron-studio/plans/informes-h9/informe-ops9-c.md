# Hito 9 · OPS9-c · informe del `devops` del cierre (mando, G-9 y SC-8)

**Fecha:** 11-11-2026, de 00:30 a 02:30 UTC aprox. **Árbol:** `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h9`, HEAD `d701b0c8`, con mucho trabajo sin commit de otros agentes (que siguió cambiando mientras trabajaba: §2.6). CRM: `_worktrees/ultron-h8/Iron-Body_Front`, HEAD `9572426`, leído sin tocarlo.

**Entorno: NINGUNO de producción.** Todo se preparó en el repositorio y se ensayó en un banco local desechable (`~/.cache/ironbody-banco-h9-ops9c`, PostgreSQL 14 en `127.0.0.1:55432`, base `ultron_studio_banco_h9_ops9c`, API en `:8521`, valla en `:8524`, CRM en `:8525`). Sin ssh, sin copias a `/root`, sin `config:cache` ni reinicios en el servidor, sin despliegues, sin rotaciones, sin commit ni push. No toqué los guiones del H8 ni `ops/README.md` (son de ops8-e) ni los documentos de DOC9 o del lead (§6 lista lo que ha quedado desfasado en ellos).

## 0. Resumen

| Encargo | Fichero | Estado |
|---|---|---|
| 1 · El mando | `ops/h9-outreach-scope.sh` (+359/−92) | Hecho y ensayado en el banco, también el camino que escribe de los dos canales (vía abierta SOLO en la copia del banco) |
| 1 · P9-24 | orden nueva `eventos medir · on · off` del mismo mando | Hecho y ensayado; §1.2 dice qué escribe y cuánto pesa |
| 2 · G-9, backend | `ops/deploy-h9-backend-cierre.sh` (nuevo, 800 líneas) | Hecho; `--solo-comprobar`, despliegue real y vuelta atrás ensayados en dos bancos (§2.5, §2.7) |
| 2 · G-9, CRM | `ops/deploy-h9-crm-cierre.sh` (nuevo, 152 líneas) | Hecho; build sellado, respaldo, `rsync` sin `--delete` y su comprobación ensayados contra un «servidor» local |
| 2 · El smoke del H9 de la ola | `ops/smoke-run-h9.sh` (+15/−9), `ops/smoke-h9.py` (+10/−2) | **Imprescindibles:** sin ellos el smoke del H9 FALLA en producción tras la ola (§2.4) |
| 2 · El banco de la ola | `ops/banco-h9/ola.sh` (nuevo) | Reproduce la producción por su mismo camino (H8 cerrado → fase 1 con su guion → la ola); también el CRM y la vía abierta (solo en la copia) |
| Evidencia | `ops/evidencia-h9/2026-11-11/ops9c/` (62 registros y `SHA256SUMS`) | Los números de las tablas de abajo son los nombres de sus registros |
| 3 · SC-8 | `ops/nginx-h9-vales.conf.propuesta` (nuevo) | Propuesta, NO aplicada; §3 |
| Este informe | `plans/informes-h9/informe-ops9-c.md` | — |

## 1. Encargo 1 · El mando (`h9-outreach-scope.sh`)

### 1.1 Qué cambió, orden por orden

| Orden | Antes | Ahora | Origen |
|---|---|---|---|
| `apagar` | 7 claves | **13**: las 7 de siempre + el clasificador apagado + los 5 topes a 0 (cupo del asistido, cupo y presupuesto de WhatsApp, cupo y techo de la voz del H9). Los precios de WhatsApp y la reserva por llamada NO se tocan: son tarifas, no topes, y con cupo y techo a 0 el canal ya es `budget_zero`. Solo escribe las claves que el código desplegado lee (un mando de otro commit no deja claves huérfanas). Avisa si el día tiene reservas en vivo (abajo) | L9-52 Q-7 |
| `horario set` | una franja `HH:MM-HH:MM` en [08:00, 19:00] | además `--sabado=HH:MM-HH:MM` en [08:00, 15:00] o `--sabado=cerrado` → escribe `semana;sábado` o `semana;-`, la forma que ya lee `OutreachConfig::ownHours()`. Niega un `;` en la línea de órdenes (sin comillas el shell lo corta). La vuelta funcional reconstruye el `--sabado=` del valor anterior | P9-17, doc9 #6 |
| `canal whatsapp on` | `--cupo --presupuesto` | además `--precio-marketing` y `--precio-utilidad` (> 0 y ≤ 1 US$, hasta 6 decimales). **Un precio 0 se niega**: desde el cierre de B9, `PolicyLadder` N4 da `budget_zero` con coste por envío 0 (`PolicyLadder.php`, `$p['costMicros'] <= 0`), así que el canal quedaría encendido y negado. Niega también si el presupuesto no alcanza para un mensaje del precio mayor, y avisa si frena antes que el cupo | encargo; doc9 #7 |
| `canal whatsapp off` | igual (bandera, cupo y presupuesto) | igual; avisa del `budget_breach` si hay reservas en vivo del día | — |
| `canal voz on` | sin opciones | `--cupo` (1–20 y ≤ `TWILIO_VOICE_DAILY_CALL_CAP` del H8), `--presupuesto` (0.01–999 y ≤ `VOICE_DAILY_BUDGET_USD` del H8), `--reserva` (≥ la reserva del H8 de UNA llamada a móvil con la duración máxima, calculada por el propio `VoiceCostGuard`; ≤ el presupuesto). Avisa si la reserva no cubre una llamada a fijo o si el techo frena antes que el cupo | encargo; b9-cierre (G-3…G-6) |
| `canal voz off` | solo la bandera | bandera + cupo y techo a 0 (como WhatsApp) | — |
| `canal … on` (los dos) | clase cuyo nombre *contenga* `WhatsappTemplateChannel`/`Voice` | la clase EXACTA (`Run\Channels\WhatsappTemplateChannel`, `Run\Channels\VoiceStrategyChannel`) Y el canal declarado en `OutreachSendGate::LIVE_CHANNELS`. Además valida primero la forma de las opciones y después el estado | doc9, «comprobante débil» |
| `eventos medir · on · off` | — (P9-24 sin procedimiento) | nueva: §1.2 | P9-24, doc9 #2 |
| `estado` | — | muestra precios, cupo/techo/reserva de la voz del H9, clase y declaración de cada canal, el motor comercial (eventos, NBA, autonomía) y lo reservado hoy en vivo por canal | — |
| Comentario del programador | «agenda sus 3 tareas» | `$TAREAS_PROGRAMADOR` (5, sin tocar, como pidió el lead) | doc9 #10 |
| `uso()` | `sed -n '10,26p'` (se rompía al añadir órdenes) | lee el bloque «Uso:» de la cabecera | — |

**Avisos nuevos (no cambian el veredicto):** al dejar un cupo en 0 con reservas en vivo del día (`asistido off`, `canal … off`, `apagar`), el mando lo dice: la siguiente pasada del monitor con la maestra y el monitor encendidos abrirá `budget_breach` en ese canal. **Lo ensayé en el banco** (§1.4, caso 78): con 2 reservas en vivo y el cupo del asistido a 0, `outreach:monitor` abrió `channel(assisted) budget_breach` (freno del sistema). El RUNBOOK lo daba como *deducido del código*.

### 1.2 P9-24: qué escribe `COMMERCIAL_EVENTS_ENABLED` y cuánto pesa (leído del código)

**Qué enciende:**
- Los **8 observadores** de `app/Observers/Commercial/*` (registrados siempre en `CommercialEventServiceProvider`; cada uno mira la bandera al ejecutarse, así que surte efecto sin reiniciar FPM: el proceso web lee la caché en cada petición; los workers, tras el `queue:restart` del mando). Hechos: enlace de pago creado y sus cambios de estado (aprobado, rechazado, vencido, pendiente); membresía activada o renovada; socio enlazado a la app; prospecto creado, cualificado o que pide humano; cita creada o completada; conversación tomada por una persona; **primera entrada y los hitos de 10, 25, 50, 100, 200 y 365 visitas**; factura pedida, validada o rechazada.
- La pasada **horaria** de `commercial:scan` (ya programada siempre; hoy sale con un aviso): renovación en ventana (10 días), vencida ayer, inactividad ≥ 14 días con membresía vigente y enlaces de pago abandonados; ≤ 500 por familia y una fila por persona, familia y día.

**Qué escribe:** SOLO `commercial_events` (`CommercialEventRecorder`: 1 SELECT por `dedupe_key` + 1 INSERT por hecho nuevo, idempotente, nunca lanza dentro de la transacción del pago) y una línea `commercial.event.recorded` por hecho en el canal de registro. **Con `COMMERCIAL_NBA_ENABLED=false` no evalúa, no abre oportunidades, no encola trabajos y no contacta a nadie** (`queueEvaluation()` sale antes). Las filas quedan con `evaluated_at` NULL: si algún día se enciende NBA, `commercial:evaluate-pending` evaluaría las de los últimos 7 días (`--max-age-days=7`).

**Cuánto pesa (lo que hay que vigilar):**
- **El camino caliente de recepción:** `AttendanceCommercialObserver::created` hace, en CADA entrada y dentro de la petición que la registra, un `COUNT(DISTINCT date(captured_at))` de las entradas de esa persona. **`attendances` no tiene ningún índice que empiece por `member_id`** (las migraciones crean `user_id+captured_at`, `captured_at` y `access_via+captured_at`; lo comprobé en el catálogo del banco, que tiene el mismo esquema): cada entrada recorre la tabla. Con decenas de miles de filas son milisegundos, pero es la única pieza que añade trabajo a una petición de una persona. Si el tiempo de la entrada crece, el índice es de `database-specialist`.
- El volumen de filas: lo mide `eventos medir` en producción (no se puede saber desde aquí: depende de la actividad real).

**El procedimiento (en el mismo mando, con todas sus garantías: copia previa, escritura atómica, `config:cache` como www-data, comparación efectiva, workers, 401, ERROR):**
1. `bash /root/h9-outreach-scope.sh eventos medir` — **solo lectura**: banderas del motor; filas y tamaño de `commercial_events`; la pasada horaria contada EN SECO (`commercial:scan --dry-run` con la bandera puesta solo en la memoria de ese proceso de tinker; de su salida, que lleva ids, solo se toman los cuatro totales); la actividad de los últimos 7 días que vigilan los observadores (recuentos); el plan (EXPLAIN sin ANALYZE) de la consulta de recepción y si existe el índice.
2. `bash /root/h9-outreach-scope.sh eventos on --orden-escrita=<referencia>` — vuelve a medir, escribe SOLO `COMMERCIAL_EVENTS_ENABLED=true` y comprueba que en el motor cambió solo eso (NBA, autonomía, Legacy, H4–H8 y todo el H9, iguales).
3. En 1–2 h, `eventos medir` otra vez y el tiempo de la entrada en recepción.
- **Se niega** sin `--orden-escrita`, sin `config/commercial.php`, o con **NBA o autonomía encendidos** (P9-24 se decidió con el motor que detecta pero no decide: con NBA cada hecho encolaría una evaluación y abriría oportunidades; eso es otra decisión).
- **Vuelta atrás:** `eventos off` (funcional) o la exacta de la copia previa. Las filas se quedan (nunca se borran); las condiciones de eventos del H9 vuelven a `unknown` y, en sombra, N6 niega a todos (L9-26).
- `apagar` NO toca los eventos (P9-24 es del motor; lo dice su aviso).

### 1.3 Negativas y vueltas atrás

Todas las negativas salen con código 2 sin tocar nada; cada orden que escribe imprime la vuelta exacta (el fichero de la copia previa) y la funcional. La cabecera del mando lista las negativas; las nuevas: precios ausentes o 0, presupuesto que no cubre un mensaje, voz sin cupo/techo/reserva o fuera de los límites del H8, clase exacta o declaración ausentes, código desplegado que no lee las claves nuevas, sábado fuera de [08:00, 15:00), `;` en la orden, `eventos on` sin orden escrita o con NBA/autonomía.

### 1.4 Ensayos del mando en el banco (todo sobre la «producción» del banco ya con la ola desplegada)

| # | Orden | Resultado |
|---|---|---|
| 30 · 45 · 73 | `estado` | 0; las líneas nuevas salen bien (precios, voz del H9, clase y declaración, motor, reservas de hoy) |
| 31 · 32 | `eventos medir` | 0; «Seq Scan · índice que empiece por member_id: no» y el aviso |
| 33 | `eventos on` sin orden escrita | 2, NO SE APLICA |
| 34 | `eventos on --orden-escrita=P9-24/banco-ops9c` | 0, APLICADO: 1 clave; «commercial.events_enabled → true; NBA, autonomía, Legacy, H4–H7 y voz del H8 sin cambios»; programador 28 → 28; workers 13 0 ×2; 401/401; 0 ERROR |
| 35 | `eventos off` | 0, APLICADO |
| 36 | `eventos on …` con `COMMERCIAL_NBA_ENABLED=true` (cambio temporal en el entorno sintético, restaurado) | 2, NO SE APLICA por NBA |
| 40 | `horario set 09:00-18:00 --sabado=09:00-13:00` | 0, APLICADO: `own_hours: null → "09:00-18:00;09:00-13:00"`; vuelta funcional `horario set legal` |
| 41 | `horario set 09:00-18:00 --sabado=cerrado` | 0, APLICADO: `→ "09:00-18:00;-"`; vuelta funcional reconstruye `--sabado=09:00-13:00` |
| 42 · 43 · 44 | sábado 09:00-16:00 · `;` en la orden · semana 07:30-18:00 | 2, 2 y 2 |
| 46 | `horario set legal` | 0, APLICADO |
| — | `OutreachConfig::ownHours()` sobre los tres valores | `{"weekdays":[540,1080],"saturday":[540,780]}`, `saturday: null`, y la franja única para los dos tipos de día: la aplicación lee lo que escribe el mando |
| 50 · 51 · 53 · 54 | WhatsApp sin precios · precio 0 · voz sin reserva · voz cupo 21 | 2 en todos, por la forma de las opciones |
| 52 | WhatsApp completo con la constante cerrada | 2: «no está en modo live» (el estado se comprueba después de la forma) |
| 55 · 56 | `sombra on` → `apagar` | 0 y 0: `apagar` escribió **13 claves**; programador 33 0 0 5 → 28 0 0 0; «la copia previa salvo 13 clave(s)» |
| 57 | `canal voz off` con todo a 0 | 0, «nada que escribir» |
| **vía abierta** (`ola.sh via-abierta`: los commits `outreach(sends-open): banco…` y `voice(calls-open): banco…` en el ORIGEN DEL BANCO y las palancas del H8 en su entorno sintético; nunca en el repo) | | |
| 60 | `estado` | «constante de envío: abierta» (el prefijo del último commit del fichero lo reconoce) |
| 61 · 62 | `sombra on` · `asistido on --cupo=5` | 0 y 0 (modo `live`) |
| 63 | WhatsApp con presupuesto 0.01 y precio 0.0125 | 2: «no alcanza ni para un mensaje» |
| **64** | `canal whatsapp on --cupo=10 --presupuesto=0.10 --precio-marketing=0.0125 --precio-utilidad=0.0008` | **0, APLICADO: 5 claves**, la comparación con 4 decimales, «fase 4–5»; aviso «8 mensajes al día al precio mayor, menos que el cupo» (0.10 / 0.0125) |
| 65 · 66 · 67 · 68 | voz: reserva 0.50 · cupo 6 (H8: 5) · presupuesto 6.00 (H8: 5.00) · reserva > presupuesto | 2 en todos; la reserva del H8 calculada por `VoiceCostGuard`: **0.9605 US$** (móvil, 300 s, transferencia a fijo) |
| **69** | `canal voz on --cupo=3 --presupuesto=2.00 --reserva=0.97` | **0, APLICADO: 4 claves**, «fase 4–6»; avisos «menor que la reserva de un FIJO (1.1220 US$)» y «el techo deja 2 llamadas, menos que el cupo» |
| 70 · 71 · 72 | `canal whatsapp off` · `canal voz off` · `apagar` | 0, 0, 0 (APLICADO; tras `apagar` los precios y la reserva quedan, por diseño) |
| 74–79 | `sombra on`, `asistido on`, una reserva sembrada (2 unidades en vivo hoy), `asistido off`, `sombra on`, `outreach:monitor` a mano, `apagar` | el aviso de `budget_breach` sale en `asistido off`; **el monitor abrió `channel assisted budget_breach` (system)** |

Ningún desvío en ninguna orden que escribió; 0 ERROR en el registro en todas.

## 2. Encargo 2 · G-9: el despliegue de esta ola

### 2.1 Backend: `deploy-h9-backend-cierre.sh`

Derivado de `deploy-h9-backend-fase1.sh` (mismos pasos, mismas lecturas y los mismos cuidados de www-data, umask y cachés). Diferencias:

- **`PREVIO=ce7c150`** (lo que sirve producción, L9-76) y **`CRM_PREVIO=9572426`**.
- **Respaldo comprobado en el paso 0** (como el del H8): `api-commit.txt` = PREVIO, volcado de < 2 h con su `.sha256`, 700/600 de root, tantas tablas con datos como tablas tiene hoy la base (253; sin contar la de W9 en una reanudación) y el entorno = `env.backup`. El bloque del respaldo va en la cabecera.
- **Commits:** `outreach(sends-open):` y `voice(calls-open):` **abortan** (se despliegan aparte, con su orden escrita); los de la ola se reconocen por `tipo(ultron-studio-h9):` o `tipo(ultron-studio-h8):`; lo demás que traiga el merge exige `--acepta-main`.
- **Puerta de diff CERRADA:** todo fichero de `backend/` que cambie entre PREVIO y `origin/main` tiene que estar en una zona del Studio o de la ejecución (`Services/Outreach`, `Models/Outreach`, `Jobs/Outreach`, `Console/Commands/Outreach`, `Services/UltronStudio`, `Models/UltronStudio`, `Http/Controllers/Crm/UltronStudio`, `Console/Commands/UltronStudio`, `config/{outreach,ultron_studio,ultron_studio_stream}.php`, `routes/ultron-studio.php`), en `tests/` o `docs/` (no corren), en la lista de migraciones o en la **LISTA CERRADA** de fuera del Studio. Hoy, **35 ficheros**, cada uno con su dueño:
  - **W9:** `MarketingMessageDispatcher.php` (*), `WhatsappOutboxService.php` (también SC-10) y `OutreachTemplateMessage.php` (nuevo: el DTO de W-B, `informe-w9.md:85`; **no estaba en tu enumeración de Marketing**).
  - **V9 / Enmienda 17:** `Telephony/OutboundGate.php` (*).
  - **L9-82 (endurecimiento):** B-T4 `VoiceContacts.php`, `VoiceTelephonyStatus.php`, `VoiceDoctorCommand.php`, `VoiceContactController.php`, `config/voice.php`; B-T5 `VoiceWebhookHealth.php`, `VoiceWebhookHealthCommand.php`, `ElevenLabsAgentDeployer.php`, `VoiceAdapters.php`, `VoiceWebhookObserver.php`, `NullVoiceWebhookObserver.php`, `Dto/VoiceSecretList.php`, `Dto/VoiceWebhookList.php`; B-T16 `QueueHealthCommand.php`, `QueueHealthService.php`, `ChannelHealthDetector.php`, `config/queue.php`; B-T17 `CheckSecretExposure.php`.
  - **pm2-voz (P-M2):** `VoiceTranscriptAllowlist.php`, `VoiceTranscriptGuard.php` (solo comentario).
  - **P9-25 y arreglos de seguridad:** `StreamTicketController.php`, `EnsureAdminAuth.php`, `SecurityHeaders.php`, `StreamTicketService.php` (SC-6, SC-7), `PruneStreamTicketsCommand.php` (SC-7, nuevo), `Models/Voice/VoiceBrakeEntry.php` (SC-3) y `Telephony/VoiceTools.php` (SC-3 / E17-8: la baja de viva voz se acepta con el freno echado; `informe-sc3-voz.md`).
  - **Texto:** `CrmPermission.php` (G-11, comentario), `PermissionCatalog.php` (el texto de `ultron.strategies_publish`, doc9 #8).
  - **`.env.example`** (no corre) y **`routes/console.php`** (*).
  - (*) **solo añaden código**: las líneas que quitan solo pueden ser comentarios y ninguna con `Schedule::` (§2.6, hallazgo 1).
  - Cualquier otro fichero ABORTA, también `routes/api.php`, `composer.*`, `VoiceCallPolicy.php`, el OTP, Meta, Legacy o el motor comercial.
- **Migraciones, lista CERRADA** (solo añadidas; una ya desplegada que cambie también aborta): `2026_11_13_100001` (V9, `vc_requester_chk` con `strategy`), `2026_11_13_200001` (W9, la tabla 26), `2026_11_14_100001` (SC-3, `vbr_reason_chk` con `webhook_unhealthy`) y `2026_11_14_100002` (SC-13, índice único `vc_one_call_per_strategy_job`). Las dos últimas **aparecieron en el árbol mientras trabajaba** y la puerta las cazó (§2.6). Todas traen su `lock_timeout` de 5 s; las de la voz toman ACCESS EXCLUSIVE o SHARE sobre `voice_calls`/`voice_brakes`, vacías en producción.
- **El efecto de cada migración se comprueba** antes de las cachés (`Q_EFECTOS`: `f f f f` → `t t t t`), además del esquema (`123 137 9 25 51 75 3 8` → **`127 141 9 26 51 75 3 8`**), la voz igual (disparadores, CHECK, tablas y un solo `vc_requester_chk` y `vbr_reason_chk`), **34 funciones** sin SECURITY DEFINER, la tabla de W9 de `iron` con `ra---s` para `iron_app` (solo inserción) y vacía, las demás tablas del H9 con las MISMAS filas que antes (no 0: el propietario pudo vincular personas entre la fase 1 y la ola).
- **Las dos constantes** cerradas en `origin/main` (una declaración `= false;` anclada y ningún `= true`) y en ejecución tras el despliegue.
- **P9-25, el orden (primero el CRM):** el paso 0 exige que `https://ironbodyneiva.cloud/version.json` sea el commit de `--crm` (distinto de 9572426) y que el `access.log` de nginx no tenga, en los últimos 10 minutos, peticiones `GET /api/admin/stream-ticket` **sin** `?scope=` (las del CRM viejo; el nuevo siempre lo manda). No imprime ninguna línea del registro (llevan IP). `--acepta-pestanas-viejas` es la decisión del propietario si quiere seguir igualmente.
- **Variables:** las 4 claves nuevas de la ola, explícitas y apagadas (protección 1): `VOICE_CONTACTS_ENABLED=false` y el cupo, el techo y la reserva de la voz del H9 a 0. Los precios de WhatsApp quedan en su defecto (0): los escribe el mando.
- **Programador:** el mismo total **+1**: `admin:prune-stream-tickets` (SC-7) se programa SIN bandera cada 10 min (§2.6); `voice:webhook-health` y los dos normalizadores cuelgan de sus banderas (0 con todo apagado).
- **Cachés** como www-data con umask 077 (600, sin seguir enlaces), `route:cache` (sin rutas nuevas: las mismas 121 30 13 9 4 1) y `queue:restart`.
- **Sanidad** como la fase 1 más el vale sin sesión (401) y el alta de contactos de voz sin token (401); `security:check-secret-exposure` es ya el nuevo (B-T17).
- **Paso 12, los smokes del H9 (fase 1) y del H8**, copiados del checkout del COMMIT DESPLEGADO a un directorio 700 bajo `/root` (los lanzadores borran sus copias, también la de `smoke-h6.py`, así que cada uno lleva la suya) y uno tras otro (la misma cuenta 32). Solo si no hubo desvíos; `--sin-smoke` y `--solo-smoke` para correrlos aparte.
- **La vuelta atrás** se imprime al final con el respaldo de ese día, **en orden inverso**: primero el backend, después el CRM.

### 2.2 CRM: `deploy-h9-crm-cierre.sh`

Calca `deploy-h8-crm-fase1.sh` (el que se usó en L9-76). Añade: el backend que sirve es el del H9 (`outreach/status` y `stream-ticket` sin token → 401); los **marcadores de la ola en el dist** (`stream-ticket?scope=` de P9-25 y los selectores `us-activation-panel` y `ca-activation` de L9-79); el respaldo incluye la **lista de ficheros publicados**, y la verificación exige que **ningún fichero de antes desaparezca** (`comm -23` de la lista de antes contra la de después) además de que el main viejo y tres chunks viejos sigan en 200. Termina diciendo lo que va después: recargar las pestañas de recepción y del punto facial, esperar 10 minutos y entonces el backend. `CRM_REMOTE=local` existe SOLO para el banco.

### 2.3 Orden del despliegue de la ola (con la autorización del propietario)

1. Commits del lead (backend y CRM, con el `docs/ops` de este informe dentro) y push.
2. Mac: `bash deploy-h9-crm-cierre.sh <crm> 9572426 --solo-comprobar` y después sin `--solo-comprobar`.
3. Recargar las pestañas de recepción y del punto facial; esperar 10 minutos.
4. Servidor: copiar `deploy-h9-backend-cierre.sh` del commit a `/root` (700) y comprobar su huella; el bloque del respaldo de su cabecera; `bash /root/deploy-h9-backend-cierre.sh <backend> <BK> --crm=<crm> --solo-comprobar` (con `--acepta-main=…` si lista ajenos) y después sin `--solo-comprobar`.
5. Vigilancia de 15 minutos (la imprime el guion) y comprobar en recepción que las fotos y el canal en vivo cargan.
6. Copiar a `/root` el mando del commit desplegado (el de `/root` hoy es el de la fase 1: RUNBOOK §2.1).

### 2.4 Lo que había que cambiar en el smoke del H9 para que la ola pase

- `smoke-run-h9.sh` exigía **exactamente** `25 8` tablas y abortaba sin ejecutar el smoke: con W9 son 26. Ahora `TABLAS_STUDIO="${SMOKE_EXPECT_STUDIO_TABLES:-26}"` y los mensajes dicen 26 y 34.
- `smoke-h9.py` exigía `live = false` en `whatsapp_template` y `voice_call`. Desde L9-80/L9-81 los dos están declarados en `LIVE_CHANNELS` («implementado no es abierto»): en el banco con la ola **dio FALLO**, y en producción habría dado lo mismo. Ahora `live` se informa (INFO) y la comprobación es la que de verdad impide salir: los dos canales presentes y `sendsEnabledInCode = false`; en la fase 1, además, las banderas de los canales apagadas (comprobación que ya existía). **Con el cambio: 27 OK · 0 FALLO · 1 OMITIDO** (los presupuestos de PERF9), código 3, como en producción el 10-11.

### 2.5 Ensayos de G-9 en el banco

El banco se monta por el MISMO camino que producción (`ola.sh montar`): el H8 cerrado (`a608450`) → **la fase 1 con su propio guion** y `--acepta-main=a921fbb,307ad51,5a10d51`, como el 10-11 (salió «DESPLIEGUE H9 FASE 1 (BACKEND) COMPLETADO»: esquema `123 137 9 25 51 75 3 8`, rutas `121 30 13 9 4 1`, programador `27 0 0 0`, workers 13 0) → encima, como `origin/main`, `d701b0c` + un commit `wip(ultron-studio-h9)` con TODO lo que estaba sin confirmar en el árbol (backend y `docs/ultron-studio/ops`).

**Primer banco (01:41–02:02 UTC), `--solo-comprobar`:**

| # | Situación | Resultado |
|---|---|---|
| 02 | Lista de migraciones de entonces (2) | **ABORTA**: la puerta encontró `2026_11_14_100001` y `2026_11_14_100002` (SC-3 y SC-13, recién llegadas) → añadidas a la lista con su comprobación de efecto |
| 03 | Lista cerrada de entonces | **ABORTA**: `PruneStreamTicketsCommand.php` (SC-7) y `Models/Voice/VoiceBrakeEntry.php` (SC-3), fuera de la lista → añadidos con su dueño; `TAREAS_NUEVAS_SIEMPRE=1` |
| 04 | CRM publicado = 9572426 | **ABORTA** por el orden de P9-25 («primero deploy-h9-crm-cierre.sh») |
| 05 | CRM nuevo + 3 vales sin `scope` en el access.log | **ABORTA**: «quedan pestañas con el CRM viejo» |
| 06 | access.log ilegible | **ABORTA** (o `--acepta-pestanas-viejas`) |
| 07 | CRM nuevo, solo vales con `scope` | **COMPROBACIONES PREVIAS EN VERDE** (código 0): respaldo de 253 tablas, puerta 46 libres + 34 de la lista, constantes cerradas, esquema y voz de partida, efectos `f f f f`, rutas 121, programador 27, workers 13 0 |

**Primer banco, despliegue real (08):** pasos 1–11 **sin un desvío**: 84 ficheros legibles por www-data; las 4 claves añadidas; **las 4 migraciones en la primera pasada** (24, 40, 6 y 12 ms); esquema `127 141 9 26 51 75 3 8`, voz `27 51 7 1 1` igual, efectos `t t t t`; 34 funciones; privilegios de la tabla de W9 `ra---s`; mismas filas; rutas iguales (121); programador **27 → 28** (la purga); colas 0; workers 13 0 ×2; 401/no-store, 401 del vale y de contactos, 413, 403 del OTP; `check-secret-exposure` OK; instantáneas 3 → 3; 0 ERROR. **Paso 12:** el smoke del H9 de ANTES de §2.4 dio el FALLO de `live` (por eso se cambió) y el del H8, 2 FALLOS del ENTORNO (§2.6, hallazgo 4) → «CON 2 DESVÍOS», que es exactamente lo que el guion debe hacer.

**Segundo banco, con el árbol de las 02:05 UTC (smokes corregidos dentro del commit de la ola):** ver §2.7.

**CRM (21):** `--solo-comprobar` → «BUILD VERIFICADO» (sello `a06bfcc` limpio, los tres marcadores en el dist); despliegue contra el «servidor» local con un build viejo sintético → **«CRM H9 CIERRE PUBLICADO Y VERIFICADO»**: 6 ficheros antes → 209 después, **0 desaparecidos**; `version.json` → `a06bfcc`; `index.html` → el main nuevo; main nuevo, main viejo, 3 chunks viejos y los 3 chunks de los marcadores → 200; `/marketing/ultron-studio`, `/…/voz` y `/marketing/automatizaciones` → 200. **Prueba negativa** de la comprobación: con un chunk viejo quitado a mano, la misma tubería cuenta «1 desaparecido»; restaurado, 0.

### 2.6 Hallazgos para el lead

1. **`routes/console.php` quita 3 líneas frente a `ce7c150`** (el comentario del bloque del H9, reescrito en L9-81 G-7). Una puerta estricta de «solo añade» (como la de la fase 1, `--numstat` sin restas) abortaría. La implementé como «las líneas que quita solo pueden ser comentarios y ninguna con `Schedule::`» (igual para `OutboundGate.php`, que quita 3 líneas del docblock por G-11, y `MarketingMessageDispatcher.php`). Si prefieres el «solo añade» literal, hay que dejar las 3 líneas viejas y añadir el texto nuevo debajo.
2. **El árbol cambió durante el encargo** (dos migraciones, tres ficheros fuera del Studio —`PruneStreamTicketsCommand.php`, `VoiceBrakeEntry.php` y, en el segundo banco, `VoiceTools.php`—, `PolicyLadder.php`, `config/outreach.php`; `WhatsappOutboxService.php` pasó de +41 a +146): la puerta cerrada los cazó todos en el ensayo. `routes/api.php` y `app/Support/SseStream.php`, que cita `informe-sec-fix-acceso.md`, NO cambiaron (la puerta los rechazaría). **La lista cerrada, `MIG_OLA`, `Q_EFECTOS` y `TAREAS_NUEVAS_SIEMPRE` hay que releerlas con el árbol final** (§2.7 dice cómo): un ensayo `--solo-comprobar` en el banco lo hace en 5 minutos.
3. **`admin:prune-stream-tickets` se programa sin bandera** (cada 10 min, borra solo las anotaciones caducadas de los vales en la caché `database`): es la única tarea que la ola enciende de verdad en producción. Está en el guion; conviene que el propietario lo sepa.
4. **El smoke del H8 (de ops8-e) no puede pasar en este banco:** `TEL_FASE1` está fijado a `ELEVENLABS_UNVERIFIED` dentro de `smoke-run-h8.sh` y la ruta 45 espera `ELEVENLABS_VERIFIED` (esta sí se puede cambiar con `SMOKE_EXPECT_VOICE_STATE`); el banco del H9 no tiene piezas de ElevenLabs y da `NOT_CONFIGURED`. Sus otras 48 comprobaciones pasaron con la ola. En producción, esos dos valores son los del estado del 10-11; si ElevenLabs cambió de estado desde entonces, el paso 12 dará desvío y habrá que leer cuál. No toqué el fichero (es de ops8-e).
5. **`smoke-h9.py` habría fallado en producción con la ola** (§2.4). Corregido; es imprescindible que viaje en el mismo commit.
6. El paso 0 del guion de la fase 1 aceptaba commits con el prefijo del H9; este acepta también `(ultron-studio-h8)`, porque el endurecimiento del H8 viaja en la ola. Si los commits del lead van todos con `(ultron-studio-h9)`, da igual.

### 2.7 Segundo ensayo completo (banco nuevo, árbol de las 02:05 UTC: 87 ficheros sin confirmar)

1. **Montaje** igual que el primero: la fase 1 con su guion, «COMPLETADO» (esquema `123 137 9 25 51 75 3 8`, programador 27); la ola `298feda` sobre `d701b0c`; respaldo de 253 tablas.
2. **`--solo-comprobar` (91):** ABORTÓ primero por `Telephony/VoiceTools.php` (SC-3/E17-8, llegado entre los dos bancos) → añadido a la lista → **EN VERDE**: 50 ficheros en zonas libres y **35 de la lista cerrada**, 0 vales sin `scope`, 4 migraciones pendientes, efectos `f f f f`.
3. **Despliegue real (92):** pasos 1–11 **sin un desvío** (esquema `127 141 9 26 51 75 3 8` en la primera pasada, voz igual, efectos `t t t t`, 34 funciones, rutas iguales, programador 27 → 28, instantáneas 3 → 3, 0 ERROR). Paso 12: **smoke del H9 del commit de la ola: 27 OK · 0 FALLO · 1 OMITIDO, código 3**; smoke del H8: 48 OK y los 2 FALLOS del entorno (§2.6, 4) → «CON 1 DESVÍO», el del H8.
4. **Vuelta atrás ensayada (93)** con las órdenes EXACTAS que imprimió el guion: HEAD `298feda` → `ce7c150` (el de `api-commit.txt`), cachés de nuevo (600), `queue:restart`; **121 rutas del Studio**; `outreach/status`, `ultron-studio/status`, `voice/status` y `stream-ticket` sin token → 401; las constantes `[false, false]`; la tabla de W9 y los CHECK ampliados **quedan** (26 tablas `ultron_studio_*`): el código anterior convive con el esquema de la ola.
5. **Smoke del H9 sobre el código vuelto atrás:** la primera pasada (94), lanzada nada más acabar los dos smokes del despliegue, dio 3 FALLOS **por 429 del limitador** (la misma IP, el smoke avisa de «3 pasadas por minuto como mucho»); la repetición tras 90 s (95): **27 OK · 0 FALLO · 1 OMITIDO, código 3**. La vuelta atrás del código queda ensayada de punta a punta (no la de los datos: no hay, la ola no escribe filas).

### 2.8 Cómo repetir el ensayo con el árbol final (cuando terminen los agentes de código)

```bash
cd backend   # el árbol de la rama, con todo lo que se vaya a confirmar
B=$HOME/.cache/ironbody-banco-h9-final; export BANCO_DB=ultron_studio_banco_h9_final BANCO_PORT=8531 BANCO_PUERTO_VALLA=8534
bash docs/ultron-studio/ops/banco-h9/ola.sh "$B" montar                     # ≈ 3 min: H8 cerrado → fase 1 con su guion → la ola
bash docs/ultron-studio/ops/banco-h9/ola.sh "$B" crm abc1234 && bash docs/ultron-studio/ops/banco-h9/ola.sh "$B" pestanas nuevas
bash docs/ultron-studio/ops/banco-h9/ola.sh "$B" desplegar --crm=abc1234 --solo-comprobar   # si ABORTA por la puerta, ver abajo
bash docs/ultron-studio/ops/banco-h9/ola.sh "$B" desplegar --crm=abc1234                    # ≈ 4 min con los dos smokes
bash docs/ultron-studio/ops/banco-h9/ensayar.sh "$B" parar
```

Si el paso 0 aborta por un fichero o una migración que la lista no nombra, NO se amplía a ciegas: se lee de quién es (su informe) y, si es de la ola, se añade a `LISTA_CERRADA` (con su dueño) o a `MIG_OLA` (con su efecto en `Q_EFECTOS` y `EFECTOS_*`); si añade una tarea al programador sin bandera, `TAREAS_NUEVAS_SIEMPRE`. El smoke del H8 dará sus 2 FALLOS del entorno del banco (§2.6, 4).

**Estado en que dejo el banco:** servidores parados (puertos 8521, 8524 y 8525 libres); el directorio `~/.cache/ironbody-banco-h9-ops9c` y la base `ultron_studio_banco_h9_ops9c` quedan para quien quiera mirarlos (la «producción» del banco quedó vuelta atrás a `ce7c150` con el esquema de la ola). Se retiran con `rm -rf ~/.cache/ironbody-banco-h9-ops9c` y `DROP DATABASE ultron_studio_banco_h9_ops9c` (o los rehace `montar.sh`).

## 3. Encargo 3 · SC-8: `nginx-h9-vales.conf.propuesta` (NO aplicada)

- **Qué hace:** con un `map` sobre `$request_uri` (la URI ORIGINAL: no cambia con la redirección interna a `/index.php`, que es donde termina una petición de Laravel y donde se aplica su `access_log`), toda petición cuya query lleve `ticket=` se registra con el **mismo formato que `combined`** (el estado sigue en la columna 9) pero con la ruta **sin la query**; todo lo demás, exactamente como hoy. Dos líneas `access_log … if=` en el `server` de la API (y en cualquier `location` que declare su propio `access_log`). Cubre las 12 rutas de `StreamTicketService::ROUTES` y las que vengan (el nombre del parámetro es el único que lee `EnsureAdminAuth`). La emisión del vale (`stream-ticket?scope=…`) se sigue registrando con su query (la usa el paso 0 de G-9).
- **Cómo probarla** (§3 del fichero): `nginx -t`, `systemctl reload nginx` (no corta conexiones), tres `curl` con una cadena de prueba que NO es un vale, y `grep` de que la cadena no está en `access.log` y de que las líneas sí están sin la query; los estados siguen en `$9`.
- **Vuelta atrás** (§4): restaurar el vhost del respaldo, retirar (mover, no borrar) el fichero de `conf.d`, `nginx -t` y recargar; en un guion de una línea en `/etc/nginx/respaldos/`, como los cambios de nginx anteriores.
- **Lo que NO resuelve:** el `error_log` (nginx no tiene formato para él: un 502/504 con vale escribe la petición entera). Mitigación operativa y la corrección de fondo (las fotos por `fetch` + `blob:` y el vale atado al `userId`) en §5 del fichero, para el dueño de P9-25 y frontend.
- **Lo que hay que leer antes en el servidor** (§0): versión de nginx (≥ 1.7 para `if=`), `nginx -T` guardado, formato y fichero actuales del `access_log`, si `conf.d` está dentro de `http`, quién lee los registros. **No verifiqué nada de eso**: el vhost no está en el repo (la memoria del proyecto dice `/etc/nginx/sites-available/api`, leído el 06-10-2026).

## 4. Lo que exige la autorización del propietario (nada de esto se ha hecho)

| # | Acción | Orden exacta |
|---|---|---|
| 1 | Commits y push del backend y del CRM (con estos `docs/ops`) | lead |
| 2 | Publicar el CRM de la ola | `bash backend/docs/ultron-studio/ops/deploy-h9-crm-cierre.sh <crm> 9572426` (antes, `--solo-comprobar`) |
| 3 | Recargar recepción y el punto facial | a mano, en los equipos |
| 4 | Respaldo y despliegue del backend | el bloque de la cabecera de `deploy-h9-backend-cierre.sh` y `bash /root/deploy-h9-backend-cierre.sh <backend> <BK> --crm=<crm> [--acepta-main=…]` (antes, `--solo-comprobar`) |
| 5 | El mando del commit desplegado en `/root` | `git show <backend>:backend/docs/ultron-studio/ops/h9-outreach-scope.sh > h9-outreach-scope.sh` → `scp` → `chmod 700` → `sha256sum` |
| 6 | P9-24 (antes de la sombra) | `bash /root/h9-outreach-scope.sh eventos medir` (solo lectura, pero en producción) → `bash /root/h9-outreach-scope.sh eventos on --orden-escrita=<ref>` |
| 7 | SC-8 en nginx | §3 de `nginx-h9-vales.conf.propuesta` |
| 8 | Cualquier encendido del mando (sombra, asistido, canales, horario…) | RUNBOOK_H9, una orden escrita por etapa |

## 5. Lo que NO verifiqué

- **Nada de producción:** ni el `access.log` real (ruta, formato, que el vhost de la API escriba ahí: lo supongo por los documentos, que usan `/var/log/nginx/access.log` en formato combined; si no es así, el paso 0 aborta y lo dice), ni la versión ni el vhost de nginx, ni el estado de ElevenLabs que espera el smoke del H8, ni el tamaño de `attendances` y el volumen real de P9-24, ni la versión del mando que hay en `/root`.
- **El `ironbody-migrate` real:** el banco usa la copia de `ironbody-migrate-propuesta.sh` (como en el H7–H9).
- **El CRM en un navegador:** solo el build, sus marcadores y la semántica del `rsync`; el comportamiento de las pestañas viejas tras P9-25 es el del informe de P9-25 (razonado, no ejecutado). El «servidor» del CRM del banco es un directorio local con un build viejo sintético.
- **El smoke del H8 completo con la ola**, por el entorno del banco (§2.6, 4).
- **El árbol final:** los agentes de código seguían escribiendo; mis listas son las del árbol de las 02:05 UTC.
- **`security:check-secret-exposure` con los registros reales:** el nuevo (B-T17) marca los registros legibles por cualquiera que contengan `CLAVE=valor`; si producción tuviera uno, el paso 9 daría desvío (es su trabajo).
- **El caso de una reanudación tardía** del guion del backend (relanzarlo tras un corte): la lógica está (como en la fase 1), pero no la ensayé.
- **`--solo-smoke`:** ensayé su guarda (aborta si producción no está en el commit de la ola); el camino bueno no lo corrí aparte (los smokes corrieron dentro del paso 12, en los dos bancos).

## 6. Riesgos residuales, deuda y documentos que han quedado desfasados (no los toqué: son de sus dueños)

- **`RUNBOOK_H9.md` (DOC9):** §2.2 «no escribe los precios ni los cupos de voz»; §3.2 paso 4.1 «HUECO: no hay procedimiento» (ahora `eventos`); paso 4.2 «el tramo de los sábados no se puede escribir con el mando»; §3.3 «`apagar` escribe 7 claves… discrepancia abierta» (ahora 13); §5.1 W4 y V2 («el mando no escribe estas variables», «`canal voz on` no lleva opciones»); §5.2 (las órdenes con sus opciones nuevas); §4.4, §5.3 y §7.2 dan el `budget_breach` como *deducido del código* (ahora ensayado); §5 «no hay guion para desplegarlos (G-9)»; §2.1, las huellas del mando y del smoke cambian con el commit de la ola.
- **`ULTRON_STUDIO_ACTIVACION_POR_ETAPAS.md` (lead):** 4.1 «mando del motor comercial» → `h9-outreach-scope.sh eventos medir/on`; 6.3 y 6.4, las opciones nuevas de `canal … on`.
- **`ULTRON_STUDIO_DEPLOYMENT_PLAN.md`:** «Lo terminado en el árbol… SIN VÍA DE DESPLIEGUE» → ya tiene guion (este).
- **`ops/README.md` (ops8-e):** le faltan `deploy-h9-backend-cierre.sh`, `deploy-h9-crm-cierre.sh`, `nginx-h9-vales.conf.propuesta`, `banco-h9/ola.sh` y `RUNBOOK_H9.md`.
- **El índice de `attendances(member_id)`** (P9-24): no existe; decisión de `database-specialist` si el tiempo de la entrada crece tras encender los eventos.
- **El `error_log` de nginx** sigue pudiendo guardar un vale en un 502/504 (§3).
- **Secretos:** no leí ni imprimí ninguno; el banco usa un entorno sintético. Nada que rotar por este trabajo.

## 7. Resumen para el lead

- **Mando:** `apagar` = 13 claves (Q-7); horario con `--sabado=`; `canal whatsapp on` con los dos precios (precio 0 negado: hoy es `budget_zero`); `canal voz on` con cupo, techo y reserva cruzados con los límites del H8; comprobación de canal por clase exacta y `LIVE_CHANNELS`; **P9-24 resuelto con `eventos medir/on/off`** (escribe solo `commercial_events`; con NBA apagado nada decide ni contacta; el coste real es una consulta sin índice en cada entrada de recepción). 49 órdenes ensayadas en el banco, también la vía abierta (en la copia), 0 desvíos; el `budget_breach` al bajar un cupo, ensayado.
- **G-9:** `deploy-h9-backend-cierre.sh` (PREVIO `ce7c150`, lista cerrada de 35 ficheros con dueño, 4 migraciones con su efecto, rechazo de `sends-open`/`calls-open`, las dos constantes cerradas, el orden de P9-25 comprobado por el version.json del CRM y el access.log, respaldo, cachés como www-data, smokes del H9 y del H8, vuelta atrás) y `deploy-h9-crm-cierre.sh` (rsync sin `--delete` con la comprobación de que nada desaparece). Ensayados en dos bancos montados por el mismo camino que producción: `--solo-comprobar` en verde tras cazar 2 migraciones y 3 ficheros nuevos que llegaron mientras trabajaba; despliegue real limpio en los pasos 1–11; smoke del H9 de la ola 27/0/1; **vuelta atrás ensayada** con las órdenes que imprime el guion (121 rutas, 401, constantes cerradas, el código anterior convive con el esquema nuevo). El CRM, publicado contra un directorio local con 0 ficheros desaparecidos.
- **Imprescindible en el commit:** `smoke-run-h9.sh` (26 tablas) y `smoke-h9.py` (`live` de WhatsApp y voz): sin ellos el smoke del H9 falla en producción tras la ola.
- **SC-8:** propuesta de nginx sin aplicar; el `error_log` no se puede filtrar con configuración.
- **Decisiones tuyas:** la regla de `routes/console.php` (§2.6, 1); releer la lista cerrada con el árbol final (5 minutos de banco); avisar al propietario de la tarea sin bandera `admin:prune-stream-tickets`.

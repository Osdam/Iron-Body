# ULTRON STUDIO · Hito 8 · h8-hardening (`backend`): cuatro brechas de la auditoría que no dependen del plan de pago (B-T4, B-T17, B-T5, B-T16)

**Fecha:** 10-11-2026. **Árbol:** `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h9` sobre `d701b0c` (producción `ce7c150`). **Sin commit, sin red hacia los proveedores, sin producción.** En el árbol hay trabajo ajeno sin confirmar (Outreach, `OutboundGate`, `EnsureAdminAuth`, migraciones y pruebas del H9 y una prueba nueva de la supresión 1581 de la voz) que no he tocado. Rutas relativas a `backend/`.

**Encargo:** `ULTRON_STUDIO_AUDITORIA_9_HITOS.md` §4.1: B-T4 🔒 (contactos de voz sin bandera), B-T17 🔒 (`security:check-secret-exposure` sin las claves de la voz ni las 13 de H-4 de SEC7), B-T5 (salud del webhook, D8-21 decisión (f)) y B-T16 (worker muerto con un trabajo reservado, H-7 de OPS7 / C-3).

## 0. Resumen

| Brecha | Estado | Lo esencial |
|---|---|---|
| **B-T4** 🔒 | Hecho | `VOICE_CONTACTS_ENABLED` (apagada): alta, carga y confirmación del consentimiento → 409 `voice_contacts_disabled` sin escribir, en el servicio y en el controlador (antes de validar); lecturas, revocar, excluir y RNE siguen; `flags.contacts` en el estado y en el doctor. El mando de `ops8-e` ya usa este mismo contrato |
| **B-T17** 🔒 | Hecho | La orden vigila las 13 de H-4 de SEC7 y las familias de la voz (`ELEVENLABS_*`, `TWILIO_VOICE_*`, `VOICE_WEBHOOK_*` que terminen en `_API_KEY`/`_SECRET`/`_TOKEN`/`_PASSWORD`), con `export` y todas las apariciones; mira además los LOGS legibles por cualquiera; dice los NOMBRES, nunca valores |
| **B-T5** | Hecho | `voice:webhook-health`: solo con `VOICE_MONITOR_ENABLED`, dos GET de solo lectura comparan el webhook post-llamada y los dos secretos con las referencias de Laravel; si no coinciden, incidente `voice_webhook_unhealthy`; veredicto en el estado (`webhookHealth`) y en el doctor; programada cada hora (minuto 5) bajo el monitor de voz; apagada, sin red |
| **B-T16** | Hecho | `queue:health`, el monitor del H7 e IRON GUARD ven el trabajo reservado de un worker muerto (`retry_after` + 60 s sin latido posterior) en `voice`, `commercial` y `lab`; incidente `queue_reserved_orphaned` por trabajo |

Verificado con pruebas nuevas y la regresión de los conjuntos pedidos en SQLite y PG (§6), Pint y mutaciones en una copia aislada. **Para `security`:** he tenido que AMPLIAR (sin aflojar) dos guardas de SEC8 para B-T5 (§5). **Para `ops8-e`:** el monitor de voz programa ahora 5 tareas, no 4 (§5). Rojos ajenos y lo no verificado, en §7.

## 1. B-T4 · Contactos de voz detrás de `VOICE_CONTACTS_ENABLED`

**Qué había:** `VoiceContacts::register()` y la carga y confirmación del consentimiento no miraban ninguna bandera; solo la llave `telephony.contacts_manage` (Super Admin), antes del visto bueno legal (D-12) y sin supresión 1581 de la voz.

**Qué hay ahora:**

| Fichero:líneas | Cambio |
|---|---|
| `config/voice.php:268-277` | `voice.contacts.enabled` = `filter_var(env('VOICE_CONTACTS_ENABLED', false), FILTER_VALIDATE_BOOLEAN)`: solo `true`, 1, on o yes la encienden |
| `.env.example:552-558` | `VOICE_CONTACTS_ENABLED=false` en el bloque del Hito 8, con su explicación |
| `app/Services/Telephony/VoiceContacts.php:34-41, 49-74` | `CODE_DISABLED = 'voice_contacts_disabled'`, `enabled()` y `assertEnabled()` (409 sin leer ni escribir) |
| `app/Services/Telephony/VoiceContacts.php:108, 164, 196` | `assertEnabled()` como primera línea de `register()`, `requestConsent()` y `confirmConsent()`: vale para cualquier llamador (controlador, consola, otro servicio) |
| `app/Http/Controllers/Crm/Voice/VoiceContactController.php:25-29, 44, 60, 72` | La misma comprobación tras `VoiceActor::require()` y **antes de validar**: apagada, 409 sea cual sea el cuerpo (el orden de `voice_publishing_disabled`: permiso → bandera → resto) |
| `app/Services/Telephony/VoiceTelephonyStatus.php:51-52` | `flags.contacts` en `GET admin/voice/status` y en `voice:doctor --telefonia --json` (aditivo) |
| `app/Console/Commands/Voice/VoiceDoctorCommand.php:141-143` | La línea «Banderas» termina en `· contactos sí` o `· contactos no` |

**Contrato:** `POST admin/voice/contacts`, `POST admin/voice/contacts/{uuid}/consents` y `POST admin/voice/contacts/{uuid}/consents/confirm` con la bandera apagada → **409** `{"ok": false, "code": "voice_contacts_disabled", "message": "…"}`, después del 401/403 de siempre. Siguen abiertos: `GET contacts`, `POST contacts/{uuid}/revoke`, `POST exclusions` y `POST rne-checks` (solo restringen las salientes; revocar «no espera a nadie», D8-18). `GET status` gana `flags.contacts` (booleano).

**Coordinación con `ops8-e`:** su mando ya usa este contrato tal cual (`h8-voice-scope.sh contactos on|off` escribe `VOICE_CONTACTS_ENABLED`, lee `flags.contacts` y se niega si el código desplegado no la lee; `smoke-h8.py:396-399` admite `flags.contacts` apagada). No he podido escribirle por mensaje (en esta sesión no tengo la herramienta de mensajes): lo dejo aquí y en el resumen al lead.

**Pruebas nuevas:** `tests/Feature/Telephony/VoiceContactsFlagTest.php`, 6 pruebas: defecto apagado; con la bandera apagada, alta, alta con cuerpo inválido, alta repetida, carga, carga inválida, confirmación por otra persona y de un contacto inexistente → 409 `voice_contacts_disabled` y **ninguna fila nueva** (contactos, consentimientos, cargas pendientes); 401/403 antes que la bandera; lecturas, revocar, excluir y RNE siguen; el servicio también rechaza (y encendido escribe); `flags.contacts` y el doctor la enseñan en los dos estados.

**Pruebas ajustadas (encienden la bandera como la fase 4b):** `VoiceCrmRoutesTest` (el flujo de cuatro ojos), `VoiceProductionNoNetworkTest` (ahora prueba además el 409 con la configuración de producción y que no escribe), `PlaceOutboundVoiceCallRecheckTest::contactCall()`, `H8E2ESalientesPrimeroTest::ENTORNO_4B` (la segunda API de la 4b; solo corre con el arnés `h8-e2e.sh`, **no ejecutado**) y `ElevenLabsConfigTest` (defecto y lectura de la bandera; la variable entra en la lista que se limpia del entorno, para no repetir la fuga de B8-h).

**Fuera de alcance (decisión de otros):** la bandera no cierra las SALIENTES con alcance `contacts` a contactos ya existentes: eso lo cierran hoy `VOICE_OUTBOUND_ENABLED=false` y `VOICE_OUTBOUND_SCOPE=canary` (defectos). Si se quiere que también las cierre, es un motivo nuevo en `OutboundPolicy` y su fixture (paquete de la puerta, que otro agente está editando). El CRM (pestaña Contactos) debería leer `flags.contacts` para no ofrecer el alta ni la carga (`frontend`).

## 2. B-T17 · `security:check-secret-exposure` con las claves de la voz, las 13 de SEC7 y los logs

**Qué había** (`app/Console/Commands/CheckSecretExposure.php:45-54` de `d701b0c`): 18 claves, ninguna de la voz ni las 13 de H-4 de SEC7; solo ficheros `.env*`; un fichero PARCIAL legible (sin `APP_KEY`) o un log con un volcado del entorno pasaban como limpios.

**Qué hay ahora** (`app/Console/Commands/CheckSecretExposure.php`):

| Líneas | Cambio |
|---|---|
| `64-69` | Las 12 de H-4 con nombre (`ADMIN_API_TOKEN`, `AUTOMATION_INTERNAL_SECRET`, `DB_OWNER_PASSWORD`, `MAIL_PASSWORD`, `META_EMBEDDED_SIGNUP_APP_SECRET`, `N8N_WEBHOOK_SECRET`, `REDIS_PASSWORD`, `ULTRON_STUDIO_TWILIO_PROBE_KEY_SECRET`, `FACTUS_WEBHOOK_SECRET`, `AWS_SECRET_ACCESS_KEY`, `LABSMOBILE_TOKEN`, `MEMBER_REGISTRATION_TOKEN`); la 13.ª, `ELEVENLABS_*`, va por la familia |
| `72-86` | Las FAMILIAS de la voz `ELEVENLABS_`, `TWILIO_VOICE_` y `VOICE_WEBHOOK_`: cuenta como secreta toda clave suya que **termine** en `_API_KEY`, `_SECRET`, `_TOKEN` o `_PASSWORD` (el mismo criterio que `esSecreto()` de la guarda de la voz). Hoy: `ELEVENLABS_API_KEY`, `ELEVENLABS_WEBHOOK_SECRET`, `ELEVENLABS_TOOL_SECRET`, `ELEVENLABS_INITIATION_SECRET`, `TWILIO_VOICE_API_KEY_SECRET`, `TWILIO_VOICE_SIGNING_TOKEN` y las que vengan con esa forma |
| `188-229` | Quinta comprobación: los **logs** de `storage/logs/*.log` legibles por «otros» que lleven una clave vigilada con valor real en una línea suya (la forma de un volcado del entorno); se leen línea a línea |
| `317-381` | Una sola expresión para todas las claves, con `export ` opcional y TODAS las apariciones (una clave repetida con un ejemplo delante y el secreto detrás también cuenta) |
| `116-119` | La tabla y el JSON dicen los NOMBRES de las claves con valor real (`keys`), para saber qué rotar; **nunca un valor** |

**Por qué no `ELEVENLABS_*` entero:** con todas las claves de la familia, `.env.example` (644, en el servidor) con `ELEVENLABS_REGION=default` o `ELEVENLABS_TIMEOUT_SECONDS=10` sería un hallazgo y cada despliegue daría desvío. Ids (`*_REF`, `*_SID`, `*_ID`), región, base y banderas no son secretos.

**Contrato del JSON (aditivo):** cada hallazgo de fichero de entorno o de log gana `keys` (lista de nombres). Nuevo `issue`: `log legible por cualquier usuario con credenciales`. El código de salida no cambia (0/1); los despliegues solo miran el código.

**Pruebas:** `tests/Feature/Security/SecretExposureVoiceKeysTest.php`, 4 pruebas, en una base de la aplicación **desechable** (sus `.env*`, `storage/logs` y `public` en un directorio temporal; nada en el árbol real y nada que otra prueba vea): cada una de las 6 de la voz, las 12 de H-4 y una futura de la familia (`VOICE_WEBHOOK_SIGNING_SECRET`) en un fichero parcial 644 → 1 con su nombre y **sin su valor** (texto y JSON), y en 600 → 0; `export`, comillas y repetida tras un ejemplo; ids, región, base, banderas, secretos vacíos o de ejemplo y los dos `.env*.example` REALES del repositorio en 644 → 0; **una clave de voz filtrada en un log** de voz 0664 (volcado de una traza con `ELEVENLABS_API_KEY=` y `export TWILIO_VOICE_SIGNING_TOKEN=`) → 1 con las dos claves y sin los valores, y el mismo log en 0640 → 0; un log 0664 sin credenciales no es hallazgo. **«En una respuesta»:** `SecretExposureTest::test_no_route_ever_serves_an_env_file` gana los marcadores de las 6 claves de la voz y tres rutas del log de voz (`/storage/logs/voice.log`, el del día y por traversal); y las pruebas de B-T4 y B-T5 comprueban con centinelas que ni el estado, ni el doctor, ni la orden nueva, ni el incidente devuelven claves, URL o ids.

**Coste:** la orden recorre los logs legibles por cualquiera. En local, 57 MB de logs en 0,47 s de CPU de usuario (la orden entera; el tiempo de pared lo puso la carga de la máquina, load ≈ 395). En el servidor crece con los logs de `channel` y `voice` (0664, 30 días).

**Recomendación (no hecha; `devops`/`security`):** los canales `channel` y `voice` se crean en **0664** (`config/logging.php:108` y `:129`) para que escriban php-fpm, los workers y el programador; para eso basta **0660**. Hoy cualquier usuario del sistema lee los logs de voz y del canal (teléfonos enmascarados, ids). Cambiarlo es de operación (afecta a quién escribe), no de esta tanda.

## 3. B-T5 · La salud del webhook, automatizada (D8-21, decisión (f))

**Qué había:** nada (`M8:52`, «PD»; riesgo R8-12: ElevenLabs desactiva solo un webhook tras 10 fallos seguidos y, sin él, la guarda posterior no corre y una violación ya no echa el freno).

**Qué hay ahora:** `php artisan voice:webhook-health [--dry-run] [--json]`.

- **Apagada** (`VOICE_MONITOR_ENABLED=false`, la de producción): dice `disabled`, **no sale a la red**, no guarda ni abre nada y sale con 0.
- **Encendida:** dos GET de solo lectura a ElevenLabs (las rutas que comprobó OPS8-c en la referencia oficial el 09-11-2026, `ops/h8-secrets-register.sh:4-9`): `GET /v1/workspace/webhooks` y `GET /v1/convai/secrets?page_size=100`. Compara con las referencias de Laravel:
  - el webhook `ELEVENLABS_POST_CALL_WEBHOOK_REF` existe, entrega en `VOICE_WEBHOOK_BASE_URL` + `/api/webhooks/voice/elevenlabs/post-call` (con la misma limpieza de la base que el agente compilado, `VoiceAgentTarget::cleanBase()`), no está desactivado ni a mano (`is_disabled`) ni solo (`is_auto_disabled`, R8-12) y firma con `hmac`;
  - los secretos `ELEVENLABS_TOOL_SECRET_REF` y `ELEVENLABS_INITIATION_SECRET_REF` existen en el workspace.
- **Si no coincide:** sale con 1 y abre o suma al incidente de IRON GUARD **`voice_webhook_unhealthy`** (fuente `voice`, como el freno), con la huella de los problemas ordenados (el patrón del monitor del H7): la misma avería repetida es UN incidente con más ocurrencias; otra combinación, otro. **Alto** si algo no coincide; **medio** si solo no se pudo comprobar. **No echa el freno** (el encargo pide aviso; ver §7).
- **Códigos (conjunto cerrado, `VoiceWebhookHealth::PROBLEMS`):** `provider_not_ready`, `base_url_invalid`, `webhook_ref_missing`, `tool_secret_ref_missing`, `initiation_secret_ref_missing`, `webhooks_unreadable`, `webhook_not_found`, `webhook_url_mismatch`, `webhook_disabled`, `webhook_auto_disabled`, `webhook_auth_not_hmac`, `secrets_unreadable`, `secrets_list_truncated`, `tool_secret_not_found`, `initiation_secret_not_found`.
- **Su lectura:** guarda el veredicto en la caché (`voice:webhook:health`, una semana), **sin URL, sin ids del proveedor y sin secretos**, con «la última entrega buena» que pide D8-21 (la última post-llamada firmada y aceptada en `voice_call_events`). `GET admin/voice/status` gana `webhookHealth` (null si nunca corrió) y `voice:doctor --telefonia` la línea `Webhook    : coincide con Laravel · <fecha> · última entrega buena …` / `NO COINCIDE: <códigos> · …` / `sin comprobar (…)`. Ni el estado ni el doctor salen a la red.
- **Programada:** en el bloque de voz de `routes/console.php:451-460`, solo con `VOICE_MONITOR_ENABLED`, cada hora en el minuto 5 (`5 * * * *`), sin solapes (10 min) y en un servidor.

| Fichero:líneas | Cambio |
|---|---|
| `app/Services/Voice/VoiceWebhookObserver.php` (nuevo) | Interfaz SEGREGADA de solo lectura, como `VoiceNumberObserver` (E5-2): `describeWebhooks()` y `describeSecrets()` |
| `app/Services/Voice/NullVoiceWebhookObserver.php` (nuevo) | El nulo: «desconocido», sin red |
| `app/Services/Voice/Dto/VoiceWebhookList.php`, `VoiceSecretList.php` (nuevos) | DTO inertes y validados; de los webhooks solo id, URL (en memoria; `toArray()` no la lleva), desactivado y autenticación; de los secretos solo ids |
| `app/Services/Voice/ElevenLabs/ElevenLabsAgentDeployer.php:69-76, 352-486` | Las dos lecturas: UN GET cada una, la cadena de `describeNumber` (`withoutRedirecting`, `connectTimeout`, `timeout`, la clave solo en `xi-api-key`), sin red si ElevenLabs no está en `UNVERIFIED`; cada fallo es un resultado (401/403, 404, otro, sin conexión, cuerpo ilegible) |
| `app/Services/Voice/VoiceAdapters.php:42-47` | `webhookObserver()`: el de ElevenLabs o el nulo |
| `app/Services/Telephony/VoiceWebhookHealth.php` (nuevo) | La comparación, el veredicto, la caché, «la última entrega buena» y el incidente |
| `app/Console/Commands/Voice/VoiceWebhookHealthCommand.php` (nuevo) | La orden |
| `app/Services/Telephony/VoiceTelephonyStatus.php:74-76` · `app/Console/Commands/Voice/VoiceDoctorCommand.php:180-186` | La lectura en el estado y en el doctor |

**Pruebas:** `tests/Unit/Voice/ElevenLabsWebhookObserverTest.php` (6) y `tests/Feature/Telephony/VoiceWebhookHealthTest.php` (9), siempre con `Http::fake` y la red cerrada: sin piezas, sin red; un GET cada una con su ruta y su cabecera en el host oficial de `production`; ni nombres ni valores de secretos en lo que sale; cada fallo es un resultado; los DTO rechazan lo que no tiene forma; **solo `VoiceWebhookHealth` llama a las dos lecturas** (guarda propia, como E5-2); apagada → sin peticiones; coincide → 0, sin incidente, y el estado y el doctor lo enseñan sin red; cada desajuste con su código e incidente alto; la misma avería suma ocurrencias (3 pasadas → 1 incidente con 3) y otra abre otro; lectura imposible o lista truncada → medio; sin piezas o sin referencias → sin red; `--dry-run` no guarda ni abre; «la última entrega buena» sale de la post-llamada aceptada y no de la iniciación; con centinelas, ni la orden, ni el estado, ni el doctor, ni el incidente llevan la clave, la base, los ids o los secretos.

## 4. B-T16 · Un worker muerto con un trabajo reservado ya se ve

**Qué había** (H-7 de OPS7, ensayado con `kill -9`): la espera solo cuenta trabajos SIN reservar (`QueueHealthService.php:86` de `d701b0c`), así que un trabajo reservado por un worker muerto no lo veían `queue:health`, el monitor del H7 ni IRON GUARD; en un carril de un solo worker y poco tráfico no llega trabajo nuevo que lo delate.

**Criterio:** un trabajo de la cola del carril con `reserved_at` más viejo que el `retry_after` de SU conexión **más 60 s** de margen (un worker vivo recoge una reserva vencida en su siguiente sondeo, en segundos) y **sin un latido del carril desde que se reservó** (si hay un latido posterior, hay otro worker vivo y la recogerá él). Solo en los carriles con `watch_reserved`: **`voice` (retry_after 120), `commercial` (300) y `lab`/`studio-lab` (300)**. He comprobado en `docs/ops/supervisor/*.conf` que el `--timeout` de sus workers es menor que su `retry_after` (60 < 120, 120 < 300 con 2 procesos, 150 < 300): un trabajo largo legítimo no puede parecer huérfano.

| Fichero:líneas | Cambio |
|---|---|
| `config/queue.php:229-237, 244, 260, 264` | `watch_reserved => true` en `commercial`, `lab` y `voice`, con su explicación |
| `app/Services/Observability/QueueHealthService.php:26-52, 111-200` | La foto de cada carril gana (aditivo) `watch_reserved`, `retry_after_seconds`, `orphaned_reserved`, `orphaned_jobs` (hasta 20: id, segundos reservado e intentos; **nunca la carga del trabajo**) y `looks_orphaned` |
| `app/Console/Commands/QueueHealthCommand.php:16-19, 38, 49, 57-79, 96-99` | Columna `huérfanos`, estado `WORKER MUERTO`, el aviso con el `retry_after` y salida 1 |
| `app/Console/Commands/UltronStudio/MonitorCommand.php:28-29, 42-43, 254-257` | El monitor del H7 (`--check=queues`) da `<carril>:orphaned`; como con las otras colas, **no** abre incidente propio |
| `app/Services/IronGuard/ChannelHealthDetector.php:69-71, 554-615` | `iron-guard:scan` gana `orphanedReservations`: **un incidente por TRABAJO**, código estable `queue_reserved_orphaned`, huella `[carril, id del trabajo]` (idempotente: la misma avería en cada pasada suma ocurrencias); crítico en la voz (P1, como el carril sin atender) y alto en el resto; evidencia sin la carga |

`GET /api/admin/iron-guard/dashboard` (que devuelve la foto) gana esas claves sin más cambio. **Dependencia:** como `queue_unattended`, el incidente lo abre `iron-guard:scan` (cada 5 min), que respeta `IRON_GUARD_ENABLED` (por defecto `false`, `config/observability.php:21`); no he comprobado su valor en producción. `queue:health` y el monitor del H7 lo ven con o sin esa bandera. **No se tocan** los demás carriles (`whatsapp`, `agent`, `media`, `billing`): tienen tráfico y el aviso de la espera; activarlo en ellos es una línea de configuración por carril (decisión del lead).

**Pruebas:** `tests/Feature/Chaos/ChaosQueueOrphanedReservationTest.php` (7 pruebas, 40 aserciones), con filas sembradas en `jobs` como las deja un worker muerto: los carriles vigilados son exactamente esos tres con sus `retry_after`; la foto lo ve y las señales de antes no (documenta H-7); no es huérfano si es reciente, si hay latido posterior o si el carril no se vigila; `queue:health` sale con 1 y lo dice; el monitor da `commercial:orphaned` sin incidente; IRON GUARD abre uno por TRABAJO (3 pasadas → 1 incidente con 3 ocurrencias; otro huérfano en el MISMO carril → otro incidente, y uno del laboratorio → otro; crítico en la voz, alto en el laboratorio), sin la carga del trabajo ni secretos; y `iron-guard:scan --force` lo abre por la orden. (El caso del mismo carril lo añadí tras la primera tanda de mutaciones: con el segundo trabajo en otro carril, la prueba no distinguía «por trabajo» de «por carril».) `UltronStudioLabLaneTest` fija la configuración exacta del carril del laboratorio: la he actualizado con `watch_reserved`.

## 5. Lo que he tocado de las guardas de SEC8 (para `security`)

Las dos guardas dicen «quien implementa no la toca; si cae, avisa al lead». B-T5 **no se puede hacer sin ampliarlas**: un GET nuevo a ElevenLabs exige un método con red enumerado y una orden de voz nueva exige estar en la lista del contrato. He hecho la ampliación MÍNIMA siguiendo el precedente de E5-2 (cuando entró `VoiceNumberObserver`): **ninguna regla se afloja**, solo se enumera lo nuevo, y lo marco en el código como pendiente del visto bueno de SEC8. Si `security` no lo acepta, se revierte con B-T5 entero.

| Guarda | Cambio exacto |
|---|---|
| `tests/Unit/Voice/VoiceIsolationTest.php` | `PAQUETE_H8` + `VoiceWebhookObserver.php`; `INTERFACES` + `'VoiceWebhookObserver' => ['describeSecrets', 'describeWebhooks']`; `RED[DESPLEGADOR]` + `'describeWebhooks' => ['get'], 'describeSecrets' => ['get']`; `UNA_PETICION` + las dos (una petición cada una). El nulo entra por `NULO` y los DTO por `Dto/`, como en E5-2 |
| `tests/Feature/Voice/VoiceBoundaryTest.php` | `COMANDOS` + `voice:webhook-health` |
| `tests/Feature/Voice/VoiceMonitorScheduleTest.php` (de OPS8) | De 4 a **5** tareas de voz con el monitor encendido; la nueva en `5 * * * *` |

Lo que siguen exigiendo sin cambios y cumple el código nuevo: solo GET, sin redirecciones, con plazo, la clave leída solo por su lector enumerado (`ElevenLabsAgentDeployer` ya lo era), host oficial en `production` (`ElevenLabsConfig::baseUrl()`), la telefonía sin red (va por la interfaz), la configuración de la voz solo `voice.*`, el registro solo por `Log::channel('voice')` y sin teléfonos ni textos, y el paquete neutro sin estado. **La guarda propia de «quién llama»** (como E5-2 hace con `describeNumber`) la he puesto en mi prueba (`ElevenLabsWebhookObserverTest::test_solo_la_salud_del_webhook_las_llama_y_cada_una_pide_su_ruta`), no en la de SEC8.

**Para `ops8-e` (mando):** con `VOICE_MONITOR_ENABLED` el programador gana **5** tareas de voz, no 4: `h8-voice-scope.sh:432` (`PROG_DELTA=4`/`-4`), su cabecera (`:18`, `:31`), el texto de `estado` («de voz: 4 con el monitor de voz encendido») y `banco-h8/ensayar-ops8c.sh:219-220` («+4 tareas de voz») tienen que pasar a 5; si no, `monitor on|off` dará un desvío falso («schedule:list da … y se esperaba …»). No he tocado esos ficheros (son de OPS8 y `ops8-e` los está editando).

## 6. Ejecutado (SQLite y PG) y cifras

Todo con `php -d memory_limit=2G`, un proceso pesado a la vez, `Http::preventStrayRequests()` en todas las pruebas de voz y ninguna petición real. La máquina estaba muy cargada por otros agentes (load ≈ 140–395): los tiempos de pared no miden el código.

**SQLite (`:memory:`):**

| Conjunto | Resultado |
|---|---|
| `tests/Feature/Telephony` (entera) | **426 pruebas, 202 171 aserciones; 132 omitidas (solo PG); 1 falla**, causada por mi clave aditiva: `VoiceCommandsTest::test_voice_doctor_telefonia_por_nombre_y_sin_valores` fijaba las claves del estado de la telefonía. La he actualizado (`webhookHealth` y la línea nueva del doctor) y repetida: **2 pruebas, 36 aserciones, OK** |
| `tests/Unit/Telephony` + `tests/Unit/Voice` + `tests/Feature/UltronStudio/UltronEngineIsolationTest.php` | **333 pruebas, 23 973 aserciones, 1 falla AJENA** (`VoiceIsolationTest::test_las_pruebas_de_voz_con_red_cierran_la_red`, por `tests/Feature/Telephony/Schema/VoiceSuppression1581Test.php` de otro agente: §7). `UltronEngineIsolationTest` sola: **9 pruebas, 1 515 aserciones, OK** (en una pasada anterior daba 2 rojos ajenos de `OutreachTables.php` y una migración del H9 sin seguimiento; en la última ya no) |
| `tests/Feature/Security/SecretExposure*` + `tests/Feature/Chaos/ChaosQueue*` + `tests/Feature/Voice` + `UltronStudioMonitorTest` + `UltronStudioLabLaneTest` | **112 pruebas, 995 aserciones, OK; 26 omitidas** (los E2E del H8, que solo corren con el arnés) |
| Las pruebas nuevas por separado | `VoiceContactsFlagTest` 6/70 · `SecretExposureVoiceKeysTest` + `SecretExposureTest` 26/335 · `ChaosQueueOrphanedReservationTest` + `ChaosQueueHealthTest` + monitor + carril del laboratorio 37/302 (con la fijación del carril ya actualizada) · `ElevenLabsWebhookObserverTest` + `VoiceWebhookHealthTest` 15/247: todo OK. Tras reforzarla (§4), `ChaosQueueOrphanedReservationTest` repetida: **7 pruebas, 40 aserciones, OK en SQLite y en PG** |

**PostgreSQL 14** (`docs/ultron-studio/ops/pgunit.sh`, `UNIT_DB=ultron_studio_h8e`, base creada para esta tanda en el clúster desechable de 55432):

| Conjunto | Resultado |
|---|---|
| `tests/Feature/Telephony` (entera, con las 132 solo-PG y ya con `VoiceCommandsTest` actualizada) | **426 pruebas, 204 060 aserciones, 1 falla AJENA**: `Schema/VoiceSchemaTest::test_down_sin_filas_borra_las_siete_tablas_y_sus_funciones_y_up_deja_el_mismo_esquema` («up() no dejó lo mismo que la primera vez»). La diferencia es la rama `requested_by_type = 'strategy'` de `vc_requester_chk`, que añade la migración SIN SEGUIMIENTO de otro agente `database/migrations/2026_11_13_100001_allow_strategy_requester_on_voice_calls.php` (V9 del H9): la prueba deshace y rehace solo la migración base de la voz y no vuelve a aplicar esa. No toco esquema ni migraciones. Todas mis pruebas nuevas y ajustadas de la telefonía pasan en PG |
| `tests/Unit/Telephony` + `tests/Unit/Voice` + `UltronEngineIsolationTest` + `ChaosQueueOrphanedReservationTest` + `ChaosQueueHealthTest` + `UltronStudioMonitorTest` + `UltronStudioLabLaneTest` + `tests/Feature/Voice` + `SecretExposureTest` + `SecretExposureVoiceKeysTest` | **445 pruebas, 24 968 aserciones, 26 omitidas (E2E sin arnés), 1 falla AJENA**: la misma `VoiceIsolationTest::test_las_pruebas_de_voz_con_red_cierran_la_red` (por `VoiceSuppression1581Test.php`, §7). En PG, los trabajos sembrados en `jobs` y las comparaciones enteras de `reserved_at` se comportan igual que en SQLite |

**Pint:** `vendor/bin/pint --test` pasa en todos mis ficheros salvo `routes/console.php` y `app/Services/IronGuard/ChannelHealthDetector.php`, que **ya fallaban en HEAD** con los mismos fixers (comprobado formateando las dos versiones: la diferencia son exactamente mis 10 y 65 líneas). Pint los reformateó al pasarlo; deshice ese reformateo ajeno para no mezclar estilo de otros en este diff.

**Mutaciones** (en una COPIA aislada del backend en el scratchpad, con `vendor` enlazado, para no tocar ficheros que otros agentes prueban a la vez; cada mutación se aplica a la copia, corre la prueba que debe cazarla y se restaura): **24/24 caen** en la pasada final.

| Brecha | Mutaciones (todas caen) |
|---|---|
| B-T4 (4) | el servicio sin la bandera en `register()`; el controlador valida antes que la bandera (`store`); la confirmación sin la bandera en el servicio; el estado sin `flags.contacts` |
| B-T17 (5) | sin las familias de la voz; sin una de las 13 de SEC7 (`N8N_WEBHOOK_SECRET`); sin mirar los logs; el valor sale en la salida; la familia entera (región y banderas como secretos: el `.env.example` real daría hallazgo) |
| B-T16 (7) | sin la condición del latido posterior; sin el margen ni el `retry_after`; la voz sin `watch_reserved`; `queue:health` no lo cuenta; el monitor no lo ve; IRON GUARD no lo mira; un incidente por carril en vez de por trabajo |
| B-T5 (8) | con el monitor apagado sale a la red; no compara la URL; no mira `is_auto_disabled` (R8-12); no abre el incidente; una lista truncada se da por ausente; el en seco guarda el veredicto; los webhooks sin `withoutRedirecting` (la caza la guarda de SEC8); los secretos se leen sin mirar el estado del proveedor |

**Cómo se llegó (sin maquillar):** la primera pasada dio 1/24 porque el arnés era inválido: con `vendor` enlazado, Composer cargaba `App\` y `Tests\` del árbol REAL y las pruebas corrían contra el código sin mutar (sin daño: nada se escribió en el árbol real). Con un bootstrap que antepone un cargador hacia la copia, 22/24: una superviviente era del arnés (la configuración seguía saliendo del árbol real porque `TestCase` infiere la base desde Composer; se arregla con `APP_BASE_PATH`) y la otra era un **hueco real de mi prueba** (el segundo huérfano estaba en otro carril y no distinguía «por trabajo» de «por carril»), que reforcé (§4). Con las dos cosas, 24/24. El guion está en el scratchpad de la sesión (`mutaciones.py` y `mut-bootstrap.php`), fuera del repositorio.

## 7. Lo no verificado y lo pendiente

**No verificado (y por qué):**
- **Las rutas y formas reales de ElevenLabs** (`/v1/workspace/webhooks`, `/v1/convai/secrets?page_size=100`, `webhook_id`, `webhook_url`, `is_disabled`, `is_auto_disabled`, `auth_type`, `secret_id`, `next_cursor`): no he hecho ninguna petición (encargo «sin red»). Me apoyo en lo que OPS8-c comprobó en la referencia oficial el 09-11-2026 (`ops/h8-secrets-register.sh:4-9`) y en la sonda del lead (`ULTRON_STUDIO_PROVEEDORES_ESTADO_REAL.md:14-15`). Que `page_size=100` sea el máximo admitido está sin confirmar; si hubiera más secretos, la orden lo dice (`secrets_list_truncated`, incidente medio) en vez de afirmar que falta. La primera pasada real (fase 2, con `--dry-run`) lo confirma.
- **Que la clave de producción pueda LEER los webhooks y los secretos** (el registro de OPS8-c prevé una clave temporal con `convai_write`/`webhooks_write` para ESCRIBIR; para leer, sin confirmar). Si no puede, la orden dará `webhooks_unreadable`/`secrets_unreadable` (incidente medio), no un falso «coincide».
- **El E2E de la 4b** con `VOICE_CONTACTS_ENABLED` (`H8E2ESalientesPrimeroTest`): solo corre con el arnés `h8-e2e.sh` y la pila real; no lo he ejecutado.
- **Producción:** nada desplegado ni ejecutado allí. Con las banderas de hoy (monitor de voz apagado), en producción solo cambian: el 409 del alta de contactos, `flags.contacts`/`webhookHealth` en el estado, la línea nueva del doctor, las claves nuevas de `security:check-secret-exposure` (y los logs) y la detección de huérfanos en `queue:health`/monitor/IRON GUARD.

**Riesgos que conviene saber antes de desplegar:**
- `security:check-secret-exposure` puede **empezar a fallar en el servidor** si allí hay un fichero `.env*` legible por «otros» con alguna de las claves nuevas, o un log legible con un volcado del entorno: sería un hallazgo verdadero (el paso del despliegue que la corre lo marcaría como desvío). Antes de desplegar, conviene correrla en el servidor en seco.
- La orden lee completos los logs legibles por cualquiera (`channel` y `voice` en 0664, 30 días): el coste crece con ellos (medido en local: 57 MB en 0,47 s de CPU).
- `GET admin/voice/status` hace una lectura más de la caché (`voice:webhook:health`); con la caché `database` de producción es una sentencia más, como la observación del número.
- El mando de `ops8-e` espera 4 tareas de voz con el monitor: ver §5.

**Pendiente, con dueño:**
- `security`: visto bueno de la ampliación de las guardas (§5) y de B-T4/B-T17; decidir si un webhook desactivado (`webhook_auto_disabled`) debe además echar el FRENO de voz (hoy solo abre incidente, como pide el encargo; R8-12 dice que sin el webhook una violación ya no frena).
- `ops8-e` / OPS8: `PROG_DELTA` y textos de 4 → 5 tareas de voz (§5); el runbook de la fase 2 debería registrar el webhook y los secretos (`h8-secrets-register.sh`) ANTES de `monitor on`, o la primera pasada abrirá `voice_webhook_unhealthy` por referencias que faltan (es un aviso verdadero, pero esperable).
- `frontend`: la pestaña Contactos del CRM puede leer `flags.contacts` (no ofrecer alta ni carga con la bandera apagada) y el panel de voz `webhookHealth`.
- `devops`/`security`: los logs `channel` y `voice` en 0660 en lugar de 0664 (§2).
- `documentation-specialist`/DOC8: `ULTRON_STUDIO_H8_VOZ.md` §8 (la orden nueva) y §10 (`VOICE_CONTACTS_ENABLED`), la matriz `ULTRON_STUDIO_H8_COMPLETITUD.md:52` (la salud del webhook deja de estar «PD») y R8-12.
- Lead: activar `watch_reserved` en los demás carriles (una línea cada uno) si se quiere; los tres pedidos ya lo tienen.

**Rojos que NO son de esta tanda (comprobado que vienen de trabajo ajeno en curso):**
- `UltronEngineIsolationTest::test_fuera_del_studio_nadie_nombra_sus_clases_sus_tablas_ni_su_configuracion` y `::test_hito_9_los_lectores_de_facts_y_el_puente_solo_leen_sus_tablas`: los provocan `app/Services/Outreach/Run/OutreachTables.php` (modificado por otro agente) y la migración sin seguimiento `database/migrations/2026_11_13_200001_create_ultron_studio_template_meta_checks_table.php` (tabla `ultron_studio_template_meta_checks`). No los he tocado (Outreach está fuera de mi alcance).
- `VoiceIsolationTest::test_las_pruebas_de_voz_con_red_cierran_la_red`: lo provoca `tests/Feature/Telephony/Schema/VoiceSuppression1581Test.php`, un fichero nuevo sin seguimiento de otro agente (la supresión 1581 de la voz, B-T3) que no llama a `Http::preventStrayRequests()`. Su dueño tiene que añadirlo; no lo he tocado.
- PG: `Schema/VoiceSchemaTest::test_down_sin_filas_borra_las_siete_tablas_y_sus_funciones_y_up_deja_el_mismo_esquema`: lo provoca la migración sin seguimiento `2026_11_13_100001_allow_strategy_requester_on_voice_calls.php` (V9 del H9, otro agente), que amplía `vc_requester_chk`; la prueba solo rehace la migración base. Su dueño (o `database-specialist`) tiene que adaptar la prueba o la migración; no lo he tocado.

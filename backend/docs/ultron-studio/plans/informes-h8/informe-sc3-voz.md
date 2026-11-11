# ULTRON STUDIO · cierre · `backend` (sc3-voz): los hallazgos de VOZ de la revisión de seguridad del cierre (SC-3, SC-11, SC-12 y SC-13)

**Fecha:** 10-11-2026. **Árbol:** backend `_worktrees/ultron-h8/Iron-Body/backend` y CRM `_worktrees/ultron-h8/Iron-Body_Front`, rama `feat/ultron-studio-h9`, sobre `d701b0c` (backend) y `9572426` (CRM). **Sin commit, sin push, sin despliegue, sin producción, sin red real (ni ElevenLabs ni Twilio), sin secretos impresos.** En el árbol hay mucho trabajo ajeno sin confirmar; solo he tocado los ficheros de §1 y, de los que ya venían modificados, solo las líneas que digo (los diffs de §8 son de lo mío y solo de lo mío). Rutas relativas a `backend/` salvo las del CRM.

**Encargo:** `plans/informes-h9/informe-sec-cierre.md` — SC-3 con su decisión de §6.3 («SÍ, frenar»), SC-11, SC-12 y SC-13 (opcional); contexto: `informe-backend-h8e.md` (B-T5 y B-T17) y la Enmienda 17 del contrato H8.

## 0. Resumen

| Hallazgo | Estado | Lo esencial |
|---|---|---|
| **SC-3** (mayor; R8-12) | **Hecho** | Un problema DEFINITIVO del webhook post-llamada o de los secretos (7 códigos) echa el FRENO de voz con el motivo nuevo **`webhook_unhealthy`** + su incidente alto; lo INCIERTO (4 códigos) y lo que falta en Laravel (4) solo abren su incidente, sin freno. Se suelta por la vía de siempre (una persona con `ultron.voice_publish` y nota); si después de soltarlo el problema sigue, la comprobación siguiente lo vuelve a echar (`:r2`, la regla de las derivas). Migración nueva `2026_11_14_100001` (PG: DROP + ADD de `vbr_reason_chk` en una sentencia, `lock_timeout`, recuentos; SQLite: nada). Texto del motivo en el CRM. La puerta de salientes niega `voice_braked` (probado) |
| **SC-11** (menor) | **Hecho** | La guarda de «quién llama» a `describeWebhooks`/`describeSecrets` vive ahora también en `VoiceIsolationTest` (tokens, `app` + `routes`, también cierres de consola, cadenas y `?->`), y fija la ruta de cada GET |
| **SC-12** (menor) | **Hecho** | El rastreo de logs ve también `CLAVE=valor` en mitad de línea, `"CLAVE":"valor"` (JSON, también escapado) y los volcados de PHP; sin falsos positivos con vacíos, ejemplos o claves parecidas; una línea que PCRE no puede revisar ya no pasa por limpia; la salida (texto y JSON) dice qué formatos NO mira |
| **SC-13** (menor, opcional) | **Hecho** | Índice único parcial `vc_one_call_per_strategy_job` (`voice_calls (client_request_id) WHERE requested_by_type = 'strategy'`), en los dos motores, migración `2026_11_14_100002` |

**Verificado (§6):** regresión de los conjuntos pedidos en SQLite y en PostgreSQL 14, Pint, **33/33 mutaciones** cazadas (en una copia aislada), specs del CRM y `tsc`. **Para el lead, tres cosas que cambian la lectura de §6.3** (detalle en §2.5): (1) el freno **también** cierra las entrantes y las herramientas, no solo las salientes; (2) `webhook_auth_not_hmac` frena también si ElevenLabs no informa `auth_type` (falla cerrado); (3) lo que falta en Laravel queda en incidente **alto**, como hoy, no medio.

## 1. Ficheros

| Fichero | Estado antes | Qué |
|---|---|---|
| `app/Models/Voice/VoiceBrakeEntry.php` | sin cambios | `REASON_WEBHOOK_UNHEALTHY` y el séptimo motivo en `REASONS` (`:15-31`) |
| `database/migrations/2026_11_14_100001_allow_webhook_unhealthy_reason_on_voice_brakes.php` | **nuevo** | SC-3: amplía `vbr_reason_chk` (§3.1) |
| `app/Services/Telephony/VoiceWebhookHealth.php` | nuevo de B-T5, sin commit | SC-3: `BRAKING`, el freno, en seco (§2) |
| `app/Console/Commands/Voice/VoiceWebhookHealthCommand.php` | nuevo de B-T5, sin commit | La línea «Freno de voz: …», la descripción y `--dry-run`. **No estaba en mi lista**: lo toqué porque su comportamiento documentado cambia (ahora frena) y sin la línea un operador no sabría que lo echó |
| `app/Console/Commands/CheckSecretExposure.php` | modificado por B-T17, sin commit | SC-12 (§4) |
| `database/migrations/2026_11_14_100002_add_one_call_per_strategy_job_index_on_voice_calls.php` | **nuevo** | SC-13 (§5) |
| `tests/Feature/Telephony/VoiceWebhookHealthBrakeTest.php` | **nuevo** | 10 pruebas de SC-3 |
| `tests/Feature/Telephony/Schema/VoiceBrakeReasonCheckTest.php` | **nuevo** | 6 pruebas de la migración de SC-3 (4 solo PG, 1 solo SQLite) |
| `tests/Feature/Telephony/Schema/VoiceStrategyJobIndexTest.php` | **nuevo** | 4 pruebas de SC-13 (1 solo PG) |
| `tests/Feature/Security/SecretExposureLogFormatsTest.php` | **nuevo** | 5 pruebas de SC-12 |
| `tests/Unit/Voice/VoiceIsolationTest.php` | modificado por B-T5, sin commit | SC-11: SOLO AÑADO dos constantes y una prueba; no toco nada de lo que había (tampoco las marcas «PENDIENTE DEL VISTO BUENO DE SEC8»: mi encargo en este fichero era solo añadir la guarda) |
| `tests/Feature/Telephony/VoiceWebhookHealthTest.php` | nuevo de B-T5, sin commit | Dos `Incident::query()->sole()` ahora ven también el incidente del freno: los acoto por `kind` y **añado** las aserciones del freno (más estrictas, no menos) |
| `tests/Feature/Telephony/Schema/VoiceSchemaTest.php` | modificado por V9, sin commit | SUMO, sin quitar nada: la ida y vuelta vuelve a aplicar las dos migraciones nuevas (como ya hacía con la de V9) y los índices parciales pasan de 3 a 4 (el de SC-13) en las dos tablas esperadas y en la cabecera |
| CRM `src/app/modules/ultron-studio/studio-voice-text.ts` | modificado por otros, sin commit | El texto de `webhook_unhealthy` en `MOTIVOS_DE_FRENO` (`:350-353`); el resto del fichero, intacto |
| CRM `src/app/modules/ultron-studio/studio-voice-brake-reason.spec.ts` | **nuevo** | 4 pruebas del texto y del lector |

No he tocado: `OutboundGate.php` (no hacía falta: ya niega por el freno, `OutboundPolicy.php:142-143`), `VoiceBrake.php`, la plantilla de voz, el compilador, `app/Services/Outreach/**`, los vales, `routes/**`, las guardas del H9 ni `docs/ultron-studio/ops/**`.

## 2. SC-3 · R8-12: un webhook definitivamente mal echa el freno

### 2.1 Qué frena y qué no (`VoiceWebhookHealth::BRAKING`, `:138-158`)

| Clase | Códigos | Incidente `voice_webhook_unhealthy` | Freno |
|---|---|---|---|
| **DEFINITIVOS** (§6.3) | `webhook_not_found`, `webhook_url_mismatch`, `webhook_disabled`, `webhook_auto_disabled`, `webhook_auth_not_hmac`, `tool_secret_not_found`, `initiation_secret_not_found` | alto (como hoy) | **SÍ**, `webhook_unhealthy`, con su incidente `voice_brake` (alto) |
| **INCIERTOS** (`UNCHECKED`, sin cambios) | `provider_not_ready`, `webhooks_unreadable`, `secrets_unreadable`, `secrets_list_truncated` | medio (como hoy) | no |
| **Lo que falta en Laravel** | `base_url_invalid`, `webhook_ref_missing`, `tool_secret_ref_missing`, `initiation_secret_ref_missing` | **alto (como hoy)** | no (§6.3 los pone entre los que no frenan) |

Con una mezcla, frena si hay al menos uno definitivo (p. ej. `webhook_auto_disabled` + `secrets_unreadable`). `--dry-run` no frena: dice si lo haría.

### 2.2 Cómo (`VoiceWebhookHealth::run()`, `:172-241`, y `brakingProblems()`, `brakeKey()` y `brake()`, `:243-290`)

- **Instante de la observación** (`:187-188`): se toma ANTES de los dos GET. Es lo que compara la regla de rearmado.
- **Orden** (`:233-236`): primero el incidente del webhook (no lanza nunca, como hasta ahora), después el freno, después el veredicto en caché y el registro. El freno **no atrapa nada**: si no se puede echar (la base cae), la orden falla a la vista (código ≠ 0, la excepción en el programador) en vez de callarse.
- **Apertura:** `VoiceBrake::openOrRearm('webhook_unhealthy', clave, evidencia, VoiceBrake::observedAfter($observadaEn))`, el mismo mecanismo de las derivas (E10-6):
  - **clave** = `webhook:` + 32 hex de `sha256` de los problemas DEFINITIVOS ordenados (`brakeKey()`, `:258-269`). La misma avería → la misma clave → idempotente mientras siga echado (3 pasadas = 1 apertura y 1 aviso del freno). Otra combinación de definitivos → otra apertura. Lo incierto **no entra en la huella**: que la lectura de los secretos falle a ratos junto a un webhook desactivado no abre aperturas nuevas cada hora. Hash y no texto: siete códigos juntos pasarían de los 128 de `open_key`.
  - **rearmado:** soltado por una persona, la comprobación SIGUIENTE que vea el mismo problema lo vuelve a echar (`<clave>:r2`, con `rearmOf`); la que ya estaba en vuelo cuando se soltó, no (su observación es anterior a la suelta). Arreglado el webhook, no vuelve. Sin rearmado (`open()`), un webhook que se desactiva otra vez una semana después no frenaría nunca: por eso `openOrRearm`.
  - **evidencia:** `{where: 'webhook_health', codes: [<definitivos>], incidentId: <id del incidente del webhook>}`: códigos y un id, nunca URL, ids del proveedor ni secretos (probado con centinelas en la orden, la apertura, su incidente y el listado del CRM). El CRM ya enseña `evidence.codes`.
- **Soltarlo:** la vía de siempre, sin cambios (`VoiceBrake::release` / `POST admin/ultron-studio/voice/brakes/{uuid}/release`, `ultron.voice_publish` + nota). Probado por la ruta del CRM.

### 2.3 Contrato

- **`voice:webhook-health --json`** (aditivo): clave nueva `brake`: `null` si nada frena; si no, `{reason: "webhook_unhealthy", problems: [...], opened: bool, uuid: ?string, engaged: bool}` (`opened`: esta pasada abrió una apertura; `engaged`: el freno queda echado, por esta u otra causa). En seco: `{…, opened: null, uuid: null, wouldOpen: bool, engaged: bool}`. Con la bandera apagada, `brake: null`. El código de salida no cambia (0 coincide / 1 no).
- **Texto de la orden:** tras «No coincide: …», una línea «Freno de voz: ECHADO (webhook_unhealthy · <códigos>; apertura <uuid>). Lo suelta una persona con «Publicar la voz» y una nota, después de revisar las llamadas hechas sin la guarda.» (o «sin apertura nueva…», «EN SECO, lo echaría…», «no se echa (solo frena un problema definitivo: …)»).
- **Sin cambios:** `GET admin/voice/status` (`webhookHealth` sigue siendo el veredicto guardado; el freno ya sale en `brakeOpen`), `voice:doctor --telefonia`, `GET admin/ultron-studio/voice/brakes` (forma igual; puede traer `reason: "webhook_unhealthy"`), la programación (las mismas 5 tareas de voz), `VoiceBrake::TRIGGERS` (las causas del fixture `policy-tables.json` §brake siguen siendo seis: `webhook_unhealthy` es un motivo directo de la base, `VoiceBrake::open()` lo admite por `REASONS`; `TelephonyFixtureParityTest` no cambia).
- **CRM:** `brakeReasonText('webhook_unhealthy')` = «Webhook post-llamada o secretos de ElevenLabs caídos o distintos de los de Laravel» (sale en la fila del freno, en «Soltar el freno: …» y en «El freno de voz está echado por …»). `VOICE_BRAKE_REASONS` sigue siendo el conjunto de DB8 (paridad con `testing/backend-voice.testing.ts`, generado: «No editar a mano»); el lector ya admitía cualquier motivo por su código (`VoiceBrakeReasonCode`), así que un servidor con el freno nuevo y un CRM sin este cambio enseñarían «Motivo: webhook_unhealthy», nunca un freno escondido.

### 2.4 Pruebas (`tests/Feature/Telephony/VoiceWebhookHealthBrakeTest.php`, 10)

Las **dos negativas del informe**: `webhook_auto_disabled` → apertura `webhook_unhealthy` con su clave y evidencia, incidente del webhook alto + incidente `voice_brake` alto, y **la puerta de salientes pasa de `['calls_disabled_in_code']` a `['calls_disabled_in_code', 'voice_braked']`** (`POST /api/admin/voice/calls` contra un canario de voz activo con publicación, verificación de ElevenLabs y conciliación del número frescas: todo abierto salvo la constante del código; la vía de estrategia, `requestForStrategy`, lee el mismo hecho, `OutboundGate.php:472`, y sus pruebas de V9 ya fijan `voice_braked` con cualquier motivo: `V9StrategyGateTest:129`, `V9OpenGateTest:201`); `webhooks_unreadable` (+ `secrets_unreadable`) → un solo incidente, medio, ninguna apertura y la puerta sin `voice_braked`. Además: los 7 definitivos, cada uno con su apertura (incluido `auth_type` ausente); los 4 inciertos y los 4 de Laravel sin freno, con su gravedad de hoy; mezcla definitivo + incierto y la huella que solo mira lo definitivo; idempotencia (3 → 1), suelta por la ruta del CRM **durante** una comprobación en vuelo (el reloj avanza dentro de la lectura) sin reapertura, reapertura `:r2` con `rearmOf` en la siguiente, y sin freno una vez arreglado; en seco (`wouldOpen`, nada escrito, y «no abriría otra» con uno echado); monitor apagado (sin red ni freno); y el texto de la orden.

### 2.5 Lo que el lead tiene que saber (decisiones y matices)

1. **El freno no solo para las salientes.** §6.3 dice «el freno solo lo leen la puerta de salientes y el job (las entrantes no lo miran)». No es así en el código: lo leen la puerta de salientes (`OutboundGate.php:147` y `:472`, las dos vías), el job (`PlaceOutboundVoiceCall.php:179`), **la entrada** (`InboundGate.php:113` → `InboundPolicy.php:75-76`: `voice_braked`, la entrante del canario se deniega) y **las herramientas** (`VoiceTools.php:66-67`: se niegan con `voice_braked`). Es el comportamiento de cualquier freno desde DB8 (lo dice el docblock de `VoiceBrake`), no lo he cambiado. La razón de §6.3 vale igual para las entrantes (sin webhook, tampoco a ellas les corre la guarda), pero el radio es mayor del que se evaluó: un webhook desactivado deja también sin entrantes a los canarios. Y si hay una llamada EN CURSO en el instante en que se echa, sus herramientas dejan de responder, incluida `no_volver_a_llamar` (preexistente para todos los motivos; la ventana es una llamada por `max_concurrent_calls = 1` y el freno lo echa una comprobación horaria). Si se quiere un freno «solo salientes» es otro diseño (otro motivo que `InboundPolicy` y `VoiceTools` ignoren): decisión del lead/`security`, no lo he hecho.
2. **`webhook_auth_not_hmac` frena también si el proveedor no dice la autenticación.** B-T5 decidió que «solo `hmac` es HMAC» (`auth_type` ausente o ilegible → el problema). Lo mantengo como definitivo, tal como lo lista §6.3: falla cerrado, como el resto del H8 ante lo que no puede comprobar. Consecuencia: si la respuesta real de `/v1/workspace/webhooks` no trae `auth_type`, `monitor on` frenaría cada hora. Mitigación: en la fase 2, `voice:webhook-health --dry-run` ANTES de `monitor on` (dice `brake.wouldOpen` sin escribir nada). La forma real de la respuesta sigue sin verificar (§7).
3. **Lo que falta en Laravel queda en alto.** §6.3 los mete en «no frenan (solo el incidente medio de hoy)», pero hoy son ALTOS (no están en `UNCHECKED`). No frenan, como pide §6.3; no bajo su gravedad (sería relajar un aviso): son fallos de configuración que no se arreglan solos.
4. **No hecho (recomendado en §6.3, no encargado):** «una llamada terminada sin post-llamada en 15 min → el mismo freno». Con la comprobación horaria, la ventana a ciegas puede llegar a una hora.
5. **Evidencia del canario** (`VoiceEvidence::forPublication`): no cuenta las aperturas `webhook_unhealthy` como deriva ni como gasto; no hace falta: las llamadas sin post-llamada ya bloquean la promoción (`missingPostCall`).

## 3. Migraciones

| Migración | PostgreSQL | SQLite (la suite) | Reversible |
|---|---|---|---|
| `2026_11_14_100001_allow_webhook_unhealthy_reason_on_voice_brakes` | Amplía `vbr_reason_chk` | Nada (como V9) | Sí; `down()` se niega con aperturas del motivo nuevo |
| `2026_11_14_100002_add_one_call_per_strategy_job_index_on_voice_calls` | Crea el índice | Crea el MISMO índice | Sí; `down()` lo quita (idempotente) |

### 3.1 `2026_11_14_100001` (SC-3), el patrón exacto de V9 (`2026_11_13_100001`)

- **PG:** dentro de `DB::transaction` (un SAVEPOINT bajo la transacción del migrador): `lock_timeout` 5 s si la sesión trae 0 (si trae otro, manda ese); **recuento antes**: exactamente un `vbr_reason_chk` y se niega si ya admite `webhook_unhealthy` (no amplía dos veces); **una sola sentencia** `ALTER TABLE voice_brakes DROP CONSTRAINT vbr_reason_chk, ADD CONSTRAINT vbr_reason_chk CHECK (reason IS NULL OR reason IN ('guard_violation', 'seal_mismatch', 'agent_drift', 'cost_cap', 'subaccount_alert', 'unknown_provider_call', 'webhook_unhealthy'))` (los seis de DB8 literales y en su orden); **recuento después**: uno con ese nombre, el mismo número de CHECK en `voice_%` (51) y el motivo dentro; si no, falla y no se registra.
- **`down()`:** se niega (`RuntimeException`, sin tocar nada) si hay aperturas `webhook_unhealthy` (el freno es de solo inserción: no se borran); sin ellas, restaura el CHECK **exacto** de DB8 leyéndolo de su migración (`rowChecks()`), con el mismo recuento. Producción no revierte nunca (protección 11).
- **Cerrojo:** ACCESS EXCLUSIVE sobre `voice_brakes` lo que dura validar las filas (una tabla diminuta); con otra transacción sobre la tabla, cae limpia (55P03) en el `lock_timeout`, probado.
- **SQLite:** `return` (los CHECK de forma de DB8 son solo de PG; el conjunto cerrado lo vigila `VoiceBrake::open()` con `VoiceBrakeEntry::REASONS`, probado en los dos motores).
- **Pruebas** (`VoiceBrakeReasonCheckTest`, 6): el modelo y el servicio admiten los 6 + 1 y ninguno más (los dos motores; `TRIGGERS` sigue con seis); en SQLite no toca el esquema; en PG el CHECK admite los 7, rechaza `manual`, `webhook_down`, mayúsculas y un espacio (23514), y una suelta sigue entrando; `down()` se niega con una fila y no la borra; sin filas deja el CHECK **idéntico** al de una DB8 recién creada (down + up de DB8 dentro de la transacción) y `up()` lo vuelve a ampliar igual; **un solo `ALTER TABLE` con DROP y ADD** en `up()` y en `down()` (registro de consultas); se niega a ampliar dos veces o sin el CHECK; y el cerrojo (55P03 en 200 ms con otra conexión sosteniendo ACCESS SHARE; con `lock_timeout = 0`, pone 5 s).

### 3.2 `2026_11_14_100002` (SC-13): ver §5.

## 4. SC-12 · `security:check-secret-exposure` y los logs

**Qué había** (`CheckSecretExposure.php` de B-T17): en los logs, solo `CLAVE=valor` al PRINCIPIO de una línea (el patrón de los `.env`), con un filtro previo «la línea tiene `=`»; un fallo de PCRE (`preg_match_all` → `false`) contaba como «nada».

**Qué hay ahora** (solo para los LOGS; los ficheros de entorno siguen exactamente con el patrón de antes):

| Líneas | Cambio |
|---|---|
| `:94-112` | `LOG_FILES`, `LOG_FORMATS` (lo que reconoce) y `LOG_NOT_COVERED` (lo que NO mira), una sola verdad para la salida y las pruebas |
| `:114-163` | La salida lo dice SIEMPRE, también en verde: dos líneas «Logs: solo storage/logs/*.log legibles por cualquiera. Reconoce: …» y «NO mira: …»; el JSON gana `logScan: {files, formats, notCovered}` (aditivo; los despliegues solo miran el código de salida) |
| `:242-275` | Un log con líneas que no se pudieron revisar sale como hallazgo («log legible por cualquier usuario que no se pudo revisar entero», o el de credenciales) con `unscannedLines`: un fallo de PCRE ya no pasa por limpio |
| `:337-384` | `realSecretKeysInLogLines()`: filtro previo por la ÚLTIMA palabra de las claves vigiladas (`KEY`, `PASSWORD`, `TOKEN`, `SECRET`, sacadas de las mismas listas) y cuatro patrones |
| `:428-462` | `logLinePatterns()`: (1) el del entorno (principio de línea); (2) `CLAVE=valor` en cualquier punto, con la clave ENTERA (no pegada a `[A-Za-z0-9_$]`: `PUSHER_APP_KEY` no es `APP_KEY`, `$APP_KEY` es una variable), nunca `==` ni `=>`, el valor entre comillas (con o sin espacios alrededor del `=`) o, sin comillas, **pegado al `=`**; (3) `"CLAVE":"valor"`, con o sin espacios y con las comillas escapadas de un JSON dentro de otro (se quita la barra final del valor); (4) `'CLAVE' => 'valor'` y `[CLAVE] => valor` |
| `:418-426` y `:464-519` | `secretAssignmentPattern()` (el mismo patrón de antes, ahora con) `keyAlternation()` (compartida con el patrón del entorno, que sale idéntico), `keyTerminals()`, `containsAny()`, `isRealValue()` (vacío o de `PLACEHOLDERS` = ejemplo; el mismo criterio que antes, ahora en un sitio) |

**Lo que dice que NO mira:** en mitad de la línea, `CLAVE = valor` sin comillas y separado del `=` (lo tomaba al principio y daba un falso positivo real: en `faltan ELEVENLABS_API_KEY= y DB_PASSWORD=` cogía «y» como valor; lo cazó mi propia prueba); un valor partido en varias líneas o codificado (base64, URL, `\u…`); `CLAVE: valor` (YAML, cabeceras) y `Authorization: Bearer …`; claves en minúsculas, con otro nombre o con prefijo propio; y otros ficheros (rotados `*.log.1`, comprimidos `*.gz`, subdirectorios, fuera de `storage/logs`). Una prueba fija que de verdad no los mira (si un día se cubren, cambian la lista y la prueba juntas).

**Pruebas** (`SecretExposureLogFormatsTest`, 5, en una base de la aplicación desechable como las de B-T17): 11 formas reales con centinelas (JSON de Monolog, mitad de línea, JSON escapado, `var_export`, `print_r`, el `Env` de un `docker inspect`, JSON con espacios, `env -i` con una vacía delante, comillas con espacios, `CLAVE = "valor"` en mitad y `CLAVE = valor` al principio) → los 11 nombres y ningún valor; en 0640 → nada. Sin falsos positivos: vacíos, `null`, `changeme`, `your_`, `xxx`, `tu_`, `placeholder` en cada forma; nombres sueltos; `PUSHER_APP_KEY`, `VITE_PUSHER_APP_KEY`, `X_DB_PASSWORD`, `MY_OPENAI_API_KEY`, minúsculas, variables `$APP_KEY`, ids y banderas de la voz, `*_REF`, comparaciones `==` y `=>` sin comillas. Lo no cubierto, de verdad no cubierto. La nota en verde y con hallazgo, y el JSON exacto. Una línea que PCRE no puede revisar (sin JIT y con `pcre.backtrack_limit = 1`, por reflexión sobre el método de los logs para no romper las expresiones del propio framework) sale como hallazgo con `unscannedLines = 1`; con PCRE normal, la misma línea está limpia.

**Coste y árbol real:** la orden entera sobre los 58 MB de logs locales: 0,18 s de CPU de usuario (0,22 s de pared, tres pasadas), y `ok: true` (ni un hallazgo en el árbol real, así que `SecretExposureTest::test_no_file_with_real_secrets_is_world_readable` sigue en verde). Antes de escribirla, una sonda en el scratchpad (con una versión anterior y más laxa de los patrones) sobre esos logs: 0 coincidencias en los cuatro formatos y 0 fallos de PCRE.

**No hecho (de SC-12):** los logs a 0660 (operación: `devops`); mirar los rotados o comprimidos.

## 5. SC-13 · una llamada por trabajo, también en el esquema

**Por qué es limpio:** la puerta (`OutboundGate::decideForStrategy`, `:441-448`) ya busca la fila del trabajo bajo su cerrojo consultivo y, si existe, devuelve 200 `duplicate` sin insertar; las filas de estrategia son siempre `attempt = 1` (`vc_requester_chk` de V9). El índice no cambia ningún camino válido: solo hace imposible una segunda fila del mismo trabajo si algo se saltara el cerrojo, y en ese caso la puerta ya convierte la violación de un UNIQUE en 409 sin crear nada (`OutboundGate.php:383-385`; su mensaje dice «otra petición a ese destino», impreciso para este caso pero inalcanzable con el cerrojo). Ninguna prueba existente insertaba dos filas de estrategia del mismo trabajo.

**La migración** (`2026_11_14_100002`), en los **dos motores** y con el mismo texto (como los parciales de DB8): `CREATE UNIQUE INDEX vc_one_call_per_strategy_job ON voice_calls (client_request_id) WHERE requested_by_type = 'strategy'`. Antes se niega si ya existe o si hay algún trabajo con más de una llamada de estrategia (lo dice con su recuento; no borra nada). Recuento de índices parciales en `voice_%`: +1 al crear, −1 al quitar (si no, falla y no se registra). PG: `lock_timeout` 5 s si la sesión trae 0; `CREATE INDEX` toma SHARE (para las escrituras de `voice_calls` mientras se construye: hoy está vacía en producción) y `DROP INDEX` ACCESS EXCLUSIVE; no `CONCURRENTLY` (no cabe en la transacción del migrador). `down()`: `DROP INDEX IF EXISTS`, idempotente.

**Pruebas** (`VoiceStrategyJobIndexTest`, 4): existe en los dos motores con su predicado (PG lo reescribe); dos filas del mismo trabajo → la segunda se rechaza (23505 en PG con el nombre del índice; 23000 en SQLite), también si la primera ya terminó; otro trabajo, o una persona con el mismo `client_request_id`, entran; `up()` se niega si ya existe o con un trabajo de dos llamadas y no borra nada; ida y vuelta; `down()` dos veces; cerrojo en PG (55P03; 5 s por defecto).

**Recuentos que suben (dicho, sin quitar nada):** `VoiceSchemaTest`: índices únicos parciales de la voz de 3 a 4 en las dos tablas esperadas (`PARTIAL_INDEXES_PG` y `_SQLITE`) y en la cabecera; los 51 CHECK y los 27/21 disparadores no cambian. `VoiceSuppression1581Test` no fija índices (cuenta disparadores y compara restricciones dentro de la prueba): sin cambios, en verde en PG.

## 6. Verificación (ejecutado)

Todo desde `backend/` con `php -d memory_limit=2G`, `--no-logging --do-not-cache-result`, un proceso pesado a la vez, `Http::preventStrayRequests()` en todas las pruebas de voz nuevas y ninguna petición real. PostgreSQL 14 desechable (`127.0.0.1:55432`, base `ultron_studio_sc3`, creada para esta tanda y borrada al terminar; se recrea con `PGGSSENCMODE=disable createdb -h 127.0.0.1 -p 55432 -U ironbody ultron_studio_sc3`) con `docs/ultron-studio/ops/pgunit.sh`.

| Motor | Conjunto | Resultado |
|---|---|---|
| SQLite (`:memory:`) | `tests/Feature/Telephony` (entera: los 3 ficheros de prueba nuevos de voz, `VoiceWebhookHealthTest`, `VoiceSchemaTest`, `TelephonyIsolationTest`, la 1581…) | **OK: 445 pruebas, 203 448 aserciones, 137 omitidas** (solo PG) · 44 s |
| SQLite | `tests/Unit/Telephony` + `tests/Unit/Voice` + `tests/Feature/Voice` + `tests/Feature/Security` + `tests/Feature/Outreach/V9` + `UltronEngineIsolationTest` + las 25 del Studio que nombran Voice (`tests/**/UltronStudio/**/*Voice*Test.php`) | **OK: 1 239 pruebas, 57 501 aserciones, 128 omitidas** (solo PG y los E2E del H8 sin su arnés) · 31 s |
| PostgreSQL 14 | `tests/Feature/Telephony` (entera; con las solo-PG: los CHECK, la ida y vuelta de `VoiceSchemaTest`, la 1581 con `psql`, los cerrojos) | **OK: 445 pruebas, 205 434 aserciones, 1 omitida** (la de solo SQLite) · 40 s |
| PostgreSQL 14 | el mismo segundo bloque | **OK: 1 239 pruebas, 57 940 aserciones, 53 omitidas** (las de solo SQLite y las que exigen su arnés, como los E2E del H8; no las he desglosado una a una) · 53 s |

Antes, por separado y en los dos motores: los 4 ficheros de prueba nuevos, `VoiceWebhookHealthTest`, `tests/Feature/Telephony/Schema` + `V9RequesterCheckTest` (190 pruebas: SQLite 1 445 aserciones/140 omitidas; PG 3 470/2 omitidas) y `tests/Feature/Security` (110/1 030), todo en verde. Rojos: **ninguno** en los conjuntos corridos (los ajenos que vio B-T5 —`VoiceIsolationTest` por la 1581 y `VoiceSchemaTest` en PG por V9— ya estaban arreglados por sus dueños).

**Pint:** `vendor/bin/pint --test` en mis 13 ficheros PHP → `passed` (Pint solo cambió comillas en la migración de SC-13 y una referencia `{@see}` con nombre completo en el docblock del modelo, que dejé como texto para no importar un servicio en un modelo).

**Mutaciones: 33/33 caen** (guion en el scratchpad, `mutaciones_sc.py`), en una **copia aislada** del backend (con `vendor/composer`, `vendor/autoload.php` y `vendor/phpunit` copiados y el resto enlazado: comprobé con una prueba sonda que las clases, las pruebas y `base_path()` salen de la copia, no del árbol real), para no enseñar ficheros mutados a los agentes que prueban en el árbol compartido. Línea base sin mutar en verde (SQLite 33 pruebas; la guarda de SC-11; PG 10). SC-3 (16): sin freno; lo ilegible frena; `webhook_auto_disabled` no frena; lo de Laravel frena; en seco frena; nunca se rearma; la observación cuenta desde el final de la lectura; la huella mezcla lo incierto; el modelo sin el motivo; la orden no lo dice; el CHECK pierde un motivo de DB8; `down()` no se niega con filas (PG); `up()` amplía dos veces (PG); `down()` no restaura DB8 (PG); sin `lock_timeout` propio (PG); DROP y ADD en dos sentencias (PG). SC-11 (4): un cierre de `routes/console.php` la llama; una cadena la nombra; `?->` desde otra clase; los secretos piden la ruta de los webhooks. SC-12 (9): sin JSON; sin el límite izquierdo; valor sin comillas separado del `=`; en verde no dice qué no mira; una línea sin revisar pasa por limpia; la barra del JSON escapado cuenta como valor; el filtro solo mira `KEY`; sin los volcados de PHP; los ejemplos cuentan. SC-13 (4): índice sin el WHERE (lo para el recuento de la propia migración); sin mirar los trabajos repetidos; `down()` no idempotente; sin `lock_timeout` (PG). **Cómo se llegó, sin maquillar:** la primera pasada se detuvo en la 2.ª mutación porque su ancla aparecía dos veces (también en `PROBLEMS`); el guion valida cada ancla y restaura cada fichero, así que no quedó nada mutado; amplié el ancla y la segunda pasada dio 33/33. Antes de mutar reforcé dos pruebas que, leyéndolas, no habrían cazado un mutante: la suelta «en vuelo» (con el reloj congelado no distinguía si el instante se toma antes o después de la lectura: ahora el reloj avanza dentro) y «DROP + ADD en una sola sentencia» (ninguna aserción lo fijaba: ahora el registro de consultas).

**CRM:** `npx ng test --watch=false` con `studio-voice-brake-reason.spec.ts` (nueva) + `studio-voice-parity`, `-activation`, `-status` y `-parse` (las que usan el motivo o el lector del freno): **5 ficheros, 72 pruebas, todas en verde**. `npx tsc -p tsconfig.app.json --noEmit` y `npx tsc -p tsconfig.spec.json --noEmit`: salida 0, sin errores. `node scripts/check-literals.mjs`: 408 ficheros, correctos. Prettier no se aplica en este módulo (el propio `studio-voice-text.ts` en HEAD y las specs hermanas ya fallan `--check`): sigo su estilo.

## 7. Lo que NO he verificado (y por qué)

- **ElevenLabs real:** la forma de `/v1/workspace/webhooks` (sobre todo si trae `auth_type` e `is_auto_disabled`) y de `/v1/convai/secrets`, y que la clave de producción pueda leerlas (encargo «sin red»; ver §2.5.2). La primera pasada real en seco (fase 2) lo dice sin frenar.
- **El freno en producción y la suelta en el CRM real con un navegador:** probado el endpoint y el lector, no la pantalla en vivo.
- **Las migraciones contra la base de producción** (volumen real, cerrojos reales con tráfico): `voice_brakes` y `voice_calls` están vacías o casi en producción a la fecha según los informes (salientes cerradas en el código); no lo he comprobado.
- **La regresión COMPLETA del backend** (solo los conjuntos de §6) y la suite completa del CRM (solo las 5 specs de la voz que tocan el freno).
- **El E2E del H8** (`tests/Feature/Voice/E2E`, solo con su arnés y la pila real): se salta en la suite.
- **Mutaciones en el CRM:** no las hice; la spec nueva fija el texto exacto, el conjunto de DB8 y el lector con el motivo nuevo.

## 8. Diffs (solo lo mío)

Ficheros NUEVOS (el diff es el fichero entero; están en el árbol): `database/migrations/2026_11_14_100001_allow_webhook_unhealthy_reason_on_voice_brakes.php` (189 líneas), `database/migrations/2026_11_14_100002_add_one_call_per_strategy_job_index_on_voice_calls.php` (150 líneas), `tests/Feature/Telephony/VoiceWebhookHealthBrakeTest.php` (466 líneas), `tests/Feature/Telephony/Schema/VoiceBrakeReasonCheckTest.php` (286 líneas), `tests/Feature/Telephony/Schema/VoiceStrategyJobIndexTest.php` (178 líneas), `tests/Feature/Security/SecretExposureLogFormatsTest.php` (216 líneas), `src/app/modules/ultron-studio/studio-voice-brake-reason.spec.ts` (50 líneas).

Ficheros que ya existían: el «antes» es el estado del árbol justo antes de mi primera edición (con el trabajo ajeno sin confirmar dentro: B-T5, B-T17, V9), así que cada diff es SOLO lo mío. Para los que no respaldé antes de editar (`VoiceWebhookHealth.php`, su orden, `VoiceIsolationTest.php` y `VoiceSchemaTest.php`) el «antes» se reconstruyó invirtiendo exactamente mis reemplazos (`reconstruir_antes.py`, en el scratchpad; cada reemplazo tenía que aparecer una sola vez).

### `app/Models/Voice/VoiceBrakeEntry.php`

```diff
diff --git a/backend/app/Models/Voice/VoiceBrakeEntry.php b/backend/app/Models/Voice/VoiceBrakeEntry.php
index aa23c8ce..f3e3f292 100644
--- a/backend/app/Models/Voice/VoiceBrakeEntry.php
+++ b/backend/app/Models/Voice/VoiceBrakeEntry.php
@@ -11,6 +11,13 @@
  * INSERCIÓN): ABRIR (`open`, lo hace el sistema solo) y SOLTAR (`release`,
  * una persona, con su nota). Una apertura se suelta una vez (`vbr_release_once`).
  * El freno está echado mientras quede una apertura sin soltar.
+ *
+ * Los motivos son un conjunto CERRADO, el mismo que `vbr_reason_chk` en
+ * PostgreSQL: los seis de DB8 (`BRAKE_REASONS` de `2026_11_10_100002`) y
+ * `webhook_unhealthy` (SC-3 del cierre, R8-12; migración `2026_11_14_100001`):
+ * el webhook post-llamada o sus secretos tienen un problema DEFINITIVO
+ * (`VoiceWebhookHealth::BRAKING`, de la telefonía) y las llamadas se quedarían
+ * sin la guarda posterior.
  */
 class VoiceBrakeEntry extends Model
 {
@@ -18,7 +25,10 @@ class VoiceBrakeEntry extends Model
 
     public const KIND_RELEASE = 'release';
 
-    public const REASONS = ['guard_violation', 'seal_mismatch', 'agent_drift', 'cost_cap', 'subaccount_alert', 'unknown_provider_call'];
+    /** SC-3 (R8-12): el webhook post-llamada o sus secretos, mal sin duda; lo abre `voice:webhook-health`. */
+    public const REASON_WEBHOOK_UNHEALTHY = 'webhook_unhealthy';
+
+    public const REASONS = ['guard_violation', 'seal_mismatch', 'agent_drift', 'cost_cap', 'subaccount_alert', 'unknown_provider_call', self::REASON_WEBHOOK_UNHEALTHY];
 
     public const UPDATED_AT = null;
 
```

### `app/Services/Telephony/VoiceWebhookHealth.php`

```diff
--- a/app/Services/Telephony/VoiceWebhookHealth.php
+++ b/app/Services/Telephony/VoiceWebhookHealth.php
@@ -3,6 +3,7 @@
 namespace App\Services\Telephony;
 
 use App\Models\Incident;
+use App\Models\Voice\VoiceBrakeEntry;
 use App\Models\Voice\VoiceCallEvent;
 use App\Services\IronGuard\IncidentRecorder;
 use App\Services\Voice\Dto\VoiceAgentTarget;
@@ -10,6 +11,7 @@
 use App\Services\Voice\Dto\VoiceSecretList;
 use App\Services\Voice\Dto\VoiceWebhookList;
 use App\Services\Voice\VoiceWebhookObserver;
+use DateTimeInterface;
 use Illuminate\Support\Carbon;
 use Illuminate\Support\Facades\Cache;
 use Illuminate\Support\Facades\Log;
@@ -35,11 +37,25 @@
  * Si algo no coincide, abre o suma al incidente de IRON GUARD de su clase
  * (fuente `voice`, código estable {@see INCIDENT_KIND}; la huella son los
  * problemas, como el monitor del H7): la misma avería repetida es UN
- * incidente con más ocurrencias. No echa el freno: avisa. Guarda el veredicto
- * (sin URL, sin ids del proveedor y sin secretos) para `voice:doctor
- * --telefonia` y `GET admin/voice/status`, con la última entrega buena que
- * dejó la post-llamada en `voice_call_events` (lo que D8-21 llama la salud del
- * webhook). `--dry-run` lee y compara, pero no guarda ni abre nada.
+ * incidente con más ocurrencias. Guarda el veredicto (sin URL, sin ids del
+ * proveedor y sin secretos) para `voice:doctor --telefonia` y
+ * `GET admin/voice/status`, con la última entrega buena que dejó la
+ * post-llamada en `voice_call_events` (lo que D8-21 llama la salud del
+ * webhook). `--dry-run` lee y compara, pero no guarda, no abre nada y no frena.
+ *
+ * EL FRENO (SC-3 del cierre, R8-12; decisión de seguridad «SÍ, frenar»): un
+ * problema DEFINITIVO ({@see BRAKING}) echa además el FRENO de voz con el
+ * motivo `webhook_unhealthy` ({@see VoiceBrake::openOrRearm()}; su clave es
+ * `webhook:` + la huella de esos problemas): sin el webhook no corre la guarda
+ * de la transcripción y una violación ya no frena. Lo INCIERTO
+ * ({@see UNCHECKED}: no se pudo leer, lista truncada, proveedor sin preparar)
+ * y lo que falta en Laravel (la base y las referencias) NO frenan: solo su
+ * incidente, medio o alto como hasta ahora. Abrir es idempotente mientras siga
+ * echado; lo suelta una persona por la vía de siempre ({@see VoiceBrake::release()},
+ * con `ultron.voice_publish` y una nota) y, si después de soltarlo la
+ * comprobación vuelve a ver el mismo problema, lo echa otra vez (la regla de
+ * las derivas, {@see VoiceBrake::observedAfter()}). El freno lo leen la puerta
+ * de salientes, el job, la entrada (`InboundPolicy`) y las herramientas.
  */
 final class VoiceWebhookHealth
 {
@@ -119,7 +135,28 @@
     /** Lo que es «no se pudo comprobar» (incidente medio), distinto de «comprobado y no coincide» (alto). */
     public const UNCHECKED = [self::PROBLEM_PROVIDER_NOT_READY, self::PROBLEM_WEBHOOKS_UNREADABLE, self::PROBLEM_SECRETS_UNREADABLE, self::PROBLEM_SECRETS_TRUNCATED];
 
+    /**
+     * SC-3 (R8-12; `informe-sec-cierre.md` §6.3): lo DEFINITIVO, comprobado en
+     * el proveedor y mal sin duda. Sin el webhook (no está, desactivado a mano o
+     * por ElevenLabs, otra URL, sin HMAC) la post-llamada no llega o no pasa la
+     * firma, así que no corre la guarda de la transcripción; sin el secreto de
+     * las herramientas `no_volver_a_llamar` no puede registrar la baja hablada,
+     * y sin el de la iniciación no se autoriza la entrante. Echa el FRENO. Un
+     * `auth_type` que el proveedor no informa cuenta como «sin HMAC» (solo
+     * `hmac` es HMAC): falla cerrado, como lo demás que el H8 no puede comprobar.
+     */
+    public const BRAKING = [
+        self::PROBLEM_WEBHOOK_NOT_FOUND, self::PROBLEM_WEBHOOK_URL_MISMATCH, self::PROBLEM_WEBHOOK_DISABLED,
+        self::PROBLEM_WEBHOOK_AUTO_DISABLED, self::PROBLEM_WEBHOOK_AUTH_NOT_HMAC,
+        self::PROBLEM_TOOL_SECRET_NOT_FOUND, self::PROBLEM_INITIATION_SECRET_NOT_FOUND,
+    ];
 
+    /** El motivo cerrado del freno (`vbr_reason_chk`, migración `2026_11_14_100001`). */
+    public const BRAKE_REASON = VoiceBrakeEntry::REASON_WEBHOOK_UNHEALTHY;
+
+    /** La clave de la apertura: este prefijo + la huella de los problemas que frenan, ordenados. */
+    public const BRAKE_KEY_PREFIX = 'webhook:';
+
     /** La autenticación que exige la post-llamada (`ElevenLabsWebhookSignature`). */
     public const AUTH_HMAC = 'hmac';
 
@@ -135,7 +172,8 @@
     /**
      * La comprobación entera. Con la bandera apagada, `disabled` sin leer
      * nada; si no, dos GET por el observador, el veredicto guardado y, si no
-     * coincide, el incidente (salvo `$dryRun`).
+     * coincide, el incidente y, si algo es DEFINITIVO, el freno (salvo
+     * `$dryRun`, que solo dice si lo echaría). `brake` es null si nada frena.
      *
      * @return array<string, mixed>
      */
@@ -143,9 +181,11 @@
     {
         if (! self::enabled()) {
             return ['state' => self::STATE_DISABLED, 'problems' => [], 'checkedAt' => null, 'webhook' => null, 'secrets' => null,
-                'lastGoodDeliveryAt' => null, 'dryRun' => $dryRun, 'incident' => null];
+                'lastGoodDeliveryAt' => null, 'dryRun' => $dryRun, 'incident' => null, 'brake' => null];
         }
 
+        // Antes de leer: una persona que suelta el freno DESPUÉS de este instante no ha visto esta observación (E10-6).
+        $observadaEn = now();
         $problemas = [];
         // 1. Las referencias de Laravel (sin red).
         $base = VoiceAgentTarget::cleanBase((string) config('voice.webhook_base_url'));
@@ -180,20 +220,76 @@
             'secrets' => $secretosVistos,
             'lastGoodDeliveryAt' => self::lastGoodDelivery(),
         ];
+        $frenan = self::brakingProblems($problemas);
         if ($dryRun) {
-            return $veredicto + ['dryRun' => true, 'incident' => null];
+            // Sin escribir: si lo echaría ahora (la misma regla que `voice:reconcile --dry-run`).
+            $freno = $frenan === [] ? null : ['reason' => self::BRAKE_REASON, 'problems' => $frenan, 'opened' => null, 'uuid' => null,
+                'wouldOpen' => VoiceBrake::nextOpening(self::brakeKey($frenan), VoiceBrake::observedAfter($observadaEn)) !== null, 'engaged' => VoiceBrake::isOpen()];
+
+            return $veredicto + ['dryRun' => true, 'incident' => null, 'brake' => $freno];
         }
 
+        // El aviso primero (no lanza nunca); el freno después: si no se puede echar, la orden falla a la vista.
         $incidente = $problemas === [] ? null : self::incident($veredicto);
+        $freno = $frenan === [] ? null : self::brake($frenan, $incidente, $observadaEn);
         Cache::put(self::CACHE_KEY, $veredicto, self::CACHE_SECONDS);
         if ($problemas !== []) {
-            Log::channel('voice')->warning('voice.webhook_health.unhealthy', ['problems' => $problemas, 'incident_id' => $incidente]);
+            Log::channel('voice')->warning('voice.webhook_health.unhealthy', ['problems' => $problemas, 'incident_id' => $incidente, 'brake_uuid' => $freno['uuid'] ?? null]);
         }
 
-        return $veredicto + ['dryRun' => false, 'incident' => $incidente];
+        return $veredicto + ['dryRun' => false, 'incident' => $incidente, 'brake' => $freno];
     }
 
     /**
+     * Los problemas del veredicto que echan el freno ({@see BRAKING}), ordenados
+     * (la huella de la clave no depende del orden en que se miraron).
+     *
+     * @param  list<string>  $problems
+     * @return list<string>
+     */
+    public static function brakingProblems(array $problems): array
+    {
+        $frenan = array_values(array_intersect($problems, self::BRAKING));
+        sort($frenan, SORT_STRING);
+
+        return $frenan;
+    }
+
+    /**
+     * La clave de la apertura para esa combinación de problemas DEFINITIVOS:
+     * la misma avería, la misma clave (idempotente mientras siga echado); otra
+     * combinación, otra apertura. Lo incierto no entra en la huella: que una
+     * lectura falle a ratos junto a un problema definitivo no abre otra.
+     *
+     * @param  list<string>  $braking  ya ordenados ({@see brakingProblems()})
+     */
+    public static function brakeKey(array $braking): string
+    {
+        return self::BRAKE_KEY_PREFIX.substr(hash('sha256', implode(',', $braking)), 0, 32);
+    }
+
+    /**
+     * Echa el freno por `webhook_unhealthy` (o lo vuelve a echar si una persona
+     * lo soltó antes de esta observación y el problema sigue). La evidencia son
+     * códigos y el id del incidente, nunca URL, ids del proveedor ni secretos.
+     * No atrapa nada: un freno que no se puede echar no se calla.
+     *
+     * `opened`: si esta pasada abrió una apertura (sin ella, la de esta avería
+     * seguía echada, o una persona la soltó mientras se miraba); `engaged`: si
+     * el freno queda echado, por esta u otra causa.
+     *
+     * @param  list<string>  $braking
+     * @return array{reason: string, problems: list<string>, opened: bool, uuid: ?string, engaged: bool}
+     */
+    private static function brake(array $braking, ?int $incident, DateTimeInterface $observedAt): array
+    {
+        $apertura = VoiceBrake::openOrRearm(self::BRAKE_REASON, self::brakeKey($braking),
+            ['where' => 'webhook_health', 'codes' => $braking, 'incidentId' => $incident], VoiceBrake::observedAfter($observedAt));
+
+        return ['reason' => self::BRAKE_REASON, 'problems' => $braking, 'opened' => $apertura !== null, 'uuid' => $apertura?->uuid, 'engaged' => VoiceBrake::isOpen()];
+    }
+
+    /**
      * El último veredicto guardado (para el estado y el doctor), o null si no
      * hay ninguno legible. Sin red.
      *
```

### `app/Console/Commands/Voice/VoiceWebhookHealthCommand.php`

```diff
--- a/app/Console/Commands/Voice/VoiceWebhookHealthCommand.php
+++ b/app/Console/Commands/Voice/VoiceWebhookHealthCommand.php
@@ -13,21 +13,24 @@
  * lectura a ElevenLabs (por la interfaz de D8-3; sin las piezas, sin red)
  * comparan el webhook post-llamada y los dos secretos registrados con las
  * referencias de Laravel (`VOICE_WEBHOOK_BASE_URL`, `ELEVENLABS_*_REF`); si no
- * coinciden, abre o suma al incidente de IRON GUARD `voice_webhook_unhealthy`.
- * Apagada, no sale a la red y dice `disabled`. El bloque de voz del programador
- * la lanza cada hora (minuto 5).
+ * coinciden, abre o suma al incidente de IRON GUARD `voice_webhook_unhealthy` y,
+ * si el problema es DEFINITIVO (SC-3 del cierre, R8-12), echa el freno de voz
+ * (`webhook_unhealthy`; lo suelta una persona, con su nota). Apagada, no sale a
+ * la red y dice `disabled`. El bloque de voz del programador la lanza cada hora
+ * (minuto 5).
  *
  * Sale 0 si coincide (o con la bandera apagada) y 1 si no. `--dry-run` lee y
- * compara, pero no guarda el veredicto ni abre incidentes. Nunca imprime una
- * URL, un id del proveedor ni un secreto: estados, códigos y fechas.
+ * compara, pero no guarda el veredicto, no abre incidentes y no frena (dice si
+ * lo haría). Nunca imprime una URL, un id del proveedor ni un secreto:
+ * estados, códigos y fechas.
  */
 class VoiceWebhookHealthCommand extends Command
 {
     protected $signature = 'voice:webhook-health
-        {--dry-run : lee y compara, sin guardar el veredicto ni abrir incidentes}
+        {--dry-run : lee y compara, sin guardar el veredicto, abrir incidentes ni echar el freno}
         {--json : el veredicto en JSON}';
 
-    protected $description = 'Salud del webhook post-llamada (D8-21 f): con VOICE_MONITOR_ENABLED, dos GET de solo lectura comparan el webhook y los dos secretos de ElevenLabs con las referencias de Laravel; si no coinciden, incidente de IRON GUARD. Apagada, sin red.';
+    protected $description = 'Salud del webhook post-llamada (D8-21 f): con VOICE_MONITOR_ENABLED, dos GET de solo lectura comparan el webhook y los dos secretos de ElevenLabs con las referencias de Laravel; si no coinciden, incidente de IRON GUARD y, si es definitivo, el freno de voz (SC-3). Apagada, sin red.';
 
     public function handle(VoiceWebhookHealth $salud): int
     {
@@ -58,8 +61,31 @@
             $this->info('Coincide: el webhook y los dos secretos son los de Laravel.');
         } else {
             $this->error('No coincide: '.implode(', ', $r['problems']).($r['incident'] === null ? '' : ' (incidente '.$r['incident'].')'));
+            $this->brakeLine($r['brake']);
         }
 
         return $codigo;
     }
+
+    /**
+     * SC-3: qué pasó con el freno. Sin un problema DEFINITIVO no se frena (lo
+     * incierto y lo que falta en Laravel solo avisan).
+     *
+     * @param  array<string, mixed>|null  $freno
+     */
+    private function brakeLine(?array $freno): void
+    {
+        if ($freno === null) {
+            $this->line('Freno de voz: no se echa (solo frena un problema definitivo: '.implode(', ', VoiceWebhookHealth::BRAKING).').');
+
+            return;
+        }
+        $por = $freno['reason'].' · '.implode(', ', $freno['problems']);
+        $ahora = $freno['engaged'] ? 'echado' : 'suelto';
+        $this->error('Freno de voz: '.match (true) {
+            $freno['opened'] === null => 'EN SECO, '.($freno['wouldOpen'] ? 'lo echaría' : 'no abriría otra apertura').' ('.$por.'); ahora está '.$ahora,
+            $freno['opened'] === true => 'ECHADO ('.$por.'; apertura '.$freno['uuid'].')',
+            default => 'sin apertura nueva ('.$por.': la de esta avería sigue echada o se soltó durante la comprobación); ahora está '.$ahora,
+        }.'. Lo suelta una persona con «Publicar la voz» y una nota, después de revisar las llamadas hechas sin la guarda.');
+    }
 }
```

### `app/Console/Commands/CheckSecretExposure.php`

```diff
--- a/app/Console/Commands/CheckSecretExposure.php
+++ b/app/Console/Commands/CheckSecretExposure.php
@@ -30,7 +30,11 @@
  *  5. Que ningún LOG legible por «otros» lleve una credencial (auditoría
  *     B-T17): los canales `channel` y `voice` se crean en 0664 a propósito, y
  *     un volcado del entorno que acabe en uno (una traza, un `printenv`, un
- *     `.env` pegado) deja la clave a la vista de cualquiera.
+ *     `.env` pegado) deja la clave a la vista de cualquiera. SC-12 del cierre:
+ *     en un log se reconoce también `CLAVE=valor` en mitad de una línea,
+ *     `"CLAVE":"valor"` (JSON) y los volcados de PHP ({@see LOG_FORMATS}); lo
+ *     que NO se mira lo dice la propia salida ({@see LOG_NOT_COVERED}), para
+ *     que un verde no se lea como más de lo que es.
  *
  * Las claves vigiladas son las de {@see SECRET_KEYS} (con las 13 que H-4 de
  * SEC7 echó en falta) y las de las familias de la voz del Hito 8
@@ -87,6 +91,26 @@
     /** Valores que no son secretos aunque la clave lo parezca. */
     private const PLACEHOLDERS = ['null', 'changeme', 'tu_', 'your_', 'placeholder', 'xxx'];
 
+    /** Los logs que se miran (los legibles por «otros»). */
+    private const LOG_FILES = 'storage/logs/*.log';
+
+    /** SC-12: las formas en que se reconoce un secreto en una línea de log ({@see logLinePatterns()}). */
+    private const LOG_FORMATS = [
+        'CLAVE=valor al principio de la línea (también con espacios, comillas o export)',
+        'CLAVE=valor en mitad de la línea (pegado al =, o entre comillas)',
+        '"CLAVE":"valor" (JSON, también escapado dentro de otro JSON)',
+        "'CLAVE' => 'valor' y [CLAVE] => valor (volcados de PHP)",
+    ];
+
+    /** SC-12: lo que el rastreo de logs NO ve. Lo dice la salida: un verde no cubre esto. */
+    private const LOG_NOT_COVERED = [
+        'en mitad de la línea, CLAVE = valor sin comillas y separado del = (se confundiría con el texto que sigue)',
+        'un valor partido en varias líneas o codificado (base64, URL, \\u…)',
+        'CLAVE: valor (YAML, cabeceras) ni Authorization: Bearer …',
+        'una clave en minúsculas, con otro nombre o con un prefijo propio (PUSHER_APP_KEY no es APP_KEY)',
+        'otros ficheros: rotados (*.log.1), comprimidos (*.gz) o fuera de storage/logs',
+    ];
+
     public function handle(): int
     {
         $findings = array_merge(
@@ -100,6 +124,8 @@
             $this->line((string) json_encode([
                 'ok' => $findings === [],
                 'findings' => $findings,
+                // SC-12: qué cubre y qué no el rastreo de logs (aditivo).
+                'logScan' => ['files' => self::LOG_FILES, 'formats' => self::LOG_FORMATS, 'notCovered' => self::LOG_NOT_COVERED],
             ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
 
             return $findings === [] ? self::SUCCESS : self::FAILURE;
@@ -107,6 +133,7 @@
 
         if ($findings === []) {
             $this->info('Ningún fichero con credenciales está expuesto.');
+            $this->logScanNote();
 
             return self::SUCCESS;
         }
@@ -116,16 +143,25 @@
         // Solo NOMBRES de claves: el valor no sale nunca de esta orden.
         $this->table(
             ['problema', 'permisos', 'fichero', 'claves'],
-            array_map(fn (array $f) => [$f['issue'], $f['permissions'], $f['path'], isset($f['keys']) ? implode(', ', $f['keys']) : '—'], $findings),
+            array_map(fn (array $f) => [$f['issue'], $f['permissions'], $f['path'], (($f['keys'] ?? []) === [] ? '—' : implode(', ', $f['keys']))
+                .(isset($f['unscannedLines']) ? ' · '.$f['unscannedLines'].' línea(s) sin revisar' : '')], $findings),
         );
         $this->newLine();
         $this->line('  Arreglo: <fg=yellow>chmod 600 <fichero></> y mover los respaldos fuera del directorio de la aplicación.');
         $this->line('  La configuración compilada se vuelve a escribir en cada <fg=yellow>config:cache</>:');
         $this->line('  el chmod hay que repetirlo ahí, no una sola vez.');
+        $this->logScanNote();
 
         return self::FAILURE;
     }
 
+    /** SC-12: lo que mira y lo que NO mira el rastreo de logs, siempre (también en verde). */
+    private function logScanNote(): void
+    {
+        $this->line('  Logs: solo '.self::LOG_FILES.' legibles por cualquiera. Reconoce: '.implode('; ', self::LOG_FORMATS).'.');
+        $this->line('  NO mira: '.implode('; ', self::LOG_NOT_COVERED).'.');
+    }
+
     /**
      * La configuración compilada (`bootstrap/cache/config.php`) legible por
      * «otros».
@@ -217,17 +253,22 @@
                 continue; // solo lo leen su dueño y su grupo
             }
 
-            $keys = $this->realSecretKeysInLines($path);
-            if ($keys === []) {
+            [$keys, $sinRevisar] = $this->realSecretKeysInLogLines($path);
+            if ($keys === [] && $sinRevisar === 0) {
                 continue;
             }
 
-            $findings[] = [
-                'issue' => 'log legible por cualquier usuario con credenciales',
+            $finding = [
+                // Una línea que no se pudo revisar no pasa por limpia: el log sale igual, con cuántas fueron.
+                'issue' => $keys !== [] ? 'log legible por cualquier usuario con credenciales' : 'log legible por cualquier usuario que no se pudo revisar entero',
                 'permissions' => substr(sprintf('%o', $perms), -4),
                 'path' => $path,
                 'keys' => $keys,
             ];
+            if ($sinRevisar > 0) {
+                $finding['unscannedLines'] = $sinRevisar;
+            }
+            $findings[] = $finding;
         }
 
         return $findings;
@@ -294,34 +335,52 @@
     }
 
     /**
-     * Lo mismo, línea a línea (los logs pueden pesar cientos de MB).
+     * Las claves de un LOG, línea a línea (los logs pueden pesar cientos de MB),
+     * con todas las formas de {@see logLinePatterns()}, y cuántas líneas no se
+     * pudieron revisar (un fallo de PCRE, p. ej. el límite de retroceso en una
+     * línea enorme: no se da por limpia).
      *
-     * @return list<string>
+     * @return array{0: list<string>, 1: int}
      */
-    private function realSecretKeysInLines(string $path): array
+    private function realSecretKeysInLogLines(string $path): array
     {
         $handle = @fopen($path, 'rb');
         if ($handle === false) {
-            return [];
+            return [[], 0];
         }
 
+        $patterns = self::logLinePatterns();
+        $terminals = self::keyTerminals();
         $keys = [];
+        $unscanned = 0;
         try {
             while (($line = fgets($handle)) !== false) {
-                // Filtro barato: sin «=» no hay asignación que mirar.
-                if (! str_contains($line, '=')) {
+                // Filtro barato: toda clave vigilada termina en una de estas palabras (KEY, PASSWORD, TOKEN, SECRET).
+                if (! self::containsAny($line, $terminals)) {
                     continue;
                 }
-                $keys = array_merge($keys, $this->realSecretKeysIn($line));
+                foreach ($patterns as $pattern) {
+                    if (preg_match_all($pattern, $line, $matches, PREG_SET_ORDER) === false) {
+                        $unscanned++;
+
+                        break;
+                    }
+                    foreach ($matches as $m) {
+                        // `rtrim('\\')`: el cierre de un JSON escapado deja su barra pegada al valor.
+                        if (self::isRealValue(trim(rtrim($m[2], '\\')))) {
+                            $keys[$m[1]] = true;
+                        }
+                    }
+                }
             }
         } finally {
             fclose($handle);
         }
 
-        $keys = array_values(array_unique($keys));
+        $keys = array_keys($keys);
         sort($keys, SORT_STRING);
 
-        return $keys;
+        return [$keys, $unscanned];
     }
 
     /**
@@ -339,19 +398,9 @@
 
         $keys = [];
         foreach ($matches as $m) {
-            $value = trim($m[2]);
-
-            if ($value === '') {
-                continue; // clave declarada pero sin valor: eso es un ejemplo
-            }
-
-            foreach (self::PLACEHOLDERS as $placeholder) {
-                if (stripos($value, $placeholder) === 0) {
-                    continue 2; // es un ejemplo, no un secreto
-                }
+            if (self::isRealValue(trim($m[2]))) {
+                $keys[$m[1]] = true;
             }
-
-            $keys[$m[1]] = true;
         }
 
         $keys = array_keys($keys);
@@ -373,11 +422,99 @@
      */
     private static function secretAssignmentPattern(): string
     {
+        return '/^[ \t]*(?:export[ \t]+)?('.self::keyAlternation().')[ \t]*=[ \t]*"?([^"\r\n#]*)"?/m';
+    }
+
+    /**
+     * SC-12 del cierre: las formas de un secreto en una línea de LOG, además de
+     * {@see secretAssignmentPattern()} (al principio de la línea). Grupo 1, la
+     * clave; grupo 2, el valor (`(?|…)`: cada alternativa reusa los números).
+     * La clave va ENTERA y en mayúsculas, como en el entorno: no puede ir pegada
+     * a otra palabra (`PUSHER_APP_KEY` no es `APP_KEY`; `$APP_KEY`, una
+     * variable, tampoco).
+     *
+     *  1. `CLAVE=valor` en cualquier punto: entre comillas (con espacios o sin
+     *     ellos alrededor del `=`), o sin comillas PEGADO al `=` y hasta un
+     *     espacio, una comilla o un separador. Nunca `==` ni `=>`. Sin comillas y
+     *     separado del `=`, el valor sería la palabra que sigue
+     *     (`faltan APP_KEY= y DB_PASSWORD=`): no se toma (lo dice
+     *     {@see LOG_NOT_COVERED}); al principio de la línea lo ve el patrón del
+     *     entorno.
+     *  2. `"CLAVE":"valor"` (JSON), con o sin espacios y también con las
+     *     comillas escapadas de un JSON dentro de otro (`\"CLAVE\":\"valor\"`).
+     *  3. `'CLAVE' => 'valor'` (`var_export`) y `[CLAVE] => valor` (`print_r`).
+     *
+     * Como en el entorno, espacio HORIZONTAL únicamente ([ \t]) y nada cruza
+     * un salto de línea: un valor vacío no se lleva el de la línea siguiente.
+     *
+     * @return list<string>
+     */
+    private static function logLinePatterns(): array
+    {
+        $key = '('.self::keyAlternation().')';
+
+        return [
+            self::secretAssignmentPattern(),
+            '/(?<![A-Za-z0-9_$])'.$key.'[ \t]*=(?![=>])(?|[ \t]*"([^"\r\n]*)"|[ \t]*\'([^\'\r\n]*)\'|([^\s"\'`,;&)}\]>]*))/',
+            '/\\\\?"'.$key.'\\\\?"[ \t]*:[ \t]*\\\\?"([^"\r\n]*)"/',
+            '/(?|\''.$key.'\'|"'.$key.'"|\['.$key.'\])[ \t]*=>[ \t]*(?|\'([^\'\r\n]*)\'|"([^"\r\n]*)"|([^\s,\'"\])]*))/',
+        ];
+    }
+
+    /**
+     * Las claves vigiladas, como alternancia de PCRE: las de {@see SECRET_KEYS}
+     * y las de las familias de la voz ({@see SECRET_KEY_FAMILIES} + `_…` + uno
+     * de {@see SECRET_SUFFIXES}).
+     */
+    private static function keyAlternation(): string
+    {
         $quote = static fn (string $k): string => preg_quote($k, '/');
         $explicit = implode('|', array_map($quote, self::SECRET_KEYS));
         $families = '(?:'.implode('|', array_map($quote, self::SECRET_KEY_FAMILIES)).')(?:_[A-Z0-9]+)*?_(?:'
             .implode('|', array_map($quote, self::SECRET_SUFFIXES)).')';
 
-        return '/^[ \t]*(?:export[ \t]+)?('.$explicit.'|'.$families.')[ \t]*=[ \t]*"?([^"\r\n#]*)"?/m';
+        return $explicit.'|'.$families;
     }
+
+    /**
+     * La última palabra de cada clave vigilada (`KEY`, `PASSWORD`, `TOKEN`,
+     * `SECRET`): una línea que no lleva ninguna no puede llevar una clave
+     * vigilada. Sale de las mismas listas: una clave nueva con otra terminación
+     * entra sola en el filtro.
+     *
+     * @return list<string>
+     */
+    private static function keyTerminals(): array
+    {
+        $last = static fn (string $k): string => substr($k, (int) strrpos('_'.$k, '_'));
+
+        return array_values(array_unique(array_map($last, [...self::SECRET_KEYS, ...self::SECRET_SUFFIXES])));
+    }
+
+    /** @param  list<string>  $needles */
+    private static function containsAny(string $haystack, array $needles): bool
+    {
+        foreach ($needles as $needle) {
+            if (str_contains($haystack, $needle)) {
+                return true;
+            }
+        }
+
+        return false;
+    }
+
+    /** Un valor de verdad: ni vacío (una clave declarada sin valor es un ejemplo) ni uno de {@see PLACEHOLDERS}. */
+    private static function isRealValue(string $value): bool
+    {
+        if ($value === '') {
+            return false;
+        }
+        foreach (self::PLACEHOLDERS as $placeholder) {
+            if (stripos($value, $placeholder) === 0) {
+                return false;
+            }
+        }
+
+        return true;
+    }
 }
```

### `tests/Feature/Telephony/VoiceWebhookHealthTest.php`

```diff
--- a/tests/Feature/Telephony/VoiceWebhookHealthTest.php
+++ b/tests/Feature/Telephony/VoiceWebhookHealthTest.php
@@ -25,6 +25,9 @@
  *  - Encendida: dos GET de solo lectura; coincide → 0 y sin incidente; no
  *    coincide → 1 e incidente `voice_webhook_unhealthy` (alto si algo no
  *    coincide, medio si no se pudo comprobar), uno por clase de avería.
+ *  - SC-3 del cierre (R8-12): lo DEFINITIVO echa además el freno de voz
+ *    (`webhook_unhealthy`, con su propio incidente `voice_brake`); el detalle,
+ *    en VoiceWebhookHealthBrakeTest.
  *  - Nunca sale una URL, un id del proveedor ni un secreto.
  */
 class VoiceWebhookHealthTest extends TestCase
@@ -156,11 +159,15 @@
 
             [$codigo, $r, $salida] = $this->salud();
             $this->assertSame([1, 'unhealthy', $problemas], [$codigo, $r['state'], $r['problems']], $caso);
-            $incidente = Incident::query()->sole();
+            $incidente = Incident::query()->where('kind', VoiceWebhookHealth::INCIDENT_KIND)->sole();
             $this->assertSame(['voice', 'voice_webhook_unhealthy', Incident::SEVERITY_HIGH, $r['incident']], [$incidente->source, $incidente->kind, $incidente->severity, $incidente->id], $caso);
             $ordenados = $problemas;
             sort($ordenados);
             $this->assertSame($ordenados, $incidente->evidence['problems'], $caso);
+            // SC-3: todos estos son DEFINITIVOS: echan el freno, con su propio aviso (y nada más).
+            $this->assertSame(['webhook_unhealthy', $ordenados, true], [$r['brake']['reason'], $r['brake']['problems'], $r['brake']['opened']], $caso);
+            $this->assertSame(1, Incident::query()->where('kind', 'voice_brake')->count(), $caso);
+            $this->assertSame(2, Incident::query()->count(), $caso);
             $this->assertSinSecretosNiUrls($salida.json_encode([$incidente->title, $incidente->evidence]), $caso);
         }
     }
@@ -171,8 +178,9 @@
         for ($i = 0; $i < 3; $i++) {
             $this->assertSame(1, $this->salud()[0]);
         }
-        $incidente = Incident::query()->sole();
+        $incidente = Incident::query()->where('kind', VoiceWebhookHealth::INCIDENT_KIND)->sole();
         $this->assertSame(3, (int) $incidente->occurrences, 'idempotente: la misma huella suma');
+        $this->assertSame(1, (int) Incident::query()->where('kind', 'voice_brake')->sum('occurrences'), 'SC-3: el freno se echa una vez mientras siga echado');
 
         $this->proveedor(['is_auto_disabled' => true, 'webhook_url' => 'https://otra.example/x']);
         $this->salud();
```

### `tests/Feature/Telephony/Schema/VoiceSchemaTest.php`

```diff
--- a/tests/Feature/Telephony/Schema/VoiceSchemaTest.php
+++ b/tests/Feature/Telephony/Schema/VoiceSchemaTest.php
@@ -19,9 +19,10 @@
  *
  *  · Columnas, tipos y nulos; ninguna guarda un teléfono en claro, una
  *    transcripción ni audio (D8-17).
- *  · Recuentos y nombres: PG 27 disparadores, 51 CHECK y 3 índices únicos
- *    parciales; SQLite 21 disparadores y los mismos 3 parciales, con el mismo
- *    WHERE.
+ *  · Recuentos y nombres: PG 27 disparadores, 51 CHECK y 4 índices únicos
+ *    parciales (los 3 de DB8 y, desde SC-13 del cierre, el de «una llamada
+ *    por trabajo», `2026_11_14_100002`); SQLite 21 disparadores y los mismos 4
+ *    parciales, con el mismo WHERE.
  *  · La función de guarda es PROPIA (`voice_forbid()`, «voice: …»), no la de F,
  *    y la de F no cambia; la migración no nombra el Studio ni el motor y sus
  *    FKs no salen de `voice_*` (D8-2, protección 5).
@@ -243,12 +244,15 @@
 
     /** Los índices únicos parciales y su WHERE, en cada motor (PG lo reescribe; SQLite lo guarda tal cual). */
     private const PARTIAL_INDEXES_PG = [
+        // SC-13 del cierre (2026_11_14_100002): una llamada por trabajo de estrategia (D-V9-2).
+        'vc_one_call_per_strategy_job' => "((requested_by_type)::text = 'strategy'::text)",
         'vc_one_live_outbound' => "(((direction)::text = 'outbound'::text) AND (transfer_of_call_id IS NULL) AND ((status)::text = ANY ((ARRAY['requested'::character varying, 'ambiguous'::character varying, 'dialing'::character varying, 'active'::character varying])::text[])))",
         'vct_one_live' => "((status)::text <> 'retired'::text)",
         'vex_one_opt_out_per_call' => "((source)::text = 'spoken_opt_out'::text)",
     ];
 
     private const PARTIAL_INDEXES_SQLITE = [
+        'vc_one_call_per_strategy_job' => "requested_by_type = 'strategy'",
         'vc_one_live_outbound' => "direction = 'outbound' AND transfer_of_call_id IS NULL AND status IN ('requested', 'ambiguous', 'dialing', 'active')",
         'vct_one_live' => "status <> 'retired'",
         'vex_one_opt_out_per_call' => "source = 'spoken_opt_out'",
@@ -455,6 +459,11 @@
             // V9 amplía `vc_requester_chk` en su propia migración (2026_11_13_100001): como en un `migrate` real, se vuelve a
             // aplicar antes de comparar (G-8 de V9, L9-81; en SQLite no hace nada). El recuento de 51 no cambia.
             (require database_path('migrations/2026_11_13_100001_allow_strategy_requester_on_voice_calls.php'))->up();
+            // SC-3 del cierre (R8-12) amplía `vbr_reason_chk` con `webhook_unhealthy` en la suya (2026_11_14_100001), igual:
+            // DROP + ADD del mismo nombre; en SQLite no hace nada. El recuento de 51 no cambia.
+            (require database_path('migrations/2026_11_14_100001_allow_webhook_unhealthy_reason_on_voice_brakes.php'))->up();
+            // SC-13 del cierre: el índice parcial de «una llamada por trabajo» (2026_11_14_100002), en los dos motores.
+            (require database_path('migrations/2026_11_14_100002_add_one_call_per_strategy_job_index_on_voice_calls.php'))->up();
             $this->assertSame($h8, $this->voiceFootprint(), 'up() no dejó lo mismo que la primera vez');
             // Y las guardas vuelven a funcionar.
             $id = $this->insertOutbound();
```

### `tests/Unit/Voice/VoiceIsolationTest.php`

```diff
--- a/tests/Unit/Voice/VoiceIsolationTest.php
+++ b/tests/Unit/Voice/VoiceIsolationTest.php
@@ -203,6 +203,15 @@
 
     /** E5-2: adónde va el GET de `describeNumber` (`ElevenLabsAgentDeployer::PHONE_NUMBERS_PATH` + el id codificado). */
     private const RUTA_DEL_NUMERO = '/v1/convai/phone-numbers/';
+
+    /**
+     * B-T5 (SC-11 del cierre): el único fichero que llama a `describeWebhooks` y
+     * a `describeSecrets` (`VoiceWebhookHealth::run()`, desde `voice:webhook-health`).
+     */
+    private const OBSERVA_EL_WEBHOOK = 'app/Services/Telephony/VoiceWebhookHealth.php';
+
+    /** B-T5: adónde va cada GET (`ElevenLabsAgentDeployer::WEBHOOKS_PATH` y `SECRETS_PATH`), y nada más. */
+    private const RUTAS_DEL_WEBHOOK = ['describeWebhooks' => ['WEBHOOKS_PATH', '/v1/workspace/webhooks'], 'describeSecrets' => ['SECRETS_PATH', '/v1/convai/secrets']];
 
     /** La cadena del GET del H6, exacta. */
     private const CADENA_H6 = ['withHeaders', 'acceptJson', 'withoutRedirecting', 'connectTimeout', 'timeout', 'get'];
@@ -772,7 +781,56 @@
         $this->assertStringContainsString('rawurlencode', $codigo, 'describeNumber pone el id del número en la ruta sin codificar (E5-2)');
         $this->assertMatchesRegularExpression('/const PHONE_NUMBERS_PATH = \''.preg_quote(self::RUTA_DEL_NUMERO, '/').'\';/', self::codigo($desplegador), 'la ruta del número no es '.self::RUTA_DEL_NUMERO.' (E5-2)');
     }
+
+    /**
+     * B-T5 (D8-21, decisión f), como E5-2: el webhook post-llamada y los
+     * secretos del workspace se observan con UN GET a su ruta y solo desde la
+     * salud del webhook. Nadie más llama a `describeWebhooks` ni a
+     * `describeSecrets` (con `->`, `?->` o `::`; también los cierres de
+     * `routes/console.php`), nadie los nombra en una cadena (una llamada
+     * dinámica), y cada GET va a la ruta de su constante: ni otra ruta del
+     * proveedor ni una URL armada a mano. SC-11 del cierre: la guarda vivía solo
+     * en la prueba de la funcionalidad (y solo barría `app/`); aquí no se pierde
+     * si esa prueba se quita. El verbo, la petición única, las redirecciones y
+     * los reintentos los mira {@see test_la_red_solo_sale_de_los_metodos_enumerados()}.
+     */
+    public function test_el_webhook_y_los_secretos_se_observan_con_un_get_a_su_ruta_y_solo_desde_la_salud_del_webhook(): void
+    {
+        $llaman = [];
+        foreach (self::php('app', 'routes') as $relativa => $ruta) {
+            $codigo = (string) file_get_contents($ruta);
+            if (stripos($codigo, 'describeWebhooks') === false && stripos($codigo, 'describeSecrets') === false) {
+                continue;
+            }
+            $tokens = self::tokens($ruta);
+            foreach ($tokens as $i => $t) {
+                foreach (array_keys(self::RUTAS_DEL_WEBHOOK) as $metodo) {
+                    if ($t[0] === T_STRING && $t[1] === $metodo && in_array(self::anterior($tokens, $i)[0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
+                        $llaman[$metodo][$relativa] = true;
+                    }
+                    if ($t[0] === T_CONSTANT_ENCAPSED_STRING && strcasecmp(self::literal($t[1]), $metodo) === 0) {
+                        $this->fail("{$relativa} nombra {$metodo} en una cadena: solo VoiceWebhookHealth la llama, y de forma directa (B-T5)");
+                    }
+                }
+            }
+        }
+        foreach (array_keys(self::RUTAS_DEL_WEBHOOK) as $metodo) {
+            $this->assertSame([self::OBSERVA_EL_WEBHOOK], array_keys($llaman[$metodo] ?? []), "{$metodo} se llama solo desde VoiceWebhookHealth (B-T5): ".implode(', ', array_keys($llaman[$metodo] ?? [])));
+        }
 
+        $desplegador = self::raiz().'/'.self::DESPLEGADOR;
+        foreach (self::RUTAS_DEL_WEBHOOK as $metodo => [$constante, $rutaOficial]) {
+            $codigo = self::codigoDeMetodo($desplegador, $metodo);
+            // `codigoDeMetodo()` une los tokens con espacios: la forma se mira con espacios opcionales.
+            $this->assertSame(1, preg_match_all('/->\s*get\s*\(\s*\$base\s*\.\s*self\s*::\s*'.$constante.'\b/', $codigo), "{$metodo} no pide su ruta ({$constante}) exactamente una vez (B-T5)");
+            foreach (self::RUTAS_DEL_WEBHOOK as [$otra]) {
+                if ($otra !== $constante) {
+                    $this->assertStringNotContainsString($otra, $codigo, "{$metodo} pide la ruta de la otra lectura (B-T5)");
+                }
+            }
+            $this->assertMatchesRegularExpression('/const '.$constante.' = \''.preg_quote($rutaOficial, '/').'\';/', self::codigo($desplegador), "la ruta de {$metodo} no es {$rutaOficial} (B-T5)");
+        }
+    }
 
     // ── Rutas y pruebas ─────────────────────────────────────────────────────
 
```

### CRM `src/app/modules/ultron-studio/studio-voice-text.ts`

```diff
--- a/src/app/modules/ultron-studio/studio-voice-text.ts
+++ b/src/app/modules/ultron-studio/studio-voice-text.ts
@@ -347,6 +347,10 @@
   cost_cap: 'Techo duro de gasto',
   subaccount_alert: 'Aviso de la subcuenta de Twilio',
   unknown_provider_call: 'Una llamada que Laravel no inició',
+  // SC-3 del cierre (R8-12): lo echa `voice:webhook-health` si el webhook post-llamada o los secretos de
+  // ElevenLabs están caídos o no son los de Laravel (sin el webhook, las llamadas van sin la guarda posterior).
+  // No está en `VOICE_BRAKE_REASONS` (los seis de DB8, en paridad con el fixture): el lector lo admite por su código.
+  webhook_unhealthy: 'Webhook post-llamada o secretos de ElevenLabs caídos o distintos de los de Laravel',
 };
 /** El motivo de un freno; uno nuevo se enseña con su código (un freno abierto nunca se esconde). */
 export const brakeReasonText = (r: VoiceBrakeReasonCode): string => MOTIVOS_DE_FRENO[r] ?? `Motivo: ${r}`;
```

## 9. Pendiente y para quién

- **Lead:** (a) leer §2.5.1 (el freno también cierra entrantes y herramientas) y confirmar que es lo querido o pedir un freno «solo salientes»; (b) la enmienda del H8 que recoja el motivo nuevo de D8-19 (`webhook_unhealthy`), la decisión de R8-12 y el índice de D-V9-2; (c) el complemento de §6.3 (post-llamada ausente a los 15 min → el mismo freno), si se quiere; (d) las marcas «PENDIENTE DEL VISTO BUENO DE SEC8» de `VoiceIsolationTest` (visto bueno dado en §6.1 del informe de seguridad; no las he quitado porque mi encargo era solo añadir la guarda).
- **`database-specialist`:** el informe de seguridad le asignaba la enmienda de `vbr_reason_chk` (SC-3) y el índice de SC-13; los he hecho siguiendo el patrón exacto de V9 y de DB8 con sus pruebas en PG, pero conviene su revisión antes de desplegar.
- **`devops` (`ops8-e`):** dos migraciones nuevas para el despliegue (`2026_11_14_100001`, `2026_11_14_100002`; si algún guion cuenta las pendientes, sube en 2); el runbook de la fase 2: `voice:webhook-health --dry-run` antes de `monitor on` (§2.5.2) y qué hacer con un freno `webhook_unhealthy` (reactivar el webhook en ElevenLabs con `h8-secrets-register.sh`, revisar las llamadas hechas sin guarda y soltarlo con nota); los logs a 0660 (SC-12).
- **`security`:** visto bueno de SC-3 tal como queda (con §2.5) y de la forma de SC-12.
- **`tester`:** casos límite que conozco desde dentro: dos `voice:webhook-health` a la vez (el UNIQUE de `open_key` hace que solo una abra; la otra ve `opened: false`); una suelta en el mismo segundo en que empieza una comprobación (cuenta como posterior: la reabre, falla cerrado, regla de E10-6); `auth_type` ausente; una línea de log enorme (fallo de PCRE → hallazgo); el reloj del servidor movido hacia atrás tras una suelta (la observación parecería anterior y no reabriría hasta la pasada siguiente).

## 10. Resumen para el lead

- **SC-3 hecho:** 7 problemas definitivos del webhook/secretos echan el freno `webhook_unhealthy` (+ incidente alto); los 4 inciertos y los 4 de Laravel, solo su incidente. Idempotente, se suelta como siempre y se rearma si el problema sigue tras soltarlo. Migración `2026_11_14_100001` (PG: DROP + ADD en una sentencia con `lock_timeout` y recuentos; SQLite: nada). La puerta niega `voice_braked` (probado). Texto en el CRM.
- **Ojo (§2.5):** el freno también deniega las entrantes y las herramientas (§6.3 decía lo contrario); `auth_type` ausente frena (falla cerrado; ensayar en seco antes de `monitor on`); lo de Laravel sigue en alto.
- **SC-11 hecho** (guarda en `VoiceIsolationTest`), **SC-12 hecho** (JSON, mitad de línea, volcados de PHP; lo no cubierto en la salida; un fallo de PCRE ya no pasa por limpio), **SC-13 hecho** (índice único parcial en los dos motores, `2026_11_14_100002`).
- **Verificado:** regresión de los conjuntos pedidos en SQLite y PG (§6), Pint, 33/33 mutaciones, CRM 72/72 y `tsc` limpio. Sin commit.

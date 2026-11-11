# SEC · Revisión de seguridad del cierre de ULTRON STUDIO (H9 + endurecimiento del H8)

**Agente:** `security` (sec-cierre) · **Fecha:** 10-11-2026 · **Árbol:** backend `_worktrees/ultron-h8/Iron-Body/backend` y CRM `_worktrees/ultron-h8/Iron-Body_Front`, rama `feat/ultron-studio-h9`, todo SIN commit sobre `d701b0c` (backend) y `9572426` (CRM). Producción en `ce7c150` (H9 fase 1, todo apagado).
**Modo:** revisión. No edité ningún fichero del árbol salvo este informe. Las sondas viven en el scratchpad de la sesión (fuera del repositorio); la base PG desechable que creé (`ultron_studio_sec`) quedó borrada. Sin producción, sin red real, sin commit, sin secretos impresos.
**Fuera de alcance (delta posterior):** lo que escriben ahora `b9-cierre` (presupuesto de voz del H9, orden de Meta, carrera de la pausa, textos, m-9, H-1) y `pm2-voz` (plantilla de voz v3).

---

## 0. Veredicto

| | |
|---|---|
| **Bloqueantes para el cierre** (con todo apagado: `SENDS_ENABLED_IN_CODE = false`, `CALLS_ENABLED_IN_CODE = false`, banderas en su defecto) | **0** |
| **Mayores** — no bloquean el cierre; **bloquean ABRIR** el envío automático o las llamadas | **4**: SC-1 (una baja por WhatsApp no alcanza la voz), SC-2 (la palabra de baja deja pasar bajas reales), SC-3 (R8-12: sin webhook post-llamada la voz llama a ciegas), SC-4 (publicar una estrategia con voz no exige autoridad de voz) |
| **Menores** | 11 (SC-5 … SC-15) |
| **Fuera del delta, preexistentes** | 2 (PX-1 mayor operativo: rotar la `APP_KEY` apaga en silencio la lista de no-llamar; PX-2 menor: oráculo 404/401 sin credencial) |
| **Guardas ampliadas de SEC8** (`VoiceIsolationTest`, `VoiceBoundaryTest`) | **VISTO BUENO**; no pido revertir B-T5. Condición no bloqueante: SC-11 |
| **Enmiendas del Anexo B** (`OutreachStaticGuardsTest`, `UltronEngineIsolationTest`, `OutreachIsolationTest`, `test_20`, `OutreachStopAndGateTest:71`) | **VISTO BUENO**: ninguna afloja; dos endurecen. Recomendación: SC-9 |
| **R8-12** | **SÍ**: un problema DEFINITIVO del webhook o de los secretos echa el freno de voz; lo incierto, solo incidente (§6.3) |
| **E · H-A** (`vct_source_chk`) | **Aceptada con condiciones** (§7) |

---

## 1. Alcance, método y modelo de amenazas

**Revisado (lectura de código + ejecución):** A (P9-25), B (W9), C (V9 + Enmienda 17 + L9-81), D (B-T4, B-T17, B-T5, B-T16 y las guardas ampliadas), E (supresión 1581 de la voz). Por cada pieza: quién la llama, qué cerrojo la precede, qué escribe, qué registra y qué pasa si falla.

**Activos:** fotos biométricas y sesión de administrador (A); la reputación y el cumplimiento frente a la SIC (Ley 1581, Ley 2300): escribir o llamar a quien no autorizó o ya dijo que no (B, C); el dinero de la voz (C); los secretos de ElevenLabs/Twilio y los logs (D); la supresión de datos de terceros (E).

**Actores:** quien lee `access.log` o sus copias; un administrador legítimo con permisos de más; el token compartido (n8n); un operador con consola; una persona que pide la baja con sus palabras; ElevenLabs que desactiva un webhook por su cuenta.

**Fronteras:** navegador ↔ API (vale por URL); H9 ↔ Marketing (`dispatchWhatsappTemplate`); H9 ↔ H8 (`requestForStrategy`); Laravel ↔ ElevenLabs (dos GET nuevos); base ↔ operador (SQL de supresión).

---

## 2. Hallazgos (por severidad)

Cada uno: ubicación, escenario, impacto, estado (confirmado o hipótesis), corrección, dueño propuesto y prueba negativa. El dueño es una propuesta: lo asigna el lead.

### Mayores — bloquean abrir el envío o la voz, no el cierre

#### SC-1 · Una baja por WhatsApp no suprime la voz ni las demás finalidades (contradice D9-15)

- **Dónde:** `app/Services/Outreach/Run/SubjectSuppressions.php:40` (`opted_out` suprime solo si coinciden la FINALIDAD **y** el MEDIO del trabajo); lo consume `LadderInputBuilder.php:98` (`isSuppressed(…, $live->purpose, $medio)`). El canal de voz no mira las señales de WhatsApp (`VoiceStrategyChannel.php:97-104`) y el H9 no escribe `marketing_leads.do_not_contact` (Q-6 de W9), así que tampoco lo ve `VoiceExclusionRule` en la puerta ni en el job.
- **Escenario:** una persona con consentimiento de voz comercial (cuatro ojos, de hace meses) responde «No me contacten más» a una plantilla comercial de WhatsApp. El normalizador registra `opted_out` (medio `whatsapp`); la semana siguiente una estrategia comercial de VOZ la inscribe: N1 no ve supresión para el medio `voice` y la puerta del H8 no ve exclusión → la llamada sale. «no me contacten» está en la propia lista cerrada de W9.
- **Impacto:** contacto a quien revocó (Ley 1581: revocación; Ley 2300 art. 5 par. 2). D9-15 dice «toda señal negativa de cualquier fuente gana»: hoy no gana entre medios.
- **Estado:** confirmado por lectura del código (ruta completa); no ejecutado de punta a punta (exige voz abierta en una copia).
- **Corrección:** que un `opted_out` de fuente `inbound_message` (W9) o `voice_call` (`no_volver_a_llamar`) suprima TODOS los medios para esa finalidad (en `isSuppressed`, ignorar el medio para `opted_out`), y decidir con asesoría si una baja comercial alcanza también `service`. Reflejarlo en D9-15. Mientras no esté, NO abrir la voz por estrategia para personas con WhatsApp.
- **Dueño:** lead (D9-15) + backend del H9 (`SubjectSuppressions`); asesoría legal.
- **Prueba negativa:** baja por WhatsApp de la persona P (finalidad comercial) → una estrategia comercial de voz sobre P niega en N1 (`global_suppression` o equivalente) y no llega a `requestForStrategy`.

#### SC-2 · La palabra de baja deja pasar bajas reales (y el docblock dice lo contrario)

- **Dónde:** `app/Services/Outreach/Facts/WhatsappSignals.php:40-45` (27 frases EXACTAS, el mensaje entero), `:175-180` (`isOptOutText`), `:183-207` (`normalize`), `:29-30` («Equivocarse hacia la baja es el lado seguro»), `:51` (`OPT_OUT_SCAN = 200`).
- **Escenario (ejecutado, sonda pura sobre el código del árbol):** NO cuentan como baja «Quiero que me den de baja», «No me vuelvan a escribir», «no me interesa, no me escriban más», «Ya no quiero recibir mensajes», «no me llamen», «dejen de escribirme», «Sáquenme de su lista», «eliminen mi número», «Detener». Tampoco «no más» o «No me escriban más» con la tilde COMBINADA (NFD: `normalize` deja «ma s»), «ＢＡＪＡ» en ancho completo ni «baja» con un espacio de ancho cero. Sí cuentan «BAJA», «Baja por favor», «STOP!!!», «quiero darme de baja», «Por favor no me contacten más».
- **Impacto:** la respuesta no reconocida queda como `replied` → `handoff_human`: esa inscripción para y una persona la ve (mitiga), pero NO hay supresión ni la ve `everOptedOut()`, así que otra estrategia o el ciclo siguiente le vuelve a escribir si nadie marca a mano el `do_not_contact`. El diseño se equivoca hacia el lado inseguro, justo al revés de lo que dice su comentario.
- **Estado:** confirmado (sonda ejecutada; resultados arriba).
- **Corrección:** (1) normalizar con `Normalizer::FORM_KC`/`FORM_KD`, quitar `\p{Mn}` y los invisibles (`\p{Cf}`), como ya hace la guarda de la transcripción de la voz; (2) un detector CONSERVADOR por contención («baja», «stop», «unsubscribe», «no me (escrib|contact|llam)», «no (quiero|deseo) (recibir )?mas», «dejen de», «saqu?enme|quitenme», «elimin(en|ar) mi»…) que marque «posible baja»: el canal no envía (como `opt_out_word`) hasta que una persona la confirme o la descarte en el Inbox. Un falso positivo cuesta una revisión; un falso negativo, un contacto ilegal. La lista exacta puede seguir siendo la que registra `opted_out` automático.
- **Dueño:** backend (dueño de W9); el texto de la baja en las plantillas, el propietario.
- **Prueba negativa:** las 12 frases de arriba → el canal devuelve `failed_to_start` (`opt_out_word` o el código nuevo) y no llama a la puerta; «¿qué es la baja?» → revisión humana, nunca envío automático silencioso.

#### SC-3 · R8-12: con el webhook post-llamada caído la voz llama a ciegas y nada frena

- **Dónde:** `app/Services/Telephony/VoicePostCall.php:168` es el ÚNICO sitio que abre el freno por `guard_violation`; `VoiceWebhookHealth.php:187-189` y `:316-334` solo abren incidente; `app/Models/Voice/VoiceBrakeEntry.php:21` y `vbr_reason_chk` (`database/migrations/2026_11_10_100002_create_voice_tables.php:239`, `:436`) cierran el conjunto de motivos.
- **Escenario:** ElevenLabs desactiva solo el webhook tras 10 fallos (`is_auto_disabled`). Desde ese momento ninguna llamada pasa por la guarda de la transcripción; si el agente ofrece un descuento o promete algo, nada frena y la puerta sigue aceptando salientes. La comprobación de B-T5 corre cada hora (minuto 5) y solo avisa.
- **Impacto:** llamadas comerciales sin la vigilancia que el H8 da por supuesta; el resto del H8 falla cerrado ante lo que no puede comprobar (`drift_unverified`, `number_unverified`), esto no.
- **Estado:** confirmado por lectura del código.
- **Corrección y decisión:** §6.3.
- **Dueño:** backend (h8-hardening, `VoiceWebhookHealth`) + `database-specialist` (enmienda de `vbr_reason_chk`) + lead (enmienda del H8).
- **Prueba negativa:** `webhook_auto_disabled` → freno abierto y `OutboundGate` niega `voice_braked`; `webhooks_unreadable` → incidente medio y NINGÚN freno.

#### SC-4 · Publicar una estrategia con pasos de voz no exige la autoridad de voz

- **Dónde:** `app/Support/Access/AuthorizationMap.php:835-841` (aprobar `ultron.approve`; publicar, promover y reanudar `ultron.strategies_publish`); `app/Services/UltronStudio/StudioStrategyPublishing.php:23-39` y `:339` (cuatro ojos por persona, sin mirar los canales). La llamada manual exige `telephony.call_place` (D8-22).
- **Escenario:** el propietario reparte `ultron.strategies_publish` a una jefa de mercadeo para las campañas de WhatsApp (la pantalla de Roles lo describe como «Publicar en canario, promover y reanudar»). Con la Enmienda 17, esa persona puede publicar una estrategia con un paso `voice_call` y hacer que el sistema llame, aunque no podría pedir una sola llamada a mano.
- **Impacto:** escalada de función por configuración (BFLA latente). Con los permisos POR DEFECTO no es explotable: `strategies_publish` y `call_place` son solo de Super Admin.
- **Estado:** confirmado (código); depende de un cambio de roles.
- **Corrección:** al publicar, promover o reanudar una versión cuyo contenido tenga un paso `voice_call`, exigir además `telephony.call_place` (o `ultron.voice_publish`) de quien publica; y decirlo en la descripción del permiso. Es una línea en `StudioStrategyPublishing` más su prueba.
- **Dueño:** lead + backend del Studio (S9); `architect` si se prefiere un permiso propio.
- **Prueba negativa:** persona con `strategies_publish` y sin `call_place` publica una versión con voz → 403; la misma persona publica una de solo WhatsApp → 200.

### Menores

| Id | Qué y dónde | Escenario e impacto | Corrección · dueño |
|---|---|---|---|
| **SC-5** | La PARADA GLOBAL, la pausa, la reversión y el freno de canal del H9 no alcanzan una saliente que la puerta YA aceptó. `PlaceOutboundVoiceCall.php:150-215` vuelve a mirar solo cerrojos del H8 (frontera D8-2) | Con el worker de voz atascado, la puerta acepta (202), el operador echa la PARADA y el job marca al volver. **Acotado:** `liveCount()` cuenta `requested`, así que como mucho `max_concurrent_calls` (1) filas pendientes, y `VoiceReconciler.php:82`/`:463` pasa a `ambiguous` lo que lleva 15 min en `requested`. La promesa «≤ 60 s» de D9-14 no se cumple para esa llamada | Ya: en el runbook de la PARADA, «con la voz viva, echa también el freno de voz del H8» (lo lee el job). Después: el job rechaza una fila `strategy` con más de N s en `requested` (enmienda del H8). · lead + devops (`ops8-e`) |
| **SC-6** | La finalidad del vale la decide el CAMINO (`*/stream`, `*/face-image/*`), no la ruta ni el método: `StreamTicketService.php:170-177`, `EnsureAdminAuth.php:68-90` | Sonda ejecutada: un vale `stream` autentica `GET /api/admin/marketing/analytics/breakdown/stream`; uno `face-image` autentica `GET /api/admin/receivables/people/face-image/1` y `…/members/face-image/identity/front`. Hoy los controladores responden 422/404 (sin datos), pero una ruta futura con un parámetro libre quedaría abierta al vale | Lista cerrada de `uri()` de ruta + GET/HEAD en `scopeFor()`, y una guarda que compare `route:list` con esa lista. · dueño de P9-25 |
| **SC-7** | `GET admin/stream-ticket` sin limitador (`routes/api.php:149`) y la anotación del vale en la caché `database` (`StreamTicketService.php:84`) sin purga | Una sesión robada o un bucle del CRM (p. ej. el 429 del Studio, que deja el vale sin gastar) acumula filas en `cache` que Laravel no borra. Disponibilidad, no confidencialidad | Limitador por persona holgado (el catálogo facial pide N vales de golpe: medir antes de fijarlo) y purga programada de `admin-stream-ticket:%` caducadas, como `voice:prune-rate-limits`. · dueño de P9-25 + `performance-specialist` para el tope |
| **SC-8** | Residual del vale (P9-25 §5) | El 429 del limitador del Studio va ANTES de la puerta (verificado: `ThrottleRequests` queda primero por la prioridad de Laravel), y los 502/504/499/503 no llegan a PHP: el vale queda en `access.log` sin gastar ≤ 60 s; nginx además escribe la línea con la query en `error_log` en los fallos de upstream. Un vale `face-image` sin gastar abre UNA foto de CUALQUIER socio | nginx: no registrar la query en `*/stream` y `face-image` (access y error). Atar el vale de la foto al `userId` de la ruta. Mejor aún para las fotos: `fetch` con cabecera `Authorization` + `blob:` (sin vale en la URL; exige `img-src blob:` en la CSP del CRM). · devops (`ops8-e`) + dueño de P9-25 |
| **SC-9** | El doble de la constante (`?Closure $sendsEnabledInCode`) en `LadderInputBuilder.php:49,63-65` y `WhatsappTemplateChannel.php:93-100` | La guarda (`OutreachStaticGuardsTest.php:126-127`) caza `sendsEnabledInCode:` y `new X(args)`, no `app(X::class, ['sendsEnabledInCode' => …])`, `makeWith`, Reflection ni un `bind(Closure::class)`. Hoy nadie lo usa (comprobado: ninguna llamada, ningún `Closure::class` en `app`, `bootstrap`, `config`, `routes`) | En los dos constructores: `if ($sendsEnabledInCode !== null && ! app()->runningUnitTests()) throw new LogicException(…)`. · b9-cierre (`LadderInputBuilder`) y dueño de W9 (canal) |
| **SC-10** | Reintentos del outbox de una plantilla del H9 (`WhatsappOutboxService.php:55-150`, rama `:157-164`) | Entre el primer intento fallido y los reintentos (30 s, 60 s, 120 s; ≈ 4 min con `max_attempts` 4) no se vuelve a mirar `canContactProactively()`, la baja de hermanos ni la PARADA; sí la pausa y la toma humana | En la rama de plantillas, antes de reenviar: `canContactProactively()` + `phoneOptedOut()`; si no, `dead` con su motivo. · dueño de W9 |
| **SC-11** | La guarda de «quién llama» a `describeWebhooks`/`describeSecrets` vive en la prueba del autor (`tests/Unit/Voice/ElevenLabsWebhookObserverTest.php:183-203`), no en la de SEC8, y solo barre `app/` | Quitar la prueba de la funcionalidad quitaría la guarda; un cierre en `routes/console.php` no se vería | Copiarla a `VoiceIsolationTest` con el patrón de `describeNumber` (tokens, `app` + `routes`). Condición NO bloqueante del visto bueno (§6.1). · backend (h8-hardening) |
| **SC-12** | B-T17: el rastreo de logs solo ve `CLAVE=valor` al principio de línea (`CheckSecretExposure.php:206-229`, `:374-382`) | No ve un secreto en JSON (`"ELEVENLABS_API_KEY":"…"`), en mitad de una línea de Laravel ni en una cabecera volcada; puede dar falsa tranquilidad. Los canales `channel` y `voice` se crean 0664 | Documentar el límite en la salida de la orden; opcional: buscar también `"CLAVE":"…"` y `CLAVE => '…'`. Logs a 0660 (coincido con el autor). · backend (h8-hardening) + devops |
| **SC-13** | Sin unicidad en la base para «una llamada por trabajo» (D-V9-2) | La idempotencia por trabajo descansa en `pg_advisory_xact_lock` (`VoiceLocks.php`, correcta) más `vc_one_live_outbound`; no hay respaldo en el esquema | Opcional: índice único parcial `(client_request_id) WHERE requested_by_type = 'strategy'`, con su anexo de pruebas del H8. · `database-specialist` |
| **SC-14** | Comentario obsoleto en `OutreachSendGate.php:24-31` | Dice que la voz «sigue con su puerta VACÍA y responde `channel_not_live`», falso desde L9-81; confunde a quien audite la protección 2 | Corregir el texto. · lead |
| **SC-15** | Supresión 1581: redactar `voice_exclusions` (huella aleatoria) hace que la exclusión deje de aplicarse a ese número (H-C 1 del informe de DB) | Quien dijo «no me vuelvan a llamar» y después pidió la supresión vuelve a ser llamable si su número reaparece con un consentimiento nuevo; si no reaparece, ninguna | Recomendación de security: conservar SOLO la huella de la exclusión como marca de supresión (sin 4 dígitos, nota ni nombre), porque sirve a la propia objeción de la persona; lo decide el propietario con su asesoría. · propietario + `database-specialist` |

### Fuera del delta (preexistentes, para que el lead los enrute)

- **PX-1 · mayor operativo · Las huellas de teléfono dependen de la `APP_KEY`.** `app/Services/Telephony/VoicePhone.php:36-56` (`HMAC(APP_KEY, 'voice-phone')`; igual el canario y el Studio). Si se rota la `APP_KEY` (que es lo que pide B-T17 ante una exposición), `voice_exclusions` y el historial de frecuencia dejan de casar en silencio: la lista de no-llamar se apaga y la frecuencia se reinicia (falla ABIERTO); las exclusiones no guardan el número, así que no se pueden recalcular. Corrección: una clave propia para las huellas, separada de la `APP_KEY`, o mirar también las huellas con `APP_PREVIOUS_KEYS`; y un paso del runbook de rotación. · `architect` + backend + devops.
- **PX-2 · menor · La vinculación de modelos corre ANTES de la puerta admin.** Orden efectivo verificado: `SubstituteBindings` → `ProtectAdminPaths` → `EnforceAdminAuthorization`. Sondeado sin credencial: `GET /api/admin/products/999999` y `/api/admin/members/999999` → **404** (un id que existe da 401: deducido del orden, no ejecutado). Un anónimo puede enumerar qué ids existen. · `api-security-specialist`.

---

## 3. A · P9-25, el vale del canal en vivo y de las fotos

**Visto bueno de la corrección**, con SC-6, SC-7 y SC-8 como menores.

- **¿Un vale robado del access.log sirve?** En el camino normal, no: nginx escribe la línea al TERMINAR la petición y la guarda lo canjeó al empezar (un solo uso, `StreamTicketService.php:188-203`). El residual es el de SC-8 (429 del limitador del Studio, 5xx y 499, y el `error_log`): como mucho una conexión SSE de ≤ 55 s o una foto, durante ≤ 60 s. Repetir un vale con 429 cae en el mismo cubo agotado de su dueño, lo que estrecha la ventana. Un vale de formato anterior (`{sid, exp}`) ya no abre nada (`AdminAuthTest::test_un_vale_del_formato_anterior…`).
- **¿Alcance cruzado stream ↔ face-image?** No. La finalidad va dentro del vale cifrado y autenticado y es además el valor de la anotación en la caché; si no coincide con la de la ruta se rechaza SIN gastarlo (`:107-123`, `:202`). Probado en `AdminAuthTest` y `FaceBiometricAccessTest::test_el_vale_de_un_stream_no_abre_la_imagen`. El límite real es SC-6: la finalidad sale del camino.
- **¿Carrera de doble canje?** **Verificada en PostgreSQL 14**, que el autor no probó: 5 rondas × 8 procesos (`pcntl_fork`) con el mismo vale contra un almacén `database` en PG → **1 ganador en cada ronda**, 0 filas en `cache` y `cache_locks`. Mutante «leer, esperar y borrar sin cerrojo» sobre el mismo almacén: **8, 4 y 8 ganadores**. El cerrojo (INSERT sobre la clave primaria de `cache_locks`) es lo que da la atomicidad.
- **¿Fuga en errores o logs?** No en la aplicación: `deny()` registra motivo, `path()` (sin query) e IP (`EnsureAdminAuth.php:120-133`); `ticket_store_failed` solo la clase; el 403 no repite el vale; no hay `fullUrl()` en middleware ni rastreador externo de errores (Sentry y similares no están en `composer.json`). Las respuestas con vale llevan `no-store` y `no-referrer` (`SecurityHeaders.php:48-60`) y la emisión `no-referrer` + `no-store`. El token compartido no obtiene vales (403). En el CRM el vale vive solo en memoria (`cache: 'no-store'`, una generación que descarta lo que llega tras cerrar sesión) y no queda ningún `withStreamToken` ni `ensureStreamTicket` (grep sobre `src/app`).
- **Tres pasadas de la guarda:** la marca `auth_admin_ticket` es un atributo del servidor (el cliente no escribe `attributes`); la segunda y la tercera pasada no vuelven a canjear y otra petición con el mismo vale da 403.
- **Limitador por persona:** verifiqué el orden efectivo con `Router::gatherRouteMiddleware()` y el kernel HTTP cargado: en `api/admin/ultron-studio/stream` va `ThrottleRequests:studio-stream` → `SubstituteBindings` → `ProtectAdminPaths` → …, así que `peekSessionId()` ve el vale sin gastar. Un vale gastado va al cubo anónimo de SU red: repetir los del log no agota el cupo de su dueño.
- **Documentación:** `informe-p9-25.md` sigue con cuatro marcadores `PENDIENTE-*` (pruebas del backend, mutaciones, CRM y suites): la evidencia del autor está incompleta.

---

## 4. B · W9, WhatsApp por plantillas

- **Consentimiento proactivo:** doble y correcto. El canal exige el hecho `consent.whatsapp.<finalidad>` sin negativas (`WhatsappTemplateChannel.php:143`, `personState()` en `:222-235`); la puerta, `canReplyReactively()` + `canContactProactively()` (`consent_status = granted`), la baja de hermanos por número, la pausa del agente y una conversación ya existente (nunca crea una). `service` es más estricto que la matriz (Q-5): bien.
- **Baja:** SC-1 y SC-2 (mayores). Lo que sí funciona: una baja de la lista cancela lo pendiente de la persona en todas las estrategias, cierra sus otras inscripciones y el canal se niega aunque el normalizador no haya pasado.
- **Idempotencia por trabajo:** correcta en tres capas: la reclamación (UPDATE condicionado y `FOR UPDATE SKIP LOCKED`), la transición a `dispatching` antes del canal y, en la puerta, `Cache::lock('marketing:outreach-template:<uuid>')` + búsqueda por `correlation_id` ANTES de insertar; el mensaje existe antes de salir a la red, así que aunque el cerrojo (30 s) caducara durante una llamada lenta a Meta, el segundo vería el primero. Un `ambiguous` nunca se reintenta.
- **¿Se puede inyectar `metadata.kind = outreach_template` para que el outbox mande una plantilla?** No: solo `dispatchWhatsappTemplate` lo escribe; los demás llamadores de `dispatchWhatsapp` fijan el `kind` en el servidor (`InternalMarketingController.php:242-246`, `:376-379`; `MarketingManualReplyService` con `manual_reply`; el resto, constantes).
- **El doble de la puerta:** hoy no lo alcanza ningún código de la aplicación; la guarda estática cubre los dos patrones obvios. SC-9 lo cierra en tiempo de ejecución.
- **Los resultados:** las órdenes `outreach:whatsapp-outcomes` y `outreach:voice-outcomes` ya están programadas dentro del bloque del H9 (`routes/console.php`, detrás de su bandera); apagada la maestra no leen la base.

### 4.1 Las enmiendas del Anexo B: ¿alguna afloja algo? — No. VISTO BUENO

| Enmienda | Lectura |
|---|---|
| `OutreachStaticGuardsTest`: excepción de W9 | Por fichero EXACTO; de la lista prohibida quita solo `MarketingMessageDispatcher`; exige exactamente una `->dispatchWhatsappTemplate(` y cero `->dispatchWhatsapp(`; Meta, el outbox y `Http::` siguen prohibidos en ese fichero. Importaciones: solo esas dos clases y solo ahí |
| Excepción de V9 | Por fichero EXACTO; solo `OutboundGate`; una `->requestForStrategy(` y cero `->request(`. `V9NoBypassStaticTest` además barre `app`, `routes`, `config`, `bootstrap` y `database` (también los cierres de consola) |
| `LIVE_CHANNELS` con `whatsapp_template` y `voice_call` | Es la decisión L9-81; «implementada no es abierta»: la constante sigue en `false` y la guarda del literal no cambia |
| Aserción nueva del doble | ENDURECE (ver SC-9 para lo que no caza) |
| `UltronEngineIsolationTest` | Suma exactamente la migración `2026_11_13_200001` y la tabla nueva; nada más |
| `OutreachIsolationTest` | ENDURECE: `TEMPLATE_META_CHECKS` con lector único (el puente) y en el patrón de escritura prohibida |
| `test_20` y `OutreachStopAndGateTest:71` | Siguen la declaración. La parte de voz de `test_20` ahora para en `channel_mismatch`, pero la cobertura de «canal de voz declarado + constante cerrada → `sends_disabled_in_code`, sin pedir nada» pasó a `V9VoiceChannelTest::test_el_canal_declarado_se_niega_con_la_constante_cerrada_y_no_pide_nada`, en verde en mi corrida |

---

## 5. C · V9, la Enmienda 17 y la voz declarada (L9-81)

### 5.1 Todas las cerraduras entre una estrategia publicada y una llamada real

| # | Cerradura | Dónde | ¿Más débil de lo que parece? |
|---|---|---|---|
| 1 | Autora + aprobación de otra persona (`ultron.approve`) + publicación por persona distinta de la autora (`ultron.strategies_publish`, Super Admin por defecto), con bandera de publicación, ventana, huella vista y simulación aprobada; canario y promoción con evidencia | `StudioStrategyPublishing` | **Sí: SC-4** (no exige autoridad de voz) |
| 2 | Maestra `COMMERCIAL_AUTOMATION_ENABLED` + modo `live` | `OutreachConfig` | No |
| 3 | Programador del H9 (o `outreach:dispatch` a mano por consola) | `routes/console.php` | No (consola = confianza total) |
| 4 | `COMMERCIAL_AUTOMATION_VOICE_ENABLED` | `config/outreach.php:104` | No |
| 5 | `OutreachSendGate::SENDS_ENABLED_IN_CODE = false` (commit `outreach(sends-open):`) | escalera N4 y canal, que en la voz la lee SIN doble (`VoiceStrategyChannel.php:88`) | No |
| 6 | `LIVE_CHANNELS` con `voice_call` | ya declarada (L9-81) | Ya abierta: no cuenta como cerradura |
| 7 | Cupo y presupuesto de voz del H9 > 0 (defecto 0 → `budget_zero`) | `config/outreach.php:106-111` (de `b9-cierre`, fuera de este alcance) | Revisar en el delta |
| 8 | Planificador: escalera N1–N7 y reserva | `OutreachPlanner` | No |
| 9 | Despachador (TOCTOU): banderas, frenos, PARADA GLOBAL, versión viva y verificable, ventana, hechos del instante, escalera completa (consentimiento por finalidad y canal, bajas, supresión, RNE, menores, un contacto al día, calendario legal, `voice_braked`) | `OutreachDispatcher::process()` | **Sí: SC-1** (la supresión es por medio) |
| 10 | Canal: declarada, trabajo de voz en vivo, modo y bandera, constante, reserva no liberada, persona resuelta y su consentimiento de voz del hecho | `VoiceStrategyChannel.php:76-104` | No |
| 11 | Puerta: forma (422), cerrojo (`pg_advisory_xact_lock`), idempotencia por trabajo, destino elegido por la puerta (contacto ACTIVO con consentimiento a cuatro ojos; canario solo en parte canaria + alcance `canary`), solo `commercial` tiene consentimiento | `OutboundGate.php:372-391`, `:432-521`, `:538-574` | No; D-V9-10 aceptado (§5.2) |
| 12 | `OutboundPolicy` (28 motivos): `CALLS_ENABLED_IN_CODE = false` (commit `voice(calls-open):`), `VOICE_OUTBOUND_ENABLED`, `TWILIO_VOICE_ENABLED`, número E.164, colisión, conciliación del número < 2 h, proveedor verificado, publicación de voz de la parte sin deriva, freno de voz, exclusiones, RNE vigente (`VOICE_RNE_VALIDITY_DAYS` = 0 → siempre `rne_check_missing` en `contacts`), calendario y festivos, frecuencia, concurrencia, cupo diario, presupuesto del día | `OutboundGate::decideForStrategy` = los mismos 16 hechos que `decide()` (lo vigila `V9NoBypassStaticTest`) | **Sí, el freno: SC-3** (sin webhook no se echa por violaciones) |
| 13 | Alcance `VOICE_OUTBOUND_SCOPE=canary` (defecto): solo canarios de voz activos, números del personal | `OutboundGate::currentScope()` | No |
| 14 | `VOICE_CONTACTS_ENABLED=false`: sin contactos ni consentimientos nuevos | B-T4 | No cierra las salientes a contactos YA existentes (lo dice el autor; las cierran 12 y 13) |
| 15 | Worker `ironbody-voice` y el job, que vuelve a mirar constante, banderas, número, freno, publicación sellada, deriva (observación real) y destino y calendario justo antes de marcar; `tries = 1` y `failOnTimeout` (un worker muerto no remarca: pasa a `ambiguous`) | `PlaceOutboundVoiceCall.php:150-215` | **Sí: SC-5** (no ve la PARADA ni la pausa del H9; acotado) |
| 16 | Credenciales y número de ElevenLabs/Twilio | `config/voice.php` | No |

### 5.2 D-V9-10 y el token compartido

- **D-V9-10 (la puerta confía en su llamador): riesgo residual ACEPTADO.** Quien tiene consola puede llamar a cualquier servicio, también a `request()` con un `Admin` construido a mano o cambiar el entorno: `requestForStrategy` no añade capacidad nueva a ese actor. Lo que importa (que ningún código de la aplicación la use fuera del canal) lo vigila `V9NoBypassStaticTest` sobre `app`, `routes` (incluido `console.php`), `config`, `bootstrap` y `database`. Y la puerta sigue aplicando TODA la política del H8: lo peor que puede hacer un llamador equivocado es llamar, dentro de todos los límites, a un contacto con consentimiento a cuatro ojos.
- **¿El token compartido puede llegar a ella? No.** No hay ruta HTTP que despache, publique o pida llamadas por estrategia; `telephony`, `ultron` y `commercial_automation` están en `SHARED_TOKEN_EXCLUDED_DOMAINS` (`CrmPermission.php:267`) y el token solo tiene permisos `.view`; no obtiene vales; y los controladores del Studio exigen una persona. Influencia indirecta: las automatizaciones (n8n por las rutas internas firmadas) pueden producir hechos (seguimientos) que inscriban a una persona, pero la llamada sigue exigiendo su consentimiento de voz a cuatro ojos y las 16 cerraduras.
- **El normalizador no cruza solicitantes:** `VoiceCallStates.php:49` enlaza un trabajo `ambiguous` solo con filas `requested_by_type = 'strategy'`; una llamada manual con el mismo `client_request_id` no se confunde con un trabajo del H9.
- **`vc_requester_chk` de V9:** las ramas de DB8 quedan literales y la de `strategy` es estrecha (sin cuenta ni rol, `client_request_id` no nulo, `attempt = 1`, nombre con patrón); `down()` se niega con filas `strategy`. Sin objeciones.

---

## 6. D · Endurecimiento del H8 (B-T4, B-T17, B-T5, B-T16)

- **B-T4 · Visto bueno.** La bandera va en el servicio (vale para cualquier llamador) y en el controlador DESPUÉS del permiso y ANTES de validar (no filtra la forma del cuerpo a quien no tiene permiso); revocar, excluir y RNE siguen abiertos (es la dirección segura). `.env.example` solo gana `VOICE_CONTACTS_ENABLED=false`.
- **B-T17 · Visto bueno**, con SC-12. Dice NOMBRES de claves, nunca valores; mira los `.env*` legibles y ahora los logs legibles por cualquiera.
- **B-T5 · Visto bueno.** Cada lectura hace exactamente un GET (`/v1/workspace/webhooks`, `/v1/convai/secrets?page_size=100`), sin redirecciones, con plazo, la clave solo en `xi-api-key`, sin red si el proveedor no está `UNVERIFIED`, y conserva solo id, URL (en memoria, `toArray()` no la lleva), banderas y tipo de autenticación, o ids de secretos (ni nombres ni valores). El veredicto en caché no lleva URL, ids ni secretos; el estado y el doctor no salen a la red.
- **B-T16 · Visto bueno.** Solo observabilidad; el incidente y el panel llevan id, segundos e intentos, nunca la carga del trabajo. Confirmé además que el job de voz tiene `tries = 1` y `failOnTimeout = true`: un trabajo rescatado de un worker muerto no vuelve a marcar.

### 6.1 Las dos guardas ampliadas de SEC8: VISTO BUENO

`tests/Unit/Voice/VoiceIsolationTest.php:88-92` (`VoiceWebhookObserver.php` en el paquete), `:131-132` (la interfaz con sus dos métodos), `:177-179` (en `RED`, los dos métodos con `get` y nada más), `:197-198` (una petición cada uno); `tests/Feature/Voice/VoiceBoundaryTest.php:105-106` (`voice:webhook-health` en `COMANDOS`). Enumeran, no aflojan: ninguna regla cambia y el código nuevo cumple las que ya estaban (solo GET, sin redirecciones, plazo, la clave por su único lector, host oficial en producción, telefonía sin red, registro solo por `Log::channel('voice')`). **No pido revertir B-T5.** Condición NO bloqueante: SC-11. Al aplicar este visto bueno, quitar las marcas «PENDIENTE DEL VISTO BUENO DE SEC8» de los comentarios.

### 6.2 Programación

`voice:webhook-health` va en el bloque de voz (minuto 5, sin solapes, un servidor) y solo con `VOICE_MONITOR_ENABLED`; con la bandera apagada no sale a la red. El mando de `ops8-e` tiene que contar 5 tareas de voz (lo dice el autor).

### 6.3 Decisión sobre R8-12: **SÍ, frenar** (antes de la fase 4a)

- **Frenan** (además del incidente alto) los problemas DEFINITIVOS: `webhook_auto_disabled`, `webhook_disabled`, `webhook_not_found`, `webhook_url_mismatch`, `webhook_auth_not_hmac`, `tool_secret_not_found` e `initiation_secret_not_found`. Sin el webhook no corre la guarda de la transcripción (el único que abre `guard_violation` es `VoicePostCall.php:168`); sin el secreto de herramientas, la herramienta `no_volver_a_llamar` no puede registrar la baja hablada.
- **No frenan** (solo el incidente medio de hoy) los INCIERTOS: `webhooks_unreadable`, `secrets_unreadable`, `secrets_list_truncated`, `provider_not_ready`, `base_url_invalid` y las referencias que faltan en Laravel. Un fallo pasajero de ElevenLabs no debe parar la voz ni obligar a soltar el freno cada hora.
- **Radio:** el freno solo lo leen la puerta de salientes y el job (las entrantes no lo miran): para exactamente lo que necesita la vigilancia.
- **Cómo:** motivo nuevo `webhook_unhealthy` en el conjunto cerrado (`VoiceBrakeEntry::REASONS` y `vbr_reason_chk`, con una migración DROP + ADD como la de V9) y `VoiceBrake::openOrRearm('webhook_unhealthy', 'webhook:<huella de los problemas>', …)` desde `VoiceWebhookHealth`; soltarlo es el acto de siempre, con su autoridad, y obliga a mirar las llamadas hechas a ciegas.
- **Complemento recomendado:** una llamada terminada sin post-llamada en 15 min → el mismo freno (detecta en minutos; la comprobación horaria deja hasta una hora a ciegas).
- Hoy, con el monitor de voz apagado y las llamadas cerradas, no cambia nada en producción.

---

## 7. E · Supresión 1581 de la voz

- **Visto bueno del guion y de la prueba.** Una transacción, todo o nada; se apagan solo los disparadores de guarda conocidos (`voice_forbid` en la lista blanca) y se reactivan exactamente esos; aborta con lo vivo; corre como dueño sin superusuario; no toca la cadena firmada; la fila del libro no lleva nombre, número ni huella; el paso 2b no pone el número en la línea de órdenes ni en el historial (fichero 600, `stdin`, `umask 077`, `shred`). La prueba ya llama a `Http::preventStrayRequests()` (`VoiceSuppression1581Test.php:116`), así que el rojo que vio h8-hardening en `VoiceIsolationTest` ya no está (mi corrida: verde).
- **H-A (`vct_source_chk`): aceptada con condiciones.** Admitir `source_id IS NULL` solo con `status = 'retired'` no afloja nada de un contacto vivo, y sin ello el historial de llamadas de quien pidió la supresión sigue enlazado a su ficha. Condiciones: (1) la regla nueva tal cual la propone el informe (`source_id IS NOT NULL OR status = 'retired'`), en una migración DROP + ADD con `lock_timeout`; (2) comprobar que nada lee `source_id` de un retirado: la puerta y el job ya filtran `status = active` y `contactTarget()` solo mira el lead con `source_id` no nulo; (3) el guion pone `source_id = NULL` solo en retirados, dentro de la misma transacción, con su prueba y su mutación. Antes de la 4b. · `database-specialist` + backend.
- **H-C:** (1) ver SC-15; (2) conservar la evidencia del consentimiento cuando la normativa lo pide es correcto, siempre que no lleve el número en claro (el guion lo redacta por texto). Decide el propietario con su asesoría.
- **PX-1** (la `APP_KEY` en las huellas) afecta directamente a esta supresión: las huellas del paso 2b solo casan mientras no se rote la clave.

---

## 8. Lo que verifiqué ejecutando (comando → resultado)

Todo desde `backend/`, un proceso pesado a la vez, `--no-logging --do-not-cache-result`.

| # | Qué | Resultado |
|---|---|---|
| 1 | `php -d memory_limit=2G vendor/bin/phpunit … tests/Feature/UltronStudio/Stream/UltronStudioStreamTicketTest.php tests/Feature/UltronStudio/Stream/UltronStudioStreamRateLimitTest.php tests/Feature/Outreach/Guards/OutreachStaticGuardsTest.php tests/Feature/Outreach/V9/V9NoBypassStaticTest.php tests/Unit/Voice/VoiceIsolationTest.php tests/Feature/Voice/VoiceBoundaryTest.php` (SQLite) | **OK (47 tests, 50 871 assertions)** |
| 2 | `… tests/Feature/Outreach/V9 tests/Feature/Outreach/W9 tests/Feature/UltronStudio/OutreachIsolationTest.php tests/Feature/UltronStudio/UltronEngineIsolationTest.php tests/Feature/Telephony/VoiceContactsFlagTest.php tests/Feature/Telephony/VoiceWebhookHealthTest.php tests/Unit/Voice/ElevenLabsWebhookObserverTest.php tests/Feature/Security/SecretExposureVoiceKeysTest.php tests/Feature/Attendance/FaceBiometricAccessTest.php tests/Feature/AdminAuthTest.php` (SQLite) | **OK (175 tests, 25 387 assertions, 30 skipped**: solo PG y la vía abierta de V9, que solo corre en su copia) |
| 3 | Sonda propia: carrera de canje con 8 procesos contra un almacén `database` en PG 14 (base desechable `ultron_studio_sec`, `PGGSSENCMODE=disable`; borrada al terminar) | Código real: **1 ganador × 5 rondas**, 0 filas en `cache` y `cache_locks`. Mutante sin cerrojo: **8, 4 y 8 ganadores** |
| 4 | Sonda propia: la finalidad del vale por camino (`EnsureAdminAuth::challenge`) | `GET …/analytics/breakdown/stream` con vale `stream` y `GET …/receivables/people/face-image/1` y `…/members/face-image/identity/front` con vale `face-image`: **autentican**. Los controladores devuelven 422/404 (leído) |
| 5 | Orden efectivo del middleware (`tinker` + `Router::gatherRouteMiddleware()` con el kernel HTTP) | Studio: `ThrottleRequests:studio-stream` primero; foto y `memberships/stream`: `SubstituteBindings` → `ProtectAdminPaths` → `EnforceAdminAuthorization` → `EnforceTrainerMemberScope` → `EnsureAdminAuth` |
| 6 | Sonda pura de `WhatsappSignals::isOptOutText()` con 27 textos | Los falsos negativos de SC-2 (naturales, NFD, ancho completo, ancho cero) |
| 7 | Sonda propia sin credencial | `GET /api/admin/products/999999` y `/api/admin/members/999999` → **404**; `/api/admin/receipts/999999` y `/api/admin/ultron-studio/stream` → 401 (PX-2) |
| 8 | `php artisan route:list --json` (1 027 rutas) | 13 rutas con `/stream` final o `face-image`: todas GET (SSE y la foto); más las que casan por un parámetro (SC-6) |
| 9 | Barrido de patrones de secreto en los 278 ficheros cambiados o nuevos (solo nombres de patrón, sin valores) | 2 coincidencias, ambas de prueba: la `APP_KEY` de fixture que ya comparten 15 ficheros versionados (`VoiceFixtures.php`, perf-h8…) y un SID de Twilio ficticio. Las 4 claves nuevas del ejemplo de entorno: vacías, `false` o 0 |
| 10 | Lectura del diff completo de las cinco áreas y de los informes de sus autores | — |

Las sondas están en el scratchpad de la sesión (`probes/SecCierreTicketProbeTest.php`, `probes/SecCierreBindingOrderProbeTest.php`, `optout_probe.php`, `mworder.php`), no en el árbol.

## 9. Lo que NO pude verificar

- **El CRM:** no corrí sus specs (`auth.stream-ticket.spec.ts`, `face-recognition.service.spec.ts`, `moderation-realtime.service.spec.ts`) ni un navegador real; lo revisé por lectura del diff. Tampoco nginx real (que la línea del access.log se escriba al terminar y qué deja en `error_log`).
- **Las suites en PG** (salvo la sonda de la carrera): me apoyo en las corridas de los autores (V9 71/71, W9 37/37, Telephony de h8-hardening, la 1581 en PG). La 1581 no la volví a correr.
- **Producción:** cuántos `voice_contacts` existen hoy (B-T4 no los cierra), a quién está repartido `ultron.strategies_publish` (SC-4), que la `APP_KEY` de producción no sea la de fixture, los permisos reales de los logs y si `IRON_GUARD_ENABLED` está encendido (B-T16).
- **ElevenLabs real:** la forma de `/v1/workspace/webhooks` y `/v1/convai/secrets` y si la clave de producción puede leerlas (B-T5).
- **La vía abierta de V9** (`v9-abierta.sh`): no la ejecuté; tampoco la regresión completa ni mutaciones propias sobre el código revisado.
- **La lectura jurídica** (SC-1, SC-2, SC-15, H-C): es del propietario con su asesoría.
- **El delta en curso** de `b9-cierre` y `pm2-voz`.

---

## 10. Resumen para el lead

- **Cierre:** 0 bloqueantes con todo apagado. Visto bueno de P9-25, W9, V9 (Enmienda 17), B-T4/B-T17/B-T5/B-T16 y la 1581.
- **Guardas:** visto bueno de las dos ampliadas de SEC8 (no revertir B-T5; SC-11 como condición no bloqueante) y de todo el Anexo B (ninguna enmienda afloja; dos endurecen).
- **Antes de abrir el envío o la voz (mayores):** SC-1, una baja por WhatsApp no alcanza la voz (D9-15 no se cumple entre medios); SC-2, la palabra de baja no reconoce bajas naturales ni tildes combinadas; SC-3, R8-12 = SÍ frenar ante un problema definitivo del webhook o de los secretos (motivo nuevo `webhook_unhealthy`, enmienda de `vbr_reason_chk`); SC-4, publicar con voz debe exigir `telephony.call_place`.
- **Cerraduras de la voz:** 16 enumeradas; las más débiles de lo que parecen son la autoridad de publicación (SC-4), la supresión por medio (SC-1), el freno sin webhook (SC-3) y la PARADA del H9, que no alcanza una saliente ya aceptada (SC-5: acotada a `max_concurrent_calls` y 15 min). El token compartido no llega a `requestForStrategy`; D-V9-10 queda aceptado.
- **Vale:** carrera verificada en PG (1 de 8, cinco rondas; sin cerrojo, 8/4/8). Menores: la finalidad por camino (SC-6), sin limitador ni purga (SC-7), residual en nginx y foto sin atar a su id (SC-8).
- **Fuera del delta:** PX-1 (rotar la `APP_KEY` apaga en silencio la lista de no-llamar) para `architect`; PX-2 (404/401 sin credencial) para `api-security-specialist`.

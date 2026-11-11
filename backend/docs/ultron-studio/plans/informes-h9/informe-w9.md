# Informe W9 · WhatsApp por plantillas de la automatización comercial (Hito 9)

**Paquete:** W9 (`backend`). **Contrato:** `docs/ultron-studio/ULTRON_STUDIO_H9_CONTRACT.md` (§3 protecciones 2, 4, 5, 10, 11 y 12; D9-2, D9-6, D9-8 etapas 5–8, D9-9.3 «W-B», D9-10, D9-15; §6.1, §9.3, §10, fila W9 de §11; P9-5, P9-7, P9-8, P9-13, P9-15; L9-1…L9-77). **Árbol:** `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h9` (HEAD `d701b0c8` = `origin/main`), **sin commit**. **Fecha:** 10-11-2026 (reloj UTC). **Sin red real, sin producción, sin n8n, sin OTP, sin ULTRON Legacy.** La constante `OutreachSendGate::SENDS_ENABLED_IN_CODE` **sigue en `false`**: ninguna prueba la gira (la «puerta abierta» es un doble inyectado).

## 0. Resumen en cifras

| Qué | Cifra |
|---|---|
| Ficheros nuevos de código | 7 (el DTO de Marketing, la migración, 2 adaptadores de `Facts`, el normalizador `WhatsappTemplateOutcomes`, la orden `outreach:whatsapp-outcomes` y el canal, reescrito desde la puerta vacía): 1 521 líneas con su PHPDoc; pruebas, 1 438 |
| Ficheros existentes tocados | 6, todos enumerados en §2: `MarketingMessageDispatcher` (+168/−0), `WhatsappOutboxService` (+41/−2), y de B9-run `OutreachSendGate` (+8/−5), `OutreachTables` (+3), `OutreachPublishedStrategies` (+88), `LadderInputBuilder` (+13). Nada de `OutreachDispatcher`, `OutcomeRecorder`, `OutreachConfig`, `config/outreach.php`, `VoiceStrategyChannel`, Telephony ni Voice |
| Pruebas nuevas | 37 en `tests/Feature/Outreach/W9/` (5 ficheros + el andamio `Support/W9World.php`) |
| SQLite | **37/37**, 375 aserciones (2 omitidas: solo PG) |
| PostgreSQL (`UNIT_DB=ultron_studio_w9`) | **37/37**, 391 aserciones (1 omitida: solo SQLite) |
| Red | Cero salvo el Graph FALSO de Meta (`Http::fake` solo para `graph.facebook.com`), con `Http::preventStrayRequests()` en todas |
| Regresión de Legacy (dirigida) | **328/328** (1 762 aserciones): despachador, outbox, pausa del agente, opt-out por teléfono, superficie del secreto interno, `send-message`, ULTRON commit, enlaces de pago, caos, IRON GUARD y valoraciones. La completa de Marketing (2 234) se paró por la carga de la máquina: §9 |
| Pint | En verde en todo lo mío. `WhatsappOutboxService.php` ya incumplía Pint en HEAD (mismos fijadores): no se reformatea para no ensuciar el diff de Legacy; mis líneas añadidas son limpias (comprobado, §8) |
| Guardas | Sin los anexos, 7 aserciones rojas de 5 pruebas, todas previstas y por imports o nombres LEGÍTIMOS del contrato (§5, con fichero:línea). **Al cierre, los Anexos A y B están aplicados en el árbol tal cual (no por mí: `git apply --check -R` lo confirma)**: Guards + Run + aislamientos = 102 pruebas, **1 roja y es de V9** (`VoiceStrategyChannel` importa `OutboundGate`) |
| Recuentos `ultron_studio_%` | 9 aserciones en SQLite (y sus gemelas de PG) suben por la tabla nueva: Anexo A, verificado en un clon (SQLite 379/379; PG con toda la suite de esquema de DB9, 698/698) y, ya aplicado, en el árbol (SQLite 379/379) |

## 1. Diseño

```
planificador (etapas 4-5; la escalera N1–N7 con la constante) ── trabajo `whatsapp_template` en vivo con su reserva
        │
despachador (etapa 6: TOCTOU completo) ── la escalera otra vez (N2: pausa del agente, frenos; N4: la constante…)
        │
WhatsappTemplateChannel::attempt()  ← sus PROPIOS cerrojos (la red de debajo), §1.1
        │   (nunca nombra Meta, el outbox ni Http)
MarketingMessageDispatcher::dispatchWhatsappTemplate()  ← las guardas de SIEMPRE + canContactProactively
        │   crea `marketing_messages` como hoy (remitente `ai`, correlation_id = uuid del trabajo)
WhatsappOutboxService::deliver() ── rama de plantillas → MetaMessagingService::sendWhatsappTemplate (ya existía)
        │
webhook de Meta, el de siempre (sin tocar) ── `marketing_message_statuses` y los entrantes en `marketing_messages`
        │
WhatsappTemplateOutcomes (outreach:whatsapp-outcomes) ── LEE eso (Facts\WhatsappSignals) → OutcomeRecorder
```

### 1.1 El canal (`Run/Channels/WhatsappTemplateChannel`)

Una petición, sin reintentos. Antes de llamar a la puerta vuelve a mirar, por su cuenta (cada uno devuelve `failed_to_start` con su código y la reserva se libera en `settle()`):

| # | Cerrojo | Código |
|---|---|---|
| 1 | La constante (por defecto `OutreachSendGate::sendsEnabledInCode()`) | `sends_disabled_in_code` (el MISMO código que N4; §7 Q-1) |
| 2 | Trabajo de este canal y en vivo; maestra en `live`; `COMMERCIAL_AUTOMATION_WHATSAPP_ENABLED`; puerta implementada | `job_channel_mismatch`, `mode_not_live`, `channel_disabled`, `channel_not_live` |
| 3 | Cupo y presupuesto reservados al planificar | `budget_not_reserved` |
| 4 | La plantilla del SELLO aprobada en el Studio, de su huella, de la finalidad y con su categoría (puente) | `template_not_approved` |
| 5 | Su correspondencia con una plantilla APROBADA por Meta (última fila de `ultron_studio_template_meta_checks`: estado, huella del Studio, nombre, idioma, categoría y huella del texto) | `template_not_approved_by_meta` |
| 6 | La persona por sus HECHOS de este instante (foto de `Facts`): resuelta, con identidad de WhatsApp y con `consent.whatsapp.<finalidad>` sin negativas (`lead.consent_denied`, `do_not_contact`, hermanos) | `unknown_subject`, `no_whatsapp_identity`, `no_consent_for_purpose_channel` |
| 7 | Una palabra de baja que la persona escribió ALGUNA VEZ por WhatsApp, o una respuesta a un contacto anterior de ESTA inscripción, aunque el normalizador no haya pasado | `opt_out_word`, `reply_pending` |
| 8 | Los marcadores (Laravel, con los hechos de este instante) y el texto compuesto otra vez contra R-01/02/03/06 | `template_marker_unresolvable`, `template_violates_restriction`, `template_parameter_invalid` |

Lo que devuelve la puerta se traduce así (`fromGate()`, probado caso por caso): guarda de la puerta → `failed_to_start` con su motivo (`do_not_contact`, `no_proactive_consent`, `lead_without_phone`, `do_not_contact_sibling`, `agent_paused`, `no_whatsapp_conversation`); simulacro (Meta apagado) → `failed_to_start` `meta_disabled_or_unconfigured` (no llegó a nadie); aceptado por Meta o con reintento programado en el outbox → `started` con `marketing_message_id`; rechazo definitivo → `failed_to_start` `meta_error_<código>`; la puerta lanzó → `ambiguous` si ya existe un mensaje con la correlación, si no `failed_to_start` (`gate_error`).

**Marcadores (D9-6) que W9 resuelve** (`Facts\WhatsappRecipient`, solo lectura): `first_name` (ficha del socio, si no el prospecto; solo letras, 2–30, mayúscula inicial: un apodo raro no se usa), `gym_name` (`legal.brand`), `plan_name`, `membership_end_date_text` («22 de noviembre de 2026») y `days_to_expiry`. **Fallan cerrado** (`template_marker_unresolvable`): `class_name` y `class_time_text` (la ocurrencia no viaja en el sello), `payment_link` (crear un enlace escribiría pagos, que el H9 nunca escribe) y `plan_price` (pide elegir el plan vendible y su formato: decisión del propietario, P9-13).

### 1.2 La puerta de Marketing (`dispatchWhatsappTemplate`, D9-9.3 «W-B»)

Las guardas de `dispatchWhatsapp()` en su orden (`canReplyReactively`, el teléfono, el opt-out de los hermanos por número normalizado, la pausa del agente) **más** `canContactProactively()` (escribir primero exige `granted`) y una conversación de WhatsApp YA existente (esta ruta nunca crea conversaciones). El mensaje se crea con `recordOutbound()` (sin cambios: remitente `ai`, el apunte del Inbox en la conversación), `metadata = {kind: outreach_template, template: {name, language, category, hash, parameters}, outreach_job_uuid, recipient}` y `correlation_id` = el uuid del trabajo (se pone en el `Context` durante la llamada y se devuelve el anterior, también si algo lanza). **Idempotente por trabajo** (un trabajo es un intento): bajo `Cache::lock('marketing:outreach-template:<uuid>')` se busca antes de insertar el saliente con esa correlación; si existe, no se crea ni se entrega otro (`replayed: true`; los reintentos son del outbox). Meta apagado: simulacro, como hoy.

### 1.3 El outbox

Una rama nueva al elegir cómo sale: si `metadata.kind = outreach_template`, sale por `sendWhatsappTemplate` con lo guardado (`OutreachTemplateMessage::fromStored()`), **también en los reintentos de `retry-outbox`** (sin esta rama un reintento saldría como texto libre). Si lo guardado no tiene la forma del contrato, no sale nada (`template_metadata_invalid`, definitivo, `dead`). Todas las barreras de antes (ya entregado, `dead`, persona al mando, pausa del agente por fecha de nacimiento) siguen delante, sin cambios.

### 1.4 Los normalizadores (`Run/WhatsappTemplateOutcomes`, D9-10)

Un ADAPTADOR sobre el camino de siempre: el webhook guarda como hoy; la pasada lee (`Facts\WhatsappSignals`) y registra por la única ingesta (`OutcomeRecorder`), una vez por `(inscripción, fuente, job:<uuid>:<resultado>)`:

- `meta_status`: `sent`, `delivered`, `read` (no terminales) en el orden real de la entrega aunque Meta los mande desordenados; `failed` solo el que MOVIÓ el estado (un `failed` tardío sobre un mensaje entregado no es un fallo; regla de `recordStatus`), con `meta_error_<código>`; el outbox que se rindió sin aceptación de Meta (`dead`) → `failed`.
- `inbound_message`: el primer entrante por WhatsApp de la persona entre este saliente y el siguiente del H9 → `replied` (por defecto `handoff_human`: «nunca otro contacto automático»); si alguno es una PALABRA DE BAJA → `opted_out` en su lugar (`end_exit` diga lo que diga la estrategia, cancela lo pendiente de la persona en TODAS las estrategias con su reserva, cierra sus otras inscripciones y la suprime por finalidad y medio vía `SubjectSuppressions`). **No se interpreta el texto** (eso es AI9): el texto se lee solo para la lista cerrada y no sale del adaptador.
- **Palabra de baja**: el mensaje ENTERO normalizado (minúsculas, sin tildes, signos ni emojis, sin «por favor/gracias» alrededor) tiene que ser una de 27 frases cerradas («baja», «stop», «no más», «no me escriban (más)», «darme de baja», «detener promociones», «unsubscribe»…). «no me interesa el plan anual», «¿qué es la baja?» o «stop motion» NO lo son (probado).
- **Concilia los `ambiguous`**: si el proceso murió tras crear el mensaje, la conciliación de B9-run lo deja `ambiguous`; el normalizador lo une a su mensaje por la correlación (`marketing_message_id`, una vez) y registra lo que dijo Meta. Uno sin mensaje se queda para una persona.
- Trabajos de WhatsApp en vivo de los últimos 30 días, ≤ 500 por pasada. **Sin fila en `outreach_runs`** (como el normalizador de la voz de V9): el panel enseña la última pasada por tipo (`OutreachReadModels.php:41-42`) y una de cada minuto taparía la ingesta de `commercial_events` del planificador; la huella son los resultados y la orden imprime los recuentos. Maestra apagada o modo `off`: no hace nada y lo dice.

### 1.5 `ultron_studio_template_meta_checks` (migración `2026_11_13_200001`)

Solo inserción; manda la ÚLTIMA fila de cada plantilla (un cambio de estado en Meta es una fila nueva). Columnas: `uuid`, `client_request_id`, `template_id` (referencia SIN FK, §7 Q-4), `template_content_hash`, `meta_name`, `language`, `category`, `meta_body_hash` (del texto aprobado solo su huella: ningún texto ni dato de personas), `meta_template_id`, `status` (`APPROVED|REJECTED|PAUSED|DISABLED`), `checked_at`, `recorded_by_admin_id/name` (instantánea de F), `created_at`. Guardas en los dos motores: `ustmc_forbid_update`, `ustmc_forbid_delete` (función de F `ultron_studio_forbid()`, sin redeclarar), `ustmc_template_of_whatsapp` (la plantilla existe, es de WhatsApp y es de esa huella; en PG función propia plpgsql INVOKER, sin SECURITY DEFINER, tabla cualificada); en PG además `ustmc_forbid_truncate` y 4 CHECK (`ustmc_status_chk`, `ustmc_language_chk`, `ustmc_category_chk`, `ustmc_shape_chk`). `REVOKE UPDATE, DELETE` del rol de la aplicación con su comprobación y la guarda de producción de DB7/DB8/DB9; `lock_timeout`; `down()` se niega con filas. **La escritura** (registrar lo que aprobó Meta) no tiene ruta ni orden en W9: §6.

## 2. Diff enumerado

### 2.1 Marketing (D9-9.3: «Diff permitido: el método nuevo, la rama de plantillas y un DTO nuevo. Nada más»)

| Fichero | Cambio | Líneas |
|---|---|---|
| `app/Services/Marketing/OutreachTemplateMessage.php` | **Nuevo** (el DTO de W-B) | +180 |
| `app/Services/Marketing/MarketingMessageDispatcher.php` | **Solo se añade**: 3 `use` (`Closure`, `Cache`, `Context`), el método público `dispatchWhatsappTemplate()` y dos privados nuevos (`templateConversation()`, `withCorrelation()`) que solo usa él. Ninguna línea existente cambia | +168 / −0 |
| `app/Services/Marketing/WhatsappOutboxService.php` | La rama de plantillas: la sentencia que elegía texto o adjunto pasa a `$result = $template ?? (…lo de antes…)` (2 líneas tocadas: el principio y el paréntesis final) y un método privado nuevo `outreachTemplateResult()` | +41 / −2 |

Fuera de esos tres: nada en `app/Services/Marketing/**` (ni `Ultron/**`), ni rutas, ni `config/marketing.php`, ni `routes/marketing.php`, ni `app/Http/Controllers/Api/Internal/**` (la puerta interna firmada no alcanza el método: no hay ruta nueva). La puerta de diff de `OutreachIsolationTest` (Legacy, n8n, OTP, motor comercial) sigue vacía (verde).

### 2.2 Ficheros de B9-run (ninguno de los prohibidos por el encargo)

| Fichero | Cambio | Por qué |
|---|---|---|
| `Run/OutreachSendGate.php` | `LIVE_CHANNELS` + `whatsapp_template` y su PHPDoc. **La constante no se toca** | W9 implementa la puerta; «implementado no es abierto»: con la constante en `false`, N4 sigue negando (`sends_disabled_in_code`) y el canal tampoco envía. La guarda y dos pruebas de B9-run fijaban el estado del 15-11: §5 |
| `Run/OutreachTables.php` | `TEMPLATE_META_CHECKS = 'ultron_studio_template_meta_checks'` | Los nombres de tablas del Studio viven aquí (L9-51) |
| `Run/OutreachPublishedStrategies.php` | `approvedWhatsappTemplate()`, `metaApproved()` y `restrictionViolations()` (passthrough a la única implementación del Studio). Solo lectura, nunca lanza | El puente es el único lector de tablas del Studio |
| `Run/LadderInputBuilder.php` | Parámetro OPCIONAL de constructor `?Closure $sendsEnabledInCode = null` (null = la constante) | El doble de la puerta para ensayar el camino entero con la constante «abierta» solo en la prueba (el encargo: «usa un doble de la puerta o haz la constante consultable por método»). En producción nadie lo pasa |
| `Run/Channels/WhatsappTemplateChannel.php` | De puerta vacía a canal real (§1.1) | Es el fichero de W9 (D9-2) |

### 2.3 Nuevos de W9

`app/Services/Outreach/Facts/WhatsappRecipient.php` (TABLES: `members`, `marketing_leads`, `marketing_conversations`, `users`), `app/Services/Outreach/Facts/WhatsappSignals.php` (TABLES: `marketing_messages`, `marketing_message_statuses`, `marketing_conversations`), `app/Services/Outreach/Run/WhatsappTemplateOutcomes.php`, `app/Console/Commands/Outreach/OutreachWhatsappOutcomesCommand.php` (`outreach:whatsapp-outcomes {--json}`), `database/migrations/2026_11_13_200001_create_ultron_studio_template_meta_checks_table.php`, `tests/Feature/Outreach/W9/**` y este informe.

## 3. Contratos nuevos

- **`MarketingMessageDispatcher::dispatchWhatsappTemplate(MarketingLead $lead, OutreachTemplateMessage $template, string $outreachJobUuid): array`** → las claves de `dispatchWhatsapp()` más `replayed`, `status` (el del mensaje tras el outbox) y `provider_error_code`. Motivos nuevos: `no_proactive_consent`, `no_whatsapp_conversation`, `already_dispatched`.
- **`OutreachTemplateMessage`** (final, inmutable): `name` (`[a-z0-9_]{1,64}`), `language` (`es|es_CO`), `category` (`marketing|utility`), `templateHash` (sha256), `parameters` (≤ 10; 1–200 caracteres; sin control, saltos, tabuladores ni 5 espacios seguidos: Cloud API los rechaza), `body` (≤ 1 024). `components()`, `metadata($uuid)`, `isTemplate()`, `fromStored()`.
- **`marketing_messages`** de W9: `sender_type = ai`, `metadata.kind = outreach_template`, `metadata.outreach_job_uuid`, `correlation_id` = uuid del trabajo.
- **Resultados**: fuentes `meta_status` (`sent`, `delivered`, `read`, `failed`) e `inbound_message` (`replied`, `opted_out`), claves `job:<uuid>:<resultado>`; las admite ya la tabla de DB9 (`SOURCE_OUTCOMES`) y `OutcomeTable`.
- **Códigos de detalle del canal** (cerrados, ≤ 48): los de §1.1 más `meta_disabled_or_unconfigured`, `meta_error_<n>`, `provider_send_failed`, `human_takeover`, `gate_refused`, `gate_error`, `already_dispatched`. El CRM (FE9) no tiene texto para los nuevos: §6.
- **Orden** `outreach:whatsapp-outcomes [--json]`.

## 4. Variables, llaves y configuración

**Ninguna nueva.** W9 usa las del contrato que ya existen (B9-run): `COMMERCIAL_AUTOMATION_WHATSAPP_ENABLED`, `…_WHATSAPP_DAILY_CAP`, `…_WHATSAPP_DAILY_BUDGET_USD`, `OUTREACH_WHATSAPP_PRICE_MARKETING_USD`, `OUTREACH_WHATSAPP_PRICE_UTILITY_USD` (sin precio, la reserva vale 0 micros: conviene ponerlo antes de la fase 4), la maestra y el modo; de Meta, las de siempre (`meta.*`); `legal.brand` para `gym_name`. **Sin rutas, permisos ni llaves nuevas**; ningún cambio en `AuthorizationMap` ni en `.env.example`. El cerrojo de idempotencia usa el almacén de caché de la aplicación (como el de las conversaciones del webhook): si en producción es `database`, deja filas efímeras en `cache_locks`.

## 5. Guardas que el trabajo legítimo de W9 pone en rojo (las decide el lead; NO las he relajado)

Ejecutado sobre el árbol (§8): `tests/Feature/Outreach/Guards` + `tests/Feature/Outreach/Run` + `OutreachIsolationTest` + `UltronEngineIsolationTest` → 102 pruebas, **7 rojas**, todas de W9 y previstas (más una línea ajena, de V9, en la misma aserción que la 3):

| # | Fichero:línea | Qué dice | Por qué es legítimo (contrato) | Propuesta (Anexo B) |
|---|---|---|---|---|
| 1 | `tests/Feature/Outreach/Guards/OutreachStaticGuardsTest.php:101` | `LIVE_CHANNELS === ['simulated', 'assisted']` («W9 y V9 añaden sus canales con su enmienda») | W9 implementa la puerta (D9-9.3) | `['simulated', 'assisted', 'whatsapp_template']` |
| 2 | `…/OutreachStaticGuardsTest.php:54` (`FORBIDDEN` incluye `MarketingMessageDispatcher`) | El canal nombra el despachador | D9-2 «Puertas de los canales (W9): `MarketingMessageDispatcher::dispatchWhatsappTemplate`»; la protección 4 prohíbe Meta, el outbox, ElevenLabs, Twilio y `Http::`, no el despachador | Excepción con la forma de la de ULTRON Legacy: SOLO `Run/Channels/WhatsappTemplateChannel.php`, y en él exactamente una llamada `->dispatchWhatsappTemplate(` y ninguna `->dispatchWhatsapp(`; el outbox y Meta siguen prohibidos |
| 3 | `…/OutreachStaticGuardsTest.php:72-83` (`ALLOWED_FOREIGN_IMPORTS`) | `WhatsappTemplateChannel.php` importa `App\Services\Marketing\MarketingMessageDispatcher` y `…\OutreachTemplateMessage` (la misma aserción lista también `VoiceStrategyChannel.php: App\Services\Telephony\OutboundGate`, que es de V9) | Ídem | Las dos clases, SOLO para ese fichero (`W9_GATE_IMPORTS`) |
| 4 | `tests/Feature/UltronStudio/UltronEngineIsolationTest.php:131-134` (`ALLOWED_H9_MIGRATIONS`) | La migración de W9 nombra `ultron_studio_*` | §6.1: la tabla es del Studio | Añadir su ruta exacta |
| 5 | `…/UltronEngineIsolationTest.php:182-188` (`H9_TABLE_NAMES_REFERENCES`; cae en `test_fuera_del_studio…` y en `test_hito_9_los_lectores…`:495) | `OutreachTables` nombra una sexta tabla | Solo la lee el puente | Añadir `'ultron_studio_template_meta_checks'` |
| 6 | `tests/Feature/Outreach/Run/OutreachStopAndGateTest.php:71` (B9-run) | `channelLive` de los canales automáticos es `false` («la puerta no existe el 15-11») | = #1 | WhatsApp `true`, voz `false` (V9 decide la suya) |
| 7 | `tests/Feature/Outreach/Run/OutreachDispatcherTest.php:272-276` (`test_20`, B9-run) | La puerta de WhatsApp devuelve `channel_not_live` | La puerta ya no está vacía; con la constante cerrada devuelve `sends_disabled_in_code` | Separar WhatsApp (`sends_disabled_in_code`) de la voz (sin cambio) |

**Endurecimiento propuesto** (no cae hoy, pero lo cierra): `tests/Feature/UltronStudio/OutreachIsolationTest.php:145-150` y `:164` — `TEMPLATE_META_CHECKS` en la lista cerrada de lectores (solo el puente) y en `STUDIO_WRITE_IN_STATEMENT`.

**`OutreachIsolationTest`: en verde** (sus 5 pruebas, también «cero red con la configuración de producción y la ejecución en `live` con WhatsApp encendido»: la constante cierra antes del canal).

### 5.1 Los recuentos del Studio (Anexo A)

La tabla nueva suma **PG +4 disparadores, +4 CHECK, +1 tabla; SQLite +3 disparadores** a los recuentos `ultron_studio_%` que fijan cinco pruebas del H5–H9 (como el Anexo A de DB9, L9-60): en SQLite caen 9 aserciones (108→111, 104→107, 98→101, 106→109) y en PG sus gemelas (123→127, 137→141, 25→26 tablas y los recuentos tras cada `down()`). Las cifras exactas, por fichero, están en el Anexo A.

### 5.2 Los anexos (parches para `git apply` desde la raíz del repositorio)

Yo no los apliqué en el árbol compartido (solo `git apply --check`). **Al cierre aparecen aplicados tal cual** (lo confirma `git apply --check -R` de los dos): entiendo que los aplicó el lead; con ellos las pruebas de §8 dan lo dicho en §0.

- **Anexo A** (`w9-anexo-a-recuentos.patch`, 5 ficheros, 11 bloques): mecánico, SUMANDO, nunca cambiando lo que había.
- **Anexo B** (`w9-anexo-b-guardas-propuesta.patch`, 5 ficheros, 10 bloques): la propuesta de §5, más una aserción que ENDURECE la guarda de la constante: en `app/`, `routes/`, `config/`, `database/` y `bootstrap/` nadie pasa el doble de la puerta (`sendsEnabledInCode:`) ni construye el canal de WhatsApp o la entrada de la escalera con argumentos (así la costura de prueba no puede abrir nada en producción). La decide el lead (y SEC9 para las guardas).

Van incrustados al final de este informe (como los de DB9) y como ficheros en el scratchpad de la sesión (rutas en el resumen al lead), regenerables con `tools/anexo_a.py` / `tools/anexo_b.py` (cada sustitución tiene que aparecer exactamente una vez o el guion se detiene). **Verificados en un clon APFS del backend** (para no tocar ficheros ajenos con V9 trabajando en el mismo árbol): §8.

## 6. Ganchos que pido (no los he hecho: son de otros dueños) y huecos del contrato

1. **`OutreachDispatcher::settle()` (`app/Services/Outreach/Run/OutreachDispatcher.php:324-328`, B9-run/lead).** Si la pausa del agente llega ENTRE la escalera de la etapa 6 y la puerta (carrera de milisegundos), la puerta devuelve `agent_paused` y hoy eso es `failed_to_start` → resultado `failed` → `end_exit` por defecto: la persona pierde el ciclo (lado seguro, pero no es `waitsForRelease`). Cambio mínimo: en el `case ChannelAttempt::FAILED_TO_START`, si `in_array($intento->detailCode, ['agent_paused', 'sends_disabled_in_code', 'channel_disabled', 'mode_not_live'], true)` → liberar la reserva y `updateOpenEnrollment(next_due_at = ahora + WAIT_MINUTES)` en vez de registrar `failed` (devolver `'waiting'`). El camino normal (la pausa vista por la escalera) YA espera: probado.
2. **El programador (`routes/console.php`, OPS9).** Dentro del bloque del H9 (`:494`), ANTES de `outreach:dispatch --inline` (el programador corre en orden las tareas del mismo minuto):

   ```php
       // W9: los normalizadores de WhatsApp (D9-8 etapa 8): estados de Meta, respuestas y palabra de baja → resultados.
       Schedule::command('outreach:whatsapp-outcomes')
           ->everyMinute()
           ->withoutOverlapping(10)
           ->onOneServer();
   ```

   Cumple la guarda de SEC9 (orden `outreach:*` con argumentos literales). `h9-outreach-scope.sh` cuenta hoy 3 tareas con el programador encendido: pasarían a 4 (y a 5 con la de V9, `outreach:voice-outcomes`, que tiene la misma forma). Sin la tarea no se registran resultados (el canal sí se niega a escribir a quien pidió la baja, §1.1 #7).
3. **Hueco de D9-10 (B9-core + lead).** Tras un WhatsApp `delivered`/`read` SIN respuesta no hay resultado con `next_step`: la secuencia de varios pasos no avanza y la inscripción vence con `no_response`. Propuesta: un `no_answer` de fuente `system` tras N horas sin respuesta (enmienda de `OutcomeTable`/`SOURCE_OUTCOMES`).
4. **La escritura de `ultron_studio_template_meta_checks` (S9 + lead).** W9 entrega la tabla y su lectura; registrar lo que aprobó Meta es un acto de gobierno: propongo una orden del Studio (como `ultron-studio:person-bindings`) o una ruta con `ultron.approve` y persona vinculada, que escriba una fila con `client_request_id`. Hasta entonces, el canal falla cerrado (`template_not_approved_by_meta`).
5. **`unknown_send` del monitor (Q-6 de L9-57) y la evidencia real del canario** (fila W9 de §11): no entran en este encargo. `WhatsappSignals` deja hecha la lectura por correlación (`outboundOf`, `hasOutboundFor`) que el monitor necesitaría.
6. **Textos del CRM (FE9).** Los códigos nuevos de §3 no tienen frase en `DecisionTraceText` ni en el espejo del CRM.
7. **N4 en la planificación (B9-run).** La correspondencia con Meta solo la mira el canal (como pidió el encargo): un trabajo sin ella se planifica, reserva y falla al empezar. Si se quiere que no se planifique, `LadderInputBuilder` debería exigirla en `templateApproved` para `whatsapp_template`.

## 7. Decisiones fuera de la letra (para que el lead las confirme)

| Q | Qué | Lo que hice |
|---|---|---|
| Q-1 | El encargo nombra `sends_closed_in_code`; el conjunto cerrado tiene `sends_disabled_in_code` (`PolicyLadder:68`, `DecisionTraceText:120`, los fixtures, `cancel_reason`) y la puerta vacía ya compartía su código con la escalera (`channel_not_live`) | Usé `sends_disabled_in_code` (`WhatsappTemplateChannel::SENDS_CLOSED`). En el camino real lo que se ve es el de N4 (la escalera niega antes). Si se quiere el literal nuevo, es esa constante |
| Q-2 | «Una respuesta posterior → `answered`/`needs_review` según la tabla» | Según la tabla: `replied` (`OutcomeTable::TABLE['answered']['sources']` = `staff`, `voice_call`; `inbound_message` solo admite `replied`, `human_requested` y `opted_out`, y la base lo hace cumplir). `needs_review` es solo del clasificador (AI9). `replied` deriva a una persona por defecto, que es el efecto buscado |
| Q-3 | «Cero escrituras fuera de `outreach_*` + `marketing_messages`» | La puerta, como hoy, también actualiza `marketing_conversations` (el apunte de `recordOutbound()` y el observador de la vista previa del Inbox): 2 escrituras, ambas DENTRO de la puerta y sin cambiar su código. La prueba lo comprueba y lo atribuye (§8) |
| Q-4 | `template_id` sin FK | Las pruebas de DB9 revierten su migración del gobierno (`down()`) y en PostgreSQL una FK lo impide. La integridad la da el disparador al insertar y las plantillas no se borran nunca (`usmt_forbid_delete` + REVOKE) |
| Q-5 | `canContactProactively()` también para `service` | La puerta lo exige siempre (W-B); una plantilla de servicio a un prospecto con la ventana abierta pero sin `granted` falla (`no_proactive_consent`): más estricto que la matriz de D9-15 |
| Q-6 | La baja | Suprime por (finalidad, WhatsApp) como `SubjectSuppressions` (D9-10). El H9 no escribe `marketing_leads.do_not_contact`: el Inbox ve el «BAJA» y una persona decide. Y el canal no escribe a quien ESCRIBIÓ una baja alguna vez, la normalice quien la normalice |

## 8. Qué verifiqué, con comandos

Todo desde `backend/`, `php -d memory_limit=2G`, `--no-logging`, un proceso pesado a la vez (la máquina tenía otros agentes: tiempos de 30 s a 2 min por fichero).

```
vendor/bin/phpunit --no-logging tests/Feature/Outreach/W9                                         # SQLite
  → OK (37 tests, 375 assertions, 2 skipped: solo PG)
UNIT_DB=ultron_studio_w9 docs/ultron-studio/ops/pgunit.sh --no-logging tests/Feature/Outreach/W9   # PG 14 desechable
  → OK (37 tests, 391 assertions, 1 skipped: solo SQLite); el rol de prueba no queda (pg_roles = 0) ni filas
  → la primera pasada cazó un dato de MI prueba ('authentication' no cabe en varchar(10): 22001 antes del CHECK); corregido
  → una pasada intermedia dio 8 errores en `parent::setUp()` (antes de cualquier aserción) y NO se repitió en las dos
    siguientes; coincidió con una edición concurrente de V9 (`VoiceStrategyChannel.php`): causa NO demostrada
Antes de los anexos: tests/Feature/Outreach/Guards + Run + OutreachIsolationTest + UltronEngineIsolationTest
  → 102 tests, 8 169 assertions, 7 failures: exactamente las de §5 (ninguna otra); OutreachIsolationTest en verde
Antes de los anexos: <las 7 pruebas de esquema del Studio> → 379 tests, 9 failures: todas recuentos (§5.1)
clon APFS + Anexo A, SQLite: <las 7 de esquema del Studio>        → OK (379 tests, 1 932 assertions, 162 skipped: solo PG)
clon APFS + Anexo A, PG: <las 7 de esquema del Studio> + tests/Feature/Outreach/Schema (DB9: down()/up() del gobierno incluido)
                                                                   → OK (698 tests, 5 417 assertions, 1 skipped)
clon APFS + Anexo B + mi código, SQLite: Guards + Run + UltronEngineIsolationTest + W9
                                                                   → 133 tests, 14 832 assertions, 1 failure: la de V9
clon APFS + Anexo B, SQLite: OutreachIsolationTest sin la puerta de diff (el clon no tiene .git) → OK (4 tests, 965 assertions)
ÁRBOL al cierre (anexos aplicados por el lead): Guards + Run + OutreachIsolationTest + UltronEngineIsolationTest
                                                                   → 102 tests, 15 472 assertions, 1 failure: la de V9
ÁRBOL al cierre: <las 7 de esquema del Studio>                    → OK (379 tests, 1 932 assertions, 162 skipped: solo PG)
Regresión dirigida de Legacy (14 de Marketing + 4 de caos + IncidentDetection + ValuationReminders)
                                                                   → OK (328 tests, 1 762 assertions)
vendor/bin/pint --test <lo mío>  → passed  (el outbox: HEAD ya fallaba con los mismos fijadores; mis líneas, limpias)
git apply --check w9-anexo-a-recuentos.patch / w9-anexo-b-guardas-propuesta.patch → aplicaban limpio (antes de que se aplicaran)
php artisan list outreach | grep whatsapp → outreach:whatsapp-outcomes registrada; con las banderas por defecto sale `master_flag_off` sin leer la base
```

Lo que prueban las 37 (por encargo):

- **En sombra nada se crea**: incluso con la puerta abierta en el doble, el canal es el simulado; 0 mensajes, 0 reservas, 0 red.
- **En vivo con la constante cerrada**: el planificador REAL no planifica WhatsApp (decisión `deny` N4 `sends_disabled_in_code`); un trabajo planificado con la puerta abierta se CANCELA en el despacho real con `sends_disabled_in_code` y su reserva vuelve a 0 (unidades y micros); el canal real no llama a la puerta (un mock que falla si se le llama).
- **Puerta abierta solo en la prueba y todo encendido**: planificador → despachador → canal → puerta → outbox → Graph falso: trabajo `started` con `marketing_message_id`; un mensaje, remitente `ai`, `sent`, `correlation_id` = uuid del trabajo, `metadata` de plantilla, cuerpo compuesto; UNA petición `type: template` con nombre, idioma y parámetros; repetir el canal o la puerta no crea ni envía nada más.
- **Meta**: sin comprobación, con la última `PAUSED`, o con nombre, idioma, categoría o texto distintos → `template_not_approved_by_meta`, `failed`, reserva liberada.
- **Estados de Meta por el webhook DE SIEMPRE** (firma, evento, job; desordenados y repetidos) → `sent`, `delivered`, `read` una vez, en orden; un `failed` 131026 → `meta_error_131026` y `end_exit`; un `failed` tardío sobre entregado no cuenta.
- **Respuesta** → `replied` y `handed_off`, sin que el texto viaje; un entrante ANTERIOR al contacto no es respuesta.
- **Palabra de baja** («  BAJA, por favor 🙏 ») → `opted_out`, la inscripción `opted_out`, la otra estrategia `exited`, su trabajo pendiente `canceled` (`opted_out`) con la reserva liberada, `SubjectSuppressions` = suprimida; y el canal se niega (`opt_out_word`) aunque el normalizador no haya pasado.
- **Pausa del agente** → `waitsForRelease`: trabajo `canceled` `marketing_agent_paused`, reserva liberada, inscripción `active` con `next_due_at` = +15 min, ningún resultado, 0 mensajes; en la carrera, la guarda de la puerta (`agent_paused`) no deja fila.
- **Aislamiento**: escuchador de consultas durante el despacho y la normalización: `{"marketing_conversations@puerta":2,"marketing_messages@puerta":3,"outreach_enrollments":3,"outreach_jobs":3,"outreach_outcomes":2,"outreach_runs":2}` (las dos de `outreach_runs` son la pasada del despachador): Marketing solo dentro de la puerta; 18 tablas ajenas sin cambiar de filas (salvo `marketing_messages`, +2: el saliente y el entrante de la persona); ni teléfono, ni nombre, ni fecha, ni el texto en `outreach_*`. Y, estático, la puerta de plantillas solo la define Marketing y solo la llama el canal: ninguna ruta, controlador (tampoco los internos de `automation.internal`), orden ni trabajo la nombra (D9-9.3 «sin ruta nueva»).
- **Esquema**: solo inserción, guarda entre tablas, CHECK de PG (23514), sin SECURITY DEFINER, la de F única, REVOKE comprobado (el rol de la aplicación inserta; UPDATE y DELETE → 42501), `down()` se niega con filas e ida y vuelta sin ellas (SQLite).
- **Piezas**: 21 bajas y 15 no-bajas de la lista cerrada; lo que Meta rechazaría no nace; el outbox entrega la plantilla en un reintento y no envía una forma rota (ni como texto); nombres raros no se usan; 11 traducciones de la puerta al intento.

## 9. Qué NO verifiqué

- **Meta de verdad**: ni una petición real (protección). El formato de `components` es el de Cloud API v18+ según `sendWhatsappTemplate` (ya existente); que Meta acepte una plantilla concreta depende de su aprobación.
- **La regresión COMPLETA de Marketing** (2 234 pruebas): la lancé y la paré a los ~70: con otros tres procesos de PHPUnit de otros agentes iba a ~13 pruebas por minuto (más de 2 h, por encima de su alarma). Corrí la DIRIGIDA a lo que toca mi diff (328/328, §8). La completa por nombre, con la llave del motor en `off` y en `all`, queda para TEST9-c (es su fila T8).
- **Los anexos en PG dentro del árbol**: en el árbol (ya aplicados por el lead) solo los corrí en SQLite; en PG, el A en el clon. La puerta de diff de `OutreachIsolationTest` con el Anexo B no la corrí en el clon (sin `.git`); en el árbol, con los anexos, pasó (es una de las 102).
- **La puerta PG de las guardas y de `tests/Feature/Outreach/Run`** con los anexos: solo SQLite (las guardas son estáticas; las de `Run` que cambia el Anexo B no dependen del motor).
- **La cola real** y el programador (§6.2): las pruebas despachan en línea.
- **Rendimiento** (PERF9): la comprobación de baja lee hasta 200 entrantes por envío; con los topes del contrato es poco.
- **`plan_price`, `class_*`, `payment_link`**: sin resolver a propósito (§1.1).

## 10. Para el propietario (lo que W9 no puede hacer)

1. Registrar en Meta las plantillas (contenido, categoría, idioma; si `first_name` puede ir como variable: **P9-13**) y su precio/techo diario.
2. Registrar su correspondencia (`ultron_studio_template_meta_checks`) cuando exista la vía de §6.4.
3. **P9-15** y el **visto bueno legal** (Ley 1581, Ley 2300 arts. 3 y 5, encargado Meta) antes de cualquier envío.
4. La fase 2 del H6 (números canarios de WhatsApp activos) y las fases 2–3 del H9 (sombra, asistido) con su evidencia.
5. Abrir la constante SOLO con un commit de una línea `outreach(sends-open): …` que cite su autorización escrita (fase 4), con W9 desplegado, los anexos aplicados y las decisiones de §5–§7 tomadas.

## Anexo A · Los recuentos del Studio con la tabla de W9 (§5.1), sin aplicar

Desde la raíz del repositorio: `git apply` de este bloque. Mecánico, SUMANDO (PG +4 disparadores, +4 CHECK, +1 tabla; SQLite +3 disparadores).

```diff
--- a/backend/tests/Feature/UltronStudio/UltronStudioPublicationSchemaTest.php
+++ b/backend/tests/Feature/UltronStudio/UltronStudioPublicationSchemaTest.php
@@ -174,9 +174,9 @@
     {
         if ($this->isPostgres()) {
             $this->assertSame(['uspub_chain_next', 'uspub_forbid_delete', 'uspub_forbid_truncate', 'uspub_forbid_update'], $this->triggersOn(self::TABLE));
-            $this->assertSame(123, $this->studioTriggerCount(), 'F 11 + laboratorio 16 + H5 3 + H6 13 + H7 1 + H8 24 + H9 55');
+            $this->assertSame(127, $this->studioTriggerCount(), 'F 11 + laboratorio 16 + H5 3 + H6 13 + H7 1 + H8 24 + H9 55 + W9 4');
             $this->assertSame(['uspub_action_chk', 'uspub_engine_contract_chk', 'uspub_reason_chk', 'uspub_seq_chk'], $this->checksOn(self::TABLE));
-            $this->assertSame(137, $this->studioCheckCount(), 'F 11 + laboratorio 13 + H5 3 + H6 22 + H7 1 + H8 34 + H9 53 (usv_status_chk y usae_subject_type_chk se sustituyen, no se suman)');
+            $this->assertSame(141, $this->studioCheckCount(), 'F 11 + laboratorio 13 + H5 3 + H6 22 + H7 1 + H8 34 + H9 53 + W9 4 (usv_status_chk y usae_subject_type_chk se sustituyen, no se suman)');
             // Los disparadores usan la función de F; H5 no crea otra ni una sobrecarga.
             $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS n FROM pg_proc WHERE proname = 'ultron_studio_forbid'")->n);
             $this->assertSame(3, (int) DB::selectOne(
@@ -186,7 +186,7 @@
         }
 
         $this->assertSame(['uspub_chain_next', 'uspub_forbid_delete', 'uspub_forbid_update', 'uspub_seq_min'], $this->triggersOn(self::TABLE));
-        $this->assertSame(108, $this->studioTriggerCount(), 'F 7 + laboratorio 11 + H5 2 + H6 10 + H7 2 + H8 23 + H9 53');
+        $this->assertSame(111, $this->studioTriggerCount(), 'F 7 + laboratorio 11 + H5 2 + H6 10 + H7 2 + H8 23 + H9 53 + W9 3');
     }
 
     // ── La cadena es de solo inserción ──────────────────────────────────────
@@ -363,13 +363,13 @@
         $this->assertSame([], $this->partialIndexesOnVersions());
         if ($this->isPostgres()) {
             $this->assertSame(['DRAFT', 'VALIDATED', 'REVIEW_REQUIRED', 'APPROVED', 'REJECTED'], $this->statusCheckValues());
-            $this->assertSame(119, $this->studioTriggerCount());  // con el H8 (+24) y el H9 (+55)
-            $this->assertSame(133, $this->studioCheckCount());    // con el H8 (+34) y el H9 (+53)
+            $this->assertSame(123, $this->studioTriggerCount());  // con el H8 (+24), el H9 (+55) y W9 (+4)
+            $this->assertSame(137, $this->studioCheckCount());    // con el H8 (+34), el H9 (+53) y W9 (+4)
             $this->assertRejected(fn () => $this->insertVersion('CANARY'), '23514', 'usv_status_chk');
             // La función es de F: down() no la toca.
             $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS n FROM pg_proc WHERE proname = 'ultron_studio_forbid'")->n);
         } else {
-            $this->assertSame(104, $this->studioTriggerCount());  // con el H8 (+23) y el H9 (+53)
+            $this->assertSame(107, $this->studioTriggerCount());  // con el H8 (+23), el H9 (+53) y W9 (+3)
         }
         $this->assertSame(4, DB::table('ultron_studio_versions')->count());
 
@@ -377,9 +377,9 @@
         $this->migration(self::GUARDS)->up();
 
         $this->assertH5SchemaPresent();
-        $this->assertSame($this->isPostgres() ? 122 : 106, $this->studioTriggerCount());   // con el H8 y el H9
+        $this->assertSame($this->isPostgres() ? 126 : 109, $this->studioTriggerCount());   // con el H8, el H9 y W9
         if ($this->isPostgres()) {
-            $this->assertSame(136, $this->studioCheckCount());
+            $this->assertSame(140, $this->studioCheckCount());
         }
     }
 
--- a/backend/tests/Feature/UltronStudio/UltronStudioStrategySchemaTest.php
+++ b/backend/tests/Feature/UltronStudio/UltronStudioStrategySchemaTest.php
@@ -249,12 +249,12 @@
             'usspub_seq_chk', 'usspub_shape_chk', 'usspub_simulation_gate_chk', 'usspub_size_chk'],
     ];
 
-    /** Todo el Studio con el H9: H8 (68 / 84 / 55) + gobierno del H9 (55 / 53 / 53). */
-    private const PG_STUDIO_TRIGGERS = 123;
-
-    private const PG_STUDIO_CHECKS = 137;
+    /** Todo el Studio con el H9: H8 (68 / 84 / 55) + gobierno del H9 (55 / 53 / 53) + W9, las comprobaciones de Meta (4 / 4 / 3). */
+    private const PG_STUDIO_TRIGGERS = 127;
 
-    private const SQLITE_STUDIO_TRIGGERS = 108;
+    private const PG_STUDIO_CHECKS = 141;
+
+    private const SQLITE_STUDIO_TRIGGERS = 111;
 
     /** Los parciales de todo el Studio: los 6 de H5/H6/H8 y los 3 del H9. */
     private const STUDIO_PARTIAL_INDEXES = ['uscl_one_active', 'uscn_one_live', 'uspb_one_active', 'ussv_one_canary', 'ussv_one_published', 'usv_one_canary', 'usv_one_published',
@@ -401,9 +401,9 @@
             }
             $this->assertSame(55, $this->countTriggers(self::GOVERNANCE_TABLES), 'vínculos 8 + estrategias 7 + plantillas 11 + versiones 12 + simulaciones 4 + revisiones 6 + cadena 7');
             $this->assertSame(53, $this->countChecks(self::GOVERNANCE_TABLES), 'vínculos 5 + estrategias 6 + plantillas 12 + versiones 11 + simulaciones 5 + revisiones 4 + cadena 10');
-            $this->assertSame(self::PG_STUDIO_TRIGGERS, $this->countLike('trigger', 'ultron\\_studio\\_%'), 'H8 68 + H9 55');
-            $this->assertSame(self::PG_STUDIO_CHECKS, $this->countLike('check', 'ultron\\_studio\\_%'), 'H8 84 + H9 53 (usae_subject_type_chk se sustituye, no se suma)');
-            $this->assertSame(25, (int) DB::scalar("SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'ultron\\_studio\\_%'"));
+            $this->assertSame(self::PG_STUDIO_TRIGGERS, $this->countLike('trigger', 'ultron\\_studio\\_%'), 'H8 68 + H9 55 + W9 4');
+            $this->assertSame(self::PG_STUDIO_CHECKS, $this->countLike('check', 'ultron\\_studio\\_%'), 'H8 84 + H9 53 + W9 4 (usae_subject_type_chk se sustituye, no se suma)');
+            $this->assertSame(26, (int) DB::scalar("SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'ultron\\_studio\\_%'"));  // + W9
             $this->assertSame(0, (int) DB::scalar("SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname = ANY(?::text[]) AND t.tgenabled <> 'O'",
                 ['{'.implode(',', self::GOVERNANCE_TABLES).'}']), 'ninguno apagado');
 
@@ -415,7 +415,7 @@
                 $this->triggersOn($tabla), $tabla);
         }
         $this->assertSame(53, $this->countTriggers(self::GOVERNANCE_TABLES), '55 − 7 de TRUNCATE + 5 equivalentes');
-        $this->assertSame(self::SQLITE_STUDIO_TRIGGERS, $this->countLike('trigger', 'ultron_studio_%'), 'H8 55 + H9 53');
+        $this->assertSame(self::SQLITE_STUDIO_TRIGGERS, $this->countLike('trigger', 'ultron_studio_%'), 'H8 55 + H9 53 + W9 3');
     }
 
     public function test_solo_en_postgres_las_funciones_son_propias_invoker_y_la_de_f_no_cambia(): void
--- a/backend/tests/Feature/UltronStudio/UltronStudioVoiceSchemaTest.php
+++ b/backend/tests/Feature/UltronStudio/UltronStudioVoiceSchemaTest.php
@@ -202,11 +202,11 @@
     ];
 
     /** Todos los del Studio, con el H8: 44 + 24 y 50 + 34 en PG; 32 + 23 en SQLite. */
-    private const PG_STUDIO_TRIGGERS = 123;
+    private const PG_STUDIO_TRIGGERS = 127;  // + W9 4
 
-    private const PG_STUDIO_CHECKS = 137;
+    private const PG_STUDIO_CHECKS = 141;  // + W9 4
 
-    private const SQLITE_STUDIO_TRIGGERS = 108;
+    private const SQLITE_STUDIO_TRIGGERS = 111;  // + W9 3
 
     /** Los parciales de todo el Studio: los 4 de H5/H6 y los 2 de los agentes. */
     private const STUDIO_PARTIAL_INDEXES = ['uscl_one_active', 'uscn_one_live', 'uspb_one_active', 'ussv_one_canary', 'ussv_one_published', 'usv_one_canary', 'usv_one_published', 'usva_one_canary', 'usva_one_rest'];
--- a/backend/tests/Feature/UltronStudio/UltronStudioIntegrationsSchemaTest.php
+++ b/backend/tests/Feature/UltronStudio/UltronStudioIntegrationsSchemaTest.php
@@ -67,17 +67,17 @@
      * aplicado: `*_H5` es el esquema sin el H6 (el down() del H6 no toca las
      * guardas del H7, que son de la cadena) y `*_H6`, con él.
      */
-    private const PG_TRIGGERS_H5 = 110;  // F 11 + laboratorio 16 + H5 3 + H7 1 + H8 24 + H9 55
+    private const PG_TRIGGERS_H5 = 114;  // F 11 + laboratorio 16 + H5 3 + H7 1 + H8 24 + H9 55 + W9 4
 
-    private const PG_TRIGGERS_H6 = 123;  // + asociaciones 5, canarios 5, instantáneas 3
+    private const PG_TRIGGERS_H6 = 127;  // + asociaciones 5, canarios 5, instantáneas 3
 
-    private const PG_CHECKS_H5 = 115;    // F 11 + laboratorio 13 + H5 3 + H7 1 + H8 34 + H9 53
+    private const PG_CHECKS_H5 = 119;    // F 11 + laboratorio 13 + H5 3 + H7 1 + H8 34 + H9 53 + W9 4
 
-    private const PG_CHECKS_H6 = 137;    // + 4, 12 y 6 (usae_subject_type_chk se sustituye, no se suma)
+    private const PG_CHECKS_H6 = 141;    // + 4, 12 y 6 (usae_subject_type_chk se sustituye, no se suma)
+
+    private const SQLITE_TRIGGERS_H5 = 101; // F 7 + laboratorio 11 + H5 2 + H7 2 + H8 23 + H9 53 + W9 3
 
-    private const SQLITE_TRIGGERS_H5 = 98; // F 7 + laboratorio 11 + H5 2 + H7 2 + H8 23 + H9 53
-
-    private const SQLITE_TRIGGERS_H6 = 108; // + 4, 4 y 2
+    private const SQLITE_TRIGGERS_H6 = 111; // + 4, 4 y 2
 
     /** Contrato §I3.1, en orden: columna → [tipo en PG, tipo en SQLite, NULL-able]. */
     private const LINK_COLUMNS = [
@@ -346,7 +346,7 @@
             foreach (self::PG_CHECKS as $tabla => $nombres) {
                 $this->assertSame($nombres, $this->checksOn($tabla), $tabla);
             }
-            $this->assertSame(self::PG_TRIGGERS_H6, $this->studioTriggerCount(), 'F 11 + laboratorio 16 + H5 3 + H6 13 + H7 1 + H8 24 + H9 55');
+            $this->assertSame(self::PG_TRIGGERS_H6, $this->studioTriggerCount(), 'F 11 + laboratorio 16 + H5 3 + H6 13 + H7 1 + H8 24 + H9 55 + W9 4');
             $this->assertSame(self::PG_CHECKS_H6, $this->studioCheckCount(), 'F 11 + laboratorio 13 + H5 3 + H6 22 + H7 1 + H8 34 + H9 53');
 
             return;
--- a/backend/tests/Feature/UltronStudio/UltronStudioHardeningH7Test.php
+++ b/backend/tests/Feature/UltronStudio/UltronStudioHardeningH7Test.php
@@ -70,17 +70,17 @@
     private const SEQ_MESSAGE = 'ultron_studio: seq empieza en 1 (INSERT en ultron_studio_publications)';
 
     /** Recuentos de todo el Studio (tablas `ultron_studio_%`). */
-    private const PG_TRIGGERS_H6 = 122;  // con el H8 (+24) y el H9 (+55)
+    private const PG_TRIGGERS_H6 = 126;  // con el H8 (+24), el H9 (+55) y W9 (+4)
 
-    private const PG_TRIGGERS_H7 = 123;  // + uspub_chain_next
+    private const PG_TRIGGERS_H7 = 127;  // + uspub_chain_next
 
-    private const PG_CHECKS_H6 = 136;    // con el H8 (+34) y el H9 (+53)
+    private const PG_CHECKS_H6 = 140;    // con el H8 (+34), el H9 (+53) y W9 (+4)
 
-    private const PG_CHECKS_H7 = 137;    // + uspub_seq_chk
+    private const PG_CHECKS_H7 = 141;    // + uspub_seq_chk
 
-    private const SQLITE_TRIGGERS_H6 = 106; // con el H8 (+23) y el H9 (+53)
+    private const SQLITE_TRIGGERS_H6 = 109; // con el H8 (+23), el H9 (+53) y W9 (+3)
 
-    private const SQLITE_TRIGGERS_H7 = 108; // + uspub_chain_next y uspub_seq_min
+    private const SQLITE_TRIGGERS_H7 = 111; // + uspub_chain_next y uspub_seq_min
 
     /** md5 de `pg_get_functiondef` de la función de F: el mismo desde el H5. */
     private const FORBID_MD5 = '4ad748b5f17f09b398f7f641c197d7ee';
```

## Anexo B · La propuesta de enmienda de las guardas y de las dos pruebas de B9-run (§5), sin aplicar

La decide el lead (y SEC9 para las guardas). Cada excepción nombra un fichero, unas clases y una llamada exactos; endurece `OutreachIsolationTest` con la tabla nueva.

```diff
--- a/backend/tests/Feature/Outreach/Guards/OutreachStaticGuardsTest.php
+++ b/backend/tests/Feature/Outreach/Guards/OutreachStaticGuardsTest.php
@@ -63,6 +63,15 @@
     private const ULTRON_LEGACY_READER = 'app/Services/Outreach/Facts/OperationalBrakes.php';
 
     /**
+     * W9 (contrato H9, D9-9.3 «W-B»; D9-2 «Puertas de los canales»): el canal de WhatsApp es el ÚNICO fichero de
+     * `Outreach` que nombra la puerta de Marketing, y solo así: el despachador y el DTO de la plantilla; del
+     * despachador, solo `dispatchWhatsappTemplate` (nunca `dispatchWhatsapp`); el outbox y Meta, nunca.
+     */
+    private const W9_GATE_CALLER = 'app/Services/Outreach/Run/Channels/WhatsappTemplateChannel.php';
+
+    private const W9_GATE_IMPORTS = ['App\\Services\\Marketing\\MarketingMessageDispatcher', 'App\\Services\\Marketing\\OutreachTemplateMessage'];
+
+    /**
      * De fuera de `Outreach`, lo que `Outreach` puede importar (L9-28 y D9-2):
      * las reglas puras del H8 y el freno de voz (solo lectura), los estados de
      * pago, el vocabulario comercial, el interruptor del agente y el registro
@@ -98,10 +107,16 @@
     {
         $this->assertFalse(OutreachSendGate::SENDS_ENABLED_IN_CODE);
         $this->assertFalse(OutreachSendGate::sendsEnabledInCode());
-        $this->assertSame(['simulated', 'assisted'], OutreachSendGate::LIVE_CHANNELS, 'W9 y V9 añaden sus canales con su enmienda');
+        $this->assertSame(['simulated', 'assisted', 'whatsapp_template'], OutreachSendGate::LIVE_CHANNELS, 'W9 añade WhatsApp por plantillas; V9 añadirá la voz con su enmienda');
 
         $fuente = (string) file_get_contents(base_path(self::GATE));
         $this->assertSame(1, preg_match_all('/public const SENDS_ENABLED_IN_CODE = false;/', $fuente), 'El literal, una vez, con esa forma exacta.');
+        // W9: el doble de la puerta (`sendsEnabledInCode` del canal de WhatsApp y de la entrada de la escalera) es SOLO de las pruebas.
+        foreach ($this->phpFiles(['app', 'routes', 'config', 'database', 'bootstrap']) as $relativa) {
+            $codigo = $this->codeOnly(base_path($relativa));
+            $this->assertSame(0, preg_match_all('/sendsEnabledInCode\s*:/', $codigo), "{$relativa} pasa el doble de la puerta.");
+            $this->assertSame(0, preg_match_all('/new\s+(WhatsappTemplateChannel|LadderInputBuilder)\s*\(\s*[^)\s]/', $codigo), "{$relativa} construye el canal o la escalera con argumentos.");
+        }
         $this->assertSame(0, preg_match_all('/SENDS_ENABLED_IN_CODE\s*=\s*(true|1|\$|self|static|env|config)/i', $fuente), 'La constante no se calcula ni se lee del entorno.');
         $codigo = $this->codeOnly(base_path(self::GATE));
         $this->assertStringContainsString('self::sendsEnabledInCode()', $this->methodBody($codigo, 'automaticSendAllowed'), 'automaticSendAllowed() tiene que exigir la constante.');
@@ -121,9 +136,15 @@
         foreach ($this->h9Files() as $relativa) {
             $vistos++;
             $codigo = $this->codeOnly(base_path($relativa));
-            foreach (self::FORBIDDEN as $prohibido) {
+            // W9: la puerta de Marketing, solo desde su canal y solo por su método de plantillas.
+            $prohibidos = $relativa === self::W9_GATE_CALLER ? array_values(array_diff(self::FORBIDDEN, ['MarketingMessageDispatcher'])) : self::FORBIDDEN;
+            foreach ($prohibidos as $prohibido) {
                 $this->assertStringNotContainsString($prohibido, $codigo, "{$relativa} nombra «{$prohibido}» (protecciones 4, 5, 7 y 8).");
             }
+            if ($relativa === self::W9_GATE_CALLER) {
+                $this->assertSame(1, preg_match_all('/->dispatchWhatsappTemplate\(/', $codigo), 'De la puerta de Marketing, una llamada: dispatchWhatsappTemplate.');
+                $this->assertSame(0, preg_match_all('/->dispatchWhatsapp\(/', $codigo), 'Nunca el texto libre de la puerta.');
+            }
             // ULTRON Legacy: solo el cerrojo, solo desde los frenos operativos, solo `engaged()`.
             if (str_contains($codigo, 'Marketing\\Ultron\\')) {
                 $this->assertSame(self::ULTRON_LEGACY_READER, $relativa, "{$relativa} importa ULTRON Legacy.");
@@ -152,7 +173,7 @@
                         $propio = true;
                     }
                 }
-                if ($propio || in_array($nombre, self::ALLOWED_FOREIGN_IMPORTS, true)) {
+                if ($propio || in_array($nombre, self::ALLOWED_FOREIGN_IMPORTS, true) || ($relativa === self::W9_GATE_CALLER && in_array($nombre, self::W9_GATE_IMPORTS, true))) {
                     continue;
                 }
                 $ajenos[] = "{$relativa}: {$nombre}";
--- a/backend/tests/Feature/UltronStudio/UltronEngineIsolationTest.php
+++ b/backend/tests/Feature/UltronStudio/UltronEngineIsolationTest.php
@@ -131,6 +131,8 @@
     private const ALLOWED_H9_MIGRATIONS = [
         'database/migrations/2026_11_12_100001_create_ultron_studio_strategy_tables.php',
         'database/migrations/2026_11_12_100002_create_outreach_tables.php',
+        // W9: la correspondencia de las plantillas del Studio con Meta (`ultron_studio_template_meta_checks`).
+        'database/migrations/2026_11_13_200001_create_ultron_studio_template_meta_checks_table.php',
     ];
 
     /**
@@ -185,6 +187,8 @@
         "T_CONSTANT_ENCAPSED_STRING 'ultron_studio_strategy_publications'",
         "T_CONSTANT_ENCAPSED_STRING 'ultron_studio_message_templates'",
         "T_CONSTANT_ENCAPSED_STRING 'ultron_studio_person_bindings'",
+        // W9: la lee solo el puente (`metaApproved()`).
+        "T_CONSTANT_ENCAPSED_STRING 'ultron_studio_template_meta_checks'",
     ];
 
     /**
--- a/backend/tests/Feature/UltronStudio/OutreachIsolationTest.php
+++ b/backend/tests/Feature/UltronStudio/OutreachIsolationTest.php
@@ -147,6 +147,8 @@
         'PUBLICATIONS' => ['app/Services/Outreach/Run/OutreachPublishedStrategies.php'],
         'TEMPLATES' => ['app/Services/Outreach/Run/OutreachPublishedStrategies.php'],
         'PERSON_BINDINGS' => ['app/Services/Outreach/Run/OutreachPublishedStrategies.php'],
+        // W9: la correspondencia con Meta, SOLO el puente.
+        'TEMPLATE_META_CHECKS' => ['app/Services/Outreach/Run/OutreachPublishedStrategies.php'],
         'STRATEGIES' => [
             'app/Console/Commands/Outreach/OutreachSuppressSubjectCommand.php',
             'app/Services/Outreach/Run/AssistedQueue.php',
@@ -161,7 +163,7 @@
     ];
 
     /** Un verbo de escritura o de bloqueo pegado a una tabla del Studio en una misma sentencia de la ejecución. */
-    private const STUDIO_WRITE_IN_STATEMENT = '/OutreachTables::(STRATEGIES|VERSIONS|PUBLICATIONS|TEMPLATES|PERSON_BINDINGS)\b[^;]*->(insert|insertGetId|insertOrIgnore|insertUsing|update|updateOrInsert|upsert|delete|truncate|increment|decrement|lockForUpdate|sharedLock)\(/';
+    private const STUDIO_WRITE_IN_STATEMENT = '/OutreachTables::(STRATEGIES|VERSIONS|PUBLICATIONS|TEMPLATES|PERSON_BINDINGS|TEMPLATE_META_CHECKS)\b[^;]*->(insert|insertGetId|insertOrIgnore|insertUsing|update|updateOrInsert|upsert|delete|truncate|increment|decrement|lockForUpdate|sharedLock)\(/';
 
     /** Dónde vive el PHP de la ejecución que puede leer el Studio. */
     private const OUTREACH_ROOTS = ['app/Services/Outreach', 'app/Console/Commands/Outreach', 'app/Jobs/Outreach'];
--- a/backend/tests/Feature/Outreach/Run/OutreachStopAndGateTest.php
+++ b/backend/tests/Feature/Outreach/Run/OutreachStopAndGateTest.php
@@ -68,7 +68,11 @@
         foreach (OutreachCatalog::AUTOMATIC_CHANNELS as $canal) {
             $this->assertFalse(OutreachSendGate::automaticSendAllowed($canal), $canal);
             $this->assertFalse(OutreachSendGate::channelFlags($canal)['sendsEnabledInCode']);
-            $this->assertFalse(OutreachSendGate::channelFlags($canal)['channelLive'], 'W9/V9: la puerta no existe el 15-11');
+            if ($canal === 'whatsapp_template') {
+                $this->assertTrue(OutreachSendGate::channelFlags($canal)['channelLive'], 'W9: la puerta existe; implementada no es abierta (la constante cierra)');
+            } else {
+                $this->assertFalse(OutreachSendGate::channelFlags($canal)['channelLive'], 'V9: la puerta no existe todavía');
+            }
         }
         $this->assertFalse(OutreachSendGate::automaticSendAllowed('assisted'), 'el asistido no es un envío automático');
         $this->assertTrue(OutreachSendGate::channelFlags('assisted:whatsapp')['channelLive']);
--- a/backend/tests/Feature/Outreach/Run/OutreachDispatcherTest.php
+++ b/backend/tests/Feature/Outreach/Run/OutreachDispatcherTest.php
@@ -269,7 +269,11 @@
         $p = $this->plannedJob('live');
         $e = DB::table('outreach_enrollments')->first();
         $live = (new OutreachPublishedStrategies)->liveVersion($p['live']['version'], 'canary');
-        foreach ([new WhatsappTemplateChannel, new VoiceStrategyChannel] as $canal) {
+        // W9: la puerta de WhatsApp existe; con la constante cerrada no llama a nada (el mismo código que N4).
+        $whatsapp = (new WhatsappTemplateChannel)->attempt($p['job'], $e, $live, self::utcAt(self::NOW));
+        $this->assertSame('failed_to_start', $whatsapp->status);
+        $this->assertSame('sends_disabled_in_code', $whatsapp->detailCode);
+        foreach ([new VoiceStrategyChannel] as $canal) {
             $intento = $canal->attempt($p['job'], $e, $live, self::utcAt(self::NOW));
             $this->assertSame('failed_to_start', $intento->status, $canal->name());
             $this->assertSame('channel_not_live', $intento->detailCode);
```

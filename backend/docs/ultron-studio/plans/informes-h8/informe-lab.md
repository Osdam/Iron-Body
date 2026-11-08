# Hito 8 · LAB8 (paso 2) · informe del laboratorio hablado

**Fecha:** 07-11-2026. **Árbol:** `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h8-voice`, sobre `80396fc5` (DB8 + `origin/main`). **Sin commit.** Producción y red: nada. Ninguna llamada a ElevenLabs: todo contra `Http::fake` con `Http::preventStrayRequests()`.

Contrato: `ULTRON_STUDIO_H8_CONTRACT.md` (D8-23, D8-7, D8-10, D8-22, «Contratos de datos», «API», fila LAB8, «Traspasos», paso 2 y puerta 2, P-19, E1-12, E2-2 y E2-4).

## 1. Qué entrego

Los 12 ficheros son nuevos y todos están dentro de mi lista exclusiva. No he tocado ningún fichero de otro paquete.

| Fichero | Líneas | Qué es |
|---|---|---|
| `app/Services/UltronStudio/StudioVoiceLabConfig.php` | 317 | La configuración, pura y sin secretos. Contiene los cuatro cerrojos (`reasons()`), los topes duros, la reserva redondeada hacia arriba y los defectos que dejan todo cerrado. |
| `app/Services/UltronStudio/StudioVoiceLabErrors.php` | 191 | Las negativas tipadas del laboratorio. Usan el constructor de `UltronStudioException`, sin tocar ese fichero. |
| `app/Services/UltronStudio/StudioVoiceLabAgents.php` | 388 | Los agentes de laboratorio: compila con S8-a (`purpose = lab`), lee con B8-a (`VoiceAgentSpec::fromCompiled`) y arma la carga con `ElevenLabsAgentMapper`. Crea **un agente por sesión**, que se despliega (`createAgent` → `describeAgent`, comparando la proyección) y pide la URL firmada (`signedSessionUrl`). |
| `app/Services/UltronStudio/StudioVoiceLabSessions.php` | 948 | Las sesiones: estado, apertura con reserva atómica, cierre (por quien probó, por el informe del proveedor o por plazo), métricas, historial y presentación. |
| `app/Services/UltronStudio/StudioVoiceLabVerdicts.php` | 111 | El veredicto a cuatro ojos sobre la misma huella, y `latestFor(specHash)` para la puerta de publicación (P-19). |
| `app/Models/UltronStudio/StudioVoiceLabSession.php` | 176 | El modelo de `ultron_studio_voice_lab_sessions`, con columnas congeladas y mutables según DB8, `advance()` condicionado y sin borrado. |
| `app/Http/Controllers/Crm/UltronStudio/UltronStudioVoiceLabController.php` | 178 | Las 6 rutas, con el orden persona → llave → bandera y validación en el borde (`PlainText` estricto en la nota). |
| `tests/Feature/UltronStudio/VoiceLabFixtures.php` | 413 | Ayudantes de prueba: rutas, configuración, perfiles sembrados por su camino y el simulador de ElevenLabs sobre `Http::fake`. |
| `tests/Feature/UltronStudio/UltronStudioVoiceLabSessionsTest.php` | 708 | 21 pruebas: rutas, cerrojos, 401/403/409/422/404, simulador, ElevenLabs, reserva, cupo, cierre, informe del proveedor, plazo y lista. |
| `tests/Feature/UltronStudio/UltronStudioVoiceLabVerdictTest.php` | 182 | 5 pruebas: cuatro ojos, huella, uno solo y definitivo, `latestFor` y el veredicto del simulador escrito a mano. |
| `tests/Feature/UltronStudio/UltronStudioVoiceLabNoNetworkTest.php` | 161 | 5 pruebas: cero red con la configuración de producción (E1-12), adaptadores nulos y cada cerrojo. |
| `tests/Feature/UltronStudio/UltronStudioVoiceLabBudgetLockTest.php` | 312 | 4 pruebas, solo en PG: dos carreras con proceso hijo, el orden del SQL y READ COMMITTED bajo REPEATABLE READ. |

## 2. Contrato

### 2.1 Rutas (para S8-b, en `routes/ultron-studio.php`) y llaves (para B8-b, en `AuthorizationMap`)

S8-b ya las añadió al árbol (sin confirmar), **idénticas** a esta lista. `UltronStudioVoiceLabSessionsTest::test_las_rutas_son_las_que_lab8_entrega_a_s8_b` compara la tabla real con la lista. Todas van bajo `/api/admin/ultron-studio`, en el grupo `api` y con `LimitRequestBodySize:65536`.

| # | Método | Path | Llave (`AuthorizationMap`) | Controlador | Limitadores |
|---|---|---|---|---|---|
| 1 | GET | `voice-lab/status` | `ultron.view` | `UltronStudioVoiceLabController@status` | `throttle:ultron-studio-read` |
| 2 | GET | `voice-lab/sessions` | `ultron.view` | `@index` | `throttle:ultron-studio-read` |
| 3 | GET | `voice-lab/sessions/{uuid}` (`whereUuid`) | `ultron.view` | `@show` | `throttle:ultron-studio-read` |
| 4 | POST | `voice-lab/sessions` | `ultron.voice_lab_run` | `@store` | `throttle:ultron-studio-write` + `throttle:ultron-studio-lab-run` (ver §6.3) |
| 5 | POST | `voice-lab/sessions/{uuid}/end` (`whereUuid`) | `ultron.voice_lab_run` | `@end` | `throttle:ultron-studio-write` |
| 6 | POST | `voice-lab/sessions/{uuid}/verdict` (`whereUuid`) | `ultron.approve` | `@verdict` | `throttle:ultron-studio-write` |

- La 3 no estaba en el borrador de la API del contrato. Sirve para que el CRM consulte una sesión mientras espera el informe del proveedor, como `lab/runs/{uuid}` en el H4.
- **Para B8-b:**
  - las 6 claves de arriba en `AuthorizationMap::OVERRIDES`;
  - la llave `ultron.voice_lab_run` en el catálogo, con su texto, por ejemplo: «Abrir sesiones del laboratorio hablado: voz en el navegador contra un agente de laboratorio de ElevenLabs, sin teléfono. Cada sesión gasta minutos del proveedor. No llega por defecto al Administrador».
  - Ojo: D8-22 dice «solo Super Admin», pero el supuesto de P-18 de la congelación dice «Administrador: solo lectura y laboratorio hablado». Decide el lead y lo aplica B8-b.
- Mientras la puerta no las clasifique, una ruta sin clasificar se deniega a todos. Mis pruebas quitan entonces `EnforceAdminAuthorization` y queda la defensa en profundidad del controlador, que exige por su cuenta la persona y la llave. En cuanto B8-b las clasifique, recorren la pila real sin cambiar nada (`VoiceLabFixtures::voiceLabRoutes()`). La prueba de rutas exige que la llave clasificada, si existe, sea la de la tabla.

### 2.2 Cerraduras, en orden

1. La puerta (`AuthorizationMap`).
2. Una persona (`StudioAccess::requirePerson`, que ya exige `ultron.view`).
3. La llave de la ruta.
4. La bandera: 409 `voice_lab_disabled` en todo salvo `GET voice-lab/status`, que responde 200 también apagado, con `{enabled:false, message, can}`.
5. La validación (422).

**Abrir una sesión** comprueba además, en este orden:
1. La repetición: misma persona y misma `clientRequestId` → 200 `replayed`.
2. La configuración: 409 `voice_lab_not_configured {reasons, mode}`.
3. La versión y el perfil: 404 `version_not_found` o `voice_profile_not_found`.
4. Los destinos: 409 `voice_lab_transfers_not_supported` (§6.4).
5. La compilación: 422 `voice_compile_refused {reason, field}`, la de S8-a tal cual.
6. La duración: 409 `voice_lab_duration_exceeds_session`.
7. El cupo de la persona: 409 `voice_lab_daily_sessions_exceeded`.
8. El tope por sesión: 409 `voice_lab_session_budget_exceeded`.
9. Lo aceptado: 409 `voice_lab_estimate_changed`.
10. Dentro del cerrojo, el cupo otra vez y el techo del día: 409 `voice_lab_daily_budget_exceeded {requiredMicros, remainingMicros}`.
11. Fuera de la transacción, el agente y la URL. Si falla el proveedor: 502 `voice_lab_provider_failed {endReason, session}`; la sesión queda `failed` con coste 0.

**Los cuatro cerrojos** (protección 12 y D8-23), en `StudioVoiceLabConfig::reasons()`:

| Cerrojo | Motivo |
|---|---|
| La bandera | `voice_lab_disabled` |
| El driver (`fake` / `elevenlabs`) | `invalid_driver`: un valor que no es ninguno de los dos no cae al simulador en silencio |
| El presupuesto | `zero_budget` (el diario o el tope por sesión ≤ 0) y `missing_price` |
| La credencial verificada | `credentials_unverified`, salvo `ELEVENLABS_VERIFIED` (`StudioIntegrations::voiceState()`: bandera, piezas y la última verificación `ok` de menos de 30 días) |

Con `elevenlabs` se exigen también:
- `missing_webhook_base`: `VOICE_WEBHOOK_BASE_URL`, https limpia;
- `missing_secret_refs`: las referencias de los secretos de herramientas e iniciación en el gestor de ElevenLabs.

Además, con cualquier driver:
- `zero_sessions`: el cupo por persona y día de Bogotá es 0;
- como mucho 5 minutos por sesión;
- la reserva atómica.

### 2.3 Configuración (para B8-b, `config/voice.php`, bloque `lab`, y el fichero de ejemplo de entorno)

B8-b ya definió `voice.lab.enabled` (`VOICE_LAB_ENABLED`, apagada). Leo exactamente esa clave.

Las demás las leo con defectos **cerrados**: si no existen, el laboratorio no abre nada. Propongo, y pasan la guarda `VoiceBoundaryTest::test_todo_apagado_por_defecto`:

```php
'lab' => [
    'enabled' => filter_var(env('VOICE_LAB_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    // fake | elevenlabs. Cualquier otro valor: `invalid_driver`, nunca el simulador en silencio.
    'driver' => strtolower(trim((string) env('VOICE_LAB_DRIVER', 'fake'))),
    // US$ del día de Bogotá y tope por sesión; texto decimal. Con 0, `zero_budget`.
    'daily_budget_usd' => env('VOICE_LAB_DAILY_BUDGET_USD', '0'),
    'session_budget_usd' => env('VOICE_LAB_SESSION_BUDGET_USD', '0'),
    // 60–300 (D8-23: como mucho 5 minutos). El agente corta en la duración del perfil.
    'max_session_seconds' => min(300, max(60, $entero('VOICE_LAB_MAX_SESSION_SECONDS', 300))),
    // Por persona y día de Bogotá; 0–50.
    'max_sessions_per_day' => min(50, max(0, $entero('VOICE_LAB_MAX_SESSIONS_PER_DAY', 5))),
],
```

El resto de claves que leo no son secretos (prueba `VoiceIsolationTest::test_los_secretos_solo_en_sus_lectores_enumerados`):

| Clave | Para qué |
|---|---|
| `voice.prices.elevenlabs` (B8-b: 80 000 micro-US$/min, con su fecha) | El precio de la reserva: el de la conversación, sin tramo de Twilio |
| `voice.limits.per_call_max_seconds` | El tope del compilador |
| `voice.webhook_base_url` | La base de los webhooks |
| `voice.elevenlabs.tool_secret_ref`, `initiation_secret_ref` y `post_call_webhook_ref` | Las referencias de los secretos y del webhook post-llamada |

Nada de `env()` fuera de `config/`.

### 2.4 Lo que devuelve la API (para FE8)

**`GET voice-lab/status`**:
- **Apagado:** `{enabled:false, message, can:{view, run, verdict}}`.
- **Encendido:** `{enabled, mode, configured, reasons, credentials, fidelity, budget:{timezone, day, dailyCapMicros, spentMicros, reservedMicros, remainingMicros, sessionCapMicros, pricePerMinuteMicros}, sessions:{today, maxPerDay}, limits:{maxSessionSeconds, urlTtlSeconds, verdictNoteMax, maxInterruptions}, estimate:{maxSeconds, reservedMicros}, observations:{objectives, restrictions, errors} (listas {code, label}), can}`.
- `estimate.reservedMicros` es lo que el CRM manda como `acceptedMaxCostMicros`.
- Nunca enseña la clave ni las referencias de los secretos (probado).

**`POST voice-lab/sessions`**:
- Cuerpo: `{versionUuid, profileUuid, acceptedMaxCostMicros, clientRequestId}`.
- **201:** `SessionDto` + `connection.signedUrl`, **solo esa vez**.
- **200 `replayed`:** la misma sesión, **sin** la URL. La URL no se guarda: ni en la sesión ni en el agente (probado buscando su firma en las filas).

**`POST voice-lab/sessions/{uuid}/end`**:
- Cuerpo: `{objectives[], restrictionsBroken[], interruptions (0–99), errors[]}`, con códigos de los catálogos del estado.
- Solo quien la abrió, una vez:
  - otra persona: 403 `voice_lab_not_session_owner`;
  - sin conexión todavía: 409 `voice_lab_session_not_started`;
  - lo observado ya enviado: 409 `voice_lab_already_reported`;
  - fallida o con veredicto: 409 `voice_lab_session_not_open`.
- Abierta, la cierra `tester_ended`. Sin el informe del proveedor, se cobra la reserva entera.

**`POST voice-lab/sessions/{uuid}/verdict`**:
- Cuerpo: `{decision: approved|rejected, note (obligatoria en rejected, 5–500, PlainText estricto), specHash}`.
- Respuestas:
  - 403 `four_eyes_required {reason: session_creator | version_author}`;
  - 409 `voice_lab_session_not_reviewable {reason: not_ended | fake_mode}`;
  - 409 `verdict_exists`;
  - 409 `voice_lab_integrity_mismatch`.

**`GET voice-lab/sessions`**:
- Parámetros: `?versionUuid&profileUuid&specHash&status&scope=all|mine&page&per_page`.
- Devuelve el paginador de Laravel con `SessionDto`, en número fijo de consultas por página (probado: 1 o 3 filas, las mismas consultas).

**`SessionDto`**:
- **Identidad y estado:** `uuid`, `status` (`open | ended | failed`), `statusLabel`, `expired`, `deadline`, `mode` (`fake | elevenlabs`).
- **Origen:** `version{uuid, label, status, shortHash}`, `profile{uuid, label, revision, status, shortHash}`, `agent{uuid, status, specHash, compilerVersion, providerAgentId}`.
- **Límites y conexión:** `maxSeconds`, `budget{day, reservedMicros, costMicros}`, `connection{issued, expiresAt[, signedUrl]}`.
- **Métricas:** `metrics{durationSeconds, windowSeconds, interruptions, objectives, restrictions{respected, broken, guardViolations}, errors, toolCalls, reported{provider, tester}}`.
- **Cierre y autoría:** `endReason`, `endReasonLabel`, `createdBy{name, role}`, `mine`, `createdAt`, `startedAt`, `endedAt`.
- **Veredicto:** `verdict{decision, note, by{name, role}, at} | null`.
- **Acciones:** `actions{end, verdict}`, cada una `{allowed, code?, message?}`.

### 2.5 Para S8-b: la puerta P-19

`StudioVoiceLabVerdicts::latestFor(string $specHash)` → `{latest: approved|rejected|null, sessionUuid, verdictAt, reviewerId, sessionCreatorId}`:
- devuelve el veredicto **más reciente** sobre sesiones de agentes de laboratorio **reales** (`provider = elevenlabs`) con esa huella;
- la huella es la misma que compila la publicación: el propósito no entra en la especificación (probado comparando con `StudioVoiceCompiler::compile(..., 'publication')`).

S8-b escribió su propio lector, `StudioVoiceEvidence::labVerdict()`, con la misma consulta **sin** el filtro `provider = elevenlabs`. Hoy es equivalente porque el servicio impide el veredicto del simulador, pero la base no lo impide. Recomiendo que la publicación use `latestFor()`: un solo dueño de la semántica del veredicto, como `StudioLabVerdicts::latestFor` en el H5. Si no, que S8-b añada el filtro. Decide el lead.

### 2.6 Para B8-b y el lead: el cierre por el webhook post-llamada

`StudioVoiceLabSessions::recordProviderReport(VoiceWebhookVerdict $verdict, ?array $guard = null): string`:
- **Entrada:** el veredicto neutro de B8-a, ya verificado, y los códigos de su guarda posterior (`[{code, turn}]`, D8-10 capa 4).
- **Devuelve:** `recorded | duplicate | not_a_lab_session | ignored`.
- **Cómo encuentra la sesión:** el agente de laboratorio es de una sola sesión, así que el `agent_id` basta, sin fiarse del cliente.
- **Qué hace:**
  - es idempotente por `conversation_id`;
  - abierta, la cierra `provider_report`;
  - terminada sin veredicto, corrige la duración y el coste con lo real.
- **El coste** es la reserva prorrateada por los segundos hablados, hacia arriba: el precio por segundo con el que se reservó. No uso `metadata.cost` del proveedor porque sus unidades están [por confirmar] (D8-15).

**El cableado no lo puedo hacer yo, y las guardas actuales lo impiden por cualquier camino directo:**
- la telefonía no puede nombrar `App\Services\UltronStudio` ni sus tablas (`TelephonyIsolationTest` 1 y `UltronEngineIsolationTest`);
- el `AppServiceProvider` y `app/Listeners` tampoco pueden nombrar el Studio (`UltronEngineIsolationTest::ALLOWED`).

Propuesta, decisión del lead con SEC8:
1. En el webhook post-llamada, si no hay ninguna fila en `voice_calls` con ese agente y la conversación **no trae `call_sid`** (una conversación web, no una llamada), B8-b **no** la trata como `unknown_provider_call` (que abriría el freno) y la entrega con `event('voice.lab.post_call', [$verdict, $guard])`. Es un evento con nombre de cadena: la telefonía no nombra el Studio.
2. El oyente se registra en un sitio que la guarda admita, una línea: `Event::listen('voice.lab.post_call', [StudioVoiceLabSessions::class, 'recordProviderReport'])`. Hace falta que el lead autorice ese sitio en `UltronEngineIsolationTest::ALLOWED`, o uno de los ficheros del Studio que se carguen al arrancar.

Mientras no se cablee, las sesiones se cierran por quien probó (`/end`) o por plazo, se cobra la reserva entera y no hay duración ni herramientas del proveedor.

## 3. Decisiones de esta versión (para confirmar)

1. **Un agente de laboratorio por sesión**, con el `client_request_id` de la sesión. Da tres cosas:
   - atribución exacta del informe post-llamada;
   - ninguna carrera entre sesiones;
   - `call_limits` estrictos en el proveedor: concurrencia 1, **1 conversación al día**, ráfaga apagada.

   El coste es que se acumulan agentes en ElevenLabs: como mucho el cupo diario por persona. D8-7 los conserva para la auditoría. Si se quiere reutilizar el agente por huella, cambia la atribución: haría falta el `conversation_id` del cliente.
2. **Antes de dar la URL se comprueba la proyección** (`describeAgent`, D8-7 paso 3). Si no cuadra, el agente queda `rejected` y la sesión `failed` con `agent_drift`. Un `ambiguous` al crear queda `failed` con `agent_create_ambiguous`: el agente puede existir, pero está inerte, con autenticación y sin URL.
3. **La duración la impone el agente** (el `max_duration_seconds` del perfil). Si el perfil pide más que la sesión (300 s por defecto), el laboratorio se niega con `voice_lab_duration_exceeds_session`, porque la reserva no cubriría la conversación. Un perfil de más de 5 minutos no se puede probar aquí: su publicación solo pasaría con la puerta dispensada (P-19).
4. **Cuatro ojos del veredicto:** ni quien abrió la sesión (lo exige también la base: `usvl_four_eyes_chk` en PG y `usvl_verdict_rules` en SQLite) ni el autor de la versión (como el H4). El autor del perfil **no** se excluye: con dos personas que hayan escrito cada una una pieza, nadie podría juzgar. Con la regla elegida siempre hay una asignación válida entre dos: el autor de la versión prueba y el otro juzga.
5. **El simulador (`fake`) no admite veredicto** (`fake_mode`), como el ensayo sin IA del H4. Sirve para el flujo del CRM y el E2E, no como evidencia.
6. **Plazo:** con URL, caducidad de la URL (15 min) + 5 de conversación + 2 de margen, y se cobra la reserva entera; sin URL, a los 5 minutos de abrirse, sin coste. Al leer se presenta `expired` sin escribir nada; se materializa dentro del cerrojo de la siguiente apertura. Lo comprometido del día ya descuenta las que nunca recibieron su URL.
7. **Métricas:** lo que observa quien probó va en códigos de conjuntos cerrados: 8 objetivos comerciales, 5 restricciones, 6 errores y las interrupciones (0–99). El proveedor aporta duración, herramientas y guarda. `restrictions.respected` es `null` si no hay nada, y `false` si quien probó marcó alguna o la guarda trajo códigos.
8. **Dónde se guardan las métricas:** en la columna `guard_codes` de DB8, con forma cerrada `{format: 'ultron-voice-lab-findings/1', provider: {conversations: [{id, durationSeconds}], toolCalls, guard} | null, tester: {...} | null}`. Solo códigos, números e ids del proveedor; nunca texto, transcripción, teléfono ni URL. El nombre de la columna dice «guarda» y lleva más. Si DB8 o el lead prefieren una columna `findings`, es una migración aditiva.
9. **Sin libro de eventos propio.** La fila de la sesión es el registro: quién la abrió, el agente, la reserva, el cierre y quién la juzgó. Ni `StudioLabEvents` ni `StudioAudit` admiten estos eventos sin tocar sus conjuntos cerrados y sus CHECK, que no son míos. Tampoco hay un `verdict_denied` deduplicado como en el H4.

## 4. Verificado (comandos ejecutados)

| Qué | Comando | Resultado |
|---|---|---|
| Mis 4 ficheros de prueba, SQLite, árbol compartido | `php vendor/bin/phpunit tests/Feature/UltronStudio/UltronStudioVoiceLab*Test.php` | **35 pruebas: 31 OK y 4 omitidas** (las 4 de carreras, solo PG); 659 aserciones |
| Los mismos en la puerta PG (`UNIT_DB=ultron_studio_h8_lab`) | `UNIT_DB=ultron_studio_h8_lab bash docs/ultron-studio/ops/pgunit.sh <los 4>` | **35/35 OK**, carreras incluidas; 693 aserciones |
| Copia aislada (HEAD + solo mis ficheros), SQLite y PG (`ultron_studio_h8_lab_mut`) | `sandbox-phpunit.sh` (§5) | SQLite **31 OK + 4 omitidas** · PG **35/35 OK** |
| Guardas de SEC8 y del Studio sobre HEAD + mis ficheros (copia aislada, SQLite) | `TelephonyIsolationTest`, `VoiceIsolationTest`, `OtpUntouchedByVoiceTest`, `VoiceBoundaryTest`, `UltronEngineIsolationTest`, `StudioPlainTextInvisiblesTest`, `StudioLabIsolationTest`, `UltronStudioLabNoEngineEffectsTest`, `UltronStudioEngineReadOnlyTest`, `UltronStudioVoiceSchemaTest` | **166 pruebas OK** (68 omitidas: las de esquema de DB8, solo PG) |
| Pint | `vendor/bin/pint --test <mis 12 ficheros>` | `passed` |
| Sintaxis | `php -l` de los 7 de `app/` | sin errores |

**En el árbol compartido**, las mismas guardas dan 7 fallos. **Ninguno es de un fichero mío**: en la copia aislada, con mis ficheros sobre HEAD, pasan todas. Son del trabajo en curso de otros paquetes:

| Guarda | Fichero en curso que la hace caer | Paquete |
|---|---|---|
| `TelephonyIsolationTest` 1 | `Telephony/VoicePublishedConfig.php` nombra `ultron_studio_voice_agents` | B8-b |
| `TelephonyIsolationTest` 2 | `Telephony/OutboundPolicy.php` nombra `voice_number_is_whatsapp` | B8-b |
| `TelephonyIsolationTest` 8 | `StudioVoiceEvidence.php` nombra `Telephony\VoiceBrake` (E2-6 lo permite y la guarda no) | S8-b / SEC8 |
| `VoiceIsolationTest` (secretos) | `StudioIntegrations.php` lee el bloque `telephony` | S8-b |
| `UltronEngineIsolationTest` (nombres) | `VoicePublishedConfig.php` | B8-b |
| `UltronEngineIsolationTest` (rutas) | 77 rutas frente a 56 (E2-6: la constante la actualiza S8-b) | S8-b |
| `UltronStudioLabNoEngineEffectsTest::test_el_contrato_de_las_21_rutas_de_f_y_g_no_cambia` | Toda ruta nueva de voz, y el limitador de la ruta 4 | §6.3 |

**Qué cubren las pruebas:**
- **Cerrojos:** cada uno por separado, sin red y sin escribir nada.
- **Respuestas de error:** 401 en las 6 rutas; 403 por llave y sin ver; 404; 422 de forma (dos fases de `ValidatesListSizesFirst`); 409 por cerrojo, cupo, tope, aceptado y día; 502 por cada fallo del proveedor (creación 503 y 422, deriva, URL 401 y 500).
- **Caminos felices:**
  - el simulador, sin red;
  - ElevenLabs sobre `Http::fake`, con las 9 peticiones exactas: 3 herramientas, el agente, su lectura, 3 lecturas de herramientas y la URL, todas con la clave centinela;
  - el cuerpo enviado: agente `· lab ·`, `ironbody-voice-lab`, autenticación encendida, `call_limits` 1/1/sin ráfaga y la duración del perfil;
  - la proyección comprobada y la URL entregada una sola vez;
  - la clave, que nunca sale en la respuesta.
- **Reserva y cupo:**
  - la frontera exacta del día y del tope por sesión;
  - el redondeo hacia arriba (61 s a 0,10 US$/min = 101 667 micro-US$);
  - el cupo por persona y día de Bogotá: 23:30 de Bogotá es el día siguiente en UTC y cuenta el de Bogotá.
- **Repetición:** la misma petición (también en mayúsculas) da 200 `replayed`; la misma llave de otra persona u otro perfil, 409.
- **Cierre y plazo:**
  - el informe del proveedor: duración, coste prorrateado, herramientas y guarda; duplicado, ajeno e ignorado;
  - el informe que corrige lo cobrado al terminar;
  - el plazo: a los 16 minutos sigue viva; a los 23, caducada sin escribir; la siguiente apertura la cierra cobrando la reserva y cierra sin coste la que nunca tuvo URL.
- **Veredicto:**
  - cuatro ojos por las dos identidades;
  - la base rechaza el UPDATE a mano;
  - definitivo, con la misma huella (en mayúsculas también);
  - manda el más reciente.
- **Cero red:** con ElevenLabs configurado como en producción y la bandera por defecto, ni una petición, y lo mismo con los adaptadores nulos.
- **Carreras en PG:** dos sesiones que juntas superan el techo dan **201 + 409**, y el último hueco del cupo, **201 + 409**. En los dos casos el hijo confirma que la petición esperó el cerrojo (`WAITER=1`).

## 5. Mutaciones por muestreo

Se hicieron en una **copia aislada** del HEAD con solo mis ficheros: `git archive HEAD`, `vendor` enlazado, autoload de `App\`, `Tests\` y `Database\` antepuesto desde la copia, `APP_BASE_PATH` en la copia y una `APP_KEY` de prueba generada para ella. **Nada se mutó en el árbol compartido.** Cada mutante corre solo sus pruebas y el original se restaura pase lo que pase.

Arnés y resultados en el scratchpad de la sesión:
- `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body-backend/e14fa725-3ced-415c-b772-28b65d626ead/scratchpad/mut/mutants.py`
- `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body-backend/e14fa725-3ced-415c-b772-28b65d626ead/scratchpad/mut/run.py`
- `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body-backend/e14fa725-3ced-415c-b772-28b65d626ead/scratchpad/mut/results.tsv`

**42 mutantes: 42 cazados y 0 supervivientes.** 3 corren en PG y 39 en SQLite.

| Grupo | Mutantes | Ejemplos |
|---|---|---|
| Reserva atómica (PG) | 3 | Sin el cerrojo consultivo, sin READ COMMITTED, sin el cupo dentro del cerrojo |
| Presupuesto y cupo | 8 | `>` → `>=` en el techo del día y en el tope por sesión; sin techo; lo abandonado sigue reservado; lo liquidado no cuenta; `>=` → `>` en el cupo; holgura en lo aceptado; el día de UTC en vez del de Bogotá |
| Apertura, URL y cierre | 12 | El fallo del proveedor no libera; la URL de 60 s; el plazo = la caducidad de la URL; el plazo o el cierre no cobran; cualquiera termina; la repetición sin mirar a la persona; sin deduplicar el informe; prorrateo hacia abajo; cualquier tipo de informe; la URL no se entrega; sin la bandera |
| Veredicto | 6 | El simulador lo admite; quien abrió la sesión o el autor de la versión juzgan; sin la huella; manda el más antiguo; la puerta ve el simulador |
| Agente de laboratorio | 6 | Sin comprobar la proyección; ambiguo = no creado; compila como publicación; sin el tope de duración; con destinos; 5 conversaciones al día |
| Configuración | 6 | Cualquier verdadero enciende; sin la credencial verificada; `\|\|` → `&&` en los presupuestos; la reserva redondea hacia abajo; sin el tope duro; un driver no válido cae al simulador |
| Defensa en profundidad | 1 | El controlador no exige la llave. Lo caza el orden de las cerraduras: sin llave y con la bandera apagada responde 409 en vez de 403 |

En la primera pasada sobrevivió uno: **V06**, «la puerta de publicación ve el simulador». Ninguna prueba escribía a mano en la base un veredicto sobre una sesión del simulador. Añadí `test_la_puerta_de_la_publicacion_no_cuenta_un_veredicto_del_simulador_aunque_se_escriba_a_mano`, y V06 queda cazado.

Ejemplos de lo que los caza:
- **L01**, sin el cerrojo: la petición no espera (`WAITER=0`), lee lo comprometido antes de que confirme la otra sesión y pasa el techo del día.
- **R01**, `>=` en el techo del día: lo caza la frontera exacta de 0,60 US$.

## 6. Hallazgos para otros paquetes, riesgos y pendientes

1. **El cableado del informe post-llamada (§2.6)** es una decisión del lead con SEC8 y B8-b. Sin él, el laboratorio funciona, pero sin duración ni guarda del proveedor y cobrando reservas enteras. **Riesgo si B8-b no distingue las conversaciones del laboratorio:** un informe de un agente que no está en `voice_calls` podría acabar como `unknown_provider_call` y abrir el freno.
2. **Las herramientas y la iniciación en una sesión web** [por confirmar con la cuenta real, como ya anotó B8-a]:
   - **Herramientas:** el agente de laboratorio llama a `POST /api/voice/tools/{tool}` con su `conversation_id`. La comprobación de D8-10 exige una fila en `voice_calls`, así que en el laboratorio **las herramientas responderán que no hay llamada**. Las respuestas que dependen de planes, horarios, la transferencia o «no volver a llamar» no se pueden evaluar.
   - **Iniciación:** si ElevenLabs llamara al webhook de iniciación también en las sesiones web, B8-b respondería `caller_authorized = no` y el agente diría «línea no disponible».
   - El CRM puede mandar las variables dinámicas al empezar (`caller_authorized`, `call_ref`, `call_direction`, `version_label`), pero eso no arregla las herramientas.
   - Decisión del lead con B8-b: o admitir las conversaciones de laboratorio en las herramientas (con respuestas sintéticas y marcadas), o aceptar la limitación y decirlo en `fidelity`.
3. **El limitador de `POST voice-lab/sessions`.** Propuse `ultron-studio-lab-run` (el del H4: 10/h por persona) y S8-b lo puso. La guarda del H4 `UltronStudioLabNoEngineEffectsTest` prohíbe ese limitador fuera de `lab/` y además compara la lista entera de rutas del Studio, así que cae con cualquier ruta de voz. Dos opciones:
   - un limitador propio, `ultron-studio-voice-lab`, en `AppServiceProvider::limitadoresDelStudio`, que no es de ningún paquete del H8;
   - ampliar esa guarda para excluir `voice-lab/` y exigir el limitador ahí.

   Si se elige la primera, en mis pruebas es una línea de `VOICE_LAB_ROUTES`.
4. **Los destinos de transferencia:** hoy un perfil con destinos da 409 `voice_lab_transfers_not_supported`, porque la carga los necesita en claro (D8-14). S8-b ya tiene `StudioVoiceProfiles::transferNumbers()` y `phoneHash()` en el árbol, sin confirmar. Cuando se confirmen, son unas 6 líneas en `StudioVoiceLabAgents::prepare()`:
   - descifrar con `transferNumbers()`;
   - `StudioVoiceCompiler::assertDestinationsMatch($spec->toArray(), $numeros, StudioVoiceProfiles::phoneHash(...))`;
   - pasar los números al `VoiceAgentTarget`.

   No lo enlacé para no depender de código sin confirmar.
5. **Los dos lectores del veredicto** (§2.5).
6. **Los perfiles con más de 5 minutos** no se pueden probar (§3.3). Es coherente con D8-23, pero conviene decírselo al propietario.
7. **La clave de ElevenLabs** que viajó por el chat (E2-2): con el laboratorio encendido en `elevenlabs`, la misma clave crea agentes y pide URL. Se rota antes de la fase 2, como ya dice E2-2.

## 7. Lo que no está verificado

- Nada contra ElevenLabs de verdad (fase 2):
  - la vigencia real de la URL firmada (15 min, [por confirmar] según B8-a);
  - que `call_limits.daily_limit` cuente también las conversaciones web;
  - que el webhook de iniciación no se dispare en las sesiones web;
  - el `conversation_id` y la duración del informe post-llamada de una conversación web.
- El E2E con el CRM (FE8) y con los simuladores del banco (OPS8).
- La pila con la puerta real: mientras B8-b no clasifique las rutas, mis pruebas la quitan (§2.1).
- La suite completa: solo he corrido mis pruebas y las guardas que rastrean mis ficheros (máquina compartida).

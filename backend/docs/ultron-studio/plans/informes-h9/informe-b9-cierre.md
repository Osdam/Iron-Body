# Informe B9-cierre · lo que quedaba del Hito 9 tras W9 y V9 (presupuesto de voz, orden de Meta, fallos pasajeros, textos, m-9 y H-1)

**Paquete:** cierre de B9 (`backend`). **Contrato:** `docs/ultron-studio/ULTRON_STUDIO_H9_CONTRACT.md` (§3 protecciones 1, 2, 10, 11 y 12; D9-6, D9-8, D9-9.3, D9-9.4, D9-10, D9-11.2, D9-14; L9-55…L9-79). **Insumos:** `informe-w9.md` §1, §3 y §6 (ganchos 1, 4, 6 y 7), `informe-v9.md` §4–§7 (G-3…G-6), `informe-reviewer.md` §13.2 (m-9) y §14.1 (H-1). **Árbol:** `_worktrees/ultron-h8/Iron-Body/backend` y `…/Iron-Body_Front`, rama `feat/ultron-studio-h9` (HEAD `d701b0c8`), **sin commit, sin push, sin despliegue, sin producción, sin red real**. **Fecha:** 10/11-11-2026 (reloj UTC). Las constantes `OutreachSendGate::SENDS_ENABLED_IN_CODE` y `VoiceCallPolicy::CALLS_ENABLED_IN_CODE` **siguen en `false`** y no se tocan; ninguna prueba las gira (la «puerta abierta» de la escalera es el doble de W9). Todo lo nuevo nace apagado o en cero.

## 0. Resumen en cifras

| Qué | Cifra |
|---|---|
| Encargos | 1–5 hechos (obligatorios); 6 (opcional) **documentado, no hecho** (§3.9) |
| Backend · ficheros existentes tocados | 10 (los míos de la lista del lead): `OutreachDispatcher` +49/−4, `OutreachPlanner` +20/−6, `OutreachReconciler` +29/−5, `OutreachMonitor` +7/−1, `LadderInputBuilder` +24/−4, `OutreachConfig` +38, `DecisionTraceText` +89/−4, `config/outreach.php` +10/−2, `.env.example` +6 (solo las tres variables), `tests/Unit/Outreach/Core/TraceTextCases.php` +3/−1 (+ el fixture exportado `trace-text-cases.json`, regenerado con su herramienta) |
| Backend · ficheros nuevos | 2 de código (`app/Services/UltronStudio/StudioTemplateMetaChecks.php`, `app/Console/Commands/UltronStudio/TemplateMetaCheckCommand.php`) y 7 de pruebas en `tests/Feature/Outreach/B9Cierre/` (**34 pruebas, 313 aserciones**) |
| CRM · ficheros tocados | 5: el espejo `commercial-automation-trace-text.ts` (+82/−2) y su spec (+11), la paridad `h9-fixtures.spec.ts` (+2/−1: la huella fijada del diccionario) y la copia sincronizada `testing/fixtures/{trace-text-cases.json, manifest.json}` (por su guion, `sync-h9-fixtures.mjs`) |
| Textos | 72 códigos nuevos con frase (116 → 188), regla de prefijo `meta_error_<n>` y `metaErrorPrefix` en la exportación |
| SQLite · `tests/Feature/Outreach` completo | **600 pruebas, 37 801 aserciones, 163 omitidas (solo PG), 1 roja: `H9RollbackTest.php:237`, prevista** (fijaba el defecto m-9; enmienda en §5, verificada) |
| PG 14 desechable (`ultron_studio_b9c`) · `Run` + `W9` + `V9` + `Schema` + `B9Cierre` | **530 pruebas, 26 081 aserciones, 28 omitidas, 1 roja: la misma `H9RollbackTest.php:237`** |
| Mis pruebas nuevas | SQLite **34/34** (313) · PG **34/34** (313) |
| Regresiones «fallan antes / pasan después» | en un clon APFS con los cuatro servicios de HEAD: **13 rojas** (m-9 ×3, H-1 ×2, WhatsApp ×5: la carrera, los tres pasajeros y la de la lista de pasajeros; voz pasajera ×3); con el arreglo, verdes (las 2 de control de la voz, `failed`, pasan en los dos casos, como deben) |
| Guardas + aislamientos (`Outreach/Guards`, `OutreachIsolationTest`, `UltronEngineIsolationTest`) | **33/33** (14 370 aserciones; «580 sentencias, 32 escrituras, todas en tablas del H9») |
| Núcleo (`tests/Unit/Outreach/Core`) | **887/887** (39 249), incluida «`trace-text-cases.json` es el export vigente» |
| CRM | módulo `commercial-automation` **464/464** (13 ficheros; con `IRON_BODY_BACKEND_DIR`: copias byte a byte del backend); `tsc -p tsconfig.app.json` **0**; `tsc -p tsconfig.spec.json` **0** |
| Pint | `--test` sobre todo lo mío: **passed** |
| Red, Meta, Twilio, ElevenLabs, n8n, OTP, Legacy, producción | **Ninguno** |

## 1. Encargo 1 · presupuesto y cupo de la voz en el H9 (G-3…G-6 de V9)

- **`config/outreach.php`, bloque `voice`** (nombres EXACTOS del encargo, los usará el mando): `daily_cap` = `$entero('COMMERCIAL_AUTOMATION_VOICE_DAILY_CAP', 0, 0, 20)`, `daily_budget_usd` = `$dolares('COMMERCIAL_AUTOMATION_VOICE_DAILY_BUDGET_USD')`, `reserve_usd_per_call` = `$dolares('COMMERCIAL_AUTOMATION_VOICE_RESERVE_USD')`; la cabecera del fichero enumera ya `voice.{enabled,daily_cap,daily_budget_usd,reserve_usd_per_call}` entre las claves del mando. Todo nace en `0`/`'0'` (la guarda `OutreachFlagsGuardTest` de SEC9 lo comprueba sin cambios: sigue verde).
- **`OutreachConfig`**: `voiceDailyCap()` (0–20), `voiceDailyBudgetMicros()`, `voiceReserveMicros()` (los tres CONFIGURADOS) y `voiceBudget()`, el presupuesto EFECTIVO que usan la escalera y la reserva: **sin reserva por llamada (0), cupo y techo efectivos 0** (D-1, §3.1).
- **`LadderInputBuilder::budget()`** para `voice_call`: `dailyBudgetMicros`, `costMicros` = la reserva y `channelDailyCap` de `voiceBudget()`. Con los defectos N4 niega `budget_zero`; con valores > 0 deja de negar por presupuesto.
- **`OutreachPlanner::parcels()`**: la parcela `channel:voice_call` = (1 unidad, la reserva en micros) contra (cupo, techo) efectivos. Sin reserva la parcela no cabe (techo 0): «la escalera ya negó `budget_zero`; esto es la red», como antes.
- **`OutreachMonitor::checkBudgets()`**: el techo de `voice_call` (unidades y micros CONFIGURADOS) detrás de WhatsApp y del asistido (el orden de los frenos que ya fija `H9MonitorThresholdsTest` no cambia).
- **`.env.example`**: las tres variables, en `0`, con su comentario, detrás de `COMMERCIAL_AUTOMATION_VOICE_ENABLED` (el resto del diff pendiente de ese fichero es de otro agente: `VOICE_CONTACTS_ENABLED`).

Pruebas (`B9Cierre/VoiceBudgetTest`, 6, mundo de V9 sin tocarlo): defectos y recortes (50 → 20, `mucho`/`1e9`/`-1` → 0) y lectores; **con los defectos** la escalera niega `budget_zero` aun con la puerta abierta (doble); **con cupo, techo y reserva** la escalera con la constante real niega SOLO `sends_disabled_in_code` y con el doble deja salir hasta el canal real, que se niega por la constante (`failed`, reserva liberada, cero filas de voz, cero jobs); **sin reserva** `budget_zero`; el **planificador** reserva 2 × 1,5 US$ contra (2, 3 US$) y aplaza la tercera persona a mañana con `budget_exhausted` + `channel_daily_cap` (N4); el **monitor** echa `budget_breach` del canal de voz al bajar el techo a 2 US$ con 3 reservados, con su incidente (2/2 unidades, 3 000 000/2 000 000 micros) y el despachador deja los dos planificados esperando.

## 2. Encargo 2 · `ultron-studio:template-meta-check` (W9 gancho 4)

**Dónde vive y por qué.** En el Studio: `App\Console\Commands\UltronStudio\TemplateMetaCheckCommand` (la orden) y `App\Services\UltronStudio\StudioTemplateMetaChecks` (validar, comparar y registrar), con el patrón de `ultron-studio:person-bindings`. No puede vivir en `app/Console/Commands/Outreach`: `UltronEngineIsolationTest` (`OUTREACH_ZONES`) prohíbe que esa zona nombre el Studio, y la tabla es del Studio (§6.1). El servicio solo ESCRIBE `ultron_studio_template_meta_checks` (una fila) y LEE la plantilla, la cuenta, su vínculo y (tras registrar) el veredicto del puente.

**Uso** (todas las opciones en castellano, como `--cuerpo-fichero` del encargo):

```
php artisan ultron-studio:template-meta-check --plantilla=<uuid de la versión> --nombre-meta=<[a-z0-9_]{1,64}> \
    --idioma=es|es_CO --categoria=marketing|utility --estado=APPROVED|REJECTED|PAUSED|DISABLED [--id-meta=<dígitos>] \
    --cuerpo-fichero=<ruta> --por=<id o correo> [--comprobado-el=AAAA-MM-DDTHH:MM:SSZ] [--peticion=<uuid>] [--aplicar] [--json]
php artisan ultron-studio:template-meta-check --lista [--json]
```

- **En seco por defecto**: valida, resuelve, compara y dice qué escribiría y si el canal ACEPTARÁ la plantilla (campo a campo: nombre, idioma, categoría, texto y estado; y del Studio: aprobada, bytes que casan con su huella, categoría de su finalidad). No escribe nada (probado: 0 filas).
- **`--aplicar`**: UNA fila, en una transacción, con los hechos de Meta **tal cual** (casen o no: una pausa o un rechazo en Meta también se registran; manda la última). Después, el veredicto lo da el **puente** de la ejecución (`approvedWhatsappTemplate()` + `metaApproved()`, lo mismo que mira el canal).
- **Quién**: `--por` = la cuenta del CRM de quien lo comprobó en el administrador de Meta: **activa**, con **`ultron.approve`** (y `ultron.view`, como toda llave del Studio: `StudioAccess::require`) y con su **vínculo verificado a una persona** (D-5, §3.5). Su id y nombre van a `recorded_by_*`.
- **El texto**: se lee del fichero (≤ 8 192 bytes), se valida (UTF-8, 1–1 024 caracteres, no solo espacios), se normaliza (sin BOM, CRLF → LF, sin UN salto final) y se guarda **solo su huella**; nunca se imprime, se devuelve ni se registra (probado: la salida no lo contiene; `--json` no lo lleva).
- **Idempotente** por `--peticion` (`client_request_id`): la misma petición con los mismos hechos no escribe otra fila («YA REGISTRADA»); con otros, 409 `client_request_reused` (sale con 2). Una carrera en el UNIQUE se relee y se repite.
- **Salidas**: 0 (en seco, registrada o repetida); 2 si los datos no valen, la plantilla no existe o no es de WhatsApp, la persona no vale o la petición se reusó. Un fallo de base sube tal cual (no se traga).

Pruebas (`B9Cierre/TemplateMetaCheckCommandTest`, 10, mundo de W9 sin tocarlo; SQLite **y PG**, donde los CHECK de dominio y forma y la guarda entre tablas aceptan la fila): en seco no escribe y dice «ACEPTARÁ»; **con `--aplicar` registra exactamente los hechos** (la huella es la del `bodyPreview` aunque el fichero lleve salto final) **y después el canal de W9 pasa la comprobación de Meta y el trabajo planificado SALE** (`started`, un mensaje, una petición al Graph falso, con `openDispatcher()` de W9); repetición idempotente y `client_request_reused`; una pausa posterior en Meta deja de aceptarla (`meta_status_not_approved`) y un nombre distinto lo dice en seco (`meta_name_mismatch`); **paridad**: en 8 variantes (casa, nombre, idioma, categoría, texto, `REJECTED`, `DISABLED`, plantilla en borrador en el Studio) el veredicto en seco es el del puente tras registrar; `--lista`; **negativas** (sale con 2 y 0 filas): plantilla inexistente, plantilla asistida, cuenta de Recepción sin la llave (`forbidden`), cuenta inactiva y cuenta inexistente por id o correo (`admin_not_found`), cuenta sin persona (`person_binding_required`), `--por` vacío, estados `ACTIVE`/`pending`, idioma y categoría fuera del dominio, nombre e id con otra forma, uuid y petición sin forma, instante sin forma o futuro, texto de 1 025 caracteres, vacío o con UTF-8 roto, fichero inexistente o de más de 8 192 bytes; y la huella (BOM, CRLF, un solo salto, caracteres y no bytes).

## 3. Decisiones (y por qué)

| Id | Decisión | Por qué |
|---|---|---|
| **D-1** · voz sin reserva | `voiceBudget()` devuelve cupo y techo EFECTIVOS 0 si la reserva por llamada es 0: N4 `budget_zero` y la parcela no cabe | Con cupo y techo pero sin reserva, el H9 reservaría 0 micros por llamada: el techo en dólares quedaría inerte sin que nadie lo viera. Falla cerrado y visible (protección 12; el comentario de `config/outreach.php` ya decía «0 = sin precio = `budget_zero`»). Los lectores devuelven lo CONFIGURADO; el monitor compara con eso |
| **D-2** · el monitor | Techos CONFIGURADOS de la voz (no los efectivos) | Como WhatsApp: bajar el techo por debajo de lo reservado frena el canal; poner la reserva a 0 a media jornada no fabrica un `budget_breach` |
| **D-3** · los pasajeros de `settle()` | `OutreachDispatcher::TRANSIENT_FAILURES`: `agent_paused`, `human_takeover`, `voice_braked`, `mode_not_live`, `mode_off` (N2) y `channel_disabled` (N4) | Son condiciones que la escalera TAMBIÉN mira en el mismo instante (`marketing_agent_paused`, `human_in_control`, el freno de voz por `Facts`, la maestra y el modo, la bandera del canal) y que cambiaron entre la etapa 6 y la puerta. **`human_takeover` va más allá de la lista del lead**: es el equivalente en la puerta de N2 `human_in_control`, y el outbox deja ese mensaje `dead` (nunca sale), así que reintentar con otro trabajo no duplica. **Fuera**: `sends_disabled_in_code` (W9 lo proponía), `channel_not_live` y `calls_disabled_in_code` son literales que la escalera y el canal leen en el mismo proceso (una discrepancia es un defecto, que debe sonar como `failed`), y lo que la escalera NO ve (`concurrency_exceeded`, `outside_hours` del horario propio del H8, que es independiente del del H9, `daily_cap_reached`, `budget_exhausted` del H8…): esperar 15 min no lo cambia y repetiría el intento hasta `attempts_exhausted`; para eso está el `on.failed` del paso (`retry_step`) |
| **D-4** · cómo queda | El trabajo queda `failed_to_start` (el grafo de DB9 solo admite eso desde `dispatching`), la reserva se libera, la inscripción espera `WAIT_MINUTES` (15, la del despachador) y se escribe una decisión `cancel` (N2/N4, el código, `gate.recheck = channel`), las tres cosas en UNA transacción; devuelve `waiting`, sin resultado | Sin la decisión el código se perdería (no hay `failed` ni `cancel_reason`); es la forma de `cancel(…, wait: true)` del propio despachador |
| **D-5** · quién da fe de Meta | Cuenta activa + `ultron.approve` + persona verificada | `ultron.approve` es la llave que aprueba las plantillas del Studio (`AuthorizationMap`: `POST …/strategies/templates/{uuid}/approve`); registrar la aprobación EXTERNA es el mismo acto de gobierno, y D9-11.2 exige persona verificada para aprobar una plantilla. Descartada `ultron.integrations_manage` (configuración de proveedores, no contenido). Sin cuatro ojos sobre la propia fila: lo que se registra es un hecho externo y Meta rechaza un envío de una plantilla que no aprobó |
| **D-6** · sin evento en el libro del Studio | La fila ES el registro (solo inserción, quién y cuándo) | Evita duplicar; si el lead quiere el evento `message_template.meta_checked` en `ultron_studio_audit_events` (actor `system`, como el arranque de personas), es una línea en `record()` |
| **D-7** · textos | 72 frases; `meta_error_<n>` por prefijo (como `voice_policy:`); `metaErrorPrefix` en la exportación; **sin frase para `canceled`** | El CRM pinta con la misma función (`codeText`) los motivos, `cancel_reason`, `exit_reason`, los detalles del historial **y los estados de trabajo**: `canceled` (detalle del normalizador de voz) llevaría su frase a todo trabajo cancelado. Los códigos abiertos del H8 (`create_<x>`, `observe_<x>`) se enseñan tal cual. La clave nueva de la exportación es aditiva: el `parse` del CRM ignora claves desconocidas |
| **D-8** · m-9 | Planificador: `cancel` N5 (`strategy_moved`/`catalog_incompatible`) o N1 (`unknown_subject`, como el despachador); conciliador: `cancel` N5 con el `run_id` de su pasada; cierre + decisión en una transacción y solo si de verdad cerró (si otro la cerró antes, nada; el recuento `canceledByChain` cuenta solo las cerradas) | La propuesta del revisor («una decisión `cancel` (N5, `[strategy_moved]`) como la del despachador»); la traza vacía (sin foto) como la del despachador, reproducible por `outreach:replay` (probado: `same`) |
| **D-9** · H-1 | `run()` sin argumento: `RelativeTime::utc($now ?? now())` | El reloj de la aplicación como las demás pasadas (al segundo); en producción es el mismo |
| **D-10** · encargo 6 | **No hecho** | §3.9 |

### 3.9 Encargo 6 (W9 gancho 7): exigir Meta al planificar — documentado

No es pequeño ni coherente sin tocar otros dueños: (1) la escalera solo tiene `template_not_approved`; usarlo para Meta borraría la distinción (`template_not_approved_by_meta`) y un motivo nuevo de N4 es un cambio del núcleo (`PolicyLadder::REASON_ORDER`), de sus fixtures (`policy-ladder-cases.json`) y de la paridad del CRM; (2) rompe `tests/Feature/Outreach/W9/WhatsappTemplateChannelTest.php:239-255` (`plannedWhatsappJob(…, metaCheck: false)` dejaría de planificar). **Diseño propuesto** (B9-core + TEST9 + FE9 + W9): entrada nueva `step.templateMetaApproved` que `LadderInputBuilder` calcula SOLO para `whatsapp_template` con el puente (`approvedWhatsappTemplate()` + `metaApproved()`, dos lecturas por paso); motivo N4 `template_not_approved_by_meta` (aviso en sombra, negativa en vivo); el planificador no reserva y la inscripción queda `blocked` → `on.blocked` (espera y se vuelve a mirar), en vez de `failed` → `end_exit` en el canal; la prueba de W9 pasa a esperar la negativa del planificador. Mientras tanto, el canal falla cerrado (`template_not_approved_by_meta`, no es pasajero: `failed`).

## 4. Contratos nuevos o modificados

- **Variables de entorno (nuevas, en 0):** `COMMERCIAL_AUTOMATION_VOICE_DAILY_CAP` (0–20), `COMMERCIAL_AUTOMATION_VOICE_DAILY_BUDGET_USD`, `COMMERCIAL_AUTOMATION_VOICE_RESERVE_USD` → `outreach.voice.{daily_cap, daily_budget_usd, reserve_usd_per_call}`. El mando (`ops/h9-outreach-scope.sh`, OPS) tendría que conocerlas: no lo toqué.
- **PHP:** `OutreachConfig::voiceDailyCap(): int`, `::voiceDailyBudgetMicros(): int`, `::voiceReserveMicros(): int`, `::voiceBudget(): array{dailyCap, dailyBudgetMicros, reserveMicros}`; `OutreachDispatcher::TRANSIENT_FAILURES` (código → nivel); `DecisionTraceText::META_ERROR_PREFIX`.
- **Orden:** `ultron-studio:template-meta-check` (§2). Salida JSON: `{mode: dry_run|applied, template, check (la fila, sin texto), comparison: {fields, studioApproved, channelAccepts, reasons}, latestBefore, result: {uuid, replayed, channelAccepts}|null}`; con `--lista`: `[{uuid, key, number, status, purpose, meta, channelAccepts}]`.
- **Exportación de textos** (`GET strategies/catalog` → `traceText`, y `trace-text-cases.json`): + `metaErrorPrefix: 'meta_error_'`, + 72 textos; formato sigue `outreach-trace-text/1`; huella del diccionario `e83bce27…d019d37` (antes `e58ae106…`). El CRM desplegado hoy lo tolera (claves extra ignoradas; los códigos sin frase se ven tal cual).
- **Decisiones nuevas en `outreach_decisions`** (mismo esquema, ningún cambio de DB9): `cancel` del planificador y del conciliador (m-9) y `cancel` de `settle()` en los pasajeros. Sin migraciones.
- **Comportamiento:** un `failed_to_start` pasajero deja de producir `failed` (y su `end_exit` por defecto); el trabajo cuenta en la tasa de fallos del monitor (`failed_to_start`) igual que antes.

## 5. Prueba que cae por el trabajo legítimo (NO la cambié) y su enmienda

`tests/Feature/Outreach/Run/H9RollbackTest.php:237` (`test_25_en_la_planificacion_una_inscripcion_vencida_de_la_version_revertida_se_cancela_strategy_moved`) afirma `0` decisiones distintas de `enroll` tras la cancelación del planificador: es exactamente el defecto m-9. Cae en los dos motores (SQLite y PG, «1 is identical to 0»). **Enmienda propuesta** (endurece, no relaja; verificada en un clon APFS: `H9RollbackTest` 5/5 en SQLite y en PG; `git apply --check` limpio desde la raíz del repositorio):

```diff
--- a/backend/tests/Feature/Outreach/Run/H9RollbackTest.php
+++ b/backend/tests/Feature/Outreach/Run/H9RollbackTest.php
@@ -234,7 +234,10 @@
         $this->assertSame(self::NOW, (string) $e->ended_at);
         $this->assertNull($e->next_due_at);
         $this->assertSame(0, DB::table('outreach_jobs')->count());
-        $this->assertSame(0, DB::table('outreach_decisions')->where('kind', '<>', 'enroll')->count());
+        // REV9 m-9 (cierre de B9): la cancelación del planificador deja su decisión `cancel` (N5, el motivo) y ninguna otra.
+        $this->assertSame(['cancel'], DB::table('outreach_decisions')->where('kind', '<>', 'enroll')->pluck('kind')->all());
+        $cancelada = DB::table('outreach_decisions')->where('kind', 'cancel')->sole();
+        $this->assertSame(['N5', ['strategy_moved'], (int) $e->id, null], [$cancelada->level, json_decode((string) $cancelada->reasons, true), (int) $cancelada->enrollment_id, $cancelada->job_id]);
         $this->assertSame(0, DB::table('outreach_outcomes')->count());
         $this->assertSame(0, $planner->planDue(now())['due'], 'cerrada: no vuelve a tocar');
     }
```

(El parche, como fichero: `b9c-enmienda-h9rollback-m9.patch` en el scratchpad de la sesión; ruta en el resumen al lead.)

En el CRM actualicé el literal de `h9-fixtures.spec.ts:25` (la huella fijada del diccionario), que es la prueba de paridad que el encargo pide mantener en verde: sigue fijando un valor exacto, el del export nuevo.

## 6. Qué verifiqué (comando → resultado)

Desde `backend/`, `php -d memory_limit=2G`, `--no-logging`, un proceso pesado a la vez.

```
vendor/bin/phpunit tests/Feature/Outreach                                            # SQLite
  → Tests: 600, Assertions: 37801, Failures: 1, Skipped: 163 → H9RollbackTest.php:237 (§5)
UNIT_DB=ultron_studio_b9c pgunit.sh tests/Feature/Outreach/{Run,W9,V9,Schema,B9Cierre} # PG 14 desechable
  → Tests: 530, Assertions: 26081, Failures: 1, Skipped: 28 → la misma H9RollbackTest.php:237
vendor/bin/phpunit tests/Feature/Outreach/B9Cierre        → OK (34 tests, 313 assertions)
pgunit.sh tests/Feature/Outreach/B9Cierre                 → OK (34 tests, 313 assertions)
clon APFS + los 4 servicios de HEAD (Monitor, Planner, Reconciler, Dispatcher), mis pruebas
  → 4 errores + 9 fallos = 13 rojas (CancelDecisionTimeline 3, MonitorClock 2, TransientWhatsapp 5, TransientVoice 3)
clon APFS + la enmienda de §5: H9RollbackTest.php           → OK (5 tests, 108 assertions) en SQLite y en PG
vendor/bin/phpunit tests/Feature/Outreach/Guards tests/Feature/UltronStudio/{OutreachIsolationTest,UltronEngineIsolationTest}.php
  → OK (33 tests, 14370 assertions)
vendor/bin/phpunit tests/Unit/Outreach/Core (+ B9Cierre/DetailCodeTextsTest) → OK (887 tests, 39249 assertions)
vendor/bin/phpunit tests/Feature/UltronStudio/Strategies  → OK (33 tests, 612 assertions)
vendor/bin/phpunit <guardas que barren Services/UltronStudio y Commands/UltronStudio: EngineReadOnly, LabNoEngineEffects,
  StudioPlainTextInvisibles, TelephonyIsolation, StudioLabIsolation, Monitor, MonitorDriftAndFailuresH7>
  → 54 tests, 1 error AJENO: UltronStudioLabNoEngineEffectsTest.php:213 busca CheckSecretExposure::containsRealSecret(),
    que quitó otro agente en app/Console/Commands/CheckSecretExposure.php (diff sin commit; en HEAD existe)
vendor/bin/pint --test <lo mío>                            → passed
php tests/Unit/Outreach/Core/tools/export-trace-text-cases.php → 46 927 bytes, sha256 52910b48…
php artisan list ultron-studio | grep template-meta       → registrada; --help con las 13 opciones
CRM: node testing/sync-h9-fixtures.mjs <backend>          → 6 copias (5 idénticas, sha256 previo = backend) + manifiesto
CRM: ng test --include=…/commercial-automation/**/*.spec.ts (IRON_BODY_BACKEND_DIR) → 13 files, 464 passed
CRM: npx tsc -p tsconfig.app.json --noEmit → 0 · npx tsc -p tsconfig.spec.json --noEmit → 0
```

## 7. Qué NO verifiqué

- **La suite completa del backend** (regresión por nombre, L9-49): no la corrí; sí todo `tests/Feature/Outreach`, el núcleo, las guardas, los aislamientos, el gobierno del Studio y las guardas que barren los directorios donde añadí ficheros.
- **`OutreachIsolationTest` y `UltronEngineIsolationTest` en PG** (los pidió el encargo sin motor: los corrí en SQLite).
- **La vía de la voz hasta la puerta del H8 con presupuesto**: en el árbol compartido el canal real se niega por la constante del H9; no corrí `v9-abierta.sh` (la copia con las constantes giradas). Su prueba `V9OpenGateTest::test_el_despachador_no_llega_al_canal_sin_presupuesto_ni_cupo_de_voz_en_el_h9` sigue esperando `budget_zero` con los defectos (no fija valores de voz), así que debería seguir verde allí: **hipótesis, no comprobada**.
- **El mando de OPS** (`h9-outreach-scope.sh`) con las tres variables, el banco y producción: nada.
- **Mutaciones**: ninguna pasada salvo la vuelta atrás de los cuatro servicios en el clon.
- **El CRM en el navegador**: solo specs y `tsc` (sin `ng build` ni capturas).

## 8. Riesgos, pendientes y propuestas para otros dueños

1. **Guardas de SEC9 (lead)**: `OutreachStaticGuardsTest::H9` no barre los dos ficheros nuevos del Studio (`app/Services/UltronStudio/StudioTemplateMetaChecks.php`, `app/Console/Commands/UltronStudio/TemplateMetaCheckCommand.php`). Cumplen sus reglas (sin proveedores, red ni `Log::`), pero conviene añadirlos a la lista. La guarda de lectores de `TEMPLATE_META_CHECKS` (`OutreachIsolationTest:151`) es de `Outreach` y no aplica al Studio.
2. **`settle()`**: entre la transición del trabajo y lo que sigue (resultado o espera) hay una ventana si el proceso muere (preexistente en el camino `failed`); el trabajo quedaría `failed_to_start` sin resultado ni espera. Mi rama de pasajeros es atómica en sí misma. Y cada carrera pasajera consume un intento (`MAX_ATTEMPTS` = 3) y cuenta en la tasa de fallos del monitor.
3. **WhatsApp sin precio**: el comentario de `config/outreach.php` y de `OutreachConfig::whatsappPriceMicros()` dice «0 = sin precio = `budget_zero`», pero la escalera no lo hace (con precio 0 reserva 0 micros; W9 lo asumió en su §4). No lo cambié (es de W9); con D-1 la voz sí falla cerrado. Decidir si WhatsApp debe hacer lo mismo.
4. **CRM (FE9)**: `TraceDictionaryDto` (`commercial-automation-api.types.ts`) no declara `metaErrorPrefix` (el `parse` lo ignora; no rompe). `GET outreach/status` no enseña el cupo, el techo ni la reserva de la voz (WhatsApp sí): lo dejé así para no desalinear los ejemplos del CRM; es una línea en `OutreachConfig::status()` si se quiere.
5. **Despliegue**: backend y CRM juntos para que las frases nuevas se vean; si no, el CRM enseña el código (sin romper).
6. **Programación** del normalizador de Meta y de voz: la hace el lead en `routes/console.php` (no lo toqué).

## 9. Resumen corto para el lead

- **Hecho, sin commit, todo apagado:** (1) presupuesto de voz: `voice.{daily_cap, daily_budget_usd, reserve_usd_per_call}` ← `COMMERCIAL_AUTOMATION_VOICE_{DAILY_CAP, DAILY_BUDGET_USD, RESERVE_USD}` (0; cupo 0–20), lectores en `OutreachConfig`, N4, parcela del planificador y techo del monitor; sin reserva falla cerrado (`budget_zero`). (2) `ultron-studio:template-meta-check` en el Studio, en seco por defecto, `--aplicar` escribe una fila idempotente; da fe una cuenta activa con `ultron.approve` y persona verificada; tras registrar, el canal de W9 pasa y el trabajo sale (probado con sus dobles). (3) `settle()`: `agent_paused`, `human_takeover`, `voice_braked`, `mode_not_live`, `mode_off` y `channel_disabled` → la reserva vuelve, la inscripción espera 15 min con decisión `cancel`, sin `failed`. (4) 72 frases nuevas en `DecisionTraceText` y en el espejo del CRM (+ `meta_error_<n>`), fixture regenerado y sincronizado. (5) m-9 (decisión `cancel` del planificador y del conciliador, visible en el historial) y H-1 (reloj del monitor), con regresiones que fallan antes y pasan después.
- **Cifras:** `tests/Feature/Outreach` SQLite 600 (1 roja prevista) · PG Run+W9+V9+Schema+nuevas 530 (la misma) · nuevas 34/34 en los dos motores · guardas+aislamientos 33/33 · núcleo 887/887 · CRM 464/464 y `tsc` 0/0 · Pint OK.
- **Para ti:** aplicar la enmienda de `H9RollbackTest.php:237` (§5; parche `b9c-enmienda-h9rollback-m9.patch`); confirmar D-1, D-3 (incluí `human_takeover`, excluí `sends_disabled_in_code`) y D-5; añadir los dos ficheros nuevos a la lista `H9` de la guarda estática. Encargo 6 queda documentado (§3.9). Rojo ajeno: `UltronStudioLabNoEngineEffectsTest.php:213` (otro agente quitó `CheckSecretExposure::containsRealSecret()`).

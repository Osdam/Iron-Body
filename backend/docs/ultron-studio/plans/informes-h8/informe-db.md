# Hito 8 · paquete DB8 · informe del `database-specialist`

**Fecha:** 03-11-2026 (Bogotá). **Árbol:** `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h8-voice`, sobre `75a6384` (Enmienda 1). **Sin commit:** todo queda en el árbol de trabajo para que el lead lo confirme. **Producción:** no he tocado nada, ni para leer.

**Contrato:** `ULTRON_STUDIO_H8_CONTRACT.md`: D8-6, D8-7, D8-8, D8-10, D8-12, D8-13, D8-17, D8-18, D8-19, D8-23, «Contratos de datos», mi fila de «Paquetes», la puerta 1 y la **Enmienda 1** (E1-1, E1-2 y E1-4). Fixture de partida para los estados: `tests/fixtures/voice/state-transitions.json` (TEST8-a, DF-S1…DF-S9).

## 0. Resumen

- **Dos migraciones, una por espacio de nombres (D8-2), cada una atómica** (tablas + guardas + REVOKE):
  - `2026_11_10_100001_create_ultron_studio_voice_tables.php`: el gobierno de la voz (4 tablas `ultron_studio_voice_*`);
  - `2026_11_10_100002_create_voice_tables.php`: la telefonía (7 tablas `voice_*`).
- **Inertes:** crean tablas y guardas; no alteran ninguna tabla existente (solo la FK de los agentes a `ultron_studio_versions`), no nombran el motor ni la cadena del H5 y no encienden nada.
- **Las guardas** (tercera capa, con SQL a mano también):
  - solo inserción en las 6 que dice el contrato (cadena, eventos, consentimientos, exclusiones, consultas al RNE y frenos), con `REVOKE UPDATE, DELETE` del rol de la aplicación, comprobación posterior y guarda de producción, como DB7;
  - la cadena de voz encadenada por id **y por huella** (`prev_hash`, E1-4), `CHECK (seq >= 1)` y la huella comprobada contra los bytes;
  - `voice_calls` solo hacia delante por el grafo de D8-13 alineado con el fixture (DF-S2, DF-S3) y la Enmienda (`dialing → active`; `denied`; tramos de transferencia enlazados con su madre);
  - cuatro ojos en perfiles, veredictos del laboratorio y consentimientos (CHECK en PG y disparador equivalente en SQLite);
  - índices únicos parciales: un agente por parte (canario y resto), una saliente viva por número, un contacto vivo por teléfono y una revocación oral por llamada;
  - ninguna columna con un teléfono en claro: huella (64 hex) + 4 dígitos, y el número solo cifrado en `voice_contacts`.
- **Recuentos (para OPS8):** PG, Studio **44 → 68 disparadores, 50 → 84 CHECK, 4 → 6 parciales, 14 → 18 tablas**; telefonía **0 → 27 disparadores, 51 CHECK, 3 parciales, 7 tablas**; 4 funciones nuevas, ninguna SECURITY DEFINER; la de F, intacta (md5 `4ad748b5…c197d7ee`). SQLite: Studio **32 → 55** disparadores; telefonía **21**.
- **Pruebas mías (4 ficheros, 273 pruebas):** **PG 273 / 2 951 aserciones, 0 fallos**; **SQLite 273 / 1 860, 0 fallos y 199 omitidas** (solo PG, lo dicen). Con las guardas de SEC8 y del motor, en el árbol compartido: **PG 295 / 86 824 y SQLite 295 / 85 733**, en verde.
- **Mutaciones:** **59 mutantes, 91 ejecuciones** (cada uno en el motor donde existe su camino), en una copia aislada: **todos mueren**. S16 sobrevivía en los dos motores y destapó un hueco de mi prueba, que reforcé (§8).
- **Ensayo dueño/app con roles reales** (sin superusuario): la aplicación queda en `ra` en las 6 de solo inserción y `rawd` en las 5 con máquina de estados; como la aplicación de verdad, 20 intentos de UPDATE/DELETE/`FOR UPDATE`/TRUNCATE → 42501 y todo lo demás como debe (§7.4).
- **Bloquea la puerta 1 hasta que el lead decida:** con mis migraciones, **8 pruebas ajenas** del H5/H6/H7 caen por sus recuentos absolutos de todo el Studio. El parche está validado en los dos motores y **no lo he aplicado** (§9.1).

## 1. Motor, versión y entorno

| | Versión | Dónde |
|---|---|---|
| PostgreSQL | 14.24 (Homebrew), el de producción según los informes H5–H7 (no lo he comprobado en producción) | Clúster desechable `127.0.0.1:55432`. Bases mías: `ultron_studio_h8_db` (pgunit), `ultron_studio_h8_db_roles` (modelo dueño/app), `ultron_studio_h8_db_mut` (mutaciones), `ultron_studio_h8_db_try` y `ultron_studio_h8_db_rst_*` (ensayos) |
| SQLite | 3.54.1 (PDO de PHP 8.3.33) | `:memory:` en la suite; ficheros en el scratchpad para `migrate`/`rollback`/`fresh` |
| Laravel / PHPUnit | 12.58 / 11.5.55 | Sin fichero de entorno: mandan las variables `DB_*` del proceso |

Roles de ensayo creados en el clúster local: `ultron_studio_h8_owner` (dueño, LOGIN, sin superusuario) y `ultron_studio_h8_app` (aplicación, LOGIN, NOINHERIT). No he tocado `h5_owner`/`h5_app` (los del banco de OPS).

**Trabajo aislado:** desarrollé y probé en clones APFS del backend en el scratchpad (`bk`, `bk2` = HEAD actual + mis ficheros, `bk_h7` = sin ellos, `bk_mut` = mutaciones). Con `RefreshDatabase` toda prueba aplica todas las migraciones, así que una migración a medias en el árbol compartido habría roto las suites de los otros cinco agentes.

## 2. Ficheros (lista exacta)

| Fichero | Líneas | Qué |
|---|---|---|
| `database/migrations/2026_11_10_100001_create_ultron_studio_voice_tables.php` (nuevo) | 980 | Cabecera 1–170; constantes 171–358 (tablas, clasificación de columnas, grafos de estado 246–252, CHECK de dominio 282 y de forma 307, parciales 348); `appRole()` 403; `rowTriggers()` 422; `sqliteOnlyRowTriggers()` 474; `forwardOnly()` 499; `createTables()` 512; `upPostgres()` 690 (funciones 721 y 752); `downPostgres()` 777; `upSqlite()` 799; `downSqlite()` 828; `assertColumnsClassified()` 843; `revokeAppWrites()` 884; `refuseWithRows()` 934 |
| `database/migrations/2026_11_10_100002_create_voice_tables.php` (nuevo) | 1 114 | Cabecera 1–138; constantes 139–448 (estados y `CALL_TRANSITIONS` 195, `LEG_INITIAL_STATUSES` 208, `TOOL_STATUSES` 211, CHECK de dominio 293, `rowChecks()` 332, parciales 439); `appRole()` 494; `rowTriggers()` 512; `sqliteOnlyRowTriggers()` 569; `forwardOnly()` 587; `createTables()` 597; `upPostgres()` 853 (`voice_forbid()` 873, `voice_brake_release()` 898); `downPostgres()` 920; `upSqlite()` 939; `downSqlite()` 958; `assertColumnsClassified()` 972; `revokeAppWrites()` 1016; `refuseWithRows()` 1067 |
| `tests/Feature/UltronStudio/UltronStudioVoiceSchemaTest.php` (nuevo) | 1 452 | 26 métodos, 98 casos: columnas, recuentos, funciones, fronteras, perfiles, agentes, la cadena (11 casos rotos), sesiones, 63 CHECK de forma, roles y `down()` |
| `tests/Feature/Telephony/Schema/VoiceSchemaFixtures.php` (nuevo) | 642 | Rasgo: filas válidas de cada tabla (sin teléfonos), la sonda de CHECK `violatedChecks()`, el modelo dueño/app |
| `tests/Feature/Telephony/Schema/VoiceSchemaTest.php` (nuevo) | 616 | 11 métodos: columnas, recuentos, función propia, fronteras, `down()`, ida y vuelta, REVOKE, comprobación posterior, guarda de producción |
| `tests/Feature/Telephony/Schema/VoiceCallTransitionsTest.php` (nuevo) | 532 | 13 métodos, 102 casos: nacer, la matriz completa (desde 20 estados de partida), lo que se fija, el cupo, `denied`, tramos, UNIQUE, parciales y 71 CHECK de forma |
| `tests/Feature/Telephony/Schema/VoiceLedgerSchemaTest.php` (nuevo) | 335 | 9 métodos, 62 casos: solo inserción, deduplicación, cuatro ojos, revocación oral, freno, contactos y 54 CHECK |
| `docs/ultron-studio/plans/informes-h8/informe-db.md` (nuevo) | — | Este informe |

`vendor/bin/pint --test` sobre los 7 PHP: `passed`. No he tocado ningún fichero ajeno: ni `ops/`, ni las guardas de SEC8, ni las pruebas del H5/H6/H7 (el parche de §9.1 está en el Anexo A, sin aplicar).

## 3. El esquema (lo que B8, S8 y LAB8 tienen que usar)

Convenciones de todas las tablas: `id` bigint; `uuid` único (el id de la API); quien actúa, como instantánea `*_admin_id` + `*_name` + `*_role` **sin FK** a `admins` (como F–H7); `created_at` NOT NULL; teléfonos solo como huella `*_hash` (HMAC `voice-phone`, 64 hex en minúsculas) + `*_last4`; códigos (motivos, fallos, terminaciones) con la forma `^[a-z][a-z0-9_]*$`; claves del proveedor `^[A-Za-z0-9_.:-]{1,128}$`; ids del proveedor hasta 128 (como `VoiceIds::PROVIDER_ID_PATTERN` de B8-a) y `call_sid` = `CA` + 32 hex; JSON de códigos con tope de 4 KB en PG (no cabe una transcripción).

### 3.1 Gobierno (`2026_11_10_100001`)

| Tabla | Columnas clave | Ciclo | Reglas |
|---|---|---|---|
| `ultron_studio_voice_profiles` | `revision`, `based_on_profile_id`, `label`, `language` (`es`), `max_duration_seconds` (60–600), `retention_days` (0–30), `record_calls` y `record_voice` (las dos `false`), `transfer_count` (0–3) + `transfer_destinations_encrypted` (NULL ⇔ 0), `content` (JSON del conjunto cerrado de D8-6, sin teléfonos), `content_hash` | `draft` → `submitted` → `approved` \| `rejected` | Nace `draft` con el contenido **congelado** (la API no tiene ruta de edición: cambiar es otra revisión); lo revisa otra identidad (aprobar y rechazar); el rechazo lleva nota; aprobado o rechazado es definitivo |
| `ultron_studio_voice_agents` | `purpose` (`lab` \| `publication`), FK a la versión y al perfil + sus huellas, `compiler_version` (`ultron-voice-compiler/N`), `spec_hash`, `payload_hash`, `projection_hash`, `provider` (`elevenlabs` \| `fake`, este solo en `lab`), `provider_agent_id` (único, una vez), `observed_hash`/`observed_at` (una vez), `failure_code`, `serves_canary`, `serves_rest` | `pending` → `deployed` \| `rejected` \| `failed` | Nace `pending`; `deployed` exige id y observada = compilada; `rejected`, observada ≠ compilada; `failed`, con código. **Huellas, nunca la carga** (lleva los destinos en claro). Atiende una parte solo si es de publicación y desplegado; **a lo sumo uno por parte** (`usva_one_canary`, `usva_one_rest`) |
| `ultron_studio_voice_publications` | `seq` (único, ≥ 1), `action` (`publish` \| `promote` \| `rollback` \| `off`), `scope` (`canary` \| `rest`), `previous_publication_id` + `prev_hash`, `restores_publication_id`, `agent_id`, `effective_json` (text: los bytes firmados de E1-4), `effective_hash` (= sha256 de esos bytes, comprobado en PG), `signature`, `lab_gate`, `evidence`, `reason` | Solo inserción | La siguiente a la cabeza, apuntando a ella por id y por huella (64 ceros en la primera). `publish` = canario con agente; `promote` = resto con agente; `rollback` = con la que vuelve y agente; `off` = sin agente; rollback y off con motivo. Solo agentes de publicación desplegados. Lo vigente de cada parte = su entrada de mayor `seq` (`usvpub_scope_idx`) |
| `ultron_studio_voice_lab_sessions` | `agent_id`, `budget_day`, `reserved_micros`, `cost_micros`, `max_seconds` (60–300), `duration_seconds`, `url_expires_at` (la URL no se guarda), `ended_at`/`end_reason`, `guard_codes`, `verdict` (+ nota, quién, cuándo) | `open` → `ended` \| `failed` | Nace `open`; el veredicto, de otra identidad y sobre una sesión terminada; con veredicto (o fallida) es definitiva |

### 3.2 Telefonía (`2026_11_10_100002`)

| Tabla | Columnas clave | Reglas |
|---|---|---|
| `voice_calls` | `call_ref` (único), `direction`, `status`, destino (`target_source` = `canary_registry` \| `voice_contacts` \| `transfer_destination`; `canary_number_uuid` sin FK; `voice_contact_id`), `remote_hash`/`remote_last4`, `caller_authorized` + `policy_reason` (entrantes), quién (`client_request_id`, `requested_by_*`), `attempt` + `parent_call_id` (reintentos), **`transfer_of_call_id`** (tramos, E1-2), el **sello** de D8-8 (`voice_publication_seq`, `part`, `stamp_state`, `studio_version_uuid`, `studio_content_hash`, `voice_profile_hash`, `spec_hash`, `payload_hash`, `compiler_version`, `provider`, `provider_agent_id`), `provider_conversation_id` y `provider_call_sid` (únicos, una vez), la observación (`observed_*`, `seal_verified`, una vez), coste (`budget_day`, `reserved_micros`, `provider_cost_micros`, `carrier_cost_micros`), `duration_seconds`, `termination_reason`, `failure_code`, `tool_calls`, `started_at`, `ended_at` | §4 |
| `voice_call_events` | `call_id` (NULL en las sin fila), `provider` (`elevenlabs` \| `twilio` \| `laravel`), `kind` (los 15 de D8-13), `provider_event_key`, `tool` (solo en `tool_call`, catálogo cerrado de D8-10), `outcome`, `codes`, actor | Solo inserción; UNIQUE (`provider`, `kind`, `provider_event_key`) (`vce_dedupe_unique`; con clave NULL no se deduplica: DF-S7); los tipos de una llamada exigen `call_id`; `unknown_provider_call`, ninguno |
| `voice_contacts` | huella, 4 dígitos, `phone_encrypted`, `source_type` (`lead` \| `member` con `source_id` sin FK; `manual` sin él), `status` (`active` \| `retired`), la **petición de consentimiento pendiente** (`consent_request_*`: todo NULL o todo puesto), registro y retirada | Un vivo por teléfono (`vct_one_live`); lo de su registro no cambia; retirado es definitivo y sin cifrado; el cifrado solo se borra (un `save()` del cast `encrypted` que lo reescriba falla) |
| `voice_consents` | `contact_id`, `kind` (`granted` \| `revoked`), `channel` = `voice_call`, `purpose` = `commercial`, `source` (los 3 de D8-18), `evidence` (≤ 300), quien la cargó (`recorded_by_*`, `recorded_at`) y quien la confirmó (`confirmed_by_*`) | Solo inserción. **Cuatro ojos** en todo `granted` cargado por una persona (`written_form` y `admin_with_evidence`): confirma otra; la revocación no espera a nadie; `recorded_inbound_request`, solo el sistema |
| `voice_exclusions` | huella, 4 dígitos, `source` (los 5 de D8-18), `call_id`, `contact_id`, `rne_check_id`, nota, actor | Solo inserción. `spoken_opt_out` la registra el sistema con su llamada, **una por llamada** (`vex_one_opt_out_per_call`); `rne` cita su consulta; las señales del lead citan el contacto; `admin`, una persona |
| `voice_rne_checks` | huella, `contact_id`, `checked_at` (≤ `created_at`), `result` (`listed` \| `not_listed`), `method` (`manual_portal` por una persona \| `api`), `evidence_ref`, actor | Solo inserción |
| `voice_brakes` | `kind` (`open` \| `release`), `brake_id` (la apertura que suelta), `reason` (las 6 causas de D8-19), `open_key` (único: la idempotencia de cada causa concreta), `call_id`, `event_id`, `evidence`, `note`, `client_request_id`, actor | Solo inserción: **abrir y soltar son filas**. Abre el sistema (con causa y clave); suelta una persona con nota; una apertura se suelta **una vez** (`vbr_release_once`) y lo que se suelta es una apertura (`vbr_release_targets_open`). «Hay freno» = alguna `open` sin su `release` |

## 4. `voice_calls`: estados, nacimiento y lo que se fija

**Grafo (solo hacia delante; `vc_status_forward`).** Es el del fixture de TEST8-a más la Enmienda:

| Desde | Hacia | Origen |
|---|---|---|
| `requested` | `canceled`, `failed_to_start`, `ambiguous`, `dialing`, `completed`, `busy`, `no_answer`, `failed` | D8-13 + DF-S2 (el webhook antes que el job) |
| `ambiguous` | `dialing`, `completed`, `busy`, `no_answer`, `failed` | DF-S3 (nunca `canceled` ni `failed_to_start`) |
| `dialing` | **`active`**, `completed`, `busy`, `no_answer`, `failed`, `hung_up` | D8-13 + **E1-1** |
| `active` | `completed`, `failed`, `hung_up` | D8-13 + DF-S4 (entrantes y, con E1-1, salientes contestadas) |
| `canceled`, `failed_to_start`, **`denied`**, `completed`, `busy`, `no_answer`, `failed`, `hung_up` | — (finales) | D8-13 + **E1-2** |

**Nacer (`vc_starts_live`):** la saliente que pide Laravel, `requested`; la entrante, `active` (autorizada) o **`denied`** (E1-2); el **tramo de transferencia**, en el estado en que lo ve el proveedor (`dialing` … `hung_up`), nunca `requested`, `canceled`, `failed_to_start`, `ambiguous` ni `denied`. Nadie nace con herramientas usadas, con el sello observado ni (salvo un tramo ya terminado) terminado.

**Se fija al empezar** (`vc_forbid_immutable_update`, por lista de columnas): dirección, destino, huella, decisión de la entrante, quién, intento y padre, la madre de un tramo, el **sello** entero y la reserva. **Una vez** (`vc_bindings_once`): conversación, `call_sid`, observación del sello y motivo de la política. **El cupo de herramientas** solo crece, como mucho 20 y **solo con la llamada en `dialing` o `active`** (E1-1). La forma atómica para B8: `UPDATE voice_calls SET tool_calls = tool_calls + 1 [, status = 'active'] WHERE id = ? AND status IN ('dialing', 'active') AND tool_calls < 20` (0 filas = cupo agotado o llamada fuera de curso).

**CHECK de forma (solo PG, 18):** dirección y estado coherentes; destino según el tipo; huella y 4 dígitos juntos (una entrante de número oculto, sin ninguno); quién (persona con su petición, o el sistema solo en un reintento; nadie en entrantes y tramos); intentos; sello completo o vacío (`applied` en toda saliente pedida; `inherited` y vacío en un tramo); huellas, versión del compilador e ids; ids según el estado (una saliente no los tiene hasta marcar; un tramo, solo `call_sid`); `ended_at` en los finales (salvo `denied`, que lo recibe o no con la post-llamada); contadores no negativos y ninguna herramienta en una `denied`; `seal_verified` = «el observado es el sellado»; códigos; decisión de la entrante (`active` ⇒ autorizada, sin motivo y con sello; `denied` ⇒ no autorizada y con motivo; solo una saliente cancelada lleva motivo); y el tramo (`vc_transfer_leg_chk`: sin observación, herramientas ni reserva propia, y nunca su propia madre).

**Índices:** `vc_one_live_outbound` (parcial: una saliente viva por número en `requested`, `ambiguous`, `dialing` o `active`; los tramos no cuentan), `vc_client_request_unique` (`requested_by_admin_id`, `client_request_id`: **por persona**), `vc_retry_unique` (`parent_call_id`, `attempt`), los UNIQUE de conversación, `call_sid` y `call_ref`, y `vc_status_idx`, `vc_remote_idx`, `vc_day_idx`, `vc_transfer_idx`.

## 5. Recuentos antes y después (para OPS8)

Medido en `ultron_studio_h8_db_roles` (el H7 migrado por el dueño con el REVOKE del H7 aplicado, como en producción) y en ficheros SQLite nuevos:

| | Antes (H7) | Con el H8 | Tras `rollback --step=2` |
|---|---|---|---|
| PG · disparadores `ultron_studio_%` | **44** | **68** (+24) | 44 |
| PG · CHECK `ultron_studio_%` | **50** | **84** (+34) | 50 |
| PG · parciales `ultron_studio_%` | 4 | **6** (`usva_one_canary`, `usva_one_rest`) | 4 |
| PG · tablas `ultron_studio_%` | 14 | **18** | 14 |
| PG · disparadores / CHECK / parciales / tablas `voice_%` | 0 / 0 / 0 / 0 | **27 / 51 / 3 / 7** | 0 |
| PG · funciones nuevas (`ultron_studio_voice_publication_chain`, `…_agents`, `voice_forbid`, `voice_brake_release`) | 0 | **4**, ninguna SECURITY DEFINER | 0 |
| PG · disparadores apagados del H8 | — | 0 | — |
| PG · md5 de `ultron_studio_forbid()` | `4ad748b5f17f09b398f7f641c197d7ee` | el mismo | el mismo |
| SQLite · disparadores `ultron_studio_%` / `voice_%` | 32 / 0 | **55 / 21** | 32 / 0 |
| SQLite · parciales `ultron_studio_%` / `voice_%` | 4 / 0 | 6 / 3 | 4 / 0 |
| Migraciones registradas | 327 | 329 | 327 |
| Aplicación en las 6 de solo inserción del H8 | — | **`ra`** (y `USAGE` en sus secuencias) | — |
| Aplicación en perfiles, agentes, sesiones, llamadas y contactos | — | `rawd` (por los privilegios por defecto del dueño) | — |
| Aplicación en la cadena, el libro y las instantáneas del H7 | `ra` | `ra` (no se tocan) | `ra` |
| Aplicación en canarios y asociaciones del H6 | `rawd` | `rawd` (no se tocan) | `rawd` |

**La consulta** que da todo eso, de solo lectura, con `-v app=<rol>`, está en §14 para que OPS8 la lleve a su `h8-schema-check.sql` (es suyo; no lo he creado).

## 6. Decisiones fuera del contrato (a confirmar por el lead)

| # | Decisión | Por qué y evidencia |
|---|---|---|
| **D-1** | Dos migraciones, una por espacio de nombres, cada una con tablas, guardas y REVOKE en la misma transacción | Separar tablas y guardas abriría un estado a medias si el REVOKE o una guarda fallan en producción (R-1 del H6, D-6 de DB7). Probado: guarda de producción y `lock_timeout` dejan las dos `Pending` y 0 tablas (§7.5) |
| **D-2** | La telefonía tiene su **propia** función de guarda, `voice_forbid()` («voice: …»), como el laboratorio con `ultron_studio_lab_forbid()`; las 4 tablas del Studio usan la de F sin redeclararla | D8-2: la telefonía no depende del Studio. Además, la migración de telefonía no nombra el Studio en su código (prueba estática) |
| **D-3** | La misma variable que DB7, `ULTRON_STUDIO_DB_APP_ROLE`, para las dos migraciones | Con dos variables, una migración revocaría al rol del banco y la otra se callaría (el banco usa `APP_ENV=local`) |
| **D-4 (E1-4)** | La cadena de voz es **de una parte por entrada** (`scope` + `agent_id`), encadenada por id y por huella: `prev_hash` = el `effective_hash` de la cabeza (64 ceros en la primera), y en PG `effective_hash` = sha256 de los bytes guardados (`usvpub_hash_matches_chk`) | El mensaje firmado de E1-4 lleva un solo agente y un `scope`, no las partes `{canary, rest}` de D8-8. Lo vigente de cada parte es su entrada de mayor `seq`: **el lector de B8 lee dos filas**, no solo la cabeza. **Confirmar con S8-b y TEST8** (los vectores): que `prev_hash` es la huella sha256 de los bytes de la anterior |
| **D-5** | «A lo sumo una parte `canary` y una `rest` vivas» = dos banderas en los agentes (`serves_canary`, `serves_rest`) con un único parcial cada una; el mismo agente puede atender las dos | Como `usv_one_canary`/`usv_one_published` del H5 sobre versiones; la cadena es de solo inserción y no puede llevar un «vivo». S8 las mueve en la misma transacción que la entrada (soltar y después tomar) |
| **D-6** | Agente: `pending` → `deployed` \| `rejected` \| `failed`. «Retirado» **no es un estado**: es un agente desplegado que no atiende ninguna parte | Revertir (D8-20, nivel 1) vuelve a un agente anterior; si «retirado» fuera final, revertir sería imposible. La deriva posterior va al freno y a los eventos: `observed_hash` es la verificación del despliegue y no se reescribe |
| **D-7** | Perfil con el contenido congelado desde que nace (`draft`) | La API de D8-6 no tiene ruta de edición. Así los cuatro ojos «por identidad» son ciertos: nadie retoca un borrador ajeno y lo aprueba |
| **D-8** | Las banderas de privacidad del perfil se llaman `record_calls` (P-7) y `record_voice` (`privacy.record_voice` de ElevenLabs), no `recording_enabled`/`audio_storage_enabled` | La guarda de SEC8 (`TelephonyIsolationTest`) prohíbe en mis migraciones columnas con `recording` o `audio` (no guardar audio). Son banderas forzadas a `false` por `usvp_privacy_chk`, no contenido. Si SEC8 prefiere los nombres largos con una excepción, se cambian |
| **D-9** | El veredicto del laboratorio va en la misma fila de la sesión | El contrato da una sola tabla («Sesiones y veredictos»); es de otra identidad, sobre una sesión terminada y definitivo |
| **D-10** | Grafo de `voice_calls` = el del fixture de TEST8-a (DF-S2, DF-S3, DF-S4) + E1-1 + E1-2 | Mi primer borrador admitía `ambiguous → failed_to_start` y `ambiguous → hung_up` y no tenía DF-S2; lo alineé con el fixture, que es lo que congeló el lead para los casos |
| **D-11 (E1-2)** | El tramo de transferencia se enlaza con **`transfer_of_call_id`**, no con `parent_call_id` | `parent_call_id` ya es el de los reintentos de negocio (D8-12: intento > 1 ⇔ padre); mezclarlos rompería esa regla. El tramo: saliente, `target_source = transfer_destination`, sin quién, sello `inherited` (el de su madre), solo `call_sid`, sin herramientas ni reserva propia (su coste entra en la reserva de la madre, D8-14) y nacido en el estado que ve el proveedor. No cuenta como «saliente viva»: dos transferencias a la vez a recepción son legítimas |
| **D-12 (E1-2)** | `denied` nace así, es final, lleva la decisión (`caller_authorized = false`) y su motivo, y cero herramientas; su `ended_at` puede quedar NULL | La post-llamada de una denegada (duración, coste, observación) se escribe sin cambiar el estado. Sin `denied`, toda entrante de un desconocido abriría el freno (Q-6) |
| **D-13** | El proveedor de los eventos propios es `laravel` | Es el del ejemplo de DF-S7, que dejaba el conjunto a DB8 |
| **D-14** | El freno se abre solo (sistema) y por una de las 6 causas de D8-19; no hay apertura manual | El contrato solo enumera aperturas automáticas. Si se quiere una manual, es una enmienda (una causa y quitar `actor_type = 'system'` en `vbr_shape_chk`) |
| **D-15** | Cuatro ojos en **todo** consentimiento `granted` cargado por una persona (`written_form` y `admin_with_evidence`), no solo en `admin_with_evidence` | El texto del contrato nombra solo `admin_with_evidence`; P-18 (lead) dice «cuatro ojos en el consentimiento cargado por una persona», y un formulario también lo carga una persona. Más estricto; si el lead lo quiere solo en `admin_with_evidence`, es una línea |
| **D-16** | La petición de consentimiento pendiente vive en `voice_contacts` (`consent_request_*`) | `voice_consents` es de solo inserción: la segunda persona inserta la fila con las dos identidades y se limpia la petición. Con P-7 apagado, `recorded_inbound_request` no tiene grabación que la respalde: B8 no debería usarlo |
| **D-17** | Ninguna FK de la telefonía al Studio (el canario va por su uuid) | D8-2, y así el `down()` del H6 sigue pudiendo borrar sus tablas |
| **D-18** | Los disparadores «solo hacia delante» no miran las filas cerradas | PG dispara por orden alfabético y SQLite por orden inverso de creación: con dos guardas a la vez, el motivo dependía del motor (lo destapó la prueba en SQLite). Ahora un final da «es definitivo» en los dos |
| **D-19** | REVOKE solo en las 6 que dice el contrato | Las 5 con máquina de estados conservan DELETE (lo para su disparador, P0001). **Opcional:** revocar también DELETE en ellas; decide el lead |
| **D-20** | Ids del proveedor hasta 128 caracteres | Los DTO de B8-a admiten 128 (`VoiceIds::PROVIDER_ID_PATTERN`); con 64 la base rechazaría ids válidos |

## 7. Qué verifiqué ejecutando

### 7.1 Pruebas propias

```text
php -d memory_limit=2G vendor/bin/phpunit tests/Feature/Telephony/Schema tests/Feature/UltronStudio/UltronStudioVoiceSchemaTest.php
  → OK, but some tests were skipped! Tests: 273, Assertions: 1860, Skipped: 199.
UNIT_DB=ultron_studio_h8_db bash docs/ultron-studio/ops/pgunit.sh tests/Feature/Telephony/Schema tests/Feature/UltronStudio/UltronStudioVoiceSchemaTest.php
  → OK (273 tests, 2951 assertions)
```

| Fichero | PG (pruebas / aserciones) | SQLite (pruebas / aserciones / omitidas) |
|---|---|---|
| `VoiceSchemaTest` | 11 / 269 | 11 / 84 / 4 |
| `VoiceCallTransitionsTest` | 102 / 1 546 | 102 / 1 214 / 72 |
| `VoiceLedgerSchemaTest` | 62 / 352 | 62 / 113 / 55 |
| `UltronStudioVoiceSchemaTest` | 98 / 784 | 98 / 449 / 68 |
| **Total** | **273 / 2 951** | **273 / 1 860 / 199** |

Las omitidas son las solo-PG (CHECK, roles, funciones) y lo dicen. La atribución de cada CHECK se hace evaluando **su expresión real** de `pg_constraint` sobre la fila candidata (`violatedChecks()`), porque PG solo nombra el primero por orden alfabético; y cada caso se inserta además con los disparadores apagados para ver el 23514 de verdad.

**Lo que destaparon las pruebas** (y está corregido): seis CHECK que aceptaban NULL (una comparación con NULL da NULL y un CHECK la deja pasar: `usvl_verdict_chk`, `vct_consent_request_chk`, `vc_target_chk`, `vc_requester_chk`, `vc_authorization_chk`, `vce_tool_chk`, lo vi revisándolos antes de ejecutar), el motivo que dependía del motor (D-18), un caso que ninguna prueba cubría (`denied` autorizada y sin motivo), que añadí antes de las mutaciones, y el hueco que destapó S16 (§8).

### 7.2 En el árbol compartido, con el trabajo en curso de los demás

Copiados mis ficheros al worktree (HEAD `75a6384` más lo que los otros paquetes tienen sin confirmar):

```text
php -d memory_limit=2G vendor/bin/phpunit tests/Feature/Telephony tests/Feature/UltronStudio/UltronStudioVoiceSchemaTest.php tests/Feature/UltronStudio/UltronEngineIsolationTest.php
  → OK, but some tests were skipped! Tests: 295, Assertions: 85733, Skipped: 199.
UNIT_DB=ultron_studio_h8_db bash docs/ultron-studio/ops/pgunit.sh <los mismos>
  → OK (295 tests, 86824 assertions)
```

Son mis 273 más las guardas de SEC8 (`TelephonyIsolationTest`, `OtpUntouchedByVoiceTest`) y la del motor (`UltronEngineIsolationTest`), en verde tras D-8. Las 3 pruebas de esquema del H5/H6/H7, en los dos motores: **60 pruebas, 8 fallos**, los de §9.1 y ninguno más.

### 7.3 Ida y vuelta

| Comprobación | Resultado |
|---|---|
| PG `migrate` → `rollback --step=2` → `migrate`, `pg_dump -s` | H7 → H8 **+1 837 / −0** líneas; el H7 tras el rollback y el H8 de la segunda vez, **idénticos** |
| Lo mismo en el modelo dueño/app, **con ACL** | **+1 991 / −0**; idénticos en los dos sentidos |
| SQLite `sqlite_master` | H7 → H8 **+106 / −0** objetos; ida y vuelta idéntica; `migrate:fresh` = incremental |
| `down()` con una fila en cualquiera de las 11 tablas | Se niega con el recuento, sin tocar el esquema (prueba en los dos motores) |
| `down()` sin filas y `up()` | Mismo esquema; no toca `ultron_studio_versions` (SQLite no la reconstruye) |
| `--pretend` | `up`: 223 sentencias, los dos REVOKE, ninguna lectura; `down`: sus `LOCK … ACCESS EXCLUSIVE`; nada aplicado |

### 7.4 Modelo dueño/app con roles reales (`ultron_studio_h8_db_roles`)

- **Montaje:** `REVOKE CREATE ON SCHEMA public FROM PUBLIC`; el esquema de `ultron_studio_h8_owner` (sin superusuario); `ultron_studio_h8_app` (NOINHERIT) con privilegios por defecto `arwd` en tablas y `rwU` en secuencias, como `iron` → `iron_app`.
- **El dueño migró** el H7 (327 migraciones) y después el H8 con `ULTRON_STUDIO_DB_APP_ROLE=ultron_studio_h8_app` y `PGOPTIONS='-c lock_timeout=5s'`, **sin ningún GRANT**: 74 + 84 ms.
- **Conectado como `ultron_studio_h8_app`** (`rolsuper = f`), en una transacción que termina en ROLLBACK (`as_app.sql`):

  | Intento | Resultado |
  |---|---|
  | Insertar en las 11 y moverlas por sus caminos (perfil enviado y aprobado por otra, agente desplegado, entrada de la cadena, contacto, saliente → `dialing` → `active` con la primera herramienta, evento, consentimiento con dos personas, RNE, revocación oral, abrir y soltar el freno, sesión) | Pasa |
  | UPDATE, DELETE, `FOR UPDATE`/`FOR SHARE` y TRUNCATE en las 6 de solo inserción (20 intentos) | **42501** los 20 |
  | DELETE en llamadas, contactos, perfiles, agentes y sesiones | P0001 (sus disparadores) |
  | TRUNCATE en llamadas y perfiles | 42501 |
  | Volver a `requested`, cambiar el `call_sid`, reiniciar el cupo, rechazar un aprobado, cambiar el id de un agente | P0001 |
  | Un consentimiento cargado y confirmado por la misma persona | 23514 |
  | Soltar dos veces | 23505 |
  | `DISABLE TRIGGER`, `DROP TRIGGER`, `DROP CONSTRAINT`, `DROP INDEX`, `CREATE OR REPLACE FUNCTION` (las dos), `SET session_replication_role` | 42501 |
  | La tabla temporal suplantadora de la cadena, creada por la aplicación | P0001 |
  | `GRANT UPDATE` a sí misma | WARNING «no privileges were granted»; sigue sin UPDATE |

### 7.5 Operación

| Caso | Resultado |
|---|---|
| `APP_ENV=production` sin el rol en el clúster | exit 1 «el rol de la aplicación «iron_app» no existe en este clúster y APP_ENV es production…»; las dos `Pending`; **0 tablas** del H8 |
| Un **escritor** de `ultron_studio_versions` con la transacción abierta | **55P03 a los 5,34 s**; las dos `Pending`; nada aplicado. Al soltarlo, migra |
| Un **lector** de versiones con la transacción abierta | Migra en 0,46 s sin esperar |
| Restauración completa (`pg_dump -Fc` → `pg_restore`) con filas en estados no iniciales | Pasa |
| Restauración **solo de datos** sobre el esquema ya migrado | **Falla** en `vc_starts_live` (la fila nace `active`): R-2 de §10 |
| Solo datos con `--disable-triggers` (superusuario) | Pasa y los disparadores quedan encendidos |

## 8. Mutaciones

En `bk_mut` (copia aislada del HEAD actual con mis ficheros) y la base `ultron_studio_h8_db_mut`. Cada mutante es una sustitución en una copia de mi migración; corre solo las pruebas y el motor donde existe su camino, y el original se restaura antes del siguiente. **No muté nada del árbol compartido.** El arnés y los 59 mutantes (55 en la primera pasada, 3 de refuerzo y S23) están en el scratchpad de la sesión, `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body/d0b76c08-37c4-420f-8fc1-b9d437920339/scratchpad/mut/` (`mutants.py`, `extra.py`, `run.py`); los resultados crudos, en `results*.tsv` de esa carpeta.

| Mutante | Qué cambia | SQLite | PG |
|---|---|---|---|
| M00 | control | pasa | pasa |
| T01 | E1-1: sin dialing → active | **muere** (3 fallos, 6 errores) | **muere** (3 fallos, 7 errores) |
| T02 | un final deja de serlo | **muere** (1 fallos) | **muere** (1 fallos) |
| T03 | requested → hung_up (DF-S9) | **muere** (1 fallos) | **muere** (1 fallos) |
| T04 | ambiguous → failed_to_start (DF-S3) | **muere** (1 fallos) | **muere** (1 fallos) |
| T05 | una entrante nace completed | **muere** (1 fallos) | **muere** (1 fallos) |
| T06 | E1-1: herramientas fuera de curso | **muere** (1 fallos) | **muere** (1 fallos) |
| T07 | el cupo de herramientas baja | **muere** (1 fallos) | **muere** (1 fallos) |
| T08 | el call_sid cambia | **muere** (1 fallos) | **muere** (1 fallos) |
| T09 | la madre de un tramo cambia (deja de ser inmutable) | **muere** (102 errores) | **muere** (102 errores) |
| T10 | una saliente active no cuenta como viva | **muere** (1 fallos) | **muere** (1 fallos) |
| T11 | los tramos cuentan como saliente viva | **muere** (1 fallos) | **muere** (1 fallos) |
| T12 | una denegada autorizada | — | **muere** (1 fallos) |
| T13 | un tramo con herramientas | — | **muere** (1 fallos) |
| T14 | el sello heredado sin madre | — | **muere** (1 fallos) |
| T15 | una denegada con herramientas | — | **muere** (1 fallos) |
| T16 | el proveedor propio | — | **muere** (3 fallos, 1 errores) |
| T17 | SQLite sin cuatro ojos | **muere** (2 fallos) | — |
| T18 | PG: la misma persona confirma | — | **muere** (1 fallos) |
| T19 | PG: se suelta una suelta | — | **muere** (1 fallos) |
| T20 | SQLite: se suelta una suelta | **muere** (1 fallos) | — |
| T21 | se suelta dos veces | **muere** (1 fallos) | **muere** (1 fallos) |
| T22 | el freno sin REVOKE | — | **muere** (2 fallos) |
| T23 | sin comprobación posterior | — | **muere** (1 fallos) |
| T24 | sin guarda de producción | — | **muere** (1 fallos) |
| T25 | down() no se niega | **muere** (1 fallos) | **muere** (1 fallos) |
| T26 | el cifrado se reescribe | **muere** (1 fallos) | **muere** (1 fallos) |
| T27 | dos contactos vivos del mismo teléfono | **muere** (2 fallos) | **muere** (2 fallos) |
| T28 | dos revocaciones orales en una llamada | **muere** (2 fallos) | **muere** (2 fallos) |
| T29 | eventos duplicados | **muere** (1 fallos) | **muere** (1 fallos) |
| T30 | los eventos se modifican | **muere** (1 fallos) | **muere** (1 fallos) |
| T31 | las llamadas se borran | **muere** (4 fallos) | **muere** (5 fallos) |
| T32 | idempotencia por persona | **muere** (1 fallos) | **muere** (1 fallos) |
| T33 | el tramo sin FK a su madre | **muere** (1 fallos) | **muere** (1 fallos) |
| S01 | PG: sin prev_hash | — | **muere** (4 fallos) |
| S02 | SQLite: sin prev_hash | **muere** (4 fallos) | — |
| S03 | PG: cabeza sin cualificar | — | **muere** (2 fallos) |
| S04 | PG: agente de laboratorio en la cadena | — | **muere** (2 fallos) |
| S05 | SQLite: agente de laboratorio en la cadena | **muere** (1 fallos) | — |
| S06 | la huella no es la de los bytes | — | **muere** (2 fallos) |
| S07 | PG: el autor aprueba su perfil | — | **muere** (2 fallos) |
| S08 | SQLite: el autor aprueba su perfil | **muere** (1 fallos) | — |
| S09 | un perfil vuelve a draft | **muere** (1 fallos) | **muere** (1 fallos) |
| S10 | SQLite: el motivo de un final depende del orden | **muere** (1 fallos) | — |
| S11 | la observación del agente cambia | **muere** (1 fallos) | **muere** (1 fallos) |
| S12 | dos agentes en el canario | **muere** (8 fallos, 7 errores) | **muere** (7 fallos, 8 errores) |
| S13 | el veredicto cambia | **muere** (1 fallos) | **muere** (1 fallos) |
| S14 | la cadena sin REVOKE | — | **muere** (1 fallos, 1 errores) |
| S15 | down() no se niega | **muere** (1 fallos) | **muere** (1 fallos) |
| S16 | un perfil nace aprobado | sobrevivía → **muere** con la prueba reforzada (1 fallos) | sobrevivía → **muere** con la prueba reforzada (1 fallos) |
| S17 | el spec_hash del agente pasa a mutable | **muere** (1 fallos) | **muere** (1 fallos) |
| S18 | publicar en el resto | — | **muere** (2 fallos) |
| S19 | un agente desplegado que no cuadra | — | **muere** (1 fallos) |
| S20 | sin guarda de producción | — | **muere** (1 fallos) |
| S21 | SQLite: el autor juzga su sesión | **muere** (1 fallos) | — |
| S22 | PG: el autor juzga su sesión | — | **muere** (2 fallos) |
| T09b | la madre de un tramo pasa a mutable | **muere** (1 fallos) | **muere** (1 fallos) |
| T34 | el cifrado se reescribe (disparador sobre otra columna) | **muere** (1 fallos) | **muere** (1 fallos) |
| T35 | el disparador de transiciones vigila otra columna | **muere** (1 fallos) | **muere** (1 fallos) |
| S23 | una sesión nace terminada | **muere** (1 fallos) | **muere** (1 fallos) |

**Lectura:**
- «—»: el camino no existe en ese motor (un CHECK solo está en PG; un disparador equivalente, solo en SQLite), así que el mutante no se corre allí. El control (M00, sin mutar) pasa en los dos.
- **S16 sobrevivía en los dos motores** y era un hueco de mi prueba: mis casos de «nace en otro estado» llevaban además `submitted_by` o `reviewed_by`, así que nunca probaban `status <> 'draft'` sola (y el mutante, por precedencia, conservaba las otras condiciones). El mismo hueco estaba en las sesiones. Reforcé la prueba («nace `submitted`/`approved`/`rejected` a secas» y «nace `ended`/`failed` a secas») y S16 y el nuevo S23 mueren en los dos, con un fallo cada uno.
- **T09 muere por otra guarda:** al quitar `transfer_of_call_id` solo de las inmutables, la columna queda sin clasificar y `assertColumnsClassified()` no deja aplicar la migración (102 errores). T09b la pasa de inmutable a mutable y la caza la prueba de lo que se fija al empezar (1 fallo).
- T34 y T35 prueban que la LISTA de columnas de un `UPDATE OF` importa: el disparador del cifrado vigilando otra columna, y el de transiciones vigilando `updated_at`.
- Los mutantes con errores además de fallos rompen pasos intermedios de otras pruebas (p. ej. T01 impide llegar a `active`); siguen siendo muertes de su camino.

## 9. Impacto en pruebas y guardas ajenas

### 9.1 Los recuentos absolutos del H5/H6/H7 (bloquea la puerta 1; decide el lead)

Con mis migraciones caen 8 pruebas que cuentan disparadores, CHECK e índices parciales de **todas** las tablas `ultron_studio_%` (las 4 nuevas lo son por nombre del contrato). El propio comentario de una de ellas lo prevé: «si un hito posterior añade guardas, estos números se actualizan a conciencia».

| Prueba | Métodos que caen |
|---|---|
| `UltronStudioIntegrationsSchemaTest` | `test_recuentos_de_disparadores_check_e_indices_parciales_del_studio`, `test_down_se_niega_con_cualquier_dato_del_hito_6_y_no_toca_nada`, `test_down_sin_datos_del_hito_6_deja_el_esquema_del_hito_5_y_se_puede_volver_a_aplicar`, `test_up_exige_que_cada_columna_este_clasificada` |
| `UltronStudioHardeningH7Test` | `test_recuentos_y_nombres_de_las_guardas_de_la_cadena`, `test_down_sin_filas_deja_el_esquema_del_hito_6_y_up_lo_vuelve_a_dejar_igual` |
| `UltronStudioPublicationSchemaTest` | `test_recuentos_de_disparadores_y_check_del_studio`, `test_down_sin_filas_de_h5_deja_el_esquema_de_h4_y_se_puede_volver_a_aplicar` |

**Parche propuesto, sin aplicar:** solo literales y comentarios (+24 disparadores, +34 CHECK y los 2 parciales de los agentes en PG; +23 disparadores en SQLite), 29 líneas cambiadas en total. Validado en el clon: las tres pruebas **SQLite 60 / 545 (12 omitidas)** y **PG 60 / 885**, en verde. Está en el **Anexo A** de este informe (diff unificado; `git apply --check` limpio contra el árbol) y en el scratchpad de la sesión: `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body/d0b76c08-37c4-420f-8fc1-b9d437920339/scratchpad/patch/recuentos-h8.diff` y `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body/d0b76c08-37c4-420f-8fc1-b9d437920339/scratchpad/patch/recuentos_h8.py` (`python3 -I recuentos_h8.py <backend>`). Lo aplica quien diga el lead (TEST8, o yo con su orden).

### 9.2 Guardas de SEC8

- `UltronEngineIsolationTest`: mis dos migraciones entran por su patrón `database/migrations/2026_11_1*voice*.php`. **Nombres definitivos** para pasar a `ALLOWED` con su ruta exacta: `database/migrations/2026_11_10_100001_create_ultron_studio_voice_tables.php` y `database/migrations/2026_11_10_100002_create_voice_tables.php`. La de telefonía no nombra el Studio en su código; podría no estar en la lista.
- `TelephonyIsolationTest`: en verde tras D-8. Su excepción del lector de la cadena (`'ultron_studio_voice_publications'`) casa con mi tabla.

## 10. Impacto, riesgos y reversibilidad

**Impacto:**
- Esquema nuevo; ninguna tabla existente cambia (el `pg_dump` solo añade).
- Coste: una lectura de una fila por índice en cada inserción de la cadena (la cabeza) y otra en el agente que nombra; una subconsulta por inserción de una suelta del freno; nada en las demás escrituras. No he medido planes: las tablas nacen vacías y un EXPLAIN aquí no demuestra nada (PERF8 mide en el banco).
- Escrituras: cada UPDATE de `voice_calls` evalúa hasta 3 disparadores (los de las columnas que lleva el SET) y sus 18 CHECK, sobre una fila; unas pocas actualizaciones por llamada.
- Ninguna consulta del motor ni del Studio existente cambia.

**Riesgos:**
- **R-1 · Cerrojo:** la FK de los agentes pide SHARE ROW EXCLUSIVE sobre `ultron_studio_versions`. Con un escritor abierto, la migración cae limpia a los 5 s con 55P03 y se reintenta; con lectores, no espera (§7.5).
- **R-2 · Restauración solo de datos:** los disparadores de «nace en su estado inicial» rechazan, fila a fila, las llamadas, perfiles, agentes y sesiones en estados posteriores. La restauración completa no tiene el problema (los disparadores se crean después de los datos); la de solo datos necesita `--disable-triggers` (superusuario). Nota para la política de respaldos de OPS8.
- **R-3 · Sin `iron_app` en producción:** la migración se niega (D-3 y §7.5). Si el rol tiene UPDATE o DELETE heredados de otro rol o de PUBLIC, también (prueba de la comprobación posterior).
- **R-4 · Usos futuros:** cualquier UPDATE, DELETE, `FOR UPDATE/SHARE` u `ON CONFLICT DO UPDATE` sobre las 6 de solo inserción será 42501. Es a propósito.
- **R-5 · Dos violaciones en la misma sentencia:** el motivo que se ve puede depender del motor (orden de los disparadores); la sentencia falla igual en los dos. D-18 lo resuelve para el caso común (un final).

**Vuelta atrás:**
- Volver al código anterior **no exige revertir el esquema** (D8-20, nivel 4): las tablas quedan sin lector y no hacen nada.
- `down()`: **nunca en producción**. Fuera de ella se niega con filas; sin filas borra las 11 tablas y las 4 funciones (15–60 ms en local).

## 11. Hallazgos para otros dueños

- **Lead / TEST8:** el parche de §9.1. Y `state-transitions.json` tiene que pasar a la Enmienda: `dialing → active` y `active → completed | failed | hung_up` en salientes (su secuencia «una saliente no puede quedar active» ya no vale), `denied` como inicial y final de una entrante, y los tramos (nacen en el estado del proveedor). Mi matriz completa (20 estados de partida × 12 estados) es la verdad de la base.
- **B8 (modelos, políticas, webhooks, herramientas):**
  - las columnas y reglas de §3 y §4; el cupo de herramientas con el UPDATE condicionado de §4;
  - la frecuencia por contacto, los cupos del día y la concurrencia **excluyen los tramos** (`transfer_of_call_id IS NULL`), y «en curso» es `dialing` o `active`;
  - toda entrante tiene fila (`active` o `denied`), y todo tramo la suya, enlazada con su madre;
  - el lector de la cadena (D-4) lee la entrada vigente de cada parte (`usvpub_scope_idx`) y verifica las dos;
  - «hay freno» = una `open` sin su `release` (consulta de la prueba `openBrakes()`); abrir es idempotente por `open_key` (una por causa concreta: p. ej. el uuid del evento);
  - las claves del proveedor que no tengan la forma de `provider_event_key` (p. ej. un `IdempotencyToken` con `+` o `/`) van como su sha256 en hex.
- **S8 / LAB8:** §3.1. Al publicar, promover o revertir, mover `serves_*` en la misma transacción que la entrada (primero soltar, después tomar). Un agente nace `pending` y se marca `deployed` en el mismo UPDATE que su observación. Un perfil no se edita: se inserta otra revisión.
- **OPS8:**
  - los recuentos de §5 y su consulta (§14) para `h8-schema-check.sql`;
  - en el banco, `ULTRON_STUDIO_DB_APP_ROLE=<rol del banco>` en el entorno del `migrate` (sin él no se revoca, como en el H7);
  - la sección de voz de `supresion-1581.sql`:
    - datos personales: las huellas, los 4 dígitos y el cifrado de `voice_contacts`, `voice_calls`, `voice_exclusions` y `voice_rne_checks`; las evidencias, notas y motivos de retirada (`voice_consents.evidence`, `voice_contacts.consent_request_evidence` y `retire_reason`, `voice_exclusions.note`, `voice_brakes.note`, `voice_rne_checks.evidence_ref`); los nombres del personal en todas;
    - en el Studio: el cifrado de los destinos del perfil, `review_note` y `verdict_note`. `effective_json` está firmado y, como el del H5, no se redacta;
    - funciones de guarda que tiene que reconocer: **`voice_forbid()`** (las `voice_*`) y `ultron_studio_forbid()` (las `ultron_studio_voice_*`);
    - redactar una fila de solo inserción exige apagar en su transacción también los disparadores de `UPDATE OF` y de «una vez» de esa tabla (todos usan esas dos funciones);
  - R-2 para los respaldos.
- **SEC8:** los nombres exactos de §9.2 y D-8.

## 12. Operaciones pendientes de autorización

No he ejecutado ninguna fuera del clúster local. En producción, por OPS8 y con la orden del propietario (fase 1):

1. El check de antes (OPS8). Esperado: 44 / 50 / 4 / 14 en `ultron_studio_%`, 0 en `voice_%`, el rol `iron_app` presente y 0 escritores abiertos en `ultron_studio_versions`.
2. `sudo /usr/local/bin/ironbody-migrate`: las dos migraciones (`2026_11_10_100001` y `2026_11_10_100002`). Si cae por 55P03, reintentar.
3. El check de después. Esperado: §5 (68 / 84 / 6 / 18; 27 / 51 / 3 / 7; `ra` en las 6; la huella de F igual).

`down()` nunca se ejecuta en producción. Ninguna operación de datos: todo es nuevo y vacío.

## 13. Qué no verifiqué, y por qué

- **Producción:** nada. Ni los roles reales `iron`/`iron_app` ni `ironbody-migrate`: lo dirá el check de OPS8.
- **Los planes de ejecución:** tablas vacías; un EXPLAIN aquí no demuestra nada.
- **La suite completa del backend:** fuera de lo pedido; es de TEST8. Ejecuté mis 4 ficheros en los dos motores, las guardas de SEC8 y del motor, y las 3 pruebas de esquema del Studio con y sin el parche.
- **El banco de OPS8:** no lo he ejecutado.
- **Que los modelos de B8/S8 casen con el esquema:** todavía no existen (paso 2).

## 14. Reproducir

```bash
cd Iron-Body/backend
php -d memory_limit=2G vendor/bin/phpunit tests/Feature/Telephony/Schema tests/Feature/UltronStudio/UltronStudioVoiceSchemaTest.php
UNIT_DB=ultron_studio_h8_db bash docs/ultron-studio/ops/pgunit.sh tests/Feature/Telephony/Schema tests/Feature/UltronStudio/UltronStudioVoiceSchemaTest.php
# Modelo de roles: migrar como el dueño revocando al rol de la aplicación
env ULTRON_STUDIO_DB_APP_ROLE=<rol app> APP_ENV=local PGOPTIONS='-c lock_timeout=5s' DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55432 \
    DB_DATABASE=<base> DB_USERNAME=<rol dueño> DB_PASSWORD= DB_URL= php artisan migrate --force
```

La consulta de recuentos (solo lectura; `psql -v app=<rol app> -f`):

```sql
-- Recuentos del esquema del H8 (solo lectura). Variables: :app (el rol de la aplicación).
SELECT 'disparadores_studio', count(*) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron\_studio\_%'
UNION ALL SELECT 'check_studio', count(*) FROM pg_constraint k JOIN pg_class c ON c.oid=k.conrelid WHERE k.contype='c' AND c.relname LIKE 'ultron\_studio\_%'
UNION ALL SELECT 'parciales_studio', count(*) FROM pg_index i JOIN pg_class t ON t.oid=i.indrelid WHERE t.relname LIKE 'ultron\_studio\_%' AND i.indpred IS NOT NULL
UNION ALL SELECT 'tablas_studio', count(*) FROM pg_tables WHERE schemaname='public' AND tablename LIKE 'ultron\_studio\_%'
UNION ALL SELECT 'disparadores_studio_voz', count(*) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron\_studio\_voice\_%'
UNION ALL SELECT 'check_studio_voz', count(*) FROM pg_constraint k JOIN pg_class c ON c.oid=k.conrelid WHERE k.contype='c' AND c.relname LIKE 'ultron\_studio\_voice\_%'
UNION ALL SELECT 'disparadores_voz', count(*) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'voice\_%'
UNION ALL SELECT 'check_voz', count(*) FROM pg_constraint k JOIN pg_class c ON c.oid=k.conrelid WHERE k.contype='c' AND c.relname LIKE 'voice\_%'
UNION ALL SELECT 'parciales_voz', count(*) FROM pg_index i JOIN pg_class t ON t.oid=i.indrelid WHERE t.relname LIKE 'voice\_%' AND i.indpred IS NOT NULL
UNION ALL SELECT 'tablas_voz', count(*) FROM pg_tables WHERE schemaname='public' AND tablename LIKE 'voice\_%'
UNION ALL SELECT 'disparadores_apagados_h8', count(*) FROM pg_trigger t JOIN pg_class c ON c.oid=t.tgrelid WHERE NOT t.tgisinternal AND (c.relname LIKE 'voice\_%' OR c.relname LIKE 'ultron\_studio\_voice\_%') AND t.tgenabled <> 'O'
UNION ALL SELECT 'funciones_h8', count(*) FROM pg_proc WHERE proname IN ('ultron_studio_voice_publication_chain','ultron_studio_voice_publication_agents','voice_forbid','voice_brake_release')
UNION ALL SELECT 'funciones_h8_definer', count(*) FROM pg_proc WHERE proname IN ('ultron_studio_voice_publication_chain','ultron_studio_voice_publication_agents','voice_forbid','voice_brake_release') AND prosecdef
UNION ALL SELECT 'forbid_f_md5_'||md5(pg_get_functiondef(oid)), 1 FROM pg_proc WHERE proname='ultron_studio_forbid';
SELECT 'privilegios_app '||c.relname||' '||
       CASE WHEN has_table_privilege(:'app', c.oid, 'SELECT') THEN 'r' ELSE '-' END||
       CASE WHEN has_table_privilege(:'app', c.oid, 'INSERT') THEN 'a' ELSE '-' END||
       CASE WHEN has_any_column_privilege(:'app', c.oid, 'UPDATE') THEN 'w' ELSE '-' END||
       CASE WHEN has_table_privilege(:'app', c.oid, 'DELETE') THEN 'd' ELSE '-' END||
       CASE WHEN has_table_privilege(:'app', c.oid, 'TRUNCATE') THEN 'D' ELSE '-' END
  FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
 WHERE n.nspname = 'public' AND c.relkind = 'r' AND (c.relname LIKE 'voice\_%' OR c.relname LIKE 'ultron\_studio\_voice\_%' OR c.relname IN ('ultron_studio_publications','ultron_studio_audit_events','ultron_studio_versions'))
 ORDER BY c.relname;
```

## Anexo A · El parche de recuentos (§9.1), sin aplicar

Validado en una copia del árbol (SQLite 60 / 545 con 12 omitidas; PG 60 / 885). Se aplica con `git apply` desde la raíz del repo.

```diff
--- a/backend/tests/Feature/UltronStudio/UltronStudioIntegrationsSchemaTest.php
+++ b/backend/tests/Feature/UltronStudio/UltronStudioIntegrationsSchemaTest.php
@@ -67,17 +67,17 @@
      * aplicado: `*_H5` es el esquema sin el H6 (el down() del H6 no toca las
      * guardas del H7, que son de la cadena) y `*_H6`, con él.
      */
-    private const PG_TRIGGERS_H5 = 31;   // F 11 + laboratorio 16 + H5 3 + H7 1
+    private const PG_TRIGGERS_H5 = 55;   // F 11 + laboratorio 16 + H5 3 + H7 1 + H8 24
 
-    private const PG_TRIGGERS_H6 = 44;   // + asociaciones 5, canarios 5, instantáneas 3
+    private const PG_TRIGGERS_H6 = 68;   // + asociaciones 5, canarios 5, instantáneas 3
 
-    private const PG_CHECKS_H5 = 28;     // F 11 + laboratorio 13 + H5 3 + H7 1
+    private const PG_CHECKS_H5 = 62;     // F 11 + laboratorio 13 + H5 3 + H7 1 + H8 34
 
-    private const PG_CHECKS_H6 = 50;     // + 4, 12 y 6 (usae_subject_type_chk se sustituye, no se suma)
+    private const PG_CHECKS_H6 = 84;     // + 4, 12 y 6 (usae_subject_type_chk se sustituye, no se suma)
 
-    private const SQLITE_TRIGGERS_H5 = 22; // F 7 + laboratorio 11 + H5 2 + H7 2
+    private const SQLITE_TRIGGERS_H5 = 45; // F 7 + laboratorio 11 + H5 2 + H7 2 + H8 23
 
-    private const SQLITE_TRIGGERS_H6 = 32; // + 4, 4 y 2
+    private const SQLITE_TRIGGERS_H6 = 55; // + 4, 4 y 2
 
     /** Contrato §I3.1, en orden: columna → [tipo en PG, tipo en SQLite, NULL-able]. */
     private const LINK_COLUMNS = [
@@ -334,7 +334,7 @@
 
     public function test_recuentos_de_disparadores_check_e_indices_parciales_del_studio(): void
     {
-        $this->assertSame(['uscl_one_active', 'uscn_one_live', 'usv_one_canary', 'usv_one_published'], $this->studioPartialIndexes());
+        $this->assertSame(['uscl_one_active', 'uscn_one_live', 'usv_one_canary', 'usv_one_published', 'usva_one_canary', 'usva_one_rest'], $this->studioPartialIndexes());
 
         if ($this->isPostgres()) {
             foreach (self::PG_TRIGGERS as $tabla => $nombres) {
@@ -343,8 +343,8 @@
             foreach (self::PG_CHECKS as $tabla => $nombres) {
                 $this->assertSame($nombres, $this->checksOn($tabla), $tabla);
             }
-            $this->assertSame(self::PG_TRIGGERS_H6, $this->studioTriggerCount(), 'F 11 + laboratorio 16 + H5 3 + H6 13 + H7 1');
-            $this->assertSame(self::PG_CHECKS_H6, $this->studioCheckCount(), 'F 11 + laboratorio 13 + H5 3 + H6 22 + H7 1');
+            $this->assertSame(self::PG_TRIGGERS_H6, $this->studioTriggerCount(), 'F 11 + laboratorio 16 + H5 3 + H6 13 + H7 1 + H8 24');
+            $this->assertSame(self::PG_CHECKS_H6, $this->studioCheckCount(), 'F 11 + laboratorio 13 + H5 3 + H6 22 + H7 1 + H8 34');
 
             return;
         }
@@ -352,7 +352,7 @@
         foreach (self::PG_TRIGGERS as $tabla => $nombres) {
             $this->assertSame(array_values(array_filter($nombres, static fn (string $n): bool => ! str_ends_with($n, '_truncate'))), $this->triggersOn($tabla), $tabla);
         }
-        $this->assertSame(self::SQLITE_TRIGGERS_H6, $this->studioTriggerCount(), 'F 7 + laboratorio 11 + H5 2 + H6 10 + H7 2');
+        $this->assertSame(self::SQLITE_TRIGGERS_H6, $this->studioTriggerCount(), 'F 7 + laboratorio 11 + H5 2 + H6 10 + H7 2 + H8 23');
     }
 
     public function test_los_disparadores_reutilizan_la_funcion_de_f_con_su_mismo_cuerpo(): void
@@ -823,7 +823,7 @@
         foreach ([self::LINKS, self::CANARIES, self::SNAPSHOTS] as $tabla) {
             $this->assertFalse(Schema::hasTable($tabla), $tabla);
         }
-        $this->assertSame(['usv_one_canary', 'usv_one_published'], $this->studioPartialIndexes());
+        $this->assertSame(['usv_one_canary', 'usv_one_published', 'usva_one_canary', 'usva_one_rest'], $this->studioPartialIndexes());
         $this->assertSame($libro, $this->sqliteObjectsOf(self::AUDIT), 'el libro cambió en SQLite');
         if ($this->isPostgres()) {
             $this->assertSame(['draft', 'version', 'rule', 'system'], $this->checkValues('usae_subject_type_chk', self::AUDIT));
@@ -903,7 +903,7 @@
             $this->assertSame($this->isPostgres() ? $nombres : array_values(array_filter($nombres, static fn (string $n): bool => ! str_ends_with($n, '_truncate'))),
                 $this->triggersOn($tabla), $tabla);
         }
-        $this->assertSame(['uscl_one_active', 'uscn_one_live', 'usv_one_canary', 'usv_one_published'], $this->studioPartialIndexes());
+        $this->assertSame(['uscl_one_active', 'uscn_one_live', 'usv_one_canary', 'usv_one_published', 'usva_one_canary', 'usva_one_rest'], $this->studioPartialIndexes());
         if ($this->isPostgres()) {
             foreach (self::PG_CHECKS as $tabla => $nombres) {
                 $this->assertSame($nombres, $this->checksOn($tabla), $tabla);
--- a/backend/tests/Feature/UltronStudio/UltronStudioHardeningH7Test.php
+++ b/backend/tests/Feature/UltronStudio/UltronStudioHardeningH7Test.php
@@ -70,17 +70,17 @@
     private const SEQ_MESSAGE = 'ultron_studio: seq empieza en 1 (INSERT en ultron_studio_publications)';
 
     /** Recuentos de todo el Studio (tablas `ultron_studio_%`). */
-    private const PG_TRIGGERS_H6 = 43;
+    private const PG_TRIGGERS_H6 = 67;   // con el H8 (+24)
 
-    private const PG_TRIGGERS_H7 = 44;   // + uspub_chain_next
+    private const PG_TRIGGERS_H7 = 68;   // + uspub_chain_next
 
-    private const PG_CHECKS_H6 = 49;
+    private const PG_CHECKS_H6 = 83;     // con el H8 (+34)
 
-    private const PG_CHECKS_H7 = 50;     // + uspub_seq_chk
+    private const PG_CHECKS_H7 = 84;     // + uspub_seq_chk
 
-    private const SQLITE_TRIGGERS_H6 = 30;
+    private const SQLITE_TRIGGERS_H6 = 53; // con el H8 (+23)
 
-    private const SQLITE_TRIGGERS_H7 = 32; // + uspub_chain_next y uspub_seq_min
+    private const SQLITE_TRIGGERS_H7 = 55; // + uspub_chain_next y uspub_seq_min
 
     /** md5 de `pg_get_functiondef` de la función de F: el mismo desde el H5. */
     private const FORBID_MD5 = '4ad748b5f17f09b398f7f641c197d7ee';
@@ -109,8 +109,8 @@
             $this->assertSame(['uspub_chain_next', 'uspub_forbid_delete', 'uspub_forbid_truncate', 'uspub_forbid_update'], $this->triggersOn(self::TABLE));
             $this->assertSame(['uspub_action_chk', 'uspub_engine_contract_chk', 'uspub_reason_chk', 'uspub_seq_chk'], $this->checksOn(self::TABLE));
             $this->assertSame('CHECK ((seq >= 1))', DB::scalar("SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'uspub_seq_chk'"));
-            $this->assertSame(self::PG_TRIGGERS_H7, $this->studioTriggerCount(), 'F 11 + laboratorio 16 + H5 3 + H6 13 + H7 1');
-            $this->assertSame(self::PG_CHECKS_H7, $this->studioCheckCount(), 'F 11 + laboratorio 13 + H5 3 + H6 22 + H7 1');
+            $this->assertSame(self::PG_TRIGGERS_H7, $this->studioTriggerCount(), 'F 11 + laboratorio 16 + H5 3 + H6 13 + H7 1 + H8 24');
+            $this->assertSame(self::PG_CHECKS_H7, $this->studioCheckCount(), 'F 11 + laboratorio 13 + H5 3 + H6 22 + H7 1 + H8 34');
 
             // El disparador de la cadena: BEFORE INSERT, por fila, con su función; los tres de H5 siguen con la de F.
             $def = (string) DB::scalar("SELECT pg_get_triggerdef(oid) FROM pg_trigger WHERE tgname = 'uspub_chain_next'");
@@ -133,7 +133,7 @@
         }
 
         $this->assertSame(['uspub_chain_next', 'uspub_forbid_delete', 'uspub_forbid_update', 'uspub_seq_min'], $this->triggersOn(self::TABLE));
-        $this->assertSame(self::SQLITE_TRIGGERS_H7, $this->studioTriggerCount(), 'F 7 + laboratorio 11 + H5 2 + H6 10 + H7 2');
+        $this->assertSame(self::SQLITE_TRIGGERS_H7, $this->studioTriggerCount(), 'F 7 + laboratorio 11 + H5 2 + H6 10 + H7 2 + H8 23');
     }
 
     // ── La cadena: la siguiente, encadenada ─────────────────────────────────
--- a/backend/tests/Feature/UltronStudio/UltronStudioPublicationSchemaTest.php
+++ b/backend/tests/Feature/UltronStudio/UltronStudioPublicationSchemaTest.php
@@ -174,9 +174,9 @@
     {
         if ($this->isPostgres()) {
             $this->assertSame(['uspub_chain_next', 'uspub_forbid_delete', 'uspub_forbid_truncate', 'uspub_forbid_update'], $this->triggersOn(self::TABLE));
-            $this->assertSame(44, $this->studioTriggerCount(), 'F 11 + laboratorio 16 + H5 3 + H6 13 + H7 1');
+            $this->assertSame(68, $this->studioTriggerCount(), 'F 11 + laboratorio 16 + H5 3 + H6 13 + H7 1 + H8 24');
             $this->assertSame(['uspub_action_chk', 'uspub_engine_contract_chk', 'uspub_reason_chk', 'uspub_seq_chk'], $this->checksOn(self::TABLE));
-            $this->assertSame(50, $this->studioCheckCount(), 'F 11 + laboratorio 13 + H5 3 + H6 22 + H7 1 (usv_status_chk y usae_subject_type_chk se sustituyen, no se suman)');
+            $this->assertSame(84, $this->studioCheckCount(), 'F 11 + laboratorio 13 + H5 3 + H6 22 + H7 1 + H8 34 (usv_status_chk y usae_subject_type_chk se sustituyen, no se suman)');
             // Los disparadores usan la función de F; H5 no crea otra ni una sobrecarga.
             $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS n FROM pg_proc WHERE proname = 'ultron_studio_forbid'")->n);
             $this->assertSame(3, (int) DB::selectOne(
@@ -186,7 +186,7 @@
         }
 
         $this->assertSame(['uspub_chain_next', 'uspub_forbid_delete', 'uspub_forbid_update', 'uspub_seq_min'], $this->triggersOn(self::TABLE));
-        $this->assertSame(32, $this->studioTriggerCount(), 'F 7 + laboratorio 11 + H5 2 + H6 10 + H7 2');
+        $this->assertSame(55, $this->studioTriggerCount(), 'F 7 + laboratorio 11 + H5 2 + H6 10 + H7 2 + H8 23');
     }
 
     // ── La cadena es de solo inserción ──────────────────────────────────────
@@ -363,13 +363,13 @@
         $this->assertSame([], $this->partialIndexesOnVersions());
         if ($this->isPostgres()) {
             $this->assertSame(['DRAFT', 'VALIDATED', 'REVIEW_REQUIRED', 'APPROVED', 'REJECTED'], $this->statusCheckValues());
-            $this->assertSame(40, $this->studioTriggerCount());
-            $this->assertSame(46, $this->studioCheckCount());
+            $this->assertSame(64, $this->studioTriggerCount());   // con el H8 (+24)
+            $this->assertSame(80, $this->studioCheckCount());     // con el H8 (+34)
             $this->assertRejected(fn () => $this->insertVersion('CANARY'), '23514', 'usv_status_chk');
             // La función es de F: down() no la toca.
             $this->assertSame(1, (int) DB::selectOne("SELECT count(*) AS n FROM pg_proc WHERE proname = 'ultron_studio_forbid'")->n);
         } else {
-            $this->assertSame(28, $this->studioTriggerCount());
+            $this->assertSame(51, $this->studioTriggerCount());   // con el H8 (+23)
         }
         $this->assertSame(4, DB::table('ultron_studio_versions')->count());
 
@@ -377,9 +377,9 @@
         $this->migration(self::GUARDS)->up();
 
         $this->assertH5SchemaPresent();
-        $this->assertSame($this->isPostgres() ? 43 : 30, $this->studioTriggerCount());
+        $this->assertSame($this->isPostgres() ? 67 : 53, $this->studioTriggerCount());   // con el H8
         if ($this->isPostgres()) {
-            $this->assertSame(49, $this->studioCheckCount());
+            $this->assertSame(83, $this->studioCheckCount());
         }
     }
```

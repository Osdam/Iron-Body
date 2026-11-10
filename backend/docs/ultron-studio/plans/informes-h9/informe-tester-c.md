# Informe TEST9-c · pruebas de cobertura de §13.1 que faltaban (Hito 9, REV9 M-3)

**Paquete:** TEST9-c (`tester`). **Encargo:** cerrar los huecos de M-3 de `informe-reviewer.md` con pruebas NUEVAS en `tests/Feature/Outreach/Run` (#23/#25 en la etapa 6, #6 y #7 de punta a punta, los umbrales del monitor de #18, #13 a nivel de ejecución) y corregir la tabla de cobertura de `informe-run.md` §0. **Contrato:** `ULTRON_STUDIO_H9_CONTRACT.md` (§13.1, §13.2, §13.3, L9-55, L9-57, L9-58, L9-63 y, al cierre, L9-65). **Árbol:** `_worktrees/ultron-h8/Iron-Body/backend` (rama `feat/ultron-studio-h8-voice`, H9 integrado, **sin commit**). **Fecha:** 10-11-2026 (UTC). **Sin red, sin producción, sin tocar código de la aplicación ni `OutreachIsolationTest.php`.**

## 0. Resumen en cifras

| Qué | Cifra |
|---|---|
| Ficheros nuevos | 8: `tests/Feature/Outreach/Run/H9PauseCancelTest.php`, `H9RollbackTest.php`, `H9LeadFollowupTest.php`, `H9EventSegmentTest.php`, `H9MonitorThresholdsTest.php`, `H9IdempotencyRaceTest.php`, `H9ChainOrphansTest.php` y `Support/h13-worker.php` (el trabajador del arnés; no es una prueba) |
| Pruebas nuevas | **24** (23 en SQLite + 1 solo PG) · 65 + 72 + 107 + 67 + 36 + 94 + 140 = **581 aserciones** |
| SQLite, una a una | `H9PauseCancelTest` 3/72 · `H9RollbackTest` 5/107 · `H9LeadFollowupTest` 4/67 · `H9EventSegmentTest` 3/36 · `H9MonitorThresholdsTest` 3/94 · `H9ChainOrphansTest` 5/140 · `H9IdempotencyRaceTest` omitida (solo PG) |
| PostgreSQL 14 (`UNIT_DB=ultron_studio_h9_test9c`) | la carrera: **1/65** (dos procesos reales del planificador, dos vueltas) · `H9RollbackTest` 5/107 tras M-4 · `H9ChainOrphansTest` 5/139 (una aserción menos: la del disparador de SQLite) · las demás, por el directorio completo (fila siguiente) |
| `tests/Feature/Outreach/Run` entero (las 45 de B9-run + las 24) | SQLite **69 pruebas, 1 168 aserciones, 2 omitidas** (las dos carreras) · PG **69 pruebas, 1 261 aserciones, 0 omitidas, 0 fallos** (con L9-65, L9-66, L9-69 y L9-72 y la m-2 de `AssistedQueue`) |
| Números de §13.1 que pasan a tener prueba en la ejecución | #6, #7 (y `valuation.outdated`), #13 (dos procesos), #18 (los tres umbrales), #23, #25; además el borde §13.3 «una reversión durante `dispatching`», la regla de L9-69 y L9-72 (firma rota, reversión falsificada, contenido ilegible, promoción; §1.7) y T1 en la ejecución (una entrada que no verifica niega y espera, no cancela) |
| Defectos del código encontrados | **1 (D-1)**: una inscripción `waiting` no volvía a tocar nunca. Reproducido con dos pruebas rojas, avisado al lead por mensaje, **corregido por el lead** (L9-65) sin tocarlo yo; las dos pruebas son ahora su regresión y están verdes en los dos motores |
| Ficheros ajenos tocados | `docs/ultron-studio/plans/informes-h9/informe-run.md` (solo §0: la fila de cobertura, que era inexacta, y la tabla nueva §0.1). Ningún fichero de `app/**`, ningún fichero de prueba existente |
| Pint | En verde en los 7 ficheros |
| Red | Cero: `Http::preventStrayRequests()` en las 6 suites; el trabajador del arnés no sale de la base |

## 1. Qué prueba cada fichero (y qué fila de la tabla `waitsForRelease`/`effect` de informe-run.md §2.8 comprueba)

### 1.1 `H9PauseCancelTest` · #23, el lado de la ejecución (3 pruebas)

| Prueba | Qué fija |
|---|---|
| `test_23_pausar_cancela_el_trabajo_ya_reclamado_strategy_paused_no_toca_lo_enviado_y_la_inscripcion_espera_hasta_la_reanudacion` | Dos socios planificados en sombra; sale uno (`simulated`); `pauseStrategy()`. La pausa NO es un freno (`openScopes()` vacío). La pasada siguiente **reclama** el pendiente (6.2 no mira la cadena) y 6.3 lo cancela `strategy_paused` con decisión `cancel` **N5**, traza como lista y `gate.recheck = dispatch`; sin llamar al canal. **La inscripción NO se cancela**: `active`, `next_due_at = +15 min`, sin resultado (fila «Cadena: pausa» de §2.8). Lo enviado queda intacto (`simulated`, `not_attempted`, `touches 1`). Mientras dura la pausa: `planDue` → `waiting` (+15 min, sin decisión), el despachador no reclama, el barrido no inscribe a nadie nuevo. Con una entrada `resume` firmada: se planifica **otro intento (fila nueva, `attempt 2`)**, el cancelado no vuelve, y sale |
| `test_23_en_vivo_la_cancelacion_por_pausa_libera_las_tres_parcelas_de_la_reserva_y_la_inscripcion_espera` | En vivo: `budget_released_at` se fija y las tres parcelas (`channel:assisted`, `strategy:<uuid>`, `phone:<ref>`) vuelven a 0; la inscripción espera, `touches 0` |
| `test_23_en_la_planificacion_la_pausa_y_el_freno_de_la_estrategia_hacen_esperar_sin_cancelar` | Sin trabajo todavía: pausada → `waiting` (+15 min, ni decisión ni trabajo), el barrido no barre; reanudada pero con el freno de la estrategia (N2, `waitsForRelease`) → lo mismo en su ámbito y el barrido la salta (`outreach_brake_strategy`) |

### 1.2 `H9RollbackTest` · #25, el lado de la ejecución (5 pruebas)

| Prueba | Qué fija |
|---|---|
| `test_25_revertir_cancela_el_trabajo_reclamado_strategy_moved_cierra_su_inscripcion_no_toca_lo_enviado_y_la_cadena_solo_crece` | `rollbackStrategy()`: la cadena crece una entrada (`seq + 1`), la cabeza anterior conserva su `effective_hash`, la versión queda `ROLLED_BACK` y `liveVersion()` es null. La pasada siguiente reclama y cancela `strategy_moved` (decisión `cancel` N5); **la inscripción se cierra** `canceled`/`strategy_moved`, `ended_at` fijado, `next_due_at` nulo, sin resultado (fila «Cadena: versión fuera»). Lo ENVIADO (el trabajo `simulated` y su `not_attempted`) no se toca; su inscripción, sin trabajo vivo y esperando la ventana (`next_due_at` nulo), la cierra la conciliación `canceled`/`strategy_moved` (`canceledByChain 1`, M-4/L9-66), con `touches 1` intacto. Después: `due 0`, barrido sin estrategias, nada que reclamar, conciliación idempotente |
| `test_25_la_conciliacion_cancela_las_inscripciones_abiertas_sin_trabajo_vivo_de_la_version_revertida_y_no_las_de_una_pausada` | A salió (sin trabajo vivo, `next_due_at` nulo), B tiene su trabajo planificado, D se inscribió y no se planificó. **Pausada**: `canceledByChain 0`, las tres siguen `active`. **Revertida**: la conciliación cierra A y D (`canceled`/`strategy_moved`, `ended_at`, sin resultado) y no toca B (trabajo vivo) ni lo enviado de A; el despachador reclama y cancela el trabajo de B (`strategy_moved`) y cierra su inscripción; otra conciliación → 0; nada abierto queda de esa versión |
| `test_25_en_la_planificacion_una_inscripcion_vencida_de_la_version_revertida_se_cancela_strategy_moved` | Sin trabajo: `planDue` → `canceled 1`, inscripción `canceled`/`strategy_moved`, sin decisión ni resultado; no vuelve a tocar |
| `test_25_la_version_restaurada_arranca_con_su_sello_nuevo_y_no_repite_en_el_ciclo_la_ocurrencia_ya_inscrita` | Tras cancelar a A y B, se publica **otra versión de la misma estrategia** (entrada firmada nueva). El barrido: A y B `already_enrolled` (una inscripción por ocurrencia y ciclo, L9-1, aunque esté cancelada); la persona nueva entra con `strategy_version_id` = v2 y `publication_seq` = la seq nueva (el cursor nuevo), se planifica y sale |
| `test_13_3_una_reversion_durante_dispatching_no_toca_el_trabajo_en_curso_y_lo_cierra_la_conciliacion` | Borde de §13.3: un trabajo `dispatching` sobrevive a la reversión (no se reinterpreta: `process()` → `not_claimed`, sin decisión `cancel`); a los 10 min la conciliación lo deja `ambiguous` (`stale`) con su incidente, sin liberar la reserva, y en la misma pasada, ya sin trabajo vivo, cierra la inscripción `canceled`/`strategy_moved` (`canceledByChain 1`) |

### 1.3 `H9LeadFollowupTest` · #6 de punta a punta (4 pruebas)

| Prueba | Qué fija |
|---|---|
| `test_6_el_prospecto_con_seguimiento_vencido_y_autorizacion_expresa_se_inscribe_se_planifica_y_sale_en_sombra` | Cuatro prospectos SIN ficha de socio; solo el mensaje/llamada `pending` cuya hora llegó es candidato (ni el futuro, ni el hecho, ni la tarea interna). Inscripción con `lead_id`, `member_id` nulo, `cycle_key = fu:<seguimiento>`; la traza del plan lleva `consent.whatsapp = granted` y N1 `pass`; sale en sombra (`not_attempted`); un segundo seguimiento del mismo prospecto es otra ocurrencia pero la inscripción abierta manda; el «futuro» entra cuando llega su hora |
| `test_6_sin_autorizacion_expresa_n1_niega_no_consent_for_purpose_channel_y_con_denied_consent_denied_sin_ningun_contacto` | `pending` y sin registro → N1 `no_consent_for_purpose_channel`; `denied` → `consent_denied`. El prefiltro NO mira el consentimiento (los tres se inscriben), la escalera sí: `denied 3`, **cero trabajos**, resultado `blocked` con su `reason_code` y `next_action = wait`, inscripciones `waiting` con `next_due_at = +24 h` |
| `test_6_una_autorizacion_que_llega_tarde_se_aprovecha_en_la_siguiente_mirada_a_las_24_h` | **Regresión de D-1** (§2.1): la autorización llega, a las 25 h `planDue` la planifica |
| `test_6_la_llamada_pedida_por_el_personal_se_programa_a_su_hora` | **Regresión de D-1**, segundo síntoma (N7): `callback_requested` del personal con `callbackAt` el viernes → `waiting` hasta esa hora → a esa hora se planifica la llamada |

### 1.4 `H9EventSegmentTest` · #7 de punta a punta (3 pruebas)

| Prueba | Qué fija |
|---|---|
| `test_7_la_invitacion_a_una_clase_con_cupo_inscribe_solo_al_segmento_elegible_y_nunca_a_toda_la_base` | Ocho personas: la fuente son las ocurrencias con cupo (el Spinning del 18-11; el Yoga lleno y el Pilates inactivo no); el prefiltro deja fuera a quien ya tiene esa reserva, al congelado, al vencido, al socio sin plan y al prospecto; la puerta deja fuera a quien tiene reserva próxima en otra clase (`not_in_audience`) y a quien tiene valoración próxima (`excluded`). **Una** inscripción, `cycle_key = cls:<clase>:2026-11-18` (keyVersion 2); se planifica y sale en sombra; la misma ocurrencia otra vez es `already_enrolled` |
| `test_7_quien_reserva_entre_planificar_y_despachar_sale_converted_sin_contacto_y_deja_de_ser_candidato` | El éxito (`class.reserved_next_7d = true`) en la reevaluación de la etapa 6 gana sobre `context_changed` (orden N6): `converted`, trabajo `canceled`/`success_reached`, sin canal; y deja de ser candidato |
| `test_7_valuation_outdated_inscribe_a_quien_tiene_la_valoracion_caducada_y_no_a_quien_tiene_una_proxima_o_ninguna` | Solo el de la valoración de hace 40 días (`val:<cita>`); ni el reciente, ni el que tiene cita próxima, ni el que nunca se valoró |

### 1.5 `H9MonitorThresholdsTest` · #18 «freno si supera el umbral» (P9-20), 3 pruebas

Todas con filas de la ejecución real: cinco socios, cinco trabajos asistidos en vivo con su reserva, la bandeja (`AssistedQueue`) para los resultados del personal y un canal roto para los fallos.

| Prueba | Qué fija |
|---|---|
| `test_18_opt_out_por_encima_del_umbral_en_24_h_echa_el_freno_de_la_estrategia_con_incidente_y_el_monitor_nunca_lo_suelta` | 4 salidos y 4 `opted_out` → nada (muestra 4 < 5). Sale el quinto: 4/5 = 800 ‰; con el umbral en 800 **no** abre (estricto), en 799 sí: freno `strategy`/`opt_out_spike`, `opened_by_type = system`, sin cuenta ni nota; incidente `outreach_brake` (fuente `outreach`, `high`) con `opted_out 4`, `started 5`, `permille_threshold 799` y el uuid del freno. El barrido salta la estrategia. Pasadas siguientes: no duplica (uno por alcance), la causa sigue marcada; a +25 h la causa sale de la ventana y el freno sigue; **nunca `released_at`**. Lo suelta Caro (una persona; el sistema no cuenta para los cuatro ojos) y, como la causa sigue, el monitor lo vuelve a echar (2 frenos, 1 abierto) |
| `test_18_fallos_del_canal_por_encima_del_umbral_echan_el_freno_del_canal_y_lo_planificado_de_ese_canal_espera` | 2 fallos (`failed_to_start`) → nada (muestra 2); 3 salen: 2/5 = 400 ‰; en 400 no abre, en 399 sí: freno `channel`/`assisted`/`failure_spike`, incidente con `failed 2`, `total 5`. Un asistido planificado de OTRA estrategia no se reclama (espera `planned`), el simulado no está frenado (`ladderBrakes`). Nunca se suelta |
| `test_18_el_gasto_reservado_por_encima_del_techo_que_la_configuracion_dice_ahora_echa_el_freno_del_canal` | 5 reservadas con techo 20 → nada; el techo baja a 4 (el contador nunca baja, §3.7 de informe-run) y un contador de WhatsApp con 5 USD reservados frente a 4 USD de presupuesto → dos frenos `budget_breach` (`whatsapp_template` y `assisted`, en ese orden) con sus cifras (`reserved_units 5`/`cap_units 4`; `reserved_micros 5 000 000`/`cap_micros 4 000 000`); los cinco planificados esperan sin perder su reserva; idempotente; nunca se suelta |

### 1.6 `H9IdempotencyRaceTest` + `Support/h13-worker.php` · #13 a nivel de ejecución (1 prueba, solo PG)

`OutreachRaceTest` **no cubría #13** (solo #16, la reserva); `OutreachConcurrencyTest` (DB9) lo cubre a nivel de BASE con filas a pelo. Aquí hay **dos procesos PHP reales** (`proc_open` de `h13-worker.php`, que arranca la aplicación contra la base de la prueba con la misma `APP_KEY`), que esperan en una **barrera** (`pg_advisory_lock` exclusivo de la prueba; los trabajadores piden el compartido) y salen a la vez cuando la prueba, tras ver en `pg_locks` a los DOS esperando, lo suelta. Cada uno ejecuta `OutreachPlanner::sweep()` + `planDue()` de verdad. Invariantes: una inscripción (`enrolled` 1 + `already_enrolled` 1 entre los dos), un trabajo (`planned` 1; el otro, `due 0` o `collisions 1`), decisiones `enroll` 1 y `plan` 1, dos pasadas `sweep` completadas, ninguna fallida, ningún teléfono en lo devuelto; la segunda vuelta de los dos no cambia nada (`already_enrolled`, `due 0`). Las filas que los trabajadores ven se confirman por la conexión de apoyo y se borran al final (solo ellas, disparadores apagados); la base queda a cero (comprobado con `psql` tras la corrida).

### 1.7 `H9ChainOrphansTest` · huérfana ≠ cadena que no verifica (REV9 M-5, Q-M4 y m-10; L9-69 y L9-72; 5 pruebas)

Una regla en los tres sitios (`LiveStrategy::orphanReason()`): la cadena que NO verifica no cancela nada; la que verifica con contenido ilegible cancela `catalog_incompatible`; la versión fuera del estado vivo de su parte cancela `strategy_moved`. Mundo común: A salió (sin trabajo vivo, espera la ventana), B tiene su trabajo `planned`, D está vencida sin trabajo.

| Prueba | Qué fija |
|---|---|
| `test_m5_con_la_firma_rota_nadie_cancela_la_vencida_espera_el_reclamado_se_cancela_chain_unreadable_y_al_volver_a_leerse_todo_sigue` | Una entrada con el ESTADO bien formado y la FIRMA rota (la de relleno de `appendPublication()` no sirve: sin `state` el puente omite la estrategia entera y saldría `strategy_moved`): el puente conserva la estrategia con `signature_mismatch`, `orphanReason()` null, `headVerified()` falso. Conciliador `canceledByChain 0` (las tres abiertas); planificador: D espera (+15 min, `waiting 1`, `canceled 0`), el barrido no inscribe; despachador: el trabajo de B se cancela `chain_unreadable` (decisión N5) y la inscripción ESPERA (`active`, +15 min); ninguna ocurrencia consumida. Con una entrada `resume` firmada de verdad la cadena vuelve a leerse: B (intento 2) y D se planifican y salen, `touches` 1/1/1, el barrido ve a las tres `already_enrolled` |
| `test_m10_una_reversion_falsificada_en_la_cabeza_deja_el_par_ausente_y_nadie_cancela_hasta_que_el_dueno_retira_la_fila` | L9-72: una entrada de REVERSIÓN FALSIFICADA en la cabeza (inserción cruda con estado «off» y firma inválida; la versión sigue `CANARY`): el par (versión, parte) queda AUSENTE y la estrategia entra en `unverifiedStrategyIds()`. Conciliador `canceledByChain 0`; planificador: la vencida espera (+15 min, `waiting`); despachador: el trabajo reclamado → `chain_unreadable` (N5) y la inscripción espera; ninguna ocurrencia consumida. El DUEÑO retira la fila (la aplicación no puede: `usspub_forbid_delete`; en PG con `session_replication_role = replica` dentro de la transacción, en SQLite soltando y recreando el disparador): la cabeza vuelve a verificar, el par reaparece, B (intento 2) y D se planifican y salen |
| `test_m5_una_rotacion_de_app_key_deja_toda_cadena_sin_verificar_y_nadie_cancela_hasta_que_vuelve_la_clave` | El escenario literal de M-5: `config('app.key')` rotada → toda la cadena `signature_mismatch`; conciliador 0, planificador `waiting`, despachador `chain_unreadable`, 3 inscripciones `active`; vuelve la clave → `runnable()`, 2 planificados, 2 `simulated` |
| `test_m5_con_la_cadena_verificando_y_el_contenido_ilegible_se_cancela_catalog_incompatible_en_los_tres_sitios` | Una versión cuyo JSON canónico nombra un hecho que el catálogo no tiene (huella correcta; `contentReason = catalog_incompatible`, `content === null`), publicada con entrada firmada: `chainReadable()` sí, `orphanReason() = catalog_incompatible`, no barre. Conciliador: las abiertas sin trabajo vivo → `canceled`/`catalog_incompatible` (2); planificador: la vencida → `canceled` con el mismo motivo; despachador: el trabajo reclamado → `canceled`/`catalog_incompatible` (N5) y su inscripción cerrada igual; idempotente |
| `test_q_m4_la_promocion_deja_huerfanas_las_inscripciones_de_la_parte_canario_sin_trabajo_vivo_y_las_de_la_parte_resto_siguen` | `promote` firmado (CANARY → PUBLISHED): la parte canario queda sin vida, la parte resto es `runnable()`. Una persona nueva entra por `rest` (con la `seq` nueva); A, B y D no vuelven a entrar (`already_enrolled 3`, L9-1). Conciliador: A y D (canario, sin trabajo vivo) → `canceled`/`strategy_moved`; B (trabajo vivo) y C (resto) siguen; lo enviado en canario no se toca. Después: C se planifica y sale; el despachador cancela el trabajo de B (`strategy_moved`) y cierra su inscripción; solo C queda abierta |

## 2. Hallazgos

### 2.1 D-1 · Defecto (corregido por el lead, L9-65): una inscripción `waiting` no volvía a tocar nunca

- **Dónde estaba:** `app/Services/Outreach/Run/OutreachStore.php:129` (`dueEnrollments()` filtraba `status = 'active'`) frente a `app/Services/Outreach/Core/FollowUpMachine.php:101` (toda transición `wait` deja `status = 'waiting'`) y `app/Services/Outreach/Run/FollowUpScheduler.php:84-97` (lo escribe con su `next_due_at`). Nada en `Run/**` reactivaba `waiting` (grep vacío); el conciliador solo las vencía al final de la ventana. Contradecía el PHPDoc de `FollowUpScheduler` (19-22), el de `dueEnrollments()` e `informe-run.md` §3.4.
- **Alcance:** `blocked` con `on.blocked = wait` (toda negativa permanente N1/N3/N4/N5: la autorización tardía no se aprovechaba), `callback_requested` (la llamada pedida por el personal nunca se programaba: T22/N7 muerto en la ejecución) y `no_answer` con `wait`. El camino N2 (`waitsForRelease`) no estaba afectado (deja `active`).
- **Reproducción:** las dos pruebas de regresión de `H9LeadFollowupTest` (`test_6_una_autorizacion_que_llega_tarde…`, `test_6_la_llamada_pedida_por_el_personal…`; nacieron como `test_6_d1_*` y se renombraron a petición del lead al pasar a verde) fallaron en rojo sobre el código del 09-11 en SQLite y en PG (`planDue()` → `due: 0` con la inscripción `waiting` y su `next_due_at` ya pasado). Avisado al lead por mensaje (04:2x UTC); no toqué el código.
- **Corrección del lead (L9-65):** `dueEnrollments()` por `OutreachTables::ENROLLMENT_OPEN_STATUSES` y, al crear el trabajo, `OutreachPlanner` devuelve `waiting → active`. Las dos pruebas pasaron a verde sin cambiar sus aserciones (solo su nombre y su PHPDoc, que decían «d1»/«ROJA hasta que…»). Directorio `Run` 69/69 en los dos motores.

### 2.2 H-1 · Menor: `OutreachMonitor::run()` sin argumento usa el reloj real

`run(?DateTimeInterface $now = null)` hace `new DateTimeImmutable('now')` cuando no recibe nada: ignora `Carbon::setTestNow()`. En producción es lo correcto (`outreach:monitor`); en pruebas, el día del contador (`budget_breach`) y la ventana de 24 h se calculan con la fecha real si no se le pasa `now()`. Mis pruebas le pasan `now()`; `OutreachStopAndGateTest` no lo necesita porque no depende del día. Anotado también por el lead en L9-65. No es defecto; conviene que lo sepa quien escriba pruebas del monitor.

### 2.3 Observaciones de comportamiento (no defectos; para que el lead las confirme)

- **O-1 · Tras una reversión, la ocurrencia ya inscrita no se repite en el ciclo.** `oenr_occurrence_unique` es por estrategia × persona × ciclo × modo (L9-1), y la inscripción cancelada por `strategy_moved` cuenta: si la misma estrategia vuelve a publicarse (otra versión) dentro del mismo ciclo (`exp:<fecha>`, `fu:<id>`, `cls:<clase>:<fecha>`…), esas personas **no** se vuelven a inscribir (`already_enrolled`); solo entran las nuevas. Es coherente con «una inscripción por ocurrencia», pero el propietario debe saber que revertir y restaurar en el mismo ciclo deja fuera a quien ya estaba inscrito. Fijado en `H9RollbackTest::test_25_la_version_restaurada…`.
- **O-2 · (Resuelta por el lead: REV9 M-4, L9-66.)** Al principio la cancelación de inscripciones era perezosa: revertir solo cerraba lo que TOCABA (trabajos reclamados en la etapa 6, inscripciones vencidas en la etapa 3), y una inscripción activa cuyo trabajo ya salió y sin `next_due_at` seguía `active` bajo la versión revertida hasta la ventana. Ahora `OutreachReconciler::cancelOrphanedByChain()` (tras `expirePlanned`, antes de `expireWindows`) cierra `canceled` (`strategy_moved` / `catalog_incompatible`) toda inscripción abierta sin trabajo vivo cuya versión/parte ya no está viva o no es `runnable()`; las que tienen trabajo vivo las sigue cerrando el despachador al reclamar, y una versión solo pausada no se toca. Fijado en `H9RollbackTest` (la prueba nueva de la conciliación, la de lo enviado y la del `dispatching`).
- **O-3 · Pausa + monitor antes que el despachador.** Con el monitor encendido (cada 10 min) y una pausa, la deriva abre el freno de la estrategia antes de que el despachador pase (cada minuto no, pero puede coincidir): entonces el trabajo **no se reclama** (espera `planned`) en vez de cancelarse `strategy_paused`; al soltar el freno, si sigue pausada, se cancela. Las dos vías acaban en «nada sale»; la diferencia es qué fila queda. Mis pruebas de pausa corren sin monitor para fijar la vía de la cadena.
- **O-4 · «Archivar» en la ejecución es `strategy_moved`,** pero la base no deja archivar con versiones vivas (`usst_archive_without_live_versions`), así que el texto de #23 («pausar o archivar») se reduce en la práctica a pausar, o a revertir y luego archivar (#25). No hay prueba de archivar con trabajos vivos porque no puede ocurrir.

## 3. Qué verifiqué, con comandos

```
php -d memory_limit=2G vendor/bin/phpunit --no-logging tests/Feature/Outreach/Run/H9PauseCancelTest.php        → OK (3 tests, 72 assertions)
php -d memory_limit=2G vendor/bin/phpunit --no-logging tests/Feature/Outreach/Run/H9RollbackTest.php           → OK (5 tests, 107 assertions)   # tras M-4 (L9-66); antes 4/71
php -d memory_limit=2G vendor/bin/phpunit --no-logging tests/Feature/Outreach/Run/H9LeadFollowupTest.php       → sobre el código del 09-11: FAILURES (4 tests, 62 assertions, 2 failures: las dos de D-1)
                                                                                                               → con L9-65: OK (4 tests, 67 assertions)
php -d memory_limit=2G vendor/bin/phpunit --no-logging tests/Feature/Outreach/Run/H9EventSegmentTest.php       → OK (3 tests, 36 assertions)
php -d memory_limit=2G vendor/bin/phpunit --no-logging tests/Feature/Outreach/Run/H9MonitorThresholdsTest.php  → OK (3 tests, 94 assertions)
export PGGSSENCMODE=disable
UNIT_DB=ultron_studio_h9_test9c perl -e 'alarm 600; exec @ARGV' docs/ultron-studio/ops/pgunit.sh --no-logging tests/Feature/Outreach/Run/H9IdempotencyRaceTest.php
                                                                                                               → OK (1 test, 65 assertions)   # PG, dos procesos
UNIT_DB=ultron_studio_h9_test9c … pgunit.sh --no-logging tests/Feature/Outreach/Run/H9RollbackTest.php         → OK (5 tests, 107 assertions)   # PG, tras M-4
php -d memory_limit=2G vendor/bin/phpunit --no-logging tests/Feature/Outreach/Run/H9ChainOrphansTest.php       → OK (5 tests, 140 assertions)   # L9-69 y L9-72
UNIT_DB=ultron_studio_h9_test9c … pgunit.sh --no-logging tests/Feature/Outreach/Run/H9ChainOrphansTest.php     → OK (5 tests, 139 assertions)   # PG (la retirada de la fila con session_replication_role = replica)
UNIT_DB=ultron_studio_h9_test9c … pgunit.sh --no-logging <las cinco suites>                                     → sobre el código del 09-11: 17 tests, 335 assertions, 2 failures (D-1)
php -d memory_limit=2G vendor/bin/phpunit --no-logging tests/Feature/Outreach/Run                              → OK, 69 tests, 1168 assertions, 2 skipped (final, con L9-65/66/69/72)
UNIT_DB=ultron_studio_h9_test9c … pgunit.sh --no-logging tests/Feature/Outreach/Run                            → OK (69 tests, 1261 assertions) (final, con L9-65/66/69/72)
psql … -d ultron_studio_h9_test9c: outreach_* / ultron_studio_strategy_* / personas de la carrera / cerrojos consultivos → 0 filas (la limpieza del arnés funciona)
vendor/bin/pint <los 7 ficheros>                                                                               → passed
```

Lo que la puerta PG cazó y SQLite no: nada nuevo en estas suites (las dos rojas de D-1 lo eran en los dos motores); la carrera #13 solo existe en PG.

## 4. Qué NO verifiqué

- **Mutantes sobre código de la aplicación**: prohibido tocar `app/**` mientras otros agentes trabajan en el mismo árbol; la sensibilidad de las pruebas nuevas está demostrada por D-1 (dos rojas sobre el código defectuoso, verdes con la corrección) y por la barrera del arnés (la prueba falla si los dos trabajadores no están esperando antes de soltarla).
- **#26 (E2E del CRM)** y la ampliación de `OutreachIsolationTest::exercise()`: TEST9-b.
- **La carrera con más de dos trabajadores** o con dos ESTRATEGIAS a la vez sobre la misma persona (el cerrojo por persona entre estrategias): lo cubre DB9 a nivel de base; el arnés admite ampliarlo (`spawnWorker` por etiqueta).
- **El monitor con el reloj real** (`outreach:monitor` por consola): solo por lectura (H-1).
- **Producción:** nada.

## 5. El arnés de la carrera: cómo se corre y sus trampas

- Base desechable propia: `UNIT_DB=ultron_studio_h9_test9c` (`pgunit.sh` exige el prefijo `ultron_studio_`); `export PGGSSENCMODE=disable` (libpq en macOS); lanzar la puerta con `perl -e 'alarm 600; exec @ARGV'` (en macOS no hay `timeout`).
- El trabajador (`Support/h13-worker.php`) recibe por `proc_open` el entorno de la suite con la base de la prueba, **la misma `APP_KEY`** (si no, la cadena firmada no verifica y el barrido no ve la estrategia), `LOG_CHANNEL=null`, las banderas (`COMMERCIAL_AUTOMATION_*`, `COMMERCIAL_EVENTS_ENABLED`) y el reloj como argumento (`Carbon::setTestNow` no cruza procesos).
- **Todo lo que los trabajadores tienen que ver se confirma por la conexión de apoyo** (`$this->fixtureConnection`): las filas del gobierno van por `RunWorld::liveStrategy()` (que ya usa `$this->db()`), pero las personas de `FactsWorld` van por la conexión por defecto (dentro de la transacción de `RefreshDatabase`) y los trabajadores no las verían: por eso la prueba siembra las suyas (`committedQualifyingMember()`). **Nada confirmado se toca desde la conexión de la prueba** (memoria del proyecto: un UPDATE suyo dejaría la fila bloqueada para el borrado del `tearDown`).
- La barrera: `pg_advisory_lock(913013)` exclusivo en la conexión de apoyo ANTES de lanzar; los trabajadores piden `pg_advisory_lock_shared`; la prueba espera a ver `count(*) = 2` en `pg_locks … NOT granted` y suelta; si no llegan los dos, falla con el stderr de ambos.
- Limpieza: `session_replication_role = replica`, las tablas del H9 enteras (el `setUp` exige que estén vacías), las identidades nuevas y las personas sembradas por id/huella; `pg_advisory_unlock_all()`; `DB::purge()`.

## 6. Estado al cierre

- Las 24 pruebas nuevas en verde en SQLite (23 + 1 omitida) y en PG (24); `tests/Feature/Outreach/Run` 69/69 en los dos motores (SQLite 1 168 aserciones, PG 1 261). Tras los cambios del lead (L9-65 D-1, L9-66 M-4, L9-69 M-5/Q-M4, L9-72 m-10, m-2 en `AssistedQueue`) la base de apoyo quedó a cero.
- `informe-run.md` §0: la fila «Pruebas de §13.1 mías cubiertas» (inexacta: afirmaba #23 en la etapa 6 y daba por cubiertos #6, #7, #13 y el umbral de #18) queda sustituida por una nota y por la tabla **§0.1** con el fichero y el método de cada número de §13.1 (y qué parte cubre cada uno: ejecución, gobierno, pura, base). Nada más de ese informe se tocó.
- O-1, O-3 y O-4 confirmadas por el lead como diseño; O-2 superada por M-4 (L9-66). Queda del lead decidir si la carrera #13 entra en el banco de OPS9 (necesita `proc_open`, `pgsql` y la base desechable). REV9 revisa L9-65, L9-66, L9-69 y L9-72 en su tercera mirada.

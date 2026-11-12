# Informe de backend · Las cuatro condiciones de security para abrir el asistido en vivo (I-1, I-2, I-3, I-4)

**12-11-2026 (16:04–17:30 UTC) · `backend` · rama `feat/ultron-studio-h9`, árbol `_worktrees/ultron-h8/Iron-Body/backend`.** Empecé sobre HEAD `b90f84c4`. Durante la sesión el lead subió `240e2678` (solo documentos: L9-116 y enmiendas del contrato), que no toca ninguno de mis ficheros [C: `git diff --name-only b90f84c4 HEAD`]. Sin commit ni push. No he tocado producción, el CRM, `config/*`, `routes/*`, migraciones, el contrato, otros documentos, `tests/Feature/Voice/E2E/` ni las zonas prohibidas del encargo.

Marcas: **[C]** comprobado ejecutando · **[L]** leído en el código, sin ejecutar · **[S]** suposición.

## 0 · Decisiones de diseño (escritas antes de codificar)

### 0.1 · Un solo cálculo de motivos por fila

Una función, `withholdReasons()`, da para cada trabajo **un** motivo o `null`, con esta prioridad (la del encargo): `brake` → `complaint_recent` → `complaint_unknown` → `expired`.

| Motivo | Regla | Fuente (la misma que la ejecución) |
|---|---|---|
| `brake` | PARADA GLOBAL, freno del canal del trabajo (`assisted`) o freno de la estrategia del trabajo | `OutreachStop::openScopes()` (la lectura de N2 en el despachador, una consulta) y `OutreachStop::ladderBrakes()` (la misma traducción que usa la escalera). No escribo otra lectura |
| `complaint_recent` / `complaint_unknown` | La queja da `true` / `null` | `withheldByComplaint()` de `155e7adc`, sin cambios: `LadderInputBuilder::complaintRecently()` sobre `CoreProjection::snapshot($s)->events` de `BatchSnapshotBuilder`, el lector del despachador. `human_requested` sigue fuera (L9-66) |
| `expired` | `expires_at <= ahora` | El mismo borde que el despachador (`OutreachDispatcher.php:258`: `expires_at <= ahora` → vencido; `:177`: reclama solo `expires_at > ahora`) |

### 0.2 · Qué pasa con cada fila

| Fila | Con motivo | Sin motivo |
|---|---|---|
| **Sin reclamar** (vistas `all` y `unclaimed`) | No se ofrece; `claim()` → 409 `not_in_queue` | Se ofrece, como hoy (`person`/`script` en null: no es de nadie) |
| **Reclamada por quien mira** (vistas `all` y `mine`; repetición de `claim()`) | Se queda, con `person: null`, `script: null` y `contactWithheld: <motivo>` | Como hoy, con `contactWithheld: null` |
| **Reclamada por otra persona** (vista `all`) | Como hoy: `person`/`script` en null porque no es suya, y `contactWithheld: null` | Ídem |

- **`contactWithheld` solo tiene valor en las filas que reclamó quien mira.** A quien no la reclamó no se le cuenta que esa persona se quejó (el mismo criterio que m-1 de security para el 409); tampoco tendría `person` ni `script` aunque no hubiera motivo. Así no hay que evaluar quejas de filas ajenas (coste 0).
- **La clave `contactWithheld` va siempre**, en cada fila de E11 y en `data` de E12, con `null` cuando no hay retención. Es aditivo: el CRM de hoy ignora las claves que no conoce (`readAssistedJob`, `commercial-automation-parse.ts:1332-1355` [L]).
- **`recordOutcome()` no cambia** (requisito 3): quien reclamó registra su resultado con cualquier motivo.
- **No se escribe nada.** `queue()` es un GET y `claim()` solo escribe al reclamar de verdad, como hoy.

### 0.3 · I-2, el vencimiento (decisión del lead, que el propietario puede cambiar)

La política (horario de la Ley 2300, frecuencia, consentimiento del medio) se evaluó para el plazo del trabajo (`expires_at` = el hueco + 24 h, `OutreachPlanner::JOB_TTL_HOURS`, nunca más allá de la ventana de la inscripción). Pasado el plazo, no se contacta con esa decisión:
- sin reclamar y vencido: fuera de la bandeja y 409 `not_in_queue` al reclamar;
- reclamado y vencido: se queda para que su reclamante registre el resultado, con `contactWithheld: 'expired'`.

El trabajo y la inscripción no cambian: `started` es final y no hay transición a `expired` desde `started` (`OutreachTables::JOB_LIVE_STATUSES`, `ojob_closed_is_final`). La inscripción sigue hasta el resultado del personal o el fin de su ventana (`OutreachReconciler::expireWindows()`). Cerrar los `started` asistidos vencidos en el conciliador es otra decisión (escritura nueva) y queda fuera.

### 0.4 · I-3, los frenos, y qué pasa si no se pueden leer

- **Una lectura por petición:** `openScopes()` una vez en `queue()` (para todas las tandas) y una vez en `claim()`.
- **Falla cerrado como N2:** el despachador no captura el error de `openScopes()` (`OutreachDispatcher.php:129` y `:228`): la pasada falla y no reclama nada. Aquí, igual: la excepción sube, `queue()` no devuelve nada (el 500 lo pinta el gestor de Laravel; que no lleve SQL con `APP_DEBUG=false` es [S], no lo he ejecutado por HTTP) y `claim()` no llega al `UPDATE`. Se lee **antes** de leer filas y antes de reclamar o repetir. No hay `catch`: un fallo de base no se disfraza de bandeja vacía.
- **Los frenos de la escalera que no son del H9** (cerrojo de ULTRON, pausa del agente, freno de voz: `OperationalBrakes`) no entran: el encargo nombra global, canal y estrategia, y esos frenos son del agente automático, no del contacto humano. Lo dejo escrito como decisión del propietario (§6).

### 0.5 · I-4, la página que se llena y `meta.withheld`

- **Lo que se puede decidir en SQL va en SQL**, como en la reclamación del despachador (`OutreachDispatcher.php:171-185`: `expires_at > ahora`, `whereNotIn('j.channel', …)`, `whereNotIn('s.uuid', …)`): las filas **sin reclamar** vencidas o frenadas no se leen. Si se leyeran en PHP, con el orden `expires_at, id` las vencidas irían siempre en cabeza y agotarían cualquier tope, que es el mismo defecto de I-4. Las reclamadas sí se leen, para su reclamante.
- **La queja solo se puede decidir en PHP** (la foto): se lee por tandas de `MAX_PAGE` (50) filas, con cursor por `(expires_at, id)`, hasta llenar `limit`, hasta que se acaben las filas o hasta `MAX_SCAN` = 200 filas leídas (4 × `MAX_PAGE`, ≤ `BatchSnapshot::MAX_SUBJECTS` = 500). Una foto por tanda, nunca una por fila.
- **`meta.withheld`** (entero ≥ 0) = trabajos **sin reclamar** y **no vencidos** de esa vista que no se ofrecen: los frenados (un `COUNT` exacto, solo si hay algún freno abierto) más los retenidos por queja que se encontraron al llenar la página. Es una **cota inferior** en la parte de la queja si la página se llenó o se llegó al tope. Sin decir de quién: un número. En `mine` es siempre 0 (allí no se quita ninguna fila).
- **Los vencidos sin reclamar no cuentan como retenidos:** han salido de la bandeja por plazo, como un trabajo con resultado o con la inscripción cerrada. Si contaran, el número crecería durante toda la ventana de la inscripción (hasta 60 días) y el aviso del CRM no se apagaría nunca.
- **`queue()` sigue devolviendo la lista**; el controlador usa un método nuevo, `page()`, que devuelve la lista y `withheld`. Las pruebas y llamadores que ya usan `queue()` no cambian de forma.

### 0.6 · `claim()`, en orden

1. `clientRequestId` uuid (422) → el trabajo existe (404) → `started` (409 `not_in_queue`).
2. Se leen los frenos (falla cerrado).
3. Reclamado por otra persona → 409 `already_claimed`. Reclamado por quien pide → repetición idempotente, con `contactWithheld` calculado en ese momento (`person`/`script` en null si hay motivo).
4. Sin reclamar: inscripción abierta (409 `enrollment_closed`, como hoy) → motivo (freno, queja, vencido) → 409 `not_in_queue` y un `warning` `outreach.assisted.claim_withheld` → `UPDATE` condicionado.

El registro conserva la forma de `155e7adc` para la queja (`{job, reason: 'complaint_recent', known}`; `known: false` = sin datos) y añade `{job, reason: 'brake'}` y `{job, reason: 'expired'}`. Sin teléfonos, nombres ni texto.

## Veredicto

**Las cuatro condiciones de §4 de `informe-security-bandeja.md` quedan cerradas en el servidor, con pruebas que fallan con el código de HEAD y pasan con el arreglo, en SQLite y en PostgreSQL 14.** Falta la parte del CRM (el aviso por fila y el de `meta.withheld`, §5), que hace otro agente.

- **I-1.** La queja que llega después de reclamar deja la fila en la bandeja de quien la reclamó, sin `person` ni `script` y con `contactWithheld: 'complaint_recent'` (o `'complaint_unknown'` sin datos), también en la repetición de `claim()`. `recordOutcome()` no cambia.
- **I-2.** Lo sin reclamar vencido no se ofrece ni se reclama (409 `not_in_queue`); lo reclamado y vencido se queda con `contactWithheld: 'expired'`.
- **I-3.** La PARADA GLOBAL, el freno del canal `assisted` (incluido `legal_hold`) y el de la estrategia: lo sin reclamar no se ofrece ni se reclama, lo reclamado se queda con `contactWithheld: 'brake'`. Con los frenos ilegibles, falla cerrado.
- **I-4.** La página se llena por detrás de los retenidos (tandas de 50, tope de 200 filas leídas) y `meta.withheld` dice cuántos, sin decir de quién.
- **Lo de `155e7adc` sigue:** la misma función y el mismo lector para la queja, `human_requested` fuera, sin teléfonos ni nombres, y el registro `outreach.assisted.claim_withheld` en `warning` con la misma forma para la queja.

Sin migraciones, sin configuración nueva, sin cambios de fixtures. Cambia la forma de E11 y E12, de forma aditiva (§5).

## 1 · Ficheros tocados (lista exacta) [C: `git status`, `git diff`]

| Fichero | Qué | Líneas (después) |
|---|---|---|
| `app/Services/Outreach/Run/AssistedQueue.php` | modificado (+242 / −53) | `:13` (uso de `Builder`); `:33-58` (docblock: los cuatro motivos y la página); `:68-83` (`WITHHELD_*`, `WITHHELD_REASONS`, `SCAN_BATCH`, `MAX_SCAN`); `:85-91` (`QUEUE_ENROLLMENT_STATUSES`, solo movida: el docblock de `queue()` estaba encima de la constante); `:98` (`OutreachStop` inyectable); `:108-111` (`queue()` = `page()['items']`); `:123-162` (`page()`: frenos, tandas, motivos, `withheld`); `:179-209` (`claim()`: frenos antes de repetir o reclamar, la repetición con su motivo, `requireNotWithheld`); `:288-301` (`requireNotWithheld`, sustituye a `requireNoRecentComplaint`); `:311-332` (`withholdReasons`); `:368-384` (`queueQuery`); `:393-416` (`queueRows`: vencido y frenado en SQL, cursor `(expires_at, id)`); `:424-442` (`brakedCount`); `:467-485` (`dto()`: `$withheld`, `$contacto`, `contactWithheld`) |
| `app/Http/Controllers/Crm/UltronStudio/UltronStudioOutreachAssistedController.php` | modificado (+3 / −1) | `:41-44`: `page()` y `meta.withheld` |
| `tests/Feature/Outreach/Run/H9AssistedConditionsTest.php` | **nuevo** (478 líneas, 10 pruebas) | — |
| `tests/Feature/Outreach/Run/H9AssistedComplaintTest.php` | modificado (+11 / −7): **dos aserciones cambian por el encargo** | `:30-37` (docblock). `:174-178`: «quien reclamó conserva el enlace a la ficha» pasa a «sigue, con el contacto retenido» (`[null, null, 'complaint_recent']`): es exactamente R-1. `:210` y `:227-231`: «la vista mine no lee quejas» pasa a «la lee una vez» (I-1 vuelve a mirar lo reclamado); la prueba cambia de nombre (`…tambien_la_vista_mine`) porque el anterior sería falso. No se relaja nada: el resto del fichero queda igual |
| `tests/Feature/Outreach/Run/OutreachApiTest.php` | modificado (+13 / −1), aditivo | `:253-267`: por HTTP, `meta == {withheld: 0}`, la clave `contactWithheld` presente y null, y la queja después de reclamar en `?filter=mine` y en la repetición de E12 |
| `docs/ultron-studio/plans/informes-motores/informe-be-bandeja-condiciones.md` | **nuevo**: este informe | — |

**No he tocado** `OutreachStop.php` (reutilizo `openScopes()` y `ladderBrakes()` tal cual; no hacía falta cambio mínimo), ni otro fichero de `app/Services/Outreach/`.

**En el árbol hay además cambios que no son míos** [C: `git diff --numstat`, aparecidos durante la sesión]: `tests/Feature/Voice/E2E/H8E2EAbiertaTest.php`, `H8E2ECerradaTest.php`, `H8E2ESalientesPrimeroTest.php` y `H8StackE2ETestCase.php`. Son de la carpeta del agente tester; no los he tocado ni ejecutado. Ninguna de mis órdenes de §3 los incluye.

## 2 · Los requisitos, uno a uno

| # | Requisito | Cómo queda | Evidencia |
|---|---|---|---|
| 1 | **I-1:** las filas de `mine` también pasan por la queja; `true`/`null` → `person`/`script` en null y `contactWithheld` (`complaint_recent`/`complaint_unknown`); sin retención, `null`; igual en la repetición de `claim()`; `recordOutcome()` igual | `page()` evalúa lo sin reclamar y lo de quien mira (`:143`); `claim()` repite con `withholdReasons()` (`:193`); `dto()` (`:470`, `:483-485`) | [C] `test_i1_…` (2), `test_quien_ya_lo_reclamo…`, `OutreachApiTest` por HTTP; mutaciones I1-a…I1-f |
| 2 | **I-2:** sin reclamar y vencido → no se ofrece, 409 `not_in_queue`; reclamado y vencido → `expired` | `queueRows()` (`:399`, en SQL) y `withholdReasons()` (`:326`, `<=` como el despachador) | [C] `test_i2_…` con el borde exacto (un segundo antes se ofrece; en `expires_at` ya no); mutaciones I2-a…I2-c |
| 3 | **I-3:** global, canal `assisted` y estrategia; lo sin reclamar no se ofrece ni se reclama, lo reclamado `brake`; ilegibles → falla cerrado, con la lectura de N2 | `openScopes()` una vez por petición (`:129`, `:189`), `ladderBrakes()` (`:316`) y en SQL los mismos `whereNotIn` que la reclamación del despachador (`:395-406`) | [C] `test_i3_…` con `stopAll`, `legal_hold` del canal y freno de estrategia, soltarlos a cuatro ojos, y frenos ajenos que no retienen; `test_i3_si_los_frenos_no_se_pueden_leer…` (`Schema::drop('outreach_brakes')`); mutaciones I3-a…I3-g |
| 4 | **I-4:** llenar la página por tandas, ≤ `MAX_SUBJECTS`, y `meta.withheld` | Tandas de 50 con cursor, tope 200; `withheld` = frenados (recuento) + retenidos por queja encontrados | [C] `test_i4_…` (3): 55 retenidos delante y la página de 2 se llena; el tope; las lecturas por página; mutaciones I4-a…I4-g |
| 5 | **Prioridad:** `brake` > `complaint_recent` > `complaint_unknown` > `expired`, un solo valor | `withholdReasons()` (`:311-332`) | [C] `test_un_solo_motivo_por_fila…` (vencido → + sin datos → + queja → + freno); mutaciones P-a y P-b |
| 6 | **Pruebas:** clases reales, reloj en UTC, rojo con HEAD y verde, SQLite y PG; `x:<id>`; `legal_hold`; página llena; mutaciones | §3 | [C] |
| — | **Lo de `155e7adc`:** una sola fuente para la queja; `human_requested` fuera; sin teléfonos ni nombres; registro en `warning` | `withheldByComplaint()` sin cambios; el registro de la queja con la misma forma (`{job, reason: 'complaint_recent', known}`) | [C] `H9AssistedComplaintTest` 4/4 (con la parte de `human_requested`); mutaciones L-a…L-c; `OutreachStaticGuardsTest` (claves de registro) en verde |

**Sobre las pruebas:** todas usan `AssistedQueue`, `OutreachStop`, `OutreachPlanner`, `OutreachDispatcher` y el emisor real de la queja, sin dobles. Los trabajos en volumen (I-4) se siembran con las filas del esquema (`OutreachSchemaFixtures`): nacen `planned` y avanzan a `started` pasando por los disparadores reales. La persona suprimida se hace con la forma de `OutreachSuppressSubjectCommand::ownerSql()`: en PG con `DISABLE TRIGGER USER`, como el dueño; en SQLite quitando y reponiendo los disparadores de la tabla (dentro de la transacción de la prueba).

## 3 · Lo que ejecuté [C]

Salidas completas en el directorio temporal de la sesión (`…/scratchpad/evidencia/`), fuera del repositorio. Motor de `pgunit.sh` comprobado con una sonda: `motor=pgsql base=ultron_studio_lead_ola5 version=PostgreSQL 14.24 (Homebrew)`.

### 3.1 · Rojo con HEAD

Pruebas definitivas sobre los dos ficheros de `app/` de HEAD (`git show HEAD:…`), restaurados después y comprobados con `cmp` («RESTAURADO»).

| Fichero | SQLite | PostgreSQL 14.24 |
|---|---|---|
| `H9AssistedConditionsTest.php` | **`Tests: 10, Assertions: 34, Errors: 1, Failures: 9.`** | ídem |
| `H9AssistedComplaintTest.php` | **`Tests: 4, Assertions: 35, Errors: 1, Failures: 1.`** | ídem |
| `OutreachApiTest.php` | **`Tests: 7, Assertions: 111, Failures: 1.`** | ídem |

Dónde cae cada una con HEAD (PG; SQLite igual):
- **I-1** (`:188`): tras la queja, Ana sigue recibiendo `person: {kind: member, id: 1}` y el guion. Es R-1, reproducido.
- **I-1 sin datos** (`:214`): con la fuente apagada, el reclamante sigue teniendo el contacto.
- **I-2** (`:238`): en el plazo exacto el trabajo sin reclamar se sigue ofreciendo.
- **I-3** (`:275`): con la PARADA GLOBAL lo sin reclamar se sigue ofreciendo.
- **Frenos ilegibles** (`:320`): la bandeja responde en lugar de fallar.
- **Prioridad** (`:341`): no hay motivo (`'falta la clave'` frente a `'expired'`).
- **I-4** (`:367`): `queue('unclaimed', 2)` devuelve `[]` con 3 trabajos ofrecibles detrás de los 55 retenidos. Es I-4, reproducido.
- **Lecturas por página** (`:420`): 0 lecturas de frenos.
- **Persona suprimida** (`:440`): su reclamante recibe el guion (`[null, true, …]`). Es un hallazgo menor que no estaba escrito (§7, H-B).
- **El tope** (`:389`): error, `MAX_SCAN` no existe.
- `H9AssistedComplaintTest`: falta `contactWithheld` (`:178`) y la vista `mine` no lee quejas (`:231`).
- `OutreachApiTest` (`:254`): falta `meta`.

### 3.2 · Verde con el arreglo (estado final del árbol)

| Orden | SQLite | PostgreSQL 14.24 |
|---|---|---|
| `phpunit --no-logging tests/Feature/Outreach/Run/H9AssistedConditionsTest.php` | `OK (10 tests, 174 assertions)` | `OK (10 tests, 174 assertions)` |
| ídem `H9AssistedComplaintTest.php` | `OK (4 tests, 39 assertions)` | `OK (4 tests, 39 assertions)` |
| ídem `OutreachApiTest.php` | `OK (7 tests, 137 assertions)` | `OK (7 tests, 137 assertions)` |
| `tests/Feature/Outreach/Run` (el directorio) | `OK, but some tests were skipped!` **`Tests: 121, Assertions: 1850, Skipped: 2.`** (las 2 «Solo PostgreSQL») | **`OK (121 tests, 1943 assertions)`** |
| `tests/Feature/Outreach/Guards` | `OK (19 tests, 12168 assertions)` | `OK (19 tests, 12168 assertions)` |
| Guardas estáticas (la orden del encargo, 15 ficheros) | **`OK (138 tests, 178726 assertions)`** | — |
| `vendor/bin/pint --test` sobre los 5 ficheros de PHP | `{"tool":"pint","result":"passed"}` | — |

**La puerta de diff** (`OutreachIsolationTest::test_la_puerta_de_diff_de_legacy_n8n_otp_y_motor_comercial_esta_vacia_y_la_voz_no_gana_ficheros`) **está en verde en este árbol**: `✔ … OK (1 test, 15 assertions)` [C]. El encargo la daba en rojo por un cambio del lead sin subir en `SupervisionService.php`; en este árbol ese cambio no está [S: estará en otra copia]. No la he tocado.

### 3.3 · Mutaciones

Guion `mut/correr.py`, fuera del repositorio: reemplazo exacto sobre el fichero real (con el número de apariciones comprobado), `php -l`, las tres pruebas (21 en total) en SQLite y vuelta atrás comprobada con `filecmp`. Al final, el md5 de los dos ficheros es el de antes («FICHEROS INTACTOS») y no queda ningún `.mutbak`.

| Id | Mutación | Resultado |
|---|---|---|
| I1-a | la página no mira lo reclamado por quien mira | **muerta** (10 fallos) |
| I1-b | la repetición de `claim()` no mira los motivos | **muerta** (5) |
| I1-c | con motivo, el reclamante conserva `person` | **muerta** (6) |
| I1-d | con motivo, el reclamante conserva `script` | **muerta** (7) |
| I1-e | sin datos (`null`) no retiene | **muerta** (6) |
| I1-f | se mira y se enseña el motivo a quien no reclamó | **muerta** (`test_i1_la_queja…`: Beto vería `complaint_recent`) |
| I2-a | SQL: lo sin reclamar vencido se lee | **muerta** (cuenta como retenido: `withheld` 1 ≠ 0) |
| I2-b | el borde: `<` en vez de `<=` | **muerta** (se reclama en el plazo exacto) |
| I2-c | sin el motivo `expired` | **muerta** (2) |
| I3-a | SQL: la parada global no quita lo sin reclamar | **muerta** |
| I3-b | SQL: sin el freno de canal | **muerta** |
| I3-c | SQL: sin el freno de estrategia | **muerta** (2) |
| I3-d | PHP: los frenos no dan motivo | **muerta** (2) |
| I3-e | frenos ilegibles: se traga el error (falla abierto), en `page()` y en `claim()` | **muerta** (`test_i3_si_los_frenos…`) |
| I3-f | `claim()` no lee los frenos | **muerta** (2) |
| I3-g | `claim()` lee los frenos DESPUÉS del `UPDATE` | **muerta** (2; la de los frenos ilegibles la ve en SQLite: el trabajo queda reclamado) |
| I4-a | una sola tanda | **muerta** (2) |
| I4-b | tandas del tamaño de `limit` | **muerta** (el recuento de lecturas) |
| I4-c | no cuenta los retenidos por queja | **muerta** (3) |
| I4-d | no cuenta los frenados | **muerta** (2) |
| I4-e | sin tope de lectura | **muerta** |
| I4-f | el cursor repite la última fila (`>=`) | **muerta** (`withheld` 56 ≠ 55) |
| I4-g | el controlador no publica `meta.withheld` | **muerta** (`OutreachApiTest`) |
| P-a | prioridad: vencido antes que la queja | **muerta** |
| P-b | prioridad: la queja pisa al freno | **muerta** |
| L-a | el freno y el plazo, sin registro | **muerta** (2) |
| L-b | el registro baja a `info` (no se vería en producción) | **muerta** (4) |
| L-c | la queja cambia la forma del registro de M-5 | **muerta** |

**28 de 28 muertas.** La mutación equivalente de M-5 (M5-8, la finalidad fija en `commercial`) sigue igual: no hay estrategia de servicio ni de cobranza que llegue a `started` en `RunWorld`.

### 3.4 · Consultas por página

Sonda `SondaBandejaConsultasTest`, fuera del repositorio. N trabajos sin reclamar por el planificador y el despachador reales, contando **todas** las consultas de una llamada en SQLite. Primero `queue('all', 50)`; después Ana reclama uno y se mide `queue('mine')`; al final, con un freno de la estrategia, `queue('all', 50)` otra vez. Misma sonda sobre HEAD (ficheros restaurados, `cmp`).

| Caso | HEAD | Con el arreglo | Diferencia |
|---|---|---|---|
| `all`, N = 1 | 9 | 10 | **+1** (`outreach_brakes`) |
| `all`, N = 5 | 17 | 18 | **+1** |
| `all`, N = 20 | 47 | 48 | **+1** |
| `mine`, 1 reclamado | 4 | 9 | **+5**: frenos ×1 y la foto de la queja (`members` ×2, `marketing_leads` ×1, `commercial_events` ×1), fijas |
| `all` con freno de estrategia, N = 20 | 46 (lo ofrecía todo) | **5** | −41: lo frenado no se lee; frenos ×1, la tanda ×1, el `COUNT` ×1 y el `dto()` de la fila reclamada ×2 |

- **Por tanda:** una lectura de trabajos y una foto, nunca una por fila (lo fija `test_i4_una_pagina_normal…`: frenos 1, foto 1, también en `mine` y con filas de otra persona).
- **Peor caso del tope:** 200 retenidos sin resolver, `1 + 2 × 4 = 9` consultas exactas (lo fija `test_i4_la_lectura…`). Con personas que se resuelven, cada foto son 4 consultas: `1 + 4 × 5 + 1` como mucho, más el `dto()` de las filas devueltas.
- **Lo que crece por fila ya venía de antes:** el `dto()` hace 2 consultas por fila devuelta (`outreach_decisions` y `ultron_studio_strategy_versions`): H-3 del informe anterior, sin cambios. Las filas retenidas no llaman a `dto()`.
- `classes` y `plans` salen una vez por proceso en la primera foto, en HEAD y con el arreglo (una caché de la lectura de hechos, [S] por lo medido). No dependen de este cambio.

## 4 · Contratos nuevos o modificados (aditivos)

**E11 · `GET outreach/assisted/queue`**
```json
{ "ok": true,
  "data": [ { "uuid": "…", "enrollment": "…", "strategy": {…}, "step": 0, "attempt": 1, "medium": "whatsapp",
              "status": "started", "subjectRef": "…", "person": null, "script": null,
              "contactWithheld": null, "reason": [ … ], "startedAt": "…", "dueBy": "…", "claimedBy": null, "outcomes": [ … ] } ],
  "meta": { "withheld": 0 } }
```
**E12 · `POST outreach/assisted/{uuid}/claim`:** `data` = la misma fila, con `contactWithheld`; `meta.replayed` como antes. Los códigos de error no cambian: el 409 `not_in_queue` cubre ahora también el freno y el plazo vencido.

| Campo | Dónde | Tipo y valores | Significado |
|---|---|---|---|
| `contactWithheld` | cada fila de `data` en E11; `data` en E12 (nuevo y repetición) | `null` \| `'brake'` \| `'complaint_recent'` \| `'complaint_unknown'` \| `'expired'` | Solo tiene valor en una fila **reclamada por quien mira**. Con valor, `person` y `script` van en `null`. El resultado (E13) se registra igual. En cualquier otra fila es `null`. Un solo motivo, por la prioridad de la columna anterior. Lista cerrada: `AssistedQueue::WITHHELD_REASONS` |
| `meta.withheld` | E11, en las tres vistas | entero ≥ 0 | Trabajos **sin reclamar y no vencidos** de esa vista que no se ofrecen ahora: los frenados (exacto) más los retenidos por queja o sin datos que se encontraron al llenar la página (cota inferior si la página se llenó o se llegó al tope de 200 filas leídas). **No cuenta los vencidos.** En `mine` siempre es `0` |

## 5 · Lo que tiene que hacer el CRM (`Iron-Body_Front/src/app/modules/commercial-automation/`)

**Sin cambios, el CRM de hoy no se rompe:** `readAssistedJob` ignora las claves que no conoce y `person`/`script` ya eran opcionales [L: `commercial-automation-parse.ts:1332-1355` en `d16e26a`]. Pero el reclamante vería desaparecer el enlace y el guion **sin explicación**, y la bandeja vacía tampoco explicaría por qué.

**Lo que hay que hacer:**

1. **El tipo y el lector de la fila:** `contactWithheld: 'brake' | 'complaint_recent' | 'complaint_unknown' | 'expired' | null`. Un valor desconocido se trata como **retenido**, nunca como «sin retención».
2. **El lector de la página:** `meta.withheld` (entero ≥ 0). Sin `meta`, «no se sabe»: no se pinta un 0.
3. **El aviso en la fila reclamada por mí con `contactWithheld`:** se muestra en lugar del enlace y del guion, y se mantiene el botón «Registrar el resultado». Textos propuestos:

| Valor | Texto propuesto |
|---|---|
| `complaint_recent` | «Esta persona se quejó después de que reclamaras el contacto: no la contactes. Registra el resultado que corresponda.» |
| `complaint_unknown` | «No se puede comprobar si esta persona se quejó (no hay datos): no la contactes. Registra el resultado que corresponda.» |
| `brake` | «Hay un freno abierto (la parada global, el del canal asistido o el de esta estrategia): no contactes a esta persona mientras siga abierto. Si ya hablaste con ella, registra el resultado.» |
| `expired` | «El plazo de este contacto ya pasó: no contactes a esta persona con esta decisión. Registra el resultado que corresponda.» |
| (desconocido) | «El servidor retuvo este contacto: no contactes a esta persona. Registra el resultado que corresponda.» |

4. **El aviso de `meta.withheld > 0`** junto a la lista, también si la lista está vacía (el texto de vacío actual dice «No hay trabajos asistidos vigentes», que no sería cierto). Propuesta: «Hay N contactos que no se ofrecen ahora: hay un freno abierto, la persona se quejó hace poco o no se puede comprobar si se quejó.» No hay que mencionar los vencidos: no cuentan.
5. **Recomendado:** recargar la bandeja también tras el 409 `not_in_queue` de reclamar, para que la fila desaparezca. El CRM en curso ya lo hace [L: `commercial-automation-session.ts:711`, sin confirmar].
6. **Lo que el `brake` tiene de distinto:** es el único motivo que se va solo. Al soltar el freno vuelven el enlace y el guion. La queja y el plazo, en la práctica, no vuelven.

**Lo que he visto en el CRM, sin tocarlo [L, solo lectura]:** el árbol del CRM tiene cambios sin confirmar en `commercial-automation-{api.types,parse,text,session,api.service}.ts` y `ca-assisted.ts` que ya usan **exactamente** estos nombres y valores: `AssistedWithheldReason`, `ASSISTED_WITHHELD_REASONS` en el mismo orden, `retencion()` que convierte un valor desconocido en `'unrecognized'`, y `readAssistedQueuePage` con `withheld: null` cuando falta `meta`. Cuadra con lo que devuelve el servidor. Un matiz para quien lo lleve: el texto del freno en curso no dice «registra el resultado».

**Fixtures del H9 sincronizados al CRM: no cambia ninguno.** El guion no está en `Iron-Body_Front/scripts/`, como decía el encargo: está en `src/app/modules/commercial-automation/testing/sync-h9-fixtures.mjs`. Copia cinco ficheros de `tests/fixtures/outreach/` y `tests/Unit/Outreach/Core/fixtures/trace-text-cases.json`. Son oráculos del motor (catálogo, condiciones, contenido, escalera, transiciones y texto de la traza), sin forma de la API de la bandeja. `git status` sobre `tests/fixtures/` y `tests/Unit/Outreach/` da 0 cambios [C]. Huellas actuales, como referencia (sin cambios): `catalog-snapshot.json` `451961b05bb1f278…`, `conditions-cases.json` `016a45aee8f956c3…`, `strategy-content-cases.json` `ad5d3894ca893d30…`, `policy-ladder-cases.json` `bbcbf665c39a130a…`, `outcome-transitions.json` `f9e38189b8e1a1f9…`.

## 6 · Texto propuesto para la enmienda del contrato (no lo he aplicado)

Sobre el texto vigente de `240e2678`.

**D9-9.5, sustituir la viñeta «Quien ya lo reclamó lo sigue viendo y registra su resultado.» y añadir otra viñeta:**
> - Quien ya lo reclamó lo sigue viendo y registra su resultado; **si ahora hay un motivo de retención, sin el enlace a la ficha ni el guion** (enmienda aditiva del 12-11-2026, I-1 de security, L9-117).
>
> - **La bandeja retiene también por los frenos y por el plazo (enmienda aditiva del 12-11-2026, I-1 a I-4 de `informe-security-bandeja.md`, L9-117).** Cada trabajo tiene como mucho un motivo, por este orden:
>   1. `brake`: la PARADA GLOBAL, el freno del canal `assisted` (también `legal_hold`) o el de la estrategia del trabajo, con la lectura de N2 (`OutreachStop::openScopes()`). Si los frenos no se pueden leer, la bandeja y la reclamación fallan cerrado.
>   2. `complaint_recent`: N6, una queja dentro de la ventana.
>   3. `complaint_unknown`: N6 sin datos de eventos o de la persona (también la clave suprimida `x:<id>`).
>   4. `expired`: `expires_at <= ahora`, el borde del despachador. La política se evaluó para ese plazo: pasado, no se contacta con esa decisión.
>
>   Un trabajo SIN reclamar con motivo no se ofrece y no se reclama: 409 `not_in_queue`. El motivo va a `outreach.assisted.claim_withheld` en `warning`: `{job, reason, known}` para la queja, `{job, reason}` para el freno y el plazo. Uno YA reclamado se queda para que su reclamante registre el resultado (E13 no cambia), con `person` y `script` en null y `contactWithheld` = el motivo. Solo lo ve quien lo reclamó. La bandeja no escribe nada.
>
>   La página se llena por detrás de lo retenido: lo vencido o frenado sin reclamar se descarta en SQL, y la queja por tandas de 50, con una foto por tanda y un tope de 200 filas leídas. `meta.withheld` dice cuántos sin reclamar y no vencidos no se ofrecen, sin decir de quién.

**§7, notas de E11 y E12:**
> | E11 | `GET assisted/queue` [O] | `commercial_automation.assisted_work` | Sin teléfonos; con un enlace a la ficha; sin los trabajos sin reclamar retenidos (freno, queja reciente o sin datos, plazo vencido; D9-9.5, L9-116, L9-117); `contactWithheld` en cada fila y `meta.withheld` (aditivos) |
> | E12 | `POST assisted/{jobUuid}/claim` [O] | Ídem | Una vez; 409 `not_in_queue` también con un motivo de retención (D9-9.5, L9-116, L9-117); la repetición lleva `contactWithheld` |

**D9-14, «Efecto» de la PARADA GLOBAL, una viñeta más:**
> - la bandeja asistida no ofrece ni deja reclamar nada nuevo, y quien ya reclamó un trabajo deja de ver el enlace y el guion mientras siga echada (`contactWithheld: 'brake'`, L9-117). Vale también para el freno del canal `assisted` y para el de la estrategia.

**L9-117, en el registro:**
> | L9-117 | backend (be-bandeja-condiciones) | Las cuatro condiciones de security para abrir el asistido en vivo (I-1 a I-4 de `informe-security-bandeja.md`) | `AssistedQueue` da a cada trabajo, como mucho, un motivo de retención: `brake` (`openScopes()` de N2 y `ladderBrakes()`; frenos ilegibles → falla cerrado), `complaint_recent` o `complaint_unknown` (la misma foto y la misma función que M-5) y `expired` (`expires_at <= ahora`). Sin reclamar con motivo: no se ofrece y 409 `not_in_queue`. Reclamado: se queda, sin `person` ni `script` y con `contactWithheld`, también en la repetición de E12. E13 no cambia. La página se llena: vencido y frenado en SQL; la queja por tandas de 50 con una foto cada una y un tope de 200; `meta.withheld`. Sin escrituras ni migraciones. **Decisión del lead que el propietario puede cambiar:** pasado `expires_at`, no se contacta con esa decisión. Pruebas: `H9AssistedConditionsTest` (10), dos aserciones de `H9AssistedComplaintTest` cambiadas por I-1 y `OutreachApiTest` por HTTP; rojo con `b90f84c4` y verde con el arreglo en SQLite y en PG 14; 28 mutaciones, 28 muertas. Consultas: +1 por página (los frenos); `mine`, +5 fijas |

## 7 · Decisiones que necesitan al propietario y hallazgos adyacentes

**Decisiones:**
- **D-1 · El plazo (I-2).** He aplicado la decisión del lead: `expires_at <= ahora` corta, sin margen. El trabajo dura el hueco más 24 h (`JOB_TTL_HOURS`), nunca más allá de la ventana de la inscripción. Si el propietario quiere un margen para quien ya reclamó (p. ej. 2 h para terminar una llamada empezada), es una constante en `withholdReasons()`.
- **D-2 · Cerrar los `started` asistidos vencidos.** Hoy se quedan `started` (el estado es final) y la inscripción abierta hasta el resultado o el fin de su ventana. Que el conciliador cierre la inscripción sería una escritura nueva y una decisión de producto. No la he hecho.
- **D-3 · Los frenos ajenos al H9** (cerrojo de ULTRON, pausa del agente de mercadeo, freno de voz: `OperationalBrakes`) no retienen la bandeja: son del agente automático, no del contacto humano. Si el propietario quiere que, por ejemplo, el freno de voz retenga los asistidos por voz, es un caso más en `withholdReasons()`.
- **D-4 · `meta.withheld` es una cota inferior en la parte de la queja.** Contarlos todos obligaría a leer y fotografiar toda la bandeja en cada página. Con I-2, lo retenido por queja vence en ≤ 24 h, así que el número real está acotado por el cupo diario del asistido.

**Hallazgos adyacentes (no los he arreglado):**
- **H-A · `GET outreach/status` → `live.assistedQueue`** (`OutreachReadModels.php:69-70`) [L] cuenta todos los `started` asistidos sin resultado del personal: también los vencidos, los frenados, los retenidos por queja y los de inscripciones cerradas. Ya no coincide con lo que ofrece la bandeja. Es un número, sin datos personales. Fuera de mis ficheros: es del lead o de quien lleve los modelos de lectura.
- **H-B · Con HEAD, el reclamante de un trabajo de una persona suprimida (`x:<id>`) seguía recibiendo el guion** (no el enlace) [C: rojo de `:440`]. Queda cerrado por I-1 (`complaint_unknown`).
- **H-C · N+1 preexistente en `dto()`** (H-3 del informe anterior), sin cambios: 2 consultas por fila devuelta. Nadie lo ha reportado como lento; no lo escalo.
- **H-D · Docblocks descolocados en el controlador** (`UltronStudioOutreachAssistedController.php:24-25`: el de `GET queue` está encima de `requireEnabled()`). Cosmético y preexistente; no lo toco.

## 8 · Lo que NO he podido comprobar

- **El 500 por HTTP con los frenos ilegibles:** solo lo he probado en el servicio (la excepción sube y nada se reclama). Que el cuerpo del 500 no lleve SQL con `APP_DEBUG=false` es [S]: es el gestor estándar de Laravel. En PG la prueba usa un punto de guardado por intento. Por eso que el `UPDATE` no se haga antes del error solo lo ve SQLite (mutación I3-g).
- **El CRM:** solo lo he leído. Ni sus pruebas ni la pantalla.
- **Producción:** nada. Ni el volumen real de la bandeja, ni si hay trabajos `started` asistidos en vivo (según L9-114 y L9-116, la automatización comercial está en sombra) [S].
- **La batería completa:** no la he lanzado, por indicación del encargo. Solo lo de §3.
- **Una estrategia de servicio o de cobranza en `started`:** no existe en los ayudantes de prueba (M5-8 sigue equivalente).
- **Las sondas** (`SondaMotorTest`, `SondaBandejaConsultasTest`, `mut/correr.py`) están en el directorio temporal de la sesión y no van al repositorio.

## 9 · Casos límite para `tester`

- **Carrera entre la comprobación y el `UPDATE` de `claim()`:** un freno o una queja en esos milisegundos no se ven al reclamar. La siguiente lectura de `mine`, o la repetición, ya retiene el contacto (m-2 de security). No la he provocado.
- **Un freno que se suelta:** el reclamante recupera el enlace y el guion; la queja y el plazo, no.
- **`expires_at` exacto:** vencido (`<=`), como el despachador. Lo cubre `test_i2_…`.
- **Página con más de 200 retenidos sin reclamar y no vencidos por delante:** la página sale vacía y `withheld = 200` (el tope). Con I-2 solo pasa si en un día hay más de 200 retenidos por queja o sin datos, por ejemplo con `commercial.events_enabled` apagado y un cupo diario alto.
- **La vista `all` con trabajos reclamados por otra persona y retenidos:** se ven como siempre (`contactWithheld: null`), sin contarle a nadie que esa persona se quejó.

# Informe del tester (backend) del Hito 5: carreras reales en PostgreSQL, suite contra `origin/main` y mutaciones de la cadena

**Fecha:** 25-10-2026 · **Rama:** `feat/ultron-studio-h5` · **HEAD:** `d98bbf9` (al empezar, `61048ff`; `d98bbf9` solo añade el informe y las capturas del paso 4 del CRM: `git diff --stat 61048ff d98bbf9 -- backend/app backend/tests backend/config backend/database backend/routes backend/composer.lock backend/phpunit.xml` sale vacío, así que lo medido sobre `61048ff` vale para `d98bbf9`) · **Base:** `origin/main` = `0366009` · **Commit:** ninguno (lo confirma el lead).

Contrato: `ULTRON_STUDIO_H5_CONTRACT.md` (A12 fila «PostgreSQL»; A2; A5.1 pasos 3 y 12; A10.6). Leído antes de empezar: `informe-api.md` entero (§2 orden de las puertas, §6.11 mutaciones hechas, §7 casos límite) y `decisiones-lead-h5.md`. Cruzado al final con `informe-api-security.md` (AS-4 es el mismo camino que H-1).

---

## 0. En una pantalla

| Tarea | Resultado |
|---|---|
| **1. Carreras reales en PG (A12)** | **38 casos nuevos, solo-PG**, cada lado una sesión de verdad (proceso hijo con su conexión, petición por el kernel HTTP). **29 verdes**: 20 a la vez sobre la misma versión y sobre versiones distintas, la «tormenta» de 19 transacciones abortando a la vez por el 23505, dos versiones, `usv_one_canary`, promover contra revertir, doble promoción, publicar contra Legacy, revertir la publicada contra promover otra, dos personas pulsando la misma salida, `recordDenied` en REPEATABLE READ (20 a la vez y con cerrojo rival). En todas: **ninguna transacción abortada deja nada a medias**, la invariante de A2 se cumple, `seq` sin huecos, la cabeza verifica y el lector del motor la aplica. **9 rojas = H-1** (abajo). Extra: todas las sesiones en REPEATABLE READ → las mismas verdes |
| **2. Suite frente a `origin/main`** | **SQLite (10 841 frente a 10 137 pruebas, el mismo día, en secuencia): 0 rojos nuevos** — 57 nombres en rojo idénticos en los dos lados, todos ajenos a H5. **PG 14:** Studio 1441 pruebas, 9 rojas = las 9 de H-1 (nuevas); de lo existente, 0 rojos nuevos (en la base, 1 rojo de tiempo ya conocido). Marketing 4977 frente a 4730: **los mismos 10 rojos por nombre**, 0 nuevos |
| **3. Mutaciones** | **22** (las 10 pedidas, con variantes, y 7 de las carreras); **19 caen, 3 sobreviven** (C2, I1, J1: huecos de cobertura, baja). `php -l` y restauración con sha256 en las 22; huella del árbol idéntica antes y después |
| **4. Informe** | Este fichero |

**Hallazgos:**
- **H-1 (media; contrato, sin daño a datos):** el reintento de la MISMA petición (misma persona, mismo `clientRequestId`, mismo cuerpo) que cruza la confirmación de la primera recibe **409** (`invalid_transition`, `not_active_publication`, `already_legacy`) en vez del **200 `replayed`** que promete A5.1 paso 3, en las **cuatro** escrituras. Reproducido 8/8 de forma determinista y de forma natural (20 envíos idénticos: 5, 2 y 3 de 19 respuestas en tres pasadas; roja en 11 de 12). Es el AS-4 de `api-security-specialist`, ahora demostrado con dos sesiones. Arreglo propuesto en §3; no lo he tocado.
- **B-1, B-2, B-3 (baja; cobertura):** nada fija que el Studio compare la huella de la cabeza (C2), que la vista previa use el `catalogVersion` de la versión (I1) ni su tope de lectura de 16 385 caracteres (J1). El código hace lo correcto; propongo las tres pruebas (§7).
- **Bloqueantes de integridad: ninguno.** Lo que el encargo pedía buscar («la transacción abortada por el 23505 deja algo a medias») no ocurre en ninguna carrera.

**Ficheros nuevos** (ninguno existente tocado; sin commit): `tests/Feature/UltronStudio/UltronStudioPublicationRaceTest.php`, `tests/Feature/UltronStudio/PublicationRaceSessions.php`, `docs/ultron-studio/ops/suite-compare.sh`, `docs/ultron-studio/ops/h5-mutations.php` y este informe.

---

## 1. Cómo se prueba una carrera aquí (el arnés)

**Dos sesiones de verdad, no una conexión con la rival inyectada.** Cada lado de cada carrera es un PROCESO HIJO (`proc_open` de `php -r`) que arranca la aplicación entera con su propia conexión a PostgreSQL y manda la petición por el **kernel HTTP** (`$kernel->handle(Request::create(…))`): middleware, sesión del personal por token Bearer (`AdminSessionService::issueSession`, una por hijo), validación, controlador, servicio y transacción reales. La prueba (el padre) solo siembra, coordina y comprueba. `assertRacersClean()` exige que cada hijo tenga un `pg_backend_pid()` distinto y distinto del de la prueba.

- **Lo que ven los hijos:** solo lo confirmado. La clase no envuelve nada en transacción (`$connectionsToTransact = []`) y cada prueba empieza con el esquema recién creado (`RefreshDatabaseState::$migrated = false` antes y después: la cadena y el libro prohíben DELETE y TRUNCATE, como en `UltronStudioLabBudgetLockTest`). Los hijos reciben la configuración de la publicación de la prueba (las 9 claves que fija `openKeys()`) y su reloj congelado en UTC.
- **Natural:** N hijos esperan un cerrojo consultivo que la prueba tiene en exclusiva; cuando `pg_locks` enseña a los N esperando, se suelta y salen a la vez. Se afirma lo que vale en CUALQUIER orden.
- **Forzada:** pausas en un punto exacto (antes de la primera sentencia que contiene unos fragmentos, dentro o fuera de transacción) hasta que **otra sesión espere un cerrojo de esta** (`pg_blocking_pids` + escalón de 40 ms) o hasta que la prueba suelte otro cerrojo. Cada prueba forzada afirma que la carrera OCURRIÓ (`waiters ≥ 1`, `paused`), no solo el resultado.
- **Rival crudo** (`raw`): una transacción con SQL dado que confirma cuando otra sesión espera sus cerrojos. Solo en dos casos que el código no deja alcanzar con dos escrituras reales (el índice `usv_one_canary` y el cerrojo de deduplicación de `recordDenied`); se dice en cada prueba.
- **Diagnóstico opcional** (sin las variables no cambia nada): `ULTRON_STUDIO_RACE_LOG=<fichero>` apunta lo que respondió cada sesión; `ULTRON_STUDIO_RACE_ISOLATION='repeatable read'` arranca todas las sesiones en ese nivel.

Después de cada carrera, siempre: **nada a medias** (`assertNothingHalfDone`: cada entrada tiene exactamente un evento principal de su acción con su `entry`, y ningún evento nombra una entrada que no existe), la invariante de A2 (`assertChainMatchesStates`), la cadena entera verifica (`seq` 1..N sin huecos, `previous_publication_id` encadenado, `client_request_id` únicos, huella, `signatureFor` sobre los bytes y `D::parts` atado a uuid/seq) y el **lector real del motor** la aplica (`describe()` con `hashOk/signatureOk/parseOk` y `forConversation()` de la canaria y de otra conversación, con la llave en `all`).

Ficheros: `tests/Feature/UltronStudio/PublicationRaceSessions.php` (trait) y `tests/Feature/UltronStudio/UltronStudioPublicationRaceTest.php` (38 casos). Se omiten en SQLite con el mismo mecanismo que las demás de solo-PG (`isPostgres()` + `markTestSkipped` en `setUp`).

---

## 2. Carreras (A12, fila «PostgreSQL»)

`PGGSSENCMODE=disable UNIT_DB=ultron_studio_h5_races bash docs/ultron-studio/ops/pgunit.sh tests/Feature/UltronStudio/UltronStudioPublicationRaceTest.php` (con `ULTRON_STUDIO_RACE_LOG` para la columna «pasó» de las naturales; los recuentos son los de la pasada final, 2 min 45 s). Resultado: **38 pruebas, 29 verdes y 9 rojas, las 9 de H-1** (§3). «Natural»: el orden lo decide el planificador y se anota el que salió; «forzada»: el orden lo fija la pausa y la prueba comprueba que la carrera ocurrió.

| # | Carrera | Tipo | Se esperaba (contrato) | Pasó |
|---|---|---|---|---|
| 1 | 20 publicaciones de la misma versión, **mismo** `clientRequestId` (20 sesiones de Pablo) | natural | 1×201 y 19×200 `replayed` con la misma entrada (A5.1 p.3); 1 entrada; CANARY una vez | 1×201, 16×`replayed`, **3×409 `invalid_transition`** → **ROJA (H-1)**; en otras pasadas, 14/5 y 17/2. Roja en 11 de las 12 pasadas en que corrió; en una, ningún hijo cayó en la ventana y salió verde (es natural: la ventana de H-1, no una inestabilidad del código arreglado). La integridad, que se comprueba ANTES, pasa siempre: 1 entrada, 1 `version.published` |
| 2 | 20 publicaciones de la misma versión, **distintos** `clientRequestId` (2 personas) | natural | 1×201; 19×409 `publication_moved` o `invalid_transition` (`CANARY`); 1 entrada; CANARY una vez | 1×201, 18×`invalid_transition`/CANARY, 1×`publication_moved` ✔ |
| 3 | 20 versiones APPROVED distintas a la vez | natural | 1×201; 19×409 `publication_moved` o `canary_in_progress`; una sola CANARY; 19 siguen APPROVED | 1×201, 18×`canary_in_progress`, 1×`publication_moved` ✔ |
| 4 | **Tormenta**: 20 versiones distintas, todas paradas antes de leer la cabeza y soltadas juntas; la ganadora no confirma hasta que la esperan las otras 19 | forzada | 1×201; 19×409 `publication_moved` (19 transacciones reales abortadas por el 23505 de `seq` a la vez); nada a medias | 1×201 con `waiters = 19`, 19×`publication_moved`, 20 pids distintos ✔ |
| 5 | Tormenta con la **misma** petición 20 veces | forzada | 1×201; 19×200 `replayed` (el 23505 resuelto fuera de la transacción) | 1×201 con `waiters = 19`, 19×`replayed` ✔ |
| 6 | Dos versiones: A inserta y espera; B lee la cabeza vieja y espera el `seq` de A | forzada | A 201; B 409 `publication_moved`; de B ni entrada, ni transición, ni evento | ✔ (`waiters = 1`) |
| 7 | B (otra versión) pasó sus puertas y lee la cabeza dentro de su transacción después de que A confirmó | forzada | B 409 `canary_in_progress` | ✔ |
| 8 | Ídem con la misma versión y otra petición | forzada | B 409 `invalid_transition` (`CANARY`) | ✔ |
| 9 | Índice `usv_one_canary` entre sesiones: un rival crudo tiene OTRA versión en CANARY sin confirmar; la publicación real inserta su entrada y espera en el índice | forzada, rival crudo | 409 `canary_in_progress`; la transacción abortada no deja su entrada, ni su transición, ni su evento | ✔ (`waiters = 1`; 0 entradas; V1 APPROVED; 0 `version.published`; el motor ve Legacy sin cabeza) |
| 10–11 | Promover contra revertir la misma CANARY (gana promover / gana revertir) | forzada | 201 + 409 `publication_moved`; estado del que gana | ✔ / ✔ |
| 12 | Revertir la canaria que otra sesión acaba de promover | forzada | 409 `invalid_transition` (`PUBLISHED`) | ✔ |
| 13 | Promover y revertir a la vez la misma CANARY | natural | respuestas = cadena; si promover y después revertir, dos 201 en ese orden | revertir 201, promover 409 `invalid_transition` (`ROLLED_BACK`) ✔ |
| 14 | Doble promoción (dos personas) | natural | exactamente una 201 | 201 + `publication_moved` ✔ |
| 15 | Doble promoción forzada | forzada | 201 + 409 `publication_moved` | ✔ |
| 16–17 | Publicar contra volver a Legacy (gana publicar / gana Legacy) | forzada | 201 + 409 `publication_moved`; {V, P} o Legacy con P RETIRED | ✔ / ✔ |
| 18 | Publicar, que ya pasó sus puertas con P en el resto, lee la cabeza tras un Legacy confirmado | forzada | 201 + 201; la parte resto sale de la cabeza leída DENTRO: {V, null}, no la P ya RETIRED | ✔ |
| 19 | Legacy, que comprobó la cabeza fuera, lee la de dentro tras una publicación confirmada | forzada | 201 + 201; mueve la CANARY y la PUBLISHED de AHORA (V → ROLLED_BACK, P → RETIRED) | ✔ |
| 20 | Publicar y volver a Legacy a la vez | natural | coherente en cualquier orden | 201 + 201 (una tras otra; en otra pasada, 201 + `publication_moved`) ✔ |
| 21–22 | Dos personas pulsan a la vez la misma salida (Legacy / revertir la canaria), cada una con su identificador | natural | una sola 201; la otra 409 (`publication_moved`, `already_legacy`, `invalid_transition`, `not_active_publication`) | 201 + `publication_moved` en las dos ✔ |
| 23–24 | Revertir la PUBLISHED (P1, con P0 RETIRED) contra promover la CANARY (V) (gana revertir / gana promover) | forzada | 201 + 409 `publication_moved`; si gana revertir, {V, P0} con P0 restaurada (Q-2) | ✔ / ✔ |
| 25 | Revertir P1 que otra sesión acaba de sustituir | forzada | 409 `invalid_transition` (`RETIRED`); nada restaurado | ✔ |
| 26 | Promover V justo después de que revertir restaurase P0 | forzada | 201 + 201; sustituye a P0 (la PUBLISHED de ahora), `version.retired {by: promote}` con su entrada | ✔ |
| 27 | Revertir P1 y promover V a la vez | natural | coherente en cualquier orden | 201 + 201 ✔ |
| 28 | 20 intentos simultáneos de la autora de publicar lo suyo, sesiones en **REPEATABLE READ** | natural | 20×403 `four_eyes_required`; **un solo** `version.four_eyes_denied` | ✔ |
| 29 | `recordDenied` en REPEATABLE READ con el cerrojo de deduplicación en manos de un rival que ya escribió la fila | forzada, rival crudo | la autora espera el cerrojo, ve la fila del rival al soltarlo y no escribe otra | ✔ (`waiters = 1`, 1 fila, la del rival) |
| 30 | Doble clic: la segunda espera el índice de la primera | forzada | 200 `replayed` | ✔ |
| 31–38 | El reintento que cruza la confirmación de la primera (4 acciones × 2 ventanas) | forzada | 200 `replayed` | **ROJAS (H-1)**, §3 |

**En las 29 verdes y en las 9 rojas** (las rojas comprueban la integridad ANTES y caen siempre en su última aserción, la del contrato: líneas 167 y 921, en las tres pasadas finales): ninguna sesión murió (sin excepción en el hijo, sin 5xx), ninguna transacción abortada dejó rastro (`assertNothingHalfDone`), la invariante de A2 se cumple, la cadena entera verifica y el lector del motor aplica la cabeza (o ve Legacy sin cabeza donde no hay cadena).

**Extra (no pedido): todas las sesiones en REPEATABLE READ.** `ULTRON_STUDIO_RACE_ISOLATION='repeatable read'` sobre el fichero final: **38 pruebas, las mismas 29 verdes y las mismas 9 rojas de H-1** (1 min 46 s); el rastro confirma que las **184** sesiones iban en `repeatable read`. Es decir, la cadena aguanta un rol con ese nivel por defecto (la lección B-2 de G, que el contrato solo aplicó a `recordDenied`): el primer choque de dos escrituras siempre es el 23505 de su inserción, antes de cualquier UPDATE.

---

## 3. H-1 · El reintento que cruza la confirmación de la primera no recibe su entrada (MEDIA)

**Qué promete el contrato.** A5.1 paso 3: «misma `clientRequestId`, misma persona y mismo cuerpo → 200 `replayed` con la entrada de la primera vez». El informe de la API (§7) lo da por cumplido: «el segundo es 200 `replayed` aunque la versión ya esté en CANARY».

**Qué hace el código.** Mira el reintento en dos sitios: al principio (`StudioPublications.php:138` publicar, `:237` promover, `:336` revertir, `:426` Legacy) y tras un 23505 (`:211`, `:307`, `:400`, `:476`). Si la PRIMERA petición se confirma después de que la segunda pasó la primera mirada y antes de que la segunda inserte su entrada, la segunda no choca con ningún índice: tropieza con el estado que su propia gemela acaba de dejar, en una de dos ventanas:
- **`gates`**: entre `replay()` y la lectura de la versión → las puertas ven el estado nuevo;
- **`transaction`**: entre las puertas y `head()` dentro de la transacción → `D::compose()` lanza y `raceRefusal()` responde con el estado de ahora (en Legacy, `assertDeclaring()`).

**Evidencia, determinista** (B, la misma petición que A, se para en la ventana; A se confirma entera; B sigue). 8 de 8 reproducen:

| Acción · ventana | Contrato | Respuesta real de B |
|---|---|---|
| publicar · gates | 200 `replayed` | 409 `invalid_transition` (`currentStatus: CANARY`) |
| publicar · transaction | 200 `replayed` | 409 `invalid_transition` (`CANARY`) |
| promover · gates | 200 `replayed` | 409 `invalid_transition` (`PUBLISHED`) |
| promover · transaction | 200 `replayed` | 409 `invalid_transition` (`PUBLISHED`) |
| revertir · gates | 200 `replayed` | 409 `not_active_publication` (`ROLLED_BACK`) |
| revertir · transaction | 200 `replayed` | 409 `invalid_transition` (`ROLLED_BACK`) |
| Legacy · gates | 200 `replayed` | 409 `already_legacy` |
| Legacy · transaction | 200 `replayed` | 409 `already_legacy` |

```
1) …UltronStudioPublicationRaceTest::test_el_reintento_que_cruza_la_confirmacion_de_la_primera_devuelve_su_entrada with data set "publish · gates"
El reintento de la MISMA petición (misma persona, mismo clientRequestId, mismo cuerpo) que cruzó la confirmación de la primera no devolvió su entrada: {"ok":false,"code":"invalid_transition","message":"Una versión en estado «Canario» no admite «publicar».","currentStatus":"CANARY","allowedFrom":["APPROVED"]}
…
7) … "legacy · gates" … {"ok":false,"code":"already_legacy","message":"La cadena ya está en Legacy: no declara ninguna versión."}
```

**Evidencia, natural** (20 envíos idénticos de Pablo, 20 sesiones, a la vez): `1×201, 14×200 replayed, 5×409 invalid_transition/CANARY` en la primera pasada, `17 y 2` en otra con más carga y `16 y 3` en la final; roja en 11 de las 12 pasadas en que corrió (en una, ningún hijo cayó en la ventana). Le pasa a un doble envío real, no solo a uno fabricado.

**Lo que sí cumple.** La segunda que llega a insertar mientras la primera no ha confirmado espera su índice, recibe el 23505 y lo resuelve fuera de la transacción abortada: 200 `replayed` (`test_el_doble_clic_que_espera_la_insercion_de_la_primera_recibe_replayed`, y la tormenta de §2: 19 de 19 `replayed` con las 19 esperando la entrada de la ganadora).

**Impacto.** Ninguno en los datos: en todos los casos queda una sola entrada, `seq` sin huecos, nada a medias en el libro, la cabeza verifica y el motor la aplica. Quien reintenta (doble clic, reintento de red, dos pestañas) recibe un error que describe lo que su propia petición acaba de hacer («Una versión en estado «Canario» no admite «publicar»»); en revertir y Legacy, las salidas de emergencia, `not_active_publication`/`already_legacy` justo cuando sí se hizo.

**Severidad: media** por contrato (incumple una garantía explícita de A5.1 paso 3 que el informe de la API da por cumplida; sin daño a datos). No es bloqueante para la integridad. `api-security-specialist` vio el camino leyendo el código (AS-4, «Nota», simulado en una conexión) y pidió esta carrera con dos sesiones: queda demostrada y en las cuatro escrituras.

**Arreglo posible (no aplicado; dueño: quien mantiene `StudioPublications`).** Antes de lanzar cualquier rechazo posterior al paso 3 (las puertas y lo que lanza la transacción: `invalid_transition`, `not_active_publication`, `canary_in_progress`, `already_legacy`, `publication_moved`), volver a llamar a `replay()` fuera de la transacción, igual que tras el 23505. Las 9 pruebas rojas lo verifican; la natural de 20 pasará a ser verde siempre (hoy es roja casi siempre y verde por azar si ningún hijo cae en la ventana).

---

## 4. Suite completa frente a `origin/main` (SQLite)

**Cómo.** Dos instantáneas `git archive` (`61048ff` y `0366009`) en el directorio temporal, cada una con `vendor/` copiado (no enlazado), `.env.testing` (600) y su propio `storage/`; la suite entera, una detrás de otra, el mismo día:

```
php -d memory_limit=2G vendor/bin/phpunit --do-not-cache-result --log-junit <logs>/junit-<lado>-sqlite.xml
[head-sqlite] inicio 2026-10-25T14:42:16Z   fin 2026-10-25T14:52:17Z exit=2
[base-sqlite] inicio 2026-10-25T14:52:17Z   fin 2026-10-25T15:07:01Z exit=2
HEAD: Tests: 10841, Assertions: 76358, Errors: 55, Failures: 2, PHPUnit Deprecations: 8, Skipped: 34.
base: Tests: 10137, Assertions: 72572, Errors: 55, Failures: 2, PHPUnit Deprecations: 8, Skipped: 30.
```

Día 25 (el test de Reports que cae los días 1–10 no aplica). Nombres sacados del junit (`//testcase[failure or error]`, «Clase::prueba [conjunto de datos]») y comparados con `comm`:

| | HEAD | base |
|---|---|---|
| Nombres en rojo | 57 | 57 |
| En rojo en HEAD y no en la base | **0** | — |
| En rojo en la base y no en HEAD | — | 0 |

**La rama no añade ningún rojo.** Los 57 son los mismos nombres en los dos lados: `MemberContractTest` 29, `Billing\FiscalReconciliationTest` 12, `Billing\FactusPayloadNoVatTest` 10, `Billing\ProductionEmissionGuardTest` 2, `Billing\EmissionBlockedTest` 2, `Security\SecretExposureTest` 1 y `Billing\PricingV2SalesAndPaymentsTest` 1. 55 están en `tests/baseline-failures.txt`; los otros dos son del entorno, no de la rama:
- `MemberContractTest::test_register_with_the_email_of_another_member_creates_its_own_account`: falta `storage/app/private/contract_templates/source/basic_registration.pdf` (fichero ignorado; tampoco está en el worktree de este Mac: allí caen las mismas 29, comprobado), igual que las otras 28 de la clase, que sí están en la lista.
- `SecretExposureTest::test_git_never_tracks_environment_files_with_secrets`: la instantánea no es un repo git (`git check-ignore` sale 128). En el worktree real pasa (comprobado: `tests/Feature/Security/SecretExposureTest.php` verde).

**Omitidas:** 4 más en HEAD, todas de solo-PG y nuevas de H5 (`UltronStudioAuditTest::…repeatable_read`, 3 de `UltronStudioPublicationSchemaTest::test_solo_en_postgres_…`). Las 38 de este informe no estaban en la instantánea (sin confirmar); en SQLite se omiten igual.

**Pruebas de la base que ya no existen en HEAD (5)**, todas sustituidas por el contrato y verdes en HEAD con su nombre nuevo: `…PermissionsTest::test_el_mapa_resuelve_exactamente_las_33_rutas…` y `…test_sin_sesion_es_401_en_las_33` (→ 38), `UltronEngineIsolationTest::test_el_motor_no_lee_versiones…` (→ `…lee_solo_con_su_llave…`), `ExportStudioFixturesCommandTest::…four_files…` (→ `…five_files…`) y `UltronStudioVersionLifecycleTest::test_publicar_y_revertir_responden_409_siempre…` (el 409 provisional de F, retirado por A5).

---

## 5. Puerta PostgreSQL 14: Studio y Marketing, HEAD frente a `origin/main`

**Cómo.** Las mismas dos instantáneas (en la de HEAD, copiadas las dos pruebas nuevas, sha256 comprobado), PostgreSQL 14.24 desechable en `127.0.0.1:55432`, bases nuevas y propias (`ultron_studio_h5_tester_head`, `ultron_studio_h5_tester_base`), **una suite cada vez**, cada instantánea con su `storage/` (memoria «Suites de Marketing en paralelo»):

```
PGGSSENCMODE=disable UNIT_DB=<base> bash docs/ultron-studio/ops/pgunit.sh --do-not-cache-result --log-junit <junit> <rutas>
[head-pg-studio]    16:02:55Z → 16:10:57Z  Tests: 1441, Assertions: 21918, Failures: 9.
[head-pg-marketing] 16:10:57Z → 16:19:23Z  Tests: 4977, Assertions: 21667, Errors: 7, Failures: 3.
[base-pg-studio]    16:19:23Z → 16:22:34Z  Tests: 946,  Assertions: 15841, Failures: 1.
[base-pg-marketing] 16:22:34Z → 16:33:59Z  Tests: 4730, Assertions: 21110, Errors: 7, Failures: 3.
```

| Suite (PG) | Rojos en HEAD | Rojos en la base | En HEAD y no en la base |
|---|---|---|---|
| `tests/Feature/UltronStudio tests/Unit/UltronStudio` | 9 | 1 | **las 9 de H-1**, todas de las pruebas nuevas de este informe (no existen en la base). De lo que ya existía: **0** |
| `tests/Feature/Marketing tests/Unit/Marketing` | 10 | 10 | **0** (los mismos 10 nombres) |

- **Studio, el rojo de la base:** `UltronStudioHardeningTest::test_una_lista_por_encima_de_su_tope_es_422_solo_por_el_tope_y_sin_recorrerla` («`refs.knowledgeKeys` tardó 50.6 ms en rechazarse», umbral 50 ms). En la rama ese umbral es 150 ms (ajuste del lead, `decisiones-lead-h5.md`: ya fallaba en la base con 52 ms); en HEAD pasa. Es tiempo, no lógica.
- **Studio en HEAD, 0 omitidas:** en PG corren también las 18 de solo-PG que ya había y las 38 nuevas.
- **Marketing, los 10 preexistentes por nombre** (idénticos en los dos lados; ninguno toca código de H5):
  - `LeadNeedsHumanLifecycleTest` ×5 (`test_escalation_marks_the_lead_and_release_restores_what_it_was`, `test_after_release_the_model_has_no_standing_permit_to_hand_off`, `test_the_remembered_status_is_consumed_and_never_restored_twice`, `test_another_conversation_still_in_human_hands_keeps_the_request_open`, `test_the_official_release_also_ends_an_active_takeover`): 22P02, un `uuid` alimentado con «lifecycle»;
  - `PaymentLinkHardGateTest::test_the_commercial_tool_cannot_mint_with_the_flag_off`: 22P02 («hard-gate-1» en un `uuid`);
  - `UltronCommitTest::test_allowed_v1_tools_are_executed` y `::test_staff_review_without_a_cause_is_not_granted`, `UltronIncidentTest::test_el_incidente_guarda_hilos_de_investigacion_no_contenido`: arrays en otro orden;
  - `UltronPresenceTest::test_a_redelivered_inbound_does_not_signal_twice`: 25P02.

---

## 6. Mutaciones

**Cómo.** `docs/ultron-studio/ops/h5-mutations.php` (en el repo), siempre sobre una COPIA (`git archive 61048ff` + vendor + `.env.testing` + las dos pruebas nuevas), nunca sobre el worktree (memoria «mutar en copia»; el arnés se niega a correr sobre un árbol de git). Por mutación: sustituciones EXACTAS (cada cadena aparece una sola vez: `MUT_CHECK=1` lo comprueba; 25/25), `php -l` de cada fichero tocado (22/22 «ok»), sus pruebas, y restauración en `finally` con sha256 comprobado (22/22 restauradas con el sha256 original; el arnés sale 3 si no). Antes de mutar, una línea base SIN mutación de cada ejecución: una mutación «cae» solo por rojos NUEVOS (las 9 de H-1 ya están rojas). Huella de `app/ tests/ config/ database/ routes/` de la copia antes y después de las 22: `5f7ea01d365e…9ccc` las dos, **idéntica**. Salida del arnés:

```
[A1] … CAE (33) · sqlite FAILURES! | Tests: 1437, Failures: 29 (65 s) · pgsql FAILURES! | Tests: 4, Failures: 4 (11 s)
[A2] … CAE (53) · sqlite FAILURES! | Tests: 1654, Failures: 53 (57 s)
[B]  … CAE (6)  · sqlite Failures: 3 · pgsql Failures: 3
[C1] … CAE (1) · [C2] … SOBREVIVE · [D] … CAE (1) · [E] … CAE (3) · [F1] … CAE (1) · [F2] … CAE (2)
[G]  … CAE (13) · [H] … CAE (1) · [I1] … SOBREVIVE · [I2] … CAE (2)
[J1] … sqlite OK · pgsql ERRORS! Errors: 12, Failures: 4  (falso «cae»: ver abajo)
[J2] … CAE (1) · [K1] … CAE (1) · [K2] … CAE (1) · [K3] … CAE (3) · [K4] … CAE (1) · [K5] … CAE (2) · [K6] … CAE (1) · [K7] … CAE (1)
huella antes  5f7ea01d365ef48b8e4c826ffdf6b34fcbcb71b0916c28fc3fbaa34c09d29ccc
huella después 5f7ea01d365ef48b8e4c826ffdf6b34fcbcb71b0916c28fc3fbaa34c09d29ccc
IDÉNTICA
```

Segunda pasada, con el arnés ya en el repo y el fichero de carreras final (pausas por tabla, O-1), de las cuatro que tocaban las pausas: `K1 CAE (2) · K3 CAE (3) · K5 CAE (2) · J1 SOBREVIVE`; huella `9d80662bc5c2…4832` antes y después, idéntica.

| # | Mutación | Resultado | Qué la tumba (rojos nuevos) |
|---|---|---|---|
| **A1** | Firmar con OTRA subclave en el Studio (la etiqueta de otro dominio, la `APP_KEY` sin decodificar); el lector verifica con la suya | **cae** (33: SQLite 29, PG 4) | `…PublicationLifecycleTest::test_publicar_en_canario_firma_la_entrada_y_el_lector_del_motor_la_aplica`, `…test_una_cabeza_que_no_verifica…`, las de promover/revertir/Legacy encima de una cabeza propia (28 distintas en SQLite) y en PG `…RaceTest::test_la_que_espera_el_seq…`, `…promover_contra_revertir…` ×2, `…veinte…distintos…` (la cadena no verifica) |
| **A2** | La subclave COMPARTIDA derivada con otra etiqueta (firmante y lector de acuerdo, distintos del contrato) | **cae** (53) | Solo los vectores del contrato: `Unit\Marketing\UltronPublishedConfigTest::test_el_lector_cumple_el_fixture_del_contrato` ×42, `…test_la_firma_cumple_el_fixture_del_contrato` ×10, `…test_la_firma_es_la_del_contrato_con_vectores_calculados_fuera_de_php`. Ninguna prueba del Studio lo ve (firmante y lector siguen de acuerdo): es lo esperado, y el fixture lo cubre |
| **B** | `seq` = cabeza + 2 | **cae** (6) | `…LifecycleTest::test_publicar_en_canario_firma…`, `…test_promover_pasa_la_misma_parte…`, `…test_la_carrera_por_la_cabeza…`; en PG `…RaceTest` (`assertChainVerifies`: «seq con huecos») ×3 |
| **C1** | Componer sin verificar la cabeza (sus partes, sin contrato, huella ni firma) | **cae** (1) | `…LifecycleTest::test_una_cabeza_que_no_verifica_no_se_copia_y_legacy_es_la_salida` — una sola prueba la sostiene |
| **C2** | `verify()` del Studio sin comparar la huella (contrato y firma sí) | **SOBREVIVE** | Ninguna: no hay prueba con una cabeza de firma buena y huella tocada. Hallazgo B-1 (§7) |
| **D** | La evidencia cuenta turnos de cualquier conversación | **cae** (1) | `…CanaryTest::test_los_turnos_y_su_reparto_salen_de_una_sola_consulta_y_solo_cuentan_los_del_canario` |
| **E** | `evidenceHash` ignorado al promover | **cae** (3) | `…CanaryTest::test_promover_exige_la_misma_evidencia_que_se_vio`, el caso del fixture «evidenceHash viejo → evidence_changed» por HTTP y en la paridad pura |
| **F1** | `restore_integrity_mismatch` desactivado (1): la entrada anterior a la promoción no se verifica | **cae** (1) | `…RollbackTest::test_si_la_entrada_de_la_que_volveria_esta_alterada_tampoco_se_restaura` |
| **F2** | `restore_integrity_mismatch` desactivado (2): la versión que vuelve no se comprueba íntegra | **cae** (2) | `…RollbackTest::test_una_restauracion_que_no_verifica_es_409_y_legacy_es_la_salida` y su caso del fixture por HTTP |
| **G** | La ventana leída en UTC en vez de Bogotá | **cae** (13) | 6 casos del fixture por HTTP, 6 en la paridad pura y `…LifecycleTest::test_la_vista_previa_tiene_la_forma_del_contrato…` |
| **H** | `clientRequestId` no ligado a la persona | **cae** (1) | `…LifecycleTest::test_publicar_y_promover_son_idempotentes_por_client_request_id` — una sola prueba |
| **I1** | La vista previa calcula `previewHash` con la versión del catálogo de HOY, no con la de la versión | **SOBREVIVE** | Ninguna: todas las versiones de las pruebas de la publicación son del catálogo de hoy. Hallazgo B-2 (§7) |
| **I2** | `previewHash` (núcleo) sin `catalogVersion` | **cae** (2) | `Unit\…StudioPublicationDeclarationTest::test_preview_hash_es_la_huella_canonica_del_objeto_de_q4` y `…es_estable_y_cambia_con_todo…` |
| **J1** | Sin el tope de 16 385 caracteres al leer la cabeza en el Studio | **SOBREVIVE** (por comportamiento) | Ninguna. SQLite: 1437 verdes (1.ª pasada) y 1441 verdes (2.ª, con el fichero final). PG, 2.ª pasada con el fichero final: 0 rojos nuevos (8 rojos, todos de H-1). En la 1.ª pasada, en PG, «cayeron» 7 carreras, pero por acoplamiento (O-1): sus pausas buscaban el texto `substr(effective_json…`, que la mutación quita, y ninguna llegó a pararse. Hallazgo B-3 (§7) |
| **J2** | Sin el tope de 16 385 al leer la cabeza en el motor | **cae** (1) | `Unit\Marketing\UltronPublishedConfigTest::test_una_declaracion_enorme_no_se_trae_entera` |
| **K1** | Publicar: sin reintento tras el 23505 | **cae** (2, repetida con el fichero final) | `…RaceTest::test_el_doble_clic_que_espera_la_insercion_de_la_primera_recibe_replayed` (B: 409 `publication_moved`) y la tormenta «la misma petición 20 veces» (19×`publication_moved` en vez de 19×`replayed`) |
| **K2** | El 23505 de `usv_one_canary` leído como `publication_moved` | **cae** (1) | `…RaceTest::test_el_indice_usv_one_canary_…` (SQLite no lo ve: allí no hay dos sesiones) |
| **K3** | Publicar lee la cabeza ANTES de abrir la transacción | **cae** (3, repetida con el fichero final, por su resultado) | `…RaceTest::test_la_que_lee_la_cabeza_despues_de_la_confirmacion_rival_no_compone_encima` ×2 y `…test_publicar_encima_de_un_legacy_recien_confirmado_compone_con_la_cabeza_nueva`: la perdedora compone sobre la cabeza VIEJA, choca en `seq` y responde `publication_moved` en vez de `canary_in_progress`/`invalid_transition`/201. (En la primera pasada «cayó» por el acoplamiento O-1; ya no) |
| **K4** | Legacy lee la CANARY y la PUBLISHED antes de la transacción | **cae** (1) | `…RaceTest::test_volver_a_legacy_justo_despues_de_una_publicacion_retira_tambien_la_canaria_recien_confirmada` (V queda en CANARY con la cabeza en Legacy: la invariante de A2 lo ve) |
| **K5** | Publicar sin transacción (entrada, transición y libro en autocommit) | **cae** (repetida con el fichero final) | `…RaceTest::test_el_indice_usv_one_canary_…`: la entrada se queda escrita aunque la transición falle, y el reintento tras el 23505 la encuentra y responde **200 `replayed`** a una publicación a medias (la prueba exige 409 y 0 entradas). La otra (`…la_que_espera_el_seq…`) cae con error, no por resultado: su pausa vive dentro de la transacción que la mutación quita (falla en voz alta, O-1) |
| **K6** | `recordDenied` sin `READ COMMITTED` (la M7 del informe de la API, contra las pruebas nuevas) | **cae** (1) | `…RaceTest::test_record_denied_en_repeatable_read_espera_el_cerrojo_rival_y_ve_su_fila_al_soltarlo` (dos filas). La natural de 20 sesiones **sobrevive**: sin pausa, las 20 no coinciden en el cerrojo lo bastante; la guarda es la forzada |
| **K7** | Promover elige la PUBLISHED que sustituye ANTES de la transacción | **cae** (1) | `…RaceTest::test_promover_justo_despues_de_revertir_la_publicada_sustituye_a_la_que_acaba_de_volver` (intenta retirar a P1, que ya no está PUBLISHED → 409) |

**Balance:** de 22, **19 caen y 3 sobreviven** (C2, I1, J1). Las 3 son huecos de cobertura de severidad baja (§7), no defectos del código: el código hace lo correcto y ninguna prueba lo fija.

**Las cadenas exactas** (abreviadas a una línea; las completas, en `h5-mutations.php`):

```
A1 · StudioPublications.php
   - UltronPublishedConfig::signatureFor(...)); $yo = $access->snapshot();
   + static fn (string $j): string => hash_hmac('sha256', UltronPublishedConfig::CONTRACT."\n".$j, hash_hmac('sha256', 'ultron-decide-token', (string) config('app.key'), true))); $yo = $access->snapshot();
A2 · UltronPublishedConfig.php
   - private const KEY_LABEL = 'ultron-studio-publication';
   + private const KEY_LABEL = 'ultron-studio-publication-v2';
B · StudioPublications.php
   - $seq = ($cabeza !== null ? (int) $cabeza->seq : 0) + 1;
   + $seq = ($cabeza !== null ? (int) $cabeza->seq : 0) + 2;
C1 · StudioPublications.php
   - return self::verify($cabeza) ?? throw UltronStudioException::publicationHeadInvalid();
   + return D::parts((string) $cabeza->effective_json, (string) $cabeza->uuid, (int) $cabeza->seq);
C2 · StudioPublications.php
   - || ! hash_equals(D::effectiveHash($json), (string) $fila->effective_hash)
   + (se quita)
D · StudioCanaryMeasures.php
   - ->where('conversation_id', $conversacion)
   + (se quita)
E · StudioPublications.php
   - $rechazo = StudioCanaryEvidence::refusal($evaluation, $sentEvidenceHash);
   + $rechazo = StudioCanaryEvidence::refusal($evaluation, $evaluation['evidenceHash']);
F1 · StudioPublications.php
   - $partes = $anterior !== null ? self::verify($anterior) : null;
   + $partes = $anterior !== null ? D::parts((string) $anterior->effective_json, (string) $anterior->uuid, (int) $anterior->seq) : null;
F2 · StudioPublications.php
   - || ! StudioVersions::integrityOk($vuelve)
   + (se quita)
G · StudioPublishing.php
   - $local = CarbonImmutable::instance($now)->setTimezone(self::TIMEZONE);
   + $local = CarbonImmutable::instance($now)->setTimezone('UTC');
H · StudioPublications.php
   - if ((int) $previa->actor_admin_id !== $access->id() || $previa->action !== $action
   + if ($previa->action !== $action
I1 · StudioPublications.php
   - $huella = D::previewHash((string) $version->uuid, (string) $version->content_hash, (string) $version->catalog_version, $clasificacion,
   + $huella = D::previewHash((string) $version->uuid, (string) $version->content_hash, StudioCatalog::VERSION, $clasificacion,
I2 · StudioPublicationDeclaration.php
   - 'catalogVersion' => $catalogVersion, 'mustMatch' => self::listOf($classification, 'mustMatch'),
   + 'mustMatch' => self::listOf($classification, 'mustMatch'),
J1 · StudioPublications.php
   - ->selectRaw('substr(effective_json, 1, '.(D::MAX_EFFECTIVE_JSON + 1).') as effective_json');
   + ->selectRaw('effective_json');
J2 · UltronPublishedConfig.php
   - ->selectRaw('substr(effective_json, 1, '.(self::MAX_EFFECTIVE_JSON + 1).') as effective_json')
   + ->selectRaw('effective_json')
K1 · StudioPublications.php
   - // FUERA de la transacción (en PostgreSQL un 23505 la deja abortada). if ($previa = $this->replay($access, D::ACTION_PUBLISH, $uuid, $clientRequestId, $cuerpo)) { return ['entry' => $previa, 'replayed' => true]; }
   + // FUERA de la transacción (en PostgreSQL un 23505 la deja abortada).
K2 · StudioPublications.php
   - if (str_contains($mensaje, 'usv_one_canary') || ($estado && $action === D::ACTION_PUBLISH)) { return UltronStudioException::canaryInProgress();
   + if (str_contains($mensaje, 'usv_one_canary') || ($estado && $action === D::ACTION_PUBLISH)) { return UltronStudioException::publicationMoved();
K3 · StudioPublications.php
   - try { $fila = DB::transaction(function () use ($access, $request, $version, $clientRequestId, $h, $acknowledged, $note): StudioPublication { $ahora = now(); $cabeza = $this->head();
   + $cabezaFuera = $this->head(); try { $fila = DB::transaction(function () use ($access, $request, $version, $clientRequestId, $h, $acknowledged, $note, $cabezaFuera): StudioPublication { $ahora = now(); $cabeza = $cabezaFuera;
K4 · StudioPublications.php
   - $this->assertDeclaring($this->head()); try { $fila = DB::transaction(function () use ($access, $request, $clientRequestId, $reason): StudioPublication {
   + $this->assertDeclaring($this->head()); $canarioFuera = StudioVersion::query()->where('status', StudioVersionStatus::Canary->value)->first(['id', 'uuid', 'label', 'status', 'content_hash']); $publicadaFuera = StudioVersion::query(…
K4 · StudioPublications.php
   - $canario = StudioVersion::query()->where('status', StudioVersionStatus::Canary->value)->first(['id', 'uuid', 'label', 'status', 'content_hash']); $publicada = StudioVersion::query()->where('status', StudioVersionStatus::Published…
   + $canario = $canarioFuera; $publicada = $publicadaFuera; $fila = $this->insertEntry(D::ACTION_LEGACY
K5 · StudioPublications.php
   - $fila = DB::transaction(function () use ($access, $request, $version, $clientRequestId, $h, $acknowledged, $note): StudioPublication {
   + $fila = (function () use ($access, $request, $version, $clientRequestId, $h, $acknowledged, $note): StudioPublication {
K5 · StudioPublications.php
   - return $fila; }); } catch (UniqueConstraintViolationException $e) { // FUERA de la transacción
   + return $fila; })(); } catch (UniqueConstraintViolationException $e) { // FUERA de la transacción
K6 · StudioAudit.php
   - DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
   + (se quita)
K7 · StudioPublications.php
   - try { $fila = DB::transaction(function () use ($access, $request, $version, $clientRequestId, $evidencia, $note): StudioPublication {
   + $anteriorFuera = StudioVersion::query()->where('status', StudioVersionStatus::Published->value)->first(['id', 'uuid', 'label', 'status', 'content_hash']); try { $fila = DB::transaction(function () use ($access, $request, $version…
K7 · StudioPublications.php
   - $anterior = StudioVersion::query()->where('status', StudioVersionStatus::Published->value)->first(['id', 'uuid', 'label', 'status', 'content_hash']); $fila = $this->insertEntry(D::ACTION_PROMOTE
   + $anterior = $anteriorFuera; $fila = $this->insertEntry(D::ACTION_PROMOTE
```

---

## 7. Hallazgos

| # | Severidad | Qué | Dónde | Prueba que lo demuestra |
|---|---|---|---|---|
| **H-1** | **Media** (contrato; sin daño a datos) | El reintento de la MISMA petición que cruza la confirmación de la primera recibe 409 en vez de 200 `replayed`, en las cuatro escrituras (§3). Es AS-4 de `api-security-specialist`, ahora con dos sesiones de PG | `StudioPublications.php:138/:211`, `:237/:307`, `:336/:400`, `:426/:476` (el reintento solo se mira al principio y tras un 23505) | `test_el_reintento_que_cruza_la_confirmacion_de_la_primera_devuelve_su_entrada` (8 casos, deterministas) y `test_veinte_publicaciones_simultaneas_con_el_mismo_client_request_id_…` (natural) — **rojas a propósito** |
| B-1 | Baja (cobertura) | Nada fija que el Studio compare la HUELLA de la cabeza antes de componer: con la comparación quitada (C2), las 1437 pruebas del Studio siguen verdes. Una cabeza con la firma buena y la columna `effective_hash` tocada a mano (DML sin `APP_KEY`) la rechaza el motor (`fallback/hash_mismatch`) y, sin esa línea, el Studio compondría encima | `StudioPublications::verify()`, `:1216` | Superviviente C2 (§6). Prueba propuesta abajo |
| B-2 | Baja (cobertura) | Nada fija que la vista previa use el `catalogVersion` DE LA VERSIÓN en `previewHash` (Q-4, decisión del lead para B-S): con el de hoy (I1), todo verde, porque ninguna prueba de la publicación usa una versión de un catálogo anterior. El núcleo sí está fijado (I2 cae). Efecto visible hoy, ninguno: la vista previa y publicar calculan igual | `StudioPublications::facts()`, `:999` | Superviviente I1 (§6) |
| B-3 | Baja (cobertura) | Nada fija el tope de 16 385 caracteres al leer la cabeza en el Studio (el del motor sí: J2 cae). Sin él (J1), una cabeza de más de 16 384 caracteres con su huella y su firma de los bytes enteros la vería el motor como `hash_mismatch` y el Studio como buena (y una fila enorme escrita a mano se leería entera en cada `GET status`) | `StudioPublications::withJson()`, `:1185` | Superviviente J1 (§6) |
| O-1 | Nota (de las pruebas nuevas) | Las pausas de las carreras se eligen por la TABLA de la sentencia (`"ultron_studio_publications"`, la primera dentro de la transacción) y no por el texto de la consulta. La primera versión las elegía por `substr(effective_json…`, y una mutación de esa consulta (J1) las dejaba sin disparar: «cae» falso. Corregido antes de entregar; si alguien cambia de sitio esas lecturas, la prueba falla en voz alta («la sesión … terminó sin llegar a PAUSED»), nunca en verde | `UltronStudioPublicationRaceTest::FIRST_CHAIN_STATEMENT` / `HEAD_READ` | J1, K3 y K5 repetidas con el fichero final (§6) |

**Pruebas propuestas para los supervivientes** (no las he añadido: el encargo limita mis ficheros nuevos al de carreras y su ayudante; cada una cabe en el fichero de pruebas de su tema y usa `PublicationFixtures`):

- **C2** (en `UltronStudioPublicationLifecycleTest`): una cabeza con la firma buena sobre sus bytes y la columna de la huella tocada a mano no se copia.
  ```php
  $this->importBaseline();
  $this->openKeys();
  $v = $this->approvedVersion($ana, $root);
  $this->seedEntry(D::ACTION_LEGACY, null, null, null, ['effective_hash' => str_repeat('0', 64)]);
  $this->assertSame(['fallback', 'hash_mismatch'], [$this->engineStamp(self::CANARY_ID)['state'], $this->engineStamp(self::CANARY_ID)['reason']]);
  $this->publishAs($root, $v->uuid)->assertStatus(409)->assertJsonPath('code', 'publication_head_invalid');
  $this->assertSame('fallback', $this->studio('GET', 'status', $root)->json('data.engine.publication.state'));
  ```
- **J1** (ídem): una cabeza de más de 16 384 caracteres, con su huella y su firma de los bytes ENTEROS (`issuedAt` de 20 000 caracteres: claves y tipos exactos), la ve el motor como `hash_mismatch` (lee 16 385) y el Studio tiene que verla igual: `publish` → 409 `publication_head_invalid`, `status` → `fallback`. Hoy, sin el tope en el Studio, compondría encima de una cabeza que el motor no aplica.
- **I1** (en `UltronStudioPublicationLifecycleTest` o en la vista previa): con una versión de F (`seedFormat1Version(…, 'APPROVED')` y su reseña), `previewHash` de `GET publication-preview` = `D::previewHash($uuid, $hash, '2026-10-18.1', <la clasificación de facts()>, <labGate>)`. Es la fórmula de Q-4 con el `catalogVersion` de la versión; con el de hoy, la vista previa y publicar siguen de acuerdo entre sí, así que el efecto visible es nulo (por eso sobrevive y por eso es baja).

**Lo que se buscó y no está:**
- **Ninguna transacción abortada por un 23505 deja nada a medias** (lo que pedía el encargo): ni entrada, ni transición, ni evento del libro, ni con dos sesiones ni con 19 abortando a la vez (tormenta), ni por `seq` ni por `usv_one_canary`; la conexión de la perdedora sigue sana (resuelve el reintento o el 409 fuera, sin 25P02).
- **Ningún orden deja la cadena y los estados en desacuerdo**, tampoco los dos órdenes «tardíos» que componen sobre una cabeza recién movida (publicar encima de un Legacy, Legacy tras una publicación, promover tras una restauración): todas las lecturas que deciden (cabeza, CANARY y PUBLISHED de ahora) se hacen dentro de la transacción, después de leer la cabeza; las mutaciones K3, K4 y K7, que las sacan fuera, tumban esas pruebas.
- **`recordDenied` en REPEATABLE READ:** con 20 sesiones a la vez y con un rival que tiene el cerrojo y la fila sin confirmar, queda una sola fila; sin el `READ COMMITTED` (K6), dos.
- **REPEATABLE READ en toda la sesión:** la cadena aguanta (29/29 no-H-1, 184 sesiones).
- **Ningún 5xx** en ninguna carrera (los hijos responden siempre JSON con su código).

---

## 8. Lo que NO he verificado

- **Un servidor web de verdad.** Las sesiones rivales mandan la petición por el kernel HTTP dentro de procesos de CLI; no hay php-fpm, ni nginx, ni red entre medias. El middleware, la sesión, el controlador y la transacción son los reales; la capa del SAPI no. El E2E con la pila real (A10.5) es otro encargo.
- **El rol `iron_app` de producción.** Todo corre como el dueño de las tablas (`ironbody`) en el clúster desechable PostgreSQL 14.24. Los cerrojos consultivos y `pg_blocking_pids` no dependen del rol, y la cadena solo necesita DML; no lo he ejecutado con `iron_app`.
- **SERIALIZABLE.** Sí probé las carreras con TODAS las sesiones en REPEATABLE READ (`ULTRON_STUDIO_RACE_ISOLATION`, §2): las mismas 29 verdes. SERIALIZABLE no (nadie lo usa; hipótesis sin comprobar: en ese nivel algún conflicto podría llegar como 40001 en vez de 23505, y `conflictRefusal()` no lo trataría).
- **Los tiempos.** Las pruebas no miden latencias. El fichero de carreras tarda entre 1 min 46 s y 5 min 14 s según la carga de la máquina, sin cambiar de resultado; cada prueba recrea el esquema (es lo que más pesa). **Coste para la puerta obligatoria:** el Studio en PG pasa de ~1 min (informe de la API) a 3–8 min. Las pausas esperan hasta 15 s (60 s en la tormenta) a que la rival espere; si una máquina muy lenta no llega, la prueba falla con «la carrera no ocurrió», nunca en verde.
- **Lo que dejó el E2E** (`informe-tester-e2e.md` §7): las carreras de dos sesiones y AS-4 son este informe; «revertir una PUBLISHED que restaura otra RETIRED» queda cubierto aquí por las carreras 23–26, por el kernel HTTP y sin la pila real.
- **El arreglo de H-1.** No lo he hecho ni probado (no es mío); las 9 pruebas rojas son la verificación.
- **Las 15 mutaciones de §6.11 del informe de la API** no las he repetido (salvo M7 contra las pruebas nuevas, K6). Tampoco las de B-M sobre el motor, salvo J2 y A2, que tocan la frontera de la firma y el tope de lectura.

---

## 9. Estado del árbol, ficheros y cómo repetirlo

**Sin commit ni push.** Ficheros NUEVOS (ninguno existente tocado):

| Fichero | Qué |
|---|---|
| `tests/Feature/UltronStudio/UltronStudioPublicationRaceTest.php` | Las 38 carreras (solo PG; en SQLite se omiten) |
| `tests/Feature/UltronStudio/PublicationRaceSessions.php` | El trait de las sesiones rivales: hijos con el kernel HTTP, barrera, pausas, rival crudo, rastro opcional |
| `docs/ultron-studio/ops/suite-compare.sh` | La suite de la rama contra su base el mismo día, en instantáneas, comparada por nombre (SQLite, o PG con `SUITE_PG_DB_*`) |
| `docs/ultron-studio/ops/h5-mutations.php` | El arnés de §6 con las 22 mutaciones; se niega a correr sobre un árbol de git (solo en copia) |
| `docs/ultron-studio/plans/informes-h5/informe-tester-backend.md` | Este informe |

Los dos de `ops/` están ahí porque el encargo pide que lo reutilizable vaya al repo (el directorio temporal se purga a los tres días); si el lead prefiere no confirmarlos, nada depende de ellos.

**No son míos y no los he tocado** (aparecieron sin seguimiento mientras trabajaba): `docs/ultron-studio/ops/smoke-h5.py`, `smoke-run-h5.sh`, `plans/informes-h5/export/`, `informe-api-security.md`, `informe-security.md`, `informe-devops-h5.md`, `informe-tester-e2e.md`.

**Huella del worktree:** `git diff --binary | shasum -a 256` = `e3b0c442…b855` (el diff vacío) al empezar y al terminar: ningún fichero con seguimiento cambió. Las mutaciones se hicieron en una copia (`git archive` + vendor), nunca en el worktree (memoria «mutar en copia»: otros agentes corren pruebas en este árbol).

**Bases desechables** creadas en `127.0.0.1:55432` (nunca producción): `ultron_studio_h5_races` (carreras), `ultron_studio_h5_mut` (mutaciones), `ultron_studio_h5_tester_head` y `ultron_studio_h5_tester_base` (puerta PG de cada lado). Se pueden borrar.

**Cómo repetirlo:**
```
# carreras (desde backend/)
PGGSSENCMODE=disable UNIT_DB=ultron_studio_h5_races bash docs/ultron-studio/ops/pgunit.sh tests/Feature/UltronStudio/UltronStudioPublicationRaceTest.php
#   … con el rastro de cada sesión y todas en REPEATABLE READ:
ULTRON_STUDIO_RACE_LOG=/ruta/rastro.jsonl ULTRON_STUDIO_RACE_ISOLATION='repeatable read' PGGSSENCMODE=disable UNIT_DB=… bash docs/ultron-studio/ops/pgunit.sh tests/Feature/UltronStudio/UltronStudioPublicationRaceTest.php
# suite contra la base, el mismo día
docs/ultron-studio/ops/suite-compare.sh /ruta/vacia 0366009 HEAD
SUITE_EXTRA="tests/Feature/UltronStudio/UltronStudioPublicationRaceTest.php tests/Feature/UltronStudio/PublicationRaceSessions.php" \
  SUITE_PG_DB_HEAD=ultron_studio_x SUITE_PG_DB_BASE=ultron_studio_y docs/ultron-studio/ops/suite-compare.sh /ruta/vacia2 0366009 HEAD -- tests/Feature/UltronStudio tests/Unit/UltronStudio
# mutaciones, en una copia
git archive HEAD backend | tar -x -C /ruta/copia && rsync -a backend/vendor/ /ruta/copia/backend/vendor/ && cp -p backend/.env.testing /ruta/copia/backend/
cp -p backend/tests/Feature/UltronStudio/{UltronStudioPublicationRaceTest,PublicationRaceSessions}.php /ruta/copia/backend/tests/Feature/UltronStudio/
MUT_PG_DB=ultron_studio_h5_mut php backend/docs/ultron-studio/ops/h5-mutations.php /ruta/copia/backend /ruta/salida [ids…]
```

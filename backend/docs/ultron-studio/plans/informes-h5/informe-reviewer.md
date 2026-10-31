# Hito 5 · informe del reviewer (final)

**Fecha:** 31-10-2026, ~21:30 UTC. **Revisión independiente, de solo lectura:** este es el único fichero escrito; sin commit ni push.

- **Backend** (`_worktrees/thermal-receipts/Iron-Body`, rama `feat/ultron-studio-h5`): HEAD `cf59cef` = `cdb9dc8` (fusión de `origin/main` `7318122` sobre `96397ea`) + `cf59cef` (m-2 y `PREVIO=7318122`, confirmado por el lead durante esta revisión). Diff del hito: `git diff 0366009...96397ea`; delta revisado aparte: `96397ea..cf59cef`.
- **CRM** (`_worktrees/thermal-receipts/Iron-Body_Front`, rama `feat/ultron-studio-h5`): HEAD `9fe1817` = fusión de `origin/main` `4105016` sobre `a29bcc8`. Diff del hito: `git diff 5c1dbd7...a29bcc8`.
- Estado del árbol del backend al escribir: `ULTRON_STUDIO_DEPLOYMENT_PLAN.md` y `ULTRON_STUDIO_ROLLBACK.md` tienen cambios sin confirmar de otro agente (no revisados: segunda pasada). `plans/informes-h5/export/` está sin seguimiento a propósito, y es idéntico a lo que `export-fixtures` genera hoy.

## Veredicto

**APROBADO CON OBSERVACIONES.**

- **Código del Hito 5 (backend y CRM): sin bloqueantes.** Los criterios 1–6 y 9 se cumplen en todo lo que pude ejecutar. H-1 (`orReplayed()`) no debilita ninguna puerta y m-2 está corregido.
- **Antes de ejecutar la fase 1** hay que cerrar tres puntos de operación. Ninguno es un defecto de lo que se despliega, y cada uno se arregla con una línea o con una comprobación de solo lectura:
  - **I-2:** un tercer argumento mal escrito convierte el ensayo `--solo-comprobar` en el despliegue real.
  - **I-3** (a confirmar en el servidor): que `www-data` pueda escribir todo lo que el `merge` va a tocar. Si no, el `merge` falla a medias y deja código mezclado.
  - **I-4:** la vuelta atrás del código de `ULTRON_STUDIO_ROLLBACK.md` apunta a `fef7c87` y desharía en producción el trabajo de main.
- **Antes de la fase 2: I-1.** En el CRM, un segundo «Volver a Legacy» con el mismo motivo en la misma pestaña no se ejecuta, y el CRM dice que la cadena no declara nada. Conviene corregirlo ya para desplegar el CRM una sola vez.
- **Pendiente:**
  - la segunda pasada de la documentación que se está escribiendo ahora;
  - los resultados de las puertas del lead, que no repetí.

## Verificado (ejecutado aquí, salida real)

| Comando | Resultado |
|---|---|
| Backend: `git diff --stat 0366009...96397ea -- app/Services/Marketing app/Jobs app/Http/Controllers/Api/Internal routes config/marketing.php` | 4 ficheros: `UltronCommitService.php` (58 líneas), `UltronPublishedConfig.php` (+501), `config/marketing.php` (+18) y `routes/ultron-studio.php` (24). Con `0366009` → `cdb9dc8` o `cf59cef` se suman `SalesPaymentGuardrailService.php` (+1, `plan_archived`) y `routes/api.php` (+50), los dos de main |
| `git diff --stat 96397ea cdb9dc8 --` código, rutas, configuración, pruebas y docs del Studio y del motor · CRM `git diff --stat a29bcc8 9fe1817 -- src/app/modules/ultron-studio e2e/ultron-studio` | Vacíos: las dos fusiones no tocan el Studio |
| `git diff --name-only --diff-filter=AMDR 7318122 cdb9dc8 -- backend/database/migrations` (también hasta `cf59cef`) | Exactamente `2026_10_25_100001_create_ultron_studio_publications_table.php` y `2026_10_25_100002_add_ultron_studio_publication_guards.php` = el literal de `deploy-h5-backend-fase1.sh:47` |
| Las 9 migraciones de main entre `fef7c87` y `7318122` (`grep` de `ultron_studio`, `create trigger` y `check (`) | Ninguna nombra `ultron_studio`; ningún disparador; un solo CHECK, en `membership_ai_capabilities`. El esquema de partida `27 24 iron iron true false` sigue valiendo |
| `phpunit` en SQLite: `UltronStudioPublicationLifecycleTest` + `UltronStudioPublicationRollbackTest` + `UltronEngineIsolationTest` (`cf59cef`) | OK 103 pruebas, 1425 aserciones, 0 omitidas, 14,8 s (incluye C2/J1/I1 y la prueba de m-2) |
| `UNIT_DB=ultron_studio_h5_review docs/ultron-studio/ops/pgunit.sh tests/Feature/UltronStudio/UltronStudioPublicationRaceTest.php --filter 'test_el_doble_clic_que_espera_la_insercion_de_la_primera_recibe_replayed\|test_el_reintento_que_cruza_la_confirmacion_de_la_primera_devuelve_su_entrada'` (PostgreSQL 14 local) | OK 9/9, 400 aserciones, 29 s |
| `php artisan route:list --path=admin/ultron-studio --json` · `php artisan auth:coverage --unmapped` (`cf59cef`) | 38 rutas · salida vacía |
| `php artisan ultron-studio:export-fixtures --path=<scratchpad>` (`cf59cef`) | Los 5 `.ts` son idénticos (sha256) a las copias del CRM (`src/app/modules/ultron-studio/testing/`) y a `plans/informes-h5/export/` |
| `shasum -a 256` de `backend-publication.testing.ts` (CRM y `export/`) | `888be271691ba2806b2bff7c6d19096256ebb8ae55fefc3ff89bdadafed3e66b` en los dos |
| `php artisan permissions:export --path=<scratchpad>` (`cf59cef`) frente a `src/app/models/backend-permission-catalog.ts` del CRM `9fe1817` | 101 permisos, ficheros idénticos (`diff` vacío). En `a29bcc8`, 96 (líneas 11–106); main añade 5 (`cash.products.refund`, `members.transfer_membership`, `payments.correct`, `payments.refund`, `plans.archive`) |
| CRM: `npx ng test --watch=false --include='src/app/modules/ultron-studio/**/*.spec.ts'` (`9fe1817`) | 54 ficheros, **878/878**, 82 s |
| CRM: `npx tsc -p tsconfig.app.json --noEmit` · `npm run check:literals` | 0 errores · «332 ficheros revisados · literales correctos» |
| CRM: `npx ng test --watch=false` (suite completa, una vez, sin otro `ng test` a la vez) | 161 ficheros (5 rojos); 3131 pruebas: **16 rojas** y 3115 verdes, 3 min. Detalle en el criterio 9 |
| Hojas del módulo: `wc -c` y `git diff --stat` de `ultron-studio.scss` | Nuevas: `studio-publication-dialog.scss` 6289 B, `studio-publication-bar.scss` 3467 B, `studio-canary-evidence.scss` 3311 B, `studio-publication-history.scss` 2516 B. Tocadas: `studio-versions-panel.scss` 7208 B y `studio-compare-view.scss` 2761 B. `ultron-studio.scss` no cambia ni en `5c1dbd7...a29bcc8` ni en `5c1dbd7..9fe1817` |
| `grep innerHTML\|bypassSecurityTrust` en el módulo, sin las specs, y en el `+` del diff | 0 coincidencias |
| `python3 -B docs/ultron-studio/ops/test_find_leaks.py docs/ultron-studio/ops/smoke-h5.py` (desde `backend/`) | **22/22**, código 0 |
| `bash -n` en los 9 `ops/*.sh` · `python3 -m py_compile smoke-h5.py` · `shellcheck` | OK · OK · **no instalado** (no se pasó) |
| `git rev-parse --short HEAD` y `git count-objects -v` (repo local) | 7 cifras con 13 554 objetos empaquetados y 6 468 sueltos (ver m-6) |

Los artefactos de estas ejecuciones (exportaciones regeneradas y registros completos del CRM) están en el scratchpad de la sesión del reviewer, que se purga solo. No se confirman.

## Criterios

### 1–5. Backend: delta sobre el reviewer anterior — CUMPLE

**H-1 (`96397ea`): `orReplayed()` no debilita ninguna puerta.**
- Los pasos 1–3 de cada escritura quedan FUERA del envoltorio:
  - el permiso y las dos llaves: `StudioPublications.php:136-137` y `:249-250` (revertir y Legacy piden solo el permiso, §A5.3/A5.4);
  - el primer reintento: `:141`, `:253`, `:364` y `:466`.
- Solo lo demás va dentro: `:146`, `:258`, `:369` y `:471`.
- `orReplayed()` (`:1393-1404`) solo captura `UltronStudioException` y, ante ella, vuelve a llamar a `replay()` (`:1358-1377`). `replay()` exige la misma persona, la misma acción, la misma versión (`version_id` nulo en Legacy) y el mismo cuerpo (`bodyOf`). Si algo difiere lanza `client_request_reused` (`:1373`), que sustituye al rechazo original.
- Solo devuelve `replayed` cuando ya existe una entrada que la MISMA petición creó pasando todas las puertas. No escribe nada.
- Lo que no es del Studio (una `QueryException`, o un UNIQUE desconocido que `conflictRefusal()` relanza) sale tal cual.

**El segundo `replay()` corre fuera de la transacción.**
- Se ejecuta cuando `DB::transaction()` ya hizo el rollback, y ningún controlador del Studio abre transacción (`grep` vacío en `app/Http/Controllers/Crm/UltronStudio/`). En PostgreSQL no hereda una transacción abortada (25P02).
- Confirmado en PG con las 9 carreras de arriba: el doble clic que espera el 23505, y las 4 escrituras × 2 ventanas (`gates`, `transaction`).
- Las pruebas secuenciales de reutilización siguen afirmando `client_request_reused` con otro cuerpo, otra persona u otra acción, y pasan: `UltronStudioPublicationLifecycleTest.php:270-283` y `UltronStudioPublicationRollbackTest.php:263-265`.

**Pruebas de cobertura de `96397ea`.** C2 (huella tocada → `publication_head_invalid` y motor `fallback/hash_mismatch`), J1 (cabeza > 16 384 caracteres leída acotada) e I1 (`previewHash` con el catálogo de la versión) pasan en SQLite.

**m-2 (`cf59cef`): corregido.**
- `facts()` empieza con `$hallazgos = []` (`:1005`).
- Cuando falla la lectura (`:1025`) o la clasificación (`:1048`), añade el hallazgo bloqueante `contenido-ilegible` (`FINDING_UNREADABLE`, `:86`; `withUnreadableFinding()`, `:1592`). Solo lo hace si no había otro bloqueo, así que no se duplica.
- Su forma `{id, level, title, detail}` es la del validador del grafo, y el CRM la lee: `readFindings` (`layer` opcional) y `errorFindings` en `studio-parse.ts`.
- La prueba de regresión `test_un_contenido_que_el_catalogo_no_sabe_leer_se_rechaza_con_un_hallazgo_que_lo_explica` está en verde.

**Fusión `cdb9dc8`.**
- No toca nada del Studio.
- Frente a `0366009`, el motor sigue cambiando solo en sus tres ficheros más `routes/ultron-studio.php`.
- Lo de main fuera del Studio: `SalesPaymentGuardrailService.php` (+1, la guarda `plan_archived`) y `routes/api.php` (+50; transferencias, PIN, planes, caja y cobros; ninguna ruta del Studio ni de `ai/*`).

### 6. CRM — CUMPLE (con I-1)

**Pruebas y paridad.**
- Specs del módulo: 878/878. `tsc` y `check:literals` en verde.
- Paridad: el sha256 coincide y los cinco ficheros que exporta hoy el backend son idénticos a las copias del CRM.

**Hojas de estilo.**
- `ultron-studio.scss` no cambia: la spec `studio-h5-sheets.spec.ts` fija su sha256.
- Las hojas nuevas pesan menos de 8 kB, por debajo del aviso `anyComponentStyle` de 8 kB de `angular.json`.
- Ninguna usa `transform` (lo afirma la misma spec) y el diff no toca el host de `fNode`.
- Sin `innerHTML` ni `bypassSecurityTrust`.

**Texto obligatorio, literal.**
- La constante `TEXTO_PUBLICAR` está en `studio-publication-text.ts:27-28`.
- Aparece como descripción del diálogo (`studio-publication-dialog.ts`, `[descripcion]="textoPublicar"`) y como ayuda junto al botón (`studio-versions-panel.ts:195`, `data-ayuda-publicar`).
- En la ayuda del permiso, la que pinta Roles, está en `PermissionCatalog.php:217` del servidor; también en el comentario del enum (`permissions.enum.ts`, `ULTRON_PUBLISH`).

**Modo publicación.**
- Solo con `readsVersions === true` (`studio-versions-panel.ts:359`).
- El parser exige un booleano (`studio-parse.ts:425`, `booleano()`), así que las comprobaciones por veracidad de `ultron-studio-page.ts:234` y `:246` equivalen a `=== true`.

**Botones y motivos.**
- Los botones salen solo con `actions.*.allowed` (`studio-versions-panel.ts:167-186`).
- Sin la llave o con negativa del servidor no hay botón, y el motivo va en `[data-motivo-accion]` (`:193`, `motivosDePublicacion()`), solo en el estado en que la acción aplicaría (D-F1).
- Administrador: el servidor manda `allowed: false, code: forbidden` (`StudioPublishing::actions()`, `StudioPublishing.php:264-296`), así que no hay botón (spec `studio-versions-panel-publication.spec.ts:139`).

**Lista cerrada de códigos.** `publication_head_invalid` está en `StudioErrorCode` y en `CODIGOS_DEL_SERVIDOR` (`studio-api.types.ts:966` y `:1013`), con texto propio (`studio-publication-text.ts:253`).

**Backend sin `engine.publication`.** `publicacionTolerante()` (`studio-parse.ts:456`): sin la clave, nada; si llega ilegible, `publicationUnreadable` y el resto del estado sigue.

**Catálogo de permisos.** 96 en el hito; 101 tras la fusión, idéntico al que exporta el backend fusionado.

**Diálogos nuevos.**
- El de publicar y el de evidencia/promover van sobre `ib-dialog`: `role="dialog"`, `aria-modal`, el foco no sale con Tab y vuelve a quien abrió (`ib-dialog.ts:31-36` y `:196-240`).
- Los errores llevan `role="alert"`: `studio-publication-dialog.ts:58/134/150/157`, `studio-canary-evidence.ts:39/82/85` y `studio-publication-history.ts:31`.

**Decisiones deliberadas.** D-F1…D-F9 no se reportan como fallos.

**Hallazgo:** I-1 (idempotencia de «Volver a Legacy» en la sesión).

### 7. Scripts de producción — PARCIAL (cumplen lo pedido salvo m-3 y m-4; I-2 e I-3 antes de la fase 1)

**`deploy-h5-backend-fase1.sh` (`cf59cef`)**
- **Sin secretos:**
  - `estado_var` solo responde `falta`/`igual`/`distinto`/`repetida` (`:22-29`), y los mensajes nombran la clave, nunca el valor (`:59`, `:86`);
  - del fichero de entorno solo se imprimen el dueño y el modo (`:88`);
  - Dotenv imprime solo la clase del error (`:90`);
  - la configuración efectiva que se enseña no tiene secretos (`:116-118`);
  - del canario, solo un booleano (`:119`).
- **Falla cerrado:**
  - `set -euo pipefail` (`:9`);
  - el paso 0 aborta antes de tocar nada (`:33-75`);
  - las aserciones posteriores suman desvíos y terminan con `exit 1` (`:146`);
  - «FASE 1 (H5) COMPLETADA» solo sale con 0 desvíos.
- **M-3:** todo git va por `g()` = `sudo -u www-data -H git` (`:11`; usos en `:35, 36, 42, 44, 45, 46, 77, 78, 145`). No queda ningún `git` desnudo.
- **B-1:** `Dotenv::createArrayBacked(".")->load()` (`:90-91`) va antes de `ironbody-migrate` (`:94`).
- **`--solo-comprobar`:** sale en `:75` tras el paso 0, que solo lee y hace `git fetch`. Ver I-2.
- **`PREVIO=7318122` (`:15`) es coherente con el resto del script.** Lo usan `:38`, `:45`, `:46` y `:49`.
  - Las migraciones nuevas son exactamente las dos de H5.
  - `fef7c87` → `7318122` → `cdb9dc8` es una línea de ancestros.
  - Los esquemas esperados son los del estado de producción descrito: `27 24 iron iron true false` antes y `30 27 3 3 2 true iron iron 0` después.
  - `marketing_ai_actions.metadata` es `jsonb` (`2026_06_02_000007_create_marketing_ai_actions_table.php:20`), así que `metadata ? 'studio'` (`:133`) es válido.
  - Las 38 rutas y la cobertura vacía se reproducen en local.
  - `ultron-studio:publication-check` sale con 0 con la cadena vacía (`PublicationCheckCommand.php:41-47` y `:70-73`).

**`h5-engine-scope.sh`**
- Valida los dos argumentos (`:18-27`) y niega `off → all` con `exit 2` (`:27`).
- Exige que la configuración efectiva sea la del alcance actual antes de tocar nada (`:48-50`) y el canario para `canary` y `all` (`:51-53`).
- Aborta con claves repetidas (`:54-56`) y deja una copia previa 600 de root (`:57-61`).
- Comprueba con Dotenv antes de `config:cache` (`:68-70`) y regenera con `umask 077`; verifica el dueño y el modo de la caché (`:71-74`).
- Comprueba la configuración efectiva pedida e imprime la orden de vuelta si no cuadra (`:75-81`).
- Sin git y sin valores sensibles.
- Ver m-3.

**`smoke-run-h5.sh` + `smoke-h5.py`**
- **Contraseña:** solo por entorno a python (`smoke-h5.py:487`) y como hash por la entrada estándar a tinker (`:129-134`).
- **Salida de tinker:** solo se imprime si casa con el patrón esperado (`tinker_line`, `:50-55`).
- **Id de la conversación canaria:** solo en una variable y por entorno a python (`:116-122`); `find_leaks` (`smoke-h5.py:83`) informa de etiquetas, nunca del valor.
- **Tokens:** en ficheros 0600 dentro de un directorio 0700 (`:66-69`; `smoke-h5.py:191`).
- **El trap `cleanup` sobre EXIT (`:73-101`) deja la cuenta 32 en reposo aunque falle:**
  - `ACTIVADA=1` se pone ANTES de activar (`:131`);
  - deja la cuenta inactiva y Super Admin, y borra sus sesiones (`:78`);
  - comprueba que los tokens dan 401/403 (`:82-91`) y que quedan 0 sesiones (`:92-93`);
  - borra los temporales y las copias de `/root`;
  - sale con 1 si algo de eso falla.
- **Escrituras en la fase 1:** solo peticiones que el servidor rechaza antes de escribir. Coincide con el código:
  - `assertKeys()` no audita un uuid inexistente (`StudioPublications.php:1489-1502`);
  - Legacy solo se intenta con la cadena vacía y la llave del Studio cerrada (`smoke-h5.py:375`), y responde `already_legacy` sin auditar.

**`deploy-crm.sh`**
- Se ejecuta en el Mac (git local: M-3 no aplica).
- Aborta con el build sin sellar o sucio, con producción movida o con `origin/main` distinto del build.
- Ver m-4.

**`h5-schema-check.sql`**
- Solo lectura: `SELECT`, `\gset` e `\if`.
- Sus esperados (27/24 → 30/27, 0 → 3/3/2) coinciden con el script.
- Comparte m-5.

Hallazgos del criterio: I-2, I-3, m-3, m-4, m-5 y m-6.

### 8. Documentación — PARCIAL (segunda pasada pendiente)

**La Enmienda 2 coincide con el código en todo lo contrastado:**
- **D-1:** `UltronStudioException.php:390` y `verifiedParts()` (`StudioPublications.php:1250`).
- **D-2:** `acknowledged = {noEffect, previewHash}` en `publishGated()`.
- **D-3:** `notApplicableLabGate()` (`:1612`).
- **D-4:** la nota va a `reason` (`insertEntry()`).
- **D-6:** se audita solo si la versión existe (`assertKeys()`), con `scope_not_allowed` al promover y `restore_integrity_mismatch` en `refuseRestore()`.
- **D-7:** `blocking` lleva `forbidden`, las llaves y `four_eyes_required` (`previewFrom()`).
- **D-9:** `evidence()` → `invalid_transition` con `[CANARY]`.
- **D-13:** el controlador valida la forma antes del servicio (`UltronStudioVersionController::publish()`).
- **D-18:**
  - `$ventana`: mal escrita o cruzando la medianoche → `07:00-19:00`; vacía → sin ventana (`config/ultron_studio.php:34-50`);
  - la etiqueta lleva raya y el intervalo es `[inicio, fin)` (`StudioPublishing::windowFor()`).
- **AS-8:** `integer|min:1`, así que `per_page=0` es 422; tope de 50 y 20 por defecto (`UltronStudioPublicationController.php:33-39`).
- **AS-2:** `throttle:ultron-studio-evidence` también en promover (`routes/ultron-studio.php:123`, junto a `:96`).
- **M-1:** `config/ultron_studio.php:65` y `flagEnabled() === true`.
- **E2.4:** «Rotación de la `APP_KEY` con la cadena viva» (`ULTRON_STUDIO_ROLLBACK.md:137-149`).
- **E2.5:** ninguna migración de H5 trae `CHECK (seq >= 1)` ni disparador de `seq`. Diferido, y coherente con lo que dice la enmienda.

**Las demás fuentes:**
- **E2.3** coincide con `informe-front-integracion.md` §3 (D-F1…D-F9; D-F10 es de la atribución de los commits).
- **`decisiones-lead-h5.md`:** todos los commits que cita existen y hacen lo que se les atribuye (backend `f4d64b8`, `5df1b1d`, `2bc053b`, `61048ff`, `d24c512`, `602c4b9`, `5772bdf`, `96397ea`; CRM `9b70337`, `3989f90`, `e7ba41b`, `33821e4`, `24a691d`, `a29bcc8`), salvo la ruta de m-10.
- **`ops/README.md`** coincide con los scripts salvo m-7 y m-8.

**Hallazgos del criterio:** I-4 (vuelta atrás del código), m-7, m-8, m-9 y m-10.

**Segunda pasada pendiente** (no revisado, por indicación del lead): las secciones «Hito 5» de `ULTRON_STUDIO_DEPLOYMENT_PLAN.md`, `ULTRON_STUDIO_FINAL_REPORT.md`, `ULTRON_STUDIO_SECURITY_REPORT.md`, `ULTRON_STUDIO_BACKEND_REPORT.md` y `ULTRON_STUDIO_VISUAL_REPORT.md`, y la hoja de ruta. Añado `ULTRON_STUDIO_ROLLBACK.md`, que tiene cambios sin confirmar en este momento.

### 9. Regresiones — CUMPLE (en lo ejecutado)

**CRM, suite completa: 16 rojas, todas fuera del Studio.** Están en 5 ficheros:
- `access-control.moderation.spec.ts` (6): «Administrativo puede revisar y asignar, nada más», «Administrador modera pero no ejecuta acciones ELEVADAS», «una política guardada ANTIGUA no borra los permisos de moderación», «una política guardada no puede CONCEDER moderación de más», «la matriz cubre exactamente los cuatro roles del backend» y «los permisos crecen monótonamente con la jerarquía de roles».
- `auth.moderation-menu.spec.ts` (7): «Super Admin ve el enlace», «Administrador ve el enlace», «Administrativo ve el enlace — regresión del gap corregido», «Recepción ve el enlace (solo lectura)», «ver el enlace no implica poder sancionar», «Administrativo revisa y asigna, pero no sanciona» y «Administrador conserva intactos sus permisos fuera de moderación».
- `auth.guard.moderation.spec.ts` (1): «Administrador entra».
- `financial-realtime.integration.spec.ts` (1): «CAJA + listado montados juntos comparten UNA conexión y los dos se enteran».
- `receivables-pagination.spec.ts` (1): «un movimiento de dinero recarga la página sin cambiar de módulo».

**Por qué ninguna es nueva:**
- Ninguno de esos ficheros cambia entre `5c1dbd7` y `9fe1817`. Sus últimos cambios son `64bd5f6` (02-10) y `b291878` (14-09).
- Ninguno importa el Studio.
- Lo único que H5 cambia en `permissions.enum.ts` son comentarios.
- 16 es la línea base conocida (moderación y tiempo real): **0 fallos nuevos**.

**Backend:** no repetí las puertas del lead. Los subconjuntos están en verde: SQLite 103/103 y PG 9/9.

## Hallazgos

| # | Sev. | Dónde | Qué y escenario de fallo | Corrección propuesta → agente |
|---|---|---|---|---|
| I-1 | **importante** (antes de la fase 2) | CRM `studio-session.ts:1300-1306` (la clave) y `:1326-1336` (solo se olvida tras `client_request_reused`); `studio-publication-bar.ts:164-176`. Servidor: `StudioPublications.php:466` (el reintento va antes de mirar la cabeza) y `:1358-1377` | **Qué:** la clave de idempotencia de una escritura de la cadena se reutiliza mientras coincidan acción, sujeto y cuerpo, también DESPUÉS de un éxito. Para publicar, promover y revertir es inocuo, porque cada versión pasa una sola vez por cada transición. Legacy no tiene versión y su cuerpo es solo el motivo. **Escenario**, en la misma pestaña: (1) «Volver a Legacy» con «Prueba de salida» → 201; (2) otra persona publica V2 en canario; (3) «Volver a Legacy» otra vez con «Prueba de salida». El CRM reenvía el MISMO `clientRequestId` y el servidor responde 200 `replayed` con la entrada vieja, que es el comportamiento del contrato (fijado en `UltronStudioPublicationRollbackTest.php:268-271`). V2 sigue en canario y la cadena la declara, pero la barra dice «Ya estaba en Legacy con esta misma petición: el Studio no declara ninguna versión». La salida del Studio falla en silencio. **Alcance:** no afecta a la fase 1, porque publicar está cerrado y, con la cadena vacía, Legacy responde 409 `already_legacy`. **Confirmación:** leyendo el código de los dos lados; no lo reproduje con una spec porque no escribo en el árbol | Olvidar la clave de la acción tras un 2xx legible y tras un 4xx definitivo. Conservarla solo para reintentar tras no tener red, 5xx, 429 o un 2xx ilegible. Spec: Legacy 201 → publicar → Legacy con el mismo motivo ⇒ `clientRequestId` distinto → **frontend** |
| I-2 | **importante** (antes de la fase 1) | `deploy-h5-backend-fase1.sh:14` y `:75` | **Qué:** cualquier tercer argumento que no sea exactamente `--solo-comprobar` ejecuta el despliegue entero. **Escenario:** `bash /root/deploy-h5-backend-fase1.sh <commit> <respaldo> --solo-comprobr` (o `--dry-run`, o `--solo_comprobar`). Pasa el paso 0 y hace el `merge`, escribe las 7 claves, migra y reinicia los workers en producción, sin la pausa entre el ensayo y la orden de ejecutar («cada fase exige su orden expresa»). **Alcance:** las comprobaciones previas siguen abortando si algo falla, así que el daño es desplegar antes de lo autorizado, no un despliegue roto | `case "${3:-}" in ''\|--solo-comprobar) ;; *) aborta "tercer argumento desconocido: $3";; esac` y `[ $# -le 3 ] \|\| aborta …` → **devops** |
| I-3 | **importante, a confirmar** (antes de la fase 1) | `deploy-h5-backend-fase1.sh:77` (`g merge -q --ff-only`); el paso 0 no comprueba que se pueda escribir | **Qué:** el `merge` va como `www-data` (M-3), pero nadie ha comprobado que `www-data` pueda escribir los directorios que va a tocar. El informe de recuperación (§3, b) dice que `.git/index` es de root porque «git del VPS se opera como root», y el despliegue de H4 hizo `git merge` como root (`deploy-h4-backend-fase1.sh:15-16`). **Escenario:** si un directorio seguido que cambia en H5 es de root, `git merge --ff-only` falla A MEDIAS (`error: unable to unlink old …: Permission denied`). Deja escritos los ficheros que pudo, con HEAD y el índice en `7318122`, y `set -e` corta sin «ABORTA». Producción sirve entonces código mezclado: por ejemplo, `UltronCommitService.php` nuevo sin el fichero nuevo `UltronPublishedConfig.php` da un error fatal en el commit de ULTRON. No lo pude comprobar: no tengo acceso al servidor | **En el servidor, en solo lectura, antes de la fase 1:** `find /var/www/api -path /var/www/api/.git -prune -o ! -user www-data -print \| head`, descontando los 5 ficheros vacíos de root, sin seguimiento, que ya se conocen. Otra opción: `sudo -u www-data test -w` sobre el directorio (o su padre existente más cercano) de cada ruta de `git diff --name-only 7318122 origin/main`. **En el script:** añadir esa comprobación al paso 0, para que la vea `--solo-comprobar` → **devops** (la comprobación en el servidor: lead) |
| I-4 | **importante** (antes de la fase 1; en edición) | `ULTRON_STUDIO_ROLLBACK.md:102-113` (versión confirmada en `cf59cef`) | **Qué:** la vuelta atrás del código manda `git checkout -q --detach fef7c87`, pero producción está en `7318122`: H4 más el trabajo de main (9 migraciones, correcciones de caja, segundo factor biométrico, transferencias de membresía, potencias de Iron IA y archivar planes). **Escenario:** el Hito 5 da problemas y quien opera sigue el runbook al pie de la letra. La API vuelve a `fef7c87` con las migraciones de main aplicadas y esas funciones desaparecen de producción; por ejemplo, desaparece la guarda `plan_archived` (`SalesPaymentGuardrailService.php:111`) y un plan archivado vuelve a poder cobrarse por enlace. **Lo que se salva:** el código del Studio es idéntico en `fef7c87` y en `7318122` (el `git diff --stat` de las rutas del Studio sale vacío), así que el ensayo de DevOps (33 rutas, datos de H5 tolerados) sigue valiendo con el destino corregido | El destino tiene que ser el commit de `$BK/api-commit.txt` (hoy `7318122`), no un literal; revisar con el mismo criterio «Volver a H5». Comprobarlo en la segunda pasada: ahora mismo hay cambios sin confirmar de otro agente en ese fichero → **documentation-specialist** / **devops** |
| m-1 | menor · DECIDIDA | `config/ultron_studio.php:65` | El lead acepta la semántica de E2.2 (`true`, `1`, `on`, `yes` encienden; lo demás apaga). Su documentación en el runbook no está: ver m-8 | — |
| m-2 | menor · CERRADA | `StudioPublications.php:1025`, `:1048` y `:1592` (`cf59cef`) | Verificado: el hallazgo `contenido-ilegible` llega en el 409, la prueba de regresión está en verde y el CRM lo lee | — |
| m-3 | menor | `h5-engine-scope.sh:91` | **Qué:** con desvíos, el mensaje es «ALCANCE canary APLICADO CON 2 DESVÍOS». Sale con 1, que es lo correcto, pero la palabra de éxito aparece en el fallo. **Escenario:** quien busca «APLICADO» en la salida o en el registro de la fase lo da por bueno | Usar «ALCANCE <x> ESCRITO CON <n> DESVÍOS: no seguir…», como el «CON N DESVÍOS» de la fase 1 → **devops** |
| m-4 | menor | `deploy-crm.sh:35-38` | **Qué:** la verificación pública no falla nunca. `curl … \| grep -q … && echo OK` está en una lista `&&`, que no dispara `set -e`, y los códigos HTTP del `main` nuevo y de `/marketing/ultron-studio` solo se imprimen. **Escenario:** un `rsync` a medias o una caché sirve el `index.html` viejo, y el script sale con 0 e imprime «RESPALDO: …» | Añadir `\|\| { echo "FALLO …"; exit 1; }` a cada comprobación y exigir 200 → **devops** |
| m-5 | menor | `deploy-h5-backend-fase1.sh:107-109`; `h5-schema-check.sql:30` | **Qué:** `has_table_privilege(…, 'SELECT,INSERT,UPDATE,DELETE')` da `true` si se tiene CUALQUIERA de los cuatro (así lo documenta PostgreSQL), así que «DML true» no prueba que haya `INSERT`. **Escenario:** si `iron_app` solo tuviera `SELECT` sobre la cadena, la fase 1 saldría en verde y la primera publicación de la fase 2 daría 500 (42501) | Comprobar `SELECT` e `INSERT` por separado; `UPDATE` y `DELETE` ya los paran los disparadores → **devops** |
| m-6 | menor · a confirmar | `deploy-h5-backend-fase1.sh:36` y `:44` | **Qué:** compara `git rev-parse --short` con literales de 7 cifras, y la abreviatura automática de git sube a 8 con ≥ 16 384 objetos empaquetados (este repo tiene 13 554 empaquetados y 6 468 sueltos). **Escenario:** un `gc --auto` tras el `fetch` empaqueta, y entonces `HEAD 73181227` ≠ `7318122` → ABORTA. Falla cerrado y lo vería `--solo-comprobar` | `--short=7`, o comparar hashes completos (`g rev-parse "$PREVIO^{commit}"`) → **devops** |
| m-7 | menor | `ops/README.md:19` | Sigue diciendo «HEAD en `fef7c87`»; el script dice `PREVIO=7318122` desde `cf59cef` | Actualizarlo → **devops** / **documentation-specialist** |
| m-8 | menor | `.env.example:304`; `ops/README.md`; `informe-devops-h5.md:257` y `:693` | **Qué:** la semántica aceptada en m-1 no está en el runbook. `ops/README.md` no la menciona e `informe-devops-h5.md` solo habla de la comparación literal del script. `.env.example:304` dice «Solo true la enciende», y es falso: `1`, `on` y `yes` también la encienden, como dicen bien `config/ultron_studio.php:61-62` y E2.2. **Escenario:** quien opera lee el ejemplo, cree que `ULTRON_STUDIO_PUBLISHING_ENABLED=1` no abre nada y, con el motor en `canary`, publicar queda abierto | Escribir «`true`, `1`, `on` o `yes` (sin distinguir mayúsculas) la encienden; cualquier otro valor la apaga» en `.env.example` y en `ops/README.md` → **documentation-specialist** |
| m-9 | menor | `ULTRON_STUDIO_H5_CONTRACT.md:393` | La Enmienda 2 dice que H-1/AS-4 «está en revisión», pero se escribió en el mismo commit que lo corrige (`96397ea`), y `decisiones-lead-h5.md:56` lo da por CORREGIDO | «Corregido en `96397ea` (`orReplayed()`); 38 carreras en PG» → **lead** |
| m-10 | menor | `decisiones-lead-h5.md:30` | Dice que los dos scripts copian el entorno en `/var/backups/ironbody/env-pre-h5-*`, y no es así. La fase 1 exige `$BK/env.backup` 600 (`deploy-h5-backend-fase1.sh:51`) y el alcance copia en `/root/env-pre-scope-<alcance>-<fecha>` (`h5-engine-scope.sh:59-60`, como dice bien `ops/README.md:20`) | Corregir la ruta → **lead** |

## Pendiente para cerrar

1. **Segunda pasada de la documentación:** las secciones «Hito 5» de `DEPLOYMENT_PLAN`, `FINAL_REPORT`, `SECURITY_REPORT`, `BACKEND_REPORT` y `VISUAL_REPORT`, la hoja de ruta y `ROLLBACK.md` (I-4). Las cinco primeras y la hoja de ruta las escribe otro agente ahora; `ROLLBACK.md` tiene cambios sin confirmar en este momento.
2. **Puertas del lead, que no repetí:** SQLite (Studio + Access + Chaos), la puerta PG completa (`pgunit.sh`, con las 38 carreras) y Marketing. No he visto sus resultados.
   - Aviso: hacia las 21:25 UTC ejecuté 9 carreras durante 29 s en `ultron_studio_h5_review`, sobre el mismo clúster. Si la puerta PG del lead corría a la vez y alguna carrera «no ocurrió», repetirla antes de darla por roja.
3. **I-3 en el servidor**, en solo lectura, antes de la fase 1.
4. **Build de producción del CRM y presupuestos de chunk:** no lo ejecuté. Escribiría en `dist/`, que es lo que lee `deploy-crm.sh`, y el lead lo tiene en marcha.
5. **`shellcheck`:** no está instalado. Lo sustituí por la lectura línea a línea y `bash -n`.
6. **No verificable desde aquí**, porque solo existe en producción:
   - el comportamiento real de `/usr/local/bin/ironbody-migrate`;
   - que `PGOPTIONS` llegue a la sesión que migra (N-10);
   - que PHP-FPM vea la caché nueva tras `config:cache`. `h5-engine-scope.sh` lee la configuración por tinker (CLI); la vista de FPM la comprueba el smoke por HTTP (`envScope`) justo después.
7. **I-1:** tras el arreglo, specs y un build del CRM. Si se corrige antes de la fase 1, el CRM se despliega una sola vez.

## Notas

- **Reanudación tardía.** Un respaldo nuevo tomado tras un corte de más de 2 h guardaría `api-commit.txt` = el commit esperado, y el script abortaría en `:49`. Falla cerrado; conviene decirlo en el runbook (reanudar = relanzar enseguida o rehacer el respaldo a mano).
- **Revisado y correcto, sin hallazgo:**
  - las 7 claves y sus defectos;
  - el orden Dotenv → migración → `route:cache` → cachés 600 → `queue:restart`;
  - el recuento de ERROR desde el inicio por fichero de log, que tolera la rotación diaria;
  - las colas fallidas por SQL;
  - la ausencia de sellos con el motor en `off`.

## Segunda pasada (documentación)

**Fecha:** 31-10-2026, ~22:00 UTC. **Sobre:** backend HEAD `70fda56`, que contiene la documentación de `2b20449` y su segunda ronda, `e2950aa`, con los scripts de `db1d8f6` y `940b0c2`. CRM `9fe1817`, para las cifras.

**Revisado:**
- `ULTRON_STUDIO_DEPLOYMENT_PLAN.md`: el runbook del Hito 5, fases 1–3 e incidencias;
- `ULTRON_STUDIO_ROLLBACK.md`: «Hito 5» y la cabecera del Hito 4;
- `ULTRON_STUDIO_FINAL_REPORT.md`, `ULTRON_STUDIO_SECURITY_REPORT.md`, `ULTRON_STUDIO_BACKEND_REPORT.md` y `ULTRON_STUDIO_VISUAL_REPORT.md`, en sus secciones del Hito 5;
- `ULTRON_STUDIO_MASTER_ROADMAP.md` y `ops/README.md`.

Solo lectura. Este apartado es lo único añadido; sin commit.

**No son hallazgos** (decisiones del lead ya escritas):
- `git` como root con `umask 022` y la comprobación de lectura por `www-data` (I-3);
- `ULTRON_STUDIO_PUBLISH_LAB_GATE=waived`, escrito en la fase 2;
- el alcance de la autorización del 25-10-2026 sobre las fases 2 y 3.

### Veredicto de la segunda pasada

**APROBADO CON OBSERVACIONES.** No hay bloqueantes.

- **Runbook:** es ejecutable con los scripts actuales. Argumentos, orden, nombres de variables, rutas y cada línea de la salida esperada coinciden con lo que imprimen.
- **Vuelta atrás:** ya no deshace el trabajo de main en el caso de hoy (I-4 cerrado).
- **Cifras:** cuadran con los informes de `plans/informes-h5/`, con el mío y con las del lead.
- **Secretos e ids:** no hay secretos ni ids de conversación.

Quedan cinco observaciones:
- **D2-1 (importante):** la vuelta atrás del código no avisa de que solo vale mientras producción siga en el commit de la fase 1. Corregirlo antes de la fase 1 cuesta una frase y una comprobación, y es obligado antes del primer despliegue de main posterior a la fase 1.
- **Cuatro menores** (D2-2 a D2-5).

`e2950aa` ya corrigió las frases desfasadas que había anotado sobre `2b20449`:
- `FINAL_REPORT:399` daba por pendientes las puertas del lead ya hechas;
- `BACKEND_REPORT:839` y `:842` decían que no se habían verificado las puertas tras la fusión ni el E2E sobre el código corregido;
- `SECURITY_REPORT:296` y el runbook decían que el E2E «no se ha repetido»;
- `ROLLBACK:127` remitía a un procedimiento de reanudación tardía que el runbook aún no tenía.

### Verificado en esta pasada

| Comprobación | Resultado |
|---|---|
| Fase 1 del runbook frente a `deploy-h5-backend-fase1.sh` actual | Coinciden la línea de uso, `--short=7`, la salida 2 con un tercer argumento desconocido y el orden (`merge` como root → lectura por `www-data` → claves → Dotenv → `ironbody-migrate` → `route:cache` → cachés → `queue:restart` → aserciones). La salida esperada del paso 5 es, línea a línea, lo que imprime el script, incluidas las líneas nuevas de lectura por `www-data` y de privilegios (`true true false true` e informativo `true true`) |
| Smoke esperado del runbook (35 OK, o 36 con una versión APPROVED, + 14) frente a `smoke-h5.py` | Contando las comprobaciones del código: Super Admin en la fase 1 con canario configurado, 35 (+1 con una APPROVED); Administrador, 14. Coincide con `informe-devops-h5.md:639-640` |
| Fases 2 y 3, y vuelta por fase, frente a `h5-engine-scope.sh` | Coinciden los argumentos (`canary off`, `all canary`, `off <actual>`), `APLICADO` / `ESCRITO CON N DESVÍOS` (m-3), la copia `/root/env-pre-scope-<alcance>-<fecha>` y `lab_gate` en la lectura. El `sed` de `waived` casa con la línea sangrada que escribe la fase 1 |
| `deploy-crm.sh` frente al paso 7 | `CRM PUBLICADO Y VERIFICADO`, o código 1 con los fallos (m-4); el respaldo `crm-pre-<build>-<ts>` es el que restaura `ROLLBACK.md` |
| Lo que lee la verificación posterior | Existen las claves de log `ultron_studio.publication_state_unavailable` (`StudioPublications.php:624`), `auth:admin:unmapped_route` (`EnforceAdminAuthorization.php:78`) y `ultron_studio.canary_acta_unavailable` (`StudioCanaryMeasures.php:180`), y la columna `ultron_aborts.released_at` |
| I-4 en `ROLLBACK.md:103-128` | El destino sale de `$BK/api-commit.txt`, en una subshell con `umask 022`. La reanudación tardía conserva `api-commit.txt` = `PREVIO` (`DEPLOYMENT_PLAN:616`) |
| Afirmaciones de `ROLLBACK.md:105` | Entre `fef7c87` y `7318122` hay 19 commits: 14 de otros módulos y 5 del Studio, solo docs y `ops/`. En las zonas del motor cambia 1 línea (`SalesPaymentGuardrailService.php`). El código del Studio es idéntico en los dos commits (`git diff --stat` vacío) |
| `smoke-run-h4.sh`, que usa la vuelta atrás | Acepta `SMOKE_LAB_EXPECT_ENABLED` y su smoke exige `readsVersions: false`, así que solo vale con el código de H4 (lo dice el runbook) |
| Cifras de los informes frente a sus fuentes | Coinciden todas: `informe-api` (1654 / 20 038 / `aeb9699c` / 15 mutaciones), `informe-motor` (217/217, 4977, 87, 12), `informe-nucleo` (236), `informe-db` (`4ad748b5`, índices 6·5 → 8·7, 55P03 a 5,24 s, 15,61 / 6,36 ms), `informe-front-integracion` (2920, 18, 71/71, `fc558002`), `informe-tester-backend` (10 841 / 10 137 / 57, 4730, 184), `informe-tester-e2e` (315, 230, 116), `informe-devops-h5` (35/36 + 14, 34 + 14, 217 tablas). Hojas anteriores: 6200 y 2329 B en `5c1dbd7`. Mis cifras (103/103, 9/9, 878/878, 3131 / 16, 101 permisos) y las del lead (1697, 1446/1447, 1447, 4977, 408/408) están citadas igual en todos los documentos. La única discrepancia es D2-4 |
| `git diff --stat cf59cef HEAD` sobre `app`, `config`, `routes`, `database`, `tests`, `bootstrap` y `composer.*` | Vacío: después de `cf59cef` solo cambian la documentación y un comentario de `.env.example`. Las puertas del lead sobre `cf59cef` valen para HEAD, como dice `DEPLOYMENT_PLAN:486` |
| CRM: módulo del Studio de `4105016` frente a `5c1dbd7` (`DEPLOYMENT_PLAN:472`) | `git diff --stat` vacío |
| Cierre de mis menores en `db1d8f6` | m-8 (`.env.example`: «true, 1, on o yes»), m-9 (Enmienda 2: AS-4/H-1 corregido en `96397ea`) y m-10 (rutas reales de las copias del entorno) cerrados. m-5 también en `h5-schema-check.sql` (`app_select` y `app_insert` por separado) |
| Secretos e ids en los 8 documentos | Busqué claves (`sk-…`, `base64:…`, `Bearer …`, `*_KEY=`/`*_SECRET=`/`*_TOKEN=` con valor), ids de conversación, teléfonos, correos y hex largos: 0. Solo aparecen cadenas sintéticas de prueba en la sección del Hito 4 (`prueba 300 123 4567 correo@x.co`) |
| `[PENDIENTE]` que quedan | Todos legítimos: I-1, el build del CRM, la fase 1 (y su registro) y esta segunda pasada |

### Hallazgos de la segunda pasada

| # | Sev. | Dónde | Qué y escenario de fallo | Corrección propuesta → agente |
|---|---|---|---|---|
| D2-1 | **importante** (antes de la fase 1, porque es barato; como muy tarde, antes del primer despliegue de main posterior a la fase 1) | `ULTRON_STUDIO_ROLLBACK.md:103-128` | **Qué:** la vuelta atrás del código hace `git checkout -q --detach "$(cat "$BK/api-commit.txt")"` sin comprobar que producción siga en el commit que desplegó la fase 1. Solo es correcta mientras nadie haya desplegado main después; el canario dura días (unos 3 turnos por semana) y main se despliega a menudo (19 commits entre el 24 y el 29-10). **Escenario:** el día D, la fase 1 deja HEAD = X (`7318122` + H5). Durante el canario, otro equipo despliega main Y = X + trabajo de caja o biometría. En la fase 2 el código de H5 da problemas, y quien opera sigue el runbook: checkout a `7318122`. Desaparece de producción el trabajo de Y y sus migraciones quedan aplicadas sin el código que las usa (la misma clase que I-4). Además, `--detach` deja el árbol sin rama, y el siguiente `git pull` de otro equipo falla | Antes del checkout, comprobar `[ "$(git -C /var/www/api rev-parse HEAD)" = "<commit desplegado por la fase 1>" ]`. Si no coincide, no usar checkout: la salida inmediata es la vuelta por fase (`h5-engine-scope.sh off` + «Volver a Legacy», que no tocan código), y la vuelta del código se decide con devops (revert de los commits del Hito 5 sobre el main de ese momento). Avisar de que `--detach` deja el árbol sin rama. Anotar el commit de la fase 1 en el registro de respaldos (`ROLLBACK:168`) → **documentation-specialist** / **devops** |
| D2-2 | menor | `ULTRON_STUDIO_DEPLOYMENT_PLAN.md:528` y `:623`; `ops/README.md:23`; cabecera de `ops/h5-schema-check.sql:2` | **Qué:** el paso 1 deja `/root/h5-schema-check.sql` en 600 de root, y los pasos 2 y 6 lo abren con `sudo -u postgres psql … -f /root/h5-schema-check.sql`. `postgres` no puede leer un fichero 600 de root (ni atravesar `/root`, 700), así que falla siempre, no solo «si `/root` no es legible», como dice la nota de `:536`. **Escenario:** la primera comprobación de solo lectura del día sale con `Permission denied`. No se toca nada y la nota da la alternativa, pero el runbook no es ejecutable tal cual en ese punto | La orden principal tiene que ser la de la entrada estándar (`sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v owner=iron -v app=iron_app -x -d ironbody < /root/h5-schema-check.sql`) en los pasos 2 y 6, en `ops/README.md:23` y en la cabecera del fichero, sin el «no verificado» → **documentation-specialist** / **devops** |
| D2-3 | menor; el riesgo, a confirmar por **security** | `ULTRON_STUDIO_SECURITY_REPORT.md:357`; `ULTRON_STUDIO_MASTER_ROADMAP.md:56` | **Qué:** describen el pendiente de `ironbody-migrate` como «root sobre un árbol de `www-data`» (M-3). Desde I-3 el árbol es de root, como dicen `SECURITY_REPORT:298` y el runbook, así que esa justificación ya no vale. El riesgo sigue por otra vía: `php artisan migrate` como root arranca Laravel y ejecuta con `require` `bootstrap/cache/config.php` (y `services.php`/`packages.php`), que son de `www-data` (el script de la fase 1 lo exige). **Escenario:** quien consiga ejecutar código como `www-data` reescribe `config.php`, y el siguiente `ironbody-migrate` lo ejecuta como root. Es lectura de código; no lo comprobé en el servidor | Reescribir el pendiente: «ejecuta como root PHP que `www-data` puede escribir (`bootstrap/cache/*.php`)». Que `security` valore la mitigación (vaciar la caché como root antes de migrar, o migrar sin cargar la de `www-data`) → **security** / **documentation-specialist** |
| D2-4 | menor | `ULTRON_STUDIO_BACKEND_REPORT.md:811` (y su fuente, `plans/informes-h5/informe-motor.md:20`) | Dice `UltronCommitService.php` (+58/−9). `git diff --numstat` da **+49/−9** en todos los commits desde `ea04d3f` (58 es el total de líneas cambiadas) | Escribir +49/−9 en los dos sitios → **documentation-specialist** |
| D2-5 | menor | `ULTRON_STUDIO_MASTER_ROADMAP.md:63` | N-2 (una `APP_KEY` por entorno) sigue en los pendientes del Hito 7 («comprobarlo comparando el sha256…»), pero `SECURITY_REPORT:309` dice que el lead ya lo comprobó el 31-10: la huella de producción no coincide con ninguna del repo ni del E2E | Marcarlo como comprobado el 31-10 (o dejar solo lo que falte, si se añade otro entorno) → **documentation-specialist** |

### Notas de la segunda pasada (sin hallazgo)

- **Defensa en profundidad de I-3, para `security`.** El runbook comprueba el dueño de `.git`, `.git/config` y `.git/hooks`, y para si no es root. El día de la fase 1 conviene listar también, en solo lectura, las 42 entradas de `.git` que no son de root (`find /var/www/api/.git ! -user root`) y confirmar que ninguna es `config`, `hooks/*`, `refs/*` ni `packed-refs`, y que `hooks/` solo tiene `*.sample`.
- **La comprobación de lectura tras el `merge`** recorre `git diff --name-only` por sustitución de proceso. Si ese `git diff` fallara, no comprobaría nada y diría «todos legibles». Es improbable, porque el `merge` acaba de salir bien. Contar las líneas y exigir más de 0 lo cerraría.
- **«Comparación de suites del mismo día»** (`DEPLOYMENT_PLAN`, condición previa) sigue sin resultado escrito. El lead la está preparando (`70fda56` arregla `suite-compare.sh` en el bash de macOS); es un pendiente legítimo.
- **`ops/README.md:25`** dice «siempre `sudo /usr/local/bin/ironbody-migrate` (… `lock_timeout` 5 s …)». El `lock_timeout` lo pone quien llama con `PGOPTIONS`, como hace el script de la fase 1, no el envoltorio. Quien lo lance a mano no lo tiene; ver N-10.

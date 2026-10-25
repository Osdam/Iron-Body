# ULTRON STUDIO · Hito 5 · Seguridad de la API (A12, `api-security-specialist`)

**Fecha:** 25-10-2026. **Revisado:** backend `feat/ultron-studio-h5`, HEAD `d98bbf9` (desde `61048ff` solo entró documentación: `git diff --stat 61048ff d98bbf9 -- app routes config database` sale vacío), diff `0366009..HEAD -- app routes config database`. Contrato `ULTRON_STUDIO_H5_CONTRACT.md` (A1–A13, Enmienda 1), `informe-api.md` (§1, §2, §3, §7) y `decisiones-lead-h5.md`.

**Modo:** solo lectura sobre el repo. Este informe es el único fichero del repo que he escrito. No he hecho commit ni push y no he tocado producción. Las pruebas de concepto son tests de PHPUnit **fuera del repo**, en `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY-Iron-Body-Crm/ea963cf5-0bc6-4c7f-b9ea-04e40544815f/scratchpad/apisec/poc/`, ejecutados con `vendor/bin/phpunit --no-logging --do-not-cache-result <ruta absoluta>` para no pisar el `junit.xml` del tester. Para la única prueba que exige PostgreSQL creé una base propia, `ultron_studio_apisec_h5`, en el clúster desechable local (127.0.0.1:55432), y la borré al terminar. Huella del árbol antes y después (`git diff --binary HEAD | shasum`): vacía las dos veces. Lo que apareció sin seguimiento entretanto es de otros agentes (`smoke-h5.*`, `informe-security.md`, `UltronStudioPublicationRace*`).

**Reparto con `security`:** su informe (`informe-security.md`) cubre la criptografía, los secretos, los logs, los scripts y el CRM. Aquí va la superficie de la API: autorización por ruta, cuatro ojos, `clientRequestId`, validación, exposición, integridad vista desde la API, limitadores y llaves. Donde los dos vimos lo mismo, lo digo y no lo cuento dos veces.

---

## 0. En una pantalla

**Bloqueantes: ninguno. Altas: ninguna.** Ninguna de las 38 rutas deja a un rol leer o escribir más de lo que dice su llave. Los cuatro ojos comparan identidades. `clientRequestId` no se puede reutilizar entre personas, acciones, versiones ni cuerpos. Ninguna respuesta ni el log llevan el id del canario, el texto firmado ni la firma. El lector del motor no lanza en ninguno de los casos que probé.

| Id | Severidad | Qué | Dónde | Estado |
|---|---|---|---|---|
| AS-1 | **Media** (= M-1 de `security`) | `ULTRON_STUDIO_PUBLISHING_ENABLED=off`, `no`, `disabled` (o cualquier texto no booleano) **abre** la llave del Studio; A13 pide «valor inválido → `false`» | `config/ultron_studio.php:63`, `StudioPublishing.php:72-75` | Confirmado ejecutando (P1) |
| AS-2 | Baja (= N-6 de `security`, que me lo pasó) | `POST promote` calcula la evidencia entera (acta incluida) **antes** de comparar la huella y la devuelve en el 409, pero solo con el cupo de escritura: 30/min **por token** frente a 10/min por persona de `GET canary-evidence`. Medido: 68 actas en un minuto para una sola persona con dos sesiones | `routes/ultron-studio.php:96` frente a `:122`; `StudioPublications.php:257`; `AppServiceProvider.php:610-613`, `:649-652` | Confirmado ejecutando (P2) |
| AS-3 | Baja (= N-3 de `security`; aquí ejecutado en PG y con corrección propuesta) | Una fila cruda con `seq = 2147483647`, insertable con el DML del rol de la aplicación y sin `APP_KEY` (la amenaza de A4.1), deja la cadena **sin salida**. Volver a Legacy responde **500** (22003). Revertir responde 409. La fila no se puede borrar | `create_…publications_table.php:84` (`integer` en PG), `StudioPublications.php:1264` | Confirmado ejecutando en PostgreSQL 14 (P10) |
| AS-4 | Nota | Doble envío intercalado con el mismo `clientRequestId` y el mismo cuerpo: responde **409 `invalid_transition`** en vez del 200 `replayed` que promete A5.1 paso 3. No hay doble efecto | `StudioPublications.php:138` y `:159-175` (y sus análogos en promover, revertir y Legacy) | Confirmado el camino (P3, simulado en una conexión); falta la carrera con dos sesiones de PG |
| AS-5 | Nota (heredado, conocido) | `PlainText` deja pasar invisibles que no están en su lista (U+2028, U+00AD, U+2060, U+061C, U+180E, etiquetas U+E00xx). Desde H5 acaban en `ultron_studio_publications.reason`, que no se puede editar ni borrar | `UntrustedText.php:29-32` | Confirmado ejecutando (P4) |
| AS-6 | Nota (diseño, para `security`/architect) | La subclave de firma `k = HMAC(APP_KEY_bytes, "ultron-studio-publication")` comparte la clave HMAC con `Workforce\Secrets::hash()`. La separación de dominios depende de que todos sus mensajes lleven `\|`. Hoy no es explotable | `UltronPublishedConfig.php:462-473`, `Support/Workforce/Secrets.php:14-17`, `:56-59` | Confirmado leyendo |
| AS-7 | Nota (para el contrato `/2`) | Revertir la PUBLICADA restaura una parte sin volver a mirar `must_match`, el laboratorio ni la revalidación. Promover y publicar miran el freno y la evidencia fuera de la transacción. Es correcto en A; con mandos aplicados sería un camino sin cuatro ojos | `StudioPublications.php:1126-1153`, `:257-263` | Confirmado leyendo |
| AS-8 | Nota (documentación) | `informe-api.md` §1 dice `per_page ≤ 0 → 20`, pero responde **422**. A7 nombra `perPage` y la API lee `per_page`, así que `perPage=1000` se ignora y salen 20 | `UltronStudioPublicationController.php:33-36`, `ResolvesPagination.php:19-28` | Confirmado ejecutando (P4) |

**También lo vio `security`, y no lo repito como hallazgo:**
- B-2: el historial enseña entradas sin verificar. Comparte la corrección estructural con AS-3 (§3).
- N-2: la firma no ata el entorno.
- N-4: un UNIQUE no reconocido acaba en `laravel.log` con sus valores.
- N-1: no hay mínimo para la longitud de la clave.

**Lo que está bien, comprobado** (detalle y evidencia en §4):
- Mapa y segunda puerta de las 38 rutas.
- Administrador: 403 en las cuatro escrituras. Una llave de escritura sin `ultron.view` también da 403.
- Sin oráculo de existencia de uuids.
- Cuatro ojos por `created_by_admin_id`.
- `clientRequestId` atado a persona, acción, versión y cuerpo.
- 422 en los 19 cuerpos inválidos probados, 413 por encima de 64 KB y 422 con un JSON anidado 600 niveles.
- Barrido de exposición: 21 respuestas y el log, sin fugas.
- Campos de más ignorados (`forceCreate` con columnas del servidor).
- Disparadores: UPDATE y DELETE crudos rechazados en SQLite (23000); DELETE crudo rechazado en PG (P0001). El UPDATE en PG no lo ejecuté.
- `seq` repetido imposible por el UNIQUE.
- El lector nunca lanza: fila de 20 000 caracteres, `APP_KEY` rotada y `APP_KEY` vacía.
- Al rotar, la salida es Legacy.
- El limitador de la evidencia va por persona y por red, y `X-Forwarded-For` no lo esquiva.
- La llave del motor y la puerta del laboratorio fallan cerradas.
- Desde n8n no se puede falsificar el sello `metadata.studio`.

---

## 1. Alcance y modelo de amenazas

**Revisado:**
- Las 38 rutas de `/api/admin/ultron-studio`, con detalle en las 5 nuevas (`publication-preview`, `canary-evidence`, `promote`, `publications`, `publications/legacy`) y en las dos que pasan a reales (`publish`, `rollback`).
- Los cambios de H5 en `status`, `versions/{uuid}`, `rules?status=archived`, `engine/readings` y `compare`.
- `StudioPublications`, `StudioPublishing`, `StudioCanaryMeasures`, `StudioPublicationDeclaration` (lo que afecta a la frontera), `StudioEngineReadings`, `StudioAudit::recordDenied`, `UltronStudioException`, los controladores, `AuthorizationMap`, `PermissionCatalog`, `limitadoresDelStudio()`, `config/ultron_studio.php` y `config/marketing.php`.
- Las dos migraciones `2026_10_25_1000*`, `UltronPublishedConfig` y el sello en `UltronCommitService::persist()`.

**Fuera de alcance:**
- El CRM (lo cubren `front` y `security`).
- Los scripts de operación y el servidor (`security`/devops).
- La API interna de n8n y el resto del motor, salvo el lector y el sello.
- La pila real E2E y las carreras con dos sesiones de PG (las lleva el tester).
- Producción.
- Las 33 rutas anteriores a H5 que no cambian: solo comprobé que siguen mapeadas y con su segunda puerta; las revisiones de F, G y H4 siguen valiendo.

**Activos, por impacto:**
1. La integridad de la declaración oficial (la cadena firmada que lee el motor), con sus cuatro ojos y sus llaves.
2. El id y los textos de la conversación canaria: datos personales de una conversación real de WhatsApp.
3. La subclave de firma derivada de la `APP_KEY`.
4. La disponibilidad del Studio y de su salida segura (Legacy).
5. El libro de solo inserción.

**Actores:**
- (a) Atacante de red sin sesión.
- (b) Usuario del CRM con un rol sin Studio (Recepción, Entrenador, Administrativo, Mercadeo).
- (c) Administrador: tiene `view`, `edit_draft`, `approve` y `manage_rules`, pero no `publish` ni `rollback`.
- (d) Editor con `ultron.publish` que creó la versión.
- (e) Otra persona con `ultron.publish`.
- (f) Quien tiene solo `ultron.rollback`.
- (g) El token compartido de automatizaciones.
- (h) Atacante con el DML del rol `iron_app` sobre la base y sin `APP_KEY`: la amenaza que A4.1 dice cubrir.
- (i) Quien opera y escribe las variables de entorno.

**Fronteras de confianza:**
- CRM↔API: bearer de sesión, sin cookies, así que no hay CSRF.
- API↔base: la cadena, el libro y las versiones.
- Studio→motor: una tabla, una clase, `signatureFor()` y `describe()`.
- Studio→acta: `Artisan::call` dentro del proceso, de la que solo salen recuentos.
- Motor→Studio: el sello en `marketing_ai_actions.metadata.studio`, que cuenta la evidencia.
- Entorno→configuración: las llaves.

---

## 2. Las 38 rutas: llave, segunda puerta, limitador y veredicto

`AuthorizationMap::resolve()` sobre el router real (§5, comando 2): las 38 se resuelven y `auth:coverage --unmapped` sale vacío. Las 38 llevan el grupo `api` (`ProtectAdminPaths` → 401 y `EnforceAdminAuthorization` → 403 por la llave), `LimitRequestBodySize:65536` y uno de los dos cupos:
- **L** = `ultron-studio-read`: 120/min por token y 600/min por red.
- **E** = `ultron-studio-write`: 30/min por token y 120/min por red.

En la segunda puerta, «Persona» significa `StudioAccess::requirePerson()`: exige persona (403 `person_required` con el token compartido) y `ultron.view` (`StudioAccess.php:61-76`).

| Método | Ruta | Llave | Segunda puerta | Limitador | H5 | Veredicto |
|---|---|---|---|---|---|---|
| GET | `status` | view | Persona | L | `engine.publication`, `publishing` | OK (P5: sin id del canario; `engineBlock()` se traga el fallo y registra solo la clase) |
| GET | `catalog` | view | Persona | L | — | OK |
| GET | `draft` | view | Persona (siempre el borrador de quien pide) | L | — | OK |
| PUT | `draft` | edit_draft | Persona + `require(edit_draft)` | E | — | OK |
| POST | `draft/discard` | edit_draft | Persona + `require(edit_draft)` | E | — | OK |
| GET | `versions` | view | Persona | L | estados nuevos | OK (espacio compartido: todas visibles con `scope=all`, `StudioVersions::page()`) |
| POST | `versions` | edit_draft | Persona + `require(edit_draft)` | E | — | OK |
| GET | `versions/{uuid}` | view | Persona | L | `actions`, `publications[]` | OK |
| POST | `versions/{uuid}/validate` | view | Persona | L | — | OK |
| POST | `versions/{uuid}/submit` | edit_draft | Persona + `require` + dueño | E | — | OK |
| POST | `versions/{uuid}/approve` | approve | Persona + `require` + cuatro ojos | E | — | OK |
| POST | `versions/{uuid}/reject` | approve | Persona + `require` + cuatro ojos | E | — | OK |
| POST | `versions/{uuid}/publish` | **publish** | Persona + `require(publish)` (`StudioPublications.php:133`) + cuatro ojos (`:1422-1436`) | E | **real** | OK en autorización · AS-1, AS-4 |
| POST | `versions/{uuid}/rollback` | **rollback** | Persona + `require(rollback)` (`:333`) | E | **real** | OK · AS-7 (nota) |
| GET | `rules` | view | Persona | L | archivadas paginadas | OK (`per_page` ≤ 50; 0 o negativo → 422; estados en lista blanca) |
| POST | `rules` | manage_rules | Persona + `require` | E | — | OK |
| PATCH | `rules/{uuid}` | manage_rules | Persona + `require` | E | — | OK |
| POST | `rules/{uuid}/archive` | manage_rules | Persona + `require` | E | — | OK |
| GET | `audit` | view | Persona | L | eventos de A8 | OK (P5: sin `actor_admin_id` ni secretos) |
| GET | `engine/readings` | view | Persona | L | `governance.studioPublication` | OK (`canaryConfigured` booleano, nunca el id) |
| GET | `compare` | view | Persona | L | `integrityOk` por lado | OK |
| GET | `lab/status`, `lab/scenarios`, `lab/runs`, `lab/runs/{uuid}`, `lab/events` | view | Persona | L | — | OK (sin cambios de H5) |
| POST | `lab/estimate` | view | Persona | L | — | OK (sin cambios) |
| POST | `lab/scenarios`, PATCH `lab/scenarios/{uuid}`, POST `lab/scenarios/{uuid}/archive` | lab_manage | Persona + `require` | E | — | OK (sin cambios) |
| POST | `lab/runs` | lab_run | Persona + `require` | E + `ultron-studio-lab-run` (10/h por persona, 30/h por red) | — | OK (sin cambios) |
| POST | `lab/runs/{uuid}/cancel` | lab_run | Persona + `require` | E | — | OK (sin cambios) |
| POST | `lab/runs/{uuid}/verdict` | approve | Persona + `require` + cuatro ojos | E | — | OK (sin cambios) |
| GET | `versions/{uuid}/publication-preview` | view | Persona | L | **nueva (34)** | OK (P8: 404 con uuid inventado, igual que el detalle; P11: sin `view`, 403) |
| GET | `versions/{uuid}/canary-evidence` | view | Persona | L + **`ultron-studio-evidence`** (10/min por persona, 30/min por red) | **nueva (35)** | OK (P2: 10 y después 429; P12: `X-Forwarded-For` no lo esquiva) |
| POST | `versions/{uuid}/promote` | **publish** | Persona + `require(publish)` (`:233`) + cuatro ojos | **solo E** | **nueva (36)** | OK en autorización · **AS-2**, AS-1, AS-4 |
| GET | `publications` | view | Persona | L | **nueva (37)** | OK (B-2 de `security`: no marca entradas sin verificar) |
| POST | `publications/legacy` | **rollback** | Persona + `require(rollback)` (`:423`) | E | **nueva (38)** | OK en autorización · **AS-3**, AS-4 |

**Orden de los códigos y qué revela cada uno** (P8, P11, ejecutado):

| Quién | Petición | Respuesta |
|---|---|---|
| Sin sesión | cualquiera | 401 (`ProtectAdminPaths`), antes del permiso y del cuerpo. Solo los limitadores van antes: con el cupo agotado, 429 |
| Solo `ultron.view` | `POST promote` con un uuid existente, inventado o en MAYÚSCULAS | **403 `forbidden` las tres** (sin oráculo) |
| `ultron.publish` o `ultron.rollback` sin `ultron.view` | `publish`, `promote`, `rollback`, `legacy`, `preview` | 403 `forbidden`, `required_permission: ultron.view`; nada escrito |
| `ultron.publish`, cuerpo inválido | `POST versions/{inventado}/promote` | 422, antes del 404 |
| `ultron.publish`, llaves cerradas | uuid existente o inventado | **409 `publishing_disabled` los dos**; solo el existente deja `version.promote_refused` en el libro (deduplicado) |
| `ultron.publish`, llaves abiertas | uuid inventado o existente | 404 `version_not_found` frente a 403 o 409. No es un oráculo útil: `GET versions` ya enseña todas las versiones a quien tiene `ultron.view`, que `publish` presupone |
| `ultron.view` | `GET canary-evidence` de una versión que no está en canario | 409 `invalid_transition` con `currentStatus` (el estado ya es visible en el detalle) |
| uuid en MAYÚSCULAS | cualquiera | resuelve la misma versión (`findByUuid()` pasa a minúsculas, `StudioVersions.php:91-95`) |

---

## 3. Hallazgos

### AS-1 (media) · La llave del Studio se abre con cualquier texto no booleano

**Es el mismo hallazgo que M-1 de `security`, confirmado aquí de forma independiente.** Va con su severidad para no contradecirnos. El motivo de que sea media es el suyo: levanta una protección que el contrato llama intocable con un valor que quien opera cree que la cierra.

- **Qué:** `'publishing_enabled' => (bool) env('ULTRON_STUDIO_PUBLISHING_ENABLED', false)` (`config/ultron_studio.php:63`) y `(bool) config(...)` (`StudioPublishing.php:72-75`). `env()` solo convierte `true`/`false`/`(true)`/`(false)`/`null`/`empty`; cualquier otro texto es una cadena no vacía, es decir, `true`.
- **Evidencia (P1,** `ApiSecH5ConfigProbeTest.php`: el fichero de configuración real evaluado con cada valor del entorno):
  ```
  P1 publishing_enabled por valor del entorno: {"null":false,"\"\"":false,"\"false\"":false,"\"FALSE\"":false,"\"0\"":false,
     "\"off\"":true,"\"no\"":true,"\"disabled\"":true,"\"none\"":true,"\"nope\"":true,"\"0.0\"":true,"\"falso\"":true,"\"true\"":true,"\"1\"":true,"\"on\"":true}
  ```
  `UltronStudioPublicationConfigTest::test_cada_variable_de_a13…` prueba los valores inválidos de la puerta del laboratorio, de los dos umbrales y de la ventana, pero no los de esta llave.
- **Actor y escenario:** (i) quien opera quiere congelar las publicaciones dejando el sello. Escribe a mano `ULTRON_STUDIO_PUBLISHING_ENABLED=off`, con el mismo `off` que cierra la llave hermana `MARKETING_ULTRON_STUDIO_CONFIG`, y deja el motor en `canary` o en `all`. Tras `config:cache`, `GET /api/admin/ultron-studio/status` responde `publishing: {available: true, code: null}`. Después, (e) envía `POST /api/admin/ultron-studio/versions/{uuid}/publish {"clientRequestId":"…","previewHash":"…","acknowledgedNoEffect":[…]}` y recibe **201** en vez de 409 `publishing_disabled`.
- **Impacto:** se publica, o se promueve, mientras el propietario cree que está congelado. Siguen las demás puertas: cuatro ojos, laboratorio, `must_match`, ventana y canario. En la opción A no cambia lo que ULTRON dice ni hace, y se deshace con revertir o con Legacy. Los scripts oficiales no lo provocan: escriben `true`/`false` literales (`h5-engine-scope.sh:14-16`, `deploy-h5-backend-fase1.sh:22`).
- **OWASP API8** (configuración insegura: falla abierto).
- **Corrección (sin aplicar):** en la configuración, `'publishing_enabled' => filter_var(env('ULTRON_STUDIO_PUBLISHING_ENABLED', false), FILTER_VALIDATE_BOOLEAN)`, que da `false` con `off`, `no` o cualquier otro texto; y en `flagEnabled()`, `config(...) === true`.
  - **Adyacente, de H4:** `'lab' => ['enabled' => (bool) env('ULTRON_STUDIO_LAB_ENABLED', false)]` tiene la misma forma. Allí lo amortiguan los otros tres cerrojos del laboratorio (driver, presupuesto, clave), pero conviene el mismo arreglo.
- **Prueba negativa:** con la variable del entorno a `off`, `no`, `disabled`, `' false '` y `0.0`, `publishing_enabled` vale `false`; con el motor en `canary`, `POST publish` responde 409 `publishing_disabled` y `status.publishing.code` es `publishing_disabled`.
- **Dueños:** backend (B-S) y tester.

### AS-2 (baja) · `POST promote` ejecuta la evidencia, acta incluida, fuera del limitador de la evidencia

- **Qué:** A6 da a la evidencia «su propio limitador (10/min por persona), porque es la lectura más cara», y la ruta `GET versions/{uuid}/canary-evidence` lo lleva (`routes/ultron-studio.php:96`, `AppServiceProvider.php:649-652`). Pero `promote()` calcula la misma evidencia antes de comparar la huella (`StudioPublications.php:257`, `evidenceFor()` en `:1062-1095`: los turnos, los frenos, el acta con `Artisan::call` y `describe()`). Además la devuelve **entera** en el 409 `canary_evidence_insufficient` o `evidence_changed`. La ruta solo lleva el cupo de escritura (`routes/ultron-studio.php:100`/`:122`; `AppServiceProvider.php:610-613`): 30/min **por token**, y cada inicio de sesión da un cupo nuevo.
- **Evidencia (P2,** `ApiSecH5LimiterProbeTest.php`, con los limitadores activos; cuento las actas por su consulta a `marketing_messages`, que solo hace el acta en esta ruta):
  ```
  P2 GET evidence: {"200":10,"429":2} actas=10
  P2 POST promote (sesión 1): {"409":28} con evidencia=28 actas acumuladas=38
  P2 POST promote (sesión 2, misma persona): {"409":30} actas acumuladas=68
  ```
- **Actor y escenario:** (e), con `ultron.publish` y sin ser autor (no es el Administrador por defecto); llaves abiertas, motor en `all` y una versión en CANARY, es decir, la fase 3. Un bucle de `POST /api/admin/ultron-studio/versions/{uuid}/promote {"clientRequestId":"<uuid nuevo>","evidenceHash":"000…0"}` desde N sesiones da 30·N actas por minuto, con un techo de 120/min por red. Desde varias redes, más.
- **Impacto real:** consumo de CPU y de base, sin fuga: la evidencia ya la puede leer esa persona. El coste de cada acta crece con la vida del canario, porque `UltronCanaryReport` carga **todos** los mensajes y acciones de la conversación canaria desde la entrada, sin tope (`UltronCanaryReport.php:70-80`; `--turns` solo recorta después). Con el tráfico de hoy (3 turnos en 7 días) es pequeño; por eso es baja.
- **OWASP API4** (consumo de recursos sin límite por coste).
- **Corrección (sin aplicar):** añadir `->middleware('throttle:ultron-studio-evidence')` a `POST versions/{uuid}/promote`. La clave del limitador con nombre incluye su nombre, así que la ruta GET y promover compartirían el cubo de 10/min por persona y 30/min por red. Alternativa: contar el intento dentro de `evidenceFor()` con `RateLimiter::attempt` y la misma clave por persona.
- **Prueba negativa:** con los limitadores activos, 10 `GET canary-evidence` y después un `POST promote` de la misma persona desde otra sesión responden **429**; y 11 `promote` en un minuto desde dos sesiones dan un 429 en el undécimo.
- **Dueños:** backend (B-S) y tester.

### AS-3 (baja) · `seq = 2^31−1` insertado con DML deja la cadena sin salida (Legacy responde 500)

- **Qué:** `seq` es `unsignedInteger` (`create_…publications_table.php:84`), que en PostgreSQL es `integer` (máximo 2 147 483 647) y sin CHECK. Toda escritura del Studio calcula `seq = cabeza + 1` (`StudioPublications.php:1264`), también volver a Legacy (`:441`), que es la salida de una cabeza rota. La fila no se puede borrar con `iron_app` (disparador `uspub_forbid_delete`). `security` lo anotó como N-3 sin proponer cambio de código; yo lo he ejecutado y lo dejo como baja, porque rompe una garantía escrita justo frente a la amenaza que A4.1 dice cubrir. Esa garantía está en `StudioPublications.php:415-417` («Una cabeza que no verifica no es Legacy: esto es, justamente, la salida») y en `informe-api.md` §7 («Legacy siempre funciona, también sobre una cabeza rota»).
- **Evidencia (P10,** `ApiSecH5PgSeqProbeTest.php` en PostgreSQL 14, base propia y desechable, `UNIT_DB=ultron_studio_apisec_h5 docs/ultron-studio/ops/pgunit.sh …`):
  ```
  P10 tipo de seq en PG: integer · sello del motor: fallback/signature_invalid
  P10 legacy: 500 QueryException SQLSTATE 22003
  P10 revertir: 409 publication_head_invalid
  P10 borrar la fila cruda con el rol de la app: rechazado P0001
  ```
- **Actor y escenario:** (h). Con DML y sin `APP_KEY`, por ejemplo una inyección SQL en cualquier otra parte de la aplicación o una credencial de base filtrada, basta un `INSERT INTO ultron_studio_publications (…, seq, …) VALUES (…, 2147483647, …)` con firma inventada. Desde ese momento:
  - `POST /api/admin/ultron-studio/publications/legacy` → 500;
  - `publish`, `promote` y `rollback` → 409 `publication_head_invalid`;
  - el motor sella `fallback/signature_invalid` en cada turno.
- **Impacto real:** el Studio pierde su salida de gobierno de forma permanente, hasta que el rol dueño desactive el disparador o borre la fila, cosa que hoy no describe ningún runbook. En la opción A el motor no cambia de comportamiento. Exige acceso de escritura a la base.
- **OWASP API8 / integridad de datos.**
- **Corrección propuesta (sin aplicar; dueño database-specialist):** un disparador `BEFORE INSERT` en PG y en SQLite que exija `NEW.seq = COALESCE((SELECT max(seq) FROM ultron_studio_publications), 0) + 1` y `NEW.previous_publication_id IS NOT DISTINCT FROM (la fila con max(seq))`. Con él, cualquier fila cruda solo puede ser la siguiente, y Legacy siempre tiene sitio en la de después. Cierra también B-2 de `security` (filas con `seq` por debajo de la cabeza). Las carreras siguen decidiéndolas el UNIQUE, porque dos que leen el mismo máximo chocan en `seq`. Un `CHECK (seq BETWEEN 1 AND …)` solo **no** basta: el atacante inserta el tope. Además:
  - traducir el 22003 de la inserción a un 409 con un código claro, mejor que un 500;
  - escribir en el runbook la salida inmediata (`h5-engine-scope.sh off`) y la reparación con el rol dueño y autorización del propietario, como propone N-3.
- **Prueba negativa (PG):** una fila cruda con `seq = max + 5`, `0`, `-1` o `2147483647` → rechazada por el disparador (P0001); con una cabeza rota de `seq` contiguo, Legacy → 201.
- **Dueños:** database-specialist (migración aditiva), backend (traducir el 22003), tester (PG) y documentación (runbook).

### AS-4 (nota) · Un doble envío intercalado responde 409 en vez de 200 `replayed`

- **Qué:** el reintento se comprueba una sola vez antes de la transacción (`StudioPublications.php:138`). Supongamos que la primera petición se confirma después de que la segunda pase esa comprobación, pero antes de que lea la cabeza dentro de su transacción (`:159`). Entonces `D::compose()` ve la versión ya en canario, salta al `catch` (`:171-175`) y `raceRefusal()` responde 409 `invalid_transition` con `currentStatus: CANARY`. A5.1 paso 3 promete 200 `replayed` para «misma `clientRequestId`, misma persona y mismo cuerpo». Solo el 23505 vuelve a mirar el reintento (`:209-216`). Lo mismo pasa en promover, revertir (`invalid_transition`/`publication_moved`) y Legacy (`already_legacy`).
- **Evidencia (P3,** `ApiSecH5ChainProbeTest.php::test_p3…`): la «primera» se inyecta con `beforeStatement('substr(effective_json', …)` justo antes de la lectura de la cabeza.
  ```
  P3 segunda (intercalada): 409 invalid_transition · …
  ```
  En una sola conexión, la primera queda dentro de la transacción de la segunda y se deshace con ella. Por eso la tercera petición, secuencial, da 201 en la prueba; con dos sesiones de PG daría 200 `replayed`. Lo demostrado es el camino; la carrera real es del tester.
- **Impacto:** ninguno de seguridad. El UNIQUE de `seq` y la transición condicionada impiden el doble efecto. El CRM enseña un error aunque la publicación salió bien.
- **Corrección (opcional):** en las cuatro escrituras, ante `invalid_transition`, `canary_in_progress`, `already_legacy` o `publication_moved` lanzados desde dentro de la transacción, volver a llamar a `replay()` fuera de ella antes de lanzar, igual que con el 23505.
- **Prueba:** carrera con dos sesiones de PG y la misma llave y el mismo cuerpo; la perdedora tiene que dar 200 `replayed`. Es una variante del `UltronStudioPublicationRaceTest` del tester.

### AS-5 (nota, heredado y conocido) · Invisibles que `PlainText` no conoce acaban en la cadena de solo inserción

- **Evidencia (P4):** `POST rollback` con un `reason` que lleva U+2028, U+00AD, U+2060, U+061C, U+180E o U+E0041 (etiqueta) pasa la validación (409 `not_active_publication`, es decir, superada la forma). En cambio, U+200B y U+202E sí están en la lista: en `note` responden 422.
- **Por qué se anota en H5:** es la clase I-2/B-2 de H4, que sigue pendiente para el Hito 7 (`UntrustedText::INVISIBLE`, `:32`). Desde H5, `note` y `reason` van a `ultron_studio_publications.reason`, que la base impide editar o borrar, y al `diff` del libro. Lo que entre ya no se limpia sin el procedimiento del rol dueño.
- **Corrección (decisión del propietario, Hito 7; mejor antes de la fase 2):** ampliar `INVISIBLE` con U+00AD, U+034F, U+061C, U+115F-1160, U+17B4-17B5, U+180B-180F, U+2028-2029, U+2060-2064, U+206A-206F, U+3164, U+FFA0 y U+E0000-E007F.
- **Prueba negativa:** esos caracteres en `note` y `reason` → 422.

### AS-6 (nota de diseño) · La subclave de firma comparte la clave HMAC con `Workforce\Secrets::hash()`

- **Hecho:** `k = hash_hmac('sha256', 'ultron-studio-publication', APP_KEY_decodificada, true)` (`UltronPublishedConfig.php:462-473`). Por su parte, `Secrets::hash($s) = hash_hmac('sha256', $s, APP_KEY_decodificada)` (`Support/Workforce/Secrets.php:14-17`, `:56-59`). Por tanto `hex2bin(Secrets::hash('ultron-studio-publication')) === k`. El `decide_token` usa otra clave: la cadena cruda con el prefijo `base64:` (`UltronDecideToken.php:145`), como ya anotó `security`.
- **Por qué hoy no es explotable:** todos los que llaman a `Secrets::hash` ponen un prefijo con `|` (`'pairing|'`, `'kiosk|'` o `"{$purpose}|{$id}|"`: `AuthorizationCodeService.php:73,121`, `DeviceRegistry.php:47,88,105`, `KioskTokens.php:75,98,108`). La etiqueta no lleva `|`, así que ningún mensaje de Workforce puede ser la etiqueta. Además, esos HMAC no se devuelven al cliente.
- **Riesgo:** la separación depende de una invariante que no está escrita en ninguna parte. Bastaría un `Secrets::hash($entradaDelUsuario)` guardado donde se pueda leer para entregar la subclave, y con ella firmar declaraciones.
- **Recomendación** (para `security`/architect; no cambia H5, porque el fixture S-2 congela la derivación):
  - escribir la invariante en los dos ficheros;
  - en un contrato `/2`, derivar con `hash_hkdf('sha256', $appKey, 32, 'ultron-studio-publication')`, que por construcción no coincide con un HMAC de un mensaje, o dar a `Secrets` su propia subclave.

### AS-7 (nota, para el contrato `/2`) · Lo que la opción A tolera y un contrato con efectos no

- Revertir la PUBLICADA vuelve a declarar la parte resto anterior (`restoreSource()`, `StudioPublications.php:1126-1153`). Solo exige integridad (huella de contenido, firma de la entrada anterior y estado RETIRED). No vuelve a mirar `must_match` contra el motor de hoy, ni el laboratorio, ni la revalidación con el catálogo actual. Es lo que manda A5.3: deshacer no depende de otro estado. Por eso la declaración restaurada puede nacer con deriva, y `status` y `publication-check` la enseñan.
- Promover y publicar comprueban el freno, la evidencia y la ventana **fuera** de la transacción (`:257-263`, `:147-151`). La ventana de carrera es de milisegundos.
- En A no daña a nadie. En `ultron-engine-config/2`, con mandos aplicados, quien tenga solo `ultron.rollback` haría efectiva sin cuatro ojos una declaración que contradice al motor. Antes de `/2`: restaurar con `must_match` y revalidación, o exigir cuatro ojos al restaurar, y volver a mirar el freno dentro de la transacción. **Dueño:** architect.

### AS-8 (nota, documentación) · Paginación: dos textos que no dicen lo que hace el código

- `informe-api.md` §1 («`per_page` (≤ 0 → 20; > 50 → 50)») no es lo que pasa. P4 da: `per_page=0` → **422**, `-1` → 422, `abc` → 422, `1000` → 50, `page=0` → 422, `page=9223372036854775807` → 200 (sin 500) y `page=99999999999999999999` → 422.
- A7 escribe `perPage`, pero la API lee `per_page`, como `GET versions`: `perPage=1000` se ignora y salen 20.
- **Sin impacto de seguridad:** el tope de 50 se cumple. Hay que alinearlo en la Enmienda 2 (documentación y lead).

---

## 4. Los siete puntos del encargo, uno a uno (lo comprobado)

1. **Autorización por ruta.**
   - El mapa coincide con `require()` en las 38 rutas (§2). Defensa doble en las cuatro escrituras (`StudioPublications.php:133`, `:233`, `:333`, `:423`) y `ultron.view` exigido siempre (`StudioAccess.php:73`, `:102-106`).
   - BFLA: el Administrador recibe 403 en `publish`, `promote`, `rollback` y `legacy` (`UltronStudioPublicationAccessTest`, ejecutada: 25/25 junto con `UltronStudioPermissionsTest` y `UltronEngineIsolationTest`). Una llave de escritura sin `view` recibe 403 `ultron.view` y no escribe nada (P11). El token compartido y los roles sin Studio reciben 403 (prueba del implementador, ejecutada).
   - BOLA: las versiones son un espacio compartido de `ultron.view` (`StudioVersions::page()`, `scope=all`), así que las rutas nuevas no dejan ver nada que no se vea ya en `GET versions/{uuid}`. El uuid en MAYÚSCULAS resuelve la misma versión. Con un uuid inventado no hay oráculo (P8).
   - Cuatro ojos por **identidad** (`created_by_admin_id === actor->id`, `StudioAccess.php:127-130`) al publicar y al promover, con `version.four_eyes_denied` deduplicado. Revertir y Legacy no los piden, por contrato.
   - `clientRequestId` (P7):

     | Caso | Respuesta |
     |---|---|
     | Misma persona y mismo cuerpo, también en MAYÚSCULAS | 200 `replayed`, con la entrada |
     | Otra nota, otra versión, revertir o Legacy con la misma llave | 409 `client_request_reused` |
     | **Otra persona**: mismo cuerpo, revertir o uuid inventado | 409 `client_request_reused`, **sin la entrada** |

     En todos los casos, una sola entrada en la cadena.
2. **Validación de entrada (P4).** 422 en los 19 cuerpos inválidos de `publish`:

   | Campo | Casos que dan 422 |
   |---|---|
   | `acknowledgedNoEffect` | 65 elementos, repetidos, con espacio, anidado, entero, objeto, cadena, 81 caracteres, ausente |
   | `previewHash` | en MAYÚSCULAS, de 63 caracteres, en una lista |
   | `note` | 501 caracteres, BEL, NUL, RLO, ZWSP |
   | `clientRequestId` | que no es uuid, uuid entre llaves |

   Además:
   - `reason` de 4 caracteres, de solo espacios o de 501 caracteres → 422; 500 emojis de 4 bytes pasa (columna de 500 caracteres).
   - Cuerpo de 70 KB → 413 `payload_too_large`; JSON con 600 niveles de anidación → 422 sin 500.
   - Paginación: ver AS-8.
   - `rules?status=` con lista blanca de estados.
3. **Exposición (P5).**
   - Barrido de 21 respuestas por el ciclo entero, con una persona que solo ve y otra que publica: `status`, `engine/readings`, detalle, `publications` (también con `per_page=50`), `audit`, `canary-evidence`, `compare`, la vista previa, los 409 (`preview_stale`, `no_effect_not_acknowledged`, `canary_evidence_insufficient`, `evidence_changed`, `already_legacy`, `publication_head_invalid`) y los 201.
   - En ninguna aparecen el id del canario (`987654321`), una firma, el principio de un texto firmado, `effective_json`, `"signature"`, `client_request_id`, `actor_admin_id` ni `SQLSTATE`.
   - El log queda **vacío** en el camino normal (escucha de `MessageLogged`). Los caminos de error registran solo `error_class` (lectura del código; coincide con `security` §3.8).
   - Los DTO salen de listas explícitas (`entryDto` `:654-673`, `stateDto`, `previewFrom`, `evidenceDto`). `COLUMNS` (`:108-112`) no lleva `effective_json`, y `describe()` no lleva el id.
4. **Integridad.**
   - Inserción solo con `forceCreate` y columnas que calcula el servidor; `$guarded = ['*']`. Los campos de más del cuerpo se ignoran (P9: `seq`, `action`, `actor_*`, `effective_json`, `signature`, `lab_gate`, `acknowledged`, `evidence`, `reason`, `engine_contract`…; la fila sale con los valores del servidor y el motor la sella `applied`).
   - El modelo lanza al actualizar o borrar. Los disparadores rechazan UPDATE y DELETE crudos (P6, SQLite: 23000) y el DELETE crudo en PG (P10: P0001; el UPDATE en PG no lo ejecuté, lo cubren la migración y la suite PG del implementador), y no se pueden repetir `seq`, `uuid` ni `client_request_id` (P6: 23000).
   - La cabeza se verifica con `hash_equals` sobre la huella y la firma, el contrato exacto y la decodificación estricta atada a `uuid` y `seq` (`:1211-1226`), y el núcleo del Studio es **más** estricto que el lector del motor (uuid y huella en minúsculas, ids ≥ 1, textos cortos ASCII): falla del lado seguro.
   - El reintento no rehace una entrada con otro cuerpo (P7). El 23505 se resuelve fuera de la transacción, y `DB::transaction` deshace la entrada, la transición y el evento juntos: no queda nada a medias. El rechazo auditado (`recordDenied`) va aparte a propósito.
5. **Limitadores.**
   - La evidencia va por **persona**: resuelve el admin desde el token antes de autenticar (`AppServiceProvider.php:627-636`), con 10/min y después 429 (P2), y por red con 30/min.
   - `ClientNetwork` usa `REMOTE_ADDR` y agrupa IPv6 por /64. Sin `TrustProxies` configurado (`bootstrap/app.php`), `X-Forwarded-For` y `X-Real-IP` no cambian el cubo: 36 lecturas de 4 personas con cabeceras distintas dan `{"200":30,"429":6}` (P12).
   - Lectura y escritura van por token (decisión de F). La única brecha de coste es AS-2.
6. **Llaves de configuración.**
   - La llave del motor solo lee con `canary` o `all`, y todo lo demás es `off` (`config/marketing.php`; la normalización de `StudioPublishing::engineScope()` es la misma, con 12 valores probados por el implementador).
   - La puerta del laboratorio solo se dispensa con `waived`. Los umbrales se recortan a 1–168 y 1–100. La ventana mal escrita toma la del contrato; vacía, no hay ventana (decisión de A13).
   - `ENGINE_READS_VERSIONS = true` no abre nada por sí sola: publicar y promover exigen las dos llaves (`keyRefusalFor()`).
   - Con la cadena vacía, revertir y Legacy no escriben (409 `not_active_publication` / `already_legacy`). Con el motor en `off`, el lector no hace ninguna consulta (prueba `DB::listen` del implementador; `UltronEngineIsolationTest` ejecutada).
   - La excepción es la llave del Studio: AS-1.
7. **El lector del motor (P6).**

   | Caso | Lo que hace el lector |
   |---|---|
   | Fila cruda de 20 000 caracteres con huella correcta | `fallback/hash_mismatch` sin lanzar (tope `substr(…, 1, 16385)` en SQL, `UltronPublishedConfig.php:264`) |
   | `APP_KEY` rotada | `fallback/signature_invalid`; el Studio responde `publication_head_invalid`; Legacy → 201, y el motor pasa a `legacy`; la versión en canario queda en `ROLLED_BACK` |
   | `APP_KEY` vacía | `fallback/read_failed` sin lanzar; `status` → 200 |

   - Comparación en tiempo constante (`hash_equals`, `:302`, `:311`).
   - El punto de guardado con una transacción abierta (`:271`): lectura del código y mutación M5 del implementador en PG.
   - Dos cabezas con el mismo `seq` son imposibles por `->unique()` (migración `:84`; P6).
   - El caso que sí rompe la salida es AS-3.

**Además: el sello no se puede falsificar desde n8n.**
- `metadata.studio` lo pone `persist()` en su base (`UltronCommitService.php:2458-2475`).
- `array_merge` dejaría ganar una clave `studio` de `$extraMetadata`, pero las cuatro llamadas (`:1236`, `:2018`, `:2259`, `:3851`) construyen ese array en el código, sin claves del cuerpo.
- Las escrituras posteriores de `metadata` (`tools_executed`, `staff_review`…) conservan el sello.
- Quien tiene el secreto interno puede crear turnos del canario, pero no elegir su sello. La evidencia vale lo que vale el camino del commit: ver §7.

---

## 5. Lo que ejecuté (comando y resultado)

Todo desde `backend/` del worktree. La salida completa está en `…/scratchpad/apisec/out-*.txt` (se purga a los tres días); lo relevante va aquí.

| # | Comando | Resultado |
|---|---|---|
| 1 | `php -d memory_limit=2G vendor/bin/phpunit --no-logging --do-not-cache-result tests/Feature/UltronStudio/UltronStudioPublicationAccessTest.php tests/Feature/UltronStudio/UltronStudioPermissionsTest.php tests/Feature/UltronStudio/UltronEngineIsolationTest.php` | `OK (25 tests, 2184 assertions)` en 6 s |
| 2 | `php artisan tinker --execute='… AuthorizationMap::resolve($r) …'` sobre las rutas `api/admin/ultron-studio` | 38 rutas, todas con su llave (tabla del §2) |
| 3 | `php artisan route:list --path=admin/ultron-studio --json -vv` | 38; las 38 con `api`, `LimitRequestBodySize:65536` y `throttle:ultron-studio-read` o `-write`; `canary-evidence` además con `-evidence`; `lab/runs` (POST) con `-lab-run` |
| 4 | `php artisan auth:coverage --unmapped` | sin salida, exit 0 |
| 5 | `phpunit … poc/ApiSecH5ConfigProbeTest.php` (P1) | `OK (1 test, 6 assertions)`: valores en AS-1 |
| 6 | `phpunit … poc/ApiSecH5LimiterProbeTest.php` (P2, P12) | `OK (2 tests, 19 assertions)`: AS-2 y §4.5 |
| 7 | `phpunit … poc/ApiSecH5InputProbeTest.php` (P4, P8, P9, P11) | `OK (4 tests, 58 assertions)`: §2, §4.2 y §4.4 |
| 8 | `phpunit … poc/ApiSecH5ChainProbeTest.php` (P3, P5, P6, P7) | `OK (4 tests, 56 assertions)`: AS-4 y §4.3, §4.4 y §4.7 |
| 9 | `psql … -c "create database ultron_studio_apisec_h5"` · `UNIT_DB=ultron_studio_apisec_h5 docs/ultron-studio/ops/pgunit.sh --no-logging --do-not-cache-result …/poc/ApiSecH5PgSeqProbeTest.php` (P10) · `drop database` | `OK (1 test, 9 assertions)`: AS-3. La base, borrada (`count = 0`) |
| 10 | Huella del árbol antes y después | `git diff --binary HEAD \| shasum`: `e3b0c442…` (vacío) las dos veces |

Las 12 pruebas de concepto **pasan cuando el hallazgo está presente**: afirman el comportamiento actual. Para convertirlas en regresión hay que invertir las aserciones de P1, P2, P3 y P10 cuando se corrijan. Las demás (P4, P5, P6, P7, P8, P9, P11 y P12) ya afirman el comportamiento correcto y pueden pasar tal cual a la suite.

---

## 6. Lo que no pude verificar

- **Carreras reales con dos sesiones de PostgreSQL:** las de AS-4 y las de A12 («20 publicaciones simultáneas», «promover contra revertir»…). Mi P3 inyecta la rival en la misma conexión. Vi aparecer `UltronStudioPublicationRaceTest.php` del tester, pero no lo ejecuté.
- **El servidor:**
  - que no haya un proxy o una CDN delante (sin `TrustProxies`, `REMOTE_ADDR` es el cliente solo si nginx habla directo con PHP-FPM, como dice `ClientNetwork`);
  - `APP_DEBUG=false` (el cuerpo genérico del 500 de AS-3);
  - los privilegios reales de `iron_app` sobre la tabla nueva (ver `security` §4).
- **El coste real del acta** con el volumen de la conversación canaria de producción: lo estimé leyendo el código, no lo medí.
- **El CRM, el E2E con la pila real y las suites completas:** no los ejecuté. Otros agentes los tenían en marcha y el encargo pedía pruebas cortas.

---

## 7. Riesgos residuales y decisiones del propietario

- **Cuatro ojos por cuenta, no por persona.** Un Super Admin tiene `users.manage` y `roles.manage`. Puede crearse una segunda cuenta con `ultron.publish` y publicar su propia versión: el modelo no lo puede impedir. Es un control organizativo; si se quiere, se añade una alerta cuando quien publica y quien creó la cuenta de la autora son la misma persona. **Decisión del propietario.**
- **La evidencia del canario vale lo que vale el commit.** Quien tenga el secreto interno de n8n puede generar turnos del canario, que se sellan con la cabeza real y no se pueden falsificar, y adelantar el umbral de turnos, aunque no el de 24 h. Ya está en la superficie del secreto interno (memoria «Qué abre el secreto interno»).
- **Una sola subclave para toda la cadena.** Rotar la `APP_KEY` obliga a volver a Legacy y a un ciclo nuevo. Comprobado en P6. El procedimiento está en `security` §2 y B-3.
- **Secretos que rotar: ninguno.** No he visto ni impreso ningún secreto; las claves de las pruebas son las de los fixtures.
- **Necesitan autorización u orden:** las correcciones de AS-1 (backend) y AS-3 (migración aditiva: database-specialist). Las demás son notas.

---

## 8. Para cada agente

- **backend (B-S):**
  - AS-1: `filter_var(…, FILTER_VALIDATE_BOOLEAN)` y `=== true`; lo mismo en la llave del laboratorio.
  - AS-2: limitador de la evidencia también en `promote`.
  - AS-3: traducir el 22003 a un 409.
  - AS-4: opcional.
- **database-specialist:** AS-3, con el disparador `BEFORE INSERT` de contigüidad en PG y SQLite (cierra también B-2 de `security`), y su prueba de que conserva el WHERE de los índices parciales de versiones (la migración no toca esa tabla).
- **tester:**
  - las pruebas negativas de AS-1, AS-2 y AS-3 (PG) y la carrera de AS-4 con dos sesiones;
  - pasar a la suite las pruebas de concepto que ya afirman lo correcto: P5 (barrido de exposición y log), P7 (matriz de `clientRequestId`), P8 (orden y oráculo), P9 (campos de más), P11 (llave sin `view`) y P12 (`X-Forwarded-For`). Están en `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY-Iron-Body-Crm/ea963cf5-0bc6-4c7f-b9ea-04e40544815f/scratchpad/apisec/poc/` (se purga a los tres días).
- **security:** AS-6 (invariante de la clave HMAC compartida con `Workforce\Secrets`). Coincidimos en M-1 (= AS-1), N-3 (= AS-3, aquí ejecutado en PG) y N-6 (= AS-2, ejecutado).
- **architect:** AS-6 (derivación para `/2`) y AS-7 (restaurar y releer el freno con mandos aplicados).
- **documentation-specialist / lead:**
  - AS-8, en la Enmienda 2;
  - el runbook de AS-3 (salida con `h5-engine-scope.sh off` y reparación con el rol dueño);
  - AS-5 entre los pendientes del Hito 7, mejor antes de la fase 2.

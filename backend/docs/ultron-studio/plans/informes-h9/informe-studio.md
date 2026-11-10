# Hito 9 · paquete S9 (S9-a + S9-b) · el gobierno de las estrategias en el Studio

**Fecha:** 09-11-2026. **Árbol:** `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h8-voice`, HEAD `ab54a256` (el árbol ya traía cambios sin confirmar del H8 y de otros paquetes del H9; no son míos y no los he tocado). **Sin commit. Sin red. Producción: nada.**

**Estado: entregado para revisión del lead**, con una prueba roja A PROPÓSITO (la de traspaso a `AuthorizationMap`, §4) y las guardas de recuento del H8 que hay que adaptar en la rama del H9 (§6.3).

## 0. Resumen en cifras

- **Código nuevo:** 10 servicios, 7 modelos, 3 controladores, 1 comando; un bloque nuevo en `routes/ultron-studio.php` (30 rutas) y el bloque `strategies` de `config/ultron_studio.php`.
- **Pruebas nuevas:** 8 ficheros en `tests/Feature/UltronStudio/Strategies/` (7 de pruebas + el trait de fixtures): **32 pruebas; SQLite 597 aserciones y PostgreSQL 591; en los dos, 31 en verde y 1 roja a propósito** (traspaso a `AuthorizationMap`).
- **Mutaciones por muestreo:** 14 mutantes sobre un clon aislado, **14 cazados** (3 sobrevivieron a la primera pasada: uno ya lo cazaba la prueba N5/N6, posterior al clon, y dos destaparon huecos que cerré con dos comprobaciones nuevas).
- **Regresión** (`tests/Feature/UltronStudio`, `tests/Unit/UltronStudio`, `TelephonyRoutesTest`, `tests/{Feature,Unit}/Outreach`): 4 709 pruebas, 24 caídas: **10 por mis 30 rutas** (recuentos, recorrido y `auth:coverage`; se arreglan con §2 y §6.3), 13 del esquema de DB9 y de B9-facts, 1 dependiente del orden y verde aislada (§7.3).
- **Pint** limpio en todo lo de S9 (incluidos los dos bloques de `routes/` y `config/`).
- **Sobre el esquema REAL de DB9** (`2026_11_12_100001`): mis modelos y servicios están alineados columna a columna con su migración (§3) y el ciclo completo pasa sus disparadores de SQLite.
- **Ficheros existentes del H5–H8:** ninguno modificado (regla 4 del lead). Mi cambio de `STUDIO_ROUTE_COUNT` lo revirtió el lead; el mío en `StudioAuditEvent::SUBJECT_TYPES` lo deshice yo (los sujetos viven en `StudioStrategyConfig`, §8 Q-3). Solo toqué lo que el encargo me da: mi bloque de `routes/ultron-studio.php` y el bloque `strategies` de `config/ultron_studio.php`.

## 1. Qué entrega, por fichero

### 1.1 Servicios (`app/Services/UltronStudio/`)

| Fichero | Qué es | Contrato |
|---|---|---|
| `StudioStrategyConfig.php` | Las llaves (D9-13), las banderas (`enabled`, `publishing_enabled`), la ventana propia, la puerta de simulación, los umbrales del canario, los 4 sujetos del libro y los bloques `governance` y `can`. `assertEnabled()` → 409 `strategies_disabled` | §9.1, D9-13 |
| `StudioStrategyValidator.php` | **S9-a.** Dos capas, todas las claves a la vez: estructura con `Outreach\Core\StrategyContent` (gramática, catálogo, límites, horario) y hallazgos de gobierno: plantillas (existe, APROBADA, misma huella, canal, finalidad, categoría, R-01/02/03/06), referencia del Studio (N3: `studio_reference_invalid`, `studio_baseline_superseded` por P9-10, `payment_links_disabled`, aviso `canary_only_declared`) y aviso `channel_not_live` (W9/V9). Pura respecto de lo que lee: el contexto entra como `Closure` | D9-5, D9-6, D9-11.4, T11, T18 |
| `StudioMessageTemplates.php` | **S9-a.** Plantillas versionadas por clave (`key` + `number`): contenido cerrado por canal (WhatsApp, asistido, voz), marcadores cerrados, línea de baja obligatoria, categoría por finalidad, restricciones R-01/02/03/06 y datos de contacto; crear (versión siguiente), listar, leer con la **referencia `{uuid, hash}` para instanciarla en un paso** y la vista previa; aprobar o rechazar a cuatro ojos por persona | D9-6, D9-15 |
| `StudioPersonBindings.php` | Vínculos cuenta → persona y **cuatro ojos por persona** (`personOf`, `requirePerson`, `samePerson`): crear (para OTRA cuenta y nunca para la propia persona; quien crea es una persona verificada), verificar (otra persona vinculada, distinta de la vinculada y de quien lo creó), revocar (una vez, con motivo), arranque de las dos primeras (origen `bootstrap`), aviso en el libro si se revoca y se vuelve a vincular en < 24 h | D9-11.2, P9-4, L9-6, L9-7 |
| `StudioStrategies.php` | Estrategias (`key`, `purpose` inmutables; idempotente por `clientRequestId`), borrador con `baseRevision` (UPDATE condicionado; 409 `draft_moved`), archivo (sin versiones vivas), listado sin N+1 con el estado de la cadena | D9-11.0, §7 #2–#5, #20 |
| `StudioStrategyVersions.php` | Congelar (JSON canónico, huella, catálogo y evaluador, referencia del Studio; `VALIDATED` o `REVIEW_REQUIRED`; 422 con todas las claves), aprobar (otra persona; relee los bytes y revalida AHORA; avisos EXACTOS; simulación sin bloqueantes DE LA VERSIÓN) y rechazar (con nota); diferencias con la anterior | D9-11.0–11.2, D9-12 |
| `StudioStrategySimulator.php` | **Puro**: puerta de inscripción → escalera N1–N7 por canal (en sombra) → selector → `DecisionTrace` y `DecisionTraceText`; recuentos, hallazgos de D9-12 y huella de las decisiones. Misma entrada, misma salida | D9-12, T12 |
| `StudioStrategySimulations.php` | Simulación `synthetic` (≤ 50 personas, hechos tipados contra el catálogo, 422 con todas las claves) y `sample` (prefiltro del disparador ahora, muestra determinista ≤ 200, foto de `BatchSnapshotBuilder` en solo lectura, personas por `subjectRef`); guarda solo recuentos, hallazgos y huellas | D9-12 |
| `StudioStrategyPublications.php` | La cadena firmada: mensaje y firma con la ÚNICA implementación (`Outreach\Core\StrategyChainSignature`, recibida como `Closure`), inserción (cabeza + 1, `previous_publication_id`, `prev_hash`), verificación (núcleo + la fila dice lo mismo que su mensaje: `row_mismatch`), estado por estrategia (también por lotes), historial con `verified` | D9-11.3, L9-23 |
| `StudioStrategyPublishing.php` | Publicar (APPROVED → CANARY), promover (CANARY → PUBLISHED; la anterior → RETIRED; evidencia y su huella), pausar, reanudar y revertir (ROLLED_BACK; la RETIRED íntegra vuelve con entrada NUEVA; sin ninguna, `off`); idempotencia por `clientRequestId`; cerrojo de la cadena y carrera → 409 `publication_moved` | D9-8, D9-11.0, #23, #25, T1, T2 |
| `StudioStrategyEvidence.php` | La evidencia del canario medida en el servidor, en SOLO LECTURA sobre `outreach_*`: horas, contactos que salieron en vivo, negativas N1 posteriores, `ambiguous` abiertos, tasa de *opt-out* ≤ 5 % (P9-20). Sin las tablas o sin poder leerlas, NO PASA. Huella sin las horas exactas | D9-11.0, T2 |

### 1.2 Modelos (`app/Models/UltronStudio/`)

`StudioStrategy`, `StudioStrategyVersion`, `StudioStrategyReview`, `StudioStrategySimulation`, `StudioStrategyPublication`, `StudioMessageTemplate`, `StudioPersonBinding`. Cada uno lanza al cambiar lo que DB9 congela (mismas listas `*_MUTABLE_COLUMNS`) y al borrar; los de solo inserción, al actualizar. Tercera capa: los disparadores de DB9.

### 1.3 Controladores (`app/Http/Controllers/Crm/UltronStudio/`)

- `UltronStudioStrategyController.php` — estrategias, borrador, validar, versiones, simulaciones, revisión, cadena, evidencia, archivo y el catálogo. Lleva la lista **`ROUTES`** (las 30, con su llave), `EXPENSIVE_ROUTES` y `STUDIO_ROUTE_COUNT_WITH_STRATEGIES` (77 + 30 = 107).
- `UltronStudioStrategyTemplateController.php` — plantillas.
- `UltronStudioStrategyPersonBindingController.php` — vínculos.

Van en `Crm/UltronStudio/` (no en `Api/Admin/UltronStudio/`, que pedía el encargo) porque ahí viven los 11 controladores del Studio y es el directorio que la lista blanca de `UltronEngineIsolationTest` ya admite: en `Api/Admin/` esa guarda caería (Q-1).

### 1.4 Consola

`app/Console/Commands/UltronStudio/PersonBindingsCommand.php` — `ultron-studio:person-bindings --bootstrap --admin-a= --identity-a= --admin-b= --identity-b= --authorization="…"` (10–160 caracteres, la columna `bootstrap_authorization` de DB9) y `--list` (sin documento ni teléfono).

### 1.5 Rutas y configuración (los dos bloques que me da el encargo)

- `routes/ultron-studio.php`: un bloque nuevo al final del grupo `admin/ultron-studio` (no cambia ninguna línea anterior salvo los tres `use`).
- `config/ultron_studio.php`: el bloque `strategies` (no cambia nada de lo anterior).

## 2. Rutas → llaves → limitadores (la lista para `AuthorizationMap` y el CRM)

Todas bajo `/api/admin/ultron-studio/`, con el tope de cuerpo de 64 KB, `whereUuid` en `{uuid}` y **limitadores con nombre** del Studio (`ultron-studio-read`: 120/min por credencial y 600/min por red; `ultron-studio-write`: 30/min y 120/min; `ultron-studio-evidence`: 10/min por PERSONA y 30/min por red, además del suyo). Cerraduras: la puerta (`AuthorizationMap`, una a una) → una persona (`ultron.view`) → la bandera maestra (409 `strategies_disabled` en TODAS) → la llave en el servicio → la persona verificada y los cuatro ojos → la bandera de publicar.

| # | Método y ruta | Llave (D9-13) | Limitador | Notas |
|---|---|---|---|---|
| 1 | `GET strategies/catalog` | `ultron.view` | read | Catálogo, huella, dominios dinámicos, marcadores, textos de traza, gobierno y `can` |
| 2 | `GET strategies` | `ultron.view` | read | Paginada (≤ 50), sin borrador; estado de versiones y de la cadena |
| 3 | `POST strategies` | `ultron.strategies_edit` | write | Idempotente por `clientRequestId` |
| 4 | `GET strategies/{uuid}` | `ultron.view` | read | Borrador, versiones, cadena y acciones |
| 5 | `PUT strategies/{uuid}/draft` | `ultron.strategies_edit` | write | `baseRevision`; 409 `draft_moved` |
| 6 | `POST strategies/{uuid}/validate` | `ultron.strategies_edit` | **read** (pura, no escribe) | 200 con errores, bloqueantes y avisos |
| 7 | `POST strategies/{uuid}/versions` | `ultron.strategies_edit` | write | Congela; 422 con todas las claves |
| 8 | `POST strategies/{uuid}/simulations` | `ultron.strategies_edit` (`sample`: además `commercial_automation.simulate_real` en el servicio) | write + evidence | **Ruta propuesta por S9**: la simulación del BORRADOR (`version_id` NULL de §6.1) |
| 9 | `GET strategies/versions/{uuid}` | `ultron.view` | read | Contenido, diferencias, validación, simulaciones, reseña y acciones |
| 10 | `POST strategies/versions/{uuid}/simulations` | `ultron.strategies_edit` (ídem `sample`) | write + evidence | La que habilita aprobar |
| 11 | `GET strategies/versions/{uuid}/simulations` | `ultron.view` | read | ≤ 50, sin trazas por persona |
| 12 | `POST strategies/versions/{uuid}/approve` | `ultron.approve` | write | Otra persona verificada; avisos exactos; simulación de la versión |
| 13 | `POST strategies/versions/{uuid}/reject` | `ultron.approve` | write | Con nota |
| 14 | `POST strategies/versions/{uuid}/publish` | `ultron.strategies_publish` | write | Bandera, ventana, `expectedContentHash`, otra persona |
| 15 | `POST strategies/versions/{uuid}/promote` | `ultron.strategies_publish` | write + evidence | `evidenceHash`, otra persona |
| 16 | `GET strategies/versions/{uuid}/canary-evidence` | `ultron.view` | read + evidence | Medida en el servidor |
| 17 | `POST strategies/{uuid}/pause` | `ultron.strategies_rollback` | write | Siempre, con motivo |
| 18 | `POST strategies/{uuid}/resume` | `ultron.strategies_publish` | write | Bandera y ventana |
| 19 | `POST strategies/{uuid}/rollback` | `ultron.strategies_rollback` | write | Siempre, con motivo; `part` opcional |
| 20 | `GET strategies/{uuid}/publications` | `ultron.view` | read | `verified` por entrada |
| 21 | `POST strategies/{uuid}/archive` | `ultron.strategies_edit` | write | Sin versiones vivas |
| 22 | `GET strategies/templates` | `ultron.view` | read | Sin el texto |
| 23 | `POST strategies/templates` | `ultron.strategies_edit` | write | Versión siguiente de una clave |
| 24 | `GET strategies/templates/{uuid}` | `ultron.view` | read | Contenido, vista previa, referencia, restricciones y acciones |
| 25 | `POST strategies/templates/{uuid}/approve` | `ultron.approve` | write | Otra persona verificada |
| 26 | `POST strategies/templates/{uuid}/reject` | `ultron.approve` | write | Con nota |
| 27 | `GET strategies/person-bindings` | `ultron.person_bindings_manage` | read | Sin documento: `identityRef` y nombre del perfil |
| 28 | `POST strategies/person-bindings` | `ultron.person_bindings_manage` | write | Nunca la propia cuenta ni la propia persona |
| 29 | `POST strategies/person-bindings/{uuid}/verify` | `ultron.person_bindings_manage` | write | Otra persona vinculada |
| 30 | `POST strategies/person-bindings/{uuid}/revoke` | `ultron.person_bindings_manage` | write | Una vez, con motivo |

**Para `AuthorizationMap::OVERRIDES` (B9-run o el lead)**, literal, en el formato del mapa:

```php
'GET api/admin/ultron-studio/strategies/catalog' => 'ultron.view',
'GET api/admin/ultron-studio/strategies' => 'ultron.view',
'POST api/admin/ultron-studio/strategies' => 'ultron.strategies_edit',
'GET api/admin/ultron-studio/strategies/{uuid}' => 'ultron.view',
'PUT api/admin/ultron-studio/strategies/{uuid}/draft' => 'ultron.strategies_edit',
'POST api/admin/ultron-studio/strategies/{uuid}/validate' => 'ultron.strategies_edit',
'POST api/admin/ultron-studio/strategies/{uuid}/versions' => 'ultron.strategies_edit',
'POST api/admin/ultron-studio/strategies/{uuid}/simulations' => 'ultron.strategies_edit',
'GET api/admin/ultron-studio/strategies/versions/{uuid}' => 'ultron.view',
'POST api/admin/ultron-studio/strategies/versions/{uuid}/simulations' => 'ultron.strategies_edit',
'GET api/admin/ultron-studio/strategies/versions/{uuid}/simulations' => 'ultron.view',
'POST api/admin/ultron-studio/strategies/versions/{uuid}/approve' => 'ultron.approve',
'POST api/admin/ultron-studio/strategies/versions/{uuid}/reject' => 'ultron.approve',
'POST api/admin/ultron-studio/strategies/versions/{uuid}/publish' => 'ultron.strategies_publish',
'POST api/admin/ultron-studio/strategies/versions/{uuid}/promote' => 'ultron.strategies_publish',
'GET api/admin/ultron-studio/strategies/versions/{uuid}/canary-evidence' => 'ultron.view',
'POST api/admin/ultron-studio/strategies/{uuid}/pause' => 'ultron.strategies_rollback',
'POST api/admin/ultron-studio/strategies/{uuid}/resume' => 'ultron.strategies_publish',
'POST api/admin/ultron-studio/strategies/{uuid}/rollback' => 'ultron.strategies_rollback',
'GET api/admin/ultron-studio/strategies/{uuid}/publications' => 'ultron.view',
'POST api/admin/ultron-studio/strategies/{uuid}/archive' => 'ultron.strategies_edit',
'GET api/admin/ultron-studio/strategies/templates' => 'ultron.view',
'POST api/admin/ultron-studio/strategies/templates' => 'ultron.strategies_edit',
'GET api/admin/ultron-studio/strategies/templates/{uuid}' => 'ultron.view',
'POST api/admin/ultron-studio/strategies/templates/{uuid}/approve' => 'ultron.approve',
'POST api/admin/ultron-studio/strategies/templates/{uuid}/reject' => 'ultron.approve',
'GET api/admin/ultron-studio/strategies/person-bindings' => 'ultron.person_bindings_manage',
'POST api/admin/ultron-studio/strategies/person-bindings' => 'ultron.person_bindings_manage',
'POST api/admin/ultron-studio/strategies/person-bindings/{uuid}/verify' => 'ultron.person_bindings_manage',
'POST api/admin/ultron-studio/strategies/person-bindings/{uuid}/revoke' => 'ultron.person_bindings_manage',
```

Con eso, `auth:coverage --unmapped` queda vacío (hoy lista exactamente estas 30) y entran en el catálogo las llaves nuevas de S9: `ultron.strategies_edit`, `ultron.strategies_publish`, `ultron.strategies_rollback` y `ultron.person_bindings_manage`. `commercial_automation.simulate_real` no protege ninguna ruta de S9 (la comprueba el servicio en `sample`): tiene que existir en el catálogo por las rutas de B9-run o por el reparto de D9-13. **Reparto por defecto (P9-21):** Super Admin todas; Administrador `strategies_edit`, `strategies_rollback`, `simulate_real` (y `ultron.view`/`ultron.approve` como hoy); `strategies_publish` y `person_bindings_manage`, **solo Super Admin**.

**Variables para el ejemplo de entorno** (todas apagadas; solo `true`/`1`/`on`/`yes` encienden):

| Variable | Defecto | Qué hace |
|---|---|---|
| `ULTRON_STUDIO_STRATEGIES_ENABLED` | `false` | Bandera maestra del gobierno: sin ella, las 30 rutas responden 409 `strategies_disabled` |
| `ULTRON_STUDIO_STRATEGIES_PUBLISHING_ENABLED` | `false` | Publicar, promover y reanudar (409 `strategies_publishing_disabled`); pausar y revertir no dependen de ella |
| `ULTRON_STUDIO_STRATEGIES_PUBLISH_WINDOW` | `07:00-19:00` | Ventana de Bogotá de publicar, promover y reanudar; vacía = sin ventana; mal escrita = la del contrato |
| `ULTRON_STUDIO_STRATEGIES_CANARY_MIN_HOURS` | `24` | Horas mínimas en canario (1–168, recortado) |
| `ULTRON_STUDIO_STRATEGIES_CANARY_MIN_TOUCHES` | `3` | Contactos mínimos que salieron (1–100, recortado) |
| `ULTRON_STUDIO_STRATEGIES_SIMULATION_GATE` | `required` | `waived` solo lo pone el propietario y queda en la columna `simulation_gate` de la entrada |

## 3. El esquema: alineado con la migración REAL de DB9

Empecé antes de que existiera la migración (solo estaba el informe parcial de DB9) con las columnas de §6.1; cuando apareció `2026_11_12_100001_create_ultron_studio_strategy_tables.php` reconcilié modelos y servicios con ella, **sin inventar columnas** (ninguna petición a DB9 pendiente). Lo que su esquema decide y el código respeta:

- **Vínculos:** `origin` (`crm` | `bootstrap`); el arranque nace verificado SIN cuenta que lo cree ni lo verifique (`created_by_admin_id`, `verified_by_*` NULL) y con `bootstrap_authorization` (≤ 160: el comando lo limita a 160); `verified_by_name`, `revoked_by_name`; `client_request_id` obligatorio (el arranque genera uno).
- **Estrategias:** nacen con `draft_revision = 1` y sin `draft_updated_*` (`usst_starts_clean`); el borrador es un OBJETO JSON (una lista es 422; el vacío, `{}`); cada escritura sube la revisión en 1 (`usst_draft_revision_next`), que es justo el UPDATE condicionado de `baseRevision`.
- **Plantillas:** la revisión va en `reviewed_by_*` (no hay `approved_by_*`); `category` solo en WhatsApp y la del contenido; número siguiente por clave (`usmt_number_next`).
- **Versiones:** `approved_by_name/role` y `approved_at`; la simulación que cita la aprobación es **de esa versión** (`ussv_simulation_gate`: `version_id` = la versión), así que la del borrador no habilita aprobar.
- **Reseñas:** `uuid` y `content_hash` (la huella exacta que se revisó).
- **Cadena:** `previous_publication_id` (cabeza anterior), `simulation_gate` en columna (solo `publish`/`promote`), `evidence` solo en `promote`, `acted_by_person_id`.

**Un fallo real que solo vio PostgreSQL** (la puerta PG, §7): `max('number')` con `FOR UPDATE` al crear la versión siguiente de una plantilla (`FOR UPDATE is not allowed with aggregate functions`; SQLite lo admite). Arreglado con un cerrojo consultivo por clave (`StudioIntegrations::lock`, READ COMMITTED) antes del `max`; la base sigue garantizando el número y el UNIQUE.

## 4. Pruebas (`tests/Feature/UltronStudio/Strategies/`)

| Fichero | Pruebas | Qué cubre (§13) |
|---|---|---|
| `StrategyFixtures.php` | — | Trait: llaves por rol (política persistida), banderas, personas y arranque, plantillas, contenido válido, personas sintéticas. Desactiva SOLO la primera cerradura (`EnforceAdminAuthorization`) hasta que B9-run clasifique las rutas |
| `UltronStudioStrategyLifecycleTest.php` | 1 | **#26 (parte de la API):** crear → plantilla a cuatro ojos → borrador (409 `draft_moved`) → validar → congelar → simular → aprobar → publicar (reintento idempotente) → pausar → reanudar → revertir → archivar; la cadena (4 entradas) verifica entera; el libro lo cuenta todo |
| `UltronStudioStrategyRoutesTest.php` | 5 | Las 30 rutas con tope de 64 KB y su limitador; el total (107) es la constante; 401 sin sesión y 403 con el token compartido en las 30; **409 `strategies_disabled` en las 30 con la bandera apagada, sin escribir nada**; `ultron.view` primero; **la de traspaso a `AuthorizationMap` (ROJA A PROPÓSITO)** |
| `UltronStudioStrategyValidationTest.php` | 10 | **T11** (422 con todas las claves: hecho fuera del catálogo, operador ajeno, texto libre/SQL, «regex», rango; profundidad y nodos; lo que no valida no se congela ni se simula); finalidad inmutable; plantillas sin aprobar, de otra huella o de otra finalidad; **T18** (`studio_reference_invalid` con línea base o versión que no cuadran; `payment_links_disabled`); **P9-10** (con una versión del Studio aprobada la línea base ya no vale y esa versión sí); plantillas: contenido cerrado, línea de baja, categoría por finalidad, marcadores, R-01/02/03/06, datos de contacto; versionado por clave; cuatro ojos; vista previa |
| `UltronStudioStrategyFourEyesTest.php` | 4 | **T1:** la autora y **su segunda cuenta (misma persona)** no aprueban, publican ni promueven; una cuenta sin vínculo (o con vínculo pendiente) no aprueba ni congela; la ventana de publicar y reanudar (pausar y revertir no dependen de ella ni de la bandera); la cadena verifica, un UPDATE crudo lo rechaza la base y una entrada manipulada (sin la guarda) da `signature_mismatch` en su `seq` y 409 `chain_unreadable`; la firma no vale en el dominio de la voz |
| `UltronStudioStrategyPersonBindingsTest.php` | 3 | Arranque solo por consola (con autorización; una sola vez; evento `system` en el libro, sin documento); crear para otra cuenta (nunca la propia ni la propia persona), idempotencia, uno vivo por cuenta, verificar por otra persona (ni quien lo creó ni la vinculada), revocar una vez con motivo, aviso por volver a vincular enseguida, la lista sin documento y solo con su llave |
| `UltronStudioStrategySimulationTest.php` | 5 | **T12:** sintética reproducible (misma entrada → misma `inputHash`, `resultHash`, `decisionsHash` y `traceHash` por persona; la huella es la de la traza devuelta; otro instante —un domingo— otra decisión: `defer` por `calendar_sunday`); hechos sintéticos tipados contra el catálogo (422 con todas las claves); **muestra REAL** por `BatchSnapshotBuilder` reproducible, por `subjectRef` (64 hex) y sin nombres, documentos ni correos, en solo lectura (0 inscripciones, 0 trabajos); la muestra exige `simulate_real`; aprobar exige una simulación SIN bloqueantes DE LA VERSIÓN (la del borrador no basta; `audience_empty` bloquea) |
| `UltronStudioStrategyRollbackTest.php` | 3 | **T2** (la evidencia real sin envíos no pasa y lo dice; con evidencia, la huella vista tiene que ser la de ahora: `evidence_changed`; promover deja la entrada con `simulation_gate` y la evidencia); **#25** (revertir la publicada: ROLLED_BACK y la anterior RETIRED vuelve a PUBLISHED con entrada NUEVA; la cadena solo crece —lo anterior, byte a byte, igual— y verifica entera; sin anterior, `off`; una revertida no se vuelve a publicar); **#23, parte del gobierno** (pausar exige motivo, deja `paused` en la cadena sin tocar las versiones; en pausa no se archiva; revertir y archivar) |

**Lo que NO cubren mis pruebas (y de quién es):** la cancelación de los trabajos pendientes en la pasada siguiente (#23, #25: B9-run, que lee la cadena con `OutreachPublishedStrategies`); el alcance canario en vivo (T2: B9-run); la evidencia que PASA con filas reales de `outreach_*` (exige envíos: W9/asistido; aquí se inyecta con la costura de pruebas, como la firma); el E2E con el CRM (#26 completo: TEST9-b).

## 5. El contrato de cada respuesta (para FE9)

Todas: `{ok: true, data, meta?}`; los errores, `{ok: false, code, message, …}` (`UltronStudioException`) o el 422 de Laravel/del dominio con `errors: {clave: [mensajes]}` y, en los del dominio, `findings: [{code, key, level}]`. Ninguna lleva teléfonos, documentos, correos, nombres de socios ni textos de mensajes a nadie; las personas reales, por `subjectRef`.

**Errores estables** (`code`): `strategies_disabled` (409), `strategies_publishing_disabled` (409), `person_required` (403), `forbidden` (403, `required_permission`), `person_binding_required` (403, `action`), `four_eyes_required` (403), `own_binding_forbidden` (403), `strategy_not_found`/`version_not_found`/`template_not_found`/`binding_not_found`/`identity_not_found`/`admin_not_found` (404), `strategy_invalid` (422, `errors`+`findings`), `template_invalid` (422), `simulation_input_invalid` (422), `draft_invalid`/`draft_too_large` (422), `draft_moved` (409, `current: {revision, content, updatedAt}`), `strategy_key_taken`, `strategy_archived`, `strategy_live` (`live`), `version_unchanged` (`version`), `version_moved`, `template_key_mismatch` (`channel`, `purpose`), `template_moved`, `invalid_transition` (`currentStatus`, `allowedFrom`), `warnings_not_acknowledged` (`warnings`), `revalidation_blocked` (`findings`), `catalog_incompatible`, `integrity_mismatch`, `simulation_required` (`contentHash`), `outside_publication_window`, `content_changed` (`expected`, `actual`), `studio_reference_invalid` (`reason`), `strategy_paused`, `canary_in_progress`, `not_active_canary`, `canary_evidence_insufficient` (`evidence`), `evidence_changed` (`evidence`), `chain_unreadable` (`verified`), `approver_unknown`, `strategy_not_active`/`strategy_not_paused` (`status`), `nothing_to_rollback` (`part`), `publication_moved`, `client_request_reused`, `binding_exists`, `binding_revoked`, `binding_already_verified`, `binding_moved`, `bootstrap_invalid`, `bootstrap_not_allowed` (409, todos 409 salvo los marcados).

**DTOs:**

- `StrategySummaryDto` (`GET strategies`, dentro de `data.data[]`): `{uuid, key, name, purpose, draftRevision, draftUpdatedAt, archived, archivedAt, createdBy{name, role}, createdAt, versions{ESTADO: n}, chain{status: active|paused|off, canary: {contentHash, version}|null, rest: …|null, seq, verified: ok|<motivo>|null}}`; `meta: {governance, can, person{bound}}`.
- `StrategyDetailDto` (`GET strategies/{uuid}`, crear, archivar): el resumen + `draft{revision, content, updatedAt}`, `versions[]` (`StrategyVersionSummaryDto`), `chain`, `can{edit, approve, publish, rollback, simulateReal, personBindings}`, `actions{pause, resume, rollback, archive: {allowed}}`.
- `StrategyVersionSummaryDto`: `{uuid, number, status, statusLabel, contentHash, shortHash, studioRefKind, createdBy, createdAt, statusChangedAt}`.
- `StrategyVersionDetailDto` (`GET strategies/versions/{uuid}`, congelar, aprobar, rechazar): el resumen + `strategy` (resumen), `content` (el canónico), `integrity{ok, reason}`, `catalogVersion`, `evaluatorVersion`, `studioRef{kind, versionUuid, contentHash}`, `validation` (`ValidationDto`), `diff{against{uuid, number}, changes[{path, change: added|removed|changed}]}|null`, `simulations[]` (`SimulationSummaryDto`), `review{decision, acknowledgedWarnings, note, reviewer, at}|null`, `actions{approve, reject, publish, promote: {allowed, code?}}`.
- `ValidationDto` (`POST strategies/{uuid}/validate` y `validation` de la versión): `{format: ultron-strategy-validation/1, valid, contentHash, catalogVersion, errors[{key, code, message}], blocking[{id, code, key, level, message}], warnings[…], warningIds[]}` (+ `canonical` en `validate`). **Aprobar exige `acknowledgedWarnings` = `warningIds` exactos.**
- `SimulationSummaryDto` (listas): `{uuid, kind, scope: draft|version, contentHash, inputHash, resultHash, sampleSize, blockingFindings, passes, at, counts{evaluated, enrolled, byGate, byKind, byLevel, byReason, byChannel, warnings}, findings{blocking[], warnings[]}, decisionsHash, source, createdBy, createdAt}`.
- `SimulationDto` (`POST …/simulations`, 201): el resumen + `people[≤ 50]{ref (la del CRM o el subjectRef de 64 hex), gate, decision{kind: exclude|plan|defer|deny, level, reasons[], retryAt, channel}, traceHash, factsHash, text[] (frases en español), trace (outreach-trace/1)}` + `sequence[{id, delay, channels, hasGuard, on, waitFor}]`. Entrada sintética: `{kind: synthetic, at?: "AAAA-MM-DDTHH:MM:SSZ", people: [{ref ^[a-z0-9][a-z0-9_-]{0,15}$, facts{clave del catálogo: valor tipado|null}, consent?{whatsapp|voice: granted|missing|denied}, optOut?{commercial, doNotContact, siblingDoNotContact}, voice?{excluded, rneOk}, canary?{whatsapp|voice: bool}, contacts?[{hoursAgo, channel, purpose, strategy: self|other|null, outcome}], events?[{key, hoursAgo}]|null, transitions?[{entity, from, to, hoursAgo}]|null}]}`; muestra: `{kind: sample, size?: 1–200}`.
- `StrategyPublicationEntryDto` (las 5 acciones, 201; reintento 200 `replayed: true`; historial): `{uuid, seq, action, state{canary, rest, status}, version{uuid, number, contentHash}|null, effectiveHash, shortHash, simulationGate, evidence, reason, actor{name, role}, at, verified}`.
- `CanaryEvidenceDto` (`GET …/canary-evidence`): `{format, version, publishSeq, hoursOk, hours, touches, n1After, ambiguousOpen, optOuts, optOutPermille, thresholds{minHours, minTouches, maxOptOutPermille}, passes, failing[min_hours|min_touches|n1_after|ambiguous_open|opt_out_rate|not_in_canary|execution_unreadable], evidenceHash, measuredAt}`. Promover lleva esa `evidenceHash`.
- `MessageTemplateSummaryDto` / `MessageTemplateDto`: `{uuid, key, number, channel, purpose, status, contentHash, reference{uuid, hash}, createdBy, createdAt, statusChangedAt}` + en el detalle `content`, `preview` (con `[marcador]`), `restrictions[R-0x]`, `usesPaymentLink`, `integrity`, `review{note, reviewer, at}|null`, `actions{approve, reject: {allowed, code?}}`.
- `PersonBindingDto`: `{uuid, status: pending|verified|revoked, origin: crm|bootstrap, account{id, name, role}, identityRef (16 hex), profileName, createdBy|null, verifiedBy|null, verifiedAt, revokedAt, revokeReason, createdAt}`; la lista lleva `meta.person{bound}`.
- `StrategyCatalogDto` (`GET strategies/catalog`): `{catalog (OutreachCatalog::export), catalogHash, dynamicDomains{plans, class_types}, contentFormat, limits, limitsOptional, templates{channels, languages, categoryByPurpose, markers, markersForbiddenByPurpose, bodyMax, scriptMax}, traceText (DecisionTraceText::export), studioBaseline{catalogVersion, catalogHash}, governance, can, person{bound}}`. `studioBaseline` es la referencia `{kind: baseline, …}` que el CRM pone en `content.studio` mientras no haya versión del Studio aprobada (P9-10).

## 6. Para los demás paquetes

### 6.1 B9-run (la ejecución y la PARADA GLOBAL)

- **El puente de la cadena** (`OutreachPublishedStrategies`) lee `ultron_studio_strategy_publications`: la última entrada de cada estrategia (`max(seq)` por `strategy_id`), su mensaje (`StrategyChainSignature::decode`) y su firma (`verifyEntry` con la entrada anterior GLOBAL: `seq − 1`). Mi `StudioStrategyPublications::statesFor()` hace eso por lotes en 4 consultas (si quieres, cópiale la forma; no lo importes: el Studio no se nombra desde `Outreach`). Además de la firma, compruebo que la fila dice lo mismo que su mensaje (`row_mismatch`): la estrategia, la acción, `seq`, `prev_hash` y la persona.
- **La PARADA GLOBAL a cuatro ojos (L9-7):** `outreach_brakes` ya tiene `opened_by_person_id` y `released_by_person_id`. La pieza de personas es mía y pública: `StudioPersonBindings::personOf(int $adminId): ?array{bindingId, bindingUuid, identityId, identityUuid}` (vínculo VERIFICADO y vivo; una consulta) y `StudioPersonBindings::samePerson(adminA, identityA, adminB, identityB): bool` (misma cuenta O misma identidad). Soltar: `personOf(quien suelta) !== null && ! samePerson(quien suelta, …, quien abrió, opened_by_person_id)`. No hay acción del Studio que pause todas las estrategias en la cadena: la PARADA GLOBAL es el freno de B9-run (E8/E9), y pausar una estrategia es la entrada `pause` de la cadena.
- **#23 y #25:** con una entrada `pause` o `rollback` (o una versión que deja de estar en el estado de la cadena), la etapa 6 cancela los trabajos pendientes (`strategy_moved`); la restaurada vuelve con otra `seq` (`publication_seq` nueva en el sello: cursor nuevo). Mis pruebas cubren la cadena; la cancelación es tuya.
- **La evidencia del canario** la mido yo en SOLO LECTURA sobre tus tablas (`StudioStrategyEvidence`): `outreach_jobs` (`strategy_version_id`, `part = canary`, `mode = live`, `status = started`, `channel <> simulated`, `status = ambiguous`, `created_at`), `outreach_decisions` (`kind = deny`, `level = N1`, `evaluated_at`) y `outreach_outcomes` ⨝ `outreach_enrollments` (`outcome = opted_out`, `occurred_at`). Si cambias esos nombres, dímelo.
- **El prefiltro del disparador** de la simulación sobre muestra real (`StudioStrategySimulations::candidates()`) usa los prefiltros de B9-facts con mi lectura: `membership.expiring_window` = vence en [hoy, hoy + `daysBefore`]; los `crm_fact` no tienen prefiltro (la muestra sale vacía y lo dice). Tu barrido debería ser la única autoridad: propongo una `Outreach\TriggerPrefilters` tuya que usemos los dos (Q-9).

### 6.2 FE9

§5 es el contrato. Las rutas van TODAS bajo `strategies/**` (§2), no con los prefijos de §7 del contrato (`strategy-versions/`, `templates/`, `person-bindings/`). La simulación sintética necesita `receivable.has_overdue` (P9-7: con la cartera desconocida, N6 bloquea lo comercial) y `canary.whatsapp` (la línea base declara «solo canario»): conviene que el formulario los enseñe como elección visible.

### 6.3 El lead (en la rama del H9; ningún fichero del H5–H8 tocado desde S9)

Con 30 rutas más, las guardas que atan el total del Studio a las rutas reales caen hasta que se adapten sumando mi constante (nunca cambiando el 77 del H8). La lista exacta, de la regresión de §7, va en §7.3. El patrón: donde una prueba compara con `UltronStudioVoiceController::STUDIO_ROUTE_COUNT` (77) las rutas que empiezan por `api/admin/ultron-studio`, compararlas con `UltronStudioStrategyController::STUDIO_ROUTE_COUNT_WITH_STRATEGIES` (107) o filtrar fuera `api/admin/ultron-studio/strategies`; el recorrido de `UltronStudioEngineReadOnlyTest` tiene que pasar también por las 30 (con la bandera apagada responden 409 sin escribir: lo prueba `UltronStudioStrategyRoutesTest`). `TelephonyRoutesTest` cae por `auth:coverage --unmapped` (mis 30): se arregla al añadir el bloque de §2 a `AuthorizationMap`.

### 6.4 SEC9

- Lo que S9 nombra de `Outreach`: `Core` (validación, simulación, firma: lo que D9-2 permite) y, de `Facts`, `CatalogDomains` (la foto de dominios que pide L9-13), `BatchSnapshotBuilder`, `CoreProjection`, `FactSelection` y los prefiltros (`MembershipFacts`, `AttendanceFacts`, `PaymentFacts`, `ReceivableFacts`, `LeadFacts`, `ClassFacts`, `ValuationFacts`), todos de solo lectura, en `StudioStrategySimulations`, `StudioStrategyValidator`, `StudioStrategyVersions` y `StudioStrategyPublishing`. D9-2 dice «solo `Core` y los servicios enumerados de métricas»; el encargo pedía la foto de `BatchSnapshotBuilder`: la lista blanca de `StudioStrategyIsolationTest` tiene que admitirlos (Q-8).
- `StudioStrategyEvidence` lee `outreach_*` en solo lectura (los «servicios enumerados de lectura de métricas para la evidencia» de D9-2). Nunca escribe en `outreach_*`: lo puede vigilar la escucha de consultas.
- Cero red: ninguna clase de S9 nombra `Http::`, proveedores ni n8n; todas las pruebas con `Http::preventStrayRequests()`.

## 7. Qué verifiqué, con comandos y resultados

Todo desde `backend/`, con `php -d memory_limit=2G` y `--no-logging`. La máquina tenía carga ~30 (otros agentes con navegadores de E2E): una pasada de un fichero llegó a tardar 2,5 min por espera de CPU, no por el código.

### 7.1 Lo de S9

| Comando | Resultado |
|---|---|
| `vendor/bin/phpunit --no-logging tests/Feature/UltronStudio/Strategies` (SQLite en memoria) | `Tests: 32, Assertions: 597, Failures: 1` — la única roja, `test_authorization_map_clasifica_cada_ruta_con_su_llave` (a propósito, §4) |
| `UNIT_DB=ultron_studio_h9_s9 docs/ultron-studio/ops/pgunit.sh --no-logging tests/Feature/UltronStudio/Strategies` (PostgreSQL 14 desechable, base creada para S9) | `Tests: 32, Assertions: 591, Failures: 1` — la misma. La primera pasada en PG dio 20 errores con UNA causa: `max()` con `FOR UPDATE` (§3), arreglada |
| `vendor/bin/pint --test` sobre los ficheros de S9, `config/ultron_studio.php` y `routes/ultron-studio.php` | `passed` |
| `php artisan auth:coverage --unmapped` | Exactamente mis 30 rutas (las de §2) |
| `php artisan route:list --path=ultron-studio` | 107 rutas (77 + 30) |
| `git diff --stat -- app/Models/UltronStudio/StudioAuditEvent.php` | vacío (mi cambio, deshecho) |
| `git diff -- app/Http/Controllers/Crm/UltronStudio/UltronStudioVoiceController.php` | solo los cambios del H8 (`addressForm`); `STUDIO_ROUTE_COUNT` = 77 |

### 7.2 Mutaciones (clon APFS del backend, `mutate_s9.py`; el árbol compartido no se toca)

Por mutante: el texto original aparece una vez; se aplica; `php -l`; se corre la suite de S9; se restaura y se compara el sha256. Cazado = cae alguna prueba distinta de la de traspaso.

| Mutante | Qué rompe | Resultado |
|---|---|---|
| `persons-same-or` | misma persona = misma cuenta Y misma identidad | cazado (2) |
| `versions-no-sim-gate` | aprobar sin simulación | cazado (2) |
| `versions-no-ack-check` | aprobar sin reconocer los avisos exactos | cazado |
| `publishing-no-four-eyes` | publicar lo propio | cazado (2) |
| `config-enabled-inverted` | la bandera maestra no frena | cazado |
| `validator-no-approved-check` | plantilla sin aprobar en un paso | cazado |
| `templates-no-discount-guard` | sin la guarda R-02 | cazado |
| `publishing-no-restore` | revertir la publicada sin devolver la anterior | cazado |
| `strategies-no-base-revision` | guardar el borrador sin `baseRevision` | cazado |
| `bindings-creator-can-verify` | quien crea un vínculo lo verifica | cazado |
| `bindings-allow-own-account` | vincular la propia cuenta | cazado |
| `publishing-no-seen-hash` | publicar otra huella de la que se vio | sobrevivió → **prueba añadida** (`content_changed`) → cazado |
| `simulator-human-requested-false` | N6 nunca ve `human_requested` | sobrevivió en el clon anterior a la prueba N5/N6 → cazado con ella |
| `chain-row-no-action-match` | una fila con otra acción verifica | sobrevivió → **comprobación añadida** (`row_mismatch`) → cazado |

**14/14 cazados.**

### 7.3 Regresión (una pasada, 4 709 pruebas, 89 084 aserciones, 388 omitidas por el motor)

`vendor/bin/phpunit --no-logging tests/Feature/UltronStudio tests/Unit/UltronStudio tests/Feature/Telephony/TelephonyRoutesTest.php tests/Feature/Outreach tests/Unit/Outreach` → 23 fallos y 1 error:

**Por mis 30 rutas (10; se arreglan con el bloque de `AuthorizationMap` de §2 y adaptando en la rama del H9 los recuentos SUMANDO `UltronStudioStrategyController::STUDIO_ROUTE_COUNT_WITH_STRATEGIES`, nunca cambiando el 77):**
`TelephonyRoutesTest::test_cada_ruta_lleva_su_tope…` (su `auth:coverage --unmapped`), `UltronStudioStrategyRoutesTest::test_authorization_map…` (la mía, a propósito), `UltronEngineIsolationTest::test_ninguna_ruta_del_studio_cuelga_de_internal…`, `UltronStudioEarlyBodyCapTest::test_el_tope_global…`, `UltronStudioEngineReadOnlyTest::test_recorrer_todas_las_rutas…` (el recorrido tiene que incluir las 30), `UltronStudioIntegrationsParityHttpTest::test_route_matrix` («legacy · las 38»: 86 en vez de 56), `UltronStudioIntegrationsPermissionsTest::test_el_mapa_resuelve_exactamente_las_56…`, `UltronStudioLabNoEngineEffectsTest::test_el_contrato_de_las_21_rutas…`, `UltronStudioPermissionsTest::test_el_mapa_resuelve_exactamente_las_38…` y `UltronStudioVoiceRoutesTest::test_las_21_rutas…`.

**No son de S9 (13):** los recuentos de esquema que cambia la migración de DB9 (`UltronStudioHardeningH7Test` ×2, `UltronStudioIntegrationsSchemaTest` ×4, `UltronStudioPublicationSchemaTest` ×2, `UltronStudioVoiceSchemaTest` ×1: el «Anexo» de DB9); `UltronEngineIsolationTest::test_fuera_del_studio_nadie_nombra…` (los adaptadores de B9-facts `CanaryFacts`, `ContactHistoryFacts` y `SubjectTimeline` nombran tablas `ultron_studio_*`: lista blanca de SEC9); y tres de B9-facts que chocan con las tablas nuevas de DB9 (`SubjectTimelineTest`: inserta en `ultron_studio_strategies` solo el `uuid` y DB9 exige `client_request_id`; `AgendaEngineHistoryFactsTest` línea 213 y `SubjectAndSnapshotTest`: 43 consultas en vez de ≤ 40).

**Dependiente del orden (1):** `UltronStudioVoiceLabSessionsTest::test_la_lista_filtra_y_pagina_sin_consultas_por_fila` (8 consultas en vez de 7 en la pasada completa; **aislada pasa**). No ejecuta código de S9.

### 7.4 Lo que NO verifiqué

- El E2E con el CRM (#26 completo) y la cancelación de trabajos de #23/#25 en la ejecución: son de TEST9-b y B9-run.
- La evidencia del canario que pasa con filas reales de `outreach_*` (exige envíos).
- Carreras reales en PG de publicar/promover a la vez (el código toma el cerrojo de la cadena, comprueba la cabeza y traduce el UNIQUE a `publication_moved`, como el H8; sin prueba de carrera propia).
- Las suites completas fuera de las de §7.3 (Marketing, Commercial): S9 no toca nada de ellas.

## 8. Q-n para el lead

En cada caso elegí la opción más estricta compatible con el contrato.

| Q | Qué | Qué hice |
|---|---|---|
| Q-1 | Los controladores: el encargo decía `Api/Admin/UltronStudio/`; §11 y los 11 del Studio, `Crm/UltronStudio/` | `Crm/UltronStudio/UltronStudioStrateg*Controller.php`. En `Api/Admin/` los nombres del Studio caerían en `UltronEngineIsolationTest` (su lista blanca admite `Crm/UltronStudio/`) |
| Q-2 | Las rutas: §7 tiene 29 con prefijos `strategy-versions/`, `templates/`, `person-bindings/`; el encargo pedía todo bajo `strategies/**` y que yo fijara la lista | 30 bajo `strategies/**`: las 29 de §7 + **`POST strategies/{uuid}/simulations`** (la simulación del borrador, `version_id` NULL de §6.1, «iterativa» de D9-11.0). Total del Studio 107 |
| Q-3 | `StudioAuditEvent::SUBJECT_TYPES` (H6) sigue con 7 sujetos: el libro ESCRIBE bien los 4 nuevos (DB9 amplió el CHECK), pero el filtro `GET audit?subjectType=strategy…` los rechaza (422) | No lo toco (regla 4). Pido añadir `strategy`, `strategy_version`, `message_template` y `person_binding` (están en `StudioStrategyConfig::SUBJECT_TYPES`) |
| Q-4 | `actedByPerson` nunca es null en el mensaje firmado (núcleo, L9-23), pero DB9 deja `acted_by_person_id` NULL en `pause`/`rollback` («el sentido seguro») | Pausar y revertir también exigen una persona VERIFICADA (sin ella no hay mensaje firmable). Consecuencia: una cuenta sin vínculo no pausa una estrategia; sí puede echar la PARADA GLOBAL (freno de B9-run). Si se quiere pausar sin vínculo, hace falta que el núcleo admita un actor de sistema |
| Q-5 | ¿Quién pone el `ULTRON_STUDIO_STRATEGIES_*` en el ejemplo de entorno y las 30 en `AuthorizationMap`? | Las paso en §2 (literal). No toco ni `AuthorizationMap` ni el ejemplo de entorno |
| Q-6 | Limitadores: el lead pide limitadores con nombre | Ya los llevan: `ultron-studio-read` / `-write` / `-evidence` (definidos en `AppServiceProvider`, con clave por credencial o PERSONA y techo por red). Lo que hace caer `TelephonyRoutesTest` es `auth:coverage --unmapped`, no el limitador. Si se quiere un cubo propio de las estrategias (`ultron-studio-strategies-*`), es `AppServiceProvider` (no mío): paso la definición |
| Q-7 | La evidencia que PASA solo existe con envíos (W9 o asistido) | Promover está cableado y probado con evidencia inyectada (la costura de pruebas, como la firma); con las tablas reales sin envíos NO pasa (falla cerrado). La evidencia real la prueba el E2E de TEST9 cuando haya asistido |
| Q-8 | D9-2 limita el Studio a `Outreach\Core`; la simulación sobre muestra real necesita la foto de `Outreach\Facts` | Uso `Facts` en solo lectura (lista en §6.4). SEC9 tiene que admitirlo |
| Q-9 | La correspondencia disparador → prefiltro está hoy en S9 (simulación) y estará en B9-run (barrido) | Propongo una sola, de B9-run, usada por los dos; mientras, la mía usa [hoy, hoy + `daysBefore`] en la ventana de vencimiento |
| Q-10 | `payment_links_disabled` (N3, T18) necesita saber si una plantilla es «de enlace de pago», y D9-6 no tiene marcador para eso | Marcador cerrado nuevo `payment_link` (lo resolverá W9). Una plantilla que lo usa, con `rules.paymentLinksEnabled = false` en la referencia, bloquea |
| Q-11 | La plantilla de voz (`purposeKey`) espera la lista cerrada de la Enmienda 16 del H8 | Mientras: un objetivo de `OutreachCatalog::GOALS`. Las estrategias de voz se simulan (aviso `channel_not_live`) |
| Q-12 | Las restricciones R-01/02/03/06 de las plantillas | Patrones propios (importes, «gratis/2x1/precio especial/descuento/%», URL o tarjeta, «garantizado/te prometo…») en `StudioMessageTemplates::restrictionViolations()`; las guardas reales del motor se aplican al despachar (W9). ¿Las espeja el CRM? |
| Q-13 | Una cuenta solo aprueba si tiene vínculo; ¿y la autora? | Congelar una versión y crear una plantilla también exigen la persona verificada de la autora (si no, no se pueden comparar personas al aprobar: dos cuentas de la misma persona se colarían). DB9 lo exige igual (`ussv_author_is_bound`, `usmt_author_is_bound`) |
| Q-14 | Publicar con la estrategia en pausa o con otra versión en canario | 409 `strategy_paused` / `canary_in_progress` (el grafo del H5 no tiene CANARY → RETIRED) |
| Q-15 | `retire` | No se usa: archivar exige que no quede nada vivo, así que no necesita entrada |
| Q-16 | «`human_requested` reciente» (N6): el contrato no fija N | 30 días en la simulación (`StudioStrategySimulator::HUMAN_REQUESTED_DAYS`); B9-run debería usar el mismo |

## 9. Lista exacta de ficheros de S9

**Nuevos (22):**

- `app/Services/UltronStudio/StudioStrategyConfig.php`
- `app/Services/UltronStudio/StudioStrategyValidator.php`
- `app/Services/UltronStudio/StudioMessageTemplates.php`
- `app/Services/UltronStudio/StudioPersonBindings.php`
- `app/Services/UltronStudio/StudioStrategies.php`
- `app/Services/UltronStudio/StudioStrategyVersions.php`
- `app/Services/UltronStudio/StudioStrategySimulator.php`
- `app/Services/UltronStudio/StudioStrategySimulations.php`
- `app/Services/UltronStudio/StudioStrategyPublications.php`
- `app/Services/UltronStudio/StudioStrategyPublishing.php`
- `app/Services/UltronStudio/StudioStrategyEvidence.php`
- `app/Models/UltronStudio/StudioStrategy.php`, `StudioStrategyVersion.php`, `StudioStrategyReview.php`, `StudioStrategySimulation.php`, `StudioStrategyPublication.php`, `StudioMessageTemplate.php`, `StudioPersonBinding.php`
- `app/Http/Controllers/Crm/UltronStudio/UltronStudioStrategyController.php`, `UltronStudioStrategyTemplateController.php`, `UltronStudioStrategyPersonBindingController.php`
- `app/Console/Commands/UltronStudio/PersonBindingsCommand.php`
- `tests/Feature/UltronStudio/Strategies/`: `StrategyFixtures.php`, `UltronStudioStrategyLifecycleTest.php`, `UltronStudioStrategyRoutesTest.php`, `UltronStudioStrategyValidationTest.php`, `UltronStudioStrategyFourEyesTest.php`, `UltronStudioStrategyPersonBindingsTest.php`, `UltronStudioStrategySimulationTest.php`, `UltronStudioStrategyRollbackTest.php`
- `docs/ultron-studio/plans/informes-h9/informe-studio.md` (este)

**Existentes, solo los bloques que me da el encargo:**

- `routes/ultron-studio.php`: tres `use` y el bloque del Hito 9 al final del grupo (`git diff` lo enseña aislado).
- `config/ultron_studio.php`: el bloque `strategies`.

**Existentes que NO toco (regla 4):** `UltronStudioVoiceController.php` (el lead revirtió mi cambio de `STUDIO_ROUTE_COUNT`: sigue en 77), `StudioAuditEvent.php` (deshice mi cambio de `SUBJECT_TYPES`: `git diff` vacío), `AuthorizationMap`, `PermissionCatalog`, `CrmPermission`, `AppServiceProvider`, el ejemplo de entorno, las migraciones de DB9, `Outreach\Core` y `Outreach\Facts` (los uso sin cambiarlos).

**Fuera del repositorio (scratchpad, para repetir las mutaciones):** `mutate_s9.py` (se niega a correr dentro de un árbol de git; trabaja sobre un clon APFS).

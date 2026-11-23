# PT-14 · Frente 1 (servidor) · informe de backend · 22-11-2026

**Árbol:** `_worktrees/lote-3/Iron-Body`, rama `cierre/voz-catalogo` sobre `c3304671` (el diseño confirmado encima de `integracion/voz` `b1f66c32`). Todo el trabajo está **en el índice, sin confirmar**.
**Diseño:** `plans/informes-cierre/diseno-arq-pt14.md` (§4.1, §4.2, §4.3, §7 servidor; §5 congelado). Preguntas abiertas de §10 con su opción por defecto: poner un plan a la venta **sí** pide el permiso; un texto sin autor registrado **no** excluye a nadie.
**Marcas:** [V] verificado ejecutando · [L] leído en el código · [S] supuesto, sin comprobar.
**Después de la revisión de security** (B-1, N-2 y N-3, corregidos encima de `748f17ab`): ver **§9**. Donde §1–§6 hablan de un solo autor (`commercial_text_by`) o del formato `authors: [{planId, by}]`, manda §9.

---

## 1. Qué cambió, fichero por fichero

### A · El texto y el permiso (paso 1)

| Fichero | Cambio |
|---|---|
| `database/migrations/2026_11_23_100001_add_commercial_text_author_to_plans_table.php` (nuevo) | `plans.commercial_text_by` → `admins`, anulable, `nullOnDelete` (el patrón de `archived_by`). En PG, dentro de una transacción con `SET LOCAL lock_timeout = '5s'` si la sesión no trae límite (como E19-3); en SQLite, tal cual (el migrador no envuelve SQLite en transacción y la reconstrucción de la tabla necesita poder apagar las llaves). `down()`: `dropConstrainedForeignId`. Sin relleno de datos. Sin tocar privilegios |
| `app/Models/Plan.php` | La definición única (D15): constantes `COMMERCIAL_TEXT_FIELDS`, `COMMERCIAL_CATALOG_MAX_PLANS` (20), `COMMERCIAL_CATALOG_MAX_BENEFITS` (18), `COMMERCIAL_BENEFIT_MAX_STORED` (255), `COMMERCIAL_CATALOG_FORMAT`; `commercialText()` (nombre con `trim`, beneficios de `benefitsArray()`; la `ErrorException` de una lista anidada, M-4, se traga con comentario y da `benefits: []`); `commercialTextEquals()`; `scopeCommercialCatalog()`; `commercialCatalogSnapshot()` (planes + autores en **una** consulta); `commercialCatalog()`; `commercialCatalogHash()`; `readCommercialCatalog()` y `commercialCatalogPlans()` (la lectura estricta, compartida por la telefonía y el Studio). `commercial_text_by` en `$hidden` y con cast `integer` |
| `app/Support/Access/PlanCommercialText.php` (nuevo) | La regla de A (precedente `InventoryCosts`): `reasonFor()` (pura: `null`, `text_changed`, `becomes_sellable`), `authorChanges()` (pura: cuándo cambia el autor), `assertAllowed()` (403 si no se tiene la llave), `allowedFor()` + `locked()` + `lockedFor()` (el `commercial_text_locked` del editor) y `withoutAuthor()` (quita la columna del autor de los `changes` de la auditoría) |
| `app/Exceptions/PlanArchiveException.php` | Factoría `commercialTextForbidden($reason)`: 403 `plan_commercial_text_forbidden`, `reasons: [$reason]`, los dos textos literales de §5 A. El `render()` es el de siempre |
| `app/Http/Controllers/Api/PlanController.php` | `adminViewer()` devuelve `?Admin`; `payload(Plan, ?Admin, ?bool)` añade `commercial_text_locked` (null sin sesión de administrador). `store`: `new Plan` + autor + `save()`, y **después** comprueba si entra en la venta con la definición SQL (`Plan::sellable()`) sobre la fila insertada; si pide la llave y no se tiene, el `throw` deshace el alta (ver §6, desviación 1). `update`: dentro de la transacción y del cerrojo, texto y `isSellable()` antes, `fill()`, texto y `isSellable()` después, `reasonFor` → `assertAllowed`, autor si cambia, `save()`; la auditoría, la de siempre (sin el campo del autor). El listado resuelve la llave **una vez** por petición |
| `app/Services/Membership/PlanArchive.php` | `reactivate`: tras el `forceFill`, si el plan queda vendible, `assertAllowed(…, 'becomes_sellable')` y autor = quien reactiva; la auditoría, sin el campo del autor |
| `app/Support/Access/CrmPermission.php` | `PLANS_COMMERCIAL_TEXT = 'plans.commercial_text'`, en la lista de exclusiones del Administrador junto a `PLANS_ARCHIVE`, con su comentario |
| `app/Support/Access/AuthorizationMap.php` | `'plans.commercial_text'` en `CONTROLLER_ENFORCED`, con su comentario |
| `app/Support/Access/PermissionCatalog.php` | Etiqueta «Nombre y beneficios» y la ayuda literal de §4.2 |

### B · La entrega (paso 2)

| Fichero | Cambio |
|---|---|
| `app/Services/Telephony/VoicePublishedPart.php` | `public readonly ?array $commercialCatalog` (lista `{id, name, benefits}` o null); invariante en el constructor: solo en una parte `ok` y con la forma de `Plan::commercialCatalogPlans()` |
| `app/Services/Telephony/VoicePublishedConfig.php` | `rows()`: en la MISMA sentencia, subconsulta correlacionada `a_origin_evidence` (la `evidence` de la entrada `publish` de menor `seq` cuyo `agent_id = p.agent_id`); `get([...])` pasa a `select([...])->selectSub(...)->get()` porque `get($columnas)` ignora sus columnas cuando ya hay un `select`. `part()`: `commercialCatalog` con lectura estricta y sin lanzar (`catalog()`). `partForCall()`: el `partFor` de `VoiceTransferDirectory`, movido sin cambios |
| `app/Services/Telephony/VoiceTransferDirectory.php` | Usa `VoicePublishedConfig::partForCall()`; su `partFor` privado desaparece. Comportamiento igual (las pruebas de destinos siguen en verde) |
| `app/Services/Telephony/VoiceTools.php` | `consultar_planes` en una llamada: `plans($cuenta, partForCall($llamada)?->commercialCatalog ?? [])`; el laboratorio: `plans($cuenta, self::LIVE)` (`LIVE = null`, lee en vivo). `plans()` usa `Plan::commercialCatalog()` (scope) y descarta, antes de la guarda del nombre, los planes que no estaban o cuyo nombre cambió (`plansUnpublished`). `benefitsOf(Plan, ?array)` descarta, antes de limpiar, los beneficios que no estaban publicados (`benefitsUnpublished`). `countsForLog` mapea los dos contadores nuevos. Precio, topes, nota y forma de la respuesta, sin cambios |

### B · La captura (paso 3)

| Fichero | Cambio |
|---|---|
| `app/Services/UltronStudio/StudioVoicePublishing.php` | `preview()`: `catalog` (propósito `publication`; `null` con `lab`) con lista, huella, `authoredByYou`, `authorRecorded` y `fourEyes`. `publish()`: parámetro `expectedCatalogHash`, que entra en el cuerpo de la idempotencia (`catalogHash`; `bodyOf()` lo lee de la `evidence`). `publishGated()`: captura el catálogo UNA vez al empezar (`catalogRecord()`), cuatro ojos del catálogo dentro de `assertFourEyes`, `assertSeenCatalog()` justo después de `assertSeenSpec` (409 `catalog_changed`), `evidence: {number, catalog: {format, hash, plans, authors}}` y el libro con `catalogHash` y `catalogPlans`. `promoteGated()`: cuatro ojos con los autores de la instantánea del canario (`StudioVoicePublications::catalogOf()`). `assertFourEyes()`: tercera comprobación, después de las frases de reglas: 403 `four_eyes_required` con `{detail: plan_text_author, planTextAuthor: [{planId, name}]}` y el evento `voice_publication.four_eyes_denied` con `diff.planTextAuthor` (ids). Revertir y apagar, sin cambios |
| `app/Services/UltronStudio/StudioVoicePublications.php` | `catalogOf(StudioVoiceAgent\|int)` (lectura estricta compartida); `state()`: `catalog` por parte con agente (`{hash, shortHash, plans, drift}`; null sin agente), con el catálogo vigente leído una vez por petición; `entryDto()`: `catalog: {hash, shortHash, plans}` en las `publish` (nunca la lista ni los autores) |
| `app/Services/UltronStudio/StudioVoiceDrift.php` | `catalogVerdict(?array $publicado, array $vigente)`: pura, sin red; `ok`/`changed`/`missing`, con `renamed`, `benefits_changed` y `new` en el orden del catálogo vigente. Un cambio de precio no es deriva |
| `app/Http/Controllers/Crm/UltronStudio/UltronStudioVoiceController.php` | `publish`: `'expectedCatalogHash' => ['required','string','regex:'.self::SHA256]` y se pasa al servicio |

### Documentación (paso 4)

| Fichero | Cambio |
|---|---|
| `docs/ultron-studio/ULTRON_STUDIO_H8_CONTRACT.md` | **Enmienda 21** al final, en el estilo de la 19 y la 20: E21-1 (D8-10 capa 2: lo publicado ∩ lo vigente, falla cerrado), E21-2 (D8-7: la captura en `evidence.catalog`; el mensaje firmado no cambia), E21-3 (S8-c: la vista previa y `expectedCatalogHash` → 409 `catalog_changed`), E21-4 (cuatro ojos `plan_text_author`), E21-5 (E18-8: el laboratorio lee lo que capturaría publicar ahora), E21-6 (la deriva del catálogo, sin freno), E21-7 (despliegue y vuelta atrás) |

### El catálogo de permisos para el CRM

`php artisan permissions:export --path=/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY-Iron-Body-Crm/f97300da-2eb3-4c26-8be8-cf5c85f99353/scratchpad/cierre/pt14/backend-permission-catalog.ts` → «123 permisos exportados» [V]. `plans.commercial_text` aparece en el catálogo y en los permisos por defecto de Super Admin, y no en los del Administrador. sha256 del fichero: `79fbd72390275696aeaccb49d51c7cc4e1a93381fb15c2bdc2eae612d4b8725c`.

---

## 2. Contratos (los de §5, sin cambios)

Implementados tal cual: claves, códigos y textos de §5 A y §5 B. Lo que §5 no fijaba y he tenido que decidir:
- `GET voice/publications`, `state.*.catalog` de una parte **con agente pero sin catálogo** (publicación anterior a PT-14, o `evidence` que no verifica): `{hash: null, shortHash: null, plans: null, drift: {outcome: "missing", changes: []}}`. `catalog: null` solo cuando la parte no tiene agente (entrada `off`), como dice §5.
- El mensaje del 403 `plan_text_author` con **varios** planes: «… de «Mensual», «Trimestral» y «Anual»: tiene que publicarla otra persona.» (el de §5 es el de un plan).
- `history[].catalog` sale en todas las entradas: con valor en las `publish` que lo llevan y `null` en las demás.

---

## 3. Pruebas nuevas: rojo y verde (salidas reales)

Cada clase se escribió antes que su código y se ejecutó para verla caer. Las salidas completas de los rojos están en el scratchpad de este agente (`…/scratchpad/pt14/a-rojo-sqlite.txt`, `b-tel-rojo.txt`, `b-lab9-rojo.txt`, `b-studio-rojo.txt`).

### `tests/Feature/Plans/PlanCommercialTextPermissionTest.php` (A 1–11)

**Rojo** (SQLite, antes del código): `Tests: 11, Assertions: 27, Errors: 1, Failures: 8.`
- 1, 2 y 6 (`no_cambia_el_nombre`, `no_cambia_los_beneficios`, `reactivar_pide_tambien`): `Expected response status code [403] but received 200.`
- 5 (`crear_o_duplicar`): `Expected response status code [403] but received 201.`
- 4 (`no_se_pone_a_la_venta_en_dos_pasos`): `Failed asserting that null is identical to 2.` (no hay autor: la columna no existe).
- 7 (`super_admin_puede`): `Failed asserting that null is identical to false.` (no hay `commercial_text_locked`).
- 8 (`commercial_text_locked`): `Administrador … 1 => null, 2 => null` en vez de `true/false`.
- 10 (`catalogo`): `Failed asserting that an array contains 'plans.commercial_text'.`
- 11 (`migracion`): `ErrorException: require(…/2026_11_23_100001_add_commercial_text_author_to_plans_table.php): Failed to open stream`.
- 3 (`reenviar_el_mismo_texto`) y 9 (`funciones_ia_archivar_y_borrar`) **pasaban ya**: son las de no regresión (el diseño marca la 3 como «no es roja hoy»).

**Verde:** SQLite `OK (11 tests, 117 assertions)`; PostgreSQL 14 `OK (11 tests, 121 assertions)` (las 4 de más son las de PG: `lock_timeout` = `5s` tras `up()` y `has_column_privilege('iron_app', 'public.plans', 'commercial_text_by', SELECT|INSERT|UPDATE)` con DML a nivel de tabla). La 11 hace `down()` → `up()` en los dos motores, fija que los índices de `plans` son los mismos tras la reconstrucción de SQLite, que no hay relleno y que la llave se anula al borrar la cuenta.

### `tests/Feature/Telephony/VoiceToolsPublishedCatalogTest.php` (telefonía 1–8)

**Rojo:** `Tests: 14, Assertions: 26, Errors: 7, Failures: 5.`
- 6 (`catalogo_manipulado`, 7 casos: huella, precio dentro, 21 planes, formato, beneficio que no es texto, autores ajenos, sin autores): `ErrorException: Undefined property: App\Services\Telephony\VoicePublishedPart::$commercialCatalog`.
- 2, 3, 4, 5 y 7 (`renombrado`, `beneficios`, `puesto_a_la_venta_despues`, `sin_catalogo`, `sellada_a_otro_agente`): `Failed asserting that two arrays are identical.` (hoy entrega el renombrado, el añadido, el nuevo, todo sin catálogo y todo con otro agente).
- 1 (`precio_de_ahora`) y 8 (`las_guardas_siguen_encima`) pasaban ya (lo de siempre sigue igual).

**Verde:** SQLite `OK (14 tests, 81 assertions)`; PostgreSQL 14, junto a las de la entrega (`VoiceLabToolsTest`, `VoiceTransferDestinationsTest`, `VoiceToolsBenefitsTest`, `VoiceToolsTest`): `OK (91 tests, 915 assertions)`.

### Telefonía 9, en `tests/Feature/Telephony/VoiceLabToolsTest.php`: `test_el_laboratorio_sigue_leyendo_en_vivo`

La parte del laboratorio pasaría con el código de antes (ya leía en vivo); la prueba contrasta además con una llamada sellada. **Rojo** comprobado poniendo un momento el `VoiceTools.php` de `HEAD` (`git show HEAD:…`) y devolviendo el nuevo (sha256 `a716ef76…` antes y después, idénticos): `Tests: 1, Assertions: 15, Failures: 1.` con `la llamada, no: ninguno de los dos estaba aprobado con ese nombre`. **Verde** en la clase entera: SQLite `OK (16 tests, 385 assertions)`; PG en la ejecución de 91 de arriba.

### Telefonía 10: `TelephonyIsolationTest`, `OtpUntouchedByVoiceTest`, `VoiceIsolationTest`

Sin cambios y en verde (en la batería final, §5).

### `tests/Feature/UltronStudio/UltronStudioVoiceCatalogTest.php` (Studio 1–7; la 8 es repetirla en PG)

**Rojo:** `Tests: 7, Assertions: 90, Errors: 2, Failures: 5.`
- 6 y 7 (`revertir_y_apagar`, `deriva`): `ErrorException: Undefined array key "catalog"`.
- 1 (`vista_previa`) y 4 (`autor_no_publica`): `Failed asserting that null is identical to Array …` (no hay `catalog` en la vista previa).
- 2 (`exige_la_huella`): `Expected response status code [422] but received 201.`
- 3 (`guarda_el_catalogo`): `Failed asserting that two arrays are identical.` (la `evidence` no lleva `catalog`).
- 5 (`autor_del_canario_no_promueve`): `Expected response status code [403] but received 409.` (pasa los cuatro ojos y se para en la evidencia).

**Verde:** SQLite `OK (7 tests, 155 assertions)`; PostgreSQL 14: ver §5.

---

## 4. Pruebas existentes adaptadas, y por qué

Todas por el cambio de requisito del propietario (PT-14). Ninguna aserción se ha aflojado ni saltado: se cambia el actor o se publica con su catálogo, y cada cambio lleva su comentario `PT-14 (cambio de requisito del propietario…)` en el código.

**De la lista de §7:**
- `tests/Feature/Plans/PlanArchiveTest.php`. `setUp`: el Administrador recibe también `plans.commercial_text` desde la matriz (reactivar mete el plan en la venta). `test_archivar_exige_motivo_y_permiso_de_gestion`: con `plans.archive` sola, reactivar ahora es 403 `plan_commercial_text_forbidden`/`becomes_sellable` y el plan sigue archivado (**aserción nueva**); con las dos llaves, 200. Caían 4: `test_archivar_exige_motivo…`, `test_quien_ya_tiene_el_plan_conserva…`, `test_reactivar_lo_devuelve…` y `test_reactivar_puede_dejarlo_fuera…`, las cuatro con 403 `becomes_sellable`.
- `tests/Feature/Telephony/VoiceToolsBenefitsTest.php`. La llamada sellada no tenía catálogo publicado y caían 9 (todas por «ningún plan»: `Undefined array key "Plan Valera"`, `0 is identical to 16`, …). `consultarPlanes()` publica la primera vez la voz con el catálogo sembrado (`voiceCatalogEvidence()`) y la de los 20 planes también. En `test_c1_ii…`, después de renombrar «Plan Valera», se vuelve a publicar con el catálogo de ahora (otro agente) y se pregunta desde una llamada sellada a esa publicación: lo que se prueba es la guarda del nombre, y sigue dando `plansWithheld: 2`.
- `tests/Feature/Telephony/VoiceToolsTest.php`. Los dos casos de planes (el del fixture y `test_consultar_planes_solo_da_los_vendibles…`) publican con el catálogo que siembran.
- Las pruebas de publicar voz del Studio que llaman a `publish` sin `expectedCatalogHash`: `StudioVoiceFixtures::publishVoice()` manda, como el CRM nuevo, la huella que enseñaría la vista previa (`seenCatalogHash()`, el mismo patrón que `seenSpecHash()`); `UltronStudioVoicePublishingTest::test_publicar_lleva_la_huella…` (su cuerpo de validación, que ahora daba 422 antes del 403), `UltronStudioVoiceNoNetworkTest` (dos cuerpos) y `UltronStudioEngineReadOnlyTest::test_recorrer_todas_las_rutas…` (un cuerpo).
- `tests/Feature/Access/RolePolicyTest.php`: **no cae y no se ha tocado.** Ninguna de sus aserciones depende de la lista completa de exclusiones del Administrador; los permisos por defecto de PT-14 los fija la prueba nueva A 10.

**Fuera de la lista de §7 (investigadas antes de tocarlas):**
- `tests/Feature/Telephony/VoiceLabToolsTest.php`: `test_los_datos_son_los_mismos_que_recibe_una_llamada_real` y `test_los_beneficios_llegan_al_laboratorio…` comparan una llamada sellada con el laboratorio. La llamada no tenía catálogo publicado: es la misma premisa que `VoiceToolsBenefitsTest` y la Enmienda 21 (E21-5) la cambia. Se publica con el catálogo de ahora y la comparación sigue siendo exacta.
- `tests/Feature/UltronStudio/UltronStudioVoiceOutboundOnlyTest.php` (2 pruebas): fijaban la `evidence` de `publish` exacta (`['number' => …]`). D5 la amplía con `catalog`: ahora fijan `['number', 'catalog']` como claves y `number` exacto como antes.
- `tests/Feature/Audit/AuditChangesTest.php`, 2 pruebas: `test_una_cadena_vacia_si_cuenta_como_cambio` (el Administrador vaciaba los beneficios de un plan vendible: `text_changed`) y `test_el_caso_exacto_de_produccion` (el precio pasaba de 0 a 1 y ponía el plan a la venta: `becomes_sellable`). Las dos prueban la **traza**, no la llave: el actor pasa a Super Admin. Con él, la segunda habría tenido un campo de más en `changes` (`commercial_text_by`). Por eso la auditoría quita esa columna (`PlanCommercialText::withoutAuthor()`), y la traza sigue siendo la de siempre (la línea ya lleva su actor). La prueba nueva A 7 fija que `changes` es `['name', 'benefits']` y no incluye el autor.
- `tests/Feature/UltronStudio/UltronStudioEngineReadOnlyTest::test_solo_dos_ficheros_del_studio_nombran_el_motor…`: **choque del diseño con una guarda.** `App\Models\Plan` cuenta como clase del motor en esa guarda, y D15 y §4.3 hacen que `StudioVoicePublishing` y `StudioVoicePublications` lean `Plan` directamente. Lo he resuelto con el mismo mecanismo con el que el H5 admitió a `StudioPublications`: los dos ficheros entran en `ENGINE_READERS`, y una comprobación nueva y más estricta fija que de todo el motor **solo nombran `App\Models\Plan`**, que de `Plan` solo usan `commercialCatalogSnapshot(`, `commercialCatalog(`, `commercialCatalogHash(`, `readCommercialCatalog(` y `COMMERCIAL_CATALOG_FORMAT`, y que ninguna sentencia que lo nombre escribe. Comprobado por mutación: con un `Plan::query()->update(...)` metido un momento en `StudioVoicePublishing`, la guarda cae con `StudioVoicePublishing.php usa de Plan algo que no es leer el catálogo: Plan::query(`. Después se restauró el fichero, con la misma huella sha256 `15806c34…` antes y después. **Lo debe confirmar el lead o security.**

**Fixtures ampliadas:** `tests/Feature/Telephony/Support/VoiceFixtures.php` (`publishVoice(…, ?array $evidence = null)` y `voiceCatalogEvidence()`, que usa la captura de producción `Plan::commercialCatalogSnapshot()`) y `tests/Feature/UltronStudio/StudioVoiceFixtures.php` (`publishVoice(…, ?string $expectedCatalogHash = null)` y `seenCatalogHash()`). La prueba 3 del Studio cierra el círculo de punta a punta: lo que escribe la API del Studio lo lee `VoicePublishedConfig::read()` (`commercialCatalog` idéntico).

---

## 5. Ejecuciones finales

Un solo PHPUnit a la vez, nunca la batería completa. Lista ejecutada (45 rutas): mis tres clases nuevas; `tests/Feature/Plans`, `tests/Feature/Telephony` (incluye `VoiceLabToolsTest`, `TelephonyIsolationTest` y `OtpUntouchedByVoiceTest`), `tests/Feature/Access`; todas las `tests/Feature/UltronStudio/*Voice*Test.php`; el paquete de guardas (`find tests -name "*Isolation*Test.php" -o -name "*ReadOnly*Test.php" -o -name "*StaticGuards*Test.php" -o -name "SecretExposure*Test.php"`, 17 ficheros); y además `tests/Feature/Audit/AuditChangesTest.php`, `tests/Feature/AdminRoutesSecurityTest.php`, `tests/Feature/Voice` (E2E y frontera), `tests/Feature/Outreach/V9`, `UltronStudioStreamVoiceTopicsTest` y `UltronStudioRulePhraseAuthorsTest`.

- **SQLite:** `OK, but there were issues!` → `Tests: 1414, Assertions: 446088, PHPUnit Deprecations: 3, Skipped: 276.` Ningún fallo ni error (2 min 15 s).
- **PostgreSQL 14** (`UNIT_DB=ultron_studio_cierre_pt14`, puerto 55432): `Tests: 1414, Assertions: 448638, Errors: 3, PHPUnit Deprecations: 3, Skipped: 54.` (5 min 24 s). Los **3 errores no son de PT-14** y no los he tocado:
  - `Access\AdminUserPasswordTest::test_si_el_motor_falla_al_guardar_ni_el_hash_ni_la_clave_llegan_al_log`: la propia prueba ejecuta, en su línea 353, un disparador con sintaxis de SQLite (`CREATE TRIGGER clave_bloqueada … BEGIN SELECT RAISE(ABORT, …); END`) y PG responde `42601 syntax error at or near "BEGIN"`. Falla antes de llamar a código de la aplicación.
  - `Integrations\WhatsappReviewIsolationTest::test_a_review_webhook_creates_no_production_side_effects` y `::test_a_production_webhook_is_still_processed_normally`: `22P02 invalid input syntax for type uuid: "test-review"` / `"test-prod"` al insertar en `meta_webhook_events.correlation_id`.
  - Ninguno de los dos ficheros está en mi diff, y la tabla y la sentencia que fallan no son de PT-14. No están en `tests/baseline-failures.txt`. **No los he ejecutado contra `HEAD`** para demostrar que ya fallaban: lo deduzco de dónde falla cada uno.
- **Las tres clases nuevas, después del formato de Pint** (imports de `DB` y `TestResponse`): SQLite `OK (32 tests, 355 assertions)`, PostgreSQL 14 `OK (32 tests, 359 assertions)`.

Las omitidas en SQLite son pruebas solo de PostgreSQL, que ya existían. Solo he comparado el recuento antes y después en `tests/Feature/Telephony`: 552 pruebas y 138 omitidas en las dos ejecuciones. Los 3 avisos de deprecación de PHPUnit son de metadatos de pruebas que no he tocado: ya salían en la ejecución de las 152 pruebas de `/api/plans` fuera de `Plans/`, que no incluía ninguna clase nueva.

Otras ejecuciones intermedias [V], todas en verde tras adaptar: `tests/Feature/Plans` + `tests/Feature/Access` (319 pruebas; antes de adaptar caían solo las 4 de `PlanArchiveTest`); las que tocan `/api/plans` fuera de `Plans/` (152; antes de adaptar caían solo las 2 de `AuditChangesTest`); las de Outreach que tocan la voz (113).

**Pint** (`vendor/bin/pint --test`): pasa en los ficheros nuevos (los dos ficheros de pruebas, formateados con `pint` después de la batería y vueltos a ejecutar) y en `PlanArchiveException.php`. En `Plan.php` y `PlanController.php` las quejas ya estaban en `HEAD` (el orden de los `use` y `@see` con nombre completo en comentarios anteriores) y no las he tocado.

---

## 6. Desviaciones del diseño (con su motivo)

1. **`store`: la venta se decide en SQL después de insertar, no con `isSellable()` antes.** `new Plan($data)` no lleva `sellable` si el cliente no lo manda, y la columna nace en `true` en la base. `isSellable()` sobre el modelo sin guardar decía «no vendible» a un duplicado del CRM que sí queda a la venta: era el agujero que A tiene que cerrar. Ahora se inserta (con el autor), se pregunta a la base con `Plan::sellable()` y, si hace falta la llave y no se tiene, el `throw` deshace el alta. La prueba A 5 cubre los dos casos: el alta con `sellable: true` y el duplicado sin él. Coste: un `INSERT` que se deshace (y consume un valor de la secuencia) cuando se deniega.
2. **El catálogo y sus autores se leen en UNA consulta** (`Plan::commercialCatalogSnapshot()`). El diseño ponía dos: el catálogo y después los autores de esos ids. Con dos, una edición entre ambas lecturas podía atribuir a otra persona el texto que se publica, y la autora real pasaría los cuatro ojos.
3. **La captura se hace al empezar `publishGated`, antes de `assertFourEyes`.** El diseño la ponía después de `assertSeenSpec`, pero también mete los cuatro ojos del catálogo dentro de `assertFourEyes`, que va antes de la ventana. Así se respetan las dos cosas: los cuatro ojos (403) siguen antes de `catalog_changed` (409), y los dos miran la misma captura.
4. **`orderBy('id')` como último criterio** en `scopeCommercialCatalog()`. Sin desempate, dos lecturas del mismo catálogo con `sort_order` y `duration_days` iguales podían darse en otro orden en PostgreSQL, con otra huella, y producir un `catalog_changed` falso o cambiar qué plan entra en el tope de 20. En `consultar_planes` solo cambia el orden entre empates, que antes no estaba definido.
5. **Los beneficios capturados: primero el tope de 18 y después se quitan los de más de 255 caracteres** y los que no son UTF-8 válido (nunca se dicen). Así la instantánea es un subconjunto de los que examina la voz.
6. **`PlanCommercialText` tiene tres funciones más que las del diseño:** `authorChanges()` (la condición del autor, en un solo sitio), `allowedFor()`/`locked()` (la llave se resuelve una vez por listado, como `InventoryCosts`; `lockedFor()` existe tal cual lo pedía el diseño) y `withoutAuthor()` (§4).
7. **La nota `BENEFITS_NOTE`** también sale cuando algún beneficio quedó fuera por no estar publicado (antes, solo con retenidos u omitidos). Su texto no cambia («si te preguntan por un beneficio que no está en esta lista…»), y es justo el caso.
8. **El diff del 403 `plan_text_author` en el libro** lleva `version: false, profile: false` además de `action` y `planTextAuthor`, como el de las frases de reglas.

---

## 7. Lo que NO he verificado

- **La batería completa** del servidor, en ninguno de los dos motores (la corre el lead). Tampoco el CRM.
- **Producción:** no he tocado nada. Que `iron_app` tenga DML sobre `plans` a nivel de tabla en producción sigue siendo un supuesto [S]: lo que sí he comprobado es que, con esos privilegios, la columna nueva los hereda (PG 14 local).
- La migración contra una base con datos y transacciones concurrentes (el `55P03` con `lock_timeout`): solo que el límite se pone. **Pendiente la revisión de database-specialist** (§9 del diseño).
- El 422 de un CRM viejo al publicar, el texto de la deriva en el CRM y la interfaz: son del frente 2.
- Una publicación real en ElevenLabs: todo va con el desplegador falso y sin red.

---

## 8. Lo adyacente que he visto (sin arreglarlo)

- **`badge` y `restrictions`** también son texto libre, WhatsApp los lee y A no los cubre (§10 del diseño ya lo saca del alcance; se señala a security).
- **`compile-preview` exige `ultron.voice_manage`.** Quien solo tenga `ultron.voice_publish` no puede previsualizar, y por tanto no ve el catálogo ni puede publicar (desde PT-14, publicar exige la huella de la vista previa). Por defecto las dos llaves son de Super Admin, pero un rol que el propietario configure solo con «publicar» se quedaría sin poder publicar. Las pruebas del Studio dan las dos llaves a quien publica.
- **`PlanController::validatedData`** convierte con `(string)` cada beneficio de un array. Una lista anidada en `benefits` (M-4) da un 500 al guardar un plan, antes de llegar a esta lógica. Es anterior a PT-14.
- **Pint** marca en `Plan.php` y `PlanController.php` el orden de los `use` y los `@see` con nombre completo. Es anterior a PT-14.
- **Riesgo R2 («último autor») del diseño:** sigue tal cual. Que lo acepte o no es de security.

---

## 9. Tras la revisión de seguridad (B-1, N-2 y N-3)

**Informe de security:** `…/scratchpad/cierre/informe-security-pt14.md`, veredicto NO APTO por B-1. **Corrección:** la «correcta» del informe, decidida y firmada por el lead; cambia D4 y D10 del diseño. Trabajo encima de `748f17ab`, en el índice y sin confirmar. Los demás no bloqueantes del informe (N-1, N-4 a N-8) no se tocan.

### 9.1 Qué cambió

| Fichero | Cambio |
|---|---|
| `database/migrations/2026_11_23_100001_add_commercial_text_author_to_plans_table.php` | Segunda columna en la **misma** migración (no está desplegada): `plans.commercial_sale_by` → `admins`, anulable, `nullOnDelete`. `down()` quita las dos |
| `app/Models/Plan.php` | `commercial_sale_by` en `$hidden` y con cast `integer`. `commercialCatalogSnapshot()` lee las dos columnas en la misma consulta y da `authors: [{planId, by, soldBy}]`. `readCommercialCatalog()` exige exactamente `{planId, by, soldBy}` (cada uno, id ≥ 1 o null) y rechaza la forma anterior sin `soldBy`; el formato sigue siendo `ultron-voice-catalog/1`. Nuevo `commercialTextReadable()`: el `try/catch` de M-4 vive en `readableBenefits()`, que comparten las dos funciones |
| `app/Support/Access/PlanCommercialText.php` | `authorChanges()` se sustituye por `textChanged()` (por valor, o ilegible con el valor en bruto cambiado; el alta, siempre) y `becomesSellable()`. `reasonFor()` recibe `$unreadableChange` (N-2). `AUTHOR_COLUMNS` con las dos columnas; `withoutAuthor()` quita las dos de `changes` |
| `app/Http/Controllers/Api/PlanController.php` | `update`: `commercial_text_by` solo si `textChanged()`; `commercial_sale_by` solo si `becomesSellable()`; N-2: `! commercialTextReadable() && isDirty('benefits')` cuenta como texto cambiado (pide la llave en un plan vendible). `store`: `commercial_text_by` siempre; si el alta queda vendible, también `commercial_sale_by` (un `UPDATE` más en la misma transacción, después de comprobar la llave) |
| `app/Services/Membership/PlanArchive.php` | Reactivar escribe `commercial_sale_by`; el autor del texto no cambia |
| `app/Services/UltronStudio/StudioVoicePublishing.php` | Cuatro ojos: quien publica o promueve no puede ser `by` NI `soldBy` de ningún plan capturado (`isPlanAuthor()`). Vista previa: `authoredByYou` = eres `by` o `soldBy`; `authorRecorded` = `by !== null`. El 403, sus claves y su mensaje no cambian, y por la API no sale ni `by` ni `soldBy` |
| `app/Services/UltronStudio/StudioVoicePublications.php` | Solo el phpdoc del tipo que devuelve `catalogOf()` |
| `docs/ultron-studio/ULTRON_STUDIO_H8_CONTRACT.md` | Enmienda 21: E21-1 (la forma de los autores), E21-2 (`authors: [{planId, by, soldBy}]`, se rechaza la forma anterior), E21-4 (las dos autorías, por qué y los cuatro ojos con las dos; marcada como corregida por B-1 con la decisión del lead) y E21-7 (la migración añade las dos columnas) |

**Pruebas:**
- **Nuevas:**
  - en A: `test_cambiar_solo_el_precio_no_toca_ninguna_autoria` y `test_una_lista_anidada_sobre_un_plan_vendible_pide_la_llave` (N-2);
  - en el Studio: `test_b1_ni_quien_escribio_el_texto_ni_quien_lo_puso_a_la_venta_publican_y_una_tercera_persona_si`, con dos casos, «Activar» (el CRM manda solo `{active: true}`) y «Reactivar». Acaba con una llamada real sellada a la publicación y con `consultar_planes`, que dice el texto aprobado;
  - en telefonía: dos casos manipulados más, autores sin `soldBy` (la forma anterior) y un `soldBy` que no es un id.
- **Ajustadas** (cambio de requisito decidido por el lead):
  - A 4: tras escribir con el plan apagado, quien tiene la llave lo enciende; queda en `commercial_sale_by` y el autor del texto no cambia;
  - A 5: un alta no vendible deja `commercial_sale_by` en null; el alta vendible con la llave deja las dos;
  - A 6: quien reactiva queda en `commercial_sale_by`, el autor del texto no cambia y la auditoría no lleva ninguna de las dos columnas;
  - A 7: cambiar el texto de un plan que ya estaba a la venta no es ponerlo a la venta, y ninguna de las dos columnas sale en el listado;
  - A 11: las dos columnas, su `nullOnDelete` y el privilegio de `iron_app` en PG para las dos;
  - Studio 1: un plan que puso a la venta quien mira es «suyo» (`authoredByYou: true`, `authorRecorded: false`) y el mensaje nombra «Mensual» y «Semestral»;
  - Studio 3 y 4: los autores guardados con `soldBy`;
  - la fixture `voiceCatalogEvidence()` con `soldBy`.
- **La clase del Studio cierra la red explícitamente en su `setUp`.** Para la llamada de B6 combina las fixtures de telefonía y del Studio con `insteadof`, como `VoiceLabToolsTest`. Con esa forma, el rastreo de `VoiceIsolationTest::test_las_pruebas_de_voz_con_red_cierran_la_red` (protección 3) no sigue a `voiceSetUp()`, y la guarda lo cazó en la primera ejecución de la lista: `Pruebas de voz con red sin Http::preventStrayRequests() (protección 3): tests/Feature/UltronStudio/UltronStudioVoiceCatalogTest.php`. Se arregló cerrando la red en la prueba; la guarda no se ha tocado.
- **N-3, `UltronStudioEngineReadOnlyTest`.** Para los dos ficheros de la voz prohíbe además:
  - alias de `Plan` o de su espacio de nombres (`use App\Models\Plan as …`, `use App\Models\{Plan as …}`, `use App\Models as …`);
  - cualquier otro nombre cualificado que acabe en `\Plan` fuera del `use`;
  - cualquier cadena que nombre `App\Models\Plan`;
  - las consultas a la tabla `plans` por el constructor (`table('plans'` y `from('plans'`) y el SQL que la escriba.

  No prohíbe el literal `'plans'` sin más, porque los dos ficheros lo usan como clave de array.

### 9.2 Rojo y verde

**Rojo** (SQLite, con las pruebas ya cambiadas y el código de `748f17ab`): `Tests: 38, Assertions: 228, Errors: 10, Failures: 6.`
- **B-1** (A 4): `el autor del texto no cambia — Failed asserting that 1 is identical to 2.` Encenderlo sobrescribía al autor del texto.
- A 5 (`two arrays are identical`, el alta vendible) y A 6 (`null is identical to 2`, sin `commercial_sale_by`).
- **N-2:** `Expected response status code [403] but received 200.`
- A 11: `up() vuelve a poner commercial_sale_by — Failed asserting that false is true.`
- Telefonía, caso «autores sin quien lo puso a la venta»: el formato anterior se aceptaba (`no se sabe qué se aprobó`).
- Las 10 de la clase del Studio, y la nueva de A sobre el precio, caían con `no such column: commercial_sale_by`, antes de llegar a la regla.
- **Rojo de comportamiento de B6**, con la columna ya añadida (migración y `Plan`) y la regla todavía sin cambiar: `Tests: 2, Assertions: 10, Failures: 2.` En los dos casos, «Activar» y «Reactivar», `[text_by, sale_by]` daba `[6, null]` en vez de `[5, 6]`: quien lo puso a la venta pasaba a ser la autora del texto y no quedaba constancia de la venta.

**Verde:**
- Las tres clases, en SQLite: `OK (38 tests, 452 assertions)`.
- En PostgreSQL 14, las clases tocadas (`PlanCommercialTextPermissionTest`, `UltronStudioVoiceCatalogTest`, `VoiceToolsPublishedCatalogTest`, `UltronStudioEngineReadOnlyTest`, `PlanArchiveTest` y `AuditChangesTest`): `OK (79 tests, 4761 assertions)`.

**Mutaciones de N-3** [V]. Cada una se metió un momento en el fichero real como método nuevo y se ejecutó `--filter test_solo_dos_ficheros`; después se restauró la copia y se comprobó la huella (`StudioVoicePublishing.php` `93335912…` y `StudioVoicePublications.php` `26687cec…`, iguales antes y después).

| Mutación | Guarda de `748f17ab` | Guarda endurecida |
|---|---|---|
| m1 alias (`use App\Models\Plan as PlanStore; PlanStore::query()->update(...)`) | `OK (1 test, 236 assertions)`: pasaba | `importa Plan (o su espacio de nombres) con un alias.` |
| m2 cadena (`app('App\Models\Plan')->newQuery()->update(...)`) | `OK (1 test, 236 assertions)`: pasaba | `nombra la clase Plan en una cadena: 'App\Models\Plan'` |
| m4 `DB::table('plans')->update(...)` | `OK (1 test, 236 assertions)`: pasaba | `consulta la tabla plans sin pasar por Plan.` |
| m3 control (`Plan::query()->update(...)`), en `StudioVoicePublications.php` | ya caía | `usa de Plan algo que no es leer el catálogo: Plan::query(` |

En `StudioVoicePublications.php`, m1, m2 y m4 caen igual (los cuatro casos ejecutados). Sin mutaciones, la guarda endurecida pasa: `OK (1 test, 1566 assertions)`.

### 9.3 Ejecuciones finales (la misma lista de 45 rutas de §5)

- **SQLite:** `OK, but there were issues!` → `Tests: 1420, Assertions: 447555, PHPUnit Deprecations: 3, Skipped: 276.` Ningún fallo ni error. Es la segunda ejecución: la primera dio 1 fallo, el de la protección 3 de §9.1, ya corregido.
- **PostgreSQL 14:** `Tests: 1420, Assertions: 450108, Errors: 3, PHPUnit Deprecations: 3, Skipped: 54.` Son los **mismos 3 errores ajenos** de §5 y con los mismos mensajes: `AdminUserPasswordTest` (`42601` por el disparador con sintaxis de SQLite que escribe la propia prueba) y los dos de `WhatsappReviewIsolationTest` (`22P02`, `correlation_id` que no es uuid).
- **Pint:** pasa en todo lo tocado. En `Plan.php` y `PlanController.php`, Pint cambiaría las mismas 6 líneas en `HEAD` y en mi versión, todas anteriores a PT-14.

### 9.4 Lo que queda y no he verificado

- **R2 en su forma original** (sonda B1b del informe): si otra persona con la llave edita **otro** beneficio con X delante, pasa a ser la autora del texto y la primera publica. Security lo confirma como no bloqueante; no cambia con esta corrección.
- **`store`** hace ahora un `UPDATE` de más en la misma transacción cuando el alta queda vendible. Antes del `UPDATE` ya se comprobó la llave, y si falta, el `throw` deshace el alta entera.
- **N-2 queda acotado a A.** `validatedData` y `benefitsArray()` no se tocan. La lista anidada la sigue pudiendo guardar quien tenga la llave, y entonces rompe igual el catálogo de la app y el de WhatsApp. Validar la forma al guardar es el hallazgo aparte del informe.
- **La guarda de N-3** sigue siendo un análisis de tokens: una clase que se construya concatenando cadenas, o un `DB::table($variable)`, no los ve. Lo prohibido son las tres formas de las mutaciones de security y sus variantes inmediatas.
- **No he ejecutado** la batería completa ni nada en producción.

---

## 10. Cierre tras las revisiones (lo añade el lead, 23-11-2026)

- **Seguridad independiente:** primera pasada, NO APTO por B-1; tras la corrección (`90653164`), **APTO CON CONDICIONES y B-1 CERRADO** (`informe-security-pt14.md`, apartado «Recomprobación»). Sus dos condiciones son operativas: la comprobación del runbook antes de la primera publicación y el privilegio de `iron_app` sobre las dos columnas al desplegar (leído en producción el 23-11: DML a nivel de tabla, así que lo heredan).
- **Revisión final independiente:** servidor **APROBADO CON CONDICIONES** (la única, que la corrección de B-1 pase la revisión de su delta), 0 bloqueantes; con 13 mutaciones propias, las 13 caen (`informe-reviewer-pt14.md`).
- **Batería completa** sobre `748f17ab`: 19 361 pruebas, 0 regresiones. La del commit corregido, ver la fila PT-14 de `MATRIZ_CIERRE_V2.md`.
- **Tras la revisión final (NB-S1):** `VoiceTools::MAX_BENEFITS_EXAMINED` deja de ser un 18 propio y toma `Plan::COMMERCIAL_CATALOG_MAX_BENEFITS`: una sola cifra para lo que la voz examina y lo que se aprueba. Sin cambio de comportamiento.
- **No bloqueantes que no se hacen en este cierre:** en `MATRIZ_CIERRE_V2.md` §11.2 y en el §11 del diseño.


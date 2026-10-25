# Informe del paso 3 (B-S) del Hito 5: la API de la publicación

**Fecha:** 25-10-2026 · **Rama:** `feat/ultron-studio-h5` · **Base:** `044f70b` (= `ea04d3f`, que dio el lead, más su commit de scripts de operación `044f70b`; ningún solape con lo mío) · **Commit:** ninguno: el árbol queda con los cambios sin confirmar para que el lead los confirme (m-3 en un commit aparte, §8).

Contrato: `docs/ultron-studio/ULTRON_STUDIO_H5_CONTRACT.md` (A1–A13 y Enmienda 1). Construido sobre lo que ya existía y estaba verificado (lector del motor `UltronPublishedConfig`, sello, migraciones, fixture `publication-cases.json`, núcleo puro `StudioPublicationDeclaration` y `StudioCanaryEvidence`): solo se llama a sus firmas; no se ha rehecho nada de ello.

---

## 0. En una pantalla

**Qué queda hecho.** La cadena de publicaciones con sus cuatro escrituras (publicar en canario, promover a todas, revertir, volver a Legacy), la vista previa (`previewHash`), la evidencia del canario contada en la base (con el acta reducida a recuentos), el historial, el bloque `engine.publication` de `status`, el extra `governance.studioPublication` de las lecturas del motor, los heredados A10.1 (`integrityOk` por lado), A10.2 (archivadas paginadas), A10.4 (m-3, aparte) y A10.6 (`READ COMMITTED` en `recordDenied`), el comando `ultron-studio:publication-check`, la exportación de las formas para el CRM y **`StudioPublishing::ENGINE_READS_VERSIONS = true`**. Son 38 rutas.

**Con las llaves por defecto** (`ULTRON_STUDIO_PUBLISHING_ENABLED=false`, `MARKETING_ULTRON_STUDIO_CONFIG=off`): publicar y promover responden 409 `publishing_disabled`, y si se enciende solo la del Studio, 409 `engine_not_reading_versions`. Revertir y Legacy funcionan sobre la cadena, que nadie lee. Probado por HTTP (`UltronEngineIsolationTest::test_el_motor_lee_solo_con_su_llave_y_publicar_esta_apagado_por_defecto`, `UltronStudioPermissionsTest`, volcado §3).

**Verificación** (detalle y salidas en §6):

| Qué | Resultado |
|---|---|
| `auth:coverage --unmapped` | vacío, exit 0 |
| `route:list --path=admin/ultron-studio` | 38 |
| SQLite: `tests/Feature/UltronStudio tests/Unit/UltronStudio tests/Feature/Access tests/Feature/Chaos` | **OK**: 1654 pruebas, 20 038 aserciones, 18 omitidas (las 18 son de solo-PostgreSQL) |
| **PostgreSQL 14** (`ops/pgunit.sh`, base `ultron_studio_h5_api`): `tests/Feature/UltronStudio tests/Unit/UltronStudio` | **OK**: 1403 pruebas, 19 163 aserciones, 0 omitidas |
| `tests/Feature/Marketing tests/Unit/Marketing` (una vez) | **OK**: 4977 pruebas, 21 689 aserciones |
| Extra (no pedido): todo lo que nombra la salud de las colas, IRON GUARD y Observability | OK: 60 + 19 pruebas |
| `permissions:export` frente a `Iron-Body_Front/src/app/models/backend-permission-catalog.ts` | **idéntico** (sha256 `aeb9699c…`; 96 permisos, ninguna clave nueva) |
| `ultron-studio:export-fixtures` → `docs/ultron-studio/plans/informes-h5/export/` | 5 ficheros; los 4 que ya existían son idénticos a los del CRM; `backend-publication.testing.ts` es nuevo |
| `git diff origin/main --stat` en las rutas del motor | solo los 3 ficheros del bloque 1 (`UltronCommitService.php`, `UltronPublishedConfig.php`, `config/marketing.php`); nada sin confirmar en esas rutas |
| Mutaciones | **15**, todas con el resultado esperado: M5 solo la caza PostgreSQL; M12 y M13 sobreviven donde deben (defensa doble; puerta que solo ve la paridad unitaria). Restauración verificada con sha256 en cada una y huella del árbol idéntica antes y después (§6.11) |
| Lectores ESTRICTOS del CRM (`studio-parse.ts`) sobre 62 respuestas reales de esta API | 62/62 legibles; el único código que el CRM no conoce es `publication_head_invalid` (D-1) |
| Pint y `php -l` sobre los 45 PHP tocados o nuevos | pasan (`AppServiceProvider.php` aparte: ya fallaba Pint en `HEAD` con los mismos cuatro fixers; no lo he «arreglado») |

**Lo que tiene que decidir el lead** (detalle en §5): D-1 (código nuevo `publication_head_invalid`, que el CRM aún no conoce), D-2 (`acknowledged` guarda también `previewHash`), D-3 (`lab_gate = {"mode": null}` fuera de publicar), D-4 (la nota de publicar o promover va en `reason`) y D-5 (el extra no entra en el catálogo de lecturas). Las demás son decisiones de implementación dentro del contrato, documentadas.

**Lo que NO he verificado** (§6.13): la pila real (E2E con n8n/Meta simulados), carreras con dos sesiones reales en PG (las mías son inyectadas en una sesión), la revisión de seguridad prevista en A12 y que el CRM consuma `backend-publication.testing.ts`.

---

## 1. Las 38 rutas: llave, limitador y respuestas

Salida de `AuthorizationMap::resolve()` sobre el router real (§6.2). `read` = `throttle:ultron-studio-read`, `write` = `throttle:ultron-studio-write`. Todas exigen persona (sin sesión 401; token compartido 403 `forbidden`, o `person_required` si llega a `StudioAccess`).

| Método | Ruta (`/api/admin/ultron-studio/…`) | Llave | Limitador | H5 |
|---|---|---|---|---|
| GET | `status` | `ultron.view` | read | **cambia**: `engine.publication` (`PublicationStateDto`), `engine.activeVersion` = uuid de la parte resto, `publishing.code` con los códigos de A5, tubería con los nueve estados |
| GET | `catalog` | `ultron.view` | read | — |
| GET | `draft` | `ultron.view` | read | — |
| GET | `versions` | `ultron.view` | read | estados nuevos |
| GET | `versions/{uuid}` | `ultron.view` | read | **cambia**: `actions.publish/promote/rollback` reales y `publications: PublicationEntryDto[]` |
| POST | `versions/{uuid}/validate` | `ultron.view` | read | — |
| GET | `rules` | `ultron.view` | read | **cambia**: con `status` que incluye `archived`, paginado (`page`, `per_page` ≤ 50, por defecto 20) |
| GET | `audit` | `ultron.view` | read | eventos de A8 |
| GET | `engine/readings` | `ultron.view` | read | **cambia**: extra `governance.studioPublication` |
| GET | `compare` | `ultron.view` | read | **cambia**: `from.integrityOk` / `to.integrityOk` |
| GET | `lab/status`, `lab/scenarios`, `lab/runs`, `lab/runs/{uuid}`, `lab/events`; POST `lab/estimate` | `ultron.view` | read | — |
| GET | `versions/{uuid}/publication-preview` | `ultron.view` | read | **NUEVA (34)**. 200 `PublicationPreviewDto`; 404 `version_not_found` |
| GET | `versions/{uuid}/canary-evidence` | `ultron.view` | read **+ `throttle:ultron-studio-evidence`** (10/min por persona, 30/min por red) | **NUEVA (35)**. 200 `CanaryEvidenceDto`; 404; 409 `invalid_transition` (`allowedFrom: ["CANARY"]`); 429 `too_many_requests` (`retry_after`) |
| GET | `publications` | `ultron.view` | read | **NUEVA (37)**. Paginador (`page`, `per_page` ≤ 50, por defecto 20), de la cabeza hacia atrás |
| PUT | `draft`; POST `draft/discard`, `versions`, `versions/{uuid}/submit` | `ultron.edit_draft` | write | — |
| POST | `versions/{uuid}/approve`, `versions/{uuid}/reject` | `ultron.approve` | write | — |
| POST | `versions/{uuid}/publish` | `ultron.publish` | write | **real (13)**: 201, 200 `replayed`, 403 `forbidden`/`four_eyes_required`, 404, 409 (A5.1 + `publication_moved` + `publication_head_invalid`), 422 |
| POST | `versions/{uuid}/rollback` | `ultron.rollback` | write | **real (14)**: 201, 200 `replayed`, 403, 404, 409 `client_request_reused`/`not_active_publication`/`restore_integrity_mismatch`/`publication_head_invalid`/`publication_moved`/`invalid_transition` (carrera), 422 |
| POST | `rules`; PATCH `rules/{uuid}`; POST `rules/{uuid}/archive` | `ultron.manage_rules` | write | — |
| POST | `lab/scenarios`; PATCH `lab/scenarios/{uuid}`; POST `lab/scenarios/{uuid}/archive` | `ultron.lab_manage` | write | — |
| POST | `lab/runs` (+ `ultron-studio-lab-run`), `lab/runs/{uuid}/cancel` | `ultron.lab_run` | write | — |
| POST | `lab/runs/{uuid}/verdict` | `ultron.approve` | write | — |
| POST | `versions/{uuid}/promote` | `ultron.publish` | write | **NUEVA (36)**: 201, 200 `replayed`, 403, 404, 409 (A5.2 + `publication_moved` + `publication_head_invalid`), 422 |
| POST | `publications/legacy` | `ultron.rollback` | write | **NUEVA (38)**: 201, 200 `replayed`, 403, 409 `client_request_reused`/`already_legacy`/`publication_moved`, 422 |

**Cuerpos aceptados** (validación de forma en el controlador, 422 estándar de Laravel; todos con el tope de 64 KB del grupo):

| Ruta | Cuerpo |
|---|---|
| `publish` | `clientRequestId` uuid · `previewHash` `^[0-9a-f]{64}$` · `acknowledgedNoEffect` lista (puede ir vacía, ≤ 64, elementos `^[A-Za-z0-9_.:\-]+$` ≤ 80, distintos) · `note?` ≤ 500 (texto plano, multilínea) |
| `promote` | `clientRequestId` · `evidenceHash` sha256 · `note?` ≤ 500 |
| `rollback` | `clientRequestId` · `reason` 5–500 (texto plano, multilínea) |
| `publications/legacy` | `clientRequestId` · `reason` 5–500 |
| `publications`, `rules?status=archived` | `page` ≥ 1 · `per_page` (≤ 0 → 20; > 50 → 50) |

**Matriz de acceso** (probada en `UltronStudioPermissionsTest`, 38 rutas, y en `UltronStudioPublicationAccessTest`): sin sesión 401; token compartido 403; Recepción, Entrenador, Administrativo y un rol solo de Mercadeo 403 en las cinco nuevas; **Administrador**: lee todo (vista previa, evidencia, historial) y recibe 403 `forbidden` en `publish`, `promote`, `rollback` y `publications/legacy`; Super Admin permitido. Defensa doble: aunque se rebaje la llave de la ruta, el servicio vuelve a exigir `ultron.publish` (mutación M12, §6.11).

---

## 2. Las puertas, en el orden en que se comprueban

Es el orden del fixture (secciones `gates`, 71 casos, y `evidence`), reproducido por HTTP (`UltronStudioPublicationLifecycleTest`: 45 de publicar y 13 de promover; `UltronStudioPublicationRollbackTest`: 13 de revertir y Legacy). Las puertas puras se comprueban además una a una contra los mismos hechos (`StudioPublicationGatesParityTest`, 72 pruebas).

**Publicar** (`StudioPublications::publish`):
1. `ultron.view` y `ultron.publish` → 403 `forbidden` (`required_permission`).
2. Llave del Studio → 409 `publishing_disabled`; llave del motor `off` → 409 `engine_not_reading_versions`. Se auditan como `version.publish_refused {code}` (deduplicado, como en F) **solo si la versión existe**: un uuid inventado no escribe en el libro.
3. Reintento por `clientRequestId`: misma persona, acción, versión y cuerpo (`noEffect` como conjunto, `previewHash` y `note`) → 200 `replayed` con la entrada de la primera vez (aunque la versión ya esté en CANARY); otra cosa → 409 `client_request_reused`.
4. Versión → 404. Cuatro ojos por IDENTIDAD (no por permiso) → 403 `four_eyes_required` + `version.four_eyes_denied {action: publish}` (deduplicado).
5–11. `StudioPublications::publishRefusal()` (pura): `invalid_transition` (`currentStatus`, `allowedFrom`) → `integrity_mismatch` → `revalidation_blocked` (`findings`) → `revalidation_changed` (`warnings`) → `lab_verdict_required` (`latest`) → `engine_value_mismatch` (`mismatches`) / `engine_reading_unavailable` (`engineReadingUnavailable`) → `no_effect_not_acknowledged` (`noEffect`, conjunto exacto) → `preview_stale` (`preview` nueva) → `engine_has_no_canary` → `engine_braked` → `canary_in_progress` → `outside_publication_window` (`window`).
12. Transacción: cabeza que no verifica → 409 `publication_head_invalid`; firma del motor (`UltronPublishedConfig::signatureFor`) sobre los bytes exactos; inserción `seq = cabeza + 1`; `APPROVED → CANARY` condicionado; `version.published`. Un 23505 se resuelve FUERA de la transacción: reintento, o `seq` → `publication_moved`, o `usv_one_canary` → `canary_in_progress`.

**Promover** (`promote`): 1–4 como publicar (cuatro ojos `{action: promote}`) → llave del motor `all` → 409 `scope_not_allowed` (auditado `version.promote_refused`) → `invalid_transition` (CANARY) → `integrity_mismatch` → evidencia insuficiente → 409 `canary_evidence_insufficient` con `evidence` → `evidenceHash` distinto → 409 `evidence_changed` con `evidence` → `engine_braked` → `outside_publication_window` → transacción (`CANARY → PUBLISHED`, la PUBLISHED anterior → `RETIRED` con `version.retired {by: promote}`, entrada `{canary: B, rest: B}` con la MISMA parte: Q-3).

**Revertir** (`rollback`): `ultron.rollback` → reintento → 404 → `not_active_publication` (`currentStatus`) → si es la PUBLISHED: de dónde vuelve la parte resto (la entrada anterior a su última promoción, que tiene que verificar entera; la versión que vuelve tiene que existir, estar RETIRED, ser íntegra y tener la huella de la parte) → si no, 409 `restore_integrity_mismatch` (auditado `version.rollback_refused`) → transacción (cabeza verificada; `CANARY`: `{canary: rest, rest: rest}`; `PUBLISHED`: `{rest anterior, rest anterior}` o `{null, null}`; con otra en canario, su parte se conserva: Q-2). Sin llaves, sin cuatro ojos y sin ventana.

**Legacy** (`legacy`): `ultron.rollback` → reintento → cabeza ausente o verificada con las dos partes vacías → 409 `already_legacy` (se comprueba otra vez dentro de la transacción) → entrada `{null, null}`, CANARY → `ROLLED_BACK`, PUBLISHED → `RETIRED`, `system.publication_legacy`. **No verifica la cabeza:** es la salida de una cabeza rota (probado).

**Evidencia** (`StudioCanaryMeasures` + `StudioCanaryEvidence`): una sola consulta sobre `marketing_ai_actions` de la conversación canaria del motor, `source_type = inbound_message`, `created_at >= entrada` (turnos, reparto por sello, sellados con ESTA versión, recuperaciones); frenos con `engaged_at >= entrada` o echado ahora; acta `ultron:canary-report <canario> --since=<entrada> --turns=min(200, max(40, turns)) --json` dentro del proceso, de la que solo se leen el código de salida, `mecanicos_total` y los 7 contadores (si su JSON no cuadra o falla: `{exitCode: 1, total: null, counts: null}`, falla cerrado, y al log solo la clase de la excepción); deriva de los 4 `must_match` con una lectura caída como deriva (Q-5); `signatureOk` del `describe()` del motor. Sin canario configurado: cero turnos, acta `{1, null, null}` y deriva en `governance.canaryOnly`.

---

## 3. Formas reales, para el CRM

**Cómo se obtuvieron.** Una prueba desechable (fuera del repo) recorre la API por HTTP como lo haría el CRM, sobre la línea base importada y con el reloj en 2026-11-03/04 (hora de Bogotá dentro de la ventana salvo donde se dice): llaves cerradas y abiertas, publicar, reintentar, promover, revertir, Legacy, cabeza rota, y cada rechazo. Vuelca las 62 respuestas (59 por HTTP y 3 que no se alcanzan por HTTP sin una carrera o con el catálogo actual, que se toman de la misma `render()` que usa la API). Después, **esas 62 respuestas se pasaron por los lectores ESTRICTOS del propio CRM** (`studio-parse.ts`: `readStatus`, `readPublicationPreview`, `readCanaryEvidence`, `readPublicationEntry`, `readPublicationPage`, `readVersionDetail`, `readEngineReadings`, `readCompare`, `readArchivedRulePage` y los `error*` de las cargas de los 409), empaquetados con el esbuild del CRM en un directorio temporal, sin tocar su árbol: **62/62 legibles**. Por el lado del CRM solo hay que avisar de que `publication_head_invalid` llega con `code: null` (D-1) y de que los 422 no traen `code` (es el 422 estándar).

Los uuids, las huellas y los nombres (`Pablo Publica`, `Prueba-…` es el rol de prueba) son de la prueba. `at` va en ISO 8601 con `+00:00`; `since` de la evidencia, con `Z`, porque entra en `evidenceHash` y el fixture fija ese formato.

#### `GET status` → `data.engine` y `data.publishing`, Legacy (cadena vacía, llaves abiertas, motor en `all`)

```json
{
  "engine": {
    "readsVersions": true,
    "activeVersion": null,
    "message": "El motor de ULTRON lee la cadena en todas las conversaciones y anota en cada turno la versión publicada. Publicar no cambia lo que ULTRON dice ni hace.",
    "publication": {
      "envScope": "all",
      "head": null,
      "canary": null,
      "rest": null,
      "drift": [],
      "state": "legacy"
    }
  },
  "publishing": {
    "available": true,
    "code": null,
    "message": "Se puede publicar: el motor lee la cadena en todas las conversaciones."
  }
}
```

#### `data.engine.publication` y `data.publishing` con la llave del motor en `off`

```json
{
  "engine.publication": {
    "envScope": "off",
    "head": null,
    "canary": null,
    "rest": null,
    "drift": [],
    "state": "off"
  },
  "publishing": {
    "available": false,
    "code": "engine_not_reading_versions",
    "message": "El motor de ULTRON no está leyendo la cadena de publicaciones (su llave está apagada): publicar no tendría ningún efecto."
  }
}
```

#### `data.publishing` con `ULTRON_STUDIO_PUBLISHING_ENABLED=false` (el defecto)

```json
{
  "available": false,
  "code": "publishing_disabled",
  "message": "Publicar está desactivado en el servidor: lo enciende quien administra el sistema."
}
```

#### `data.engine` con una versión en canario (`activeVersion` = la parte resto: aún ninguna)

```json
{
  "readsVersions": true,
  "activeVersion": null,
  "message": "El motor de ULTRON lee la cadena en todas las conversaciones y anota en cada turno la versión publicada. Publicar no cambia lo que ULTRON dice ni hace.",
  "publication": {
    "envScope": "all",
    "head": {
      "uuid": "f2ff5367-3342-43e9-bc55-450c8c2472fd",
      "seq": 1,
      "action": "publish",
      "at": "2026-11-03T15:10:00+00:00",
      "signatureOk": true
    },
    "canary": {
      "versionUuid": "ae13abd5-3c19-4ec6-bdcd-d1a828e3f92b",
      "label": "Primera versión"
    },
    "rest": null,
    "drift": [],
    "state": "applied"
  }
}
```

#### `data.engine` tras promover, con deriva (el entorno cambió `rules.paymentLinksEnabled`)

```json
{
  "readsVersions": true,
  "activeVersion": "ae13abd5-3c19-4ec6-bdcd-d1a828e3f92b",
  "message": "El motor de ULTRON lee la cadena en todas las conversaciones y anota en cada turno la versión publicada. Publicar no cambia lo que ULTRON dice ni hace.",
  "publication": {
    "envScope": "all",
    "head": {
      "uuid": "55a43998-4720-47ab-8264-1882231a9df1",
      "seq": 2,
      "action": "promote",
      "at": "2026-11-04T16:00:00+00:00",
      "signatureOk": true
    },
    "canary": {
      "versionUuid": "ae13abd5-3c19-4ec6-bdcd-d1a828e3f92b",
      "label": "Primera versión"
    },
    "rest": {
      "versionUuid": "ae13abd5-3c19-4ec6-bdcd-d1a828e3f92b",
      "label": "Primera versión"
    },
    "drift": [
      {
        "param": "rules.paymentLinksEnabled",
        "declared": false,
        "engine": true
      }
    ],
    "state": "applied"
  }
}
```

#### `data.engine.publication` con una cabeza que no verifica (firma de otra clave)

```json
{
  "envScope": "all",
  "head": {
    "uuid": "bc7fa0de-ffaf-4908-b522-907f1a778910",
    "seq": 8,
    "action": "legacy",
    "at": "2026-11-04T16:05:00+00:00",
    "signatureOk": false
  },
  "canary": null,
  "rest": null,
  "drift": [],
  "state": "fallback"
}
```

#### `GET versions/{uuid}/publication-preview` → `PublicationPreviewDto`, publicable (línea base aprobada)

```json
{
  "versionUuid": "ae13abd5-3c19-4ec6-bdcd-d1a828e3f92b",
  "contentHash": "9060d16439064921a5d83594bf495c30841d4a77c2aa64074f69a032de966e56",
  "previewHash": "c47ccca44a7c97bdc6ed909117817378942d4bbc5471c60a21838ae05ca367f7",
  "publishable": true,
  "params": [
    {
      "layer": "knowledge",
      "key": "strictFacts",
      "class": "matches",
      "versionValue": true,
      "engineValue": true,
      "ok": true
    },
    {
      "layer": "intent",
      "key": "driver",
      "class": "must_match",
      "versionValue": "entorno",
      "engineValue": "rules",
      "ok": true
    },
    {
      "layer": "context",
      "key": "windowTurns",
      "class": "matches",
      "versionValue": 10,
      "engineValue": 10,
      "ok": true
    },
    {
      "layer": "memory",
      "key": "useLeadProfile",
      "class": "matches",
      "versionValue": true,
      "engineValue": true,
      "ok": true
    },
    {
      "layer": "strategy",
      "key": "discountsAllowed",
      "class": "matches",
      "versionValue": false,
      "engineValue": false,
      "ok": true
    },
    {
      "layer": "rules",
      "key": "handoffOnlyCorroborated",
      "class": "matches",
      "versionValue": true,
      "engineValue": true,
      "ok": true
    },
    {
      "layer": "rules",
      "key": "paymentLinksEnabled",
      "class": "must_match",
      "versionValue": false,
      "engineValue": false,
      "ok": true
    },
    {
      "layer": "integrations",
      "key": "channel",
      "class": "matches",
      "versionValue": "whatsapp",
      "engineValue": "whatsapp",
      "ok": true
    },
    {
      "layer": "integrations",
      "key": "appointments",
      "class": "matches",
      "versionValue": true,
      "engineValue": true,
      "ok": true
    },
    {
      "layer": "governance",
      "key": "enabled",
      "class": "must_match",
      "versionValue": true,
      "engineValue": true,
      "ok": true
    },
    {
      "layer": "governance",
      "key": "canaryOnly",
      "class": "must_match",
      "versionValue": true,
      "engineValue": true,
      "ok": true
    },
    {
      "layer": "governance",
      "key": "autoBrake",
      "class": "matches",
      "versionValue": true,
      "engineValue": true,
      "ok": true
    }
  ],
  "noEffect": [],
  "requirements": {
    "status": {
      "ok": true,
      "current": "APPROVED"
    },
    "fourEyes": {
      "ok": true
    },
    "integrity": {
      "ok": true
    },
    "revalidation": {
      "ok": true
    },
    "labGate": {
      "mode": "waived",
      "ok": true,
      "latest": null,
      "message": "La puerta del laboratorio está dispensada en el servidor (waived): queda escrito en la publicación."
    },
    "engineCanary": {
      "ok": true
    },
    "brake": {
      "ok": true
    },
    "canaryFree": {
      "ok": true
    },
    "window": {
      "ok": true,
      "window": null
    },
    "keys": {
      "studio": true,
      "engine": "all"
    }
  },
  "blocking": []
}
```

#### Vista previa con un cambio sin efecto (tono): `params` incluye la fila `no_effect` y `noEffect` la nombra

```json
{
  "noEffect": [
    "persona.tone"
  ],
  "params[no_effect]": [
    {
      "layer": "persona",
      "key": "tone",
      "class": "no_effect",
      "versionValue": "profesional",
      "engineValue": null,
      "ok": true
    }
  ],
  "publishable": true,
  "blocking": []
}
```

#### Vista previa bloqueada (puerta del laboratorio `required` sin veredicto, cobro distinto en el motor, freno echado, ventana 07:00-19:00)

```json
{
  "publishable": false,
  "blocking": [
    "lab_verdict_required",
    "engine_value_mismatch",
    "engine_braked"
  ],
  "requirements": {
    "status": {
      "ok": true,
      "current": "APPROVED"
    },
    "fourEyes": {
      "ok": true
    },
    "integrity": {
      "ok": true
    },
    "revalidation": {
      "ok": true
    },
    "labGate": {
      "mode": "required",
      "ok": false,
      "latest": null,
      "message": "Sin veredicto del laboratorio sobre esta huella."
    },
    "engineCanary": {
      "ok": true
    },
    "brake": {
      "ok": false,
      "message": "El freno de ULTRON está echado: no se publica ni se promueve hasta que alguien lo suelte."
    },
    "canaryFree": {
      "ok": true
    },
    "window": {
      "ok": true,
      "window": "07:00–19:00"
    },
    "keys": {
      "studio": true,
      "engine": "all"
    }
  },
  "params[rules.paymentLinksEnabled]": [
    {
      "layer": "rules",
      "key": "paymentLinksEnabled",
      "class": "must_match",
      "versionValue": false,
      "engineValue": true,
      "ok": false
    }
  ]
}
```

#### La misma versión vista por su autora (Administrador, sin `ultron.publish`): depende de quien mira; `previewHash` no

```json
{
  "blocking": [
    "forbidden",
    "four_eyes_required"
  ],
  "requirements.fourEyes": {
    "ok": false,
    "message": "Nadie publica lo que creó: tiene que publicarla otra persona."
  },
  "previewHash = el de quien publica": true
}
```

#### `GET versions/{uuid}/canary-evidence` → `CanaryEvidenceDto`, suficiente (24 h 50 min desde la entrada → `hours: 24`; 3 turnos sellados)

```json
{
  "versionUuid": "ae13abd5-3c19-4ec6-bdcd-d1a828e3f92b",
  "since": "2026-11-03T15:10:00Z",
  "thresholds": {
    "minHours": 24,
    "minTurns": 3
  },
  "hours": 24,
  "turns": 3,
  "stamped": 3,
  "byState": {
    "applied": 3,
    "fallback/contract_unknown": 0,
    "fallback/hash_mismatch": 0,
    "fallback/malformed": 0,
    "fallback/read_failed": 0,
    "fallback/signature_invalid": 0,
    "fallback/table_missing": 0,
    "legacy": 0,
    "out_of_scope": 0,
    "unstamped": 0
  },
  "brakes": {
    "count": 0,
    "engaged": false
  },
  "acta": {
    "exitCode": 0,
    "total": 0,
    "counts": {
      "datos_personales": 0,
      "enlaces_no_oficiales": 0,
      "horarios_aplazados_en_una_persona": 0,
      "negaciones_de_un_cobro_disponible": 0,
      "planes_no_vendibles": 0,
      "precios_que_no_son_del_catalogo": 0,
      "traspasos_no_autorizados": 0
    }
  },
  "recoveries": 0,
  "drift": [],
  "signatureOk": true,
  "evidenceHash": "b5a0cf2c8f8999b3a75fc630f289125204f9bc0f00a8435066298b7e5cd87c40",
  "passes": true,
  "failing": []
}
```

#### Evidencia insuficiente (recién publicada): solo cambian las cifras, `passes` y `failing`

```json
{
  "since": "2026-11-03T15:10:00Z",
  "hours": 0,
  "turns": 0,
  "stamped": 0,
  "evidenceHash": "4f70f752ba5eaaa94a6c6fca8688736bf6917919300095136c95f783551edd8e",
  "passes": false,
  "failing": [
    "hours",
    "turns"
  ]
}
```

#### `POST versions/{uuid}/publish` → 201 (`PublicationEntryDto` en `data`)

```json
{
  "ok": true,
  "data": {
    "uuid": "f2ff5367-3342-43e9-bc55-450c8c2472fd",
    "seq": 1,
    "action": "publish",
    "version": {
      "uuid": "ae13abd5-3c19-4ec6-bdcd-d1a828e3f92b",
      "label": "Primera versión"
    },
    "effectiveHash": "4900d87e731c63b81dec79e738cf6b5295af9cfdefeb44624fbcd1539f5bb431",
    "shortHash": "4900d87e",
    "labGate": {
      "mode": "waived"
    },
    "actor": {
      "name": "Pablo Publica",
      "role": "Prueba-f8f2f3e7"
    },
    "reason": "Primera en canario",
    "at": "2026-11-03T15:10:00+00:00"
  }
}
```

#### El mismo `clientRequestId` con el mismo cuerpo → 200 `replayed: true` (la entrada de la primera vez)

```json
{
  "ok": true,
  "replayed": true,
  "data.uuid": "f2ff5367-3342-43e9-bc55-450c8c2472fd"
}
```

#### `POST versions/{uuid}/promote` → 201 (`labGate: null` fuera de publicar; la nota iría en `reason`)

```json
{
  "uuid": "55a43998-4720-47ab-8264-1882231a9df1",
  "seq": 2,
  "action": "promote",
  "version": {
    "uuid": "ae13abd5-3c19-4ec6-bdcd-d1a828e3f92b",
    "label": "Primera versión"
  },
  "effectiveHash": "ec6b2953a9becf7efda01715576d58dd84e868f02211bbf11827236f5793ae47",
  "shortHash": "ec6b2953",
  "labGate": null,
  "actor": {
    "name": "Pablo Publica",
    "role": "Prueba-f8f2f3e7"
  },
  "reason": null,
  "at": "2026-11-04T16:00:00+00:00"
}
```

#### `POST versions/{uuid}/rollback` de la PUBLICADA sin anterior → 201 (la cadena queda en Legacy)

```json
{
  "uuid": "d93a143d-6e1d-4f68-89c7-de588ae9abe8",
  "seq": 5,
  "action": "rollback",
  "version": {
    "uuid": "ae13abd5-3c19-4ec6-bdcd-d1a828e3f92b",
    "label": "Primera versión"
  },
  "effectiveHash": "bc9881851fcc58e33175f58e698502afb0d0353a594d0c610894772243a3ef62",
  "shortHash": "bc988185",
  "labGate": null,
  "actor": {
    "name": "Pablo Publica",
    "role": "Prueba-f8f2f3e7"
  },
  "reason": "Volvemos a lo de antes",
  "at": "2026-11-04T16:05:00+00:00"
}
```

#### `POST publications/legacy` → 201 (`version: null`)

```json
{
  "uuid": "5548eb5a-c722-4c7a-961e-993dee37ded1",
  "seq": 7,
  "action": "legacy",
  "version": null,
  "effectiveHash": "147f3e5b462f9053fed8907ee1b02bde4583dfbe3dd3e856ddc59aaeb4ae68ff",
  "shortHash": "147f3e5b",
  "labGate": null,
  "actor": {
    "name": "Pablo Publica",
    "role": "Prueba-f8f2f3e7"
  },
  "reason": "Apagar la declaración",
  "at": "2026-11-04T16:05:00+00:00"
}
```

#### `GET publications?per_page=3` → paginador de Laravel dentro de `{ok, data}` (de la cabeza hacia atrás)

```json
{
  "current_page": 1,
  "data": [
    {
      "uuid": "5548eb5a-c722-4c7a-961e-993dee37ded1",
      "seq": 7,
      "action": "legacy",
      "version": null,
      "effectiveHash": "147f3e5b462f9053fed8907ee1b02bde4583dfbe3dd3e856ddc59aaeb4ae68ff",
      "shortHash": "147f3e5b",
      "labGate": null,
      "actor": {
        "name": "Pablo Publica",
        "role": "Prueba-f8f2f3e7"
      },
      "reason": "Apagar la declaración",
      "at": "2026-11-04T16:05:00+00:00"
    },
    "… (3 por página en este volcado)"
  ],
  "first_page_url": "http://localhost/api/admin/ultron-studio/publications?page=1",
  "from": 1,
  "last_page": 3,
  "last_page_url": "http://localhost/api/admin/ultron-studio/publications?page=3",
  "links": "[… el de Laravel …]",
  "next_page_url": "http://localhost/api/admin/ultron-studio/publications?page=2",
  "path": "http://localhost/api/admin/ultron-studio/publications",
  "per_page": 3,
  "prev_page_url": null,
  "to": 3,
  "total": 7
}
```

#### `GET versions/{uuid}` (en canario, visto por quien tiene `ultron.publish` y `ultron.rollback`): `actions` y `publications`

```json
{
  "status": "CANARY",
  "statusLabel": "Canario",
  "actions": {
    "submit": {
      "allowed": false,
      "code": "forbidden",
      "message": "Hace falta el permiso «Editar el borrador y crear versiones»."
    },
    "approve": {
      "allowed": false,
      "code": "forbidden",
      "message": "Hace falta el permiso «Aprobar o rechazar versiones de otra persona»."
    },
    "reject": {
      "allowed": false,
      "code": "forbidden",
      "message": "Hace falta el permiso «Aprobar o rechazar versiones de otra persona»."
    },
    "publish": {
      "allowed": false,
      "code": "invalid_transition",
      "message": "Solo se publica una versión aprobada."
    },
    "promote": {
      "allowed": true
    },
    "rollback": {
      "allowed": true
    }
  },
  "publications": [
    {
      "uuid": "f2ff5367-3342-43e9-bc55-450c8c2472fd",
      "seq": 1,
      "action": "publish",
      "version": {
        "uuid": "ae13abd5-3c19-4ec6-bdcd-d1a828e3f92b",
        "label": "Primera versión"
      },
      "effectiveHash": "4900d87e731c63b81dec79e738cf6b5295af9cfdefeb44624fbcd1539f5bb431",
      "shortHash": "4900d87e",
      "labGate": {
        "mode": "waived"
      },
      "actor": {
        "name": "Pablo Publica",
        "role": "Prueba-f8f2f3e7"
      },
      "reason": "Primera en canario",
      "at": "2026-11-03T15:10:00+00:00"
    }
  ]
}
```

#### `GET engine/readings` → extra `governance.studioPublication` (lo que devuelve `describe()`; sin id del canario)

```json
{
  "available": true,
  "value": {
    "env": "all",
    "head": {
      "entry": "f2ff5367-3342-43e9-bc55-450c8c2472fd",
      "seq": 1,
      "contract": "ultron-engine-config/1",
      "hashOk": true,
      "signatureOk": true,
      "parseOk": true
    },
    "parts": {
      "canary": true,
      "rest": false
    },
    "canaryConfigured": true
  }
}
```

#### `GET compare?from=baseline&to={uuid}` → `integrityOk` por lado (A10.1)

```json
{
  "from": {
    "kind": "baseline",
    "integrityOk": null
  },
  "to": {
    "kind": "version",
    "label": "Primera versión",
    "integrityOk": true
  }
}
```

#### `status.lifecycle` (tubería): nueve estados alcanzables; `RETIRED` = «Sustituida»

```json
[
  {
    "status": "DRAFT",
    "label": "Creada, sin enviar",
    "reachable": true
  },
  {
    "status": "VALIDATING",
    "label": "Validando",
    "reachable": false,
    "reason": "La validación es inmediata: ninguna versión queda en este estado."
  },
  {
    "status": "VALIDATED",
    "label": "Validada, espera revisión",
    "reachable": true
  },
  {
    "status": "REVIEW_REQUIRED",
    "label": "Con avisos · espera revisión",
    "reachable": true
  },
  {
    "status": "APPROVED",
    "label": "Aprobada",
    "reachable": true
  },
  {
    "status": "REJECTED",
    "label": "Rechazada",
    "reachable": true
  },
  {
    "status": "CANARY",
    "label": "Canario",
    "reachable": true
  },
  {
    "status": "PUBLISHED",
    "label": "Publicada",
    "reachable": true
  },
  {
    "status": "RETIRED",
    "label": "Sustituida",
    "reachable": true
  },
  {
    "status": "ROLLED_BACK",
    "label": "Revertida",
    "reachable": true
  }
]
```

#### Cuerpos de error reales (los 4xx del Hito 5)

`preview` y `evidence` traen el DTO ENTERO (arriba); aquí se abrevian. `via factory`: no alcanzable por HTTP sin una carrera o con el catálogo actual; es la misma `render()` que usa la API.

| HTTP | Escenario | Cuerpo |
|---|---|---|
| 409 | `publish.publishing_disabled` | `{"ok": false, "code": "publishing_disabled", "message": "Publicar está desactivado en el servidor: lo enciende quien administra el sistema."}` |
| 409 | `publish.engine_not_reading_versions` | `{"ok": false, "code": "engine_not_reading_versions", "message": "El motor de ULTRON no está leyendo la cadena de publicaciones (su llave está apagada): publicar no tendría ningún efecto."}` |
| 403 | `publish.forbidden` | `{"ok": false, "code": "forbidden", "message": "No tienes permiso para esta acción.", "required_permission": "ultron.publish"}` |
| 403 | `publish.four_eyes_required` | `{"ok": false, "code": "four_eyes_required", "message": "Nadie publica lo que creó: pide a otra persona con permiso que lo haga."}` |
| 404 | `publish.version_not_found` | `{"ok": false, "code": "version_not_found", "message": "La versión no existe."}` |
| 422 | `publish.validation_422` | `{"message": "The client request id field must be a valid UUID. (and 2 more errors)", "errors": {"clientRequestId": ["The client request id field must be a valid UUID."], "previewHash": ["The preview hash field is required."], "acknowledgedNoEffect.0": ["The acknowledgedNoEffect.0 field format is invalid."]}}` |
| 409 | `publish.lab_verdict_required` | `{"ok": false, "code": "lab_verdict_required", "message": "No se puede publicar: esta versión no tiene ningún veredicto del laboratorio. Hace falta un veredicto «aprobada» sobre esta misma huella.", "latest": null}` |
| 409 | `publish.engine_value_mismatch` | `{"ok": false, "code": "engine_value_mismatch", "message": "La versión declara valores distintos de los que lee hoy el motor. Una declaración que contradice al motor sería falsa: crea una versión que refleje el motor.", "mismatches": [{"param": "rules.paymentLinksEnabled", "versionValue": false, "engineValue": true}]}` |
| 409 | `publish.engine_reading_unavailable` | `{"ok": false, "code": "engine_reading_unavailable", "message": "No se pudo leer lo que tiene hoy el motor en algún interruptor: sin esa lectura no se publica. Vuelve a intentarlo en un momento.", "engineReadingUnavailable": ["intent.driver"]}` |
| 409 | `publish.no_effect_not_acknowledged` | `{"ok": false, "code": "no_effect_not_acknowledged", "message": "Para publicar hay que reconocer exactamente lo que no tendrá efecto en el motor, ni uno más ni uno menos.", "noEffect": ["persona.tone"]}` |
| 409 | `publish.preview_stale` | `{"ok": false, "code": "preview_stale", "message": "La vista previa cambió desde que la viste: revísala y vuelve a confirmar.", "preview": "<preview completa>"}` |
| 409 | `publish.engine_braked` | `{"ok": false, "code": "engine_braked", "message": "El freno de ULTRON está echado: no se publica ni se promueve hasta que alguien lo suelte."}` |
| 409 | `publish.outside_publication_window` | `{"ok": false, "code": "outside_publication_window", "message": "Fuera de la ventana de publicación (07:00–19:00, hora de Bogotá). Revertir y volver a Legacy se pueden hacer siempre.", "window": "07:00–19:00"}` |
| 409 | `publish.integrity_mismatch` | `{"ok": false, "code": "integrity_mismatch", "message": "La huella de la versión no coincide con su contenido. No se puede publicar: avisa a quien administra el sistema."}` |
| 409 | `publish.revalidation_blocked` | `{"ok": false, "code": "revalidation_blocked", "message": "La versión ya no pasa la validación con el catálogo actual: tiene bloqueos y no se puede publicar.", "findings": "<9 hallazgos con id/level/title/detail>"}` |
| 409 | `publish.revalidation_changed` | `{"ok": false, "code": "revalidation_changed", "message": "El catálogo cambió después de aprobar: los avisos de la versión ya no son los que se reconocieron. Crea una versión nueva y vuelve a aprobarla.", "warnings": ["opcional-E14", "opcional-E16"]}` |
| 409 | `publish.engine_has_no_canary` *(factory)* | `{"ok": false, "code": "engine_has_no_canary", "message": "El motor no tiene configurada la conversación canaria: sin ella no se publica (toda publicación empieza en canario)."}` |
| 409 | `publish.client_request_reused` | `{"ok": false, "code": "client_request_reused", "message": "Ese identificador de petición ya se usó para otra operación. Vuelve a intentarlo desde el Studio."}` |
| 409 | `publish.invalid_transition` | `{"ok": false, "code": "invalid_transition", "message": "Una versión en estado «Canario» no admite «publicar».", "currentStatus": "CANARY", "allowedFrom": ["APPROVED"]}` |
| 409 | `publish.canary_in_progress` | `{"ok": false, "code": "canary_in_progress", "message": "Ya hay otra versión en canario. Promuévela o revértela antes de publicar otra."}` |
| 409 | `promote.canary_evidence_insufficient` | `{"ok": false, "code": "canary_evidence_insufficient", "message": "La evidencia del canario todavía no basta para promover. Revisa qué falta.", "evidence": "<evidence completa>"}` |
| 409 | `promote.scope_not_allowed` | `{"ok": false, "code": "scope_not_allowed", "message": "Promover exige que el motor lea la cadena en todas las conversaciones (su llave en «all»). Hoy solo la lee en la canaria."}` |
| 409 | `promote.invalid_transition` | `{"ok": false, "code": "invalid_transition", "message": "Una versión en estado «Aprobada» no admite «promover».", "currentStatus": "APPROVED", "allowedFrom": ["CANARY"]}` |
| 409 | `evidence.not_canary` | `{"ok": false, "code": "invalid_transition", "message": "Una versión en estado «Aprobada» no admite «ver la evidencia del canario».", "currentStatus": "APPROVED", "allowedFrom": ["CANARY"]}` |
| 409 | `promote.evidence_changed` | `{"ok": false, "code": "evidence_changed", "message": "La evidencia del canario cambió desde que la viste: revísala y vuelve a confirmar.", "evidence": "<evidence completa>"}` |
| 409 | `rollback.not_active_publication` | `{"ok": false, "code": "not_active_publication", "message": "Solo se revierte la versión en canario o la publicada vigente.", "currentStatus": "APPROVED"}` |
| 422 | `rollback.validation_422` | `{"message": "The reason field must be at least 5 characters.", "errors": {"reason": ["The reason field must be at least 5 characters."]}}` |
| 429 | `evidence.too_many_requests` | `{"ok": false, "code": "too_many_requests", "message": "Demasiadas consultas seguidas a la evidencia del canario. Espera un minuto.", "retry_after": 60}` |
| 409 | `legacy.already_legacy` | `{"ok": false, "code": "already_legacy", "message": "La cadena ya está en Legacy: no declara ninguna versión."}` |
| 409 | `publish.publication_head_invalid` | `{"ok": false, "code": "publication_head_invalid", "message": "La última entrada de la cadena de publicaciones no pasa la verificación (huella o firma): no se compone nada encima. Vuelve a Legacy para empezar una cadena limpia y avisa a quien administra el sistema."}` |
| 409 | `publication_moved` *(factory)* | `{"ok": false, "code": "publication_moved", "message": "La cadena de publicaciones cambió mientras tanto (otra persona publicó, promovió o revirtió). Vuelve a cargar y repite si sigue teniendo sentido."}` |
| 409 | `restore_integrity_mismatch` *(factory)* | `{"ok": false, "code": "restore_integrity_mismatch", "message": "La versión anterior que volvería no pasa la verificación (huella o firma alterada): no se restaura. La salida segura es volver a Legacy, y avisa a quien administra el sistema."}` |

---

## 4. Cambios por fichero (y por qué)

Rutas relativas a `backend/`. «Nuevo» = sin seguimiento todavía.

### 4.1 Producción, nuevos

| Fichero | Qué y por qué |
|---|---|
| `app/Services/UltronStudio/StudioPublications.php` (1566 l.) | El servicio de la cadena. Las cuatro escrituras de A5 en su orden. La vista previa (`previewFrom`), la evidencia (`evidenceDto`), el historial (`page`, `forVersion`) y el estado (`state`, `engineBlock`). Las puertas PURAS (`publishRefusal`, `promoteStateRefusal`, `promoteEvidenceRefusal`, `rollbackStateRefusal`), estáticas para poder probarlas contra el fixture sin base. La verificación de una entrada (contrato, huella, firma del motor y decodificación estricta atada a `uuid`/`seq`), la inserción firmada (`UltronPublishedConfig::signatureFor`) y el texto firmado leído acotado a `MAX_EFFECTIVE_JSON + 1` como lo lee el motor. El reintento por `clientRequestId`. El 23505 resuelto fuera de la transacción. El libro en la misma transacción (A8). Llama al motor solo con `signatureFor()` (vía `StudioPublicationDeclaration`) y con `describe()` (vía `StudioEngineReadings`). |
| `app/Services/UltronStudio/StudioCanaryMeasures.php` | La parte con base de la evidencia (A6): una consulta para turnos, reparto, sellados y recuperaciones (`metadata` es `jsonb` en PG: se lee por clave con la gramática de la conexión, `metadata->studio->state`, nunca comparando texto); los frenos; y el acta con `Artisan::call` en el proceso, de la que solo salen recuentos. No guarda, registra ni devuelve texto. |
| `app/Services/UltronStudio/StudioPublicationExport.php` | Las formas de `backend-publication.testing.ts` para el CRM. Fuera del comando porque `UltronStudioLabNoEngineEffectsTest` prohíbe nombrar el veredicto del laboratorio en los comandos del Studio. |
| `app/Models/UltronStudio/StudioPublication.php` | Modelo de solo inserción: `updating` y `deleting` lanzan, igual que los disparadores de la base. |
| `app/Console/Commands/UltronStudio/PublicationCheckCommand.php` | `ultron-studio:publication-check {--json}`: sale 1 con deriva, cabeza que no verifica o lectura fallida; 0 si cuadra. Sin ids ni textos (probado). |
| `app/Http/Controllers/Crm/UltronStudio/UltronStudioPublicationController.php` | `GET publications` (20 por defecto, tope 50) y `POST publications/legacy`. |

### 4.2 Producción, modificados

| Fichero | Qué y por qué |
|---|---|
| `app/Services/UltronStudio/StudioPublishing.php` | **`ENGINE_READS_VERSIONS = true`.** Las llaves: la del Studio, y la del motor normalizada con el MISMO criterio que el lector (probado con 12 valores contra `describe()['env']`). La puerta del laboratorio, los umbrales (topes duros 1–168 h y 1–100 turnos, también al leer), la ventana en Bogotá `[inicio, fin)` por minutos, `status()` y `actions()` para publicar, promover y revertir. |
| `app/Services/UltronStudio/StudioVersionStatus.php` | Todos alcanzables salvo VALIDATING; RETIRED = «Sustituida»; `ACTIVE_PUBLICATION = [CANARY, PUBLISHED]`. |
| `app/Services/UltronStudio/StudioVersions.php` | Fuera `refuse()`, el 409 provisional de F, que ahora es el flujo real. `findings()` pasa a pública y estática; el detalle gana `publications[]`; `actions` sale de `StudioPublishing::actions()`; `compare` añade `integrityOk` por lado (A10.1). |
| `app/Services/UltronStudio/StudioEngineReadings.php` | Extra `governance.studioPublication` (`describe()`), `MUST_MATCH`, y `publication()`, que propaga el fallo para que quien lo use falle cerrado. |
| `app/Services/UltronStudio/StudioRules.php`, `app/Http/Controllers/Crm/UltronStudio/UltronStudioRuleController.php` | Las archivadas, paginadas (A10.2). Las vivas no cambian. |
| `app/Services/UltronStudio/StudioAudit.php` | Los eventos de A8 y `SET TRANSACTION ISOLATION LEVEL READ COMMITTED` antes del cerrojo de `recordDenied` (A10.6, solo PG). |
| `app/Services/UltronStudio/StudioLabVerdicts.php` | `latestFor()`: el veredicto más reciente sobre esta huella y la versión de prompts vigente, o `stale_prompts`, o null. Está aquí por la frontera de `UltronStudioLabNoEngineEffectsTest`. |
| `app/Exceptions/UltronStudioException.php` | Las fábricas de los códigos de A7 con sus cargas, más `publication_head_invalid` (D-1). |
| `app/Http/Controllers/Crm/UltronStudio/UltronStudioVersionController.php` | `publish` y `rollback` reales, y `promote`, `preview` y `evidence` nuevos; validación de forma; 201 `{ok, data}` o 200 `{ok, replayed: true, data}`. |
| `app/Http/Controllers/Crm/UltronStudio/UltronStudioController.php` | `status.engine` desde `StudioPublications::engineBlock()`. |
| `routes/ultron-studio.php` | Cinco rutas más (38), y `canary-evidence` con su limitador. |
| `app/Support/Access/AuthorizationMap.php` | Cinco entradas (§1). |
| `app/Support/Access/PermissionCatalog.php` | Solo textos de ayuda. En `ultron.publish` va la frase obligatoria de A11 tal cual; en `ultron.rollback`, su variante (D-8). Ninguna clave nueva. |
| `app/Providers/AppServiceProvider.php` | Limitador `ultron-studio-evidence` dentro de `limitadoresDelStudio()`: 10/min por persona y 30/min por red, con su mensaje. Nada más en ese fichero (su Pint ajeno queda como estaba). |
| `config/ultron_studio.php`, `.env.example` | Las variables de A13, con su defecto, su vacío y su valor inválido (probado contra el fichero real evaluado con cada entorno). |
| `app/Console/Commands/UltronStudio/ExportStudioFixturesCommand.php` | Sexto fichero exportado: `backend-publication.testing.ts`. |

### 4.3 m-3, en su commit aparte (A10.4)

`config/queue.php` (carril `lab`: `unattended_after_seconds` desde `QUEUE_STUDIO_LAB_UNATTENDED_AFTER_SECONDS`, 60–3600; vacío o no entero → 300), `app/Services/Observability/QueueHealthService.php` (umbral por carril; sin la clave, el global de 120 s), `tests/Feature/UltronStudio/UltronStudioLabLaneTest.php` (forma exacta del carril, `lab` 300 frente a WhatsApp 120 con trabajos de 200 y 301 s, acotado desde el entorno) y **solo el segundo bloque** de `.env.example` (las dos líneas tras `QUEUE_STUDIO_LAB_RETRY_AFTER=300`). Receta en §8.

### 4.4 Pruebas

| Fichero | Qué prueba |
|---|---|
| `tests/Feature/UltronStudio/PublicationFixtures.php` (nuevo) | Trait: canario, llaves abiertas, persona con permisos exactos, versiones aprobadas o sembradas, publicar, promover, revertir y Legacy como el CRM, turnos sellados, veredictos, entradas sembradas firmadas, el sello REAL que calcula el motor (`engineStamp`). |
| `UltronStudioPublicationLifecycleTest.php` (nuevo) | Publicar con el lector REAL del motor (`applied`), la puerta del laboratorio con veredictos reales, promover y sustituir (RETIRED), idempotencia, libro en la misma transacción, carreras (`publication_moved`, `canary_in_progress`), cabeza rota y salida por Legacy, vista previa (forma, estabilidad, motivos), historial y su tope, y **los 58 casos de publicar y promover del fixture por HTTP**. |
| `UltronStudioPublicationRollbackTest.php` (nuevo) | Revertir el canario y la publicada (con restauración), solo-publicada → Legacy, Q-2, Legacy, sin llaves, cuatro ojos ni ventana, `restore_integrity_mismatch` (versión con huella mala y entrada anterior con firma mala), idempotencia y motivo, y los 13 casos de revertir y Legacy del fixture. |
| `UltronStudioPublicationCanaryTest.php` (nuevo) | La evidencia en la base: una sola consulta, qué cuenta y qué no, reparto, sellados de esta versión, recuperaciones, umbrales y sus topes, huella estable en el tiempo, frenos, acta con un hallazgo REAL (un correo en un saliente) sin texto ni id en la respuesta ni en el log, sin canario configurado, Q-5 con lectura caída, cabeza alterada, `evidence_changed`, solo de CANARY, y el limitador por persona. |
| `UltronStudioPublicationAccessTest.php` (nuevo) | Cuatro ojos por identidad y su rastro, cada llave abre solo lo suyo, Administrador, token y roles sin Studio en las cinco nuevas, lo que ve quien mira, y que un uuid inventado no escribe en el libro. |
| `UltronStudioPublicationConfigTest.php` (nuevo) | La llave del motor leída como la lee el lector, constantes compartidas, cada variable de A13 (defecto, vacía, inválida), `.env.example`, y el comando de comprobación (exit 0/1). |
| `tests/Unit/UltronStudio/StudioPublicationGatesParityTest.php` (nuevo) | Los 71 casos de `gates` contra las puertas puras: código, estado y carga; todo caso cae en una puerta pura o en una de HTTP, ninguno se queda sin probar (aquí viven `engine_has_no_canary` y los otros dos casos que HTTP no puede construir, D-10). |
| `UltronEngineIsolationTest.php` | 38 rutas; «el motor no lee versiones» pasa a «el motor lee solo con su llave y publicar está apagado por defecto». |
| `UltronStudioEngineReadOnlyTest.php` | `ENGINE_READERS` admite los ficheros del Studio que llaman a `signatureFor()`/`describe()`; recorre las 38; publicar, promover, revertir y Legacy escriben solo en `ultron_studio_*`; vista previa y evidencia no escriben nada. |
| `UltronStudioPermissionsTest.php` | La matriz de las 38 rutas, con cuerpos válidos y textos de ayuda. |
| `UltronStudioAuditTest.php` | NUEVA en PG: `recordDenied` toma su cerrojo en READ COMMITTED aunque la sesión vaya en REPEATABLE READ (patrón de `UltronStudioLabBudgetLockTest`). |
| `UltronStudioCompareTest.php`, `UltronStudioEngineReadingsTest.php`, `UltronStudioStatusCatalogTest.php`, `UltronStudioVersionLifecycleTest.php`, `UltronStudioHardeningTest.php`, `UltronStudioRulesTest.php`, `UltronStudioRuleConditionsTest.php`, `UltronStudioLabNoEngineEffectsTest.php`, `tests/Unit/UltronStudio/ExportStudioFixturesCommandTest.php` | Se adaptan a lo que cambia el contrato: `integrityOk` por lado (+1 prueba), el extra y su fallo, los estados alcanzables, publicar con cuerpo, `promote` en `actions`, archivadas en `data.data` y su tope (pedir 1000 da 50), los ficheros nuevos dentro de la frontera y el sexto export. |

---

## 5. Desviaciones y decisiones (con motivo)

**Para el lead (cambian algo que el contrato fija o deja abierto):**

- **D-1. Código nuevo 409 `publication_head_invalid`.** El contrato no da código para «la cabeza no verifica» al componer encima (publicar, promover y revertir). Ninguno de los existentes dice la verdad: `publication_moved` invita a recargar y reintentar, y eso nunca arreglaría una cabeza rota; `integrity_mismatch` habla de la versión. La salida es Legacy (probado) y el mensaje lo dice. **Efecto en el CRM:** no está en su lista cerrada de códigos (`studio-api.types.ts`), así que llega con `code: null` y se enseña el mensaje del servidor; no rompe nada (comprobado con su lector). Si se acepta, el front lo añade a `CODIGOS_DEL_SERVIDOR` y a `StudioErrorCode`. Si no, la alternativa es reutilizar `publication_moved` con este mensaje.
- **D-2. `acknowledged` guarda `{noEffect, previewHash}`.** A3 dice `{noEffect:[…]}`. Sin `previewHash` el reintento no puede comparar el cuerpo sin guardar la petición. `noEffect` es el conjunto EXIGIDO (el que se reconoció exacto).
- **D-3. `lab_gate` = `{"mode": null}` en promover, revertir y Legacy.** La columna es NOT NULL y la puerta del laboratorio solo existe al publicar. En el DTO sale `labGate: null` (el CRM lo exige solo en `publish`).
- **D-4. La nota de publicar y promover se guarda en `reason`.** A3 no tiene columna `note`; `reason` (≤ 500) es obligatoria en revertir y Legacy y opcional aquí. El DTO la devuelve en `reason`.
- **D-5. `governance.studioPublication` no entra en el catálogo de lecturas.** Añadirlo obliga a subir `StudioCatalog::VERSION`, y el fixture fija `2026-10-19.1`. Sale como extra de `engine/readings` (A4.9), que el CRM ya lee (comprobado).

**Decisiones de implementación, dentro del contrato:**

- **D-6. Rechazos auditados.** `version.{publish|promote|rollback}_refused {code}` solo para los 409 de llave (`publishing_disabled`, `engine_not_reading_versions`), `scope_not_allowed` y `restore_integrity_mismatch`, deduplicados, y solo con la versión existente. El 403 `forbidden` no se audita, como en F, y el de cuatro ojos va como `version.four_eyes_denied`. Las puertas que dependen de hechos (ventana, freno, evidencia…) no se auditan: llenarían el libro, que no se puede vaciar, cada vez que alguien pulsa fuera de hora.
- **D-7. `blocking` de la vista previa incluye `forbidden` y `four_eyes_required`.** Dependen de quien mira, y `requirements.fourEyes` también; `previewHash` no (Q-4, probado). También incluye las llaves (`publishing_disabled`, `engine_not_reading_versions`). El reconocimiento y la propia huella no son requisitos: dependen de lo que se envíe.
- **D-8. Ayuda de `ultron.rollback`.** La frase obligatoria de A11 es la de publicar y va tal cual en `ultron.publish`. En revertir va «…En este hito no cambia lo que ULTRON dice ni hace. No depende de las llaves que permiten publicar.»
- **D-9. Evidencia de una versión que no está en CANARY → 409 `invalid_transition`** (`allowedFrom: ["CANARY"]`), con el mismo cuerpo que las transiciones. Con el canario sin configurar: cero turnos, acta `{1, null, null}` y deriva en `governance.canaryOnly`.
- **D-10. `engine_has_no_canary` no se alcanza por HTTP con el catálogo actual.** `canaryOnly` es `must_match` y su lectura es «canario configurado», así que sin canario falla antes `engine_value_mismatch`; y una versión con `canaryOnly: false` no pasa la validación. La puerta existe, y la prueban la paridad unitaria y la mutación M13. Por eso el arnés HTTP adapta tres casos del fixture, con el motivo escrito en la prueba (`httpExpectation()`).
- **D-11. `unstamped` se calcula restando** (turnos − estados reconocidos): un sello con un estado que no se reconoce cuenta como sin sello y la evidencia falla cerrada. Probado.
- **D-12. Frenos con `engaged_at >= entrada`** (incluido el mismo segundo): falla cerrado.
- **D-13. 422 antes que las llaves.** La forma del cuerpo se valida en el controlador, antes de entrar en el servicio. El fixture solo trae cuerpos bien formados, así que no cambia ningún caso.
- **D-14. `status.publishing.available` solo mira las llaves.** Con una cabeza rota sigue `true`; quien lo dice es `engine.publication` (`state: fallback`, `head.signatureOk: false`), y publicar responde 409 `publication_head_invalid`.
- **D-15. Si `describe()` falla, `status.engine` sale sin `publication`.** Se registra solo la clase de la excepción. El CRM ya tolera la ausencia (`publicationUnreadable`, A11).
- **D-16. El limitador de la evidencia también corta por red** (30/min), además de los 10/min por persona del contrato, como el de lanzar del laboratorio.
- **D-17. Archivadas.** Se pagina en cuanto `status` incluye `archived`, aunque vaya con otros estados; sin `archived`, la lista de siempre.
- **D-18. Ventana.** La etiqueta lleva raya (`07:00–19:00`, como el ejemplo de A7) y la variable de entorno un guion (`07:00-19:00`). Mal escrita o con inicio ≥ fin → `07:00-19:00`; vacía → sin ventana (A13).

---

## 6. Verificación (comando y resultado)

Todo desde `backend/` del worktree. Las salidas completas están en el directorio temporal de la sesión (se purga a los 3 días); lo relevante va aquí.

**6.1** `php artisan auth:coverage --unmapped` → sin salida, exit 0.

**6.2** `php artisan route:list --path=admin/ultron-studio --json | python3 -c '…len…'` → `38 rutas`. La tabla de §1 sale de `AuthorizationMap::resolve()` sobre el router real: 38 filas.

**6.3 SQLite** `php -d memory_limit=2G vendor/bin/phpunit --display-skipped tests/Feature/UltronStudio tests/Unit/UltronStudio tests/Feature/Access tests/Feature/Chaos`
```
OK, but some tests were skipped!
Tests: 1654, Assertions: 20038, Skipped: 18.        (46 s)
```
Las 18 omitidas son todas de solo-PostgreSQL: cerrojos consultivos, niveles de aislamiento, CHECK, TRUNCATE (incluida la mía de `recordDenied`).

**6.4 PostgreSQL 14.24** (`127.0.0.1:55432`, base nueva `ultron_studio_h5_api`) `UNIT_DB=ultron_studio_h5_api docs/ultron-studio/ops/pgunit.sh --display-skipped tests/Feature/UltronStudio tests/Unit/UltronStudio`
```
OK (1403 tests, 19163 assertions)                   (59 s)
```
La primera pasada en PG dio **1 fallo, en una prueba mía**: afirmaba `previous_publication_id` con valores absolutos (1, 2, 3), y en PG la identidad no retrocede con el rollback de cada prueba (salieron 23, 24, 25). Se corrigió la prueba para afirmar la relación (cada entrada apunta a la anterior). Es justo lo que esta puerta existe para cazar.

**6.5 Marketing** `php -d memory_limit=2G vendor/bin/phpunit tests/Feature/Marketing tests/Unit/Marketing` → `OK (4977 tests, 21689 assertions)` (1 min 52 s). Sin otra suite de PHPUnit en paralelo en el árbol (memoria «Suites de Marketing en paralelo»).

**6.6 Extra.** Las 6 pruebas que nombran la salud de las colas (`ChaosLaneIsolationTest`, `ChaosQueueHealthTest`, `UltronEventBridgeTest`, `UltronStudioEngineReadOnlyTest`, `UltronStudioLabLaneTest`, `StudioLabIsolationTest`) → `OK (60 tests, 1426 assertions)`; `tests/Feature/IronGuard tests/Feature/Observability` → `OK (19 tests, 38 assertions)`.

**6.7** `php artisan permissions:export --path=<tmp>` → `96 permisos exportados`; `cmp` con `Iron-Body_Front/src/app/models/backend-permission-catalog.ts` → idéntico (sha256 `aeb9699c1b90a709831349355df35d13ad2a840982c5c2be9917b08f86d6c83e` los dos). Es lo esperado: el `.ts` lleva claves y no textos de ayuda, y no hay claves nuevas.

**6.8** `php artisan ultron-studio:export-fixtures --path=docs/ultron-studio/plans/informes-h5/export` → «Catálogo 2026-10-19.1, 51 casos de paridad, 123 de coherencia, 11 escenarios del laboratorio (2026-10-20.1) y las formas de la publicación exportados…». Los 4 que ya existían son byte a byte los del CRM (`src/app/modules/ultron-studio/testing/`). `backend-publication.testing.ts` (sha256 `888be271…`) es nuevo: **el front tiene que copiarlo**.

**6.9** `git diff origin/main --stat -- app/Services/Marketing app/Jobs app/Http/Controllers/Api/Internal routes/marketing.php routes/api.php config/marketing.php` (`origin/main` = `0366009`):
```
 .../Marketing/Ultron/UltronCommitService.php       |  58 ++-
 .../Marketing/Ultron/UltronPublishedConfig.php     | 501 +++++++++++++++++++++
 backend/config/marketing.php                       |  18 +
 3 files changed, 568 insertions(+), 9 deletions(-)
```
Son los tres del bloque 1, ya confirmados; `git status --porcelain` en esas rutas sale vacío (no he tocado el motor).

**6.10** `vendor/bin/pint --test` sobre los 45 PHP tocados o nuevos (todos menos `AppServiceProvider.php`) → `passed`; `php -l` → 45 sin errores. `AppServiceProvider.php`: `git show HEAD:…` también falla con los mismos cuatro fixers, así que es anterior a mí.

**6.11 Mutaciones.** Un arnés temporal aplica cada mutación (cadena exacta, una sola aparición), pasa `php -l`, ejecuta sus pruebas, restaura en `finally` y comprueba sha256. Además se tomó la huella del árbol (`git diff --binary` y sha256 de los ficheros sin seguimiento) antes y después: **idéntica**.

| # | Mutación | Resultado |
|---|---|---|
| M1 | Firmar con sha256 en vez de `signatureFor` | cae: `…firma_la_entrada_y_el_lector_del_motor_la_aplica` (el lector real la sella `fallback`) |
| M2 | Sin cuatro ojos | cae: `…los_cuatro_ojos_por_identidad…` |
| M3 | Reconocimiento exacto: dar por reconocido el conjunto bueno | cae: 2 casos del fixture por HTTP |
| M4 | No comprobar la firma de la cabeza antes de componer | cae: `…una_cabeza_que_no_verifica…` |
| M5 | Releer la cabeza DENTRO de la transacción abortada tras el 23505 | **sobrevive en SQLite y cae en PG** (25P02): la puerta de PG es la que lo ve |
| M6a | Tope del historial 50 → 500 | cae (prueba nueva del tope) |
| M6b | Tope de archivadas 50 → 500 | cae (aserción nueva: pedir 1000 da 50; la prueba que había pedía 50 justos y no lo habría visto) |
| M7 | Sin `READ COMMITTED` en `recordDenied` | cae en PG (`…fija_read_committed…`) |
| M8a | Ventana siempre abierta | cae: 7 casos de paridad y 7 por HTTP |
| M8b | Ventana `[inicio, fin]` | cae: 3 y 3 (19:00, 17:15) |
| M9 | `stamped` cuenta los applied de cualquier versión | cae en SQLite y en PG |
| M10 | Limitador 10 → 11 | cae |
| M11 | El reintento no compara el cuerpo | cae |
| M12 | `promote` con la llave de ver | cae en `UltronStudioPermissionsTest`; la prueba del Administrador **sobrevive**, porque el servicio vuelve a exigir `ultron.publish` (defensa doble, no un hueco) |
| M13 | Quitar `engine_has_no_canary` | cae en la paridad unitaria; por HTTP sobrevive, como se espera (D-10) |

**6.12 Lectores del CRM** sobre las 62 respuestas reales: 62 OK (§3).

**6.13 No verificado**:
- La pila real: el E2E del tester (A10.5: webhook firmado, simulador de n8n, workers) no lo he ejecutado. La cadena se probó con el lector REAL del motor en proceso (`UltronPublishedConfig::forConversation()`), y los turnos del canario son filas sembradas en `marketing_ai_actions` con el sello que deja el commit.
- Carreras con dos sesiones reales en PG (20 publicaciones simultáneas, promover contra revertir…; fila «PostgreSQL» de A12, del tester). Las mías inyectan la escritura rival en la misma conexión (`beforeStatement`), dentro de un SAVEPOINT; eso basta para M5, pero no sustituye a las de A12.
- La suite completa del backend fuera de las pedidas, y la línea base contra `origin/main` del mismo día (del tester; memoria «La suite depende del calendario»).
- La revisión de seguridad de A12 (`api-security-specialist` y `security`), prevista tras este paso.

---

## 7. Para tester, seguridad y CRM

**Tester. Casos límite que conozco desde dentro:**
- Doble clic en publicar: el segundo es 200 `replayed` aunque la versión ya esté en CANARY (el reintento va antes que el estado). El mismo `clientRequestId` con otra nota, otra persona u otra acción → `client_request_reused`.
- 23505 dentro de un SAVEPOINT (RefreshDatabase) frente a una transacción de verdad: con dos sesiones, la rival aborta la transacción entera, y la resolución está fuera (como en `StudioVersions::create`). Merece la carrera real de A12.
- La carrera del estado: si otra versión entra en CANARY entre las puertas y la transición, el índice `usv_one_canary` para la segunda → `canary_in_progress` (en SQLite el mensaje dice `ultron_studio_versions.status`; se distingue por la acción).
- Fronteras: la ventana a las 07:00 entra y a las 19:00 no; 06:59:59 está fuera; `hours` es entero hacia abajo (24 h 50 min → 24); los frenos del mismo segundo que la entrada cuentan.
- La evidencia es CARA (acta incluida): el limitador corta a la 11.ª por minuto y persona, también con otra sesión de la misma persona.
- Revertir la PUBLISHED restaura la RETIRED solo si la entrada anterior a su promoción verifica entera y la versión casa con la parte. Si no, `restore_integrity_mismatch`, y Legacy siempre funciona, también sobre una cabeza rota.

**Seguridad** (A12; marcado para `api-security-specialist` y `security`):
- Autorización por ruta y cuatro ojos por identidad (`isCreator`, no por rol); el Administrador no publica ni revierte por defecto.
- `clientRequestId`: único global, ligado a persona, acción, versión y cuerpo.
- Fugas: ninguna respuesta lleva el id de la conversación canaria, el texto firmado ni la firma (probado sobre 8 respuestas, incluida la de promover). El acta: su texto vive en memoria dentro de `Artisan::call` y se descarta; al log solo va la clase de la excepción.
- La firma usa la subclave del motor derivada de `APP_KEY`: rotar `APP_KEY` deja la cabeza en `fallback/signature_invalid` (el motor no cambia de comportamiento en A) y el Studio en `publication_head_invalid`. La salida es Legacy. Hay que escribirlo en el runbook antes de cualquier rotación.
- Entrada no confiable: `acknowledgedNoEffect` acotada en número, longitud y alfabeto; `note` y `reason` en texto plano; el tope de 64 KB del grupo.

**CRM (front):**
- Copiar `docs/ultron-studio/plans/informes-h5/export/backend-publication.testing.ts` a `src/app/modules/ultron-studio/testing/`.
- Decidir con el lead D-1 (`publication_head_invalid`): hoy llega con `code: null` y el mensaje del servidor.
- Todo lo de §3 lo leen ya sus lectores estrictos, sin cambios.

---

## 8. Estado del árbol y cómo confirmar

- **Sin commit ni push.** Están los ficheros de §4 y el directorio `docs/ultron-studio/plans/informes-h5/export/` (5 `.ts`), más este informe.
- **No son míos** y no los he tocado: `docs/ultron-studio/plans/informes-h5/capturas-crm/` y `chrome-resultados.json` (sin seguimiento, de las 01:49–01:57, de la pasada del CRM con Chrome).
- **m-3 aparte.** El parche se dejó en el directorio temporal de la sesión (`…/scratchpad/m3/m3-cola-lab.patch`, sha256 `98d5b916…`; `git apply --cached --check` pasa sobre el índice actual, que está vacío). Si ya no está, se rehace así: `git diff -- config/queue.php app/Services/Observability/QueueHealthService.php tests/Feature/UltronStudio/UltronStudioLabLaneTest.php`, más el bloque de `.env.example` que añade, tras `QUEUE_STUDIO_LAB_RETRY_AFTER=300`, el comentario y `QUEUE_STUDIO_LAB_UNATTENDED_AFTER_SECONDS=300`. Orden sugerido: `git apply --cached m3-cola-lab.patch` y commit de m-3; después `git add` del resto y commit de B-S. El segundo commit lleva el bloque A13 de `.env.example`, que es el que queda sin preparar.

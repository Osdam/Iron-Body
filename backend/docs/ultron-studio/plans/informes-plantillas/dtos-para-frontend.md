# Plantillas de WhatsApp de Meta desde el CRM · contrato de la API para el CRM

> **Autor:** `backend` (be-tpl), 2026-11-11. **Para:** `crm-fe` (pestaña «Plantillas» de Campañas automáticas).
> **Base:** plan `plans/PLANTILLAS_META_PLAN.md` §5.8 y la fila L9-96 del contrato H9. Rama `feat/ultron-studio-h9`, sin commit.
> **Los ejemplos JSON son respuestas REALES** del servidor (volcadas por una prueba con el doble de Meta, driver `fake`), recortadas solo donde se indica con `…`. Las fechas y los uuid son de esa ejecución.

---

## 0. Lo esencial

- **4 rutas nuevas** bajo `/api/admin/ultron-studio/` (de 121 a 125). **Los DTO de las rutas que ya existían crecen, sin cambiar de tipo**: la lista y el detalle de las plantillas ganan `meta`, y el catálogo gana `templates.meta`.
- **El CRM nunca calcula** «lista para campañas» ni si un botón se puede pulsar: lo dicen `meta.campaignReady` y `meta.actions.*` del servidor. Con `allowed: false` llega el `code` para el candado y el motivo.
- **El CRM nunca manda el contenido a Meta**: enviar es `POST …/{uuid}/meta/submit` con `{clientRequestId}`; el servidor reconstruye la plantilla de la versión inmutable aprobada.
- **Un fallo de Meta no es un error HTTP**: el envío responde 201 con `meta.state` `FAILED` (y `meta.submission.error`) o `UNKNOWN`. Los 4xx son solo de nuestras reglas.
- Llave nueva: **`ultron.meta_templates_submit`** (enviar y «Comprobar ahora»), solo Super Admin por defecto. Ya está en el catálogo generado del CRM (`src/app/models/backend-permission-catalog.ts`, +2 líneas, regenerado con `php artisan permissions:export`).

| # | Ruta | Llave | Cupos | Cuerpo | Respuestas |
|---|---|---|---|---|---|
| 1 | `GET strategies/templates/meta/status` | `ultron.view` | lectura | — | 200 (también con el módulo apagado) |
| 2 | `POST strategies/templates/meta/probe` | `ultron.integrations_manage` | escritura + `ultron-studio-probe` (6/h por persona) | vacío | 201 nueva · 200 `reused` (enfriamiento de 300 s) |
| 3 | `POST strategies/templates/{uuid}/meta/submit` | `ultron.meta_templates_submit` | escritura + `ultron-studio-meta` (20/h por persona, 60/h por red) | `{clientRequestId: uuid}` | 201 · 200 `replayed` |
| 4 | `POST strategies/templates/{uuid}/meta/refresh` | `ultron.meta_templates_submit` | escritura + `ultron-studio-meta` | vacío | 200 con `check` |

**Cerraduras, en este orden** (lo que el CRM puede recibir): sin sesión 401 · token compartido 403 · sin la llave 403 `forbidden` · sin persona 403 `person_required` · estrategias apagadas **409 `strategies_disabled` en las 4** · módulo apagado **409 `meta_templates_disabled` en 2, 3 y 4** (`status` responde 200 con `enabled: false`) · persona sin vínculo verificado 403 `person_binding_required` (2, 3 y 4) · el resto, §4.

---

## 1. `GET strategies/templates/meta/status`

```json
{
  "ok": true,
  "data": {
    "enabled": true,
    "submitEnabled": true,
    "syncEnabled": true,
    "metaEnabled": true,
    "driver": "fake",
    "graphVersion": "v25.0",
    "configured": true,
    "configurationProblem": null,
    "credentialSource": "env",
    "lastProbe": {
      "uuid": "01207b59-c7e2-4bd6-a093-d0f3ba6a7e7b",
      "at": "2026-11-11T06:18:26Z",
      "outcome": "ok",
      "scopes": { "whatsapp_business_management": true },
      "templatesReadable": true,
      "templatesError": null,
      "driver": "fake",
      "graphVersion": "v25.0",
      "by": { "name": "Ana Autora", "role": "Super Admin" },
      "writeVerified": false
    },
    "lastSyncAt": "2026-11-11T06:18:26Z",
    "lastSync": { "at": "2026-11-11T06:18:26Z", "outcome": "ok", "watched": 1, "requests": 1, "error": null, "changed": 1, "rowsWritten": 1 },
    "counts": { "PAUSED": 1 },
    "submissions": { "SENDING": 0, "SUBMITTED": 1, "FAILED": 1, "UNKNOWN": 0 },
    "can": { "submit": true, "refresh": true, "probe": true }
  }
}
```

- `driver`: `fake` (modo de prueba, sin red) o `graph`. En producción siempre `graph`.
- `configurationProblem`: `null` o `missing_waba` · `missing_token` · `malformed_token` · `no_simulator` (fuera de producción, `graph` sin simulador).
- `credentialSource`: `database` (la conexión hecha desde el CRM) o `env`.
- `lastProbe`: `null` si no hay sonda en las últimas 24 h. `lastSync`/`lastSyncAt`: `null` si no hay pasada en 24 h. `lastSync.outcome`: `ok` · `partial` · `meta_failed` (las demás salidas de la orden no se guardan).
- `counts`: estados en Meta de los envíos `SUBMITTED`. **Sin envíos es `{}` (un objeto)**.
- **Ni el token ni la cuenta de WhatsApp viajan nunca.**
- La franja «Conexión con Meta» (§5.13 del plan): *Apagada en el servidor* = `!enabled` · *Modo de prueba (sin red)* = `driver === 'fake'` · *Conectada (Graph vX, credencial del CRM o del servidor)* = `driver === 'graph' && configured` · *Sin permiso de plantillas* = `lastProbe.outcome` ∈ {`missing_permission`, `no_waba_access`} · *Sin comprobar* = `lastProbe === null`.

## 2. `POST strategies/templates/meta/probe` (sin cuerpo)

201 (nueva) — la de 200 es idéntica con `"reused": true` y la misma `probe` (dentro de los 300 s no sale a la red):

```json
{
  "ok": true,
  "data": {
    "reused": false,
    "probe": {
      "uuid": "01207b59-c7e2-4bd6-a093-d0f3ba6a7e7b",
      "at": "2026-11-11T06:18:26Z",
      "outcome": "ok",
      "scopes": { "whatsapp_business_management": true },
      "templatesReadable": true,
      "templatesError": null,
      "driver": "fake",
      "graphVersion": "v25.0",
      "by": { "name": "Ana Autora", "role": "Super Admin" },
      "writeVerified": false
    }
  }
}
```

- `outcome`: `ok` · `missing_permission` · `no_waba_access` · `auth_failed` · `unreachable` · `not_configured` · `error`.
- `scopes.whatsapp_business_management`: `true` / `false` / `null` (Meta no dejó leer los permisos: manda `templatesReadable`).
- `templatesError`: `null` o `permission` · `auth` · `not_found` · `unreachable` · `not_configured` · `error`.
- `writeVerified` es siempre `false`: **la sonda no prueba la escritura**; decirlo en pantalla («crear plantillas solo se comprueba con el primer envío»).
- Errores: 409 `meta_templates_disabled`, 409 `meta_not_configured` (con `reason`, uno de los de `configurationProblem`), 403, 429.

## 3. `POST strategies/templates/{uuid}/meta/submit` `{clientRequestId}`

201 con **el detalle completo de la plantilla** (el mismo DTO que `GET strategies/templates/{uuid}`). Aquí, solo su bloque `meta` (`timeline` recortado):

```json
{
  "ok": true,
  "data": {
    "uuid": "a7e07fac-3259-453f-9b24-0ced53d47c64",
    "…": "el resto del detalle, igual que GET strategies/templates/{uuid}",
    "meta": {
      "status": "PENDING",
      "state": "SUBMITTED",
      "campaignReady": false,
      "campaignReasons": ["template_not_approved_by_meta"],
      "submission": {
        "uuid": "696ef374-6b57-41ff-ba93-95fdfe5ebbd5",
        "state": "SUBMITTED",
        "metaName": "pausar_renovacion_v1",
        "language": "es_CO",
        "category": "marketing",
        "metaTemplateId": "1688186126583244",
        "metaStatus": "PENDING",
        "metaCategory": "MARKETING",
        "correctCategory": null,
        "rejectedReason": null,
        "quality": null,
        "matchesStudio": null,
        "mismatch": [],
        "attesterUnavailable": false,
        "error": null,
        "submittedBy": { "name": "Ana Autora", "role": "Super Admin" },
        "submittedAt": "2026-11-11T06:18:26+00:00",
        "lastCheckedAt": "2026-11-11T06:18:26+00:00",
        "lastChangedAt": "2026-11-11T06:18:26+00:00"
      },
      "attempts": 1,
      "latestCheck": null,
      "timeline": ["…"],
      "actions": { "submit": { "allowed": false, "code": "already_submitted" }, "refresh": { "allowed": true } }
    }
  }
}
```

- La **misma petición repetida** (mismo `clientRequestId`, misma versión, misma persona): 200 con `"replayed": true` y el mismo DTO; no vuelve a salir nada a Meta. Con otra versión u otra persona: 409 `client_request_reused`.
- **Meta rechaza la petición** (201, no un error HTTP):

```json
"meta": {
  "status": null,
  "state": "FAILED",
  "campaignReady": false,
  "campaignReasons": ["template_not_approved_by_meta"],
  "submission": {
    "uuid": "9747dbbe-1945-4f2b-af3e-f19442dbe99c",
    "state": "FAILED",
    "metaTemplateId": null,
    "metaStatus": null,
    "error": { "code": 100, "subcode": null, "message": "El doble de Meta rechaza a propósito las plantillas cuyo nombre empieza por fallar_." },
    "…": "el resto de campos, null",
    "lastCheckedAt": null,
    "lastChangedAt": null
  },
  "attempts": 1,
  "latestCheck": null,
  "actions": { "submit": { "allowed": true }, "refresh": { "allowed": false, "code": "meta_not_tracked" } }
}
```

  `error.message` es el `error_user_msg` de Meta **ya saneado** (una línea, ≤ 300; sin tokens ni números largos): se puede pintar tal cual. `FAILED` libera la versión: `actions.submit` vuelve a `allowed`.
- **Sin respuesta de Meta** (tiempo agotado): `meta.state = "UNKNOWN"`, `submission.metaTemplateId = null`. Texto del plan: «No sabemos si Meta la recibió; se comprobará sola en unos minutos. No la vuelvas a enviar.» (`actions.submit` dice `already_submitted`.)

## 4. `POST strategies/templates/{uuid}/meta/refresh` («Comprobar ahora», sin cuerpo)

200 con el detalle en `data` (como §3) y, aparte, `check`:

```json
{
  "ok": true,
  "check": { "outcome": "ok", "error": null, "changed": true, "rowWritten": true, "channelAccepts": false },
  "data": { "…": "el detalle completo de la plantilla" }
}
```

- `check.outcome`: `ok` · `not_found` (Meta ya no la tiene) · `failed` (Meta no contestó: `check.error` es la clase, p. ej. `server`, `transport`, `rate_limited`, `auth`, `permission`; no se registró nada).
- `rowWritten: true` = quedó una fila nueva en el registro que lee W9 (a nombre de quien pulsó si es positiva).
- Errores propios: 409 `meta_not_tracked` (nunca se envió ni se registró a mano), 409 `meta_check_in_progress` (se está enviando o comprobando ahora), 422 `template_not_whatsapp`, 404 `template_not_found`.

## 5. Los bloques nuevos en las rutas que ya existían

### 5.1 Lista: `GET strategies/templates` → cada fila gana `meta` (null si no es de WhatsApp)

```json
{
  "uuid": "a7e07fac-3259-453f-9b24-0ced53d47c64",
  "key": "pausar_renovacion_v1",
  "number": 1,
  "channel": "whatsapp",
  "purpose": "commercial",
  "status": "APPROVED",
  "…": "lo de siempre",
  "meta": { "status": "PAUSED", "state": "SUBMITTED", "campaignReady": false }
}
```

Otra fila real: `"meta": { "status": null, "state": "FAILED", "campaignReady": false }`. Una versión nunca enviada: `{ "status": null, "state": null, "campaignReady": false }`. Sale en tres consultas para toda la página.

### 5.2 Detalle: `GET strategies/templates/{uuid}` (y las respuestas de crear, aprobar, rechazar, enviar y comprobar)

Gana `meta` (null si no es de WhatsApp). `waPreview` (del paso 1b) ya estaba. Ejemplo real de una plantilla pausada por Meta después de «Comprobar ahora» (`timeline` recortado a 2 de 11):

```json
"meta": {
  "status": "PAUSED",
  "state": "SUBMITTED",
  "campaignReady": false,
  "campaignReasons": ["meta_status_not_approved"],
  "submission": {
    "uuid": "696ef374-6b57-41ff-ba93-95fdfe5ebbd5",
    "state": "SUBMITTED",
    "metaName": "pausar_renovacion_v1",
    "language": "es_CO",
    "category": "marketing",
    "metaTemplateId": "1688186126583244",
    "metaStatus": "PAUSED",
    "metaCategory": "MARKETING",
    "correctCategory": null,
    "rejectedReason": "NONE",
    "quality": "RED",
    "matchesStudio": true,
    "mismatch": [],
    "attesterUnavailable": false,
    "error": null,
    "submittedBy": { "name": "Ana Autora", "role": "Super Admin" },
    "submittedAt": "2026-11-11T06:18:26+00:00",
    "lastCheckedAt": "2026-11-11T06:18:26+00:00",
    "lastChangedAt": "2026-11-11T06:18:26+00:00"
  },
  "attempts": 1,
  "latestCheck": { "status": "PAUSED", "source": "meta_api", "checkedAt": "2026-11-11T06:18:26Z", "recordedBy": "Beto Revisor" },
  "timeline": [
    { "at": "2026-11-11T06:18:26+00:00", "event": "meta_template.meta_check_recorded", "summary": "Quedó registrado lo que dice Meta de la versión 1 de la plantilla «pausar_renovacion_v1»: PAUSED", "actor": "Beto Revisor" },
    { "at": "2026-11-11T06:18:26+00:00", "event": "meta_template.status_changed", "summary": "Meta cambió el estado de la versión 1 de la plantilla «pausar_renovacion_v1»: APPROVED → PAUSED", "actor": "Beto Revisor" }
  ],
  "actions": { "submit": { "allowed": false, "code": "already_submitted" }, "refresh": { "allowed": true } }
}
```

### 5.3 Tipos (TypeScript)

```ts
type Allowed = { allowed: true } | { allowed: false; code: string };

interface TemplateMetaSummary {            // fila de la lista
  status: string | null;                   // estado en Meta que se enseña
  state: 'SENDING' | 'SUBMITTED' | 'FAILED' | 'UNKNOWN' | null;   // el envío
  campaignReady: boolean;                  // lo decide el servidor (regla del puente de W9)
}

interface TemplateMetaDetail extends TemplateMetaSummary {
  campaignReasons: string[];               // [] si campaignReady
  submission: null | {
    uuid: string; state: 'SENDING' | 'SUBMITTED' | 'FAILED' | 'UNKNOWN';
    metaName: string; language: 'es' | 'es_CO'; category: 'marketing' | 'utility';
    metaTemplateId: string | null; metaStatus: string | null; metaCategory: string | null;
    correctCategory: string | null; rejectedReason: string | null;
    quality: 'GREEN' | 'YELLOW' | 'RED' | 'UNKNOWN' | null;
    matchesStudio: boolean | null; mismatch: string[]; attesterUnavailable: boolean;
    error: null | { code: number | null; subcode: number | null; message: string | null };
    submittedBy: { name: string; role: string | null }; submittedAt: string;
    lastCheckedAt: string | null; lastChangedAt: string | null;
  };
  attempts: number;                        // envíos de esta versión, fallidos incluidos
  latestCheck: null | { status: string; source: 'manual' | 'meta_api'; checkedAt: string; recordedBy: string };
  timeline: { at: string; event: string; summary: string; actor: string }[];   // del libro, del más reciente al más antiguo, ≤ 50
  actions: { submit: Allowed; refresh: Allowed };
}
```

`submission` es el envío vivo o, si no hay, el último (fallido). `status` es lo último observado del envío vivo y, si no, el de la última comprobación registrada (también las manuales).

### 5.4 Catálogo: `GET strategies/catalog` → `data.templates.meta`

Real, con `ULTRON_STUDIO_META_TEMPLATES_URL_HOSTS=ironbodyneiva.cloud` y el botón «Llamar» encendido (por defecto: `urlButtons: false`, `urlHosts: []`, `phoneButton: false`, `phoneNumber: null`):

```json
{
  "headerFormats": ["TEXT"], "headerMax": 60, "footerMax": 60, "buttonTextMax": 25, "buttonsMax": 3, "urlButtonsMax": 2,
  "urlMax": 200, "exampleMax": 40, "buttonTypes": ["PHONE_NUMBER", "QUICK_REPLY", "URL"],
  "urlButtons": true, "urlHosts": ["ironbodyneiva.cloud"], "phoneButton": true, "phoneNumber": "573143455483",
  "optOutLabels": ["Darme de baja", "No más mensajes", "Detener promociones"],
  "examples": { "class_name": "Spinning", "class_time_text": "mañana a las 6:00 p. m.", "days_to_expiry": "5", "first_name": "Laura",
    "gym_name": "Iron Body", "membership_end_date_text": "15 de diciembre", "payment_link": "el enlace de pago",
    "plan_name": "Plan Trimestral", "plan_price": "el valor de tu plan" },
  "wordsPerVariable": 4
}
```

El botón «Llamar» se crea con `{ "type": "PHONE_NUMBER", "text": "Llamar" }` (sin número: el servidor pone el del gimnasio; si llega otro, 422 `phone_not_allowed`).

---

## 6. Vocabulario cerrado

**Estados en Meta** (`status`, `metaStatus`, `latestCheck.status`): `APPROVED`, `PENDING`, `REJECTED`, `PAUSED`, `DISABLED`, `IN_APPEAL`, `PENDING_DELETION`, `DELETED`, `ARCHIVED`, `LIMIT_EXCEEDED`, y dos locales: `NOT_FOUND` (Meta ya no la tiene) y `UNKNOWN` (Meta dijo algo que el servidor no sabe registrar: bloquea). `metaStatus` puede traer otro valor de Meta tal cual (en `[A-Z_]`): tratarlo como desconocido.

**`campaignReasons`** (de `compare()`, el mismo criterio que el canal): `template_not_approved_by_meta` (sin comprobación) · `meta_status_not_approved` · `meta_name_mismatch` · `language_mismatch` · `category_mismatch` (p. ej. recategorizada a Marketing) · `body_mismatch` · `components_mismatch` (encabezado, pie o botones distintos: «¿se editó en el administrador de WhatsApp?») · `studio_template_not_approved` · `studio_template_hash_mismatch` · `studio_category_purpose_mismatch`.

**`submission.mismatch`**: `meta_name_mismatch`, `language_mismatch`, `category_mismatch`, `body_mismatch`, `components_mismatch`, `meta_template_not_found`, `meta_unreadable`.

**`actions.submit.code`**: `forbidden` · `meta_templates_disabled` · `meta_submit_disabled` · `meta_messaging_disabled` · `meta_not_configured` · `person_binding_required` · `already_submitted` · `template_not_whatsapp` · `template_not_approved` · `integrity_mismatch` · `four_eyes_required` · `meta_examples_required` · `meta_name_taken`.

**`actions.refresh.code`**: `forbidden` · `meta_templates_disabled` · `meta_not_configured` · `person_binding_required` · `meta_not_tracked` · `meta_check_in_progress`.

**`attesterUnavailable: true`**: Meta la aprobó, pero quien la envió ya no puede dar fe (baja, sin la llave o sin vínculo): «falta que otra persona con la llave pulse “Comprobar ahora”».

**Eventos del `timeline`** nuevos: `meta_template.submit_requested`, `.submitted`, `.submit_failed`, `.submit_unknown`, `.check_requested`, `.status_changed`, `.quality_changed`, `.category_changed`, `.mismatch_detected`, `.meta_check_recorded`, `.attester_unavailable` (y los de siempre: `message_template.created`, `.approved`, `.rejected`). La sonda deja `meta_templates.probed` (sujeto `integration`, no sale en el `timeline` de una plantilla).

## 7. Errores de las rutas 3 y 4 (cuerpo `{ok:false, code, message, …}`)

| HTTP | `code` | Cuándo | Extra |
|---|---|---|---|
| 409 | `meta_templates_disabled` | Maestra del módulo apagada | |
| 409 | `meta_submit_disabled` | Bandera de envío apagada | |
| 409 | `meta_messaging_disabled` | `META_ENABLED` apagado (solo enviar) | |
| 409 | `meta_not_configured` | Falta la cuenta o la credencial | `reason` |
| 409 | `template_not_approved` | La versión no está aprobada | `currentStatus` |
| 409 | `integrity_mismatch` | La huella no casa con el contenido | |
| 409 | `four_eyes_required` | Defensiva (la base ya lo impide) | |
| 409 | `already_submitted` | Hay un envío vivo, o se está enviando ahora | |
| 409 | `meta_name_taken` | Otra versión reclama ese nombre e idioma | `template: {uuid, key, number}` |
| 409 | `client_request_reused` | El `clientRequestId` ya se usó con otra versión o persona | |
| 409 | `meta_not_tracked` / `meta_check_in_progress` | Solo «Comprobar ahora» | |
| 422 | `meta_examples_required` | Hay variables sin ejemplos | |
| 422 | `template_not_whatsapp` | No es de WhatsApp | |
| 422 | `meta_payload_invalid` | Meta no admitiría la versión guardada (no salió nada) | |
| 422 | — (validación de Laravel: `{message, errors: {clientRequestId: [...]}}`, sin `ok` ni `code`) | Falta `clientRequestId` o no es un uuid | |
| 404 | `template_not_found` | No existe la versión | |
| 403 | `forbidden` / `person_required` / `person_binding_required` | | |
| 429 | `too_many_requests` | Cupos | `retry_after` |

Ejemplos reales: `{"ok":false,"code":"already_submitted","message":"Esta versión ya se envió a Meta: compruébala en lugar de enviarla otra vez."}` · `{"ok":false,"code":"meta_examples_required","message":"La plantilla tiene variables sin sus ejemplos (Meta los exige para revisarla): crea una versión nueva con ejemplos."}` · `{"ok":false,"code":"meta_submit_disabled","message":"Enviar plantillas a Meta está desactivado en el servidor: lo enciende quien administra el sistema."}` · `{"ok":false,"code":"meta_not_tracked","message":"Esta versión no se ha enviado a Meta ni tiene una comprobación registrada: no hay nada que comprobar."}`.

## 8. Refresco

Como dice el plan (§5.13 punto 4): releer el detalle cada 60 s mientras `meta.state` sea `SENDING` o `UNKNOWN`, o `meta.status` sea `PENDING` o `IN_APPEAL`; en pausa con la pestaña oculta y como mucho 30 min. La sincronización del servidor pasa cada 5 min (si está encendida); «Comprobar ahora» lee en el acto (y gasta del cupo de 20/h).

## 9. Lo que NO hay (para no buscarlo)

- Ningún filtro de servidor por estado en Meta en `GET strategies/templates`: el plan lo pide en la lista; hoy se filtra en el cliente sobre `meta` de la página. Si hace falta en el servidor, pedirlo.
- Ningún tema SSE de plantillas (fase 2).
- Editar o borrar en Meta (fase 2, Q6).

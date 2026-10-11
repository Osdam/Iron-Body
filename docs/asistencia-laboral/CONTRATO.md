# Asistencia laboral, biometría y dispositivos — contrato de API y eventos

Versión del contrato: **v1** (rama `feat/employee-attendance`, 2026-10-10).
Fuente de verdad: el backend. CRM y Flutter construyen contra este documento.
Si una implementación necesita apartarse, se cambia AQUÍ primero.

Todas las rutas cuelgan de `/api`. Fechas y horas en ISO 8601 **UTC**
(`2026-10-10T13:02:11+00:00`); el día del gimnasio (`business_date`,
`session_date`) es `YYYY-MM-DD` en `America/Bogota`. Los clientes presentan en
Bogotá; nunca envían la hora oficial.

## 0. Formato de error

```json
{ "ok": false, "code": "<código estable>", "message": "<texto en español para la persona>" }
```

Puede llevar campos extra documentados por endpoint (p. ej. `workday`).
Validación: el 422 estándar de Laravel `{ "message": "...", "errors": { "campo": ["..."] } }`.
Los clientes deciden por `code`, nunca por `message`.

## 1. Firma de dispositivo

Las rutas marcadas **[firmada]** exigen tres cabeceras:

| Cabecera | Valor |
|---|---|
| `X-Device-Id` | id del dispositivo devuelto al registrarlo (uuid) |
| `X-Device-Nonce` | nonce de `POST kiosk/device/challenge` (un solo uso, 120 s) |
| `X-Device-Signature` | base64 estándar (con relleno) de la firma **ECDSA P-256 / SHA-256 en DER** |

Cadena canónica firmada (UTF-8, separada por `\n`, sin salto final):

```
IB-DEVICE-SIG-V1
<MÉTODO HTTP en mayúsculas>
<ruta sin "/api/" inicial ni query; p. ej. kiosk/workday/clock-in>
<X-Device-Id>
<X-Device-Nonce>
<sha256 hex en minúsculas de los bytes EXACTOS del cuerpo; cuerpo vacío = e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855>
```

Ejemplo (cuerpo exacto `{"operation_id":"7d3c2b1a-0000-4000-8000-000000000001","verification_token":"vt_example"}`):

```
IB-DEVICE-SIG-V1
POST
kiosk/workday/clock-in
5f0e6a52-3c1e-4c5b-9a41-6a1e2a9b0c11
mJ0q3V9c2xH1tq8m5bYQ4n6ZkqLrJ1aT0c7Wf2s9XyE
281a3cc9d92af8819a9bd5dcd2cd00931655693ade8e03607c32d302f9b141ae
```

El nonce queda consumido aunque la firma falle. Un reintento pide nonce nuevo.

Errores de firma:

| HTTP | `code` | Cuándo |
|---|---|---|
| 401 | `device_signature_required` | faltan cabeceras en una ruta que las exige |
| 401 | `device_unknown` | `X-Device-Id` no registrado |
| 403 | `device_revoked` | dispositivo revocado desde el CRM |
| 401 | `device_challenge_invalid` | nonce inexistente, caducado, usado o de otro dispositivo |
| 401 | `device_signature_invalid` | la firma no verifica con la clave registrada |

Todos quedan en el registro de seguridad de dispositivos (CRM → Dispositivos → Intentos),
agrupados: como mucho uno por tipo, dispositivo e IP por minuto. Las rutas firmadas no
admiten query string (401 `device_signature_invalid`).

### 1.1 `POST kiosk/device/challenge` (sin firma)

```json
{ "device_id": "5f0e6a52-…" }
```
→ 200 `{ "ok": true, "nonce": "<43 chars base64url>", "expires_at": "…", "ttl": 120 }`.
Errores: `device_unknown` (401), `device_revoked` (403). Límite 60/min por dispositivo.

### 1.2 `POST kiosk/device/register` (sin firma; prueba de posesión dentro)

```json
{
  "pairing_code": "K7QX-4M2P",
  "public_key": "<base64 DER SubjectPublicKeyInfo, EC P-256>",
  "key_algorithm": "ES256",
  "proof": "<base64 DER ECDSA sobre 'IB-DEVICE-REGISTER-V1\n<código normalizado>\n<sha256 hex del DER de public_key>'>",
  "key_storage": "strongbox | tee | secure_enclave | software | unknown",
  "attestation": { "platform": "android", "certificate_chain": ["<base64 DER hoja>", "...", "<raíz>"] },
  "device": { "platform": "android|ios", "manufacturer": "samsung", "model": "SM-X200",
              "os_version": "14", "app_version": "2.1.0+16" }
}
```

- Código normalizado: mayúsculas, sin guiones ni espacios (`K7QX4M2P`).
- Android: la clave se genera con `setAttestationChallenge(<código normalizado en UTF-8>)`
  y se envía la cadena de certificados. iOS: `attestation: null`, `key_storage: secure_enclave`.
- `key_storage` es lo DECLARADO; lo verificado sale de la atestación.

→ 201
```json
{ "ok": true, "device": { "id": "<uuid>", "name": "Tablet Recepción 01", "location": "Sede principal",
  "status": "active", "registered_at": "…", "attestation": { "status": "verified|unverified|not_provided",
  "security_level": "strongbox|tee|software|null" } } }
```
Errores 422: `pairing_code_invalid` (uniforme: no existe, caducó o ya se usó), `public_key_invalid`,
`proof_invalid`; 409 `public_key_already_registered`. Límite 10/min por IP (cupo propio).
Reintento idempotente: el MISMO código con la MISMA clave, si ese registro ya se completó
(se perdió la respuesta), devuelve 200 con el mismo dispositivo.

### 1.3 `GET kiosk/device/status` [firmada]

→ `{ "ok": true, "device": { "id", "name", "location", "status", "last_seen_at" } }`.

## 2. Identificación en el kiosko

Una «referencia de empleado» (`ref`) es opaca, dura 10 min y solo vale en el
dispositivo que la pidió. Nunca viaja un id interno.

### 2.1 `POST kiosk/employees/identify` [firmada]

```json
{ "document": "1075234567" }
```
→ 200
```json
{ "ok": true, "employee": {
  "ref": "<opaca>",
  "display_name": "Ana M.",
  "positions": [ { "key": "receptionist", "label": "Recepción" } ],
  "capabilities": { "workday": true, "classes": false },
  "biometric": { "status": "active | not_enrolled | revoked | renewal_pending" } } }
```
- `display_name`: nombre + inicial del apellido (pantalla compartida).
- Antes de verificar NO se revela el estado de la jornada.
- 404 `employee_not_found` (uniforme: no existe, inactivo o sin cargo). Límite 20/min por dispositivo.

### 2.2 `GET kiosk/employees/roster` [firmada]

→ `{ "ok": true, "data": [ { "ref", "display_name", "positions", "capabilities", "biometric": { "status" } } ] }`
Solo empleados activos con alguna capacidad.

## 3. Verificación de identidad

### 3.1 `POST kiosk/verify` [firmada] — rostro comparado en el servidor

```json
{ "employee_ref": "…", "purpose": "workday | trainer_session", "embedding": [ 192 números ] }
```
- `embedding`: salida de MobileFaceNet, 192 valores finitos de **norma L2 ≈ 1**
  (se rechaza fuera de [0.9, 1.1]).

→ 200
```json
{ "ok": true,
  "verification": { "token": "<opaco>", "expires_at": "…", "method": "face" },
  "employee": { "display_name": "Ana Martínez", "positions": [ … ], "capabilities": { … } },
  "workday": { "state": "none | open", "current": <Workday|null>, "last": <Workday|null>,
               "pending_incident": <Workday|null> },
  "allowed_actions": [ "clock_in" ] }
```
`pending_incident`: una jornada que quedó abierta más de 16 h (salida olvidada). La
persona puede entrar de nuevo; al hacerlo esa jornada pasa a incidencia.
`allowed_actions` vacío = la persona se verificó pero su cargo no registra jornada.

Con `purpose: trainer_session` (camino «Entrenadores») la respuesta añade:
`"trainer_session": { "token": "<bearer del portal>", "trainer": <TrainerProfessionalResource> }`
y no incluye `verification`. 403 `not_a_trainer` si la persona no tiene perfil de entrenador activo;
403 `trainer_face_login_disabled` si el acceso facial al portal está apagado
(`TRAINER_FACE_LOGIN_ENABLED=false`; la jornada no depende de ese interruptor).
Esa sesión es de TABLET COMPARTIDA: caduca a las 12 h o tras 15 min sin uso (401
`session_expired`), no convierte la tablet en dispositivo de confianza y debe cerrarse con
`POST trainer/auth/logout` al terminar.

Errores: 422 `face_no_match` (uniforme), 409 `biometric_not_enrolled` (con `biometric.status`),
422 `embedding_invalid`, 429 `face_attempts_exceeded` (5 fallos en 15 min por persona;
bloqueo 15 min), 401 `employee_ref_invalid`, 404 `employee_not_found` (desactivada entre
identificar y verificar).

Cada vía cuenta sus fallos por separado: kiosko (por persona; solo lo alcanza una tablet
vinculada), inicio/fin de clase (por persona; exige la sesión del entrenador) y login
facial público `trainer/auth/face/login` (por persona, sin mirar la IP: rotar direcciones
no da intentos de más). Fallar en el login público, que no exige nada, bloquea como mucho
ese login de esa persona un rato (sigue entrando con su documento); nunca el rostro en la
tablet.

Respaldo y entrenadores: el código de propósito `class` sirve UNA vez, para entrar al
portal (`trainer_session`) o para iniciar/finalizar una clase (`fallback_code`).

### 3.2 `POST kiosk/verify-code` [firmada] — respaldo con código de administrador

```json
{ "employee_ref": "…", "purpose": "workday | trainer_session", "code": "48213907" }
```
Misma respuesta que 3.1 con `"method": "admin_code"`. El código lo genera un
administrador en el CRM para ESA persona y ESE propósito; un solo uso; 30 min.
Errores: 422 `authorization_code_invalid` (uniforme), 429 `authorization_code_attempts_exceeded`.

`verification.token`: un solo uso, 120 s, ligado a persona + dispositivo + propósito.

## 4. Jornada laboral

### 4.1 `POST kiosk/workday/clock-in` [firmada]

```json
{ "verification_token": "…", "operation_id": "<uuid v4 generado por la app al tocar el botón>" }
```
→ 201 `{ "ok": true, "result": "clocked_in", "workday": <Workday>, "flagged_previous": <Workday|null> }`
→ 200 `{ "ok": true, "result": "replayed", "workday": <Workday> }` (mismo `operation_id` ya aplicado)

Errores: 409 `workday_already_open` (+ `workday`), 401 `verification_invalid`,
403 `workday_not_applicable`, 409 `idempotency_conflict` (el `operation_id` ya se usó
para otra persona u otra acción).

Regla de salida olvidada: si la jornada abierta tiene más de 16 h, pasa a
incidencia `missing_clock_out` (sin hora de salida inventada) y se abre la nueva;
viene en `flagged_previous`.

### 4.2 `POST kiosk/workday/clock-out` [firmada]

Mismo cuerpo. → 200 `{ "ok": true, "result": "clocked_out" | "replayed", "workday": <Workday> }`.
Errores: 409 `no_open_workday` (+ `last`), 401 `verification_invalid`, 409 `idempotency_conflict`.
Si la jornada abierta tiene más de 16 h, NO se cierra (sería una jornada de un día y
medio): pasa a incidencia y responde 409 `no_open_workday` con `last` = esa jornada
(`status: incident`). La corrige un administrador.

### 4.3 Objeto `Workday`

`clock_out.source`: `employee` o `admin_correction` (corregida en el CRM); en ese caso
`clock_out.method` es `admin`. Los dispositivos de una jornada siempre firmaron (las
rutas del kiosko lo exigen): `device.verified` es `true`. `incident`
incluye además `resolved_by` y `note` cuando se resolvió.

```json
{ "id": "<uuid>", "status": "open | closed | incident",
  "business_date": "2026-10-10",
  "clock_in_at": "…", "clock_out_at": "…|null", "duration_seconds": 30120,
  "position": { "key": "receptionist", "label": "Recepción" },
  "clock_in":  { "method": "face | enrollment | admin_code", "device": { "id": "<uuid>", "name": "Tablet Recepción 01" } },
  "clock_out": { "method": "…", "device": { … } } ,
  "incident": { "reason": "missing_clock_out", "label": "Salida no registrada",
                "flagged_at": "…", "resolved_at": null, "resolution": null } }
```
`clock_out` e `incident` pueden ser `null`. `duration_seconds` solo con salida real o corregida.
`incident.reason`: `missing_clock_out` (salida olvidada) o `profile_deactivated` (la persona se
desactivó con la jornada abierta). Mostrar siempre `label`; motivos nuevos no rompen al cliente.

## 5. Enrolamiento facial (autorizado por un administrador)

### 5.1 `POST kiosk/enrollment/start` [firmada]

```json
{ "employee_ref": "…", "authorization_code": "48213907" }
```
→ 200
```json
{ "ok": true, "enrollment": { "token": "<opaco>", "expires_at": "…" },
  "consent": { "version": "2026-10-v1", "title": "…", "text": "…" } }
```
Errores: 422 `authorization_code_invalid` (uniforme), 429 `authorization_code_attempts_exceeded`.

### 5.2 `POST kiosk/enrollment/complete` [firmada]

```json
{ "enrollment_token": "…", "consent_version": "2026-10-v1", "consent_accepted": true,
  "embeddings": [ [192], [192], [192] ], "operation_id": "<uuid>" }
```
- Tres capturas de la MISMA sesión de cámara; deben ser coherentes entre sí.
- El servidor compara 1:N contra las demás personas: un rostro ya vinculado a otra
  identidad se rechaza (409 `face_already_linked`, con traza aunque se rechace).
- `operation_id` es opcional y no se usa: el reintento de un enrolamiento completado lo
  reconoce el propio `enrollment_token` (mismo dispositivo, sin caducar).

→ 201
```json
{ "ok": true, "biometric": { "status": "active", "enrolled_at": "…" },
  "verification": { "token": "…", "expires_at": "…", "method": "enrollment" },
  "workday": { "state": "none | open", "current": …, "last": … },
  "allowed_actions": [ "clock_in" ] }
```
La app, si la persona llegó para registrar ENTRADA, llama a `clock-in` con ese
token sin volver al inicio.

Errores: 422 `consent_required`, 422 `embeddings_inconsistent`, 409 `face_already_linked`,
401 `enrollment_invalid`, 422 `embedding_invalid`.

## 6. Portal del entrenador (rutas existentes, ampliadas)

### 6.1 `POST trainer/classes/{class}/start` y `/end` (bearer de entrenador)

Cuerpo nuevo (compatible con la app publicada):
```json
{ "session_date": "2026-10-10", "embedding": [192], "fallback_code": "…", "face_verified": true }
```
- `embedding` → rostro comparado en el servidor contra la plantilla de la persona.
- `fallback_code` → código de respaldo (propósito `class`) generado en el CRM.
- Solo `face_verified: true` (app publicada) → se acepta mientras
  `WORKFORCE_LEGACY_FACE_CLAIM=true`, y queda como `legacy_claim`.
- Cabeceras de firma: opcionales. Si vienen, se verifican y un fallo es error.
  Sin ellas: modo `monitor` (se registra el dispositivo DECLARADO de la sesión) o
  `enforce` (403 `device_not_authorized`), según `WORKFORCE_DEVICE_POLICY_CLASSES`.

Errores nuevos: 422 `face_no_match`, 409 `biometric_not_enrolled`, 422 `authorization_code_invalid`,
403 `device_not_authorized` y los de la sección 1.

`session` en la respuesta añade:
`start_identity_method`, `end_identity_method` (`face | admin_code | legacy_claim`).

### 6.2 `POST trainer/auth/logout` (bearer de entrenador)

Revoca la sesión actual. → `{ "ok": true }`.

### 6.3 `POST trainer/auth/face/enroll` (existente)

Ya no sobrescribe: con plantilla activa → 409 `face_already_enrolled`. Por defecto
(`WORKFORCE_LEGACY_SELF_ENROLLMENT=deny`) tampoco registra un primer rostro → 409
`enrollment_requires_authorization`: el registro se hace en el kiosko con el código de un
administrador (§5). Con `first_only` se permitiría el primer registro desde el portal
(desaconsejado mientras el acceso por documento no tenga segundo factor).

## 7. CRM (sesión de administrador)

Permisos: `workforce.view`, `workforce.manage`, `biometrics.view`,
`biometrics.manage`, `devices.view`, `devices.manage`. Ninguno lo recibe el
token compartido de automatizaciones. Por defecto: Super Admin y Administrador.

### 7.1 Jornadas — `workforce.view` salvo indicación

`GET admin/workforce/workdays?from=YYYY-MM-DD&to=YYYY-MM-DD&identity=<uuid>&position=<key>&status=open|closed|incident&device=<uuid>&method=face|enrollment|admin_code&page=1&per_page=25`
(rango por `business_date`, máx. 93 días; por defecto últimos 7 días de Bogotá)
→
```json
{ "ok": true, "data": [ <WorkdayRow> ], "meta": { "page": 1, "per_page": 25, "total": 140, "last_page": 6 },
  "events_cursor": 1234 }
```
`WorkdayRow` = `Workday` + `"employee": { "identity_id": "<uuid>", "full_name": "Ana Martínez" } | null`
(`null` si la identidad ya no existe). `method` del filtro admite también `admin`.
`events_cursor` se lee ANTES de consultar los datos.

`GET admin/workforce/workdays/summary?from=&to=`
→ `{ "ok": true, "data": { "eligible_employees": 12, "employees_with_records": 9, "currently_in": 4,
     "finished": 31, "incidents_open": 1, "punctuality": null } }`
(`punctuality` es `null` hasta que existan horarios laborales aprobados.)

`GET admin/workforce/workdays/{id}` → `{ "ok": true, "data": <WorkdayRow> + "events": [ <WorkdayEvent> ] }`
`WorkdayEvent`: `{ "id", "type": "clock_in|clock_out|incident_flagged|incident_resolved",
"at", "method", "device": { "id", "name", "verified": true }, "actor": { "type": "employee|admin|system", "name" },
"note": null, "technical": { "ip_masked", "app_version", "device_platform" } }`
(`technical` solo si además tiene `devices.view`; dentro, `face_distance` solo si además tiene `biometrics.view`).

`POST admin/workforce/workdays/{id}/resolve` — `workforce.manage`
```json
{ "action": "set_clock_out | dismiss", "clock_out_at": "<ISO 8601 CON desfase, p. ej. 2026-10-11T17:00:00-05:00; solo con set_clock_out>", "note": "motivo (5-500)" }
```
→ `{ "ok": true, "data": <WorkdayRow> }`. Errores: 409 `workday_not_incident`, 422 `clock_out_invalid`.

### 7.2 Personal — `workforce.view` / `workforce.manage`

`GET admin/workforce/positions` → `{ "ok": true, "data": [ { "key", "label", "source": "trainer|staff", "workday": true } ] }`

`GET admin/workforce/staff?search=&position=&status=active|inactive` →
```json
{ "ok": true, "data": [ { "identity_id": "<uuid>", "full_name": "Ana Martínez", "document_masked": "•••• 4567",
  "positions": [ { "key", "label", "source" } ], "status": "active|inactive",
  "trainer_id": 7, "staff_profile": true,
  "capabilities": { "workday": true, "classes": false },
  "biometric": { "status": "active", "enrolled_at": "…", "updated_at": "…" },
  "workday": { "open": true, "since": "…" },
  "phone": "+57…" } ] }
```
`phone` solo llega a quien tiene `workforce.manage` (para editar); `staff_profile: false`
= la persona solo es entrenador y no se edita aquí.

`POST admin/workforce/staff` — `workforce.manage`
`{ "full_name": "…", "document": "…", "phone": "…|null", "positions": ["receptionist"] }`
→ 201 fila. 409 `staff_profile_exists`. Solo cargos `source: staff`.

`PATCH admin/workforce/staff/{identity_id}` — `workforce.manage`
`{ "full_name?", "phone?", "positions?", "status?": "active|inactive" }` → fila.
Desactivar a alguien que ya no conserva ningún perfil activo revoca y BORRA su plantilla.

`POST admin/workforce/staff/{identity_id}/fallback-code` — `workforce.manage`
`{ "purpose": "workday | class", "reason": "…" }` → `{ "ok": true, "code": "48213907", "expires_at": "…", "purpose": "workday" }`
(el código se muestra UNA vez). El propósito `class` exige ADEMÁS `trainers.manage`
(abre el portal del entrenador): sin él, 403 `forbidden` con `required_permission: "trainers.manage"`.

### 7.3 Biometría — `biometrics.view` / `biometrics.manage`

`GET admin/biometrics/identities/{identity_id}` →
```json
{ "ok": true, "data": { "status": "not_enrolled|active|renewal_pending|revoked",
  "enrolled_at": "…", "updated_at": "…", "enrolled_via": "kiosk|trainer_portal_legacy",
  "enrolled_device": { "id", "name" } , "consent_version": "2026-10-v1", "last_verified_at": "…",
  "revoked": { "at", "by", "reason" }, "pending_codes": [ { "purpose", "expires_at", "created_by" } ],
  "history": [ { "event", "label", "at", "actor", "detail" } ] } }
```
Nunca incluye la plantilla.

`renewal_pending` = hay plantilla pero NO verifica: o se registró antes del kiosko (sin
consentimiento ni autorización: las 4 de producción, decisión 2026-10-11) o un
administrador ya autorizó un registro nuevo. En ambos casos la persona debe registrarse
de nuevo con código. Solo una plantilla `active` verifica (kiosko, clases y login facial).

`POST admin/biometrics/identities/{identity_id}/enrollment-code` — `biometrics.manage`
`{ "reason": "…" }` → `{ "ok": true, "code": "…", "expires_at": "…" }`. Con plantilla activa
el estado pasa a `renewal_pending` hasta que se use o caduque.

`POST admin/biometrics/identities/{identity_id}/revoke` — `biometrics.manage`
`{ "reason": "motivo (5-500)" }` → `{ "ok": true, "data": { "status": "revoked", "revoked_at": "…" } }`
(solo eso: para el perfil completo, volver a pedir `GET …/identities/{id}`). 409
`biometric_not_active` si no hay biometría activa.
Revocar BORRA la plantilla y conserva la traza.

`DELETE admin/trainers/{trainer}/face` (existente) equivale a revocar. Acepta cuerpo
opcional `{ "reason": "…" }`; sin él, el motivo es «Eliminado desde la ficha del
entrenador». Desde esta versión exige `biometrics.manage` (antes `trainers.manage`).
Respuesta compatible: `{ "ok": true, "face_enrolled": false, "message": "…", "biometric": { "status": "revoked" } }`.

### 7.4 Dispositivos — `devices.view` / `devices.manage`

`GET admin/devices` → `{ "ok": true, "data": [ <Device> ] }`
```json
{ "id": "<uuid>", "name": "Tablet Recepción 01", "location": "Sede principal", "status": "active|revoked",
  "platform": "android", "manufacturer": "samsung", "model": "SM-X200", "os_version": "14", "app_version": "2.1.0+16",
  "key": { "algorithm": "ES256", "fingerprint": "a1b2c3d4e5f60718", "storage_declared": "strongbox",
           "attestation": { "status": "verified", "security_level": "strongbox" } },
  "registered_at": "…", "registered_by": "Laura (Administrador)", "last_seen_at": "…",
  "revoked": { "at", "by", "reason" } }
```
`POST admin/devices/pairing-codes` — `devices.manage` `{ "name": "…", "location": "…|null" }`
→ `{ "ok": true, "code": "K7QX-4M2P", "expires_at": "…" }` (15 min, un solo uso).
`PATCH admin/devices/{id}` — `devices.manage` `{ "name?", "location?" }`.
`POST admin/devices/{id}/revoke` — `devices.manage` `{ "reason": "…" }`.
`GET admin/devices/security-events?page=1&per_page=25` — `devices.view` →
`{ "ok": true, "data": [ { "id", "at", "event", "label", "device": { "id", "name" }|null,
"claimed_device_id": "…|null", "operation": "kiosk/workday/clock-in", "ip_masked": "190.85.•.•" } ], "meta": {…} }`.

### 7.5 Supervisión de clases (existente, ampliada) — `classes.view`

`GET admin/class-sessions` conserva todos sus campos y añade:
```json
"start_device": { "id": "<uuid>|null", "name": "Tablet Recepción 01", "verified": true } | null,
"end_device":   { … } | null,
"start_identity_method": "face | admin_code | legacy_claim | null",
"end_identity_method":   "…",
```
y en la raíz `"events_cursor": <int>`.
- Dispositivo verificado: `verified: true`, `id` presente.
- Solo declarado por la sesión de la app anterior: `id: null`, `verified: false`, `name` = lo declarado.
- Histórico sin dato: `null` → el CRM muestra «No registrado».

## 8. Tiempo real (SSE)

Mismo canal y mismo protocolo que hoy: `GET admin/classes/stream?proto=2&after_id=N&ticket=…`.
Ahora lo abre quien tenga cualquiera de `classes.view`, `workforce.view`,
`biometrics.view`, `devices.view`; cada hecho se entrega solo a quien tiene su permiso.

| Evento (`event:` = `type`) | Permiso | `reason` | `subject` |
|---|---|---|---|
| `booking.*`, `class.updated` (existentes) | `classes.view` | `session.started`, `session.ended`, … | — |
| `classes` (huella, existente) | `classes.view` | — | — |
| `workday.updated` | `workforce.view` | `clock_in`, `clock_out`, `incident`, `resolved` | uuid de la jornada |
| `staff.updated` | `workforce.view` | `created`, `updated`, `deactivated` | uuid de la identidad |
| `biometric.updated` | `biometrics.view` | `enrolled`, `revoked`, `code_issued` | uuid de la identidad |
| `device.updated` | `devices.view` | `registered`, `revoked`, `renamed`, `security_event` | uuid del dispositivo |

Carga (sin datos personales; el cliente vuelve a pedir lo que le toca ver):
```json
{ "id": 1301, "type": "workday.updated", "class_id": null, "session_date": "2026-10-10",
  "source": "kiosk", "reason": "clock_in", "subject": "<uuid>", "at": "…" }
```
`ready {cursor, resync}`, deduplicación por `id`, solapamiento y reconexión: idénticos
a los del canal de clases actual. Los eventos salen en la misma transacción que el
cambio (outbox `class_events`): no se anuncia nada que luego se revierta.

## 9. Cargos

| key | label | origen | jornada |
|---|---|---|---|
| `trainer_floor` | Entrenador de planta | rol de entrenador | sí |
| `trainer_functional` | Entrenador funcional | rol de entrenador | no (solo clases) |
| `receptionist` | Recepción | perfil de personal | sí |
| `administrative` | Administrativo | perfil de personal | sí |
| `maintenance` | Aseo y mantenimiento | perfil de personal | sí |

Una persona = una identidad. Sus cargos son la unión de sus roles de entrenador
activos y los de su perfil de personal activo.

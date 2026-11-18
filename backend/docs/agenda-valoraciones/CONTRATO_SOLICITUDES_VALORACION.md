# Contrato: «Solicitar valoración» desde la app

**Versión 1 (18-11-2026).** Forma parte del ciclo de valoraciones (`CONTRATO_CICLO_VALORACIONES.md`).

**Principio:** una SOLICITUD no es una cita. No tiene hora ni entrenador: es una petición del socio que administración convierte en una cita NORMAL de la agenda existente, con el asistente de siempre. No hay ninguna segunda agenda.

## 1. Datos (backend C)

**Tabla nueva `valuation_requests`:**

| Columna | Tipo y regla |
|---|---|
| `id`, `uuid` | `uuid` UNIQUE |
| `member_id` | FK `members` ON DELETE CASCADE |
| `status` | `pending`, `scheduled`, `attended`, `rejected` o `cancelled` |
| `source` | `'app'` |
| `client_request_id` | UNIQUE NULL: idempotencia de la app |
| `appointment_id` | FK `valuation_appointments` ON DELETE SET NULL, NULL |
| `handled_by_admin_id`, `handled_by_name`, `handled_at` | Quién la gestionó y cuándo |
| `rejection_reason` | varchar 500 NULL; obligatorio al rechazar |
| `version` | int |
| `created_at`, `updated_at` | Marcas de tiempo |

- **Índice único parcial:** `(member_id) WHERE status = 'pending'`. No puede haber dos pendientes por socio. En SQLite, el equivalente.
- Cada cambio queda en el historial: `AuditTrail` para las acciones del CRM y la propia fila con `handled_*`.

## 2. App del socio (`auth.member`)

**`GET /api/app/valuation-requests/current`.** Estado actual del socio autenticado; NUNCA de otro.

```json
{ "data": {
  "request": {"uuid": "…", "status": "pending", "created_at": "ISO -05:00"} | null,
  "next_appointment": {"uuid": "…", "starts_at": "ISO -05:00", "date_label": "jueves 20 de noviembre, 10:00 a. m.",
                       "trainer_name": "Carlos P.", "status": "scheduled|confirmed"} | null,
  "can_request": true,
  "blocked_reason": null | "pending_request" | "future_appointment"
}}
```

- **`next_appointment`:** la cita abierta FUTURA del socio (`scheduled`, `confirmed` o `in_progress` con `starts_at` futuro).
- **`request`:** la última solicitud, solo si sigue `pending`. Las cerradas no se muestran como activas.
- **`can_request`:** false si hay una solicitud pendiente o una cita futura.

**`POST /api/app/valuation-requests`.** Cuerpo `{client_request_id: uuid}`.
- **201:** la crea y devuelve el mismo `data` que el GET.
- **200:** repetición con el mismo `client_request_id`; devuelve lo mismo, sin crear otra.
- **409 `valuation_request_pending`:** ya tiene una pendiente. Devuelve `data` con el estado actual.
- **409 `valuation_future_appointment`:** ya tiene una cita futura. Devuelve `data`.
- **Bloqueo:** el socio, con `FOR NO KEY UPDATE` sobre `members` en el orden que ya usa la agenda, más el índice parcial. Dos toques a la vez → una sola solicitud.
- **Límite:** `throttle`, p. ej. 6 por minuto.

**Después del commit:**
- **Aviso al CRM** (`createAdminNotification`):
  - `type='valuation'`, `source_event='valuation_request_created'`;
  - `title` «Solicitud de valoración», `message` «María G. solicitó una valoración desde la app.»;
  - `required_permission='valuations.view_all'`, `action_type='valuation_request'`;
  - `action_payload={request_uuid, member_id}`, `event_key='valuation:request:{uuid}:created'`, prioridad `medium`.
- **Aviso al socio** dentro de la app, SIN push (`should_popup=false`):
  - «Recibimos tu solicitud de valoración. Te contactaremos para agendarla.» Nunca dice que ya hay una cita.
  - `event_key='valuation:request:{uuid}:received'`, `action_route='/evaluation'`.

## 3. CRM (`/api/admin/valuations/requests`)

| Ruta | Permiso | Qué hace |
|---|---|---|
| `GET /api/admin/valuations/requests?status=pending\|all&page=` | `valuations.view` | Con el alcance de la agenda. Por fila: `{uuid, status, created_at, member:{id,name,document_tail}, appointment:{uuid,starts_at,trainer_name,status}\|null, future_appointment:{…}\|null, handled_by_name, handled_at, rejection_reason}`. `future_appointment` es la cita futura que ya tenga el socio, para enlazarla en un clic |
| `GET /api/admin/valuations/requests/{uuid}` | `valuations.view` | Una fila |
| `POST /api/admin/valuations/requests/{uuid}/schedule` | `valuations.create` | Cuerpo `{appointment_uuid, expected_version}`. Enlaza una cita YA creada con el asistente existente: misma ficha, abierta y futura. La solicitud pasa a `scheduled`. Si ya está enlazada a esa misma cita, responde 200 |
| `POST /api/admin/valuations/requests/{uuid}/attend` | `valuations.update` | Cuerpo `{expected_version}`. Atendida sin cita (p. ej. se valoró en el momento) → `attended` |
| `POST /api/admin/valuations/requests/{uuid}/reject` | `valuations.update` | Cuerpo `{reason (10..500), expected_version}` → `rejected`. Aviso al socio en la app: «No pudimos agendar tu valoración: {motivo}» (`should_popup=false`) |

- **Errores:** 409 `request_changed` si la versión no coincide, y 409 `request_not_pending` si ya se cerró.
- **Flujo del CRM:**
  1. el aviso `valuation_request` abre la solicitud;
  2. «Agendar» abre el ASISTENTE EXISTENTE con el socio ya elegido y envía `as_next_follow_up: true`, `type=initial` o `follow_up` según tenga o no valoraciones;
  3. con el `uuid` de la cita creada, llama a `/schedule`.

  Si `/schedule` falla, la solicitud queda pendiente con la cita futura visible para enlazarla en un clic. Nunca hay una cita duplicada, porque `as_next_follow_up` lo impide.

## 4. Confirmación, reprogramación y cancelación hacia la app (backend A)

- Al crear la cita, el aviso al socio de `confirmation` pasa a ser un **push** (`should_popup=true`) con fecha, hora y entrenador. También lo son `rescheduled` y `cancellation`.
- Todos llevan `action_route='/evaluation'`.
- Respetan el horario de silencio de §6 del contrato del ciclo.

## 5. App Flutter (parche OTA, solo Dart)

**En la pantalla de Valoración física (`/evaluation`), una tarjeta arriba:**
- **Sin solicitud ni cita:** botón «Solicitar valoración».
  - Hace el POST con un `client_request_id` generado una vez por intento y reutilizado en los reintentos.
  - Botón desactivado mientras envía.
- **`pending`:** «Solicitud recibida. Te contactaremos para agendar tu valoración.» No dice que haya cita.
- **`next_appointment`:** «Tu valoración: jueves 20 de noviembre, 10:00 a. m. · con Carlos P.»
- **Un 409:** se muestra el estado que devuelve el servidor, sin error rojo.

**Se refresca** al abrir la pantalla, al volver a la app y al tocar o recibir un push o aviso de valoración. **Sin dependencias nativas, assets ni rutas nuevas:** usa `/evaluation`, que ya maneja el enrutador de la release.

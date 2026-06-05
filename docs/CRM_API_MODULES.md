# CRM API — módulos de control de la app

> **Estado del frontend.** El repo **no contiene código fuente Angular** (en
> `frontend/` solo había artefactos de build: `dist/`, `.angular/`, `node_modules`,
> sin `src/`, `angular.json` ni `package.json`). Mientras no exista el frontend,
> **estos endpoints son la fuente de verdad** para controlar la app desde el CRM.
> No se inventó UI. Ver `docs/LOCAL_DEV.md` para levantar el backend.

Base: `/api`. Las rutas `admin/*` siguen el patrón del CRM (sin auth a nivel de
ruta; el acceso se controla en la capa del CRM). Las rutas de miembro usan el
bearer del dispositivo (`Authorization: Bearer <session_token|access_hash>`).

---

## 1. Planes y features
- `GET  /api/plans`, `POST /api/plans`, `GET/PUT/DELETE /api/plans/{plan}`
- `GET  /api/plans/features` — catálogo de features.
- `PUT  /api/plans/{plan}/features` — features por plan (gating premium en la app).
- `GET  /api/plans/{plan}/ai-capabilities`, `PUT /api/plans/{plan}/ai-capabilities`
- `GET  /api/membership-plans`, `GET /api/membership-plans/{plan}` (lo que ve la app).

## 2. Membresías / pagos / cancelaciones  → `backend/docs/MEMBRESIA_RENOVACION_CANCELACION.md`
- `GET  /api/admin/memberships/{member}` — estado (active/cancel_requested/cancelled/expired).
- `POST /api/admin/memberships/{member}/cancel` (`immediate` opcional).
- `POST /api/admin/memberships/{member}/reactivate`.
- Pagos (ePayco): `apiResource payments`, `GET /api/payments/epayco/history`, webhooks de confirmación.
- App (miembro): `GET /api/member/membership/status`, `POST cancel-request|cancel-confirm|reactivate`.

## 3. Publicidad (campañas del Home)  → `backend/docs/PUBLICIDAD_EVENTOS_API.md`
- `GET/POST /api/admin/ads`, `PATCH|POST /api/admin/ads/{ad}`, `DELETE /api/admin/ads/{ad}`
- `POST /api/admin/ads/{ad}/activate|deactivate`
- App: `GET /api/member/ads/active`, `POST /api/member/ads/{ad}/seen`
- Imagen: Firebase Storage (`image_url`+`image_path`) o archivo `image` (disco público).
  Frecuencia: `once|daily|always`.

## 4. Eventos  → `backend/docs/PUBLICIDAD_EVENTOS_API.md`
- `GET/POST /api/admin/events`, `PATCH|POST /api/admin/events/{event}`, `DELETE`
- `POST /api/admin/events/{event}/activate|deactivate`
- App: `GET /api/member/events`, `GET /api/member/events/{event}`

## 5. Lives (Story Live)  → `backend/docs/STORY_LIVE.md`
- `GET  /api/admin/lives` — historial.
- `POST /api/admin/lives/{live}/end` — finalizar.
- App (staff): `POST /api/member/live/create|{id}/start|{id}/end`.
- App (cualquiera): `GET /api/member/live/active`, `POST /api/member/live/{id}/join-token`.
- Staff se marca con `members.is_staff`. Proveedor LiveKit (vars `LIVE_*`/`LIVEKIT_*`).
- **Acceso de staff (CRM):** `GET /api/admin/members/{member}` y
  `PATCH /api/admin/members/{member}/staff-access` `{ "is_staff": true|false }`.
- Permisos para la app en `app-state.live` (`can_create`, `can_view`…); el backend
  decide, la app solo renderiza.

## 6. Seguridad / reportes
- `GET  /api/admin/security/reports`, `GET /api/admin/security/reports/{report}`
- `PATCH /api/admin/security/reports/{report}`
- `POST /api/admin/security/reports/{report}/revoke-devices`
- `GET  /api/admin/security/locks` — bloqueos/suspensiones.
- `POST /api/admin/members/{member}/suspend`, `POST /api/admin/members/{member}/unlock`
- Login adaptativo: ver `backend/docs/SEGURIDAD_LOGIN_ADAPTATIVO.md`.

## 7. Dispositivos / sesiones
- App: `GET /api/members/devices`, `POST /api/members/devices/{uuid}/revoke`
  (+ variantes con 2FA `revoke-request`/`revoke-confirm`), `POST /api/member/sessions/logout-others`.
- Admin: `POST /api/admin/devices/{deviceId}/release` — libera un vínculo equipo↔titular.

## 8. Stories (contenido)
- `GET/POST /api/admin/stories`, `DELETE /api/admin/stories/{id}`.

---

### Notas para construir el frontend del CRM
- Reutilizar el patrón de respuesta `{ ok: bool, data|message }`.
- Subida de imágenes (ads/eventos): subir a Firebase Storage desde el CRM y
  enviar `image_url` + `image_path`, **o** enviar `image` (multipart) y dejar que
  el backend la guarde en el disco público.
- Para multipart en update usar `POST` con `_method=PATCH`.
- Marcar staff (`members.is_staff`) habilita "Crear Live" en la app.

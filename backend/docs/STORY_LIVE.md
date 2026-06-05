# Story Live / transmisiones en vivo (Bloque 5)

Transmisiones en vivo reales con **LiveKit** como proveedor (SDK Flutter sólido,
tokens server-side). Solo el **staff** puede crear/transmitir; los miembros
entran a mirar. Si no hay credenciales del proveedor, la función se presenta como
**"no disponible"** (no hay UI muerta ni crashes).

## Roles
- `members.is_staff` (boolean) marca al personal del gimnasio. Solo ellos pueden
  `create`/`start`/`end` y publican cámara/mic. El CRM lo activa por miembro.
- Cualquier miembro puede listar lives activos y entrar como espectador.

## Contrato de permisos (app-state)
El backend DECIDE; la app solo renderiza. `GET /api/member/app-state` incluye:
```json
"live": {
  "enabled": true, "provider": "livekit", "is_staff": true,
  "can_create": true, "can_start": true, "can_end_own_live": true, "can_view": true
}
```
- staff + LiveKit configurado → `can_create=true`.
- miembro normal → `can_create=false`, `can_view=true`.
- LiveKit no configurado → todo en `false` (la app muestra "no disponible").
La app usa `live.can_create` para mostrar "Crear Live" (no hardcodea is_staff).

## Otorgar acceso de staff desde el CRM
- `GET   /api/admin/members/{member}` — datos básicos (incluye `is_staff`).
- `PATCH /api/admin/members/{member}/staff-access` body `{"is_staff": true}`.
Solo el CRM puede marcar staff; el usuario móvil **nunca** se auto-marca. Cada
cambio se audita en log: `member.staff_access.updated` (by_admin_id, member_id,
is_staff). Tras el cambio, la app refleja el permiso en el siguiente refresh del
app-state.

## Arquitectura
```
Flutter (livekit_client) ──join-token──▶ Laravel ──mint JWT (HS256)──▶ LiveKit room
                          ◀── url+token ──
```
- El backend acuña un JWT de acceso a la sala con `LiveKitService::mintToken`
  (claim `video`: `roomJoin`, `canPublish` solo para el host, `canSubscribe`).
- La `api_secret` nunca sale del backend.

## Endpoints

### Miembro (auth.member)
- `GET  /api/member/live/active` — lives en vivo + `enabled` (si el proveedor está configurado).
- `POST /api/member/live/create` — crear (solo staff). 403 si no es staff, 503 si no hay proveedor.
- `POST /api/member/live/{id}/start` — iniciar (host/staff).
- `POST /api/member/live/{id}/end` — finalizar (host/staff).
- `GET  /api/member/live/{id}` — detalle.
- `POST /api/member/live/{id}/join-token` — token de sala (`can_publish` true solo para el host).

### CRM admin
- `GET  /api/admin/lives` — historial.
- `POST /api/admin/lives/{id}/end` — finalizar a la fuerza.

## Activación (servidor) — intervención manual con credenciales

1. Crear un proyecto en **LiveKit Cloud** (o desplegar LiveKit self-host).
2. Obtener `API Key`, `API Secret` y la `URL` (wss://...).
3. En el `.env` del servidor (no en el repo):
   ```env
   LIVE_ENABLED=true
   LIVEKIT_URL=wss://tu-proyecto.livekit.cloud
   LIVEKIT_API_KEY=...
   LIVEKIT_API_SECRET=...
   ```
4. `php artisan config:clear`.
5. Marcar a los entrenadores/staff con `members.is_staff = true` desde el CRM.

**Qué falta para producción (depende de credenciales externas):** las claves de
LiveKit y un proyecto activo. Sin ellas, `GET live/active` devuelve `enabled:false`
y la app muestra "no disponible".

## App (Flutter)
- Dependencia: `livekit_client` (reusa `flutter_webrtc`, ya presente).
- `LiveLobbyScreen` (Perfil → "En vivo"): lista lives; el staff ve "Crear Live".
- `LiveRoomScreen`: host publica cámara/mic, espectador mira. Libera cámara, mic
  y la conexión de forma determinista al salir (`Room.disconnect()`+`dispose()`).
- Permisos de cámara/mic solo se piden al host.

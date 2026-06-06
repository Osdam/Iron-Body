# Real-time (SSE) — Iron Body

Mecanismo **principal** de sincronización de la app: canal privado por miembro
sobre **Server-Sent Events (SSE)**. El polling queda solo como *fallback* de
resiliencia en el cliente.

## Por qué SSE (y no Reverb/WebSocket)

- Los eventos son **unidireccionales** backend → miembro (no hay RPC del cliente).
- Reusa infraestructura ya probada en este repo (`App\Support\SseStream`, usado
  por `notifications/stream`, contratos y asistencia). No requiere un servidor
  WebSocket aparte (Reverb/Soketi), ni broker, ni queue workers dedicados, ni
  proxy `wss` adicional → menos piezas que mantener y desplegar en el VPS.
- Compatible con iOS/Android (HTTP plano) y con App Store.

Si en el futuro se necesita fan-out masivo (p. ej. stories de TODOS los miembros
en tiempo real) se puede migrar a Laravel Reverb; el cliente ya está desacoplado
por `RealtimeService` y bastaría cambiar el transporte.

## Arquitectura

1. **Bus de eventos** — tabla efímera `member_realtime_events` (se auto-poda a
   5 min). El backend inserta una señal con `App\Services\RealtimeEvents::emit()`
   en cada mutación crítica (membresía/pago/perfil/teléfono/staff-live/story/
   seguridad). El payload NO lleva tokens, OTP, montos ni secretos: solo
   `{type, member_id, version, changed[], timestamp}`.
2. **Canal privado SSE** — `GET /api/member/realtime` (middleware `auth_member`).
   El token valida la conexión y la consulta está **acotada al `auth_member->id`**:
   un miembro jamás recibe eventos de otro. Conexión acotada (~25s) y el cliente
   reconecta solo (no deja un worker tomado indefinidamente).
3. **Cliente** — `RealtimeService` (Flutter) consume el SSE, despacha el refresco
   del módulo (AppState/stories/notificaciones) y reconecta con backoff. Avisa a
   `AppSyncService` para que el polling quede solo como fallback cuando el socket
   está caído.

## Requisitos en el VPS (php-fpm + nginx)

SSE corre dentro de Laravel; **no hay proceso aparte**. Solo hay que asegurar:

- **nginx no debe bufferizar el stream** (ya se envía `X-Accel-Buffering: no`).
  En el `location` del API conviene además:
  ```nginx
  proxy_buffering off;
  proxy_cache off;
  proxy_read_timeout 60s;
  fastcgi_buffering off;       # si se usa fastcgi a php-fpm
  ```
- **php-fpm con suficientes workers**: cada conexión SSE ocupa un worker ~25s.
  Subir `pm.max_children` acorde a la concurrencia esperada (cada miembro activo
  mantiene 1 conexión rotando). Recomendado holgura ≥ usuarios concurrentes.
- **Migración aplicada**:
  ```bash
  php artisan migrate          # crea member_realtime_events
  php artisan config:clear
  php artisan route:clear
  ```
- **Poda**: es automática en `emit()`. Opcionalmente, un cron de respaldo:
  ```
  * * * * * php artisan tinker --execute="\App\Models\MemberRealtimeEvent::where('created_at','<',now()->subMinutes(10))->delete();"
  ```
- **Health check**: `GET /api/member/realtime` con un bearer válido debe devolver
  `200` con `Content-Type: text/event-stream` y emitir `: ping` periódicamente.
  Sin bearer debe devolver `401`.

> Nota dev: `php artisan serve` usa 1 worker; para que el stream no bloquee otras
> peticiones, arrancar con `PHP_CLI_SERVER_WORKERS=8 php artisan serve`. En
> php-fpm/nginx no aplica.

## Background (iOS/Android) — Bloque 7

- **Foreground**: SSE (`RealtimeService`) — mecanismo principal.
- **Background/killed**: no se garantiza el socket. Se usa **FCM/APNs** (ya
  integrado: `PushMessagingService` + `NotificationsStreamService`) para avisos;
  al **abrir/resumir** la app, `RealtimeService.start()` + `AppSyncService
  .refreshOnResume()` hacen refresh inmediato (ya cableado en `AppShell`).
- Hook listo para silent-push de app-state: emitir un push silencioso desde
  `RealtimeEvents::emit()` (o un listener del modelo) cuando haya cambios
  críticos; no implementado aquí para no acoplar la entrega push al bus.

## Fallback

`AppSyncService` mantiene un polling **conservador (60s)** que corre SOLO cuando
el real-time NO está conectado (`setRealtimeConnected(false)`), más el refresh en
foreground/resume y tras acciones críticas (pago, perfil, etc.). Nunca reemplaza
al real-time mientras el socket está activo.

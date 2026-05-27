# Módulo de Notificaciones — Iron Body (referencia)

Sistema de notificaciones **en tiempo real** (SSE) + **push nativo** (FCM),
sincronizado entre la app Flutter y el CRM Angular, con deduplicación e
idempotencia en el backend Laravel. Documento de referencia de endpoints y
configuración. (Para activar FCM ver `FCM_SETUP.md`.)

## Arquitectura

```
Evento de negocio (pago, rutina, clase, login…)
        │
        ▼
NotificationService::notify*()  ──►  tabla `notifications` (dedup por event_key)
        │                                   │
        │ (afterResponse, solo nuevas)      │
        ▼                                   ▼
  FcmService (push nativo)            SSE streams (tiempo real)
        │                              /  \
        ▼                             ▼    ▼
   App cerrada/background        App (SSE)  CRM (EventSource)
                                    │            │
                              InAppPushController  refresh()+refreshUnreadCount()
```

- **SSE** = tiempo real in-app (app abierta) + CRM. Latencia ~1 s (miembro).
- **FCM** = eventos críticos con la app cerrada/background.
- **Polling** = fallback automático en ambos clientes si el stream cae.
- Una sola fuente de verdad: todo nace en `NotificationService`.

## Endpoints

### App (audience = member; resuelve por Bearer session token o `?document=`)
| Método | Ruta | Descripción |
|---|---|---|
| GET | `/api/notifications` | Lista (hasta 200) + `unread_count`. Filtros `category`, `search`, `status`. |
| GET | `/api/notifications/unread-count` | Contador de no leídas. |
| GET | `/api/notifications/stream` | **SSE** tiempo real. Emite `event: notification`. |
| GET | `/api/notifications/popup-pending` | Pendientes de mostrar como push in-app (≤3). |
| POST | `/api/notifications/{uuid}/read` | Marca leída. |
| POST | `/api/notifications/{uuid}/popup-shown` | Sella el push in-app mostrado. |
| POST | `/api/notifications/read-all` | Marca todas leídas. |
| DELETE | `/api/notifications/{uuid}` | Elimina. |
| POST | `/api/members/push-token` | Registra/renueva token FCM (auth.member). |
| POST | `/api/members/push-token/remove` | Da de baja un token FCM (auth.member). |

### CRM (audience = admin)
| Método | Ruta | Descripción |
|---|---|---|
| GET | `/api/admin/notifications` | Lista paginada + búsqueda (nombre/doc/ref/tipo/fecha). |
| GET | `/api/admin/notifications/unread-count` | Contador. |
| GET | `/api/admin/notifications/stream` | **SSE** tiempo real para el CRM. |
| POST | `/api/admin/notifications` | Crear notificación manual. |
| POST | `/api/admin/notifications/{uuid}/read` | Marca leída. |
| POST | `/api/admin/notifications/read-all` | Marca todas. |

## SSE — formato y configuración

- Respuesta `Content-Type: text/event-stream`, `Cache-Control: no-cache`,
  `X-Accel-Buffering: no`.
- Evento: `id: <id>` / `event: notification` / `data: <Notification::toPublicArray()>`.
- Heartbeat `: ping` para mantener viva la conexión.
- Conexión **acotada** (~20 s) + `retry: 3000`; el cliente reconecta solo.
- **Cursor**: al conectar arranca desde el último `id` del miembro → solo emite lo
  NUEVO (lo histórico llega por el GET de lista). Se puede forzar con `?after_id=`.
- **Sondeo interno**: miembro **1 s**, admin **1.5 s** (helper `app/Support/SseStream.php`).
- **Sesión revocada**: si el Bearer corresponde a una sesión cerrada, los endpoints
  devuelven `401 {code: session_revoked}` y la app redirige al login.

> **Dev**: `php artisan serve` usa 1 worker; una conexión SSE lo bloquea. Arranca con
> varios: `PHP_CLI_SERVER_WORKERS=8 php artisan serve`. En producción (php-fpm/nginx)
> no aplica. Compatible con el túnel ngrok (SSE va sobre HTTP).

## FCM — push nativo

- Cliente HTTP v1 (`app/Services/Fcm/FcmHttpV1Client.php`): JWT RS256 con service
  account → access token OAuth2 cacheado → `messages:send`.
- `FcmService::sendToMember()` empuja a todos los tokens del miembro y borra los
  muertos (UNREGISTERED). No-op + log si no hay credenciales.
- Disparo: `NotificationService::persist()` → `maybePush()` con
  `dispatch(...)->afterResponse()` (no bloquea el request) **solo** para
  notificaciones de **miembro nuevas** (`wasRecentlyCreated`) con `should_popup`
  (`FCM_ONLY_POPUP=true`).
- Config en `.env` (`FCM_ENABLED`, `FCM_PROJECT_ID`, `FCM_CREDENTIALS`). Ver
  `FCM_SETUP.md` para pasos de credenciales (proyecto **iron-body-85fc3**).

## Cobertura de eventos (todos disparados)

| Categoría | Eventos (método notify*) |
|---|---|
| Rutinas | Created, Assigned, Updated, Completed, Deleted, Published |
| Nutrición | NutritionGoalCompleted (rueda 100%), NutritionDayLogged |
| Clases | Created, Reserved, ReservationCancelled, Updated, Cancelled, Full, Reminder |
| Entrenadores | Created, Updated (incl. disponibilidad/availability), Deleted, Assigned, Unassigned, Note |
| Pagos/Membresías | PaymentApproved, PaymentRejected, MembershipActivated, Expiring, Expired, Cancelled, PlanChanged |
| IRON IA | IronAiRecommendation (solo coaching/progreso; sin solapar clases/recordatorios) |
| Miembros (CRM) | MemberCreated, MemberUpdated, MemberDeleted, NewMemberRegistered |
| Seguridad | NewDeviceLogin, ConcurrentSessionRevoked, ConcurrentBlocked, FaceMismatch, DeviceMismatch, SuspiciousLogin |
| Promos/Sistema | PromotionCreated/Updated/Deleted/Published, System, SystemEvent, AdminAuditEvent |

## Deduplicación / idempotencia

- `event_key` único por evento → `firstOrCreate`: el mismo evento nunca crea dos
  filas (p. ej. `payment_approved_{ref}`, `nutrition_goal_completed_{mid}_{fecha}`,
  `face_mismatch_{mid}_{device}_{fecha}`).
- FCM solo se empuja en filas **recién creadas** (`wasRecentlyCreated`) → si el
  event_key ya existía, no se reenvía push.
- Clientes deduplican por `uuid` (cola de InAppPushController; lista en CRM/app).

## Clientes

- **Flutter**: `NotificationsStreamService` (SSE) → `InAppPushController.checkNow()`
  + badge (`NotificationsService.unreadCount`) + `revision` (refresca lista abierta).
  `PushMessagingService` (FCM). UI premium: cápsula ovalada, animación 3D rotateX,
  gradiente amarillo pastel/blanco, swipe lateral/vertical, tap a detalle.
- **Angular**: `shared/services/notifications.service.ts` abre `EventSource` en
  `startPolling` (debounce 200 ms → `refresh()` + `refreshUnreadCount()`),
  reconexión nativa; polling como fallback.

## Probar push real (FCM)

Comando incluido (envía un push de prueba a los dispositivos del miembro):

```bash
php artisan fcm:test                 # primer miembro con token
php artisan fcm:test 1034778400      # por documento
```

Si falta el service-account.json o `FCM_ENABLED=false`, el comando lo informa
con claridad y NO simula envío. Con el archivo colocado + `FCM_ENABLED=true`,
envía de verdad y reporta enviados/fallidos (borra tokens UNREGISTERED).

Para probar SSE por curl:
```bash
curl -N "$URL/api/admin/notifications/stream" -H 'ngrok-skip-browser-warning: true'
# en otra terminal, dispara cualquier evento o:
php artisan tinker --execute='app(App\Services\NotificationService::class)->notifySystem("Hola","prueba", App\Models\Notification::AUDIENCE_ADMIN);'
```

## Resultados E2E medidos (vía ngrok)

- **Latencia SSE miembro**: 238–513 ms (promedio ≈ 389 ms) — objetivo < 1 s ✅
- **Latencia SSE admin**: ~773 ms — objetivo < 1.5 s ✅
- **Eventos perdidos**: 0. **Dedup**: 2 acciones nutrición → 1 notificación ✅
- **Acción CRM → miembro** y **acción app → backend**: notifican + registran ✅
- **FCM cliente**: token real registrado desde dispositivo Android ✅
- **FCM envío real**: ✅ **ACTIVO y validado** — `service-account.json` en
  `storage/app/firebase/`, `FCM_ENABLED=true`, `isConfigured=1`. `php artisan
  fcm:test 1034778400` → push **aceptado por Google** (FCM v1) al token registrado.
  Flujo JWT RS256 → OAuth2 → `messages:send` funcionando.

### Nota de dedup por tipo de evento
- Eventos de **estado** (pago, membresía, rutina, clase, nutrición, IRON IA,
  face/device mismatch): `event_key` → idempotentes (no duplican).
- Alertas de **seguridad por-ocurrencia** (nuevo dispositivo, sesión concurrente
  revocada/bloqueada, login sospechoso): SIN event_key **a propósito** — cada
  ocurrencia es un aviso distinto y no debe suprimirse. El hook FCM igualmente no
  re-empuja la MISMA fila (`wasRecentlyCreated`).

## Verificado

`route:list` (4 rutas stream/push), 47 `notify*` con triggers en controladores/
servicios, SSE entrega por curl (miembro 238–513 ms / admin ~773 ms),
`flutter analyze` 0 errores, `flutter build apk --debug` exit 0 (FCM no rompe el
build), comando `fcm:test` listo. Datos de prueba purgados.

> **Defecto conocido (módulo nutrición, fuera de alcance):** `POST
> /api/app/nutrition/day` da 500 en el 2º guardado del mismo día por mismatch de
> formato en `log_date` (cast `date` guarda `Y-m-d H:i:s`, el `updateOrCreate`
> busca por `Y-m-d`). La notificación deduplica bien; el registro del día no se
> actualiza al repetir. Fix sugerido: `firstOrNew` + `whereDate`, o cast
> `date:Y-m-d`. No aplicado por la regla de no tocar nutrición.

Ver también `FCM_SETUP.md`.

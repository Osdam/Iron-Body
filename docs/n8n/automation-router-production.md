# Automation Router — Guía de producción

Workflow: **Iron Body - Automation Router**
Archivo versionado: [`n8n/workflows/ironbody_automation_router.json`](../../n8n/workflows/ironbody_automation_router.json)

> Esta es la versión **limpia y parametrizada** del router de notificaciones
> proactivas. La instancia local (ID `aYfFqb4KkeQ2epM9`) tenía la URL de Laravel
> quemada (`http://172.17.0.1:8080`); esta versión usa `$env.LARAVEL_BASE_URL`
> y **no contiene secretos ni URLs locales**.

---

## 1. Propósito

Convierte un **evento proactivo** emitido por Laravel (outbox → cola → webhook)
en una **notificación interna** del miembro, llamando de vuelta al endpoint
interno de Laravel `POST /api/internal/automation/notify-member`.

n8n aquí solo **coordina y enruta**: recibe el evento, elige el mensaje y avisa
a Laravel. Laravel es quien crea la notificación (con anti-spam) y, si hay FCM,
el push.

## 2. Qué NO hace

- ❌ **No** maneja el chat de Coach IA ni el "Plan de hoy" (eso es síncrono:
  Flutter → Laravel → OpenAI, sin n8n).
- ❌ **No** llama a OpenAI.
- ❌ **No** accede a PostgreSQL ni construye contexto del usuario.
- ❌ **No** reemplaza a Laravel; solo lo invoca por el endpoint interno.
- ❌ **No** es F3 marketing (asesor comercial). Ese es otro conjunto de flujos.
- ❌ **No** recibe ni reenvía PII sensible (documento, biometría, pagos, tokens).

## 3. Diagrama

```
Detector / evento (Laravel)
        │  ironbody:emit-automation-events / streak.touch
        ▼
automation_events (outbox)  ──►  Job SendAutomationEventToN8n
        │   POST  N8N_WEBHOOK_URL
        │   Headers: Authorization: Bearer <N8N_WEBHOOK_SECRET>
        │            X-IronBody-Event: <event_type>
        │            X-IronBody-Signature: <HMAC-SHA256 del body>
        ▼
[n8n] Webhook /webhook/iron-body-automation
        ▼
[n8n] Code "Map + Notify Laravel"
        │   POST  {LARAVEL_BASE_URL}/api/internal/automation/notify-member
        │   Headers: Authorization: Bearer <AUTOMATION_INTERNAL_SECRET>
        ▼
[Laravel] notify-member  ──►  AppNotificationService (anti-spam)
        │                        ├─ app_notifications (centro del coach)
        │                        └─ AppPushService → FCM (push, si está activo)
        ▼
App Flutter (óvalo + centro de notificaciones, abre action_route)
```

## 4. Eventos soportados

El Code node mapea estos `event_type` (header `x-ironbody-event` o `body.event_type`):

| event_type                     | type (notif)                   | action_route               | priority |
|--------------------------------|--------------------------------|----------------------------|----------|
| `nutrition.missing`            | `nutrition.missing`            | `/iron-ai?focus=nutrition` | normal   |
| `workout.missed`               | `workout.missed`               | `/iron-ai?focus=training`  | normal   |
| `membership.expiring`          | `membership.expiring`          | `/membership`              | high     |
| `streak.completed`             | `streak.completed`             | `/iron-ai?focus=progress`  | normal   |
| `evaluation.outdated`          | `evaluation.outdated`          | `/evaluation`              | normal   |
| `progress.stalled`             | `progress.stalled`             | `/iron-ai?focus=progress`  | normal   |
| `iron_ai.weekly_summary_ready` | `iron_ai.weekly_summary_ready` | `/iron-ai?focus=weekly`    | normal   |

Cualquier otro evento → `status: skipped`, `skipped_reason: unmapped_event`.

### `member_id` aceptado del payload
El Code node lo busca, en orden, en: `data.member.id`, `data.member_id`,
`body.member_id`, `body.member.id`.

### Payload que n8n envía a `notify-member`
```json
{
  "member_id": 123,
  "type": "nutrition.missing",
  "title": "Nutricion pendiente",
  "body": "Aun no registras tus comidas de hoy. ...",
  "action_route": "/iron-ai?focus=nutrition",
  "priority": "normal",
  "payload": { "event_type": "nutrition.missing", "source": "n8n-automation-router" }
}
```
> `event_type`/`source` viajan dentro de `payload` (metadata segura). El backend
> fuerza `source='automation'` por su cuenta; los campos extra top-level se
> ignoran por la validación de Laravel.

### Respuesta del Respond OK
```json
{ "ok": true, "received": true, "event_type": "...", "member_id": 123,
  "status": "sent | skipped", "laravel_status": 200, "skipped_reason": "..." }
```
`skipped_reason` ∈ `no_base_url | no_secret | no_member | unmapped_event`.

## 5. Variables de entorno en n8n

| Variable                        | Valor (producción)                          | Obligatoria |
|---------------------------------|---------------------------------------------|-------------|
| `LARAVEL_BASE_URL`              | `https://api.ironbodyneiva.cloud`           | ✅          |
| `AUTOMATION_INTERNAL_SECRET`    | *(mismo valor que el `.env` de Laravel)*    | ✅          |
| `N8N_BLOCK_ENV_ACCESS_IN_NODE`  | `false` (para que el Code node lea `$env`)  | ✅          |
| `N8N_WEBHOOK_SECRET`            | *(solo si se implementa HMAC — Fase 2)*     | opcional    |

> ⚠️ Sin `N8N_BLOCK_ENV_ACCESS_IN_NODE=false` el Code node no lee `$env` y todo
> cae en `skipped: no_secret` (limitación conocida del sandbox de n8n).

## 6. Variables de entorno en Laravel (VPS)

| Variable                     | Valor                                                        |
|------------------------------|--------------------------------------------------------------|
| `N8N_ENABLED`                | `true`                                                       |
| `N8N_WEBHOOK_URL`            | `https://<dominio-n8n>/webhook/iron-body-automation`         |
| `N8N_WEBHOOK_SECRET`         | *(secreto largo; Laravel firma el body con HMAC-SHA256)*     |
| `AUTOMATION_INTERNAL_SECRET` | *(mismo valor que el Bearer que usa n8n)*                    |

Además, en la VPS deben estar corriendo:
- **Worker de cola**: `php artisan queue:work --tries=3 --max-time=3600` (supervisor).
- **Scheduler**: `* * * * * php artisan schedule:run`.

> Sin worker, los eventos quedan `pending` y nunca llegan a n8n.
> El Coach IA síncrono (chat / Plan de hoy) **no** depende de esto.

## 7. Importar el JSON en n8n

1. Entrar a la n8n de la VPS → **Workflows** → **Import from File** (o
   *⋯ → Import from URL/Clipboard*).
2. Seleccionar `n8n/workflows/ironbody_automation_router.json`.
3. El workflow se importa **inactivo** (`active: false`). No tocar todavía.

## 8. Configurar las env vars de n8n

Según cómo corra n8n en la VPS:

- **Docker**: añadir al `docker run` / `docker-compose.yml`:
  ```
  LARAVEL_BASE_URL=https://api.ironbodyneiva.cloud
  AUTOMATION_INTERNAL_SECRET=<mismo-valor-que-laravel>
  N8N_BLOCK_ENV_ACCESS_IN_NODE=false
  ```
  Recrear el contenedor **conservando el volumen** (`n8n_data`). Nunca quemar el
  secreto en el JSON del workflow.
- **n8n nativo / systemd**: exportarlas en el `EnvironmentFile` del servicio.

> Las "Variables" de la UI de n8n (Enterprise) no son lo mismo que `$env` del
> Code node. Este workflow usa `$env`, así que deben ser **variables de entorno
> del proceso n8n**.

## 9. Activar el workflow

Tras confirmar las env vars: abrir el workflow → toggle **Active** (arriba a la
derecha). El webhook de producción queda escuchando en `/webhook/iron-body-automation`.

## 10. Copiar la URL de producción

En el nodo **Webhook**, pestaña **Production URL**:
```
https://<dominio-n8n>/webhook/iron-body-automation
```
(La *Test URL* `/webhook-test/...` solo funciona con el editor abierto y "Listen for test event".)

## 11. Poner `N8N_WEBHOOK_URL` en Laravel

En el `.env` del backend en la VPS:
```
N8N_ENABLED=true
N8N_WEBHOOK_URL=https://<dominio-n8n>/webhook/iron-body-automation
```
Luego:
```
php artisan config:clear   # o config:cache
```

## 12. Cómo probar

**Smoke test del canal (no requiere datos reales):**
```
php artisan ironbody:n8n-test-event
```
Emite un evento `system.test`. Verifica que el `automation_event` quede `sent`.
> Nota: `system.test` **no** está en el mapa del router → el Respond devolverá
> `skipped: unmapped_event`. Eso es correcto: confirma webhook + ida y vuelta.

**Prueba con un evento real mapeado:**
```
php artisan ironbody:emit-automation-events --only=nutrition.missing
# (o el detector específico, p. ej. ironbody:detect-nutrition-missing)
```
Esto debe: detectar miembros → encolar evento → Job POST a n8n → router →
`notify-member` → fila en `app_notifications`.

**Verificar comandos disponibles:**
```
php artisan list | grep -iE "n8n|automation|detect|weekly-ai"
php artisan route:list | grep automation
```

## 13. Cómo revisar logs

- **Laravel**: `storage/logs/laravel.log` → buscar `iron-ai-coach`, `automation`,
  errores del Job `SendAutomationEventToN8n`. (Los logs **no** registran secretos
  ni PII por diseño.)
- **Cola**: estado de la tabla `automation_events` (`status`, `attempts`,
  `last_error`).
- **n8n**: panel **Executions** del workflow → ver input/output de cada nodo y el
  `laravel_status` devuelto.

## 14. Verificar la notificación en BD / app

```sql
SELECT id, member_id, type, title, action_route, source, created_at
FROM app_notifications
ORDER BY id DESC
LIMIT 5;
```
- `source` debe ser `automation`.
- En la app: abrir el centro de notificaciones del coach (campana) → debe
  aparecer; al tocarla navega a `action_route`.
- Estado del evento: `automation_events.status = 'sent'`.

## 15. Riesgos pendientes

1. **Webhook de entrada sin autenticación**: `/webhook/iron-body-automation` es
   público. Laravel solo crea la notificación si el callback lleva el Bearer
   correcto (que solo n8n conoce), pero conviene cerrar el webhook con
   *Header Auth* / allowlist de IP a futuro.
2. **HMAC saliente n8n → Laravel no implementado** (ver Fase 2 abajo). Hoy el
   callback usa solo Bearer; Laravel acepta HMAC opcional, así que es válido,
   pero es menos defensa en profundidad.
3. **Dependencia de env vars de n8n**: si faltan, todo cae en `skipped` (no
   rompe, pero no notifica). Verificar tras cada redeploy del contenedor.
4. **Workflow no auto-versionado desde n8n**: si se edita en la UI de la VPS,
   re-exportar y actualizar este JSON para no perder el cambio.

### Fase 2 (opcional) — HMAC saliente desde n8n
Para firmar el callback (header `X-IronBody-Signature` = HMAC-SHA256 del body con
`N8N_WEBHOOK_SECRET`), habría que usar `crypto.subtle` (Web Crypto) en el Code
node, porque el módulo `crypto` de Node está bloqueado en el sandbox. Es viable
pero **frágil**: la firma debe calcularse sobre exactamente el mismo cuerpo
serializado que se envía, y cualquier diferencia de serialización rompe la
verificación. Se deja como mejora futura; mientras tanto el Bearer es suficiente
(Laravel valida HMAC solo si llega). **No implementar con hacks.**

## 16. Checklist de go-live

- [ ] `N8N_BLOCK_ENV_ACCESS_IN_NODE=false` en el entorno de n8n.
- [ ] `LARAVEL_BASE_URL=https://api.ironbodyneiva.cloud` en n8n.
- [ ] `AUTOMATION_INTERNAL_SECRET` en n8n == el del `.env` de Laravel.
- [ ] JSON importado en la n8n de la VPS.
- [ ] Workflow **activado**; Production URL copiada.
- [ ] `N8N_ENABLED=true` y `N8N_WEBHOOK_URL` apuntando al webhook público en Laravel.
- [ ] `config:clear`/`config:cache` ejecutado en Laravel.
- [ ] `queue:work` (supervisor) y `schedule:run` (cron) activos en la VPS.
- [ ] `ironbody:n8n-test-event` → evento `sent`, n8n responde 200.
- [ ] Evento real (`nutrition.missing`) → `app_notifications` creada, visible en la app.
- [ ] Logs revisados (sin secretos ni PII).
- [ ] (Opcional Fase 2) HMAC saliente evaluado.

---

# Fase 2 — Iron Body Proactive Coach (eventos de comportamiento)

Extiende el router con eventos premium que acompañan al usuario fuera de la app.
**No cambia el flujo base** (Laravel → n8n → notify-member → app_notifications →
FCM). El router ahora **prefiere el mensaje personalizado** que envía Laravel
(`data.notification`) y, si no viene, usa su **catálogo de respaldo** (incluye
los 13 eventos nuevos). `nutrition.missing` queda **idéntico** (texto sin tocar).

## Arquitectura del mensaje (premium + personalizado)

```
Detector Laravel → ProactiveCoachService.consider()
   ├─ catálogo premium (App\Support\ProactiveCoach\ProactiveCoachCatalog)
   │     · elige variante (rota por día), personaliza con {name}
   ├─ presupuesto anti-spam (máx 1 fuerte / 2 totales por día)  ← capa nueva
   ├─ idempotencia día/semana (idempotency_key)
   └─ AutomationEventService.emit(payload.data.notification = {title,body,route,...})
         → Job → n8n Router
              · usa data.notification si viene; si no, catálogo de respaldo
              → notify-member (igual que siempre)
```

La copy premium vive en DOS sitios por diseño: **Laravel** (personalizada) y el
**catálogo de respaldo de n8n** (genérica). Si Laravel no envía bloque, n8n
sigue funcionando solo.

## Eventos nuevos (Fase 2)

| Evento | Intensidad | Cadencia | action_route | Detector | Scheduler sugerido |
|---|---|---|---|---|---|
| `workout.not_started_today` | soft | diaria | `/iron-ai?focus=workout` | `detect-workout-not-started` | 17:00 |
| `streak.at_risk` | **strong** | diaria | `/iron-ai?focus=streak` | `detect-streak-at-risk` | 20:30 |
| `streak.not_started` | soft | semanal | `/iron-ai?focus=streak` | `detect-streak-not-started` | mié 11:00 |
| `daily.compliance_missing` | **strong** | diaria | `/iron-ai?focus=today` | `detect-daily-compliance-missing` | 18:30 |
| `coach.nudge` | soft | diaria | `/iron-ai?focus=today` | `detect-coach-nudges` | 16:00 |
| `iron_ai.chat_invite` | soft | semanal | `/iron-ai?focus=chat` | `detect-iron-ai-invites` | jue 10:00 |
| `iron_ai.nutrition_invite` | soft | semanal | `/iron-ai?focus=nutrition` | `detect-iron-ai-invites` | jue 10:00 |
| `iron_ai.progress_invite` | soft | semanal | `/iron-ai?focus=progress` | `detect-iron-ai-invites` | jue 10:00 |
| `iron_ai.streak_invite` | soft | semanal | `/iron-ai?focus=streak` | `detect-iron-ai-invites` | jue 10:00 |
| `coach.reactivation` | **strong** | semanal | `/iron-ai?focus=reactivation` | `detect-coach-reactivation` | mar/vie 10:30 |
| `weekly.coach_plan` | soft | semanal | `/iron-ai?focus=weekly-plan` | `detect-weekly-coach-plan` | lun 08:30 |
| `module.discovery` | soft | semanal | `/iron-ai?focus=discover` | `detect-module-discovery` | **INACTIVO** |
| `workout.missed` *(base)* | — | diaria | `/iron-ai?focus=workout` | `detect-workout-missed` | 19:00 (tono evolucionado) |

`module.discovery` está **preparado pero inactivo**: requiere la tabla
`app_module_usages` poblada por instrumentación en Flutter (Fase 3). El detector
se salta siempre hasta que `PROACTIVE_COACH_DISCOVERY_ENABLED=true` **y** haya
datos. No inventa uso.

## Estrategia anti-spam (capas)

1. **Idempotencia** (`automation_events.idempotency_key`): no duplica el mismo
   evento/miembro por día (o semana, según cadencia).
2. **Presupuesto diario** (`ProactiveCoachService`): máx **1 fuerte** y máx **2
   totales** proactivas por miembro/día (config `proactive_coach.budget`).
3. **Quiet hours**: no emite 21:00–08:00 (config `proactive_coach.quiet_hours`).
4. **Gate final** (`AppNotificationService`, sin cambios): 12h mismo tipo, 1/tipo/día,
   3 totales/día.
5. **Cumplimiento**: si el usuario ya cumplió (entrenó/registró/marcó racha), el
   detector lo salta antes de emitir.

## Rutas en Flutter

`CoachNotificationRouter` ya enruta `/iron-ai`, `/membership`, `/progress`,
`/nutrition`, `/evaluation`, `/workouts`, `/classes`. Los focos nuevos
(`workout/chat/discover/reactivation/weekly-plan/weekly`) abren el Coach IA; el
servicio Flutter `IronAiCoachService.normalizeFocus()` los **clampa** a un foco
válido del backend (`today/progress/nutrition/streak`) para no romper la petición.

## Flag maestro y rollback

- `PROACTIVE_COACH_ENABLED=false` (default) → el scheduler **no** agenda ningún
  detector nuevo. El flujo base sigue intacto.
- Para activar: `PROACTIVE_COACH_ENABLED=true` + `config:cache`. **Rollback**:
  volver a `false` (o quitar la env) y `config:cache`. Sin tocar código.
- `PROACTIVE_COACH_DISCOVERY_ENABLED=false` (default) mantiene `module.discovery`
  inerte aunque el flag maestro esté en true.

## Probar en local (sin enviar nada real)

```
php artisan ironbody:detect-workout-not-started --dry-run
php artisan ironbody:detect-streak-at-risk --dry-run
php artisan ironbody:detect-daily-compliance-missing --dry-run
php artisan ironbody:detect-coach-nudges --dry-run
php artisan ironbody:detect-iron-ai-invites --dry-run
php artisan ironbody:detect-coach-reactivation --dry-run
php artisan ironbody:detect-weekly-coach-plan --dry-run
php artisan ironbody:detect-module-discovery --dry-run   # avisa INACTIVO
php artisan test --filter=ProactiveCoachTest
```

`--dry-run` muestra qué se emitiría sin escribir. Opciones: `--member-id=<id>`
(un solo miembro, cualquier estado), `--limit=<n>` (tope de acciones),
`--event=<event_type>` (filtra en detectores multi-evento, p. ej. invites).

## Probar en producción de forma controlada

```
# 1) Smoke del canal (no mapeado → skipped, confirma ida/vuelta):
php artisan ironbody:n8n-test-event

# 2) Un solo miembro real, evento real (con N8N_ENABLED=true):
php artisan ironbody:detect-streak-at-risk --member-id=<ID> --limit=1
#    (sin --dry-run: emite de verdad a ese único miembro)

# 3) Validar:
#    - automation_events: status 'sent'
#    - n8n Executions: laravel_status 200
#    - app_notifications: fila con type/action_route correctos, source='automation'
#    - dispositivo real: push (si FCM_ENABLED=true y hay token activo)
```

> ⚠️ Nunca correr un detector **sin** `--member-id`/`--limit` en producción la
> primera vez: emitiría masivamente. Validar siempre con `--dry-run` antes.

## Despliegue en la VPS (pasos exactos, NO ejecutar aquí)

```
git pull
composer install --no-dev --optimize-autoloader      # si cambió composer
php artisan migrate                                   # crea app_module_usages (vacía, inerte)
php artisan optimize:clear
php artisan config:cache
php artisan route:cache                               # si aplica
php artisan queue:restart
supervisorctl restart ironbody-queue-worker:*
# n8n: Import from File → n8n/workflows/ironbody_automation_router.json
#      (reemplaza el router; conserva env vars). Activar SOLO tras probar.
#      Verificar Production URL = https://n8n.ironbodyneiva.cloud/webhook/iron-body-automation
# Pruebas: system.test → un miembro con --member-id → revisar Executions + tablas.
```

Tablas/colas a revisar: `automation_events`, `app_notifications`, `jobs`,
`failed_jobs`, y n8n **Executions** (input/output de cada nodo + `laravel_status`).

## Revisar n8n Executions

Panel del workflow → **Executions** → abrir una ejecución → nodo
"Map + Notify Laravel": ver `event_type`, si usó `personalized:true` (mensaje de
Laravel) o el respaldo, y `laravel_status` (200 = notify-member OK).

## Riesgos pendientes / antes de activar masivo

- `workout.not_started_today` depende del formato de `routines.days`; si está
  vacío/no fiable, degrada a "rutina asignada sin completion hoy". Revisar con
  datos reales antes de activarlo a todos.
- `module.discovery` requiere instrumentación Flutter (Fase 3); no activar.
- HMAC saliente n8n→Laravel sigue sin implementar (Bearer-only, aceptado).
- Activar **uno por uno** (no todos de golpe): empezar por 1-2 eventos suaves,
  observar volumen en `app_notifications` y feedback, luego ampliar.
</content>
</invoke>

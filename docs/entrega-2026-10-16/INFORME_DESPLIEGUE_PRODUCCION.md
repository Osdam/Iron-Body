# Informe de despliegues a producción

Servidor: `ironbody-vps` (Ubuntu 22.04, PHP 8.3, PostgreSQL 14.24, nginx, supervisor con 12 workers). API en `/var/www/api/backend` (repo git, actualizado como root con `git merge --ff-only`); CRM compilado en `/var/www/app-source` y publicado en `/var/www/app` con `npm run deploy -- --backup` (no borra ficheros: las pestañas abiertas siguen funcionando). Sin CI/CD; `main` sin protección de rama (los push fueron avances rápidos, nunca forzados).

## Despliegue 1 — Personal y traslados (16-10-2026, 04:56–05:05 UTC)

| | Antes | Después |
|---|---|---|
| Backend | `808516d` | `b1ce780` |
| CRM | `3701558` | `5886982` (`main-OG4FYQ3U.js`) |
| Migraciones | 297 | 299 (`plan_transfer_batches`, `plan_transfer_items`; aditivas, `lock_timeout=5s`, 47 ms y 20 ms) |

- Respaldo: `/var/backups/ironbody/pre-personal-traslados-20261016T045652Z` (verificado; ver `INFORME_BACKUPS.md`).
- Cachés: `config:cache` como `www-data` (el `config.php` pasó de `root:root 644` —legible por cualquiera— a `www-data 600`) y `route:cache`. `queue:restart`: 12 RUNNING en dos comprobaciones.
- Smoke: API 200; rutas nuevas 401 sin sesión; prueba autenticada con rollback (ficha 200, Recepción 403, candidatos 26, simulación «Élite» con fechas iguales, 0 escrituras); 11 rutas de kiosko sin cambios; `WORKFORCE_MEMBER_LINK_ENABLED` = false.
- Monitoreo: 0 errores en el log de Laravel, 0 respuestas 5xx.

## Despliegue 2 — Cierre de seguridad (16-10-2026, 12:35–12:39 UTC, con 2 cajas abiertas)

| | Antes | Después |
|---|---|---|
| Backend | `b1ce780` | `43e6206` |
| CRM | `5886982` | `6a1566c` (`main-VLQ5RT26.js`; el `main` anterior se conserva) |
| Migraciones | 299 | 299 (sin migraciones) |

Contenido (ramas integradas en `release/2026-10-16-cierre-seguridad`):
- `fix/members-register-ownership` (`6850b81`): grupo de registro público.
- `fix/access-hardening` (`b070ba2`): rostros y torniquete exigen persona; entradas de empleado no gastan cupo.
- `feat/member-link-secure-channel` (`d6b9478`): código del vínculo ligado al reto; rastro de cambios de `members.phone` (bandera sigue apagada).
- `fix/legacy-web-crm-disabled` (`22dc5a5`): CRM antiguo `/crm/*` apagado.
- CRM `fix/attendance-employee-access` (`0e0ac42`): Punto físico acepta acceso de empleado.

Procedimiento: respaldo `/var/backups/ironbody/pre-cierre-seguridad-20261016T122613Z` (SHA256 OK, restauración aislada 0 errores, 12 tablas = producción) → push `b1ce780..43e6206` / `5886982..6a1566c` → API `merge --ff-only` → `config:cache` (600) + `route:cache` → `queue:restart` (12 RUNNING ×2) → `npm run deploy -- --backup` (respaldo `/var/www/app.prev-20261016123825`).

Smoke tests (producción):

| Comprobación | Resultado |
|---|---|
| `GET /api/health` | 200 |
| `GET /crm/trainers`, `/crm/member-routines`, `/crm/trainers/create` | **404** (antes 200, sin autenticación) |
| `GET /api/members/incomplete` | 405 (fuera del grupo público) |
| `POST /api/members/register` sin token | 401 |
| `DELETE api/members/{member}` | apunta a `discardRegistration` (`route:list`); no se probó contra socios reales |
| Token compartido → `face-references` / `turnstile` | **403** / **403** (antes 200) |
| Token compartido → `GET /api/users` | 200 (sin cambio, previsto) |
| Sesión de Recepción (la que usa el lector) → `face-references` | 200 (718 rostros) |
| Sesión de Recepción → `GET /api/users?page=1` | 200 con bloque `access` |
| `register` con el token de la APK sobre el documento de una socia activa | **409**, sin `access_hash`, usuario intacto |
| Ficha laboral, comprobación, acceso de Yuri, candidatos y lotes | 200 / 200 / 200 / 200 (27 candidatos) / 0 lotes |
| Kiosko | 11 rutas sin cambios |
| Escrituras inesperadas en la prueba con rollback | 0; sesiones residuales: 0 |

Monitoreo (12:35–12:39 UTC): 0 errores de Laravel; 0 respuestas 5xx en 516 peticiones; el lector de la sede siguió sincronizando (`GET /api/users` 200 ×400). La prueba de humo dejó una línea `member:registration:rejected` en el log (esperado; sin datos en claro).

## Despliegue 3 — Segunda iteración de endurecimiento (16-10-2026, 15:52–15:57 UTC)

| | Antes | Después |
|---|---|---|
| Backend | `43e6206` | `ed2aabb` (rama `release/2026-10-16-endurecimiento-2`: `e8aa158` + `7d80a0a`) |
| CRM | `6a1566c` | sin cambios |
| Migraciones | 299 | 299 |

- Revisión independiente de api-security: APTO CON CORRECCIONES MENORES; N1 y N3 corregidos antes de desplegar.
- Suite completa: 8.821 pruebas, los mismos 56 fallos que producción (0 nuevos, 0 desaparecidos).
- Respaldo: `/var/backups/ironbody/pre-endurecimiento-2-20261016T155026Z` (SHA256 OK; volcado legible, 192 tablas; restauración completa probada con el mismo procedimiento sobre el respaldo de las 12:26).
- Procedimiento: push `43e6206..ed2aabb` → `merge --ff-only` → `config:cache` (600) + `route:cache` → `queue:restart` (12 RUNNING ×2).
- Verificado: kiosko 11 rutas; `register`, pasos y descarte con sus cupos por red (`throttle:member-register`, `-steps`, `-discard`); `DELETE api/members/{member}` → `discardRegistration` (responde siempre 409).
- Smoke: API 200; `/crm/*` 404; `members/incomplete` 405; token compartido 403 en rostros y torniquete; Recepción 200 en rostros (719) y socios; `register` sobre una socia activa → 409 con solo `{ok, message, status}` (sin ids ni `access_hash`), usuario intacto; 0 escrituras en la prueba con rollback.
- Monitoreo (15:55–15:57 UTC): 0 errores de Laravel; 245 peticiones, 0 respuestas 5xx; el lector de la sede: 235 respuestas 200 y 3 entradas registradas (201).

## Reversión (si hiciera falta)

- API: `cd /var/www/api/backend && git checkout --detach b1ce780` (o `808516d` para volver antes del despliegue 1) → `sudo -u www-data php artisan config:cache && chmod 600 bootstrap/cache/config.php && sudo -u www-data php artisan route:cache && sudo -u www-data php artisan queue:restart`. Sin migraciones que deshacer en el despliegue 2; las tablas del despliegue 1 pueden quedarse.
- CRM: `rsync -a /var/www/app.prev-20261016123825/ /var/www/app/` (sin `--delete`).
- Base de datos: no se restaura sobre la base viva sin analizar lo escrito después.

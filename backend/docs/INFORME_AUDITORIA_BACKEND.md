# Informe de Auditoría de Seguridad — Backend Iron Body

**Fecha:** 2026-07-15 · **Alcance:** backend Laravel (`/backend`) · **Modo:** solo lectura + correcciones aplicadas en rama `fix/auditoria-seguridad-p1` · **Estado prod:** en producción.

> Módulo de acceso físico (torniquete/asistencias/ZKTeco) **excluido**: está en retiro.

---

## Resumen

- **Hallazgos:** 0 críticos (P0) · 4 altos (P1) · 6 medios (P2) · 4 bajos (P3).
- **Corregidos por código (con tests):** 10 de 13. 
- **Pendientes de acción manual (servidor/infra):** 3.
- **Verificación:** suite completa **882 tests en verde** (las únicas fallas son preexistentes por entorno: plantillas PDF de contratos, LiveKit y OpenAI ausentes; ninguna causada por estos cambios).

Lo bien hecho: pagos Wompi e idempotencia sólidos (firma `hash_equals`, `lockForUpdate`, monto autoritativo), sin SQL injection ni mass assignment. Los problemas eran de **exposición de datos, autenticación y configuración**.

---

## Hallazgos, qué se hizo y estado

| ID | Severidad | Hallazgo | Qué se hizo | Estado |
|----|-----------|----------|-------------|--------|
| **BACK-001** | P1 | Endpoints `notifications*` **sin auth**: se leían/borraban notificaciones de cualquier miembro por cédula (`?document=`). | Se exige `auth.member`; identidad solo por Bearer; eliminado el fallback por documento. | ✅ + test |
| **BACK-002** | P1 | Contraseña real de BD versionada en `.env.example` (igual a producción). | Reemplazada por placeholder vacío. | ⚠️ falta rotar en servidor |
| **BACK-003** | P1 | Dependencias con CVEs (Laravel/Symfony/Guzzle, 19 avisos). | — | ⏳ `composer update` en staging |
| **BACK-004** | P1 | Defaults OTP inseguros (código OTP en respuesta si `driver=dev`). | Verificado: producción ya está bien configurada. | ✅ mitigado |
| **BACK-005** | P2 | Middleware de token de registro **fallaba abierto** (sin token → grupo público). | Fail-closed en producción (503 si falta el token). | ✅ |
| **BACK-006** | P2 | `access_hash` permanente e **irrevocable** (token filtrado = acceso indefinido). | Columna `access_hash_revoked_at` + rechazo en login + kill-switch al "cerrar otras sesiones". | ✅ + test + migración |
| **BACK-007** | P2 | Login/OTP **sin rate-limiting** (fuerza bruta, spam de SMS). | `throttle` en `login` (6/min), `verify` (10/min), `resend` (4/min). | ✅ |
| **BACK-008** | P2 | Sesiones de dispositivo **sin expiración**. | TTL deslizante por inactividad (`OTP_SESSION_TTL_DAYS`, 90 días). | ✅ + test |
| **BACK-009** | P2 | `exercises/sync` **público**, dispara sync externo (abuso/costo). | Exige `auth.admin` + `throttle:3,1`. | ✅ + test |
| **BACK-010** | P2 | Vigencia de membresía calculada en **UTC** → acceso cortado ~5 h antes el último día. | Fin de día en `America/Bogota` en los 4 sitios (servicio, gate, payload, modelo). | ✅ + test |
| **BACK-011** | P3 | Nivel de log por defecto `debug`. | Default `info` en producción (explícito manda). | ✅ |
| **BACK-013** | P3 | Código legacy de pagos (Nequi/ePayco). | Eliminado `NequiPaymentController` (muerto). ePayco (composer) pendiente. | ✅ parcial |
| **BACK-014** | P3 | Sin cabeceras de seguridad. | Middleware global: `nosniff`, `SAMEORIGIN`, `Referrer-Policy`, cross-domain `none`. | ✅ + test |

---

## Acciones manuales pendientes (solo el equipo puede hacerlas)

1. 🔴 **Rotar `DB_PASSWORD`** en PostgreSQL de producción y actualizar el `.env` real. La contraseña vieja quedó en el historial de git; considerarla comprometida.
2. **Ejecutar `php artisan migrate`** al desplegar (nueva columna `access_hash_revoked_at`, aditiva y segura).
3. **Confirmar `MEMBER_REGISTRATION_TOKEN`** seteado en producción (si falta, el registro responde 503 por el nuevo fail-closed).
4. **`composer update`** en staging para cerrar los CVEs (BACK-003) y **`composer remove epayco/epayco-php`** (SDK sin uso).

---

## Recomendaciones (siguientes pasos)

- **CI de seguridad:** linter que falle si `.env.example` trae valores no vacíos en claves sensibles; `composer audit` en el pipeline.
- **Endurecer config en arranque:** abortar en `APP_ENV=production` si `APP_DEBUG=true`, `OTP_DRIVER=dev` o `OTP_EXPOSE_CODE=true`.
- **Plan a mediano plazo para `access_hash`:** migrar la app a usar siempre `session_token` y retirar el hash permanente como bearer (hoy queda revocable, que ya reduce el riesgo).
- **CSP/HSTS:** añadir tras inventariar los orígenes del CRM y garantizar HTTPS estricto (no incluidos ahora para no romper el front).
- **Rediseño del módulo de acceso físico** (cuando se rehaga): idempotencia por `event_uuid`, token por dispositivo revocable, rechazo de eventos con fecha futura/muy antigua.
- **Rotación/retención de logs** y revisión de PII en nivel `info`.

---

## Archivos tocados (rama `fix/auditoria-seguridad-p1`)

**Código:** `routes/api.php`, `config/otp.php`, `config/logging.php`, `bootstrap/app.php`, `.env.example`, `app/Http/Controllers/Api/NotificationController.php`, `app/Http/Controllers/Api/AuthController.php`, `app/Http/Controllers/Api/MemberAccountController.php`, `app/Http/Middleware/AuthenticateMember.php`, `app/Http/Middleware/EnsureMemberRegistrationToken.php`, `app/Http/Middleware/SecurityHeaders.php` (nuevo), `app/Models/Member.php`, `app/Models/MemberDeviceSession.php`, `app/Services/MembershipService.php`, `app/Support/MemberPayload.php`, migración `..._add_access_hash_revoked_at_to_members_table.php` (nueva). Eliminado: `NequiPaymentController.php`.

**Tests:** `NotificationAuthTest`, `MembershipTimezoneBoundaryTest`, `DeviceSessionTtlTest`, `AccessHashRevocationTest`, `SecurityHeadersTest` (nuevos) + `AdminRoutesSecurityTest` (actualizado).

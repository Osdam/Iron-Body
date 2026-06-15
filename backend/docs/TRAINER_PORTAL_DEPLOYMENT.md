# Portal profesional de entrenadores — Despliegue, `.env` y rollback

Guía operativa para llevar a producción el **portal de entrenadores** (identidad
+ perfiles dobles, acceso OTP profesional, valoraciones profesionales, clases y
asistencia). Cubre los **dos repos**: backend Laravel (`CRM/Iron-Body/backend`) y
app Flutter (`APP/Iron_Body_App`).

> **Principio rector:** todo es **aditivo y reversible** y nace **apagado**. Con
> los feature flags en `false`, la app y el CRM se comportan **exactamente como
> hoy**: el miembro normal no ve ni intuye el sistema de perfiles múltiples, y
> los pagos Wompi y el login del miembro quedan intactos.

---

## 1. Resumen de cambios

### Tablas nuevas (todas aditivas)
| Tabla | Propósito |
|---|---|
| `identities` | Identidad central por persona (documento normalizado único) |
| `trainer_roles` | Roles profesionales (`trainer_floor`, `trainer_functional`) |
| `trainer_audit_logs` | Bitácora append-only del dominio profesional |
| `trainer_auth_challenges` | Retos OTP del acceso profesional (código hasheado) |
| `trainer_device_sessions` | Sesiones por dispositivo del portal (token hasheado) |
| `professional_assessments` | Valoraciones profesionales (estados + versiones) |
| `class_attendances` | Asistencia por clase y fecha de sesión |

### Columnas añadidas (aditivas, nullable)
- `members.identity_id`
- `trainers.identity_id`
- `trainers.location` (sede)

> No se modifica ni elimina ninguna columna existente. No se tocan
> `member_*` (auth del miembro), `payments`, `attendances` (torniquete), etc.

### Endpoints nuevos (resumen)
- Acceso profesional: `POST /api/trainer/auth/access|verify|resend`,
  `GET /api/trainer/auth/me|bootstrap`, `POST /api/trainer/auth/biometric-unlock`.
- Espacios del miembro: `GET /api/member/workspaces`.
- Valoraciones (entrenador): `…/trainer/members/{id}/assessments`,
  `…/trainer/assessments/{uuid}/(submit|amend)`, etc.
- Valoraciones (miembro, solo lectura): `GET /api/member/assessments[/{uuid}]`,
  `POST /api/member/assessments/{uuid}/ack`.
- Clases: `GET /api/trainer/classes[/{id}]`,
  `POST|PUT /api/trainer/classes/{id}/attendance`.
- CRM admin: `…/admin/trainers/{id}/professional|identity/link|activate|deactivate|devices|audit`.

Todas las rutas profesionales están detrás de **feature flags** aplicados en el
backend (responden `404` cuando están apagadas, para no filtrar su existencia).

### Flutter
- Entrada discreta **"Acceso para entrenadores"** en el login (no compite con
  Iniciar sesión / Crear cuenta).
- `lib/features/trainer/*`: login OTP, home profesional, valoraciones, clases y
  asistencia, perfiles dobles + cambio de espacio.
- Entrada **"Cambiar a Portal de entrenador"** en el perfil del miembro: se
  **auto-oculta** si el backend no reporta portal para esa identidad.

---

## 2. Variables de entorno (`.env`)

Añadir al `.env` de **producción** del backend (ya documentadas en
`.env.example`). **No hay secretos nuevos**: el acceso profesional reutiliza el
motor OTP/Twilio existente.

```dotenv
# Portal profesional — feature flags (nace TODO apagado)
TRAINER_PORTAL_ENABLED=false
TRAINER_AUTH_ENABLED=false
TRAINER_PROFESSIONAL_ASSESSMENTS_ENABLED=false
TRAINER_CLASSES_ENABLED=false
TRAINER_WORKSPACE_SWITCHING_ENABLED=false
# Piloto por identidad (ids de la tabla `identities`, separados por comas).
# Habilita las funciones para esas identidades aunque la bandera global esté off.
TRAINER_PILOT_IDENTITIES=
```

**Requisito de OTP en producción** (ya vigente para el miembro, aplica igual al
entrenador): `OTP_DRIVER=twilio` (o `labsmobile`) con credenciales válidas y
`OTP_EXPOSE_CODE=false`. Con `OTP_DRIVER=dev` el sistema **falla cerrado** en
producción (no envía ni expone códigos).

### Flutter
No requiere variables nuevas. El gateo de funciones lo decide el backend; la app
solo oculta UI. La URL del backend se sigue inyectando en build:
`--dart-define=BACKEND_BASE_URL=https://api.ironbodyneiva.cloud`.

---

## 3. Despliegue del backend

> Ejecutar en el servidor, en una ventana de mantenimiento corta. Las
> migraciones son aditivas y rápidas (no reescriben tablas grandes; el backfill
> recorre por lotes).

```bash
cd /ruta/al/backend

# 1) Código + dependencias
git pull            # rama con el portal ya integrada
composer install --no-dev --optimize-autoloader

# 2) Copia de seguridad de la base ANTES de migrar (obligatoria)
#    pg_dump -Fc -d <db> -f backup_pre_trainer_portal.dump

# 3) Migraciones (aditivas). Revisar primero qué se va a ejecutar:
php artisan migrate --pretend       # inspección, no aplica nada
php artisan migrate --force         # aplica

# 4) Limpiar y recachear config
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
```

Migraciones que se aplican (en orden):

```
2026_06_14_000001_create_identities_table
2026_06_14_000002_add_identity_id_to_members_table
2026_06_14_000003_add_identity_id_to_trainers_table
2026_06_14_000004_backfill_identities          # crea identidades + enlaza datos
2026_06_14_000005_create_trainer_roles_table
2026_06_14_000006_add_location_to_trainers_table
2026_06_14_000007_create_trainer_audit_logs_table
2026_06_14_000008_create_trainer_auth_challenges_table
2026_06_14_000009_create_trainer_device_sessions_table
2026_06_14_000010_create_professional_assessments_table
2026_06_15_000001_create_class_attendances_table
```

> **Backfill:** `backfill_identities` crea una identidad por documento
> normalizado y enlaza miembros y entrenadores que comparten documento. Es
> **idempotente** (solo procesa filas con `identity_id` nulo) y se puede
> re-ejecutar de forma segura llamando a
> `App\Services\Identity\IdentityLinkService::backfillExisting()` (p. ej. desde
> `php artisan tinker`) si se cargan datos nuevos.

En este punto **nada cambió para el usuario**: las banderas siguen en `false`.

---

## 4. Despliegue del Flutter

```bash
cd /ruta/al/APP/Iron_Body_App
flutter pub get
flutter build apk --release --dart-define=BACKEND_BASE_URL=https://api.ironbodyneiva.cloud
# iOS (si aplica):
flutter build ipa --dart-define=BACKEND_BASE_URL=https://api.ironbodyneiva.cloud
```

La app nueva es segura de publicar con las banderas en `false`: la entrada
"Acceso para entrenadores" lleva a una pantalla que el backend responde `404`
hasta que se active `TRAINER_AUTH_ENABLED` (o el piloto), y la entrada del
miembro se auto-oculta.

---

## 5. Alta de entrenadores (CRM)

El perfil profesional lo administra el CRM. Por cada entrenador:

1. Crear/ubicar el `Trainer` (CRUD existente) con **documento** y **teléfono**
   correctos (el OTP se envía a ese teléfono).
2. Vincular identidad: `POST /api/admin/trainers/{id}/identity/link`
   (`{ "document": "<doc>" }` o `{ "identity_id": <id> }`).
3. Asignar roles y sede: `PUT /api/admin/trainers/{id}/professional`
   (`{ "roles": ["trainer_floor","trainer_functional"], "location": "Sede Norte" }`).
4. Asignar miembros (planta) y/o clases (funcional) con los flujos existentes
   (`member_trainer_assignments`, `classes.trainer_id`).

Para **desactivar** un entrenador: `POST /api/admin/trainers/{id}/deactivate`
→ corta el acceso profesional y **revoca sus sesiones**, pero conserva su cuenta
de miembro, su membresía y sus valoraciones históricas.

---

## 6. Estrategia de activación (rollout)

1. **Apagado (default):** desplegar backend + app. Verificar que el miembro
   normal no nota cambios. (Ya es seguro quedarse aquí indefinidamente.)
2. **Piloto por identidad:** dar de alta 1–2 entrenadores (sección 5), obtener
   sus `identities.id` y ponerlos en `TRAINER_PILOT_IDENTITIES`. Activar:
   ```dotenv
   TRAINER_PILOT_IDENTITIES=12,34
   ```
   `php artisan config:cache`. Solo esas identidades ven el portal y sus
   funciones; el resto, intacto.
3. **Global por capa:** activar las banderas de forma incremental, validando
   cada una en producción antes de la siguiente:
   ```dotenv
   TRAINER_PORTAL_ENABLED=true
   TRAINER_AUTH_ENABLED=true
   TRAINER_PROFESSIONAL_ASSESSMENTS_ENABLED=true
   TRAINER_CLASSES_ENABLED=true
   TRAINER_WORKSPACE_SWITCHING_ENABLED=true
   ```
   Tras cada cambio: `php artisan config:cache`.

---

## 7. Verificación post-despliegue (smoke)

Con un entrenador piloto real (OTP por SMS):

- [ ] Miembro normal: login, Home, pagos y notificaciones **sin cambios**; no
      aparece nada de entrenador en su perfil.
- [ ] "Acceso para entrenadores" → documento → llega OTP → ingresa al portal.
- [ ] Documento inexistente o no-entrenador → mensaje **genérico** (no revela).
- [ ] Home profesional muestra rol, sede y miembros asignados (datos reales).
- [ ] Crear valoración → enviar → el **miembro la recibe** (notificación +
      pantalla de solo lectura) y **no puede editarla**.
- [ ] Corregir una valoración → crea versión nueva; la anterior queda histórica.
- [ ] Entrenador funcional: agenda real, pasar lista (sin doble marcado),
      corrección auditada.
- [ ] Perfil doble: aparece "Cambiar a Portal de entrenador" (step-up) y, desde
      el portal, "Ir a mi cuenta de miembro".
- [ ] Desde el CRM, desactivar al entrenador cierra su sesión profesional al
      instante y conserva su cuenta de miembro.

Suites automatizadas (antes de publicar):

```bash
# Backend
cd CRM/Iron-Body/backend && php artisan optimize:clear && php artisan test
# Flutter
cd APP/Iron_Body_App && flutter analyze && flutter test && flutter build apk --debug
```

---

## 8. Plan de rollback

El rollback es gradual y de bajo riesgo:

### Nivel 1 — Apagar (inmediato, sin tocar datos) ✅ recomendado primero
```dotenv
TRAINER_PORTAL_ENABLED=false
TRAINER_AUTH_ENABLED=false
TRAINER_PROFESSIONAL_ASSESSMENTS_ENABLED=false
TRAINER_CLASSES_ENABLED=false
TRAINER_WORKSPACE_SWITCHING_ENABLED=false
TRAINER_PILOT_IDENTITIES=
```
`php artisan config:cache`. El portal desaparece para todos; la app vuelve al
comportamiento actual. **Los datos quedan intactos** (recomendado sobre el
rollback de migraciones).

### Nivel 2 — Revertir el código
Volver el backend y la app al commit anterior al portal y redeploy. Las tablas
nuevas quedan huérfanas pero **no afectan** al funcionamiento previo (nada las
referencia). Pueden retirarse luego con el Nivel 3 en una ventana tranquila.

### Nivel 3 — Revertir migraciones (solo si es imprescindible)
Cada migración tiene `down()` reversible. Revierte las 11 del portal:
```bash
php artisan migrate:rollback --step=11 --force
```
Notas:
- `down()` de `backfill_identities` pone `members.identity_id` y
  `trainers.identity_id` en `null` y **vacía `identities`**.
- `down()` de las columnas elimina solo lo aditivo (`identity_id`, `location`).
- **Nunca** usar `migrate:fresh`, `db:wipe` ni resets destructivos.
- Restaurar desde el `pg_dump` del paso 3.2 solo ante corrupción.

---

## 9. Riesgos y notas

- **Documentos sucios/duplicados:** el backfill agrupa por documento
  normalizado; documentos vacíos/no normalizables reciben identidad **dedicada**
  (no se fusionan personas distintas). Revisar `identities` tras el backfill si
  la base trae documentos inconsistentes.
- **OTP del entrenador:** depende de que el `trainers.phone` esté correcto. Sin
  teléfono válido, el acceso responde de forma genérica (no se puede entrar).
- **Pendiente conocido (no bloqueante):** el enrutado de arranque por
  preferencia de espacio y el deep-link de notificación en *cold-start* de FCM
  aún no están cableados; el deep-link in-app sí funciona. La UI Angular de
  administración de entrenadores está pendiente (los endpoints backend ya
  existen y son consumibles).

---

## 10. Resultados de pruebas (referencia)

- Backend: **352 tests / 1315 assertions** verdes (incluye regresión de login de
  miembro y Wompi). Pint aplicado.
- Flutter: **flutter analyze** limpio en la feature; **21 widget tests** del
  portal verdes; `flutter build apk --debug` correcto.

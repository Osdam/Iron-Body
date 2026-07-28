# Runbook de despliegue — Moderación de comunidad (UGC)

**Estado:** preparado, NO ejecutado.
**Alcance:** 9 migraciones + backend Laravel + CRM Angular + app Flutter.

> Las rutas y nombres de servicio siguen la convención ya usada en
> `docs/NUTRITION_PRODUCTION_DEPLOY.md` (`php8.3-fpm`, `nginx`). **Confirma la
> versión de PHP-FPM y la ruta del proyecto en el servidor antes de empezar**;
> este documento no asume nada que no esté ya en uso.

Variables del runbook (ajústalas una vez y reutilízalas):

```bash
APP_DIR=/var/www/iron-body/backend        # confirmar
CRM_DIR=/var/www/iron-body/frontend       # confirmar
STAMP=$(date +%Y%m%d-%H%M%S)
BACKUP_DIR=/var/backups/ironbody
```

---

## 0. Pre-vuelo (5 min, sin tocar nada)

```bash
cd "$APP_DIR"
php artisan --version
php artisan migrate:status | tail -20      # confirma las 9 pendientes de moderación
psql "$DATABASE_URL" -tAc "SELECT version();"   # PostgreSQL >= 11 (metadata-only ADD COLUMN)
psql "$DATABASE_URL" -tAc "SELECT count(*) FROM stories;"
```

**Criterio de aborto:** si `migrate:status` muestra pendientes que **no**
reconoces (p. ej. migraciones de IVA/suscripciones aún sin desplegar), detente y
decide explícitamente si entran en esta ventana. `migrate` las aplicaría todas.

---

## 1. Respaldo de PostgreSQL y `.env`

```bash
mkdir -p "$BACKUP_DIR"

# Dump lógico completo, comprimido y verificable.
pg_dump --format=custom --no-owner --no-privileges \
  --file="$BACKUP_DIR/ironbody-$STAMP.dump" "$DATABASE_URL"

# Verificar que el dump se puede leer (no restaura nada).
pg_restore --list "$BACKUP_DIR/ironbody-$STAMP.dump" | head

# .env real (nunca al repositorio).
cp "$APP_DIR/.env" "$BACKUP_DIR/env-$STAMP.bak"
chmod 600 "$BACKUP_DIR/env-$STAMP.bak"

ls -lh "$BACKUP_DIR"
```

**No continúes si `pg_restore --list` falla.**

---

## 2. Actualización del backend desde `main`

```bash
cd "$APP_DIR"
php artisan down --retry=60 --secret="deploy-$STAMP"   # opcional; ver nota
git fetch origin
git status --short          # debe estar limpio en el servidor
git log --oneline -1
git pull --ff-only origin main
git log --oneline -1        # confirma el commit desplegado
```

> **Nota sobre modo mantenimiento.** Las migraciones son aditivas y compatibles
> con tráfico (§4), así que `php artisan down` es *opcional*. Si lo omites, el
> despliegue es sin corte. Si lo usas, recuerda `php artisan up` en el paso 6.

---

## 3. Dependencias

```bash
cd "$APP_DIR"
composer install --no-dev --optimize-autoloader --no-interaction
```

---

## 4. Migraciones

```bash
cd "$APP_DIR"

# 1) Ensayo: imprime el SQL sin ejecutarlo. Revísalo.
php artisan migrate --pretend | tee "$BACKUP_DIR/migrate-pretend-$STAMP.sql"

# 2) Ejecución real.
php artisan migrate --force

# 3) Confirmación.
php artisan migrate:status | grep 2026_07_28
```

**Por qué es seguro con tráfico activo:**

| Migración | Operación | Riesgo |
|---|---|---|
| `000001`–`000007`, `000009` | `CREATE TABLE` nuevas | Ninguno: no bloquean tablas en uso |
| `000008` | `ALTER TABLE stories ADD COLUMN` | Metadata-only en PostgreSQL ≥ 11 (el default es constante) → sin reescritura ni bloqueo largo |
| `000008` | `CREATE INDEX stories_moderation_idx` | `ACCESS SHARE` breve. Si `stories` fuera muy grande, usar `CREATE INDEX CONCURRENTLY` a mano y marcar la migración como ejecutada |

Ninguna migración hace `DROP`, `RENAME` ni `UPDATE` sobre datos existentes.

---

## 5. Limpieza y caché de configuración

```bash
cd "$APP_DIR"
php artisan config:clear && php artisan route:clear && php artisan cache:clear
php artisan config:cache
php artisan route:cache
php artisan event:cache      # solo si ya se usa en este proyecto
```

---

## 6. Reinicio de PHP y workers

```bash
sudo systemctl reload php8.3-fpm     # confirmar versión
php artisan queue:restart            # los workers recogen el código nuevo
sudo systemctl reload nginx
php artisan up                       # solo si ejecutaste `down` en el paso 2
```

**Programar la limpieza de evidencia** (una sola vez, en el crontab del deploy):

```bash
# Diario a las 03:15 — idempotente, seguro de repetir.
15 3 * * * cd /var/www/iron-body/backend && php artisan moderation:purge-evidence >> storage/logs/moderation-purge.log 2>&1
```

---

## 7. Compilación y despliegue del CRM

```bash
cd "$CRM_DIR"
npm ci
npm run build          # salida en dist/frontend
# Publicar dist/frontend según el método ya usado (rsync / raíz de nginx).
sudo systemctl reload nginx
```

---

## 8. Verificación del backend

```bash
cd "$APP_DIR"

# Rutas registradas (deben ser 20).
php artisan route:list --path=moderation | tail -3

# Tablas creadas (debe devolver 8).
psql "$DATABASE_URL" -tAc "SELECT count(*) FROM information_schema.tables
  WHERE table_name IN ('user_blocks','content_reports','report_content_snapshots',
  'moderation_actions','member_suspensions','moderation_appeals',
  'moderation_audit_logs','member_ugc_consents');"

# Columnas nuevas en stories (debe devolver 5).
psql "$DATABASE_URL" -tAc "SELECT count(*) FROM information_schema.columns
  WHERE table_name='stories' AND column_name IN
  ('moderation_state','moderated_at','moderation_reason_code','reports_count','deleted_at');"

# El feed sigue respondiendo (regresión de Stories).
curl -s -o /dev/null -w '%{http_code}\n' -H "Authorization: Bearer <token_de_prueba>" \
  https://<host>/api/app/stories

# La API admin exige credenciales (debe ser 401).
curl -s -o /dev/null -w '%{http_code}\n' https://<host>/api/admin/moderation/reports
```

---

## 9. QA manual (usuario A, usuario B, administrador)

Con los interruptores **aún apagados** (§10) solo se valida que nada se rompió:

| # | Actor | Acción | Resultado esperado |
|---|---|---|---|
| 1 | A | Publica un estado | Se publica (tras aceptar los Lineamientos) |
| 2 | B | Ve el estado de A en el feed | Visible |
| 3 | B | Menú ⋮ sobre el estado de A | Sin «Reportar» ni «Bloquear» (interruptores off) |
| 4 | A | Menú ⋮ sobre su propio estado | «Eliminar», nunca «Reportar»/«Bloquear» |
| 5 | Admin | Entra a CRM → Moderación | Carga el dashboard vacío, sin errores |

Tras activar los interruptores (§10):

| # | Actor | Acción | Resultado esperado |
|---|---|---|---|
| 6 | B | Reporta el estado de A | «Recibimos tu reporte» (**nunca** «eliminado») |
| 7 | Admin | Ve el caso en el CRM | Aparece; reportante como «Reportante confidencial» |
| 8 | Admin | Se asigna el caso y lo pasa a «En revisión» | OK |
| 9 | Admin (Super) | Abre la evidencia | URL firmada; caduca en 10 min |
| 10 | Admin | Restringe publicación 24 h | A recibe notificación con motivo público |
| 11 | A | Intenta publicar | 403 `posting_restricted` |
| 12 | A | Abre rutinas, nutrición, clases y membresía | **Todo funciona** |
| 13 | A | Perfil → Moderación y apelaciones → Apelar | Se registra la apelación |
| 14 | Admin | Resuelve la apelación aceptándola | A vuelve a publicar de inmediato |
| 15 | B | Bloquea a A | El contenido de A desaparece del feed de B |
| 16 | A | Mira su feed | Tampoco ve a B (simetría) |
| 17 | B | Perfil → Usuarios bloqueados → Desbloquear | Vuelven a verse |
| 18 | Admin | CRM → Logs / `moderation_audit_logs` | Toda la línea de tiempo registrada |

**Comprobación crítica:** en ningún punto de los pasos 7–14 debe aparecer el
nombre, id o documento de B en el CRM.

---

## 10. Activación gradual

Los interruptores viven en el `.env` del servidor y **no requieren desplegar
código**. Activa de uno en uno, con observación entre pasos.

```bash
cd "$APP_DIR"

# Paso 10.a — Bloqueo (el más seguro: sin cola de trabajo, efecto inmediato).
#   .env → UGC_BLOCKING_ENABLED=true
php artisan config:cache && sudo systemctl reload php8.3-fpm

# Observar 24 h:
psql "$DATABASE_URL" -tAc "SELECT count(*) FROM user_blocks;"

# Paso 10.b — Reportes (genera trabajo para el equipo de moderación).
#   .env → UGC_REPORTS_ENABLED=true
php artisan config:cache && sudo systemctl reload php8.3-fpm

# Observar 24-48 h:
psql "$DATABASE_URL" -tAc "SELECT status, count(*) FROM content_reports GROUP BY status;"
tail -f storage/logs/laravel.log | grep moderation.
```

**Mantener apagados de momento:**

- `UGC_AUTO_QUARANTINE_ENABLED=false` — encender solo cuando haya volumen real
  y el equipo haya calibrado el umbral.
- `UGC_POSTING_AGE_ENFORCED=false` — ver el informe de edad (Objetivo 2).

---

## 11. Rollback sin eliminar datos históricos

**Nivel 1 — Desactivación funcional (segundos, cero pérdida). Preferido.**

```bash
# .env → UGC_REPORTS_ENABLED=false / UGC_BLOCKING_ENABLED=false
php artisan config:cache && sudo systemctl reload php8.3-fpm
```

Las tablas y todos los casos, sanciones y auditoría **se conservan intactos**.
La app oculta las opciones y la API responde 503 con código estable.

**Nivel 2 — Rollback de código (mantiene el esquema y los datos).**

```bash
cd "$APP_DIR"
git log --oneline -5
git checkout <commit_anterior>
composer install --no-dev --optimize-autoloader
php artisan config:cache && php artisan route:cache
sudo systemctl reload php8.3-fpm && php artisan queue:restart
```

Las 9 tablas quedan huérfanas pero **no estorban**: ningún código antiguo las
consulta. Volver adelante es un `git checkout main`.

**Nivel 3 — Rollback del esquema (último recurso; SÍ destruye moderación).**

```bash
cd "$APP_DIR"
# Verificado en local: revierte exactamente las 9 migraciones de moderación.
php artisan migrate:rollback --step=9 --force
php artisan migrate:status | grep 2026_07_28   # deben quedar en Pending
```

Elimina las 8 tablas de moderación y las 5 columnas añadidas a `stories`.
**`stories` conserva todas sus filas y datos originales** (verificado en local).
Lo que se pierde son los casos, sanciones, apelaciones y la auditoría de
moderación — por eso es el último recurso, y por eso el respaldo del paso 1 es
obligatorio.

Rollback parcial más fino (p. ej. solo la columna de `stories`):

```bash
php artisan migrate:rollback --step=2 --force   # 000009 + 000008
```

---

## 12. Generación del AAB definitivo

**Después** de que el backend esté desplegado y verificado, nunca antes: la app
consulta `/api/app/moderation/status` al iniciar y necesita el backend nuevo.

```bash
cd /ruta/APP/Iron_Body_App
git pull --ff-only origin main
flutter clean
flutter pub get
flutter analyze                        # debe salir "No issues found!"
flutter test                           # debe salir "All tests passed!"
flutter build appbundle --release
ls -lh build/app/outputs/bundle/release/app-release.aab
```

Subida a Play Console:

1. Track **interno** primero; validar el flujo completo con cuentas reales.
2. Actualizar el cuestionario de **Contenido de la app → Contenido generado por
   usuarios** con las respuestas del informe (reportar: sí; bloquear: sí;
   desnudos: no; violencia gráfica: no; moderación de chats: no).
3. Adjuntar la URL pública de los Lineamientos de Comunidad
   (`UGC_GUIDELINES_URL`) — debe estar publicada **antes** de enviar a revisión.
4. Promover a producción por etapas (10 % → 50 % → 100 %).

---

## Checklist de cierre

- [ ] Dump verificado con `pg_restore --list`
- [ ] `.env` respaldado con permisos 600
- [ ] `migrate:status` sin pendientes de moderación
- [ ] 20 rutas registradas
- [ ] 8 tablas + 5 columnas verificadas en PostgreSQL
- [ ] Cron de `moderation:purge-evidence` programado
- [ ] CRM desplegado y módulo Moderación accesible por rol
- [ ] QA manual 1–18 superado
- [ ] Interruptores activados por etapas
- [ ] Lineamientos publicados en la URL pública
- [ ] AAB generado **después** del backend

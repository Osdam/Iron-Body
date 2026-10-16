# Informe de respaldos — despliegue «personal y traslados» (16-10-2026)

Estados: **IMPLEMENTADO** (existe) · **PROBADO** (verificado con evidencia) · **PENDIENTE** (decisión del propietario).

## 1. Respaldo previo al despliegue del 16-10-2026 (04:56 UTC)

| Dato | Valor |
|---|---|
| Ubicación | `/var/backups/ironbody/pre-personal-traslados-20261016T045652Z/` (VPS `ironbody-vps`) |
| Permisos | Directorio `root:root 700`; ficheros `600`. No es accesible desde nginx ni por `www-data`. |
| Tamaño total | 97 MB |
| Integridad | `SHA256SUMS` con todos los ficheros; `sha256sum -c` → **OK** |

Contenido:

| Fichero | Qué es |
|---|---|
| `ironbody.dump` | `pg_dump -Fc` de la base `ironbody` (29.551.480 bytes; 190 tablas con datos) |
| `versions.txt` | HEAD de la API (`808516d`), de `/var/www/app-source` (`3701558`), sello del CRM publicado (`3701558`, `main-LBAQSO2P.js`) |
| `app-published.tgz` | Copia del CRM publicado (`/var/www/app`, 1.022 ficheros) |
| `config/` | `.env` de la API, cachés `bootstrap/cache/*.php`, sitios nginx `api` y `app`, `ironbody-workers.conf` (supervisor). **Contiene secretos**: no se imprime ni se copia fuera del servidor. |
| `migrations.txt` | 297 migraciones aplicadas (antes del despliegue) |
| `services.txt` | Estado de nginx, php8.3-fpm, postgresql, supervisor, cron y de los 12 workers |
| `api-tracked-status.txt`, `app-source-tracked-status.txt` | Cambios rastreados sin commit en el servidor (API: ninguno; app-source: `package-lock.json`, del `npm install` del 29-09, conservado) |

## 2. Verificación de restauración — **PROBADO**

Restauración real en un clúster PostgreSQL 14 **aislado** (otro directorio, puerto 55440, sin TCP: solo socket en su propio directorio), con `pg_restore --no-owner --no-privileges`:

- Errores de `pg_restore`: **0**.
- Recuentos restaurado = producción en el momento de la verificación:

| Tabla | Filas |
|---|---|
| migrations | 297 |
| users | 3.990 |
| members | 3.990 |
| payments | 10.215 |
| plans | 26 |
| receivables | 125 |
| electronic_invoices | 854 |
| employee_accesses | 5 |
| identities | 14 |
| staff_profiles | 0 |
| audit_logs | 2.991 |
| cash_shifts | 148 |

Clúster de verificación: **parado**, en `/var/lib/postgresql/restore-check-pre-personal-traslados-20261016T045652Z` (1,1 GB, propietario `postgres`, 700). **No se ha borrado**: contiene una copia completa de datos personales; borrarlo es decisión del propietario (`rm -rf` de ese directorio con el clúster parado).

## 3. Copia del CRM publicado anterior

`npm run deploy -- --backup` dejó `/var/www/app.prev-20261016050135` (copia íntegra de `/var/www/app` antes de publicar `5886982`). Además, el despliegue no borra ficheros: el `main-LBAQSO2P.js` anterior sigue en `/var/www/app`.

## 4. Respaldos anteriores (no tocados)

`/var/backups/ironbody/`: `ironbody-pre-asistencia-laboral-20261011T050501Z.dump`, `ironbody-pre-autoclose-20260903-173807.sql.gz`, `lucena-users4519-pre-trimestre-20261011T055144Z.json`. Copias del CRM `/var/www/app.prev-2026100703…`, `…044647`, `…11010534`. Ninguna se ha borrado.

## 5. Cómo restaurar (solo con autorización y evaluando las escrituras posteriores)

- **Código de la API**: `cd /var/www/api/backend && git checkout --detach 808516d` (como root) → `sudo -u www-data php artisan config:cache && chmod 600 bootstrap/cache/config.php && sudo -u www-data php artisan route:cache && sudo -u www-data php artisan queue:restart`. Las dos tablas nuevas pueden quedarse: el código anterior no las usa.
- **CRM**: `rsync -a /var/www/app.prev-20261016050135/ /var/www/app/` (sin `--delete`).
- **Base de datos**: NO restaurar el volcado sobre la base viva sin analizar lo escrito después (cobros, asistencias, jornadas). Si hiciera falta, restaurar en una base aparte y extraer lo necesario.

## 6. Respaldo previo al despliegue de cierre de seguridad (16-10-2026, 12:26 UTC) — **PROBADO**

| Dato | Valor |
|---|---|
| Ubicación | `/var/backups/ironbody/pre-cierre-seguridad-20261016T122613Z/` (root, 700/600) |
| Tamaño | 98 MB (`ironbody.dump` 29.586.238 bytes, 192 tablas con datos; CRM 1.061 ficheros) |
| Versiones | API `b1ce780`, app-source `5886982`, CRM publicado `5886982` (`main-OG4FYQ3U.js`) |
| Integridad | `sha256sum -c` → **OK** |
| Restauración aislada | 0 errores; migrations 299, users 3.992, members 3.992, payments 10.220, plans 26, receivables 127, electronic_invoices 858, employee_accesses 5, identities 15, staff_profiles 1, audit_logs 3.012, cash_shifts 150 — todos iguales a producción |
| Clúster de verificación | **parado**, `/var/lib/postgresql/restore-check-pre-cierre-seguridad-20261016T122613Z` (1,1 GB). No borrado (decisión del propietario) |

Copia del CRM publicado anterior: `/var/www/app.prev-20261016123825`.

## 7. Respaldo previo a la segunda iteración (16-10-2026, 15:50 UTC) — integridad PROBADA

`/var/backups/ironbody/pre-endurecimiento-2-20261016T155026Z/` (98 MB; `ironbody.dump` 29.693.079 bytes; API `43e6206`). `sha256sum -c` → OK; `pg_restore --list` → 192 tablas con datos. No se creó un tercer clúster de restauración: el despliegue era solo de código (sin migraciones) y la restauración completa con el mismo procedimiento se había probado tres horas antes.

## 8. Espacio

Disco del VPS: 194 GB, ~21 GB usados antes de estos respaldos. Los dos respaldos (≈195 MB) y los dos clústeres de verificación (≈2,2 GB) no comprometen el espacio.

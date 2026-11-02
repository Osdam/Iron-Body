# ULTRON STUDIO: lista de comprobaciones operativas

**Qué es.** Lo que hay que mirar a diario, cada semana y antes de cada fase, con el comando exacto, lo esperado y qué hacer si no sale.

**Para quién.** Quien opera el servidor (`ssh ironbody-vps`, root) y el propietario, que autoriza. La guía de uso del CRM está en `ULTRON_STUDIO_GUIA_ADMINISTRACION.md`; los runbooks completos, en `ULTRON_STUDIO_DEPLOYMENT_PLAN.md` y `ULTRON_STUDIO_ROLLBACK.md`.

**Tipo.** Operativo (lista de comprobación). **Fecha:** 02-11-2026, escrita sobre el backend `5ed9525` y revisada sobre `3fbb155` (con `1c76a7f`, F-1 y F-2 y la primera pasada de REV7), con los scripts de `docs/ultron-studio/ops/` confirmados en esa rama. **Donde esta lista y un script no coincidan, manda el script.**

## Reglas

- **Todo lo de §1 y §2 es de solo lectura.** Lo que escribe (un despliegue, un mando de alcance, un smoke, encender el monitor) va en §3 y **exige autorización**.
- Artisan, siempre como `www-data` y desde `/var/www/api/backend`: `sudo -u www-data php artisan …`. Nunca como root.
- **Nunca se imprime** el fichero de entorno, el id de la conversación canaria, teléfonos, `signature` ni `effective_json`. Las consultas SQL no los seleccionan.
- Si el log de Laravel es diario (`laravel-AAAA-MM-DD.log`), se usa el fichero del día.
- La base guarda UTC; la ventana de publicación (07:00–19:00) y el día del presupuesto del laboratorio son de Bogotá (UTC−5).

## 1. Diario (unos 5 minutos)

```bash
cd /var/www/api/backend
supervisorctl status                                                               # 13 RUNNING y ningún BACKOFF/FATAL
systemctl is-active ironbody-billing-worker                                        # active
sudo -u postgres psql -X -Atq -d ironbody -c "SELECT count(*) FROM failed_jobs;"   # no crece (2 el 02-11-2026)
grep -c 'production.ERROR' storage/logs/laravel.log                                # sin saltos frente al día anterior (345 el 02-11-2026 05:08 UTC)
sudo -u www-data php artisan ultron-studio:publication-check                       # código 0
sudo -u www-data php artisan ultron-studio:integrations-doctor --expect=twilio:connected_otp_only,twilio_voice:not_configured,elevenlabs:ELEVENLABS_NOT_CONFIGURED   # código 0
sudo -u www-data php artisan ultron-studio:integrations-doctor --expect=meta_ads:connected_read                   # código 0 (si sale degraded: anotar el motivo; es un dato)
sudo -u www-data php artisan voice:doctor                                          # Estado: ELEVENLABS_NOT_CONFIGURED
sudo -u www-data php artisan queue:health                                          # «Todos los carriles al día.» y código 0
sudo -u www-data php artisan security:check-secret-exposure                        # código 0
sudo -u postgres psql -X -Atq -d ironbody -c "SELECT count(*) FROM marketing_ai_actions WHERE metadata ? 'studio';"   # 0 mientras el motor esté en off (fase 1 del H5)
cat /var/lib/ironbody/backup-status.json                                           # el respaldo de las 03:15 (Bogotá) sin fallo y de menos de 30 h
```

| Si sale… | Qué significa | Qué hacer |
|---|---|---|
| Menos de 13 RUNNING, o algún BACKOFF/FATAL | Un worker caído. Además, **un worker muerto con un trabajo reservado no lo ve `queue:health` ni IRON GUARD** (H-7 de OPS7, abierto): esta línea es la única que lo enseña | `stat -c '%U %a' bootstrap/cache/*.php` (tienen que ser `www-data 600`). Reiniciar workers (`supervisorctl restart …`) reinicia servicios productivos: **solo con autorización** |
| `failed_jobs` crece | Un trabajo falló | Mirar la cola y la clase del error sin imprimir el cuerpo; avisar a `backend` |
| `publication-check` con 1 | Deriva entre lo declarado y el motor, una cabeza que no verifica o una lectura fallida | Ver `ULTRON_STUDIO_ROLLBACK.md`, «Hito 5» («la cabeza no verifica»). No reparar con SQL |
| Un `integrations-doctor` con 1 | Una integración ya no es la congelada en el H6 (por ejemplo, una pieza de ElevenLabs en el entorno) | Revisar la configuración sin imprimir valores; avisar al lead |
| `voice:doctor` distinto de `ELEVENLABS_NOT_CONFIGURED` | Alguien puso piezas de ElevenLabs | Parar y avisar: encender voz exige autorización expresa aparte |
| `queue:health` con 1 | Un carril con cola sin atender | Mirar `supervisorctl status`; avisar a `devops` |
| `security:check-secret-exposure` con 1 | Un fichero de secretos legible de más | Avisar a `security`. Ojo: hoy vigila 18 claves y deja fuera 13 (H-4 de SEC7) |
| Sellos `studio` > 0 con el motor en `off` | El motor está leyendo la cadena sin autorización | Avisar al lead de inmediato |
| El respaldo falló o tiene más de 30 h | IRON GUARD abre `backup_unhealthy` | `docs/ops/BACKUPS.md`, «Problemas» |

**Costes del día** (dónde se miran; ninguno exige consola):
- **Laboratorio:** CRM › laboratorio, cabecera del presupuesto (tope de 2 US$ al día de Bogotá y 0,50 US$ por ejecución) y el panel de uso del proyecto de OpenAI del laboratorio.
- **OTP de Twilio:** CRM › Integraciones › Twilio (coste del día). Techos `TWILIO_DAILY_SOFT_LIMIT_USD`/`TWILIO_DAILY_HARD_LIMIT_USD` (6/12 US$); detalle y palancas en `docs/ops/TWILIO-CONTROL-DE-COSTE.md`.
- **OpenAI del motor:** el panel de su proyecto; las palancas son `MARKETING_ULTRON_ENABLED` y la pausa del agente en el CRM.

**Con el Hito 7 desplegado** (hoy no lo está), además:

```bash
sudo -u www-data php artisan ultron-studio:monitor --dry-run                       # código 0: publicación, integraciones y colas, sin abrir incidentes
sudo -u postgres psql -X -Atq -d ironbody -c "SELECT count(*) FROM incidents WHERE source = 'ultron_studio';"   # con el monitor encendido: mirar los nuevos en IRON GUARD
```

`ultron-studio:monitor` sale con 0 si todo cuadra, con 1 si algo no cuadra y con 2 si se usa mal. Con el monitor encendido abre incidentes `publication_drift` (alto), `integrations_drift` (alto) y `monitor_check_failed` (medio); las colas no abren incidente propio (ya lo hace `iron-guard:scan`).

## 2. Semanal

```bash
systemctl list-timers 'ironbody-db-*'                                              # los dos temporizadores programados
cat /var/lib/ironbody/restore-test-status.json                                     # la verificación de restauración del lunes 04:30 (Bogotá), sin fallo y de menos de 10 días
stat -c '%a %U %n' /var/backups/ironbody/*                                         # respaldos de despliegue: directorios 700 de root
sudo -u postgres psql -X -Atq -d ironbody -c "SELECT max(started_at) FROM meta_sync_runs;"   # la sincronización horaria de Meta sigue sumando pasadas (hora UTC)
df -h /                                                                            # espacio en disco
ls -l /root/env-pre-* 2>/dev/null                                                  # copias del fichero de entorno que dejan los mandos: solo listar
sudo -u postgres psql -X -Atq -d ironbody -c "SELECT event, count(*) FROM ultron_studio_audit_events WHERE event LIKE '%four_eyes_denied' AND created_at > now() - interval '7 days' GROUP BY event;"   # intentos de saltarse los cuatro ojos en la semana
```

- **Respaldos de despliegue en 755:** los de G y del Hito 4 se crearon sin `umask 077` (comprobado por el lead el 31-10-2026). Endurecerlos (`chmod 700` el directorio y `chmod 600` el volcado) es del propietario, con autorización. **Nunca se borra un respaldo.**
- **Copias del fichero de entorno** (`/root/env-pre-*`, 600 de root): llevan secretos. Quitarlas lo decide el propietario.
- **Intentos de cuatro ojos denegados:** cada uno es una cuenta que quiso aprobar, publicar o autorizar lo suyo. Uno suelto es un error; varios seguidos, una conversación con el propietario.

## 3. Antes de cada fase

### 3.1 Común a todas

- [ ] **Autorización** del propietario para esa fase, por escrito, con la fecha.
- [ ] **La fase anterior estable 24 horas:** 0 ERROR nuevos del Studio, 0 respuestas 5xx en `/api/admin`, 13/0 workers y colas fallidas sin crecer (§1).
- [ ] **Unos 15 minutos sin nadie en el Studio** si hay migración: piden ACCESS EXCLUSIVE, y una pestaña abierta con una lectura basta para un 55P03.
- [ ] **Respaldo de menos de 2 horas**, después de cualquier cambio a mano en el fichero de entorno (el script exige que sean iguales).
- [ ] **Los scripts copiados desde el Mac con sus huellas** (`shasum -a 256` en el Mac igual a `sha256sum` en el servidor). Los smokes **borran sus ficheros de `/root/` al terminar**: se copian otra vez antes de cada ejecución.
- [ ] **Cupos de los smokes** (todos usan la cuenta temporal admin id 32 y la dejan inactiva, sin sesiones):

| Smoke | Cupo | Por qué |
|---|---|---|
| `smoke-run-h5.sh` | 3 por minuto | El login: 8 por minuto e IP |
| `smoke-run-h6.sh` | **3 por hora** y 3 por minuto | El cupo de la sonda (6/h por persona) corre antes de autenticar y cada smoke gasta 2: una cuarta ejecución en la hora da 429 y cuenta como FALLO (H-3 de DevOps del H6) |
| `smoke-run-h7.sh` | 3 por minuto | El login. No toca la sonda (cabecera de `smoke-run-h7.sh`) |

### 3.2 Despliegue del Hito 7 (backend; el CRM no cambia)

Runbook completo en `ULTRON_STUDIO_DEPLOYMENT_PLAN.md`, «Hito 7». Condiciones:

- [ ] El H6 en su fase 1, **verificado a las 24 horas**. [PENDIENTE: verificación de las 24 h del H6, hacia el 03-11-2026 05:00 UTC (lead).]
- [ ] **REV7 sin bloqueantes.** Primera pasada (`3fbb155`): APROBADO CON OBSERVACIONES, sin bloqueantes; M-1 decidida (opción b) y en corrección por `security`. [PENDIENTE: la segunda pasada del `reviewer` (`plans/informes-h7/informe-reviewer.md` §9).]
- [ ] **El commit desplegado lleva la corrección de M-1** (la clase nueva de invisibles solo en el Studio; fuera, en la agenda de valoraciones y en la Caja, nada cambia). [PENDIENTE: el commit de `security` (lead).]
- [ ] **TEST7 completo.** Fases A (`1426130`) y B (`df0140f`) hechas: suite por nombre 57 = 57 rojos (0 nuevos), CRM 3 312/3 328 (los 16 de la línea base), E2E 660/660, recuperación 21/21 y vuelta atrás 13/13. [PENDIENTE: la fase C sobre el código final (`tester`).]
- [x] **F-1 y F-2 de PERF7** hechos en `e2411e3`: la evidencia del canario, p95 de 1 050 a 70 ms con 200 turnos; las campañas, de 36,5 a 27,2 ms; los dos sin cambiar la respuesta.
- [x] **`PREVIO` fijado en un commit:** `1e5f9a0`, en `1c76a7f`. Si producción se mueve antes del despliegue (`git -C /var/www/api rev-parse --short=7 HEAD` en el servidor), el paso 0 aborta: se actualiza `PREVIO` en otro commit y se vuelve a copiar el script.
- [ ] Push con avance rápido a `main`; nunca `--force`.

Pasos (servidor, root; el detalle y lo esperado, en el runbook):

```bash
# 1. Respaldo (bloque completo en el runbook: umask 077 en una subshell, ≥ 226 tablas con datos)
# 2. Scripts (desde el Mac, con el árbol limpio en el commit que se despliega)
O=docs/ultron-studio/ops
shasum -a 256 $O/deploy-h7-backend.sh $O/h7-schema-check.sql $O/smoke-h7.py $O/smoke-run-h7.sh $O/smoke-h6.py
scp $O/deploy-h7-backend.sh $O/h7-schema-check.sql $O/smoke-h7.py $O/smoke-run-h7.sh $O/smoke-h6.py ironbody-vps:/root/
# 3. Esquema antes (solo lectura): 43/49/4/14, rotas 0, existe_rol_app 1 y 0 filas de cerrojos
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v owner=iron -v app=iron_app -x -d ironbody < /root/h7-schema-check.sql
# 4. Ensayo → COMPROBACIONES PREVIAS EN VERDE (no se ha tocado nada)
bash /root/deploy-h7-backend.sh <commit> "$BK" --solo-comprobar
# 5. Despliegue → DESPLIEGUE H7 (BACKEND) COMPLETADO
bash /root/deploy-h7-backend.sh <commit> "$BK" 2>&1 | tee /root/h7-$(date -u +%Y%m%d-%H%M%S).log
# 6. Esquema después: 44/50, uspub_chain_next:O y iron_app en ar en la cadena, el libro y las instantáneas
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v owner=iron -v app=iron_app -x -d ironbody < /root/h7-schema-check.sql
# 7. Smoke del H7 → código 0 (borra smoke-h7.py, smoke-h6.py y su lanzador al terminar)
bash /root/smoke-run-h7.sh
# 8. Regresión del H6 (copiar antes smoke-h6.py y smoke-run-h6.sh otra vez; 3 por hora) → código 0, 78 + 26 OK
SMOKE_PHASE=1 bash /root/smoke-run-h6.sh
```

- [ ] **Si se rehace el respaldo en una reanudación, `api-commit.txt` = `PREVIO`:** `git -C /var/www/api rev-parse 1e5f9a0 > "$BK/api-commit.txt"`. El paso 0 lo exige siempre (`deploy-h7-backend.sh:157-160`, corregido en `1c76a7f`), así que ya no hace falta comprobarlo a mano.
- [ ] **Vigilar el paso 3 (la migración):** el `lock_timeout` de 5 s solo llega si el envoltorio de hoy conserva `PGOPTIONS` (N-10, sin comprobar en el servidor). Si no avanza, mirar los cerrojos de la cadena con `h7-schema-check.sql` desde otra sesión.
- [ ] **A los 15 minutos y a las 24 horas:** el bloque del runbook (monitor en seco, workers, `failed_jobs`, ERROR, 5xx y `Cache-Control: no-store, private`), y a los 15 minutos la sonda de la clase de invisibles en el PCRE 10.39 del servidor (SEC7 §4.7; la última línea del bloque) → `string(6) "U+00AD"` y `NULL`.

### 3.3 Encender el monitor del Hito 7

- [ ] El H7 desplegado y estable a las 24 h.
- [ ] **H-1 de OPS7 comprobado:** si `schedule:run` está en el crontab de root, cada tarea programada (el monitor incluido) carga como root las cachés de `www-data` cada minuto. Comprobarlo (solo lectura) y moverlo al crontab de `www-data` es del propietario (paso 6b de `ironbody-migrate-propuesta.sh`):

```bash
crontab -l -u root | grep -n 'schedule:run'; crontab -l -u www-data 2>/dev/null | grep -n 'schedule:run'
```

- [ ] Saber que, con el monitor encendido, **cambiar una bandera de integraciones abre un incidente de deriva** hasta que se reconozca otra vez la configuración (la huella de Twilio incluye la sonda; H-1 de DevOps del H6). Es una deducción de esas dos piezas, no un ensayo.
- [ ] Los pasos (copia del fichero de entorno en 600, `ULTRON_STUDIO_MONITOR_ENABLED=true`, caché como `www-data`, `schedule:list` con 2 tareas del Studio y una primera pasada a 0) están en el runbook, «Hito 7», paso 10. **No hay script para este mando.**

### 3.4 Fase 2 del Hito 6 (escrituras, T1, sonda y configuración autorizada)

Runbook: `ULTRON_STUDIO_DEPLOYMENT_PLAN.md`, «Hito 6», «Fase 2». Procedimiento con personas: `ULTRON_STUDIO_PRUEBAS_FISICAS.md` §5 (escrito el 01-11-2026, cuando el H6 no estaba desplegado: sus pasos de CRM siguen valiendo).

- [ ] **`log_error_verbosity = terse` en PostgreSQL** (propietario, como `postgres`, sin reinicio; `plans/informes-h7/informe-db.md` §9): con el valor por defecto, un 23505 o un 23514 de los canarios deja la huella del teléfono, o la fila entera, en el log del servidor.

```sql
ALTER SYSTEM SET log_error_verbosity = 'terse';
SELECT pg_reload_conf();
SHOW log_error_verbosity;               -- terse
```

- [ ] **El rol `iron_app` en READ COMMITTED** (O-2 del tester del H6; con REPEATABLE READ, 7 de 37 carreras darían 500). [PENDIENTE: comprobación de solo lectura en producción (`devops`).]
- [ ] **`opcache.validate_timestamps` del pool de FPM**, comprobado una vez (N-4 a). [PENDIENTE (`devops`).]
- [ ] **Dos personas reales:** A tiene el teléfono de la conversación canaria; B es Super Admin con `ultron.canary_authorize` y distinta de A. Dos personas, no dos cuentas; nunca la cuenta de smoke.
- [ ] El orden: `writes on` → smoke de la fase 2 → T1 (A registra; B autoriza y activa: `in_sync`) → `probe on` → una pulsación de la sonda → **reconocer la configuración de los 3 proveedores después de `probe on`** → otro smoke de la fase 2.

### 3.5 Fase 2 del Hito 5 (canario) y validación 2 del Hito 4

Runbook: `ULTRON_STUDIO_DEPLOYMENT_PLAN.md`, «Hito 5», «Fase 2». Personas: `ULTRON_STUDIO_PRUEBAS_FISICAS.md` §4.3.

- [ ] **La puerta del laboratorio** (sigue en `required` por decisión del propietario del 01-11-2026): **una ejecución del laboratorio sobre la versión que se va a publicar y un veredicto `approved` de una persona distinta de la autora**. Es a la vez la **validación 2 del Hito 4** (`HITO_4_COMPLETED`) y la condición de la primera publicación. Con el laboratorio en `openai` desde el 02-11-2026, ya es posible.
- [ ] Una versión que refleje el motor tal como está (`GET engine/readings`), creada por A y aprobada por B.
- [ ] Quien publica, con `ultron.publish` y distinto de A, nombrado por el propietario.
- [ ] El motor con canario y el freno suelto.
- [ ] Dentro de la ventana: de 07:00 a 19:00 de Bogotá.
- [ ] El mando: `bash /root/h5-engine-scope.sh canary off` → `ALCANCE canary APLICADO (copia previa: …)`; después `SMOKE_PHASE=2 bash /root/smoke-run-h5.sh` → código 0, antes de publicar.

### 3.6 Fase 3 del Hito 5 (todas y promoción)

- [ ] **La evidencia del canario pasa** (`passes: true` en «Promover a todas»): 24 h, 3 turnos, todos sellados con la versión, sin frenos, acta a cero, sin deriva y la firma correcta. **Ningún sello `fallback`** en la ventana.
- [ ] `bash /root/h5-engine-scope.sh all canary` → `ALCANCE all APLICADO`; `SMOKE_PHASE=3 bash /root/smoke-run-h5.sh` → 0; promover dentro de la ventana, otra persona que la autora; `publication-check` → 0.

### 3.7 Antes de restaurar un respaldo

- [ ] Autorización expresa y `database-specialist` coordinando; **nunca encima de la base viva**.
- [ ] **Con dueños**, no con `--no-owner` (H-6 de OPS7; corregido en `docs/ops/BACKUPS.md` el 02-11-2026): con `--no-owner` las tablas quedan del superusuario y el siguiente `ironbody-migrate` falla con 42501.
- [ ] **Nunca `pg_restore --data-only`** sobre el esquema ya creado: la cadena de publicaciones tiene un disparador que exige el orden de `seq` (R-4 de DB7).
- [ ] Volver a aplicar después las supresiones de la Ley 1581 hechas desde la fecha del volcado (`ULTRON_STUDIO_GUIA_ADMINISTRACION.md` §10).

## 4. Umbrales, de un vistazo

| Señal | Bien | Fuente del umbral |
|---|---|---|
| Workers | 13 RUNNING, 0 BACKOFF/FATAL | Los scripts de despliegue (`workers()`) |
| `failed_jobs` | No crece | Scripts de despliegue |
| ERROR del log | 0 nuevos frente al valor anterior | Scripts de despliegue (paso 12) |
| `publication-check`, `integrations-doctor`, `queue:health`, `security:check-secret-exposure`, `ultron-studio:monitor --dry-run` | Código 0 | Sus comandos |
| `voice:doctor` | `ELEVENLABS_NOT_CONFIGURED` | Contrato H6 |
| Sellos `studio` con el motor en `off` | 0 | Contrato H5 A4.3 |
| `Cache-Control` de `/api/admin/*` (con el H7) | `no-store, private` | `deploy-h7-backend.sh`, paso 9 |
| Respaldo diario | Sin fallo y < 30 h | IRON GUARD (`docs/ops/BACKUPS.md`) |
| Verificación de restauración | Sin fallo y < 10 días | IRON GUARD |
| Laboratorio | ≤ 2 US$ al día y ≤ 0,50 US$ por ejecución (lo para el servidor) | Configuración de la fase 3 del H4 |
| OTP | Por debajo del techo blando de 6 US$ al día | `TWILIO-CONTROL-DE-COSTE.md` |
| Latencias del Studio (con el H7) | Techos iniciales de `ops/perf-h7/presupuestos-h7.json` (p. ej. lecturas p95 ≤ 250 ms; la evidencia del canario p95 ≤ 2 500 ms y ≤ 400 ms tras F-1, que ya está hecho: 70 ms con 200 turnos en local) | PERF7 §7. **No implementado en el smoke:** `smoke-h7.py` no carga ese fichero. [PENDIENTE: incorporarlo y calibrar con la primera pasada en producción (`devops`).] |

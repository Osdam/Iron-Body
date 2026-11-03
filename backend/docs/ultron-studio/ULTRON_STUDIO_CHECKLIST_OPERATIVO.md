# ULTRON STUDIO: lista de comprobaciones operativas

**Qué es.** Lo que hay que mirar a diario, cada semana y antes de cada fase, con el comando exacto, lo esperado y qué hacer si no sale.

**Para quién.** Quien opera el servidor (`ssh ironbody-vps`, root) y el propietario, que autoriza. La guía de uso del CRM está en `ULTRON_STUDIO_GUIA_ADMINISTRACION.md`; los runbooks completos, en `ULTRON_STUDIO_DEPLOYMENT_PLAN.md` y `ULTRON_STUDIO_ROLLBACK.md`.

**Tipo.** Operativo (lista de comprobación). **Fecha:** 02-11-2026, escrita sobre el backend `5ed9525` y revisada sobre `3fbb155` (con `1c76a7f`, F-1 y F-2 y la primera pasada de REV7) y sobre `cdade1e` (`TAREAS`, los presupuestos en el smoke, el mando del monitor, M-1 y la fase C de TEST7), con los scripts de `docs/ultron-studio/ops/` confirmados en esa rama. **Donde esta lista y un script no coincidan, manda el script.**

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
| `smoke-run-h7.sh` | 3 por minuto | El login. No toca la sonda (cabecera de `smoke-run-h7.sh`). Su fase de presupuestos no pasa de 90 lecturas con sesión por minuto (cupo de 120) y de 8 evidencias por minuto (cupo de 10); cada pasada dura unos 2 minutos |

### 3.2 Despliegue del Hito 7 (backend; el CRM no cambia)

Runbook completo en `ULTRON_STUDIO_DEPLOYMENT_PLAN.md`, «Hito 7». Condiciones:

- [x] El H6 en su fase 1, **verificado a las 24 horas**. **Hecho de forma equivalente (lead, 03-11-2026 20:00 UTC, a las 39 horas; la secuencia programada para las 05:00 UTC no corrió porque el Mac del lead se durmió):** 13 workers RUNNING, `failed_jobs` 2 (igual), tablas del H6 `0 | 0 | 3`, `integrations-doctor` en orden (según `ultron-studio:monitor --dry-run`), 0 sellos, y los únicos ERROR nuevos del registro son 45 `42P01` sobre `receivable_payment_splits` entre las 16:51 y las 16:55 UTC, de un despliegue ajeno (ver «Hito 7: ejecución real»). El smoke de la fase 1 del H6 repetido ese día: 78 + 26 OK, 0 FALLO.
- [ ] **REV7 sin bloqueantes.** Primera pasada (`3fbb155`): APROBADO CON OBSERVACIONES, sin bloqueantes; M-1 y m-3 corregidos en `601398c`, m-1 en `7c838ca` y m-2 en la documentación. **Segunda pasada (`4166d94`, §9): APROBADO CON OBSERVACIONES, sin bloqueantes ni mayores abiertos**; menores nuevos m-4 (el modo 600 de los mandos, §3.3; abierto) y m-5 (Dotenv en el paso 0; corregido en `285a585`). Condiciones antes del paso 5: la fase D en verde y la tercera pasada de DOC7 revisada. [PENDIENTE: la revisión de la tercera pasada de DOC7 (`reviewer`).]
- [x] **El commit desplegado lleva la corrección de M-1** (`601398c`, en la rama: la clase nueva de invisibles solo en el Studio; fuera, en la agenda de valoraciones y en la Caja, nada cambia). Basta con desplegar el HEAD de la rama o uno posterior.
- [x] **TEST7 completo.** Fases A (`1426130`), B (`df0140f`) y C (`cdade1e`) hechas; la C, tras F-1 y F-2: PostgreSQL 2 881/2 881, el motor 5 015/5 015 con las dos llaves, la suite por nombre 57 = 57 (0 nuevos, 195 pruebas nuevas) y el E2E 660/660. **Fase D hecha (`6399d9e`, `informe-tester.md` §3ter; sobre el HEAD final con M-1, `601398c`):** puerta PostgreSQL 3 854 pruebas con 2 rojos del entorno (`SecretExposureTest`, la instantánea no es un repo git, y `ValuationOverlapGuardMigrationTest`, que exige un rol sin superusuario; los dos fallan igual sobre `1e5f9a0`); comparación por nombre contra `1e5f9a0` 57 = 57 rojos y **0 nuevos** (239 pruebas nuevas, 44 de M-1); E2E completo **660/660** (el primer intento cayó por saturación del arnés, no por el código); mutaciones de invisibles y `PlainText` 5/5 cazadas.
- [x] **`TAREAS` fijado en un commit** en `deploy-h7-backend.sh` y en `smoke-run-h7.sh`, con el mismo número: el total de `schedule:list` de producción con el monitor apagado. Con `pendiente`, el despliegue y el smoke se negaban (código 2). **`TAREAS=39`** en los dos: en `57b1178` el despliegue y en `08bdd1d` el smoke, como valor por defecto. Se leyó el 02-11-2026 a las 17:35 UTC: 0 del Studio, 41 con el monitor encendido. Si el programador cambia antes de desplegar, el paso 4 aborta y se vuelve a fijar.
- [x] **F-1 y F-2 de PERF7** hechos en `e2411e3`: la evidencia del canario, p95 de 1 050 a 70 ms con 200 turnos; las campañas, de 36,5 a 27,2 ms; los dos sin cambiar la respuesta.
- [x] **`PREVIO` fijado en un commit:** `1e5f9a0`, en `1c76a7f`. Si producción se mueve antes del despliegue (`git -C /var/www/api rev-parse --short=7 HEAD` en el servidor), el paso 0 aborta: se actualiza `PREVIO` en otro commit y se vuelve a copiar el script.
- [ ] Push con avance rápido a `main`; nunca `--force`.

Pasos (servidor, root; el detalle y lo esperado, en el runbook):

```bash
# 1. Respaldo (bloque completo en el runbook: umask 077 en una subshell, ≥ 226 tablas con datos)
# 2. Scripts (desde el Mac, con el árbol limpio en el commit que se despliega): son 7; smoke-h6.py también,
#    porque smoke-h7.py usa su detector de fugas y sin él el smoke sale con 2 (smoke-run-h7.sh:51)
O=docs/ultron-studio/ops
shasum -a 256 $O/deploy-h7-backend.sh $O/h7-schema-check.sql $O/smoke-h7.py $O/smoke-run-h7.sh $O/smoke-h6.py $O/perf-h7/presupuestos-h7.json $O/h7-monitor-scope.sh
scp $O/deploy-h7-backend.sh $O/h7-schema-check.sql $O/smoke-h7.py $O/smoke-run-h7.sh $O/smoke-h6.py $O/perf-h7/presupuestos-h7.json $O/h7-monitor-scope.sh ironbody-vps:/root/
# 3. Esquema antes (solo lectura): 43/49/4/14, rotas 0, existe_rol_app 1 y 0 filas de cerrojos
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v owner=iron -v app=iron_app -x -d ironbody < /root/h7-schema-check.sql
# 4. Ensayo → COMPROBACIONES PREVIAS EN VERDE, con «programador 39 tareas (TAREAS=39), 0 del Studio» (TAREAS ya fijado
#    en los dos scripts). Si el total cambió, aborta: se fija el nuevo en los dos en un commit, se copian otra vez y se repite
bash /root/deploy-h7-backend.sh <commit> "$BK" --solo-comprobar
# 5. Despliegue → DESPLIEGUE H7 (BACKEND) COMPLETADO
bash /root/deploy-h7-backend.sh <commit> "$BK" 2>&1 | tee /root/h7-$(date -u +%Y%m%d-%H%M%S).log
# 6. Esquema después: 44/50, uspub_chain_next:O y iron_app en ar en la cadena, el libro y las instantáneas
sudo -u postgres psql -X -v ON_ERROR_STOP=1 -v owner=iron -v app=iron_app -x -d ironbody < /root/h7-schema-check.sql
# 7. Smoke del H7, la primera pasada con la línea base de los presupuestos → código 0, o 3 si lo único
#    OMITIDO son presupuestos por estado (sin versión en CANARY, la evidencia). Borra al terminar sus copias de /root
SMOKE_BUDGETS_OUT=/root/h7-presupuestos-medidos-$(date -u +%Y%m%d).json bash /root/smoke-run-h7.sh
# 8. Regresión del H6 (copiar antes smoke-h6.py y smoke-run-h6.sh otra vez; 3 por hora) → código 0, 78 + 26 OK
SMOKE_PHASE=1 bash /root/smoke-run-h6.sh
```

- [ ] **Si se rehace el respaldo en una reanudación, `api-commit.txt` = `PREVIO`:** `git -C /var/www/api rev-parse 1e5f9a0 > "$BK/api-commit.txt"`. El paso 0 lo exige siempre (`deploy-h7-backend.sh:157-160`, corregido en `1c76a7f`), así que ya no hace falta comprobarlo a mano.
- [ ] **El smoke:** se lee la línea `PRESUPUESTOS:` y el motivo de cada OMITIDO. Un FALLO de tiempo ya se repitió una vez dentro del smoke; se repite la pasada entera una vez más y **nunca se sube un techo para que pase**. Las medidas de esta primera pasada son la línea base: los techos se recalibran después editando `perf-h7/presupuestos-h7.json` del repo, en un commit aparte (nunca el código).
- [ ] **A los 15 minutos y a las 24 horas:** el bloque del runbook (monitor en seco, workers, `failed_jobs`, ERROR, 5xx y `Cache-Control: no-store, private`), y a los 15 minutos la sonda de la clase de invisibles en el PCRE 10.39 del servidor (SEC7 §4.7; la última línea del bloque) → `string(6) "U+00AD"` y `NULL`.

### 3.3 Encender el monitor del Hito 7

- [ ] El H7 desplegado y estable a las 24 h.
- [ ] **H-1 de OPS7 comprobado:** si `schedule:run` está en el crontab de root, cada tarea programada (el monitor incluido) carga como root las cachés de `www-data` cada minuto. Comprobarlo (solo lectura) y moverlo al crontab de `www-data` es del propietario (paso 6b de `ironbody-migrate-propuesta.sh`):

```bash
crontab -l -u root | grep -n 'schedule:run'; crontab -l -u www-data 2>/dev/null | grep -n 'schedule:run'
```

- [ ] Saber que, con el monitor encendido, **cambiar una bandera de integraciones abre un incidente de deriva** hasta que se reconozca otra vez la configuración (la huella de Twilio incluye la sonda; H-1 de DevOps del H6). Es una deducción de esas dos piezas, no un ensayo.
- [ ] El mando, `h7-monitor-scope.sh` (copiado en el paso 2; detalle en el runbook, «Hito 7», paso 10):

```bash
bash /root/h7-monitor-scope.sh estado          # monitor=off y ninguna tarea del Studio
bash /root/h7-monitor-scope.sh on off          # → MANDO monitor → on APLICADO (copia previa: …): +2 tareas, las 2 del Studio
cd /var/www/api/backend && sudo -u www-data php artisan ultron-studio:monitor   # primera pasada real: código 0 y ningún incidente nuevo
```

- [ ] **Apagarlo** (la vuelta atrás de nivel 2, `ULTRON_STUDIO_ROLLBACK.md`): `bash /root/h7-monitor-scope.sh off on` → `MANDO monitor → off APLICADO`. Quita las 2 tareas del Studio; los incidentes ya abiertos se quedan en IRON GUARD.
- [ ] **El fichero de entorno, en 600:** el mando lo exige, como el del H6. Con la propuesta de `ironbody-migrate` instalada (paso 3b: `root:www-data 640`), los dos abortan sin tocar nada, también al apagar, y volver a 600 no sirve: tendría que ser de root y `www-data` no lo leería (m-4 de REV7; hoja de ruta, B-3 y C-14). [PENDIENTE: adaptar los mandos antes de que el propietario instale la propuesta (`devops`).]

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
| Latencias del Studio (con el H7) | Los techos de `ops/perf-h7/presupuestos-h7.json`, que mide el smoke del H7 en su fase de presupuestos (p. ej. lecturas: mediana ≤ 150 ms y máximo ≤ 250 ms; la evidencia del canario: mediana ≤ 2 000 ms y máximo ≤ **400 ms**, el techo tras F-1). Son techos iniciales (≈ 3–4 veces el p95 local) | PERF7 §7; `smoke-h7.py` desde `0d43401`. La primera pasada en producción, con `SMOKE_BUDGETS_OUT`, es la línea base, y se recalibra editando el JSON en un commit. [PENDIENTE: la recalibración tras la primera pasada en producción (`devops`).] |

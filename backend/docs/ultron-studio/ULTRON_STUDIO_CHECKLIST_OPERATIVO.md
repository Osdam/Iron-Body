# ULTRON STUDIO: lista de comprobaciones operativas

**Qué es.** Lo que hay que mirar a diario, cada semana y antes de cada fase, con el comando exacto, lo esperado y qué hacer si no sale.

**Para quién.** Quien opera el servidor (`ssh ironbody-vps`, root) y el propietario, que autoriza. La guía de uso del CRM está en `ULTRON_STUDIO_GUIA_ADMINISTRACION.md`; los runbooks completos, en `ULTRON_STUDIO_DEPLOYMENT_PLAN.md` y `ULTRON_STUDIO_ROLLBACK.md`.

**Tipo.** Operativo (lista de comprobación). **Fecha:** 02-11-2026, escrita sobre el backend `5ed9525` y revisada sobre `3fbb155` (con `1c76a7f`, F-1 y F-2 y la primera pasada de REV7) y sobre `cdade1e` (`TAREAS`, los presupuestos en el smoke, el mando del monitor, M-1 y la fase C de TEST7), con los scripts de `docs/ultron-studio/ops/` confirmados en esa rama. **Donde esta lista y un script no coincidan, manda el script.**

**Ampliada el 08-11-2026 con el Hito 8 (voz y telefonía):** §5, sobre el backend `8d3c41f` (rama `feat/ultron-studio-h8-voice`), y corregidas con fecha las expectativas de ElevenLabs de §1 y §4, que cambiaron el 03-11-2026 (contrato H8, E1-12).

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
sudo -u www-data php artisan ultron-studio:integrations-doctor --expect=twilio:connected_otp_only,twilio_voice:not_configured,elevenlabs:ELEVENLABS_VERIFIED   # código 0 (desde el 03-11-2026; ver la nota)
sudo -u www-data php artisan ultron-studio:integrations-doctor --expect=meta_ads:connected_read                   # código 0 (si sale degraded: anotar el motivo; es un dato)
sudo -u www-data php artisan voice:doctor                                          # Estado: ELEVENLABS_UNVERIFIED (desde el 03-11-2026; ver la nota)
sudo -u www-data php artisan queue:health                                          # «Todos los carriles al día.» y código 0
sudo -u www-data php artisan security:check-secret-exposure                        # código 0
sudo -u postgres psql -X -Atq -d ironbody -c "SELECT count(*) FROM marketing_ai_actions WHERE metadata ? 'studio';"   # 0 mientras el motor esté en off (fase 1 del H5)
cat /var/lib/ironbody/backup-status.json                                           # el respaldo de las 03:15 (Bogotá) sin fallo y de menos de 30 h
```

*Corregido el 08-11-2026 (DOC8, por E1-12):* hasta el 03-11-2026 estas dos líneas esperaban `elevenlabs:ELEVENLABS_NOT_CONFIGURED` y `ELEVENLABS_NOT_CONFIGURED`. Desde que el propietario autorizó y el lead configuró ElevenLabs (03-11-2026 22:55 UTC, con `backend/docs/ultron-studio/ops/el-verificar.sh`), **el Studio afirma `ELEVENLABS_VERIFIED` y `voice:doctor` dice `ELEVENLABS_UNVERIFIED`**: el espacio neutro de la voz no guarda la evidencia de la verificación, el Studio sí. La verificación **caduca el 03-12-2026 a las 22:55 UTC**; desde entonces, sin renovarla, el primer `integrations-doctor` sale con 1 (§5.4). *Fuentes: contrato H8, E1-12 y E8-7; `ULTRON_STUDIO_DEPLOYMENT_PLAN.md`, «Hito 8» («Dónde vive cada estado de ElevenLabs», paso 4 y paso 10).*

| Si sale… | Qué significa | Qué hacer |
|---|---|---|
| Menos de 13 RUNNING, o algún BACKOFF/FATAL | Un worker caído. Además, **un worker muerto con un trabajo reservado no lo ve `queue:health` ni IRON GUARD** (H-7 de OPS7, abierto): esta línea es la única que lo enseña | `stat -c '%U %a' bootstrap/cache/*.php` (tienen que ser `www-data 600`). Reiniciar workers (`supervisorctl restart …`) reinicia servicios productivos: **solo con autorización** |
| `failed_jobs` crece | Un trabajo falló | Mirar la cola y la clase del error sin imprimir el cuerpo; avisar a `backend` |
| `publication-check` con 1 | Deriva entre lo declarado y el motor, una cabeza que no verifica o una lectura fallida | Ver `ULTRON_STUDIO_ROLLBACK.md`, «Hito 5» («la cabeza no verifica»). No reparar con SQL |
| Un `integrations-doctor` con 1 | Una integración ya no es la congelada en el H6 (por ejemplo, una pieza de ElevenLabs en el entorno). *Desde el 03-11-2026:* con `elevenlabs` en `ELEVENLABS_UNVERIFIED`, lo más probable es que **caducó la verificación** | Revisar la configuración sin imprimir valores; avisar al lead. Si caducó: renovarla con autorización (§5.4) |
| `voice:doctor` distinto de `ELEVENLABS_UNVERIFIED` (antes del 03-11-2026: distinto de `ELEVENLABS_NOT_CONFIGURED`) | `ELEVENLABS_DISABLED`: alguien apagó la bandera; `ELEVENLABS_NOT_CONFIGURED`: falta una pieza | Parar y avisar al lead: cambiar la voz exige autorización expresa |
| `queue:health` con 1 | Un carril con cola sin atender | Mirar `supervisorctl status`; avisar a `devops` |
| `security:check-secret-exposure` con 1 | Un fichero de secretos legible de más | Avisar a `security`. Ojo: hoy vigila 18 claves y deja fuera 13 (H-4 de SEC7) |
| Sellos `studio` > 0 con el motor en `off` | El motor está leyendo la cadena sin autorización | Avisar al lead de inmediato |
| El respaldo falló o tiene más de 30 h | IRON GUARD abre `backup_unhealthy` | `docs/ops/BACKUPS.md`, «Problemas» |

**Costes del día** (dónde se miran; ninguno exige consola):
- **Laboratorio:** CRM › laboratorio, cabecera del presupuesto (tope de 2 US$ al día de Bogotá y 0,50 US$ por ejecución) y el panel de uso del proyecto de OpenAI del laboratorio.
- **OTP de Twilio:** CRM › Integraciones › Twilio (coste del día). Techos `TWILIO_DAILY_SOFT_LIMIT_USD`/`TWILIO_DAILY_HARD_LIMIT_USD` (6/12 US$); detalle y palancas en `docs/ops/TWILIO-CONTROL-DE-COSTE.md`.
- **OpenAI del motor:** el panel de su proyecto; las palancas son `MARKETING_ULTRON_ENABLED` y la pausa del agente en el CRM.

**Con el Hito 7 desplegado** (hoy no lo está; *nota del 08-11-2026: en producción desde el 03-11-2026*), además:

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
| `voice:doctor` | `ELEVENLABS_UNVERIFIED` desde el 03-11-2026 (antes, `ELEVENLABS_NOT_CONFIGURED`); el Studio, `ELEVENLABS_VERIFIED` hasta el 03-12-2026 22:55 UTC si no se renueva | Contrato H6; contrato H8, E1-12 (corregido el 08-11-2026) |
| Sellos `studio` con el motor en `off` | 0 | Contrato H5 A4.3 |
| `Cache-Control` de `/api/admin/*` (con el H7) | `no-store, private` | `deploy-h7-backend.sh`, paso 9 |
| Respaldo diario | Sin fallo y < 30 h | IRON GUARD (`docs/ops/BACKUPS.md`) |
| Verificación de restauración | Sin fallo y < 10 días | IRON GUARD |
| Laboratorio | ≤ 2 US$ al día y ≤ 0,50 US$ por ejecución (lo para el servidor) | Configuración de la fase 3 del H4 |
| OTP | Por debajo del techo blando de 6 US$ al día | `TWILIO-CONTROL-DE-COSTE.md` |
| Latencias del Studio (con el H7) | Los techos de `ops/perf-h7/presupuestos-h7.json`, que mide el smoke del H7 en su fase de presupuestos (p. ej. lecturas: mediana ≤ 150 ms y máximo ≤ 250 ms; la evidencia del canario: mediana ≤ 2 000 ms y máximo ≤ **400 ms**, el techo tras F-1). Son techos iniciales (≈ 3–4 veces el p95 local) | PERF7 §7; `smoke-h7.py` desde `0d43401`. La primera pasada en producción, con `SMOKE_BUDGETS_OUT`, es la línea base, y se recalibra editando el JSON en un commit. [PENDIENTE: la recalibración tras la primera pasada en producción (`devops`).] |

---

## 5. Hito 8: voz y telefonía

**Estado el 08-11-2026:** nada del Hito 8 está en producción. La fase 1 está ensayada en el banco sobre el HEAD final `8d3c41f` (paso 0 en verde con `--acepta-main=7c84cb5`, despliegue completado, smoke 49 OK y 1 omitido esperado; `plans/informes-h8/informe-devops.md`, «Cierre del lead»). Runbook completo: `ULTRON_STUDIO_DEPLOYMENT_PLAN.md`, «Hito 8». Referencia de rutas, variables y comandos: `ULTRON_STUDIO_H8_VOZ.md`. **Donde esta lista y un script no coincidan, manda el script.**

### 5.0 Reglas propias de la voz

- **Cada fase de la 2 a la 4b pide autorización escrita** del propietario, con su entorno, su impacto y su vuelta atrás declarados antes. Ninguna se encadena con la siguiente sin su criterio de entrada.
- **El mando `h8-voice-scope.sh` todavía no existe** (OPS8). Sin él no se enciende ninguna fase: editar a mano el fichero de entorno de producción no es el procedimiento.
- **Nunca se imprimen** los secretos de la voz (`ELEVENLABS_API_KEY`, `ELEVENLABS_WEBHOOK_SECRET`, `ELEVENLABS_TOOL_SECRET`, `ELEVENLABS_INITIATION_SECRET`, `TWILIO_VOICE_API_KEY_SECRET`, `TWILIO_VOICE_SIGNING_TOKEN`), teléfonos, `call_ref`, `effective_json`, `signature` ni transcripciones. Las consultas SQL no seleccionan `remote_hash`, `remote_last4`, `phone_hash`, `phone_encrypted`, `evidence` ni `note`.
- **`voice:reconcile` no es de solo lectura:** escribe eventos y precios y puede echar el freno (también el de gasto). No se usa como diagnóstico hasta que tenga `--dry-run`, que informará (`would_open_brake`, incidentes y eventos) sin escribir: **corrección en curso (B8-d, E10-9)**, antes de la fase 2.
- El registro de la voz va a su canal: `storage/logs/voice-*.log`.
- Artisan, siempre como `www-data` desde `/var/www/api/backend`.

### 5.1 Diario (desde la fase 1)

```bash
cd /var/www/api/backend
sudo -u www-data php artisan voice:doctor --telefonia                              # fase 1: constante cerrada, banderas apagadas, cadena none/none, freno suelto
sudo -u www-data php artisan ultron-studio:integrations-doctor --expect=twilio:connected_otp_only,twilio_voice:not_configured,elevenlabs:ELEVENLABS_VERIFIED   # código 0 (la expectativa de las fases 2–4b, en §5.10)
sudo -u postgres psql -X -Atq -d ironbody -c "SELECT count(*) FROM voice_brakes o WHERE o.kind = 'open' AND NOT EXISTS (SELECT 1 FROM voice_brakes r WHERE r.brake_id = o.id);"   # 0 = freno suelto
sudo -u postgres psql -X -Atq -d ironbody -c "SELECT direction, status, count(*) FROM voice_calls WHERE created_at > now() - interval '24 hours' GROUP BY 1, 2 ORDER BY 1, 2;"   # fases 1 y 2: ninguna fila (salvo la prueba de O-2)
sudo -u postgres psql -X -Atq -d ironbody -c "SELECT count(*) FROM jobs WHERE queue = 'voice';"   # 0 (el carril de las salientes; hasta la 4a no hay worker)
ls storage/logs/voice-*.log 2>/dev/null && grep -hoE '\] [a-z]+\.[A-Z]+: [a-z0-9_.]+' storage/logs/voice-*.log | sort | uniq -c   # solo rechazos de rutas públicas, si alguien las tocó
```

Las dos primeras líneas salen del runbook («Hito 8», paso 9 y paso 4); las consultas del freno y de las llamadas reproducen `VoiceBrake::isOpen()` y los recuentos de DB8, y **no se han ejecutado contra producción**.

| Si sale… | Qué significa | Qué hacer |
|---|---|---|
| `Freno : ECHADO` o el recuento > 0 | Una de las seis causas lo abrió (§5.9) | No soltarlo: aclarar la causa (§5.9) y avisar al lead |
| Llamadas en las fases 1 o 2 (salvo la fila `denied` de la prueba de O-2) | Algo llamó o se inició fuera de lo previsto | Parar y avisar al lead |
| `voice.inbound.unauthorized` en `voice-*.log` | Una iniciación con el secreto mal puesto, o alguien sondeando la ruta | §5.9 (O-2) |
| `integrations-doctor` con 1 y `elevenlabs` en `ELEVENLABS_UNVERIFIED` | Caducó la verificación | §5.4, punto 3 |
| `jobs` de la cola `voice` > 0 antes de la 4a | Una saliente encolada sin worker | Avisar al lead: no debería poder ocurrir con las salientes apagadas |

### 5.2 Semanal (con el monitor de voz encendido, desde la fase 2)

```bash
cd /var/www/api/backend
sudo -u www-data php artisan schedule:list | grep -cE 'voice:|verify-voice'        # 4 con VOICE_MONITOR_ENABLED=true; 0 apagado (no buscar solo «voice»: casa con «Invoice»)
sudo -u postgres psql -X -Atq -d ironbody -c "SELECT o.reason, count(*) FROM voice_brakes o WHERE o.kind = 'open' AND o.created_at > now() - interval '7 days' GROUP BY 1;"   # aperturas de la semana, por causa
```

- El 4 sale de las cuatro tareas del bloque de voz de `routes/console.php:429-469`, que fija `VoiceMonitorScheduleTest`. **No lo he ejecutado:** `schedule:list` necesita la base y en el árbol de trabajo no la tiene; la primera ejecución en el servidor confirma el filtro.
- **La verificación semanal** de ElevenLabs corre los lunes a las 07:20 de Bogotá y mantiene `ELEVENLABS_VERIFIED`. Si el lunes la verificación no sale «ok», el Studio pasa a `UNVERIFIED` cuando caduque la anterior.
- **Los intentos de cuatro ojos** de la voz entran en la consulta semanal de §2 (`voice_publication.four_eyes_denied` termina en `four_eyes_denied`).

### 5.3 Requisitos previos del propietario

**Antes de la fase 2:**
- [ ] **El plan de pago de ElevenLabs con telefonía** (hoy el workspace está en el plan **free**, E1-12) y una clave **acotada** con cupo de créditos [P-22]; la región [P-21].
- [ ] **La rotación de la clave de ElevenLabs** que viajó por el chat (E2-2): clave nueva en la consola → `backend/docs/ultron-studio/ops/el-verificar.sh` con el fichero de la clave nueva → `ultron-studio:integrations-snapshot --verify-voice` («credenciales válidas») → **solo entonces** revocar la vieja → reconocer otra vez la configuración. **Ojo:** `el-verificar.sh` regenera también `ELEVENLABS_WEBHOOK_SECRET` y `ELEVENLABS_TOOL_SECRET` (`el-verificar.sh:24-34`): se ejecuta **antes** de registrar los secretos en ElevenLabs; después, volver a ejecutarlo rompería la post-llamada y las herramientas.
- [ ] **La subcuenta «Voz» de Twilio**, con una API key restringida para Laravel (llamadas: leer, listar y actualizar; números: leer; uso: leer), otra para ElevenLabs (lo que exija la importación, C-A1) y el Auth Token de la subcuenta (el token de firma).
- [ ] **El número local con su expediente regulatorio (el «bundle») aprobado:** registro mercantil y dirección dentro de la localidad del prefijo [P-23]; 17,50 US$ al mes. **Nunca el número de WhatsApp.**
- [ ] El presupuesto y los avisos de la subcuenta [P-5].
- [ ] B-6, recomendado: la key restringida del OTP y la rotación del maestro [P-4].
- [ ] **Registrar en ElevenLabs los secretos propios** (el del webhook y el de las herramientas, generados en el servidor) y el de iniciación, **sin imprimirlos** (runbook, «Registrar en ElevenLabs los dos secretos propios»).
- [ ] Si el monitor del H7 está encendido, **`ULTRON_STUDIO_MONITOR_EXPECT`** con la expectativa de la fase (§5.10), con el procedimiento del paso 11 del runbook.
- [ ] La autorización escrita de la fase 2.

**Antes de la fase 3:** la fase 2 del H6 (escrituras de canarios y dos personas) y `log_error_verbosity = terse` (§3.4); al menos **dos personas canario de voz** con consentimiento; el texto del aviso de IA aprobado (E1-3); P-7 a P-9, P-12, P-16 y P-24 decididos; la autorización escrita y la del «T3 de voz».

**Antes de la fase 4a:** la fase 3 estable 24 horas con N llamadas sin incidencias; la autorización escrita.

**Antes de la fase 4b:** el visto bueno legal (Ley 2300, RNE, Ley 1581 y contratos de transmisión); P-11, P-13 a P-15 y P-20; el fixture de festivos 2026–2028 aprobado (P-13); la autorización escrita.

### 5.4 Fase 1 (todo apagado)

Runbook: «Hito 8», pasos 1–11. Condiciones: REV8 sin bloqueantes; la ascendencia de la rama corregida; la decisión del propietario sobre `--acepta-main=7c84cb5` en el momento de desplegar.

1. **Lo esperado al terminar:** `DESPLIEGUE H8 FASE 1 (BACKEND) COMPLETADO`; esquema `68 84 6 18 27 51 3 7`; rutas `77 18 8 10 15 6 13 5 8 4`; smoke con código 3 y lo único OMITIDO, `GET voice-lab/sessions` (409 `voice_lab_disabled`).
2. **A los 15 minutos y a las 24 horas:** el bloque del paso 9 del runbook (§5.1 incluido): 13 workers, `failed_jobs` igual, sin ERROR nuevos.
3. **Antes del 03-12-2026 a las 22:55 UTC, renovar la verificación de ElevenLabs** (con autorización): `sudo -u www-data php artisan ultron-studio:integrations-snapshot --verify-voice` → «credenciales válidas; evidencia guardada» (un GET), o encender el monitor de voz, que la renueva los lunes. **Encender el monitor del H7 no la renueva:** solo avisa.
4. **Antes de encender el monitor del H7:** `ULTRON_STUDIO_MONITOR_EXPECT=twilio:connected_otp_only,twilio_voice:not_configured,elevenlabs:ELEVENLABS_VERIFIED`, con el procedimiento del paso 11 (el defecto del código no cambia; sin esto, el monitor abriría `integrations_drift`).

**Vuelta atrás:** no hay bandera que bajar; el código, con el bloque «Vuelta atrás de la fase 1» del runbook. Las 11 tablas quedan vacías; **nunca `migrate:rollback`**.

### 5.5 Fase 2 (credenciales, número y laboratorio; ni una llamada telefónica)

**Antes:**
- [ ] §3.1 (común) y §5.3 hechos; la fase 1 estable 24 horas.
- [ ] El mando `h8-voice-scope.sh` existe y está ensayado. [PENDIENTE (OPS8).]
- [ ] **La corrección B8-d desplegada** (Enmienda 10): `cost_cap` solo con gasto comprometido mayor que el presupuesto y que cero, y lo denegado sin gasto (E10-8; sin ella, la fila `denied` de la prueba de O-2 echaría el freno); `voice:reconcile --dry-run` (E10-9); el tope de los códigos de la guarda en la post-llamada (E10-4, M-2 de SEC8-b). **Corrección en curso (B8-d).**
- [ ] **La CSP del laboratorio hablado en nginx** (E10-12): `connect-src wss://api.elevenlabs.io` y `Permissions-Policy: microphone=(self)`, sin `script-src blob: data:`. [PENDIENTE (OPS8, con autorización: `nginx -t` y `reload`).]
- [ ] La regresión de los smokes del H6 y del H7 adaptada al H8. [PENDIENTE (TEST8/OPS8).]

**Qué se pone** (con el mando, con copia previa y `config:cache`): la subcuenta y sus keys (`TWILIO_VOICE_ACCOUNT_SID`, `TWILIO_VOICE_API_KEY_SID`, `TWILIO_VOICE_API_KEY_SECRET`), `TWILIO_VOICE_SIGNING_TOKEN`, el número (`TWILIO_VOICE_FROM`), `ELEVENLABS_AGENT_PHONE_NUMBER_ID`, `ELEVENLABS_INITIATION_SECRET`, las tres referencias (`ELEVENLABS_*_REF`) y `VOICE_WEBHOOK_BASE_URL`; la importación del número en ElevenLabs (el propietario, con la key de ElevenLabs); `VOICE_MONITOR_ENABLED=true` **en cuanto el número esté importado**; el laboratorio hablado (`VOICE_LAB_ENABLED=true`, `VOICE_LAB_DRIVER=elevenlabs`, `VOICE_LAB_DAILY_BUDGET_USD` y `VOICE_LAB_SESSION_BUDGET_USD` > 0).

**La comprobación previa del secreto de iniciación (O-2), ANTES de conectar el número a las entrantes:**
1. Una iniciación de prueba con la herramienta de prueba del webhook de ElevenLabs [por confirmar en la consola] **o** desde el servidor, con el valor leído del entorno por PHP y pasado a `curl` por la entrada estándar (nunca en la línea de órdenes), un número sintético (`+57 300 000 00xx`) y un `call_sid` inventado. Detalle: runbook, «El secreto de iniciación, comprobado ANTES de conectar el número».
2. **Esperado:** 200 y `caller_authorized` = «no» (las entrantes siguen apagadas); en `voice-*.log`, **ningún** `voice.inbound.unauthorized`. Deja una fila `denied` sintética, que es la evidencia.
3. **Con un 401, el secreto no casa: no se conecta el número.** Se corrige y se repite.

**Después:**
- [ ] `voice:doctor` sin piezas que falten; `voice:doctor --telefonia`: número configurado, número importado «sí», subcuenta «sí», freno suelto.
- [ ] `ultron-studio:integrations-snapshot --verify-voice` → «credenciales válidas» (con la clave nueva).
- [ ] **Reconocer otra vez la configuración autorizada** (Super Admin, `ultron.integrations_manage`): la huella de voz cambia al llegar las piezas del H8.
- [ ] `integrations-doctor --expect=twilio:connected_otp_only,twilio_voice:disabled,elevenlabs:ELEVENLABS_VERIFIED` → 0 (con el número puesto y `TWILIO_VOICE_ENABLED=false`, la clasificación es `disabled`; `StudioIntegrations::classifyTwilioVoice`).
- [ ] `sudo -u www-data php artisan schedule:list | grep -cE 'voice:|verify-voice'` → 4.
- [ ] Una sesión del laboratorio hablado con presupuesto, y su veredicto de otra persona.

**Vuelta atrás:** nivel 2 de D8-20 (`voice/off` si hubiera publicación; `ELEVENLABS_ENABLED=false` y la caché); nivel 3, revocar las keys en las consolas (propietario).

### 5.6 Fase 3 (entrantes en canario)

**Antes:**
- [ ] La fase 2 verificada y §5.3 hecho; canarios de voz activos (registro del H6).
- [ ] El lector de la verificación en la telefonía (E4-6) y la salud del webhook automatizada. [PENDIENTE (B8 + SEC8).]
- [ ] nginx deja pasar 1 MB a la post-llamada (lo dice el smoke en el servidor). [PENDIENTE (OPS8, con autorización).]
- [ ] La sección de voz de `ops/supresion-1581.sql`. [PENDIENTE (OPS8 + `database-specialist`).]
- [ ] `VOICE_FACTS_SITE` y `VOICE_FACTS_HOURS` puestos (lo que dice `consultar_horarios`).
- [ ] El commit `voice(calls-open)` desplegado (una línea, con la autorización escrita citada en el mensaje).

**Qué se enciende:** `ULTRON_STUDIO_VOICE_PUBLISHING_ENABLED=true` (para publicar el canario), una publicación de voz en `canary` (cuatro ojos y el veredicto del laboratorio; §14.6 de la guía), y después `VOICE_INBOUND_ENABLED=true`, `VOICE_INBOUND_DAILY_CAP` > 0 y `TWILIO_VOICE_ENABLED=true`; el presupuesto de voz, con la regla de B8-d (E10-8).

**Después:** `voice:doctor --telefonia` con la constante ABIERTA, entrantes «sí» y la cadena `canary` ok; `integrations-doctor --expect=twilio:connected_otp_only,twilio_voice:connected_voice,elevenlabs:ELEVENLABS_VERIFIED` → 0; las pruebas físicas de `ULTRON_STUDIO_PRUEBAS_FISICAS.md` §9.

**Vuelta atrás:** nivel 0 (`VOICE_INBOUND_ENABLED=false`, con el mando); nivel 0b (`voice:hangup --all --confirm`); nivel 1 (revertir la publicación).

### 5.7 Fase 4a (salientes a canarios)

**Antes:** la fase 3 estable 24 horas con N llamadas sin incidencias; la corrección de M-3 de SEC8-b (que gane el corte de una saliente con el job en vuelo; B8-d, E10-5) [corrección en curso]; **el worker `ironbody-voice` instalado y en RUNNING** (borrador en `docs/ops/supervisor/ironbody-voice.conf`; antes de copiarlo, comprobar la conexión `voice`): **14 RUNNING**.

**Qué se enciende:** `VOICE_OUTBOUND_ENABLED=true` (alcance `canary`), `TWILIO_VOICE_DAILY_CALL_CAP` pequeño (p. ej. 3) y `VOICE_DAILY_BUDGET_USD` > 0 (una saliente a un móvil reserva unos 0,96 US$ con 300 s).

**Después:** las pruebas físicas de §9.4 de las pruebas físicas; `supervisorctl status | grep -c RUNNING` → 14; la cola `voice` sin atascos.

**Vuelta atrás:** nivel 0 (`VOICE_OUTBOUND_ENABLED=false`), 0b y 1.

### 5.8 Fase 4b (salientes a contactos con consentimiento)

**Antes:** la 4a estable; el visto bueno legal; **una publicación promovida a `rest`**: el alcance `contacts` lee esa parte y, sin ella, toda saliente a un contacto da `no_publication` (`VoicePublishedConfig.php:252-255`); el RNE decidido (P-14).

**Qué se enciende:** `VOICE_OUTBOUND_SCOPE=contacts` y `VOICE_RNE_VALIDITY_DAYS` > 0; el horario propio si se decide (`VOICE_OUTBOUND_HOURS_*`, P-13).

**Vuelta atrás:** `VOICE_OUTBOUND_SCOPE=canary` o el nivel 0.

### 5.9 Señales de alarma y qué hacer

| Señal | Causa probable | Qué hacer | Qué NO hacer |
|---|---|---|---|
| Freno `guard_violation` | El agente dijo algo que la guarda marca | Ver la llamada en «Llamadas» (códigos y turno, sin texto); revertir si se repite | Soltar sin entender qué dijo |
| Freno `seal_mismatch` | Una entrante atendida por otro agente (publicar con una llamada en curso, o el número reasignado) | Comprobar la deriva del número; publicar con el canario en silencio | — |
| Freno `agent_drift` (con `trigger: number_drift`: el número) | Alguien cambió el agente o el número en ElevenLabs | **Revertir o apagar** desde el Studio; si se sospecha de la clave, nivel 3 (revocarla) | «Arreglarlo» en el panel de ElevenLabs |
| Freno `unknown_provider_call` | Una llamada en Twilio sin su fila: iniciada fuera de Laravel, o una entrante cuya iniciación falló | Mirar `voice-*.log`: con `voice.inbound.unauthorized`, es el secreto (O-2); si no, sospechar de una credencial (nivel 3) | Soltar sin saber quién llamó |
| Freno `cost_cap` | Lo comprometido del día superó `VOICE_DAILY_BUDGET_USD` | Revisar el gasto del día. Con B8-d (E10-8), solo con gasto real comprometido | Subir el techo para que pase |
| Freno `subaccount_alert` | Twilio avisó de gasto en la subcuenta | Revisar la consola de Twilio | — |
| **O-2:** cada entrante da 401 en la iniciación, sin fila, y la conciliación la marca desconocida y frena (un freno y un incidente por llamada) | El secreto de iniciación mal puesto (otro valor, otra cabecera o sin poner; la cabecera es `X-Voice-Initiation-Secret`) | `voice.inbound.unauthorized` con `has_header: true` (valor distinto) o `false` (sin cabecera); corregir el secreto en ElevenLabs o en el entorno (con el mando, sin imprimirlo), repetir la iniciación de prueba y **solo entonces** soltar cada apertura con la nota de la causa | Conectar el número sin la prueba |
| `integrations-doctor`: `elevenlabs` en `UNVERIFIED` | Caducó la verificación (30 días) | Renovarla (§5.4, punto 3) | — |
| El webhook post-llamada desactivado en ElevenLabs | 10 fallos seguidos (por ejemplo, M-2 de SEC8-b, que corrige B8-d, E10-4) | Avisar a `backend`; mientras, no hay guarda posterior ni `seal_verified` | Reactivarlo sin aclarar la causa |
| El smoke dice que nginx corta la post-llamada de 1 MB | `client_max_body_size` menor que el tope | Subirlo antes de la fase 3 (cambio de nginx, con autorización) | — |
| El worker `ironbody-voice` en FATAL | Falta la conexión `voice` o el worker no arranca | `supervisorctl status`; avisar a `devops` | Reiniciar sin autorización |

**Antes de soltar cualquier freno:** la causa aclarada (la lista en el CRM, «Estado» → freno, o la consulta de §5.2), y cada apertura soltada por separado por un Super Admin con su nota (5–300 caracteres). Mientras B8-d no dé `--dry-run`, `voice:reconcile` no es un diagnóstico (§5.0).

### 5.10 Umbrales de la voz, de un vistazo

| Señal | Bien | Fuente |
|---|---|---|
| `voice:doctor --telefonia` | Fase 1: constante cerrada, banderas apagadas, cadena none/none, freno suelto | Runbook, paso 9 |
| `integrations-doctor` (expectativa por fase) | Fase 1: `twilio:connected_otp_only,twilio_voice:not_configured,elevenlabs:ELEVENLABS_VERIFIED`; fase 2: `twilio_voice:disabled`; fases 3–4b: `twilio_voice:connected_voice` (ElevenLabs, `ELEVENLABS_VERIFIED` en todas) | Runbook, tabla de fases; `StudioIntegrations::classifyTwilioVoice` |
| Tareas de voz en `schedule:list` | 0 con el monitor de voz apagado; 4 encendido | `routes/console.php:429-469` |
| Workers | 13 hasta la 4a; 14 desde la 4a | Runbook |
| Freno | Ninguna apertura sin soltar | `VoiceBrake::isOpen()` |
| Presupuestos del smoke | Los de `ops/perf-h8/presupuestos-h8.json` (p. ej. `GET admin/voice/status` p95 ≤ 310 ms); techos iniciales, se recalibran con la primera pasada en producción | `plans/informes-h8/informe-performance.md` §7 |

### 5.11 Fuentes

Contrato `ULTRON_STUDIO_H8_CONTRACT.md` (D8-19 a D8-21, «Fases de despliegue», E1-12, E2-2, E4-6, E8-7); `ULTRON_STUDIO_DEPLOYMENT_PLAN.md`, «Hito 8»; `plans/informes-h8/informe-devops.md` (§OPS8-b y «Cierre del lead»), `informe-security.md` (§SEC8-b), `informe-tester.md` (O-2), `informe-performance.md` §7; la Enmienda 10 (E10-4 a E10-12; B8-d); `backend/docs/ultron-studio/ops/el-verificar.sh`; en el backend `8d3c41f`: `routes/console.php`, `config/voice.php`, `app/Services/Telephony/{VoiceBrake,VoiceReconciler,VoicePublishedConfig}.php`, `app/Services/UltronStudio/StudioIntegrations.php`.

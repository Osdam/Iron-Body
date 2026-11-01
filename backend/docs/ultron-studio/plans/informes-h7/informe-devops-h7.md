# Hito 7 · Informe de devops (OPS7) — PARCIAL

**Fecha:** 01-11-2026. **Rama:** `feat/ultron-studio-h7` (worktree `_worktrees/ultron-h7`). **Entorno de todo lo hecho: local (Mac). No es producción:** sin SSH, sin peticiones al servidor, sin commit. **Estado:** paquete INCOMPLETO por límite de uso de la sesión; lo pendiente está en §9.

## 0. En una pantalla

| Entrega | Estado | Evidencia |
|---|---|---|
| 1. Monitor con bandera | **Hecho y probado** | 11/11 pruebas (131 aserciones, SQLite); Pint OK; `schedule:list` 26 entradas con la bandera apagada → 28 encendida |
| 2. Runbook de costes | **Hecho** (§4) | Palancas sacadas del código y de `docs/ops/TWILIO-CONTROL-DE-COSTE.md` |
| 3. `ironbody-migrate` sin root | **Escrito, no ensayado** | `bash -n` OK; lanzador PHP por stdin probado aparte. Falta el ensayo en el banco (§9) |
| 4. Supresión Ley 1581 | **Escrito y ensayado en una base desechable** (no en el banco con roles) | En seco y real: 6 campos, 43 disparadores antes y después, 1 fila de registro (§3) |
| 5. Respaldos | **Política escrita** (§5); **ensayo de restauración PENDIENTE** | — |
| 6. Scripts del H7 | **Escritos, no ensayados** | `bash -n` y `py_compile` OK; `h7-schema-check.sql` ejecutado contra una base desechable |
| 7. Ensayo de recuperación | **PENDIENTE** | Diseño en §9 |

## 1. Ficheros

- **Código (exclusivos):**
  - `app/Console/Commands/UltronStudio/MonitorCommand.php` (nuevo);
  - `config/ultron_studio.php`, **solo** el bloque `monitor`;
  - `routes/console.php`: **solo añade** el bloque del monitor (35 líneas, 0 borradas; deshice a propósito lo que Pint reescribía en líneas ajenas).
- **Prueba (fuera de la lista del contrato; la pidió el lead):** `tests/Feature/UltronStudio/UltronStudioMonitorTest.php`. Sin «H7» en el nombre, para no pisar el patrón de TEST7.
- **Operación:** `ops/{deploy-h7-backend.sh, h7-schema-check.sql, smoke-h7.py, smoke-run-h7.sh, ironbody-migrate-propuesta.sh, supresion-1581.sql}`.
- **`banco-h7/`:** no creado.

## 2. Monitor (entrega 1)

- **Bandera:** `ULTRON_STUDIO_MONITOR_ENABLED` con `filter_var`; solo `true`, `1`, `on` y `yes` la encienden.
- **Expectativa:** `ULTRON_STUDIO_MONITOR_EXPECT`. Por defecto: `twilio:connected_otp_only,twilio_voice:not_configured,elevenlabs:ELEVENLABS_NOT_CONFIGURED`. Vacía, vale la de por defecto; mal escrita, sale `expectation_invalid`.
- **`ultron-studio:monitor [--check=publication|integrations|queues] [--dry-run] [--json]`:**
  - aplica las reglas de `publication-check`, de `integrations-doctor --expect` (más la deriva de la configuración autorizada) y de `queue:health`, sobre los mismos servicios;
  - una prueba de paridad compara su veredicto con el de los comandos;
  - códigos: 0 si todo cuadra · 1 si algo no cuadra · 2 con una opción mal escrita.
- **Incidentes:** usa `IncidentRecorder::record()` con la fuente `ultron_studio` y tres clases:
  - `publication_drift` (alto);
  - `integrations_drift` (alto);
  - `monitor_check_failed` (medio).
  - Las colas **no** abren incidente propio: ya lo hace `iron-guard:scan`.
  - Sin notificaciones externas.
  - `--dry-run` no escribe nada.
- **Decisión D-1 (fuera de la letra del contrato; la confirma el lead):** el monitor corre **cada 30 min**, no cada hora.
  - La ventana de agrupación de IRON GUARD es de 60 min. Con una pasada por hora, cada pasada **reabriría** el incidente: las ocurrencias volverían a 1 y se perdería el reconocimiento.
  - La instantánea de integraciones (`integrations-snapshot`) va a diario, a las 07:10 de Bogotá.
  - Volver a la cadencia del contrato es cambiar una línea.
- **No hice:** documentar las dos variables en `.env.example`, que no es fichero mío.

## 3. Supresión Ley 1581 (entrega 4)

- **Qué hace `supresion-1581.sql`:**
  - **Redacta, no borra.** En seco por defecto (`\set dry 1`): transacción `READ ONLY` que termina en `ROLLBACK`.
  - Con `dry 0`, una transacción con `SET LOCAL ROLE` al rol dueño:
    - apaga solo los disparadores de UPDATE cuya función es `ultron_studio_forbid` o `ultron_studio_lab_forbid` (si hay otra función, aborta);
    - redacta;
    - los vuelve a activar;
    - comprueba que no queda ninguna coincidencia y que los disparadores activos son los mismos que antes;
    - deja una fila `system.personal_data_redacted` en el libro, con recuentos y el radicado, nunca el dato.
- **Qué busca:**
  - un término (nombre o correo);
  - un teléfono E.164, con separadores y con o sin `+57`;
  - la huella de un canario: solo si está **revocado**; queda irreversible;
  - un `admin_id` del personal.
- **No redacta, solo informa:** `publications.effective_json`, `versions.content` y `lab_runs.target_snapshot`, porque son contenido firmado o con huella.
- **Entrada de datos:** las variables van por un fichero 600 de root que se concatena delante del script. Así el dato no pasa por `ps`, por el historial ni por un fichero legible por postgres.
- **Ensayo real** (base desechable `ultron_studio_h7_ops`, rol dueño = superusuario local):
  - nombre con tilde guardado como `é` en JSON → redactado;
  - `300 999 0777` y `+57 300-999-0777` → redactados;
  - el canario revocado queda con alias `suprimido`, huella aleatoria y `0000`;
  - 43 disparadores antes y después.
- **Falta ensayarlo** con el modelo de roles (dueño sin superusuario), con `publication-check` tras redactar `reason` y con los casos negativos (§9).
- **Límites que el propietario tiene que conocer:**
  - las copias de seguridad siguen llevando el dato hasta que caducan; tras restaurar una, hay que volver a aplicar cada supresión del registro;
  - el log de PostgreSQL puede llevar la huella (H-2 del H6);
  - `log_statement` tiene que estar en `none` o `ddl`.

## 4. Runbook de costes operativos (entrega 2)

| Coste | Palanca (de más rápida a más profunda) | Dónde se mira |
|---|---|---|
| **OTP (Twilio Verify)** | Interruptor `TWILIO_SEND_ENABLED=false` · techo `TWILIO_DAILY_SOFT/HARD_LIMIT_USD` (6/12) · cupos `OTP_*` · login adaptativo · Usage Triggers de Twilio (avisan, no frenan) | `OtpPolicy::costSnapshot()`; `grep otp.cost_guard storage/logs/laravel.log`; Usage Records de Twilio (la cifra real) |
| **OpenAI del motor** | `MARKETING_ULTRON_ENABLED` (apaga ULTRON entero); la pausa del agente en el CRM | Facturación del proyecto de OpenAI del motor; filas de `marketing_ai_actions` por día |
| **OpenAI del laboratorio** | Cuatro cerrojos: `ULTRON_STUDIO_LAB_ENABLED`, `…_DRIVER=fake`, `…_DAILY_BUDGET_USD=0` y clave propia; además `…_RUN_BUDGET_USD` (0,50), `…_MAX_RUNS_PER_DAY` (20) y los topes de casos y tokens | `GET lab/status`; el proyecto propio del laboratorio en OpenAI (`store:false`) |
| **Twilio fuera del OTP** | Voz apagada (`TWILIO_VOICE_ENABLED=false`, tope diario 0); la sonda del Studio es un GET gratis y lleva su bandera, enfriamiento y cupo | `integrations-doctor`; `marketing_calls`: 0 |
| **Infraestructura** | VPS fijo; respaldos de ~3 MB al día (14 diarios + 8 semanales < 100 MB) | `df -h`; `/var/lib/ironbody/backup-status.json` |

Todas estas palancas se cambian en `.env` y se aplican con `config:cache` (como `www-data`, `umask 077`), con una copia previa del fichero en 600. Ninguna necesita desplegar.

## 5. Política de respaldos (entrega 5)

- **Vigente, y no la cambio:**
  - automático diario a las 03:15 (Bogotá) en `/root/db-backups/auto`;
  - volcado `-Fc -Z9`, validado (tamaño e índice), atómico, 600, con `.sha256`;
  - retención de 14 diarios + 8 semanales;
  - verificación de restauración semanal sobre una base desechable;
  - IRON GUARD abre `backup_unhealthy` (si `BACKUP_MONITORING_ENABLED=true`);
  - RPO 24 h; RTO medido: 2 s.
- **Respaldos de despliegue** (`/var/backups/ironbody/<hito>-<ts>`):
  - directorio 700 y ficheros 600, de root;
  - **nunca se borran sin orden del propietario**;
  - los de G y del H4 nacieron en 755: endurecerlos lo autoriza el propietario.
- **Recomendación:** una copia fuera del VPS (otra cuenta o región), cifrada. Hoy, todo está en la misma máquina. Es decisión y coste del propietario.
- **Ensayo de restauración en el banco:** pendiente (§9).

## 6. Scripts del H7 (entrega 6)

- **`deploy-h7-backend.sh`:** calca el del H6.
  - `PREVIO=pendiente`: se niega a todo hasta fijarlo en un commit el día del despliegue.
  - Una migración.
  - Puerta de diff con una lista explícita de ficheros permitidos. Hoy: la API interna de conocimiento de SEC7 y `console.php` (solo líneas añadidas). **Faltan los controladores de M-2 de B7.**
  - `MIN_TABLAS=226`.
  - Compuerta 44/50/4/14; disparadores y `CHECK` de la cadena.
  - Privilegios de `iron_app`: UPDATE y DELETE en `false` solo en la cadena, el libro y las instantáneas.
  - Comprueba con `curl -I` el `Cache-Control: no-store, private` en 401 y 404, y el 413 temprano sin sesión.
  - Monitor apagado y 0 tareas del Studio en `schedule:list`; configuración del H5 y del H6 igual que antes.
  - 13/0 dos veces; `integrations-snapshot` sin cambios.
- **`h7-schema-check.sql`:** cuadra contra una base con el H6, antes de migrar (43/49/4/14).
- **`smoke-h7.py` y `smoke-run-h7.sh`:**
  - comprueban `no-store`, el 413 con y sin sesión y el 401 por debajo del tope, `verified` por entrada y en el detalle de las versiones, y los 5 invisibles → 422 con dos contrapruebas;
  - usan el detector de fugas de `smoke-h6.py`, así que hay que copiar los dos ficheros;
  - comprueban en la base las 14 tablas y los incidentes.
- **No ejecutados contra ninguna API.**

## 7. Hallazgos

- **H-1 (media, no verificado en el servidor):** según `docs/agenda-valoraciones/IRON_BODY_VALUATION_SCHEDULING_REPORT.md` §2, `schedule:run` corre como **root**.
  - Así, cada tarea programada (el monitor también) incluye `bootstrap/cache/*.php` de `www-data`: es la clase M-3, cada minuto.
  - Propuesta: pasar la línea al crontab de `www-data`, tras comprobar que ninguna tarea necesita root.
  - Dueños: el propietario y `security`.
- **H-2:** el `ironbody-migrate` de hoy depende de que `PGOPTIONS` llegue hasta PHP (N-10). La propuesta lo fija ella misma y solo admite `lock_timeout`.
- **H-3:** si `www-data` puede escribir el `.env` (es lo probable: `www-data 600`), podría desviar la conexión del dueño. La propuesta saca el destino de la base de la credencial; se recomienda además `root:www-data 640`.

## 8. Acciones pendientes de autorización

| Acción | Quién |
|---|---|
| Commit de estos ficheros | El lead |
| Instalar `ironbody-migrate` (pasos 0–7 al final del script, con `--check` antes de sustituir) | El propietario |
| Fijar `PREVIO`, completar la puerta con los ficheros de B7 y desplegar el H7 | Con autorización |
| Encender el monitor: `ULTRON_STUDIO_MONITOR_ENABLED=true`, después `config:cache` y comprobar 2 tareas en `schedule:list` | Con autorización |

## 9. PENDIENTE (no hecho por límite de uso)

1. **`banco-h7/`.** Partiría del banco del H6: «producción» en `7d2c6d8` con el esquema del H6 y los roles `h5_owner`/`h5_app`, y origen = `HEAD` más el trabajo sin confirmar.
   - El motor de migración del banco necesita `ULTRON_STUDIO_DB_APP_ROLE=h5_app`.
   - Ensayos que faltan:
     - `deploy-h7-backend.sh` con `--solo-comprobar` y con el 55P03;
     - el smoke;
     - la propuesta de `ironbody-migrate`: marcador plantado en `bootstrap/cache` (no lo ejecuta), `PGOPTIONS` ajeno → 2, credencial 644 o con una clave extra → 2, `DB_HOST` falso en el `.env` (manda la credencial), caché en 600.
2. **Supresión con roles reales:**
   - `publication-check` 0 después de redactar `reason`;
   - un canario vivo → aborta sin cambios;
   - `h5_app` → 42501.
3. **Restauración en una base desechable:** de un `-Fc` del banco, con recuentos exactos de todas las tablas, 44/50 y los disparadores activos.
4. **Recuperación:**
   - lectura de la cadena caída (`fallback/read_failed`, sin turno mudo). Se hace en un clúster PG **propio** (otro puerto): el de 55432 lo usan otros agentes y no se para;
   - worker del laboratorio muerto a mitad (huérfana cerrada);
   - vuelta atrás de código H7 → H6 con el esquema del H7 (smoke H6 de la fase 1).
5. **Integración con los informes:** los recuentos definitivos de DB7 y la lista de M-2 de B7.

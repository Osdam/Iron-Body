#!/usr/bin/env bash
#
# Respaldo de PostgreSQL de Iron Body.
#
# Corre como `postgres` sobre el socket local, que en este servidor está en
# autenticación `peer`. Eso significa que **no hay contraseña en ninguna parte**:
# ni en el script, ni en un `.pgpass`, ni en la línea de comandos donde `ps` la
# vería. La identidad la da el usuario del sistema.
#
# La regla que ordena todo lo que sigue: un respaldo incompleto no puede parecer
# válido. En este mismo directorio hay un `.dump` de cero bytes de junio con
# nombre perfectamente normal —alguien lo dio por bueno porque `pg_dump` salió
# con código 0—. De ahí que se escriba a un fichero temporal, se valide, y solo
# entonces se le ponga el nombre definitivo: mientras no supere las
# comprobaciones, no existe con el nombre que alguien buscaría en una urgencia.
set -Eeuo pipefail

DB="ironbody"
DEST="/root/db-backups/auto"
STATUS="/var/lib/ironbody/backup-status.json"
LOG="/var/log/ironbody-backup.log"
LOCK="/var/run/ironbody-db-backup.lock"

# Retención. La base son 70 MB y el dump comprimido ~3 MB, así que guardar un mes
# cuesta menos de 100 MB en un disco con 179 GB libres. Lo que limita no es el
# espacio: es cuánto historial es útil para una recuperación operativa.
KEEP_DAILY=14
KEEP_WEEKLY=8

# Suelo de tamaño. Cualquier dump por debajo es sospechoso por definición: la
# base tiene 224 migraciones y 483 miembros, no cabe en 200 kB.
MIN_BYTES=$((200 * 1024))

# Mínimo de objetos en el índice del dump. Un dump que se abre pero solo lista
# tres tablas está roto de una forma que el tamaño no delata.
MIN_ENTRIES=200

TZ_LOCAL="America/Bogota"
export TZ="$TZ_LOCAL"

STARTED_AT="$(date -Iseconds)"
STARTED_EPOCH="$(date +%s)"
STAMP="$(date +%Y-%m-%d_%H-%M-%S)"
TARGET="${DEST}/${DB}-${STAMP}.dump"
PARTIAL="${TARGET}.part"

log() { printf '%s [backup] %s\n' "$(date -Iseconds)" "$*" | tee -a "$LOG" >&2; }

# El estado se escribe SIEMPRE, salga bien o mal. Un respaldo que falla en
# silencio es indistinguible de uno que nunca se programó, y ese es justo el
# escenario que se quiere hacer imposible.
write_status() {
  local result="$1" message="$2" size="${3:-0}" checksum="${4:-}" duration="${5:-0}"
  install -d -m 0755 "$(dirname "$STATUS")"
  local next
  next="$(systemctl show ironbody-db-backup.timer -p NextElapseRealtime --value 2>/dev/null || true)"
  cat > "${STATUS}.part" <<JSON
{
  "result": "${result}",
  "message": "${message}",
  "database": "${DB}",
  "started_at": "${STARTED_AT}",
  "finished_at": "$(date -Iseconds)",
  "duration_seconds": ${duration},
  "size_bytes": ${size},
  "sha256": "${checksum}",
  "file": "$([ "$result" = "ok" ] && echo "$TARGET" || echo "")",
  "timezone": "${TZ_LOCAL}",
  "next_run": "${next}",
  "retention": {"daily": ${KEEP_DAILY}, "weekly": ${KEEP_WEEKLY}}
}
JSON
  mv -f "${STATUS}.part" "$STATUS"
  chmod 0644 "$STATUS"
}

fail() {
  local msg="$1"
  log "FALLO: ${msg}"
  rm -f "$PARTIAL"
  write_status "failed" "${msg}" 0 "" "$(( $(date +%s) - STARTED_EPOCH ))"
  exit 1
}

trap 'fail "interrumpido o error inesperado en la línea ${LINENO}"' ERR

# Una sola ejecución a la vez. Dos `pg_dump` simultáneos sobre la misma base no
# se corrompen entre sí, pero duplican la carga en la ventana de menos actividad,
# que es justo lo que se estaba evitando al elegir la hora.
exec 9>"$LOCK"
flock -n 9 || { log "otra ejecución en curso; se sale sin hacer nada"; exit 0; }

install -d -m 0700 "$DEST"
install -d -m 0755 "$(dirname "$STATUS")"
touch "$LOG" && chmod 0640 "$LOG"

# `postgres` no puede leer /root: sin esto cada sudo avisa por nada.
cd /

log "inicio · base=${DB} destino=${DEST}"

# ── 1. Volcado ──────────────────────────────────────────────────────────
#
# Formato `custom` (-Fc) con compresión interna 9. Es lo que permite
# `pg_restore` selectivo —una tabla, un esquema— en lugar de tener que tragarse
# el fichero entero, y por eso NO se envuelve además en gzip: un `.dump.gz`
# obligaría a descomprimir 3 GB en disco antes de poder mirar dentro, y en una
# urgencia ese paso extra se paga en minutos.
# El volcado sale por la SALIDA ESTÁNDAR y lo escribe root con una redirección.
# `pg_dump` corre como `postgres` —que es lo que da la autenticación peer y evita
# la contraseña— pero `postgres` no puede escribir en un directorio 0700 de root,
# y ensanchar los permisos del directorio de respaldos para arreglarlo sería
# cambiar el problema por uno peor. Con la redirección, quien escribe es root y
# los respaldos siguen siendo ilegibles para todo lo demás.
if ! sudo -u postgres pg_dump --format=custom --compress=9 --no-password \
      "$DB" > "$PARTIAL" 2>>"$LOG"; then
  fail "pg_dump devolvió error"
fi

[ -f "$PARTIAL" ] || fail "pg_dump no dejó ningún fichero"

SIZE="$(stat -c %s "$PARTIAL")"
log "volcado terminado · ${SIZE} bytes"

# ── 2. Validación ───────────────────────────────────────────────────────
#
# Tres comprobaciones, y ninguna sobra. El código de salida dice que el proceso
# terminó; el tamaño, que escribió algo; y `pg_restore -l`, que lo que escribió
# es un dump que se puede abrir y tiene dentro lo que debería. El `.dump` de cero
# bytes de junio pasó la primera y habría fallado la segunda.
[ "$SIZE" -gt 0 ] || fail "el volcado quedó vacío"
[ "$SIZE" -ge "$MIN_BYTES" ] || fail "volcado sospechosamente pequeño (${SIZE} < ${MIN_BYTES} bytes)"

# `--list` solo parsea el fichero: no necesita base de datos, así que lo lee
# root directamente y no hace falta que `postgres` alcance el directorio.
ENTRIES="$(pg_restore --list "$PARTIAL" 2>>"$LOG" | grep -cE '^[0-9]+;' || true)"
ENTRIES="${ENTRIES:-0}"
[ "${ENTRIES:-0}" -ge "$MIN_ENTRIES" ] \
  || fail "el índice del volcado solo tiene ${ENTRIES:-0} objetos (mínimo ${MIN_ENTRIES}): el fichero se abre pero está incompleto"

log "validación ok · ${ENTRIES} objetos en el índice"

# ── 3. Nombre definitivo, de forma atómica ──────────────────────────────
chmod 0600 "$PARTIAL"
mv -f "$PARTIAL" "$TARGET"

# ── 4. Huella ───────────────────────────────────────────────────────────
#
# Sirve para dos cosas distintas: detectar corrupción en reposo, y poder afirmar
# que el fichero que se restauró es el mismo que se respaldó.
CHECKSUM="$(sha256sum "$TARGET" | awk '{print $1}')"
printf '%s  %s\n' "$CHECKSUM" "$(basename "$TARGET")" > "${TARGET}.sha256"
chmod 0600 "${TARGET}.sha256"

DURATION=$(( $(date +%s) - STARTED_EPOCH ))
log "respaldo válido · $(basename "$TARGET") · ${SIZE} bytes · ${DURATION}s · sha256 ${CHECKSUM:0:12}…"

# ── 5. Retención ────────────────────────────────────────────────────────
#
# Se ejecuta DESPUÉS de tener un respaldo nuevo y validado, nunca antes: limpiar
# primero y fallar después dejaría el sistema con menos respaldos que cuando
# empezó. Y solo se toca este directorio: los snapshots manuales de
# `/root/db-backups` son anteriores a cambios concretos y no los borra nadie.
prune() {
  local kept=0
  # Los diarios más recientes se conservan enteros.
  mapfile -t all < <(find "$DEST" -maxdepth 1 -name "${DB}-*.dump" -printf '%T@ %p\n' | sort -rn | awk '{print $2}')

  for f in "${all[@]}"; do
    kept=$((kept + 1))
    [ "$kept" -le "$KEEP_DAILY" ] && continue

    # Fuera de la ventana diaria se guarda uno por semana ISO.
    local week
    week="$(date -d "@$(stat -c %Y "$f")" +%G-%V)"
    if [ -z "${seen_week[$week]:-}" ] && [ "${#seen_week[@]}" -lt "$KEEP_WEEKLY" ]; then
      seen_week[$week]=1
      continue
    fi

    log "retención: se elimina $(basename "$f")"
    rm -f "$f" "${f}.sha256"
  done
}
declare -A seen_week=()
prune

REMAINING="$(find "$DEST" -maxdepth 1 -name "${DB}-*.dump" | wc -l)"
log "fin · ${REMAINING} respaldos automáticos en ${DEST}"

write_status "ok" "respaldo válido y verificado" "$SIZE" "$CHECKSUM" "$DURATION"
trap - ERR
exit 0

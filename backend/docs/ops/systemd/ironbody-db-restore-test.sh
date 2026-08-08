#!/usr/bin/env bash
#
# Verificación de restauración.
#
# Existe porque un respaldo que nunca se restauró no es un respaldo: es una
# suposición con nombre de fichero. Se puede tener un año de dumps válidos y
# descubrir el día del incendio que faltaba una extensión, que el rol no existe
# en destino, o que el esquema se quedó a medias.
#
# **Nunca toca la base productiva.** Crea una base desechable con nombre propio,
# restaura dentro, comprueba, y la destruye. Si algo falla, la desechable se
# queda para poder mirarla y el código de salida es distinto de cero.
set -Eeuo pipefail

DB="ironbody"
DEST="/root/db-backups/auto"
STATUS="/var/lib/ironbody/restore-test-status.json"
LOG="/var/log/ironbody-backup.log"
LOCK="/var/run/ironbody-db-restore-test.lock"

export TZ="America/Bogota"

TEMP_DB="ironbody_restore_check_$(date +%Y%m%d_%H%M%S)_$$"
STARTED_AT="$(date -Iseconds)"
STARTED_EPOCH="$(date +%s)"

# Tablas que tienen que existir Y tener sentido tras la restauración. La lista es
# la del negocio, no la del esquema: si estas están, el sistema se puede operar.
CRITICAL_TABLES=(
  users admins members plans payments payment_transactions
  electronic_invoices marketing_leads marketing_conversations marketing_messages
  marketing_lead_attributions commercial_events commercial_opportunities
  commercial_approvals commercial_alerts incidents attendances
)

log() { printf '%s [restore-test] %s\n' "$(date -Iseconds)" "$*" | tee -a "$LOG" >&2; }

write_status() {
  local result="$1" message="$2" dump="${3:-}" duration="${4:-0}" rows="${5:-0}"
  install -d -m 0755 "$(dirname "$STATUS")"
  cat > "${STATUS}.part" <<JSON
{
  "result": "${result}",
  "message": "${message}",
  "dump_tested": "${dump}",
  "started_at": "${STARTED_AT}",
  "finished_at": "$(date -Iseconds)",
  "restore_seconds": ${duration},
  "tables_verified": ${#CRITICAL_TABLES[@]},
  "rows_compared": ${rows},
  "temp_database": "${TEMP_DB}",
  "timezone": "America/Bogota"
}
JSON
  mv -f "${STATUS}.part" "$STATUS"
  chmod 0644 "$STATUS"
}

cleanup_temp() {
  sudo -u postgres dropdb --if-exists "$TEMP_DB" 2>>"$LOG" || true
}

fail() {
  local msg="$1"
  log "FALLO: ${msg}"
  write_status "failed" "${msg}" "${DUMP:-}" "$(( $(date +%s) - STARTED_EPOCH ))" 0
  # La desechable NO se borra en caso de fallo: es la única evidencia de en qué
  # estado quedó la restauración, y borrarla sería tirar el cuerpo del delito.
  log "la base desechable ${TEMP_DB} se conserva para inspección"
  exit 1
}

trap 'fail "error inesperado en la línea ${LINENO}"' ERR

exec 9>"$LOCK"
flock -n 9 || { log "otra verificación en curso; se sale"; exit 0; }

touch "$LOG" && chmod 0640 "$LOG"

# ── 1. El respaldo más reciente ─────────────────────────────────────────
DUMP="$(find "$DEST" -maxdepth 1 -name "${DB}-*.dump" -printf '%T@ %p\n' 2>/dev/null | sort -rn | head -1 | awk '{print $2}')"
[ -n "$DUMP" ] || fail "no hay ningún respaldo automático que verificar en ${DEST}"

log "se verifica $(basename "$DUMP")"

# ── 2. Huella antes de tocarlo ──────────────────────────────────────────
if [ -f "${DUMP}.sha256" ]; then
  ( cd "$(dirname "$DUMP")" && sha256sum --quiet --check "$(basename "$DUMP").sha256" ) 2>>"$LOG" \
    || fail "la huella SHA-256 no coincide: el fichero se corrompió en reposo"
  log "huella verificada"
else
  fail "el respaldo no tiene fichero de huella"
fi

# ── 3. Base desechable ──────────────────────────────────────────────────
sudo -u postgres createdb "$TEMP_DB" 2>>"$LOG" || fail "no se pudo crear la base desechable"
log "base desechable creada: ${TEMP_DB}"

# ── 4. Restauración, cronometrada ───────────────────────────────────────
#
# El tiempo que salga de aquí ES el RTO de la base. No se estima: se mide.
RESTORE_START="$(date +%s)"

# `--no-owner --no-privileges`: la desechable no tiene los roles de producción y
# no hace falta que los tenga para comprobar que los datos llegaron. Los errores
# se toleran a nivel de objeto pero se cuentan: si son muchos, algo va mal.
set +e
# El dump se le pasa por la entrada estándar, por lo mismo que en el respaldo:
# `postgres` necesita hablar con la base pero no puede leer el directorio de
# root, y los respaldos no se abren a nadie más para resolver eso.
# Sin `--exit-on-error`: esa opción NO acepta valor —`--exit-on-error=false` es
# un error de sintaxis, no un «no salgas»— y su ausencia ya es el comportamiento
# que se quiere: seguir tras un error de objeto y contarlos al final.
#
# `cd /` antes del sudo porque `postgres` no puede leer /root y, si el cwd
# heredado es ese, cada invocación escupe un aviso que ensucia el log sin ser un
# problema real.
cd /
cat "$DUMP" | sudo -u postgres pg_restore --format=custom --dbname="$TEMP_DB" \
  --no-owner --no-privileges 2>"/tmp/restore-errors.$$"
RESTORE_RC=$?
set -e

RESTORE_SECONDS=$(( $(date +%s) - RESTORE_START ))
# `grep -c` imprime 0 Y sale con código 1 cuando no encuentra nada, así que un
# `|| echo 0` añade un segundo cero y la variable acaba valiendo "0\n0" —que no
# es un número y rompe la comparación de después—. `|| true` conserva el 0 que ya
# imprimió grep.
RESTORE_ERRORS="$(grep -c "^pg_restore: error" "/tmp/restore-errors.$$" 2>/dev/null || true)"
RESTORE_ERRORS="${RESTORE_ERRORS:-0}"
cat "/tmp/restore-errors.$$" >> "$LOG" 2>/dev/null || true
rm -f "/tmp/restore-errors.$$"

log "restauración terminada en ${RESTORE_SECONDS}s · rc=${RESTORE_RC} · errores de objeto=${RESTORE_ERRORS}"

# Unos pocos errores de propiedad son normales restaurando sin los roles; muchos
# significan que el dump no se pudo aplicar.
[ "${RESTORE_ERRORS:-0}" -le 10 ] || fail "la restauración produjo ${RESTORE_ERRORS} errores"

# ── 5. Esquema ──────────────────────────────────────────────────────────
TABLES_PROD="$(sudo -u postgres psql -tAc "select count(*) from information_schema.tables where table_schema='public'" "$DB")"
TABLES_TEMP="$(sudo -u postgres psql -tAc "select count(*) from information_schema.tables where table_schema='public'" "$TEMP_DB")"

log "tablas: producción=${TABLES_PROD} restaurada=${TABLES_TEMP}"

[ "$TABLES_TEMP" -gt 0 ] || fail "la base restaurada no tiene ninguna tabla"
[ "$TABLES_TEMP" -eq "$TABLES_PROD" ] \
  || fail "faltan tablas: producción tiene ${TABLES_PROD}, la restaurada ${TABLES_TEMP}"

# ── 6. Tablas críticas y conteos ────────────────────────────────────────
#
# Se comparan CANTIDADES, nunca contenido: aquí hay datos de personas reales y
# una verificación de respaldo no es motivo para pasearlos por un log.
MISMATCH=0
COMPARED=0

for t in "${CRITICAL_TABLES[@]}"; do
  exists="$(sudo -u postgres psql -tAc "select count(*) from information_schema.tables where table_schema='public' and table_name='${t}'" "$TEMP_DB")"
  if [ "$exists" != "1" ]; then
    log "  ✗ ${t}: no existe en la base restaurada"
    MISMATCH=$((MISMATCH + 1))
    continue
  fi

  n_prod="$(sudo -u postgres psql -tAc "select count(*) from \"${t}\"" "$DB" 2>/dev/null || echo ERR)"
  n_temp="$(sudo -u postgres psql -tAc "select count(*) from \"${t}\"" "$TEMP_DB" 2>/dev/null || echo ERR)"
  COMPARED=$((COMPARED + 1))

  if [ "$n_prod" = "$n_temp" ]; then
    log "  ✓ ${t}: ${n_temp} filas"
  else
    # Una diferencia pequeña es esperable si la base cambió entre el volcado y
    # ahora. Se informa con el signo para poder distinguir «creció después» de
    # «se perdieron filas».
    log "  ~ ${t}: producción ${n_prod}, restaurada ${n_temp} (la base siguió viva tras el volcado)"
    if [ "$n_temp" -gt "$n_prod" ] 2>/dev/null; then
      log "  ✗ ${t}: la restaurada tiene MÁS filas que producción; eso no se explica por el tiempo"
      MISMATCH=$((MISMATCH + 1))
    fi
  fi
done

[ "$MISMATCH" -eq 0 ] || fail "${MISMATCH} tabla(s) crítica(s) no superaron la comprobación"

# ── 7. Una consulta de negocio de verdad ────────────────────────────────
#
# Que las tablas existan no prueba que el esquema sirva. Esto ejerce una unión
# real —miembro, usuario, pago— que es la que sostiene media aplicación.
JOINED="$(sudo -u postgres psql -tAc "
  select count(*) from members m
  join users u on u.id = m.user_id
  left join payments p on p.member_id = m.id
" "$TEMP_DB" 2>>"$LOG" || echo ERR)"

[ "$JOINED" != "ERR" ] || fail "la unión miembro-usuario-pago falla en la base restaurada: el esquema no es utilizable"
log "unión de negocio verificada · ${JOINED} filas"

# ── 8. Destruir la desechable ───────────────────────────────────────────
cleanup_temp
log "base desechable eliminada"

TOTAL=$(( $(date +%s) - STARTED_EPOCH ))
log "VERIFICACIÓN OK · restauración ${RESTORE_SECONDS}s · total ${TOTAL}s · ${COMPARED} tablas comparadas"

write_status "ok" "restauración verificada sobre base desechable" "$(basename "$DUMP")" "$RESTORE_SECONDS" "$COMPARED"
trap - ERR
exit 0

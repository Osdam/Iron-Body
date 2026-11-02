#!/bin/bash
# Ensayo de respaldo y restauración en el banco, con el procedimiento de docs/ops/systemd/ironbody-db-backup.sh (volcado
# -Fc -Z9 a .part, validación de tamaño e índice, nombre definitivo, 600 y .sha256) y de ironbody-db-restore-test.sh
# (huella, base desechable, restauración cronometrada, comparación), pero comparando el recuento EXACTO de TODAS las
# tablas y el esquema del Studio, y de dos formas: con dueños (la que deja la base lista para operar: iron dueño,
# iron_app solo DML) y con --no-owner (la del apartado «Restauración real» de BACKUPS.md). Nunca toca la base del banco:
# restaura en ultron_studio_banco_h7_rest_<modo>, que se borra al final salvo que algo falle.
# Uso: bash ensayo-restauracion.sh <banco>    (código 0 = todos los casos OK)
. "$(dirname "$0")/comun.sh"
B=$(banco_dir "${1:-}")
cargar_banco "$B"
Q() { psql -X -Atq -v ON_ERROR_STOP=1 -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$1" -c "$2"; }
DEST="$B/respaldos"; ( umask 077; mkdir -p "$DEST" ); chmod 700 "$DEST"
STAMP=$(date +%Y-%m-%d_%H-%M-%S); DUMP="$DEST/ironbody-$STAMP.dump"
# Recuento exacto de cada tabla de public (una consulta) y la forma del Studio.
Q_RECUENTO="SELECT string_agg(c.relname || '=' || (xpath('/row/n/text()', query_to_xml(format('SELECT count(*) AS n FROM public.%I', c.relname), false, true, '')))[1]::text, ' ' ORDER BY c.relname) FROM pg_class c JOIN pg_namespace s ON s.oid = c.relnamespace WHERE c.relkind = 'r' AND s.nspname = 'public';"
Q_STUDIO="SELECT (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron_studio_%' AND t.tgenabled = 'O')||' '||(SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE 'ultron_studio_%')||' '||(SELECT count(*) FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid WHERE t.relname LIKE 'ultron_studio_%' AND i.indpred IS NOT NULL)||' '||(SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'ultron_studio_%')||' '||(SELECT md5(pg_get_functiondef(oid)) FROM pg_proc WHERE proname = 'ultron_studio_forbid');"
Q_DUENOS="SELECT (SELECT string_agg(DISTINCT tableowner, ',') FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'ultron_studio_%')||' '||(SELECT string_agg(has_table_privilege('h5_app', 'public.' || t, 'INSERT')||'/'||has_any_column_privilege('h5_app', 'public.' || t, 'UPDATE')||'/'||has_table_privilege('h5_app', 'public.' || t, 'DELETE'), ' ' ORDER BY t) FROM unnest(ARRAY['ultron_studio_publications', 'ultron_studio_audit_events', 'ultron_studio_integration_snapshots', 'ultron_studio_canary_numbers']) AS t);"

echo "== respaldo y restauración en el banco ($BANCO_DB)"
# 1. El volcado, como el timer de producción.
S=$(date +%s)
( umask 077; pg_dump --format=custom --compress=9 --no-password -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" "$BANCO_DB" > "$DUMP.part" )
TAM=$(stat -f '%z' "$DUMP.part"); ENTR=$(pg_restore --list "$DUMP.part" | grep -cE '^[0-9]+;' || true)
chmod 600 "$DUMP.part"; mv -f "$DUMP.part" "$DUMP"
( cd "$DEST" && shasum -a 256 "$(basename "$DUMP")" > "$(basename "$DUMP").sha256" && chmod 600 "$(basename "$DUMP").sha256" )
caso "volcado -Fc -Z9: validado (tamaño > 200 kB e índice ≥ 200 objetos) y renombrado" "$( [ "$TAM" -gt 204800 ] && [ "$ENTR" -ge 200 ] && echo 0 || echo 1)" "$TAM bytes · $ENTR objetos · $(( $(date +%s) - S )) s"
caso "permisos: directorio 700, volcado y huella 600" "$( [ "$(stat -f '%Lp' "$DEST")" = 700 ] && [ "$(stat -f '%Lp' "$DUMP")" = 600 ] && [ "$(stat -f '%Lp' "$DUMP.sha256")" = 600 ] && echo 0 || echo 1)"
( cd "$DEST" && shasum -a 256 -c --status "$(basename "$DUMP").sha256" ); caso "la huella SHA-256 casa" "$?"
TABLAS=$(pg_restore -l "$DUMP" | grep -c 'TABLE DATA' || true)
caso "el volcado lista ≥ 226 tablas con datos (el umbral del despliegue del H7)" "$( [ "$TABLAS" -ge 226 ] && echo 0 || echo 1)" "$TABLAS"
ORIGEN=$(Q "$BANCO_DB" "$Q_RECUENTO"); ESTUDIO0=$(Q "$BANCO_DB" "$Q_STUDIO"); DUENOS0=$(Q "$BANCO_DB" "$Q_DUENOS")
echo "   origen: $(tr ' ' '\n' <<< "$ORIGEN" | wc -l | tr -d ' ') tablas · Studio (disparadores activos · CHECK · parciales · tablas · md5 de F): $ESTUDIO0 · dueños y h5_app (INSERT/UPDATE/DELETE): $DUENOS0"

for MODO in con-duenos sin-duenos; do
  R="ultron_studio_banco_h7_rest_${MODO//-/_}"
  psql -X -q -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d postgres -c "DROP DATABASE IF EXISTS $R;" -c "CREATE DATABASE $R;"
  # El esquema public con los mismos permisos que el de producción (en una base nueva, PUBLIC tiene CREATE).
  Q "$R" "REVOKE CREATE ON SCHEMA public FROM PUBLIC;" > /dev/null
  OPC=(); [ "$MODO" = sin-duenos ] && OPC=(--no-owner)
  S=$(date +%s)
  ERR=$(pg_restore --format=custom ${OPC[@]+"${OPC[@]}"} -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" --dbname="$R" < "$DUMP" 2>&1 | grep -c '^pg_restore: error' || true)
  RTO=$(( $(date +%s) - S ))
  caso "[$MODO] pg_restore sin errores de objeto" "$( [ "$ERR" = 0 ] && echo 0 || echo 1)" "$ERR errores · restauración $RTO s"
  DESTINO=$(Q "$R" "$Q_RECUENTO")
  if [ "$DESTINO" = "$ORIGEN" ]; then caso "[$MODO] recuento EXACTO de las $(tr ' ' '\n' <<< "$ORIGEN" | wc -l | tr -d ' ') tablas, igual al origen" 0
  else caso "[$MODO] recuento exacto de todas las tablas" 1 "$(diff <(tr ' ' '\n' <<< "$ORIGEN") <(tr ' ' '\n' <<< "$DESTINO") | head -5 | tr '\n' ' ')"; fi
  caso "[$MODO] Studio: los mismos disparadores ACTIVOS, CHECK, parciales, tablas y función de F" "$( [ "$(Q "$R" "$Q_STUDIO")" = "$ESTUDIO0" ] && echo 0 || echo 1)" "$(Q "$R" "$Q_STUDIO")"
  DUENOS=$(Q "$R" "$Q_DUENOS")
  if [ "$MODO" = con-duenos ]; then
    caso "[$MODO] dueño h5_owner y h5_app con ar en la cadena, el libro y las instantáneas (arwd en canarios), como el origen" "$( [ "$DUENOS" = "$DUENOS0" ] && echo 0 || echo 1)" "$DUENOS"
  else
    echo "   [$MODO] dueños y h5_app tras --no-owner: $DUENOS (origen: $DUENOS0)"
    caso "[$MODO] HALLAZGO: con --no-owner las tablas dejan de ser del dueño (el siguiente ironbody-migrate fallaría con 42501)" "$( [ "${DUENOS%% *}" != h5_owner ] && echo 0 || echo 1)" "dueño ${DUENOS%% *}"
  fi
  # La cadena restaurada verifica con la APP_KEY de este entorno (otra APP_KEY la dejaría en fallback).
  RC=0; ( cd "$B/api/backend" && env APP_CONFIG_CACHE="$B/run/no-cache.php" DB_DATABASE="$R" php artisan ultron-studio:publication-check > /dev/null 2>&1 ) || RC=$?
  caso "[$MODO] la cadena restaurada verifica (publication-check 0, con la APP_KEY del banco)" "$RC"
  psql -X -q -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d postgres -c "DROP DATABASE $R;"
done
rm -f "$DUMP" "$DUMP.sha256"   # el respaldo del ensayo es del banco (sintético): no es un respaldo de nadie
echo "RESPALDO Y RESTAURACIÓN EN EL BANCO: $((CASOS - FALLOS))/$CASOS OK"
[ "$FALLOS" = 0 ]

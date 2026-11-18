#!/usr/bin/env bash
# Despliegue del backend: bandeja de valoraciones recibidas en Seguimiento (CONTRATO_BANDEJA_SEGUIMIENTO.md),
# sobre el ciclo de valoraciones (finalización automática al «Enviar al miembro»,
# seguimiento con intervalos, solicitudes desde la app, buscador sin tildes, horario de silencio
# y detector de 60 días). Contratos: docs/agenda-valoraciones/CONTRATO_CICLO_VALORACIONES.md y
# CONTRATO_SOLICITUDES_VALORACION.md.
#
# Se ejecuta EN EL SERVIDOR como root, DESPUÉS del respaldo (que va justo antes):
#   TS=$(date -u +%Y%m%d-%H%M%S); BK=/var/backups/ironbody/ciclo-valoraciones-$TS
#   ( umask 077; mkdir -p "$BK"
#     git -C /var/www/api rev-parse HEAD > "$BK/api-commit.txt"
#     sudo -u postgres pg_dump -Fc ironbody > "$BK/ironbody.dump"
#     sha256sum "$BK/ironbody.dump" > "$BK/ironbody.dump.sha256"
#     cp -a /var/www/api/backend/bootstrap/cache "$BK/bootstrap-cache" )
#   bash deploy-ciclo-valoraciones.sh <commit esperado de origin/main (8)> "$BK" [--solo-comprobar]
#
# CUATRO migraciones, solo aditivas, que se aplican SOLAS (--path) antes del código:
#   · índice único parcial valuation_appointments_assessment_uq (la columna hoy está vacía: 0 de 49);
#   · columna valuation_settings.follow_up_interval_days (60 en la fila existente);
#   · tabla member_valuation_follow_ups;
#   · tabla valuation_requests (con una pendiente por socio como máximo).
# El código viejo convive con las cuatro: no lee las tablas nuevas ni la columna.
#
# NO corrige datos. La reconciliación de citas pasadas va APARTE, con su propio respaldo,
# primero en ensayo: sudo -u www-data php artisan valuations:link-assessments [--apply]
#
# VUELTA ATRÁS
#   código:   cd /var/www/api && git checkout --detach "$(cat $BK/api-commit.txt)"
#             cd backend && sudo -u www-data sh -c 'umask 077; php artisan config:cache -q && php artisan route:cache -q'
#             sudo -u www-data php artisan queue:restart -q
#   esquema:  las tablas, la columna y el índice pueden quedarse (el código viejo los ignora). Para
#             quitarlos: php artisan migrate:rollback con el rol dueño y --path a la copia de las migraciones.
set -euo pipefail
export LC_ALL=C
umask 022

[ "$#" -ge 2 ] && [ "$#" -le 3 ] || { echo "uso: $0 <commit esperado (8)> <respaldo> [--solo-comprobar]; no se ha tocado nada"; exit 2; }
ESPERADO="$1"; BK="$2"; MODO="${3:-}"
[[ "$ESPERADO" =~ ^[0-9a-f]{8}$ ]] || { echo "el commit esperado va con 8 cifras hexadecimales; no se ha tocado nada"; exit 2; }
[ -z "$MODO" ] || [ "$MODO" = --solo-comprobar ] || { echo "argumento desconocido: $MODO; no se ha tocado nada"; exit 2; }
[ "$(id -u)" = 0 ] || { echo "se ejecuta como root; no se ha tocado nada"; exit 2; }

API_DIR=/var/www/api
B=$API_DIR/backend
WWW=www-data
API=https://api.ironbodyneiva.cloud/api
LOG=$B/storage/logs/laravel.log
NGX=/var/log/nginx/access.log
MIGS="2026_11_19_100005_add_admin_review_to_professional_assessments_table"
# Fuera de storage/: de root y sin escritura para www-data (revisión de seguridad del 13-11).
MIGDIR=/var/lib/ironbody-deploy/bandeja-seguimiento-mig

# Los ÚNICOS ficheros que se ejecutan y pueden cambiar con esta ola (se rellena con el diff final).
LISTA_CERRADA="backend/app/Http/Controllers/Api/Admin/ValuationController.php
backend/app/Http/Controllers/Api/Admin/ValuationFollowUpController.php
backend/app/Services/Valuations/ValuationFollowUps.php
backend/app/Support/Access/AuthorizationMap.php
backend/database/migrations/2026_11_19_100005_add_admin_review_to_professional_assessments_table.php
backend/routes/api.php"

aborta() { echo "ABORTA: $*"; exit 1; }
# ¿Está registrada? Se mira la tabla migrations (migrate:status no ve las aplicadas con --path).
migrada() { ( cd "$B" && sudo -u "$WWW" php artisan tinker --execute="echo Illuminate\Support\Facades\DB::table('migrations')->where('migration', '$1')->exists() ? 'si' : 'no';" 2>/dev/null | tail -1 ); }
cd "$API_DIR"

echo "0. comprobaciones (solo lectura)"
[ -s "$BK/api-commit.txt" ] && [ -s "$BK/ironbody.dump" ] && [ -s "$BK/ironbody.dump.sha256" ] || aborta "falta el respaldo en $BK"
( cd "$BK" && sha256sum -c --quiet ironbody.dump.sha256 ) || aborta "el volcado no cuadra con su sha256"
PREVIO=$(git rev-parse HEAD)
[ "$PREVIO" = "$(cat "$BK/api-commit.txt")" ] || aborta "HEAD cambió desde el respaldo ($PREVIO): rehacer el respaldo"
[ -z "$(git status --porcelain --untracked-files=no)" ] || aborta "hay ficheros RASTREADOS modificados en producción"
git fetch -q origin
OBJ=$(git rev-parse --short=8 origin/main)
[ "$OBJ" = "$ESPERADO" ] || aborta "origin/main es $OBJ y se esperaba $ESPERADO: revisar qué entró antes de seguir"
git merge-base --is-ancestor HEAD origin/main || aborta "origin/main no desciende de HEAD: no es avance rápido"
CAMBIAN=$(git diff --name-only HEAD origin/main | grep -v -E '^backend/(docs|tests)/' | grep -v -E '^(docs|n8n/respaldos|n8n/flujos)/' || true)
FUERA=$(comm -23 <(printf '%s\n' "$CAMBIAN" | sed '/^$/d' | sort) <(printf '%s\n' "$LISTA_CERRADA" | sort))
[ -z "$FUERA" ] || aborta "cambian ficheros que se ejecutan y NO son de esta ola: $(echo $FUERA)"
NUEVAS=$(git diff --name-only --diff-filter=A HEAD origin/main -- backend/database/migrations | sort)
[ "$NUEVAS" = "$(printf '%s\n' $MIGS | sed 's#^#backend/database/migrations/#; s#$#.php#' | sort)" ] || aborta "migraciones nuevas inesperadas: $(echo $NUEVAS)"
RUN0=$(supervisorctl status 2>/dev/null | grep -c RUNNING || true)
NO_RUN0=$(supervisorctl status 2>/dev/null | grep -c -E 'BACKOFF|FATAL|EXITED|STOPPED' || true)
L0=$(wc -l < "$LOG" 2>/dev/null || echo 0)
N0=$(wc -l < "$NGX")
echo "   HEAD $PREVIO → $OBJ · $(printf '%s\n' "$CAMBIAN" | sed '/^$/d' | wc -l) ficheros de código · workers RUNNING $RUN0 (otros: $NO_RUN0)"
[ "$NO_RUN0" = 0 ] || aborta "hay workers fuera de RUNNING antes de empezar"
[ "$MODO" != --solo-comprobar ] || { echo "== --solo-comprobar: no se ha tocado nada"; exit 0; }

echo "1. migraciones SOLAS, con el código viejo sirviendo (aditivas)"
command -v ironbody-migrate >/dev/null || aborta "falta el envoltorio ironbody-migrate"
APLICADAS=$( cd "$B" && sudo -u "$WWW" php artisan tinker --execute="echo implode(PHP_EOL, Illuminate\Support\Facades\DB::table('migrations')->pluck('migration')->all());" 2>/dev/null )
PENDIENTES=$(comm -23 <(git ls-tree --name-only origin/main backend/database/migrations/ | sed -E 's#.*/##; s#\.php$##' | sort) <(printf '%s\n' "$APLICADAS" | sort) | grep -v -x -F "$MIGS" || true)
[ -z "$PENDIENTES" ] || aborta "hay otras migraciones pendientes: $(echo $PENDIENTES)"
( umask 022; install -d -o root -g root -m 755 "$(dirname "$MIGDIR")" "$MIGDIR" )
rm -f "$MIGDIR"/*.php
for MIG in $MIGS; do
  git show "origin/main:backend/database/migrations/$MIG.php" > "$MIGDIR/$MIG.php"
  chown root:root "$MIGDIR/$MIG.php"; chmod 644 "$MIGDIR/$MIG.php"
  [ "$(sha256sum < "$MIGDIR/$MIG.php")" = "$(git show "origin/main:backend/database/migrations/$MIG.php" | sha256sum)" ] \
    || aborta "la migración $MIG copiada no coincide con git"
done
# Una sola migración (100005), aditiva.
PEND=0; for MIG in $MIGS; do [ "$(migrada "$MIG")" = si ] || PEND=1; done
if [ "$PEND" = 1 ]; then
  ironbody-migrate --path="$MIGDIR" --realpath \
    || aborta "la migración falló (si es 55P03, lock timeout: no deja restos; repetir)"
fi
for MIG in $MIGS; do [ "$(migrada "$MIG")" = si ] || aborta "$MIG no consta en la tabla migrations"; done
# La columna nueva y el relleno: las valoraciones ya enviadas quedan revisadas (no «nuevas» falsas).
PRIV=$(cd "$B" && sudo -u "$WWW" php artisan tinker --execute="echo json_encode(Illuminate\Support\Facades\DB::selectOne(\"select (select count(*) from information_schema.columns where table_name='professional_assessments' and column_name in ('admin_reviewed_at','admin_reviewed_by_admin_id')) c, (select count(*) from professional_assessments where submitted_at is not null and admin_reviewed_at is null) n\"));" 2>/dev/null | tail -1)
echo "   columnas y pendientes de revisar: $PRIV"
echo "$PRIV" | grep -q '"c":2' || aborta "faltan las columnas admin_reviewed_* en professional_assessments"

echo "2. código: merge por avance rápido (root, umask 022)"
MARCA=$(mktemp)
git merge --ff-only -q origin/main
[ "$(git rev-parse --short=8 HEAD)" = "$ESPERADO" ] || aborta "HEAD no quedó en $ESPERADO"
for f in $(git diff --name-only "$PREVIO" HEAD -- '*.php'); do
  [ -f "$f" ] || continue
  sudo -u "$WWW" php -l "$f" > /dev/null || aborta "$WWW no puede leer o compilar $f"
done
NOLEE=$(find .git -newer "$MARCA" ! -perm -o=r 2>/dev/null | head -3)
[ -z "$NOLEE" ] || aborta ".git con ficheros que otros no leen (umask): $NOLEE"
rm -f "$MARCA"

echo "3. cachés como $WWW y reinicio ordenado de los workers"
cd "$B"
sudo -u "$WWW" sh -c 'umask 077; php artisan config:cache -q && php artisan route:cache -q'
for f in bootstrap/cache/config.php bootstrap/cache/routes-v7.php; do
  [ -f "$f" ] && [ ! -L "$f" ] || aborta "$f no es un fichero normal"
  [ "$(stat -c '%U' "$f")" = "$WWW" ] || aborta "$f no es de $WWW (los workers quedarían en BACKOFF)"
done
sudo -u "$WWW" php artisan queue:restart -q

echo "4. comprobaciones después"
RUTAS=$(sudo -u "$WWW" php artisan route:list --path=valuation 2>/dev/null)
for R in 'api/admin/valuations/follow-ups/recent' 'api/admin/valuations/follow-ups/{assessmentUuid}/reviewed'; do
  printf '%s\n' "$RUTAS" | grep -qF "$R" || aborta "la ruta $R no está registrada"
done
for U in "admin/valuations/follow-ups/recent"; do
  c=$(curl -s --max-time 20 -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$API/$U") || c=000
  echo "   GET $U sin sesión → $c (esperado 401: protegida, sin efectos)"
  [ "$c" = 401 ] || aborta "$U sin sesión respondió $c"
done
# queue:restart deja que cada worker termine su trabajo: alguno tarda más de 8 s en volver
# (14-11: 13 de 14 a los 8 s y 14 a los 20 s; ver docs/iron-ia/ops/deploy-solo-codigo.sh).
# Se espera hasta 30 s antes de juzgar.
for _ in 1 2 3 4 5 6 7 8 9 10; do
  sleep 3
  RUN1=$(supervisorctl status 2>/dev/null | grep -c RUNNING || true)
  [ "$RUN1" = "$RUN0" ] && break
done
NO_RUN1=$(supervisorctl status 2>/dev/null | grep -c -E 'BACKOFF|FATAL' || true)
echo "   workers RUNNING $RUN1 (antes $RUN0) · BACKOFF/FATAL $NO_RUN1"
[ "$RUN1" = "$RUN0" ] && [ "$NO_RUN1" = 0 ] || aborta "los workers no volvieron igual que antes"
ERR=$(tail -n +"$((L0 + 1))" "$LOG" 2>/dev/null | grep -c 'production.ERROR' || true)
X5=$(tail -n +"$((N0 + 1))" "$NGX" | awk '$9 ~ /^5/' | wc -l)
echo "   laravel.log ERROR nuevos: $ERR · nginx 5xx nuevos: $X5"
echo "== desplegado $ESPERADO (antes $PREVIO). Respaldo: $BK"
echo "   Sin pasos aparte: no hay datos que corregir."

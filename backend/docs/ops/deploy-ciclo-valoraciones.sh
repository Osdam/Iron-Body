#!/usr/bin/env bash
# Despliegue del backend: ciclo de valoraciones (finalización automática al «Enviar al miembro»,
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
MIGS="2026_11_19_100001_add_assessment_link_unique_index_to_valuation_appointments
2026_11_19_100002_add_follow_up_interval_days_to_valuation_settings
2026_11_19_100003_create_member_valuation_follow_ups_table
2026_11_19_100004_create_valuation_requests_table"
# Fuera de storage/: de root y sin escritura para www-data (revisión de seguridad del 13-11).
MIGDIR=/var/lib/ironbody-deploy/ciclo-valoraciones-mig

# Los ÚNICOS ficheros que se ejecutan y pueden cambiar con esta ola (se rellena con el diff final).
LISTA_CERRADA="backend/app/Console/Commands/DetectEvaluationOutdated.php
backend/app/Console/Commands/EmitAutomationEvents.php
backend/app/Console/Commands/LinkValuationAssessments.php
backend/app/Http/Controllers/Api/Admin/ValuationController.php
backend/app/Http/Controllers/Api/Admin/ValuationFollowUpController.php
backend/app/Http/Controllers/Api/Admin/ValuationRequestController.php
backend/app/Http/Controllers/Api/Admin/ValuationSettingsController.php
backend/app/Http/Controllers/Api/AppValuationRequestController.php
backend/app/Models/MemberValuationFollowUp.php
backend/app/Models/ValuationRequest.php
backend/app/Models/ValuationSetting.php
backend/app/Services/Fcm/FcmService.php
backend/app/Services/Trainer/ProfessionalAssessmentService.php
backend/app/Services/TrainerTaskService.php
backend/app/Services/Valuations/MemberHasFutureAppointmentException.php
backend/app/Services/Valuations/ValuationAgenda.php
backend/app/Services/Valuations/ValuationFollowUps.php
backend/app/Services/Valuations/ValuationMessages.php
backend/app/Services/Valuations/ValuationNotifier.php
backend/app/Services/Valuations/ValuationReminderProcessor.php
backend/app/Services/Valuations/ValuationRequests.php
backend/app/Services/Valuations/ValuationSettings.php
backend/app/Support/Access/AuthorizationMap.php
backend/database/migrations/2026_11_19_100001_add_assessment_link_unique_index_to_valuation_appointments.php
backend/database/migrations/2026_11_19_100002_add_follow_up_interval_days_to_valuation_settings.php
backend/database/migrations/2026_11_19_100003_create_member_valuation_follow_ups_table.php
backend/database/migrations/2026_11_19_100004_create_valuation_requests_table.php
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
# Las cuatro en un solo --path: se aplican en orden de nombre (100001 … 100004).
PEND=0; for MIG in $MIGS; do [ "$(migrada "$MIG")" = si ] || PEND=1; done
if [ "$PEND" = 1 ]; then
  ironbody-migrate --path="$MIGDIR" --realpath \
    || aborta "la migración falló (si es 55P03, lock timeout: no deja restos; repetir)"
fi
for MIG in $MIGS; do [ "$(migrada "$MIG")" = si ] || aborta "$MIG no consta en la tabla migrations"; done
# iron_app hereda los privilegios por defecto de iron: compruébalo en las tablas nuevas y el índice.
PRIV=$(cd "$B" && sudo -u "$WWW" php artisan tinker --execute="echo json_encode(Illuminate\\Support\\Facades\\DB::selectOne(\"select (has_table_privilege(current_user, 'member_valuation_follow_ups', 'SELECT') and has_table_privilege(current_user, 'member_valuation_follow_ups', 'INSERT') and has_table_privilege(current_user, 'member_valuation_follow_ups', 'UPDATE') and has_table_privilege(current_user, 'valuation_requests', 'SELECT') and has_table_privilege(current_user, 'valuation_requests', 'INSERT') and has_table_privilege(current_user, 'valuation_requests', 'UPDATE')) t, (has_sequence_privilege(current_user, 'member_valuation_follow_ups_id_seq', 'USAGE') and has_sequence_privilege(current_user, 'valuation_requests_id_seq', 'USAGE')) s, (select count(*) from pg_indexes where indexname in ('valuation_appointments_assessment_uq', 'valuation_requests_one_pending_per_member')) i, (select count(*) from information_schema.columns where table_name='valuation_settings' and column_name='follow_up_interval_days') c, (select follow_up_interval_days from valuation_settings order by id limit 1) d\"));" 2>/dev/null | tail -1)
echo "   privilegios, índices y columna: $PRIV"
echo "$PRIV" | grep -q '"t":true' && echo "$PRIV" | grep -q '"s":true' && echo "$PRIV" | grep -q '"i":2' && echo "$PRIV" | grep -q '"c":1' \
  || aborta "faltan privilegios de iron_app en las tablas nuevas, un índice o la columna follow_up_interval_days"

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
sudo -u "$WWW" php artisan list valuations 2>/dev/null | grep -q 'valuations:link-assessments' || aborta "la orden valuations:link-assessments no aparece"
for R in 'admin/valuations/requests' 'admin/valuations/members/{member}/follow-up' 'app/valuation-requests/current'; do
  sudo -u "$WWW" php artisan route:list --path="$R" 2>/dev/null | grep -q "${R%%/\{*}" || aborta "la ruta $R no está registrada"
done
for U in "admin/valuations/requests" "app/valuation-requests/current"; do
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
echo "   Siguiente paso (aparte, con respaldo propio): valuations:link-assessments en ensayo y luego --apply"

#!/usr/bin/env bash
# Despliegue del backend: reclasificación de cobros + canales de venta de planes + vencimientos
# de personalizados (docs/ops/RECLASIFICACION-DE-COBROS.md, docs/ops/VENCIMIENTOS-PERSONALIZADOS.md).
#
# Se ejecuta EN EL SERVIDOR como root, DESPUÉS del respaldo (que va justo antes):
#   TS=$(date -u +%Y%m%d-%H%M%S); BK=/var/backups/ironbody/subsanacion-reclasificacion-$TS
#   ( umask 077; mkdir -p "$BK"
#     git -C /var/www/api rev-parse HEAD > "$BK/api-commit.txt"
#     sudo -u postgres pg_dump -Fc ironbody > "$BK/ironbody.dump"
#     sha256sum "$BK/ironbody.dump" > "$BK/ironbody.dump.sha256"
#     cp -a /var/www/api/backend/bootstrap/cache "$BK/bootstrap-cache" )
#   bash deploy-subsanacion-reclasificacion.sh <commit esperado de origin/main (8)> "$BK" [--solo-comprobar]
#
# DOS migraciones, solo aditivas, que se aplican SOLAS (--path) antes del código:
#   · tabla nueva payment_reclassifications (la constancia de cada reclasificación);
#   · columna plans.counter_sellable (boolean NOT NULL DEFAULT true; false en los archivados).
#     En PostgreSQL 11+ añadir una columna con valor constante no reescribe la tabla.
# El código viejo convive con las dos: no lee la tabla nueva y no conoce la columna.
#
# NO corrige ningún dato de socios. La reclasificación concreta va APARTE, con su propio
# respaldo, primero en ensayo (sin --apply) y luego aplicada:
#   sudo -u www-data php artisan payments:reclassify-to-commission --payment=… [ver el runbook]
#
# VUELTA ATRÁS
#   código:   cd /var/www/api && git checkout --detach "$(cat $BK/api-commit.txt)"
#             cd backend && sudo -u www-data sh -c 'umask 077; php artisan config:cache -q && php artisan route:cache -q'
#             sudo -u www-data php artisan queue:restart -q
#   esquema:  la columna y la tabla pueden quedarse (el código viejo las ignora). Si hubiera que
#             quitarlas, SOLO si payment_reclassifications está vacía (si tiene filas es la
#             constancia de una corrección financiera y no se borra):
#             sudo -u postgres psql -d ironbody -v ON_ERROR_STOP=1 -c "BEGIN;
#               DROP TABLE payment_reclassifications; ALTER TABLE plans DROP COLUMN counter_sellable;
#               DELETE FROM migrations WHERE migration IN ('2026_11_18_100001_create_payment_reclassifications_table',
#                 '2026_11_18_100002_add_counter_sellable_to_plans_table'); COMMIT;"
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
MIGS="2026_11_18_100001_create_payment_reclassifications_table
2026_11_18_100002_add_counter_sellable_to_plans_table"
# Fuera de storage/: de root y sin escritura para www-data (revisión de seguridad del 13-11).
MIGDIR=/var/lib/ironbody-deploy/subsanacion-reclasificacion-mig

# Los ÚNICOS ficheros que se ejecutan y pueden cambiar con esta ola (se rellena con el diff final).
LISTA_CERRADA="backend/app/Console/Commands/BillingCancelTestRequestsCommand.php
backend/app/Console/Commands/ReclassifyPaymentToCommissionCommand.php
backend/app/Http/Controllers/Api/Admin/MemberPaymentHistoryController.php
backend/app/Http/Controllers/Api/Admin/ReceivableController.php
backend/app/Http/Controllers/Api/Admin/TrainerCommissionController.php
backend/app/Http/Controllers/Api/AppPaymentController.php
backend/app/Http/Controllers/Api/MembershipPlanController.php
backend/app/Http/Controllers/Api/PaymentController.php
backend/app/Http/Controllers/Api/PlanController.php
backend/app/Http/Controllers/Api/WompiPaymentController.php
backend/app/Models/Payment.php
backend/app/Models/PaymentReclassification.php
backend/app/Models/Plan.php
backend/app/Models/ReceivablePayment.php
backend/app/Models/TrainerCommissionAgreement.php
backend/app/Models/TrainerCommissionCharge.php
backend/app/Rules/PlanSellableAtCounter.php
backend/app/Services/Billing/PendingInvoiceCancellation.php
backend/app/Services/Caja/CashShiftPdfService.php
backend/app/Services/Caja/CashShiftReport.php
backend/app/Services/Caja/ReceiptSourceResolver.php
backend/app/Services/Commissions/TrainerCommissions.php
backend/app/Services/Membership/DeletedPlanRestorer.php
backend/app/Services/Membership/PlanArchive.php
backend/app/Services/Payments/PaymentReclassifier.php
backend/app/Services/Payments/PlanUpgrades.php
backend/app/Services/Payments/ReclassificationRejected.php
backend/app/Services/Reports/MoneyLedger.php
backend/app/Services/Subscriptions/MembershipSubscriptionService.php
backend/app/Support/Caja/PaymentOrigin.php
backend/database/migrations/2026_11_18_100001_create_payment_reclassifications_table.php
backend/database/migrations/2026_11_18_100002_add_counter_sellable_to_plans_table.php
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
# Las dos en un solo --path: se aplican en orden de nombre (100001 y 100002).
if [ "$(migrada 2026_11_18_100001_create_payment_reclassifications_table)" = no ] || [ "$(migrada 2026_11_18_100002_add_counter_sellable_to_plans_table)" = no ]; then
  ironbody-migrate --path="$MIGDIR" --realpath \
    || aborta "la migración falló (si es 55P03, lock timeout: no deja restos; repetir)"
fi
for MIG in $MIGS; do [ "$(migrada "$MIG")" = si ] || aborta "$MIG no consta en la tabla migrations"; done
# iron_app hereda los privilegios por defecto de iron: compruébalo uno por uno.
PRIV=$(cd "$B" && sudo -u "$WWW" php artisan tinker --execute="echo json_encode(Illuminate\Support\Facades\DB::selectOne(\"select (has_table_privilege(current_user, 'payment_reclassifications', 'SELECT') and has_table_privilege(current_user, 'payment_reclassifications', 'INSERT') and has_table_privilege(current_user, 'payment_reclassifications', 'UPDATE') and has_table_privilege(current_user, 'payment_reclassifications', 'DELETE')) t, has_sequence_privilege(current_user, 'payment_reclassifications_id_seq', 'USAGE') s, (select count(*) from information_schema.columns where table_name='plans' and column_name='counter_sellable') c\"));" 2>/dev/null | tail -1)
echo "   privilegios y columna: $PRIV"
echo "$PRIV" | grep -q '"t":true' && echo "$PRIV" | grep -q '"s":true' && echo "$PRIV" | grep -q '"c":1' \
  || aborta "iron_app sin privilegios en payment_reclassifications o falta plans.counter_sellable"

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
sudo -u "$WWW" php artisan list payments 2>/dev/null | grep -q 'payments:reclassify-to-commission' || aborta "la orden payments:reclassify-to-commission no aparece"
sudo -u "$WWW" php artisan route:list --path=admin/commissions/follow-up 2>/dev/null | grep -q 'follow-up' || aborta "la ruta de seguimiento de personalizados no está registrada"
c=$(curl -s --max-time 20 -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$API/admin/commissions/follow-up") || c=000
echo "   GET admin/commissions/follow-up sin sesión → $c (esperado 401: protegida, sin efectos)"
[ "$c" = 401 ] || aborta "admin/commissions/follow-up sin sesión respondió $c"
sleep 8
RUN1=$(supervisorctl status 2>/dev/null | grep -c RUNNING || true)
NO_RUN1=$(supervisorctl status 2>/dev/null | grep -c -E 'BACKOFF|FATAL' || true)
echo "   workers RUNNING $RUN1 (antes $RUN0) · BACKOFF/FATAL $NO_RUN1"
[ "$RUN1" = "$RUN0" ] && [ "$NO_RUN1" = 0 ] || aborta "los workers no volvieron igual que antes"
ERR=$(tail -n +"$((L0 + 1))" "$LOG" 2>/dev/null | grep -c 'production.ERROR' || true)
X5=$(tail -n +"$((N0 + 1))" "$NGX" | awk '$9 ~ /^5/' | wc -l)
echo "   laravel.log ERROR nuevos: $ERR · nginx 5xx nuevos: $X5"
echo "== desplegado $ESPERADO (antes $PREVIO). Respaldo: $BK"
echo "   Siguiente paso (aparte, con respaldo propio): el runbook de docs/ops/RECLASIFICACION-DE-COBROS.md"

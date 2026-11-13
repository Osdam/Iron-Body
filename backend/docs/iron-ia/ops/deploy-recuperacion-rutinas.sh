#!/usr/bin/env bash
# Despliegue del backend: recuperación de rutinas de Iron IA + organización muscular única
# (docs/iron-ia/RECUPERACION-RUTINAS.md, docs/iron-ia/ORGANIZACION-MUSCULAR.md).
#
# Se ejecuta EN EL SERVIDOR como root, DESPUÉS del respaldo (que va justo antes):
#   TS=$(date -u +%Y%m%d-%H%M%S); BK=/var/backups/ironbody/iron-ia-recuperacion-$TS
#   ( umask 077; mkdir -p "$BK"
#     git -C /var/www/api rev-parse HEAD > "$BK/api-commit.txt"
#     sudo -u postgres pg_dump -Fc ironbody > "$BK/ironbody.dump"
#     sha256sum "$BK/ironbody.dump" > "$BK/ironbody.dump.sha256"
#     cp -a /var/www/api/backend/bootstrap/cache "$BK/bootstrap-cache" )
#   bash deploy-recuperacion-rutinas.sh <commit esperado de origin/main (8)> "$BK" [--solo-comprobar]
#
# UNA migración, solo aditiva (tabla nueva iron_ia_recovery_items). Se aplica SOLA, con
# --path, antes del código: si hubiera otras pendientes de otras sesiones, no se tocan.
# Nada se ejecuta contra los socios: la campaña (iron-ia:recover-routines) va aparte y empieza
# por la simulación.
#
# VUELTA ATRÁS
#   código:   cd /var/www/api && git checkout --detach "$(cat $BK/api-commit.txt)"
#             cd backend && sudo -u www-data sh -c 'umask 077; php artisan config:cache -q && php artisan route:cache -q'
#             sudo -u www-data php artisan queue:restart -q
#   tabla:    solo si la campaña no ha hecho nada (la tabla es la evidencia). Como postgres, por
#             autenticación local (peer), sin ninguna contraseña en la línea de órdenes:
#             sudo -u postgres psql -d ironbody -v ON_ERROR_STOP=1 -c "BEGIN; DROP TABLE iron_ia_recovery_items;
#             DELETE FROM migrations WHERE migration = '2026_11_17_100001_create_iron_ia_recovery_items_table'; COMMIT;"
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
MIG=2026_11_17_100001_create_iron_ia_recovery_items_table
# Fuera de storage/: de root y sin escritura para www-data, que solo necesita leerla. En
# storage/ (de www-data) alguien con www-data podría cambiarla entre la copia y la
# ejecución con el rol dueño (revisión de seguridad del 13-11).
MIGDIR=/var/lib/ironbody-deploy/iron-ia-recuperacion-mig

# Los ÚNICOS ficheros que se ejecutan y pueden cambiar con esta ola.
LISTA_CERRADA="backend/app/Console/Commands/RecoverIronIaRoutinesCommand.php
backend/app/Exceptions/IntegralPlanException.php
backend/app/Http/Controllers/Api/AppRoutineController.php
backend/app/Jobs/IronIa/GenerateRecoveryProposal.php
backend/app/Models/IronIaRecoveryItem.php
backend/app/Services/Nutrition/Ai/NutritionAiCostGuard.php
backend/app/Services/Trainer/IntegralPlanGenerationService.php
backend/app/Services/Trainer/IntegralPlanPowerEngine.php
backend/app/Services/Trainer/IntegralPlanStrategy.php
backend/app/Services/Trainer/MemberContextAssembler.php
backend/app/Services/Trainer/Recovery/RoutineRecoveryCampaign.php
backend/app/Services/Trainer/RoutineMuscleOrganizer.php
backend/app/Services/Trainer/RoutinePublisher.php
backend/config/iron_ai.php
backend/database/migrations/$MIG.php"

aborta() { echo "ABORTA: $*"; exit 1; }
# ¿Está ya registrada? Se mira la tabla migrations: migrate:status solo enumera los ficheros
# de database/migrations, y el de esta ola no está ahí hasta el merge (13-11).
migrada() { ( cd "$B" && sudo -u "$WWW" php artisan tinker --execute="echo Illuminate\Support\Facades\DB::table('migrations')->where('migration', '$MIG')->exists() ? 'si' : 'no';" 2>/dev/null | tail -1 ); }
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
NUEVAS=$(git diff --name-only --diff-filter=A HEAD origin/main -- backend/database/migrations)
[ "$NUEVAS" = "backend/database/migrations/$MIG.php" ] || aborta "migraciones nuevas inesperadas: $(echo $NUEVAS)"
YA=$(migrada)
[ "$YA" = no ] || echo "   la migración ya está aplicada: reanudación (no se vuelve a migrar)"
RUN0=$(supervisorctl status 2>/dev/null | grep -c RUNNING || true)
NO_RUN0=$(supervisorctl status 2>/dev/null | grep -c -E 'BACKOFF|FATAL|EXITED|STOPPED' || true)
L0=$(wc -l < "$LOG" 2>/dev/null || echo 0)
N0=$(wc -l < "$NGX")
echo "   HEAD $PREVIO → $OBJ · $(printf '%s\n' "$CAMBIAN" | sed '/^$/d' | wc -l) ficheros de código · migración nueva: $MIG · workers RUNNING $RUN0 (otros: $NO_RUN0)"
[ "$NO_RUN0" = 0 ] || aborta "hay workers fuera de RUNNING antes de empezar"
[ "$MODO" != --solo-comprobar ] || { echo "== --solo-comprobar: no se ha tocado nada"; exit 0; }

echo "1. migración SOLA, con el código viejo sirviendo (tabla nueva; no toca ninguna existente)"
# La aplicación corre como iron_app (solo DML): migra el rol DUEÑO (iron) por el envoltorio
# ironbody-migrate, que lee su contraseña de /root y no la deja en el .env ni en la caché.
# (El 13-11 el primer intento migró como iron_app y PostgreSQL lo rechazó con 42501, sin restos.)
command -v ironbody-migrate >/dev/null || aborta "falta el envoltorio ironbody-migrate"
# Nada más pendiente: con --path solo corre la de esta ola, pero un código que dependiera de
# otra migración pendiente se rompería al hacer el merge.
APLICADAS=$( cd "$B" && sudo -u "$WWW" php artisan tinker --execute="echo implode(PHP_EOL, Illuminate\Support\Facades\DB::table('migrations')->pluck('migration')->all());" 2>/dev/null )
PENDIENTES=$(comm -23 <(git ls-tree --name-only origin/main backend/database/migrations/ | sed -E 's#.*/##; s#\.php$##' | sort) <(printf '%s\n' "$APLICADAS" | sort) | grep -v -x "$MIG" || true)
[ -z "$PENDIENTES" ] || aborta "hay otras migraciones pendientes: $(echo $PENDIENTES)"
( umask 022; install -d -o root -g root -m 755 "$(dirname "$MIGDIR")" "$MIGDIR" )
git show "origin/main:backend/database/migrations/$MIG.php" > "$MIGDIR/$MIG.php"
chown root:root "$MIGDIR/$MIG.php"; chmod 644 "$MIGDIR/$MIG.php"
# Justo antes de ejecutarla: lo que hay en disco es lo de git.
[ "$(sha256sum < "$MIGDIR/$MIG.php")" = "$(git show "origin/main:backend/database/migrations/$MIG.php" | sha256sum)" ] \
  || aborta "la migración copiada no coincide con git"
if [ "$YA" = no ]; then
  ironbody-migrate --path="$MIGDIR" --realpath \
    || aborta "la migración falló (si es 55P03, lock timeout: no deja restos; repetir)"
fi
[ "$(migrada)" = si ] || aborta "la migración no consta en la tabla migrations"
# iron_app hereda los privilegios por defecto de iron: compruébalo, no lo supongas. Uno por
# uno: con varios en la misma llamada, has_table_privilege da true si tiene CUALQUIERA.
PRIV=$(cd "$B" && sudo -u "$WWW" php artisan tinker --execute="echo json_encode(Illuminate\Support\Facades\DB::selectOne(\"select (has_table_privilege(current_user, 'iron_ia_recovery_items', 'SELECT') and has_table_privilege(current_user, 'iron_ia_recovery_items', 'INSERT') and has_table_privilege(current_user, 'iron_ia_recovery_items', 'UPDATE') and has_table_privilege(current_user, 'iron_ia_recovery_items', 'DELETE')) t, has_sequence_privilege(current_user, 'iron_ia_recovery_items_id_seq', 'USAGE') s\"));" 2>/dev/null | tail -1)
echo "   privilegios de la aplicación sobre la tabla nueva: $PRIV"
echo "$PRIV" | grep -q '"t":true' && echo "$PRIV" | grep -q '"s":true' || aborta "iron_app no tiene SELECT/INSERT/UPDATE/DELETE o USAGE de la secuencia en la tabla nueva"

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
sudo -u "$WWW" php artisan list iron-ia 2>/dev/null | grep -q 'iron-ia:recover-routines' || aborta "la orden iron-ia:recover-routines no aparece"
TABLA=$(sudo -u "$WWW" php artisan tinker --execute="echo Illuminate\Support\Facades\Schema::hasTable('iron_ia_recovery_items') ? 'si' : 'no';" 2>/dev/null | tail -1)
[ "$TABLA" = si ] || aborta "la tabla iron_ia_recovery_items no existe"
c=$(curl -s --max-time 20 -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$API/trainer/exercises") || c=000
echo "   GET trainer/exercises sin sesión → $c (esperado 401: auth.trainer vivo, sin efectos)"
[ "$c" = 401 ] || aborta "trainer/exercises sin sesión respondió $c"
sleep 8
RUN1=$(supervisorctl status 2>/dev/null | grep -c RUNNING || true)
NO_RUN1=$(supervisorctl status 2>/dev/null | grep -c -E 'BACKOFF|FATAL' || true)
echo "   workers RUNNING $RUN1 (antes $RUN0) · BACKOFF/FATAL $NO_RUN1"
[ "$RUN1" = "$RUN0" ] && [ "$NO_RUN1" = 0 ] || aborta "los workers no volvieron igual que antes"
ERR=$(tail -n +"$((L0 + 1))" "$LOG" 2>/dev/null | grep -c 'production.ERROR' || true)
X5=$(tail -n +"$((N0 + 1))" "$NGX" | awk '$9 ~ /^5/' | wc -l)
echo "   laravel.log ERROR nuevos: $ERR · nginx 5xx nuevos: $X5"
echo "== desplegado $ESPERADO (antes $PREVIO). Respaldo: $BK"
echo "   Siguiente paso (aparte): sudo -u $WWW php artisan iron-ia:recover-routines recuperacion-2026-11   # simulación"

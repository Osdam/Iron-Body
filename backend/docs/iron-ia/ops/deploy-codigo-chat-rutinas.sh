#!/usr/bin/env bash
# Despliegue de SOLO CÓDIGO (sin migraciones): el chat de Iron IA deja de ofrecer al socio
# rutinas que no ve (borradores, propuestas de recuperación, retiradas). Lista cerrada abajo.
#
# Se ejecuta EN EL SERVIDOR como root, DESPUÉS del respaldo (que va justo antes):
#   TS=$(date -u +%Y%m%d-%H%M%S); BK=/var/backups/ironbody/iron-ia-chat-$TS
#   ( umask 077; mkdir -p "$BK"
#     git -C /var/www/api rev-parse HEAD > "$BK/api-commit.txt"
#     sudo -u postgres pg_dump -Fc ironbody > "$BK/ironbody.dump"
#     sha256sum "$BK/ironbody.dump" > "$BK/ironbody.dump.sha256"
#     cp -a /var/www/api/backend/bootstrap/cache "$BK/bootstrap-cache" )
#   bash deploy-codigo-chat-rutinas.sh <commit esperado de origin/main (8)> "$BK" [--solo-comprobar]
#
# VUELTA ATRÁS
#   cd /var/www/api && git checkout --detach "$(cat $BK/api-commit.txt)"
#   cd backend && sudo -u www-data sh -c 'umask 077; php artisan config:cache -q && php artisan route:cache -q'
#   sudo -u www-data php artisan queue:restart -q
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
# Los ÚNICOS ficheros que se ejecutan y pueden cambiar con esta ola.
LISTA_CERRADA="backend/app/Services/IronAiService.php"

aborta() { echo "ABORTA: $*"; exit 1; }
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
NUEVAS=$(git diff --name-only HEAD origin/main -- backend/database/migrations)
[ -z "$NUEVAS" ] || aborta "este despliegue es SOLO de código y llegan migraciones: $(echo $NUEVAS)"
RUN0=$(supervisorctl status 2>/dev/null | grep -c RUNNING || true)
NO_RUN0=$(supervisorctl status 2>/dev/null | grep -c -E 'BACKOFF|FATAL|EXITED|STOPPED' || true)
L0=$(wc -l < "$LOG" 2>/dev/null || echo 0)
N0=$(wc -l < "$NGX")
echo "   HEAD $PREVIO → $OBJ · $(printf '%s\n' "$CAMBIAN" | sed '/^$/d' | wc -l) ficheros de código · sin migraciones · workers RUNNING $RUN0 (otros: $NO_RUN0)"
[ "$NO_RUN0" = 0 ] || aborta "hay workers fuera de RUNNING antes de empezar"
[ "$MODO" != --solo-comprobar ] || { echo "== --solo-comprobar: no se ha tocado nada"; exit 0; }

echo "1. código: merge por avance rápido (root, umask 022)"
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

echo "2. cachés como $WWW y reinicio ordenado de los workers"
cd "$B"
sudo -u "$WWW" sh -c 'umask 077; php artisan config:cache -q && php artisan route:cache -q'
for f in bootstrap/cache/config.php bootstrap/cache/routes-v7.php; do
  [ -f "$f" ] && [ ! -L "$f" ] || aborta "$f no es un fichero normal"
  [ "$(stat -c '%U' "$f")" = "$WWW" ] || aborta "$f no es de $WWW (los workers quedarían en BACKOFF)"
done
sudo -u "$WWW" php artisan queue:restart -q

echo "3. comprobaciones después"
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
echo "   La campaña NO se ha ejecutado."

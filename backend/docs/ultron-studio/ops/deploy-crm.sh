#!/bin/bash
# Publica el CRM en producción siguiendo ULTRON_STUDIO_DEPLOYMENT_PLAN.md (sin --delete: pestañas abiertas en caja).
# Uso: deploy-crm.sh <commit-del-build> <commit-esperado-hoy-en-producción> [<worktree del CRM>]
# Falla ante cualquier desviación: build no sellado o sucio, producción movida, origin/main distinto del build.
set -euo pipefail
ESPERADO="${1:?commit del build (version.json)}"; PROD_ACTUAL="${2:?commit que hoy sirve producción}"
WT="${3:-/Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/thermal-receipts/Iron-Body_Front}"
DIST="$WT/dist/frontend/browser"
TS=$(date -u +%Y%m%d-%H%M%S)
BK="/var/backups/ironbody/crm-pre-$ESPERADO-$TS"

echo "== 1. Sello del build"
grep -Eq "\"commit\": *\"$ESPERADO\"" "$DIST/version.json" || { echo "version.json no lleva $ESPERADO:"; cat "$DIST/version.json"; exit 1; }
grep -Eq '"dirty": *false' "$DIST/version.json" || { echo "build sucio"; cat "$DIST/version.json"; exit 1; }
MAIN_NUEVO=$(grep -o 'main-[A-Z0-9]*\.js' "$DIST/index.html" | head -1)
echo "main nuevo: $MAIN_NUEVO"

echo "== 2. Nada se movió"
PUB=$(curl -fsS https://ironbodyneiva.cloud/version.json)
echo "$PUB" | grep -Eq "\"commit\": *\"$PROD_ACTUAL\"" || { echo "producción ya no está en $PROD_ACTUAL: $PUB"; exit 1; }
git -C "$WT" fetch -q origin
[ "$(git -C "$WT" rev-parse --short origin/main)" = "$ESPERADO" ] || { echo "origin/main ($(git -C "$WT" rev-parse --short origin/main)) no es el build $ESPERADO: haz push antes"; exit 1; }
MAIN_VIEJO=$(curl -fsS https://ironbodyneiva.cloud/ | grep -o 'main-[A-Z0-9]*\.js' | head -1)
echo "main en producción: $MAIN_VIEJO"

echo "== 3. Respaldo"
ssh -o BatchMode=yes ironbody-vps "mkdir -p $BK && cp /var/www/app/index.html /var/www/app/version.json $BK/ && ls -la $BK"

echo "== 4. Publicar (sin --delete)"
rsync -a --no-owner --no-group "$DIST/" ironbody-vps:/var/www/app/
ssh -o BatchMode=yes ironbody-vps "chown -R root:root /var/www/app"

echo "== 5. Verificación pública"
sleep 2
curl -fsS https://ironbodyneiva.cloud/version.json; echo
curl -fsS https://ironbodyneiva.cloud/ | grep -o 'main-[A-Z0-9]*\.js' | head -1 | grep -q "$MAIN_NUEVO" && echo "index.html → $MAIN_NUEVO OK"
for f in "$MAIN_NUEVO" "$MAIN_VIEJO"; do printf "%-22s %s\n" "$f" "$(curl -s -o /dev/null -w '%{http_code}' https://ironbodyneiva.cloud/$f)"; done
printf "%-22s %s\n" "/marketing/ultron-studio" "$(curl -s -o /dev/null -w '%{http_code}' https://ironbodyneiva.cloud/marketing/ultron-studio)"
echo "RESPALDO: $BK"

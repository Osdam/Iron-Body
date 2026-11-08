#!/bin/bash
# Fase 1 del Hito 8 en el CRM: la sección «Voz y telefonía» del Studio (ruta diferida `marketing/ultron-studio/voz`,
# chunk `studio-voice-page`) y su enlace en la barra del tablero, con TODO APAGADO en el servidor: la página solo
# ofrece lo que dicen `can`, `flags` y `actions` del backend, que en la fase 1 lo niega todo (409/422 y banderas en
# false). Calca ops/deploy-crm.sh (build sellado, nada se movió, rsync SIN --delete porque la caja del gimnasio puede
# tener el CRM abierto y los chunk-*.js viejos tienen que seguir sirviéndose) y añade lo del H8:
#   0. el backend del H8 YA está en producción (GET /api/admin/voice/status sin token → 401, no 404): publicar el CRM
#      antes dejaría la página nueva hablando con rutas que no existen
#   1. el árbol del CRM limpio en el commit del build, = origin/main, y el commit que hoy sirve producción contenido en
#      él (si no, se borraría trabajo de otro equipo)
#   2. dependencias: npm ci SOLO si package-lock.json cambió desde lo que sirve producción; si no, npm ls tiene que pasar
#   3. build de producción (npm run build:prod: sello + ng build + version.json) y verificación del dist: el sello, el
#      chunk de la voz (el selector app-ultron-studio-voice) y el chunk del tablero con el enlace (data-voz-enlace y la
#      ruta), y que el main del build los importa
#   --solo-comprobar termina aquí: no hace ssh ni publica nada (es el ensayo; sirve también en el Mac sin red al VPS)
#   4. respaldo en el servidor ANTES de tocar (index.html, version.json y un tar de todo /var/www/app; nunca se borra)
#   5. rsync sin --delete y dueños root:root
#   6. verificación pública: version.json, index.html → el main nuevo, el main viejo sigue sirviendo, el chunk de voz y
#      el del tablero sirven (200), y las rutas del Studio y de la voz devuelven la aplicación (200)
# Se ejecuta EN EL MAC (no en el servidor), con el árbol del CRM del H8. Nunca imprime secretos (no los toca).
# Uso: bash deploy-h8-crm-fase1.sh <commit del build (7 cifras)> <commit que hoy sirve producción (7 cifras)> [<árbol del CRM>] [--solo-comprobar]
# Variables (para el ensayo): CRM_REMOTE (ironbody-vps), CRM_WEB (https://ironbodyneiva.cloud),
#   CRM_API (https://api.ironbodyneiva.cloud/api), CRM_DIR (/var/www/app), CRM_BACKUPS (/var/backups/ironbody).
# VUELTA ATRÁS (con la copia de este despliegue, que el script imprime al final; los chunk-*.js nuevos se quedan sin
# nadie que los pida, como los viejos con rsync sin --delete):
#   rápida  : ssh ironbody-vps "cp -p <BK>/index.html <BK>/version.json /var/www/app/ && chown root:root /var/www/app/index.html /var/www/app/version.json"
#   completa: ssh ironbody-vps "tar -xzpf <BK>/app.tgz -C /var/www"   (devuelve también lo que el rsync sobrescribió)
#   y comprobar: curl -fsS https://ironbodyneiva.cloud/version.json → el commit de antes.
set -euo pipefail
export LC_ALL=C
SOLO=""
ARGS=()
for a in "$@"; do case "$a" in --solo-comprobar) SOLO=1 ;; *) ARGS+=("$a") ;; esac; done
[ "${#ARGS[@]}" -ge 2 ] && [ "${#ARGS[@]}" -le 3 ] || { echo "uso: bash deploy-h8-crm-fase1.sh <commit del build> <commit que hoy sirve producción> [<árbol del CRM>] [--solo-comprobar]"; exit 2; }
ESPERADO=${ARGS[0]}; PROD_ACTUAL=${ARGS[1]}
WT=${ARGS[2]:-/Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/ultron-h8/Iron-Body_Front}
[[ "$ESPERADO" =~ ^[0-9a-f]{7}$ ]] && [[ "$PROD_ACTUAL" =~ ^[0-9a-f]{7}$ ]] || { echo "los dos commits van con 7 cifras hexadecimales; no se ha tocado nada"; exit 2; }
REMOTE=${CRM_REMOTE:-ironbody-vps}
WEB=${CRM_WEB:-https://ironbodyneiva.cloud}
API=${CRM_API:-https://api.ironbodyneiva.cloud/api}
DIR=${CRM_DIR:-/var/www/app}
BKS=${CRM_BACKUPS:-/var/backups/ironbody}
DIST="$WT/dist/frontend/browser"
TS=$(date -u +%Y%m%d-%H%M%S)
BK="$BKS/crm-pre-h8-$ESPERADO-$TS"
aborta() { echo "ABORTA: $*"; exit 1; }
g() { git -C "$WT" "$@"; }

echo "== 0. El backend del H8 ya sirve (sin token: 401, no 404)"
c=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$API/admin/voice/status")
[ "$c" = 401 ] || aborta "GET $API/admin/voice/status sin token respondió $c (esperado 401): el backend del H8 no está desplegado; primero deploy-h8-backend-fase1.sh y su smoke"
c=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$API/admin/ultron-studio/voice/publications")
[ "$c" = 401 ] || aborta "GET $API/admin/ultron-studio/voice/publications sin token respondió $c (esperado 401)"
echo "   admin/voice/status y admin/ultron-studio/voice/publications → 401"

echo "== 1. Árbol y commits"
[ -d "$WT/.git" ] || [ -f "$WT/.git" ] || aborta "$WT no es un árbol de git"
[ -z "$(g status --porcelain --untracked-files=no)" ] || aborta "el árbol del CRM tiene cambios sin confirmar: el build tiene que salir de un commit limpio"
g fetch -q origin
[ "$(g rev-parse --short=7 HEAD)" = "$ESPERADO" ] || aborta "HEAD del árbol es $(g rev-parse --short=7 HEAD), no $ESPERADO"
[ "$(g rev-parse --short=7 origin/main)" = "$ESPERADO" ] || aborta "origin/main ($(g rev-parse --short=7 origin/main)) no es el build $ESPERADO: push (avance rápido) antes"
g cat-file -e "$PROD_ACTUAL^{commit}" 2>/dev/null || aborta "el commit de producción $PROD_ACTUAL no existe en el árbol (git fetch)"
g merge-base --is-ancestor "$PROD_ACTUAL" "$ESPERADO" || aborta "$ESPERADO no contiene $PROD_ACTUAL (lo que sirve producción): publicarlo borraría trabajo de otro equipo"
if [ -z "$SOLO" ]; then
  PUB=$(curl -fsS "$WEB/version.json") || aborta "no se pudo leer $WEB/version.json"
  printf '%s' "$PUB" | grep -Eq "\"commit\": *\"$PROD_ACTUAL\"" || aborta "producción ya no está en $PROD_ACTUAL: $PUB"
  MAIN_VIEJO=$(curl -fsS "$WEB/" | grep -o 'main-[A-Z0-9]*\.js' | head -1)
  [ -n "$MAIN_VIEJO" ] || aborta "no se pudo leer el main que sirve producción"
  echo "   producción: $PROD_ACTUAL · main $MAIN_VIEJO"
fi
echo "   árbol limpio en $ESPERADO = origin/main · contiene $PROD_ACTUAL · ficheros del CRM que cambian: $(g diff --name-only "$PROD_ACTUAL" "$ESPERADO" | wc -l | tr -d ' ')"

echo "== 2. Dependencias"
if [ -n "$(g diff --name-only "$PROD_ACTUAL" "$ESPERADO" -- package-lock.json package.json)" ]; then
  echo "   package.json o package-lock.json cambian desde producción: npm ci (las versiones exactas del lock)"
  ( cd "$WT" && npm ci --no-audit --no-fund )
else
  ( cd "$WT" && npm ls --depth=0 > /dev/null 2>&1 ) || aborta "node_modules no casa con package.json (npm ls falla): npm ci en el árbol antes de desplegar"
  echo "   sin cambios de dependencias desde producción; node_modules casa con package.json"
fi

echo "== 3. Build de producción y verificación del dist"
( cd "$WT" && npm run -s build:prod )
grep -Eq "\"commit\": *\"$ESPERADO\"" "$DIST/version.json" || { cat "$DIST/version.json"; aborta "version.json no lleva $ESPERADO"; }
grep -Eq '"dirty": *false' "$DIST/version.json" || { cat "$DIST/version.json"; aborta "build sucio"; }
[ -z "$(g status --porcelain --untracked-files=no)" ] || aborta "el build dejó el árbol sucio (build-info.ts sin restaurar): revisarlo"
MAIN_NUEVO=$(grep -o 'main-[A-Z0-9]*\.js' "$DIST/index.html" | head -1)
[ -n "$MAIN_NUEVO" ] && [ -f "$DIST/$MAIN_NUEVO" ] || aborta "index.html no apunta a un main-*.js del dist"
VOZ=$(grep -l 'app-ultron-studio-voice' "$DIST"/chunk-*.js 2>/dev/null | head -2 | xargs -n1 basename 2>/dev/null || true)
[ "$(printf '%s\n' "$VOZ" | grep -c . || true)" = 1 ] || aborta "el chunk de la voz (selector app-ultron-studio-voice) no está en el dist, o está en más de uno: [$VOZ]"
TABLERO=$(grep -l 'data-voz-enlace' "$DIST"/chunk-*.js 2>/dev/null | xargs -n1 basename 2>/dev/null || true)
[ "$(printf '%s\n' "$TABLERO" | grep -c . || true)" = 1 ] || aborta "el enlace del tablero (data-voz-enlace) no está en un único chunk del dist: [$TABLERO]"
grep -q 'marketing/ultron-studio/voz' "$DIST/$TABLERO" || aborta "el chunk del tablero ($TABLERO) no lleva la ruta marketing/ultron-studio/voz"
# La ruta diferida: el main (o un chunk de rutas que el main importa) nombra el fichero del chunk de voz.
grep -lq "$VOZ" "$DIST/$MAIN_NUEVO" "$DIST"/chunk-*.js 2>/dev/null || aborta "nadie importa $VOZ: la ruta diferida de la voz no está cableada"
BYTES=$(wc -c < "$DIST/$VOZ" | tr -d ' ')
GZ=$(gzip -9 -c "$DIST/$VOZ" | wc -c | tr -d ' ')
echo "   sello $ESPERADO limpio · main $MAIN_NUEVO · chunk de voz $VOZ ($BYTES B, $GZ B con gzip -9) · tablero $TABLERO con el enlace"
if [ -n "$SOLO" ]; then echo "BUILD VERIFICADO (no se ha publicado nada: --solo-comprobar)"; exit 0; fi

echo "== 4. Respaldo en el servidor (antes de tocar; nunca se borra)"
ssh -o BatchMode=yes "$REMOTE" "set -e; umask 077; mkdir -p '$BK' && cp -p '$DIR/index.html' '$DIR/version.json' '$BK/' && tar -C '$(dirname "$DIR")' -czf '$BK/app.tgz' '$(basename "$DIR")' && ls -la '$BK'"

echo "== 5. Publicar (sin --delete)"
rsync -a --no-owner --no-group "$DIST/" "$REMOTE:$DIR/"
ssh -o BatchMode=yes "$REMOTE" "chown -R root:root '$DIR'"

echo "== 6. Verificación pública"
sleep 2
FALLOS=0
PUBV=$(curl -fsS "$WEB/version.json") || { echo "FALLO: no se pudo leer version.json"; FALLOS=$((FALLOS + 1)); }
echo "$PUBV"
printf '%s' "$PUBV" | grep -Eq "\"commit\": *\"$ESPERADO\"" || { echo "FALLO: version.json publicado no lleva $ESPERADO"; FALLOS=$((FALLOS + 1)); }
if curl -fsS "$WEB/" | grep -o 'main-[A-Z0-9]*\.js' | head -1 | grep -qx "$MAIN_NUEVO"; then echo "index.html → $MAIN_NUEVO OK"; else echo "FALLO: index.html publicado no apunta a $MAIN_NUEVO"; FALLOS=$((FALLOS + 1)); fi
for f in "$MAIN_NUEVO" "$MAIN_VIEJO" "$VOZ" "$TABLERO"; do
  c=$(curl -s -o /dev/null -w '%{http_code}' "$WEB/$f"); printf "%-28s %s\n" "$f" "$c"
  [ "$c" = 200 ] || { echo "FALLO: $f responde $c (esperado 200)"; FALLOS=$((FALLOS + 1)); }
done
if curl -fsS "$WEB/$VOZ" | grep -q 'app-ultron-studio-voice'; then echo "el chunk de voz publicado es el del build OK"; else echo "FALLO: el $VOZ publicado no lleva el selector de la voz"; FALLOS=$((FALLOS + 1)); fi
for p in marketing/ultron-studio marketing/ultron-studio/voz; do
  c=$(curl -s -o /dev/null -w '%{http_code}' "$WEB/$p"); printf "%-28s %s\n" "/$p" "$c"
  [ "$c" = 200 ] || { echo "FALLO: /$p responde $c (esperado 200)"; FALLOS=$((FALLOS + 1)); }
done
echo "RESPALDO: $BK (index.html, version.json y app.tgz)"
echo "VUELTA ATRÁS rápida: ssh $REMOTE \"cp -p '$BK/index.html' '$BK/version.json' '$DIR/' && chown root:root '$DIR/index.html' '$DIR/version.json'\""
[ "$FALLOS" = 0 ] && echo "CRM H8 FASE 1 PUBLICADO Y VERIFICADO" || { echo "CRM PUBLICADO CON $FALLOS FALLOS DE VERIFICACIÓN: revisar antes de seguir (respaldo en $BK)"; exit 1; }

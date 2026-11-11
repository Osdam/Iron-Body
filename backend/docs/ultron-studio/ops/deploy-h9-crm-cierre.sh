#!/bin/bash
# Cierre del Hito 9 en el CRM (G-9 de L9-81; OPS9-c, informe-ops9-c.md): el CRM de la ola, que va ANTES que el backend
# (P9-25, plans/informes-h9/informe-p9-25.md §4: el CRM nuevo funciona con el backend de la fase 1 —pide un vale por
# conexión y por foto, con `?scope=`, y el backend viejo ignora `scope`—; al revés, no). Entra: P9-25 en el CRM (un vale
# por conexión, reconexión y foto: auth.service, studio-stream, face-recognition, moderation-realtime), el panel «Estado de
# activación» (L9-79) en «Voz y telefonía» y «Campañas automáticas», H9-E2E-2 (la simulación sintética manda listas
# vacías) y H9-E2E-3, los textos del historial de publicaciones y de la voz. Calca deploy-h8-crm-fase1.sh (build sellado,
# nada se movió, rsync SIN --delete porque la caja del gimnasio puede tener el CRM abierto y los chunk-*.js viejos tienen
# que seguir sirviéndose) y añade:
#   0. el backend que sirve es el del H9 (fase 1 o la ola): admin/ultron-studio/outreach/status y admin/stream-ticket sin
#      token → 401, no 404
#   1. el árbol del CRM limpio en el commit del build, = origin/main, y el commit que hoy sirve producción contenido en él
#   2. dependencias: npm ci SOLO si package-lock.json cambió desde lo que sirve producción; si no, npm ls tiene que pasar
#   3. build de producción (npm run build:prod) y verificación del dist: el sello y los marcadores de la ola (el vale con
#      `stream-ticket?scope=` de P9-25 y los paneles de activación us-activation-panel y ca-activation de L9-79)
#   --solo-comprobar termina aquí: no publica nada (sirve en el Mac sin red al VPS)
#   4. respaldo en el servidor ANTES de tocar (index.html, version.json, un tar de todo el directorio y la LISTA de sus
#      ficheros; nunca se borra)
#   5. rsync SIN --delete y dueños root:root
#   6. verificación: NINGÚN fichero de antes desapareció (la lista de antes ⊆ la de después), version.json, index.html → el
#      main nuevo, el main viejo y chunks viejos siguen sirviendo (200), los marcadores nuevos sirven, y las rutas del
#      Studio y de las campañas devuelven la aplicación (200)
#   7. lo que va DESPUÉS: recargar las pestañas de recepción y del punto facial y, entonces, el backend
# Se ejecuta EN EL MAC, con el árbol del CRM de la ola. Nunca imprime secretos (no los toca).
# Uso: bash deploy-h9-crm-cierre.sh <commit del build (7)> <commit que hoy sirve producción (7)> [<árbol del CRM>] [--solo-comprobar]
# Variables (para el ensayo): CRM_REMOTE (ironbody-vps; «local» = el directorio de este Mac, SOLO en el banco), CRM_WEB
#   (https://ironbodyneiva.cloud), CRM_API (https://api.ironbodyneiva.cloud/api), CRM_DIR (/var/www/app), CRM_BACKUPS
#   (/var/backups/ironbody).
# VUELTA ATRÁS (con el respaldo que imprime al final; los chunk-*.js nuevos se quedan sin nadie que los pida). OJO, ORDEN:
# si el backend de la ola YA está desplegado, PRIMERO su vuelta atrás (deploy-h9-backend-cierre.sh la imprime) y después
# esta: un CRM viejo con el backend de la ola reparte un vale de un solo uso entre streams y pierde las fotos (P9-25).
#   rápida  : ssh ironbody-vps "cp -p <BK>/index.html <BK>/version.json /var/www/app/ && chown root:root /var/www/app/index.html /var/www/app/version.json"
#   completa: ssh ironbody-vps "tar -xzpf <BK>/app.tgz -C /var/www"   (devuelve también lo que el rsync sobrescribió)
#   y comprobar: curl -fsS https://ironbodyneiva.cloud/version.json → el commit de antes; recargar las pestañas.
set -euo pipefail
export LC_ALL=C
SOLO=""
ARGS=()
for a in "$@"; do case "$a" in --solo-comprobar) SOLO=1 ;; *) ARGS+=("$a") ;; esac; done
[ "${#ARGS[@]}" -ge 2 ] && [ "${#ARGS[@]}" -le 3 ] || { echo "uso: bash deploy-h9-crm-cierre.sh <commit del build> <commit que hoy sirve producción> [<árbol del CRM>] [--solo-comprobar]"; exit 2; }
ESPERADO=${ARGS[0]}; PROD_ACTUAL=${ARGS[1]}
WT=${ARGS[2]:-/Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/ultron-h8/Iron-Body_Front}
[[ "$ESPERADO" =~ ^[0-9a-f]{7}$ ]] && [[ "$PROD_ACTUAL" =~ ^[0-9a-f]{7}$ ]] || { echo "los dos commits van con 7 cifras hexadecimales; no se ha tocado nada"; exit 2; }
[ "$ESPERADO" != "$PROD_ACTUAL" ] || { echo "el build y producción son el mismo commit: nada que publicar"; exit 2; }
REMOTE=${CRM_REMOTE:-ironbody-vps}
WEB=${CRM_WEB:-https://ironbodyneiva.cloud}
API=${CRM_API:-https://api.ironbodyneiva.cloud/api}
DIR=${CRM_DIR:-/var/www/app}
BKS=${CRM_BACKUPS:-/var/backups/ironbody}
DIST="$WT/dist/frontend/browser"
TS=$(date -u +%Y%m%d-%H%M%S)
BK="$BKS/crm-pre-h9-cierre-$ESPERADO-$TS"
aborta() { echo "ABORTA: $*"; exit 1; }
g() { git -C "$WT" "$@"; }
# Una orden en el servidor (o, SOLO en el ensayo del banco, en este Mac) y el destino del rsync.
remoto() { if [ "$REMOTE" = local ]; then bash -c "$1"; else ssh -o BatchMode=yes "$REMOTE" "$1"; fi; }
DESTINO="$REMOTE:$DIR/"; [ "$REMOTE" != local ] || DESTINO="$DIR/"
# La lista ordenada de los ficheros del directorio publicado (rutas relativas; find de GNU o de BSD).
LISTAR="cd '$DIR' && find . -type f | sed 's#^\\./##' | LC_ALL=C sort"

echo "== 0. El backend del H9 ya sirve (sin token: 401, no 404)"
for p in admin/ultron-studio/outreach/status "admin/stream-ticket?scope=stream"; do
  c=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$API/$p")
  [ "$c" = 401 ] || aborta "GET $API/$p sin token respondió $c (esperado 401): el backend del H9 (fase 1) no está; este CRM habla con sus rutas"
done
echo "   admin/ultron-studio/outreach/status y admin/stream-ticket → 401 (el backend de la fase 1 o el de la ola; el CRM nuevo vale para los dos)"

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
# Los marcadores de la ola: el vale con su finalidad (P9-25) y los dos paneles de activación (L9-79), cada uno en algún
# fichero del dist (el main o un chunk diferido).
MARCAS=""
for m in 'stream-ticket?scope=' us-activation-panel ca-activation; do
  f=$(grep -lF -- "$m" "$DIST/$MAIN_NUEVO" "$DIST"/chunk-*.js 2>/dev/null | head -1 | xargs -n1 basename 2>/dev/null || true)
  [ -n "$f" ] || aborta "el marcador «$m» no está en el dist: este build no es el de la ola"
  MARCAS="$MARCAS $f"
done
MARCAS=$(printf '%s\n' $MARCAS | LC_ALL=C sort -u | tr '\n' ' ')
echo "   sello $ESPERADO limpio · main $MAIN_NUEVO · marcadores de la ola en: $MARCAS"
if [ -n "$SOLO" ]; then echo "BUILD VERIFICADO (no se ha publicado nada: --solo-comprobar)"; exit 0; fi

echo "== 4. Respaldo en el servidor (antes de tocar; nunca se borra)"
remoto "set -e; umask 077; mkdir -p '$BK' && cp -p '$DIR/index.html' '$DIR/version.json' '$BK/' && tar -C '$(dirname "$DIR")' -czf '$BK/app.tgz' '$(basename "$DIR")' && ($LISTAR) > '$BK/ficheros-antes.txt' && ls -la '$BK'"
N_ANTES=$(remoto "wc -l < '$BK/ficheros-antes.txt'" | tr -d ' ')
VIEJOS=$(remoto "grep -E '^chunk-[A-Z0-9]+\\.js\$' '$BK/ficheros-antes.txt' | head -3" | tr '\n' ' ')
echo "   $N_ANTES ficheros publicados antes (lista en $BK/ficheros-antes.txt)"

echo "== 5. Publicar (sin --delete)"
rsync -a --no-owner --no-group "$DIST/" "$DESTINO"
remoto "chown -R root:root '$DIR'" || [ "$REMOTE" = local ]

echo "== 6. Verificación (nada se borró, y lo público)"
sleep 2
FALLOS=0
DESAPARECIDOS=$(remoto "($LISTAR) | LC_ALL=C comm -23 '$BK/ficheros-antes.txt' - | wc -l" | tr -d ' ')
N_DESPUES=$(remoto "($LISTAR) | wc -l" | tr -d ' ')
echo "   ficheros: $N_ANTES antes → $N_DESPUES después · de los de antes, desaparecidos: $DESAPARECIDOS (esperado 0: rsync sin --delete)"
[ "$DESAPARECIDOS" = 0 ] || { echo "FALLO: $DESAPARECIDOS ficheros de antes ya no están (¿--delete?): las pestañas abiertas pedirán chunks que no existen; la vuelta completa los devuelve"; FALLOS=$((FALLOS + 1)); }
PUBV=$(curl -fsS "$WEB/version.json") || { echo "FALLO: no se pudo leer version.json"; FALLOS=$((FALLOS + 1)); }
echo "$PUBV"
printf '%s' "$PUBV" | grep -Eq "\"commit\": *\"$ESPERADO\"" || { echo "FALLO: version.json publicado no lleva $ESPERADO"; FALLOS=$((FALLOS + 1)); }
if curl -fsS "$WEB/" | grep -o 'main-[A-Z0-9]*\.js' | head -1 | grep -qx "$MAIN_NUEVO"; then echo "index.html → $MAIN_NUEVO OK"; else echo "FALLO: index.html publicado no apunta a $MAIN_NUEVO"; FALLOS=$((FALLOS + 1)); fi
for f in "$MAIN_NUEVO" "$MAIN_VIEJO" $VIEJOS $MARCAS; do
  c=$(curl -s -o /dev/null -w '%{http_code}' "$WEB/$f"); printf "   %-34s %s\n" "$f" "$c"
  [ "$c" = 200 ] || { echo "FALLO: $f responde $c (esperado 200)"; FALLOS=$((FALLOS + 1)); }
done
for p in marketing/ultron-studio marketing/ultron-studio/voz marketing/automatizaciones; do
  c=$(curl -s -o /dev/null -w '%{http_code}' "$WEB/$p"); printf "   %-34s %s\n" "/$p" "$c"
  [ "$c" = 200 ] || { echo "FALLO: /$p responde $c (esperado 200)"; FALLOS=$((FALLOS + 1)); }
done
echo "RESPALDO: $BK (index.html, version.json, app.tgz y ficheros-antes.txt)"
echo "VUELTA ATRÁS rápida (si el backend de la ola ya está, PRIMERO el suyo): ssh $REMOTE \"cp -p '$BK/index.html' '$BK/version.json' '$DIR/' && chown root:root '$DIR/index.html' '$DIR/version.json'\""
if [ "$FALLOS" = 0 ]; then
  echo "== 7. Lo que va DESPUÉS (P9-25), en este orden:"
  echo "   1) recargar las pestañas de RECEPCIÓN y del PUNTO FACIAL (el vigilante de versión avisa en ≤ 5 min y recarga al navegar; la que no navega se queda vieja hasta pulsar «recargar»)"
  echo "   2) esperar 10 minutos y, en el servidor: bash /root/deploy-h9-backend-cierre.sh <commit del backend> <respaldo> --crm=$ESPERADO --solo-comprobar (cuenta en el access.log los vales sin scope: tienen que ser 0)"
  echo "   3) el despliegue del backend con la misma orden sin --solo-comprobar"
  echo "CRM H9 CIERRE PUBLICADO Y VERIFICADO"
else
  echo "CRM PUBLICADO CON $FALLOS FALLOS DE VERIFICACIÓN: NO seguir con el backend; revisar (respaldo en $BK)"; exit 1
fi

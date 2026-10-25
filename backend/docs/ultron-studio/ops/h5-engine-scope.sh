#!/bin/bash
# Hito 5 · alcance de lectura del motor sobre el Studio. Se ejecuta EN EL SERVIDOR como root y SOLO con la
# autorización expresa del propietario para la fase que corresponda. El segundo argumento es el alcance que hay
# HOY: si no coincide con la configuración efectiva, no se toca nada.
#   bash h5-engine-scope.sh canary off      (fase 2: el motor lee en la conversación canaria y el Studio puede publicar)
#   bash h5-engine-scope.sh all canary      (fase 3: todas las conversaciones)
#   bash h5-engine-scope.sh canary all      (vuelta de la fase 3 a la 2)
#   bash h5-engine-scope.sh off canary|all  (vuelta a la fase 1: el motor no consulta la cadena; Legacy exacto)
# off → all directo no se admite: la fase 3 llega después del canario y de su evidencia.
# Escribe las dos llaves de entorno (MARKETING_ULTRON_STUDIO_CONFIG y ULTRON_STUDIO_PUBLISHING_ENABLED) tras una copia
# previa 600 del fichero, regenera la caché de configuración como www-data con umask 077, comprueba que la
# configuración efectiva es la pedida, reinicia los workers y comprueba sanidad, sin imprimir valores sensibles.
# Nunca toca la clave de OpenAI, la pausa, el freno, el canario del motor ni la cadena de publicaciones.
# canary y all abren también la llave de publicar (fases 2 y 3 del contrato, §A5). Para que el motor solo selle sin
# publicaciones nuevas, poner a mano ULTRON_STUDIO_PUBLISHING_ENABLED=false y regenerar la caché igual que aquí (N-9).
set -euo pipefail
export LC_ALL=C
ALCANCE="${1:?uso: h5-engine-scope.sh off|canary|all <alcance actual>}"
DESDE="${2:?falta el alcance actual (off|canary|all): se comprueba antes de tocar nada}"
case "$ALCANCE" in
  off)        PUBLICAR=false ;;
  canary|all) PUBLICAR=true ;;
  *) echo "alcance no válido: $ALCANCE (off|canary|all)"; exit 2 ;;
esac
case "$DESDE" in off|canary|all) ;; *) echo "alcance actual no válido: $DESDE (off|canary|all)"; exit 2 ;; esac
[ "$ALCANCE" != "$DESDE" ] || { echo "el alcance ya es $ALCANCE: nada que hacer"; exit 0; }
[ "$DESDE:$ALCANCE" != "off:all" ] || { echo "off → all directo no: primero canary (fase 2) y su evidencia"; exit 2; }
cd /var/www/api/backend
ENVF=.env
aborta() { echo "ABORTA: $*"; exit 1; }
DESVIOS=0
desvio() { echo "   ✗ $*"; DESVIOS=$((DESVIOS + 1)); }
leer() { # la configuración efectiva en una línea JSON, o nada (la salida de un tinker fallido no se imprime)
  local l
  l=$(sudo -u www-data php artisan tinker --execute='echo json_encode(["engine_scope" => config("marketing.ultron.studio_config"), "publishing_enabled" => (bool) config("ultron_studio.publishing_enabled"), "lab_gate" => config("ultron_studio.publishing.lab_gate"), "canary_set" => config("marketing.ultron.canary_conversation_id") !== null, "ultron_enabled" => (bool) config("marketing.ultron.enabled")]), PHP_EOL;' 2>&1 | tail -1) || true
  case "$l" in '{"engine_scope"'*) printf '%s\n' "$l" ;; esac
}
cuenta_var() { grep -Ec "^[[:space:]]*(export[[:space:]]+)?$1[[:space:]]*=" "$ENVF" || true; }
set_var() { # escribe la clave con la sangría que tuviera la línea (la añade, con dos espacios, si falta)
  if [ "$(cuenta_var "$1")" != 0 ]; then
    sed -i -E "s/^([[:space:]]*)(export[[:space:]]+)?$1[[:space:]]*=.*/\\1$1=$2/" "$ENVF"
  else
    [ -z "$(tail -c1 "$ENVF")" ] || printf '\n' >> "$ENVF"     # sin salto final, la línea nueva se pegaría a la anterior
    printf '  %s=%s\n' "$1" "$2" >> "$ENVF"
  fi
}

ANTES=$(leer)
[ -n "$ANTES" ] || aborta "no se pudo leer la configuración efectiva"
case "$ANTES" in *"\"engine_scope\":\"$DESDE\""*) ;; *) aborta "la configuración efectiva no es la de «$DESDE»: $ANTES" ;; esac
if [ "$ALCANCE" != off ]; then
  case "$ANTES" in *'"canary_set":true'*) ;; *) aborta "el motor no tiene canario configurado: sin él no hay fase 2 ni 3" ;; esac
fi
for k in MARKETING_ULTRON_STUDIO_CONFIG ULTRON_STUDIO_PUBLISHING_ENABLED ULTRON_STUDIO_PUBLISH_LAB_GATE; do
  [ "$(cuenta_var "$k")" -le 1 ] || aborta "$k aparece repetida en el fichero de entorno (gana la última): deduplicar antes"
done
DUENO=$(stat -c '%U:%G' "$ENVF")
TS=$(date -u +%Y%m%d-%H%M%S)
COPIA="/root/env-pre-scope-$ALCANCE-$TS"
install -m 600 -o root -g root "$ENVF" "$COPIA"
echo "0. desde $DESDE · copia previa del fichero de entorno: $COPIA (600, root)"

set_var MARKETING_ULTRON_STUDIO_CONFIG "$ALCANCE"
set_var ULTRON_STUDIO_PUBLISHING_ENABLED "$PUBLICAR"
[ "$(cuenta_var ULTRON_STUDIO_PUBLISH_LAB_GATE)" != 0 ] || set_var ULTRON_STUDIO_PUBLISH_LAB_GATE required
echo "1. llaves → motor=$ALCANCE publicar=$PUBLICAR (permisos $(stat -c '%U %a' "$ENVF"))"
# B-1: el fichero tiene que parsear ANTES de tocar la caché (config:cache la vacía primero; si Laravel no arranca, la API cae).
sudo -u www-data php -r 'require "vendor/autoload.php"; try { Dotenv\Dotenv::createArrayBacked(".")->load(); } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' \
  || aborta "el fichero de entorno NO parsea tras escribir las llaves. Vuelta: install -m 600 -o ${DUENO%%:*} -g ${DUENO##*:} $COPIA $ENVF"
sudo -u www-data sh -c 'umask 077; php artisan config:cache -q'
CACHE=bootstrap/cache/config.php
[ "$(stat -c '%U' "$CACHE")" = www-data ] || aborta "$CACHE no es de www-data: no se reinician los workers (quedarían en BACKOFF). Vuelta: install -m 600 -o ${DUENO%%:*} -g ${DUENO##*:} $COPIA $ENVF"
[ "$(stat -c '%a' "$CACHE")" = 600 ] || chmod 600 "$CACHE"
echo "2. caché: $(stat -c '%U %a' "$CACHE")"
DESPUES=$(leer)
case "$DESPUES" in
  *"\"engine_scope\":\"$ALCANCE\",\"publishing_enabled\":$PUBLICAR,"*) echo "   $DESPUES" ;;
  *) echo "ABORTA: la configuración efectiva no es la pedida: ${DESPUES:-ilegible}"
     echo "   vuelta: install -m 600 -o ${DUENO%%:*} -g ${DUENO##*:} $COPIA $ENVF && sudo -u www-data sh -c 'umask 077; php artisan config:cache -q' && chmod 600 $CACHE"
     exit 1 ;;
esac
sudo -u www-data php artisan queue:restart -q
supervisorctl restart 'ironbody-lab:*' > /dev/null
sleep 8
RUNNING=$(supervisorctl status | grep -c RUNNING || true); MAL=$(supervisorctl status | grep -c 'BACKOFF\|FATAL' || true)
echo "3. workers RUNNING: $RUNNING · BACKOFF/FATAL: $MAL"
[ "$RUNNING" = 13 ] && [ "$MAL" = 0 ] || desvio "workers: $RUNNING RUNNING y $MAL en BACKOFF/FATAL (esperado 13 y 0)"
c=$(curl -s -o /dev/null -w '%{http_code}' https://api.ironbodyneiva.cloud/api/admin/ultron-studio/status)
echo "4. status sin token → $c"; [ "$c" = 401 ] || desvio "status sin token respondió $c"
if sudo -u www-data php artisan ultron-studio:publication-check > /dev/null 2>&1; then echo "5. publication-check: 0 (cuadra)"; else desvio "publication-check no sale con 0: ver «sudo -u www-data php artisan ultron-studio:publication-check»"; fi
if [ "$DESVIOS" = 0 ]; then echo "ALCANCE $ALCANCE APLICADO (copia previa: $COPIA)"; else echo "ALCANCE $ALCANCE APLICADO CON $DESVIOS DESVÍOS (copia previa: $COPIA)"; exit 1; fi

#!/bin/bash
# Motores (Enmienda M-1 del H5) · la llave de los prompts gobernados por la versión publicada. Se ejecuta EN EL SERVIDOR
# como root y SOLO con la orden expresa del propietario para la etapa que corresponda (plan de motores, §Plan paso 8).
# El segundo argumento es la llave que hay HOY: si no coincide con la configuración efectiva, no se toca nada.
#   bash motores-prompts-scope.sh canary off   (etapa E2: decide ofrece prompts a la conversación canaria)
#   bash motores-prompts-scope.sh off canary   (vuelta atrás: decide no ofrece prompts a nadie; n8n S1 usa sus constantes
#                                               Legacy, que son la base byte a byte; el turno siguiente sale «legacy»)
# Solo hay dos valores: «all» no existe en esta entrega (el fichero de configuración lo trata como off).
# Escribe UNA llave de entorno (MARKETING_ULTRON_STUDIO_PROMPTS) tras una copia previa 600 del fichero, regenera la caché
# de configuración como www-data con umask 077, comprueba que la configuración efectiva es la pedida, reinicia los
# workers y comprueba sanidad, sin imprimir valores sensibles. Nunca toca la clave de OpenAI, la pausa, el freno, el
# canario del motor, la llave del sello (MARKETING_ULTRON_STUDIO_CONFIG: es de h5-engine-scope.sh), la de publicar ni la
# cadena de publicaciones.
# Para «canary» exige que el motor ya lea la cadena (MARKETING_ULTRON_STUDIO_CONFIG canary o all) y que tenga canario:
# sin sello declarado no hay prompts, y sin canario nadie los recibe.
set -euo pipefail
export LC_ALL=C
LLAVE="${1:?uso: motores-prompts-scope.sh off|canary <llave actual>}"
DESDE="${2:?falta la llave actual (off|canary): se comprueba antes de tocar nada}"
case "$LLAVE" in off|canary) ;; *) echo "llave no válida: $LLAVE (off|canary)"; exit 2 ;; esac
case "$DESDE" in off|canary) ;; *) echo "llave actual no válida: $DESDE (off|canary)"; exit 2 ;; esac
[ "$LLAVE" != "$DESDE" ] || { echo "la llave ya es $LLAVE: nada que hacer"; exit 0; }
cd /var/www/api/backend
ENVF=.env
aborta() { echo "ABORTA: $*"; exit 1; }
DESVIOS=0
desvio() { echo "   ✗ $*"; DESVIOS=$((DESVIOS + 1)); }
leer() { # la configuración efectiva en una línea JSON, o nada (la salida de un tinker fallido no se imprime)
  local l
  l=$(sudo -u www-data php artisan tinker --execute='echo json_encode(["prompts_scope" => config("marketing.ultron.studio_prompts"), "engine_scope" => config("marketing.ultron.studio_config"), "canary_set" => config("marketing.ultron.canary_conversation_id") !== null, "ultron_enabled" => (bool) config("marketing.ultron.enabled")]), PHP_EOL;' 2>&1 | tail -1) || true
  case "$l" in '{"prompts_scope"'*) printf '%s\n' "$l" ;; esac
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

# MB-5 (revisión de devops, 11-11-2026): todo lo que se imprime queda además en un registro de root (600).
LOG="/root/motores-prompts-scope-$(date -u +%Y%m%d-%H%M%S).log"
( umask 077; : > "$LOG" ) || { echo "no se pudo crear el registro $LOG; no se ha tocado nada"; exit 2; }
exec > >(tee -a "$LOG") 2>&1
# MB-4: exclusión mutua con cualquier otro mando que escriba el fichero de entorno (el común y los del H8 y el H9, que ya
# usan el suyo): la restauración de uno, que copia el fichero ENTERO, no puede pisar el cambio de otro.
exec 7> /run/lock/ironbody-env.lock 8> /run/lock/h8-voice-scope.lock 9> /run/lock/h9-outreach-scope.lock
{ flock -n 7 && flock -n 8 && flock -n 9; } || aborta "otro mando que escribe el fichero de entorno está en curso: esperar a que termine"

ANTES=$(leer)
[ -n "$ANTES" ] || aborta "no se pudo leer la configuración efectiva"
case "$ANTES" in *"\"prompts_scope\":\"$DESDE\""*) ;; *) aborta "la configuración efectiva no es la de «$DESDE»: $ANTES" ;; esac
if [ "$LLAVE" = canary ]; then
  case "$ANTES" in *'"engine_scope":"canary"'*|*'"engine_scope":"all"'*) ;; *) aborta "el motor no lee la cadena (MARKETING_ULTRON_STUDIO_CONFIG=off): primero «bash h5-engine-scope.sh canary off» y su evidencia" ;; esac
  case "$ANTES" in *'"canary_set":true'*) ;; *) aborta "el motor no tiene canario configurado: sin él nadie recibe prompts" ;; esac
  # MB-8: con la llave encendida el Studio publica en /2; si la cadena todavía no lo admite, publicar daría 500.
  DEF=$(cd / && sudo -u postgres psql -X -Atq -d ironbody -c "SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'uspub_engine_contract_chk'" 2>/dev/null) || DEF=""
  case "$DEF" in *"ultron-engine-config/2"*) ;; *) aborta "la cadena de publicaciones todavía no admite el contrato /2 (falta la migración 2026_11_16_100001): no se enciende la llave" ;; esac
fi
[ "$(cuenta_var MARKETING_ULTRON_STUDIO_PROMPTS)" -le 1 ] || aborta "MARKETING_ULTRON_STUDIO_PROMPTS aparece repetida en el fichero de entorno (gana la última): deduplicar antes"
DUENO=$(stat -c '%U:%G' "$ENVF")
MODO=$(stat -c '%a' "$ENVF")     # M1-B: la vuelta atrás deja el fichero con el dueño Y el modo que tenía, no con 600 a ciegas
CACHE=bootstrap/cache/config.php
TS=$(date -u +%Y%m%d-%H%M%S)
COPIA="/root/env-pre-prompts-$LLAVE-$TS"
install -m 600 -o root -g root "$ENVF" "$COPIA" || { rm -f "$COPIA"; aborta "no se pudo hacer la copia previa del fichero de entorno (¿disco lleno?): no se ha tocado nada"; }
ETAPA=0   # 0 nada tocado · 1 fichero escrito · 2 caché regenerada (el valor nuevo ya está activo en FPM) · 3 aplicado
restaurar() { install -m "$MODO" -o "${DUENO%%:*}" -g "${DUENO##*:}" "$COPIA" "$ENVF"; }
al_salir() { # M1-B: un fallo a medias no deja el valor nuevo escrito (ni activo) sin que nadie lo sepa
  local rc=$?
  set +e
  if [ "$ETAPA" = 1 ] || [ "$ETAPA" = 2 ]; then
    echo "FALLO A MEDIAS (etapa $ETAPA, código $rc; 0 si lo cortó una señal): se restaura el fichero de entorno"
    if restaurar; then echo "   fichero de entorno restaurado ($(stat -c '%U:%G %a' "$ENVF"))"; else echo "   ✗ NO se pudo restaurar: install -m $MODO -o ${DUENO%%:*} -g ${DUENO##*:} $COPIA $ENVF"; fi
    if [ "$ETAPA" = 2 ]; then
      if sudo -u www-data sh -c 'umask 077; php artisan config:cache -q' && [ -f "$CACHE" ] && [ ! -L "$CACHE" ] && sudo -u www-data chmod 600 "$CACHE"; then echo "   caché regenerada con el valor anterior"; else echo "   ✗ la caché NO se regeneró: sudo -u www-data sh -c 'umask 077; php artisan config:cache -q' && chmod 600 $CACHE"; fi
      sudo -u www-data php artisan queue:restart -q && echo "   colas reiniciadas"
    fi
    D=$(leer)   # MB-3: no basta el código de salida: se vuelve a leer la configuración efectiva
    if [ -n "$D" ] && [ "$D" = "$ANTES" ]; then echo "   restaurado y COMPROBADO: la configuración efectiva es la de antes"; else echo "   ✗ tras restaurar, la configuración efectiva NO es la de antes: ${D:-ilegible} (antes: $ANTES)"; fi
    echo "   la copia previa se conserva para revisarla: $COPIA (lleva secretos: borrarla con shred -u al terminar)"
  fi
}
trap al_salir EXIT
echo "0. desde $DESDE · copia previa del fichero de entorno: $COPIA (600, root)"

ETAPA=1
set_var MARKETING_ULTRON_STUDIO_PROMPTS "$LLAVE"
echo "1. llave → prompts=$LLAVE (permisos $(stat -c '%U %a' "$ENVF"))"
[ "$(stat -c '%U:%G %a' "$ENVF")" = "$DUENO $MODO" ] || aborta "el fichero de entorno cambió de dueño o de modo al escribir la llave (era $DUENO $MODO)"
# El fichero tiene que parsear ANTES de tocar la caché (config:cache la vacía primero; si Laravel no arranca, la API cae).
sudo -u www-data php -r 'require "vendor/autoload.php"; try { Dotenv\Dotenv::createArrayBacked(".")->load(); } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' \
  || aborta "el fichero de entorno NO parsea tras escribir la llave (se restaura solo)"
ETAPA=2
sudo -u www-data sh -c 'umask 077; php artisan config:cache -q'
[ -f "$CACHE" ] && [ ! -L "$CACHE" ] || aborta "$CACHE no es un fichero normal (¿un enlace?); se restaura solo"
[ "$(stat -c '%U' "$CACHE")" = www-data ] || aborta "$CACHE no es de www-data: no se reinician los workers (quedarían en BACKOFF); se restaura solo"
[ "$(stat -c '%a' "$CACHE")" = 600 ] || sudo -u www-data chmod 600 "$CACHE"   # MB-1: nunca como root en un directorio que escribe www-data
echo "2. caché: $(stat -c '%U %a' "$CACHE")"
DESPUES=$(leer)
case "$DESPUES" in
  *"\"prompts_scope\":\"$LLAVE\","*) echo "   $DESPUES" ;;
  *) echo "ABORTA: la configuración efectiva no es la pedida: ${DESPUES:-ilegible} (se restaura solo)"
     exit 1 ;;
esac
sudo -u www-data php artisan queue:restart -q
supervisorctl restart 'ironbody-lab:*' > /dev/null
ETAPA=3   # aplicado en FPM y en los workers: desde aquí lo que falle es un DESVÍO que se dice, no se deshace solo
sleep 8
# 13 programas de producción y, desde el 11-11-2026, ironbody-voice si el H8 lo instaló (14). El reinicio de las colas
# deja algún worker unos segundos en STARTING: se espera hasta 45 s antes de darlo por desvío.
W_ESPERADOS=$( n=$(supervisorctl status 2>/dev/null | grep -c '^ironbody-voice:' || true); [ "${n:-0}" -ge 1 ] && echo 14 || echo 13 )
HASTA=$(( $(date +%s) + 45 ))
while :; do
  RUNNING=$(supervisorctl status | grep -c RUNNING || true); MAL=$(supervisorctl status | grep -c 'BACKOFF\|FATAL' || true)
  { [ "$RUNNING" = "$W_ESPERADOS" ] && [ "$MAL" = 0 ]; } || [ "$(date +%s)" -ge "$HASTA" ] && break
  sleep 3
done
echo "3. workers RUNNING: $RUNNING · BACKOFF/FATAL: $MAL (esperado $W_ESPERADOS y 0)"
[ "$RUNNING" = "$W_ESPERADOS" ] && [ "$MAL" = 0 ] || desvio "workers: $RUNNING RUNNING y $MAL en BACKOFF/FATAL (esperado $W_ESPERADOS y 0)"
c=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 https://api.ironbodyneiva.cloud/api/admin/ultron-studio/status) || c=000   # I-1 del reviewer: si curl falla, es un desvío que se dice, no una muerte muda en la etapa 3
echo "4. status sin token → $c"; [ "$c" = 401 ] || desvio "status sin token respondió $c"
# La traza (solo lectura): la llave efectiva y lo que decide ofrecería AHORA a la canaria. Sin ids ni textos.
T=$(sudo -u www-data php artisan ultron:prompt-trace --json --last=1 2>/dev/null \
  | php -r '$t = json_decode(stream_get_contents(STDIN), true); if (! is_array($t)) exit(1); echo $t["key"], " ", $t["current"]["mode"], " ", $t["current"]["reason"] ?? ("versión ".substr((string) $t["current"]["version"], 0, 8)), PHP_EOL;' 2>/dev/null) || T=""
echo "5. traza: ${T:-ilegible} (llave · modo · motivo o versión)"
case "$T" in "$LLAVE "*) ;; *) desvio "la traza no confirma la llave $LLAVE: ver «sudo -u www-data php artisan ultron:prompt-trace»" ;; esac
[ "$LLAVE" != off ] || case "$T" in "off legacy prompts_off") ;; *) desvio "con la llave en off la oferta tiene que ser «legacy prompts_off»" ;; esac
if sudo -u www-data php artisan ultron-studio:publication-check > /dev/null 2>&1; then echo "6. publication-check: 0 (cuadra)"; else desvio "publication-check no sale con 0: ver «sudo -u www-data php artisan ultron-studio:publication-check»"; fi
if [ "$DESVIOS" = 0 ]; then
  # M1-B: la copia lleva TODOS los secretos del fichero de entorno; aplicada sin desvíos ya no hace falta (la vuelta atrás
  # es este mismo mando con la llave anterior). Se borra, y con ella las copias de este mando de más de 14 días.
  shred -u "$COPIA" 2>/dev/null || rm -f "$COPIA"
  VIEJAS=$(find /root -maxdepth 1 -type f -name 'env-pre-prompts-*' -mtime +14 2>/dev/null | wc -l)
  [ "$VIEJAS" = 0 ] || { find /root -maxdepth 1 -type f -name 'env-pre-prompts-*' -mtime +14 -exec shred -u {} \; 2>/dev/null; echo "   $VIEJAS copias antiguas de este mando borradas (más de 14 días)"; }
  echo "LLAVE DE PROMPTS $LLAVE APLICADA (copia previa borrada; vuelta atrás: bash motores-prompts-scope.sh $DESDE $LLAVE)"
else
  echo "LLAVE DE PROMPTS $LLAVE ESCRITA CON $DESVIOS DESVÍOS: no seguir hasta aclararlos (copia previa, con secretos: $COPIA; vuelta atrás: bash motores-prompts-scope.sh $DESDE $LLAVE)"; exit 1
fi

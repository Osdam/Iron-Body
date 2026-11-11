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

# MB-5 (revisión de devops, 11-11-2026): todo lo que se imprime queda además en un registro de root (600).
LOG="/root/h5-engine-scope-$(date -u +%Y%m%d-%H%M%S).log"
( umask 077; : > "$LOG" ) || { echo "no se pudo crear el registro $LOG; no se ha tocado nada"; exit 2; }
exec > >(tee -a "$LOG") 2>&1
# MB-4: exclusión mutua con cualquier otro mando que escriba el fichero de entorno (el común y los del H8 y el H9, que ya
# usan el suyo): la restauración de uno, que copia el fichero ENTERO, no puede pisar el cambio de otro.
exec 7> /run/lock/ironbody-env.lock 8> /run/lock/h8-voice-scope.lock 9> /run/lock/h9-outreach-scope.lock
{ flock -n 7 && flock -n 8 && flock -n 9; } || aborta "otro mando que escribe el fichero de entorno está en curso: esperar a que termine"

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
MODO=$(stat -c '%a' "$ENVF")     # M1-B (revisión de seguridad del 11-11-2026): la vuelta atrás deja el dueño Y el modo que tenía
CACHE=bootstrap/cache/config.php
TS=$(date -u +%Y%m%d-%H%M%S)
COPIA="/root/env-pre-scope-$ALCANCE-$TS"
install -m 600 -o root -g root "$ENVF" "$COPIA" || { rm -f "$COPIA"; aborta "no se pudo hacer la copia previa del fichero de entorno (¿disco lleno?): no se ha tocado nada"; }
ETAPA=0   # 0 nada tocado · 1 fichero escrito · 2 caché regenerada (el valor nuevo ya está activo en FPM) · 3 aplicado
restaurar() { install -m "$MODO" -o "${DUENO%%:*}" -g "${DUENO##*:}" "$COPIA" "$ENVF"; }
al_salir() { # un fallo a medias no deja el alcance nuevo escrito (ni activo) sin que nadie lo sepa
  local rc=$?
  set +e
  if [ "$ETAPA" = 1 ] || [ "$ETAPA" = 2 ]; then
    echo "FALLO A MEDIAS (etapa $ETAPA, código $rc; 0 si lo cortó una señal): se restaura el fichero de entorno"
    if restaurar; then echo "   fichero de entorno restaurado ($(stat -c '%U:%G %a' "$ENVF"))"; else echo "   ✗ NO se pudo restaurar: install -m $MODO -o ${DUENO%%:*} -g ${DUENO##*:} $COPIA $ENVF"; fi
    if [ "$ETAPA" = 2 ]; then
      if sudo -u www-data sh -c 'umask 077; php artisan config:cache -q' && [ -f "$CACHE" ] && [ ! -L "$CACHE" ] && sudo -u www-data chmod 600 "$CACHE"; then echo "   caché regenerada con el alcance anterior"; else echo "   ✗ la caché NO se regeneró: sudo -u www-data sh -c 'umask 077; php artisan config:cache -q' && chmod 600 $CACHE"; fi
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
set_var MARKETING_ULTRON_STUDIO_CONFIG "$ALCANCE"
set_var ULTRON_STUDIO_PUBLISHING_ENABLED "$PUBLICAR"
[ "$(cuenta_var ULTRON_STUDIO_PUBLISH_LAB_GATE)" != 0 ] || set_var ULTRON_STUDIO_PUBLISH_LAB_GATE required
echo "1. llaves → motor=$ALCANCE publicar=$PUBLICAR (permisos $(stat -c '%U %a' "$ENVF"))"
[ "$(stat -c '%U:%G %a' "$ENVF")" = "$DUENO $MODO" ] || aborta "el fichero de entorno cambió de dueño o de modo al escribir las llaves (era $DUENO $MODO)"
# B-1: el fichero tiene que parsear ANTES de tocar la caché (config:cache la vacía primero; si Laravel no arranca, la API cae).
sudo -u www-data php -r 'require "vendor/autoload.php"; try { Dotenv\Dotenv::createArrayBacked(".")->load(); } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' \
  || aborta "el fichero de entorno NO parsea tras escribir las llaves (se restaura solo)"
ETAPA=2
sudo -u www-data sh -c 'umask 077; php artisan config:cache -q'
[ -f "$CACHE" ] && [ ! -L "$CACHE" ] || aborta "$CACHE no es un fichero normal (¿un enlace?); se restaura solo"
[ "$(stat -c '%U' "$CACHE")" = www-data ] || aborta "$CACHE no es de www-data: no se reinician los workers (quedarían en BACKOFF); se restaura solo"
[ "$(stat -c '%a' "$CACHE")" = 600 ] || sudo -u www-data chmod 600 "$CACHE"   # MB-1: nunca como root en un directorio que escribe www-data
echo "2. caché: $(stat -c '%U %a' "$CACHE")"
DESPUES=$(leer)
case "$DESPUES" in
  *"\"engine_scope\":\"$ALCANCE\",\"publishing_enabled\":$PUBLICAR,"*) echo "   $DESPUES" ;;
  *) echo "ABORTA: la configuración efectiva no es la pedida: ${DESPUES:-ilegible} (se restaura solo)"
     exit 1 ;;
esac
sudo -u www-data php artisan queue:restart -q
supervisorctl restart 'ironbody-lab:*' > /dev/null
ETAPA=3   # aplicado en FPM y en los workers: desde aquí lo que falle es un DESVÍO que se dice, no se deshace solo
sleep 8
# 13 programas de producción y, desde el 11-11-2026, ironbody-voice si el H8 lo instaló (como h6 y h7). El reinicio
# de las colas deja algún worker unos segundos en STARTING: se espera hasta 45 s antes de darlo por desvío.
W_ESPERADOS=$( n=$(supervisorctl status 2>/dev/null | grep -c '^ironbody-voice:' || true); [ "${n:-0}" -ge 1 ] && echo 14 || echo 13 )
HASTA=$(( $(date +%s) + 45 ))
while :; do
  RUNNING=$(supervisorctl status | grep -c RUNNING || true); MAL=$(supervisorctl status | grep -c 'BACKOFF\|FATAL' || true)
  { [ "$RUNNING" = "$W_ESPERADOS" ] && [ "$MAL" = 0 ]; } || [ "$(date +%s)" -ge "$HASTA" ] && break
  sleep 3
done
echo "3. workers RUNNING: $RUNNING · BACKOFF/FATAL: $MAL (esperado $W_ESPERADOS y 0)"
[ "$RUNNING" = "$W_ESPERADOS" ] && [ "$MAL" = 0 ] || desvio "workers: $RUNNING RUNNING y $MAL en BACKOFF/FATAL (esperado $W_ESPERADOS y 0)"
c=$(curl -s -o /dev/null -w '%{http_code}' https://api.ironbodyneiva.cloud/api/admin/ultron-studio/status)
echo "4. status sin token → $c"; [ "$c" = 401 ] || desvio "status sin token respondió $c"
if sudo -u www-data php artisan ultron-studio:publication-check > /dev/null 2>&1; then echo "5. publication-check: 0 (cuadra)"; else desvio "publication-check no sale con 0: ver «sudo -u www-data php artisan ultron-studio:publication-check»"; fi
if [ "$DESVIOS" = 0 ]; then   # m-3: la palabra APLICADO solo sale en verde
  # M1-B: la copia lleva TODOS los secretos del fichero de entorno; aplicado sin desvíos ya no hace falta (la vuelta atrás
  # es este mismo mando con el alcance anterior). Se borra, y con ella las copias de este mando de más de 14 días.
  shred -u "$COPIA" 2>/dev/null || rm -f "$COPIA"
  VIEJAS=$(find /root -maxdepth 1 -type f -name 'env-pre-scope-*' -mtime +14 2>/dev/null | wc -l)
  [ "$VIEJAS" = 0 ] || { find /root -maxdepth 1 -type f -name 'env-pre-scope-*' -mtime +14 -exec shred -u {} \; 2>/dev/null; echo "   $VIEJAS copias antiguas de este mando borradas (más de 14 días)"; }
  echo "ALCANCE $ALCANCE APLICADO (copia previa borrada; vuelta atrás: bash h5-engine-scope.sh $DESDE $ALCANCE)"
else
  echo "ALCANCE $ALCANCE ESCRITO CON $DESVIOS DESVÍOS: no seguir con el smoke ni publicar hasta aclararlos (copia previa, con secretos: $COPIA; vuelta atrás: bash h5-engine-scope.sh $DESDE $ALCANCE)"; exit 1
fi

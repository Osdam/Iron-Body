#!/bin/bash
# Hito 6 · los dos mandos de la fase 2 y de la vuelta atrás de nivel 2 (contrato «Despliegue por fases»). Son
# INDEPENDIENTES: reconocer la configuración no depende de ninguno y la sonda no exige las escrituras.
#   bash /root/h6-integrations-scope.sh writes on|off <estado actual on|off>   ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED
#   bash /root/h6-integrations-scope.sh probe  on|off <estado actual on|off>   ULTRON_STUDIO_TWILIO_PROBE_ENABLED
#   bash /root/h6-integrations-scope.sh estado                                  solo lee e imprime la configuración efectiva
# Sin el estado actual no se toca nada: se imprime la configuración efectiva y se sale con 2. Si el estado actual no
# coincide con la configuración efectiva, tampoco.
# Se ejecuta EN EL SERVIDOR como root, con la fase 1 del H6 desplegada y la autorización que corresponda (fase 2:
# autorización del 25-10-2026; vuelta atrás: inmediata). Orden: copia previa 600 del fichero de entorno en /root →
# la llave (con la sangría que tuviera; dos espacios si falta), reescrita sobre el mismo fichero para conservar dueño
# y modo → el fichero parsea → config:cache como www-data con umask 077 y 600 → la configuración efectiva es la
# pedida y lo demás no cambió → queue:restart → 13/0 workers dos veces → 401 → instantánea de sistema (registra el
# cambio de huella: `probe` cambia la de Twilio; `writes`, ninguna). «APLICADO» solo sale con 0 desvíos.
# Nunca imprime valores del fichero de entorno ni la salida de un tinker que no case con lo esperado. No toca el
# motor, ni la pausa, ni el freno, ni el canario, ni las claves de Twilio, Meta o ElevenLabs. No reinicia FPM ni
# ningún programa de supervisor: estas dos llaves solo las lee el proceso web, que lee la caché en cada petición.
set -euo pipefail
export LC_ALL=C
umask 077
uso() {
  echo "uso: bash /root/h6-integrations-scope.sh writes|probe on|off <estado actual on|off>"
  echo "     bash /root/h6-integrations-scope.sh estado"
}
[ "$#" -le 3 ] || { uso; exit 2; }
MANDO="${1:-}"; VALOR="${2:-}"; DESDE="${3:-}"
case "$MANDO" in
  writes) CLAVE=ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED; CAMPO=writes_enabled ;;
  probe)  CLAVE=ULTRON_STUDIO_TWILIO_PROBE_ENABLED; CAMPO=probe_enabled ;;
  estado) [ "$#" = 1 ] || { uso; exit 2; } ;;
  *) uso; exit 2 ;;
esac
if [ "$MANDO" != estado ]; then
  case "$VALOR" in on|off) ;; *) echo "valor no válido: «$VALOR» (on|off)"; uso; exit 2 ;; esac
  case "$DESDE" in on|off|"") ;; *) echo "estado actual no válido: «$DESDE» (on|off)"; uso; exit 2 ;; esac
fi
[ "$(id -u)" = 0 ] || { echo "se ejecuta como root en el servidor"; exit 2; }
cd /var/www/api/backend
ENVF=.env
API=https://api.ironbodyneiva.cloud/api
aborta() { echo "ABORTA: $*"; exit 1; }
DESVIOS=0
desvio() { echo "   ✗ $*"; DESVIOS=$((DESVIOS + 1)); }
booleano() { [ "$1" = on ] && echo true || echo false; }
# La misma lectura que el paso 7 de deploy-h6-backend-fase1.sh: banderas, topes, nombres de variables y booleanos de
# presencia; nunca un valor. Si tinker falla, nada (su salida podría arrastrar una línea del fichero de entorno).
leer() {
  local l
  l=$(sudo -u www-data php artisan tinker --execute='$p = "ultron_studio.integrations."; echo json_encode(["writes_enabled" => config($p."writes_enabled"), "probe_enabled" => config($p."twilio_probe.enabled"), "probe_cooldown_s" => config($p."twilio_probe.cooldown_seconds"), "probe_credential" => (filled(config($p."twilio_probe.key_sid")) && filled(config($p."twilio_probe.key_secret"))) ? "restricted_key" : "otp", "probe_base_set" => filled(config($p."twilio_probe.base")), "canary_max_active" => config($p."canary.max_active"), "canary_max_live" => config($p."canary.max_live"), "canary_country_codes" => array_map("strval", (array) config($p."canary.country_codes")), "campaign_links_max_active" => config($p."campaign_links.max_active"), "twilio_aliases" => ["sid_source" => config($p."twilio_aliases.sid_source"), "token_source" => config($p."twilio_aliases.token_source"), "sid_consistent" => config($p."twilio_aliases.sid_consistent"), "token_consistent" => config($p."twilio_aliases.token_consistent")], "elevenlabs_enabled" => config("voice.elevenlabs.enabled"), "twilio_voice_enabled" => config("voice.twilio.enabled"), "twilio_voice_from_set" => filled(config("voice.twilio.from")), "twilio_voice_daily_cap" => config("voice.twilio.daily_call_cap"), "voice" => App\Services\Voice\VoiceProviders::status()->toArray()]), PHP_EOL;' 2>&1 | tail -1) || true
  case "$l" in '{"writes_enabled":'*) printf '%s\n' "$l" ;; esac
}
# El motor, el H4 y el H5: este script no los toca y se comprueba que siguen igual.
leer_motor() {
  local l
  l=$(sudo -u www-data php artisan tinker --execute='echo json_encode(["engine_scope" => config("marketing.ultron.studio_config"), "publishing_enabled" => config("ultron_studio.publishing_enabled"), "lab_gate" => config("ultron_studio.publishing.lab_gate"), "lab_enabled" => config("ultron_studio.lab.enabled"), "lab_driver" => config("ultron_studio.lab.driver"), "canary_set" => config("marketing.ultron.canary_conversation_id") !== null, "ultron_enabled" => config("marketing.ultron.enabled")]), PHP_EOL;' 2>&1 | tail -1) || true
  case "$l" in '{"engine_scope"'*) printf '%s\n' "$l" ;; esac
}
cuenta_var() { grep -Ec "^[[:space:]]*(export[[:space:]]+)?$1[[:space:]]*=" "$ENVF" || true; }
# Escribe la clave con la sangría que tuviera la línea (la añade con dos espacios si falta). La sustitución se escribe
# sobre el MISMO fichero (cat > .env): conserva inodo, dueño y modo, sin depender del «sed -i» de GNU ni de BSD.
set_var() {
  local tmp
  if [ "$(cuenta_var "$1")" != 0 ]; then
    tmp=$(mktemp /root/.h6-scope-env.XXXXXX)                     # 600 (umask 077), con secretos: se borra enseguida
    sed -E "s/^([[:space:]]*)(export[[:space:]]+)?$1[[:space:]]*=.*/\\1$1=$2/" "$ENVF" > "$tmp"
    cat "$tmp" > "$ENVF"
    rm -f -- "$tmp"
  else
    [ -z "$(tail -c1 "$ENVF")" ] || printf '\n' >> "$ENVF"        # sin salto final, la línea nueva se pegaría a la anterior
    printf '  %s=%s\n' "$1" "$2" >> "$ENVF"
  fi
}
workers() { # 13 RUNNING y 0 BACKOFF/FATAL, esperando hasta $1 s; imprime «RUNNING MAL»
  local hasta s r m
  hasta=$(( $(date +%s) + $1 ))
  while :; do
    s=$(supervisorctl status 2>/dev/null || true)
    r=$(printf '%s\n' "$s" | grep -c RUNNING || true)
    m=$(printf '%s\n' "$s" | grep -cE 'BACKOFF|FATAL' || true)
    if { [ "$r" = 13 ] && [ "$m" = 0 ]; } || [ "$(date +%s)" -ge "$hasta" ]; then echo "$r $m"; return 0; fi
    sleep 2
  done
}
instantaneas() { ( cd / && sudo -u postgres psql -X -Atq -v ON_ERROR_STOP=1 -d ironbody -c "SELECT count(*) FROM ultron_studio_integration_snapshots WHERE kind = 'config' AND actor_type = 'system';" ) 2>/dev/null || echo '?'; }

ANTES=$(leer)
[ -n "$ANTES" ] || aborta "no se pudo leer la configuración efectiva del Hito 6 (¿está desplegada la fase 1?; la salida de tinker no se imprime)"
case "$ANTES" in *'"writes_enabled":true'*) W_ACT=on ;; *'"writes_enabled":false'*) W_ACT=off ;; *) W_ACT='?' ;; esac
case "$ANTES" in *'"probe_enabled":true'*) P_ACT=on ;; *'"probe_enabled":false'*) P_ACT=off ;; *) P_ACT='?' ;; esac
if [ "$MANDO" = estado ]; then
  echo "configuración efectiva del Hito 6 (proceso CLI, misma caché que el web): writes=$W_ACT probe=$P_ACT"
  echo "$ANTES"
  exit 0
fi
[ "$CAMPO" = writes_enabled ] && ACT=$W_ACT || ACT=$P_ACT
if [ -z "$DESDE" ]; then
  echo "falta el estado actual (on|off); hoy, según la configuración efectiva: $MANDO=$ACT. No se ha tocado nada."
  echo "$ANTES"
  exit 2
fi
[ "$ACT" = "$DESDE" ] || aborta "la configuración efectiva no es la de «$MANDO $DESDE» (hoy $MANDO=$ACT): $ANTES"
[ "$VALOR" != "$DESDE" ] || { echo "$MANDO ya está en $VALOR: nada que hacer"; exit 0; }
[ "$(cuenta_var "$CLAVE")" -le 1 ] || aborta "$CLAVE aparece repetida en el fichero de entorno (gana la última): deduplicar antes"
MOTOR_ANTES=$(leer_motor)
[ -n "$MOTOR_ANTES" ] || aborta "no se pudo leer la configuración del motor y del Studio (tinker falló; su salida no se imprime)"
DESDE_B=$(booleano "$DESDE"); NUEVO_B=$(booleano "$VALOR")
ESPERADA=${ANTES/"\"$CAMPO\":$DESDE_B"/"\"$CAMPO\":$NUEVO_B"}
[ "$ESPERADA" != "$ANTES" ] || aborta "no se pudo calcular la configuración esperada (¿cambió la forma de la lectura?)"
DUENO=$(stat -c '%U:%G' "$ENVF"); MODO=$(stat -c '%a' "$ENVF")
TS=$(date -u +%Y%m%d-%H%M%S)
COPIA="/root/env-pre-h6-$MANDO-$VALOR-$TS"
install -m 600 -o root -g root "$ENVF" "$COPIA"
VUELTA="install -m $MODO -o ${DUENO%%:*} -g ${DUENO##*:} $COPIA /var/www/api/backend/.env && cd /var/www/api/backend && sudo -u www-data sh -c 'umask 077; php artisan config:cache -q' && chmod 600 bootstrap/cache/config.php"
echo "0. $MANDO: $DESDE → $VALOR · copia previa del fichero de entorno: $COPIA (600, root) · fichero $DUENO $MODO"
case "$ANTES" in
  *'"probe_credential":"restricted_key"'*) CREDENCIAL='clave restringida (ULTRON_STUDIO_TWILIO_PROBE_KEY_SID/_SECRET)' ;;
  *) CREDENCIAL='la del OTP (no hay clave restringida completa)' ;;
esac
[ "$MANDO" != probe ] || echo "   credencial que usará la sonda: $CREDENCIAL; solo GET, hosts oficiales en production, enfriamiento de 300 s"

set_var "$CLAVE" "$NUEVO_B"
[ "$(stat -c '%U:%G %a' "$ENVF")" = "$DUENO $MODO" ] || { chown "$DUENO" "$ENVF"; chmod "$MODO" "$ENVF"; echo "   dueño y modo del fichero restaurados a $DUENO $MODO"; }
echo "1. $CLAVE=$NUEVO_B (fichero $(stat -c '%U %a' "$ENVF"))"
# B-1 del H5: el fichero tiene que parsear ANTES de tocar la caché (config:cache la vacía primero; si Laravel no
# arranca, la API cae).
sudo -u www-data php -r 'require "vendor/autoload.php"; try { Dotenv\Dotenv::createArrayBacked(".")->load(); } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' \
  || aborta "el fichero de entorno NO parsea tras escribir la llave. Vuelta: $VUELTA"
sudo -u www-data sh -c 'umask 077; php artisan config:cache -q'
CACHE=bootstrap/cache/config.php
[ "$(stat -c '%U' "$CACHE")" = www-data ] || aborta "$CACHE no es de www-data: no se reinician los workers (quedarían en BACKOFF). Vuelta: $VUELTA"
[ "$(stat -c '%a' "$CACHE")" = 600 ] || chmod 600 "$CACHE"
echo "2. caché: $(stat -c '%U %a' "$CACHE")"
DESPUES=$(leer)
if [ "$DESPUES" = "$ESPERADA" ]; then
  echo "   $DESPUES"
else
  echo "ABORTA: la configuración efectiva no es la pedida (o cambió algo más que $CAMPO)"
  echo "   antes:    $ANTES"
  echo "   esperada: $ESPERADA"
  echo "   leída:    ${DESPUES:-ilegible}"
  echo "   vuelta:   $VUELTA"
  exit 1
fi
MOTOR=$(leer_motor)
[ "$MOTOR" = "$MOTOR_ANTES" ] && echo "   motor, H4 y H5 sin cambios" || desvio "la configuración del motor, del H4 o del H5 cambió: antes $MOTOR_ANTES · después ${MOTOR:-ilegible}"
sudo -u www-data php artisan queue:restart -q
sleep 6
W1=$(workers 45); sleep 8; W2=$(workers 45)
echo "3. workers (RUNNING · BACKOFF/FATAL), dos lecturas separadas: [$W1] y [$W2]"
[ "$W1" = "13 0" ] && [ "$W2" = "13 0" ] || desvio "workers [$W1] y [$W2] (esperado 13 0 las dos veces)"
c=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$API/admin/ultron-studio/integrations/status")
echo "4. integrations/status sin token → $c"; [ "$c" = 401 ] || desvio "integrations/status sin token respondió $c (esperado 401)"
if [ "$DESVIOS" = 0 ]; then
  I0=$(instantaneas)
  if sudo -u www-data php artisan ultron-studio:integrations-snapshot > /dev/null 2>&1; then
    echo "5. instantánea de sistema: observadas $I0 → $(instantaneas) (solo inserta si la huella de un proveedor cambió)"
  else
    desvio "ultron-studio:integrations-snapshot no salió con 0 (ejecutarlo a mano como www-data para ver por qué)"
  fi
else
  echo "5. instantánea de sistema: no se toma con $DESVIOS desvíos"
fi
echo "   la vista del proceso web (flags de GET integrations/status) la comprueba el smoke: SMOKE_PHASE=2 bash /root/smoke-run-h6.sh"
[ "$MANDO $VALOR" != "probe on" ] || echo "   OJO: la huella de Twilio incluye probe.enabled. Reconocer la configuración de Twilio DESPUÉS de este cambio (si ya estaba reconocida, ahora sale «drift»)"
[ "$MANDO $VALOR" != "writes on" ] || echo "   T1 con personas reales (quien tiene el teléfono registra; otra persona autoriza y activa): la cuenta de smoke nunca sirve para el T1 real"
if [ "$DESVIOS" = 0 ]; then
  echo "MANDO $MANDO → $VALOR APLICADO (copia previa: $COPIA)"
else
  echo "MANDO $MANDO → $VALOR ESCRITO CON $DESVIOS DESVÍOS: no seguir hasta aclararlos (copia previa: $COPIA; vuelta: $VUELTA)"   # m-3 del H5: APLICADO solo en verde
  exit 1
fi

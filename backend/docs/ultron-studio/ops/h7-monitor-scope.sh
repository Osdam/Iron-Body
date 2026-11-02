#!/bin/bash
# Hito 7 · el mando del monitor (contrato OPS7 (a): «monitor apagado por defecto y encendido con autorización») y la
# vuelta atrás de nivel 2 del H7. Una sola llave, ULTRON_STUDIO_MONITOR_ENABLED:
#   bash /root/h7-monitor-scope.sh on  off     enciende el monitor (desde apagado)
#   bash /root/h7-monitor-scope.sh off on      lo apaga (desde encendido): la vuelta atrás de nivel 2
#   bash /root/h7-monitor-scope.sh estado      solo lee e imprime la configuración efectiva y el programador
# Sin el estado actual no se toca nada: se imprime la configuración efectiva y se sale con 2. Si el estado actual no
# coincide con la configuración efectiva, tampoco. Calcado de h6-integrations-scope.sh.
# Se ejecuta EN EL SERVIDOR como root, con el H7 desplegado y la autorización del propietario (encender: la que pide
# el contrato; apagar: inmediata). Orden: copia previa 600 del fichero de entorno en /root (exige el fichero en 600) →
# la llave (con la sangría que tuviera; dos espacios si falta), reescrita sobre el mismo fichero para conservar inodo,
# dueño y modo → el fichero parsea → config:cache como www-data con umask 077; la caché, de www-data, 600 y nunca un
# enlace (H-5: el chmod, si hace falta, lo hace www-data) → la configuración efectiva es la pedida y lo demás no cambió
# → schedule:list (su código de salida, aparte: m-1 del reviewer del H7): las 2 tareas del Studio (ultron-studio:monitor
# cada 30 min e integrations-snapshot a las 07:10 de Bogotá) aparecen o desaparecen y el total cambia en exactamente 2
# (26 ↔ 28 en las pruebas y 27 ↔ 29 en el banco: el total depende de las demás banderas del servidor, billing, wompi,
# coach, bienestar, seguimientos, Meta) → queue:restart (como el mando
# del H6: los workers vuelven a cargar la caché nueva AHORA, mientras se mira) → 13 RUNNING y 0 BACKOFF/FATAL dos veces
# → 401 sin token. Al encender, además (informativo, no cambia el veredicto): el monitor en seco sobre la publicación y
# las integraciones y quién ejecuta schedule:run en el crontab (H-1). «APLICADO» solo sale con 0 desvíos; con alguno,
# código 1 y la orden exacta para volver.
# Nunca imprime valores del fichero de entorno ni la salida de un tinker que no case con lo esperado. No toca el motor,
# ni la expectativa del monitor (ULTRON_STUDIO_MONITOR_EXPECT), ni las banderas del H5 o del H6, ni las claves de
# ningún proveedor. No recarga FPM: el proceso web no usa esta llave (la leen el programador y el propio comando).
set -euo pipefail
export LC_ALL=C
umask 077
uso() {
  echo "uso: bash /root/h7-monitor-scope.sh on|off <estado actual on|off>"
  echo "     bash /root/h7-monitor-scope.sh estado"
}
[ "$#" -le 2 ] || { uso; exit 2; }
VALOR="${1:-}"; DESDE="${2:-}"
case "$VALOR" in
  on|off) case "$DESDE" in on|off|"") ;; *) echo "estado actual no válido: «$DESDE» (on|off)"; uso; exit 2 ;; esac ;;
  estado) [ "$#" = 1 ] || { uso; exit 2; } ;;
  *) uso; exit 2 ;;
esac
[ "$(id -u)" = 0 ] || { echo "se ejecuta como root en el servidor"; exit 2; }
cd /var/www/api/backend
ENVF=.env
CLAVE=ULTRON_STUDIO_MONITOR_ENABLED
API=https://api.ironbodyneiva.cloud/api
aborta() { echo "ABORTA: $*"; exit 1; }
DESVIOS=0
desvio() { echo "   ✗ $*"; DESVIOS=$((DESVIOS + 1)); }
booleano() { [ "$1" = on ] && echo true || echo false; }
# La configuración efectiva del H7 (la misma lectura que el paso 7 de deploy-h7-backend.sh): la bandera y si la
# expectativa es la del código, sin valores. Si tinker falla, nada (su salida podría arrastrar una línea del fichero).
leer() {
  local l
  l=$(sudo -u www-data php artisan tinker --execute='echo json_encode(["monitor_enabled" => config("ultron_studio.monitor.enabled"), "monitor_expect_default" => config("ultron_studio.monitor.expect") === "twilio:connected_otp_only,twilio_voice:not_configured,elevenlabs:ELEVENLABS_NOT_CONFIGURED"]), PHP_EOL;' 2>&1 | tail -1) || true
  case "$l" in '{"monitor_enabled":'*) printf '%s\n' "$l" ;; esac
}
# El motor, el H4, el H5 y el H6 (la misma lectura que leer_previa de deploy-h7-backend.sh): este script no los toca.
leer_resto() {
  local l
  l=$(sudo -u www-data php artisan tinker --execute='$p = "ultron_studio.integrations."; echo json_encode(["engine_scope" => config("marketing.ultron.studio_config"), "publishing_enabled" => config("ultron_studio.publishing_enabled"), "lab_gate" => config("ultron_studio.publishing.lab_gate"), "lab_enabled" => config("ultron_studio.lab.enabled"), "lab_driver" => config("ultron_studio.lab.driver"), "canary_set" => config("marketing.ultron.canary_conversation_id") !== null, "ultron_enabled" => config("marketing.ultron.enabled"), "writes_enabled" => config($p."writes_enabled"), "probe_enabled" => config($p."twilio_probe.enabled"), "elevenlabs_enabled" => config("voice.elevenlabs.enabled"), "twilio_voice_enabled" => config("voice.twilio.enabled")]), PHP_EOL;' 2>&1 | tail -1) || true
  case "$l" in '{"engine_scope"'*) printf '%s\n' "$l" ;; esac
}
# El programador por `schedule:list --json`, con su código de salida APARTE (m-1: sin base o sin caché lanza una
# QueryException sobre cache_locks y la salida vacía no puede pasar por «0 tareas»): «total del_Studio» (entradas y
# cuántas son ultron-studio:*), «FALLO <código>» o «ILEGIBLE». Su salida de error no se imprime.
programadas() {
  local salida rc=0
  salida=$(sudo -u www-data php artisan schedule:list --json 2> /dev/null) || rc=$?
  [ "$rc" = 0 ] || { echo "FALLO $rc"; return 0; }
  printf '%s' "$salida" | python3 -c '
import json, sys
try:
    r = json.load(sys.stdin)
    assert isinstance(r, list)
except Exception:
    print("ILEGIBLE")
    sys.exit(0)
print(len(r), sum(1 for x in r if "ultron-studio:" in str(x.get("command", ""))))' 2> /dev/null || echo ILEGIBLE
}
cuenta_var() { grep -Ec "^[[:space:]]*(export[[:space:]]+)?$1[[:space:]]*=" "$ENVF" || true; }
# Escribe la clave con la sangría que tuviera la línea (la añade con dos espacios si falta). La sustitución se escribe
# sobre el MISMO fichero (cat > .env): conserva inodo, dueño y modo, sin depender del «sed -i» de GNU ni de BSD.
set_var() {
  local tmp
  if [ "$(cuenta_var "$1")" != 0 ]; then
    tmp=$(mktemp /root/.h7-monitor-env.XXXXXX)                   # 600 (umask 077), con secretos: se borra enseguida
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

ANTES=$(leer)
[ -n "$ANTES" ] || aborta "no se pudo leer la configuración efectiva del monitor (¿está desplegado el H7?; la salida de tinker no se imprime)"
case "$ANTES" in *'"monitor_enabled":true'*) ACT=on ;; *'"monitor_enabled":false'*) ACT=off ;; *) ACT='?' ;; esac
PROG0=$(programadas)
if [ "$VALOR" = estado ]; then
  echo "configuración efectiva del monitor (proceso CLI, la misma caché que el programador): monitor=$ACT"
  echo "$ANTES"
  case "$PROG0" in
    "FALLO "*) echo "schedule:list NO sale con 0 (código ${PROG0#FALLO }; su salida no se imprime): el programador no se puede leer (¿base o caché?)"; exit 1 ;;
    ILEGIBLE) echo "schedule:list no devolvió la lista JSON de tareas"; exit 1 ;;
  esac
  echo "schedule:list (total · del Studio): $PROG0 (encendido: 2 del Studio; apagado: 0)"
  exit 0
fi
if [ -z "$DESDE" ]; then
  echo "falta el estado actual (on|off); hoy, según la configuración efectiva: monitor=$ACT. No se ha tocado nada."
  echo "$ANTES"
  exit 2
fi
[ "$ACT" = "$DESDE" ] || aborta "la configuración efectiva no es la de «monitor $DESDE» (hoy monitor=$ACT): $ANTES"
[ "$VALOR" != "$DESDE" ] || { echo "el monitor ya está en $VALOR: nada que hacer"; exit 0; }
[ "$(cuenta_var "$CLAVE")" -le 1 ] || aborta "$CLAVE aparece repetida en el fichero de entorno (gana la última): deduplicar antes"
case "$PROG0" in
  "FALLO "*) aborta "schedule:list no sale con 0 (código ${PROG0#FALLO }; su salida no se imprime): sin poder leer el programador no se toca nada (¿base o caché? m-1)" ;;
  ILEGIBLE) aborta "schedule:list no devolvió la lista JSON de tareas: no se toca nada" ;;
esac
case "$PROG0" in
  *" 0")  [ "$DESDE" = off ] || aborta "el programador no tiene tareas del Studio con el monitor encendido ($PROG0): revisar antes de tocar nada" ;;
  *" 2")  [ "$DESDE" = on ] || aborta "el programador tiene 2 tareas del Studio con el monitor apagado ($PROG0): revisar antes de tocar nada" ;;
  *) aborta "el programador no cuadra antes de empezar (total · del Studio: $PROG0)" ;;
esac
RESTO_ANTES=$(leer_resto)
[ -n "$RESTO_ANTES" ] || aborta "no se pudo leer la configuración del motor, del H5 y del H6 (tinker falló; su salida no se imprime)"
DESDE_B=$(booleano "$DESDE"); NUEVO_B=$(booleano "$VALOR")
ESPERADA=${ANTES/"\"monitor_enabled\":$DESDE_B"/"\"monitor_enabled\":$NUEVO_B"}
[ "$ESPERADA" != "$ANTES" ] || aborta "no se pudo calcular la configuración esperada (¿cambió la forma de la lectura?)"
TOTAL0=${PROG0%% *}
[ "$VALOR" = on ] && PROG_ESPERADA="$((TOTAL0 + 2)) 2" || PROG_ESPERADA="$((TOTAL0 - 2)) 0"
DUENO=$(stat -c '%U:%G' "$ENVF"); MODO=$(stat -c '%a' "$ENVF")
[ "$MODO" = 600 ] || aborta "el fichero de entorno tiene modo $MODO y se exige 600 (N-4 de security): corregirlo a mano antes de tocar nada"
TS=$(date -u +%Y%m%d-%H%M%S)
COPIA="/root/env-pre-h7-monitor-$VALOR-$TS"
install -m 600 -o root -g root "$ENVF" "$COPIA"
VUELTA="install -m $MODO -o ${DUENO%%:*} -g ${DUENO##*:} $COPIA /var/www/api/backend/.env && cd /var/www/api/backend && sudo -u www-data sh -c 'umask 077; php artisan config:cache -q' && sudo -u www-data chmod 600 bootstrap/cache/config.php && sudo -u www-data php artisan queue:restart -q"
echo "0. monitor: $DESDE → $VALOR · copia previa del fichero de entorno: $COPIA (600, root) · fichero $DUENO $MODO · programador (total · del Studio): $PROG0"

set_var "$CLAVE" "$NUEVO_B"
[ "$(stat -c '%U:%G %a' "$ENVF")" = "$DUENO $MODO" ] || { chown "$DUENO" "$ENVF"; chmod "$MODO" "$ENVF"; echo "   dueño y modo del fichero restaurados a $DUENO $MODO"; }
echo "1. $CLAVE=$NUEVO_B (fichero $(stat -c '%U %a' "$ENVF"))"
# B-1 del H5: el fichero tiene que parsear ANTES de tocar la caché (config:cache la vacía primero; si Laravel no
# arranca, la API cae).
sudo -u www-data php -r 'require "vendor/autoload.php"; try { Dotenv\Dotenv::createArrayBacked(".")->load(); } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' \
  || aborta "el fichero de entorno NO parsea tras escribir la llave. Vuelta: $VUELTA"
sudo -u www-data sh -c 'umask 077; php artisan config:cache -q' || aborta "config:cache falló (su salida no se imprime). Vuelta: $VUELTA"
CACHE=bootstrap/cache/config.php
# bootstrap/cache lo escribe www-data: root no hace chmod ahí (seguiría un enlace puesto en su lugar; H-5 de SEC7).
[ -f "$CACHE" ] && [ ! -L "$CACHE" ] || aborta "$CACHE no es un fichero normal (¿un enlace?): no se reinician los workers. Vuelta: $VUELTA"
[ "$(stat -c '%U' "$CACHE")" = www-data ] || aborta "$CACHE no es de www-data: no se reinician los workers (quedarían en BACKOFF). Vuelta: $VUELTA"
[ "$(stat -c '%a' "$CACHE")" = 600 ] || { sudo -u www-data chmod 600 "$CACHE" || aborta "www-data no pudo dejar $CACHE en 600. Vuelta: $VUELTA"; echo "   $CACHE pasado a 600 (como www-data)"; }
echo "2. caché: $(stat -c '%U %a' "$CACHE")"
DESPUES=$(leer)
if [ "$DESPUES" = "$ESPERADA" ]; then
  echo "   $DESPUES"
else
  echo "ABORTA: la configuración efectiva no es la pedida (o cambió algo más que la bandera del monitor)"
  echo "   antes:    $ANTES"
  echo "   esperada: $ESPERADA"
  echo "   leída:    ${DESPUES:-ilegible}"
  echo "   vuelta:   $VUELTA"
  exit 1
fi
RESTO=$(leer_resto)
[ "$RESTO" = "$RESTO_ANTES" ] && echo "   motor, H4, H5 y H6 sin cambios" || desvio "la configuración del motor, del H4, del H5 o del H6 cambió: antes $RESTO_ANTES · después ${RESTO:-ilegible}"
PROG=$(programadas)
case "$PROG" in
  "FALLO "*) desvio "schedule:list no sale con 0 (código ${PROG#FALLO }; su salida no se imprime): no se puede comprobar el programador (m-1)" ;;
  ILEGIBLE) desvio "schedule:list no devolvió la lista JSON de tareas: no se puede comprobar el programador" ;;
  *) echo "3. programador (total · del Studio): $PROG0 → $PROG (esperado $PROG_ESPERADA: las 2 tareas del Studio $([ "$VALOR" = on ] && echo aparecen || echo desaparecen) y nada más cambia)"
     [ "$PROG" = "$PROG_ESPERADA" ] || desvio "schedule:list da [$PROG] y se esperaba [$PROG_ESPERADA]" ;;
esac
sudo -u www-data php artisan queue:restart -q || desvio "queue:restart no salió con 0"
sleep 6
W1=$(workers 45); sleep 8; W2=$(workers 45)
echo "4. workers (RUNNING · BACKOFF/FATAL), dos lecturas separadas: [$W1] y [$W2]"
[ "$W1" = "13 0" ] && [ "$W2" = "13 0" ] || desvio "workers [$W1] y [$W2] (esperado 13 0 las dos veces)"
c=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$API/admin/ultron-studio/status" || true)
echo "5. status sin token → $c"; [ "$c" = 401 ] || desvio "status sin token respondió $c (esperado 401)"
if [ "$VALOR" = on ]; then
  # Informativo: lo que verá la primera pasada programada (en seco no abre incidentes). Las colas, en la primera pasada
  # real: justo tras queue:restart un carril puede tener cola sin latido unos segundos (como el paso 9 del despliegue).
  if sudo -u www-data php artisan ultron-studio:monitor --check=publication --check=integrations --dry-run > /dev/null 2>&1; then
    echo "   INFO: ultron-studio:monitor --dry-run (publicación e integraciones): 0, la primera pasada no debería abrir incidente"
  else
    echo "   INFO: ultron-studio:monitor --dry-run (publicación e integraciones) NO sale con 0: la primera pasada programada abrirá o sumará un incidente; verlo a mano: sudo -u www-data php artisan ultron-studio:monitor --dry-run"
  fi
  # H-1 (informe-devops-h7.md §8): con schedule:run en el crontab de root, el monitor corre como root cada 30 min.
  # Sin «grep -q» en tubería: con pipefail, el SIGPIPE del que escribe daría «no» aunque casara.
  en_crontab() { local c; c=$(crontab -l -u "$1" 2> /dev/null || true); printf '%s\n' "$c" | grep -v '^[[:space:]]*#' | grep -c 'schedule:run' || true; }
  if [ "$(en_crontab root)" != 0 ]; then
    echo "   AVISO (H-1): schedule:run está en el crontab de root: el monitor y la instantánea correrán como root (pasarlo al de www-data es el paso 6b de ironbody-migrate-propuesta.sh)"
  elif [ "$(en_crontab www-data)" != 0 ]; then
    echo "   INFO (H-1): schedule:run está en el crontab de www-data"
  else
    echo "   INFO (H-1): schedule:run no aparece en el crontab de root ni en el de www-data (¿systemd o /etc/cron.d?): comprobar quién lo ejecuta"
  fi
  echo "   siguiente, a mano: la primera pasada real: sudo -u www-data php artisan ultron-studio:monitor → 0 y ningún incidente ultron_studio nuevo"
fi
if [ "$DESVIOS" = 0 ]; then
  echo "MANDO monitor → $VALOR APLICADO (copia previa: $COPIA)"
else
  echo "MANDO monitor → $VALOR ESCRITO CON $DESVIOS DESVÍOS: no seguir hasta aclararlos (copia previa: $COPIA; vuelta: $VUELTA)"   # m-3 del H5: APLICADO solo en verde
  exit 1
fi

#!/bin/bash
# Hito 7 · el mando del monitor (contrato OPS7 (a): «monitor apagado por defecto y encendido con autorización») y la
# vuelta atrás de nivel 2 del H7. Una sola llave, ULTRON_STUDIO_MONITOR_ENABLED:
#   bash /root/h7-monitor-scope.sh on  off     enciende el monitor (desde apagado)
#   bash /root/h7-monitor-scope.sh off on      lo apaga (desde encendido): la vuelta atrás de nivel 2
#   bash /root/h7-monitor-scope.sh estado      solo lee e imprime la configuración efectiva y el programador
# Sin el estado actual no se toca nada: se imprime la configuración efectiva y se sale con 2. Si el estado actual no
# coincide con la configuración efectiva, tampoco. Calcado de h6-integrations-scope.sh.
# OPS8-e (10-11-2026, auditoría de los 9 hitos): B-T13, «on» se NIEGA (código 2, sin tocar nada) si el monitor en seco
# de las integraciones no sale con 0 con la expectativa de hoy (la del código, ELEVENLABS_NOT_CONFIGURED, ya no es la de
# producción desde E1-12): la expectativa de cada etapa se fija con `h8-voice-scope.sh expectativa fase1|fase2|llamadas`;
# «estado» la enseña, dice si el estado de hoy la confirma y quién ejecuta DE VERDAD schedule:run (B-4). B-T14 (C-14): el
# fichero de entorno puede ser de www-data en 600 o de root:www-data en 640, también al apagar. Los workers esperados son
# 13, o 14 con ironbody-voice instalado (fase 4a del H8). Apagar nunca depende de la expectativa.
# Se ejecuta EN EL SERVIDOR como root, con el H7 desplegado y la autorización del propietario (encender: la que pide
# el contrato; apagar: inmediata). Orden: copia previa 600 del fichero de entorno en /root (exige el fichero de www-data en
# 600 o de root:www-data en 640) →
# la llave (con la sangría que tuviera; dos espacios si falta), reescrita sobre el mismo fichero para conservar inodo,
# dueño y modo → el fichero parsea → config:cache como www-data con umask 077; la caché, de www-data, 600 y nunca un
# enlace (H-5: el chmod, si hace falta, lo hace www-data) → la configuración efectiva es la pedida y lo demás no cambió
# → schedule:list (su código de salida, aparte: m-1 del reviewer del H7): las 2 tareas del monitor (ultron-studio:monitor
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
# QueryException sobre cache_locks y la salida vacía no puede pasar por «0 tareas»): «total del_monitor» (entradas y
# cuántas son las DOS de este monitor), «FALLO <código>» o «ILEGIBLE». Su salida de error no se imprime.
# 11-11-2026: solo cuentan SUS dos tareas, `ultron-studio:monitor` y `ultron-studio:integrations-snapshot`, las dos SIN
# argumentos (routes/console.php, bajo ULTRON_STUDIO_MONITOR_ENABLED). Antes contaba cualquier `ultron-studio:*` y veía 1
# con el monitor apagado: la sincronización de plantillas de Meta (ultron-studio:meta-templates-sync, ola M-1, su propia
# bandera; como smoke-run-h8.sh:100) y, con el monitor de voz encendido, también vería la verificación semanal de
# ElevenLabs (`ultron-studio:integrations-snapshot --verify-voice`, de VOICE_MONITOR_ENABLED).
programadas() {
  local salida rc=0
  salida=$(sudo -u www-data php artisan schedule:list --json 2> /dev/null) || rc=$?
  [ "$rc" = 0 ] || { echo "FALLO $rc"; return 0; }
  printf '%s' "$salida" | python3 -c '
import json, re, sys
try:
    r = json.load(sys.stdin)
    assert isinstance(r, list)
except Exception:
    print("ILEGIBLE")
    sys.exit(0)
suyas = [re.compile(r"(?:^|[\s\x27\x22])ultron-studio:" + n + r"[\s\x27\x22]*$") for n in ("monitor", "integrations-snapshot")]
print(len(r), sum(1 for x in r if any(p.search(str(x.get("command", ""))) for p in suyas)))' 2> /dev/null || echo ILEGIBLE
}
# Solo para «estado» (informativo): las otras `ultron-studio:*` del programador, que no son de este monitor y no cuentan.
otras_del_studio() {
  sudo -u www-data php artisan schedule:list --json 2> /dev/null | python3 -c '
import json, re, sys
suyas = [re.compile(r"(?:^|[\s\x27\x22])ultron-studio:" + n + r"[\s\x27\x22]*$") for n in ("monitor", "integrations-snapshot")]
c = [str(x.get("command", "")) for x in json.load(sys.stdin)]
o = sorted(re.sub(r"^.*?(ultron-studio:\S+(?:\s+--\S+)*).*$", r"\1", x) for x in c if "ultron-studio:" in x and not any(p.search(x) for p in suyas))
print("%d (%s)" % (len(o), ", ".join(o)) if o else "0")' 2> /dev/null || echo '?'
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
workers() { # $2 RUNNING y 0 BACKOFF/FATAL, esperando hasta $1 s; imprime «RUNNING MAL»
  local hasta s r m
  hasta=$(( $(date +%s) + $1 ))
  while :; do
    s=$(supervisorctl status 2>/dev/null || true)
    r=$(printf '%s\n' "$s" | grep -c RUNNING || true)
    m=$(printf '%s\n' "$s" | grep -cE 'BACKOFF|FATAL' || true)
    if { [ "$r" = "$2" ] && [ "$m" = 0 ]; } || [ "$(date +%s)" -ge "$hasta" ]; then echo "$r $m"; return 0; fi
    sleep 2
  done
}
# OPS8-e: los programas de supervisor esperados son 13, o 14 si el worker de la voz (ironbody-voice, fase 4a del H8) está
# instalado (aparece en supervisorctl, en el estado que sea: si no está en RUNNING, la comprobación de después lo dice).
programas_esperados() { local n; n=$(supervisorctl status 2>/dev/null | grep -c '^ironbody-voice:' || true); [ "${n:-0}" -ge 1 ] && echo 14 || echo 13; }
# C-14 (B-T14 de la auditoría de los 9 hitos, OPS8-e): el fichero de entorno, un fichero normal (nunca un enlace) de
# www-data en 600 o de root:www-data en 640 (la propuesta de ironbody-migrate, paso 3b; B-3), como h8-voice-scope.sh.
# Nada más se admite: ni otro dueño, ni otro grupo, ni otro modo. Vale para encender y para apagar (nivel 2).
ENV_600=www-data
ENV_640=root:www-data
fichero_ok() {
  [ -f "$ENVF" ] && [ ! -L "$ENVF" ] || return 1
  [ "$(stat -c '%U %a' "$ENVF")" = "$ENV_600 600" ] && return 0
  [ "$(stat -c '%U:%G %a' "$ENVF")" = "$ENV_640 640" ] && return 0
  return 1
}
niega() { echo "NO SE APLICA (no se ha tocado nada): $*"; exit 2; }
# B-T13 (OPS8-e): la expectativa del monitor (ULTRON_STUDIO_MONITOR_EXPECT; la del código si no hay ninguna). No es un
# secreto: proveedor:estado separados por comas. Lo que no tenga esa forma sale como «?».
leer_expectativa() {
  local l
  l=$(sudo -u www-data php artisan tinker --execute='echo (string) config("ultron_studio.monitor.expect"), PHP_EOL;' 2>&1 | tail -1) || true
  [[ "$l" =~ ^[a-z_]{1,40}:[A-Za-z_]{1,40}(,[a-z_]{1,40}:[A-Za-z_]{1,40}){0,9}$ ]] && printf '%s\n' "$l" || echo '?'
}
# ¿Confirma el estado de hoy esa expectativa (y no hay deriva de la configuración autorizada)? El monitor en seco, solo
# integraciones: 0 = la primera pasada no abriría incidente por ellas. No escribe nada.
expectativa_confirmada() { sudo -u www-data php artisan ultron-studio:monitor --check=integrations --dry-run > /dev/null 2>&1; }
# H-1 / B-4: ¿quién ejecuta DE VERDAD schedule:run? El usuario EFECTIVO de cada línea activa: en el crontab de root, una
# línea con «sudo -u www-data» (o runuser -u) corre como www-data —es lo que hay en producción, comprobado por el lead
# el 11-11-2026: «cd /var/www/api/backend && sudo -u www-data /usr/bin/php artisan schedule:run», y www-data sin crontab—;
# en /etc/crontab y /etc/cron.d el usuario es el sexto campo. Salida: «root=N www-data=M …» o «ninguna». Solo es un
# problema lo que corra como root (el monitor y la instantánea escribirían como root).
quien_programa() {
  { for u in root www-data; do printf '#@crontab %s\n' "$u"; crontab -l -u "$u" 2> /dev/null || true; done
    for f in /etc/crontab /etc/cron.d/*; do if [ -f "$f" ]; then printf '#@sistema %s\n' "$f"; cat "$f" 2> /dev/null || true; fi; done; } | python3 -I -c '
import re, sys
de, sistema, ef = None, False, {}
for l in sys.stdin.read().splitlines():
    if l.startswith("#@crontab "):
        de, sistema = l.split(" ", 1)[1], False
        continue
    if l.startswith("#@sistema "):
        de, sistema = None, True
        continue
    s = l.strip()
    if not s or s.startswith("#") or "schedule:run" not in s or re.match(r"^[A-Za-z_][A-Za-z0-9_]*[ \t]*=", s):
        continue
    c = s.split()
    n = 1 if s.startswith("@") else 5
    usuario = (c[n] if len(c) > n else "?") if sistema else de
    orden = " ".join(c[n + 1:] if sistema else c[n:])
    m = re.search(r"\b(?:sudo|runuser)\b(?:\s+-[A-Za-tv-z]\S*)*\s+-u\s*([a-z_][a-z0-9_-]*)", orden)
    ef[m.group(1) if m else usuario] = ef.get(m.group(1) if m else usuario, 0) + 1
print(" ".join("%s=%d" % kv for kv in sorted(ef.items())) or "ninguna")'
}
# Las que corren como root (0 si ninguna).
como_root() { local q; q=$(quien_programa); case " $q " in *" root="*) q=${q#*root=}; echo "${q%% *}" ;; *) echo 0 ;; esac; }

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
  echo "schedule:list (total · del monitor): $PROG0 (encendido: sus 2, ultron-studio:monitor e integrations-snapshot; apagado: 0) · otras ultron-studio:* que no cuentan: $(otras_del_studio)"
  # B-T13 (OPS8-e): la expectativa y si el estado de hoy la confirma; quién ejecuta el programador (B-4); el fichero (C-14).
  EXP=$(leer_expectativa)
  if expectativa_confirmada; then CONF=sí; else CONF="NO (encendido, la primera pasada abriría un incidente: fijar la de la etapa con bash /root/h8-voice-scope.sh expectativa fase1|fase2|llamadas)"; fi
  # Sin «case» dentro de $(…) en una cadena: el bash 3.2 cierra la sustitución en el «)» del patrón (visto en el banco).
  DEL_CODIGO=""
  case "$ANTES" in *'"monitor_expect_default":true'*) DEL_CODIGO=' (la del código, sin ULTRON_STUDIO_MONITOR_EXPECT)' ;; esac
  echo "expectativa del monitor: $EXP$DEL_CODIGO · la confirma el estado de hoy (monitor en seco, integraciones): $CONF"
  QP=$(quien_programa)
  if [ "$(como_root)" != 0 ]; then QV="NO: alguna línea corre como root (el monitor y la instantánea escribirían como root)"; elif [ "$QP" = ninguna ]; then QV="no se ve ninguna línea (¿systemd, otro usuario o sin permiso para leer los crontab?)"; else QV="sí, nada corre como root"; fi
  echo "schedule:run (B-4), usuario efectivo de cada línea activa (crontab de root y de www-data, /etc/crontab y /etc/cron.d; root con «sudo -u www-data» cuenta como www-data): $QP · correcto: $QV"
  if fichero_ok; then echo "fichero de entorno: $(stat -c '%U:%G %a' "$ENVF") (C-14: www-data 600 o root:www-data 640)"; else echo "fichero de entorno: $(stat -c '%U:%G %a' "$ENVF" 2>/dev/null || echo '?') — NO ADMITIDO (C-14): el mando no tocará nada"; fi
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
  *" 0")  [ "$DESDE" = off ] || aborta "el programador no tiene las tareas del monitor con el monitor encendido ($PROG0): revisar antes de tocar nada" ;;
  *" 2")  [ "$DESDE" = on ] || aborta "el programador tiene las 2 tareas del monitor con el monitor apagado ($PROG0): revisar antes de tocar nada" ;;
  *) aborta "el programador no cuadra antes de empezar (total · del monitor: $PROG0)" ;;
esac
RESTO_ANTES=$(leer_resto)
[ -n "$RESTO_ANTES" ] || aborta "no se pudo leer la configuración del motor, del H5 y del H6 (tinker falló; su salida no se imprime)"
DESDE_B=$(booleano "$DESDE"); NUEVO_B=$(booleano "$VALOR")
ESPERADA=${ANTES/"\"monitor_enabled\":$DESDE_B"/"\"monitor_enabled\":$NUEVO_B"}
[ "$ESPERADA" != "$ANTES" ] || aborta "no se pudo calcular la configuración esperada (¿cambió la forma de la lectura?)"
TOTAL0=${PROG0%% *}
[ "$VALOR" = on ] && PROG_ESPERADA="$((TOTAL0 + 2)) 2" || PROG_ESPERADA="$((TOTAL0 - 2)) 0"
DUENO=$(stat -c '%U:%G' "$ENVF"); MODO=$(stat -c '%a' "$ENVF")
fichero_ok || aborta "el fichero de entorno no es un fichero normal de $ENV_600 en 600 ni de $ENV_640 en 640 (es $DUENO $MODO; C-14, N-4 de security): corregirlo a mano antes de tocar nada"
if [ "$VALOR" = on ]; then
  # B-T13 (OPS8-e): encendido con una expectativa que el estado de hoy no confirma (la del código, ELEVENLABS_NOT_CONFIGURED,
  # dejó de serlo con E1-12), la primera pasada abre un incidente integrations_drift. La vía para fijarla es el mando de la
  # voz, que solo la escribe si integrations-doctor la confirma. Apagar nunca se condiciona a esto.
  EXP=$(leer_expectativa)
  expectativa_confirmada || niega "el monitor en seco (integraciones) no sale con 0 con la expectativa de hoy ($EXP): encendido, su primera pasada abriría un incidente. Fijar la de la etapa con bash /root/h8-voice-scope.sh expectativa fase1|fase2|llamadas (el plan de despliegue, «Hito 8», dice cuál toca; la del código no vale desde E1-12) y, si hay deriva, reconocer la configuración autorizada; después, repetir"
  echo "   expectativa del monitor: $EXP (el estado de hoy la confirma: monitor en seco, integraciones, 0)"
fi
W_ESPERADOS=$(programas_esperados)
TS=$(date -u +%Y%m%d-%H%M%S)
COPIA="/root/env-pre-h7-monitor-$VALOR-$TS"
install -m 600 -o root -g root "$ENVF" "$COPIA"
VUELTA="install -m $MODO -o ${DUENO%%:*} -g ${DUENO##*:} $COPIA /var/www/api/backend/.env && cd /var/www/api/backend && sudo -u www-data sh -c 'umask 077; php artisan config:cache -q' && sudo -u www-data chmod 600 bootstrap/cache/config.php && sudo -u www-data php artisan queue:restart -q"
echo "0. monitor: $DESDE → $VALOR · copia previa del fichero de entorno: $COPIA (600, root) · fichero $DUENO $MODO · programador (total · del monitor): $PROG0"

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
  *) echo "3. programador (total · del monitor): $PROG0 → $PROG (esperado $PROG_ESPERADA: las 2 tareas del monitor $([ "$VALOR" = on ] && echo aparecen || echo desaparecen) y nada más cambia)"
     [ "$PROG" = "$PROG_ESPERADA" ] || desvio "schedule:list da [$PROG] y se esperaba [$PROG_ESPERADA]" ;;
esac
sudo -u www-data php artisan queue:restart -q || desvio "queue:restart no salió con 0"
sleep 6
W1=$(workers 45 "$W_ESPERADOS"); sleep 8; W2=$(workers 45 "$W_ESPERADOS")
echo "4. workers (RUNNING · BACKOFF/FATAL), dos lecturas separadas: [$W1] y [$W2] (esperado $W_ESPERADOS 0: 13$([ "$W_ESPERADOS" = 14 ] && echo ' y ironbody-voice'))"
[ "$W1" = "$W_ESPERADOS 0" ] && [ "$W2" = "$W_ESPERADOS 0" ] || desvio "workers [$W1] y [$W2] (esperado $W_ESPERADOS 0 las dos veces)"
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
  # H-1 (informe-devops-h7.md §8) / B-4: lo que importa es el usuario EFECTIVO de schedule:run (quien_programa, arriba).
  if [ "$(como_root)" != 0 ]; then
    echo "   AVISO (H-1): una línea activa de schedule:run corre como root ($(quien_programa)): el monitor y la instantánea correrán como root (que corra como www-data: «sudo -u www-data» en la línea de root, o el crontab de www-data)"
  elif [ "$(quien_programa)" != ninguna ]; then
    echo "   INFO (H-1 / B-4): schedule:run corre como $(quien_programa) (correcto)"
  else
    echo "   INFO (H-1): no se ve ninguna línea activa de schedule:run (¿systemd o sin permiso para leer los crontab?): comprobar quién lo ejecuta"
  fi
  echo "   siguiente, a mano: la primera pasada real: sudo -u www-data php artisan ultron-studio:monitor → 0 y ningún incidente ultron_studio nuevo"
fi
if [ "$DESVIOS" = 0 ]; then
  echo "MANDO monitor → $VALOR APLICADO (copia previa: $COPIA)"
else
  echo "MANDO monitor → $VALOR ESCRITO CON $DESVIOS DESVÍOS: no seguir hasta aclararlos (copia previa: $COPIA; vuelta: $VUELTA)"   # m-3 del H5: APLICADO solo en verde
  exit 1
fi

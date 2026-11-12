#!/bin/bash
# Hito 8 · el MANDO de la voz para las fases 2 → 4b (contrato H8, fila OPS8: «h8-voice-scope.sh para las fases 2–4b, con
# copia previa; admite 600 y 640, por C-14»; D8-20 nivel 0; D8-23 y E11-1 (VOICE_LAB_*); E4-1 (publicar); E2-6 (monitor de
# voz)). Sin él no se enciende ninguna fase: las ediciones a mano del fichero de entorno de producción no son el
# procedimiento (ULTRON_STUDIO_DEPLOYMENT_PLAN.md, «Hito 8», fases 2 → 4b). Calcado de h7-monitor-scope.sh,
# h6-integrations-scope.sh y el-verificar.sh.
#
#   bash /root/h8-voice-scope.sh estado
#   bash /root/h8-voice-scope.sh lab on --driver=elevenlabs|fake --presupuesto-dia=USD --presupuesto-sesion=USD [--sesiones-dia=N] [--segundos=N]
#   bash /root/h8-voice-scope.sh lab off
#   bash /root/h8-voice-scope.sh fase 2 set CLAVE=VALOR [CLAVE=VALOR …]      las piezas NO secretas (y VOICE_NUMBER_INBOUND_CAPABLE)
#   bash /root/h8-voice-scope.sh fase 2 secreto <fichero>                    las de Twilio que SÍ son secretas, desde un
#                                                                             fichero 600 de root (nunca por argumentos)
#   bash /root/h8-voice-scope.sh salientes on --cupo=N --presupuesto=USD    fase 4a: alcance canary, nada más
#   bash /root/h8-voice-scope.sh salientes off                               nivel 0 de D8-20 para las salientes
#   bash /root/h8-voice-scope.sh entrantes on --cupo=N                       fase 3
#   bash /root/h8-voice-scope.sh entrantes off                               nivel 0 de D8-20 para las entrantes
#   bash /root/h8-voice-scope.sh monitor on [--acepta-freno] | off           VOICE_MONITOR_ENABLED (las tareas voice:*: 4, o 5)
#   bash /root/h8-voice-scope.sh publicar on|off                             ULTRON_STUDIO_VOICE_PUBLISHING_ENABLED (E4-1)
#   bash /root/h8-voice-scope.sh expectativa fase1|fase2|llamadas            ULTRON_STUDIO_MONITOR_EXPECT del monitor del H7
#   bash /root/h8-voice-scope.sh contactos on|off                            VOICE_CONTACTS_ENABLED (B-T4: alta de contactos y su consentimiento; fase 4b)
#   bash /root/h8-voice-scope.sh hechos --sede='…' [--horario='…'] [--en-seco]   VOICE_FACTS_SITE / VOICE_FACTS_HOURS (consultar_horarios)
#   bash /root/h8-voice-scope.sh hechos borrar [--en-seco]                   los quita del fichero (la herramienta dice que no los tiene)
#
# Se ejecuta EN EL SERVIDOR como root, con la fase 1 desplegada y la AUTORIZACIÓN ESCRITA de la fase (protección 13);
# apagar («off») es la vuelta atrás de nivel 0 y no espera a nadie. Cada orden que escribe: el fichero de entorno tiene que
# ser un fichero normal (nunca un enlace) de www-data en 600 o de root:www-data en 640 (C-14) → las claves pedidas con
# su forma validada y, si la orden lo exige, sus condiciones (abajo) → copia previa 600 de root en
# /root/env-pre-voice-<orden>-<fecha> (nunca se sobrescribe una copia) → SOLO las claves pedidas, con la sangría que
# tuvieran (dos espacios si faltan), en una escritura atómica que conserva dueño y modo → el fichero parsea → config:cache
# como www-data con umask 077 (caché de www-data, 600, nunca un enlace) → la configuración efectiva cambió SOLO en lo
# pedido y el motor, el H4–H7 y el estado de ElevenLabs siguen igual → el fichero es la copia salvo esas claves → el
# programador cambia solo si la orden es «monitor» (±4 tareas de voz; ±5 con voice:webhook-health) → queue:restart → los workers como antes, dos
# lecturas → 401 sin token → 0 ERROR nuevos. Imprime el antes y el después SIN VALORES DE SECRETOS (presencia, nombres,
# referencias no secretas y los cuatro últimos dígitos del número y de la cuenta) y la orden exacta de vuelta atrás.
# «APLICADO» solo sale con 0 desvíos; con alguno, código 1 y la vuelta atrás. Códigos: 0 hecho o nada que hacer, 1 aborto
# o desvío, 2 uso o condición no cumplida (no se ha tocado nada).
#
# Se NIEGA (código 2, sin tocar nada):
#   · salientes on / entrantes on, si la constante VoiceCallPolicy::CALLS_ENABLED_IN_CODE no es exactamente `true` en el
#     código DESPLEGADO (en ejecución y en el fichero, una sola declaración anclada, y el último commit que tocó
#     VoiceCallPolicy.php empieza por `voice(calls-open):`): las fases 3 y 4a exigen ese commit (protección 2);
#   · salientes on, además: sin el worker ironbody-voice en RUNNING, sin número, sin número importado, sin la subcuenta
#     y su key (sin ella no hay corte de emergencia, nivel 0b), con el número de voz igual a uno de WhatsApp, o con un
#     presupuesto que no cubre la reserva de la PUERTA para UNA saliente en el peor caso (C-1, 11-11-2026: OutboundGate
#     reserva SIEMPRE con un tramo de transferencia a fijo, 0,9605 US$ a un móvil colombiano y 1,1220 en cualquier otro
#     caso con los precios de hoy; las cifras se leen del código desplegado con las funciones de la puerta, y si la
#     fórmula de la puerta ya no es la que conoce este mando, también se niega);
#   · entrantes on, con el número declarado «Outbound Only» (VOICE_NUMBER_INBOUND_CAPABLE=false: un caller ID verificado
#     no recibe llamadas por Twilio);
#   · lab on --driver=elevenlabs, si falta alguna de las tres referencias (ELEVENLABS_TOOL_SECRET_REF,
#     ELEVENLABS_INITIATION_SECRET_REF, ELEVENLABS_POST_CALL_WEBHOOK_REF) o VOICE_WEBHOOK_BASE_URL, o si el Studio no
#     afirma ELEVENLABS_VERIFIED (el laboratorio respondería credentials_unverified);
#   · fase 2 set / secreto, con las salientes o las entrantes encendidas (las piezas se cambian con las llamadas
#     apagadas), con una clave fuera de su lista o un valor fuera de su forma; un número de voz que sea de WhatsApp;
#     VOICE_NUMBER_INBOUND_CAPABLE si el código desplegado no la lee (la declaración de B8-g/S8-d);
#   · monitor on, si `voice:reconcile --dry-run --numbers` (lecturas a los proveedores, no escribe nada) dice que la
#     primera pasada echaría el freno, salvo --acepta-freno (p. ej. la llamada de prueba del propietario en la ventana de
#     48 h de la conciliación: cada llamada de la cuenta sin su fila es `unknown_provider_call` y echa el freno);
#   · expectativa <fase>, si integrations-doctor no confirma esa expectativa con el estado de ahora;
#   · contactos on|off, si el código desplegado no lee VOICE_CONTACTS_ENABLED (B-T4, OPS8-e: sin ella, escribirla no
#     tendría efecto). «contactos on» es de la fase 4b (visto bueno legal D-12 y la supresión 1581 de la voz, B-T3, antes).
#
# «hechos» (los dos hechos de `consultar_horarios`, config/voice.php `voice.facts`, vacíos en producción el 11-11-2026) va en
# su PROPIO bloque, con las garantías de los mandos de la ola M-1 (plantillas/meta-templates-scope.sh): registro en
# $COPIAS (600) de todo lo que imprime; los tres cerrojos de los mandos que escriben el fichero de entorno; copia previa
# 600; escritura atómica que conserva dueño, modo y bytes ajenos (también CRLF); el fichero, leído por Dotenv, da el valor
# pedido BYTE A BYTE antes de tocar la caché; config:cache como www-data en 600; la configuración efectiva
# (`config('voice.facts.*')` leída por artisan) igual BYTE A BYTE al argumento (sha256 y longitud), el resto de la voz,
# el motor y el H4–H7 y el programador sin cambios; queue:restart; workers dos veces; 401 sin token; 0 ERROR nuevos. Una
# trampa restaura el fichero (y la caché y las colas) si algo falla a medias y lo COMPRUEBA (el fichero idéntico a la
# copia, la configuración efectiva la de antes). Imprime huellas (sha256 y bytes), nunca el texto ni otro valor.
# --en-seco: todas las comprobaciones y las huellas que escribiría; ni copia ni escritura (el registro, sí).
# No exige ninguna fase: el laboratorio, las llamadas y los monitores pueden estar como estén (los hechos son información).
# Los valores, entre comillas dobles y de un conjunto CERRADO (dentro de las comillas, Dotenv solo interpreta «"», «\» y
# «$», que no se admiten; así el texto es literal): letras ASCII, áéíóúüñ y sus mayúsculas, dígitos, el espacio y
# . , ; : - # / ( ). De 1 a 300 caracteres, sin espacios al principio ni al final (la configuración los recorta), sin
# las palabras que env() convierte (true, false, null, empty, con o sin paréntesis), sin URL y sin teléfonos (7 o más
# dígitos seguidos): `.env.example` lo pide así. Lo demás (comillas, $, \, `, tabuladores, saltos de línea, tildes
# sueltas NFD, otro alfabeto, emojis, UTF-8 inválido) se NIEGA con código 2 sin tocar nada.
# Nunca imprime valores del fichero de entorno ni la salida de un tinker que no case con lo esperado. No toca las claves de
# ElevenLabs (rotación: el-verificar.sh; secretos y webhook: h8-secrets-register.sh), ni el motor, ni el H4–H7, ni la
# publicación, ni el freno. No reinicia FPM (el proceso web lee la caché en cada petición) ni ningún programa de supervisor.
set -euo pipefail
export LC_ALL=C
umask 077

# ── Lo propio de producción, en un solo bloque (el banco sustituye estas líneas y ninguna otra) ──────────────────────
APPDIR=/var/www/api/backend
API=https://api.ironbodyneiva.cloud/api
WWW=www-data
ROOT_U=root
ROOT_G=root
COPIAS=/root
CERROJO=/run/lock/h8-voice-scope.lock
NIVEL_ERROR=production.ERROR

uso() {
  sed -n '8,23p' "$0" | sed 's/^# \{0,1\}//'
}
aborta() { echo "ABORTA: $*"; exit 1; }
niega() { echo "NO SE APLICA (no se ha tocado nada): $*"; exit 2; }
# «fase 2 secreto»: el fichero de los secretos se destruye solo si la orden sale APLICADA. Si no, se queda (600, root)
# para repetir sin volver a la consola (Twilio enseña el secreto de una key una sola vez), y se avisa al salir.
aviso_secretos() { [ -z "${SECRETOS_FICHERO:-}" ] || [ ! -e "$SECRETOS_FICHERO" ] || echo "AVISO: $SECRETOS_FICHERO sigue en disco (600, root): repetir la orden o destruirlo con shred -u cuando ya no haga falta"; }
trap aviso_secretos EXIT
DESVIOS=0
desvio() { echo "   ✗ $*"; DESVIOS=$((DESVIOS + 1)); }

[ "$#" -ge 1 ] || { uso; exit 2; }
[ "$(id -u)" = 0 ] || { echo "se ejecuta como root en el servidor"; exit 2; }
cd "$APPDIR"
ENVF="$APPDIR/.env"

# ── Lecturas (como www-data; nunca un valor de un secreto) ───────────────────────────────────────────────────────────
# La configuración efectiva de la voz, plana. Secretos: solo presencia. El número y la subcuenta: los cuatro últimos.
# Las referencias, la base y el id del número importado no son secretos (config/voice.php) y salen tal cual.
leer_voz() {
  local l
  l=$(sudo -u "$WWW" php artisan tinker --execute='$e = "voice.elevenlabs."; $t = "voice.twilio."; $l = "voice.lab."; $hay = fn ($v) => is_string($v) && trim($v) !== ""; $txt = fn ($v) => is_string($v) && trim($v) !== "" ? trim($v) : null; $u4 = fn ($v) => is_string($v) && strlen(trim($v)) >= 4 ? substr(trim($v), -4) : null; $p = (array) config("voice.prices"); $max = (int) config("voice.limits.per_call_max_seconds"); echo json_encode(["calls_in_code" => App\Services\Voice\VoiceCallPolicy::CALLS_ENABLED_IN_CODE, "inbound" => config("voice.inbound.enabled"), "inbound_cap" => config("voice.inbound.daily_cap"), "outbound" => config("voice.outbound.enabled"), "outbound_scope" => config("voice.outbound.scope"), "outbound_cap" => config($t."daily_call_cap"), "budget" => (string) config("voice.limits.daily_budget_usd"), "twilio_voice" => config($t."enabled"), "lab" => config($l."enabled"), "lab_driver" => config($l."driver"), "lab_budget" => (string) config($l."daily_budget_usd"), "lab_session_budget" => (string) config($l."session_budget_usd"), "lab_max_seconds" => config($l."max_session_seconds"), "lab_sessions" => config($l."max_sessions_per_day"), "monitor" => config("voice.monitor.enabled"), "publishing" => config("ultron_studio.voice.publishing_enabled"), "webhook_base" => $txt(config("voice.webhook_base_url")), "phone_number_id" => $txt(config($e."agent_phone_number_id")), "from_last4" => $u4(config($t."from")), "account_last4" => $u4(config($t."account_sid")), "tool_ref" => $txt(config($e."tool_secret_ref")), "initiation_ref" => $txt(config($e."initiation_secret_ref")), "post_call_ref" => $txt(config($e."post_call_webhook_ref")), "s_api_key" => $hay(config($e."api_key")), "s_webhook" => $hay(config($e."webhook_secret")), "s_tool" => $hay(config($e."tool_secret")), "s_initiation" => $hay(config($e."initiation_secret")), "s_twilio_key_sid" => $hay(config($t."api_key_sid")), "s_twilio_key_secret" => $hay(config($t."api_key_secret")), "s_signing" => $hay(config($t."signing_token")), "expect" => $txt(config("ultron_studio.monitor.expect")), "h7_monitor" => config("ultron_studio.monitor.enabled"), "whatsapp_collision" => App\Services\Telephony\VoiceNumberGuard::collidesWithConfig(), "reserve_min_micros" => App\Services\Telephony\VoiceCostGuard::reserveMicros("mobile", $max, null, $p), "reserve_max_micros" => App\Services\Telephony\VoiceCostGuard::reserveMicros("landline", $max, "landline", $p), "studio_voice" => App\Services\UltronStudio\StudioIntegrations::voiceState(), "number_decl_known" => is_array(config("voice.number")), "number_inbound_capable" => config("voice.number.inbound_capable"), "contacts" => config("voice.contacts.enabled")]), PHP_EOL;' 2>&1 | tail -1) || true
  case "$l" in '{"calls_in_code":'*) printf '%s\n' "$l" ;; esac
}
# Lo que el mando NO toca y tiene que seguir igual: el motor, el H4, el H5, el H6, el monitor del H7 y el estado de
# ElevenLabs (el proveedor neutro y lo que afirma el Studio). La misma lectura que leer_previa de la fase 1, sin la bandera
# de Twilio Voice (esa sí la mueven salientes y entrantes y va en leer_voz).
leer_resto() {
  local l
  l=$(sudo -u "$WWW" php artisan tinker --execute='$p = "ultron_studio.integrations."; echo json_encode(["engine_scope" => config("marketing.ultron.studio_config"), "publishing_enabled" => config("ultron_studio.publishing_enabled"), "lab_gate" => config("ultron_studio.publishing.lab_gate"), "lab_enabled" => config("ultron_studio.lab.enabled"), "lab_driver" => config("ultron_studio.lab.driver"), "canary_set" => config("marketing.ultron.canary_conversation_id") !== null, "ultron_enabled" => config("marketing.ultron.enabled"), "writes_enabled" => config($p."writes_enabled"), "probe_enabled" => config($p."twilio_probe.enabled"), "monitor_enabled" => config("ultron_studio.monitor.enabled"), "elevenlabs_enabled" => config("voice.elevenlabs.enabled"), "voice" => App\Services\Voice\VoiceProviders::status()->toArray()]), PHP_EOL;' 2>&1 | tail -1) || true
  case "$l" in '{"engine_scope"'*) printf '%s\n' "$l" ;; esac
}
# voice:doctor --telefonia --json (sin red), resumido en una línea con los nombres de la fase 1.
telefonia() {
  sudo -u "$WWW" php artisan voice:doctor --telefonia --json 2>/dev/null | python3 -I -c '
import json, sys
try:
    d = json.load(sys.stdin)
    t = d["telefonia"]
    f = t["flags"]
    pub = t.get("publication") or {}
    l = t["limits"]
    print("estado=%s constante=%s encendidas=%d cadena=%s freno=%s cupos=%s/%s/%s numero=%s importado=%s subcuenta=%s whatsapp=%s proveedor=%s" % (
        d.get("state"), "abierta" if t.get("callsEnabledInCode") else "cerrada", sum(1 for v in f.values() if v is True),
        ",".join("%s:%s" % (k, (pub.get(k) or {}).get("status")) for k in ("canary", "rest")),
        {True: "echado", False: "suelto"}.get(t.get("brakeOpen"), "?"), l.get("outboundDailyCap"), l.get("inboundDailyCap"),
        l.get("dailyBudgetMicros"), "si" if (t.get("number") or {}).get("configured") else "no",
        "si" if (t.get("number") or {}).get("importedNumberIdConfigured") else "no", "si" if t.get("carrierConfigured") else "no",
        "CHOCA" if (t.get("number") or {}).get("collidesWithMessaging") else "no", t.get("provider")))
except Exception:
    print("?")' 2>/dev/null || echo '?'
}
# El programador por `schedule:list --json`, con su código de salida APARTE (m-1 del H7): «total del_Studio de_voz».
programador() {
  local salida rc=0
  salida=$(sudo -u "$WWW" php artisan schedule:list --json 2>/dev/null) || rc=$?
  [ "$rc" = 0 ] || { echo "FALLO $rc"; return 0; }
  printf '%s' "$salida" | python3 -I -c '
import json, re, sys
try:
    r = json.load(sys.stdin)
    assert isinstance(r, list)
except Exception:
    print("ILEGIBLE")
    sys.exit(0)
c = [str(x.get("command", "")) for x in r]
voz = [x for x in c if re.search(r"(?<![a-z])voice:", x) or "--verify-voice" in x]
print(len(r), sum(1 for x in c if "ultron-studio:" in x and x not in voz), len(voz))' 2>/dev/null || echo ILEGIBLE
}
# Los workers: «RUNNING MAL» esperando hasta $2 s a que RUNNING = $1 y 0 BACKOFF/FATAL.
workers() {
  local hasta s r m
  hasta=$(( $(date +%s) + $2 ))
  while :; do
    s=$(supervisorctl status 2>/dev/null || true)
    r=$(printf '%s\n' "$s" | grep -c RUNNING || true)
    m=$(printf '%s\n' "$s" | grep -cE 'BACKOFF|FATAL' || true)
    if { [ "$r" = "$1" ] && [ "$m" = 0 ]; } || [ "$(date +%s)" -ge "$hasta" ]; then echo "$r $m"; return 0; fi
    sleep 2
  done
}
worker_voz() { supervisorctl status 2>/dev/null | grep -E '^ironbody-voice:' | grep -c RUNNING || true; }
# OPS8-e: las tareas de voz que programa el código DESPLEGADO con VOICE_MONITOR_ENABLED: 4 (las dos conciliaciones, la
# poda y la verificación semanal de ElevenLabs), o 5 si el código trae voice:webhook-health (B-T5, h8-hardening). Se lee
# del código (artisan list), no se supone: así el mando vale antes y después de desplegar B-T5 sin aflojar la cuenta exacta.
tareas_voz() { local n; n=$(sudo -u "$WWW" php artisan list --raw 2>/dev/null | grep -c '^voice:webhook-health[[:space:]]' || true); [ "${n:-0}" -ge 1 ] && echo 5 || echo 4; }
errores_log() { local n=0 f; for f in "$APPDIR"/storage/logs/laravel*.log; do [ -f "$f" ] && n=$((n + $(grep -c "$NIVEL_ERROR" "$f" || true))); done; echo "$n"; }
# El fichero de entorno: un fichero normal, de www-data en 600 o de root:www-data en 640 (C-14), nunca un enlace.
fichero_ok() {
  [ -f "$ENVF" ] && [ ! -L "$ENVF" ] || return 1
  local um
  um=$(stat -c '%U %a' "$ENVF")
  [ "$um" = "$WWW 600" ] && return 0
  [ "$(stat -c '%U:%G %a' "$ENVF")" = "$ROOT_U:$WWW 640" ] && return 0
  return 1
}
cuenta_var() { grep -Ec "^[[:space:]]*(export[[:space:]]+)?$1[[:space:]]*=" "$ENVF" || true; }
# «hechos»: los dos hechos de consultar_horarios como HUELLAS [sha256, bytes] (nunca el texto). leer_hechos, la
# configuración efectiva (artisan como www-data: la caché que lee FPM); facts_known=false si el código desplegado no
# tiene `voice.facts`. hechos_fichero, el fichero tal como lo entiende Dotenv (el mismo analizador que Laravel), sin
# caché: null si la clave no está; nada si el fichero no parsea.
leer_hechos() {
  local l
  l=$(sudo -u "$WWW" php artisan tinker --execute='$f = config("voice.facts"); $h = fn ($v) => is_string($v) ? [hash("sha256", $v), strlen($v)] : null; echo json_encode(["facts_known" => is_array($f), "site" => is_array($f) ? $h($f["site"] ?? null) : null, "hours" => is_array($f) ? $h($f["hours"] ?? null) : null]), PHP_EOL;' 2>&1 | tail -1) || true
  case "$l" in '{"facts_known":'*) printf '%s\n' "$l" ;; esac
}
hechos_fichero() {
  sudo -u "$WWW" php -r 'require "vendor/autoload.php"; try { $e = Dotenv\Dotenv::createArrayBacked(".")->load(); } catch (Throwable $x) { exit(1); } $h = fn ($k) => array_key_exists($k, $e) ? [hash("sha256", (string) $e[$k]), strlen((string) $e[$k])] : null; echo json_encode(["site" => $h("VOICE_FACTS_SITE"), "hours" => $h("VOICE_FACTS_HOURS")]), PHP_EOL;' 2>/dev/null || true
}
mismo_json() { A="$1" B="$2" python3 -I -c 'import json, os, sys; sys.exit(0 if json.loads(os.environ["A"]) == json.loads(os.environ["B"]) else 1)' 2>/dev/null; }
# Una línea legible de las huellas (12 cifras del sha256 y los bytes), para «estado» y para el antes → después.
huellas() { printf '%s' "$1" | python3 -I -c '
import json, sys
try:
    d = json.load(sys.stdin)
except Exception:
    print("ilegible"); sys.exit(0)
f = lambda v: "no está en el fichero" if v is None else ("vacía" if v[1] == 0 else "%d bytes, sha256 %s…" % (v[1], v[0][:12]))
print("sede: %s · horario: %s" % (f(d.get("site")), f(d.get("hours"))))' 2>/dev/null || echo ilegible; }
# La constante del código desplegado: «abierta» solo si en ejecución es true, el fichero tiene exactamente una
# declaración `= true;` anclada y ninguna `= false`, y el último commit que tocó el fichero es `voice(calls-open):`.
constante() {
  local f=app/Services/Voice/VoiceCallPolicy.php abiertas cerradas asunto
  abiertas=$(grep -cE '^[[:space:]]*public const CALLS_ENABLED_IN_CODE = true;[[:space:]]*$' "$f" || true)
  cerradas=$(grep -cE 'CALLS_ENABLED_IN_CODE[[:space:]]*=[[:space:]]*false' "$f" || true)
  asunto=$(git -C "$APPDIR/.." log -1 --format=%s -- "backend/$f" 2>/dev/null || true)
  case "$1" in
    '"calls_in_code":true') [ "$abiertas" = 1 ] && [ "$cerradas" = 0 ] && [[ "$asunto" =~ ^voice\(calls-open\): ]] && { echo abierta; return; }
                            echo "incoherente (en ejecución true; declaraciones true: $abiertas, false: $cerradas; último commit del fichero: «${asunto:0:60}»)" ;;
    *) echo cerrada ;;
  esac
}
# C-1 (11-11-2026): lo que reserva la PUERTA por saliente, leído del código desplegado con SUS funciones (sin red ni
# escritura) y comparado con el presupuesto pedido con su misma regla (VoiceCostGuard::budgetMicros y ::fits, con 0
# comprometido). La puerta (OutboundGate::decide) reserva SIEMPRE con un tramo de transferencia a fijo; el tipo de destino
# solo elige el tramo de la llamada (móvil colombiano +573…, o fijo para cualquier otro y sin número). La puerta decide
# en dos caminos (decide, el del CRM, y decideForStrategy, el de V9) con la misma fórmula: TODA línea de OutboundGate.php
# que asigne $precios o $maxima o llame a reserveMicros( o a fits( tiene que ser, literal, la que conoce este mando (y
# al menos una de cada); si no, las cifras de abajo podrían no ser las de la puerta: «cambiada».
# Salida: una línea JSON {movil, otro, peor, presupuesto, cabe, segundos, precios} en µUS$, o «cambiada: …», o nada.
reserva_puerta() { # $1 = el presupuesto ya validado por es_usd (solo dígitos y un punto)
  local g=app/Services/Telephony/OutboundGate.php par patron l n m
  [[ "$1" =~ ^[0-9]{1,3}(\.[0-9]{1,2})?$ ]] || return 0
  for par in '$precios = |$precios = VoiceCostGuard::prices();' \
             "\$maxima = |\$maxima = (int) config('voice.limits.per_call_max_seconds', 300);" \
             'VoiceCostGuard::reserveMicros(|$reserva = VoiceCostGuard::reserveMicros($numero === null ? VoiceCostGuard::KIND_LANDLINE : VoiceCostGuard::destinationKind($numero), $maxima, VoiceCostGuard::KIND_LANDLINE, $precios);' \
             'VoiceCostGuard::fits(|$cabe = VoiceCostGuard::fits(VoiceCostGuard::budgetFromConfig(), VoiceCostGuard::committedMicros($dia), $reserva);'; do
    patron=${par%%|*}; l=${par#*|}
    n=$(grep -cF -- "$patron" "$g" 2>/dev/null || true); m=$(grep -cxF -- "        $l" "$g" 2>/dev/null || true)
    [ "${n:-0}" -ge 1 ] && [ "$n" = "$m" ] || { echo "cambiada: «${patron}» aparece ${n:-0} veces en $g y solo ${m:-0} son la fórmula conocida"; return 0; }
  done
  l=$(sudo -u "$WWW" php artisan tinker --execute='$g = App\Services\Telephony\VoiceCostGuard::class; $p = $g::prices(); $s = (int) config("voice.limits.per_call_max_seconds", 300); $m = $g::reserveMicros($g::KIND_MOBILE, $s, $g::KIND_LANDLINE, $p); $o = $g::reserveMicros($g::KIND_LANDLINE, $s, $g::KIND_LANDLINE, $p); $b = $g::budgetMicros("'"$1"'"); echo json_encode(["movil" => $m, "otro" => $o, "peor" => max($m, $o), "presupuesto" => $b, "cabe" => $g::fits($b, 0, max($m, $o)), "segundos" => $s, "precios" => $p["as_of"]]), PHP_EOL;' 2>&1 | tail -1) || true
  case "$l" in '{"movil":'*) printf '%s\n' "$l" ;; esac
}

# ── Validación de valores (por forma; ninguno se imprime si es secreto) ──────────────────────────────────────────────
es_usd() { [[ "$1" =~ ^[0-9]{1,3}(\.[0-9]{1,2})?$ ]] && python3 -I -c 'import sys; from decimal import Decimal as D; v = D(sys.argv[1]); sys.exit(0 if D(sys.argv[2]) <= v <= D(sys.argv[3]) else 1)' "$1" "$2" "$3"; }
es_entero() { [[ "$1" =~ ^[0-9]{1,3}$ ]] && [ "$1" -ge "$2" ] && [ "$1" -le "$3" ]; }
# Las piezas NO secretas que admite «fase 2 set», con su forma (config/voice.php y VoiceIds/VoiceAgentTarget):
forma_no_secreta() { # CLAVE VALOR → 0 si casa
  case "$1" in
    VOICE_WEBHOOK_BASE_URL) [[ "$2" =~ ^https://[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+(:[0-9]{1,5})?$ ]] ;;
    ELEVENLABS_AGENT_PHONE_NUMBER_ID|ELEVENLABS_TOOL_SECRET_REF|ELEVENLABS_INITIATION_SECRET_REF|ELEVENLABS_POST_CALL_WEBHOOK_REF) [[ "$2" =~ ^[A-Za-z0-9_-]{1,128}$ ]] ;;
    TWILIO_VOICE_FROM) [[ "$2" =~ ^\+[1-9][0-9]{7,14}$ ]] ;;
    TWILIO_VOICE_ACCOUNT_SID) [[ "$2" =~ ^AC[0-9a-f]{32}$ ]] ;;
    VOICE_NUMBER_INBOUND_CAPABLE) [[ "$2" =~ ^(true|false)$ ]] ;;
    *) return 1 ;;
  esac
}
SECRETAS="TWILIO_VOICE_API_KEY_SID TWILIO_VOICE_API_KEY_SECRET TWILIO_VOICE_SIGNING_TOKEN"

# ── La escritura (python aislado, como root; los valores llegan por la entrada estándar, nunca por argumentos) ─────────
# Sustituye cada clave con la sangría que tuviera (o la añade con dos espacios), escribe un temporal en el mismo
# directorio (O_EXCL, O_NOFOLLOW, 600) con el dueño y el modo del original, fsync y os.replace. Una clave repetida aborta.
escribir() { # «$1» = JSON {CLAVE: valor} por la entrada estándar
  ENVF="$ENVF" python3 -I -c '
import json, os, re, stat, sys
p = os.environ["ENVF"]
cambios = json.load(sys.stdin)
fd = os.open(p, os.O_RDONLY | os.O_NOFOLLOW)
st = os.fstat(fd)
if not stat.S_ISREG(st.st_mode):
    sys.exit("no es un fichero normal")
with os.fdopen(fd, encoding="utf-8") as fh:
    s = fh.read()
for k, v in cambios.items():
    if not re.fullmatch(r"[A-Z][A-Z0-9_]{1,63}", k) or not isinstance(v, str) or re.search(r"[\s#\"\x27\\$`]", v) or v == "":
        sys.exit("clave o valor fuera de forma (no se imprime)")
    pat = re.compile(r"^([ \t]*)(?:export[ \t]+)?" + re.escape(k) + r"[ \t]*=.*$", re.M)
    n = len(pat.findall(s))
    if n > 1:
        sys.exit(k + " repetida en el fichero de entorno: deduplicar a mano")
    if n == 1:
        s = pat.sub(lambda m: m.group(1) + k + "=" + v, s, count=1)
        print("   " + k + " sustituida")
    else:
        if s and not s.endswith("\n"):
            s += "\n"
        s += "  " + k + "=" + v + "\n"
        print("   " + k + " añadida")
d = os.path.dirname(p)
tmp = os.path.join(d, ".env.h8-voice-" + str(os.getpid()))
tfd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
with os.fdopen(tfd, "w", encoding="utf-8") as fh:
    fh.write(s)
    fh.flush()
    os.fchown(fh.fileno(), st.st_uid, st.st_gid)
    os.fchmod(fh.fileno(), stat.S_IMODE(st.st_mode))
    os.fsync(fh.fileno())
os.replace(tmp, p)
dfd = os.open(d, os.O_RDONLY)
os.fsync(dfd)
os.close(dfd)'
}
parsea() { sudo -u "$WWW" php -r 'require "vendor/autoload.php"; try { Dotenv\Dotenv::createArrayBacked(".")->load(); } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' > /dev/null 2>&1; }
cachea() {
  local c=bootstrap/cache/config.php
  sudo -u "$WWW" sh -c 'umask 077; php artisan config:cache -q' || return 1
  # bootstrap/cache lo escribe www-data: root no hace chmod ahí (seguiría un enlace puesto en su lugar; H-5 de SEC7).
  [ -f "$c" ] && [ ! -L "$c" ] || return 1
  [ "$(stat -c '%U' "$c")" = "$WWW" ] || return 1
  [ "$(stat -c '%a' "$c")" = 600 ] || sudo -u "$WWW" chmod 600 "$c" || return 1
}

# ── Las órdenes: cada una deja en CAMBIOS el JSON {CLAVE: valor} y en ORDEN su nombre ───────────────────────────────
ORDEN=""; CAMBIOS=""; VUELTA_FUNCIONAL=""; PROG_DELTA=0; SECRETOS_FICHERO=""; ACEPTA_FRENO=0
json_de() { python3 -I -c 'import json, sys; print(json.dumps(dict(a.split("=", 1) for a in sys.argv[1:]), sort_keys=True))' "$@"; }

ANTES=$(leer_voz)
[ -n "$ANTES" ] || aborta "no se pudo leer la configuración efectiva de la voz (¿está desplegada la fase 1 del H8?; la salida de tinker no se imprime)"
campo() { printf '%s' "$ANTES" | python3 -I -c 'import json, sys; v = json.load(sys.stdin).get(sys.argv[1]); print(json.dumps(v) if not isinstance(v, str) else v)' "$1"; }
CONST=$(constante "$(printf '%s' "$ANTES" | grep -oE '"calls_in_code":(true|false)')")

if [ "$1" = estado ]; then
  [ "$#" = 1 ] || { uso; exit 2; }
  echo "== estado de la voz (configuración efectiva del proceso CLI, la misma caché que el web; sin valores de secretos)"
  echo "   código desplegado: HEAD $(git -C "$APPDIR/.." rev-parse --short=7 HEAD 2>/dev/null || echo '?') · constante de llamadas: $CONST"
  printf '%s' "$ANTES" | python3 -I -c '
import json, sys
d = json.load(sys.stdin)
b = lambda k: "sí" if d.get(k) is True else ("no" if d.get(k) is False else "?")
x = lambda k: d.get(k) if d.get(k) not in (None, "") else "—"
print("   salientes: %s · alcance %s · cupo/día %s · presupuesto/día %s US$ · Twilio Voice %s" % (b("outbound"), x("outbound_scope"), x("outbound_cap"), x("budget"), b("twilio_voice")))
print("   entrantes: %s · cupo/día %s" % (b("inbound"), x("inbound_cap")))
print("   laboratorio hablado: %s · driver %s · presupuesto/día %s US$ · por sesión %s US$ · %s s · %s sesiones/día" % (b("lab"), x("lab_driver"), x("lab_budget"), x("lab_session_budget"), x("lab_max_seconds"), x("lab_sessions")))
print("   monitor de voz: %s · publicar la voz (Studio): %s · monitor del H7: %s (expectativa: %s)" % (b("monitor"), b("publishing"), b("h7_monitor"), x("expect")))
print("   piezas no secretas: VOICE_WEBHOOK_BASE_URL %s · ELEVENLABS_AGENT_PHONE_NUMBER_ID %s · TWILIO_VOICE_FROM %s · TWILIO_VOICE_ACCOUNT_SID %s" % (x("webhook_base"), x("phone_number_id"), ("…" + d["from_last4"]) if d.get("from_last4") else "—", ("AC…" + d["account_last4"]) if d.get("account_last4") else "—"))
print("   referencias (ids, no secretos): herramientas %s · iniciación %s · webhook post-llamada %s" % (x("tool_ref"), x("initiation_ref"), x("post_call_ref")))
s = [("ELEVENLABS_API_KEY", "s_api_key"), ("ELEVENLABS_WEBHOOK_SECRET", "s_webhook"), ("ELEVENLABS_TOOL_SECRET", "s_tool"), ("ELEVENLABS_INITIATION_SECRET", "s_initiation"), ("TWILIO_VOICE_API_KEY_SID", "s_twilio_key_sid"), ("TWILIO_VOICE_API_KEY_SECRET", "s_twilio_key_secret"), ("TWILIO_VOICE_SIGNING_TOKEN", "s_signing")]
print("   secretos (solo presencia): " + " · ".join("%s %s" % (n, "presente" if d.get(k) else "falta") for n, k in s))
print("   número de voz igual a uno de WhatsApp: %s · ElevenLabs según el Studio: %s" % ("SÍ (la política deniega por nombre)" if d.get("whatsapp_collision") else "no", x("studio_voice")))
print("   el número admite entrantes (VOICE_NUMBER_INBOUND_CAPABLE): %s" % ({True: "sí (declarado)", False: "NO: «Outbound Only» declarado", None: "sin declarar (se trata como «admite»)"}.get(d.get("number_inbound_capable"), "?") if d.get("number_decl_known") else "el código desplegado no lee la declaración (B8-g/S8-d)"))
print("   reserva por saliente (VoiceCostGuard): %.4f US$ a un móvil sin transferencia · %.4f US$ a un fijo con transferencia" % (d.get("reserve_min_micros", 0) / 1e6, d.get("reserve_max_micros", 0) / 1e6))
print("   contactos de voz (alta y consentimiento, VOICE_CONTACTS_ENABLED, B-T4): %s" % {True: "SÍ (fase 4b)", False: "no (409 voice_contacts_disabled; revocar, excluir y el RNE siguen)"}.get(d.get("contacts"), "el código desplegado no lee la bandera (antes de B-T4: el alta no tiene bandera)"))'
  echo "   voice:doctor --telefonia (sin red): $(telefonia)"
  HE=$(leer_hechos)
  case "$HE" in
    *'"facts_known":true'*)
      DICE=$(printf '%s' "$HE" | python3 -I -c 'import json, sys; d = json.load(sys.stdin); print("sí" if all((d.get(k) or [0, 0])[1] > 0 for k in ("site", "hours")) else "no (necesita los dos; dice que no los tiene)")' 2>/dev/null || echo '?')
      echo "   hechos de consultar_horarios (huellas, nunca el texto): $(huellas "$HE") · líneas en el fichero: VOICE_FACTS_SITE $(cuenta_var VOICE_FACTS_SITE), VOICE_FACTS_HOURS $(cuenta_var VOICE_FACTS_HOURS) · la herramienta los dice: $DICE (bash $0 hechos …)" ;;
    *) echo "   hechos de consultar_horarios: el código desplegado no tiene voice.facts (o tinker falló)" ;;
  esac
  P=$(programador); echo "   programador (total · del Studio · de voz): $P (de voz: $(tareas_voz) con el monitor de voz encendido, 0 apagado)"
  echo "   workers (RUNNING · BACKOFF/FATAL): $(workers 999 0) · ironbody-voice en RUNNING: $(worker_voz) (lo exige «salientes on»)"
  if fichero_ok; then echo "   fichero de entorno: $(stat -c '%U:%G %a' "$ENVF") (C-14: www-data 600 o root:www-data 640)"; else echo "   fichero de entorno: $(stat -c '%U:%G %a' "$ENVF" 2>/dev/null || echo '?') — NO ADMITIDO (C-14): ninguna orden que escriba seguirá"; fi
  E=$(printf '%s' "$ANTES" | python3 -I -c 'import json, sys; print(json.load(sys.stdin).get("expect") or "")')
  if [ -n "$E" ]; then
    if sudo -u "$WWW" php artisan ultron-studio:integrations-doctor --expect="$E" > /dev/null 2>&1; then echo "   integrations-doctor confirma la expectativa del monitor del H7: sí"; else echo "   integrations-doctor confirma la expectativa del monitor del H7: NO (con el monitor del H7 encendido abriría un incidente; ver «expectativa»)"; fi
  fi
  exit 0
fi

# ── «hechos»: VOICE_FACTS_SITE y VOICE_FACTS_HOURS, en su propio bloque (las garantías, en la cabecera) ───────────────
# El escritor de los hechos: el plan JSON {CLAVE: texto | null} por la entrada estándar (null = quitar la línea). Vuelve a
# comprobar el conjunto cerrado (defensa en profundidad), escribe CLAVE="texto" con la sangría que tuviera la línea (dos
# espacios si se añade), lee y escribe sin traducir finales de línea (los bytes ajenos quedan idénticos), en un temporal
# del mismo directorio (O_EXCL, O_NOFOLLOW, 600) con el dueño y el modo del original, fsync y os.replace.
escribir_hechos() {
  ENVF="$ENVF" python3 -I -c '
import json, os, re, stat, sys
PERMITIDOS = set("ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789 .,;:-#/()ÁÉÍÓÚÜÑáéíóúüñ")
p = os.environ["ENVF"]
cambios = json.load(sys.stdin)
fd = os.open(p, os.O_RDONLY | os.O_NOFOLLOW)
st = os.fstat(fd)
if not stat.S_ISREG(st.st_mode):
    sys.exit("no es un fichero normal")
with os.fdopen(fd, encoding="utf-8", newline="") as fh:
    s = fh.read()
for k, v in sorted(cambios.items()):
    if k not in ("VOICE_FACTS_SITE", "VOICE_FACTS_HOURS"):
        sys.exit("clave fuera de los hechos")
    if v is not None and (not isinstance(v, str) or v == "" or len(v) > 300 or v != v.strip(" ") or any(c not in PERMITIDOS for c in v)):
        sys.exit("valor fuera del conjunto admitido (no se escribe ni se imprime)")
    pat = re.compile(r"^([ \t]*)(?:export[ \t]+)?" + re.escape(k) + r"[ \t]*=[^\r\n]*", re.M)
    n = len(pat.findall(s))
    if n > 1:
        sys.exit(k + " repetida en el fichero de entorno: deduplicar a mano")
    if v is None:
        if n == 1:
            s = re.sub(r"^[ \t]*(?:export[ \t]+)?" + re.escape(k) + r"[ \t]*=[^\r\n]*(?:\r?\n|\Z)", "", s, count=1, flags=re.M)
            print("   " + k + " quitada")
        else:
            print("   " + k + " no estaba")
        continue
    linea = k + "=\"" + v + "\""
    if n == 1:
        s = pat.sub(lambda m: m.group(1) + linea, s, count=1)
        print("   " + k + " sustituida")
    else:
        if s and not s.endswith("\n"):
            s += "\n"
        s += "  " + linea + "\n"
        print("   " + k + " añadida")
d = os.path.dirname(p) or "."
tmp = os.path.join(d, ".env.h8-voice-hechos-" + str(os.getpid()))
tfd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
try:
    with os.fdopen(tfd, "w", encoding="utf-8", newline="") as fh:
        fh.write(s)
        fh.flush()
        os.fchown(fh.fileno(), st.st_uid, st.st_gid)
        os.fchmod(fh.fileno(), stat.S_IMODE(st.st_mode))
        os.fsync(fh.fileno())
    os.replace(tmp, p)
except BaseException:
    try:
        os.unlink(tmp)
    except FileNotFoundError:
        pass
    raise
dfd = os.open(d, os.O_RDONLY)
os.fsync(dfd)
os.close(dfd)'
}
if [ "$1" = hechos ]; then
  shift
  DS=0; DH=0; BORRAR=0; EN_SECO=0; HS=""; HH=""
  [ "$#" -ge 1 ] || niega "hechos --sede='…' [--horario='…'] [--en-seco]  |  hechos borrar [--en-seco]"
  for a in "$@"; do
    case "$a" in
      --sede=*) [ "$DS" = 0 ] || niega "--sede va una sola vez"; DS=1; HS=${a#--sede=} ;;
      --horario=*) [ "$DH" = 0 ] || niega "--horario va una sola vez"; DH=1; HH=${a#--horario=} ;;
      borrar) BORRAR=1 ;;
      --en-seco) EN_SECO=1 ;;
      *) niega "argumento desconocido (no se imprime): hechos --sede='…' [--horario='…'] [--en-seco]  |  hechos borrar [--en-seco]" ;;
    esac
  done
  if [ "$BORRAR" = 1 ]; then
    [ "$DS$DH" = 00 ] || niega "«hechos borrar» va solo (quita los dos)"
    ORDEN=hechos-borrar
  else
    ORDEN=hechos
  fi
  # El conjunto CERRADO y las formas prohibidas, antes de nada (código 2 sin registro ni cerrojos). Los mensajes dicen el
  # motivo y, como mucho, los códigos U+XXXX de lo rechazado; nunca el texto. El plan sale en JSON ASCII.
  PLAN=$(python3 -I -c '
import json, re, sys, unicodedata
borrar, ds, hs, dh, hh = sys.argv[1:6]
PERMITIDOS = set("ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789 .,;:-#/()ÁÉÍÓÚÜÑáéíóúüñ")
RESERVADAS = {"true", "false", "null", "empty", "(true)", "(false)", "(null)", "(empty)"}
MAXIMO = 300
def valida(nombre, crudo):
    try:
        v = crudo.encode("utf-8", "surrogateescape").decode("utf-8")
    except UnicodeError:
        sys.exit("%s: no es UTF-8 válido" % nombre)
    if v == "":
        sys.exit("%s vacía: para quitar los hechos, «hechos borrar»" % nombre)
    if len(v) > MAXIMO:
        sys.exit("%s: %d caracteres (máximo %d)" % (nombre, len(v), MAXIMO))
    fuera = sorted({c for c in v if c not in PERMITIDOS})
    if fuera:
        nfd = " · lleva una tilde SUELTA (NFD): escribir la letra compuesta" if any(unicodedata.combining(c) for c in fuera) else ""
        sys.exit("%s: %d carácter(es) fuera del conjunto admitido (%s)%s. Se admiten letras, áéíóúüñ y sus mayúsculas, dígitos, el espacio y . , ; : - # / ( ); nada de comillas, $, \\, acento grave, tabuladores ni saltos de línea" % (nombre, len(fuera), ", ".join("U+%04X" % ord(c) for c in fuera[:8]), nfd))
    if v != v.strip(" "):
        sys.exit("%s: espacios al principio o al final (la configuración los recorta y no casaría byte a byte)" % nombre)
    if v.lower() in RESERVADAS:
        sys.exit("%s: env() de Laravel convierte esa palabra (true, false, null, empty) en otra cosa; no es un texto" % nombre)
    if "://" in v or re.search(r"(?i)www\.", v):
        sys.exit("%s: parece una URL (los hechos son texto plano, sin URL: .env.example)" % nombre)
    if re.search(r"[0-9](?:[ .()-]*[0-9]){6,}", v):
        sys.exit("%s: 7 o más dígitos seguidos parecen un teléfono (los hechos no llevan teléfonos: .env.example)" % nombre)
    return v
if borrar == "1":
    plan = {"VOICE_FACTS_SITE": None, "VOICE_FACTS_HOURS": None}
else:
    if ds != "1" and dh != "1":
        sys.exit("falta --sede=\x27…\x27 o --horario=\x27…\x27 (o «hechos borrar»)")
    plan = {}
    if ds == "1":
        plan["VOICE_FACTS_SITE"] = valida("la sede", hs)
    if dh == "1":
        plan["VOICE_FACTS_HOURS"] = valida("el horario", hh)
print(json.dumps(plan, sort_keys=True))' "$BORRAR" "$DS" "$HS" "$DH" "$HH" 2>&1) || niega "$PLAN"
  unset HS HH

  # MB-5: todo lo que se imprime queda además en un registro de root (600).
  LOG="$COPIAS/h8-voice-scope-$ORDEN-$(date -u +%Y%m%d-%H%M%S).log"
  ( umask 077; : > "$LOG" ) || { echo "no se pudo crear el registro $LOG; no se ha tocado nada"; exit 2; }
  exec > >(tee -a "$LOG") 2>&1
  echo "== h8-voice-scope.sh $ORDEN · $(date -u +%Y-%m-%dT%H:%M:%SZ) · registro $LOG (huellas sha256 y bytes, nunca el texto)"
  # MB-4: exclusión mutua con cualquier otro mando que escriba el fichero de entorno (los tres cerrojos de la ola M-1).
  CERROJOS=$(dirname "$CERROJO")
  exec 7> "$CERROJOS/ironbody-env.lock" 8> "$CERROJO" 9> "$CERROJOS/h9-outreach-scope.lock"
  { flock -n 7 && flock -n 8 && flock -n 9; } || aborta "otro mando que escribe el fichero de entorno está en curso: esperar a que termine (no se ha tocado nada)"
  fichero_ok || aborta "el fichero de entorno no es un fichero normal de $WWW en 600 ni de $ROOT_U:$WWW en 640 (es $(stat -c '%U:%G %a' "$ENVF" 2>/dev/null || echo '?')): corregirlo a mano antes (C-14, N-4)"
  H0=$(leer_hechos)
  [ -n "$H0" ] || aborta "no se pudo leer la configuración efectiva de los hechos (tinker; su salida no se imprime)"
  case "$H0" in *'"facts_known":true'*) ;; *) niega "el código desplegado no tiene voice.facts (config/voice.php, D8-10): escribirlos no tendría efecto" ;; esac
  for k in VOICE_FACTS_SITE VOICE_FACTS_HOURS; do [ "$(cuenta_var "$k")" -le 1 ] || niega "$k aparece repetida en el fichero de entorno (gana la última): deduplicar antes"; done
  F0=$(hechos_fichero)
  [ -n "$F0" ] || aborta "el fichero de entorno NO parsea hoy (Dotenv): no se ha tocado nada"
  # Lo esperado: en la configuración efectiva, la huella del argumento (la del texto vacío al borrar) o la de antes si esa
  # clave no se pide; en el fichero (Dotenv), la huella del argumento, null al borrar, o la de antes.
  ESP=$(PLAN="$PLAN" H0="$H0" F0="$F0" python3 -I -c '
import hashlib, json, os
plan = json.loads(os.environ["PLAN"]); h0 = json.loads(os.environ["H0"]); f0 = json.loads(os.environ["F0"])
hu = lambda v: [hashlib.sha256(v.encode("utf-8")).hexdigest(), len(v.encode("utf-8"))]
ef = {"facts_known": True}; fi = {}
for campo, k in (("site", "VOICE_FACTS_SITE"), ("hours", "VOICE_FACTS_HOURS")):
    if k in plan:
        ef[campo] = hu(plan[k] if plan[k] is not None else "")
        fi[campo] = hu(plan[k]) if plan[k] is not None else None
    else:
        ef[campo] = h0.get(campo)
        fi[campo] = f0.get(campo)
print(json.dumps(ef))
print(json.dumps(fi))')
  ESP_EF=$(printf '%s\n' "$ESP" | sed -n 1p); ESP_FI=$(printf '%s\n' "$ESP" | sed -n 2p)
  [ -n "$ESP_EF" ] && [ -n "$ESP_FI" ] || aborta "no se pudo calcular lo esperado (no se ha tocado nada)"
  # Para fijar, el fichero y la caché tienen que decir HOY lo mismo (si no, falta un config:cache o alguien los tocó a mano).
  # «hechos borrar» es la vuelta atrás y no se condiciona a esto.
  if [ "$BORRAR" = 0 ]; then
    COHERENTE=$(H0="$H0" F0="$F0" python3 -I -c '
import hashlib, json, os
h0 = json.loads(os.environ["H0"]); f0 = json.loads(os.environ["F0"])
vacia = [hashlib.sha256(b"").hexdigest(), 0]
print("si" if all((f0.get(c) if f0.get(c) is not None else vacia) == h0.get(c) for c in ("site", "hours")) else "no")')
    [ "$COHERENTE" = si ] || aborta "el fichero y la configuración efectiva no dicen lo mismo de los hechos (fichero: $(huellas "$F0") · efectiva: $(huellas "$H0")): falta un config:cache o se tocaron a mano; aclararlo antes (no se ha tocado nada; «hechos borrar» no lo exige)"
  fi
  if mismo_json "$ESP_EF" "$H0" && mismo_json "$ESP_FI" "$F0"; then
    echo "== $ORDEN: el fichero y la configuración efectiva ya dicen eso ($(huellas "$F0")); nada que escribir"
    exit 0
  fi
  RESTO_ANTES=$(leer_resto)
  [ -n "$RESTO_ANTES" ] || aborta "no se pudo leer la configuración del motor y del H4–H7 (tinker falló; su salida no se imprime)"
  PROG0=$(programador)
  case "$PROG0" in "FALLO "*|ILEGIBLE) aborta "schedule:list no se puede leer ($PROG0): sin el programador no se toca nada (m-1 del H7)" ;; esac
  W0=$(workers 999 0); RUN0=${W0%% *}
  [ "${W0#* }" = 0 ] || aborta "workers con BACKOFF o FATAL antes de empezar ($W0): no se toca nada sobre un sistema degradado"
  case "$RUN0" in 13|14) ;; *) aborta "workers RUNNING antes de empezar: $RUN0 (13 sin ironbody-voice, 14 con él)" ;; esac
  ERR0=$(errores_log)
  if [ "$EN_SECO" = 1 ]; then
    echo "== EN SECO ($ORDEN): no se ha tocado nada (ni copia ni escritura). Todas las comprobaciones previas pasan:"
    echo "   fichero $(stat -c '%U:%G %a' "$ENVF") · programador $PROG0 · workers $W0 · el código lee voice.facts"
    echo "   antes (configuración efectiva): $(huellas "$H0") · líneas en el fichero: VOICE_FACTS_SITE $(cuenta_var VOICE_FACTS_SITE), VOICE_FACTS_HOURS $(cuenta_var VOICE_FACTS_HOURS)"
    echo "   escribiría (en el fichero): $(huellas "$ESP_FI") · configuración efectiva esperada: $(huellas "$ESP_EF")"
    echo "   comparar estas huellas con las del texto que se quería escribir ANTES de repetir la orden sin --en-seco"
    exit 0
  fi
  DUENO=$(stat -c '%U:%G' "$ENVF"); MODO=$(stat -c '%a' "$ENVF")
  TS=$(date -u +%Y%m%d-%H%M%S)
  COPIA="$COPIAS/env-pre-voice-$ORDEN-$TS"
  [ ! -e "$COPIA" ] || aborta "ya existe $COPIA: nunca se sobrescribe una copia (esperar un segundo y repetir)"
  install -m 600 -o "$ROOT_U" -g "$ROOT_G" "$ENVF" "$COPIA" || { rm -f -- "$COPIA"; aborta "no se pudo hacer la copia previa (¿disco lleno?): no se ha tocado nada"; }
  cmp -s "$ENVF" "$COPIA" || aborta "la copia previa no es idéntica al fichero de entorno: no se ha tocado nada (copia: $COPIA)"
  VUELTA="install -m $MODO -o ${DUENO%%:*} -g ${DUENO##*:} $COPIA $ENVF && cd $APPDIR && sudo -u $WWW sh -c 'umask 077; php artisan config:cache -q' && sudo -u $WWW chmod 600 bootstrap/cache/config.php && sudo -u $WWW php artisan queue:restart -q"
  # La trampa: un fallo a medias (etapa 1, el fichero escrito; etapa 2, además la caché nueva, ya activa en FPM) no deja
  # los hechos nuevos sin que nadie lo sepa: restaura el fichero (y la caché y las colas) y lo COMPRUEBA.
  ETAPA=0
  al_salir_hechos() {
    local rc=$? h v r ok=1
    set +e
    { [ "$ETAPA" = 1 ] || [ "$ETAPA" = 2 ]; } || return 0
    echo "FALLO A MEDIAS (etapa $ETAPA, código $rc; 0 si lo cortó una señal): se restaura el fichero de entorno desde la copia previa"
    if install -m "$MODO" -o "${DUENO%%:*}" -g "${DUENO##*:}" "$COPIA" "$ENVF" && cmp -s "$COPIA" "$ENVF" && [ "$(stat -c '%U:%G %a' "$ENVF")" = "$DUENO $MODO" ]; then
      echo "   fichero de entorno restaurado: idéntico byte a byte a la copia previa, $DUENO $MODO"
    else
      ok=0; echo "   ✗ el fichero de entorno NO quedó idéntico a la copia previa"
    fi
    if [ "$ETAPA" = 2 ]; then
      if cachea; then echo "   caché regenerada con el fichero de antes ($(stat -c '%U %a' bootstrap/cache/config.php))"; else ok=0; echo "   ✗ la caché NO se regeneró"; fi
      if sudo -u "$WWW" php artisan queue:restart -q; then echo "   colas reiniciadas (por si un worker llegó a cargar la caché nueva)"; else ok=0; echo "   ✗ queue:restart no salió con 0"; fi
    fi
    h=$(leer_hechos); v=$(leer_voz); r=$(leer_resto)
    if [ -n "$h" ] && [ "$h" = "$H0" ]; then echo "   hechos efectivos: los de antes ($(huellas "$h"))"; else ok=0; echo "   ✗ hechos efectivos: $(huellas "${h:-x}") (antes: $(huellas "$H0"))"; fi
    { [ -n "$v" ] && [ "$v" = "$ANTES" ] && [ -n "$r" ] && [ "$r" = "$RESTO_ANTES" ]; } || { ok=0; echo "   ✗ la configuración de la voz, del motor o del H4–H7 no es la de antes (no se imprime: comparar con «bash $0 estado»)"; }
    if [ "$ok" = 1 ]; then
      echo "   restaurado y COMPROBADO: el fichero es la copia previa byte a byte y la configuración efectiva es la de antes"
    else
      echo "   ✗ restauración INCOMPLETA: vuelta a mano: $VUELTA"
    fi
    echo "   la copia previa se conserva: $COPIA (600; lleva secretos: borrarla con shred -u cuando ya no haga falta)"
    echo "MANDO $ORDEN NO APLICADO (fallo a medias; restaurado: $([ "$ok" = 1 ] && echo sí || echo 'NO del todo'))"
  }
  trap al_salir_hechos EXIT
  echo "0. $ORDEN · copia previa: $COPIA (600, $ROOT_U) · fichero $DUENO $MODO · programador $PROG0 · workers $W0"
  echo "   antes (configuración efectiva): $(huellas "$H0") · líneas en el fichero: VOICE_FACTS_SITE $(cuenta_var VOICE_FACTS_SITE), VOICE_FACTS_HOURS $(cuenta_var VOICE_FACTS_HOURS)"
  echo "   pedido (en el fichero): $(huellas "$ESP_FI")"

  ETAPA=1
  printf '%s' "$PLAN" | escribir_hechos || aborta "no se pudo escribir el fichero de entorno (se restaura solo)"
  [ "$(stat -c '%U:%G %a' "$ENVF")" = "$DUENO $MODO" ] || aborta "el dueño o el modo del fichero de entorno cambiaron al escribir (se restaura solo)"
  # El fichero es la copia previa salvo las líneas de los dos hechos (líneas en blanco incluidas; sin imprimir nada).
  python3 -I -c '
import re, sys
pat = re.compile(r"^[ \t]*(?:export[ \t]+)?(VOICE_FACTS_SITE|VOICE_FACTS_HOURS)[ \t]*=")
f = lambda p: [l.rstrip("\r\n") for l in open(p, encoding="utf-8", newline="").read().splitlines(True) if not pat.match(l)]
sys.exit(0 if f(sys.argv[1]) == f(sys.argv[2]) else 1)' "$ENVF" "$COPIA" || aborta "el fichero de entorno difiere de la copia previa en algo más que los hechos (se restaura solo)"
  # B-1 del H5: el fichero tiene que parsear ANTES de tocar la caché, y dar el valor pedido BYTE A BYTE (el texto va
  # entre comillas dobles: un «#» o un espacio sin ellas cortarían el valor).
  parsea || aborta "el fichero de entorno NO parsea tras escribir (se restaura solo)"
  F1=$(hechos_fichero)
  mismo_json "$F1" "$ESP_FI" || aborta "el fichero, leído por Dotenv, no da lo pedido byte a byte ($(huellas "${F1:-x}"); pedido: $(huellas "$ESP_FI")); se restaura solo"
  echo "1. escrito: $(huellas "$F1") = lo pedido byte a byte (Dotenv) · fichero $(stat -c '%U:%G %a' "$ENVF") · el resto, idéntico a la copia previa"

  ETAPA=2
  cachea || aborta "config:cache falló o la caché no quedó de $WWW en 600 (su salida no se imprime); se restaura solo"
  H1=$(leer_hechos)
  mismo_json "$H1" "$ESP_EF" || aborta "la configuración efectiva no es la pedida BYTE A BYTE ($(huellas "${H1:-x}"); pedida: $(huellas "$ESP_EF")); se restaura sola"
  echo "2. caché $(stat -c '%U %a' bootstrap/cache/config.php) · configuración efectiva (artisan como $WWW): $(huellas "$H0") → $(huellas "$H1") = el argumento byte a byte"
  V1=$(leer_voz)
  [ -n "$V1" ] && [ "$V1" = "$ANTES" ] || aborta "la configuración de la voz cambió en algo más que los hechos (no se imprime); se restaura sola"
  R1=$(leer_resto)
  [ -n "$R1" ] && [ "$R1" = "$RESTO_ANTES" ] || aborta "la configuración del motor, del H4–H7 o de ElevenLabs cambió; se restaura sola"
  PROG1=$(programador)
  [ "$PROG1" = "$PROG0" ] || aborta "el programador cambió ($PROG0 → $PROG1; los hechos no programan nada); se restaura solo"
  echo "3. sin cambios: el resto de la voz, el motor, el H4–H7, ElevenLabs y el programador ($PROG1)"
  sudo -u "$WWW" php artisan queue:restart -q || aborta "queue:restart no salió con 0; se restaura solo"
  ETAPA=3   # aplicado en FPM y en los workers: desde aquí lo que falle es un DESVÍO que se dice, no se deshace solo

  sleep 6
  W1=$(workers "$RUN0" 45); sleep 8; W2=$(workers "$RUN0" 45)
  echo "4. workers (RUNNING · BACKOFF/FATAL), dos lecturas: [$W1] y [$W2] (esperado $RUN0 0 las dos veces)"
  [ "$W1" = "$RUN0 0" ] && [ "$W2" = "$RUN0 0" ] || desvio "workers [$W1] y [$W2] (esperado $RUN0 0)"
  c=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 -H 'Accept: application/json' "$API/admin/voice/status") || c=000
  echo "5. admin/voice/status sin token → $c"; [ "$c" = 401 ] || desvio "admin/voice/status sin token respondió $c (esperado 401)"
  ERR=$(errores_log)
  echo "6. $NIVEL_ERROR en laravel*.log: $ERR0 → $ERR"; [ "$ERR" = "$ERR0" ] || desvio "$((ERR - ERR0)) ERROR nuevos en el log durante el cambio"
  # Lo que verá consultar_horarios: con los DOS hechos, los dice; con uno o ninguno, dice que no los tiene.
  ESP_HC=$(printf '%s' "$ESP_EF" | python3 -I -c 'import json, sys; d = json.load(sys.stdin); print("true" if d["site"][1] > 0 and d["hours"][1] > 0 else "false")')
  DOC=$(sudo -u "$WWW" php artisan voice:doctor --telefonia --json 2>/dev/null || true)
  HC=$(printf '%s' "$DOC" | python3 -I -c 'import json, sys
try:
    v = json.load(sys.stdin)["telefonia"]["facts"]["hoursConfigured"]
    print("true" if v is True else ("false" if v is False else "?"))
except Exception:
    print("?")' 2>/dev/null || echo '?')
  echo "7. voice:doctor --telefonia (sin red): facts.hoursConfigured=$HC (esperado $ESP_HC: consultar_horarios $([ "$ESP_HC" = true ] && echo 'dice la sede y el horario' || echo 'dice que no los tiene'))"
  case "$HC" in true|false) [ "$HC" = "$ESP_HC" ] || desvio "voice:doctor dice facts.hoursConfigured=$HC (esperado $ESP_HC)" ;; *) echo "   (voice:doctor no se pudo leer: informativo)" ;; esac
  [ "$ESP_HC" = true ] || [ "$BORRAR" = 1 ] || echo "   AVISO: consultar_horarios solo los dice con los DOS puestos; falta $(printf '%s' "$ESP_EF" | python3 -I -c 'import json, sys; d = json.load(sys.stdin); print(" y ".join(n for n, c in (("la sede (--sede)", "site"), ("el horario (--horario)", "hours")) if d[c][1] == 0))')"
  echo "   vuelta atrás exacta (el fichero de antes): $VUELTA"
  case "$BORRAR:$F0" in
    0:'{"site":null,"hours":null}') echo "   vuelta atrás funcional: bash $0 hechos borrar (antes no estaban en el fichero: vuelve idéntico byte a byte)" ;;
    0:*) echo "   vuelta atrás funcional: bash $0 hechos borrar (los quita; antes había líneas de los hechos: para dejar el fichero idéntico, la exacta)" ;;
    *) echo "   para volver a ponerlos: bash $0 hechos --sede='…' --horario='…' (o la exacta, con la copia)" ;;
  esac
  echo "   la copia previa ($COPIA, 600) lleva secretos: borrarla con shred -u cuando la vuelta exacta ya no haga falta"
  if [ "$DESVIOS" = 0 ]; then
    echo "MANDO $ORDEN APLICADO (copia previa: $COPIA; registro: $LOG)"
    exit 0
  fi
  echo "MANDO $ORDEN ESCRITO CON $DESVIOS DESVÍOS: no seguir hasta aclararlos (copia previa: $COPIA; vuelta: $VUELTA)"
  exit 1
fi

# ── Análisis de la orden ───────────────────────────────────────────────────────────────────────────────────────────
OUT=$(campo outbound); INB=$(campo inbound)
case "$1 ${2:-}" in
  "lab on")
    ORDEN=lab-on; shift 2
    DRIVER=""; PD=""; PS=""; SES=""; SEG=""
    for a in "$@"; do
      case "$a" in
        --driver=*) DRIVER=${a#*=} ;;
        --presupuesto-dia=*) PD=${a#*=} ;;
        --presupuesto-sesion=*) PS=${a#*=} ;;
        --sesiones-dia=*) SES=${a#*=} ;;
        --segundos=*) SEG=${a#*=} ;;
        *) niega "argumento desconocido: $a" ;;
      esac
    done
    case "$DRIVER" in elevenlabs|fake) ;; *) niega "--driver=elevenlabs|fake es obligatorio (sin defecto: el simulador o la cuenta real, decidido a propósito)" ;; esac
    es_usd "$PD" 0.01 5.00 || niega "--presupuesto-dia en US$ con hasta 2 decimales, de 0.01 a 5.00 (fase 2a: un techo mayor es un cambio de este script)"
    es_usd "$PS" 0.01 1.00 || niega "--presupuesto-sesion en US$ con hasta 2 decimales, de 0.01 a 1.00 (5 min de ElevenLabs ≈ 0.40 US$)"
    python3 -I -c 'import sys; from decimal import Decimal as D; sys.exit(0 if D(sys.argv[2]) <= D(sys.argv[1]) else 1)' "$PD" "$PS" || niega "el presupuesto por sesión no puede pasar del diario"
    [ -z "$SES" ] || es_entero "$SES" 1 10 || niega "--sesiones-dia de 1 a 10"
    [ -z "$SEG" ] || es_entero "$SEG" 60 300 || niega "--segundos de 60 a 300 (D8-23: como mucho 5 min por sesión)"
    if [ "$DRIVER" = elevenlabs ]; then
      FALTAN=$(printf '%s' "$ANTES" | python3 -I -c 'import json, sys; d = json.load(sys.stdin); print(" ".join(n for n, k in [("VOICE_WEBHOOK_BASE_URL", "webhook_base"), ("ELEVENLABS_TOOL_SECRET_REF", "tool_ref"), ("ELEVENLABS_INITIATION_SECRET_REF", "initiation_ref"), ("ELEVENLABS_POST_CALL_WEBHOOK_REF", "post_call_ref")] if not d.get(k)))')
      [ -z "$FALTAN" ] || niega "lab on con driver elevenlabs exige las tres referencias y la base de los webhooks; faltan: $FALTAN (h8-secrets-register.sh y «fase 2 set»)"
      [ "$(campo studio_voice)" = ELEVENLABS_VERIFIED ] || niega "el Studio no afirma ELEVENLABS_VERIFIED ($(campo studio_voice)): el laboratorio respondería credentials_unverified; renovar la verificación antes (ultron-studio:integrations-snapshot --verify-voice, un GET, con autorización)"
    fi
    A=(VOICE_LAB_ENABLED=true "VOICE_LAB_DRIVER=$DRIVER" "VOICE_LAB_DAILY_BUDGET_USD=$PD" "VOICE_LAB_SESSION_BUDGET_USD=$PS")
    [ -z "$SES" ] || A+=("VOICE_LAB_MAX_SESSIONS_PER_DAY=$SES")
    [ -z "$SEG" ] || A+=("VOICE_LAB_MAX_SESSION_SECONDS=$SEG")
    CAMBIOS=$(json_de "${A[@]}")
    VUELTA_FUNCIONAL="bash $0 lab off" ;;
  "lab off")
    ORDEN=lab-off; [ "$#" = 2 ] || { uso; exit 2; }
    # Los cuatro cerrojos de gasto cerrados a la vez (protección 12): bandera, driver de simulador y presupuestos a 0.
    CAMBIOS=$(json_de VOICE_LAB_ENABLED=false VOICE_LAB_DRIVER=fake VOICE_LAB_DAILY_BUDGET_USD=0 VOICE_LAB_SESSION_BUDGET_USD=0) ;;
  "fase 2")
    case "${3:-}" in
      set)
        ORDEN=fase2-set; shift 3
        [ "$#" -ge 1 ] || niega "fase 2 set CLAVE=VALOR [CLAVE=VALOR …]"
        [ "$OUT $INB" = "false false" ] || niega "las piezas se cambian con las salientes y las entrantes apagadas (hoy: salientes $OUT, entrantes $INB)"
        A=()
        for a in "$@"; do
          k=${a%%=*}; v=${a#*=}
          [ "$a" != "$k" ] || niega "«$k» va como CLAVE=VALOR"
          case " $SECRETAS ELEVENLABS_API_KEY ELEVENLABS_WEBHOOK_SECRET ELEVENLABS_TOOL_SECRET ELEVENLABS_INITIATION_SECRET " in
            *" $k "*) niega "$k es un SECRETO: nunca por argumentos (quedaría en ps y en el historial). Twilio: «fase 2 secreto <fichero>»; ElevenLabs: el-verificar.sh y h8-secrets-register.sh" ;;
          esac
          forma_no_secreta "$k" "$v" || niega "$k no es una pieza de «fase 2 set» o su valor no tiene la forma (las admitidas: VOICE_WEBHOOK_BASE_URL, ELEVENLABS_AGENT_PHONE_NUMBER_ID, TWILIO_VOICE_FROM, TWILIO_VOICE_ACCOUNT_SID, las tres *_REF y VOICE_NUMBER_INBOUND_CAPABLE=true|false)"
          [ "$k" != VOICE_NUMBER_INBOUND_CAPABLE ] || [ "$(campo number_decl_known)" = true ] || niega "el código desplegado no lee VOICE_NUMBER_INBOUND_CAPABLE (la declaración «Outbound Only» de B8-g/S8-d): desplegar ese código antes; escribirla ahora no tendría efecto"
          [ "$(cuenta_var "$k")" -le 1 ] || niega "$k aparece repetida en el fichero de entorno (gana la última): deduplicar antes"
          A+=("$a")
        done
        CAMBIOS=$(json_de "${A[@]}")
        # El número de voz nunca es uno de WhatsApp (D8-5, protección 6): la guarda pura del código, con el entorno.
        NUEVO_FROM=$(printf '%s' "$CAMBIOS" | python3 -I -c 'import json, sys; print(json.load(sys.stdin).get("TWILIO_VOICE_FROM", ""))')
        if [ -n "$NUEVO_FROM" ]; then
          CHOCA=$(printf '%s' "$NUEVO_FROM" | sudo -u "$WWW" php -r 'require "vendor/autoload.php"; $n = trim((string) stream_get_contents(STDIN)); $e = Dotenv\Dotenv::createArrayBacked(".")->load(); $prot = $e["WHATSAPP_PROTECTED_NUMBERS"] ?? ""; $vis = $e["WHATSAPP_DISPLAY_PHONE"] ?? ""; echo App\Services\Telephony\VoiceNumberGuard::collides($n, $vis, trim((string) $prot) !== "" ? $prot : $vis) ? "si" : "no";' 2>/dev/null || echo '?')
          [ "$CHOCA" = no ] || niega "TWILIO_VOICE_FROM coincide con el número de WhatsApp o con uno protegido ($CHOCA; protección 6: el número de WhatsApp nunca es de voz)"
        fi
        ;;
      secreto)
        ORDEN=fase2-secreto; [ "$#" = 4 ] || niega "fase 2 secreto <fichero 600 de root con CLAVE=VALOR por línea>"
        SECRETOS_FICHERO=$4
        [ "$OUT $INB" = "false false" ] || niega "las piezas se cambian con las salientes y las entrantes apagadas (hoy: salientes $OUT, entrantes $INB)"
        [ -f "$SECRETOS_FICHERO" ] && [ ! -L "$SECRETOS_FICHERO" ] || niega "$SECRETOS_FICHERO no es un fichero normal"
        [ "$(stat -c '%U %a' "$SECRETOS_FICHERO")" = "$ROOT_U 600" ] || niega "$SECRETOS_FICHERO tiene que ser de root y 600"
        # Solo las claves secretas de Twilio, cada una con su forma; el par de la key va junto. Nada se imprime salvo nombres.
        CAMBIOS=$(python3 -I -c '
import json, re, sys
formas = {"TWILIO_VOICE_API_KEY_SID": r"SK[0-9a-f]{32}", "TWILIO_VOICE_API_KEY_SECRET": r"[A-Za-z0-9]{32}", "TWILIO_VOICE_SIGNING_TOKEN": r"[0-9a-f]{32}"}
out = {}
for n, l in enumerate(open(sys.argv[1], encoding="utf-8"), 1):
    l = l.strip()
    if not l or l.startswith("#"):
        continue
    k, sep, v = l.partition("=")
    k, v = k.strip(), v.strip()
    if not sep or k not in formas:
        sys.exit("línea %d: solo %s" % (n, ", ".join(formas)))
    if not re.fullmatch(formas[k], v):
        sys.exit("línea %d: %s no tiene la forma esperada (no se imprime)" % (n, k))
    if k in out:
        sys.exit("línea %d: %s repetida" % (n, k))
    out[k] = v
if not out:
    sys.exit("el fichero no trae ninguna clave")
if ("TWILIO_VOICE_API_KEY_SID" in out) != ("TWILIO_VOICE_API_KEY_SECRET" in out):
    sys.exit("TWILIO_VOICE_API_KEY_SID y TWILIO_VOICE_API_KEY_SECRET van juntas (son un par)")
print(json.dumps(out, sort_keys=True))' "$SECRETOS_FICHERO" 2>&1) || niega "$CAMBIOS"
        for k in $SECRETAS; do [ "$(cuenta_var "$k")" -le 1 ] || niega "$k aparece repetida en el fichero de entorno: deduplicar antes"; done
        ;;
      *) uso; exit 2 ;;
    esac ;;
  "salientes on"|"entrantes on")
    QUE=$1; ORDEN=$1-on; shift 2
    CUPO=""; PRES=""
    for a in "$@"; do
      case "$a" in
        --cupo=*) CUPO=${a#*=} ;;
        --presupuesto=*) [ "$QUE" = salientes ] || niega "--presupuesto es de las salientes"; PRES=${a#*=} ;;
        *) niega "argumento desconocido: $a" ;;
      esac
    done
    [ "$CONST" = abierta ] || niega "la constante VoiceCallPolicy::CALLS_ENABLED_IN_CODE está $CONST en el código desplegado: las fases 3 y 4a exigen el commit de una línea \`voice(calls-open): …\` con la autorización escrita del propietario (protección 2), desplegado antes de este mando"
    if [ "$QUE" = salientes ]; then
      es_entero "$CUPO" 1 5 || niega "--cupo de salientes al día, de 1 a 5 (fase 4a: la primera, 1)"
      es_usd "$PRES" 0.01 20.00 || niega "--presupuesto en US$ al día, de 0.01 a 20.00"
      [ "$(worker_voz)" -ge 1 ] || niega "el worker ironbody-voice no está en RUNNING: sin él ninguna saliente sale de la cola voice (instalarlo antes: docs/ops/supervisor/ironbody-voice.conf, con autorización; plan, fase 4a)"
      FALTAN=$(printf '%s' "$ANTES" | python3 -I -c 'import json, sys; d = json.load(sys.stdin); print(" ".join(n for n, k in [("TWILIO_VOICE_FROM", "from_last4"), ("ELEVENLABS_AGENT_PHONE_NUMBER_ID", "phone_number_id"), ("TWILIO_VOICE_ACCOUNT_SID", "account_last4"), ("TWILIO_VOICE_API_KEY_SID", "s_twilio_key_sid"), ("TWILIO_VOICE_API_KEY_SECRET", "s_twilio_key_secret"), ("VOICE_WEBHOOK_BASE_URL", "webhook_base")] if not d.get(k)))')
      [ -z "$FALTAN" ] || niega "faltan piezas para las salientes (sin la key de la subcuenta no hay corte de emergencia, nivel 0b): $FALTAN"
      [ "$(campo whatsapp_collision)" = false ] || niega "el número de voz coincide con uno de WhatsApp (protección 6)"
      RP=$(reserva_puerta "$PRES")
      case "$RP" in
        '{"movil":'*) ;;
        cambiada:*) niega "la reserva de la puerta ya no es la que conoce este mando (${RP#cambiada: }): sin saber cuánto reserva OutboundGate por saliente no se enciende (C-1: revisar reserva_puerta en el mando contra el código desplegado)" ;;
        *) niega "no se pudo leer la reserva de la puerta (tinker como $WWW; su salida no se imprime): sin ella no se enciende" ;;
      esac
      CUBRE=$(printf '%s' "$RP" | python3 -I -c '
import json, sys
d = json.load(sys.stdin)
u = lambda v: "%.4f" % (v / 1e6)
minimo = -(-d["peor"] // 10000) / 100   # la reserva del peor caso, redondeada hacia arriba al centavo (la forma de --presupuesto)
print("%s|reserva de la puerta por saliente (OutboundGate → VoiceCostGuard, %d s con un tramo de transferencia a fijo, SIEMPRE; precios del %s): %s US$ a un móvil colombiano (+573…) · %s US$ a cualquier otro destino · peor caso %s US$ (presupuesto mínimo: %.2f)" % ("si" if d["cabe"] is True else "no", d["segundos"], d["precios"], u(d["movil"]), u(d["otro"]), u(d["peor"]), minimo))' 2>/dev/null || echo 'no|?')
      [ "${CUBRE%%|*}" = si ] || niega "el presupuesto ($PRES US$) no cubre la reserva de UNA saliente en el peor caso: ${CUBRE#*|}. Con menos, la puerta deniega con budget_exhausted aunque este mando lo encendiera (C-1)"
      echo "   ${CUBRE#*|}; el presupuesto ($PRES US$) la cubre"
      CAMBIOS=$(json_de VOICE_OUTBOUND_ENABLED=true TWILIO_VOICE_ENABLED=true VOICE_OUTBOUND_SCOPE=canary "TWILIO_VOICE_DAILY_CALL_CAP=$CUPO" "VOICE_DAILY_BUDGET_USD=$PRES")
    else
      [ -z "$PRES" ] || niega "--presupuesto es de las salientes"
      es_entero "$CUPO" 1 20 || niega "--cupo de entrantes al día, de 1 a 20 (P-5)"
      [ "$(campo number_inbound_capable)" != false ] || niega "el número está declarado «Outbound Only» (VOICE_NUMBER_INBOUND_CAPABLE=false: un caller ID verificado no recibe llamadas por Twilio); la fase 3 exige un número comprado o portado"
      CAMBIOS=$(json_de VOICE_INBOUND_ENABLED=true TWILIO_VOICE_ENABLED=true "VOICE_INBOUND_DAILY_CAP=$CUPO")
    fi
    VUELTA_FUNCIONAL="bash $0 $QUE off" ;;
  "salientes off")
    ORDEN=salientes-off; [ "$#" = 2 ] || { uso; exit 2; }
    # Nivel 0 de D8-20, con los cerrojos de gasto también cerrados. TWILIO_VOICE_ENABLED solo baja si las entrantes están
    # apagadas: las entrantes no la leen, pero el Studio clasifica con ella (connected_voice).
    A=(VOICE_OUTBOUND_ENABLED=false TWILIO_VOICE_DAILY_CALL_CAP=0 VOICE_DAILY_BUDGET_USD=0)
    [ "$INB" = true ] || A+=(TWILIO_VOICE_ENABLED=false)
    CAMBIOS=$(json_de "${A[@]}") ;;
  "entrantes off")
    ORDEN=entrantes-off; [ "$#" = 2 ] || { uso; exit 2; }
    A=(VOICE_INBOUND_ENABLED=false VOICE_INBOUND_DAILY_CAP=0)
    [ "$OUT" = true ] || A+=(TWILIO_VOICE_ENABLED=false)
    CAMBIOS=$(json_de "${A[@]}") ;;
  "monitor on"|"monitor off")
    ORDEN=monitor-$2; V=$([ "$2" = on ] && echo true || echo false)
    for a in "${@:3}"; do case "$a" in --acepta-freno) [ "$2" = on ] || niega "--acepta-freno solo con «monitor on»"; ACEPTA_FRENO=1 ;; *) niega "argumento desconocido: $a" ;; esac; done
    CAMBIOS=$(json_de "VOICE_MONITOR_ENABLED=$V")
    TV=$(tareas_voz); [ "$2" = on ] && PROG_DELTA=$TV || PROG_DELTA=-$TV
    [ "$2" = off ] || VUELTA_FUNCIONAL="bash $0 monitor off" ;;
  "publicar on"|"publicar off")
    ORDEN=publicar-$2; [ "$#" = 2 ] || { uso; exit 2; }
    CAMBIOS=$(json_de "ULTRON_STUDIO_VOICE_PUBLISHING_ENABLED=$([ "$2" = on ] && echo true || echo false)")
    [ "$2" = off ] || VUELTA_FUNCIONAL="bash $0 publicar off" ;;
  "expectativa fase1"|"expectativa fase2"|"expectativa llamadas")
    ORDEN=expectativa-$2; [ "$#" = 2 ] || { uso; exit 2; }
    case "$2" in
      fase1) EXP=twilio:connected_otp_only,twilio_voice:not_configured,elevenlabs:ELEVENLABS_VERIFIED ;;
      fase2) EXP=twilio:connected_otp_only,twilio_voice:disabled,elevenlabs:ELEVENLABS_VERIFIED ;;
      llamadas) EXP=twilio:connected_otp_only,twilio_voice:connected_voice,elevenlabs:ELEVENLABS_VERIFIED ;;
    esac
    sudo -u "$WWW" php artisan ultron-studio:integrations-doctor --expect="$EXP" > /dev/null 2>&1 \
      || niega "integrations-doctor no confirma «$EXP» con el estado de hoy: con el monitor del H7 encendido abriría un incidente (ejecutarlo a mano como www-data para ver qué difiere)"
    CAMBIOS=$(json_de "ULTRON_STUDIO_MONITOR_EXPECT=$EXP") ;;
  "contactos on"|"contactos off")
    # B-T4 (OPS8-e): la bandera de h8-hardening (config/voice.php, `voice.contacts.enabled`). Si el código desplegado no la
    # lee, escribirla no tendría efecto: se niega (también «off»; sin la bandera, el alta no tiene cerrojo en el código).
    ORDEN=contactos-$2; [ "$#" = 2 ] || { uso; exit 2; }
    case "$(campo contacts)" in true|false) ;; *) niega "el código desplegado no lee VOICE_CONTACTS_ENABLED (B-T4): desplegar antes ese código; escribirla ahora no tendría efecto" ;; esac
    CAMBIOS=$(json_de "VOICE_CONTACTS_ENABLED=$([ "$2" = on ] && echo true || echo false)")
    [ "$2" = off ] || VUELTA_FUNCIONAL="bash $0 contactos off" ;;
  *) uso; exit 2 ;;
esac
[ -n "$CAMBIOS" ] || aborta "no se calcularon los cambios"

# ── Comprobaciones comunes de solo lectura (si algo falla, no se toca nada) ──────────────────────────────────────────
fichero_ok || aborta "el fichero de entorno no es un fichero normal de $WWW en 600 ni de $ROOT_U:$WWW en 640 (es $(stat -c '%U:%G %a' "$ENVF" 2>/dev/null || echo '?')): corregirlo a mano antes (C-14, N-4)"
exec 9> "$CERROJO"
flock -n 9 || aborta "otro mando de la voz está en curso ($CERROJO)"
for k in $(printf '%s' "$CAMBIOS" | python3 -I -c 'import json, sys; print(" ".join(json.load(sys.stdin)))'); do
  [ "$(cuenta_var "$k")" -le 1 ] || aborta "$k aparece repetida en el fichero de entorno (gana la última): deduplicar antes"
done
YA=$(printf '%s' "$CAMBIOS" | ENVF="$ENVF" python3 -I -c '
import json, os, re, sys
c = json.load(sys.stdin)
s = open(os.environ["ENVF"], encoding="utf-8").read()
def valor(k):
    m = re.search(r"^[ \t]*(?:export[ \t]+)?" + re.escape(k) + r"[ \t]*=[ \t]*(.*?)[ \t]*$", s, re.M)
    return None if m is None else m.group(1).strip("\"")
print("si" if all(valor(k) == v for k, v in c.items()) else "no")')
RESTO_ANTES=$(leer_resto)
[ -n "$RESTO_ANTES" ] || aborta "no se pudo leer la configuración del motor y del H4–H7 (tinker falló; su salida no se imprime)"
PROG0=$(programador)
case "$PROG0" in "FALLO "*|ILEGIBLE) aborta "schedule:list no se puede leer ($PROG0): sin el programador no se toca nada (m-1 del H7)" ;; esac
read -r TOTAL0 STUDIO0 VOZ0 <<< "$PROG0"
W0=$(workers 999 0); RUN0=${W0%% *}
[ "${W0#* }" = 0 ] || aborta "workers con BACKOFF o FATAL antes de empezar ($W0): no se toca nada sobre un sistema degradado"
case "$RUN0" in 13|14) ;; *) aborta "workers RUNNING antes de empezar: $RUN0 (13 sin ironbody-voice, 14 con él)" ;; esac
if [ "$ORDEN" = monitor-on ]; then
  # La primera pasada de la conciliación, en seco y ANTES de encenderla: cada llamada de la cuenta de Twilio de las últimas
  # 48 h sin su fila en voice_calls es unknown_provider_call y echa el freno (D8-19). Lecturas a los proveedores; no escribe.
  RC=0; R=$(sudo -u "$WWW" php artisan voice:reconcile --dry-run --numbers --json 2>/dev/null) || RC=$?
  RES=$(printf '%s' "$R" | python3 -I -c '
import json, sys
try:
    d = json.load(sys.stdin)
    n = d.get("number") or {}
    print("desconocidas=%s conocidas=%s ambiguas_sin_job=%s atascadas=%s techo=%s numero=%s haria_freno=%s causas=%s" % (d.get("unknown"), d.get("known"), d.get("staleRequested"), d.get("stuckDialing"), "SUPERADO" if d.get("costCap") else "dentro", n.get("result", "—"), "SÍ" if d.get("wouldOpenBrake") else "no", ",".join(sorted({str(b.get("cause")) for b in (d.get("would") or {}).get("brakes", [])})) or "—"))
except Exception:
    print("?")' 2>/dev/null || echo '?')
  echo "   conciliación en seco (voice:reconcile --dry-run --numbers, código $RC): $RES"
  case "$RES" in
    *"haria_freno=no"*) ;;
    "?") [ "$ACEPTA_FRENO" = 1 ] || niega "la conciliación en seco no devolvió un resumen legible (código $RC): sin saber si echaría el freno no se enciende (repetir con --acepta-freno solo si se entiende por qué)" ;;
    *) [ "$ACEPTA_FRENO" = 1 ] || niega "la primera pasada echaría el freno ($RES). Cada llamada de la cuenta sin su fila (p. ej. una prueba desde la consola de ElevenLabs) es unknown_provider_call: esperar a que salga de la ventana de 48 h, o encender con --acepta-freno sabiendo que una persona con ultron.voice_publish tendrá que soltarlo con la nota de la causa" ;;
  esac
fi
if [ "$YA" = si ]; then
  echo "== $ORDEN: el fichero de entorno ya tiene esos valores (no se imprimen); nada que escribir"
  echo "   configuración efectiva: $(telefonia)"
  exit 0
fi

# ── Copia previa y escritura ───────────────────────────────────────────────────────────────────────────────────────
DUENO=$(stat -c '%U:%G' "$ENVF"); MODO=$(stat -c '%a' "$ENVF")
TS=$(date -u +%Y%m%d-%H%M%S)
COPIA="$COPIAS/env-pre-voice-$ORDEN-$TS"
[ ! -e "$COPIA" ] || aborta "ya existe $COPIA: nunca se sobrescribe una copia (esperar un segundo y repetir)"
install -m 600 -o "$ROOT_U" -g "$ROOT_G" "$ENVF" "$COPIA"
VUELTA="install -m $MODO -o ${DUENO%%:*} -g ${DUENO##*:} $COPIA $ENVF && cd $APPDIR && sudo -u $WWW sh -c 'umask 077; php artisan config:cache -q' && sudo -u $WWW chmod 600 bootstrap/cache/config.php && sudo -u $WWW php artisan queue:restart -q"
ERR0=$(errores_log)
echo "0. $ORDEN · copia previa: $COPIA (600, $ROOT_U) · fichero $DUENO $MODO · programador $PROG0 · workers $W0 · constante $CONST"
if [ -n "$SECRETOS_FICHERO" ]; then
  printf '%s' "$CAMBIOS" | escribir || aborta "no se pudo escribir el fichero de entorno (no se imprime nada). Vuelta: $VUELTA"
  unset CAMBIOS
  CLAVES_TOCADAS=$(python3 -I -c '
import re, sys
print(" ".join(l.split("=", 1)[0].strip() for l in open(sys.argv[1], encoding="utf-8") if l.strip() and not l.strip().startswith("#")))' "$SECRETOS_FICHERO")
else
  printf '%s' "$CAMBIOS" | escribir || aborta "no se pudo escribir el fichero de entorno. Vuelta: $VUELTA"
  CLAVES_TOCADAS=$(printf '%s' "$CAMBIOS" | python3 -I -c 'import json, sys; print(" ".join(json.load(sys.stdin)))')
fi
[ "$(stat -c '%U:%G %a' "$ENVF")" = "$DUENO $MODO" ] || aborta "el dueño o el modo del fichero de entorno cambiaron al escribir. Vuelta: $VUELTA"
echo "1. claves escritas (sin valores): $CLAVES_TOCADAS · fichero $(stat -c '%U:%G %a' "$ENVF")"
# B-1 del H5: el fichero tiene que parsear ANTES de tocar la caché (config:cache la vacía primero; si Laravel no arranca,
# la API cae).
parsea || aborta "el fichero de entorno NO parsea tras escribir. Vuelta: $VUELTA"
cachea || aborta "config:cache falló o la caché no quedó de $WWW en 600 (su salida no se imprime). Vuelta: $VUELTA"
echo "2. el fichero parsea · caché: $(stat -c '%U %a' bootstrap/cache/config.php)"

# ── Comprobaciones posteriores ─────────────────────────────────────────────────────────────────────────────────────
DESPUES=$(leer_voz)
[ -n "$DESPUES" ] || { echo "ABORTA: la configuración efectiva no se puede leer tras el cambio (tinker; su salida no se imprime). Vuelta: $VUELTA"; exit 1; }
# Lo que tiene que cambiar, campo a campo, y lo que no. Los secretos solo pasan de «falta» a «presente».
COMP=$(ANTES="$ANTES" DESPUES="$DESPUES" CLAVES="$CLAVES_TOCADAS" CAMBIOS="${CAMBIOS:-}" python3 -I -c '
import json, os
a = json.loads(os.environ["ANTES"]); b = json.loads(os.environ["DESPUES"])
c = json.loads(os.environ["CAMBIOS"]) if os.environ["CAMBIOS"] else {k: None for k in os.environ["CLAVES"].split()}
booleano = lambda v: v == "true"
mapa = {
    "VOICE_INBOUND_ENABLED": ("inbound", booleano), "VOICE_INBOUND_DAILY_CAP": ("inbound_cap", int),
    "VOICE_OUTBOUND_ENABLED": ("outbound", booleano), "VOICE_OUTBOUND_SCOPE": ("outbound_scope", str),
    "TWILIO_VOICE_DAILY_CALL_CAP": ("outbound_cap", int), "VOICE_DAILY_BUDGET_USD": ("budget", str),
    "TWILIO_VOICE_ENABLED": ("twilio_voice", booleano), "VOICE_LAB_ENABLED": ("lab", booleano),
    "VOICE_LAB_DRIVER": ("lab_driver", str), "VOICE_LAB_DAILY_BUDGET_USD": ("lab_budget", str),
    "VOICE_LAB_SESSION_BUDGET_USD": ("lab_session_budget", str), "VOICE_LAB_MAX_SESSION_SECONDS": ("lab_max_seconds", int),
    "VOICE_LAB_MAX_SESSIONS_PER_DAY": ("lab_sessions", int), "VOICE_MONITOR_ENABLED": ("monitor", booleano),
    "ULTRON_STUDIO_VOICE_PUBLISHING_ENABLED": ("publishing", booleano), "VOICE_WEBHOOK_BASE_URL": ("webhook_base", str),
    "ELEVENLABS_AGENT_PHONE_NUMBER_ID": ("phone_number_id", str), "TWILIO_VOICE_FROM": ("from_last4", lambda v: v[-4:]),
    "TWILIO_VOICE_ACCOUNT_SID": ("account_last4", lambda v: v[-4:]), "ELEVENLABS_TOOL_SECRET_REF": ("tool_ref", str),
    "ELEVENLABS_INITIATION_SECRET_REF": ("initiation_ref", str), "ELEVENLABS_POST_CALL_WEBHOOK_REF": ("post_call_ref", str),
    "TWILIO_VOICE_API_KEY_SID": ("s_twilio_key_sid", lambda v: True), "TWILIO_VOICE_API_KEY_SECRET": ("s_twilio_key_secret", lambda v: True),
    "TWILIO_VOICE_SIGNING_TOKEN": ("s_signing", lambda v: True), "ULTRON_STUDIO_MONITOR_EXPECT": ("expect", str),
    "VOICE_NUMBER_INBOUND_CAPABLE": ("number_inbound_capable", booleano), "VOICE_CONTACTS_ENABLED": ("contacts", booleano),
}
esperado = {}
for k, v in c.items():
    if k not in mapa:
        print("FALLO clave sin lectura: " + k); raise SystemExit
    campo, conv = mapa[k]
    esperado[campo] = conv(v) if v is not None else True
# Derivados que pueden cambiar con lo pedido sin ser una clave: la colisión con WhatsApp (el número) y la reserva no.
derivados = {"whatsapp_collision"} if "TWILIO_VOICE_FROM" in c else set()
mal = []; cambios = []
for k in sorted(set(a) | set(b)):
    if k in esperado:
        if b.get(k) != esperado[k]:
            mal.append("%s=%s (esperado %s)" % (k, json.dumps(b.get(k)), json.dumps(esperado[k])))
        if a.get(k) != b.get(k):
            cambios.append("%s: %s → %s" % (k, json.dumps(a.get(k)), json.dumps(b.get(k))))
    elif a.get(k) != b.get(k) and k not in derivados:
        mal.append("%s cambió sin pedirlo: %s → %s" % (k, json.dumps(a.get(k)), json.dumps(b.get(k))))
    elif a.get(k) != b.get(k):
        cambios.append("%s: %s → %s" % (k, json.dumps(a.get(k)), json.dumps(b.get(k))))
print(("FALLO " + " · ".join(mal)) if mal else ("OK " + (" · ".join(cambios) if cambios else "sin cambios visibles (los valores ya eran esos)")))' 2>&1)
case "$COMP" in
  OK*) echo "3. configuración efectiva, antes → después (sin valores de secretos): ${COMP#OK }" ;;
  *) echo "ABORTA: la configuración efectiva no es la pedida: ${COMP#FALLO }"; echo "   vuelta: $VUELTA"; exit 1 ;;
esac
[ "$(printf '%s' "$DESPUES" | grep -oE '"whatsapp_collision":(true|false)')" = '"whatsapp_collision":false' ] || desvio "tras el cambio, el número de voz coincide con uno de WhatsApp (la política deniega las salientes por nombre)"
RESTO=$(leer_resto)
[ "$RESTO" = "$RESTO_ANTES" ] && echo "   motor, H4–H7 y ElevenLabs (proveedor y Studio) sin cambios" || desvio "la configuración del motor, del H4–H7 o de ElevenLabs cambió: antes $RESTO_ANTES · después ${RESTO:-ilegible}"
# El fichero es la copia previa salvo las claves de la orden (sin imprimir nada de él).
if CLAVES="$CLAVES_TOCADAS" python3 -I -c '
import os, re, sys
ks = os.environ["CLAVES"].split()
pat = re.compile(r"^[ \t]*(?:export[ \t]+)?(" + "|".join(map(re.escape, ks)) + r")[ \t]*=")
f = lambda p: [l for l in open(p, encoding="utf-8").read().splitlines() if l.strip() and not pat.match(l)]
sys.exit(0 if f(sys.argv[1]) == f(sys.argv[2]) else 1)' "$ENVF" "$COPIA"; then
  echo "   el fichero de entorno es la copia previa salvo $(echo $CLAVES_TOCADAS | wc -w | tr -d ' ') clave(s) de la orden"
else
  desvio "el fichero de entorno difiere de la copia previa en algo más que las claves de la orden (comparar a mano sin imprimir valores)"
fi
PROG=$(programador)
case "$PROG" in
  "FALLO "*|ILEGIBLE) desvio "schedule:list no se puede leer tras el cambio ($PROG)" ;;
  *) ESP="$((TOTAL0 + PROG_DELTA)) $STUDIO0 $((VOZ0 + PROG_DELTA))"
     echo "4. programador (total · del Studio · de voz): $PROG0 → $PROG (esperado $ESP)"
     [ "$PROG" = "$ESP" ] || desvio "schedule:list da [$PROG] y se esperaba [$ESP]" ;;
esac
sudo -u "$WWW" php artisan queue:restart -q || desvio "queue:restart no salió con 0"
sleep 6
W1=$(workers "$RUN0" 45); sleep 8; W2=$(workers "$RUN0" 45)
echo "5. workers (RUNNING · BACKOFF/FATAL), dos lecturas: [$W1] y [$W2] (esperado $RUN0 0 las dos veces)"
[ "$W1" = "$RUN0 0" ] && [ "$W2" = "$RUN0 0" ] || desvio "workers [$W1] y [$W2] (esperado $RUN0 0)"
c=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$API/admin/voice/status" || true)
echo "6. admin/voice/status sin token → $c"; [ "$c" = 401 ] || desvio "admin/voice/status sin token respondió $c (esperado 401)"
ERR=$(errores_log)
echo "7. $NIVEL_ERROR en laravel*.log: $ERR0 → $ERR"; [ "$ERR" = "$ERR0" ] || desvio "$((ERR - ERR0)) ERROR nuevos en el log durante el cambio"
echo "   voice:doctor --telefonia (sin red): $(telefonia)"

# ── Avisos de la orden (informativos: no cambian el veredicto) ──────────────────────────────────────────────────────
case "$ORDEN" in
  fase2-set|fase2-secreto)
    echo "   AVISO: la huella de voz del Studio cambió con las piezas: reconocer otra vez la configuración autorizada (CRM → Studio → Integraciones → Voz, o GET/POST integrations/config) antes de seguir; si el monitor del H7 está encendido, su expectativa pasa a ser la de la fase 2 (bash $0 expectativa fase2)" ;;
  salientes-off)
    echo "   AVISO (H-25, OPS8-d): salientes on/off cambian la huella de voz del Studio (twilioVoice.enabled y dailyCallCap): reconocer otra vez la configuración autorizada (CRM → Studio → Integraciones → Voz) o, con el monitor del H7 encendido, saltará config_drift:voice"
    ;;
  salientes-on)
    echo "   AVISO (H-25, OPS8-d): salientes on/off cambian la huella de voz del Studio (twilioVoice.enabled y dailyCallCap): reconocer otra vez la configuración autorizada (CRM → Studio → Integraciones → Voz) o, con el monitor del H7 encendido, saltará config_drift:voice"
    echo "   AVISO: la puerta deniega igualmente sin una publicación de voz en canary, con el freno echado, sin canarios de voz activos o con ElevenLabs sin verificar en la telefonía (E4-6); la primera saliente, a un canario y desde el CRM («Voz y telefonía»)"
    [ "$(campo monitor)" = true ] || echo "   AVISO: el monitor de voz está apagado: sin conciliación no se ven las ambiguas ni las llamadas ajenas (bash $0 monitor on)"
    case "$(campo number_decl_known) $(campo number_inbound_capable)" in
      "true false") ;;
      "true "*) echo "   AVISO: si el número es «Outbound Only» (caller ID verificado; h8-twilio-check.sh lo dice), declararlo ANTES de publicar: bash $0 fase 2 set VOICE_NUMBER_INBOUND_CAPABLE=false (sin la declaración, publicar intentaría asignarle el agente y la deriva del número lo vería)" ;;
      *) echo "   AVISO: el código desplegado no lee VOICE_NUMBER_INBOUND_CAPABLE: con un número «Outbound Only», publicar intentaría asignarle el agente y la deriva del número (voice:reconcile --numbers) echaría el freno (runbook 4a-primero: desplegar antes el código de B8-g/S8-d)" ;;
    esac ;;
  entrantes-on)
    echo "   AVISO: un número «Outbound Only» (Verified Caller ID) no recibe llamadas: comprobarlo antes con h8-twilio-check.sh" ;;
  monitor-on)
    echo "   AVISO: la primera pasada corre en el siguiente minuto 10/20/30/40/50 (la del número, a la hora en punto): mirar entonces voice:reconcile --dry-run --json y el freno" ;;
  contactos-on)
    echo "   AVISO (B-T4): es de la fase 4b: el visto bueno legal (D-12) y la supresión de la Ley 1581 que cubra las tablas de voz (B-T3) van ANTES; llamar a un contacto exige además VOICE_OUTBOUND_SCOPE=contacts, una publicación promovida a rest y el RNE (P-14). Alta y consentimiento siguen a cuatro ojos" ;;
  contactos-off)
    echo "   AVISO (B-T4): alta, carga y confirmación del consentimiento responden 409 voice_contacts_disabled; leer, revocar, excluir y el RNE siguen (solo restringen)" ;;
esac
echo "   vuelta atrás exacta (el fichero de antes): $VUELTA"
[ -z "$VUELTA_FUNCIONAL" ] || echo "   vuelta atrás funcional: $VUELTA_FUNCIONAL"
if [ -n "$SECRETOS_FICHERO" ] && [ "$DESVIOS" = 0 ]; then
  shred -u "$SECRETOS_FICHERO" 2>/dev/null || rm -f -- "$SECRETOS_FICHERO"
  echo "   $SECRETOS_FICHERO destruido (los secretos solo quedan en el fichero de entorno y en la copia previa, las dos 600)"
fi
if [ "$DESVIOS" = 0 ]; then
  echo "MANDO $ORDEN APLICADO (copia previa: $COPIA)"
else
  echo "MANDO $ORDEN ESCRITO CON $DESVIOS DESVÍOS: no seguir hasta aclararlos (copia previa: $COPIA; vuelta: $VUELTA)"
  exit 1
fi

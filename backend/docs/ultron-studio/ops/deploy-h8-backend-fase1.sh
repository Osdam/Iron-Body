#!/bin/bash
# Fase 1 del Hito 8 (voz y telefonía) en producción: el CÓDIGO y las MIGRACIONES con TODO APAGADO. Contrato
# ULTRON_STUDIO_H8_CONTRACT.md, «Fases de despliegue» (fase 1), protecciones 1–13, D8-19/D8-21, E1-10, E1-12, E2-2, E2-3,
# E2-6, E3-5, E3-7, E4-1, E6-4 y E6-5. Lo que entra: 21 rutas nuevas del Studio (gobierno de la voz y laboratorio
# hablado: 56 → 77), las 17 de la telefonía (13 del CRM en /api/admin/voice y 4 públicas, cerradas por su credencial),
# 2 migraciones (11 tablas vacías con sus guardas) y el bloque de voz de routes/console.php (apagado). Lo que NO cambia:
# VoiceCallPolicy::CALLS_ENABLED_IN_CODE sigue en false; ninguna bandera de voz se enciende; ElevenLabs sigue como lo
# dejó E1-12 (piezas del H6 puestas, ELEVENLABS_ENABLED=true, verificado por el Studio: ELEVENLABS_VERIFIED); Twilio Voice
# sigue sin número ni subcuenta; el OTP, el motor de WhatsApp, Meta y Legacy no se tocan (puertas de diff del paso 0).
# Se ejecuta EN EL SERVIDOR como root, tras el respaldo. Orden:
#   0. comprobaciones de solo lectura (abortan sin tocar nada; con --solo-comprobar se termina aquí)
#   1. código (git como root con umask 022, merge --ff-only) y lectura por www-data de cada fichero del merge
#   2. las 9 variables de la fase 1 (5 interruptores y 4 cerrojos de gasto, apagados) ANTES de migrar, y el fichero de
#      entorno parsea
#   3. ironbody-migrate (las 2 del H8, o lo que falte de ellas); un 55P03 se reintenta hasta 3 veces; si el esquema no
#      da 68/84/6/18 + 27/51/3/7, ABORTA SIN route:cache (las rutas nuevas no se sirven con tablas sin guardas). Si las
#      dos ya estaban aplicadas por otro, no migra: lo dice y comprueba el esquema
#   4. aserciones del esquema (E3-5: recuentos, 4 funciones INVOKER del dueño, ninguna guarda apagada, F intacta,
#      dueños, privilegios de iron_app uno a uno, 0 filas, las tablas del H7 iguales)
#   5. config:cache y route:cache como www-data con umask 077, cachés www-data 600 y queue:restart
#   6. rutas (Studio 77 / 18 / 8 / 10, voz 15, laboratorio 6, telefonía 13 = 5 GET + 8 POST, públicas 4) y cobertura
#   7. configuración efectiva: TODA la voz apagada, la constante cerrada, el motor/H4/H5/H6/H7 iguales, el programador
#      con el mismo total y 0 tareas de voz · 8. workers 13/0 dos veces (la fase 1 NO instala ironbody-voice)
#   9. sanidad HTTP (401 sin credencial en las rutas nuevas, no-store, 413 temprano, OTP 403), colas, secretos,
#      publication-check, el monitor en seco sin deriva nueva, el entorno = respaldo salvo las 9 claves
#  10. voice:doctor --telefonia (sin red) · 11. integrations-doctor (ELEVENLABS_VERIFIED, E1-12) e integrations-snapshot
#      sin cambios (S8-b 9: la fase 1 no cambia la huella de voz) · 12. ERROR nuevos en el log
# Cada aserción que falla se dice y el script termina con 1; «DESPLIEGUE H8 FASE 1 (BACKEND) COMPLETADO» solo sale con 0
# desvíos. Nunca imprime valores del fichero de entorno, ni secretos, ni la salida de un tinker que no case con lo
# esperado. Todo lo que imprime queda además en /root/h8-fase1-<fecha UTC>.log (600, root).
# Uso: bash /root/deploy-h8-backend-fase1.sh <commit esperado (7 cifras)> <directorio del respaldo de hoy> [--solo-comprobar] [--acepta-main=<commit>[,<commit>…]]
#   --solo-comprobar: ejecuta solo el paso 0 (lecturas y git fetch) y sale; es el ensayo en el servidor.
#   --acepta-main=…: los commits AJENOS al H8 (de origin/main) que producción no tiene y que viajarían con el merge, que
#     el propietario acepta desplegar con el H8 EN ESE MOMENTO (decisión del lead del 08-11-2026; hoy, 7c84cb5). Sin la
#     bandera, el paso 0 los lista y el despliegue aborta; un commit aceptado que no esté entre ellos también aborta.
# VUELTA ATRÁS (ULTRON_STUDIO_DEPLOYMENT_PLAN.md, «Hito 8», y ULTRON_STUDIO_ROLLBACK.md): la fase 1 no enciende nada,
# así que no hay bandera que bajar. Código: `git checkout -q --detach $(cat $BK/api-commit.txt)` como root con umask 022
# (solo si producción sigue en el commit del H8), config:cache y route:cache como www-data, queue:restart. Las 11 tablas
# QUEDAN (vacías y sin lector; D8-20, nivel 4): NUNCA `migrate:rollback` en producción; quitarlas es de
# database-specialist con autorización del propietario. Volver el código no es volver los datos.
set -euo pipefail
export LC_ALL=C
umask 022
g() { git "$@"; }
uso() { echo "uso: bash /root/deploy-h8-backend-fase1.sh <commit esperado (7 cifras)> <directorio del respaldo de hoy> [--solo-comprobar] [--acepta-main=<commit>[,<commit>…]]"; }
[ "$#" -ge 2 ] && [ "$#" -le 4 ] || { uso; echo "no se ha tocado nada"; exit 2; }
ESPERADO="$1"
BK="$2"
shift 2
SOLO_COMPROBAR=""
ACEPTA=""
for a in "$@"; do
  case "$a" in
    --solo-comprobar) [ -z "$SOLO_COMPROBAR" ] || { echo "--solo-comprobar repetido; no se ha tocado nada"; exit 2; }; SOLO_COMPROBAR=--solo-comprobar ;;
    --acepta-main=*) [ -z "$ACEPTA" ] || { echo "--acepta-main repetido; no se ha tocado nada"; exit 2; }
                     ACEPTA=${a#--acepta-main=}
                     [[ "$ACEPTA" =~ ^[0-9a-f]{7}(,[0-9a-f]{7})*$ ]] || { echo "--acepta-main va con commits de 7 cifras separados por comas (los que imprime el paso 0); no se ha tocado nada"; exit 2; } ;;
    *) echo "argumento desconocido: $a (se admiten --solo-comprobar y --acepta-main=<commit>[,…]); no se ha tocado nada"; exit 2 ;;
  esac
done
[[ "$ESPERADO" =~ ^[0-9a-f]{7}$ ]] || { echo "el commit esperado va con 7 cifras hexadecimales (git rev-parse --short=7 origin/main); no se ha tocado nada"; exit 2; }
[ "$(id -u)" = 0 ] || { echo "se ejecuta como root en el servidor; no se ha tocado nada"; exit 2; }
# PREVIO = lo que sirve producción ANTES del H8. Leído en el servidor el 08-11-2026 por devops con la autorización del
# lead (solo lectura: `git rev-parse HEAD` y `migrate:status | tail`): 32a2560 («El barrido dice de quién es cada
# etiqueta»), con la migración ajena 2026_11_06_000001_add_benefit_only_to_plans_table YA aplicada (lote 133). El
# contrato partió de cfcd0d1; lo ajeno de planes y beneficios ya está en producción salvo 7c84cb5 (ver AJENOS_ACEPTADOS).
# Si producción se mueve antes del despliegue, el paso 0 aborta («producción no está en …»): se vuelve a leer, se revisa
# qué entró y se fija aquí en otro commit. Con «pendiente», --solo-comprobar lee el HEAD y lo imprime, y el despliegue se
# niega.
PREVIO=32a2560
# Los commits AJENOS al H8 entre PREVIO y origin/main que producción todavía no tiene se comparan por su PARCHE, no por
# su hash (una reescritura de la rama puede dejar copias con otro hash de commits que ya están en producción, y esas no
# cuentan). Hoy queda uno: 7c84cb5 («Un cobro que no da vigencia no es la membresía de nadie»: MembershipRebuilder y su
# prueba, sin migración, fuera de la puerta). Solo viaja con --acepta-main=7c84cb5, que decide el propietario al desplegar.
[[ "$PREVIO" =~ ^([0-9a-f]{7}|pendiente)$ ]] || { echo "PREVIO no es un commit de 7 cifras ni «pendiente» ($PREVIO); no se ha tocado nada"; exit 2; }
[ "$PREVIO" != pendiente ] || [ "$SOLO_COMPROBAR" = --solo-comprobar ] \
  || { echo "PREVIO sin fijar: leerlo en el servidor con --solo-comprobar y fijarlo en un commit (ver la cabecera); no se ha tocado nada"; exit 2; }
MIG_H8_1=2026_11_10_100001_create_ultron_studio_voice_tables
MIG_H8_2=2026_11_10_100002_create_voice_tables
MIGRACIONES="backend/database/migrations/$MIG_H8_1.php backend/database/migrations/$MIG_H8_2.php "
# Puerta de diff (protecciones 4 y 5 del contrato H8, más la del H6/H7): el OTP (Sms, Otp, config/otp.php, el controlador
# del aviso de gasto y routes/api.php), el motor (Marketing, los Jobs existentes, la API interna, routes/marketing.php,
# config/marketing.php), Meta, el catálogo congelado y las dependencias. La cruzan SOLO los ficheros nuevos que el
# contrato nombra (los 4 controladores públicos de la voz y el job de la saliente, B8) y routes/console.php (OPS8, que
# solo puede AÑADIR líneas: el bloque de voz). Un fichero de la puerta que no esté aquí ABORTA: si es de otro equipo,
# se despliega antes por su vía (lección del H7: un `migrate` ajeno aplicó también nuestra migración).
# composer.json/lock en la puerta: el H8 no cambia dependencias PHP (git diff cfcd0d1..HEAD vacío); si cambian, no es
# del H8 y este script NO ejecuta composer en producción (supply chain y tiempo): aborta.
PUERTA=(backend/app/Services/Marketing backend/app/Jobs backend/app/Http/Controllers/Api backend/app/Services/Sms
        backend/app/Services/Otp backend/routes/api.php backend/routes/marketing.php backend/routes/console.php
        backend/config/marketing.php backend/config/meta.php backend/config/otp.php
        backend/app/Services/UltronStudio/StudioCatalog.php backend/composer.json backend/composer.lock)
PERMITIDOS_EN_PUERTA="backend/app/Http/Controllers/Api/Voice/ElevenLabsInitiationController.php
  backend/app/Http/Controllers/Api/Voice/ElevenLabsPostCallController.php
  backend/app/Http/Controllers/Api/Voice/TwilioVoiceUsageTriggerController.php
  backend/app/Http/Controllers/Api/Voice/VoiceToolController.php
  backend/app/Jobs/Voice/PlaceOutboundVoiceCall.php
  backend/routes/console.php"
PERMITIDOS_EN_PUERTA=$(printf '%s\n' $PERMITIDOS_EN_PUERTA | tr '\n' ' ')
HUELLA_F=4ad748b5f17f09b398f7f641c197d7ee        # md5(pg_get_functiondef) de ultron_studio_forbid(): la de F (H5–H8)
MIN_TABLAS=227                                     # 226 del H7 + receivable_payment_splits (ajena, 03-11-2026)
API=https://api.ironbodyneiva.cloud/api
PSQL="sudo -u postgres psql -X -Atq -v ON_ERROR_STOP=1 -d ironbody"
LOGDIR=/root
TS=$(date -u +%Y%m%d-%H%M%S)
[ "$SOLO_COMPROBAR" = --solo-comprobar ] && LOG="$LOGDIR/h8-fase1-comprobar-$TS.log" || LOG="$LOGDIR/h8-fase1-$TS.log"
( umask 077; : > "$LOG" ) || { echo "no se pudo crear el registro $LOG; no se ha tocado nada"; exit 2; }
exec > >(tee -a "$LOG") 2>&1
echo "== deploy-h8-backend-fase1.sh · $(date -u +%Y-%m-%dT%H:%M:%SZ) · ESPERADO=$ESPERADO · PREVIO=$PREVIO · BK=$BK${SOLO_COMPROBAR:+ · $SOLO_COMPROBAR} · registro $LOG"
aborta() { echo "ABORTA: $*"; exit 1; }
DESVIOS=0
desvio() { echo "   ✗ $*"; DESVIOS=$((DESVIOS + 1)); }
artisan() { ( cd /var/www/api/backend && sudo -u www-data php artisan "$@" ); }
# Las 9 variables de la fase 1, explícitas aunque coincidan con el defecto (protección 1: «todas las variables nuevas
# VOICE_* y ULTRON_STUDIO_VOICE_* en false o 0»): los 5 interruptores (entrantes, salientes, laboratorio hablado, monitor
# de voz y publicar la voz, E4-1) y los 4 cerrojos de gasto (entrantes al día, presupuesto de llamadas, presupuesto y
# conductor del laboratorio). Las mueve después el mando de las fases 2–4b, nunca a mano. Las PIEZAS (secretos, la
# subcuenta, el número) son del propietario y no se escriben nunca desde aquí.
VARS="VOICE_INBOUND_ENABLED=false VOICE_OUTBOUND_ENABLED=false VOICE_LAB_ENABLED=false VOICE_MONITOR_ENABLED=false ULTRON_STUDIO_VOICE_PUBLISHING_ENABLED=false VOICE_INBOUND_DAILY_CAP=0 VOICE_DAILY_BUDGET_USD=0 VOICE_LAB_DAILY_BUDGET_USD=0 VOICE_LAB_DRIVER=fake"
CLAVES_H8=$(for kv in $VARS; do printf '%s|' "${kv%%=*}"; done); CLAVES_H8=${CLAVES_H8%|}
# Las piezas del H8 (StudioIntegrationConfig::anyH8Piece): con una sola presente, la huella de voz cambia y la
# configuración autorizada quedaría en deriva (S8-b, decisión 9). En la fase 1 tienen que estar ausentes o vacías.
PIEZAS_H8="ELEVENLABS_INITIATION_SECRET ELEVENLABS_TOOL_SECRET_REF ELEVENLABS_INITIATION_SECRET_REF ELEVENLABS_POST_CALL_WEBHOOK_REF TWILIO_VOICE_ACCOUNT_SID TWILIO_VOICE_API_KEY_SID TWILIO_VOICE_API_KEY_SECRET TWILIO_VOICE_SIGNING_TOKEN TWILIO_VOICE_BASE_URL VOICE_WEBHOOK_BASE_URL"
# Las piezas de la fase 2 que son del H6 (el número): ausentes. Las de E1-12 (clave, agente, webhook, herramientas) SÍ
# están en producción: a diferencia del H6, este script no aborta por encontrarlas (E1-12); solo dice si están.
PIEZAS_FASE2="ELEVENLABS_AGENT_PHONE_NUMBER_ID TWILIO_VOICE_FROM"
PIEZAS_E112="ELEVENLABS_API_KEY ELEVENLABS_AGENT_ID ELEVENLABS_WEBHOOK_SECRET ELEVENLABS_TOOL_SECRET"
# Lo que afirma el Studio en la fase 1 (E1-12): Twilio solo OTP, sin voz de Twilio y ElevenLabs verificado (la última
# verificación «ok» tiene menos de 30 días). Si ha caducado, el paso 0 aborta y dice cómo seguir.
EXPECT_FASE1=twilio:connected_otp_only,twilio_voice:not_configured,elevenlabs:ELEVENLABS_VERIFIED
ENVF=/var/www/api/backend/.env
cuenta_var() { grep -Ec "^[[:space:]]*(export[[:space:]]+)?$1[[:space:]]*=" "$ENVF" || true; }
valor_var() { grep -E "^[[:space:]]*(export[[:space:]]+)?$1[[:space:]]*=" "$ENVF" | sed -E "s/^[[:space:]]*(export[[:space:]]+)?$1[[:space:]]*=[[:space:]]*//; s/[[:space:]]+\$//; s/^\"(.*)\"\$/\\1/"; }
estado_var() { # CLAVE VALOR → falta | igual | distinto | repetida (sin imprimir el valor)
  local n
  n=$(cuenta_var "$1")
  [ "$n" = 0 ] && { echo falta; return; }
  [ "$n" = 1 ] || { echo repetida; return; }
  [ "$(valor_var "$1")" = "$2" ] && echo igual || echo distinto
}
presencia() { # CLAVE → falta | vacia | presente | repetida (sin imprimir el valor)
  local n
  n=$(cuenta_var "$1")
  [ "$n" = 0 ] && { echo falta; return; }
  [ "$n" = 1 ] || { echo repetida; return; }
  [ -n "$(valor_var "$1")" ] && echo presente || echo vacia
}
LOGS=/var/www/api/backend/storage/logs
lineas_log() { local f; for f in "$LOGS"/laravel*.log; do [ -f "$f" ] && echo "$f $(wc -l < "$f" | tr -d ' ')"; done; return 0; }
workers() {
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
# Las rutas en la caché de rutas, en una línea (o «?»): Studio total · integraciones · sus GET · sus POST · voice/* ·
# voice-lab/* · telefonía del CRM (/api/admin/voice) · sus GET · sus POST · públicas de la voz (webhooks y herramientas).
# Antes: 56 18 8 10 0 0 0 0 0 0 · después: 77 18 8 10 15 6 13 5 8 4.
rutas() {
  artisan route:list --json 2>/dev/null | python3 -c '
import json, sys
try:
    r = json.load(sys.stdin)
except Exception:
    print("?")
    sys.exit(0)
u = lambda x: "/" + str(x.get("uri", "")).lstrip("/")
m = lambda x: str(x.get("method", ""))
s = [x for x in r if u(x).startswith("/api/admin/ultron-studio/")]
i = [x for x in s if "/integrations/" in u(x) + "/"]
v = [x for x in s if u(x).startswith("/api/admin/ultron-studio/voice/")]
l = [x for x in s if u(x).startswith("/api/admin/ultron-studio/voice-lab/")]
t = [x for x in r if u(x).startswith("/api/admin/voice/")]
p = [x for x in r if u(x).startswith("/api/webhooks/voice/") or u(x).startswith("/api/voice/tools/")]
print(len(s), len(i), sum(1 for x in i if "GET" in m(x)), sum(1 for x in i if m(x) == "POST"), len(v), len(l),
      len(t), sum(1 for x in t if "GET" in m(x)), sum(1 for x in t if m(x) == "POST"), len(p))' 2>/dev/null || echo '?'
}
# El programador por `schedule:list --json`, con su código de salida APARTE (m-1 del H7): «total del_Studio de_voz»,
# «FALLO <código>» o «ILEGIBLE». De voz = las órdenes `voice:` y la verificación semanal de ElevenLabs (el bloque de
# voz de routes/console.php, E2-6), que con VOICE_MONITOR_ENABLED apagada no existen.
programador() {
  local salida rc=0
  salida=$(artisan schedule:list --json 2>/dev/null) || rc=$?
  [ "$rc" = 0 ] || { echo "FALLO $rc"; return 0; }
  printf '%s' "$salida" | python3 -c '
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
# Motor, H4, H5, H6, el monitor del H7 y el estado de la voz (el proveedor neutro y lo que afirma el Studio): no los toca
# el H8 en la fase 1; se leen antes y después y tienen que ser iguales. Todo existe ya en el código del H7.
leer_previa() {
  local l
  l=$(artisan tinker --execute='$p = "ultron_studio.integrations."; echo json_encode(["engine_scope" => config("marketing.ultron.studio_config"), "publishing_enabled" => config("ultron_studio.publishing_enabled"), "lab_gate" => config("ultron_studio.publishing.lab_gate"), "lab_enabled" => config("ultron_studio.lab.enabled"), "lab_driver" => config("ultron_studio.lab.driver"), "canary_set" => config("marketing.ultron.canary_conversation_id") !== null, "ultron_enabled" => config("marketing.ultron.enabled"), "writes_enabled" => config($p."writes_enabled"), "probe_enabled" => config($p."twilio_probe.enabled"), "monitor_enabled" => config("ultron_studio.monitor.enabled"), "elevenlabs_enabled" => config("voice.elevenlabs.enabled"), "twilio_voice_enabled" => config("voice.twilio.enabled"), "voice" => App\Services\Voice\VoiceProviders::status()->toArray(), "studio_voice" => App\Services\UltronStudio\StudioIntegrations::voiceState()]), PHP_EOL;' 2>&1 | tail -1) || true
  case "$l" in '{"engine_scope"'*) printf '%s\n' "$l" ;; esac
}
# La configuración efectiva del H8: solo banderas, cerrojos, recuentos de presencia y la conexión de la cola; nunca un
# valor de un secreto. Antes del merge las claves del H8 no existen (salen null): solo es informativa.
leer_h8() {
  local l
  l=$(artisan tinker --execute='$e = "voice.elevenlabs."; $t = "voice.twilio."; $piezas = count(array_filter([config($e."initiation_secret"), config($e."tool_secret_ref"), config($e."initiation_secret_ref"), config($e."post_call_webhook_ref"), config($t."account_sid"), config($t."api_key_sid"), config($t."api_key_secret"), config($t."signing_token"), config($t."base_url"), config("voice.webhook_base_url")], "filled")); echo json_encode(["calls_in_code" => App\Services\Voice\VoiceCallPolicy::CALLS_ENABLED_IN_CODE, "inbound" => config("voice.inbound.enabled"), "outbound" => config("voice.outbound.enabled"), "lab" => config("voice.lab.enabled"), "monitor" => config("voice.monitor.enabled"), "publishing" => config("ultron_studio.voice.publishing_enabled"), "twilio_voice" => config($t."enabled"), "twilio_from_set" => filled(config($t."from")), "imported_number_set" => filled(config($e."agent_phone_number_id")), "outbound_cap" => config($t."daily_call_cap"), "inbound_cap" => config("voice.inbound.daily_cap"), "budget" => (string) config("voice.limits.daily_budget_usd"), "lab_driver" => config("voice.lab.driver"), "lab_budget" => (string) config("voice.lab.daily_budget_usd"), "h8_pieces" => $piezas, "queue_voice" => config("queue.connections.voice.driver"), "studio_monitor" => config("ultron_studio.monitor.enabled")]), PHP_EOL;' 2>&1 | tail -1) || true
  case "$l" in '{"calls_in_code"'*) printf '%s\n' "$l" ;; esac
}
ESPERADA_H8='{"calls_in_code":false,"inbound":false,"outbound":false,"lab":false,"monitor":false,"publishing":false,"twilio_voice":false,"twilio_from_set":false,"imported_number_set":false,"outbound_cap":0,"inbound_cap":0,"budget":"0","lab_driver":"fake","lab_budget":"0","h8_pieces":0,"queue_voice":"database","studio_monitor":false}'
# La última verificación de voz del Studio (la que sostiene ELEVENLABS_VERIFIED 30 días): «<resultado> <caduca, UTC>
# <horas que quedan>» o «none». Sin valores: solo el resultado y la fecha.
verificacion_voz() {
  local l
  l=$(artisan tinker --execute='$u = App\Services\UltronStudio\StudioIntegrationConfig::latest(App\Models\UltronStudio\StudioIntegrationSnapshot::PROVIDER_VOICE, App\Models\UltronStudio\StudioIntegrationSnapshot::KIND_PROBE); if ($u === null) { echo "none", PHP_EOL; } else { $fin = Carbon\CarbonImmutable::parse($u->created_at)->addSeconds(App\Services\UltronStudio\StudioIntegrationConfig::VOICE_VERIFIED_MAX_AGE_SECONDS); echo preg_replace("/[^a-z_]/", "", (string) $u->outcome), " ", $fin->utc()->format("Y-m-d\\TH:i"), " ", (int) floor(Carbon\CarbonImmutable::now()->diffInHours($fin, false)), PHP_EOL; }' 2>&1 | tail -1) || true
  [[ "$l" =~ ^(none|[a-z_]{1,32}\ [0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}\ -?[0-9]+)$ ]] && printf '%s\n' "$l"
  return 0
}
# El monitor del H7 en seco, solo las integraciones: sus problemas ordenados (o «ok», o «?»). No abre incidentes.
monitor_integraciones() {
  # El monitor sale con 1 cuando algo no cuadra: se lee su salida aparte (con pipefail, el 1 no puede pasar por «?»).
  local salida
  salida=$(artisan ultron-studio:monitor --check=integrations --dry-run --json 2>/dev/null) || true
  printf '%s' "$salida" | python3 -c '
import json, re, sys
try:
    d = json.load(sys.stdin)
    r = d["checks"]["integrations"]
except Exception:
    print("?")
    sys.exit(0)
p = sorted(str(x) for x in r.get("problems", []) if re.fullmatch(r"[A-Za-z0-9_.:>\-]{1,120}", str(x)))
print("ok" if r.get("ok") is True else (",".join(p) if p else "?"))' 2>/dev/null || echo '?'
}
INSERCION="'ultron_studio_publications', 'ultron_studio_audit_events', 'ultron_studio_integration_snapshots'"
# Una línea: disparadores · CHECK · parciales · tablas de ultron_studio_* y de voice_*.
Q_ESQUEMA="SELECT (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron\_studio\_%')||' '||(SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE 'ultron\_studio\_%')||' '||(SELECT count(*) FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid WHERE t.relname LIKE 'ultron\_studio\_%' AND i.indpred IS NOT NULL)||' '||(SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'ultron\_studio\_%')||' '||(SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'voice\_%')||' '||(SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE 'voice\_%')||' '||(SELECT count(*) FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid WHERE t.relname LIKE 'voice\_%' AND i.indpred IS NOT NULL)||' '||(SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'voice\_%');"
ESQ_ANTES="44 50 4 14 0 0 0 0"
ESQ_MEDIO="68 84 6 18 0 0 0 0"                     # la 100001 aplicada y la 100002 no (cayó por 55P03 o se cortó)
ESQ_DESPUES="68 84 6 18 27 51 3 7"
# El escritor de versiones choca con la FK de la 100001 (SHARE ROW EXCLUSIVE); un lector, no (informe-db §7.5).
Q_BLOQ="SELECT count(*) FROM pg_locks l JOIN pg_class c ON c.oid = l.relation WHERE c.relname = 'ultron_studio_versions' AND l.pid <> pg_backend_pid() AND l.mode NOT IN ('AccessShareLock', 'RowShareLock');"
Q_HUELLA="SELECT md5(pg_get_functiondef(oid)) FROM pg_proc WHERE proname = 'ultron_studio_forbid';"
Q_MIG_H8="SELECT coalesce(string_agg(migration, ' ' ORDER BY migration), '') FROM migrations WHERE migration IN ('$MIG_H8_1', '$MIG_H8_2');"
Q_MIGRACIONES="SELECT migration FROM migrations;"
H8_REL="(c.relname LIKE 'voice\_%' OR c.relname LIKE 'ultron\_studio\_voice\_%')"
Q_FUNC="SELECT count(*)||' '||count(*) FILTER (WHERE prosecdef)||' '||coalesce(string_agg(proname || ':' || pg_get_userbyid(proowner), ',' ORDER BY proname COLLATE \"C\"), '') FROM pg_proc WHERE proname IN ('ultron_studio_voice_publication_chain', 'ultron_studio_voice_publication_agents', 'voice_forbid', 'voice_brake_release');"
Q_APAGADOS="SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND (c.relname LIKE 'voice\_%' OR c.relname LIKE 'ultron\_studio\_%') AND t.tgenabled <> 'O';"
Q_DUENOS_H8="SELECT coalesce(string_agg(c.relname || ':' || pg_get_userbyid(c.relowner), ' ' ORDER BY c.relname COLLATE \"C\"), '') FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind = 'r' AND $H8_REL;"
Q_PRIV_H8="SELECT coalesce(string_agg(c.relname || '=' || CASE WHEN has_table_privilege('iron_app', c.oid, 'SELECT') THEN 'r' ELSE '-' END || CASE WHEN has_table_privilege('iron_app', c.oid, 'INSERT') THEN 'a' ELSE '-' END || CASE WHEN has_any_column_privilege('iron_app', c.oid, 'UPDATE') THEN 'w' ELSE '-' END || CASE WHEN has_table_privilege('iron_app', c.oid, 'DELETE') THEN 'd' ELSE '-' END || CASE WHEN has_table_privilege('iron_app', c.oid, 'TRUNCATE') THEN 'D' ELSE '-' END || CASE WHEN to_regclass('public.' || c.relname || '_id_seq') IS NULL THEN '?' WHEN has_sequence_privilege('iron_app', 'public.' || c.relname || '_id_seq', 'USAGE') THEN 's' ELSE '-' END, ' ' ORDER BY c.relname COLLATE \"C\"), '') FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind = 'r' AND $H8_REL;"
Q_FILAS_H8="SELECT coalesce(string_agg(c.relname || '=' || (xpath('/row/n/text()', query_to_xml(format('SELECT count(*) AS n FROM public.%I', c.relname), false, true, '')))[1]::text, ' ' ORDER BY c.relname COLLATE \"C\"), '') FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind = 'r' AND $H8_REL;"
TABLAS_H8="ultron_studio_voice_agents ultron_studio_voice_lab_sessions ultron_studio_voice_profiles ultron_studio_voice_publications voice_brakes voice_call_events voice_calls voice_consents voice_contacts voice_exclusions voice_rne_checks"
PRIV_H8_ESPERADO="ultron_studio_voice_agents=rawd-s ultron_studio_voice_lab_sessions=rawd-s ultron_studio_voice_profiles=rawd-s ultron_studio_voice_publications=ra---s voice_brakes=ra---s voice_call_events=ra---s voice_calls=rawd-s voice_consents=ra---s voice_contacts=rawd-s voice_exclusions=ra---s voice_rne_checks=ra---s"
FUNC_ESPERADO="4 0 ultron_studio_voice_publication_agents:iron,ultron_studio_voice_publication_chain:iron,voice_brake_release:iron,voice_forbid:iron"
DUENOS_ESPERADO=$(for t in $TABLAS_H8; do printf '%s:iron ' "$t"; done); DUENOS_ESPERADO=${DUENOS_ESPERADO% }
FILAS_H8_ESPERADAS=$(for t in $TABLAS_H8; do printf '%s=0 ' "$t"; done); FILAS_H8_ESPERADAS=${FILAS_H8_ESPERADAS% }
# Los privilegios de iron_app en las 5 tablas del H6/H7 (el H8 no los toca: antes = después) y sus filas.
Q_PRIV_H7="SELECT string_agg(has_table_privilege('iron_app', 'public.' || t, 'SELECT')||'/'||has_table_privilege('iron_app', 'public.' || t, 'INSERT')||'/'||has_any_column_privilege('iron_app', 'public.' || t, 'UPDATE')||'/'||has_table_privilege('iron_app', 'public.' || t, 'DELETE')||'/'||has_table_privilege('iron_app', 'public.' || t, 'TRUNCATE'), ' ' ORDER BY t) FROM unnest(ARRAY[$INSERCION, 'ultron_studio_campaign_links', 'ultron_studio_canary_numbers']) AS t;"
Q_FILAS_H7="SELECT (SELECT count(*) FROM ultron_studio_publications)||' '||(SELECT count(*) FROM ultron_studio_audit_events)||' '||(SELECT count(*) FROM ultron_studio_integration_snapshots)||' '||(SELECT count(*) FROM ultron_studio_versions);"
# Los trabajos en la cola de la voz (E1-10: la conexión `voice`, driver database): 0 siempre en la fase 1.
Q_COLA_VOZ="SELECT CASE WHEN to_regclass('public.jobs') IS NULL THEN 'sin_tabla' ELSE (SELECT count(*) FROM jobs WHERE queue = 'voice')::text END;"

echo "0. comprobaciones previas (solo lectura; si algo falla, no se toca nada):"
cd /var/www/api
g fetch -q origin
ACTUAL=$(g rev-parse --short=7 HEAD)
if [ "$PREVIO" = pendiente ]; then
  # Solo en --solo-comprobar (arriba se niega lo demás): se comprueba todo contra el HEAD de hoy, para fijarlo después.
  BASE=$ACTUAL; REANUDA=0
  echo "   PREVIO sin fijar: producción está en $ACTUAL; las comprobaciones se hacen contra él"
else
  BASE=$PREVIO
  case "$ACTUAL" in
    "$PREVIO")   REANUDA=0 ;;
    "$ESPERADO") REANUDA=1; echo "   reanudación: el código ya está en $ESPERADO (una ejecución anterior se cortó); se repiten variables, migración y comprobaciones" ;;
    *) aborta "producción no está en $PREVIO ni en $ESPERADO (HEAD $ACTUAL): si se desplegó otra cosa después, revisar qué entró y actualizar PREVIO en un commit" ;;
  esac
fi
MODIFICADOS=$(g status --porcelain --untracked-files=no | wc -l | tr -d ' ')
[ "$MODIFICADOS" = 0 ] || aborta "el checkout de producción tiene $MODIFICADOS cambios locales"
[ "$(g rev-parse --short=7 origin/main)" = "$ESPERADO" ] || aborta "origin/main es $(g rev-parse --short=7 origin/main), no $ESPERADO"
g merge-base --is-ancestor "$BASE" origin/main || aborta "origin/main no es avance rápido desde $BASE"
NUEVAS=$(g diff --name-only --diff-filter=AMDR "$BASE" origin/main -- backend/database/migrations | sort | tr '\n' ' ')
if [ "$NUEVAS" != "$MIGRACIONES" ]; then
  AJENAS=""
  for f in $NUEVAS; do case " $MIGRACIONES " in *" $f "*) ;; *) AJENAS="$AJENAS ${f##*/}" ;; esac; done
  aborta "migraciones nuevas o tocadas entre $BASE y origin/main distintas de las dos del Hito 8: [$NUEVAS]${AJENAS:+ · ajenas al H8:$AJENAS}. El H8 no despliega trabajo de otros: que su dueño lo despliegue antes por su vía (y entonces fijar PREVIO al commit que ya lo tiene en producción)"
fi
# Los commits ajenos que traería el merge y que producción no tiene: por parche (`git patch-id --stable`) frente a los
# últimos 400 commits de producción. Los del H8 se reconocen por su prefijo (`tipo(ultron-studio-h8):` y el giro de la
# constante, `voice(calls-open):`).
EN_PROD=$(g log --no-merges -p --max-count=400 "$BASE" | g patch-id --stable | awk '{print $1}' | sort -u)
AJENOS=""
ACEPTADOS_VISTOS=""
while read -r pid cid; do
  [ -n "${cid:-}" ] || continue
  grep -qxF "$pid" <<< "$EN_PROD" && continue
  # Los commits del hito llevan el prefijo `tipo(ultron-studio-h8):` o `voice(calls-open):`; el tipo puede ser compuesto
  # con barras (`docs/test/ops(ultron-studio-h8):`, commits del lead): C-1 de REV8 lo detectó como «ajeno» por error.
  [[ "$(g log -1 --format=%s "$cid")" =~ ^[a-z]+(/[a-z]+)*\((ultron-studio-h8|calls-open)\): ]] && continue
  case ",$ACEPTA," in *",${cid:0:7},"*) ACEPTADOS_VISTOS="$ACEPTADOS_VISTOS ${cid:0:7}"; continue ;; esac
  AJENOS="$AJENOS ${cid:0:7}"
done < <(g log --no-merges -p "$BASE..origin/main" | g patch-id --stable)
for c in ${ACEPTA//,/ }; do
  case " $ACEPTADOS_VISTOS " in *" $c "*) ;; *) aborta "--acepta-main=$c: ese commit no está entre los ajenos que traería el merge desde $BASE (¿otro HEAD u otro PREVIO?): no se acepta nada a ciegas" ;; esac
done
[ -z "$ACEPTADOS_VISTOS" ] || echo "   commits ajenos ACEPTADOS por el propietario con --acepta-main (viajan con el H8):$ACEPTADOS_VISTOS ($(for c in $ACEPTADOS_VISTOS; do g log -1 --format='%s' "$c" | cut -c1-60; done | paste -sd '|' -))"
FALTA_DECIDIR=""
if [ -n "$AJENOS" ]; then
  LISTA="$(for c in $AJENOS; do printf '%s «%s» · ' "$c" "$(g log -1 --format='%s' "$c" | cut -c1-60)"; done)"
  if [ "$SOLO_COMPROBAR" = --solo-comprobar ]; then
    FALTA_DECIDIR=$(echo $AJENOS | tr ' ' ',')
    echo "   PENDIENTE DE DECISIÓN: commits ajenos al H8 que producción no tiene y viajarían con el merge: ${LISTA% · }"
  else
    aborta "commits ajenos al H8 entre $BASE y origin/main que producción no tiene: ${LISTA% · }. El H8 no despliega trabajo de otros sin decisión expresa: o su dueño lo despliega antes por su vía, o el propietario lo acepta ahora con --acepta-main=$(echo $AJENOS | tr ' ' ',')"
  fi
fi
EN_PUERTA=$(g diff --name-only "$BASE" origin/main -- "${PUERTA[@]}" | tr '\n' ' ')
FUERA=""
for f in $EN_PUERTA; do case " $PERMITIDOS_EN_PUERTA " in *" $f "*) ;; *) FUERA="$FUERA $f" ;; esac; done
[ -z "$FUERA" ] || aborta "la puerta de diff tiene ficheros que el contrato del H8 no nombra (OTP, motor, API interna, Meta, catálogo o dependencias):$FUERA"
BORRADAS=$(g diff --numstat "$BASE" origin/main -- backend/routes/console.php | awk '{print $2}')
[ -z "$BORRADAS" ] || [ "$BORRADAS" = 0 ] || aborta "routes/console.php pierde $BORRADAS líneas entre $BASE y origin/main: el H8 solo añade el bloque de voz"
# E10-7 (SEC8-b B-3): la constante de llamadas se comprueba ANTES del merge, en el commit que se va a desplegar (y no solo
# después, en el paso 7): la fase 1 nunca despliega la constante abierta (`voice(calls-open):` es de la fase 3).
POLITICA_EN_ORIGEN=$(g show "origin/main:backend/app/Services/Voice/VoiceCallPolicy.php" 2>/dev/null || true)
CONSTANTE_EN_ORIGEN=$(printf '%s\n' "$POLITICA_EN_ORIGEN" | { grep -cE '^[[:space:]]*public const CALLS_ENABLED_IN_CODE = false;[[:space:]]*$' || true; })
CONSTANTE_ABIERTA=$(printf '%s\n' "$POLITICA_EN_ORIGEN" | { grep -cE 'CALLS_ENABLED_IN_CODE[[:space:]]*=[[:space:]]*true' || true; })
# REV8 m-4: la declaración anclada (una línea exacta) y ninguna coincidencia de «= true» en todo el fichero, ni en comentarios.
[ "$CONSTANTE_EN_ORIGEN" = 1 ] && [ "$CONSTANTE_ABIERTA" = 0 ] || aborta "VoiceCallPolicy::CALLS_ENABLED_IN_CODE no es exactamente false en origin/main ($ESPERADO; declaraciones=false: $CONSTANTE_EN_ORIGEN, apariciones de true: $CONSTANTE_ABIERTA): la fase 1 no despliega la constante abierta"
case "$(cat "$BK/api-commit.txt" 2>/dev/null)" in
  "$BASE"*) ;;
  *) aborta "$BK/api-commit.txt no es $BASE (la vuelta atrás del código lo lee; si el respaldo se rehizo en una reanudación, escribirlo con: git -C /var/www/api rev-parse $BASE > $BK/api-commit.txt)" ;;
esac
[ -s "$BK/ironbody.dump" ] && [ -n "$(find "$BK/ironbody.dump" -mmin -120)" ] || aborta "no hay volcado de menos de 2 h en $BK"
[ "$(stat -c '%a' "$BK")" = 700 ] || aborta "el directorio del respaldo tiene que ser 700 (es $(stat -c '%a' "$BK"))"
[ "$(stat -c '%U %a' "$BK/ironbody.dump")" = "root 600" ] && [ "$(stat -c '%U %a' "$BK/env.backup")" = "root 600" ] || aborta "el volcado y env.backup tienen que ser de root y 600"
[ -f "$BK/ironbody.dump.sha256" ] || aborta "falta $BK/ironbody.dump.sha256 (el bloque del respaldo lo crea): un volcado sin su huella no se puede comprobar después"
sha256sum -c --status "$BK/ironbody.dump.sha256" || aborta "el volcado no coincide con su ironbody.dump.sha256"
TABLAS=$(pg_restore -l "$BK/ironbody.dump" | grep -c 'TABLE DATA' || true)
[ "$TABLAS" -ge "$MIN_TABLAS" ] || aborta "el volcado solo lista $TABLAS tablas con datos (al menos $MIN_TABLAS; contarlas a mano antes de pensar en bajar el umbral)"
if [ "$REANUDA" = 0 ]; then cmp -s "$ENVF" "$BK/env.backup" || aborta "el fichero de entorno cambió desde el respaldo: rehacer el respaldo (env.backup tiene que ser el de partida)"; fi
echo "   respaldo $BK: $TABLAS tablas con datos · 700/600 de root · huella del volcado comprobada"
[ -z "$(tail -c1 "$ENVF")" ] || echo "   el fichero de entorno no acaba en salto de línea: se añadirá uno antes de las claves"
for kv in $VARS; do
  case "$(estado_var "${kv%%=*}" "${kv#*=}")" in
    falta|igual) ;;
    *) aborta "${kv%%=*} ya existe con otro valor o repetida (no se imprime): la fase 1 la deja apagada; lo que la encienda va con el mando de su fase y su autorización. Revisar a mano" ;;
  esac
done
for k in $PIEZAS_H8 $PIEZAS_FASE2; do
  case "$(presencia "$k")" in falta|vacia) ;; *) aborta "$k está en el entorno (no se imprime): es una pieza de la fase 2 (con una sola, la huella de voz cambia y el número o la subcuenta existirían); aclararlo con el propietario" ;; esac
done
case "$(estado_var TWILIO_VOICE_ENABLED false)" in falta|igual) ;; *) aborta "TWILIO_VOICE_ENABLED no está en false (o repetida): la fase 1 no tiene voz de Twilio" ;; esac
case "$(estado_var TWILIO_VOICE_DAILY_CALL_CAP 0)" in falta|igual) ;; *) aborta "TWILIO_VOICE_DAILY_CALL_CAP no está en 0 (o repetida): la fase 1 no llama" ;; esac
case "$(estado_var ELEVENLABS_REGION default)" in falta|igual) ;; *) aborta "ELEVENLABS_REGION existe con otro valor o repetida: revisar a mano (E1-12 la dejó en default)" ;; esac
E112=""; for k in $PIEZAS_E112; do E112="$E112 ${k#ELEVENLABS_}=$(presencia "$k")"; done
case "$E112" in *repetida*) aborta "una pieza de ElevenLabs está repetida en el entorno: deduplicar a mano (no se imprime)" ;; esac
echo "   ElevenLabs (E1-12; presencia, sin valores):$E112 · ENABLED$(estado_var ELEVENLABS_ENABLED true | sed 's/igual/=true/; s/^distinto/ distinto de true/; s/^falta/ ausente/')"
case "$(presencia ELEVENLABS_BASE_URL)" in presente|repetida) echo "   AVISO: ELEVENLABS_BASE_URL está en el entorno: en production se ignora (hosts oficiales siempre)" ;; esac
OTRAS=$(grep -oE "^[[:space:]]*(export[[:space:]]+)?(VOICE_|ULTRON_STUDIO_VOICE_|QUEUE_VOICE_)[A-Z0-9_]+" "$ENVF" | sed -E 's/^[[:space:]]*(export[[:space:]]+)?//' | sort -u | grep -vxE "$CLAVES_H8|VOICE_WEBHOOK_BASE_URL" | tr '\n' ' ' || true)
[ -z "$OTRAS" ] || echo "   AVISO: otras claves de la voz ya en el entorno (solo nombres; la fase 1 no las escribe, la configuración efectiva del paso 7 dice si encienden algo): $OTRAS"
echo "   claves de la fase 1 ausentes o iguales · ninguna pieza del H8 ni del número"
MIG_REG=$($PSQL -c "$Q_MIG_H8") || aborta "no se pudo leer la tabla migrations (psql)"
case "$MIG_REG" in
  "")                       ESQ_PARTIDA=$ESQ_ANTES; YA_APLICADAS=0 ;;
  "$MIG_H8_1")              ESQ_PARTIDA=$ESQ_MEDIO; YA_APLICADAS=0; echo "   AVISO: la $MIG_H8_1 ya está aplicada y la $MIG_H8_2 no (una ejecución cortada o un migrate ajeno): se aplica la que falta" ;;
  "$MIG_H8_1 $MIG_H8_2")    ESQ_PARTIDA=$ESQ_DESPUES; YA_APLICADAS=1; echo "   AVISO: las dos migraciones del H8 YA están aplicadas (otro despliegue o un migrate ajeno, como en el H7): no se vuelve a migrar; el esquema se comprueba igual" ;;
  *) aborta "registro de migraciones del H8 inesperado: [$MIG_REG] (la 100002 sin la 100001): PARAR y avisar a database-specialist" ;;
esac
APLICADAS=$($PSQL -c "$Q_MIGRACIONES" | sort -u) || aborta "no se pudo leer la tabla migrations (psql)"
# Pendientes = los ficheros de migración de origin/main (solo el primer nivel, como los carga Laravel) que la base no
# tiene registrados. Es lo que aplicaría ironbody-migrate: tiene que ser exactamente lo que falta del H8.
PENDIENTES=$(comm -23 <(g ls-tree --name-only origin/main -- backend/database/migrations/ | sed -n 's#^backend/database/migrations/\([^/]*\)\.php$#\1#p' | sort -u) <(printf '%s\n' "$APLICADAS") | tr '\n' ' ')
ESPERADAS=""; for m in $MIG_H8_1 $MIG_H8_2; do case " $MIG_REG " in *" $m "*) ;; *) ESPERADAS="$ESPERADAS$m " ;; esac; done
[ "$PENDIENTES" = "$ESPERADAS" ] || aborta "migraciones pendientes en origin/main frente a la base: [$PENDIENTES] (esperadas, solo las del H8 que faltan: [$ESPERADAS]). ironbody-migrate aplicaría TODAS: lo ajeno se despliega antes por su vía"
PRE=$($PSQL -c "${Q_ESQUEMA%;}||' '||(SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE proname = 'ultron_studio_forbid')||' '||(SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'ultron_studio_versions')||' '||has_schema_privilege('iron', 'public', 'CREATE')||' '||(SELECT count(*) FROM pg_roles WHERE rolname = 'iron_app');") || aborta "no se pudo leer el esquema de partida (psql; ¿existe el rol iron?)"
[ "$PRE" = "$ESQ_PARTIDA iron iron true 1" ] || aborta "esquema de partida inesperado: [$PRE] (esperado [$ESQ_PARTIDA iron iron true 1]: disparadores, CHECK, parciales y tablas de ultron_studio_* y voice_*, dueños de F y de versiones, CREATE del dueño y el rol iron_app). Con voice_* distinto de 0 antes de migrar hay tablas que no son del H8; sin iron_app, en production las migraciones FALLAN (REVOKE); con otro dueño, 42501"
HUELLA=$($PSQL -c "$Q_HUELLA") || aborta "no se pudo leer ultron_studio_forbid() (psql)"
[ "$HUELLA" = "$HUELLA_F" ] || aborta "ultron_studio_forbid() no es la de F (md5 $HUELLA, esperado $HUELLA_F): PARAR y avisar a database-specialist"
BLOQ=$($PSQL -c "$Q_BLOQ") || aborta "no se pudieron leer los cerrojos (psql)"
[ "$BLOQ" = 0 ] || aborta "$BLOQ cerrojos de escritura sobre ultron_studio_versions (la FK de la 100001 pide SHARE ROW EXCLUSIVE y cae por 55P03 con un escritor abierto): esperar y repetir; quién, con h8-schema-check.sql"
PRIV7_0=$($PSQL -c "$Q_PRIV_H7") || aborta "no se pudieron leer los privilegios de iron_app en el H7 (psql)"
FILAS7_0=$($PSQL -c "$Q_FILAS_H7") || aborta "no se pudieron contar las filas del Studio (psql)"
RUTAS0=$(rutas)
case "$YA_APLICADAS$REANUDA" in
  00) [ "$RUTAS0" = "56 18 8 10 0 0 0 0 0 0" ] || aborta "rutas en la caché de producción: [$RUTAS0] (esperado [56 18 8 10 0 0 0 0 0 0]: el H7, sin voz)" ;;
  *)  echo "   rutas en la caché ahora (informativo en una reanudación): [$RUTAS0]" ;;
esac
PROG0=$(programador)
case "$PROG0" in
  "FALLO "*) aborta "schedule:list no sale con 0 (código ${PROG0#FALLO }; su salida no se imprime): sin poder leer el programador no se despliega (¿base o caché? m-1 del H7)" ;;
  ILEGIBLE) aborta "schedule:list no devolvió la lista JSON de tareas: sin poder leer el programador no se despliega" ;;
esac
read -r TOTAL0 STUDIO0 VOZ0 <<< "$PROG0"
[ "$STUDIO0 $VOZ0" = "0 0" ] || aborta "el programador tiene $STUDIO0 tareas del Studio y $VOZ0 de voz antes de desplegar (esperado 0 y 0: el monitor del H7 apagado y la voz sin programar)"
PREVIA_ANTES=$(leer_previa)
[ -n "$PREVIA_ANTES" ] || aborta "no se pudo leer la configuración efectiva del motor, del H4–H7 y de la voz (tinker falló; su salida no se imprime)"
case "$PREVIA_ANTES" in *'"monitor_enabled":false'*) ;; *) aborta "el monitor del H7 no está apagado: la fase 1 del H8 se despliega con el monitor apagado (su expectativa no es aún la de la fase; ver el plan)" ;; esac
case "$PREVIA_ANTES" in
  *'"studio_voice":"ELEVENLABS_VERIFIED"'*) ;;
  *) aborta "el Studio no afirma ELEVENLABS_VERIFIED (lo que dejó E1-12): $(printf '%s' "$PREVIA_ANTES" | grep -oE '"studio_voice":"[A-Z_]+"'). Si la última verificación tiene más de 30 días, renovarla es UN GET con la clave de producción (\`ultron-studio:integrations-snapshot --verify-voice\`, con autorización; E2-2 pide rotar esa clave antes de la fase 2) o desplegar con otra expectativa, decisión del lead" ;;
esac
VER=$(verificacion_voz)
case "$VER" in
  ok\ *) HORAS=${VER##* }; echo "   verificación de voz: ok · ELEVENLABS_VERIFIED hasta $(printf '%s' "$VER" | cut -d' ' -f2) UTC (quedan $HORAS h)"
         [ "$HORAS" -ge 24 ] || echo "   AVISO: ELEVENLABS_VERIFIED caduca en menos de 24 h: el doctor del paso 11 podría cambiar durante el despliegue; renovarla antes (con autorización) o asumirlo" ;;
  *) aborta "no se pudo leer la última verificación de voz del Studio, o no es ok ([$VER])" ;;
esac
DOC0=0; artisan ultron-studio:integrations-doctor --expect="$EXPECT_FASE1" > /dev/null 2>&1 || DOC0=$?
[ "$DOC0" = 0 ] || aborta "integrations-doctor --expect=$EXPECT_FASE1 no sale con 0 (código $DOC0): el punto de partida no es el de E1-12; ejecutarlo a mano como www-data y aclararlo"
MONI0=$(monitor_integraciones)
[ "$MONI0" != "?" ] || aborta "el monitor del H7 en seco (integraciones) no devolvió un resultado legible"
case ",$MONI0," in *",config_drift:"*) aborta "el monitor del H7 ya ve deriva de la configuración autorizada antes de desplegar ([$MONI0]): aclararlo antes (GET integrations/config)" ;; esac
W0=$(workers 20)
[ "$W0" = "13 0" ] || aborta "workers antes de empezar: $W0 (RUNNING, BACKOFF/FATAL; esperado 13 0): no se despliega sobre un sistema degradado"
FACT=$(systemctl is-active ironbody-billing-worker 2>/dev/null || true)
[ "$FACT" = active ] || aborta "ironbody-billing-worker no está activo antes de empezar ($FACT)"
LOG_ANTES=$(lineas_log)
FALLIDAS_ANTES=$($PSQL -c "SELECT count(*) FROM failed_jobs;") || aborta "no se pudo leer failed_jobs (psql)"
SELLOS_ANTES=$($PSQL -c "SELECT count(*) FROM marketing_ai_actions WHERE metadata ? 'studio';") || aborta "no se pudo leer marketing_ai_actions (psql)"
INST_ANTES=$($PSQL -c "SELECT count(*) FROM ultron_studio_integration_snapshots;") || aborta "no se pudieron contar las instantáneas (psql)"
INCID_ANTES=$($PSQL -c "SELECT count(*) FROM incidents WHERE source = 'ultron_studio';") || aborta "no se pudieron contar los incidentes (psql)"
echo "   HEAD $ACTUAL limpio · migraciones del H8: 2 en el diff, pendientes [${PENDIENTES% }]$([ "$YA_APLICADAS" = 1 ] && echo ' (ya aplicadas por otro)') · puerta: [${EN_PUERTA% }] (todas nombradas por el contrato) · esquema [$PRE] · función de F · sin escritores de versiones · rutas [$RUTAS0] · programador $TOTAL0 tareas, 0 del Studio, 0 de voz · workers 13/0 · facturación activa"
echo "   iron_app en libro · asociaciones · canarios · instantáneas · cadena (SELECT/INSERT/UPDATE/DELETE/TRUNCATE): $PRIV7_0"
echo "   filas cadena · libro · instantáneas · versiones: $FILAS7_0 · colas fallidas: $FALLIDAS_ANTES · sellos studio: $SELLOS_ANTES · incidentes ultron_studio: $INCID_ANTES"
echo "   motor, H4–H7 y voz (informativo; no cambia): $PREVIA_ANTES"
echo "   integrations-doctor $EXPECT_FASE1: 0 · monitor del H7 en seco, integraciones: [$MONI0] (informativo; tiene que seguir igual)"
case "$MONI0" in ok) ;; *) echo "   AVISO: la expectativa del monitor del H7 no es la de la fase 1 (E1-12): antes de ENCENDERLO, ULTRON_STUDIO_MONITOR_EXPECT=$EXPECT_FASE1 (decisión del lead; ver el plan, «Hito 8», fase 1)" ;; esac
sudo -u www-data php -r 'require "/var/www/api/backend/vendor/autoload.php"; try { Dotenv\Dotenv::createArrayBacked("/var/www/api/backend")->load(); } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' \
  || aborta "el fichero de entorno de producción NO parsea antes de empezar: aclararlo antes de desplegar (no se ha tocado nada)"
echo "   el fichero de entorno parsea (antes de tocar nada)"
if [ "$SOLO_COMPROBAR" = --solo-comprobar ]; then
  if [ -n "$FALTA_DECIDIR" ]; then
    echo "COMPROBACIONES PREVIAS LEÍDAS, FALTA DECIDIR los commits ajenos [$FALTA_DECIDIR] (no se ha tocado nada): el despliegue solo sigue con --acepta-main=$FALTA_DECIDIR (decisión del propietario) o cuando su dueño los haya desplegado"
    exit 3
  elif [ "$PREVIO" = pendiente ]; then
    echo "COMPROBACIONES PREVIAS LEÍDAS, FALTA FIJAR PREVIO=$ACTUAL (no se ha tocado nada): revisar lo que entró en producción desde el H7 (git log --oneline cfcd0d1..$ACTUAL), fijarlo en deploy-h8-backend-fase1.sh en un commit, volver a copiarlo y repetir --solo-comprobar"
  else
    echo "COMPROBACIONES PREVIAS EN VERDE (no se ha tocado nada)"
  fi
  exit 0
fi

g merge -q --ff-only origin/main
HEAD=$(g rev-parse --short=7 HEAD)
[ "$HEAD" = "$ESPERADO" ] || aborta "HEAD $HEAD no es $ESPERADO"
echo "1. código: HEAD=$HEAD"
ILEGIBLES=0; REVISADOS=0
CAMBIADOS=$(g diff --name-only "$PREVIO" HEAD) || aborta "git diff --name-only $PREVIO HEAD falló: no se puede comprobar la lectura por www-data"
while IFS= read -r f; do
  [ -n "$f" ] || continue
  [ -e "$f" ] || continue
  REVISADOS=$((REVISADOS + 1))
  sudo -u www-data test -r "$f" || { echo "   ✗ www-data no puede leer $f ($(stat -c '%U:%G %a' "$f"))"; ILEGIBLES=$((ILEGIBLES + 1)); }
done <<< "$CAMBIADOS"
[ "$REVISADOS" -gt 0 ] || aborta "la comprobación de lectura no revisó ningún fichero (el diff $PREVIO..HEAD salió vacío): algo no cuadra"
[ "$ILEGIBLES" = 0 ] || aborta "$ILEGIBLES de $REVISADOS ficheros del merge no los lee www-data: chmod 644 (ficheros) / 755 (directorios) sobre git diff --name-only $PREVIO HEAD antes de seguir; relanzar este mismo script (reanuda)"
echo "   los $REVISADOS ficheros del merge son legibles por www-data"
cd backend
echo "2. variables (5 interruptores y 4 cerrojos de gasto de la voz, apagados):"
[ -z "$(tail -c1 "$ENVF")" ] || printf '\n' >> "$ENVF"
for kv in $VARS; do
  k=${kv%%=*}; v=${kv#*=}
  if [ "$(estado_var "$k" "$v")" = falta ]; then printf '  %s=%s\n' "$k" "$v" >> "$ENVF"; echo "   $k añadida"; else echo "   $k ya estaba con el valor de la fase 1"; fi
done
echo "   permisos del fichero: $(stat -c '%U %a' "$ENVF")"
sudo -u www-data php -r 'require "vendor/autoload.php"; try { Dotenv\Dotenv::createArrayBacked(".")->load(); } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' \
  || aborta "el fichero de entorno NO parsea: restáuralo desde $BK/env.backup (mismo dueño y modo) antes de seguir"
echo "   el fichero de entorno parsea"
echo "3. migraciones (rol dueño, lock_timeout 5 s; un 55P03 se reintenta hasta 3 veces, 15 s entre intentos):"
INTENTO=0; RC=0
if [ -z "${PENDIENTES// /}" ]; then
  echo "   nada que migrar: las dos del H8 ya estaban registradas (paso 0); no se llama a ironbody-migrate"
  ESQ=$($PSQL -c "$Q_ESQUEMA") || aborta "no se pudo leer el esquema (psql); relanzar este mismo script (reanuda)"
  [ "$ESQ" = "$ESQ_DESPUES" ] || aborta "las migraciones constan aplicadas pero el esquema es [$ESQ], no [$ESQ_DESPUES]: alguien tocó las guardas; NO se ha hecho route:cache; PARAR y avisar a database-specialist"
else
  while :; do
    INTENTO=$((INTENTO + 1))
    set +e
    SALIDA=$(sudo env PGOPTIONS="-c lock_timeout=5s" /usr/local/bin/ironbody-migrate 2>&1)
    RC=$?
    set -e
    printf '%s\n' "$SALIDA" | sed 's/^/   /'
    ESQ=$($PSQL -c "$Q_ESQUEMA") || aborta "no se pudo leer el esquema tras ironbody-migrate (psql). NO se ha hecho route:cache; relanzar este mismo script (reanuda)"
    [ "$ESQ" != "$ESQ_DESPUES" ] || break
    case "$SALIDA" in
      *55P03*|*"lock timeout"*)
        if [ "$INTENTO" -lt 3 ]; then echo "   intento $INTENTO: 55P03 (un escritor de ultron_studio_versions) · esquema [$ESQ] · reintento en 15 s"; sleep 15; continue; fi ;;
    esac
    aborta "esquema tras ironbody-migrate [$ESQ], esperado [$ESQ_DESPUES] (código $RC, intento $INTENTO; rutas en caché ahora: [$(rutas)]). Cada migración es una transacción: o está entera o no está ([$ESQ_MEDIO] = solo la del Studio). NO se ha hecho route:cache: las rutas nuevas no se sirven. Ver quién tiene versiones con h8-schema-check.sql y relanzar este mismo script (reanuda)"
  done
fi
echo "   esquema [$ESQ]: 68 disparadores · 84 CHECK · 6 parciales · 18 tablas del Studio; 27 · 51 · 3 · 7 de la telefonía (intento $INTENTO)"
[ "$RC" = 0 ] || desvio "ironbody-migrate salió con $RC aunque el esquema cuadra: revisar su salida (arriba) antes de dar la fase por buena"
echo "4. esquema del Hito 8 (antes de servir las rutas nuevas):"
FUNC=$($PSQL -c "$Q_FUNC")
echo "   funciones nuevas (número · SECURITY DEFINER · dueños): $FUNC"
[ "$FUNC" = "$FUNC_ESPERADO" ] || aborta "funciones del H8 [$FUNC], esperado [$FUNC_ESPERADO]. NO se ha hecho route:cache; revisar con h8-schema-check.sql y database-specialist"
APAG=$($PSQL -c "$Q_APAGADOS")
[ "$APAG" = 0 ] || aborta "$APAG disparadores apagados en ultron_studio_* o voice_*: NO se ha hecho route:cache; PARAR y avisar a database-specialist"
[ "$($PSQL -c "$Q_HUELLA")" = "$HUELLA_F" ] || aborta "ultron_studio_forbid() cambió de cuerpo con la migración. NO se ha hecho route:cache"
DUENOS=$($PSQL -c "$Q_DUENOS_H8")
[ "$DUENOS" = "$DUENOS_ESPERADO" ] || aborta "dueños de las tablas del H8 [$DUENOS], esperado todas de iron. NO se ha hecho route:cache"
PRIV8=$($PSQL -c "$Q_PRIV_H8")
echo "   iron_app en las 11 tablas (r SELECT · a INSERT · w UPDATE · d DELETE · D TRUNCATE · s secuencia): $PRIV8"
[ "$PRIV8" = "$PRIV_H8_ESPERADO" ] || aborta "privilegios de iron_app en las tablas del H8 [$PRIV8], esperado [$PRIV_H8_ESPERADO] (ra en las 6 de solo inserción, rawd en las 5 con estados). NO se ha hecho route:cache; avisar a database-specialist"
FILAS8=$($PSQL -c "$Q_FILAS_H8")
[ "$FILAS8" = "$FILAS_H8_ESPERADAS" ] && echo "   las 11 tablas del H8, vacías" || desvio "filas en las tablas del H8 [$FILAS8]: la fase 1 no escribe nada (¿alguien usó la voz?)"
PRIV7=$($PSQL -c "$Q_PRIV_H7")
[ "$PRIV7" = "$PRIV7_0" ] && echo "   iron_app en las 5 tablas del H6/H7: igual que antes" || desvio "los privilegios de iron_app en las tablas del H6/H7 cambiaron: antes [$PRIV7_0] · después [$PRIV7]"
FILAS7=$($PSQL -c "$Q_FILAS_H7")
[ "$FILAS7" = "$FILAS7_0" ] && echo "   filas de la cadena, el libro, las instantáneas y versiones: $FILAS7 (las mismas)" || desvio "las filas del Studio cambiaron durante la migración: antes [$FILAS7_0] · después [$FILAS7] (¿alguien usó el Studio?)"
echo "5. cachés y workers:"
sudo -u www-data sh -c 'umask 077; php artisan config:cache -q && php artisan route:cache -q'
# bootstrap/cache lo escribe www-data: root no hace chmod ahí (seguiría un enlace puesto en su lugar; H-5 de SEC7).
for f in bootstrap/cache/config.php bootstrap/cache/routes-v7.php; do
  [ -f "$f" ] && [ ! -L "$f" ] || aborta "$f no es un fichero normal (¿un enlace?): no se reinician los workers"
  [ "$(stat -c '%U' "$f")" = www-data ] || aborta "$f no es de www-data: no se reinician los workers (quedarían en BACKOFF)"
  [ "$(stat -c '%a' "$f")" = 600 ] || { sudo -u www-data chmod 600 "$f" || aborta "www-data no pudo dejar $f en 600"; echo "   $f pasado a 600 (como www-data)"; }
done
echo "   cachés: $(stat -c '%U %a' bootstrap/cache/config.php) · $(stat -c '%U %a' bootstrap/cache/routes-v7.php)"
PIDS_ANTES=$(supervisorctl status 2>/dev/null | grep -oE 'pid [0-9]+' | sort || true)
sudo -u www-data php artisan queue:restart -q
RUTAS=$(rutas)
COBERTURA=$(artisan auth:coverage --unmapped 2>&1 | tr -d '\n' | cut -c1-80 || true)
echo "6. rutas (Studio · integraciones · GET · POST · voz · laboratorio · telefonía · GET · POST · públicas): $RUTAS · auth:coverage --unmapped → [$COBERTURA]"
[ "$RUTAS" = "77 18 8 10 15 6 13 5 8 4" ] || desvio "rutas [$RUTAS] (esperado [77 18 8 10 15 6 13 5 8 4])"
[ -z "$COBERTURA" ] || desvio "auth:coverage --unmapped no está vacío"
echo "7. configuración efectiva:"
H8=$(leer_h8)
case "$H8" in '{"calls_in_code"'*) echo "   $H8" ;; *) echo "   (tinker no devolvió la configuración del H8; su salida no se imprime)" ;; esac
[ "$H8" = "$ESPERADA_H8" ] || { desvio "la configuración efectiva del H8 no es la de la fase 1 (todo apagado)"; echo "     esperada: $ESPERADA_H8"; }
PREVIA=$(leer_previa)
[ "$PREVIA" = "$PREVIA_ANTES" ] && echo "   motor, H4–H7 y voz (proveedor y Studio) iguales que antes del despliegue" || desvio "la configuración del motor, del H4–H7 o el estado de la voz cambió: antes $PREVIA_ANTES · después ${PREVIA:-ilegible}"
PROG=$(programador)
case "$PROG" in
  "FALLO "*) desvio "schedule:list no sale con 0 (código ${PROG#FALLO }; su salida no se imprime): no se puede comprobar el programador (m-1)" ;;
  ILEGIBLE) desvio "schedule:list no devolvió la lista JSON de tareas: no se puede comprobar el programador" ;;
  *) echo "   programador (total · del Studio · de voz): $PROG (esperado $TOTAL0 0 0: con VOICE_MONITOR_ENABLED apagada el bloque de voz no programa nada)"
     [ "$PROG" = "$TOTAL0 0 0" ] || desvio "el programador da [$PROG] y se esperaba [$TOTAL0 0 0]" ;;
esac
COLA=$($PSQL -c "$Q_COLA_VOZ")
[ "$COLA" = 0 ] || [ "$COLA" = sin_tabla ] && echo "   cola voice: $COLA trabajos (E1-10; la fase 1 no despacha nada a la voz)" || desvio "hay $COLA trabajos en la cola voice: nada debería despacharse con todo apagado (y la fase 1 no instala ironbody-voice)"
sleep 6
W1=$(workers 45); sleep 8; W2=$(workers 45)
echo "8. workers (RUNNING · BACKOFF/FATAL), dos lecturas separadas: [$W1] y [$W2] (13: ironbody-voice NO se instala en la fase 1)"
[ "$W1" = "13 0" ] && [ "$W2" = "13 0" ] || desvio "workers [$W1] y [$W2] (esperado 13 0 las dos veces)"
PIDS_DESPUES=$(supervisorctl status 2>/dev/null | grep -oE 'pid [0-9]+' | sort || true)
NUEVOS=$(comm -13 <(printf '%s\n' "$PIDS_ANTES") <(printf '%s\n' "$PIDS_DESPUES") | grep -c pid || true)
echo "   INFO: workers con pid nuevo tras queue:restart: $NUEVOS de 13 (el resto termina su trabajo antes de reiniciarse; se reinician además cada hora)"
FACT=$(systemctl is-active ironbody-billing-worker 2>/dev/null || true)
[ "$FACT" = active ] || desvio "ironbody-billing-worker: $FACT"
echo "9. sanidad:"
# Sin token: 401 y `Cache-Control: no-store, private` (B7-a) en lo nuevo del CRM y del Studio y en lo de siempre.
for p in admin/voice/status admin/voice/calls admin/ultron-studio/voice/publications admin/ultron-studio/voice-lab/status admin/ultron-studio/status; do
  CAB=$(curl -sI -H 'Accept: application/json' "$API/$p" | tr -d '\r')
  c=$(printf '%s\n' "$CAB" | awk 'NR == 1 {print $2}')
  cc=$(printf '%s\n' "$CAB" | awk -F': *' 'tolower($1) == "cache-control" {print $2}' | tail -1)
  echo "   HEAD $p sin token → $c · Cache-Control: ${cc:-(ninguna)}"
  [ "$c" = 401 ] || desvio "HEAD $p respondió $c (esperado 401)"
  [ "$cc" = "no-store, private" ] || desvio "HEAD $p: Cache-Control «${cc:-ninguna}» (esperado «no-store, private»)"
done
# Las 4 públicas, sin credencial: 401 cada una, antes de leer el cuerpo (E6-4: 4 fallos en su cubo, lejos de 30/min).
for p in webhooks/voice/elevenlabs/initiation webhooks/voice/elevenlabs/post-call voice/tools/consultar_planes; do
  r=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Accept: application/json' -H 'Content-Type: application/json' --data '{}' "$API/$p")
  echo "   POST $p sin credencial → $r"; [ "$r" = 401 ] || desvio "POST $p sin credencial respondió $r (esperado 401)"
done
r=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Accept: application/json' --data 'AccountSid=x' "$API/webhooks/voice/twilio/usage-trigger")
echo "   POST webhooks/voice/twilio/usage-trigger sin firma → $r"; [ "$r" = 401 ] || desvio "el aviso de gasto de la voz sin firma respondió $r (esperado 401)"
# El tope de cuerpo va ANTES de autenticar (D8-16): 9 000 bytes a la iniciación (tope 8 KB) → 413 sin mirar nada.
GRANDE=$(python3 -c 'import json; print(json.dumps({"caller_id": "x" * 9000}))')
r=$(printf '%s' "$GRANDE" | curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Accept: application/json' -H 'Content-Type: application/json' --data-binary @- "$API/webhooks/voice/elevenlabs/initiation")
echo "   POST iniciación con 9 000 bytes sin credencial → $r (tope de 8 KB antes de autenticar)"; [ "$r" = 413 ] || desvio "la iniciación con 9 000 bytes respondió $r (esperado 413)"
GRANDE=$(python3 -c 'import json; print(json.dumps({"label": "x" * 70000}))')
R413=$(printf '%s' "$GRANDE" | curl -s -o - -w ' %{http_code}' -X POST -H 'Accept: application/json' -H 'Content-Type: application/json' --data-binary @- "$API/admin/ultron-studio/versions" | tail -c 200)
echo "   POST versions con 70 000 bytes sin token → ${R413##* } $(printf '%s' "$R413" | grep -o '"code":"[a-z_]*"' || true)"
case "$R413" in *'"code":"payload_too_large"'*' 413') ;; *) desvio "el cuerpo de 70 000 bytes sin sesión no dio 413 payload_too_large" ;; esac
c=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Accept: application/json' "$API/webhooks/twilio/usage-trigger")
echo "   usage-trigger del OTP sin firma → $c (falla cerrada; T-1, intacto)"; [ "$c" = 403 ] || desvio "usage-trigger del OTP sin firma respondió $c (esperado 403)"
FALLIDAS=$($PSQL -c "SELECT count(*) FROM failed_jobs;")
echo "   colas fallidas: $FALLIDAS (antes $FALLIDAS_ANTES)"; [ "$FALLIDAS" -le "$FALLIDAS_ANTES" ] || desvio "colas fallidas nuevas durante el despliegue"
SELLOS=$($PSQL -c "SELECT count(*) FROM marketing_ai_actions WHERE metadata ? 'studio';")
case "$PREVIA_ANTES" in
  *'"engine_scope":"off"'*) echo "   sellos studio en marketing_ai_actions (motor en off): $SELLOS"; [ "$SELLOS" = 0 ] || desvio "hay sellos del Studio con el motor en off" ;;
  *) echo "   sellos studio en marketing_ai_actions (motor leyendo; informativo): $SELLOS_ANTES → $SELLOS" ;;
esac
artisan security:check-secret-exposure > /dev/null 2>&1 && echo "   security:check-secret-exposure: OK" || desvio "security:check-secret-exposure no pasa"
artisan ultron-studio:publication-check > /dev/null 2>&1 && echo "   ultron-studio:publication-check: 0 (cuadra)" || desvio "ultron-studio:publication-check no sale con 0"
if artisan ultron-studio:monitor --check=publication --dry-run > /dev/null 2>&1; then echo "   ultron-studio:monitor --dry-run (publicación): 0"; else desvio "ultron-studio:monitor --check=publication --dry-run no sale con 0"; fi
MONI=$(monitor_integraciones)
echo "   monitor en seco, integraciones: [$MONI] (antes [$MONI0])"
[ "$MONI" = "$MONI0" ] || desvio "el veredicto del monitor del H7 sobre las integraciones cambió con el despliegue: antes [$MONI0] · después [$MONI]"
case ",$MONI," in *",config_drift:"*) desvio "el monitor ve deriva de la configuración autorizada tras el despliegue: la fase 1 no tiene que cambiar ninguna huella (S8-b 9)" ;; esac
INCID=$($PSQL -c "SELECT count(*) FROM incidents WHERE source = 'ultron_studio';")
[ "$INCID" = "$INCID_ANTES" ] && echo "   incidentes ultron_studio: $INCID (los mismos)" || desvio "incidentes ultron_studio: $INCID_ANTES → $INCID durante el despliegue"
P="^[[:space:]]*(export[[:space:]]+)?($CLAVES_H8)[[:space:]]*="
if cmp -s <(grep -vE "$P" "$ENVF" | sed '/^$/d') <(grep -vE "$P" "$BK/env.backup" | sed '/^$/d'); then echo "   fichero de entorno igual al respaldo salvo las 9 claves del Hito 8"; else desvio "el fichero de entorno difiere del respaldo en algo más que las claves del Hito 8 (comparar a mano sin imprimir valores)"; fi
N=0; for kv in $VARS; do if [ "$(estado_var "${kv%%=*}" "${kv#*=}")" = igual ]; then N=$((N + 1)); fi; done
[ "$N" = 9 ] || desvio "solo $N de las 9 claves del Hito 8 están una vez y con su valor"
echo "10. voice:doctor --telefonia (sin red; la telefonía dice ELEVENLABS_UNVERIFIED a propósito hasta la fase 3, E4-6):"
TEL=$(artisan voice:doctor --telefonia --json 2>/dev/null | python3 -c '
import json, sys
try:
    d = json.load(sys.stdin)
    t = d["telefonia"]
    f = t["flags"]
    pub = t.get("publication") or {}
    l = t["limits"]
    print("estado=%s constante=%s encendidas=%d cadena=%s freno=%s cupos=%s/%s/%s numero=%s subcuenta=%s proveedor=%s" % (
        d.get("state"), "abierta" if t.get("callsEnabledInCode") else "cerrada", sum(1 for v in f.values() if v is True),
        ",".join("%s:%s" % (k, (pub.get(k) or {}).get("status")) for k in ("canary", "rest")),
        {True: "echado", False: "suelto"}.get(t.get("brakeOpen"), "?"), l.get("outboundDailyCap"), l.get("inboundDailyCap"),
        l.get("dailyBudgetMicros"), "si" if (t.get("number") or {}).get("configured") else "no",
        "si" if t.get("carrierConfigured") else "no", t.get("provider")))
except Exception:
    print("?")' 2>/dev/null || echo '?')
echo "   $TEL"
[ "$TEL" = "estado=ELEVENLABS_UNVERIFIED constante=cerrada encendidas=0 cadena=canary:none,rest:none freno=suelto cupos=0/0/0 numero=no subcuenta=no proveedor=ELEVENLABS_UNVERIFIED" ] \
  || desvio "voice:doctor --telefonia no es el de la fase 1 (esperado: estado=ELEVENLABS_UNVERIFIED constante=cerrada encendidas=0 cadena=canary:none,rest:none freno=suelto cupos=0/0/0 numero=no subcuenta=no proveedor=ELEVENLABS_UNVERIFIED)"
echo "11. integrations-doctor e integrations-snapshot (E1-12; S8-b 9: la huella de voz no cambia):"
if artisan ultron-studio:integrations-doctor --expect="$EXPECT_FASE1" > /dev/null 2>&1; then
  echo "   twilio connected_otp_only · twilio_voice not_configured · elevenlabs ELEVENLABS_VERIFIED: 0"
else
  desvio "integrations-doctor no confirma $EXPECT_FASE1"
fi
if artisan ultron-studio:integrations-doctor --expect=meta_ads:connected_read > /dev/null 2>&1; then
  echo "   meta_ads connected_read: 0"
elif artisan ultron-studio:integrations-doctor --expect=meta_ads:degraded > /dev/null 2>&1; then
  echo "   INFO: meta_ads sale degraded (dato viejo, pasada fallida o sincronización apagada): se informa, no es un fallo del despliegue"
else
  desvio "meta_ads no es connected_read ni degraded"
fi
if artisan ultron-studio:integrations-snapshot > /dev/null 2>&1; then
  INST=$($PSQL -c "SELECT count(*) FROM ultron_studio_integration_snapshots;")
  echo "   instantáneas: $INST_ANTES → $INST"
  [ "$INST" = "$INST_ANTES" ] || desvio "integrations-snapshot anotó $((INST - INST_ANTES)) instantánea(s): la huella de alguna integración cambió con la fase 1 (S8-b 9 dice que no; revisar GET integrations/config antes de seguir)"
else
  desvio "ultron-studio:integrations-snapshot no salió con 0 (ejecutarlo a mano como www-data para ver por qué)"
fi
ERRORES=0; BLOQUEOS=0
for f in "$LOGS"/laravel*.log; do
  [ -f "$f" ] || continue
  n=$(printf '%s\n' "$LOG_ANTES" | awk -v f="$f" '$1 == f {print $2}')
  e=$(tail -n +"$(( ${n:-0} + 1 ))" "$f" | grep -c 'production.ERROR' || true)
  l=$(tail -n +"$(( ${n:-0} + 1 ))" "$f" | grep -c 'production.ERROR: SQLSTATE\[55P03\]' || true)
  ERRORES=$((ERRORES + e)); BLOQUEOS=$((BLOQUEOS + l))
done
ESPERADOS=$(( INTENTO > 1 ? (BLOQUEOS < INTENTO - 1 ? BLOQUEOS : INTENTO - 1) : 0 ))
ERRORES=$((ERRORES - ESPERADOS))
echo "12. laravel.log ERROR desde el inicio del despliegue: $ERRORES$([ "$ESPERADOS" = 0 ] || echo " (más $ESPERADOS del 55P03 reintentado en el paso 3, esperados)")"; [ "$ERRORES" = 0 ] || desvio "$ERRORES errores en el log desde el inicio"
[ "$(g -C /var/www/api status --porcelain --untracked-files=no | wc -l | tr -d ' ')" = 0 ] || desvio "el checkout tiene cambios tras el despliegue"
echo "   registro de esta ejecución: $LOG"
if [ "$DESVIOS" = 0 ]; then echo "DESPLIEGUE H8 FASE 1 (BACKEND) COMPLETADO"; else echo "DESPLIEGUE H8 FASE 1 (BACKEND) CON $DESVIOS DESVÍOS: no seguir con el CRM ni con el smoke hasta aclararlos"; exit 1; fi

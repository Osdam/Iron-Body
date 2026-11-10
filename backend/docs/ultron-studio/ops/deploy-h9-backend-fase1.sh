#!/bin/bash
# Fase 1 del Hito 9 (automatización comercial omnicanal: gobierno de las estrategias y ejecución outreach) en
# producción: el CÓDIGO y las MIGRACIONES con TODO APAGADO. Contrato ULTRON_STUDIO_H9_CONTRACT.md, §9 (banderas y
# vuelta atrás), §9.3 (aislamiento), §11 (OPS9), §14 (fase 1), L9-6, L9-29, L9-34; plans/informes-h9/informe-db.md
# §5, §13 y §15; L9-43, L9-57, L9-59 y L9-63 (OPS9-b). Lo que entra, todo bajo /api/admin/ultron-studio: las 30 rutas
# del gobierno (strategies/**, S9), el canal SSE (stream, SSE9) y las 13 de la ejecución (outreach/**, B9-run: 9 GET y
# 4 POST) → el Studio pasa de 77 a 121 rutas (StudioRouteCount::TOTAL = 77 + 30 + 1 + 13); 3 migraciones (15 tablas
# vacías con sus guardas y 4 índices de lectura sobre tablas que ya existen) y el bloque del H9 de routes/console.php
# (3 tareas detrás de COMMERCIAL_AUTOMATION_SCHEDULER_ENABLED; apagada, 0). Lo que NO cambia: OutreachSendGate::SENDS_ENABLED_IN_CODE sigue en false; ninguna
# bandera COMMERCIAL_AUTOMATION_* ni ULTRON_STUDIO_STRATEGIES_* se enciende; el motor comercial (COMMERCIAL_EVENTS/NBA/
# AUTONOMY), ULTRON Legacy, el WhatsApp de producción, n8n, el OTP y la voz del H8 no se tocan (puertas de diff del
# paso 0, §9.3).
# Se ejecuta EN EL SERVIDOR como root, tras el respaldo. Orden:
#   0. comprobaciones de solo lectura (abortan sin tocar nada; con --solo-comprobar se termina aquí)
#   1. código (git como root con umask 022, merge --ff-only) y lectura por www-data de cada fichero del merge
#   2. las 15 variables de la fase 1 (todas las banderas de §9.1 apagadas o en 0, y la puerta de simulación en
#      `required`) ANTES de migrar, y el fichero de entorno parsea
#   3. ironbody-migrate (lo que falte del H9); un 55P03 se reintenta hasta 3 veces; si el esquema no da
#      123/137/9/25 + 51/75/3/8, ABORTA SIN route:cache (las rutas nuevas no se sirven con tablas sin guardas). Si las
#      del H9 ya estaban aplicadas por otro, no migra: lo dice y comprueba el esquema. La 100003 (índices CONCURRENTLY,
#      L9-29) es un PASO APARTE en hora de poco tráfico (--indices, abajo): si ironbody-migrate la aplica aquí porque
#      estaba pendiente, se comprueba; si cae por lock_timeout, el despliegue sigue (son índices de lectura: el código
#      funciona sin ellos) y lo dice
#   4. aserciones del esquema (recuentos de L9-34, 33 funciones del dueño sin SECURITY DEFINER, ninguna guarda apagada, F
#      intacta, el libro con 11 sujetos, dueños, privilegios de iron_app uno a uno, 0 filas, las tablas del H5–H8 iguales)
#   5. config:cache y route:cache como www-data con umask 077, cachés www-data 600 y queue:restart
#   6. rutas (Studio = StudioRouteCount::TOTAL del código desplegado: 30 de strategies, 13 de la ejecución y el canal
#      SSE) y auth:coverage --unmapped vacío
#   7. configuración efectiva: TODO el H9 apagado, la constante de envío cerrada, el motor/Legacy/H4–H8 iguales, el
#      programador con el mismo total y 0 tareas outreach:* · 8. workers como antes, dos lecturas
#   9. sanidad HTTP (401 y no-store sin credencial en las rutas nuevas, 413 temprano en el tope de 64 KB, OTP 403),
#      colas, secretos, publication-check, el monitor en seco sin deriva nueva, el entorno = respaldo salvo las 15 claves
#  10. integrations-snapshot sin cambios (la fase 1 no cambia ninguna huella) · 11. ERROR nuevos en el log
# Cada aserción que falla se dice y el script termina con 1; «DESPLIEGUE H9 FASE 1 (BACKEND) COMPLETADO» solo sale con 0
# desvíos. Nunca imprime valores del fichero de entorno, ni secretos, ni la salida de un tinker que no case con lo
# esperado. Todo lo que imprime queda además en /root/h9-fase1-<fecha UTC>.log (600, root).
# Uso: bash /root/deploy-h9-backend-fase1.sh <commit esperado (7 cifras)> <directorio del respaldo de hoy> [--solo-comprobar] [--acepta-main=<commit>[,<commit>…]]
#      bash /root/deploy-h9-backend-fase1.sh <commit esperado (7 cifras)> <directorio del respaldo de hoy> --indices [--acepta-hora]
#   --solo-comprobar: ejecuta solo el paso 0 (lecturas y git fetch) y sale; es el ensayo en el servidor.
#   --acepta-main=…: los commits AJENOS al H9 (de origin/main) que producción no tiene y que viajarían con el merge, que
#     el propietario acepta desplegar con el H9 EN ESE MOMENTO. Sin la bandera, el paso 0 los lista y el despliegue
#     aborta; un commit aceptado que no esté entre ellos también aborta.
#   --indices: el PASO APARTE de la 100003 (informe-db.md §13, L9-29): con la fase 1 ya desplegada (HEAD = el commit
#     esperado y las 100001/100002 registradas), comprueba que no hay transacciones largas, enseña el tamaño de las 4
#     tablas, aplica solo lo que falta (la 100003, con lock_timeout 60 s: CONCURRENTLY espera sin bloquear a nadie) y
#     comprueba los 4 índices válidos. Entre las 22:00 y las 06:00 de Bogotá; a otra hora exige --acepta-hora.
# VUELTA ATRÁS (ULTRON_STUDIO_DEPLOYMENT_PLAN.md, «Hito 9», y ULTRON_STUDIO_ROLLBACK.md): la fase 1 no enciende nada,
# así que no hay bandera que bajar (nivel 0 de §9.2 ya cumplido). Código (nivel 3): `git checkout -q --detach $(cat
# $BK/api-commit.txt)` como root con umask 022 (solo si producción sigue en el commit del H9), config:cache y
# route:cache como www-data, queue:restart. Las 15 tablas QUEDAN (vacías y sin lector; down() se niega con filas):
# NUNCA `migrate:rollback` en producción; quitarlas es de database-specialist con autorización del propietario. Los 4
# índices de la 100003 se pueden retirar con `DROP INDEX CONCURRENTLY` sin tocar datos. Volver el código no es volver
# los datos.
set -euo pipefail
export LC_ALL=C
umask 022
g() { git "$@"; }
uso() { echo "uso: bash /root/deploy-h9-backend-fase1.sh <commit esperado (7 cifras)> <directorio del respaldo de hoy> [--solo-comprobar] [--acepta-main=<commit>[,<commit>…]] | --indices [--acepta-hora]"; }
[ "$#" -ge 2 ] && [ "$#" -le 4 ] || { uso; echo "no se ha tocado nada"; exit 2; }
ESPERADO="$1"
BK="$2"
shift 2
SOLO_COMPROBAR=""
ACEPTA=""
INDICES=""
ACEPTA_HORA=""
for a in "$@"; do
  case "$a" in
    --solo-comprobar) [ -z "$SOLO_COMPROBAR" ] || { echo "--solo-comprobar repetido; no se ha tocado nada"; exit 2; }; SOLO_COMPROBAR=--solo-comprobar ;;
    --indices) [ -z "$INDICES" ] || { echo "--indices repetido; no se ha tocado nada"; exit 2; }; INDICES=--indices ;;
    --acepta-hora) ACEPTA_HORA=--acepta-hora ;;
    --acepta-main=*) [ -z "$ACEPTA" ] || { echo "--acepta-main repetido; no se ha tocado nada"; exit 2; }
                     ACEPTA=${a#--acepta-main=}
                     [[ "$ACEPTA" =~ ^[0-9a-f]{7}(,[0-9a-f]{7})*$ ]] || { echo "--acepta-main va con commits de 7 cifras separados por comas (los que imprime el paso 0); no se ha tocado nada"; exit 2; } ;;
    *) echo "argumento desconocido: $a (se admiten --solo-comprobar, --acepta-main=<commit>[,…], --indices y --acepta-hora); no se ha tocado nada"; exit 2 ;;
  esac
done
[ -z "$INDICES" ] || [ -z "$SOLO_COMPROBAR$ACEPTA" ] || { echo "--indices no se combina con --solo-comprobar ni con --acepta-main; no se ha tocado nada"; exit 2; }
[ -n "$INDICES" ] || [ -z "$ACEPTA_HORA" ] || { echo "--acepta-hora solo va con --indices; no se ha tocado nada"; exit 2; }
[[ "$ESPERADO" =~ ^[0-9a-f]{7}$ ]] || { echo "el commit esperado va con 7 cifras hexadecimales (git rev-parse --short=7 origin/main); no se ha tocado nada"; exit 2; }
[ "$(id -u)" = 0 ] || { echo "se ejecuta como root en el servidor; no se ha tocado nada"; exit 2; }
# PREVIO = lo que sirve producción ANTES del H9. L9-34: primero se confirmó y desplegó el cierre del H8 (despliegue 1b,
# E16-30/E16-31: producción está en a608450 desde el 09-11-2026) y la rama del H9 nace desde el HEAD de main. Fijado por
# OPS9-b (L9-63): si producción no está en a608450 ni en el commit esperado, el paso 0 aborta («pendiente» solo vale con
# --solo-comprobar: lee el HEAD y lo imprime para fijarlo en un commit). Entre a608450 y el HEAD del que nace la rama hay
# dos commits de documentación del H8 (a921fbb y 307ad51, solo docs/): el paso 0 los lista como ajenos al H9 y el
# propietario los acepta con --acepta-main (como el 7c84cb5 del H8) o la rama del H9 los deja detrás.
PREVIO=a608450
[[ "$PREVIO" =~ ^([0-9a-f]{7}|pendiente)$ ]] || { echo "PREVIO no es un commit de 7 cifras ni «pendiente» ($PREVIO); no se ha tocado nada"; exit 2; }
[ "$PREVIO" != pendiente ] || [ "$SOLO_COMPROBAR" = --solo-comprobar ] || [ -n "$INDICES" ] \
  || { echo "PREVIO sin fijar: leerlo en el servidor con --solo-comprobar y fijarlo en un commit (ver la cabecera); no se ha tocado nada"; exit 2; }
MIG_H9_1=2026_11_12_100001_create_ultron_studio_strategy_tables
MIG_H9_2=2026_11_12_100002_create_outreach_tables
MIG_H9_3=2026_11_12_100003_add_outreach_read_indexes
MIGRACIONES="backend/database/migrations/$MIG_H9_1.php backend/database/migrations/$MIG_H9_2.php backend/database/migrations/$MIG_H9_3.php "
# Puerta de diff (§9.3 del contrato H9, más las de los hitos anteriores): ULTRON Legacy (Marketing), el motor comercial
# (Commercial: solo lecturas desde Facts, ninguna escritura ni cambio), n8n (Automation), el OTP (Sms, Otp, config/otp.php),
# la voz del H8 (Voice y Telephony: CERO diff), Meta, el catálogo congelado, las dependencias y los ficheros de rutas y
# configuración ajenos. La cruza SOLO routes/console.php (OPS9: solo AÑADE el bloque del H9). routes/api.php es
# INTOCABLE (L9-43 y B-2 de SEC9: el canal SSE vive en routes/ultron-studio.php y su controlador en Crm/UltronStudio,
# fuera de la puerta). Un fichero de la puerta que no esté aquí ABORTA: si es de otro equipo, se despliega antes por su
# vía. composer.json/lock en la puerta: el H9 no cambia dependencias PHP; si cambian, no es del H9 y este script NO
# ejecuta composer en producción (supply chain y tiempo): aborta.
PUERTA=(backend/app/Services/Marketing backend/app/Services/Commercial backend/app/Services/Automation backend/app/Jobs
        backend/app/Http/Controllers/Api backend/app/Services/Sms backend/app/Services/Otp backend/app/Services/Voice
        backend/app/Services/Telephony backend/routes/api.php backend/routes/marketing.php backend/routes/voice.php
        backend/routes/console.php backend/config/marketing.php backend/config/meta.php backend/config/otp.php
        backend/config/commercial.php backend/config/automation.php backend/config/voice.php
        backend/app/Services/UltronStudio/StudioCatalog.php backend/composer.json backend/composer.lock)
# backend/app/Jobs está en la puerta por los emisores hacia n8n (SendAutomationEventToN8n, SendUltronEventToN8n): el único
# fichero del H9 ahí es el trabajo del carril `commercial` de B9-run (L9-58), inerte hasta la fase en vivo (el programador
# despacha --inline) y cubierto por las guardas de aislamiento de SEC9 (app/Jobs/Outreach). Se admite ese fichero EXACTO:
# cualquier otro trabajo nuevo o tocado sigue abortando.
PERMITIDOS_EN_PUERTA="backend/routes/console.php
  backend/app/Jobs/Outreach/DispatchOutreachJob.php"
PERMITIDOS_EN_PUERTA=$(printf '%s\n' $PERMITIDOS_EN_PUERTA | tr '\n' ' ')
# «Solo añade»: git diff --numstat PREVIO..origin/main no puede restar ni una línea. En el árbol integrado da 48 0: el
# bloque del programador y nada más (el lead revirtió el reformateo de Pint, M-2a de REV9).
SOLO_ANADEN="backend/routes/console.php"
HUELLA_F=4ad748b5f17f09b398f7f641c197d7ee        # md5(pg_get_functiondef) de ultron_studio_forbid(): la de F (H5–H9)
RUTAS_STRATEGIES=30                                # S9 (§7 + la simulación del borrador): STUDIO_ROUTE_COUNT_WITH_STRATEGIES = 77 + 30
RUTAS_EJECUCION=13                                 # B9-run (§7, E1–E13, L9-57): admin/ultron-studio/outreach/** = UltronStudioOutreachController::ROUTE_COUNT
RUTAS_EJECUCION_GET=9                              # status, metrics, enrollments, enrollments/{uuid}, decisions/{uuid}, jobs, brakes, people/{ref}/timeline, assisted/queue
RUTAS_EJECUCION_POST=4                             # brakes, brakes/{uuid}/release, assisted/{uuid}/claim, assisted/{uuid}/outcome
RUTAS_STREAM=1                                     # SSE9 (L9-43): admin/ultron-studio/stream = UltronStudioStreamController::ROUTE_COUNT
API=https://api.ironbodyneiva.cloud/api
PSQL="sudo -u postgres psql -X -Atq -v ON_ERROR_STOP=1 -d ironbody"
LOGDIR=/root
TS=$(date -u +%Y%m%d-%H%M%S)
if [ -n "$INDICES" ]; then LOG="$LOGDIR/h9-indices-$TS.log"; elif [ "$SOLO_COMPROBAR" = --solo-comprobar ]; then LOG="$LOGDIR/h9-fase1-comprobar-$TS.log"; else LOG="$LOGDIR/h9-fase1-$TS.log"; fi
( umask 077; : > "$LOG" ) || { echo "no se pudo crear el registro $LOG; no se ha tocado nada"; exit 2; }
exec > >(tee -a "$LOG") 2>&1
echo "== deploy-h9-backend-fase1.sh · $(date -u +%Y-%m-%dT%H:%M:%SZ) · ESPERADO=$ESPERADO · PREVIO=$PREVIO · BK=$BK${SOLO_COMPROBAR:+ · $SOLO_COMPROBAR}${INDICES:+ · $INDICES} · registro $LOG"
aborta() { echo "ABORTA: $*"; exit 1; }
DESVIOS=0
desvio() { echo "   ✗ $*"; DESVIOS=$((DESVIOS + 1)); }
artisan() { ( cd /var/www/api/backend && sudo -u www-data php artisan "$@" ); }
# Las 15 variables de la fase 1, explícitas aunque coincidan con el defecto (protección 1 del contrato: todo nace
# apagado): las 11 de la ejecución (§9.1, config/outreach.php) y las 4 del gobierno y el canal SSE que tienen defecto
# «apagado» (publicar, la maestra del gobierno, la puerta de simulación en `required` y el stream). La ventana, el canario
# mínimo y el horario propio quedan en su defecto del código (07:00-19:00, 24 h / 3 contactos, el legal). Las mueve
# después el mando h9-outreach-scope.sh, nunca a mano.
VARS="COMMERCIAL_AUTOMATION_ENABLED=false COMMERCIAL_AUTOMATION_MODE=off COMMERCIAL_AUTOMATION_SCHEDULER_ENABLED=false COMMERCIAL_AUTOMATION_ASSISTED_ENABLED=false COMMERCIAL_AUTOMATION_ASSISTED_DAILY_CAP=0 COMMERCIAL_AUTOMATION_WHATSAPP_ENABLED=false COMMERCIAL_AUTOMATION_WHATSAPP_DAILY_BUDGET_USD=0 COMMERCIAL_AUTOMATION_WHATSAPP_DAILY_CAP=0 COMMERCIAL_AUTOMATION_VOICE_ENABLED=false COMMERCIAL_AUTOMATION_MONITOR_ENABLED=false COMMERCIAL_AUTOMATION_CLASSIFIER_ENABLED=false ULTRON_STUDIO_STRATEGIES_ENABLED=false ULTRON_STUDIO_STRATEGIES_PUBLISHING_ENABLED=false ULTRON_STUDIO_STRATEGIES_SIMULATION_GATE=required ULTRON_STUDIO_STREAM_ENABLED=false"
N_VARS=$(echo $VARS | wc -w | tr -d ' ')
CLAVES_H9=$(for kv in $VARS; do printf '%s|' "${kv%%=*}"; done); CLAVES_H9=${CLAVES_H9%|}
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
LOGS=/var/www/api/backend/storage/logs
lineas_log() { local f; for f in "$LOGS"/laravel*.log; do [ -f "$f" ] && echo "$f $(wc -l < "$f" | tr -d ' ')"; done; return 0; }
workers() { # <RUNNING esperados> <segundos>
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
# Las rutas en la caché de rutas, en una línea (o «?»): Studio total · strategies/** · ejecución (/api/admin/
# ultron-studio/outreach/**) · sus GET · sus POST · el canal SSE del Studio (/api/admin/ultron-studio/stream).
# Antes: 77 0 0 0 0 0 · después: 121 30 13 9 4 1 (StudioRouteCount::TOTAL del código desplegado, L9-59).
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
e = [x for x in s if u(x).startswith("/api/admin/ultron-studio/strategies")]
c = [x for x in s if u(x).startswith("/api/admin/ultron-studio/outreach/")]
t = [x for x in s if u(x) == "/api/admin/ultron-studio/stream"]
print(len(s), len(e), len(c), sum(1 for x in c if "GET" in m(x)), sum(1 for x in c if m(x) == "POST"), len(t))' 2>/dev/null || echo '?'
}
# El programador por `schedule:list --json`, con su código de salida APARTE (m-1 del H7): «total del_Studio de_voz
# de_outreach», «FALLO <código>» o «ILEGIBLE». De outreach = las órdenes `outreach:` del bloque del H9 de
# routes/console.php, que con COMMERCIAL_AUTOMATION_SCHEDULER_ENABLED apagada no existen.
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
out = [x for x in c if re.search(r"(?<![a-z])outreach:", x)]
print(len(r), sum(1 for x in c if "ultron-studio:" in x and x not in voz), len(voz), len(out))' 2>/dev/null || echo ILEGIBLE
}
# Motor comercial, ULTRON Legacy, el H4–H7 y la voz del H8: no los toca el H9 en la fase 1; se leen antes y después y
# tienen que ser iguales. Todo existe ya en el código del H8.
leer_previa() {
  local l
  l=$(artisan tinker --execute='$p = "ultron_studio.integrations."; echo json_encode(["engine_scope" => config("marketing.ultron.studio_config"), "ultron_enabled" => config("marketing.ultron.enabled"), "canary_set" => config("marketing.ultron.canary_conversation_id") !== null, "followups_scheduler" => config("marketing.followups.scheduler_enabled"), "commercial_nba" => config("commercial.enabled"), "commercial_events" => config("commercial.events_enabled"), "commercial_autonomy" => config("commercial.autonomy_enabled"), "publishing_enabled" => config("ultron_studio.publishing_enabled"), "lab_gate" => config("ultron_studio.publishing.lab_gate"), "lab_enabled" => config("ultron_studio.lab.enabled"), "writes_enabled" => config($p."writes_enabled"), "probe_enabled" => config($p."twilio_probe.enabled"), "monitor_enabled" => config("ultron_studio.monitor.enabled"), "monitor_expect" => config("ultron_studio.monitor.expect"), "voice_publishing" => config("ultron_studio.voice.publishing_enabled"), "voice_inbound" => config("voice.inbound.enabled"), "voice_outbound" => config("voice.outbound.enabled"), "voice_monitor" => config("voice.monitor.enabled"), "voice_lab" => config("voice.lab.enabled"), "twilio_voice" => config("voice.twilio.enabled"), "elevenlabs_enabled" => config("voice.elevenlabs.enabled"), "calls_in_code" => App\Services\Voice\VoiceCallPolicy::CALLS_ENABLED_IN_CODE, "studio_voice" => App\Services\UltronStudio\StudioIntegrations::voiceState()]), PHP_EOL;' 2>&1 | tail -1) || true
  case "$l" in '{"engine_scope"'*) printf '%s\n' "$l" ;; esac
}
# La configuración efectiva del H9: banderas, topes y la constante de envío (buscada por su fichero: B9-run decide el
# subdirectorio de OutreachSendGate). Antes del merge las claves del H9 no existen (salen null): solo es informativa.
leer_h9() {
  local l
  l=$(artisan tinker --execute='$o = "outreach."; $s = "ultron_studio.strategies."; $g = null; foreach (array_merge(glob(app_path("Services/Outreach/OutreachSendGate.php")) ?: [], glob(app_path("Services/Outreach/*/OutreachSendGate.php")) ?: []) as $f) { $c = "App\\" . str_replace("/", "\\", substr($f, strlen(app_path()) + 1, -4)); if (class_exists($c) && defined($c . "::SENDS_ENABLED_IN_CODE")) { $g = $c; break; } } echo json_encode(["deployed" => is_array(config("outreach")), "enabled" => config($o."enabled"), "mode" => config($o."mode"), "scheduler" => config($o."scheduler.enabled"), "monitor" => config($o."monitor.enabled"), "assisted" => config($o."assisted.enabled"), "assisted_cap" => config($o."assisted.daily_cap"), "whatsapp" => config($o."whatsapp.enabled"), "whatsapp_cap" => config($o."whatsapp.daily_cap"), "whatsapp_budget" => config($o."whatsapp.daily_budget_usd") === null ? null : (string) config($o."whatsapp.daily_budget_usd"), "voice" => config($o."voice.enabled"), "classifier" => config($o."classifier.enabled"), "sends_in_code" => $g === null ? null : constant($g . "::SENDS_ENABLED_IN_CODE"), "sends_gate" => $g, "strategies_deployed" => is_array(config("ultron_studio.strategies")), "strategies" => config($s."enabled"), "strategies_publishing" => config($s."publishing_enabled"), "simulation_gate" => config($s."simulation_gate"), "stream_deployed" => is_array(config("ultron_studio_stream")), "stream" => config("ultron_studio_stream.enabled"), "studio_routes_const" => defined("App\\Http\\Controllers\\Crm\\UltronStudio\\UltronStudioStrategyController::STUDIO_ROUTE_COUNT_WITH_STRATEGIES") ? App\Http\Controllers\Crm\UltronStudio\UltronStudioStrategyController::STUDIO_ROUTE_COUNT_WITH_STRATEGIES : null, "studio_routes_total" => defined("App\\Http\\Controllers\\Crm\\UltronStudio\\StudioRouteCount::TOTAL") ? App\Http\Controllers\Crm\UltronStudio\StudioRouteCount::TOTAL : null]), PHP_EOL;' 2>&1 | tail -1) || true
  case "$l" in '{"deployed"'*) printf '%s\n' "$l" ;; esac
}
# ¿Es la del H9 apagado? Campo a campo (los nombres de las claves de config/outreach.php son de B9-run: si uno falta,
# sale null y se dice cuál).
h9_apagado() { # JSON → «OK» o «MAL campo=valor …»
  printf '%s' "$1" | python3 -I -c '
import json, sys
d = json.load(sys.stdin)
esp = {"deployed": True, "enabled": False, "mode": "off", "scheduler": False, "monitor": False, "assisted": False, "assisted_cap": 0,
       "whatsapp": False, "whatsapp_cap": 0, "voice": False, "classifier": False, "sends_in_code": False,
       "strategies_deployed": True, "strategies": False, "strategies_publishing": False, "simulation_gate": "required"}
mal = ["%s=%s" % (k, json.dumps(d.get(k))) for k, v in esp.items() if d.get(k) != v]
b = d.get("whatsapp_budget")
if b is None or float(b) != 0.0:
    mal.append("whatsapp_budget=%s" % json.dumps(b))
if d.get("stream_deployed") is True and d.get("stream") is not False:
    mal.append("stream=%s" % json.dumps(d.get("stream")))
print("OK" if not mal else "MAL " + " ".join(mal))' 2>/dev/null || echo "MAL ilegible"
}
# El monitor del H7 en seco, solo las integraciones: sus problemas ordenados (o «ok», o «?»). No abre incidentes.
monitor_integraciones() {
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
GOBIERNO_H9="'ultron_studio_person_bindings', 'ultron_studio_strategies', 'ultron_studio_message_templates', 'ultron_studio_strategy_versions', 'ultron_studio_strategy_simulations', 'ultron_studio_strategy_reviews', 'ultron_studio_strategy_publications'"
H9_REL="(c.relname LIKE 'outreach\_%' OR c.relname IN ($GOBIERNO_H9))"
FUNC_REL="(proname LIKE 'outreach\_%' OR proname LIKE 'ultron\_studio\_chk\_%' OR proname = 'ultron_studio_strategy_publication_chain')"
# Una línea: disparadores · CHECK · parciales · tablas de ultron_studio_* y de outreach_*.
Q_ESQUEMA="SELECT (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron\_studio\_%')||' '||(SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE 'ultron\_studio\_%')||' '||(SELECT count(*) FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid WHERE t.relname LIKE 'ultron\_studio\_%' AND i.indpred IS NOT NULL)||' '||(SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'ultron\_studio\_%')||' '||(SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'outreach\_%')||' '||(SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE 'outreach\_%')||' '||(SELECT count(*) FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid WHERE t.relname LIKE 'outreach\_%' AND i.indpred IS NOT NULL)||' '||(SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'outreach\_%');"
ESQ_ANTES="68 84 6 18 0 0 0 0"                      # el H8 en producción (a608450: el despliegue 1b del 09-11-2026 fue solo de código; el esquema es el de fe29126)
ESQ_MEDIO="123 137 9 25 0 0 0 0"                    # la 100001 aplicada y la 100002 no (cayó por 55P03 o se cortó)
ESQ_DESPUES="123 137 9 25 51 75 3 8"
# El escritor de identities choca con la FK de person_bindings de la 100001 (SHARE ROW EXCLUSIVE, L9-6); un lector, no.
Q_BLOQ="SELECT count(*) FROM pg_locks l JOIN pg_class c ON c.oid = l.relation WHERE c.relname = 'identities' AND l.pid <> pg_backend_pid() AND l.mode NOT IN ('AccessShareLock', 'RowShareLock');"
Q_LARGAS="SELECT count(*) FROM pg_stat_activity a WHERE a.xact_start < now() - interval '30 seconds' AND a.pid <> pg_backend_pid();"
Q_HUELLA="SELECT md5(pg_get_functiondef(oid)) FROM pg_proc WHERE proname = 'ultron_studio_forbid';"
Q_MIG_H9="SELECT coalesce(string_agg(migration, ' ' ORDER BY migration), '') FROM migrations WHERE migration IN ('$MIG_H9_1', '$MIG_H9_2', '$MIG_H9_3');"
Q_MIGRACIONES="SELECT migration FROM migrations;"
Q_FUNC="SELECT count(*)||' '||count(*) FILTER (WHERE prosecdef)||' '||count(DISTINCT pg_get_userbyid(proowner))||' '||coalesce(min(pg_get_userbyid(proowner)), '') FROM pg_proc WHERE $FUNC_REL;"
FUNC_ESPERADO="33 0 1 iron"
Q_APAGADOS="SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND (c.relname LIKE 'outreach\_%' OR c.relname LIKE 'ultron\_studio\_%') AND t.tgenabled <> 'O';"
Q_LIBRO="SELECT (pg_get_constraintdef(oid) LIKE '%person_binding%' AND pg_get_constraintdef(oid) LIKE '%strategy_version%') FROM pg_constraint WHERE conname = 'usae_subject_type_chk';"
Q_INDICES="SELECT count(*) FILTER (WHERE i.indisvalid AND i.indisready)||' '||count(*) FILTER (WHERE NOT (i.indisvalid AND i.indisready)) FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname LIKE '%\_h9\_idx';"
Q_TAMANOS="SELECT string_agg(t||'='||pg_size_pretty(pg_total_relation_size('public.' || t))||'/'||(xpath('/row/n/text()', query_to_xml(format('SELECT count(*) AS n FROM public.%I', t), false, true, '')))[1]::text||'f', ' ') FROM unnest(ARRAY['users', 'payment_transactions', 'class_reservations', 'receivables']) AS t;"
Q_DUENOS_H9="SELECT coalesce(string_agg(c.relname || ':' || pg_get_userbyid(c.relowner), ' ' ORDER BY c.relname COLLATE \"C\"), '') FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind = 'r' AND $H9_REL;"
Q_PRIV_H9="SELECT coalesce(string_agg(c.relname || '=' || CASE WHEN has_table_privilege('iron_app', c.oid, 'SELECT') THEN 'r' ELSE '-' END || CASE WHEN has_table_privilege('iron_app', c.oid, 'INSERT') THEN 'a' ELSE '-' END || CASE WHEN has_any_column_privilege('iron_app', c.oid, 'UPDATE') THEN 'w' ELSE '-' END || CASE WHEN has_table_privilege('iron_app', c.oid, 'DELETE') THEN 'd' ELSE '-' END || CASE WHEN has_table_privilege('iron_app', c.oid, 'TRUNCATE') THEN 'D' ELSE '-' END || CASE WHEN to_regclass('public.' || c.relname || '_id_seq') IS NULL THEN '?' WHEN has_sequence_privilege('iron_app', 'public.' || c.relname || '_id_seq', 'USAGE') THEN 's' ELSE '-' END, ' ' ORDER BY c.relname COLLATE \"C\"), '') FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind = 'r' AND $H9_REL;"
Q_FILAS_H9="SELECT coalesce(string_agg(c.relname || '=' || (xpath('/row/n/text()', query_to_xml(format('SELECT count(*) AS n FROM public.%I', c.relname), false, true, '')))[1]::text, ' ' ORDER BY c.relname COLLATE \"C\"), '') FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE n.nspname = 'public' AND c.relkind = 'r' AND $H9_REL;"
TABLAS_H9="outreach_brakes outreach_budget_counters outreach_cursors outreach_decisions outreach_enrollments outreach_jobs outreach_outcomes outreach_runs ultron_studio_message_templates ultron_studio_person_bindings ultron_studio_strategies ultron_studio_strategy_publications ultron_studio_strategy_reviews ultron_studio_strategy_simulations ultron_studio_strategy_versions"
# ra---s en las 5 de solo inserción (cadena, reseñas, simulaciones, decisiones, resultados); raw--s en las 10 con estado
# (sin DELETE); outreach_cursors no tiene secuencia (clave natural): «?» en la última posición (medido en DB9, §8.4).
PRIV_H9_ESPERADO="outreach_brakes=raw--s outreach_budget_counters=raw--s outreach_cursors=raw--? outreach_decisions=ra---s outreach_enrollments=raw--s outreach_jobs=raw--s outreach_outcomes=ra---s outreach_runs=raw--s ultron_studio_message_templates=raw--s ultron_studio_person_bindings=raw--s ultron_studio_strategies=raw--s ultron_studio_strategy_publications=ra---s ultron_studio_strategy_reviews=ra---s ultron_studio_strategy_simulations=ra---s ultron_studio_strategy_versions=raw--s"
DUENOS_ESPERADO=$(for t in $TABLAS_H9; do printf '%s:iron ' "$t"; done); DUENOS_ESPERADO=${DUENOS_ESPERADO% }
FILAS_H9_ESPERADAS=$(for t in $TABLAS_H9; do printf '%s=0 ' "$t"; done); FILAS_H9_ESPERADAS=${FILAS_H9_ESPERADAS% }
# Los privilegios de iron_app en las tablas del H5–H8 (el H9 no los toca: antes = después) y las filas del Studio.
Q_PRIV_PREVIAS="SELECT string_agg(t||'='||has_table_privilege('iron_app', 'public.' || t, 'SELECT')||'/'||has_table_privilege('iron_app', 'public.' || t, 'INSERT')||'/'||has_any_column_privilege('iron_app', 'public.' || t, 'UPDATE')||'/'||has_table_privilege('iron_app', 'public.' || t, 'DELETE')||'/'||has_table_privilege('iron_app', 'public.' || t, 'TRUNCATE'), ' ' ORDER BY t) FROM unnest(ARRAY['ultron_studio_publications', 'ultron_studio_audit_events', 'ultron_studio_integration_snapshots', 'ultron_studio_campaign_links', 'ultron_studio_canary_numbers', 'ultron_studio_voice_publications', 'voice_calls', 'voice_brakes']) AS t WHERE to_regclass('public.' || t) IS NOT NULL;"
Q_FILAS_PREVIAS="SELECT (SELECT count(*) FROM ultron_studio_publications)||' '||(SELECT count(*) FROM ultron_studio_audit_events)||' '||(SELECT count(*) FROM ultron_studio_integration_snapshots)||' '||(SELECT count(*) FROM ultron_studio_versions)||' '||(SELECT count(*) FROM ultron_studio_voice_publications);"
# Los trabajos en las colas del H9 (si B9-run usa una cola propia, `outreach`): 0 siempre en la fase 1.
Q_COLA_H9="SELECT CASE WHEN to_regclass('public.jobs') IS NULL THEN 'sin_tabla' ELSE (SELECT count(*) FROM jobs WHERE queue LIKE 'outreach%')::text END;"

# ── --indices: el PASO APARTE de la 100003 (informe-db.md §13, L9-29) ──────────────────────────────────────────────────
if [ -n "$INDICES" ]; then
  echo "0. paso aparte de la 100003 (índices de lectura, CREATE INDEX CONCURRENTLY): comprobaciones previas"
  cd /var/www/api
  ACTUAL=$(g rev-parse --short=7 HEAD)
  [ "$ACTUAL" = "$ESPERADO" ] || aborta "producción está en $ACTUAL, no en $ESPERADO: el paso aparte va DESPUÉS de la fase 1 del H9 (o el commit esperado no es el de hoy)"
  [ "$(g status --porcelain --untracked-files=no | wc -l | tr -d ' ')" = 0 ] || aborta "el checkout de producción tiene cambios locales"
  MIG_REG=$($PSQL -c "$Q_MIG_H9") || aborta "no se pudo leer la tabla migrations (psql)"
  case "$MIG_REG" in
    "$MIG_H9_1 $MIG_H9_2") ;;
    "$MIG_H9_1 $MIG_H9_2 $MIG_H9_3")
      IDX=$($PSQL -c "$Q_INDICES")
      echo "   la $MIG_H9_3 ya está registrada · índices %_h9_idx (válidos · inválidos): $IDX"
      [ "$IDX" = "4 0" ] && { echo "PASO APARTE DE LA 100003: NADA QUE HACER (los 4 índices válidos)"; exit 0; } || aborta "la 100003 consta aplicada pero los índices no son 4 válidos y 0 inválidos ([$IDX]): PARAR y avisar a database-specialist" ;;
    *) aborta "las 100001 y 100002 tienen que estar registradas antes del paso aparte (registro: [$MIG_REG]): desplegar la fase 1" ;;
  esac
  APLICADAS=$($PSQL -c "$Q_MIGRACIONES" | sort -u) || aborta "no se pudo leer la tabla migrations (psql)"
  PENDIENTES=$(comm -23 <(g ls-tree --name-only HEAD -- backend/database/migrations/ | sed -n 's#^backend/database/migrations/\([^/]*\)\.php$#\1#p' | sort -u) <(printf '%s\n' "$APLICADAS") | tr '\n' ' ')
  [ "$PENDIENTES" = "$MIG_H9_3 " ] || aborta "migraciones pendientes frente a la base: [$PENDIENTES] (esperada solo la 100003). ironbody-migrate aplicaría TODAS: lo ajeno se despliega antes por su vía"
  HORA=$(TZ=America/Bogota date +%H)
  if [ "$HORA" -ge 6 ] && [ "$HORA" -lt 22 ]; then
    [ -n "$ACEPTA_HORA" ] || aborta "son las ${HORA}:$(TZ=America/Bogota date +%M) en Bogotá: la 100003 va en hora de poco tráfico (22:00–06:00). CONCURRENTLY no bloquea a nadie, pero lee las 4 tablas enteras y espera a cada transacción abierta; con la orden del propietario, repetir con --acepta-hora"
    echo "   AVISO: ${HORA}:$(TZ=America/Bogota date +%M) en Bogotá, fuera de la franja de poco tráfico; sigue por --acepta-hora"
  else
    echo "   hora en Bogotá: ${HORA}:$(TZ=America/Bogota date +%M) (franja de poco tráfico)"
  fi
  LARGAS=$($PSQL -c "$Q_LARGAS") || aborta "no se pudo leer pg_stat_activity (psql)"
  [ "$LARGAS" = 0 ] || aborta "$LARGAS transacciones abiertas desde hace más de 30 s: CONCURRENTLY esperaría a todas (sin bloquear a nadie, pero el índice tardaría lo que tarden); verlas con h9-schema-check.sql, esperar y repetir"
  TAM0=$($PSQL -c "$Q_TAMANOS")
  IDX0=$($PSQL -c "$Q_INDICES")
  echo "   tamaño total y filas de las 4 tablas que reciben índice: $TAM0 · índices %_h9_idx antes (válidos · inválidos): $IDX0"
  [ "${IDX0#* }" = 0 ] || echo "   AVISO: hay ${IDX0#* } índice(s) inválido(s) de un intento anterior: la 100003 los borra y los rehace"
  DF=$(df -Pk /var/lib/postgresql 2>/dev/null | awk 'NR == 2 {print int($4 / 1024)}' || echo '?')
  echo "   espacio libre en el volumen de PostgreSQL: ${DF:-?} MB (los 4 índices ocupan del orden del 5–15 % de sus tablas)"
  LOG_ANTES=$(lineas_log)
  echo "1. ironbody-migrate (rol dueño, lock_timeout 60 s: solo la 100003; un 55P03 se reintenta hasta 3 veces, 20 s entre intentos):"
  INTENTO=0; RC=0
  while :; do
    INTENTO=$((INTENTO + 1))
    set +e
    SALIDA=$(sudo env PGOPTIONS="-c lock_timeout=60s" /usr/local/bin/ironbody-migrate 2>&1)
    RC=$?
    set -e
    printf '%s\n' "$SALIDA" | sed 's/^/   /'
    IDX=$($PSQL -c "$Q_INDICES") || aborta "no se pudieron leer los índices tras ironbody-migrate (psql); relanzar este mismo script (reanuda)"
    MIG_REG=$($PSQL -c "$Q_MIG_H9")
    [ "$IDX $MIG_REG" != "4 0 $MIG_H9_1 $MIG_H9_2 $MIG_H9_3" ] || break
    case "$SALIDA" in
      *55P03*|*"lock timeout"*)
        if [ "$INTENTO" -lt 3 ]; then echo "   intento $INTENTO: 55P03 (una transacción larga sobre una de las 4 tablas) · índices [$IDX] · reintento en 20 s"; sleep 20; continue; fi ;;
    esac
    aborta "tras ironbody-migrate los índices son [$IDX] (esperado 4 0) y el registro [$MIG_REG] (código $RC, intento $INTENTO). Un índice inválido no estorba (el planificador no lo usa): relanzar este mismo paso aparte, que lo rehace"
  done
  [ "$RC" = 0 ] || desvio "ironbody-migrate salió con $RC aunque los índices cuadran: revisar su salida (arriba)"
  echo "2. índices %_h9_idx (válidos · inválidos): $IDX · registro: $MIG_REG · intento $INTENTO"
  $PSQL -c "SELECT c.relname||' '||t.relname||' '||pg_size_pretty(pg_relation_size(c.oid)) FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid JOIN pg_class t ON t.oid = i.indrelid WHERE c.relname LIKE '%\_h9\_idx' ORDER BY c.relname;" | sed 's/^/   /'
  ESQ=$($PSQL -c "$Q_ESQUEMA")
  [ "$ESQ" = "$ESQ_DESPUES" ] && echo "   esquema [$ESQ]: el de la fase 1, sin cambios" || desvio "el esquema cambió con la 100003 ([$ESQ], esperado [$ESQ_DESPUES]): solo añade índices"
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
  echo "3. laravel.log ERROR desde el inicio del paso aparte: $ERRORES$([ "$ESPERADOS" = 0 ] || echo " (más $ESPERADOS del 55P03 reintentado, esperados)")"; [ "$ERRORES" = 0 ] || desvio "$ERRORES errores en el log desde el inicio"
  echo "   registro de esta ejecución: $LOG · vuelta atrás de los índices (sin tocar datos): DROP INDEX CONCURRENTLY IF EXISTS <índice>, como el dueño, con autorización"
  if [ "$DESVIOS" = 0 ]; then echo "PASO APARTE DE LA 100003 COMPLETADO (4 índices válidos)"; else echo "PASO APARTE DE LA 100003 CON $DESVIOS DESVÍOS: aclararlos antes de dar la fase por cerrada"; exit 1; fi
  exit 0
fi

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
  aborta "migraciones nuevas o tocadas entre $BASE y origin/main distintas de las tres del Hito 9: [$NUEVAS]${AJENAS:+ · ajenas al H9:$AJENAS}. El H9 no despliega trabajo de otros: que su dueño lo despliegue antes por su vía (y entonces fijar PREVIO al commit que ya lo tiene en producción)"
fi
# Los commits ajenos que traería el merge y que producción no tiene: por parche (`git patch-id --stable`) frente a los
# últimos 400 commits de producción. Los del H9 se reconocen por su prefijo (`tipo(ultron-studio-h9):`, con tipo simple
# o compuesto con barras, como los commits del lead). Un `outreach(sends-open):` en la fase 1 es una ALARMA: la constante
# de envío no se abre hasta la fase 4 (§14), así que aborta en vez de contarlo como propio.
EN_PROD=$(g log --no-merges -p --max-count=400 "$BASE" | g patch-id --stable | awk '{print $1}' | sort -u)
AJENOS=""
ACEPTADOS_VISTOS=""
while read -r pid cid; do
  [ -n "${cid:-}" ] || continue
  grep -qxF "$pid" <<< "$EN_PROD" && continue
  ASUNTO=$(g log -1 --format=%s "$cid")
  [[ "$ASUNTO" =~ ^outreach\(sends-open\): ]] && aborta "el commit ${cid:0:7} abre la constante de envío (\`outreach(sends-open):\`): eso es de la fase 4 con visto bueno legal, nunca de la fase 1. No se despliega"
  [[ "$ASUNTO" =~ ^[a-z]+(/[a-z]+)*\(ultron-studio-h9\): ]] && continue
  case ",$ACEPTA," in *",${cid:0:7},"*) ACEPTADOS_VISTOS="$ACEPTADOS_VISTOS ${cid:0:7}"; continue ;; esac
  AJENOS="$AJENOS ${cid:0:7}"
done < <(g log --no-merges -p "$BASE..origin/main" | g patch-id --stable)
for c in ${ACEPTA//,/ }; do
  case " $ACEPTADOS_VISTOS " in *" $c "*) ;; *) aborta "--acepta-main=$c: ese commit no está entre los ajenos que traería el merge desde $BASE (¿otro HEAD u otro PREVIO?): no se acepta nada a ciegas" ;; esac
done
[ -z "$ACEPTADOS_VISTOS" ] || echo "   commits ajenos ACEPTADOS por el propietario con --acepta-main (viajan con el H9):$ACEPTADOS_VISTOS ($(for c in $ACEPTADOS_VISTOS; do g log -1 --format='%s' "$c" | cut -c1-60; done | paste -sd '|' -))"
FALTA_DECIDIR=""
if [ -n "$AJENOS" ]; then
  LISTA="$(for c in $AJENOS; do printf '%s «%s» · ' "$c" "$(g log -1 --format='%s' "$c" | cut -c1-60)"; done)"
  if [ "$SOLO_COMPROBAR" = --solo-comprobar ]; then
    FALTA_DECIDIR=$(echo $AJENOS | tr ' ' ',')
    echo "   PENDIENTE DE DECISIÓN: commits ajenos al H9 que producción no tiene y viajarían con el merge: ${LISTA% · }"
  else
    aborta "commits ajenos al H9 entre $BASE y origin/main que producción no tiene: ${LISTA% · }. El H9 no despliega trabajo de otros sin decisión expresa: o su dueño lo despliega antes por su vía, o el propietario lo acepta ahora con --acepta-main=$(echo $AJENOS | tr ' ' ',')"
  fi
fi
EN_PUERTA=$(g diff --name-only "$BASE" origin/main -- "${PUERTA[@]}" | tr '\n' ' ')
FUERA=""
for f in $EN_PUERTA; do case " $PERMITIDOS_EN_PUERTA " in *" $f "*) ;; *) FUERA="$FUERA $f" ;; esac; done
[ -z "$FUERA" ] || aborta "la puerta de diff tiene ficheros que el contrato del H9 no nombra (Legacy, motor comercial, n8n, OTP, voz del H8, Meta, catálogo, rutas o configuración ajenas, dependencias):$FUERA"
for f in $SOLO_ANADEN; do
  BORRADAS=$(g diff --numstat "$BASE" origin/main -- "$f" | awk '{print $2}')
  [ -z "$BORRADAS" ] || [ "$BORRADAS" = 0 ] || aborta "$f pierde $BORRADAS líneas entre $BASE y origin/main: el H9 solo AÑADE (el bloque del programador y la ruta del canal SSE)"
done
# La constante de envío se comprueba ANTES del merge, en el commit que se va a desplegar: cerrada (una declaración
# `= false;` y ninguna `= true`), en UN solo fichero OutreachSendGate.php bajo app/Services/Outreach.
GATES=$(g ls-tree -r --name-only origin/main -- backend/app/Services/Outreach | grep -E '/OutreachSendGate\.php$' || true)
[ "$(printf '%s\n' "$GATES" | grep -c . || true)" = 1 ] || aborta "en origin/main hay $(printf '%s\n' "$GATES" | grep -c . || true) ficheros OutreachSendGate.php bajo app/Services/Outreach (esperado 1: la constante de envío de B9-run)"
[ "$(g show "origin/main:$GATES" | grep -cE '^[[:space:]]*public const SENDS_ENABLED_IN_CODE = false;[[:space:]]*$' || true)" = 1 ] \
  && [ "$(g show "origin/main:$GATES" | grep -cE 'SENDS_ENABLED_IN_CODE[[:space:]]*=[[:space:]]*true' || true)" = 0 ] \
  || aborta "OutreachSendGate::SENDS_ENABLED_IN_CODE no está exactamente cerrada en origin/main ($GATES): la fase 1 se despliega con la constante en false (§14)"
echo "   constante de envío en origin/main: cerrada ($GATES)"
# La constante de llamadas del H8 no cambia con el H9 (cero diff en Voice y Telephony ya lo garantiza la puerta).
[ "$(g diff --name-only "$BASE" origin/main -- backend/app/Services/Voice/VoiceCallPolicy.php | wc -l | tr -d ' ')" = 0 ] || aborta "VoiceCallPolicy.php cambia entre $BASE y origin/main: el H9 no toca la voz del H8"
MIG_REG=$($PSQL -c "$Q_MIG_H9") || aborta "no se pudo leer la tabla migrations (psql)"
case "$MIG_REG" in
  "")                                      ESQ_PARTIDA=$ESQ_ANTES; YA_APLICADAS=0 ;;
  "$MIG_H9_1")                             ESQ_PARTIDA=$ESQ_MEDIO; YA_APLICADAS=0; echo "   AVISO: la $MIG_H9_1 ya está aplicada y la $MIG_H9_2 no (una ejecución cortada o un migrate ajeno): se aplica lo que falta" ;;
  "$MIG_H9_1 $MIG_H9_2")                   ESQ_PARTIDA=$ESQ_DESPUES; YA_APLICADAS=1; echo "   AVISO: las 100001 y 100002 YA están aplicadas (otro despliegue o un migrate ajeno, como en el H7); la 100003 no: se aplica lo que falta y el esquema se comprueba igual" ;;
  "$MIG_H9_1 $MIG_H9_2 $MIG_H9_3")         ESQ_PARTIDA=$ESQ_DESPUES; YA_APLICADAS=1; echo "   AVISO: las tres migraciones del H9 YA están aplicadas (otro despliegue o un migrate ajeno): no se vuelve a migrar; el esquema se comprueba igual" ;;
  *) aborta "registro de migraciones del H9 inesperado: [$MIG_REG] (una posterior sin la anterior): PARAR y avisar a database-specialist" ;;
esac
APLICADAS=$($PSQL -c "$Q_MIGRACIONES" | sort -u) || aborta "no se pudo leer la tabla migrations (psql)"
# Pendientes = los ficheros de migración de origin/main (solo el primer nivel, como los carga Laravel) que la base no
# tiene registrados. Es lo que aplicaría ironbody-migrate: tiene que ser exactamente lo que falta del H9.
PENDIENTES=$(comm -23 <(g ls-tree --name-only origin/main -- backend/database/migrations/ | sed -n 's#^backend/database/migrations/\([^/]*\)\.php$#\1#p' | sort -u) <(printf '%s\n' "$APLICADAS") | tr '\n' ' ')
ESPERADAS=""; for m in $MIG_H9_1 $MIG_H9_2 $MIG_H9_3; do case " $MIG_REG " in *" $m "*) ;; *) ESPERADAS="$ESPERADAS$m " ;; esac; done
[ "$PENDIENTES" = "$ESPERADAS" ] || aborta "migraciones pendientes en origin/main frente a la base: [$PENDIENTES] (esperadas, solo las del H9 que faltan: [$ESPERADAS]). ironbody-migrate aplicaría TODAS: lo ajeno se despliega antes por su vía"
PRE=$($PSQL -c "${Q_ESQUEMA%;}||' '||(SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE proname = 'ultron_studio_forbid')||' '||(SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'identities')||' '||has_schema_privilege('iron', 'public', 'CREATE')||' '||(SELECT count(*) FROM pg_roles WHERE rolname = 'iron_app');") || aborta "no se pudo leer el esquema de partida (psql; ¿existe el rol iron?)"
[ "$PRE" = "$ESQ_PARTIDA iron iron true 1" ] || aborta "esquema de partida inesperado: [$PRE] (esperado [$ESQ_PARTIDA iron iron true 1]: disparadores, CHECK, parciales y tablas de ultron_studio_* y outreach_*, dueños de F y de identities, CREATE del dueño y el rol iron_app). Con outreach_* distinto de 0 antes de migrar hay tablas que no son del H9; sin iron_app, en production las migraciones FALLAN (REVOKE); con otro dueño de identities, la FK de person_bindings da 42501"
HUELLA=$($PSQL -c "$Q_HUELLA") || aborta "no se pudo leer ultron_studio_forbid() (psql)"
[ "$HUELLA" = "$HUELLA_F" ] || aborta "ultron_studio_forbid() no es la de F (md5 $HUELLA, esperado $HUELLA_F): PARAR y avisar a database-specialist"
BLOQ=$($PSQL -c "$Q_BLOQ") || aborta "no se pudieron leer los cerrojos (psql)"
[ "$BLOQ" = 0 ] || aborta "$BLOQ cerrojos de escritura sobre identities (la FK de la 100001 pide SHARE ROW EXCLUSIVE y cae por 55P03 con un escritor abierto, L9-6): esperar y repetir; quién, con h9-schema-check.sql"
PRIVP_0=$($PSQL -c "$Q_PRIV_PREVIAS") || aborta "no se pudieron leer los privilegios de iron_app en el H5–H8 (psql)"
FILASP_0=$($PSQL -c "$Q_FILAS_PREVIAS") || aborta "no se pudieron contar las filas del Studio (psql)"
RUTAS0=$(rutas)
case "$YA_APLICADAS$REANUDA" in
  00) [[ "$RUTAS0" =~ ^[0-9]+\ 0\ 0\ 0\ 0\ 0$ ]] || aborta "rutas en la caché de producción: [$RUTAS0] (esperado [<Studio del H8> 0 0 0 0 0]: sin strategies, sin la ejecución ni el canal SSE)" ;;
  *)  echo "   rutas en la caché ahora (informativo en una reanudación): [$RUTAS0]" ;;
esac
PROG0=$(programador)
case "$PROG0" in
  "FALLO "*) aborta "schedule:list no sale con 0 (código ${PROG0#FALLO }; su salida no se imprime): sin poder leer el programador no se despliega (¿base o caché? m-1 del H7)" ;;
  ILEGIBLE) aborta "schedule:list no devolvió la lista JSON de tareas: sin poder leer el programador no se despliega" ;;
esac
read -r TOTAL0 STUDIO0 VOZ0 OUT0 <<< "$PROG0"
[ "$OUT0" = 0 ] || aborta "el programador ya tiene $OUT0 tareas outreach:* antes de desplegar (esperado 0)"
PREVIA_ANTES=$(leer_previa)
[ -n "$PREVIA_ANTES" ] || aborta "no se pudo leer la configuración efectiva del motor, de Legacy, del H4–H7 y de la voz (tinker falló; su salida no se imprime)"
case "$PREVIA_ANTES" in *'"followups_scheduler":false'*) ;; *) aborta "MARKETING_FOLLOWUPS_SCHEDULER no está apagado: el paso 0 del contrato lo leyó en false (dos relojes sobre el mismo motor no se despliegan sin aclararlo)" ;; esac
H9_ANTES=$(leer_h9)
[ -n "$H9_ANTES" ] || aborta "no se pudo leer la configuración del H9 (tinker falló; su salida no se imprime)"
case "$H9_ANTES" in *'"deployed":false'*) ;; *) [ "$REANUDA" = 1 ] || aborta "config/outreach.php ya existe en producción antes del merge: ¿otro despliegue? (informativo: $H9_ANTES)" ;; esac
W0=$(workers 999 20); RUN0=${W0%% *}
[ "${W0#* }" = 0 ] || aborta "workers con BACKOFF o FATAL antes de empezar ($W0): no se despliega sobre un sistema degradado"
[ "$RUN0" -ge 13 ] && [ "$RUN0" -le 16 ] || aborta "workers RUNNING antes de empezar: $RUN0 (esperado de 13 a 16: los 13 de producción, ironbody-voice si el H8 lo instaló)"
FACT=$(systemctl is-active ironbody-billing-worker 2>/dev/null || true)
[ "$FACT" = active ] || aborta "ironbody-billing-worker no está activo antes de empezar ($FACT)"
LOG_ANTES=$(lineas_log)
FALLIDAS_ANTES=$($PSQL -c "SELECT count(*) FROM failed_jobs;") || aborta "no se pudo leer failed_jobs (psql)"
SELLOS_ANTES=$($PSQL -c "SELECT count(*) FROM marketing_ai_actions WHERE metadata ? 'studio';") || aborta "no se pudo leer marketing_ai_actions (psql)"
INST_ANTES=$($PSQL -c "SELECT count(*) FROM ultron_studio_integration_snapshots;") || aborta "no se pudieron contar las instantáneas (psql)"
INCID_ANTES=$($PSQL -c "SELECT count(*) FROM incidents WHERE source IN ('ultron_studio', 'outreach');") || aborta "no se pudieron contar los incidentes (psql)"
MONI0=$(monitor_integraciones)
[ "$MONI0" != "?" ] || aborta "el monitor del H7 en seco (integraciones) no devolvió un resultado legible"
case ",$MONI0," in *",config_drift:"*) aborta "el monitor del H7 ya ve deriva de la configuración autorizada antes de desplegar ([$MONI0]): aclararlo antes (GET integrations/config)" ;; esac
LARGAS=$($PSQL -c "$Q_LARGAS")
echo "   HEAD $ACTUAL limpio · migraciones del H9: 3 en el diff, pendientes [${PENDIENTES% }]$([ "$YA_APLICADAS" = 1 ] && echo ' (las tablas ya aplicadas por otro)') · puerta: [${EN_PUERTA% }] (todas nombradas por el contrato) · esquema [$PRE] · función de F · sin escritores de identities · rutas [$RUTAS0] · programador $TOTAL0 tareas, $STUDIO0 del Studio, $VOZ0 de voz, 0 de outreach · workers $W0 · facturación activa · transacciones de más de 30 s: $LARGAS"
echo "   iron_app en las tablas del H5–H8 (SELECT/INSERT/UPDATE/DELETE/TRUNCATE): $PRIVP_0"
echo "   filas cadena · libro · instantáneas · versiones · cadena de voz: $FILASP_0 · colas fallidas: $FALLIDAS_ANTES · sellos studio: $SELLOS_ANTES · incidentes ultron_studio/outreach: $INCID_ANTES"
echo "   motor, Legacy, H4–H7 y voz (informativo; no cambia): $PREVIA_ANTES"
echo "   H9 antes del merge (informativo): $H9_ANTES"
echo "   monitor del H7 en seco, integraciones: [$MONI0] (informativo; tiene que seguir igual)"
[ "$LARGAS" = 0 ] || echo "   AVISO: hay $LARGAS transacciones abiertas desde hace más de 30 s: si la 100003 viaja en este ironbody-migrate, CONCURRENTLY esperará a que terminen (sin bloquear a nadie) o caerá por lock_timeout y quedará para el paso aparte"
sudo -u www-data php -r 'require "/var/www/api/backend/vendor/autoload.php"; try { Dotenv\Dotenv::createArrayBacked("/var/www/api/backend")->load(); } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' \
  || aborta "el fichero de entorno de producción NO parsea antes de empezar: aclararlo antes de desplegar (no se ha tocado nada)"
echo "   el fichero de entorno parsea (antes de tocar nada)"
if [ "$SOLO_COMPROBAR" = --solo-comprobar ]; then
  if [ -n "$FALTA_DECIDIR" ]; then
    echo "COMPROBACIONES PREVIAS LEÍDAS, FALTA DECIDIR los commits ajenos [$FALTA_DECIDIR] (no se ha tocado nada): el despliegue solo sigue con --acepta-main=$FALTA_DECIDIR (decisión del propietario) o cuando su dueño los haya desplegado"
    exit 3
  elif [ "$PREVIO" = pendiente ]; then
    echo "COMPROBACIONES PREVIAS LEÍDAS, FALTA FIJAR PREVIO=$ACTUAL (no se ha tocado nada): revisar lo que entró en producción desde el H8 (git log --oneline a608450..$ACTUAL), fijarlo en deploy-h9-backend-fase1.sh en un commit, volver a copiarlo y repetir --solo-comprobar"
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
echo "2. variables ($N_VARS: todas las banderas del H9 apagadas o en 0 y la puerta de simulación en required):"
[ -z "$(tail -c1 "$ENVF")" ] || printf '\n' >> "$ENVF"
for kv in $VARS; do
  k=${kv%%=*}; v=${kv#*=}
  case "$(estado_var "$k" "$v")" in
    falta) printf '  %s=%s\n' "$k" "$v" >> "$ENVF"; echo "   $k añadida" ;;
    igual) echo "   $k ya estaba con el valor de la fase 1" ;;
    repetida) aborta "$k aparece repetida en el fichero de entorno (gana la última): deduplicar antes; restáuralo desde $BK/env.backup si hace falta" ;;
    distinto) aborta "$k ya existe con OTRO valor (no se imprime): la fase 1 exige todo apagado; aclararlo antes (el mando h9-outreach-scope.sh es el procedimiento para moverla después)" ;;
  esac
done
echo "   permisos del fichero: $(stat -c '%U %a' "$ENVF")"
sudo -u www-data php -r 'require "vendor/autoload.php"; try { Dotenv\Dotenv::createArrayBacked(".")->load(); } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' \
  || aborta "el fichero de entorno NO parsea: restáuralo desde $BK/env.backup (mismo dueño y modo) antes de seguir"
echo "   el fichero de entorno parsea"
echo "3. migraciones (rol dueño, lock_timeout 5 s; un 55P03 se reintenta hasta 3 veces, 15 s entre intentos):"
INTENTO=0; RC=0; IDX="0 0"
if [ -z "${PENDIENTES// /}" ]; then
  echo "   nada que migrar: las tres del H9 ya estaban registradas (paso 0); no se llama a ironbody-migrate"
  ESQ=$($PSQL -c "$Q_ESQUEMA") || aborta "no se pudo leer el esquema (psql); relanzar este mismo script (reanuda)"
  [ "$ESQ" = "$ESQ_DESPUES" ] || aborta "las migraciones constan aplicadas pero el esquema es [$ESQ], no [$ESQ_DESPUES]: alguien tocó las guardas; NO se ha hecho route:cache; PARAR y avisar a database-specialist"
  IDX=$($PSQL -c "$Q_INDICES")
else
  while :; do
    INTENTO=$((INTENTO + 1))
    set +e
    SALIDA=$(sudo env PGOPTIONS="-c lock_timeout=5s" /usr/local/bin/ironbody-migrate 2>&1)
    RC=$?
    set -e
    printf '%s\n' "$SALIDA" | sed 's/^/   /'
    ESQ=$($PSQL -c "$Q_ESQUEMA") || aborta "no se pudo leer el esquema tras ironbody-migrate (psql). NO se ha hecho route:cache; relanzar este mismo script (reanuda)"
    IDX=$($PSQL -c "$Q_INDICES")
    MIG_REG=$($PSQL -c "$Q_MIG_H9")
    # Las tablas (100001 y 100002) tienen que estar enteras; la 100003 puede quedar para el paso aparte.
    if [ "$ESQ" = "$ESQ_DESPUES" ]; then
      case "$MIG_REG" in "$MIG_H9_1 $MIG_H9_2"*) break ;; esac
    fi
    case "$SALIDA" in
      *55P03*|*"lock timeout"*)
        if [ "$INTENTO" -lt 3 ]; then echo "   intento $INTENTO: 55P03 (un escritor de identities, L9-6) · esquema [$ESQ] · reintento en 15 s"; sleep 15; continue; fi ;;
    esac
    aborta "esquema tras ironbody-migrate [$ESQ], esperado [$ESQ_DESPUES] (código $RC, intento $INTENTO; registro [$MIG_REG]; rutas en caché ahora: [$(rutas)]). Cada migración de tablas es una transacción: o está entera o no está ([$ESQ_MEDIO] = solo la del gobierno). NO se ha hecho route:cache: las rutas nuevas no se sirven. Ver quién tiene identities con h9-schema-check.sql y relanzar este mismo script (reanuda)"
  done
fi
echo "   esquema [$ESQ]: 123 disparadores · 137 CHECK · 9 parciales · 25 tablas del Studio; 51 · 75 · 3 · 8 de outreach (intento $INTENTO)"
case "$MIG_REG" in
  *"$MIG_H9_3") [ "$IDX" = "4 0" ] && echo "   la 100003 también quedó aplicada en esta pasada (poco tráfico): 4 índices de lectura válidos; el paso aparte no hace falta (--indices dirá «nada que hacer»)" || desvio "la 100003 consta aplicada pero los índices son [$IDX] (esperado 4 0): relanzar el paso aparte (--indices), que rehace el inválido" ;;
  *) echo "   la 100003 (índices CONCURRENTLY) NO quedó aplicada (código $RC; índices válidos·inválidos [$IDX]): el código funciona sin ellos (solo rendimiento, D9-16). PASO APARTE en hora de poco tráfico: bash $0 $ESPERADO $BK --indices"
     [ "$RC" = 0 ] || { echo "   (el código $RC de ironbody-migrate es el de la 100003 caída: no cuenta como desvío si el esquema de las tablas cuadra)"; RC=0; } ;;
esac
[ "$RC" = 0 ] || desvio "ironbody-migrate salió con $RC aunque el esquema cuadra: revisar su salida (arriba) antes de dar la fase por buena"
echo "4. esquema del Hito 9 (antes de servir las rutas nuevas):"
FUNC=$($PSQL -c "$Q_FUNC")
echo "   funciones nuevas (número · SECURITY DEFINER · dueños distintos · dueño): $FUNC"
[ "$FUNC" = "$FUNC_ESPERADO" ] || aborta "funciones del H9 [$FUNC], esperado [$FUNC_ESPERADO]. NO se ha hecho route:cache; revisar con h9-schema-check.sql y database-specialist"
APAG=$($PSQL -c "$Q_APAGADOS")
[ "$APAG" = 0 ] || aborta "$APAG disparadores apagados en ultron_studio_* u outreach_*: NO se ha hecho route:cache; PARAR y avisar a database-specialist"
[ "$($PSQL -c "$Q_HUELLA")" = "$HUELLA_F" ] || aborta "ultron_studio_forbid() cambió de cuerpo con la migración. NO se ha hecho route:cache"
[ "$($PSQL -c "$Q_LIBRO")" = t ] || aborta "el CHECK del libro (usae_subject_type_chk) no admite los 4 sujetos nuevos del H9 (11 en total, L9-34). NO se ha hecho route:cache"
DUENOS=$($PSQL -c "$Q_DUENOS_H9")
[ "$DUENOS" = "$DUENOS_ESPERADO" ] || aborta "dueños de las tablas del H9 [$DUENOS], esperado todas de iron. NO se ha hecho route:cache"
PRIV9=$($PSQL -c "$Q_PRIV_H9")
echo "   iron_app en las 15 tablas (r SELECT · a INSERT · w UPDATE · d DELETE · D TRUNCATE · s secuencia): $PRIV9"
[ "$PRIV9" = "$PRIV_H9_ESPERADO" ] || aborta "privilegios de iron_app en las tablas del H9 [$PRIV9], esperado [$PRIV_H9_ESPERADO] (ra en las 5 de solo inserción, raw sin DELETE en las 10 con estado). NO se ha hecho route:cache; avisar a database-specialist"
FILAS9=$($PSQL -c "$Q_FILAS_H9")
[ "$FILAS9" = "$FILAS_H9_ESPERADAS" ] && echo "   las 15 tablas del H9, vacías" || desvio "filas en las tablas del H9 [$FILAS9]: la fase 1 no escribe nada (¿alguien usó el Studio o la ejecución?)"
PRIVP=$($PSQL -c "$Q_PRIV_PREVIAS")
[ "$PRIVP" = "$PRIVP_0" ] && echo "   iron_app en las tablas del H5–H8: igual que antes" || desvio "los privilegios de iron_app en las tablas del H5–H8 cambiaron: antes [$PRIVP_0] · después [$PRIVP]"
FILASP=$($PSQL -c "$Q_FILAS_PREVIAS")
[ "$FILASP" = "$FILASP_0" ] && echo "   filas de la cadena, el libro, las instantáneas, versiones y la cadena de voz: $FILASP (las mismas)" || desvio "las filas del Studio cambiaron durante la migración: antes [$FILASP_0] · después [$FILASP] (¿alguien usó el Studio?)"
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
H9=$(leer_h9)
RUTAS=$(rutas)
COBERTURA=$(artisan auth:coverage --unmapped 2>&1 | tr -d '\n' | cut -c1-80 || true)
STUDIO_TOTAL=$(printf '%s' "$H9" | python3 -I -c 'import json, sys; v = json.load(sys.stdin).get("studio_routes_total"); print(v if isinstance(v, int) else "?")' 2>/dev/null || echo '?')
STREAM_ESP=0; case "$H9" in *'"stream_deployed":true'*) STREAM_ESP=1 ;; esac
# El total del Studio es la constante del código desplegado, StudioRouteCount::TOTAL = 77 + 30 + 1 + 13 (L9-59): el canal
# SSE y la ejecución cuelgan del mismo prefijo admin/ultron-studio y ya cuentan en ella (nada se suma aparte).
RUTAS_ESP="$STUDIO_TOTAL $RUTAS_STRATEGIES $RUTAS_EJECUCION $RUTAS_EJECUCION_GET $RUTAS_EJECUCION_POST $RUTAS_STREAM"
echo "6. rutas (Studio · strategies/** · ejecución · sus GET · sus POST · canal SSE): $RUTAS (esperado $RUTAS_ESP: el Studio por StudioRouteCount::TOTAL del código desplegado, E2-6 y L9-59) · auth:coverage --unmapped → [$COBERTURA]"
[ "$RUTAS" = "$RUTAS_ESP" ] || desvio "rutas [$RUTAS] (esperado [$RUTAS_ESP])"
[ -z "$COBERTURA" ] || desvio "auth:coverage --unmapped no está vacío"
echo "7. configuración efectiva:"
case "$H9" in '{"deployed"'*) echo "   $H9" ;; *) echo "   (tinker no devolvió la configuración del H9; su salida no se imprime)" ;; esac
APAGADO=$(h9_apagado "$H9")
[ "$APAGADO" = OK ] || desvio "la configuración efectiva del H9 no es la de la fase 1 (todo apagado, la constante cerrada): ${APAGADO#MAL }"
PREVIA=$(leer_previa)
[ "$PREVIA" = "$PREVIA_ANTES" ] && echo "   motor comercial, Legacy, H4–H7 y voz del H8 iguales que antes del despliegue" || desvio "la configuración del motor, de Legacy, del H4–H7 o de la voz cambió: antes $PREVIA_ANTES · después ${PREVIA:-ilegible}"
PROG=$(programador)
case "$PROG" in
  "FALLO "*) desvio "schedule:list no sale con 0 (código ${PROG#FALLO }; su salida no se imprime): no se puede comprobar el programador (m-1)" ;;
  ILEGIBLE) desvio "schedule:list no devolvió la lista JSON de tareas: no se puede comprobar el programador" ;;
  *) echo "   programador (total · del Studio · de voz · de outreach): $PROG (esperado $TOTAL0 $STUDIO0 $VOZ0 0: con COMMERCIAL_AUTOMATION_SCHEDULER_ENABLED apagada el bloque del H9 no programa nada)"
     [ "$PROG" = "$TOTAL0 $STUDIO0 $VOZ0 0" ] || desvio "el programador da [$PROG] y se esperaba [$TOTAL0 $STUDIO0 $VOZ0 0]" ;;
esac
COLA=$($PSQL -c "$Q_COLA_H9")
[ "$COLA" = 0 ] || [ "$COLA" = sin_tabla ] && echo "   colas outreach*: $COLA trabajos (la fase 1 no despacha nada)" || desvio "hay $COLA trabajos en colas outreach*: nada debería despacharse con todo apagado"
sleep 6
W1=$(workers "$RUN0" 45); sleep 8; W2=$(workers "$RUN0" 45)
echo "8. workers (RUNNING · BACKOFF/FATAL), dos lecturas separadas: [$W1] y [$W2] (esperado $RUN0 0: la fase 1 no instala ningún worker)"
[ "$W1" = "$RUN0 0" ] && [ "$W2" = "$RUN0 0" ] || desvio "workers [$W1] y [$W2] (esperado $RUN0 0 las dos veces)"
PIDS_DESPUES=$(supervisorctl status 2>/dev/null | grep -oE 'pid [0-9]+' | sort || true)
NUEVOS=$(comm -13 <(printf '%s\n' "$PIDS_ANTES") <(printf '%s\n' "$PIDS_DESPUES") | grep -c pid || true)
echo "   INFO: workers con pid nuevo tras queue:restart: $NUEVOS de $RUN0 (el resto termina su trabajo antes de reiniciarse; se reinician además cada hora)"
FACT=$(systemctl is-active ironbody-billing-worker 2>/dev/null || true)
[ "$FACT" = active ] || desvio "ironbody-billing-worker: $FACT"
echo "9. sanidad:"
# Sin token: 401 y `Cache-Control: no-store, private` (B7-a) en lo nuevo del Studio y de la ejecución y en lo de siempre.
for p in admin/ultron-studio/outreach/status admin/ultron-studio/outreach/brakes admin/ultron-studio/strategies admin/ultron-studio/strategies/catalog admin/ultron-studio/strategies/templates admin/ultron-studio/status; do
  CAB=$(curl -sI -H 'Accept: application/json' "$API/$p" | tr -d '\r')
  c=$(printf '%s\n' "$CAB" | awk 'NR == 1 {print $2}')
  cc=$(printf '%s\n' "$CAB" | awk -F': *' 'tolower($1) == "cache-control" {print $2}' | tail -1)
  echo "   HEAD $p sin token → $c · Cache-Control: ${cc:-(ninguna)}"
  [ "$c" = 401 ] || desvio "HEAD $p respondió $c (esperado 401)"
  [ "$cc" = "no-store, private" ] || desvio "HEAD $p: Cache-Control «${cc:-ninguna}» (esperado «no-store, private»)"
done
c=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$API/admin/ultron-studio/stream?topics=ledger")
if [ "$STREAM_ESP" = 1 ]; then echo "   GET admin/ultron-studio/stream sin tique → $c"; [ "$c" = 401 ] || desvio "el canal SSE sin tique respondió $c (esperado 401)"; else echo "   INFO: GET admin/ultron-studio/stream → $c (SSE9 no viaja en este commit: 404 esperado)"; fi
# El tope de cuerpo (64 KB) va ANTES de autenticar en el Studio: 70 000 bytes a POST strategies → 413 payload_too_large.
GRANDE=$(python3 -c 'import json; print(json.dumps({"name": "x" * 70000}))')
R413=$(printf '%s' "$GRANDE" | curl -s -o - -w ' %{http_code}' -X POST -H 'Accept: application/json' -H 'Content-Type: application/json' --data-binary @- "$API/admin/ultron-studio/strategies" | tail -c 200)
echo "   POST strategies con 70 000 bytes sin token → ${R413##* } $(printf '%s' "$R413" | grep -o '"code":"[a-z_]*"' || true)"
case "$R413" in *'"code":"payload_too_large"'*' 413') ;; *) desvio "el cuerpo de 70 000 bytes sin sesión a POST strategies no dio 413 payload_too_large" ;; esac
R413=$(printf '%s' "$GRANDE" | curl -s -o - -w ' %{http_code}' -X POST -H 'Accept: application/json' -H 'Content-Type: application/json' --data-binary @- "$API/admin/ultron-studio/outreach/brakes" | tail -c 200)
echo "   POST ultron-studio/outreach/brakes con 70 000 bytes sin token → ${R413##* } $(printf '%s' "$R413" | grep -o '"code":"[a-z_]*"' || true)"
case "$R413" in *'"code":"payload_too_large"'*' 413') ;; *) desvio "el cuerpo de 70 000 bytes sin sesión a POST outreach/brakes no dio 413 payload_too_large (el tope de 64 KB del grupo admin/ultron-studio de routes/ultron-studio.php)" ;; esac
c=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Accept: application/json' "$API/webhooks/twilio/usage-trigger")
echo "   usage-trigger del OTP sin firma → $c (falla cerrada; T-1, intacto)"; [ "$c" = 403 ] || desvio "usage-trigger del OTP sin firma respondió $c (esperado 403)"
c=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$API/admin/voice/status")
echo "   admin/voice/status sin token → $c (la voz del H8, intacta)"; [ "$c" = 401 ] || desvio "admin/voice/status sin token respondió $c (esperado 401)"
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
case ",$MONI," in *",config_drift:"*) desvio "el monitor ve deriva de la configuración autorizada tras el despliegue: la fase 1 no tiene que cambiar ninguna huella" ;; esac
INCID=$($PSQL -c "SELECT count(*) FROM incidents WHERE source IN ('ultron_studio', 'outreach');")
[ "$INCID" = "$INCID_ANTES" ] && echo "   incidentes ultron_studio/outreach: $INCID (los mismos)" || desvio "incidentes ultron_studio/outreach: $INCID_ANTES → $INCID durante el despliegue"
P="^[[:space:]]*(export[[:space:]]+)?($CLAVES_H9)[[:space:]]*="
if cmp -s <(grep -vE "$P" "$ENVF" | sed '/^$/d') <(grep -vE "$P" "$BK/env.backup" | sed '/^$/d'); then echo "   fichero de entorno igual al respaldo salvo las $N_VARS claves del Hito 9"; else desvio "el fichero de entorno difiere del respaldo en algo más que las claves del Hito 9 (comparar a mano sin imprimir valores)"; fi
N=0; for kv in $VARS; do if [ "$(estado_var "${kv%%=*}" "${kv#*=}")" = igual ]; then N=$((N + 1)); fi; done
[ "$N" = "$N_VARS" ] || desvio "solo $N de las $N_VARS claves del Hito 9 están una vez y con su valor"
echo "10. integrations-snapshot (la fase 1 no cambia ninguna huella) e integrations-doctor (informativo, con la expectativa configurada del monitor del H7):"
if artisan ultron-studio:integrations-snapshot > /dev/null 2>&1; then
  INST=$($PSQL -c "SELECT count(*) FROM ultron_studio_integration_snapshots;")
  echo "   instantáneas: $INST_ANTES → $INST"
  [ "$INST" = "$INST_ANTES" ] || desvio "integrations-snapshot anotó $((INST - INST_ANTES)) instantánea(s): la huella de alguna integración cambió con la fase 1 (no debería; revisar GET integrations/config antes de seguir)"
else
  desvio "ultron-studio:integrations-snapshot no salió con 0 (ejecutarlo a mano como www-data para ver por qué)"
fi
EXPECT=$(printf '%s' "$PREVIA_ANTES" | python3 -I -c 'import json, sys; v = json.load(sys.stdin).get("monitor_expect"); print(v if isinstance(v, str) and v.strip() else "")' 2>/dev/null || echo "")
if [ -n "$EXPECT" ]; then
  if artisan ultron-studio:integrations-doctor --expect="$EXPECT" > /dev/null 2>&1; then echo "   integrations-doctor --expect=$EXPECT: 0"; else echo "   INFO: integrations-doctor no confirma la expectativa configurada ($EXPECT): es del H8, no de este despliegue; con el monitor del H7 encendido abriría un incidente"; fi
else
  echo "   INFO: sin ULTRON_STUDIO_MONITOR_EXPECT configurada: el doctor no se evalúa"
fi
ERRORES=0; BLOQUEOS=0
for f in "$LOGS"/laravel*.log; do
  [ -f "$f" ] || continue
  n=$(printf '%s\n' "$LOG_ANTES" | awk -v f="$f" '$1 == f {print $2}')
  e=$(tail -n +"$(( ${n:-0} + 1 ))" "$f" | grep -c 'production.ERROR' || true)
  l=$(tail -n +"$(( ${n:-0} + 1 ))" "$f" | grep -c 'production.ERROR: SQLSTATE\[55P03\]' || true)
  ERRORES=$((ERRORES + e)); BLOQUEOS=$((BLOQUEOS + l))
done
# Los 55P03 esperados: los reintentos del paso 3 y, si la 100003 cayó por lock_timeout en esta pasada, el suyo.
ESPERADOS=$(( INTENTO > 1 ? (BLOQUEOS < INTENTO - 1 ? BLOQUEOS : INTENTO - 1) : 0 ))
case "$MIG_REG" in *"$MIG_H9_3") ;; *) [ "$BLOQUEOS" -le "$ESPERADOS" ] || ESPERADOS=$((ESPERADOS + 1)) ;; esac
ERRORES=$((ERRORES - ESPERADOS))
echo "11. laravel.log ERROR desde el inicio del despliegue: $ERRORES$([ "$ESPERADOS" = 0 ] || echo " (más $ESPERADOS del 55P03 esperados: reintentos del paso 3 o la 100003 pendiente del paso aparte)")"; [ "$ERRORES" -le 0 ] || desvio "$ERRORES errores en el log desde el inicio"
[ "$(g -C /var/www/api status --porcelain --untracked-files=no | wc -l | tr -d ' ')" = 0 ] || desvio "el checkout tiene cambios tras el despliegue"
echo "   registro de esta ejecución: $LOG"
case "$MIG_REG" in *"$MIG_H9_3") ;; *) echo "   PENDIENTE: el paso aparte de la 100003 en hora de poco tráfico (22:00–06:00 de Bogotá): bash $0 $ESPERADO $BK --indices" ;; esac
if [ "$DESVIOS" = 0 ]; then echo "DESPLIEGUE H9 FASE 1 (BACKEND) COMPLETADO"; else echo "DESPLIEGUE H9 FASE 1 (BACKEND) CON $DESVIOS DESVÍOS: no seguir con el CRM ni con el smoke hasta aclararlos"; exit 1; fi

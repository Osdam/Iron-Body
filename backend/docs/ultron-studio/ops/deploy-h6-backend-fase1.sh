#!/bin/bash
# Fase 1 del Hito 6 en producción: código desplegado con las ESCRITURAS y la SONDA APAGADAS. Las 18 rutas nuevas leen;
# las 47–54 responden 409 integrations_writes_disabled y la 56, 409 probe_disabled. El motor no lee nada nuevo.
# Se ejecuta EN EL SERVIDOR como root, tras el respaldo. Orden:
#   0. comprobaciones de solo lectura (abortan sin tocar nada; con --solo-comprobar se termina aquí)
#   1. código (git como root con umask 022) y lectura por www-data de cada fichero del merge
#   2. las 8 variables de la fase 1 ANTES de ironbody-migrate, y comprobación de que el fichero de entorno parsea
#   3. ironbody-migrate (2 migraciones); un 55P03 se reintenta hasta 3 veces; si el esquema no da 43/49 con 4 parciales
#      y 14 tablas, ABORTA SIN route:cache: las rutas nuevas no se sirven con las tablas sin guardas (Enmienda 1 §E1.3)
#   4. aserciones del esquema (guardas por prefijo, 7 sujetos, función de F, dueños, privilegios de iron_app, 0 filas)
#   5. route:cache como www-data con umask 077, cachés www-data 600 y queue:restart
#   6. rutas (56 / 18 / 8 GET / 10 POST) y cobertura · 7. configuración efectiva exacta · 8. workers 13/0 dos veces
#   9. sanidad (401, usage-trigger 403, colas fallidas, sellos, secretos, publication-check, entorno = respaldo)
#  10. integrations-doctor · 11. primera instantánea de sistema (integrations-snapshot) · 12. ERROR nuevos en el log
# Cada aserción que falla se dice y el script termina con 1; «FASE 1 (H6) COMPLETADA» solo sale con 0 desvíos.
# Nunca imprime valores del fichero de entorno, ni secretos, ni la salida de un tinker que no case con lo esperado.
# Uso: bash /root/deploy-h6-backend-fase1.sh <commit esperado (7 cifras)> <directorio del respaldo de hoy> [--solo-comprobar]
#   --solo-comprobar: ejecuta solo el paso 0 (lecturas y git fetch) y sale; es el ensayo en el servidor.
set -euo pipefail
export LC_ALL=C
# git como ROOT con umask 022 (decisión del lead, ajuste 4 del contrato H6; I-3 del reviewer del H5): el árbol de
# /var/www/api y su .git son de root desde el 25-10; lo que git reescriba queda 644/755 y tras el merge se comprueba
# que www-data lee cada fichero (memoria «umask 077 y git merge en el VPS»).
umask 022
g() { git "$@"; }
uso() { echo "uso: bash /root/deploy-h6-backend-fase1.sh <commit esperado (7 cifras)> <directorio del respaldo de hoy> [--solo-comprobar]"; }
[ "$#" -ge 2 ] && [ "$#" -le 3 ] || { uso; echo "no se ha tocado nada"; exit 2; }
ESPERADO="$1"
BK="$2"
SOLO_COMPROBAR="${3:-}"
case "$SOLO_COMPROBAR" in ""|--solo-comprobar) ;; *) echo "tercer argumento desconocido: $SOLO_COMPROBAR (solo se admite --solo-comprobar); no se ha tocado nada"; exit 2 ;; esac   # I-2 del reviewer H5
[[ "$ESPERADO" =~ ^[0-9a-f]{7}$ ]] || { echo "el commit esperado va con 7 cifras hexadecimales (git rev-parse --short=7 origin/main); no se ha tocado nada"; exit 2; }
[ "$(id -u)" = 0 ] || { echo "se ejecuta como root en el servidor; no se ha tocado nada"; exit 2; }
# PREVIO = lo que sirve producción ANTES del Hito 6: la fase 1 del H5 (7ba0ce8, 01-11-2026; origin/main va por delante
# solo en documentación). SE ACTUALIZA AL DESPLEGAR: si el día del despliegue producción sirve otro commit (otro equipo
# desplegó después del H5), se revisa qué entró, se cambia aquí en un commit y se vuelve a copiar el script.
PREVIO=7ba0ce8
MIGRACIONES="backend/database/migrations/2026_11_01_100001_create_ultron_studio_integration_tables.php backend/database/migrations/2026_11_01_100002_add_ultron_studio_integration_guards.php "
# Puerta de diff del contrato («Despliegue por fases»), más las dependencias: este script no ejecuta composer install.
PUERTA=(backend/app/Services/Marketing backend/app/Jobs backend/app/Http/Controllers/Api backend/app/Services/Sms
        backend/app/Services/Otp backend/routes/api.php backend/routes/marketing.php backend/routes/console.php
        backend/config/marketing.php backend/config/meta.php backend/config/otp.php
        backend/app/Services/UltronStudio/StudioCatalog.php backend/composer.json backend/composer.lock)
HUELLA_F=4ad748b5f17f09b398f7f641c197d7ee        # md5(pg_get_functiondef) de ultron_studio_forbid(): la de F, medida en H5 e informe-db H6
MIN_TABLAS=223                                     # 222 en el volcado del H5 (01-11, antes de migrar) + ultron_studio_publications
API=https://api.ironbodyneiva.cloud/api
PSQL="sudo -u postgres psql -X -Atq -v ON_ERROR_STOP=1 -d ironbody"
aborta() { echo "ABORTA: $*"; exit 1; }
DESVIOS=0
desvio() { echo "   ✗ $*"; DESVIOS=$((DESVIOS + 1)); }
artisan() { ( cd /var/www/api/backend && sudo -u www-data php artisan "$@" ); }
# Las 8 variables de la fase 1, explícitas aunque coincidan con el defecto (contrato «Fase 1» paso 2 e I12). Las
# piezas de la clave restringida (ULTRON_STUDIO_TWILIO_PROBE_KEY_SID/_SECRET) son del propietario y nunca se escriben;
# ULTRON_STUDIO_TWILIO_PROBE_BASE se ignora en production y tampoco se escribe.
VARS="ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED=false ULTRON_STUDIO_TWILIO_PROBE_ENABLED=false ULTRON_STUDIO_CANARY_MAX_ACTIVE=3 ULTRON_STUDIO_CANARY_COUNTRY_CODES=57 ULTRON_STUDIO_CAMPAIGN_LINKS_MAX_ACTIVE=50 ELEVENLABS_ENABLED=false TWILIO_VOICE_ENABLED=false TWILIO_VOICE_DAILY_CALL_CAP=0"
CLAVES_H6=$(for kv in $VARS; do printf '%s|' "${kv%%=*}"; done); CLAVES_H6=${CLAVES_H6%|}
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
# 13 RUNNING y 0 BACKOFF/FATAL, esperando hasta $1 segundos (un worker que acaba su trabajo tras queue:restart tarda en
# volver: el 01-11 el único recuento a los 6 s dio 12). Imprime «RUNNING MAL».
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
# Rutas del Studio en la caché de rutas: «total integrations GET POST» (esperado 56 18 8 10), o «?».
rutas() {
  artisan route:list --path=admin/ultron-studio --json 2>/dev/null | python3 -c '
import json, sys
try:
    r = json.load(sys.stdin)
except Exception:
    print("?")
    sys.exit(0)
i = [x for x in r if "/integrations/" in "/" + str(x.get("uri", "")) + "/"]
print(len(r), len(i), sum(1 for x in i if "GET" in str(x.get("method", ""))), sum(1 for x in i if str(x.get("method", "")) == "POST"))' 2>/dev/null || echo '?'
}
# Configuración del motor, del H4 y del H5 (no la toca el H6): se lee antes y después y tiene que ser la misma.
leer_motor() {
  local l
  l=$(artisan tinker --execute='echo json_encode(["engine_scope" => config("marketing.ultron.studio_config"), "publishing_enabled" => config("ultron_studio.publishing_enabled"), "lab_gate" => config("ultron_studio.publishing.lab_gate"), "lab_enabled" => config("ultron_studio.lab.enabled"), "lab_driver" => config("ultron_studio.lab.driver"), "canary_set" => config("marketing.ultron.canary_conversation_id") !== null, "ultron_enabled" => config("marketing.ultron.enabled")]), PHP_EOL;' 2>&1 | tail -1) || true
  case "$l" in '{"engine_scope"'*) printf '%s\n' "$l" ;; esac
}
# Configuración efectiva del H6: solo banderas, topes, nombres de variables y booleanos de presencia; nunca un valor.
leer_h6() {
  local l
  l=$(artisan tinker --execute='$p = "ultron_studio.integrations."; echo json_encode(["writes_enabled" => config($p."writes_enabled"), "probe_enabled" => config($p."twilio_probe.enabled"), "probe_cooldown_s" => config($p."twilio_probe.cooldown_seconds"), "probe_credential" => (filled(config($p."twilio_probe.key_sid")) && filled(config($p."twilio_probe.key_secret"))) ? "restricted_key" : "otp", "probe_base_set" => filled(config($p."twilio_probe.base")), "canary_max_active" => config($p."canary.max_active"), "canary_max_live" => config($p."canary.max_live"), "canary_country_codes" => array_map("strval", (array) config($p."canary.country_codes")), "campaign_links_max_active" => config($p."campaign_links.max_active"), "twilio_aliases" => ["sid_source" => config($p."twilio_aliases.sid_source"), "token_source" => config($p."twilio_aliases.token_source"), "sid_consistent" => config($p."twilio_aliases.sid_consistent"), "token_consistent" => config($p."twilio_aliases.token_consistent")], "elevenlabs_enabled" => config("voice.elevenlabs.enabled"), "twilio_voice_enabled" => config("voice.twilio.enabled"), "twilio_voice_from_set" => filled(config("voice.twilio.from")), "twilio_voice_daily_cap" => config("voice.twilio.daily_call_cap"), "voice" => App\Services\Voice\VoiceProviders::status()->toArray()]), PHP_EOL;' 2>&1 | tail -1) || true
  case "$l" in '{"writes_enabled"'*) printf '%s\n' "$l" ;; esac
}
H6_TABLAS="'ultron_studio_campaign_links', 'ultron_studio_canary_numbers', 'ultron_studio_integration_snapshots'"
# Una línea: disparadores · CHECK · parciales · tablas ultron_studio_* (43 49 4 14 con el H6; 30 27 2 11 antes).
Q_ESQUEMA="SELECT (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron_studio_%')||' '||(SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE 'ultron_studio_%')||' '||(SELECT count(*) FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid WHERE t.relname LIKE 'ultron_studio_%' AND i.indpred IS NOT NULL)||' '||(SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'ultron_studio_%');"
Q_BLOQ="SELECT count(*) FROM pg_locks l JOIN pg_class c ON c.oid = l.relation WHERE c.relname IN ('ultron_studio_audit_events', 'ultron_studio_versions', $H6_TABLAS) AND l.pid <> pg_backend_pid();"
Q_HUELLA="SELECT md5(pg_get_functiondef(oid)) FROM pg_proc WHERE proname = 'ultron_studio_forbid';"

echo "0. comprobaciones previas (solo lectura; si algo falla, no se toca nada):"
cd /var/www/api
g fetch -q origin
ACTUAL=$(g rev-parse --short=7 HEAD)                 # --short=7 (m-6 del H5): la abreviatura automática crece con el número de objetos
case "$ACTUAL" in
  "$PREVIO")   REANUDA=0 ;;
  "$ESPERADO") REANUDA=1; echo "   reanudación: el código ya está en $ESPERADO (una ejecución anterior se cortó); se repiten variables, migración y comprobaciones" ;;
  *) aborta "producción no está en $PREVIO ni en $ESPERADO (HEAD $ACTUAL): si se desplegó otra cosa después del H5, revisar qué entró y actualizar PREVIO en un commit" ;;
esac
MODIFICADOS=$(g status --porcelain --untracked-files=no | wc -l | tr -d ' ')   # sin grep: con pipefail, un grep sin coincidencias mataría el script
[ "$MODIFICADOS" = 0 ] || aborta "el checkout de producción tiene $MODIFICADOS cambios locales"
[ "$(g rev-parse --short=7 origin/main)" = "$ESPERADO" ] || aborta "origin/main es $(g rev-parse --short=7 origin/main), no $ESPERADO"
g merge-base --is-ancestor "$PREVIO" origin/main || aborta "origin/main no es avance rápido desde $PREVIO"
NUEVAS=$(g diff --name-only --diff-filter=AMDR "$PREVIO" origin/main -- backend/database/migrations | sort | tr '\n' ' ')
[ "$NUEVAS" = "$MIGRACIONES" ] || aborta "migraciones nuevas o tocadas entre $PREVIO y origin/main distintas de las dos del Hito 6: [$NUEVAS]"
FUERA=$(g diff --name-only "$PREVIO" origin/main -- "${PUERTA[@]}" | tr '\n' ' ')
[ -z "$FUERA" ] || aborta "la puerta de diff no está vacía (motor, integraciones existentes, catálogo o dependencias; este script no hace composer install): [$FUERA]"
case "$(cat "$BK/api-commit.txt" 2>/dev/null)" in
  "$PREVIO"*) ;;
  *) aborta "$BK/api-commit.txt no es $PREVIO (la vuelta atrás del código lo lee; si el respaldo se rehizo en una reanudación, escribirlo con: git -C /var/www/api rev-parse $PREVIO > $BK/api-commit.txt)" ;;
esac
[ -s "$BK/ironbody.dump" ] && [ -n "$(find "$BK/ironbody.dump" -mmin -120)" ] || aborta "no hay volcado de menos de 2 h en $BK"
[ "$(stat -c '%a' "$BK")" = 700 ] || aborta "el directorio del respaldo tiene que ser 700 (es $(stat -c '%a' "$BK"))"
[ "$(stat -c '%U %a' "$BK/ironbody.dump")" = "root 600" ] && [ "$(stat -c '%U %a' "$BK/env.backup")" = "root 600" ] || aborta "el volcado y env.backup tienen que ser de root y 600"
if [ -f "$BK/ironbody.dump.sha256" ]; then sha256sum -c --status "$BK/ironbody.dump.sha256" || aborta "el volcado no coincide con su ironbody.dump.sha256"; fi
TABLAS=$(pg_restore -l "$BK/ironbody.dump" | grep -c 'TABLE DATA' || true)
[ "$TABLAS" -ge "$MIN_TABLAS" ] || aborta "el volcado solo lista $TABLAS tablas con datos (al menos $MIN_TABLAS)"
if [ "$REANUDA" = 0 ]; then cmp -s "$ENVF" "$BK/env.backup" || aborta "el fichero de entorno cambió desde el respaldo: rehacer el respaldo (env.backup tiene que ser el de partida)"; fi
echo "   respaldo $BK: $TABLAS tablas con datos · 700/600 de root"
[ -z "$(tail -c1 "$ENVF")" ] || echo "   el fichero de entorno no acaba en salto de línea: se añadirá uno antes de las claves"
for kv in $VARS; do
  case "$(estado_var "${kv%%=*}" "${kv#*=}")" in
    falta|igual) ;;
    *) aborta "${kv%%=*} ya existe con otro valor o repetida (no se imprime): revisar a mano antes de desplegar" ;;
  esac
done
# ElevenLabs y Twilio Voice: el hito se cierra en ELEVENLABS_NOT_CONFIGURED y sin número de voz. Una pieza en el entorno
# contradice el plan: se para y se aclara con el propietario (las credenciales no se tocan desde aquí).
for k in ELEVENLABS_API_KEY ELEVENLABS_AGENT_ID ELEVENLABS_WEBHOOK_SECRET ELEVENLABS_TOOL_SECRET ELEVENLABS_AGENT_PHONE_NUMBER_ID TWILIO_VOICE_FROM; do
  case "$(presencia "$k")" in falta|vacia) ;; *) aborta "$k está en el entorno (no se imprime): la fase 1 exige ELEVENLABS_NOT_CONFIGURED y sin número de voz; aclararlo con el propietario" ;; esac
done
case "$(estado_var ELEVENLABS_REGION default)" in falta|igual) ;; *) aborta "ELEVENLABS_REGION existe con otro valor o repetida: revisar a mano (la fase 1 espera la región por defecto)" ;; esac
KSID=$(presencia ULTRON_STUDIO_TWILIO_PROBE_KEY_SID); KSEC=$(presencia ULTRON_STUDIO_TWILIO_PROBE_KEY_SECRET)
case "$KSID $KSEC" in *repetida*) aborta "una pieza de la clave restringida de la sonda está repetida en el entorno: deduplicar a mano (no se imprime)" ;; esac
if [ "$KSID $KSEC" = "presente presente" ]; then CRED=restricted_key; else CRED=otp; fi
case "$KSID $KSEC" in "presente "*|*" presente") [ "$CRED" = restricted_key ] || echo "   AVISO: solo una de las dos piezas de la clave restringida de la sonda está en el entorno: la sonda usará la credencial del OTP" ;; esac
case "$(presencia ULTRON_STUDIO_TWILIO_PROBE_BASE)" in
  presente) BASE_SET=true; echo "   AVISO: ULTRON_STUDIO_TWILIO_PROBE_BASE está en el entorno: en production se ignora (hosts oficiales siempre)" ;;
  repetida) aborta "ULTRON_STUDIO_TWILIO_PROBE_BASE está repetida en el entorno: deduplicar a mano" ;;
  *) BASE_SET=false ;;
esac
case "$(presencia ELEVENLABS_BASE_URL)" in presente|repetida) echo "   AVISO: ELEVENLABS_BASE_URL está en el entorno: en production se ignora" ;; esac
# Los alias de Twilio, solo por presencia de nombres (los valores no se leen): como I12, «al cachear».
SIDP=$(presencia TWILIO_SID); SIDA=$(presencia TWILIO_ACCOUNT_SID); TOKP=$(presencia TWILIO_TOKEN); TOKA=$(presencia TWILIO_AUTH_TOKEN)
case "$SIDP $SIDA $TOKP $TOKA" in *repetida*) aborta "una variable de Twilio está repetida en el entorno: deduplicar a mano (no se imprime)" ;; esac
if [ "$SIDP" = presente ]; then SID_SRC='"TWILIO_SID"'; elif [ "$SIDA" = presente ]; then SID_SRC='"TWILIO_ACCOUNT_SID"'; else SID_SRC=null; fi
if [ "$TOKP" = presente ]; then TOK_SRC='"TWILIO_TOKEN"'; elif [ "$TOKA" = presente ]; then TOK_SRC='"TWILIO_AUTH_TOKEN"'; else TOK_SRC=null; fi
if [ "$SIDP $SIDA" = "presente presente" ]; then SID_CONS=true; else SID_CONS=null; fi   # true: hecho de producción al congelar (las dos con la misma huella)
if [ "$TOKP $TOKA" = "presente presente" ]; then TOK_CONS=true; else TOK_CONS=null; fi
ESPERADA_H6="{\"writes_enabled\":false,\"probe_enabled\":false,\"probe_cooldown_s\":300,\"probe_credential\":\"$CRED\",\"probe_base_set\":$BASE_SET,\"canary_max_active\":3,\"canary_max_live\":10,\"canary_country_codes\":[\"57\"],\"campaign_links_max_active\":50,\"twilio_aliases\":{\"sid_source\":$SID_SRC,\"token_source\":$TOK_SRC,\"sid_consistent\":$SID_CONS,\"token_consistent\":$TOK_CONS},\"elevenlabs_enabled\":false,\"twilio_voice_enabled\":false,\"twilio_voice_from_set\":false,\"twilio_voice_daily_cap\":0,\"voice\":{\"provider\":\"none\",\"state\":\"ELEVENLABS_NOT_CONFIGURED\",\"enabled\":false,\"missing\":[\"api_key\",\"agent_id\",\"webhook_secret\"],\"region\":\"default\"}}"
echo "   claves del Hito 6 ausentes o iguales · sonda: credencial $CRED · Twilio: SID por $SID_SRC, token por $TOK_SRC (sin valores)"
PRE=$($PSQL -c "SELECT (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron_studio_%')||' '||(SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE 'ultron_studio_%')||' '||(SELECT count(*) FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid WHERE t.relname LIKE 'ultron_studio_%' AND i.indpred IS NOT NULL)||' '||(SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'ultron_studio_%')||' '||(SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE proname = 'ultron_studio_forbid')||' '||(SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'ultron_studio_audit_events')||' '||(SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'ultron_studio_versions')||' '||has_schema_privilege('iron', 'public', 'CREATE')||' '||(SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename IN ($H6_TABLAS));") || aborta "no se pudo leer el esquema de partida (psql; ¿existe el rol iron?)"
if [ "$REANUDA" = 0 ]; then
  [ "$PRE" = "30 27 2 11 iron iron iron true 0" ] || aborta "esquema de partida inesperado: [$PRE] (esperado [30 27 2 11 iron iron iron true 0]: el del H5); si la función, el libro o versiones no son de iron, la 100002 falla con 42501"
else
  case "$PRE" in *" iron iron iron true "*) ;; *) aborta "dueños o permiso CREATE inesperados: [$PRE]" ;; esac
fi
HUELLA=$($PSQL -c "$Q_HUELLA") || aborta "no se pudo leer ultron_studio_forbid() (psql)"
[ "$HUELLA" = "$HUELLA_F" ] || aborta "ultron_studio_forbid() no es la de F (md5 $HUELLA, esperado $HUELLA_F): la 100002 la redeclara con el cuerpo de F y la cambiaría; PARAR y avisar a database-specialist"
BLOQ=$($PSQL -c "$Q_BLOQ") || aborta "no se pudieron leer los cerrojos (psql)"
[ "$BLOQ" = 0 ] || aborta "$BLOQ cerrojos sobre el libro, versiones o las tablas del H6 (la 100002 pide ACCESS EXCLUSIVE, que choca también con un SELECT abierto): esperar y repetir; quién los tiene, con h6-schema-check.sql"
EVENTOS=$($PSQL -c "SELECT count(*)||' '||count(*) FILTER (WHERE subject_type NOT IN ('draft', 'version', 'rule', 'system')) FROM ultron_studio_audit_events;") || aborta "no se pudo leer el libro (psql)"
RUTAS0=$(rutas)
if [ "$REANUDA" = 0 ]; then
  [ "$RUTAS0" = "38 0 0 0" ] || aborta "rutas del Studio en la caché de producción: [$RUTAS0] (esperado [38 0 0 0], el Hito 5 en su fase 1)"
fi
MOTOR_ANTES=$(leer_motor)
[ -n "$MOTOR_ANTES" ] || aborta "no se pudo leer la configuración efectiva del motor y del Studio (tinker falló; su salida no se imprime)"
W0=$(workers 20)
[ "$W0" = "13 0" ] || aborta "workers antes de empezar: $W0 (RUNNING, BACKOFF/FATAL; esperado 13 0): no se despliega sobre un sistema degradado"
FACT=$(systemctl is-active ironbody-billing-worker 2>/dev/null || true)
[ "$FACT" = active ] || aborta "ironbody-billing-worker no está activo antes de empezar ($FACT)"
LOG_ANTES=$(lineas_log)                          # para contar solo los ERROR que se escriban desde aquí
FALLIDAS_ANTES=$($PSQL -c "SELECT count(*) FROM failed_jobs;") || aborta "no se pudo leer failed_jobs (psql)"   # por SQL: en Laravel 12 queue:failed ya no pinta «| id |»
SELLOS_ANTES=$($PSQL -c "SELECT count(*) FROM marketing_ai_actions WHERE metadata ? 'studio';") || aborta "no se pudo leer marketing_ai_actions (psql)"
[ "$REANUDA" = 0 ] && ESQUEMA="esquema 30/27 del H5, 2 parciales, 11 tablas, de iron" || ESQUEMA="reanudación [$PRE]"
echo "   HEAD $ACTUAL limpio · puerta de diff vacía · $ESQUEMA · función de F · sin cerrojos · rutas [$RUTAS0] · workers 13/0 · facturación activa"
echo "   libro: $EVENTOS (eventos · de sujetos nuevos) · colas fallidas: $FALLIDAS_ANTES · sellos studio: $SELLOS_ANTES"
echo "   motor, H4 y H5 (informativo; no cambia): $MOTOR_ANTES"
[ "$SOLO_COMPROBAR" != --solo-comprobar ] || { echo "COMPROBACIONES PREVIAS EN VERDE (no se ha tocado nada)"; exit 0; }

g merge -q --ff-only origin/main
HEAD=$(g rev-parse --short=7 HEAD)
[ "$HEAD" = "$ESPERADO" ] || aborta "HEAD $HEAD no es $ESPERADO"
echo "1. código: HEAD=$HEAD"
# Lo que el merge reescribió lo tiene que poder leer www-data (PHP-FPM y los workers); un fichero 600 de root dejaría un
# 500 silencioso. Fichero a fichero sobre el diff real, con recuento (versión de 7ba0ce8, la que se ejecutó el 01-11).
ILEGIBLES=0; REVISADOS=0
CAMBIADOS=$(g diff --name-only "$PREVIO" HEAD) || aborta "git diff --name-only $PREVIO HEAD falló: no se puede comprobar la lectura por www-data"
while IFS= read -r f; do
  [ -n "$f" ] || continue
  [ -e "$f" ] || continue                                     # borrado por el merge
  REVISADOS=$((REVISADOS + 1))
  sudo -u www-data test -r "$f" || { echo "   ✗ www-data no puede leer $f ($(stat -c '%U:%G %a' "$f"))"; ILEGIBLES=$((ILEGIBLES + 1)); }
done <<< "$CAMBIADOS"
[ "$REVISADOS" -gt 0 ] || aborta "la comprobación de lectura no revisó ningún fichero (el diff $PREVIO..HEAD salió vacío): algo no cuadra"
[ "$ILEGIBLES" = 0 ] || aborta "$ILEGIBLES de $REVISADOS ficheros del merge no los lee www-data: chmod 644 (ficheros) / 755 (directorios) sobre git diff --name-only $PREVIO HEAD antes de seguir"
echo "   los $REVISADOS ficheros del merge son legibles por www-data"
cd backend
echo "2. variables (escrituras y sonda apagadas, voz apagada, topes por defecto):"
[ -z "$(tail -c1 "$ENVF")" ] || printf '\n' >> "$ENVF"
for kv in $VARS; do
  k=${kv%%=*}; v=${kv#*=}
  if [ "$(estado_var "$k" "$v")" = falta ]; then printf '  %s=%s\n' "$k" "$v" >> "$ENVF"; echo "   $k añadida"; else echo "   $k ya estaba con el valor de la fase 1"; fi
done
echo "   permisos del fichero: $(stat -c '%U %a' "$ENVF")"
# B-1 del H5: el fichero tiene que parsear ANTES de que ironbody-migrate vacíe la caché (solo se imprime la clase del error).
sudo -u www-data php -r 'require "vendor/autoload.php"; try { Dotenv\Dotenv::createArrayBacked(".")->load(); } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' \
  || aborta "el fichero de entorno NO parsea: restáuralo desde $BK/env.backup (mismo dueño y modo 600) antes de seguir"
echo "   el fichero de entorno parsea"
echo "3. migración (rol dueño, lock_timeout 5 s; un 55P03 se reintenta hasta 3 veces, 15 s entre intentos):"
INTENTO=0
while :; do
  INTENTO=$((INTENTO + 1))
  set +e
  SALIDA=$(sudo env PGOPTIONS="-c lock_timeout=5s" /usr/local/bin/ironbody-migrate 2>&1)
  RC=$?
  set -e
  printf '%s\n' "$SALIDA" | sed 's/^/   /'
  ESQ=$($PSQL -c "$Q_ESQUEMA") || aborta "no se pudo leer el esquema tras ironbody-migrate (psql). NO se ha hecho route:cache; relanzar este mismo script (reanuda)"
  [ "$ESQ" != "43 49 4 14" ] || break
  case "$SALIDA" in
    *55P03*|*"lock timeout"*)
      if [ "$INTENTO" -lt 3 ]; then echo "   intento $INTENTO: 55P03 (alguien tenía el libro, versiones o una tabla nueva) · esquema [$ESQ] · reintento en 15 s"; sleep 15; continue; fi ;;
  esac
  aborta "esquema tras ironbody-migrate [$ESQ], esperado [43 49 4 14] (código $RC, intento $INTENTO; rutas en caché ahora: [$(rutas)]). NO se ha hecho route:cache: las 18 rutas nuevas no se sirven y nadie usa las tablas (si cayó la 100002, quedan SIN GUARDAS hasta reintentar). Ver quién tiene cerrojos con h6-schema-check.sql y relanzar este mismo script (reanuda)"
done
echo "   esquema 43 disparadores · 49 CHECK · 4 parciales · 14 tablas (intento $INTENTO)"
[ "$RC" = 0 ] || desvio "ironbody-migrate salió con $RC aunque el esquema cuadra: revisar su salida (arriba) antes de dar la fase por buena"
echo "4. esquema del Hito 6 (antes de servir las rutas nuevas):"
POST=$($PSQL -c "SELECT (SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal AND tgname LIKE 'uscl\_%')||' '||(SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal AND tgname LIKE 'uscn\_%')||' '||(SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal AND tgname LIKE 'usis\_%')||' '||(SELECT count(*) FROM pg_constraint WHERE contype = 'c' AND conname LIKE 'uscl\_%')||' '||(SELECT count(*) FROM pg_constraint WHERE contype = 'c' AND conname LIKE 'uscn\_%')||' '||(SELECT count(*) FROM pg_constraint WHERE contype = 'c' AND conname LIKE 'usis\_%')||' '||(SELECT count(*) FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname IN ('uscl_one_active', 'uscn_one_live') AND i.indisunique AND i.indpred IS NOT NULL)||' '||(SELECT pg_get_constraintdef(oid) LIKE '%''campaign_link''%' AND pg_get_constraintdef(oid) LIKE '%''canary_number''%' AND pg_get_constraintdef(oid) LIKE '%''integration''%' FROM pg_constraint WHERE conname = 'usae_subject_type_chk' AND conrelid = 'public.ultron_studio_audit_events'::regclass)||' '||(SELECT string_agg(tableowner, ',' ORDER BY tablename) FROM pg_tables WHERE schemaname = 'public' AND tablename IN ($H6_TABLAS))||' '||(SELECT count(*) FROM ultron_studio_campaign_links)||' '||(SELECT count(*) FROM ultron_studio_canary_numbers)||' '||(SELECT count(*) FROM ultron_studio_integration_snapshots WHERE actor_type <> 'system' OR kind <> 'config')||' '||(SELECT count(*) FROM ultron_studio_audit_events WHERE subject_type IN ('campaign_link', 'canary_number', 'integration'));")
echo "   disp. uscl·uscn·usis · CHECK uscl·uscn·usis · parciales H6 · 7 sujetos · dueños · filas asoc.·canarios·instant. de persona o sonda · eventos nuevos: $POST"
[ "$POST" = "5 5 3 4 12 6 2 true iron,iron,iron 0 0 0 0" ] || aborta "esquema del H6 [$POST], esperado [5 5 3 4 12 6 2 true iron,iron,iron 0 0 0 0]. NO se ha hecho route:cache; revisar con h6-schema-check.sql y database-specialist"
[ "$($PSQL -c "$Q_HUELLA")" = "$HUELLA_F" ] || aborta "ultron_studio_forbid() cambió de cuerpo con la migración. NO se ha hecho route:cache"
# m-5 del H5: has_table_privilege con una lista responde true si se tiene CUALQUIERA; uno a uno. UPDATE lo necesitan las
# asociaciones (desactivar) y los canarios (transiciones); en las instantáneas UPDATE y DELETE son informativos (los
# paran los disparadores, como DELETE en las tres).
PRIV=$($PSQL -c "SELECT string_agg(has_table_privilege('iron_app', 'public.' || t, 'SELECT')||'/'||has_table_privilege('iron_app', 'public.' || t, 'INSERT')||'/'||(CASE WHEN t = 'ultron_studio_integration_snapshots' THEN 'n' ELSE has_table_privilege('iron_app', 'public.' || t, 'UPDATE')::text END)||'/'||has_table_privilege('iron_app', 'public.' || t, 'TRUNCATE')||'/'||has_sequence_privilege('iron_app', 'public.' || t || '_id_seq', 'USAGE'), ' ' ORDER BY t) FROM unnest(ARRAY[$H6_TABLAS]) AS t;")
echo "   iron_app (SELECT/INSERT/UPDATE/TRUNCATE/secuencia) en asociaciones · canarios · instantáneas: $PRIV"
[ "$PRIV" = "true/true/true/false/true true/true/true/false/true true/true/n/false/true" ] || aborta "privilegios de iron_app sobre las tablas del H6 [$PRIV], esperado [true/true/true/false/true true/true/true/false/true true/true/n/false/true]. NO se ha hecho route:cache"
MODIF=$($PSQL -c "SELECT string_agg(has_table_privilege('iron_app', 'public.' || t, 'DELETE')::text, ' ' ORDER BY t)||' | '||has_table_privilege('iron_app', 'public.ultron_studio_integration_snapshots', 'UPDATE') FROM unnest(ARRAY[$H6_TABLAS]) AS t;")
echo "   iron_app DELETE en las tres · UPDATE en instantáneas (informativo; los paran los disparadores): $MODIF"
echo "5. cachés y workers:"
sudo -u www-data sh -c 'umask 077; php artisan route:cache -q'
for f in bootstrap/cache/config.php bootstrap/cache/routes-v7.php; do
  [ "$(stat -c '%U' "$f")" = www-data ] || aborta "$f no es de www-data: no se reinician los workers (quedarían en BACKOFF)"
  [ "$(stat -c '%a' "$f")" = 600 ] || { chmod 600 "$f"; echo "   $f pasado a 600"; }
done
echo "   cachés: $(stat -c '%U %a' bootstrap/cache/config.php) · $(stat -c '%U %a' bootstrap/cache/routes-v7.php)"
PIDS_ANTES=$(supervisorctl status 2>/dev/null | grep -oE 'pid [0-9]+' | sort || true)
sudo -u www-data php artisan queue:restart -q
RUTAS=$(rutas)
COBERTURA=$(artisan auth:coverage --unmapped 2>&1 | tr -d '\n' | cut -c1-80 || true)
echo "6. rutas del Studio (total · integrations · GET · POST): $RUTAS · auth:coverage --unmapped → [$COBERTURA]"
[ "$RUTAS" = "56 18 8 10" ] || desvio "rutas del Studio [$RUTAS] (esperado [56 18 8 10])"
[ -z "$COBERTURA" ] || desvio "auth:coverage --unmapped no está vacío"
echo "7. configuración efectiva:"
H6=$(leer_h6)
case "$H6" in '{"writes_enabled"'*) echo "   $H6" ;; *) echo "   (tinker no devolvió la configuración del H6; su salida no se imprime)" ;; esac
[ "$H6" = "$ESPERADA_H6" ] || { desvio "la configuración efectiva del H6 no es la de la fase 1"; echo "     esperada: $ESPERADA_H6"; }
MOTOR=$(leer_motor)
[ "$MOTOR" = "$MOTOR_ANTES" ] && echo "   motor, H4 y H5 iguales que antes del despliegue" || desvio "la configuración del motor, del H4 o del H5 cambió: antes $MOTOR_ANTES · después ${MOTOR:-ilegible}"
sleep 6
W1=$(workers 45); sleep 8; W2=$(workers 45)
echo "8. workers (RUNNING · BACKOFF/FATAL), dos lecturas separadas: [$W1] y [$W2]"
[ "$W1" = "13 0" ] && [ "$W2" = "13 0" ] || desvio "workers [$W1] y [$W2] (esperado 13 0 las dos veces)"
PIDS_DESPUES=$(supervisorctl status 2>/dev/null | grep -oE 'pid [0-9]+' | sort || true)
NUEVOS=$(comm -13 <(printf '%s\n' "$PIDS_ANTES") <(printf '%s\n' "$PIDS_DESPUES") | grep -c pid || true)
echo "   INFO: workers con pid nuevo tras queue:restart: $NUEVOS de 13 (el resto termina su trabajo antes de reiniciarse; uno que vuelva tarde en BACKOFF lo ve la verificación de los 15 min)"
FACT=$(systemctl is-active ironbody-billing-worker 2>/dev/null || true)
[ "$FACT" = active ] || desvio "ironbody-billing-worker: $FACT"
echo "9. sanidad:"
for p in "GET integrations/status" "GET integrations/canaries" "GET integrations/config" "POST integrations/meta/links"; do
  c=$(curl -s -o /dev/null -w '%{http_code}' -X "${p%% *}" -H 'Accept: application/json' "$API/admin/ultron-studio/${p#* }")
  echo "   $p sin token → $c"; [ "$c" = 401 ] || desvio "$p sin token respondió $c (esperado 401)"
done
c=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Accept: application/json' "$API/webhooks/twilio/usage-trigger")
echo "   usage-trigger sin firma → $c (falla cerrada; T-1)"; [ "$c" = 403 ] || desvio "usage-trigger sin firma respondió $c (esperado 403)"
FALLIDAS=$($PSQL -c "SELECT count(*) FROM failed_jobs;")
echo "   colas fallidas: $FALLIDAS (antes $FALLIDAS_ANTES)"; [ "$FALLIDAS" -le "$FALLIDAS_ANTES" ] || desvio "colas fallidas nuevas durante el despliegue"
SELLOS=$($PSQL -c "SELECT count(*) FROM marketing_ai_actions WHERE metadata ? 'studio';")
case "$MOTOR_ANTES" in
  *'"engine_scope":"off"'*) echo "   sellos studio en marketing_ai_actions (motor del H5 en off): $SELLOS"; [ "$SELLOS" = 0 ] || desvio "hay sellos del Studio con el motor en off" ;;
  *) echo "   sellos studio en marketing_ai_actions (motor del H5 leyendo; informativo): $SELLOS_ANTES → $SELLOS" ;;
esac
artisan security:check-secret-exposure > /dev/null 2>&1 && echo "   security:check-secret-exposure: OK" || desvio "security:check-secret-exposure no pasa"
artisan ultron-studio:publication-check > /dev/null 2>&1 && echo "   ultron-studio:publication-check: 0 (cuadra)" || desvio "ultron-studio:publication-check no sale con 0"
P="^[[:space:]]*(export[[:space:]]+)?($CLAVES_H6)[[:space:]]*="
if cmp -s <(grep -vE "$P" "$ENVF" | sed '/^$/d') <(grep -vE "$P" "$BK/env.backup" | sed '/^$/d'); then echo "   fichero de entorno igual al respaldo salvo las 8 claves del Hito 6"; else desvio "el fichero de entorno difiere del respaldo en algo más que las claves del Hito 6 (comparar a mano sin imprimir valores)"; fi
N=0; for kv in $VARS; do if [ "$(estado_var "${kv%%=*}" "${kv#*=}")" = igual ]; then N=$((N + 1)); fi; done
[ "$N" = 8 ] || desvio "solo $N de las 8 claves del Hito 6 están una vez y con su valor"
echo "10. integrations-doctor:"
DOC=$(artisan ultron-studio:integrations-doctor --json 2>/dev/null | python3 -c '
import json, re, sys
def walk(n):
    if isinstance(n, dict):
        if "id" in n and "classification" in n:
            yield n
        for v in n.values():
            yield from walk(v)
    elif isinstance(n, list):
        for v in n:
            yield from walk(v)
ok = re.compile(r"^[A-Za-z_]{1,40}$")
try:
    d = json.load(sys.stdin)
except Exception:
    print("(salida no legible: solo cuentan los códigos de salida)")
    sys.exit(0)
out = []
for p in walk(d):
    if not (isinstance(p.get("id"), str) and isinstance(p.get("classification"), str)):
        continue
    vals = [p.get("id"), p.get("classification"), p.get("code"), p.get("reason")]
    if all(v is None or (isinstance(v, str) and ok.match(v)) for v in vals):
        out.append(p["id"] + "=" + p["classification"] + ("/" + p["code"] if p.get("code") else "") + ("(" + p["reason"] + ")" if p.get("reason") else ""))
print(" ".join(out) if out else "(sin proveedores legibles)")' 2>/dev/null || echo '(sin salida)')
echo "   $DOC"
if artisan ultron-studio:integrations-doctor --expect=twilio:connected_otp_only,twilio_voice:not_configured,elevenlabs:ELEVENLABS_NOT_CONFIGURED > /dev/null 2>&1; then
  echo "   twilio connected_otp_only · twilio_voice not_configured · elevenlabs ELEVENLABS_NOT_CONFIGURED: 0"
else
  desvio "integrations-doctor no confirma twilio:connected_otp_only, twilio_voice:not_configured y elevenlabs:ELEVENLABS_NOT_CONFIGURED"
fi
if artisan ultron-studio:integrations-doctor --expect=meta_ads:connected_read > /dev/null 2>&1; then
  echo "   meta_ads connected_read: 0"
elif artisan ultron-studio:integrations-doctor --expect=meta_ads:degraded > /dev/null 2>&1; then
  echo "   INFO: meta_ads sale degraded (dato viejo, pasada fallida o sincronización apagada): se informa, no es un fallo del despliegue"
else
  desvio "meta_ads no es connected_read ni degraded (¿not_configured?): la clasificación al congelar era connected_read"
fi
echo "11. primera instantánea de sistema (integrations-snapshot; inserta solo si la huella de un proveedor cambió):"
if [ "$DESVIOS" = 0 ]; then
  INST_ANTES=$($PSQL -c "SELECT count(*) FROM ultron_studio_integration_snapshots;")
  if artisan ultron-studio:integrations-snapshot > /dev/null 2>&1; then
    OBS=$($PSQL -c "SELECT string_agg(p || '=' || (SELECT count(*) FROM ultron_studio_integration_snapshots s WHERE s.provider = p AND s.kind = 'config' AND s.actor_type = 'system'), ' ' ORDER BY p) FROM unnest(ARRAY['meta_ads', 'twilio', 'voice']) AS p;")
    INST=$($PSQL -c "SELECT count(*) FROM ultron_studio_integration_snapshots;")
    echo "   instantáneas: $INST_ANTES → $INST · observadas por proveedor: $OBS"
    case "$OBS" in "meta_ads="[1-9]*" twilio="[1-9]*" voice="[1-9]*) ;; *) desvio "falta la instantánea observada de algún proveedor [$OBS]" ;; esac
  else
    desvio "ultron-studio:integrations-snapshot no salió con 0 (ejecutarlo a mano como www-data para ver por qué)"
  fi
else
  echo "   no se toma con $DESVIOS desvíos: la primera observada tiene que ser la configuración verificada (se toma al relanzar, que reanuda)"
fi
ERRORES=0; BLOQUEOS=0
for f in "$LOGS"/laravel*.log; do
  [ -f "$f" ] || continue
  n=$(printf '%s\n' "$LOG_ANTES" | awk -v f="$f" '$1 == f {print $2}')
  e=$(tail -n +"$(( ${n:-0} + 1 ))" "$f" | grep -c 'production.ERROR' || true)
  l=$(tail -n +"$(( ${n:-0} + 1 ))" "$f" | grep -c 'production.ERROR: SQLSTATE\[55P03\]' || true)
  ERRORES=$((ERRORES + e)); BLOQUEOS=$((BLOQUEOS + l))
done
# Un 55P03 que el paso 3 reintentó con éxito deja su excepción en el log: se descuentan como mucho tantas como reintentos.
ESPERADOS=$(( BLOQUEOS < INTENTO - 1 ? BLOQUEOS : INTENTO - 1 ))
ERRORES=$((ERRORES - ESPERADOS))
echo "12. laravel.log ERROR desde el inicio del despliegue: $ERRORES$([ "$ESPERADOS" = 0 ] || echo " (más $ESPERADOS del 55P03 reintentado en el paso 3, esperados)")"; [ "$ERRORES" = 0 ] || desvio "$ERRORES errores en el log desde el inicio"
[ "$(g -C /var/www/api status --porcelain --untracked-files=no | wc -l | tr -d ' ')" = 0 ] || desvio "el checkout tiene cambios tras el despliegue"
if [ "$DESVIOS" = 0 ]; then echo "FASE 1 (H6) COMPLETADA"; else echo "FASE 1 (H6) CON $DESVIOS DESVÍOS: no seguir con el CRM ni con el smoke hasta aclararlos"; exit 1; fi

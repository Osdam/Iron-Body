#!/bin/bash
# Hito 7 en producción (seguridad, validación y producción): código desplegado con el MONITOR APAGADO y la migración
# de endurecimiento de DB7. No hay rutas nuevas (siguen las 56 del H6); lo que cambia: `Cache-Control: no-store` en
# /api/admin/*, el tope de 64 KB del Studio antes de TrimStrings (413 también sin sesión), `verified` por entrada del
# historial, los invisibles rechazados en PlainText y limpiados en la API interna de conocimiento, el monitor (apagado)
# y, en la base, la cadena consecutiva (uspub_chain_next + uspub_seq_chk) y UPDATE/DELETE revocados a iron_app en la
# cadena, el libro y las instantáneas. Las banderas del H5 y del H6 NO cambian.
# Se ejecuta EN EL SERVIDOR como root, tras el respaldo. Orden:
#   0. comprobaciones de solo lectura (abortan sin tocar nada; con --solo-comprobar se termina aquí)
#   1. código (git como root con umask 022) y lectura por www-data de cada fichero del merge
#   2. la variable del monitor (apagado) ANTES de ironbody-migrate, y comprobación de que el fichero de entorno parsea
#   3. ironbody-migrate (1 migración); un 55P03 se reintenta hasta 3 veces; si el esquema no da 44/50/4/14, ABORTA
#      (el código nuevo ya sirve, sin rutas nuevas: lo que faltaría es la guarda; relanzar reanuda)
#   4. aserciones del esquema (disparador y CHECK de la cadena, función de F intacta, dueños, privilegios de iron_app)
#   5. route:cache como www-data con umask 077, cachés www-data 600 y queue:restart
#   6. rutas (56 / 18 / 8 GET / 10 POST) y cobertura · 7. configuración efectiva: monitor apagado y sin programar, H5 y
#      H6 iguales que antes · 8. workers 13/0 dos veces
#   9. sanidad: 401 y `Cache-Control: no-store, private` con curl -I (también un 404 de /api/admin), 413 temprano sin
#      sesión, usage-trigger 403, colas fallidas, sellos, secretos, publication-check, el monitor en seco, entorno = respaldo
#  10. integrations-doctor · 11. integrations-snapshot sin cambios (el H7 no cambia ninguna integración) · 12. ERROR nuevos
# Cada aserción que falla se dice y el script termina con 1; «DESPLIEGUE H7 (BACKEND) COMPLETADO» solo sale con 0 desvíos.
# Nunca imprime valores del fichero de entorno, ni secretos, ni la salida de un tinker que no case con lo esperado.
# Uso: bash /root/deploy-h7-backend.sh <commit esperado (7 cifras)> <directorio del respaldo de hoy> [--solo-comprobar]
#   --solo-comprobar: ejecuta solo el paso 0 (lecturas y git fetch) y sale; es el ensayo en el servidor.
set -euo pipefail
export LC_ALL=C
umask 022
g() { git "$@"; }
uso() { echo "uso: bash /root/deploy-h7-backend.sh <commit esperado (7 cifras)> <directorio del respaldo de hoy> [--solo-comprobar]"; }
[ "$#" -ge 2 ] && [ "$#" -le 3 ] || { uso; echo "no se ha tocado nada"; exit 2; }
ESPERADO="$1"
BK="$2"
SOLO_COMPROBAR="${3:-}"
case "$SOLO_COMPROBAR" in ""|--solo-comprobar) ;; *) echo "tercer argumento desconocido: $SOLO_COMPROBAR (solo se admite --solo-comprobar); no se ha tocado nada"; exit 2 ;; esac
[[ "$ESPERADO" =~ ^[0-9a-f]{7}$ ]] || { echo "el commit esperado va con 7 cifras hexadecimales (git rev-parse --short=7 origin/main); no se ha tocado nada"; exit 2; }
[ "$(id -u)" = 0 ] || { echo "se ejecuta como root en el servidor; no se ha tocado nada"; exit 2; }
# PREVIO = lo que sirve producción ANTES del Hito 7: el commit de la fase 1 del H6 (o el de su fase 2, si se hizo).
# NO SE CONOCE AL ESCRIBIR ESTE SCRIPT (el H6 no estaba desplegado el 01-11-2026): se fija EN UN COMMIT el día del
# despliegue con `git -C /var/www/api rev-parse --short=7 HEAD` leído en el servidor, se revisa qué entró desde el H6 y
# se vuelve a copiar el script. Con «pendiente» el script se niega a todo, también a --solo-comprobar.
PREVIO=pendiente
[[ "$PREVIO" =~ ^[0-9a-f]{7}$ ]] || { echo "PREVIO sin fijar ($PREVIO): fijarlo en un commit con el HEAD de producción del día (ver la cabecera); no se ha tocado nada"; exit 2; }
MIGRACIONES="backend/database/migrations/2026_11_02_100001_add_ultron_studio_hardening.php "
# Puerta de diff del contrato (la del H6: motor, integraciones existentes, catálogo y dependencias; este script no
# ejecuta composer install). En el H7 la cruzan SOLO los ficheros que el contrato nombra, cada uno con su paquete; un
# fichero de la puerta que no esté aquí ABORTA. Se completa con los informes de B7 (los controladores de M-2, uno por
# commit) y de SEC7 antes de desplegar; console.php solo puede AÑADIR líneas (el bloque del monitor).
PUERTA=(backend/app/Services/Marketing backend/app/Jobs backend/app/Http/Controllers/Api backend/app/Services/Sms
        backend/app/Services/Otp backend/routes/api.php backend/routes/marketing.php backend/routes/console.php
        backend/config/marketing.php backend/config/meta.php backend/config/otp.php
        backend/app/Services/UltronStudio/StudioCatalog.php backend/composer.json backend/composer.lock)
PERMITIDOS_EN_PUERTA="backend/app/Http/Controllers/Api/Internal/InternalMarketingKnowledgeController.php backend/routes/console.php"
HUELLA_F=4ad748b5f17f09b398f7f641c197d7ee        # md5(pg_get_functiondef) de ultron_studio_forbid(): la de F (H5, H6 y H7)
MIN_TABLAS=226                                     # 223 del H6 + sus 3 tablas (asociaciones, canarios, instantáneas)
API=https://api.ironbodyneiva.cloud/api
PSQL="sudo -u postgres psql -X -Atq -v ON_ERROR_STOP=1 -d ironbody"
aborta() { echo "ABORTA: $*"; exit 1; }
DESVIOS=0
desvio() { echo "   ✗ $*"; DESVIOS=$((DESVIOS + 1)); }
artisan() { ( cd /var/www/api/backend && sudo -u www-data php artisan "$@" ); }
# La variable del Hito 7, explícita aunque coincida con el defecto (contrato: «monitor apagado por defecto y encendido
# con autorización»). ULTRON_STUDIO_MONITOR_EXPECT no se escribe: rige la del código (las protecciones del H6).
VARS="ULTRON_STUDIO_MONITOR_ENABLED=false"
CLAVES_H7=$(for kv in $VARS; do printf '%s|' "${kv%%=*}"; done); CLAVES_H7=${CLAVES_H7%|}
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
# Configuración del motor, del H4, del H5 y del H6 (no la toca el H7): se lee antes y después y tiene que ser la misma.
leer_previa() {
  local l
  l=$(artisan tinker --execute='$p = "ultron_studio.integrations."; echo json_encode(["engine_scope" => config("marketing.ultron.studio_config"), "publishing_enabled" => config("ultron_studio.publishing_enabled"), "lab_gate" => config("ultron_studio.publishing.lab_gate"), "lab_enabled" => config("ultron_studio.lab.enabled"), "lab_driver" => config("ultron_studio.lab.driver"), "canary_set" => config("marketing.ultron.canary_conversation_id") !== null, "ultron_enabled" => config("marketing.ultron.enabled"), "writes_enabled" => config($p."writes_enabled"), "probe_enabled" => config($p."twilio_probe.enabled"), "elevenlabs_enabled" => config("voice.elevenlabs.enabled"), "twilio_voice_enabled" => config("voice.twilio.enabled")]), PHP_EOL;' 2>&1 | tail -1) || true
  case "$l" in '{"engine_scope"'*) printf '%s\n' "$l" ;; esac
}
# Configuración efectiva del H7: el monitor (bandera y si la expectativa es la del código), sin valores.
leer_h7() {
  local l
  l=$(artisan tinker --execute='echo json_encode(["monitor_enabled" => config("ultron_studio.monitor.enabled"), "monitor_expect_default" => config("ultron_studio.monitor.expect") === "twilio:connected_otp_only,twilio_voice:not_configured,elevenlabs:ELEVENLABS_NOT_CONFIGURED"]), PHP_EOL;' 2>&1 | tail -1) || true
  case "$l" in '{"monitor_enabled"'*) printf '%s\n' "$l" ;; esac
}
ESPERADA_H7='{"monitor_enabled":false,"monitor_expect_default":true}'
INSERCION="'ultron_studio_publications', 'ultron_studio_audit_events', 'ultron_studio_integration_snapshots'"
# Una línea: disparadores · CHECK · parciales · tablas ultron_studio_* (44 50 4 14 con el H7; 43 49 4 14 antes).
Q_ESQUEMA="SELECT (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron_studio_%')||' '||(SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE 'ultron_studio_%')||' '||(SELECT count(*) FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid WHERE t.relname LIKE 'ultron_studio_%' AND i.indpred IS NOT NULL)||' '||(SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'ultron_studio_%');"
Q_BLOQ="SELECT count(*) FROM pg_locks l JOIN pg_class c ON c.oid = l.relation WHERE c.relname = 'ultron_studio_publications' AND l.pid <> pg_backend_pid();"
Q_HUELLA="SELECT md5(pg_get_functiondef(oid)) FROM pg_proc WHERE proname = 'ultron_studio_forbid';"
# Privilegios de iron_app, uno a uno (m-5 del H5) y por columna: SELECT/INSERT/UPDATE/DELETE/TRUNCATE/secuencia, en
# orden alfabético: libro, asociaciones, canarios, instantáneas y cadena.
Q_PRIV="SELECT string_agg(has_table_privilege('iron_app', 'public.' || t, 'SELECT')||'/'||has_table_privilege('iron_app', 'public.' || t, 'INSERT')||'/'||has_any_column_privilege('iron_app', 'public.' || t, 'UPDATE')||'/'||has_table_privilege('iron_app', 'public.' || t, 'DELETE')||'/'||has_table_privilege('iron_app', 'public.' || t, 'TRUNCATE')||'/'||has_sequence_privilege('iron_app', 'public.' || t || '_id_seq', 'USAGE'), ' ' ORDER BY t) FROM unnest(ARRAY[$INSERCION, 'ultron_studio_campaign_links', 'ultron_studio_canary_numbers']) AS t;"
# Las filas de las tablas que toca la migración (no toca ninguna: antes = después).
Q_FILAS="SELECT (SELECT count(*) FROM ultron_studio_publications)||' '||(SELECT count(*) FROM ultron_studio_audit_events)||' '||(SELECT count(*) FROM ultron_studio_integration_snapshots);"

echo "0. comprobaciones previas (solo lectura; si algo falla, no se toca nada):"
cd /var/www/api
g fetch -q origin
ACTUAL=$(g rev-parse --short=7 HEAD)
case "$ACTUAL" in
  "$PREVIO")   REANUDA=0 ;;
  "$ESPERADO") REANUDA=1; echo "   reanudación: el código ya está en $ESPERADO (una ejecución anterior se cortó); se repiten variable, migración y comprobaciones" ;;
  *) aborta "producción no está en $PREVIO ni en $ESPERADO (HEAD $ACTUAL): si se desplegó otra cosa después del H6, revisar qué entró y actualizar PREVIO en un commit" ;;
esac
MODIFICADOS=$(g status --porcelain --untracked-files=no | wc -l | tr -d ' ')
[ "$MODIFICADOS" = 0 ] || aborta "el checkout de producción tiene $MODIFICADOS cambios locales"
[ "$(g rev-parse --short=7 origin/main)" = "$ESPERADO" ] || aborta "origin/main es $(g rev-parse --short=7 origin/main), no $ESPERADO"
g merge-base --is-ancestor "$PREVIO" origin/main || aborta "origin/main no es avance rápido desde $PREVIO"
NUEVAS=$(g diff --name-only --diff-filter=AMDR "$PREVIO" origin/main -- backend/database/migrations | sort | tr '\n' ' ')
[ "$NUEVAS" = "$MIGRACIONES" ] || aborta "migraciones nuevas o tocadas entre $PREVIO y origin/main distintas de la del Hito 7: [$NUEVAS]"
EN_PUERTA=$(g diff --name-only "$PREVIO" origin/main -- "${PUERTA[@]}" | tr '\n' ' ')
FUERA=""
for f in $EN_PUERTA; do case " $PERMITIDOS_EN_PUERTA " in *" $f "*) ;; *) FUERA="$FUERA $f" ;; esac; done
[ -z "$FUERA" ] || aborta "la puerta de diff tiene ficheros que el contrato del H7 no nombra (motor, integraciones existentes, catálogo o dependencias):$FUERA"
BORRADAS=$(g diff --numstat "$PREVIO" origin/main -- backend/routes/console.php | awk '{print $2}')
[ -z "$BORRADAS" ] || [ "$BORRADAS" = 0 ] || aborta "routes/console.php pierde $BORRADAS líneas entre $PREVIO y origin/main: el H7 solo añade el bloque del monitor"
case "$(cat "$BK/api-commit.txt" 2>/dev/null)" in
  "$PREVIO"*) ;;
  "$ESPERADO"*) [ "$REANUDA" = 1 ] || aborta "$BK/api-commit.txt es $ESPERADO pero producción sigue en $PREVIO: el respaldo no es de antes del despliegue" ;;
  *) aborta "$BK/api-commit.txt no es $PREVIO" ;;
esac
[ -s "$BK/ironbody.dump" ] && [ -n "$(find "$BK/ironbody.dump" -mmin -120)" ] || aborta "no hay volcado de menos de 2 h en $BK"
[ "$(stat -c '%a' "$BK")" = 700 ] || aborta "el directorio del respaldo tiene que ser 700 (es $(stat -c '%a' "$BK"))"
[ "$(stat -c '%U %a' "$BK/ironbody.dump")" = "root 600" ] && [ "$(stat -c '%U %a' "$BK/env.backup")" = "root 600" ] || aborta "el volcado y env.backup tienen que ser de root y 600"
if [ -f "$BK/ironbody.dump.sha256" ]; then sha256sum -c --status "$BK/ironbody.dump.sha256" || aborta "el volcado no coincide con su ironbody.dump.sha256"; fi
TABLAS=$(pg_restore -l "$BK/ironbody.dump" | grep -c 'TABLE DATA' || true)
[ "$TABLAS" -ge "$MIN_TABLAS" ] || aborta "el volcado solo lista $TABLAS tablas con datos (al menos $MIN_TABLAS; contarlas a mano antes de pensar en bajar el umbral)"
if [ "$REANUDA" = 0 ]; then cmp -s "$ENVF" "$BK/env.backup" || aborta "el fichero de entorno cambió desde el respaldo: rehacer el respaldo (env.backup tiene que ser el de partida)"; fi
echo "   respaldo $BK: $TABLAS tablas con datos · 700/600 de root"
[ -z "$(tail -c1 "$ENVF")" ] || echo "   el fichero de entorno no acaba en salto de línea: se añadirá uno antes de la clave"
for kv in $VARS; do
  case "$(estado_var "${kv%%=*}" "${kv#*=}")" in
    falta|igual) ;;
    *) aborta "${kv%%=*} ya existe con otro valor o repetida (no se imprime): el monitor se enciende después, con autorización; revisar a mano" ;;
  esac
done
case "$(cuenta_var ULTRON_STUDIO_MONITOR_EXPECT)" in 0) ;; *) echo "   AVISO: ULTRON_STUDIO_MONITOR_EXPECT está en el entorno (no se imprime): la expectativa del monitor no será la del código" ;; esac
PRE=$($PSQL -c "SELECT (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron_studio_%')||' '||(SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE 'ultron_studio_%')||' '||(SELECT count(*) FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid WHERE t.relname LIKE 'ultron_studio_%' AND i.indpred IS NOT NULL)||' '||(SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'ultron_studio_%')||' '||(SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE proname = 'ultron_studio_forbid')||' '||(SELECT string_agg(tableowner, ',' ORDER BY tablename) FROM pg_tables WHERE schemaname = 'public' AND tablename IN ($INSERCION))||' '||has_schema_privilege('iron', 'public', 'CREATE')||' '||(SELECT count(*) FROM pg_roles WHERE rolname = 'iron_app')||' '||(SELECT count(*) FROM pg_trigger WHERE NOT tgisinternal AND tgname = 'uspub_chain_next');") || aborta "no se pudo leer el esquema de partida (psql; ¿existe el rol iron?)"
if [ "$REANUDA" = 0 ]; then
  [ "$PRE" = "43 49 4 14 iron iron,iron,iron true 1 0" ] || aborta "esquema de partida inesperado: [$PRE] (esperado [43 49 4 14 iron iron,iron,iron true 1 0]: el del H6 con iron_app); si la cadena, el libro o las instantáneas no son de iron, la migración falla con 42501"
else
  case "$PRE" in *" iron iron,iron,iron true 1 "*) ;; *) aborta "dueños, permiso CREATE o rol iron_app inesperados: [$PRE]" ;; esac
fi
HUELLA=$($PSQL -c "$Q_HUELLA") || aborta "no se pudo leer ultron_studio_forbid() (psql)"
[ "$HUELLA" = "$HUELLA_F" ] || aborta "ultron_studio_forbid() no es la de F (md5 $HUELLA, esperado $HUELLA_F): PARAR y avisar a database-specialist"
ROTAS=$($PSQL -c "SELECT count(*) FROM (SELECT seq, previous_publication_id, row_number() OVER (ORDER BY seq) AS rn, lag(id) OVER (ORDER BY seq) AS prev_id FROM ultron_studio_publications) x WHERE seq <> rn OR previous_publication_id IS DISTINCT FROM prev_id;") || aborta "no se pudo leer la cadena (psql)"
[ "$ROTAS" = 0 ] || aborta "la cadena de publicaciones tiene $ROTAS entradas no consecutivas o mal encadenadas: la migración se NEGARÍA; la reparación es del rol dueño con la autorización del propietario (runbook del H5, AS-3/N-3)"
BLOQ=$($PSQL -c "$Q_BLOQ") || aborta "no se pudieron leer los cerrojos (psql)"
[ "$BLOQ" = 0 ] || aborta "$BLOQ cerrojos sobre la cadena (la migración pide ACCESS EXCLUSIVE, que choca también con un SELECT abierto): esperar y repetir; quién los tiene, con h7-schema-check.sql"
PRIV0=$($PSQL -c "$Q_PRIV") || aborta "no se pudieron leer los privilegios de iron_app (psql)"
FILAS0=$($PSQL -c "$Q_FILAS") || aborta "no se pudieron contar las filas (psql)"
RUTAS0=$(rutas)
[ "$RUTAS0" = "56 18 8 10" ] || aborta "rutas del Studio en la caché de producción: [$RUTAS0] (esperado [56 18 8 10], el H6)"
PREVIA_ANTES=$(leer_previa)
[ -n "$PREVIA_ANTES" ] || aborta "no se pudo leer la configuración efectiva del motor, del H5 y del H6 (tinker falló; su salida no se imprime)"
W0=$(workers 20)
[ "$W0" = "13 0" ] || aborta "workers antes de empezar: $W0 (RUNNING, BACKOFF/FATAL; esperado 13 0): no se despliega sobre un sistema degradado"
FACT=$(systemctl is-active ironbody-billing-worker 2>/dev/null || true)
[ "$FACT" = active ] || aborta "ironbody-billing-worker no está activo antes de empezar ($FACT)"
LOG_ANTES=$(lineas_log)
FALLIDAS_ANTES=$($PSQL -c "SELECT count(*) FROM failed_jobs;") || aborta "no se pudo leer failed_jobs (psql)"
SELLOS_ANTES=$($PSQL -c "SELECT count(*) FROM marketing_ai_actions WHERE metadata ? 'studio';") || aborta "no se pudo leer marketing_ai_actions (psql)"
INST_ANTES=$($PSQL -c "SELECT count(*) FROM ultron_studio_integration_snapshots;") || aborta "no se pudieron contar las instantáneas (psql)"
INCID_ANTES=$($PSQL -c "SELECT count(*) FROM incidents WHERE source = 'ultron_studio';") || aborta "no se pudieron contar los incidentes (psql)"
echo "   HEAD $ACTUAL limpio · migración: 1 · puerta: [$EN_PUERTA] (todas nombradas por el contrato) · esquema [$PRE] · función de F · cadena consecutiva · sin cerrojos · rutas [$RUTAS0] · workers 13/0 · facturación activa"
echo "   iron_app (SELECT/INSERT/UPDATE/DELETE/TRUNCATE/secuencia) en libro · asociaciones · canarios · instantáneas · cadena: $PRIV0"
echo "   filas cadena · libro · instantáneas: $FILAS0 · colas fallidas: $FALLIDAS_ANTES · sellos studio: $SELLOS_ANTES · incidentes ultron_studio: $INCID_ANTES"
echo "   motor, H4, H5 y H6 (informativo; no cambia): $PREVIA_ANTES"
[ "$SOLO_COMPROBAR" != --solo-comprobar ] || { echo "COMPROBACIONES PREVIAS EN VERDE (no se ha tocado nada)"; exit 0; }

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
[ "$ILEGIBLES" = 0 ] || aborta "$ILEGIBLES de $REVISADOS ficheros del merge no los lee www-data: chmod 644 (ficheros) / 755 (directorios) sobre git diff --name-only $PREVIO HEAD antes de seguir"
echo "   los $REVISADOS ficheros del merge son legibles por www-data"
cd backend
echo "2. variable (monitor apagado):"
[ -z "$(tail -c1 "$ENVF")" ] || printf '\n' >> "$ENVF"
for kv in $VARS; do
  k=${kv%%=*}; v=${kv#*=}
  if [ "$(estado_var "$k" "$v")" = falta ]; then printf '  %s=%s\n' "$k" "$v" >> "$ENVF"; echo "   $k añadida"; else echo "   $k ya estaba con el valor del despliegue"; fi
done
echo "   permisos del fichero: $(stat -c '%U %a' "$ENVF")"
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
  ESQ=$($PSQL -c "$Q_ESQUEMA") || aborta "no se pudo leer el esquema tras ironbody-migrate (psql); relanzar este mismo script (reanuda)"
  [ "$ESQ" != "44 50 4 14" ] || break
  case "$SALIDA" in
    *55P03*|*"lock timeout"*)
      if [ "$INTENTO" -lt 3 ]; then echo "   intento $INTENTO: 55P03 (alguien tenía la cadena) · esquema [$ESQ] · reintento en 15 s"; sleep 15; continue; fi ;;
  esac
  aborta "esquema tras ironbody-migrate [$ESQ], esperado [44 50 4 14] (código $RC, intento $INTENTO). La migración es una transacción: o está entera o no está (con [43 49 4 14] no se aplicó). El código nuevo sirve igual (no hay rutas nuevas): falta la guarda. Ver quién tiene la cadena con h7-schema-check.sql y relanzar este mismo script (reanuda)"
done
echo "   esquema 44 disparadores · 50 CHECK · 4 parciales · 14 tablas (intento $INTENTO)"
[ "$RC" = 0 ] || desvio "ironbody-migrate salió con $RC aunque el esquema cuadra: revisar su salida (arriba) antes de dar el despliegue por bueno"
echo "4. esquema del Hito 7:"
POST=$($PSQL -c "SELECT (SELECT string_agg(tgname || ':' || tgenabled, ',' ORDER BY tgname) FROM pg_trigger WHERE NOT tgisinternal AND tgrelid = 'public.ultron_studio_publications'::regclass)||' '||(SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = 'uspub_seq_chk' AND conrelid = 'public.ultron_studio_publications'::regclass)||' '||(SELECT pg_get_userbyid(proowner)||'/'||prosecdef FROM pg_proc WHERE proname = 'ultron_studio_publication_chain')||' '||(SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron_studio_%' AND t.tgenabled <> 'O');")
echo "   disparadores de la cadena · CHECK de seq · dueño/definer de la función · disparadores apagados: $POST"
[ "$POST" = "uspub_chain_next:O,uspub_forbid_delete:O,uspub_forbid_truncate:O,uspub_forbid_update:O CHECK ((seq >= 1)) iron/false 0" ] || aborta "esquema del H7 [$POST], esperado [uspub_chain_next:O,uspub_forbid_delete:O,uspub_forbid_truncate:O,uspub_forbid_update:O CHECK ((seq >= 1)) iron/false 0]; revisar con h7-schema-check.sql y database-specialist"
[ "$($PSQL -c "$Q_HUELLA")" = "$HUELLA_F" ] || aborta "ultron_studio_forbid() cambió de cuerpo con la migración"
PRIV=$($PSQL -c "$Q_PRIV")
echo "   iron_app (SELECT/INSERT/UPDATE/DELETE/TRUNCATE/secuencia) en libro · asociaciones · canarios · instantáneas · cadena: $PRIV"
[ "$PRIV" = "true/true/false/false/false/true true/true/true/true/false/true true/true/true/true/false/true true/true/false/false/false/true true/true/false/false/false/true" ] \
  || aborta "privilegios de iron_app [$PRIV], esperado UPDATE y DELETE en false solo en el libro, las instantáneas y la cadena, y SELECT/INSERT/secuencia en true en las cinco"
FILAS=$($PSQL -c "$Q_FILAS")
[ "$FILAS" = "$FILAS0" ] && echo "   filas de la cadena, el libro y las instantáneas: $FILAS (las mismas: la migración no toca filas)" || desvio "las filas cambiaron durante la migración: antes [$FILAS0] · después [$FILAS] (¿alguien escribió en el Studio?)"
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
[ "$RUTAS" = "56 18 8 10" ] || desvio "rutas del Studio [$RUTAS] (esperado [56 18 8 10]: el H7 no añade rutas)"
[ -z "$COBERTURA" ] || desvio "auth:coverage --unmapped no está vacío"
echo "7. configuración efectiva:"
H7=$(leer_h7)
case "$H7" in '{"monitor_enabled"'*) echo "   $H7" ;; *) echo "   (tinker no devolvió la configuración del H7; su salida no se imprime)" ;; esac
[ "$H7" = "$ESPERADA_H7" ] || { desvio "la configuración efectiva del monitor no es la del despliegue (apagado, expectativa del código)"; echo "     esperada: $ESPERADA_H7"; }
PROGRAMADAS=$(artisan schedule:list 2>/dev/null | grep -c 'ultron-studio:' || true)
echo "   tareas programadas del Studio: $PROGRAMADAS (con el monitor apagado, 0)"; [ "$PROGRAMADAS" = 0 ] || desvio "el programador tiene $PROGRAMADAS tareas del Studio con el monitor apagado"
PREVIA=$(leer_previa)
[ "$PREVIA" = "$PREVIA_ANTES" ] && echo "   motor, H4, H5 y H6 iguales que antes del despliegue" || desvio "la configuración del motor, del H4, del H5 o del H6 cambió: antes $PREVIA_ANTES · después ${PREVIA:-ilegible}"
sleep 6
W1=$(workers 45); sleep 8; W2=$(workers 45)
echo "8. workers (RUNNING · BACKOFF/FATAL), dos lecturas separadas: [$W1] y [$W2]"
[ "$W1" = "13 0" ] && [ "$W2" = "13 0" ] || desvio "workers [$W1] y [$W2] (esperado 13 0 las dos veces)"
PIDS_DESPUES=$(supervisorctl status 2>/dev/null | grep -oE 'pid [0-9]+' | sort || true)
NUEVOS=$(comm -13 <(printf '%s\n' "$PIDS_ANTES") <(printf '%s\n' "$PIDS_DESPUES") | grep -c pid || true)
echo "   INFO: workers con pid nuevo tras queue:restart: $NUEVOS de 13 (el resto termina su trabajo antes de reiniciarse)"
FACT=$(systemctl is-active ironbody-billing-worker 2>/dev/null || true)
[ "$FACT" = active ] || desvio "ironbody-billing-worker: $FACT"
echo "9. sanidad:"
# `Cache-Control: no-store, private` con curl -I (HEAD): en las respuestas sin sesión, en un 404 de /api/admin y en una
# ruta administrativa fuera del Studio. La cabecera la pone el middleware global más externo (B7-a).
for p in admin/ultron-studio/status admin/ultron-studio/publications admin/ultron-studio/integrations/status admin/auth/me admin/no-existe-h7; do
  CAB=$(curl -sI -H 'Accept: application/json' "$API/$p" | tr -d '\r')
  c=$(printf '%s\n' "$CAB" | awk 'NR == 1 {print $2}')
  cc=$(printf '%s\n' "$CAB" | awk -F': *' 'tolower($1) == "cache-control" {print $2}' | tail -1)
  echo "   HEAD $p sin token → $c · Cache-Control: ${cc:-(ninguna)}"
  case "$p" in admin/no-existe-h7) [ "$c" = 404 ] || desvio "HEAD $p respondió $c (esperado 404)" ;; *) [ "$c" = 401 ] || desvio "HEAD $p respondió $c (esperado 401)" ;; esac
  [ "$cc" = "no-store, private" ] || desvio "HEAD $p: Cache-Control «${cc:-ninguna}» (esperado «no-store, private»)"
done
# El tope de 64 KB del Studio antes de TrimStrings: un cuerpo de 70 000 bytes sin sesión → 413 payload_too_large (antes
# del 401). Nada que escribir: ni siquiera llega a la autenticación.
GRANDE=$(python3 -c 'import json; print(json.dumps({"label": "x" * 70000}))')
R413=$(printf '%s' "$GRANDE" | curl -s -o - -w ' %{http_code}' -X POST -H 'Accept: application/json' -H 'Content-Type: application/json' --data-binary @- "$API/admin/ultron-studio/versions" | tail -c 200)
echo "   POST versions con 70 000 bytes sin token → ${R413##* } $(printf '%s' "$R413" | grep -o '"code":"[a-z_]*"' || true)"
case "$R413" in *'"code":"payload_too_large"'*' 413') ;; *) desvio "el cuerpo de 70 000 bytes sin sesión no dio 413 payload_too_large (¿el tope sigue detrás de la autenticación?)" ;; esac
c=$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Accept: application/json' "$API/webhooks/twilio/usage-trigger")
echo "   usage-trigger sin firma → $c (falla cerrada; T-1)"; [ "$c" = 403 ] || desvio "usage-trigger sin firma respondió $c (esperado 403)"
FALLIDAS=$($PSQL -c "SELECT count(*) FROM failed_jobs;")
echo "   colas fallidas: $FALLIDAS (antes $FALLIDAS_ANTES)"; [ "$FALLIDAS" -le "$FALLIDAS_ANTES" ] || desvio "colas fallidas nuevas durante el despliegue"
SELLOS=$($PSQL -c "SELECT count(*) FROM marketing_ai_actions WHERE metadata ? 'studio';")
case "$PREVIA_ANTES" in
  *'"engine_scope":"off"'*) echo "   sellos studio en marketing_ai_actions (motor en off): $SELLOS"; [ "$SELLOS" = 0 ] || desvio "hay sellos del Studio con el motor en off" ;;
  *) echo "   sellos studio en marketing_ai_actions (motor leyendo; informativo): $SELLOS_ANTES → $SELLOS" ;;
esac
artisan security:check-secret-exposure > /dev/null 2>&1 && echo "   security:check-secret-exposure: OK" || desvio "security:check-secret-exposure no pasa"
artisan ultron-studio:publication-check > /dev/null 2>&1 && echo "   ultron-studio:publication-check: 0 (cuadra)" || desvio "ultron-studio:publication-check no sale con 0"
# El monitor a mano y en seco (no abre incidentes): la publicación y las integraciones; las colas, en la verificación
# de los 15 minutos (justo tras queue:restart un carril puede tener cola sin latido unos segundos).
if artisan ultron-studio:monitor --check=publication --check=integrations --dry-run > /dev/null 2>&1; then echo "   ultron-studio:monitor --dry-run (publicación e integraciones): 0"; else desvio "ultron-studio:monitor --dry-run no sale con 0 (ejecutarlo a mano como www-data para ver qué no cuadra)"; fi
INCID=$($PSQL -c "SELECT count(*) FROM incidents WHERE source = 'ultron_studio';")
[ "$INCID" = "$INCID_ANTES" ] && echo "   incidentes ultron_studio: $INCID (los mismos)" || desvio "incidentes ultron_studio: $INCID_ANTES → $INCID durante el despliegue"
P="^[[:space:]]*(export[[:space:]]+)?($CLAVES_H7)[[:space:]]*="
if cmp -s <(grep -vE "$P" "$ENVF" | sed '/^$/d') <(grep -vE "$P" "$BK/env.backup" | sed '/^$/d'); then echo "   fichero de entorno igual al respaldo salvo la clave del Hito 7"; else desvio "el fichero de entorno difiere del respaldo en algo más que la clave del Hito 7 (comparar a mano sin imprimir valores)"; fi
N=0; for kv in $VARS; do if [ "$(estado_var "${kv%%=*}" "${kv#*=}")" = igual ]; then N=$((N + 1)); fi; done
[ "$N" = 1 ] || desvio "la clave del Hito 7 no está una vez y con su valor"
echo "10. integrations-doctor (las clasificaciones del H6, sin cambios):"
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
  desvio "meta_ads no es connected_read ni degraded"
fi
echo "11. integrations-snapshot (el H7 no cambia ninguna integración: no tiene que anotar nada):"
if artisan ultron-studio:integrations-snapshot > /dev/null 2>&1; then
  INST=$($PSQL -c "SELECT count(*) FROM ultron_studio_integration_snapshots;")
  echo "   instantáneas: $INST_ANTES → $INST"
  [ "$INST" = "$INST_ANTES" ] || desvio "integrations-snapshot anotó $((INST - INST_ANTES)) instantánea(s): la huella de alguna integración cambió con el H7 (revisar con GET integrations/config antes de seguir)"
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
ESPERADOS=$(( BLOQUEOS < INTENTO - 1 ? BLOQUEOS : INTENTO - 1 ))
ERRORES=$((ERRORES - ESPERADOS))
echo "12. laravel.log ERROR desde el inicio del despliegue: $ERRORES$([ "$ESPERADOS" = 0 ] || echo " (más $ESPERADOS del 55P03 reintentado en el paso 3, esperados)")"; [ "$ERRORES" = 0 ] || desvio "$ERRORES errores en el log desde el inicio"
[ "$(g -C /var/www/api status --porcelain --untracked-files=no | wc -l | tr -d ' ')" = 0 ] || desvio "el checkout tiene cambios tras el despliegue"
if [ "$DESVIOS" = 0 ]; then echo "DESPLIEGUE H7 (BACKEND) COMPLETADO"; else echo "DESPLIEGUE H7 (BACKEND) CON $DESVIOS DESVÍOS: no seguir con el smoke hasta aclararlos"; exit 1; fi

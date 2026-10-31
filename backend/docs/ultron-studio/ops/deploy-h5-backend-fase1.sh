#!/bin/bash
# Fase 1 del Hito 5 en producción: código desplegado con TODO apagado (el motor no lee la cadena y publicar sigue
# en 409). Se ejecuta EN EL SERVIDOR como root tras el respaldo. Orden: comprobaciones de solo lectura (abortan sin
# tocar nada) → código → variables ANTES de ironbody-migrate (regenera la caché como www-data) → route:cache →
# cachés www-data 600 → queue:restart → aserciones SQL/rutas/config → sanidad. Cada aserción que falla se dice y
# el script termina con 1; «COMPLETADA» solo sale con todo en verde. Nunca imprime valores del fichero de entorno.
# Uso: bash /root/deploy-h5-backend-fase1.sh <commit esperado> <directorio del respaldo de hoy> [--solo-comprobar]
#   --solo-comprobar: ejecuta solo el paso 0 (lecturas y git fetch) y sale; es el ensayo en el servidor.
set -euo pipefail
export LC_ALL=C
# git como ROOT (I-3 del reviewer, comprobado en el servidor el 31-10-2026): desde el 25-10 el árbol de /var/www/api
# (2.493 entradas, entre ellas todo lo que H5 cambia) y .git (5.089 de 5.131 entradas) son de root, y www-data no
# puede escribir ni en objects/ ni en los directorios del código; un merge como www-data fallaría a medias. Código y
# .git de root, legibles por www-data, es la postura más segura (PHP-FPM no puede reescribir el código); la M-3
# original (hooks/config de un .git de www-data ejecutados por root) ya no se da: .git/config y hooks/ no tienen
# hooks ni core.hooksPath, y root tiene safe.directory=/var/www/api. umask 022 para que lo que git reescriba quede
# 644/755 (memoria «umask 077 y git merge en el VPS»), y tras el merge se comprueba que www-data lee cada fichero.
umask 022
g() { git "$@"; }
ESPERADO="${1:?commit esperado en origin/main}"
BK="${2:?directorio del respaldo de hoy (/var/backups/ironbody/ultron-studio-h5-<fecha UTC>)}"
SOLO_COMPROBAR="${3:-}"
case "$SOLO_COMPROBAR" in ""|--solo-comprobar) ;; *) echo "tercer argumento desconocido: $SOLO_COMPROBAR (solo se admite --solo-comprobar); no se ha tocado nada"; exit 2 ;; esac   # I-2 del reviewer: un flag mal escrito no puede convertirse en el despliegue entero
PREVIO=7318122                                   # lo que sirve producción antes del Hito 5 (origin/main del 29-10-2026; antes fef7c87)
PSQL="sudo -u postgres psql -X -Atq -v ON_ERROR_STOP=1 -d ironbody"
aborta() { echo "ABORTA: $*"; exit 1; }
DESVIOS=0
desvio() { echo "   ✗ $*"; DESVIOS=$((DESVIOS + 1)); }
VARS="MARKETING_ULTRON_STUDIO_CONFIG=off ULTRON_STUDIO_PUBLISHING_ENABLED=false ULTRON_STUDIO_PUBLISH_LAB_GATE=required ULTRON_STUDIO_CANARY_MIN_HOURS=24 ULTRON_STUDIO_CANARY_MIN_TURNS=3 ULTRON_STUDIO_PUBLISH_WINDOW=07:00-19:00 QUEUE_STUDIO_LAB_UNATTENDED_AFTER_SECONDS=300"
ENVF=/var/www/api/backend/.env
estado_var() { # CLAVE VALOR → falta | igual | distinto | repetida (sin imprimir el valor)
  local n v
  n=$(grep -Ec "^[[:space:]]*(export[[:space:]]+)?$1[[:space:]]*=" "$ENVF" || true)
  [ "$n" = 0 ] && { echo falta; return; }
  [ "$n" = 1 ] || { echo repetida; return; }
  v=$(grep -E "^[[:space:]]*(export[[:space:]]+)?$1[[:space:]]*=" "$ENVF" | sed -E "s/^[[:space:]]*(export[[:space:]]+)?$1[[:space:]]*=[[:space:]]*//; s/[[:space:]]+\$//; s/^\"(.*)\"\$/\\1/")
  [ "$v" = "$2" ] && echo igual || echo distinto
}
LOGS=/var/www/api/backend/storage/logs
lineas_log() { local f; for f in "$LOGS"/laravel*.log; do [ -f "$f" ] && echo "$f $(wc -l < "$f" | tr -d ' ')"; done; return 0; }

echo "0. comprobaciones previas (solo lectura; si algo falla, no se toca nada):"
cd /var/www/api
g fetch -q origin
ACTUAL=$(g rev-parse --short=7 HEAD)                 # --short=7 (m-6): la abreviatura automática crece con el número de objetos
case "$ACTUAL" in
  "$PREVIO")   REANUDA=0 ;;
  "$ESPERADO") REANUDA=1; echo "   reanudación: el código ya está en $ESPERADO (una ejecución anterior se cortó); se repiten variables, migración y comprobaciones" ;;
  *) aborta "producción no está en $PREVIO ni en $ESPERADO (HEAD $ACTUAL)" ;;
esac
MODIFICADOS=$(g status --porcelain --untracked-files=no | wc -l | tr -d ' ')   # sin grep: con pipefail, un grep sin coincidencias mataría el script
[ "$MODIFICADOS" = 0 ] || aborta "el checkout de producción tiene $MODIFICADOS cambios locales"
[ "$(g rev-parse --short=7 origin/main)" = "$ESPERADO" ] || aborta "origin/main es $(g rev-parse --short=7 origin/main), no $ESPERADO"
g merge-base --is-ancestor "$PREVIO" origin/main || aborta "origin/main no es avance rápido desde $PREVIO"
NUEVAS=$(g diff --name-only --diff-filter=AMDR "$PREVIO" origin/main -- backend/database/migrations | sort | tr '\n' ' ')
[ "$NUEVAS" = "backend/database/migrations/2026_10_25_100001_create_ultron_studio_publications_table.php backend/database/migrations/2026_10_25_100002_add_ultron_studio_publication_guards.php " ] \
  || aborta "migraciones nuevas o tocadas entre $PREVIO y origin/main distintas de las dos del Hito 5: [$NUEVAS]"
case "$(cat "$BK/api-commit.txt" 2>/dev/null)" in "$PREVIO"*) ;; *) aborta "$BK/api-commit.txt no es $PREVIO" ;; esac
[ -s "$BK/ironbody.dump" ] && [ -n "$(find "$BK/ironbody.dump" -mmin -120)" ] || aborta "no hay volcado de menos de 2 h en $BK"
[ "$(stat -c '%a' "$BK/ironbody.dump")" = 600 ] && [ "$(stat -c '%a' "$BK/env.backup")" = 600 ] || aborta "el volcado y env.backup tienen que ser 600"
TABLAS=$(pg_restore -l "$BK/ironbody.dump" | grep -c 'TABLE DATA' || true)
[ "$TABLAS" -ge 211 ] || aborta "el volcado solo lista $TABLAS tablas con datos (al menos 211)"
echo "   respaldo $BK: $TABLAS tablas con datos"
[ -z "$(tail -c1 "$ENVF")" ] || echo "   el fichero de entorno no acaba en salto de línea: se añadirá uno antes de las claves"
for kv in $VARS; do
  case "$(estado_var "${kv%%=*}" "${kv#*=}")" in
    falta|igual) ;;
    *) aborta "${kv%%=*} ya existe con otro valor o repetida (no se imprime): revisar a mano antes de desplegar" ;;
  esac
done
PRE=$($PSQL -c "SELECT (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron_studio_%')||' '||(SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE 'ultron_studio_%')||' '||(SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE proname = 'ultron_studio_forbid')||' '||(SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'ultron_studio_versions')||' '||has_schema_privilege('iron', 'public', 'CREATE')||' '||(to_regclass('public.ultron_studio_publications') IS NOT NULL);")
if [ "$REANUDA" = 0 ]; then
  [ "$PRE" = "27 24 iron iron true false" ] || aborta "esquema de partida inesperado: [$PRE] (esperado [27 24 iron iron true false]); si la función o versiones no son de iron, la 100002 falla con 42501"
else
  case "$PRE" in *" iron iron true "*) ;; *) aborta "dueños o permiso CREATE inesperados: [$PRE]" ;; esac
fi
HUELLA=$($PSQL -c "SELECT md5(pg_get_functiondef(oid)) FROM pg_proc WHERE proname = 'ultron_studio_forbid';")
BLOQ=$($PSQL -c "SELECT count(*) FROM pg_locks l JOIN pg_class c ON c.oid = l.relation WHERE c.relname = 'ultron_studio_versions' AND l.pid <> pg_backend_pid();")
[ "$BLOQ" = 0 ] || aborta "$BLOQ cerrojos sobre ultron_studio_versions (la 100002 pide ACCESS EXCLUSIVE, que choca también con un SELECT abierto): esperar y repetir"
LOG_ANTES=$(lineas_log)                          # para contar solo los ERROR que se escriban desde aquí
FALLIDAS_ANTES=$($PSQL -c "SELECT count(*) FROM failed_jobs;")   # por SQL: en Laravel 12 queue:failed ya no pinta «| id |» y el grep de H4 contaba siempre 0
[ "$REANUDA" = 0 ] && ESQUEMA="esquema 27/24 de iron" || ESQUEMA="reanudación: dueños iron y CREATE comprobados"
echo "   HEAD $ACTUAL limpio · $ESQUEMA · sin cerrojos · claves del Hito 5 ausentes o iguales · colas fallidas: $FALLIDAS_ANTES"
[ "$SOLO_COMPROBAR" != --solo-comprobar ] || { echo "COMPROBACIONES PREVIAS EN VERDE (no se ha tocado nada)"; exit 0; }

g merge -q --ff-only origin/main
HEAD=$(g rev-parse --short=7 HEAD)
[ "$HEAD" = "$ESPERADO" ] || aborta "HEAD $HEAD no es $ESPERADO"
echo "1. código: HEAD=$HEAD"
# Lo que el merge reescribió lo tiene que poder leer www-data (PHP-FPM y los workers); un fichero 600 de root
# dejaría un 500 silencioso. Se comprueba fichero a fichero sobre el diff real, no sobre todo el árbol.
ILEGIBLES=0
while IFS= read -r f; do
  [ -e "$f" ] || continue                                     # borrado por el merge
  sudo -u www-data test -r "$f" || { echo "   ✗ www-data no puede leer $f ($(stat -c '%U:%G %a' "$f"))"; ILEGIBLES=$((ILEGIBLES + 1)); }
done < <(g diff --name-only "$PREVIO" HEAD)
[ "$ILEGIBLES" = 0 ] || aborta "$ILEGIBLES ficheros del merge no los lee www-data: chmod 644 (ficheros) / 755 (directorios) sobre git diff --name-only $PREVIO HEAD antes de seguir"
echo "   todos los ficheros del merge son legibles por www-data"
cd backend
echo "2. variables (todo apagado):"
[ -z "$(tail -c1 "$ENVF")" ] || printf '\n' >> "$ENVF"
for kv in $VARS; do
  k=${kv%%=*}; v=${kv#*=}
  if [ "$(estado_var "$k" "$v")" = falta ]; then printf '  %s=%s\n' "$k" "$v" >> "$ENVF"; echo "   $k añadida"; else echo "   $k ya estaba con el valor de la fase 1"; fi
done
echo "   permisos del fichero: $(stat -c '%U %a' "$ENVF")"
# B-1: el fichero tiene que parsear ANTES de que ironbody-migrate vacíe la caché (solo se imprime la clase del error).
sudo -u www-data php -r 'require "vendor/autoload.php"; try { Dotenv\Dotenv::createArrayBacked(".")->load(); } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' \
  || aborta "el fichero de entorno NO parsea: restáuralo desde $BK/env.backup (mismo dueño y modo 600) antes de seguir"
echo "   el fichero de entorno parsea"
echo "3. migración (rol dueño, lock_timeout 5 s):"
sudo env PGOPTIONS="-c lock_timeout=5s" /usr/local/bin/ironbody-migrate 2>&1 | sed 's/^/   /'
sudo -u www-data sh -c 'umask 077; php artisan route:cache -q'
for f in bootstrap/cache/config.php bootstrap/cache/routes-v7.php; do
  [ "$(stat -c '%U' "$f")" = www-data ] || aborta "$f no es de www-data: no se reinician los workers (quedarían en BACKOFF)"
  [ "$(stat -c '%a' "$f")" = 600 ] || { chmod 600 "$f"; echo "   $f pasado a 600"; }
done
echo "   cachés: $(stat -c '%U %a' bootstrap/cache/config.php) · $(stat -c '%U %a' bootstrap/cache/routes-v7.php)"
sudo -u www-data php artisan queue:restart -q
echo "4. SQL:"
POST=$($PSQL -c "SELECT (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron_studio_%')||' '||(SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE 'ultron_studio_%')||' '||(SELECT count(*) FROM pg_trigger WHERE tgname LIKE 'uspub_%')||' '||(SELECT count(*) FROM pg_constraint WHERE contype = 'c' AND conname LIKE 'uspub_%')||' '||(SELECT count(*) FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname IN ('usv_one_canary', 'usv_one_published') AND i.indisunique AND i.indpred IS NOT NULL)||' '||(SELECT pg_get_constraintdef(oid) LIKE '%ROLLED_BACK%' FROM pg_constraint WHERE conname = 'usv_status_chk')||' '||(SELECT pg_get_userbyid(proowner) FROM pg_proc WHERE proname = 'ultron_studio_forbid')||' '||(SELECT tableowner FROM pg_tables WHERE schemaname = 'public' AND tablename = 'ultron_studio_publications')||' '||(SELECT count(*) FROM ultron_studio_publications);")
echo "   disparadores · CHECK · uspub disp. · uspub CHECK · parciales · 9 estados · dueño función · dueño cadena · filas: $POST"
[ "$POST" = "30 27 3 3 2 true iron iron 0" ] || desvio "esquema tras migrar [$POST], esperado [30 27 3 3 2 true iron iron 0] (si la 100002 cayó por 55P03, relanzar este mismo script: reanuda)"
[ "$($PSQL -c "SELECT md5(pg_get_functiondef(oid)) FROM pg_proc WHERE proname = 'ultron_studio_forbid';")" = "$HUELLA" ] || desvio "la función ultron_studio_forbid cambió de cuerpo"
# m-5: has_table_privilege con una lista responde true si se tiene CUALQUIERA de los privilegios; SELECT e INSERT se preguntan por separado.
PRIV=$($PSQL -c "SELECT has_table_privilege('iron_app', 'public.ultron_studio_publications', 'SELECT')||' '||has_table_privilege('iron_app', 'public.ultron_studio_publications', 'INSERT')||' '||has_table_privilege('iron_app', 'public.ultron_studio_publications', 'TRUNCATE')||' '||has_sequence_privilege('iron_app', 'public.ultron_studio_publications_id_seq', 'USAGE');")
echo "   iron_app sobre la cadena (SELECT · INSERT · TRUNCATE · secuencia): $PRIV"
[ "$PRIV" = "true true false true" ] || desvio "privilegios de iron_app sobre la cadena [$PRIV], esperado [true true false true]"
# UPDATE y DELETE los tiene iron_app por los privilegios por defecto del esquema; lo que hace la cadena de solo
# inserción son los disparadores uspub_* (3/3, afirmados arriba). Se enseñan como información; revocarlos es un
# refuerzo candidato del Hito 7 (migración aditiva del rol dueño).
MODIF=$($PSQL -c "SELECT has_table_privilege('iron_app', 'public.ultron_studio_publications', 'UPDATE')||' '||has_table_privilege('iron_app', 'public.ultron_studio_publications', 'DELETE');")
echo "   iron_app UPDATE · DELETE sobre la cadena (informativo; los paran los disparadores): $MODIF"
RUTAS=$(sudo -u www-data php artisan route:list --path=admin/ultron-studio --json 2>/dev/null | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))' || echo '?')
COBERTURA=$(sudo -u www-data php artisan auth:coverage --unmapped 2>&1 | tr -d '\n' | cut -c1-80 || true)
echo "5. rutas studio: $RUTAS · auth:coverage --unmapped → [$COBERTURA]"
[ "$RUTAS" = 38 ] || desvio "rutas del Studio: $RUTAS (esperado 38)"
[ -z "$COBERTURA" ] || desvio "auth:coverage --unmapped no está vacío"
echo "6. configuración efectiva:"
H5=$(sudo -u www-data php artisan tinker --execute='echo json_encode(["engine_scope" => config("marketing.ultron.studio_config"), "publishing_enabled" => (bool) config("ultron_studio.publishing_enabled"), "lab_gate" => config("ultron_studio.publishing.lab_gate"), "canary_min" => [config("ultron_studio.publishing.canary_min_hours"), config("ultron_studio.publishing.canary_min_turns")], "window" => config("ultron_studio.publishing.window"), "lab_unattended_s" => config("queue.lanes.lab.unattended_after_seconds")]), PHP_EOL;' 2>&1 | tail -1 || true)
case "$H5" in '{"engine_scope"'*) echo "   $H5" ;; *) echo "   (tinker no devolvió la configuración; su salida no se imprime)" ;; esac
[ "$H5" = '{"engine_scope":"off","publishing_enabled":false,"lab_gate":"required","canary_min":[24,3],"window":"07:00-19:00","lab_unattended_s":300}' ] || desvio "la configuración efectiva no es la de la fase 1"
LAB=$(sudo -u www-data php artisan tinker --execute='echo json_encode(["lab_enabled" => (bool) config("ultron_studio.lab.enabled"), "lab_driver" => config("ultron_studio.lab.driver"), "canary_set" => config("marketing.ultron.canary_conversation_id") !== null]), PHP_EOL;' 2>&1 | tail -1 || true)
case "$LAB" in '{"lab_enabled"'*) echo "   Hito 4 y canario (informativo): $LAB" ;; *) echo "   (sin lectura del laboratorio)" ;; esac
sleep 6
RUNNING=$(supervisorctl status | grep -c RUNNING || true); MAL=$(supervisorctl status | grep -c 'BACKOFF\|FATAL' || true)
echo "7. workers RUNNING: $RUNNING · BACKOFF/FATAL: $MAL"
[ "$RUNNING" = 13 ] && [ "$MAL" = 0 ] || desvio "workers: $RUNNING RUNNING y $MAL en BACKOFF/FATAL (esperado 13 y 0)"
echo "8. sanidad:"
sleep 3
for p in status lab/status publications versions/00000000-0000-4000-8000-0000000005a5/publication-preview; do
  c=$(curl -s -o /dev/null -w '%{http_code}' "https://api.ironbodyneiva.cloud/api/admin/ultron-studio/$p")
  echo "   $p sin token → $c"; [ "$c" = 401 ] || desvio "$p sin token respondió $c (esperado 401)"
done
FALLIDAS=$($PSQL -c "SELECT count(*) FROM failed_jobs;")
echo "   colas fallidas: $FALLIDAS (antes $FALLIDAS_ANTES)"; [ "$FALLIDAS" -le "$FALLIDAS_ANTES" ] || desvio "colas fallidas nuevas durante el despliegue"
SELLOS=$($PSQL -c "SELECT count(*) FROM marketing_ai_actions WHERE metadata ? 'studio';")
echo "   filas de marketing_ai_actions con sello 'studio' (0 con el motor en off): $SELLOS"; [ "$SELLOS" = 0 ] || desvio "hay sellos del Studio con el motor en off"
ERRORES=0
for f in "$LOGS"/laravel*.log; do
  [ -f "$f" ] || continue
  n=$(printf '%s\n' "$LOG_ANTES" | awk -v f="$f" '$1 == f {print $2}')
  e=$(tail -n +"$(( ${n:-0} + 1 ))" "$f" | grep -c 'production.ERROR' || true)
  ERRORES=$((ERRORES + e))
done
echo "   laravel.log ERROR desde el inicio del despliegue: $ERRORES"; [ "$ERRORES" = 0 ] || desvio "$ERRORES errores en el log desde el inicio"
sudo -u www-data php artisan security:check-secret-exposure > /dev/null 2>&1 && echo "   security:check-secret-exposure: OK" || desvio "security:check-secret-exposure no pasa"
sudo -u www-data php artisan ultron-studio:publication-check > /dev/null 2>&1 && echo "   ultron-studio:publication-check: 0 (cuadra)" || desvio "ultron-studio:publication-check no sale con 0"
[ "$(g -C /var/www/api status --porcelain --untracked-files=no | wc -l | tr -d ' ')" = 0 ] || desvio "el checkout tiene cambios tras el despliegue"
if [ "$DESVIOS" = 0 ]; then echo "FASE 1 (H5) COMPLETADA"; else echo "FASE 1 (H5) CON $DESVIOS DESVÍOS: no seguir con el CRM ni con el smoke hasta aclararlos"; exit 1; fi

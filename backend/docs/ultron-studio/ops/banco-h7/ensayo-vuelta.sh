#!/bin/bash
# Vuelta atrás por niveles del Hito 7 (y de lo que el H7 hereda del H6), en un banco RECIÉN desplegado con
# deploy-h7-backend.sh (montar.sh → sembrar.sh → ensayar.sh desplegar). Niveles del contrato:
#  2 · banderas: encender el monitor (2 tareas programadas, una pasada sin incidentes) y apagarlo (0 tareas);
#  3 · código: el H6 (7d2c6d8) sobre el esquema del H7 (cachés como www-data, 600, queue:restart): rutas 56, el
#      no-store y el 413 temprano se van (prueba de que vuelve el código), el smoke del H6 de la fase 1 en verde y una
#      escritura real del H6 en la cadena (volver a Legacy) que el disparador del H7 acepta; y vuelta al H7 con su smoke;
#  4 · esquema: down() se NIEGA con filas (nunca en producción; aquí, para ver que no destruye nada).
# Uso: bash ensayo-vuelta.sh <banco>     (código 0 = todos los casos OK)
. "$(dirname "$0")/comun.sh"
B=$(banco_dir "${1:-}")
cargar_banco "$B"
APPD="$B/api/backend"; API="http://127.0.0.1:$BANCO_PORT/api"
Q() { psql -X -Atq -v ON_ERROR_STOP=1 -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$BANCO_DB" -c "$1"; }
art() { ( cd "$APPD" && php artisan "$@" ); }
caches() { ( cd "$APPD" && sh -c 'umask 077; php artisan config:cache -q && php artisan route:cache -q' && chmod 600 bootstrap/cache/config.php bootstrap/cache/routes-v7.php && php artisan queue:restart -q ); }
reinicia_api() { bash "$OPS/banco-h6/api.sh" "$B" stop > /dev/null 2>&1; bash "$OPS/banco-h6/api.sh" "$B" start > /dev/null 2>&1 < /dev/null; }
pon_var() { python3 - "$APPD/.env" "$1" "$2" <<'PY'
import re, sys
p, k, v = sys.argv[1:4]
s = open(p, encoding='utf-8').read()
s, n = re.subn(r'(?m)^(\s*)' + re.escape(k) + r'=.*$', lambda m: m.group(1) + k + '=' + v, s)
if n == 0:
    s += ('' if s.endswith('\n') else '\n') + '  ' + k + '=' + v + '\n'
open(p, 'w', encoding='utf-8').write(s)   # el mismo fichero (mismo inodo y modo), como set_var
PY
}
cabecera() { curl -sI -H 'Accept: application/json' "$API/admin/ultron-studio/status" | tr -d '\r' | awk -F': *' 'tolower($1) == "cache-control" {print $2}'; }
grande() { python3 -c 'import json; print(json.dumps({"label": "x" * 70000}))' | curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Accept: application/json' -H 'Content-Type: application/json' --data-binary @- "$API/admin/ultron-studio/versions"; }
ESQ="SELECT (SELECT count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE NOT t.tgisinternal AND c.relname LIKE 'ultron_studio_%')||' '||(SELECT count(*) FROM pg_constraint k JOIN pg_class c ON c.oid = k.conrelid WHERE k.contype = 'c' AND c.relname LIKE 'ultron_studio_%');"
[ "$(git -C "$B/api" rev-parse --short=7 HEAD)" = "$ESPERADO" ] && [ "$(Q "$ESQ")" = "44 50" ] || muere "el banco no está desplegado con el H7 ($ESPERADO, 44/50): montar.sh, sembrar.sh y ensayar.sh desplegar antes"

# El smoke del H6 espera la clasificación de producción al congelar: el lead canario y el espejo de Meta de la semilla
# del tester (como banco-h6/sembrar.sh; una sola vez por banco).
SEED=${BANCO_SEED:-$(cd "$WT/.." && pwd -P)/Iron-Body_Front/e2e/ultron-studio/specs/h6-seed.php}
if [ "$(Q "SELECT count(*) FROM marketing_conversations WHERE id = 987654321;")" = 0 ]; then
  [ -f "$SEED" ] || muere "no encuentro h6-seed.php en $SEED (BANCO_SEED=…)"
  L=$(Q "INSERT INTO marketing_leads (channel, meta_user_id, created_at, updated_at) VALUES ('whatsapp', '573009990001', now(), now()) RETURNING id;" | head -1)
  Q "INSERT INTO marketing_conversations (id, lead_id, channel, created_at, updated_at) VALUES (987654321, $L, 'whatsapp', now(), now());" > /dev/null
  ( cd "$APPD" && php -d memory_limit=1G "$SEED" "$APPD" canary-lead 573009990001 > /dev/null && php -d memory_limit=1G "$SEED" "$APPD" meta-ads 990000000001001,990000000002002,990000000003003 > "$B/logs/seed-meta.json" && php -d memory_limit=1G "$SEED" "$APPD" sync-run 990000000003003 ok schedule 1 --touch > /dev/null )
  echo "   semilla del H6: lead canario y espejo de Meta"
fi

echo "== nivel 2 · la bandera del monitor"
cp -p "$APPD/.env" "$B/run/env-pre-monitor"
pon_var ULTRON_STUDIO_MONITOR_ENABLED true; caches
N=$(art schedule:list 2> /dev/null | grep -c 'ultron-studio:' || true)
caso "monitor encendido (true + config:cache): 2 tareas del Studio en schedule:list" "$( [ "$N" = 2 ] && echo 0 || echo 1)" "$N"
I0=$(Q "SELECT count(*) FROM incidents WHERE source = 'ultron_studio';")
RC=0; art ultron-studio:monitor > "$B/logs/monitor-encendido.log" 2>&1 || RC=$?
caso "una pasada del monitor de verdad (no en seco) sobre el banco sano: 0 y ningún incidente" "$( [ "$RC" = 0 ] && [ "$(Q "SELECT count(*) FROM incidents WHERE source = 'ultron_studio';")" = "$I0" ] && echo 0 || echo 1)" "rc $RC"
pon_var ULTRON_STUDIO_MONITOR_ENABLED false; caches
N=$(art schedule:list 2> /dev/null | grep -c 'ultron-studio:' || true)
caso "monitor apagado otra vez (false + config:cache): 0 tareas del Studio" "$( [ "$N" = 0 ] && echo 0 || echo 1)" "$N"
cmp -s "$APPD/.env" "$B/run/env-pre-monitor"; caso "el fichero de entorno vuelve a ser el de antes" "$?"

echo "== nivel 3 · el código del H6 sobre el esquema del H7"
NS_H7=$(cabecera); G_H7=$(grande)
git -C "$B/api" checkout -q --detach "$PREVIO_BANCO"; caches; reinicia_api
caso "código en $PREVIO_BANCO (H6): rutas 56 y esquema del H7 intacto (44/50)" "$( [ "$(art route:list --path=admin/ultron-studio --json | python3 -c 'import json,sys; print(len(json.load(sys.stdin)))')" = 56 ] && [ "$(Q "$ESQ")" = "44 50" ] && echo 0 || echo 1)"
NS_H6=$(cabecera); G_H6=$(grande)
caso "vuelve el comportamiento del H6: sin no-store (${NS_H6:-ninguna}) y 70 000 bytes sin sesión → 401, no 413" "$( [ "$NS_H7" = 'no-store, private' ] && [ "$NS_H6" != 'no-store, private' ] && [ "$G_H7" = 413 ] && [ "$G_H6" = 401 ] && echo 0 || echo 1)" "H7: $NS_H7 · $G_H7 → H6: $NS_H6 · $G_H6"
RC=0; art ultron-studio:publication-check > /dev/null 2>&1 || RC=$?; caso "publication-check del H6 sobre la cadena del H7: 0" "$RC"
RC=0; bash "$AQUI/ensayar.sh" "$B" smoke-h6 1 > "$B/logs/smoke-h6-vuelta.log" 2>&1 || RC=$?
caso "smoke del H6 (fase 1) con el código del H6 y el esquema del H7" "$( [ "$RC" = 0 ] && echo 0 || echo 1)" "rc $RC · $(grep -E '^RESUMEN' "$B/logs/smoke-h6-vuelta.log" | tr '\n' ' ' | cut -c1-160)"
# Una escritura real del H6 en la cadena: volver a Legacy (no depende de la llave de publicar). El servicio del H6
# encadena con seq = cabeza + 1 apuntando a la cabeza: el disparador del H7 lo acepta.
PW=$(openssl rand -hex 16); HASH=$(PW="$PW" php -r 'echo password_hash(getenv("PW"), PASSWORD_BCRYPT);')
Q "INSERT INTO admins (uuid, name, email, password, role, status, created_at, updated_at) VALUES (gen_random_uuid(), 'Operadora banco', 'operadora-vuelta-h7@local', '$HASH', 'Super Admin', 'active', now(), now()) ON CONFLICT (email) DO UPDATE SET password = EXCLUDED.password, status = 'active';" > /dev/null
TOK=$(curl -s -H 'Accept: application/json' -H 'Content-Type: application/json' -d "{\"email\":\"operadora-vuelta-h7@local\",\"password\":\"$PW\"}" "$API/admin/auth/login" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("token") or "")')
unset PW HASH
C0=$(Q "SELECT count(*) FROM ultron_studio_publications;")
CODIGO=$(curl -s -o "$B/logs/legacy-h6.json" -w '%{http_code}' -X POST -H 'Accept: application/json' -H 'Content-Type: application/json' -H "Authorization: Bearer $TOK" \
  -d "{\"clientRequestId\":\"$(python3 -c 'import uuid; print(uuid.uuid4())')\",\"reason\":\"Ensayo de la vuelta atrás del H7: el código del H6 escribe en la cadena\"}" "$API/admin/ultron-studio/publications/legacy")
# Con la cadena en Legacy, volver a Legacy es un 409 already_legacy sin escritura (la escritura encadenada bajo el
# disparador del H7 la cubren las pruebas de DB7 en PG: publicar, promover, revertir, Legacy y las 38 carreras del H5).
CODE=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1])).get("code"))' "$B/logs/legacy-h6.json" 2> /dev/null || true)
if [[ "$CODIGO" =~ ^20[01]$ ]]; then
  caso "el H6 escribe en la cadena con el disparador del H7 (una entrada más, encadenada)" "$( [ "$(Q "SELECT count(*) FROM ultron_studio_publications;")" = $((C0 + 1)) ] && echo 0 || echo 1)" "HTTP $CODIGO"
else
  caso "con la cadena en Legacy, el H6 responde 409 already_legacy y no escribe (la escritura encadenada: pruebas de DB7)" "$( [ "$CODIGO" = 409 ] && [ "$CODE" = already_legacy ] && [ "$(Q "SELECT count(*) FROM ultron_studio_publications;")" = "$C0" ] && echo 0 || echo 1)" "HTTP $CODIGO $CODE"
fi
RC=0; art ultron-studio:publication-check > /dev/null 2>&1 || RC=$?; caso "la cabeza nueva verifica (publication-check 0)" "$RC"
Q "UPDATE admins SET status = 'inactive' WHERE email = 'operadora-vuelta-h7@local';" > /dev/null
git -C "$B/api" checkout -q main; caches; reinicia_api
caso "de vuelta al H7 ($ESPERADO): no-store y 413 temprano otra vez" "$( [ "$(git -C "$B/api" rev-parse --short=7 HEAD)" = "$ESPERADO" ] && [ "$(cabecera)" = 'no-store, private' ] && [ "$(grande)" = 413 ] && echo 0 || echo 1)"
RC=0; bash "$AQUI/ensayar.sh" "$B" smoke > "$B/logs/smoke-h7-vuelta.log" 2>&1 || RC=$?
caso "smoke del H7 tras volver" "$( [ "$RC" = 0 ] && echo 0 || echo 1)" "rc $RC · $(grep -E '^RESUMEN' "$B/logs/smoke-h7-vuelta.log" | cut -c1-80)"

echo "== nivel 4 · el esquema: down() se niega con filas (nunca en producción)"
FILAS="SELECT (SELECT count(*) FROM ultron_studio_publications)||' '||(SELECT count(*) FROM ultron_studio_audit_events)||' '||(SELECT count(*) FROM ultron_studio_integration_snapshots);"
F0=$(Q "$FILAS")
RC=0; ( cd "$APPD" && env DB_USERNAME=h5_owner APP_CONFIG_CACHE="$B/run/no-cache.php" php artisan migrate:rollback --step=1 --force > "$B/logs/down-h7.log" 2>&1 ) || RC=$?
caso "migrate:rollback de la migración del H7 con filas: se niega, no borra nada y el esquema sigue 44/50" "$( [ "$RC" != 0 ] && [ "$(Q "$ESQ")" = "44 50" ] && [ "$(Q "$FILAS")" = "$F0" ] && echo 0 || echo 1)" "rc $RC · filas cadena/libro/instantáneas $F0 → $(Q "$FILAS") · $(grep -oE 'se niega[^(]*' "$B/logs/down-h7.log" | head -1 | cut -c1-90)"
echo "VUELTA ATRÁS EN EL BANCO: $((CASOS - FALLOS))/$CASOS OK"
[ "$FALLOS" = 0 ]

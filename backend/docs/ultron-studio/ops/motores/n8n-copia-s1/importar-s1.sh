#!/bin/bash
# Importa en n8n la COPIA «Studio S1» del flujo de ULTRON, INACTIVA, con el importador oficial (n8n import:workflow).
# No toca el flujo vivo (YspRwsXnqt33NouP): lo comprueba antes y después. Uso (servidor, root):
#   bash importar-s1.sh <id nuevo> <sha256 esperado del selector> [--solo-comprobar]
# Requiere /root/n8n-s1/ultron-s1-import.json (600). No imprime parámetros de nodos ni credenciales.
set -euo pipefail
export LC_ALL=C
cd /tmp
NUEVO="${1:?id del flujo nuevo}"; SHA="${2:?sha256 del selector}"; MODO="${3:-}"
VIVO=YspRwsXnqt33NouP
PROYECTO=TEt5qxIcC0S7DedW
F=/root/n8n-s1/ultron-s1-import.json
aborta() { echo "ABORTA (no se ha importado nada): $*"; exit 1; }
q() { docker exec n8n-postgres-1 psql -U n8n -d n8n -X -Atq -F ' | ' -v ON_ERROR_STOP=1 -c "$1"; }
foto_vivo() { q "select active, \"versionId\", \"activeVersionId\", \"updatedAt\", json_array_length(nodes), md5(nodes::text), md5(connections::text) from workflow_entity where id='$VIVO'"; }
[ -s "$F" ] || aborta "falta $F"
[ "$(stat -c '%U %a' "$F")" = "root 600" ] || aborta "$F no es de root en 600"
ANTES=$(foto_vivo)
case "$ANTES" in "t | 29de5119-2e5a-4bc8-a479-60f4fe37c5b3 | 29de5119-2e5a-4bc8-a479-60f4fe37c5b3 |"*) ;; *) aborta "el flujo vivo no está como se espera: $ANTES" ;; esac
WH_ANTES=$(q "select method||' '||\"webhookPath\"||' '||\"workflowId\" from webhook_entity where \"webhookPath\"='iron-body-ultron'")
[ "$WH_ANTES" = "POST iron-body-ultron $VIVO" ] || aborta "el webhook vivo no es el esperado: $WH_ANTES"
EXISTE=$(q "select count(*) from workflow_entity where id='$NUEVO'")
echo "0. flujo vivo: $ANTES"
echo "   webhook vivo: $WH_ANTES · ¿existe ya $NUEVO? $EXISTE"
if [ "$MODO" = "--solo-comprobar" ]; then echo "SOLO COMPROBADO: nada importado"; exit 0; fi
[ "$EXISTE" = 0 ] || [ "$(q "select active from workflow_entity where id='$NUEVO'")" = f ] || aborta "$NUEVO ya existe y está ACTIVO: no se reimporta encima de un flujo publicado"

# 1. Respaldo de las filas del flujo vivo (con sus referencias de credenciales; sin secretos: n8n guarda los secretos aparte, cifrados).
TS=$(date -u +%Y%m%d-%H%M%S)
BK=/var/backups/ironbody/n8n-ultron-pre-s1-$TS
( umask 077; mkdir -p "$BK"
  q "select row_to_json(w) from workflow_entity w where id='$VIVO'" > "$BK/workflow_entity.json"
  q "select row_to_json(h) from workflow_history h where \"workflowId\"='$VIVO'" > "$BK/workflow_history.json"
  q "select row_to_json(s) from shared_workflow s where \"workflowId\"='$VIVO'" > "$BK/shared_workflow.json" )
echo "1. respaldo: $BK ($(ls "$BK" | wc -l) ficheros, $(du -sh "$BK" | cut -f1))"

# 2. Importar (inactivo por defecto) en el proyecto dueño de las credenciales.
docker cp "$F" n8n-n8n-1:/tmp/ultron-s1-import.json
docker exec -u root n8n-n8n-1 chown node:node /tmp/ultron-s1-import.json
set +e
SALIDA=$(docker exec n8n-n8n-1 n8n import:workflow --input=/tmp/ultron-s1-import.json --projectId="$PROYECTO" 2>&1)
RC=$?
set -e
docker exec -u root n8n-n8n-1 rm -f /tmp/ultron-s1-import.json
echo "2. importador (código $RC): $(printf '%s' "$SALIDA" | tail -3 | tr '\n' ' ' | cut -c1-300)"
[ "$RC" = 0 ] || { echo "IMPORTACIÓN FALLIDA: revisar arriba (el flujo vivo no se toca en ningún caso)"; }

# 3. Comprobaciones.
DESPUES=$(foto_vivo)
WH_DESPUES=$(q "select method||' '||\"webhookPath\"||' '||\"workflowId\" from webhook_entity where \"webhookPath\"='iron-body-ultron'")
[ "$ANTES" = "$DESPUES" ] && echo "3. flujo vivo INTACTO (misma versión, mismos nodos y conexiones)" || echo "3. ✗ EL FLUJO VIVO CAMBIÓ: antes [$ANTES] después [$DESPUES]"
[ "$WH_ANTES" = "$WH_DESPUES" ] && echo "   webhook vivo intacto: $WH_DESPUES" || echo "   ✗ EL WEBHOOK CAMBIÓ: $WH_DESPUES"
echo "4. flujo nuevo:"
q "select id, left(name,60), active, coalesce(\"activeVersionId\",'(sin publicar)'), left(\"versionId\",8), json_array_length(nodes), \"isArchived\" from workflow_entity where id='$NUEVO'"
q "select 'proyecto', sw.\"projectId\", sw.role from shared_workflow sw where sw.\"workflowId\"='$NUEVO'"
q "select 'ajustes', settings::text from workflow_entity where id='$NUEVO'" | cut -c1-260
SHA_BD=$(q "select encode(sha256(convert_to(n.nodo->'parameters'->>'jsCode','UTF8')),'hex') from (select json_array_elements(nodes) as nodo from workflow_entity where id='$NUEVO') n where n.nodo->>'name'='Elegir Prompts'")
[ "$SHA_BD" = "$SHA" ] && echo "   selector en la base: sha256 = el generado (${SHA:0:16}…)" || echo "   ✗ el selector en la base NO es el generado: ${SHA_BD:0:16}… (esperado ${SHA:0:16}…)"
q "select 'credenciales por nodo', count(*) from (select json_array_elements(nodes) as nodo from workflow_entity where id='$NUEVO') n where n.nodo->'credentials' is not null"
q "select 'historial del nuevo', count(*) from workflow_history where \"workflowId\"='$NUEVO'"
# El flujo nuevo, tal como quedó, para pasarle el comprobador fuera (600; nodos y conexiones con referencias, sin secretos).
( umask 077; q "select json_build_object('id', id, 'name', name, 'active', active, 'nodes', nodes, 'connections', connections, 'settings', settings, 'versionId', \"versionId\") from workflow_entity where id='$NUEVO'" > /root/n8n-s1/exportado-de-n8n.json )
echo "5. exportado a /root/n8n-s1/exportado-de-n8n.json ($(stat -c '%s' /root/n8n-s1/exportado-de-n8n.json) bytes)"

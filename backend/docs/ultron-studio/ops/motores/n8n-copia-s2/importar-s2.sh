#!/bin/bash
# PREPARADO Y SIN EJECUTAR (ola 5 de «regenerar antes de sustituir», 21-11-2026). Nadie lo ha corrido contra el n8n en
# marcha: lo ejecuta el lead o devops, en el servidor, con la orden del propietario (gate 4-H; L-6 del plan).
#
# Importa en n8n la COPIA «Studio S2» del flujo de ULTRON, INACTIVA, con el importador oficial (n8n import:workflow).
# Derivado de importar-s1.sh. No toca el flujo publicado (la copia S1) ni la reserva (el de siempre): los fotografía
# antes y después y aborta si algo se mueve. Uso (servidor, root):
#   bash importar-s2.sh <id nuevo> <sha256 del selector> <sha256 de Preparar Commit> [--solo-comprobar]
# Los dos sha256 salen de n8n/flujos/ultron-studio-s2/huellas.json (selector.jsCodeSha256 y preparaCommitJsCodeSha256).
# Requiere /root/n8n-s2/ultron-s2-import.json (root, 600), preparado como dice el LEEME. No imprime parámetros de nodos
# ni credenciales (las credenciales del fichero son referencias de n8n: id y nombre; los secretos no salen de n8n).
set -euo pipefail
export LC_ALL=C
cd /tmp
NUEVO="${1:?id del flujo nuevo}"; SHA_SEL="${2:?sha256 del selector}"; SHA_COMMIT="${3:?sha256 de Preparar Commit}"; MODO="${4:-}"
VIVO=QSklqHlsDIxucRfk        # la copia S1: la publicada hoy (plan H2)
RESERVA=YspRwsXnqt33NouP     # el de siempre (29de5119): despublicado e intacto
PROYECTO=TEt5qxIcC0S7DedW    # el proyecto dueño de las credenciales (el mismo que en S1)
NOMBRE='Iron Body - ULTRON - Commercial Advisor (Studio S2)'
F=/root/n8n-s2/ultron-s2-import.json
aborta() { echo "ABORTA (no se ha importado nada): $*"; exit 1; }
q() { docker exec n8n-postgres-1 psql -U n8n -d n8n -X -Atq -F ' | ' -v ON_ERROR_STOP=1 -c "$1"; }
foto() { q "select active, \"versionId\", coalesce(\"activeVersionId\",'(sin publicar)'), \"updatedAt\", json_array_length(nodes), md5(nodes::text), md5(connections::text), md5(settings::text) from workflow_entity where id='$1'"; }
sha_nodo() { q "select encode(sha256(convert_to(n.nodo->'parameters'->>'jsCode','UTF8')),'hex') from (select json_array_elements(nodes) as nodo from workflow_entity where id='$1') n where n.nodo->>'name'='$2'"; }

[[ "$NUEVO" =~ ^[A-Za-z0-9]{16}$ ]] || aborta "el id nuevo no tiene la forma de n8n (16 letras o cifras): $NUEVO"
case "$NUEVO" in "$VIVO"|"$RESERVA") aborta "el id nuevo es el de un flujo que ya existe ($NUEVO): nunca encima" ;; esac
[[ "$SHA_SEL" =~ ^[0-9a-f]{64}$ && "$SHA_COMMIT" =~ ^[0-9a-f]{64}$ ]] || aborta "los sha256 no tienen forma de sha256"
[ -s "$F" ] || aborta "falta $F"
[ "$(stat -c '%U %a' "$F")" = "root 600" ] || aborta "$F no es de root en 600"

# 0. El fichero, comprobado con el Node del contenedor de n8n (por la entrada estándar: no se copia nada todavía).
COMPRUEBA='let s = ""; process.stdin.on("data", d => { s += d; }).on("end", () => {
  const h = c => require("crypto").createHash("sha256").update(String(c), "utf8").digest("hex");
  let f; try { f = JSON.parse(s); } catch (e) { console.log("MAL json"); return; }
  const n = Object.fromEntries((f.nodes || []).map(x => [x.name, x]));
  const [id, sel, commit, nombre] = process.argv.slice(1);
  const mal = [];
  if (f.id !== id) mal.push("id");
  if (f.active !== false) mal.push("active");
  if (f.name !== nombre) mal.push("name");
  if (typeof f.versionId !== "string" || f.versionId === "") mal.push("versionId");
  if ("_generado" in f) mal.push("_generado");
  if ((f.nodes || []).length !== 39 || Object.keys(n).length !== 39) mal.push("nodos");
  if ((f.nodes || []).filter(x => x.credentials).length !== 12) mal.push("credenciales");
  if (!n["Elegir Prompts"] || h(n["Elegir Prompts"].parameters.jsCode) !== sel) mal.push("selector");
  if (!n["Preparar Commit"] || h(n["Preparar Commit"].parameters.jsCode) !== commit) mal.push("Preparar Commit");
  for (const c of ["Comprobar Borrador 1", "Comprobar Borrador 2"]) {
    const x = n[c];
    if (!x || x.retryOnFail || x.maxTries || !x.parameters.options || x.parameters.options.timeout !== 3000 || x.onError !== "continueRegularOutput") mal.push(c);
  }
  console.log(mal.length ? "MAL " + mal.join(", ") : "OK");
});'
FICHERO=$(docker exec -i n8n-n8n-1 node -e "$COMPRUEBA" "$NUEVO" "$SHA_SEL" "$SHA_COMMIT" "$NOMBRE" < "$F")
[ "$FICHERO" = OK ] || aborta "el fichero no es el S2 generado y preparado: $FICHERO"

ANTES_VIVO=$(foto "$VIVO")
ANTES_RESERVA=$(foto "$RESERVA")
case "$ANTES_VIVO" in "t | "*) ;; *) aborta "la copia S1 no está publicada como se espera: $ANTES_VIVO" ;; esac
case "$ANTES_VIVO" in *"(sin publicar)"*) aborta "la copia S1 no tiene versión activa: $ANTES_VIVO" ;; esac
case "$ANTES_RESERVA" in "f | "*) ;; *) aborta "la reserva no está despublicada como se espera: $ANTES_RESERVA" ;; esac
WH_ANTES=$(q "select method||' '||\"webhookPath\"||' '||\"workflowId\" from webhook_entity where \"webhookPath\"='iron-body-ultron'")
[ "$WH_ANTES" = "POST iron-body-ultron $VIVO" ] || aborta "el webhook vivo no es el esperado: $WH_ANTES"
EXISTE=$(q "select count(*) from workflow_entity where id='$NUEVO'")
echo "0. fichero: $FICHERO (39 nodos, 12 con credencial, selector y Preparar Commit = los generados, comprobación 3 s sin reintentos)"
echo "   copia S1 (publicada): $ANTES_VIVO"
echo "   reserva:              $ANTES_RESERVA"
echo "   webhook vivo: $WH_ANTES · ¿existe ya $NUEVO? $EXISTE"
if [ "$MODO" = "--solo-comprobar" ]; then echo "SOLO COMPROBADO: nada importado"; exit 0; fi
[ "$EXISTE" = 0 ] || [ "$(q "select active from workflow_entity where id='$NUEVO'")" = f ] || aborta "$NUEVO ya existe y está ACTIVO: no se reimporta encima de un flujo publicado"

# 1. Respaldo de las filas de la copia S1 y de la reserva (con sus referencias de credenciales; sin secretos: n8n guarda
#    los secretos aparte, cifrados).
TS=$(date -u +%Y%m%d-%H%M%S)
BK=/var/backups/ironbody/n8n-ultron-pre-s2-$TS
( umask 077; mkdir -p "$BK"
  for W in "$VIVO" "$RESERVA"; do
    q "select row_to_json(w) from workflow_entity w where id='$W'" > "$BK/workflow_entity-$W.json"
    q "select row_to_json(h) from workflow_history h where \"workflowId\"='$W'" > "$BK/workflow_history-$W.json"
    q "select row_to_json(s) from shared_workflow s where \"workflowId\"='$W'" > "$BK/shared_workflow-$W.json"
  done
  q "select row_to_json(e) from webhook_entity e where \"webhookPath\"='iron-body-ultron'" > "$BK/webhook_entity.json"
  ( cd "$BK" && sha256sum ./*.json > SHA256SUMS ) )
echo "1. respaldo: $BK ($(ls "$BK" | wc -l) ficheros, $(du -sh "$BK" | cut -f1))"

# 2. Importar (inactivo) en el proyecto dueño de las credenciales.
docker cp "$F" n8n-n8n-1:/tmp/ultron-s2-import.json
docker exec -u root n8n-n8n-1 chown node:node /tmp/ultron-s2-import.json
set +e
SALIDA=$(docker exec n8n-n8n-1 n8n import:workflow --input=/tmp/ultron-s2-import.json --projectId="$PROYECTO" 2>&1)
RC=$?
set -e
docker exec -u root n8n-n8n-1 rm -f /tmp/ultron-s2-import.json
echo "2. importador (código $RC): $(printf '%s' "$SALIDA" | tail -3 | tr '\n' ' ' | cut -c1-300)"
MAL=0
[ "$RC" = 0 ] || { echo "✗ IMPORTACIÓN FALLIDA: revisar arriba (la copia S1 y la reserva no se tocan en ningún caso)"; MAL=$((MAL + 1)); }

# 3. Comprobaciones: lo publicado y la reserva, intactos; el nuevo, inactivo y con lo generado.
DESPUES_VIVO=$(foto "$VIVO")
DESPUES_RESERVA=$(foto "$RESERVA")
WH_DESPUES=$(q "select method||' '||\"webhookPath\"||' '||\"workflowId\" from webhook_entity where \"webhookPath\"='iron-body-ultron'")
if [ "$ANTES_VIVO" = "$DESPUES_VIVO" ]; then echo "3. copia S1 INTACTA (misma versión, nodos, conexiones y ajustes)"; else echo "3. ✗ LA COPIA S1 CAMBIÓ: antes [$ANTES_VIVO] después [$DESPUES_VIVO]"; MAL=$((MAL + 1)); fi
if [ "$ANTES_RESERVA" = "$DESPUES_RESERVA" ]; then echo "   reserva INTACTA"; else echo "   ✗ LA RESERVA CAMBIÓ: antes [$ANTES_RESERVA] después [$DESPUES_RESERVA]"; MAL=$((MAL + 1)); fi
if [ "$WH_ANTES" = "$WH_DESPUES" ]; then echo "   webhook vivo intacto: $WH_DESPUES"; else echo "   ✗ EL WEBHOOK CAMBIÓ: $WH_DESPUES"; MAL=$((MAL + 1)); fi
echo "4. flujo nuevo:"
q "select id, left(name,60), active, coalesce(\"activeVersionId\",'(sin publicar)'), left(\"versionId\",8), json_array_length(nodes), \"isArchived\" from workflow_entity where id='$NUEVO'"
q "select 'proyecto', sw.\"projectId\", sw.role from shared_workflow sw where sw.\"workflowId\"='$NUEVO'"
[ "$(q "select active from workflow_entity where id='$NUEVO'")" = f ] || { echo "   ✗ el flujo nuevo NO está inactivo"; MAL=$((MAL + 1)); }
[ "$(q "select coalesce(\"activeVersionId\",'-') from workflow_entity where id='$NUEVO'")" = - ] || { echo "   ✗ el flujo nuevo tiene versión publicada"; MAL=$((MAL + 1)); }
[ "$(q "select json_array_length(nodes) from workflow_entity where id='$NUEVO'")" = 39 ] || { echo "   ✗ el flujo nuevo no tiene 39 nodos"; MAL=$((MAL + 1)); }
CRED=$(q "select count(*) from (select json_array_elements(nodes) as nodo from workflow_entity where id='$NUEVO') n where n.nodo->'credentials' is not null")
[ "$CRED" = 12 ] && echo "   credenciales por nodo: 12" || { echo "   ✗ credenciales por nodo: $CRED (esperadas 12)"; MAL=$((MAL + 1)); }
for PAR in "Elegir Prompts:$SHA_SEL" "Preparar Commit:$SHA_COMMIT"; do
  NODO=${PAR%%:*}; ESPERADO=${PAR#*:}
  EN_BD=$(sha_nodo "$NUEVO" "$NODO")
  if [ "$EN_BD" = "$ESPERADO" ]; then echo "   $NODO en la base: sha256 = el generado (${ESPERADO:0:16}…)"; else echo "   ✗ $NODO en la base NO es el generado: ${EN_BD:0:16}… (esperado ${ESPERADO:0:16}…)"; MAL=$((MAL + 1)); fi
done
if [ "$(q "select md5(settings::text) from workflow_entity where id='$NUEVO'")" = "$(q "select md5(settings::text) from workflow_entity where id='$VIVO'")" ]; then echo "   ajustes = los de la copia S1 (flujo de errores incluido)"; else echo "   ✗ los ajustes no son los de la copia S1"; MAL=$((MAL + 1)); fi
q "select 'historial del nuevo', count(*) from workflow_history where \"workflowId\"='$NUEVO'"
# El flujo nuevo, tal como quedó, para pasarle el comprobador fuera (600; nodos y conexiones con referencias, sin secretos).
( umask 077; q "select json_build_object('id', id, 'name', name, 'active', active, 'nodes', nodes, 'connections', connections, 'settings', settings, 'versionId', \"versionId\") from workflow_entity where id='$NUEVO'" > /root/n8n-s2/exportado-de-n8n.json )
echo "5. exportado a /root/n8n-s2/exportado-de-n8n.json ($(stat -c '%s' /root/n8n-s2/exportado-de-n8n.json) bytes): pasarle «verificar-flujo.cjs --rev=S2»"
if [ "$MAL" = 0 ]; then echo "COPIA S2 IMPORTADA, INACTIVA Y COMPROBADA"; else echo "COPIA S2 CON $MAL PROBLEMAS: no seguir con el ensayo ni con el cambio hasta aclararlos"; exit 1; fi

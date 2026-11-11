#!/bin/bash
# El banco de la ola «Enmienda M-1 + plantillas de Meta» (informe-devops-ola.md §5; RUNBOOK_M1.md): ensaya
# deploy-m1-backend.sh y las etapas E2 de los dos mandos (h5-engine-scope.sh y motores/motores-prompts-scope.sh, con un
# fallo forzado a medias para la trampa de M1-B) sobre una «producción» de juguete EN EL MISMO ESTADO que la real:
# el código y el esquema de 728f208 (lo que sirve producción), el H9 en sombra con el gobierno, publicar y el canal en
# vivo encendidos (L9-101) y el laboratorio hablado en 2a (L9-100), escritos en su fichero de entorno SINTÉTICO. Encima,
# como origin/main, los commits DEFINITIVOS de la ola (HEAD del worktree; con ficheros de la ola sin confirmar, se niega).
# Usa montar.sh, preparar.sh, ensayar.sh y sembrar.sh del banco del H9 sin cambiarlos.
#   bash m1.sh <banco> montar            el banco en 728f208 con el estado de producción, la API, la valla y la cuenta del smoke
#   bash m1.sh <banco> ola               origin/main = HEAD del worktree (la ola confirmada) y el respaldo de hoy
#   bash m1.sh <banco> respaldo          rehace el respaldo con el formato del plan (api-commit = la «producción» de hoy)
#   bash m1.sh <banco> crm <commit>      el version.json del CRM «publicado» (file://$B/crm)
#   bash m1.sh <banco> desplegar [opciones de deploy-m1-backend.sh]   la copia del banco, con la RED y los SMOKE_*
#   bash m1.sh <banco> mandos            E2 y su vuelta con los dos mandos del repo (copias del banco) y la trampa de M1-B
# Orden del ensayo completo (RUNBOOK_M1.md §6): montar → ola → crm <commit del CRM de la ola> → desplegar
# --solo-comprobar → desplegar → mandos → (ensayar.sh parar). NO se ejecuta sin la orden del lead (la Mac tiene 8 GB).
# Variables: las de montar.sh (BANCO_DB, BANCO_PORT, BANCO_PUERTO_VALLA, BANCO_PG_PORT).
. "$(dirname "$0")/comun.sh"
B=$(banco_dir "${1:-}")
ORDEN=${2:-}
shift 2 || true
PROD=728f2085                                   # lo que sirve producción (deploy-m1-backend.sh, PREVIO)
CRM_PROD=a539690                                # el CRM de hoy (deploy-m1-backend.sh, CRM_PREVIO)
export PATH="$B/bin:$PATH"

# El estado de producción que el despliegue tiene que encontrar y dejar IGUAL (el H9 en sombra y la voz en 2a), en el
# fichero de entorno SINTÉTICO del banco. Lo que en producción escribió cada mando (L9-100, L9-101), con dos
# diferencias que se dicen: la sonda de Twilio queda apagada (el banco no tiene sus credenciales) y el laboratorio
# hablado no tiene clave de ElevenLabs (el smoke del H8 lo marca: FALLO conocido del banco, L9-94).
estado_prod() {
  cargar_banco "$B"
  python3 -I - "$B/api/backend/.env" <<'PY'
import re, sys
p = sys.argv[1]
s = open(p, encoding="utf-8").read()
claves = [
    ("COMMERCIAL_EVENTS_ENABLED", "true"), ("ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED", "true"),
    ("ULTRON_STUDIO_STRATEGIES_ENABLED", "true"), ("ULTRON_STUDIO_STRATEGIES_PUBLISHING_ENABLED", "true"),
    ("ULTRON_STUDIO_STRATEGIES_PUBLISH_WINDOW", "07:00-19:00"), ("ULTRON_STUDIO_STRATEGIES_CANARY_MIN_HOURS", "24"),
    ("ULTRON_STUDIO_STRATEGIES_CANARY_MIN_TOUCHES", "3"),
    ("COMMERCIAL_AUTOMATION_ENABLED", "true"), ("COMMERCIAL_AUTOMATION_MODE", "shadow"),
    ("COMMERCIAL_AUTOMATION_SCHEDULER_ENABLED", "true"), ("COMMERCIAL_AUTOMATION_MONITOR_ENABLED", "true"),
    ("ULTRON_STUDIO_STREAM_ENABLED", "true"),
    ("VOICE_LAB_ENABLED", "true"), ("VOICE_LAB_DRIVER", "elevenlabs"), ("VOICE_LAB_DAILY_BUDGET_USD", "3.00"),
]
for k, v in claves:
    s, n = re.subn(r"^([ \t]*)" + k + r"=.*$", lambda m: m.group(1) + k + "=" + v, s, count=1, flags=re.M)
    if n == 0:
        s = s.rstrip("\n") + "\n  " + k + "=" + v + "\n"
# La automatización interna configurada, como en producción (n8n llama a decide y commit): sin ella el middleware
# responde 503 y la sanidad del despliegue (decide/commit sin firma → 401) daría un desvío falso. Valor sintético y
# aleatorio de este banco; no se imprime.
if not re.search(r"^[ \t]*AUTOMATION_INTERNAL_SECRET=.+$", s, flags=re.M):
    import secrets
    s = s.rstrip("\n") + "\n  AUTOMATION_INTERNAL_SECRET=" + secrets.token_hex(32) + "\n"
open(p, "w", encoding="utf-8").write(s)
print("   estado de producción en el entorno sintético: %d claves (H9 en sombra, gobierno, publicar y canal; laboratorio hablado 2a) y la automatización interna configurada" % len(claves))
PY
  ( cd "$B/api/backend" && env "${RED[@]}" php artisan config:cache -q && env "${RED[@]}" php artisan route:cache -q && chmod 600 bootstrap/cache/config.php bootstrap/cache/routes-v7.php )
}
# origin/main = HEAD del worktree: la ola tiene que estar CONFIRMADA (es el ensayo de los commits definitivos).
ola() {
  cargar_banco "$B"
  local sucios esp
  sucios=$(git -C "$WT" status --porcelain --untracked-files=all -- backend/app backend/config backend/routes backend/database backend/resources backend/bootstrap backend/.env.example n8n backend/docs/ultron-studio/ops | grep -v '/evidencia-' || true)
  [ -z "$sucios" ] || muere "hay ficheros de la ola sin confirmar en el worktree ($(printf '%s\n' "$sucios" | wc -l | tr -d ' ')): el ensayo es con los commits DEFINITIVOS"
  git -C "$WT" merge-base --is-ancestor "$PROD" HEAD || muere "HEAD del worktree no contiene $PROD (lo que sirve producción)"
  local rama cabeza
  rama=$(git -C "$WT" rev-parse --abbrev-ref HEAD); cabeza=$(git -C "$WT" rev-parse HEAD)
  ( cd "$B/src" && git fetch -q "$(git -C "$WT" rev-parse --path-format=absolute --git-common-dir)" "$rama" \
      && [ "$(git rev-parse FETCH_HEAD)" = "$cabeza" ] && git -c advice.detachedHead=false checkout -q -B banco FETCH_HEAD && git branch -q -f main HEAD ) \
    || muere "no se pudo traer al origen del banco la rama $rama en $cabeza (¿commits sin confirmar en la rama?)"
  esp=$(git -C "$B/src" rev-parse --short=7 main)
  python3 -I - "$B/banco.env" "$esp" "${PROD:0:7}" <<'PY'
import re, sys
p, esp, previo = sys.argv[1:4]
s = open(p, encoding="utf-8").read()
s = re.sub(r"^ESPERADO=.*$", "ESPERADO=" + esp, s, flags=re.M)
s = re.sub(r"^PREVIO_BANCO=.*$", "PREVIO_BANCO=" + previo, s, flags=re.M)
open(p, "w", encoding="utf-8").write(s)
PY
  ( cd "$B/api" && git fetch -q origin )
  echo "   ola: origin/main = $esp ($(git -C "$B/src" log --oneline "$PROD..main" | wc -l | tr -d ' ') commits sobre ${PROD:0:7})"
}
respaldo() {
  cargar_banco "$B"
  ( umask 077
    rm -rf "$B/bk.prev" && { [ ! -d "$B/bk" ] || mv "$B/bk" "$B/bk.prev"; }
    mkdir -p "$B/bk"
    git -C "$B/api" rev-parse HEAD > "$B/bk/api-commit.txt"
    pg_dump -Fc -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" "$BANCO_DB" > "$B/bk/ironbody.dump"
    shasum -a 256 "$B/bk/ironbody.dump" > "$B/bk/ironbody.dump.sha256"
    install -m 600 "$B/api/backend/.env" "$B/bk/env.backup"
    chmod 700 "$B/bk"; chmod 600 "$B/bk"/* )
  echo "   respaldo $B/bk: api-commit $(cut -c1-7 "$B/bk/api-commit.txt") · $(pg_restore -l "$B/bk/ironbody.dump" | grep -c 'TABLE DATA') tablas con datos"
}
# La copia del banco de un guion del repo: cada sustitución tiene que casar EXACTAMENTE las veces dichas (si el guion
# cambia y una no casa, falla en vez de ensayar otra cosa). La lógica es la del repo.
copia() { # origen destino reglas(JSON: [[viejo, nuevo, veces], …])
  python3 -I - "$1" "$2" "$3" <<'PY'
import json, sys
origen, destino, reglas = sys.argv[1], sys.argv[2], json.loads(sys.argv[3])
s = open(origen, encoding="utf-8").read()
for viejo, nuevo, veces in reglas:
    n = s.count(viejo)
    if n != veces:
        sys.exit(f"m1: «{viejo[:60]}» aparece {n} veces en {origen} (esperado {veces}): el guion cambió")
    s = s.replace(viejo, nuevo)
open(destino, "w", encoding="utf-8").write(s)
PY
  chmod 700 "$2"
  bash -n "$2"
}
preparar_deploy() {
  cargar_banco "$B"
  bash "$AQUI/preparar.sh" "$B" > /dev/null   # el migrador del banco
  local me reglas
  me=$(whoami)
  reglas=$(python3 -I -c 'import json, sys
b, me, port, host, pgport, pguser, db = sys.argv[1:8]
print(json.dumps([
  ["[ \"$(id -u)\" = 0 ] || { echo \"se ejecuta como root", "true || { echo \"se ejecuta como root", 1],
  ["API=https://api.ironbodyneiva.cloud/api", f"API=http://127.0.0.1:{port}/api", 1],
  ["CRM_WEB=https://ironbodyneiva.cloud\n", f"CRM_WEB=file://{b}/crm\n", 1],
  ["PSQL=\"sudo -u postgres psql -X -Atq -v ON_ERROR_STOP=1 -d ironbody\"", f"PSQL=\"psql -X -Atq -v ON_ERROR_STOP=1 -h {host} -p {pgport} -U {pguser} -d {db}\"", 1],
  ["LOGDIR=/root\n", f"LOGDIR={b}/root\n", 1],
  ["WWW=www-data\n", f"WWW={me}\n", 1], ["ROOT_U=root\n", f"ROOT_U={me}\n", 1],
  ["DUENO_BD=iron\n", "DUENO_BD=h5_owner\n", 1], ["APP_BD=iron_app\n", "APP_BD=h5_app\n", 1],
  ["NIVEL_ERROR=production.ERROR", "NIVEL_ERROR=local.ERROR", 1],
  ["MIGRADOR=/usr/local/bin/ironbody-migrate", f"MIGRADOR={b}/root/ironbody-migrate", 1],
]))' "$B" "$me" "$BANCO_PORT" "$PG_HOST" "$PG_PORT" "$PG_USER" "$BANCO_DB")
  copia "$OPS/deploy-m1-backend.sh" "$B/root/deploy-m1-backend.tmp" "$reglas"
  sed "s#/var/www/api#$B/api#g" "$B/root/deploy-m1-backend.tmp" > "$B/root/deploy-m1-backend.sh"
  rm -f "$B/root/deploy-m1-backend.tmp"; chmod 700 "$B/root/deploy-m1-backend.sh"; bash -n "$B/root/deploy-m1-backend.sh"
  echo "   copia del banco de deploy-m1-backend.sh: 11 sustituciones del bloque de producción y las rutas /var/www/api"
}
# Los dos mandos de E2 (copias del banco) con adaptadores de GNU solo para ellos (sed -i -E y shred), en $B/bin-mandos.
preparar_mandos() {
  cargar_banco "$B"
  local me url m nombre
  me=$(whoami); url="http://127.0.0.1:$BANCO_PORT/api"
  mkdir -p "$B/bin-mandos"
  printf '%s\n' '#!/bin/bash' '# sed del banco SOLO para los mandos: «sed -i -E …» de GNU → el de macOS («-i \x27\x27»).' \
    'if [ "${1:-}" = -i ]; then shift; exec /usr/bin/sed -i "" "$@"; fi' 'exec /usr/bin/sed "$@"' > "$B/bin-mandos/sed"
  printf '%s\n' '#!/bin/bash' '# shred del banco: macOS no lo trae; borra (la copia es sintética).' '[ "${1:-}" = -u ] && shift' 'exec rm -f -- "$@"' > "$B/bin-mandos/shred"
  chmod 755 "$B/bin-mandos/sed" "$B/bin-mandos/shred"
  for m in h5-engine-scope.sh:scope motores/motores-prompts-scope.sh:prompts; do
    nombre=$(basename "${m%%:*}")
    copia "$OPS/${m%%:*}" "$B/root/$nombre" "$(python3 -I -c 'import json, sys
b, me, url, tipo = sys.argv[1:5]
print(json.dumps([
  ["cd /var/www/api/backend", f"cd {b}/api/backend", 1],
  ["install -m 600 -o root -g root \"$ENVF\" \"$COPIA\"", "install -m 600 \"$ENVF\" \"$COPIA\"", 1],
  [f"COPIA=\"/root/env-pre-{tipo}-", f"COPIA=\"{b}/root/env-pre-{tipo}-", 1],
  ["find /root -maxdepth 1", f"find {b}/root -maxdepth 1", 2],
  ["https://api.ironbodyneiva.cloud/api", url, 1],
  ["= www-data ]", f"= {me} ]", 1],
  ["DESPUES=$(leer)", "DESPUES=$(leer); [ -z \"${BANCO_FALLA:-}\" ] || DESPUES=falla-forzada-del-banco", 1],
]))' "$B" "$me" "$url" "${m##*:}")"
  done
  echo "   copias del banco: h5-engine-scope.sh y motores-prompts-scope.sh (7 sustituciones cada uno; BANCO_FALLA=1 fuerza el fallo en la lectura de después)"
}
mando() { ( export PATH="$B/bin-mandos:$PATH" "${RED[@]}"; bash "$B/root/$1" "${@:2}" ); }
clave_prompts() { ( cd "$B/api/backend" && env "${RED[@]}" php artisan tinker --execute='echo config("marketing.ultron.studio_prompts"), PHP_EOL;' 2>/dev/null | tail -1 ); }

case "$ORDEN" in
  montar)
    [ ! -e "$B" ] || muere "ya existe $B: bash $AQUI/ensayar.sh $B parar && rm -rf $B"
    echo "#### 1. el banco con $PROD (lo que sirve producción) como «producción» y como origin/main"
    BANCO_PREVIO=$PROD BANCO_WIP=0 bash "$AQUI/montar.sh" "$B" "$PROD"
    echo "#### 2. el estado de producción (H9 en sombra, laboratorio hablado 2a) en el entorno sintético"
    estado_prod
    echo "#### 3. la API, la valla y la cuenta del smoke"
    bash "$AQUI/ensayar.sh" "$B" arrancar
    bash "$AQUI/sembrar.sh" "$B"
    echo "#### 4. el CRM «publicado» de hoy ($CRM_PROD)"
    mkdir -p "$B/crm"; printf '{\n  "commit": "%s",\n  "dirty": false\n}\n' "$CRM_PROD" > "$B/crm/version.json"
    echo "BANCO DE LA OLA M-1 LISTO: $B (producción = ${PROD:0:7} en sombra). Siguiente: bash $0 $B ola" ;;
  ola) ola; respaldo ;;
  respaldo) respaldo ;;
  crm)
    [[ "${1:-}" =~ ^[0-9a-f]{7}$ ]] || muere "crm <commit de 7 cifras>"
    mkdir -p "$B/crm"; printf '{\n  "commit": "%s",\n  "dirty": false\n}\n' "$1" > "$B/crm/version.json"; echo "   CRM «publicado»: $1" ;;
  desplegar)
    cargar_banco "$B"
    preparar_deploy
    . "$B/banco.env"
    export "${RED[@]}"
    export APP_DIR="$B/api/backend" SMOKE_API="http://127.0.0.1:$BANCO_PORT/api" SMOKE_RUN_AS= \
      SMOKE_PSQL="psql -X -Atq -h $PG_HOST -p $PG_PORT -U $PG_USER -d $BANCO_DB" SMOKE_ADMIN_ID="${ADMIN_ID:-}" \
      SMOKE_VALLA_STATS="http://127.0.0.1:$PUERTO_VALLA/__stats" SMOKE_LOGS="$B/api/backend/storage/logs"
    exec bash "$B/root/deploy-m1-backend.sh" "$ESPERADO" "$B/bk" "$@" ;;
  mandos)
    # E2 y su vuelta, con la trampa de M1-B: el fichero de entorno vuelve IDÉNTICO y con su modo (640 a propósito), un
    # fallo forzado a medias lo restaura solo y deja la llave como estaba, y solo queda la copia del fallo.
    cargar_banco "$B"
    [ "$(git -C "$B/api" rev-parse --short=7 HEAD)" = "$ESPERADO" ] || muere "el banco no está desplegado ($ESPERADO): primero bash $0 $B desplegar"
    preparar_mandos
    set +e   # los mandos pueden (y uno DEBE) salir con 1: lo juzga cada caso
    ENVF="$B/api/backend/.env"; MODO_ORIG=$(stat -c '%a' "$ENVF")
    install -m 600 "$ENVF" "$B/root/env-ref-m1"; chmod 640 "$ENVF"
    rm -f "$B"/root/env-pre-scope-* "$B"/root/env-pre-prompts-*
    S1=$(mando h5-engine-scope.sh canary off 2>&1); r=$?; printf '%s\n' "$S1" | sed 's/^/      /'
    caso "E2a: h5-engine-scope.sh canary off → APLICADO" "$([ "$r" = 0 ] && printf '%s' "$S1" | grep -q 'ALCANCE canary APLICADO' && echo 0 || echo 1)" "código $r"
    S2=$(mando motores-prompts-scope.sh canary off 2>&1); r=$?; printf '%s\n' "$S2" | sed 's/^/      /'
    caso "E2b: motores-prompts-scope.sh canary off → APLICADA (traza con la llave canary)" "$([ "$r" = 0 ] && printf '%s' "$S2" | grep -q 'LLAVE DE PROMPTS canary APLICADA' && echo 0 || echo 1)" "código $r"
    caso "el modo del fichero de entorno sigue en 640 tras E2" "$([ "$(stat -c '%a' "$ENVF")" = 640 ] && echo 0 || echo 1)" "$(stat -c '%a' "$ENVF")"
    install -m 600 "$ENVF" "$B/root/env-ref-m1-canary"
    S3=$(BANCO_FALLA=1 mando motores-prompts-scope.sh off canary 2>&1); r=$?; printf '%s\n' "$S3" | sed 's/^/      /'
    caso "M1-B: un fallo forzado en la lectura de después sale con 1 y la trampa restaura (FALLO A MEDIAS, etapa 2)" "$([ "$r" = 1 ] && printf '%s' "$S3" | grep -q 'FALLO A MEDIAS (etapa 2' && echo 0 || echo 1)" "código $r"
    caso "M1-B: el fichero de entorno, idéntico byte a byte al de antes del fallo" "$(cmp -s "$ENVF" "$B/root/env-ref-m1-canary" && echo 0 || echo 1)"
    caso "M1-B: el modo del fichero de entorno sigue en 640 tras la restauración" "$([ "$(stat -c '%a' "$ENVF")" = 640 ] && echo 0 || echo 1)" "$(stat -c '%a' "$ENVF")"
    caso "M1-B: la llave efectiva sigue en canary (caché regenerada con el valor anterior)" "$([ "$(clave_prompts)" = canary ] && echo 0 || echo 1)" "$(clave_prompts)"
    caso "M1-B: la copia del intento fallido se conserva (lleva secretos: se dice dónde)" "$(ls "$B"/root/env-pre-prompts-off-* > /dev/null 2>&1 && echo 0 || echo 1)"
    S4=$(mando motores-prompts-scope.sh off canary 2>&1); r=$?; printf '%s\n' "$S4" | sed 's/^/      /'
    caso "vuelta de E2b: motores-prompts-scope.sh off canary → APLICADA y traza «off legacy prompts_off»" "$([ "$r" = 0 ] && printf '%s' "$S4" | grep -q 'LLAVE DE PROMPTS off APLICADA' && printf '%s' "$S4" | grep -q 'off legacy prompts_off' && echo 0 || echo 1)" "código $r"
    S5=$(mando h5-engine-scope.sh off canary 2>&1); r=$?; printf '%s\n' "$S5" | sed 's/^/      /'
    caso "vuelta de E2a: h5-engine-scope.sh off canary → APLICADO" "$([ "$r" = 0 ] && printf '%s' "$S5" | grep -q 'ALCANCE off APLICADO' && echo 0 || echo 1)" "código $r"
    caso "ida y vuelta: el fichero de entorno idéntico byte a byte al de partida" "$(cmp -s "$ENVF" "$B/root/env-ref-m1" && echo 0 || echo 1)"
    caso "ida y vuelta: el modo sigue en 640" "$([ "$(stat -c '%a' "$ENVF")" = 640 ] && echo 0 || echo 1)" "$(stat -c '%a' "$ENVF")"
    QUEDAN=$(ls "$B"/root/env-pre-scope-* "$B"/root/env-pre-prompts-* 2>/dev/null | wc -l | tr -d ' ')
    caso "copias con secretos que quedan: solo la del fallo (las aplicadas sin desvíos se borran)" "$([ "$QUEDAN" = 1 ] && echo 0 || echo 1)" "$QUEDAN"
    chmod "$MODO_ORIG" "$ENVF"; rm -f "$B/root/env-ref-m1" "$B/root/env-ref-m1-canary" "$B"/root/env-pre-prompts-off-*
    ( cd "$B/api/backend" && env "${RED[@]}" php artisan config:cache -q && chmod 600 bootstrap/cache/config.php )
    set -e
    echo "$((CASOS - FALLOS)) OK · $FALLOS FALLO"
    [ "$FALLOS" = 0 ] ;;
  *) muere "uso: bash m1.sh <banco> montar | ola | respaldo | crm <commit> | desplegar [opciones] | mandos" ;;
esac

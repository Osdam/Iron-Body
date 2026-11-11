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
#   bash m1.sh <banco> plantillas        el mando de las banderas de Meta (plantillas/meta-templates-scope.sh): off → read →
#                                         full → read → off, rechazos, fallo forzado, smokes en read y la copia fiel
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
  sucios=$(git -C "$WT" status --porcelain --untracked-files=all -- backend/app backend/config backend/routes backend/database backend/resources backend/bootstrap backend/.env.example n8n || true)
  # BANCO_OLA_COMMIT=<sha>: se ensaya ESE commit (el banco lleva commits, no el árbol); lo que esté sin confirmar se dice y
  # NO entra en el ensayo. Sin ella, con código de la ola sin confirmar, se niega (no se sabría qué se ensaya).
  if [ -n "${BANCO_OLA_COMMIT:-}" ]; then
    [ -z "$sucios" ] || echo "   AVISO: código sin confirmar en el worktree que NO entra en este ensayo (se ensaya $BANCO_OLA_COMMIT): $(printf '%s\n' "$sucios" | awk '{print $2}' | tr '\n' ' ')"
  else
    [ -z "$sucios" ] || muere "hay ficheros de la ola sin confirmar en el worktree ($(printf '%s\n' "$sucios" | wc -l | tr -d ' ')): el ensayo es con los commits DEFINITIVOS (o BANCO_OLA_COMMIT=<sha> para ensayar uno concreto)"
  fi
  # Los smokes se copian del checkout DESPLEGADO (el commit): si están cambiados en el árbol, el banco no probaría estos.
  # Los guiones de despliegue y de los mandos se copian del árbol (preparar_deploy, preparar_mandos): pueden ir por delante.
  sucios=$(git -C "$WT" status --porcelain -- 'backend/docs/ultron-studio/ops/smoke-*' 'backend/docs/ultron-studio/ops/perf-h8' 'backend/docs/ultron-studio/ops/perf-h9' || true)
  [ -z "$sucios" ] || muere "los smokes del árbol no son los del commit ($(printf '%s\n' "$sucios" | tr '\n' ' ')): confirmarlos antes"
  sucios=$(git -C "$WT" status --porcelain -- backend/docs/ultron-studio/ops | grep -v '/evidencia-' || true)
  [ -z "$sucios" ] || echo "   AVISO: guiones de ops del árbol por delante del commit (se ensayan ESOS; el lead los confirma después): $(printf '%s\n' "$sucios" | awk '{print $2}' | tr '\n' ' ')"
  git -C "$WT" merge-base --is-ancestor "$PROD" HEAD || muere "HEAD del worktree no contiene $PROD (lo que sirve producción)"
  local rama cabeza
  rama=$(git -C "$WT" rev-parse --abbrev-ref HEAD); cabeza=$(git -C "$WT" rev-parse HEAD)
  ( cd "$B/src" && git fetch -q "$(git -C "$WT" rev-parse --path-format=absolute --git-common-dir)" "$rama" \
      && [ "$(git rev-parse FETCH_HEAD)" = "$cabeza" ] && git -c advice.detachedHead=false checkout -q -B banco "${BANCO_OLA_COMMIT:-FETCH_HEAD}" \
      && git merge-base --is-ancestor HEAD FETCH_HEAD && git branch -q -f main HEAD ) \
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
b, me, url, tipo, nombre, psql = sys.argv[1:7]
base = nombre[:-3]
reglas = [
  ["cd /var/www/api/backend", f"cd {b}/api/backend", 1],
  ["install -m 600 -o root -g root \"$ENVF\" \"$COPIA\"", "install -m 600 \"$ENVF\" \"$COPIA\"", 1],
  [f"COPIA=\"/root/env-pre-{tipo}-", f"COPIA=\"{b}/root/env-pre-{tipo}-", 1],
  [f"LOG=\"/root/{base}-", f"LOG=\"{b}/root/{base}-", 1],
  ["find /root -maxdepth 1", f"find {b}/root -maxdepth 1", 2],
  ["/run/lock/", f"{b}/run/", 3],
  ["https://api.ironbodyneiva.cloud/api", url, 1],
  ["= www-data ]", f"= {me} ]", 1],
  ["DESPUES=$(leer)", "DESPUES=$(leer); [ -z \"${BANCO_FALLA:-}\" ] || DESPUES=falla-forzada-del-banco", 1],
]
if tipo == "prompts":   # MB-8: la lectura del CHECK de la cadena, contra la base del banco
    reglas.append(["sudo -u postgres psql -X -Atq -d ironbody", psql, 1])
print(json.dumps(reglas))' "$B" "$me" "$url" "${m##*:}" "$nombre" "psql -X -Atq -h $PG_HOST -p $PG_PORT -U $PG_USER -d $BANCO_DB")"
    ! grep -nE '(^|[^A-Za-z0-9_./-])/root/|/run/lock/|sudo -u postgres' "$B/root/$nombre" || muere "la copia del banco de $nombre todavía nombra /root, /run/lock o sudo -u postgres: el mando cambió"
  done
  echo "   copias del banco: h5-engine-scope.sh (9 sustituciones) y motores-prompts-scope.sh (10: también la lectura del CHECK); registro, copias y cerrojos bajo $B; BANCO_FALLA=1 fuerza el fallo en la lectura de después"
}
mando() { ( export PATH="$B/bin-mandos:$PATH" "${RED[@]}"; bash "$B/root/$1" "${@:2}" ); }
clave_prompts() { ( cd "$B/api/backend" && env "${RED[@]}" php artisan tinker --execute='echo config("marketing.ultron.studio_prompts"), PHP_EOL;' 2>/dev/null | tail -1 ); }

# ── El mando de las plantillas de Meta (plantillas/meta-templates-scope.sh) ──────────────────────────────────────────
# Dos copias del banco: la FIEL (solo las rutas, los usuarios y la URL: conserva las expectativas de producción, PM-6, y
# tiene que negarse aquí: el banco es «local» con el doble) y la DEL BANCO (además «local» y «fake», y BANCO_FALLA=1
# fuerza el fallo en la lectura de después). La lógica es la del repo.
preparar_plantillas() {
  cargar_banco "$B"
  local me grupo url comunes
  me=$(whoami); grupo=$(id -gn); url="http://127.0.0.1:$BANCO_PORT/api"
  preparar_mandos > /dev/null   # los adaptadores de sed -i y shred en $B/bin-mandos
  comunes=$(python3 -I -c 'import json, sys
b, me, grupo, url = sys.argv[1:5]
print(json.dumps([
  ["[ \"$(id -u)\" = 0 ] || { echo \"se ejecuta como root", "true || { echo \"se ejecuta como root", 1],
  ["APPDIR=/var/www/api/backend\n", f"APPDIR={b}/api/backend\n", 1],
  ["API=https://api.ironbodyneiva.cloud/api\n", f"API={url}\n", 1],
  ["WWW=www-data\n", f"WWW={me}\n", 1], ["ROOT_U=root\n", f"ROOT_U={me}\n", 1], ["ROOT_G=root\n", f"ROOT_G={grupo}\n", 1],
  ["COPIAS=/root\n", f"COPIAS={b}/root\n", 1], ["CERROJOS=/run/lock\n", f"CERROJOS={b}/run\n", 1],
]))' "$B" "$me" "$grupo" "$url")
  copia "$OPS/plantillas/meta-templates-scope.sh" "$B/root/meta-templates-scope-fiel.sh" "$comunes"
  copia "$OPS/plantillas/meta-templates-scope.sh" "$B/root/meta-templates-scope.sh" "$(python3 -I -c 'import json, sys
r = json.loads(sys.argv[1]) + [
  ["EXIGE_ENTORNO=production", "EXIGE_ENTORNO=local", 1], ["EXIGE_DRIVER=graph", "EXIGE_DRIVER=fake", 1],
  ["DESPUES=$(leer)", "DESPUES=$(leer); [ -z \"${BANCO_FALLA:-}\" ] || DESPUES=falla-forzada-del-banco", 1]]
print(json.dumps(r))' "$comunes")"
  local f
  for f in meta-templates-scope.sh meta-templates-scope-fiel.sh; do
    ! grep -v '^[[:space:]]*#' "$B/root/$f" | grep -nE '/var/www|(^|[^A-Za-z0-9_./-])/root/|/run/lock' || muere "la copia del banco $f todavía nombra rutas o usuarios de producción: el mando cambió"
  done
  echo "   copias del banco del mando de plantillas: la FIEL (8 sustituciones: rutas, usuarios y URL) y la del banco (11: además «local», «fake» y el fallo forzado)"
}
pon_env() { # CLAVE VALOR en el entorno SINTÉTICO del banco (fuera del mando: para preparar un estado) y config:cache
  python3 -I - "$B/api/backend/.env" "$1" "$2" <<'PY'
import re, sys
p, k, v = sys.argv[1:4]
s = open(p, encoding="utf-8").read()
s, n = re.subn(r"^([ \t]*)" + k + r"=.*$", lambda m: m.group(1) + k + "=" + v, s, count=1, flags=re.M)
if n == 0:
    s = s.rstrip("\n") + "\n  " + k + "=" + v + "\n"
open(p, "w", encoding="utf-8").write(s)
PY
  cache_banco
}
cache_banco() { ( cd "$B/api/backend" && env "${RED[@]}" php artisan config:cache -q && chmod 600 bootstrap/cache/config.php ); }
banderas() { ( cd "$B/api/backend" && env "${RED[@]}" php artisan tinker --execute='$s = "App\\Services\\UltronStudio\\StudioMetaTemplates"; echo json_encode([$s::enabled(), $s::syncEnabled(), $s::submitEnabled()]), PHP_EOL;' 2>/dev/null | tail -1 ); }
# Un paso del ensayo: la orden, su salida sangrada y el caso (código esperado y un texto que tiene que salir).
paso() { # nombre código_esperado texto_esperado orden…
  local nombre=$1 esperado=$2 texto=$3 salida rc
  shift 3
  salida=$("$@" 2>&1); rc=$?
  printf '%s\n' "$salida" | sed "s#$B#\$B#g; s/^/      /"
  caso "$nombre" "$([ "$rc" = "$esperado" ] && printf '%s' "$salida" | grep -qF -- "$texto" && echo 0 || echo 1)" "código $rc"
}
igual() { caso "$1" "$(cmp -s "$B/api/backend/.env" "$2" && echo 0 || echo 1)"; }
modo640() { caso "$1: el modo del fichero de entorno sigue en 640" "$([ "$(stat -c '%a' "$B/api/backend/.env")" = 640 ] && echo 0 || echo 1)" "$(stat -c '%a' "$B/api/backend/.env")"; }
smoke_h8_banco() { # fase → el lanzador del H8 del árbol, con las variables del banco (como ensayar.sh smoke para el H9)
  mkdir -p "$B/smokework8"
  env "${RED[@]}" APP_DIR="$B/api/backend" SMOKE_API="http://127.0.0.1:$BANCO_PORT/api" SMOKE_RUN_AS= \
    SMOKE_PSQL="psql -X -Atq -h $PG_HOST -p $PG_PORT -U $PG_USER -d $BANCO_DB" SMOKE_WORKDIR="$B/smokework8" \
    SMOKE_PY="$OPS/smoke-h8.py" SMOKE_H6_PY="$OPS/smoke-h6.py" SMOKE_BUDGETS="$OPS/perf-h8/presupuestos-h8.json" \
    SMOKE_ADMIN_ID="$ADMIN_ID" SMOKE_PHASE="$1" SMOKE_VALLA_STATS="http://127.0.0.1:$PUERTO_VALLA/__stats" \
    SMOKE_LOGS="$B/api/backend/storage/logs" bash "$OPS/smoke-run-h8.sh"
}

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
  plantillas)
    # El mando de las banderas de Meta: off → read → full → read → off, los rechazos (copia fiel, off → full, estado
    # actual equivocado, full sin META_ENABLED, mezcla), un fallo forzado a medias, dos idas y vueltas idénticas byte a
    # byte en 640, los smokes del H9 (read) y del H8 en read, registros en 600 y la copia que queda. Deja el banco como
    # estaba (el fichero de entorno del principio y su modo).
    cargar_banco "$B"
    [ "$(git -C "$B/api" rev-parse --short=7 HEAD)" = "$ESPERADO" ] || muere "el banco no está desplegado ($ESPERADO): primero bash $0 $B desplegar"
    [ -n "$(lsof -nP -iTCP:"$BANCO_PORT" -sTCP:LISTEN 2>/dev/null)" ] || bash "$AQUI/ensayar.sh" "$B" arrancar
    preparar_plantillas
    set +e
    ENVF="$B/api/backend/.env"; MODO_ORIG=$(stat -c '%a' "$ENVF")
    rm -f "$B"/root/env-pre-plantillas-* "$B"/root/meta-templates-scope-2*.log
    install -m 600 "$ENVF" "$B/root/env-ref-p0"; chmod 640 "$ENVF"
    caso "partida: las tres banderas apagadas (las escribió el despliegue)" "$([ "$(banderas)" = '[false,false,false]' ] && echo 0 || echo 1)" "$(banderas)"
    echo "#### rechazos sin tocar nada"
    paso "copia FIEL (expectativas de producción): «read off» en el banco se niega por PM-6 (no es graph en production)" 1 "tiene que ser «graph» en «production»" mando meta-templates-scope-fiel.sh read off
    igual "  … y el fichero de entorno no cambió" "$B/root/env-ref-p0"
    paso "«full off» se niega (off → full directo no: primero read)" 2 "off → full directo no" mando meta-templates-scope.sh full off
    paso "estado actual equivocado («read full» con off) se niega y dice los tres valores" 1 "maestra=false sincronización=false envío=false" mando meta-templates-scope.sh read full
    igual "  … y el fichero de entorno no cambió" "$B/root/env-ref-p0"
    echo "#### primera ida y vuelta: off → read → off (sin META_ENABLED)"
    paso "P1: «read off» → APLICADO (la sincronización en el programador, la orden en seco sin red)" 0 "PLANTILLAS DE META read APLICADO" mando meta-templates-scope.sh read off
    caso "  … banderas efectivas read" "$([ "$(banderas)" = '[true,true,false]' ] && echo 0 || echo 1)" "$(banderas)"
    modo640 "P1"
    install -m 600 "$ENVF" "$B/root/env-ref-p1"
    paso "«full read» se niega sin META_ENABLED (el código lo exige para enviar)" 1 "META_ENABLED tiene que estar en true" mando meta-templates-scope.sh full read
    igual "  … y el fichero de entorno no cambió" "$B/root/env-ref-p1"
    paso "vuelta de P1: «off read» → APLICADO" 0 "PLANTILLAS DE META off APLICADO" mando meta-templates-scope.sh off read
    igual "primera ida y vuelta: el fichero de entorno idéntico byte a byte al de partida" "$B/root/env-ref-p0"
    modo640 "primera ida y vuelta"
    echo "#### segunda ida y vuelta: off → read → full → read → off (con META_ENABLED, puesto fuera del mando)"
    pon_env META_ENABLED true; chmod 640 "$ENVF"; install -m 600 "$ENVF" "$B/root/env-ref-p2"
    paso "P1: «read off» → APLICADO" 0 "PLANTILLAS DE META read APLICADO" mando meta-templates-scope.sh read off
    echo "#### smokes en read (SMOKE_EXPECT_META_TEMPLATES=read)"
    S9=$(SMOKE_EXPECT_M1=1 SMOKE_EXPECT_META_TEMPLATES=read bash "$AQUI/ensayar.sh" "$B" smoke "" 2 2>&1); r9=$?
    printf '%s\n' "$S9" | grep -E '^(FALLO|OMITIDO|RESUMEN|SMOKE|OK      (meta/|enviar|la sonda|las 4|recuento|cuerpos \([0-9]+\): sin claves prohibidas))|^INFO    (meta/|POST strategies)' | sed 's/^/      /'
    caso "smoke del H9 en read: 0 FALLO (código 0 o 3: el OMITIDO conocido de PERF9)" "$( { [ "$r9" = 0 ] || [ "$r9" = 3 ]; } && ! printf '%s' "$S9" | grep -q '^FALLO' && echo 0 || echo 1)" "código $r9"
    echo "   pausa de 65 s (el cupo de inicios de sesión por IP)"; sleep 65
    S8=$(smoke_h8_banco 2a 2>&1); r8=$?
    printf '%s\n' "$S8" | grep -E '^(FALLO|RESUMEN|SMOKE|OK      el programador)' | sed 's/^/      /'
    caso "smoke del H8 en read: el programador sin la sincronización de Meta como tarea de monitor, y ningún FALLO salvo el conocido del banco (ElevenLabs)" \
      "$(printf '%s' "$S8" | grep -q '^OK      el programador' && [ "$(printf '%s\n' "$S8" | grep '^FALLO' | grep -vc 'ELEVENLABS_VERIFIED')" = 0 ] && echo 0 || echo 1)" "código $r8"
    paso "P2: «full read» → APLICADO" 0 "PLANTILLAS DE META full APLICADO" mando meta-templates-scope.sh full read
    caso "  … banderas efectivas full" "$([ "$(banderas)" = '[true,true,true]' ] && echo 0 || echo 1)" "$(banderas)"
    modo640 "P2"
    install -m 600 "$ENVF" "$B/root/env-ref-full"
    echo "#### una MEZCLA (envío sin sincronización, N-1) puesta a mano fuera del mando: se niega sin tocar nada"
    pon_env ULTRON_STUDIO_META_TEMPLATES_SYNC_ENABLED false; install -m 600 "$ENVF" "$B/root/env-ref-mezcla"
    paso "con la mezcla, «read full» se niega y dice los tres valores" 1 "MEZCLA que este mando no admite (maestra=true sincronización=false envío=true)" mando meta-templates-scope.sh read full
    igual "  … y el fichero de entorno no cambió" "$B/root/env-ref-mezcla"
    install -m 640 "$B/root/env-ref-full" "$ENVF"; cache_banco
    echo "#### fallo forzado a medias (BANCO_FALLA=1: la lectura de después no es la pedida)"
    export BANCO_FALLA=1
    paso "M1-B: «read full» con el fallo → código 1, FALLO A MEDIAS (etapa 2) y restaurado y COMPROBADO" 1 "restaurado y COMPROBADO" mando meta-templates-scope.sh read full
    unset BANCO_FALLA
    igual "  … el fichero de entorno idéntico byte a byte al de antes del fallo" "$B/root/env-ref-full"
    modo640 "tras el fallo"
    caso "  … las banderas efectivas siguen en full (caché regenerada con el estado anterior)" "$([ "$(banderas)" = '[true,true,true]' ] && echo 0 || echo 1)" "$(banderas)"
    caso "  … la copia del intento fallido se conserva" "$(ls "$B"/root/env-pre-plantillas-read-* > /dev/null 2>&1 && echo 0 || echo 1)"
    paso "vuelta de P2: «read full» → APLICADO" 0 "PLANTILLAS DE META read APLICADO" mando meta-templates-scope.sh read full
    paso "vuelta de P1: «off read» → APLICADO (sin la sincronización en el programador)" 0 "PLANTILLAS DE META off APLICADO" mando meta-templates-scope.sh off read
    igual "segunda ida y vuelta: el fichero de entorno idéntico byte a byte al de su partida" "$B/root/env-ref-p2"
    modo640 "segunda ida y vuelta"
    LOGS_MAL=$(for f in "$B"/root/meta-templates-scope-2*.log; do [ "$(stat -c '%a' "$f")" = 600 ] || echo "$f"; done | wc -l | tr -d ' ')
    caso "los registros del mando en 600 ($(ls "$B"/root/meta-templates-scope-2*.log | wc -l | tr -d ' ') registros)" "$([ "$LOGS_MAL" = 0 ] && echo 0 || echo 1)" "$LOGS_MAL fuera de 600"
    QUEDAN=$(ls "$B"/root/env-pre-plantillas-* 2>/dev/null | wc -l | tr -d ' ')
    caso "copias con secretos que quedan: solo la del fallo (las aplicadas sin desvíos se borran)" "$([ "$QUEDAN" = 1 ] && echo 0 || echo 1)" "$QUEDAN"
    install -m "$MODO_ORIG" "$B/root/env-ref-p0" "$ENVF"; cache_banco
    caso "el banco vuelve a su fichero de entorno de partida (sin META_ENABLED) y a su modo" "$(cmp -s "$ENVF" "$B/root/env-ref-p0" && [ "$(stat -c '%a' "$ENVF")" = "$MODO_ORIG" ] && echo 0 || echo 1)"
    rm -f "$B"/root/env-ref-p* "$B/root/env-ref-full" "$B/root/env-ref-mezcla" "$B"/root/env-pre-plantillas-*
    set -e
    echo "$((CASOS - FALLOS)) OK · $FALLOS FALLO"
    [ "$FALLOS" = 0 ] ;;
  *) muere "uso: bash m1.sh <banco> montar | ola | respaldo | crm <commit> | desplegar [opciones] | mandos | plantillas" ;;
esac

#!/bin/bash
# El banco del CIERRE del Hito 9 (OPS9-c, G-9): ensaya deploy-h9-backend-cierre.sh y el mando nuevo sobre una
# «producción» que llega a la fase 1 POR EL MISMO CAMINO que la real (L9-76): el H8 cerrado en a608450 → la fase 1 con
# deploy-h9-backend-fase1.sh (aceptando los tres commits de documentación del H8, como el propietario) → y encima, como
# origin/main, la OLA: d701b0c más TODO lo que está sin confirmar en el árbol (backend/{app,config,routes,database,
# .env.example} y docs/ultron-studio/ops), en un commit `wip(ultron-studio-h9)`: lo que el lead confirmará.
# Usa montar.sh, preparar.sh, ensayar.sh y sembrar.sh del banco del H9 sin cambiarlos.
#   bash ola.sh <banco> montar            el banco hasta la fase 1 (con su despliegue real) y la ola como origin/main
#   bash ola.sh <banco> ola               (re)hace el commit de la ola con el árbol de AHORA y el respaldo de hoy
#   bash ola.sh <banco> respaldo          rehace el respaldo con el formato del plan (api-commit = la producción de hoy)
#   bash ola.sh <banco> crm <commit>      el version.json del CRM «publicado» (file://$B/crm)
#   bash ola.sh <banco> pestanas viejas|nuevas   añade al access.log sintético 3 peticiones de vale de ahora
#   bash ola.sh <banco> desplegar [opciones de deploy-h9-backend-cierre.sh]   la copia del banco, con la RED y los SMOKE_*
#   bash ola.sh <banco> mando <orden…>    el mando del repo (copia del banco, ensayar.sh alcance)
#   bash ola.sh <banco> via-abierta       SOLO EN EL BANCO: las dos constantes abiertas en commits del origen del banco y
#                                         las palancas del H8 en su entorno sintético, para ensayar `canal … on` del mando
#   bash ola.sh <banco> crm-montar | crm-servir | crm-parar | crm-desplegar [--solo-comprobar]
#                                         el CRM de la ola: clon con la ola como origin/main, un «servidor» local con un build
#                                         viejo sintético, un servidor estático con la reserva de index.html (como try_files)
#                                         y deploy-h9-crm-cierre.sh con CRM_REMOTE=local (rsync a ese directorio)
# Variables: las de montar.sh (BANCO_DB, BANCO_PORT, BANCO_PUERTO_VALLA, BANCO_PG_PORT).
. "$(dirname "$0")/comun.sh"
B=$(banco_dir "${1:-}")
ORDEN=${2:-}
shift 2 || true
H8_CERRADO=a608450
FASE1=ce7c150
ACEPTA_FASE1=a921fbb,307ad51,5a10d51           # los tres commits ajenos que el propietario aceptó el 10-11 (L9-76)
export PATH="$B/bin:$PATH"

ola_commit() { # el commit de la ola en $B/src (main) y ESPERADO/PREVIO en banco.env
  cargar_banco "$B"
  local G="git -c user.name=banco -c user.email=banco@local -c advice.detachedHead=false" n=0 line st f
  ( cd "$B/src"
    $G checkout -q -B banco "$(git -C "$WT" rev-parse HEAD)"
    while IFS= read -r line; do
      st=${line:0:2}; f=${line:3}
      case "$st" in " D"|"D "|"DD") continue ;; esac
      case "$f" in backend/app/*|backend/config/*|backend/routes/*|backend/database/*|backend/bootstrap/app.php|backend/.env.example|backend/docs/ultron-studio/ops/*) ;; *) continue ;; esac
      case "$f" in */banco-*|*/evidencia-*|*/h8-e2e*|*/h9-e2e*) continue ;; esac
      mkdir -p "$(dirname "$f")"; cp -R "$WT/$f" "$f"; n=$((n + 1))
    done < <(git -C "$WT" status --porcelain --untracked-files=all)
    git add -A backend
    $G commit -q -m "wip(ultron-studio-h9): banco: la ola del cierre sin confirmar ($n ficheros)"
    $G branch -q -f main HEAD
    echo "   ola: $(git rev-parse --short=7 HEAD) sobre $(git -C "$WT" rev-parse --short=7 HEAD) con $n ficheros sin confirmar del árbol" )
  local esp
  esp=$(git -C "$B/src" rev-parse --short=7 main)
  python3 -I - "$B/banco.env" "$esp" "$FASE1" <<'PY'
import re, sys
p, esp, previo = sys.argv[1:4]
s = open(p, encoding="utf-8").read()
s = re.sub(r"^ESPERADO=.*$", "ESPERADO=" + esp, s, flags=re.M)
s = re.sub(r"^PREVIO_BANCO=.*$", "PREVIO_BANCO=" + previo, s, flags=re.M)
open(p, "w", encoding="utf-8").write(s)
PY
  ( cd "$B/api" && git fetch -q origin )
}
respaldo() { # el bloque del plan: api-commit = la producción de hoy, volcado, huella, entorno; 700/600
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
# La copia del banco de deploy-h9-backend-cierre.sh: SOLO el bloque «lo propio de producción» y la comprobación de root
# (cada sustitución tiene que casar exactamente una vez; si el guion cambia y una no casa, falla en vez de ensayar otra
# cosa). La lógica es la del repo.
preparar_cierre() {
  cargar_banco "$B"
  bash "$AQUI/preparar.sh" "$B" > /dev/null   # el migrador del banco (y las copias del H9 de siempre)
  python3 -I - "$OPS" "$B" "$(whoami)" "$BANCO_PORT" "$PG_HOST" "$PG_PORT" "$PG_USER" "$BANCO_DB" <<'PY'
import sys
ops, b, me, port, host, pgport, pguser, db = sys.argv[1:9]
s = open(f"{ops}/deploy-h9-backend-cierre.sh", encoding="utf-8").read()
reglas = [
    ('[ "$(id -u)" = 0 ] || { echo "se ejecuta como root', 'true || { echo "se ejecuta como root', 1),
    ('API=https://api.ironbodyneiva.cloud/api', f'API=http://127.0.0.1:{port}/api', 1),
    ('CRM_WEB=https://ironbodyneiva.cloud\n', f'CRM_WEB=file://{b}/crm\n', 1),
    ('NGINX_ACCESS=/var/log/nginx/access.log', f'NGINX_ACCESS={b}/logs/nginx-access.log', 1),
    ('PSQL="sudo -u postgres psql -X -Atq -v ON_ERROR_STOP=1 -d ironbody"', f'PSQL="psql -X -Atq -v ON_ERROR_STOP=1 -h {host} -p {pgport} -U {pguser} -d {db}"', 1),
    ('LOGDIR=/root', f'LOGDIR={b}/root', 1),
    ('WWW=www-data', f'WWW={me}', 1), ('ROOT_U=root', f'ROOT_U={me}', 1),
    ('DUENO_BD=iron\n', 'DUENO_BD=h5_owner\n', 1), ('APP_BD=iron_app', 'APP_BD=h5_app', 1),
    ('NIVEL_ERROR=production.ERROR', 'NIVEL_ERROR=local.ERROR', 1),
    ('MIGRADOR=/usr/local/bin/ironbody-migrate', f'MIGRADOR={b}/root/ironbody-migrate', 1),
]
for viejo, nuevo, veces in reglas:
    n = s.count(viejo)
    if n != veces:
        sys.exit(f"ola: «{viejo[:60]}» aparece {n} veces (esperado {veces}): el guion cambió")
    s = s.replace(viejo, nuevo)
n = s.count("/var/www/api")
s = s.replace("/var/www/api", f"{b}/api")
open(f"{b}/root/deploy-h9-backend-cierre.sh", "w", encoding="utf-8").write(s)
print(f"   copia del banco: {len(reglas)} sustituciones del bloque de producción y {n} rutas /var/www/api")
PY
  chmod 700 "$B/root/deploy-h9-backend-cierre.sh"
  bash -n "$B/root/deploy-h9-backend-cierre.sh"
}
pestanas() { # viejas|nuevas → 3 líneas de ahora en el access.log sintético (formato combined)
  local q i t
  case "$1" in viejas) q="" ;; nuevas) q="?scope=stream" ;; *) muere "pestanas viejas|nuevas" ;; esac
  t=$(LC_ALL=C date -u '+%d/%b/%Y:%H:%M:%S +0000')
  for i in 1 2 3; do printf '192.0.2.%s - - [%s] "GET /api/admin/stream-ticket%s HTTP/2.0" 200 120 "https://ironbodyneiva.cloud/" "Mozilla/5.0 (banco)"\n' "$i" "$t" "$q" >> "$B/logs/nginx-access.log"; done
  printf '192.0.2.9 - - [%s] "GET /api/admin/ultron-studio/status HTTP/2.0" 401 50 "-" "curl (banco)"\n' "$t" >> "$B/logs/nginx-access.log"
  echo "   access.log sintético: +3 vales $1 de $t"
}

case "$ORDEN" in
  montar)
    [ ! -e "$B" ] || muere "ya existe $B: bash $AQUI/ensayar.sh $B parar && rm -rf $B"
    echo "#### 1. el banco con el H8 cerrado ($H8_CERRADO) como producción y la fase 1 ($FASE1) como origin/main"
    BANCO_PREVIO=$H8_CERRADO BANCO_WIP=0 bash "$AQUI/montar.sh" "$B" "$FASE1"
    cargar_banco "$B"
    echo "#### 2. la API y la valla, y la cuenta del smoke"
    bash "$AQUI/ensayar.sh" "$B" arrancar
    bash "$AQUI/sembrar.sh" "$B"
    echo "#### 3. la fase 1 con su propio guion, como el 10-11 (--acepta-main=$ACEPTA_FASE1)"
    bash "$AQUI/ensayar.sh" "$B" desplegar --acepta-main="$ACEPTA_FASE1"
    echo "#### 4. la ola como origin/main, el respaldo de hoy, el CRM «viejo» publicado y el access.log sintético"
    ola_commit
    respaldo
    mkdir -p "$B/crm"; printf '{\n  "commit": "9572426",\n  "dirty": false\n}\n' > "$B/crm/version.json"
    : > "$B/logs/nginx-access.log"
    echo "BANCO DEL CIERRE LISTO: $B (producción = la fase 1; origin/main = la ola)" ;;
  ola) ola_commit; respaldo ;;
  respaldo) respaldo ;;
  crm)
    [[ "${1:-}" =~ ^[0-9a-f]{7}$ ]] || muere "crm <commit de 7 cifras>"
    mkdir -p "$B/crm"; printf '{\n  "commit": "%s",\n  "dirty": false\n}\n' "$1" > "$B/crm/version.json"; echo "   CRM «publicado»: $1" ;;
  pestanas) pestanas "${1:-}" ;;
  desplegar)
    cargar_banco "$B"
    preparar_cierre
    . "$B/banco.env"
    export "${RED[@]}"
    export APP_DIR="$B/api/backend" SMOKE_API="http://127.0.0.1:$BANCO_PORT/api" SMOKE_RUN_AS= \
      SMOKE_PSQL="psql -X -Atq -h $PG_HOST -p $PG_PORT -U $PG_USER -d $BANCO_DB" SMOKE_ADMIN_ID="${ADMIN_ID:-}" \
      SMOKE_VALLA_STATS="http://127.0.0.1:$PUERTO_VALLA/__stats" SMOKE_LOGS="$B/api/backend/storage/logs"
    exec bash "$B/root/deploy-h9-backend-cierre.sh" "$ESPERADO" "$B/bk" "$@" ;;
  mando) exec bash "$AQUI/ensayar.sh" "$B" alcance "$@" ;;
  via-abierta)
    # SOLO EN EL BANCO (una copia fuera del repo; RUNBOOK_H9 §7.4: «la vía abierta se ensaya en una copia»): los commits
    # `outreach(sends-open): banco …` y `voice(calls-open): banco …` en el origen del banco, el avance rápido de su
    # «producción» y las palancas del H8 abiertas en su entorno SINTÉTICO (salientes, cupo 5 y 5 US$), para ensayar el
    # camino que escribe de `canal whatsapp on` y `canal voz on` del mando. Después el banco ya no vale para el despliegue:
    # se vuelve a montar.
    cargar_banco "$B"
    G="git -c user.name=banco -c user.email=banco@local"
    ( cd "$B/src"
      f=backend/app/Services/Outreach/Run/OutreachSendGate.php
      sed -i '' 's/public const SENDS_ENABLED_IN_CODE = false;/public const SENDS_ENABLED_IN_CODE = true;/' "$f"
      [ "$(grep -c 'public const SENDS_ENABLED_IN_CODE = true;' "$f")" = 1 ] || muere "no se pudo girar la constante de envío en el banco"
      git add "$f"; $G commit -q -m "outreach(sends-open): banco: ensayo del mando (OPS9-c), nunca en el repo"
      f=backend/app/Services/Voice/VoiceCallPolicy.php
      sed -i '' 's/public const CALLS_ENABLED_IN_CODE = false;/public const CALLS_ENABLED_IN_CODE = true;/' "$f"
      [ "$(grep -c 'public const CALLS_ENABLED_IN_CODE = true;' "$f")" = 1 ] || muere "no se pudo girar la constante de llamadas en el banco"
      git add "$f"; $G commit -q -m "voice(calls-open): banco: ensayo del mando (OPS9-c), nunca en el repo"
      $G branch -q -f main HEAD )
    ( cd "$B/api" && git fetch -q origin && git merge -q --ff-only origin/main && echo "   «producción» del banco: $(git rev-parse --short=7 HEAD) con las dos constantes abiertas" )
    python3 -I - "$B/api/backend/.env" <<'PY'
import re, sys
p = sys.argv[1]; s = open(p, encoding="utf-8").read()
for k, v in (("VOICE_OUTBOUND_ENABLED", "true"), ("TWILIO_VOICE_DAILY_CALL_CAP", "5"), ("VOICE_DAILY_BUDGET_USD", "5.00")):
    s, n = re.subn(r"^([ \t]*)" + k + r"=.*$", lambda m: m.group(1) + k + "=" + v, s, count=1, flags=re.M)
    assert n == 1, k
open(p, "w", encoding="utf-8").write(s)
PY
    ( cd "$B/api/backend" && env "${RED[@]}" php artisan config:cache -q && chmod 600 bootstrap/cache/config.php )
    echo "   palancas del H8 en el entorno sintético del banco: salientes encendidas, cupo 5 y 5.00 US$ al día" ;;
  crm-montar)
    # El CRM de la ola en el banco: un origen desnudo y un árbol clonados del CRM, con lo que está sin confirmar en un
    # commit `wip`, origin/main = ese commit; node_modules clonado (APFS); y el «servidor» del CRM: un directorio con un
    # build VIEJO sintético (index.html → main-VIEJO1.js, version.json = 9572426 y tres chunk-VIEJO*.js) que el rsync sin
    # --delete tiene que respetar.
    cargar_banco "$B"
    FRONT=${BANCO_CRM:-$(cd "$WT/../Iron-Body_Front" && pwd -P)}
    [ -d "$FRONT/node_modules" ] || muere "falta $FRONT/node_modules"
    rm -rf "$B/crm-git" "$B/crm-www" "$B/crm-bk"; mkdir -p "$B/crm-git" "$B/crm-www/app" "$B/crm-bk"
    G="git -c user.name=banco -c user.email=banco@local -c advice.detachedHead=false"
    git clone -q --bare "$(git -C "$FRONT" rev-parse --path-format=absolute --git-common-dir)" "$B/crm-git/origen.git"
    $G clone -q --no-checkout "$B/crm-git/origen.git" "$B/crm-git/wt"
    ( cd "$B/crm-git/wt"
      $G checkout -q -B main "$(git -C "$FRONT" rev-parse HEAD)"
      n=0
      while IFS= read -r line; do
        st=${line:0:2}; f=${line:3}
        case "$st" in " D"|"D "|"DD") continue ;; esac
        case "$f" in src/*|e2e/*|scripts/*|angular.json|package.json|package-lock.json|tsconfig*.json) ;; *) continue ;; esac
        mkdir -p "$(dirname "$f")"; cp -R "$FRONT/$f" "$f"; n=$((n + 1))
      done < <(git -C "$FRONT" status --porcelain --untracked-files=all)
      git add -A
      $G commit -q -m "wip(ultron-studio-h9): banco: el CRM de la ola sin confirmar ($n ficheros)"
      git push -q origin HEAD:main
      git fetch -q origin
      echo "   CRM de la ola: $(git rev-parse --short=7 HEAD) sobre $(git -C "$FRONT" rev-parse --short=7 HEAD) con $n ficheros sin confirmar" )
    cp -cR "$FRONT/node_modules" "$B/crm-git/wt/node_modules" 2> /dev/null || cp -R "$FRONT/node_modules" "$B/crm-git/wt/node_modules"
    ( cd "$B/crm-www/app"
      printf '<!doctype html><html><head><title>IronBody</title></head><body><script src="main-VIEJO1.js" type="module"></script></body></html>\n' > index.html
      printf '{\n  "commit": "9572426",\n  "dirty": false\n}\n' > version.json
      for f in main-VIEJO1.js chunk-VIEJO1.js chunk-VIEJO2.js chunk-VIEJO3.js; do printf '/* build viejo del banco: %s */\n' "$f" > "$f"; done )
    echo "   «servidor» del CRM: $B/crm-www/app con el build viejo sintético (5 ficheros)" ;;
  crm-servir)
    # Como nginx con try_files: un fichero que existe se sirve; una ruta de la SPA (sin extensión) devuelve index.html.
    PUERTO_CRM=${BANCO_PUERTO_CRM:-8525}
    ( nohup python3 -I -c '
import functools, http.server, os, sys
raiz, puerto = sys.argv[1], int(sys.argv[2])
class H(http.server.SimpleHTTPRequestHandler):
    def send_head(self):
        ruta = self.path.split("?", 1)[0]
        if not os.path.exists(self.translate_path(ruta)) and "." not in os.path.basename(ruta):
            self.path = "/index.html"
        return super().send_head()
    def log_message(self, *a):
        pass
http.server.ThreadingHTTPServer(("127.0.0.1", puerto), functools.partial(H, directory=raiz)).serve_forever()' "$B/crm-www/app" "$PUERTO_CRM" > "$B/logs/crm-www.out" 2>&1 & echo $! > "$B/run/crm-www.pid" )
    sleep 1; curl -fsS "http://127.0.0.1:$PUERTO_CRM/version.json" > /dev/null && echo "   CRM servido en :$PUERTO_CRM" ;;
  crm-parar) [ ! -f "$B/run/crm-www.pid" ] || { kill "$(cat "$B/run/crm-www.pid")" 2> /dev/null || true; rm -f "$B/run/crm-www.pid"; }; echo "   CRM parado" ;;
  crm-desplegar)
    cargar_banco "$B"
    ESP_CRM=$(git -C "$B/crm-git/wt" rev-parse --short=7 HEAD)
    exec env CRM_REMOTE=local CRM_WEB="http://127.0.0.1:${BANCO_PUERTO_CRM:-8525}" CRM_API="http://127.0.0.1:$BANCO_PORT/api" \
      CRM_DIR="$B/crm-www/app" CRM_BACKUPS="$B/crm-bk" bash "$OPS/deploy-h9-crm-cierre.sh" "$ESP_CRM" 9572426 "$B/crm-git/wt" "$@" ;;
  *) muere "uso: bash ola.sh <banco> montar | ola | respaldo | crm <commit> | pestanas viejas|nuevas | desplegar [opciones] | mando <orden…> | via-abierta | crm-montar | crm-servir | crm-parar | crm-desplegar [--solo-comprobar]" ;;
esac

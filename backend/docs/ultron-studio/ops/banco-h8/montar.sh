#!/bin/bash
# Monta el banco del Hito 8: una «producción» de juguete en cfcd0d1 (la de hoy: el H7 en producción de facto) con un
# `origin` local cuyo `main` es lo que se quiere desplegar (HEAD del worktree más, con BANCO_WIP=1, lo que aún no está
# confirmado del backend), PostgreSQL 14 desechable con el modelo de roles de producción (h5_owner = iron, dueño sin
# superusuario; h5_app = iron_app, solo DML por privilegios por defecto), el esquema de cfcd0d1 aplicado por el dueño, el
# fichero de entorno SINTÉTICO de la fase 1 (todo apagado y SIN NINGUNA PIEZA DE VOZ: `voice:doctor` tiene que decir
# ELEVENLABS_NOT_CONFIGURED), las PIEZAS SINTÉTICAS de los simuladores aparte (sim/piezas.conf, 600), el respaldo con
# el formato del runbook y los sustitutos de sudo, stat (GNU), supervisorctl (13 programas, 14 con el worker de voz
# «instalado»), systemctl, sha256sum y flock. Nada sale a la red: las órdenes de artisan del montaje van con un proxy a
# un puerto cerrado y, después, `ensayar.sh` lo hace todo por la valla.
# Uso:   bash montar.sh <directorio del banco, fuera del repo> [<ref de origen; HEAD por defecto>]
# Variables: BANCO_DB (ultron_studio_banco_h8_ops; se BORRA y se recrea; tiene que empezar por ultron_studio_banco_h8_),
#            BANCO_PORT (8401: la API), BANCO_PUERTO_EL (8402), BANCO_PUERTO_TW (8403), BANCO_PUERTO_VALLA (8404),
#            BANCO_PG_PORT (55432), BANCO_WIP (0 por defecto: con cinco agentes escribiendo a la vez, 1 metería su trabajo
#            a medias; con 1, el origen añade los cambios sin confirmar de backend/{app,config,routes,database}).
. "$(dirname "$0")/comun.sh"
B=$(banco_dir "${1:-}")
REF=${2:-HEAD}
DB=${BANCO_DB:-ultron_studio_banco_h8_ops}
PORT=${BANCO_PORT:-8401}
PEL=${BANCO_PUERTO_EL:-8402}
PTW=${BANCO_PUERTO_TW:-8403}
PVA=${BANCO_PUERTO_VALLA:-8404}
WIP=${BANCO_WIP:-0}
[[ "$DB" =~ ^ultron_studio_banco_h8_[a-z0-9_]{1,40}$ ]] || muere "BANCO_DB tiene que ser ultron_studio_banco_h8_<algo> (se borra y se recrea)"
for p in "$PORT" "$PEL" "$PTW" "$PVA"; do
  [[ "$p" =~ ^[0-9]+$ ]] && [ "$p" -ge 8400 ] && [ "$p" -le 65535 ] || muere "los puertos del banco van de 8400 a 65535 (dado: $p)"
  lsof -nP -iTCP:"$p" -sTCP:LISTEN > /dev/null 2>&1 && muere "el puerto $p ya está ocupado: no monto encima de un proceso ajeno"
done
[ "$(printf '%s\n' "$PORT" "$PEL" "$PTW" "$PVA" | sort -u | wc -l | tr -d ' ')" = 4 ] || muere "los cuatro puertos tienen que ser distintos"
[ ! -e "$B" ] || muere "ya existe $B: bash $AQUI/ensayar.sh $B parar && rm -rf $B"
for b in psql pg_dump pg_restore php openssl git python3 shasum node curl lsof; do command -v "$b" > /dev/null || muere "falta $b"; done
[ -d "$WT/backend/vendor" ] || muere "falta $WT/backend/vendor (composer install en el worktree)"
[ -f "$SUPERVISOR_VOZ" ] || muere "falta el borrador del worker de voz: $SUPERVISOR_VOZ"
psql_su -d postgres -Atc "SELECT 1" > /dev/null || muere "no hay PostgreSQL en $PG_HOST:$PG_PORT (ver pgunit.sh para levantarlo)"
COMUN=$(git -C "$WT" rev-parse --path-format=absolute --git-common-dir)
SHA=$(git -C "$WT" rev-parse --verify "$REF^{commit}")
G="git -c user.name=banco -c user.email=banco@local -c advice.detachedHead=false"
mkdir -p "$B"/{bin,root,logs,run,etc/ironbody-migrate,etc/supervisor/conf.d}

echo "== 1. origen: $REF ($(git -C "$WT" rev-parse --short=7 "$SHA"))"
$G clone -q --no-checkout "$COMUN" "$B/src"
cd "$B/src"
$G checkout -q -B banco "$SHA"
git merge-base --is-ancestor "$PREVIO_BANCO" HEAD || muere "la ref no contiene $PREVIO_BANCO (la producción de hoy)"
if [ "$WIP" = 1 ]; then
  N=0
  while IFS= read -r line; do
    st=${line:0:2}; f=${line:3}
    case "$st" in " D"|"D "|"DD") continue ;; esac
    case "$f" in backend/app/*|backend/config/*|backend/routes/*|backend/database/*|backend/bootstrap/app.php|backend/.env.example) ;; *) continue ;; esac
    mkdir -p "$(dirname "$f")"; cp -R "$WT/$f" "$f"; N=$((N + 1))
  done < <(git -C "$WT" status --porcelain --untracked-files=all)
  git add -A backend
  $G commit -q -m "banco: instantánea del trabajo en curso ($N ficheros)" || true
  echo "   + $N ficheros sin confirmar del worktree (BANCO_WIP=1)"
fi
ESPERADO=$(git rev-parse --short=7 HEAD)
$G branch -q -f main HEAD
echo "   ESPERADO=$ESPERADO · migraciones nuevas desde $PREVIO_BANCO: $(git diff --name-only --diff-filter=AMDR "$PREVIO_BANCO" HEAD -- backend/database/migrations | wc -l | tr -d ' ')"

echo "== 2. «producción» en $PREVIO_BANCO (origin = el origen del banco)"
$G clone -q "$B/src" "$B/api"
cd "$B/api"
$G checkout -q -B main "$PREVIO_BANCO"
cp -cR "$WT/backend/vendor" "$B/api/backend/vendor" 2> /dev/null || cp -R "$WT/backend/vendor" "$B/api/backend/vendor"   # en APFS, por clonación

echo "== 3. base $DB con el modelo de roles de producción"
psql_su -d postgres -Atc "SELECT 1 FROM pg_roles WHERE rolname = 'h5_owner'" | grep -q 1 || psql_su -d postgres -c "CREATE ROLE h5_owner LOGIN;"
psql_su -d postgres -Atc "SELECT 1 FROM pg_roles WHERE rolname = 'h5_app'" | grep -q 1 || psql_su -d postgres -c "CREATE ROLE h5_app LOGIN;"
psql_su -d postgres -Atc "SELECT 1 FROM pg_database WHERE datname = '$DB'" | grep -q 1 && psql_su -d postgres -c "DROP DATABASE $DB;"
psql_su -d postgres -c "CREATE DATABASE $DB;"
psql_su -d "$DB" -c "REVOKE CREATE ON SCHEMA public FROM PUBLIC;" -c "GRANT USAGE, CREATE ON SCHEMA public TO h5_owner;" \
  -c "GRANT USAGE ON SCHEMA public TO h5_app;" \
  -c "ALTER DEFAULT PRIVILEGES FOR ROLE h5_owner IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO h5_app;" \
  -c "ALTER DEFAULT PRIVILEGES FOR ROLE h5_owner IN SCHEMA public GRANT SELECT, UPDATE, USAGE ON SEQUENCES TO h5_app;"

echo "== 4. fichero de entorno SINTÉTICO de la fase 1 (sin piezas de voz; sangría de dos espacios como producción)"
hex() { openssl rand -hex "$1"; }
OTP_SID="AC$(hex 16)"; OTP_TOK=$(hex 16)
umask 077
cat > "$B/api/backend/.env" <<ENV
APP_NAME=IronBody
APP_ENV=local
APP_KEY=base64:$(openssl rand -base64 32)
APP_DEBUG=false
APP_URL=http://127.0.0.1:$PORT
LOG_CHANNEL=single
LOG_LEVEL=debug
DB_CONNECTION=pgsql
DB_HOST=$PG_HOST
DB_PORT=$PG_PORT
DB_DATABASE=$DB
DB_USERNAME=h5_app
DB_PASSWORD=
CACHE_STORE=database
QUEUE_CONNECTION=database
MAIL_MAILER=log
OTP_DRIVER=twilio
  TWILIO_SID=$OTP_SID
  TWILIO_ACCOUNT_SID=$OTP_SID
  TWILIO_TOKEN=$OTP_TOK
  TWILIO_AUTH_TOKEN=$OTP_TOK
  TWILIO_VERIFY_SERVICE_SID=VA$(hex 16)
  TWILIO_BASE=https://api.twilio.com
  TWILIO_VERIFY_BASE=https://verify.twilio.com
  TWILIO_FROM=
  META_ADS_ACCESS_TOKEN=EAA$(hex 40)
  META_AD_ACCOUNT_IDS=990000000001001,990000000002002,990000000003003
  META_ADS_SYNC_ENABLED=true
  META_ADS_GRAPH_VERSION=v26.0
  WHATSAPP_DISPLAY_PHONE=+573000000050
  MARKETING_ULTRON_ENABLED=true
  MARKETING_ULTRON_CANARY_CONVERSATION_ID=987654321
  ULTRON_STUDIO_LAB_ENABLED=true
  ULTRON_STUDIO_LAB_DRIVER=fake
  ULTRON_STUDIO_LAB_DAILY_BUDGET_USD=0
  MARKETING_ULTRON_STUDIO_CONFIG=off
  ULTRON_STUDIO_PUBLISHING_ENABLED=false
  ULTRON_STUDIO_PUBLISH_LAB_GATE=required
  ULTRON_STUDIO_CANARY_MIN_HOURS=24
  ULTRON_STUDIO_CANARY_MIN_TURNS=3
  ULTRON_STUDIO_PUBLISH_WINDOW=07:00-19:00
  QUEUE_STUDIO_LAB_UNATTENDED_AFTER_SECONDS=300
  ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED=false
  ULTRON_STUDIO_TWILIO_PROBE_ENABLED=false
  ULTRON_STUDIO_CANARY_MAX_ACTIVE=3
  ULTRON_STUDIO_CANARY_COUNTRY_CODES=57
  ULTRON_STUDIO_CAMPAIGN_LINKS_MAX_ACTIVE=50
  ULTRON_STUDIO_MONITOR_ENABLED=false
  ELEVENLABS_ENABLED=false
  TWILIO_VOICE_ENABLED=false
  TWILIO_VOICE_DAILY_CALL_CAP=0
  ELEVENLABS_BASE_URL=http://127.0.0.1:$PEL
ENV
# MAIL_MAILER=log: el correo no sale por un socket crudo (la valla solo ve HTTP). ELEVENLABS_BASE_URL: guarda del banco
# (en `production` se ignora): si alguien enciende la voz aquí, el GET va al simulador y no a la valla.
printf 'DB_HOST=%s\nDB_PORT=%s\nDB_DATABASE=%s\nDB_OWNER_USERNAME=h5_owner\nDB_OWNER_PASSWORD=banco-sin-uso\n' "$PG_HOST" "$PG_PORT" "$DB" > "$B/etc/ironbody-migrate/owner.env"
chmod 750 "$B/etc/ironbody-migrate"; chmod 600 "$B/etc/ironbody-migrate/owner.env"

echo "== 5. piezas SINTÉTICAS de los simuladores (sim/piezas.conf, 600): las de la fase 2, que la aplicación NO tiene"
mkdir -p "$B/sim"; chmod 700 "$B/sim"
cat > "$B/sim/piezas.conf" <<PZ
# Piezas SINTÉTICAS del banco del Hito 8 (aleatorias, de esta máquina). Las leen los simuladores (--config) y
# «ensayar.sh doctor» (en el entorno de UN proceso); nunca el fichero de entorno de la aplicación en la fase 1.
LARAVEL_BASE=http://127.0.0.1:$PORT
EL_BASE=http://127.0.0.1:$PEL
EL_TWILIO_BASE=http://127.0.0.1:$PTW
EL_API_KEY=xi_$(hex 24)
EL_WEBHOOK_SECRET=wsec_$(hex 24)
EL_TOOL_SECRET=tool_$(hex 24)
EL_INITIATION_SECRET=ini_$(hex 24)
EL_INITIATION_HEADER=X-Voice-Initiation-Secret
EL_VERIFY_AGENT_ID=agent_verificacion_$(hex 4)
EL_PHONE_NUMBER_ID=phnum_$(hex 8)
EL_MAX_RETENTION_DAYS=30
VOICE_NUMBER=+576010000090
WHATSAPP_NUMBER=+573000000050
TW_ACCOUNT_SID=AC$(hex 16)
TW_MASTER_SID=$OTP_SID
TW_MASTER_TOKEN=$OTP_TOK
TW_SIGNING_TOKEN=$(hex 16)
TW_LARAVEL_KEY_SID=SK$(hex 16)
TW_LARAVEL_KEY_SECRET=$(hex 16)
TW_EL_KEY_SID=SK$(hex 16)
TW_EL_KEY_SECRET=$(hex 16)
TW_VOICE_NUMBER_SID=PN$(hex 16)
TW_SIGN_BASE=https://api.ironbody-banco.invalid
SENTINEL_ELEVENLABS=banco-h8-centinela-el-$(hex 6)
SENTINEL_TWILIO=banco-h8-centinela-tw-$(hex 6)
PZ
chmod 600 "$B/sim/piezas.conf"
umask 022
unset OTP_SID OTP_TOK

echo "== 6. migraciones de $PREVIO_BANCO como h5_owner (esquema de hoy) y cachés; sin red (proxy a un puerto cerrado)"
cd "$B/api/backend"
SINRED=(PGGSSENCMODE=disable http_proxy=http://127.0.0.1:9 https_proxy=http://127.0.0.1:9 HTTP_PROXY=http://127.0.0.1:9 HTTPS_PROXY=http://127.0.0.1:9 no_proxy=127.0.0.1,localhost NO_PROXY=127.0.0.1,localhost)
env "${SINRED[@]}" DB_USERNAME=h5_owner php artisan migrate --force -q
env "${SINRED[@]}" php artisan config:cache -q && env "${SINRED[@]}" php artisan route:cache -q && chmod 600 bootstrap/cache/config.php bootstrap/cache/routes-v7.php
echo "   rutas del Studio: $(env "${SINRED[@]}" php artisan route:list --path=admin/ultron-studio --json | python3 -c 'import json, sys; print(len(json.load(sys.stdin)))') (56 = el H7 en producción)"
env "${SINRED[@]}" php artisan ultron-studio:integrations-snapshot -q
echo "   instantáneas de sistema: $(psql_su -d "$DB" -Atc "SELECT count(*) FROM ultron_studio_integration_snapshots WHERE actor_type = 'system' AND kind = 'config';") (3 = meta_ads, twilio y voice)"
echo "   voz: $(env "${SINRED[@]}" php artisan voice:doctor --json | python3 -c 'import json, sys; print(json.load(sys.stdin).get("state"))') (fase 1: ELEVENLABS_NOT_CONFIGURED)"

echo "== 7. sustitutos en $B/bin"
cat > "$B/bin/sudo" <<'S'
#!/bin/bash
# sudo del banco: ignora -u <usuario> y -H; «sudo env K=V cmd» se ejecuta tal cual.
while [ $# -gt 0 ]; do case "$1" in -u) shift 2 ;; -H) shift ;; *) break ;; esac; done
exec "$@"
S
cat > "$B/bin/stat" <<'S'
#!/bin/bash
# stat -c de GNU sobre el stat de BSD: %U %G %a %n %s %Y.
if [ "${1:-}" = -c ]; then f=$2; shift 2; f=${f//%U/%Su}; f=${f//%G/%Sg}; f=${f//%a/%Lp}; f=${f//%n/%N}; f=${f//%Y/%m}; f=${f//%s/%z}; exec /usr/bin/stat -f "$f" "$@"; fi
exec /usr/bin/stat "$@"
S
cat > "$B/bin/supervisorctl" <<'S'
#!/bin/bash
# supervisorctl del banco: los 13 programas de producción (docs/ops/supervisor/) y, si el worker de voz está
# «instalado» (ensayar.sh <banco> worker instalar), el 14.º: ironbody-voice. reread/update contestan como supervisor.
D=$(cd "$(dirname "$0")/.." && pwd)
case "${1:-}" in
  reread) [ -f "$D/etc/supervisor/conf.d/ironbody-voice.conf" ] && [ ! -f "$D/sup-voz-instalado" ] && echo "ironbody-voice: available"; [ ! -f "$D/etc/supervisor/conf.d/ironbody-voice.conf" ] && [ -f "$D/sup-voz-instalado" ] && echo "ironbody-voice: disappeared"; exit 0 ;;
  update)
    if [ -f "$D/etc/supervisor/conf.d/ironbody-voice.conf" ] && [ ! -f "$D/sup-voz-instalado" ]; then touch "$D/sup-voz-instalado"; echo "ironbody-voice: added process group"; fi
    if [ ! -f "$D/etc/supervisor/conf.d/ironbody-voice.conf" ] && [ -f "$D/sup-voz-instalado" ]; then rm -f "$D/sup-voz-instalado"; echo "ironbody-voice: stopped"; echo "ironbody-voice: removed process group"; fi
    exit 0 ;;
  status) ;;
  *) exit 0 ;;
esac
c=0; [ -f "$D/sup-calls" ] && c=$(cat "$D/sup-calls"); c=$((c + 1)); echo "$c" > "$D/sup-calls"
n=1000
linea() { n=$((n + 1)); printf '%-38s RUNNING   pid %s, uptime 0:10:00\n' "$1:$1_$2" "$n"; }
for i in 00 01 02 03; do linea ironbody-whatsapp "$i"; done
for i in 00 01 02 03; do linea ironbody-agent "$i"; done
for i in 00 01; do linea ironbody-media "$i"; done
for i in 00 01; do linea ironbody-commercial "$i"; done
linea ironbody-lab 00
[ -f "$D/sup-voz-instalado" ] && linea ironbody-voice 00
exit 0
S
cat > "$B/bin/systemctl" <<'S'
#!/bin/bash
[ "${1:-}" = is-active ] && { echo active; exit 0; }
exit 0
S
cat > "$B/bin/sha256sum" <<'S'
#!/bin/bash
exec shasum -a 256 "$@"
S
cat > "$B/bin/flock" <<'S'
#!/bin/bash
# flock del banco (macOS no lo trae): no bloquea nada. La exclusión de verdad se prueba en el servidor.
exit 0
S
chmod 755 "$B"/bin/*

echo "== 8. respaldo con el formato del runbook (700/600)"
(
  umask 077
  BK="$B/bk"; mkdir -p "$BK"
  git -C "$B/api" rev-parse HEAD > "$BK/api-commit.txt"
  pg_dump -Fc -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" "$DB" > "$BK/ironbody.dump"
  shasum -a 256 "$BK/ironbody.dump" > "$BK/ironbody.dump.sha256"
  install -m 600 "$B/api/backend/.env" "$BK/env.backup"
  chmod 700 "$BK"; chmod 600 "$BK"/*
)
echo "   tablas con datos en el volcado: $(pg_restore -l "$B/bk/ironbody.dump" | grep -c 'TABLE DATA')"
printf 'BANCO_DB=%s\nBANCO_PORT=%s\nPUERTO_EL=%s\nPUERTO_TW=%s\nPUERTO_VALLA=%s\nESPERADO=%s\n' "$DB" "$PORT" "$PEL" "$PTW" "$PVA" "$ESPERADO" > "$B/banco.env"
echo "BANCO LISTO: $B (ESPERADO=$ESPERADO). Siguiente: bash $AQUI/ensayar.sh $B arrancar && bash $AQUI/sembrar.sh $B"

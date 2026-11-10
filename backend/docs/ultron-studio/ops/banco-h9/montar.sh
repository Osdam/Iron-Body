#!/bin/bash
# Monta el banco del Hito 9: una «producción» de juguete con el H8 cerrado (HEAD del worktree más el cierre del H8
# que aún no está confirmado) y un `origin` local cuyo `main` es lo que se quiere desplegar (eso MÁS el trabajo del H9
# sin confirmar, separado por rutas como manda L9-34), PostgreSQL 14 desechable con el modelo de roles de producción
# (h5_owner = iron, dueño sin superusuario; h5_app = iron_app, solo DML por privilegios por defecto), el esquema de la
# «producción» aplicado por el dueño (el H8 con sus REVOKE), el fichero de entorno SINTÉTICO de la fase 1 del H8 (todo
# apagado, sin piezas de voz y SIN NINGUNA CLAVE DEL H9: las añade el despliegue), el respaldo con el formato del runbook
# y los sustitutos de sudo, stat (GNU), supervisorctl (13 programas), systemctl, sha256sum y flock. Nada sale a la red:
# las órdenes de artisan del montaje van con un proxy a un puerto cerrado y, después, `ensayar.sh` lo hace todo por la
# valla del H8 (banco-h8/valla.cjs). No hay simuladores de proveedores: la fase 1 del H9 no habla con nadie.
# Uso:   bash montar.sh <directorio del banco, fuera del repo> [<ref de origen; HEAD por defecto>]
# Variables: BANCO_DB (ultron_studio_banco_h9_ops; se BORRA y se recrea; tiene que empezar por ultron_studio_banco_h9_),
#            BANCO_PORT (8501: la API), BANCO_PUERTO_VALLA (8504), BANCO_PG_PORT (55432), BANCO_WIP (1 por defecto: el
#            H9 vive sin confirmar en el árbol compartido; con 0 solo se despliega lo confirmado), BANCO_PREVIO (un
#            commit en vez de HEAD + el cierre del H8).
. "$(dirname "$0")/comun.sh"
B=$(banco_dir "${1:-}")
REF=${2:-HEAD}
DB=${BANCO_DB:-ultron_studio_banco_h9_ops}
PORT=${BANCO_PORT:-8501}
PVA=${BANCO_PUERTO_VALLA:-8504}
WIP=${BANCO_WIP:-1}
[[ "$DB" =~ ^ultron_studio_banco_h9_[a-z0-9_]{1,40}$ ]] || muere "BANCO_DB tiene que ser ultron_studio_banco_h9_<algo> (se borra y se recrea)"
for p in "$PORT" "$PVA"; do
  [[ "$p" =~ ^[0-9]+$ ]] && [ "$p" -ge 8400 ] && [ "$p" -le 65535 ] || muere "los puertos del banco van de 8400 a 65535 (dado: $p)"
  lsof -nP -iTCP:"$p" -sTCP:LISTEN > /dev/null 2>&1 && muere "el puerto $p ya está ocupado: no monto encima de un proceso ajeno"
done
[ "$PORT" != "$PVA" ] || muere "los dos puertos tienen que ser distintos"
[ ! -e "$B" ] || muere "ya existe $B: bash $AQUI/ensayar.sh $B parar && rm -rf $B"
for b in psql pg_dump pg_restore php openssl git python3 shasum node curl lsof; do command -v "$b" > /dev/null || muere "falta $b"; done
[ -d "$WT/backend/vendor" ] || muere "falta $WT/backend/vendor (composer install en el worktree)"
[ -f "$VALLA_CJS" ] || muere "falta la valla del H8: $VALLA_CJS"
psql_su -d postgres -Atc "SELECT 1" > /dev/null || muere "no hay PostgreSQL en $PG_HOST:$PG_PORT (ver pgunit.sh para levantarlo)"
COMUN=$(git -C "$WT" rev-parse --path-format=absolute --git-common-dir)
SHA=$(git -C "$WT" rev-parse --verify "$REF^{commit}")
G="git -c user.name=banco -c user.email=banco@local -c advice.detachedHead=false"
mkdir -p "$B"/{bin,root,logs,run,etc/ironbody-migrate}

echo "== 1. origen: $REF ($(git -C "$WT" rev-parse --short=7 "$SHA"))"
$G clone -q --no-checkout "$COMUN" "$B/src"
cd "$B/src"
$G checkout -q -B banco "$SHA"
PREVIO_SHA=$SHA
if [ "$WIP" = 1 ]; then
  # Lo que no está confirmado en el worktree, en DOS commits (L9-34): primero el cierre del H8 (todo lo que no es del
  # H9: será la «producción» del banco si BANCO_PREVIO es HEAD), después el H9 (lo que es_h9 reconoce por su ruta).
  N8=0; N9=0
  while IFS= read -r line; do
    st=${line:0:2}; f=${line:3}
    case "$st" in " D"|"D "|"DD") continue ;; esac
    case "$f" in backend/app/*|backend/config/*|backend/routes/*|backend/database/*|backend/bootstrap/app.php|backend/.env.example) ;; *) continue ;; esac
    mkdir -p "$(dirname "$f")"; cp -R "$WT/$f" "$f"
    if es_h9 "$f"; then N9=$((N9 + 1)); else git add -- "$f"; N8=$((N8 + 1)); fi
  done < <(git -C "$WT" status --porcelain --untracked-files=all)
  if [ "$N8" -gt 0 ]; then
    $G commit -q -m "wip(ultron-studio-h8): banco: cierre del H8 sin confirmar ($N8 ficheros)"
    PREVIO_SHA=$(git rev-parse HEAD)
    echo "   + $N8 ficheros del cierre del H8 (commit $(git rev-parse --short=7 HEAD), la «producción» del banco)"
  fi
  if [ "$N9" -gt 0 ]; then
    git add -A backend
    # Con el prefijo del H9: el paso 0 del despliegue reconoce los commits del hito por su prefijo.
    $G commit -q -m "wip(ultron-studio-h9): banco: instantánea del trabajo del H9 sin confirmar ($N9 ficheros)"
    echo "   + $N9 ficheros del H9 (commit $(git rev-parse --short=7 HEAD))"
  fi
fi
if [ "$PREVIO_BANCO" != HEAD ]; then
  git merge-base --is-ancestor "$PREVIO_BANCO" HEAD || muere "la ref no contiene $PREVIO_BANCO (BANCO_PREVIO)"
  PREVIO_SHA=$(git rev-parse --verify "$PREVIO_BANCO^{commit}")
fi
PREVIO_CORTO=$(git rev-parse --short=7 "$PREVIO_SHA")
ESPERADO=$(git rev-parse --short=7 HEAD)
$G branch -q -f main HEAD
echo "   PREVIO=$PREVIO_CORTO · ESPERADO=$ESPERADO · migraciones nuevas entre los dos: $(git diff --name-only --diff-filter=AMDR "$PREVIO_SHA" HEAD -- backend/database/migrations | sed 's#.*/##' | tr '\n' ' ')"

echo "== 2. «producción» en $PREVIO_CORTO (origin = el origen del banco)"
$G clone -q "$B/src" "$B/api"
cd "$B/api"
$G checkout -q -B main "$PREVIO_SHA"
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

echo "== 4. fichero de entorno SINTÉTICO (la fase 1 del H8 cerrada; sin claves del H9; sangría de dos espacios como producción)"
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
  MARKETING_FOLLOWUPS_SCHEDULER=false
  COMMERCIAL_EVENTS_ENABLED=false
  COMMERCIAL_NBA_ENABLED=false
  COMMERCIAL_AUTONOMY_ENABLED=false
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
  VOICE_INBOUND_ENABLED=false
  VOICE_OUTBOUND_ENABLED=false
  VOICE_LAB_ENABLED=false
  VOICE_MONITOR_ENABLED=false
  ULTRON_STUDIO_VOICE_PUBLISHING_ENABLED=false
  VOICE_INBOUND_DAILY_CAP=0
  VOICE_DAILY_BUDGET_USD=0
  VOICE_LAB_DAILY_BUDGET_USD=0
  VOICE_LAB_DRIVER=fake
ENV
# MAIL_MAILER=log: el correo no sale por un socket crudo (la valla solo ve HTTP). Las 9 claves de la voz son las que
# dejó la fase 1 del H8 en producción (deploy-h8-backend-fase1.sh, paso 2).
printf 'DB_HOST=%s\nDB_PORT=%s\nDB_DATABASE=%s\nDB_OWNER_USERNAME=h5_owner\nDB_OWNER_PASSWORD=banco-sin-uso\n' "$PG_HOST" "$PG_PORT" "$DB" > "$B/etc/ironbody-migrate/owner.env"
chmod 750 "$B/etc/ironbody-migrate"; chmod 600 "$B/etc/ironbody-migrate/owner.env"
umask 022
unset OTP_SID OTP_TOK

echo "== 5. migraciones de $PREVIO_CORTO como h5_owner (el esquema del H8 con sus REVOKE) y cachés; sin red (proxy a un puerto cerrado)"
cd "$B/api/backend"
SINRED=(PGGSSENCMODE=disable http_proxy=http://127.0.0.1:9 https_proxy=http://127.0.0.1:9 HTTP_PROXY=http://127.0.0.1:9 HTTPS_PROXY=http://127.0.0.1:9 no_proxy=127.0.0.1,localhost NO_PROXY=127.0.0.1,localhost)
env "${SINRED[@]}" DB_USERNAME=h5_owner ULTRON_STUDIO_DB_APP_ROLE=h5_app php artisan migrate --force -q
env "${SINRED[@]}" php artisan config:cache -q && env "${SINRED[@]}" php artisan route:cache -q && chmod 600 bootstrap/cache/config.php bootstrap/cache/routes-v7.php
echo "   rutas del Studio: $(env "${SINRED[@]}" php artisan route:list --path=admin/ultron-studio --json | python3 -c 'import json, sys; print(len(json.load(sys.stdin)))') (77 = el H8 en producción)"
echo "   esquema: $(psql_su -d "$DB" -Atc "SELECT (SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'ultron\_studio\_%')||' tablas ultron_studio_* · '||(SELECT count(*) FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'outreach\_%')||' outreach_*'") (18 · 0 = antes del H9)"
env "${SINRED[@]}" php artisan ultron-studio:integrations-snapshot -q || true
echo "   voz: $(env "${SINRED[@]}" php artisan voice:doctor --json | python3 -c 'import json, sys; print(json.load(sys.stdin).get("state"))') (fase 1 del H8: ELEVENLABS_NOT_CONFIGURED)"

echo "== 6. sustitutos en $B/bin"
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
# supervisorctl del banco: los 13 programas de producción (docs/ops/supervisor/). La fase 1 del H9 no instala ninguno.
case "${1:-}" in status) ;; *) exit 0 ;; esac
n=1000
linea() { n=$((n + 1)); printf '%-38s RUNNING   pid %s, uptime 0:10:00\n' "$1:$1_$2" "$n"; }
for i in 00 01 02 03; do linea ironbody-whatsapp "$i"; done
for i in 00 01 02 03; do linea ironbody-agent "$i"; done
for i in 00 01; do linea ironbody-media "$i"; done
for i in 00 01; do linea ironbody-commercial "$i"; done
linea ironbody-lab 00
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

echo "== 7. respaldo con el formato del runbook (700/600)"
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
printf 'BANCO_DB=%s\nBANCO_PORT=%s\nPUERTO_VALLA=%s\nESPERADO=%s\nPREVIO_BANCO=%s\n' "$DB" "$PORT" "$PVA" "$ESPERADO" "$PREVIO_CORTO" > "$B/banco.env"
echo "BANCO LISTO: $B (PREVIO=$PREVIO_CORTO · ESPERADO=$ESPERADO). Siguiente: bash $AQUI/ensayar.sh $B arrancar && bash $AQUI/sembrar.sh $B && bash $AQUI/ensayar.sh $B todo"

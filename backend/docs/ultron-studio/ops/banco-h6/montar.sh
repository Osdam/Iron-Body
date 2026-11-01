#!/bin/bash
# Monta el banco del Hito 6: una «producción» de juguete en 7ba0ce8 (la fase 1 del H5) con un `origin` local cuyo
# `main` es el commit que se quiere desplegar, PostgreSQL 14 desechable con el modelo de roles de producción (dueño sin
# superusuario, aplicación con solo DML por privilegios por defecto), un fichero de entorno SINTÉTICO, el respaldo con
# el formato del runbook y sustitutos de sudo, stat (GNU), supervisorctl, systemctl, sha256sum e ironbody-migrate.
# Uso:   bash montar.sh <directorio del banco, fuera del repo> [<ref de origen; HEAD por defecto>]
# Variables: BANCO_DB (ultron_studio_banco_h6; se BORRA y se recrea), BANCO_PORT (8161), BANCO_PG_PORT (55432),
#            BANCO_WIP=1 (añade al origen los cambios sin confirmar del backend del worktree, como el primer ensayo).
# Si la ref no contiene 7ba0ce8, se fusiona en el banco el origin/main del repo (-X ours: solo choca documentación).
. "$(dirname "$0")/comun.sh"
B=$(banco_dir "${1:-}")
REF=${2:-HEAD}
DB=${BANCO_DB:-ultron_studio_banco_h6}
PORT=${BANCO_PORT:-8161}
WIP=${BANCO_WIP:-0}
case "$DB" in ultron_studio_banco_*) ;; *) muere "BANCO_DB tiene que empezar por ultron_studio_banco_ (se borra y se recrea)" ;; esac
[[ "$PORT" =~ ^[0-9]+$ ]] || muere "BANCO_PORT no es un número"
[ ! -e "$B" ] || muere "ya existe $B: bash $AQUI/api.sh $B stop && rm -rf $B"
for b in psql pg_dump pg_restore php openssl git python3 shasum; do command -v "$b" > /dev/null || muere "falta $b"; done
[ -d "$WT/backend/vendor" ] || muere "falta $WT/backend/vendor (composer install en el worktree)"
psql_su -d postgres -Atc "SELECT 1" > /dev/null || muere "no hay PostgreSQL en $PG_HOST:$PG_PORT (ver pgunit.sh para levantarlo)"
COMUN=$(git -C "$WT" rev-parse --path-format=absolute --git-common-dir)
SHA=$(git -C "$WT" rev-parse --verify "$REF^{commit}")
G="git -c user.name=banco -c user.email=banco@local -c advice.detachedHead=false"
mkdir -p "$B"/{bin,root,logs}

echo "== 1. origen: $REF ($(git -C "$WT" rev-parse --short=7 "$SHA"))"
$G clone -q --no-checkout "$COMUN" "$B/src"
cd "$B/src"
$G checkout -q -B banco "$SHA"
if ! git merge-base --is-ancestor 7ba0ce8 HEAD; then
  echo "   la ref no contiene 7ba0ce8: se fusiona el origin/main del repo, solo en el banco"
  $G fetch -q origin refs/remotes/origin/main:refs/remotes/upstream/main
  $G merge -q --no-edit -X ours upstream/main -m "banco: fusión de origin/main"
fi
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
echo "   ESPERADO=$ESPERADO · migraciones nuevas desde 7ba0ce8: $(git diff --name-only --diff-filter=AMDR 7ba0ce8 HEAD -- backend/database/migrations | wc -l | tr -d ' ')"

echo "== 2. «producción» en 7ba0ce8 (origin = el origen del banco)"
$G clone -q "$B/src" "$B/api"
cd "$B/api"
$G checkout -q -B main 7ba0ce8
cp -cR "$WT/backend/vendor" "$B/api/backend/vendor" 2> /dev/null || cp -R "$WT/backend/vendor" "$B/api/backend/vendor"   # en APFS, copia por clonación

echo "== 3. base $DB con el modelo de roles de producción (informe-db.md del H6, §7.4)"
psql_su -d postgres -Atc "SELECT 1 FROM pg_roles WHERE rolname = 'h5_owner'" | grep -q 1 || psql_su -d postgres -c "CREATE ROLE h5_owner LOGIN;"
psql_su -d postgres -Atc "SELECT 1 FROM pg_roles WHERE rolname = 'h5_app'" | grep -q 1 || psql_su -d postgres -c "CREATE ROLE h5_app LOGIN;"
psql_su -d postgres -Atc "SELECT 1 FROM pg_database WHERE datname = '$DB'" | grep -q 1 && psql_su -d postgres -c "DROP DATABASE $DB;"
psql_su -d postgres -c "CREATE DATABASE $DB;"
psql_su -d "$DB" -c "REVOKE CREATE ON SCHEMA public FROM PUBLIC;" -c "GRANT USAGE, CREATE ON SCHEMA public TO h5_owner;" \
  -c "GRANT USAGE ON SCHEMA public TO h5_app;" \
  -c "ALTER DEFAULT PRIVILEGES FOR ROLE h5_owner IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO h5_app;" \
  -c "ALTER DEFAULT PRIVILEGES FOR ROLE h5_owner IN SCHEMA public GRANT SELECT, UPDATE, USAGE ON SEQUENCES TO h5_app;"

echo "== 4. fichero de entorno SINTÉTICO (valores aleatorios de esta máquina; sangría de dos espacios como producción)"
SID="AC$(openssl rand -hex 16)"; TOK=$(openssl rand -hex 16)
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
OTP_DRIVER=twilio
  TWILIO_SID=$SID
  TWILIO_ACCOUNT_SID=$SID
  TWILIO_TOKEN=$TOK
  TWILIO_AUTH_TOKEN=$TOK
  TWILIO_VERIFY_SERVICE_SID=VA$(openssl rand -hex 16)
  TWILIO_BASE=https://api.twilio.com
  TWILIO_VERIFY_BASE=https://verify.twilio.com
  TWILIO_FROM=
  META_ADS_ACCESS_TOKEN=EAA$(openssl rand -hex 40)
  META_AD_ACCOUNT_IDS=990000000001001,990000000002002,990000000003003
  META_ADS_SYNC_ENABLED=true
  META_ADS_GRAPH_VERSION=v26.0
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
ENV
umask 022
unset SID TOK

echo "== 5. migraciones de 7ba0ce8 como h5_owner (esquema del H5) y cachés"
cd "$B/api/backend"
env DB_USERNAME=h5_owner php artisan migrate --force -q
php artisan config:cache -q && php artisan route:cache -q && chmod 600 bootstrap/cache/config.php bootstrap/cache/routes-v7.php
echo "   rutas del Studio: $(php artisan route:list --path=admin/ultron-studio --json | python3 -c 'import json, sys; print(len(json.load(sys.stdin)))') (38 = el H5 en su fase 1)"

echo "== 6. sustitutos en $B/bin"
cat > "$B/bin/sudo" <<'S'
#!/bin/bash
# sudo del banco: ignora -u <usuario> y -H; «sudo env K=V cmd» se ejecuta tal cual.
while [ $# -gt 0 ]; do case "$1" in -u) shift 2 ;; -H) shift ;; *) break ;; esac; done
exec "$@"
S
cat > "$B/bin/stat" <<'S'
#!/bin/bash
# stat -c de GNU sobre el stat de BSD: %U %G %a %n.
if [ "${1:-}" = -c ]; then f=$2; shift 2; f=${f//%U/%Su}; f=${f//%G/%Sg}; f=${f//%a/%Lp}; f=${f//%n/%N}; exec /usr/bin/stat -f "$f" "$@"; fi
exec /usr/bin/stat "$@"
S
cat > "$B/bin/supervisorctl" <<'S'
#!/bin/bash
# 13 programas RUNNING. Control (ficheros en el banco): sup-dip = N lecturas con uno arrancando; sup-backoff = uno en
# BACKOFF; sup-backoff-from = BACKOFF a partir de la lectura N (sup-calls cuenta las lecturas).
D=$(cd "$(dirname "$0")/.." && pwd)
[ "${1:-}" = status ] || exit 0
c=0; [ -f "$D/sup-calls" ] && c=$(cat "$D/sup-calls"); c=$((c + 1)); echo "$c" > "$D/sup-calls"
if [ -f "$D/sup-backoff-from" ] && [ "$c" -ge "$(cat "$D/sup-backoff-from")" ]; then touch "$D/sup-backoff"; fi
n=0; [ -f "$D/sup-dip" ] && n=$(cat "$D/sup-dip")
for i in $(seq -w 1 13); do
  if [ "$i" = 13 ] && [ -f "$D/sup-backoff" ]; then echo "ironbody-lab:ironbody-lab_00   BACKOFF   Exited too quickly"; continue; fi
  if [ "$i" = 13 ] && [ "$n" -gt 0 ]; then echo "ironbody-lab:ironbody-lab_00   STARTING"; echo $((n - 1)) > "$D/sup-dip"; continue; fi
  echo "ironbody-workers:worker_$i   RUNNING   pid 10$i, uptime 0:10:00"
done
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
cat > "$B/bin/ironbody-migrate" <<S
#!/bin/bash
# Como el envoltorio de producción: vacía la caché, migra con el rol DUEÑO y la regenera (aquí con umask 022: el peor
# caso, 644; el script de la fase 1 tiene que dejarla en 600). Conserva PGOPTIONS (lock_timeout). Con $B/lock-once,
# la primera vez abre un lector del libro con la transacción abierta 12 s: provoca el 55P03 de la 100002.
if [ -f "$B/lock-once" ]; then
  rm -f "$B/lock-once"
  (psql -X -q -h $PG_HOST -p $PG_PORT -U h5_app -d $DB -c "BEGIN; SELECT count(*) FROM ultron_studio_audit_events; SELECT pg_sleep(12); ROLLBACK;" > /dev/null 2>&1 &)
  sleep 1
fi
cd "$B/api/backend"
php artisan config:clear -q
env DB_USERNAME=h5_owner php artisan migrate --force; rc=\$?
php artisan config:cache -q
exit \$rc
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
echo "   tablas con datos en el volcado: $(pg_restore -l "$B/bk/ironbody.dump" | grep -c 'TABLE DATA') (el script exige ≥ 223)"
printf 'BANCO_DB=%s\nBANCO_PORT=%s\nESPERADO=%s\n' "$DB" "$PORT" "$ESPERADO" > "$B/banco.env"
echo "BANCO LISTO: $B (ESPERADO=$ESPERADO). Siguiente: bash $AQUI/preparar.sh $B && bash $AQUI/api.sh $B start"

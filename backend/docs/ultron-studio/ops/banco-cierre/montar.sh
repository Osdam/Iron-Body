#!/bin/bash
# Banco del cierre (deploy-cierre-lote.sh): una «producción» de juguete en el Mac para ensayar UN LOTE de punta a punta
# con artisan y PostgreSQL de verdad, sin producción y sin red. Nada vive dentro del repo.
#   <banco>/origen.git  desnudo y DESECHABLE, hace de GitHub: lee los objetos del repo por alternates (solo lectura; el
#                       repo no recibe ningún commit, ref ni fetch). Su main = la fusión de las ramas del lote.
#   <banco>/fusion      clon desechable donde se fusionan las ramas, con --no-ff, sobre <previo>.
#   <banco>/prod        hace de /var/www/api: clon de origen.git con HEAD en <previo>, vendor clonado (APFS) del árbol
#                       que se indique y un fichero de entorno SINTÉTICO (sin ninguna credencial real).
#   PostgreSQL 14 desechable (127.0.0.1:55432, pgunit.sh): base <ultron_studio_ops_…> con el modelo de roles de
#                       producción del banco del H9 (h5_owner = iron, dueño; h5_app = iron_app, solo DML), migrada hasta
#                       <previo> por el dueño (con los REVOKE: ULTRON_STUDIO_DB_APP_ROLE=h5_app).
# Uso: bash montar.sh <banco, fuera del repo> <previo> <base ultron_studio_ops_…> <rama> [<rama>…]
# Variables: BANCO_VENDOR (el árbol del que se clona backend/vendor; tiene que tener el mismo composer.lock que <previo>),
#            BANCO_PORT (8591: la API de artisan serve que usa ensayar.sh).
set -euo pipefail
export LC_ALL=C PGGSSENCMODE=disable
AQUI=$(cd "$(dirname "$0")" && pwd -P)
WT=$(cd "$AQUI/../../../../.." && pwd -P)
muere() { echo "banco: $*" >&2; exit 1; }
[ "$#" -ge 4 ] || muere "uso: bash montar.sh <banco> <previo> <base ultron_studio_ops_…> <rama> [<rama>…]"
B=$(python3 -I -c 'import os, sys; print(os.path.realpath(sys.argv[1]))' "$1"); PREVIO=$2; DB=$3; shift 3
case "$B/" in "$WT"/*) muere "el banco no puede vivir dentro del repo ($B): lleva un fichero de entorno" ;; esac
[ ! -e "$B" ] || muere "ya existe $B (bash $AQUI/ensayar.sh $B borrar)"
[[ "$DB" =~ ^ultron_studio_ops_[a-z0-9_]{1,40}$ ]] || muere "la base tiene que llamarse ultron_studio_ops_<algo> (se crea y se borra al terminar)"
PORT=${BANCO_PORT:-8591}
VENDOR=${BANCO_VENDOR:-$WT}
PG=(psql -X -q -v ON_ERROR_STOP=1 -h 127.0.0.1 -p 55432 -U ironbody)
for b in psql pg_dump php git python3 shasum openssl curl lsof; do command -v "$b" > /dev/null || muere "falta $b"; done
[ -d "$VENDOR/backend/vendor" ] || muere "falta $VENDOR/backend/vendor (BANCO_VENDOR)"
"${PG[@]}" -d postgres -Atc "SELECT 1" > /dev/null || muere "no hay PostgreSQL en 127.0.0.1:55432 (pgunit.sh)"
[ -z "$("${PG[@]}" -d postgres -Atc "SELECT 1 FROM pg_database WHERE datname = '$DB'")" ] || muere "la base $DB ya existe: no la piso"
for r in h5_owner h5_app; do
  [ "$("${PG[@]}" -d postgres -Atc "SELECT count(*) FROM pg_roles WHERE rolname = '$r'")" = 1 ] || muere "falta el rol $r del banco del H9 (este banco no crea roles del clúster)"
done
COMUN=$(git -C "$WT" rev-parse --path-format=absolute --git-common-dir)
P=$(git -C "$WT" rev-parse --verify "$PREVIO^{commit}")
G=(git -c user.name=banco -c user.email=banco@local -c advice.detachedHead=false)
mkdir -p "$B"/{bin,root,logs,run,backups,var-lib,etc-php}

echo "== 1. origen.git desechable (alternates al repo, solo lectura) y la fusión de: $*"
git init -q --bare "$B/origen.git"
echo "$COMUN/objects" > "$B/origen.git/objects/info/alternates"
git -C "$B/origen.git" update-ref refs/heads/main "$P"
for r in "$@"; do git -C "$B/origen.git" update-ref "refs/heads/$r" "$(git -C "$WT" rev-parse --verify "$r^{commit}")"; done
"${G[@]}" clone -q --shared --no-checkout "$B/origen.git" "$B/fusion"
( cd "$B/fusion" && "${G[@]}" checkout -q -B lote origin/main
  for r in "$@"; do "${G[@]}" merge -q --no-ff --no-edit -m "merge($r): banco del cierre" "origin/$r" || muere "la fusión de $r tiene conflictos: este banco no los resuelve"; done
  "${G[@]}" push -q origin lote:refs/heads/main )
N=$(git -C "$B/origen.git" rev-parse main)
echo "   previo $(git -C "$B/origen.git" rev-parse --short=8 "$P") → nuevo $(git -C "$B/origen.git" rev-parse --short=8 "$N") ($(git -C "$B/origen.git" rev-list --count "$P..$N") commits, $(git -C "$B/origen.git" diff --name-only --no-renames "$P" "$N" | wc -l | tr -d ' ') ficheros)"

echo "== 2. «producción» en el previo, con vendor"
"${G[@]}" clone -q --shared "$B/origen.git" "$B/prod"
( cd "$B/prod" && "${G[@]}" checkout -q -B main "$P" )
diff -q <(git -C "$B/prod" show "$P:backend/composer.lock") "$VENDOR/backend/composer.lock" > /dev/null || muere "el composer.lock de $VENDOR no es el del previo"
cp -cR "$VENDOR/backend/vendor" "$B/prod/backend/vendor" 2> /dev/null || cp -R "$VENDOR/backend/vendor" "$B/prod/backend/vendor"

echo "== 3. base $DB con el modelo de roles del banco del H9"
"${PG[@]}" -d postgres -c "CREATE DATABASE $DB;"
"${PG[@]}" -d "$DB" -c "REVOKE CREATE ON SCHEMA public FROM PUBLIC;" -c "GRANT USAGE, CREATE ON SCHEMA public TO h5_owner;" \
  -c "GRANT USAGE ON SCHEMA public TO h5_app;" \
  -c "ALTER DEFAULT PRIVILEGES FOR ROLE h5_owner IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO h5_app;" \
  -c "ALTER DEFAULT PRIVILEGES FOR ROLE h5_owner IN SCHEMA public GRANT SELECT, UPDATE, USAGE ON SEQUENCES TO h5_app;"

echo "== 4. fichero de entorno SINTÉTICO (sin credenciales reales) y el del dueño del banco"
( umask 077
  printf '%s\n' "APP_NAME=IronBody" "APP_ENV=local" "APP_KEY=base64:$(openssl rand -base64 32)" "APP_DEBUG=false" \
    "APP_URL=http://127.0.0.1:$PORT" "LOG_CHANNEL=single" "LOG_LEVEL=warning" "DB_CONNECTION=pgsql" "DB_HOST=127.0.0.1" \
    "DB_PORT=55432" "DB_DATABASE=$DB" "DB_USERNAME=h5_app" "DB_PASSWORD=" "CACHE_STORE=file" "SESSION_DRIVER=file" \
    "QUEUE_CONNECTION=database" "MAIL_MAILER=log" > "$B/prod/backend/.env"
  printf '%s\n' "DB_OWNER_USERNAME=h5_owner" "DB_OWNER_PASSWORD=banco-sin-uso" > "$B/root/.ironbody-owner.env" )
printf 'BANCO_DB=%s\nBANCO_PORT=%s\nPREVIO=%s\nNUEVO=%s\n' "$DB" "$PORT" "$P" "$N" > "$B/banco.env"

echo "== 5. migraciones del previo como h5_owner y cachés (sin red: proxy a un puerto cerrado)"
SINRED=(http_proxy=http://127.0.0.1:9 https_proxy=http://127.0.0.1:9 HTTP_PROXY=http://127.0.0.1:9 HTTPS_PROXY=http://127.0.0.1:9 no_proxy=127.0.0.1,localhost NO_PROXY=127.0.0.1,localhost)
cd "$B/prod/backend"
env "${SINRED[@]}" DB_USERNAME=h5_owner ULTRON_STUDIO_DB_APP_ROLE=h5_app php artisan migrate --force -q
( umask 077; env "${SINRED[@]}" php artisan config:cache -q && env "${SINRED[@]}" php artisan route:cache -q )
echo "   $("${PG[@]}" -d "$DB" -Atc "SELECT count(*) FROM migrations") migraciones registradas · cachés $(stat -f '%Su %Lp' bootstrap/cache/config.php) / $(stat -f '%Su %Lp' bootstrap/cache/routes-v7.php)"
echo "BANCO MONTADO: $B (previo $(git -C "$B/prod" rev-parse --short=8 HEAD), nuevo $(git -C "$B/origen.git" rev-parse --short=8 main), base $DB). Siguiente: bash $AQUI/ensayar.sh $B arrancar"

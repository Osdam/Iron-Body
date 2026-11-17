#!/usr/bin/env bash
# Dos reclasificaciones simultáneas del mismo cobro contra PostgreSQL (ver README.md).
#
# Uso: run.sh
#
# Variables:
#   RACE_APP   copia AISLADA del backend (con su vendor real y un .env de pruebas sin
#              secretos). Obligatoria: nunca el árbol de trabajo compartido.
#   RACE_DB    base PG desechable; debe empezar por subs_beb_ (por defecto subs_beb_race)
#   PG_PORT    puerto del PostgreSQL local (por defecto 55432)
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
: "${RACE_APP:?RACE_APP = copia aislada del backend}"
DB="${RACE_DB:-subs_beb_race}"
PHP="${PHP:-php}"
case "$DB" in subs_beb_*) ;; *) echo "base no permitida: $DB" >&2; exit 2;; esac

export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT="${PG_PORT:-55432}" DB_DATABASE="$DB" DB_USERNAME=ironbody DB_PASSWORD= DB_URL=
export APP_ENV=testing CACHE_STORE=array QUEUE_CONNECTION=sync BROADCAST_CONNECTION=null MAIL_MAILER=array SESSION_DRIVER=array
export FACTUS_ENABLED=false LOG_CHANNEL=single
# macOS: libpq con GSSAPI falla en procesos hijos; no hace falta para una base local.
export PGGSSENCMODE=disable

out="$(mktemp -d)/escenario.json"
(cd "$RACE_APP" && "$PHP" artisan migrate:fresh --force >/dev/null 2>&1) || { echo "migrate:fresh falló"; exit 2; }
"$PHP" "$HERE/setup.php" "$RACE_APP" "$out" >/dev/null || exit 2
"$PHP" "$HERE/race.php" "$RACE_APP" "$out" "$DB"

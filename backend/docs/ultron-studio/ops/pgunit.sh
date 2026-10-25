#!/usr/bin/env bash
# Puerta obligatoria del Studio: PHPUnit contra un PostgreSQL 14 desechable local (nunca la base de producción).
# Uso: docs/ultron-studio/ops/pgunit.sh <args de phpunit>     (desde cualquier directorio)
# Variables: UNIT_DB (por defecto ultron_studio_h4_unit; debe empezar por ultron_studio_), PG_PORT (55432).
# Clúster local: initdb -D ~/.ironbody-pg14-disposable -U ironbody --auth=trust
#   pg_ctl -D ~/.ironbody-pg14-disposable -o "-p 55432 -c unix_socket_directories='' -c listen_addresses=127.0.0.1" start
set -euo pipefail
cd "$(dirname "$0")/../../.."
DB="${UNIT_DB:-ultron_studio_h4_unit}"
case "$DB" in ultron_studio_*) ;; *) echo "base no permitida: $DB (debe empezar por ultron_studio_)" >&2; exit 2;; esac
exec env DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT="${PG_PORT:-55432}" DB_DATABASE="$DB" DB_USERNAME=ironbody DB_PASSWORD= DB_URL= \
  php -d memory_limit=2G vendor/bin/phpunit "$@"

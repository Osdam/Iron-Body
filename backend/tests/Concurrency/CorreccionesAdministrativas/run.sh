#!/usr/bin/env bash
# Carreras reales de las correcciones administrativas contra PostgreSQL (ver README.md).
#
# Uso: run.sh <escenario>     pago_doble_anulacion | venta_doble_cobro | venta_anular_vs_cobrar | venta_devoluciones_simultaneas | all
#
# Variables:
#   RACE_APP   backend a probar (por defecto, el de este árbol). Para demostrar el
#              fallo en una versión anterior, apúntalo a un worktree de esa versión
#              con su vendor y un .env con APP_KEY (sin secretos).
#   RACE_DB    base PG desechable; debe empezar por ultron_studio_ (por defecto ultron_studio_correcciones_race)
#   PG_PORT    puerto del PostgreSQL local (por defecto 55432)
set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
APP="${RACE_APP:-$(cd "$HERE/../../.." && pwd)}"
DB="${RACE_DB:-ultron_studio_correcciones_race}"
PHP="${PHP:-php}"
case "$DB" in ultron_studio_*) ;; *) echo "base no permitida: $DB" >&2; exit 2;; esac

export DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT="${PG_PORT:-55432}" DB_DATABASE="$DB" DB_USERNAME=ironbody DB_PASSWORD= DB_URL=
export APP_ENV=testing CACHE_STORE=array QUEUE_CONNECTION=sync BROADCAST_CONNECTION=null MAIL_MAILER=array SESSION_DRIVER=array
export FACTUS_ENABLED=false LOG_CHANNEL=single
# macOS: libpq con GSSAPI falla en procesos hijos; no hace falta para una base local.
export PGGSSENCMODE=disable

correr() {
  local sc="$1" out
  out="$(mktemp -d)/escenario.json"
  (cd "$APP" && "$PHP" artisan migrate:fresh --force >/dev/null 2>&1) || { echo "migrate:fresh falló"; return 2; }
  "$PHP" "$HERE/setup.php" "$APP" "$sc" "$out" >/dev/null || return 2
  "$PHP" "$HERE/race.php" "$APP" "$out" "$DB"
}

case "${1:-}" in
pago_doble_anulacion|venta_doble_cobro|venta_anular_vs_cobrar|venta_devoluciones_simultaneas) correr "$1" ;;
all)
  rc=0
  for sc in pago_doble_anulacion venta_doble_cobro venta_anular_vs_cobrar venta_devoluciones_simultaneas; do correr "$sc" || rc=$?; done
  exit $rc ;;
*) echo "uso: run.sh pago_doble_anulacion|venta_doble_cobro|venta_anular_vs_cobrar|venta_devoluciones_simultaneas|all" >&2; exit 2 ;;
esac

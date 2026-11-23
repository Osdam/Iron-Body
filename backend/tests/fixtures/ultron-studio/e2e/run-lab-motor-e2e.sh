#!/usr/bin/env bash
# T9 del apartado 5: el E2E del laboratorio frente al motor con el worker real.
# Crea una base desechable del Studio en el PostgreSQL local, la migra desde cero
# (lo hace la prueba) y lanza UltronStudioLabEngineE2ETest con ULTRON_STUDIO_LAB_E2E=1.
# Uso: tests/fixtures/ultron-studio/e2e/run-lab-motor-e2e.sh        (desde cualquier directorio)
# Variables: E2E_DB (ultron_studio_cierre_lab_e2e; debe empezar por ultron_studio_), PG_PORT (55432).
# Necesita node en el PATH y el puerto 18093 libre. Nada sale a la red: el simulador escucha en 127.0.0.1.
set -euo pipefail
cd "$(dirname "$0")/../../../.."
DB="${E2E_DB:-ultron_studio_cierre_lab_e2e}"
case "$DB" in ultron_studio_*) ;; *) echo "base no permitida: $DB" >&2; exit 2;; esac
createdb -h 127.0.0.1 -p "${PG_PORT:-55432}" -U ironbody "$DB" 2>/dev/null || true
exec perl -e 'alarm 600; exec @ARGV' env ULTRON_STUDIO_LAB_E2E=1 UNIT_DB="$DB" bash docs/ultron-studio/ops/pgunit.sh --no-logging tests/Feature/UltronStudio/E2E/UltronStudioLabEngineE2ETest.php

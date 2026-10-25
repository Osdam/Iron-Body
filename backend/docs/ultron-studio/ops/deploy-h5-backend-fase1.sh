#!/bin/bash
# Fase 1 del Hito 5 en producción: código desplegado con TODO apagado (el motor no lee la cadena y publicar sigue
# en 409). Se ejecuta EN EL SERVIDOR como root tras el respaldo. Orden: código → variables ANTES de ironbody-migrate
# (regenera la caché como www-data) → route:cache → queue:restart → comprobaciones SQL/rutas/config → sanidad.
# Nunca imprime valores del fichero de entorno.   Uso: bash /root/deploy-h5-backend-fase1.sh <commit esperado>
set -euo pipefail
export LC_ALL=C
ESPERADO="${1:?commit esperado en origin/main}"
cd /var/www/api
git fetch -q origin
MODIFICADOS=$(git status --porcelain | grep -v '^??' | wc -l | tr -d ' ')
[ "$MODIFICADOS" = "0" ] || { echo "el checkout de producción tiene cambios locales: no se despliega"; exit 1; }
git merge -q --ff-only origin/main
HEAD=$(git rev-parse --short HEAD)
[ "$HEAD" = "$ESPERADO" ] || { echo "HEAD $HEAD no es $ESPERADO"; exit 1; }
echo "1. código: HEAD=$HEAD"
cd backend
ENVF=.env
add_var() { if grep -Eq "^\s*$1=" "$ENVF"; then echo "   $1 ya existe (no se toca)"; else printf '  %s=%s\n' "$1" "$2" >> "$ENVF"; echo "   $1 añadida"; fi; }
echo "2. variables (todo apagado):"
add_var MARKETING_ULTRON_STUDIO_CONFIG off
add_var ULTRON_STUDIO_PUBLISHING_ENABLED false
add_var ULTRON_STUDIO_PUBLISH_LAB_GATE required
echo "   permisos del fichero: $(stat -c '%U %a' $ENVF)"
echo "3. migración (rol dueño, lock_timeout 5 s):"
sudo env PGOPTIONS="-c lock_timeout=5s" /usr/local/bin/ironbody-migrate 2>&1 | sed 's/^/   /'
sudo -u www-data php artisan route:cache -q
chmod 600 bootstrap/cache/routes-v7.php
echo "   cachés: $(stat -c '%U %a' bootstrap/cache/config.php) · $(stat -c '%U %a' bootstrap/cache/routes-v7.php)"
sudo -u www-data php artisan queue:restart -q
echo "4. SQL:"
sudo -u postgres psql -Atd ironbody -c "SELECT 'disparadores studio: '||count(*) FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid WHERE c.relname LIKE 'ultron_studio_%' AND NOT t.tgisinternal;" | sed 's/^/   /'
sudo -u postgres psql -Atd ironbody -c "SELECT 'CHECK studio: '||count(*) FROM pg_constraint WHERE contype = 'c' AND conrelid::regclass::text LIKE 'ultron_studio_%';" | sed 's/^/   /'
sudo -u postgres psql -Atd ironbody -c "SELECT 'cadena: '||count(*)||' filas · índices parciales: '||(SELECT count(*) FROM pg_indexes WHERE tablename='ultron_studio_versions' AND indexname IN ('usv_one_canary','usv_one_published')) FROM ultron_studio_publications;" | sed 's/^/   /'
echo "5. rutas studio: $(sudo -u www-data php artisan route:list --path=admin/ultron-studio 2>/dev/null | grep -c ultron-studio) · auth:coverage --unmapped → [$(sudo -u www-data php artisan auth:coverage --unmapped 2>&1 | tr -d '\n' | cut -c1-40)]"
echo "6. configuración efectiva:"
sudo -u www-data php artisan tinker --execute='echo json_encode(["engine_scope" => config("marketing.ultron.studio_config"), "publishing_enabled" => (bool) config("ultron_studio.publishing_enabled"), "lab_gate" => config("ultron_studio.publishing.lab_gate"), "lab_enabled" => (bool) config("ultron_studio.lab.enabled"), "lab_driver" => config("ultron_studio.lab.driver")]), PHP_EOL;' 2>&1 | tail -1 | sed 's/^/   /'
sleep 6
echo "7. workers RUNNING: $(supervisorctl status | grep -c RUNNING) · BACKOFF/FATAL: $(supervisorctl status | grep -c 'BACKOFF\|FATAL')"
echo "8. sanidad:"
sleep 3
for p in status lab/status publications; do echo "   $p sin token → $(curl -s -o /dev/null -w '%{http_code}' https://api.ironbodyneiva.cloud/api/admin/ultron-studio/$p)"; done
echo "   colas fallidas: $(sudo -u www-data php artisan queue:failed 2>/dev/null | grep -c '^| [0-9a-f]')"
echo "   filas de marketing_ai_actions con sello 'studio' (debe ser 0 con el motor en off): $(sudo -u postgres psql -Atd ironbody -c "SELECT count(*) FROM marketing_ai_actions WHERE metadata ? 'studio'")"
echo "FASE 1 (H5) COMPLETADA"

#!/bin/bash
# Fase 1 del Hito 4 en producción (laboratorio APAGADO). Se ejecuta EN EL SERVIDOR como root tras el respaldo.
# Orden del runbook (ULTRON_STUDIO_DEPLOYMENT_PLAN.md): código → variables → ironbody-migrate (regenera la caché de
# configuración como www-data) → route:cache → queue:restart → comprobaciones SQL/rutas/config → worker ironbody-lab →
# importación de escenarios → sanidad. Nunca imprime valores del fichero de entorno.
# Uso: bash /root/deploy-h4-backend-fase1.sh <commit esperado>
set -euo pipefail
export LC_ALL=C
ESPERADO="${1:?commit esperado en origin/main}"
cd /var/www/api
git fetch -q origin
git merge -q --ff-only origin/main
HEAD=$(git rev-parse --short HEAD)
[ "$HEAD" = "$ESPERADO" ] || { echo "HEAD $HEAD no es $ESPERADO"; exit 1; }
echo "1. código: HEAD=$HEAD modificados=$(git status --porcelain | grep -v '^??' | wc -l | tr -d ' ')"
cd backend
ENVF=.env
add_var() { # añade la clave con la sangría de dos espacios del fichero si no existe
  if grep -Eq "^\s*$1=" "$ENVF"; then echo "   $1 ya existe (no se toca)"; else printf '  %s=%s\n' "$1" "$2" >> "$ENVF"; echo "   $1 añadida"; fi
}
echo "2. variables del laboratorio:"
add_var ULTRON_STUDIO_LAB_ENABLED false
add_var ULTRON_STUDIO_LAB_DRIVER fake
add_var ULTRON_STUDIO_LAB_DAILY_BUDGET_USD 0
add_var QUEUE_STUDIO_LAB studio-lab
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
sudo -u postgres psql -Atd ironbody -c "SELECT 'tablas lab: '||count(*)||' · dueño: '||string_agg(DISTINCT tableowner, ',') FROM pg_tables WHERE tablename LIKE 'ultron_studio_lab_%';" | sed 's/^/   /'
echo "5. rutas studio: $(sudo -u www-data php artisan route:list --path=admin/ultron-studio 2>/dev/null | grep -c ultron-studio) · auth:coverage --unmapped → [$(sudo -u www-data php artisan auth:coverage --unmapped 2>&1 | tr -d '\n' | cut -c1-40)]"
echo "6. configuración efectiva (sin valores sensibles):"
sudo -u www-data php artisan tinker --execute='echo json_encode(["enabled" => config("ultron_studio.lab.enabled"), "driver" => config("ultron_studio.lab.driver"), "daily_budget" => config("ultron_studio.lab.daily_budget_usd") ?? config("ultron_studio.lab.budget.daily_usd"), "key_present" => (bool) (config("ultron_studio.lab.openai.api_key") ?? config("ultron_studio.lab.openai_api_key")), "queue" => config("queue.lanes.lab.queue")]), PHP_EOL;' 2>&1 | tail -1 | sed 's/^/   /'
echo "7. worker ironbody-lab:"
UP_BEFORE=$(supervisorctl status | grep -v ironbody-lab | awk '{print $NF}' | sort | head -1)
cp docs/ops/supervisor/ironbody-lab.conf /etc/supervisor/conf.d/ironbody-lab.conf
supervisorctl reread | sed 's/^/   /'; supervisorctl update | sed 's/^/   /'
sleep 6
echo "   RUNNING: $(supervisorctl status | grep -c RUNNING) · BACKOFF/FATAL: $(supervisorctl status | grep -c 'BACKOFF\|FATAL') · lab: $(supervisorctl status | grep ironbody-lab | awk '{print $2}' | tr '\n' ' ')"
echo "   tiempo de actividad mínimo de los otros workers: $UP_BEFORE (no deben haberse reiniciado salvo por queue:restart)"
echo "8. escenarios del catálogo:"
sudo -u www-data php artisan ultron-studio:lab-import-scenarios --dry-run 2>&1 | tail -1 | sed 's/^/   /'
sudo -u www-data php artisan ultron-studio:lab-import-scenarios 2>&1 | tail -1 | sed 's/^/   /'
echo "9. sanidad:"
sleep 3
for p in status lab/status; do echo "   $p sin token → $(curl -s -o /dev/null -w '%{http_code}' https://api.ironbodyneiva.cloud/api/admin/ultron-studio/$p)"; done
echo "   queue:health → $(sudo -u www-data php artisan queue:health 2>&1 | tail -3 | tr '\n' ' ' | cut -c1-160)"
echo "   colas fallidas: $(sudo -u www-data php artisan queue:failed 2>/dev/null | grep -c '^| [0-9a-f]')"
echo "   laravel.log ERROR últimos 5 min: $(find storage/logs -name 'laravel*.log' -mmin -5 -exec grep -c 'production.ERROR' {} + 2>/dev/null | awk -F: '{s+=$NF} END {print s+0}')"
echo "FASE 1 COMPLETADA"

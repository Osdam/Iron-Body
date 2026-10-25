#!/bin/bash
# Fase 2 del Hito 4 en producción: laboratorio ENCENDIDO en modo de ensayo sin IA (driver fake, presupuesto 0).
# Reversible con el mismo script: `bash deploy-h4-fase2.sh off`. Se ejecuta EN EL SERVIDOR como root.
# Nunca imprime valores del fichero de entorno. No toca la clave de OpenAI (la fase 3 es del propietario).
set -euo pipefail
export LC_ALL=C
MODO="${1:-on}"
cd /var/www/api/backend
ENVF=.env
case "$MODO" in
  on)  VAL=true ;;
  off) VAL=false ;;
  *) echo "uso: $0 on|off"; exit 2 ;;
esac
grep -Eq "^\s*ULTRON_STUDIO_LAB_ENABLED=" "$ENVF" || { echo "falta ULTRON_STUDIO_LAB_ENABLED: ejecuta antes la fase 1"; exit 1; }
grep -Eq "^\s*ULTRON_STUDIO_LAB_DRIVER=fake" "$ENVF" || { echo "el driver no es fake: la fase 2 solo enciende el ensayo sin IA"; exit 1; }
grep -Eq "^\s*ULTRON_STUDIO_LAB_DAILY_BUDGET_USD=0$" "$ENVF" || { echo "el presupuesto diario no es 0"; exit 1; }
sed -i -E "s/^(\s*ULTRON_STUDIO_LAB_ENABLED=).*/\1$VAL/" "$ENVF"
echo "1. bandera → $VAL (driver fake, presupuesto 0; permisos $(stat -c '%U %a' $ENVF))"
sudo -u www-data php artisan config:cache -q
chmod 600 bootstrap/cache/config.php
echo "2. caché: $(stat -c '%U %a' bootstrap/cache/config.php)"
sudo -u www-data php artisan tinker --execute='echo json_encode(["enabled" => config("ultron_studio.lab.enabled"), "driver" => config("ultron_studio.lab.driver"), "daily_budget" => config("ultron_studio.lab.daily_budget_usd"), "key_present" => (bool) config("ultron_studio.lab.openai.api_key")]), PHP_EOL;' 2>&1 | tail -1 | sed 's/^/   /'
supervisorctl restart 'ironbody-lab:*' | sed 's/^/   /'
sudo -u www-data php artisan queue:restart -q
sleep 6
echo "3. workers RUNNING: $(supervisorctl status | grep -c RUNNING) · BACKOFF/FATAL: $(supervisorctl status | grep -c 'BACKOFF\|FATAL') · lab: $(supervisorctl status | grep ironbody-lab | awk '{print $2}')"
echo "4. lab/status sin token → $(curl -s -o /dev/null -w '%{http_code}' https://api.ironbodyneiva.cloud/api/admin/ultron-studio/lab/status)"
echo "FASE 2 ($MODO) COMPLETADA"

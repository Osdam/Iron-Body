#!/bin/bash
# Smoke del Hito 4 en producción con la cuenta temporal (admin id 32, Super Admin, INACTIVA entre usos):
# se reactiva con una contraseña nueva, se usa y se vuelve a desactivar. Sin salidas de secretos.
# Se ejecuta EN EL SERVIDOR: copiar smoke-h4.py y este fichero a /root/ y lanzar
#   SMOKE_LAB_EXPECT_ENABLED=0 bash /root/smoke-run-h4.sh     (fase 1, apagado)
#   SMOKE_LAB_EXPECT_ENABLED=1 bash /root/smoke-run-h4.sh     (fase 2, fake)
set -euo pipefail
export LC_ALL=C
cd /var/www/api/backend
export SMOKE_EMAIL=studio-smoke@ironbodyneiva.cloud
export SMOKE_PW=$(openssl rand -base64 30 | tr -d "/+=" | cut -c1-28)
sudo -u www-data env SMOKE_EMAIL="$SMOKE_EMAIL" SMOKE_PW="$SMOKE_PW" php artisan tinker --execute='$a = App\Models\Admin::query()->findOrFail(32); $a->forceFill(["name" => "Studio smoke (temporal)", "email" => getenv("SMOKE_EMAIL"), "password" => getenv("SMOKE_PW"), "role" => App\Models\Admin::ROLE_SUPER_ADMIN, "status" => "active"])->save(); echo "admin ", $a->id, " ", $a->status, " hash_ok=", (Hash::check(getenv("SMOKE_PW"), $a->fresh()->password) ? "sí" : "NO"), PHP_EOL;' 2>&1 | tail -1
set +e
SMOKE_LAB_EXPECT_ENABLED="${SMOKE_LAB_EXPECT_ENABLED:-0}" python3 /root/smoke-h4.py "$SMOKE_EMAIL" "$SMOKE_PW" > /root/smoke-out.txt 2>&1
RC=$?
set -e
grep -v "^TOKEN=" /root/smoke-out.txt
TOKEN=$(grep "^TOKEN=" /root/smoke-out.txt | cut -d= -f2-)
sudo -u www-data php artisan tinker --execute='$a = App\Models\Admin::query()->findOrFail(32); $a->forceFill(["status" => "inactive"])->save(); echo "estado=", $a->fresh()->status, PHP_EOL;' 2>&1 | tail -1
echo "token de la cuenta desactivada → $(curl -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $TOKEN" https://api.ironbodyneiva.cloud/api/admin/ultron-studio/status) (esperado 403)"
rm -f /root/smoke-out.txt /root/smoke-h4.py /root/smoke-run-h4.sh
exit $RC

#!/bin/bash
# Smoke del Hito 4 en producción con la cuenta temporal (admin id 32, Super Admin, INACTIVA entre usos).
# Se ejecuta EN EL SERVIDOR como root tras copiar smoke-h4.py y este fichero a /root/:
#   SMOKE_LAB_EXPECT_ENABLED=0 bash /root/smoke-run-h4.sh     (fase 1, apagado)
#   SMOKE_LAB_EXPECT_ENABLED=1 bash /root/smoke-run-h4.sh     (fase 2, fake)
# Seguridad: la contraseña nunca va en argumentos (solo por entorno a python y como HASH a tinker);
# el token va en un fichero de cabecera 0600; un trap desactiva la cuenta, borra sus sesiones y los temporales
# aunque el smoke falle.
set -euo pipefail
export LC_ALL=C
cd /var/www/api/backend
export SMOKE_EMAIL=studio-smoke@ironbodyneiva.cloud
export SMOKE_PW=$(openssl rand -base64 30 | tr -d "/+=" | cut -c1-28)
HASH=$(php -r 'echo password_hash(getenv("SMOKE_PW"), PASSWORD_BCRYPT);')
HDR=/root/.smoke-h4-hdr; OUT=/root/smoke-out.txt
umask 077
cleanup() {
  set +e
  sudo -u www-data php artisan tinker --execute='$a = App\Models\Admin::query()->findOrFail(32); $a->forceFill(["status" => "inactive"])->save(); $n = DB::table("admin_sessions")->where("admin_id", 32)->delete(); echo "estado=", $a->fresh()->status, " sesiones_borradas=", $n, PHP_EOL;' 2>&1 | tail -1
  if [ -s "$HDR" ]; then echo "token tras desactivar → $(curl -s -o /dev/null -w '%{http_code}' -H @"$HDR" https://api.ironbodyneiva.cloud/api/admin/ultron-studio/status) (esperado 401/403)"; fi
  rm -f "$OUT" "$HDR" /root/smoke-h4.py /root/smoke-run-h4.sh
}
trap cleanup EXIT
sudo -u www-data php artisan tinker --execute="\$a = App\Models\Admin::query()->findOrFail(32); \$a->forceFill(['name' => 'Studio smoke (temporal)', 'email' => '$SMOKE_EMAIL', 'password' => '$HASH', 'role' => App\Models\Admin::ROLE_SUPER_ADMIN, 'status' => 'active'])->save(); echo 'admin ', \$a->id, ' ', \$a->status, PHP_EOL;" 2>&1 | tail -1
set +e
SMOKE_LAB_EXPECT_ENABLED="${SMOKE_LAB_EXPECT_ENABLED:-0}" python3 /root/smoke-h4.py "$SMOKE_EMAIL" > "$OUT" 2>&1
RC=$?
set -e
grep -v "^TOKEN=" "$OUT"
TOKEN=$(grep "^TOKEN=" "$OUT" | cut -d= -f2- || true)
[ -n "$TOKEN" ] && printf 'Authorization: Bearer %s\n' "$TOKEN" > "$HDR"
exit $RC

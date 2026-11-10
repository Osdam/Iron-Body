#!/bin/bash
# La cuenta del smoke (Super Admin, INACTIVA entre usos, como la 32 de producción): su id queda en banco.env (ADMIN_ID).
# Con --personas=N, además, N socios SINTÉTICOS por la fábrica de la aplicación (UserFactory: nombres y correos
# inventados; teléfonos +57 300 00 0xxxx, la convención sintética de TEST8-a, que ningún operador asigna): ni una persona
# real, ni un número real. Son el material del barrido en sombra del H9 (fase 2 del banco): sin personas, el barrido no
# inscribe a nadie. Una vez por banco (la cuenta); las personas se pueden añadir después. Imprime solo recuentos.
# Uso: bash sembrar.sh <banco> [--personas=N]
. "$(dirname "$0")/comun.sh"
B=$(banco_dir "${1:-}")
cargar_banco "$B"
PERSONAS=0
case "${2:-}" in --personas=*) PERSONAS=${2#*=}; [[ "$PERSONAS" =~ ^[0-9]{1,4}$ ]] || muere "--personas=N (hasta 9999)" ;; "") ;; *) muere "opción desconocida: ${2:-} (solo --personas=N)" ;; esac
if [ -z "${ADMIN_ID:-}" ]; then
  ADM=$(psql -X -Atq -v ON_ERROR_STOP=1 -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$BANCO_DB" -c "INSERT INTO admins (uuid, name, email, password, role, status, created_at, updated_at) VALUES (gen_random_uuid(), 'Studio smoke', 'studio-smoke@ironbodyneiva.cloud', '!', 'Super Admin', 'inactive', now(), now()) RETURNING id;" | head -1)
  printf 'ADMIN_ID=%s\n' "$ADM" >> "$B/banco.env"
  echo "cuenta de smoke: id $ADM (Super Admin, inactiva)"
else
  echo "cuenta de smoke: id $ADMIN_ID (ya sembrada)"
fi
[ "$PERSONAS" -gt 0 ] || exit 0
read -r -d '' PHP_PERSONAS <<'PHP' || true
declare(strict_types=1);
[$_, $dir, $n] = $argv;
require $dir.'/vendor/autoload.php';
$app = require $dir.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$desde = (int) Illuminate\Support\Facades\DB::table('users')->where('email', 'like', 'sintetica-%@banco.invalid')->count();
$hechas = 0;
for ($i = $desde + 1; $i <= $desde + (int) $n; $i++) {
    $u = App\Models\User::factory()->create(['name' => "Persona sintética {$i}", 'email' => "sintetica-{$i}@banco.invalid"]);
    $u->forceFill(['phone' => sprintf('+5730000%05d', $i)])->save();
    $hechas++;
}
echo "personas sintéticas: {$hechas} nuevas (total ".($desde + $hechas).", teléfonos +57 300 00 0xxxx, correos @banco.invalid)\n";
PHP
(cd "$B/api/backend" && env "${RED[@]}" DB_USERNAME=h5_owner php -r "$PHP_PERSONAS" "$B/api/backend" "$PERSONAS")
echo "   en la base: $(psql_su -d "$BANCO_DB" -Atc "SELECT count(*) FROM users WHERE email LIKE 'sintetica-%@banco.invalid';") socios sintéticos"

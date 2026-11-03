#!/bin/bash
# La cuenta del smoke (Super Admin, INACTIVA entre usos, como la 32 de producción): su id queda en banco.env (ADMIN_ID).
# Con --canarios-voz, además, dos canarios de voz ACTIVOS (+57 300 000 0011 y 0012: la convención sintética de TEST8-a) por el mismo
# camino que el registro del H6 (el modelo con el cast `encrypted` y la huella de PhoneFingerprint::of), con una segunda
# identidad que autoriza y activa, pero sin la API de los cuatro ojos: es el estado de la fase 3 (el «T3 de voz») para
# ensayar la puerta de entrantes. Una vez por banco. Imprime solo recuentos (nunca un teléfono completo).
# Uso: bash sembrar.sh <banco> [--canarios-voz]
. "$(dirname "$0")/comun.sh"
B=$(banco_dir "${1:-}")
cargar_banco "$B"
CANARIOS=0
case "${2:-}" in --canarios-voz) CANARIOS=1 ;; "") ;; *) muere "opción desconocida: ${2:-} (solo --canarios-voz)" ;; esac
[ -z "${ADMIN_ID:-}" ] || muere "este banco ya está sembrado (ADMIN_ID=$ADMIN_ID)"
ADM=$(psql -X -Atq -v ON_ERROR_STOP=1 -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$BANCO_DB" -c "INSERT INTO admins (uuid, name, email, password, role, status, created_at, updated_at) VALUES (gen_random_uuid(), 'Studio smoke', 'studio-smoke@ironbodyneiva.cloud', '!', 'Super Admin', 'inactive', now(), now()) RETURNING id;" | head -1)
printf 'ADMIN_ID=%s\n' "$ADM" >> "$B/banco.env"
echo "cuenta de smoke: id $ADM (Super Admin, inactiva)"
[ "$CANARIOS" = 1 ] || exit 0
read -r -d '' PHP_CANARIOS <<'PHP' || true
declare(strict_types=1);
[$_, $dir, $staff] = $argv;
require $dir.'/vendor/autoload.php';
$app = require $dir.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$staff = (int) $staff;
$n = 0;
foreach (['+573000000011' => 'Canario de voz 1 (banco)', '+573000000012' => 'Canario de voz 2 (banco)'] as $e164 => $alias) {
    App\Models\UltronStudio\StudioCanaryNumber::query()->forceCreate([
        'client_request_id' => (string) Illuminate\Support\Str::uuid(), 'channel' => 'voice', 'alias' => $alias,
        'phone_hash' => App\Support\Privacy\PhoneFingerprint::of($e164), 'phone_last4' => substr($e164, -4), 'country_code' => '57',
        'phone_encrypted' => $e164, 'status' => 'active', 'expected_version_id' => null, 'consent_attested' => true,
        'registered_by_admin_id' => $staff, 'registered_by_name' => 'Ana Operadora', 'registered_by_role' => 'Super Admin',
        'authorized_by_admin_id' => $staff + 1000, 'authorized_by_name' => 'Otra Persona', 'authorized_by_role' => 'Super Admin', 'authorized_at' => now(),
        'activated_by_admin_id' => $staff + 1000, 'activated_by_name' => 'Otra Persona', 'activated_by_role' => 'Super Admin', 'activated_at' => now(),
        'status_changed_at' => now(),
    ]);
    $n++;
}
echo "canarios de voz: {$n} activos (últimos 4: 0011 y 0012)\n";
PHP
(cd "$B/api/backend" && env "${RED[@]}" php -r "$PHP_CANARIOS" "$B/api/backend" "$ADM")
echo "   en la base: $(psql_su -d "$BANCO_DB" -Atc "SELECT count(*) FROM ultron_studio_canary_numbers WHERE channel = 'voice' AND status = 'active' AND phone_encrypted IS NOT NULL;") canarios de voz activos con el teléfono cifrado"

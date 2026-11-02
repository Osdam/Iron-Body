#!/bin/bash
# La cuenta del smoke (Super Admin, INACTIVA entre usos, como la 32 de producción): su id queda en banco.env (ADMIN_ID).
# Una vez por banco. Uso: bash sembrar.sh <banco>
. "$(dirname "$0")/comun.sh"
B=$(banco_dir "${1:-}")
cargar_banco "$B"
[ -z "${ADMIN_ID:-}" ] || muere "este banco ya está sembrado (ADMIN_ID=$ADMIN_ID)"
ADM=$(psql -X -Atq -v ON_ERROR_STOP=1 -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$BANCO_DB" -c "INSERT INTO admins (uuid, name, email, password, role, status, created_at, updated_at) VALUES (gen_random_uuid(), 'Studio smoke', 'studio-smoke@ironbodyneiva.cloud', '!', 'Super Admin', 'inactive', now(), now()) RETURNING id;" | head -1)
printf 'ADMIN_ID=%s\n' "$ADM" >> "$B/banco.env"
echo "cuenta de smoke: id $ADM (Super Admin, inactiva)"

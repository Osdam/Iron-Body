#!/bin/bash
# Datos SINTÉTICOS para que el banco se parezca a la clasificación de producción al congelar (Meta connected_read,
# Twilio connected_otp_only, canarios engine_canary_not_registered) y la cuenta del smoke. Se ejecuta UNA vez por banco.
#   · el lead y la conversación canaria 987654321 (wa_id 573009990001); el teléfono lo pone la semilla del tester
#     (`h6-seed.php canary-lead`) por el mismo camino que la ingesta;
#   · el espejo de Meta de la semilla del tester (`meta-ads`: 12 campañas, la cuenta 3 vieja) y una pasada que refresca
#     la cuenta 3 (sin ella, Meta sale degraded/stale, que también es un caso válido);
#   · la cuenta de smoke inactiva (Super Admin): su id queda en banco.env (ADMIN_ID).
# Uso: bash sembrar.sh <banco>    · BANCO_SEED = ruta de h6-seed.php (por defecto, la del worktree del CRM hermano)
. "$(dirname "$0")/comun.sh"
B=$(banco_dir "${1:-}")
cargar_banco "$B"
SEED=${BANCO_SEED:-$(cd "$WT/.." && pwd -P)/Iron-Body_Front/e2e/ultron-studio/specs/h6-seed.php}
[ -f "$SEED" ] || muere "no encuentro h6-seed.php en $SEED (BANCO_SEED=…)"
[ -z "${ADMIN_ID:-}" ] || muere "este banco ya está sembrado (ADMIN_ID=$ADMIN_ID)"
q() { psql -X -Atq -v ON_ERROR_STOP=1 -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$BANCO_DB" -c "$1"; }
L=$(q "INSERT INTO marketing_leads (channel, meta_user_id, created_at, updated_at) VALUES ('whatsapp', '573009990001', now(), now()) RETURNING id;" | head -1)
q "INSERT INTO marketing_conversations (id, lead_id, channel, created_at, updated_at) VALUES (987654321, $L, 'whatsapp', now(), now());" > /dev/null
cd "$B/api/backend"
echo "canario: $(php -d memory_limit=1G "$SEED" "$B/api/backend" canary-lead 573009990001 | cut -c1-160)"
php -d memory_limit=1G "$SEED" "$B/api/backend" meta-ads 990000000001001,990000000002002,990000000003003 > "$B/logs/seed-meta.json"
echo "meta-ads: $(wc -c < "$B/logs/seed-meta.json" | tr -d ' ') bytes de oráculo en $B/logs/seed-meta.json"
echo "refresco de la cuenta 3: $(php -d memory_limit=1G "$SEED" "$B/api/backend" sync-run 990000000003003 ok schedule 1 --touch | cut -c1-120)"
ADM=$(q "INSERT INTO admins (uuid, name, email, password, role, status, created_at, updated_at) VALUES (gen_random_uuid(), 'Studio smoke', 'studio-smoke-banco@local', '!', 'Super Admin', 'inactive', now(), now()) RETURNING id;" | head -1)
printf 'ADMIN_ID=%s\n' "$ADM" >> "$B/banco.env"
echo "cuenta de smoke: id $ADM (Super Admin, inactiva)"

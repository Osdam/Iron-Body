#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# dedupe-env-keys.sh — deja UNA sola definición por clave en el .env productivo.
#
# Por qué hace falta, y por qué antes de rotar nada:
#
#   · El .env productivo define tres claves DOS veces: ADMIN_API_TOKEN,
#     MAIL_PASSWORD y MEMBER_REGISTRATION_TOKEN. La primera aparición de cada
#     una es la plantilla de Laravel (`null`); la segunda, el valor real.
#   · Comprobado en este servidor con el repositorio de entorno que usa Laravel:
#     ante duplicados GANA LA ÚLTIMA. O sea que hoy funciona por accidente de
#     orden, no por diseño.
#   · `ironbody-rotate-secret` se niega —con razón— a rotar una clave duplicada.
#     Con MAIL_PASSWORD duplicada, la rotación de SMTP no puede ni empezar.
#
# Qué hace: borra las apariciones ANTERIORES y conserva la ÚLTIMA byte a byte.
# No reescribe el valor, no lo lee, no lo cita de otra forma: sólo elimina
# líneas muertas. Por construcción la configuración efectiva no cambia, y el
# script lo COMPRUEBA comparando huellas antes/después. Si alguna cambia,
# restaura la copia y aborta.
#
# Uso:  dedupe-env-keys.sh CLAVE [CLAVE …]
# ---------------------------------------------------------------------------
set -Eeuo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib-env.sh
source "$HERE/lib-env.sh"

require_root
[ $# -gt 0 ] || { fail "uso: $(basename "$0") CLAVE [CLAVE …]"; exit 1; }

# Huella de la configuración efectiva, para probar que el dedupe es inocuo.
FINGERPRINT_KEYS=(
    'admin.api_token'
    'mail.mailers.smtp.password'
    'mail.mailers.smtp.username'
    'billing.credentials.password'
    'billing.credentials.client_secret'
    'database.connections.pgsql.password'
    'services.openai.api_key'
    'wompi.private_key'
    'app.key'
)

snapshot() {
    for k in "${FINGERPRINT_KEYS[@]}"; do
        printf '%s %s\n' "$k" "$(config_sha "$k")"
    done
}

hdr "DEDUPE — huella ANTES"
BEFORE="$(snapshot)"
printf '%s\n' "$BEFORE" | sed 's/^/  /'

hdr "DEDUPE — duplicados encontrados"
TARGETS=()
for key in "$@"; do
    n="$(grep -cE "^[[:space:]]*${key}=" "$ENV_FILE" || true)"
    log "$(printf '%-28s' "$key") $n definición(es)"
    [ "$n" -gt 1 ] && TARGETS+=("$key")
done
if [ ${#TARGETS[@]} -eq 0 ]; then
    ok "no hay nada que deduplicar"
    exit 0
fi

BACKUP="$(env_backup)"
ok "copia previa: $BACKUP"

hdr "DEDUPE — eliminando definiciones muertas"
for key in "${TARGETS[@]}"; do
    owner="$(stat -c '%U:%G' "$ENV_FILE")"
    mode="$(stat -c '%a' "$ENV_FILE")"
    tmp="$(mktemp "$(dirname "$ENV_FILE")/.env.dedupe.XXXXXX")"
    chmod 600 "$tmp"

    awk -v KEY="$key" '
        BEGIN { rx = "^[ \t]*" KEY "=" }
        { lines[NR] = $0; if ($0 ~ rx) last = NR }
        END {
            for (i = 1; i <= NR; i++) {
                # La última se imprime TAL CUAL: ni se reescribe ni se recita.
                if (lines[i] ~ rx && i != last) continue
                print lines[i]
            }
        }
    ' "$ENV_FILE" > "$tmp"

    kept="$(grep -cE "^[[:space:]]*${key}=" "$tmp" || true)"
    if [ "$kept" -ne 1 ]; then
        rm -f "$tmp"
        fail "$key quedó con $kept definiciones tras el dedupe"
        env_restore "$BACKUP"; exit 1
    fi

    chown "$owner" "$tmp"; chmod "$mode" "$tmp"
    mv -f "$tmp" "$ENV_FILE"
    ok "$key → 1 definición (se conservó la última, la que ya se aplicaba)"
done

hdr "DEDUPE — recarga y comprobación"
cd "$APP_DIR"
sudo -u "$RUN_AS" php artisan config:cache >/dev/null && ok "config:cache"

AFTER="$(snapshot)"
if [ "$BEFORE" = "$AFTER" ]; then
    ok "la configuración efectiva es IDÉNTICA a la de antes"
else
    fail "la configuración efectiva CAMBIÓ — se revierte:"
    diff <(printf '%s\n' "$BEFORE") <(printf '%s\n' "$AFTER") | sed 's/^/    /' || true
    env_restore "$BACKUP"
    sudo -u "$RUN_AS" php artisan config:cache >/dev/null || true
    exit 1
fi

reload_consumers
health_check

printf '\n\033[1;32mDEDUPE = PASS\033[0m\n'
log "copia previa: $BACKUP"

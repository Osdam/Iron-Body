#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# secret-scan.sh — ¿alguno de los secretos VIVOS está fuera de donde debe estar?
#
# No busca patrones parecidos a una credencial: busca los valores EXACTOS que
# ahora mismo usa producción, leídos del .env. Un escáner de patrones dice
# "esto parece una clave"; éste dice "esta clave, la que está en uso hoy, está
# también en este fichero". Es la diferencia entre una sospecha y un hallazgo.
#
# El valor nunca se imprime ni pasa por argv: viaja a grep por stdin (`-f -`).
# La salida sólo dice NOMBRE de la variable y RUTA del fichero.
#
# Ubicaciones esperadas (no son hallazgos): el propio .env y sus copias de
# rotación, que existen justamente para poder volver atrás.
#
# Uso: secret-scan.sh [ruta-extra …]
# ---------------------------------------------------------------------------
set -Eeuo pipefail

APP_DIR="${APP_DIR:-/var/www/api/backend}"
ENV_FILE="${ENV_FILE:-$APP_DIR/.env}"

umask 077

BOLD=$'\033[1m'; RED=$'\033[31m'; GRN=$'\033[32m'; YEL=$'\033[33m'; OFF=$'\033[0m'

# Rutas donde un secreto NO debería estar nunca.
SCAN_PATHS=(
    "$APP_DIR/app" "$APP_DIR/config" "$APP_DIR/routes" "$APP_DIR/database"
    "$APP_DIR/tests" "$APP_DIR/docs" "$APP_DIR/resources" "$APP_DIR/public"
    "$APP_DIR/storage" "$APP_DIR/scripts" "$APP_DIR/bootstrap"
    "$APP_DIR/.env.example" "$APP_DIR/.env.production.example"
    /var/log /etc/nginx /etc/systemd/system /etc/supervisor /usr/local/sbin
    /opt/ironbody-rotation
)
[ $# -gt 0 ] && SCAN_PATHS+=("$@")

# Ubicaciones legítimas: el .env vivo, las copias de seguridad de rotación y la
# configuración compilada. Esta última CONTIENE todos los secretos por diseño
# (`config:cache` vuelca los valores resueltos), así que listarla 24 veces sólo
# taparía los hallazgos de verdad; lo que hay que vigilar de ella son los
# permisos, y eso se comprueba explícitamente más abajo.
EXPECTED_RX="^($ENV_FILE|$APP_DIR/bootstrap/cache/config\\.php|/root/ironbody-rotation/env-backups/|/root/secret-rotation-backups/|/root/db-backups/)"

printf '%s\n' "${BOLD}SECRET SCAN — valores vivos fuera de su sitio${OFF}"
printf '%s\n' "$(printf '─%.0s' $(seq 1 70))"

# ── Qué se busca ────────────────────────────────────────────────────────────
# Sólo variables cuyo valor es un secreto de verdad. Se descartan vacíos,
# booleanos, números y marcadores de plantilla: buscar "true" por todo el disco
# no encuentra fugas, encuentra ruido.
mapfile -t KEYS < <(awk '
    {
        l = $0; sub(/^[ \t]+/, "", l)
        if (l !~ /^[A-Za-z_][A-Za-z0-9_]*=/) next
        eq = index(l, "="); k = substr(l, 1, eq - 1); v = substr(l, eq + 1)
        gsub(/^["'"'"']|["'"'"']$/, "", v)
        if (k !~ /KEY|SECRET|PASSWORD|TOKEN|CLIENT_ID|CREDENTIAL|PRIVATE|_API$|API_KEY|DSN|WEBHOOK_SECRET/) next
        if (length(v) < 12) next
        lv = tolower(v)
        if (lv == "null" || lv == "true" || lv == "false" || lv ~ /^(changeme|placeholder|your|tu_|xxx)/) next
        if (v ~ /^[0-9]+$/) next
        # Rutas y URLs no son secretos aunque la clave se llame CREDENTIALS:
        # FCM_CREDENTIALS guarda la RUTA del fichero de la cuenta de servicio, y
        # buscarla por el disco marcaba config/fcm.php como fuga.
        if (v ~ /^https?:\/\//) next
        if (v ~ /^[A-Za-z0-9_.\/-]+\.[A-Za-z0-9]{2,5}$/ && v ~ /\//) next
        print k
    }
' "$ENV_FILE")

printf '  variables con valor secreto en el .env: %d\n\n' "${#KEYS[@]}"

FINDINGS=0
declare -a HITLIST=()

value_of() {
    awk -v KEY="$1" '
        { l = $0; sub(/^[ \t]+/, "", l)
          if (l ~ "^" KEY "=") { v = substr(l, index(l, "=") + 1); gsub(/^["'"'"']|["'"'"']$/, "", v); out = v } }
        END { printf "%s", out }
    ' "$ENV_FILE"
}

for key in "${KEYS[@]}"; do
    hits="$(value_of "$key" | grep -rlF -f - \
              --binary-files=without-match \
              --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git \
              --exclude-dir=framework --exclude='*.map' \
              "${SCAN_PATHS[@]}" 2>/dev/null || true)"

    unexpected=""
    while IFS= read -r f; do
        [ -n "$f" ] || continue
        [[ "$f" =~ $EXPECTED_RX ]] && continue
        unexpected+="$f"$'\n'
    done <<< "$hits"

    if [ -n "${unexpected//[$'\n']/}" ]; then
        FINDINGS=$((FINDINGS + 1))
        printf '  %s✘%s %-32s aparece FUERA del .env en:\n' "$RED" "$OFF" "$key"
        while IFS= read -r f; do
            [ -n "$f" ] || continue
            printf '        %s\n' "$f"
            HITLIST+=("$key → $f")
        done <<< "$unexpected"
    else
        printf '  %s✔%s %-32s sólo en el .env y sus copias\n' "$GRN" "$OFF" "$key"
    fi
done

# ── Rastros por patrón: credenciales de otros (o de antes) ──────────────────
printf '\n%s\n' "${BOLD}Rastros por patrón (credenciales ajenas al .env actual)${OFF}"
printf '%s\n' "$(printf '─%.0s' $(seq 1 70))"

declare -A PATTERNS=(
    ['clave OpenAI (sk-)']='sk-[A-Za-z0-9_-]\{20,\}'
    ['clave Anthropic']='sk-ant-[A-Za-z0-9_-]\{20,\}'
    ['Wompi privada prod']='prv_prod_[A-Za-z0-9]\{10,\}'
    ['Wompi integridad prod']='prod_integrity_[A-Za-z0-9]\{10,\}'
    ['token Meta (EAA…)']='EAA[A-Za-z0-9]\{40,\}'
    ['APP_KEY de Laravel']='base64:[A-Za-z0-9+/]\{40,\}='
    ['verificador SCRAM']='SCRAM-SHA-256\$[0-9]\{3,\}:'
    ['clave privada PEM']='-----BEGIN [A-Z ]*PRIVATE KEY-----'
)

# Coincidencias por patrón ya investigadas, con su motivo. Cada línea es una
# afirmación comprobada, no una excusa para callar el escáner:
#
#   · BillingSecretsTest.php lleva una constante FAKE_SECRET con pinta de clave
#     de OpenAI que la propia prueba usa para verificar que un secreto no se
#     filtra por la API. La búsqueda por valor confirma que OPENAI_API_KEY vive
#     sólo en el .env. (El literal no se repite aquí: escribirlo en este mismo
#     fichero hacía que el escáner se denunciara a sí mismo.)
#   · letsencrypt.log contiene blobs base64 de certificados; alguno empieza por
#     "EAA" por casualidad. META_ACCESS_TOKEN no aparece fuera del .env.
#   · service-account.json ES una credencial de verdad (cuenta de servicio de
#     Firebase) y está donde debe: se comprueba abajo que no sea legible por
#     otros, en vez de fingir que no existe.
ALLOWED_PATTERN_HITS_RX="(/tests/Feature/Billing/BillingSecretsTest\.php$|^/var/log/letsencrypt/|/storage/app/firebase/service-account\.json$)"

for label in "${!PATTERNS[@]}"; do
    found="$(grep -rl --binary-files=without-match \
              --exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git \
              --exclude-dir=framework --exclude='*.map' \
              -e "${PATTERNS[$label]}" "${SCAN_PATHS[@]}" 2>/dev/null || true)"
    clean=""
    while IFS= read -r f; do
        [ -n "$f" ] || continue
        [[ "$f" =~ $EXPECTED_RX ]] && continue
        [[ "$f" =~ $ALLOWED_PATTERN_HITS_RX ]] && { printf '  %s·%s %-28s %s (revisado: no es una fuga)\n' "$YEL" "$OFF" "$label" "$f"; continue; }
        clean+="$f"$'\n'
    done <<< "$found"

    if [ -n "${clean//[$'\n']/}" ]; then
        FINDINGS=$((FINDINGS + 1))
        printf '  %s✘%s %-28s\n' "$RED" "$OFF" "$label"
        while IFS= read -r f; do [ -n "$f" ] && printf '        %s\n' "$f"; done <<< "$clean"
    else
        printf '  %s✔%s %-28s sin rastros\n' "$GRN" "$OFF" "$label"
    fi
done

# ── Permisos ────────────────────────────────────────────────────────────────
printf '\n%s\n' "${BOLD}Permisos de los ficheros con secretos${OFF}"
printf '%s\n' "$(printf '─%.0s' $(seq 1 70))"
perm_check() {
    local f="$1" mode
    [ -e "$f" ] || return 0
    mode="$(stat -c '%a' "$f")"
    if [ "${mode: -1}" != "0" ]; then
        FINDINGS=$((FINDINGS + 1))
        printf '  %s✘%s %s  modo %s (legible por «otros»)\n' "$RED" "$OFF" "$f" "$mode"
    else
        printf '  %s✔%s %-56s modo %s\n' "$GRN" "$OFF" "$f" "$mode"
    fi
}
perm_check "$ENV_FILE"
perm_check "$APP_DIR/bootstrap/cache/config.php"
perm_check "$APP_DIR/storage/app/firebase/service-account.json"
for d in /root/ironbody-rotation/env-backups /root/secret-rotation-backups /root/db-backups; do
    [ -d "$d" ] || continue
    printf '  %s✔%s %-56s modo %s (dir)\n' "$GRN" "$OFF" "$d" "$(stat -c '%a' "$d")"
    while IFS= read -r f; do perm_check "$f"; done < <(find "$d" -maxdepth 1 -type f | head -40)
done

# ── Veredicto ───────────────────────────────────────────────────────────────
printf '\n%s\n' "$(printf '═%.0s' $(seq 1 70))"
if [ "$FINDINGS" -eq 0 ]; then
    printf '%sSECRET SCAN = PASS%s  (0 secretos vivos fuera de su sitio)\n' "$GRN$BOLD" "$OFF"
    exit 0
fi
printf '%sSECRET SCAN = %d HALLAZGO(S)%s\n' "$RED$BOLD" "$FINDINGS" "$OFF"
for h in "${HITLIST[@]:-}"; do [ -n "$h" ] && printf '  · %s\n' "$h"; done
exit 1

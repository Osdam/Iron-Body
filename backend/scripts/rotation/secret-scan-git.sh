#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# secret-scan-git.sh — ¿alguno de los secretos vivos entró alguna vez en Git?
#
# Distinto de secret-scan.sh: aquel mira el disco AHORA, éste mira el histórico
# COMPLETO (todas las ramas, todos los commits, incluidos los ficheros ya
# borrados). Un secreto que se subió y se quitó en el commit siguiente sigue
# estando en el repositorio para siempre, y `git status` limpio no dice nada de
# eso.
#
# Se corre en el servidor porque es donde están los valores vivos; el .git de
# aquí es el mismo repositorio que el de desarrollo.
#
# Nunca imprime valores: sólo NOMBRE de variable y número de apariciones.
# ---------------------------------------------------------------------------
set -Eeuo pipefail

APP_DIR="${APP_DIR:-/var/www/api/backend}"
ENV_FILE="${ENV_FILE:-$APP_DIR/.env}"
REPO="${REPO:-$APP_DIR}"

umask 077
BOLD=$'\033[1m'; RED=$'\033[31m'; GRN=$'\033[32m'; OFF=$'\033[0m'

WORK="$(mktemp -d)"; chmod 700 "$WORK"
trap 'find "$WORK" -type f -exec shred -u {} + 2>/dev/null || true; rm -rf "$WORK"' EXIT

printf '%s\n' "${BOLD}SECRET SCAN · HISTÓRICO DE GIT${OFF}"
printf '%s\n' "$(printf '─%.0s' $(seq 1 70))"
printf '  repositorio : %s\n' "$REPO"
printf '  commits     : %s\n' "$(git -C "$REPO" rev-list --all --count)"
printf '  ramas/refs  : %s\n\n' "$(git -C "$REPO" for-each-ref --format='%(refname)' | wc -l)"

mapfile -t KEYS < <(awk '
    {
        l = $0; sub(/^[ \t]+/, "", l)
        if (l !~ /^[A-Za-z_][A-Za-z0-9_]*=/) next
        eq = index(l, "="); k = substr(l, 1, eq - 1); v = substr(l, eq + 1)
        gsub(/^["'"'"']|["'"'"']$/, "", v)
        if (k !~ /KEY|SECRET|PASSWORD|TOKEN|CLIENT_ID|CREDENTIAL|PRIVATE|_API$|API_KEY|DSN/) next
        if (length(v) < 12) next
        lv = tolower(v)
        if (lv == "null" || lv == "true" || lv == "false" || lv ~ /^(changeme|placeholder|your|tu_|xxx)/) next
        if (v ~ /^[0-9]+$/) next
        if (v ~ /^https?:\/\//) next
        if (v ~ /^[A-Za-z0-9_.\/-]+\.[A-Za-z0-9]{2,5}$/ && v ~ /\//) next
        print k
    }
' "$ENV_FILE")

value_of() {
    awk -v KEY="$1" '
        { l = $0; sub(/^[ \t]+/, "", l)
          if (l ~ "^" KEY "=") { v = substr(l, index(l, "=") + 1); gsub(/^["'"'"']|["'"'"']$/, "", v); out = v } }
        END { printf "%s", out }
    ' "$ENV_FILE"
}

# Un único recorrido por todo el histórico: 400+ commits por 24 claves serían
# 24 recorridos completos. Se vuelca a un fichero 0600 que se tritura al salir.
PATTERNS="$WORK/patterns"; : > "$PATTERNS"; chmod 600 "$PATTERNS"
for key in "${KEYS[@]}"; do value_of "$key" >> "$PATTERNS"; printf '\n' >> "$PATTERNS"; done

HITS="$WORK/hits"; chmod 600 "$PATTERNS"
printf '  recorriendo el histórico completo (esto tarda)…\n'
git -C "$REPO" log --all --no-color -p 2>/dev/null | grep -F -f "$PATTERNS" > "$HITS" || true
chmod 600 "$HITS"
printf '  líneas del histórico que contienen algún secreto vivo: %s\n\n' "$(wc -l < "$HITS")"

FINDINGS=0
for key in "${KEYS[@]}"; do
    n="$(value_of "$key" | grep -cF -f - "$HITS" || true)"
    if [ "${n:-0}" -gt 0 ]; then
        FINDINGS=$((FINDINGS + 1))
        printf '  %s✘%s %-32s %s aparición(es) en el histórico\n' "$RED" "$OFF" "$key" "$n"
    else
        printf '  %s✔%s %-32s nunca entró en Git\n' "$GRN" "$OFF" "$key"
    fi
done

# Ficheros de entorno versionados alguna vez, con o sin valores dentro.
printf '\n%s\n' "${BOLD}Ficheros de entorno que estuvieron versionados${OFF}"
printf '%s\n' "$(printf '─%.0s' $(seq 1 70))"
envfiles="$(git -C "$REPO" log --all --pretty=format: --name-only --diff-filter=A \
            | sort -u | grep -E '(^|/)\.env' | grep -v '\.example$' || true)"
if [ -z "$envfiles" ]; then
    # Las plantillas .env.example y .env.production.example SÍ están
    # versionadas y deben estarlo: son la documentación de qué variables hacen
    # falta. Que no lleven valores reales lo fija SecretExposureTest.
    printf '  %s✔%s ningún .env con valores ha estado en el índice (sólo las plantillas .example)\n' "$GRN" "$OFF"
else
    printf '  %s✘%s ficheros .env* añadidos alguna vez:\n' "$RED" "$OFF"
    printf '        %s\n' $envfiles
    FINDINGS=$((FINDINGS + 1))
fi

printf '\n%s\n' "$(printf '═%.0s' $(seq 1 70))"
if [ "$FINDINGS" -eq 0 ]; then
    printf '%sGIT HISTORY = PASS%s  (0 secretos vivos en el histórico)\n' "$GRN$BOLD" "$OFF"
    exit 0
fi
printf '%sGIT HISTORY = %d HALLAZGO(S)%s — un secreto en el histórico exige ROTARLO,\n' "$RED$BOLD" "$FINDINGS" "$OFF"
printf 'no borrar el commit: quien clonó el repo ya lo tiene.\n'
exit 1

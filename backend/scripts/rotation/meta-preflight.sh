#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# meta-preflight.sh — todo lo comprobable de Meta ANTES de tocar el portal.
#
# Lo que hace y lo que NO:
#
#   · Pregunta a Graph por el token, el número y el WABA (sólo GET).
#   · Verifica el webhook por el camino REAL, el que llamará Meta: la URL
#     pública con hub.challenge, y un POST con firma inválida que debe salir
#     rechazado.
#   · NO envía mensajes, NO registra números, NO suscribe webhooks y NO mete un
#     evento sintético en la bandeja. Un preflight que ensucia el Inbox con
#     conversaciones falsas obliga a limpiarlas después, y limpiar a mano en
#     producción es como se pierden mensajes de verdad.
#
# Nada de esto depende de META_ENABLED: la entrada funciona con el canal
# apagado, y eso es justo lo que se quiere comprobar antes del OTP.
# ---------------------------------------------------------------------------
set -Eeuo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${APP_DIR:-/var/www/api/backend}"
ENV_FILE="${ENV_FILE:-$APP_DIR/.env}"
BASE="${BASE:-https://api.ironbodyneiva.cloud}"
RUN_AS="${RUN_AS:-www-data}"

PASS=0; FAILED=0; WARN=0
ok()   { PASS=$((PASS+1));    printf '  \033[32m✔\033[0m %-46s %s\n' "$1" "${2:-}"; }
bad()  { FAILED=$((FAILED+1)); printf '  \033[31m✘\033[0m %-46s %s\n' "$1" "${2:-}"; }
warn() { WARN=$((WARN+1));    printf '  \033[33m!\033[0m %-46s %s\n' "$1" "${2:-}"; }
hdr()  { printf '\n\033[1m%s\033[0m\n%s\n' "$*" "$(printf '─%.0s' $(seq 1 68))"; }

env_val() {
    awk -v KEY="$1" '
        { l = $0; sub(/^[ \t]+/, "", l)
          if (l ~ "^" KEY "=") { v = substr(l, index(l, "=") + 1); gsub(/^["'"'"']|["'"'"']$/, "", v); out = v } }
        END { printf "%s", out }
    ' "$ENV_FILE"
}

hdr "META · credenciales contra Graph (sólo lectura)"
php "$HERE/meta_preflight.php" || true

hdr "META · webhook, por el camino que usará Meta"

VERIFY_TOKEN="$(env_val META_VERIFY_TOKEN)"
CHALLENGE="preflight-$RANDOM$RANDOM"

if [ -z "$VERIFY_TOKEN" ]; then
    bad "META_VERIFY_TOKEN" "vacío: la verificación de Meta fallaría"
else
    # El verify token va en la query porque así lo manda Meta; es de un solo uso
    # conceptual y no abre nada por sí mismo.
    body="$(curl -sS --max-time 20 --get "${BASE}/api/webhooks/meta" \
        --data-urlencode "hub.mode=subscribe" \
        --data-urlencode "hub.verify_token=${VERIFY_TOKEN}" \
        --data-urlencode "hub.challenge=${CHALLENGE}" 2>/dev/null || echo '')"
    if [ "$body" = "$CHALLENGE" ]; then
        ok "GET /api/webhooks/meta" "devuelve el hub.challenge tal cual"
    else
        bad "GET /api/webhooks/meta" "devolvió [$(printf '%.60s' "$body")] en vez del challenge"
    fi

    wrong="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 --get "${BASE}/api/webhooks/meta" \
        --data-urlencode "hub.mode=subscribe" \
        --data-urlencode "hub.verify_token=token-que-no-es" \
        --data-urlencode "hub.challenge=${CHALLENGE}" 2>/dev/null || echo 000)"
    [ "$wrong" != "200" ] && ok "verify_token incorrecto rechazado" "HTTP $wrong" \
        || bad "verify_token incorrecto rechazado" "devolvió 200: acepta cualquiera"
fi

# Firma inválida: el cuerpo es un evento vacío y bien formado, así que si la
# firma se colara igualmente no crearía ninguna conversación.
sig_code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 \
    -X POST "${BASE}/api/webhooks/meta" \
    -H 'Content-Type: application/json' \
    -H 'X-Hub-Signature-256: sha256=0000000000000000000000000000000000000000000000000000000000000000' \
    -d '{"object":"whatsapp_business_account","entry":[]}' 2>/dev/null || echo 000)"
[ "$sig_code" = "403" ] && ok "POST con firma inválida" "HTTP 403" || bad "POST con firma inválida" "HTTP $sig_code (se esperaba 403)"

nosig_code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 \
    -X POST "${BASE}/api/webhooks/meta" \
    -H 'Content-Type: application/json' \
    -d '{"object":"whatsapp_business_account","entry":[]}' 2>/dev/null || echo 000)"
[ "$nosig_code" = "403" ] && ok "POST sin firma" "HTTP 403" || bad "POST sin firma" "HTTP $nosig_code (se esperaba 403)"

hdr "META · el secreto con el que Meta firma"
APP_SECRET="$(env_val META_APP_SECRET)"
WEBHOOK_SECRET="$(env_val META_WEBHOOK_SECRET)"
if [ -z "$WEBHOOK_SECRET" ] && [ -z "$APP_SECRET" ]; then
    bad "secreto de firma" "no hay ninguno configurado"
elif [ "$APP_SECRET" = "$WEBHOOK_SECRET" ]; then
    ok "META_WEBHOOK_SECRET = META_APP_SECRET" "coherente con cómo firma Meta"
else
    # Meta firma X-Hub-Signature-256 con el App Secret de la app. Si el que
    # valida el webhook es otro, TODO evento entrante se rechaza con 403 y el
    # síntoma es «no llega nada», sin ningún error que apunte al secreto.
    warn "META_WEBHOOK_SECRET ≠ META_APP_SECRET" "uno de los dos no es el App Secret real"
fi

hdr "META · canal de entrada listo para recibir"
for q in whatsapp-high media; do
    n="$(supervisorctl status 2>/dev/null | grep -c "ironbody-${q%%-*}" || true)"
    [ "${n:-0}" -ge 1 ] && ok "workers de la cola $q" "$n procesos" || bad "workers de la cola $q" "ninguno"
done

command -v ffmpeg >/dev/null && ok "ffmpeg" "$(ffmpeg -version 2>/dev/null | head -1 | cut -c1-40)" \
    || bad "ffmpeg" "ausente: las notas de voz no se podrán transcodificar"

media_disk="$(env_val WHATSAPP_MEDIA_DISK)"
ok "disco de multimedia" "${media_disk:-<por defecto>}"

hdr "META · interruptores (la primera conexión va con la IA apagada)"
for f in META_ENABLED MARKETING_AGENT_ENABLED COMMERCIAL_AUTONOMY_ENABLED MARKETING_HERMES_ENABLED IRON_GUARD_AUTO_REMEDIATION MARKETING_INBOUND_AUTO_EXECUTE; do
    v="$(env_val "$f")"
    case "$(printf '%s' "${v:-false}" | tr 'A-Z' 'a-z')" in
        true|1) bad "$f" "= $v (debe estar apagado hasta el canary)" ;;
        *)      ok  "$f" "= ${v:-false}" ;;
    esac
done

printf '\n%s\n' "$(printf '═%.0s' $(seq 1 68))"
printf '  PASS: %d   FAIL: %d   AVISOS: %d\n' "$PASS" "$FAILED" "$WARN"
[ "$FAILED" -eq 0 ] && printf '\n\033[1;32mMETA PREFLIGHT INTERNO = PASS\033[0m\n' \
    || printf '\n\033[1;31mMETA PREFLIGHT INTERNO = FAIL\033[0m\n'
exit 0

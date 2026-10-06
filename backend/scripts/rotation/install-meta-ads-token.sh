#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# install-meta-ads-token.sh — instala el token de Meta Ads del panel «Mercadeo
# digital (Meta)» en el .env productivo, SIN imprimirlo ni dejarlo en un log, y
# comprueba con Meta que lee la cuenta que debe.
#
# El token es del usuario de sistema `ironbody_crm_analytics` (app «Iron Body
# CRM Analytics») y tiene que llevar ÚNICAMENTE `ads_read`. No es el token de
# WhatsApp ni sustituye a ninguna otra credencial: este script solo escribe
# META_ADS_ACCESS_TOKEN, META_AD_ACCOUNT_ID y META_ADS_GRAPH_VERSION.
#
# Por qué no `ironbody-rotate-secret`: su lista de variables es cerrada y esta
# no está en ella. Las barreras son las mismas, las de lib-env.sh:
#   · el valor se teclea dos veces por la terminal, no se ve, y no va nunca por
#     argv (se queda en un fichero 0600 que se borra al salir);
#   · copia del .env antes de tocarlo (0600, root) y escritura atómica;
#   · lo que se compara es la longitud y una huella, jamás el valor.
#
# Qué hace, en orden:
#   1. lee el token y comprueba su forma (EAA…, solo letras y números);
#   2. copia el .env;
#   3. escribe las tres variables. META_ADS_SYNC_ENABLED NO se toca: la pasada
#      de cada hora se enciende aparte, cuando la cuenta esté conciliada;
#   4. cachea la configuración y comprueba que Laravel ve ESE token;
#   5. recarga php-fpm y reinicia los workers con elegancia (queue:restart);
#   6. `marketing:meta-ads-check` con la cuenta y el nombre esperados: si no
#      pasa (token sin ads_read, con permisos de más, otra cuenta, otra zona…),
#      deja el .env como estaba y el panel sigue «Sin conexión»;
#   7. comprueba que el token no aparece en ningún log.
#
# No sincroniza nada: eso va después, con el guion (sync de 30 días dos veces,
# conciliación y relleno de 2026).
#
# Uso (terminal real, para poder teclear el token):
#   ssh -t ironbody-vps 'sudo bash /var/www/api/backend/scripts/rotation/install-meta-ads-token.sh'
# Vuelta atrás: el script imprime la ruta de la copia; restaurarla y cachear:
#   sudo cp -p <copia> /var/www/api/backend/.env && cd /var/www/api/backend \
#     && sudo -u www-data php artisan config:cache && sudo systemctl reload php8.3-fpm
# ---------------------------------------------------------------------------
set -Eeuo pipefail
set +o history 2>/dev/null || true
set +x

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib-env.sh
source "$HERE/lib-env.sh"

CUENTA="${META_ADS_CUENTA:-1577192552449023}"
NOMBRE="${META_ADS_NOMBRE:-Fredy Medina}"
VERSION="${META_ADS_VERSION:-v26.0}"
# 0 solo para probar el script fuera del servidor (sin systemd ni workers).
RECARGAR="${RECARGAR:-1}"

require_root
[ -f "$ENV_FILE" ] || { fail "no encuentro $ENV_FILE"; exit 1; }
cd "$APP_DIR"

TEMPORALES=()
limpiar() { local f; for f in "${TEMPORALES[@]:-}"; do [ -n "$f" ] && rm -f "$f"; done; }
trap limpiar EXIT

artisan() { sudo -u "$RUN_AS" php artisan "$@"; }

# Lo que la aplicación ve de las variables que NO son secretas.
ver_config() {
    sudo -u "$RUN_AS" php -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        printf("cuenta=%s · versión=%s · zona=%s · pasada horaria=%s\n",
            config("meta.ads.ad_account_id") ?: "—", config("meta.ads.graph_version"),
            config("meta.ads.timezone"), config("meta.ads.sync_enabled") ? "ENCENDIDA" : "apagada");
    '
}

recargar() {
    [ "$RECARGAR" = 1 ] || { log "(sin recargar procesos: RECARGAR=0)"; return 0; }
    systemctl reload php8.3-fpm
    artisan queue:restart >/dev/null
    ok "php-fpm recargado; workers reiniciados con elegancia (queue:restart)"
}

cachear() {
    artisan config:clear >/dev/null && artisan config:cache >/dev/null || return 1
    # Como en deploy-produccion.sh: el fichero cacheado tiene que ser de www-data.
    chown "$RUN_AS:$RUN_AS" bootstrap/cache/config.php
    chmod 600 bootstrap/cache/config.php
}

BACKUP=""
restaurar() {
    [ -n "$BACKUP" ] || return 0
    env_restore "$BACKUP"
    cachear || fail "no se pudo cachear la configuración restaurada: revisar a mano"
    recargar
    ok "el .env vuelve a estar como antes: el panel sigue «Sin conexión»"
}

hdr "Meta Ads — estado ANTES"
if grep -qE '^[[:space:]]*META_ADS_ACCESS_TOKEN=.+' "$ENV_FILE"; then
    log "META_ADS_ACCESS_TOKEN: presente (se sustituirá)"
else
    log "META_ADS_ACCESS_TOKEN: no está"
fi
log "$(ver_config)"

hdr "1. Token (no se muestra)"
TOKEN_FILE="$(read_secret_to_file 'Token de Meta Ads (solo ads_read)')" || exit 1
TEMPORALES+=("$TOKEN_FILE")
LEN="$(secret_len "$TOKEN_FILE")"
# Se mira el fichero, no el valor: grep recibe la RUTA.
grep -qE '^EAA[A-Za-z0-9]+$' "$TOKEN_FILE" \
    || { fail "no parece un token de Meta (empieza por EAA y solo lleva letras y números)"; exit 1; }
[ "$LEN" -ge 100 ] || { fail "demasiado corto ($LEN caracteres): ¿se pegó entero?"; exit 1; }
ok "token leído: $LEN caracteres, huella $(secret_sha "$TOKEN_FILE")"

hdr "2. Copia del .env"
BACKUP="$(env_backup)"
ok "copia: $BACKUP"
trap 'fail "interrumpido: se restaura el .env"; restaurar; limpiar' ERR

hdr "3. Escribir"
CUENTA_FILE="$(mktemp)"; TEMPORALES+=("$CUENTA_FILE"); printf '%s' "$CUENTA" > "$CUENTA_FILE"
VERSION_FILE="$(mktemp)"; TEMPORALES+=("$VERSION_FILE"); printf '%s' "$VERSION" > "$VERSION_FILE"
env_set META_ADS_ACCESS_TOKEN "$TOKEN_FILE"
env_set META_AD_ACCOUNT_ID "$CUENTA_FILE"
env_set META_ADS_GRAPH_VERSION "$VERSION_FILE"

hdr "4. Lo que ve Laravel"
cachear || { fail "Laravel no lee la configuración nueva"; trap - ERR; restaurar; exit 1; }
VISTO="$(config_sha meta.ads.access_token)"
ESPERADO="len=$LEN sha=$(secret_sha "$TOKEN_FILE")"
if [ "$VISTO" != "$ESPERADO" ]; then
    fail "Laravel no ve el token nuevo ($VISTO)"; trap - ERR; restaurar; exit 1
fi
ok "Laravel ve el token nuevo ($VISTO)"
log "$(ver_config)"

hdr "5. Procesos"
recargar

hdr "6. Comprobación con Meta (solo lectura)"
trap - ERR
if ! artisan marketing:meta-ads-check --expect-account="$CUENTA" --expect-name="$NOMBRE"; then
    fail "la comprobación no pasó: se deja el .env como estaba"
    restaurar
    exit 1
fi

hdr "7. Rastro"
# -F -f: el patrón se lee del fichero, no de argv. Solo se cuentan coincidencias.
# Sin coincidencias grep sale con 1 (es el caso bueno): no puede tumbar el script.
n="$( { grep -rIcF -f "$TOKEN_FILE" storage/logs /var/log/nginx 2>/dev/null || true; } | awk -F: '{s += $NF} END {print s + 0}')"
if [ "$n" -eq 0 ]; then
    ok "el token no aparece en ningún log"
else
    fail "el token aparece $n veces en los logs: revisar y rotarlo"
fi

hdr "Listo"
log "Token instalado y cuenta comprobada. La pasada horaria sigue apagada."
log "Siguiente (guion): sync de 30 días dos veces → conciliación → relleno de 2026 (simulación y real)."

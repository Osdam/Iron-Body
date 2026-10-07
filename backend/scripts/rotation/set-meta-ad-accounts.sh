#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# set-meta-ad-accounts.sh — conecta al panel «Mercadeo digital (Meta)» las
# cuentas publicitarias que lee el usuario de sistema `ironbody_crm_analytics`
# (META_AD_ACCOUNT_IDS) y, si se pide, enciende la pasada horaria
# (META_ADS_SYNC_ENABLED). No toca el token ni ninguna otra credencial.
#
# Las barreras son las de lib-env.sh: copia del .env antes de tocarlo,
# escritura atómica y comprobación de lo que Laravel ve de verdad. Si la
# comprobación con Meta (solo lectura) no pasa, deja el .env como estaba.
#
# Uso:
#   ssh ironbody-vps 'sudo CUENTAS=1759859358524714,1577192552449023,361177379835856 \
#       bash /var/www/api/backend/scripts/rotation/set-meta-ad-accounts.sh'
#   # y, cuando sync, relleno, conciliación y QA hayan pasado:
#   ssh ironbody-vps 'sudo CUENTAS=… ENCENDER_PASADA=1 bash …/set-meta-ad-accounts.sh'
#
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

CUENTAS="${CUENTAS:-}"
ENCENDER_PASADA="${ENCENDER_PASADA:-0}"
# 0 solo para probar el script fuera del servidor (sin systemd ni workers).
RECARGAR="${RECARGAR:-1}"

require_root
[ -f "$ENV_FILE" ] || { fail "no encuentro $ENV_FILE"; exit 1; }
cd "$APP_DIR"

# Ids de cuenta: solo dígitos, separados por comas, sin repetir. No son secretos.
[[ "$CUENTAS" =~ ^[0-9]{1,40}(,[0-9]{1,40})*$ ]] \
    || { fail "CUENTAS tiene que ser una lista de ids separados por comas (solo dígitos)"; exit 1; }
if [ "$(printf '%s' "$CUENTAS" | tr ',' '\n' | sort | uniq -d | wc -l)" -ne 0 ]; then
    fail "CUENTAS repite algún id"; exit 1
fi

TEMPORALES=()
limpiar() { local f; for f in "${TEMPORALES[@]:-}"; do [ -n "$f" ] && rm -f "$f"; done; }
trap limpiar EXIT

artisan() { sudo -u "$RUN_AS" php artisan "$@"; }

ver_config() {
    sudo -u "$RUN_AS" php -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        printf("cuentas=%s · principal=%s · zona=%s · pasada horaria=%s\n",
            implode(",", (array) config("meta.ads.ad_account_ids")) ?: "—",
            config("meta.ads.ad_account_id") ?: "—",
            config("meta.ads.timezone"), config("meta.ads.sync_enabled") ? "ENCENDIDA" : "apagada");
    '
}

cuentas_vistas() {
    sudo -u "$RUN_AS" php -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        echo implode(",", (array) config("meta.ads.ad_account_ids"));
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
    chown "$RUN_AS:$RUN_AS" bootstrap/cache/config.php
    chmod 600 bootstrap/cache/config.php
}

BACKUP=""
restaurar() {
    [ -n "$BACKUP" ] || return 0
    env_restore "$BACKUP"
    cachear || fail "no se pudo cachear la configuración restaurada: revisar a mano"
    recargar
    ok "el .env vuelve a estar como antes"
}

hdr "Meta Ads — estado ANTES"
log "$(ver_config)"

hdr "1. Copia del .env"
BACKUP="$(env_backup)"
ok "copia: $BACKUP"
trap 'fail "interrumpido: se restaura el .env"; restaurar; limpiar' ERR

hdr "2. Escribir"
CUENTAS_FILE="$(mktemp)"; TEMPORALES+=("$CUENTAS_FILE"); printf '%s' "$CUENTAS" > "$CUENTAS_FILE"
env_set META_AD_ACCOUNT_IDS "$CUENTAS_FILE"
if [ "$ENCENDER_PASADA" = 1 ]; then
    PASADA_FILE="$(mktemp)"; TEMPORALES+=("$PASADA_FILE"); printf 'true' > "$PASADA_FILE"
    env_set META_ADS_SYNC_ENABLED "$PASADA_FILE"
fi

hdr "3. Lo que ve Laravel"
cachear || { fail "Laravel no lee la configuración nueva"; trap - ERR; restaurar; exit 1; }
VISTAS="$(cuentas_vistas)"
if [ "$VISTAS" != "$CUENTAS" ]; then
    fail "Laravel ve otras cuentas ($VISTAS)"; trap - ERR; restaurar; exit 1
fi
ok "Laravel ve las cuentas: $VISTAS"
log "$(ver_config)"

hdr "4. Procesos"
recargar

hdr "5. Comprobación con Meta (solo lectura, cada cuenta)"
trap - ERR
if ! artisan marketing:meta-ads-check; then
    fail "la comprobación no pasó: se deja el .env como estaba"
    restaurar
    exit 1
fi

hdr "Listo"
log "$(ver_config)"
[ "$ENCENDER_PASADA" = 1 ] && log "Pasada horaria ENCENDIDA (minuto 15 de cada hora, solo lectura contra Meta)." \
    || log "La pasada horaria no se tocó."

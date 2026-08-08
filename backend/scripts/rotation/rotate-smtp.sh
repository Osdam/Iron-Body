#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# rotate-smtp.sh — rotación de la credencial SMTP (Hostinger).
#
# Proveedor: smtp.hostinger.com:465 (SMTPS). La credencial es la contraseña del
# BUZÓN: el mismo secreto sirve para leer el correo por IMAP y para enviar por
# SMTP, así que cambiarla en hPanel invalida las dos cosas a la vez. No hay
# "contraseña de aplicación" separada que rotar por su cuenta.
#
# Consumidor real: uno solo, `SendElectronicInvoiceEmailJob` (cola `billing`),
# que manda el comprobante al cliente. Nada más envía correo en este sistema —
# los OTP van por otro canal—, así que una credencial rota no silencia
# notificaciones: retrasa comprobantes, y el job reintenta.
#
# Igual que en Factus: la credencial nueva se prueba ANTES de escribir el .env.
#
# Uso (terminal real, no chat):
#   ssh -t root@servidor /opt/ironbody-rotation/rotate-smtp.sh
#   ssh -t root@servidor /opt/ironbody-rotation/rotate-smtp.sh --send=tu@correo
# ---------------------------------------------------------------------------
set -Eeuo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib-env.sh
source "$HERE/lib-env.sh"

require_root

SEND_TO=""
KEYS=(MAIL_PASSWORD)
for arg in "$@"; do
    case "$arg" in
        --send=*)      SEND_TO="${arg#--send=}" ;;
        MAIL_USERNAME) KEYS=(MAIL_USERNAME "${KEYS[@]}") ;;
        *) fail "argumento no reconocido: $arg"; exit 1 ;;
    esac
done

hdr "SMTP — estado ANTES"
log "$(printf '%-22s' 'MAIL_HOST')      $(cd "$APP_DIR" && grep -m1 -oP '^\s*MAIL_HOST=\K.*' .env)"
log "$(printf '%-22s' 'MAIL_USERNAME')  $(config_sha 'mail.mailers.smtp.username')"
log "$(printf '%-22s' 'MAIL_PASSWORD')  $(config_sha 'mail.mailers.smtp.password')"
php "$HERE/smtp_check.php" || { fail "la credencial ACTUAL ya no autentica (¿se cambió en el panel?)"; }

hdr "SMTP — valor nuevo"
declare -A VALFILE=()
cleanup() {
    for f in "${VALFILE[@]:-}"; do
        [ -n "${f:-}" ] && [ -f "$f" ] && shred -u "$f" 2>/dev/null || true
    done
}
trap cleanup EXIT

for k in "${KEYS[@]}"; do
    VALFILE[$k]="$(read_secret_to_file "$k")"
done

hdr "SMTP — comprobación previa (sin tocar el .env)"
PRECHECK_ARGS=()
for k in "${KEYS[@]}"; do PRECHECK_ARGS+=("$k=${VALFILE[$k]}"); done
if ! php "$HERE/smtp_check.php" "${PRECHECK_ARGS[@]}"; then
    fail "la credencial nueva NO autentica. El .env NO se ha tocado."
    exit 1
fi

hdr "SMTP — escritura"
BACKUP="$(env_backup)"
ok "copia previa: $BACKUP"

rollback() {
    fail "revirtiendo…"
    env_restore "$BACKUP"
    reload_consumers || true
    fail "SMTP ROTATION = FAIL (revertido)"
    exit 1
}

for k in "${KEYS[@]}"; do
    env_set "$k" "${VALFILE[$k]}" || rollback
done

hdr "SMTP — recarga de consumidores"
reload_consumers || rollback

hdr "SMTP — la configuración efectiva es la nueva"
declare -A CONFIG_PATH=(
    [MAIL_USERNAME]='mail.mailers.smtp.username'
    [MAIL_PASSWORD]='mail.mailers.smtp.password'
)
for k in "${KEYS[@]}"; do
    want="len=$(secret_len "${VALFILE[$k]}") sha=$(secret_sha "${VALFILE[$k]}")"
    got="$(config_sha "${CONFIG_PATH[$k]}")"
    [ "$want" = "$got" ] || { fail "$k → esperado [$want], Laravel ve [$got]"; rollback; }
    ok "$k → $got"
done

hdr "SMTP — autenticación desde la configuración ya aplicada"
if [ -n "$SEND_TO" ]; then
    php "$HERE/smtp_check.php" "--send=$SEND_TO" || rollback
else
    php "$HERE/smtp_check.php" || rollback
    log "sin --send=<correo> no se ha enviado ningún mensaje (a propósito)"
fi

hdr "SMTP — cola de facturación"
systemctl is-active --quiet ironbody-billing-worker && ok "ironbody-billing-worker activo" || { fail "worker de billing caído"; rollback; }
failed="$(cd "$APP_DIR" && sudo -u "$RUN_AS" php -r '
    require "vendor/autoload.php"; $a = require "bootstrap/app.php";
    $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    echo Illuminate\Support\Facades\DB::table("failed_jobs")->count();
')"
log "failed_jobs: $failed"

health_check || rollback

printf '\n\033[1;32mSMTP ROTATION = PASS\033[0m\n'
log "copia previa: $BACKUP"

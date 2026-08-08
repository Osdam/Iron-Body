#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# rotate-factus.sh — rotación atómica de las credenciales RAÍZ de Factus.
#
# Credenciales raíz (las únicas que se rotan aquí):
#   FACTUS_PASSWORD        contraseña de la cuenta Factus  (autoservicio: panel)
#   FACTUS_CLIENT_SECRET   secreto OAuth2                  (lo emite Halltec)
#   FACTUS_CLIENT_ID       identificador OAuth2            (lo emite Halltec)
#   FACTUS_USERNAME        email de la cuenta              (lo emite Halltec)
#
# access_token y refresh_token NO son credenciales: son derivados y se destruyen
# siempre, se rote lo que se rote. Es la parte que importa: FactusTokenManager
# prueba `refresh_token` antes que `password`, así que un refresh viejo vivo
# (TTL 14 días) haría pasar por buena una credencial equivocada.
#
# Uso (en un terminal REAL, no pegando el secreto en un chat ni en argv):
#   ssh -t root@servidor /opt/ironbody-rotation/rotate-factus.sh FACTUS_PASSWORD
#   ssh -t root@servidor /opt/ironbody-rotation/rotate-factus.sh FACTUS_CLIENT_ID FACTUS_CLIENT_SECRET
#
# Sin argumentos rota sólo FACTUS_PASSWORD.
# Cualquier fallo (config efectiva distinta, auth KO, health KO) revierte el
# .env a la copia previa y vuelve a recargar los consumidores.
# ---------------------------------------------------------------------------
set -Eeuo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib-env.sh
source "$HERE/lib-env.sh"

require_root

KEYS=("$@")
[ ${#KEYS[@]} -gt 0 ] || KEYS=(FACTUS_PASSWORD)

declare -A CONFIG_PATH=(
    [FACTUS_USERNAME]='billing.credentials.username'
    [FACTUS_PASSWORD]='billing.credentials.password'
    [FACTUS_CLIENT_ID]='billing.credentials.client_id'
    [FACTUS_CLIENT_SECRET]='billing.credentials.client_secret'
)

for k in "${KEYS[@]}"; do
    [ -n "${CONFIG_PATH[$k]:-}" ] || { fail "clave no rotable aquí: $k"; exit 1; }
done

hdr "FACTUS — estado ANTES"
log "ambiente : $(cd "$APP_DIR" && sudo -u "$RUN_AS" php artisan tinker --execute='echo config("billing.env");' 2>/dev/null || echo '?')"
for k in "${!CONFIG_PATH[@]}"; do
    log "$(printf '%-22s' "$k") $(config_sha "${CONFIG_PATH[$k]}")"
done
sudo -u "$RUN_AS" php "$HERE/factus_verify.php" --inspect 2>/dev/null | sed 's/^/  /' || true

hdr "FACTUS — nuevos valores"
log "Se piden por stdin, con confirmación. No se muestran, no van en argv,"
log "no quedan en el historial. Ctrl-C aborta sin tocar nada."
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

hdr "FACTUS — comprobación previa de las credenciales nuevas"
log "Se prueba el password grant con los valores nuevos ANTES de tocar el .env:"
log "si Factus los rechaza, el sistema se queda exactamente como estaba."
PRECHECK_ARGS=()
for k in "${KEYS[@]}"; do
    # Sólo viaja la RUTA del fichero por argv; el valor nunca.
    PRECHECK_ARGS+=("$k=${VALFILE[$k]}")
done
if ! php "$HERE/factus_precheck.php" "${PRECHECK_ARGS[@]}"; then
    fail "las credenciales nuevas NO autentican contra Factus. El .env NO se ha tocado."
    exit 1
fi

hdr "FACTUS — escritura"
BACKUP="$(env_backup)"
ok "copia previa: $BACKUP"

rollback() {
    fail "revirtiendo…"
    env_restore "$BACKUP"
    reload_consumers || true
    sudo -u "$RUN_AS" php "$HERE/factus_verify.php" --purge >/dev/null 2>&1 || true
    fail "FACTUS ROTATION = FAIL (revertido al estado anterior)"
    exit 1
}

for k in "${KEYS[@]}"; do
    env_set "$k" "${VALFILE[$k]}" || rollback
done

hdr "FACTUS — recarga de consumidores"
reload_consumers || rollback

hdr "FACTUS — la configuración efectiva es la nueva"
FPFAIL=0
for k in "${KEYS[@]}"; do
    want="len=$(secret_len "${VALFILE[$k]}") sha=$(secret_sha "${VALFILE[$k]}")"
    got="$(config_sha "${CONFIG_PATH[$k]}")"
    if [ "$want" = "$got" ]; then
        ok "$k → $got"
    else
        fail "$k → esperado [$want] pero Laravel ve [$got]"
        FPFAIL=1
    fi
done
[ "$FPFAIL" -eq 0 ] || rollback

hdr "FACTUS — tokens derivados y autenticación desde cero"
sudo -u "$RUN_AS" php "$HERE/factus_verify.php" --verify || rollback

hdr "FACTUS — salud del servicio"
health_check || rollback

hdr "FACTUS — doctor (read-only, sin red)"
(cd "$APP_DIR" && sudo -u "$RUN_AS" php artisan billing:factus-doctor 2>&1 | tail -5) || true

printf '\n\033[1;32mFACTUS ROTATION = PASS\033[0m\n'
log "copia previa conservada en: $BACKUP"

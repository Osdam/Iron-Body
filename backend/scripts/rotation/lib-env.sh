#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# lib-env.sh — primitivas para rotar secretos del .env productivo.
#
# Reglas que impone esta librería (no son recomendaciones, son barreras):
#
#   · El valor NUNCA viaja por argv. Se lee de stdin y se deja en un fichero
#     temporal 0600 que awk lee con getline. Así no aparece en `ps`, ni en el
#     historial del shell, ni en los logs de systemd.
#   · La escritura es atómica: se construye un fichero nuevo en el MISMO
#     sistema de ficheros y se mueve con `mv`. Nunca hay un .env a medias, ni
#     siquiera si el proceso muere entre medias.
#   · Se respeta la semántica real de phpdotenv ante claves duplicadas:
#     comprobado en este servidor que GANA LA ÚLTIMA ocurrencia. El .env
#     productivo tiene duplicados reales (ADMIN_API_TOKEN, MAIL_PASSWORD,
#     MEMBER_REGISTRATION_TOKEN), así que editar "la primera que aparezca"
#     habría escrito una línea que nadie lee.
#   · Toda rotación deja copia previa en $BACKUP_DIR (0600, root) y expone
#     env_restore para volver atrás sin pensar.
# ---------------------------------------------------------------------------
set -Eeuo pipefail

APP_DIR="${APP_DIR:-/var/www/api/backend}"
ENV_FILE="${ENV_FILE:-$APP_DIR/.env}"
BACKUP_DIR="${BACKUP_DIR:-/root/ironbody-rotation/env-backups}"
RUN_AS="${RUN_AS:-www-data}"

umask 077

log()  { printf '  %s\n' "$*"; }
ok()   { printf '  \033[32m✔\033[0m %s\n' "$*"; }
fail() { printf '  \033[31m✘\033[0m %s\n' "$*" >&2; }
hdr()  { printf '\n\033[1m%s\033[0m\n%s\n' "$*" "$(printf '─%.0s' $(seq 1 62))"; }

require_root() {
    [ "$(id -u)" -eq 0 ] || { fail "este script debe correr como root"; exit 1; }
}

# ── Copia de seguridad ─────────────────────────────────────────────────────
env_backup() {
    install -d -m 700 "$BACKUP_DIR"
    local stamp dest
    stamp="$(date +%Y%m%d-%H%M%S)"
    dest="$BACKUP_DIR/.env.$stamp"
    cp -p "$ENV_FILE" "$dest"
    chmod 600 "$dest"
    printf '%s' "$dest"
}

env_restore() {
    local from="$1"
    [ -f "$from" ] || { fail "no existe la copia $from"; return 1; }
    local owner mode
    owner="$(stat -c '%U:%G' "$ENV_FILE")"
    mode="$(stat -c '%a' "$ENV_FILE")"
    cp "$from" "$ENV_FILE.restore.$$"
    chown "$owner" "$ENV_FILE.restore.$$"
    chmod "$mode" "$ENV_FILE.restore.$$"
    mv -f "$ENV_FILE.restore.$$" "$ENV_FILE"
    ok "restaurado .env desde $(basename "$from")"
}

# ── Lectura silenciosa de un secreto ───────────────────────────────────────
# read_secret VAR_NAME "etiqueta"  → deja el valor en un fichero temporal 0600
# cuya ruta se imprime por stdout. El valor jamás se muestra ni se exporta.
read_secret_to_file() {
    local label="$1" tmp v1 v2
    tmp="$(mktemp)"; chmod 600 "$tmp"
    printf '  %s: ' "$label" > /dev/tty
    IFS= read -rs v1 < /dev/tty; printf '\n' > /dev/tty
    printf '  repetir para confirmar: ' > /dev/tty
    IFS= read -rs v2 < /dev/tty; printf '\n' > /dev/tty
    if [ "$v1" != "$v2" ]; then
        rm -f "$tmp"; unset v1 v2
        fail "no coinciden"; return 1
    fi
    if [ -z "$v1" ]; then rm -f "$tmp"; unset v1 v2; fail "valor vacío"; return 1; fi
    case "$v1" in
        *"'"*) rm -f "$tmp"; unset v1 v2
               fail "el valor contiene comilla simple: no se puede citar sin ambigüedad en .env. Regenéralo."
               return 1 ;;
    esac
    printf '%s' "$v1" > "$tmp"
    unset v1 v2
    printf '%s' "$tmp"
}

# sha corto de un fichero de secreto, para comparar sin revelar.
secret_sha() { sha256sum "$1" | cut -c1-8; }
secret_len() { printf '%s' "$(wc -c < "$1" | tr -d ' ')"; }

# ── Escritura atómica de una clave ─────────────────────────────────────────
# env_set KEY VALUE_FILE
#   · sustituye la ÚLTIMA ocurrencia (la que phpdotenv aplica de verdad)
#   · elimina las ocurrencias anteriores de esa misma clave (dedupe)
#   · si la clave no existe, la añade al final
env_set() {
    local key="$1" valfile="$2" tmp owner mode
    [ -f "$valfile" ] || { fail "falta el fichero de valor"; return 1; }

    owner="$(stat -c '%U:%G' "$ENV_FILE")"
    mode="$(stat -c '%a' "$ENV_FILE")"
    tmp="$(mktemp "$(dirname "$ENV_FILE")/.env.new.XXXXXX")"
    chmod 600 "$tmp"

    awk -v KEY="$key" -v VALFILE="$valfile" '
        BEGIN {
            getline NEW < VALFILE; close(VALFILE)
            rx = "^[ \t]*" KEY "="
        }
        { lines[NR] = $0; if ($0 ~ rx) last = NR }
        END {
            if (last == 0) {
                for (i = 1; i <= NR; i++) print lines[i]
                print KEY "='"'"'" NEW "'"'"'"
            } else {
                for (i = 1; i <= NR; i++) {
                    if (i == last) {
                        match(lines[i], /^[ \t]*/)
                        indent = substr(lines[i], 1, RLENGTH)
                        print indent KEY "='"'"'" NEW "'"'"'"
                    } else if (lines[i] ~ rx) {
                        # duplicado anterior: se elimina (phpdotenv usa el último)
                        continue
                    } else {
                        print lines[i]
                    }
                }
            }
        }
    ' "$ENV_FILE" > "$tmp"

    # El .env nuevo debe tener al menos tantas claves como el viejo menos los
    # duplicados eliminados. Un fichero truncado no se mueve a producción.
    local before after
    before="$(grep -cE '^[[:space:]]*[A-Za-z_][A-Za-z0-9_]*=' "$ENV_FILE")"
    after="$(grep -cE '^[[:space:]]*[A-Za-z_][A-Za-z0-9_]*=' "$tmp")"
    if [ "$after" -lt $((before - 5)) ]; then
        rm -f "$tmp"
        fail "el .env resultante perdió demasiadas claves ($before → $after). Abortado."
        return 1
    fi

    chown "$owner" "$tmp"
    chmod "$mode" "$tmp"
    mv -f "$tmp" "$ENV_FILE"
    ok "$key escrito (.env atómico, $after claves)"
}

# ── Comprobación: lo que Laravel ve de verdad ──────────────────────────────
# config_sha "dotted.config.key" → imprime "len=N sha=xxxxxxxx" o <VACIO>
config_sha() {
    local key="$1"
    cd "$APP_DIR"
    sudo -u "$RUN_AS" php -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        $v = config($argv[1]);
        if ($v === null || $v === "") { echo "<VACIO>"; exit; }
        $v = (string) $v;
        echo "len=" . strlen($v) . " sha=" . substr(hash("sha256", $v), 0, 8);
    ' "$key"
}

# ── Recarga de consumidores ────────────────────────────────────────────────
reload_consumers() {
    cd "$APP_DIR"
    sudo -u "$RUN_AS" php artisan config:cache  >/dev/null && ok "config:cache"
    # `config:cache` vuelca en bootstrap/cache/config.php el valor RESUELTO de
    # todos los secretos y lo escribe con el umask del proceso: en este servidor
    # nacía a 664, legible por cualquier usuario. El .env a 600 no sirve de nada
    # si al lado queda una copia compilada abierta, y como se reescribe en cada
    # recarga, el chmod tiene que ir aquí y no en un arreglo de una vez.
    chmod 600 "$APP_DIR/bootstrap/cache/config.php" 2>/dev/null \
        && ok "configuración compilada a 600"
    sudo -u "$RUN_AS" php artisan queue:restart >/dev/null && ok "queue:restart (señal a los workers en vuelo)"
    systemctl restart php8.3-fpm                && ok "php8.3-fpm reiniciado"
    systemctl restart ironbody-billing-worker   && ok "ironbody-billing-worker reiniciado"
    supervisorctl restart all >/dev/null        && ok "workers supervisor reiniciados"
}

health_check() {
    local url="${1:-https://api.ironbodyneiva.cloud/api/health}" code
    code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "$url" || echo 000)"
    if [ "$code" = "200" ]; then ok "health $url → HTTP 200"; return 0; fi
    fail "health $url → HTTP $code"; return 1
}

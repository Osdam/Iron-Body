#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# rotate-postgres.sh — rotación coordinada del password del ROLE de aplicación.
#
# Qué se rota: el role `iron`, que es el que usa Laravel (php-fpm, workers,
# scheduler, comandos) por TCP contra 127.0.0.1 con scram-sha-256.
#
# Qué NO se toca, a propósito:
#
#   · El usuario `postgres`. Los respaldos (`ironbody-db-backup.sh`) y la
#     verificación de restauración corren como el usuario de sistema
#     `postgres` y la línea `local all all peer` de pg_hba les da identidad sin
#     contraseña. Rotar `iron` no los roza. Se comprueba explícitamente al
#     final en vez de darlo por hecho.
#   · APP_KEY, que se conserva de forma deliberada.
#
# Nadie teclea nada: la contraseña se genera en el propio servidor, se usa y se
# destruye. No pasa por el prompt, ni por argv, ni por el historial, ni por un
# log. A PostgreSQL ni siquiera llega en claro: se le entrega el verificador
# SCRAM ya calculado (ver scram_verifier.php).
#
# Rollback real: antes de cambiar nada se guarda el verificador ANTERIOR de
# `pg_authid`. Si algo falla se restaura ese verificador y el .env de la copia,
# y la contraseña vieja vuelve a ser válida tal cual era.
#
# Ventana: entre el ALTER ROLE y la recarga de la configuración hay unos
# segundos en los que una conexión NUEVA con la credencial vieja sería
# rechazada. Las conexiones ya abiertas no se cortan (PostgreSQL no las
# reautentica), así que el impacto se limita a las peticiones que lleguen justo
# en ese hueco. Se mide y se informa.
# ---------------------------------------------------------------------------
set -Eeuo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib-env.sh
source "$HERE/lib-env.sh"

require_root

DB_ROLE="${DB_ROLE:-iron}"
DB_NAME="${DB_NAME:-ironbody}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-5432}"

PSQL_SUPER=(sudo -u postgres psql -X -q -v ON_ERROR_STOP=1)

WORK="$(mktemp -d)"; chmod 700 "$WORK"
cleanup() { find "$WORK" -type f -exec shred -u {} + 2>/dev/null || true; rm -rf "$WORK"; }
trap cleanup EXIT

# ── 0. El terreno es el que creemos ────────────────────────────────────────
hdr "POSTGRES — comprobaciones previas"

cd /  # psql como postgres no puede leer /root

conn="$(cd "$APP_DIR" && sudo -u "$RUN_AS" php -r '
    require "vendor/autoload.php"; $a = require "bootstrap/app.php";
    $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $c = config("database.default");
    echo $c."|".config("database.connections.$c.username")."|".config("database.connections.$c.database");
')"
IFS='|' read -r cfg_driver cfg_user cfg_db <<< "$conn"
log "Laravel usa: driver=$cfg_driver role=$cfg_user db=$cfg_db"
[ "$cfg_user" = "$DB_ROLE" ] || { fail "el role de Laravel ($cfg_user) no es $DB_ROLE"; exit 1; }
[ "$cfg_db" = "$DB_NAME" ] || { fail "la BD de Laravel ($cfg_db) no es $DB_NAME"; exit 1; }

"${PSQL_SUPER[@]}" -tAc "SELECT 1" >/dev/null && ok "peer del superusuario postgres operativo (respaldos a salvo)"

superuser="$("${PSQL_SUPER[@]}" -tAc "SELECT rolsuper FROM pg_roles WHERE rolname='$DB_ROLE'")"
[ "$superuser" = "f" ] && ok "$DB_ROLE no es superusuario" || { fail "$DB_ROLE es superusuario: revísalo antes de rotar"; exit 1; }

log_stmt="$("${PSQL_SUPER[@]}" -tAc "SHOW log_statement")"
log "log_statement=$log_stmt (aun así la contraseña no viaja en claro: se envía el verificador SCRAM)"

# ── 1. Verificador anterior, para poder volver ─────────────────────────────
OLDVERIFIER="$WORK/old.verifier"
"${PSQL_SUPER[@]}" -tAc "SELECT rolpassword FROM pg_authid WHERE rolname='$DB_ROLE'" > "$OLDVERIFIER"
chmod 600 "$OLDVERIFIER"
if ! grep -q '^SCRAM-SHA-256\$' "$OLDVERIFIER"; then
    fail "no se pudo leer el verificador actual de $DB_ROLE; sin rollback fiable no se rota"
    exit 1
fi
ok "verificador anterior guardado (rollback posible)"

# ── 2. Contraseña nueva, generada aquí y sólo aquí ─────────────────────────
hdr "POSTGRES — credencial nueva"
NEWPASS="$WORK/new.pass"
# Alfanumérica de 44 caracteres (~262 bits). Sin comillas ni símbolos: el .env
# la cita entre comillas simples y una comilla en el valor sería ambigua.
#
# La fuente es `openssl rand`, no `tr < /dev/urandom | head`: ese pipe termina
# con SIGPIPE cuando head ya tiene bastante, y con `pipefail` eso aborta el
# script a mitad. `cut` consume toda la entrada y no dispara la señal.
openssl rand -base64 96 | LC_ALL=C tr -dc 'A-Za-z0-9' | cut -c1-44 | tr -d '\n' > "$NEWPASS"
chmod 600 "$NEWPASS"
[ "$(wc -c < "$NEWPASS")" -eq 44 ] || { fail "no se pudo generar la contraseña"; exit 1; }
ok "contraseña generada en el servidor: $(secret_len "$NEWPASS") caracteres, sha=$(secret_sha "$NEWPASS")"

VERIFIER="$WORK/new.verifier"
php "$HERE/scram_verifier.php" "$NEWPASS" > "$VERIFIER"
chmod 600 "$VERIFIER"
grep -q '^SCRAM-SHA-256\$4096:' "$VERIFIER" || { fail "el verificador SCRAM no tiene el formato esperado"; exit 1; }
ok "verificador SCRAM calculado (la contraseña en claro no llega a PostgreSQL)"

# ── 3. Cambio en PostgreSQL ────────────────────────────────────────────────
hdr "POSTGRES — ALTER ROLE"
BACKUP="$(env_backup)"
ok "copia previa del .env: $BACKUP"

WINDOW_START="$(date +%s%3N)"

{
    printf "ALTER ROLE %s PASSWORD '" "$DB_ROLE"
    cat "$VERIFIER"
    printf "';\n"
} > "$WORK/alter.sql"
chmod 600 "$WORK/alter.sql"

# El SQL entra por stdin, no con -f: el directorio de trabajo es 0700 de root
# y el proceso psql corre como `postgres`, que no puede abrir ese fichero. La
# redirección la hace root antes del sudo, así que el descriptor ya va abierto.
if ! "${PSQL_SUPER[@]}" < "$WORK/alter.sql" >/dev/null; then
    fail "ALTER ROLE falló; no se ha tocado el .env"
    exit 1
fi
ok "ALTER ROLE $DB_ROLE ejecutado"

pg_rollback() {
    {
        printf "ALTER ROLE %s PASSWORD '" "$DB_ROLE"
        tr -d '\n' < "$OLDVERIFIER"
        printf "';\n"
    } > "$WORK/rollback.sql"
    chmod 600 "$WORK/rollback.sql"
    "${PSQL_SUPER[@]}" < "$WORK/rollback.sql" >/dev/null && ok "verificador anterior restaurado en PostgreSQL"
}

rollback_all() {
    fail "revirtiendo…"
    pg_rollback || true
    env_restore "$BACKUP" || true
    reload_consumers || true
    fail "POSTGRESQL ROTATION = FAIL (estado anterior restaurado)"
    exit 1
}

# ── 4. ¿Autentica la credencial nueva? ─────────────────────────────────────
PGPASS="$WORK/pgpass"
{ printf '%s:%s:%s:%s:' "$DB_HOST" "$DB_PORT" "$DB_NAME" "$DB_ROLE"; cat "$NEWPASS"; printf '\n'; } > "$PGPASS"
chmod 600 "$PGPASS"

if ! PGPASSFILE="$PGPASS" psql -X -q -h "$DB_HOST" -p "$DB_PORT" -U "$DB_ROLE" -d "$DB_NAME" -tAc "SELECT 1" >/dev/null 2>&1; then
    fail "la credencial nueva NO autentica contra PostgreSQL"
    rollback_all
fi
ok "la credencial nueva autentica por TCP+scram"

# ── 5. .env + recarga ──────────────────────────────────────────────────────
hdr "POSTGRES — .env y consumidores"
env_set DB_PASSWORD "$NEWPASS" || rollback_all
reload_consumers || rollback_all
WINDOW_END="$(date +%s%3N)"

want="len=$(secret_len "$NEWPASS") sha=$(secret_sha "$NEWPASS")"
got="$(config_sha 'database.connections.pgsql.password')"
[ "$want" = "$got" ] || { fail "Laravel no lee la contraseña nueva (esperado [$want], leído [$got])"; rollback_all; }
ok "Laravel lee la contraseña nueva → $got"
log "ventana de reconexión: $((WINDOW_END - WINDOW_START)) ms"

# ── 6. Que la contraseña vieja ya no valga ─────────────────────────────────
hdr "POSTGRES — la credencial anterior está muerta"
OLDPASS_TEST="$WORK/oldpass"
# No conocemos la contraseña anterior en claro (nunca se leyó), así que lo que
# se comprueba es que el verificador almacenado CAMBIÓ: es la única afirmación
# que se puede sostener con evidencia.
NOWVERIFIER="$("${PSQL_SUPER[@]}" -tAc "SELECT rolpassword FROM pg_authid WHERE rolname='$DB_ROLE'")"
if [ "$NOWVERIFIER" = "$(cat "$OLDVERIFIER")" ]; then
    fail "el verificador almacenado no cambió"
    rollback_all
fi
ok "el verificador SCRAM almacenado es distinto del anterior"
rm -f "$OLDPASS_TEST" 2>/dev/null || true

# ── 7. Salud completa ──────────────────────────────────────────────────────
hdr "POSTGRES — salud del sistema"
FAILED=0

db_ok="$(cd "$APP_DIR" && sudo -u "$RUN_AS" php -r '
    require "vendor/autoload.php"; $a = require "bootstrap/app.php";
    $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    try { echo Illuminate\Support\Facades\DB::selectOne("select count(*) c from members")->c > 0 ? "si" : "vacio"; }
    catch (Throwable $e) { echo "no"; }
')"
[ "$db_ok" = "si" ] && ok "consulta real desde Laravel (members) OK" || { fail "consulta desde Laravel: $db_ok"; FAILED=1; }

health_check || FAILED=1

# El backup depende de peer, no de la contraseña: se comprueba, no se supone.
"${PSQL_SUPER[@]}" -d "$DB_NAME" -tAc "SELECT count(*) FROM pg_stat_user_tables" >/dev/null \
    && ok "acceso peer de postgres a $DB_NAME intacto (respaldos)" || { fail "el acceso peer se rompió"; FAILED=1; }

workers="$(supervisorctl status 2>/dev/null | grep -c RUNNING || echo 0)"
log "workers supervisor vivos: $workers"
[ "$workers" -ge 1 ] || { fail "no hay workers vivos"; FAILED=1; }

systemctl is-active --quiet ironbody-billing-worker && ok "ironbody-billing-worker activo" || { fail "billing worker caído"; FAILED=1; }

[ "$FAILED" -eq 0 ] || rollback_all

printf '\n\033[1;32mPOSTGRESQL ROTATION = PASS\033[0m\n'
log "copia previa del .env: $BACKUP"
log "la contraseña nueva no se ha mostrado en ningún momento y sólo vive en $ENV_FILE (0600)"

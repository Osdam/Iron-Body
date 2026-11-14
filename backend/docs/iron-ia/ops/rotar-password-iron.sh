#!/usr/bin/env bash
# Rotación de la contraseña del rol DUEÑO de PostgreSQL (`iron`).
#
# Por qué: el envoltorio ironbody-migrate antiguo la pasaba por la línea de órdenes de sudo,
# que la dejó en claro en /var/log/auth.log y en el journal (13-11-2026). Además vive en
# copias antiguas de .env (antes del 18-10 la app se conectaba como `iron`). Rotarla deja
# todas esas copias inservibles sin borrar nada.
#
# Consumidores comprobados (13-11): SOLO /root/.ironbody-owner.env (ironbody-migrate). La app
# es iron_app; n8n tiene su propio PostgreSQL (usuario n8n). 0 sesiones de `iron` abiertas.
#
# Se ejecuta EN EL SERVIDOR como root:  bash rotar-password-iron.sh <ruta de probar-ironbody-migrate.sh>
#
# Nunca imprime ni pasa por argumentos la contraseña: se genera en una variable, PostgreSQL
# recibe SOLO el verificador SCRAM (un hash) por la entrada estándar, y las comprobaciones
# la pasan por el entorno. Si cualquier comprobación falla, se restaura sola la anterior
# (verificador y fichero) y el script termina con error.
set -euo pipefail
export LC_ALL=C
umask 077

[ "$(id -u)" = 0 ] || { echo "se ejecuta como root"; exit 2; }
PROBAR="${1:-}"
[ -r "$PROBAR" ] || { echo "uso: $0 <probar-ironbody-migrate.sh>"; exit 2; }
ENVF=/root/.ironbody-owner.env
B=/var/www/api/backend
TS=$(date -u +%Y%m%d-%H%M%S)
RB=/var/backups/ironbody/rotacion-iron-$TS
PSQL() { ( cd /tmp && sudo -u postgres psql -X -q -v ON_ERROR_STOP=1 -d postgres "$@" ); }

echo "0. comprobaciones previas"
[ -r "$ENVF" ] || { echo "falta $ENVF"; exit 1; }
[ "$(PSQL -Atc "select current_setting('password_encryption')")" = scram-sha-256 ] || { echo "password_encryption no es scram-sha-256"; exit 1; }
[ "$(PSQL -Atc "select count(*) from pg_roles where rolname='iron' and rolcanlogin and not rolsuper")" = 1 ] || { echo "el rol iron no es el esperado"; exit 1; }
echo "   sesiones abiertas de iron: $(PSQL -Atc "select count(*) from pg_stat_activity where usename='iron'")"

echo "1. respaldo para volver atrás ($RB, root 700)"
mkdir -p "$RB"
PSQL -Atc "select rolpassword from pg_authid where rolname='iron'" > "$RB/verificador-anterior"
cp -p "$ENVF" "$RB/ironbody-owner.env"
grep -q '^SCRAM-SHA-256\$' "$RB/verificador-anterior" || { echo "no se pudo guardar el verificador anterior"; exit 1; }

restaurar() {
  echo "!! restaurando la contraseña anterior"
  { printf "ALTER ROLE iron PASSWORD '%s';\n" "$(cat "$RB/verificador-anterior")"; } | PSQL
  cp -p "$RB/ironbody-owner.env" "$ENVF"
  echo "!! restaurada; nada cambió"
}

echo "2. contraseña nueva y su verificador SCRAM (la contraseña no sale de esta shell)"
NUEVA=$(openssl rand -hex 24)
VERIF=$(NUEVA="$NUEVA" python3 -I -c '
import os, hashlib, hmac, base64
pw = os.environ["NUEVA"].encode()
salt = os.urandom(16); it = 4096
salted = hashlib.pbkdf2_hmac("sha256", pw, salt, it)
ck = hmac.new(salted, b"Client Key", "sha256").digest()
sk = hmac.new(salted, b"Server Key", "sha256").digest()
b = lambda x: base64.b64encode(x).decode()
print(f"SCRAM-SHA-256${it}:{b(salt)}${b(hashlib.sha256(ck).digest())}:{b(sk)}")')
[[ "$VERIF" =~ ^SCRAM-SHA-256\$4096: ]] || { echo "verificador inválido; nada cambió"; exit 1; }

echo "3. aplicar en PostgreSQL y en $ENVF (atómico)"
{ printf "ALTER ROLE iron PASSWORD '%s';\n" "$VERIF"; } | PSQL
TMP=$(mktemp /root/.ironbody-owner.env.XXXXXX)
{ printf 'DB_OWNER_USERNAME=iron\n'; printf "DB_OWNER_PASSWORD='%s'\n" "$NUEVA"; } > "$TMP"
chmod 600 "$TMP"; chown root:root "$TMP"; mv "$TMP" "$ENVF"

echo "4. comprobaciones"
con() { # $1 = fichero con DB_OWNER_*; imprime el usuario conectado o el código de error
  ( set -a; . "$1"; set +a; cd "$B"
    php -r '$c = (require "bootstrap/cache/config.php")["database"]["connections"]["pgsql"];
      try { $p = new PDO("pgsql:host={$c["host"]};port={$c["port"]};dbname={$c["database"]}", getenv("DB_OWNER_USERNAME"), getenv("DB_OWNER_PASSWORD"));
        echo $p->query("select current_user")->fetchColumn(); } catch (Throwable $e) { echo "ERROR:", $e->getCode(); }' )
}
fallos=0
r=$(con "$ENVF"); [ "$r" = iron ] && echo "   OK la nueva conecta como iron" || { echo "   FALLO la nueva no conecta ($r)"; fallos=1; }
r=$(con "$RB/ironbody-owner.env"); case "$r" in ERROR:*) echo "   OK la anterior queda rechazada ($r)";; *) echo "   FALLO la anterior sigue entrando ($r)"; fallos=1;; esac
if [ "$fallos" = 0 ]; then
  if bash "$PROBAR" /usr/local/bin/ironbody-migrate > "$RB/probar.txt" 2>&1; then
    echo "   OK ironbody-migrate con la nueva: $(grep -c '^OK' "$RB/probar.txt") comprobaciones"
  else
    echo "   FALLO ironbody-migrate:"; grep -E '^(FALLO|PARADA)' "$RB/probar.txt"; fallos=1
  fi
fi
n=$( { cat /var/log/auth.log; journalctl -q --no-pager --since today 2>/dev/null; cat /var/log/postgresql/*.log 2>/dev/null; } \
  | BUSCA="$NUEVA" awk 'index($0, ENVIRON["BUSCA"]) > 0 { c++ } END { print c + 0 }')
[ "$n" = 0 ] && echo "   OK la nueva no aparece en auth.log, el journal ni los registros de PostgreSQL" || { echo "   FALLO la nueva aparece $n veces en registros"; fallos=1; }

if [ "$fallos" != 0 ]; then restaurar; exit 1; fi

# La anterior está comprometida (registros): no se guarda para volver a ella.
rm -f "$RB/ironbody-owner.env" "$RB/verificador-anterior"
unset NUEVA VERIF
echo "== rotada. Copias antiguas de .env con la anterior: ya no sirven para entrar. Rastro: $RB/probar.txt"

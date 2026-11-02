#!/bin/bash
# Ensayo de la propuesta de ironbody-migrate (copia del banco, preparar.sh) sobre un banco ya desplegado. Lo que el
# banco NO puede probar (no hay usuarios de sistema): el cambio de usuario de setpriv, sudo y flock reales; eso es el
# `--check` en el servidor. Lo que SÍ prueba: que el proceso que migra no ejecuta las cachés de www-data (un marcador
# plantado en services.php y packages.php), la lista blanca de PGOPTIONS, la credencial (modo y claves), que el destino
# de la base sale de la credencial y no del .env, que una variable PG* del .env aborta, el .env escribible y el código
# escribible o enlazado (find -L), y que la caché de configuración nunca recibe un chmod a través de un enlace (H-5).
# Uso: bash ensayo-migrador.sh <banco>    (código 0 = todos los casos OK; deja el banco como lo encontró)
. "$(dirname "$0")/comun.sh"
B=$(banco_dir "${1:-}")
cargar_banco "$B"
bash "$AQUI/preparar.sh" "$B" > /dev/null
export PATH="$B/bin:$PATH"
M="$B/root/ironbody-migrate"
APPD="$B/api/backend"
CRED="$B/etc/ironbody-migrate/owner.env"
LOGM="$B/logs/migrador"; mkdir -p "$LOGM"
mig() { local n=$1; shift; local rc=0; env "$@" bash "$M" ${ARGS:-} > "$LOGM/$n.log" 2>&1 || rc=$?; echo "$rc"; }
cp -p "$APPD/.env" "$B/run/env.orig"; cp -p "$CRED" "$B/run/cred.orig"
restaura() { cp -p "$B/run/env.orig" "$APPD/.env"; cp -p "$B/run/cred.orig" "$CRED"; chmod 600 "$APPD/.env" "$CRED"; }
trap restaura EXIT

echo "== propuesta de ironbody-migrate en el banco ($BANCO_DB)"
ARGS=--check; RC=$(mig check); caso "--check: migrate:status con el rol dueño → 0" "$RC" "$(grep -c 'Ran' "$LOGM/check.log") migraciones Ran"
ARGS=--pretend; RC=$(mig pretend); caso "--pretend: nada pendiente → 0" "$( [ "$RC" = 0 ] && grep -q 'Nothing to migrate' "$LOGM/pretend.log" && echo 0 || echo 1)" "rc $RC"
ARGS=--status; RC=$(mig status); caso "--status → 0" "$RC"

# 1. El marcador: las cachés de arranque de www-data (bootstrap/cache) llevan código que apunta quién lo ejecuta.
MARCA="$B/run/marcador"; : > "$MARCA"
for f in services.php packages.php; do
  cp -p "$APPD/bootstrap/cache/$f" "$B/run/$f.orig"
  python3 - "$APPD/bootstrap/cache/$f" "$MARCA" <<'PY'
import sys
p, m = sys.argv[1:3]
s = open(p, encoding='utf-8').read()
assert s.startswith('<?php')
open(p, 'w', encoding='utf-8').write("<?php file_put_contents(" + repr(m) + ", implode(' ', array_slice($_SERVER['argv'] ?? [], 0, 2)) . PHP_EOL, FILE_APPEND);" + s[5:])
PY
done
( cd "$APPD" && php artisan migrate:status --database=pgsql_owner > /dev/null 2>&1 || true )
VISTO=$(sort -u "$MARCA" | tr '\n' ';')
caso "control: un artisan normal (lo que hace hoy el envoltorio como root) SÍ ejecuta las cachés de www-data" "$( [ -s "$MARCA" ] && echo 0 || echo 1)" "$VISTO"
: > "$MARCA"
ARGS=--status; RC=$(mig marcador-status)
caso "la propuesta (--status): el proceso del dueño NO ejecuta ninguna caché de www-data" "$( [ "$RC" = 0 ] && [ ! -s "$MARCA" ] && echo 0 || echo 1)" "rc $RC · marcador: $(sort -u "$MARCA" | tr '\n' ';')"
: > "$MARCA"
ARGS=; RC=$(mig marcador-migrate)
SOLO=$(sort -u "$MARCA" | tr '\n' ';')
caso "la propuesta (migrar): solo la regeneración de la caché, como www-data, ejecuta lo de www-data" "$( [ "$RC" = 0 ] && [ "$SOLO" = 'artisan config:cache;' ] && echo 0 || echo 1)" "rc $RC · marcador: $SOLO"
for f in services.php packages.php; do cp -p "$B/run/$f.orig" "$APPD/bootstrap/cache/$f"; done
caso "la caché de configuración regenerada: del usuario de www-data y 600" "$( [ "$(stat -f '%Lp' "$APPD/bootstrap/cache/config.php")" = 600 ] && echo 0 || echo 1)" "$(stat -f '%Su %Lp' "$APPD/bootstrap/cache/config.php")"

# 2. PGOPTIONS: solo «-c lock_timeout=N(ms|s)».
ARGS=--status; RC=$(mig pgopt-mal PGOPTIONS='-c search_path=pg_temp,public'); caso "PGOPTIONS con search_path → 2 sin tocar nada" "$( [ "$RC" = 2 ] && echo 0 || echo 1)" "rc $RC"
ARGS=--status; RC=$(mig pgopt-dos PGOPTIONS='-c lock_timeout=5s -c statement_timeout=0'); caso "PGOPTIONS con dos opciones → 2" "$( [ "$RC" = 2 ] && echo 0 || echo 1)" "rc $RC"
ARGS=--status; RC=$(mig pgopt-bien PGOPTIONS='-c lock_timeout=2s'); caso "PGOPTIONS «-c lock_timeout=2s» → se respeta (0)" "$( [ "$RC" = 0 ] && grep -q 'lock_timeout=2s' "$LOGM/pgopt-bien.log" && echo 0 || echo 1)" "rc $RC"

# 3. La credencial: modo y claves.
chmod 644 "$CRED"; ARGS=--status; RC=$(mig cred-644); restaura
caso "credencial en 644 → 2" "$( [ "$RC" = 2 ] && echo 0 || echo 1)" "rc $RC"
printf 'APP_KEY=base64:%s\n' "$(openssl rand -base64 32)" >> "$CRED"; ARGS=--status; RC=$(mig cred-clave); restaura
caso "credencial con una clave no admitida (APP_KEY) → 2" "$( [ "$RC" = 2 ] && grep -q 'clave no admitida en la credencial: APP_KEY' "$LOGM/cred-clave.log" && echo 0 || echo 1)" "rc $RC"
grep -v '^DB_OWNER_PASSWORD=' "$B/run/cred.orig" > "$CRED"; ARGS=--status; RC=$(mig cred-falta); restaura
caso "credencial sin DB_OWNER_PASSWORD → 2" "$( [ "$RC" = 2 ] && echo 0 || echo 1)" "rc $RC"

# 4. El .env: el destino de la base no sale de él; una variable PG* aborta; escribible por otros aborta.
python3 - "$APPD/.env" <<'PY'
import sys, re
p = sys.argv[1]; s = open(p, encoding='utf-8').read()
s = re.sub(r'(?m)^DB_HOST=.*$', 'DB_HOST=127.0.0.2', s); s = re.sub(r'(?m)^DB_PORT=.*$', 'DB_PORT=1', s)
open(p, 'w', encoding='utf-8').write(s)
PY
ARGS=--status; RC=$(mig env-destino); restaura
caso "un .env con DB_HOST/DB_PORT falsos no desvía al dueño: la credencial manda (0)" "$RC" "rc $RC"
printf '  PGHOSTADDR=127.0.0.2\n' >> "$APPD/.env"; ARGS=--status; RC=$(mig env-pghostaddr); restaura
caso "un .env con PGHOSTADDR → 2 (libpq lo leería del entorno)" "$( [ "$RC" = 2 ] && grep -q 'el entorno trae PGHOSTADDR' "$LOGM/env-pghostaddr.log" && echo 0 || echo 1)" "rc $RC"
chmod 620 "$APPD/.env"; ARGS=--status; RC=$(mig env-escribible); restaura
caso "un .env escribible por el grupo → 2" "$( [ "$RC" = 2 ] && echo 0 || echo 1)" "rc $RC"

# 5. El código: escribible por otro, o un enlace a algo escribible (find -L).
F="$APPD/app/Console/Commands/UltronStudio/MonitorCommand.php"; chmod 664 "$F"; ARGS=--status; RC=$(mig codigo-escribible); chmod 644 "$F"
caso "un fichero de app/ escribible por el grupo → 2" "$( [ "$RC" = 2 ] && grep -q 'MonitorCommand.php' "$LOGM/codigo-escribible.log" && echo 0 || echo 1)" "rc $RC"
OBJ="$B/run/objetivo-666.php"; printf '<?php\n' > "$OBJ"; chmod 666 "$OBJ"; ln -s "$OBJ" "$APPD/app/zz-enlace-banco.php"
ARGS=--status; RC=$(mig codigo-enlace); rm -f "$APPD/app/zz-enlace-banco.php" "$OBJ"
caso "un enlace en app/ a un fichero que otro puede escribir → 2 (find -L)" "$( [ "$RC" = 2 ] && grep -q 'zz-enlace-banco.php' "$LOGM/codigo-enlace.log" && echo 0 || echo 1)" "rc $RC"

# 6. H-5: si la caché de configuración resulta ser un enlace, nadie hace chmod a través de él.
CFG="$APPD/bootstrap/cache/config.php"; cp -p "$CFG" "$B/run/config.orig"
DEST="$B/run/destino-644"; printf 'x\n' > "$DEST"; chmod 644 "$DEST"; rm -f "$CFG"; ln -s "$DEST" "$CFG"
ARGS=; RC=$(mig h5-enlace)
# Con un destino que no es una caché, Laravel ni arranca (código 1); lo que se prueba es que NADIE cambia el destino.
caso "caché convertida en enlace a otro fichero: el destino SIGUE en 644 (ningún chmod a través del enlace)" "$( [ "$RC" != 0 ] && [ "$(stat -f '%Lp' "$DEST")" = 644 ] && ! grep -q 'pasado a 600' "$LOGM/h5-enlace.log" && echo 0 || echo 1)" "rc $RC · destino $(stat -f '%Lp' "$DEST")"
rm -f "$CFG" "$DEST"
# Con un destino que sí es una caché válida (644): config:cache borra el enlace y escribe un fichero nuevo; el destino
# no se toca y la caché queda como fichero normal en 600.
cp -p "$B/run/config.orig" "$DEST"; chmod 644 "$DEST"; ln -s "$DEST" "$CFG"
ARGS=; RC=$(mig h5-enlace-valido)
caso "enlace a una caché válida: la caché vuelve a ser un fichero normal en 600 y el destino sigue en 644" "$( [ "$RC" = 0 ] && [ ! -L "$CFG" ] && [ "$(stat -f '%Lp' "$CFG")" = 600 ] && [ "$(stat -f '%Lp' "$DEST")" = 644 ] && echo 0 || echo 1)" "rc $RC · caché $(stat -f '%Lp' "$CFG") · destino $(stat -f '%Lp' "$DEST")"
rm -f "$CFG" "$DEST"; cp -p "$B/run/config.orig" "$CFG"; chmod 600 "$CFG"

# 7. El registro de cada ejecución.
N=$(wc -l < "$B/logs/ironbody-migrate.log" | tr -d ' ')
caso "una línea por ejecución en el registro del envoltorio, sin valores" "$( [ "$N" -ge 10 ] && ! grep -qiE 'password|banco-sin-uso|base64:' "$B/logs/ironbody-migrate.log" && echo 0 || echo 1)" "$N líneas"
echo "PROPUESTA DE IRONBODY-MIGRATE EN EL BANCO: $((CASOS - FALLOS))/$CASOS OK"
[ "$FALLOS" = 0 ]

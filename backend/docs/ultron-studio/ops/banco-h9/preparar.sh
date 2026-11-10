#!/bin/bash
# Copias del banco del Hito 9 de deploy-h9-backend-fase1.sh, de ironbody-migrate-propuesta.sh (el migrador del banco,
# como en el H7 y el H8) y de h9-outreach-scope.sh (el mando) en $B/root. Solo cambian rutas, psql, la URL, los roles
# (iron → h5_owner, iron_app → h5_app), los dueños esperados (root/www-data → el usuario del Mac), el canal del registro,
# la comprobación de root, el directorio del registro, el volumen de PostgreSQL y el valor de PREVIO (el de la
# «producción» del banco). La LÓGICA es la del repo: cada sustitución tiene que encontrar su texto exacto (si un script
# del repo cambia y una ya no casa, preparar FALLA en vez de ensayar otra cosa) y al final se dice cuántas líneas
# cambiaron. Es el preparar.sh del banco del H8 con lo del H9.
# Uso: bash preparar.sh <banco>       (ensayar.sh lo hace solo antes de cada `desplegar` y cada `alcance`)
. "$(dirname "$0")/comun.sh"
B=$(banco_dir "${1:-}")
cargar_banco "$B"
ME=$(whoami)
URL="http://127.0.0.1:$BANCO_PORT/api"
BPSQL="psql -X -Atq -v ON_ERROR_STOP=1 -h $PG_HOST -p $PG_PORT -U $PG_USER -d $BANCO_DB"
PHPBIN=$(command -v php)
mkdir -p "$B/root"
GRUPO=$(id -gn)
python3 -I - "$OPS" "$B" "$ME" "$URL" "$BPSQL" "$PHPBIN" "$PREVIO_BANCO" "$GRUPO" <<'PY'
import re
import sys
ops, b, me, url, bpsql, php, previo, grupo = sys.argv[1:9]
def sustituye(nombre, reglas):
    s = open(f'{ops}/{nombre}', encoding='utf-8').read()
    for viejo, nuevo, veces in reglas:
        if isinstance(viejo, re.Pattern):
            n = len(viejo.findall(s))
            if n == 0 or (veces and n != veces):
                sys.exit(f'preparar: /{viejo.pattern[:70]}/ casa {n} veces en {nombre} (esperado {veces or "≥ 1"}): el script cambió')
            s = viejo.sub(nuevo, s)
            continue
        n = s.count(viejo)
        if n == 0 or (veces and n != veces):
            sys.exit(f'preparar: «{viejo[:70]}» aparece {n} veces en {nombre} (esperado {veces or "≥ 1"}): el script cambió')
        s = s.replace(viejo, nuevo)
    return s
comunes = [('/var/www/api', f'{b}/api', 0)]
deploy = sustituye('deploy-h9-backend-fase1.sh', comunes + [
    ('https://api.ironbodyneiva.cloud/api', url, 0),
    ('sudo -u postgres psql -X -Atq -v ON_ERROR_STOP=1 -d ironbody', bpsql, 1),
    ('[ "$(id -u)" = 0 ]', 'true', 1),
    (re.compile(r'^PREVIO=(\S*).*$', re.M), lambda m: f'PREVIO={previo[:7]}   # BANCO: la «producción» del banco (en el repo, PREVIO={m.group(1)})', 1),
    ('LOGDIR=/root', f'LOGDIR={b}/root', 1),
    ('/usr/local/bin/ironbody-migrate', f'{b}/root/ironbody-migrate', 0),
    ("'iron_app'", "'h5_app'", 0),
    ("has_schema_privilege('iron'", "has_schema_privilege('h5_owner'", 1),
    ('$ESQ_PARTIDA iron iron true 1', '$ESQ_PARTIDA h5_owner h5_owner true 1', 0),
    ('FUNC_ESPERADO="33 0 1 iron"', 'FUNC_ESPERADO="33 0 1 h5_owner"', 1),
    ("printf '%s:iron ' \"$t\"", "printf '%s:h5_owner ' \"$t\"", 1),
    ('= www-data ]', f'= {me} ]', 0),
    ('production.ERROR', 'local.ERROR', 0),
    ('df -Pk /var/lib/postgresql', 'df -Pk /', 1),
])
open(f'{b}/root/deploy-h9-backend-fase1.sh', 'w', encoding='utf-8').write(deploy)
# El migrador: la propuesta de ironbody-migrate del H7, con las mismas sustituciones que su banco (banco-h8/preparar.sh).
migrador = sustituye('ironbody-migrate-propuesta.sh', comunes + [
    ('CRED=/etc/ironbody-migrate/owner.env', f'CRED={b}/etc/ironbody-migrate/owner.env', 1),
    ('LOG=/var/log/ironbody-migrate.log', f'LOG={b}/logs/ironbody-migrate.log', 1),
    ('CERROJO=/run/lock/ironbody-migrate.lock', f'CERROJO={b}/run/ironbody-migrate.lock', 1),
    ('RUNBASE=/run', f'RUNBASE={b}/run', 1),
    ('WWW=www-data', f'WWW={me}', 1),
    ('PHP=/usr/bin/php', f'PHP={php}', 1),
    ('DUENO_ARBOL=root', f'DUENO_ARBOL={me}', 1),
    ('como() { local u=$1; shift; setpriv --reuid="$u" --regid="$u" --init-groups --no-new-privs --inh-caps=-all -- "$@"; }',
     'como() { shift; "$@"; }   # BANCO: sin cambio de usuario (el Mac no tiene setpriv ni los usuarios de sistema)', 1),
    ('es_root() { [ "$(id -u)" = 0 ]; }', 'es_root() { true; }', 1),
    ('command -v setpriv > /dev/null || muere "falta setpriv (util-linux)"', 'true', 1),
    ('ENT=$(getent passwd "$USUARIO" || true)', 'ENT="ironbody-migrate:x:990:990::/nonexistent:/usr/sbin/nologin"', 1),
    ('for g in $(id -nG "$USUARIO"); do', 'for g in ironbody-migrate; do', 1),
    ('= "$USUARIO 600" ]', f'= "{me} 600" ]', 1),
    ('chown "$USUARIO:$USUARIO" "$RUN" && chmod 700 "$RUN"', 'chmod 700 "$RUN"', 1),
    ('install -d -m 700 -o "$USUARIO" -g "$USUARIO"', 'install -d -m 700', 1),
    ('install -m 600 -o "$USUARIO" -g "$USUARIO" .env', 'install -m 600 .env', 1),
    ('-perm /022', '-perm +022', 2),
    ('[ "$D" = / ] && break', f'[ "$D" = "{b}" ] && break', 1),
    # El REVOKE de DB7, DB8 y DB9 nombra el rol de la aplicación: en el banco es h5_app (en producción, iron_app).
    ('LOG_CHANNEL=stderr PGOPTIONS="$PGOPT"', 'LOG_CHANNEL=stderr ULTRON_STUDIO_DB_APP_ROLE=h5_app PGOPTIONS="$PGOPT"', 1),
])
open(f'{b}/root/ironbody-migrate', 'w', encoding='utf-8').write(migrador)
# El mando: el bloque «lo propio de producción» y la comprobación de root.
mando = sustituye('h9-outreach-scope.sh', [
    ('APPDIR=/var/www/api/backend', f'APPDIR={b}/api/backend', 1), ('WWW=www-data', f'WWW={me}', 1), ('[ "$(id -u)" = 0 ]', 'true', 1),
    ('API=https://api.ironbodyneiva.cloud/api', f'API={url}', 1), ('ROOT_U=root', f'ROOT_U={me}', 1), ('ROOT_G=root', f'ROOT_G={grupo}', 1),
    ('COPIAS=/root', f'COPIAS={b}/root', 1), ('CERROJO=/run/lock/h9-outreach-scope.lock', f'CERROJO={b}/run/h9-outreach-scope.lock', 1),
    ('NIVEL_ERROR=production.ERROR', 'NIVEL_ERROR=local.ERROR', 1),
])
open(f'{b}/root/h9-outreach-scope.sh', 'w', encoding='utf-8').write(mando)
PY
chmod 700 "$B/root/deploy-h9-backend-fase1.sh" "$B/root/ironbody-migrate" "$B/root/h9-outreach-scope.sh"
for f in deploy-h9-backend-fase1.sh:deploy-h9-backend-fase1.sh ironbody-migrate-propuesta.sh:ironbody-migrate h9-outreach-scope.sh:h9-outreach-scope.sh; do
  o=${f%%:*}; c=${f#*:}
  bash -n "$B/root/$c"
  echo "== $o → $B/root/$c: $(diff "$OPS/$o" "$B/root/$c" | grep -c '^>' || true) líneas con sustituciones del banco"
done

#!/bin/bash
# Copias del banco de deploy-h7-backend.sh, h7-monitor-scope.sh e ironbody-migrate-propuesta.sh en $B/root. Solo
# cambian rutas, psql, la URL, los roles (iron → h5_owner, iron_app → h5_app), los dueños esperados (root/www-data →
# el usuario del Mac), el canal del log, la comprobación de root, el valor de PREVIO, y en la propuesta lo que el Mac
# no tiene (setpriv, getent, usuarios de sistema, find de GNU, /run). La LÓGICA es la del repo: cada sustitución tiene
# que encontrar su texto exacto (si un script del repo cambia y una ya no casa, preparar FALLA en vez de ensayar otra
# cosa) y el diff se enseña al final.
# PREVIO (T-1 del tester): la línea «PREVIO=…» del repo se sustituye SEA CUAL SEA su valor (pendiente o el commit que
# fije el lead) por el de la «producción» del banco, y tiene que haber exactamente una: el banco ensaya el guion que
# irá a producción, con la única diferencia de ese valor (y el del repo queda anotado en la propia línea).
# Uso: bash preparar.sh <banco>       (se repite cada vez que cambian los scripts del repo; ensayar.sh lo hace solo)
. "$(dirname "$0")/comun.sh"
B=$(banco_dir "${1:-}")
cargar_banco "$B"
ME=$(whoami)
URL="http://127.0.0.1:$BANCO_PORT/api"
BPSQL="psql -X -Atq -v ON_ERROR_STOP=1 -h $PG_HOST -p $PG_PORT -U $PG_USER -d $BANCO_DB"
PHPBIN=$(command -v php)
python3 - "$OPS" "$B" "$ME" "$URL" "$BPSQL" "$PHPBIN" "$PREVIO_BANCO" <<'PY'
import re
import sys
ops, b, me, url, bpsql, php, previo = sys.argv[1:8]
def sustituye(nombre, reglas):
    s = open(f'{ops}/{nombre}', encoding='utf-8').read()
    for viejo, nuevo, veces in reglas:
        if isinstance(viejo, re.Pattern):          # regla por expresión regular (multilínea): la misma exigencia de veces
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
comunes = [
    ('/var/www/api', f'{b}/api', 0),
]
deploy = sustituye('deploy-h7-backend.sh', comunes + [
    ('https://api.ironbodyneiva.cloud/api', url, 0),
    ('sudo -u postgres psql -X -Atq -v ON_ERROR_STOP=1 -d ironbody', bpsql, 1),
    ('[ "$(id -u)" = 0 ]', 'true', 1),
    (re.compile(r'^PREVIO=(\S*).*$', re.M), lambda m: f'PREVIO={previo}   # BANCO: la «producción» del banco (en el repo, PREVIO={m.group(1)})', 1),
    ('/usr/local/bin/ironbody-migrate', f'{b}/bin/ironbody-migrate', 1),
    ("'iron_app'", "'h5_app'", 0),
    ("has_schema_privilege('iron'", "has_schema_privilege('h5_owner'", 1),
    ('43 49 4 14 iron iron,iron,iron true 1 0', '43 49 4 14 h5_owner h5_owner,h5_owner,h5_owner true 1 0', 0),
    ('" iron iron,iron,iron true 1 "', '" h5_owner h5_owner,h5_owner,h5_owner true 1 "', 1),
    ('CHECK ((seq >= 1)) iron/false 0', 'CHECK ((seq >= 1)) h5_owner/false 0', 0),
    ('= "root 600"', f'= "{me} 600"', 2),
    ('= www-data ]', f'= {me} ]', 0),
    ('production.ERROR', 'local.ERROR', 0),
])
open(f'{b}/root/deploy-h7-backend.sh', 'w', encoding='utf-8').write(deploy)
# El mando del monitor: la copia previa y el temporal van al /root del banco; install sin -o root (el Mac no es root);
# la caché la «escribe www-data», que en el banco es el usuario del Mac. crontab -l -u … falla sin privilegios: el
# aviso H-1 sale como «no aparece», que es lo que hay en el Mac.
mando = sustituye('h7-monitor-scope.sh', comunes + [
    ('https://api.ironbodyneiva.cloud/api', url, 1),
    ('[ "$(id -u)" = 0 ]', 'true', 1),
    ('/root/', f'{b}/root/', 0),
    ('install -m 600 -o root -g root', 'install -m 600', 1),
    ('= www-data ]', f'= {me} ]', 1),
])
open(f'{b}/root/h7-monitor-scope.sh', 'w', encoding='utf-8').write(mando)
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
    ('-perm /022', '-perm +022', 2),   # la orden y el comentario de la instalación
    ('[ "$D" = / ] && break', f'[ "$D" = "{b}" ] && break', 1),
    # El REVOKE de DB7 nombra el rol de la aplicación: en el banco es h5_app (en producción, iron_app, el de defecto).
    ('LOG_CHANNEL=stderr PGOPTIONS="$PGOPT"', 'LOG_CHANNEL=stderr ULTRON_STUDIO_DB_APP_ROLE=h5_app PGOPTIONS="$PGOPT"', 1),
])
open(f'{b}/root/ironbody-migrate', 'w', encoding='utf-8').write(migrador)
PY
chmod 700 "$B/root/deploy-h7-backend.sh" "$B/root/h7-monitor-scope.sh" "$B/root/ironbody-migrate"
for f in deploy-h7-backend.sh:deploy-h7-backend.sh h7-monitor-scope.sh:h7-monitor-scope.sh ironbody-migrate-propuesta.sh:ironbody-migrate; do
  o=${f%%:*}; c=${f#*:}
  bash -n "$B/root/$c"
  echo "== $o → $B/root/$c: $(diff "$OPS/$o" "$B/root/$c" | grep -c '^>' || true) líneas con sustituciones del banco"
done

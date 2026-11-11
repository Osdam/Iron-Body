#!/bin/bash
# Copias del banco del Hito 8 de deploy-h8-backend-fase1.sh y de ironbody-migrate-propuesta.sh (el migrador del banco,
# como en el H7) en $B/root, (OPS8-e) de los mandos del H6 y del H7 (h6-integrations-scope.sh y h7-monitor-scope.sh: B-T13 y
# B-T14) y del de la rotación del token del OTP (h8-otp-token-rotate.sh, contra la cuenta maestra del simulador), y (OPS8-c) de los tres de las fases 2 → 4a: h8-voice-scope.sh (el mando),
# h8-secrets-register.sh (contra el simulador de ElevenLabs) y h8-twilio-check.sh (contra el de Twilio). En estos tres
# solo cambia su bloque «lo propio de producción» (rutas, URL, usuarios, cerrojo, hosts de los proveedores) y la
# comprobación de root. Solo cambian rutas, psql, la URL, los roles (iron → h5_owner, iron_app → h5_app), los dueños
# esperados (root/www-data → el usuario del Mac), el canal del registro, la comprobación de root, el directorio del
# registro y el valor de PREVIO (el de la «producción» del banco). La LÓGICA es la del repo: cada sustitución tiene que
# encontrar su texto exacto (si un script del repo cambia y una ya no casa, preparar FALLA en vez de ensayar otra cosa)
# y al final se dice cuántas líneas cambiaron. Es el preparar.sh del banco del H7 (banco-h7/preparar.sh) con lo del H8.
# Uso: bash preparar.sh <banco>       (ensayar.sh lo hace solo antes de cada `desplegar`)
. "$(dirname "$0")/comun.sh"
B=$(banco_dir "${1:-}")
cargar_banco "$B"
ME=$(whoami)
URL="http://127.0.0.1:$BANCO_PORT/api"
BPSQL="psql -X -Atq -v ON_ERROR_STOP=1 -h $PG_HOST -p $PG_PORT -U $PG_USER -d $BANCO_DB"
PHPBIN=$(command -v php)
mkdir -p "$B/root"
GRUPO=$(id -gn)
python3 -I - "$OPS" "$B" "$ME" "$URL" "$BPSQL" "$PHPBIN" "$PREVIO_BANCO" "$GRUPO" "$PUERTO_EL" "$PUERTO_TW" <<'PY'
import re
import sys
ops, b, me, url, bpsql, php, previo, grupo, puerto_el, puerto_tw = sys.argv[1:11]
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
deploy = sustituye('deploy-h8-backend-fase1.sh', comunes + [
    ('https://api.ironbodyneiva.cloud/api', url, 0),
    ('sudo -u postgres psql -X -Atq -v ON_ERROR_STOP=1 -d ironbody', bpsql, 1),
    ('[ "$(id -u)" = 0 ]', 'true', 1),
    (re.compile(r'^PREVIO=(\S*).*$', re.M), lambda m: f'PREVIO={previo[:7]}   # BANCO: la «producción» del banco (en el repo, PREVIO={m.group(1)})', 1),
    ('LOGDIR=/root', f'LOGDIR={b}/root', 1),
    ('/usr/local/bin/ironbody-migrate', f'{b}/root/ironbody-migrate', 0),
    ("'iron_app'", "'h5_app'", 0),
    ("has_schema_privilege('iron'", "has_schema_privilege('h5_owner'", 1),
    ('$ESQ_PARTIDA iron iron true 1', '$ESQ_PARTIDA h5_owner h5_owner true 1', 0),
    ('FUNC_ESPERADO="4 0 ultron_studio_voice_publication_agents:iron,ultron_studio_voice_publication_chain:iron,voice_brake_release:iron,voice_forbid:iron"',
     'FUNC_ESPERADO="4 0 ultron_studio_voice_publication_agents:h5_owner,ultron_studio_voice_publication_chain:h5_owner,voice_brake_release:h5_owner,voice_forbid:h5_owner"', 1),
    ("printf '%s:iron ' \"$t\"", "printf '%s:h5_owner ' \"$t\"", 1),
    ('= "root 600"', f'= "{me} 600"', 2),
    ('= www-data ]', f'= {me} ]', 0),
    ('production.ERROR', 'local.ERROR', 0),
])
open(f'{b}/root/deploy-h8-backend-fase1.sh', 'w', encoding='utf-8').write(deploy)
# El migrador: la propuesta de ironbody-migrate del H7, con las mismas sustituciones que su banco (banco-h7/preparar.sh).
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
    # El REVOKE de DB7 y de DB8 nombra el rol de la aplicación: en el banco es h5_app (en producción, iron_app).
    ('LOG_CHANNEL=stderr PGOPTIONS="$PGOPT"', 'LOG_CHANNEL=stderr ULTRON_STUDIO_DB_APP_ROLE=h5_app PGOPTIONS="$PGOPT"', 1),
])
open(f'{b}/root/ironbody-migrate', 'w', encoding='utf-8').write(migrador)
# OPS8-c: los tres de las fases 2 → 4a. El bloque «lo propio de producción» de cada uno y la comprobación de root.
propio = [('APPDIR=/var/www/api/backend', f'APPDIR={b}/api/backend', 1), ('WWW=www-data', f'WWW={me}', 1), ('[ "$(id -u)" = 0 ]', 'true', 1)]
mando = sustituye('h8-voice-scope.sh', propio + [
    ('API=https://api.ironbodyneiva.cloud/api', f'API={url}', 1), ('ROOT_U=root', f'ROOT_U={me}', 1), ('ROOT_G=root', f'ROOT_G={grupo}', 1),
    ('COPIAS=/root', f'COPIAS={b}/root', 1), ('CERROJO=/run/lock/h8-voice-scope.lock', f'CERROJO={b}/run/h8-voice-scope.lock', 1),
    ('NIVEL_ERROR=production.ERROR', 'NIVEL_ERROR=local.ERROR', 1),
])
open(f'{b}/root/h8-voice-scope.sh', 'w', encoding='utf-8').write(mando)
registro = sustituye('h8-secrets-register.sh', propio + [
    ('API=https://api.ironbodyneiva.cloud/api', f'API={url}', 1), ('ROOT_U=root', f'ROOT_U={me}', 1), ('ROOT_G=root', f'ROOT_G={grupo}', 1),
    ('COPIAS=/root', f'COPIAS={b}/root', 1), ('CERROJO=/run/lock/h8-voice-scope.lock', f'CERROJO={b}/run/h8-voice-scope.lock', 1),
    ('EL_BASE_DEFAULT=https://api.elevenlabs.io', f'EL_BASE_DEFAULT=http://127.0.0.1:{puerto_el}', 1),
    ('EL_BASE_EU=https://api.eu.residency.elevenlabs.io', f'EL_BASE_EU=http://127.0.0.1:{puerto_el}', 1),
])
open(f'{b}/root/h8-secrets-register.sh', 'w', encoding='utf-8').write(registro)
twilio = sustituye('h8-twilio-check.sh', propio + [
    ('TW_API=https://api.twilio.com', f'TW_API=http://127.0.0.1:{puerto_tw}', 1),
    ('TW_NUMBERS=https://numbers.twilio.com', f'TW_NUMBERS=http://127.0.0.1:{puerto_tw}', 1),
])
open(f'{b}/root/h8-twilio-check.sh', 'w', encoding='utf-8').write(twilio)
# OPS8-e: el mando de la rotación del token maestro del OTP, contra la cuenta maestra del simulador de Twilio.
otp = sustituye('h8-otp-token-rotate.sh', propio + [
    ('API=https://api.ironbodyneiva.cloud/api', f'API={url}', 1), ('ROOT_U=root', f'ROOT_U={me}', 1), ('ROOT_G=root', f'ROOT_G={grupo}', 1),
    ('COPIAS=/root', f'COPIAS={b}/root', 1), ('CERROJO=/run/lock/h8-voice-scope.lock', f'CERROJO={b}/run/h8-voice-scope.lock', 1),
    ('NIVEL_ERROR=production.ERROR', 'NIVEL_ERROR=local.ERROR', 1),
    ('TWILIO_API=https://api.twilio.com', f'TWILIO_API=http://127.0.0.1:{puerto_tw}', 1),
])
open(f'{b}/root/h8-otp-token-rotate.sh', 'w', encoding='utf-8').write(otp)
# OPS8-e: los mandos del H6 y del H7 (B-T13 y B-T14), como banco-h7/preparar.sh: la copia previa y el temporal al /root del
# banco, install sin -o root, la caché «de www-data» (el usuario del Mac) y los dueños admitidos del fichero de entorno:
# 600 del usuario del Mac o 640 del usuario y su grupo (en el servidor, www-data 600 o root:www-data 640). crontab -l -u …
# falla sin privilegios en el Mac: B-4 sale como «ninguna» (el usuario efectivo de schedule:run no se puede leer).
for nombre, extra in [('h7-monitor-scope.sh', []), ('h6-integrations-scope.sh', [('sudo -u postgres psql -X -Atq -v ON_ERROR_STOP=1 -d ironbody', bpsql, 1)])]:
    copia = sustituye(nombre, comunes + extra + [
        ('https://api.ironbodyneiva.cloud/api', url, 1),
        ('[ "$(id -u)" = 0 ]', 'true', 1),
        ('/root/', f'{b}/root/', 0),
        ('install -m 600 -o root -g root', 'install -m 600', 1),
        ('= www-data ]', f'= {me} ]', 1),
        ('ENV_600=www-data', f'ENV_600={me}', 1),
        ('ENV_640=root:www-data', f'ENV_640={me}:{grupo}', 1),
    ])
    open(f'{b}/root/{nombre}', 'w', encoding='utf-8').write(copia)
PY
chmod 700 "$B/root/deploy-h8-backend-fase1.sh" "$B/root/ironbody-migrate" "$B/root/h8-voice-scope.sh" "$B/root/h8-secrets-register.sh" "$B/root/h8-twilio-check.sh" "$B/root/h7-monitor-scope.sh" "$B/root/h6-integrations-scope.sh" "$B/root/h8-otp-token-rotate.sh"
for f in deploy-h8-backend-fase1.sh:deploy-h8-backend-fase1.sh ironbody-migrate-propuesta.sh:ironbody-migrate h8-voice-scope.sh:h8-voice-scope.sh h8-secrets-register.sh:h8-secrets-register.sh h8-twilio-check.sh:h8-twilio-check.sh h7-monitor-scope.sh:h7-monitor-scope.sh h6-integrations-scope.sh:h6-integrations-scope.sh h8-otp-token-rotate.sh:h8-otp-token-rotate.sh; do
  o=${f%%:*}; c=${f#*:}
  bash -n "$B/root/$c"
  echo "== $o → $B/root/$c: $(diff "$OPS/$o" "$B/root/$c" | grep -c '^>' || true) líneas con sustituciones del banco"
done

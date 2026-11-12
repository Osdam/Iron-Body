#!/bin/bash
# ElevenLabs en producción (H6, «ULTRON_STUDIO_ELEVENLABS_READY.md» pasos 1–6), con autorización del propietario del 03-11-2026:
# escribe la clave (desde un fichero, nunca por argumentos), el agent_id y dos secretos propios generados aquí
# (webhook y herramientas), con ELEVENLABS_ENABLED=false → doctor = ELEVENLABS_DISABLED; después ENABLED=true →
# doctor --verify (UN GET) → instantánea de verificación. Las llamadas siguen cerradas por CALLS_ENABLED_IN_CODE=false.
# Nunca imprime valores. Uso: bash /root/el-verificar.sh /root/el-clave.txt <agent_id>     (alta inicial, o rotación ANTES del registro de los secretos en ElevenLabs)
#                             bash /root/el-verificar.sh /root/el-clave.txt --solo-clave   (rotación DESPUÉS del registro: solo la clave; secretos, agente y región se conservan; H-16 de OPS8-c)
# Ejecutado en producción el 03-11-2026 22:55 UTC (E1-12). Endurecido el 08-11-2026 tras la revisión automática: python en modo
# aislado (-I: root no importa módulos del directorio de www-data), sin seguir enlaces simbólicos y escritura atómica del fichero
# de entorno conservando dueño y modo (fchown/fchmod sobre el descriptor abierto, nunca por ruta). Para la ROTACIÓN de la clave (E2-2): clave nueva en la consola →
# este mismo guion con el fichero nuevo → revocar la vieja en la consola. Copia en el repo desde el 08-11-2026 (DOC8, discrepancia 4).
# C-3 (auditoría C, 11-11-2026):
#   · toma los TRES cerrojos de los mandos que escriben el fichero de entorno (ironbody-env, h8-voice-scope y
#     h9-outreach-scope, como h8-voice-scope.sh «hechos», h5-engine-scope.sh y meta-templates-scope.sh) ANTES de tocar
#     nada; con uno ocupado aborta sin copia ni escritura y el fichero de la clave se queda para repetir;
#   · la VUELTA ATRÁS ante cualquier fallo después de escribir (escritura, Dotenv, caché, la bandera, doctor --verify o un
#     corte inesperado) es automática y COMPROBADA: el fichero de entorno vuelve a ser la copia previa byte a byte (dueño y
#     modo), la caché se regenera como www-data (600, de www-data, legible por www-data: si no, los workers no arrancan),
#     queue:restart, y la configuración efectiva de la voz es la de antes (huella sha256, nunca valores). Antes la vuelta
#     que se proponía era ELEVENLABS_ENABLED=false, que tumbaba el laboratorio hablado; ahora ELEVENLABS_ENABLED vuelve a
#     ser lo que era. Si la restauración no se puede comprobar, lo dice y da la orden exacta a mano.
#   El fichero de la clave se destruye tras un doctor --verify fallido (REV8 m-5: una clave que no verificó no se deja en
#   claro) y al terminar bien; con cualquier otro fallo se queda (600, root) para repetir, y se avisa.
set -uo pipefail
export LC_ALL=C
KEYFILE="${1:?fichero con la clave}"; SOLO=0; AGENT=""
if [ "${2:-}" = "--solo-clave" ]; then SOLO=1; else AGENT="${2:?agent_id (o --solo-clave)}"; fi
APPDIR=/var/www/api/backend; ENVF="$APPDIR/.env"; TS=$(date -u +%Y%m%d-%H%M%S)
WWW=www-data
CERROJOS=/run/lock
cd "$APPDIR" || { echo "ABORTA: no existe $APPDIR"; exit 1; }
[ ! -L "$ENVF" ] || { echo "ABORTA: el fichero de entorno es un enlace simbólico"; exit 1; }
[ "$(stat -c '%U %a' "$ENVF")" = "www-data 600" ] || { echo "ABORTA: fichero de entorno en $(stat -c '%U %a' "$ENVF")"; exit 1; }
[ -s "$KEYFILE" ] || { echo "ABORTA: $KEYFILE vacío"; exit 1; }
[ "$SOLO" = 1 ] || echo "$AGENT" | grep -Eq '^[A-Za-z0-9_-]{1,64}$' || { echo "ABORTA: agent_id con forma inválida"; exit 1; }
# C-3: exclusión mutua con cualquier otro mando que escriba el fichero de entorno, ANTES de la copia y de escribir.
exec 7> "$CERROJOS/ironbody-env.lock" 8> "$CERROJOS/h8-voice-scope.lock" 9> "$CERROJOS/h9-outreach-scope.lock" \
  || { echo "ABORTA: no se pudieron abrir los cerrojos de $CERROJOS (no se ha tocado nada; $KEYFILE sigue en disco)"; exit 1; }
{ flock -n 7 && flock -n 8 && flock -n 9; } \
  || { echo "ABORTA: otro mando que escribe el fichero de entorno está en curso: esperar a que termine (no se ha tocado nada; $KEYFILE sigue en disco)"; exit 1; }
# La caché de configuración como www-data con umask 077: un fichero normal (nunca un enlace), de www-data, en 600 y
# legible por www-data (los workers y FPM la leen como www-data). root no hace chmod ahí (H-5 de SEC7).
cache() {
  local c=bootstrap/cache/config.php
  sudo -u "$WWW" sh -c 'umask 077; php artisan config:cache -q' || return 1
  [ -f "$c" ] && [ ! -L "$c" ] || return 1
  [ "$(stat -c '%U' "$c")" = "$WWW" ] || return 1
  [ "$(stat -c '%a' "$c")" = 600 ] || sudo -u "$WWW" chmod 600 "$c" || return 1
  sudo -u "$WWW" test -r "$c"
}
doctor() { sudo -u www-data php artisan voice:doctor "$@" 2>&1 | grep -vE 'xi-api|sk_' ; }
parsea_el() { sudo -u "$WWW" php -r 'require "vendor/autoload.php"; try { Dotenv\Dotenv::createArrayBacked(".")->load(); } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }'; }
# La configuración efectiva de la voz (la caché, leída por artisan como www-data) como UNA huella sha256: compara antes y
# después de una restauración sin imprimir ningún valor (la clave va dentro de config("voice")).
huella_voz() { sudo -u "$WWW" php artisan tinker --execute='echo "H8EL:".hash("sha256", (string) json_encode(config("voice"))), PHP_EOL;' 2>/dev/null | grep -oE '^H8EL:[0-9a-f]{64}$' | tail -1; }
echo "0. partida: $(doctor | head -1) · workers $(supervisorctl status | grep -c RUNNING)/$(supervisorctl status | grep -c 'BACKOFF\|FATAL')"
H0=$(huella_voz)
[ -n "$H0" ] || { echo "ABORTA: no se pudo leer la configuración efectiva de la voz (tinker como $WWW; su salida no se imprime). No se ha tocado nada; $KEYFILE sigue en disco"; exit 1; }
DUENO=$(stat -c '%U:%G' "$ENVF"); MODO=$(stat -c '%a' "$ENVF")
COPIA="/root/env-pre-elevenlabs-$TS"
[ ! -e "$COPIA" ] || { echo "ABORTA: ya existe $COPIA (nunca se sobrescribe una copia): esperar un segundo y repetir"; exit 1; }
install -m 600 -o root -g root "$ENVF" "$COPIA" && cmp -s "$ENVF" "$COPIA" \
  || { rm -f -- "$COPIA"; echo "ABORTA: no se pudo hacer la copia previa idéntica (¿disco lleno?): no se ha tocado nada; $KEYFILE sigue en disco"; exit 1; }
VUELTA="install -m $MODO -o ${DUENO%%:*} -g ${DUENO##*:} $COPIA $ENVF && cd $APPDIR && sudo -u $WWW sh -c 'umask 077; php artisan config:cache -q' && sudo -u $WWW chmod 600 bootstrap/cache/config.php && sudo -u $WWW php artisan queue:restart -q"
# La vuelta atrás automática (C-3): el fichero de antes, la caché regenerada con él y las colas; y se COMPRUEBA.
restaurar() {
  local ok=1 h
  if install -m "$MODO" -o "${DUENO%%:*}" -g "${DUENO##*:}" "$COPIA" "$ENVF" && cmp -s "$COPIA" "$ENVF" && [ "$(stat -c '%U:%G %a' "$ENVF")" = "$DUENO $MODO" ]; then
    echo "   fichero de entorno restaurado: idéntico byte a byte a la copia previa, $DUENO $MODO"
  else
    ok=0; echo "   ✗ el fichero de entorno NO quedó idéntico a la copia previa ($DUENO $MODO)"
  fi
  if cache; then echo "   caché regenerada con el fichero de antes ($(stat -c '%U %a' bootstrap/cache/config.php), legible por $WWW)"; else ok=0; echo "   ✗ la caché NO se regeneró bien (config:cache como $WWW, 600, de $WWW y legible)"; fi
  if sudo -u "$WWW" php artisan queue:restart -q; then echo "   colas reiniciadas (por si un worker llegó a cargar la configuración intermedia)"; else ok=0; echo "   ✗ queue:restart no salió con 0"; fi
  h=$(huella_voz)
  if [ -n "$h" ] && [ "$h" = "$H0" ]; then echo "   configuración efectiva de la voz: la de antes (misma huella; ELEVENLABS_ENABLED y la clave, los de antes)"; else ok=0; echo "   ✗ la configuración efectiva de la voz NO es la de antes (¿el fichero y la caché ya no coincidían al empezar?)"; fi
  [ "$ok" = 1 ]
}
# ETAPA 0: nada escrito · 1: escrito y sin verificar (un fallo restaura) · 2: verificado (lo que falle después se dice).
ETAPA=0
al_salir() {
  local rc=$?
  [ "$ETAPA" = 1 ] || return 0
  trap - EXIT
  set +e
  echo "VUELTA ATRÁS AUTOMÁTICA (fallo a medias, código $rc; 0 si lo cortó una señal): se restaura la copia previa y se regenera la caché; ElevenLabs NO se apaga"
  if restaurar; then
    echo "   restaurado y COMPROBADO: el fichero es la copia previa byte a byte y la configuración efectiva es la de antes"
    R=sí
  else
    echo "   ✗ restauración INCOMPLETA: vuelta a mano: $VUELTA"
    R=NO
  fi
  [ ! -e "$KEYFILE" ] || echo "   AVISO: $KEYFILE sigue en disco (600, root): repetir la orden o destruirlo con shred -u"
  echo "   la copia previa se conserva: $COPIA (600; lleva la clave de antes y los secretos: shred -u cuando ya no haga falta)"
  echo "ELEVENLABS VERIFICACIÓN FALLIDA (restaurado: $R)"
  exit 1
}
trap al_salir EXIT
falla() { echo "   ✗ $*"; exit 1; }
# 1. variables (en el sitio: dueño y modo se conservan); secretos propios generados aquí, salvo con --solo-clave:
#    tras el registro en ElevenLabs (h8-secrets-register.sh) regenerarlos rompería la post-llamada y las herramientas (H-16 de OPS8-c).
if [ "$SOLO" = 1 ]; then WH=""; TL=""; echo "1. modo --solo-clave: se sustituye solo la clave; secretos, agente y región se conservan"; else WH=$(openssl rand -hex 32); TL=$(openssl rand -hex 32); fi
ETAPA=1
KEYFILE="$KEYFILE" AGENT="$AGENT" WH="$WH" TL="$TL" SOLO="$SOLO" ENVF="$ENVF" python3 -I - <<'PY' || falla "no se pudo escribir el fichero de entorno (paso 1; no se imprime nada)"
import os, re
p = os.environ['ENVF']
fd = os.open(p, os.O_RDONLY | os.O_NOFOLLOW); st = os.fstat(fd)
with os.fdopen(fd, encoding='utf-8') as fh: s = fh.read()
if not s.endswith('\n'): s += '\n'
vals = [('ELEVENLABS_ENABLED', 'false'), ('ELEVENLABS_API_KEY', open(os.environ['KEYFILE']).read().strip())]
if os.environ['SOLO'] != '1':
    vals += [('ELEVENLABS_AGENT_ID', os.environ['AGENT']), ('ELEVENLABS_WEBHOOK_SECRET', os.environ['WH']),
             ('ELEVENLABS_TOOL_SECRET', os.environ['TL']), ('ELEVENLABS_REGION', 'default')]
for k, v in vals:
    pat = re.compile(r'^(\s*)' + re.escape(k) + r'\s*=.*$', re.M)
    if pat.search(s): s = pat.sub(lambda m: f'{m.group(1)}{k}={v}', s, count=1); print('   ' + k + ' sustituida')
    else: s += f'{k}={v}\n'; print('   ' + k + ' añadida')
tmp = p + '.tmp-' + str(os.getpid())
tfd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
try:
    with os.fdopen(tfd, 'w', encoding='utf-8') as fh:
        fh.write(s); fh.flush(); os.fchown(fh.fileno(), st.st_uid, st.st_gid); os.fchmod(fh.fileno(), 0o600); os.fsync(fh.fileno())
    os.replace(tmp, p)
except BaseException:
    try: os.unlink(tmp)
    except FileNotFoundError: pass
    raise
PY
unset WH TL
parsea_el || falla "el fichero de entorno NO parsea tras escribir (paso 1)"
[ "$(stat -c '%U:%G %a' "$ENVF")" = "$DUENO $MODO" ] || falla "el dueño o el modo del fichero de entorno cambiaron al escribir (paso 1)"
echo "   parsea · permisos $(stat -c '%U %a' "$ENVF")"
cache || falla "config:cache falló o la caché no quedó de $WWW en 600 y legible (paso 2)"
echo "2. caché $(stat -c '%U %a' bootstrap/cache/config.php) · doctor con la bandera apagada:"; doctor | sed 's/^/   /' | head -6
# 3. bandera encendida (solo selecciona el proveedor de verificación; CALLS_ENABLED_IN_CODE sigue en false) y verificación con UN GET
escribir_bandera() {
  ENVF="$ENVF" python3 -I - <<'PY'
import os, re
p = os.environ['ENVF']
fd = os.open(p, os.O_RDONLY | os.O_NOFOLLOW); st = os.fstat(fd)
with os.fdopen(fd, encoding='utf-8') as fh: s = fh.read()
s = re.sub(r'^(\s*)ELEVENLABS_ENABLED\s*=.*$', r'\1ELEVENLABS_ENABLED=true', s, count=1, flags=re.M)
tmp = p + '.tmp-' + str(os.getpid())
tfd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
try:
    with os.fdopen(tfd, 'w', encoding='utf-8') as fh:
        fh.write(s); fh.flush(); os.fchown(fh.fileno(), st.st_uid, st.st_gid); os.fchmod(fh.fileno(), 0o600); os.fsync(fh.fileno())
    os.replace(tmp, p)
except BaseException:
    try: os.unlink(tmp)
    except FileNotFoundError: pass
    raise
print('   ELEVENLABS_ENABLED=true')
PY
}
escribir_bandera || falla "no se pudo escribir ELEVENLABS_ENABLED=true (paso 3)"
cache || falla "config:cache falló o la caché no quedó bien con la bandera encendida (paso 3)"
echo "3. doctor --verify (un GET al agente):"; doctor --verify | sed 's/^/   /' | head -8; RC=${PIPESTATUS[0]}
if [ "$RC" != 0 ]; then
  # REV8 m-5: fallo cerrado. La clave nueva NO verificó: se destruye el fichero de la clave (no se deja en claro en disco) y
  # la trampa vuelve a la configuración de antes (C-3: la copia previa y la caché; ElevenLabs no se apaga). Repetir con un
  # fichero nuevo cuando se sepa por qué falló.
  shred -u "$KEYFILE" 2>/dev/null || rm -f "$KEYFILE"
  echo "   ✗ doctor --verify salió con $RC: la clave nueva no verificó; fichero de la clave destruido"
  exit 1
fi
ETAPA=2
echo "4. instantánea de verificación (integrations-snapshot --verify-voice):"; sudo -u www-data php artisan ultron-studio:integrations-snapshot --verify-voice 2>&1 | grep -vE 'xi-api|sk_' | sed 's/^/   /' | tail -4
echo "5. estado efectivo:"; sudo -u www-data php artisan tinker --execute='echo json_encode(["calls_enabled_in_code" => App\Services\Voice\VoiceCallPolicy::CALLS_ENABLED_IN_CODE, "elevenlabs_enabled" => config("voice.elevenlabs.enabled"), "has_key" => (bool) config("voice.elevenlabs.api_key"), "agent_id_set" => (bool) config("voice.elevenlabs.agent_id"), "twilio_voice_enabled" => config("voice.twilio.enabled")]), PHP_EOL;' 2>/dev/null | tail -1 | sed 's/^/   /'
sudo -u www-data php artisan queue:restart -q; sleep 6
echo "   workers $(supervisorctl status | grep -c RUNNING)/$(supervisorctl status | grep -c 'BACKOFF\|FATAL') · secret-exposure: $(sudo -u www-data php artisan security:check-secret-exposure > /dev/null 2>&1 && echo OK || echo FALLA) · ERROR log: $(grep -c 'production.ERROR' storage/logs/laravel.log)"
echo "   integrations-doctor (voz): $(sudo -u www-data php artisan ultron-studio:integrations-doctor 2>&1 | grep -io 'elevenlabs=[A-Za-z_/]*' | head -1)"
shred -u "$KEYFILE" 2>/dev/null || rm -f "$KEYFILE"
echo "6. fichero de la clave destruido · copia previa: $COPIA (600 root) · modo: $([ "$SOLO" = 1 ] && echo solo-clave || echo completo)"
echo "   vuelta atrás (solo mientras la clave de antes no esté revocada en la consola): $VUELTA"
echo "   después de revocar la de antes, la vuelta es otra clave nueva; la copia previa lleva la de antes: shred -u cuando ya no haga falta"
echo "ELEVENLABS VERIFICACIÓN TERMINADA"

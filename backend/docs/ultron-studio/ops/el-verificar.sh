#!/bin/bash
# ElevenLabs en producción (H6, «ULTRON_STUDIO_ELEVENLABS_READY.md» pasos 1–6), con autorización del propietario del 03-11-2026:
# escribe la clave (desde un fichero, nunca por argumentos), el agent_id y dos secretos propios generados aquí
# (webhook y herramientas), con ELEVENLABS_ENABLED=false → doctor = ELEVENLABS_DISABLED; después ENABLED=true →
# doctor --verify (UN GET) → instantánea de verificación. Las llamadas siguen cerradas por CALLS_ENABLED_IN_CODE=false.
# Nunca imprime valores. Uso: bash /root/el-verificar.sh /root/el-clave.txt <agent_id>
# Ejecutado en producción el 03-11-2026 22:55 UTC (E1-12). Endurecido el 08-11-2026 tras la revisión automática: python en modo
# aislado (-I: root no importa módulos del directorio de www-data), sin seguir enlaces simbólicos y escritura atómica del fichero
# de entorno conservando dueño y modo (fchown/fchmod sobre el descriptor abierto, nunca por ruta). Para la ROTACIÓN de la clave (E2-2): clave nueva en la consola →
# este mismo guion con el fichero nuevo → revocar la vieja en la consola. Copia en el repo desde el 08-11-2026 (DOC8, discrepancia 4).
set -uo pipefail
export LC_ALL=C
KEYFILE="${1:?fichero con la clave}"; AGENT="${2:?agent_id}"
APPDIR=/var/www/api/backend; ENVF="$APPDIR/.env"; TS=$(date -u +%Y%m%d-%H%M%S)
cd "$APPDIR"
[ ! -L "$ENVF" ] || { echo "ABORTA: el fichero de entorno es un enlace simbólico"; exit 1; }
[ "$(stat -c '%U %a' "$ENVF")" = "www-data 600" ] || { echo "ABORTA: fichero de entorno en $(stat -c '%U %a' "$ENVF")"; exit 1; }
[ -s "$KEYFILE" ] || { echo "ABORTA: $KEYFILE vacío"; exit 1; }
echo "$AGENT" | grep -Eq '^[A-Za-z0-9_-]{1,64}$' || { echo "ABORTA: agent_id con forma inválida"; exit 1; }
cache() { sudo -u www-data sh -c 'umask 077; php artisan config:cache -q' && [ ! -L bootstrap/cache/config.php ] && chmod 600 bootstrap/cache/config.php; }
doctor() { sudo -u www-data php artisan voice:doctor "$@" 2>&1 | grep -vE 'xi-api|sk_' ; }
echo "0. partida: $(doctor | head -1) · workers $(supervisorctl status | grep -c RUNNING)/$(supervisorctl status | grep -c 'BACKOFF\|FATAL')"
install -m 600 -o root -g root "$ENVF" "/root/env-pre-elevenlabs-$TS"
# 1. variables (en el sitio: dueño y modo se conservan); secretos propios generados aquí
WH=$(openssl rand -hex 32); TL=$(openssl rand -hex 32)
KEYFILE="$KEYFILE" AGENT="$AGENT" WH="$WH" TL="$TL" ENVF="$ENVF" python3 -I - <<'PY'
import os, re
p = os.environ['ENVF']
fd = os.open(p, os.O_RDONLY | os.O_NOFOLLOW); st = os.fstat(fd)
with os.fdopen(fd, encoding='utf-8') as fh: s = fh.read()
if not s.endswith('\n'): s += '\n'
vals = [('ELEVENLABS_ENABLED', 'false'), ('ELEVENLABS_API_KEY', open(os.environ['KEYFILE']).read().strip()),
        ('ELEVENLABS_AGENT_ID', os.environ['AGENT']), ('ELEVENLABS_WEBHOOK_SECRET', os.environ['WH']),
        ('ELEVENLABS_TOOL_SECRET', os.environ['TL']), ('ELEVENLABS_REGION', 'default')]
for k, v in vals:
    pat = re.compile(r'^(\s*)' + re.escape(k) + r'\s*=.*$', re.M)
    if pat.search(s): s = pat.sub(lambda m: f'{m.group(1)}{k}={v}', s, count=1); print('   ' + k + ' sustituida')
    else: s += f'{k}={v}\n'; print('   ' + k + ' añadida')
tmp = p + '.tmp-' + str(os.getpid())
tfd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
with os.fdopen(tfd, 'w', encoding='utf-8') as fh:
    fh.write(s); fh.flush(); os.fchown(fh.fileno(), st.st_uid, st.st_gid); os.fchmod(fh.fileno(), 0o600); os.fsync(fh.fileno())
os.replace(tmp, p)
PY
unset WH TL
sudo -u www-data php -r 'require "/var/www/api/backend/vendor/autoload.php"; try { Dotenv\Dotenv::createArrayBacked("/var/www/api/backend")->load(); } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' || { echo "ABORTA: el fichero de entorno NO parsea; restaurar /root/env-pre-elevenlabs-$TS"; exit 1; }
echo "   parsea · permisos $(stat -c '%U %a' "$ENVF")"
cache; echo "2. caché $(stat -c '%U %a' bootstrap/cache/config.php) · doctor con la bandera apagada:"; doctor | sed 's/^/   /' | head -6
# 3. bandera encendida (solo selecciona el proveedor de verificación; CALLS_ENABLED_IN_CODE sigue en false) y verificación con UN GET
ENVF="$ENVF" python3 -I - <<'PY'
import os, re
p = os.environ['ENVF']
fd = os.open(p, os.O_RDONLY | os.O_NOFOLLOW); st = os.fstat(fd)
with os.fdopen(fd, encoding='utf-8') as fh: s = fh.read()
s = re.sub(r'^(\s*)ELEVENLABS_ENABLED\s*=.*$', r'\1ELEVENLABS_ENABLED=true', s, count=1, flags=re.M)
tmp = p + '.tmp-' + str(os.getpid())
tfd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
with os.fdopen(tfd, 'w', encoding='utf-8') as fh:
    fh.write(s); fh.flush(); os.fchown(fh.fileno(), st.st_uid, st.st_gid); os.fchmod(fh.fileno(), 0o600); os.fsync(fh.fileno())
os.replace(tmp, p); print('   ELEVENLABS_ENABLED=true')
PY
cache; echo "3. doctor --verify (un GET al agente):"; doctor --verify | sed 's/^/   /' | head -8; RC=${PIPESTATUS[0]}
echo "4. instantánea de verificación (integrations-snapshot --verify-voice):"; sudo -u www-data php artisan ultron-studio:integrations-snapshot --verify-voice 2>&1 | grep -vE 'xi-api|sk_' | sed 's/^/   /' | tail -4
echo "5. estado efectivo:"; sudo -u www-data php artisan tinker --execute='echo json_encode(["calls_enabled_in_code" => App\Services\Voice\VoiceCallPolicy::CALLS_ENABLED_IN_CODE, "elevenlabs_enabled" => config("voice.elevenlabs.enabled"), "has_key" => (bool) config("voice.elevenlabs.api_key"), "agent_id_set" => (bool) config("voice.elevenlabs.agent_id"), "twilio_voice_enabled" => config("voice.twilio.enabled")]), PHP_EOL;' 2>/dev/null | tail -1 | sed 's/^/   /'
sudo -u www-data php artisan queue:restart -q; sleep 6
echo "   workers $(supervisorctl status | grep -c RUNNING)/$(supervisorctl status | grep -c 'BACKOFF\|FATAL') · secret-exposure: $(sudo -u www-data php artisan security:check-secret-exposure > /dev/null 2>&1 && echo OK || echo FALLA) · ERROR log: $(grep -c 'production.ERROR' storage/logs/laravel.log)"
echo "   integrations-doctor (voz): $(sudo -u www-data php artisan ultron-studio:integrations-doctor 2>&1 | grep -io 'elevenlabs=[A-Za-z_/]*' | head -1)"
shred -u "$KEYFILE" 2>/dev/null || rm -f "$KEYFILE"
echo "6. fichero de la clave destruido · copia previa: /root/env-pre-elevenlabs-$TS (600 root) · vuelta atrás: ELEVENLABS_ENABLED=false y la caché"
echo "ELEVENLABS VERIFICACIÓN TERMINADA"

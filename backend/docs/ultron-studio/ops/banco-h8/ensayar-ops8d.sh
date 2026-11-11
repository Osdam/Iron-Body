#!/bin/bash
# El ensayo de OPS8-d en el banco del Hito 8: la secuencia EXACTA de producción de las fases 2, 2a y 4a-primero del
# plan de despliegue («Hito 8», «Fase 2a» y «Fase 4a-primero») con el código que corre en producción (a608450, el
# despliegue 1b), contra los simuladores de ElevenLabs y Twilio y sin red (la valla). Es el ensayar-ops8c.sh (los
# mandos, caso a caso) llevado a la secuencia entera, de principio a fin y por la API:
#   fase 2    rotación de la clave de ElevenLabs (el-verificar.sh, modo completo, con la clave vieja revocada en el
#             simulador) → fase 2 set VOICE_WEBHOOK_BASE_URL → h8-secrets-register.sh --solo-comprobar, el registro y
#             otra vez --solo-comprobar → el-verificar.sh --solo-clave (H-16: las referencias y los dos secretos
#             registrados se conservan) → reconocer la configuración autorizada (integrations/config/acknowledge) →
#             estado → expectativa fase1;
#   fase 2a   lab on --driver=fake → SMOKE_PHASE=2a → una sesión del laboratorio con el driver fake de principio a fin
#             por la API (versión y perfil a cuatro ojos, compile-preview, abrir, cerrar) y el veredicto de OTRA
#             identidad (que el código NIEGA con `fake_mode`: una sesión del simulador no admite veredicto,
#             StudioVoiceLabSessions::NOT_REVIEWABLE) → lab off → lab on --driver=elevenlabs → SMOKE_PHASE=2a → una
#             sesión con el adaptador REAL contra el simulador, cerrada por su informe post-llamada, y el veredicto de
#             Beto sobre la misma specHash;
#   fase 4a   fase 2 secreto <fichero> → fase 2 set de la cuenta, el número y el id importado con
#             VOICE_NUMBER_INBOUND_CAPABLE=false → h8-twilio-check.sh (caller ID verificado) → expectativa fase2 →
#             monitor on → publicar on → la publicación canaria a cuatro ojos (sin asignar el número: Outbound Only) →
#             el commit voice(calls-open) SOLO en el clon del banco → worker comprobar e instalar → salientes on
#             --cupo=1 --presupuesto=2.00 → expectativa llamadas → SMOKE_PHASE=4a → UNA saliente al canario punta a punta
#             por la API (la puerta), el worker con la orden literal del borrador de supervisor, contestada, con su
#             herramienta, su post-llamada firmada, su coste conciliado y la segunda petición del día en
#             daily_cap_reached → la vuelta atrás completa.
# COSTURA (solo del banco, como en el E2E de TEST8): el horario legal de las salientes y la ventana de publicar son de
# Bogotá en días hábiles; si el ensayo corre fuera, publicar y la saliente van por una SEGUNDA API de la misma
# «producción» (php -S, como iniciarApi2 del E2E) y por el worker con el reloj de Carbon desplazado (ops/h8-e2e/
# reloj.php) al siguiente día hábil a las 07:30 de Bogotá (OPS8D_RELOJ lo fija). El resto (el mando, el registro, el
# smoke, la conciliación) corre con el reloj real. En producción no hay nada que desplazar: se publica y se llama en
# horario.
# OPS8-e (10-11-2026, auditoría de los 9 hitos): B-T1, la constante ya NO se gira a mano en el clon de la «producción»:
# el commit `voice(calls-open):` de una línea se hace en el ORIGEN del banco ($B/src, nunca en el repo), encima de un
# commit de solo documentación (como d701b0c en producción), y lo despliega deploy-h8-backend-fase1.sh en modo
# `--fase=4a --autorizacion=<ref>` con su respaldo, tras sus casos negativos (sin autorización, otra autorización, sin
# aceptar la documentación, sin worker, el worker con --sin-worker, una línea más, otro fichero, un commit de código antes,
# otro asunto, dos commits de la constante, otra sangría, un --acepta-main a ciegas, la fase 2 incompleta y el modo
# normal con la constante abierta); la vuelta atrás del código es la del runbook (checkout del commit del respaldo,
# cachés y queue:restart). B-T7: con la fase 1 (todo apagado) se crea y aprueba un perfil de voz, como en producción
# (E16-30), y SMOKE_PHASE=1 tiene que salir sin FALLO (OPS8E_SMOKE_VIEJO=<smoke-h8.py> pasa además el de antes, que daba
# 1 FALLO). Necesita un banco recién montado (la «producción» y el origen en el 1b): la cadena de voz conserva la
# publicación de una pasada anterior y el smoke 2a exige la cadena vacía.
# Cada paso dice OK o FALLO con el código de salida y la línea que lo decide; la salida completa queda en
# $B/logs/ensayo-ops8d-<fecha>.txt (600; sin secretos: las huellas se comparan aquí y solo sale «sí» o «no»).
# Requisitos: el banco montado con «producción» en fe29126 y origen a608450 (BANCO_PREVIO=fe29126 montar.sh … a608450),
# en marcha, con E1-12 (e112), sembrado (--canarios-voz), con la línea base del Studio importada
# (ultron-studio:import-baseline, lo que producción ya tiene) y con el despliegue 1b hecho (desplegar). Se puede
# repetir: al empezar devuelve el entorno al de partida, cierra la constante, retira el worker y vacía los simuladores.
# Uso: bash ensayar-ops8d.sh <banco>
. "$(dirname "$0")/comun.sh"
B=$(banco_dir "${1:-}")
cargar_banco "$B"
BE="$B/api/backend"
ENVB="$BE/.env"
export PATH="$B/bin:$PATH"   # los sustitutos del banco (sudo, stat, supervisorctl…)
E=(bash "$AQUI/ensayar.sh" "$B")
ME=$(whoami); GRUPO=$(id -gn); PHPBIN=$(command -v php)
LOG="$B/logs/ensayo-ops8d-$(date -u +%Y%m%d-%H%M%S).txt"
( umask 077; : > "$LOG" )
vivo "$PUERTO_VALLA" && vivo "$PUERTO_EL" && vivo "$PUERTO_TW" || muere "arranca antes: bash $AQUI/ensayar.sh $B arrancar"
[ -n "${ADMIN_ID:-}" ] || muere "el banco no está sembrado: bash $AQUI/sembrar.sh $B --canarios-voz"
API="http://127.0.0.1:$BANCO_PORT/api"
PUERTO_API2=${BANCO_PUERTO_API2:-$((BANCO_PORT + 4))}
API2="http://127.0.0.1:$PUERTO_API2/api"
RELOJ="$OPS/h8-e2e/reloj.php"
AYUDANTE="$AQUI/ayudante-ops8d.php"
TOK="$B/tokens"; mkdir -p "$TOK"; chmod 700 "$TOK"
SALIDA=""; RESP=""
psq() { psql -X -Atq -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$BANCO_DB" -c "$1"; }
# paso <nombre> <código esperado> <patrón ERE que tiene que salir> <orden…>
paso() {
  local nombre=$1 esperado=$2 patron=$3 rc=0
  shift 3
  SALIDA=$("$@" 2>&1) || rc=$?
  { echo "════ $nombre · código $rc"; printf '%s\n' "$SALIDA"; } >> "$LOG"
  if [ "$rc" = "$esperado" ] && printf '%s' "$SALIDA" | grep -qE "$patron"; then
    caso "$nombre" 0 "código $rc"
  else
    caso "$nombre" 1 "código $rc (esperado $esperado); $(printf '%s' "$SALIDA" | grep -E 'ABORTA|NO SE APLICA|✗|VEREDICTO|APLICADO|REGISTRADO|TERMINADA|FALLIDA|FALLO|ERROR|PENDIENTE' | head -3 | tr '\n' ' ' | cut -c1-260)"
  fi
}
anota() { { echo "════ $1"; shift; printf '%s\n' "$@"; } >> "$LOG"; }
ctl_el() { curl -s --max-time 10 "http://127.0.0.1:$PUERTO_EL$1"; }
ctl_tw() { curl -s --max-time 10 "http://127.0.0.1:$PUERTO_TW$1"; }
pieza() { python3 -I -c 'import sys; print(next((l.split("=", 1)[1].strip() for l in open(sys.argv[1]) if l.startswith(sys.argv[2] + "=")), ""))' "$B/sim/piezas.conf" "$1"; }
# Un campo de un JSON (ruta con puntos, admite índices de lista), de $RESP o del argumento 2.
jc() { printf '%s' "${2:-$RESP}" | python3 -I -c 'import json, sys
d = json.load(sys.stdin)
for p in sys.argv[1].split("."):
    d = (d[int(p)] if p.isdigit() and 0 <= int(p) < len(d) else None) if isinstance(d, list) else (d.get(p) if isinstance(d, dict) else None)
print(json.dumps(d, ensure_ascii=False) if isinstance(d, (dict, list, bool)) else ("" if d is None else d))' "$1" 2>/dev/null || true; }
# Una petición a la API del banco con la sesión de Ana (a) o de Beto (b): api <a|b> <MÉTODO> <ruta bajo /api> [cuerpo JSON] [base].
# Deja en RESP {"http": …, "body": …} con signedUrl, token y secretos OCULTOS, y la anota en el registro.
api() {
  local quien=$1 metodo=$2 ruta=$3 cuerpo=${4:-} base=${5:-$API}
  RESP=$(python3 -I - "$TOK/token-$quien" "$metodo" "$base/$ruta" "$cuerpo" <<'PY'
import json, sys, urllib.error, urllib.request
tokf, metodo, url, cuerpo = sys.argv[1:5]
tok = open(tokf, encoding='utf-8').read().strip()
op = urllib.request.build_opener(urllib.request.ProxyHandler({}))
data = cuerpo.encode() if cuerpo else None
cab = {'Accept': 'application/json', 'Authorization': 'Bearer ' + tok}
if data is not None:
    cab['Content-Type'] = 'application/json'
req = urllib.request.Request(url, data=data, method=metodo, headers=cab)
try:
    with op.open(req, timeout=90) as r:
        st, body = r.status, r.read()
except urllib.error.HTTPError as e:
    st, body = e.code, e.read()
try:
    j = json.loads(body)
except Exception:
    j = {'_raw': body[:200].decode(errors='replace')}
OCULTAS = {'signedUrl', 'token', 'secret', 'phone', 'phoneNumber', 'number_encrypted'}
def limpia(x):
    if isinstance(x, dict):
        return {k: ('[oculto]' if k in OCULTAS else limpia(v)) for k, v in x.items()}
    if isinstance(x, list):
        return [limpia(v) for v in x]
    return x
print(json.dumps({'http': st, 'body': limpia(j)}, ensure_ascii=False, separators=(',', ':')))
PY
)
  anota "API $quien $metodo $ruta" "$(printf '%s' "$RESP" | cut -c1-1500)"
}
uuid() { python3 -I -c 'import uuid; print(uuid.uuid4())'; }
# El oráculo del registro (de ensayar-ops8c.sh): la huella sha256 de lo que guarda el simulador frente a la del fichero
# de entorno del banco (y los ids frente a las referencias). Solo imprime «sí» o «no».
oraculo() {
  python3 -I - "$ENVB" "$PUERTO_EL" <<'PY'
import hashlib, json, re, sys, urllib.request
env = open(sys.argv[1], encoding='utf-8').read()
def v(k):
    m = re.search(r'^[ \t]*' + re.escape(k) + r'[ \t]*=[ \t]*(.*?)[ \t]*$', env, re.M)
    return m.group(1) if m else ''
st = json.load(urllib.request.build_opener(urllib.request.ProxyHandler({})).open(f'http://127.0.0.1:{sys.argv[2]}/__state?secrets=1'))
h = lambda x: hashlib.sha256(x.encode()).hexdigest()
reg = {x['name']: x for x in st['secrets']}
t, i = reg.get('ironbody-voz-herramientas') or {}, reg.get('ironbody-voz-iniciacion') or {}
w = [x for x in st['webhooks'] if x['name'] == 'ironbody-voz-post-llamada']
ok = lambda c: 'si' if c else 'no'
print('herramientas=%s iniciacion=%s webhook=%s secretos=%d webhooks=%d' % (
    ok(t.get('sha256') == h('Bearer ' + v('ELEVENLABS_TOOL_SECRET')) and t.get('secret_id') == v('ELEVENLABS_TOOL_SECRET_REF')),
    ok(i.get('sha256') == h(v('ELEVENLABS_INITIATION_SECRET')) and i.get('secret_id') == v('ELEVENLABS_INITIATION_SECRET_REF')),
    ok(len(w) == 1 and w[0]['sha256'] == h(v('ELEVENLABS_WEBHOOK_SECRET')) and w[0]['webhook_id'] == v('ELEVENLABS_POST_CALL_WEBHOOK_REF')),
    len(st['secrets']), len(st['webhooks'])))
PY
}
# La huella conjunta (sha256) de unas claves del fichero de entorno, sin imprimir valores: para ver si cambiaron.
huella_env() { python3 -I - "$ENVB" "$@" <<'PY'
import hashlib, re, sys
env = open(sys.argv[1], encoding='utf-8').read()
def v(k):
    m = re.search(r'^[ \t]*' + re.escape(k) + r'[ \t]*=[ \t]*(.*?)[ \t]*$', env, re.M)
    return m.group(1) if m else ''
print(hashlib.sha256('\n'.join(k + '=' + v(k) for k in sys.argv[2:]).encode()).hexdigest()[:16])
PY
}
# ¿El valor de una clave del entorno es exactamente una pieza del banco? Solo «si» o «no».
env_es_pieza() { python3 -I - "$ENVB" "$B/sim/piezas.conf" "$1" "$2" <<'PY'
import re, sys
env = open(sys.argv[1], encoding='utf-8').read()
pz = dict(l.strip().split('=', 1) for l in open(sys.argv[2], encoding='utf-8') if '=' in l and not l.startswith('#'))
m = re.search(r'^[ \t]*' + re.escape(sys.argv[3]) + r'[ \t]*=[ \t]*(.*?)[ \t]*$', env, re.M)
print('si' if m and m.group(1) == pz.get(sys.argv[4], '\0') else 'no')
PY
}
# Cambia en el fichero de entorno del banco UNA clave (solo para lo que en producción está fuera del mando: la base del
# simulador de Twilio, que en production se ignora).
pon_env() { python3 -I - "$ENVB" "$1" "$2" <<'PY'
import re, sys
p, k, v = sys.argv[1:4]
s = open(p, encoding='utf-8').read()
pat = re.compile(r'^([ \t]*)' + re.escape(k) + r'[ \t]*=.*$', re.M)
s = pat.sub(lambda m: m.group(1) + k + '=' + v, s, count=1) if pat.search(s) else s.rstrip('\n') + '\n  ' + k + '=' + v + '\n'
open(p, 'w', encoding='utf-8').write(s)
PY
  ( cd "$BE" && env "${RED[@]}" php artisan config:cache -q && chmod 600 bootstrap/cache/config.php )
}
# El fichero de entorno de partida: el respaldo de E1-12 más las 9 claves que añade el despliegue de la fase 1 (lo que
# deja el despliegue 1b: solo código, las 9 claves ya con su valor).
INICIAL="$B/root/env-ensayo-ops8d-inicial"
restaura_inicial() {
  if [ ! -f "$INICIAL" ]; then
    ( umask 077; { cat "$B/bk/env.backup"; [ -z "$(tail -c1 "$B/bk/env.backup")" ] || printf '\n'
      for kv in VOICE_INBOUND_ENABLED=false VOICE_OUTBOUND_ENABLED=false VOICE_LAB_ENABLED=false VOICE_MONITOR_ENABLED=false ULTRON_STUDIO_VOICE_PUBLISHING_ENABLED=false VOICE_INBOUND_DAILY_CAP=0 VOICE_DAILY_BUDGET_USD=0 VOICE_LAB_DAILY_BUDGET_USD=0 VOICE_LAB_DRIVER=fake; do printf '  %s\n' "$kv"; done
      # H-36 (OPS8-e), COSTURA del banco como TWILIO_VOICE_BASE_URL: la sesión de administración caduca a las 12 h de crearse
      # (expires_at con el reloj real; config/admin.php) y la segunda API corre con el reloj desplazado al siguiente día hábil:
      # con el ensayo empezado entre las ~19:30 y las 07:00 de Bogotá (o en fin de semana) el salto pasa de 12 h y la segunda
      # API ve las sesiones caducadas (403 admin_token_invalid; pasó el 10-11-2026 a las 19:10). 72 h cubren el salto más
      # largo (viernes 18:30 → lunes 07:30). En producción no hay costura de reloj.
      printf '  %s\n' ADMIN_SESSION_TTL_MINUTES=4320; } > "$INICIAL" )
  fi
  install -m 600 "$INICIAL" "$ENVB"
  ( cd "$BE" && env "${RED[@]}" php artisan config:cache -q && chmod 600 bootstrap/cache/config.php )
}
# La copia del banco de el-verificar.sh (no está en preparar.sh): solo cambian las rutas, el usuario, el dueño de la
# copia previa y el canal del registro; cada sustitución tiene que casar (si el guion del repo cambia, esto falla).
prepara_el_verificar() {
  python3 -I - "$OPS/el-verificar.sh" "$B/root/el-verificar.sh" "$BE" "$ME" "$GRUPO" "$B/root" <<'PY'
import sys
src, dst, be, me, grupo, root = sys.argv[1:7]
s = open(src, encoding='utf-8').read()
for viejo, nuevo, veces in [
    ('APPDIR=/var/www/api/backend', f'APPDIR={be}', 1),
    ('/var/www/api/backend', be, 0),
    ('= "www-data 600"', f'= "{me} 600"', 1),
    ('-o root -g root', f'-o {me} -g {grupo}', 1),
    ('/root/env-pre-elevenlabs-', f'{root}/env-pre-elevenlabs-', 0),   # una sola regla: dos que se solapen sustituyen dos veces
    ('production.ERROR', 'local.ERROR', 1),
]:
    n = s.count(viejo)
    if n == 0 or (veces and n != veces):
        sys.exit(f'prepara_el_verificar: «{viejo[:60]}» aparece {n} veces (esperado {veces or "≥ 1"}): el guion cambió')
    s = s.replace(viejo, nuevo)
open(dst, 'w', encoding='utf-8').write(s)
PY
  chmod 700 "$B/root/el-verificar.sh"; bash -n "$B/root/el-verificar.sh"
}
# La rotación de la clave «en la consola»: una clave nueva en las piezas del simulador (sin pasar por la línea de órdenes)
# y los simuladores reiniciados con ella (la vieja deja de valer: 401). La clave nueva queda en $B/root/el-clave.txt (600),
# como /root/el-clave.txt en el servidor. Guarda la vieja en $B/root/el-clave-vieja.txt para probar que se revocó.
rotar_clave_simulador() {
  python3 -I - "$B/sim/piezas.conf" "$B/root/el-clave.txt" "$B/root/el-clave-vieja.txt" <<'PY'
import os, secrets, sys
p, nueva, vieja = sys.argv[1:4]
s = open(p, encoding='utf-8').read().splitlines()
k = 'xi_' + secrets.token_hex(24)
out = []
for l in s:
    if l.startswith('EL_API_KEY='):
        with os.fdopen(os.open(vieja, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600), 'w') as f:
            f.write(l.split('=', 1)[1].strip() + '\n')
        out.append('EL_API_KEY=' + k)
    else:
        out.append(l)
open(p, 'w', encoding='utf-8').write('\n'.join(out) + '\n')
with os.fdopen(os.open(nueva, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600), 'w') as f:
    f.write(k + '\n')
PY
  chmod 600 "$B/sim/piezas.conf" "$B/root/el-clave.txt" "$B/root/el-clave-vieja.txt"
  "${E[@]}" parar >> "$LOG" 2>&1
  "${E[@]}" arrancar >> "$LOG" 2>&1
}
# ¿Acepta el simulador de ElevenLabs la clave de un fichero? (un GET al agente de verificación; imprime el código HTTP)
sim_acepta_clave() { python3 -I - "$1" "$PUERTO_EL" "$(pieza EL_VERIFY_AGENT_ID)" <<'PY'
import sys, urllib.error, urllib.request
k = open(sys.argv[1], encoding='utf-8').read().strip()
op = urllib.request.build_opener(urllib.request.ProxyHandler({}))
req = urllib.request.Request(f'http://127.0.0.1:{sys.argv[2]}/v1/convai/agents/{sys.argv[3]}', headers={'xi-api-key': k, 'Accept': 'application/json'})
try:
    with op.open(req, timeout=10) as r:
        print(r.status)
except urllib.error.HTTPError as e:
    print(e.code)
PY
}
# El reloj desplazado (COSTURA del banco): el siguiente instante dentro del horario legal (L–V 07:00–19:00, sábados
# 08:00–15:00, Bogotá; sin festivos del fixture de TEST8-a), o el de ahora si ya está dentro. OPS8D_RELOJ lo fija.
reloj_objetivo() { python3 -I - "$BE/tests/fixtures/voice/holidays-co-2026-2028.json" <<'PY'
import datetime, json, sys
bog = datetime.timezone(datetime.timedelta(hours=-5))
ahora = datetime.datetime.now(bog)
fest = set()
try:
    fx = json.load(open(sys.argv[1], encoding='utf-8'))
    for y in fx.get('years', {}).values():
        fest |= {d['date'] for d in y}
except Exception:
    pass
def dentro(t):
    s = t.hour * 3600 + t.minute * 60
    return t.strftime('%Y-%m-%d') not in fest and ((t.weekday() <= 4 and 7 * 3600 <= s < 19 * 3600 - 1800) or (t.weekday() == 5 and 8 * 3600 <= s < 15 * 3600 - 1800))
if dentro(ahora):
    print(ahora.replace(microsecond=0).isoformat()); sys.exit(0)
t = ahora.replace(hour=7, minute=30, second=0, microsecond=0)
if t <= ahora:
    t += datetime.timedelta(days=1)
while not dentro(t):
    t += datetime.timedelta(days=1)
print(t.isoformat())
PY
}
# H-35 (OPS8-e): el reloj de la costura se fija AL USARLO (al arrancar la segunda API, en la 4a), no al empezar: si el
# ensayo empieza dentro del horario, «ahora» es el instante del arranque, ANTERIOR a la verificación de ElevenLabs de la
# fase 2, y con ese reloj la marca de E4-6 queda «en el futuro» (más allá de los 300 s de holgura): la telefonía falla
# cerrada (elevenlabs_unverified) y la puerta deniega. Pasó el 10-11-2026 (ensayo de las 18:29 de Bogotá).
OBJETIVO=""; RELOJ_ENV=()
fija_reloj() {
  OBJETIVO=${OPS8D_RELOJ:-$(reloj_objetivo)}
  RELOJ_ENV=("H8_E2E_RELOJ_OBJETIVO=$OBJETIVO" "H8_E2E_VENDOR=$BE/vendor/autoload.php")
  echo "   reloj desplazado de la costura (publicar y la saliente), fijado ahora: $OBJETIVO · ahora $(date -u +%Y-%m-%dT%H:%M:%SZ)"
}
# Un proceso PHP de la «producción» del banco con el reloj desplazado (y la red por la valla).
php_reloj() { ( cd "$BE" && env "${RED[@]}" "${RELOJ_ENV[@]}" "$PHPBIN" -d auto_prepend_file="$RELOJ" "$@" 2> >(grep -v 'h8-e2e reloj' >&2) ); }
php_real() { ( cd "$BE" && env "${RED[@]}" "$PHPBIN" "$@" ); }
# La SEGUNDA API, con el reloj desplazado: php -S directo (como artisan serve por dentro), desde public/. php -S NO ejecuta
# auto_prepend_file cuando se le da un guion de enrutado (sonda de OPS8-d), así que el reloj entra por un enrutado propio
# del banco que lo carga y después cede al de Laravel tal cual; STDERR no existe en el SAPI cli-server y el reloj lo usa.
api2_arrancar() {
  ( umask 077; printf '%s\n' '<?php' \
    '// Enrutado de la SEGUNDA API del banco (OPS8-d): el reloj desplazado del E2E y, después, el enrutado de Laravel tal cual.' \
    "if (! defined('STDERR')) { define('STDERR', fopen('php://stderr', 'w')); }" \
    "require '$RELOJ';" \
    "if ((require '$BE/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php') === false) { return false; }" > "$B/root/api2-router.php" )
  ( cd "$BE/public" && nohup env "${RED[@]}" "${RELOJ_ENV[@]}" "$PHPBIN" -S "127.0.0.1:$PUERTO_API2" "$B/root/api2-router.php" > "$B/logs/api2.out" 2>&1 < /dev/null & echo $! > "$B/run/api2.pid" )
  local i c=""
  for i in $(seq 1 60); do c=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$API2/admin/voice/status" || true); [ "$c" = 401 ] && break; sleep 0.5; done
  [ "$c" = 401 ] || muere "la segunda API (:$PUERTO_API2, reloj desplazado) no respondió (ver $B/logs/api2.out)"
}
api2_parar() { [ -f "$B/run/api2.pid" ] && { kill -TERM "$(cat "$B/run/api2.pid")" 2>/dev/null || true; rm -f "$B/run/api2.pid"; }; sleep 0.3; for p in $(lsof -nP -iTCP:"$PUERTO_API2" -sTCP:LISTEN 2>/dev/null | tail -n +2 | awk '{print $2}' | sort -u); do kill -KILL "$p" 2>/dev/null || true; done; }
trap 'api2_parar' EXIT
# El worker de la cola voice con la orden LITERAL del borrador de supervisor (como «worker comprobar»), una pasada
# (--stop-when-empty), con el reloj desplazado (el job vuelve a comprobar el horario con SU reloj).
worker_una_pasada() {
  local CMD
  CMD=$(sed -n 's/^command=//p' "$SUPERVISOR_VOZ")
  CMD=${CMD/\/usr\/bin\/php/$PHPBIN -d auto_prepend_file=$RELOJ}
  CMD=${CMD/\/var\/www\/api\/backend\/artisan/$BE/artisan}
  read -r -a PARTES <<< "$CMD"
  ( cd "$BE" && env "${RED[@]}" "${RELOJ_ENV[@]}" "${PARTES[@]}" --stop-when-empty 2>&1 | grep -v 'h8-e2e reloj' )
}
# El smoke de una fase contra la «producción» del banco (smoke-run-h8.sh del repo, con la valla): salida y código.
RC_SMOKE=0
smoke() { RC_SMOKE=0; SALIDA=$(env SMOKE_PHASE="$1" "${E[@]}" smoke 2>&1) || RC_SMOKE=$?; { echo "════ smoke fase $1 · código $RC_SMOKE"; printf '%s\n' "$SALIDA"; } >> "$LOG"; printf '%s\n' "$SALIDA" > "$B/logs/ensayo-ops8d-smoke-$2.txt"; }
# El veredicto FUNCIONAL del smoke: ningún FALLO que no sea de un presupuesto de rendimiento (los de PERF8 están medidos en
# el servidor; en el Mac, con otros agentes trabajando, un GET puede pasar de su techo: se dice aparte y no cuenta como fallo
# de la secuencia), los OMITIDOS esperados y el marcador repetido N veces. Con un presupuesto fallido el smoke sale con 1.
# OPS8-e: los FALLO de TIEMPO son los presupuestos de PERF8 (perf-h8/presupuestos-h8.json): las rutas y public401.maxMs, el «401
# en menos de N ms» de las públicas, que sale del mismo fichero. En el Mac con otros agentes (carga media > 100 el 10-11-2026)
# no se pueden medir; en producción el smoke no cambia (ahí son FALLO). Sin «printf | grep -q»: SIGPIPE con pipefail.
TIEMPO='^FALLO   (presupuesto|las 4 públicas responden el 401 en menos de)'
smoke_ok() { # <etiqueta> <omitidos esperados> <patrón extra que tiene que repetirse N veces> <N>
  local funcionales presupuestos
  funcionales=$(grep '^FALLO' <<< "$SALIDA" | grep -vEc "$TIEMPO" || true); presupuestos=$(grep -Ec "$TIEMPO" <<< "$SALIDA" || true)
  { [ "$RC_SMOKE" = 3 ] || { [ "$RC_SMOKE" = 1 ] && [ "$presupuestos" -gt 0 ]; }; } && [ "$funcionales" = 0 ] && grep -qE "RESUMEN \(superadmin\): [0-9]+ OK · $presupuestos FALLO · $2 OMITIDO" <<< "$SALIDA" && [ "$(grep -c "$3" <<< "$SALIDA" || true)" = "$4" ] && echo 0 || echo 1
}
resumen_smoke() { printf '%s' "$SALIDA" | grep -E 'RESUMEN|^FALLO|PRESUPUESTOS' | head -4 | tr '\n' ' ' | cut -c1-300; }
# Cuántas líneas del registro de voz casan con un patrón (los smokes dejan a propósito rechazos: se compara antes y después).
cuenta_log() { cat "$BE"/storage/logs/voice*.log 2>/dev/null | grep -cE "$1"; true; }
# Reconocer la configuración autorizada de la voz (la huella cambia con la clave, los secretos, las referencias, las piezas
# de Twilio y con salientes on/off: twilioVoice.enabled y dailyCallCap entran en los hechos, StudioIntegrationConfig::voiceFacts).
reconocer_voz() { # <nota> → 0 si quedó reconocida (201), 1 si no; deja la huella en HUELLA
  local intento
  for intento in 1 2 3; do
    api b GET admin/ultron-studio/integrations/config
    HUELLA=$(printf '%s' "$RESP" | python3 -I -c 'import json, sys; d = json.load(sys.stdin)["body"]; ps = d.get("data", d).get("providers") if isinstance(d.get("data", d), dict) else None; v = next((p for p in (ps or []) if isinstance(p, dict) and p.get("provider") == "voice"), None) if isinstance(ps, list) else ((ps or {}).get("voice") if isinstance(ps, dict) else None); print((v or {}).get("effectiveFingerprint") or "")' 2>/dev/null || true)
    [ -n "$HUELLA" ] || return 1
    api b POST admin/ultron-studio/integrations/config/acknowledge "{\"clientRequestId\":\"$(uuid)\",\"provider\":\"voice\",\"fingerprint\":\"$HUELLA\",\"note\":\"$1\"}"
    [ "$(jc http)" = 201 ] && return 0
    # Un 409 config_changed aislado (4.ª pasada de OPS8-d): entre el GET y el POST otra petición vio otra huella. Se anota
    # con las dos huellas (no son secretos) y se repite tras un segundo; si persiste, es un fallo de verdad.
    anota "reconocer: intento $intento → HTTP $(jc http) $(jc body.code) · huella vista ${HUELLA:0:12} · huella actual $(jc body.effectiveFingerprint | cut -c1-12)"
    [ "$(jc body.code)" = config_changed ] || return 1
    sleep 1.5
  done
  return 1
}

# ── OPS8-e: commits fabricados en el ORIGEN del banco (nunca en el repo ni en el árbol de trabajo) ──────────────────
P_POLITICA=backend/app/Services/Voice/VoiceCallPolicy.php
GIRO='s/^([[:space:]]*public const CALLS_ENABLED_IN_CODE = )false;/\1true;/'
# hace_commit <padre> <mensaje> <ruta> <sed -E> [<ruta> <sed -E>]… → el sha del commit nuevo en $B/src (con fontanería:
# índice temporal, hash-object y commit-tree; no mueve ninguna rama ni toca ningún árbol de trabajo).
hace_commit() {
  local padre=$1 msg=$2 idx="$B/run/indice-ops8e.$$" ruta expr blob arbol
  shift 2
  rm -f "$idx"
  GIT_INDEX_FILE="$idx" git -C "$B/src" read-tree "$padre"
  while [ "$#" -ge 2 ]; do
    ruta=$1; expr=$2; shift 2
    blob=$(git -C "$B/src" show "$padre:$ruta" | sed -E "$expr" | git -C "$B/src" hash-object -w --stdin)
    GIT_INDEX_FILE="$idx" git -C "$B/src" update-index --cacheinfo "100644,$blob,$ruta"
  done
  arbol=$(GIT_INDEX_FILE="$idx" git -C "$B/src" write-tree)
  rm -f "$idx"
  git -C "$B/src" -c user.name=banco -c user.email=banco@local commit-tree "$arbol" -p "$padre" -m "$msg"
}
origen_a() { git -C "$B/src" update-ref refs/heads/main "$1"; }   # el main del ORIGEN del banco (su propio clon)
corto() { git -C "$B/src" rev-parse --short=7 "$1"; }
# ── 0. Punto de partida: entorno de la fase 1, constante cerrada, worker retirado, simuladores vacíos, cuentas ────
# OPS8-e: un banco recién montado (la «producción» y el main del origen, en el commit del 1b). Una pasada anterior deja la
# publicación canaria en la cadena (el smoke 2a exige la cadena vacía) y el origen con el commit de la constante.
[ "$(git -C "$B/api" rev-parse --short=7 HEAD)" = "$ESPERADO" ] && [ "$(git -C "$B/src" rev-parse --short=7 main)" = "$ESPERADO" ] \
  || muere "el ensayo necesita la «producción» y el main del origen en $ESPERADO (un banco recién montado: ver banco-h8/README.md, §OPS8-d y §OPS8-e); hoy: «producción» $(git -C "$B/api" rev-parse --short=7 HEAD), origen $(git -C "$B/src" rev-parse --short=7 main)"
[ "$(git -C "$B/api" status --porcelain --untracked-files=no | wc -l | tr -d ' ')" = 0 ] || muere "el clon de la «producción» del banco tiene cambios locales"
[ ! -f "$B/sup-voz-instalado" ] || "${E[@]}" worker retirar >> "$LOG" 2>&1
api2_parar
restaura_inicial
echo "== ensayo OPS8-d · banco $B · «producción» $(git -C "$B/api" rev-parse --short=7 HEAD) · registro $LOG"
echo "   reloj desplazado de la costura (publicar y la saliente): se fija al arrancar la segunda API, en la 4a (H-35)"
ctl_el '/__reset?state=1' > /dev/null; ctl_tw '/__reset?state=1' > /dev/null
R=$(php_real "$AYUDANTE" "$BE" cuentas "$TOK" 2>> "$LOG") || muere "no se pudieron crear las cuentas de los cuatro ojos (ver $LOG)"
ANA=$(jc accounts.a "$R"); BETO=$(jc accounts.b "$R")
caso "0 · cuentas de los cuatro ojos: Ana ($ANA) y Beto ($BETO), Super Admin activas, con sesión (tokens en 600)" "$([ -n "$ANA" ] && [ -n "$BETO" ] && [ "$ANA" != "$BETO" ] && echo 0 || echo 1)"
CANARIOS=$(php_real "$AYUDANTE" "$BE" canarios 2>> "$LOG"); C1=$(jc canaries.0 "$CANARIOS")
caso "0 · dos canarios de voz activos sembrados (sin teléfonos en la salida)" "$([ -n "$C1" ] && [ -n "$(jc canaries.1 "$CANARIOS")" ] && echo 0 || echo 1)"
paso "0 · mando · estado de partida: constante cerrada, todo apagado, ElevenLabs VERIFIED en el Studio" 0 'constante de llamadas: cerrada' "${E[@]}" alcance estado
printf '%s' "$SALIDA" | grep -q 'salientes: no' && printf '%s' "$SALIDA" | grep -q 'ElevenLabs según el Studio: ELEVENLABS_VERIFIED' && caso "0 · estado: salientes apagadas y ELEVENLABS_VERIFIED" 0 || caso "0 · estado: salientes apagadas y ELEVENLABS_VERIFIED" 1
paso "0 · mando · expectativa fase1 (monitor del H7 apagado; integrations-doctor la confirma) → APLICADA" 0 'MANDO expectativa-fase1 APLICADO' "${E[@]}" alcance expectativa fase1

# ── 0b. OPS8-e · B-T1 desde el punto de partida: el modo normal sigue negando la constante abierta; el 4a exige la fase 2 ─
AUT="OPS8E-AUT-4A-$(date -u +%Y%m%d)"   # la referencia de la orden escrita del propietario (simulada en el banco)
MSG_VOZ=$(printf 'voice(calls-open): banco OPS8-e: abre las llamadas salientes (ensayo; nunca en el repo)\n\nAutorización escrita del propietario: %s' "$AUT")
BASE1B=$(git -C "$B/api" rev-parse HEAD)
VOZ0=$(hace_commit "$BASE1B" "$MSG_VOZ" "$P_POLITICA" "$GIRO")
origen_a "$VOZ0"
# El modo NORMAL con la «producción» en PREVIO (la del banco) y la constante abierta en origin/main: llega a E10-7 y aborta.
git -C "$B/api" checkout -q --detach "$PREVIO_BANCO"
paso "0 · B-T1 · el modo NORMAL con voice(calls-open) en origin/main → aborta (la fase 1 no despliega la constante abierta)" 1 'la fase 1 no despliega la constante abierta' "${E[@]}" desplegar --esperado="$(corto "$VOZ0")" --solo-comprobar
git -C "$B/api" checkout -q main
caso "0 · la «producción» del banco vuelve a su main ($ESPERADO) sin cambios locales" "$([ "$(git -C "$B/api" rev-parse --short=7 HEAD)" = "$ESPERADO" ] && [ "$(git -C "$B/api" status --porcelain --untracked-files=no | wc -l | tr -d ' ')" = 0 ] && echo 0 || echo 1)"
BK0="$B/bk-4a-sin-fase2-$(date -u +%Y%m%d-%H%M%S)"
"${E[@]}" respaldo "$BK0" >> "$LOG" 2>&1
paso "0 · B-T1 · el modo 4a con la fase 2 sin hacer → aborta (la fase 2 no está completa; no se ha tocado nada)" 1 'la fase 2 no está completa' "${E[@]}" desplegar --fase=4a --autorizacion="$AUT" --esperado="$(corto "$VOZ0")" --respaldo="$BK0" --sin-worker --solo-comprobar
origen_a "$BASE1B"

# ── 0c. OPS8-e · B-T7: un perfil de voz creado y aprobado a cuatro ojos con TODO APAGADO (como el perfil 1 de producción,
# E16-30), y el smoke de la fase 1 tiene que seguir sin FALLO: el perfil es información; la ausencia de escrituras la exige
# el lanzador con el recuento exacto. (El perfil lo usa después la fase 2a.)
PERFIL_JSON='{"voiceId":"pNInz6obpgDQGcFmaJgB","greetings":{"inbound":"¿En qué te puedo ayudar hoy?","outbound":"Te llamo porque pediste información de Iron Body."},"holes":{"presentation":"Te ayudo con planes, horarios y la sede.","closing":"Gracias por tu tiempo."},"maxDurationSeconds":300,"tools":["consultar_planes","consultar_horarios","solicitar_humano","no_volver_a_llamar"],"systemTools":["end_call"],"transfer":{"destinations":[]},"privacy":{"retentionDays":30},"voicemail":"hang_up"}'
api a POST admin/ultron-studio/voice/profiles "{\"clientRequestId\":\"$(uuid)\",\"label\":\"Voz del ensayo OPS8-d\",\"content\":$PERFIL_JSON}"
PERFIL=$(jc body.data.uuid); caso "0 · perfil de voz con la fase 1: Ana lo crea (201, draft)" "$([ "$(jc http)" = 201 ] && [ -n "$PERFIL" ] && echo 0 || echo 1)" "HTTP $(jc http) $(jc body.code)"
api a POST "admin/ultron-studio/voice/profiles/$PERFIL/submit"
api a POST "admin/ultron-studio/voice/profiles/$PERFIL/approve"; caso "0 · perfil: Ana no aprueba el suyo (403)" "$([ "$(jc http)" = 403 ] && echo 0 || echo 1)" "HTTP $(jc http)"
api b POST "admin/ultron-studio/voice/profiles/$PERFIL/approve"; caso "0 · perfil: Beto lo aprueba (approved)" "$([ "$(jc http)" = 200 ] && [ "$(jc body.data.status)" = approved ] && echo 0 || echo 1)" "HTTP $(jc http) $(jc body.data.status)"
smoke 1 1-con-perfil
caso "0 · B-T7 · SMOKE_PHASE=1 con un perfil de voz aprobado: código 3, 0 FALLO, 1 OMITIDO (el presupuesto del laboratorio apagado) y el perfil como información" "$(smoke_ok 1 1 'GET voice/profiles (informativo: la fase 1 ya desplegada' 1)" "código $RC_SMOKE · $(resumen_smoke)"
if [ -n "${OPS8E_SMOKE_VIEJO:-}" ]; then
  # A/B: el smoke-h8.py de antes de OPS8-e en el MISMO estado (lo que pasó en producción el 10-11, E16-30).
  RC_SMOKE=0; SALIDA=$(env BANCO_SMOKE_PY="$OPS8E_SMOKE_VIEJO" SMOKE_PHASE=1 "${E[@]}" smoke 2>&1) || RC_SMOKE=$?
  { echo "════ smoke fase 1 con el smoke-h8.py de antes · código $RC_SMOKE"; printf '%s\n' "$SALIDA"; } >> "$LOG"; printf '%s\n' "$SALIDA" > "$B/logs/ensayo-ops8d-smoke-1-con-perfil-smoke-de-antes.txt"
  caso "0 · B-T7 (A/B) · el smoke-h8.py de antes, en el mismo estado: exactamente 1 FALLO funcional, «ningún perfil de voz»" "$([ "$(grep '^FALLO' <<< "$SALIDA" | grep -vEc "$TIEMPO" || true)" = 1 ] && grep -q '^FALLO   GET voice/profiles: ningún perfil de voz' <<< "$SALIDA" && echo 0 || echo 1)" "código $RC_SMOKE · $(resumen_smoke)"
fi

# ── 1. FASE 2: rotación de la clave, la base, el registro, --solo-clave, reconocer ─────────────────────────────────
prepara_el_verificar
rotar_clave_simulador
EL0=$(ctl_el /__stats); TW0=$(ctl_tw /__stats)   # la línea base de violaciones, tras el reinicio
caso "1 · rotación «en la consola»: el simulador rechaza la clave VIEJA (401) y acepta la NUEVA (200)" "$([ "$(sim_acepta_clave "$B/root/el-clave-vieja.txt")" = 401 ] && [ "$(sim_acepta_clave "$B/root/el-clave.txt")" = 200 ] && echo 0 || echo 1)"
H_SECRETOS0=$(huella_env ELEVENLABS_WEBHOOK_SECRET ELEVENLABS_TOOL_SECRET)
paso "1 · el-verificar.sh <fichero de la clave> <agent_id> (modo completo, ANTES del registro) → VERIFICACIÓN TERMINADA" 0 'ELEVENLABS VERIFICACIÓN TERMINADA' bash "$B/root/el-verificar.sh" "$B/root/el-clave.txt" "$(pieza EL_VERIFY_AGENT_ID)"
printf '%s' "$SALIDA" | grep -q 'modo: completo' && printf '%s' "$SALIDA" | grep -qE 'credentials_ok|válidas|valid' && caso "1 · el-verificar: modo completo y credenciales válidas contra el simulador" 0 || caso "1 · el-verificar: modo completo y credenciales válidas" 1
caso "1 · el-verificar: el fichero de la clave se destruyó y la clave del entorno es la nueva (sin imprimirla)" "$([ ! -e "$B/root/el-clave.txt" ] && [ "$(env_es_pieza ELEVENLABS_API_KEY EL_API_KEY)" = si ] && echo 0 || echo 1)"
caso "1 · el-verificar (modo completo): regeneró los dos secretos propios (webhook y herramientas), como antes del registro" "$([ "$(huella_env ELEVENLABS_WEBHOOK_SECRET ELEVENLABS_TOOL_SECRET)" != "$H_SECRETOS0" ] && echo 0 || echo 1)"
caso "1 · el-verificar: copia previa del entorno en \$B/root/env-pre-elevenlabs-* (600)" "$(ls "$B"/root/env-pre-elevenlabs-* > /dev/null 2>&1 && echo 0 || echo 1)"
printf '%s' "$SALIDA" | grep -qE 'xi_[0-9a-f]{12}' && caso "1 · el-verificar: ninguna clave en la salida" 1 || caso "1 · el-verificar: ninguna clave en la salida" 0
rm -f "$B/root/el-clave-vieja.txt"
paso "1 · mando · fase 2 set VOICE_WEBHOOK_BASE_URL (la base canónica) → APLICADO" 0 'MANDO fase2-set APLICADO' "${E[@]}" alcance fase 2 set "VOICE_WEBHOOK_BASE_URL=$(pieza TW_SIGN_BASE)"
paso "1 · registro · --solo-comprobar antes: FALTA ALGO (código 3; solo GET)" 3 'FALTA ALGO' "${E[@]}" secretos --solo-comprobar
printf '%s' "$SALIDA" | grep -q 'se generaría y se crearía' && printf '%s' "$SALIDA" | grep -q 'no existe: se crearía' && caso "1 · registro en seco: generaría el de iniciación y crearía los dos secretos y el webhook" 0 || caso "1 · registro en seco: qué haría" 1
paso "1 · registro · aplicar: SECRETOS Y WEBHOOK REGISTRADOS" 0 'SECRETOS Y WEBHOOK REGISTRADOS' "${E[@]}" secretos
O=$(oraculo); caso "1 · registro · oráculo: lo registrado en el simulador es EXACTAMENTE lo del entorno (huellas e ids)" "$([[ "$O" == 'herramientas=si iniciacion=si webhook=si secretos=2 webhooks=1' ]] && echo 0 || echo 1)" "$O"
paso "1 · registro · --solo-comprobar después: TODO REGISTRADO (código 0)" 0 'TODO REGISTRADO' "${E[@]}" secretos --solo-comprobar
# H-16: una rotación POSTERIOR al registro, con --solo-clave, conserva las referencias y los dos secretos registrados.
H_REG0=$(huella_env ELEVENLABS_WEBHOOK_SECRET ELEVENLABS_TOOL_SECRET ELEVENLABS_INITIATION_SECRET ELEVENLABS_TOOL_SECRET_REF ELEVENLABS_INITIATION_SECRET_REF ELEVENLABS_POST_CALL_WEBHOOK_REF ELEVENLABS_AGENT_ID ELEVENLABS_REGION)
( umask 077; pieza EL_API_KEY > "$B/root/el-clave.txt" )
paso "1 · el-verificar.sh <fichero> --solo-clave (rotación DESPUÉS del registro, H-16) → VERIFICACIÓN TERMINADA" 0 'ELEVENLABS VERIFICACIÓN TERMINADA' bash "$B/root/el-verificar.sh" "$B/root/el-clave.txt" --solo-clave
printf '%s' "$SALIDA" | grep -q 'modo: solo-clave' && caso "1 · --solo-clave: el guion dice su modo" 0 || caso "1 · --solo-clave: modo" 1
caso "1 · --solo-clave: los tres ids, los tres secretos, el agente y la región siguen byte a byte igual" "$([ "$(huella_env ELEVENLABS_WEBHOOK_SECRET ELEVENLABS_TOOL_SECRET ELEVENLABS_INITIATION_SECRET ELEVENLABS_TOOL_SECRET_REF ELEVENLABS_INITIATION_SECRET_REF ELEVENLABS_POST_CALL_WEBHOOK_REF ELEVENLABS_AGENT_ID ELEVENLABS_REGION)" = "$H_REG0" ] && echo 0 || echo 1)"
O=$(oraculo); caso "1 · --solo-clave: el registro sigue cuadrando con el simulador (post-llamada y herramientas no se rompen)" "$([[ "$O" == 'herramientas=si iniciacion=si webhook=si secretos=2 webhooks=1' ]] && echo 0 || echo 1)" "$O"
# Reconocer la configuración autorizada de la voz (la huella cambió con la clave, los secretos y las referencias).
reconocer_voz "OPS8-d: piezas de la fase 2 en el banco" && R=0 || R=1
caso "1 · reconocer la configuración autorizada de la voz (integrations/config/acknowledge, Beto) → 201" "$R" "HTTP $(jc http) $(jc body.code)"
paso "1 · mando · estado tras la fase 2: las tres referencias y la base presentes, todo apagado" 0 'constante de llamadas: cerrada' "${E[@]}" alcance estado
printf '%s' "$SALIDA" | grep -q 'VOICE_WEBHOOK_BASE_URL https://' && ! printf '%s' "$SALIDA" | grep -qE 'referencias \(ids, no secretos\): herramientas — ' && printf '%s' "$SALIDA" | grep -q 'ELEVENLABS_INITIATION_SECRET presente' && caso "1 · estado: base, referencias y secreto de iniciación presentes" 0 || caso "1 · estado: base, referencias y secreto de iniciación presentes" 1

# ── 2. FASE 2a: el laboratorio hablado, primero con el driver fake y después con el adaptador real ───────────────
paso "2a · mando · lab on --driver=fake (1.00/0.50 US$, 3 sesiones, 300 s) → APLICADO" 0 'MANDO lab-on APLICADO' "${E[@]}" alcance lab on --driver=fake --presupuesto-dia=1.00 --presupuesto-sesion=0.50 --sesiones-dia=3 --segundos=300
SES0=$(psq 'SELECT count(*) FROM ultron_studio_voice_lab_sessions;')
smoke 2a 2a-fake
caso "2a · SMOKE_PHASE=2a con el driver fake: código 3, 0 FALLO, los 3 POST del laboratorio OMITIDOS" "$(smoke_ok 2a 3 'laboratorio hablado encendido: abrir, cerrar o juzgar' 3)" "código $RC_SMOKE · $(resumen_smoke)"
caso "2a · el smoke no abrió ninguna sesión del laboratorio" "$([ "$(psq 'SELECT count(*) FROM ultron_studio_voice_lab_sessions;')" = "$SES0" ] && echo 0 || echo 1)"
# La versión del Studio a cuatro ojos (una sola vez) y el perfil de voz: Ana crea y envía, Beto aprueba.
api a GET admin/ultron-studio/draft
REV=$(jc body.data.revision)
if [ "$(jc body.data.exists)" != true ]; then
  CONT=$(printf '%s' "$RESP" | python3 -I -c 'import json, sys; print(json.dumps(json.load(sys.stdin)["body"]["data"]["content"], ensure_ascii=False))')
  api a PUT admin/ultron-studio/draft "{\"baseRevision\":$REV,\"content\":$CONT}"; REV=$(jc body.data.revision)
fi
api a POST admin/ultron-studio/versions "{\"label\":\"Versión del ensayo OPS8-d\",\"note\":null,\"draftRevision\":$REV,\"clientRequestId\":\"$(uuid)\"}"
VERSION=$(jc body.data.uuid); caso "2a · versión del Studio: Ana la crea (201)" "$([ "$(jc http)" = 201 ] && [ -n "$VERSION" ] && echo 0 || echo 1)" "HTTP $(jc http) $(jc body.code)"
api a POST "admin/ultron-studio/versions/$VERSION/submit"; AVISOS=$(jc body.data.warnings); [ -n "$AVISOS" ] || AVISOS='[]'
api a POST "admin/ultron-studio/versions/$VERSION/approve" "{\"acknowledgedWarnings\":$AVISOS}"; caso "2a · versión: Ana no aprueba la suya (403, cuatro ojos)" "$([ "$(jc http)" = 403 ] && echo 0 || echo 1)" "HTTP $(jc http)"
api b POST "admin/ultron-studio/versions/$VERSION/approve" "{\"acknowledgedWarnings\":$AVISOS}"; caso "2a · versión: Beto la aprueba (200)" "$([ "$(jc http)" = 200 ] && echo 0 || echo 1)" "HTTP $(jc http) $(jc body.data.status)"
# El perfil de voz, aprobado a cuatro ojos en el punto de partida (0c, B-T7: como el de producción, con la fase 1).
api a POST admin/ultron-studio/voice/compile-preview "{\"versionUuid\":\"$VERSION\",\"profileUuid\":\"$PERFIL\"}"
SPEC=$(jc body.data.specHash); FALTAN=$(jc body.data.deployable.missing)
caso "2a · compile-preview (sin red): specHash de 64 hex; para desplegar solo faltan las piezas del número y del cupo (las pone la fase 4a)" "$([ "$(jc http)" = 200 ] && [[ "$SPEC" =~ ^[0-9a-f]{64}$ ]] && python3 -I -c 'import json, sys; sys.exit(0 if set(json.loads(sys.argv[1] or "[]")) <= {"daily_call_cap", "phone_number_id"} else 1)' "$FALTAN" && echo 0 || echo 1)" "HTTP $(jc http) · faltan $FALTAN"
# La sesión con el driver FAKE, de principio a fin por la API.
VC0=$(psq 'SELECT count(*) FROM voice_calls;')
api a POST admin/ultron-studio/voice-lab/sessions "{\"versionUuid\":\"$VERSION\",\"profileUuid\":\"$PERFIL\",\"acceptedMaxCostMicros\":400000,\"clientRequestId\":\"$(uuid)\"}"
SESF=$(jc body.data.uuid); caso "2a · laboratorio (fake): Ana abre la sesión (201, modo fake, abierta, sin URL firmada)" "$([ "$(jc http)" = 201 ] && [ "$(jc body.data.mode)" = fake ] && [ "$(jc body.data.status)" = open ] && echo 0 || echo 1)" "HTTP $(jc http) $(jc body.code) modo $(jc body.data.mode) estado $(jc body.data.status)"
api a GET "admin/ultron-studio/voice-lab/sessions/$SESF"; caso "2a · laboratorio (fake): la sesión se lee (200)" "$([ "$(jc http)" = 200 ] && echo 0 || echo 1)" "HTTP $(jc http)"
api a POST "admin/ultron-studio/voice-lab/sessions/$SESF/end" '{"objectives":["aviso_ia","saludo"],"restrictionsBroken":[],"interruptions":0,"errors":[]}'
caso "2a · laboratorio (fake): Ana la cierra con lo que observó (ended, tester_ended)" "$([ "$(jc http)" = 200 ] && [ "$(jc body.data.status)" = ended ] && echo 0 || echo 1)" "HTTP $(jc http) $(jc body.data.status) $(jc body.data.endReason)"
api b POST "admin/ultron-studio/voice-lab/sessions/$SESF/verdict" "{\"decision\":\"approved\",\"specHash\":\"$SPEC\"}"
caso "2a · laboratorio (fake): el veredicto de OTRA identidad (Beto) lo NIEGA el código (409 voice_lab_session_not_reviewable, motivo fake_mode: una sesión del simulador no admite veredicto; StudioVoiceLabSessions::NOT_REVIEWABLE)" "$([ "$(jc http)" = 409 ] && [ "$(jc body.code)" = voice_lab_session_not_reviewable ] && [ "$(jc body.reason)" = fake_mode ] && echo 0 || echo 1)" "HTTP $(jc http) $(jc body.code) $(jc body.reason)"
caso "2a · laboratorio (fake): la sesión gastó 0 (cost_micros 0) y no escribió en voice_*" "$([ "$(psq "SELECT cost_micros FROM ultron_studio_voice_lab_sessions WHERE uuid = '$SESF';")" = 0 ] && [ "$(psq 'SELECT count(*) FROM voice_calls;')" = "$VC0" ] && echo 0 || echo 1)"
paso "2a · mando · lab off → APLICADO (los cuatro cerrojos)" 0 'MANDO lab-off APLICADO' "${E[@]}" alcance lab off
printf '%s' "$SALIDA" | grep -q 'lab_driver: "fake" → "fake"' || printf '%s' "$SALIDA" | grep -q 'lab_budget: "1.00" → "0"' && caso "2a · lab off: presupuestos a 0" 0 || caso "2a · lab off: presupuestos a 0" 1
paso "2a · mando · lab on --driver=elevenlabs (las referencias y la base puestas; VERIFIED) → APLICADO" 0 'MANDO lab-on APLICADO' "${E[@]}" alcance lab on --driver=elevenlabs --presupuesto-dia=1.00 --presupuesto-sesion=0.50 --sesiones-dia=3 --segundos=300
smoke 2a 2a-elevenlabs
caso "2a · SMOKE_PHASE=2a con el driver elevenlabs: código 3, 0 FALLO, los 3 POST del laboratorio OMITIDOS" "$(smoke_ok 2a 3 'laboratorio hablado encendido: abrir, cerrar o juzgar' 3)" "código $RC_SMOKE · $(resumen_smoke)"
EL_A=$(ctl_el /__stats); REJ0=$(cuenta_log 'post_call\.rejected')
api a POST admin/ultron-studio/voice-lab/sessions "{\"versionUuid\":\"$VERSION\",\"profileUuid\":\"$PERFIL\",\"acceptedMaxCostMicros\":400000,\"clientRequestId\":\"$(uuid)\"}"
SESE=$(jc body.data.uuid); AGL=$(jc body.data.agent.providerAgentId)
caso "2a · laboratorio (elevenlabs): Ana abre la sesión (201, modo elevenlabs, abierta, URL firmada presente y oculta, misma specHash)" "$([ "$(jc http)" = 201 ] && [ "$(jc body.data.mode)" = elevenlabs ] && [ "$(jc body.data.status)" = open ] && [ "$(jc body.data.connection.signedUrl)" = '[oculto]' ] && [ "$(jc body.data.agent.specHash)" = "$SPEC" ] && echo 0 || echo 1)" "HTTP $(jc http) $(jc body.code) modo $(jc body.data.mode)"
D=$(python3 -I -c 'import json, sys; a, b = json.loads(sys.argv[1]), json.loads(sys.argv[2]); r = lambda k: b["byRoute"].get(k, 0) - a["byRoute"].get(k, 0); print(r("/v1/convai/tools"), r("/v1/convai/agents/create"), r("/v1/convai/agents/{agent_id}"), r("/v1/convai/conversation/get-signed-url"), b["violations"] - a["violations"])' "$EL_A" "$(ctl_el /__stats)")
caso "2a · laboratorio (elevenlabs): en el simulador, 4 herramientas, 1 agente creado, 1 lectura y 1 URL firmada, 0 violaciones" "$([ "$D" = "4 1 1 1 0" ] && echo 0 || echo 1)" "$D"
PC=$(ctl_el "/__emit/post-call?web=1&agent_id=$AGL"); anota "emisor post-llamada web" "$PC"
FS=$(psq "SELECT status || ' ' || coalesce(end_reason, '-') || ' ' || coalesce(duration_seconds::text, '-') || ' ' || cost_micros || ' ' || reserved_micros FROM ultron_studio_voice_lab_sessions WHERE uuid = '$SESE';")
caso "2a · laboratorio (elevenlabs): la post-llamada web del simulador → 200 y la sesión se cierra por el informe del proveedor (64 s, coste ≤ reserva)" "$(python3 -I -c 'import json, sys; pc = json.loads(sys.argv[1]); st, er, du, co, re = sys.argv[2].split(); sys.exit(0 if pc["deliveries"][0]["status"] == 200 and st == "ended" and er == "provider_report" and du == "64" and 0 < int(co) <= int(re) else 1)' "$PC" "$FS" && echo 0 || echo 1)" "$FS"
caso "2a · laboratorio (elevenlabs): ningún voice.post_call.rejected nuevo en el registro de voz (el secreto del webhook casa)" "$([ "$(cuenta_log 'post_call\.rejected')" = "$REJ0" ] && echo 0 || echo 1)" "antes $REJ0 · después $(cuenta_log 'post_call\.rejected') (los de antes son los rechazos a propósito del smoke)"
api a POST "admin/ultron-studio/voice-lab/sessions/$SESE/verdict" "{\"decision\":\"approved\",\"specHash\":\"$SPEC\"}"; caso "2a · veredicto: Ana (abrió la sesión) no lo da (403)" "$([ "$(jc http)" = 403 ] && echo 0 || echo 1)" "HTTP $(jc http) $(jc body.code)"
api b POST "admin/ultron-studio/voice-lab/sessions/$SESE/verdict" "{\"decision\":\"approved\",\"specHash\":\"$SPEC\"}"; caso "2a · veredicto: Beto lo aprueba sobre la misma specHash (200)" "$([ "$(jc http)" = 200 ] && [ "$(jc body.data.verdict.decision)" = approved ] && echo 0 || echo 1)" "HTTP $(jc http) $(jc body.code)"
api a POST admin/ultron-studio/voice/compile-preview "{\"versionUuid\":\"$VERSION\",\"profileUuid\":\"$PERFIL\"}"
caso "2a · compile-preview: ahora con el veredicto approved del laboratorio sobre su huella" "$([ "$(jc body.data.labVerdict.latest)" = approved ] && [ "$(jc body.data.specHash)" = "$SPEC" ] && echo 0 || echo 1)" "$(jc body.data.labVerdict.latest)"

# ── 3. FASE 4a-PRIMERO: Twilio, el número, el monitor, publicar, el canario, la constante, el worker, las salientes ──
F="$B/root/tw-voz.txt"
"${E[@]}" piezas-twilio "$F" > /dev/null
paso "4a · mando · fase 2 secreto /root/tw-voz.txt (key de Laravel y token de firma) → APLICADO y el fichero destruido" 0 'MANDO fase2-secreto APLICADO' "${E[@]}" alcance fase 2 secreto "$F"
caso "4a · el fichero de las piezas secretas ya no existe" "$([ ! -e "$F" ] && echo 0 || echo 1)"
paso "4a · mando · fase 2 set de la cuenta, el número, el id importado y VOICE_NUMBER_INBOUND_CAPABLE=false → APLICADO" 0 'MANDO fase2-set APLICADO' "${E[@]}" alcance fase 2 set "TWILIO_VOICE_ACCOUNT_SID=$(pieza TW_ACCOUNT_SID)" "TWILIO_VOICE_FROM=$(pieza VOICE_NUMBER)" "ELEVENLABS_AGENT_PHONE_NUMBER_ID=$(pieza EL_PHONE_NUMBER_ID)" VOICE_NUMBER_INBOUND_CAPABLE=false
pon_env TWILIO_VOICE_BASE_URL "http://127.0.0.1:$PUERTO_TW"   # solo del banco: en production se ignora (la fase 1 la exige ausente)
ctl_tw '/__numero?tipo=caller_id&cuenta=subcuenta' > /dev/null
paso "4a · h8-twilio-check.sh: subcuenta con el número como CALLER ID VERIFICADO → APTA (código 0) con los avisos" 0 'VEREDICTO: APTA' "${E[@]}" twilio
printf '%s' "$SALIDA" | grep -q 'CALLER ID VERIFICADO' && printf '%s' "$SALIDA" | grep -q 'la key NO puede leer mensajes' && caso "4a · twilio-check: caller ID verificado y la sonda de mensajes denegada" 0 || caso "4a · twilio-check: caller ID y sonda" 1
reconocer_voz "OPS8-d: cuenta y número de Twilio en el banco" && R=0 || R=1
caso "4a · reconocer otra vez la configuración autorizada tras las piezas de Twilio (201)" "$R" "HTTP $(jc http) $(jc body.code)"
paso "4a · mando · expectativa fase2 (número puesto, Twilio Voice apagado) → APLICADA" 0 'MANDO expectativa-fase2 APLICADO' "${E[@]}" alcance expectativa fase2
TV=$(tareas_voz_banco "$B")   # OPS8-e: 4, o 5 con voice:webhook-health (B-T5), leídas del código del banco
paso "4a · mando · monitor on (la conciliación en seco no echaría el freno) → APLICADO, programador +$TV" 0 'MANDO monitor-on APLICADO' "${E[@]}" alcance monitor on
printf '%s' "$SALIDA" | grep -qE '4\. programador .* → [0-9]+ 0 '"$TV"' \(esperado' && caso "4a · monitor on: exactamente las $TV tareas de voz del código" 0 || caso "4a · monitor on: +$TV" 1
paso "4a · mando · publicar on → APLICADO" 0 'MANDO publicar-on APLICADO' "${E[@]}" alcance publicar on
fija_reloj
api2_arrancar
anota "segunda API (:$PUERTO_API2) con el reloj desplazado a $OBJETIVO" "$(head -3 "$B/logs/api2.out" 2>/dev/null)"
PUB="{\"versionUuid\":\"$VERSION\",\"profileUuid\":\"$PERFIL\",\"expectedSpecHash\":\"$SPEC\"}"
# H-26 (OPS8-d): publicar exige un cupo diario mayor que 0 (StudioVoicePublishing::missingPieces, daily_call_cap = el de
# salientes + el de entrantes). En la 4a-primero las entrantes están a 0 y el de salientes solo lo pone «salientes on»: el
# orden del runbook (publicar en el paso 5, salientes on en el 6) da 409 voice_not_deployable. Primero salientes on.
api b POST admin/ultron-studio/voice/publish "{\"clientRequestId\":\"$(uuid)\",${PUB#\{}" "$API2"
caso "4a · H-26 · publicar ANTES de salientes on (el orden del runbook) se niega: 409 voice_not_deployable, falta daily_call_cap" "$([ "$(jc http)" = 409 ] && [ "$(jc body.code)" = voice_not_deployable ] && [ "$(jc body.missing)" = '["daily_call_cap"]' ] && echo 0 || echo 1)" "HTTP $(jc http) $(jc body.code) $(jc body.missing)"
# ── B-T1 (OPS8-e): el commit voice(calls-open) lo despliega el guion en modo 4a, nunca una mano ──────────────────────
# En el ORIGEN del banco: un commit de SOLO documentación (como d701b0c, que producción no tiene) y, encima, el de la
# constante (una línea, cita la autorización). Nunca en el repo: el árbol de trabajo sigue con la constante cerrada.
BASE4A=$(git -C "$B/api" rev-parse HEAD)
DOCS=$(hace_commit "$BASE4A" "docs(ultron-studio-h9): banco OPS8-e: registro de un despliegue (solo documentación, como d701b0c)" backend/docs/ultron-studio/ULTRON_STUDIO_H8_CONTRACT.md '$s/$/ <!-- banco OPS8-e -->/')
VOZ=$(hace_commit "$DOCS" "$MSG_VOZ" "$P_POLITICA" "$GIRO")
origen_a "$VOZ"
BK4A="$B/bk-4a-$(date -u +%Y%m%d-%H%M%S)"
"${E[@]}" respaldo "$BK4A" >> "$LOG" 2>&1
caso "4a · B-T1 · en el origen del banco: un commit de solo documentación y encima voice(calls-open) de UNA línea que cita la autorización; el repo sigue con la constante cerrada" "$([ "$(git -C "$B/src" rev-parse main)" = "$VOZ" ] && [ "$(git -C "$B/src" diff --numstat "$VOZ^" "$VOZ")" = "1	1	$P_POLITICA" ] && [[ "$(git -C "$B/src" log -1 --format=%B "$VOZ")" == *"$AUT"* ]] && ! grep -qE 'CALLS_ENABLED_IN_CODE = true;' "$WT/backend/app/Services/Voice/VoiceCallPolicy.php" && echo 0 || echo 1)"
d4a() { "${E[@]}" desplegar --fase=4a --respaldo="$BK4A" "$@"; }
V7=$(corto "$VOZ"); D7=$(corto "$DOCS")
paso "4a · B-T1 · --fase=4a sin --autorizacion → uso (código 2), no se toca nada" 2 'exige --autorizacion' d4a --esperado="$V7" --solo-comprobar
paso "4a · B-T1 · una autorización que el commit no cita → aborta" 1 'no cita la autorización' d4a --autorizacion=OTRA-AUTORIZACION-99 --esperado="$V7" --acepta-main="$D7" --sin-worker --solo-comprobar
paso "4a · B-T1 · sin aceptar el commit de documentación → FALTA DECIDIR (código 3), no se toca nada" 3 'FALTA DECIDIR los commits de solo documentación' d4a --autorizacion="$AUT" --esperado="$V7" --sin-worker --solo-comprobar
paso "4a · B-T1 · --acepta-main de un commit que no viaja → aborta (nada a ciegas)" 1 'no se acepta nada a ciegas' d4a --autorizacion="$AUT" --esperado="$V7" --acepta-main="$D7,$(corto "$BASE4A")" --sin-worker --solo-comprobar
paso "4a · B-T1 · sin el worker y sin --sin-worker → aborta" 1 'ironbody-voice no está en RUNNING' d4a --autorizacion="$AUT" --esperado="$V7" --acepta-main="$D7" --solo-comprobar
paso "4a · B-T1 · --sin-worker y la documentación aceptada → COMPROBACIONES PREVIAS EN VERDE (modo 4a)" 0 'COMPROBACIONES PREVIAS EN VERDE · MODO 4a' d4a --autorizacion="$AUT" --esperado="$V7" --acepta-main="$D7" --sin-worker --solo-comprobar
# Lo que NO puede pasar: cada commit, un momento como main del origen (después vuelve a VOZ).
no_pasa() { # <nombre> <patrón ERE del aborto> <commit>
  origen_a "$3"
  paso "4a · B-T1 · $1 → aborta, no se toca nada" 1 "$2" d4a --autorizacion="$AUT" --esperado="$(corto "$3")" --acepta-main="$D7" --sin-worker --solo-comprobar
  origen_a "$VOZ"
}
no_pasa "voice(calls-open) que además cambia otra línea del fichero" 'no es de una línea' "$(hace_commit "$DOCS" "$MSG_VOZ" "$P_POLITICA" "$GIRO; s/EN ESTE HITO NUNCA PERMITE NADA/EN ESTE HITO PERMITE/")"
no_pasa "voice(calls-open) que además toca otro fichero (config/voice.php)" 'no cambia solo' "$(hace_commit "$DOCS" "$MSG_VOZ" "$P_POLITICA" "$GIRO" backend/config/voice.php '1s/$/ /')"
no_pasa "voice(calls-open) con la declaración en otra sangría" 'no es exactamente la declaración' "$(hace_commit "$DOCS" "$MSG_VOZ" "$P_POLITICA" 's/^[[:space:]]*public const CALLS_ENABLED_IN_CODE = false;/  public const CALLS_ENABLED_IN_CODE = true;/')"
no_pasa "la misma línea con otro asunto (feat(voz): …)" 'no es un commit «voice\(calls-open\)' "$(hace_commit "$DOCS" "$(printf 'feat(voz): abre las llamadas\n\nAutorización escrita del propietario: %s' "$AUT")" "$P_POLITICA" "$GIRO")"
CODIGO=$(hace_commit "$DOCS" "fix(ultron-studio-h8): banco OPS8-e: un cambio de código antes de la constante" backend/app/Services/Telephony/VoiceCostGuard.php '1s/$/ /')
no_pasa "un commit de código entre la producción y la constante" 'fuera de la documentación' "$(hace_commit "$CODIGO" "$MSG_VOZ" "$P_POLITICA" "$GIRO")"
VOZ_A=$(hace_commit "$DOCS" "$(printf 'voice(calls-open): banco OPS8-e: un primer commit «de la constante» que solo toca documentación\n\nAutorización escrita del propietario: %s' "$AUT")" backend/docs/ultron-studio/ULTRON_STUDIO_H8_CONTRACT.md '$s/$/ x/')
no_pasa "dos commits voice(calls-open) en el tramo" 'commits «voice\(calls-open\):» entre' "$(hace_commit "$VOZ_A" "$MSG_VOZ" "$P_POLITICA" "$GIRO")"
caso "4a · B-T1 · el main del origen vuelve al commit de la 4a y la «producción» sigue en el 1b" "$([ "$(git -C "$B/src" rev-parse main)" = "$VOZ" ] && [ "$(git -C "$B/api" rev-parse HEAD)" = "$BASE4A" ] && echo 0 || echo 1)"
# El worker (runbook 4a; la Activación lo pone ANTES de la constante): comprobar e instalar, y con él la 4a de verdad.
paso "4a · worker comprobar: el origen declara la conexión voice y la orden literal del borrador arranca y sale vacía" 0 'la orden del borrador arranca' "${E[@]}" worker comprobar
printf '%s' "$SALIDA" | grep -q 'declara la conexión voice' && caso "4a · worker comprobar: config/queue.php con la conexión voice" 0 || caso "4a · worker comprobar: conexión voice" 1
paso "4a · worker instalar: supervisorctl 13 → 14 programas" 0 'programas RUNNING: 14' "${E[@]}" worker instalar
paso "4a · B-T1 · --sin-worker con ironbody-voice en RUNNING → aborta (la declaración no es verdad)" 1 'sin-worker, pero ironbody-voice está en RUNNING' d4a --autorizacion="$AUT" --esperado="$V7" --acepta-main="$D7" --sin-worker --solo-comprobar
paso "4a · B-T1 · con el worker y la documentación aceptada → COMPROBACIONES PREVIAS EN VERDE (modo 4a, 14 programas)" 0 'COMPROBACIONES PREVIAS EN VERDE · MODO 4a' d4a --autorizacion="$AUT" --esperado="$V7" --acepta-main="$D7" --solo-comprobar
paso "4a · B-T1 · EL DESPLIEGUE 4a por el guion → DESPLIEGUE H8 FASE 4a … COMPLETADO, 0 desvíos" 0 'DESPLIEGUE H8 FASE 4a .*COMPLETADO' d4a --autorizacion="$AUT" --esperado="$V7" --acepta-main="$D7"
printf '%s\n' "$SALIDA" > "$B/logs/ensayo-ops8d-despliegue-4a.txt"
# Sin «printf | grep -q» sobre la salida del despliegue (grande): con pipefail, un SIGPIPE daría un FALLO falso.
INST=$(sed -n 's/^   instantáneas: \([0-9][0-9]*\) → \([0-9][0-9]*\)$/\1 \2/p' <<< "$SALIDA")
grep -q 'solo cambió la constante: calls_in_code false → true' <<< "$SALIDA" && grep -q 'fichero de entorno idéntico al respaldo' <<< "$SALIDA" \
  && [ -n "$INST" ] && [ "${INST% *}" = "${INST#* }" ] && grep -q 'filas de las 11 tablas del H8, las mismas que en el paso 0' <<< "$SALIDA" \
  && caso "4a · B-T1 · el despliegue solo cambió la constante: configuración efectiva, entorno = respaldo, instantáneas ($INST) y filas del H8 iguales" 0 || caso "4a · B-T1 · el despliegue solo cambió la constante" 1 "instantáneas [$INST]"
REG4A=$(ls -t "$B"/root/h8-fase4a-2*.log 2>/dev/null | head -1)
caso "4a · B-T1 · la referencia de la autorización queda en el registro del despliegue (cabecera y cierre)" "$([ -n "$REG4A" ] && [ "$(grep -cF "$AUT" "$REG4A")" -ge 2 ] && echo 0 || echo 1)" "${REG4A##*/}"
caso "4a · B-T1 · la «producción» del banco en el commit de la 4a: la constante abierta en el fichero y el último commit del fichero, voice(calls-open)" "$([ "$(git -C "$B/api" rev-parse HEAD)" = "$VOZ" ] && grep -qE '^[[:space:]]*public const CALLS_ENABLED_IN_CODE = true;[[:space:]]*$' "$BE/app/Services/Voice/VoiceCallPolicy.php" && [[ "$(git -C "$B/api" log -1 --format=%s -- "$P_POLITICA")" =~ ^voice\(calls-open\): ]] && echo 0 || echo 1)"
paso "4a · mando · salientes on --cupo=1 --presupuesto=2.00 → APLICADO (alcance canary, 14 workers)" 0 'MANDO salientes-on APLICADO' "${E[@]}" alcance salientes on --cupo=1 --presupuesto=2.00
printf '%s' "$SALIDA" | grep -q '(esperado 14 0 las dos veces)' && printf '%s' "$SALIDA" | grep -q 'outbound_cap: 0 → 1' && caso "4a · salientes on: 14 workers y cupo 1" 0 || caso "4a · salientes on: 14 workers y cupo 1" 1
paso "4a · mando · expectativa llamadas (Twilio Voice conectado) → APLICADA" 0 'MANDO expectativa-llamadas APLICADO' "${E[@]}" alcance expectativa llamadas
# H-25 (OPS8-d): salientes on cambia twilioVoice.enabled y dailyCallCap, que entran en la huella de la voz: sin reconocer
# otra vez, el monitor del H7 (en seco en el smoke; cada 30 min si está encendido) ve config_drift:voice. El mando no lo avisa.
reconocer_voz "OPS8-d: salientes encendidas (cupo 1, 2.00 US\$)" && R=0 || R=1
caso "4a · reconocer otra vez la configuración autorizada tras salientes on (H-25: Twilio Voice y el cupo entran en la huella)" "$R" "HTTP $(jc http) $(jc body.code)"
api a POST admin/ultron-studio/voice/compile-preview "{\"versionUuid\":\"$VERSION\",\"profileUuid\":\"$PERFIL\"}"
caso "4a · compile-preview: con el número, el id y el cupo puestos ya no falta nada para desplegar" "$([ "$(jc body.data.deployable.missing)" = '[]' ] && echo 0 || echo 1)" "faltan $(jc body.data.deployable.missing)"
# La publicación canaria, ya con el cupo (el orden que funciona: salientes on → publicar), a cuatro ojos y en ventana.
sleep 1.1   # O-1 (E9-6): un suceso anterior del mismo segundo contaría contra la publicación
api a POST admin/ultron-studio/voice/publish "{\"clientRequestId\":\"$(uuid)\",${PUB#\{}" "$API2"; caso "4a · publicar: Ana no publica lo que creó (403 four_eyes_required)" "$([ "$(jc http)" = 403 ] && [ "$(jc body.code)" = four_eyes_required ] && echo 0 || echo 1)" "HTTP $(jc http) $(jc body.code)"
EL_P=$(ctl_el /__stats)
api b POST admin/ultron-studio/voice/publish "{\"clientRequestId\":\"$(uuid)\",${PUB#\{}" "$API2"
caso "4a · publicar: Beto publica en canario (201, entrada 1) SIN tocar el número (not_applicable_outbound_only); en ventana por el reloj desplazado" "$([ "$(jc http)" = 201 ] && [ "$(jc body.data.entries.0.seq)" -ge 1 ] && [ "$(jc body.data.number.outcome)" = not_applicable_outbound_only ] && echo 0 || echo 1)" "HTTP $(jc http) $(jc body.code) · entrada $(jc body.data.entries.0.seq) · número $(jc body.data.number)"
caso "4a · publicar: en el simulador el número importado sigue SIN agente y no hubo PATCH al número" "$(python3 -I -c 'import json, sys; a, b = json.loads(sys.argv[1]), json.loads(sys.argv[2]); st = json.loads(sys.argv[3]); n = (st.get("numbers") or [{}])[0]; sys.exit(0 if n.get("agent") is None and b["byRoute"].get("/v1/convai/phone-numbers/{phone_number_id}", 0) == a["byRoute"].get("/v1/convai/phone-numbers/{phone_number_id}", 0) else 1)' "$EL_P" "$(ctl_el /__stats)" "$(ctl_el /__state)" && echo 0 || echo 1)"
paso "4a · mando · estado tras publicar: la cadena canary:ok" 0 'cadena=canary:ok,rest:none' "${E[@]}" alcance estado
smoke 4a 4a
caso "4a · SMOKE_PHASE=4a con la publicación canaria y el laboratorio encendido: código 3, 0 FALLO; publicar y promover (2) y los POST del laboratorio (3) OMITIDOS" "$(smoke_ok 4a 5 'publicar la voz encendido: publicar o promover' 2)" "código $RC_SMOKE · $(resumen_smoke)"
# La saliente punta a punta: la observación del número con el reloj del job, la puerta por la segunda API, el worker
# literal, contestada, su herramienta, su post-llamada, su coste y la segunda petición del día.
OBS=$(php_reloj "$AYUDANTE" "$BE" observar-numero 2>> "$LOG"); anota "observar-numero (reloj desplazado)" "$OBS"
caso "4a · la observación del número con el reloj desplazado cuadra (outbound_only declarado, matchesFrom, verified)" "$([[ "$(jc result "$OBS")" =~ ^(ok|outbound_only)$ ]] && [ "$(jc matchesFrom "$OBS")" = true ] && [ "$(jc policy "$OBS")" = verified ] && echo 0 || echo 1)" "$(printf '%s' "$OBS" | cut -c1-160)"
EL_S=$(ctl_el /__stats); TW_S=$(ctl_tw /__stats); LOG0=$(cuenta_log 'voice\.inbound\.|post_call\.rejected')
api a POST admin/voice/calls "{\"clientRequestId\":\"$(uuid)\",\"target\":{\"kind\":\"canary\",\"uuid\":\"$C1\"}}" "$API2"
LL=$(jc body.call.uuid); caso "4a · la puerta (POST admin/voice/calls, en horario por el reloj desplazado): 202 y la fila requested" "$([ "$(jc http)" = 202 ] && [ "$(jc body.call.status)" = requested ] && echo 0 || echo 1)" "HTTP $(jc http) $(jc body.code) $(jc body.reasons)"
W=$(worker_una_pasada 2>&1) || true; anota "worker (orden literal del borrador, una pasada)" "$W"
FILA=$(psq "SELECT status || ' ' || coalesce(provider_conversation_id, '-') || ' ' || coalesce(provider_call_sid, '-') FROM voice_calls WHERE uuid = '$LL';")
read -r EST CONV SID <<< "$FILA"
caso "4a · el worker marcó: dialing, conversación de ElevenLabs y SID de Twilio" "$([ "$EST" = dialing ] && [[ "$CONV" == conv_* ]] && [[ "$SID" =~ ^CA[0-9a-f]{32}$ ]] && echo 0 || echo 1)" "$EST"
D=$(python3 -I -c 'import json, sys; a, b, c, d = (json.loads(x) for x in sys.argv[1:5]); print(b["byRoute"].get("/v1/convai/twilio/outbound-call", 0) - a["byRoute"].get("/v1/convai/twilio/outbound-call", 0), d["byRoute"].get("/2010-04-01/Accounts/{sid}/Calls.json", 0) - c["byRoute"].get("/2010-04-01/Accounts/{sid}/Calls.json", 0), b["violations"] - a["violations"], d["violations"] - c["violations"])' "$EL_S" "$(ctl_el /__stats)" "$TW_S" "$(ctl_tw /__stats)")
caso "4a · UNA outbound-call a ElevenLabs y la llamada creada en Twilio (por ElevenLabs), 0 violaciones nuevas" "$([ "$D" = "1 1 0 0" ] && echo 0 || echo 1)" "$D"
caso "4a · la llamada en Twilio la creó la credencial de ElevenLabs (nunca la de Laravel ni la maestra)" "$([ "$(jc origin "$(ctl_tw "/__state?sid=$SID")")" = elevenlabs_key ] && echo 0 || echo 1)"
ctl_el "/__call?conversation_id=$CONV&event=answered" > /dev/null
T=$(ctl_el "/__tool?conversation_id=$CONV&tool=consultar_planes"); anota "herramienta consultar_planes" "$T"
caso "4a · contestada: la primera herramienta responde ok y la fila pasa a active" "$([ "$(jc laravel.body.verdict "$T")" = ok ] && [ "$(psq "SELECT status FROM voice_calls WHERE uuid = '$LL';")" = active ] && echo 0 || echo 1)" "$(jc laravel.body.verdict "$T")"
ctl_el "/__call?conversation_id=$CONV&event=completed&duration=42" > /dev/null
PC=$(ctl_el "/__emit/post-call?conversation_id=$CONV"); anota "post-llamada de la saliente" "$PC"
G=$(psq "SELECT status || ' ' || seal_verified || ' ' || coalesce(duration_seconds::text, '-') FROM voice_calls WHERE uuid = '$LL';")
caso "4a · la post-llamada firmada → 200, completed, seal_verified, 42 s" "$([ "$(jc deliveries.0.status "$PC")" = 200 ] && [ "$G" = "completed true 42" ] && echo 0 || echo 1)" "$G"
caso "4a · el veredicto de la guarda de la transcripción, sin violaciones (evento guard_verdict)" "$([ "$(psq "SELECT count(*) FROM voice_call_events e JOIN voice_calls c ON c.id = e.call_id WHERE c.uuid = '$LL' AND e.kind = 'guard_verdict' AND coalesce(e.outcome, 'ok') IN ('ok', 'clean', 'no_violations');")" -ge 1 ] && echo 0 || echo 1)" "$(psq "SELECT string_agg(kind || ':' || coalesce(outcome, '-'), ',' ORDER BY id) FROM voice_call_events e JOIN voice_calls c ON c.id = e.call_id WHERE c.uuid = '$LL';")"
RC=$(php_real artisan voice:reconcile --json 2>> "$LOG" || true); anota "voice:reconcile --json (reloj real)" "$RC"
PRECIO=$(python3 -I -c 'import json, sys; p = json.loads(sys.argv[1]).get("price"); print(int(round(abs(float(p)) * 1000000)) if p is not None else "")' "$(ctl_tw "/__state?sid=$SID")")
caso "4a · la conciliación (reloj real): la llamada es conocida (unknown 0) y su coste es el precio de Twilio" "$([ "$(jc unknown "$RC")" = 0 ] && [ -n "$PRECIO" ] && [ "$PRECIO" -gt 0 ] && [ "$(psq "SELECT carrier_cost_micros FROM voice_calls WHERE uuid = '$LL';")" = "$PRECIO" ] && echo 0 || echo 1)" "unknown $(jc unknown "$RC") · precio µUS\$ $PRECIO"
RD=$(php_real artisan voice:reconcile --dry-run --json 2>> "$LOG" || true); anota "voice:reconcile --dry-run --json" "$RD"
caso "4a · la conciliación en seco después: no echaría el freno y el freno sigue suelto" "$([ "$(jc wouldOpenBrake "$RD")" = false ] && [ "$(psq "SELECT count(*) FROM voice_brakes WHERE kind = 'open';")" = 0 ] && echo 0 || echo 1)" "haría freno $(jc wouldOpenBrake "$RD")"
api a POST admin/voice/calls "{\"clientRequestId\":\"$(uuid)\",\"target\":{\"kind\":\"canary\",\"uuid\":\"$C1\"}}" "$API2"
caso "4a · la segunda petición del mismo día: 409 con daily_cap_reached (cupo 1), sin fila nueva" "$([ "$(jc http)" = 409 ] && printf '%s' "$(jc body.reasons)" | grep -q daily_cap_reached && [ "$(psq "SELECT count(*) FROM voice_calls WHERE direction = 'outbound';")" = 1 ] && echo 0 || echo 1)" "HTTP $(jc http) $(jc body.reasons)"
caso "4a · en el registro de voz, ningún webhook de iniciación durante la saliente (E16-10) ni post-llamada rechazada (nada nuevo desde la puerta)" "$([ "$(cuenta_log 'voice\.inbound\.|post_call\.rejected')" = "$LOG0" ] && echo 0 || echo 1)" "antes $LOG0 · después $(cuenta_log 'voice\.inbound\.|post_call\.rejected')"
OBS=$(php_real "$AYUDANTE" "$BE" observar-numero 2>> "$LOG"); caso "4a · la observación del número con el reloj REAL vuelve a cuadrar (verified)" "$([ "$(jc policy "$OBS")" = verified ] && echo 0 || echo 1)"
api b GET admin/ultron-studio/voice/canary-evidence
caso "4a · GET voice/canary-evidence (informativo): calls 1 con su post-llamada, missingPostCall 0, cadena verificada, freno suelto" "$([ "$(jc body.data.measures.calls)" = 1 ] && [ "$(jc body.data.measures.missingPostCall)" = 0 ] && [ "$(jc body.data.chainVerified)" = true ] && echo 0 || echo 1)" "$(jc body.data.measures) · fallan $(jc body.data.failing)"
api2_parar
# La vuelta atrás completa (el nivel 0 no espera a nadie; el resto, en orden inverso).
paso "4a · vuelta atrás · salientes off → APLICADO (cupo y presupuesto a 0, Twilio Voice apagado)" 0 'MANDO salientes-off APLICADO' "${E[@]}" alcance salientes off
paso "4a · vuelta atrás · expectativa fase2 (Twilio Voice otra vez disabled) → APLICADA" 0 'MANDO expectativa-fase2 APLICADO' "${E[@]}" alcance expectativa fase2
paso "4a · vuelta atrás · publicar off → APLICADO" 0 'MANDO publicar-off APLICADO' "${E[@]}" alcance publicar off
paso "4a · vuelta atrás · monitor off → APLICADO (programador −$TV)" 0 'MANDO monitor-off APLICADO' "${E[@]}" alcance monitor off
paso "4a · vuelta atrás · lab off → APLICADO" 0 'MANDO lab-off APLICADO' "${E[@]}" alcance lab off
paso "4a · vuelta atrás · worker retirar: 14 → 13" 0 'programas RUNNING: 13' "${E[@]}" worker retirar
# B-T1 (OPS8-e): la vuelta atrás del código de la 4a es la del runbook (nivel 4), solo si la «producción» sigue en el commit
# de la 4a: checkout del commit del respaldo (umask 022), config:cache y route:cache, y queue:restart.
if [ "$(git -C "$B/api" rev-parse HEAD)" = "$VOZ" ]; then
  ( cd "$B/api" && umask 022 && git checkout -q --detach "$(cat "$BK4A/api-commit.txt")" )
  ( cd "$BE" && env "${RED[@]}" php artisan config:cache -q && env "${RED[@]}" php artisan route:cache -q && chmod 600 bootstrap/cache/config.php bootstrap/cache/routes-v7.php && env "${RED[@]}" php artisan queue:restart -q ) >> "$LOG" 2>&1
fi
caso "4a · vuelta atrás · el código de antes de la 4a (checkout del commit del respaldo, cachés, queue:restart): la constante cerrada en el fichero" "$([ "$(git -C "$B/api" rev-parse HEAD)" = "$(cat "$BK4A/api-commit.txt")" ] && grep -qE '^[[:space:]]*public const CALLS_ENABLED_IN_CODE = false;[[:space:]]*$' "$BE/app/Services/Voice/VoiceCallPolicy.php" && echo 0 || echo 1)"
paso "4a · vuelta atrás · estado final: constante cerrada y todo apagado" 0 'constante de llamadas: cerrada' "${E[@]}" alcance estado
printf '%s' "$SALIDA" | grep -q 'salientes: no' && printf '%s' "$SALIDA" | grep -q 'laboratorio hablado: no' && printf '%s' "$SALIDA" | grep -q 'monitor de voz: no' && caso "4a · todo apagado al terminar" 0 || caso "4a · todo apagado al terminar" 1
php_real "$AYUDANTE" "$BE" revocar >> "$LOG" 2>&1 || true

# ── 4. Cierre: ninguna violación nueva en los simuladores, la valla sin hosts de fuera, el registro sin secretos ───
EL1=$(ctl_el /__stats); TW1=$(ctl_tw /__stats)
dv() { python3 -I -c 'import json, sys; a, b = json.loads(sys.argv[1]), json.loads(sys.argv[2]); print(b["violations"] - a["violations"], json.dumps({k: v - a["violationsByCode"].get(k, 0) for k, v in b["violationsByCode"].items() if v - a["violationsByCode"].get(k, 0)}))' "$1" "$2"; }
caso "cierre · 0 violaciones nuevas en el simulador de ElevenLabs" "$([ "$(dv "$EL0" "$EL1" | cut -d' ' -f1)" = 0 ] && echo 0 || echo 1)" "$(dv "$EL0" "$EL1")"
caso "cierre · 0 violaciones nuevas en el simulador de Twilio" "$([ "$(dv "$TW0" "$TW1" | cut -d' ' -f1)" = 0 ] && echo 0 || echo 1)" "$(dv "$TW0" "$TW1")"
V=$(curl -s --max-time 5 "http://127.0.0.1:$PUERTO_VALLA/__stats" | python3 -I -c 'import json, sys; d = json.load(sys.stdin); print(" ".join(h for h in d["byHost"] if not h.startswith("h8-valla-sonda")) or "ninguno")')
caso "cierre · la valla no vio ningún host de fuera" "$([ "$V" = ninguno ] && echo 0 || echo 1)" "$V"
printf '%s\n' "$EL1" > "$B/logs/ensayo-ops8d-stats-elevenlabs.json"; printf '%s\n' "$TW1" > "$B/logs/ensayo-ops8d-stats-twilio.json"
BARRIDO=$(python3 -I - "$LOG" "$B/sim/piezas.conf" "$TOK" "$B"/logs/ensayo-ops8d-smoke-*.txt <<'PY'
import os, re, sys
log, piezas, tokens = sys.argv[1:4]
ficheros = [log] + sys.argv[4:]
# Solo las piezas SECRETAS (la clave y los secretos de ElevenLabs, las keys y los tokens de Twilio, los centinelas): el id
# del número importado, el agente de verificación y la cuenta no son secretos (config/voice.php) y el mando los imprime.
SECRETAS = {'EL_API_KEY', 'EL_WEBHOOK_SECRET', 'EL_TOOL_SECRET', 'EL_INITIATION_SECRET', 'TW_MASTER_TOKEN', 'TW_SIGNING_TOKEN',
            'TW_LARAVEL_KEY_SID', 'TW_LARAVEL_KEY_SECRET', 'TW_EL_KEY_SID', 'TW_EL_KEY_SECRET', 'SENTINEL_ELEVENLABS', 'SENTINEL_TWILIO'}
valores = set()
for l in open(piezas, encoding='utf-8'):
    if '=' in l and not l.startswith('#'):
        k, v = l.split('=', 1)
        if k.strip() in SECRETAS and len(v.strip()) >= 12:
            valores.add(v.strip())
for f in os.listdir(tokens):
    valores.add(open(os.path.join(tokens, f), encoding='utf-8').read().strip())
patrones = [r'(xi_|tool_|ini_|wsec_)[0-9a-f]{12}', r'SK[0-9a-f]{32}', r'\+57[0-9]{10}', r'57(300|601)0000[0-9]{4}']
hallazgos = []
for f in ficheros:
    s = open(f, encoding='utf-8', errors='replace').read()
    for v in valores:
        if v and v in s:
            hallazgos.append(f'{os.path.basename(f)}: una pieza o un token')
    for p in patrones:
        if re.search(p, s):
            hallazgos.append(f'{os.path.basename(f)}: /{p}/')
print(' · '.join(sorted(set(hallazgos))) or 'limpio')
PY
)
caso "cierre · el registro del ensayo y las salidas del smoke no llevan piezas, tokens ni teléfonos" "$([ "$BARRIDO" = limpio ] && echo 0 || echo 1)" "$BARRIDO"
chmod 600 "$LOG" "$B"/logs/ensayo-ops8d-*
echo "$((CASOS - FALLOS)) OK · $FALLOS FALLO · registro $LOG"
[ "$FALLOS" = 0 ]

#!/bin/bash
# El ensayo de OPS8-c en el banco del Hito 8: el mando h8-voice-scope.sh, el registro h8-secrets-register.sh y la
# comprobación h8-twilio-check.sh, cada uno en su copia del banco (preparar.sh) y con RED (la valla). Cada paso dice
# OK o FALLO con el código de salida y la línea que lo decide; la salida completa de cada orden queda en
# $B/logs/ensayo-ops8c-<fecha>.txt (600; las órdenes no imprimen secretos, y este script tampoco: las huellas se
# comparan aquí y solo sale «sí» o «no»).
# Requisitos: el banco montado (montar.sh), en marcha (ensayar.sh arrancar), con E1-12 (ensayar.sh e112), sembrado
# (sembrar.sh --canarios-voz) y con la FASE 1 desplegada (ensayar.sh desplegar --acepta-main=…). Se puede repetir: al
# empezar devuelve el fichero de entorno al de partida (el respaldo de E1-12 más las 9 claves de la fase 1, lo mismo
# que deja el despliegue), cierra la constante y retira el worker si una ejecución anterior se cortó, y vacía el estado
# de los simuladores. Al terminar lo deja igual: el fichero de partida, la constante cerrada y el worker retirado.
# Smokes: el de la fase 1 al principio (la regresión; necesita voice_calls vacía, así que en un banco recién montado)
# y, al final, el de la 2a y el de la 4a SIN publicación canaria (tiene que fallar por nombre en la cadena y en nada más).
# Uso: bash ensayar-ops8c.sh <banco>
. "$(dirname "$0")/comun.sh"
B=$(banco_dir "${1:-}")
cargar_banco "$B"
BE="$B/api/backend"
ENVB="$BE/.env"
export PATH="$B/bin:$PATH"   # los sustitutos del banco (sudo, stat, supervisorctl…): la vuelta atrás impresa los usa
E=(bash "$AQUI/ensayar.sh" "$B")
LOG="$B/logs/ensayo-ops8c-$(date -u +%Y%m%d-%H%M%S).txt"
( umask 077; : > "$LOG" )
vivo "$PUERTO_VALLA" && vivo "$PUERTO_EL" && vivo "$PUERTO_TW" || muere "arranca antes: bash $AQUI/ensayar.sh $B arrancar"
API="http://127.0.0.1:$BANCO_PORT/api"
SALIDA=""
# paso <nombre> <código esperado> <patrón ERE que tiene que salir> <orden…>
paso() {
  local nombre=$1 esperado=$2 patron=$3 rc=0
  shift 3
  SALIDA=$("$@" 2>&1) || rc=$?
  { echo "════ $nombre · código $rc"; printf '%s\n' "$SALIDA"; } >> "$LOG"
  if [ "$rc" = "$esperado" ] && printf '%s' "$SALIDA" | grep -qE "$patron"; then
    caso "$nombre" 0 "código $rc"
  else
    caso "$nombre" 1 "código $rc (esperado $esperado); $(printf '%s' "$SALIDA" | grep -E 'ABORTA|NO SE APLICA|✗|VEREDICTO|APLICADO|REGISTRADO|FALLO|ERROR' | head -3 | tr '\n' ' ' | cut -c1-260)"
  fi
}
ctl_el() { curl -s --max-time 5 "http://127.0.0.1:$PUERTO_EL$1"; }
ctl_tw() { curl -s --max-time 5 "http://127.0.0.1:$PUERTO_TW$1"; }
pieza() { python3 -I -c 'import sys; print(next((l.split("=", 1)[1].strip() for l in open(sys.argv[1]) if l.startswith(sys.argv[2] + "=")), ""))' "$B/sim/piezas.conf" "$1"; }
# El oráculo del registro: la huella sha256 de lo que guarda el simulador frente a la del fichero de entorno del banco
# (y los ids frente a las referencias). Solo imprime «sí» o «no».
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
# De extremo a extremo, contra la API del banco: la post-llamada firmada con el secreto del entorno (tipo desconocido
# válido → 200, D8-16) y con el de las piezas (el de antes del registro → 401); la iniciación con el secreto del entorno
# (200 y caller_authorized=no, con las entrantes apagadas) y con uno falso (401). Solo imprime los códigos.
extremo() {
  python3 -I - "$ENVB" "$B/sim/piezas.conf" "$API" <<'PY'
import hashlib, hmac, json, re, secrets, sys, time, urllib.error, urllib.request
env = open(sys.argv[1], encoding='utf-8').read()
pz = dict(l.strip().split('=', 1) for l in open(sys.argv[2], encoding='utf-8') if '=' in l and not l.startswith('#'))
def v(k):
    m = re.search(r'^[ \t]*' + re.escape(k) + r'[ \t]*=[ \t]*(.*?)[ \t]*$', env, re.M)
    return m.group(1) if m else ''
op = urllib.request.build_opener(urllib.request.ProxyHandler({}))
def post(ruta, cuerpo, cab):
    r = urllib.request.Request(sys.argv[3] + ruta, data=cuerpo, method='POST', headers={'Content-Type': 'application/json', 'Accept': 'application/json', **cab})
    try:
        with op.open(r, timeout=10) as x:
            return x.status, x.read()
    except urllib.error.HTTPError as e:
        return e.code, e.read()
def firma(sec, cuerpo):
    t = str(int(time.time()))
    return 't=%s,v0=%s' % (t, hmac.new(sec.encode(), (t + '.').encode() + cuerpo, hashlib.sha256).hexdigest())
pc = json.dumps({'type': 'ensayo_ops8c', 'event_timestamp': int(time.time()), 'data': {}}).encode()
a, _ = post('/webhooks/voice/elevenlabs/post-call', pc, {'ElevenLabs-Signature': firma(v('ELEVENLABS_WEBHOOK_SECRET'), pc)})
b, _ = post('/webhooks/voice/elevenlabs/post-call', pc, {'ElevenLabs-Signature': firma(pz['EL_WEBHOOK_SECRET'], pc)})
ini = json.dumps({'caller_id': '+573000000099', 'agent_id': 'agent_ensayo_ops8c', 'called_number': pz['VOICE_NUMBER'], 'call_sid': 'CA' + secrets.token_hex(16), 'conversation_id': 'conv_ensayo_' + secrets.token_hex(6)}).encode()
c, cuerpo = post('/webhooks/voice/elevenlabs/initiation', ini, {'X-Voice-Initiation-Secret': v('ELEVENLABS_INITIATION_SECRET')})
try:
    autorizado = json.loads(cuerpo)['dynamic_variables']['caller_authorized']
except Exception:
    autorizado = '?'
d, _ = post('/webhooks/voice/elevenlabs/initiation', ini, {'X-Voice-Initiation-Secret': secrets.token_hex(32)})
print('postllamada_nuevo=%s postllamada_viejo=%s iniciacion=%s autorizado=%s iniciacion_falsa=%s' % (a, b, c, autorizado, d))
PY
}
# Cambia en el fichero de entorno del banco UNA clave a un valor (o la vacía) sin imprimir nada: simula lo que harían
# el-verificar.sh (otro secreto de herramientas) o una ejecución anterior cortada (una referencia que falta).
pon_env() { python3 -I - "$ENVB" "$1" "$2" <<'PY'
import os, re, sys
p, k, v = sys.argv[1:4]
s = open(p, encoding='utf-8').read()
pat = re.compile(r'^([ \t]*)' + re.escape(k) + r'[ \t]*=.*$', re.M)
s = pat.sub(lambda m: m.group(1) + k + '=' + v, s, count=1) if pat.search(s) else s.rstrip('\n') + '\n  ' + k + '=' + v + '\n'
open(p, 'w', encoding='utf-8').write(s)
PY
  ( cd "$BE" && env "${RED[@]}" php artisan config:cache -q && chmod 600 bootstrap/cache/config.php )
}

# El fichero de entorno de partida: el respaldo de E1-12 más las 9 claves que añade el despliegue de la fase 1.
INICIAL="$B/root/env-ensayo-ops8c-inicial"
restaura_inicial() {
  if [ ! -f "$INICIAL" ]; then
    ( umask 077; { cat "$B/bk/env.backup"; [ -z "$(tail -c1 "$B/bk/env.backup")" ] || printf '\n'
      for kv in VOICE_INBOUND_ENABLED=false VOICE_OUTBOUND_ENABLED=false VOICE_LAB_ENABLED=false VOICE_MONITOR_ENABLED=false ULTRON_STUDIO_VOICE_PUBLISHING_ENABLED=false VOICE_INBOUND_DAILY_CAP=0 VOICE_DAILY_BUDGET_USD=0 VOICE_LAB_DAILY_BUDGET_USD=0 VOICE_LAB_DRIVER=fake; do printf '  %s\n' "$kv"; done; } > "$INICIAL" )
  fi
  install -m 600 "$INICIAL" "$ENVB"
  ( cd "$BE" && env "${RED[@]}" php artisan config:cache -q && chmod 600 bootstrap/cache/config.php )
}
grep -qE 'CALLS_ENABLED_IN_CODE = true;' "$BE/app/Services/Voice/VoiceCallPolicy.php" && "${E[@]}" constante cerrar >> "$LOG" 2>&1
[ ! -f "$B/sup-voz-instalado" ] || "${E[@]}" worker retirar >> "$LOG" 2>&1
restaura_inicial
echo "== ensayo OPS8-c · banco $B · registro $LOG"
ctl_el '/__reset?state=1' > /dev/null; ctl_tw '/__reset?state=1' > /dev/null
EL0=$(ctl_el /__stats); TW0=$(ctl_tw /__stats)
# El smoke de una fase contra la «producción» del banco (smoke-run-h8.sh del repo, con la valla): salida y código.
RC_SMOKE=0
smoke() { RC_SMOKE=0; SALIDA=$(env SMOKE_PHASE="$1" "${E[@]}" smoke 2>&1) || RC_SMOKE=$?; { echo "════ smoke fase $1 · código $RC_SMOKE"; printf '%s\n' "$SALIDA"; } >> "$LOG"; }

# ── 0. Regresión: el smoke de la fase 1 sigue siendo el de producción (49 OK · 0 FALLO · 1 OMITIDO, código 3) ──────
if [ "$(psql -X -Atq -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$BANCO_DB" -c 'SELECT count(*) FROM voice_calls;')" = 0 ]; then
  smoke 1
  caso "smoke · fase 1 (por defecto) sin cambios: código 3, 0 FALLO y el único OMITIDO el del laboratorio apagado" "$([ "$RC_SMOKE" = 3 ] && printf '%s' "$SALIDA" | grep -qE 'RESUMEN \(superadmin\): [0-9]+ OK · 0 FALLO · 1 OMITIDO' && ! printf '%s' "$SALIDA" | grep -q '^FALLO' && echo 0 || echo 1)" "código $RC_SMOKE · $(printf '%s' "$SALIDA" | grep -E 'RESUMEN|^FALLO' | head -3 | tr '\n' ' ' | cut -c1-200)"
else
  echo "OMITIDO smoke · fase 1: voice_calls no está vacía (una ejecución anterior de este ensayo dejó filas denied); se ensaya en un banco recién montado"
fi

# ── 1. El mando: estado y negativas (nada se toca) ─────────────────────────────────────────────────────────────
paso "mando · estado (todo apagado, constante cerrada, sin piezas de la fase 2)" 0 'constante de llamadas: cerrada' "${E[@]}" alcance estado
printf '%s' "$SALIDA" | grep -q 'salientes: no' && printf '%s' "$SALIDA" | grep -q 'secretos (solo presencia)' || caso "mando · estado enseña banderas y presencias" 1
paso "mando · salientes on con la constante cerrada → se niega (código 2)" 2 'CALLS_ENABLED_IN_CODE está cerrada' "${E[@]}" alcance salientes on --cupo=1 --presupuesto=2.00
paso "mando · entrantes on con la constante cerrada → se niega" 2 'voice\(calls-open\)' "${E[@]}" alcance entrantes on --cupo=1
paso "mando · lab on elevenlabs sin referencias ni base → se niega" 2 'faltan: VOICE_WEBHOOK_BASE_URL ELEVENLABS_TOOL_SECRET_REF' "${E[@]}" alcance lab on --driver=elevenlabs --presupuesto-dia=1.00 --presupuesto-sesion=0.50
paso "mando · lab on sin --driver → se niega" 2 'driver=elevenlabs\|fake es obligatorio' "${E[@]}" alcance lab on --presupuesto-dia=1.00 --presupuesto-sesion=0.50
paso "mando · lab on con presupuesto fuera de tope → se niega" 2 'presupuesto-dia' "${E[@]}" alcance lab on --driver=fake --presupuesto-dia=9.00 --presupuesto-sesion=0.50
paso "mando · fase 2 set de un SECRETO por argumentos → se niega" 2 'es un SECRETO' "${E[@]}" alcance fase 2 set ELEVENLABS_INITIATION_SECRET=x
paso "mando · fase 2 set de una clave fuera de la lista → se niega" 2 'no es una pieza' "${E[@]}" alcance fase 2 set VOICE_OUTBOUND_ENABLED=true
paso "mando · fase 2 set con una base http → se niega por forma" 2 'no es una pieza' "${E[@]}" alcance fase 2 set VOICE_WEBHOOK_BASE_URL=http://api.ironbody-banco.invalid
paso "mando · fase 2 set del número de WhatsApp como número de voz → se niega (protección 6)" 2 'WhatsApp' "${E[@]}" alcance fase 2 set "TWILIO_VOICE_FROM=$(pieza WHATSAPP_NUMBER)"
paso "mando · fase 2 set VOICE_NUMBER_INBOUND_CAPABLE con un código que no la lee → se niega" 2 'no lee VOICE_NUMBER_INBOUND_CAPABLE' "${E[@]}" alcance fase 2 set VOICE_NUMBER_INBOUND_CAPABLE=false
paso "mando · expectativa llamadas sin Twilio Voice conectado → se niega" 2 'integrations-doctor no confirma' "${E[@]}" alcance expectativa llamadas
chmod 644 "$ENVB"
paso "mando · fichero de entorno en 644 → aborta sin tocar nada (C-14)" 1 'no es un fichero normal de .* en 600 ni de .* en 640' "${E[@]}" alcance publicar on
chmod 600 "$ENVB"

# ── 2. Piezas no secretas y registro de secretos y webhook ─────────────────────────────────────────────────────
paso "mando · fase 2 set VOICE_WEBHOOK_BASE_URL (la base canónica del banco) → APLICADO" 0 'MANDO fase2-set APLICADO' "${E[@]}" alcance fase 2 set "VOICE_WEBHOOK_BASE_URL=$(pieza TW_SIGN_BASE)"
printf '%s' "$SALIDA" | grep -q 'webhook_base: null → "https://api.ironbody-banco.invalid"' && caso "mando · antes → después de la base, sin nada más" 0 || caso "mando · antes → después de la base" 1
paso "mando · la misma orden otra vez → nada que hacer (código 0, sin copia)" 0 'ya tiene esos valores' "${E[@]}" alcance fase 2 set "VOICE_WEBHOOK_BASE_URL=$(pieza TW_SIGN_BASE)"
paso "registro · --solo-comprobar antes: falta todo (código 3, solo GET)" 3 'FALTA ALGO' "${E[@]}" secretos --solo-comprobar
printf '%s' "$SALIDA" | grep -q 'se generaría y se crearía' && printf '%s' "$SALIDA" | grep -q 'no existe: se crearía' && caso "registro · en seco dice qué crearía y que generaría el de iniciación" 0 || caso "registro · en seco dice qué haría" 1
paso "registro · aplicar: genera el de iniciación, crea los dos secretos y el webhook, escribe las referencias" 0 'SECRETOS Y WEBHOOK REGISTRADOS' "${E[@]}" secretos
printf '%s' "$SALIDA" | grep -q 'ELEVENLABS_INITIATION_SECRET generado aquí' && caso "registro · el secreto de iniciación se generó en el servidor y no se imprimió" 0 || caso "registro · secreto de iniciación generado" 1
O=$(oraculo); caso "registro · oráculo: lo registrado en el simulador es EXACTAMENTE lo del entorno (huellas e ids)" "$([[ "$O" == 'herramientas=si iniciacion=si webhook=si secretos=2 webhooks=1' ]] && echo 0 || echo 1)" "$O"
X=$(extremo); caso "registro · de extremo a extremo: post-llamada con el secreto NUEVO 200 y con el VIEJO 401; iniciación 200 (no) y falsa 401" "$([[ "$X" == 'postllamada_nuevo=200 postllamada_viejo=401 iniciacion=200 autorizado=no iniciacion_falsa=401' ]] && echo 0 || echo 1)" "$X"
paso "registro · otra vez: idempotente, todo «ya registrado», nada nuevo en ElevenLabs" 0 'SECRETOS Y WEBHOOK REGISTRADOS' "${E[@]}" secretos
O=$(oraculo); caso "registro · sigue habiendo 2 secretos y 1 webhook (sin duplicados)" "$([[ "$O" == *'secretos=2 webhooks=1' ]] && echo 0 || echo 1)" "$O"
pon_env ELEVENLABS_TOOL_SECRET "$(openssl rand -hex 32)"
paso "registro · tras rotar el secreto de herramientas (como el-verificar.sh), sin --actualizar: «ya registrado» (el valor no se puede leer)" 0 'ya registrado' "${E[@]}" secretos
O=$(oraculo); caso "registro · el valor registrado de herramientas ya NO es el del entorno (lo que avisa el texto)" "$([[ "$O" == 'herramientas=no'* ]] && echo 0 || echo 1)" "$O"
paso "registro · --actualizar: PATCH del valor al mismo id" 0 'ACTUALIZADO' "${E[@]}" secretos --actualizar
O=$(oraculo); caso "registro · tras --actualizar, otra vez exactamente lo del entorno" "$([[ "$O" == 'herramientas=si iniciacion=si webhook=si secretos=2 webhooks=1' ]] && echo 0 || echo 1)" "$O"
pon_env ELEVENLABS_INITIATION_SECRET_REF ""
paso "registro · referencia vacía con el secreto ya en ElevenLabs: FALTA DECIDIR (código 3), sin duplicar" 3 'FALTA DECIDIR --actualizar' "${E[@]}" secretos
paso "registro · --reusar: escribe su id sin tocar el valor" 0 'REUSADO' "${E[@]}" secretos --reusar
O=$(oraculo); caso "registro · tras --reusar, todo cuadra" "$([[ "$O" == 'herramientas=si iniciacion=si webhook=si secretos=2 webhooks=1' ]] && echo 0 || echo 1)" "$O"
paso "registro · --solo-comprobar al final: TODO REGISTRADO" 0 'TODO REGISTRADO' "${E[@]}" secretos --solo-comprobar
paso "registro · --actualizar y --reusar juntas → se niega" 2 'excluyentes' "${E[@]}" secretos --actualizar --reusar

# ── 3. El resto de la fase 2: número, cuenta y las piezas secretas de Twilio ──────────────────────────────────────
paso "mando · fase 2 set del número, el id importado y la subcuenta → APLICADO" 0 'MANDO fase2-set APLICADO' "${E[@]}" alcance fase 2 set "TWILIO_VOICE_FROM=$(pieza VOICE_NUMBER)" "ELEVENLABS_AGENT_PHONE_NUMBER_ID=$(pieza EL_PHONE_NUMBER_ID)" "TWILIO_VOICE_ACCOUNT_SID=$(pieza TW_ACCOUNT_SID)"
F="$B/root/tw-piezas.txt"
( umask 077; printf 'TWILIO_VOICE_API_KEY_SID=no-es-un-sid\nTWILIO_VOICE_API_KEY_SECRET=%s\n' "$(openssl rand -hex 16)" > "$F" )
paso "mando · fase 2 secreto con una clave fuera de forma → se niega, el fichero se queda y se avisa" 2 'sigue en disco' "${E[@]}" alcance fase 2 secreto "$F"
printf '%s' "$SALIDA" | grep -q 'no tiene la forma esperada' && [ -e "$F" ] && caso "mando · el fichero de secretos rechazado sigue en disco (para repetir sin volver a la consola)" 0 || caso "mando · el fichero rechazado sigue en disco" 1
rm -f -- "$F"
"${E[@]}" piezas-twilio "$F" > /dev/null
paso "mando · fase 2 secreto <fichero> (key de Laravel y token de firma) → APLICADO y el fichero destruido" 0 'MANDO fase2-secreto APLICADO' "${E[@]}" alcance fase 2 secreto "$F"
caso "mando · el fichero de las piezas secretas ya no existe" "$([ ! -e "$F" ] && echo 0 || echo 1)"
printf '%s' "$SALIDA" | grep -qE '(xi_|tool_|ini_|wsec_)[0-9a-f]{8}|SK[0-9a-f]{32}' && caso "mando · ningún secreto en la salida" 1 || caso "mando · ningún secreto en la salida" 0

# La base del simulador de Twilio para el adaptador de Laravel (la conciliación en seco de «monitor on»): una pieza SOLO
# del banco (en production se ignora y el host es api.twilio.com; la fase 1 la exige ausente).
pon_env TWILIO_VOICE_BASE_URL "http://127.0.0.1:$PUERTO_TW"

# ── 4. La comprobación de Twilio, escenario a escenario ────────────────────────────────────────────────────────
paso "twilio · subcuenta de la del OTP, número comprado, voice_url de ElevenLabs → APTA" 0 'VEREDICTO: APTA' "${E[@]}" twilio
printf '%s' "$SALIDA" | grep -q 'la key NO puede leer mensajes' && caso "twilio · la sonda de mensajes: denegada (lo esperado)" 0 || caso "twilio · la sonda de mensajes" 1
ctl_tw '/__numero?tipo=caller_id' > /dev/null
paso "twilio · caller ID verificado («Outbound Only») → APTA con los avisos de salientes solo y de la declaración" 0 'CALLER ID VERIFICADO' "${E[@]}" twilio
printf '%s' "$SALIDA" | grep -q 'VOICE_NUMBER_INBOUND_CAPABLE=false' && caso "twilio · pide declarar VOICE_NUMBER_INBOUND_CAPABLE=false" 0 || caso "twilio · pide la declaración" 1
ctl_tw '/__numero?cuenta=madre' > /dev/null
paso "twilio · la voz en una CUENTA MADRE → VEREDICTO SUBCUENTA (código 3) con el procedimiento del caller ID" 3 'VEREDICTO: SUBCUENTA' "${E[@]}" twilio
printf '%s' "$SALIDA" | grep -q 'se verifica OTRA VEZ en la subcuenta' && caso "twilio · procedimiento del caller ID: verificar otra vez, sin comprar" 0 || caso "twilio · procedimiento del caller ID" 1
ctl_tw '/__numero?tipo=comprado&bundle=pendiente&address=local&voice_url=otra' > /dev/null
paso "twilio · cuenta madre con número comprado → procedimiento de traslado oficial (AccountSid destino)" 3 'trasladarlo SIN COMPRAR' "${E[@]}" twilio
printf '%s' "$SALIDA" | grep -q 'bundle regulatorio en estado pending-review' && printf '%s' "$SALIDA" | grep -q 'exige dirección (local)' && printf '%s' "$SALIDA" | grep -q 'ivr.banco.invalid' && caso "twilio · bundle pendiente, dirección y voice_url ajena, avisados" 0 || caso "twilio · bundle, dirección y voice_url" 1
ctl_tw '/__numero?cuenta=subcuenta&bundle=aprobado&address=none&voice_url=elevenlabs' > /dev/null
ctl_tw "/__seed-call?direction=inbound&from=%2B573000000077&to=%2B576019999999&status=completed" > /dev/null
paso "twilio · una llamada ajena en la ventana → avisa (la conciliación echaría el freno)" 0 'NO son del número de voz' "${E[@]}" twilio
ctl_tw '/__numero?sms_laravel=1' > /dev/null
paso "twilio · una key de la voz con permisos de mensajería → NO APTA (código 3)" 3 'VEREDICTO: NO APTA' "${E[@]}" twilio
ctl_tw '/__numero?sms_laravel=0' > /dev/null

# ── 5. Monitor, publicar, laboratorio y expectativa ─────────────────────────────────────────────────────────────
paso "mando · monitor on con una llamada ajena en la ventana → se niega (la primera pasada echaría el freno)" 2 'echaría el freno' "${E[@]}" alcance monitor on
TV=$(tareas_voz_banco "$B")   # OPS8-e: 4, o 5 con voice:webhook-health (B-T5), leídas del código del banco
paso "mando · monitor on --acepta-freno → APLICADO, programador +$TV tareas de voz" 0 'MANDO monitor-on APLICADO' "${E[@]}" alcance monitor on --acepta-freno
printf '%s' "$SALIDA" | grep -qE '4\. programador .* → [0-9]+ 0 '"$TV"' \(esperado' && caso "mando · el programador ganó exactamente las $TV tareas de voz del código" 0 || caso "mando · programador +$TV" 1
paso "mando · monitor off → APLICADO, programador −$TV" 0 'MANDO monitor-off APLICADO' "${E[@]}" alcance monitor off
ctl_tw '/__reset?state=1' > /dev/null
paso "mando · monitor on sin llamadas ajenas → la conciliación en seco no echaría el freno → APLICADO" 0 'MANDO monitor-on APLICADO' "${E[@]}" alcance monitor on
paso "mando · monitor off" 0 'MANDO monitor-off APLICADO' "${E[@]}" alcance monitor off
paso "mando · publicar on → APLICADO" 0 'MANDO publicar-on APLICADO' "${E[@]}" alcance publicar on
COPIA=$(printf '%s' "$SALIDA" | sed -n 's/^MANDO publicar-on APLICADO (copia previa: \(.*\))$/\1/p')
VUELTA=$(printf '%s' "$SALIDA" | sed -n 's/^   vuelta atrás exacta (el fichero de antes): //p')
( eval "export ${RED[*]}; $VUELTA" ) > /dev/null 2>&1 && cmp -s "$ENVB" "$COPIA" && caso "mando · la vuelta atrás EXACTA que imprime deja el fichero byte a byte como la copia previa" 0 || caso "mando · la vuelta atrás exacta" 1 "$COPIA"
paso "mando · tras la vuelta atrás, publicar está apagado" 0 'publicar la voz \(Studio\): no' "${E[@]}" alcance estado
paso "mando · lab on elevenlabs con todo puesto → APLICADO (cuatro cerrojos abiertos a propósito)" 0 'MANDO lab-on APLICADO' "${E[@]}" alcance lab on --driver=elevenlabs --presupuesto-dia=1.00 --presupuesto-sesion=0.50 --sesiones-dia=3 --segundos=120
paso "mando · lab off → APLICADO (bandera, driver fake y presupuestos a 0)" 0 'MANDO lab-off APLICADO' "${E[@]}" alcance lab off
printf '%s' "$SALIDA" | grep -q 'lab_driver: "elevenlabs" → "fake"' && printf '%s' "$SALIDA" | grep -q 'lab_budget: "1.00" → "0"' && caso "mando · lab off cierra los cuatro cerrojos" 0 || caso "mando · lab off cierra los cuatro cerrojos" 1
paso "mando · expectativa fase2 (número puesto, Twilio Voice apagado) → APLICADO" 0 'MANDO expectativa-fase2 APLICADO' "${E[@]}" alcance expectativa fase2

# ── 6. Llamadas: la constante girada SOLO en el clon del banco ──────────────────────────────────────────────────
"${E[@]}" constante abrir >> "$LOG" 2>&1
paso "mando · salientes on con la constante abierta pero sin el worker ironbody-voice → se niega" 2 'ironbody-voice no está en RUNNING' "${E[@]}" alcance salientes on --cupo=1 --presupuesto=2.00
"${E[@]}" worker instalar >> "$LOG" 2>&1
paso "mando · salientes on con un presupuesto que no cubre ni una llamada → se niega (VoiceCostGuard)" 2 'no cubre ni la reserva' "${E[@]}" alcance salientes on --cupo=1 --presupuesto=0.30
paso "mando · salientes on --cupo=1 --presupuesto=2.00 → APLICADO (alcance canary, 14 workers)" 0 'MANDO salientes-on APLICADO' "${E[@]}" alcance salientes on --cupo=1 --presupuesto=2.00
printf '%s' "$SALIDA" | grep -q 'outbound_scope' || true
printf '%s' "$SALIDA" | grep -q '(esperado 14 0 las dos veces)' && caso "mando · workers 14 antes y después" 0 || caso "mando · workers 14" 1
paso "mando · fase 2 set con las salientes encendidas → se niega" 2 'con las salientes y las entrantes apagadas' "${E[@]}" alcance fase 2 set "VOICE_WEBHOOK_BASE_URL=$(pieza TW_SIGN_BASE)"
paso "mando · salientes off → APLICADO (nivel 0; cupo y presupuesto a 0; Twilio Voice apagado)" 0 'MANDO salientes-off APLICADO' "${E[@]}" alcance salientes off
paso "mando · entrantes on --cupo=1 → APLICADO" 0 'MANDO entrantes-on APLICADO' "${E[@]}" alcance entrantes on --cupo=1
paso "mando · entrantes off → APLICADO" 0 'MANDO entrantes-off APLICADO' "${E[@]}" alcance entrantes off
"${E[@]}" worker retirar >> "$LOG" 2>&1
"${E[@]}" constante cerrar >> "$LOG" 2>&1
paso "mando · estado final: constante cerrada y todo apagado" 0 'constante de llamadas: cerrada' "${E[@]}" alcance estado
printf '%s' "$SALIDA" | grep -q 'salientes: no' && printf '%s' "$SALIDA" | grep -q 'entrantes: no' && printf '%s' "$SALIDA" | grep -q 'laboratorio hablado: no' && caso "mando · todo apagado al terminar" 0 || caso "mando · todo apagado al terminar" 1

# ── 7. Smoke de las fases 2a y 4a ──────────────────────────────────────────────────────────────────────────────
restaura_inicial; ctl_el '/__reset?state=1' > /dev/null; ctl_tw '/__reset?state=1' > /dev/null
"${E[@]}" alcance fase 2 set "VOICE_WEBHOOK_BASE_URL=$(pieza TW_SIGN_BASE)" >> "$LOG" 2>&1
"${E[@]}" secretos >> "$LOG" 2>&1
"${E[@]}" alcance lab on --driver=elevenlabs --presupuesto-dia=1.00 --presupuesto-sesion=0.50 --sesiones-dia=3 --segundos=300 >> "$LOG" 2>&1
smoke 2a
caso "smoke · fase 2a (laboratorio encendido): código 3, 0 FALLO; los 3 POST del laboratorio OMITIDOS, sin abrir sesiones" "$([ "$RC_SMOKE" = 3 ] && printf '%s' "$SALIDA" | grep -qE 'RESUMEN \(superadmin\): [0-9]+ OK · 0 FALLO · [0-9]+ OMITIDO' && [ "$(printf '%s' "$SALIDA" | grep -c 'laboratorio hablado encendido: abrir, cerrar o juzgar')" = 3 ] && ! printf '%s' "$SALIDA" | grep -q '^FALLO' && echo 0 || echo 1)" "código $RC_SMOKE · $(printf '%s' "$SALIDA" | grep -E 'RESUMEN|^FALLO' | head -4 | tr '\n' ' ' | cut -c1-260)"
caso "smoke · fase 2a: ninguna sesión del laboratorio creada" "$([ "$(psql -X -Atq -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$BANCO_DB" -c 'SELECT count(*) FROM ultron_studio_voice_lab_sessions;')" = 0 ] && echo 0 || echo 1)"
"${E[@]}" alcance lab off >> "$LOG" 2>&1
# 4a SIN publicación canaria: todo lo demás de la fase encendido. El smoke tiene que fallar SOLO por la cadena.
"${E[@]}" alcance fase 2 set "TWILIO_VOICE_FROM=$(pieza VOICE_NUMBER)" "ELEVENLABS_AGENT_PHONE_NUMBER_ID=$(pieza EL_PHONE_NUMBER_ID)" "TWILIO_VOICE_ACCOUNT_SID=$(pieza TW_ACCOUNT_SID)" >> "$LOG" 2>&1
"${E[@]}" piezas-twilio "$F" > /dev/null; "${E[@]}" alcance fase 2 secreto "$F" >> "$LOG" 2>&1
pon_env TWILIO_VOICE_BASE_URL "http://127.0.0.1:$PUERTO_TW"
"${E[@]}" constante abrir >> "$LOG" 2>&1
"${E[@]}" worker instalar >> "$LOG" 2>&1
"${E[@]}" alcance monitor on >> "$LOG" 2>&1
"${E[@]}" alcance publicar on >> "$LOG" 2>&1
"${E[@]}" alcance salientes on --cupo=1 --presupuesto=2.00 >> "$LOG" 2>&1
smoke 4a
FALLOS_4A=$(printf '%s' "$SALIDA" | grep -E '^FALLO' || true)
caso "smoke · fase 4a sin publicación canaria: falla, y SOLO por la cadena (telefonía, cadena y entradas de voice/publications)" "$([ "$RC_SMOKE" = 1 ] && [ -n "$FALLOS_4A" ] && ! printf '%s\n' "$FALLOS_4A" | grep -vqE 'cadena|voice/publications' && echo 0 || echo 1)" "$(printf '%s\n' "$FALLOS_4A" | cut -c1-110 | tr '\n' '|' | cut -c1-330)"
caso "smoke · fase 4a: banderas, cupos y piezas de la fase en verde; publicar y promover OMITIDOS" "$(printf '%s' "$SALIDA" | grep -q '^OK .*banderas de la fase 4a' && printf '%s' "$SALIDA" | grep -q '^OK .*cupo de salientes ≥ 1' && printf '%s' "$SALIDA" | grep -q '^OK .*el número de voz, el id importado y la subcuenta' && [ "$(printf '%s' "$SALIDA" | grep -c 'publicar la voz encendido: publicar o promover')" = 2 ] && echo 0 || echo 1)"
"${E[@]}" alcance salientes off >> "$LOG" 2>&1
"${E[@]}" alcance publicar off >> "$LOG" 2>&1
"${E[@]}" alcance monitor off >> "$LOG" 2>&1
"${E[@]}" worker retirar >> "$LOG" 2>&1
"${E[@]}" constante cerrar >> "$LOG" 2>&1
restaura_inicial

# ── 8. Cierre: ninguna violación nueva en los simuladores y la valla sin hosts de fuera ──────────────────────────
EL1=$(ctl_el /__stats); TW1=$(ctl_tw /__stats)
dv() { python3 -I -c 'import json, sys; a, b = json.loads(sys.argv[1]), json.loads(sys.argv[2]); print(b["violations"] - a["violations"], json.dumps({k: v - a["violationsByCode"].get(k, 0) for k, v in b["violationsByCode"].items() if v - a["violationsByCode"].get(k, 0)}))' "$1" "$2"; }
caso "cierre · 0 violaciones nuevas en el simulador de ElevenLabs" "$([ "$(dv "$EL0" "$EL1" | cut -d' ' -f1)" = 0 ] && echo 0 || echo 1)" "$(dv "$EL0" "$EL1")"
caso "cierre · 0 violaciones nuevas en el simulador de Twilio" "$([ "$(dv "$TW0" "$TW1" | cut -d' ' -f1)" = 0 ] && echo 0 || echo 1)" "$(dv "$TW0" "$TW1")"
V=$(curl -s --max-time 5 "http://127.0.0.1:$PUERTO_VALLA/__stats" | python3 -I -c 'import json, sys; d = json.load(sys.stdin); print(" ".join(h for h in d["byHost"] if not h.startswith("h8-valla-sonda")) or "ninguno")')
caso "cierre · la valla no vio ningún host de fuera" "$([ "$V" = ninguno ] && echo 0 || echo 1)" "$V"
grep -qE '(xi_|tool_|ini_|wsec_)[0-9a-f]{12}' "$LOG" && caso "cierre · el registro del ensayo no lleva secretos con forma de las piezas" 1 || caso "cierre · el registro del ensayo no lleva secretos con forma de las piezas" 0
chmod 600 "$LOG"
echo "$((CASOS - FALLOS)) OK · $FALLOS FALLO · registro $LOG"
[ "$FALLOS" = 0 ]

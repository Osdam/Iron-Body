#!/bin/bash
# OPS8-e (10-11-2026, auditoría de los 9 hitos): el ensayo en el banco de lo que OPS8-e cambia en los mandos, fuera del
# despliegue de la 4a (ese va en ensayar-ops8d.sh):
#   B-T13  h7-monitor-scope.sh «on» se NIEGA (código 2, sin tocar nada) si el monitor en seco de las integraciones no sale
#          con 0 con la expectativa de hoy: con deriva de la configuración autorizada (salientes off sin reconocer, H-25) y
#          con la expectativa del código (sin ULTRON_STUDIO_MONITOR_EXPECT: ELEVENLABS_NOT_CONFIGURED). La vía es
#          h8-voice-scope.sh «expectativa»; con ella, enciende y apaga. «estado» enseña la expectativa, si el estado de hoy
#          la confirma y el usuario efectivo de schedule:run (B-4; en el Mac, crontab -l -u … no tiene permiso: «ninguna»).
#   B-T14  h6-integrations-scope.sh y h7-monitor-scope.sh con el fichero de entorno en 640 (dueño:grupo) encienden y
#          APAGAN; en 644 siguen abortando sin tocar nada, también al apagar. Con ironbody-voice instalado esperan 14.
#   B-T4   h8-voice-scope.sh «contactos on|off»: con el código del banco (a608450, sin la bandera) se niega; con el código
#          de h8-hardening (4 ficheros aditivos del árbol de trabajo, copiados UN MOMENTO al clon de la «producción» del
#          banco y restaurados al terminar; nunca se tocan en el repo): estado la enseña; con la bandera apagada, el alta
#          responde 409 voice_contacts_disabled antes de validar; «contactos on» → 422 (valida); «off» → 409 otra vez.
#   B-T5   (de h8-hardening) h8-voice-scope.sh «monitor on|off» espera las tareas de voz que programa el código desplegado
#          (4 en a608450; 5 con voice:webhook-health), leídas del código.
# Corre DESPUÉS de ensayar-ops8d.sh sobre el mismo banco, en marcha (fase 2 hecha, todo apagado, constante cerrada).
# Cada caso dice OK o FALLO; la salida completa queda en $B/logs/ensayo-ops8e-<fecha>.txt (600; sin secretos).
# Uso: bash ensayar-ops8e.sh <banco>
. "$(dirname "$0")/comun.sh"
B=$(banco_dir "${1:-}")
cargar_banco "$B"
BE="$B/api/backend"
ENVB="$BE/.env"
export PATH="$B/bin:$PATH"   # los sustitutos del banco (sudo, stat, supervisorctl…)
E=(bash "$AQUI/ensayar.sh" "$B")
PHPBIN=$(command -v php)
LOG="$B/logs/ensayo-ops8e-$(date -u +%Y%m%d-%H%M%S).txt"
( umask 077; : > "$LOG" )
vivo "$PUERTO_VALLA" && vivo "$PUERTO_EL" && vivo "$PUERTO_TW" || muere "arranca antes: bash $AQUI/ensayar.sh $B arrancar"
API="http://127.0.0.1:$BANCO_PORT/api"
AYUDANTE="$AQUI/ayudante-ops8d.php"
TOK="$B/tokens"; mkdir -p "$TOK"; chmod 700 "$TOK"
SALIDA=""; RESP=""
php_real() { ( cd "$BE" && env "${RED[@]}" "$PHPBIN" "$@" ); }
# paso <nombre> <código esperado> <patrón ERE que tiene que salir> <orden…> (el de ensayar-ops8d.sh)
paso() {
  local nombre=$1 esperado=$2 patron=$3 rc=0
  shift 3
  SALIDA=$("$@" 2>&1) || rc=$?
  { echo "════ $nombre · código $rc"; printf '%s\n' "$SALIDA"; } >> "$LOG"
  if [ "$rc" = "$esperado" ] && grep -qE "$patron" <<< "$SALIDA"; then
    caso "$nombre" 0 "código $rc"
  else
    caso "$nombre" 1 "código $rc (esperado $esperado); $(grep -E 'ABORTA|NO SE APLICA|✗|APLICADO|FALLO|ERROR' <<< "$SALIDA" | head -3 | tr '\n' ' ' | cut -c1-260)"
  fi
}
anota() { { echo "════ $1"; shift; printf '%s\n' "$@"; } >> "$LOG"; }
jc() { printf '%s' "${2:-$RESP}" | python3 -I -c 'import json, sys
d = json.load(sys.stdin)
for p in sys.argv[1].split("."):
    d = (d[int(p)] if p.isdigit() and 0 <= int(p) < len(d) else None) if isinstance(d, list) else (d.get(p) if isinstance(d, dict) else None)
print(json.dumps(d, ensure_ascii=False) if isinstance(d, (dict, list, bool)) else ("" if d is None else d))' "$1" 2>/dev/null || true; }
uuid() { python3 -I -c 'import uuid; print(uuid.uuid4())'; }
# Una petición con la sesión de Ana (a) o de Beto (b); RESP = {"http": …, "body": …} (sin tokens), anotada en el registro.
api() {
  local quien=$1 metodo=$2 ruta=$3 cuerpo=${4:-}
  RESP=$(python3 -I - "$TOK/token-$quien" "$metodo" "$API/$ruta" "$cuerpo" <<'PY'
import json, sys, urllib.error, urllib.request
tokf, metodo, url, cuerpo = sys.argv[1:5]
tok = open(tokf, encoding='utf-8').read().strip()
op = urllib.request.build_opener(urllib.request.ProxyHandler({}))
data = cuerpo.encode() if cuerpo else None
cab = {'Accept': 'application/json', 'Authorization': 'Bearer ' + tok}
if data is not None:
    cab['Content-Type'] = 'application/json'
try:
    with op.open(urllib.request.Request(url, data=data, method=metodo, headers=cab), timeout=60) as r:
        st, body = r.status, r.read()
except urllib.error.HTTPError as e:
    st, body = e.code, e.read()
try:
    j = json.loads(body)
except Exception:
    j = {'_raw': body[:200].decode(errors='replace')}
print(json.dumps({'http': st, 'body': j}, ensure_ascii=False, separators=(',', ':')))
PY
)
  anota "API $quien $metodo $ruta" "$(printf '%s' "$RESP" | cut -c1-800)"
}
# Reconocer la configuración autorizada de la voz (Beto), con el reintento de H-28.
reconocer_voz() {
  local intento huella
  for intento in 1 2 3; do
    api b GET admin/ultron-studio/integrations/config
    huella=$(printf '%s' "$RESP" | python3 -I -c 'import json, sys; d = json.load(sys.stdin)["body"]; ps = d.get("data", d).get("providers") if isinstance(d.get("data", d), dict) else None; v = next((p for p in (ps or []) if isinstance(p, dict) and p.get("provider") == "voice"), None) if isinstance(ps, list) else ((ps or {}).get("voice") if isinstance(ps, dict) else None); print((v or {}).get("effectiveFingerprint") or "")' 2>/dev/null || true)
    [ -n "$huella" ] || return 1
    api b POST admin/ultron-studio/integrations/config/acknowledge "{\"clientRequestId\":\"$(uuid)\",\"provider\":\"voice\",\"fingerprint\":\"$huella\",\"note\":\"$1\"}"
    [ "$(jc http)" = 201 ] && return 0
    [ "$(jc body.code)" = config_changed ] || return 1
    sleep 1.5
  done
  return 1
}
# Quita una clave del fichero de entorno del banco (mismo inodo, mismo modo) y rehace la caché.
quita_env() { python3 -I - "$ENVB" "$1" <<'PY'
import re, sys
p, k = sys.argv[1:3]
s = open(p, encoding='utf-8').read()
s2 = re.sub(r'(?m)^[ \t]*(?:export[ \t]+)?' + re.escape(k) + r'[ \t]*=.*\n?', '', s)
with open(p, 'r+', encoding='utf-8') as f:
    f.seek(0); f.write(s2); f.truncate()
PY
  ( cd "$BE" && env "${RED[@]}" php artisan config:cache -q && chmod 600 bootstrap/cache/config.php )
}
huella_fichero() { shasum -a 256 "$ENVB" | cut -c1-16; }
pieza() { python3 -I -c 'import sys; print(next((l.split("=", 1)[1].strip() for l in open(sys.argv[1]) if l.startswith(sys.argv[2] + "=")), ""))' "$B/sim/piezas.conf" "$1"; }
modo_fichero() { /usr/bin/stat -f '%Su:%Sg %Lp' "$ENVB"; }
YO=$(whoami); GRUPO=$(id -gn)

# ── 0. Punto de partida ───────────────────────────────────────────────────────────────────────────────────────────
[ "$(git -C "$B/api" status --porcelain --untracked-files=no | wc -l | tr -d ' ')" = 0 ] || muere "el clon de la «producción» del banco tiene cambios locales"
grep -qE '^[[:space:]]*public const CALLS_ENABLED_IN_CODE = false;[[:space:]]*$' "$BE/app/Services/Voice/VoiceCallPolicy.php" || muere "la constante no está cerrada en la «producción» del banco: correr antes ensayar-ops8d.sh hasta su vuelta atrás"
[ ! -f "$B/sup-voz-instalado" ] || "${E[@]}" worker retirar >> "$LOG" 2>&1
chmod 600 "$ENVB"
echo "== ensayo OPS8-e · banco $B · «producción» $(git -C "$B/api" rev-parse --short=7 HEAD) · registro $LOG"
R=$(php_real "$AYUDANTE" "$BE" cuentas "$TOK" 2>> "$LOG") || muere "no se pudieron crear las cuentas de los cuatro ojos (ver $LOG)"
caso "0 · cuentas de los cuatro ojos con sesión (tokens en 600)" "$([ -n "$(jc accounts.a "$R")" ] && [ -n "$(jc accounts.b "$R")" ] && echo 0 || echo 1)"
paso "0 · h7 estado (solo lectura): el monitor apagado, su expectativa, si se confirma, el crontab (B-4) y el fichero (C-14)" 0 'expectativa del monitor: ' "${E[@]}" h7 estado
grep -q 'expectativa del monitor: twilio:connected_otp_only,twilio_voice:disabled,elevenlabs:ELEVENLABS_VERIFIED' <<< "$SALIDA" && caso "0 · B-T13 · estado enseña la expectativa de la fase 2 (la que dejó la vuelta atrás de ensayar-ops8d.sh)" 0 || caso "0 · B-T13 · estado enseña la expectativa" 1
grep -qE 'schedule:run \(B-4\), usuario efectivo de cada línea activa .*: (ninguna|[a-z_-]+=[0-9]+( [a-z_-]+=[0-9]+)*) · correcto: ' <<< "$SALIDA" && caso "0 · B-4 · estado dice el usuario EFECTIVO de schedule:run (en el Mac, sin permiso para leer los crontab: ninguna)" 0 || caso "0 · B-4 · estado y quién ejecuta schedule:run" 1
grep -q "fichero de entorno: $YO:$GRUPO 600 (C-14" <<< "$SALIDA" && caso "0 · C-14 · estado admite el fichero en 600" 0 || caso "0 · C-14 · estado y el fichero" 1

# ── 1. B-T13 · con deriva de la configuración autorizada, «on» se niega ──────────────────────────────────────────────
# La deriva se PROVOCA con el mando (la base de los webhooks entra en la huella de la voz, voiceFacts) para no depender de lo
# que dejó ensayar-ops8d.sh (su «salientes off» sin reconocer, H-25) y poder repetir el ensayo; después vuelve y se reconoce.
BASE_WH=$(pieza TW_SIGN_BASE)
paso "1 · mando · fase 2 set VOICE_WEBHOOK_BASE_URL a otra base (mueve la huella de la voz: deriva)" 0 'MANDO fase2-set APLICADO' "${E[@]}" alcance fase 2 set VOICE_WEBHOOK_BASE_URL=https://deriva-ops8e.invalid
H0=$(huella_fichero)
paso "1 · B-T13 · h7 on con deriva de la configuración autorizada (la provocada con el mando) → NO SE APLICA (código 2)" 2 'NO SE APLICA \(no se ha tocado nada\): el monitor en seco' "${E[@]}" h7 on off
caso "1 · el fichero de entorno no cambió (huella antes y después) y no hubo copia previa" "$([ "$(huella_fichero)" = "$H0" ] && ! grep -q 'copia previa' <<< "$SALIDA" && echo 0 || echo 1)"
paso "1 · mando · la base de los webhooks de vuelta a la canónica del banco" 0 'MANDO fase2-set APLICADO' "${E[@]}" alcance fase 2 set "VOICE_WEBHOOK_BASE_URL=$BASE_WH"
reconocer_voz "OPS8-e: reconocer tras la deriva provocada (y salientes off, H-25)" && R=0 || R=1
caso "1 · reconocer la configuración autorizada de la voz (Beto) → 201" "$R" "HTTP $(jc http) $(jc body.code)"
paso "1 · B-T13 · h7 estado: el estado de hoy ya confirma la expectativa" 0 'la confirma el estado de hoy \(monitor en seco, integraciones\): sí' "${E[@]}" h7 estado

# ── 2. B-T13 · con la expectativa del código (sin ULTRON_STUDIO_MONITOR_EXPECT), «on» se niega; la vía es el mando de la voz
quita_env ULTRON_STUDIO_MONITOR_EXPECT
H0=$(huella_fichero)
paso "2 · B-T13 · sin ULTRON_STUDIO_MONITOR_EXPECT (la del código: ELEVENLABS_NOT_CONFIGURED) → h7 on se niega (código 2)" 2 'NO SE APLICA .*h8-voice-scope.sh expectativa' "${E[@]}" h7 on off
caso "2 · el fichero de entorno no cambió" "$([ "$(huella_fichero)" = "$H0" ] && echo 0 || echo 1)"
paso "2 · B-T13 · h7 estado: la del código y NO confirmada" 0 '\(la del código, sin ULTRON_STUDIO_MONITOR_EXPECT\) · la confirma el estado de hoy .*: NO' "${E[@]}" h7 estado
paso "2 · la vía: h8-voice-scope.sh expectativa fase2 (integrations-doctor la confirma) → APLICADO" 0 'MANDO expectativa-fase2 APLICADO' "${E[@]}" alcance expectativa fase2
paso "2 · B-T13 · h7 on off con la expectativa confirmada → APLICADO (programador +2)" 0 'MANDO monitor → on APLICADO' "${E[@]}" h7 on off
grep -q 'expectativa del monitor: twilio:connected_otp_only,twilio_voice:disabled,elevenlabs:ELEVENLABS_VERIFIED (el estado de hoy la confirma' <<< "$SALIDA" && grep -qE '3\. programador \(total · del Studio\): [0-9]+ 0 → [0-9]+ 2' <<< "$SALIDA" \
  && caso "2 · on: dice la expectativa confirmada y las 2 tareas del Studio" 0 || caso "2 · on: expectativa y programador" 1
paso "2 · h7 off on (la vuelta atrás de nivel 2) → APLICADO" 0 'MANDO monitor → off APLICADO' "${E[@]}" h7 off on

# ── 3. B-T14 (C-14) · el fichero de entorno en 640 enciende y apaga; en 644 sigue abortando, también al apagar ─────────
chmod 640 "$ENVB"
caso "3 · el fichero de entorno del banco en 640 ($YO:$GRUPO)" "$([ "$(modo_fichero)" = "$YO:$GRUPO 640" ] && echo 0 || echo 1)" "$(modo_fichero)"
paso "3 · B-T14 · h7 on off con el fichero en 640 → APLICADO" 0 'MANDO monitor → on APLICADO' "${E[@]}" h7 on off
caso "3 · tras escribir, el fichero sigue en 640 (mismo dueño, grupo y modo)" "$([ "$(modo_fichero)" = "$YO:$GRUPO 640" ] && echo 0 || echo 1)" "$(modo_fichero)"
chmod 644 "$ENVB"; H0=$(huella_fichero)
paso "3 · B-T14 · h7 off on (APAGAR) con el fichero en 644 → ABORTA sin tocar nada (644 sigue sin admitirse)" 1 'no es un fichero normal de .* en 600 ni de .* en 640' "${E[@]}" h7 off on
caso "3 · con 644, el fichero no cambió" "$([ "$(huella_fichero)" = "$H0" ] && echo 0 || echo 1)"
paso "3 · B-T14 · h6 writes on off con el fichero en 644 → ABORTA sin tocar nada" 1 'no es un fichero normal de .* en 600 ni de .* en 640' "${E[@]}" h6 writes on off
chmod 640 "$ENVB"
paso "3 · B-T14 · h7 off on (APAGAR) con el fichero en 640 → APLICADO" 0 'MANDO monitor → off APLICADO' "${E[@]}" h7 off on
paso "3 · B-T14 · h6 writes on off con el fichero en 640 → APLICADO" 0 'MANDO writes → on APLICADO' "${E[@]}" h6 writes on off
paso "3 · B-T14 · h6 writes off on (APAGAR) con el fichero en 640 → APLICADO" 0 'MANDO writes → off APLICADO' "${E[@]}" h6 writes off on
caso "3 · el fichero sigue en 640 tras los cuatro cambios" "$([ "$(modo_fichero)" = "$YO:$GRUPO 640" ] && echo 0 || echo 1)" "$(modo_fichero)"
chmod 600 "$ENVB"

# ── 4. Con ironbody-voice instalado (fase 4a del H8), los dos mandos esperan 14 programas ───────────────────────────────
paso "4 · worker instalar: 13 → 14 programas" 0 'programas RUNNING: 14' "${E[@]}" worker instalar
paso "4 · h7 on off con ironbody-voice instalado → APLICADO, esperado 14 0" 0 'MANDO monitor → on APLICADO' "${E[@]}" h7 on off
grep -q '(esperado 14 0: 13 y ironbody-voice)' <<< "$SALIDA" && caso "4 · h7 on: workers esperados 14" 0 || caso "4 · h7 on: workers esperados 14" 1
paso "4 · h7 off on con 14 → APLICADO" 0 'MANDO monitor → off APLICADO' "${E[@]}" h7 off on
paso "4 · h6 writes on off con 14 → APLICADO" 0 'MANDO writes → on APLICADO' "${E[@]}" h6 writes on off
grep -q '(esperado 14 0: 13 y ironbody-voice)' <<< "$SALIDA" && caso "4 · h6 writes on: workers esperados 14" 0 || caso "4 · h6 writes on: workers esperados 14" 1
paso "4 · h6 writes off on con 14 → APLICADO" 0 'MANDO writes → off APLICADO' "${E[@]}" h6 writes off on
paso "4 · worker retirar: 14 → 13" 0 'programas RUNNING: 13' "${E[@]}" worker retirar

# ── 5. B-T4 · «contactos on|off» del mando de la voz ─────────────────────────────────────────────────────────────────
paso "5 · estado del mando de la voz con el código del banco: no lee la bandera de contactos" 0 'contactos de voz .*el código desplegado no lee la bandera' "${E[@]}" alcance estado
H0=$(huella_fichero)
paso "5 · contactos on con un código que no lee VOICE_CONTACTS_ENABLED → NO SE APLICA (código 2)" 2 'no lee VOICE_CONTACTS_ENABLED' "${E[@]}" alcance contactos on
paso "5 · contactos off con ese código → NO SE APLICA (código 2)" 2 'no lee VOICE_CONTACTS_ENABLED' "${E[@]}" alcance contactos off
caso "5 · el fichero de entorno no cambió" "$([ "$(huella_fichero)" = "$H0" ] && echo 0 || echo 1)"
# El código de h8-hardening (B-T4): 4 ficheros del árbol de trabajo, aditivos e idénticos a a608450 salvo la bandera,
# copiados un momento al clon de la «producción» del banco. Se guardan los originales y se restauran al terminar.
# VoiceTelephonyStatus.php del árbol trae además el `webhookHealth` de B-T5 (con clases que el banco no tiene: un 500, visto
# en la primera pasada): de ese fichero se toma SOLO la línea de B-T4 (`flags.contacts`), insertada en el original del banco.
FICHEROS_BT4="config/voice.php app/Services/Telephony/VoiceContacts.php app/Http/Controllers/Crm/Voice/VoiceContactController.php app/Services/Telephony/VoiceTelephonyStatus.php"
ORIG="$B/run/ops8e-originales"; rm -rf "$ORIG"; mkdir -p "$ORIG"
for f in $FICHEROS_BT4; do mkdir -p "$ORIG/$(dirname "$f")"; cp -p "$BE/$f" "$ORIG/$f"; done
for f in config/voice.php app/Services/Telephony/VoiceContacts.php app/Http/Controllers/Crm/Voice/VoiceContactController.php; do cp "$WT/backend/$f" "$BE/$f"; done
python3 -I - "$BE/app/Services/Telephony/VoiceTelephonyStatus.php" <<'LINEA'
import sys
p = sys.argv[1]
s = open(p, encoding='utf-8').read()
a = "                'monitor' => config('voice.monitor.enabled') === true,\n"
assert s.count(a) == 1, 'VoiceTelephonyStatus.php del banco no tiene la línea del monitor'
open(p, 'w', encoding='utf-8').write(s.replace(a, a + "                'contacts' => VoiceContacts::enabled(),\n"))
LINEA
anota "código de B-T4 copiado al banco (sha256 de los 4 ficheros)" "$(cd "$BE" && shasum -a 256 $FICHEROS_BT4)"
( cd "$BE" && env "${RED[@]}" php artisan config:cache -q && chmod 600 bootstrap/cache/config.php )
paso "5 · con el código de B-T4: estado la enseña apagada" 0 'contactos de voz \(alta y consentimiento, VOICE_CONTACTS_ENABLED, B-T4\): no \(409 voice_contacts_disabled' "${E[@]}" alcance estado
api a GET admin/voice/status
caso "5 · GET admin/voice/status: flags.contacts = false (lo que lee el smoke)" "$([ "$(jc http)" = 200 ] && [ "$(jc body.flags.contacts)" = false ] && echo 0 || echo 1)" "HTTP $(jc http) contacts=$(jc body.flags.contacts)"
api a POST admin/voice/contacts '{}'
caso "5 · con la bandera apagada, el alta de un contacto → 409 voice_contacts_disabled ANTES de validar" "$([ "$(jc http)" = 409 ] && [ "$(jc body.code)" = voice_contacts_disabled ] && echo 0 || echo 1)" "HTTP $(jc http) $(jc body.code)"
paso "5 · contactos on → MANDO contactos-on APLICADO (contacts: false → true)" 0 'MANDO contactos-on APLICADO' "${E[@]}" alcance contactos on
grep -q 'contacts: false → true' <<< "$SALIDA" && grep -q 'AVISO (B-T4): es de la fase 4b' <<< "$SALIDA" && caso "5 · on: solo cambió contacts y avisa de la fase 4b" 0 || caso "5 · on: el cambio y el aviso" 1
api a POST admin/voice/contacts '{}'
caso "5 · con la bandera encendida, el alta con un cuerpo vacío → 422 validation_failed (valida; nada se escribe)" "$([ "$(jc http)" = 422 ] && [ "$(jc body.code)" = validation_failed ] && echo 0 || echo 1)" "HTTP $(jc http) $(jc body.code)"
paso "5 · contactos off → MANDO contactos-off APLICADO" 0 'MANDO contactos-off APLICADO' "${E[@]}" alcance contactos off
api a POST admin/voice/contacts '{}'
caso "5 · apagada otra vez → 409 voice_contacts_disabled" "$([ "$(jc http)" = 409 ] && [ "$(jc body.code)" = voice_contacts_disabled ] && echo 0 || echo 1)" "HTTP $(jc http) $(jc body.code)"
VC=$(psql -X -Atq -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$BANCO_DB" -c 'SELECT count(*) FROM voice_contacts;')
caso "5 · ninguna fila en voice_contacts (las tres peticiones no escribieron nada)" "$([ "$VC" = 0 ] && echo 0 || echo 1)" "$VC"
for f in $FICHEROS_BT4; do cp -p "$ORIG/$f" "$BE/$f"; done
rm -rf "$ORIG"
( cd "$BE" && env "${RED[@]}" php artisan config:cache -q && chmod 600 bootstrap/cache/config.php )
caso "5 · el clon de la «producción» vuelve a su código (git status limpio)" "$([ "$(git -C "$B/api" status --porcelain --untracked-files=no | wc -l | tr -d ' ')" = 0 ] && echo 0 || echo 1)"
php_real "$AYUDANTE" "$BE" revocar >> "$LOG" 2>&1 || true

# ── 6. B-T5 de h8-hardening · el mando de la voz espera las tareas de voz que programa el código DESPLEGADO ─────────────
# 4 sin voice:webhook-health (el código del banco, a608450) y 5 con ella: lo lee del código (artisan list), no lo supone.
TV=$(tareas_voz_banco "$B")
paso "6 · estado del mando de la voz: las tareas de voz con el monitor encendido, leídas del código (a608450: 4; con B-T5: 5)" 0 "de voz: $TV con el monitor de voz encendido" "${E[@]}" alcance estado
paso "6 · monitor on (--acepta-freno por si la saliente del ensayo sigue en la ventana de 48 h) → APLICADO" 0 'MANDO monitor-on APLICADO' "${E[@]}" alcance monitor on --acepta-freno
grep -qE '4\. programador .* → [0-9]+ 0 '"$TV"' \(esperado' <<< "$SALIDA" && caso "6 · monitor on: exactamente las $TV tareas de voz del código del banco" 0 || caso "6 · monitor on: las tareas de voz" 1
paso "6 · monitor off → APLICADO (programador −$TV)" 0 'MANDO monitor-off APLICADO' "${E[@]}" alcance monitor off

# ── Cierre: el registro sin secretos ni tokens ─────────────────────────────────────────────────────────────────────────
BARRIDO=$(python3 -I - "$LOG" "$B/sim/piezas.conf" "$TOK" <<'PY'
import os, re, sys
log, piezas, tokens = sys.argv[1:4]
SECRETAS = {'EL_API_KEY', 'EL_WEBHOOK_SECRET', 'EL_TOOL_SECRET', 'EL_INITIATION_SECRET', 'TW_MASTER_TOKEN', 'TW_SIGNING_TOKEN',
            'TW_LARAVEL_KEY_SID', 'TW_LARAVEL_KEY_SECRET', 'TW_EL_KEY_SID', 'TW_EL_KEY_SECRET', 'SENTINEL_ELEVENLABS', 'SENTINEL_TWILIO'}
valores = {l.split('=', 1)[1].strip() for l in open(piezas, encoding='utf-8') if '=' in l and not l.startswith('#') and l.split('=', 1)[0].strip() in SECRETAS and len(l.split('=', 1)[1].strip()) >= 12}
valores |= {open(os.path.join(tokens, f), encoding='utf-8').read().strip() for f in os.listdir(tokens)}
s = open(log, encoding='utf-8', errors='replace').read()
h = [f'una pieza o un token' for v in valores if v and v in s] + [f'/{p}/' for p in [r'(xi_|tool_|ini_|wsec_)[0-9a-f]{12}', r'SK[0-9a-f]{32}', r'\+57[0-9]{10}'] if re.search(p, s)]
print(' · '.join(sorted(set(h))) or 'limpio')
PY
)
caso "cierre · el registro del ensayo no lleva piezas, tokens ni teléfonos" "$([ "$BARRIDO" = limpio ] && echo 0 || echo 1)" "$BARRIDO"
chmod 600 "$LOG"
echo "$((CASOS - FALLOS)) OK · $FALLOS FALLO · registro $LOG"
[ "$FALLOS" = 0 ]

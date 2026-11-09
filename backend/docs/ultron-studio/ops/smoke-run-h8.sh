#!/bin/bash
# Smoke del Hito 8 (fase 1, todo apagado) en producción, con la cuenta temporal admin id 32 («Studio smoke», Super
# Admin, INACTIVA entre usos), que al terminar vuelve a su reposo (Super Admin e inactiva, sin sesiones). Es el lanzador
# de smoke-run-h7.sh con lo del Hito 8:
#   · antes de nada: la voz apagada en la configuración efectiva (las 5 banderas, la constante cerrada, el monitor de
#     voz y el del H7 apagados) y el programador con `schedule:list` y su código de salida aparte: 0 tareas del Studio
#     y 0 de voz;
#   · `voice:doctor --telefonia --json` (sin red): el estado de la fase 1;
#   · el monitor del H7 en seco, solo integraciones: sin deriva de la configuración autorizada (S8-b 9) y el mismo
#     veredicto antes y después;
#   · la base: recuento EXACTO de todas las tablas ultron_studio_* (18) y voice_* (7) antes y después, filas que nombran
#     a la cuenta en cualquier columna *admin_id, los incidentes de IRON GUARD del Studio y la cola `voice`;
#   · CERO RED SALIENTE durante el smoke: estricto por la base y el registro (ningún agente, ninguna instantánea nueva,
#     ningún evento de llamada; en el canal `voice`, solo los 4 rechazos esperados de las rutas públicas) y, en el
#     banco, por la valla (SMOKE_VALLA_STATS: ni un bloqueo nuevo). En el servidor, además, un muestreo informativo de
#     los sockets de PHP hacia las IP de ElevenLabs (débil: las IP de una CDN se comparten).
# Se ejecuta EN EL SERVIDOR como root, tras copiar a /root/ este fichero, smoke-h8.py, smoke-h6.py (del que smoke-h8.py
# toma el detector de fugas) y los presupuestos de PERF8 (ops/perf-h8/presupuestos-h8.json, copiado como
# /root/presupuestos-h8.json):
#   bash /root/smoke-run-h8.sh
# Con SMOKE_BUDGETS_OUT=/root/<fichero>.json deja además las medidas de cada ruta y la propuesta de techo (0600, sin
# datos personales): es la línea base de la primera pasada en producción.
# Sin filas positivas: el smoke solo envía lo que el servidor rechaza antes de escribir (ver smoke-h8.py) y este
# lanzador lo comprueba en la base. Una tabla del Studio o de la voz que cambie es un FALLO.
# Seguridad (como el del H7): la contraseña (aleatoria, de un solo uso) solo va por entorno a python y como HASH por la
# entrada estándar a tinker; el id de la conversación canaria solo va por entorno y nunca se imprime; los secretos del
# servidor no salen de PHP (huellas saladas con una sal de esta ejecución); los tokens, en ficheros 0600 de un
# directorio 0700; un trap deja la cuenta en reposo, comprueba que los tokens ya no sirven y borra los temporales y las
# copias de /root aunque el smoke falle. La aplicación nunca como root (artisan como www-data).
# Códigos de salida: 0 todo OK · 1 algún FALLO · 3 sin fallos pero con algo OMITIDO · 2 uso incorrecto.
# Cupos: 3 pasadas por minuto como mucho (login, 8/min por IP); el laboratorio hablado gasta 1 de sus 10 por HORA y
# persona en cada pasada (un POST que responde 409). No toca la sonda de Twilio.
# Ensayo local: APP_DIR, SMOKE_API, SMOKE_RUN_AS="", SMOKE_PSQL, SMOKE_WORKDIR, SMOKE_PY, SMOKE_H6_PY, SMOKE_BUDGETS,
# SMOKE_ADMIN_ID, SMOKE_VALLA_STATS y SMOKE_LOGS (ver banco-h8/README.md).
set -euo pipefail
export LC_ALL=C

APP_DIR="${APP_DIR:-/var/www/api/backend}"
API="${SMOKE_API:-https://api.ironbodyneiva.cloud/api}"
ADMIN_ID="${SMOKE_ADMIN_ID:-32}"
WORK="${SMOKE_WORKDIR:-/root}"
PY="${SMOKE_PY:-$WORK/smoke-h8.py}"
PY6="${SMOKE_H6_PY:-$WORK/smoke-h6.py}"
BUD="${SMOKE_BUDGETS:-$WORK/presupuestos-h8.json}"
RUN_AS="${SMOKE_RUN_AS-www-data}"
PSQL="${SMOKE_PSQL:-sudo -u postgres psql -X -Atq -d ironbody}"
LOGS="${SMOKE_LOGS:-$APP_DIR/storage/logs}"
VALLA="${SMOKE_VALLA_STATS:-}"
SMOKE_EMAIL=studio-smoke@ironbodyneiva.cloud
# Lo que tiene que decir voice:doctor --telefonia en la fase 1 (el mismo que el paso 10 de deploy-h8-backend-fase1.sh).
TEL_FASE1="estado=ELEVENLABS_UNVERIFIED constante=cerrada encendidas=0 cadena=canary:none,rest:none freno=suelto cupos=0/0/0 numero=no subcuenta=no proveedor=ELEVENLABS_UNVERIFIED"
# Los rechazos que el smoke provoca a propósito en el canal `voice` (uno por credencial que falta); cualquier otra
# línea nueva del canal durante el smoke es actividad de voz que la fase 1 no tiene.
RECHAZOS="voice.inbound.unauthorized voice.post_call.rejected voice.tool.unauthorized voice.twilio.usage_rejected"

[[ "$ADMIN_ID" =~ ^[0-9]+$ ]] || { echo "SMOKE_ADMIN_ID no es un número"; exit 2; }
if [ -n "$RUN_AS" ] && [ "$(id -u)" != 0 ]; then echo "en el servidor se ejecuta como root"; exit 2; fi
for b in python3 php openssl curl join; do command -v "$b" > /dev/null || { echo "falta $b"; exit 2; }; done
[ -f "$PY" ] || { echo "no encuentro $PY"; exit 2; }
[ -f "$PY6" ] || { echo "no encuentro $PY6 (smoke-h8.py usa su detector de fugas)"; exit 2; }
[ -f "$BUD" ] || { echo "no encuentro $BUD (ops/perf-h8/presupuestos-h8.json del repo, copiado como presupuestos-h8.json junto al smoke; SMOKE_BUDGETS=…)"; exit 2; }
# El JSON se valida con el mismo código del smoke ANTES de activar la cuenta (-B: sin __pycache__ en /root).
PRES=$(python3 -I -B -c 'import importlib.util, sys
s = importlib.util.spec_from_file_location("smoke_h8", sys.argv[1]); m = importlib.util.module_from_spec(s); s.loader.exec_module(m)
try:
    d, h = m.load_budgets(sys.argv[2])
except Exception as e:
    print(type(e).__name__ + (": " + str(e) if type(e) is ValueError else "")); sys.exit(1)
print(str(d.get("version", "?")) + " · " + str(len(d["routes"])) + " rutas · sha256 " + h)' "$PY" "$BUD" 2>&1 | tail -1) \
  || { echo "los presupuestos de $BUD no sirven ($PRES): no se ejecuta el smoke y la cuenta no se ha tocado"; exit 2; }
cd "$APP_DIR"

artisan() { if [ -n "$RUN_AS" ]; then sudo -u "$RUN_AS" php artisan "$@"; else php artisan "$@"; fi; }
programador() {
  local salida rc=0
  salida=$(artisan schedule:list --json 2>/dev/null) || rc=$?
  [ "$rc" = 0 ] || { echo "FALLO $rc"; return 0; }
  printf '%s' "$salida" | python3 -c '
import json, re, sys
try:
    r = json.load(sys.stdin)
    assert isinstance(r, list)
except Exception:
    print("ILEGIBLE")
    sys.exit(0)
c = [str(x.get("command", "")) for x in r]
voz = [x for x in c if re.search(r"(?<![a-z])voice:", x) or "--verify-voice" in x]
print(len(r), sum(1 for x in c if "ultron-studio:" in x and x not in voz), len(voz))' 2>/dev/null || echo ILEGIBLE
}
tinker_line() {
  local salida
  salida=$(artisan tinker --execute="$2" 2>&1 | tail -1) || true
  [[ "$salida" =~ $1 ]] || return 1
  printf '%s\n' "$salida"
}
sql() { ( cd / && $PSQL -v ON_ERROR_STOP=1 -c "$1" ); }
telefonia() {
  artisan voice:doctor --telefonia --json 2>/dev/null | python3 -c '
import json, sys
try:
    d = json.load(sys.stdin)
    t = d["telefonia"]
    f = t["flags"]
    pub = t.get("publication") or {}
    l = t["limits"]
    print("estado=%s constante=%s encendidas=%d cadena=%s freno=%s cupos=%s/%s/%s numero=%s subcuenta=%s proveedor=%s" % (
        d.get("state"), "abierta" if t.get("callsEnabledInCode") else "cerrada", sum(1 for v in f.values() if v is True),
        ",".join("%s:%s" % (k, (pub.get(k) or {}).get("status")) for k in ("canary", "rest")),
        {True: "echado", False: "suelto"}.get(t.get("brakeOpen"), "?"), l.get("outboundDailyCap"), l.get("inboundDailyCap"),
        l.get("dailyBudgetMicros"), "si" if (t.get("number") or {}).get("configured") else "no",
        "si" if t.get("carrierConfigured") else "no", t.get("provider")))
except Exception:
    print("?")' 2>/dev/null || echo '?'
}
monitor_integraciones() {
  # El monitor sale con 1 cuando algo no cuadra: se lee su salida aparte (con pipefail, el 1 no puede pasar por «?»).
  local salida
  salida=$(artisan ultron-studio:monitor --check=integrations --dry-run --json 2>/dev/null) || true
  printf '%s' "$salida" | python3 -c '
import json, re, sys
try:
    r = json.load(sys.stdin)["checks"]["integrations"]
except Exception:
    print("?")
    sys.exit(0)
p = sorted(str(x) for x in r.get("problems", []) if re.fullmatch(r"[A-Za-z0-9_.:>\-]{1,120}", str(x)))
print("ok" if r.get("ok") is True else (",".join(p) if p else "?"))' 2>/dev/null || echo '?'
}
# Líneas de los ficheros del canal `voice` (diario: voice-AAAA-MM-DD.log) y de laravel*.log, para mirar solo lo nuevo.
lineas() { local f; for f in "$LOGS"/$1*.log; do [ -f "$f" ] && echo "$f $(wc -l < "$f" | tr -d ' ')"; done; return 0; }
nuevas() { # prefijo, lineas de antes → las líneas escritas desde entonces
  local f n
  for f in "$LOGS"/$1*.log; do
    [ -f "$f" ] || continue
    n=$(printf '%s\n' "$2" | awk -v f="$f" '$1 == f {print $2}')
    tail -n +"$(( ${n:-0} + 1 ))" "$f"
  done
  return 0
}
valla() { [ -n "$VALLA" ] && curl -s --max-time 3 "$VALLA" | python3 -I -c 'import json, sys; print(json.load(sys.stdin).get("blocked", "?"))' 2>/dev/null || echo '?'; }

Q_TABLAS="SELECT coalesce(string_agg(c.relname || '=' || (xpath('/row/n/text()', query_to_xml(format('SELECT count(*) AS n FROM public.%I', c.relname), false, true, '')))[1]::text, ' ' ORDER BY c.relname COLLATE \"C\"), '') FROM pg_class c JOIN pg_namespace s ON s.oid = c.relnamespace WHERE c.relkind = 'r' AND s.nspname = 'public' AND (c.relname LIKE 'ultron\_studio\_%' OR c.relname LIKE 'voice\_%');"
Q_CUENTA="SELECT coalesce(string_agg(x.t || '.' || x.col || '=' || x.n, ' ' ORDER BY x.t, x.col), '') FROM (SELECT c.table_name AS t, c.column_name AS col, (xpath('/row/n/text()', query_to_xml(format('SELECT count(*) AS n FROM public.%I WHERE %I = %s', c.table_name, c.column_name, $ADMIN_ID), false, true, '')))[1]::text AS n FROM information_schema.columns c WHERE c.table_schema = 'public' AND (c.table_name LIKE 'ultron\_studio\_%' OR c.table_name LIKE 'voice\_%') AND c.column_name LIKE '%admin\_id') x;"
Q_INCIDENTES="SELECT count(*)||' '||coalesce(sum(occurrences), 0) FROM incidents WHERE source = 'ultron_studio';"
Q_COLA_VOZ="SELECT CASE WHEN to_regclass('public.jobs') IS NULL THEN 'sin_tabla' ELSE (SELECT count(*) FROM jobs WHERE queue = 'voice')::text END;"

umask 077
TMPD=$(mktemp -d "$WORK/.smoke-h8.XXXXXX")
HDR="$TMPD/hdr-superadmin"
ACTIVADA=0
FINAL=""
VIGIA_PID=""

cleanup() {
  local rc=$? codigo restantes
  set +e
  [ -n "$FINAL" ] || FINAL=$rc
  [ -z "$VIGIA_PID" ] || kill "$VIGIA_PID" 2> /dev/null
  if [ "$ACTIVADA" = 1 ]; then
    if tinker_line '^cuenta [0-9]+ en reposo: inactiva, Super Admin, sesiones borradas: [0-9]+$' '$a = App\Models\Admin::query()->findOrFail('"$ADMIN_ID"'); $a->forceFill(["status" => "inactive", "role" => App\Models\Admin::ROLE_SUPER_ADMIN])->save(); $n = DB::table("admin_sessions")->where("admin_id", '"$ADMIN_ID"')->delete(); $f = $a->fresh(); echo $f->status === "inactive" && $f->role === App\Models\Admin::ROLE_SUPER_ADMIN ? "cuenta {$f->id} en reposo: inactiva, Super Admin, sesiones borradas: {$n}" : "cuenta {$f->id} NO quedó en reposo", PHP_EOL;'; then :; else
      echo "FALLO   no se pudo dejar en reposo la cuenta $ADMIN_ID: desactivarla y borrar sus sesiones A MANO, ya"
      FINAL=1
    fi
    if [ -s "$HDR" ]; then
      codigo=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' -H @"$HDR" "$API/admin/voice/status")
      if [ "$codigo" = 401 ] || [ "$codigo" = 403 ]; then echo "OK      token tras desactivar → $codigo"; else echo "FALLO   token tras desactivar → $codigo (esperado 401 o 403): revocar a mano"; FINAL=1; fi
    fi
    restantes=$(sql "SELECT count(*) FROM admin_sessions WHERE admin_id = $ADMIN_ID;")
    if [ "$restantes" = 0 ]; then echo "OK      sesiones de la cuenta tras el smoke: 0"; else echo "FALLO   sesiones de la cuenta tras el smoke: ${restantes:-no se pudo leer}"; FINAL=1; fi
  fi
  [ -n "${TMPD:-}" ] && [ -d "$TMPD" ] && rm -rf -- "$TMPD"
  case "$PY" in /root/*) rm -f -- "$PY" ;; esac
  case "$PY6" in /root/*) rm -f -- "$PY6" ;; esac
  case "$BUD" in /root/*) rm -f -- "$BUD" ;; esac
  case "$0" in /root/*) rm -f -- "$0" ;; esac
  echo "SMOKE H8: código de salida $FINAL"
  exit "$FINAL"
}
trap cleanup EXIT

echo "== Smoke del Hito 8 (fase 1) · $(date -u +%Y-%m-%dT%H:%M:%SZ) · $API"
echo "OK      presupuestos de rendimiento válidos: $PRES ($BUD)"

# 1. La voz apagada en la configuración efectiva, y el programador: schedule:list sale con 0, sin tareas del Studio ni de voz.
CONF=$(tinker_line '^\{"calls_in_code":(true|false),.*\}$' 'echo json_encode(["calls_in_code" => App\Services\Voice\VoiceCallPolicy::CALLS_ENABLED_IN_CODE, "inbound" => config("voice.inbound.enabled"), "outbound" => config("voice.outbound.enabled"), "lab" => config("voice.lab.enabled"), "voice_monitor" => config("voice.monitor.enabled"), "publishing" => config("ultron_studio.voice.publishing_enabled"), "twilio_voice" => config("voice.twilio.enabled"), "studio_monitor" => config("ultron_studio.monitor.enabled")]), PHP_EOL;') \
  || { echo "FALLO   no se pudo leer la configuración efectiva de la voz (tinker falló o no es la forma esperada; su salida no se imprime). No se ejecuta el smoke."; FINAL=1; exit 1; }
[ "$CONF" = '{"calls_in_code":false,"inbound":false,"outbound":false,"lab":false,"voice_monitor":false,"publishing":false,"twilio_voice":false,"studio_monitor":false}' ] \
  || { echo "FALLO   la voz no está toda apagada ($CONF): este smoke es el de la fase 1. No se ejecuta."; FINAL=1; exit 1; }
echo "OK      voz apagada: $CONF"
PROG=$(programador)
case "$PROG" in
  "FALLO "*) echo "FALLO   schedule:list no sale con 0 (código ${PROG#FALLO }; su salida no se imprime): sin poder leer el programador no se ejecuta el smoke"; FINAL=1; exit 1 ;;
  ILEGIBLE) echo "FALLO   schedule:list no devolvió la lista JSON de tareas. No se ejecuta el smoke."; FINAL=1; exit 1 ;;
esac
read -r _TOTAL STUDIO VOZ <<< "$PROG"
if [ "$STUDIO $VOZ" = "0 0" ]; then echo "OK      el programador: $_TOTAL tareas, ninguna del Studio ni de voz"; else echo "FALLO   el programador tiene $STUDIO tareas del Studio y $VOZ de voz (esperado 0 y 0 con los dos monitores apagados)"; FINAL=1; fi
TEL=$(telefonia)
if [ "$TEL" = "$TEL_FASE1" ]; then echo "OK      voice:doctor --telefonia (sin red): $TEL"; else echo "FALLO   voice:doctor --telefonia: [$TEL] (esperado [$TEL_FASE1])"; FINAL=1; fi
MONI0=$(monitor_integraciones)
case ",$MONI0," in
  *",config_drift:"*) echo "FALLO   el monitor del H7 ve deriva de la configuración autorizada antes del smoke: [$MONI0]"; FINAL=1 ;;
  ",?,") echo "FALLO   el monitor del H7 (integraciones, en seco) no devolvió un resultado legible"; FINAL=1 ;;
  *) echo "OK      monitor del H7 en seco (integraciones), sin deriva de la configuración autorizada: [$MONI0]" ;;
esac

# 2. ¿Tiene el motor canario? El id se queda en esta variable: nunca se imprime.
if CANARIO=$(tinker_line '^([0-9]+|none)$' '$c = config("marketing.ultron.canary_conversation_id"); echo $c === null ? "none" : (int) $c, PHP_EOL;'); then
  if [ "$CANARIO" = none ]; then echo "INFO    canario del motor configurado: no"; else echo "INFO    canario del motor configurado: sí (su id no se imprime)"; fi
else
  echo "AVISO   no se pudo leer si el motor tiene canario: la búsqueda de su id en los cuerpos queda OMITIDA"
  CANARIO=""
fi

# 3. Huellas saladas de los secretos del servidor (las del H7 más las piezas de la voz del H8; los valores no salen de PHP).
SAL=$(openssl rand -hex 32)
HUELLAS=$(printf '%s\n' "$SAL" | tinker_line '^(none|[0-9]+:[0-9a-f]{64}( [0-9]+:[0-9a-f]{64})*)$' '$s = hex2bin(trim((string) fgets(STDIN))); $v = [config("otp.twilio.sid"), config("otp.twilio.token"), config("otp.twilio.verify_service_sid"), config("otp.twilio.from"), config("meta.ads.access_token"), config("meta.access_token"), config("meta.app_secret"), config("meta.verify_token"), config("ultron_studio.integrations.twilio_probe.key_sid"), config("ultron_studio.integrations.twilio_probe.key_secret"), config("voice.elevenlabs.api_key"), config("voice.elevenlabs.webhook_secret"), config("voice.elevenlabs.tool_secret"), config("voice.elevenlabs.initiation_secret"), config("voice.elevenlabs.agent_id"), config("voice.elevenlabs.agent_phone_number_id"), config("voice.twilio.from"), config("voice.twilio.account_sid"), config("voice.twilio.api_key_sid"), config("voice.twilio.api_key_secret"), config("voice.twilio.signing_token"), config("app.key"), config("ultron_studio.lab.openai.api_key")]; $k = (string) config("app.key"); if (str_starts_with($k, "base64:")) { $v[] = substr($k, 7); } foreach ((array) config("meta.ads.ad_account_ids") as $a) { $v[] = (string) $a; $v[] = "act_".$a; } if (Illuminate\Support\Facades\Schema::hasTable("ultron_studio_canary_numbers")) { foreach (DB::table("ultron_studio_canary_numbers")->pluck("phone_hash") as $h) { $v[] = (string) $h; } } $o = []; foreach ($v as $x) { if (is_string($x) && strlen($x) >= 8) { $o[strlen($x).":".hash("sha256", $s.$x)] = true; } } echo $o === [] ? "none" : implode(" ", array_keys($o)), PHP_EOL;') \
  || { echo "AVISO   no se pudieron calcular las huellas de los secretos: el centinela exacto queda OMITIDO (las reglas de formato siguen)"; HUELLAS=""; }
[ -z "$HUELLAS" ] || echo "INFO    centinela exacto: $(printf '%s\n' "$HUELLAS" | wc -w | tr -d ' ') huellas saladas de secretos del servidor"

# 4. Fotografía de la base y de los registros antes del smoke (solo lectura).
ANTES=$(sql "$Q_TABLAS") && ANTES_CUENTA=$(sql "$Q_CUENTA") && INC_ANTES=$(sql "$Q_INCIDENTES") && COLA_ANTES=$(sql "$Q_COLA_VOZ") \
  || { echo "FALLO   no se pudo leer la base (psql). No se ejecuta el smoke."; FINAL=1; exit 1; }
N_STUDIO=$(tr ' ' '\n' <<< "$ANTES" | grep -c '^ultron_studio_' || true)
N_VOZ=$(tr ' ' '\n' <<< "$ANTES" | grep -c '^voice_' || true)
[ "$N_STUDIO $N_VOZ" = "18 7" ] && echo "OK      18 tablas ultron_studio_* y 7 voice_* en la base" || { echo "FALLO   tablas en la base: $N_STUDIO ultron_studio_* y $N_VOZ voice_* (esperado 18 y 7: ¿está desplegada la fase 1?). No se ejecuta el smoke."; FINAL=1; exit 1; }
VOZ_LOG_ANTES=$(lineas voice); APP_LOG_ANTES=$(lineas laravel)
VALLA_ANTES=$(valla)
# Vigía informativo en el servidor: sockets de PHP hacia las IP de ElevenLabs mientras corre el smoke (ss de iproute2).
if [ -z "$VALLA" ] && command -v ss > /dev/null && command -v getent > /dev/null; then
  IPS_EL=$(for h in api.elevenlabs.io api.us.elevenlabs.io api.eu.residency.elevenlabs.io; do getent ahostsv4 "$h" 2>/dev/null | awk '{print $1}'; done | sort -u | tr '\n' ' ')
  if [ -n "$IPS_EL" ]; then
    ( while :; do ss -Htnp 2>/dev/null | awk -v ips=" $IPS_EL" '/php/ {split($5, p, ":"); if (index(ips, " " p[1] " ")) print $5}'; sleep 0.5; done ) > "$TMPD/vigia" 2> /dev/null &
    VIGIA_PID=$!
  fi
fi

# 5. Activar la cuenta con una contraseña de un solo uso (el hash va por stdin a tinker).
SMOKE_PW=$(openssl rand -base64 30 | tr -d '/+=' | cut -c1-28)
HASH=$(SMOKE_PW="$SMOKE_PW" php -r 'echo password_hash(getenv("SMOKE_PW"), PASSWORD_BCRYPT);')
ACTIVADA=1
printf '%s\n' "$HASH" | tinker_line '^cuenta [0-9]+ activa como Super Admin$' '$h = trim((string) fgets(STDIN)); $a = App\Models\Admin::query()->findOrFail('"$ADMIN_ID"'); $a->forceFill(["name" => "Studio smoke (temporal)", "email" => "'"$SMOKE_EMAIL"'", "password" => $h, "role" => App\Models\Admin::ROLE_SUPER_ADMIN, "status" => "active"])->save(); $f = $a->fresh(); echo $f->status === "active" && $f->role === App\Models\Admin::ROLE_SUPER_ADMIN ? "cuenta {$f->id} activa como Super Admin" : "cuenta {$f->id} sin activar", PHP_EOL;' \
  || { echo "FALLO   no se pudo activar la cuenta $ADMIN_ID (su salida no se imprime)"; unset HASH; FINAL=1; exit 1; }
unset HASH

set +e
SMOKE_API="$API" SMOKE_EMAIL="$SMOKE_EMAIL" SMOKE_PW="$SMOKE_PW" SMOKE_CANARY_ID="$CANARIO" SMOKE_SECRET_SALT="$SAL" \
  SMOKE_SECRET_DIGESTS="${HUELLAS:-}" SMOKE_TOKEN_FILE="$HDR" SMOKE_H6_PY="$PY6" SMOKE_BUDGETS="$BUD" \
  SMOKE_BUDGETS_OUT="${SMOKE_BUDGETS_OUT:-}" PYTHONIOENCODING=utf-8 PYTHONDONTWRITEBYTECODE=1 \
  python3 "$PY" superadmin
RC1=$?
set -e

# 6. Fotografía después y comparación.
RC2=0
# El vigía muere por SIGTERM y su `wait` devuelve 143: con `set -e` eso tumbaba el lanzador y el código final salía 143 en vez de 3 (visto en producción el 09-11-2026).
[ -z "$VIGIA_PID" ] || { kill "$VIGIA_PID" 2> /dev/null; wait "$VIGIA_PID" 2> /dev/null || true; VIGIA_PID=""; }
echo "== Base, registros y red (solo lectura)"
if DESPUES=$(sql "$Q_TABLAS") && DESPUES_CUENTA=$(sql "$Q_CUENTA") && INC_DESPUES=$(sql "$Q_INCIDENTES") && COLA_DESPUES=$(sql "$Q_COLA_VOZ"); then
  if [ "$ANTES_CUENTA" = "$DESPUES_CUENTA" ]; then echo "OK      ninguna fila nueva de ultron_studio_* ni de voice_* nombra a la cuenta $ADMIN_ID"; else echo "FALLO   filas que nombran a la cuenta: antes [$ANTES_CUENTA] · después [$DESPUES_CUENTA]"; RC2=1; fi
  if [ "$ANTES" = "$DESPUES" ]; then
    echo "OK      recuento exacto de las 25 tablas (18 del Studio y 7 de la voz) igual antes y después: ninguna escritura, ningún agente, ninguna instantánea, ningún evento de llamada"
  else
    CAMBIOS=$(join -t= -1 1 -2 1 <(tr ' ' '\n' <<< "$ANTES" | sort) <(tr ' ' '\n' <<< "$DESPUES" | sort) | awk -F= '$2 != $3 {printf "%s=%s→%s  ", $1, $2, $3}')
    echo "FALLO   una tabla del Studio o de la voz cambió durante el smoke (el smoke no escribe; ¿alguien usó el Studio a la vez?): $CAMBIOS"
    RC2=1
  fi
  if [ "$INC_ANTES" = "$INC_DESPUES" ]; then echo "OK      incidentes de IRON GUARD del Studio (filas · ocurrencias): $INC_DESPUES, los mismos"; else echo "FALLO   incidentes del Studio: $INC_ANTES → $INC_DESPUES (con los monitores apagados no se abre ninguno)"; RC2=1; fi
  if [ "$COLA_DESPUES" = 0 ] || [ "$COLA_DESPUES" = sin_tabla ]; then echo "OK      cola voice: $COLA_DESPUES trabajos (antes $COLA_ANTES)"; else echo "FALLO   cola voice con $COLA_DESPUES trabajos tras el smoke (antes $COLA_ANTES)"; RC2=1; fi
else
  echo "FALLO   no se pudo leer la base después del smoke (psql)"
  RC2=1
fi
VOZ_NUEVAS=$(nuevas voice "$VOZ_LOG_ANTES")
NOMBRES=$(printf '%s\n' "$VOZ_NUEVAS" | sed -nE 's/^\[[^]]*\] [A-Za-z0-9_-]+\.([A-Z]+): ([a-z0-9_.]+).*$/\1 \2/p' | sort | uniq -c | awk '{printf "%s×%s(%s) ", $3, $1, $2}')
OTRAS=$(printf '%s\n' "$VOZ_NUEVAS" | sed -nE 's/^\[[^]]*\] [A-Za-z0-9_-]+\.[A-Z]+: ([a-z0-9_.]+).*$/\1/p' | sort -u | grep -vxF -f <(tr ' ' '\n' <<< "$RECHAZOS") | tr '\n' ' ' || true)
SIN_FORMA=$(printf '%s\n' "$VOZ_NUEVAS" | grep -c . || true)
CON_FORMA=$(printf '%s\n' "$VOZ_NUEVAS" | grep -cE '^\[[^]]*\] [A-Za-z0-9_-]+\.[A-Z]+: [a-z0-9_.]+' || true)
if [ -z "$OTRAS" ] && [ "$SIN_FORMA" = "$CON_FORMA" ] && ! printf '%s\n' "$VOZ_NUEVAS" | grep -qE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):'; then
  echo "OK      canal voice durante el smoke: solo los rechazos esperados de las rutas públicas [${NOMBRES:-ninguna línea}] (nada sale a la red ni se encola)"
else
  echo "FALLO   canal voice durante el smoke: líneas que no son los 4 rechazos esperados [${OTRAS:-}] · todas: [${NOMBRES:-}] (líneas sin la forma de Laravel: $((SIN_FORMA - CON_FORMA)))"
  RC2=1
fi
ERR=$(nuevas laravel "$APP_LOG_ANTES" | grep -cE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' || true)
if [ "$ERR" = 0 ]; then echo "OK      laravel*.log: ningún ERROR durante el smoke"; else echo "FALLO   laravel*.log: $ERR líneas ERROR o peores durante el smoke (revisarlas a mano; no se imprimen)"; RC2=1; fi
if [ -n "$VALLA" ]; then
  VALLA_DESPUES=$(valla)
  if [ "$VALLA_ANTES" != "?" ] && [ "$VALLA_ANTES" = "$VALLA_DESPUES" ]; then echo "OK      cero red saliente (valla): bloqueos $VALLA_ANTES → $VALLA_DESPUES"; else echo "FALLO   la valla vio salidas durante el smoke: bloqueos $VALLA_ANTES → $VALLA_DESPUES"; RC2=1; fi
elif [ -f "$TMPD/vigia" ]; then
  N_EL=$(sort -u "$TMPD/vigia" | grep -c . || true)
  echo "INFO    vigía de red: $N_EL sockets distintos de PHP hacia las IP de ElevenLabs durante el smoke (débil: una CDN comparte IP; lo estricto es la base y el canal voice)"
else
  echo "INFO    vigía de red no disponible (sin ss/getent o sin resolver los hosts de ElevenLabs): lo estricto es la base y el canal voice"
fi
MONI=$(monitor_integraciones)
if [ "$MONI" = "$MONI0" ]; then echo "OK      monitor del H7 en seco (integraciones) igual que antes del smoke: [$MONI]"; else echo "FALLO   el monitor del H7 cambió durante el smoke: [$MONI0] → [$MONI]"; RC2=1; fi

FINAL=${FINAL:-0}
[ "$FINAL" = 1 ] || FINAL=0
for rc in "$RC1" "$RC2"; do
  case "$rc" in 0) ;; 3) [ "$FINAL" = 0 ] && FINAL=3 ;; *) FINAL=1 ;; esac
done
exit "$FINAL"

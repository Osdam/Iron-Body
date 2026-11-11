#!/bin/bash
# Smoke del Hito 9 (fase 1, todo apagado; fase 2, sombra) en producción, con la cuenta temporal admin id 32 («Studio
# smoke», Super Admin, INACTIVA entre usos), que al terminar vuelve a su reposo (Super Admin e inactiva, sin sesiones).
# Es el lanzador de smoke-run-h8.sh con lo del Hito 9:
#   · antes de nada: la automatización comercial apagada en la configuración efectiva (la maestra, el modo, el
#     programador, el monitor, el asistido, los canales, el clasificador, el gobierno del Studio, publicar y el canal
#     SSE) y la constante de envío cerrada; el programador con `schedule:list` y su código de salida aparte: 0 tareas
#     outreach:* (fase 1) o las del bloque del H9 (fase 2);
#   · la base: recuento EXACTO de todas las tablas ultron_studio_* (26: las 25 de la fase 1 y la de W9) y outreach_* (8)
#     antes y después (SMOKE_EXPECT_STUDIO_TABLES), filas que
#     nombran a la cuenta en cualquier columna *admin_id, los incidentes de IRON GUARD del Studio y de outreach y las
#     colas outreach*;
#   · CERO RED SALIENTE durante el smoke: estricto por la base y el registro (ninguna fila nueva en las 34 tablas, ningún
#     ERROR) y, en el banco, por la valla (SMOKE_VALLA_STATS: ni un bloqueo nuevo). En el servidor, además, un muestreo
#     informativo de los sockets de PHP hacia Meta, ElevenLabs y Twilio (débil: las IP de una CDN se comparten);
#   · FASE 2 (sombra): tras el smoke, UNA pasada del barrido y del despacho a mano (las órdenes de B9-run, por defecto
#     outreach:sweep y outreach:dispatch --inline, las mismas del bloque del programador, L9-58; SMOKE_SWEEP_CMD y
#     SMOKE_DISPATCH_CMD) y la prueba de que NO HUBO ENVÍOS: las
#     26 tablas del Studio iguales, marketing_messages y voice_calls iguales, las colas whatsapp/voice/outreach sin
#     trabajos nuevos, 0 trabajos outreach_jobs en modo live, la valla sin bloqueos nuevos y ningún ERROR. Si las
#     órdenes no existen, la pasada queda OMITIDA (código 3) y lo dice.
# Se ejecuta EN EL SERVIDOR como root, tras copiar a /root/ este fichero, smoke-h9.py, smoke-h6.py (del que smoke-h9.py
# toma el detector de fugas) y, si PERF9 ya los entregó, los presupuestos (ops/perf-h9/presupuestos-h9.json, copiado
# como /root/presupuestos-h9.json; sin ellos los presupuestos quedan OMITIDOS):
#   bash /root/smoke-run-h9.sh
# Con SMOKE_BUDGETS_OUT=/root/<fichero>.json deja además las medidas de cada ruta y la propuesta de techo (0600, sin
# datos personales): es la línea base de la primera pasada en producción.
# Sin filas positivas: el smoke solo envía lo que el servidor rechaza antes de escribir (ver smoke-h9.py) y este
# lanzador lo comprueba en la base. Una tabla del Studio o de outreach que cambie durante el smoke es un FALLO.
# Seguridad (como el del H8): la contraseña (aleatoria, de un solo uso) solo va por entorno a python y como HASH por la
# entrada estándar a tinker; el id de la conversación canaria solo va por entorno y nunca se imprime; los secretos del
# servidor no salen de PHP (huellas saladas con una sal de esta ejecución); los tokens, en ficheros 0600 de un
# directorio 0700; un trap deja la cuenta en reposo, comprueba que los tokens ya no sirven y borra los temporales y las
# copias de /root aunque el smoke falle. La aplicación nunca como root (artisan como www-data).
# Códigos de salida: 0 todo OK · 1 algún FALLO · 3 sin fallos pero con algo OMITIDO · 2 uso incorrecto.
# Cupos: 3 pasadas por minuto como mucho (login, 8/min por IP).
# Ensayo local: APP_DIR, SMOKE_API, SMOKE_RUN_AS="", SMOKE_PSQL, SMOKE_WORKDIR, SMOKE_PY, SMOKE_H6_PY, SMOKE_BUDGETS,
# SMOKE_ADMIN_ID, SMOKE_VALLA_STATS y SMOKE_LOGS (ver banco-h9/README.md).
set -euo pipefail
export LC_ALL=C

APP_DIR="${APP_DIR:-/var/www/api/backend}"
API="${SMOKE_API:-https://api.ironbodyneiva.cloud/api}"
ADMIN_ID="${SMOKE_ADMIN_ID:-32}"
WORK="${SMOKE_WORKDIR:-/root}"
PY="${SMOKE_PY:-$WORK/smoke-h9.py}"
PY6="${SMOKE_H6_PY:-$WORK/smoke-h6.py}"
BUD="${SMOKE_BUDGETS-}"
[ -n "$BUD" ] || { [ -f "$WORK/presupuestos-h9.json" ] && BUD="$WORK/presupuestos-h9.json" || BUD=""; }
RUN_AS="${SMOKE_RUN_AS-www-data}"
PSQL="${SMOKE_PSQL:-sudo -u postgres psql -X -Atq -d ironbody}"
LOGS="${SMOKE_LOGS:-$APP_DIR/storage/logs}"
VALLA="${SMOKE_VALLA_STATS:-}"
FASE="${SMOKE_PHASE:-1}"
SWEEP="${SMOKE_SWEEP_CMD:-outreach:sweep}"
# L9-58: el despacho va EN LÍNEA (≤ 50 por pasada, cada uno en su transacción, sin cola ni worker nuevos), como en el
# bloque del programador; sin --inline encolaría en el carril `commercial` y, sin consumidor, lo reclamado quedaría
# `claimed` hasta que la conciliación lo marcara `ambiguous`.
DISPATCH="${SMOKE_DISPATCH_CMD:-outreach:dispatch --inline}"
# Tareas outreach:* que el programador tiene que enseñar en la fase 2 (el bloque del H9 de routes/console.php: barrido,
# los dos normalizadores de W9 y V9, despacho y monitor; L9-81). En la fase 1, 0.
TAREAS_SOMBRA="${SMOKE_EXPECT_OUTREACH_TASKS:-5}"
# Tablas ultron_studio_* del código de ESTE commit: las 25 de la fase 1 (18 del H5–H8 y 7 del gobierno del H9) y la de
# W9 (ultron_studio_template_meta_checks, migración 2026_11_13_200001; L9-80, OPS9-c). outreach_*: 8.
TABLAS_STUDIO="${SMOKE_EXPECT_STUDIO_TABLES:-26}"
TABLAS_OUT=8
SMOKE_EMAIL=studio-smoke@ironbodyneiva.cloud

[[ "$ADMIN_ID" =~ ^[0-9]+$ ]] || { echo "SMOKE_ADMIN_ID no es un número"; exit 2; }
case "$FASE" in 1|2) ;; *) echo "SMOKE_PHASE = 1 | 2"; exit 2 ;; esac
[[ "$TAREAS_SOMBRA" =~ ^[0-9]+$ ]] || { echo "SMOKE_EXPECT_OUTREACH_TASKS no es un número"; exit 2; }
[[ "$TABLAS_STUDIO" =~ ^[0-9]+$ ]] || { echo "SMOKE_EXPECT_STUDIO_TABLES no es un número"; exit 2; }
[[ "$SWEEP" =~ ^outreach:[a-z-]+(\ --[a-z-]+)*$ ]] && [[ "$DISPATCH" =~ ^outreach:[a-z-]+(\ --[a-z-]+)*$ ]] || { echo "SMOKE_SWEEP_CMD y SMOKE_DISPATCH_CMD tienen que ser órdenes outreach:<nombre> [--opción …] (sin valores)"; exit 2; }
if [ -n "$RUN_AS" ] && [ "$(id -u)" != 0 ]; then echo "en el servidor se ejecuta como root"; exit 2; fi
for b in python3 php openssl curl join; do command -v "$b" > /dev/null || { echo "falta $b"; exit 2; }; done
[ -f "$PY" ] || { echo "no encuentro $PY"; exit 2; }
[ -f "$PY6" ] || { echo "no encuentro $PY6 (smoke-h9.py usa su detector de fugas)"; exit 2; }
PRES="sin presupuestos (PERF9 no los ha entregado o no están junto al smoke): OMITIDOS"
if [ -n "$BUD" ]; then
  [ -f "$BUD" ] || { echo "no encuentro $BUD (ops/perf-h9/presupuestos-h9.json del repo, copiado como presupuestos-h9.json junto al smoke; SMOKE_BUDGETS=…)"; exit 2; }
  # El JSON se valida con el mismo código del smoke ANTES de activar la cuenta (-B: sin __pycache__ en /root).
  PRES=$(python3 -I -B -c 'import importlib.util, sys
s = importlib.util.spec_from_file_location("smoke_h9", sys.argv[1]); m = importlib.util.module_from_spec(s); s.loader.exec_module(m)
try:
    d, h = m.load_budgets(sys.argv[2])
except Exception as e:
    print(type(e).__name__ + (": " + str(e) if type(e) is ValueError else "")); sys.exit(1)
print(str(d.get("version", "?")) + " · " + str(len(d["routes"])) + " rutas · sha256 " + h)' "$PY" "$BUD" 2>&1 | tail -1) \
    || { echo "los presupuestos de $BUD no sirven ($PRES): no se ejecuta el smoke y la cuenta no se ha tocado"; exit 2; }
fi
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
out = [x for x in c if re.search(r"(?<![a-z])outreach:", x)]
print(len(r), sum(1 for x in c if "ultron-studio:" in x and x not in voz), len(voz), len(out))' 2>/dev/null || echo ILEGIBLE
}
tinker_line() {
  local salida
  salida=$(artisan tinker --execute="$2" 2>&1 | tail -1) || true
  [[ "$salida" =~ $1 ]] || return 1
  printf '%s\n' "$salida"
}
sql() { ( cd / && $PSQL -v ON_ERROR_STOP=1 -c "$1" ); }
ordenes_outreach() {
  artisan list --format=json 2>/dev/null | python3 -I -c '
import json, sys
try:
    d = json.load(sys.stdin)
    print(" ".join(sorted(c["name"] for c in d.get("commands", []) if str(c.get("name", "")).startswith("outreach:"))))
except Exception:
    print("")' 2>/dev/null || echo ""
}
# Líneas de los ficheros laravel*.log (y outreach*.log si B9-run abre un canal propio), para mirar solo lo nuevo.
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

Q_TABLAS="SELECT coalesce(string_agg(c.relname || '=' || (xpath('/row/n/text()', query_to_xml(format('SELECT count(*) AS n FROM public.%I', c.relname), false, true, '')))[1]::text, ' ' ORDER BY c.relname COLLATE \"C\"), '') FROM pg_class c JOIN pg_namespace s ON s.oid = c.relnamespace WHERE c.relkind = 'r' AND s.nspname = 'public' AND (c.relname LIKE 'ultron\_studio\_%' OR c.relname LIKE 'outreach\_%');"
Q_CUENTA="SELECT coalesce(string_agg(x.t || '.' || x.col || '=' || x.n, ' ' ORDER BY x.t, x.col), '') FROM (SELECT c.table_name AS t, c.column_name AS col, (xpath('/row/n/text()', query_to_xml(format('SELECT count(*) AS n FROM public.%I WHERE %I = %s', c.table_name, c.column_name, $ADMIN_ID), false, true, '')))[1]::text AS n FROM information_schema.columns c WHERE c.table_schema = 'public' AND (c.table_name LIKE 'ultron\_studio\_%' OR c.table_name LIKE 'outreach\_%') AND c.column_name LIKE '%admin\_id') x;"
Q_INCIDENTES="SELECT count(*)||' '||coalesce(sum(occurrences), 0) FROM incidents WHERE source IN ('ultron_studio', 'outreach');"
Q_COLAS="SELECT CASE WHEN to_regclass('public.jobs') IS NULL THEN 'sin_tabla' ELSE (SELECT count(*) FROM jobs WHERE queue LIKE 'outreach%')::text||'/'||(SELECT count(*) FROM jobs WHERE queue IN ('whatsapp', 'voice', 'marketing'))::text END;"
# Las tablas por las que SALE algo hacia una persona (WhatsApp por el outbox del motor, la voz del H8): iguales antes y
# después de la pasada en sombra. Solo las que existan.
Q_ENVIOS="SELECT coalesce(string_agg(t || '=' || (xpath('/row/n/text()', query_to_xml(format('SELECT count(*) AS n FROM public.%I', t), false, true, '')))[1]::text, ' ' ORDER BY t), '') FROM unnest(ARRAY['marketing_messages', 'voice_calls', 'voice_call_events']) AS t WHERE to_regclass('public.' || t) IS NOT NULL;"
Q_LIVE="SELECT CASE WHEN to_regclass('public.outreach_jobs') IS NULL THEN 'sin_tabla' ELSE (SELECT count(*) FROM outreach_jobs WHERE mode = 'live')::text END;"

umask 077
TMPD=$(mktemp -d "$WORK/.smoke-h9.XXXXXX")
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
      codigo=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' -H @"$HDR" "$API/admin/ultron-studio/status")
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
  echo "SMOKE H9: código de salida $FINAL"
  exit "$FINAL"
}
trap cleanup EXIT

echo "== Smoke del Hito 9 (fase $FASE) · $(date -u +%Y-%m-%dT%H:%M:%SZ) · $API"
echo "INFO    presupuestos de rendimiento: $PRES${BUD:+ ($BUD)}"

# 1. La automatización apagada (o en sombra) en la configuración efectiva, y el programador: schedule:list sale con 0.
CONF=$(tinker_line '^\{"deployed":(true|false),.*\}$' '$o = "outreach."; $s = "ultron_studio.strategies."; $g = null; foreach (array_merge(glob(app_path("Services/Outreach/OutreachSendGate.php")) ?: [], glob(app_path("Services/Outreach/*/OutreachSendGate.php")) ?: []) as $f) { $c = "App\\" . str_replace("/", "\\", substr($f, strlen(app_path()) + 1, -4)); if (class_exists($c) && defined($c . "::SENDS_ENABLED_IN_CODE")) { $g = $c; break; } } echo json_encode(["deployed" => is_array(config("outreach")), "enabled" => config($o."enabled"), "mode" => config($o."mode"), "scheduler" => config($o."scheduler.enabled"), "monitor" => config($o."monitor.enabled"), "assisted" => config($o."assisted.enabled"), "whatsapp" => config($o."whatsapp.enabled"), "voice" => config($o."voice.enabled"), "classifier" => config($o."classifier.enabled"), "sends" => $g === null ? null : constant($g . "::SENDS_ENABLED_IN_CODE"), "strategies" => config($s."enabled"), "publishing" => config($s."publishing_enabled"), "stream" => config("ultron_studio_stream.enabled")]), PHP_EOL;') \
  || { echo "FALLO   no se pudo leer la configuración efectiva del H9 (tinker falló o no es la forma esperada; su salida no se imprime). No se ejecuta el smoke."; FINAL=1; exit 1; }
# Lo que tiene que valer cada bandera en la fase. La constante de envío y el canal SSE pueden faltar (null) si B9-run o
# SSE9 no viajan todavía: se informa, nunca se admite «true».
# SMOKE_ALLOW_MISSING_EXEC=1: SOLO para el banco antes de que B9-run entregue la ejecución (config/outreach.php): las
# banderas de la ejecución no se exigen (salen como AVISO) y el smoke deja su bloque en OMITIDO. En producción, nunca.
FC=$(printf '%s' "$CONF" | FASE="$FASE" SIN_EJECUCION="${SMOKE_ALLOW_MISSING_EXEC:-}" python3 -I -c '
import json, os, sys
d = json.load(sys.stdin)
f = os.environ["FASE"]
esp = {"1": {"enabled": False, "mode": "off", "scheduler": False, "monitor": False, "assisted": False, "whatsapp": False, "voice": False, "classifier": False, "strategies": False, "publishing": False},
       "2": {"enabled": True, "mode": "shadow", "scheduler": True, "monitor": True, "assisted": False, "whatsapp": False, "voice": False}}[f]
muleta = ""
if d.get("deployed") is not True:
    if os.environ.get("SIN_EJECUCION") == "1" and f == "1":
        esp = {k: v for k, v in esp.items() if k in ("strategies", "publishing")}
        muleta = "MULETA del banco: config/outreach.php no está desplegado y SMOKE_ALLOW_MISSING_EXEC=1 (B9-run pendiente); las banderas de la ejecución no se comprueban"
    else:
        print("MAL config/outreach.php no está desplegado (deployed=%s)" % json.dumps(d.get("deployed"))); sys.exit(0)
mal = ["%s=%s" % (k, json.dumps(d.get(k))) for k, v in esp.items() if d.get(k) != v]
if d.get("sends") is not False and d.get("sends") is not None:
    mal.append("sends=%s" % json.dumps(d.get("sends")))
if f == "1" and d.get("stream") not in (False, None):
    mal.append("stream=%s" % json.dumps(d.get("stream")))
avisos = [muleta] if muleta else []
if d.get("sends") is None:
    avisos.append("la constante de envío no está en el código desplegado (OutreachSendGate)")
if d.get("stream") is None:
    avisos.append("el canal SSE no está desplegado (config/ultron_studio_stream.php)")
print(("MAL " + " ".join(mal)) if mal else ("OK " + ("; ".join(avisos) or "-")))')
case "$FC" in
  "OK "*) echo "OK      configuración del H9 de la fase $FASE: $CONF"; [ "${FC#OK }" = "-" ] || echo "AVISO   ${FC#OK }" ;;
  *) echo "FALLO   la configuración del H9 no es la de la fase $FASE (${FC#MAL }): $CONF. No se ejecuta."; FINAL=1; exit 1 ;;
esac
PROG=$(programador)
case "$PROG" in
  "FALLO "*) echo "FALLO   schedule:list no sale con 0 (código ${PROG#FALLO }; su salida no se imprime): sin poder leer el programador no se ejecuta el smoke"; FINAL=1; exit 1 ;;
  ILEGIBLE) echo "FALLO   schedule:list no devolvió la lista JSON de tareas. No se ejecuta el smoke."; FINAL=1; exit 1 ;;
esac
read -r _TOTAL STUDIO VOZ OUT <<< "$PROG"
[ "$FASE" = 1 ] && ESP_OUT=0 || ESP_OUT=$TAREAS_SOMBRA
if [ "$OUT" = "$ESP_OUT" ]; then
  echo "OK      el programador: $_TOTAL tareas, $STUDIO del Studio, $VOZ de voz y $OUT de outreach (esperado $ESP_OUT en la fase $FASE)"
else
  echo "FALLO   el programador tiene $OUT tareas outreach:* (esperado $ESP_OUT en la fase $FASE)"; FINAL=1
fi
ORDENES=$(ordenes_outreach)
echo "INFO    órdenes outreach:* desplegadas: ${ORDENES:-ninguna}"

# 2. ¿Tiene el motor canario? El id se queda en esta variable: nunca se imprime.
if CANARIO=$(tinker_line '^([0-9]+|none)$' '$c = config("marketing.ultron.canary_conversation_id"); echo $c === null ? "none" : (int) $c, PHP_EOL;'); then
  if [ "$CANARIO" = none ]; then echo "INFO    canario del motor configurado: no"; else echo "INFO    canario del motor configurado: sí (su id no se imprime)"; fi
else
  echo "AVISO   no se pudo leer si el motor tiene canario: la búsqueda de su id en los cuerpos queda OMITIDA"
  CANARIO=""
fi

# 3. Huellas saladas de los secretos del servidor (las del H8 más la clave de la aplicación; los valores no salen de PHP).
SAL=$(openssl rand -hex 32)
HUELLAS=$(printf '%s\n' "$SAL" | tinker_line '^(none|[0-9]+:[0-9a-f]{64}( [0-9]+:[0-9a-f]{64})*)$' '$s = hex2bin(trim((string) fgets(STDIN))); $v = [config("otp.twilio.sid"), config("otp.twilio.token"), config("otp.twilio.verify_service_sid"), config("otp.twilio.from"), config("meta.ads.access_token"), config("meta.access_token"), config("meta.app_secret"), config("meta.verify_token"), config("ultron_studio.integrations.twilio_probe.key_sid"), config("ultron_studio.integrations.twilio_probe.key_secret"), config("voice.elevenlabs.api_key"), config("voice.elevenlabs.webhook_secret"), config("voice.elevenlabs.tool_secret"), config("voice.elevenlabs.initiation_secret"), config("voice.elevenlabs.agent_id"), config("voice.elevenlabs.agent_phone_number_id"), config("voice.twilio.from"), config("voice.twilio.account_sid"), config("voice.twilio.api_key_sid"), config("voice.twilio.api_key_secret"), config("voice.twilio.signing_token"), config("app.key"), config("ultron_studio.lab.openai.api_key"), config("automation.internal_secret")]; $k = (string) config("app.key"); if (str_starts_with($k, "base64:")) { $v[] = substr($k, 7); } foreach ((array) config("meta.ads.ad_account_ids") as $a) { $v[] = (string) $a; $v[] = "act_".$a; } if (Illuminate\Support\Facades\Schema::hasTable("ultron_studio_canary_numbers")) { foreach (DB::table("ultron_studio_canary_numbers")->pluck("phone_hash") as $h) { $v[] = (string) $h; } } $o = []; foreach ($v as $x) { if (is_string($x) && strlen($x) >= 8) { $o[strlen($x).":".hash("sha256", $s.$x)] = true; } } echo $o === [] ? "none" : implode(" ", array_keys($o)), PHP_EOL;') \
  || { echo "AVISO   no se pudieron calcular las huellas de los secretos: el centinela exacto queda OMITIDO (las reglas de formato siguen)"; HUELLAS=""; }
[ -z "$HUELLAS" ] || echo "INFO    centinela exacto: $(printf '%s\n' "$HUELLAS" | wc -w | tr -d ' ') huellas saladas de secretos del servidor"

# 4. Fotografía de la base y de los registros antes del smoke (solo lectura).
ANTES=$(sql "$Q_TABLAS") && ANTES_CUENTA=$(sql "$Q_CUENTA") && INC_ANTES=$(sql "$Q_INCIDENTES") && COLAS_ANTES=$(sql "$Q_COLAS") \
  || { echo "FALLO   no se pudo leer la base (psql). No se ejecuta el smoke."; FINAL=1; exit 1; }
N_STUDIO=$(tr ' ' '\n' <<< "$ANTES" | grep -c '^ultron_studio_' || true)
N_OUT=$(tr ' ' '\n' <<< "$ANTES" | grep -c '^outreach_' || true)
[ "$N_STUDIO $N_OUT" = "$TABLAS_STUDIO $TABLAS_OUT" ] && echo "OK      $TABLAS_STUDIO tablas ultron_studio_* y $TABLAS_OUT outreach_* en la base" || { echo "FALLO   tablas en la base: $N_STUDIO ultron_studio_* y $N_OUT outreach_* (esperado $TABLAS_STUDIO y $TABLAS_OUT: ¿están desplegadas la fase 1 del H9 y la migración de W9 de este commit? El smoke tiene que ser el del commit desplegado). No se ejecuta el smoke."; FINAL=1; exit 1; }
APP_LOG_ANTES=$(lineas laravel); OUT_LOG_ANTES=$(lineas outreach)
VALLA_ANTES=$(valla)
# Vigía informativo en el servidor: sockets de PHP hacia las IP de Meta, ElevenLabs y Twilio mientras corre el smoke.
if [ -z "$VALLA" ] && command -v ss > /dev/null && command -v getent > /dev/null; then
  IPS=$(for h in graph.facebook.com api.elevenlabs.io api.twilio.com; do getent ahostsv4 "$h" 2>/dev/null | awk '{print $1}'; done | sort -u | tr '\n' ' ')
  if [ -n "$IPS" ]; then
    ( while :; do ss -Htnp 2>/dev/null | awk -v ips=" $IPS" '/php/ {split($5, p, ":"); if (index(ips, " " p[1] " ")) print $5}'; sleep 0.5; done ) > "$TMPD/vigia" 2> /dev/null &
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
  SMOKE_BUDGETS_OUT="${SMOKE_BUDGETS_OUT:-}" SMOKE_PHASE="$FASE" PYTHONIOENCODING=utf-8 PYTHONDONTWRITEBYTECODE=1 \
  python3 "$PY" superadmin
RC1=$?
set -e

# 6. Fotografía después y comparación: el smoke no escribe en ninguna de las tablas del Studio y de outreach, en ninguna fase.
RC2=0
# El vigía muere por SIGTERM y su `wait` devuelve 143: con `set -e` eso tumbaba el lanzador (lección del H8, 09-11-2026).
[ -z "$VIGIA_PID" ] || { kill "$VIGIA_PID" 2> /dev/null; wait "$VIGIA_PID" 2> /dev/null || true; VIGIA_PID=""; }
echo "== Base, registros y red (solo lectura)"
if DESPUES=$(sql "$Q_TABLAS") && DESPUES_CUENTA=$(sql "$Q_CUENTA") && INC_DESPUES=$(sql "$Q_INCIDENTES") && COLAS_DESPUES=$(sql "$Q_COLAS"); then
  if [ "$ANTES_CUENTA" = "$DESPUES_CUENTA" ]; then echo "OK      ninguna fila nueva de ultron_studio_* ni de outreach_* nombra a la cuenta $ADMIN_ID"; else echo "FALLO   filas que nombran a la cuenta: antes [$ANTES_CUENTA] · después [$DESPUES_CUENTA]"; RC2=1; fi
  if [ "$ANTES" = "$DESPUES" ]; then
    echo "OK      recuento exacto de las $((TABLAS_STUDIO + TABLAS_OUT)) tablas ($TABLAS_STUDIO del Studio y $TABLAS_OUT de outreach) igual antes y después: ninguna escritura, ningún freno, ninguna inscripción"
  else
    CAMBIOS=$(join -t= -1 1 -2 1 <(tr ' ' '\n' <<< "$ANTES" | sort) <(tr ' ' '\n' <<< "$DESPUES" | sort) | awk -F= '$2 != $3 {printf "%s=%s→%s  ", $1, $2, $3}')
    echo "FALLO   una tabla del Studio o de outreach cambió durante el smoke (el smoke no escribe; ¿alguien usó el Studio a la vez, o el programador en sombra?): $CAMBIOS"
    RC2=1
  fi
  if [ "$INC_ANTES" = "$INC_DESPUES" ]; then echo "OK      incidentes de IRON GUARD (ultron_studio y outreach; filas · ocurrencias): $INC_DESPUES, los mismos"; else echo "FALLO   incidentes: $INC_ANTES → $INC_DESPUES durante el smoke"; RC2=1; fi
  if [ "$COLAS_DESPUES" = "$COLAS_ANTES" ]; then echo "OK      colas (outreach* / whatsapp+voice+marketing): $COLAS_DESPUES, las mismas"; else echo "FALLO   colas: $COLAS_ANTES → $COLAS_DESPUES durante el smoke"; RC2=1; fi
else
  echo "FALLO   no se pudo leer la base después del smoke (psql)"
  RC2=1
fi
ERR=$(nuevas laravel "$APP_LOG_ANTES" | grep -cE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' || true)
if [ "$ERR" = 0 ]; then echo "OK      laravel*.log: ningún ERROR durante el smoke"; else echo "FALLO   laravel*.log: $ERR líneas ERROR o peores durante el smoke (revisarlas a mano; no se imprimen)"; RC2=1; fi
ERR_OUT=$(nuevas outreach "$OUT_LOG_ANTES" | grep -cE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' || true)
[ "$ERR_OUT" = 0 ] || { echo "FALLO   outreach*.log: $ERR_OUT líneas ERROR o peores durante el smoke"; RC2=1; }
if [ -n "$VALLA" ]; then
  VALLA_DESPUES=$(valla)
  if [ "$VALLA_ANTES" != "?" ] && [ "$VALLA_ANTES" = "$VALLA_DESPUES" ]; then echo "OK      cero red saliente (valla): bloqueos $VALLA_ANTES → $VALLA_DESPUES"; else echo "FALLO   la valla vio salidas durante el smoke: bloqueos $VALLA_ANTES → $VALLA_DESPUES"; RC2=1; fi
elif [ -f "$TMPD/vigia" ]; then
  N_EXT=$(sort -u "$TMPD/vigia" | grep -c . || true)
  echo "INFO    vigía de red: $N_EXT sockets distintos de PHP hacia las IP de Meta, ElevenLabs o Twilio durante el smoke (débil: una CDN comparte IP; lo estricto es la base)"
else
  echo "INFO    vigía de red no disponible (sin ss/getent o sin resolver los hosts): lo estricto es la base"
fi

# 7. FASE 2: una pasada en sombra, a mano, y la prueba de que no hubo envíos.
RC3=0
if [ "$FASE" = 2 ]; then
  echo "== Sombra: una pasada del barrido y del despacho (sin envíos)"
  # Las órdenes se buscan por su nombre (sin las opciones): `outreach:dispatch --inline` existe si existe `outreach:dispatch`.
  if case " $ORDENES " in *" ${SWEEP%% *} "*) case " $ORDENES " in *" ${DISPATCH%% *} "*) true ;; *) false ;; esac ;; *) false ;; esac; then
    ENV_ANTES=$(sql "$Q_ENVIOS"); COLAS_ANTES2=$(sql "$Q_COLAS"); VALLA_ANTES2=$(valla); STUDIO_ANTES=$(tr ' ' '\n' <<< "$DESPUES" | grep '^ultron_studio_' | tr '\n' ' ')
    LOG_ANTES2=$(lineas laravel); OUT_LOG_ANTES2=$(lineas outreach)
    for o in "$SWEEP" "$DISPATCH"; do
      # Sin comillas a propósito: la orden lleva sus opciones (validadas arriba: solo outreach:<nombre> y --opción).
      # shellcheck disable=SC2086
      set +e; SAL=$(artisan $o 2>&1); rc=$?; set -e
      # Solo para enseñar la salida: con LC_ALL=C, [[:print:]] no casa las líneas con tildes o «·» (todas las del despacho) y,
      # sin ninguna línea, el grep sale con 1 y `set -e -o pipefail` mataba el lanzador sin decir nada (L9-101).
      { printf '%s\n' "$SAL" | grep -vE '[0-9]{10,}' | grep -E '^[[:print:]]{0,240}$' | head -8 | sed 's/^/        /'; } || true
      if [ "$rc" = 0 ]; then echo "OK      $o salió con 0"; else echo "FALLO   $o salió con $rc"; RC3=1; fi
    done
    ENV_DESPUES=$(sql "$Q_ENVIOS"); COLAS_DESPUES2=$(sql "$Q_COLAS"); VALLA_DESPUES2=$(valla); LIVE=$(sql "$Q_LIVE")
    STUDIO_DESPUES=$(tr ' ' '\n' <<< "$(sql "$Q_TABLAS")" | grep '^ultron_studio_' | tr '\n' ' ')
    if [ "$ENV_ANTES" = "$ENV_DESPUES" ]; then echo "OK      sin envíos: marketing_messages, voice_calls y voice_call_events iguales [$ENV_DESPUES]"; else echo "FALLO   la pasada en sombra ESCRIBIÓ en una tabla de envío: antes [$ENV_ANTES] · después [$ENV_DESPUES]"; RC3=1; fi
    if [ "$LIVE" = 0 ] || [ "$LIVE" = sin_tabla ]; then echo "OK      trabajos outreach_jobs en modo live: $LIVE"; else echo "FALLO   hay $LIVE trabajos en modo live tras una pasada en SOMBRA"; RC3=1; fi
    if [ "$COLAS_ANTES2" = "$COLAS_DESPUES2" ]; then echo "OK      colas (outreach* / whatsapp+voice+marketing) sin trabajos nuevos: $COLAS_DESPUES2"; else echo "FALLO   colas: $COLAS_ANTES2 → $COLAS_DESPUES2 tras la pasada en sombra"; RC3=1; fi
    if [ "$STUDIO_ANTES" = "$STUDIO_DESPUES" ]; then echo "OK      las $TABLAS_STUDIO tablas del Studio iguales tras la pasada (la ejecución no escribe en el gobierno)"; else echo "FALLO   una tabla del Studio cambió con la pasada en sombra"; RC3=1; fi
    if [ -n "$VALLA" ]; then
      if [ "$VALLA_ANTES2" != "?" ] && [ "$VALLA_ANTES2" = "$VALLA_DESPUES2" ]; then echo "OK      cero red saliente en la pasada (valla): bloqueos $VALLA_ANTES2 → $VALLA_DESPUES2"; else echo "FALLO   la valla vio salidas en la pasada en sombra: $VALLA_ANTES2 → $VALLA_DESPUES2"; RC3=1; fi
    fi
    E2=$(nuevas laravel "$LOG_ANTES2" | grep -cE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' || true); E3=$(nuevas outreach "$OUT_LOG_ANTES2" | grep -cE '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' || true)
    if [ "$((E2 + E3))" = 0 ]; then echo "OK      ningún ERROR en la pasada en sombra"; else echo "FALLO   $((E2 + E3)) líneas ERROR o peores en la pasada en sombra"; RC3=1; fi
    echo "INFO    outreach_* tras la pasada (informativo: la sombra sí escribe pasadas, inscripciones, decisiones y trabajos simulados): $(tr ' ' '\n' <<< "$(sql "$Q_TABLAS")" | grep '^outreach_' | tr '\n' ' ')"
  else
    echo "OMITIDO la pasada en sombra: las órdenes $SWEEP y $DISPATCH no están en el código desplegado (hay: ${ORDENES:-ninguna}); SMOKE_SWEEP_CMD/SMOKE_DISPATCH_CMD si B9-run las llamó de otra forma"
    RC3=3
  fi
fi

FINAL=${FINAL:-0}
[ "$FINAL" = 1 ] || FINAL=0
for rc in "$RC1" "$RC2" "$RC3"; do
  case "$rc" in 0) ;; 3) [ "$FINAL" = 0 ] && FINAL=3 ;; *) FINAL=1 ;; esac
done
exit "$FINAL"

#!/bin/bash
# Smoke del Hito 7 en producción, con la cuenta temporal admin id 32 («Studio smoke», Super Admin, INACTIVA entre usos),
# que al terminar vuelve a su reposo (Super Admin e inactiva, sin sesiones). Es el lanzador de smoke-run-h6.sh con lo
# del Hito 7: el monitor apagado y sin tareas programadas, y los incidentes del Studio sin cambios.
# Se ejecuta EN EL SERVIDOR como root, tras copiar a /root/ este fichero, smoke-h7.py, smoke-h6.py (del que smoke-h7.py
# toma el detector de fugas) y perf-h7/presupuestos-h7.json (los presupuestos de rendimiento de PERF7, §7.1):
#   bash /root/smoke-run-h7.sh
# Con SMOKE_BUDGETS_OUT=/root/<fichero>.json deja además las medidas de cada ruta y la propuesta de techo (0600, sin
# datos personales): es la línea base de la primera pasada en producción. Recalibrar = editar el JSON del REPO,
# confirmarlo y volver a copiarlo con su huella; nunca se edita el código.
# Sin filas positivas: el smoke solo envía lo que el servidor rechaza antes de escribir (ver smoke-h7.py) y este
# lanzador lo comprueba en la base: recuento EXACTO de las 14 tablas ultron_studio_* antes y después, filas que nombran
# a la cuenta en cualquier columna *admin_id y los incidentes de IRON GUARD de fuente ultron_studio. Una tabla del Studio
# que cambie es un FALLO.
# Seguridad (como el del H6): la contraseña (aleatoria, de un solo uso) solo va por entorno a python y como HASH por la
# entrada estándar a tinker; el id de la conversación canaria solo va por entorno y nunca se imprime; los secretos del
# servidor no salen de PHP (huellas saladas con una sal de esta ejecución); los tokens, en ficheros 0600 de un
# directorio 0700; un trap deja la cuenta en reposo, comprueba que los tokens ya no sirven y borra los temporales y las
# copias de /root (también la del JSON) aunque el smoke falle. app nunca como root (artisan como www-data).
# Códigos de salida: 0 todo OK · 1 algún FALLO · 3 sin fallos pero con algo OMITIDO · 2 uso incorrecto. Un presupuesto
# OMITIDO por el estado (sin versión en CANARY, laboratorio apagado o sin ejecuciones) da 3: se lee en la línea
# «PRESUPUESTOS:» y en su motivo.
# Cupos: 3 por minuto (login, 8/min por IP). Cada pasada dura unos 2 minutos (la fase de presupuestos, en serie y con
# sus pausas) y no pasa de 90 lecturas con sesión por minuto (cupo 120). No toca la sonda de Twilio (su cupo es el
# del H6).
# Ensayo local: APP_DIR, SMOKE_API, SMOKE_RUN_AS="", SMOKE_PSQL, SMOKE_WORKDIR, SMOKE_PY, SMOKE_H6_PY, SMOKE_BUDGETS,
# SMOKE_TAREAS y SMOKE_ADMIN_ID (ver banco-h7/README.md).
set -euo pipefail
export LC_ALL=C

# TAREAS = entradas de `schedule:list` en producción con el monitor APAGADO: el MISMO valor que TAREAS de
# deploy-h7-backend.sh, leído en el servidor con su --solo-comprobar y fijado en los dos scripts en un commit (m-1 del
# reviewer del H7). Con «pendiente» el smoke no se ejecuta (código 2). SMOKE_TAREAS lo sustituye en un ensayo local.
TAREAS="${SMOKE_TAREAS:-pendiente}"
APP_DIR="${APP_DIR:-/var/www/api/backend}"
API="${SMOKE_API:-https://api.ironbodyneiva.cloud/api}"
ADMIN_ID="${SMOKE_ADMIN_ID:-32}"
WORK="${SMOKE_WORKDIR:-/root}"
PY="${SMOKE_PY:-$WORK/smoke-h7.py}"
PY6="${SMOKE_H6_PY:-$WORK/smoke-h6.py}"
BUD="${SMOKE_BUDGETS:-$WORK/presupuestos-h7.json}"
RUN_AS="${SMOKE_RUN_AS-www-data}"
PSQL="${SMOKE_PSQL:-sudo -u postgres psql -X -Atq -d ironbody}"
SMOKE_EMAIL=studio-smoke@ironbodyneiva.cloud

[[ "$ADMIN_ID" =~ ^[0-9]+$ ]] || { echo "SMOKE_ADMIN_ID no es un número"; exit 2; }
[[ "$TAREAS" =~ ^[0-9]+$ ]] || { echo "TAREAS sin fijar ($TAREAS): leerlo con deploy-h7-backend.sh --solo-comprobar y fijarlo aquí y en deploy-h7-backend.sh en un commit; la cuenta no se ha tocado"; exit 2; }
if [ -n "$RUN_AS" ] && [ "$(id -u)" != 0 ]; then echo "en el servidor se ejecuta como root"; exit 2; fi
for b in python3 php openssl curl join; do command -v "$b" > /dev/null || { echo "falta $b"; exit 2; }; done
[ -f "$PY" ] || { echo "no encuentro $PY"; exit 2; }
[ -f "$PY6" ] || { echo "no encuentro $PY6 (smoke-h7.py usa su detector de fugas)"; exit 2; }
[ -f "$BUD" ] || { echo "no encuentro $BUD (perf-h7/presupuestos-h7.json del repo, copiado junto al smoke; SMOKE_BUDGETS=…)"; exit 2; }
# El JSON se valida con el mismo código del smoke ANTES de activar la cuenta: uno roto no la activa en balde. Con -B:
# importar smoke-h7.py no deja __pycache__ en /root.
PRES=$(python3 -I -B -c 'import importlib.util, sys
s = importlib.util.spec_from_file_location("smoke_h7", sys.argv[1]); m = importlib.util.module_from_spec(s); s.loader.exec_module(m)
try:
    d, h = m.load_budgets(sys.argv[2])
except Exception as e:
    print(type(e).__name__ + (": " + str(e) if type(e) is ValueError else "")); sys.exit(1)
print(str(d.get("version", "?")) + " · " + str(len(d["routes"])) + " rutas · sha256 " + h)' "$PY" "$BUD" 2>&1 | tail -1) \
  || { echo "los presupuestos de $BUD no sirven ($PRES): no se ejecuta el smoke y la cuenta no se ha tocado"; exit 2; }
cd "$APP_DIR"

artisan() { if [ -n "$RUN_AS" ]; then sudo -u "$RUN_AS" php artisan "$@"; else php artisan "$@"; fi; }
# El programador por `schedule:list --json`, con su código de salida APARTE (m-1: sin base o sin caché lanza una
# QueryException sobre cache_locks y la salida vacía no puede pasar por «0 tareas del Studio»): «total del_Studio»,
# «FALLO <código>» o «ILEGIBLE». Su salida de error no se imprime.
programador() {
  local salida rc=0
  salida=$(artisan schedule:list --json 2>/dev/null) || rc=$?
  [ "$rc" = 0 ] || { echo "FALLO $rc"; return 0; }
  printf '%s' "$salida" | python3 -c '
import json, sys
try:
    r = json.load(sys.stdin)
    assert isinstance(r, list)
except Exception:
    print("ILEGIBLE")
    sys.exit(0)
print(len(r), sum(1 for x in r if "ultron-studio:" in str(x.get("command", ""))))' 2>/dev/null || echo ILEGIBLE
}
tinker_line() {
  local salida
  salida=$(artisan tinker --execute="$2" 2>&1 | tail -1) || true
  [[ "$salida" =~ $1 ]] || return 1
  printf '%s\n' "$salida"
}
sql() { ( cd / && $PSQL -v ON_ERROR_STOP=1 -c "$1" ); }

Q_TABLAS="SELECT coalesce(string_agg(c.relname || '=' || (xpath('/row/n/text()', query_to_xml(format('SELECT count(*) AS n FROM public.%I', c.relname), false, true, '')))[1]::text, ' ' ORDER BY c.relname), '') FROM pg_class c JOIN pg_namespace s ON s.oid = c.relnamespace WHERE c.relkind = 'r' AND s.nspname = 'public' AND c.relname LIKE 'ultron\_studio\_%';"
Q_CUENTA="SELECT coalesce(string_agg(x.t || '.' || x.col || '=' || x.n, ' ' ORDER BY x.t, x.col), '') FROM (SELECT c.table_name AS t, c.column_name AS col, (xpath('/row/n/text()', query_to_xml(format('SELECT count(*) AS n FROM public.%I WHERE %I = %s', c.table_name, c.column_name, $ADMIN_ID), false, true, '')))[1]::text AS n FROM information_schema.columns c WHERE c.table_schema = 'public' AND c.table_name LIKE 'ultron\_studio\_%' AND c.column_name LIKE '%admin\_id') x;"
Q_INCIDENTES="SELECT count(*)||' '||coalesce(sum(occurrences), 0) FROM incidents WHERE source = 'ultron_studio';"

umask 077
TMPD=$(mktemp -d "$WORK/.smoke-h7.XXXXXX")
HDR="$TMPD/hdr-superadmin"
ACTIVADA=0
FINAL=""

cleanup() {
  local rc=$? codigo restantes
  set +e
  [ -n "$FINAL" ] || FINAL=$rc
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
  echo "SMOKE H7: código de salida $FINAL"
  exit "$FINAL"
}
trap cleanup EXIT

echo "== Smoke del Hito 7 · $(date -u +%Y-%m-%dT%H:%M:%SZ) · $API"
echo "OK      presupuestos de rendimiento válidos: $PRES ($BUD)"

# 1. El monitor apagado (la bandera, booleana de verdad) y el programador: schedule:list sale con 0, TAREAS entradas y
#    ninguna del Studio.
CONF=$(tinker_line '^\{"monitor_enabled":(true|false)\}$' 'echo json_encode(["monitor_enabled" => config("ultron_studio.monitor.enabled")]), PHP_EOL;') \
  || { echo "FALLO   no se pudo leer la configuración efectiva del monitor (tinker falló o no hay booleano; su salida no se imprime). No se ejecuta el smoke."; FINAL=1; exit 1; }
[ "$CONF" = '{"monitor_enabled":false}' ] || { echo "FALLO   el monitor está encendido ($CONF): este smoke es el del despliegue, con el monitor apagado. No se ejecuta."; FINAL=1; exit 1; }
echo "OK      monitor apagado: $CONF"
PROG=$(programador)
case "$PROG" in
  "FALLO "*) echo "FALLO   schedule:list no sale con 0 (código ${PROG#FALLO }; su salida no se imprime): sin poder leer el programador no se ejecuta el smoke"; FINAL=1; exit 1 ;;
  ILEGIBLE) echo "FALLO   schedule:list no devolvió la lista JSON de tareas. No se ejecuta el smoke."; FINAL=1; exit 1 ;;
esac
if [ "$PROG" = "$TAREAS 0" ]; then echo "OK      el programador: $TAREAS tareas (TAREAS), ninguna del Studio"; else echo "FALLO   el programador (total · del Studio) da [$PROG] y con el monitor apagado se esperaba [$TAREAS 0]"; FINAL=1; fi

# 2. ¿Tiene el motor canario? El id se queda en esta variable: nunca se imprime.
if CANARIO=$(tinker_line '^([0-9]+|none)$' '$c = config("marketing.ultron.canary_conversation_id"); echo $c === null ? "none" : (int) $c, PHP_EOL;'); then
  if [ "$CANARIO" = none ]; then echo "INFO    canario del motor configurado: no"; else echo "INFO    canario del motor configurado: sí (su id no se imprime)"; fi
else
  echo "AVISO   no se pudo leer si el motor tiene canario: la búsqueda de su id en los cuerpos queda OMITIDA"
  CANARIO=""
fi

# 3. Huellas saladas de los secretos del servidor (las mismas piezas que el smoke del H6; los valores no salen de PHP).
SAL=$(openssl rand -hex 32)
HUELLAS=$(printf '%s\n' "$SAL" | tinker_line '^(none|[0-9]+:[0-9a-f]{64}( [0-9]+:[0-9a-f]{64})*)$' '$s = hex2bin(trim((string) fgets(STDIN))); $v = [config("otp.twilio.sid"), config("otp.twilio.token"), config("otp.twilio.verify_service_sid"), config("otp.twilio.from"), config("meta.ads.access_token"), config("meta.access_token"), config("meta.app_secret"), config("meta.verify_token"), config("ultron_studio.integrations.twilio_probe.key_sid"), config("ultron_studio.integrations.twilio_probe.key_secret"), config("voice.elevenlabs.api_key"), config("voice.elevenlabs.webhook_secret"), config("voice.elevenlabs.tool_secret"), config("voice.elevenlabs.agent_id"), config("app.key"), config("ultron_studio.lab.openai.api_key")]; $k = (string) config("app.key"); if (str_starts_with($k, "base64:")) { $v[] = substr($k, 7); } foreach ((array) config("meta.ads.ad_account_ids") as $a) { $v[] = (string) $a; $v[] = "act_".$a; } if (Illuminate\Support\Facades\Schema::hasTable("ultron_studio_canary_numbers")) { foreach (DB::table("ultron_studio_canary_numbers")->pluck("phone_hash") as $h) { $v[] = (string) $h; } } $o = []; foreach ($v as $x) { if (is_string($x) && strlen($x) >= 8) { $o[strlen($x).":".hash("sha256", $s.$x)] = true; } } echo $o === [] ? "none" : implode(" ", array_keys($o)), PHP_EOL;') \
  || { echo "AVISO   no se pudieron calcular las huellas de los secretos: el centinela exacto queda OMITIDO (las reglas de formato siguen)"; HUELLAS=""; }
[ -z "$HUELLAS" ] || echo "INFO    centinela exacto: $(printf '%s\n' "$HUELLAS" | wc -w | tr -d ' ') huellas saladas de secretos del servidor"

# 4. Fotografía de la base antes del smoke (solo lectura).
ANTES=$(sql "$Q_TABLAS") && ANTES_CUENTA=$(sql "$Q_CUENTA") && INC_ANTES=$(sql "$Q_INCIDENTES") \
  || { echo "FALLO   no se pudo leer la base (psql). No se ejecuta el smoke."; FINAL=1; exit 1; }
N_TABLAS=$(tr ' ' '\n' <<< "$ANTES" | grep -c '^ultron_studio_' || true)
[ "$N_TABLAS" = 14 ] && echo "OK      14 tablas ultron_studio_* en la base" || { echo "FALLO   tablas ultron_studio_* en la base: $N_TABLAS (esperado 14). No se ejecuta el smoke."; FINAL=1; exit 1; }

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
echo "== Base (solo lectura)"
if DESPUES=$(sql "$Q_TABLAS") && DESPUES_CUENTA=$(sql "$Q_CUENTA") && INC_DESPUES=$(sql "$Q_INCIDENTES"); then
  if [ "$ANTES_CUENTA" = "$DESPUES_CUENTA" ]; then echo "OK      ninguna fila nueva de ultron_studio_* nombra a la cuenta $ADMIN_ID"; else echo "FALLO   filas que nombran a la cuenta: antes [$ANTES_CUENTA] · después [$DESPUES_CUENTA]"; RC2=1; fi
  if [ "$ANTES" = "$DESPUES" ]; then
    echo "OK      recuento exacto de las 14 tablas ultron_studio_* igual antes y después [$DESPUES]"
  else
    CAMBIOS=$(join -t= -1 1 -2 1 <(tr ' ' '\n' <<< "$ANTES" | sort) <(tr ' ' '\n' <<< "$DESPUES" | sort) | awk -F= '$2 != $3 {printf "%s=%s→%s  ", $1, $2, $3}')
    echo "FALLO   una tabla del Studio cambió durante el smoke (el smoke no escribe; ¿alguien usó el Studio a la vez?): $CAMBIOS"
    RC2=1
  fi
  if [ "$INC_ANTES" = "$INC_DESPUES" ]; then echo "OK      incidentes de IRON GUARD del Studio (filas · ocurrencias): $INC_DESPUES, los mismos"; else echo "FALLO   incidentes del Studio: $INC_ANTES → $INC_DESPUES (con el monitor apagado no se abre ninguno)"; RC2=1; fi
else
  echo "FALLO   no se pudo leer la base después del smoke (psql)"
  RC2=1
fi

FINAL=${FINAL:-0}
[ "$FINAL" = 1 ] || FINAL=0
for rc in "$RC1" "$RC2"; do
  case "$rc" in 0) ;; 3) [ "$FINAL" = 0 ] && FINAL=3 ;; *) FINAL=1 ;; esac
done
exit "$FINAL"

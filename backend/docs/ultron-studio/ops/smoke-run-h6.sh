#!/bin/bash
# Smoke del Hito 6 en producción (fases 1 y 2) con la cuenta temporal admin id 32 («Studio smoke», Super Admin,
# INACTIVA entre usos). En la segunda parte la misma cuenta hace de Administrador (se le cambia el rol durante el smoke:
# trae campaigns_manage y canary_manage, y no canary_authorize ni integrations_manage) y al terminar vuelve a su reposo:
# Super Admin e inactiva, sin sesiones. Es el lanzador de smoke-run-h5.sh con lo del Hito 6.
# Se ejecuta EN EL SERVIDOR como root, tras copiar smoke-h6.py y este fichero a /root/:
#   SMOKE_PHASE=1 bash /root/smoke-run-h6.sh   (fase 1: escrituras y sonda apagadas)
#   SMOKE_PHASE=2 bash /root/smoke-run-h6.sh   (fase 2: escrituras encendidas; la sonda, encendida o no)
# Sin filas positivas: el smoke solo envía lo que el servidor rechaza antes de escribir (ver smoke-h6.py) y este
# lanzador lo comprueba en la base: recuento EXACTO de las 14 tablas ultron_studio_* antes y después y filas que nombran
# a la cuenta en cualquier columna *admin_id. Una tabla del Hito 6 que cambie es un FALLO.
# Seguridad: la contraseña (aleatoria, de un solo uso) solo va por entorno a python y como HASH por la entrada estándar a
# tinker, nunca en argumentos; el id de la conversación canaria solo va por entorno a python y nunca se imprime; los
# secretos del servidor no salen de PHP: tinker devuelve sha256(sal + valor) con una sal aleatoria de esta ejecución
# (por la entrada estándar) y python busca esas huellas en los cuerpos (centinela exacto); el teléfono del lead canario
# NO se lee (la regla de teléfonos del detector es más estricta y no exige tocar datos personales). De tinker solo se
# usa una línea que case con lo esperado; los tokens quedan en ficheros 0600 dentro de un directorio 0700; un trap
# desactiva la cuenta, le devuelve el rol de reposo, borra sus sesiones, comprueba que los tokens ya no sirven y borra
# los temporales y las copias de /root aunque el smoke falle. app nunca como root (artisan como www-data).
# Códigos de salida: 0 todo OK · 1 algún FALLO · 3 sin fallos pero con algo OMITIDO · 2 uso incorrecto.
# Cupos: COMO MUCHO 3 EJECUCIONES POR HORA (la sonda: 6/h por persona y el smoke gasta 2) y 3 por minuto (login).
# Ensayo local (sin sudo ni producción): APP_DIR, SMOKE_API, SMOKE_RUN_AS="", SMOKE_PSQL, SMOKE_WORKDIR, SMOKE_PY y
# SMOKE_ADMIN_ID (ver plans/informes-h6/informe-devops-h6.md, «Pendiente de validar contra la pila real»).
set -euo pipefail
export LC_ALL=C

APP_DIR="${APP_DIR:-/var/www/api/backend}"
API="${SMOKE_API:-https://api.ironbodyneiva.cloud/api}"
FASE="${SMOKE_PHASE:-1}"
ADMIN_ID="${SMOKE_ADMIN_ID:-32}"
WORK="${SMOKE_WORKDIR:-/root}"
PY="${SMOKE_PY:-$WORK/smoke-h6.py}"
RUN_AS="${SMOKE_RUN_AS-www-data}"            # vacío: artisan con el usuario actual (solo en el ensayo local)
PSQL="${SMOKE_PSQL:-sudo -u postgres psql -X -Atq -d ironbody}"
SEGUNDA="${SMOKE_SEGUNDA_CUENTA:-rol}"       # rol: la misma cuenta hace de Administrador · no: se omite
SMOKE_EMAIL=studio-smoke@ironbodyneiva.cloud

case "$FASE" in 1|2) ;; *) echo "SMOKE_PHASE no válido: $FASE (1|2)"; exit 2 ;; esac
case "$SEGUNDA" in rol|no) ;; *) echo "SMOKE_SEGUNDA_CUENTA no válido: $SEGUNDA (rol|no)"; exit 2 ;; esac
[[ "$ADMIN_ID" =~ ^[0-9]+$ ]] || { echo "SMOKE_ADMIN_ID no es un número"; exit 2; }
if [ -n "$RUN_AS" ] && [ "$(id -u)" != 0 ]; then echo "en el servidor se ejecuta como root"; exit 2; fi
for b in python3 php openssl curl join; do command -v "$b" > /dev/null || { echo "falta $b"; exit 2; }; done
[ -f "$PY" ] || { echo "no encuentro $PY"; exit 2; }
cd "$APP_DIR"

artisan() { if [ -n "$RUN_AS" ]; then sudo -u "$RUN_AS" php artisan "$@"; else php artisan "$@"; fi; }
# Última línea de un tinker, SOLO si casa con el patrón esperado ($1, ERE); si no, nada y código 1. La entrada
# estándar pasa a tinker (así llegan el hash y la sal). $2 es el código PHP.
tinker_line() {
  local salida
  salida=$(artisan tinker --execute="$2" 2>&1 | tail -1) || true
  [[ "$salida" =~ $1 ]] || return 1
  printf '%s\n' "$salida"
}
# SMOKE_PSQL se parte en palabras a propósito (es un comando con sus opciones). Desde / para que postgres no tropiece
# con el directorio de trabajo.
sql() { ( cd / && $PSQL -v ON_ERROR_STOP=1 -c "$1" ); }

# Recuento EXACTO de cada tabla del Studio (una sola consulta, sin estimaciones de pg_class).
Q_TABLAS="SELECT coalesce(string_agg(c.relname || '=' || (xpath('/row/n/text()', query_to_xml(format('SELECT count(*) AS n FROM public.%I', c.relname), false, true, '')))[1]::text, ' ' ORDER BY c.relname), '') FROM pg_class c JOIN pg_namespace s ON s.oid = c.relnamespace WHERE c.relkind = 'r' AND s.nspname = 'public' AND c.relname LIKE 'ultron\_studio\_%';"
# Filas del Studio que nombran a la cuenta de smoke en cualquier columna *admin_id (las 3 tablas del H6 tienen siete).
Q_CUENTA="SELECT coalesce(string_agg(x.t || '.' || x.col || '=' || x.n, ' ' ORDER BY x.t, x.col), '') FROM (SELECT c.table_name AS t, c.column_name AS col, (xpath('/row/n/text()', query_to_xml(format('SELECT count(*) AS n FROM public.%I WHERE %I = %s', c.table_name, c.column_name, $ADMIN_ID), false, true, '')))[1]::text AS n FROM information_schema.columns c WHERE c.table_schema = 'public' AND c.table_name LIKE 'ultron\_studio\_%' AND c.column_name LIKE '%admin\_id') x;"
Q_SELLOS="SELECT count(*) FROM marketing_ai_actions WHERE metadata ? 'studio';"
H6_TABLAS_RE='ultron_studio_(campaign_links|canary_numbers|integration_snapshots)='

umask 077
TMPD=$(mktemp -d "$WORK/.smoke-h6.XXXXXX")
HDR1="$TMPD/hdr-superadmin"
HDR2="$TMPD/hdr-administrador"
ACTIVADA=0
FINAL=""

cleanup() {
  local rc=$? h codigo restantes
  set +e
  [ -n "$FINAL" ] || FINAL=$rc
  if [ "$ACTIVADA" = 1 ]; then
    if tinker_line '^cuenta [0-9]+ en reposo: inactiva, Super Admin, sesiones borradas: [0-9]+$' '$a = App\Models\Admin::query()->findOrFail('"$ADMIN_ID"'); $a->forceFill(["status" => "inactive", "role" => App\Models\Admin::ROLE_SUPER_ADMIN])->save(); $n = DB::table("admin_sessions")->where("admin_id", '"$ADMIN_ID"')->delete(); $f = $a->fresh(); echo $f->status === "inactive" && $f->role === App\Models\Admin::ROLE_SUPER_ADMIN ? "cuenta {$f->id} en reposo: inactiva, Super Admin, sesiones borradas: {$n}" : "cuenta {$f->id} NO quedó en reposo", PHP_EOL;'; then :; else
      echo "FALLO   no se pudo dejar en reposo la cuenta $ADMIN_ID: desactivarla y borrar sus sesiones A MANO, ya"
      FINAL=1
    fi
    for h in "$HDR1" "$HDR2"; do
      [ -s "$h" ] || continue
      codigo=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' -H @"$h" "$API/admin/ultron-studio/integrations/status")
      if [ "$codigo" = 401 ] || [ "$codigo" = 403 ]; then
        echo "OK      token de ${h##*/hdr-} tras desactivar → $codigo"
      else
        echo "FALLO   token de ${h##*/hdr-} tras desactivar → $codigo (esperado 401 o 403): revocar a mano"
        FINAL=1
      fi
    done
    restantes=$(sql "SELECT count(*) FROM admin_sessions WHERE admin_id = $ADMIN_ID;")
    if [ "$restantes" = 0 ]; then echo "OK      sesiones de la cuenta tras el smoke: 0"; else echo "FALLO   sesiones de la cuenta tras el smoke: ${restantes:-no se pudo leer}"; FINAL=1; fi
  fi
  [ -n "${TMPD:-}" ] && [ -d "$TMPD" ] && rm -rf -- "$TMPD"
  case "$PY" in /root/*) rm -f -- "$PY" ;; esac      # las copias de /root no se quedan en el servidor
  case "$0" in /root/*) rm -f -- "$0" ;; esac
  echo "SMOKE H6 fase $FASE: código de salida $FINAL"
  exit "$FINAL"
}
trap cleanup EXIT

echo "== Smoke del Hito 6 · fase $FASE · $(date -u +%Y-%m-%dT%H:%M:%SZ) · $API"

# 1. La configuración efectiva tiene que ser la de la fase (si no, el smoke mediría otra cosa). Booleanos de verdad: un
#    texto en lugar de un booleano no casa con el patrón y el smoke no arranca.
CONF=$(tinker_line '^\{"writes_enabled":(true|false),"probe_enabled":(true|false)\}$' 'echo json_encode(["writes_enabled" => config("ultron_studio.integrations.writes_enabled"), "probe_enabled" => config("ultron_studio.integrations.twilio_probe.enabled")]), PHP_EOL;') \
  || { echo "FALLO   no se pudo leer la configuración efectiva del Hito 6 (tinker falló o no hay booleanos; su salida no se imprime). No se ejecuta el smoke."; FINAL=1; exit 1; }
case "$FASE:$CONF" in
  '1:{"writes_enabled":false,"probe_enabled":false}') PROBE=false ;;
  '2:{"writes_enabled":true,"probe_enabled":false}')  PROBE=false ;;
  '2:{"writes_enabled":true,"probe_enabled":true}')   PROBE=true ;;
  *) echo "FALLO   configuración efectiva $CONF; la fase $FASE exige escrituras $([ "$FASE" = 1 ] && echo 'y sonda apagadas' || echo 'encendidas'). No se ejecuta el smoke."; FINAL=1; exit 1 ;;
esac
echo "OK      configuración efectiva de la fase $FASE: $CONF"

# 2. ¿Tiene el motor canario? El id se queda en esta variable: nunca se imprime.
if CANARIO=$(tinker_line '^([0-9]+|none)$' '$c = config("marketing.ultron.canary_conversation_id"); echo $c === null ? "none" : (int) $c, PHP_EOL;'); then
  if [ "$CANARIO" = none ]; then echo "INFO    canario del motor configurado: no"; else echo "INFO    canario del motor configurado: sí (su id no se imprime)"; fi
else
  echo "AVISO   no se pudo leer si el motor tiene canario: la búsqueda de su id en los cuerpos queda OMITIDA"
  CANARIO=""
fi

# 3. Cuentas de Meta configuradas (un número) y huellas saladas de los secretos del servidor (los valores no salen de
#    PHP). Sin esto, la comprobación correspondiente del smoke se omite o falla, pero el smoke sigue siendo seguro.
CUENTAS=$(tinker_line '^[0-9]+$' 'echo count((array) config("meta.ads.ad_account_ids")), PHP_EOL;') \
  || { echo "AVISO   no se pudo leer cuántas cuentas de Meta hay configuradas: se supone 3 (la clasificación al congelar)"; CUENTAS=3; }
SAL=$(openssl rand -hex 32)
HUELLAS=$(printf '%s\n' "$SAL" | tinker_line '^(none|[0-9]+:[0-9a-f]{64}( [0-9]+:[0-9a-f]{64})*)$' '$s = hex2bin(trim((string) fgets(STDIN))); $v = [config("otp.twilio.sid"), config("otp.twilio.token"), config("otp.twilio.verify_service_sid"), config("otp.twilio.from"), config("meta.ads.access_token"), config("meta.access_token"), config("meta.app_secret"), config("meta.verify_token"), config("ultron_studio.integrations.twilio_probe.key_sid"), config("ultron_studio.integrations.twilio_probe.key_secret"), config("voice.elevenlabs.api_key"), config("voice.elevenlabs.webhook_secret"), config("voice.elevenlabs.tool_secret"), config("voice.elevenlabs.agent_id"), config("app.key")]; $k = (string) config("app.key"); if (str_starts_with($k, "base64:")) { $v[] = substr($k, 7); } foreach ((array) config("meta.ads.ad_account_ids") as $a) { $v[] = (string) $a; $v[] = "act_".$a; } if (Illuminate\Support\Facades\Schema::hasTable("ultron_studio_canary_numbers")) { foreach (DB::table("ultron_studio_canary_numbers")->pluck("phone_hash") as $h) { $v[] = (string) $h; } foreach (DB::table("ultron_studio_canary_numbers")->whereNotNull("phone_encrypted")->pluck("phone_encrypted") as $c) { $v[] = (string) $c; } } $o = []; foreach ($v as $x) { if (is_string($x) && strlen($x) >= 8) { $o[strlen($x).":".hash("sha256", $s.$x)] = true; } } echo $o === [] ? "none" : implode(" ", array_keys($o)), PHP_EOL;') \
  || { echo "AVISO   no se pudieron calcular las huellas de los secretos: el centinela exacto queda OMITIDO (las reglas de formato siguen)"; HUELLAS=""; }
[ -z "$HUELLAS" ] || echo "INFO    centinela exacto: $(printf '%s\n' "$HUELLAS" | wc -w | tr -d ' ') huellas saladas de secretos del servidor (sal de esta ejecución; ningún valor sale de PHP)"

# 4. Fotografía de la base antes del smoke (solo lectura).
ANTES=$(sql "$Q_TABLAS") && ANTES_CUENTA=$(sql "$Q_CUENTA") && SELLOS=$(sql "$Q_SELLOS") \
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

run_py() { # $1 = superadmin | administrador · $2 = fichero de cabecera del token
  SMOKE_PHASE="$FASE" SMOKE_PROBE_ENABLED="$PROBE" SMOKE_API="$API" SMOKE_EMAIL="$SMOKE_EMAIL" SMOKE_PW="$SMOKE_PW" \
    SMOKE_CANARY_ID="$CANARIO" SMOKE_META_ACCOUNTS="$CUENTAS" SMOKE_SECRET_SALT="$SAL" SMOKE_SECRET_DIGESTS="${HUELLAS:-}" \
    SMOKE_TOKEN_FILE="$2" PYTHONIOENCODING=utf-8 PYTHONDONTWRITEBYTECODE=1 python3 "$PY" "$1"
}

set +e
run_py superadmin "$HDR1"; RC1=$?
set -e

RC2=0
if [ "$SEGUNDA" = rol ]; then
  tinker_line '^cuenta [0-9]+ ahora Administrador$' '$a = App\Models\Admin::query()->findOrFail('"$ADMIN_ID"'); $a->forceFill(["role" => App\Models\Admin::ROLE_ADMINISTRADOR])->save(); echo $a->fresh()->role === App\Models\Admin::ROLE_ADMINISTRADOR ? "cuenta {$a->id} ahora Administrador" : "cuenta {$a->id} sin cambiar de rol", PHP_EOL;' \
    || echo "AVISO   no se pudo cambiar el rol: la parte del Administrador lo detectará (su sesión traerá canary_authorize)"
  set +e
  run_py administrador "$HDR2"; RC2=$?
  set -e
else
  echo "OMITIDO Administrador: llaves del Hito 6 y 403 en 50, 55 y 56  [SMOKE_SEGUNDA_CUENTA=no]"
  RC2=3
fi

# 6. Fotografía después y comparación.
RC3=0
echo "== Base (solo lectura)"
if DESPUES=$(sql "$Q_TABLAS") && DESPUES_CUENTA=$(sql "$Q_CUENTA") && SELLOS_DESPUES=$(sql "$Q_SELLOS"); then
  if [ "$ANTES_CUENTA" = "$DESPUES_CUENTA" ]; then
    echo "OK      ninguna fila nueva de ultron_studio_* nombra a la cuenta $ADMIN_ID"
  else
    echo "FALLO   filas de ultron_studio_* que nombran a la cuenta: antes [$ANTES_CUENTA] · después [$DESPUES_CUENTA]"
    RC3=1
  fi
  if [ "$ANTES" = "$DESPUES" ]; then
    echo "OK      recuento exacto de las 14 tablas ultron_studio_* igual antes y después [$DESPUES]"
  else
    CAMBIOS=$(join -t= -1 1 -2 1 <(tr ' ' '\n' <<< "$ANTES" | sort) <(tr ' ' '\n' <<< "$DESPUES" | sort) | awk -F= '$2 != $3 {printf "%s=%s→%s  ", $1, $2, $3}')
    if [[ "$CAMBIOS" =~ $H6_TABLAS_RE ]]; then
      echo "FALLO   una tabla del Hito 6 cambió durante el smoke (el smoke no escribe; ¿la escribió otra persona o el smoke?): $CAMBIOS"
      RC3=1
    else
      echo "AVISO   cambió el recuento de otras tablas del Studio (¿alguien usó el Studio durante el smoke?): ${CAMBIOS:-tablas distintas}"
    fi
  fi
  echo "INFO    filas de marketing_ai_actions con sello «studio» (Hito 5; informativo): $SELLOS → $SELLOS_DESPUES"
else
  echo "FALLO   no se pudo leer la base después del smoke (psql)"
  RC3=1
fi

FINAL=0
for rc in "$RC1" "$RC2" "$RC3"; do
  case "$rc" in 0) ;; 3) [ "$FINAL" = 0 ] && FINAL=3 ;; *) FINAL=1 ;; esac
done
exit "$FINAL"

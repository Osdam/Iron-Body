#!/bin/bash
# Smoke del Hito 5 en producción (fases 1, 2 y 3) con la cuenta temporal admin id 32 («Studio smoke», Super Admin,
# INACTIVA entre usos). En la segunda parte la misma cuenta hace de Administrador sin ultron.publish (se le cambia
# el rol durante el smoke) y al terminar vuelve a su reposo: Super Admin e inactiva, sin sesiones.
# Se ejecuta EN EL SERVIDOR como root, tras copiar smoke-h5.py y este fichero a /root/:
#   SMOKE_PHASE=1 bash /root/smoke-run-h5.sh   (fase 1: todo apagado)
#   SMOKE_PHASE=2 bash /root/smoke-run-h5.sh   (fase 2: motor en canary y publicar permitido; ANTES de publicar)
#   SMOKE_PHASE=3 bash /root/smoke-run-h5.sh   (fase 3: motor en all)
# Seguridad: la contraseña (aleatoria, de un solo uso) solo va por entorno a python y como HASH por la entrada
# estándar a tinker, nunca en argumentos; el id de la conversación canaria solo va por entorno a python y nunca se
# imprime; los tokens quedan en ficheros de cabecera 0600 dentro de un directorio 0700; de tinker solo se imprime
# una línea que case con lo esperado (si falla, su salida podría arrastrar un valor del fichero de entorno); un trap
# desactiva la cuenta, le devuelve el rol de reposo, borra sus sesiones, comprueba que los tokens ya no sirven y
# borra los temporales aunque el smoke falle. app y git: nunca como root (artisan como www-data; git, no se usa).
# Solo lectura: antes de nada comprueba que la configuración efectiva es la de la fase, y antes y después cuenta
# las tablas ultron_studio_* (exacto) y las filas que nombran a la cuenta en cualquier columna *admin_id.
# Códigos de salida: 0 todo OK · 1 algún FALLO · 3 sin fallos pero con algo OMITIDO · 2 uso incorrecto.
# Ensayo local (sin sudo ni producción): APP_DIR, SMOKE_API, SMOKE_RUN_AS="", SMOKE_PSQL, SMOKE_WORKDIR,
# SMOKE_PY y SMOKE_ADMIN_ID (ver plans/informes-h5/informe-devops-h5.md, «Ensayo local»).
set -euo pipefail
export LC_ALL=C

APP_DIR="${APP_DIR:-/var/www/api/backend}"
API="${SMOKE_API:-https://api.ironbodyneiva.cloud/api}"
FASE="${SMOKE_PHASE:-1}"
ADMIN_ID="${SMOKE_ADMIN_ID:-32}"
WORK="${SMOKE_WORKDIR:-/root}"
PY="${SMOKE_PY:-$WORK/smoke-h5.py}"
RUN_AS="${SMOKE_RUN_AS-www-data}"            # vacío: artisan con el usuario actual (solo en el ensayo local)
PSQL="${SMOKE_PSQL:-sudo -u postgres psql -X -Atq -d ironbody}"
SEGUNDA="${SMOKE_SEGUNDA_CUENTA:-rol}"       # rol: la misma cuenta hace de Administrador · no: se omite
SMOKE_EMAIL=studio-smoke@ironbodyneiva.cloud

case "$FASE" in
  1) ALCANCE=off;    PUBLICAR=false ;;
  2) ALCANCE=canary; PUBLICAR=true ;;
  3) ALCANCE=all;    PUBLICAR=true ;;
  *) echo "SMOKE_PHASE no válido: $FASE (1|2|3)"; exit 2 ;;
esac
case "$SEGUNDA" in rol|no) ;; *) echo "SMOKE_SEGUNDA_CUENTA no válido: $SEGUNDA (rol|no)"; exit 2 ;; esac
[[ "$ADMIN_ID" =~ ^[0-9]+$ ]] || { echo "SMOKE_ADMIN_ID no es un número"; exit 2; }
if [ -n "$RUN_AS" ] && [ "$(id -u)" != 0 ]; then echo "en el servidor se ejecuta como root"; exit 2; fi
for b in python3 php openssl curl; do command -v "$b" > /dev/null || { echo "falta $b"; exit 2; }; done
[ -f "$PY" ] || { echo "no encuentro $PY"; exit 2; }
cd "$APP_DIR"

artisan() { if [ -n "$RUN_AS" ]; then sudo -u "$RUN_AS" php artisan "$@"; else php artisan "$@"; fi; }
# Última línea de un tinker, SOLO si casa con el patrón esperado ($1, ERE); si no, nada y código 1. La entrada
# estándar pasa a tinker (así llega el hash). $2 es el código PHP.
tinker_line() {
  local salida
  salida=$(artisan tinker --execute="$2" 2>&1 | tail -1) || true
  [[ "$salida" =~ $1 ]] || return 1
  printf '%s\n' "$salida"
}
# SMOKE_PSQL se parte en palabras a propósito (es un comando con sus opciones). Desde / para que postgres no
# tropiece con el directorio de trabajo.
sql() { ( cd / && $PSQL -v ON_ERROR_STOP=1 -c "$1" ); }

# Recuento EXACTO de cada tabla del Studio (una sola consulta, sin estimaciones de pg_class).
Q_TABLAS="SELECT coalesce(string_agg(c.relname || '=' || (xpath('/row/n/text()', query_to_xml(format('SELECT count(*) AS n FROM public.%I', c.relname), false, true, '')))[1]::text, ' ' ORDER BY c.relname), '') FROM pg_class c JOIN pg_namespace s ON s.oid = c.relnamespace WHERE c.relkind = 'r' AND s.nspname = 'public' AND c.relname LIKE 'ultron\_studio\_%';"
# Filas del Studio que nombran a la cuenta de smoke en cualquier columna *admin_id (autoría de lo que escribiera).
Q_CUENTA="SELECT coalesce(string_agg(x.t || '.' || x.col || '=' || x.n, ' ' ORDER BY x.t, x.col), '') FROM (SELECT c.table_name AS t, c.column_name AS col, (xpath('/row/n/text()', query_to_xml(format('SELECT count(*) AS n FROM public.%I WHERE %I = %s', c.table_name, c.column_name, $ADMIN_ID), false, true, '')))[1]::text AS n FROM information_schema.columns c WHERE c.table_schema = 'public' AND c.table_name LIKE 'ultron\_studio\_%' AND c.column_name LIKE '%admin\_id') x;"
Q_SELLOS="SELECT count(*) FROM marketing_ai_actions WHERE metadata ? 'studio';"

umask 077
TMPD=$(mktemp -d "$WORK/.smoke-h5.XXXXXX")
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
      codigo=$(curl -s -o /dev/null -w '%{http_code}' -H @"$h" "$API/admin/ultron-studio/status")
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
  echo "SMOKE H5 fase $FASE: código de salida $FINAL"
  exit "$FINAL"
}
trap cleanup EXIT

echo "== Smoke del Hito 5 · fase $FASE · $(date -u +%Y-%m-%dT%H:%M:%SZ) · $API"

# 1. La configuración efectiva tiene que ser la de la fase (si no, el smoke mediría otra cosa).
ESPERADA="{\"engine_scope\":\"$ALCANCE\",\"publishing_enabled\":$PUBLICAR}"
CONF=$(tinker_line '^\{"engine_scope":(null|"[a-z]+"),"publishing_enabled":(true|false)\}$' 'echo json_encode(["engine_scope" => config("marketing.ultron.studio_config"), "publishing_enabled" => (bool) config("ultron_studio.publishing_enabled")]), PHP_EOL;') \
  || { echo "FALLO   no se pudo leer la configuración efectiva (tinker falló; su salida no se imprime). No se ejecuta el smoke."; FINAL=1; exit 1; }
if [ "$CONF" != "$ESPERADA" ]; then
  echo "FALLO   configuración efectiva $CONF; la fase $FASE exige $ESPERADA. No se ejecuta el smoke."
  FINAL=1
  exit 1
fi
echo "OK      configuración efectiva de la fase $FASE: motor=$ALCANCE publicar=$PUBLICAR"

# 2. ¿Tiene el motor canario? El id se queda en esta variable: nunca se imprime.
if CANARIO=$(tinker_line '^([0-9]+|none)$' '$c = config("marketing.ultron.canary_conversation_id"); echo $c === null ? "none" : (int) $c, PHP_EOL;'); then
  if [ "$CANARIO" = none ]; then echo "INFO    canario del motor configurado: no"; else echo "INFO    canario del motor configurado: sí (su id no se imprime)"; fi
else
  echo "AVISO   no se pudo leer si el motor tiene canario: la búsqueda de su id en los cuerpos queda OMITIDA"
  CANARIO=""
fi

# 3. Fotografía de la base antes del smoke (solo lectura).
ANTES=$(sql "$Q_TABLAS") && ANTES_CUENTA=$(sql "$Q_CUENTA") && SELLOS=$(sql "$Q_SELLOS") \
  || { echo "FALLO   no se pudo leer la base (psql). No se ejecuta el smoke."; FINAL=1; exit 1; }

# 4. Activar la cuenta con una contraseña de un solo uso (el hash va por stdin a tinker).
SMOKE_PW=$(openssl rand -base64 30 | tr -d '/+=' | cut -c1-28)
HASH=$(SMOKE_PW="$SMOKE_PW" php -r 'echo password_hash(getenv("SMOKE_PW"), PASSWORD_BCRYPT);')
ACTIVADA=1
printf '%s\n' "$HASH" | tinker_line '^cuenta [0-9]+ activa como Super Admin$' '$h = trim((string) fgets(STDIN)); $a = App\Models\Admin::query()->findOrFail('"$ADMIN_ID"'); $a->forceFill(["name" => "Studio smoke (temporal)", "email" => "'"$SMOKE_EMAIL"'", "password" => $h, "role" => App\Models\Admin::ROLE_SUPER_ADMIN, "status" => "active"])->save(); $f = $a->fresh(); echo $f->status === "active" && $f->role === App\Models\Admin::ROLE_SUPER_ADMIN ? "cuenta {$f->id} activa como Super Admin" : "cuenta {$f->id} sin activar", PHP_EOL;' \
  || { echo "FALLO   no se pudo activar la cuenta $ADMIN_ID (su salida no se imprime)"; unset HASH; FINAL=1; exit 1; }
unset HASH

run_py() { # $1 = superadmin | administrador · $2 = fichero de cabecera del token
  SMOKE_PHASE="$FASE" SMOKE_API="$API" SMOKE_EMAIL="$SMOKE_EMAIL" SMOKE_PW="$SMOKE_PW" SMOKE_CANARY_ID="$CANARIO" \
    SMOKE_TOKEN_FILE="$2" PYTHONIOENCODING=utf-8 python3 "$PY" "$1"
}

set +e
run_py superadmin "$HDR1"; RC1=$?
set -e

RC2=0
if [ "$SEGUNDA" = rol ]; then
  tinker_line '^cuenta [0-9]+ ahora Administrador$' '$a = App\Models\Admin::query()->findOrFail('"$ADMIN_ID"'); $a->forceFill(["role" => App\Models\Admin::ROLE_ADMINISTRADOR])->save(); echo $a->fresh()->role === App\Models\Admin::ROLE_ADMINISTRADOR ? "cuenta {$a->id} ahora Administrador" : "cuenta {$a->id} sin cambiar de rol", PHP_EOL;' \
    || echo "AVISO   no se pudo cambiar el rol: la parte del Administrador lo detectará (su sesión traerá ultron.publish)"
  set +e
  run_py administrador "$HDR2"; RC2=$?
  set -e
else
  echo "OMITIDO Administrador sin ultron.publish → 403  [SMOKE_SEGUNDA_CUENTA=no]"
  RC2=3
fi

# 5. Fotografía después y comparación.
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
    echo "OK      recuento exacto de las tablas ultron_studio_* igual antes y después [$DESPUES]"
  else
    CAMBIOS=$(join -t= -1 1 -2 1 <(tr ' ' '\n' <<< "$ANTES" | sort) <(tr ' ' '\n' <<< "$DESPUES" | sort) | awk -F= '$2 != $3 {printf "%s %s→%s  ", $1, $2, $3}')
    case "$CAMBIOS" in
      *ultron_studio_publications*) echo "FALLO   la cadena de publicaciones cambió durante el smoke: $CAMBIOS"; RC3=1 ;;
      *) echo "AVISO   cambió el recuento de otras tablas (¿alguien usó el Studio durante el smoke?): ${CAMBIOS:-tablas distintas}" ;;
    esac
  fi
  if [ "$FASE" = 1 ]; then
    if [ "$SELLOS" = 0 ] && [ "$SELLOS_DESPUES" = 0 ]; then
      echo "OK      filas de marketing_ai_actions con sello «studio»: 0 (el motor no lee la cadena)"
    else
      echo "FALLO   filas de marketing_ai_actions con sello «studio» en la fase 1: $SELLOS → $SELLOS_DESPUES"
      RC3=1
    fi
  else
    echo "INFO    filas de marketing_ai_actions con sello «studio»: $SELLOS → $SELLOS_DESPUES"
  fi
else
  echo "FALLO   no se pudo leer la base después del smoke (psql)"
  RC3=1
fi

FINAL=0
for rc in "$RC1" "$RC2" "$RC3"; do
  case "$rc" in 0) ;; 3) [ "$FINAL" = 0 ] && FINAL=3 ;; *) FINAL=1 ;; esac
done
exit "$FINAL"

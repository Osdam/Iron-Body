#!/bin/bash
# Hito 8 · OPS8-e · la ROTACIÓN del Auth Token MAESTRO de Twilio: el de la cuenta raíz que usa el OTP (y que hoy custodia
# también ElevenLabs: ULTRON_STUDIO_PROVEEDORES_ESTADO_REAL.md, A-1/H-14; B-6 del roadmap). Preparación autorizada por el
# propietario: en producción se ejecuta solo cuando él entregue el token nuevo, con su orden escrita. Calcado de
# h8-voice-scope.sh («fase 2 secreto»): el mismo escritor, la misma copia previa, el mismo config:cache como www-data, las
# mismas comprobaciones y el mismo cerrojo (los dos escriben el fichero de entorno).
#
#   bash /root/h8-otp-token-rotate.sh estado
#   bash /root/h8-otp-token-rotate.sh rotar <fichero 600 de root con el token NUEVO> [--solo-comprobar]
#   bash /root/h8-otp-token-rotate.sh vuelta <copia previa /root/env-pre-otp-token-…> [--solo-comprobar]
#
# QUÉ ESCRIBE: TWILIO_TOKEN y TWILIO_AUTH_TOKEN, los dos alias que lee config/otp.php (`otp.twilio.token` = TWILIO_TOKEN
# si está definida; si no, TWILIO_AUTH_TOKEN). Sustituye los que estén (uno o los dos), cada uno con su sangría y su
# `export`; no añade ninguno. En producción las asignaciones llevan dos espacios de sangría (227 de ellas,
# docs/ops/ROTACION-CREDENCIALES.md): por eso `grep ^TWILIO_` no las ve. Aquí se buscan como las lee phpdotenv 5:
# `^[ \t]*(export[ \t]+)?CLAVE[ \t]*=`; una clave repetida (gana la última) se niega. NO usa lib-env.sh de
# scripts/rotation: su env_set no ve `export` ni `CLAVE =`, añade la clave que falta (con dos alias, cambiaría cuál gana)
# y su reload_consumers reinicia php8.3-fpm y todo supervisor.
# QUIÉN MÁS LO NOTA: TwilioVerifyService y TwilioSmsSender (el OTP); TwilioUsageTriggerController (la firma del aviso de
# gasto del OTP, webhooks/twilio/usage-trigger: Twilio firma con el token PRINCIPAL de la cuenta, así que entre escribir
# el nuevo y promocionarlo esos avisos se rechazan con 403); la huella de Twilio del Studio (authToken.fp de
# StudioIntegrationConfig::twilioFacts: tras rotar, «drift» hasta reconocerla otra vez); y, si la voz usara la misma
# cuenta o este token como token de firma, la voz (se avisa).
#
# «rotar»: el token NUEVO llega en un fichero normal de root en 600 (una línea: el token, o TWILIO_AUTH_TOKEN=<token>;
# 32 hex en minúscula); nunca por argumentos y nunca se imprime. PASO 0, de solo lectura (si algo falla, código 2 y no se
# toca nada): el fichero de entorno (www-data 600 o root:www-data 640, C-14) y las dos claves (0 o 1 vez cada una, al
# menos una); el OTP con Twilio (driver=twilio, cuenta AC+32); la caché igual al fichero, bloque a bloque y token
# incluido (si no, config:cache publicaría además los cambios pendientes, o dejaría el OTP sin token); el token nuevo
# distinto del actual; un GET de SOLO LECTURA a la ficha de la cuenta (/2010-04-01/Accounts/{sid}.json) con el token
# NUEVO → 200, la misma cuenta, active (el cuerpo trae el token principal: no se guarda ni se imprime; el token va a curl
# por su entrada, -K -); el mismo GET con el token ACTUAL (si da 401, aviso: la vuelta atrás ya no existe); los workers
# sin BACKOFF/FATAL. Con --solo-comprobar termina ahí. Después: copia previa 600 de root en
# /root/env-pre-otp-token-<fecha> (nunca se sobrescribe) → las claves (escritura atómica con dueño y modo) → el fichero
# es la copia salvo esas líneas y trae el token nuevo → parsea → config:cache como www-data (umask 077, caché 600) → la
# configuración efectiva tiene el token nuevo (se compara, no se imprime), el resto de la configuración igual bloque a
# bloque y la caché igual al fichero → GET con el token EFECTIVO de Laravel → queue:restart → los workers como antes →
# 401 sin token → 0 ERROR nuevos. «APLICADO» solo con 0 desvíos; entonces el fichero del token se destruye (shred -u).
#
# «vuelta <copia>»: lo mismo, con el token que tenía una copia previa de este mando (la copia no se toca), y SOLO si
# Twilio lo sigue aceptando (GET 200). Al promocionar el token nuevo en la consola de Twilio, el viejo deja de valer: la
# vuelta atrás deja de existir y el mando se niega sin tocar nada. Con --solo-comprobar sirve para confirmar la promoción.
#
# Imprime la huella del Studio (authToken.fp: HMAC con la APP_KEY, 8 hex, la misma que enseña el Studio; no es el token)
# antes y después: sirve para comprobar la vista del PROCESO WEB, que el mando no puede ver (tinker y php -r son CLI y no
# comparten el OPcache del FPM; opcache.validate_timestamps del pool está sin comprobar, C-8 / N-4 a). No recarga FPM ni
# supervisor, no toca la voz, ni el H6, ni el H7. Los pasos de la consola de Twilio y de ElevenLabs son del PROPIETARIO
# (docs/ops/ROTACION-CREDENCIALES.md, nota OPS8-e). Códigos: 0 hecho o nada que hacer, 1 aborto o desvío (con la vuelta
# atrás exacta), 2 uso o condición no cumplida (no se ha tocado nada).
set -euo pipefail
export LC_ALL=C
umask 077

# ── Lo propio de producción, en un solo bloque (el banco sustituye estas líneas y ninguna otra) ──────────────────────
APPDIR=/var/www/api/backend
API=https://api.ironbodyneiva.cloud/api
TWILIO_API=https://api.twilio.com
WWW=www-data
ROOT_U=root
ROOT_G=root
COPIAS=/root
CERROJO=/run/lock/h8-voice-scope.lock
NIVEL_ERROR=production.ERROR

uso() { sed -n '8,10p' "$0" | sed 's/^# \{0,1\}//'; }
aborta() { echo "ABORTA: $*"; exit 1; }
niega() { echo "NO SE APLICA (no se ha tocado nada): $*"; exit 2; }
# El fichero del token se destruye solo si «rotar» sale APLICADO; si no, se queda (600, root) para repetir, y se avisa.
TOKEN_FICHERO=""
aviso_token() { [ -z "${TOKEN_FICHERO:-}" ] || [ ! -e "$TOKEN_FICHERO" ] || echo "AVISO: $TOKEN_FICHERO sigue en disco (600, root): repetir la orden o destruirlo con shred -u cuando ya no haga falta"; }
trap aviso_token EXIT
DESVIOS=0
desvio() { echo "   ✗ $*"; DESVIOS=$((DESVIOS + 1)); }

[ "$#" -ge 1 ] || { uso; exit 2; }
ORDEN=$1; shift
SOLO=0; FUENTE=""
case "$ORDEN" in
  estado) [ "$#" = 0 ] || { uso; exit 2; } ;;
  rotar|vuelta)
    [ "$#" -ge 1 ] && [ "$#" -le 2 ] || { uso; exit 2; }
    FUENTE=$1
    if [ "$#" = 2 ]; then [ "$2" = --solo-comprobar ] || { uso; exit 2; }; SOLO=1; fi
    # Un token por argumentos queda en ps y en el historial: se niega antes de mirar nada más.
    if [[ "$FUENTE" =~ [0-9a-fA-F]{32} ]] || [[ "$FUENTE" == *=* ]]; then
      niega "eso parece un token o una asignación: NUNCA por argumentos (quedaría en ps y en el historial). Va en un fichero 600 de root; si ya se tecleó, el token está quemado: pedir otro en la consola"
    fi ;;
  *) uso; exit 2 ;;
esac
[ "$(id -u)" = 0 ] || { echo "se ejecuta como root en el servidor"; exit 2; }
cd "$APPDIR"
ENVF="$APPDIR/.env"

# ── Lecturas comunes (calcadas de h8-voice-scope.sh) ─────────────────────────────────────────────────────────────────
workers() {
  local hasta s r m
  hasta=$(( $(date +%s) + $2 ))
  while :; do
    s=$(supervisorctl status 2>/dev/null || true)
    r=$(printf '%s\n' "$s" | grep -c RUNNING || true)
    m=$(printf '%s\n' "$s" | grep -cE 'BACKOFF|FATAL' || true)
    if { [ "$r" = "$1" ] && [ "$m" = 0 ]; } || [ "$(date +%s)" -ge "$hasta" ]; then echo "$r $m"; return 0; fi
    sleep 2
  done
}
errores_log() { local n=0 f; for f in "$APPDIR"/storage/logs/laravel*.log; do [ -f "$f" ] && n=$((n + $(grep -c "$NIVEL_ERROR" "$f" || true))); done; echo "$n"; }
fichero_ok() {
  [ -f "$ENVF" ] && [ ! -L "$ENVF" ] || return 1
  local um
  um=$(stat -c '%U %a' "$ENVF")
  [ "$um" = "$WWW 600" ] && return 0
  [ "$(stat -c '%U:%G %a' "$ENVF")" = "$ROOT_U:$WWW 640" ] && return 0
  return 1
}
cuenta_var() { grep -Ec "^[[:space:]]*(export[[:space:]]+)?$1[[:space:]]*=" "$ENVF" || true; }
parsea() { sudo -u "$WWW" php -r 'require "vendor/autoload.php"; try { Dotenv\Dotenv::createArrayBacked(".")->load(); } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' > /dev/null 2>&1; }
cachea() {
  local c=bootstrap/cache/config.php
  sudo -u "$WWW" sh -c 'umask 077; php artisan config:cache -q' || return 1
  # bootstrap/cache lo escribe www-data: root no hace chmod ahí (seguiría un enlace puesto en su lugar; H-5 de SEC7).
  [ -f "$c" ] && [ ! -L "$c" ] || return 1
  [ "$(stat -c '%U' "$c")" = "$WWW" ] || return 1
  [ "$(stat -c '%a' "$c")" = 600 ] || sudo -u "$WWW" chmod 600 "$c" || return 1
}

# ── Los tokens: python aislado, como root; los valores van solo por tuberías, nunca por argumentos ni a la pantalla ───
# Modos: token <fichero> (valida; no imprime nada) · token-solo <fichero> · cambios <fichero|copia> <rotar|vuelta>
# (JSON {CLAVE: valor} de las claves que hay en el fichero de entorno) · ya <fichero|copia> <rotar|vuelta> (si|no) ·
# entrada <clave de un uso> <nuevo|viejo|nada> [<fichero|copia>] (la entrada del lector PHP) · efectivo-copia <copia>.
PY_TOK='
import json, os, re, stat, sys
CLAVES = ("TWILIO_TOKEN", "TWILIO_AUTH_TOKEN")
FORMA = re.compile(r"[0-9a-f]{32}")
def del_fichero(p):
    try:
        fd = os.open(p, os.O_RDONLY | os.O_NOFOLLOW)
        st = os.fstat(fd)
        if not stat.S_ISREG(st.st_mode) or st.st_size > 256:
            sys.exit("el fichero del token no es un fichero normal de menos de 256 bytes")
        s = os.read(fd, 257).decode("utf-8", "strict")
    except UnicodeDecodeError:
        sys.exit("el fichero del token no es UTF-8")
    ls = [l.strip() for l in s.splitlines() if l.strip() and not l.strip().startswith("#")]
    if len(ls) != 1:
        sys.exit("el fichero del token tiene que traer UNA línea con el token (trae %d)" % len(ls))
    m = re.fullmatch(r"(?:(?:TWILIO_AUTH_TOKEN|TWILIO_TOKEN)[ \t]*=[ \t]*)?([0-9a-f]{32})", ls[0])
    if not m:
        sys.exit("la línea no tiene la forma de un Auth Token de Twilio (32 hex en minúscula; no se imprime)")
    return m.group(1)
def valores(p):
    out = {}
    for l in open(p, encoding="utf-8").read().splitlines():
        m = re.match(r"^[ \t]*(?:export[ \t]+)?([A-Z_][A-Z0-9_]*)[ \t]*=[ \t]*(.*?)[ \t]*$", l)
        if m and m.group(1) in CLAVES:
            v = m.group(2)
            if len(v) >= 2 and v[0] == v[-1] and v[0] in "\"\x27":
                v = v[1:-1]
            else:
                v = re.split(r"[ \t]+#", v, 1)[0].strip()
            out.setdefault(m.group(1), []).append(v)
    return out
def de_copia(p):
    v = valores(p)
    if not v:
        sys.exit("la copia no trae TWILIO_TOKEN ni TWILIO_AUTH_TOKEN")
    for k, vs in v.items():
        if len(vs) != 1:
            sys.exit(k + " repetida en la copia")
        if not FORMA.fullmatch(vs[0]):
            sys.exit(k + " de la copia no tiene la forma de un Auth Token (no se imprime)")
    return {k: vs[0] for k, vs in v.items()}
def efectivo(d):
    return d.get("TWILIO_TOKEN", d.get("TWILIO_AUTH_TOKEN"))
def deseado(fuente, que, env):
    presentes = sorted(valores(env))
    if que == "rotar":
        t = del_fichero(fuente)
        return {k: t for k in presentes}
    c = de_copia(fuente)
    if sorted(c) != presentes:
        sys.exit("la copia trae %s y el fichero de entorno %s: no son las mismas claves" % ("+".join(sorted(c)) or "ninguna", "+".join(presentes) or "ninguna"))
    return c
modo, a = sys.argv[1], sys.argv[2:]
env = os.environ.get("ENVF", "")
if modo == "token":
    del_fichero(a[0])
elif modo == "token-solo":
    sys.stdout.write(del_fichero(a[0]))
elif modo == "efectivo-copia":
    sys.stdout.write(efectivo(de_copia(a[0])))
elif modo == "cambios":
    print(json.dumps(deseado(a[0], a[1], env), sort_keys=True))
elif modo == "ya":
    d = deseado(a[0], a[1], env)
    v = valores(env)
    print("si" if d and all(v.get(k) == [x] for k, x in d.items()) else "no")
elif modo == "entrada":
    e = {"k": a[0]}
    if a[1] == "nuevo":
        e["nuevo"] = del_fichero(a[2])
    elif a[1] == "viejo":
        e["viejo"] = efectivo(de_copia(a[2]))
    print(json.dumps(e))
else:
    sys.exit("modo desconocido")
'
tok() { ENVF="$ENVF" python3 -I -c "$PY_TOK" "$@"; }

# Dónde y cómo están las claves en el fichero de entorno, sin valores: línea, sangría, export, comillas y longitud.
formas() {
  ENVF="$ENVF" python3 -I -c '
import os, re
s = open(os.environ["ENVF"], encoding="utf-8").read().splitlines()
for k in ("TWILIO_TOKEN", "TWILIO_AUTH_TOKEN", "TWILIO_SID", "TWILIO_ACCOUNT_SID"):
    pat = re.compile(r"^([ \t]*)(export[ \t]+)?" + k + r"[ \t]*=[ \t]*(.*?)[ \t]*$")
    hits = [(n, m) for n, l in enumerate(s, 1) for m in [pat.match(l)] if m]
    if not hits:
        print("   %s: no está en el fichero" % k)
        continue
    d = []
    for n, m in hits:
        v = m.group(3)
        q = len(v) >= 2 and v[0] == v[-1] and v[0] in "\"\x27"
        v = v[1:-1] if q else re.split(r"[ \t]+#", v, 1)[0].strip()
        d.append("línea %d, sangría %d, %s, %s, %d car." % (n, len(m.group(1)), "export" if m.group(2) else "sin export", "comillas" if q else "sin comillas", len(v)))
    print("   %s: %s%s" % (k, " · ".join(d), " — REPETIDA (gana la última)" if len(hits) > 1 else ""))'
}

# El lector: la configuración del OTP como la ve Laravel (como www-data). Con «fresca», sin la caché (APP_CONFIG_CACHE a
# un fichero que no existe): lo que publicaría un config:cache ahora. La entrada (JSON por la entrada estándar) trae una
# clave de un solo uso para comparar entre procesos sin imprimir nada, y el token nuevo o el viejo para compararlos con
# el efectivo. Salida: JSON en una línea, sin ningún valor secreto (longitudes, booleanos, la cuenta, la huella del Studio
# y HMAC de un solo uso de cada bloque de la configuración).
PHP_LECTOR='
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$in = json_decode((string) stream_get_contents(STDIN), true);
$in = is_array($in) ? $in : [];
$k = is_string($in["k"] ?? null) ? $in["k"] : "";
$s = fn ($v) => is_string($v) ? $v : "";
$t = $s(config("otp.twilio.token"));
$sid = $s(config("otp.twilio.sid"));
$igual = fn ($x) => is_string($x) && $x !== "" && $t !== "" && hash_equals($t, $x);
$alias = config("ultron_studio.integrations.twilio_aliases");
$alias = is_array($alias) ? $alias : [];
$c = config()->all();
unset($c["otp"]["twilio"]["token"]);
unset($c["ultron_studio"]["integrations"]["twilio_aliases"]["token_consistent"], $c["ultron_studio"]["integrations"]["twilio_aliases"]["token_source"]);
$h = [];
foreach ($c as $b => $v) {
    $h[$b] = $k === "" ? null : substr(hash_hmac("sha256", (string) json_encode($v, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE), $k), 0, 16);
}
ksort($h);
$studio = null; $deriva = []; $fp = null;
try {
    $f = App\Services\UltronStudio\StudioIntegrationConfig::twilioFacts();
    $fp = $f["authToken"]["fp"] ?? null;
    foreach ((array) (app(App\Services\UltronStudio\StudioIntegrationConfig::class)->overview()["providers"] ?? []) as $p) {
        if (is_array($p) && ($p["provider"] ?? null) === "twilio") {
            $studio = $p["state"] ?? null;
            foreach ((array) ($p["drift"] ?? []) as $d) { $deriva[] = (string) ($d["key"] ?? "?"); }
        }
    }
} catch (Throwable $e) { $studio = "ilegible"; }
$vt = $s(config("voice.twilio.signing_token"));
$vsid = $s(config("voice.twilio.account_sid"));
echo json_encode([
    "driver" => config("otp.driver"),
    "sid" => preg_match("/^AC[0-9a-f]{32}\$/", $sid) ? $sid : null,
    "token_len" => strlen($t),
    "token_forma" => (bool) preg_match("/^[0-9a-f]{32}\$/", $t),
    "token_k" => $k !== "" && $t !== "" ? substr(hash_hmac("sha256", $t, $k), 0, 16) : null,
    "es_nuevo" => $igual($in["nuevo"] ?? null),
    "es_viejo" => $igual($in["viejo"] ?? null),
    "fuente" => $alias["token_source"] ?? null,
    "alias_token" => $alias["token_consistent"] ?? null,
    "alias_sid" => $alias["sid_consistent"] ?? null,
    "verify" => (bool) preg_match("/^VA[0-9a-f]{32}\$/", $s(config("otp.twilio.verify_service_sid"))),
    "base" => parse_url($s(config("otp.twilio.base")), PHP_URL_HOST),
    "verify_base" => parse_url($s(config("otp.twilio.verify_base")), PHP_URL_HOST),
    "envio" => (bool) config("otp.send_enabled", true),
    "voz_misma_cuenta" => $vsid !== "" && $sid !== "" && hash_equals($sid, $vsid),
    "voz_firma_es_este" => $igual($vt),
    "studio" => $studio,
    "deriva" => $deriva,
    "fp" => $fp,
    "monitor_h7" => config("ultron_studio.monitor.enabled") === true,
    "huellas" => $h,
]), PHP_EOL;
'
lee_otp() { # [fresca]; la entrada por la entrada estándar → JSON en una línea, o nada si no se pudo leer
  local l
  if [ "${1:-}" = fresca ]; then
    l=$(sudo -u "$WWW" env APP_CONFIG_CACHE=/nonexistent/h8-otp-token-rotate/config.php php -d display_errors=stderr -r "$PHP_LECTOR" 2> /dev/null | tail -1) || true
  else
    l=$(sudo -u "$WWW" php -d display_errors=stderr -r "$PHP_LECTOR" 2> /dev/null | tail -1) || true
  fi
  case "$l" in '{"driver":'*) printf '%s\n' "$l" ;; esac
}
# El token efectivo de Laravel (la caché), SOLO hacia una tubería.
token_efectivo() { sudo -u "$WWW" php -d display_errors=stderr -r 'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $t = config("otp.twilio.token"); echo is_string($t) ? $t : "";' 2> /dev/null || true; }
campo() { printf '%s' "$1" | python3 -I -c 'import json, sys; v = json.load(sys.stdin).get(sys.argv[1]); print(v if isinstance(v, str) else json.dumps(v))' "$2"; }

# GET de SOLO LECTURA a la ficha de la cuenta con el token que llega por la entrada estándar: «código cuenta=si|no
# estado=… tipo=…». El token pasa a curl por su entrada (-K -), nunca por argumentos; el cuerpo (trae el token principal
# de la cuenta) se lee en memoria para sacar tres campos y se descarta.
sonda() { # $1 = la cuenta (AC…)
  local r
  r=$( (python3 -I -c '
import re, sys
t = sys.stdin.read().strip()
if not re.fullmatch(r"[0-9a-f]{32}", t):
    sys.exit(3)
sys.stdout.write("url = \"%s/2010-04-01/Accounts/%s.json\"\nuser = \"%s:%s\"\n" % (sys.argv[2], sys.argv[1], sys.argv[1], t))' "$1" "$TWILIO_API" \
    | curl -sS --connect-timeout 10 --max-time 20 -K - -H 'Accept: application/json' -w '\n%{http_code}' \
    | python3 -I -c '
import json, sys
cuerpo, _, codigo = sys.stdin.read().rpartition("\n")
try:
    d = json.loads(cuerpo) if cuerpo.strip() else {}
except Exception:
    d = {}
d = d if isinstance(d, dict) else {}
print("%s cuenta=%s estado=%s tipo=%s" % (codigo.strip() or "000", "si" if d.get("sid") == sys.argv[1] else "no", d.get("status") or "-", d.get("type") or "-"))' "$1") 2> /dev/null) || true
  printf '%s\n' "${r:-000 cuenta=no estado=- tipo=-}" | tail -1
}

# La escritura (python aislado, como root; los valores por la entrada estándar). SOLO sustituye: cada clave tiene que
# estar exactamente una vez; conserva su sangría y su `export`; temporal en el mismo directorio (O_EXCL, O_NOFOLLOW, 600)
# con el dueño y el modo del original, fsync y os.replace.
escribir() {
  ENVF="$ENVF" python3 -I -c '
import json, os, re, stat, sys
p = os.environ["ENVF"]
cambios = json.load(sys.stdin)
fd = os.open(p, os.O_RDONLY | os.O_NOFOLLOW)
st = os.fstat(fd)
if not stat.S_ISREG(st.st_mode):
    sys.exit("no es un fichero normal")
with os.fdopen(fd, encoding="utf-8") as fh:
    s = fh.read()
if not cambios:
    sys.exit("nada que escribir")
for k, v in cambios.items():
    if k not in ("TWILIO_TOKEN", "TWILIO_AUTH_TOKEN") or not isinstance(v, str) or not re.fullmatch(r"[0-9a-f]{32}", v):
        sys.exit("clave o valor fuera de forma (no se imprime)")
    pat = re.compile(r"^([ \t]*)(export[ \t]+)?" + re.escape(k) + r"[ \t]*=.*$", re.M)
    n = len(pat.findall(s))
    if n != 1:
        sys.exit("%s aparece %d veces en el fichero de entorno (tiene que ser 1)" % (k, n))
    s = pat.sub(lambda m: m.group(1) + (m.group(2) or "") + k + "=" + v, s, count=1)
    print("   " + k + " sustituida")
d = os.path.dirname(p)
tmp = os.path.join(d, ".env.h8-otp-token-" + str(os.getpid()))
tfd = os.open(tmp, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
with os.fdopen(tfd, "w", encoding="utf-8") as fh:
    fh.write(s)
    fh.flush()
    os.fchown(fh.fileno(), st.st_uid, st.st_gid)
    os.fchmod(fh.fileno(), stat.S_IMODE(st.st_mode))
    os.fsync(fh.fileno())
os.replace(tmp, p)
dfd = os.open(d, os.O_RDONLY)
os.fsync(dfd)
os.close(dfd)'
}

# El resumen legible de una lectura (sin secretos).
resumen() {
  printf '%s' "$1" | python3 -I -c '
import json, sys
d = json.load(sys.stdin)
sid = d.get("sid") or ""
print("driver=%s · cuenta %s · token %s car.%s · fuente %s · alias del token %s · Verify %s · base %s · envío %s" % (
    d.get("driver"), (sid[:10] + "…") if sid else "NO VÁLIDA", d.get("token_len"), "" if d.get("token_forma") else " (NO son 32 hex)",
    d.get("fuente"), {True: "coherentes", False: "DISTINTOS (gana TWILIO_TOKEN)", None: "uno solo"}.get(d.get("alias_token"), "?"),
    "VA+32" if d.get("verify") else "NO", d.get("base"), "encendido" if d.get("envio") else "APAGADO"))'
}
# Bloques de la configuración que difieren entre dos lecturas (los HMAC de un solo uso), o «ninguno».
bloques_distintos() {
  A="$1" B="$2" python3 -I -c '
import json, os
a = json.loads(os.environ["A"]).get("huellas") or {}
b = json.loads(os.environ["B"]).get("huellas") or {}
d = sorted(k for k in set(a) | set(b) if a.get(k) != b.get(k) or a.get(k) is None)
print(",".join(d) if d else "ninguno")'
}
estudio() { # «estado (deriva: a,b) · huella xxxxxxxx»
  printf '%s' "$1" | python3 -I -c '
import json, sys
d = json.load(sys.stdin)
print("%s%s · huella del token en el Studio %s" % (d.get("studio"), (" (deriva: " + ",".join(d.get("deriva") or []) + ")") if d.get("deriva") else "", d.get("fp") or "—"))'
}

K=$(python3 -I -c 'import secrets; print(secrets.token_hex(16))')

# ── estado (solo lectura) ─────────────────────────────────────────────────────────────────────────────────────────────
if [ "$ORDEN" = estado ]; then
  echo "== estado del token del OTP (sin valores; solo lectura)"
  if fichero_ok; then echo "   fichero de entorno: $(stat -c '%U:%G %a' "$ENVF") (C-14: www-data 600 o root:www-data 640)"; else echo "   fichero de entorno: $(stat -c '%U:%G %a' "$ENVF" 2>/dev/null || echo '?') — NO ADMITIDO (C-14): rotar se negará"; fi
  formas
  C1=$(tok entrada "$K" nada | lee_otp)
  C2=$(tok entrada "$K" nada | lee_otp fresca)
  [ -n "$C1" ] || { echo "   ✗ la configuración efectiva no se puede leer (la salida de PHP no se imprime)"; exit 0; }
  echo "   configuración efectiva (la caché): $(resumen "$C1")"
  if [ -z "$C2" ]; then
    echo "   ✗ la configuración del fichero (sin caché) no se puede leer"
  else
    BD=$(bloques_distintos "$C1" "$C2")
    if [ "$BD" = ninguno ] && [ "$(campo "$C1" token_k)" = "$(campo "$C2" token_k)" ]; then
      echo "   la caché y el fichero: iguales (bloque a bloque y el token): un config:cache no cambiaría nada"
    else
      echo "   ✗ la caché y el fichero NO son iguales (bloques distintos: $BD; token $([ "$(campo "$C1" token_k)" = "$(campo "$C2" token_k)" ] && echo igual || echo DISTINTO)): un config:cache publicaría eso (rotar se negará)"
    fi
  fi
  SID=$(campo "$C1" sid)
  if [ "$SID" != null ]; then echo "   Twilio, GET de solo lectura a la ficha de la cuenta con el token EFECTIVO: $(token_efectivo | sonda "$SID")"; fi
  echo "   Studio · Twilio: $(estudio "$C1")"
  echo "   la voz: $([ "$(campo "$C1" voz_misma_cuenta)" = true ] && echo 'usa LA MISMA cuenta que el OTP' || echo 'no usa la cuenta del OTP') · su token de firma $([ "$(campo "$C1" voz_firma_es_este)" = true ] && echo 'ES este token' || echo 'no es este token')"
  echo "   monitor del H7: $([ "$(campo "$C1" monitor_h7)" = true ] && echo 'encendido (tras rotar, config_drift:twilio hasta reconocer)' || echo apagado)"
  echo "   workers (RUNNING · BACKOFF/FATAL): $(workers 0 0)"
  exit 0
fi

# ── rotar | vuelta · paso 0, de solo lectura ─────────────────────────────────────────────────────────────────────────
echo "== h8-otp-token-rotate.sh $ORDEN · $(date -u '+%Y-%m-%d %H:%M:%S') UTC$([ "$SOLO" = 1 ] && echo ' · SOLO COMPROBAR (no se escribe nada)')"
[ -f "$FUENTE" ] && [ ! -L "$FUENTE" ] || niega "$FUENTE no es un fichero normal"
[ "$(stat -c '%U %a' "$FUENTE")" = "$ROOT_U 600" ] || niega "$FUENTE tiene que ser de $ROOT_U y 600 (es $(stat -c '%U %a' "$FUENTE"))"
if [ "$ORDEN" = rotar ]; then
  TOKEN_FICHERO=$FUENTE
  E=$(tok token "$FUENTE" 2>&1) || niega "$E"
  echo "0. fichero del token: $FUENTE ($ROOT_U 600, una línea, 32 hex; no se imprime)"
else
  case "$FUENTE" in "$COPIAS"/env-pre-otp-token-*) ;; *) niega "la vuelta atrás lee una copia previa de este mando ($COPIAS/env-pre-otp-token-…), no $FUENTE" ;; esac
  E=$(tok efectivo-copia "$FUENTE" 2>&1 > /dev/null) || niega "$E"
  echo "0. copia previa: $FUENTE ($ROOT_U 600; la copia no se toca)"
fi
fichero_ok || niega "el fichero de entorno no es un fichero normal de $WWW en 600 ni de $ROOT_U:$WWW en 640 (es $(stat -c '%U:%G %a' "$ENVF" 2>/dev/null || echo '?')): C-14"
exec 9> "$CERROJO"
flock -n 9 || niega "otro mando que escribe el fichero de entorno está en curso ($CERROJO)"
NT=$(cuenta_var TWILIO_TOKEN); NA=$(cuenta_var TWILIO_AUTH_TOKEN)
[ "$NT" -le 1 ] && [ "$NA" -le 1 ] || niega "TWILIO_TOKEN aparece $NT veces y TWILIO_AUTH_TOKEN $NA (gana la última): deduplicar antes (scripts/rotation/dedupe-env-keys.sh)"
[ $((NT + NA)) -ge 1 ] || niega "el fichero de entorno no define TWILIO_TOKEN ni TWILIO_AUTH_TOKEN: el OTP no lee su token de aquí (¿de dónde sale el de la caché?); no se añade nada a ciegas"
echo "   fichero de entorno: $(stat -c '%U:%G %a' "$ENVF") · las claves (sin valores):"
formas
QUE=nuevo; [ "$ORDEN" = vuelta ] && QUE=viejo
ANTES=$(tok entrada "$K" "$QUE" "$FUENTE" | lee_otp)
FRESCA=$(tok entrada "$K" "$QUE" "$FUENTE" | lee_otp fresca)
[ -n "$ANTES" ] || niega "la configuración efectiva no se puede leer (la salida de PHP no se imprime)"
[ -n "$FRESCA" ] || niega "la configuración del fichero (sin caché) no se puede leer: el fichero o el código no arrancan"
echo "   configuración efectiva: $(resumen "$ANTES")"
[ "$(campo "$ANTES" driver)" = twilio ] || niega "el OTP no usa Twilio (driver=$(campo "$ANTES" driver)): este mando rota el token del OTP con Twilio"
SID=$(campo "$ANTES" sid)
[ "$SID" != null ] || niega "la cuenta del OTP (otp.twilio.sid) no tiene la forma AC+32 hex"
BD=$(bloques_distintos "$ANTES" "$FRESCA")
[ "$BD" = ninguno ] || niega "la caché y el fichero de entorno difieren en: $BD. Un config:cache publicaría eso junto con el token: aclararlo antes (un cambio a medias de otro mando o una edición a mano)"
[ "$(campo "$ANTES" token_k)" = "$(campo "$FRESCA" token_k)" ] || niega "el token del fichero de entorno NO es el que tiene la caché: un config:cache cambiaría el token del OTP por otro (o lo dejaría sin token). Aclararlo antes"
echo "   la caché y el fichero: iguales (bloque a bloque y el token): config:cache solo cambiará el token"
YA=$(tok ya "$FUENTE" "$([ "$ORDEN" = rotar ] && echo rotar || echo vuelta)" 2>&1) || niega "$YA"
if [ "$ORDEN" = rotar ]; then ES=$(campo "$ANTES" es_nuevo); else ES=$(campo "$ANTES" es_viejo); fi
if [ "$ES" = true ]; then
  [ "$YA" = si ] || niega "la configuración efectiva ya tiene ese token pero el fichero no en todas sus claves: estado incoherente, aclararlo a mano"
  echo "== $ORDEN: la configuración y el fichero ya tienen ese token; nada que escribir"
  [ "$ORDEN" = vuelta ] || { shred -u "$FUENTE" 2>/dev/null || rm -f -- "$FUENTE"; echo "   $FUENTE destruido"; }
  exit 0
fi
echo "   el token $([ "$ORDEN" = rotar ] && echo nuevo || echo 'de la copia') es distinto del actual: sí"
if [ "$ORDEN" = rotar ]; then RN=$(tok token-solo "$FUENTE" | sonda "$SID"); else RN=$(tok efectivo-copia "$FUENTE" | sonda "$SID"); fi
echo "   Twilio, GET de solo lectura a la ficha de la cuenta ${SID:0:10}… con el token $([ "$ORDEN" = rotar ] && echo NUEVO || echo 'DE LA COPIA'): $RN"
case "$RN" in
  "200 cuenta=si estado=active "*) ;;
  401*) if [ "$ORDEN" = rotar ]; then niega "Twilio rechaza el token nuevo (401): no es de esta cuenta, está mal copiado o ya no existe"; else niega "Twilio ya no acepta el token de la copia (401): se promocionó el nuevo (o se revocó). La vuelta atrás no existe: el camino es seguir con el token de ahora"; fi ;;
  *) niega "la ficha de la cuenta no se pudo confirmar ($RN): sin esa prueba no se escribe nada" ;;
esac
RA=$(token_efectivo | sonda "$SID")
case "$RA" in
  "200 cuenta=si"*) if [ "$ORDEN" = rotar ]; then echo "   Twilio, el mismo GET con el token ACTUAL: $RA (sigue vivo: la vuelta atrás existe hasta que se promocione el nuevo)"; else echo "   Twilio, el mismo GET con el token de ahora: $RA"; fi ;;
  *) echo "   AVISO: el mismo GET con el token ACTUAL da $RA: Twilio ya no lo acepta (¿promocionado o revocado?). El OTP está fallando ahora mismo y la vuelta atrás no existiría" ;;
esac
W0=$(workers 999 0); RUN0=${W0%% *}
[ "${W0#* }" = 0 ] || niega "workers con BACKOFF o FATAL antes de empezar ($W0): no se toca nada sobre un sistema degradado"
case "$RUN0" in 13|14) ;; *) niega "workers RUNNING antes de empezar: $RUN0 (13 sin ironbody-voice, 14 con él)" ;; esac
echo "   workers: $W0 · Studio · Twilio: $(estudio "$ANTES") · monitor del H7: $([ "$(campo "$ANTES" monitor_h7)" = true ] && echo encendido || echo apagado)"
[ "$(campo "$ANTES" voz_misma_cuenta)" != true ] || echo "   AVISO: la voz usa LA MISMA cuenta que el OTP (TWILIO_VOICE_ACCOUNT_SID): contra D8-4/D8-5; sus API keys no cambian, pero ver el aviso del token de firma"
[ "$(campo "$ANTES" voz_firma_es_este)" != true ] || echo "   AVISO: TWILIO_VOICE_SIGNING_TOKEN ES este token: al PROMOCIONAR, Twilio firmará los webhooks de la voz con el nuevo → cambiarlo en ese momento con «h8-voice-scope.sh fase 2 secreto» (llamadas apagadas)"
if [ "$SOLO" = 1 ]; then
  echo "COMPROBACIÓN EN VERDE: nada escrito (--solo-comprobar)$([ "$ORDEN" = rotar ] && echo "; $FUENTE sigue en disco para la orden de verdad")"
  TOKEN_FICHERO=""
  exit 0
fi

# ── Copia previa y escritura ─────────────────────────────────────────────────────────────────────────────────────────
DUENO=$(stat -c '%U:%G' "$ENVF"); MODO=$(stat -c '%a' "$ENVF")
TS=$(date -u +%Y%m%d-%H%M%S)
PREFIJO=""; if [ "$ORDEN" = vuelta ]; then PREFIJO="vuelta-"; fi   # (no `$([ … ] && …)` en una asignación: con set -e, saldría sin decir nada)
COPIA="$COPIAS/env-pre-otp-token-$PREFIJO$TS"
[ ! -e "$COPIA" ] || niega "ya existe $COPIA: nunca se sobrescribe una copia (esperar un segundo y repetir)"
install -m 600 -o "$ROOT_U" -g "$ROOT_G" "$ENVF" "$COPIA"
VUELTA="install -m $MODO -o ${DUENO%%:*} -g ${DUENO##*:} $COPIA $ENVF && cd $APPDIR && sudo -u $WWW sh -c 'umask 077; php artisan config:cache -q' && sudo -u $WWW chmod 600 bootstrap/cache/config.php && sudo -u $WWW php artisan queue:restart -q"
ERR0=$(errores_log)
echo "1. copia previa: $COPIA (600, $ROOT_U) · fichero $DUENO $MODO"
tok cambios "$FUENTE" "$([ "$ORDEN" = rotar ] && echo rotar || echo vuelta)" | escribir || aborta "no se pudo escribir el fichero de entorno (no se imprime nada). Vuelta: $VUELTA"
[ "$(stat -c '%U:%G %a' "$ENVF")" = "$DUENO $MODO" ] || aborta "el dueño o el modo del fichero de entorno cambiaron al escribir. Vuelta: $VUELTA"
if python3 -I -c '
import re, sys
pat = re.compile(r"^[ \t]*(?:export[ \t]+)?(TWILIO_TOKEN|TWILIO_AUTH_TOKEN)[ \t]*=")
f = lambda p: [l for l in open(p, encoding="utf-8").read().splitlines() if not pat.match(l)]
sys.exit(0 if f(sys.argv[1]) == f(sys.argv[2]) else 1)' "$ENVF" "$COPIA"; then
  echo "   el fichero es la copia previa salvo las líneas de TWILIO_TOKEN y TWILIO_AUTH_TOKEN"
else
  aborta "el fichero de entorno difiere de la copia previa en algo más que el token (comparar a mano sin imprimir valores). Vuelta: $VUELTA"
fi
[ "$(tok ya "$FUENTE" "$([ "$ORDEN" = rotar ] && echo rotar || echo vuelta)")" = si ] || aborta "el fichero no quedó con el token pedido en todas sus claves. Vuelta: $VUELTA"
[ "$(cuenta_var TWILIO_TOKEN) $(cuenta_var TWILIO_AUTH_TOKEN)" = "$NT $NA" ] || aborta "cambió el número de definiciones de las claves. Vuelta: $VUELTA"
# B-1 del H5: el fichero tiene que parsear ANTES de tocar la caché (si Laravel no arranca, la API cae).
parsea || aborta "el fichero de entorno NO parsea tras escribir. Vuelta: $VUELTA"
cachea || aborta "config:cache falló o la caché no quedó de $WWW en 600 (su salida no se imprime). Vuelta: $VUELTA"
echo "2. el fichero parsea · caché: $(stat -c '%U %a' bootstrap/cache/config.php)"

# ── Comprobaciones posteriores ───────────────────────────────────────────────────────────────────────────────────────
DESPUES=$(tok entrada "$K" "$QUE" "$FUENTE" | lee_otp)
DFRESCA=$(tok entrada "$K" "$QUE" "$FUENTE" | lee_otp fresca)
[ -n "$DESPUES" ] && [ -n "$DFRESCA" ] || aborta "la configuración no se puede leer tras el cambio (la salida de PHP no se imprime). Vuelta: $VUELTA"
if [ "$ORDEN" = rotar ]; then ES=$(campo "$DESPUES" es_nuevo); else ES=$(campo "$DESPUES" es_viejo); fi
[ "$ES" = true ] || aborta "la configuración efectiva NO tiene el token pedido. Vuelta: $VUELTA"
echo "3. configuración efectiva: $(resumen "$DESPUES")"
echo "   el token efectivo es el $([ "$ORDEN" = rotar ] && echo nuevo || echo 'de la copia') (comparado, no impreso)"
BD=$(bloques_distintos "$ANTES" "$DESPUES")
[ "$BD" = ninguno ] && echo "   el resto de la configuración, igual bloque a bloque (driver, cuenta, Verify, voz, H4–H7…)" || desvio "la configuración cambió en algo más que el token: bloques $BD"
BD=$(bloques_distintos "$DESPUES" "$DFRESCA")
[ "$BD" = ninguno ] && [ "$(campo "$DESPUES" token_k)" = "$(campo "$DFRESCA" token_k)" ] && echo "   la caché y el fichero: iguales" || desvio "la caché nueva y el fichero difieren (bloques $BD)"
[ $((NT + NA)) -lt 2 ] || [ "$(campo "$DESPUES" alias_token)" = true ] || desvio "los dos alias del token no quedaron iguales (alias_token=$(campo "$DESPUES" alias_token))"
echo "   Studio · Twilio: $(estudio "$ANTES") → $(estudio "$DESPUES")"
NUEVAS=$(A="$ANTES" B="$DESPUES" python3 -I -c '
import json, os
a = set(json.loads(os.environ["A"]).get("deriva") or []); b = set(json.loads(os.environ["B"]).get("deriva") or [])
print(",".join(sorted((b - a) - {"authToken", "aliasesConsistent"})) or "ninguna")')
[ "$NUEVAS" = ninguna ] || desvio "la huella de Twilio del Studio cambió en algo más que el token: $NUEVAS"
RE=$(token_efectivo | sonda "$SID")
echo "4. Twilio, GET de solo lectura con el token EFECTIVO de Laravel (la caché nueva): $RE"
case "$RE" in "200 cuenta=si estado=active "*) ;; *) desvio "Twilio no acepta el token efectivo ($RE)" ;; esac
sudo -u "$WWW" php artisan queue:restart -q || desvio "queue:restart no salió con 0"
sleep 6
W1=$(workers "$RUN0" 45); sleep 8; W2=$(workers "$RUN0" 45)
echo "5. workers (RUNNING · BACKOFF/FATAL), dos lecturas: [$W1] y [$W2] (esperado $RUN0 0 las dos veces)"
[ "$W1" = "$RUN0 0" ] && [ "$W2" = "$RUN0 0" ] || desvio "workers [$W1] y [$W2] (esperado $RUN0 0)"
c=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: application/json' "$API/admin/voice/status" || true)
echo "6. admin/voice/status sin token → $c"; [ "$c" = 401 ] || desvio "admin/voice/status sin token respondió $c (esperado 401)"
ERR=$(errores_log)
echo "7. $NIVEL_ERROR en laravel*.log: $ERR0 → $ERR"; [ "$ERR" = "$ERR0" ] || desvio "$((ERR - ERR0)) ERROR nuevos en el log durante el cambio"

# ── Lo que sigue (informativo: no cambia el veredicto) ───────────────────────────────────────────────────────────────
FP=$(campo "$DESPUES" fp)
echo "   VISTA DEL PROCESO WEB (antes de seguir; el mando no la ve: C-8): en el CRM, Studio → Integraciones → Twilio (o GET admin/ultron-studio/integrations/config) la huella del token tiene que ser $FP$([ "$(campo "$ANTES" studio)" = authorized ] && echo ' y el estado «drift» en authToken'). Si sigue la de antes ($(campo "$ANTES" fp)), el FPM no ve la caché nueva: NO promocionar; recargarlo con autorización (systemctl reload php8.3-fpm, como install-meta-ads-token.sh) y volver a mirar"
if [ "$ORDEN" = rotar ]; then
  echo "   AVISO (propietario, ahora): un inicio de sesión real con OTP desde una sesión nueva (un SMS de Verify). Con el secundario sin promocionar valen los dos tokens: esta prueba no distingue el viejo del nuevo; la que lo distingue es la de después de promocionar"
  echo "   AVISO (propietario, en cuanto la vista web cuadre, sin dejar pasar horas): promocionar el token en la consola de Twilio. Hasta entonces Twilio firma con el principal viejo y el aviso de gasto del OTP (webhooks/twilio/usage-trigger) responde 403. Al promocionar, el viejo deja de valer: la vuelta atrás deja de existir. Confirmarlo con: bash $0 vuelta $COPIA --solo-comprobar (tiene que negarse con 401) y repetir el inicio de sesión con OTP"
  echo "   AVISO (propietario): ElevenLabs guarda el token VIEJO como secreto del workspace (twilio_token_account_${SID:0:10}…): al promocionar deja de valer y el número importado deja de poder llamar. Rehacer la importación con una credencial RESTRINGIDA (D-2/D-3 y B-6: key de solo voz o subcuenta (b′)), NO con este token maestro nuevo: si no, el nuevo queda en la misma custodia que el viejo (A-1/H-14)"
  [ "$(campo "$ANTES" studio)" != authorized ] || echo "   AVISO: reconocer otra vez la configuración de Twilio en el Studio cuando la vista web cuadre (con el monitor del H7 encendido, config_drift:twilio salta hasta entonces)"
fi
echo "   vuelta atrás exacta (el fichero de antes; solo mientras Twilio acepte el token viejo): $VUELTA"
[ "$ORDEN" = vuelta ] || echo "   vuelta atrás funcional (solo el token, comprobado contra Twilio antes de escribir): bash $0 vuelta $COPIA"
if [ "$ORDEN" = rotar ] && [ "$DESVIOS" = 0 ]; then
  shred -u "$FUENTE" 2>/dev/null || rm -f -- "$FUENTE"
  echo "   $FUENTE destruido (el token nuevo solo queda en el fichero de entorno y en la caché, las dos 600; el viejo, en la copia previa)"
fi
if [ "$DESVIOS" = 0 ]; then
  echo "MANDO otp-token-$ORDEN APLICADO (copia previa: $COPIA)"
else
  echo "MANDO otp-token-$ORDEN ESCRITO CON $DESVIOS DESVÍOS: no seguir hasta aclararlos (copia previa: $COPIA; vuelta: $VUELTA)"
  exit 1
fi

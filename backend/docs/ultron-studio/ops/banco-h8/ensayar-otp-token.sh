#!/bin/bash
# OPS8-e · ensayo en el banco del mando de la ROTACIÓN del token maestro del OTP (h8-otp-token-rotate.sh) contra la
# cuenta maestra del simulador de Twilio, que rota como la consola (/__maestro: secundario, promoción). Nada sale de
# 127.0.0.1; los tokens del ensayo son aleatorios, nunca van por argumentos y se guardan en $B/tokens (600) para el
# barrido. Deja el banco como estaba: el fichero de entorno byte a byte y el token original otra vez principal.
#   bash ensayar-otp-token.sh <banco>        (con el banco arrancado: bash ensayar.sh <banco> arrancar)
# Qué demuestra:
#   0  el punto de partida: el OTP del banco es la cuenta maestra del simulador; «estado»; el aviso de gasto del OTP, 200
#   1  los negativos, que no tocan nada (el fichero de entorno, byte a byte)
#   2  ciclo A: rotar (--solo-comprobar y de verdad), la vista web (la huella del Studio que da la API), la ventana del aviso
#      de gasto (403 hasta promocionar) y la vuelta atrás antes de promocionar → el fichero byte a byte como al principio
#   3  ciclo B: con Twilio reconocida en el Studio, rotar → «drift» en authToken; promocionar → el aviso, 200, y la vuelta
#      atrás se niega (401) sin tocar nada
#   4  el banco como estaba: el token original otra vez principal y «vuelta» con la copia → byte a byte y «authorized»
#   5  cierre: el registro, los de Laravel y el del simulador sin tokens; 0 violaciones nuevas en el simulador
. "$(dirname "$0")/comun.sh"
B=$(banco_dir "${1:-}")
cargar_banco "$B"
BE="$B/api/backend"
ENVB="$BE/.env"
export PATH="$B/bin:$PATH"   # los sustitutos del banco (sudo, stat, supervisorctl…)
E=(bash "$AQUI/ensayar.sh" "$B")
PHPBIN=$(command -v php)
LOG="$B/logs/ensayo-otp-token-$(date -u +%Y%m%d-%H%M%S).txt"
( umask 077; : > "$LOG" )
vivo "$PUERTO_VALLA" && vivo "$PUERTO_EL" && vivo "$PUERTO_TW" || muere "arranca antes: bash $AQUI/ensayar.sh $B arrancar"
API="http://127.0.0.1:$BANCO_PORT/api"
AYUDANTE="$AQUI/ayudante-ops8d.php"
TOK="$B/tokens"; mkdir -p "$TOK"; chmod 700 "$TOK"
SALIDA=""; RESP=""
php_real() { ( cd "$BE" && env "${RED[@]}" "$PHPBIN" "$@" ); }
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
hay() { grep -qE "$1" <<< "$SALIDA"; }
anota() { { echo "════ $1"; shift; printf '%s\n' "$@"; } >> "$LOG"; }
jc() { printf '%s' "${2:-$RESP}" | python3 -I -c 'import json, sys
d = json.load(sys.stdin)
for p in sys.argv[1].split("."):
    d = (d[int(p)] if p.isdigit() and 0 <= int(p) < len(d) else None) if isinstance(d, list) else (d.get(p) if isinstance(d, dict) else None)
print(json.dumps(d, ensure_ascii=False) if isinstance(d, (dict, list, bool)) else ("" if d is None else d))' "$1" 2>/dev/null || true; }
uuid() { python3 -I -c 'import uuid; print(uuid.uuid4())'; }
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
# El bloque de Twilio de GET integrations/config, como lo calcula el proceso web: «estado huella deriva».
studio_twilio() {
  api b GET admin/ultron-studio/integrations/config
  printf '%s' "$RESP" | python3 -I -c '
import json, sys
d = json.load(sys.stdin)["body"]
d = d.get("data", d)
ps = d.get("providers") if isinstance(d, dict) else None
v = next((p for p in (ps or []) if isinstance(p, dict) and p.get("provider") == "twilio"), None) if isinstance(ps, list) else None
v = v or {}
print("%s %s %s" % (v.get("state"), ((v.get("effective") or {}).get("authToken") or {}).get("fp"), ",".join(sorted(x.get("key", "?") for x in (v.get("drift") or []))) or "-"))' 2>/dev/null || echo "? ? ?"
}
reconocer() { # <proveedor> <nota> → 0 si quedó reconocida (201)
  local intento huella
  for intento in 1 2 3; do
    api b GET admin/ultron-studio/integrations/config
    huella=$(printf '%s' "$RESP" | python3 -I -c 'import json, sys; d = json.load(sys.stdin)["body"]; d = d.get("data", d); ps = d.get("providers") if isinstance(d, dict) else None; v = next((p for p in (ps or []) if isinstance(p, dict) and p.get("provider") == sys.argv[1]), None) if isinstance(ps, list) else None; print((v or {}).get("effectiveFingerprint") or "")' "$1" 2>/dev/null || true)
    [ -n "$huella" ] || return 1
    api b POST admin/ultron-studio/integrations/config/acknowledge "{\"clientRequestId\":\"$(uuid)\",\"provider\":\"$1\",\"fingerprint\":\"$huella\",\"note\":\"$2\"}"
    [ "$(jc http)" = 201 ] && return 0
    [ "$(jc body.code)" = config_changed ] || return 1
    sleep 1.5
  done
  return 1
}
ctl_tw() { curl -s --max-time 10 "http://127.0.0.1:$PUERTO_TW$1"; }
# /__maestro?secundario=<token>, con el token por la entrada estándar (nunca en argumentos).
secundario() { python3 -I -c 'import sys, urllib.request; t = sys.stdin.read().strip(); op = urllib.request.build_opener(urllib.request.ProxyHandler({})); print(op.open(sys.argv[1] + "/__maestro?secundario=" + t, timeout=10).read().decode())' "http://127.0.0.1:$PUERTO_TW"; }
aviso_otp() { ctl_tw '/__emit/usage-trigger?variant=valid&destino=otp' | python3 -I -c 'import json, sys; print(json.load(sys.stdin)["deliveries"][0]["status"])' 2>/dev/null || echo '?'; }
violaciones() { ctl_tw '/__stats' | python3 -I -c 'import json, sys; d = json.load(sys.stdin); print(d.get("violations"), d.get("sondasMaestra", 0))' 2>/dev/null || echo '? ?'; }
huella_fichero() { shasum -a 256 "$ENVB" | cut -c1-16; }
pieza() { python3 -I -c 'import sys; print(next((l.split("=", 1)[1].strip() for l in open(sys.argv[1]) if l.startswith(sys.argv[2] + "=")), ""))' "$B/sim/piezas.conf" "$1"; }
FT="$B/root/otp-token-nuevo"
pon_token() { rm -f "$FT"; ( umask 077; cat > "$FT" ); chmod 600 "$FT"; }   # el contenido, por la entrada estándar
nuevo_token() { python3 -I -c 'import secrets; print(secrets.token_hex(16))'; }
# Reescribe el fichero de entorno con un cambio de prueba (mismo inodo y modo; los valores nunca salen):
#   dup CLAVE (repite su línea) · valor CLAVE (le pone un valor aleatorio de 32 hex) · app (cambia APP_NAME)
toca_env() { python3 -I - "$ENVB" "$@" <<'PY'
import re, secrets, sys
p, que = sys.argv[1], sys.argv[2]
s = open(p, encoding='utf-8').read()
if que == 'dup':
    m = re.search(r'(?m)^[ \t]*(?:export[ \t]+)?' + sys.argv[3] + r'[ \t]*=.*$', s)
    s = s + ('' if s.endswith('\n') else '\n') + m.group(0) + '\n'
elif que == 'valor':
    s, n = re.subn(r'(?m)^([ \t]*(?:export[ \t]+)?' + sys.argv[3] + r'[ \t]*=).*$', lambda m: m.group(1) + secrets.token_hex(16), s, count=1)
    assert n == 1
elif que == 'app':
    s, n = re.subn(r'(?m)^([ \t]*(?:export[ \t]+)?APP_NAME[ \t]*=).*$', lambda m: m.group(1) + '"Iron Body ensayo OPS8-e"', s, count=1)
    if n == 0:
        s = s + '  APP_NAME="Iron Body ensayo OPS8-e"\n'
with open(p, 'r+', encoding='utf-8') as f:
    f.seek(0); f.write(s); f.truncate()
PY
}
# Restaura el contenido del fichero de entorno desde la copia del principio (mismo inodo y modo).
restaura_env() { python3 -I -c 'import sys; s = open(sys.argv[1], encoding="utf-8").read(); f = open(sys.argv[2], "r+", encoding="utf-8"); f.seek(0); f.write(s); f.truncate(); f.close()' "$INICIAL" "$ENVB"; }
# Las líneas del token en el fichero de entorno frente al token esperado (por la entrada estándar), sin imprimir valores.
lineas_token() { python3 -I -c '
import re, sys
t = sys.stdin.read().strip()
s = open(sys.argv[1], encoding="utf-8").read().splitlines()
r = []
for k in ("TWILIO_TOKEN", "TWILIO_AUTH_TOKEN"):
    ls = [m for l in s for m in [re.match(r"^([ \t]*)(export[ \t]+)?" + k + r"[ \t]*=[ \t]*(.*?)[ \t]*$", l)] if m]
    r.append("%s=%s,sangría %d,%s" % (k, "nuevo" if len(ls) == 1 and ls[0].group(3) == t else "OTRO", len(ls[0].group(1)) if ls else -1, "export" if ls and ls[0].group(2) else "sin export"))
print(" ".join(r))' "$ENVB"; }
# El fichero de entorno frente a la copia del principio: «solo las líneas del token» o lo que difiera.
solo_lineas_token() { python3 -I -c '
import re, sys
pat = re.compile(r"^[ \t]*(?:export[ \t]+)?(TWILIO_TOKEN|TWILIO_AUTH_TOKEN)[ \t]*=")
a = open(sys.argv[1], encoding="utf-8").read().splitlines(); b = open(sys.argv[2], encoding="utf-8").read().splitlines()
if len(a) != len(b):
    print("otro número de líneas"); sys.exit()
d = [i + 1 for i, (x, y) in enumerate(zip(a, b)) if x != y]
print("solo las líneas del token (%s)" % ",".join(map(str, d)) if d and all(pat.match(a[i - 1]) and pat.match(b[i - 1]) for i in d) else ("ninguna" if not d else "OTRAS líneas: %s" % ",".join(map(str, d))))' "$INICIAL" "$ENVB"; }
# «7. … ERROR en laravel*.log: N → M» con N = M (sin retrorreferencias: el grep del Mac puede ser ugrep).
errores_iguales() { sed -n 's/^7\. .*: \([0-9]*\) → \([0-9]*\)$/\1 \2/p' <<< "$SALIDA" | awk 'NF == 2 && $1 == $2 { print "si" }'; }
copia_de() { sed -n 's/^MANDO otp-token-[a-z]* APLICADO (copia previa: \(.*\))$/\1/p' <<< "$SALIDA"; }
fp_de() { sed -n 's/.*la huella del token tiene que ser \([0-9a-f]\{8\}\).*/\1/p' <<< "$SALIDA" | head -1; }

# ── 0. Punto de partida ───────────────────────────────────────────────────────────────────────────────────────────────
echo "== ensayo de la rotación del token del OTP · banco $B · registro $LOG"
chmod 600 "$ENVB"
caso "0 · el OTP del banco es la cuenta maestra del simulador (cuenta y token, comparados sin imprimirlos)" "$(python3 -I - "$ENVB" "$B/sim/piezas.conf" <<'PY'
import re, sys
env = {}
for l in open(sys.argv[1], encoding='utf-8'):
    m = re.match(r'^[ \t]*(?:export[ \t]+)?([A-Z_]+)[ \t]*=[ \t]*(.*?)\s*$', l)
    if m: env[m.group(1)] = m.group(2).strip('"')
p = dict(l.strip().split('=', 1) for l in open(sys.argv[2], encoding='utf-8') if '=' in l and not l.startswith('#'))
ok = env.get('TWILIO_SID') == p.get('TW_MASTER_SID') == env.get('TWILIO_ACCOUNT_SID') and env.get('TWILIO_TOKEN') == p.get('TW_MASTER_TOKEN') == env.get('TWILIO_AUTH_TOKEN')
print(0 if ok else 1)
PY
)"
M=$(ctl_tw '/__maestro')
caso "0 · el simulador: el token original es el principal y no hay secundario" "$([ "$(jc principalOriginal "$M")" = true ] && [ "$(jc secundario "$M")" = false ] && echo 0 || echo 1)" "$M"
read -r V0 S0 <<< "$(violaciones)"
R=$(php_real "$AYUDANTE" "$BE" cuentas "$TOK" 2>> "$LOG") || muere "no se pudieron crear las cuentas (ver $LOG)"
caso "0 · cuentas con sesión para leer la vista web (tokens en 600)" "$([ -n "$(jc accounts.b "$R")" ] && echo 0 || echo 1)"
INICIAL="$B/root/env-inicial-ensayo-otp-$(date -u +%Y%m%d-%H%M%S)"
( umask 077; cp -p "$ENVB" "$INICIAL" ); chmod 600 "$INICIAL"
H0=$(huella_fichero)
paso "0 · estado (solo lectura): las claves con sangría 2, caché = fichero, GET 200 con el token efectivo, workers 13 0" 0 'la caché y el fichero: iguales' "${E[@]}" otp estado
caso "0 · estado: TWILIO_TOKEN y TWILIO_AUTH_TOKEN encontrados con su sangría (lo que «grep ^TWILIO_» no ve)" "$(hay 'TWILIO_TOKEN: línea [0-9]+, sangría 2' && hay 'TWILIO_AUTH_TOKEN: línea [0-9]+, sangría 2' && hay 'EFECTIVO: 200 cuenta=si estado=active' && echo 0 || echo 1)"
EST0=$(studio_twilio)
anota "Studio · Twilio al empezar (vista web)" "$EST0"
caso "0 · el aviso de gasto del OTP, firmado como Twilio con el principal → 200" "$([ "$(aviso_otp)" = 200 ] && echo 0 || echo 1)"

# ── 1. Negativos: ninguno toca nada ───────────────────────────────────────────────────────────────────────────────────
T=$(nuevo_token); ( umask 077; printf '%s\n' "$T" > "$TOK/otp-nuevo-0" )   # también entra en el barrido
paso "1 · el token por argumento → 2" 2 'NUNCA por argumentos' "${E[@]}" otp rotar "$T"
printf '%s\n' "$T" | pon_token; chmod 644 "$FT"
paso "1 · el fichero del token en 644 → 2" 2 'tiene que ser de .* y 600' "${E[@]}" otp rotar "$FT"
printf '%s\n' "${T:0:31}" | pon_token
paso "1 · 31 hex → 2 (forma)" 2 'no tiene la forma de un Auth Token' "${E[@]}" otp rotar "$FT"
printf '%s\n' "$T" | tr 'a-f' 'A-F' | pon_token
paso "1 · en mayúsculas → 2 (forma)" 2 'no tiene la forma de un Auth Token' "${E[@]}" otp rotar "$FT"
printf '%s\n%s\n' "$T" "$T" | pon_token
paso "1 · dos líneas → 2" 2 'UNA línea' "${E[@]}" otp rotar "$FT"
printf 'TWILIO_AUTH_TOKEN=%s\n' "$T" | pon_token
paso "1 · un token que Twilio no conoce (TWILIO_AUTH_TOKEN=…, sin secundario) → 2 (401)" 2 'Twilio rechaza el token nuevo \(401\)' "${E[@]}" otp rotar "$FT"
chmod 644 "$ENVB"
paso "1 · el fichero de entorno en 644 → 2 (C-14)" 2 'C-14' "${E[@]}" otp rotar "$FT"
chmod 600 "$ENVB"
toca_env dup TWILIO_TOKEN
paso "1 · TWILIO_TOKEN repetida en el fichero de entorno → 2" 2 'TWILIO_TOKEN aparece 2 veces' "${E[@]}" otp rotar "$FT"
restaura_env; toca_env app
paso "1 · un cambio sin cachear en otro bloque (APP_NAME) → 2: config:cache lo publicaría" 2 'difieren en: app' "${E[@]}" otp rotar "$FT"
restaura_env; toca_env valor TWILIO_TOKEN
paso "1 · el token del fichero no es el de la caché → 2" 2 'NO es el que tiene la caché' "${E[@]}" otp rotar "$FT"
restaura_env
paso "1 · vuelta con un fichero que no es una copia de este mando → 2" 2 'lee una copia previa de este mando' "${E[@]}" otp vuelta "$ENVB"
pieza TW_MASTER_TOKEN | pon_token
paso "1 · el token de ahora → 0, nada que escribir" 0 'nada que escribir' "${E[@]}" otp rotar "$FT"
caso "1 · …y el fichero del token, destruido" "$([ ! -e "$FT" ] && echo 0 || echo 1)"
caso "1 · tras los negativos, el fichero de entorno es byte a byte el del principio" "$([ "$(huella_fichero)" = "$H0" ] && echo 0 || echo 1)"

# ── 2. Ciclo A: rotar y volver atrás ANTES de promocionar ─────────────────────────────────────────────────────────────
N1=$(nuevo_token); ( umask 077; printf '%s\n' "$N1" > "$TOK/otp-nuevo-1" )
M=$(printf '%s' "$N1" | secundario)
caso "2 · consola (simulada): el token nuevo como secundario" "$([ "$(jc secundario "$M")" = true ] && echo 0 || echo 1)"
printf '%s\n' "$N1" | pon_token
paso "2 · rotar --solo-comprobar → 0 EN VERDE, nada escrito" 0 'COMPROBACIÓN EN VERDE' "${E[@]}" otp rotar "$FT" --solo-comprobar
caso "2 · …GET 200 con el NUEVO y con el ACTUAL; el fichero de entorno intacto y el del token en disco" "$(hay 'token NUEVO: 200 cuenta=si estado=active' && hay 'token ACTUAL: 200 cuenta=si' && [ "$(huella_fichero)" = "$H0" ] && [ -f "$FT" ] && echo 0 || echo 1)"
paso "2 · rotar → APLICADO" 0 'MANDO otp-token-rotar APLICADO' "${E[@]}" otp rotar "$FT"
COPIA_A=$(copia_de); FP_A=$(fp_de)
caso "2 · …las dos claves, el resto de la configuración igual, caché = fichero, GET 200 con el efectivo, workers, 401, 0 ERROR" "$(hay 'TWILIO_TOKEN sustituida' && hay 'TWILIO_AUTH_TOKEN sustituida' && hay 'el resto de la configuración, igual' && hay 'la caché y el fichero: iguales' && hay '^4\. Twilio.*: 200 cuenta=si estado=active' && hay '^5\. workers.*\[13 0\] y \[13 0\]' && hay '^6\. .* 401' && [ "$(errores_iguales)" = si ] && echo 0 || echo 1)"
caso "2 · …el fichero del token destruido y la copia previa 600 byte a byte el fichero de antes" "$([ ! -e "$FT" ] && [ -n "$COPIA_A" ] && cmp -s "$COPIA_A" "$INICIAL" && [ "$(/usr/bin/stat -f '%Lp' "$COPIA_A")" = 600 ] && echo 0 || echo 1)" "$(basename "${COPIA_A:-?}")"
L=$(printf '%s' "$N1" | lineas_token)
caso "2 · el fichero: solo cambian las dos líneas, con el token nuevo, sangría 2 y sin export" "$([ "$(solo_lineas_token)" != ninguna ] && [[ "$(solo_lineas_token)" == "solo las líneas del token"* ]] && [ "$L" = "TWILIO_TOKEN=nuevo,sangría 2,sin export TWILIO_AUTH_TOKEN=nuevo,sangría 2,sin export" ] && echo 0 || echo 1)" "$(solo_lineas_token) · $L"
EST=$(studio_twilio)
caso "2 · vista web (la API del banco): la huella de Twilio del Studio es la que dijo el mando" "$([ -n "$FP_A" ] && [ "$(cut -d' ' -f2 <<< "$EST")" = "$FP_A" ] && [ "$(cut -d' ' -f2 <<< "$EST0")" != "$FP_A" ] && echo 0 || echo 1)" "$EST0 → $EST (mando: $FP_A)"
caso "2 · ventana: sin promocionar, Twilio firma con el principal viejo → el aviso de gasto del OTP da 403" "$([ "$(aviso_otp)" = 403 ] && echo 0 || echo 1)"
paso "2 · vuelta --solo-comprobar → 0 EN VERDE (el token viejo sigue vivo)" 0 'COMPROBACIÓN EN VERDE' "${E[@]}" otp vuelta "$COPIA_A" --solo-comprobar
paso "2 · vuelta → APLICADO" 0 'MANDO otp-token-vuelta APLICADO' "${E[@]}" otp vuelta "$COPIA_A"
caso "2 · tras la vuelta: el fichero de entorno byte a byte el del principio, la copia intacta y el aviso otra vez 200" "$([ "$(huella_fichero)" = "$H0" ] && cmp -s "$COPIA_A" "$INICIAL" && [ "$(aviso_otp)" = 200 ] && echo 0 || echo 1)"
ctl_tw '/__maestro?secundario=borrar' > /dev/null

# ── 3. Ciclo B: con Twilio reconocida; rotar, promocionar y la vuelta atrás que ya no existe ──────────────────────────
RC=0; reconocer twilio "ensayo OPS8-e: Twilio reconocida antes de rotar" || RC=$?
EST=$(studio_twilio)
caso "3 · Twilio reconocida en el Studio (201) → authorized" "$([ "$RC" = 0 ] && [ "${EST%% *}" = authorized ] && echo 0 || echo 1)" "$EST"
N2=$(nuevo_token); ( umask 077; printf '%s\n' "$N2" > "$TOK/otp-nuevo-2" )
printf '%s' "$N2" | secundario > /dev/null
printf '%s\n' "$N2" | pon_token
paso "3 · rotar → APLICADO" 0 'MANDO otp-token-rotar APLICADO' "${E[@]}" otp rotar "$FT"
COPIA_B=$(copia_de); FP_B=$(fp_de)
caso "3 · …el Studio pasa de authorized a drift en authToken y el mando pide reconocer" "$(hay 'Studio · Twilio: authorized .* → drift \(deriva: authToken' && hay 'reconocer otra vez la configuración de Twilio' && echo 0 || echo 1)"
EST=$(studio_twilio)
caso "3 · vista web: drift en authToken con la huella nueva" "$([ "$EST" = "drift $FP_B authToken" ] && echo 0 || echo 1)" "$EST"
H_B=$(huella_fichero)
P0=$(jc promociones "$(ctl_tw '/__maestro')")   # el contador del simulador no se pone a cero entre ensayos
M=$(ctl_tw '/__maestro?promocionar=1')
caso "3 · consola (simulada): promocionar → el original ya no es el principal" "$([ "$(jc promociones "$M")" = "$((P0 + 1))" ] && [ "$(jc principalOriginal "$M")" = false ] && [ "$(jc secundario "$M")" = false ] && echo 0 || echo 1)" "$M"
caso "3 · promocionado: el aviso de gasto del OTP, firmado ya con el nuevo, da 200" "$([ "$(aviso_otp)" = 200 ] && echo 0 || echo 1)"
paso "3 · vuelta --solo-comprobar tras promocionar → 2 (401): confirma la promoción" 2 'ya no acepta el token de la copia \(401\)' "${E[@]}" otp vuelta "$COPIA_B" --solo-comprobar
paso "3 · vuelta tras promocionar → 2, sin tocar nada" 2 'ya no acepta el token de la copia \(401\)' "${E[@]}" otp vuelta "$COPIA_B"
caso "3 · …el fichero de entorno, el mismo que tras rotar" "$([ "$(huella_fichero)" = "$H_B" ] && echo 0 || echo 1)"
paso "3 · estado: GET 200 con el token efectivo y el Studio en drift" 0 'EFECTIVO: 200 cuenta=si estado=active' "${E[@]}" otp estado
caso "3 · …estado enseña la deriva" "$(hay 'Studio · Twilio: drift \(deriva: authToken' && echo 0 || echo 1)"

# ── 4. El banco como estaba ───────────────────────────────────────────────────────────────────────────────────────────
pieza TW_MASTER_TOKEN | secundario > /dev/null
M=$(ctl_tw '/__maestro?promocionar=1')
caso "4 · simulador: el token original otra vez principal" "$([ "$(jc principalOriginal "$M")" = true ] && echo 0 || echo 1)" "$M"
paso "4 · vuelta con la copia de la rotación B (el token original) → APLICADO" 0 'MANDO otp-token-vuelta APLICADO' "${E[@]}" otp vuelta "$COPIA_B"
EST=$(studio_twilio)
caso "4 · el fichero de entorno byte a byte el del principio y el Studio otra vez authorized" "$([ "$(huella_fichero)" = "$H0" ] && [ "${EST%% *}" = authorized ] && echo 0 || echo 1)" "$EST"
caso "4 · el aviso de gasto del OTP, 200" "$([ "$(aviso_otp)" = 200 ] && echo 0 || echo 1)"
read -r V1 S1 <<< "$(violaciones)"
caso "4 · el simulador: 0 violaciones nuevas (las sondas de la cuenta maestra no lo son)" "$([ "$V1" = "$V0" ] && echo 0 || echo 1)" "violaciones $V0 → $V1 · sondas $S0 → $S1"
rm -f "$FT"

# ── 5. Cierre: ningún token en el registro, en los de Laravel ni en el del simulador ──────────────────────────────────
BARRIDO=$(python3 -I - "$B/sim/piezas.conf" "$TOK" "$LOG" "$B/logs/sim-twilio.jsonl" "$B/logs/valla.jsonl" "$BE"/storage/logs/*.log <<'PY'
import os, sys
piezas, tokens = sys.argv[1:3]
v = {l.split('=', 1)[1].strip() for l in open(piezas, encoding='utf-8') if l.startswith('TW_MASTER_TOKEN=')}
v |= {open(os.path.join(tokens, f), encoding='utf-8').read().strip() for f in os.listdir(tokens) if f.startswith('otp-nuevo-')}
malos = [os.path.basename(f) for f in sys.argv[3:] if os.path.isfile(f) and any(x and x in open(f, encoding='utf-8', errors='replace').read() for x in v)]
print('limpio (%d ficheros, %d tokens buscados)' % (len([f for f in sys.argv[3:] if os.path.isfile(f)]), len(v)) if not malos else 'CON TOKENS: ' + ', '.join(malos))
PY
)
caso "5 · el registro del ensayo, los de Laravel, el del simulador y el de la valla sin ningún token" "$([[ "$BARRIDO" == limpio* ]] && echo 0 || echo 1)" "$BARRIDO"
chmod 600 "$LOG"
echo "$((CASOS - FALLOS)) OK · $FALLOS FALLO · registro $LOG"
[ "$FALLOS" = 0 ]

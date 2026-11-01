#!/bin/bash
# Prueba sin servidor de las funciones del fichero de entorno, EXTRAÍDAS TAL CUAL de los scripts del repo:
# estado_var/presencia/cuenta_var/valor_var de deploy-h6-backend-fase1.sh y set_var de h6-integrations-scope.sh.
# Casos: sangría, `export`, `K = "v"`, comillas, repetidas, prefijos parecidos, comentarios, línea vacía, sin salto final;
# set_var conserva sangría, inodo y modo y no deja temporales. Uso: bash prueba-entorno.sh   (código 0 = todo OK)
. "$(dirname "$0")/comun.sh"
T=$(mktemp -d "${TMPDIR:-/tmp}/banco-h6-entorno.XXXXXX")
trap 'rm -rf -- "$T"' EXIT
python3 - "$OPS" "$T" <<'PY'
import re, sys
ops, out = sys.argv[1], sys.argv[2]
def fn(src, name):
    m = re.search(r'^' + name + r'\(\) \{.*?^\}\n', src, re.S | re.M) or re.search(r'^' + name + r'\(\) \{[^\n]*\}\n', src, re.M)
    if not m:
        sys.exit(f'no encuentro {name}()')
    return m.group(0)
d = open(f'{ops}/deploy-h6-backend-fase1.sh', encoding='utf-8').read()
s = open(f'{ops}/h6-integrations-scope.sh', encoding='utf-8').read()
open(f'{out}/deploy-fns.sh', 'w').write(''.join(fn(d, n) for n in ('cuenta_var', 'valor_var', 'estado_var', 'presencia')))
open(f'{out}/scope-fns.sh', 'w').write(''.join(fn(s, n) for n in ('cuenta_var', 'set_var')).replace('mktemp /root/.h6-scope-env.XXXXXX', f'mktemp {out}/.h6-scope-env.XXXXXX'))
PY
ENVF="$T/test.env"
printf '%s\n' 'APP_ENV=production' '  ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED=false' 'export ULTRON_STUDIO_TWILIO_PROBE_ENABLED = "false"' \
  'ULTRON_STUDIO_TWILIO_PROBE_ENABLED_X=true' '# ULTRON_STUDIO_CANARY_MAX_ACTIVE=9' '' 'ULTRON_STUDIO_CANARY_COUNTRY_CODES="57"' \
  'ULTRON_STUDIO_TWILIO_PROBE_KEY_SID=' 'TWILIO_SID=ACx' 'TWILIO_ACCOUNT_SID=ACx' 'DUP=1' 'DUP=2' > "$ENVF"
printf 'ULTRON_STUDIO_CAMPAIGN_LINKS_MAX_ACTIVE=60' >> "$ENVF"     # sin salto final
chmod 640 "$ENVF"
# shellcheck disable=SC1091
. "$T/deploy-fns.sh"
ok=0; ko=0
t() { if [ "$2" = "$3" ]; then ok=$((ok + 1)); echo "OK    $1 → $2"; else ko=$((ko + 1)); echo "FALLO $1 → $2 (esperado $3)"; fi; }
t 'estado con sangría'                          "$(estado_var ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED false)" igual
t 'estado con export, espacios y comillas'      "$(estado_var ULTRON_STUDIO_TWILIO_PROBE_ENABLED false)" igual
t 'estado con otro valor'                       "$(estado_var ULTRON_STUDIO_TWILIO_PROBE_ENABLED true)" distinto
t 'estado de una clave comentada → falta'       "$(estado_var ULTRON_STUDIO_CANARY_MAX_ACTIVE 3)" falta
t 'estado con comillas "57"'                    "$(estado_var ULTRON_STUDIO_CANARY_COUNTRY_CODES 57)" igual
t 'estado de la última línea sin salto final'   "$(estado_var ULTRON_STUDIO_CAMPAIGN_LINKS_MAX_ACTIVE 50)" distinto
t 'estado de una repetida'                      "$(estado_var DUP 1)" repetida
t 'presencia vacía'                             "$(presencia ULTRON_STUDIO_TWILIO_PROBE_KEY_SID)" vacia
t 'presencia que falta'                         "$(presencia ULTRON_STUDIO_TWILIO_PROBE_KEY_SECRET)" falta
t 'presencia con valor'                         "$(presencia TWILIO_SID)" presente
t 'presencia repetida'                          "$(presencia DUP)" repetida
t 'un prefijo parecido no cuenta'               "$(cuenta_var ULTRON_STUDIO_TWILIO_PROBE_ENABLED)" 1
# shellcheck disable=SC1091
. "$T/scope-fns.sh"
if stat -c '%a' "$ENVF" > /dev/null 2>&1; then modo() { stat -c '%a' "$1"; }; else modo() { /usr/bin/stat -f '%Lp' "$1"; }; fi   # GNU o BSD
INO0=$(ls -i "$ENVF" | awk '{print $1}'); M0=$(modo "$ENVF")
set_var ULTRON_STUDIO_TWILIO_PROBE_ENABLED true
set_var ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED true
set_var NUEVA_CLAVE false
t 'set_var normaliza export y espacios'          "$(grep -n 'ULTRON_STUDIO_TWILIO_PROBE_ENABLED' "$ENVF" | head -1)" '3:ULTRON_STUDIO_TWILIO_PROBE_ENABLED=true'
t 'set_var no toca el prefijo parecido'          "$(grep -c '^ULTRON_STUDIO_TWILIO_PROBE_ENABLED_X=true$' "$ENVF")" 1
t 'set_var conserva la sangría'                  "$(grep -n 'WRITES_ENABLED' "$ENVF")" '2:  ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED=true'
t 'set_var añade con el salto que faltaba'       "$(tail -2 "$ENVF" | tr '\n' '|')" 'ULTRON_STUDIO_CAMPAIGN_LINKS_MAX_ACTIVE=60|  NUEVA_CLAVE=false|'
t 'set_var conserva el inodo'                    "$(ls -i "$ENVF" | awk '{print $1}')" "$INO0"
t 'set_var conserva el modo'                     "$(modo "$ENVF")" "$M0"
t 'set_var no deja temporales'                   "$(ls -A "$T" | grep -c '^\.h6-scope-env' || true)" 0
t 'el resto intacto (comentario, alias, DUP)'     "$(grep -cE '^(# ULTRON_STUDIO_CANARY_MAX_ACTIVE=9|TWILIO_SID=ACx|TWILIO_ACCOUNT_SID=ACx|DUP=1|DUP=2|APP_ENV=production)$' "$ENVF")" 6
t 'la línea vacía sigue'                         "$(grep -c '^$' "$ENVF" || true)" 1
t 'estado tras set_var'                          "$(estado_var ULTRON_STUDIO_TWILIO_PROBE_ENABLED true)" igual
if [ -f "$WT/backend/vendor/autoload.php" ]; then
  t 'el fichero resultante parsea con phpdotenv'  "$(php -r 'require $argv[1]; try { Dotenv\Dotenv::createArrayBacked($argv[2], "test.env")->load(); echo "parsea"; } catch (Throwable $e) { echo get_class($e); }' "$WT/backend/vendor/autoload.php" "$T")" parsea
fi
echo "$ok OK · $ko FALLO"
[ "$ko" = 0 ]

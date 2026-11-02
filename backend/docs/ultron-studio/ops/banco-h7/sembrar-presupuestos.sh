#!/bin/bash
# Estado del banco para medir TODAS las rutas de los presupuestos de smoke-h7.py (las 3 que sin él salen OMITIDO):
#   · una ejecución del laboratorio de verdad, con el conductor fake (sin red y coste 0), lanzada por la API como la
#     lanza el CRM (estimar y lanzar, 2 escenarios contra la línea base) y terminada con StudioLabRunner;
#   · una versión en CANARY con su entrada firmada de la cadena (sembrar-presupuestos.php: estado de juguete).
# Escribe en el Studio del banco: se ejecuta ANTES del smoke, nunca durante. Dos personas propias del banco (autora y
# revisora; la autora entra por la API con una contraseña aleatoria que no se imprime) y al final la autora vuelve a
# quedar inactiva y sin sesiones. Una vez por banco desplegado.
# Uso: bash sembrar-presupuestos.sh <banco>
. "$(dirname "$0")/comun.sh"
B=$(banco_dir "${1:-}")
cargar_banco "$B"
APPD="$B/api/backend"; API="http://127.0.0.1:$BANCO_PORT/api"
Q() { psql -X -Atq -v ON_ERROR_STOP=1 -h "$PG_HOST" -p "$PG_PORT" -U "$PG_USER" -d "$BANCO_DB" -c "$1"; }
[ "$(Q "SELECT count(*) FROM ultron_studio_versions WHERE label = 'Banco H7: versión en canario (presupuestos)';")" = 0 ] \
  || muere "este banco ya está sembrado para los presupuestos"

PW=$(openssl rand -hex 16); HASH=$(PW="$PW" php -r 'echo password_hash(getenv("PW"), PASSWORD_BCRYPT);')
AUT=$(Q "INSERT INTO admins (uuid, name, email, password, role, status, created_at, updated_at) VALUES (gen_random_uuid(), 'Autora banco', 'autora-presupuestos-h7@local', '$HASH', 'Super Admin', 'active', now(), now()) ON CONFLICT (email) DO UPDATE SET password = EXCLUDED.password, status = 'active' RETURNING id;" | head -1)
REV=$(Q "INSERT INTO admins (uuid, name, email, password, role, status, created_at, updated_at) VALUES (gen_random_uuid(), 'Revisora banco', 'revisora-presupuestos-h7@local', '!', 'Super Admin', 'inactive', now(), now()) ON CONFLICT (email) DO UPDATE SET status = 'inactive' RETURNING id;" | head -1)
unset HASH
echo "== personas del banco: autora $AUT · revisora $REV"

( cd "$APPD" && php artisan ultron-studio:lab-import-scenarios -q ) || muere "no se pudieron importar los escenarios del laboratorio"
echo "== escenarios del laboratorio: $(Q "SELECT count(*) FROM ultron_studio_lab_scenarios;")"

RUN=$(PW="$PW" python3 -I - "$API" <<'PY'
import json, os, sys, urllib.error, urllib.request, uuid
api = sys.argv[1]
def llama(metodo, ruta, cuerpo=None, token=None):
    datos = json.dumps(cuerpo).encode() if cuerpo is not None else None
    req = urllib.request.Request(api + ruta, data=datos, method=metodo)
    req.add_header('Accept', 'application/json')
    if datos is not None:
        req.add_header('Content-Type', 'application/json')
    if token:
        req.add_header('Authorization', 'Bearer ' + token)
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return r.status, json.loads(r.read() or b'null')
    except urllib.error.HTTPError as e:
        return e.code, json.loads(e.read() or b'null')
st, b = llama('POST', '/admin/auth/login', {'email': 'autora-presupuestos-h7@local', 'password': os.environ['PW']})
token = b.get('token') if isinstance(b, dict) else None
if st != 200 or not token:
    sys.exit(f'login de la autora: HTTP {st}')
st, b = llama('GET', '/admin/ultron-studio/lab/scenarios', token=token)
d = (b or {}).get('data')
lista = d.get('data') if isinstance(d, dict) else d
uuids = [x['uuid'] for x in (lista or []) if isinstance(x, dict) and x.get('uuid') and x.get('status', 'active') == 'active'][:2]
if st != 200 or len(uuids) < 2:
    sys.exit(f'escenarios: HTTP {st}, {len(uuids)} activos')
objetivo = {'kind': 'baseline'}
st, b = llama('POST', '/admin/ultron-studio/lab/estimate', {'target': objetivo, 'scenarioUuids': uuids}, token)
maximo = (((b or {}).get('data') or {}).get('estimate') or {}).get('maxMicros')
if st != 200 or not isinstance(maximo, int):
    sys.exit(f'estimación: HTTP {st} {(b or {}).get("code")}')
st, b = llama('POST', '/admin/ultron-studio/lab/runs', {'target': objetivo, 'scenarioUuids': uuids, 'acceptedMaxCostMicros': maximo, 'clientRequestId': str(uuid.uuid4())}, token)
run = ((b or {}).get('data') or {}).get('uuid')
if st not in (200, 201) or not run:
    sys.exit(f'lanzar: HTTP {st} {(b or {}).get("code")}')
print(run)
PY
) || muere "no se pudo lanzar la ejecución del laboratorio"
unset PW
echo "== ejecución del laboratorio lanzada por la API: $(php "$AQUI/sembrar-presupuestos.php" "$APPD" drain "$RUN")"
echo "== versión en canario y su entrada firmada: $(php "$AQUI/sembrar-presupuestos.php" "$APPD" canary "$AUT" "$REV")"
Q "UPDATE admins SET status = 'inactive' WHERE id = $AUT;" > /dev/null
Q "DELETE FROM admin_sessions WHERE admin_id = $AUT;" > /dev/null
RC=0; ( cd "$APPD" && php artisan ultron-studio:publication-check > /dev/null 2>&1 ) || RC=$?
echo "== publication-check sobre la cadena sembrada: $RC (0 = la firma y la cadena cuadran) · autora inactiva y sin sesiones"
echo "== tablas: versiones $(Q "SELECT count(*) FROM ultron_studio_versions;") · cadena $(Q "SELECT count(*) FROM ultron_studio_publications;") · ejecuciones $(Q "SELECT count(*) FROM ultron_studio_lab_runs;") · casos $(Q "SELECT count(*) FROM ultron_studio_lab_run_cases;")"
[ "$RC" = 0 ]

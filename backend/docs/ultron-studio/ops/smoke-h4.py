#!/usr/bin/env python3
"""Smoke del Hito 4 (laboratorio de IA) en producción, fase 1 (bandera apagada) o fase 2 (fake).
Política: SIN filas positivas: no crea escenarios, ejecuciones ni veredictos. Solo lecturas, 409 con la
bandera apagada, 403 sin llave, 422/409 sin fila y 401 sin token.
Uso: smoke-h4.py <email super admin> [<password>|"" con SMOKE_PW en el entorno] [<email cuenta sin llave> <password>]
Variables: SMOKE_API (por defecto producción), SMOKE_LAB_EXPECT_ENABLED=0|1 (por defecto 0)."""
import json, os, sys, urllib.request, urllib.error

API = os.environ.get('SMOKE_API', 'https://api.ironbodyneiva.cloud/api')
EXPECT_ENABLED = os.environ.get('SMOKE_LAB_EXPECT_ENABLED', '0') == '1'
email = sys.argv[1]
password = sys.argv[2] if len(sys.argv) >= 3 and sys.argv[2] else os.environ.get('SMOKE_PW', '')  # preferible por entorno
email2, password2 = (sys.argv[3], sys.argv[4]) if len(sys.argv) >= 5 else (None, None)
results = []

def check(name, ok, detail=''):
    results.append(bool(ok))
    print(('PASS ' if ok else 'FAIL ') + name + (f'  [{detail}]' if detail else ''))

def call(method, path, body=None, token=None):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(API + path, data=data, method=method)
    req.add_header('Accept', 'application/json')
    if data is not None:
        req.add_header('Content-Type', 'application/json')
    if token:
        req.add_header('Authorization', 'Bearer ' + token)
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            return r.status, json.loads(r.read().decode() or 'null'), dict(r.headers)
    except urllib.error.HTTPError as e:
        raw = e.read().decode()
        try:
            return e.code, json.loads(raw), dict(e.headers)
        except Exception:
            return e.code, {'raw': raw[:200]}, dict(e.headers)

def data_of(body):
    return (body or {}).get('data') if isinstance(body, dict) else None

def login(mail, pw):
    st, body, _ = call('POST', '/admin/auth/login', {'email': mail, 'password': pw})
    return st, (body or {}).get('token') or ((body or {}).get('data') or {}).get('token'), body

st, token, body = login(email, password)
check('login de la cuenta de smoke (Super Admin)', st == 200 and bool(token), f'{st}')
if not token:
    print(json.dumps(body)[:300]); sys.exit(1)
perms = ((body or {}).get('user') or ((body or {}).get('data') or {}).get('user') or {}).get('permissions') or []
check('la sesión trae ultron.lab_manage y ultron.lab_run', 'ultron.lab_manage' in perms and 'ultron.lab_run' in perms, str([p for p in perms if 'lab' in str(p)]))

S = '/admin/ultron-studio'
L = S + '/lab'
# F/G intactos
st, s, _ = call('GET', S + '/status', token=token); d = data_of(s) or {}
check('GET status (F/G): motor no lee versiones y publicar sigue bloqueado', st == 200 and d.get('engine', {}).get('readsVersions') is False and d.get('publishing', {}).get('available') is False, f'{st}')
check('status: catálogo 2026-10-19.1 y 12 reglas del código', d.get('catalog', {}).get('version') == '2026-10-19.1' and d.get('legacyBaseline', {}).get('rulesImported') == 12)

# lab/status: siempre 200 para quien tiene view
st, ls, _ = call('GET', L + '/status', token=token); lab = data_of(ls) or {}
check('GET lab/status → 200', st == 200, f'{st}')
check(f'lab/status: enabled = {EXPECT_ENABLED}', lab.get('enabled') is EXPECT_ENABLED, str(lab.get('enabled')))
can = lab.get('can') or {}
check('lab/status: can completo para Super Admin (view, manageScenarios, run, verdict = sí; refleja las llaves, no el estado)', all(can.get(k) is True for k in ['view', 'manageScenarios', 'run', 'verdict']), str(can))
blob = json.dumps(lab)
check('lab/status: sin clave, sin rutas del servidor ni variables de entorno', all(x not in blob for x in ['sk-', 'OPENAI', 'app/Services', 'api_key', 'API_KEY']))
if EXPECT_ENABLED:
    check('lab/status: modo fake sin coste (fase 2) y presupuesto diario 0', lab.get('mode') == 'fake' and (lab.get('budget') or {}).get('dailyCapMicros') == 0, f"{lab.get('mode')} {lab.get('budget')}")
    check('lab/status: catálogo de 11 escenarios importados (C-11 retirado, Enmienda 6)', (lab.get('catalog') or {}).get('scenariosImported') == 11, str(lab.get('catalog')))
    check('lab/status: etiqueta de fidelidad «No es ULTRON»', 'no es ultron' in str(lab.get('fidelity')).lower(), str(lab.get('fidelity'))[:80])

# Las 11 rutas restantes con la bandera apagada → 409 lab_disabled (sin dejar fila)
rutas = [
    ('GET', L + '/scenarios', None),
    ('GET', L + '/runs?page=1&per_page=5', None),
    ('GET', L + '/runs/00000000-0000-4000-8000-000000000001', None),
    ('POST', L + '/estimate', {'target': {'kind': 'baseline'}, 'scenarioUuids': ['00000000-0000-4000-8000-000000000001']}),
    ('GET', L + '/events?page=1&per_page=5', None),
    ('POST', L + '/scenarios', {'category': 'otro', 'title': 'Smoke (no debe crearse)', 'turns': [{'role': 'prospect', 'text': 'hola'}], 'facts': {}, 'criteria': [{'kind': 'must', 'text': 'responder', 'rules': []}], 'clientRequestId': '00000000-0000-4000-8000-00000000a0b1'}),
    ('PATCH', L + '/scenarios/00000000-0000-4000-8000-000000000001', {'baseRevision': 1, 'title': 'x'}),
    ('POST', L + '/scenarios/00000000-0000-4000-8000-000000000001/archive', {'reason': 'smoke sin efecto'}),
    ('POST', L + '/runs', {'target': {'kind': 'baseline'}, 'scenarioUuids': ['00000000-0000-4000-8000-000000000001'], 'acceptedMaxCostMicros': 0, 'clientRequestId': '00000000-0000-4000-8000-00000000a0b2'}),
    ('POST', L + '/runs/00000000-0000-4000-8000-000000000001/cancel', None),
    ('POST', L + '/runs/00000000-0000-4000-8000-000000000001/verdict', {'decision': 'rejected', 'note': 'smoke sin efecto', 'resultsHash': 'x'}),
]
if not EXPECT_ENABLED:
    for m, p, b in rutas:
        st, r, _ = call(m, p, b, token=token)
        check(f'apagado: {m} {p.replace(L, "lab")[:48]} → 409 lab_disabled', st == 409 and (r or {}).get('code') == 'lab_disabled', f'{st} {(r or {}).get("code")}')
else:
    st, sc, _ = call('GET', L + '/scenarios', token=token); scen = data_of(sc) or []
    check('fase 2: GET lab/scenarios → 11 del catálogo activos, solo lectura', st == 200 and len([x for x in scen if x.get('source') == 'catalog' and x.get('status') == 'active']) == 11, str(len(scen)))
    st, e, _ = call('POST', L + '/estimate', {'target': {'kind': 'baseline'}, 'scenarioUuids': [scen[0]['uuid']]}, token=token) if scen else (0, {}, {})
    est = data_of(e) or {}
    check('fase 2: estimación en fake → 200, coste 0, allowed (no crea nada)', st == 200 and est.get('mode') == 'fake' and (est.get('estimate') or {}).get('maxMicros') == 0 and est.get('allowed') is True, f'{st} {est.get("refusal")}')
    st, runs, _ = call('GET', L + '/runs?page=1&per_page=5', token=token)
    check('fase 2: GET lab/runs → paginador', st == 200 and isinstance((data_of(runs) or {}).get('total'), int), f'{st}')
    bad = {'category': 'otro', 'title': 'Smoke (no debe crearse)', 'turns': [{'role': 'prospect', 'text': 'mi correo es alguien@correo.com'}], 'facts': {}, 'criteria': [{'kind': 'must', 'text': 'responder', 'rules': []}], 'clientRequestId': '00000000-0000-4000-8000-00000000a0b3'}
    st, r, _ = call('POST', L + '/scenarios', bad, token=token)
    check('fase 2: POST lab/scenarios con un correo → 422 (filtro de datos personales) sin fila', st == 422 and any('turns' in k for k in ((r or {}).get('errors') or {})), f'{st} {sorted(((r or {}).get("errors") or {}).keys())[:3]}')
    st, sc2, _ = call('GET', L + '/scenarios', token=token)
    check('fase 2: siguen los mismos escenarios', len(data_of(sc2) or []) == len(scen))

# Cuenta sin llave (opcional): 403 en todo lab/* incluido status
if email2:
    st2, token2, _ = login(email2, password2)
    check('login de la cuenta sin llave ultron', st2 == 200 and bool(token2), f'{st2}')
    if token2:
        st, _, _ = call('GET', L + '/status', token=token2)
        check('sin ultron.view → 403 en lab/status', st == 403, f'{st}')
        st, _, _ = call('POST', L + '/runs', {'target': {'kind': 'baseline'}, 'scenarioUuids': [], 'acceptedMaxCostMicros': 0, 'clientRequestId': '00000000-0000-4000-8000-00000000a0b4'}, token=token2)
        check('sin llave → 403 en POST lab/runs', st == 403, f'{st}')
st, _, _ = call('GET', L + '/status')
check('sin token → 401', st == 401, f'{st}')
print('TOKEN=' + token)
print(f'RESUMEN: {sum(results)}/{len(results)} PASS')
sys.exit(0 if all(results) else 2)

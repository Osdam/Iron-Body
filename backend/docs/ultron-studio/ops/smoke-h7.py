#!/usr/bin/env python3
"""Smoke del Hito 7 de ULTRON STUDIO (seguridad, validación y producción), con el monitor apagado.

Comprueba, contra la API desplegada y SIN FILAS POSITIVAS:
  · `Cache-Control: no-store, private` en /api/admin/*: el login, lecturas autenticadas del Studio y de fuera del
    Studio, un 401, un 404 y un 413 (B7-a), y también cada GET de la fase de presupuestos;
  · el tope de 64 KB del Studio ANTES de la autenticación y de la validación: 70 000 bytes → 413 payload_too_large sin
    sesión y con sesión; 60 000 bytes sin sesión → 401 (el tope no muerde de más) (B7-d);
  · `verified` por entrada en `GET publications` y en `publications` del detalle de las versiones, todas true, y la de
    la cabeza igual a `status.engine.publication.head.signatureOk` (B7-c);
  · los invisibles rechazados con 422 en un motivo (U+2060, U+00AD, U+061C, U+2028 y la etiqueta U+E0041) y las dos
    contraprobas que NO lo son (texto limpio y un emoji con ZWJ y U+FE0F) (SEC7-a);
  · los PRESUPUESTOS DE RENDIMIENTO de PERF7 (informe-performance.md §7.1), leídos de presupuestos-h7.json: por cada
    ruta del fichero, `samples.warmup` GET sin contar y `samples.n` cronometradas (perf_counter alrededor de la
    petición: conexión nueva y cuerpo leído entero), en serie y separadas al menos `minIntervalSeconds` (la evidencia
    del canario trae los suyos: 1 + 3, ≥ 6,5 s); mediana, máximo y bytes. FALLO si la mediana supera p50Ms, si el
    máximo supera el techo p95, si el cuerpo supera maxBytes o si el estado no es 200. El techo p95 es p95MsAfterF1
    cuando la ruta lo trae (F-1 está en el código del H7 desde e2411e3) y p95Ms si no. OMITIDO si falta lo que pide
    `requires`: sin versión en CANARY (la evidencia), sin versiones, laboratorio apagado (409 lab_disabled) o sin
    ejecuciones. Con 5 muestras el «p95» es el máximo y un solo pico da FALLO: un FALLO SOLO de tiempo se mide otra
    vez, una sola, y vale la repetición (`calibration` del JSON). Cada ruta imprime además la propuesta de techo
    max(1,5 × medida, medida + 50 ms) para recalibrar tras la primera pasada en producción;
  · ninguna fuga en los cuerpos (el detector del H6, `find_leaks`; los de la fase de presupuestos no entran: son las
    mismas lecturas y su asunto es el tiempo), ningún 5xx y ningún 429.
El monitor apagado y sin tareas programadas lo comprueba el lanzador (smoke-run-h7.sh) en el servidor.

POLÍTICA: SIN FILAS POSITIVAS. Las únicas peticiones que no son GET:
  · el login de la cuenta de smoke;
  · 2 POST de 70 000 bytes y 1 de 60 000 a `versions`: el primero y el tercero sin sesión (413 y 401: no llegan al
    controlador) y el segundo con sesión (413 antes de validar);
  · 7 POST a `integrations/canaries/<uuid INVENTADO>/deactivate`: 5 con un invisible (422, antes del servicio) y 2
    contraprobas con texto válido que el servicio para antes de escribir: con las escrituras del H6 apagadas, 409
    integrations_writes_disabled; encendidas, 404 canary_not_found (el uuid no existe: no hay fila que cambiar).
La fase de presupuestos solo hace GET a rutas /admin/ultron-studio/… del fichero (se validan: un fichero manipulado no
convierte el smoke en otra cosa). El lanzador comprueba además en la base que las 14 tablas ultron_studio_* y los
incidentes no cambian.

RECALIBRAR SIN TOCAR CÓDIGO: los techos solo viven en el JSON. Se edita el JSON del repo (p50Ms, p95Ms; en la
evidencia, también p95MsAfterF1, que manda mientras exista), se confirma y se vuelve a copiar con su huella; el smoke
imprime la ruta, la versión y la huella del fichero que ha usado. Con SMOKE_BUDGETS_OUT=<fichero> deja además (0600)
las medidas y la propuesta de cada ruta en JSON, sin datos personales ni secretos.

Uso:   SMOKE_EMAIL=… SMOKE_PW=… python3 smoke-h7.py superadmin        (la contraseña SOLO por entorno, nunca en argv)
Variables: SMOKE_API · SMOKE_H6_PY (la ruta de smoke-h6.py, del que se toma el detector; por defecto, junto a este) ·
           SMOKE_BUDGETS (la ruta de presupuestos-h7.json; por defecto, junto a este o en perf-h7/) ·
           SMOKE_BUDGETS_OUT (opcional) · SMOKE_CANARY_ID · SMOKE_SECRET_SALT y SMOKE_SECRET_DIGESTS (como el H6) ·
           SMOKE_TOKEN_FILE.
Código: 0 todo OK · 1 algún FALLO · 3 sin fallos pero con algo OMITIDO · 2 uso incorrecto (también un JSON de
presupuestos ausente o mal formado: no se hace ninguna petición).
Cupos: 1 login (8/min por IP), 3 POST sin sesión al Studio y 8 escrituras con sesión (30/min por credencial). Las
lecturas con sesión no pasan de 90 en ningún minuto (el cupo es 120/min por credencial; si hace falta, el smoke espera)
y la evidencia, de 8 por minuto (10/min por persona; 4 por pasada y 4 más si se repite). Dura unos 2 minutos. No toca la sonda de Twilio.
"""
import collections
import hashlib
import importlib.util
import json
import math
import os
import re
import statistics
import sys
import time
import urllib.error
import urllib.request

API = os.environ.get('SMOKE_API', 'https://api.ironbodyneiva.cloud/api').rstrip('/')
S = '/admin/ultron-studio'
FAKE = '00000000-0000-4000-8000-0000000007a7'
NO_STORE = 'no-store, private'
ENTRY_KEYS = ['uuid', 'seq', 'action', 'version', 'effectiveHash', 'shortHash', 'labGate', 'actor', 'reason', 'at', 'verified']
# Invisibles que PlainText dejaba pasar antes del H7 (AS-5 del H5) y una etiqueta del bloque U+E0000–E007F.
INVISIBLES = {'U+2060': '\u2060', 'U+00AD': '\u00ad', 'U+061C': '\u061c', 'U+2028': '\u2028', 'U+E0041': '\U000e0041'}   # escritos con escapes: un invisible literal se pierde al copiar
REASON = 'Smoke del Hito 7: motivo de prueba, sin efecto'
EMOJI_REASON = 'Smoke del Hito 7 \U0001F469\u200d\U0001F4BB \u2764\ufe0f sin efecto'   # ZWJ dentro de un emoji y VS16: válidos
# Peticiones con sesión en cualquier ventana de 60 s: por debajo del cupo de lectura (120/min por credencial).
CUPO_MINUTO = 90
# Presupuestos: las rutas que se admiten del JSON y los marcadores que se saben resolver (de dónde salen y qué
# significa que falten). Un uuid resuelto se valida antes de entrar en una ruta.
RUTA_PRESUPUESTO = re.compile(r'^/admin/ultron-studio/[A-Za-z0-9_/{}-]+$')
MARCA = re.compile(r'\{([A-Za-z]+)\}')
UUID = re.compile(r'^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$')
MARCAS = {
    'canary': (S + '/status', ('data', 'engine', 'publication', 'canary', 'versionUuid'),
               'sin versión en CANARY (GET status: engine.publication.canary vacío)'),
    'firstVersion': (S + '/versions', ('data', 'data', 0, 'uuid'), 'no hay versiones (GET versions sin ninguna)'),
    'firstRun': (S + '/lab/runs', ('data', 'data', 0, 'uuid'), 'laboratorio apagado o sin ejecuciones (GET lab/runs sin ninguna)'),
}


def load_h6():
    path = os.environ.get('SMOKE_H6_PY') or os.path.join(os.path.dirname(os.path.abspath(__file__)), 'smoke-h6.py')
    spec = importlib.util.spec_from_file_location('smoke_h6', path)
    if spec is None or spec.loader is None:
        raise FileNotFoundError(path)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def budgets_path():
    env = os.environ.get('SMOKE_BUDGETS', '').strip()
    if env:
        return env
    aqui = os.path.dirname(os.path.abspath(__file__))
    for p in (os.path.join(aqui, 'presupuestos-h7.json'), os.path.join(aqui, 'perf-h7', 'presupuestos-h7.json')):
        if os.path.isfile(p):
            return p
    return os.path.join(aqui, 'presupuestos-h7.json')


def _num(x, minimo=0.0, maximo=None):
    ok = isinstance(x, (int, float)) and not isinstance(x, bool) and x >= minimo
    return ok and (maximo is None or x <= maximo)


def _samples(s, donde):
    if not (isinstance(s, dict) and isinstance(s.get('warmup'), int) and not isinstance(s.get('warmup'), bool)
            and isinstance(s.get('n'), int) and not isinstance(s.get('n'), bool)
            and 0 <= s['warmup'] <= 5 and 1 <= s['n'] <= 30 and _num(s.get('minIntervalSeconds'), 0, 60)):
        raise ValueError(f'{donde}: samples tiene que ser {{warmup 0–5, n 1–30, minIntervalSeconds 0–60}}')
    return {'warmup': s['warmup'], 'n': s['n'], 'minIntervalSeconds': float(s['minIntervalSeconds'])}


def load_budgets(path):
    """(documento validado, huella sha256 de 12 cifras). ValueError/OSError si no sirve: el smoke no empieza."""
    with open(path, 'rb') as f:
        raw = f.read()
    doc = json.loads(raw.decode('utf-8'))
    if not isinstance(doc, dict) or not isinstance(doc.get('routes'), list) or not doc['routes']:
        raise ValueError('sin lista «routes»')
    base = _samples(doc.get('samples'), 'samples')
    vistas = set()
    for i, r in enumerate(doc['routes']):
        donde = f'routes[{i}]'
        if not isinstance(r, dict) or not isinstance(r.get('name'), str) or not r['name'].strip():
            raise ValueError(f'{donde}: sin «name»')
        donde = f'«{r["name"]}»'
        p = r.get('path')
        if not isinstance(p, str) or not RUTA_PRESUPUESTO.match(p) or '..' in p or '//' in p:
            raise ValueError(f'{donde}: «path» tiene que ser una ruta /admin/ultron-studio/… sin «..» ni consulta')
        for m in MARCA.findall(p):
            if m not in MARCAS:
                raise ValueError(f'{donde}: marcador {{{m}}} desconocido (se resuelven {", ".join(sorted(MARCAS))})')
        if MARCA.sub('', p).count('{') or MARCA.sub('', p).count('}'):
            raise ValueError(f'{donde}: llaves sueltas en «path»')
        for k in ('p50Ms', 'p95Ms', 'maxBytes'):
            if not _num(r.get(k), 1):
                raise ValueError(f'{donde}: «{k}» tiene que ser un número ≥ 1')
        if 'p95MsAfterF1' in r and not _num(r['p95MsAfterF1'], 1):
            raise ValueError(f'{donde}: «p95MsAfterF1» tiene que ser un número ≥ 1')
        r['_samples'] = _samples(r['samples'], donde) if 'samples' in r else base
        if r['name'] in vistas:
            raise ValueError(f'{donde}: nombre repetido')
        vistas.add(r['name'])
    return doc, hashlib.sha256(raw).hexdigest()[:12]


def dig(node, path):
    for k in path:
        if isinstance(k, int):
            node = node[k] if isinstance(node, list) and len(node) > k else None
        else:
            node = node.get(k) if isinstance(node, dict) else None
        if node is None:
            return None
    return node


def propuesta(medida):
    """El techo recalibrado del JSON (`calibration`): max(1,5 × medida, medida + 50 ms), redondeado hacia arriba."""
    return int(math.ceil(max(1.5 * medida, medida + 50)))


def data_of(body):
    return body.get('data') if isinstance(body, dict) else None


def code_of(body):
    return body.get('code') if isinstance(body, dict) else None


class Smoke:
    def __init__(self, h6, canary_id, salt, digests):
        self.h6 = h6
        self.canary_id = canary_id
        self.salt = salt
        self.digests = digests
        self.counts = {'OK': 0, 'FALLO': 0, 'OMITIDO': 0}
        self.bodies = []
        self.statuses = []
        self.no_store_bad = []
        self.no_store_seen = 0
        self.recent = collections.deque()   # salidas con sesión de los últimos 60 s (el cupo de arriba)
        self.waited = 0.0
        self.last_ms = 0.0
        self.last_bytes = 0

    def line(self, kind, name, detail=''):
        if kind in self.counts:
            self.counts[kind] += 1
        print(f'{kind:<7} {name}' + (f'  [{detail}]' if detail != '' else ''), flush=True)

    def check(self, name, ok, detail=''):
        self.line('OK' if ok else 'FALLO', name, detail)
        return bool(ok)

    def skip(self, name, why):
        self.line('OMITIDO', name, why)

    def info(self, name, detail=''):
        self.line('INFO', name, detail)

    def pace(self):
        """Ninguna ventana de 60 s con más de CUPO_MINUTO peticiones con sesión: si hace falta, espera."""
        ahora = time.monotonic()
        while self.recent and ahora - self.recent[0] >= 60:
            self.recent.popleft()
        if len(self.recent) >= CUPO_MINUTO:
            espera = 60 - (ahora - self.recent[0]) + 0.05
            time.sleep(espera)
            self.waited += espera
            ahora = time.monotonic()
            while self.recent and ahora - self.recent[0] >= 60:
                self.recent.popleft()
        self.recent.append(time.monotonic())

    def call(self, method, path, body=None, token=None, raw=None, label=None, keep=True):
        data = raw if raw is not None else (json.dumps(body, ensure_ascii=False).encode('utf-8') if body is not None else None)
        req = urllib.request.Request(API + path, data=data, method=method)
        req.add_header('Accept', 'application/json')
        if data is not None:
            req.add_header('Content-Type', 'application/json')
        if token:
            req.add_header('Authorization', 'Bearer ' + token)
            self.pace()
        headers = {}
        payload = b''
        t0 = time.perf_counter()
        try:
            with urllib.request.urlopen(req, timeout=30) as r:
                status, payload, headers = r.status, r.read(), r.headers
        except urllib.error.HTTPError as e:
            status, payload, headers = e.code, e.read(), e.headers
        except (urllib.error.URLError, OSError) as e:
            status = 0
            print(f'        (sin respuesta de {method} {label or path}: {type(e).__name__})', flush=True)
        self.last_ms = (time.perf_counter() - t0) * 1000
        self.last_bytes = len(payload)
        text = payload.decode('utf-8', 'replace')
        try:
            parsed = json.loads(text) if text else None
        except ValueError:
            parsed = None
        self.statuses.append(status)
        lbl = f'{method} {label or path.replace(S + "/", "")}'.replace(FAKE, '<inventado>')
        if status:
            cc = headers.get('Cache-Control') if headers else None
            if path.startswith('/admin'):
                self.no_store_seen += 1
                if cc != NO_STORE:
                    self.no_store_bad.append(f'{lbl}={cc}')
        if keep:
            self.bodies.append((lbl, text if len(text) < 200000 else text[:200000], parsed))
        return status, parsed, headers

    def scan(self):
        n = len(self.bodies)
        leaks = self.h6.find_leaks(self.bodies, self.canary_id, self.salt, self.digests)
        joined = lambda s: ', '.join(sorted(s))[:240]
        self.check(f'cuerpos ({n}): sin claves prohibidas (teléfono, huellas, ids de Meta, textos del proveedor, firmas)', not leaks['forbidden'], joined(leaks['forbidden']))
        self.check(f'cuerpos ({n}): cada secreto solo como presencia, origen, huella de 8 hex o referencia corta', not leaks['container'], joined(leaks['container']))
        self.check(f'cuerpos ({n}): sin credenciales, sin APP_KEY y sin rutas del servidor', not leaks['secret'], joined(leaks['secret']))
        self.check(f'cuerpos ({n}): sin teléfonos ni ids de Meta (10 cifras o más)', not leaks['phone_or_id'], joined(leaks['phone_or_id']))
        self.check(f'cuerpos ({n}): ninguna clave nombra una conversación', not leaks['conversation'], joined(leaks['conversation']))
        if self.canary_id and self.canary_id != 'none':
            self.check(f'cuerpos ({n}): ninguno lleva el id de la conversación canaria', not leaks['canary'], joined(leaks['canary']))
        if self.digests:
            k = sum(len(v) for v in self.digests.values())
            self.check(f'cuerpos ({n}): ninguno contiene un secreto del servidor (centinela exacto, {k} huellas saladas)', not leaks['sentinel'], joined(leaks['sentinel']))
        else:
            self.skip('cuerpos: centinela exacto de los secretos del servidor', 'sin SMOKE_SECRET_DIGESTS (las calcula el lanzador)')
        self.check(f'Cache-Control «{NO_STORE}» en las {self.no_store_seen} respuestas de /api/admin', not self.no_store_bad, ', '.join(self.no_store_bad)[:240])
        self.check('limitador: ninguna respuesta 429', 429 not in self.statuses, f'{self.statuses.count(429)} respuestas 429')
        if 0 in self.statuses:
            self.check('red: todas las peticiones tuvieron respuesta', False, f'{self.statuses.count(0)} sin respuesta')
        fives = sum(1 for s in self.statuses if 500 <= s <= 599)
        self.check('ninguna respuesta 5xx', fives == 0, f'{fives} respuestas 5xx')

    def exit_code(self):
        if self.counts['FALLO']:
            return 1
        return 3 if self.counts['OMITIDO'] else 0


def login(sm, email, password):
    st, body, _ = sm.call('POST', '/admin/auth/login', {'email': email, 'password': password}, label='auth/login')
    token = body.get('token') if isinstance(body, dict) else None
    hint = ' · limitador del login (8/min por IP): esperar un minuto' if st == 429 else ''
    if not sm.check('login de la cuenta de smoke', st == 200 and bool(token), f'HTTP {st} {code_of(body) or ""}'.strip() + hint):
        return None
    path = os.environ.get('SMOKE_TOKEN_FILE')
    if path:
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        os.fchmod(fd, 0o600)
        with os.fdopen(fd, 'w') as f:
            f.write('Authorization: Bearer ' + token + '\n')
    # El cuerpo del login lleva el token: no entra en la lista de cuerpos que se enseñan ni se buscan fuera.
    sm.bodies.pop()
    return token


def check_no_store(sm, token):
    bad = []
    for rel in ['status', 'publications?page=1&per_page=5', 'versions?page=1&per_page=5', 'integrations/status', 'audit?page=1&per_page=5']:
        st, _, h = sm.call('GET', f'{S}/{rel}', token=token, label=rel.split('?')[0])
        if st != 200:
            bad.append(f'{rel.split("?")[0]}={st}')
    st, _, _ = sm.call('GET', '/admin/auth/me', token=token, label='auth/me')
    if st != 200:
        bad.append(f'auth/me={st}')
    sm.check('lecturas autenticadas (Studio y auth/me) → 200', not bad, ', '.join(bad))
    st401, b401, _ = sm.call('GET', f'{S}/status', label='status sin token')
    st404, _, _ = sm.call('GET', '/admin/no-existe-smoke-h7', token=token, label='admin/no-existe')
    sm.check('sin token → 401 y una ruta administrativa inexistente → 404', st401 == 401 and st404 == 404, f'{st401} · {st404}')


def check_body_cap(sm, token):
    grande = json.dumps({'label': 'x' * 70000}).encode()
    justo = json.dumps({'label': 'x' * 60000}).encode()
    st, b, _ = sm.call('POST', f'{S}/versions', raw=grande, label='versions (70 000 bytes, sin token)')
    sm.check('70 000 bytes sin sesión → 413 payload_too_large (el tope va antes de la autenticación)', st == 413 and code_of(b) == 'payload_too_large', f'HTTP {st} {code_of(b) or ""}'.strip())
    st, b, _ = sm.call('POST', f'{S}/versions', raw=grande, token=token, label='versions (70 000 bytes)')
    sm.check('70 000 bytes con sesión → 413 payload_too_large (antes de validar: no hay versión)', st == 413 and code_of(b) == 'payload_too_large', f'HTTP {st} {code_of(b) or ""}'.strip())
    st, b, _ = sm.call('POST', f'{S}/versions', raw=justo, label='versions (60 000 bytes, sin token)')
    sm.check('60 000 bytes sin sesión → 401 (por debajo del tope manda la autenticación)', st == 401, f'HTTP {st} {code_of(b) or ""}'.strip())


def check_verified(sm, token):
    st, b, _ = sm.call('GET', f'{S}/publications?page=1&per_page=50', token=token, label='publications')
    page = data_of(b) if isinstance(data_of(b), dict) else {}
    entries = page.get('data') if isinstance(page.get('data'), list) else None
    if not sm.check('GET publications → 200 con su página', st == 200 and entries is not None, f'HTTP {st}'):
        return
    # La forma del H5 más «verified» al final (B7-c: «la forma solo suma verified al final»).
    shapes = [e for e in entries if not (isinstance(e, dict) and set(e.keys()) <= set(ENTRY_KEYS) and {'uuid', 'seq', 'action', 'verified'} <= set(e.keys())
                                         and list(e.keys())[-1] == 'verified' and isinstance(e.get('verified'), bool))]
    sm.check(f'historial: las {len(entries)} entradas tienen la forma del H5 y «verified» booleano al final', not shapes, f'{len(shapes)} con otra forma')
    falsas = [e.get('seq') for e in entries if isinstance(e, dict) and e.get('verified') is not True]
    sm.check(f'historial: las {len(entries)} entradas verifican (verified true)', not falsas, f'no verifican seq {falsas}' if falsas else '')
    if not entries:
        sm.info('historial vacío', 'el motor lee Legacy: nada que verificar entrada a entrada')
    st, s, _ = sm.call('GET', f'{S}/status', token=token, label='status')
    head = (((data_of(s) or {}).get('engine') or {}).get('publication') or {}).get('head') if st == 200 else None
    if entries and isinstance(head, dict):
        cabeza = max((e for e in entries if isinstance(e, dict)), key=lambda e: e.get('seq') or 0)
        sm.check('la cabeza del historial: verified = head.signatureOk del estado', cabeza.get('verified') is head.get('signatureOk'), f'verified {cabeza.get("verified")} · signatureOk {head.get("signatureOk")}')
    elif entries:
        sm.check('el estado trae la cabeza de una cadena no vacía', False, f'HTTP {st}')
    st, v, _ = sm.call('GET', f'{S}/versions?page=1&per_page=3', token=token, label='versions')
    vpage = data_of(v) if isinstance(data_of(v), dict) else {}
    uuids = [x.get('uuid') for x in (vpage.get('data') or []) if isinstance(x, dict) and isinstance(x.get('uuid'), str)][:3]
    if not uuids:
        sm.info('detalle de versiones', 'no hay versiones: nada que mirar en publications del detalle')
        return
    bad = []
    total = 0
    for u in uuids:
        st, d, _ = sm.call('GET', f'{S}/versions/{u}', token=token, label='versions/<uuid>')
        pubs = (data_of(d) or {}).get('publications') if st == 200 else None
        if not isinstance(pubs, list):
            bad.append(f'HTTP {st}')
            continue
        total += len(pubs)
        bad += [f'seq {p.get("seq")}' for p in pubs if not (isinstance(p, dict) and p.get('verified') is True)]
    sm.check(f'detalle de {len(uuids)} versión(es): sus {total} entradas de la cadena verifican', not bad, ', '.join(bad)[:200])


def check_invisibles(sm, token):
    st, b, _ = sm.call('GET', f'{S}/integrations/status', token=token, label='integrations/status')
    writes = ((data_of(b) or {}).get('flags') or {}).get('writesEnabled') if st == 200 else None
    if not isinstance(writes, bool):
        sm.skip('motivos con invisibles', f'no se pudo leer flags.writesEnabled (HTTP {st}): sin la bandera vista no se envía nada')
        return
    want = ('404', 'canary_not_found') if writes else ('409', 'integrations_writes_disabled')
    path = f'{S}/integrations/canaries/{FAKE}/deactivate'
    bad = []
    for name, ch in INVISIBLES.items():
        st, r, _ = sm.call('POST', path, {'reason': f'Smoke del Hito 7{ch} sin efecto'}, token, label=f'canaries/<inventado>/deactivate ({name})')
        errors = r.get('errors') if isinstance(r, dict) else None
        if not (st == 422 and isinstance(errors, dict) and 'reason' in errors):
            bad.append(f'{name}={st}')
    sm.check(f'motivo con un invisible ({", ".join(INVISIBLES)}) → 422 en reason', not bad, ', '.join(bad))
    for label, reason in [('texto limpio', REASON), ('emoji con ZWJ y U+FE0F', EMOJI_REASON)]:
        st, r, _ = sm.call('POST', path, {'reason': reason}, token, label=f'canaries/<inventado>/deactivate ({label})')
        sm.check(f'contraprueba, {label}: NO es 422 (pasa la forma y lo para el servicio: {want[0]} {want[1]})',
                 str(st) == want[0] and code_of(r) == want[1], f'HTTP {st} {code_of(r) or ""}'.strip())


def measure(sm, token, rel, label, samples):
    """Una medida de una ruta: (estado de corte, cuerpo de corte, tiempos ms, bytes, último cuerpo 200).
    Se para en la primera respuesta que no sea 200 (incluido el calentamiento): no insiste sobre un error."""
    tiempos, tamanos, ultimo, previa = [], [], None, None
    for i in range(samples['warmup'] + samples['n']):
        if previa is not None:
            espera = samples['minIntervalSeconds'] - (time.perf_counter() - previa)
            if espera > 0:
                time.sleep(espera)
        previa = time.perf_counter()
        st, body, _ = sm.call('GET', rel, token=token, label=label, keep=False)
        if st != 200:
            return st, body, tiempos, tamanos, ultimo
        if i >= samples['warmup']:
            tiempos.append(sm.last_ms)
            tamanos.append(sm.last_bytes)
        ultimo = body
    return 200, None, tiempos, tamanos, ultimo


def judge(route, techo95, tiempos, tamanos):
    """(mediana, máximo, bytes, motivos de FALLO, ¿todos los motivos son de tiempo?)."""
    med, mx, by = statistics.median(tiempos), max(tiempos), max(tamanos)
    tiempo = []
    if med > route['p50Ms']:
        tiempo.append(f'mediana {med:.1f} > {route["p50Ms"]:g}')
    if mx > techo95:
        tiempo.append(f'máximo {mx:.1f} > {techo95:g}')
    otros = [f'{by} B > {route["maxBytes"]:g}'] if by > route['maxBytes'] else []
    return med, mx, by, tiempo + otros, bool(tiempo) and not otros


def check_budgets(sm, token, doc, origen, huella):
    rutas = doc['routes']
    base = doc['samples']
    print(f"== Presupuestos de rendimiento · {origen} · {doc.get('version', '¿sin versión?')} · sha256 {huella} · {len(rutas)} rutas · "
          f"{base['warmup']} + {base['n']} muestras, ≥ {base['minIntervalSeconds']:g} s", flush=True)
    t0 = time.monotonic()
    espera0 = sm.waited
    resueltos = {}
    sin_200 = set()          # rutas de origen de un marcador que no dieron 200 (su FALLO ya se ha contado)
    tally = {'OK': 0, 'FALLO': 0, 'OMITIDO': 0}
    informe = []

    def anota(kind, route, motivo='', **medidas):
        tally[kind] += 1
        informe.append({'name': route['name'], 'path': route['path'], 'status': kind, 'reason': motivo, **medidas,
                        'p50Ms': route['p50Ms'], 'p95Ms': route.get('p95MsAfterF1', route['p95Ms']), 'maxBytes': route['maxBytes']})

    for route in rutas:
        nombre = f"presupuesto {route['name']}"
        faltan = [m for m in MARCA.findall(route['path']) if m not in resueltos]
        if faltan:
            motivo = '; '.join(f'falta {{{m}}}: GET {MARCAS[m][0].replace(S + "/", "")} no dio 200' if MARCAS[m][0] in sin_200 else MARCAS[m][2]
                               for m in faltan)
            sm.skip(nombre, motivo)
            anota('OMITIDO', route, motivo)
            continue
        rel = MARCA.sub(lambda m: resueltos[m.group(1)], route['path'])
        techo95 = route.get('p95MsAfterF1', route['p95Ms'])
        nota95 = f' (tras F-1; antes, {route["p95Ms"]:g})' if 'p95MsAfterF1' in route else ''
        etiqueta = route['name'].replace('GET ', '', 1)
        intentos = []
        while True:
            st, cuerpo, tiempos, tamanos, ultimo = measure(sm, token, rel, etiqueta, route['_samples'])
            if st != 200:
                break
            med, mx, by, malos, solo_tiempo = judge(route, techo95, tiempos, tamanos)
            intentos.append((med, mx, by, malos))
            if malos and solo_tiempo and len(intentos) == 1:
                continue        # un pico con 5 muestras: se repite una vez y vale la repetición
            break
        if st == 409 and code_of(cuerpo) == 'lab_disabled' and route.get('requires'):
            sm.skip(nombre, 'laboratorio apagado (409 lab_disabled)')
            anota('OMITIDO', route, 'laboratorio apagado (409 lab_disabled)')
            continue
        if st != 200:
            sm.check(nombre, False, f'HTTP {st} {code_of(cuerpo) or ""}'.strip() + ' (se esperaba 200)')
            anota('FALLO', route, f'HTTP {st}')
            sin_200.add(route['path'])
            continue
        med, mx, by, malos = intentos[-1]
        n = len(tiempos)
        previo = ''
        if len(intentos) == 2:
            p = intentos[0]
            previo = f' · repetida: la primera medida dio {", ".join(p[3])}'
        cal = f'calibración: p50 ≤ {propuesta(med)} · p95 ≤ {propuesta(mx)}'
        texto = (f"mediana {med:.1f} ms ≤ {route['p50Ms']:g} · máximo {mx:.1f} ms ≤ {techo95:g}{nota95} · {by} B ≤ {route['maxBytes']:g}"
                 if not malos else ', '.join(malos))
        sm.check(f'{nombre}: {texto}', not malos, f'n {n}{previo} · {cal}')
        anota('FALLO' if malos else 'OK', route, ', '.join(malos), n=n, medianMs=round(med, 1), maxMs=round(mx, 1), bytes=by,
              retried=len(intentos) == 2, proposedP50Ms=propuesta(med), proposedP95Ms=propuesta(mx))
        for marca, (origen_m, camino, _) in MARCAS.items():
            if route['path'] == origen_m:
                valor = dig(ultimo, camino)
                if isinstance(valor, str) and UUID.match(valor):
                    resueltos[marca] = valor.lower()
    dur = time.monotonic() - t0
    esperas = sm.waited - espera0
    print(f"PRESUPUESTOS: {tally['OK']} OK · {tally['FALLO']} FALLO · {tally['OMITIDO']} OMITIDO · {dur:.0f} s"
          + (f' (de ellos {esperas:.0f} s esperando al cupo de {CUPO_MINUTO}/min)' if esperas else ''), flush=True)
    salida = os.environ.get('SMOKE_BUDGETS_OUT', '').strip()
    if salida:
        datos = {'source': origen, 'sha256': huella, 'version': doc.get('version'), 'api': API,
                 'at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()),
                 'rule': 'propuesta = max(1,5 × medida, medida + 50 ms): p50 con la mediana, p95 con el máximo', 'routes': informe}
        fd = os.open(salida, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        os.fchmod(fd, 0o600)
        with os.fdopen(fd, 'w', encoding='utf-8') as f:
            json.dump(datos, f, ensure_ascii=False, indent=2)
            f.write('\n')
        print(f'        medidas y propuesta de cada ruta en {salida} (0600)', flush=True)


def main(argv):
    part = argv[1] if len(argv) > 1 else ''
    email = os.environ.get('SMOKE_EMAIL', '')
    password = os.environ.get('SMOKE_PW', '')
    canary = os.environ.get('SMOKE_CANARY_ID', '').strip()
    salt_hex = os.environ.get('SMOKE_SECRET_SALT', '').strip()
    digests_raw = os.environ.get('SMOKE_SECRET_DIGESTS', '').strip()
    if part != 'superadmin':
        print('uso: SMOKE_EMAIL=… SMOKE_PW=… smoke-h7.py superadmin')
        return 2
    if not email or not password:
        print('faltan SMOKE_EMAIL o SMOKE_PW (la contraseña solo por entorno)')
        return 2
    if canary and canary != 'none' and not canary.isdigit():
        print('SMOKE_CANARY_ID tiene que ser un número o «none»')
        return 2
    if digests_raw and digests_raw != 'none' and not re.fullmatch(r'[0-9a-f]{32,128}', salt_hex):
        print('SMOKE_SECRET_DIGESTS sin SMOKE_SECRET_SALT válida (hex)')
        return 2
    try:
        h6 = load_h6()
    except (OSError, ImportError, SyntaxError) as e:
        print(f'no se pudo cargar el detector de smoke-h6.py ({type(e).__name__}): SMOKE_H6_PY=…')
        return 2
    origen = budgets_path()
    try:
        budgets, huella = load_budgets(origen)
    except (OSError, ValueError, KeyError, TypeError) as e:
        detalle = str(e) if isinstance(e, ValueError) and not isinstance(e, json.JSONDecodeError) else type(e).__name__
        print(f'no se pudieron cargar los presupuestos de {origen} ({detalle}): SMOKE_BUDGETS=…')
        return 2
    sm = Smoke(h6, canary, bytes.fromhex(salt_hex) if salt_hex else b'', h6.parse_digests(digests_raw))
    print(f'== Smoke del Hito 7 · {part} · {API}', flush=True)
    token = login(sm, email, password)
    if token:
        check_no_store(sm, token)
        check_body_cap(sm, token)
        check_verified(sm, token)
        check_invisibles(sm, token)
        check_budgets(sm, token, budgets, origen, huella)
    sm.scan()
    c = sm.counts
    print(f"RESUMEN ({part}): {c['OK']} OK · {c['FALLO']} FALLO · {c['OMITIDO']} OMITIDO", flush=True)
    return sm.exit_code()


if __name__ == '__main__':
    sys.exit(main(sys.argv))

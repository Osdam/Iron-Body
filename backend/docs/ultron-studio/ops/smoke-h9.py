#!/usr/bin/env python3
"""Smoke del Hito 9 de ULTRON STUDIO (automatización comercial omnicanal), fase 1: TODO APAGADO; fase 2: SOMBRA.

Comprueba, contra la API desplegada y SIN FILAS POSITIVAS:
  · las 30 rutas del gobierno de las estrategias (S9, /api/admin/ultron-studio/strategies/**) SIN SESIÓN → 401 con
    `Cache-Control: no-store, private`; CON SESIÓN (la cuenta temporal del smoke, Super Admin) y la bandera maestra
    apagada (fase 1) → las 30 responden 409 `strategies_disabled`, también las lecturas (contrato §7 y §9.1; nada se
    lee ni se escribe); con la bandera encendida (fase 2, si el propietario la abrió para el canario): las lecturas →
    200 (vacías o no), un uuid inventado → 404, los POST con un cuerpo vacío → 422 antes de escribir, y publicar,
    promover y reanudar → 409 `strategies_publishing_disabled` mientras publicar esté apagado;
  · las 13 rutas de la ejecución (B9-run, /api/admin/ultron-studio/outreach/**, L9-57): sin sesión → 401 y no-store;
    con sesión: `GET status` responde 200 SIEMPRE (observabilidad; la maestra apagada se lee en `data.config.enabled =
    false` y `data.config.mode = off`), con la constante de envío cerrada (`data.config.sendsEnabledInCode = false`),
    ningún canal hacia personas que pueda salir (desde L9-80/L9-81 WhatsApp y la voz están DECLARADOS, `live = true`:
    implementado no es abierto; los cierra la constante y, en la fase 1, su bandera) y la cabeza de la cadena legible; las OTRAS 12, con la maestra APAGADA (fase 1)
    → 409 `outreach_disabled` en todas (L9-47 y L9-68: la puerta va tras la llave y antes de validar o buscar nada:
    401/403 van antes; 404 y 422 nunca); con la maestra ENCENDIDA (fase 2): las listas → 200, un uuid inventado → 404,
    los POST con un cuerpo vacío → 422 (la PARADA GLOBAL pide motivo: con {} no se abre ningún freno), nunca un 2xx en
    un POST ni un 404 en una ruta que existe. Un 404 de `GET status` es un FALLO (desde L9-59 las 13 rutas viajan en la
    fase 1) y las otras 12 quedan OMITIDAS;
  · el canal SSE del Studio (SSE9, GET /api/admin/ultron-studio/stream): con un tique de `GET admin/stream-ticket` y la
    bandera apagada → 409 `stream_disabled` (el CRM sondea); sin tique → 401 (o 409 si la bandera va antes). Si la ruta
    no existe (SSE9 no viaja) → OMITIDO. Con la bandera encendida el smoke NO abre el canal (emite hasta 55 s);
  · los PRESUPUESTOS de rendimiento de PERF9 (perf-h9/presupuestos-h9.json; D9-16: historial p95 < 300 ms, métricas
    p95 < 500 ms) si el fichero existe: por ruta, `samples.warmup` GET sin contar y `samples.n` cronometradas, en serie y
    separadas al menos `minIntervalSeconds`; FALLO si la mediana supera p50Ms, el máximo supera p95Ms, el cuerpo supera
    maxBytes o el estado no es 200. Un FALLO SOLO de tiempo se mide otra vez, una sola, y vale la repetición. Una ruta
    que responde 409 `strategies_disabled` (fase 1) o 404 (la ejecución sin desplegar) queda OMITIDA, no FALLO. Sin
    fichero (PERF9 aún no lo entregó), los presupuestos quedan OMITIDOS y lo dice;
  · ninguna fuga en los cuerpos (el detector del H6, `find_leaks`, más las claves propias del H9: `subject_key`,
    `member_id`, documento y correo, P9-9 y E3: solo `subjectRef`), ningún 5xx y ningún 429.
La red saliente, la base (recuentos exactos antes y después), el programador (0 tareas outreach:* en la fase 1) y, en
la fase 2, la pasada en sombra SIN ENVÍOS los comprueba el lanzador (smoke-run-h9.sh) en el servidor.

POLÍTICA: SIN FILAS POSITIVAS. Las únicas peticiones que no son GET:
  · el login de la cuenta de smoke;
  · el tique del canal SSE (GET; de un solo uso y vida corta);
  · 19 POST/PUT del gobierno con cuerpos que la validación rechaza (422), recursos inventados (404) o que la bandera
    para antes (409); publicar, promover y reanudar SOLO con uuids inventados: ni con la bandera encendida se puede
    aplicar nada;
  · 4 POST de la ejecución con cuerpo vacío o a recursos inventados: 409 con la maestra apagada; 422/404 con ella
    encendida (el freno global pide motivo).
La fase de presupuestos solo hace GET a las rutas que admite el validador (un fichero manipulado no convierte el smoke
en otra cosa).

Uso:   SMOKE_EMAIL=… SMOKE_PW=… python3 smoke-h9.py superadmin        (la contraseña SOLO por entorno, nunca en argv)
Variables: SMOKE_API · SMOKE_H6_PY (smoke-h6.py, del que se toma el detector) · SMOKE_BUDGETS (opcional) ·
           SMOKE_BUDGETS_OUT · SMOKE_CANARY_ID · SMOKE_SECRET_SALT y SMOKE_SECRET_DIGESTS (como el H6) ·
           SMOKE_TOKEN_FILE · SMOKE_PHASE (1 por defecto | 2 sombra).
Código: 0 todo OK · 1 algún FALLO · 3 sin fallos pero con algo OMITIDO · 2 uso incorrecto (también un JSON de
presupuestos dado y mal formado: no se hace ninguna petición).

FASES: SMOKE_PHASE = 1 (todo apagado: lo de arriba) · 2 (sombra: `GET status` con modo shadow, la constante cerrada, el
asistido y los canales apagados; el gobierno puede estar encendido y el smoke lo lee de `GET strategies/catalog`). En
las dos el smoke sigue SIN FILAS POSITIVAS.

OLA M-1 + PLANTILLAS DE META (SMOKE_EXPECT_M1=1, lo pone deploy-m1-backend.sh; vacía = se comprueba si está y, si no,
OMITIDO): `GET status` del Studio trae `engine.prompts` con la llave de SMOKE_PROMPTS_KEY (off: `current` legacy con
`prompts_off`, la base n8n-29de5119, el compilador 3 (2 desde las frases de las reglas, M1-18; 3 desde el margen del tope, A4-1), la huella Legacy y como mucho 20 turnos sin ids ni textos; solo
ese subárbol entra en el barrido de fugas); las 4 rutas de las plantillas de Meta sin sesión → 401; con sesión y las
estrategias apagadas → 409 strategies_disabled; con las estrategias encendidas y el módulo apagado: `meta/status` → 200
con `enabled`, `submitEnabled` y `syncEnabled` en false, sin sonda ni envíos, y las otras 3 → 409
meta_templates_disabled. La SONDA solo se toca si `meta/status` acaba de decir que el módulo está apagado (encendido,
hablaría con Meta: queda OMITIDA); enviar va con un cuerpo vacío y comprobar con un uuid inventado: nunca un 2xx.
Cupos: 1 login (8/min por IP); lecturas con sesión ≤ 90 en cualquier minuto (cupo 120); escrituras con sesión ≤ 25 por
minuto (cupo 30); el cubo de la evidencia (las simulaciones, promover y la evidencia del canario comparten
`ultron-studio-evidence`, 10/min por persona) ≤ 8 por minuto. Si hace falta, el smoke espera. Dura unos 2 minutos.
"""
import collections
import hashlib
import importlib.util
import json
import math
import os
import re
import socket
import statistics
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

API = os.environ.get('SMOKE_API', 'https://api.ironbodyneiva.cloud/api').rstrip('/')
S = '/admin/ultron-studio'
C = '/admin/ultron-studio/outreach'   # L9-57: la ejecución cuelga del prefijo del Studio
FAKE = '00000000-0000-4000-8000-0000000009a9'
ZEROS64 = '0' * 64
NO_STORE = 'no-store, private'
FASE = os.environ.get('SMOKE_PHASE', '1').strip() or '1'
# Lo que tiene que decir cada fase. None = cualquiera (se informa). Ver la cabecera, «FASES».
ESPERA = {
    '1': {'enabled': False, 'mode': 'off', 'strategies': False, 'stream': False, 'listas_vacias': True},
    '2': {'enabled': True, 'mode': 'shadow', 'strategies': None, 'stream': None, 'listas_vacias': False},
}
VIVO = {'strategies': False, 'publishing': None, 'exec': False, 'exec_enabled': False, 'stream_route': False, 'meta_route': None}   # lo que la API dice de verdad
# Cupos con sesión por minuto (por debajo de los del servidor) y cubos: lectura (ultron-studio-read 120/min), escritura
# (ultron-studio-write 30/min) y la evidencia (ultron-studio-evidence 10/min por persona: la comparten las dos
# simulaciones, promover y GET canary-evidence).
CUPOS = {'read': 90, 'write': 25, 'evidence': 8}
RUTA_PRESUPUESTO = re.compile(r'^/admin/ultron-studio/(?:strategies|outreach)(?:/[A-Za-z0-9_-]+)*$')
EVIDENCE_PATHS = {f'{S}/strategies/versions/{FAKE}/canary-evidence', f'{S}/strategies/{FAKE}/simulations',
                  f'{S}/strategies/versions/{FAKE}/simulations', f'{S}/strategies/versions/{FAKE}/promote'}
# Claves que una respuesta del H9 no lleva nunca (P9-9, E3, SSE9): la persona solo como `subjectRef` (huella).
H9_FORBIDDEN_KEYS = {'subject_key', 'subjectKey', 'member_id', 'memberId', 'document', 'documentNumber', 'document_number',
                     'email', 'full_name', 'fullName', 'body', 'message_body', 'messageBody'}
# La ola M-1 + plantillas de Meta (ver la cabecera). Constantes de informe-bm.md §3 y dtos-studio-prompts.md §2.
M1 = os.environ.get('SMOKE_EXPECT_M1', '').strip() == '1'
PROMPTS_KEY = os.environ.get('SMOKE_PROMPTS_KEY', 'off').strip() or 'off'
PROMPTS_BASE = 'n8n-29de5119'
LEGACY_BUNDLE = '3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902'
PROMPT_STATES = {'applied', 'legacy', 'engine_fallback', 'mismatch', 'legacy_drift', 'not_reported', 'unverified', 'stamp_error', None}
TURN_KEYS = {'at', 'declared', 'offered', 'observed', 'state'}
# El estado ESPERADO de las plantillas de Meta (el mando plantillas/meta-templates-scope.sh): off (por defecto) · read · full.
# Sin la variable, off con lo de un módulo que nunca se ha usado (sin sonda en 24 h y sin envíos): el despliegue de la ola.
# Con ella, la sonda y los envíos se informan (en read/full el CRM los usa); las tres banderas, siempre las del estado.
META_EXPECT_RAW = os.environ.get('SMOKE_EXPECT_META_TEMPLATES', '').strip()
META_EXPECT = META_EXPECT_RAW or 'off'
META_FLAGS = {'off': (False, False, False), 'read': (True, True, False), 'full': (True, True, True)}   # enabled, sync, submit
META_ROUTES = [('GET', 'strategies/templates/meta/status', None), ('POST', 'strategies/templates/meta/probe', None),
               ('POST', f'strategies/templates/{FAKE}/meta/submit', {}), ('POST', f'strategies/templates/{FAKE}/meta/refresh', None)]


def load_h6():
    path = os.environ.get('SMOKE_H6_PY') or os.path.join(os.path.dirname(os.path.abspath(__file__)), 'smoke-h6.py')
    spec = importlib.util.spec_from_file_location('smoke_h6', path)
    if spec is None or spec.loader is None:
        raise FileNotFoundError(path)
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def budgets_path():
    """El fichero de presupuestos o '' si no hay ninguno (los presupuestos quedan OMITIDOS, no es un error de uso)."""
    env = os.environ.get('SMOKE_BUDGETS', '').strip()
    if env:
        return env
    aqui = os.path.dirname(os.path.abspath(__file__))
    # El de PERF9; junto al smoke solo está en el servidor (el runbook lo copia a /root con ese nombre).
    for p in (os.path.join(aqui, 'perf-h9', 'presupuestos-h9.json'), os.path.join(aqui, 'presupuestos-h9.json')):
        if os.path.isfile(p):
            return p
    return ''


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
        if not isinstance(p, str) or not RUTA_PRESUPUESTO.match(p) or '..' in p or '//' in p or '{' in p:
            raise ValueError(f'{donde}: «path» tiene que ser una ruta GET del H9 (/admin/ultron-studio/strategies… o '
                             f'/admin/ultron-studio/outreach/…) sin «..», consulta ni marcadores')
        for k in ('p50Ms', 'p95Ms', 'maxBytes'):
            if not _num(r.get(k), 1):
                raise ValueError(f'{donde}: «{k}» tiene que ser un número ≥ 1')
        if r['p95Ms'] < r['p50Ms']:
            raise ValueError(f'{donde}: p95Ms por debajo de p50Ms')
        r['_samples'] = _samples(r['samples'], donde) if 'samples' in r else base
        if r['name'] in vistas:
            raise ValueError(f'{donde}: nombre repetido')
        vistas.add(r['name'])
    return doc, hashlib.sha256(raw).hexdigest()[:12]


def propuesta(medida):
    """El techo recalibrado (`calibration`): max(1,5 × medida, medida + 50 ms), redondeado hacia arriba."""
    return int(math.ceil(max(1.5 * medida, medida + 50)))


def data_of(body):
    return body.get('data') if isinstance(body, dict) else None


def code_of(body):
    return body.get('code') if isinstance(body, dict) else None


def busca(node, nombres, profundidad=0):
    """El primer valor de una clave con uno de esos nombres (recursivo, hasta 6 niveles) o un centinela."""
    if profundidad > 6:
        return busca.NADA
    if isinstance(node, dict):
        for k, v in node.items():
            if str(k) in nombres:
                return v
        for v in node.values():
            r = busca(v, nombres, profundidad + 1)
            if r is not busca.NADA:
                return r
    elif isinstance(node, list):
        for v in node[:20]:
            r = busca(v, nombres, profundidad + 1)
            if r is not busca.NADA:
                return r
    return busca.NADA


busca.NADA = object()


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
        self.recent = {k: collections.deque() for k in CUPOS}
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

    def pace(self, buckets):
        """Ningún minuto con más peticiones con sesión que el cupo de cada cubo: si hace falta, espera."""
        for b in buckets:
            cola, cupo = self.recent[b], CUPOS[b]
            ahora = time.monotonic()
            while cola and ahora - cola[0] >= 60:
                cola.popleft()
            if len(cola) >= cupo:
                espera = 60 - (ahora - cola[0]) + 0.05
                time.sleep(espera)
                self.waited += espera
                ahora = time.monotonic()
                while cola and ahora - cola[0] >= 60:
                    cola.popleft()
        for b in buckets:
            self.recent[b].append(time.monotonic())

    def call(self, method, path, body=None, token=None, raw=None, label=None, keep=True, content_type='application/json', buckets=None, timeout=30):
        data = raw if raw is not None else (json.dumps(body, ensure_ascii=False).encode('utf-8') if body is not None else None)
        req = urllib.request.Request(API + path, data=data, method=method)
        req.add_header('Accept', 'application/json')
        if data is not None:
            req.add_header('Content-Type', content_type)
        if token:
            req.add_header('Authorization', 'Bearer ' + token)
            if buckets is None:
                buckets = ('read',) if method == 'GET' else ('write',)
                if path.split('?')[0] in EVIDENCE_PATHS:
                    buckets += ('evidence',)
        buckets = tuple(buckets or ())
        if buckets:
            self.pace(buckets)
        headers = {}
        payload = b''
        t0 = time.perf_counter()
        try:
            with urllib.request.urlopen(req, timeout=timeout) as r:
                status, payload, headers = r.status, r.read(), r.headers
        except urllib.error.HTTPError as e:
            status, payload, headers = e.code, e.read(), e.headers
        except (urllib.error.URLError, OSError, socket.timeout) as e:
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
        lbl = f'{method} {label or path}'.replace(FAKE, '<inventado>').replace(ZEROS64, '<ceros>')
        if status and path.startswith('/admin'):
            self.no_store_seen += 1
            cc = headers.get('Cache-Control') if headers else None
            if cc != NO_STORE:
                self.no_store_bad.append(f'{lbl}={cc}')
        if keep:
            self.bodies.append((lbl, text if len(text) < 200000 else text[:200000], parsed))
        return status, parsed, headers

    def scan(self):
        n = len(self.bodies)
        leaks = self.h6.find_leaks(self.bodies, self.canary_id, self.salt, self.digests)
        # El catálogo de estrategias es VOCABULARIO estático: nombres de hechos («conversation.human_in_control»,
        # «conversation.window_open») y códigos de motivo («no_whatsapp_conversation»), no una conversación. En la fase 1
        # respondía 409 y nunca se barría; con el gobierno encendido (fase 2, L9-101) daba un FALLO falso. Solo se exime
        # esa ruta y solo de esta regla: las demás (teléfonos, ids, secretos, claves propias) la siguen barriendo.
        leaks['conversation'] = {l for l in leaks['conversation'] if not l.endswith('/admin/ultron-studio/strategies/catalog')}
        joined = lambda s: ', '.join(sorted(s))[:240]
        self.check(f'cuerpos ({n}): sin claves prohibidas del H6 (teléfono, huellas, ids de Meta, textos del proveedor, firmas)', not leaks['forbidden'], joined(leaks['forbidden']))
        propias = set()
        for lbl, _, parsed in self.bodies:
            for kind, _, key, _ in self.h6.pairs(parsed):
                if kind == 'key' and key in H9_FORBIDDEN_KEYS:
                    propias.add(f'{lbl}:{key}')
        self.check(f'cuerpos ({n}): sin claves propias del H9 (subject_key, member_id, documento, correo, cuerpo de mensaje; solo subjectRef, P9-9)', not propias, joined(propias))
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
    sm.bodies.pop()   # el cuerpo del login lleva el token: no entra en la lista de cuerpos que se buscan fuera
    return token


# ── Studio: las 30 rutas del gobierno de las estrategias (S9, informe-studio.md §2) ──────────────────────────────
# (método, ruta relativa al Studio, cuerpo, estados admitidos CON la bandera encendida, código exigido o None).
# Cuerpos inválidos o recursos inventados en todo lo que podría escribir: ni con el gobierno y publicar encendidos se
# puede aplicar nada. Con la bandera maestra apagada, las 30 responden 409 strategies_disabled.
PUBLICAN = {('POST', f'strategies/versions/{FAKE}/publish'), ('POST', f'strategies/versions/{FAKE}/promote'), ('POST', f'strategies/{FAKE}/resume')}
STRATEGY_ROUTES = [
    ('GET', 'strategies/catalog', None, {200}, None),
    ('GET', 'strategies', None, {200}, None),
    ('POST', 'strategies', {}, {422}, None),
    ('GET', f'strategies/{FAKE}', None, {404}, None),
    ('PUT', f'strategies/{FAKE}/draft', {}, {404, 422}, None),
    ('POST', f'strategies/{FAKE}/validate', {}, {404, 422}, None),
    ('POST', f'strategies/{FAKE}/versions', {}, {404, 422}, None),
    ('POST', f'strategies/{FAKE}/simulations', {}, {404, 422}, None),
    ('GET', f'strategies/versions/{FAKE}', None, {404}, None),
    ('POST', f'strategies/versions/{FAKE}/simulations', {}, {404, 422}, None),
    ('GET', f'strategies/versions/{FAKE}/simulations', None, {404}, None),
    ('POST', f'strategies/versions/{FAKE}/approve', {}, {404, 422}, None),
    ('POST', f'strategies/versions/{FAKE}/reject', {}, {404, 422}, None),
    ('POST', f'strategies/versions/{FAKE}/publish', {'clientRequestId': FAKE, 'expectedContentHash': ZEROS64}, {409}, 'strategies_publishing_disabled'),
    ('POST', f'strategies/versions/{FAKE}/promote', {'clientRequestId': FAKE, 'evidenceHash': ZEROS64}, {409}, 'strategies_publishing_disabled'),
    ('GET', f'strategies/versions/{FAKE}/canary-evidence', None, {404}, None),
    ('POST', f'strategies/{FAKE}/pause', {}, {404, 422}, None),
    ('POST', f'strategies/{FAKE}/resume', {}, {409}, 'strategies_publishing_disabled'),
    ('POST', f'strategies/{FAKE}/rollback', {}, {404, 422}, None),
    ('GET', f'strategies/{FAKE}/publications', None, {404}, None),
    ('POST', f'strategies/{FAKE}/archive', {}, {404, 422}, None),
    ('GET', 'strategies/templates', None, {200}, None),
    ('POST', 'strategies/templates', {}, {422}, None),
    ('GET', f'strategies/templates/{FAKE}', None, {404}, None),
    ('POST', f'strategies/templates/{FAKE}/approve', {}, {404, 422}, None),
    ('POST', f'strategies/templates/{FAKE}/reject', {}, {404, 422}, None),
    ('GET', 'strategies/person-bindings', None, {200}, None),
    ('POST', 'strategies/person-bindings', {}, {422}, None),
    ('POST', f'strategies/person-bindings/{FAKE}/verify', {}, {404, 422}, None),
    ('POST', f'strategies/person-bindings/{FAKE}/revoke', {}, {404, 422}, None),
]


def check_strategies_without_session(sm):
    bad = []
    for method, rel, body, _, _ in STRATEGY_ROUTES:
        st, _, _ = sm.call(method, f'{S}/{rel}', body, label=f'{S}/{rel}')
        if st != 401:
            bad.append(f'{method} {rel.replace(FAKE, "<inventado>")}={st}')
    gets = sum(1 for m, *_ in STRATEGY_ROUTES if m == 'GET')
    sm.check(f'las {len(STRATEGY_ROUTES)} rutas del gobierno sin sesión → 401 ({gets} GET y {len(STRATEGY_ROUTES) - gets} POST/PUT)', not bad, ', '.join(bad)[:240])


def check_strategies_with_session(sm, token):
    st, b, _ = sm.call('GET', f'{S}/strategies/catalog', token=token, label=f'{S}/strategies/catalog')
    VIVO['strategies'] = st == 200
    if st == 200:
        pub = busca(b, {'publishing'})
        if isinstance(pub, dict) and isinstance(pub.get('enabled'), bool):
            VIVO['publishing'] = pub['enabled']
    esperado = ESPERA[FASE]['strategies']
    if esperado is False:
        sm.check('GET strategies/catalog con la bandera maestra del gobierno apagada → 409 strategies_disabled (fase 1)',
                 st == 409 and code_of(b) == 'strategies_disabled', f'HTTP {st} {code_of(b) or ""}'.strip())
    else:
        sm.info('gobierno de las estrategias (ULTRON_STUDIO_STRATEGIES_ENABLED)', 'encendido' if VIVO['strategies'] else f'apagado (HTTP {st} {code_of(b) or ""})'.strip())
    if VIVO['strategies']:
        sm.info('publicar/promover/reanudar (ULTRON_STUDIO_STRATEGIES_PUBLISHING_ENABLED), según el catálogo',
                {True: 'encendido', False: 'apagado', None: 'el catálogo no lo dice (se admiten las dos respuestas)'}[VIVO['publishing']])
    bad = []
    for method, rel, body, admitidos, code in STRATEGY_ROUTES:
        if (method, rel) == ('GET', 'strategies/catalog'):
            continue
        buckets = None
        if (method, rel) in PUBLICAN or f'{S}/{rel}' in EVIDENCE_PATHS:
            buckets = ('write' if method != 'GET' else 'read',) + (('evidence',) if f'{S}/{rel}' in EVIDENCE_PATHS else ())
        st, b, _ = sm.call(method, f'{S}/{rel}', body, token, label=f'{S}/{rel}', buckets=buckets)
        if not VIVO['strategies']:
            ok = st == 409 and code_of(b) == 'strategies_disabled'
        elif (method, rel) in PUBLICAN:
            if VIVO['publishing'] is False:
                ok = st == 409 and code_of(b) == code
            elif VIVO['publishing'] is True:
                ok = st in {404, 409, 422} and st < 500   # inventado, fuera de ventana o inválido: nunca se aplica
            else:
                ok = st in {404, 409, 422}
        else:
            ok = st in admitidos
        if st in (200, 201, 202, 204) and method != 'GET':
            ok = False
        if not ok:
            bad.append(f'{method} {rel.replace(FAKE, "<inventado>")}={st} {code_of(b) or ""}'.strip())
    pista = ' · 403 forbidden en todas: faltan las 30 entradas de AuthorizationMap (auth:coverage --unmapped; informe-studio.md §2)' if bad and all('=403 ' in x for x in bad) else ''
    if not VIVO['strategies']:
        sm.check(f'las otras {len(STRATEGY_ROUTES) - 1} rutas del gobierno con sesión → 409 strategies_disabled (también las lecturas; nada se lee ni se escribe)', not bad, (' · '.join(bad)[:300] + pista))
    else:
        sm.check(f'las otras {len(STRATEGY_ROUTES) - 1} rutas del gobierno con sesión: lecturas 200, inventados 404, cuerpos vacíos 422, '
                 + ('publicar/promover/reanudar 409 strategies_publishing_disabled' if VIVO['publishing'] is False else 'publicar/promover/reanudar sin aplicar nada (404/409/422)'),
                 not bad, ' · '.join(bad)[:400])
        st, b, _ = sm.call('GET', f'{S}/strategies', token=token, label=f'{S}/strategies')
        d = data_of(b)
        total = (b.get('meta') or {}).get('total') if isinstance(b, dict) and isinstance(b.get('meta'), dict) else (b.get('total') if isinstance(b, dict) else None)
        if ESPERA[FASE]['listas_vacias']:
            sm.check('GET strategies: ninguna estrategia (fase 1)', st == 200 and d == [] , f'HTTP {st} · total {total}')
        else:
            sm.info('GET strategies (fase 2: puede haber estrategias del canario)', f'HTTP {st} · total {total}')


# ── Ejecución: las 13 rutas de B9-run (/api/admin/ultron-studio/outreach/**, L9-57; 9 GET y 4 POST) ──────────────
# (método, ruta relativa, cuerpo, estados admitidos CON LA MAESTRA ENCENDIDA). Con la maestra apagada (fase 1) las 12
# responden 409 outreach_disabled (L9-47, L9-68: `requireEnabled()` tras la llave y antes de validar o buscar; `status`
# no la lleva). Un POST nunca da 2xx; el 404 solo donde el recurso se inventa.
EXEC_ROUTES = [
    ('GET', 'metrics', None, {200, 409}),
    ('GET', 'enrollments', None, {200, 409}),
    ('GET', f'enrollments/{FAKE}', None, {404, 409}),
    ('GET', f'decisions/{FAKE}', None, {404, 409}),
    ('GET', 'jobs', None, {200, 409}),
    ('GET', 'brakes', None, {200, 409}),
    ('POST', 'brakes', {}, {409, 422}),
    ('POST', f'brakes/{FAKE}/release', {}, {404, 409, 422}),
    ('GET', f'people/{ZEROS64}/timeline', None, {200, 409}),   # un seudónimo desconocido → 200 con lista vacía (sin oráculo, L9-57 Q-4)
    ('GET', 'assisted/queue', None, {200, 409}),
    ('POST', f'assisted/{FAKE}/claim', {}, {404, 409, 422}),
    ('POST', f'assisted/{FAKE}/outcome', {}, {404, 409, 422}),
]
LISTAS = {'enrollments', 'jobs', 'brakes'}
# Los canales de `data.config.channels` que, en vivo, llegarían a una persona: con la constante cerrada nunca `live`.
CANALES_PERSONAS = ('whatsapp_template', 'voice_call')


def check_exec_with_session(sm, token):
    st, b, _ = sm.call('GET', f'{C}/status', token=token, label=f'{C}/status')
    if st == 404:
        VIVO['exec'] = False
        sm.check('GET ultron-studio/outreach/status existe (las 13 rutas de la ejecución viajan en la fase 1, L9-57/L9-59)', False,
                 'HTTP 404: la ejecución no está en este despliegue')
        sm.skip('las otras 12 rutas de la ejecución', 'sin GET status no se prueban')
        return
    VIVO['exec'] = True
    E = ESPERA[FASE]
    d = data_of(b)
    cfg = d.get('config') if isinstance(d, dict) and isinstance(d.get('config'), dict) else None
    if sm.check('GET ultron-studio/outreach/status → 200 con data.config (OutreachConfig::status)', st == 200 and cfg is not None, f'HTTP {st} {code_of(b) or ""}'.strip()):
        sm.check(f'status: data.config.mode «{E["mode"]}» (fase {FASE})', cfg.get('mode') == E['mode'], f'mode={json.dumps(cfg.get("mode"))}')
        VIVO['exec_enabled'] = cfg.get('enabled') is True
        sm.check(f'status: data.config.enabled {"true" if E["enabled"] else "false"} (COMMERCIAL_AUTOMATION_ENABLED, fase {FASE})', cfg.get('enabled') is E['enabled'], f'enabled={json.dumps(cfg.get("enabled"))}')
        sm.check('status: la constante de envío cerrada (data.config.sendsEnabledInCode = false)', cfg.get('sendsEnabledInCode') is False, f'sendsEnabledInCode={json.dumps(cfg.get("sendsEnabledInCode"))}')
        canales = cfg.get('channels') if isinstance(cfg.get('channels'), dict) else {}
        vivos = [k for k in CANALES_PERSONAS if isinstance(canales.get(k), dict) and canales[k].get('live') is True]
        # OPS9-c: desde L9-80 (W9) y L9-81 (V9) los dos canales automáticos están DECLARADOS en
        # OutreachSendGate::LIVE_CHANNELS (`live` = su puerta está implementada): implementado no es abierto. Lo que impide
        # que algo salga hacia una persona es la constante (sendsEnabledInCode = false, arriba) y, en la fase 1, la bandera
        # de cada canal (abajo). `live` se informa; la comprobación es la de la constante con los dos canales presentes.
        sm.info('status: canales automáticos declarados (live = puerta implementada; implementado no es abierto, L9-80/L9-81)', ', '.join(vivos) or 'ninguno')
        sm.check('status: ningún canal hacia personas puede salir (whatsapp_template y voice_call presentes y la constante de envío cerrada)',
                 bool(canales) and all(isinstance(canales.get(k), dict) for k in CANALES_PERSONAS) and cfg.get('sendsEnabledInCode') is False,
                 f'declarados: {", ".join(vivos) or "ninguno"} · sendsEnabledInCode={json.dumps(cfg.get("sendsEnabledInCode"))}' if canales else 'sin channels')
        if FASE == '1':
            apagados = {k: (canales[k].get('enabled') if isinstance(canales.get(k), dict) else None) for k in ('assisted',) + CANALES_PERSONAS}
            sm.check('status: canales apagados en la fase 1 (assisted, whatsapp_template, voice_call)', all(v is False for v in apagados.values()), json.dumps(apagados))
            sm.check('status: programador y monitor apagados en la fase 1', cfg.get('scheduler') is False and cfg.get('monitor') is False,
                     f'scheduler={json.dumps(cfg.get("scheduler"))} monitor={json.dumps(cfg.get("monitor"))}')
        frenos = d.get('brakes') if isinstance(d.get('brakes'), dict) else {}
        abiertos = frenos.get('open') if isinstance(frenos.get('open'), list) else None
        sm.info('status: frenos abiertos (data.brakes.open)', 'ilegible' if abiertos is None else str(len(abiertos)))
        cadena = d.get('chain') if isinstance(d.get('chain'), dict) else {}
        cabeza = cadena.get('head') if isinstance(cadena.get('head'), dict) else {}
        sm.check('status: la cabeza de la cadena de estrategias verifica (data.chain.head.verified; sin nada publicado, verifica)', cabeza.get('verified') is True, json.dumps(cabeza)[:160])
    maestra = VIVO['exec_enabled']   # lo que la API dijo en status, no lo que la fase espera
    bad, vacias = [], []
    for method, rel, body, admitidos in EXEC_ROUTES:
        st, b, _ = sm.call(method, f'{C}/{rel}', body, token, label=f'{C}/{rel}')
        if not maestra:
            # L9-47: con la maestra apagada, la puerta responde antes de validar o buscar: 409 en las 12, sin excepción.
            ok = st == 409 and code_of(b) == 'outreach_disabled'
        else:
            ok = st in admitidos and not (method != 'GET' and 200 <= st < 300)
        if not ok:
            bad.append(f'{method} {rel.replace(FAKE, "<inventado>").replace(ZEROS64, "<ceros>")}={st} {code_of(b) or ""}'.strip())
        if maestra and rel in LISTAS and st == 200 and E['listas_vacias']:
            d = data_of(b)
            # enrollments y jobs paginan (LengthAwarePaginator: data.data, data.total); brakes es una lista en data.
            items = d.get('data') if isinstance(d, dict) else d
            if not (isinstance(items, list) and items == []):
                vacias.append(f'{rel}: {len(items) if isinstance(items, list) else "sin data"}')
    if not maestra:
        sm.check(f'las otras {len(EXEC_ROUTES)} rutas de la ejecución con sesión y la maestra apagada → 409 outreach_disabled en todas (L9-47: también las lecturas; nada se lee ni se escribe)', not bad, ' · '.join(bad)[:400])
    else:
        sm.check(f'las otras {len(EXEC_ROUTES)} rutas de la ejecución con sesión y la maestra encendida: listas 200, inventados 404, cuerpos vacíos 422 (el freno global pide motivo: no se abre ninguno), nunca un 2xx en un POST', not bad, ' · '.join(bad)[:400])
        if E['listas_vacias']:
            sm.check('maestra encendida en la fase 1 (ya es un FALLO arriba): inscripciones, trabajos y frenos vacíos', not vacias, ', '.join(vacias))


def check_exec_without_session(sm):
    if not VIVO['exec']:
        return
    bad = []
    for method, rel, body, _ in [('GET', 'status', None, None)] + EXEC_ROUTES:
        st, _, _ = sm.call(method, f'{C}/{rel}', body, label=f'{C}/{rel}')
        if st != 401:
            bad.append(f'{method} {rel.replace(FAKE, "<inventado>").replace(ZEROS64, "<ceros>")}={st}')
    sm.check(f'las {len(EXEC_ROUTES) + 1} rutas de la ejecución sin sesión → 401', not bad, ', '.join(bad)[:240])


# ── El canal SSE del Studio (SSE9) ─────────────────────────────────────────────────────────────────────────────

def check_stream_with_session(sm, token):
    st, b, _ = sm.call('GET', '/admin/stream-ticket', token=token, label='/admin/stream-ticket', keep=False)
    tique = busca(b, {'ticket'}) if st == 200 else busca.NADA
    if st == 404:
        sm.skip('canal SSE del Studio', 'GET admin/stream-ticket → 404: sin tiques no hay canal que probar')
        return
    # El tique es `Crypt::encryptString` (base64 con +, / y =, unos 300 caracteres; StreamTicketService::issue): va
    # codificado en la consulta y nunca se imprime.
    if not (st == 200 and isinstance(tique, str) and re.fullmatch(r'[A-Za-z0-9+/=._~-]{16,4000}', tique)):
        sm.check('GET admin/stream-ticket → 200 con un tique', False, f'HTTP {st} · tique {"ausente" if tique is busca.NADA else type(tique).__name__}')
        return
    # El canal, con el tique: en la fase 1, 409 stream_disabled. Si estuviera encendido, emitiría hasta 55 s: se corta a
    # los 4 s y se informa (no se lee nada).
    st, b, _ = sm.call('GET', f'{S}/stream?ticket={urllib.parse.quote(tique, safe="")}&topics=ledger', label=f'{S}/stream?ticket=<tique>&topics=ledger', timeout=4)
    if st == 404:
        VIVO['stream_route'] = False
        sm.skip('canal SSE del Studio (GET ultron-studio/stream)', 'HTTP 404: SSE9 no está en este despliegue')
        sm.statuses.pop()
        return
    VIVO['stream_route'] = True
    if ESPERA[FASE]['stream'] is False:
        sm.check('GET ultron-studio/stream con tique y la bandera apagada → 409 stream_disabled (el CRM vuelve al sondeo)',
                 st == 409 and code_of(b) == 'stream_disabled', f'HTTP {st} {code_of(b) or ""}'.strip())
    elif st == 0:
        sm.statuses.pop()
        sm.info('canal SSE encendido: abre y emite (cortado a los 4 s; el smoke no lo lee)', '')
    else:
        sm.info('GET ultron-studio/stream con tique', f'HTTP {st} {code_of(b) or ""}'.strip())


def check_stream_without_ticket(sm):
    if not VIVO['stream_route']:
        return
    st, b, _ = sm.call('GET', f'{S}/stream?topics=ledger', label=f'{S}/stream (sin tique)')
    sm.check('GET ultron-studio/stream sin tique → 401 (o 409 si la bandera va antes del tique)', st in (401, 409), f'HTTP {st} {code_of(b) or ""}'.strip())


# ── La ola M-1 + plantillas de Meta (deploy-m1-backend.sh; ver la cabecera) ─────────────────────────────────────

def check_m1_prompts(sm, token):
    """`GET status` → `data.engine.prompts` (dtos-studio-prompts.md §2). Solo ese subárbol entra en el barrido de fugas:
    el resto de `status` lo barren los smokes del H5 y del H7, con sus propias reglas."""
    st, b, _ = sm.call('GET', f'{S}/status', token=token, label=f'{S}/status', keep=False)
    d = data_of(b)
    eng = d.get('engine') if st == 200 and isinstance(d, dict) else None
    if not isinstance(eng, dict):
        sm.check('GET ultron-studio/status → 200 con data.engine', False, f'HTTP {st} {code_of(b) or ""}'.strip())
        return
    pr = eng.get('prompts')
    if not isinstance(pr, dict):
        if M1:
            sm.check('status: engine.prompts presente (Enmienda M-1; si su lectura falla la clave no viene: mirar el log)', False, 'ausente')
        else:
            sm.skip('status: engine.prompts (Enmienda M-1)', 'no viene: código anterior a la ola')
        return
    sm.bodies.append((f'GET {S}/status · engine.prompts', json.dumps(pr, ensure_ascii=False), pr))
    cur = pr.get('current') if isinstance(pr.get('current'), dict) else {}
    sm.check(f'status: engine.prompts.key = {PROMPTS_KEY}', pr.get('key') == PROMPTS_KEY, f'key={json.dumps(pr.get("key"))}')
    sm.check(f'status: engine.prompts con la base {PROMPTS_BASE}, el compilador 3 y la huella Legacy {LEGACY_BUNDLE[:8]}…',
             pr.get('base') == PROMPTS_BASE and pr.get('compiler') == 3 and pr.get('legacyBundleHash') == LEGACY_BUNDLE,
             f'base={json.dumps(pr.get("base"))} compiler={json.dumps(pr.get("compiler"))} legacy={str(pr.get("legacyBundleHash"))[:8]}')
    if PROMPTS_KEY == 'off':
        sm.check('status: engine.prompts.current con la llave apagada = legacy por prompts_off, sin versión ni huella (decide no ofrece nada)',
                 cur == {'mode': 'legacy', 'version': None, 'bundleHash': None, 'reason': 'prompts_off'}, json.dumps(cur)[:160])
    else:
        sm.info('status: engine.prompts.current (llave en canary)', json.dumps({k: cur.get(k) for k in ('mode', 'reason')}))
    turnos = pr.get('lastTurns')
    malos = []
    if not isinstance(turnos, list) or len(turnos) > 20:
        malos.append(f'lastTurns={type(turnos).__name__}')
    else:
        for i, t in enumerate(turnos):
            if not (isinstance(t, dict) and set(t) == TURN_KEYS and re.fullmatch(r'\d{4}-\d{2}-\d{2}T\d{2}:\d{2}Z', str(t.get('at', '')))
                    and t.get('state') in PROMPT_STATES):
                malos.append(f'turno {i}')
    sm.check('status: engine.prompts.lastTurns ≤ 20 y cada turno solo {at, declared, offered, observed, state} (sin ids ni textos)', not malos,
             ', '.join(malos)[:200] if malos else f'{len(turnos)} turnos')


def check_m1_meta_with_session(sm, token):
    """Las 4 rutas de las plantillas de Meta con sesión: cerradas y sin red (dtos-para-frontend.md §0, «Cerraduras»)."""
    st, b, _ = sm.call('GET', f'{S}/strategies/templates/meta/status', token=token, label=f'{S}/strategies/templates/meta/status')
    if st == 404:
        VIVO['meta_route'] = False
        if M1:
            sm.check('GET strategies/templates/meta/status existe (las 4 rutas de las plantillas de Meta: 121 → 125)', False, 'HTTP 404')
        else:
            sm.skip('las 4 rutas de las plantillas de Meta', 'HTTP 404: código anterior a la ola')
        return
    VIVO['meta_route'] = True
    cerrado = False   # True solo si consta que NADA de estas rutas puede hablar con Meta
    if not VIVO['strategies']:
        cerrado = sm.check('GET strategies/templates/meta/status con las estrategias apagadas → 409 strategies_disabled (la puerta va antes del módulo)',
                           st == 409 and code_of(b) == 'strategies_disabled', f'HTTP {st} {code_of(b) or ""}'.strip())
    else:
        d = data_of(b)
        if sm.check('GET strategies/templates/meta/status → 200 (responde también con el módulo apagado)', st == 200 and isinstance(d, dict),
                    f'HTTP {st} {code_of(b) or ""}'.strip()):
            flags = {k: d.get(k) for k in ('enabled', 'syncEnabled', 'submitEnabled')}
            como_toca = sm.check(f'meta/status: el módulo en «{META_EXPECT}» (enabled, syncEnabled, submitEnabled = {", ".join(json.dumps(v) for v in META_FLAGS[META_EXPECT])})',
                                 tuple(flags.values()) == META_FLAGS[META_EXPECT], json.dumps(flags))
            cerrado = como_toca and META_EXPECT == 'off'
            subs = d.get('submissions')
            detalle = f'lastProbe={"null" if d.get("lastProbe") is None else "sí"} submissions={json.dumps(subs)[:120]}'
            if META_EXPECT == 'off' and not META_EXPECT_RAW:
                sm.check('meta/status: ninguna sonda en 24 h y ningún envío a Meta', d.get('lastProbe') is None and isinstance(subs, dict) and all(v == 0 for v in subs.values()), detalle)
            else:
                sm.info(f'meta/status: la última sonda y los envíos (informativo con SMOKE_EXPECT_META_TEMPLATES={META_EXPECT})', detalle)
            sm.info('meta/status (informativo, sin secretos)', json.dumps({k: d.get(k) for k in ('driver', 'graphVersion', 'configured', 'configurationProblem', 'credentialSource', 'metaEnabled')})[:200])
    bad = []
    for method, rel, body in META_ROUTES[1:]:
        if rel.endswith('/probe') and not cerrado:
            if VIVO['strategies'] and META_EXPECT != 'off':
                sm.info('POST strategies/templates/meta/probe', f'no se toca con el módulo en «{META_EXPECT}»: hablaría con Meta')
            else:
                sm.skip('POST strategies/templates/meta/probe', 'no consta que el módulo esté apagado: la sonda hablaría con Meta y el smoke no la toca')
            continue
        if rel.endswith('/refresh') and not cerrado and VIVO['strategies']:
            # Con el módulo encendido, «Comprobar ahora» llega a la puerta de la persona verificada, que deja en el libro el
            # intento denegado de la cuenta del smoke (`person_binding.required_denied`): sería una fila a su nombre.
            sm.info(f'POST strategies/templates/<inventado>/meta/refresh', f'no se toca con el módulo en «{META_EXPECT}»: la puerta de la persona dejaría en el libro el intento denegado de la cuenta del smoke')
            continue
        st, b, _ = sm.call(method, f'{S}/{rel}', body, token, label=f'{S}/{rel}')
        if not VIVO['strategies']:
            ok = st == 409 and code_of(b) == 'strategies_disabled'
        elif cerrado:
            ok = st == 409 and code_of(b) == 'meta_templates_disabled'
        else:
            ok = st in (403, 404, 409, 422)   # módulo encendido: cuerpo vacío o uuid inventado; nunca se aplica nada
        if 200 <= st < 300:
            ok = False
        if not ok:
            bad.append(f'{method} {rel.replace(FAKE, "<inventado>")}={st} {code_of(b) or ""}'.strip())
    esperado = 'strategies_disabled' if not VIVO['strategies'] else ('meta_templates_disabled' if cerrado else '403/404/409/422')
    sm.check(f'{"la sonda, enviar y comprobar" if cerrado else "enviar (cuerpo vacío: 422 antes del servicio)"} de las plantillas de Meta con sesión → {esperado} (nada sale hacia Meta, ninguna fila)',
             not bad, ' · '.join(bad)[:300])


def check_m1_meta_without_session(sm):
    if VIVO.get('meta_route') is False:
        return
    bad = []
    for method, rel, body in META_ROUTES:
        st, _, _ = sm.call(method, f'{S}/{rel}', body, label=f'{S}/{rel}')
        if st != 401:
            bad.append(f'{method} {rel.replace(FAKE, "<inventado>")}={st}')
    if bad and all(x.endswith('=404') for x in bad) and not M1:
        sm.skip('las 4 rutas de las plantillas de Meta sin sesión', 'HTTP 404: código anterior a la ola')
        return
    sm.check(f'las {len(META_ROUTES)} rutas de las plantillas de Meta sin sesión → 401', not bad, ', '.join(bad)[:240])


# ── Presupuestos (GET con sesión) ──────────────────────────────────────────────────────────────────────────────

def measure(sm, token, rel, label, samples):
    tiempos, tamanos, previa = [], [], None
    for i in range(samples['warmup'] + samples['n']):
        if previa is not None:
            espera = samples['minIntervalSeconds'] - (time.perf_counter() - previa)
            if espera > 0:
                time.sleep(espera)
        previa = time.perf_counter()
        st, body, _ = sm.call('GET', rel, token=token, label=label, keep=False)
        if st != 200:
            return st, body, tiempos, tamanos
        if i >= samples['warmup']:
            tiempos.append(sm.last_ms)
            tamanos.append(sm.last_bytes)
    return 200, None, tiempos, tamanos


def judge(route, tiempos, tamanos):
    med, mx, by = statistics.median(tiempos), max(tiempos), max(tamanos)
    tiempo = []
    if med > route['p50Ms']:
        tiempo.append(f'mediana {med:.1f} > {route["p50Ms"]:g}')
    if mx > route['p95Ms']:
        tiempo.append(f'máximo {mx:.1f} > {route["p95Ms"]:g}')
    otros = [f'{by} B > {route["maxBytes"]:g}'] if by > route['maxBytes'] else []
    return med, mx, by, tiempo + otros, bool(tiempo) and not otros


def check_budgets(sm, token, doc, origen, huella):
    rutas = doc['routes']
    base = doc['samples']
    print(f"== Presupuestos de rendimiento · {origen} · {doc.get('version', '¿sin versión?')} · sha256 {huella} · {len(rutas)} rutas · "
          f"{base['warmup']} + {base['n']} muestras, ≥ {base['minIntervalSeconds']:g} s", flush=True)
    t0, espera0 = time.monotonic(), sm.waited
    tally = {'OK': 0, 'FALLO': 0, 'OMITIDO': 0}
    informe = []
    for route in rutas:
        nombre = f"presupuesto {route['name']}"
        intentos = []
        while True:
            st, cuerpo, tiempos, tamanos = measure(sm, token, route['path'], route['name'].replace('GET ', '', 1), route['_samples'])
            if st != 200:
                break
            med, mx, by, malos, solo_tiempo = judge(route, tiempos, tamanos)
            intentos.append((med, mx, by, malos))
            if malos and solo_tiempo and len(intentos) == 1:
                continue
            break
        if st == 409 and code_of(cuerpo) == 'strategies_disabled':
            sm.skip(nombre, 'gobierno apagado (409 strategies_disabled): lo esperado en la fase 1')
            tally['OMITIDO'] += 1
            informe.append({'name': route['name'], 'path': route['path'], 'status': 'OMITIDO', 'reason': 'strategies_disabled'})
            continue
        if st == 409 and code_of(cuerpo) == 'outreach_disabled':
            sm.skip(nombre, 'automatización comercial apagada (409 outreach_disabled, L9-47): lo esperado en la fase 1')
            tally['OMITIDO'] += 1
            informe.append({'name': route['name'], 'path': route['path'], 'status': 'OMITIDO', 'reason': 'outreach_disabled'})
            continue
        if st == 404 and route['path'].startswith(C + '/') and not VIVO['exec']:
            sm.skip(nombre, 'la ejecución del H9 no está en este despliegue (404)')
            tally['OMITIDO'] += 1
            informe.append({'name': route['name'], 'path': route['path'], 'status': 'OMITIDO', 'reason': 'not_deployed'})
            continue
        if st != 200:
            sm.check(nombre, False, f'HTTP {st} {code_of(cuerpo) or ""}'.strip() + ' (se esperaba 200)')
            tally['FALLO'] += 1
            informe.append({'name': route['name'], 'path': route['path'], 'status': 'FALLO', 'reason': f'HTTP {st}'})
            continue
        med, mx, by, malos = intentos[-1]
        previo = f' · repetida: la primera medida dio {", ".join(intentos[0][3])}' if len(intentos) == 2 else ''
        texto = (f"mediana {med:.1f} ms ≤ {route['p50Ms']:g} · máximo {mx:.1f} ms ≤ {route['p95Ms']:g} · {by} B ≤ {route['maxBytes']:g}"
                 if not malos else ', '.join(malos))
        sm.check(f'{nombre}: {texto}', not malos, f'n {len(tiempos)}{previo} · calibración: p50 ≤ {propuesta(med)} · p95 ≤ {propuesta(mx)}')
        tally['FALLO' if malos else 'OK'] += 1
        informe.append({'name': route['name'], 'path': route['path'], 'status': 'FALLO' if malos else 'OK', 'reason': ', '.join(malos),
                        'n': len(tiempos), 'medianMs': round(med, 1), 'maxMs': round(mx, 1), 'bytes': by, 'retried': len(intentos) == 2,
                        'p50Ms': route['p50Ms'], 'p95Ms': route['p95Ms'], 'maxBytes': route['maxBytes'],
                        'proposedP50Ms': propuesta(med), 'proposedP95Ms': propuesta(mx)})
    dur, esperas = time.monotonic() - t0, sm.waited - espera0
    print(f"PRESUPUESTOS: {tally['OK']} OK · {tally['FALLO']} FALLO · {tally['OMITIDO']} OMITIDO · {dur:.0f} s"
          + (f' (de ellos {esperas:.0f} s esperando a los cupos)' if esperas else ''), flush=True)
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
        print('uso: SMOKE_EMAIL=… SMOKE_PW=… smoke-h9.py superadmin')
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
    if FASE not in ESPERA:
        print('SMOKE_PHASE = 1 | 2')
        return 2
    if META_EXPECT not in META_FLAGS:
        print('SMOKE_EXPECT_META_TEMPLATES = off | read | full')
        return 2
    try:
        h6 = load_h6()
    except (OSError, ImportError, SyntaxError) as e:
        print(f'no se pudo cargar el detector de smoke-h6.py ({type(e).__name__}): SMOKE_H6_PY=…')
        return 2
    origen = budgets_path()
    budgets = huella = None
    if origen:
        try:
            budgets, huella = load_budgets(origen)
        except (OSError, ValueError, KeyError, TypeError) as e:
            detalle = str(e) if isinstance(e, ValueError) and not isinstance(e, json.JSONDecodeError) else type(e).__name__
            print(f'no se pudieron cargar los presupuestos de {origen} ({detalle}): SMOKE_BUDGETS=…')
            return 2
    sm = Smoke(h6, canary, bytes.fromhex(salt_hex) if salt_hex else b'', h6.parse_digests(digests_raw))
    print(f'== Smoke del Hito 9 (fase {FASE}) · {part} · {API}', flush=True)
    # El login PRIMERO: las peticiones anónimas suman en el cubo por IP que Laravel comparte con el login del CRM
    # (`throttle:8,1`); medido en el banco del H8 (OPS8-b).
    token = login(sm, email, password)
    if token:
        check_exec_with_session(sm, token)
        check_strategies_with_session(sm, token)
        check_stream_with_session(sm, token)
        check_m1_prompts(sm, token)
        check_m1_meta_with_session(sm, token)
        if budgets is not None:
            check_budgets(sm, token, budgets, origen, huella)
        else:
            sm.skip('presupuestos de rendimiento', 'sin fichero de PERF9 (ops/perf-h9/presupuestos-h9.json, copiado como presupuestos-h9.json junto al smoke; SMOKE_BUDGETS=…): D9-16 queda sin medir en este smoke')
    check_strategies_without_session(sm)
    check_exec_without_session(sm)
    check_stream_without_ticket(sm)
    check_m1_meta_without_session(sm)
    sm.scan()
    c = sm.counts
    print(f"RESUMEN ({part}): {c['OK']} OK · {c['FALLO']} FALLO · {c['OMITIDO']} OMITIDO", flush=True)
    return sm.exit_code()


if __name__ == '__main__':
    sys.exit(main(sys.argv))

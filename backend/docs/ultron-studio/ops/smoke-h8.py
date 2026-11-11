#!/usr/bin/env python3
"""Smoke del Hito 8 de ULTRON STUDIO (voz y telefonía), fase 1: TODO APAGADO.

Comprueba, contra la API desplegada y SIN FILAS POSITIVAS:
  · las 17 rutas de la telefonía SIN CREDENCIALES: las 13 del CRM (/api/admin/voice/*) → 401 con
    `Cache-Control: no-store, private`; las 4 públicas → 401 con su código (`unauthorized`, `invalid_signature`,
    `verdict: unauthorized`), sin límite por IP antes de autenticar (E5-4/E6-4: el smoke manda pocas, lejos de los
    30 fallos por minuto de su cubo); una herramienta con un nombre fuera del patrón → 404; el tope de cuerpo ANTES de
    autenticar (D8-16): 9 000 bytes a la iniciación y a las herramientas (tope 8 KB) y 70 000 al aviso de Twilio
    (64 KB) → 413; y, solo como INFORMACIÓN, quién responde a un cuerpo de 1 MB + 1 en la post-llamada (Laravel, 413
    JSON: nginx deja pasar 1 MB; nginx, 413 HTML: hay que subir su tope antes de la fase 3);
  · las 13 del CRM CON SESIÓN (la cuenta temporal del smoke, Super Admin, solo lectura): el estado con la constante
    cerrada, las 5 banderas apagadas, la cadena sin entradas, el freno suelto y los cupos y el presupuesto a 0; las
    listas de llamadas y contactos vacías; una llamada inventada → 404; las 8 POST con un cuerpo vacío → 422
    `validation_failed` (o 404 al colgar una llamada inventada), que el servidor rechaza ANTES de la puerta;
  · las 21 rutas nuevas del Studio sin sesión → 401 y con sesión: las lecturas → 200 (vacías), un uuid inventado → 404,
    el laboratorio hablado → 409 `voice_lab_disabled` (salvo su estado), publicar y promover → 409
    `voice_publishing_disabled` (E4-1) con un cuerpo válido de uuids inventados, y lo demás → 422 o 404 antes de
    escribir (revertir y apagar no tienen bandera: SOLO cuerpos inválidos, nunca uno que se pueda aplicar);
  · la ruta 45 (`GET integrations/voice`): ElevenLabs en el estado que espera la fase (ELEVENLABS_VERIFIED, E1-12),
    `publishing.enabled` false, las 7 llaves de `can` (todas true para el Super Admin);
  · los PRESUPUESTOS de rendimiento de PERF8 (perf-h8/presupuestos-h8.json, el único: el provisional de OPS8-b se
    retiró al llegar el de PERF8, que cubre todas sus rutas): por ruta, `samples.warmup` GET sin contar y `samples.n` cronometradas, en serie y separadas al menos
    `minIntervalSeconds`; FALLO si la mediana supera p50Ms, el máximo supera p95Ms, el cuerpo supera maxBytes o el
    estado no es 200. Un FALLO SOLO de tiempo se mide otra vez, una sola, y vale la repetición. Una ruta del laboratorio
    hablado que responde 409 `voice_lab_disabled` (la fase 1: el laboratorio apagado) queda OMITIDA, no FALLO;
  · ninguna fuga en los cuerpos (el detector del H6, `find_leaks`), ningún 5xx y ningún 429.
La red saliente, el monitor del H7, la base (recuentos exactos antes y después) y `voice:doctor --telefonia` los
comprueba el lanzador (smoke-run-h8.sh) en el servidor.

POLÍTICA: SIN FILAS POSITIVAS. Las únicas peticiones que no son GET:
  · el login de la cuenta de smoke;
  · las 4 públicas sin credencial (401, sin registrar nada salvo una línea de aviso en el canal `voice`), 3 cuerpos
    por encima de su tope (413 antes del controlador) y la sonda de 1 MB (413);
  · 8 POST de la telefonía con sesión y cuerpo vacío (422 antes de la puerta) o a una llamada inventada (404);
  · 13 POST del Studio: publicar y promover con la bandera apagada (409 antes de compilar ni de salir a la red); el
    laboratorio apagado (409 antes de validar); y el resto con cuerpos que la validación rechaza (422) o recursos
    inventados (404). Revertir y apagar la voz, que no dependen de la bandera, SOLO con cuerpos inválidos.
La fase de presupuestos solo hace GET a las rutas que admite el validador (un fichero manipulado no convierte el smoke
en otra cosa).

Uso:   SMOKE_EMAIL=… SMOKE_PW=… python3 smoke-h8.py superadmin        (la contraseña SOLO por entorno, nunca en argv)
Variables: SMOKE_API · SMOKE_H6_PY (smoke-h6.py, del que se toma el detector) · SMOKE_BUDGETS · SMOKE_BUDGETS_OUT ·
           SMOKE_CANARY_ID · SMOKE_SECRET_SALT y SMOKE_SECRET_DIGESTS (como el H6) · SMOKE_TOKEN_FILE ·
           SMOKE_EXPECT_VOICE_STATE (por defecto ELEVENLABS_VERIFIED, E1-12) · SMOKE_VOICE_PROFILES_BEFORE (B-T7, OPS8-e:
           los perfiles de voz de la base ANTES del smoke, que pone el lanzador; con 0, «ningún perfil» es estricto en la
           fase 1; con alguno, o sin el dato, es informativo: la fase 1 ya desplegada admite perfiles aprobados, E16-30).
Código: 0 todo OK · 1 algún FALLO · 3 sin fallos pero con algo OMITIDO · 2 uso incorrecto (también un JSON de
presupuestos ausente o mal formado: no se hace ninguna petición).

FASES (OPS8-c, 09-11-2026): SMOKE_PHASE = 1 (por defecto: todo lo de arriba, sin cambios) · 2a (el laboratorio hablado
encendido, sin llamadas: la constante cerrada, entrantes, salientes y Twilio Voice apagados, la cadena sin entradas, los
cupos de llamadas a 0; las piezas de la fase 2 y el monitor de voz, informativos) · 4a (salientes a canarios: la
constante ABIERTA, salientes y Twilio Voice encendidos, entrantes apagadas, el monitor de voz encendido, la parte canary
de la cadena «ok» y la rest «none», cupo de salientes ≥ 1 con presupuesto > 0 y entrantes a 0, el número, el id importado
y la subcuenta puestos, publicar encendido). En 2a y 4a el smoke sigue SIN FILAS POSITIVAS: con su bandera encendida,
NO llama a crear una sesión del laboratorio, ni a cerrarla o juzgarla, ni a publicar o promover (OMITIDO, con el
motivo): esas rutas escriben o gastan. Las lecturas del laboratorio y de la cadena se comprueban con su fase.
Cupos: 1 login (8/min por IP); lecturas con sesión ≤ 90 en cualquier minuto (cupo 120); escrituras con sesión ≤ 25 por
minuto (cupo 30); el cubo de la evidencia (publicar, promover y la evidencia del canario comparten
`ultron-studio-evidence`, 10/min por persona) ≤ 8 por minuto; 1 POST del laboratorio hablado por pasada (su limitador
es de 10 por HORA y persona). Si hace falta, el smoke espera. Dura unos 2 minutos. No toca la sonda de Twilio.
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
V = '/admin/voice'
FAKE = '00000000-0000-4000-8000-0000000008a8'
ZEROS64 = '0' * 64
NO_STORE = 'no-store, private'
EXPECT_VOICE_STATE = os.environ.get('SMOKE_EXPECT_VOICE_STATE', 'ELEVENLABS_VERIFIED').strip()
FASE = os.environ.get('SMOKE_PHASE', '1').strip() or '1'
# B-T7 (OPS8-e): los perfiles de voz que la base tenía ANTES del smoke (lo pasa el lanzador, que cuenta la tabla). «0» hace
# estricta la expectativa «ningún perfil» de la fase 1; otro número (o sin el dato) la deja en información.
PERFILES_ANTES = os.environ.get('SMOKE_VOICE_PROFILES_BEFORE', '').strip()
if not re.fullmatch(r'[0-9]{1,9}', PERFILES_ANTES):
    PERFILES_ANTES = ''
# Lo que tiene que decir la telefonía en cada fase. None = cualquiera (se informa). Ver la cabecera, «FASES».
ESPERA = {
    '1': {'calls': False, 'flags': {'inbound': False, 'outbound': False, 'twilioVoice': False, 'lab': False, 'monitor': False},
          'cadena': 'none', 'cupos': 'cero', 'piezas': 'ninguna', 'publicar': False, 'listas_vacias': True},
    '2a': {'calls': False, 'flags': {'inbound': False, 'outbound': False, 'twilioVoice': False, 'lab': True, 'monitor': None},
           'cadena': 'none', 'cupos': 'cero', 'piezas': None, 'publicar': False, 'listas_vacias': False},   # la prueba O-2 de la iniciación deja una fila denied
    '4a': {'calls': True, 'flags': {'inbound': False, 'outbound': True, 'twilioVoice': True, 'lab': None, 'monitor': True},
           'cadena': 'canario', 'cupos': 'salientes', 'piezas': 'completas', 'publicar': True, 'listas_vacias': False},
}
VIVO = {'lab': False, 'publicar': False, 'contacts': None}   # lo que la API dice de verdad (lo leen las rutas del Studio para no escribir)
# Las 5 banderas de la telefonía del H8 (GET admin/voice/status, `flags`); el código de B-T4 añade `contacts`.
BANDERAS = {'inbound', 'outbound', 'twilioVoice', 'lab', 'monitor'}
# Las tres escrituras de contactos que cierra VOICE_CONTACTS_ENABLED (B-T4): alta, carga y confirmación del consentimiento.
ESCRIBEN_CONTACTOS = {'/admin/voice/contacts', f'/admin/voice/contacts/{FAKE}/consents', f'/admin/voice/contacts/{FAKE}/consents/confirm'}
VOICE_CAPS = 7   # las 7 llaves de D8-22 en `can` de la ruta 45
# Cupos con sesión por minuto (por debajo de los del servidor) y cubos: lectura (Studio 120/min y telefonía
# throttle:120,1), escritura (30/min y throttle:30,1) y la evidencia (10/min por persona: la comparten publicar,
# promover y GET voice/canary-evidence).
# 'tel_ip': TODA petición a /api/admin/voice/* (con sesión o sin ella) cuenta en el MISMO cubo por IP de Laravel
# (`throttle:120,1` y `throttle:30,1` corren antes de que el controlador autentique, y ese cubo es también el del login,
# `throttle:8,1`): medido en el banco por OPS8-b. El smoke no pasa de 25 por minuto ahí.
CUPOS = {'read': 90, 'write': 25, 'evidence': 8, 'tel_ip': 25}
RUTA_PRESUPUESTO = re.compile(r'^/admin/(?:ultron-studio/(?:voice|voice-lab|integrations/voice)(?:/[A-Za-z0-9_-]+)*|voice/[A-Za-z0-9_/-]+)$')
EVIDENCE_PATHS = {S + '/voice/canary-evidence'}


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
    # El de PERF8; junto al smoke solo está en el servidor (el runbook lo copia a /root con ese nombre).
    for p in (os.path.join(aqui, 'perf-h8', 'presupuestos-h8.json'), os.path.join(aqui, 'presupuestos-h8.json')):
        if os.path.isfile(p):
            return p
    return os.path.join(aqui, 'presupuestos-h8.json')


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
            raise ValueError(f'{donde}: «path» tiene que ser una ruta GET de la voz (/admin/ultron-studio/voice…, '
                             f'/admin/ultron-studio/integrations/voice o /admin/voice/…) sin «..», consulta ni marcadores')
        for k in ('p50Ms', 'p95Ms', 'maxBytes'):
            if not _num(r.get(k), 1):
                raise ValueError(f'{donde}: «{k}» tiene que ser un número ≥ 1')
        if r['p95Ms'] < r['p50Ms']:
            raise ValueError(f'{donde}: p95Ms por debajo de p50Ms')
        r['_samples'] = _samples(r['samples'], donde) if 'samples' in r else base
        if r['name'] in vistas:
            raise ValueError(f'{donde}: nombre repetido')
        vistas.add(r['name'])
    pub = doc.get('public401')
    if pub is not None and not (isinstance(pub, dict) and _num(pub.get('maxMs'), 1)):
        raise ValueError('public401 tiene que ser {maxMs ≥ 1}')
    return doc, hashlib.sha256(raw).hexdigest()[:12]


def propuesta(medida):
    """El techo recalibrado (`calibration`): max(1,5 × medida, medida + 50 ms), redondeado hacia arriba."""
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

    def call(self, method, path, body=None, token=None, raw=None, label=None, keep=True, content_type='application/json', buckets=None):
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
        buckets = tuple(buckets or ()) + (('tel_ip',) if path.startswith(V + '/') else ())
        if buckets:
            self.pace(buckets)
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
        lbl = f'{method} {label or path}'.replace(FAKE, '<inventado>')
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
    sm.bodies.pop()   # el cuerpo del login lleva el token: no entra en la lista de cuerpos que se buscan fuera
    return token


# ── Telefonía (routes/voice.php): 13 del CRM y 4 públicas ──────────────────────────────────────────────────────

TEL_CRM = [
    ('GET', V + '/status'), ('GET', V + '/calls'), ('GET', V + f'/calls/{FAKE}'), ('GET', V + '/metrics'),
    ('GET', V + '/contacts'), ('POST', V + '/calls'), ('POST', V + f'/calls/{FAKE}/hangup'), ('POST', V + '/contacts'),
    ('POST', V + f'/contacts/{FAKE}/consents'), ('POST', V + f'/contacts/{FAKE}/consents/confirm'),
    ('POST', V + f'/contacts/{FAKE}/revoke'), ('POST', V + '/exclusions'), ('POST', V + '/rne-checks'),
]
# (ruta, tipo de cuerpo, clave del motivo, motivo): la respuesta de cada controlador a una credencial que falta.
TEL_PUBLIC = [
    ('/webhooks/voice/elevenlabs/initiation', 'json', 'code', 'unauthorized'),
    ('/webhooks/voice/elevenlabs/post-call', 'json', 'code', 'invalid_signature'),
    ('/voice/tools/consultar_planes', 'json', 'verdict', 'unauthorized'),
    ('/webhooks/voice/twilio/usage-trigger', 'form', 'code', 'invalid_signature'),
]


def check_telephony_without_credentials(sm, public_max_ms):
    bad = []
    for method, path in TEL_CRM:
        st, _, _ = sm.call(method, path, {} if method == 'POST' else None, label=path)
        if st != 401:
            bad.append(f'{method} {path.replace(FAKE, "<inventado>")}={st}')
    sm.check(f'telefonía del CRM sin sesión: las {len(TEL_CRM)} rutas → 401 (5 GET y 8 POST)', not bad, ', '.join(bad)[:240])
    bad, tiempos = [], []
    for path, kind, key, value in TEL_PUBLIC:
        raw = b'AccountSid=smoke-h8' if kind == 'form' else b'{}'
        st, b, _ = sm.call('POST', path, raw=raw, label=path, content_type='application/x-www-form-urlencoded' if kind == 'form' else 'application/json')
        tiempos.append(sm.last_ms)
        if not (st == 401 and isinstance(b, dict) and b.get(key) == value and b.get('ok') is False):
            bad.append(f'{path}={st} {json.dumps(b)[:60] if b is not None else ""}')
    sm.check('las 4 públicas sin credencial → 401 con su motivo (unauthorized · invalid_signature · verdict unauthorized · invalid_signature)', not bad, ', '.join(bad)[:240])
    if public_max_ms:
        mx = max(tiempos) if tiempos else 0
        sm.check(f'las 4 públicas responden el 401 en menos de {public_max_ms:g} ms (máximo {mx:.1f} ms; sin red saliente en ese camino)', mx <= public_max_ms, f'{", ".join(f"{t:.0f}" for t in tiempos)} ms')
    st, _, _ = sm.call('POST', '/voice/tools/NoExiste', raw=b'{}', label='/voice/tools/<fuera del patrón>')
    sm.check('una herramienta con un nombre fuera del patrón [a-z_]{1,48} → 404 (la ruta no casa)', st == 404, f'HTTP {st}')
    for path, n, cap in [('/webhooks/voice/elevenlabs/initiation', 9000, '8 KB'), ('/voice/tools/consultar_planes', 9000, '8 KB'),
                         ('/webhooks/voice/twilio/usage-trigger', 70000, '64 KB')]:
        raw = json.dumps({'x': 'x' * n}).encode() if 'twilio' not in path else ('Body=' + 'x' * n).encode()
        ctype = 'application/x-www-form-urlencoded' if 'twilio' in path else 'application/json'
        st, b, _ = sm.call('POST', path, raw=raw, label=f'{path} ({len(raw)} bytes)', content_type=ctype)
        sm.check(f'{path} con {len(raw)} bytes sin credencial → 413 (tope de {cap} antes de autenticar)', st == 413, f'HTTP {st} {code_of(b) or ""}'.strip())
    # Exactamente el tope de la post-llamada (1 048 576 bytes; el middleware rechaza solo por encima): si llega a
    # Laravel, es su 401 de firma; si nginx lo corta antes, un 413 sin JSON. Solo informa: lo decide la fase 3.
    raw = json.dumps({'x': 'x' * (1048576 - 9)}).encode()   # {"x": "…"} = 9 bytes de envoltorio
    assert len(raw) == 1048576
    st, b, _ = sm.call('POST', '/webhooks/voice/elevenlabs/post-call', raw=raw, label=f'post-call ({len(raw)} bytes)', keep=False)
    quien = 'Laravel (401 invalid_signature): nginx deja pasar el tope entero' if st == 401 and code_of(b) == 'invalid_signature' else \
        ('nginx (413 sin JSON): su client_max_body_size es menor que el tope de 1 MB de la post-llamada; subirlo antes de la fase 3' if st == 413 and b is None else f'HTTP {st} {code_of(b) or ""}'.strip())
    sm.info(f'post-call con {len(raw)} bytes (= el tope de 1 MB) sin firma: responde {quien}')


def check_telephony_with_session(sm, token):
    st, b, _ = sm.call('GET', V + '/status', token=token)
    ok = st == 200 and isinstance(b, dict)
    flags = b.get('flags') if ok else None
    pub = b.get('publication') if ok else None
    lim = b.get('limits') if ok else None
    E = ESPERA[FASE]
    if sm.check('GET admin/voice/status → 200', ok, f'HTTP {st}'):
        VIVO['lab'] = isinstance(flags, dict) and flags.get('lab') is True
        # B-T4 (h8-hardening): el código nuevo añade `contacts` (registrar contactos y su consentimiento,
        # VOICE_CONTACTS_ENABLED); el de antes no la tiene. Se admiten los dos y, si está, tiene que estar APAGADA: es de la
        # fase 4b, y ninguna de las fases de este smoke (1, 2a, 4a) la enciende.
        VIVO['contacts'] = flags.get('contacts') if isinstance(flags, dict) else None
        conjunto = isinstance(flags, dict) and set(flags) in (BANDERAS, BANDERAS | {'contacts'}) and flags.get('contacts', False) is False
        sm.check('la constante de las llamadas, ' + ('ABIERTA (el commit voice(calls-open) desplegado, fase 4a)' if E['calls'] else 'cerrada (VoiceCallPolicy::CALLS_ENABLED_IN_CODE = false)'),
                 b.get('callsEnabledInCode') is E['calls'], str(b.get('callsEnabledInCode')))
        if FASE == '1':
            sm.check('las 5 banderas de la voz apagadas (entrantes, salientes, Twilio Voice, laboratorio, monitor) y la de contactos, si el código la tiene',
                     conjunto and all(v is False for v in flags.values()),
                     json.dumps(flags))
        else:
            fijas = {k: v for k, v in E['flags'].items() if v is not None}
            sm.check(f'banderas de la fase {FASE}: ' + ', '.join(f'{k} {"sí" if v else "no"}' for k, v in fijas.items()) + ' (contactos, si el código la tiene: no)',
                     conjunto and all(flags.get(k) is v for k, v in fijas.items()),
                     json.dumps(flags))
        sm.check('sin flags.publishing en la telefonía (E4-1: la bandera de publicar vive en el Studio)', isinstance(flags, dict) and 'publishing' not in flags)
        if E['cadena'] == 'none':
            sm.check('la cadena de voz sin entradas (canary y rest: none) y el freno suelto',
                     isinstance(pub, dict) and all(isinstance(pub.get(k), dict) and pub[k].get('status') == 'none' for k in ('canary', 'rest')) and b.get('brakeOpen') is False,
                     f'{json.dumps({k: (pub or {}).get(k, {}).get("status") for k in ("canary", "rest")})} · freno {b.get("brakeOpen")}')
        else:
            sm.check('la cadena de voz con la parte canary vigente (ok) y la rest sin entradas (none), y el freno suelto',
                     isinstance(pub, dict) and (pub.get('canary') or {}).get('status') == 'ok' and (pub.get('rest') or {}).get('status') == 'none' and b.get('brakeOpen') is False,
                     f'{json.dumps({k: (pub or {}).get(k, {}).get("status") for k in ("canary", "rest")})} · freno {b.get("brakeOpen")}')
        if E['cupos'] == 'cero':
            sm.check('cupos y presupuesto a 0 (salientes al día, entrantes al día, µUS$ al día)',
                     isinstance(lim, dict) and lim.get('outboundDailyCap') == 0 and lim.get('inboundDailyCap') == 0 and lim.get('dailyBudgetMicros') == 0,
                     json.dumps({k: (lim or {}).get(k) for k in ('outboundDailyCap', 'inboundDailyCap', 'dailyBudgetMicros')}))
        else:
            sm.check('cupo de salientes ≥ 1 con presupuesto > 0 y entrantes a 0 (fase 4a)',
                     isinstance(lim, dict) and isinstance(lim.get('outboundDailyCap'), int) and lim['outboundDailyCap'] >= 1 and lim.get('inboundDailyCap') == 0
                     and isinstance(lim.get('dailyBudgetMicros'), int) and lim['dailyBudgetMicros'] > 0,
                     json.dumps({k: (lim or {}).get(k) for k in ('outboundDailyCap', 'inboundDailyCap', 'dailyBudgetMicros')}))
        num = b.get('number') if isinstance(b.get('number'), dict) else {}
        piezas = f'{json.dumps(num)} · subcuenta {b.get("carrierConfigured")}'
        if E['piezas'] == 'ninguna':
            sm.check('sin número de voz, sin número importado y sin subcuenta de Twilio (piezas de la fase 2)',
                     num.get('configured') is False and num.get('importedNumberIdConfigured') is False and b.get('carrierConfigured') is False, piezas)
        elif E['piezas'] == 'completas':
            sm.check('el número de voz, el id importado y la subcuenta de Twilio puestos, y el número no es de WhatsApp',
                     num.get('configured') is True and num.get('importedNumberIdConfigured') is True and b.get('carrierConfigured') is True and num.get('collidesWithMessaging') is not True, piezas)
        else:
            sm.info('piezas de la fase 2 (informativo en la 2a: el laboratorio no las necesita)', piezas)
        sm.info('proveedor según la telefonía', f'{b.get("provider")} (E4-6: dice UNVERIFIED hasta que B8 añada su lector de la verificación, antes de la fase 3)')
    for rel, label in [('/calls', 'llamadas'), ('/contacts', 'contactos')]:
        st, b, _ = sm.call('GET', V + rel, token=token)
        meta = b.get('meta') if isinstance(b, dict) else None
        if ESPERA[FASE]['listas_vacias'] or rel == '/contacts':
            sm.check(f'GET admin/voice{rel} → 200, {label} vacíos', st == 200 and isinstance(b.get('data'), list) and b['data'] == [] and isinstance(meta, dict) and meta.get('total') == 0,
                     f'HTTP {st} · total {(meta or {}).get("total")}')
        else:
            sm.check(f'GET admin/voice{rel} → 200 (fase {FASE}: puede haber llamadas de canarios)', st == 200 and isinstance(b.get('data'), list) and isinstance(meta, dict),
                     f'HTTP {st} · total {(meta or {}).get("total")}')
    st, b, _ = sm.call('GET', V + f'/calls/{FAKE}', token=token)
    sm.check('GET admin/voice/calls/<inventado> → 404 call_not_found', st == 404 and code_of(b) == 'call_not_found', f'HTTP {st} {code_of(b) or ""}'.strip())
    st, b, _ = sm.call('GET', V + '/metrics', token=token)
    sm.check('GET admin/voice/metrics → 200 sin llamadas vivas ni ambiguas, freno suelto',
             st == 200 and isinstance(b, dict) and b.get('live') == 0 and b.get('ambiguous') == 0 and b.get('brakeOpen') is False,
             f'HTTP {st} · vivas {(b or {}).get("live")} · ambiguas {(b or {}).get("ambiguous")}')
    bad = []
    # B-T4: con la bandera de contactos APAGADA (código nuevo), dar de alta, cargar y confirmar el consentimiento responden
    # 409 voice_contacts_disabled ANTES de validar el cuerpo; sin la bandera en el código (el de antes), 422 como las demás.
    cerradas = VIVO.get('contacts') is False
    for path in [V + '/calls', V + '/contacts', V + f'/contacts/{FAKE}/consents', V + f'/contacts/{FAKE}/consents/confirm',
                 V + f'/contacts/{FAKE}/revoke', V + '/exclusions', V + '/rne-checks']:
        st, b, _ = sm.call('POST', path, {}, token)
        if cerradas and path in ESCRIBEN_CONTACTOS:
            if not (st == 409 and code_of(b) == 'voice_contacts_disabled'):
                bad.append(f'{path.replace(FAKE, "<inventado>")}={st} {code_of(b) or ""} (esperado 409 voice_contacts_disabled)')
        elif not (st == 422 and code_of(b) == 'validation_failed'):
            bad.append(f'{path.replace(FAKE, "<inventado>")}={st} {code_of(b) or ""}')
    sm.check('las 7 POST de la telefonía con un cuerpo vacío → 422 validation_failed (antes de la puerta: nada se escribe)'
             + ('; con la bandera de contactos apagada, alta, carga y confirmación del consentimiento → 409 voice_contacts_disabled antes de validar' if cerradas else ''),
             not bad, ', '.join(bad)[:240])
    st, b, _ = sm.call('POST', V + f'/calls/{FAKE}/hangup', {}, token)
    sm.check('POST admin/voice/calls/<inventado>/hangup → 404 call_not_found (antes de hablar con Twilio)', st == 404 and code_of(b) == 'call_not_found', f'HTTP {st} {code_of(b) or ""}'.strip())


# ── Studio: las 21 rutas nuevas (S8 y LAB8) y la ruta 45 ───────────────────────────────────────────────────────

VALID_PUBLISH = {'clientRequestId': FAKE, 'versionUuid': FAKE, 'profileUuid': FAKE, 'expectedSpecHash': ZEROS64}
VALID_PROMOTE = {'clientRequestId': FAKE, 'evidenceHash': ZEROS64, 'expectedSpecHash': ZEROS64}
# (método, ruta relativa al Studio, cuerpo, estado esperado, código esperado o None). Cuerpos inválidos o recursos
# inventados en todo lo que podría escribir; publicar y promover, con un cuerpo válido: lo para la bandera (E4-1).
STUDIO_ROUTES = [
    ('GET', 'voice/profiles', None, 200, None),
    ('GET', f'voice/profiles/{FAKE}', None, 404, None),
    ('POST', 'voice/profiles', {}, 422, None),
    ('POST', f'voice/profiles/{FAKE}/submit', {}, 404, None),
    ('POST', f'voice/profiles/{FAKE}/approve', {}, 404, None),
    ('POST', f'voice/profiles/{FAKE}/reject', {}, 422, None),
    ('POST', 'voice/compile-preview', {'versionUuid': FAKE, 'profileUuid': FAKE}, 404, None),
    ('GET', 'voice/publications', None, 200, None),
    ('POST', 'voice/publish', VALID_PUBLISH, 409, 'voice_publishing_disabled'),
    ('POST', 'voice/promote', VALID_PROMOTE, 409, 'voice_publishing_disabled'),
    ('POST', 'voice/rollback', {}, 422, None),
    ('POST', 'voice/off', {}, 422, None),
    ('GET', 'voice/canary-evidence', None, 200, None),
    ('GET', 'voice/brakes', None, 200, None),
    ('POST', f'voice/brakes/{FAKE}/release', {}, 422, None),
    ('GET', 'voice-lab/status', None, 200, None),
    ('GET', 'voice-lab/sessions', None, 409, 'voice_lab_disabled'),
    ('GET', f'voice-lab/sessions/{FAKE}', None, 409, 'voice_lab_disabled'),
    ('POST', 'voice-lab/sessions', {}, 409, 'voice_lab_disabled'),
    ('POST', f'voice-lab/sessions/{FAKE}/end', {}, 409, 'voice_lab_disabled'),
    ('POST', f'voice-lab/sessions/{FAKE}/verdict', {}, 409, 'voice_lab_disabled'),
]


def check_studio_without_session(sm):
    bad = []
    for method, rel, body, _, _ in STUDIO_ROUTES:
        st, _, _ = sm.call(method, f'{S}/{rel}', body, label=f'{S}/{rel}')
        if st != 401:
            bad.append(f'{method} {rel.replace(FAKE, "<inventado>")}={st}')
    sm.check(f'las {len(STUDIO_ROUTES)} rutas nuevas del Studio sin sesión → 401 (8 GET y 13 POST)', not bad, ', '.join(bad)[:240])


# Con su bandera encendida, estas rutas escriben o gastan: el smoke no las llama (OMITIDO con el motivo).
ESCRIBEN_CON_LAB = {('POST', 'voice-lab/sessions'), ('POST', f'voice-lab/sessions/{FAKE}/end'), ('POST', f'voice-lab/sessions/{FAKE}/verdict')}
ESCRIBEN_CON_PUBLICAR = {('POST', 'voice/publish'), ('POST', 'voice/promote')}
# Con el laboratorio encendido, sus lecturas responden de verdad: la lista 200 y una sesión inventada 404.
LAB_ENCENDIDO = {('GET', 'voice-lab/sessions'): (200, None), ('GET', f'voice-lab/sessions/{FAKE}'): (404, None)}


def check_studio_with_session(sm, token):
    bad, vistos = [], {}
    publicar_vivo = ESPERA[FASE]['publicar']
    for method, rel, body, want, code in STUDIO_ROUTES:
        if VIVO['lab'] and (method, rel) in ESCRIBEN_CON_LAB:
            sm.skip(f'{method} {S}/{rel.replace(FAKE, "<inventado>")}', 'laboratorio hablado encendido: abrir, cerrar o juzgar una sesión escribe o gasta; el smoke no lo hace')
            continue
        if publicar_vivo and (method, rel) in ESCRIBEN_CON_PUBLICAR:
            sm.skip(f'{method} {S}/{rel}', 'publicar la voz encendido: publicar o promover escribe en la cadena y crea agentes; el smoke no lo hace')
            continue
        if VIVO['lab'] and (method, rel) in LAB_ENCENDIDO:
            want, code = LAB_ENCENDIDO[(method, rel)]
        buckets = None
        if rel in ('voice/publish', 'voice/promote'):
            buckets = ('write', 'evidence')
        elif rel == 'voice-lab/sessions' and method == 'POST':
            buckets = ('write',)
        st, b, _ = sm.call(method, f'{S}/{rel}', body, token, label=f'{S}/{rel}', buckets=buckets)
        vistos[(method, rel)] = b
        if st != want or (code is not None and code_of(b) != code):
            bad.append(f'{method} {rel.replace(FAKE, "<inventado>")}={st} {code_of(b) or ""} (esperado {want}{" " + code if code else ""})')
    sm.check(f'las {len(vistos)} rutas nuevas del Studio con sesión que no escriben en la fase {FASE}: lecturas 200, inventados 404, '
             + ('laboratorio 409 voice_lab_disabled, ' if not VIVO['lab'] else 'laboratorio encendido (lista 200, inventada 404), ')
             + ('publicar y promover 409 voice_publishing_disabled, ' if not publicar_vivo else '')
             + 'el resto 422 antes de escribir', not bad, ' · '.join(bad)[:400])
    perfiles = data_of(vistos.get(('GET', 'voice/profiles')))
    total = (perfiles or {}).get('total') if isinstance(perfiles, dict) else '?'
    if FASE == '1' and PERFILES_ANTES == '0':
        # B-T7 (OPS8-e): estricto solo cuando la base no tenía ningún perfil antes del smoke (el primer despliegue, o la voz
        # sin usar): entonces la API tampoco puede enseñar ninguno.
        sm.check('GET voice/profiles: ningún perfil de voz (la base no tenía ninguno antes del smoke)',
                 isinstance(perfiles, dict) and perfiles.get('data') == [] and perfiles.get('total') == 0, f'total {total}')
    elif FASE == '1':
        # Con la fase 1 ya desplegada el Studio puede crear y aprobar perfiles (E16-30: el perfil 1, aprobado el 09-11): no
        # es un fallo del despliegue. Lo que el smoke no puede hacer es escribir, y eso lo exige el lanzador con el
        # recuento exacto de todas las tablas antes y después.
        sm.info('GET voice/profiles (informativo: la fase 1 ya desplegada admite perfiles creados en el Studio; ninguna escritura del smoke: el recuento exacto del lanzador)',
                f'total {total} en la API · {PERFILES_ANTES or "? (sin el recuento del lanzador)"} en la base antes del smoke')
    else:
        sm.info('GET voice/profiles (informativo desde la fase 2: el laboratorio y la publicación usan perfiles)', f'total {total}')
    pubs = data_of(vistos.get(('GET', 'voice/publications')))
    publishing = pubs.get('publishing') if isinstance(pubs, dict) else None
    historial = pubs.get('history') if isinstance(pubs, dict) else None
    if not publicar_vivo:
        sm.check('GET voice/publications: publicar apagado (voice_publishing_disabled) y la cadena de voz sin entradas',
                 isinstance(publishing, dict) and publishing.get('enabled') is False and publishing.get('code') == 'voice_publishing_disabled'
                 and isinstance(historial, dict) and historial.get('total') == 0,
                 f'publicar {json.dumps({k: (publishing or {}).get(k) for k in ("enabled", "code")})} · entradas {(historial or {}).get("total") if isinstance(historial, dict) else "?"}')
    else:
        sm.check('GET voice/publications: publicar ENCENDIDO y la cadena de voz con al menos una entrada (la del canario)',
                 isinstance(publishing, dict) and publishing.get('enabled') is True and isinstance(historial, dict) and isinstance(historial.get('total'), int) and historial['total'] >= 1,
                 f'publicar {json.dumps({k: (publishing or {}).get(k) for k in ("enabled", "code")})} · entradas {(historial or {}).get("total") if isinstance(historial, dict) else "?"}')
    lab = data_of(vistos.get(('GET', 'voice-lab/status')))
    sm.info('GET voice-lab/status', json.dumps({k: lab.get(k) for k in ('enabled', 'driver', 'state', 'code') if isinstance(lab, dict) and k in lab})[:160] if isinstance(lab, dict) else 'sin datos')
    st, b, _ = sm.call('GET', f'{S}/integrations/voice', token=token, label=f'{S}/integrations/voice')
    d = data_of(b) if st == 200 else None
    if sm.check('GET integrations/voice (ruta 45) → 200', isinstance(d, dict), f'HTTP {st}'):
        el = d.get('elevenlabs') if isinstance(d.get('elevenlabs'), dict) else {}
        sm.check(f'ruta 45: ElevenLabs en {EXPECT_VOICE_STATE} (E1-12; lo afirma el Studio con la verificación de menos de 30 días)',
                 el.get('state') == EXPECT_VOICE_STATE, str(el.get('state')))
        pb = d.get('publishing') if isinstance(d.get('publishing'), dict) else {}
        sm.check('ruta 45: publicar la voz ' + ('encendido (publishing.enabled true, fase 4a)' if ESPERA[FASE]['publicar'] else 'apagado (publishing.enabled false, E4-1)'),
                 pb.get('enabled') is ESPERA[FASE]['publicar'], json.dumps({k: pb.get(k) for k in ('available', 'enabled', 'code')}))
        can = d.get('can') if isinstance(d.get('can'), dict) else {}
        sm.check(f'ruta 45: «can» con las {VOICE_CAPS} llaves de D8-22, todas true para el Super Admin', len(can) == VOICE_CAPS and all(v is True for v in can.values()),
                 ', '.join(f'{k}={v}' for k, v in sorted(can.items()))[:200])


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
        if st == 409 and code_of(cuerpo) == 'voice_lab_disabled' and route['path'].startswith(S + '/voice-lab/'):
            sm.skip(nombre, 'laboratorio hablado apagado (409 voice_lab_disabled): lo esperado en la fase 1')
            tally['OMITIDO'] += 1
            informe.append({'name': route['name'], 'path': route['path'], 'status': 'OMITIDO', 'reason': 'voice_lab_disabled'})
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
        print('uso: SMOKE_EMAIL=… SMOKE_PW=… smoke-h8.py superadmin')
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
    if not re.fullmatch(r'ELEVENLABS_[A-Z_]{3,20}', EXPECT_VOICE_STATE):
        print('SMOKE_EXPECT_VOICE_STATE no es un estado de ElevenLabs')
        return 2
    if FASE not in ESPERA:
        print('SMOKE_PHASE = 1 | 2a | 4a')
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
    print(f'== Smoke del Hito 8 (fase {FASE}) · {part} · {API}', flush=True)
    # El login PRIMERO: las rutas del CRM de la telefonía usan `throttle:120,1` y `throttle:30,1` y exigen la sesión
    # en el controlador, así que cada petición ANÓNIMA suma en el cubo por IP que Laravel comparte con el login del
    # CRM (`throttle:8,1`). Medido en el banco (OPS8-b): 13 anónimas antes del login → el login da 429.
    token = login(sm, email, password)
    if token:
        check_telephony_with_session(sm, token)
        check_studio_with_session(sm, token)
        check_budgets(sm, token, budgets, origen, huella)
    check_telephony_without_credentials(sm, (budgets.get('public401') or {}).get('maxMs'))
    check_studio_without_session(sm)
    sm.scan()
    c = sm.counts
    print(f"RESUMEN ({part}): {c['OK']} OK · {c['FALLO']} FALLO · {c['OMITIDO']} OMITIDO", flush=True)
    return sm.exit_code()


if __name__ == '__main__':
    sys.exit(main(sys.argv))

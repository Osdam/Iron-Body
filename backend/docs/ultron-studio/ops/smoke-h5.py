#!/usr/bin/env python3
"""Smoke del Hito 5 de ULTRON STUDIO (gobierno y versionado), por fases.

  Fase 1 (SMOKE_PHASE=1, el defecto): código desplegado con TODO apagado (MARKETING_ULTRON_STUDIO_CONFIG=off,
          ULTRON_STUDIO_PUBLISHING_ENABLED=false): publicar y promover → 409 publishing_disabled.
  Fase 2 (SMOKE_PHASE=2): el motor lee la cadena en la conversación canaria y publicar está permitido. Se pasa
          ANTES de la primera publicación y sin nadie publicando mientras corre.
  Fase 3 (SMOKE_PHASE=3): el motor lee en todas las conversaciones.

POLÍTICA: SOLO LECTURA. Las únicas peticiones que no son GET son intentos que el servidor rechaza ANTES de
escribir (código de 61048ff, app/Services/UltronStudio/StudioPublications.php):
  · publish, promote y rollback sobre un uuid INVENTADO: 409 de llave (assertKeys solo audita si la versión
    existe) o 404 version_not_found (findByUuid va antes de cualquier escritura);
  · publications/legacy: 409 already_legacy, SOLO en la fase 1 y solo después de ver en esta misma ejecución
    la cadena vacía y la llave del Studio cerrada (con publicar apagado nadie puede llenarla entre medias);
  · con el Administrador, 403 de la puerta (EnforceAdminAuthorization; solo deja una línea WARNING en el log),
    y únicamente si su sesión NO trae la llave: si la trae, no se envía nada que el servidor pudiera aceptar.
El lanzador (smoke-run-h5.sh) comprueba además en la base que las tablas ultron_studio_* no cambian.

Uso:   SMOKE_EMAIL=… SMOKE_PW=… python3 smoke-h5.py superadmin|administrador
       (la contraseña SOLO por entorno, nunca en argv)
Variables: SMOKE_API (por defecto https://api.ironbodyneiva.cloud/api) · SMOKE_PHASE 1|2|3 ·
           SMOKE_CANARY_ID (id de la conversación canaria, o «none» si el motor no tiene; nunca se imprime) ·
           SMOKE_TOKEN_FILE (deja «Authorization: Bearer …» en 0600 para que el lanzador compruebe que el token
           muere al desactivar la cuenta) · SMOKE_EXPECT_CATALOG (2026-10-19.1) · SMOKE_EXPECT_RULES (12).
Salida: una línea por comprobación (OK / FALLO / OMITIDO; INFO y AVISO no cuentan) y un RESUMEN.
Código: 0 todo OK · 1 algún FALLO · 3 sin fallos pero con algo OMITIDO · 2 uso incorrecto.
"""
import json
import os
import re
import sys
import urllib.error
import urllib.request
import uuid

API = os.environ.get('SMOKE_API', 'https://api.ironbodyneiva.cloud/api').rstrip('/')
S = '/admin/ultron-studio'
# uuid inventado (versión 4 bien formada): ninguna versión real lo tiene, así que nada se escribe con él.
FAKE = '00000000-0000-4000-8000-0000000005a5'
SCOPES = {'1': 'off', '2': 'canary', '3': 'all'}
EXPECT_CATALOG = os.environ.get('SMOKE_EXPECT_CATALOG', '2026-10-19.1')
EXPECT_RULES = int(os.environ.get('SMOKE_EXPECT_RULES', '12'))
H5_STATES = {'CANARY', 'PUBLISHED', 'RETIRED', 'ROLLED_BACK'}
HEX64 = re.compile(r'^[0-9a-f]{64}$')

# Lo que ningún cuerpo del Studio puede llevar (contrato H5 §A3, §A4.9, §A6 y revisión de seguridad).
FORBIDDEN_KEYS = {'signature', 'effective_json', 'effectiveJson'}
FORBIDDEN_RAW = re.compile(r'"(signature|effective_json|effectiveJson)"\s*:')
CONVERSATION_KEY = re.compile(r'conversation', re.I)
CANARY_KEY = re.compile(r'canar|conversation|chat', re.I)
SECRET = re.compile(r'sk-[A-Za-z0-9_-]{8,}|OPENAI_|APP_KEY|base64:[A-Za-z0-9+/=]{20,}|/var/www|app/Services/')
# Texto libre del personal o mensajes fijos del servidor: un motivo «rotación de APP_KEY» no es una fuga. El id del
# canario sí se busca también aquí (escribirlo en un motivo lo publicaría en el historial).
FREE_TEXT_KEYS = {'reason', 'label', 'note', 'message', 'name', 'title', 'description', 'text'}
# Antes de buscar el id del canario dentro de un texto se quitan uuids, huellas y fechas (dan falsos positivos).
NOISE = re.compile(r'[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'
                   r'|[0-9a-f]{32,}'
                   r'|\d{4}-\d{2}-\d{2}(?:[T ][0-9:.]+(?:Z|[+-]\d{2}:?\d{2})?)?(?:\.\d+)?')


def data_of(body):
    return body.get('data') if isinstance(body, dict) else None


def code_of(body):
    return body.get('code') if isinstance(body, dict) else None


def pairs(node, key=None):
    """(tipo, clave, valor) de cada nodo de un JSON: las claves y las hojas con su clave más cercana."""
    if isinstance(node, dict):
        for k, v in node.items():
            yield 'key', str(k), None
            yield from pairs(v, str(k))
    elif isinstance(node, list):
        for v in node:
            yield from pairs(v, key)
    else:
        yield 'leaf', key, node


def find_leaks(bodies, canary_id):
    """Devuelve las etiquetas de los cuerpos con cada tipo de fuga. Pura: la prueba local la llama sin red."""
    found = {'forbidden': set(), 'conversation': set(), 'secret': set(), 'canary': set()}
    digits = canary_id if canary_id and canary_id.isdigit() else ''
    loose = len(digits) >= 4   # un id corto aparece por casualidad en recuentos y horas: solo bajo su clave
    for label, raw, parsed in bodies:
        raw = raw or ''
        if FORBIDDEN_RAW.search(raw):
            found['forbidden'].add(label)
        if parsed is None:
            if SECRET.search(raw):   # una página de error o una traza sin JSON: se mira entera
                found['secret'].add(label)
            continue
        for kind, k, v in pairs(parsed):
            if kind == 'key':
                if k in FORBIDDEN_KEYS:
                    found['forbidden'].add(label)
                if CONVERSATION_KEY.search(k):
                    found['conversation'].add(label)
                if SECRET.search(k):
                    found['secret'].add(label)
                continue
            if isinstance(v, str) and k not in FREE_TEXT_KEYS and SECRET.search(v):
                found['secret'].add(label)
            if not digits or isinstance(v, bool):
                continue
            if isinstance(v, int) and str(v) == digits and (loose or (k is not None and CANARY_KEY.search(k))):
                found['canary'].add(label)
            elif isinstance(v, str) and (loose or (k is not None and CANARY_KEY.search(k))):
                if re.search(r'(?<!\d)' + re.escape(digits) + r'(?!\d)', NOISE.sub(' ', v)):
                    found['canary'].add(label)
    return found


class Smoke:
    def __init__(self, phase, part, canary_id):
        self.phase = phase
        self.scope = SCOPES[phase]
        self.part = part
        self.canary_id = canary_id
        self.counts = {'OK': 0, 'FALLO': 0, 'OMITIDO': 0}
        self.bodies = []        # (etiqueta, texto crudo, json) de cada respuesta del Studio
        self.statuses = []      # para el limitador (429)
        self.remaining = []     # X-RateLimit-Remaining vistos
        self.labels = {FAKE: '<inventado>'}

    # ── salida ──────────────────────────────────────────────────────────────
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

    def label(self, method, path):
        for real, alias in self.labels.items():
            path = path.replace(real, alias)
        return f'{method} {path.replace(S + "/", "")}'

    # ── HTTP ────────────────────────────────────────────────────────────────
    def call(self, method, path, body=None, token=None, studio=True):
        data = json.dumps(body).encode() if body is not None else None
        req = urllib.request.Request(API + path, data=data, method=method)
        req.add_header('Accept', 'application/json')
        if data is not None:
            req.add_header('Content-Type', 'application/json')
        if token:
            req.add_header('Authorization', 'Bearer ' + token)
        headers = {}
        try:
            with urllib.request.urlopen(req, timeout=30) as r:   # TLS verificado (defecto de urllib)
                status, raw, headers = r.status, r.read().decode('utf-8', 'replace'), r.headers
        except urllib.error.HTTPError as e:
            status, raw, headers = e.code, e.read().decode('utf-8', 'replace'), e.headers
        except (urllib.error.URLError, OSError) as e:
            status, raw = 0, ''
            print(f'        (sin respuesta de {self.label(method, path)}: {type(e).__name__})', flush=True)
        try:
            parsed = json.loads(raw) if raw else None
        except ValueError:
            parsed = None
        self.statuses.append(status)
        if studio:   # el login tiene su propio limitador (8/min) y no cuenta aquí
            rem = headers.get('X-RateLimit-Remaining') if headers else None
            if rem is not None and str(rem).isdigit():
                self.remaining.append(int(rem))
            self.bodies.append((self.label(method, path), raw, parsed))
        return status, parsed

    def login(self, email, password):
        st, body = self.call('POST', '/admin/auth/login', {'email': email, 'password': password}, studio=False)
        token = body.get('token') if isinstance(body, dict) else None
        user = (body.get('user') if isinstance(body, dict) else None) or {}
        perms = user.get('permissions') if isinstance(user.get('permissions'), list) else []
        hint = ' · limitador del login (8/min por IP): esperar un minuto' if st == 429 else ''
        if not self.check(f'login de la cuenta de smoke ({self.part})', st == 200 and bool(token),
                          f'HTTP {st} {code_of(body) or ""}'.strip() + hint):
            return None, []
        path = os.environ.get('SMOKE_TOKEN_FILE')
        if path:
            fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
            os.fchmod(fd, 0o600)
            with os.fdopen(fd, 'w') as f:
                f.write('Authorization: Bearer ' + token + '\n')
        return token, perms

    def expect(self, name, method, path, body, token, status, code, extra=None):
        st, r = self.call(method, path, body, token)
        ok = st == status and code_of(r) == code and (extra is None or extra(r))
        detail = f'HTTP {st} {code_of(r) or ""}'.strip()
        if isinstance(r, dict) and r.get('required_permission'):
            detail += f' requiere {r.get("required_permission")}'
        self.check(name, ok, detail)
        return st, r

    # ── cierre ──────────────────────────────────────────────────────────────
    def scan(self):
        n = len(self.bodies)
        leaks = find_leaks(self.bodies, self.canary_id)
        joined = lambda s: ', '.join(sorted(s))[:240]
        self.check(f'cuerpos ({n}): ninguno lleva signature, effective_json ni effectiveJson',
                   not leaks['forbidden'], joined(leaks['forbidden']))
        self.check(f'cuerpos ({n}): ninguna clave nombra una conversación', not leaks['conversation'],
                   joined(leaks['conversation']))
        self.check(f'cuerpos ({n}): sin claves, rutas del servidor ni variables de entorno', not leaks['secret'],
                   joined(leaks['secret']))
        if self.canary_id == 'none':
            self.info('cuerpos: id de la conversación canaria', 'el motor no tiene canario configurado: nada que buscar')
        elif self.canary_id:
            self.check(f'cuerpos ({n}): ninguno lleva el id de la conversación canaria', not leaks['canary'],
                       joined(leaks['canary']))
            if len(self.canary_id) < 4:
                self.info('id canario de menos de 4 cifras', 'solo se busca bajo claves de canario o conversación')
        else:
            self.skip('cuerpos: ninguno lleva el id de la conversación canaria',
                      'sin SMOKE_CANARY_ID (lo lee el lanzador en el servidor)')
        self.check('limitador: ninguna respuesta 429', 429 not in self.statuses,
                   f'{self.statuses.count(429)} respuestas 429')
        if self.remaining:
            self.info('limitador: mínimo X-RateLimit-Remaining en las rutas del Studio', str(min(self.remaining)))
        if 0 in self.statuses:
            self.check('red: todas las peticiones tuvieron respuesta', False, f'{self.statuses.count(0)} sin respuesta')

    def exit_code(self):
        if self.counts['FALLO']:
            return 1
        return 3 if self.counts['OMITIDO'] else 0


# ── Parte 1: Super Admin (la cuenta de smoke) ──────────────────────────────

def run_superadmin(sm, email, password):
    token, perms = sm.login(email, password)
    if not token:
        return
    sm.check('la sesión trae ultron.view, ultron.publish y ultron.rollback',
             all(p in perms for p in ('ultron.view', 'ultron.publish', 'ultron.rollback')),
             str(sorted(p for p in perms if str(p).startswith('ultron.'))))
    new_cr = lambda: str(uuid.uuid4())

    # GET status: el bloque de la publicación y las llaves.
    st, s = sm.call('GET', S + '/status', token=token)
    d = data_of(s) or {}
    sm.check('GET status → 200', st == 200, f'HTTP {st}')
    eng = d.get('engine') or {}
    pub = eng.get('publication')
    sm.check('status: engine.readsVersions = true (ENGINE_READS_VERSIONS)', eng.get('readsVersions') is True,
             str(eng.get('readsVersions')))
    head = None
    if sm.check('status: engine.publication presente (PublicationStateDto)', isinstance(pub, dict),
                '' if isinstance(pub, dict) else 'ausente: la cadena no se pudo leer (log ultron_studio.publication_state_unavailable) o backend anterior'):
        head = pub.get('head')
        sm.check(f'status: engine.publication.envScope = {sm.scope}', pub.get('envScope') == sm.scope,
                 str(pub.get('envScope')))
        if sm.phase == '1':
            sm.check("status: fase 1 → state 'off', sin cabeza, sin partes y sin deriva",
                     pub.get('state') == 'off' and head is None and pub.get('canary') is None
                     and pub.get('rest') is None and pub.get('drift') == [],
                     f"state={pub.get('state')} cabeza={'sí' if head else 'no'}")
            sm.check('status: engine.activeVersion = null', eng.get('activeVersion') is None,
                     'null' if eng.get('activeVersion') is None else 'con versión')
        elif head is None:
            sm.check("status: cadena vacía → state 'legacy' y sin partes",
                     pub.get('state') == 'legacy' and pub.get('canary') is None and pub.get('rest') is None,
                     str(pub.get('state')))
        else:
            sm.check('status: la cabeza verifica (signatureOk) y el estado no es fallback',
                     head.get('signatureOk') is True and pub.get('state') in ('applied', 'legacy'),
                     f"seq={head.get('seq')} action={head.get('action')} state={pub.get('state')}")
        if sm.phase != '1':
            drift = pub.get('drift')
            sm.check('status: sin deriva entre lo declarado y lo que lee el motor', drift == [],
                     f'{len(drift)} parámetros' if isinstance(drift, list) else str(drift))
    publishing = d.get('publishing') or {}
    if sm.phase == '1':
        sm.check('status: publishing.available = false con code publishing_disabled',
                 publishing.get('available') is False and publishing.get('code') == 'publishing_disabled',
                 f"{publishing.get('available')} {publishing.get('code')}")
    else:
        sm.check('status: publishing.available = true (las dos llaves abiertas)',
                 publishing.get('available') is True and publishing.get('code') is None,
                 f"{publishing.get('available')} {publishing.get('code')}")
    can = d.get('can') or {}
    sm.check('status: can.publish y can.rollback = true para el Super Admin',
             can.get('publish') is True and can.get('rollback') is True,
             f"publish={can.get('publish')} rollback={can.get('rollback')}")
    catalog = (d.get('catalog') or {}).get('version')
    rules = (d.get('legacyBaseline') or {}).get('rulesImported')
    sm.check(f'status: catálogo {EXPECT_CATALOG} y {EXPECT_RULES} reglas del código (F/G intactos)',
             catalog == EXPECT_CATALOG and rules == EXPECT_RULES, f'{catalog} · {rules}')
    life = [x for x in (d.get('lifecycle') or []) if isinstance(x, dict)]
    reach = {x.get('status') for x in life if x.get('reachable') is True}
    retired = next((x for x in life if x.get('status') == 'RETIRED'), {})
    sm.check('status: tubería con 9 estados alcanzables (VALIDATING no) y RETIRED = «Sustituida»',
             len(reach) == 9 and 'VALIDATING' not in reach and H5_STATES <= reach and retired.get('label') == 'Sustituida',
             f'{len(reach)} alcanzables')

    # GET publications: el historial y su coherencia con la cabeza de status.
    st, p = sm.call('GET', S + '/publications?page=1&per_page=5', token=token)
    page = data_of(p) or {}
    total = page.get('total')
    rows = page.get('data')
    sm.check('GET publications → 200 con el paginador (per_page=5 respetado)',
             st == 200 and isinstance(total, int) and isinstance(rows, list) and page.get('per_page') == 5,
             f'HTTP {st} total={total}')
    chain_empty = st == 200 and total == 0 and rows == []
    if sm.phase == '1':
        sm.check('publications: la cadena está vacía (fase 1)', chain_empty, f'total={total}')
    else:
        sm.info('publications: entradas en la cadena', f'total={total}')
    if isinstance(pub, dict) and st == 200 and isinstance(rows, list):
        first = rows[0] if rows else None
        same = (head is None and first is None) or (
            isinstance(head, dict) and isinstance(first, dict)
            and head.get('uuid') == first.get('uuid') and head.get('seq') == first.get('seq'))
        sm.check('coherencia: la cabeza de status es la primera entrada del historial', same)

    st, v = sm.call('GET', S + '/versions?status=CANARY,PUBLISHED,RETIRED,ROLLED_BACK&page=1&per_page=5', token=token)
    vt = (data_of(v) or {}).get('total')
    if sm.phase == '1':
        sm.check('versions: ninguna en CANARY, PUBLISHED, RETIRED ni ROLLED_BACK (fase 1)', st == 200 and vt == 0,
                 f'HTTP {st} total={vt}')
    else:
        sm.check('GET versions?status=<estados del Hito 5> → 200', st == 200 and isinstance(vt, int), f'HTTP {st}')
        sm.info('versions en estados del Hito 5', f'total={vt}')

    # GET engine/readings: el extra governance.studioPublication (describe() del motor, sin id del canario).
    st, e = sm.call('GET', S + '/engine/readings', token=token)
    extra = ((data_of(e) or {}).get('extras') or {}).get('governance.studioPublication')
    extra = extra if isinstance(extra, dict) else {}
    val = extra.get('value') if isinstance(extra.get('value'), dict) else None
    sm.check(f'engine/readings: governance.studioPublication disponible con env = {sm.scope}',
             st == 200 and extra.get('available') is True and val is not None and val.get('env') == sm.scope,
             f"HTTP {st} available={extra.get('available')} env={(val or {}).get('env')}")
    if val is not None:
        vhead = val.get('head')
        if sm.phase == '1':
            sm.check('engine/readings: sin cabeza en la fase 1', vhead is None)
        elif isinstance(vhead, dict):
            sm.check('engine/readings: la cabeza verifica entera (contrato, huella, firma, texto)',
                     vhead.get('contract') == 'ultron-engine-config/1' and vhead.get('hashOk') is True
                     and vhead.get('signatureOk') is True and vhead.get('parseOk') is True,
                     f"hash={vhead.get('hashOk')} firma={vhead.get('signatureOk')} texto={vhead.get('parseOk')}")
        configured = val.get('canaryConfigured')
        if sm.phase == '1':
            sm.info('engine/readings: canario del motor configurado (la fase 2 lo exige)', str(configured))
        else:
            sm.check('engine/readings: canario del motor configurado (sin él no se publica)', configured is True,
                     str(configured))

    # Intentos que el servidor rechaza sin escribir (uuid inventado).
    if sm.phase == '1':
        want_status, want_code = 409, 'publishing_disabled'
    else:
        want_status, want_code = 404, 'version_not_found'
    sm.expect(f'POST versions/<inventado>/publish → {want_status} {want_code}', 'POST', f'{S}/versions/{FAKE}/publish',
              {'clientRequestId': new_cr(), 'previewHash': '0' * 64, 'acknowledgedNoEffect': []}, token,
              want_status, want_code)
    sm.expect(f'POST versions/<inventado>/promote → {want_status} {want_code}', 'POST', f'{S}/versions/{FAKE}/promote',
              {'clientRequestId': new_cr(), 'evidenceHash': '0' * 64}, token, want_status, want_code)
    sm.expect('POST versions/<inventado>/rollback → 404 version_not_found', 'POST', f'{S}/versions/{FAKE}/rollback',
              {'clientRequestId': new_cr(), 'reason': 'Smoke del Hito 5: uuid inventado, sin efecto'}, token,
              404, 'version_not_found')
    if sm.phase == '1':
        keys_closed = publishing.get('available') is False
        if chain_empty and head is None and keys_closed:
            sm.expect('POST publications/legacy → 409 already_legacy (cadena vacía)', 'POST', f'{S}/publications/legacy',
                      {'clientRequestId': new_cr(), 'reason': 'Smoke del Hito 5: cadena vacía, sin efecto'}, token,
                      409, 'already_legacy')
        else:
            sm.skip('POST publications/legacy → 409 already_legacy',
                    'la cadena no está vacía o la llave del Studio está abierta: el intento podría escribir')
    sm.expect('GET versions/<inventado>/publication-preview → 404 version_not_found', 'GET',
              f'{S}/versions/{FAKE}/publication-preview', None, token, 404, 'version_not_found')
    sm.expect('GET versions/<inventado>/canary-evidence → 404 version_not_found (1 llamada al limitador propio)', 'GET',
              f'{S}/versions/{FAKE}/canary-evidence', None, token, 404, 'version_not_found')

    # Una versión APPROVED real, si la hay: su vista previa (solo lee; no se publica nada).
    st, a = sm.call('GET', S + '/versions?status=APPROVED&page=1&per_page=5', token=token)
    items = (data_of(a) or {}).get('data')
    if st != 200 or not isinstance(items, list):
        sm.check('GET versions?status=APPROVED → 200', False, f'HTTP {st}')
    elif not items:
        sm.info('vista previa de una versión APPROVED real', 'no comprobado: no hay ninguna APPROVED')
    else:
        vid = str(items[0].get('uuid'))
        sm.labels[vid] = '<APPROVED>'
        st, pv = sm.call('GET', f'{S}/versions/{vid}/publication-preview', token=token)
        prev = data_of(pv) or {}
        blocking = prev.get('blocking') if isinstance(prev.get('blocking'), list) else None
        keys = (prev.get('requirements') or {}).get('keys')
        base = st == 200 and blocking is not None and HEX64.match(str(prev.get('previewHash') or '')) is not None
        if sm.phase == '1':
            sm.check('vista previa de una APPROVED real → 200, no publicable y blocking con publishing_disabled',
                     base and prev.get('publishable') is False and 'publishing_disabled' in blocking
                     and keys == {'studio': False, 'engine': 'off'},
                     f'HTTP {st} blocking={blocking} keys={keys}')
        else:
            sm.check('vista previa de una APPROVED real → 200 sin publishing_disabled ni engine_not_reading_versions',
                     base and 'publishing_disabled' not in blocking and 'engine_not_reading_versions' not in blocking
                     and keys == {'studio': True, 'engine': sm.scope},
                     f'HTTP {st} blocking={blocking} keys={keys}')
        sm.info('versiones APPROVED en la API', str((data_of(a) or {}).get('total')))

    # Sin token: las 5 rutas nuevas existen en la caché de rutas y piden sesión.
    for method, path in [('GET', f'{S}/versions/{FAKE}/publication-preview'),
                         ('GET', f'{S}/versions/{FAKE}/canary-evidence'),
                         ('POST', f'{S}/versions/{FAKE}/promote'),
                         ('GET', f'{S}/publications'),
                         ('POST', f'{S}/publications/legacy')]:
        sm.expect(f'sin token: {sm.label(method, path)} → 401 admin_token_required', method, path,
                  {} if method == 'POST' else None, None, 401, 'admin_token_required')

    # Hito 4 intacto (una llamada): el laboratorio responde.
    st, lab = sm.call('GET', S + '/lab/status', token=token)
    lab = data_of(lab) or {}
    sm.check('GET lab/status → 200 (Hito 4 intacto)', st == 200 and isinstance(lab.get('enabled'), bool),
             f"HTTP {st} enabled={lab.get('enabled')} mode={lab.get('mode')}")


# ── Parte 2: Administrador sin ultron.publish ni ultron.rollback ───────────

def run_administrador(sm, email, password):
    token, perms = sm.login(email, password)
    if not token:
        return
    has_view = 'ultron.view' in perms
    has_publish = 'ultron.publish' in perms
    has_rollback = 'ultron.rollback' in perms
    sm.check('Administrador: la sesión trae ultron.view y NO ultron.publish ni ultron.rollback',
             has_view and not has_publish and not has_rollback,
             f'view={has_view} publish={has_publish} rollback={has_rollback}')
    new_cr = lambda: str(uuid.uuid4())

    st, s = sm.call('GET', S + '/status', token=token)
    can = (data_of(s) or {}).get('can') or {}
    sm.check('Administrador: GET status → 200 con can.publish = false y can.rollback = false',
             st == 200 and can.get('publish') is False and can.get('rollback') is False,
             f"HTTP {st} publish={can.get('publish')} rollback={can.get('rollback')}")
    forbidden = lambda perm: (lambda r: isinstance(r, dict) and r.get('required_permission') == perm)
    if has_publish:
        why = 'la sesión trae ultron.publish (concedida en Roles): no se envía nada que pudiera aceptarse'
        sm.skip('Administrador: POST publish → 403', why)
        sm.skip('Administrador: POST promote → 403', why)
    else:
        sm.expect('Administrador: POST versions/<inventado>/publish → 403 forbidden (ultron.publish)', 'POST',
                  f'{S}/versions/{FAKE}/publish',
                  {'clientRequestId': new_cr(), 'previewHash': '0' * 64, 'acknowledgedNoEffect': []}, token,
                  403, 'forbidden', forbidden('ultron.publish'))
        sm.expect('Administrador: POST versions/<inventado>/promote → 403 forbidden (ultron.publish)', 'POST',
                  f'{S}/versions/{FAKE}/promote', {'clientRequestId': new_cr(), 'evidenceHash': '0' * 64}, token,
                  403, 'forbidden', forbidden('ultron.publish'))
    if has_rollback:
        why = 'la sesión trae ultron.rollback (concedida en Roles): no se envía nada que pudiera aceptarse'
        sm.skip('Administrador: POST rollback → 403', why)
        sm.skip('Administrador: POST publications/legacy → 403', why)
    else:
        sm.expect('Administrador: POST versions/<inventado>/rollback → 403 forbidden (ultron.rollback)', 'POST',
                  f'{S}/versions/{FAKE}/rollback',
                  {'clientRequestId': new_cr(), 'reason': 'Smoke del Hito 5: sin llave, sin efecto'}, token,
                  403, 'forbidden', forbidden('ultron.rollback'))
        sm.expect('Administrador: POST publications/legacy → 403 forbidden (ultron.rollback)', 'POST',
                  f'{S}/publications/legacy',
                  {'clientRequestId': new_cr(), 'reason': 'Smoke del Hito 5: sin llave, sin efecto'}, token,
                  403, 'forbidden', forbidden('ultron.rollback'))
    st, p = sm.call('GET', S + '/publications?page=1&per_page=5', token=token)
    sm.check('Administrador: GET publications → 200 (lee el historial)',
             st == 200 and isinstance((data_of(p) or {}).get('total'), int), f'HTTP {st}')
    sm.expect('Administrador: GET versions/<inventado>/publication-preview → 404 (lee; la versión no existe)', 'GET',
              f'{S}/versions/{FAKE}/publication-preview', None, token, 404, 'version_not_found')


def main(argv):
    part = argv[1] if len(argv) > 1 else ''
    phase = os.environ.get('SMOKE_PHASE', '1')
    email = os.environ.get('SMOKE_EMAIL', '')
    password = os.environ.get('SMOKE_PW', '')
    canary = os.environ.get('SMOKE_CANARY_ID', '').strip()
    if part not in ('superadmin', 'administrador') or phase not in SCOPES:
        print('uso: SMOKE_PHASE=1|2|3 SMOKE_EMAIL=… SMOKE_PW=… smoke-h5.py superadmin|administrador')
        return 2
    if not email or not password:
        print('faltan SMOKE_EMAIL o SMOKE_PW (la contraseña solo por entorno)')
        return 2
    if canary and canary != 'none' and not canary.isdigit():
        print('SMOKE_CANARY_ID tiene que ser un número o «none»')
        return 2
    sm = Smoke(phase, part, canary)
    print(f'== Smoke del Hito 5 · fase {phase} (motor {sm.scope}) · {part} · {API}', flush=True)
    (run_superadmin if part == 'superadmin' else run_administrador)(sm, email, password)
    sm.scan()
    c = sm.counts
    print(f"RESUMEN ({part}, fase {phase}): {c['OK']} OK · {c['FALLO']} FALLO · {c['OMITIDO']} OMITIDO", flush=True)
    return sm.exit_code()


if __name__ == '__main__':
    sys.exit(main(sys.argv))

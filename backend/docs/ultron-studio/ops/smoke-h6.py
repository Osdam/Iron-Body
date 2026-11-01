#!/usr/bin/env python3
"""Smoke del Hito 6 de ULTRON STUDIO (integraciones comerciales), por fases.

  Fase 1 (SMOKE_PHASE=1, el defecto): código desplegado con las escrituras y la sonda APAGADAS
          (ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED=false, ULTRON_STUDIO_TWILIO_PROBE_ENABLED=false):
          las escrituras 47–54 → 409 integrations_writes_disabled y la sonda → 409 probe_disabled.
  Fase 2 (SMOKE_PHASE=2): escrituras encendidas (la sonda, encendida o no: SMOKE_PROBE_ENABLED). Las escrituras ya
          no se paran en la bandera, pero el smoke sigue sin escribir: usa recursos inventados (404) o cuerpos que el
          servidor rechaza por forma (422) antes de escribir.

POLÍTICA: SIN FILAS POSITIVAS. Las únicas peticiones que no son GET son intentos que el servidor rechaza ANTES de
escribir, según el orden del contrato (ULTRON_STUDIO_H6_CONTRACT.md §I3.4, §I4.4, §I4.9, §I5.4 e §I7.3:
puerta → forma (422) → permiso → bandera (409) → reintento → recurso (404) → identidad → estado):
  · 47 asociar: campaña con id opaco INVENTADO → fase 1: 409 de bandera; fase 2: 404 campaign_not_found (no hay campaña
    que resolver, luego no hay fila que insertar);
  · 48, 50–54: uuid INVENTADO → fase 1: 409 de bandera; fase 2: 404 link_not_found / canary_not_found (no hay fila);
  · 49 registrar un número: fase 1, con la bandera apagada vista en ESTA ejecución y una versión esperada inventada
    (si la bandera estuviera abierta, el 404 version_not_found llega antes del INSERT); fase 2, siempre con un cuerpo
    inválido (teléfono y consentimiento) → 422: con las escrituras abiertas, nunca se envía un registro válido;
  · 56 sonda: con la bandera apagada vista en esta ejecución → 409 probe_disabled; si no, cuerpo inválido → 422;
  · 55 reconocer la configuración ESCRIBE: el Super Admin no la llama nunca; el Administrador, con un cuerpo inválido,
    solo para ver el 403 de la puerta (sin la llave, la puerta lo para antes de leer el cuerpo);
  · con el Administrador, los 403 de la puerta (50, 55 y 56) solo se envían si su sesión NO trae la llave.
Los rechazos por bandera no se auditan (§I8); los 403 de la puerta dejan una línea WARNING en el log y nada en la base.
El lanzador (smoke-run-h6.sh) comprueba además en la base que las 14 tablas ultron_studio_* no cambian.

Uso:   SMOKE_EMAIL=… SMOKE_PW=… python3 smoke-h6.py superadmin|administrador
       (la contraseña SOLO por entorno, nunca en argv)
Variables: SMOKE_API (por defecto https://api.ironbodyneiva.cloud/api) · SMOKE_PHASE 1|2 ·
           SMOKE_PROBE_ENABLED true|false (la configuración efectiva de la sonda; en la fase 1, false) ·
           SMOKE_CANARY_ID (id de la conversación canaria, o «none»; nunca se imprime) ·
           SMOKE_META_ACCOUNTS (cuentas de Meta configuradas; 3 por defecto) ·
           SMOKE_SECRET_SALT y SMOKE_SECRET_DIGESTS («L:sha256(sal+valor) …» de los secretos del servidor, o «none»;
           los calcula el lanzador sin sacar los valores de PHP) ·
           SMOKE_TOKEN_FILE (deja «Authorization: Bearer …» en 0600 para que el lanzador compruebe que el token muere) ·
           SMOKE_EXPECT_CATALOG (2026-10-19.1).
Salida: una línea por comprobación (OK / FALLO / OMITIDO; INFO y AVISO no cuentan) y un RESUMEN.
Código: 0 todo OK · 1 algún FALLO · 3 sin fallos pero con algo OMITIDO · 2 uso incorrecto.
Cupos: 2 logins por ejecución (8/min por IP), 9 POST sin token (30/min por IP) y 2 llamadas a la sonda por persona
       (6/h): COMO MUCHO 3 EJECUCIONES POR HORA.
"""
import hashlib
import json
import os
import re
import sys
import urllib.error
import urllib.request
import uuid

API = os.environ.get('SMOKE_API', 'https://api.ironbodyneiva.cloud/api').rstrip('/')
S = '/admin/ultron-studio'
I = S + '/integrations'
# uuid inventado (versión 4 bien formada): ningún número, asociación ni versión real lo tiene.
FAKE = '00000000-0000-4000-8000-0000000006a6'
# id opaco inventado: los de verdad son 16 hex de un HMAC con la APP_KEY (probabilidad de choque 2^-64).
FAKE_CAMPAIGN = 'campaign:0000000000000000'
# Un móvil colombiano con forma válida (^\+573\d{9}$) en un prefijo sin asignar (399): solo viaja en la fase 1,
# con la bandera apagada vista en esta ejecución y con una versión esperada inventada.
FAKE_PHONE = '+573999999999'
REASON = 'Smoke del Hito 6: recurso inventado, sin efecto'
ALIAS = 'Smoke H6 (sin efecto)'
EXPECT_CATALOG = os.environ.get('SMOKE_EXPECT_CATALOG', '2026-10-19.1')
PROVIDERS = {'meta_ads', 'twilio', 'twilio_voice', 'canaries', 'elevenlabs'}
VERDICTS = {'in_sync', 'engine_canary_not_registered', 'engine_canary_registered_inactive', 'registry_has_extra_active',
            'engine_canary_phone_unusable', 'no_engine_canary', 'unreadable'}
CANARY_STATES = {'pending', 'authorized', 'active', 'revoked'}
ACTIONS = {'authorize', 'activate', 'deactivate', 'revoke', 'expectation'}
CONFIG_STATES = {'authorized', 'drift', 'never_authorized'}
PROBE_OUTCOMES = {'ok', 'degraded', 'auth_failed', 'unreachable', 'error'}
MISSING_H6 = ['api_key', 'agent_id', 'webhook_secret']
HEX64 = re.compile(r'^[0-9a-f]{64}$')
HEX8 = re.compile(r'^[0-9a-f]{8}$')
OPAQUE_CAMPAIGN = re.compile(r'^campaign:[0-9a-f]{16}$')
OPAQUE_ACCOUNT = re.compile(r'^account:[0-9a-f]{16}$')
MASKED_REF = re.compile(r'^\*{3}[0-9]{4}$')

# ── detector de fugas (puro: test_find_leaks_h6.py lo llama sin red) ────────────────────────────────────────────
# Claves que no pueden aparecer en NINGÚN cuerpo del Studio (contrato §I2, §I4.5, §I4.9, §I5.2, §I5.4 e §I13).
FORBIDDEN_KEYS = {
    'phone', 'telefono', 'phone_hash', 'phoneHash', 'phone_encrypted', 'phoneEncrypted', 'phone_e164', 'e164',
    'engineHash', 'engine_hash', 'to_phone', 'from_phone', 'toPhone', 'fromPhone', 'transcript', 'lead_id', 'leadId',
    'campaign_id', 'campaignId', 'ad_account_id', 'adAccountId', 'entity_id', 'entityId',
    'access_token', 'accessToken', 'auth_token', 'api_key', 'key_secret', 'webhook_secret', 'tool_secret',
    'friendly_name', 'friendlyName', 'phone_number', 'phoneNumber', 'alert_text', 'alertText', 'request_url',
    'requestUrl', 'request_variables', 'requestVariables', 'response_body', 'responseBody',
    'effective_json', 'effectiveJson',
}
# Contenedores de un secreto: su valor tiene que ser un objeto con solo estas piezas (presencia, origen, huella de 8
# hex, referencia corta o 4 dígitos). Un texto ahí sería el valor mismo.
SECRET_CONTAINERS = {'apiKey', 'webhookSecret', 'toolSecret', 'authToken', 'accountSid', 'verifyServiceSid', 'keySid',
                     'keySecret', 'agentId', 'phoneNumberId', 'token'}
CONTAINER_PARTS = {'present', 'source', 'fp', 'ref', 'last4'}
CONVERSATION_KEY = re.compile(r'conversation', re.I)
CANARY_KEY = re.compile(r'canar|conversation|chat', re.I)
ENV_SECRET_KEY = re.compile(r'^[A-Z][A-Z0-9_]{2,}_(?:KEY|TOKEN|SECRET|PASSWORD|SID)$')
SECRET_NAMES = re.compile(r'OPENAI_|APP_KEY')                       # nombres: fuga en claves y en valores que no son texto libre
SERVER_PATHS = re.compile(r'/var/www|app/Services/|vendor/laravel')  # rutas del servidor: fuga en cualquier texto
SECRET_VALUES = re.compile(
    r'\b(?:AC|SK|VA|PN|CA|MG|IS|MM|SM|RE|CF|PK|CR)[0-9a-f]{32}\b'   # SID completos de Twilio (cuenta, clave, Verify, números, llamadas…)
    r'|\bEAA[A-Za-z0-9]{20,}'                                       # token de Meta
    r'|\bsk-[A-Za-z0-9_-]{8,}|\bsk_[A-Za-z0-9]{20,}|\bwsec_[A-Za-z0-9]{16,}'   # OpenAI, ElevenLabs (clave y secreto del webhook)
    r'|base64:[A-Za-z0-9+/=]{20,}'                                  # APP_KEY
    r'|\b(?:Bearer|Basic)\s+[A-Za-z0-9._~+/=-]{16,}|xi-api-key')
HEX32 = re.compile(r'(?<![0-9a-fA-F])[0-9a-f]{32}(?![0-9a-fA-F])')   # token de Twilio o secreto de 32 hex suelto
FREE_TEXT_KEYS = {'reason', 'label', 'note', 'message', 'name', 'title', 'description', 'text', 'summary',
                  'statusLabel', 'nameAtLink', 'currentName', 'alias'}
NOISE = re.compile(r'[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'
                   r'|[0-9a-fA-F]{32,}'
                   r'|\d{4}-\d{2}-\d{2}(?:[T ][0-9:.]+(?:Z|[+-]\d{2}:?\d{2})?)?(?:\.\d+)?')
# Un teléfono o un id de Meta: 10 o más cifras seguidas, o con separadores de teléfono (espacio, punto, guion, paréntesis).
PHONE_OR_ID = re.compile(r'(?<![0-9A-Za-z])\+?[0-9](?:[ .()-]?[0-9]){9,}(?![0-9A-Za-z])')
LEAK_KINDS = ('forbidden', 'container', 'secret', 'phone_or_id', 'conversation', 'canary', 'sentinel')


def pairs(node, path=()):
    """(tipo, ruta del padre, clave, valor) de cada nodo de un JSON: las claves y las hojas con su clave más cercana."""
    if isinstance(node, dict):
        for k, v in node.items():
            yield 'key', path, str(k), v
            yield from pairs(v, path + (str(k),))
    elif isinstance(node, list):
        for i, v in enumerate(node):
            yield from pairs(v, path + (i,))
    else:
        key = next((p for p in reversed(path) if isinstance(p, str)), None)
        yield 'leaf', path, key, node


def parse_digests(raw):
    """«L:hex L:hex …» → {L: {hex, …}}; «none» o vacío → {}."""
    out = {}
    for item in (raw or '').split():
        if item == 'none':
            continue
        n, _, h = item.partition(':')
        if n.isdigit() and int(n) >= 8 and HEX64.match(h):
            out.setdefault(int(n), set()).add(h)
    return out


def sentinel_hit(text, salt, digests):
    """¿Contiene `text` alguno de los secretos cuyas huellas saladas trae `digests`? Ventana deslizante por longitud."""
    if not digests or not text:
        return False
    base = hashlib.sha256(salt)
    for n, wanted in digests.items():
        if len(text) < n:
            continue
        for i in range(len(text) - n + 1):
            h = base.copy()
            h.update(text[i:i + n].encode('utf-8', 'surrogatepass'))
            if h.hexdigest() in wanted:
                return True
    return False


def _strings_of(raw, parsed):
    if parsed is None:
        return [raw]
    return [k for kind, _, k, _ in pairs(parsed) if kind == 'key'] + \
           [v for kind, _, _, v in pairs(parsed) if kind == 'leaf' and isinstance(v, str)]


def find_leaks(bodies, canary_id='', salt=b'', digests=None):
    """{tipo: {etiquetas}} de los cuerpos con cada tipo de fuga. Pura: no toca la red."""
    found = {k: set() for k in LEAK_KINDS}
    digits = canary_id if canary_id and canary_id.isdigit() else ''
    loose = len(digits) >= 4   # un id corto aparece por casualidad en recuentos y horas: solo bajo su clave
    for label, raw, parsed in bodies:
        raw = raw or ''
        if digests and any(sentinel_hit(s, salt, digests) for s in _strings_of(raw, parsed)):
            found['sentinel'].add(label)
        if parsed is None:   # una página de error o una traza sin JSON: se mira entera
            if SECRET_VALUES.search(raw) or SERVER_PATHS.search(raw) or SECRET_NAMES.search(raw) \
                    or re.search(r'"(effective_json|effectiveJson|phone_hash|engineHash)"\s*:', raw):
                found['secret'].add(label)
            continue
        for kind, path, k, v in pairs(parsed):
            if kind == 'key':
                parent = path[-2] if len(path) >= 2 else None
                if k in FORBIDDEN_KEYS and path != ('errors',):   # en el 422 estándar, «errors.phone» es el nombre del campo
                    found['forbidden'].add(label)
                elif k == 'signature' and not (parent == 'webhooks' and isinstance(path[-1], int)
                                               and isinstance(v, str) and not re.search(r'[0-9a-fA-F]{32}', v)):
                    found['forbidden'].add(label)   # solo vale la descripción fija del webhook de Twilio (§I5.2)
                if k in SECRET_CONTAINERS and not (v is None or isinstance(v, bool)):   # aliasesConsistent.token es un booleano
                    bad = not isinstance(v, dict) or not set(v) <= CONTAINER_PARTS \
                        or ('fp' in v and not (isinstance(v['fp'], str) and HEX8.match(v['fp']))) \
                        or ('ref' in v and not (isinstance(v['ref'], str) and len(v['ref']) <= 12)) \
                        or ('last4' in v and not (isinstance(v['last4'], str) and re.fullmatch(r'[0-9]{4}', v['last4'])))
                    if bad:
                        found['container'].add(label)
                if CONVERSATION_KEY.search(k):
                    found['conversation'].add(label)
                if SECRET_NAMES.search(k) or SERVER_PATHS.search(k) or SECRET_VALUES.search(k) or ENV_SECRET_KEY.match(k):
                    found['secret'].add(label)
                if PHONE_OR_ID.search(NOISE.sub(' ', k)):
                    found['phone_or_id'].add(label)   # un teléfono o un id de Meta como clave (p. ej. un recuento por número)
                continue
            if isinstance(v, str):
                if SECRET_VALUES.search(v) or SERVER_PATHS.search(v) or HEX32.search(v) \
                        or (k not in FREE_TEXT_KEYS and SECRET_NAMES.search(v)):
                    found['secret'].add(label)
                if PHONE_OR_ID.search(NOISE.sub(' ', v)):
                    found['phone_or_id'].add(label)
            elif isinstance(v, int) and not isinstance(v, bool) and abs(v) >= 1_000_000_000:
                found['phone_or_id'].add(label)
            if not digits or isinstance(v, bool):
                continue
            if isinstance(v, int) and str(v) == digits and (loose or (k is not None and CANARY_KEY.search(k))):
                found['canary'].add(label)
            elif isinstance(v, str) and (loose or (k is not None and CANARY_KEY.search(k))):
                if re.search(r'(?<!\d)' + re.escape(digits) + r'(?!\d)', NOISE.sub(' ', v)):
                    found['canary'].add(label)
    return found


# ── utilidades ─────────────────────────────────────────────────────────────────────────────────────────────────

def data_of(body):
    return body.get('data') if isinstance(body, dict) else None


def code_of(body):
    return body.get('code') if isinstance(body, dict) else None


def is_page(p, per_page=None):
    return isinstance(p, dict) and isinstance(p.get('data'), list) and isinstance(p.get('total'), int) \
        and (per_page is None or p.get('per_page') == per_page)


def short(x, n=160):
    s = json.dumps(x, ensure_ascii=False, sort_keys=True) if not isinstance(x, str) else x
    return s if len(s) <= n else s[:n] + '…'


class Smoke:
    def __init__(self, phase, part, canary_id, probe_enabled, meta_accounts, salt, digests):
        self.phase = phase
        self.part = part
        self.canary_id = canary_id
        self.probe_enabled = probe_enabled
        self.meta_accounts = meta_accounts
        self.salt = salt
        self.digests = digests
        self.counts = {'OK': 0, 'FALLO': 0, 'OMITIDO': 0}
        self.bodies = []        # (etiqueta, texto crudo, json) de cada respuesta del Studio
        self.statuses = []      # para el limitador (429)
        self.remaining = []     # X-RateLimit-Remaining vistos
        self.labels = {FAKE: '<inventado>'}
        self.writes_seen = None   # flags.writesEnabled que vio ESTA ejecución en GET integrations/status
        self.probe_seen = None    # flags.twilioProbeEnabled ídem
        self.verdict = None

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

    def warn(self, name, detail=''):
        self.line('AVISO', name, detail)

    def label(self, method, path):
        for real, alias in self.labels.items():
            path = path.replace(real, alias)
        path = path.replace(I + '/', 'integrations/').replace(S + '/', '')
        return f'{method} {path}'

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

    def expect_422(self, name, method, path, body, token, field):
        st, r = self.call(method, path, body, token)
        errors = r.get('errors') if isinstance(r, dict) else None
        self.check(name, st == 422 and isinstance(errors, dict) and field in errors,
                   f'HTTP {st} {code_of(r) or ""}'.strip() + (f' errores en {sorted(errors)}' if isinstance(errors, dict) else ''))

    # ── cierre ──────────────────────────────────────────────────────────────
    def scan(self):
        n = len(self.bodies)
        leaks = find_leaks(self.bodies, self.canary_id, self.salt, self.digests)
        joined = lambda s: ', '.join(sorted(s))[:240]
        self.check(f'cuerpos ({n}): sin claves prohibidas (teléfono, huellas de teléfono, ids de Meta, textos del proveedor, firmas)',
                   not leaks['forbidden'], joined(leaks['forbidden']))
        self.check(f'cuerpos ({n}): cada secreto solo como presencia, origen, huella de 8 hex o referencia corta',
                   not leaks['container'], joined(leaks['container']))
        self.check(f'cuerpos ({n}): sin credenciales de Twilio, Meta, ElevenLabs u OpenAI, sin APP_KEY y sin rutas del servidor',
                   not leaks['secret'], joined(leaks['secret']))
        self.check(f'cuerpos ({n}): sin teléfonos ni ids de Meta (10 cifras o más)', not leaks['phone_or_id'],
                   joined(leaks['phone_or_id']))
        self.check(f'cuerpos ({n}): ninguna clave nombra una conversación', not leaks['conversation'],
                   joined(leaks['conversation']))
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
        if self.digests:
            k = sum(len(v) for v in self.digests.values())
            self.check(f'cuerpos ({n}): ninguno contiene un secreto del servidor (centinela exacto, {k} huellas saladas)',
                       not leaks['sentinel'], joined(leaks['sentinel']))
        else:
            self.skip('cuerpos: centinela exacto de los secretos del servidor',
                      'sin SMOKE_SECRET_DIGESTS (las calcula el lanzador en el servidor)')
        self.check('limitador: ninguna respuesta 429', 429 not in self.statuses,
                   f'{self.statuses.count(429)} respuestas 429')
        if self.remaining:
            self.info('limitador: mínimo X-RateLimit-Remaining en las rutas del Studio', str(min(self.remaining)))
        if 0 in self.statuses:
            self.check('red: todas las peticiones tuvieron respuesta', False, f'{self.statuses.count(0)} sin respuesta')
        fives = sum(1 for s in self.statuses if 500 <= s <= 599)
        self.check('ninguna respuesta 5xx', fives == 0, f'{fives} respuestas 5xx')

    def exit_code(self):
        if self.counts['FALLO']:
            return 1
        return 3 if self.counts['OMITIDO'] else 0


# ── lecturas (39–46) ───────────────────────────────────────────────────────────────────────────────────────────

def can_from(perms):
    return {'campaignsManage': 'ultron.campaigns_manage' in perms, 'canaryManage': 'ultron.canary_manage' in perms,
            'canaryAuthorize': 'ultron.canary_authorize' in perms,
            'integrationsManage': 'ultron.integrations_manage' in perms}


def expected_verdicts(sm):
    """Veredictos aceptables en esta fase (fase 1: registro vacío)."""
    if sm.phase == '1':
        if sm.canary_id == 'none':
            return {'no_engine_canary'}
        if sm.canary_id:
            return {'engine_canary_not_registered'}
        return {'engine_canary_not_registered', 'no_engine_canary'}
    return VERDICTS - {'unreadable'}


def check_status(sm, token, perms, who):
    st, b = sm.call('GET', I + '/status', token=token)
    d = data_of(b) or {}
    sm.check(f'{who}GET integrations/status → 200', st == 200 and isinstance(d, dict), f'HTTP {st} {code_of(b) or ""}'.strip())
    can = d.get('can') if isinstance(d.get('can'), dict) else {}
    sm.check(f'{who}status: can coincide con las llaves de la sesión', can == can_from(perms), short(can))
    flags = d.get('flags') if isinstance(d.get('flags'), dict) else {}
    want_w, want_p = sm.phase == '2', sm.probe_enabled
    sm.writes_seen, sm.probe_seen = flags.get('writesEnabled'), flags.get('twilioProbeEnabled')
    sm.check(f'{who}status: flags = writesEnabled {str(want_w).lower()} · twilioProbeEnabled {str(want_p).lower()}',
             flags.get('writesEnabled') is want_w and flags.get('twilioProbeEnabled') is want_p, short(flags))
    provs = {p.get('id'): p for p in (d.get('providers') or []) if isinstance(p, dict)}
    sm.check(f'{who}status: los 5 proveedores, todos available (ninguna fuente falló)',
             set(provs) == PROVIDERS and all(p.get('available') is True for p in provs.values()),
             ' '.join(f"{k}:{p.get('available')}" for k, p in sorted(provs.items())))
    if who:   # el Administrador solo comprueba la forma y los permisos: las clasificaciones las mira el Super Admin
        return d
    meta = provs.get('meta_ads', {})
    ev = meta.get('evidence') if isinstance(meta.get('evidence'), dict) else {}
    if meta.get('classification') == 'connected_read':
        sm.check('status: meta_ads connected_read', True, f"state={meta.get('state')}")
    elif meta.get('classification') == 'degraded':
        sm.info('status: meta_ads degraded (se informa como dato, no es un fallo del despliegue)', f"reason={meta.get('reason')}")
    else:
        sm.check('status: meta_ads connected_read o degraded', False, f"{meta.get('classification')} reason={meta.get('reason')}")
    sm.check(f'status: meta_ads con {sm.meta_accounts} cuentas y la sincronización encendida',
             ev.get('accounts') == sm.meta_accounts and ev.get('syncEnabled') is True,
             f"cuentas={ev.get('accounts')} sync={ev.get('syncEnabled')} graph={ev.get('graphVersion')}")
    sm.info('status: meta_ads última pasada con éxito', f"hace {ev.get('minutesSinceSuccess')} min (umbral {ev.get('staleAfterMinutes')} min)")
    tw = provs.get('twilio', {})
    tev = tw.get('evidence') if isinstance(tw.get('evidence'), dict) else {}
    sm.check('status: twilio connected_otp_only (driver twilio, Verify activo)',
             tw.get('classification') == 'connected_otp_only' and tev.get('otpDriver') == 'twilio' and tev.get('verifyActive') is True,
             f"{tw.get('classification')} reason={tw.get('reason')}")
    tv = provs.get('twilio_voice', {})
    tvev = tv.get('evidence') if isinstance(tv.get('evidence'), dict) else {}
    sm.check('status: twilio_voice not_configured (apagada y sin número)',
             tv.get('classification') == 'not_configured' and tvev.get('enabled') is False and tvev.get('fromConfigured') is False,
             f"{tv.get('classification')} {short(tvev)}")
    ca = provs.get('canaries', {})
    cev = ca.get('evidence') if isinstance(ca.get('evidence'), dict) else {}
    sm.verdict = cev.get('verdict')
    sm.check(f"status: canaries registry con veredicto {' o '.join(sorted(expected_verdicts(sm))) if sm.phase == '1' else '≠ unreadable'}",
             ca.get('classification') == 'registry' and sm.verdict in expected_verdicts(sm), f"{ca.get('classification')} {sm.verdict}")
    if sm.phase == '1':
        sm.check('status: canaries sin números (0 activos, 0 pendientes, 0 autorizados)',
                 cev.get('active') == {'whatsapp': 0, 'voice': 0} and cev.get('pending') == 0 and cev.get('authorized') == 0,
                 short({k: cev.get(k) for k in ('active', 'pending', 'authorized')}))
    else:
        sm.info('status: canaries', short({k: cev.get(k) for k in ('active', 'pending', 'authorized', 'verdict')}))
    el = provs.get('elevenlabs', {})
    eev = el.get('evidence') if isinstance(el.get('evidence'), dict) else {}
    sm.check('status: elevenlabs not_configured · ELEVENLABS_NOT_CONFIGURED · apagada · faltan api_key, agent_id y webhook_secret',
             el.get('classification') == 'not_configured' and el.get('code') == 'ELEVENLABS_NOT_CONFIGURED'
             and eev.get('enabled') is False and eev.get('missing') == MISSING_H6,
             f"{el.get('classification')} {el.get('code')} {short(eev)}")
    return d


def check_meta(sm, token):
    st, b = sm.call('GET', I + '/meta/campaigns?page=1&per_page=5', token=token)
    d = data_of(b) or {}
    conn = d.get('connection') if isinstance(d.get('connection'), dict) else {}
    camps = d.get('campaigns')
    sm.check('GET integrations/meta/campaigns → 200 con la conexión y el paginador (per_page=5 respetado)',
             st == 200 and bool(conn) and is_page(camps, 5), f'HTTP {st} {code_of(b) or ""}'.strip())
    state = conn.get('state')
    if state in ('ok', 'running'):
        sm.check('campaigns: conexión ok/running y sincronización encendida', conn.get('syncEnabled') is True and conn.get('reason') is None,
                 f"state={state} reason={conn.get('reason')} sync={conn.get('syncEnabled')}")
    elif state in ('stale', 'failed'):
        sm.info('campaigns: conexión degradada (dato viejo o pasada fallida; se informa)', f"state={state} reason={conn.get('reason')}")
    else:
        sm.check('campaigns: conexión configurada', False, f"state={state} reason={conn.get('reason')}")
    accs = conn.get('accounts') if isinstance(conn.get('accounts'), list) else []
    sm.check(f'campaigns: {sm.meta_accounts} cuentas, cada una con id opaco y referencia enmascarada (***NNNN)',
             len(accs) == sm.meta_accounts and all(isinstance(a, dict) and OPAQUE_ACCOUNT.match(str(a.get('id')))
                                                  and MASKED_REF.match(str(a.get('ref'))) for a in accs),
             f'{len(accs)} cuentas')
    sm.info('campaigns: antigüedad de la última pasada con éxito', f"hace {conn.get('minutesSinceSuccess')} min (umbral {conn.get('staleAfterMinutes')})")
    total = camps.get('total') if is_page(camps) else None
    # Todas las de la primera página grande (el contrato recorta per_page a 50): el barrido de fugas y la forma
    # cubren así cada nombre enmascarado, no solo los cinco primeros.
    st50, b50 = sm.call('GET', I + '/meta/campaigns?page=1&per_page=50', token=token)
    big = (data_of(b50) or {}).get('campaigns')
    sm.check('campaigns?per_page=50 → 200 (el máximo del contrato)', st50 == 200 and is_page(big, 50), f'HTTP {st50}')
    rows = big.get('data') if is_page(big) else []
    bad = []
    for r in rows:
        if not isinstance(r, dict):
            bad.append('fila no es un objeto')
            continue
        m = r.get('meta') if isinstance(r.get('meta'), dict) else {}
        age = m.get('ageMinutes')
        if not OPAQUE_CAMPAIGN.match(str(r.get('id'))):
            bad.append('id no opaco')
        if not MASKED_REF.match(str(r.get('ref'))):
            bad.append('ref sin enmascarar')
        if not OPAQUE_ACCOUNT.match(str((r.get('account') or {}).get('id'))):
            bad.append('cuenta no opaca')
        if not ('ageMinutes' in m and (age is None or (isinstance(age, int) and not isinstance(age, bool) and age >= 0))
                and isinstance(m.get('stale'), bool) and isinstance(m.get('possiblyDeleted'), bool)):
            bad.append('sin antigüedad/estado')
        if sm.phase == '1' and (r.get('ultron') or {}).get('link') is not None:
            bad.append('con asociación en la fase 1')
    sm.check(f'campaigns: {len(rows)} de {total} (per_page=50), con id opaco, referencia enmascarada, antigüedad y estado'
             + (' y sin asociación' if sm.phase == '1' else ''), not bad, ', '.join(sorted(set(bad))))
    if total == 0 and state in ('ok', 'running'):
        sm.warn('campaigns: ninguna campaña en el espejo con la conexión ok', 'revisar meta_ad_entities (114 filas al congelar)')
    ages = [((r.get('meta') or {}).get('ageMinutes')) for r in rows if isinstance(r, dict)]
    sm.info('campaigns: antigüedad del estado en Meta (min)', short(ages))
    st, b = sm.call('GET', I + '/meta/campaigns?linked=yes&page=1&per_page=5', token=token)
    lk = (data_of(b) or {}).get('campaigns')
    if sm.phase == '1':
        sm.check('campaigns?linked=yes → 200 y vacía (fase 1)', st == 200 and is_page(lk) and lk.get('total') == 0, f'HTTP {st}')
    else:
        sm.check('campaigns?linked=yes → 200', st == 200 and is_page(lk), f'HTTP {st}')
    st, b = sm.call('GET', I + '/meta/links?page=1&per_page=5', token=token)
    p = data_of(b)
    if sm.phase == '1':
        sm.check('GET integrations/meta/links → 200 y vacía (fase 1)', st == 200 and is_page(p, 5) and p.get('total') == 0, f'HTTP {st}')
    else:
        ok = st == 200 and is_page(p, 5) and all(isinstance(x, dict) and x.get('status') in ('active', 'inactive')
                                                  and OPAQUE_CAMPAIGN.match(str((x.get('campaign') or {}).get('id')))
                                                  and (x.get('target') or {}).get('kind') in ('baseline', 'version') for x in p['data'])
        sm.check('GET integrations/meta/links → 200 con asociaciones bien formadas', ok, f"HTTP {st} total={(p or {}).get('total')}")


def check_canaries(sm, token):
    st, b = sm.call('GET', I + '/canaries?page=1&per_page=5', token=token)
    d = data_of(b) or {}
    coh = d.get('coherence') if isinstance(d.get('coherence'), dict) else {}
    lim = d.get('limits') if isinstance(d.get('limits'), dict) else {}
    nums = d.get('numbers')
    sm.check('GET integrations/canaries → 200 con coherencia, topes y el paginador (per_page=5)',
             st == 200 and bool(coh) and bool(lim) and is_page(nums, 5), f'HTTP {st} {code_of(b) or ""}'.strip())
    sm.check(f"canaries: topes 3 activos · 10 vivos · país 57 · writesEnabled {str(sm.phase == '2').lower()}",
             lim.get('maxActivePerChannel') == 3 and lim.get('maxLivePerChannel') == 10 and lim.get('countryCodes') == ['57']
             and lim.get('writesEnabled') is (sm.phase == '2'), short(lim))
    if sm.canary_id:
        sm.check('canaries: engineCanaryConfigured coincide con el canario del motor',
                 coh.get('engineCanaryConfigured') is (sm.canary_id != 'none'), str(coh.get('engineCanaryConfigured')))
    v = coh.get('verdict')
    sm.check('canaries: el veredicto coincide con el de status y es de la fase', v == sm.verdict and v in expected_verdicts(sm), f'{v}')
    if v == 'registry_has_extra_active':
        sm.warn('canaries: hay más de un número de WhatsApp activo en el registro', 'el motor sigue atendiendo solo la conversación canaria')
    rows = nums.get('data') if is_page(nums) else []
    if sm.phase == '1':
        sm.check('canaries: registro vacío y 0 activos de WhatsApp (fase 1)',
                 nums.get('total') == 0 and rows == [] and coh.get('activeWhatsapp') == 0 if is_page(nums) else False,
                 f"total={(nums or {}).get('total') if isinstance(nums, dict) else None} activos={coh.get('activeWhatsapp')}")
        return
    bad = []
    for n in rows:
        if not isinstance(n, dict):
            bad.append('fila no es un objeto')
            continue
        if n.get('status') not in CANARY_STATES or n.get('channel') not in ('whatsapp', 'voice'):
            bad.append('estado o canal fuera del conjunto')
        if not re.match(r'^\+[0-9]{1,3} ', str(n.get('masked', ''))) or len(re.findall(r'[0-9]', str(n.get('masked', '')))) > 7:
            bad.append('número sin enmascarar')
        acts = n.get('actions') if isinstance(n.get('actions'), dict) else {}
        if set(acts) != ACTIONS or not all(isinstance(a, dict) and isinstance(a.get('allowed'), bool) for a in acts.values()):
            bad.append('acciones incompletas')
    sm.check(f"canaries: {len(rows)} de {nums.get('total') if is_page(nums) else '?'} números bien formados y enmascarados", not bad,
             ', '.join(sorted(set(bad))))
    sm.info('canaries: activos de WhatsApp en el registro', str(coh.get('activeWhatsapp')))


def check_twilio(sm, token):
    st, b = sm.call('GET', I + '/twilio', token=token)
    d = data_of(b) or {}
    sm.check('GET integrations/twilio → 200 connected_otp_only', st == 200 and d.get('classification') == 'connected_otp_only',
             f"HTTP {st} {d.get('classification')} {d.get('reason')}")
    otp = d.get('otp') if isinstance(d.get('otp'), dict) else {}
    sm.check('twilio: OTP por Twilio con Verify activo', otp.get('driver') == 'twilio' and otp.get('verifyActive') is True,
             f"driver={otp.get('driver')} verify={otp.get('verifyActive')}")
    cred = d.get('credentials') if isinstance(d.get('credentials'), dict) else {}
    sm.check('twilio: aliasesConsistent = {sid: true, token: true} (la misma credencial con dos nombres)',
             cred.get('aliasesConsistent') == {'sid': True, 'token': True}, short(cred.get('aliasesConsistent')))
    sid = cred.get('accountSid') if isinstance(cred.get('accountSid'), dict) else {}
    tok = cred.get('authToken') if isinstance(cred.get('authToken'), dict) else {}
    vsid = cred.get('verifyServiceSid') if isinstance(cred.get('verifyServiceSid'), dict) else {}
    sm.check('twilio: SID y Verify solo por referencia corta (AC…/VA…) y el token solo por huella de 8 hex',
             sid.get('present') is True and str(sid.get('ref', '')).startswith('AC') and len(str(sid.get('ref', ''))) <= 8
             and tok.get('present') is True and HEX8.match(str(tok.get('fp', ''))) is not None and 'ref' not in tok
             and vsid.get('present') is True and str(vsid.get('ref', '')).startswith('VA') and len(str(vsid.get('ref', ''))) <= 8,
             f"sid={sid.get('source')} token={tok.get('source')}")
    sm.check('twilio: apiBase y verifyBase oficiales', (cred.get('apiBase') or {}).get('official') is True
             and (cred.get('verifyBase') or {}).get('official') is True,
             f"api={short(cred.get('apiBase'))} verify={short(cred.get('verifyBase'))}")
    hooks = d.get('webhooks') if isinstance(d.get('webhooks'), list) else []
    sm.check('twilio: webhook usage_trigger descrito (firma, idempotencia, cupo, solo log)',
             any(isinstance(w, dict) and w.get('id') == 'usage_trigger' and w.get('persistence') for w in hooks), f'{len(hooks)} webhooks')
    voice = d.get('voice') if isinstance(d.get('voice'), dict) else {}
    sm.check('twilio: voz apagada y sin número (not_configured)',
             voice.get('enabled') is False and voice.get('fromConfigured') is False and voice.get('state') == 'not_configured', short(voice))
    calls = d.get('calls') if isinstance(d.get('calls'), dict) else {}
    sm.check('twilio: calls.total = 0 (nada llama; marketing_calls sin filas)', calls.get('total') == 0, str(calls.get('total')))
    probe = d.get('probe') if isinstance(d.get('probe'), dict) else {}
    sm.check(f'twilio: probe.enabled {str(sm.probe_enabled).lower()} · enfriamiento 300 s · credencial otp o restricted_key',
             probe.get('enabled') is sm.probe_enabled and probe.get('cooldownSeconds') == 300
             and probe.get('credential') in ('otp', 'restricted_key'), short({k: probe.get(k) for k in ('enabled', 'cooldownSeconds', 'credential')}))
    last = probe.get('last')
    if sm.phase == '1':
        sm.check('twilio: ninguna sonda todavía (probe.last = null)', last is None, 'null' if last is None else 'con instantánea')
    elif isinstance(last, dict):
        sm.info('twilio: última sonda', f"outcome={last.get('outcome')} at={last.get('at')}")
        sm.check('twilio: el resultado de la última sonda es del conjunto cerrado', last.get('outcome') in PROBE_OUTCOMES, str(last.get('outcome')))
    else:
        sm.info('twilio: última sonda', 'ninguna todavía')
    cost = d.get('cost') if isinstance(d.get('cost'), dict) else {}
    sm.info('twilio: gasto estimado del OTP hoy (USD)', f"{cost.get('estimatedSpentUsd')} de {cost.get('dailyHardLimitUsd')} (blando {cost.get('dailySoftLimitUsd')})")
    st, b = sm.call('GET', I + '/twilio/calls?page=1&per_page=5', token=token)
    p = data_of(b)
    sm.check('GET integrations/twilio/calls → 200 y vacía (nada llama)', st == 200 and is_page(p, 5) and p.get('total') == 0,
             f"HTTP {st} total={(p or {}).get('total') if isinstance(p, dict) else None}")


def check_voice(sm, token):
    st, b = sm.call('GET', I + '/voice', token=token)
    d = data_of(b) or {}
    el = d.get('elevenlabs') if isinstance(d.get('elevenlabs'), dict) else {}
    sm.check('GET integrations/voice → 200 con ELEVENLABS_NOT_CONFIGURED, apagada, sin verificación y región por defecto',
             st == 200 and el.get('state') == 'ELEVENLABS_NOT_CONFIGURED' and el.get('enabled') is False
             and el.get('missing') == MISSING_H6 and el.get('region') == 'default' and el.get('lastVerification') is None,
             f"HTTP {st} {el.get('state')} missing={el.get('missing')}")
    tv = d.get('twilioVoice') if isinstance(d.get('twilioVoice'), dict) else {}
    sm.check('voice: Twilio Voice apagada, sin número y con cupo diario 0',
             tv == {'enabled': False, 'fromConfigured': False, 'dailyCallCap': 0}, short(tv))
    pol = d.get('policy') if isinstance(d.get('policy'), dict) else {}
    reasons = pol.get('reasons') if isinstance(pol.get('reasons'), list) else []
    sm.check('voice: policy.allowed = false con calls_disabled_in_code el primero (en este hito no se llama a nadie)',
             pol.get('allowed') is False and reasons[:1] == ['calls_disabled_in_code']
             and {'twilio_voice_disabled', 'no_voice_number', 'elevenlabs_not_configured', 'daily_cap_zero'} <= set(reasons),
             short(reasons))
    lv = {x.get('id'): x for x in (d.get('levels') or []) if isinstance(x, dict)}
    sm.check('voice: niveles text_simulation disponible; integration_test y real_call no',
             (lv.get('text_simulation') or {}).get('available') is True and (lv.get('integration_test') or {}).get('available') is False
             and (lv.get('real_call') or {}).get('available') is False, short({k: v.get('available') for k, v in lv.items()}))


def check_config(sm, token):
    st, b = sm.call('GET', I + '/config', token=token)
    d = data_of(b) or {}
    provs = [p for p in (d.get('providers') or []) if isinstance(p, dict)]
    sm.check('GET integrations/config → 200 con meta_ads, twilio y voice en ese orden',
             st == 200 and [p.get('provider') for p in provs] == ['meta_ads', 'twilio', 'voice'], f"HTTP {st} {[p.get('provider') for p in provs]}")
    states = {}
    for p in provs:
        name, state = p.get('provider'), p.get('state')
        eff, auth, obs = p.get('effectiveFingerprint'), p.get('authorized'), p.get('observed')
        states[name] = state
        coherent = HEX64.match(str(eff)) is not None and state in CONFIG_STATES and (
            (state == 'never_authorized' and auth is None)
            or (state == 'authorized' and isinstance(auth, dict) and auth.get('fingerprint') == eff)
            or (state == 'drift' and isinstance(auth, dict) and auth.get('fingerprint') != eff and isinstance(p.get('drift'), list) and p['drift']))
        sm.check(f'config: {name} con estado coherente con sus huellas', coherent, f'{state}')
        if sm.phase == '1':
            sm.check(f'config: {name} never_authorized o authorized en la fase 1 (sin deriva)', state in ('never_authorized', 'authorized'), f'{state}')
        if not isinstance(obs, dict) or not HEX64.match(str(obs.get('fingerprint'))):
            sm.check(f'config: {name} con su «observado» (la instantánea de sistema del despliegue)', False, 'sin observado')
        elif obs.get('fingerprint') == eff:
            sm.check(f'config: {name} observado = configuración efectiva (nada cambió desde la última instantánea)', True)
        elif sm.phase == '1':
            sm.check(f'config: {name} observado = configuración efectiva', False, 'la configuración cambió desde la instantánea del despliegue')
        else:
            sm.warn(f'config: {name} cambió desde la última instantánea de sistema', 'ejecutar ultron-studio:integrations-snapshot (lo hace h6-integrations-scope.sh)')
        eff_facts = p.get('effective') if isinstance(p.get('effective'), dict) else {}
        if name == 'twilio':
            pr = eff_facts.get('probe') if isinstance(eff_facts.get('probe'), dict) else {}
            sm.check('config: twilio efectiva con OTP por Twilio, alias coherentes y la sonda de la fase',
                     eff_facts.get('otpDriver') == 'twilio' and eff_facts.get('aliasesConsistent') == {'sid': True, 'token': True}
                     and pr.get('enabled') is sm.probe_enabled and pr.get('credential') in ('otp', 'restricted_key'),
                     short({k: eff_facts.get(k) for k in ('otpDriver', 'aliasesConsistent', 'probe')}))
        elif name == 'meta_ads':
            tkn = eff_facts.get('token') if isinstance(eff_facts.get('token'), dict) else {}
            accs = eff_facts.get('accounts') if isinstance(eff_facts.get('accounts'), list) else []
            sm.check(f'config: meta_ads efectiva con token por huella, {sm.meta_accounts} cuentas por referencia y sincronización encendida',
                     tkn.get('present') is True and HEX8.match(str(tkn.get('fp', ''))) is not None and len(accs) == sm.meta_accounts
                     and all(isinstance(a, dict) and set(a) == {'ref'} and MASKED_REF.match(str(a.get('ref'))) for a in accs)
                     and eff_facts.get('syncEnabled') is True, f'cuentas={len(accs)} sync={eff_facts.get("syncEnabled")}')
        elif name == 'voice':
            e11 = eff_facts.get('elevenlabs') if isinstance(eff_facts.get('elevenlabs'), dict) else {}
            tv = eff_facts.get('twilioVoice') if isinstance(eff_facts.get('twilioVoice'), dict) else {}
            sm.check('config: voice efectiva con ElevenLabs apagado y sin clave, y Twilio Voice apagada con cupo 0',
                     e11.get('enabled') is False and (e11.get('apiKey') or {}).get('present') is False
                     and tv.get('enabled') is False and tv.get('dailyCallCap') == 0, short({'enabled': e11.get('enabled'), 'twilioVoice': tv}))
    sm.info('config: estados', short(states))


def check_audit_and_h5(sm, token):
    for subject in ('campaign_link', 'canary_number', 'integration'):
        st, b = sm.call('GET', f'{S}/audit?subjectType={subject}&page=1&per_page=5', token=token)
        p = data_of(b)
        total = p.get('total') if isinstance(p, dict) else None
        if sm.phase == '1':
            sm.check(f'audit?subjectType={subject} → 200 y sin eventos (fase 1)', st == 200 and total == 0, f'HTTP {st} total={total}')
        else:
            sm.check(f'audit?subjectType={subject} → 200 (el sujeto nuevo existe en el libro)', st == 200 and isinstance(total, int),
                     f'HTTP {st} total={total}')
    st, b = sm.call('GET', S + '/status', token=token)
    d = data_of(b) or {}
    cat = (d.get('catalog') or {}).get('version') if isinstance(d.get('catalog'), dict) else None
    eng = d.get('engine') if isinstance(d.get('engine'), dict) else {}
    sm.check(f'GET status → 200 con el catálogo {EXPECT_CATALOG} congelado y el H5 desplegado (engine.readsVersions)',
             st == 200 and cat == EXPECT_CATALOG and eng.get('readsVersions') is True, f'HTTP {st} catálogo={cat}')
    pub = eng.get('publication') if isinstance(eng.get('publication'), dict) else {}
    sm.info('H5: alcance del motor y estado de la cadena', f"envScope={pub.get('envScope')} state={pub.get('state')}")
    st, b = sm.call('GET', S + '/publications?page=1&per_page=1', token=token)
    sm.check('GET publications → 200 (Hito 5 intacto)', st == 200 and is_page(data_of(b)), f'HTTP {st}')
    st, b = sm.call('GET', S + '/lab/status', token=token)
    lab = data_of(b) or {}
    sm.check('GET lab/status → 200 (Hito 4 intacto)', st == 200 and isinstance(lab.get('enabled'), bool), f"HTTP {st} mode={lab.get('mode')}")


# ── escrituras (47–56), siempre sin efecto ─────────────────────────────────────────────────────────────────────

def forbidden(perm):
    return lambda r: isinstance(r, dict) and r.get('required_permission') == perm


def check_writes(sm, token, perms, who):
    new_cr = lambda: str(uuid.uuid4())
    p1 = sm.phase == '1'
    flag = (409, 'integrations_writes_disabled')
    cases = [
        ('47 POST integrations/meta/links (campaña inventada)', f'{I}/meta/links',
         {'clientRequestId': new_cr(), 'campaign': FAKE_CAMPAIGN, 'target': {'kind': 'baseline'}},
         'ultron.campaigns_manage', flag if p1 else (404, 'campaign_not_found')),
        ('48 POST integrations/meta/links/<inventado>/deactivate', f'{I}/meta/links/{FAKE}/deactivate', {'reason': REASON},
         'ultron.campaigns_manage', flag if p1 else (404, 'link_not_found')),
        ('50 POST integrations/canaries/<inventado>/authorize', f'{I}/canaries/{FAKE}/authorize', {},
         'ultron.canary_authorize', flag if p1 else (404, 'canary_not_found')),
        ('51 POST integrations/canaries/<inventado>/activate', f'{I}/canaries/{FAKE}/activate', {},
         'ultron.canary_manage', flag if p1 else (404, 'canary_not_found')),
        ('52 POST integrations/canaries/<inventado>/deactivate', f'{I}/canaries/{FAKE}/deactivate', {'reason': REASON},
         'ultron.canary_manage', flag if p1 else (404, 'canary_not_found')),
        ('53 POST integrations/canaries/<inventado>/revoke', f'{I}/canaries/{FAKE}/revoke', {'reason': REASON},
         'ultron.canary_manage', flag if p1 else (404, 'canary_not_found')),
        ('54 POST integrations/canaries/<inventado>/expectation', f'{I}/canaries/{FAKE}/expectation', {'versionUuid': None},
         'ultron.canary_manage', flag if p1 else (404, 'canary_not_found')),
    ]
    for name, path, body, perm, (status, code) in cases:
        if perm in perms:
            sm.expect(f'{who}{name} → {status} {code}', 'POST', path, body, token, status, code)
        elif who:
            sm.expect(f'{who}{name} → 403 forbidden ({perm})', 'POST', path, body, token, 403, 'forbidden', forbidden(perm))
        else:
            sm.check(f'{who}{name}: la sesión trae {perm}', False, 'el Super Admin debería tenerla')
    # 49 · registrar: la única creación con un dato personal. En la fase 2 NUNCA se envía un registro válido.
    name49 = '49 POST integrations/canaries'
    if 'ultron.canary_manage' not in perms:
        sm.expect(f'{who}{name49} → 403 forbidden (ultron.canary_manage)', 'POST', f'{I}/canaries',
                  {'clientRequestId': 'no-es-un-uuid'}, token, 403, 'forbidden', forbidden('ultron.canary_manage'))
    elif p1 and sm.writes_seen is False:
        sm.expect(f'{who}{name49} (bandera apagada vista en esta ejecución) → 409 integrations_writes_disabled', 'POST', f'{I}/canaries',
                  {'clientRequestId': new_cr(), 'channel': 'whatsapp', 'phone': FAKE_PHONE, 'alias': ALIAS,
                   'consentAttested': True, 'expectedVersionUuid': FAKE}, token, 409, 'integrations_writes_disabled')
    elif p1:
        sm.skip(f'{who}{name49} → 409 integrations_writes_disabled',
                'status no dijo writesEnabled = false en esta ejecución: un registro válido podría escribir')
    else:
        sm.expect_422(f'{who}{name49} con teléfono y consentimiento inválidos → 422 (antes de la bandera; nunca escribe)', 'POST',
                      f'{I}/canaries', {'clientRequestId': new_cr(), 'channel': 'whatsapp', 'phone': 'no es un teléfono',
                                         'alias': ALIAS, 'consentAttested': False}, token, 'phone')
    # 56 · la sonda (GET a Twilio con coste cero, pero no en un smoke): válida solo con la bandera apagada vista aquí.
    name56 = '56 POST integrations/twilio/probe'
    if 'ultron.integrations_manage' not in perms:
        if who:
            sm.expect(f'{who}{name56} → 403 forbidden (ultron.integrations_manage)', 'POST', f'{I}/twilio/probe',
                      {'clientRequestId': 'no-es-un-uuid'}, token, 403, 'forbidden', forbidden('ultron.integrations_manage'))
        else:
            sm.check(f'{name56}: la sesión trae ultron.integrations_manage', False, 'el Super Admin debería tenerla')
    elif who:
        sm.skip(f'{who}{name56} → 403', 'la sesión trae ultron.integrations_manage (concedida en Roles): no se envía nada que pudiera aceptarse')
    elif sm.probe_enabled is False and sm.probe_seen is False:
        sm.expect(f'{name56} (bandera apagada vista en esta ejecución) → 409 probe_disabled', 'POST', f'{I}/twilio/probe',
                  {'clientRequestId': new_cr()}, token, 409, 'probe_disabled')
    else:
        sm.expect_422(f'{name56} con clientRequestId inválido → 422 (la sonda encendida no se pulsa desde el smoke)', 'POST',
                      f'{I}/twilio/probe', {'clientRequestId': 'no-es-un-uuid'}, token, 'clientRequestId')
    # 55 · reconocer la configuración: escribe. Solo el Administrador sin la llave, con un cuerpo inválido, por el 403.
    name55 = '55 POST integrations/config/acknowledge'
    if not who:
        sm.info(f'{name55}', 'no se llama con el Super Admin: con un cuerpo válido escribiría una instantánea')
    elif 'ultron.integrations_manage' in perms:
        sm.skip(f'{who}{name55} → 403', 'la sesión trae ultron.integrations_manage (concedida en Roles): no se envía')
    else:
        sm.expect(f'{who}{name55} → 403 forbidden (ultron.integrations_manage)', 'POST', f'{I}/config/acknowledge',
                  {'clientRequestId': 'no-es-un-uuid', 'provider': 'twilio', 'fingerprint': 'x'}, token, 403, 'forbidden',
                  forbidden('ultron.integrations_manage'))


def check_no_token(sm):
    """Las rutas nuevas existen en la caché de rutas y piden sesión (una ruta inexistente daría 404). La sonda (56) no:
    su cupo (6/h por persona, 20/h por red) se reserva."""
    gets = ['status', 'meta/campaigns', 'meta/links', 'canaries', 'twilio', 'twilio/calls', 'voice', 'config']
    posts = ['meta/links', f'meta/links/{FAKE}/deactivate', 'canaries', f'canaries/{FAKE}/authorize', f'canaries/{FAKE}/activate',
             f'canaries/{FAKE}/deactivate', f'canaries/{FAKE}/revoke', f'canaries/{FAKE}/expectation', 'config/acknowledge']
    bad = []
    for method, rel in [('GET', g) for g in gets] + [('POST', p) for p in posts]:
        st, r = sm.call(method, f'{I}/{rel}', {} if method == 'POST' else None, None)
        if st != 401 or code_of(r) != 'admin_token_required':
            bad.append(f'{sm.label(method, I + "/" + rel)}={st}')
    sm.check(f'sin token: las {len(gets) + len(posts)} rutas nuevas (salvo la sonda) → 401 admin_token_required', not bad, ', '.join(bad)[:240])


# ── partes ─────────────────────────────────────────────────────────────────────────────────────────────────────

def run_superadmin(sm, email, password):
    token, perms = sm.login(email, password)
    if not token:
        return
    want = ('ultron.view', 'ultron.campaigns_manage', 'ultron.canary_manage', 'ultron.canary_authorize', 'ultron.integrations_manage')
    sm.check('la sesión trae ultron.view y las 4 llaves del Hito 6', all(p in perms for p in want),
             str(sorted(p for p in perms if str(p).startswith('ultron.'))))
    check_status(sm, token, perms, '')
    check_meta(sm, token)
    check_canaries(sm, token)
    check_twilio(sm, token)
    check_voice(sm, token)
    check_config(sm, token)
    check_audit_and_h5(sm, token)
    check_writes(sm, token, perms, '')
    check_no_token(sm)


def run_administrador(sm, email, password):
    token, perms = sm.login(email, password)
    if not token:
        return
    has = lambda p: p in perms
    sm.check('Administrador: la sesión trae ultron.view, campaigns_manage y canary_manage, y NO canary_authorize ni integrations_manage',
             has('ultron.view') and has('ultron.campaigns_manage') and has('ultron.canary_manage')
             and not has('ultron.canary_authorize') and not has('ultron.integrations_manage'),
             str(sorted(p for p in perms if str(p).startswith('ultron.'))))
    check_status(sm, token, perms, 'Administrador: ')
    reads = ['meta/campaigns?page=1&per_page=5', 'meta/links?page=1&per_page=5', 'canaries?page=1&per_page=5', 'twilio',
             'twilio/calls?page=1&per_page=5', 'voice', 'config']
    bad = []
    for rel in reads:
        st, _ = sm.call('GET', f'{I}/{rel}', token=token)
        if st != 200:
            bad.append(f'{rel.split("?")[0]}={st}')
    sm.check(f'Administrador: las {len(reads) + 1} lecturas de integraciones → 200', not bad, ', '.join(bad))
    check_writes(sm, token, perms, 'Administrador: ')


def main(argv):
    part = argv[1] if len(argv) > 1 else ''
    phase = os.environ.get('SMOKE_PHASE', '1')
    email = os.environ.get('SMOKE_EMAIL', '')
    password = os.environ.get('SMOKE_PW', '')
    canary = os.environ.get('SMOKE_CANARY_ID', '').strip()
    probe = os.environ.get('SMOKE_PROBE_ENABLED', 'false').strip()
    accounts = os.environ.get('SMOKE_META_ACCOUNTS', '3').strip()
    salt_hex = os.environ.get('SMOKE_SECRET_SALT', '').strip()
    digests_raw = os.environ.get('SMOKE_SECRET_DIGESTS', '').strip()
    if part not in ('superadmin', 'administrador') or phase not in ('1', '2') or probe not in ('true', 'false'):
        print('uso: SMOKE_PHASE=1|2 SMOKE_PROBE_ENABLED=true|false SMOKE_EMAIL=… SMOKE_PW=… smoke-h6.py superadmin|administrador')
        return 2
    if phase == '1' and probe == 'true':
        print('la fase 1 exige la sonda apagada (SMOKE_PROBE_ENABLED=false)')
        return 2
    if not email or not password:
        print('faltan SMOKE_EMAIL o SMOKE_PW (la contraseña solo por entorno)')
        return 2
    if canary and canary != 'none' and not canary.isdigit():
        print('SMOKE_CANARY_ID tiene que ser un número o «none»')
        return 2
    if not accounts.isdigit():
        print('SMOKE_META_ACCOUNTS tiene que ser un número')
        return 2
    if digests_raw and digests_raw != 'none' and not re.fullmatch(r'[0-9a-f]{32,128}', salt_hex):
        print('SMOKE_SECRET_DIGESTS sin SMOKE_SECRET_SALT válida (hex)')
        return 2
    digests = parse_digests(digests_raw)
    sm = Smoke(phase, part, canary, probe == 'true', int(accounts), bytes.fromhex(salt_hex) if salt_hex else b'', digests)
    print(f"== Smoke del Hito 6 · fase {phase} (escrituras {'encendidas' if phase == '2' else 'apagadas'}, sonda "
          f"{'encendida' if probe == 'true' else 'apagada'}) · {part} · {API}", flush=True)
    (run_superadmin if part == 'superadmin' else run_administrador)(sm, email, password)
    sm.scan()
    c = sm.counts
    print(f"RESUMEN ({part}, fase {phase}): {c['OK']} OK · {c['FALLO']} FALLO · {c['OMITIDO']} OMITIDO", flush=True)
    return sm.exit_code()


if __name__ == '__main__':
    sys.exit(main(sys.argv))

#!/usr/bin/env python3
"""Prueba aislada (sin red ni API) de la lógica de OPS8-e en smoke-h8.py: B-T7 (perfiles de voz según la base de antes)
y B-T4 (la bandera `contacts` y el 409 voice_contacts_disabled). Carga el smoke como módulo con SMOKE_PHASE y
SMOKE_VOICE_PROFILES_BEFORE en el entorno, y le pasa un `sm` falso con respuestas enlatadas."""
import importlib.util
import os
import sys

SMOKE = sys.argv[1]
FALLOS = 0


def carga(fase, perfiles_antes):
    os.environ['SMOKE_PHASE'] = fase
    if perfiles_antes is None:
        os.environ.pop('SMOKE_VOICE_PROFILES_BEFORE', None)
    else:
        os.environ['SMOKE_VOICE_PROFILES_BEFORE'] = perfiles_antes
    spec = importlib.util.spec_from_file_location('smoke_h8_prueba', SMOKE)
    m = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(m)
    return m


class SM:
    def __init__(self, respuestas):
        self.r = respuestas
        self.lineas = []

    def call(self, method, path, body=None, token=None, raw=None, label=None, keep=True, content_type='application/json', buckets=None):
        for (mt, pref), resp in self.r:
            if mt == method and (path == pref or (pref.endswith('*') and path.startswith(pref[:-1]))):
                return resp[0], resp[1], {}
        return 599, None, {}

    def check(self, name, ok, detail=''):
        self.lineas.append(('OK' if ok else 'FALLO', name, detail))
        return ok

    def info(self, name, detail=''):
        self.lineas.append(('INFO', name, detail))

    def skip(self, name, why):
        self.lineas.append(('OMITIDO', name, why))


def caso(nombre, cond):
    global FALLOS
    print(('OK    ' if cond else 'FALLO ') + nombre)
    FALLOS += 0 if cond else 1


def telefonia(m, flags, contactos_409):
    V = m.V
    FAKE = m.FAKE
    status = {'flags': flags, 'callsEnabledInCode': False, 'publication': {'canary': {'status': 'none'}, 'rest': {'status': 'none'}},
              'brakeOpen': False, 'limits': {'outboundDailyCap': 0, 'inboundDailyCap': 0, 'dailyBudgetMicros': 0},
              'number': {'configured': False, 'importedNumberIdConfigured': False}, 'carrierConfigured': False, 'provider': 'ELEVENLABS_UNVERIFIED'}
    vacia = (200, {'data': [], 'meta': {'total': 0}})
    v422 = (422, {'code': 'validation_failed'})
    v409 = (409, {'code': 'voice_contacts_disabled'})
    r = [(('GET', V + '/status'), (200, status)), (('GET', V + '/calls'), vacia), (('GET', V + '/contacts'), vacia),
         (('GET', V + f'/calls/{FAKE}'), (404, {'code': 'call_not_found'})),
         (('GET', V + '/metrics'), (200, {'live': 0, 'ambiguous': 0, 'brakeOpen': False})),
         (('POST', V + f'/calls/{FAKE}/hangup'), (404, {'code': 'call_not_found'}))]
    for p in [V + '/contacts', V + f'/contacts/{FAKE}/consents', V + f'/contacts/{FAKE}/consents/confirm']:
        r.append((('POST', p), v409 if contactos_409 else v422))
    for p in [V + '/calls', V + f'/contacts/{FAKE}/revoke', V + '/exclusions', V + '/rne-checks']:
        r.append((('POST', p), v422))
    sm = SM(r)
    m.check_telephony_with_session(sm, 'tok')
    return sm


def resultado(sm, trozo):
    return [x[0] for x in sm.lineas if trozo in x[1]]


m = carga('1', '0')
cinco = {'inbound': False, 'outbound': False, 'twilioVoice': False, 'lab': False, 'monitor': False}
sm = telefonia(m, dict(cinco), False)
caso('código ANTERIOR (5 banderas, 422 en las 7 POST): banderas OK', resultado(sm, 'banderas') == ['OK'])
caso('código ANTERIOR: las 7 POST OK', resultado(sm, 'POST de la telefonía') == ['OK'])
m = carga('1', '0')
sm = telefonia(m, dict(cinco, contacts=False), True)
caso('código NUEVO, contactos apagados: banderas OK (6 claves, contacts=false)', resultado(sm, 'banderas') == ['OK'])
caso('código NUEVO, contactos apagados: alta/carga/confirmación 409 voice_contacts_disabled → OK', resultado(sm, 'POST de la telefonía') == ['OK'])
caso('código NUEVO, contactos apagados: el texto lo dice', any('voice_contacts_disabled' in x[1] for x in sm.lineas))
m = carga('1', '0')
sm = telefonia(m, dict(cinco, contacts=False), False)
caso('código NUEVO, contactos apagados pero 422 (incoherente) → FALLO', resultado(sm, 'POST de la telefonía') == ['FALLO'])
m = carga('1', '0')
sm = telefonia(m, dict(cinco, contacts=True), False)
caso('contactos ENCENDIDOS en la fase 1 → FALLO de banderas', resultado(sm, 'banderas') == ['FALLO'])
m = carga('1', '0')
sm = telefonia(m, dict(cinco, otra=False), False)
caso('una bandera desconocida → FALLO de banderas', resultado(sm, 'banderas') == ['FALLO'])


def perfiles(m, total):
    S = m.S
    FAKE = m.FAKE
    r = []
    for method, rel, body, want, code in m.STUDIO_ROUTES:
        b = {'code': code} if code else {}
        if (method, rel) == ('GET', 'voice/profiles'):
            b = {'data': {'data': [{'uuid': 'x'}] * total, 'total': total}}
        elif (method, rel) == ('GET', 'voice/publications'):
            b = {'data': {'publishing': {'enabled': False, 'code': 'voice_publishing_disabled'}, 'history': {'total': 0}}}
        r.append(((method, f'{S}/{rel}'), (want, b)))
    r.append((('GET', f'{S}/integrations/voice'), (200, {'data': {'elevenlabs': {'state': 'ELEVENLABS_VERIFIED'}, 'publishing': {'enabled': False}, 'can': {f'k{i}': True for i in range(7)}}})))
    sm = SM(r)
    m.check_studio_with_session(sm, 'tok')
    return [x for x in sm.lineas if 'voice/profiles' in x[1]]


for antes, total, esperado, nombre in [('0', 0, 'OK', 'base sin perfiles y la API sin perfiles → estricto, OK'),
                                       ('0', 1, 'FALLO', 'base sin perfiles y la API con uno → estricto, FALLO'),
                                       ('1', 1, 'INFO', 'base con 1 perfil (E16-30) → informativo, sin FALLO'),
                                       (None, 1, 'INFO', 'sin el recuento del lanzador → informativo')]:
    m = carga('1', antes)
    lineas = perfiles(m, total)
    caso(f'fase 1 · {nombre}', [x[0] for x in lineas] == [esperado])
m = carga('2a', '0')
caso('fase 2a · perfiles siempre informativos', [x[0] for x in perfiles(m, 3)] == ['INFO'])
m = carga('1', '1; DROP')
caso('SMOKE_VOICE_PROFILES_BEFORE mal formado → se ignora (informativo)', m.PERFILES_ANTES == '' and [x[0] for x in perfiles(m, 0)] == ['INFO'])
print(f'== {FALLOS} FALLO')
sys.exit(1 if FALLOS else 0)

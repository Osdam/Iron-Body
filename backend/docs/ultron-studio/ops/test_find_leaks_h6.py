#!/usr/bin/env python3
"""Prueba sin red de find_leaks() de smoke-h6.py (se carga por ruta; no ejecuta main).

Uso (desde backend/): python3 -B docs/ultron-studio/ops/test_find_leaks_h6.py docs/ultron-studio/ops/smoke-h6.py
Los cuerpos «limpios» son los ejemplos JSON del contrato (ULTRON_STUDIO_H6_CONTRACT.md §I3.4, §I4.9, §I5.2–§I5.4,
§I6.6 e §I7.2): si el detector los marca, es un falso positivo del detector, no una fuga.
"""
import hashlib
import importlib.util
import json
import sys

spec = importlib.util.spec_from_file_location('smoke_h6', sys.argv[1])
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

SALT = bytes.fromhex('a1' * 32)
SECRET_TOKEN = 'f3c0e1a2b4d6978812345abcdeffedcb'          # «token de Twilio» de la prueba (32 hex)
SECRET_KEY = 'xK9pQ2vL7mN4rT8wZ1yB5cD3fG6hJ0kS'           # «secreto de API key» de la prueba (32 alfanuméricos: sin formato reconocible)
DIGESTS = {len(x): {hashlib.sha256(SALT + x.encode()).hexdigest()} for x in (SECRET_TOKEN,)}
DIGESTS.setdefault(len(SECRET_KEY), set()).add(hashlib.sha256(SALT + SECRET_KEY.encode()).hexdigest())


def leaks(body, canary='', digests=None):
    raw = body if isinstance(body, str) else json.dumps(body, ensure_ascii=False)
    try:
        parsed = json.loads(raw)
    except ValueError:
        parsed = None
    return {k for k, v in m.find_leaks([('x', raw, parsed)], canary, SALT, digests).items() if v}


STATUS = {'ok': True, 'data': {
    'can': {'campaignsManage': True, 'canaryManage': True, 'canaryAuthorize': False, 'integrationsManage': False},
    'flags': {'writesEnabled': False, 'twilioProbeEnabled': False},
    'providers': [
        {'id': 'meta_ads', 'available': True, 'classification': 'connected_read', 'state': 'ok', 'reason': None,
         'evidence': {'accounts': 3, 'lastSuccessAt': '2026-10-31T21:15:40+00:00', 'minutesSinceSuccess': 12,
                      'staleAfterMinutes': 180, 'coveredTo': '2026-10-31', 'syncEnabled': True, 'graphVersion': 'v26.0'}},
        {'id': 'twilio', 'available': True, 'classification': 'connected_otp_only', 'state': 'ok', 'reason': None,
         'evidence': {'otpDriver': 'twilio', 'verifyActive': True, 'sendEnabled': True,
                      'otpVerified': {'last24h': 14, 'last7d': 96, 'lastAt': '2026-10-31T20:01:02+00:00'}, 'estimatedSpentTodayUsd': 0.41}},
        {'id': 'twilio_voice', 'available': True, 'classification': 'not_configured', 'evidence': {'enabled': False, 'fromConfigured': False}},
        {'id': 'canaries', 'available': True, 'classification': 'registry',
         'evidence': {'active': {'whatsapp': 0, 'voice': 0}, 'pending': 0, 'authorized': 0, 'verdict': 'engine_canary_not_registered'}},
        {'id': 'elevenlabs', 'available': True, 'classification': 'not_configured', 'code': 'ELEVENLABS_NOT_CONFIGURED',
         'evidence': {'enabled': False, 'missing': ['api_key', 'agent_id', 'webhook_secret']}}],
    'note': 'Las banderas son las del proceso web, con su caché de configuración.'}}
TWILIO = {'ok': True, 'data': {
    'classification': 'connected_otp_only', 'state': 'ok', 'reason': None,
    'otp': {'driver': 'twilio', 'verifyActive': True, 'sendEnabled': True, 'rateLimitConfigured': True, 'legacySenderEnabled': False,
            'memberLinkSenderConfigured': True, 'verified': {'last24h': 14, 'last7d': 96, 'lastAt': None}},
    'cost': {'date': '2026-10-31', 'estimatedSpentUsd': 0.41, 'dailySoftLimitUsd': 6.0, 'dailyHardLimitUsd': 12.0},
    'credentials': {'accountSid': {'present': True, 'source': 'TWILIO_SID', 'ref': 'AC…1234'},
                    'authToken': {'present': True, 'source': 'TWILIO_TOKEN', 'fp': 'a1b2c3d4'},
                    'aliasesConsistent': {'sid': True, 'token': True},
                    'verifyServiceSid': {'present': True, 'ref': 'VA…9f0e'},
                    'apiBase': {'official': True}, 'verifyBase': {'official': True}},
    'webhooks': [{'id': 'usage_trigger', 'path': '/api/webhooks/twilio/usage-trigger', 'signature': 'HMAC-SHA1 (X-Twilio-Signature)',
                  'idempotency': 'IdempotencyToken, caché 24 h', 'throttle': '60/min', 'persistence': 'solo log'}],
    'voice': {'enabled': False, 'fromConfigured': False, 'state': 'not_configured'},
    'calls': {'total': 0, 'withProviderSid': 0, 'byStatus': [], 'byDirection': [], 'lastCreatedAt': None},
    'probe': {'enabled': False, 'credential': 'otp', 'cooldownSeconds': 300, 'last': None}}}
CONFIG = {'ok': True, 'data': {'providers': [
    {'provider': 'meta_ads', 'state': 'never_authorized', 'effectiveFingerprint': 'ab' * 32, 'authorized': None,
     'observed': {'uuid': 'f2ff5367-3342-43e9-bc55-450c8c2472fd', 'fingerprint': 'ab' * 32, 'at': '2026-11-01T01:16:00+00:00'}, 'drift': [],
     'effective': {'token': {'present': True, 'fp': '0a1b2c3d'}, 'accounts': [{'ref': '***4714'}, {'ref': '***9023'}, {'ref': '***5856'}],
                   'graphVersion': 'v26.0', 'syncEnabled': True, 'syncDays': 7, 'staleMinutes': 180, 'timezone': 'America/Bogota'}},
    {'provider': 'twilio', 'state': 'drift', 'effectiveFingerprint': 'cd' * 32,
     'authorized': {'uuid': '6c1e1c0e-2f71-4c1a-9a1e-6b1f0c2d3e4f', 'fingerprint': 'ef' * 32, 'at': '2026-11-01T02:00:00+00:00',
                    'by': {'name': 'Ana Gómez', 'role': 'Super Admin'}, 'note': 'Revisado con el propietario'},
     'observed': None,
     'drift': [{'key': 'verifyServiceSid', 'authorized': {'present': True, 'ref': 'VA…9f0e'}, 'effective': {'present': True, 'ref': 'VA…1a2b'}}],
     'effective': {'otpDriver': 'twilio', 'accountSid': {'present': True, 'source': 'TWILIO_SID', 'ref': 'AC…1234'},
                   'authToken': {'present': True, 'source': 'TWILIO_TOKEN', 'fp': 'a1b2c3d4'}, 'aliasesConsistent': {'sid': True, 'token': True},
                   'verifyServiceSid': {'present': True, 'ref': 'VA…1a2b'}, 'from': {'present': True, 'last4': '5483'},
                   'apiBase': {'official': True}, 'verifyBase': {'official': True}, 'rateLimitName': {'present': True},
                   'sendEnabled': True, 'legacySenderEnabled': False, 'costSoftUsd': '6.00', 'costHardUsd': '12.00',
                   'probe': {'enabled': False, 'credential': 'otp'}}},
    {'provider': 'voice', 'state': 'never_authorized', 'effectiveFingerprint': '12' * 32, 'authorized': None, 'observed': None, 'drift': [],
     'effective': {'elevenlabs': {'enabled': False, 'apiKey': {'present': False}, 'agentId': {'present': False},
                                  'webhookSecret': {'present': False}, 'region': 'default', 'toolSecret': {'present': False},
                                  'phoneNumberId': {'present': False}},
                   'twilioVoice': {'enabled': False, 'from': {'present': False}, 'dailyCallCap': 0}}}]}}
CANARY = {'ok': True, 'data': {
    'coherence': {'engineCanaryConfigured': True, 'verdict': 'in_sync', 'activeWhatsapp': 1},
    'limits': {'maxActivePerChannel': 3, 'maxLivePerChannel': 10, 'countryCodes': ['57'], 'writesEnabled': True},
    'numbers': {'data': [{'uuid': '0b6f1d9c-58a4-4f0e-9a41-3c2d1e0f9a8b', 'channel': 'whatsapp', 'alias': 'Móvil de pruebas 1',
                          'masked': '+57 ••• ••• 4821', 'status': 'active', 'statusLabel': 'Activo (registro)',
                          'registeredBy': {'name': 'Laura', 'role': 'Administrador'}, 'registeredAt': '2026-11-02T14:00:00+00:00',
                          'authorizedBy': {'name': 'Ana', 'role': 'Super Admin'}, 'authorizedAt': '2026-11-02T14:05:00+00:00',
                          'activatedBy': {'name': 'Ana', 'role': 'Super Admin'}, 'activatedAt': '2026-11-02T14:06:00+00:00', 'revoked': None,
                          'expectedVersion': {'uuid': '2d9a4c4e-6c8b-4a3f-8a0e-0f1e2d3c4b5a', 'label': 'v3', 'status': 'CANARY',
                                              'statusLabel': 'Canario', 'stale': False},
                          'effectiveVersion': {'source': 'publication_chain', 'state': 'applied', 'part': 'canary',
                                               'versionUuid': '2d9a4c4e-6c8b-4a3f-8a0e-0f1e2d3c4b5a', 'label': 'v3'},
                          'expectation': 'match', 'isEngineCanary': True,
                          'actions': {a: {'allowed': a in ('deactivate', 'revoke', 'expectation'), 'reason': None}
                                      for a in ('authorize', 'activate', 'deactivate', 'revoke', 'expectation')},
                          'statusChangedAt': '2026-11-02T14:06:00+00:00'}],
                'current_page': 1, 'last_page': 1, 'per_page': 20, 'total': 1}}}
CAMPAIGNS = {'ok': True, 'data': {
    'connection': {'state': 'ok', 'reason': None, 'syncEnabled': True, 'staleAfterMinutes': 180, 'lastSuccessAt': '2026-10-31T21:15:40+00:00',
                   'minutesSinceSuccess': 12, 'accounts': [{'id': 'account:9c1d2e3f4a5b6c7d', 'ref': '***4714', 'name': 'IronBody2', 'state': 'ok',
                                                            'lastSuccessAt': '2026-10-31T21:15:40+00:00'}]},
    'campaigns': {'data': [{'id': 'campaign:1a2b3c4d5e6f7a8b', 'ref': '***0387', 'name': 'Pauta octubre ***4821',
                            'account': {'id': 'account:9c1d2e3f4a5b6c7d', 'ref': '***4714', 'name': 'IronBody2'},
                            'meta': {'effectiveStatus': 'ACTIVE', 'statusKnownAt': '2026-10-31T20:33:00+00:00', 'ageMinutes': 42,
                                     'stale': False, 'possiblyDeleted': False}, 'ultron': {'link': None}}],
                  'current_page': 1, 'last_page': 1, 'per_page': 20, 'total': 12}}}
VOICE = {'ok': True, 'data': {
    'elevenlabs': {'state': 'ELEVENLABS_NOT_CONFIGURED', 'enabled': False, 'missing': ['api_key', 'agent_id', 'webhook_secret'],
                   'region': 'default', 'lastVerification': None},
    'twilioVoice': {'enabled': False, 'fromConfigured': False, 'dailyCallCap': 0},
    'policy': {'allowed': False, 'reasons': ['calls_disabled_in_code', 'twilio_voice_disabled', 'no_voice_number',
                                             'elevenlabs_not_configured', 'not_an_active_voice_canary', 'daily_cap_zero']},
    'levels': [{'id': 'text_simulation', 'available': True, 'cost': 'none', 'where': 'laboratorio'},
               {'id': 'integration_test', 'available': False, 'reasons': ['calls_disabled_in_code']},
               {'id': 'real_call', 'available': False, 'reasons': ['calls_disabled_in_code']}]}}
PROBE = {'ok': True, 'data': {'replayed': False, 'reused': False, 'snapshot': {
    'uuid': '9e8d7c6b-5a49-4382-9170-6f5e4d3c2b1a', 'at': '2026-11-02T15:00:00+00:00', 'outcome': 'degraded',
    'facts': {'credential': 'otp', 'account': {'status': 'active', 'type': 'Full'},
              'numbers': {'count': 1, 'truncated': False, 'voiceCapable': 0, 'smsCapable': 1, 'items': [{'last4': '5483', 'voice': False, 'sms': True}]},
              'callerIds': {'count': 0, 'truncated': False}, 'calls7d': {'total': 0, 'truncated': False, 'byStatus': []},
              'alerts24h': {'total': 3, 'truncated': False, 'byCode': {'60200': 3}, 'byLevel': {'error': 1, 'warning': 2}},
              'errors': [{'step': 'alerts', 'outcome': 'auth_failed', 'httpStatus': 403}]},
    'by': {'name': 'Ana', 'role': 'Super Admin'}}}}

ACCOUNT_SID = 'AC' + '0123456789abcdef' * 2
casos = [
    # limpios: los ejemplos del contrato
    ('status (§I7.2)', STATUS, '', None, set()),
    ('twilio (§I5.2): firma descriptiva del webhook, source, ref y fp', TWILIO, '', None, set()),
    ('config (§I5.3) con facts, deriva y nota', CONFIG, '', None, set()),
    ('canario (§I4.9) enmascarado con bolas U+2022', CANARY, '', None, set()),
    ('campañas (§I3.4) con nombre enmascarado', CAMPAIGNS, '', None, set()),
    ('voz (§I6.6)', VOICE, '', None, set()),
    ('sonda (§I5.4): byCode, last4, httpStatus', PROBE, '', None, set()),
    ('limpio con el centinela activo', TWILIO, '', DIGESTS, set()),
    ('catálogo, ventana y fechas', {'data': {'catalog': {'version': '2026-10-19.1'}, 'window': '07:00-19:00', 'at': '2026-10-25T15:00:47.123456Z'}}, '', None, set()),
    ('motivo libre que nombra la APP_KEY', {'data': {'reason': 'Rotación de APP_KEY del 25-10'}}, '', None, set()),
    ('id corto del canario bajo seq: no', {'data': {'seq': 7, 'total': 7}}, '7', None, set()),
    # credenciales y secretos por formato
    ('SID de cuenta completo en un valor', {'data': {'x': ACCOUNT_SID}}, '', None, {'secret'}),
    ('SID completo como ref', {'data': {'accountSid': {'present': True, 'ref': ACCOUNT_SID}}}, '', None, {'secret', 'container'}),
    ('token de Twilio (32 hex) como fp', {'data': {'authToken': {'present': True, 'fp': SECRET_TOKEN}}}, '', None, {'secret', 'container'}),
    ('token como texto del contenedor', {'data': {'authToken': 'abc'}}, '', None, {'container'}),
    ('contenedor con una pieza de más', {'data': {'apiKey': {'present': True, 'value': 'x'}}}, '', None, {'container'}),
    ('last4 de cinco cifras', {'data': {'from': {'present': True, 'last4': '12345'}}, 'x': {'token': {'present': True, 'last4': '12345'}}}, '', None, {'container'}),
    ('alias de token booleano: no', {'data': {'aliasesConsistent': {'sid': True, 'token': None}}}, '', None, set()),
    ('token de Meta', {'data': {'x': 'EAAG' + 'a' * 40}}, '', None, {'secret'}),
    ('clave de ElevenLabs', {'data': {'x': 'sk_' + 'b' * 40}}, '', None, {'secret'}),
    ('secreto de webhook de ElevenLabs', {'data': {'x': 'wsec_' + 'c' * 30}}, '', None, {'secret'}),
    ('APP_KEY en base64', {'data': {'x': 'base64:' + 'Q' * 43 + '='}}, '', None, {'secret'}),
    ('cabecera Basic', {'data': {'message': 'Basic ' + 'dXNlcjpwYXNzd29yZA' * 2}}, '', None, {'secret'}),
    ('clave con nombre de variable secreta', {'data': {'META_ADS_ACCESS_TOKEN': '***'}}, '', None, {'secret'}),
    ('ruta del servidor en un mensaje', {'message': 'Error en /var/www/api/backend/app/Services/X.php'}, '', None, {'secret'}),
    ('HTML con traza', '<html><body>Error in /var/www/api/backend/app/Services/A.php</body></html>', '', None, {'secret'}),
    # teléfonos e ids de Meta
    ('teléfono con espacios', {'data': {'masked': '+57 300 123 4567'}}, '', None, {'phone_or_id'}),
    ('teléfono de 10 cifras en un nombre', {'data': {'name': 'Pauta 3001234567'}}, '', None, {'phone_or_id'}),
    ('id de campaña de Meta como texto', {'data': {'x': '120201234567890123'}}, '', None, {'phone_or_id'}),
    ('id de Meta como entero', {'data': {'x': 120201234567890123}}, '', None, {'phone_or_id'}),
    ('teléfono como clave de un recuento', {'data': {'byNumber': {'573001234567': 2}}}, '', None, {'phone_or_id'}),
    ('IP con puntos (se marca: dato personal)', {'data': {'x': '192.168.100.200'}}, '', None, {'phone_or_id'}),
    # claves prohibidas
    ('phone_hash', {'data': {'phone_hash': 'ab' * 32}}, '', None, {'forbidden'}),
    ('engineHash de la coherencia', {'data': {'coherence': {'engineHash': 'ab' * 32}}}, '', None, {'forbidden'}),
    ('phone', {'data': {'phone': '+573001234567'}}, '', None, {'forbidden', 'phone_or_id'}),
    ('friendly_name de Twilio', {'data': {'friendly_name': 'Gimnasio'}}, '', None, {'forbidden'}),
    ('signature fuera de webhooks', {'data': {'signature': 'HMAC'}}, '', None, {'forbidden'}),
    ('signature con hex dentro de webhooks', {'data': {'webhooks': [{'signature': 'ab' * 20}]}}, '', None, {'forbidden'}),
    ('effective_json', {'data': [{'effective_json': '{}'}]}, '', None, {'forbidden'}),
    ('422 estándar: «errors.phone» es el nombre del campo', {'message': 'El teléfono no tiene formato internacional (+57…).',
                                                          'errors': {'phone': ['El teléfono no tiene formato internacional (+57…).'],
                                                                     'consentAttested': ['Hay que aceptar.']}}, '', None, set()),
    ('422 que repite el teléfono en el mensaje', {'errors': {'phone': ['+57 300 123 4567 no vale']}}, '', None, {'phone_or_id'}),
    ('«phone» dentro de data.errors sí cuenta', {'data': {'errors': {'phone': 'x'}}}, '', None, {'forbidden'}),
    # conversación y canario (semántica del H5)
    ('clave con conversation', {'data': {'canaryConversationId': 4521}}, '4521', None, {'conversation', 'canary'}),
    ('id largo bajo cualquier clave', {'data': {'x': 4521}}, '4521', None, {'canary'}),
    ('id dentro de un texto', {'data': {'reason': 'conversación 4521 del canario'}}, '4521', None, {'canary'}),
    ('id pegado a otras cifras: no', {'data': {'message': 'lote 145210'}}, '4521', None, set()),
    ('trozo de uuid = id: no', {'data': {'uuid': 'f2ff5367-3342-43e9-bc55-450c8c2472fd'}}, '3342', None, set()),
    ('canario none: no busca', {'data': {'x': 4521}}, 'none', None, set()),
    # centinela exacto (huellas saladas)
    ('centinela: token de 32 hex embebido', {'data': {'note': 'x' + SECRET_TOKEN + 'y'}}, '', DIGESTS, {'sentinel', 'secret'}),
    ('centinela: secreto sin formato reconocible', {'data': {'message': 'clave ' + SECRET_KEY}}, '', DIGESTS, {'sentinel'}),
    ('centinela: secreto como clave JSON', {'data': {SECRET_KEY: 1}}, '', DIGESTS, {'sentinel'}),
    ('centinela: casi el secreto (una letra cambiada): no', {'data': {'message': SECRET_KEY[:-1] + 'Q'}}, '', DIGESTS, set()),
    ('centinela en HTML', '<p>' + SECRET_KEY + '</p>', '', DIGESTS, {'sentinel'}),
]
fallos = 0
for nombre, cuerpo, canario, digests, esperado in casos:
    obtenido = leaks(cuerpo, canario, digests)
    ok = obtenido == esperado
    fallos += not ok
    print(('OK   ' if ok else 'FALLO'), nombre, '' if ok else f'esperado {sorted(esperado)} obtenido {sorted(obtenido)}')
# parse_digests: lo que el lanzador le pasa
pd = m.parse_digests('none')
ok = pd == {} and m.parse_digests('34:' + 'a' * 64 + ' 7:' + 'b' * 64 + ' 34:' + 'c' * 64 + ' basura') == {34: {'a' * 64, 'c' * 64}}
fallos += not ok
print(('OK   ' if ok else 'FALLO'), 'parse_digests ignora «none», longitudes < 8 y basura')
print(f'{len(casos) + 1 - fallos}/{len(casos) + 1}')
sys.exit(1 if fallos else 0)

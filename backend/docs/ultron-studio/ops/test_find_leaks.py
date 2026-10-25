#!/usr/bin/env python3
"""Prueba sin red de find_leaks() de smoke-h5.py (se carga por ruta; no ejecuta main)."""
import importlib.util
import json
import sys

spec = importlib.util.spec_from_file_location('smoke_h5', sys.argv[1])
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)


def leaks(body, canary=''):
    raw = body if isinstance(body, str) else json.dumps(body, ensure_ascii=False)
    try:
        parsed = json.loads(raw)
    except ValueError:
        parsed = None
    return {k for k, v in m.find_leaks([('x', raw, parsed)], canary).items() if v}


casos = [
    ('limpio', {'ok': True, 'data': {'head': None, 'drift': []}}, '', set()),
    ('signature', {'data': {'signature': 'ab' * 32}}, '', {'forbidden'}),
    ('effectiveJson', {'data': {'effectiveJson': '{}'}}, '', {'forbidden'}),
    ('effective_json', {'data': [{'effective_json': '{}'}]}, '', {'forbidden'}),
    ('signatureOk permitido', {'data': {'head': {'signatureOk': True, 'effectiveHash': 'ab' * 32}}}, '', set()),
    ('clave con conversation', {'data': {'canaryConversationId': 4521}}, '4521', {'conversation', 'canary'}),
    ('id largo bajo cualquier clave', {'data': {'x': 4521}}, '4521', {'canary'}),
    ('id corto bajo seq: no', {'data': {'seq': 7, 'total': 7}}, '7', set()),
    ('id corto bajo clave de canario: sí', {'data': {'canary': 7}}, '7', {'canary'}),
    ('fecha con el año = id: no', {'data': {'at': '2026-10-25T15:00:47+00:00'}}, '2026', set()),
    ('versión de catálogo = id: no', {'data': {'catalog': {'version': '2026-10-19.1'}}}, '2026', set()),
    ('trozo de uuid = id: no', {'data': {'uuid': 'f2ff5367-3342-43e9-bc55-450c8c2472fd'}}, '3342', set()),
    ('huella con las cifras: no', {'data': {'hash': 'aeb9699c1b90a709831349355df35d13ad2a840982c5c2be9917b08f86d6c83e'}}, '9831', set()),
    ('ventana con id corto: no', {'data': {'window': '07:00–19:00'}}, '19', set()),
    ('id dentro de un texto: sí', {'data': {'reason': 'conversación 4521 del canario'}}, '4521', {'canary'}),
    ('id pegado a otras cifras: no', {'data': {'message': 'lote 145210'}}, '4521', set()),
    ('motivo que nombra APP_KEY: no', {'data': {'reason': 'Rotación de APP_KEY del 25-10'}}, '', set()),
    ('ruta del servidor en file: sí', {'message': 'x', 'file': '/var/www/api/backend/app/Services/X.php'}, '', {'secret'}),
    ('clave de entorno como clave: sí', {'data': {'OPENAI_API_KEY': '***'}}, '', {'secret'}),
    ('clave sk- en un valor: sí', {'data': {'k': 'sk-abcdefghijklmnop'}}, '', {'secret'}),
    ('HTML con traza: sí', '<html><body>Error in /var/www/api/backend/app/Services/A.php</body></html>', '', {'secret'}),
    ('canario none: no busca', {'data': {'x': 4521}}, 'none', set()),
]
fallos = 0
for nombre, cuerpo, canario, esperado in casos:
    obtenido = leaks(cuerpo, canario)
    ok = obtenido == esperado
    fallos += not ok
    print(('OK   ' if ok else 'FALLO'), nombre, '' if ok else f'esperado {sorted(esperado)} obtenido {sorted(obtenido)}')
print(f'{len(casos) - fallos}/{len(casos)}')
sys.exit(1 if fallos else 0)

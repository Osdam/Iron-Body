#!/usr/bin/env python3
"""PERF8 (Hito 8): genera presupuestos-h8.json a partir de lo MEDIDO (nada a mano).

  python3 -I presupuestos.py resultados/http-<UTC>.json [resultados/http-<UTC>.json …]   → presupuestos-h8.json

Lee resultados/*-consultas.json y *-tiempos.json (arnés en proceso) y uno o más http-*.json (banco de extremo a
extremo; si una ruta sale en varios, manda el último que la midió sin error). Escribe el fichero en el ESQUEMA que
valida smoke-h8.py (routes[{name, path, p50Ms, p95Ms, maxBytes}], samples, public401) y añade, como claves que el
smoke ignora, lo medido y los máximos de sentencias para CI (TEST8).

Reglas (las mismas del H7, escritas para que nadie las adivine):
  · techo inicial del smoke: p50Ms = ↑10(max(3 × p50 HTTP local, p50 + 100 ms)); p95Ms = ↑10(max(3 × p95 HTTP local,
    p95 + 150 ms)). Son techos INICIALES (≈ 3 veces lo local), no medidas de producción: la primera pasada en
    producción los recalibra con la regla de OPS8 (max(1,5 × medida, medida + 50 ms)), en un commit aparte.
  · maxBytes = ↑KB(max(1,5 × bytes medidos con la siembra a escala, bytes + 4 KB)): la siembra llena las páginas.
  · public401.maxMs = ↑10(max(3 × p95 HTTP del 401, p95 + 100 ms)).
  · maxQueries (CI) = sentencias medidas + 2 (CACHE_STORE=database; limitadores como en el arnés).
"""
import glob
import json
import math
import os
import sys

AQUI = os.path.dirname(os.path.abspath(__file__))
RES = os.path.join(AQUI, 'resultados')

# nombre del smoke → (ruta del smoke, ruta en el banco HTTP, (ruta, datos) en el arnés en proceso)
RUTAS = [
    ('GET admin/voice/status', '/admin/voice/status', 'GET admin/voice/status', ('GET admin/voice/status', '5 000 llamadas')),
    ('GET admin/voice/calls', '/admin/voice/calls', 'GET admin/voice/calls', ('GET admin/voice/calls', 'por defecto (25)')),
    ('GET admin/voice/metrics', '/admin/voice/metrics', 'GET admin/voice/metrics', ('GET admin/voice/metrics', 'hoy + 2 partes')),
    ('GET admin/voice/contacts', '/admin/voice/contacts', 'GET admin/voice/contacts', ('GET admin/voice/contacts', 'por defecto (25)')),
    ('GET voice/profiles', '/admin/ultron-studio/voice/profiles', 'GET voice/profiles', ('GET voice/profiles', '20')),
    ('GET voice/publications', '/admin/ultron-studio/voice/publications', 'GET voice/publications', ('GET voice/publications', '20 de 50')),
    ('GET voice/brakes', '/admin/ultron-studio/voice/brakes', 'GET voice/brakes', ('GET voice/brakes', '8 sueltos')),
    ('GET voice-lab/status', '/admin/ultron-studio/voice-lab/status', 'GET voice-lab/status', ('GET voice-lab/status', '300 sesiones')),
    ('GET voice-lab/sessions', '/admin/ultron-studio/voice-lab/sessions', 'GET voice-lab/sessions', ('GET voice-lab/sessions', '20 de 300')),
    ('GET integrations/voice', '/admin/ultron-studio/integrations/voice', 'GET integrations/voice', ('GET integrations/voice (ruta 45)', 'publicador')),
    ('GET voice/canary-evidence', '/admin/ultron-studio/voice/canary-evidence', 'GET voice/canary-evidence', ('GET voice/canary-evidence', 'canario vigente')),
]

PUBLICAS = [
    ('POST webhooks/voice/elevenlabs/initiation', 300, 'POST initiation (canario)', ('POST initiation · canario (todo abierto salvo la constante)', 'canario')),
    ('POST voice/tools/consultar_planes', 500, 'POST tools/consultar_planes', ('POST tools/consultar_planes', 'siguiente (cupo 2–11 de 20)')),
    ('POST voice/tools/solicitar_humano', 500, 'POST tools/solicitar_humano', ('POST tools/solicitar_humano', 'siguiente (cupo 2–11 de 20)')),
    ('POST webhooks/voice/elevenlabs/post-call (9 KB)', 200, 'POST post-call (9 KB)', ('POST post-call · transcripción', '9 KB · 40 turnos')),
    ('POST webhooks/voice/elevenlabs/post-call (~1 MB, peor caso)', 200, 'POST post-call (~1 MB)', ('POST post-call · transcripción', '~1 MB · 1000 turnos')),
    ('POST webhooks/voice/twilio/usage-trigger', None, 'POST twilio/usage-trigger (abre el freno)', ('POST twilio/usage-trigger', 'aviso nuevo (abre el freno)')),
]


def arriba(x, paso):
    return int(math.ceil(x / paso) * paso)


def cargar(patron):
    out = {}
    for f in sorted(glob.glob(os.path.join(RES, patron))):
        with open(f, encoding='utf-8') as fh:
            d = json.load(fh)
        for r in d['results']:
            out[(r['route'], r.get('size'))] = r
    return out


def main():
    http = {}
    for f in sys.argv[1:]:
        with open(f, encoding='utf-8') as fh:
            for r in json.load(fh)['results']:
                if 'error' not in r and r['timing']['n'] >= 20:
                    http[r['route']] = r
    cons = cargar('*-consultas.json')
    tiem = cargar('*-tiempos.json')

    rutas = []
    for nombre, ruta, clave_http, clave_proc in RUTAS:
        h = http.get(clave_http)
        if h is None:
            print(f'AVISO: {nombre} sin medida HTTP válida (n ≥ 20): fuera de routes', file=sys.stderr)
            continue
        t = h['timing']
        p = tiem.get(clave_proc, {}).get('timing', {}).get('all', {})
        c = cons.get(clave_proc, {})
        p50 = arriba(max(3 * t['p50'], t['p50'] + 100), 10)
        p95 = max(p50, arriba(max(3 * t['p95'], t['p95'] + 150), 10))
        rutas.append({
            'name': nombre, 'path': ruta, 'p50Ms': p50, 'p95Ms': p95,
            'maxBytes': arriba(max(1.5 * h['bytes'], h['bytes'] + 4096), 1024),
            'measured': {'httpLocal': {'n': t['n'], 'p50': t['p50'], 'p95': t['p95'], 'max': t['max'], 'bytes': h['bytes']},
                         'inProcess': {'n': p.get('n'), 'p50': p.get('p50'), 'p95': p.get('p95')}, 'queries': c.get('queries')},
        })

    publicas = []
    for nombre, objetivo, clave_http, clave_proc in PUBLICAS:
        h = http.get(clave_http)
        p = tiem.get(clave_proc, {}).get('timing', {}).get('all', {})
        c = cons.get(clave_proc, {})
        publicas.append({'name': nombre, 'contractP95Ms': objetivo,
                         'httpLocal': None if h is None else {'n': h['timing']['n'], 'p50': h['timing']['p50'], 'p95': h['timing']['p95'], 'max': h['timing']['max'], 'requestBytes': h['requestBytes']},
                         'inProcess': {'n': p.get('n'), 'p50': p.get('p50'), 'p95': p.get('p95')}, 'queries': c.get('queries'),
                         'meetsContract': None if objetivo is None or h is None else h['timing']['p95'] < objetivo})

    h401 = http.get('POST initiation (secreto malo, 401)')
    public401 = {'maxMs': arriba(max(3 * h401['timing']['p95'], h401['timing']['p95'] + 100), 10),
                 'note': f"401 sin credencial de las 4 públicas; local HTTP p95 {h401['timing']['p95']} ms (iniciación, n={h401['timing']['n']})"} if h401 else {'maxMs': 300}

    maxq = {}
    for (ruta, datos), r in sorted(cons.items()):
        if isinstance(r.get('queries'), int):
            maxq[f'{ruta} · {datos}'] = r['queries'] + 2

    doc = {
        'version': 'perf8-2026-11-08',
        'source': 'PERF8 (plans/informes-h8/informe-performance.md): techos INICIALES derivados de lo medido en local '
                  '(banco HTTP php -S + arnés en proceso, siembra a escala); se recalibran con la primera pasada en producción.',
        'rules': __doc__.split('Reglas', 1)[1].strip(),
        'samples': {'warmup': 1, 'n': 5, 'minIntervalSeconds': 0.6},
        'public401': public401,
        'routes': rutas,
        'publicRoutes': publicas,
        'maxQueries': maxq,
    }
    salida = os.path.join(AQUI, 'presupuestos-h8.json')
    with open(salida, 'w', encoding='utf-8') as fh:
        json.dump(doc, fh, ensure_ascii=False, indent=2)
        fh.write('\n')
    print('escrito', salida, '·', len(rutas), 'rutas del smoke ·', len(maxq), 'máximos de sentencias')


if __name__ == '__main__':
    main()

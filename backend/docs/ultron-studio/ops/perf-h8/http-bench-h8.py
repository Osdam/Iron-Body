#!/usr/bin/env python3
"""PERF8 (Hito 8): banco HTTP de EXTREMO A EXTREMO de la voz, contra el servidor de `serve.sh start`.

  PERF_BASE=http://127.0.0.1:8610/api python3 -I http-bench-h8.py <PERF_STATE_DIR/http> [filtro]

Por ruta: PERF_HTTP_WARM peticiones de calentamiento (no cuentan) y PERF_HTTP_N cronometradas, EN SERIE, cada una
con conexión TCP nueva y el cuerpo leído entero (time.perf_counter() alrededor de conectar + enviar + leer). Es
tiempo de servicio a concurrencia 1, no capacidad. El ritmo queda por debajo de cada cupo (el CRM rota 8 sesiones;
la evidencia y el 401 van a ≥ 2,05 s porque sus cubos son de 10–30/min). Un estado inesperado para esa ruta.

Orden: lecturas del CRM y del Studio, iniciación, 401, herramientas, post-llamada y, AL FINAL, el aviso de Twilio
(abre el freno). Escribe resultados/http-<UTC>.json junto a este guion. Sin dependencias fuera de la biblioteca
estándar. Las credenciales del manifiesto son SINTÉTICAS (mundo de la medida), nunca de un proveedor real.
"""
import hashlib
import hmac
import http.client
import json
import math
import os
import secrets
import sys
import time
import urllib.parse


def percentiles(v):
    """Rango más cercano, ceil(q·n) − 1, igual que el arnés en proceso."""
    v = sorted(v)
    n = len(v)

    def rango(q):
        return round(v[max(0, math.ceil(q * n) - 1)], 3) if n else 0.0

    return {'n': n, 'p50': rango(0.50), 'p95': rango(0.95), 'max': round(v[-1], 3) if n else 0.0, 'min': round(v[0], 3) if n else 0.0}


def main():
    estado = sys.argv[1]
    filtro = sys.argv[2] if len(sys.argv) > 2 else ''
    base = urllib.parse.urlparse(os.environ.get('PERF_BASE', 'http://127.0.0.1:8610/api'))
    host, port, prefijo = base.hostname, base.port, base.path.rstrip('/')
    n = int(os.environ.get('PERF_HTTP_N', '30'))
    calentar = int(os.environ.get('PERF_HTTP_WARM', '3'))
    with open(os.path.join(estado, 'manifest.json'), encoding='utf-8') as f:
        m = json.load(f)
    sesiones = m['sessions']
    giro = {'i': 0}

    def sesion():
        giro['i'] += 1
        return sesiones[giro['i'] % len(sesiones)]

    def leer(nombre):
        with open(os.path.join(estado, nombre), 'rb') as f:
            return f.read()

    contadores = {}

    def siguiente(clave):
        contadores[clave] = contadores.get(clave, -1) + 1
        return contadores[clave]

    def get(ruta):
        return lambda: ('GET', ruta, None, {'Authorization': 'Bearer ' + sesion(), 'Accept': 'application/json'})

    def iniciacion(secreto):
        def f():
            k = siguiente('init')
            cuerpo = json.dumps({'caller_id': m['canaryPhone'], 'agent_id': m['sealedAgent'], 'called_number': m['voiceFrom'],
                                 'call_sid': 'CA' + hashlib.md5(f'perf-http-init-{k}-{secrets.token_hex(4)}'.encode()).hexdigest(),
                                 'conversation_id': f'conv_perf_http_init_{k}_{secrets.token_hex(3)}'}).encode()
            return ('POST', '/webhooks/voice/elevenlabs/initiation', cuerpo, {'X-Voice-Initiation-Secret': secreto, 'Content-Type': 'application/json', 'Accept': 'application/json'})
        return f

    def herramienta(nombre, desde):
        def f():
            k = siguiente('tool-' + nombre)
            c = m['toolCalls'][desde + k // 10]
            cuerpo = json.dumps({'conversation_id': c['conv'], 'call_sid': c['sid'], 'call_ref': c['ref']}).encode()
            return ('POST', '/voice/tools/' + nombre, cuerpo, {'Authorization': 'Bearer ' + m['toolBearer'], 'Content-Type': 'application/json', 'Accept': 'application/json'})
        return f

    def postllamada(tipo):
        def f():
            k = siguiente('pc-' + tipo)
            cuerpo = leer(f'postcall-{tipo}-{k}.json')
            t = str(int(time.time()))
            firma = hmac.new(m['postCallSigning'].encode(), t.encode() + b'.' + cuerpo, hashlib.sha256).hexdigest()
            return ('POST', '/webhooks/voice/elevenlabs/post-call', cuerpo, {'ElevenLabs-Signature': f't={t},v0={firma}', 'Content-Type': 'application/json', 'Accept': 'application/json'})
        return f

    def aviso():
        k = siguiente('twilio')
        d = json.loads(leer(f'twilio-{k}.json'))
        return ('POST', '/webhooks/voice/twilio/usage-trigger', d['body'].encode(), {'X-Twilio-Signature': d['signature'], 'Content-Type': 'application/x-www-form-urlencoded', 'Accept': 'application/json'})

    S = '/admin/ultron-studio'
    rutas = [
        ('GET admin/voice/status', get('/admin/voice/status'), 200, 0.15),
        ('GET admin/voice/calls', get('/admin/voice/calls'), 200, 0.15),
        ('GET admin/voice/metrics', get('/admin/voice/metrics'), 200, 0.15),
        ('GET admin/voice/contacts', get('/admin/voice/contacts'), 200, 0.15),
        ('GET integrations/voice', get(S + '/integrations/voice'), 200, 0.15),
        ('GET voice/publications', get(S + '/voice/publications'), 200, 0.15),
        ('GET voice/profiles', get(S + '/voice/profiles'), 200, 0.15),
        ('GET voice/brakes', get(S + '/voice/brakes'), 200, 0.15),
        ('GET voice-lab/status', get(S + '/voice-lab/status'), 200, 0.15),
        ('GET voice-lab/sessions', get(S + '/voice-lab/sessions'), 200, 0.15),
        ('GET voice/canary-evidence', get(S + '/voice/canary-evidence'), 200, 2.05),
        ('POST initiation (canario)', iniciacion(m['initiationHeaderValue']), 200, 0.15),
        ('POST initiation (secreto malo, 401)', iniciacion('secreto-que-no-vale-000000000000000000'), 401, 2.05),
        ('POST tools/consultar_planes', herramienta('consultar_planes', 0), 200, 0.15),
        ('POST tools/solicitar_humano', herramienta('solicitar_humano', 10), 200, 0.15),
        ('POST post-call (9 KB)', postllamada('9k'), 200, 0.15),
        ('POST post-call (~1 MB)', postllamada('1m'), 200, 0.15),
        ('POST twilio/usage-trigger (abre el freno)', aviso, 200, 0.15),
    ]

    resultados = []
    carga0 = os.getloadavg()
    for nombre, generar, esperado, ritmo in rutas:
        if filtro and filtro not in nombre:
            continue
        tiempos, bytes_resp, bytes_pet, estados, error = [], 0, [], {}, None
        for i in range(calentar + n):
            metodo, ruta, cuerpo, cabeceras = generar()
            t0 = time.perf_counter()
            c = http.client.HTTPConnection(host, port, timeout=60)
            c.request(metodo, prefijo + ruta, body=cuerpo, headers=cabeceras)
            r = c.getresponse()
            datos = r.read()
            ms = (time.perf_counter() - t0) * 1000
            c.close()
            estados[r.status] = estados.get(r.status, 0) + 1
            if r.status != esperado:
                error = f'HTTP {r.status} (esperado {esperado}): {datos[:200]!r}'
                break
            if i >= calentar:
                tiempos.append(ms)
                bytes_resp = len(datos)
                bytes_pet.append(len(cuerpo) if cuerpo else 0)
            time.sleep(ritmo)
        fila = {'route': nombre, 'expected': esperado, 'statuses': estados, 'bytes': bytes_resp,
                'requestBytes': round(sum(bytes_pet) / len(bytes_pet)) if bytes_pet else 0, 'timing': percentiles(tiempos),
                'spacingSeconds': ritmo, 'load1': os.getloadavg()[0]}
        if error:
            fila['error'] = error
        resultados.append(fila)
        t = fila['timing']
        print(f"{nombre}: n={t['n']} p50 {t['p50']} · p95 {t['p95']} · máx {t['max']} ms · {bytes_resp} B · {estados}" + (f' · ERROR {error}' if error else ''), flush=True)

    salida = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'resultados', 'http-' + time.strftime('%Y%m%dT%H%M%SZ', time.gmtime()) + '.json')
    with open(salida, 'w', encoding='utf-8') as f:
        json.dump({'env': {'at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), 'base': f'{host}:{port}{prefijo}', 'n': n, 'warm': calentar,
                           'load_start': carga0, 'load_end': os.getloadavg(), 'server': 'php artisan serve --no-reload, 4 workers, APP_ENV=production, config/route/event cache'},
                   'results': resultados}, f, ensure_ascii=False, indent=2)
    print('escrito', salida)


if __name__ == '__main__':
    main()

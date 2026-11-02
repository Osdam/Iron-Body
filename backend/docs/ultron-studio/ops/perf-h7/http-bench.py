#!/usr/bin/env python3
"""PERF7 (Hito 7): banco HTTP SECUENCIAL de las rutas del Studio contra el servidor local (serve.sh).

Mide el tiempo de servicio de extremo a extremo de cada GET (conexión nueva por petición, cuerpo leído entero), con
concurrencia 1: es latencia por petición, no capacidad. No hay omisión coordinada que corregir porque no se reclama
caudal: cada petición sale cuando la anterior terminó y, además, respetando el ritmo mínimo de abajo.

Ritmo (para no medir 429 ni tocar los limitadores): lecturas ≥ 115 ms entre salidas (≤ 522/min, cupo de red 600/min)
rotando sesiones de personas distintas (cupo 120/min por credencial); la evidencia del canario ≥ 2,1 s (cupo de red
30/min, 10/min por persona). Un 429 o cualquier respuesta ≠ 200 se cuenta y se informa; nunca se descarta.

Uso:  PERF_TOKENS_FILE=<sesiones, una por línea> python3 -I http-bench.py <manifest.json> [filtro]
      PERF_BASE (http://127.0.0.1:8310/api/admin/ultron-studio) · PERF_N (30) · PERF_WARM (5) · PERF_REPS (3)
      PERF_OUT (fichero JSON de salida; por defecto resultados/http-<UTC>.json junto a este guion)
Solo GET. Sin dependencias: biblioteca estándar.
"""
import http.client
import json
import os
import sys
import time
import urllib.parse


def percentiles(t):
    t = sorted(t)
    n = len(t)
    if n == 0:
        return {'n': 0, 'p50': 0.0, 'p95': 0.0, 'max': 0.0, 'min': 0.0}

    def p(q):
        import math
        return round(t[max(0, math.ceil(q * n) - 1)], 3)

    return {'n': n, 'p50': p(0.50), 'p95': p(0.95), 'max': round(t[-1], 3), 'min': round(t[0], 3)}


def peticion(base, ruta, sesion):
    u = urllib.parse.urlsplit(base)
    conn = http.client.HTTPConnection(u.hostname, u.port or 80, timeout=60)
    camino = u.path.rstrip('/') + '/' + ruta.lstrip('/')
    t0 = time.perf_counter()
    conn.request('GET', camino, headers={'Authorization': 'Bearer ' + sesion, 'Accept': 'application/json', 'Connection': 'close'})
    r = conn.getresponse()
    cuerpo = r.read()
    ms = (time.perf_counter() - t0) * 1000
    conn.close()
    return r.status, len(cuerpo), ms


def main():
    if len(sys.argv) < 2:
        print(__doc__)
        return 2
    with open(sys.argv[1], encoding='utf-8') as f:
        manifiesto = json.load(f)
    filtro = sys.argv[2] if len(sys.argv) > 2 else None
    with open(os.environ['PERF_TOKENS_FILE'], encoding='utf-8') as f:
        sesiones = [l.strip() for l in f if l.strip()]
    if len(sesiones) < 6:
        print('hacen falta al menos 6 sesiones de personas distintas', file=sys.stderr)
        return 2
    base = os.environ.get('PERF_BASE', 'http://127.0.0.1:8310/api/admin/ultron-studio')
    n = int(os.environ.get('PERF_N', '30'))
    calentar = int(os.environ.get('PERF_WARM', '5'))
    tandas = int(os.environ.get('PERF_REPS', '3'))
    salida = os.environ.get('PERF_OUT') or os.path.join(os.path.dirname(os.path.abspath(__file__)), 'resultados', time.strftime('http-%Y%m%dT%H%M%SZ.json', time.gmtime()))

    resultados = []
    turno = 0
    for ruta in manifiesto['routes']:
        if filtro and filtro not in ruta['name']:
            continue
        intervalo = 2.1 if ruta.get('limiter') == 'evidence' else 0.115
        estados = {}
        bytes_vistos = set()
        porTanda = []
        todas = []
        cargas = [os.getloadavg()[0]]
        ultima = 0.0
        for k in range(-1, tandas):           # k = -1: calentamiento
            tiempos = []
            for i in range(calentar if k < 0 else n):
                espera = intervalo - (time.perf_counter() - ultima)
                if espera > 0:
                    time.sleep(espera)
                ultima = time.perf_counter()
                sesion = sesiones[turno % len(sesiones)]
                turno += 1
                estado, largo, ms = peticion(base, ruta['path'], sesion)
                estados[estado] = estados.get(estado, 0) + 1
                if estado != 200:
                    continue
                bytes_vistos.add(largo)
                if k >= 0:
                    tiempos.append(ms)
            if k >= 0:
                porTanda.append(percentiles(tiempos))
                todas.extend(tiempos)
                cargas.append(os.getloadavg()[0])
        fila = {
            'route': ruta['name'], 'path': ruta['path'], 'size': ruta.get('size'), 'statuses': estados,
            'bytes': sorted(bytes_vistos), 'reps': porTanda, 'all': percentiles(todas), 'load1': cargas,
        }
        resultados.append(fila)
        p95s = [r['p95'] for r in porTanda]
        print(f"| {ruta['name']} | {ruta.get('size')} | {fila['all']['n']} | {fila['all']['p50']:.1f} | {fila['all']['p95']:.1f} | "
              f"{fila['all']['max']:.1f} | {min(p95s) if p95s else 0:.1f}–{max(p95s) if p95s else 0:.1f} | "
              f"{'/'.join(str(b) for b in fila['bytes'])} | {estados} | carga≤{max(cargas):.1f} |", flush=True)

    os.makedirs(os.path.dirname(salida), exist_ok=True)
    with open(salida, 'w', encoding='utf-8') as f:
        json.dump({'base': base, 'at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), 'n': n, 'warm': calentar, 'reps': tandas,
                   'sizes': manifiesto.get('sizes'), 'results': resultados}, f, ensure_ascii=False, indent=2)
    print(f'\nresultados: {salida}')
    return 0


if __name__ == '__main__':
    sys.exit(main())

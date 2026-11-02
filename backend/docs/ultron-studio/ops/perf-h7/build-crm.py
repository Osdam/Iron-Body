#!/usr/bin/env python3
"""PERF7 (Hito 7): tiempo de `ng build` (configuración production, la de despliegue) y tamaño de los chunks del CRM.

Uso:  python3 -I build-crm.py <raíz del CRM> [corridas=3]
      PERF_OUT (JSON de salida; por defecto resultados/crm-build-<UTC>.json junto a este guion)
Cada corrida: borra dist/ del propio CRM, lanza node_modules/.bin/ng build y cronometra el tiempo de pared; guarda la
salida del build. Al final, de la ÚLTIMA corrida: los ficheros iniciales (los que lista el propio build como
«Initial chunk files») y los perezosos del Studio (rutas marketing/ultron-studio, /laboratorio, /integraciones), en
bytes crudos y gzip -9 (nginx sirve gzip; brotli no está en la biblioteca estándar).
No modifica nada fuera de dist/ del CRM. Sin dependencias.
"""
import gzip
import json
import os
import re
import shutil
import subprocess
import sys
import time


def gz(path):
    with open(path, 'rb') as f:
        return len(gzip.compress(f.read(), 9))


def main():
    raiz = os.path.abspath(sys.argv[1])
    corridas = int(sys.argv[2]) if len(sys.argv) > 2 else 3
    salida = os.environ.get('PERF_OUT') or os.path.join(os.path.dirname(os.path.abspath(__file__)), 'resultados', time.strftime('crm-build-%Y%m%dT%H%M%SZ.json', time.gmtime()))
    ng = os.path.join(raiz, 'node_modules', '.bin', 'ng')
    dist = os.path.join(raiz, 'dist')
    tiempos = []
    texto = ''
    for i in range(corridas):
        if os.path.isdir(dist):
            shutil.rmtree(dist)
        carga = os.getloadavg()[0]
        t0 = time.perf_counter()
        p = subprocess.run([ng, 'build'], cwd=raiz, capture_output=True, text=True, env={**os.environ, 'NG_CLI_ANALYTICS': 'false', 'CI': '1'})
        dt = time.perf_counter() - t0
        texto = p.stdout + p.stderr
        tiempos.append({'run': i + 1, 'seconds': round(dt, 2), 'exit': p.returncode, 'load1Before': round(carga, 2), 'load1After': round(os.getloadavg()[0], 2)})
        print(f'corrida {i + 1}: {dt:.1f} s (salida {p.returncode}, carga antes {carga:.2f})', flush=True)
        if p.returncode != 0:
            print(texto[-3000:])
            return 1

    navegador = os.path.join(dist, 'frontend', 'browser')
    limpio = re.sub(r'\x1b\[[0-9;]*m', '', texto)
    iniciales = []
    perezosos = []
    seccion = None
    for linea in limpio.splitlines():
        if 'Initial chunk files' in linea:
            seccion = 'i'
            continue
        if 'Lazy chunk files' in linea:
            seccion = 'l'
            continue
        m = re.match(r'^\s*(\S+\.(?:js|css))\s+\|\s+(.*?)\s+\|\s+([\d.]+ [kMG]?B)\s+\|', linea)
        if m and seccion:
            fila = {'file': m.group(1), 'names': m.group(2).strip(), 'rawReported': m.group(3)}
            ruta = os.path.join(navegador, fila['file'])
            if os.path.exists(ruta):
                fila['bytes'] = os.path.getsize(ruta)
                fila['gzip'] = gz(ruta)
            (iniciales if seccion == 'i' else perezosos).append(fila)
    studio = [f for f in perezosos if re.search(r'ultron|studio', f['names'], re.I)]
    total_ini = sum(f.get('bytes', 0) for f in iniciales)
    total_ini_gz = sum(f.get('gzip', 0) for f in iniciales)
    resumen = {
        'crm': raiz,
        'at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()),
        'runs': tiempos,
        'initial': {'files': iniciales, 'bytes': total_ini, 'gzip': total_ini_gz},
        'studioLazy': studio,
        'lazyCount': len(perezosos),
        'buildTail': limpio[-4000:],
    }
    os.makedirs(os.path.dirname(salida), exist_ok=True)
    with open(salida, 'w', encoding='utf-8') as f:
        json.dump(resumen, f, ensure_ascii=False, indent=2)
    print(f"\ninicial: {total_ini:,} B crudos / {total_ini_gz:,} B gzip en {len(iniciales)} ficheros".replace(',', '.'))
    for f in studio:
        print(f"perezoso del Studio: {f['file']} ({f['names']}): {f.get('bytes', 0):,} B / {f.get('gzip', 0):,} B gzip".replace(',', '.'))
    print(f'resultados: {salida}')
    return 0


if __name__ == '__main__':
    sys.exit(main())

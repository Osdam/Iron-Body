#!/usr/bin/env python3
"""Barrido del E2E del Hito 9 (prueba #26). Nunca imprime un valor: solo su etiqueta y el nombre del fichero.

Uso: python3 -I barrido-h9.py <manifiesto 600> <fichero de entorno de la copia> <directorio de tokens> -- <rutas…>

Busca, en cada fichero (texto o binario; los directorios, recursivos):
  - los DATOS PERSONALES SINTÉTICOS de la siembra (el manifiesto de `ayudante-h9.php sembrar`): el nombre completo de
    cada persona y su teléfono en claro (nacional de 10 cifras, E.164 con y sin «+»). Los últimos 4 dígitos se
    permiten (el registro de canarios los guarda, D8-17);
  - la APP_KEY de la copia (base64 y cruda en hex) y los tokens de sesión de las cuentas del E2E.
Las tablas BASE (socios, prospectos) sí llevan esos datos por diseño: por eso a este barrido se le pasan las filas de
las tablas del H9 (volcado de `ayudante-h9.php filas`), los registros, la red, las comprobaciones y la evidencia, nunca
un volcado entero de la base. Sale con 1 si encuentra algo.
"""
import base64
import json
import os
import sys


def entorno(ruta):
    out = {}
    with open(ruta, encoding='utf-8') as f:
        for linea in f:
            linea = linea.strip()
            if not linea or linea.startswith('#') or '=' not in linea:
                continue
            k, v = linea.split('=', 1)
            out[k.strip()] = v.strip()
    return out


def main(argv):
    if '--' not in argv or argv.index('--') != 4 or len(argv) < 6:
        print('uso: barrido-h9.py <manifiesto> <entorno> <tokens> -- <rutas…>', file=sys.stderr)
        return 2
    manifiesto = json.load(open(argv[1], encoding='utf-8'))
    env = entorno(argv[2])
    tokens_dir = argv[3]
    rutas = argv[5:]

    buscar = {}
    for pid, p in sorted((manifiesto.get('people') or {}).items()):
        buscar[f'nombre de {pid}'] = p['name'].encode()
        buscar[f'teléfono de {pid} (nacional)'] = p['phone'].encode()
        buscar[f'teléfono de {pid} (E.164)'] = p['e164'].encode()
        buscar[f'teléfono de {pid} (sin +)'] = p['e164'][1:].encode()
        # Con espacios, como lo escribiría una persona.
        t = p['phone']
        buscar[f'teléfono de {pid} (con espacios)'] = f'{t[0:3]} {t[3:6]} {t[6:]}'.encode()
    clave = env.get('APP_KEY', '')
    if clave.startswith('base64:'):
        buscar['APP_KEY'] = clave[7:].encode()
        buscar['APP_KEY(hex)'] = base64.b64decode(clave[7:]).hex().encode()
    for nombre in sorted(os.listdir(tokens_dir)):
        with open(os.path.join(tokens_dir, nombre), encoding='utf-8') as f:
            t = f.read().strip()
        if t:
            buscar['sesión ' + nombre] = t.encode()

    hallazgos = {}
    vistos = 0

    def mirar(ruta):
        nonlocal vistos
        try:
            with open(ruta, 'rb') as f:
                datos = f.read()
        except OSError:
            return
        vistos += 1
        for etiqueta, valor in buscar.items():
            if valor and valor in datos:
                hallazgos.setdefault(etiqueta, []).append(ruta)

    for r in rutas:
        if os.path.isdir(r):
            for raiz, _, ficheros in os.walk(r):
                for nombre in ficheros:
                    mirar(os.path.join(raiz, nombre))
        elif os.path.isfile(r):
            mirar(r)

    personas = len(manifiesto.get('people') or {})
    print(f'barrido: {len(buscar)} valores buscados ({personas} personas sintéticas, APP_KEY y sesiones) en {vistos} ficheros')
    for etiqueta, donde in sorted(hallazgos.items()):
        print(f'ENCONTRADO {etiqueta} en {len(donde)} fichero(s): ' + ', '.join(sorted({os.path.basename(d) for d in donde})))
    return 1 if hallazgos else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))

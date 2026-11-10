#!/usr/bin/env python3
"""Barrido de secretos y teléfonos del E2E del Hito 8 (TEST8-b). Nunca imprime un valor: solo su etiqueta y dónde.

Uso: python3 -I barrido.py <piezas 600> <fichero de entorno de la copia> <directorio de tokens> -- <ficheros o directorios…>

Busca, en cada fichero (texto o binario, también dentro de los directorios, recursivo):
  - las piezas SECRETAS de la ejecución (claves, secretos de webhook, herramientas e iniciación, el token de firma, las
    claves de Twilio, la credencial maestra sintética del OTP), la APP_KEY (en base64 y cruda en hex) y los tokens de
    sesión de las cuentas del E2E;
  - los CENTINELAS de los simuladores (van dentro de las transcripciones: Laravel no las guarda, D8-17 y P-8);
  - los TELÉFONOS de la ejecución en claro (canarios, el del guion de la transcripción, los contactos 4b, la llamada
    sembrada solo en Twilio y el que no es canario), con y sin «+»;
    los últimos 4 dígitos se permiten (D8-17).
Sale con 1 si encuentra algo.
"""
import base64
import os
import sys


def piezas(ruta):
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
    if '--' not in argv or len(argv) < 5:
        print('uso: barrido.py <piezas> <entorno> <tokens> -- <rutas…>', file=sys.stderr)
        return 2
    corte = argv.index('--')
    p = piezas(argv[1])
    entorno = piezas(argv[2])
    tokens_dir = argv[3]
    rutas = argv[corte + 1:]

    buscar = {}
    for k in ('EL_API_KEY', 'EL_WEBHOOK_SECRET', 'EL_TOOL_SECRET', 'EL_INITIATION_SECRET', 'TW_LARAVEL_KEY_SECRET',
              'TW_SIGNING_TOKEN', 'TW_EL_KEY_SECRET', 'TW_MASTER_TOKEN', 'SENTINEL_ELEVENLABS', 'SENTINEL_TWILIO'):
        if p.get(k):
            buscar[k] = p[k].encode()
    clave = entorno.get('APP_KEY', '')
    if clave.startswith('base64:'):
        buscar['APP_KEY'] = clave[7:].encode()
        buscar['APP_KEY(hex)'] = base64.b64decode(clave[7:]).hex().encode()
    for nombre in sorted(os.listdir(tokens_dir)):
        with open(os.path.join(tokens_dir, nombre), encoding='utf-8') as f:
            t = f.read().strip()
        if t:
            buscar['sesión ' + nombre] = t.encode()
    for e164 in ('+573000000011', '+573000000012', '+573000000019', '+573000000071', '+573000000072', '+573000000077', '+573000000099'):
        buscar['teléfono …' + e164[-4:]] = e164.encode()
        buscar['teléfono …' + e164[-4:] + ' (sin +)'] = e164[1:].encode()
        buscar['teléfono …' + e164[-4:] + ' (nacional)'] = e164[3:].encode()
    buscar['teléfono del guion (con espacios)'] = b'300 000 0019'

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

    print(f'barrido: {len(buscar)} valores buscados en {vistos} ficheros')
    for etiqueta, donde in sorted(hallazgos.items()):
        print(f'ENCONTRADO {etiqueta} en {len(donde)} fichero(s): ' + ', '.join(sorted({os.path.basename(d) for d in donde})))
    return 1 if hallazgos else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))

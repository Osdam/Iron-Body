"""Actualiza líneas de un documento por prefijo. python3 -I matriz.py <fichero> <cambios.json>
cambios.json: {"lineas": {prefijo: línea nueva}, "tras": {prefijo: líneas que se insertan después}}; cada prefijo debe casar con UNA línea."""
import json, sys
ruta, cambios = sys.argv[1], json.load(open(sys.argv[2], encoding='utf-8'))
lineas = open(ruta, encoding='utf-8').read().split('\n')
n = m = 0
for prefijo, nueva in cambios.get('lineas', {}).items():
    idx = [i for i, l in enumerate(lineas) if l.startswith(prefijo)]
    assert len(idx) == 1, 'el prefijo %r casa con %d líneas' % (prefijo, len(idx))
    lineas[idx[0]] = nueva; n += 1
for prefijo, extra in cambios.get('tras', {}).items():
    idx = [i for i, l in enumerate(lineas) if l.startswith(prefijo)]
    assert len(idx) == 1, 'el prefijo %r casa con %d líneas' % (prefijo, len(idx))
    lineas[idx[0] + 1:idx[0] + 1] = extra.split('\n'); m += 1
open(ruta, 'w', encoding='utf-8').write('\n'.join(lineas))
print('matriz actualizada: %d líneas y %d inserciones' % (n, m))

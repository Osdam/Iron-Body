#!/usr/bin/env python3
"""PERF7: tablas en Markdown a partir de los JSON del arnés (resultados/*.json).

Uso:  python3 -I resumen.py [directorio de resultados] [--detalle]
      --detalle añade, por fila, las tablas tocadas y la sentencia más repetida.
Sin dependencias: solo la biblioteca estándar.
"""
import json
import os
import sys


def ms(v):
    return '—' if v is None else f'{v:.1f}'


def tiempos(t):
    """p50 / p95 / máx de todas las tandas juntas y la dispersión de los p95 entre tandas."""
    if not t:
        return ('—', '—', '—', '—', '—')
    todas = t['all']
    p95s = [r['p95'] for r in t['reps']]
    disp = f'{min(p95s):.1f}–{max(p95s):.1f}' if len(p95s) > 1 else '—'
    carga = max(t.get('load1') or [0])
    return (str(todas['n']), ms(todas['p50']), ms(todas['p95']), ms(todas['max']), f'{disp} (carga≤{carga:.1f})')


def main():
    args = [a for a in sys.argv[1:] if not a.startswith('--')]
    detalle = '--detalle' in sys.argv
    base = args[0] if args else os.path.join(os.path.dirname(os.path.abspath(__file__)), 'resultados')
    for nombre in sorted(os.listdir(base)):
        if not nombre.endswith('.json'):
            continue
        with open(os.path.join(base, nombre), encoding='utf-8') as f:
            doc = json.load(f)
        if nombre.startswith('http-'):
            print(f"\n### {nombre} · banco HTTP · {doc.get('at')} · n={doc.get('n')} × {doc.get('reps')} tandas tras {doc.get('warm')} de calentamiento\n")
            print('| Ruta | Datos | n | p50 ms | p95 ms | máx ms | p95 por tanda | bytes | estados | carga 1 min |')
            print('|---|---|---|---|---|---|---|---|---|---|')
            for r in doc['results']:
                a = r['all']
                p95s = [x['p95'] for x in r['reps']]
                print(f"| {r['route']} | {r['size']} | {a['n']} | {a['p50']:.1f} | {a['p95']:.1f} | {a['max']:.1f} | {min(p95s):.1f}–{max(p95s):.1f} | "
                      f"{'/'.join(str(b) for b in r['bytes'])} | {r['statuses']} | {min(r['load1']):.1f}–{max(r['load1']):.1f} |")
            continue
        if nombre.startswith('crm-build-'):
            print(f"\n### {nombre} · ng build (production) · {doc.get('at')}\n")
            for c in doc['runs']:
                print(f"- corrida {c['run']}: {c['seconds']} s (carga antes {c['load1Before']}, después {c['load1After']})")
            ini = doc['initial']
            print(f"- inicial: {ini['bytes']:,} B crudos / {ini['gzip']:,} B gzip en {len(ini['files'])} ficheros".replace(',', '.'))
            for f in doc['studioLazy']:
                print(f"- perezoso del Studio: {f['file']} ({f['names']}): {f.get('bytes', 0):,} B / {f.get('gzip', 0):,} B gzip".replace(',', '.'))
            continue
        env = doc.get('env', {})
        print(f"\n### {nombre} · {env.get('at')} · n={env.get('n')} × {env.get('reps')} tandas · carga al empezar {env.get('load')}\n")
        print('| Ruta | Tamaño | n | p50 ms | p95 ms | máx ms | p95 por tanda | consultas | ms en BD | bytes |')
        print('|---|---|---|---|---|---|---|---|---|---|')
        for r in doc['results']:
            if 'reader' in r:
                for modo, p in r['reader'].items():
                    print(f"| {r['route']} ({modo}) | {r['size']} | {p['n']} | {p['p50']:.3f} | {p['p95']:.3f} | {p['max']:.3f} | — | — | — | — |")
                continue
            n, p50, p95, mx, disp = tiempos(r.get('timing'))
            print(f"| {r['route']} | {r['size']} | {n} | {p50} | {p95} | {mx} | {disp} | {r['queries']} | {r['dbMs']:.1f} | {r['bytes']:,} |".replace(',', '.'))
            if detalle:
                tablas = ', '.join(f'{k}×{v}' for k, v in r['perTable'].items())
                rep = r.get('mostRepeated') or {}
                extra = f" · sello={r.get('stamp')} · Studio={r.get('studioStatements')}" if 'stamp' in r else ''
                print(f"|  ↳ | {tablas} | | | | | | máx. repetida ×{rep.get('count')}: `{(rep.get('sql') or '')[:120]}` | |{extra} |")


if __name__ == '__main__':
    main()

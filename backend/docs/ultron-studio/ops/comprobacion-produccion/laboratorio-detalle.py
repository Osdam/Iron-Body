#!/usr/bin/env python3
"""LABORATORIO FRENTE AL MOTOR: el detalle que explica el recuento de laboratorio-recuento.py. Las ejecuciones con error,
las que escribiría el modelo (con sus borradores) y por qué el revisor rechazó las demás (sus motivos, contados, y si el
texto rechazado tenía o no algún defecto para el servidor). De lo leído en solo lectura con
laboratorio-lectura-del-dia.sql; no llama a nada. Escrito para la línea base del 23-11-2026.
Uso: python3 -I laboratorio-detalle.py <fichero .jsonl>"""
import collections
import json
import sys

filas = [json.loads(x) for x in open(sys.argv[1], encoding="utf-8") if x.strip().startswith("{")]


def corta(s, n):
    s = (s or "").replace("\n", " ⏎ ")
    return s if len(s) <= n else s[:n] + "…"


print("=== LA EJECUCIÓN CON ERROR")
for i, f in enumerate(filas, 1):
    if f.get("case_status") != "done" or f.get("error_code"):
        print(f"  n.º {i} · {f['run8']} · ejecución {f['run_status']} · caso {f.get('case_status')} · error {f.get('error_code')} · outcome {f.get('outcome')} · parada {f.get('stop_reason')}")
        print(f"  creada {f['created_at']} · empezó {f.get('case_started')} · terminó {f.get('case_finished')} · coste {(f.get('run_cost') or 0) / 1e6:.4f} · reservado {(f.get('reserved_micros') or 0) / 1e6:.4f} · sobrecoste {f.get('overrun_micros')}")
        print("  engine:", json.dumps(f.get("engine"), ensure_ascii=False)[:500])
        print("  llamadas:", json.dumps(f.get("calls"), ensure_ascii=False)[:900])
        print("  resumen de la ejecución:", json.dumps(f.get("summary"), ensure_ascii=False)[:400])
        print("  hallazgos:", json.dumps(f.get("checks"), ensure_ascii=False)[:500])

print("\n=== LATENCIAS DE LAS LLAMADAS AL MODELO (ms), por papel, en las 19 con detalle")
lat = collections.defaultdict(list)
for f in filas:
    for c in (f.get("calls") or []):
        if c.get("latencyMs") is not None:
            lat[c.get("role")].append(c["latencyMs"])
for k, v in lat.items():
    v = sorted(v)
    print(f"  {k:<18} n={len(v):>2} · mediana {v[len(v) // 2]:>5} · máxima {v[-1]:>6}")

print("\n=== LA ÚNICA QUE ESCRIBIRÍA EL MODELO")
for i, f in enumerate(filas, 1):
    e = f.get("engine") or {}
    if (e.get("final") or {}).get("origin") == "model":
        print(f"  n.º {i} · {f['run8']} · texto final ({e['final'].get('chars')} de {e['final'].get('cap')} caracteres):")
        print("   ", corta(f.get("reply"), 600))
        for d in e.get("drafts") or []:
            ck = d.get("check") or {}
            cr = d.get("critic") or {}
            print(f"    borrador {d['n']} ({d['role']}, {d['chars']} car., pedido por {d.get('rewriteBy')}): comprobación {ck.get('pass')} {ck.get('codes')} · revisor {cr.get('verdict')} {cr.get('issues')}")
            print("       ", corta(d.get("text"), 330))
        j = (f.get("judge") or {})
        for c in j.get("criteria") or []:
            print("    juez del laboratorio, criterio:", c.get("verdict"), "·", corta(c.get("reason"), 300))
        print("    juez, resumen:", corta(j.get("summary"), 300))

print("\n=== POR QUÉ RECHAZÓ EL REVISOR (el último borrador de las 18 que irían al respaldo)")
motivos = collections.Counter()
limpio = []
for i, f in enumerate(filas, 1):
    e = f.get("engine") or {}
    fin = e.get("final") or {}
    if fin.get("origin") != "fallback":
        continue
    d = (e.get("drafts") or [])[-1]
    cr = d.get("critic") or {}
    for m in cr.get("issues") or []:
        motivos[m] += 1
    sin_defecto = not (fin.get("codes") or [])
    if sin_defecto:
        limpio.append(i)
    print(f"  n.º {i:>2} · {fin.get('chars')}/{fin.get('cap')} · defectos del servidor en ese texto: {fin.get('codes') or 'NINGUNO'} · revisor: {cr.get('issues')}")
    print("        texto:", corta(d.get("text"), 300))
    print("        nota del revisor:", corta(cr.get("notes"), 330))
print(f"\n  motivos del revisor, contados (18 rechazos): {dict(motivos.most_common())}")
print(f"  rechazos del revisor sobre un texto SIN ningún defecto para el servidor (lista válida y dentro del tope): {len(limpio)} → {limpio}")

print("\n=== LAS NOTAS DEL SERVIDOR AL PEDIR LA REESCRITURA (primer borrador), una por tipo de defecto")
vistos = set()
for f in filas:
    for d in ((f.get("engine") or {}).get("drafts") or [])[:1]:
        ck = d.get("check") or {}
        clave = tuple(ck.get("codes") or [])
        if clave and clave not in vistos:
            vistos.add(clave)
            print(f"  {list(clave)} ({d.get('chars')} car.):")
            print("      borrador:", corta(d.get("text"), 260))
            print("      nota:", corta(ck.get("notes"), 520))

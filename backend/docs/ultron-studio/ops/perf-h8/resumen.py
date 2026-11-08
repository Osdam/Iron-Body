#!/usr/bin/env python3
"""PERF8 (Hito 8): resumen de los JSON de resultados/ en Markdown o texto.

  python3 -I resumen.py consultas resultados/*-consultas.json     sentencias, BD, bytes y planes con lectura secuencial
  python3 -I resumen.py tiempos   resultados/*-tiempos.json       p50/p95/máx de todas, p95 por tanda (ruido) y carga
  python3 -I resumen.py indices   resultados/*-consultas.json     uso de índices de la familia (scans por índice)
  python3 -I resumen.py tabla     resultados/                     tabla por ruta: consultas, bytes, p50, p95, ruido

Sin dependencias: solo la biblioteca estándar.
"""
import glob
import json
import os
import sys


def cargar(rutas):
    out = []
    for r in rutas:
        with open(r, encoding="utf-8") as f:
            out.append(json.load(f))
    return out


def consultas(rutas):
    for d in cargar(rutas):
        print(f"\n## {d['family']}  (carga {d['env'].get('load')})")
        for r in d["results"]:
            if "queries" not in r:
                print(f"- {r['route']} · {r.get('size')}: {json.dumps({k: v for k, v in r.items() if k not in ('route', 'size')}, ensure_ascii=False)[:400]}")
                continue
            tablas = ", ".join(f"{t}×{n}" for t, n in r.get("perTable", {}).items())
            print(f"- **{r['route']}** · {r.get('size')}: HTTP {r.get('status', '—')}, {r['queries']} sentencias, BD {r['dbMs']} ms,"
                  f" {r.get('bytes', '—')} B" + (f" (cuerpo {r['requestBytes']} B)" if r.get('requestBytes') else "") + f" — {tablas}")
            mr = r.get("mostRepeated") or {}
            if mr.get("count", 0) > 2:
                print(f"    · más repetida ×{mr['count']}: {mr['sql'][:160]}")
            for p in r.get("plans", []):
                seq = [s for s in p.get("seqScans", [])]
                if seq or p.get("explainError"):
                    print(f"    · SEQ {seq} ×{p['calls']} {p.get('execMs')} ms :: {p['sql'][:150]}" + (f" ERROR {p['explainError']}" if p.get('explainError') else ''))


def tiempos(rutas):
    for d in cargar(rutas):
        print(f"\n## {d['family']}  (n={d['env'].get('n')}, carga al empezar {d['env'].get('load')})")
        for r in d["results"]:
            t = r.get("timing")
            if not t:
                continue
            a = t["all"]
            reps = t.get("reps", [])
            p95s = [x["p95"] for x in reps] if reps else []
            ruido = f"{min(p95s)}–{max(p95s)}" if p95s else "—"
            print(f"- {r['route']} · {r.get('size')}: n={a['n']} p50 {a['p50']} · p95 {a['p95']} · máx {a['max']} ms · p95 por tanda {ruido} · carga {t.get('load1')}")


def indices(rutas):
    for d in cargar(rutas):
        print(f"\n## {d['family']}")
        for i in d.get("indexUsage", []):
            if i["scans"] or i["parcial"]:
                print(f"- {i['tabla']}.{i['indice']}: {i['scans']} scans, {i['tuplas']} tuplas" + (f"  [PARCIAL: {i['parcial']}]" if i["parcial"] else ""))


def tabla(directorio):
    cons = {}
    for d in cargar(sorted(glob.glob(os.path.join(directorio, "*-consultas.json")))):
        for r in d["results"]:
            cons[(r["route"], r.get("size"))] = r
    print("| Ruta | Datos | Sentencias | BD ms | Bytes | p50 ms | p95 ms | máx ms | p95 por tanda | carga |")
    print("|---|---|---|---|---|---|---|---|---|---|")
    for d in cargar(sorted(glob.glob(os.path.join(directorio, "*-tiempos.json")))):
        for r in d["results"]:
            t = r.get("timing")
            c = cons.get((r["route"], r.get("size")), r)
            if not t:
                continue
            a = t["all"]
            p95s = [x["p95"] for x in t.get("reps", [])]
            ruido = f"{min(p95s)}–{max(p95s)}" if p95s else "—"
            carga = t.get("load1")
            carga = f"{min(carga):.1f}–{max(carga):.1f}" if isinstance(carga, list) else carga
            print(f"| {r['route']} | {r.get('size')} | {c.get('queries', '—')} | {c.get('dbMs', '—')} | {c.get('bytes', '—')} | {a['p50']} | {a['p95']} | {a['max']} | {ruido} | {carga} |")


if __name__ == "__main__":
    modo = sys.argv[1] if len(sys.argv) > 1 else ""
    if modo == "consultas":
        consultas(sys.argv[2:])
    elif modo == "tiempos":
        tiempos(sys.argv[2:])
    elif modo == "indices":
        indices(sys.argv[2:])
    elif modo == "tabla":
        tabla(sys.argv[2])
    else:
        print(__doc__)
        sys.exit(2)

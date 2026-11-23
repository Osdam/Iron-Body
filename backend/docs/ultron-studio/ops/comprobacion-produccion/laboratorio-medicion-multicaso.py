#!/usr/bin/env python3
"""MEDICIÓN DEL TURNO DE PLANES EN EL LABORATORIO CUANDO LAS EJECUCIONES LLEVAN VARIOS CASOS: el análisis que acompaña a
laboratorio-recuento.py (que se escribió para ejecuciones de un caso: su «de 36» mezcla la simulación y su coste repite
el de la ejecución en cada caso). Lee lo que dejó laboratorio-lectura-del-dia.sql (solo lectura) y no llama a nada.
Uso: laboratorio-medicion-multicaso.py <casos.jsonl> <uuid de la versión> <huella del paquete> <compilador>"""
import collections
import json
import re
import sys
from datetime import datetime, timedelta

F, VERSION, HUELLA, COMP = sys.argv[1], sys.argv[2], sys.argv[3], int(sys.argv[4])
FRASE = "¿Qué planes tienen y cuánto cuesta la mensualidad?"
filas = [json.loads(x) for x in open(F, encoding="utf-8") if x.strip().startswith("{")]


def bog(ts):
    return (datetime.fromisoformat(ts) - timedelta(hours=5)).strftime("%H:%M:%S") if ts else "?"


def de_lista(codigos):
    return [c for c in (codigos or []) if c.split("/")[0] in ("plans_overview", "policy_plans_overview")]


def usd(micros):
    return (micros or 0) / 1e6


def plano(s, n):
    s = (s or "-").replace("\n", " ")
    return s if len(s) <= n else s[:n] + "…"


motor = [f for f in filas if str(f.get("prompt_version") or "").startswith("motor-")]
simul = [f for f in filas if not str(f.get("prompt_version") or "").startswith("motor-")]
runs = []
for f in motor:
    r = next((x for x in runs if x["run8"] == f["run8"]), None)
    if r is None:
        r = {"run8": f["run8"], "created": f["created_at"], "cost": usd(f.get("run_cost")), "status": f.get("run_status"), "casos": []}
        runs.append(r)
    r["casos"].append(f)
runs.sort(key=lambda r: r["created"])

print("A. VALIDEZ DE LA MEDICIÓN")
malos = []
for f in motor:
    obj = f.get("engine_target") or {}
    turnos = [t.get("text") for t in ((f.get("scenario_snapshot") or {}).get("turns") or [])]
    if obj.get("version") != VERSION or obj.get("bundleHash") != HUELLA or obj.get("compiler") != COMP or f.get("model") != "gpt-4.1-mini" or turnos != [FRASE]:
        malos.append((f["run8"], f.get("scenario_code")))
print(f"   casos del recorrido del motor: {len(motor)} en {len(runs)} ejecuciones · todos sobre la versión {VERSION[:8]}, el paquete {HUELLA[:8]}, el compilador {COMP}, gpt-4.1-mini y la frase de los planes: {'SÍ' if not malos else 'NO → ' + str(malos)}")
print(f"   versión de prompts: {sorted(set(f.get('prompt_version') for f in motor))} · escenarios: {dict(sorted(collections.Counter(f.get('scenario_code') for f in motor).items()))}")
print(f"   lanzadas por: {sorted(set(str(f.get('created_by_role')) for f in filas))} (cuentas distintas: {len(set(f.get('created_by_admin_id') for f in filas))}) · día de presupuesto: {sorted(set(str(f.get('budget_day')) for f in filas))}")


def valida(f):
    fin = (f.get("engine") or {}).get("final") or {}
    return fin.get("origin") == "model" and not de_lista(fin.get("codes"))


def primer(f):
    ds = (f.get("engine") or {}).get("drafts") or []
    return bool(ds) and ((ds[0].get("check") or {}).get("pass") is True)


print("\nB. POR EJECUCIÓN (en el orden en que se lanzaron)")
for i, r in enumerate(runs, 1):
    cs = r["casos"]
    sin = [c for c in cs if not isinstance(c.get("engine"), dict)]
    extra = (" (" + ", ".join(str(c.get("error_code")) for c in sin) + ")") if sin else ""
    print(f"   {i}. {r['run8']} · {bog(r['created'])} (Bogotá) · {r['status']} · {len(cs)} casos · válidas escritas por el modelo: {sum(valida(c) for c in cs)}"
          f" · al respaldo: {sum(1 for c in cs if isinstance(c.get('engine'), dict) and not valida(c))} · sin resultado: {len(sin)}{extra}"
          f" · válidas al primer borrador: {sum(primer(c) for c in cs)} · coste US$ {r['cost']:.4f}")


def cuenta(casos, nombre):
    n = len(casos)
    v = sum(valida(c) for c in casos)
    p = sum(primer(c) for c in casos)
    cods = collections.Counter()
    hard = 0
    for c in casos:
        for d in (c.get("engine") or {}).get("drafts") or []:
            for k in (d.get("check") or {}).get("codes") or []:
                cods[k] += 1
            cr = d.get("critic") or {}
            if cr.get("hardFail") and not de_lista((d.get("check") or {}).get("codes")):
                hard += 1
    pw = sum(x for k, x in cods.items() if "price_written" in k)
    um = sum(x for k, x in cods.items() if "unknown_marker" in k)
    print(f"   {nombre}: {n} muestras · válidas escritas por el modelo {v} ({100 * v / n:.0f} %) · válidas al primer borrador {p} ({100 * p / n:.0f} %)"
          f" · price_written {pw} · unknown_marker {um} · fallo grave del revisor sobre una lista válida {hard}")


print("\nC. CONTRA LOS UMBRALES (17 o más de 20 válidas = 85 %; 12 o más de 20 al primer borrador = 60 %; 0; 0; 0)")
cuenta([c for r in runs[:4] for c in r["casos"]], "las 20 previstas (las cuatro primeras ejecuciones)")
cuenta(motor, f"las {len(motor)} que se lanzaron")

print("\nD. DÓNDE SE PIERDEN (los casos con detalle del motor)")
con = [c for c in motor if isinstance(c.get("engine"), dict)]
d1 = collections.Counter()
dfin = collections.Counter()
motivos = collections.Counter()
marc = collections.Counter()
estados = collections.Counter()
borr = collections.Counter()
pregs = collections.Counter()
largos = []
fin_limpio = rech = rech_limpio = tuteo_dice = tuteo_falso = 0
for c in con:
    e = c["engine"]
    ds = e.get("drafts") or []
    fin = e.get("final") or {}
    ult = ds[-1]
    estados[(e.get("strategy") or {}).get("nextState")] += 1
    borr[len(ds)] += 1
    largos.append(ds[0].get("chars") or 0)
    for k in (ds[0].get("check") or {}).get("codes") or []:
        d1[k] += 1
    for k in fin.get("codes") or []:
        dfin[k] += 1
    limpio = not (fin.get("codes") or [])
    fin_limpio += limpio
    marc[len(set(re.findall(r"\{\{PLAN_[123]\}\}", ult.get("text") or "")))] += 1
    m = re.findall(r"¿[^?]*\?", ult.get("text") or "")
    pregs[m[-1] if m else "(sin pregunta)"] += 1
    cr = ult.get("critic") or {}
    if cr.get("verdict") == "fail":
        rech += 1
        rech_limpio += limpio
        for x in cr.get("issues") or []:
            motivos[x] += 1
        if re.search(r"tuteo|\btú\b|\btu\b", cr.get("notes") or "", re.I):
            tuteo_dice += 1
            tuteo_falso += not (ult.get("tuteo") or [])
print(f"   casos con detalle: {len(con)} · borradores usados: {dict(borr)} · etapa que eligió el estratega: {dict(estados)}")
print(f"   PRIMER borrador, defectos para el servidor: {dict(d1.most_common())} · su longitud: mediana {sorted(largos)[len(largos) // 2]} caracteres, máxima {max(largos)} (tope 200)")
print(f"   ÚLTIMO borrador sin ningún defecto para el servidor (lista válida y dentro del tope): {fin_limpio} de {len(con)} · defectos que quedan: {dict(dfin.most_common()) or 'ninguno'}")
print(f"   planes que nombra el último borrador con su marcador: {dict(sorted(marc.items(), reverse=True))}")
print(f"   la pregunta con la que cierra el último borrador: {dict(pregs.most_common())}")
print(f"   el REVISOR rechazó el último borrador en {rech} de {len(con)}; de esos, {rech_limpio} eran textos sin ningún defecto para el servidor")
print(f"   motivos del revisor en esos {rech} rechazos: {dict(motivos.most_common())}")
print(f"   en {tuteo_dice} de {rech} rechazos la nota del revisor habla de tuteo; en {tuteo_falso} de esos {tuteo_dice}, el detector de tuteo del servidor no encontró ninguno en ese texto")

print("\nE. LA SIMULACIÓN DEL STUDIO (la de la puerta de publicar)")
for r8 in sorted(set(f["run8"] for f in simul)):
    cs = sorted([f for f in simul if f["run8"] == r8], key=lambda x: x.get("position") or 0)
    f0 = cs[0]
    print(f"   {r8} · {bog(f0['created_at'])} (Bogotá) · {f0.get('run_status')} · versión {f0.get('version8')} · prompts {f0.get('prompt_version')} · {len(cs)} casos"
          f" · resultados: {dict(collections.Counter(str(c.get('outcome')) for c in cs))} · propuesta del laboratorio: {f0.get('ai_recommendation')} · coste US$ {usd(f0.get('run_cost')):.4f}")
    for c in cs:
        if c.get("outcome") != "pass":
            print(f"      no pasa → {c.get('scenario_code')} · {c.get('outcome')} · {(c.get('scenario_snapshot') or {}).get('title')}")

print("\nF. COSTE")
todas = {}
for f in filas:
    todas[f["run8"]] = usd(f.get("run_cost"))
print(f"   {len(todas)} ejecuciones · US$ {sum(todas.values()):.4f} en total · recorridos del motor US$ {sum(r['cost'] for r in runs):.4f} · por muestra del motor US$ {sum(r['cost'] for r in runs) / max(1, len(motor)):.4f}")

print("\nG. LOS TEXTOS QUE SÍ SALDRÍAN DEL MODELO (y lo que dijo de ellos el revisor)")
for c in con:
    e = c["engine"]
    if (e.get("final") or {}).get("origin") == "model":
        u = (e.get("drafts") or [])[-1]
        cr = u.get("critic") or {}
        fin = e.get("final") or {}
        print(f"   {c['run8']} · {c.get('scenario_code')} · {fin.get('chars')}/{fin.get('cap')} caracteres ya con los planes puestos · revisor {cr.get('verdict')}: {plano(u.get('text'), 330)}")
        print(f"      nota del revisor: {plano(cr.get('notes'), 260)}")

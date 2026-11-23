#!/usr/bin/env python3
"""MEDICIÓN DEL TURNO DE PLANES EN EL LABORATORIO FRENTE AL MOTOR: el recuento de las ejecuciones de un día, de lo leído en
producción en SOLO LECTURA con laboratorio-lectura-del-dia.sql (una línea JSON por caso). No llama a nada: solo cuenta.

Uso:
  ssh ironbody-vps 'export LC_ALL=C; cd /tmp; PGOPTIONS="-c default_transaction_read_only=on -c statement_timeout=30000" \\
      sudo -E -u postgres psql -X -At -d ironbody -f -' < laboratorio-lectura-del-dia.sql > casos.jsonl
  python3 -I laboratorio-recuento.py casos.jsonl [--textos] [--version=<uuid>] [--huella=<sha256 del paquete>]
      [--compilador=N] [--escenarios=E-001,E-002] [--modo=base|medicion]

Por defecto, la LÍNEA BASE del 23-11-2026 (versión 3, compilador 4, huella 26d57565…, escenario E-001, umbral «como mucho
6 válidas de 20»: tiene que REPRODUCIR el fallo). Con --modo=medicion, los umbrales para pedir la quinta prueba real
(diseno-arq-pt17-planes.md §5 b): 17 o más de 20 escritas por el modelo con lista válida, 12 o más válidas al primer
borrador, ninguna con price_written ni unknown_marker y ningún fallo grave del revisor sobre una lista válida.
Resultado de la línea base: evidencia-h9/2026-11-23/05-linea-base-del-laboratorio.txt (1 de 20; 18 al respaldo; 1 perdida)."""
import collections
import json
import sys
from datetime import datetime, timedelta

OPC = dict(a[2:].split("=", 1) for a in sys.argv[2:] if a.startswith("--") and "=" in a)
FRASE = "¿Qué planes tienen y cuánto cuesta la mensualidad?"
VERSION = OPC.get("version", "39cfae9f-19a1-44bd-8d8e-f9f3327fed81")
HUELLA = OPC.get("huella", "26d57565894df0fb9885d73be4c4aba818f16529bfb0fe4ae2e4418c5e1b1075")
COMPILADOR = int(OPC.get("compilador", "4"))
ESCENARIOS = set(OPC.get("escenarios", "E-001").split(","))
MODO = OPC.get("modo", "base")
UMBRAL = 6
filas = [json.loads(x) for x in open(sys.argv[1], encoding="utf-8") if x.strip().startswith("{")]
TEXTOS = "--textos" in sys.argv


def bog(ts):
    return (datetime.fromisoformat(ts) - timedelta(hours=5)).strftime("%H:%M:%S") if ts else "?"


def de_lista(codigos):
    return [c for c in (codigos or []) if c.split("/")[0] in ("plans_overview", "policy_plans_overview")]


print(f"EJECUCIONES LEÍDAS: {len({f['run_id'] for f in filas})} · casos: {len(filas)}")
inval = []
orig = collections.Counter()
causas = collections.Counter()
primer = 0
validas = []
cod_todos = collections.Counter()
cod_final = collections.Counter()
cod_primer = collections.Counter()
criticos = collections.Counter()
hard = []
ndraft = collections.Counter()
juez = collections.Counter()
outcomes = collections.Counter()
coste = tin = tout = 0
intents = collections.Counter()
print("\n n.º  ejec.    hora(Bog)  s    borr.  1.er borrador                      revisor(es)        saldría        causa / códigos finales              car./tope  coste US$")
for i, f in enumerate(filas, 1):
    e = f.get("engine")
    obj = f.get("engine_target") or {}
    snap = f.get("scenario_snapshot") or {}
    turnos = snap.get("turns") or []
    motivos = []
    if f["run_status"] != "completed":
        motivos.append(f"ejecución {f['run_status']}")
    if f.get("stop_reason"):
        motivos.append(f"parada: {f['stop_reason']}")
    if f.get("case_status") != "done":
        motivos.append(f"caso {f.get('case_status')}")
    if f.get("error_code"):
        motivos.append(f"error {f['error_code']}")
    if not isinstance(e, dict) or "final" not in e:
        motivos.append("sin detalle del motor")
    if f.get("prompt_version", "").split("-")[0] != "motor":
        motivos.append(f"no es el recorrido del motor ({f.get('prompt_version')})")
    if obj.get("version") != VERSION or obj.get("bundleHash") != HUELLA or obj.get("compiler") != COMPILADOR:
        motivos.append("otro objetivo, otra huella u otro compilador")
    if f.get("model") != "gpt-4.1-mini" or obj.get("model") != "gpt-4.1-mini":
        motivos.append(f"otro modelo ({f.get('model')})")
    # El escenario puede traer turnos antes (el saludo y la bienvenida); lo que mide es que el ÚLTIMO sea la frase exacta.
    if f.get("scenario_code") not in ESCENARIOS or not turnos or turnos[-1].get("role") != "prospect" or turnos[-1].get("text") != FRASE:
        motivos.append("otro escenario u otra frase")
    coste += f.get("run_cost") or 0
    tin += f.get("run_in") or 0
    tout += f.get("run_out") or 0
    outcomes[f.get("outcome")] += 1
    if motivos:
        inval.append((i, f["run8"], motivos))
    if not isinstance(e, dict) or "final" not in e:
        print(f" {i:>3}  {f['run8']}  {bog(f['created_at'])}   -    -      (sin detalle del motor: {'; '.join(motivos)})")
        if TEXTOS:
            print("        claves de engine:", sorted(e.keys()) if isinstance(e, dict) else e, "· reply:", (f.get("reply") or "")[:200], "· checks:", json.dumps(f.get("checks"), ensure_ascii=False)[:400], "· calls:", json.dumps(f.get("calls"), ensure_ascii=False)[:500])
        continue
    ds = e.get("drafts") or []
    fin = e["final"]
    intents[(e.get("strategy") or {}).get("intent")] += 1
    ndraft[len(ds)] += 1
    for d in ds:
        for c in ((d.get("check") or {}).get("codes") or []):
            cod_todos[c] += 1
        if (d.get("check") or {}).get("http") not in (None, 200):
            inval.append((i, f["run8"], [f"la comprobación del borrador {d.get('n')} respondió {d['check'].get('http')}"]))
        cr = d.get("critic")
        if cr:
            criticos[cr.get("verdict")] += 1
            if cr.get("hardFail"):
                hard.append((i, d.get("n"), cr.get("hardFail"), bool(de_lista((d.get("check") or {}).get("codes")))))
    d1 = ds[0] if ds else {}
    c1 = d1.get("check") or {}
    if c1.get("pass") is True:
        primer += 1
    for c in (c1.get("codes") or []):
        cod_primer[c] += 1
    for c in (fin.get("codes") or []):
        cod_final[c] += 1
    orig[fin.get("origin")] += 1
    causas[fin.get("cause")] += 1
    lista_ok = not de_lista(fin.get("codes"))
    if fin.get("origin") == "model" and lista_ok:
        validas.append(i)
    for c in ((f.get("judge") or {}).get("criteria") or []):
        juez[c.get("verdict")] += 1
    rev = ",".join(f"{d.get('n')}:{(d.get('critic') or {}).get('verdict')}" for d in ds if d.get("critic")) or "-"
    p1 = ("pasa" if c1.get("pass") is True else "no pasa: " + ",".join(c1.get("codes") or [])) if c1 else "sin comprobar"
    sale = "EL MODELO" if fin.get("origin") == "model" else "respaldo"
    print(f" {i:>3}  {f['run8']}  {bog(f['created_at'])}  {round((e.get('elapsedMs') or 0) / 1000):>2}   {len(ds)}      {p1[:34]:<34} {rev:<18} {sale:<14} {((fin.get('cause') or '-') + ' / ' + ','.join(fin.get('codes') or []))[:36]:<36} {fin.get('chars')}/{fin.get('cap')}   {(f.get('run_cost') or 0) / 1e6:.4f}")
    if TEXTOS:
        print("        →", (f.get("reply") or "").replace("\n", " ⏎ ")[:330])

n = len(filas)
print(f"\nRESUMEN ({n} ejecuciones)")
print(f"  saldría el texto del MODELO: {orig.get('model', 0)} · saldría el RESPALDO fijo del servidor: {orig.get('fallback', 0)} · sin detalle: {n - sum(orig.values())}")
print(f"  VÁLIDAS ESCRITAS POR EL MODELO (saldría su texto y la lista pasa la comprobación del servidor): {len(validas)} de {n}  {validas if validas else ''}")
print(f"  causa del respaldo: {dict(causas)}")
print(f"  lista válida al PRIMER borrador (drafts[0].check.pass): {primer} de {n}")
print(f"  borradores por caso: {dict(sorted(ndraft.items()))} · veredictos del revisor (todos los borradores revisados): {dict(criticos)} · fallos graves del revisor: {hard if hard else 'ninguno'}")
print(f"  defectos del primer borrador: {dict(cod_primer.most_common())}")
print(f"  defectos en todos los borradores comprobados: {dict(cod_todos.most_common())}")
print(f"  defectos que quedarían en el texto final: {dict(cod_final.most_common())}")
print(f"  price_written: {sum(v for k, v in cod_todos.items() if k.startswith('price_written')) + sum(v for k, v in cod_final.items() if k.startswith('price_written'))} · unknown_marker: {sum(v for k, v in cod_todos.items() if k.startswith('unknown_marker')) + sum(v for k, v in cod_final.items() if k.startswith('unknown_marker'))}")
print(f"  intención que leyó el estratega: {dict(intents)} · resultado del laboratorio (pass/review/fail): {dict(outcomes)} · juez, criterio «Debe presentar la lista…»: {dict(juez)}")
print(f"  coste total: US$ {coste / 1e6:.4f} · tokens: {tin} de entrada y {tout} de salida · primera: {bog(filas[0]['created_at'])} · última: {bog(filas[-1]['finished_at'])} (hora de Bogotá) · día de presupuesto: {sorted({f['budget_day'] for f in filas})}")
print(f"  lanzadas por: {sorted({f['created_by_role'] for f in filas})} · cuentas distintas: {len({f['created_by_admin_id'] for f in filas})}")
print(f"\nLO QUE INVALIDARÍA LA MEDICIÓN: {'nada' if not inval else ''}")
for x in inval:
    print("  · ejecución", x[0], x[1], "→", "; ".join(x[2]))
v = len(validas)
if MODO == "base":
    print(f"\nUMBRAL: la línea base reproduce el fallo si hay como mucho {UMBRAL} válidas de 20 → hay {v}: {'REPRODUCE EL FALLO' if v <= UMBRAL else 'NO LO REPRODUCE'}")
else:
    precio = sum(n_ for k, n_ in list(cod_todos.items()) + list(cod_final.items()) if k.split("/")[0] in ("price_written", "unknown_marker"))
    graves = [h for h in hard if not h[3]]
    ok = v >= 17 and primer >= 12 and precio == 0 and not graves and not inval and n >= 20
    print(f"\nUMBRALES para pedir la quinta prueba ({n} muestras; hacen falta 20): válidas escritas por el modelo {v} (≥ 17) · válidas al primer borrador {primer} (≥ 12) · price_written o unknown_marker {precio} (0) · fallos graves del revisor sobre una lista válida {len(graves)} (0) · invalidaciones {len(inval)} (0) → {'SE CUMPLEN' if ok else 'NO SE CUMPLEN'}")

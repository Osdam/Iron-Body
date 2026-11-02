"""A/B intercalado de F-2: HEAD frente al árbol, en la misma máquina y en los mismos minutos.

Uso (desde backend/): python3 -I docs/ultron-studio/ops/perf-h7/resultados/f1-f2/ab-campanas.py
Solo lee la base del banco de PERF7 (ultron_studio_h7_perf_http).
Escribe la versión de HEAD de los dos ficheros, mide, escribe la del árbol, mide (dos vueltas)
y restaura SIEMPRE la del árbol, comprobando su sha256.
"""
import hashlib
import os
import subprocess

FICHEROS = ["app/Services/UltronStudio/StudioCampaignLinks.php", "app/Services/UltronStudio/StudioMetaCampaigns.php"]
PERFIL = "docs/ultron-studio/ops/perf-h7/resultados/f1-f2/perfil-campanas.php"
VARIABLES = dict(os.environ)
VARIABLES.update({
    "APP_ENV": "testing", "DB_CONNECTION": "pgsql", "DB_HOST": "127.0.0.1", "DB_PORT": "55432",
    "DB_DATABASE": "ultron_studio_h7_perf_http", "DB_USERNAME": "ironbody", "DB_PASSWORD": "", "DB_URL": "",
    "PGGSSENCMODE": "disable",
})


def carga() -> str:
    return subprocess.run(["sysctl", "-n", "vm.loadavg"], capture_output=True, text=True).stdout.strip()


mios = {f: open(f, "rb").read() for f in FICHEROS}
huellas = {f: hashlib.sha256(b).hexdigest() for f, b in mios.items()}
head = {f: subprocess.run(["git", "show", "HEAD:backend/" + f], capture_output=True, check=True).stdout for f in FICHEROS}
try:
    for vuelta in (1, 2):
        for nombre, contenido in (("HEAD", head), ("ARBOL", mios)):
            for f, b in contenido.items():
                with open(f, "wb") as fh:
                    fh.write(b)
            print(f"\n=== {nombre} (vuelta {vuelta}) · carga {carga()} ===", flush=True)
            for guion in ("docs/ultron-studio/ops/perf-h7/tramos-campanas.php", PERFIL):
                r = subprocess.run(["php", guion], capture_output=True, text=True, env=VARIABLES)
                lineas = [l for l in r.stdout.splitlines() if l.startswith("| ") and "---" not in l and "Tramo" not in l]
                print("\n".join(lineas) if lineas else (r.stdout + r.stderr)[-500:], flush=True)
finally:
    for f, b in mios.items():
        with open(f, "wb") as fh:
            fh.write(b)
for f in FICHEROS:
    with open(f, "rb") as fh:
        print("restaurado", f, "OK" if hashlib.sha256(fh.read()).hexdigest() == huellas[f] else "DISTINTO")

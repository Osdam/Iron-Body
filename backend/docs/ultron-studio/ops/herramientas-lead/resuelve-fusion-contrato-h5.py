#!/usr/bin/env python3
"""Resuelve el conflicto del contrato del H5 al fusionar PT-15 en la integración: las dos ramas añadieron una enmienda
al final (A4-5 la integración, A4-6 PT-15). Se conservan las dos, primero la nuestra y después la suya.
Uso: resuelve-contrato.py <ruta del contrato en conflicto>"""
import sys

ruta = sys.argv[1]
t = open(ruta, encoding="utf-8").read().split("\n")
sal, estado, nuestras, suyas, conflictos = [], "fuera", [], [], 0
for l in t:
    if l.startswith("<<<<<<< "):
        assert estado == "fuera"
        estado, nuestras, suyas = "nuestras", [], []
        conflictos += 1
    elif l.startswith("=======") and estado == "nuestras":
        estado = "suyas"
    elif l.startswith(">>>>>>> ") and estado == "suyas":
        # Primero lo nuestro (A4-5), una línea en blanco de separación, y lo suyo (A4-6).
        while nuestras and nuestras[-1] == "":
            nuestras.pop()
        while suyas and suyas[0] == "":
            suyas.pop(0)
        sal += nuestras + [""] + suyas
        estado = "fuera"
    elif estado == "nuestras":
        nuestras.append(l)
    elif estado == "suyas":
        suyas.append(l)
    else:
        sal.append(l)
assert estado == "fuera" and conflictos >= 1, (estado, conflictos)
txt = "\n".join(sal)
for marca in ("<<<<<<<", ">>>>>>>"):
    assert marca not in txt, marca
for enm in ("## Enmienda A4-4", "## Enmienda A4-5", "## Enmienda A4-6"):
    assert txt.count(enm) == 1, (enm, txt.count(enm))
assert txt.index("## Enmienda A4-4") < txt.index("## Enmienda A4-5") < txt.index("## Enmienda A4-6")
open(ruta, "w", encoding="utf-8").write(txt)
print(f"contrato resuelto: {conflictos} conflicto(s); A4-4, A4-5 y A4-6 presentes una vez y en orden")

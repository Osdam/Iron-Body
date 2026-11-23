#!/usr/bin/env python3
"""Resuelve los dos conflictos de fusionar PT-14 (cierre/voz-catalogo) en la integración: en los dos se conservan las
dos partes. Uso: resuelve-fusion-pt14.py <backend de la integración>"""
import re
import sys

B = sys.argv[1]


def bloques(ruta):
    t = open(ruta, encoding="utf-8").read()
    m = re.findall(r"<<<<<<< HEAD\n(.*?)=======\n(.*?)>>>>>>> [^\n]*\n", t, flags=re.S)
    assert len(m) == 1, (ruta, len(m))
    return t, m[0][0], m[0][1]


def escribe(ruta, t, nuestro, suyo, resuelto):
    antes = "<<<<<<< HEAD\n" + nuestro + "=======\n" + suyo
    i = t.index(antes)
    j = t.index("\n", t.index(">>>>>>> ", i)) + 1
    t = t[:i] + resuelto + t[j:]
    assert "<<<<<<<" not in t and ">>>>>>>" not in t and "\n=======\n" not in t
    open(ruta, "w", encoding="utf-8").write(t)


# 1. AuthorizationMap: las dos llaves comprobadas en el controlador, la del lote 4 y la de PT-14.
p = B + "/app/Support/Access/AuthorizationMap.php"
t, nuestro, suyo = bloques(p)
assert "'commercial_automation.complaints_dismiss'," in nuestro and "'plans.commercial_text'," in suyo
escribe(p, t, nuestro, suyo, nuestro + suyo)

# 2. La guarda del motor: la lista de lectores con los del laboratorio (lote 5) Y los de la voz (PT-14), y las dos constantes.
p = B + "/tests/Feature/UltronStudio/UltronStudioEngineReadOnlyTest.php"
t, nuestro, suyo = bloques(p)
lista_nuestra, resto_nuestro = nuestro.split("    ];\n", 1)          # la línea de ficheros (con los del laboratorio) · LAB_ENGINE_READERS
lista_suya, resto_suyo = suyo.split("    ];\n", 1)                  # la línea de ficheros + el comentario y los dos de la voz · VOICE_PLAN_READS
lineas_suyas = lista_suya.split("\n")
assert lineas_suyas[0].lstrip().startswith("'StudioLabEngineChecks.php'"), lineas_suyas[0]
voz = "\n".join(lineas_suyas[1:])                                  # el comentario de PT-14 y sus dos ficheros
assert "'StudioVoicePublications.php', 'StudioVoicePublishing.php'," in voz
# Lo que la línea suya tiene que no esté en la nuestra (no debe haber nada: la nuestra es un superconjunto).
de_la_suya = set(re.findall(r"'([A-Za-z]+\.php)'", lineas_suyas[0]))
de_la_nuestra = set(re.findall(r"'([A-Za-z]+\.php)'", lista_nuestra))
assert de_la_suya <= de_la_nuestra, de_la_suya - de_la_nuestra
assert "LAB_ENGINE_READERS" in resto_nuestro and "VOICE_PLAN_READS" in resto_suyo
resuelto = lista_nuestra + voz + "    ];\n" + resto_nuestro + "\n" + resto_suyo.lstrip("\n")
escribe(p, t, nuestro, suyo, resuelto)
print("dos conflictos resueltos conservando las dos partes")

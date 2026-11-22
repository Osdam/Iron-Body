#!/usr/bin/env python3
"""Avance de ULTRON STUDIO FULL (cierre V2): un solo cálculo, repetible, sin sumar de cabeza.

Uso: python3 -I avance.py            imprime la tabla y el global
     python3 -I avance.py --json     lo mismo, en JSON

Cómo se calcula (no cambia entre informes; solo cambian las ETAPAS de cada función):
  - Los PESOS por apartado son los del cierre V2 (suman 100) y no se tocan.
  - Los apartados 1 a 3 están cerrados (100 %). El apartado 4 lleva sus propios bloques A–E (ver BLOQUES_4).
  - Los apartados 5 a 14 parten de una BASE (lo que ya existía y funcionaba antes de la directiva definitiva del
    21-11-2026, estimado entonces) y reparten lo que falta entre sus FUNCIONES pendientes, cada una con su peso.
  - Cada función avanza por ETAPAS con un valor fijo:
        0,00  sin empezar
        0,40  IMPLEMENTADA y probada en local, con su revisión de seguridad si la exige (parcial: una fracción de 0,40)
        0,50  INTEGRADA: fusionada, batería completa y revisión final sin bloqueantes
        0,70  DESPLEGADA en producción y verificada allí
        1,00  ACEPTADA: prueba real satisfactoria (o el criterio operativo de la función)
    Una función que no es código (una decisión, una lectura, un trámite) vale 1,00 cuando está hecha y 0,00 si no.
  - Avance del apartado = base + (1 − base) × Σ(peso de la función × valor de su etapa).
  - Lectura TÉCNICA: en el código, lo ganado hasta 0,50 (implementar e integrar), más los entregables técnicos que
    no son código (un diseño, un análisis). Lectura OPERATIVA: en el código, lo ganado de 0,50 a 1,00 (desplegar,
    verificar y probar en real), más los hechos operativos (una acción del propietario, una verificación en
    producción). Las dos suman el avance; no son dos escalas distintas.

Para actualizar: cambiar SOLO el valor de la etapa de la función que avanzó (y su nota), nunca los pesos ni las bases.
"""
import json
import sys

PESOS = {1: 2, 2: 1, 3: 8, 4: 18, 5: 6, 6: 9, 7: 8, 8: 10, 9: 6, 10: 7, 11: 7, 12: 6, 13: 4, 14: 8}
NOMBRES = {
    1: "Recuperar el estado", 2: "Coordinación", 3: "Laboratorio de aceptación y canario",
    4: "Studio gobierna WhatsApp", 5: "Laboratorio de texto frente al motor real",
    6: "Sofía, ElevenLabs y publicación de voz", 7: "Twilio Voice", 8: "Automatización H9",
    9: "Bandeja asistida", 10: "Consentimientos, quejas y cuatro ojos", 11: "Meta Ads y plantillas",
    12: "Pagos, membresías y eventos", 13: "Credenciales e infraestructura", 14: "Pruebas finales y certificación",
}

# Apartado 4: bloques (suman 100) y lo hecho de cada uno. D lleva sus pasos: servidor 3, n8n 3, versión 3 y
# laboratorio 2, aprobación/veredicto/reversión/publicación 0,25 cada una, tercera prueba real 3.
BLOQUES_4 = [
    ("A · Primeros bloqueantes", 22, 22.0),
    ("B · Trato de usted, textos de respaldo y lista fija", 14, 14.0),
    ("C · Regenerar antes de sustituir", 40, 40.0),
    ("D · Despliegue, n8n S2, versión 3, publicación y tercera prueba real", 12, 8.75),
    ("E · Frases prohibidas en real, ampliación por etapas, pendientes H4-11 y H4-13", 12, 0.0),
]

# (apartado): base, [(función, peso, valor de la etapa, nota, tipo)]
# tipo: c = código (pasa por las etapas) · t = entregable técnico que no es código (cuenta entero como técnico)
#       o = hecho operativo (una acción del propietario, una verificación en producción o una prueba real)
APARTADOS = {
    5: (0.25, [
        ("Diseño del recorrido del motor en el laboratorio", 10, 1.00, "hecho (arq-lab)", "t"),
        ("Backend: prompts compilados, enrutador, comprobación, contexto, ejecución y puerta", 55, 0.02, "en curso (be-lab): primera pieza", "c"),
        ("CRM: lanzar y ver una ejecución del motor", 15, 0.00, "espera al backend", "c"),
        ("Una ejecución real del motor con una versión, desplegada", 20, 0.00, "", "o"),
    ]),
    6: (0.50, [
        ("Perfil de voz revisión 3 aprobado por otra cuenta", 10, 0.00, "acción del propietario", "o"),
        ("Veredicto del laboratorio hablado", 10, 0.00, "acción del propietario", "o"),
        ("Publicación de la voz en canario", 25, 0.00, "depende de Twilio y de las dos anteriores", "o"),
        ("Sofía dice los beneficios de los planes (datos del CRM en la llamada)", 20, 0.34, "implementado y revisado; cerrando hallazgos", "c"),
        ("Guarda de voz ampliada para contactos reales", 15, 0.10, "en curso (be-voz3)", "c"),
        ("Instrucciones del Studio aplicadas a la voz, vistas en una llamada real", 20, 0.00, "", "o"),
    ]),
    7: (0.30, [
        ("Origen de las salientes: el número empresarial compartido (código, mandos, seguridad)", 25, 0.20, "backend hecho; faltan mandos y seguridad", "c"),
        ("Clave restringida, importación en ElevenLabs y piezas de la fase 2", 15, 0.00, "acción del propietario", "o"),
        ("Apertura de la constante de llamadas y su despliegue", 15, 0.20, "pruebas de la apertura listas sobre la punta", "c"),
        ("Robustez de la llamada: plazo del job y salida de la que no llegó a iniciarse", 15, 0.34, "implementado y revisado; cerrando hallazgos", "c"),
        ("Primera llamada real satisfactoria", 25, 0.00, "no antes del lunes 23-11", "o"),
        ("Entrantes: necesidad de enrutamiento documentada", 5, 1.00, "documentado; no resuelto por diseño", "t"),
    ]),
    8: (0.50, [
        ("Clasificador de respuestas", 30, 0.40, "implementado, revisado y endurecido", "c"),
        ("Sombra con estrategias reales (observación de siete días)", 35, 0.00, "exige dos personas vinculadas", "o"),
        ("WhatsApp automático en canario real", 25, 0.00, "exige plantilla aprobada por Meta", "o"),
        ("Voz por estrategia en canario real", 10, 0.00, "depende del apartado 7", "o"),
    ]),
    9: (0.60, [
        ("Seguridad R3 de la bandeja (R3-1 a R3-3, F-1 a F-3, m-1 y m-2)", 60, 0.70, "desplegado y verificado en producción el 22-11 (lote 1, 45b53023)", "c"),
        ("CRM: los once motivos y el aviso del reclamo", 15, 0.40, "implementado", "c"),
        ("Decisión D-3 (frenos ajenos)", 5, 0.00, "decisión del propietario", "o"),
        ("Modo asistido en vivo con una prueba real", 20, 0.00, "exige estrategias publicadas", "o"),
    ]),
    10: (0.35, [
        ("Libro de autorizaciones, lector y rutas", 30, 0.30, "implementado; seguridad con condiciones abiertas", "c"),
        ("CRM: pantalla de autorizaciones de contacto", 10, 0.00, "", "c"),
        ("Texto legal, fuentes e identidad de socios sin prospecto", 10, 0.00, "decisión del propietario y su asesoría", "o"),
        ("Quejas presenciales y descarte de una queja mal detectada", 15, 0.04, "en curso (be-quejas)", "c"),
        ("Cuatro ojos por regla", 20, 0.40, "implementado y revisado por seguridad de API", "c"),
        ("Dos personas reales vinculadas", 15, 0.00, "acción del propietario", "o"),
    ]),
    11: (0.45, [
        ("Permisos de Meta verificados en producción", 10, 1.00, "sonda ok el 22-11", "o"),
        ("Primeras plantillas redactadas y validadas", 15, 0.40, "cinco, contra el validador real", "t"),
        ("El validador exige la línea de baja en el texto", 5, 0.04, "en curso (be-quejas)", "c"),
        ("Primera plantilla enviada y aprobada por Meta", 35, 0.00, "exige dos personas vinculadas", "o"),
        ("Canario real con una plantilla aprobada", 35, 0.00, "", "o"),
    ]),
    12: (0.45, [
        ("Análisis de los tres asuntos, contrastado con producción", 20, 1.00, "0 pagos aprobados sin activar", "t"),
        ("Aviso automático de pago aprobado sin membresía", 40, 0.30, "implementado y probado en los dos motores (apagado por su bandera); falta la revisión final", "c"),
        ("Corrección del cobro manual con IVA adicional", 25, 0.00, "decisión del propietario (núcleo de pagos)", "c"),
        ("Decisión sobre el activador de membresías", 15, 0.00, "recomendado no tocar; lo decide el propietario", "o"),
    ]),
    13: (0.40, [
        ("Rotar la clave de ElevenLabs", 35, 0.00, "acción del propietario", "o"),
        ("Copias históricas del entorno: revisadas y limpiadas", 25, 0.40, "revisadas; la limpieza pide autorización", "c"),
        ("Rol Administrativo y cuentas Super Admin: revisados y ajustados", 20, 0.40, "revisados; el ajuste pide autorización", "c"),
        ("Reimportar el número en ElevenLabs con clave restringida y rotar el token maestro", 20, 0.00, "", "o"),
    ]),
    14: (0.00, [
        ("Batería completa sobre la integración final", 15, 0.00, "ensayo sobre tres ramas: 19 205 pruebas, 0 regresiones; falta la integración final", "t"),
        ("Recorridos de extremo a extremo simulados sobre la integración", 25, 0.00, "", "t"),
        ("Pruebas canarias reales de los cinco recorridos", 45, 0.00, "", "o"),
        ("Matriz final de aceptación", 15, 0.00, "", "o"),
    ]),
}


def avance_4():
    assert sum(p for _, p, _ in BLOQUES_4) == 100
    return sum(h for _, _, h in BLOQUES_4) / 100.0


def lecturas(valor, tipo="c"):
    """Cuánto de una función es técnico y cuánto operativo. El código reparte por etapas (técnico hasta 0,50)."""
    if tipo == "t":
        return valor, 0.0
    if tipo == "o":
        return 0.0, valor
    return min(valor, 0.5), max(valor - 0.5, 0.0)


def calcular():
    assert sum(PESOS.values()) == 100
    filas = []
    for n in sorted(PESOS):
        if n in (1, 2, 3):
            a, t, o, base = 1.0, None, None, None
        elif n == 4:
            a, t, o, base = avance_4(), None, None, None
        else:
            base, funciones = APARTADOS[n]
            assert sum(f[1] for f in funciones) == 100, n
            tec = sum(f[1] / 100.0 * lecturas(f[2], f[4])[0] for f in funciones)
            ope = sum(f[1] / 100.0 * lecturas(f[2], f[4])[1] for f in funciones)
            a = base + (1 - base) * (tec + ope)
            t, o = (1 - base) * tec, (1 - base) * ope
        filas.append({"apartado": n, "nombre": NOMBRES[n], "peso": PESOS[n], "avance": a, "base": base, "tecnico": t, "operativo": o})
    return filas, sum(f["peso"] * f["avance"] for f in filas)


def main():
    filas, total = calcular()
    if "--json" in sys.argv:
        print(json.dumps({"global": round(total, 2), "apartados": filas}, ensure_ascii=False, indent=1))
        return
    print("Apartado                                        Peso  Avance   (base · técnico ganado · operativo ganado)")
    for f in filas:
        extra = "" if f["base"] is None else "   (%2.0f %% · +%.1f · +%.1f)" % (f["base"] * 100, f["tecnico"] * 100, f["operativo"] * 100)
        print("%2d %-44s %4d  %5.1f %%%s" % (f["apartado"], f["nombre"][:44], f["peso"], f["avance"] * 100, extra))
    print("GLOBAL: %.1f %%   (exacto: %.2f)" % (total, total))
    print()
    for n in sorted(APARTADOS):
        base, funciones = APARTADOS[n]
        print("Apartado %d · %s" % (n, NOMBRES[n]))
        for nombre, peso, valor, nota, tipo in funciones:
            print("   %3d  %.2f  %s  %s%s" % (peso, valor, {"c": "código   ", "t": "técnico  ", "o": "operativo"}[tipo], nombre, (" — " + nota) if nota else ""))


if __name__ == "__main__":
    main()

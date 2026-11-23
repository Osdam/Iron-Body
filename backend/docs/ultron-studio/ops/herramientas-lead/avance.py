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
    ("D · Despliegue, n8n S2, versión 3, publicación y tercera prueba real", 12, 9.0),  # la cuarta prueba (22-11) tampoco se superó: sigue en 9,0
    # E no tenía reparto interno; desde el 22-11 sus tres partes valen 4 cada una: frases prohibidas en real (0),
    # ampliación por etapas (0) y las dos condiciones previas, H4-11 y el cubo de H4-13 (PT-15: integradas, 0,50 × 4 = 2,0).
    ("E · Frases prohibidas en real, ampliación por etapas, pendientes H4-11 y H4-13", 12, 2.0),
]

# (apartado): base, [(función, peso, valor de la etapa, nota, tipo)]
# tipo: c = código (pasa por las etapas) · t = entregable técnico que no es código (cuenta entero como técnico)
#       o = hecho operativo (una acción del propietario, una verificación en producción o una prueba real)
APARTADOS = {
    5: (0.25, [
        ("Diseño del recorrido del motor en el laboratorio", 10, 1.00, "hecho (arq-lab)", "t"),
        ("Backend: prompts compilados, enrutador, comprobación, contexto, ejecución y puerta", 55, 0.50, "integrado el 22-11 (lote 5, bdb93199): seguridad, base de datos y revisión final sin bloqueantes abiertos (B-1 corregido) y batería completa sin regresiones; falta desplegarlo (HUMAN GATE)", "c"),
        ("CRM: lanzar y ver una ejecución del motor", 15, 0.40, "implementada, probada y con su revisión final aprobada (PT-09 y PT-10, 22-11); falta publicarla (HUMAN GATE)", "c"),
        ("Una ejecución real del motor con una versión, desplegada", 20, 0.00, "", "o"),
    ]),
    6: (0.50, [
        ("Perfil de voz revisión 3 aprobado por otra cuenta", 10, 0.00, "acción del propietario", "o"),
        ("Veredicto del laboratorio hablado", 10, 0.00, "acción del propietario", "o"),
        ("Publicación de la voz en canario", 25, 0.00, "depende de Twilio y de las dos anteriores", "o"),
        ("Sofía dice los beneficios de los planes (datos del CRM en la llamada)", 20, 0.50, "integrado el 22-11 (lote 3, b1f66c32): batería completa sin regresiones y revisión final aprobada; seguridad: apto para la canaria; quién gobierna esos textos quedó decidido e implementado en PT-14 (23-11, unidad de voz e610d035), que no tiene peso propio en este cálculo", "c"),
        ("Guarda de voz ampliada para contactos reales", 15, 0.32, "léxico ampliado y nombre del plan por la guarda (C-1 i y ii), revisado por seguridad; quedan huecos y frenos falsos por calibrar", "c"),
        ("Instrucciones del Studio aplicadas a la voz, vistas en una llamada real", 20, 0.00, "", "o"),
    ]),
    7: (0.30, [
        ("Origen de las salientes: el número empresarial compartido (código, mandos, seguridad)", 25, 0.50, "integrado el 22-11 (lote 3, b1f66c32): código, mandos y runbook revisados por seguridad, batería completa sin regresiones y revisión final aprobada; desplegarlo exige la autorización específica del propietario", "c"),
        ("Clave restringida, importación en ElevenLabs y piezas de la fase 2", 15, 0.00, "acción del propietario", "o"),
        ("Apertura de la constante de llamadas y su despliegue", 15, 0.20, "pruebas de la apertura listas sobre la punta", "c"),
        ("Robustez de la llamada: plazo del job y salida de la que no llegó a iniciarse", 15, 0.50, "integrado el 22-11 (lote 3, b1f66c32): revisado por seguridad, batería completa sin regresiones y revisión final aprobada", "c"),
        ("Primera llamada real satisfactoria", 25, 0.00, "no antes del lunes 23-11", "o"),
        ("Entrantes: necesidad de enrutamiento documentada", 5, 1.00, "documentado; no resuelto por diseño", "t"),
    ]),
    8: (0.50, [
        ("Clasificador de respuestas", 30, 0.70, "desplegado y verificado en producción el 22-11 (lote 2, fddd3450); apagado: falta la prueba real", "c"),
        ("Sombra con estrategias reales (observación de siete días)", 35, 0.00, "exige dos personas vinculadas", "o"),
        ("WhatsApp automático en canario real", 25, 0.00, "exige plantilla aprobada por Meta", "o"),
        ("Voz por estrategia en canario real", 10, 0.00, "depende del apartado 7", "o"),
    ]),
    9: (0.60, [
        ("Seguridad R3 de la bandeja (R3-1 a R3-3, F-1 a F-3, m-1 y m-2)", 60, 0.70, "desplegado y verificado en producción el 22-11 (lote 1, 45b53023)", "c"),
        ("CRM: los once motivos y el aviso del reclamo", 15, 0.70, "publicado y verificado desde fuera el 22-11 (CRM a9e7e7a); falta verlo con una sesión real", "c"),
        ("Decisión D-3 (frenos ajenos)", 5, 0.00, "decisión del propietario", "o"),
        ("Modo asistido en vivo con una prueba real", 20, 0.00, "exige estrategias publicadas", "o"),
    ]),
    10: (0.35, [
        ("Libro de autorizaciones, lector y rutas", 30, 0.50, "integrado el 22-11 (lote 4, ba4b143e): vuelve a 0,50 al cerrarse PT-18 (el identificador del socio), con seguridad de API y revisión final sin bloqueantes y su batería completa; falta desplegarlo (HUMAN GATE)", "c"),
        ("CRM: pantalla de autorizaciones de contacto", 10, 0.40, "implementada, probada y con su revisión final aprobada tras cerrar el bloqueante del identificador del socio (PT-07, PT-10 y PT-18, 22-11); falta publicarla (HUMAN GATE)", "c"),
        ("Texto legal, fuentes e identidad de socios sin prospecto", 10, 0.00, "decisión del propietario y su asesoría", "o"),
        ("Quejas presenciales y descarte de una queja mal detectada", 15, 0.50, "integrado el 22-11 (lote 4, ba4b143e): vuelve a 0,50 al cerrarse PT-18 (el identificador del socio), con seguridad de API y revisión final sin bloqueantes y su batería completa; falta desplegarlo (HUMAN GATE)", "c"),
        ("Cuatro ojos por regla", 20, 0.70, "desplegado y verificado en producción el 22-11 (lote 2, fddd3450); apagado: falta la prueba real", "c"),
        ("Dos personas reales vinculadas", 15, 0.00, "acción del propietario", "o"),
    ]),
    11: (0.45, [
        ("Permisos de Meta verificados en producción", 10, 1.00, "sonda ok el 22-11", "o"),
        ("Primeras plantillas redactadas y validadas", 15, 0.40, "cinco, contra el validador real", "t"),
        ("El validador exige la línea de baja en el texto", 5, 0.50, "integrado el 22-11 (lote 4, ba4b143e): seguridad de API y revisión final sin bloqueantes y batería completa con solo los dos fallos esperados; falta desplegarlo (HUMAN GATE)", "c"),
        ("Primera plantilla enviada y aprobada por Meta", 35, 0.00, "exige dos personas vinculadas", "o"),
        ("Canario real con una plantilla aprobada", 35, 0.00, "", "o"),
    ]),
    12: (0.45, [
        ("Análisis de los tres asuntos, contrastado con producción", 20, 1.00, "0 pagos aprobados sin activar", "t"),
        ("Aviso automático de pago aprobado sin membresía", 40, 0.70, "desplegado y verificado en producción el 22-11 (lote 2, fddd3450); apagado: falta la prueba real", "c"),
        ("Corrección del cobro manual con IVA adicional", 25, 0.50, "integrado el 22-11 (PT-16, e1c2af3c): revisión final aprobada y dentro de la batería de la integración definitiva; inerte en producción; falta desplegarlo (HUMAN GATE)", "c"),
        ("Decisión sobre el activador de membresías", 15, 0.00, "recomendado no tocar; lo decide el propietario", "o"),
    ]),
    13: (0.40, [
        ("Rotar la clave de ElevenLabs", 35, 0.00, "acción del propietario", "o"),
        ("Copias históricas del entorno: revisadas y limpiadas", 25, 0.40, "revisadas; la limpieza pide autorización", "c"),
        ("Rol Administrativo y cuentas Super Admin: revisados y ajustados", 20, 0.40, "revisados; el ajuste pide autorización", "c"),
        ("Reimportar el número en ElevenLabs con clave restringida y rotar el token maestro", 20, 0.00, "", "o"),
    ]),
    14: (0.00, [
        ("Batería completa sobre la integración final", 15, 1.00, "hecha el 23-11 sobre integracion/final con todo dentro, PT-14 y PT-19 incluidos (4e53f04a): 19 781 pruebas; el único fallo es la puerta de diff, que pasa sola al subir (PT-11)", "t"),
        ("Recorridos de extremo a extremo simulados sobre la integración", 25, 1.00, "hechos el 22-11 (PT-12): WhatsApp, automatización, gobierno y seguridad demostrados en simulado; voz parcial (el audio y el corte por duración solo los demuestra una llamada real)", "t"),
        ("Pruebas canarias reales de los cinco recorridos", 45, 0.00, "", "o"),
        ("Matriz final de aceptación", 15, 0.00, "es la aceptación con las pruebas reales; el documento técnico (PT-13, MATRIZ_ACEPTACION_TECNICA.md) existe desde el 22-11 y no mueve este valor", "o"),
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

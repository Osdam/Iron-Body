# ULTRON STUDIO: evaluación asistida por IA (estado)

**Fecha:** 17-10-2026. **Estado: NO IMPLEMENTADO.** No se ha hecho ninguna llamada a OpenAI en este trabajo. Lo que sigue es lo verificado en la auditoría y el diseño con el que arrancará el Hito I.

## Lo que existe hoy

- ULTRON razona en **n8n** con cinco nodos OpenAI (3 llamadas por turno, 5 si el Critic tumba el borrador). Modelo, parámetros, esquema y credencial **no están en el repositorio**.
- En Laravel, el cerebro legado usa Chat Completions con `json_object` (no `json_schema`), `gpt-4.1-mini` por defecto, 20 s, 1 reintento, `fail_closed`.
- **No se registran tokens, modelo ni coste** de ULTRON; no hay cortacircuitos ni límite de coste para n8n. La clave de OpenAI se comparte con IRON IA y el límite TPM es de la organización.

## Diseño previsto

- **Un job fuera del camino caliente**, con **clave y proyecto propios** para no competir con ULTRON ni con IRON IA.
- **Responses API con salida estructurada estricta** (`json_schema`, `strict`): el veredicto es un objeto con campos fijos, nunca texto libre interpretado.
- **Entradas redactadas** (`MemoryRedactor`): nunca datos personales de prospectos.
- **Registro** de modelo, tokens y coste por evaluación.
- **El resumen visible** (10 puntos del encargo §17): cómo se comportará, cómo se expresará, qué estrategia usará, qué reglas respetará, qué campañas, cómo se comportarán las llamadas, qué cambió, riesgos, pruebas aprobadas y si requiere revisión humana.
- **Tres cosas distintas y así etiquetadas:** pruebas técnicas (deterministas), evaluación de IA (opinión) y aprobación humana. **El modelo no puede autorizar una publicación**, y un hallazgo determinista crítico bloquea aunque la IA opine a favor. Un escenario que solo el modelo describió no cuenta como probado.

## Preguntas abiertas

Clave/proyecto separados (y verificar el límite TPM vigente); qué escenarios del laboratorio se ejecutan de verdad contra el motor y cuáles solo contra la versión en borrador.

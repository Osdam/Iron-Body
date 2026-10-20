# ULTRON STUDIO: evaluación asistida por IA (estado)

**Fecha:** 17-10-2026; actualizado el 19-10-2026 (hora de Bogotá). **Estado: IMPLEMENTADO EN RAMA, NO DESPLEGADO** (Hito 4, laboratorio de IA: backend `6dbf485` y CRM `3e630eb`, rama `feat/ultron-studio-h4`). **Ninguna llamada real a OpenAI** se ha hecho todavía: las pruebas usan `Http::fake` y el E2E, un simulador local. La primera llamada real llega con la fase 3 del despliegue, con la clave dedicada del propietario.

**Qué es este documento:** el estado de la evaluación asistida por IA del Studio, para quien tiene que decidir sobre ella. El contrato es `ULTRON_STUDIO_H4_CONTRACT.md`; el detalle técnico, la sección «Hito 4» de `ULTRON_STUDIO_BACKEND_REPORT.md`, `ULTRON_STUDIO_SECURITY_REPORT.md` y `ULTRON_STUDIO_VISUAL_REPORT.md`.

## Lo que hay ahora

- **Una simulación del Studio, no ULTRON.** Un actor de laboratorio (un modelo de OpenAI con clave propia) interpreta una versión, el borrador propio o la línea base sobre escenarios sintéticos. Sobre su respuesta corren pruebas deterministas: las del Studio y las guardas puras del motor, a través de un único adaptador. Un juez (otro modelo, con salida estricta) opina criterio a criterio. El código agrega y propone; una persona emite el veredicto. En los datos y en la pantalla dice «Simulación del Studio · no es ULTRON».
- **Tres cosas distintas, y así etiquetadas:** «Comprobación del Studio» y «Filtro del motor» (deterministas), «Opinión de la IA» (el juez) y el veredicto de una persona. La agregación es código: **un hallazgo determinista bloqueante gana siempre a la IA**, y si el juez falla, el caso llega como mucho a «revisar». La propuesta del laboratorio se llama «Propuesta (no es un veredicto)».
- **El modelo no autoriza nada.** El veredicto lo emite una persona con `ultron.approve`, distinta del autor de la versión, uno por ejecución e inmutable. Ningún código del runner, del job ni de los comandos escribe un veredicto (lo vigila una prueba estática, que la Enmienda 5 amplía a las escrituras directas en la tabla). Y en este hito el veredicto no cambia la versión ni el motor: publicar y revertir siguen en 409.
- **Coste registrado y acotado.** Modelo, tokens y coste por llamada y por ejecución, en micro-USD. Cuatro cerrojos antes de gastar (bandera apagada, driver `fake`, presupuesto diario 0 y clave ausente o compartida), estimación aceptada de forma explícita, reserva atómica contra el tope diario, tope por ejecución sobre el coste real, `max_output_tokens` en cada llamada, ningún reintento automático y un solo worker.
- **Lo que no hace:** no ejecuta el pipeline real de ULTRON (no existe un modo seco sin tocar el motor ni n8n), no reproduce conversaciones reales, no simula varios turnos con un prospecto simulado, el modelo no escribe escenarios y el laboratorio no cubre el coste de ULTRON en n8n.

## Qué se adoptó del diseño, qué cambió y por qué

Parte de la tabla «Delta honesto» del plan del architect (`scratchpad/hito-4/plan-architect.md`), con lo que finalmente se implementó.

| Diseño del 17-10-2026 | Qué había antes del Hito 4 | Qué se implementó, y por qué |
|---|---|---|
| «Un job fuera del camino caliente» | Cinco carriles sobre la tabla `jobs` y 12 procesos de supervisor. `commercial` lleva `SendUltronEventToN8n`, el puente vivo de ULTRON a n8n | **Adoptado, con un carril propio:** `studio-lab`, con su conexión, su prioridad (P5) y un proceso `ironbody-lab` aparte, con `tries = 1`. Ni `agent` ni `commercial`: el laboratorio no puede retrasar al motor. El job vive fuera de `app/Jobs`, que es zona del motor para la prueba de aislamiento. Además, PHP-FPM tiene `max_execution_time = 30`: una llamada a un LLM nunca puede ir dentro de una petición |
| «Clave y proyecto propios» | Toda llamada de Laravel a OpenAI usa `OPENAI_API_KEY`, compartida con IRON IA | **Adoptado:** `ULTRON_STUDIO_LAB_OPENAI_API_KEY`. Si falta, o si es la misma que `OPENAI_API_KEY` (se comparan huellas), no hay ejecución de pago (`missing_key` o `shared_key`). **El proyecto lo crea el propietario:** el código no puede ver la credencial de n8n |
| «Responses API con salida estructurada estricta» | Nadie en el repo usaba la Responses API: todo iba por `/v1/chat/completions` | **Adoptado:** cliente propio con `Http::` (sin SDK ni dependencias), `text.format` `json_schema` con `strict: true`, `store: false` siempre (la API guarda la respuesta si se omite), `max_output_tokens` y negativas y respuestas incompletas clasificadas. Los esquemas no usan `maxLength`, `pattern` ni `maxItems`: las cotas las aplica el servidor al leer |
| «Entradas redactadas (`MemoryRedactor`)» | `MemoryRedactor` es código del motor y redacta texto de prospectos | **Cambiado:** el laboratorio **no lee** ningún texto de prospecto. Los escenarios son sintéticos, los escriben personas y un filtro propio **rechaza** (422) correos, teléfonos, documentos e invisibles en todo lo que sale hacia el proveedor. Redactar sería admitir que llegan datos personales. Los nombres no se pueden detectar: un aviso fijo lo pide en el CRM |
| «Registro de modelo, tokens y coste» | ULTRON no registra nada de eso | **Adoptado y ampliado:** por llamada y por ejecución, con la estimación previa, la reserva, el coste real y el exceso sobre la reserva. Es el primer componente del CRM con contabilidad de coste de IA, y no cubre n8n |
| «El resumen visible» de 10 puntos del encargo | — | **Parcial.** La vista de una ejecución enseña, por caso, la respuesta (cómo se expresa), los hallazgos deterministas (qué reglas respeta y qué filtro la marcaría), la opinión de la IA criterio a criterio y regla a regla, los riesgos, el coste, la propuesta y el veredicto; «qué cambió» sale de «Comparar con la línea base» (`GET compare` de G). **No hay** un resumen de 10 puntos como tal, ni apartados de campañas o llamadas: ULTRON solo conversa por WhatsApp en texto y las campañas son del Hito 6 |
| «Tres cosas distintas… un hallazgo determinista crítico bloquea aunque la IA opine a favor… un escenario que solo el modelo describió no cuenta como probado» | — | **Adoptado al pie de la letra.** El juez no recibe los hallazgos deterministas y no puede convertir en «pasa» un caso con un bloqueante; la propuesta no es un veredicto; el modelo nunca escribe escenarios |
| Pregunta abierta: «qué escenarios se ejecutan de verdad contra el motor» | — | **Ninguno en el Hito 4.** El único contacto con el motor son sus guardas puras aplicadas al texto del laboratorio, sin base, log ni estado; el motor no lee nada del laboratorio |
| Lo llamaba «Hito I» | La hoja de ruta lo llama Hito 4 | Hito 4 |

**Lo que se añadió en las revisiones** (Enmienda 4 del contrato):
- el tope por ejecución se aplica también **al coste real**, porque la estimación (3 bytes por token) puede quedarse corta con la entrada;
- un hallazgo bloqueante, `lab-invisibles`, si la respuesta del actor trae caracteres de control o invisibles que podrían ocultar texto a los filtros;
- el veredicto humano y los cuatro ojos, por identidad, respecto al autor de la versión.

## Decisiones pendientes del propietario (antes de la fase 3)

- **Proyecto y clave de OpenAI dedicados** al laboratorio, con presupuesto propio en el panel de OpenAI: es el límite exterior del gasto.
- **Retención:** qué conserva OpenAI con `store: false` en su cuenta. Según la documentación oficial consultada el 19-10-2026, los registros de vigilancia de abusos pueden contener prompts y respuestas y se conservan hasta 30 días por defecto; no conservarlos exige una aprobación previa de OpenAI.
- **Modelo y precios** oficiales. Los del plan (por ejemplo, unos US$ 0,40 y 1,60 por millón de tokens de entrada y de salida para un modelo «mini») son de terceros y solo orientativos. Con modelos de razonamiento, `max_output_tokens` incluye el razonamiento; que rechacen `temperature` es una **suposición** que se comprueba con el modelo elegido.
- **Topes** cuando llegue la clave: propuestos US$ 2 al día, US$ 0,50 por ejecución, 20 ejecuciones al día y 12 casos por ejecución (máximo duro, 20).

## Lo que sigue igual en el motor

- ULTRON razona en **n8n** con cinco nodos de OpenAI (3 llamadas por turno, 5 si el Critic tumba el borrador). Modelo, parámetros, esquema y credencial **no están en el repositorio**.
- En Laravel, el cerebro legado usa Chat Completions con `json_object` (no `json_schema`), `gpt-4.1-mini` por defecto, 20 s, 1 reintento y `fail_closed`.
- **ULTRON sigue sin registrar tokens, modelo ni coste**, y n8n no tiene cortacircuitos ni límite de coste. La clave del motor (`OPENAI_API_KEY`) se comparte con IRON IA y el límite TPM es de la organización (auditoría del 17-10-2026). El laboratorio no cambia nada de esto: no toca el motor ni n8n.

## Diseño previsto el 17-10-2026 (Hito 1, histórico)

Se conserva tal como se escribió, antes de implementarlo.
- Un job fuera del camino caliente, con clave y proyecto propios para no competir con ULTRON ni con IRON IA.
- Responses API con salida estructurada estricta (`json_schema`, `strict`): el veredicto es un objeto con campos fijos, nunca texto libre interpretado.
- Entradas redactadas (`MemoryRedactor`): nunca datos personales de prospectos.
- Registro de modelo, tokens y coste por evaluación.
- El resumen visible (10 puntos del encargo §17): cómo se comportará, cómo se expresará, qué estrategia usará, qué reglas respetará, qué campañas, cómo se comportarán las llamadas, qué cambió, riesgos, pruebas aprobadas y si requiere revisión humana.
- Tres cosas distintas y así etiquetadas: pruebas técnicas (deterministas), evaluación de IA (opinión) y aprobación humana. El modelo no puede autorizar una publicación, y un hallazgo determinista crítico bloquea aunque la IA opine a favor. Un escenario que solo el modelo describió no cuenta como probado.
- Preguntas abiertas: clave y proyecto separados (y verificar el límite TPM vigente); qué escenarios del laboratorio se ejecutan de verdad contra el motor y cuáles solo contra la versión en borrador.

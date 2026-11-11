# Prueba de aceptación A (Studio → WhatsApp) · preparación técnica

**Fecha:** 11-11-2026, 19:15–19:30 UTC. **Quién:** el lead, con la cuenta técnica temporal 32 «Studio smoke (temporal)» (abierta con `lab-tecnica-cuenta.sh abrir`, cerrada al terminar; sin sesión viva). **Dónde:** producción (API `2687119`), por la misma API que usa el CRM.

Esta parte es la del **autor**. La aprobación, el veredicto del laboratorio y la publicación son de una persona que no sea la autora (cuatro ojos por cuenta): no se hicieron aquí.

## Antes de crear nada (solo lectura)

| Comprobación | Resultado |
|---|---|
| Enlaces de pago de ULTRON (`marketing.ultron.payment_links_enabled`) | `false` (la suposición de la auditoría A, confirmada) |
| Freno de ULTRON (`ultron:abort`, sin opciones) | suelto |
| Cadena de publicación (`ultron-studio:publication-check`) | vacía; el motor lee Legacy; cuadra |
| Laboratorio de texto | encendido, `openai`, tope de US$ 2,00 al día y US$ 0,50 por ejecución, 11 escenarios activos |
| Versión aprobada que ya existía (`ad1f1562…`, autora «Desarrolladores») | idéntica a la línea base en los cuatro parámetros gobernados (tono cercano, emojis sí, 320, objetivo cita): publicarla no cambiaría nada (`baseline: true`, huella `3f6a5674`). Por eso la prueba necesita una versión nueva |
| Borrador de la cuenta técnica | era la línea base (`isBaseline: true`, sin diferencias de configuración con la versión aprobada) |

## Lo creado

| Qué | Dato |
|---|---|
| Versión | **«Prueba de aceptación · profesional 200»**, uuid `4017639c-75bf-4f0a-8461-57479478a532`, huella de contenido `24b29173`, autora «Studio smoke (temporal)». Cambia solo `persona.tone` (cercano → profesional) y `persona.maxChars` (320 → 200) |
| Validación | publicable, sin bloqueantes (los dos avisos opcionales de siempre: E14 y E16) |
| Estado | enviada a revisión (`REVIEW_REQUIRED`) |
| Vista previa de publicar (servidor de producción) | `effect = prompts`, `noEffect = []`, tono y longitud en la clase `prompt`, **huella de los prompts `702890f7`**, `baseline: false`, 113 672 bytes, compilador 1, base `n8n-29de5119`. Requisitos vistos por la autora: falta aprobar (`status`), cuatro ojos y el veredicto del laboratorio; el canario del motor, el freno, la ventana, la integridad y la revalidación, en regla |
| Ejecución del laboratorio sobre la versión | `f5361d87-1f19-4124-afca-f9a7d05b60bf`: 11 casos, **7 `review` y 4 `fail`**, US$ 0,0799 (estimado máximo 0,1903), huella de resultados `639a816e` |
| Ejecución de referencia sobre la línea base (mismos 11 escenarios) | `7d0d104e-fd3c-4a12-ab52-182f99ce55a1`: 1 `pass`, 6 `review` y 4 `fail`, US$ 0,0835 |

**Los cuatro casos que fallan son los mismos en la versión y en la línea base** («Ya pagué» sin pago registrado, «Pide hablar con un asesor», «Duda sin pedir a nadie» y «Quiere ir a conocer mañana»). Son del actor de la simulación frente a los criterios de esos escenarios, no del cambio de tono y longitud. El laboratorio es una simulación, no ULTRON: en WhatsApp el traspaso a una persona, los pagos y las citas pasan además por las guardas del flujo y del servidor (R-04, R-06 y R-07), que la simulación no ejecuta. Cómo responde ULTRON de verdad se ve en el turno real del paso 5.

## Lo que falta, y de quién es

1. **Aprobar** la versión (cuenta distinta de la autora, con `ultron.approve`).
2. **Veredicto «Aprobada»** sobre la ejecución `f5361d87…`, reconociendo los 11 casos (cuenta distinta de la autora de la versión).
3. **Publicar en canario** entre las 07:00 y las 19:00 de Bogotá (cuenta distinta de la autora, con `ultron.publish`). La vista previa tiene que enseñar `702890f7`.
4. Dos o tres mensajes desde el teléfono canario.
5. El lead comprueba: `php artisan ultron:prompt-trace --last=5` → `applied`, la versión, observada `702890f7`, copia S1, ningún `mismatch` ni `legacy_drift`.
6. **Revertir** con motivo → el turno siguiente vuelve a `legacy` con la huella `3f6a5674`.

Es una prueba técnica en la conversación canaria. No equivale a una aprobación comercial independiente.

# Informe del reviewer · laboratorio de texto, apartado 3b (`d6330d56..143c91d0`)

Fecha: 2026-11-12. Revisado: `git diff d6330d56 143c91d0 -- backend/app backend/tests` (ya desplegado). Marcas: **[C]** comprobado ejecutando · **[L]** leído · **[S]** supuesto. No lancé ninguna batería (indicación del lead).

## Veredicto

**APROBADO CON CONDICIONES.** No hay nada bloqueante: el cambio solo toca los textos del laboratorio, no empeora
producción y no hace falso ningún `fail`. La condición es de lectura, no de código: escribir la Enmienda 8 en el
contrato H4. Era la condición 2 del informe `a3` y sigue abierta (`grep "Enmienda 8"` en
`ULTRON_STUDIO_H4_CONTRACT.md` no encuentra nada [C]). Ahora tiene que incluir también C-02 c2, C-03 c1/c2 y
C-07 c2: un `pass` ahí mide la conducta que el actor trae dada, no la versión. Que `.6` diera 10/1/0 tanto en la
versión como en la línea base es justo lo esperable.

## Bloqueantes

Ninguno.

## Respuestas

**1. Palabras prohibidas.** `OutboundContentGuard::inspect():668` aplica `DISCOUNT_PATTERN` al texto en bruto,
sin `normalize()`, y con `/iu`, que no quita tildes [L]. Pasé cada forma por la regex real [C]:
bloquea «gratis», «sin costo», «2x1», «precio especial», «descuento», «rebaja» y «promocion» (sin tilde);
**no bloquea** «promoción» (con tilde), «descuentos», «rebajas», «promociones» ni «sin costos»; **bloquea y falta
en la lista** «te lo dejo en», «mitad de precio» y «N % menos / off / de descuento».

No falsea nada. Evitar «promoción» es más estricto que el motor, así que no puede dar un `fail` falso; y un
«te lo dejo en {{PLAN_PRICE}}» sería un `fail` verdadero, igual que en producción, aunque puede hacer inestable
C-02/C-03. Lo único inexacto es decir que «promoción» se rechaza «en cualquier frase».

**2. `HANDOFF_FACTS[true]` es fiel** [L]. En `UltronCommitService:448-464,537`, con la petición corroborada, el
mismo commit que publica la respuesta autoriza `HUMAN_HANDOFF` (`needs_human`) y filtra la respuesta con
`handoffAllowed=true`. Encaja con `composer.txt:14` y con `strategist.txt:82`.

**3. Juez, reglas 3 y 4: ningún hueco nuevo** [L]. La 3 se limita al plan y su precio («no tengo ese dato» sigue
valiendo en C-09 y C-12). La 4 recoge las sugerencias del `a3` y cierra aquel riesgo de R-11. Sigue el de antes:
con R-09 en `not_applicable`, un marcador del plan equivocado no se detecta.

**4. El tope de 0,011 US$ conserva los dientes** [L; la ejecutó el lead]. Con los precios del fixture (0,40 y
1,60 US$/Mtok), el actor real del caso 1 cuesta ≈0,0086 US$, más el juez. La prueba sigue exigiendo tres cosas:
lanzamiento aceptado (estimación ≤ tope), `overrun_micros > 0`, y `budget_exhausted` con el caso 2 `skipped`.
Sin el exceso, lo comprometido sería la estimación, que cabe en el tope, y la ejecución no se pararía; la prueba
sigue distinguiendo. Es frágil: habrá que volver a subir el tope cada vez que crezcan las instrucciones.

**5. ¿La letra de algún criterio?** La prueba de cinco palabras pasa, pero hay paráfrasis que son el examen [L]:
la conducta (a) es C-07 c2 («Ayudar a decidir con una pregunta sencilla o una explicación breve»); el punto 2
nombra las cuatro palabras de C-03 c1; «respondes con esos marcadores» es C-02 c2. No falsean el resultado (el
motor fija esas conductas para cualquier versión); por eso piden la condición del veredicto.

## Pendientes (no bloquean)

- [C] **Producción, anterior a este rango → backend + security:** `DISCOUNT_PATTERN` no normaliza, así que
  «Tenemos una promoción…» o «descuentos» pasan el filtro y solo los frena el prompt del critic. Corrección:
  regex sobre `normalize($body)` y plurales (`descuentos?`, `rebajas?`, `promocion(es)?`), y la misma corrección
  en la copia de `StudioVoiceTemplate:319`.
- [C] Punto 2 del actor: «expresiones» en vez de «palabras», añadir «te lo dejo en» y «mitad de precio», y no
  afirmar el rechazo de «promoción» con tilde hasta arreglar el motor. Requiere una `PROMPT_VERSION` nueva.
- [L] `UltronStudioLabRunnerTest`: calcular el tope a partir de `estimate.maxMicros` en vez de fijarlo a mano.
- [L] `StudioLabEngineChecksTest`: la frase nueva del traspaso no se prueba con `handoffRequested=false` (es el
  hueco de la guarda ya señalado en el `a3`).
- [S] C-02 c3 (`should`) sigue en `review` en la versión y en la línea base: no depende de la versión.

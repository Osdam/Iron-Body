# Informe del reviewer · laboratorio de texto, apartado 3 (commit `5595fd78`)

Fecha: 2026-11-12. Revisado: `git show 5595fd78` (encima de `41d75b61`), sin subir ni desplegar.
Marcas: **[C]** comprobado ejecutando · **[L]** leído · **[S]** supuesto.

## Veredicto

**APROBADO CON CONDICIONES.** No encontré nada bloqueante: el cambio no empeora producción ni falsea el
resultado del laboratorio. **Se puede subir y desplegar hoy**, con dos condiciones que no tocan código:

1. Antes de desplegar, confirmar que no hay ninguna ejecución del laboratorio en `queued`/`running` en
   producción. El runner no vuelve a comprobar `prompt_version` [L: `StudioLabRunner`/`StudioLabStepJob` no lo
   leen]. Una ejecución a medias seguiría con los prompts nuevos, pero quedaría etiquetada `2026-10-19.1`.
   Para la puerta no hay daño, porque esa ejecución contaría como `stale_prompts`; el problema es que sus
   resultados mezclarían las dos versiones.
2. Escribir en el contrato la Enmienda 8, que el código cita y todavía no existe: `ULTRON_STUDIO_H4_CONTRACT.md`
   llega hasta la Enmienda 7 [L], y la lista del actor de §A4 no recoge las cuatro conductas. Tiene que decir
   lo que señalo en el punto 3: en C-04, C-06, C-07 y C-08, un `pass` comprueba la conducta fija del motor y
   **no** la versión. Hay que escribirla antes de tomar como evidencia la ejecución de aceptación.

Pruebas que ejecuté: `StudioLabPromptsTest` + `StudioLabEngineChecksTest` → OK (22 pruebas, 567 aserciones)
[C]. No lancé la batería completa porque así me lo pediste.

## Bloqueantes

Ninguno.

## Respuestas a lo pedido

**1. Regla nueva del juez (`not_applicable`).** No hay ningún escenario del catálogo en que una R-xx que debía
juzgarse pase a `not_applicable` [L, uno por uno]:
- R-04/C-04 y R-07/C-06/C-07: el dato está en «hechos».
- R-05/C-12: «hechos» dice expresamente que no hay datos de membresía; además lo cubren el criterio `must_not`
  y `MembershipFactGuard`.
- R-01, R-02, R-03, R-06, R-08 y R-10: se deciden con el texto o la conversación, y la mayoría también tiene
  guarda determinista.
- R-09 (a qué plan corresponde el marcador), R-11 y R-12: el laboratorio no los simula; `not_applicable` es lo
  honesto.

La agregación no cambia: `not_applicable` es neutro [L, `StudioLabOutcome:100-109`].

Riesgo residual [S]: un E-NNN con «no me escriban más» en la conversación. La cláusula «y que no está en la
conversación» obliga a juzgar R-11, pero el ejemplo literal «si la persona pidió antes no recibir mensajes»
puede llevar al modelo a ponerla en `not_applicable`. Si lo hace, el caso pasaría de `fail` a `pass`. Ese
escenario ya es irrealizable en el laboratorio (por eso se retiró C-11 en la Enmienda 6.1), así que lo dejo
como pendiente. Con las S-NNN, lo que antes quedaba en `unclear` y daba `review` ahora queda en
`not_applicable` y da `pass` solo cuando la regla depende de datos que no se simulan. La configuración sí se
simula, así que una S-NNN de configuración se sigue juzgando.

**2. ¿Las conductas contradicen algo?** No.
- Las cuatro reflejan conductas que el motor fija en código: `appointments` y `handoffOnlyCorroborated` son
  `binding('fixed', …)` con «cortesía como solicitud, nunca cita» y «solo deriva si está corroborado»
  [L, `StudioCatalog:708,717`].
- Coinciden con `composer.txt` (orden reconocer→resolver→avanzar, traspaso en una frase sin tiempos ni
  nombres, cortesía nunca «agendada/confirmada/reservada», «todavía no me aparece confirmado… unos minutos…
  sin capturas») y con `strategist.txt` 100-105 [L].
- La conducta (d) es más estricta que el motor, porque prohíbe también «registrada». Es correcto: en el
  laboratorio no se registra ninguna solicitud y `courtesyRegistrationIn` lo bloquearía.
- `persona.maxChars` 200: las frases de las conductas (a)/(b) caben en 97-173 caracteres [C]. Pasarse da
  `lab-longitud` = warning, no fail.

Pasé 28 frases por las siete guardas reales [C]:
- **Limpias:** «todavía no me aparece confirmado…», «el equipo te confirma la visita», «una persona del equipo
  te la confirma», «tiene que confirmarla», «en unos minutos te confirmo», «continuará la conversación».
- **Bloqueadas, aunque el modelo las escribiría obedeciendo:** «apenas se refleje **te aviso**»
  (`motor-promesa`), «**anoto** tu solicitud» y «**queda registrada** tu solicitud» (`motor-promesa`), y
  «una persona del equipo **te va a escribir** para confirmar tu visita»
  (`machine_reply_unauthorized_handoff`).

Que se bloqueen es correcto, porque el motor también las bloquearía y el `fail` es verdadero. El efecto es que
C-04 y C-08 pueden salir inestables de una ejecución a otra; no falsea nada (queda como pendiente).

**3. ¿Le enseña el examen al actor?** En la redacción, **sí**:
- C-08 c2 aparece casi literal en la conducta (d), con 8 de sus 13 palabras seguidas [C].
- C-06 c1 y C-07 c1 comparten fragmentos de 4 palabras [C].
- C-04 c1/c2/c3 y C-08 c1 están parafraseados en (b) y (d) [L].

Aun así, **no invalida lo que mide el laboratorio**. Esas conductas no dependen de la versión, ni en el motor
ni en el catálogo (son `fixed`), así que ninguna versión podía cambiarlas: antes fallaban igual en la versión
y en la línea base, y ahora pasarán igual en las dos. En esos cuatro casos el laboratorio sigue sin
discriminar entre versiones; antes generaba un `fail` falso y ahora no. Lo que depende de la versión (tono,
emojis, longitud, objetivo, S-NNN) se sigue midiendo igual. La condición 2 existe para que nadie lea un
`pass` en esos cuatro casos como evidencia sobre la versión.

**4. `PROMPT_VERSION`.**
- Puerta: `latestFor($version, '2026-11-12.1')`. Con 0 veredictos en producción no se pierde nada; los
  veredictos futuros sobre las 3 ejecuciones antiguas quedarían en `stale_prompts`, que falla cerrado [L,
  `StudioPublications:1350`, `StudioLabVerdicts:126-150`].
- `StudioLabRuns` solo expone y graba la constante [L].
- Coste: las instrucciones crecen en 1 846 bytes (actor +1 164, juez +682) [C], unos +615 tokens de entrada
  por caso con `BYTES_PER_TOKEN` 3, ≈ +6,8 k tokens en 11 casos. No compruebo el margen frente a `runCapMicros`
  de producción [S].
- Nadie más fija `2026-10-19.1` en el código, salvo el feature test actualizado [L].

**5. Pruebas.**
- La huella fija cada texto, así que cualquier mutación de las instrucciones o de `HANDOFF_FACTS` hace fallar
  la prueba. Los literales nuevos son redundantes con la huella, pero no hacen daño.
- **Mutaciones que sobreviven:**
  - (a) Pegar un criterio literal en `ACTOR_INSTRUCTIONS`, actualizando la huella. La prueba «sin examen»
    (`StudioLabPromptsTest:136-142`) solo mira `actorInput` de C-04, no las instrucciones.
  - (b) Cambiar una conducta para empujar al actor a «te aviso» o «te escribirán». La prueba nueva usa frases
    escritas a mano, no derivadas de las instrucciones.
  - (c) El par del traspaso no discrimina: «Claro, una persona del equipo continúa la conversación contigo.»
    **también pasa limpia con `handoffRequested=false`** [C]. Que la guarda ignore `handoffRequested` en esa
    frase no rompería la prueba (solo la frena el negativo «Te paso con una asesora»).

## Pendientes (no bloquean)

- [C] Hueco en la guarda (anterior al commit, afecta a producción → **security/backend**): «una persona del
  equipo continúa la conversación (contigo)» sin traspaso autorizado no la marca `OutboundContentGuard` y sale
  limpia. En el laboratorio la recoge el juez (R-07/C-07 c1); en producción solo la frena el prompt.
- [C] (b) y (d) no prohíben «te aviso», «anoto» ni «alguien te escribirá»: añadir «sin prometer avisar ni que
  alguien te escriba» para que C-04 y C-08 no salgan inestables (requiere `PROMPT_VERSION` nueva).
- [S] Juez, regla 4: reformular el ejemplo de R-11 como «si pidió no recibir mensajes en otra conversación o
  con otro número», y añadir «configuración» a la lista de dónde puede estar el dato.
- [S] Juez, regla 3: «que no dé un dato que no puede tener» podría aprobar en C-02 c2 un «no tengo el precio»
  aunque existe `{{PLAN_PRICE}}`; vigilarlo en la ejecución de aceptación.
- [L] Con `not_applicable`, `reason` va vacío: no se distingue «no lo toca» de «no se simula». A quien emite el
  veredicto le serviría el motivo.
- [L] Prueba: extender «sin examen» a `ACTOR_INSTRUCTIONS` frente a todos los criterios del catálogo, y
  parafrasear C-08 c2 en (d).
- [L] Prueba: probar la frase del traspaso también con `handoffRequested=false` cuando se cierre el hueco de la
  guarda.

# Plan del arquitecto · Cierre V2, apartado 4: el tono (H4-9), «un solo plan» (H4-10) y los huecos que quedan

**Fecha:** 18-11-2026. **Autor:** `architect`, solo lectura: no he tocado código, pruebas, producción ni n8n; este informe es el único fichero escrito. **Base:** `feat/ultron-studio-h9` = `origin/main` = `938c6180` [C]. Rutas relativas a `backend/`.
**Marcas:** **[C]** comprobado ejecutando (grep o recuento sobre el árbol) · **[L]** leído en el código o en un documento · **[S]** supuesto, sin verificar.

---

## 1. Veredicto

1. **H4-9 tiene una causa clara.** El parche «profesional» es una sola frase frente a unos 40 ejemplos de tuteo del redactor, que no contienen ni un «usted». Además, al revisor solo se le dice que tutear «es defecto de naturalness», y esa es una dimensión que puntúa, no una que haga fallar. Por eso aprueba borradores que tutean.
2. **Recomiendo (a)+(b)+(c) juntos.** El tono va firmado en el token (`sp.t`); un detector determinista del tuteo actúa en el commit y solo con «profesional». Llega el compilador 4, con un revisor que sí hace fallar el tuteo. Los respaldos de Laravel tienen variantes «de usted» en el lado de ULTRON. Si se aplica solo una de las tres piezas, el resultado sigue tuteando.
3. **H4-10 no tiene arreglo en el camino del modelo.** Un borrador solo puede cotizar el precio de un plan, porque los marcadores se resuelven contra un único `Plan`. Ante «¿qué planes tienen?», cuando además pide precio, debe salir **siempre** la lista determinista. Sin petición de precio, se exigen al menos dos planes nombrados; si no los hay, sale la lista.
4. **Lo demás del apartado 4 está casi todo hecho**: autenticidad, traza, reversión, fallo seguro, longitud, frases compiladas y la alerta de `mismatch`. Lo que falta es el tono, el panorama de planes, una prueba real de las frases prohibidas y la promoción más allá de una conversación. La promoción es otro proyecto (L) y necesita una decisión de negocio.
5. **Coste de la siguiente tanda (P1+P2): M**, unos dos días con revisiones. Hay un punto de no retorno: el compilador 4 deja la versión 2 en Legacy al desplegar y hay que volver a publicarla con el circuito humano completo.

---

## 2. P1 · H4-9: que el tono publicado se cumpla

### 2.1 Causa (con evidencia)

| # | Hecho | Peso |
|---|---|---|
| C1 | `composer.txt:42` (COMO SUENAS) da como «español colombiano natural» las expresiones «Listo», «De una», «Tranquilo» y **«Te entiendo»**, y como palabras de la casa **«te acompanamos», «tu proceso», «tu objetivo»** [L]. El turno 3 abrió literalmente con «Te entiendo, quieres saber…». | Alto |
| C2 | El prompt base tutea en bloque: hay 40 citas con tuteo en `composer.txt`, 37 en `composer_retry.txt`, 21 en `critic.txt` y 21 en `critic_retry.txt`, y **0** apariciones de «usted» en los cinco [C, por grep]. El parche se inserta **después** de la línea 42 (`UltronPromptCompiler.php:531`) y dice «manda sobre los ejemplos **de arriba**» (`:219`) [L]. Los ejemplos que vienen debajo (por ejemplo, `composer.txt:68`, «te paso el link cuando me digas es correcto») no quedan cubiertos por esa frase. | Alto |
| C3 | El revisor no puede hacer fallar el tuteo. `T_TONE_PRO_CRITIC` (`:221`) solo dice «tutear… SÍ es defecto de naturalness». `naturalness` es una dimensión que puntúa (`critic.txt:8`) y no aparece en la lista «FALLA si» (`critic.txt:34`). Además, `critic.txt:3` ordena «si el borrador cumple, aprueba… rechazar tiene un coste real» [L]. Resultado: turno 2, pass 0,9 con `issues` [naturalness, novelty]; turno 3, pass 0,95 sin `issues` (dictamen, §2). | Alto |
| C4 | Laravel le pasa al modelo la pregunta sugerida, ya redactada de tú (`StrategyContract.php:106-118`: «seria tu primera vez entrenando o ya tienes experiencia?», «que dias o en que franja te queda mejor entrenar?»), y `composer.txt:5` dice «usa suggested_question… dila con tus palabras» [L]. En el turno 2 el modelo la pasó a usted, así que no es la causa principal, pero empuja en la misma dirección. | Medio |
| C5 | `knowledge_base.tone` es contenido de la base de datos (categoría `tone`, `MarketingKnowledgeBaseService.php:29`) [L]. No he leído su texto: no sé si tutea [S]. Lo mismo vale para las piezas de la ficha que Laravel pega en los respaldos. | Desconocido |
| C6 | Nada lo comprueba en Laravel. `turnoDelStudio()` solo devuelve `studio` y `tope` (`UltronCommitService.php:5049-5063`, `TURNO_LEGACY` en `:142`) [L]. El tono no viaja en el token. | — |

**Causa más probable:** C3 + C1/C2. El modelo deriva hacia el tuteo, porque el prompt está escrito así, y el único filtro que hay no está autorizado a hacerlo fallar. C4 y C5 suman.

### 2.2 Diseño recomendado: tres piezas que van juntas

**(a) Comprobación determinista en el commit, solo con «profesional».**

- **Firma.** `UltronPromptOffer` añade `sp.t` = `persona.tone` firmado. Solo va en modo `studio` y solo si no es la línea base (`cercano`, `UltronPromptCompiler.php:124-130`), con la misma pauta que `sp.n` (`UltronPromptOffer.php:197-201`). `turnoDelStudio()` pasa a devolver `tono` (`profesional`|`entusiasta`|null), y `TURNO_LEGACY` gana `'tono' => null`. Un valor fuera del catálogo se ignora con un aviso, como el tope. Nada cambia en la respuesta a n8n.
- **Detector.** Clase pura nueva, `UltronRegisterDetector::tuteo(string): list<string>`, que devuelve las marcas encontradas (como mucho 5). Reglas:
  1. Antes de mirar, quita las URL, los `{{…}}`, lo que va entre comillas («…», "…", “…”) y los nombres de plan del catálogo (`CommercialTurnPolicy::plansNamedIn`, `:768`).
  2. Pasa el texto a minúsculas **conservando las tildes**. No usa `SalesAgentDecisionSchema::normalize()`, que las quita, porque «estás» y «estas» no son lo mismo.
  3. Trocea por palabras con `[a-záéíóúñü]+`, sin `\p{…}`, que el PCRE de producción no admite (`UltronCommitService.php:5007`) [L].
  4. **Lista cerrada de marcas.** Pronombres: `tú tu tus te ti contigo tuyo tuya tuyos tuyas`. Imperativos con enclítico: `cuéntame dime dímelo escríbeme avísame pregúntame cuéntanos escríbenos avísanos dinos`. Verbos de tú: `quieres puedes tienes necesitas buscas prefieres vienes estás sabes vas eres empiezas arrancas entrenas animas alcanzaste viniste quisieras podrías`.
  5. **Nunca marca** «su»/«sus» (es tercera persona o usted, ambiguo), «usted», ni «te» dentro de otra palabra (la comparación es por palabra entera: «este», «parte», «gente»), ni «té», ni «estas»/«esta» sin tilde.
- **Qué hace el commit** (entre `:1596` y `:1598`, después del recorte por longitud y antes de `violations()`, solo si `tono === 'profesional'`):
  1. Si todas las marcas están en la **pregunta final**, quita esa pregunta con la misma regla de A41-4 (la que ya aprobó el propietario) y vuelve a mirar.
  2. Si siguen quedando marcas, añade la violación `policy_register_mismatch`. El borrador entra por la rama de la política que ya existe (`:1633`) y sale el respaldo de su intención, escrito de usted gracias a (c).
  3. **No se reescribe texto ni se pide otro intento:** el reintento ya lo hace n8n cuando el revisor (b) falla.
  4. La traza va en `metadata.register` (aditiva): `{expected:'usted', found:[…], action:'ok'|'trimmed'|'discarded'}`. Sin texto de la persona.
- **Los otros tonos:** con `entusiasta` y `cercano` no se comprueba nada; los dos tutean, como la línea base. El detector de ustedeo no hace falta.

**(b) Compilador 4.**

- Cambia **solo** los dos textos del tono profesional; el resto, igual.
- El texto del redactor declara que manda sobre **todo** el prompt (también sobre los ejemplos de abajo, `suggested_question` y `approved_brand_copy`) y da las equivalencias.
- El texto del revisor convierte el tuteo y la mezcla en un **fallo** con `notes`: así se provoca el reintento de n8n, que es el único sitio donde puede haber «otro intento».
- **Además** (es código, no prompt): con `profesional`, `StrategyContract::preguntaAdaptativa` devuelve las cinco preguntas de usted. Eso cambia el contenido de `decide` solo en los turnos `studio`+`profesional`.
- **Consecuencia:** cambian las huellas de todo paquete con `tone = profesional`. La versión 2 (`af9e0d23…`) cae a `legacy · compiler_changed` al desplegar (mismo mecanismo que A41-8) y **hay que volver a publicarla**. La línea base y la huella Legacy `3f6a5674…` no se mueven. `MAX_PATCH_BYTES = 1200` (`:189`): los textos propuestos caben.

**(c) Respaldos de usted en el lado de ULTRON.**

- **Mecanismo.** Una clase pura nueva, `UltronFormalReplies`, con las variantes. El commit la usa en lugar de `$this->replies->replyFor/welcomeReplies/lastResorts`, y los textos propios (memoria, ficha, política y acta) ganan un parámetro `$tono`. Todo ello **solo con `tono === 'profesional'`**; con cualquier otro valor, la llamada sigue siendo exactamente la de hoy. `SalesConversationReplyService.php` **no se toca**.
- Los textos que llevan datos (franja, horario, día de la visita, dirección) se arman con los mismos datos y la variante de usted.
- **Última red, en `antesDeEnviar()`.** Si con «profesional» un texto de Laravel sigue tuteando, el motivo será contenido de la base de datos (C5): se anota `metadata.register.fallback_mismatch` y un aviso en el log, **y sale igual** (antes que dejar el turno mudo).
- **¿Neutros o de usted?** Recomiendo **de usted**. Los neutros en español fuerzan construcciones impersonales que suenan a máquina, y la única pareja que se aparta de la línea base es «profesional». El respaldo de precios ya es neutro (`:5206-5207`) y se queda como está.
- **Cuántos son.** Las variantes completas propuestas son **36** (§2.3). Faltan por enumerar unos **25 fragmentos** [S, estimado con grep: 54 literales que tutean en `SalesConversationReplyService.php`, 8 en `SalesObjectionResponderService.php`, 10 en `CommercialTurnPolicy.php`, 7 en `CourtesyRequestService.php` y 24 en `UltronCommitService.php`; hay solapes y trozos concatenados]. Son `paymentPendingReply()` (`SalesConversationReplyService.php:177`), las 5 respuestas a objeciones, `planReply`/`courtesyReply`/`courtesyResumption` (`CommercialTurnPolicy.php:688`, `:895`, `:913`) y el acta de la visita (`CourtesyRequestService`).
- **Inventario completo.** Lo genera y lo fija una prueba, como `a4-textos-ajustados.md`: fichero nuevo `plans/informes-motores/a4-textos-usted.md` con original → variante, que el propietario aprueba de una vez.

**Coste y falsos positivos.** El detector es S y el commit, S. El compilador 4 es S en código y M en revisión, porque cambian las huellas y hay que volver a publicar. Las variantes son M por la cantidad y la aprobación.

| Falso positivo | Tratamiento |
|---|---|
| Comillas con lo que dijo la persona | Se quitan antes de mirar |
| Un nombre de plan con «tu» | Se quitan los nombres del catálogo |
| La ficha de la base de datos tutea | Solo afecta a los respaldos, y ahí solo se anota |
| «te» dentro de otra palabra, «té», «su» de tercera persona | No se marcan, por construcción |

Hay un falso negativo aceptado: los verbos de tú que no están en la lista cerrada. Es preferible a tumbar mensajes correctos.

**Efecto esperado sobre la prueba del 18-11** [S, aplicando el diseño a los textos reales]:

| Turno | Qué pasaría |
|---|---|
| 1 | Sale como salió (es de usted) |
| 2 | «tus ejercicios» está en el cuerpo: se descarta y, al ser una pregunta de precios, sale la lista determinista neutra (que además resuelve H4-10) |
| 3 | «Te entiendo, quieres» está en el cuerpo: se descarta y sale el horario de usted, «Claro. Lunes a viernes… ¿En qué franja le queda mejor entrenar?» |

El compilador 4 debería bajar esa tasa de descartes, porque el revisor provoca el reintento en n8n antes de llegar al commit.

### 2.3 Textos para aprobar (los aprueba el propietario; texto exacto)

**Para el modelo (compilador 4).** Los prompts van sin tildes, como la base.

- `T_TONE_PRO_COMPOSER`:
  `REGISTRO PUBLICADO: profesional. Esto manda sobre TODO este prompt, tambien sobre los ejemplos de abajo, suggested_question y approved_brand_copy: trata a la persona de usted en cada frase, sin mezclar. Los ejemplos se dicen de usted: "Te entiendo" es "Le entiendo", "tu objetivo" es "su objetivo", "te paso" es "le paso", "cuentame" es "cuenteme", "quieres" es "quiere". Nunca "te", "tu", "tus", "ti" ni "contigo". Frases completas y sobrias, sin muletillas coloquiales ("De una", "Listo", "Tranquilo") ni exclamaciones seguidas. Sigue siendo calido; profesional no es frio. El backend comprueba el registro antes de enviar y descarta el mensaje que tutee.`
- `T_TONE_PRO_CRITIC`:
  `REGISTRO PUBLICADO: profesional. Tratar de usted NO es defecto. Si el borrador tutea en cualquier frase o mezcla tu y usted ("te", "tu", "tus", "ti", "contigo", "quieres", "puedes", "cuentame", "dime"), verdict fail con naturalness y notes "pasar todo el mensaje a usted". Lo que va entre comillas citando a la persona no cuenta.`

**Pregunta sugerida con «profesional»** (sustituye a las de `StrategyContract.php:108`, `:110`, `:111`, `:115`, `:118`, por ese orden):
`viene buscando mantener su rendimiento o cambiar su composicion corporal?` · `que busca lograr al empezar: bajar grasa, ganar masa o coger el habito?` · `seria su primera vez entrenando o ya tiene experiencia?` · `seria su primera vez entrenando o ya viene con experiencia?` · `que dias o en que franja le queda mejor entrenar?`

**Para el cliente (36 variantes).** `{franja}`, `{líneas}`, `{dirección}` y `{día}` son los mismos datos que hoy. He quitado «Listo», «Tranquilo» y «De una», que el propio parche profesional prohíbe.

*Bienvenida* (`welcomeReplies`, `SalesConversationReplyService.php:373`):

| # | Texto |
|---|---|
| W1 | `Hola, muy {franja}. Bienvenido a Iron Body, qué gusto atenderle. Cuénteme, ¿en qué le podemos ayudar hoy?` |
| W2 | `Hola, {franja}. Qué gusto tenerle por aquí: bienvenido a Iron Body. Cuénteme, ¿en qué le ayudo hoy?` |
| W3 | `Muy {franja}, bienvenido a Iron Body. Cuénteme qué necesita y le acompaño.` |
| W4 | `Hola de nuevo, muy {franja}. Cuénteme, ¿en qué le podemos ayudar hoy?` |
| W5 | `Hola, aquí seguimos. Cuénteme qué necesita y le acompaño.` |
| W6 | `Muy {franja}. Dígame en qué le ayudo hoy.` |

*Horario* (`scheduleReply`, `:400`):

| # | Texto |
|---|---|
| S1 | `Claro. {líneas} ¿En qué franja le queda mejor entrenar?` |
| S2 | `No tengo un horario general confirmado en mi información, y prefiero no darle uno equivocado. Si quiere, pregúnteme por una clase en concreto y le digo el día y la hora que tengo registrados; también le ayudo con planes o ubicación.` |

*Por intención* (`replyFor`, `:48-141`):

| Intención | Texto |
|---|---|
| GREETING | `Hola, bienvenido a Iron Body, qué gusto atenderle. Cuénteme, ¿en qué le podemos ayudar hoy?` |
| PRICING_QUESTION (solo si no hay lista de precios) | `Sí, claro. Cuénteme qué busca —bajar grasa, ganar masa o coger el hábito— y le digo el plan que mejor le encaja y su precio.` |
| GOAL_FAT_LOSS | `Muy bien. Para bajar grasa lo más importante es empezar con algo que pueda sostener. ¿Ya viene entrenando o empieza desde cero?` |
| GOAL_MUSCLE_GAIN | `Perfecto. Para ganar masa lo clave es entrenar constante y con buena guía. ¿Ya ha entrenado antes o está empezando?` |
| GOAL_RECOMPOSITION | `Eso se puede trabajar como recomposición corporal: ganar músculo y bajar grasa poco a poco. Lo clave es hacerlo con constancia y buena guía. ¿Ya ha entrenado antes o está empezando?` |
| LOCATION_QUESTION | `Estamos en {dirección}. ¿Va a venir por primera vez?` |
| GENERAL_INFO | `Con gusto le cuento. Iron Body es un gimnasio en Neiva donde se entrena con acompañamiento y un espacio preparado para avanzar en su objetivo, sea principiante o ya venga entrenando. ¿Qué le cuento más a fondo: los planes, las clases o cómo empezar?` |
| THANKS | `Con gusto. Si después quiere empezar o comparar planes, me escribe y le ayudo sin problema.` |
| NOT_INTERESTED | `Entendido, no hay problema. Si después se anima o solo quiere resolver dudas, aquí le ayudamos.` |
| BOT_QUESTION | `Soy el asistente automático de Iron Body y le puedo ayudar con información inicial. Si prefiere hablar con una persona del equipo, solo dígamelo.` |
| HUMAN_REQUEST | `Claro, dejo marcada su solicitud para que alguien del equipo la revise. Igual sigo por aquí si quiere que le ayude con precios, ubicación o planes.` |
| COMPLAINT | `Entiendo. Lo dejo marcado como caso para revisión del equipo. Cuénteme qué pasó exactamente y lo atendemos.` |
| INVOICE_REQUEST | `Claro. Para factura necesito que el equipo confirme los datos correctos para no cometer errores. Le dejo marcada la solicitud de facturación. Si quiere, también puedo ayudarle con información de planes o ubicación.` |
| MEDICAL_RISK_ESCALATION | `Gracias por contarlo. Con una lesión es mejor no recomendar ejercicios específicos por aquí para no darle una orientación irresponsable. Le dejo marcado que necesita revisión del equipo, y mientras tanto puedo ayudarle con información general de planes o ubicación.` |
| FRAUD_OR_PAYMENT_CLAIM | `Entiendo, lo dejo marcado para que el equipo revise su caso de pago con cuidado. La membresía solo queda activa cuando el pago está confirmado. ¿Le ayudo con algo más mientras tanto?` |
| GOODBYE | `Con gusto. Si más adelante quiere empezar o resolver dudas, me escribe y le ayudo.` |
| (resto de intenciones) | `No quiero responderle cualquier cosa. ¿Se refiere a los planes, horarios o ubicación?` |

*Últimos recursos* (`lastResorts`, `:439`). Los dos «sensibles» ya son neutros y no cambian.

| # | Texto |
|---|---|
| U1 | `Claro, con gusto le ayudo. Cuénteme, ¿en qué le ayudo hoy?` |
| U2 | `Con gusto. ¿Qué le cuento primero del gimnasio?` |
| U3 | `Cuénteme qué busca y le oriento con lo que tenemos.` |
| U4 (precios) | `Con gusto le paso la información de los planes. Dígame si empieza este mes o está comparando opciones.` |
| U5 (ubicación) | `Con gusto le ayudo con la ubicación y cómo llegar. Dígame si necesita también horarios o planes.` |

*Memoria de la visita* (`UltronCommitService.php:4903-4912`):

| # | Texto |
|---|---|
| M1 | `No tengo registrada una visita suya. Dígame el día y la hora que le sirven y la dejo solicitada.` |
| M2 | `Su visita estaba para el {día}. Si no alcanzó a venir, dígame qué otro día y hora le sirven y la dejo solicitada.` |
| M3 | `Claro. El equipo ya confirmó su visita: {día}. Le esperamos.` |
| M4 | `Claro. Su visita de cortesía quedó solicitada para el {día}; el equipo la revisa para tenerlo todo listo.` |

*Ficha del gimnasio* (`:4580`, `:4584`): apertura `Con gusto le cuento.` y cierre `¿Qué le cuento más a fondo: los planes, las clases o cómo empezar?`.

**Quedan por redactar unos 25 fragmentos**, con la misma regla: pago pendiente, las 5 objeciones, plan, visita, retomar la visita y el acta. Los enumera `backend` en `a4-textos-usted.md`, que una prueba deja fijado, y el propietario lo aprueba entero.

---

## 3. P2 · H4-10: «¿qué planes tienen?» → más de un plan

**Hecho clave [L]:** un borrador del modelo solo puede citar **un** precio. `{{PLAN_PRICE}}` se resuelve contra un único `Plan` (`UltronDraftPlaceholders.php:33`; `resolve($…, $plan)` en `UltronCommitService.php:5168`), y cualquier cifra escrita por el modelo se rechaza. A «¿qué planes tienen **y cuánto cuestan**?» el modelo no puede contestar con más de un precio, por mucho que se cambie el estratega.

**Reconocimiento.** Función pura nueva `CommercialTurnPolicy::pideElPanorama(string $inbound, array $policy, array $resolution): bool` sobre el texto normalizado. Es verdadera si:

- el texto pide planes en plural o el panorama: `\b(que|cuales)\s+(planes|membresias|opciones)\b`, `\b(planes|membresias)\s+(tienen|hay|manejan|ofrecen)\b`, `\bque\s+ofrecen\b`, `\b(los|todos\s+los)\s+planes\b`. Es el subconjunto en plural de `PIDE_RECOMENDACION` (`:179`) [L];
- **y no** se da ninguna de estas condiciones: `hard_required_plan !== null` con un solo plan nombrado (`:283-286`, `:504`); la resolución es `CHOOSE_PLAN` (la persona elige entre opciones ya dadas); `reception_mode`, `memory_mode` o `goal_takes_turn`; o el texto pide un filtro («para estudiantes», «para parejas», «por días») [S: lista por cerrar con el corpus de pruebas].

**Qué se exige** (en el commit, después de `violations()` en `:1598`, **solo con `studio`**; `decide()` no cambia):

| Caso | Regla | → Recomendación |
|---|---|---|
| Panorama **y** pide precio (`PREGUNTA_PRECIO`, `:115`, o intención de precios) | El modelo no puede cumplir: siempre la lista | Violación `policy_plans_overview` → `respaldoDePrecios()` |
| Panorama sin precio | El borrador nombra al menos 2 planes vendibles distintos (`plansNamedIn`, `:768`); si no, violación | Se queda el texto del modelo si cumple; si no, la lista |

- **Por qué una violación y no «siempre la lista».** La violación reutiliza toda la maquinaria que ya existe: la traza `policy_violations`, el borrador descartado, `$plan = null` y la memoria de lo que se envió (`:1751`). Además conserva la respuesta del modelo cuando cumple. En el caso con precio el resultado es el mismo que preferir siempre la lista.
- **Cambios en el commit.** Las condiciones de `:1629` (calcular `$precios`) y de `:4869` (`respaldoPorIntencion`) pasan de «intención = precios» a «intención = precios **o** violación de panorama». En el camino del revisor caído (`safeFallback`, `:2032-2034`), lo mismo con `pideElPanorama`.
- **No rompe «¿cuánto cuesta el Mensual?»:** un plan nombrado no es panorama, sigue `named_by_person` y el texto del modelo con su precio. «¿Cuánto cuesta el Mensual y qué otros planes hay?» sí es panorama, y la lista ya pone primero el plan nombrado (`:5149`).
- **«La mensualidad»** en singular no es panorama: sigue un plan, y cuál decide H4-8.
- **No choca con «no más de tres» del revisor** (`critic.txt:19`): la regla pide **al menos** dos, y la lista ya tiene un tope de tres (`:5151`).
- **Fichero de la política.** `CommercialTurnPolicy` solo lo usan clases de ULTRON y `StudioCatalog` [C, grep], no el agente Legacy. Aun así, la función nueva es pura y no cambia `decide()` ni `violations()`.

---

## 4. P3 · Tabla de huecos del «trabajo técnico» del apartado 4

**Imprescindible (★)** = sin esto no se puede decir «el Studio gobierna WhatsApp en las conversaciones autorizadas». La columna «Permiso» indica si hace falta una decisión o una acción del propietario.

| Punto | Estado hoy | Dónde | Qué falta | Tamaño | ★ | Permiso |
|---|---|---|---|---|---|---|
| Compilación de las capas aplicables | PARCIAL | Gobernados: tono, emojis, longitud, objetivo y frases (`UltronPromptCompiler.php:102-118`); el resto, `must_match`/declarado/sin efecto (auditoría §3.1) | Nada de código para las aplicables. Hay que **decir** en el contrato qué capas no aplican a WhatsApp y por qué (`intent.fallback`, contexto, memoria, prioridad de oferta) | S (documento) | ★ | No |
| Contrato Laravel → n8n | HECHO Y PROBADO | `decide` ofrece, selector S1, huella ofrecida = observada (dictamen §1) | — | — | ★ | — |
| Autenticidad e integridad de los prompts | HECHO Y PROBADO | `sp` firmado; cadena inmutable (V9); huella `af9e0d23` en los tres turnos | — | — | ★ | — |
| Resolución de conflictos | PARCIAL | Implícita: el código (R-xx y política) manda sobre la versión, y la versión sobre el prompt base. El parche solo cubre «los ejemplos de arriba» (C2) | Escribir la precedencia en el contrato y fijarla con una prueba (el compilador 4 corrige el texto del tono) | S | ★ | No |
| Políticas no negociables | PARCIAL | Cerrojos R-01, R-02 y R-07 al validar; guardas en el código | H4-6: `OutboundContentGuard` no normaliza «promoción» ni los plurales, y no marca la oferta de traspaso. **El guard es compartido con Legacy**: va aparte, con su propia instantánea | M | — | Sí (toca Legacy) |
| Reglas comerciales | PARCIAL | `CommercialTurnPolicy`; `strategy.offerPriority` sin efecto (manda el módulo de Planes) | H4-10 (P2); H4-8 (dato del catálogo) | M | ★ | H4-8: sí |
| Tono y longitud | Longitud HECHO Y PROBADO; tono PARCIAL | H4-1/H4-3 cerrados; H4-9 abierto | P1 | M | ★ | Textos |
| Emojis | HECHO SIN PRUEBA REAL | Gobernado por prompt (`:227-229`); sin comprobación en el commit | La misma pieza (a) con `sp.e` y un detector de emojis, si el propietario la quiere | S | — | Decisión |
| Objetivos comerciales | HECHO SIN PRUEBA REAL | `strategy.goal` → Strategist (`:243-245`); la versión 2 no lo cambió [S] | Una prueba real con `venta` o `informar` | S (prueba) | — | Prueba real |
| Contexto | HECHO (declarado, fijo = 10) | auditoría §3.1 | Nada: no es gobernable por diseño. Decirlo en el CRM | — | — | — |
| Memoria permitida | HECHO (declarado, fijo) | `memory.useLeadProfile`; `MemoryRedactor` | Ídem | — | — | — |
| Información faltante | NO EXISTE como gobierno | W1/INC-6: «Si no entiende» no aplica a WhatsApp (R-07) | Decidir «no aplicable» y decirlo, o gobernar solo «preguntar» | S (texto) | — | Decisión |
| Solicitudes de atención humana | HECHO Y PROBADO en el código (no por el Studio) | R-07, `HumanHandoffAuthority` | El hueco de H4-6 (ver arriba) | — | ★ (ya está) | — |
| Exclusiones | PARCIAL | DNC, `do_not_sell`, Legacy para todos los demás | No hay lista de exclusión por versión. Para el canario individual no hace falta; para el grupo controlado, sí | M | — | Decisión |
| Caché e invalidación | HECHO Y PROBADO | Sin caché: la cadena se lee en cada turno (`UltronPublishedConfig`, sin `Cache::` [C]); `compiler_changed` | — | — | ★ | — |
| Registro de la versión efectiva | HECHO Y PROBADO | `metadata.studio`, `studio_prompts`, `length`; `ultron:prompt-trace` | Añadir `metadata.register` (P1) | S | ★ | — |
| Conversaciones en curso | HECHO Y PROBADO | El `sp` firmado del turno manda; no hay carrera con una reversión (A41-2) | — | — | ★ | — |
| Vuelta atrás (rollback) | HECHO Y PROBADO en real (12-11) | `StudioPublications`, `UltronStudioPublicationRollbackTest` | — | — | ★ | — |
| Fallo seguro | HECHO Y PROBADO | Cualquier error de la oferta → Legacy; alerta M1-A, ya implementada (`MonitorCommand.php:68`, `prompts`) | — | — | ★ | — |
| Frases prohibidas | HECHO SIN PRUEBA REAL | Compilador ≥ 2: `rules.forbiddenPhrases` (`:115`, `:250-252`); `StudioRulePhrases`; sin guarda en Laravel (M1-18) | (1) **Revisión** de los textos: `:250` y `:252` dicen «aunque cambien las mayúsculas» pero no hablan de tildes, plurales ni subcadenas, y el revisor puede tumbar una frase legítima que contenga la prohibida (falso positivo). (2) Prueba real con una S-NNN. (3) Opcional: comprobación determinista en el commit con la misma pieza que (a) | S + S + M | ★ (1 y 2) | Prueba real; crear la S-NNN |
| Promoción general | NO EXISTE más allá del canario individual | La llave solo admite `off|canary` (`UltronPromptOffer.php:109-115`); un solo id (`config/marketing.php:83`); el enrutador de ULTRON también es de un id (`MarketingAgentSwitch.php:229`, `UltronCommitService.php:301`, `UltronEventEmitter.php:84`, `SupervisionService.php:95`, `:191`) | Para un grupo controlado: (1) que **ULTRON atienda a más de una conversación** (hoy solo la canaria; es una decisión de negocio, porque deja de atender Legacy); (2) un conjunto o cohorte (por ejemplo, el registro de números canarios C1 o un hash por porcentaje) que lean a la vez el enrutador y `UltronPromptOffer`; (3) que la parte `canary` de la cadena signifique «la cohorte»; (4) la llave `all`; (5) medidas por cohorte en el monitor. La reversión inmediata ya existe | L | — (ampliación) | Sí, de negocio |
| «Sin modificar a mano los cinco prompts» | HECHO Y PROBADO | El compilador genera; n8n acepta cualquier paquete con la huella (A41-8) | — | — | ★ | — |

**Propuesta de olas**

- **Ola A (la siguiente tanda): P1 y P2.**
  - P1 completo: `sp.t`, detector, compilador 4, pregunta sugerida de usted, variantes de usted.
  - P2.
  - `metadata.register`.
  - La **revisión escrita** de los textos de frases prohibidas: es solo un documento y sus correcciones irían en el mismo compilador 4.
  - Cierre: prueba real con la versión vuelta a publicar.
- **Ola B (tras la prueba real de A):**
  - prueba real de frases prohibidas (crear una S-NNN);
  - la precedencia escrita en el contrato;
  - la declaración de las capas no aplicables;
  - emojis y objetivo, con su prueba real;
  - H4-6 como paso aparte, porque toca un guard compartido con Legacy.
- **Ola C (ampliación, con decisión de negocio):** grupo controlado → ampliación progresiva → cobertura general.

Con A y la parte ★ de B cerrada, la afirmación «el Studio gobierna WhatsApp **en las conversaciones autorizadas**» queda sostenida, siendo hoy la única autorizada la canaria.

---

## 5. Pruebas (sobre las clases reales; en rojo antes del cambio y en verde después)

1. **`tests/Unit/Marketing/Ultron/UltronRegisterDetectorTest.php` (pura).** Un corpus de positivos: los tres textos del 18-11, todas las marcas de la lista y una mezcla de tú y usted. Y de negativos: «su»/«sus», «usted», «este/parte/gente», «té», «estas», citas entre comillas, nombres de plan, URL y `{{…}}`. Cada marca y cada exclusión llevan su caso.
2. **`tests/Feature/Marketing/UltronA4RegistroYPanoramaTest.php`** (commit real, PostgreSQL 14 en lo transaccional), con la pauta de `UltronA4LongitudYPreciosTest.php`:
   - registro: «profesional» con un borrador que tutea → descarte y respaldo de usted; con el tuteo solo en la pregunta final → se quita la pregunta; `entusiasta` y `cercano` → no hace nada; un token sin `t` → no hace nada; `metadata.register`;
   - panorama: «¿qué planes tienen y cuánto cuestan?» → lista; «¿qué planes tienen?» con 1 plan → lista y con 2 → modelo; «¿cuánto cuesta el Mensual?» → modelo; «el Mensual y qué otros hay» → lista con el Mensual primero; `CHOOSE_PLAN` → no es panorama; camino del revisor caído.
3. **Completitud de (c).** Con «profesional», todo texto que el commit puede emitir (intenciones × estados, franja, ya saludado, sin ficha) pasa el detector, salvo las piezas de la base de datos. Esta prueba genera `a4-textos-usted.md` y lo deja fijado.
4. **Legacy byte a byte.** La instantánea `legacy-a4.json` (33 turnos × 2 modos) sigue idéntica sin regenerarse, y `sp` sin `t` se trata igual que hoy.
5. **Compilador 4.** `compile(base) = base`; la huella Legacy `3f6a5674…` sin cambios; las huellas de «profesional» cambian (nueva fijada en la prueba); `entusiasta` y `cercano` no cambian; los parches caben en `MAX_PATCH_BYTES`; el comprobador del flujo (`verificar-flujo.cjs`) con el paquete PHP nuevo.
6. **Estructural.** `antesDeEnviar()` sigue siendo el único punto de control antes de `dispatchWhatsapp` (la prueba que ya existe).
7. **Batería completa** en el árbol limpio, con los dos rojos esperados de siempre.

---

## 6. Despliegue, vuelta atrás y qué necesita permiso

- **Despliegue.** `ops/hotfix-backend.sh` con la lista cerrada del diff. Ficheros de producto:
  - nuevos: `UltronRegisterDetector.php` y `UltronFormalReplies.php`;
  - cambiados: `UltronCommitService.php`, `Prompts/UltronPromptOffer.php`, `Prompts/UltronPromptCompiler.php`, `CommercialTurnPolicy.php`, `StrategyContract.php` y `CourtesyRequestService.php` (este último si el acta tutea).
  - Sin migraciones, rutas ni configuración.
  - Hace falta **una enmienda del lead** como A41-10, para que las protecciones 5 a 8 del H9 y la puerta de diff cedan en esos ficheros.
- **Punto de no retorno práctico.** Al desplegar el compilador 4, la versión 2 deja de aplicarse (`legacy · compiler_changed`) y la canaria vuelve a Legacy hasta que se publique otra vez. Volver a publicar exige el circuito humano: dos personas, el veredicto del laboratorio sobre la versión y la ventana horaria [L, auditoría §3.6]. No sé si el sistema deja volver a publicar la misma versión 2 o exige una versión 3 [S]: `backend` debe comprobarlo antes del despliegue.
- **Vuelta atrás.** Volver al commit anterior y `queue:restart`. No hay datos que deshacer (la metadata es aditiva). Si se vuelve atrás después de publicar con el compilador 4, esa publicación cae también a `compiler_changed` y vuelve a Legacy. Es seguro, pero hay que volver a publicar.
- **Necesita permiso o acción del propietario:**
  1. Los textos de §2.3, más los ~25 restantes de `a4-textos-usted.md`.
  2. Comprobar el tono en modo «descartar». Recomendado: sí; la alternativa es «solo anotar».
  3. El despliegue.
  4. Volver a publicar en el canario tras el compilador 4.
  5. La prueba real: 3 a 5 mensajes, incluidos «¿qué planes tienen y cuánto cuestan?» y una pregunta de horario.
  6. H4-8 (qué plan es «la mensualidad» y el orden de la lista), que cambia lo que muestra P2.

---

## 7. Riesgos

- **Más respaldos en la canaria** hasta que el compilador 4 haga efecto. Son correctos, pero menos personalizados. Se mide en la prueba real con `metadata.register`.
- **Falsos positivos del detector** con texto de la base de datos (la ficha y `knowledge_base.tone` [S]). En los respaldos solo se anotan; en los borradores del modelo, el modelo tiene que reformular. La prueba del corpus los fija.
- **La lista del panorama pisa una buena respuesta del modelo** cuando la persona pide planes con un filtro («para estudiantes»). Se mitiga excluyendo los filtros [S, el corpus por cerrar].
- **H4-8 sin decidir:** la lista y «la mensualidad» siguen el orden del catálogo de hoy (Plan Valera primero).
- **Que el compilador 4 se despliegue sin coordinarlo con el propietario** deja la canaria en Legacy sin que nadie lo haya pedido. Hay que avisar antes.
- **Fuera de alcance:** `SalesConversationReplyService.php`, los prompts base y el n8n en marcha no se tocan. El reintento lo dispara el revisor ya existente con el texto nuevo del compilador.

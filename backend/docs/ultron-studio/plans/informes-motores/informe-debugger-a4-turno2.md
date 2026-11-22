# Debugger · Turno 2 de la tercera prueba real (22-11-2026): causa raíz del `critic_failed` (revisado con la adenda)

**Base:** `lote-1` en `45b53023` (lo que sirve producción), solo lectura (`git status` vacío antes y después). Ni ssh, ni modelos, ni n8n. **Marcas:** [C] comprobado ejecutando o leyendo el dato exacto · [L] leído en el código o en un documento · [H] hipótesis.

## Causa raíz (una frase)
**Se sustituyó porque ninguno de los tres borradores cumplía las reglas deterministas de la lista.** El tercero repetía `{{PLAN_3}}` y, expandido, medía 251; el commit lo habría cambiado por la misma lista fija aunque el revisor lo hubiera aprobado [C].
- **Lo estructural:** las notas de reescritura no le dan al modelo un esqueleto ni el presupuesto ya expandido, y el tercer borrador no se comprueba.
- **Lo que agravó la memoria heredada del 18-11** [H, con indicios fuertes]:
  - la fase DISCOVERY llevó a una estrategia de descubrimiento, con su pregunta; los beneficios del Plan Valera (id 3), ya entregados entonces, se copiaron detrás de `{{PLAN_3}}`, que es el Élite (id 8).

## Corrección recomendada (mínima, con pruebas de regresión, sin tocar el revisor ni subir los reintentos)
- **(0)** Repetir la prueba en una canaria limpia, con una comprobación previa de que la memoria está vacía.
- **(1)** Notas de reescritura con un esqueleto y el presupuesto expandido.
- **(2)** La causa determinista del borrador descartado, en la metadata.
- **(3)** Los beneficios junto a cada marcador en `plans_overview`.

Las cuatro son solo backend y ninguna toca la huella de la base, del compilador ni del flujo. Después, si hace falta, (4): el texto del revisor y del estratega en el compilador 5, con su republicación. La (c) no se recomienda (§7).

## Reproducción (sin modelo; sondas en mi scratchpad)
- **Sonda de las clases puras** (`sonda-borrador3.php`, catálogo Z1, `po=[3,4,8]`) [C]:
  - el borrador 3 mide 200 en crudo y 251 expandido; `verify()` = `mixed`; `UltronRegisterDetector::tuteo()` = []; sin la pregunta final mide 211, y sin la segunda mención del plan, 215.
- **El borrador 3 exacto por el commit, con el revisor APROBANDO** (`SondaBorrador3CommitTest::test_sonda_borrador3_aprobado`, «profesional» y 200) [C]:
  - `reply_final` es idéntico a Y1, byte a byte; `policy_violations=["policy_length_exceeded","policy_plans_overview"]`; `plans_overview.detail="mixed"`, longitud de 251 a 199 por sustitución, `register.found=[]`.
- **`decide` en una conversación limpia** (`test_sonda_decide_conversacion_limpia`) [C]:
  - `novelty.must_not_repeat` sale vacío; `suggested_question=null`; `plans_overview` = PLAN_1→id 3 Plan Valera, PLAN_2→id 4 Plan Mensual, PLAN_3→id 8 Élite.
- **Borradores plausibles de una conversación limpia** (`sonda-limpia.php`) [C]:
  - tres planes, un beneficio corto cada uno y una pregunta: 191 expandido; tres planes sin beneficios: 137; dos planes con un beneficio cada uno: 143; Todos pasan `verify`: la lista cabe en 200, pero solo con beneficios de dos o tres palabras.
- **Prueba existente** `test_una_lista_que_no_vale_da_la_lista_fija`: OK (5 tests, 35 assertions) [C].

## A. Qué se debe a la memoria heredada y qué fallaría igual en una conversación nueva
| Hecho del turno 2 | ¿Memoria? | Por qué |
|---|---|---|
| Estrategia `collect_data` / `ask_discovery` y la pregunta «¿Qué busca lograr…?» | **Probablemente sí** [H] | El 18-11 la conversación estaba en NEW_LEAD y la misma pregunta recibió una respuesta directa, sin descubrimiento (`ops/evidencia-h9/2026-11-18/canario-a4/04-mensajes-y-dictamen.txt`, acción #8122). Hoy llega en DISCOVERY y WARM/INTERESTED (acta del turno 1). En limpio, `suggested_question` es null [C, sonda] |
| Los beneficios del borrador 3 («acceso ilimitado, asesoría y seguimiento con entrenador») | **Sí** [C]/[H] | Son casi literales los del Plan Valera entregados el 18-11: memoria H7 y el saliente de #8122, «acceso ilimitado al gimnasio, asesoría semi personalizada y seguimiento en tus ejercicios». Están pegados a `{{PLAN_3}}`, que es el Élite. Si el Élite no tiene esos beneficios, es una atribución equivocada que P-4 no ve, porque es una paráfrasis. Falta leer `plans.benefits` del 8 |
| El revisor: «ya se entregó precio y beneficios del plan 3» | **Sí** [C] | La memoria tiene `price_of_plans [3]` y los dos beneficios. El revisor no recibe `plans_overview` y no puede saber que `{{PLAN_3}}` ≠ id 3 [C]. En el turno 3 el horario ya entregado se repitió y no protestó [C]: apuntaba al «plan 3» |
| El revisor: «no repetir lo ya preguntado» | Probablemente sí [H] | `must_not_repeat.last_agent_question` sale de la memoria (`NoveltyGuard.php:108-113`) |
| Borrador 1 sin lista (`missing`) | **No** (estructural) [L] | La base del redactor exige «el marcador de precio de un plan concreto» (`composer.txt:50`) y el estratega «el PRIMERO de active_plans» (`strategist.txt:19-23`). Ninguna base nombra `plans_overview` (grep: 0) y el parche del motor no llega al estratega |
| Borradores 2 y 3 fuera de las reglas (`mixed`, 251) | **Mixto** | La falta de presupuesto (el modelo no ve precios y los marcadores pasan de 30 a 71 caracteres) y de un esqueleto es estructural [C]. El relleno que los desbordó (los beneficios copiados y la pregunta de descubrimiento) viene de la memoria [H] |
| El revisor: tuteo en una frase de usted | **Estructural**, con posible cebo [H] | «busca» es de usted y el detector da [] [C]. El revisor no recibe la medida del servidor. En `recent_messages` había salientes de tú (18-11 y X7: «recibí tu saludo… acompañarte»), que pueden haberlo cebado |
| El revisor: «tres planes es exceso» | **Estructural** [L] | No recibe `plans_overview` ni `price_asked`; su parche (`UltronPromptCompiler.php:333`) dice lo contrario. Le pesó la estrategia `collect_data` (memoria) |
| Reparto de intentos: tercero sin comprobar, el revisor decide solo | **Estructural** [L] | El plan §3 P-2, l. 79-80 |

**¿Habría pasado el turno 2 en una conversación limpia?**
- **Mi juicio:** más probable que no que sí, con confianza media-baja, cerca de 1 de 3 de que pasara [H]. Se van la pregunta de descubrimiento, los beneficios copiados y dos de los motivos del revisor. Siguen el sesgo del borrador 1 hacia un solo plan y el hecho de que el parche pide «para quién es cada uno» con los beneficios del catálogo, que son largos (28 y 52 caracteres los dos del Valera). Con tres planes, eso se pasa de 200, y las notas no dan el presupuesto ni el esqueleto. Siguen también el tercer borrador sin comprobar y un revisor sin `plans_overview`.
- **Se mide sin tocar producción:** el mismo mensaje, unas 10 veces, en una conversación limpia del laboratorio del Studio (§8).

**Contraste del turno 3** (escrito al primer intento, 168 caracteres, de usted, nota 0,95) [C]. El camino funciona cuando la respuesta es un hecho corto, sin marcadores, que el revisor puede contrastar en `knowledge_base`, y con una estrategia `understand`/`check_in`. El fallo del turno 2 no es un revisor que siempre rechaza: es el camino de la lista con marcadores, al que le falta presupuesto en el redactor y contexto en el revisor.

## 1. El recorrido exacto del turno 2 y lo que recibió el redactor [C, salvo lo marcado]
- **Estratega** → «Validar Estrategia»: `pricing_question`, `collect_data`, `ask_discovery` (Y4).
- **Borrador 1 · «Composer».** La comprobación 1 devuelve `plans_overview/missing` (14:11:24). «Nota del Servidor 1» pasa `critic_issues=["relevance"]` y, en `critic_notes`, N-P1 (`UltronRegenerationNotes.php:104`):
  > «FALTA LA LISTA DE PLANES: … Nombra al menos dos planes de commercial_turn_policy.plans_overview, en su orden, escribiendo solo sus marcadores PLAN_1, PLAN_2 o PLAN_3 entre dobles llaves; el backend pone el nombre y el precio. Sin PLAN_NAME ni PLAN_PRICE.»
- **Borrador 2 · «Composer Reintento».** La comprobación 2 devuelve `mixed` + `length_over_cap` (14:11:26). La nota es N-P2 + N-L (:106 y :102; 428 caracteres juntas):
  > N-P2: «… usa solo PLAN_1, PLAN_2 y PLAN_3 entre dobles llaves, cada uno una sola vez, sin PLAN_NAME, PLAN_PRICE ni PLAN_DURATION.»
  > N-L: «DEMASIADO LARGO: el borrador mide {L} … el limite publicado es 200. Reescribelo en unos 160 … como mucho una pregunta corta al final. Cada marcador cuenta como el texto que lo sustituye.»
- **Borrador 3 · «Composer Reintento 2».** Recibe solo la nota del 2 y pasa directo a «Critic Comercial 3» y a Preparar Commit (conexiones de `flujo-s2.json`), sin comprobación. El revisor da fail con 0,4. El commit mira `isFail` (`UltronCommitService.php:470`) antes de expandir la lista (:678) y entra en `safeFallback` → `respaldoDePrecios` (:2329-2332).
- **¿Bastan las notas para acertar a la primera? No.**
  - **(i) Dicen cuántos planes y qué marcadores, pero no cuánto ocupan:** de 30 caracteres en crudo a 71 expandidos. El modelo nunca ve un precio (`UltronPlansOverview::items()` :222-243). El borrador 3 midió justo 200 en crudo [C]: se ajustó al tope sin expandir [H].
  - **(ii) No hay esqueleto, y las reglas chocan:** se pide decir «para quien es cada uno» (:331) y la base pide «el PORQUE… el {{PLAN_NAME}}» (`composer_retry.txt:4`), pero se prohíben PLAN_NAME, el nombre escrito y repetir un marcador. No se le dice cómo pegar el beneficio en la misma mención.
  - **(iii) La lista cabe en 200 solo con beneficios de dos o tres palabras** (191) [C]; ninguna nota lo dice.

## 2. Los marcadores y lo que habría hecho el commit [C]
- **Cómo se expanden** (`item()` :264-275): «Plan Valera ($65.000 COP)», 25 caracteres; «Plan Mensual ($80.000 COP)», 26; «Élite ($180.000 COP)», 20.
- **El borrador 3 expandido** (251): «Le comento que tenemos el Plan Valera ($65.000 COP), el Plan Mensual ($80.000 COP) y el Élite ($180.000 COP). Por ejemplo, el Élite ($180.000 COP) incluye acceso ilimitado, asesoría y seguimiento con entrenador. ¿Qué busca lograr con su entrenamiento?»
- **Si el revisor lo hubiera aprobado:**
  - la lista no vale: `mixed` por el marcador repetido (`UltronPlansOverview.php:358`);
  - la regla F1 solo puede quitar la pregunta final (no lleva cifras): quedan 211 > 200, así que se descarta (`UltronCommitService.php:1663-1668`, :1761);
  - entra la rama de la política (:1830-1849) y «la lista manda» (:1937-1945);
  - sale el MISMO texto que salió.

## 3. El revisor
- **Lo que le dice su prompt** [L]:
  - el parche del trato (:280) le ordena fallar por tuteo, sin saber que Laravel ya lo mide;
  - la base (:19 y :40) prohíbe más de tres planes, salvo que se pidan todos;
  - el parche del motor (:333) dice que «nombrar dos o tres no es defecto»;
  - el del tope (:300) dice que más de ~200 falla, algo que no puede medir con marcadores;
  - la repetición está en :14 y LOW_NOVELTY en :38;
  - y :3 le dice «segundo intento», cuando era su primera mirada.
- **Lo que recibe** (`values[1]`) [C]:
  - el borrador CON marcadores;
  - `incoming_text` (sí ve la pregunta del cliente);
  - la `strategy`, `active_plans` sin precio, `recent_messages`, `memory` y `novelty`;
  - de `commercial_turn_policy`, solo seis campos: ni `plans_overview` (la correspondencia marcador → id), ni `price_asked`, ni la nota ni las medidas del servidor.
- **Sus cuatro motivos:**
  1. **Tuteo: FALSO** [C].
  2. **La repetición: cierta respecto de la memoria del plan id 3** y real dentro del borrador (`{{PLAN_3}}` dos veces) [C]. Pero el borrador la atribuye al Élite: lo más probable es que el revisor, el redactor o los dos confundieran `PLAN_3` con el id 3 [H].
  3. **«Exceso»: FALSO** ante una petición explícita [L].
  4. **La pregunta: hay una sola** [C]; «lo ya preguntado» sale de la memoria [H].
- **Saldo:** acertó el veredicto (el borrador no era enviable), con dos motivos falsos y dos que dependen de la memoria.

## 4. El reparto de intentos [L]
- **Diseño:** tres borradores en total (`UltronDraftCheck.php:77`, :172-176); el tercero no se comprueba y lo juzga un revisor (plan §3 P-2).
- **Que el primer «no» del revisor vaya al respaldo está diseñado** (§10, condición 1), y el riesgo estaba avisado (`informe-reviewer-a4-regenerar.md:216` y :253).
- **Lo no previsto:** dos devoluciones del servidor que el modelo no podía corregir se gastan el presupuesto, y el revisor juzga un borrador que el servidor tampoco habría dejado pasar.
- **El plan no dice nada** del rechazo en el último intento con todo lo determinista en verde: sale el respaldo (:2206-2224). **No es el caso de hoy:** el borrador 3 fallaba dos reglas deterministas [C].

## 5. El primer borrador (`missing`)
- **Lo que tiene delante:** `plans_overview` con tres marcadores y `price_asked: true` [C; el propio `missing` exige `po` ≠ [], :342-362], `active_plans` sin precio y la estrategia de descubrimiento.
- **Lo que lo empuja a un solo plan:** `composer.txt:50` y :38, `strategist.txt:19-23`; el parche del motor es condicional y no llega al estratega (`enginePatches()` :695).
- **Con la memoria, además:** `novelty.must_not_repeat.price_of_plans=[3]` y «no repitas el precio de un plan ya dado» (`composer.txt:21`) desaniman a nombrar el PLAN_1, que es el id 3 [H]. El texto del borrador 1 no está en la evidencia.

## 6. Factores, por peso
1. **El borrador 3 no valía** (`mixed`, 251): sin esqueleto ni presupuesto expandido [C]; relleno heredado de la memoria [H].
2. **El borrador 1 salió sin lista** por la base de precios de un plan y un estratega que no conoce `plans_overview` [L]: se perdió una de las dos reescrituras.
3. **La memoria del 18-11:** la estrategia de descubrimiento, la copia de beneficios del id 3 y dos motivos del revisor [C]/[H].
4. **La colisión entre `PLAN_k` y el id `k`:** `plans_overview` pone PLAN_1 = id 3, y la memoria, `novelty` y `active_plans` hablan del «plan 3» [C]. Es una trampa de diseño que la memoria activó.
5. **El tercero sin comprobar** y un revisor sin `plans_overview` ni las medidas del servidor [L].
6. **La atribución:** «el revisor rechazó todos los intentos» (`UltronOriginReport.php:461`), cuando fue 1 de 3 y el commit habría sustituido igual [C].

## 7. Correcciones, de la mínima a la mayor (cada una con la regresión que hoy cae y después pasa)
- **(0) Canaria limpia antes de la siguiente prueba.** Es protocolo, no código: una conversación nueva, sin borrar nada. La decide el lead con autorización, porque es configuración de producción. Comprobación previa de solo lectura: la memoria de la canaria tiene vacíos `prices_delivered`, `benefits_delivered`, `facts_delivered` y la bienvenida. Hoy cae (H7) y pasaría con una conversación nueva.
- **(1) Notas con esqueleto y presupuesto expandido** (`UltronRegenerationNotes` N-P1, N-P2 y N-L, con parámetros enteros nuevos; `UltronDraftDefects::of()` los calcula con los `item()` de `UltronDraftCheck::expandido()`).
  - **El texto,** sin poder escribir `{{…}}`: «los marcadores ya ocupan {X}; para el resto, como mucho {R}; una frase con PLAN_1 a PLAN_n, cada uno una vez; el beneficio, de dos o tres palabras y en la misma mención; si no cabe, dos planes; PLAN_3 es una posición, no el plan_id 3».
  - **Huellas:** ninguna. Sí cambian textos fijos que aprueba el propietario.
  - **Riesgo:** `merge()` corta por notas enteras a 500, y n8n otra vez a 500 (`leer-comprobacion-2.js:17`). Hoy suman 428: si crece, se pierde N-L entera. La cifra de caracteres delata el orden de magnitud del precio: `security`.
  - **Regresión** (`UltronDraftDefectsTest` / `UltronCheckEndpointTest`): con el borrador 3, `po=[3,4,8]` y tope 200, las notas llevan X=71 y R=89, nombran cómo poner el beneficio, juntas miden ≤ 500 y conservan los dos códigos. Hoy cae porque no hay cifras; la paridad sigue verde.
  - **Lo que esa prueba no demuestra:** que el modelo obedezca. Eso lo mide el laboratorio.
- **(2) La causa determinista del borrador descartado.** En `handleCriticFailure`, la metadata del borrador descartado con los defectos de `UltronDraftDefects` (solo anota), y el informe de origen separa «revisor» de «revisor + servidor». No cambia lo que sale. Regresión: el commit con el borrador 3 y `critic=fail` deja `["plans_overview/mixed","length_over_cap"]` y el informe no dice «el revisor rechazó todos». Hoy cae porque falta la clave.
- **(3) Los beneficios junto al marcador** en `plans_overview.plans[]` (`UltronPlansOverview::items()` / `decide`, solo Studio): con la posición al lado, el modelo no tiene que pasar de `PLAN_k` a un `plan_id` y de ahí a `active_plans`. No hay exposición nueva (ya viajan en `active_plans`) ni cambia ninguna huella; cambia lo que ve el redactor en el turno de lista. Regresión: `decide` con la pregunta del panorama devuelve en `plans[2]` los beneficios del plan 8. Hoy cae porque la clave no está.
- **(4) Compilador 5** (cambia la huella de todo paquete no base: la versión 3 cae a Legacy hasta republicarla, con gate):
  - **(b1)** El texto del revisor: «su, le, usted y la tercera persona (busca, quiere) son de usted; solo falla por tuteo citando una palabra de la lista»; «si la persona pide planes o precios, dos o tres planes con sus marcadores es lo pedido; la longitud de un borrador con marcadores la mide el backend».
  - **El parche del motor también al estratega:** con `plans_overview`, recomendar y no `ask_discovery`.
  - **Regresión:** las pruebas del compilador exigen esas frases en `critic_retry`, `critic` y `strategist`, y E2 cambia a propósito. Hoy caen.
  - **Lo que protege la guarda del trato:** el detector es una lista cerrada con falsos negativos aceptados (`UltronRegisterDetector.php:34-35`). Por eso (b1) acota al revisor en vez de quitarle el registro.
- **(5) Darle al revisor `plans_overview`, `price_asked` y las medidas del servidor:** cambia su `values[1]`, así que hace falta un flujo S3 (generarlo, comprobarlo, importarlo y publicarlo). Es la mayor. Solo si (1) a (4) no bastan.
- **(c) Que salga el borrador del modelo cuando el revisor rechaza en el último intento y todo lo determinista pasa: NO la recomiendo.** No desactiva al revisor, pero convierte su veto final en un consejo.
  - **Se conserva** todo lo determinista del commit: precios y nombres del catálogo, ninguna cifra inventada, planes vendibles, URL y datos de tarjeta, hechos de pago y de membresía, el registro según el detector, la longitud, las violaciones de política, el filtro de contenido, las promesas (`PromiseAuthority`) y la autorización de relevo. También el `hard_fail` del revisor, si se mantiene como veto.
  - **Se pierde** el último juez de lo que no es determinista: relevancia y empatía, la fuga de relevo en el texto, la mentira de identidad, la presión no literal, los servicios inventados con paráfrasis, la novedad y la referencia, las promesas médicas, y los **beneficios mal atribuidos con paráfrasis, que es justo el riesgo de este turno**. «Revisión humana» llega con el mensaje ya enviado.
  - **Hoy no habría cambiado nada** [C].
  - **Si el propietario la quiere:** solo con `hard_fail` null, issues dentro de {naturalness, progressive_disclosure, question_discipline}, todo lo determinista en verde sobre el texto final, nunca en reclamos, salud o pagos, con `security` y medida aparte. Regresión: el commit con fail en el intento 3 y un borrador válido sale; con `hard_fail`, sigue el respaldo.
- **Descartadas por el propietario:** quitar el revisor y subir los borradores.

## 8. Qué medir en la siguiente prueba real y qué faltaba en el laboratorio
- **La siguiente prueba:**
  - en una canaria limpia, comprobada antes (0); en el turno 2: `plans_overview.valid=true`, `used` ≥ 2, ni `policy_plans_overview` ni `policy_length_exceeded`, `length.to` ≤ 200, `register.found=[]`; quién reescribió y con qué códigos, y la causa determinista si hay respaldo (2); los borradores leídos en la ejecución de n8n.
- **Antes de la canaria,** en el laboratorio del Studio con una conversación limpia: el mismo mensaje unas 10 veces, contando las listas válidas en el 1.º, 2.º y 3.er borrador, y el borrador válido de referencia (137 caracteres) juzgado N veces por el revisor de la v3, para sus falsos rechazos.
- **Faltaban:**
  - la canaria sucia como caso: con la memoria del 18-11 (`price_of_plans=[3]`, los beneficios del 3) y `PLAN_3` = id 8;
  - el borrador 3 real, con pass y con fail del revisor (sale lo mismo);
  - el recorrido servidor + servidor con un tercero inválido, juzgado en el commit;
  - el presupuesto de tres marcadores con los beneficios reales del catálogo.

## Lo que NO pude confirmar, y la lectura que lo confirmaría
- **Los beneficios reales del Élite** (`plans.benefits` del plan 8): dirían si el borrador 3 se los atribuía mal.
- **De la ejecución de n8n 1078446** (con el MCP, solo lectura):
  - los borradores 1 y 2 y la L de N-L; la estrategia completa y `strategy_hints`; el `novelty` (`fresh_candidates`, `last_agent_question`), los `recent_messages` y la `memory` que vio cada nodo, para confirmar la confusión PLAN_3 / id 3 y el cebo del tuteo.
- **El paquete compilado de la versión 39cfae9f** (huella 26d57565): los emojis, el objetivo y las frases. Mis citas asumen «profesional», 200 y el motor.

Sondas (fuera del repositorio): `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body-backend-docs-ultron-studio/f48a668b-9dda-4c1d-9f09-2fcf4f90b894/scratchpad/sonda/` (`sonda-borrador3.php`, `sonda-recorte.php`, `sonda-limpia.php`, `SondaBorrador3CommitTest.php`).


## Nota del lead (22-11-2026, 14:40 UTC): la lectura de producción que pedía el informe

Beneficios del catálogo, leídos en producción en solo lectura (`plans.benefits`):
- **Plan Valera (id 3):** «Acceso ilimitado al gimnasio», «Zona de pesas y maquinaria», «Asesoría semi personalizada con entrenador de planta», «Seguimiento en ejecución de ejercicios», «Valoración inicial»…
- **Plan Mensual (id 4):** los mismos primeros beneficios que el Valera, más los suyos.
- **Élite (id 8):** «Acceso total al gimnasio», «Acceso completo a IRONBODY WORKOUT», «Sesiones funcionales por agenda», «Entrenamiento intensivo y dinámico», «Rutinas guiadas por entrenadores», «Seguimiento continuo durante todo el proceso», «Guía nutricional…»…

Confirmado [C]: el tercer borrador decía que el `{{PLAN_3}}` (el Élite) «incluye acceso ilimitado, asesoría y seguimiento con entrenador», que son beneficios del Plan Valera y del Plan Mensual, no del Élite. La corrección (3) (los beneficios de cada plan junto a su marcador) ataca un riesgo real.

No se leyó la ejecución 1078446 de n8n: leer una ejecución con webhook autenticado enseña el secreto interno en claro. Los borradores 1 y 2 y la estrategia completa quedan sin leer; no cambian la causa raíz.

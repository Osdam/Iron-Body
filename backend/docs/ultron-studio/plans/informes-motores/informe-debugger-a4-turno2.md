# Debugger · Turno 2 de la tercera prueba real (22-11-2026): causa raíz del `critic_failed`

**Base:** árbol `lote-1` en `45b53023` (lo que sirve producción), solo lectura: `git status` vacío antes y después. Ni ssh, ni modelos, ni n8n. Rutas relativas a `backend/` salvo `n8n/…`. **Marcas:** [C] comprobado ejecutando o leyendo el dato exacto · [L] leído en el código o en un documento · [H] hipótesis.

## Causa raíz (una frase)
**Ninguno de los tres borradores cumplía las reglas deterministas de la lista, porque al modelo no se le da con qué cumplirlas a la vez: dos o tres marcadores, cada uno una sola vez, ≤ 200 caracteres YA EXPANDIDOS y sin la pregunta de descubrimiento que le empujan el estratega y las pistas. El tercer borrador, que nadie comprueba, repitió `{{PLAN_3}}` y expandido medía 251. El commit lo habría sustituido por la MISMA lista fija aunque el revisor lo hubiera aprobado [C].** El «no» del revisor no causó la sustitución: solo le puso la etiqueta.

## Corrección recomendada
**(1) + (1b) de §7, solo backend y sin tocar ninguna huella (base, compilador ni flujo):**
- **(1)** Notas de reescritura del servidor con un esqueleto en palabras y el presupuesto ya expandido: los tres marcadores ocupan 71 caracteres y quedan unos 89 de los 160 que se le piden.
- **(1b)** En la fila del respaldo, la causa determinista del borrador descartado, para que el informe de origen no culpe al revisor.

Lo del revisor y la alineación del estratega y del redactor van en la próxima subida del compilador, con su republicación. La (c) no se recomienda.

## Reproducción (sin modelo, en mi scratchpad)
- **Sonda de las clases puras** (`sonda/sonda-borrador3.php`, con el catálogo Z1 y `po=[3,4,8]`) [C]:
  - `select()` = [3,4,8];
  - el borrador 3 en crudo mide **200** y expandido **251**;
  - `verify()` = **`mixed`**;
  - `UltronRegisterDetector::tuteo()` = **[]**;
  - `UltronReplyFitter::draft(…,200)` = null: sin la pregunta final mide 211, y quitando solo la segunda mención del plan, 215.
- **El borrador 3 exacto por el commit, con el revisor APROBANDO** (`phpunit --filter test_sonda_borrador3_aprobado sonda/SondaBorrador3CommitTest.php`, una copia renombrada de `UltronA4ListaDelModeloTest` en el scratchpad, «profesional» y 200) [C]:
  - `reply_final` es idéntico a `PRECIOS_200` y a Y1, byte a byte;
  - `policy_violations=["policy_length_exceeded","policy_plans_overview"]`;
  - `plans_overview={"used":[3,4,8],"valid":false,"detail":"mixed"}`;
  - `length={"from":251,"to":199,"action":"replaced"}`;
  - `register.found=[]`.
- **Prueba existente** `test_una_lista_que_no_vale_da_la_lista_fija` (incluye «un marcador repetido»): OK (5 tests, 35 assertions) [C].

## 1. El recorrido exacto del turno 2 y lo que recibió el redactor
- **Estratega** → «Validar Estrategia»: `pricing_question`, `next_best_action=ask_discovery`, `strategy_goal=collect_data` (Y4) [C].
- **Borrador 1 · «Composer»** (rol `composer`, temperatura 0,7). La comprobación 1 devuelve `plans_overview/missing`, `rewrite` (registro 14:11:24) [C]. «Nota del Servidor 1» pasa `critic_issues=["relevance"]` y `critic_notes` = **N-P1** (`UltronRegenerationNotes.php:104`, con `ENTRIES` en :146) [C]:
  > «FALTA LA LISTA DE PLANES: la persona pidio los planes o los precios en general. Nombra al menos dos planes de commercial_turn_policy.plans_overview, en su orden, escribiendo solo sus marcadores PLAN_1, PLAN_2 o PLAN_3 entre dobles llaves; el backend pone el nombre y el precio. Sin PLAN_NAME ni PLAN_PRICE.»
- **Borrador 2 · «Composer Reintento»** (`composer_retry`, 0,6; `rejected_draft` = el borrador 1). La comprobación 2 devuelve `plans_overview/mixed` + `length_over_cap` (14:11:26) [C]. «Nota del Servidor 2» pasa `issues=["relevance","progressive_disclosure"]` y `notes` = N-P2 + « » + N-L, unidas por `merge()` (:228-243); juntas miden 428 caracteres [C]:
  > N-P2 (:106): «MARCADORES MEZCLADOS: con la lista de planes usa solo PLAN_1, PLAN_2 y PLAN_3 entre dobles llaves, cada uno una sola vez, sin PLAN_NAME, PLAN_PRICE ni PLAN_DURATION.»
  > N-L (:102): «DEMASIADO LARGO: el borrador mide {L} caracteres y el limite publicado es 200. Reescribelo en unos 160: primero la respuesta a lo que pregunto la persona, sin relleno, y como mucho una pregunta corta al final. Cada marcador cuenta como el texto que lo sustituye.»
- **Borrador 3 · «Composer Reintento 2»** (`composer_retry`; `rejected_draft` = el borrador 2; las notas son solo las del 2, no la N-P1).
  - Va directo a «Critic Comercial 3» → Preparar Commit (conexiones de `flujo-s2.json`) [C], sin comprobación, como decide el plan (§3 P-2, l. 79-80) [L].
  - El revisor da fail con 0,4. El commit mira `isFail` (`UltronCommitService.php:470`) ANTES de expandir la lista (:678) y entra en `handleCriticFailure`, `safeFallback` y `respaldoDePrecios` (:2206-2224, :2329-2332) [L]. Y1 es esa lista [C].
- **¿Bastan las notas para acertar a la primera? No.**
  - **(i) Dicen cuántos planes y con qué marcadores, pero no cuánto ocupan.** El modelo nunca ve un precio (`UltronPlansOverview::items()` :222-243), así que no puede calcular «cada marcador cuenta como el texto que lo sustituye». Los tres marcadores son 30 caracteres en crudo y 71 expandidos [C]. El borrador 3 midió exactamente 200 en crudo [C]: se ajustó al tope contando cada marcador como 10 [H].
  - **(ii) No hay esqueleto, y las reglas chocan.** El parche del motor manda «para decir para quien es cada uno usa solo los benefits» (`UltronPromptCompiler.php:331`). La base pide «di el PORQUE… el {{PLAN_NAME}}» (`composer_retry.txt:4`). Las notas prohíben PLAN_NAME, el nombre escrito y repetir un marcador. Lo único que le queda para volver a hablar de un plan es repetir su marcador, que es lo que hizo [L]/[H].
  - **(iii) En 200 no caben tres planes, sus beneficios y una pregunta:** ya en el mejor caso salen 211-215 [C]. Ninguna nota dice «si no caben tres, dos» ni «sin pregunta».
  - **(iv) La nota del tercer borrador no arrastra la del primero** (su `values[1]` toma `critic_notes` de `$json`) [C]. Es un factor menor.

## 2. Los marcadores y lo que habría hecho el commit
- **Cómo se expanden** (`UltronPlansOverview::item()` :264-275 y `expand()` :288-306): «Nombre ($precio COP)» para los planes de 28 a 31 días [L]. Con Z1 [C]:
  - «Plan Valera ($65.000 COP)», 25 caracteres;
  - «Plan Mensual ($80.000 COP)», 26;
  - «Élite ($180.000 COP)», 20.
- **El borrador 3 expandido** (251 caracteres) [C]: «Le comento que tenemos el Plan Valera ($65.000 COP), el Plan Mensual ($80.000 COP) y el Élite ($180.000 COP). Por ejemplo, el Élite ($180.000 COP) incluye acceso ilimitado, asesoría y seguimiento con entrenador. ¿Qué busca lograr con su entrenamiento?»
- **Si el revisor lo hubiera aprobado:**
  - **La lista no vale:** `listaDelModelo()` llama a `verify()`, que da `mixed` porque `{{PLAN_3}}` aparece dos veces (`UltronPlansOverview.php:358`).
  - **No se puede recortar:** la regla F1 solo quita la pregunta final, y solo si no lleva cifras y no es la primera frase (`UltronReplyFitter.php:295-333`). Sin ella mide 211 > 200, así que el borrador se descarta (`UltronCommitService.php:1663-1668`, :1761).
  - **Entra la rama de la política:** `policy_plans_overview` (:1830-1834) y el respaldo de precios (:1846-1849), donde «la lista manda» (:1937-1945).
  - **Al cliente le llega el MISMO texto que llegó** (sonda) [C].

## 3. El revisor
- **Lo que le dice su prompt** (`critic_retry` de la base + parches del compilador 4 para «profesional», 200 y el motor) [L]:
  - **Trato:** el parche de :280 le ordena fallar si el borrador tutea o mezcla («te», «tu», «quieres», «puedes», «cuentame», «dime»). Laravel ya lo mide de forma determinista con el detector, y el revisor ni lo sabe ni recibe esa medida.
  - **Información progresiva:** la base (:19 y :40) prohíbe más de tres planes «sin que la persona pidiera todos». El parche del motor (:333) dice «nombrar dos o tres no es defecto de progressive_disclosure». El del tope (:300) dice «más de ~200 caracteres falla aunque se haya pedido detalle», y eso no lo puede medir sobre un texto con marcadores.
  - **Repetición:** :14 y LOW_NOVELTY en :38.
  - **Y una frase engañosa** en :3: «Este ya es el segundo intento». Aquí era su primera mirada al turno.
- **Lo que recibe** (`values[1]` de «Critic Comercial 3») [C]:
  - el borrador CON marcadores, sin expandir;
  - `incoming_text`: sí ve la pregunta del cliente;
  - la `strategy` entera (ask_discovery, collect_data), `active_plans` sin precio, `recent_messages` y `memory`;
  - de `commercial_turn_policy`, solo seis campos (`active_goal`, `resume_goal_after_answer`, `price_verification`, `reception_mode`, `memory_mode`, `greet_required`). Ni `plans_overview` ni `price_asked`, ni la nota del servidor, ni sus medidas de registro y de longitud.
- **Sus cuatro motivos, uno a uno:**
  1. **«¿Qué busca lograr…?» es tuteo: FALSO.** «busca» es de usted; «buscas» sí está en la lista del detector (`UltronRegisterDetector.php:59-75`), y `tuteo()` = [] sobre el texto expandido [C]. Es casi literal la pregunta sugerida de usted del propio servidor (`StrategyContract.php:145`) [H: no tengo las pistas del turno].
  2. **«Repite el precio y los beneficios del plan 3»: REAL dentro del borrador.** `{{PLAN_3}}` va dos veces: es `mixed`, y expandido diría «Élite ($180.000 COP)» dos veces [C]. Si además se refiere a turnos anteriores, no lo puedo ver [H].
  3. **«Tres planes son exceso de información»: FALSO** frente a su propio parche (:333) y frente a lo que se pidió [L]. Lo probable es que la estrategia diga descubrimiento y que no vea `plans_overview` [H]. El exceso real era la longitud (251), que no puede medir [C].
  4. **«Hace una pregunta abierta»:** hay UNA sola [C]; «lo ya preguntado» depende de `recent_messages` [H].
- **Saldo:** el veredicto acertó por casualidad (el borrador no era enviable). De los cuatro motivos, dos son falsos, uno real y uno sin confirmar: es ruido de gpt-4.1-mini a 0,1 con un contexto que contradice la tarea [H].

## 4. El reparto de intentos
- **Lo que dice el diseño** [L]:
  - tres borradores como mucho (`UltronDraftCheck.php:77` y :172-176);
  - el tercero no se comprueba y siempre lo juzga un revisor (plan §3 P-2);
  - el camino servidor + servidor son 5 llamadas al modelo (`informe-backend-a4-regenerar.md:1138`).
- **Que el primer «no» del revisor vaya al respaldo está DISEÑADO** (§10, condición 1: «el texto fijo solo sale cuando fallan los tres borradores»), y el revisor de la reescritura ya avisó del riesgo (`informe-reviewer-a4-regenerar.md:216` y :253) [L].
- **Lo que no estaba previsto:** que el presupuesto lo gasten dos devoluciones del servidor que el modelo no sabía corregir, y que al final el revisor juzgue un borrador que el servidor tampoco habría dejado pasar.
- **El caso de la pregunta no está en el plan:** no dice nada de un rechazo del revisor en el último intento con todas las comprobaciones deterministas en verde. En ese caso sale el respaldo sin mirar nada más (:2206-2224) [L]. **Este turno NO es ese caso:** el borrador 3 fallaba dos reglas deterministas [C].

## 5. El primer borrador (`plans_overview/missing`)
- **Lo que tiene delante:**
  - `commercial_turn_policy.plans_overview` = {plans: [{marker, plan_id, name, duration_days} × 3], price_asked: true} (`UltronDecideService.php:307-324` y :615-616). Que se ofreció lo prueba el propio `missing`, que solo sale con `po` ≠ [] (`UltronPlansOverview.php:342-362`) [C];
  - `active_plans` sin precio;
  - la estrategia.
- **Todo lo empuja a un solo plan:**
  - la base del redactor: «Si preguntan cuánto cuesta… DEBE llevar el marcador de precio de un plan concreto… Elige el plan» (`composer.txt:50`) y «el precio solo del que se cotiza» (:38);
  - el estratega: «genérica → default_plan_id» y «PLANES → el PRIMERO de active_plans» (`strategist.txt:19-23`);
  - ninguna de las cinco bases menciona `plans_overview` (grep: 0) [C], y el parche del motor no llega al estratega (`enginePatches()` :695) [L];
  - la estrategia del turno pidió descubrimiento (Y4) [C], contra `strategist.txt:65` («responder primero»);
  - el parche de la lista es condicional y va perdido entre unos 27 KB [L].
- **Es el patrón ya medido el 18-11:** un plan con `{{PLAN_PRICE}}` (`informe-reviewer-a4-regenerar.md:216`) [L]. El texto del borrador 1 no está en la evidencia.

## 6. La causa raíz (la frase de arriba) y los factores, por peso
1. **El borrador 3 no valía por reglas deterministas** (`mixed` y 251): las notas no dan esqueleto ni presupuesto expandido, y en 200 no caben tres planes, beneficios y una pregunta [C].
2. **El borrador 1 salió sin lista** por la base de precios de un plan, un estratega que no conoce `plans_overview` y una estrategia de descubrimiento; con eso se perdió una de las dos reescrituras [C]/[L].
3. **El tercer borrador no se comprueba** y el revisor decide solo, sin `plans_overview` y sin las medidas del servidor [C]/[L].
4. **El ruido del revisor** (tuteo, exceso): hoy no cambió el resultado [C]; con un borrador 3 válido, sí lo cambiaría.
5. **La atribución:** `UltronOriginReport.php:461` dice «el revisor rechazó todos los intentos», cuando rechazó 1 de 3 y el commit habría sustituido igual [C]. Empuja a corregir lo que no es, por ejemplo con (c).

## 7. Correcciones, de la mínima a la mayor
- **(1) [RECOMENDADA] La (a): notas con esqueleto y presupuesto expandido.**
  - **Qué toca:** N-P1, N-P2 y N-L de `UltronRegenerationNotes`, con parámetros enteros nuevos ({X} = caracteres de los marcadores expandidos, {R} = M − X), calculados en `UltronDraftDefects::of()` con los `item()` que ya hace `UltronDraftCheck::expandido()`.
  - **El esqueleto, en palabras** (una nota no puede formar `{{…}}`): «una frase con PLAN_1, PLAN_2 y PLAN_3, cada uno una vez; para quién es cada uno, en la misma mención; si no caben tres, dos; una pregunta corta o ninguna».
  - **Huellas:** ninguna. Sí cambian textos fijos que tiene que aprobar el propietario en un gate, y su prueba literal.
  - **Riesgo bajo, con una trampa:** `merge()` corta por notas ENTERAS a 500 caracteres, y n8n vuelve a cortar a 500 (`leer-comprobacion-2.js:17`). Hoy son 428: si crece, se pierde N-L entera. Además, la cifra de caracteres delata el orden de magnitud del precio; que lo mire `security`.
  - **Prueba sin modelo:** unitaria con el borrador 3 y `po=[3,4,8]` (X=71, R=89, notas ≤ 500) y la paridad de la comprobación con el commit en verde, con los mismos códigos.
- **(1b) [RECOMENDADA, junto con la 1] La causa determinista en el respaldo.** En `handleCriticFailure`, anotar en la metadata los defectos deterministas del borrador descartado (las funciones de `UltronDraftDefects`), y que el informe separe «revisor» de «revisor + servidor». Solo backend; no cambia lo que sale. Prueba: convertir la sonda en test.
- **(2) Sin pregunta sugerida cuando hay lista.** `suggested_question` a null cuando `decide` ofrece `plans_overview` (`StrategyContract.php:276`; solo turnos del Studio). Solo backend, sin huellas; quita una pregunta de unos 40 caracteres que no cabe. Riesgo: cambia el turno de precios del Studio. Prueba: `decide` con la pregunta del panorama devuelve las pistas sin pregunta.
- **(3) Compilador 5: alinear al estratega y al redactor.** El parche del motor también al estratega (con `plans_overview`: recomendar, nada de `ask_discovery`) y, en el del redactor, el esqueleto y «cada marcador ocupa unos 25 caracteres». **Cambia la huella de todo paquete no base:** la versión 3 cae a Legacy (`compiler_changed`) hasta republicarla, con gate. Riesgo medio. Prueba: las huellas, E2 y el laboratorio.
- **(4) La (b), en dos formas.**
  - **(b1) Texto del revisor en el compilador 5:** «su, le y usted, y la tercera persona (busca, quiere), son de usted; solo falla por tuteo citando una palabra de la lista»; «si la persona pide planes o precios, dos o tres con sus marcadores es lo pedido; la longitud de un borrador con marcadores la mide el backend».
  - **(b2) Darle al revisor `plans_overview`, `price_asked` y las medidas del servidor:** cambia su `values[1]`, así que hace falta un flujo S3 (generarlo, comprobarlo, importarlo y publicarlo).
  - **Qué protege la guarda actual:** el detector es una lista cerrada con falsos negativos aceptados (`UltronRegisterDetector.php:34-35`), y el revisor caza formas de tú que no están en ella. Quitarle el registro del todo deja pasar esas formas al cliente.
  - **Recomiendo (b1)**, con la cita obligatoria y en la misma subida que la (3). La (b2), ahora no.
- **(5) La (c): NO la recomiendo.**
  - **Hoy no habría cambiado nada:** el borrador 3 fallaba reglas deterministas [C].
  - **Lo que se pierde:** el revisor es la única red de lo que no es determinista o solo lo es a medias: relevancia y empatía, la fuga de relevo en el texto, la mentira de identidad, la presión o la superioridad no literales, los servicios inventados con paráfrasis, la baja novedad, la referencia mal resuelta y las promesas médicas. «Marcado para revisión humana» llega cuando el mensaje ya se envió.
  - **Si el propietario la quiere, solo con estas condiciones:** `hard_fail` null, nota por encima de un umbral, todas las deterministas en verde sobre el texto final, nunca en reclamos, salud o pagos, con `security`, y medida aparte en el informe.
- **(6) Un cuarto borrador o reescribir el tercero: no.** Choca con la condición 2 del propietario (dos reintentos como mucho) y con la latencia. Comprobar el tercero sin reescribirlo solo ahorraría una llamada; la atribución ya la da la (1b).

## 8. Qué medir en la siguiente prueba real y qué faltaba en el laboratorio
- **En el turno 2 de la prueba:**
  - `plans_overview.valid=true` y `used` ≥ 2;
  - ni `policy_plans_overview` ni `policy_length_exceeded`;
  - `length.to` ≤ 200 y `register.found=[]`;
  - las reescrituras, con sus códigos;
  - los borradores 1 a 3 leídos en la ejecución de n8n, para ver si el modelo siguió el esqueleto;
  - si hay respaldo, la causa determinista del borrador descartado (la 1b).
- **Antes de la canaria,** el mismo mensaje unas 10 veces en el laboratorio, contando las listas válidas en el 1.º, 2.º y 3.er borrador.
- **Faltaban, sin modelo:**
  - el borrador 3 real con fail del revisor y con pass (sale lo mismo);
  - el recorrido S2 servidor + servidor con un tercer borrador inválido, juzgado en el commit (el comprobador de S2 no llega al commit);
  - el presupuesto: tres marcadores, beneficios y una pregunta no caben en 200.
- **Con modelo, en el laboratorio del Studio:** el borrador válido de referencia «Claro. Tenemos el {{PLAN_1}}, el {{PLAN_2}} y el {{PLAN_3}}. ¿Cuál le interesa?» (120 caracteres expandido, `verify` null [C]) juzgado N veces por el revisor de la v3, para medir sus falsos rechazos.

## Lo que NO pude confirmar, y la lectura de producción que lo confirmaría
- **De la ejecución de n8n 1078446** (con el MCP, solo lectura):
  - los textos de los borradores 1 y 2, la L de N-L y por qué el 2 dio `mixed`;
  - la estrategia completa y `suggested_question`;
  - los `recent_messages` y la `memory` que vio el revisor, para saber si «ya se entregó» se refiere a la historia.
- **El paquete compilado de la versión 39cfae9f** (huella 26d57565): los emojis, el objetivo y las frases prohibidas. Solo añaden parches en naturalness y en el estratega; mis citas asumen «profesional», 200 y el motor [H hasta leerlo].
- **Los beneficios reales del Élite** (`plans.benefits` del plan 8): dirían si también había `benefit_misattributed`. No cambia el resultado.
- **El catálogo de la sonda** es el de la prueba, con los mismos nombres, precios y `po` que Z1; que `po` era [3,4,8] lo confirma Y1 [C].

Sondas: `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body-backend-docs-ultron-studio/f48a668b-9dda-4c1d-9f09-2fcf4f90b894/scratchpad/sonda/` (`sonda-borrador3.php`, `sonda-recorte.php`, `SondaBorrador3CommitTest.php`). Ninguna toca el repositorio.

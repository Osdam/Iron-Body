# A4 · «Regenerar antes de sustituir» · Los textos fijos nuevos (para aprobar)

Generado por `UltronRegenerarTextosFijosTest::test_los_textos_fijos_de_regenerar_que_aprueba_el_propietario` (no a mano), con las clases reales: `UltronPromptCompiler` (las dos instrucciones del parche del motor), `UltronRegenerationNotes` (las notas de reescritura) y `UltronPlansOverview::item()` (la expansión de la lista). Si un texto cambia en el código y no aquí, la prueba falla: se regenera con `A4_REGENERAR=escribir` y se vuelve a aprobar.

Todo esto existe solo con la versión del Studio aplicada; sin ella (Legacy) no sale ninguno de estos textos. Las instrucciones y las notas son para el modelo (sin tildes, como el resto de los prompts) y la persona no las lee; lo único que lee la persona es la expansión de la lista (§3). Las notas no llevan nada de lo que escribió la persona: solo marcas de una lista cerrada, números y textos del catálogo saneados.

## 1. Las dos instrucciones del parche del motor

Salen en el paquete de prompts de toda versión del Studio que gobierna algún parámetro (con la línea base no hay parches y no salen), al final de los parches. Le enseñan al redactor a escribir la lista de planes con los marcadores, y al revisor a no tumbarla por «no lleva el marcador de precio». La lista solo viene cuando la persona pide los planes o los precios en general.

### Al redactor (`T_PLANS_COMPOSER`)

Va en `composer` y en `composer_retry`, en la línea siguiente a `FORMA:`.

> LISTA DE PLANES DEL BACKEND: si commercial_turn_policy.plans_overview trae planes, la persona pidio los planes o los precios en general y el backend ya eligio cuales mostrar. En ese turno esto manda sobre todo este prompt (las reglas de precios y marcadores, recomendar un solo plan con su PORQUE, resolved_reference y suggested_question) y sobre la estrategia si habla de un solo plan: la respuesta es esa lista, en una frase. Nombra los planes en el orden en que vienen, escribiendo por cada uno SOLO su marcador (PLAN_1, PLAN_2 o PLAN_3 entre dobles llaves), que el backend sustituye por el nombre y el precio reales, seguido de dos o tres palabras de uno de los benefits que trae ese marcador en plans_overview.plans. No escribas sus nombres ni cifras, no uses PLAN_NAME, PLAN_PRICE ni PLAN_DURATION y no repitas un marcador. Fuera de los marcadores, unas 15 palabras en todo el mensaje: un arranque corto, los beneficios y, como mucho, una pregunta corta para elegir (sobre cual amplio la informacion); nunca su experiencia ni su objetivo. Con un tope publicado de menos de 200 caracteres, solo PLAN_1 y PLAN_2. Si plans_overview no viene, nada de esto aplica.

### Al revisor (`T_PLANS_CRITIC`)

Va en `critic` y en `critic_retry`, en la línea siguiente a `progressive_disclosure:`.

> LISTA DE PLANES DEL BACKEND: los marcadores PLAN_1, PLAN_2 y PLAN_3 entre dobles llaves los sustituye el backend por el nombre y el precio reales de planes que el mismo eligio. Cuentan como nombre y como marcador de precio de ese plan: un borrador que los usa para dar varios planes con su precio NO esquiva el precio, no es invented_plan ni invented_price, y nombrar dos o tres no es defecto de progressive_disclosure. Lo demas se juzga como siempre.

## 2. Las notas de reescritura

Las devuelve la comprobación del servidor (`ai/check`) cuando el borrador tiene un defecto que el modelo puede corregir reescribiendo, y n8n se las pasa al redactor en `critic_notes`, como ya hacía con las del revisor. Solo se pide la reescritura si queda presupuesto: como mucho dos reescrituras (tres borradores) y 25 s desde `decide`. Si hay varios defectos, van primero los cuatro de la forma (la lista, el trato, la longitud, el beneficio) y después los de la política, unidas por un espacio y cortadas a 500 caracteres por notas enteras. Si los intentos se agotan, sale el respaldo de siempre.

Entre llaves, en la plantilla, lo que rellena el servidor; debajo, un ejemplo ya relleno.

### 2.1 El trato de usted (`register_tuteo`, `naturalness`)

**Cuándo sale:** con el tono «profesional», cuando el borrador (ya expandido) tutea. Entre paréntesis van las marcas de tuteo que encontró el detector, de su lista cerrada y como mucho cinco.

- **Plantilla:** «REESCRIBE DE USTED: la version publicada trata a la persona de usted y el borrador tutea ({MARCAS}). Pasa todo el mensaje a usted sin mezclar (le, su, usted, cuenteme, quiere) y conserva lo que decia.»
- **Texto** (203 caracteres): «REESCRIBE DE USTED: la version publicada trata a la persona de usted y el borrador tutea (te, quieres). Pasa todo el mensaje a usted sin mezclar (le, su, usted, cuenteme, quiere) y conserva lo que decia.»

### 2.2 La longitud (`length_over_cap`, `progressive_disclosure`)

**Cuándo sale:** con un tope publicado distinto del de la línea base, cuando el borrador expandido pasa del tope (cada marcador cuenta como el texto que lo sustituye). L es su medida, N el tope publicado y M lo que se le pide al redactor (el 80 % del tope, en decenas: 160 con 200).

- **Plantilla:** «DEMASIADO LARGO: el borrador mide {L} caracteres y el limite publicado es {N}. Reescribelo en unos {M}: primero la respuesta a lo que pregunto la persona, sin relleno, y como mucho una pregunta corta al final. Cada marcador cuenta como el texto que lo sustituye.»
- **Texto** (262 caracteres): «DEMASIADO LARGO: el borrador mide 241 caracteres y el limite publicado es 200. Reescribelo en unos 160: primero la respuesta a lo que pregunto la persona, sin relleno, y como mucho una pregunta corta al final. Cada marcador cuenta como el texto que lo sustituye.»

### 2.3 La lista: falta la lista (`plans_overview/missing`, `relevance`)

**Cuándo sale:** en un turno con la lista ofrecida (la persona pidió los planes o los precios en general) y sin herramientas, cuando el borrador no nombra al menos dos planes de la lista con sus marcadores. También si no lleva ninguno aunque nombre un plan a mano (el borrador típico del 18-11, «El Plan Valera cuesta {{PLAN_PRICE}}…»).

- **Texto** (306 caracteres): «FALTA LA LISTA DE PLANES: la persona pidio los planes o los precios en general. Nombra al menos dos planes de commercial_turn_policy.plans_overview, en su orden, escribiendo solo sus marcadores PLAN_1, PLAN_2 o PLAN_3 entre dobles llaves; el backend pone el nombre y el precio. Sin PLAN_NAME ni PLAN_PRICE.»

### 2.4 La lista: marcadores mezclados (`plans_overview/mixed`, `relevance`)

**Cuándo sale:** con la lista, cuando el borrador mezcla sus marcadores con PLAN_NAME, PLAN_PRICE o PLAN_DURATION, o repite uno.

- **Texto** (165 caracteres): «MARCADORES MEZCLADOS: con la lista de planes usa solo PLAN_1, PLAN_2 y PLAN_3 entre dobles llaves, cada uno una sola vez, sin PLAN_NAME, PLAN_PRICE ni PLAN_DURATION.»

### 2.5 La lista: el nombre escrito a mano (`plans_overview/name_written`, `relevance`)

**Cuándo sale:** con la lista y al menos uno de sus marcadores, cuando el borrador escribe además a mano el nombre de un plan de la lista.

- **Texto** (106 caracteres): «NOMBRE DUPLICADO: no escribas el nombre de un plan de la lista; su marcador ya pone el nombre y el precio.»

### 2.6 La lista: un marcador que no existe (`plans_overview/unknown_marker`, `relevance`)

**Cuándo sale:** con la lista, cuando el borrador usa un marcador que no es de ella (PLAN_4 con tres planes, PLAN_0, PLAN_01, plan_1), haya o no herramientas en el turno. K es cuántos planes tiene la lista.

- **Plantilla:** «MARCADOR INEXISTENTE: este turno solo tiene PLAN_1 a PLAN_{K}. Usa solo esos, entre dobles llaves.»
- **Texto** (96 caracteres): «MARCADOR INEXISTENTE: este turno solo tiene PLAN_1 a PLAN_3. Usa solo esos, entre dobles llaves.»

### 2.7 La lista: un marcador sin lista (`plans_overview/no_list`, `relevance`)

**Cuándo sale:** en un turno sin lista (no se ofreció, o es un turno de cobro), cuando el borrador usa PLAN_1, PLAN_2 o PLAN_3.

- **Texto** (128 caracteres): «SIN LISTA DE PLANES: en este turno no uses PLAN_1, PLAN_2 ni PLAN_3. Para un precio usa PLAN_PRICE con el plan de la estrategia.»

### 2.8 La lista: una cifra escrita (`plans_overview/price_written`, `factuality`)

**Cuándo sale:** con la lista, cuando el borrador escribe una cifra o un precio (los pone el backend).

- **Texto** (92 caracteres): «SIN CIFRAS: no escribas precios ni cifras de dinero; el backend los pone con los marcadores.»

### 2.9 El beneficio de otro plan (`benefit_misattributed`, `factuality`)

**Cuándo sale:** cuando una frase del borrador nombra un solo plan y le atribuye un beneficio que en el catálogo es de otro. PLAN es su marcador sin llaves si el borrador lo nombra con él, su nombre del catálogo si lo escribe a mano, o «ese plan» si el nombre queda vacío al sanearlo; BENEFICIO es el texto del catálogo, sin llaves ni saltos de línea y a 60 caracteres.

- **Plantilla:** «BENEFICIO DE OTRO PLAN: el borrador atribuye a {PLAN} un beneficio que en active_plans es de otro plan ("{BENEFICIO}"). A cada plan, solo los benefits de su plan_id.»
- **Texto** (182 caracteres): «BENEFICIO DE OTRO PLAN: el borrador atribuye a PLAN_1 un beneficio que en active_plans es de otro plan ("Entrenador personal dedicado"). A cada plan, solo los benefits de su plan_id.»

### 2.10 La política: el saludo que vende (`policy_greeting_sells`, `customer_fit`)

**Cuándo sale:** cuando la persona solo saludó (modo recepción) y el borrador nombra planes, precios o pagos, o pregunta ya su objetivo.

- **Texto** (173 caracteres): «SALUDO QUE VENDE: la persona solo saludo. Saluda, da la bienvenida y pregunta en que le puede ayudar. No nombres planes, precios ni pagos y no preguntes todavia su objetivo.»

### 2.11 La política: la visita a medias (`policy_active_goal_lost`, `phase_alignment`)

**Cuándo sale:** cuando hay una visita de cortesía a medio concretar, la persona está dando sus datos y el borrador no la retoma (nombra un plan o una cifra).

- **Texto** (209 caracteres): «VISITA A MEDIAS: hay una visita de cortesia a medio concretar y la persona esta dando sus datos. Retoma la visita pidiendo solo el dato que falta (el dia o la hora). No nombres planes ni precios en este turno.»

### 2.12 La política: el primer plan equivocado (`policy_wrong_first_plan`, `phase_alignment`)

**Cuándo sale:** cuando el turno tiene un plan obligatorio (commercial_turn_policy.required_plan_id) y el borrador nombra otro primero, o cuando nombra un plan que la persona rechazó (fuera de commercial_turn_policy.allowed_plan_ids). Las dos claves le llegan al redactor en commercial_turn_policy.

- **Texto** (225 caracteres): «PRIMER PLAN EQUIVOCADO: el primer plan que nombres tiene que ser el de commercial_turn_policy.required_plan_id, y nombra solo planes de commercial_turn_policy.allowed_plan_ids. Los demas permitidos, solo despues y si aportan.»

### 2.13 La política: la información sin el dato (`policy_answer_without_fact`, `relevance`)

**Cuándo sale:** cuando la persona pidió información del gimnasio y el borrador no copia ningún dato de knowledge_base (la dirección, el horario o los servicios).

- **Texto** (254 caracteres): «FALTA EL DATO: la persona pidio informacion del gimnasio y el borrador no da ningun dato de knowledge_base. Responde primero con el dato pedido, copiado tal cual de knowledge_base (direccion, horario o servicios), y deja como mucho una pregunta al final.»

### 2.14 La política: solo preguntas (`policy_general_info_only_discovery`, `relevance`)

**Cuándo sale:** cuando la persona pidió información (también el horario o el precio) y el borrador solo pregunta.

- **Texto** (171 caracteres): «SOLO PREGUNTAS: la persona pidio informacion y el borrador solo pregunta. Responde primero con lo que pidio, con un dato concreto, y deja como mucho una pregunta al final.»

### 2.15 La política: la memoria que vende (`policy_memory_turn_sells`, `customer_fit`)

**Cuándo sale:** cuando la persona preguntó por algo ya acordado (su visita) y el borrador vende o pregunta su objetivo.

- **Texto** (171 caracteres): «PREGUNTA DE MEMORIA: la persona pregunto por algo ya acordado (su visita). Responde solo eso, con el dato del compromiso. No vendas ni preguntes su objetivo en este turno.»

## 2 bis. Con la lista y el tope: el esqueleto y el presupuesto

Cuando el turno tiene la lista de planes Y un tope publicado, tres de las notas de arriba salen en esta variante en lugar de la suya: llevan, en palabras, cómo escribir la lista (una frase con los marcadores, cada uno una vez y con «para quién es» en su mención; si no caben, dos; una pregunta corta o ninguna) y cuánto ocupan los marcadores una vez expandidos. K es cuántos planes tiene la lista; X, lo que miden juntos sus marcadores ya expandidos (el servidor lo calcula con el catálogo de ese momento; el modelo nunca ve un precio); M, lo que se le pide al redactor; R = M − X, lo que le queda para todo lo demás. Sin tope, o sin lista, salen las de arriba tal cual. El código y el `issue` son los de la nota de arriba. El ejemplo es el turno 2 de la prueba real del 22-11 (Plan Valera, Plan Mensual y Élite; tope 200).

### 2 bis.1 La longitud, con la lista (`length_over_cap`, `progressive_disclosure`)

**Cuándo sale:** con la lista y el tope, en lugar de la de la longitud de arriba: cuando el borrador expandido pasa del tope.

- **Plantilla:** «DEMASIADO LARGO: mide {L} caracteres y el limite publicado es {N}. Reescribelo en unos {M}: primero la respuesta, sin relleno. Los marcadores de la lista ya ocupan {X}: quedan {R}. Si no caben, dos planes; una pregunta corta o ninguna.»
- **Texto** (233 caracteres): «DEMASIADO LARGO: mide 251 caracteres y el limite publicado es 200. Reescribelo en unos 160: primero la respuesta, sin relleno. Los marcadores de la lista ya ocupan 71: quedan 89. Si no caben, dos planes; una pregunta corta o ninguna.»

### 2 bis.2 La lista: falta la lista, con el presupuesto (`plans_overview/missing`, `relevance`)

**Cuándo sale:** con la lista y el tope, en lugar de «falta la lista» de arriba.

- **Plantilla:** «FALTA LA LISTA DE PLANES: una frase con PLAN_1 a PLAN_{K} entre dobles llaves, cada uno una vez y, en su mencion, para quien es; sin PLAN_NAME ni PLAN_PRICE. Ya expandidos ocupan {X} caracteres: quedan {R} de {M}. Si no caben, dos; una pregunta corta o ninguna.»
- **Texto** (257 caracteres): «FALTA LA LISTA DE PLANES: una frase con PLAN_1 a PLAN_3 entre dobles llaves, cada uno una vez y, en su mencion, para quien es; sin PLAN_NAME ni PLAN_PRICE. Ya expandidos ocupan 71 caracteres: quedan 89 de 160. Si no caben, dos; una pregunta corta o ninguna.»

### 2 bis.3 La lista: marcador repetido o mezclado, con el presupuesto (`plans_overview/mixed`, `relevance`)

**Cuándo sale:** con la lista y el tope, en lugar de «marcadores mezclados» de arriba: el borrador repite un marcador de la lista o lo mezcla con PLAN_NAME, PLAN_PRICE o PLAN_DURATION.

- **Plantilla:** «MARCADOR REPETIDO O MEZCLADO: una frase con PLAN_1 a PLAN_{K} entre dobles llaves, cada uno una vez y, en su mencion, para quien es; sin PLAN_NAME, PLAN_PRICE ni PLAN_DURATION. Ya expandidos ocupan {X} caracteres: quedan {R} de {M}.»
- **Texto** (228 caracteres): «MARCADOR REPETIDO O MEZCLADO: una frase con PLAN_1 a PLAN_3 entre dobles llaves, cada uno una vez y, en su mencion, para quien es; sin PLAN_NAME, PLAN_PRICE ni PLAN_DURATION. Ya expandidos ocupan 71 caracteres: quedan 89 de 160.»

## 3. La expansión de la lista (lo que lee la persona)

Cuando el borrador nombra los planes de la lista con sus marcadores (`{{PLAN_1}}` a `{{PLAN_3}}`), el commit sustituye cada uno por el nombre y el precio del plan leídos del catálogo en ese momento. Si el plan es de un mes (28 a 31 días), sin duración; si no, con la duración en las palabras de la lista fija («7 días», «tres meses», «un año»…) y «1 día» en singular. El nombre sale sin llaves (si alguien escribe una en el CRM, no se convierte en un marcador), y si lo que sale tiene aún forma de marcador, no sale: va el último recurso de siempre.

- 30 días: «Plan Valera ($65.000 COP)»
- 7 días: «Plan Semana (7 días, $45.000 COP)»
- 1 día: «PLAN DÍA (1 día, $10.000 COP)»

Un borrador entero, «Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}. ¿Cuál le interesa más?», sale así (131 caracteres): «Claro. Tenemos Plan Valera ($65.000 COP), Plan Semana (7 días, $45.000 COP) y PLAN DÍA (1 día, $10.000 COP). ¿Cuál le interesa más?»

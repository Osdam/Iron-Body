# A4 · «Regenerar antes de sustituir» · Los textos fijos nuevos (para aprobar)

Generado por `UltronRegenerarTextosFijosTest::test_los_textos_fijos_de_regenerar_que_aprueba_el_propietario` (no a mano), con las clases reales: `UltronPromptCompiler` (las dos instrucciones del parche del motor), `UltronRegenerationNotes` (las notas de reescritura) y `UltronPlansOverview::item()` (la expansión de la lista). Si un texto cambia en el código y no aquí, la prueba falla: se regenera con `A4_REGENERAR=escribir` y se vuelve a aprobar.

Todo esto existe solo con la versión del Studio aplicada; sin ella (Legacy) no sale ninguno de estos textos. Las instrucciones y las notas son para el modelo (sin tildes, como el resto de los prompts) y la persona no las lee; lo único que lee la persona es la expansión de la lista (§3). Las notas no llevan nada de lo que escribió la persona: solo marcas de una lista cerrada, números y textos del catálogo saneados.

## 1. Las instrucciones del parche del motor

Salen en el paquete de prompts de toda versión del Studio que gobierna algún parámetro (con la línea base no hay parches y no salen), al final de los parches. Con el compilador 8 (PT-17, puerta 2) le dicen al redactor que la lista la escribe el backend: él pone la ranura LISTA_DE_PLANES entre dobles llaves donde va la lista, o nada y el backend la pone antes de su pregunta final; y al revisor, que esa lista no la juzga. La lista solo viene cuando la persona pide los planes o los precios en general.

Desde el compilador 6 (Enmienda A4-6) van al final de cada prompt. En el del redactor, <L> = el tope publicado y nunca menos de 320 (la cifra con la que el servidor mide la respuesta con la lista): con un tope de 200, 320. Desde el compilador 8 es su única cifra.

### Al redactor (`T_PLANS_COMPOSER`)

Va en `composer` y en `composer_retry`, en la línea siguiente a la última de la base (`price_verification true significa…`): la última del prompt.

> LISTA DE PLANES DEL BACKEND: si commercial_turn_policy.plans_overview trae planes, la persona pidio los planes o los precios y el backend ya eligio cuales mostrar y escribe la lista: cada plan de plans_overview.plans, en su orden, con su nombre y su precio oficiales. Tu escribes el resto con tus palabras y pones LISTA_DE_PLANES entre dobles llaves, una sola vez, donde va la lista; si no la pones, el backend la pone antes de tu pregunta final. No escribas nombres de plan, cifras ni marcadores que empiecen por PLAN_. Puedes hablar de los planes sin nombrarlos o por su lugar en la lista (el primero, el segundo, el tercero), con sus benefits. Si pregunto ademas otra cosa, respondela tambien; si pidio una recomendacion, va despues de la lista. Puedes abrir con una frase corta y cerrar con UNA pregunta breve que ayude a elegir o a avanzar; suggested_question no es obligatoria aqui. LONGITUD DE ESTE TURNO: el mensaje completo, ya con la lista, no pasa de <L> caracteres. En este turno esto manda sobre lo anterior que choque (orden del mensaje, recomendar un solo plan, preguntar antes de informar, los marcadores y el tope general). Si plans_overview no viene, nada de esto aplica.

### Al revisor (`T_PLANS_CRITIC`)

Va en `critic` y en `critic_retry`, la última línea del prompt (tras `Si el borrador explica POR QUE la persona vio otro precio…` y la de abajo).

> LISTA DE PLANES DEL BACKEND (va al final porque manda sobre todo lo anterior): si reply_draft lleva LISTA_DE_PLANES, o PLAN_1, PLAN_2 o PLAN_3, entre dobles llaves, o la persona pidio los planes o los precios en general y el borrador no nombra planes ni precios, el backend pone ahi, o antes de la pregunta final, la lista con el nombre y el precio oficiales de cada plan elegido, en su orden. Esa lista no la juzgas: juzga solo la prosa. Cuenta como el precio de cada plan: no es invented_plan ni invented_price, no esquiva el precio y no es plantilla, folleto ni exceso de planes. En ese borrador NO son defecto: no preguntar antes objetivo, experiencia u horario, aunque la fase sea NEW_LEAD o DISCOVERY o la estrategia traiga recommended_plan_id, ni cerrar con una pregunta corta para elegir o sin pregunta. Un nombre de plan o una cifra escritos a mano los rechaza el backend.

### Al revisor, lo que ya comprueba el backend (`T_PLANS_CRITIC_CHECKED`)

Va en `critic` y en `critic_retry`, en la línea siguiente a la última de la base (`Si el borrador explica POR QUE la persona vio otro precio…`), justo antes de la de arriba.

> VEREDICTO EN LA LISTA DE PLANES: si reply_draft entra en LISTA DE PLANES DEL BACKEND (abajo), el registro (tu/usted), la longitud y la lista ya los comprueba el backend de forma determinista: no falles, no bajes naturalness ni los menciones en notes por ellos. En ese borrador es fail con hard_fail solo un incumplimiento real de seguridad, exactitud o politica de HARD_FAIL o de FALLA TAMBIEN (un plan, clase, servicio, beneficio u horario que no esta en active_plans, gym o knowledge_base es invented_class), y fail sin hard_fail solo si deja sin responder una pregunta directa del mensaje. Lo demas es verdict pass, con la mejora en notes.

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

Con los marcadores (compilador 7 o anterior; con el 8, las de §2 ter), cuando el turno tiene la lista de planes Y un tope publicado, tres de las notas de arriba salen en esta variante en lugar de la suya: llevan, en palabras, cómo escribir la lista (una frase con los marcadores, cada uno una vez y con «para quién es» en su mención; si no caben, dos; una pregunta corta o ninguna) y cuánto ocupan los marcadores una vez expandidos. K es cuántos planes tiene la lista; X, lo que miden juntos sus marcadores ya expandidos (el servidor lo calcula con el catálogo de ese momento; el modelo nunca ve un precio); M, lo que se le pide al redactor; R = M − X, lo que le queda para todo lo demás. Sin tope, o sin lista, salen las de arriba tal cual. El código y el `issue` son los de la nota de arriba. El ejemplo es el turno 2 de la prueba real del 22-11 (Plan Valera, Plan Mensual y Élite; tope 200).

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

## 2 ter. Con la lista del servidor (compilador 8)

Con el compilador 8 la lista la compone Laravel (PT-17, puerta 2, L1): el redactor ya no ve marcadores de plan, y las seis notas de la lista y la de la longitud de un mensaje con la lista salen en esta variante en lugar de las de arriba, con el mismo código y el mismo `issue`. Hablan de la ranura LISTA_DE_PLANES (en palabras: una nota no lleva llaves). N es el tope de la lista del turno; X, lo que mide la lista que compone Laravel con el catálogo de ese momento (el modelo nunca ve un precio); R = N − X, lo que le queda a la prosa (nunca menos de 0); L, lo que mide el mensaje con la lista puesta. El ejemplo es el de la prueba real del 22-11 (Plan Valera, Plan Mensual y Élite; tope 200, así que el de la lista es 320): X = 75 y R = 245.

### 2 ter.1 La lista del servidor: falta la lista (`plans_overview/missing`, `relevance`)

**Cuándo sale:** con la lista del servidor, cuando el borrador cotiza un plan con PLAN_NAME, PLAN_PRICE o PLAN_DURATION en vez de dejar la lista, o cuando con los marcadores PLAN_1 a PLAN_3 no pone todos los de la lista.

- **Plantilla:** «FALTA LA LISTA DE PLANES: pon LISTA_DE_PLANES entre dobles llaves una sola vez, donde va la lista; el backend escribe cada plan con su nombre y su precio. Sin nombres de plan, cifras ni marcadores PLAN_. La lista ocupa {X} de {N} caracteres: tu texto, como mucho {R}.»
- **Texto** (266 caracteres): «FALTA LA LISTA DE PLANES: pon LISTA_DE_PLANES entre dobles llaves una sola vez, donde va la lista; el backend escribe cada plan con su nombre y su precio. Sin nombres de plan, cifras ni marcadores PLAN_. La lista ocupa 75 de 320 caracteres: tu texto, como mucho 245.»

### 2 ter.2 La lista del servidor: las dos formas mezcladas (`plans_overview/mixed`, `relevance`)

**Cuándo sale:** con la lista del servidor, cuando el borrador pone la ranura y además marcadores PLAN_, la repite, la junta con PLAN_NAME, PLAN_PRICE o PLAN_DURATION o la escribe sin sus dos llaves; cuando pone los marcadores PLAN_1 a PLAN_3 fuera de su orden o repetidos; o cuando, por lo demás bien, deja restos con forma de marcador (PLAN_1 sin llaves, una sola llave).

- **Plantilla:** «MARCADORES MEZCLADOS: usa solo LISTA_DE_PLANES entre dobles llaves, una sola vez; sin marcadores que empiecen por PLAN_. El backend pone la lista entera, en su orden. La lista ocupa {X} de {N} caracteres: tu texto, como mucho {R}.»
- **Texto** (229 caracteres): «MARCADORES MEZCLADOS: usa solo LISTA_DE_PLANES entre dobles llaves, una sola vez; sin marcadores que empiecen por PLAN_. El backend pone la lista entera, en su orden. La lista ocupa 75 de 320 caracteres: tu texto, como mucho 245.»

### 2 ter.3 La lista del servidor: el nombre escrito a mano (`plans_overview/name_written`, `relevance`)

**Cuándo sale:** con la lista del servidor, cuando el borrador escribe a mano el nombre de un plan de la lista (el backend ya lo pone en la lista, con su precio).

- **Texto** (196 caracteres): «NOMBRE DUPLICADO: no escribas el nombre de ningun plan de la lista; el backend ya lo pone, con su precio, en la lista. Si hablas de uno, nombralo por su lugar (el primero, el segundo, el tercero).»

### 2 ter.4 La lista del servidor: un marcador que no existe (`plans_overview/unknown_marker`, `relevance`)

**Cuándo sale:** con la lista del servidor, cuando el borrador escribe la ranura con otra caja (en minúsculas) o un PLAN_k que no es de la lista.

- **Texto** (142 caracteres): «MARCADOR INEXISTENTE: en este turno la lista se pide con LISTA_DE_PLANES entre dobles llaves, en mayusculas; no uses otros marcadores de plan.»

### 2 ter.5 La lista del servidor: la ranura sin lista (`plans_overview/no_list`, `relevance`)

**Cuándo sale:** con el compilador 8, en un turno sin lista (no se ofreció, o es un turno de cobro), cuando el borrador usa la ranura, una variante suya o PLAN_1 a PLAN_3. En el commit, la ranura fuera de su turno es un marcador desconocido (422).

- **Texto** (150 caracteres): «SIN LISTA DE PLANES: en este turno no uses LISTA_DE_PLANES ni marcadores PLAN_ con numero. Para un precio usa PLAN_PRICE con el plan de la estrategia.»

### 2 ter.6 La lista del servidor: una cifra escrita (`plans_overview/price_written`, `factuality`)

**Cuándo sale:** con la lista del servidor, cuando el borrador escribe una cifra o un precio (los pone el backend en la lista).

- **Texto** (93 caracteres): «SIN CIFRAS: no escribas precios ni cifras de dinero; el backend pone los precios en la lista.»

### 2 ter.7 La longitud, con la lista del servidor (`length_over_cap`, `progressive_disclosure`)

**Cuándo sale:** con la lista del servidor, cuando el mensaje con la lista ya puesta pasa del tope de la lista del turno: pide acortar la prosa, nunca quitar planes. Si el último borrador sigue sin caber, sale la lista fija entera.

- **Plantilla:** «DEMASIADO LARGO: con la lista mide {L} caracteres y el limite de este turno es {N}. La lista ya ocupa {X}: tu texto, como mucho {R}. Primero la respuesta; una pregunta corta o ninguna. No quites planes: la lista va entera.»
- **Texto** (221 caracteres): «DEMASIADO LARGO: con la lista mide 339 caracteres y el limite de este turno es 320. La lista ya ocupa 75: tu texto, como mucho 245. Primero la respuesta; una pregunta corta o ninguna. No quites planes: la lista va entera.»

## 3. La expansión de la lista (lo que lee la persona)

Cuando el borrador nombra los planes de la lista con sus marcadores (`{{PLAN_1}}` a `{{PLAN_3}}`), el commit sustituye cada uno por el nombre y el precio del plan leídos del catálogo en ese momento. Si el plan es de un mes (28 a 31 días), sin duración; si no, con la duración en las palabras de la lista fija («7 días», «tres meses», «un año»…) y «1 día» en singular. El nombre sale sin llaves (si alguien escribe una en el CRM, no se convierte en un marcador), y si lo que sale tiene aún forma de marcador, no sale: va el último recurso de siempre.

- 30 días: «Plan Valera ($65.000 COP)»
- 7 días: «Plan Semana (7 días, $45.000 COP)»
- 1 día: «PLAN DÍA (1 día, $10.000 COP)»

Un borrador entero, «Claro. Tenemos {{PLAN_1}}, {{PLAN_2}} y {{PLAN_3}}. ¿Cuál le interesa más?», sale así (131 caracteres): «Claro. Tenemos Plan Valera ($65.000 COP), Plan Semana (7 días, $45.000 COP) y PLAN DÍA (1 día, $10.000 COP). ¿Cuál le interesa más?»

Con el compilador 8 la lista la compone Laravel: el borrador lleva la ranura `{{LISTA_DE_PLANES}}` o nada, y el commit pone la lista ENTERA, con todos los planes de la lista firmada, en su orden y unidos por «; », con el nombre y el precio leídos del catálogo en ese momento. Va en la ranura o, sin ella, en una frase propia justo antes de la pregunta final (o al final), con el encabezado de la lista fija sin «Claro. »: «Planes de un mes: », «Algunos planes de un mes: », «Nuestros planes: » o «Algunos de nuestros planes: ». Las frases del modelo no se tocan. Si un plan de la lista ya no se vende, no sale una lista a medias: sale la lista fija del catálogo de ese momento, entera.

- Con la ranura, «Claro, con gusto. Estos son: {{LISTA_DE_PLANES}}. ¿Cuál le interesa más?» sale así (144 caracteres): «Claro, con gusto. Estos son: Plan Valera ($65.000 COP); Plan Semana (7 días, $45.000 COP); PLAN DÍA (1 día, $10.000 COP). ¿Cuál le interesa más?»
- Sin ranura, «Claro, con gusto. ¿Cuál le interesa más?» sale así (150 caracteres): «Claro, con gusto. Nuestros planes: Plan Valera ($65.000 COP); Plan Semana (7 días, $45.000 COP); PLAN DÍA (1 día, $10.000 COP). ¿Cuál le interesa más?»

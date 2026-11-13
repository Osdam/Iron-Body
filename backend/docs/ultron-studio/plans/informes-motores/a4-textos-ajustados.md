# A4 · Textos de respaldo ajustados al tope (para aprobar)

Generado por `UltronA4LongitudYPreciosTest::test_los_textos_ajustados_que_aprueba_el_propietario` (no a mano), turno completo por el endpoint con el revisor caído dos veces. Datos: los 13 planes vendibles de producción (plan §2.1) y la ficha del horario de producción (253 caracteres). La base de conocimiento de la prueba tiene las tres piezas de producción (identidad, ubicación con un teléfono ficticio que `sinTelefonos` retira, y horario; no hay `gym_info`): `general_info` se llena por piezas en orden. Entre paréntesis, la longitud medida (sin URL). Hora fijada: martes 12-11-2026, 16:52 en Bogotá («buenas tardes»).

Leyenda: «Hoy (Legacy)» es lo que sale sin versión aplicada y no cambia; «Versión aplicada, sin tope (320)» solo difiere en la pregunta de precios (D-6).

## Por intención (`SalesAgentDecisionSchema::INTENTS`)

### `pricing_question` — entrante: «¿Qué planes tienen y cuánto cuesta la mensualidad?»

- **Hoy (Legacy)** (125) «Sí, claro. Cuéntame qué buscas —bajar grasa, ganar masa o coger el hábito— y te digo el plan que mejor te encaja y su precio.»
- **Versión aplicada, sin tope (320)** (191) «Claro. Planes de un mes: Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP; Élite, $180.000 COP. También hay planes por día, por semana y de varios meses. ¿Sobre cuál amplío la información?»
- **Tope 200** (191) «Claro. Planes de un mes: Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP; Élite, $180.000 COP. También hay planes por día, por semana y de varios meses. ¿Sobre cuál amplío la información?»
- **Tope 160** (133) «Claro. Planes de un mes: Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP; Élite, $180.000 COP. ¿Sobre cuál amplío la información?»

### `payment_link_request` — entrante: «Mándame el link para pagar»

- **Hoy (Legacy)** (236) «El pago lo haces tú mismo desde la app Iron Body Workout: creas tu cuenta con tu documento y ahí pagas con Nequi, PSE, tarjeta o Daviplata. También puedes pagar en el gimnasio cuando vengas. ¿Quieres que te pase la app para descargarla?»
- **Versión aplicada, sin tope (320)** (236) «El pago lo haces tú mismo desde la app Iron Body Workout: creas tu cuenta con tu documento y ahí pagas con Nequi, PSE, tarjeta o Daviplata. También puedes pagar en el gimnasio cuando vengas. ¿Quieres que te pase la app para descargarla?»
- **Tope 200** (185) «El pago lo haces tú mismo desde la app Iron Body Workout: creas tu cuenta con tu documento y ahí pagas con Nequi, PSE, tarjeta o Daviplata. ¿Quieres que te pase la app para descargarla?»
- **Tope 160** (139) «El pago lo haces tú mismo desde la app Iron Body Workout: creas tu cuenta con tu documento y ahí pagas con Nequi, PSE, tarjeta o Daviplata.»

### `price_objection` — entrante: «Está muy caro»

- **Hoy (Legacy)** (147) «Te entiendo. Más que pagar por pagar, la idea es que sientas que lo vas a aprovechar. ¿Quieres que te cuente para quién sí vale la pena el mensual?»
- **Versión aplicada, sin tope (320)** (147) «Te entiendo. Más que pagar por pagar, la idea es que sientas que lo vas a aprovechar. ¿Quieres que te cuente para quién sí vale la pena el mensual?»
- **Tope 200** (147) «Te entiendo. Más que pagar por pagar, la idea es que sientas que lo vas a aprovechar. ¿Quieres que te cuente para quién sí vale la pena el mensual?»
- **Tope 160** (147) «Te entiendo. Más que pagar por pagar, la idea es que sientas que lo vas a aprovechar. ¿Quieres que te cuente para quién sí vale la pena el mensual?»

### `time_objection` — entrante: «No tengo tiempo»

- **Hoy (Legacy)** (130) «Te entiendo. A veces la clave es armar algo realista, no perfecto. ¿Cuántos días a la semana crees que podrías ir sin complicarte?»
- **Versión aplicada, sin tope (320)** (130) «Te entiendo. A veces la clave es armar algo realista, no perfecto. ¿Cuántos días a la semana crees que podrías ir sin complicarte?»
- **Tope 200** (130) «Te entiendo. A veces la clave es armar algo realista, no perfecto. ¿Cuántos días a la semana crees que podrías ir sin complicarte?»
- **Tope 160** (130) «Te entiendo. A veces la clave es armar algo realista, no perfecto. ¿Cuántos días a la semana crees que podrías ir sin complicarte?»

### `delay_objection` — entrante: «Lo voy a pensar»

- **Hoy (Legacy)** (129) «Tranquilo, puedes pensarlo con calma. Si decides empezar, te ayudamos a hacerlo bien. ¿Te queda alguna duda que pueda resolverte?»
- **Versión aplicada, sin tope (320)** (129) «Tranquilo, puedes pensarlo con calma. Si decides empezar, te ayudamos a hacerlo bien. ¿Te queda alguna duda que pueda resolverte?»
- **Tope 200** (129) «Tranquilo, puedes pensarlo con calma. Si decides empezar, te ayudamos a hacerlo bien. ¿Te queda alguna duda que pueda resolverte?»
- **Tope 160** (129) «Tranquilo, puedes pensarlo con calma. Si decides empezar, te ayudamos a hacerlo bien. ¿Te queda alguna duda que pueda resolverte?»

### `beginner_fear` — entrante: «Nunca he entrenado y me da miedo»

- **Hoy (Legacy)** (157) «Es normal sentir pena al inicio. A muchas personas les pasa cuando no conocen el ambiente o las máquinas. ¿Qué te preocupa más: ir solo o no saber qué hacer?»
- **Versión aplicada, sin tope (320)** (157) «Es normal sentir pena al inicio. A muchas personas les pasa cuando no conocen el ambiente o las máquinas. ¿Qué te preocupa más: ir solo o no saber qué hacer?»
- **Tope 200** (157) «Es normal sentir pena al inicio. A muchas personas les pasa cuando no conocen el ambiente o las máquinas. ¿Qué te preocupa más: ir solo o no saber qué hacer?»
- **Tope 160** (157) «Es normal sentir pena al inicio. A muchas personas les pasa cuando no conocen el ambiente o las máquinas. ¿Qué te preocupa más: ir solo o no saber qué hacer?»

### `insecurity_body` — entrante: «Me da pena mi cuerpo»

- **Hoy (Legacy)** (162) «Te entiendo, y no tienes por qué sentirte juzgado por eso. Mucha gente empieza justo desde ahí, paso a paso. ¿Te preocupa más el ambiente o no saber cómo empezar?»
- **Versión aplicada, sin tope (320)** (162) «Te entiendo, y no tienes por qué sentirte juzgado por eso. Mucha gente empieza justo desde ahí, paso a paso. ¿Te preocupa más el ambiente o no saber cómo empezar?»
- **Tope 200** (162) «Te entiendo, y no tienes por qué sentirte juzgado por eso. Mucha gente empieza justo desde ahí, paso a paso. ¿Te preocupa más el ambiente o no saber cómo empezar?»
- **Tope 160** (112) «Te entiendo, y no tienes por qué sentirte juzgado por eso. ¿Te preocupa más el ambiente o no saber cómo empezar?»

### `location_question` — entrante: «¿Dónde quedan?»

- **Hoy (Legacy)** (70) «Estamos en Cl. 24 Sur #33-53, Neiva, Huila. ¿Vas a ir por primera vez?»
- **Versión aplicada, sin tope (320)** (70) «Estamos en Cl. 24 Sur #33-53, Neiva, Huila. ¿Vas a ir por primera vez?»
- **Tope 200** (70) «Estamos en Cl. 24 Sur #33-53, Neiva, Huila. ¿Vas a ir por primera vez?»
- **Tope 160** (70) «Estamos en Cl. 24 Sur #33-53, Neiva, Huila. ¿Vas a ir por primera vez?»

### `schedule_question` — entrante: «¿Cuál es el horario de  atención entre semana?»

- **Hoy (Legacy)** (300) «Claro. Horario general de atencion: Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m. Este es el horario de apertura del gimnasio, no el horario de las clases. ¿En qué franja te queda mejor entrenar?»
- **Versión aplicada, sin tope (320)** (300) «Claro. Horario general de atencion: Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m. Este es el horario de apertura del gimnasio, no el horario de las clases. ¿En qué franja te queda mejor entrenar?»
- **Tope 200** (186) «Claro. Horario general de atencion: Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m.»
- **Tope 160** (150) «Claro. Horario general de atencion: Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m.»

### `general_info` — entrante: «Quiero información del gimnasio»

- **Hoy (Legacy)** (444) «Con gusto te cuento. Iron Body Neiva es un centro de acondicionamiento físico en Neiva. Iron Body Neiva está ubicado en Cl. 24 Sur #33-53, Neiva, Huila. Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m. Este es el horario de apertura del gimnasio, no el horario de las clases. ¿Qué te cuento más a fondo: los planes, las clases o cómo empezar?»
- **Versión aplicada, sin tope (320)** (444) «Con gusto te cuento. Iron Body Neiva es un centro de acondicionamiento físico en Neiva. Iron Body Neiva está ubicado en Cl. 24 Sur #33-53, Neiva, Huila. Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m. Este es el horario de apertura del gimnasio, no el horario de las clases. ¿Qué te cuento más a fondo: los planes, las clases o cómo empezar?»
- **Tope 200** (154) «Con gusto te cuento. Iron Body Neiva es un centro de acondicionamiento físico en Neiva. ¿Qué te cuento más a fondo: los planes, las clases o cómo empezar?»
- **Tope 160** (154) «Con gusto te cuento. Iron Body Neiva es un centro de acondicionamiento físico en Neiva. ¿Qué te cuento más a fondo: los planes, las clases o cómo empezar?»

### `goal_fat_loss` — entrante: «Quiero bajar de peso»

- **Hoy (Legacy)** (127) «Listo. Para bajar grasa lo más importante es empezar con algo que puedas sostener. ¿Ya vienes entrenando o arrancas desde cero?»
- **Versión aplicada, sin tope (320)** (127) «Listo. Para bajar grasa lo más importante es empezar con algo que puedas sostener. ¿Ya vienes entrenando o arrancas desde cero?»
- **Tope 200** (127) «Listo. Para bajar grasa lo más importante es empezar con algo que puedas sostener. ¿Ya vienes entrenando o arrancas desde cero?»
- **Tope 160** (127) «Listo. Para bajar grasa lo más importante es empezar con algo que puedas sostener. ¿Ya vienes entrenando o arrancas desde cero?»

### `goal_muscle_gain` — entrante: «Quiero ganar masa»

- **Hoy (Legacy)** (117) «Perfecto. Para ganar masa lo clave es entrenar constante y con buena guía. ¿Ya has entrenado antes o estás empezando?»
- **Versión aplicada, sin tope (320)** (117) «Perfecto. Para ganar masa lo clave es entrenar constante y con buena guía. ¿Ya has entrenado antes o estás empezando?»
- **Tope 200** (117) «Perfecto. Para ganar masa lo clave es entrenar constante y con buena guía. ¿Ya has entrenado antes o estás empezando?»
- **Tope 160** (117) «Perfecto. Para ganar masa lo clave es entrenar constante y con buena guía. ¿Ya has entrenado antes o estás empezando?»

### `goal_recomposition` — entrante: «Quiero bajar grasa y ganar músculo»

- **Hoy (Legacy)** (183) «Eso se puede trabajar como recomposición corporal: ganar músculo y bajar grasa poco a poco. Lo clave es hacerlo con constancia y buena guía. ¿Ya has entrenado antes o estás empezando?»
- **Versión aplicada, sin tope (320)** (183) «Eso se puede trabajar como recomposición corporal: ganar músculo y bajar grasa poco a poco. Lo clave es hacerlo con constancia y buena guía. ¿Ya has entrenado antes o estás empezando?»
- **Tope 200** (183) «Eso se puede trabajar como recomposición corporal: ganar músculo y bajar grasa poco a poco. Lo clave es hacerlo con constancia y buena guía. ¿Ya has entrenado antes o estás empezando?»
- **Tope 160** (134) «Eso se puede trabajar como recomposición corporal: ganar músculo y bajar grasa poco a poco. ¿Ya has entrenado antes o estás empezando?»

### `high_intent_close` — entrante: «Quiero inscribirme ya»

- **Hoy (Legacy)** (236) «El pago lo haces tú mismo desde la app Iron Body Workout: creas tu cuenta con tu documento y ahí pagas con Nequi, PSE, tarjeta o Daviplata. También puedes pagar en el gimnasio cuando vengas. ¿Quieres que te pase la app para descargarla?»
- **Versión aplicada, sin tope (320)** (236) «El pago lo haces tú mismo desde la app Iron Body Workout: creas tu cuenta con tu documento y ahí pagas con Nequi, PSE, tarjeta o Daviplata. También puedes pagar en el gimnasio cuando vengas. ¿Quieres que te pase la app para descargarla?»
- **Tope 200** (185) «El pago lo haces tú mismo desde la app Iron Body Workout: creas tu cuenta con tu documento y ahí pagas con Nequi, PSE, tarjeta o Daviplata. ¿Quieres que te pase la app para descargarla?»
- **Tope 160** (139) «El pago lo haces tú mismo desde la app Iron Body Workout: creas tu cuenta con tu documento y ahí pagas con Nequi, PSE, tarjeta o Daviplata.»

### `greeting` — entrante: «Hola buenas tardes»

- **Hoy (Legacy)** (110) «Hola, muy buenas tardes. Bienvenido a Iron Body, qué gusto atenderte. Cuéntame, ¿en qué te podemos ayudar hoy?»
- **Versión aplicada, sin tope (320)** (110) «Hola, muy buenas tardes. Bienvenido a Iron Body, qué gusto atenderte. Cuéntame, ¿en qué te podemos ayudar hoy?»
- **Tope 200** (110) «Hola, muy buenas tardes. Bienvenido a Iron Body, qué gusto atenderte. Cuéntame, ¿en qué te podemos ayudar hoy?»
- **Tope 160** (110) «Hola, muy buenas tardes. Bienvenido a Iron Body, qué gusto atenderte. Cuéntame, ¿en qué te podemos ayudar hoy?»

### `thanks` — entrante: «Gracias por la info»

- **Hoy (Legacy)** (93) «Con gusto. Si después quieres empezar o comparar planes, me escribes y te ayudo sin problema.»
- **Versión aplicada, sin tope (320)** (93) «Con gusto. Si después quieres empezar o comparar planes, me escribes y te ayudo sin problema.»
- **Tope 200** (93) «Con gusto. Si después quieres empezar o comparar planes, me escribes y te ayudo sin problema.»
- **Tope 160** (93) «Con gusto. Si después quieres empezar o comparar planes, me escribes y te ayudo sin problema.»

### `goodbye` — entrante: «Chao, hablamos luego»

- **Hoy (Legacy)** (92) «De una, tranquilo. Si más adelante quieres empezar o resolver dudas, me escribes y te ayudo.»
- **Versión aplicada, sin tope (320)** (92) «De una, tranquilo. Si más adelante quieres empezar o resolver dudas, me escribes y te ayudo.»
- **Tope 200** (92) «De una, tranquilo. Si más adelante quieres empezar o resolver dudas, me escribes y te ayudo.»
- **Tope 160** (92) «De una, tranquilo. Si más adelante quieres empezar o resolver dudas, me escribes y te ayudo.»

### `not_interested` — entrante: «No me interesa»

- **Hoy (Legacy)** (104) «Listo, tranquilo. No hay problema. Si después te animas o solo quieres resolver dudas, aquí te ayudamos.»
- **Versión aplicada, sin tope (320)** (104) «Listo, tranquilo. No hay problema. Si después te animas o solo quieres resolver dudas, aquí te ayudamos.»
- **Tope 200** (104) «Listo, tranquilo. No hay problema. Si después te animas o solo quieres resolver dudas, aquí te ayudamos.»
- **Tope 160** (104) «Listo, tranquilo. No hay problema. Si después te animas o solo quieres resolver dudas, aquí te ayudamos.»

### `bot_question` — entrante: «¿Eres un bot?»

- **Hoy (Legacy)** (144) «Soy el asistente automático de Iron Body y te puedo ayudar con información inicial. Si prefieres hablar con una persona del equipo, solo dímelo.»
- **Versión aplicada, sin tope (320)** (144) «Soy el asistente automático de Iron Body y te puedo ayudar con información inicial. Si prefieres hablar con una persona del equipo, solo dímelo.»
- **Tope 200** (144) «Soy el asistente automático de Iron Body y te puedo ayudar con información inicial. Si prefieres hablar con una persona del equipo, solo dímelo.»
- **Tope 160** (144) «Soy el asistente automático de Iron Body y te puedo ayudar con información inicial. Si prefieres hablar con una persona del equipo, solo dímelo.»

### `human_request` — entrante: «Quiero hablar con una persona»

- **Hoy (Legacy)** (148) «Claro, dejo marcada tu solicitud para que alguien del equipo la revise. Igual sigo por aquí si quieres que te ayude con precios, ubicación o planes.»
- **Versión aplicada, sin tope (320)** (148) «Claro, dejo marcada tu solicitud para que alguien del equipo la revise. Igual sigo por aquí si quieres que te ayude con precios, ubicación o planes.»
- **Tope 200** (148) «Claro, dejo marcada tu solicitud para que alguien del equipo la revise. Igual sigo por aquí si quieres que te ayude con precios, ubicación o planes.»
- **Tope 160** (148) «Claro, dejo marcada tu solicitud para que alguien del equipo la revise. Igual sigo por aquí si quieres que te ayude con precios, ubicación o planes.»

### `complaint` — entrante: «Tengo una queja del servicio»

- **Hoy (Legacy)** (107) «Entiendo. Lo dejo marcado como caso para revisión del equipo. Cuéntame qué pasó exactamente y lo atendemos.»
- **Versión aplicada, sin tope (320)** (107) «Entiendo. Lo dejo marcado como caso para revisión del equipo. Cuéntame qué pasó exactamente y lo atendemos.»
- **Tope 200** (107) «Entiendo. Lo dejo marcado como caso para revisión del equipo. Cuéntame qué pasó exactamente y lo atendemos.»
- **Tope 160** (107) «Entiendo. Lo dejo marcado como caso para revisión del equipo. Cuéntame qué pasó exactamente y lo atendemos.»

### `invoice_request` — entrante: «Necesito factura»

- **Hoy (Legacy)** (215) «Claro. Para factura necesito que el equipo confirme los datos correctos para no cometer errores. Te dejo marcada la solicitud de facturación. Si quieres, también puedo ayudarte con información de planes o ubicación.»
- **Versión aplicada, sin tope (320)** (215) «Claro. Para factura necesito que el equipo confirme los datos correctos para no cometer errores. Te dejo marcada la solicitud de facturación. Si quieres, también puedo ayudarte con información de planes o ubicación.»
- **Tope 200** (125) «Claro. Te dejo marcada la solicitud de facturación. Si quieres, también puedo ayudarte con información de planes o ubicación.»
- **Tope 160** (125) «Claro. Te dejo marcada la solicitud de facturación. Si quieres, también puedo ayudarte con información de planes o ubicación.»

### `medical_risk_escalation` — entrante: «Me lesioné la rodilla»

- **Hoy (Legacy)** (266) «Gracias por contarlo. Con una lesión es mejor no recomendar ejercicios específicos por aquí para no darte una orientación irresponsable. Te dejo marcado que necesitas revisión del equipo, y mientras tanto puedo ayudarte con información general de planes o ubicación.»
- **Versión aplicada, sin tope (320)** (266) «Gracias por contarlo. Con una lesión es mejor no recomendar ejercicios específicos por aquí para no darte una orientación irresponsable. Te dejo marcado que necesitas revisión del equipo, y mientras tanto puedo ayudarte con información general de planes o ubicación.»
- **Tope 200** (151) «Gracias por contarlo. Te dejo marcado que necesitas revisión del equipo, y mientras tanto puedo ayudarte con información general de planes o ubicación.»
- **Tope 160** (151) «Gracias por contarlo. Te dejo marcado que necesitas revisión del equipo, y mientras tanto puedo ayudarte con información general de planes o ubicación.»

### `fraud_or_payment_claim` — entrante: «Me cobraron dos veces»

- **Hoy (Legacy)** (195) «Tranquilo, lo dejo marcado para que el equipo revise tu caso de pago con cuidado. La membresía solo queda activa cuando el pago está confirmado. ¿Quieres que te ayude con algo más mientras tanto?»
- **Versión aplicada, sin tope (320)** (195) «Tranquilo, lo dejo marcado para que el equipo revise tu caso de pago con cuidado. La membresía solo queda activa cuando el pago está confirmado. ¿Quieres que te ayude con algo más mientras tanto?»
- **Tope 200** (195) «Tranquilo, lo dejo marcado para que el equipo revise tu caso de pago con cuidado. La membresía solo queda activa cuando el pago está confirmado. ¿Quieres que te ayude con algo más mientras tanto?»
- **Tope 160** (132) «Tranquilo, lo dejo marcado para que el equipo revise tu caso de pago con cuidado. ¿Quieres que te ayude con algo más mientras tanto?»

### `do_not_contact_request` — entrante: «No me escriban más»

- **Hoy (Legacy)** — (no se contesta)
- **Versión aplicada, sin tope (320)** — (no se contesta)
- **Tope 200** — (no se contesta)
- **Tope 160** — (no se contesta)

### `spam_or_low_quality` — entrante: «asdf»

- **Hoy (Legacy)** (58) «Claro, con gusto te ayudo. Cuéntame, ¿en qué te ayudo hoy?»
- **Versión aplicada, sin tope (320)** (58) «Claro, con gusto te ayudo. Cuéntame, ¿en qué te ayudo hoy?»
- **Tope 200** (58) «Claro, con gusto te ayudo. Cuéntame, ¿en qué te ayudo hoy?»
- **Tope 160** (58) «Claro, con gusto te ayudo. Cuéntame, ¿en qué te ayudo hoy?»

### `unknown` — entrante: «mmm»

- **Hoy (Legacy)** (86) «No quiero responderte cualquier cosa. ¿Te refieres a los planes, horarios o ubicación?»
- **Versión aplicada, sin tope (320)** (86) «No quiero responderte cualquier cosa. ¿Te refieres a los planes, horarios o ubicación?»
- **Tope 200** (86) «No quiero responderte cualquier cosa. ¿Te refieres a los planes, horarios o ubicación?»
- **Tope 160** (86) «No quiero responderte cualquier cosa. ¿Te refieres a los planes, horarios o ubicación?»

## Horario (ficha de producción)

### Entre semana — «¿Cuál es el horario de  atención entre semana?»

- **Hoy (Legacy)** (300) «Claro. Horario general de atencion: Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m. Este es el horario de apertura del gimnasio, no el horario de las clases. ¿En qué franja te queda mejor entrenar?»
- **Versión aplicada, sin tope (320)** (300) «Claro. Horario general de atencion: Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m. Este es el horario de apertura del gimnasio, no el horario de las clases. ¿En qué franja te queda mejor entrenar?»
- **Tope 200** (186) «Claro. Horario general de atencion: Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m.»
- **Tope 160** (150) «Claro. Horario general de atencion: Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m.»

### Sábado — «¿y los sábados?»

- **Hoy (Legacy)** (300) «Claro. Horario general de atencion: Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m. Este es el horario de apertura del gimnasio, no el horario de las clases. ¿En qué franja te queda mejor entrenar?»
- **Versión aplicada, sin tope (320)** (300) «Claro. Horario general de atencion: Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m. Este es el horario de apertura del gimnasio, no el horario de las clases. ¿En qué franja te queda mejor entrenar?»
- **Tope 200** (186) «Claro. Horario general de atencion: Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m.»
- **Tope 160** (150) «Claro. Horario general de atencion: Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m.»

### Sin día — «¿Qué horario tienen?»

- **Hoy (Legacy)** (300) «Claro. Horario general de atencion: Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m. Este es el horario de apertura del gimnasio, no el horario de las clases. ¿En qué franja te queda mejor entrenar?»
- **Versión aplicada, sin tope (320)** (300) «Claro. Horario general de atencion: Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m. Este es el horario de apertura del gimnasio, no el horario de las clases. ¿En qué franja te queda mejor entrenar?»
- **Tope 200** (186) «Claro. Horario general de atencion: Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. Festivos de 8:00 a. m. a 2:00 p. m.»
- **Tope 160** (150) «Claro. Horario general de atencion: Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m.»

## Precios (catálogo de producción)

### «¿Qué planes tienen y cuánto cuesta la mensualidad?»

- **Hoy (Legacy)** (125) «Sí, claro. Cuéntame qué buscas —bajar grasa, ganar masa o coger el hábito— y te digo el plan que mejor te encaja y su precio.»
- **Versión aplicada, sin tope (320)** (191) «Claro. Planes de un mes: Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP; Élite, $180.000 COP. También hay planes por día, por semana y de varios meses. ¿Sobre cuál amplío la información?»
- **Tope 200** (191) «Claro. Planes de un mes: Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP; Élite, $180.000 COP. También hay planes por día, por semana y de varios meses. ¿Sobre cuál amplío la información?»
- **Tope 160** (133) «Claro. Planes de un mes: Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP; Élite, $180.000 COP. ¿Sobre cuál amplío la información?»

### «¿Y el Élite cuánto cuesta? ¿Qué planes tienen?» (nombra un plan)

- **Hoy (Legacy)** (125) «Sí, claro. Cuéntame qué buscas —bajar grasa, ganar masa o coger el hábito— y te digo el plan que mejor te encaja y su precio.»
- **Versión aplicada, sin tope (320)** (191) «Claro. Planes de un mes: Élite, $180.000 COP; Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP. También hay planes por día, por semana y de varios meses. ¿Sobre cuál amplío la información?»
- **Tope 200** (191) «Claro. Planes de un mes: Élite, $180.000 COP; Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP. También hay planes por día, por semana y de varios meses. ¿Sobre cuál amplío la información?»
- **Tope 160** (133) «Claro. Planes de un mes: Élite, $180.000 COP; Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP. ¿Sobre cuál amplío la información?»


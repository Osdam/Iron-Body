# ULTRON Studio · Por qué cada cosa salía encendida o apagada

**Para:** el propietario. **Fecha:** 11-11-2026 (se actualiza al cierre). **Producción:** API `e9b3c9e3`, CRM `85c62d2` (11-11-2026, 23:16 UTC).
**Cómo leerlo:** cada apartado dice lo que veías, por qué salía así, qué se hizo y cómo queda. Los estados finales usan solo cinco palabras: **OPERATIVO Y PROBADO** · **LISTO PARA ACTIVACIÓN AUTORIZADA** · **EN OBSERVACIÓN** · **BLOQUEADO EXTERNAMENTE** · **INCOMPLETO**. No hay porcentajes.

El detalle técnico, con fichero y línea, está en las tres auditorías de `plans/cierre/` y en las filas L9-111 y L9-112 del contrato del H9.

---

## 1. La respuesta corta

Lo que veías «apagado» o «suspendido» tenía cuatro causas distintas, y solo una era falta de código:

1. **Nunca se había ejercido ninguna aprobación.** ULTRON Studio no deja que nadie publique lo que él mismo escribió: exige que otra persona apruebe y publique. En producción había 1 versión aprobada y 0 publicaciones, 0 veredictos del laboratorio, 0 personas vinculadas, 0 números canarios registrados, 0 estrategias y 0 plantillas. Los motores estaban encendidos, pero sin nada publicado que ejecutar: el de automatización hizo 809 pasadas de despacho en su primer día y no tenía ninguna estrategia que evaluar.
2. **El CRM describía el sistema de hace semanas.** Unas 40 frases seguían diciendo «el motor no lee el Studio», «falta el plan de pago de ElevenLabs» o «pendiente de proveedor externo» cuando ya no era verdad. Corregido y desplegado el 11-11: en las mismas 10 pantallas reales había 5 frases falsas visibles antes y 0 después.
3. **Faltan piezas que solo tú puedes aportar,** sobre todo para que Sofía llame por teléfono: un número de origen que no sea el de WhatsApp, una clave de Twilio para la voz y las autorizaciones escritas de la primera llamada.
4. **Hay cerrojos puestos a propósito** que no se abren con una bandera: las llamadas y los envíos automáticos están cerrados por una constante del código, que solo se abre con un commit que cite tu autorización escrita. Eso no es un fallo: es el seguro contra contactar a socios por accidente.

Y hay cosas **incompletas de verdad** en el código. Están en el apartado 5, sin maquillar.

---

## 2. Sección por sección

### 2.1 Studio · Versiones, aprobación y publicación

| Lo que veías | Por qué | Qué se hizo | Cómo queda |
|---|---|---|---|
| «Publicar» sin efecto; avisos de «el motor de ULTRON no lee versiones del Studio» | Era verdad hasta el 11-11. Ese día se desplegó la Enmienda M-1: la versión publicada gobierna el tono, los emojis, la longitud máxima y el objetivo de los prompts de WhatsApp en la conversación canaria. Los textos no se habían actualizado | Textos corregidos según la llave real del servidor. Aprobar, el aviso tras aprobar y el veredicto dicen qué falta y quién puede hacerlo | Crear, validar, enviar a revisión, aprobar e historial: **OPERATIVO Y PROBADO** |
| Una versión «aprobada» que no cambiaba nada | La única versión aprobada era idéntica a la línea base: publicarla habría dejado a ULTRON igual. Además, faltaba el veredicto del laboratorio y que la publicara alguien que no la creó | Dejé preparada la versión de prueba «Prueba de aceptación · profesional 200» (tono profesional, 200 caracteres), enviada a revisión, con su ejecución del laboratorio hecha. Su vista previa da la huella esperada, `702890f7` | Publicar en canario y revertir: **LISTO PARA ACTIVACIÓN AUTORIZADA**. Faltan tus tres clics (aprobar, veredicto, publicar) y 2–3 mensajes desde tu teléfono canario |
| «Promover a todas» | Aunque se promueva, en esta entrega los prompts gobernados solo llegan a la conversación canaria | — | **INCOMPLETO** por diseño de esta entrega (apartado 5) |

### 2.2 Reglas comerciales

| Lo que veías | Por qué | Qué se hizo | Cómo queda |
|---|---|---|---|
| Reglas que se pueden crear pero «el motor no las aplica» | Era cierto para WhatsApp: ninguna regla del Studio (S-NNN) llegaba a ningún motor. En la voz, sus frases prohibidas sí entran en el agente compilado | Hecho y desplegado en el servidor el 11-11: las frases que prohíbe una regla viva entran en los prompts de WhatsApp de la conversación canaria al publicar una versión que la copia. Sin tocar n8n; lo ya publicado queda idéntico. Revisado por seguridad (62 entradas hostiles, ninguna se sale de sus comillas) y por un revisor independiente. No es un filtro duro: se le pide al modelo y el revisor automático lo vigila | Constructor, coherencia y copia en la versión: **OPERATIVO Y PROBADO** como registro. Efecto en WhatsApp: **LISTO PARA ACTIVACIÓN AUTORIZADA**. Para encenderlo faltan tu aprobación de las dos frases fijas (apartado 4) y publicar a cuatro ojos la primera versión con una regla con frases |
| — | Restricción nueva que trae ese efecto: con los prompts del Studio encendidos, una versión con más de 12 frases prohibidas, con una frase de menos de 3 caracteres o sin letras, o con signos como `%`, `$` o comillas, no se puede publicar | El diálogo de publicar dice qué regla y por qué | Por diseño: nada se recorta en silencio |

### 2.3 Laboratorio de texto

| Lo que veías | Por qué | Qué se hizo | Cómo queda |
|---|---|---|---|
| «Simulación… No es ULTRON: el motor no lee el Studio» | La segunda mitad dejó de ser verdad con M-1 | Texto nuevo del servidor: dice lo que el laboratorio es y manda a la evidencia real, el turno de la conversación canaria | Ejecutar, estimar y coste: **OPERATIVO Y PROBADO** (3 ejecuciones reales; hoy, US$ 0,16) |
| Ningún veredicto | Lo da una persona que no sea la autora de la versión | Hay una ejecución lista para tu veredicto | **LISTO PARA ACTIVACIÓN AUTORIZADA** |
| — | El laboratorio prueba una simulación con instrucciones propias, no los cinco prompts reales de WhatsApp. En 11 escenarios fallan 4, los mismos con la versión de prueba y con la línea base | Dicho en el texto | **INCOMPLETO** (apartado 5) |

### 2.4 Integraciones y números canarios

| Lo que veías | Por qué | Qué se hizo | Cómo queda |
|---|---|---|---|
| «Números canarios: sin efecto en el motor» | Falso desde que existen la voz y la automatización: las dos leen el registro. Solo WhatsApp no lo lee | Texto corregido, por canal | Registro, autorización y revocación: **LISTO PARA ACTIVACIÓN AUTORIZADA** |
| «El teléfono de la conversación canaria del motor no está en el registro» | Es verdad: tu teléfono es el canario del motor (lo fija el servidor), pero nadie lo ha registrado en el Studio. Registrarlo exige dos Super Admin distintos: uno registra declarando el consentimiento del titular y otro autoriza y activa | El aviso ya era correcto; se deja | Depende de ti (apartado 4) |
| Meta Ads, WhatsApp de Meta, Twilio OTP, ElevenLabs, n8n | — | Cada una comprobada con una lectura o validación real el 11-11 | **OPERATIVO Y PROBADO** |

### 2.5 WhatsApp ↔ n8n

| Lo que veías | Por qué | Qué se hizo | Cómo queda |
|---|---|---|---|
| ULTRON solo contesta a un número | Está limitado a la conversación canaria por decisión tuya, mientras se prueba. A los demás clientes los atiende el personal | Tu «Hola» del 11-11 lo contestó ULTRON por la copia nueva del flujo, y el turno quedó anotado | Conversación canaria con los prompts de siempre: **OPERATIVO Y PROBADO** |
| Nadie avisaba si n8n aplicaba otra cosa que la publicada | No existía la alerta | Hecha y desplegada: el monitor lo comprueba cada 30 minutos y abre un incidente | **EN OBSERVACIÓN** (lee bien en producción; ningún caso real la ha disparado) |
| Abrir ULTRON a todos los clientes | Es una decisión tuya, no una bandera técnica | — | Depende de ti (apartado 4) |

### 2.6 Voz · Laboratorio hablado (Sofía)

| Lo que veías | Por qué | Qué se hizo | Cómo queda |
|---|---|---|---|
| «Falta el plan de pago de ElevenLabs» | El plan ya estaba contratado; el texto era viejo | Ningún texto del CRM vuelve a poner el plan como condición | — |
| Sofía no sabía planes ni horarios | El perfil de voz no tenía esas dos consultas, y la sede y el horario no estaban cargados | Sede y horario cargados; revisión 3 del perfil con las consultas, enviada a revisión; sesión real desde el CRM: Sofía dio cuatro planes con su precio, la sede y el horario, y coinciden con la base | **OPERATIVO Y PROBADO** en el laboratorio. Para publicar esa revisión falta que otra persona la apruebe |

### 2.7 Voz · Publicación, Twilio, llamadas y monitor

| Lo que veías | Por qué | Qué se hizo | Cómo queda |
|---|---|---|---|
| Publicación de voz «apagada» | Estaba apagada en el servidor | Encendida el 11-11. Publicar exige además el número importado y un cupo, que solo existen con la telefonía | **BLOQUEADO EXTERNAMENTE** |
| Twilio Voice «Apagado», «Subcuenta sin configurar» | No es un interruptor: faltan un número de origen y una clave de voz. El único número que hay es el de WhatsApp, y el código lo protege para que la voz nunca salga con él | El CRM dice ahora qué falta y que lo aportas tú en la consola de Twilio | **BLOQUEADO EXTERNAMENTE** |
| Salientes «Apagadas» | Esperan el número, la clave, el commit autorizado que abre las llamadas, una voz publicada y tu autorización | Camino mínimo y texto de la autorización, escritos (auditoría C §3 y §4.1) | **BLOQUEADO EXTERNAMENTE** |
| Entrantes «Apagadas» | No existe un número propio de Twilio que pueda recibir llamadas | Dicho en el CRM | **BLOQUEADO EXTERNAMENTE** |
| Monitor «apagado» | Lo estaba | Encendido el 11-11 tras conciliar en seco. Su primera revisión horaria salió bien y no echó el freno. El CRM dice qué vigila y cómo salió la última vigilancia | **EN OBSERVACIÓN** |
| Contactos y consentimiento de voz | Apagado a propósito hasta el visto bueno legal (Ley 1581, Ley 2300 y Registro de Números Excluidos). No hace falta para la llamada a tu canario. Corrección del 11-11: este informe decía que la supresión de datos no cubría las tablas de voz; sí las cubre desde el 10-11 | Supresión comprobada de nuevo el 11-11 en PostgreSQL (1 prueba, 1 122 comprobaciones) | **BLOQUEADO EXTERNAMENTE** (visto bueno legal); dos flecos en el apartado 5 |

### 2.8 Automatización comercial (campañas automáticas)

| Lo que veías | Por qué | Qué se hizo | Cómo queda |
|---|---|---|---|
| «La automatización nace apagada» | Texto fijo; en realidad está en sombra desde el 11-11 | El aviso dice el modo real | — |
| Sombra encendida pero sin resultados | No hay ninguna estrategia publicada. Publicar una exige dos personas vinculadas (hay 0), una plantilla aprobada y una versión aprobada con su simulación | El panel lo dice con el recuento real | Gobierno de estrategias: **OPERATIVO Y PROBADO**. Ejecución en sombra: **LISTO PARA ACTIVACIÓN AUTORIZADA** |
| — | Aun con una estrategia, la sombra habría negado a todo el que no fuera canario, contra lo que dice el contrato | Corregido, aprobado por seguridad (ninguna vía por la que salga algo real hacia quien no es canario) y desplegado el 11-11 | Hecho |
| — | El barrido solo miraba a los primeros 500 candidatos; el constructor ofrecía 6 disparadores que nadie emite | Corregido y desplegado el 11-11: el barrido recorre toda la audiencia en pasadas sucesivas y esos 6 disparadores ya no se ofrecen | Hecho |
| — | Nadie registra las quejas como evento: una estrategia no puede excluir a quien se quejó (las quejas por WhatsApp sí detienen la conversación mientras el personal la revisa) | Encontrado en esta revisión; no se inventó un registro | **INCOMPLETO**: hay que resolverlo antes de abrir cualquier envío automático |
| Asistido | Exige al menos 7 días de sombra sin hallazgos y tu decisión | — | **EN OBSERVACIÓN** (los 7 días no se acortan) |
| WhatsApp automático «Pendiente de proveedor externo» | No se había enviado nada a Meta: no era Meta quien tenía algo pendiente | Ahora es «condición contractual» mientras no haya una plantilla en revisión | **LISTO PARA ACTIVACIÓN AUTORIZADA** hasta donde llega sin personas; el envío sigue cerrado por la constante |
| Voz automática | Sale por la voz, así que hereda sus bloqueos | Dicho en el CRM | **BLOQUEADO EXTERNAMENTE** |
| Botones que daban error | Crear plantillas y «Comprobar permisos» exigen persona vinculada | Se desactivan con el motivo escrito | — |
| Clasificador de respuestas | No está implementado; la bandera no hace nada | El CRM lo dice | **INCOMPLETO** |
| Socios | No hay fuente de consentimiento para socios: una estrategia sobre socios se niega a todos. Sobre prospectos sí funciona | — | **INCOMPLETO**, y necesita tu decisión legal |

### 2.9 Plantillas de Meta

| Lo que veías | Por qué | Qué se hizo | Cómo queda |
|---|---|---|---|
| «Conectada» sin haber comprobado nada | El texto afirmaba de más | Ahora dice «Configurada, sin comprobar con Meta» | Lectura y sincronización: **OPERATIVO Y PROBADO**. Comprobar permisos y enviar la primera plantilla: **LISTO PARA ACTIVACIÓN AUTORIZADA** (exigen persona vinculada) |

---

## 3. Qué se encendió y qué se desplegó el 11-11

- **Encendido en producción con su mando:** el motor del Studio y sus prompts en canario, las plantillas de Meta, la sede y el horario de Sofía, el monitor de integraciones, el monitor de voz y la publicación de voz. ElevenLabs, verificado hasta el 11-12.
- **Desplegado:** la Enmienda M-1 y las plantillas, las consultas de Sofía en el laboratorio, la alerta automática de los prompts y los textos veraces del CRM.
- **Pruebas reales hechas:** tu turno de WhatsApp por la copia nueva del flujo; la sesión hablada con Sofía consultando planes, precios, sede y horario; las lecturas reales de cada integración; las diez pantallas del CRM antes y después.
- **Pruebas reales pendientes:** un turno con una versión publicada (tus tres clics), una publicación de voz, una llamada, una plantilla enviada a Meta y una estrategia publicada en sombra.

---

## 4. Lo que depende de ti

| # | Qué | Para qué | Riesgo y vuelta atrás |
|---|---|---|---|
| 1 | Con «Desarrolladores»: aprobar la versión «Prueba de aceptación · profesional 200», dar el veredicto «Aprobada» a su ejecución del laboratorio, publicarla en canario (07:00–19:00) y mandar 2–3 mensajes desde tu teléfono canario | Demostrar que lo publicado en el Studio es lo que ULTRON dice | Solo afecta a tu conversación. «Revertir» lo deja como estaba |
| 2 | Decir quiénes son las dos personas reales, cada una con su cuenta personal y su ficha en Personal | Vincularlas (una sola orden mía). Sin eso no hay estrategias, plantillas ni comprobación de Meta | Se hace una vez; un vínculo equivocado se revoca desde el CRM |
| 3 | Registrar tu teléfono como número canario (un Super Admin registra; otro autoriza y activa) | Que el registro documente el canario y sirva a la voz y a la automatización | Se desactiva o se revoca |
| 4 | En Twilio: un número de origen que no sea el de WhatsApp (verificar otro como identificador de llamada no cuesta) y una clave restringida de voz; importarlo en ElevenLabs como solo saliente | La primera llamada de Sofía a tu canario | Sin coste hasta la llamada. La llamada: 1 al día, tope de US$ 2,00, 5 minutos; texto exacto de la autorización en la auditoría C §3 |
| 5 | Crear una clave nueva de ElevenLabs y, al final, revocar la vieja | La actual pasó por el chat | Hasta revocar la vieja se puede volver |
| 6 | Decidir la base legal del consentimiento de los socios | Estrategias sobre socios (renovación, vencidos, inactivos) | Sin ella, solo prospectos |
| 7 | Decidir cuándo abrir ULTRON a todas las conversaciones | Que conteste a clientes reales | Una bandera; se cierra igual |
| 8 | Decidir qué se hace con las copias antiguas del fichero de entorno que guardan secretos en el servidor | Higiene | Se conserva la última hasta verificar |
| 9 | Aprobar las dos frases fijas que el compilador añade a los prompts cuando una versión trae reglas con frases prohibidas. Al redactor: «FRASES PROHIBIDAS PUBLICADAS: nunca escribas ninguna de estas frases, tampoco con otras mayusculas; si la respuesta parece necesitar una, dilo con otras palabras. Las frases: …». Al revisor: «FRASES PROHIBIDAS PUBLICADAS: si el borrador contiene alguna de estas frases, aunque cambien las mayusculas, verdict fail con naturalness. Las frases: …» | Que las reglas del Studio tengan efecto en WhatsApp | Sin reglas con frases no cambia nada. Se quita volviendo a publicar una versión sin esas reglas o con «Volver a Legacy» |

### Decisiones nuevas: quejas, cuatro ojos por regla y consentimiento

Un análisis de arquitectura del 11-11 (`plans/cierre/plan-quejas-consentimiento-cuatro-ojos.md`) dejó quince preguntas cerradas. Estas son las que cambian lo que se construye esta semana.

| Tema | Pregunta | Lo que se usa mientras no contestes |
|---|---|---|
| Quejas | ¿Una queja que detecta la IA en WhatsApp frena el contacto automático aunque nadie la confirme? | Sí: un falso positivo solo silencia |
| Quejas | ¿Cuántos días frena una queja? | 30 días |
| Quejas | ¿Frena solo lo comercial o también la cobranza? | Comercial y cobranza; los avisos de servicio, no |
| Quejas | ¿Se cuentan las quejas anteriores, recuperándolas del historial? | No, hasta que lo digas |
| Quejas | ¿Quieres un botón en el CRM para registrar quejas presenciales o por teléfono? | No se construye todavía |
| Cuatro ojos | ¿Aceptas que quien escribió una frase prohibida no pueda aprobar ni publicar ninguna versión que la copie, aunque sea Super Admin? | No se construye hasta tu respuesta; rige la regla de operación |
| Cuatro ojos | Si las dos únicas personas que revisan escribieron frases, ¿aceptas el bloqueo hasta que haya una tercera o se archive la regla? | Igual |
| Consentimiento | ¿Cómo se recoge la autorización de los socios para mensajes comerciales, y qué texto exacto firma? | Necesita a tu asesoría legal. La autorización de datos actual no cubre mensajes comerciales por WhatsApp |

---

## 5. Lo incompleto de verdad

| Qué | Por qué importa | Estado |
|---|---|---|
| Registro de quejas para la automatización | Sin él, una estrategia no puede excluir a quien se quejó | No implementado; obligatorio antes de abrir envíos automáticos |
| Cuatro ojos por regla | Hoy los cuatro ojos son por versión: quien escribe una frase prohibida puede aprobar y publicar la versión de otra persona que la copia | Regla de operación mientras tanto: quien escribe la regla no aprueba ni publica |
| El laboratorio de texto prueba una simulación, no los prompts reales | El veredicto certifica la simulación; la evidencia real es el turno canario | No entra en esta entrega |
| Prompts gobernados fuera de la conversación canaria | Para que el Studio gobierne todas las conversaciones | No entra en esta entrega |
| Fuente de consentimiento de los socios | Sin ella no hay estrategias sobre socios | Necesita tu decisión legal y desarrollo |
| Clasificador de respuestas | Hace falta cuando haya envíos automáticos con respuesta | No implementado |
| Eventos especiales del gimnasio y campañas como disparador | Ampliación | No implementado |
| Supresión de datos de voz: dos flecos | La supresión ya cubre las once tablas de voz. Quedan dos cosas: el enlace de un contacto con su ficha de socio o prospecto no se puede suprimir (se informa), y el borrado de la conversación en ElevenLabs y de la llamada en Twilio es un paso manual sin confirmar | Decidir antes de registrar contactos que no sean canarios |

---

## 6. Seguridad y continuidad

- No se tocó el OTP de Twilio, Factus, Wompi, Firebase ni la app móvil. El WhatsApp de siempre sigue atendiendo a los clientes.
- Ninguna llamada ni mensaje automático ha salido hacia un socio: las dos constantes del código siguen cerradas.
- Los siete días de sombra no se han acortado. Como la sombra corrió sin estrategias, lo honesto es contarlos desde la primera publicación; es tu decisión.
- Cada cambio de hoy tiene su vuelta atrás escrita (L9-112).
- Pendiente: rotar la clave de ElevenLabs y decidir sobre las copias antiguas con secretos.

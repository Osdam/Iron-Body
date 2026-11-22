# Informe frontend · Cierre de ULTRON STUDIO en el CRM (A–F y ronda 2)

**22-11-2026 · `frontend`.** Árbol `_worktrees/cierre-crm/Iron-Body_Front`, rama `cierre/crm` (sale de `0cfad9d` = `origin/main`). Sin push, sin red, sin desplegar.
Commits: `25aa447` (A) · `087b4a8` (B) · `4006eec` (C) · `aa61a49` (ajuste de A) · `c374438` (D) · ronda 2: `cd1e33d` · `f911f5d` · `f41b4cf` · `ead3f3e` · campos nuevos del clasificador: `afd2b68` · E: `c47ba62` · F: `a9e7e7a`. Las rutas son de `src/app/modules/`.

## A · Bandeja asistida: los once motivos (`25aa447`)

- **Una tabla, una línea por motivo.** `commercial-automation/commercial-automation-text.ts:256` `ASSISTED_WITHHELD_REASON_TEXTS` (código → texto, en el orden de §9.4) es la única fuente. El tipo sale de ella (`:271`) y la lista del lector también (`:274`). `commercial-automation-api.types.ts:1185-1186` solo los reexporta, así que el lector (`commercial-automation-parse.ts`) no cambia. Un motivo que no está en la tabla sigue siendo `unrecognized` («no contactes»).
- **Con el servidor de hoy no cambia nada:** sin `contactWithheld` ni `meta`, la fila sale igual. Las pruebas «API anterior» y «sin retención» no se han tocado y siguen en verde.
- **Textos** (antes → ahora):
  - `brake` `:257`: «Hay un freno abierto (…): no contactes a esta persona mientras siga abierto.» → «Hay un freno abierto (…), o el modo ya no está «En vivo» o el canal asistido está apagado: no contactes a esta persona mientras siga así.» El modo cuenta como apagado si está fuera de `live` (`AssistedQueue::openBrakes()`).
  - `opted_out` `:260`: «…retiró su autorización o el número no es suyo» → «…retiró su autorización, revocó la de llamadas o el número no es suyo».
  - `policy_unknown` `:265`: «No se puede comprobar si esta persona pidió que no la contacten (no hay datos de su ficha)» → «No se puede comprobar que se pueda contactar a esta persona (por ejemplo, faltan datos de su ficha o su contacto de voz se retiró)». Ahora también cubre la elegibilidad y el alcance canario que no se pueden comprobar (`AssistedQueue.php:472`).
  - Los cuatro nuevos, que terminan todos con «Si ya hablaste con ella, registra el resultado.»:
    - `template_not_approved` `:259`: «La plantilla de este trabajo ya no está aprobada, así que no hay guion: no contactes a esta persona por este trabajo.»
    - `subject_minor` `:262` (ajustado en `aa61a49`, revisión de security): «Esta persona no puede ser contactada por este canal: no la contactes.» Ya no dice «Esta persona es menor de edad…»; el spec comprueba que ningún aviso nombra la edad.
    - `rne_check_missing` `:263`: «Falta una consulta vigente al Registro de Números Excluidos para alguno de sus números: no la llames hasta que se renueve.»
    - `canary_scope_mismatch` `:264`: «Este trabajo solo puede llegar a números de prueba y ninguno de los suyos sigue activo para este medio: no contactes a esta persona por este trabajo.»
  - El recuento `meta.withheld` (`:295`) también cambia, porque el servidor suma ahora los motivos nuevos (`AssistedQueue.php:222`). Antes: «…hay un freno abierto, la estrategia no está en marcha, la persona pidió que no la contacten o se quejó, o no se puede comprobar.» Ahora: «…hay un freno abierto (o el modo ya no está «En vivo» o el canal asistido está apagado), la estrategia no está en marcha o su plantilla ya no está aprobada, la persona pidió que no la contacten, se quejó o ya no se la puede contactar por este canal, o no se puede comprobar.»
- **El 201 del reclamo** con cualquier motivo pasa por el mismo lector; la prueba lo recorre de punta a punta. El aviso sigue siendo `role="status"`, sin cambios de estructura.
- **Cambia a propósito** `commercial-automation-api.service.spec.ts:452`, que fijaba la lista exacta de 7 motivos y ahora fija la de 11. Si security ajusta un texto, es una línea de la tabla y su fila del `it.each`.

## B · El clasificador (AI9) en el panel (`087b4a8`)

- **Lector** (`commercial-automation-parse.ts:1038`; tipos en `commercial-automation-api.types.ts:861` y `:875`): sin la clave, `null` (la API de hoy). Con ella es estricto dentro del bloque: si algo no encaja, `'unreadable'`, y el resto del estado se sigue leyendo (la PARADA GLOBAL está en la misma pantalla). `today` va aparte (`:1060`).
- **Vista** (`commercial-automation/ca-panel.ts:102`, `classifierView`, pintada en la fila de la bandera con `data-ca-clasificador`). «Clasificador de respuestas (AI9): …», y según el estado:
  - **sin el bloque** (producción hoy): «no está implementado; la bandera no hace nada», igual que siempre; la prueba B6 sigue en verde;
  - **ilegible o contradictorio** (`effective` con el cupo a 0, o `enabled` ≠ `classifier`): «el servidor manda su estado, pero no se puede leer; no se da por operativo»;
  - **apagado**: «existe, pero está apagado; no clasifica ninguna respuesta»;
  - **sin configurar**: «encendido, pero sin configurar (falta X); …». X es lo que se sabe que falta: la conexión con el proveedor, el modelo, la clave propia o los precios, el cupo, el techo;
  - **sin «En vivo»**: «encendido y configurado, pero la ejecución no está «En vivo»; …», o «…pero el servidor no lo da por operativo; …»;
  - **operativo**, solo con `effective: true`: «operativo con el modelo M (versión V); clasifica las respuestas de WhatsApp a las campañas. Hoy: N de CAP llamadas y US$ a de US$ b (gastado o reservado).» Si falta `today`: «El consumo de hoy no se pudo leer.». Solo en este estado el chip sale «Encendida» en vivo.
- **Decisiones:** se muestran los topes de la configuración actual, que son los que aplica la reserva (`BudgetReservations.php:93`); los importes salen con `formatMicroUsd`.
- **Campos nuevos del servidor** (`afd2b68`; §13 del informe y `OutreachConfig.php:200`, `:234-235`):
  - `maxPerPass` a 0 → «sin configurar (falta el tope de respuestas por pasada)». Operativo, «…, hasta N por pasada». `effective` con el tope a 0 sale ilegible.
  - `providerFailures.consecutive` > 0 → estado `failing`, chip «Encendida, con fallos» (aviso; desde `alertAfter`, rojo): «…pero el proveedor falla: lleva N fallos seguidos sin una salida usable; el último, «tiempo agotado», el … (hora de Bogotá). Las respuestas que no consigue clasificar quedan como hoy, sin los frenos de la IA.».
  - Desde el umbral, con el monitor encendido: «el monitor abre un incidente en IRON GUARD en su pasada (o le suma una ocurrencia si ya está abierto), sin echar ningún freno»; apagado: «nadie abre el incidente». No digo «hay un incidente abierto», porque el panel no lo sabe: lo abre el monitor cuando pasa (`OutreachMonitor.php:220`).
  - Sin clasificar, la última racha se añade igual. Si la racha no se lee: «No se pudo leer si el proveedor está fallando.», sin chip en vivo. Sin los dos campos, todo como antes; solo cambia la prueba del lector, con los dos en `null`.

## C · Textos que no decían la verdad (`4006eec`)

- **a. Tono y objetivo** (`ultron-studio/studio-engine-text.ts:159`, `:172`, `:195`). La voz los compila (`StudioVoiceCompiler.php:409-420`); emojis y longitud, no.
  - **Llave apagada:**
    - «Sin equivalente en el motor» → «…del motor de WhatsApp»;
    - «Sin mando en el motor: el tono sale de…» → «En WhatsApp no tiene mando: el tono sale de los prompts de n8n y de los ítems de tono y brand_copy aprobados en la base de conocimiento. En la voz sí aplica: entra en el agente compilado al publicar la voz.» (el objetivo, igual, con «lo decide el prompt del Strategist en n8n»);
    - propuesta «el motor no la lee.» → «con la llave de prompts apagada, en WhatsApp no tiene efecto aunque se publique; en la voz, entra en el agente al publicar la voz con una versión que lo lleve.»
  - **Llave encendida o ilegible:** lo de WhatsApp se dice de WhatsApp y se añade la frase de la voz, también en «Lo cambia de verdad» y en la propuesta.
- **b. `governance.enabled`** (`studio-engine-text.ts:234`, `:239`; `studio-inspector.ts:221`): «Desde el Studio solo debería poder apagarse. …» → «El Studio no lo enciende ni lo apaga: la versión solo lo declara, y tiene que coincidir con el motor (publicar otro valor se rechaza). El interruptor está en el entorno del servidor; encenderlo exige el procedimiento de activación aprobado.» (`must_match` → 409, `StudioPublicationDeclaration.php:257-266`).
- **c. Reglas S-NNN con la llave apagada** (`studio-publication-text.ts:274`): «…no tienen efecto en el motor.» → «Las reglas del Studio no tienen efecto en WhatsApp. En la voz, sus frases prohibidas sí entran en el agente compilado.» (`StudioVoiceCompiler.php:771-783`).
- **d. Enlace de pago** (`commercial-automation/ca-tpl-detail.ts:201`): «Sí: N3 la bloquea si la versión comercial no permite enlaces de pago» → «Sí. Hoy el envío automático de WhatsApp nunca manda esta plantilla: no crea el enlace de pago y no la envía, aunque la versión comercial lo permita. Si no lo permite, N3 la bloquea antes.» (`payment_link` no está en `RESOLVABLE` en ninguna de las tres ramas; `WhatsappTemplateChannel.php:281`).
- **e. R-xx** (`commercial-automation/ca-builder.ts:323`): «N3 lee de esa referencia «rules.paymentLinksEnabled», «governance.canaryOnly» y las restricciones R-xx.» → «De esa referencia, N3 lee «rules.paymentLinksEnabled» y «governance.canaryOnly»; las restricciones R-xx las evalúa el servidor sobre el texto de cada plantilla.» (`studioReference()`, `violationsOf()`).
- **f. Pista de emojis: NO tocada.** Es `hint` del catálogo congelado (`StudioCatalog.php:589`; copia en `ultron-studio/testing/backend-studio-catalog.testing.ts:998`). El CRM no tiene ninguna capa que sobrescriba las pistas: `studio-param-row.ts:106` pinta `p.hint` tal cual.
- **Specs que cambian a propósito:** los que fijaban los textos viejos de tono y objetivo, en `studio-engine-text.spec.ts`, `studio-param-row.spec.ts` y `studio-inspector.spec.ts`. Emojis y longitud siguen probados con el catálogo literal.

## Bloque D · Cuatro ojos por regla (`c374438`)

- **Comprobado contra el CRM:** el `message` del servidor ya se ve en los cinco sitios: la fila «Cuatro ojos» de la vista previa (`requirementRows`), los 403 de publicar, aprobar y promover, y los motivos de `actions` en el detalle (`motivoSinAprobar`, `motivosDePublicacion`). En el detalle no hay más que pintar: `actions` trae el mensaje, no la lista.
- **Lo que faltaba, la lista:** una frase por regla, sin repetir el mensaje (que ya dice la acción y «otra persona»). Dice la regla con su descripción en la versión, qué revisión copia, cuáles escribiste y si el libro no lo prueba. Está en `ultron-studio/studio-publication-text.ts:367` y `:377`. Ejemplo: «S-003 «Mensajes cortos»: la versión copia su revisión 2; escribiste la revisión 2.» o «S-007: la versión copia su revisión 3; no consta en el libro quién escribió todas sus revisiones.».
- **Lector:** `studio-parse.ts:1039` y `:1054` (`requirements.rulePhraseAuthor`, solo si viene). Es estricto como el resto de la vista previa: su `ok` casa con la lista, y «Cuatro ojos» no se cumple si ella no se cumple (`:1073`). `:2621` lee el 403 de forma suave.
- **Dónde se pinta:** bajo los requisitos del diálogo de publicar (`studio-publication-dialog.ts:110`, `aria-label` «Cuatro ojos, regla a regla»); en los 403 de publicar (`:419`), aprobar (`studio-versions-panel.ts:332`, `role="alert"`) y promover (`studio-canary-evidence.ts:86`).
- **Con el servidor de hoy no cambia nada:** probado con la vista previa sin la clave y con el 403 sin `detail`. No he tocado `testing/backend-publication.testing.ts`.

## Ronda 2 (de mis hallazgos fuera de alcance)

1. **El aviso del reclamo** (`cd1e33d`, `commercial-automation-session.ts`):
   - Si el 201 o el 200 repetido traen `contactWithheld`, el aviso es «Trabajo reclamado, pero el servidor retiene el contacto. <texto del motivo de la tabla>» (o «Ese trabajo ya era tuyo, pero…»). Antes decía siempre «Trabajo reclamado: ahora es tuyo hasta registrar el resultado.».
   - Es nivel `warn` y dura 12 s (`CA_AVISO_RETENIDO_MS`). También con `brake`, lo único que manda el servidor de hoy, y con «Sin reclamar», donde la fila desaparece y el aviso queda.
2. **Callan la voz** (`f911f5d`; la voz compila tono, objetivo comercial, «Si no entiende» y las frases de las reglas, `StudioVoiceCompiler.php:24`, `:410`):
   - «Motor ahora» de Personalidad, con la llave apagada: «…: está fijo en código o en n8n.» → «Nada de esta capa se lee en vivo: en WhatsApp está fijo en código o en n8n. En la voz, el tono sí entra en el agente compilado al publicar la voz.». Con la llave encendida o ilegible, se añade la misma frase de la voz.
   - Diálogo de publicar, llave apagada (`noEffectText`, `noEffectParamText`): la ayuda pasa de «…el motor no los aplica en este hito.» a «…ULTRON por WhatsApp no los aplica en este hito. En la voz, el tono, el objetivo comercial, «Si no entiende» y las frases prohibidas de las reglas sí entran en el agente compilado al publicar la voz.». Esos tres parámetros dicen «ULTRON por WhatsApp no lo aplica en este hito; en la voz sí entra en el agente compilado al publicar la voz»; los demás conservan «el motor no lo aplica en este hito».
3. **`strategy_stopped`** (`f41b4cf`): «(pausada, apagada, revertida o archivada, …)» → «(pausada, apagada, revertida, retirada o archivada, …)», como `AssistedQueue.php:46-48`.
4. **Importes diminutos del clasificador** (`ead3f3e`, `classifierUsdText`): lo que `formatMicroUsd` redondea a cero sin serlo (menos de 50 micro-US$) sale «menos de US$ 0,0001», la unidad más pequeña que ya enseña el formato («menos de US$ 0,01» escondería importes que hoy se ven). El cero de verdad sigue siendo «US$ 0,00».

## Bloque E · El número empresarial de WhatsApp como origen de la voz (`c47ba62`)

- **Comprobado contra `cierre/telefonia`:** `VoiceTelephonyStatus.php:77` manda `number.sharedWithMessaging = {declared, admitted, reason}`. `VoiceNumberGuard::SHARED_REASONS` tiene **ocho** motivos, no los siete de tu lista: falta `observed_not_business_number`, de la ronda 2 del backend (`cd9a8dc6`; el §6 de su informe es anterior).
- **Lector** (`studio-voice-parse.ts`, `numeroCompartido`): tolerante, como `inboundCapable`. Sin el campo, ilegible o contradictorio (admitido con motivo o sin declarar), `null`: todo como hoy, y falla cerrado.
- **Activación** (`studio-voice-activation.ts`, `twilioYNumero`): coincidir con WhatsApp es un error solo si no está admitido.
  - Admitido: salientes «operativa», con «El origen es el número empresarial de WhatsApp, admitido solo para salientes y sin agente asignado.». Las entrantes siguen con su pieza del proveedor.
  - Declarado y no admitido: «…está declarado como compartido, pero el servidor no lo admite: <qué falta>. Hasta entonces la voz no lo usa.» (los ocho motivos, en `studio-voice-text.ts`; uno desconocido, «el servidor no lo admite (motivo: x)»).
  - Sin declarar: el error de siempre.
- **Panel de telefonía** (`studio-voice-status.ts`): admitido, sin el chip de error y con la fila «Compartido con WhatsApp»: «El número de voz es el número empresarial de WhatsApp. Está admitido solo para llamadas salientes, sin agente asignado; no recibe llamadas.». Declarado y no admitido, la fila dice qué falta. Sin el campo, sin fila.
- **No he tocado `testing/backend-voice.testing.ts`:** dice «No editar a mano», porque lo genera `gen-backend-voice-3.py` desde respuestas capturadas. Las pruebas añaden el campo sobre la captura. Para que la captura lo traiga hay que regenerarla desde el backend nuevo.

## Bloque F · Con la llave de prompts encendida (`a9e7e7a`)

- **Comprobado:** la voz compila «Si no entiende» y las frases de las reglas con el MISMO filtro que el compilador 2 de WhatsApp (`StudioRulePhrases::byRule`; `StudioPromptBundle.php:177`, `:202`; `StudioVoiceCompiler.php:773`).
- **Compilador 1:** la regla pasa de «…no tienen efecto en WhatsApp.» a «…no tienen efecto en WhatsApp. En la voz, sus frases prohibidas sí entran en el agente compilado.»; la ayuda nombra «Si no entiende» y las frases.
- **Compilador 2:** «Esta regla no aporta frases a WhatsApp ni a la voz: …», porque el filtro es el mismo; la ayuda dice que «Si no entiende» sí entra en la voz.
- **«Si no entiende»:** con los dos compiladores, «el motor no lo aplica en WhatsApp; en la voz sí entra en el agente compilado al publicar la voz».

## Pruebas (`npx ng test --watch=false --include=…`) y compilación

- **Por bloque:**
  - A, 68 (el ajuste, 68 también); B, 74; C, 172;
  - D, 9 ficheros en verde. Los 3 rojos de la primera pasada eran del spec: la S-003 de ejemplo iba `active`; con `draft`, 17 de 17;
  - ronda 2: `ca-assisted` 47, los 7 del punto 2 147, `ca-panel` 28 (antes corregí una aserción mía mal escrita);
  - campos nuevos: los 3 de B, 81 (`ca-panel`, 34);
  - E: `studio-voice-parse`, `-activation`, `-status` y `-parity`, `Tests 95 passed (95)`; F: 4 ficheros, `Tests 81 passed (81)`.
- **Módulos enteros:**
  - por el camino hubo rojos solo por «timed out» con la máquina cargada; repetidos aislados, todos en verde (`58 passed (58)` y `25 passed (25)`);
  - al final (con A–F): `ultron-studio` `90 passed (90) · 1419 passed (1419)`; `commercial-automation` `20 passed (20) · 676 passed (676)`. Antes de E y F, `ultron-studio` dio un «timed out» en `ultron-studio-page.spec.ts` (no tocado); aislado, `25 passed (25)`.
- `node scripts/check-literals.mjs`: `422 ficheros revisados · literales correctos`.
- **Compilación:** `npx ng build --configuration=production` → `Application bundle generation complete`, sin errores, después de C, de D, de la ronda 2 y de E y F. Solo los avisos de presupuesto y NG8107 de siempre. No usé `build:prod`, porque sella el código fuente.

## Lo que NO he podido verificar

- **La app en un navegador:** no la he corrido, ni contra un servidor real.
- **El bloque `classifierSetup`:** lo he construido a partir de §11 y del código de `OutreachConfig::classifierSetup()`/`OutreachReadModels` (rama `cierre-motores`), no de una respuesta real.
- **Lectores de pantalla:** no he probado ninguno. He mantenido los mismos elementos y roles: `role="status"` en la bandeja, la fila `li` de la bandera, `role="alert"` en «Antes de aplicar».
- **El incidente `outreach_classifier` en el CRM:** no he encontrado un panel de incidentes con textos por tipo. No sé cómo se ve el tipo nuevo.
- **D en la voz:** la voz enseña el `message` del 403 al publicar y promover (`studio-voice-publications.ts:416`, `:435`), pero no pinta la lista, que no estaba en el encargo. Tampoco he probado la vista previa B-2 con una respuesta real del servidor; la he construido a partir de `StudioPublications.php:956` y `:973`.

## Hallazgos fuera de alcance (no tocados; los anteriores quedaron resueltos en la ronda 2 y en F)

1. **(f) La pista de emojis:** queda como límite. El catálogo está congelado y el CRM no tiene capa de pistas.
2. **Bandeja, para security:** aunque el texto ya no lo diga, el código `subject_minor` sigue llegando a quien reclama en la respuesta (`contactWithheld`). El CRM lo repite en `data-ca-asistido-retenido`. Ocultarlo es cosa del servidor; el atributo no cambia lo que ya se ve en la red.
3. **Bandeja, los otros diez textos:** no son los de §9.4 al pie de la letra. Dicen lo mismo, pero en el registro del módulo («…: no contactes a esta persona… Si ya hablaste con ella, registra el resultado.»). Si se quieren literales, es una línea por motivo.
4. **Para reviewer:** `commercial-automation-api.types.ts` ahora importa de `commercial-automation-text.ts` en tiempo de ejecución, para reexportar la lista. No crea ningún ciclo: `text.ts` solo importa tipos de `types.ts` y `trace-text.ts`, que no importa nada.
5. **Para tester**, los flujos críticos: reclamar → 201 con motivo → el aviso con el motivo y la fila retenida (también con «Sin reclamar»); el panel con cada estado del clasificador; «Antes de aplicar» de «ULTRON encendido»; aprobar, publicar y promover con un 403 B-2; el panel de voz con el número compartido admitido y sin admitir.

# Informe FE · Estados y frases del CRM que no decían la verdad (cierre, 11-11-2026)

**Agente:** frontend · **Worktree:** `Iron-Body_Front`, rama `feat/ultron-studio-h9`, HEAD `5f2a454` · **Sin commit, sin push, sin `build:prod`, sin despliegue, sin tocar el backend.**
Fuentes: auditoría B §4.8 (y §1, §2, §4.1, §4.5, §4.7) y auditoría C §4.8 (y §1, §4.1, §4.4, §4.6).

## Resumen para el lead

- **Los 22 puntos están corregidos** (B1…B9, C1…C13), solo en los ficheros permitidos. No he cambiado ni los seis nombres de estado ni la precedencia global.
- **El plan de ElevenLabs ya no aparece como condición en ninguna parte del CRM.** Lo he comprobado con `grep` sobre todo `src/` (sin contar las pruebas): ninguna frase visible dice «plan de pago», «minutos de agente», «D-4» ni «sin plan». Solo quedan dos comentarios de código, `studio-voice-activation.ts:85` y `:259`, que dicen que el plan está contratado. La leyenda de «Pendiente de proveedor externo» ya no dice «un plan».
- **Cambios de estado** (antes → ahora; el motivo de cada uno está en la tabla):
  - WhatsApp automático: proveedor → **condición contractual** mientras no haya nada en revisión en Meta.
  - Voz automática (V9): proveedor → **condición contractual**.
  - Laboratorio apagado o en simulador: proveedor → **lista**.
  - Laboratorio encendido sin ninguna sesión real: proveedor → **lista**.
  - Publicación de voz: ahora queda en **proveedor** por la pieza del número importado (C12).
  - Un servidor que no declara la puerta de W9 o V9 pasa a **error de despliegue** (antes era la «puerta vacía»).
- **Tanda 2 (al final del informe): los dos cableados que siguen están HECHOS, y también los cuatro adyacentes**: el monitor apagado, «Crear versión corregida», el tono del clasificador y `T.fase2`. Además, el monitor encendido ya dice cómo salió su última vigilancia y hasta cuándo vale la verificación del proveedor. Lo que sigue es el estado tras la tanda 1.
- **Hacen falta dos cambios en ficheros que no me tocaban.** No los he hecho. Sin ellos, B1 y B3 quedan correctos pero a medias:
  1. **`commercial-automation-page.ts:105` y `:208`.** El aviso de la cabecera es una constante.
     - Lo he dejado en una frase que siempre es verdad: no afirma ni el modo ni la constante.
     - La frase que dice el modo real ya existe: `sendsText(config)`, en `commercial-automation-text.ts:52`, con sus pruebas.
     - Falta usarla en la página, con dos líneas: `protected readonly sinEnvios = computed(() => { const c = this.session.status.value()?.config; return c ? sendsText(c) : TEXTO_SIN_ENVIOS; });` y `{{ sinEnvios() }}`.
  2. **`ca-activation-panel.ts`.** El panel de activación no recibe los envíos a Meta.
     - La derivación ya acepta un `metaTemplates?` opcional.
     - Hay que pasarle `metaTemplates: lectura(this.session.metaStatus)` y, con el gobierno encendido, llamar a `loadMetaStatus()` si la lectura está en `idle`.
     - Mientras no se haga, la fila de WhatsApp no afirma nada de Meta y lo declara como dato que falta. En producción eso da hoy «condición contractual», que es lo correcto.
- **Pruebas:** 11 ficheros de prueba, **149 pruebas en verde**. `tsc` de la app y de las pruebas sale con 0, y `check-literals` también.
  - No he lanzado la batería completa, como se pidió.
  - No he levantado la app ni he mirado nada en un navegador.

## Tabla antes → después

«Estado» es el que sale **hoy en producción**, con el estado que describen las auditorías, salvo que se diga otra cosa.

| # | Antes | Después | Estado que deriva | Fichero:líneas |
|---|---|---|---|---|
| **B1** | «…la automatización nace apagada…» (constante del aviso) | **Sin el estado leído** (`TEXTO_SIN_ENVIOS`): «Ningún mensaje ni llamada sale solo mientras el envío automático siga cerrado en el código (OutreachSendGate). Si lo está, y en qué modo está la automatización (apagada, en sombra o en vivo), lo dice el estado del servidor en «Panel».» **Con el estado** (`sendsText`), en sombra: «Hoy no sale ningún mensaje ni llamada automática: el envío automático está cerrado en el código (OutreachSendGate). La automatización está en sombra: decide y simula, sin contactar a nadie; el asistido está apagado.» También cubre apagada, en vivo, el asistido con su tope y la constante abierta | — (aviso) | `commercial-automation-text.ts:1-11` (import), `:39-71`. **Falta cablearlo en la página (ver Resumen)** |
| **B2** | «Ninguna estrategia publicada: falta que otra persona vinculada publique…» | «Ninguna estrategia publicada. Antes hacen falta dos personas vinculadas y verificadas (**hoy N**), una plantilla aprobada a cuatro ojos y una versión de la estrategia aprobada por otra persona con su simulación. Las dos primeras personas las vincula el equipo técnico por consola, con tu autorización escrita.» Con 1 persona o más, dice dónde se ven los vínculos. Con 2, «Las dos personas vinculadas ya están…» y dónde se ven las publicadas. Si la cuenta no puede leer los vínculos: «(tu cuenta no puede contarlas)», más un dato que falta | Contractual | `ca-activation.ts:161-193` (`personasVerificadas`, `huecoPersonas`, `sinPublicadasTexto`), `:273-284` |
| **B3** | WhatsApp «Pendiente de proveedor externo» (Meta), sin nada enviado | Proveedor **solo** si hay un envío esperando a Meta: `counts` PENDING, IN_APPEAL o UNKNOWN (enviado y sin estado de Meta), o `submissions.SENDING`. Con alguna aprobada, Meta deja de faltar. Sin nada en revisión: contractual, «Ninguna plantilla de WhatsApp aprobada por Meta ni pendiente de su revisión: antes la escribe una persona vinculada, la aprueba otra (cuatro ojos) y una persona vinculada la envía a Meta, que la revisa (personas vinculadas: hoy N).» Sin leer `meta/status`: contractual («Hace falta una plantilla de WhatsApp aprobada por Meta antes de abrir el envío: …»), más un dato que falta | Contractual (hoy) | `ca-activation.ts:44-49` (entrada opcional), `:65` (ruta), `:195-247` (`enviosAMeta`, `plantillasDeMeta`), `:337-355`. **Precedencia global sin tocar** |
| **B4** | «Programa las pasadas de barrido y despacho; todavía no ha terminado ninguna.» | «Programa el barrido, el despacho y el monitor. No hay barridos porque no hay ninguna estrategia publicada: el barrido solo recorre las de la cadena firmada. Último despacho: ‹fecha›.» Variantes: con el monitor apagado; con un barrido anterior («el último barrido terminó el…; ahora no hay ninguna estrategia publicada que barrer») | Disponible y operativa | `ca-activation.ts:413-437` (`programadorOperativoTexto`) |
| **B5** | W9: «…hoy su puerta está vacía y falla cerrada» (rama muerta) | Rama actualizada para W9 y V9: «El servidor dice que el canal de WhatsApp por plantillas (W9) [o de voz por estrategia (V9)] no tiene su puerta (OutreachSendGate): hay que revisar el despliegue.» Es el mismo criterio que ya seguía el asistido. W9 y V9 están en `OutreachSendGate::LIVE_CHANNELS` (lo comprobé en `OutreachSendGate.php:46`) | Error técnico real (solo con un backend sin W9 o V9) | `ca-activation.ts:338-344` |
| **B6** | «Clasificador de respuestas (AI9)» · «Apagada»/«Encendida» | Etiqueta: «Clasificador de respuestas (AI9): no está implementado; la bandera no hace nada». Encendida, el chip dice «Encendida, sin efecto» | — (bandera) | `ca-panel.ts:66-67`, `:839-840` |
| **B7** | «Conectada · Graph v25.0 · …» con cable continuo, sin haber comprobado nada | Sin comprobar: «Configurada, sin comprobar con Meta · Graph v25.0 · ‹credencial›», cable discontinuo, y el detalle «Sin comprobar en las últimas 24 h. «Comprobar permisos» exige una cuenta con persona vinculada.» «Conectada» **solo** con `lastProbe.outcome = ok`. Si la última comprobación salió con otro resultado (red, `error`, algo desconocido): «Configurada, la última comprobación con Meta no salió bien · …» | — (franja) | `ca-meta-text.ts:366-397` |
| **B8** | «Nueva versión de plantilla», «Crear una versión» y «Comprobar permisos» se ofrecían a cuentas sin vínculo, que recibían un 403 | Los tres botones siguen visibles pero **desactivados**, con `aria-describedby` apuntando a un motivo en texto, con candado `aria-hidden` (no depende del color): «Necesitas una persona vinculada a tu cuenta para crear una versión de plantilla: el servidor exige saber qué persona la escribe (cuatro ojos).» y «Necesitas una persona vinculada a tu cuenta para «Comprobar permisos».» Usan `session.personBound()` | — (acciones) | `ca-templates.ts:9`, `:20`, `:51-57`, `:68-72`, `:109`, `:164-167`. Textos: `commercial-automation-text.ts:84-85`, `ca-meta-text.ts:369-370` |
| **B9** | «Crea la primera: nace en borrador y no hace nada hasta que se publique.» | Añadido: «Congelar, aprobar y publicar exigen persona vinculada; tu cuenta la tiene / no la tiene.» | — (lista vacía) | `ca-strategies.ts:58`, `:153-157` |
| **C1** | «Falta el plan de pago de ElevenLabs … (D-4)» y, aparte, «Después, encenderlo…» | «El laboratorio está apagado en el servidor (VOICE_LAB_ENABLED): lo enciende el equipo técnico con el mando de voz («lab on --driver=elevenlabs», con su presupuesto y sus topes), con tu autorización escrita. Encendido, aquí se ve cada cerrojo que le falte.» Si ElevenLabs no está verificado, esa falta va primero, como antes | Proveedor → **Lista** | `studio-voice-activation.ts:86-87`, `:259-271` |
| **C2** | Simulador, primera sesión, veredicto y los dos datos que faltan dependían del plan | **Simulador:** «…falta pasarlo a ElevenLabs: lo hace el equipo técnico con el mando de voz (…), con tu autorización escrita (paso 2a.5)» (estado lista). **Primera sesión:** «Falta una sesión real (gasta del presupuesto del laboratorio): la abre una persona desde el laboratorio hablado y queda comprobado cuando llega el informe de ElevenLabs de esa conversación (paso 2a.7).» (estado lista: la hace una persona, no el proveedor). **Veredicto sin laboratorio legible:** «Falta el veredicto «aprobado» del laboratorio hablado, de otra persona, sobre una sesión real de la misma compilación (pasos 2a.7 y 4a.11).» (proveedor → contractual). **Datos que faltan:** sin plan; ver los `ActivationGap`. Además he quitado «, sin plan» de 4 frases de ElevenLabs | Lista o contractual | `studio-voice-activation.ts:88-94`, `:120`, `:191`, `:258-291`, `:337-347` |
| **C3** | V9: «…y estas, el plan de pago de ElevenLabs.» (además, rama muerta: V9 ya es `live`) | Con el canal `live`: «Las llamadas por estrategia salen por la voz del Hito 8, que antes necesita un número de voz que no sea el de WhatsApp del gimnasio y la credencial de Twilio de la voz (los consigues tú en la consola de Twilio), y las autorizaciones de la fase de llamadas: el commit voice(calls-open) y «salientes on». Su estado está en Voz y telefonía › Estado de activación.» Más un dato que falta | Proveedor → **Contractual** | `ca-activation.ts:81-84`, `:349-356` |
| **C4** | «Falta el número de voz … verificado en la subcuenta (D-3…)» | «Falta un número de origen que no sea el de WhatsApp del gimnasio (TWILIO_VOICE_FROM): el de WhatsApp está protegido como número de mensajería y la voz no lo usa. Lo consigues tú en la consola de Twilio: verifica otro número como identificador de llamada (sin coste) o compra uno; Twilio no tiene nada pendiente. Se ve en Estado › Telefonía («Número de voz»).» Sin cifras | Proveedor | `studio-voice-activation.ts:97-98` |
| **C5** | «Falta el id del número importado … (D-2…)» | Añadido: «Importar el número de WhatsApp del gimnasio no sirve: se importa el número nuevo, como solo saliente y sin agente. Lo haces tú en la consola de ElevenLabs y el equipo técnico carga su id con el mando (paso 4a.2).» | Proveedor | `studio-voice-activation.ts:100-101` |
| **C6** | «Falta Twilio Voice: la subcuenta y sus claves…» y la fase 2a «falta la subcuenta de telefonía y el número» | «Falta la credencial restringida de voz de Twilio (una API key solo de voz; se recomienda una subcuenta «Voz», sin coste, con un número que no sea el de WhatsApp del gimnasio). La creas tú en la consola de Twilio y la carga el equipo técnico con el mando; Twilio no tiene nada pendiente y el código no exige subcuenta. Se ve en Estado › Telefonía («Credencial de Twilio de la voz»).» Fase 2a: «…faltan la credencial restringida de voz de Twilio (se recomienda una subcuenta) y un número que no sea el de WhatsApp del gimnasio…» | Proveedor | `studio-voice-activation.ts:95-96`; `studio-voice-text.ts:138-142` |
| **C7** | «Subcuenta de Twilio: Sin configurar» | «Credencial de Twilio de la voz (cortar y conciliar): Sin configurar». El valor tiene `data-voz-credencial-twilio` | — | `studio-voice-status.ts:81` |
| **C8** | Línea, Twilio Voice: «Apagado» | Sin número ni credencial: «Sin configurar: faltan el número y la credencial; todavía no se puede encender». Si falta una sola pieza, la nombra. Con las dos y la bandera apagada: «Apagado». Un servidor sin `telephonyState` (H6) no afirma que falte la credencial | — (línea) | `studio-voice-text.ts:188-196`, `:202-212`, `:217` |
| **C9** | Línea, Salientes: «Apagadas» | «Cerradas: esperan un número que no sea el de WhatsApp del gimnasio, la credencial de Twilio de la voz, el commit autorizado que abre las llamadas en el código (voice(calls-open)) y una voz publicada; después, encenderlas con tu autorización escrita». Solo nombra lo que el estado permite afirmar. Con todo lo demás listo: «Cerradas: solo falta encenderlas («salientes on»), con tu autorización escrita» | — (línea) | `studio-voice-text.ts:220-242` |
| **C10** | Línea, Entrantes: «Apagadas» | Sin número, o con el número declarado solo saliente (`number.inboundCapable = false`, que llega de la telefonía): «No disponibles: no hay un número propio de Twilio con voz». Con número y sin declarar: «Apagadas» | — (línea) | `studio-voice-text.ts:213`; quien llama a la función pasa `inboundCapable` en `studio-voice-status.ts:200-201` |
| **C11** | Monitor: «Concilia las llamadas cada 10 minutos y comprueba el número cada hora…» | «Encendido: vigila cada hora el webhook post-llamada y sus secretos en ElevenLabs, y el techo de gasto del día; si algo no cuadra, echa el freno.» A continuación, según `carrierConfigured` e `importedNumberIdConfigured`: «Conciliará las llamadas cuando esté la credencial de Twilio de la voz, y el número cuando esté su id importado en ElevenLabs.» (hoy). Hay tres variantes más, una por cada pieza presente. Lo verifiqué en `VoiceReconciler.php` (`run()` y `numbers()`) | Disponible y operativa (sin estado «en observación» en la taxonomía) | `studio-voice-activation.ts:449-452`, `:463-479` |
| **C12** | Publicación: solo el veredicto y el cupo | Nueva falta: «Publicar exige el id del número de voz importado en ElevenLabs (ELEVENLABS_AGENT_PHONE_NUMBER_ID), y no lo hay: un número que no sea el de WhatsApp del gimnasio, importado como solo saliente. Lo importas tú en la consola de ElevenLabs y el equipo técnico carga su id con el mando; ElevenLabs no tiene nada pendiente.» Sale de `telephonyState.numberImported` (ruta 45) | **Proveedor** (hoy manda sobre el veredicto y el cupo) | `studio-voice-activation.ts:121-122`, `:335-363` |
| **C13** | Salientes y Twilio como «Pendiente de proveedor externo», sin decir quién | Se queda el estado. Cada frase de pieza de consola dice «Lo consigues tú / La creas tú / Lo haces tú en la consola de…» y «‹proveedor› no tiene nada pendiente» (C4, C5, C6, C12, y C3 en la automatización) | Proveedor | Mismas líneas que C4, C5, C6 y C12 |
| Regla 3 | Leyenda de «proveedor»: «…un plan, una verificación, una pieza o una aprobación.» | «Espera algo de ElevenLabs, Twilio o Meta: una verificación, una pieza o una aprobación.» El ejemplo del comentario de `ActivationGap` ya no habla del plan | — | `activation-state.ts:44`, `:68` |

**Sobre C12:** `missingPieces` solo viaja en `compile-preview` (`deployable.missing`), y el panel de activación no lo lee. He usado `telephonyState.numberImported` de la ruta 45. Lee la misma variable, `voice.elevenlabs.agent_phone_number_id` (`StudioIntegrations.php:442`), pero con un matiz: `missingPieces` además exige que el id tenga forma válida (`VoiceIds::providerIdOrNull`). Un id mal formado daría `numberImported = true` y seguiría faltando para publicar.

## `ActivationGap` declarados (datos que no llegan a la derivación)

| Dónde | Lo que no se sabe | Ruta que lo daría | Campo |
|---|---|---|---|
| Sombra, sin estrategias y sin poder leer los vínculos (B2) | Cuántas personas vinculadas y verificadas hay. La lista de vínculos exige `ultron.person_bindings_manage` | `GET admin/ultron-studio/outreach/status` | **Propuesta:** `personBindings.verified`, un recuento sin datos personales |
| Canal WhatsApp, sin leer `meta/status` (B3) | Si hay alguna plantilla enviada a Meta o ya aprobada | `GET admin/ultron-studio/strategies/templates/meta/status` | `submissions` y `counts`. **El servidor ya los da:** falta cablear `ca-activation-panel.ts` (ver Resumen) |
| Canal voz (C3) | Si la voz del Hito 8 ya puede llamar | `GET admin/voice/status` | `number.configured`, `carrierConfigured` y `callsEnabledInCode`. Este panel no los recibe. **Propuesta:** `config.channels.voice_call.telephonyReady` en `outreach/status` |
| Laboratorio apagado (C1) | Qué cerrojos tendría al encenderlo: apagado, el servidor no los calcula | `GET admin/ultron-studio/voice-lab/status` | `data.reasons` con `enabled:false` |
| Laboratorio en simulador (C2) | Qué cerrojos tendría con ElevenLabs: los de ahora son los del simulador. Lo comprobé en `StudioVoiceLabConfig::reasons()`, que depende del driver | `GET admin/ultron-studio/voice-lab/status` | `data.reasons` del driver `elevenlabs` con `mode:fake` |

He **quitado** dos datos que faltaban porque ya no son ciertos:
- «si el plan de ElevenLabs tiene minutos de agente» (`data.plan`);
- `config.channels.whatsapp_template.approvedTemplates`. Lo sustituye `meta/status`, que el servidor ya expone.

**Sin `ActivationGap`, porque no es una fila de activación:**
- **B6.** «No implementado» es un hecho del código, no del estado. Para derivarlo haría falta, por ejemplo, `config.classifierImplemented` en `GET outreach/status`.
- **C11.** El servidor ya manda `webhookHealth` y `providerVerification` en `GET admin/voice/status`, pero `VoiceTelephonyStatusDto` y su lector (`studio-voice-parse.ts`) no los leen. Con ellos, el monitor podría decir cómo salió su última vigilancia.

## Pruebas y comprobaciones (salida real)

Cada ejecución, con `bash -c "nice -n 10 npx ng test --watch=false --include=…"`, un solo proceso cada vez:

| Ejecución | Ficheros | Resultado |
|---|---|---|
| 1 | `commercial-automation/ca-activation.spec.ts` | **1 fichero, 28 pruebas en verde.** Repetida tras el último ajuste: 28 en verde |
| 2 | `ca-meta-text`, `ca-templates`, `ca-panel`, `ca-strategies` (nuevo) y `commercial-automation-text` (nuevo) | **5 ficheros, 46 pruebas en verde** |
| 3 | `ultron-studio/studio-voice-activation`, `studio-voice-status` y `activation-state` | **3 ficheros, 56 pruebas en verde** |
| 4 (regresión) | `ultron-studio/studio-voice-page.spec.ts` y `commercial-automation/commercial-automation-page.spec.ts` | **2 ficheros, 19 pruebas en verde** |

- `npx tsc -p tsconfig.app.json --noEmit` → código 0.
- `npx tsc -p tsconfig.spec.json --noEmit` → código 0.
- `node scripts/check-literals.mjs` → «417 ficheros revisados · literales correctos», código 0.
- El proyecto no tiene ESLint configurado.
- Los avisos NG8107 que salen al compilar las pruebas son de ficheros ajenos (`marketing-inbox-v2`, `reports`, `electronic-invoice-detail`, `receivables`).

**Pruebas que fijaban una frase o un estado falso y he cambiado** (cada una lo dice en un comentario):
- `ca-activation.spec.ts`:
  - la fase 1 tenía WhatsApp y voz en «proveedor»;
  - fijaba «Falta W9» y «Falta V9 … plan de pago de ElevenLabs»;
  - fijaba el campo `approvedTemplates`;
  - fijaba «el último barrido terminó» en minúscula;
  - la prueba del componente esperaba `whatsapp:proveedor` y `voz:proveedor`.
- `ca-meta-text.spec.ts` y `ca-templates.spec.ts`: fijaban «Conectada» y «Sin comprobar en las últimas 24 h: pulsa «Comprobar permisos».» con `lastProbe: null`.
- `studio-voice-activation.spec.ts`:
  - fijaba el plan con minutos (D-4), `data.plan` y «sin plan»;
  - fijaba los estados «proveedor» del laboratorio apagado, en simulador y sin sesión real;
  - fijaba el veredicto «con plan» (2a.7) como proveedor;
  - fijaba los prefijos «Falta Twilio Voice: la subcuenta…» y «Falta el número de voz (TWILIO_VOICE_FRO…».

**Fixture compartida:** la de `testing/ca-examples.testing.ts` (`configEjecucion()`) es del 10-11 y trae W9 y V9 con `live:false`. No la he tocado, porque no estaba en mi lista. En el spec de activación, los canales se declaran como en el servidor desplegado (`CANALES_H9`), y el caso `live:false` se prueba aparte (B5).

**Pruebas nuevas:**
- escenarios «la producción del 11-11» para las dos secciones;
- B3 con los envíos a Meta leídos: nada, en revisión, saliendo, solo rechazadas, aprobada;
- B5, B6 (encendida y apagada), B7 (sin comprobar y con la comprobación fallida) y B8 (botones desactivados, `aria-describedby`, motivo en texto, ninguna petición al pulsar);
- B9, con y sin persona;
- B1 (`sendsText` y la constante);
- C4, C5, C6 y C13, cada frase entera y sin cifras;
- C8, C9 y C10 en la línea, de forma pura y montada (la telefonía pasa `inboundCapable`);
- C11, las cuatro combinaciones;
- C12, con y sin el número importado.

## Lo que no pude verificar

- **La app en un navegador.** No la he levantado: lo único visual nuevo son el motivo bajo el botón y la franja «discontinua». Hay que revisar con capturas:
  - que el motivo `ig-reason` debajo de «Nueva versión de plantilla» y junto a «Comprobar permisos» se ve bien en un ancho estrecho y en uno ancho. El estilo es el existente del kit;
  - el cable discontinuo de la franja de Meta.
- **El efecto real en producción.** Las pruebas reproducen el estado que describen las auditorías, con dos supuestos que van dichos en las propias pruebas:
  - los eventos comerciales encendidos en la automatización;
  - la telefonía con su verificación propia de ElevenLabs en la voz.
- **B1 y B3 completos.** Dependen de los dos cableados fuera de mi lista (ver Resumen).

## Adyacentes (vistos, no arreglados)

1. **Monitor de voz apagado.** `studio-voice-activation.ts:453-461` todavía pide la credencial de Twilio para encender el monitor («La conciliación lee a Twilio y a ElevenLabs. …», como proveedor). Producción lo encendió sin ella: el bloqueo es falso. C11 solo cubría el monitor encendido.
2. **«Crear versión corregida»** (`ca-tpl-detail.ts:143`) se ofrece también sin persona vinculada. Es el mismo 403 que en B8, pero ese fichero no estaba en mi lista.
3. **Tono del chip del clasificador.** Encendido, sigue en `live` (`tonoBandera` solo recibe el valor). El texto ya dice «sin efecto». Cambiar el tono exige tocar la plantilla del panel.
4. **Frase de la fase 2 de la sombra.** `ca-activation.ts` (`T.fase2`) sigue diciendo «dos personas vinculadas y verificadas por consola». Solo las dos primeras van por consola; las siguientes se crean en el CRM.
5. **Números canarios.** `studio-integrations-text.ts:59` y `:145` dicen «El motor no los lee en este hito» (auditoría B §4.8). Es del otro agente; no lo he tocado.


---

# Tanda 2 · Cableados y adyacentes (11-11-2026)

## Resumen para el lead (tanda 2)

- **Los siete puntos están hechos**, con las mismas reglas: todo sale del estado del servidor; las pruebas usan las clases reales; un proceso de pruebas cada vez.
- **No he parado en el punto 7.** No era grande ni tocaba contratos de paridad:
  - `webhookHealth` y `providerVerification` son aditivos.
  - La fixture capturada (`af6bd805`) no los trae, y la prueba de paridad no cambia.
  - Sigo el precedente de `number.check` (B8-h) y de `today`. Si el bloque llega con forma, se lee estricto: estado cerrado, coherencia entre estado y datos, códigos e instantes. Si falta o llega con otra forma, queda «sin saberse» y no tumba la telefonía.
  - Los dos conjuntos nuevos (`VOICE_WEBHOOK_HEALTH_SET` y `VOICE_PROVIDER_MARK_SET`) **no** van en `VOICE_SETS`, ni tienen paridad automática con el backend. Repiten las constantes PHP `VoiceWebhookHealth::STATE_*` y `VoiceProviderState::MARK_STATES`. Si se quiere paridad, hay que añadirlos al generador de la fixture: es cosa del backend.
- **Pruebas:** 15 ficheros, **194 pruebas en verde**, en 3 ejecuciones. `tsc` de la app y de las pruebas sale con 0, y `check-literals` también.
- No he levantado la app. No he hecho commit, push, `build:prod` ni despliegue.

## Antes → después

| # | Antes | Después | Estado | Fichero:líneas |
|---|---|---|---|---|
| 1 | La cabecera mostraba la constante `TEXTO_SIN_ENVIOS` | `sinEnvios = computed(…)`: con el estado leído, `sendsText(config)`; sin él, la regla que siempre se cumple. En sombra: «Hoy no sale ningún mensaje ni llamada automática: el envío automático está cerrado en el código (OutreachSendGate). La automatización está en sombra: decide y simula, sin contactar a nadie; el asistido está apagado.» | — | `commercial-automation-page.ts:26`, `:106`, `:210-213` |
| 2 | El panel de activación no recibía los envíos a Meta | Pasa `metaTemplates: lectura(this.session.metaStatus)` y, con el gobierno encendido y la lectura en `idle`, llama una vez a `loadMetaStatus()`. La ruta solo exige `ultron.view` y el gobierno encendido (`UltronStudioMetaTemplateController::status`, `StudioAccess::requirePerson`, que **no** pide persona vinculada). El canal de WhatsApp distingue ya con el dato real: nada enviado → contractual; en revisión → proveedor. Si la lectura no está, el dato que falta dice por qué: este panel no la recibe, el gobierno está apagado, todavía se lee, la cuenta no puede leerla (403) o la lectura falló (código) | Contractual o proveedor, según el dato | `ca-activation-panel.ts:12-19` (comentario), `:44`, `:55-56`; `ca-activation.ts:238-256` (`huecoMeta`) |
| 3 | Monitor de voz apagado: «La conciliación lee a Twilio y a ElevenLabs. Falta la credencial…» (proveedor), más el bloqueo de ElevenLabs | «Apagado en el servidor (VOICE_MONITOR_ENABLED): lo enciende el equipo técnico con el mando de voz («monitor on»), que antes concilia en seco y no lo enciende si la primera pasada echaría el freno, con tu autorización escrita.» Ni Twilio ni ElevenLabs son condición: lo comprobé en `h8-voice-scope.sh:830-845`, que solo exige que la conciliación en seco no vaya a echar el freno | Proveedor → **Lista** | `studio-voice-activation.ts:113-116`, `:448-479` |
| 4 | «Crear versión corregida» se ofrecía sin persona vinculada (403 al crear) | Desactivado, con `aria-describedby="ctd-sin-persona"` y el mismo motivo de B8 en texto (`TEXTO_PLANTILLA_SIN_PERSONA`) | — | `ca-tpl-detail.ts:30-40` (import), `:153`, `:156`, `:282-283` |
| 5 | El chip del clasificador encendido se pintaba como «en vivo» (`live`) | `tonoBandera(v, key)`: el clasificador encendido sale en tono de aviso (`warn`) y apagado en `idle`. Las demás banderas no cambian. Cambio mínimo en la plantilla: se pasa `b.key` | — | `ca-panel.ts:165`, `:825-830` |
| 6 | `T.fase2`: «dos personas vinculadas y verificadas por consola» | «…dos personas vinculadas y verificadas (cuatro ojos; las dos primeras las vincula el equipo técnico por consola, con tu autorización escrita, y las siguientes se crean desde el CRM, en «Vínculos de personas»)…» | Contractual (sin cambio) | `ca-activation.ts:72-74` |
| 7 | Monitor encendido: solo lo que vigila | Añade cómo salió la **última vigilancia, con su hora**: «…, el ‹fecha›, salió bien», «…encontró problemas: ‹en llano›», o «Todavía no hay ninguna vigilancia del webhook guardada en el servidor» si llega `null`. Y **hasta cuándo vale la verificación** de ElevenLabs de la telefonía: «vale hasta el ‹fecha›», «caducó el ‹fecha›: la renueva una verificación correcta de ElevenLabs», «no tiene ninguna… guardada», o «la marca… es ilegible: cuenta como sin verificar». Si un bloque no llega o llega con otra forma, no se inventa: queda declarado como dato que falta. Con problemas, el monitor sigue «operativo» (cumple su función); el freno, si lo echa, sale en las demás funciones | Disponible y operativa | Tipos: `studio-voice-api.types.ts:1366-1393`, `:1422-1428`. Lector: `studio-voice-parse.ts:4-30` (imports), `:1291-1292`, `:1310-1347`. Textos: `studio-voice-text.ts:395-414` (`webhookProblemText`). Derivación: `studio-voice-activation.ts:448-500` |

## Datos que faltan (`ActivationGap`), tras la tanda 2

| Dónde | Lo que no se sabe | Ruta | Campo |
|---|---|---|---|
| Canal WhatsApp | Si hay algo enviado a Meta o aprobado, **solo** cuando la lectura de `meta/status` no está: todavía se lee, 403, fallo, o el gobierno está apagado | `GET admin/ultron-studio/strategies/templates/meta/status` | `submissions` y `counts`. El panel ya los lee |
| Monitor encendido | Cómo salió la última vigilancia, si `webhookHealth` no llega o llega con otra forma | `GET admin/voice/status` | `webhookHealth` |
| Monitor encendido | Hasta cuándo vale la verificación, si `providerVerification` no llega o llega con otra forma | `GET admin/voice/status` | `providerVerification` |

Los de la tanda 1 siguen igual: B2, C3, C1 y C2.

## Pruebas y comprobaciones (salida real, tanda 2)

| Ejecución | Ficheros | Resultado |
|---|---|---|
| 1 | `ca-activation`, `ca-panel`, `ca-templates` y `commercial-automation-page` | Primera vez: **1 fallo** en una prueba mía. Esperaba el motivo «gobierno apagado» pero no le pasaba `metaTemplates`, así que el motivo correcto era «este panel no los recibe». Corregida la prueba (la entrada, no la expectativa), se repitió: **4 ficheros, 65 pruebas en verde** |
| 2 | `studio-voice-activation`, `studio-voice-parse`, `studio-voice-status`, `studio-voice-parity` y `studio-voice-costs` | **5 ficheros, 83 pruebas en verde** |
| 3 (regresión) | `studio-voice-page`, `activation-state`, `ca-meta-text`, `ca-strategies` y `commercial-automation-text` | **5 ficheros, 46 pruebas en verde** |

- `npx tsc -p tsconfig.app.json --noEmit` → código 0.
- `npx tsc -p tsconfig.spec.json --noEmit` → código 0.
- `node scripts/check-literals.mjs` → «417 ficheros revisados · literales correctos», código 0.

**Pruebas que fijaban un estado falso y he cambiado** (cada una lo dice en un comentario):
- `studio-voice-activation.spec.ts`:
  - el monitor apagado como «proveedor» pidiendo Twilio, en la producción del 10-11, con el freno echado y en el componente (`monitor:proveedor` y la leyenda con 6 → `monitor:lista` y 5);
- `ca-activation.spec.ts`:
  - el campo `submissions y counts (este panel no los recibe)` pasa a `submissions y counts`, con el motivo en `what`;
  - las dos pruebas del componente que pedían vínculos ahora contestan también `meta/status`, que el panel pide una vez.

**Pruebas nuevas:**
- **Comercial:**
  - B1 en la página: aviso estático y, tras leer el estado, el modo real;
  - B3 cableado en el componente: pide `meta/status` una vez; «nada enviado» → contractual, sin dato que falte; «en revisión» → proveedor; con el gobierno apagado no la pide;
  - los motivos del dato que falta;
  - «Crear versión corregida» sin persona vinculada;
  - el tono del clasificador.
- **Voz:**
  - el lector de los dos bloques: forma real, `null`, ausente y cuatro formas rotas por bloque, sin tumbar la telefonía;
  - el monitor encendido con cada resultado de la vigilancia y cada estado de la marca;
  - el monitor sin los dos bloques: dos datos que faltan;
  - el monitor apagado, también con ElevenLabs sin configurar.

## Lo que no pude verificar (tanda 2)

- **La app en un navegador.** Falta mirar:
  - la frase del aviso de cabecera en un ancho estrecho;
  - el motivo bajo «Crear versión corregida», dentro del cajón.
- **La forma real en producción de `webhookHealth` y `providerVerification`.** La he sacado del código del backend del worktree (`VoiceWebhookHealth::lastVerdict()`, `VoiceProviderState::verification()`), no de una respuesta capturada. Si producción mandara otra forma, la fila lo dice como dato que falta; no inventa nada.
- **Si producción ya manda los dos bloques.** Depende de que el backend desplegado incluya `VoiceTelephonyStatus` con esos campos. No lo he comprobado contra producción.

## Adyacentes (tanda 2)

1. Los números canarios en `studio-integrations-text.ts:59` y `:145` siguen siendo del otro agente; no los he tocado.
2. **Sin paridad automática** para los conjuntos `VOICE_WEBHOOK_HEALTH_SET` y `VOICE_PROVIDER_MARK_SET`, ni para los textos de los 15 problemas (`webhookProblemText`). Un problema nuevo del backend se enseña con su código, sin romper nada. Si se quiere paridad, hay que añadir `VoiceWebhookHealth::PROBLEMS` y `MARK_STATES` al generador de la fixture de voz (backend).
3. **El cajón «Crear versión corregida».** Con la cuenta sin persona vinculada, el detalle sigue ofreciendo «Aprobar» y «Enviar a Meta» según las `actions` que manda el servidor (ya traen su propio motivo). No lo he tocado: lo decide el servidor.

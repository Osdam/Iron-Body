# ULTRON STUDIO · Estado de activación en el CRM (voz del Hito 8 y campañas automáticas del Hito 9)

**Fecha:** 10-11-2026. **Encargo:** del propietario, por el lead (y, añadidas por el lead durante el trabajo, las brechas **B-T8** y **B-T15** de `ULTRON_STUDIO_AUDITORIA_9_HITOS.md`, §10): que el CRM distinga, para cada función de la voz y de la automatización comercial, seis estados exactos —«Disponible y operativa», «Configurada y lista para activación», «Pausada voluntariamente», «Bloqueada por condición contractual», «Pendiente de proveedor externo», «Error técnico real»— en lugar de «Fase 1 · Todo apagado» o «apagado en el servidor».
**Árbol del CRM:** `_worktrees/ultron-h8/Iron-Body_Front`, rama `feat/ultron-studio-h9`, HEAD `9572426`. **Backend:** solo lectura (`_worktrees/ultron-h8/Iron-Body/backend`, HEAD `d701b0c8`). **Sin commit. Sin red. Producción: nada.**

**Estado: entregado para revisión del lead.** Cifras de verificación en §8.

---

## 1. Qué se entrega

### 1.1 Ficheros nuevos (CRM)

| Fichero | Qué es |
|---|---|
| `src/app/modules/ultron-studio/activation-state.ts` | La **taxonomía** de seis estados (nombre exacto, significado, ícono), la **regla de decisión** (`decideActivation`: precedencia de las faltas), los tipos (`ActivationBlocker`, `ActivationGap`, `ActivationVerdict`, `ActivationReading`, `ActivationRow`) y la clasificación de un fallo de lectura (`classifyReadFailure`: 5xx/red/ilegible = error técnico; 403/404/429 no son estados) |
| `src/app/modules/ultron-studio/activation-state.component.ts` + `.scss` | **`<us-activation-state>`**: una función con su estado (ícono + nombre exacto + tono, nunca solo color), la frase de lo que falta, la nota del dato que la API no da y un desplegable nativo (`<details>`) con lo demás que falta y las rutas de las que sale |
| `src/app/modules/ultron-studio/activation-panel.component.ts` + `.scss` | **`<us-activation-panel>`**: la leyenda de los seis estados (siempre los seis, con su cuenta; los vacíos en discreto), las funciones y «Qué significa cada estado» plegado |
| `src/app/modules/ultron-studio/_activation-hues.scss` | El tono de cada estado sobre los tokens del Studio (`--us-ok`, `--us-cyan`, `--us-violet`, `--us-warn`, `--us-blue`, `--us-bad`); no declara tokens nuevos |
| `src/app/modules/ultron-studio/studio-voice-activation.ts` | La **derivación de la voz** (pura): seis funciones |
| `src/app/modules/ultron-studio/studio-voice-activation-panel.ts` | `<us-voice-activation>`: el panel de «Voz y telefonía», con la sesión de la página |
| `src/app/modules/commercial-automation/ca-activation.ts` | La **derivación de las campañas** (pura): siete funciones |
| `src/app/modules/commercial-automation/ca-activation-panel.ts` | `<ca-activation>`: el panel de «Campañas automáticas», con la sesión de la página |
| `…/activation-state.spec.ts`, `…/studio-voice-activation.spec.ts`, `…/ca-activation.spec.ts` | Las pruebas (§8) |
| `e2e/h9-ui/capture-estados.mjs` | El arnés de capturas (hermano de `capture-h9.mjs`; API interceptada, sin red) |

### 1.2 Ficheros existentes que cambian (diff mínimo)

| Fichero | Cambio | Por qué |
|---|---|---|
| `ultron-studio/studio-voice-page.ts` | La pestaña «Estado» monta `<us-voice-activation />` encima de `<us-voice-status />` (+1 import) | El panel va en la pestaña que se abre por defecto. Se puso en la página y no dentro de `StudioVoiceStatusComponent` porque sus pruebas (y las de `studio-voice-guards`) verifican cada petición con `http.verify()` y el panel lee además `voice-lab/status` |
| `commercial-automation/commercial-automation-page.ts` | La pestaña «Panel» monta `<ca-activation />` encima de `<ca-panel />` (+1 import) | Ídem; `ca-panel.ts` no se toca |
| `ultron-studio/studio-voice-api.types.ts` | `VoiceTelephonyStatusDto.number` gana `inboundCapable?`, `check?` y `checkReason?` (opcionales) y el tipo `VoiceNumberCheck` | El servidor ya los manda (`VoiceTelephonyStatus::snapshot()`, B8-g y B8-h) y el CRM los ignoraba; sin `check` no se puede afirmar que las salientes están «comprobadas» (la puerta deniega con `number_unverified`/`number_mismatch`), y sin `inboundCapable` no se ve el «solo saliente» de la 4a-primero |
| `ultron-studio/studio-voice-parse.ts` | `readVoiceTelephonyStatus` los lee **tolerante**: un servidor anterior no los manda y un valor que no se conoce queda en `null` sin tumbar la telefonía | Aditivo; las pruebas de paridad y del lector no cambian |
| `ultron-studio/studio-voice-text.ts` | **B-T8:** `TEXTO_SIN_LLAMADAS` ya no dice «hasta la fase 3» | §10 |
| `ultron-studio/studio-voice-api.types.ts` | **B-T8:** el comentario de `callsEnabledInCode` repetía «el commit `voice(calls-open)` de la fase 3» | §10 |
| `ultron-studio/studio-api.types.ts`, `studio-parse.ts` | **B-T15:** `PublicationEntryDto.verified?: boolean`; `readPublicationEntry` lo toma **solo si es un booleano** (si no llega, la clave no aparece) | §10 |
| `ultron-studio/studio-publication-text.ts`, `studio-publication-history.ts` + `.scss` | **B-T15:** `entryVerificationView` y el distintivo por entrada (Verificada / No verificada / Sin verificar), con la explicación en la que no verifica | §10 |
| `ultron-studio/studio-publication-history.spec.ts` | +2 pruebas (B-T15) | §10 |

Nada del backend se tocó. **Aviso:** durante el trabajo el árbol del backend pasó a tener cambios sin commit **ajenos** (otros agentes: `StreamTicketService` con el vale de un solo uso de 60 s —P9-25—, `OutreachSendGate::LIVE_CHANNELS` con `whatsapp_template` —W9 en curso—, `WhatsappTemplateChannel`, `VoiceStrategyChannel`, `OutboundGate`, pruebas de facturación…). No son míos y no los toqué; la derivación trata las dos formas (ver §4.4 y §6).

## 2. La taxonomía

| Estado | Ícono (Material Symbols) | Tono (token del Studio) | Qué significa (leyenda del panel) |
|---|---|---|---|
| Disponible y operativa | `task_alt` | verde `--us-ok` | Encendida y comprobada por el servidor |
| Configurada y lista para activación | `power_settings_new` | cian `--us-cyan` | Tiene todas sus piezas: solo falta encenderla, con tu autorización escrita |
| Pausada voluntariamente | `pause_circle` | violeta `--us-violet` | Un freno o una pausa la detiene; la suelta una persona, con una nota |
| Bloqueada por condición contractual | `gavel` | ámbar `--us-warn` | Espera una condición del contrato: cuatro ojos, un plazo, un visto bueno o un commit autorizado |
| Pendiente de proveedor externo | `pending` | azul `--us-blue` | Espera algo de ElevenLabs, Twilio o Meta: un plan, una verificación, una pieza o una aprobación |
| Error técnico real | `error` | rosa `--us-bad` | El servidor falló o no pudo leer lo que hace falta: no se decide con un dato que falta |

**Cómo se decide.** Cada derivación apunta, en el orden de los pasos del documento, **todo** lo que le falta a la función; cada falta es un bloqueo con su estado y una frase. Manda el bloqueo de más precedencia: **error → pausada → proveedor → contrato → interruptor** (`ACTIVATION_PRECEDENCE`); entre iguales, el primero apuntado. Sin bloqueos, «Disponible y operativa». En pantalla: el estado, la frase del bloqueo que manda, la nota del dato que la API no da (si lo hubo) y, plegado, «Qué más falta (n)» con el resto y «Lo dice el servidor en …» con las rutas y campos.

**Lo que no es un estado.** «Leyendo…» (aún no llegó la lectura) y «Sin estado» (la cuenta no tiene el permiso de la fuente, el servidor no tiene la función, o 429) se pintan con borde discontinuo y sin tono, y no cuentan en la leyenda: no se confunden con los seis.

## 3. Voz y telefonía (Hito 8): seis funciones

Fuentes: la ruta 45 (`GET admin/ultron-studio/integrations/voice`, siempre leída), la telefonía (`GET admin/voice/status`, con `telephony.view`), el laboratorio (`GET …/voice-lab/status` y, solo si está encendido con ElevenLabs y sin cerrojos, `GET …/voice-lab/sessions`), el freno (`GET …/voice/brakes`, para decir su motivo) y los contactos (`GET admin/voice/contacts`, solo con el alcance «contactos» y `telephony.contacts_manage`). Las frases salen de `ULTRON_STUDIO_H8_PREPARACION_PLAN_PAGO.md` (paso y etiqueta) y de «Fases de despliegue» del contrato del H8.

**La puerta de las llamadas usa la verificación de la TELEFONÍA** (`OutboundGate.php:116`, `VoiceProviderState::current()` = `admin/voice/status.provider`), no la del Studio (ruta 45, que en producción dice `ELEVENLABS_VERIFIED` hasta el 03-12 mientras la telefonía dice `ELEVENLABS_UNVERIFIED`). El laboratorio usa la del Studio (`StudioVoiceLabConfig::credentialsState()`).

| Función | Reglas (en orden; cada línea es un bloqueo) | Hoy en producción (10-11, según el documento) |
|---|---|---|
| **Laboratorio hablado** | Lectura 5xx → error · apagado: ElevenLabs del Studio sin verificar/sin piezas/apagado → proveedor (paso 2.2); el plan con minutos (D-4) → proveedor **con dato que falta**; encenderlo (2a.5) → interruptor · simulador (`fake`) → proveedor (no habla ningún agente) · encendido sin configurar: los cerrojos del servidor en su orden (`invalid_driver` y `missing_price` → error: son de configuración; `credentials_unverified`, `missing_webhook_base`, `missing_secret_refs` → proveedor; `zero_budget`, `zero_sessions` → contrato: el presupuesto es un cerrojo de D8-23 que autoriza el propietario) · encendido y sin cerrojos: **comprobado** solo si hay una sesión de ElevenLabs terminada con el informe del proveedor (`metrics.reported.provider`); si no, proveedor («la primera sesión real») | **Pendiente de proveedor externo**: «Falta el plan de pago de ElevenLabs con minutos de agente, o tu excepción escrita para usar la franquicia gratuita (D-4)…» |
| **Publicación de voz** | Bloque `publishing` o `publication` sin datos → error · freno → pausada · cadena que no verifica → error · con voz publicada: la bandera apagada → **pausada** (lo publicado sigue atendiendo); si no, operativa («Publicada en canario… 4a.16») · sin nada publicado: la puerta del laboratorio (`labGate: required`): sin laboratorio comprobado → proveedor (2a.7 → 4a.11); comprobado y sin veredicto aprobado → contrato (cuatro ojos); el cupo diario `outbound.dailyCap + inbound.dailyCap = 0` → contrato (**H-26**: lo pone `salientes on` tras `voice(calls-open)`); la bandera → interruptor (4a.6) | **Pendiente de proveedor externo** (veredicto del laboratorio, que exige el plan); detrás, H-26 y `publicar on` |
| **Llamadas salientes** | Sin `telephony.view` → sin estado · lectura 5xx → error · freno (o ilegible: error) · ElevenLabs de la telefonía → proveedor (2.2) · número = WhatsApp → error · sin subcuenta → proveedor (D-3, 4a.1–4a.3) · sin número → proveedor (4a.2) · sin importación → proveedor (D-2) · `number.check` `mismatch`/`unverified` → proveedor · sin publicación que atienda → el estado de la publicación · constante cerrada → contrato (4a.7) · sin canario de voz activo (`policy.reasons` de la ruta 45) → contrato (0.7, 4a.14) · bandera, Twilio Voice, cupo o presupuesto → interruptor (4a.9) | **Pendiente de proveedor externo**: la verificación propia de la telefonía (2.2); detrás, Twilio, número, importación, publicación, constante, canario y `salientes on` |
| **Llamadas entrantes** | Primero, **el número** (E16-16: la fase 3 exige un número propio de Twilio en la (sub)cuenta, importado en ElevenLabs con entrantes; el previsto es un identificador de llamada, solo saliente): `inboundCapable === false` → proveedor; sin declarar (`null`) → proveedor **(dato que falta)**; `true` → nada · después, como las salientes (sin la comprobación del número) · bandera apagada → contrato: tus decisiones **P-7 a P-9** con el visto bueno legal **(dato que falta)** · cupo 0 → interruptor | **Pendiente de proveedor externo**: «Las entrantes exigen un número propio de Twilio…» |
| **Monitor de voz** | Sin `telephony.view` → sin estado · encendido → operativa · apagado: ElevenLabs sin piezas o apagado → proveedor; sin subcuenta de Twilio → proveedor (la conciliación en seco lee a los dos, 4a.5); la bandera → interruptor | **Pendiente de proveedor externo** (Twilio) |
| **Contactos con consentimiento** | Las salientes no operativas → su estado («Antes tienen que funcionar las salientes a canarios…») · alcance `canary` → contrato: visto bueno legal (Ley 2300, RNE, 1581 con la supresión de la voz) y P-11, P-13–P-15, P-20 **(dato que falta)** · alcance `contacts` y, con la llave, ningún contacto activo con consentimiento → contrato (cuatro ojos) | **Pendiente de proveedor externo** (por las salientes) |

## 4. Campañas automáticas (Hito 9): siete funciones

Fuentes: el catálogo (`GET …/strategies/catalog`, con su **409 `strategies_disabled`**), el estado de la ejecución (`GET …/outreach/status`, que responde 200 con la maestra apagada: L9-47), los vínculos (`GET …/strategies/person-bindings`, solo con el gobierno encendido y `ultron.person_bindings_manage`) y el canal en vivo (`StudioStreamService`). Frases del contrato del H9 (§9, §14) y de la matriz de aceptación (§5, §8).

| Función | Reglas | Hoy en producción (fase 1, L9-76) |
|---|---|---|
| **Gobierno de estrategias** | Catálogo 5xx → error · 404 → sin estado (sin H9) · **409 `strategies_disabled` → Configurada y lista para activación** (`ULTRON_STUDIO_STRATEGIES_ENABLED`) · encendido → operativa (la frase dice si publicar está abierto y su ventana) | **Configurada y lista para activación** |
| **Ejecución en sombra** | Estado 5xx → error; 404 → sin estado; 403 → sin estado · cadena que no verifica, freno ajeno ilegible → error · PARADA GLOBAL → pausada (quién y cuándo) · `mode=live` → operativa («la sombra quedó atrás») · `mode=shadow`: P9-24 (`commercialEvents`) → contrato; sin estrategias publicadas → contrato (cuatro ojos); programador o monitor apagados → interruptor · apagada: **condiciones de entrada de la fase 2** (dos personas vinculadas por consola, 24 h de fase 1 estable, P9-17 y P9-18) → contrato **(dato que falta; las personas se cuentan si la cuenta tiene la llave)**; P9-24 → contrato; gobierno y `sombra on` → interruptor | **Bloqueada por condición contractual** |
| **Asistido** | Comunes · freno del canal → pausada · sin modo `live` → contrato (≥ 7 días en sombra, P9-11, y P9-12) **(dato que falta)** · en vivo sin la bandera o el cupo → interruptor | **Bloqueada por condición contractual** |
| **Canal WhatsApp** | Comunes · freno del canal → pausada · puerta vacía (`channels.whatsapp_template.live=false`) → **proveedor** (W9 y Meta, con C9) · puerta implementada con la constante cerrada → proveedor: plantillas aprobadas por Meta **(dato que falta)** · constante `sends-open` → contrato · modo no `live` → contrato · bandera, cupo o presupuesto → interruptor | **Pendiente de proveedor externo** (W9) |
| **Canal voz** | Comunes · freno de voz del H8 (`brakes.foreign.voice_braked`) → pausada · freno del canal → pausada · puerta vacía → **proveedor** (V9, que exige las fases 4a/4b del H8 y estas el plan) · constante → contrato · modo → contrato · bandera → interruptor | **Pendiente de proveedor externo** (V9) |
| **Canal en vivo (SSE)** | Conectado → operativa · conectando → leyendo · cae tres veces sin abrir → error · **rechazado** → contrato **(dato que falta**: un `EventSource` no deja ver si fue el 409 `stream_disabled`, un 403 o un 5xx) · sin abrir → sin estado | **Bloqueada por condición contractual** (con la nota) |
| **Programador** | Comunes · una pasada `failed` o con `errorCode` → error · ejecución apagada → el estado de la sombra («Se enciende con la fase 2…»), con sus datos que faltan · `programador on` → interruptor · encendido y activo → operativa (fecha del último barrido) | **Bloqueada por condición contractual** |

### 4.4 Lo que cambia cuando se despliegue W9 (en curso en el árbol del backend)

`OutreachSendGate::LIVE_CHANNELS` ya incluye `whatsapp_template` en el árbol (sin commit). Desplegado, la fila «Canal WhatsApp» pasará sola de «puerta vacía» a «Pendiente de proveedor externo · Faltan las plantillas de WhatsApp aprobadas por Meta» (el estado no trae esa aprobación: dato que falta) y, detrás, la constante. Probado con esa forma en `ca-activation.spec.ts`.

## 5. Datos que faltan en la API (ruta y campo que harían falta)

En todos estos casos el panel **dice** que el servidor no da el dato y decide con la regla del documento o del contrato, conservadora (nunca «lista» ni «operativa» sin el dato). Los campos son **propuestas** para `backend`; ninguno se inventó en el CRM.

| # | Lo que no se sabe | Ruta | Campo que haría falta (propuesta) | Cómo se decide sin él | Con él |
|---|---|---|---|---|---|
| F-1 | Qué le falta al laboratorio mientras está apagado y si el plan tiene minutos de agente | `GET admin/ultron-studio/voice-lab/status` (`enabled:false` solo trae `message` y `can`) | `data.reasons` (los cerrojos que tendría encendido) y `data.plan` o los minutos de agente | Proveedor: «la primera sesión real es solo con plan» (2a.7) | «Configurada y lista para activación» cuando no falte nada |
| F-2 | Si el plan de ElevenLabs tiene minutos (también con el laboratorio encendido) | ídem, o `GET admin/ultron-studio/integrations/voice` → `elevenlabs.plan` | `plan` / `agentMinutesAvailable` | Se exige una sesión real con informe del proveedor | Operativa sin esperar a la primera sesión |
| F-3 | Las condiciones de la fase 3 (P-7 a P-9 y visto bueno legal) | `GET admin/voice/status` | `phaseGates.inbound` | Contrato (H8, fila legal: «antes de la 3») | Interruptor cuando estén |
| F-4 | Las condiciones de la fase 4b (visto bueno legal; P-11, P-13–P-15, P-20) | `GET admin/voice/status` | `phaseGates.contacts` | Contrato | Interruptor |
| F-5 | Las condiciones de la fase 2 del H9 (24 h estable, P9-17, P9-18; las dos personas, para quien no tiene la llave de vínculos) | `GET admin/ultron-studio/outreach/status` | `phaseGates.shadow` | Contrato (§14) | Interruptor |
| F-6 | Los días que lleva la sombra y P9-12 | ídem | `phaseGates.assisted` (o el inicio de la sombra) | Contrato (§14, fase 3) | Interruptor |
| F-7 | Si Meta aprobó las plantillas de WhatsApp (cuando W9 esté) | ídem | `config.channels.whatsapp_template.approvedTemplates` | Proveedor (§14, fase 4) | Contrato (`sends-open`) |
| F-9 | Si el número de voz puede recibir llamadas (hoy sin declarar) | `GET admin/voice/status` | `number.inboundCapable` (ya existe; falta que se **declare** en el entorno, paso 4a.2) | Proveedor (E16-16) | Sin ese bloqueo con `true` |
| F-8 | Si el canal en vivo está apagado (409) o rechaza por otra cosa | ídem (o `integrations/status`) | `config.stream.enabled` | Contrato (Paquete SSE9: lo enciende el propietario por escrito) | «Configurada y lista para activación» con el 409; error con un 5xx |

## 6. Decisiones que conviene que el lead confirme

1. **Freno abierto por el sistema = «Pausada voluntariamente».** Es la regla del encargo («freno echado o pausa»). El freno de voz lo abre el sistema (`seal_mismatch`, `guard_violation`, `cost_cap`…), no una persona; la frase lo dice («está echado por el agente que atendió no es el sellado… lo suelta una persona con «Publicar la voz» y una nota») para que nadie lo lea como una pausa propia. Un freno **ilegible** (`brake: unknown`, bloque sin datos o `brakeOpen: null`) es «Error técnico real».
2. **Precedencia: el freno manda sobre el proveedor.** Con el freno echado y piezas del proveedor pendientes, la fila dice «Pausada» y el proveedor queda en «Qué más falta». Razón: pide atención ya y lo soltaría una persona.
3. **«Publicación de voz» con la bandera encendida y nada publicado = operativa** («Lista para publicar en canario: la publica otra persona…»): la función (publicar) está disponible y el servidor la deja; publicar es un acto a cuatro ojos, no una pieza que falte.
4. **El canal en vivo rechazado = contrato** (por la regla de datos que faltan; ver F-8). Con el dato sería «lista».
5. **`missing_price` del laboratorio = error técnico**: el precio sale de `config('voice.prices.elevenlabs')` (código), no del proveedor (`StudioVoiceLabConfig.php:137`).
6. **El texto de la cabecera** («Fase 1 · Todo apagado») no se tocó: es la fase que calcula el servidor y sigue siendo cierta; el panel es lo que explica el porqué.

## 7. Estados de la interfaz, accesibilidad y diseño

- **Estados cubiertos:** cargando (por función: «Leyendo…», `role="status"`), sin permiso de la fuente (dice el permiso), sin la función en el servidor (sin H8/H9), 429 pasajero, error técnico (5xx, red, respuesta ilegible: con el código y qué hacer), y los seis estados. Una fuente que falla no tumba las demás filas.
- **Accesibilidad:** sección con `aria-labelledby`; cada función es un `h3`; el estado se lee como texto (el ícono es `aria-hidden`); leyenda en una lista con `aria-label`; «Qué más falta» y «Qué significa cada estado» son `<details>` nativos (teclado y lector sin código propio); foco visible del Studio (`.ig-root :focus-visible`); contraste: textos claros sobre tintes del 7–10 % (≥ 7:1), el muted de la leyenda vacía ≈ 4,8:1.
- **Responsive:** la leyenda y las cabeceras de fila envuelven; a 390 px el estado baja bajo el nombre y «Qué más falta» pasa a una columna; sin desplazamiento horizontal (medido en las capturas, §8).
- **Diseño:** el sistema del Studio manda: tokens `--us-*`, Lexend para los nombres y las cuentas, Inter para las frases, el mismo bloque que «La línea» (borde fuerte y degradado de superficie), filas con el filete de `ig-rows` y un lomo de 3 px del color del estado (como `ig-lane`). Ninguna hoja declara tokens ni usa `transform`; las tres pesan menos de 8 kB.
- **Seguridad:** solo lectura (ningún POST); todo lo que llega del servidor se pinta con interpolación (prueba de texto hostil); sin almacenamiento del navegador, sin consola, sin `innerHTML`; ninguna ruta nueva ni secreto.

## 10. Las brechas de la auditoría (B-T8 y B-T15)

**B-T8 · texto desfasado de la constante (H8).** `TEXTO_SIN_LLAMADAS` (`studio-voice-text.ts`, la cabecera de «Voz y telefonía») decía «la constante del código sigue cerrada hasta la fase 3», contra **E16-16** (la 4a va antes que la 3, imposible con el número actual). Ahora: «Hoy no se hace ninguna llamada: la constante del código sigue cerrada. Se abre con el commit voice(calls-open) que autorices por escrito para las salientes a canarios (fase 4a, que va antes que la 3: E16-16); las entrantes exigen además un número propio de Twilio.» El comentario del tipo (`callsEnabledInCode`) decía lo mismo y se corrigió. De paso, por la misma E16-16, la fila **«Llamadas entrantes»** del panel pide primero el número propio de Twilio (pieza del proveedor), no una decisión de orden. Prueba: `studio-voice-activation.spec.ts` («el aviso de la cabecera») y la de la página (`studio-voice-page.spec.ts`, que compara con la constante).

**B-T15 · `verified` por entrada (H7).** La API lo da desde B7-c (`StudioPublications::historyEntryDto`: las mismas cuatro comprobaciones que el motor sobre la cabeza) en `GET publications` y en el detalle de la versión; las respuestas de escritura no lo llevan. El historial (`us-publication-history`, en sus dos usos: la cadena paginada y la lista fija del detalle) enseña en la línea de la huella un distintivo con el lenguaje del estado de activación: **Verificada** (`verified`, verde), **No verificada** (`gpp_bad`, rosa, con la frase «Su huella o su firma no cuadran con la clave de este entorno: alguien tocó la fila sin la clave. No se cree; revisa el incidente.») y **Sin verificar** (`hourglass_empty`, discontinuo: el servidor no la verificó en esa respuesta). El lector toma el campo solo si es un booleano y, si no llega, no añade la clave: las comparaciones exactas de las pruebas de compatibilidad del H5 siguen igual. Pruebas: +2 en `studio-publication-history.spec.ts`.

**Adyacente, sin tocar (para quien corresponda):**
- `studio-voice-text.ts:70` `TEXTO_AVISO_FIJO`: «provisional hasta que el propietario lo apruebe antes de la fase 3». Con E16-16 la primera llamada es de la 4a; el documento de preparación lo pone en D-6 (paso 0.5, antes de todo). No es B-T8 (es el aviso de IA, no la constante): decide el lead.
- `commercial-automation-api.types.ts:677`: el comentario de `sendsEnabledInCode` dice «cerrada en el código hasta la fase 3»; según el contrato del H9 (§14) se abre en la fase 4 (WhatsApp automático). Solo comentario.
- `ULTRON_STUDIO_GUIA_ADMINISTRACION.md:480` repite el texto viejo de B-T8 (auditoría, punto 12): es de `documentation-specialist`.

## 8. Verificación

_(se completa al terminar las ejecuciones)_

## 9. Para el resto del equipo

- **tester:** flujos críticos: abrir «Voz y telefonía» (pestaña Estado) y «Campañas automáticas» (Panel) con una cuenta sin `telephony.view` y otra sin `commercial_automation.view` (filas «Sin estado» con el permiso, sin peticiones de más); con el freno de voz echado (las cuatro funciones de llamadas «Pausada»); con la telefonía devolviendo 5xx (cuatro filas «Error técnico real» y el resto intacto); con la PARADA GLOBAL; y el desplegable «Qué más falta» con teclado.
- **security:** nada nuevo que revisar en tokens o sesión; el panel no escribe ni guarda nada y pinta como texto.
- **backend:** los ocho datos de §5, si se quiere que el panel deje de decidir por el documento en esos casos.
- **reviewer:** §6 y §10.
- **Árboles compartidos:** durante el trabajo, otros agentes tenían cambios sin commit en los dos árboles (CRM: `auth.service.ts`, `studio-stream.service.ts`, `face-recognition.service.ts`, `moderation-realtime.service.ts` y sus pruebas —el vale de un solo uso, P9-25—; backend: §1.2). No son míos, no los toqué, y mis ejecuciones de `tsc`, pruebas y build los incluyen tal como estaban en ese momento.

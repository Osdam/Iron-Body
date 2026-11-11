# Auditoría C · Voz y telefonía (cierre, 11-11-2026, solo lectura)

**Base:** backend del worktree `feat/ultron-studio-h9` (`6f9d463`, contiene `2687119` = producción) y CRM del worktree (`5f2a454` = `b07eda5` + un commit que solo toca pruebas). Producción solo a través de los hechos del lead (`estado-voz-20261111.txt`, `panel-voz-crm-20261111-1848Z.json`, `estado-produccion-20261111-1842Z.txt`). No se tocó nada: ni código, ni mandos, ni producción, ni red.
**Marcas:** **[C]** comprobado en código o repo · **[P]** hecho de producción del lead · **[H]** hipótesis, sin comprobar.

> **Lo más importante (cambia el plan):** el «número de empresa» …5483 que ElevenLabs tiene importado es el **número oficial de WhatsApp del gimnasio** (+57 314 345 5483: `docs/whatsapp/ACTIVACION.md:4`, `docs/marketing-meta-whatsapp.md:16`, `config/meta.php:42,392`) **[C]**. La protección 6 del H8 lo prohíbe como número de voz (`ULTRON_STUDIO_H8_CONTRACT.md:56`) y el código lo bloquea en cuanto la configuración de WhatsApp de producción lo declare. Sin otro número de origen **no hay primera llamada legítima**, y el paso 0.6 de `ULTRON_STUDIO_ACTIVACION_POR_ETAPAS.md:20` (`TWILIO_VOICE_FROM=+57…5483`) **no debe ejecutarse**. La salida más barata no cuesta nada: verificar **otro** número como identificador de llamada en Twilio.

---

## 1. Matriz

| Funcionalidad | Frontend | Backend | Tablas | Dependencia externa | Bandera efectiva (prod) | Estado | Causa demostrada | Corrección | Prueba | Resultado esperado |
|---|---|---|---|---|---|---|---|---|---|---|
| Laboratorio hablado | `studio-voice-lab.ts`, `studio-voice-lab-conversation.ts` | `StudioVoiceLabSessions`, `StudioVoiceLabAgents`, `ElevenLabsLabSessionIssuer` | `ultron_studio_voice_lab_sessions`, `ultron_studio_voice_agents` (purpose `lab`) | ElevenLabs (plan Creator; verificación hasta el 11-12) | `VOICE_LAB_ENABLED=true`, `elevenlabs`, 3,00/1,00 US$, 300 s, 5/día [P] | **OPERATIVO Y PROBADO** | Sesión real del 11-11 11:37 UTC: 67 s, US$ 0,09 [P] | Ninguna. Vigilar la verificación y el agente de verificación (§4.6) | Una sesión de ≤ 5 min | `ended` por `provider_report` |
| Herramientas `consultar_planes` y `consultar_horarios` | diálogo del perfil, laboratorio | `VoiceTools::lab()` (`VoiceTools.php:265-280`), `StudioVoiceLabSessions::answerToolCall` | ninguna (`guard_codes.toolRequests` de la sesión) | — | Sede y horario fijados (`facts.hoursConfigured=true`) [P]. La rev. 2 solo tiene `no_volver_a_llamar` [P] | **LISTO PARA ACTIVACIÓN AUTORIZADA** | Falta la rev. 3 del perfil, que se hace a cuatro ojos (§4.7). Queda abierto qué manda ElevenLabs como `call_sid` en una conversación web (E18-8) | Rev. 3: la crea el propietario y la aprueba otra persona. Después, una sesión | Sesión con la rev. 3 preguntando por planes y horario | Dice los precios y el horario; el registro tiene `voice.tool` con `lab:true` y ningún `voice.tool.invalid_schema` (si aparece, pasa a INCOMPLETO) |
| Publicación de voz | `studio-voice-publications.ts` | `StudioVoicePublishing`, `ElevenLabsAgentDeployer/Mapper` | `ultron_studio_voice_publications`, `ultron_studio_voice_agents` | ElevenLabs (crea un agente) | `ULTRON_STUDIO_VOICE_PUBLISHING_ENABLED=true`, ventana 07–19 (abierta), puerta del laboratorio `required` [P] | **BLOQUEADO EXTERNAMENTE** | Faltan `phone_number_id` y un cupo diario ≥ 1 (`StudioVoicePublishing.php:703-733`). El cupo solo lo pone `salientes on` (H-26), que exige la constante abierta y Twilio. El único número importado es el de WhatsApp. Hay 0 veredictos [P] | Un número válido del propietario y después §4.1, pasos 6–12 | Publicar en canario desde el CRM | 201, `number.outcome=not_applicable_outbound_only`, cadena `canary:ok` |
| Twilio Voice (credencial y número) | `studio-voice-status.ts:75-87` | `TwilioVoiceCarrier` (cortar y conciliar), `TwilioRequestSignature` (solo el aviso de gasto) | — | Twilio: una key restringida y un número que no sea de WhatsApp | `TWILIO_VOICE_ENABLED=false`. Faltan los 3 secretos. `FROM`, `ACCOUNT_SID` y `PHONE_NUMBER_ID` vacíos [P] | **BLOQUEADO EXTERNAMENTE** | La cuenta tiene 0 API keys [P]. El único identificador empresarial es el número de WhatsApp [C] | §4.1, pasos 1–8 | `bash /root/h8-twilio-check.sh` | `VEREDICTO: APTA`, «CALLER ID VERIFICADO» |
| Salientes canarias | `studio-voice-calls.ts`, `studio-voice-call-dialog.ts` | `OutboundGate`, `OutboundPolicy`, `PlaceOutboundVoiceCall`, `ElevenLabsCallStarter` | `voice_calls`, `voice_call_events`, `ultron_studio_canary_numbers` | Twilio y ElevenLabs | Constante cerrada. `VOICE_OUTBOUND_ENABLED=false`, cupo 0, 0 US$. Worker `ironbody-voice` RUNNING [P] | **BLOQUEADO EXTERNAMENTE** | No hay número de origen válido ni key (fila anterior). Detrás vienen cinco autorizaciones: el commit, `salientes on`, la publicación, el canario y la llamada | §4.1 completo | Primera llamada al canario | `requested→dialing→active→completed`, post-llamada sin violaciones, coste registrado, `unknown 0`, freno suelto |
| Entrantes | fila «Entrantes» del panel | `InboundGate`, `InboundPolicy`, `ElevenLabsInitiationController` | `voice_calls`, `voice_call_events` | Twilio: un número propio con voz (y el expediente regulatorio) | `VOICE_INBOUND_ENABLED=false`, cupo 0 [P] | **BLOQUEADO EXTERNAMENTE** | 0 números propios. La importación es `supports_inbound:false` [P]. El mando se niega con un número solo saliente (`h8-voice-scope.sh:760`) | Comprar o portar un número (§4.5) | Una entrante desde un canario y otra desde un número no canario | El canario es atendido; el no canario oye «no disponible» |
| Monitor de voz | fila «Monitor» (`studio-voice-activation.ts:425-441`) | `VoiceReconciler`, `VoiceWebhookHealth`, `integrations-snapshot --verify-voice` | `voice_brakes`, `voice_call_events`, incidentes de IRON GUARD | ElevenLabs (GET); Twilio (GET, todavía sin credencial) | `VOICE_MONITOR_ENABLED=true`, 5 tareas [P] | **EN OBSERVACIÓN** | Encendido hoy. La conciliación de Twilio y la del número están inertes (`carrier=not_configured` y sin id: `VoiceReconciler.php:128-135, 564, 574-576`). A las 18:48 `webhookHealth=null`: la primera pasada es a las 19:05 UTC [P] | Lista 2.1, R2 | `voice:webhook-health --dry-run --json` y después `voice:doctor --telefonia --json` | `ok`, sin `wouldOpen`, freno suelto |
| Contactos y consentimiento de voz | `studio-voice-contacts.ts`, `studio-voice-contact-dialog.ts` | `VoiceContacts`, `VoiceConsentRule`, `VoiceExclusionRule` | `voice_contacts`, `voice_consents`, `voice_exclusions`, `voice_rne_checks` | Visto bueno legal (Ley 1581, Ley 2300, RNE) | `VOICE_CONTACTS_ENABLED=false`, alcance `canary`, RNE 0 días [P] | **INCOMPLETO** | La supresión de la Ley 1581 no cubre las tablas de voz. La propuesta `ops/supresion-1581-voz.propuesta.sql` no está integrada ni probada (PREPARACION §4). Faltan además D-12 y P-14. **No hace falta para la llamada canaria** (`VoiceExclusionRule.php:9-17, 34-51`) | database-specialist la integra y la prueba; el visto bueno legal | — (fase 4b) | — |
| Rotación de la clave de ElevenLabs | Studio → Integraciones → Voz (reconocer) | `el-verificar.sh --solo-clave` | — | Consola de ElevenLabs | La clave de producción es la que pasó por el chat [P] | **LISTO PARA ACTIVACIÓN AUTORIZADA** | El mando existe y está ensayado (H-16). Falta la clave nueva del propietario y su orden | §4.9 | `el-verificar.sh`, después `h8-secrets-register.sh --solo-comprobar` y `voice:doctor --verify` con la clave vieja ya revocada | «ELEVENLABS VERIFICACIÓN TERMINADA», código 0 y verificación correcta |

---

## 2. Las dos listas

### 2.1 Órdenes que el lead puede ejecutar ya

Solo hay lecturas, más una escritura condicionada. **No queda ninguna pieza no secreta que se pueda cargar hoy sin el propietario**: las tres que faltan (`TWILIO_VOICE_ACCOUNT_SID`, `TWILIO_VOICE_FROM`, `ELEVENLABS_AGENT_PHONE_NUMBER_ID`) dependen de su decisión sobre la cuenta y el número. Las que ya existen (el SID raíz y la importación de …5483) son justo las que **no** hay que usar.

| # | Orden (en el servidor, como root) | Qué comprueba | Vuelta atrás |
|---|---|---|---|
| R1 | `cd /var/www/api/backend && sudo -u www-data php artisan tinker --execute='echo json_encode(["display" => filled(config("meta.whatsapp_display_phone")), "protegidos" => count((array) config("meta.protected_numbers")), "5483_es_whatsapp" => App\Services\Telephony\VoiceNumberGuard::collidesWithMessaging("+573143455483")]), PHP_EOL;'` (solo booleanos; el número es público: `config/legal.php:59`) | `true`: el código ya bloquea …5483. `false`: el código no lo ve, pero la protección 6 sigue prohibiéndolo, y hay que añadir el número a `WHATSAPP_PROTECTED_NUMBERS` (con su autorización) | — |
| R2 | **Urgente (la primera pasada es a las 19:05 UTC):** `sudo -u www-data php artisan voice:webhook-health --dry-run --json` | Si `brake.wouldOpen` es `true` (por ejemplo, porque ElevenLabs no informa `auth_type`: L9-91), el monitor echará el freno **cada hora** | Si lo echaría: `bash /root/h8-voice-scope.sh monitor off` antes de las :05 |
| R3 | Después de las 19:05: `sudo -u www-data php artisan voice:doctor --telefonia --json` | `webhookHealth` en `ok` y `brakeOpen=false` | Con el freno echado: averiguar el motivo; lo suelta una persona con `ultron.voice_publish` |
| R4 | `bash /root/h8-secrets-register.sh --solo-comprobar` | Código 0, «TODO REGISTRADO»: los 2 secretos y el webhook existen con las referencias del entorno | — |
| R5 | `sudo -u www-data php artisan voice:reconcile --dry-run --numbers --json` | `carrier=not_configured`, `unknown 0`, `wouldOpenBrake false` (la línea base) | — |
| R6 | `bash /root/h6-integrations-scope.sh estado`, y en psql, quién creó la versión APPROVED y el perfil rev. 2 y quién abrió las 3 sesiones `ended` | Escrituras del Studio encendidas (el 11-11 decía `integrations_writes:true` [P]). Así se planifican los cuatro ojos de §4.1, pasos 11–13 | — |
| W1 | *Solo si el origen elegido es un identificador verificado, y con la autorización de la fase:* `bash /root/h8-voice-scope.sh fase 2 set VOICE_NUMBER_INBOUND_CAPABLE=false` | `estado`: «NO: Outbound Only declarado». No cambia la huella de voz (`StudioIntegrationConfig.php:187-217` no la lee) | La vuelta exacta que imprime el mando (instalar la copia previa y regenerar la caché) |

**No ejecutar:** `fase 2 set TWILIO_VOICE_FROM=+57…5483` (el mando lo niega si choca: `h8-voice-scope.sh:698-700`); `fase 2 set ELEVENLABS_AGENT_PHONE_NUMBER_ID=phnum_1001m6…` (con el monitor encendido, la pasada horaria del número echaría el freno `number_is_messaging`: `VoiceReconciler.php:601-611`); `expectativa fase2` antes de tener el número; `monitor on --acepta-freno`.

### 2.2 Lo que solo puede dar el propietario

1. **Un número de origen que no sea el de WhatsApp.** Verificar otro número como identificador de llamada en Twilio (gratis: Twilio llama a ese número y se teclea un código) o comprar uno. Importarlo en ElevenLabs como solo saliente, sin agente, y darnos su `phnum_…` y los 4 últimos dígitos.
2. **La cuenta de Twilio de la voz** y, dentro de ella, la **API key restringida de voz**, dejada en `/root/tw-voz.txt` (600, con el modelo de `ops/modelos-root/`). Se recomienda una subcuenta «Voz», que no tiene coste. Usar la cuenta actual exige una enmienda del contrato y el visto bueno de security.
3. **Tres autorizaciones escritas:** el commit `voice(calls-open)` y su despliegue; `salientes on --cupo=1 --presupuesto=2.00`; y la primera llamada (texto de §3).
4. **Una segunda persona real con cuenta propia**, para el veredicto del laboratorio, la publicación, la autorización del canario y la aprobación de la rev. 3.
5. **La clave nueva de ElevenLabs**, y revocar la vieja solo después de verificar la nueva (§4.9).
6. **No tocar en las consolas** lo que lista §4.6.

---

## 3. Solicitud de autorización de la primera llamada (para el propietario)

> Autorizo **una** llamada saliente de prueba de ULTRON («Sofía») desde el CRM, con estas condiciones:
> - **Origen:** el número …____ (identificador de llamada verificado en la cuenta de voz de Twilio), importado en ElevenLabs como solo saliente (`phnum_…`). **No** es el número de WhatsApp …5483.
> - **Destino:** mi teléfono registrado como **canario de voz** (…____), activado por otra persona. Ningún otro número.
> - **Agente:** el publicado en canario («Iron Body · voz · publication · <12 primeros caracteres de la huella>»), compilado con la versión «____» y el perfil «Sofia Medellin» rev. __, con el veredicto «aprobado» del laboratorio que dio ____.
> - **Cuándo:** el __-11-2026 entre las __:__ y las __:__ (horario legal: de lunes a viernes de 07:00 a 19:00 y sábados de 08:00 a 15:00; **ni el 15-11, que es domingo, ni el 16-11, que es festivo**: `OutboundSchedule.php:29`).
> - **Duración máxima:** 300 s, con corte automático del agente. 30 s de timbre. Si contesta un buzón, cuelga. Sin grabación ni audio guardado (`ElevenLabsCallStarter.php:97-114`).
> - **Cupo:** 1 llamada al día. **Presupuesto del día:** US$ 2,00.
> - **Reserva previa del sistema** (`VoiceCostGuard`, `OutboundGate.php:133`): **US$ 0,9605** si el destino es un móvil colombiano (+573…) y **US$ 1,1220** en cualquier otro caso. Son 5 minutos con un tramo de transferencia a fijo, que se reserva siempre.
> - **Coste real esperado:** unos **US$ 0,12 por minuto** (ElevenLabs 0,08 + Twilio móvil 0,0377 + Media Streams 0,0044, precios del 03-11). Un minuto cuesta unos 0,12 y el máximo de 5 minutos unos 0,61. Twilio lo cobra del saldo que comparte con el SMS del OTP. Los minutos de ElevenLabs pueden entrar en el plan Creator (por confirmar en la consola).
> - **Resultado esperado:** suena mi teléfono; Sofía da el aviso de IA y el saludo; conversamos un momento y cuelga. En el CRM la llamada queda «completada», con su post-llamada sin violaciones y su coste, y sin freno.
> - **Cómo se corta:** cuelgo yo; o «Colgar» en el CRM; o en el servidor `sudo -u www-data php artisan voice:hangup --all --confirm`; y después `bash /root/h8-voice-scope.sh salientes off`. Una segunda llamada ese día la deniega el cupo.
>
> Firma y fecha: ____

---

## 4. Respuestas

### 4.1 Camino mínimo y legítimo hasta la primera saliente canaria

**¿Subcuenta o cuenta actual?** **El código no distingue entre las dos** [C]. `TwilioVoiceCarrier::configured()` solo comprueba la forma de AC, SK y el secreto (`TwilioVoiceCarrier.php:69-72`). En el Studio, «subcuenta lista» es exactamente eso (`StudioIntegrations.php:441`). `VoiceTwilioAccountTest` lo fija: con la cuenta principal se hacen las mismas peticiones y nunca se usa la credencial del OTP; lo ejecuté y pasa (2 pruebas, 31 aserciones). Ni `salientes on` ni el despliegue 4a exigen que sea una subcuenta. Solo `h8-twilio-check.sh` devuelve `VEREDICTO: SUBCUENTA` (código 3), y eso no bloquea nada.

Lo que sí la hace necesaria, en la práctica, es esto:
- (a) El contrato: D8-4 y D8-5. La propia comprobación lo dice: «misma cuenta» es viable solo con 0 llamadas ajenas en la cuenta, la importación hecha **sin** el Auth Token, el token de firma vacío y una enmienda con el visto bueno de security (`h8-twilio-check.sh:241-251`).
- (b) **La importación en ElevenLabs.** Hoy guarda el Auth Token **maestro** del OTP (A-1, [P]). El formulario de importación de ElevenLabs pide SID y token; si admite una API key está por confirmar [H]. Con una subcuenta se importa con el SID y el token **de la subcuenta**, y el OTP queda fuera.
- (c) La conciliación lista **todas** las llamadas de la cuenta configurada (`VoiceReconciler.php:128-139`). Cualquier llamada manual hecha desde ElevenLabs con un número de esa cuenta echa el freno.

**Recomendación:** subcuenta. Crearla es gratis y el uso se factura a la cuenta madre.

**¿Se puede crear en la cuenta actual una key restringida solo a voz, sin coste y sin tocar el OTP?** Sí. Crear una API key no cambia el Auth Token ni Verify, y el OTP usa `otp.twilio.*` y el canal `sms` (`TwilioVerifyService.php:49`). Los nombres exactos de los permisos se confirman en la consola [H]: llamadas (leer, listar y actualizar), identificadores de llamada verificados (listar), números (listar), cuenta (leer) y ninguno de mensajería. `h8-twilio-check.sh` comprueba que la key **no** puede leer mensajes.

**Las tres piezas secretas, según el código:**

| Pieza | Quién la lee | Para qué | ¿Obligatoria para la primera saliente? | De dónde sale |
|---|---|---|---|---|
| `TWILIO_VOICE_API_KEY_SID` y `TWILIO_VOICE_API_KEY_SECRET` | Solo `TwilioVoiceCarrier` | Colgar (el corte de emergencia 0b, `voice:hangup`) y conciliar llamadas. **No inicia la llamada**: la inicia ElevenLabs con `agent_phone_number_id` (`ElevenLabsCallStarter.php:44, 97-114`) | La puerta y el job no la miran. **Sí la exigen los mandos**: `salientes on` (`h8-voice-scope.sh:751-752`) y el despliegue 4a (`deploy-h8-backend-fase1.sh:188`). Sin ella no hay corte de emergencia ni coste conciliado | Consola de Twilio, **en la cuenta donde está el número**: API keys → Restricted |
| `TWILIO_VOICE_SIGNING_TOKEN` | Solo `TwilioRequestSignature::verifyRequest` (`:226-243`) | Únicamente el webhook `usage-trigger` (`routes/voice.php:53-54`); sin ella responde 401 (`TwilioVoiceUsageTriggerController.php:33-43`) | **No** («opcional», `deploy-h8-backend-fase1.sh:187`). Sin ella solo se pierde el aviso de gasto de Twilio; el techo de `VoiceCostGuard` y el freno por coste siguen | Twilio firma con el Auth Token **de la cuenta dueña del recurso**. En la cuenta actual sería el token maestro del OTP, así que se deja **vacío** (`h8-twilio-check.sh:174, 245`). Con subcuenta, el Auth Token de la subcuenta |

**¿Sirve el número de empresa (un identificador verificado) como origen?** Técnicamente sí. ElevenLabs lo importa como solo saliente, la llamada del 03-11 salió (53 s, completada) [P], y la puerta no distingue un número comprado de uno verificado. **Pero choca** con la protección del número de WhatsApp: …5483 es el número oficial de WhatsApp del gimnasio, en coexistencia [C]. El bloqueo aparece en todos los puntos:
- el mando se niega a escribirlo (`h8-voice-scope.sh:698-700`);
- `h8-twilio-check.sh` da FALLO (`:175-176`);
- la política responde `voice_number_is_whatsapp` (`OutboundPolicy.php:124-126`, `VoiceNumberGuard.php:98-112, 211-214`);
- la conciliación horaria del número **echa el freno** `number_is_messaging`, aunque `TWILIO_VOICE_FROM` diga otro número (`VoiceReconciler.php:601-611`).

Todo eso ocurre si `WHATSAPP_DISPLAY_PHONE` o `WHATSAPP_PROTECTED_NUMBERS` lo contienen (`config/meta.php:77,123`); R1 lo confirma. Aunque no lo contengan, el contrato lo prohíbe y security dejó dicho «parar y no tocar la guarda» (`plans/informes-h8/informe-security.md:1171-1175`). **Es un bloqueo real.** Las salidas, por orden:
1. Verificar como identificador **otro** número que no sea el de WhatsApp ni el remitente del OTP. Gratis y en minutos (`h8-twilio-check.sh:237`). El otro identificador ya verificado (…6026) fue el destino de la prueba del 03-11: si es el teléfono que hará de canario, no sirve como origen [H].
2. Comprar un número de Twilio para Colombia. Cuesta unos US$ 17,50 al mes y exige el expediente regulatorio, que lleva días (`ULTRON_STUDIO_CHECKLIST_OPERATIVO.md:273`). Es lo único que habilita además las entrantes.
3. Enmendar la protección 6. No se recomienda: la marca y las devoluciones de llamada irían al WhatsApp que atiende el personal.

**La constante.** Abrirla exige un commit cuyo asunto empiece por `voice(calls-open): `, con un solo padre, que cite **literalmente** la autorización escrita y cuyo diff sea **exactamente** la línea `= false;` → `= true;` de `app/Services/Voice/VoiceCallPolicy.php:23`. Se despliega con `deploy-h8-backend-fase1.sh <commit> $BK --fase=4a --autorizacion=<ref>` (`:36-56`, `:370-400`). Su paso 0 exige la **fase 2 completa**: las 14 piezas, incluidos el número, el id importado y la key (`:188`), `numero=si subcuenta=si` (`:683-684`), la expectativa de la fase 2 y ninguna deriva. Por eso **va después** de las piezas de Twilio. Después, `h8-voice-scope.sh` exige que el último commit que tocó el fichero sea `voice(calls-open):` (`:217-227`).

Ya preparado: el modo 4a ensayado en el banco (OPS8-d) y el worker en marcha [P]. Una consecuencia sin resolver: tras ese commit, las guardas que afirman `false` se ponen en rojo (`VoiceIsolationTest.php:330-334`, `VoiceCallPolicyTest.php:77-80`), y la regla de una sola línea impide tocarlas en el mismo commit (hallazgo C-4).

**El worker, el presupuesto, el cupo, el canario y la publicación:**
- **Worker:** RUNNING [P].
- **Cupo y presupuesto:** los pone `salientes on --cupo=1 --presupuesto=2.00`. Con menos de 0,9605 US$, una llamada a un móvil se deniega por `budget_exhausted` aunque el mando lo acepte (hallazgo C-1).
- **Canario:** el alcance `canary` llama al registro de canarios del Studio. **No** usa `voice_contacts`, ni su consentimiento, ni el RNE, ni `VOICE_CONTACTS_ENABLED` (`OutboundGate.php:219-240`, `VoiceExclusionRule.php:9-17, 34-51`). El alta va a cuatro ojos: registra quien tiene el teléfono, declarando el consentimiento (`StudioCanaryNumbers.php:150-225`); otra persona autoriza (`:266`); y se activa (`:291`).
- **Publicación:** §4.4.

**Los pasos.** «Obligatorio» significa que, sin el paso, no hay llamada (por el código o por el mando que es el procedimiento). «Recomendado» significa que no se exige, pero tiene un motivo.

| # | Quién | Orden o clic | Comprueba | Vuelta atrás | Tipo |
|---|---|---|---|---|---|
| 0 | Lead | R1 a R6 | Bloqueo de …5483; webhook sano; freno suelto | — | Obligatorio (decide el camino) |
| 1 | Propietario, Twilio | Crear la subcuenta «Voz» | Aparece un AC… nuevo | Cerrarla (sin coste) | Recomendado (contrato) |
| 2 | Propietario, Twilio (subcuenta) | Verified Caller IDs → Add → un número que no sea …5483 ni el remitente del OTP → Twilio llama → teclear el código | Queda verificado | Borrarlo | **Obligatorio** (o comprar un número) |
| 3 | Propietario, Twilio (subcuenta) | Permisos geográficos de voz: Colombia | Habilitado | — | Obligatorio si la subcuenta no lo hereda [H] |
| 4 | Propietario, Twilio (subcuenta) | API keys → Restricted (solo voz) → `/root/tw-voz.txt` 600, sin pasar por el chat | Lo comprueba el paso 7 | Revocar la key | Obligatorio por el mando y para poder cortar |
| 5 | Propietario, ElevenLabs | Phone numbers → Import from Twilio (el número del paso 2, con el SID y el token **de la subcuenta**), **sin asignar agente** → anotar `phnum_…` | Saliente sí, entrante no | Borrar la importación | Obligatorio |
| 6 | Lead | `monitor off` → `fase 2 secreto /root/tw-voz.txt` → `fase 2 set TWILIO_VOICE_ACCOUNT_SID=AC… TWILIO_VOICE_FROM=+57… ELEVENLABS_AGENT_PHONE_NUMBER_ID=phnum_… VOICE_NUMBER_INBOUND_CAPABLE=false` → `expectativa fase2` → reconocer la configuración en el CRM → `monitor on`, que concilia en seco antes de encender | `MANDO … APLICADO` en cada orden; `estado` con las piezas presentes y «WhatsApp: no» | La vuelta exacta que imprime cada orden | Obligatorio (el `monitor off/on` es recomendado: por si la llamada de verificación de Twilio aparece en el registro de llamadas [H]) |
| 7 | Lead | `bash /root/h8-twilio-check.sh` | `APTA`, «CALLER ID VERIFICADO», mensajería denegada, 0 llamadas ajenas | — | Recomendado |
| 8 | Lead | Esperar la pasada `voice:reconcile --numbers` del minuto 0 → `voice:doctor --telefonia --json` | `number.check=verified` (la puerta exige una observación de menos de 2 h: `VoiceNumberGuard.php:175-204`) | — | **Obligatorio** |
| 9 | Propietario (orden escrita) y lead | Commit `voice(calls-open)`, push con la orden del propietario, respaldo `BK` nuevo, `deploy-h8-backend-fase1.sh … --fase=4a --autorizacion=… --solo-comprobar` y luego sin `--solo-comprobar` | «DESPLIEGUE H8 FASE 4a … COMPLETADO»; constante abierta | `git checkout -q --detach $(cat $BK/api-commit.txt)`, cachés y `queue:restart` | Obligatorio |
| 10 | Lead (orden escrita) | `salientes on --cupo=1 --presupuesto=2.00` → reconocer → `expectativa llamadas` | APLICADO; la puerta todavía dice `no_publication` | `salientes off` | Obligatorio |
| 11 | Persona B, CRM | Veredicto «aprobado» sobre una sesión de la **misma compilación** que se publicará. Con la rev. 2 vale una de las 3 sesiones ya terminadas; con la rev. 3, una sesión nueva | `compile-preview` muestra `labVerdict.latest=approved`. B no puede ser quien abrió la sesión ni quien creó la versión (`StudioVoiceLabSessions.php:1065-1072`) | Un veredicto por sesión: si hay que rehacerlo, otra sesión | Obligatorio (puerta `required`) |
| 12 | Quien no creó la versión ni el perfil, CRM, de 07:00 a 19:00 de Bogotá | Studio → Voz → previsualizar → Publicar en canario | 201; `estado` dice `cadena=canary:ok`; smoke 4a | «Apagar la voz». En la primera publicación no hay a qué revertir (`StudioVoicePublishing.php:442-455`) | Obligatorio |
| 13 | Propietario y B, CRM | Integraciones → Canarios: registrar (canal voz) → autorizar → activar | Activo | Desactivar o revocar | Obligatorio |
| 14 | Persona con `telephony.call_place`, en horario legal | Voz y telefonía → Llamadas → nueva → el canario | 202 → `completed` (§3) | Colgar, `voice:hangup --all --confirm`, `salientes off` | Obligatorio, con la autorización de §3 |

Los pasos 11 y 13 se pueden hacer ya, en paralelo. Si el propietario hace los pasos 1–5 el jueves 12, la llamada cabe el viernes 13 o el sábado 14 (de 08:00 a 15:00).

### 4.4 Publicación de voz (bandera ya encendida)

**Qué exige**, en el orden del código (`StudioVoicePublishing.php:274-322`):
1. El permiso `ultron.voice_publish`.
2. La bandera, que ya está encendida.
3. Cuatro ojos: quien publica no creó ni la versión ni el perfil (`:1081-1089`).
4. La ventana de 07:00 a 19:00 de Bogotá.
5. El freno suelto (`:1060-1070`).
6. Una versión aprobada (hay 1 [P]) y un perfil aprobado (rev. 2).
7. Que la `specHash` sea la que se previsualizó.
8. Un **veredicto «aprobado» sobre esa misma huella** (`:852-866`). La huella no depende de si se compila para el laboratorio o para publicar; el propósito solo decide si se admiten borradores enviados (`StudioVoiceCompiler.php:382, 458`).
9. Las piezas (`:703-733`): la base de los webhooks, las 3 referencias, un **cupo ≥ 1** y el **id del número**. Faltan las dos últimas.
10. `VOICE_NUMBER_INBOUND_CAPABLE=false` con un identificador verificado. Sin esa declaración, intenta asignar el número y falla con 409 `voice_number_not_assigned` (`:300-318`; hallazgo C-5).

**Efecto en ElevenLabs:** **crea siempre un agente nuevo**; nunca modifica uno existente. Se llama «**Iron Body · voz · publication · <12 primeros caracteres de la specHash>**» (`ElevenLabsAgentMapper.php:89, 168`) y lleva sus herramientas, `end_call`, `call_limits` (diario = cupo de salientes + cupo de entrantes, concurrencia 1, sin ráfagas: `:129-133`), autenticación y el webhook post-llamada propio (`:158-163`). Con un número solo saliente, no toca el número.

**¿Antes de tener la telefonía?** **No.** Sin `phone_number_id` y sin cupo responde 409 `voice_not_deployable`, y el cupo solo existe tras la constante y `salientes on` (H-26, reproducido en el banco).

**Comprobar:** `estado` dice `cadena=canary:ok`; en el panel, `publication.canary.status=ok` con su `providerAgentId`; `voice:reconcile --dry-run --json` sin deriva; el smoke 4a.

**Revertir:** «Apagar la voz», el nivel 2, que inserta una entrada sin agente. `publicar off` solo impide nuevas publicaciones. El agente queda en ElevenLabs, inerte, para la auditoría.

### 4.5 Entrantes

Con 0 números propios es imposible recibir llamadas [P]. La importación de …5483 es `supports_inbound:false` (`PROVEEDORES_ESTADO_REAL.md:12`). En el código, `entrantes on` se niega con un número declarado solo saliente (`h8-voice-scope.sh:760`), publicar no le asigna agente (`StudioVoicePublishing.php:300`), y además todo exige la constante.

**Qué habría que comprar o configurar:**
1. Un número de Twilio con voz en la (sub)cuenta: un local de Colombia con el expediente regulatorio (registro mercantil y dirección dentro de la localidad), o portar uno existente.
2. Importarlo en ElevenLabs con entrantes.
3. `TWILIO_VOICE_FROM` e `ELEVENLABS_AGENT_PHONE_NUMBER_ID` apuntando a él, y `VOICE_NUMBER_INBOUND_CAPABLE=true` o sin declarar.
4. Una publicación, que entonces le asigna el agente canario.
5. `entrantes on --cupo=N`.
6. Las decisiones P-7 a P-9 y E18-6 (una entrante cuyo webhook de iniciación falla se corta sin el mensaje de «no disponible»).

**Ya preparado:** `InboundGate` e `InboundPolicy`, la ruta de iniciación con su secreto registrado [P], la asignación al publicar, la vigilancia de deriva del número y `entrantes on/off`.

### 4.6 Monitor de voz: qué vigila, cuándo frena y qué puede hacer frenar el propietario

| Tarea (`routes/console.php:439-488`) | Qué vigila | Cuándo echa el freno | Hoy |
|---|---|---|---|
| `voice:reconcile`, en los minutos 10, 20, 30, 40 y 50 | Las llamadas de Twilio de la cuenta configurada en las últimas 48 h frente a `voice_calls`; las `requested` de más de 15 min (pasan a `ambiguous`); la deriva de los agentes publicados; las atascadas; el techo de gasto | Llamada desconocida (`:236-238`), deriva del agente, coste comprometido por encima del presupuesto y mayor que 0 (`:647-658`), cortada sin colgado confirmado | Twilio inerte (`not_configured`), sin agentes publicados |
| `voice:reconcile --numbers`, cada hora | Lo anterior, más la identidad del número importado y a qué agente apunta | `number_is_messaging`, `number_mismatch`, `number_drift` (`:601-630`) | Inerte (sin id) |
| `voice:webhook-health`, en el minuto 5 de cada hora | 2 GET: el webhook post-llamada y los 2 secretos frente a las referencias de Laravel | Webhook ausente, desactivado (a mano o por ElevenLabs), con otra URL, sin HMAC (también si no informa `auth_type`), o falta un secreto (`VoiceWebhookHealth.php:140-157`) | **Activa.** Primera pasada a las 19:05 UTC (R2) |
| `voice:prune-rate-limits`, diaria a las 04:40 de Bogotá | Purga local | Nunca | — |
| `integrations-snapshot --verify-voice`, los lunes a las 07:20 de Bogotá (12:20 UTC) | Un GET al agente de verificación (`ELEVENLABS_AGENT_ID`) | No echa el freno, pero **cualquier fallo borra la marca de verificación**: el laboratorio responde `credentials_unverified` y las salientes `elevenlabs_unverified` (`VoiceProviderState.php`, comentario de la clase) | La próxima es el lunes 16-11 |

**Efecto del freno:** deniega publicar (409 `voice_braked`), las salientes, las entrantes y las herramientas salvo `no_volver_a_llamar`. En el laboratorio **se pueden abrir sesiones, pero planes y horarios responden `voice_braked`** (`VoiceTools.php:113-114, 273-277`). Lo suelta una persona con `ultron.voice_publish` y una nota (`VoiceBrake.php:244-251`), y vuelve a echarse si la causa sigue.

**Riesgo operativo: qué puede hacer el propietario que eche el freno o bloquee** (hay que avisarle):
- **Asignar el webhook «ironbody-voz-post-llamada» a un agente suyo, o a todo el workspace.** Cada conversación de ese agente pasa a `unknown_provider_call` y echa el freno **en el acto**, con el monitor encendido o no (`VoicePostCall.php:177-190`; el laboratorio la rechaza con `not_a_lab_session`: `StudioVoiceLabSessions.php:496-499`). Probar sus agentes manuales («Sofia Asesora de ventas», «ULTRON Voice») **sin ese webhook no frena**: el webhook va por agente (`h8-secrets-register.sh:13-15`).
- **Probar desde la consola el agente publicado** (cuando exista): freno inmediato (`VoicePostCall.php:208-211`). **Duplicar un agente del Studio:** la copia probablemente hereda el webhook y frenaría [H]. **Probar desde la consola un agente de laboratorio del Studio:** lo reclama su sesión, así que no frena, pero añade conversación y coste a esa sesión (`StudioVoiceLabSessions.php:502-544`).
- **Editar, borrar o desactivar en la consola el webhook o los secretos** «ironbody-voz-herramientas» e «ironbody-voz-iniciacion», o cambiar su URL: freno en el minuto 5 siguiente.
- **Editar un agente «Iron Body · voz · …» publicado:** `agent_drift` y freno. **Asignar un agente al número importado:** `number_drift` y freno.
- **Llamar por teléfono desde ElevenLabs con un número de la cuenta cuya key tenga Laravel:** freno en 10 minutos o menos. Hoy no, porque Twilio no está configurado en Laravel.
- **Borrar o tocar el agente de verificación** («ULTRON Voice — Iron Body Neiva», …r2fn, `ELEVENLABS_AGENT_ID` [P]), **o revocar la clave vieja antes de que el servidor tenga la nueva:** la verificación del lunes falla, se borra la marca y el laboratorio queda en `credentials_unverified`.

### 4.7 Revisión 3 del perfil

**La crea** alguien con `ultron.voice_manage`, el Super Admin por defecto, como revisión 3 basada en la 2. Lleva `no_volver_a_llamar` (obligatoria), `consultar_planes`, `consultar_horarios` y `end_call` (`StudioVoiceCompiler.php:188-190, 564`). **La envía a revisión** quien la creó. **La aprueba** **otra identidad** con `ultron.approve`; también lo impide la base (`usvp_four_eyes_chk`, `StudioVoiceProfiles.php:46-48`).

**No invalida nada de la rev. 2:** la rev. 2 sigue aprobada y publicable, porque no existe un estado «sustituido» (`StudioVoiceProfile.php:73-81`). Pero las herramientas entran en la especificación (`StudioVoiceCompiler.php:250, 275`), así que la rev. 3 tiene **otra specHash**: **los veredictos de la rev. 2 no le sirven**. Necesita su propia sesión del laboratorio (que admite el perfil ya enviado a revisión, antes de aprobarlo: `:458`) y el veredicto de otra persona.

**Mínimo frente a recomendado:** la primera llamada puede hacerse con la rev. 2, que saluda, da el aviso de IA y atiende la baja, pero no informa planes ni horario. La rev. 3 es lo que el propietario espera de Sofía.

### 4.8 Textos del CRM que no dicen la verdad hoy

| Fichero:línea | Dice | Debería decir |
|---|---|---|
| `ultron-studio/studio-voice-activation.ts:85` (`plan`) | «Falta el plan de pago de ElevenLabs … (D-4)» | «Laboratorio apagado: encenderlo con `lab on --driver=elevenlabs` y su presupuesto, con tu autorización. El plan Creator ya está contratado.» |
| `…activation.ts:86` (`planSimulador`), `:87` (`primeraSesion`), `:114` (`veredictoPlan`), `:252` y `:267` (huecos) | Dependen del plan o de «confirmar los minutos de agente» | Quitar la condición del plan (comprado el 11-11). `primeraSesion`: «Falta una sesión real (gasta del presupuesto del laboratorio).» |
| `commercial-automation/ca-activation.ts:77` (`v9`) | «… y estas, el plan de pago de ElevenLabs.» | «… y estas, un número de voz que no sea el de WhatsApp, la credencial de Twilio y las autorizaciones de la 4a.» |
| `…activation.ts:93` (`numero`) | «Falta el número de voz … verificado en la subcuenta» | «Falta un número de origen que **no** sea el de WhatsApp: …5483 es el de WhatsApp (protección 6). Verifica otro como identificador de llamada (gratis) o compra uno.» |
| `…activation.ts:95` (`importacion`) | «Falta el id del número importado … (D-2)» | Añadir: «La importación actual (…5483) no se puede usar: importa el número nuevo como solo saliente.» |
| `…activation.ts:92` (`twilio`) y `studio-voice-text.ts:138` (fase 2a) | «Falta la subcuenta de telefonía…» | «Falta la key restringida de voz de Twilio (subcuenta recomendada) y un número que no sea el de WhatsApp.» El código no exige subcuenta |
| `studio-voice-status.ts:81` | «Subcuenta de Twilio: Sin configurar» | «Credencial de Twilio de la voz (cortar y conciliar): sin configurar». Pinta `carrierConfigured`, que no significa subcuenta |
| `studio-voice-text.ts:198` (línea, Twilio Voice) | «Apagado» | Si no hay número: «Sin configurar: faltan el número y la credencial (no se puede encender aún)». Hoy dice «apagado» lo que en realidad espera una credencial |
| `studio-voice-text.ts:203` (línea, Salientes) | «Apagadas» | «Cerradas: esperan número, credencial, `voice(calls-open)`, publicación y tu autorización» |
| `studio-voice-text.ts:202` (línea, Entrantes) | «Apagadas» | «No disponibles: no hay un número propio de Twilio» |
| `…activation.ts:430` (monitor, `operativa`) | «Concilia las llamadas cada 10 minutos y comprueba el número cada hora…» y estado «Disponible y operativa» | «Encendido: vigila el webhook post-llamada y los secretos cada hora y el techo de gasto. Conciliará llamadas y número cuando estén la credencial de Twilio y el número.» Debería verse como en observación |
| `…activation.ts:304-345` (publicación) | Solo menciona el veredicto y el cupo | Falta «el id del número importado» (`missingPieces` incluye `phone_number_id`) |

El estado «Pendiente de proveedor externo» (`activation-state.ts:44`) en las salientes y en Twilio sugiere que se espera a Twilio o a ElevenLabs. **Se espera al propietario**: su decisión sobre el número y la key. Estos cambios son de frontend: este informe no toca nada.

### 4.9 Rotación de la clave de ElevenLabs

1. **Propietario, en la consola de ElevenLabs:** crea una clave nueva con los mismos permisos que la actual (verlos en la consola) y, como mínimo, Agents de lectura y escritura y lectura de los webhooks del workspace [H: nombres exactos], con cupo de créditos. Todavía **no** revoca la vieja.
2. **Servidor, con el propietario en la terminal:** `install -m 600 -o root -g root /dev/null /root/el-clave.txt && vi /root/el-clave.txt` (una línea; nunca `echo` ni el chat; `ops/modelos-root/README.md`). Hacerlo **sin sesión del laboratorio abierta** y fuera de los minutos 0, 5 y de las decenas: el guion apaga `ELEVENLABS_ENABLED` unos segundos (`el-verificar.sh:35, 54-66`).
3. `bash /root/el-verificar.sh /root/el-clave.txt --solo-clave` → «ELEVENLABS VERIFICACIÓN TERMINADA». Conserva el agente, la región y los 3 secretos (H-16), renueva la marca y destruye el fichero de la clave.
4. **Verificar sin imprimir nada:**
   - `bash /root/h8-voice-scope.sh estado` → «ElevenLabs según el Studio: ELEVENLABS_VERIFIED»;
   - `sudo -u www-data php artisan voice:doctor --telefonia --json` → `providerVerification.verifiedAt` nuevo;
   - `bash /root/h8-secrets-register.sh --solo-comprobar` → código 0 (la clave nueva lee los secretos y los webhooks);
   - `sudo -u www-data php artisan voice:webhook-health --dry-run --json` → `ok`.
5. **CRM, otra persona:** Integraciones → Voz → reconocer. La huella de la clave entra en la de la voz y el monitor del H7 está encendido, así que sin este paso abriría `config_drift:voice` cada 30 min.
6. **Propietario:** revoca la clave vieja. Después, `sudo -u www-data php artisan voice:doctor --verify`, que hace un GET y no guarda nada, para demostrar que se usa la nueva. **No** usar `--verify-voice` para esto: un fallo borraría la marca.
7. **Vuelta atrás:** hasta el paso 6, instalar `/root/env-pre-elevenlabs-<TS>` y regenerar la caché (la vieja todavía vale). **No** usar la vuelta que propone el guion en un fallo (`ELEVENLABS_ENABLED=false`, `:72`): apagaría el laboratorio. Después del paso 6 la vieja está quemada: si algo falla, otra clave nueva. La copia previa lleva la clave vieja: borrarla con `shred -u` cuando ya no haga falta.

---

## 5. Hallazgos aparte (no bloquean, salvo que se diga)

- **C-1.** `salientes on` compara el presupuesto con la reserva **sin transferencia** (0,6105; `h8-voice-scope.sh:754-755`), pero la puerta reserva **siempre con transferencia a fijo** (0,9605 a un móvil; `OutboundGate.php:133`). Con un presupuesto entre 0,61 y 0,96 el mando lo acepta y la puerta deniega todo con `budget_exhausted`. `ACTIVACION_POR_ETAPAS.md:52` repite el «≈ 0,61». Con 2,00 no pasa.
- **C-2.** El comentario de `routes/console.php:459-464` dice que `webhook-health` «no echa el freno». Desde SC-3 sí lo echa (`VoiceWebhookHealth.php:46-58`).
- **C-3.** `el-verificar.sh` no toma los cerrojos comunes del fichero de entorno, y su vuelta atrás ante un fallo apaga ElevenLabs entero (§4.9).
- **C-4.** Tras `voice(calls-open)` caen en rojo las guardas de la constante (`VoiceIsolationTest.php:330-334`, `VoiceCallPolicyTest.php:77-80`; quizá otras de la lista de `grep CALLS_ENABLED_IN_CODE tests/` [H]). El commit de una sola línea no puede tocarlas. Hay que decidir antes, con el reviewer, cómo se tratan, sin relajarlas.
- **C-5.** Publicar sin `VOICE_NUMBER_INBOUND_CAPABLE=false` con un identificador verificado da 409 `voice_number_not_assigned`. Ya está en el plan (4a.2), pero el CRM no lo dice.
- **C-6.** El saldo de Twilio (US$ 21,80 el 10-11 [P]) es el mismo que paga los SMS del OTP. Las llamadas de voz y las pruebas manuales lo consumen, y sin saldo **cae el inicio de sesión**. Conviene una alerta de saldo o la recarga automática (decide el propietario).
- **C-7.** Residual de E18-9: una fila sin ids (`requested` o `ambiguous`) todavía se alcanza por `call_ref` desde otra conversación. Falla cerrado (`seal_mismatch` y freno); con 1 llamada al día el riesgo es bajo.

## 6. Qué se comprobó y qué no

- **Ejecutado:**
  - `TelephonyFixtureParityTest`: 15 pruebas y 703 aserciones, OK. Incluye la guarda de WhatsApp y la reserva de 960 500 µUS$.
  - `VoiceTwilioAccountTest`: 2 pruebas y 31 aserciones, OK. La cuenta principal y la subcuenta piden lo mismo, y una llamada ajena frena igual.
  - `VoiceLabPostCallTest`: 4 pruebas y 24 aserciones, OK. Una conversación web desconocida frena.
  - Evaluación pura con `php -r`: `collides("+573143455483", "+57 314 345 5483")=true`; reserva a un móvil sin transferencia 610 500, en la puerta a un móvil 960 500 y a un fijo 1 122 000; 0,70 US$ no cubre una llamada y 2,00 sí.
- **No comprobado** (no se puede sin producción ni red):
  - si `WHATSAPP_DISPLAY_PHONE` o `WHATSAPP_PROTECTED_NUMBERS` contienen …5483 en producción (R1);
  - el `auth_type` real del webhook (R2);
  - si el importador de ElevenLabs admite una API key;
  - si la llamada de verificación de Twilio aparece en el registro de llamadas;
  - los nombres exactos de los permisos de las keys;
  - cómo se muestra en Colombia un identificador verificado;
  - si duplicar un agente en ElevenLabs copia su webhook.

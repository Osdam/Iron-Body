# Informe del backend de voz · la primera llamada de Sofía sobre la punta (cierre, 22-11-2026)

**Base:** rama `cierre/voz` = `0c57d7c4` (`main`). La constante `CALLS_ENABLED_IN_CODE` sigue en `false` en la rama. Para medir con ella abierta usé un árbol desechable: `_worktrees/cierre-voz-abierta/Iron-Body`, rama local `tmp/voz-abierta-medicion` = `0c57d7c4` + `cbf94374` (la línea abierta, simulada) + los tres parches aplicados con `git am`. Esa rama no se integra y la he dejado tal cual. Sin red hacia los proveedores, sin producción y sin llamadas.
**Marcas:** **[C]** comprobado ejecutando · **[L]** leído en el código · **[H]** hipótesis, solo se ve con el proveedor real.

## 0. En corto

1. **La apertura está lista sobre la punta.** Con la línea abierta caen los mismos 69 casos del 11-11 (35 métodos, 21 ficheros). Los tres parches aplican limpios y lo dejan todo en verde. Con la constante cerrada, los parches siguen haciendo caer pruebas, así que no son tautológicos. No hay que rehacerlos y sus huellas no cambian [C].
2. **El E2E pasa en los dos sentidos** (fuente cerrada y fuente abierta): 3 fases, 26 pruebas y 661 comprobaciones, y el **mismo hash de resultados** `832085b4…` [C].
3. **No he cambiado código.** El recorrido no muestra ningún defecto que impida la llamada. Corrijo el runbook en nueve puntos que la punta dejó viejos (§5). Dos riesgos son para el lead: H-1 y H-2 (§8).
4. **Tres cosas que el propietario tiene que saber antes de la llamada:** si no contesta, ese día ya no se puede repetir (la frecuencia legal vale también para el canario); Sofía no tiene los **beneficios** de los planes; y con un solo canario, las 3 llamadas de la evidencia no caben en una semana.

## 1. El recorrido de la llamada real

### a. Inicio desde el CRM

| Punto | Evidencia | Prueba |
|---|---|---|
| Ruta y permiso | `routes/voice.php:66` `POST /api/admin/voice/calls`. Cupo de escritura de 30 por minuto y persona. `AuthorizationMap.php:908` → `telephony.call_place`. Por defecto solo lo tiene el Super Admin (`CrmPermission.php:322`). Además, una persona con la llave (`VoiceCallController.php:75`) | `VoiceCrmRoutesTest::test_cada_ruta_exige_sesion_persona_y_su_llave`, `::test_el_administrador_por_defecto_ve_el_registro_pero_no_llama_ni_administra_contactos` |
| Antes de la política | 422 con un cuerpo mal formado (`:76-84`). 404 si el canario no está en el registro (`OutboundGate.php:271-275`). La misma persona con el mismo id de petición: 200 con la misma fila, o 409 `client_request_reused` si es otro destino (`:111-121`). 409 `outbound_race` por el índice de una viva por número (`:95-99`) | `::test_la_puerta_valida_el_cuerpo_el_destino_y_el_id_de_la_peticion`, `::test_la_misma_peticion_de_la_misma_persona_…` |
| La política, **en orden** (409; se acumulan todos los motivos; `OutboundPolicy.php:43-51, 98-173`) | 1 `calls_disabled_in_code` · 2 `outbound_disabled` · 3 `twilio_voice_disabled` · 4 `no_voice_number` · 5 `voice_number_is_whatsapp` · 6 `number_unverified` (sin una conciliación del número de menos de 2 h) · 7 `number_mismatch` · 8–10 `elevenlabs_not_configured`, `elevenlabs_disabled` o `elevenlabs_unverified` (la marca de 30 días) · 11–13 `no_publication`, `publication_unreadable` o `agent_drift` · 14 `voice_braked` · 15 `not_an_active_voice_canary` · 16 `excluded` (también un «no me vuelvas a llamar» anterior) · 17 `outside_hours` · 18 `holiday` · 19 `frequency_exceeded` · 20 `concurrency_exceeded` · 21 `daily_cap_zero` o `daily_cap_reached` · 22 `budget_exhausted`. Con el alcance canario no aplican `source_not_allowed`, `contact_not_authorized`, `no_voice_consent` ni `rne_check_missing` | `TelephonyFixtureParityTest::test_outbound_policy_cuadra_con_los_43_casos_del_fixture` (37 casos `decide · canary`) |
| Domingo y festivo **con el canario** | **También se niega.** El calendario va en `targetReasons()` para todos los alcances (`OutboundPolicy.php:220-226`). `LEGAL` no tiene domingo (`OutboundSchedule.php:29`), así que el domingo da `outside_hours` y el festivo da `holiday`. Comprobado con `php -r`: el lunes 23-11 de 07:00 a 18:59:59 está dentro; las 19:00 y el sábado a las 15:00 están fuera; el 08-12 es `holiday`. Próximos festivos: 08-12, 25-12 y 01-01. El horario propio (`VOICE_OUTBOUND_OWN_HOURS_*`), si se configura, solo estrecha la ventana | fixture «decide · canary · fuera de horario» y «· festivo»; `PlaceOutboundVoiceCallRecheckTest::test_un_job_que_corre_fuera_de_la_ventana_o_en_festivo_cancela_con_su_motivo`; `ColombianHolidaysTest` |
| La frecuencia **con el canario** | Una al día (no se configura) y como mucho dos en 7 días móviles (`VoiceFrequency.php:26, 74-79`). Cuentan `no_answer`, `busy`, `failed`, `ambiguous` y `hung_up` (`:36`). Una segunda petición el mismo día da exactamente `[frequency_exceeded, daily_cap_reached]` [C, con `php -r` sobre la política abierta]. Una tercera en 7 días da `[frequency_exceeded]` | fixture «decide · canary · frecuencia agotada» |
| Fila, reserva y job | Fila `requested` con su sello y una reserva de 960 500 µUS$ a un móvil (`OutboundGate.php:132-134, 166-188`), el evento `outbound_requested` y el job al confirmar (`:189-193`). El job va en la conexión y la cola `voice` (`PlaceOutboundVoiceCall.php:85-89`), con un intento y 30 s | `PlaceOutboundVoiceCallTest::test_el_job_es_de_un_intento_en_su_carril_y_con_su_plazo`; con la apertura, `assertOutboundAccepted` (T2: UN job en `voice`) |
| El job, justo antes de marcar | Comprueba, en este orden (`:150-216`): la constante; `outbound_disabled`; `twilio_voice_disabled`; el número; el freno; `publication_moved`; la deriva (`drift_unverified` o `agent_drift`); el destino y el calendario. Después, `error` si el número ya no es el sellado o falta el id del número importado (`:113-121, 234-236`) | `PlaceOutboundVoiceCallRecheckTest` (8 métodos) |
| `ElevenLabsCallStarter` | UN `POST /v1/convai/twilio/outbound-call`, sin reintentos ni redirecciones y con un plazo de 20 s como mucho (`ElevenLabsCallStarter.php:56-89`). El cuerpo lleva el número importado, el destino, la grabación apagada, 30 s de timbre y el buzón `hang_up` (`:97-114`). La tabla de resultados (`:127-155`): `started` → `dialing`; `rejected` → `failed_to_start`; cualquier otra cosa → `ambiguous` | `ElevenLabsCallStarterTest::test_el_cuerpo_de_la_peticion`, `::test_la_tabla_de_resultados`; `PlaceOutboundVoiceCallTest::test_cada_resultado_del_iniciador_…` |

### b. Los datos comerciales en una llamada REAL (no solo en el laboratorio)

- **Las herramientas responden en una llamada real** [L]. `VoiceTools::find()` encuentra la llamada por `conversation_id` o por `call_sid`, que guarda el job al marcar (`PlaceOutboundVoiceCall.php:280-286`). Si la fila aún no tiene ids, la encuentra por `call_ref`. La llamada tiene que estar en `dialing` o `active` (`VoiceTools.php:101-126, 201-223`). Los ids los rellenan las variables del sistema, nunca el modelo (`ElevenLabsAgentMapper.php:81-85`). Solo el camino `lab()` (`:265-282`) depende de una sesión del laboratorio, y no aplica a las llamadas. Pruebas: `VoiceToolsTest::test_cada_caso_del_fixture` (la primera herramienta de una saliente en `dialing` la pasa a `active`) y `::test_la_saliente_que_aun_no_guardo_sus_ids_se_sigue_encontrando_por_su_call_ref`.
- **Las herramientas de la publicación las pone el perfil** (`StudioVoiceCompiler.php:272`). La rev. 2 solo tiene `no_volver_a_llamar` (§4.7 de la auditoría C). La rev. 3 añadiría `consultar_planes` y `consultar_horarios`. `solicitar_humano` solo va si hay transferencia.
- **Planes y precios:** `Plan::sellable()` (activo, vendible, sin archivar, con precio y duración mayores que 0; `Plan.php:302-311`), el mismo criterio que WhatsApp (`GymFactsProvider.php:91`). Hasta 20 planes, con nombre, vigencia en días, si se compra encima de una membresía y el precio exacto de la base (`VoiceTools.php:311-331`). Prueba: `VoiceToolsTest::test_consultar_planes_solo_da_los_vendibles_con_el_precio_exacto_y_consultar_horarios_sin_hechos_no_inventa`.
- **HUECO, de contenido:** los **beneficios** y las reglas de acceso del plan no llegan a la voz. Se quedan fuera `benefits`, `access_mode`, `access_days`, `access_windows`, `access_classes`, `entry_credits` y `restrictions` (`Plan.php:31-43`). WhatsApp sí lleva `benefits` (`GymFactsProvider.php:96`). Como el prompt prohíbe afirmar un beneficio que no le haya dado una herramienta (`StudioVoiceTemplate.php:487`), Sofía dirá que el equipo lo confirma por WhatsApp. Cambiarlo es una enmienda de D8-10 y no lo he tocado (§8, H-3).
- **Sede y horario:** salen de `VOICE_FACTS_SITE` y `VOICE_FACTS_HOURS` (`config/voice.php:260-263`, `VoiceTools.php:334-343`). Sin ellos, «no disponible». La auditoría C dice que en producción están fijados [P].
- **Datos de la persona en la iniciación: ninguno, por diseño (D8-11).** Las variables son solo `caller_authorized=si`, `call_direction=saliente`, `call_ref` y `version_label` (`PlaceOutboundVoiceCall.php:243-248`). Sofía no conoce el nombre ni el plan de quien recibe la llamada.

### c. Qué compila `StudioVoiceCompiler` y qué llega a la voz

| Origen | Parámetro o capa | ¿Llega? | Dónde |
|---|---|---|---|
| Versión | `persona.tone`, `strategy.goal`, `intent.fallback` | Sí: «CÓMO HABLAS» y «TU OBJETIVO» | compilador `:409-420` → `StudioVoiceTemplate.php:484-485` |
| Versión | `knowledge.strictFacts`, `strategy.discountsAllowed`, `rules.paymentLinksEnabled`, `rules.handoffOnlyCorroborated` | No al texto: son cerrojos. Si no tienen el valor seguro, la compilación da 422 | compilador `:396-406` |
| Versión | frases `forbid text contains` de las reglas S-NNN vivas | Sí: «NUNCA … usas estas expresiones» y `spec.forbiddenPhrases`, que entra en la huella. **La guarda posterior no las vigila** (§8, H-4) | `:771-783`, `:283`; `StudioVoiceTemplate.php:612-628` |
| Versión | el resto de capas (context, memory, composer, integrations, governance, la base de conocimiento, los planes del motor) | No. Lo comercial solo llega por las herramientas en la llamada | el compilador no lee nada más (`:375-421`) |
| Versión | `catalogVersion` | No es texto. Si es otro catálogo, `version_catalog_mismatch` | `:390-392` |
| Perfil | `voiceId`, `language` | Sí: `tts.voice_id` y `agent.language` | mapper `:173, :182` |
| Perfil | `aiNotice` (el de la plantilla en su trato) y `addressForm` | Sí: `first_message`, «IDIOMA Y TRATO» y «NEGATIVAS EXACTAS» | compilador `:513-519`; mapper `:172`; plantilla `:477, :500` |
| Perfil | `greetings.inbound` y `.outbound`, `holes.presentation` y `.closing` | Sí: el prompt | plantilla `:483, :486, :499` |
| Perfil | `tools`, `systemTools`, `transfer.destinations` | Sí: herramientas de servidor, `built_in_tools` (`end_call`, y la transferencia si la hay) y los números descifrados, solo los aprobados (R-07) | mapper `:179, :198-…`; compilador `:351-366` |
| Perfil | `maxDurationSeconds`, `privacy`, `voicemail` | Sí: `conversation.max_duration_seconds` y `platform_settings.privacy`. El buzón no va en el agente: va en cada llamada (`hang_up`) | mapper `:134-141, :184`; `ElevenLabsCallStarter.php:108` |

### d. Estados y límites

- **Estados:** `requested → dialing` lo hace el job con `started` (`PlaceOutboundVoiceCall.php:279-291`). `dialing → active`, **solo con la primera herramienta** (`VoiceTools.php:120-126`, E1-1); Twilio en curso sigue siendo `dialing` (`VoiceReconciler.php:29-31`). `→ completed`, con la `post_call_transcription`, desde `dialing` o `active` (`VoicePostCall.php:152-159`; caso del fixture «saliente contestada sin herramientas: dialing → completed», `VoicePostCallWebhookTest::test_cada_cuerpo_del_fixture_deja_lo_que_dice_el_contrato`).
- **Finales de fallo:** `call_initiation_failure` → `busy`, `no_answer` o `failed` (`VoicePostCall.php:105-112`; la conciliación hace lo mismo con el estado de Twilio, `VoiceReconciler.php:93-97`). El proveedor dice que no → `failed_to_start`. Sin respuesta legible, o si el job muere → `ambiguous` (`PlaceOutboundVoiceCall.php:135-148, 298-304`). El buzón cuelga por detección de contestador (`ElevenLabsCallStarter.php:52, 108`); qué estado deja es [H].
- **El tope de 300 s lo impone ElevenLabs** con `max_duration_seconds` del perfil, que no puede pasar de `VOICE_PER_CALL_MAX_SECONDS` (compilador `:543-545`; mapper `:184`; `ElevenLabsAgentMapperTest::test_la_carga_fija_todo_lo_que_protege`). Laravel no corta a los 300 s: reserva 300 s (`OutboundGate.php:132-133`) y solo cuenta las que llevan más de 10 min en `dialing` (`VoiceReconciler.php:144-150`), sin frenar.
- **El timbre de 30 s:** `VOICE_OUTBOUND_RINGING_SECONDS` (`config/voice.php:184`) → `ringing_timeout_secs` (`PlaceOutboundVoiceCallTest::test_el_cuerpo_de_la_peticion_cuadra_con_las_aserciones_del_fixture`).
- **La post-llamada:** el sello observado, `completed` y la guarda en memoria; una violación echa el freno (`VoicePostCall.php:118-174`; `::test_una_violacion_de_la_guarda_abre_el_freno_y_solo_guarda_codigos`).
- **El coste:** lo comprometido es el mayor entre la reserva y lo real (`VoiceCostGuard.php:150-175`). El precio de Twilio lo escribe la conciliación (`VoiceReconciler.php:456`); el de ElevenLabs llega crudo, sin convertir (`VoicePostCall.php:129-130`). Pruebas: `VoiceReconcilerTest`, `VoiceCostCapZeroBudgetTest`.
- **El historial:** `voice_calls` y un evento `voice_call_events` en cada paso (`outbound_requested`, `outbound_started`, `tool_call`, `post_call_transcription`, `guard_verdict`, `hangup_requested`). En el CRM, sin datos personales (`VoiceCrmRoutesTest::test_el_registro_y_el_detalle_no_llevan_datos_personales`, `VoiceCallDetailEventsTest`).

### e. Corte y supervisión

- **«Colgar» en el CRM** (`VoiceCallController.php:91-101` → `VoiceHangup.php:94-130`): una `requested` pasa a `canceled` sin red; una `dialing` o `active` se cuelga en Twilio con la key de voz y pasa a `hung_up`; sin el SID de Twilio, `no_carrier_id`; una `ambiguous` no se puede cortar. Pruebas: `VoiceCrmRoutesTest::test_el_corte_cancela_una_pedida_y_sin_credencial_no_cuelga_una_en_curso`, `VoiceHangupCanceledTest`.
- **`voice:hangup --all --confirm`** corta las `requested`, `dialing` y `active` (`VoiceHangupCommand.php:54`; `VoiceCommandsTest::test_voice_hangup_valida_sus_argumentos_y_cancela_las_pedidas_sin_red`).
- **El freno** niega la puerta, el job y las herramientas, salvo la baja (`VoiceTools.php:113`).
- **El monitor:** `VoiceReconciler` en los minutos :10…:50 y el número cada hora; `VoiceWebhookHealth` en el :05, que frena con `webhook_unhealthy` (`VoiceWebhookHealth.php:46-58, 154-155`; `VoiceWebhookHealthBrakeTest`).
- **Si no llega la post-llamada:** con la key de Twilio, la conciliación lleva la fila a `completed` según Twilio y anota su precio, pero sin veredicto de la guarda; la evidencia cuenta `missingPostCall`, que bloquea promover (`VoiceEvidence.php:29, 78`). Sin la key, la fila se queda viva y la concurrencia niega la siguiente.

### f. Entrantes (no he construido nada)

- **Lo que está hecho** [L]: `ElevenLabsInitiationController` autentica con el secreto (`:31-41`) y limita por llamada (`:45-50`). `InboundGate` deja una fila en cada iniciación; con `VOICE_NUMBER_INBOUND_CAPABLE=false` niega siempre con `number_outbound_only` (`InboundGate.php:122-126`) y comprueba el número llamado y el sello (`:127-136`). `InboundPolicy` pide un canario activo, el cupo, el freno y la publicación. Pruebas: `VoiceInitiationWebhookTest`.
- **Lo que falta, EXACTO:** (1) un **número de Twilio con voz** en la (sub)cuenta de voz: un local de Colombia con su expediente regulatorio, o uno portado (un identificador verificado no recibe llamadas); (2) importarlo en ElevenLabs **con entrantes** (`supports_inbound: true`); (3) `TWILIO_VOICE_FROM` y `ELEVENLABS_AGENT_PHONE_NUMBER_ID` apuntando a él, con `VOICE_NUMBER_INBOUND_CAPABLE` en `true` o sin declarar; (4) publicar para asignarle el agente canario; (5) `entrantes on --cupo=N`.

## 2. Qué corregí

Nada de código: el recorrido no muestra ningún defecto en mi zona que impida la llamada; lo que encontré son decisiones o riesgos (§8). Solo documentación: `ops/RUNBOOK_PRIMERA_LLAMADA.md` (nueve puntos marcados «(22-11)», §5), `ops/apertura-llamadas/README.md` (la medición del 22-11), la evidencia del E2E en `ops/evidencia-h8/2026-11-22/20261122T050408Z-0c57d7c4/` (pasó el barrido) y este informe.

## 3. La apertura, medida sobre `0c57d7c4` [C]

- **Solo la línea abierta:** voz y telefonía dan `Tests: 892, Failures: 26`. Otros 89 ficheros (V9, B9Cierre, `Facts`, `Guards`, W9, `Unit/Outreach/Core`, `Unit/Twilio`, los de voz e integraciones del Studio, y todas las pruebas nuevas o cambiadas desde `1ef1d72e` en `Outreach` y `UltronStudio`) dan `Tests: 2613, Failures: 43`. Son **69 casos, idénticos a la lista del 11-11**: el `diff` contra `04-…` y `05-…` sale vacío, quitando las dos puertas de diff contra git, que caían en los dos estados. `app/Services/{Voice,Telephony}` no ha cambiado desde `1ef1d72e`.
- **`git am`:** limpio, y `range-diff` da «=» en los tres. Las huellas no cambian: `9f51b30a…`, `5e5c61de…` y `361c9ff2…`. `bateria-rapida.txt` sigue con 24 ficheros.
- **Abierta y con los parches:** batería rápida `OK (624 tests, 24944 assertions)`; voz y telefonía `OK (901, Skipped: 163)`; los 89 ficheros `OK (2614, Skipped: 148)`; el comprobador del fixture `RESULTADO: OK`; PostgreSQL 14, `Feature/{Telephony,Voice}`: `Tests: 528, Skipped: 27`, sin fallos (la cifra del 11-11).
- **Con la constante cerrada y los parches:** `Tests: 624, Errors: 10, Failures: 69`. Caen 79 casos, la cifra del 11-11, y nada fuera de lo que cambian los parches. `StudioIntegrationsParityTest` cae porque lee el fixture `integrations-cases.json`, que cambia `T`.

## 4. El arnés E2E y las baterías [C]

E2E: `H8_E2E_VENTANA= H8_E2E_DB=ultron_studio_h8_e2e_cvoz bash backend/docs/ultron-studio/ops/h8-e2e.sh todas`. Lo lancé a las 00:04 de Bogotá, fuera de la ventana de publicar; por eso `H8_E2E_VENTANA` va vacía, como indica su README.
```
— rama cierre/voz (0c57d7c4): «la fuente trae la constante CERRADA»
cerrada: pruebas 6 (6 OK, 0 FALLO, 0 OMITIDAS) · comprobaciones 113 (113 OK, 0 FALLO) · 14 s
abierta: pruebas 9 (9 OK, 0 FALLO, 0 OMITIDAS) · comprobaciones 301 (301 OK, 0 FALLO) · 73 s
salientes: pruebas 11 (11 OK, 0 FALLO, 0 OMITIDAS) · comprobaciones 247 (247 OK, 0 FALLO) · 55 s
hash de resultados …: 832085b4ae85cf34c6423f6d8bd70ce3dfba4a47b99e397651994d37af1f887b
— árbol desechable (d85035ac = apertura simulada + T + T2 + T3): «la fuente trae la constante ABIERTA»
cerrada: … 6 OK … 113 OK · abierta: … 9 OK … 301 OK · salientes: … 11 OK … 247 OK   (mismo hash 832085b4…)
```
**Las baterías de la rama `cierre/voz`** (constante cerrada, SQLite; `nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging …`, una cada vez):
- `tests/Unit/{Voice,Telephony} tests/Feature/{Voice,Telephony}`: `OK, Tests: 892, Assertions: 237835, Skipped: 163` (las omitidas son el E2E sin arnés y las de solo PostgreSQL);
- los 21 ficheros (`grep -v ClosedCopy bateria-rapida.txt`): `OK (614 tests, 24406 assertions)`, la cifra del 11-11;
- el paquete de guardas estáticas (16 ficheros `*Isolation*`, `*ReadOnly*`, `*StaticGuards*` y `SecretExposure*`): `OK (141 tests, 182975 assertions)`.

## 5. Qué quedó viejo en el runbook (cada cambio, marcado «(22-11)»)

1. La cabecera: revisado sobre `0c57d7c4`.
2. A3, «Cuándo»: decía «ni el 15-11 ni el 16-11», que ya pasaron. Ahora dice los próximos festivos y que el canario también respeta el horario. Añade que, si no contesta, ese día no se puede repetir.
3. Paso 0: `C` = `9502884a`. «7cc6100f… (el de HEAD)» era el HEAD del 11-11. Las huellas nuevas `231e4a8a…`, `cd7a5a7a…` y `be6c3c47…` son las de `0c57d7c4` [C].
4. Paso 9: la medición del 22-11 (los mismos 69 casos y los parches válidos).
5. Paso 9, las precondiciones: el ejemplo del 12-11 ya no vale.
   - El último despliegue con evidencia en el repo es `cd5dce0` (13-11).
   - Desde entonces `main` trae 10 migraciones, `routes/api.php`, `routes/marketing.php` y `config/iron_ai.php`.
   - `hotfix-backend.sh:77` se niega a llevar eso. Si producción no lo tiene, la 4a aborta en `:402`.
6. Paso 14: `active` solo aparece si Sofía usa una herramienta. Con la rev. 2, lo normal es `requested → dialing → completed`.
7. Paso 14: el coste que se ve es la reserva. La segunda petición da `[frequency_exceeded, daily_cap_reached]`, y un `no_answer` también gasta el día.
8. «Al terminar»: las 3 llamadas de la evidencia no caben en 7 días con un solo canario.
9. Tabla D: una fila nueva para la fila `ambiguous`: no repetir, cuándo se resuelve sola y que bloquea las siguientes (H-1).

**Los mandos:** en lo que leí no hay ningún defecto que impida la llamada: `h8-voice-scope.sh:218-258, 779-791` (la constante, y la reserva de la puerta, que sigue casando con `OutboundGate.php`: 2 de 2 en los cuatro patrones), `deploy-h8-backend-fase1.sh:373-410, 672-686` y la cabecera de `h8-twilio-check.sh`. No he cambiado ninguno, así que sus huellas no cambian. No los he podido ejecutar: son de producción.

## 6. Los hallazgos de §5 de la auditoría C

| | Estado en `0c57d7c4` |
|---|---|
| C-1 | **Cerrado.** `salientes on` usa la reserva de la PUERTA (con transferencia) y se niega por debajo del peor caso (`h8-voice-scope.sh:231-258, 779-791`). Mínimo: 1,13 US$ |
| C-2 | **Vivo.** `routes/console.php:462-463` dice «(no echa el freno)», y `VoiceWebhookHealth` sí lo echa (`:46-58`). Es un comentario fuera de mi zona |
| C-3 | **Cerrado.** `el-verificar.sh` toma los tres cerrojos (`:13, :38-39`) y su vuelta atrás no apaga ElevenLabs (`:86-88`) |
| C-4 | **Resuelto con los parches**, que siguen valiendo sobre la punta (§3) |
| C-5 | Vivo en el CRM: el texto de la 409 `voice_number_not_assigned`. No lo he comprobado (CRM, fuera de mi zona). El runbook ya lo avisa (B, paso 6) |
| C-6 | Vivo: decisión del propietario sobre el saldo compartido con el OTP |
| C-7 | Vivo y aceptado: una fila sin ids se alcanza por `call_ref` (`VoiceTools.php:210-216`) y falla cerrada |

## 7. Lo que NO he podido verificar: lo que hay que mirar en la primera llamada [H]

1. Que ElevenLabs **no llame al webhook de iniciación** en una saliente. Si lo llamara antes de que el job guarde el SID, Laravel crearía una entrante `denied` y Sofía diría «no disponible» (`InboundGate.php:183-212`). Mirar la línea `voice.inbound.initiation_for_outbound` o una entrante `denied` a la misma hora.
2. Que el agente publicado con `auth.enable_auth=true` (`ElevenLabsAgentMapper.php:156`) llame por Twilio. Ningún agente del Studio ha llamado todavía por teléfono; la prueba del 03-11 fue con un agente manual.
3. Que las herramientas reciban `system__call_sid` y `system__conversation_id` con forma (`CA…`, sin vacíos). Si no, `voice.tool.invalid_schema` en el canal `voice`.
4. Que la `post_call_transcription` traiga el `agent_id` sellado y llegue: `seal_verified=true`, `guard_verdict clean`, `completed`.
5. Qué deja el buzón (la detección de contestador) y si el corte de los 300 s lo hace ElevenLabs (`termination_reason`, `duration_seconds` ≤ 300).
6. El precio real de Twilio en `carrier_cost_micros` tras la conciliación, y las unidades de `costRaw` de ElevenLabs.
7. Que `voice:reconcile --dry-run` dé `unknown` 0 después de la llamada.
8. Lo que hace producción hoy, sin `ssh`: el commit que sirve, `/root`, las banderas y las piezas.

## 8. Hallazgos fuera de alcance (no bloquean la primera llamada)

- **H-1 · Una `ambiguous` de una llamada que NO salió no tiene salida y bloquea las salientes** [L]. `ambiguous` está en `VoiceCall::LIVE` (`VoiceCall.php:51`), y `liveCount()` alimenta la concurrencia (`OutboundGate.php:151`). Si la llamada salió, se resuelve sola: la post-llamada la encuentra por su `call_ref` (`VoicePostCall.php:155` admite `ambiguous → completed`) o la conciliación por el SID del evento (`VoiceReconciler.php:304-312`). Si no salió (el job murió por el plazo, el proveedor estaba caído o el job se perdió), no llega nada, no hay ningún mando para cerrarla y todas las salientes siguientes dan `concurrency_exceeded` hasta que alguien la cierre a mano en la base, con autorización. Ya hay una fila en la tabla D del runbook (punto 9 de §5). Falta una decisión de architect y database.
- **H-2 · El plazo del job no acota la observación de la deriva** [L]. Con la caché de la deriva vacía (aproximadamente la mitad del tiempo [H]: la caché dura 5 min y la conciliación la renueva cada 10), el job hace un GET del agente y otro por herramienta (`ElevenLabsAgentDeployer.php:157-217`), cada uno con `ELEVENLABS_TIMEOUT_SECONDS` (10 s por defecto). Después hace el POST, de hasta 20 s. Con la rev. 3 y un proveedor lento, el total pasa de los 30 s del job; el job muere y la fila queda `ambiguous`, y vuelve a H-1. Con el proveedor sano son menos de 2 s. Arreglo propuesto, sin hacer: un plazo restante que corte con `drift_unverified` antes del POST.
- **H-3 · `consultar_planes` sin beneficios ni reglas de acceso** (§1.b): es una enmienda de D8-10, con revisión de la guarda, porque los textos de beneficios pueden traer vocabulario de descuento.
- **H-4 · La guarda posterior de la voz no vigila las frases S-NNN** (`VoiceTranscriptGuard`). D8-10, capa 4, no las incluye. En WhatsApp tampoco hay guarda determinista (M1-18). Hoy es solo el prompt.
- **H-5 · Con un solo canario, la evidencia de 3 llamadas tarda al menos 8 días** (`config/ultron_studio.php:303` frente a `VoiceFrequency`). Lo decide el propietario: un segundo canario o el modo `calendar` (P-11).
- C-2 (el comentario de `routes/console.php`) y C-5 (CRM), en la tabla de §6.

## 9. Lo que queda en la máquina

- El árbol desechable `_worktrees/cierre-voz-abierta/Iron-Body` y su rama `tmp/voz-abierta-medicion` (`cbf94374` + los tres parches, hasta `d85035ac`). No los he borrado. El árbol tiene sin confirmar la evidencia de su E2E (`ops/evidencia-h8/2026-11-22/20261122T050659Z-d85035ac/`).
- Las bases de PostgreSQL `ultron_studio_cierre_voz` y `ultron_studio_h8_e2e_cvoz` (las recrea el arnés).
- Las ejecuciones del arnés, en `~/.cache/ironbody-h8-e2e/run-20261122T05*`.

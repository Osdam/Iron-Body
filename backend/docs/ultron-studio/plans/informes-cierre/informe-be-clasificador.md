# Informe backend · el clasificador de respuestas (AI9)

Rama `cierre/motores` (sale de `0c57d7c4`). Commits: `678ad816` (contador y migración), `db237c18` (clasificador e integración con W9), `70950447` (transporte). Hay dos pasos que tienen que hacer otros: **la migración** (§5) y **el enlace del transporte** (§6). Sin ellos, el clasificador existe pero no llama a nadie. **Ronda 2 (§12):** el lead aceptó la migración y autorizó el enlace, la prueba de esquema, `.env.example` y la enmienda del contrato; todo hecho. En producción la migración sigue pendiente del rol dueño.

## 1. Diseño corto

- **Dónde engancha.** En `WhatsappTemplateOutcomes::forJob()`, después de las señales deterministas. La baja segura (`opted_out`) y la posible baja (`human_requested` · `opt_out_word`) se quedan como hoy y no pasan por la IA. Cualquier otra respuesta se registra **como hoy**, `replied` (`inbound_message`). Solo después, y solo si **esta** pasada insertó de verdad ese `replied` (ganó `oout_source_unique`), se llama al clasificador con `DB::afterCommit`, con el `try` dentro.
- **Por construcción no abre contacto.** Cuando llega el clasificador, la inscripción ya está cerrada por `replied` (`handoff_human`* o `end_exit`). Un resultado `classifier` no planifica nada: `FollowUpMachine` lo ve `enrollment_final`. Solo añade sus efectos de freno:
  - `opted_out` cancela lo pendiente en todas las estrategias y suprime finalidad × medio;
  - `wrong_person` suprime el medio;
  - `not_interested`, `human_requested` y `callback_requested` dejan constancia.
- **Etiquetas.** Las cinco que D9-10 da a AI9, más `none` («ninguna»), que nunca se registra. Esquema JSON estricto con `enum` y sin propiedades de más. No hay etiqueta que avance la venta.
- **Umbrales.** Los de `OutcomeTable`, sin tocarlos: 50 para `opted_out` y 90 para el resto. Por debajo, se descarta y queda como hoy.
- **Privacidad.** Al proveedor van solo el modelo, unas instrucciones fijas, el esquema y el texto de **una** respuesta enmascarado (teléfonos y cifras de 6 o más, documentos, correos y enlaces), cortado a 500 caracteres, con `store: false`. Nunca `user`, `metadata` ni `safety_identifier`, ni un id, nombre o teléfono de la persona. El texto no se guarda ni se registra en los logs.
- **Datos no confiables.** El modelo podría obedecer al texto: la salida se valida entera y lo que no encaja se descarta (`ReplyClassifier::read()`).
- **Presupuesto propio.** Reutiliza la reserva atómica del H9 con el alcance `classifier:reply` (día de Bogotá, modo `live`). Antes de llamar se reservan una llamada y el coste **máximo**: bytes de entrada como tokens, que es una cota superior, más el tope de salida. Después se devuelve lo no gastado según el `usage`. Si la petición no llegó a salir se devuelve todo; un fallo que pudo cobrarse conserva la reserva. Por defecto el cupo y el techo están a 0 y no clasifica.
- **Modos de fallo.** Todos dejan la respuesta como hoy:
  - apagado, sin transporte enlazado, sin modelo o sin precios, sin clave propia;
  - cupo o techo a 0, presupuesto agotado, sin texto;
  - tiempo agotado (2–10 s, conexión 3 s, sin `retry`), error del proveedor, negativa, salida incompleta o fuera del esquema;
  - confianza baja;
  - una excepción, que queda dentro con su clase y sin el texto.
- **La red, fuera del H9.** La guarda estática prohíbe `Http::` y `openai` en `App\Services\Outreach` (§3.4 y `OutreachStaticGuardsTest`). Por eso el H9 solo tiene el puerto `ReplyClassifierTransport`, y el adaptador vive en `App\Services\ReplyClassification`. Así no se amplió ninguna lista cerrada.

## 2. Contradicciones y cómo las resolví (lectura restrictiva)

1. **`callback_requested → wait*` (D9-10) frente a «nunca permite más contacto».** `wait` vuelve a planificar el mismo paso, es decir, un contacto que hoy no ocurriría tras `replied`. Lo resolví clasificando solo **después** de `replied`, con la inscripción cerrada: `callback_requested` se registra con `next_action = wait`, pero no crea ningún trabajo. Queda el conjunto exacto del contrato sin que pueda abrir contacto. Si el propietario quiere que la IA devuelva la llamada, eso es otra enmienda.
2. **«Por debajo del umbral, `needs_review`» (`OutcomeTable`) frente a «se descarta, como hoy» (encargo).** Me quedé con lo que pidió el lead: el clasificador no manda nada por debajo del umbral. La regla de `OutcomeTable` sigue debajo como segunda barrera.
3. **`OutcomeTable::SOURCES_AVAILABLE` no se toca.** Es la foto del 15-11 que fija `outcome-transitions.json` (`sources15_11`), y ese fichero tiene copia en el CRM. W9 y V9 tampoco lo tocaron. `resolve()` ya admite `classifier`: no hacía falta tocar `OutcomeTable.php` ni `OutcomeRecorder.php`.
4. **La clave.** El encargo pedía mirar las claves existentes. La de `services.openai` es la compartida de IRON IA y del cerebro legado. Seguí la pauta del laboratorio: clave **propia** (`COMMERCIAL_AUTOMATION_CLASSIFIER_API_KEY`), y la compartida no vale (`configured() = false`). Es el «proyecto de OpenAI y su presupuesto» que el paquete AI9 exige.

## 3. Qué cambió

| Fichero | Qué |
|---|---|
| `app/Services/Outreach/Run/WhatsappTemplateOutcomes.php:207-208`, `:226-246` | El enganche (solo con `RESULT_RECORDED`) y `classifyAfterCommit()`. `run()` devuelve `classifier` (`:109-113`). |
| `app/Services/Outreach/Run/Classifier/ReplyClassifier.php:85` (`classifyReply`), `:98-183`, `:193` (`read`), `:211` (`sourceKey`), `:223` (`settle`) | El orquestador. Reserva en `:128-133`, llamada en `:135`, umbral en `:164` y registro por `OutcomeRecorder` en `:174`. |
| `…/Classifier/ReplyClassifierPrompt.php:32`, `:39`, `:66`, `:80` | Etiquetas, instrucciones, esquema y huella (`d22c305a…`). |
| `…/Classifier/ReplyTextMask.php:37`, `:43` | El enmascarado. |
| `…/Classifier/ReplyClassifierTransport.php` | El puerto: errores cerrados y `NOT_SENT`. |
| `app/Services/Outreach/Facts/WhatsappSignals.php:239` | `replyText()`: solo un entrante de WhatsApp de esos prospectos. |
| `app/Services/Outreach/Run/BudgetReservations.php:44`, `:49` | `SCOPE_CLASSIFIER` y su forma. |
| `app/Services/Outreach/Run/OutreachConfig.php:154-224`, `:410` | Lectores, `classifierSetup()` y `status().classifierSetup`. |
| `app/Services/Outreach/Run/OutreachReadModels.php:53`, `:90` | `config.classifierSetup.today`. Las claves de primer nivel no cambian (lo fija `OutreachApiTest`). |
| `config/outreach.php:123` | `classifier.*`, todo a 0 o vacío. |
| `app/Services/ReplyClassification/OpenAiReplyClassifierTransport.php:54`, `:72`, `:81-86` | El adaptador. |
| `database/migrations/2026_11_22_100001_allow_classifier_budget_scope.php:43`, `:53` | La migración (§5). |

## 4. Cada propiedad y la prueba que la demuestra

| Propiedad | Prueba |
|---|---|
| Conjunto cerrado = D9-10 para AI9; con cualquier `on` posible, nunca `next_step`, `retry_step` ni `end_success` | `ReplyClassifierPureTest::test_las_etiquetas_son_exactamente_las_fuentes_classifier_de_la_tabla_y_ninguna_permite_contacto` |
| Ninguna etiqueta abre contacto, tampoco `callback_requested` (ni a los 3 días) | `ReplyClassifierEndToEndTest::test_cada_etiqueta_aceptada_queda_registrada_y_ninguna_abre_un_contacto` (3 casos) y `…test_no_me_interesa_anade_el_freno…` |
| Baja de la IA con umbral 50: cancela otras estrategias y suprime | `…test_una_baja_que_solo_ve_la_ia_frena_con_el_umbral_bajo_cancela_lo_pendiente_y_suprime` |
| Bajo el umbral o `none` → descartado, como hoy | `…test_por_debajo_del_umbral_o_sin_freno_se_descarta_y_queda_como_hoy` (4) |
| Privacidad: solo el texto enmascarado, sin datos de la persona, el texto no se guarda | `…test_no_me_interesa_anade_el_freno_con_su_traza_sin_abrir_contacto_y_el_texto_sale_enmascarado`; `ReplyClassifierPureTest::test_el_enmascarado_quita_telefonos_documentos_correos_y_enlaces` (13) y `…corta_y_falla_cerrado…` |
| `store: false`, petición exacta, clave propia, nunca la compartida, sin `retry` | `OpenAiReplyClassifierTransportTest::test_la_peticion_es_exactamente_la_del_contrato…`, `…sin_clave_propia_o_con_la_compartida_no_hay_peticion`, `…no_usa_retry…` |
| Salida restringida: fuera del esquema, descartada | `ReplyClassifierPureTest::test_cualquier_salida_fuera_del_esquema_se_descarta` (14) |
| Inyección: con el modelo «obedeciendo», la clase de efectos no cambia | `ReplyClassifierEndToEndTest::test_una_inyeccion_en_el_texto_nunca_cambia_la_clase_de_efectos` (10) |
| Falla cerrado: apagado, sin cupo, sin modelo, sin clave, sin transporte | `…test_apagado_por_defecto…`, `…test_encendido_sin_cupo_sin_modelo_o_sin_transporte…` |
| Falla cerrado: cada error del proveedor; cuenta solo lo que pudo cobrarse | `…test_cualquier_fallo_del_proveedor_deja_la_respuesta_como_hoy…` (10); `OpenAiReplyClassifierTransportTest::test_cada_fallo_es_una_clase_cerrada…` (12) y `…sin_conexion_no_salio…` |
| Una excepción no sale de la pasada | `…test_una_excepcion_del_transporte_no_sale_de_la_pasada…` |
| Presupuesto agotado o techo menor que una llamada → no llama; reserva atómica | `…test_sin_presupuesto_del_dia_o_con_un_techo_menor_que_una_llamada_no_llama`; `ClassifierBudgetScopeTest::test_la_reserva_del_clasificador_es_atomica_contra_su_techo` |
| Después de confirmar, fuera de toda transacción y nunca si se deshace | `…test_el_clasificador_corre_despues_de_confirmar_y_fuera_de_toda_transaccion`, `…test_si_la_transaccion_de_quien_llama_se_deshace_el_modelo_no_se_llama` |
| Una vez por respuesta (pasadas repetidas, otro mensaje) | `…test_una_respuesta_se_clasifica_una_sola_vez_aunque_la_pasada_se_repita` |
| Una vez con **dos procesos** en PostgreSQL | `ReplyClassifierRaceTest::test_dos_procesos_a_la_vez_sobre_la_misma_respuesta_hacen_una_llamada_y_un_resultado` (barrera consultiva; 1 llamada, 1 `replied`, 1 resultado; 2.ª vuelta, nada) |
| Traza: versión, huella, modelo (en la clave), confianza (columna), coste (contador) | `…test_no_me_interesa…` y `ReplyClassifierPureTest::test_la_huella_del_prompt_esta_fijada…`, `…la_clave_lleva_trabajo_version_huella_y_modelo…` |
| Señales deterministas primero | `…test_la_baja_segura_y_la_posible…`, `…test_una_posible_baja_la_revisa_una_persona…` |
| Cadena completa con el transporte real (red fingida) | `OpenAiReplyClassifierTransportTest::test_de_extremo_a_extremo_por_el_transporte_real_con_la_red_fingida` |
| Estado veraz | `ReplyClassifierEndToEndTest::test_el_estado_dice_si_existe_si_esta_listo_sus_techos_y_lo_que_lleva_hoy` |

**Mutaciones.** Apliqué ocho y las devolví; las ocho tumban su prueba:
- clasificar también los `replied` repetidos;
- llamada directa en lugar de `afterCommit`;
- no enmascarar;
- quitar el umbral;
- aceptar propiedades de más;
- no devolver lo no gastado;
- `store: true`;
- aceptar la clave compartida.

## 5. ⚠️ MIGRACIÓN (aditiva; en producción la ejecuta el rol dueño con autorización del propietario)

`2026_11_22_100001_allow_classifier_budget_scope`:
- **En PostgreSQL** amplía `obud_scope_chk` con `|classifier:reply` en una sola sentencia (`lock_timeout` de 5 s). No cambia tablas, columnas, índices, disparadores ni datos. El recuento sigue en 75 CHECK.
- **En SQLite** no hace nada (allí no existen esos CHECK).
- **`down()`** devuelve el CHECK estrecho, pero solo si no hay filas `classifier:*`. Con filas se niega: es irreversible desde la primera llamada. Para apagar el clasificador basta la bandera.
- **Probada** en los dos motores (`ClassifierBudgetScopeTest`).
- **Sin la migración**, en PostgreSQL la reserva viola el CHECK, la excepción queda dentro y el clasificador falla cerrado.

**Por qué no se pudo sin migración.** No hay ningún sitio duradero y atómico para el cupo y el coste de las llamadas, que incluyen las fallidas:
- el CHECK de alcance de los contadores es cerrado;
- el libro de resultados no admite filas de fallo;
- la caché se borra al desplegar.

**Choca con una guarda de DB9** (resuelto en la ronda 2, §12). `OutreachSchemaTest::test_down_sin_filas_y_up_dejan_el_mismo_esquema_y_no_tocan_el_gobierno` hace ida y vuelta de `100002`, que recrea el CHECK estrecho. Cae en PostgreSQL **solo** por esta migración: sin ella, verde, comprobado apartándola del árbol.
- No toqué la prueba.
- Tampoco se puede editar `100002`: el guion de despliegue (`deploy-h9-backend-cierre.sh:456`) prohíbe tocar una migración ya desplegada.
- Cambio propuesto para el lead o DB9: tras `$m->up();`, volver a aplicar las migraciones posteriores que cambian esas tablas, como haría `migrate`: `(require database_path('migrations/2026_11_22_100001_allow_classifier_budget_scope.php'))->up();`.
- Además, `MIG_OLA` y sus efectos (`pg_get_constraintdef` de `obud_scope_chk`) en el guion de despliegue (devops/ops9).

## 6. Lo que falta para encender (no es mío)

El enlace y `.env.example` quedaron hechos en la ronda 2 (§12). Siguen pendientes la migración en producción, el mando y el modelo real.

- **El enlace del transporte**, en `app/Providers/AppServiceProvider.php::register()`:
  ```php
  $this->app->bind(\App\Services\Outreach\Run\Classifier\ReplyClassifierTransport::class, \App\Services\ReplyClassification\OpenAiReplyClassifierTransport::class);
  ```
  Lo probé en local y después lo quité. Con él: `OutreachIsolationTest --filter test_cero_red` 2/2, y el estado da `transport: true, configured: false, effective: false`.
- **Variables nuevas** (todas `COMMERCIAL_AUTOMATION_CLASSIFIER_`, para `.env.example`, que es de B9-run): `DAILY_CAP` (0–2000), `DAILY_BUDGET_USD`, `MODEL`, `PRICE_INPUT_USD_PER_MTOK`, `PRICE_OUTPUT_USD_PER_MTOK`, `TIMEOUT_SECONDS` (5), `MAX_OUTPUT_TOKENS` (64) y `API_KEY` (secreto).
- **El mando** (`h9-outreach-scope.sh`). Es compatible: `clases_outreach Classifier` encuentra `ReplyClassifier`. Pero `clasificador on` solo pone la bandera, y con cupo 0 el clasificador no hace nada (falla cerrado, y el estado lo dice). Propuesta, sin aplicar:
  - `clasificador on --cupo= --presupuesto= --modelo= --precio-entrada= --precio-salida=`, que exija después `classifierSetup.effective`;
  - `apagar`, que ponga también `…_CLASSIFIER_DAILY_CAP=0` y `…_DAILY_BUDGET_USD=0`.

## 7. Pruebas ejecutadas (línea final)

**SQLite**
- `tests/Feature/Outreach` (incluye AI9): `Tests: 755, Assertions: 42601, Skipped: 167.` Las omitidas son de solo PostgreSQL.
- `tests/Unit/Outreach`: `OK (1048 tests, 53495 assertions)`.
- Paquete de guardas estáticas: `OK (141 tests, 183182 assertions)`.

**PostgreSQL 14** (`ultron_studio_cierre_motores`)
- `tests/Feature/Outreach/AI9`: `OK (61 tests, 454 assertions)`. Incluye la carrera, que limpió sus filas: 0 en las tablas.
- `tests/Feature/Outreach/W9`: `Tests: 50, Assertions: 1728, Skipped: 1`.
- `OutreachApiTest`: `OK (7 tests, 137 assertions)`.
- `OutreachIsolationTest`: `OK (5 tests, 2612 assertions)`.
- `OutreachOutcomesTest`: `OK (6 tests, 101 assertions)`.
- `tests/Feature/Outreach/Schema`: `Tests: 319, Assertions: 2086, Failures: 1, Skipped: 1`. El fallo es el de §5.

## 8. Lo que NO verifiqué

- **El modelo real.** No hice ninguna llamada a OpenAI (estaba prohibido). Queda sin comprobar:
  - que el modelo elegido acepte `json_schema` estricto con este esquema y con `max_output_tokens` 64 (un modelo de razonamiento puede agotarlos y dar `incomplete`, que se descarta);
  - su latencia frente al tiempo máximo de 5 s;
  - la calidad de las etiquetas en español.
  El lead lo comprobará con autorización: una llamada con el transporte enlazado y una clave propia de prueba.
- **Producción.** No corrí nada contra producción ni contra la migración allí.
- **La batería completa.** No la corrí (la corre el lead).

## 9. Lo que security debe mirar primero

1. El adaptador fuera del H9 y el enlace por contenedor: es el primer componente del H9 con red que no pasa por una puerta con excepción nombrada en la guarda. ¿Basta, o se quiere una entrada `AI9_TRANSPORT` en `OutreachStaticGuardsTest`?
2. `ReplyTextMask`: no reconoce nombres propios ni cifras escritas con letras, que salen dentro de la respuesta.
3. `replyText()`: es la primera salida de un cuerpo de mensaje desde `Facts`.
4. La clave en `config/outreach.php`: solo la lee el adaptador, y la compartida se rechaza por huella.
5. El efecto de un `opted_out` o `wrong_person` falso: suprime a la persona (el lado seguro, pero es pérdida de venta).

## 10. Fuera de alcance (hallazgos)

- `SOURCES_AVAILABLE` sigue sin W9, V9 ni AI9. Es un fixture compartido con el CRM; si se quiere al día, cambia `sources15_11`.
- La salida humana de `outreach:whatsapp-outcomes` no enseña el bloque `classifier`. El JSON sí lo lleva.
- Solo se clasifica el **primer** entrante. «Hola» y luego «no me interesa» da `none` y queda como hoy.

## 11. Para el CRM (no toqué su repositorio)

- `GET outreach/status`: **`data.config.classifier` sigue siendo el booleano de siempre.**
- Nuevo `data.config.classifierSetup`, con estos campos: `implemented`, `version`, `promptRef`, `labels[]`, `enabled`, `transport`, `configured`, `model` (o null), `dailyCap`, `dailyBudgetMicros`, `timeoutSeconds`, `effective` y `today` {`day`, `calls`, `micros`, `capUnits` (o null), `capMicros` (o null)}.
- La salida JSON de `outreach:whatsapp-outcomes` gana `classifier` (`calls`, `costMicros`, `<estado>.<motivo>`).
- Ningún fixture compartido (`tests/fixtures/outreach/**`) cambia.
- `ca-panel.ts:66` puede pasar de «no implementado» a leer `classifierSetup.effective`.

## 12. Ronda 2 (22-11-2026): lo que autorizó el lead

Un commit por punto: `cabbceb6`, `0b768a97`, `326d7e5b` y `8aa7f5f6`. El mando, los guiones de despliegue, `SOURCES_AVAILABLE` y la salida humana de la orden no se tocaron.

1. **La prueba de esquema** (`tests/Feature/Outreach/Schema/OutreachSchemaTest.php`):
   - `LATER_EXECUTION_MIGRATIONS` (`:273`) es una lista cerrada, una línea por migración con lo que toca. Hoy solo está la de AI9. Para `outreach_consents`, si llega a tocar las tablas de `100002`, sería una línea más.
   - La ida y vuelta la vuelve a aplicar tras el `up()` de `100002`, en `:607`. La comparación del esquema no cambia.
   - Prueba nueva `test_las_migraciones_posteriores_que_tocan_la_ejecucion_estan_en_su_lista` (`:629`), con el rastreo `TOUCHES_EXECUTION` (`:279`): ocho tablas, prefijos de guardas, funciones y `OutreachTables::*`. Cae si una migración posterior toca la ejecución sin estar en la lista, o si la lista nombra una que no la toca. Lo comprobé con una migración de prueba que nombra `outreach_jobs` y quitando la entrada: las dos caen.
2. **El enlace del transporte y su guarda:**
   - `app/Providers/AppServiceProvider.php:127`, con sus `use` en `:40-41`.
   - Guarda nueva `tests/Feature/Outreach/Guards/ReplyClassifierStaticGuardsTest.php` (entra sola en el paquete por su nombre):
     - `:56`: el espacio solo contiene el adaptador;
     - `:65`: es el único implementador, solo lo nombra su línea de enlace, y el contenedor lo resuelve;
     - `:86`: el H9 solo conoce el puerto;
     - `:98`: una URL, un `Http::`, sin redirecciones ni `retry`, también en ejecución (un 302 a otro host, un 500, un 429 y un corte son una petición cada uno);
     - `:129` y `:147`: sin clave propia o con la compartida no sale ninguna petición, tampoco por el clasificador entero.
   - Seis mutaciones la ponen en rojo: sin `withoutRedirecting`, con `retry`, el H9 importa el adaptador, otra URL, otro implementador y la redirección comprobada solo en ejecución.
   - `ReplyClassifierEndToEndTest` cambia donde suponía «sin transporte». Con el enlace real, la bandera sola da `not_configured`. Sin enlace (`unset`, `:100`) da `transport_unbound`. El estado dice `transport: true` (`:476`).
3. **`.env.example:477-491`:** las ocho variables con una línea de comentario cada una y a 0 o vacías. Evaluadas así, `config/outreach.php` deja el clasificador inerte. Pasan `SecretExposureTest` y las cuatro pruebas que leen el fichero.
4. **El contrato del H9:** «Enmienda AI9» al final (`ULTRON_STUDIO_H9_CONTRACT.md:1632`). La tabla de L9 no se tocó.

**Pruebas de la ronda 2 (línea final)**

SQLite
- `tests/Feature/Outreach`: `Tests: 762, Assertions: 42859, Skipped: 167.` (todas de solo PostgreSQL).
- `tests/Unit/Outreach`: `OK (1048 tests, 53495 assertions)`.
- Paquete de guardas estáticas: `OK (147 tests, 183435 assertions)`.

PostgreSQL 14
- `tests/Feature/Outreach/AI9`: `OK (61 tests, 457 assertions)`.
- `tests/Feature/Outreach/Schema`: `Tests: 320, Assertions: 2092, Skipped: 1.` En verde; la omitida ya lo estaba antes.
- `OutreachIsolationTest`: `OK (5 tests, 2612 assertions)`, cero red con todo por defecto y con la configuración de producción.
- `ReplyClassifierStaticGuardsTest`: `OK (6 tests, 253 assertions)`.

**Fuera de alcance.** `AppServiceProvider.php` ya no pasaba Pint en `HEAD` (los mismos cuatro fixers): no lo reformateé. Mis dos `use` van en su orden.

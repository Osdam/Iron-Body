# Informe security · el clasificador de respuestas (AI9)

Rama `cierre/motores`, diff `0c57d7c4..HEAD` (9 commits, hasta `8c4d43aa`). Una sola pasada, con veredicto. Marcas: **[C]** comprobado ejecutando · **[L]** leído · **[S]** supuesto.
Las sondas están fuera del repositorio, en `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body-backend-docs-ultron-studio/becd0ab4-c28a-4871-8ce6-cc76392ca82a/scratchpad/sec-ai9/` (`mask-probe.php`, `SecAi9ProbeTest.php` y `SecAi9PgProbeTest.php`). Al terminar, `git status --porcelain` está igual que al empezar, salvo este informe.

## Veredicto

- **(a) Integrar y desplegar APAGADO: APTO.** No hay bloqueantes. Con los valores por defecto no sale ninguna petición: la bandera está apagada, el cupo y el techo a 0, y faltan el modelo, los precios y la clave propia. Lo comprueban la guarda, `OutreachIsolationTest` y mi sonda [C].
- **(b) Encender en canario con el modelo real: APTO CON CONDICIONES** (C1–C5). Ninguna exige cambiar el diseño. C1, C2 y C5 son decisiones o trámites del propietario; C3 y C4 son operación.

## Hallazgos

### BLOQUEANTES
Ninguno.

### CONDICIONES (antes de encender)

**C1 · Una baja o un `wrong_person` falsos son definitivos y no se pueden deshacer en el H9 (alta, por su impacto en el negocio).**
- *Dónde:* `ReplyClassifier.php:164`, con el umbral 50 de `OutcomeTable::CLASSIFIER_LOW`; `SubjectSuppressions.php:16`, que dice que el libro es de solo inserción y que «la supresión es permanente»; y los efectos en `OutcomeRecorder.php:161-173`.
- *Escenario:* la persona contesta «Por ahora no, en enero les aviso». Las señales deterministas no lo marcan [C]. Si el modelo dice `opted_out` con 55, pasa todo esto. La cancelación y la supresión las comprobé con el modelo fingido [C]; que alcance todas las finalidades y canales lo leí [L]:
  - se cancelan sus trabajos pendientes en todas las estrategias, finalidades y canales;
  - se cierran sus otras inscripciones;
  - queda suprimida para (finalidad, WhatsApp) para siempre.
  - Con `wrong_person` (umbral 90) queda suprimida en WhatsApp para todas las finalidades (`SubjectSuppressions.php:103`) [L].
- *Detección:* sí se puede detectar. La fila lleva `source=classifier`, la confianza y una `source_key` con el modelo y la huella del prompt, y el mensaje sigue en el Inbox.
- *Deshacer:* no hay forma; un «descartar» llegaría con C9.
- *Corrección mínima (sin código):*
  1. el propietario acepta por escrito que una baja de la IA es definitiva;
  2. durante el canario, alguien revisa a diario `outreach_outcomes WHERE source='classifier' AND outcome IN ('opted_out','wrong_person')` contra el Inbox.
- Una vía de revocación por una persona es un cambio estructural: lo paso a **architect**.

**C2 · Encargado y transmisión (Ley 1581).**
- El contrato nombra a OpenAI como encargado para AI9 (`ULTRON_STUDIO_H9_CONTRACT.md:882`), pero eso no sustituye el trámite.
- Faltan, del propietario o su asesoría:
  - el contrato de transmisión (DPA) que cubra el proyecto propio del clasificador (Decreto 1074/2015, art. 2.2.2.25.5.2) [S, jurídico];
  - que la política de tratamiento mencione la transmisión al exterior y la clasificación automática.
- `store: false` no es retención cero: OpenAI conserva registros de abuso hasta 30 días salvo que el proyecto tenga retención cero (ZDR) [S, comprobar en la DPA vigente]. Si el proyecto lo admite, conviene activarla.

**C3 · La clave y el mando.**
- La clave debe ser de un proyecto de OpenAI propio, con un límite de gasto configurado en OpenAI. Es una segunda barrera: el contador local registra `min(reserva, uso real)` (`ReplyClassifier.php:139`), así que si la cota superior fallara, contaría de menos sin avisar.
- El futuro `clasificador on` (devops) debe fijar el cupo, el techo, el modelo y los precios, y no imprimir nunca `COMMERCIAL_AUTOMATION_CLASSIFIER_API_KEY`. Hoy los guiones solo imprimen claves elegidas una a una (`deploy-h9-backend-cierre.sh:273`, `h9-outreach-scope.sh:149`) [L].
- `apagar` debe poner también el cupo y el techo a 0.

**C4 · Una llamada real verificada antes del canario (el lead, con autorización).** Hay que comprobar: que el modelo acepta `json_schema` estricto con `max_output_tokens` 64 (un modelo de razonamiento da `incomplete`, y eso descarta el 100 %, en silencio); la latencia frente a 5 s; y que `usage.input_tokens` queda por debajo de la reserva (comparar el `cost_micros` del registro con la reserva).
- Además, un aviso si `provider_auth`, `quota` o `rate_limited` se mantienen. Si no, los frenos de la IA se pierden sin que nadie lo vea: falla cerrado, pero de forma invisible.

**C5 · Decisión: hasta dónde llega una baja que solo detecta la IA.**
- *Dónde:* `SubjectSuppressions.php:112`. El `opted_out` del clasificador suprime solo (finalidad, WhatsApp); el de `inbound_message` o `voice_call` alcanza todos los medios (SC-1).
- *Escenario:* «Ya no quiero saber nada de ustedes, gracias» no es una posible baja para el detector determinista [C]. La IA lo marca `opted_out` y la voz por estrategia (V9) de la misma finalidad podría llamar más adelante.
- Es lo que dice el contrato (D9-10), pero es la cara contraria de C1.
- El propietario o **architect** deciden antes de encender con V9 activo para las mismas personas: extenderla a todos los medios o derivarla a revisión. No lo implemento.

### MENORES

- **M1 · Huecos del enmascarado** (`ReplyTextMask.php:37-41`) [C, `mask-probe.php`].
  - *Se ocultan:*
    - el móvil de 10 cifras con cualquier agrupación, `+57` y separadores;
    - el fijo `(601)`, la cédula con puntos, el NIT con dígito de verificación, la tarjeta y las fechas;
    - las cifras invisibles, las árabes y las de ancho completo;
    - el corte a 500 caracteres sin dejar cifras sueltas.
  - *Pasan:*
    - nombres, direcciones («Calle 45 # 23-12 apto 301»), cifras escritas con letras;
    - el correo con espacios o «arroba»;
    - `instagram.com/usuario` sin esquema, `@usuario`;
    - una cédula antigua de 5 cifras, media latitud.
  - **Son aceptables:** es el texto que la persona escribió al gimnasio, el contrato permite enviarlo, y el enmascarado es una defensa más. Ninguno saca un identificador del sistema.
  - *Mejora barata y opcional:* el correo con espacios o «arroba», dominio más ruta sin esquema y `@usuario`.
- **M2 · La pasada de W9 bloquea en serie.**
  - `outreach:whatsapp-outcomes` corre cada minuto, antes del despacho, en el mismo proceso del programador (`routes/console.php:519-522`). Cada clasificación espera hasta el tiempo máximo (5 s, como mucho 10).
  - Con 100 respuestas nuevas y el proveedor lento, son unos 8 min y `withoutOverlapping(10)` puede caducar.
  - No es de seguridad: antes de enviar, los canales vuelven a mirar la baja en los entrantes (`WhatsappTemplateChannel.php:158`, `VoiceStrategyChannel.php:107`) y si ya hubo un entrante después del saliente anterior (`WhatsappTemplateChannel.php:263-265`) [L], y la carrera es idempotente [C, `ReplyClassifierRaceTest`].
  - *Corrección:* un tope por pasada, por ejemplo `classifier.max_per_pass`; lo demás queda `replied`. Antes de pasar del canario.
- **M3 · La guarda es una alarma, no una barrera.** `ReplyClassifierStaticGuardsTest.php:71` no detecta un `implements` hecho con un alias o con una subinterfaz. Las líneas `:103-109` no prohíben en el adaptador `new \GuzzleHttp\Client`, `curl_*`, `file_get_contents`, `fsockopen` ni una URL armada por partes. *Corrección:* añadirlos a la lista prohibida. A la pregunta §9.1 del autor: no hace falta `AI9_TRANSPORT` en `OutreachStaticGuardsTest`; basta su guarda, con M3.
- **M4 · La clave del laboratorio se acepta.** `configured()` solo la compara con `services.openai.api_key` (`OpenAiReplyClassifierTransport.php:60-62`). Con la misma clave que `ultron_studio.lab.openai.api_key` da `true` [C], y el clasificador y el laboratorio comparten proyecto y presupuesto. *Corrección:* compararla también con esa clave.
- **M5 · Etiquetas en los registros de lo descartado.** `outreach.classifier.discarded` lleva el `label` y la `confidence` junto al uuid del trabajo (`ReplyClassifier.php:158-167`). Es un dato indirecto de la persona en un log, que el guion de la 1581 no alcanza; es de la misma clase que los registros del H9 que ya existen. *Corrección:* aceptarlo con la rotación de logs o quitar `label` y `confidence` cuando se descarta.

## Las diez preguntas

1. **Qué sale.** [C, `SecAi9ProbeTest`]
   - `POST https://api.openai.com/v1/responses`, con las cabeceras `Authorization` (la clave propia), `Accept`, `Content-Type`, `User-Agent: GuzzleHttp/7` y `Host`.
   - El cuerpo: `model`, `store:false`, las instrucciones fijas, un `input` con el texto enmascarado, `max_output_tokens` y el esquema. No lleva `user`, `metadata`, `safety_identifier`, `previous_response_id` ni herramientas.
   - No salen el teléfono, `full_name`, `document_number`, `member_uuid`, el uuid del trabajo o de la inscripción, `subject_ref`, `phone_ref` ni el wamid. Enmascarado: M1.
2. **De quién es.** `replyText()` (`WhatsappSignals.php:239-252`) exige el id del mensaje, un prospecto de esa persona, el canal WhatsApp y que sea entrante [C]:
   - otra persona, otro canal, un saliente, un id inexistente o una lista vacía dan `null`;
   - el teléfono no une personas (`SubjectResolver`);
   - el id sale de `inboundBetween`, es decir, posterior al saliente;
   - un `body` de ubicación o de tarjeta de contacto es `null` y no se clasifica (`MetaWebhookService.php:180-230`) [L].
   - Queda la semántica de W9: el primer entrante puede no contestar a *ese* contacto, si los mensajes se cruzaron [S].
3. **Inyección.**
   - Salida validada entera (`ReplyClassifier.php:193-208`); con cualquier etiqueta, la inscripción ya está cerrada y no se crea ningún trabajo [C, E2E].
   - Peor caso: C1 sobre ESA persona, sin otra persona, pagos, membresías ni contactos. Un tercero solo provoca una supresión escribiendo desde un número de esa persona, igual que con la baja determinista.
4. **Clave.**
   - Solo el adaptador la lee (`:56`, `:81`), y el rechazo de la compartida funciona, también con espacios [C].
   - No aparece en los registros ni en `status()` [C]. `classifierSetup` pide `commercial_automation.view` (`UltronStudioOutreachController.php:127`) y no lleva secretos [C].
   - La caché de configuración la guarda como al resto de secretos [L]. M4.
5. **Gasto.**
   - Un solo `UPDATE` condicionado, más `obud_limits_chk` en la base: atómico [C, prueba y carrera en PG].
   - Una llamada por trabajo respondido. Un tercero no puede crear trabajos. Encender no reclasifica lo que ya estaba como `replied` [L].
   - Si se agota, `budget_exhausted`, sin llamada y la respuesta queda como hoy. Un fallo que pudo cobrarse conserva la reserva.
6. **Fallo.**
   - Corre en `DB::afterCommit`, con su propia transacción; si quien llama deshace, no se llama [C, E2E].
   - En PG **sin** la migración: `error.exception`, 0 llamadas, el `replied` confirmado y la conexión usable, sin 25P02 [C, `SecAi9PgProbeTest`].
7. **Red.** URL constante, `withoutRedirecting`, sin `retry`, `connectTimeout(3)` y `timeout(2–10)`. No hay middleware HTTP global ni `globalOptions`, y TLS verifica por defecto [L]. SSRF imposible por configuración. La guarda cubre lo que dice, con los huecos de M3 [C].
8. **Registros.**
   - El texto no queda en ninguna tabla nueva (barrido de la base antes y después de la pasada) ni en ningún log [C].
   - De cada clasificación queda:
     - la fila del libro, solo si se registra (`subject_key`/`ref`, la etiqueta, la confianza, la `source_key` y `next_action`);
     - el contador del día, sin datos personales;
     - logs con el uuid, la versión, la huella, el modelo, el coste, la latencia y la etiqueta (M5).
9. **Migración.**
   - Solo añade `|classifier:reply` a `obud_scope_chk` (`:38` y `:41`), en una sentencia.
   - `down()` se niega con filas, bajo `LOCK` [C, `ClassifierBudgetScopeTest` en PG].
   - La prueba de esquema vuelve a aplicar una lista cerrada (`OutreachSchemaTest.php:273`, `:608`) y compara la huella completa: columnas, índices, definiciones de CHECK, disparadores, funciones y ACL. No se relaja [L + C, 2/2 en PG].
10. **Ley 1581.** El guion seudonimiza `outreach_outcomes` por `subject_key` (`supresion-1581.sql:1011-1013`), y eso cubre la fila del clasificador. El contador no tiene datos de la persona. Los logs quedan fuera, como en el resto del H9 (M5). Sobre OpenAI como encargado: C2.

## Pruebas ejecutadas (línea final)

- **SQLite:** AI9, más la guarda y la prueba pura: `Tests: 100, Assertions: 925, Skipped: 3`. `tests/Feature/Outreach/Guards`: `OK (25 tests, 12760 assertions)`. `OutreachIsolationTest` 5/5, `SecretExposureTest` 22/22 y mis sondas `OK (3 tests, 30 assertions)`.
- **PostgreSQL 14** (`ultron_studio_cierre_motores`): `tests/Feature/Outreach/AI9` `OK (61 tests, 457 assertions)`, `ReplyClassifierStaticGuardsTest` `OK (6, 253)`, la ida y vuelta del esquema y la lista `OK (2, 17)`, y mi sonda sin la migración `OK (1, 8)`. Al terminar, la base de prueba sigue con el CHECK ancho.

## Lo que no pude verificar

- **El modelo real.** No hice red, por indicación. Queda sin comprobar si acepta el esquema y los 64 tokens, la latencia, la calidad en español, los tokens reales frente a la cota y la tasa real de `opted_out` falsos (C1 y C4).
- **Lo demás:** la retención y la DPA de OpenAI ([S], C2); producción (la migración con el rol dueño, el `.env` y la caché de configuración); la latencia de M2 bajo carga real, que estimé y no medí; y la política jurídica de C5, que no es mía.

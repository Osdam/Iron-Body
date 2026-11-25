# PT-17 — cierre técnico local del compilador 9

Fecha: 24-11-2026. Rama: `fix/pt17-contrato-inicial`. Base: `e750dd2c`.

**Veredicto de integración: NOT READY.** La corrección y su validación funcional local están terminadas; permanece sin dispensar la guarda H9 de diferencias contra `origin/main`. Este informe no declara aceptado PT-17 ni autoriza publicar, integrar o desplegar. El runtime remoto de n8n no se inspeccionó.

El trabajo se limita a esta copia. No se ejecutaron SSH, llamadas al LLM comercial, mediciones reales, commits, push, merge, despliegues ni publicaciones. Las pruebas usan SQLite en memoria, HTTP simulado y Node local. La clave de aplicación de pruebas es sintética; no se copió un `.env` ni se divulgaron credenciales.

## Cambios y causa corregida

| Pieza | Cambio comprobable |
|---|---|
| `UltronListTurnContract` + `UltronDecideService` + `StudioLabEngineContext` | Componen la lista desde los planes autorizados antes de la primera llamada. Envían únicamente su medida, límite y presupuesto; no sus precios. Ambos contextos entregan el mismo contrato. Un catálogo incompleto, inseguro o que por sí mismo excede el límite no degrada silenciosamente al prompt genérico. |
| `UltronPromptCompiler` + `UltronListTurnPrompts` | El compilador sigue siendo el 9. Firma ambas alternativas de cada rol y selecciona una antes de inferir. La alternativa de lista no contiene las obligaciones de cotización individual; la general conserva exactamente los bytes del compilador 8. La línea base conserva la huella Legacy. `compile(..., 8)` permite reproducir evidencia histórica; no habilita publicar una versión anterior como si fuera actual. |
| `UltronController` + `UltronPromptOffer` | La oferta c9 exige negociación `generation_contract: ultron-list-turn/1`. Un workflow antiguo o incompatible recibe Legacy, nunca el contenedor de variantes como instrucciones de generación. |
| `StudioLabEngineFlow` + `StudioLabEngineTransport` + `StudioLabOpenAiClient` | La ruta especializada transmite instrucciones por `instructions`, contexto como un mensaje `user` y JSON Schema estricto. La ruta histórica conserva sus dos mensajes `user`. El esquema exige una ranura literal y admite como máximo una ranura de app. El límite de caracteres se comprueba en Laravel; no se atribuye al proveedor una garantía de longitud no probada. |
| `UltronPlansOverview` + `UltronDraftCheck` + `UltronDraftDefects` + `UltronCommitService` | En c9 se exige la ranura exacta `{{LISTA_DE_PLANES}}` una sola vez. Sin ella no hay inserción ni normalización automática. El rechazo también se aplica cuando hay herramientas no monetarias. Los tokens c8 y los turnos sin lista conservan sus reglas. |
| `UltronRegenerationNotes` + `UltronListCriticGate` | Toda corrección de lista c9 repite sintaxis y presupuesto. Un primer borrador limpio, confirmado por el backend, conserva la política de rechazos blandos del Gate 1 sin provocar otra generación innecesaria. Fallos graves y dimensiones de veto conservan su efecto. El veredicto original del crítico se conserva. |
| `StudioLabPrompts` + `StudioLabEngineCase/Check/Runner/Runs` | El juez del motor c9 recibe el borrador original, candidato renderizado, predicción y hechos autorizados. No evalúa con las reglas del antiguo actor sin datos. Su versión y huella quedan congeladas; si cambian, no se llama al proveedor. Ningún borrador c9 mayor que la cota de almacenamiento de 2000 caracteres se trunca para archivarlo o juzgarlo: falla y conserva el consumo ya registrado. |
| `StudioListN8nFlow` + generador y comparador S3 | Generan una copia inactiva que materializa los mismos cuerpos y selectores que PHP, preservando nombres usados por referencias del grafo. Verifican un export local antes de integrar; rechazan diferencias operativas, datos fijados/de ejecución y una copia S3 activa. |
| Generadores S1/S2 | Quedan fijados al compilador 8 para seguir reproduciendo sus artefactos existentes byte a byte. C9 utiliza su generador S3 independiente; no se regeneraron ni sustituyeron S1/S2. |

## Presupuesto y significado del primer borrador

La colocación c9 es explícita: el renderer sustituye la ranura sin añadir encabezados ni separadores propios. Todo encabezado, espacio, signo, respuesta adicional y pregunta escritos fuera de ella consumen el presupuesto de prosa. Los separadores internos oficiales ya están contados en la lista.

Con el catálogo de la evidencia: lista de 75 caracteres, límite final 320, prosa máxima 245, ranura de 19 y borrador crudo máximo de 264. Las pruebas cubren exactamente el límite y un carácter más, Unicode, duraciones distintas y listas de dos o tres planes en su orden.

Para enlaces opcionales de la app se reserva el mayor bloque posible de Laravel, con sus separadores y el reemplazo de enlaces ya enviados: 46 caracteres con la configuración actual. El presupuesto de prosa con app es 199 en el ejemplo. Esta reserva opcional es conservadora y se identifica por separado del presupuesto exacto de la lista. `app_links_fits` distingue una reserva posible de una combinación que no cabe; cero caracteres de prosa no significa que una combinación imposible sea válida.

La salida mantiene los cuatro campos originales: `reply_draft`, `uses_price_placeholder`, `questions_asked`, `plans_mentioned`. La ranura debe venir en `reply_draft` del modelo. No hay un adaptador que la agregue y luego marque el primer borrador como válido. Una salida fuera del esquema, sin ranura, con nombres/precios manuales, demasiado larga o destinada a un respaldo sigue siendo un fallo.

## Compatibilidad y evidencia histórica

- La rejilla de 306 configuraciones conserva la variante general c8 y firma las alternativas c9. La línea base y los recursos `n8n-29de5119` no cambian.
- Los 25 casos c8 se guardan sin corregir sus textos. Sus huellas y conteos permanecen explícitos: **0/20 primeros borradores válidos y 12/20 finales del modelo**. Reproducirlos no estima tasas c9.
- Las pruebas históricas de Gates 1 y 2 usan paquetes realmente compilados con 8 y tokens c8 explícitos, como turnos anteriores que llegan al commit después del despliegue. Las pruebas de la oferta vigente negocian y comprueban c9. Ninguna prueba afirma que el `decide` actual ofrece una publicación c8 bajo el compilador 9.
- La comparación de 544 archivos protegidos con la base encontró cero diferencias: artefactos S1/S2, recursos de prompts, voz, telefonía, compilación/publicación de voz, modelo Plan, configuraciones, rutas y migraciones. No se modificó la configuración o publicación de Sofía ni se consultó su estado remoto.
- Las fixtures existentes c6, c7 y terceros v4 permanecen idénticas a la base. Los scripts originales de medición no están en este worktree; no se han creado sustitutos ni modificado umbrales para obtener una aprobación.

## Diferencias S2/S3

S2 permanece intacto. S3 tiene 53 nodos y `active:false`, sin identificador ni versión de workflow para sobrescribir uno existente. Cada uno de los siete nodos de modelo se representa mediante preparación, HTTP explícito y un adaptador que conserva el nombre original. El adaptador rechaza respuestas incompletas, negativas, JSON inválido y esquemas inválidos; no repara el borrador.

La paridad offline ejecuta el código real generado y compara orden, cuerpos HTTP completos, comprobaciones, decisiones del crítico y preparación del commit. Cubre Legacy, turno general c9, lista, fallo blando sobre lista limpia, fallo grave, correcciones, tercer borrador y servidor de comprobación caído. El sello incorpora transporte, selector, validador de presupuesto y decisión del crítico.

El comparador local devuelve `runtimeVerified:false` incluso cuando las diferencias son vacías. No certifica la versión instalada de n8n, la compatibilidad efectiva de HTTP Request 4.4, las credenciales ni el comportamiento remoto del proveedor.

## Límites del resultado y pasos posteriores

1. Revisar el diff completo y resolver formalmente el alcance de la guarda H9, que exige ausencia de cambios en el motor comercial contra `origin/main` y por eso detecta esta corrección autorizada. La guarda no se ha eliminado, ignorado ni añadido a una línea base de fallos tolerados.
2. Autorizar por separado commit, push e integración. No hay migraciones, cambios de permisos ni necesidad de modificar otras ramas como parte de esta entrega local.
3. Antes de modificar un runtime, obtener un export local autorizado del S2 real y pasarlo por el generador/comparador. Si difiere, detener la integración y reconciliar esa diferencia; no asumir que coincide con el versionado.
4. Verificar posteriormente el runtime y sus credenciales; importar S3 como copia nueva e inactiva y conservar S2. La negociación protege frente a mezclar consumidores antiguos con paquetes c9.
5. La creación/aprobación/publicación de una versión y cualquier prueba pagada requieren autorizaciones posteriores distintas. No reutilizar automáticamente una aprobación de versiones anteriores.
6. La aceptación comercial sigue pendiente de una medición real autorizada: **≥17/20 listas finales válidas del modelo, ≥12/20 primeros borradores válidos, máximo 320 caracteres, cero fallos graves, cero recortes y cero respaldos injustificados**. Las pruebas offline no certifican esas tasas ni autorizan repetir la medición que falló.

## Resultado final de la validación

Corrida consolidada posterior al formato, sin cambios de código durante su ejecución: **8443 pruebas, 492742 aserciones, 0 errores, 1 fallo y 279 omitidas**. Pasaron 8163 casos. La única falla es `Tests\Feature\UltronStudio\OutreachIsolationTest::test_la_puerta_de_diff_de_legacy_n8n_otp_y_motor_comercial_esta_vacia_y_la_voz_no_gana_ficheros` (`OutreachIsolationTest.php:374`). Detecta los archivos comerciales modificados por esta tarea; no se trató como aprobación ni se excluyó de la corrida. Las omisiones incluyen pruebas condicionadas a PostgreSQL y un arnés E2E dedicado; no se certifican esas condiciones con SQLite.

| Comprobación | Resultado |
|---|---|
| Sintaxis de archivos PHP modificados o nuevos | 77 archivos, cero errores |
| Pint sobre todos esos PHP | passed |
| `git diff --check` | sin errores |
| Dependencias locales frente a `composer.lock` | 136 paquetes, cero diferencias |
| Archivos protegidos frente a la base | 544, cero diferencias y cero archivos nuevos |
| Comparador S3 | `ok:true`, `differences:[]`, `runtimeVerified:false` |
| Estado S3 | 53 nodos, inactivo |
| Medición comercial c9 | no ejecutada; criterios sin certificar |

Evidencia de la corrida: [JUnit](../../../../storage/test-results/pt17-c9-final-combined.xml) y [salida completa](../../../../storage/test-results/pt17-c9-final-combined.txt), relativos a `backend/` como `storage/test-results/...`. Los logs están fuera del control de versiones. La ficha portable [pt17-c9-verificacion-local.json](pt17-c9-verificacion-local.json) conserva conteos, huellas, límites y manifiesto.

Comando ejecutado desde `backend/`, con clave sintética exclusiva del proceso de pruebas:

```bash
APP_ENV=testing APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
php -d memory_limit=1G vendor/bin/phpunit --do-not-cache-result \
  --log-junit storage/test-results/pt17-c9-final-combined.xml \
  tests/Feature/Marketing tests/Unit/Marketing/Ultron \
  tests/Feature/UltronStudio tests/Unit/UltronStudio \
  tests/Unit/Voice/VoiceIsolationTest.php \
  tests/Feature/Telephony/TelephonyIsolationTest.php \
  tests/Feature/Commercial/ListPlansToolSellableTest.php
```

El proceso termina con código 1 por la guarda H9 indicada; no se convirtió ese resultado en verde. Entorno: PHP 8.3.33 y PHPUnit 11.5.55. No se descargaron dependencias.

Identificación del resultado local:

- Paquete profesional/200 c9: `863bc1f617e179a7cd41201ac0cd44cd7b3e7beb1c40eead9d11e3c902ea9505`; 142394 bytes.
- Recorrido: `motor-S3-c9-2026-11-24.1`; nodesHash `ece291bc216b66a972590c95fa63ff7e03767e37b2bb0616dcaafb4d90d00cc4`.
- Juez c9: `e88cbee7f41baa7b9701a333aa6bd08dfed7814af7cac0e685128f8a6c82fdae`.
- S3 SHA-256: `51b6d1c2bf0c8224e92c2b684e29b2a7758007bf9f0579f8bea1f4a8ee84daa3`.
- S3 huella operativa: `1402711fe8034dc281a379f281bae2f12168e811659ec03c1f01c03e36762592`.
- Legacy: `3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902`.

## Inventario exacto de archivos

86 archivos locales: 61 modificados y 25 nuevos. Ninguno preparado en el índice para commit. Las adaptaciones de pruebas históricas preservan sus casos y condiciones c8; las pruebas de negociación vigente verifican c9.

| Estado | Archivo desde la raíz del repositorio |
|---|---|
| Modificado | `backend/app/Console/Commands/Marketing/UltronPromptsN8n.php` |
| Modificado | `backend/app/Http/Controllers/Api/Internal/UltronController.php` |
| Nuevo | `backend/app/Services/Marketing/Ultron/Prompts/UltronListTurnPrompts.php` |
| Modificado | `backend/app/Services/Marketing/Ultron/Prompts/UltronPromptCompiler.php` |
| Modificado | `backend/app/Services/Marketing/Ultron/Prompts/UltronPromptOffer.php` |
| Modificado | `backend/app/Services/Marketing/Ultron/Prompts/UltronPromptsN8nFlow.php` |
| Modificado | `backend/app/Services/Marketing/Ultron/Prompts/UltronRegenN8nFlow.php` |
| Modificado | `backend/app/Services/Marketing/Ultron/UltronCommitService.php` |
| Modificado | `backend/app/Services/Marketing/Ultron/UltronDecideService.php` |
| Modificado | `backend/app/Services/Marketing/Ultron/UltronDraftCheck.php` |
| Modificado | `backend/app/Services/Marketing/Ultron/UltronDraftDefects.php` |
| Nuevo | `backend/app/Services/Marketing/Ultron/UltronListCriticGate.php` |
| Nuevo | `backend/app/Services/Marketing/Ultron/UltronListTurnContract.php` |
| Modificado | `backend/app/Services/Marketing/Ultron/UltronPlansOverview.php` |
| Modificado | `backend/app/Services/Marketing/Ultron/UltronRegenerationNotes.php` |
| Modificado | `backend/app/Services/UltronStudio/StudioLabEngineCase.php` |
| Modificado | `backend/app/Services/UltronStudio/StudioLabEngineCheck.php` |
| Modificado | `backend/app/Services/UltronStudio/StudioLabEngineContext.php` |
| Modificado | `backend/app/Services/UltronStudio/StudioLabEngineFlow.php` |
| Modificado | `backend/app/Services/UltronStudio/StudioLabEngineModelClient.php` |
| Nuevo | `backend/app/Services/UltronStudio/StudioLabEngineTransport.php` |
| Modificado | `backend/app/Services/UltronStudio/StudioLabOpenAiClient.php` |
| Modificado | `backend/app/Services/UltronStudio/StudioLabPrompts.php` |
| Modificado | `backend/app/Services/UltronStudio/StudioLabRunner.php` |
| Modificado | `backend/app/Services/UltronStudio/StudioLabRuns.php` |
| Nuevo | `backend/app/Services/UltronStudio/StudioListN8nFlow.php` |
| Nuevo | `backend/docs/ultron-studio/ops/motores/generar-flujo-s3.php` |
| Nuevo | `backend/docs/ultron-studio/ops/motores/verificar-flujo-s3.cjs` |
| Nuevo | `backend/docs/ultron-studio/plans/informes-motores/cierre-tecnico-pt17-c9.md` |
| Nuevo | `backend/docs/ultron-studio/plans/informes-motores/pt17-c9-verificacion-local.json` |
| Modificado | `backend/tests/Feature/Marketing/CriticListTurnVetoTest.php` |
| Modificado | `backend/tests/Feature/Marketing/StrategyHintsListTurnTest.php` |
| Modificado | `backend/tests/Feature/Marketing/StudioLabEngineCheckParityTest.php` |
| Modificado | `backend/tests/Feature/Marketing/StudioLabEngineContextParityTest.php` |
| Modificado | `backend/tests/Feature/Marketing/UltronA4ListaDelModeloTest.php` |
| Modificado | `backend/tests/Feature/Marketing/UltronA4LongitudYPreciosTest.php` |
| Modificado | `backend/tests/Feature/Marketing/UltronA4NotasDelServidorTest.php` |
| Modificado | `backend/tests/Feature/Marketing/UltronA4RegistroYPanoramaTest.php` |
| Modificado | `backend/tests/Feature/Marketing/UltronCheckCommitParityTest.php` |
| Modificado | `backend/tests/Feature/Marketing/UltronCheckEndpointTest.php` |
| Modificado | `backend/tests/Feature/Marketing/UltronCheckListFinalTest.php` |
| Modificado | `backend/tests/Feature/Marketing/UltronCommitPromptsTest.php` |
| Modificado | `backend/tests/Feature/Marketing/UltronDecidePromptsTest.php` |
| Modificado | `backend/tests/Feature/Marketing/UltronListTurnCapTest.php` |
| Nuevo | `backend/tests/Feature/Marketing/UltronListTurnExplicitSlotTest.php` |
| Modificado | `backend/tests/Feature/Marketing/UltronListTurnShapesTest.php` |
| Modificado | `backend/tests/Feature/Marketing/UltronListaDelServidorTest.php` |
| Modificado | `backend/tests/Feature/Marketing/UltronPlansListCapFlowTest.php` |
| Modificado | `backend/tests/Feature/Marketing/UltronPromptsTraceCommandTest.php` |
| Modificado | `backend/tests/Feature/Marketing/UltronPt17cEstrategiaDeListaFlowTest.php` |
| Modificado | `backend/tests/Feature/Marketing/UltronRegenerationCommitTest.php` |
| Modificado | `backend/tests/Feature/UltronStudio/UltronStudioEngineReadOnlyTest.php` |
| Modificado | `backend/tests/Feature/UltronStudio/UltronStudioLabEngineInvariantsTest.php` |
| Modificado | `backend/tests/Feature/UltronStudio/UltronStudioLabEngineRunnerTest.php` |
| Modificado | `backend/tests/Feature/UltronStudio/UltronStudioPublicationPromptsTest.php` |
| Modificado | `backend/tests/Feature/UltronStudio/UltronStudioPublicationRulePhrasesTest.php` |
| Modificado | `backend/tests/Feature/UltronStudio/UltronStudioPublicationV2Test.php` |
| Modificado | `backend/tests/Feature/UltronStudio/UltronStudioStatusCatalogTest.php` |
| Nuevo | `backend/tests/Support/HistoricalListTurn.php` |
| Nuevo | `backend/tests/Unit/Marketing/Ultron/Prompts/UltronInitialListPromptsTest.php` |
| Modificado | `backend/tests/Unit/Marketing/Ultron/Prompts/UltronPromptCompileCasesTest.php` |
| Modificado | `backend/tests/Unit/Marketing/Ultron/Prompts/UltronPromptCompiler8Test.php` |
| Modificado | `backend/tests/Unit/Marketing/Ultron/Prompts/UltronPromptCompilerPhrasesTest.php` |
| Modificado | `backend/tests/Unit/Marketing/Ultron/Prompts/UltronPromptCompilerTest.php` |
| Modificado | `backend/tests/Unit/Marketing/Ultron/Prompts/UltronPromptEnginePatchTest.php` |
| Modificado | `backend/tests/Unit/Marketing/Ultron/Prompts/UltronPromptOfferEngineTest.php` |
| Modificado | `backend/tests/Unit/Marketing/Ultron/Prompts/UltronPublishedConfigPhrasesTest.php` |
| Nuevo | `backend/tests/Unit/Marketing/Ultron/UltronListCriticGateTest.php` |
| Nuevo | `backend/tests/Unit/Marketing/Ultron/UltronListTurnContractTest.php` |
| Nuevo | `backend/tests/Unit/Marketing/Ultron/UltronListTurnExplicitSlotTest.php` |
| Modificado | `backend/tests/Unit/UltronStudio/ExportStudioFixturesCommandTest.php` |
| Modificado | `backend/tests/Unit/UltronStudio/StudioLabEngineFlowParityTest.php` |
| Nuevo | `backend/tests/Unit/UltronStudio/StudioLabEngineJudgeTest.php` |
| Modificado | `backend/tests/Unit/UltronStudio/StudioLabEngineListaDelServidorTest.php` |
| Modificado | `backend/tests/Unit/UltronStudio/StudioLabEnginePlansListCapTest.php` |
| Nuevo | `backend/tests/Unit/UltronStudio/StudioLabEnginePt17C8CorpusTest.php` |
| Nuevo | `backend/tests/Unit/UltronStudio/StudioLabEngineTransportTest.php` |
| Modificado | `backend/tests/Unit/UltronStudio/StudioLabIsolationTest.php` |
| Nuevo | `backend/tests/Unit/UltronStudio/StudioListN8nFlowTest.php` |
| Modificado | `backend/tests/Unit/UltronStudio/StudioPromptDeclarationTest.php` |
| Nuevo | `backend/tests/fixtures/ultron-studio/pt17-borradores-reales-c8.README.md` |
| Nuevo | `backend/tests/fixtures/ultron-studio/pt17-borradores-reales-c8.json` |
| Nuevo | `backend/tests/fixtures/ultron-studio/tools/ejecutar-s3-node.cjs` |
| Nuevo | `backend/tests/fixtures/ultron-studio/tools/recorrer-s3-lab.cjs` |
| Nuevo | `n8n/flujos/ultron-studio-s3/LEEME.md` |
| Nuevo | `n8n/flujos/ultron-studio-s3/flujo-s3.json` |


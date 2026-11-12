# Informe del reviewer · ola 5 (fechas del escáner, quejas, aviso de reglas, documentos)

**11-11-2026 · reviewer independiente · rama `feat/ultron-studio-h9`.** Backend HEAD `9502884a` (= `origin/main` local), CRM HEAD `85c62d2`. Revisado todo lo que está sin commit en los dos árboles. No he tocado código, producción ni la red. Los dos árboles quedan como estaban (backend: 36 modificados + 17 sin seguimiento; CRM: 17 modificados); lo único nuevo es este fichero.

Marcas: **[E]** comprobado ejecutando · **[L]** leído en el código, sin ejecutar.

## Veredicto

**APROBADO CON CONDICIONES.** No hay bloqueantes de código. Las cuatro piezas hacen lo que dicen, la paridad backend↔CRM es real y las pruebas nuevas matan las mutaciones que elegí. Para desplegar esta noche hay condiciones de operación (franja horaria, la puerta de diff después del merge, qué ficheros entran en el commit) y una revisión de `security` (Q1-6) que sigue pendiente.

## Verificado ejecutando [E]

| Qué | Motor | Resultado |
|---|---|---|
| `ScanCommercialFactsBogotaDayTest`, `H9RenewalWindowBogotaDayTest`, `H9MembershipExpiredBogotaDayTest`, `H9SweepTriggersEndToEndTest`, `H9CrmEventTriggersEndToEndTest` | SQLite | OK: 6/43, 1/19, 1/28, 6/115, 4/78 |
| `H9ComplaintRecentTest`, `ComplaintEventWiringTest`, `UltronStudioStrategyComplaintSimulationTest`, `OutreachPlannerTest` | SQLite | OK: 8/69, 11/36, 3/15, 8/89 |
| `PolicyAndChannelParityTest`, `DecisionTraceAndPurityTest`, `OutreachCatalogEmittersTest`, `OutreachCatalogTest`, `FixturesSanityTest` | SQLite | OK: 179/488, 7/1157, 2/33, 29/123, 78/13805 |
| `StudioRuleCoherenceTest`, `StudioCoherenceParityTest`, `StudioLabChecksTest`, `StudioLabParityTest`, `UltronStudioDraftTest`, `UltronStudioVersionFormatTest` | SQLite | OK: 31/196, 124/255, 12/192, 207/1738, 14/152, 6/120 |
| `OutreachDispatcherTest`, `OutreachPolicyTest`, `B9Cierre/CancelDecisionTimelineTest` | SQLite | OK: 9/110, 6/50, 3/21 |
| `OutreachIsolationTest` (guardas) | SQLite | **1 fallo, el esperado**: la puerta de diff lista exactamente `app/Observers/Commercial/MarketingAiActionCommercialObserver.php` (`OutreachIsolationTest.php:376`). Las otras 4 pasan |
| `ComplaintEventWiringTest`, `H9ComplaintRecentTest`, `ScanCommercialFactsBogotaDayTest` | **PostgreSQL 14** (`pgunit.sh`, `ultron_studio_rev5`) | OK: 11/36, 8/69, 6/43 |
| CRM: `h9-fixtures`, `commercial-automation-trace-text`, `-content`, `-api.service`, `studio-rules-coherence`, `studio-diff-text`, `ultron-studio-page` (con `IRON_BODY_BACKEND_DIR`) | Vitest | **7 ficheros, 191 pruebas en verde**. La comparación byte a byte con el backend se ejecutó: no aparece su aviso de «sin árbol del backend» |
| CRM: `studio-rules-coherence.parity.spec.ts` (no estaba en la lista del encargo; es el que ata el espejo TS al fixture del backend) | Vitest | 1 fichero, 124 en verde |
| CRM: `npx tsc -p tsconfig.app.json --noEmit` | — | sin errores |
| Paridad de fixtures, por mi cuenta: `shasum -a 256` de los 6 del H9 en el backend, su copia en el CRM y `manifest.json` | — | **las tres huellas coinciden en los 6** |
| Paridad del Studio, por mi cuenta: `php artisan ultron-studio:export-fixtures --path=<scratchpad>` y `cmp` con `testing/` del CRM | — | **los 6 `.ts` idénticos**: la copia de `backend-coherence-cases.testing.ts` es la que genera el backend, no una edición a mano que coincida por casualidad |
| Sustitución literal de la Enmienda 3 en `coherence-cases.json` | — | `git diff` normalizado: exactamente 16 títulos y 16 detalles, sin ningún otro cambio |

## Mutaciones [E]

Hechas en un clon APFS del backend (`cp -c -R`) en el scratchpad, fuera de cualquier árbol de git. Cada fichero se restauró comprobando su sha256.

| # | Mutación | Pruebas | Resultado |
|---|---|---|---|
| MUT-A | `ScanCommercialFacts.php:248`: clave del enlace abandonado con el día UTC (`now()->format('Y-m-d')`) en vez del de Bogotá | `ScanCommercialFactsBogotaDayTest` | **MUERE** (`test_el_enlace_abandonado_se_recuerda_una_vez_por_dia_de_bogota`) |
| MUT-B | `PolicyLadder.php:83`: `complaint_recent` antes que `human_requested_recently` en N6 (cambia la precedencia del efecto: `exit_complaint` le ganaría a `handoff`) | `PolicyAndChannelParityTest` | **MUERE** (caso de fixture «pidió una persona y se quejó: los dos motivos y manda el efecto handoff») |
| MUT-C | `LadderInputBuilder.php:184`: sin datos de eventos, `complaintRecently()` devuelve `false` (falla abierto) en vez de `null` | `H9ComplaintRecentTest` | **MUERE** (`test_aplica_a_lo_comercial_y_a_la_cobranza_no_al_servicio_y_sin_datos_falla_cerrado`) |

## Bloqueantes

Ninguno.

## Importantes

1. **Franja de despliegue: esta noche puede duplicar un `payment_pending` por enlace abandonado.** `ScanCommercialFacts.php:248` [L, razonado sobre el código; la clave nueva la fija MUT-A].
   - **Escenario:** el comando corre cada hora en punto UTC. Las pasadas viejas de esta noche, a partir de las 00:00 UTC (19:00 de Bogotá), ya registraron `tx:<id>:abandoned:2026-11-12` (día UTC). Si se despliega antes de las 00:00 de Bogotá, la primera pasada nueva registra `tx:<id>:abandoned:2026-11-11` (día de Bogotá) para el mismo enlace: un segundo evento. Y mañana `…:2026-11-12` ya existe, así que ese día no habrá ninguno.
   - La inactividad (`:206`, semana ISO) solo puede duplicar un domingo; hoy es miércoles. Renovación y vencidas no duplican (su clave no depende del «hoy»).
   - **Impacto hoy:** bajo. Hay 0 estrategias y el NBA está apagado, así que nadie actúa por el evento. Pero se ve en Supervisión y en el contexto de la Bandeja.
   - **Qué hacer:** desplegar el backend a partir de las **00:00 de Bogotá (05:00 UTC)**, o aceptar por escrito el duplicado. Coincide con lo que pide el informe de backend (§1.3, §2.7); lo subrayo porque el encargo dice «esta noche». → lead / `devops`.
2. **Q1-6 sin hacer: un dato personal nuevo que la supresión 1581 no cubre.** `docs/ultron-studio/ops/supresion-1581.sql` no nombra `commercial_events` [E: `grep -i commercial` sin resultados], y desde ahora esa tabla guarda «esta persona se quejó» (`complaint_created`, con lead, socio, conversación y fila de la IA, sin texto).
   - **Escenario:** una persona pide la supresión de sus datos → sus filas `complaint_created` siguen en `commercial_events`. El hueco ya existía para los otros eventos, pero la queja es un dato más sensible.
   - El plan (§1.3, Q1-6) exige que `security` revise el nivel nuevo de la escalera y este dato. No he encontrado su informe en `plans/informes-motores/`.
   - **Qué hacer:** que `security` lo revise (o que el lead acepte por escrito desplegar sin esa revisión), y que `database-specialist` amplíe la supresión a `commercial_events`. → **escalado a `security`** y a `database-specialist`. No lo juzgo yo.

## Menores

1. **La frase nueva del laboratorio no la fija ninguna prueba.** `StudioLabChecks.php:170` [E: `grep` de «laboratorio es una simulación» y de «no deja que el texto contenga» en `tests/` y en el CRM, sin resultados].
   - **Escenario:** volver al texto anterior, o romper la frase, deja todo en verde. La Enmienda 3 la convierte en contrato y entra en el `results_hash` de cada ejecución.
   - **Qué hacer:** un `assertSame` del `detail` completo de `lab-regla-<S-NNN>` en `StudioLabChecksTest`. → `tester`.
2. **Fuera del alcance: 5 directorios `capturas/` sin seguimiento** (unos 65 MB de PNG) en `docs/ultron-studio/ops/evidencia-h8/2026-11-11/crm-…/` y `evidencia-h9/2026-11-11/e2e/…/`. Son de olas anteriores (su evidencia se confirmó sin capturas, p. ej. `9d6b3252`).
   - **Escenario:** un `git add -A` los mete en el commit de la corrección puntual. Además, `hotfix-backend.sh` aborta si el diff trae ficheros que no están en la lista permitida.
   - **Qué hacer:** añadir al commit solo los ficheros de las cuatro piezas, con una lista explícita. → lead.
3. **`OutreachReplay.php:115` trata cualquier `InvalidArgumentException` de la escalera como `ladder_input_outdated`.**
   - **Escenario:** una entrada guardada con un tipo mal (no solo una clave que falta) también se etiqueta como «anterior al cambio», y eso oculta un defecto real de quien la guardó.
   - **Qué hacer:** limitar la etiqueta a la falta de `context.complaintRecently` (o de cualquier clave que falte) y dejar los demás casos como `ladder_input_invalid`. → `backend`.
4. **`h9-outreach-scope.sh:202` y `:941` siguen diciendo «los 8 observadores»**: ahora son 9. Es solo texto de operación, pero `eventos on` y `eventos medir` se leen al operar. → `devops` (el informe de backend ya lo deja pendiente).

## Respuestas a lo que pedía el encargo

**(a) Escáner, horas límite y claves [E + L].**
- Las cuatro familias usan `gymToday()` = `Carbon::today('America/Bogota')`.
- `membership_end_date` llega como texto `Y-m-d` por su accesor (`User.php:76-81`), así que `Carbon::parse(…, BUSINESS_TZ)->startOfDay()` es la medianoche de Bogotá de ese día (no la conversión de una medianoche UTC). Comprobé que no había un desfase de un día.
- Las consultas pasan fechas en texto (`toDateString()`). Los cortes de los enlaces son intervalos de tiempo y no dependen de la zona.
- Claves: renovación y vencidas, sin riesgo; inactividad y enlaces, ver Importante 1.

**(b) Escalera, planificador y despacho [E + L].** `grep` de `complaintRecently` y de `PolicyLadder::decide` en `app/`. Arman la entrada:
- `OutreachPlanner::plan()` (`:472`), `OutreachDispatcher::process()` (`:293`), los dos por `LadderInputBuilder::build()` (`:134`);
- `StudioStrategySimulator::person()` (`:165`);
- `OutreachReplay` la relee de lo guardado (con su `catch`).

El CRM no tiene escalera en TS: solo los textos. No queda ningún otro constructor.

Qué pasaba antes y qué pasa ahora:
- `normalize()` exige las claves del SPEC e ignora las que sobran (`PolicyLadder.php:584-588`).
- Con la fuente de eventos sin datos, N6 ya negaba por `human_requested_recently` (null), así que no aparece ninguna negativa nueva salvo una queja real o la finalidad que no aplica.
- En `service`, `complaintRecently()` devuelve `false` antes de mirar los eventos.
- N6 no recibe `$sombra`, así que niega igual en sombra.
- Precedencia del efecto: `success_reached` > `context_changed` > `human_requested_recently` > `complaint_recent`. Lo fija el fixture (MUT-B muere).
- Planificador y despacho cierran la inscripción con `exited/complaint_recent`. El despacho devuelve `canceled`, un resultado que ya existía.

**(c) El emisor [E + L].**
- `DB::afterCommit` en Laravel 12.58: sin transacción, ejecuta en el acto (`DatabaseTransactionsManager.php:205-212`); con transacción, al confirmar la raíz; si se revierte, se descarta. Las tres ramas tienen prueba y pasan en PostgreSQL.
- El `try/catch (\Throwable)` va dentro del callback, así que ni ULTRON (`UltronCommitService.php:2545`, cuyo `catch (QueryException)` traduciría el error a «ya ejecutado») ni el agente de ventas reciben la excepción.
- Los 12 sitios que crean `MarketingAiAction` usan `create()`, así que el observador los ve.
- Fuera de la transacción, el registrador hace un `exists()` previo y solo traga la violación de unicidad.

**(d) Paridad [E].** Byte a byte en los 6 fixtures del H9 (las tres huellas). La copia del Studio la regeneré desde el backend: idéntica. Los textos `complaint_recent` y `exit_complaint` son iguales letra por letra en `DecisionTraceText.php` y en `commercial-automation-trace-text.ts`, y el `dictionaryHash` del manifiesto los fija. No es un verde por construcción: el `h9-fixtures.spec` compara contra el árbol del backend.

**Calidad de las pruebas [E + L].**
- Usan las clases reales: `OutreachPlanner`, despacho, `PolicyLadder` y el observador registrado por el proveedor. El único doble es un `CommercialSubjectResolver` que lanza, para probar la contención.
- Ninguna prueba se ha relajado: `OutreachCatalogEmittersTest` sube de 21 a 22 emisores con un `assertArrayHasKey('complaint_created')` explícito, y `OutreachCatalogTest` añade la aserción de la queja.
- No hay `skip` nuevos.

**Alcance [E].** Nada en `database/`, `routes/`, `config/`, `composer.*` ni `bootstrap/`. Todo lo modificado pertenece a A–D, salvo las `capturas/` (Menor 2). La puerta de diff falla solo por el observador autorizado (informe de backend, §2.1). `ScanCommercialFacts.php` y el proveedor quedan fuera de lo que vigila.

## Despliegue

- **Orden:** primero el backend, luego el CRM enseguida. Las dos ventanas mezcladas son seguras [L]:
  - **Backend `.2` con el CRM viejo.** El CRM no fija la versión del catálogo en ejecución: lee `available` y `triggerable` del servidor, así que `complaint_created` aparece como evento de condición. Los códigos `complaint_recent` y `exit_complaint` se verían en crudo (`traceTextOrCode`), pero con 0 estrategias no hay ninguna decisión que los lleve. En los borradores, el aviso del servidor (texto nuevo) manda sobre el del navegador (`studio-api.types.ts:323`).
  - **CRM nuevo con el backend viejo.** La vista previa local enseña el texto nuevo del aviso y el servidor el viejo. Las fixtures y los textos nuevos no se usan en ejecución. No se rompe nada.
- **Antes del hotfix:**
  1. Hacer el merge a `origin/main` (el script lo exige).
  2. Volver a pasar `OutreachIsolationTest` contra el `origin/main` nuevo: tiene que salir verde.
  3. Pasar a `hotfix-backend.sh` la lista completa de ficheros de `app/`, `tests/` y `docs/` (aborta con cualquier otro).
  4. El script ya hace `queue:restart`, que hace falta: el observador también corre en los workers (`AnalyzeInboundMessage`) y el despacho en `DispatchOutreachJob`.
- **Franja:** a partir de las 00:00 de Bogotá (Importante 1).
- **Vuelta atrás del backend:** la que imprime el script (`git checkout --detach <previo>` + `queue:restart`). Queda:
  - las filas `complaint_created` en `commercial_events`: inofensivas, porque el catálogo `.1` vuelve a dejar el evento no disponible;
  - las inscripciones cerradas con `complaint_recent`, que se quedan cerradas;
  - con el código viejo, la escalera ignora la clave de más, así que el replay de una decisión con queja daría «distinta», sin caerse;
  - un borrador del Studio que use `complaint_created` en una condición pasaría a citar un evento no disponible (hoy no hay ninguno: la publicación está apagada).
- **Vuelta atrás del CRM:** su despliegue normal, sin dependencias.

## Notas

- **Bien, comprobado:**
  - el docblock del escáner y el de `repeated_subject` (el reparto de recuentos) coinciden con el código;
  - el CRM ignora la clave `repeated_subject` en `counts`: parsea claves concretas (`commercial-automation-parse.ts:555-560`); ni se rompe ni la enseña;
  - el `results_hash` del laboratorio se recalcula sobre lo guardado (`StudioLabVerdicts.php:69-74`), así que las ejecuciones anteriores al cambio de texto siguen pudiendo recibir su veredicto.
- **D (documentos) [L]:**
  - existen `ops/supresion-1581.sql`, con su sección de voz, y `tests/Feature/Telephony/Schema/VoiceSuppression1581Test.php`. No ejecuté esa prueba, así que «repetida el 11-11» no lo he comprobado;
  - la tabla de la segunda tanda (18 y 0) cuadra con `05-c4-segunda-tanda.txt`: 2→20 en Unit, y Feature con 10 = 1 error + 9 fallos en las dos copias.
- **Observación sin importancia práctica:** `MarketingAiActionCommercialObserver.php:73` marca `source=sales_agent` en toda fila sin `source_type`, también las que llegan por `POST /internal/marketing/actions` (n8n heredado). Solo es la etiqueta del payload.
- **No he podido verificar:**
  - el estado de producción (commit desplegado, `opcache.validate_timestamps`: el script no recarga PHP-FPM; si opcache no valida marcas de tiempo, la web seguiría con el código viejo, **a confirmar por `devops`**);
  - las baterías completas (las lanza el lead);
  - el tráfico real de ULTRON y n8n;
  - las 46 mutaciones que declara backend (hice las mías: 3 de 3 muertas).

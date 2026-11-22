# Revisión final del lote 2 (A cuatro ojos · B clasificador AI9 · C aviso de pagos), 22-11-2026

**[C]** comprobado ejecutando · **[L]** leído · **[S]** supuesto. Solo lectura sobre los tres árboles: no edité ni confirmé nada. Mis sondas viven en mi scratchpad. La única escritura en `.git`: los objetos sueltos (sin ninguna ref) que dejó `git merge-tree --write-tree`.

## Veredictos (desplegar en producción con todo apagado)
| Rama | Veredicto | Bloqueantes |
|---|---|---|
| A · `cierre/gobierno` (2f76488c) | **APROBADO** | 0 |
| B · `cierre/motores` (1ebe8d2e) | **APROBADO CON CONDICIONES** (las de despliegue de §5) | 0 |
| C · `cierre/pagos-aviso` (739ac150) | **APROBADO** | 0 |

## A · Cuatro ojos por regla
**Para una versión SIN frases de reglas no cambia nada, ni en las respuestas ni en las lecturas [C].** Lo comprobé con una sonda diferencial propia: la misma prueba HTTP sobre HEAD y sobre una copia del árbol con los cuatro servicios devueltos a `dfcee951` (y sin `StudioRulePhraseAuthors`). Uuids y reloj son deterministas. El contenido lleva las 12 R-xx que importa `import-baseline` (como producción) y además una S-001 sin frases.
- La sonda recorre 25 pasos: el detalle (creador, revisor, publicador, promotor), la lista, aprobar (creador 403 y revisor 200), la vista previa de tres personas, publicar (creador 403 y publicador 201), la evidencia, promover (creador 403 y promotor 201), una segunda versión publicada y revertida, y una tercera de Root (creador con permiso de publicar: 403 al publicar y al promover).
- Resultado: estado, cuerpo, `previewHash`, requisitos (las 10 claves de siempre), `blocking`, mensajes y la secuencia COMPLETA de SQL, con sus valores, son **idénticos**. En SQLite son 199 consultas y en PostgreSQL 14, 205. El libro de auditoría también es idéntico. Solo cambian los hashes aleatorios de los tokens de sesión.
- Por qué [L]: `StudioRulePhraseAuthors::of()` devuelve `null` antes de cualquier consulta si `StudioRulePhrases::byRule()` (pura) no da códigos (`StudioRulePhraseAuthors.php:69-72`). Las tres ramas de `facts()` y de `previewFrom()` vuelven a lo de antes con `null` (`StudioPublications.php:956-974`, `:1337-1339`). `actions()` solo llama al cierre si se llega a esa puerta, y lo memoiza (`StudioVersions.php:493-507`, `:518-524`). La voz devuelve lo de antes si `of()` da `null` (`StudioVoicePublishing.php:1086-1098`). `previewHash` no recibe la autoría (`StudioPublications.php:1320`).
- Sin bloqueantes. Revisé el orden (creador primero, después autoría), el fallo cerrado (`unverified`), la memoización y que rechazar no pase por aquí, y que ningún llamador de `facts()` quede con dos argumentos (solo `:207` y `:598`) [L].

**No bloqueantes (A):**
1. `tests/Feature/UltronStudio/UltronStudioPublicationLifecycleTest.php:839-856`: `phraseRuleBy()` quedó metido entre el docblock de `assertOutcome()` y su función, así que `assertOutcome` pierde su `@param … $carga` y ese docblock queda huérfano. Es cosmético. → backend.
2. Una S-NNN a la que le falte la línea del libro de una revisión ≤ la copiada niega aprobar, publicar y promover a TODOS para siempre: una revisión nueva no rellena el rastro de las anteriores, y la única salida es archivarla y crear otra. Hoy no existe ninguna S-NNN en producción, y solo se llega por SQL o por un importador futuro. Conviene escribirlo en el runbook. → documentación.
3. La voz no enseña el requisito antes de actuar: el CRM se entera por el 403 (`detail: rule_phrase_author`). Solo afecta a versiones con frases.
4. Los tres casos nuevos de `publication-cases.json` (+160/−0) están escritos a mano, sin la segunda derivación. Está declarado en `publication-cases.README.md:35`.

## B · Clasificador de respuestas (AI9), apagado
**Con todo por defecto no se ejecuta nada del clasificador [C].** Lo comprobé con otra sonda diferencial: el mundo real de W9 (plantilla en vivo enviada por la puerta real y una respuesta por el webhook) pasado por `Artisan::call('outreach:whatsapp-outcomes --json')`, es decir, por el contenedor como en producción, sobre HEAD y sobre una copia con todo el producto de B devuelto a `0c57d7c4`.
- El contenedor deja `classifier = NULL`: Laravel 12 usa el valor por defecto si la clase no está enlazada (`Container.php:1327-1331`). No se resolvió ni `ReplyClassifier`, ni el puerto, ni `OpenAiReplyClassifierTransport`. El enlace de `AppServiceProvider.php:127` es perezoso [C].
- W9, en las dos pasadas: SQL idéntico (15 y 14 consultas) y las mismas filas en outcomes, enrollments, jobs, budget_counters, brakes e incidents. La única diferencia es la clave nueva `"classifier": []` del JSON (`WhatsappTemplateOutcomes.php:96`), que nadie analiza en `ops/` [C].
- Monitor (`outreach:monitor`, con su bandera): mismo número de consultas (29). Cambian el resultado (`checks.classifier`) y el `counts` de `outreach_runs` (`"classifier":0`), aditivo y lejos del tope de 4096 bytes. En producción, con la caché `database`, suma **una** lectura de la caché por pasada (`OutreachMonitor.php:77`, `ReplyClassifierHealth.php:61`, que nunca lanza) [C en pruebas, L en producción].
- La lectura del estado (pantalla H9 del CRM) añade `config.classifierSetup`, un SELECT del contador del día y una lectura de la caché (`OutreachReadModels.php:52-56`, `:93`). Es aditivo [L].
- **El registro de la respuesta no se puede alterar [L+C]:** el clasificador solo corre si ESTA pasada ganó la inserción de `replied`, después del COMMIT (`DB::afterCommit`, `:280`), con `try` propio (`:281-285`) y su propio `try` en `classifyReply` (`ReplyClassifier.php:91-97`). Solo AÑADE etiquetas sin contacto. Lo cubren `test_una_excepcion_del_transporte…`, `test_si_la_transaccion_de_quien_llama_se_deshace…` y `…_fuera_de_toda_transaccion`, en verde en los dos motores.
- **Migración [L+C]:** es aditiva, una sola sentencia `DROP/ADD CONSTRAINT` (`:50`) y NARROW es el texto exacto de `100002:627`. Con el clasificador apagado nunca se escribe `classifier:reply`, así que el código funciona antes y después de aplicarla. `down()` se niega si hay filas `classifier:*` y, sin ellas, deja el CHECK estrecho (`:53-69`; probado en PostgreSQL por `ClassifierBudgetScopeTest`). `h9-schema-check.sql` no fija el texto de ese CHECK.

**No bloqueantes (B):**
1. Encendido SIN la migración en PostgreSQL, la reserva viola el CHECK. La excepción se queda dentro (falla cerrado), pero no cuenta en la racha del monitor (informe, línea 386). Hay que aplicar la migración antes de encender. → condición.
2. `classifyAfterCommit()` captura `&$counts` (`:277-293`). Si `forJob()` corriera alguna vez dentro de una transacción externa, el callback se ejecutaría después de que `forJob` devolviera `$c` por valor, y esos recuentos se perderían de la salida. Clasificar, clasificaría igual. Solo afecta a la salida informativa.
3. `ClassifierBudgetScopeTest.php:45-49` usa `now()` real, aunque solo para las marcas de tiempo, con un día literal: no depende del calendario.
4. Lo que pasa del tope por pasada no se clasifica nunca (decisión M2, documentada en `:236-256`).

## C · Aviso de «cobrado sin servicio», apagado
- **El criterio es el de las clases `FALLO_*` del SQL, cláusula por cláusula [L], y está atado al escritor real [C].** Usuario resuelto como `coalesce(t.user_id, m.user_id)` y existente. La unión por referencia Y `origin='gateway'`. Sin fila: plan de la transacción → `SIN_PAGO_NI_MEMBRESIA`; sin plan → `SIN_REGISTRO_DE_PAGO`. Con fila: `paid`, `p.plan_id` y `pl.duration_days>0` sin periodo → `CON_PLAN_SIN_PERIODO`. El corte se calcula con la misma fórmula y `created_at` nulo sigue siendo FALLO, como en el SQL. No avisa de `REVERTIDO`, `SIN_PLAN`, `REVISAR_PLAN_SIN_DIAS` ni `SIN_USUARIO` (`UnactivatedApprovedPayments.php:85-140`). Plans y payments no tienen borrado lógico, la zona horaria es UTC (`config/app.php:78`) y el índice único parcial impide duplicados. Lo que añade (ventana de 7 días y gracia de 5 min) está declarado.
- **No escribe nada en pagos, socios ni membresías [C]:** con la bandera apagada no hace ninguna consulta. `--dry-run` solo hace SELECT, con la bandera apagada o encendida, y deja intactos los recuentos de transactions, payments, users y members y la fila de la transacción. Lo único que escribe es `incidents` e `incident_events` vía `IncidentRecorder`, y el log. No toca `PaymentMembershipActivator` ni ningún fichero del núcleo [C, por la lista de ficheros].
- **Con la bandera apagada no se agenda [C]:** `routes/console.php:239-244`. La prueba recarga el fichero real y lo comprueba también con IRON GUARD encendido; encendida, `*/30`, `withoutOverlapping(10)` y `onOneServer`.

**No bloqueantes (C):**
1. Declarado en el informe §6: no va dentro de `ChannelHealthDetector` y falta la prueba en PostgreSQL de la cadena real de fallo que pedía `diseno-arq-pagos.md` §2(d).
2. Un caso que no se puede resolver vuelve a avisar cada 30 min durante 7 días. «Reconocido» (`acknowledged`) mantiene la agrupación (`Incident.php:70-72`): conviene decirlo en `ops/aviso-pagos-aprobados-sin-activar.md` en vez de «cerrarlo».

## Las seis preguntas
1. **Corrección y regresiones.** No encontré errores de lógica ni cambios de comportamiento fuera de lo declarado. A y B quedan demostrados con sus sondas diferenciales; C, por la equivalencia con el SQL y sus 22 casos.
2. **Producción HOY con todo apagado.**
   - A: en el detalle, la vista previa, aprobar, publicar y promover (WhatsApp y voz) corre `byRule()`, que es pura. Sin S-NNN con frases no hay consulta y la respuesta es idéntica [C].
   - B: el enlace perezoso. W9 solo corre si `outreach.scheduler.enabled` (`routes/console.php:504-523`), y en sombra solo lee trabajos `live`; aun con uno en vivo, el SQL es idéntico [C]. El monitor añade una lectura de la caché; el estado, un SELECT y una lectura de la caché.
   - C: nada se agenda. A mano, sin bandera, no lee nada.
   - Ninguna de las tres puede romper el motor. Si el programador del H9 está encendido hoy en producción es [S]: no pude mirarlo.
3. **Lo que no pueden tocar [C]:** ninguna de las tres ni `cierre/bandeja` toca `app/Services/Marketing/**`, `app/Http/**`, `routes/marketing.php`, `config/marketing.php`, `app/Services/{Commercial,Automation,Otp,Sms,Payments,Wompi}`, `resources/ultron-prompts` ni `n8n/` (`git diff --name-only` contra la base de cada una). Para una versión sin frases, A no cambia NADA: ni bytes de respuesta ni consultas (arriba).
4. **Pruebas:** usan las clases reales, por HTTP, la orden y el contenedor. No se aflojó nada: los recuentos solo suben (58→61, 71→74, 231→234) y el fixture es +160/−0. El reloj está congelado: A `at()`/`setTestNow`, B `setTestNow` (y el trabajador de la carrera lo recibe por argumento), C `travelTo`. La única excepción es la menor de B.3. Hay mutaciones declaradas en los informes, que no repetí.
5. **Despliegue y vuelta atrás.**
   - A: sin migración ni configuración. El paso normal del guion (`config:cache`, `route:cache`, `queue:restart`). Vuelta atrás: `git revert -m 1` del merge; nada de datos (los `*.four_eyes_denied` que se hubieran escrito quedan, porque el libro es de solo inserción).
   - B: la migración `2026_11_22_100001` con el ROL DUEÑO (`ironbody-migrate`), `lock_timeout` de 5 s y un ACCESS EXCLUSIVE breve sobre `outreach_budget_counters`, que es pequeña. `config:cache`, aunque sin él los valores por defecto del código también apagan. `queue:restart`. Vuelta atrás: revertir el código y dejar el CHECK ancho, que es inocuo, o `down()` (sin filas `classifier:*`, que con el clasificador apagado no habrá). Antes de encender: migración aplicada y C1–C5 de security.
   - C: `config:cache` y nada más (el programador relee `routes/console.php` en cada `schedule:run`). Antes de encender, `--dry-run` con salida 0. Vuelta atrás: revertir; apagada no escribe nada.
   - **Juntas o separadas:** pueden ir en UN lote, con un merge por rama para revertirlas por separado. **Condición:** el guion de la ola tiene listas cerradas (`deploy-h9-backend-cierre.sh:126` `MIG_OLA`, `:459`/`:529` migraciones esperadas, `:501` `LISTA_CERRADA`, `:714-719` total del programador). La ola nueva tiene que nombrar la migración de B y sus ficheros; si no, el guion aborta, como debe. Con las tres apagadas, el total del programador no cambia. A es independiente de la prueba de las 7:00 (la versión aprobada no lleva frases), pero conviene no desplegar con la ventana de publicación abierta.
6. **Fusión [C]:** `git merge-tree --write-tree` por pares entre `feat/ultron-studio-h9`, bandeja, A, B y C: **cero conflictos** en los 10 pares. Comparten fichero solo bandeja y B (`ULTRON_STUDIO_H9_CONTRACT.md`) y B y C (`.env.example`, en secciones distintas); las dos fusiones son mecánicas. Monté el árbol fusionado (h9 + bandeja + A + B + C, árbol `28b7eb60`) y le pasé guardas, IronGuard, AI9, Schema, Guards de Outreach, autoría, ciclo y paridad de publicación: 937 pruebas, 933 en verde. Las 4 rojas son del entorno de la copia (sin `.git`: `SecretExposure` y `OutreachIsolation` dan código 128; solo `backend/`: las del catálogo no ven `n8n/`). En cada rama pasan, y ninguna rama toca esas rutas.

## Pruebas ejecutadas (línea final)
- C nuevas: SQLite `OK (22 tests, 82 assertions)` · PostgreSQL `OK (22 tests, 82 assertions)`. Zona en SQLite (IronGuard, Observability, Wompi, Payments y 4 del programador): `OK (275 tests, 2435 assertions)`. Guardas: `OK (141 tests, 182991 assertions)`.
- A nuevas: SQLite `OK (15 tests, 299 assertions)` · PostgreSQL `OK (15 tests, 299 assertions)`. Zona Studio en SQLite: `Tests: 3790, Assertions: 42046, Failures: 1, Skipped: 277`. El fallo fue por tiempo (`UltronStudioHardeningTest`, 150,6 ms frente a 150 ms, con la máquina en carga 19); aislado da `OK (1 test, 45 assertions)` y esa prueba no la toca la rama. Publicación, versiones y voz en PostgreSQL (15 ficheros): `OK (270 tests, 6631 assertions)`. Guardas: `OK (141 tests, 183008 assertions)`. Sonda diferencial: idéntica en SQLite (199 consultas) y en PostgreSQL (205).
- B nuevas (AI9, Pure, StaticGuards, OutreachSchema): SQLite `OK, Tests: 150, Assertions: 3917, Skipped: 7` (solo PostgreSQL) · PostgreSQL `OK (150 tests, 4110 assertions)`. Zona Outreach: SQLite `OK, Tests: 1849, Assertions: 99334, Skipped: 167` · PostgreSQL `OK, Tests: 1849, Assertions: 100498, Skipped: 29`. Guardas: `OK (149 tests, 186056 assertions)`. Sonda diferencial: W9 idéntico.
- Árbol fusionado (SQLite): `Tests: 937, Assertions: 205472, Failures: 4 (entorno), Skipped: 134`.

## Lo que NO pude verificar
- Producción (sin ssh): si el programador o el monitor del H9 están encendidos, el almacén de caché real y que el rol dueño pueda hacer el ALTER [S].
- B encendido SIN la migración en PostgreSQL: lo leí y lo afirma el informe; no lo ejecuté.
- La voz no entró en la sonda diferencial de A: la revisé leyendo y con `UltronStudioVoicePublishingTest` en verde en PostgreSQL.
- El árbol fusionado en PostgreSQL y las 4 guardas que necesitan `.git` y `n8n/` en forma fusionada.
- Cómo usa el CRM las claves nuevas (`detail`, `rulePhraseAuthor`, `classifierSetup`), que son aditivas. Tampoco revisé un guion de despliegue de esta ola, porque todavía no existe.

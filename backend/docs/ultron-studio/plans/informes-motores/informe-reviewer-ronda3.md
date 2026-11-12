# Revisión final · bandeja asistida, ronda 3

Fecha: 2026-11-12 · Revisor: `reviewer` · Alcance: backend `298b855a` (encima de `d9243a97`) y CRM `eeb4b79` (encima de `b10d593`), los dos LOCALES. La seguridad de N-1/N-2 la revisa `security` en paralelo: aquí no se repite.

Marcas: **[C]** comprobado ejecutando · **[L]** leído en el código · **[S]** supuesto, sin comprobar.

## Veredicto

| Commit | Veredicto | ¿Se sube y se despliega hoy? |
|---|---|---|
| Backend `298b855a` | **APROBADO** | **Sí**, en lo que toca a esta revisión. Queda pendiente el veredicto de `security` sobre N-1/N-2 y la batería completa que corre el lead en otro árbol. |
| CRM `eeb4b79` | **APROBADO** | **Sí**. Es aditivo: con el backend de hoy (`306b83e3`) se ve igual. Mejor desplegarlo junto con el backend o después de él. |

No hay bloqueantes ni importantes. Los menores de abajo van a la lista de pendientes.

## Lo que se ejecutó

- **[C] Diferencial de `SubjectSuppressions`, la versión vieja frente a la nueva.** Lo hice en una copia del backend en el scratchpad. Dentro de `OptOutAcrossMediaTest`, cada llamada a `isSuppressed()` se envolvió para calcular tres cosas: la regla de `306b83e3` copiada tal cual (con su SQL y su `limit(200)`), el `isSuppressed()` nuevo y `areSuppressed()` con una sola pregunta. Al final de cada prueba, además, `areSuppressed()` respondía todas las preguntas juntas en una sola llamada. Hubo 32 preguntas: opted_out por inbound_message, voice_call y staff; opt_out_word con failed y human_requested; wrong_person; respuestas y fallos que no suprimen; dos finalidades × dos medios. Las tres respuestas coinciden en todas, y el lote también (lotes de 24 y de 4 claves). Las 3 pruebas pasaron con 135 aserciones.
- **[C] Mutaciones de m-R1 y m-R2**, sobre `H9AssistedOptOutStrategyTest`, en SQLite y en la copia:
  - `heldCount()` sin el `whereNull(assisted_claimed_by_admin_id)`: m-R1 falla (4/4). **Muerta.**
  - `heldCount()` sin el `expires_at > ahora`: m-R1 falla (4/4). **Muerta.**
  - `break 2` → `break` en el corte de la página: m-R2 falla. **Muerta.**
  - `count($filas) >= $limite` → `>`: m-R2 falla. **Muerta.**
  - `heldCount()` sin el `orWhereNot(inRunningPart)`: m-R1 **sobrevive**. Cuando no hay ningún freno, el cierre queda vacío y el recuento coincide por casualidad. La mata otra prueba del mismo fichero (27 pruebas, 1 fallo con la mutación). La batería la cubre; m-R1 sola, no.
- **[C] Base en la copia:** las pruebas m-3, m-8, m-R1 y m-R2 dan 7/7 en verde.
- **[C] CRM:** `npx tsc -p tsconfig.app.json --noEmit` termina con exit 0. `node scripts/check-literals.mjs`: «417 ficheros revisados · literales correctos». `ng test` del módulo `commercial-automation`: 20 ficheros y **634 pruebas en verde**.
- No lancé la batería completa (lo pidió el lead) ni nada en PostgreSQL: me basta lo que el lead ya ejecutó en PG 14.
- La copia y las mutaciones se quedaron en el scratchpad. Los dos árboles siguen limpios; el único fichero nuevo es este informe.

## 1. Código compartido: ningún llamador cambia de comportamiento

### `SubjectSuppressions::isSuppressed()`

- **[L]** Hace la misma consulta que antes. Cambia solo el orden de los dos `WHERE`, que se unen con AND: el resultado es el mismo. Se conservan el `limit(200)` sin `ORDER BY` (igual que antes), las mismas columnas y la misma regla, que se movió tal cual a `suppresses()`. No hay `catch` nuevo: un error de base de datos sube igual que antes.
- **[L]** Lo llama un solo sitio de producción: `LadderInputBuilder.php:98`, que es la entrada de N2 en el planificador y en el despachador.
- **[C]** El diferencial de arriba lo confirma.
- **[L]** `areSuppressed()` es nuevo. Solo lo usa `AssistedQueue.php:430`. Con `[]` devuelve `[]` sin consultar. Conserva las claves de la pregunta. **No tiene `limit`**, a diferencia de `isSuppressed()`. Eso solo puede hacer que la bandeja retenga cuando el despachador no lo haría, nunca al revés. Hace falta que una persona tenga más de 200 filas supresoras que no la suprimen, algo irreal (ver Notas).

### `OutreachPublishedStrategies::liveStrategies(?array $strategyIds = null)`

- **[L]** Con `null` construye exactamente la misma consulta que antes (`whereNull('archived_at')->orderBy('id')`), y el resto del método no cambia. Lo llaman sin argumentos los seis sitios de producción: `OutreachPlanner:359`, `OutreachReconciler:122`, `OutreachMonitor:84` y `:103`, `OutreachReadModels:38`, y `runnableStrategies()` y `liveVersion()` dentro de la propia clase. Solo `AssistedQueue:512` pasa ids.
- **[L]** La clase es `final` y en `tests/` ni `app/` hay subclases ni redefiniciones de `liveStrategies`: cambiar la firma no rompe ninguna declaración compatible.
- **[L]** Con una lista de ids, `chainStates()` verifica cada entrada contra el `seq − 1` GLOBAL de la tabla de publicaciones, no contra la lista: filtrar no cambia la verificación. `[]` devuelve `[]`, y solo la bandeja lo pasa, cuando su vista está vacía. El orden de salida sigue siendo por id.

## 2. `AssistedQueue.php`

- **[L] La página y `claim()` son coherentes.** Las dos usan la misma `withholdReasons()` con las mismas entradas: frenos de `openScopes()`, partes en marcha de `runningParts()`, la foto y el libro de supresiones. La página filtra en SQL lo mismo que `withholdReasons()` comprueba en PHP para lo sin reclamar: frenos, plazo y `inRunningPart`. En `claim()`, `runningParts([strategy_id])` lee solo la estrategia del trabajo, y la verificación de la cadena es la misma porque es global. La parte que comparan las dos es la de la INSCRIPCIÓN (`e.strategy_version_id`, `e.part`), la misma que usa el despachador (`OutreachDispatcher:237`). Lo que la página no ofrece, `claim()` lo rechaza con 409 `not_in_queue`; lo que ofrece, `claim()` lo deja reclamar, salvo que algo cambie entre las dos lecturas.
- **[L] `runningParts()`** pide `runnable() && !paused() && chainStatus === 'active'`. En la cadena solo hay tres estados: `active`, `paused` y `off`. El despachador cancela por `paused`, `!runnable` y huérfana, así que la bandeja no es más permisiva que él.
- **[L] `opted_out` y `policy_unknown`** solo se dan para una persona resuelta. La política de cada módulo empieza en `false` (`LeadFacts.php:151`, `VoiceConsentFacts`), así que un socio sin prospecto no cae siempre en `policy_unknown`. [C] m-R2 ofrece 120 filas de personas resueltas.
- **[L] m-8.** El `all` de quien mira ya no incluye lo que otra persona reclamó y está vencido. Su reclamante sigue viéndolo en `mine` y en su propio `all`, como `expired`.
- **[L] `scanCapped`** = `leidas >= MAX_SCAN && filas < limite`. Si la página se llena o la última tanda viene incompleta, sale `false`. Hay un único falso positivo, inocuo: exactamente `MAX_SCAN` filas y nada detrás. El texto dice «puede haber», así que sigue siendo cierto.
- **[L] Coste por petición: ninguna consulta nueva por fila.** Las consultas por fila que hay (`contentOfVersion()`, `reasonOf()` y el guion en `dto()`) ya estaban antes. Lo nuevo va por página (la cadena) o por tanda (una foto con la política y una consulta al libro). [C] Lo fija `test_las_lecturas_de_la_pagina_y_de_claim_van_en_orden_y_por_lotes`. Sí crecen dos cosas, y van en el menor m-1.

## 3. Pruebas

- **[L] Las aserciones cambiadas en `H9AssistedConditionsTest` y `OutreachApiTest` no se aflojan.** Las lecturas de frenos pasan de 1 a 2, con la segunda obligada a ir DESPUÉS del `UPDATE` y la primera todavía antes de la queja y del `UPDATE`. `WITHHELD_REASONS` se compara con la lista completa de siete. `scanCapped` se añade con su valor exacto en cuatro `assertSame`. El recuento exacto de consultas sigue siendo exacto: `1 + (1 + 9) + 2·tandas`, más una aserción nueva de que la cadena se lee una vez. `complaint_unknown` → `policy_unknown` en la clave suprimida es un cambio de motivo, no de retención: la fila sigue sin enlace ni guion y sin ofrecerse. Todas son más estrictas o equivalentes. Hay una discrepancia sin importancia: el informe habla de «seis» y yo cuento seis cambios en `H9AssistedConditionsTest` y uno en `OutreachApiTest`.
- **[C] m-R1 y m-R2 existen y matan sus mutaciones** (ver arriba). m-R1 cubre los cuatro casos: parada global, freno del canal, freno de la estrategia y pausa, con un reclamado y un vencido a la vez.

## 4. CRM `eeb4b79`

- **[L] La relectura al volver a la pestaña no puede entrar en bucle.** Solo responde a `visibilitychange`, y la carga no provoca ese evento. No hace nada si el documento no está visible o si la sección está en `loading`. `cargar()` pone `loading` en TODA lectura, también en las relecturas (`commercial-automation-session.ts:744`), y descarta respuestas viejas por `seq`. Además, como mucho una vez cada 15 s. El oyente es una función flecha guardada en una propiedad, se quita en `DestroyRef.onDestroy` con la misma referencia, y hay prueba de ello.
- **[L] Relee con el último filtro de la sesión** (`bandejaFiltro`), que es el de los datos que se ven.
- **[L] Un motivo desconocido sigue contando como retenido.** `retencion()` devuelve `'unrecognized'` para cualquier cadena que no esté en la lista, y su texto dice que no contactes. [C] Hay prueba con `legal_review`.
- **[L] Con `scanCapped === true` no afirma que no haya trabajo.** Esconde los dos textos de vacío y muestra el aviso del tope. Un `scanCapped` ilegible se lee como `null` y la pantalla se comporta como antes.
- **[C]** tsc, literales y 634 pruebas en verde.

## Hallazgos

### Bloqueantes

Ninguno.

### Importantes

Ninguno.

### Menores (a la lista de pendientes)

**m-1 · El coste de una página crece con el número de estrategias de la vista y con el número de prospectos «no contactar» de TODA la base. No crece con las filas.** [L] para lo que crece, [S] para su peso en producción.

- `AssistedQueue.php:153-155` → `liveStrategies($ids)` → `chainStates()`/`verifyRow()` hace 2 consultas por estrategia de la vista (`OutreachPublishedStrategies.php:508-509`), más una a `StudioVersion` por cada versión con referencia de tipo `version`. Para una estrategia son unas 9 consultas; para K, unas 7 + 2K. Esto ya lo paga el planificador cada minuto con todas las estrategias. También se paga en cada `claim()`, para una estrategia.
- `personSelection()` (`AssistedQueue.php:~385`) pide la política. Por cada tanda con al menos una persona resuelta, eso dispara `LeadFacts::optedOutPhones()` (`LeadFacts.php:279`), que lee **todos** los `marketing_leads` con `do_not_contact = true`. Son como mucho 4 lecturas por página (`MAX_SCAN / SCAN_BATCH`), y ahora el CRM relee al volver a la pestaña, hasta una vez cada 15 s por pestaña abierta.
- Escenario: con 20 000 prospectos «no contactar», una página que recorre 200 filas lee 4 × 20 000 filas. No sé cuántos hay en producción [S].
- Propuesta (no para hoy): medir el número de prospectos «no contactar» en producción. Si es grande, memorizar `optedOutPhones()` dentro de una misma petición o de un mismo `BatchSnapshotBuilder`, o filtrarlo por los números de la tanda. Hoy la automatización está en sombra y no hay asistidos en vivo, así que no frena el despliegue.

## Notas (sin hallazgo)

- **[L]** Hay una carrera de milisegundos: si entre `viewParts()` y `queueRows()` aparece un trabajo de una (versión, parte) nueva, sin frenos ni partes paradas en la vista, ese trabajo ni se ofrece ni se cuenta en `withheld` en esa respuesta. Aparece en la siguiente lectura. Inocuo.
- **[L]** m-2: si `openScopes()` falla DESPUÉS del `UPDATE`, la reclamación queda escrita y la respuesta es un 500. La repetición pasa por la rama `replayed` y vuelve a mirar todo. Falla cerrado; aceptable.
- **[L]** Anterior a este commit, no es de esta ronda: `isSuppressed()` tiene `limit(200)` sin `ORDER BY`. Si una persona tuviera más de 200 filas supresoras que no la suprimen para la pregunta, podría quedarse fuera justo la que sí. Es irreal hoy. Si se quiere cerrar, se ordena o se quita el tope, como ya hace `areSuppressed()`.
- **[L]** Anterior a este commit, en el CRM: la señal `filtro` del componente vuelve a `'all'` cada vez que se crea el componente, pero `bandejaFiltro` de la sesión, que vive en `commercial-automation-page`, conserva el último filtro. Si alguien elige «Mías», cambia de pestaña y vuelve, el botón marca «Todos» y la lista es la de «Mías». La relectura de esta ronda mantiene la lista coherente con lo que se ve, pero no arregla el botón.
- **No verificado:** la batería completa sobre `298b855a`, que corre el lead; nada en PostgreSQL por mi parte; la seguridad de N-1 y N-2, que es de `security`; ni el efecto real del menor m-1 con datos de producción.

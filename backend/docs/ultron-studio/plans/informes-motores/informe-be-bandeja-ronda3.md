# Informe de backend · La bandeja asistida, tercera ronda (N-1, N-2, m-1, m-2, m-3, m-8)

**12-11-2026 · `backend` · rama `feat/ultron-studio-h9`, base `306b83e3` (sin commits nuevos).**
- **Especificación:** `informe-security-bandeja-condiciones.md` (N-1, N-2, m-1, m-2, m-3, m-8 y §7).
- **Estado de este documento:** las secciones 1 (para el CRM) y 2 (decisiones) se escribieron ANTES de terminar las pruebas, para que el agente del CRM pudiera empezar. Después cambiaron en dos puntos, los dos ya incluidos arriba:
  - el texto de `opted_out` cubre también la palabra de baja POSIBLE;
  - un consentimiento denegado DESCONOCIDO es `policy_unknown`, no `opted_out` (D-R3-1).

  La forma del contrato para el CRM (valores, orden, `meta`, códigos) no ha cambiado desde la primera versión.
- **Qué he tocado:** 4 ficheros de `app/` y pruebas. No he tocado: commits, push, despliegue, producción, migraciones, el contrato, el CRM, `VoiceCallPolicy.php`, `OutreachSendGate.php`, `PolicyLadder.php`, `CoreProjection.php`, `app/Services/Commercial/` ni `tests/Feature/Voice/`.

Marcas: **[C]** comprobado ejecutando · **[L]** leído en el código · **[S]** supuesto.

## Veredicto

**Hecho y verificado [C].** N-1, N-2, m-1, m-2, m-3 y m-8, con la prioridad única de siete motivos. Todo en rojo con `306b83e3` y en verde con el arreglo, en SQLite y en PostgreSQL 14. Las guardas, las vecinas y pint siguen en verde. 24 de 26 mutaciones muertas en los dos motores, también las tres del revisor (m-R1 y m-R2); las 2 vivas son equivalentes.

**Para el propietario o el lead:**
1. el coste: la página normal pasa de 13 a ≈ 35 consultas, y la peor, de 21 a 77 (≈ 67 ms en local) (§5);
2. la palabra de baja POSIBLE ahora retiene el contacto en la bandeja hasta que alguien la resuelva (D-R3-1);
3. la exclusión de voz se añadió a `opted_out` aunque no estaba en la lista de security (D-R3-1);
4. D-3 sigue pendiente, como pidió el encargo;
5. la enmienda del contrato es una propuesta (§7).

---

## 1 · Para el CRM (el contrato que cambia)

Todo es **aditivo** salvo dos cambios de comportamiento que el CRM ya sabe tratar (un 409 que antes era 200, y una fila que deja de salir en `all`).

### 1.1 · `contactWithheld`: la lista final, en orden de prioridad (una fila lleva uno solo, o `null`)

| # | Valor | Nuevo | Cuándo |
|---|---|---|---|
| 1 | `brake` | — | La PARADA GLOBAL, el freno del canal `assisted` o el de la estrategia (`outreach_brakes`). |
| 2 | `strategy_stopped` | **sí** | La estrategia del trabajo ya no está en marcha en la cadena: pausada, apagada, revertida o retirada (su versión ya no está viva), archivada, o la cadena no se puede verificar (ilegible = retenido). |
| 3 | `opted_out` | **sí** | Se SABE una señal de «no contactar» que la escalera niega hoy (N1/N2): `do_not_contact` de la persona o de un prospecto con su mismo número, el *opt-out* comercial (solo en finalidad comercial), el consentimiento denegado, la exclusión de voz (solo trabajos por voz: el «no volver a llamar» o el RNE) o la supresión del libro del H9 (un `opted_out` registrado, una palabra de baja segura o POSIBLE que nadie ha resuelto aún —SC-2—, o `wrong_person` por ese medio). |
| 4 | `complaint_recent` | — | Queja dentro de la ventana (N6). |
| 5 | `policy_unknown` | **sí** | No se puede comprobar lo del punto 3: la persona no se resuelve (ficha borrada o fusionada), la clave ya no es de una persona (la supresión 1581, `x:<id>`) o falta alguno de esos datos. Falla cerrado. |
| 6 | `complaint_unknown` | — | Sin datos de eventos para saber si se quejó (N6, falla cerrado). |
| 7 | `expired` | — | El plazo del trabajo pasó. |

En TypeScript: `'brake' | 'strategy_stopped' | 'opted_out' | 'complaint_recent' | 'policy_unknown' | 'complaint_unknown' | 'expired'`, y la constante en ese orden. Un valor desconocido sigue contando como retenido (ya lo hace el CRM).

Igual que hasta ahora:
- con un motivo, `person` y `script` van en `null`;
- el motivo solo lo ve quien reclamó; para otra persona, `contactWithheld: null` siempre (no se le cuenta, por ejemplo, que esa persona pidió que no la contacten);
- un trabajo SIN reclamar con cualquier motivo no se ofrece, y reclamarlo da **409 `not_in_queue`**;
- registrar el resultado (`POST …/outcome`) no cambia: quien reclamó lo registra aunque el contacto esté retenido.

**Nuevo en la respuesta de reclamar (m-2):** un `201` (reclamación nueva) puede llegar ya con `contactWithheld: 'brake'` y sin `person` ni `script`, si alguien echó un freno entre la comprobación y la escritura. El trabajo queda reclamado por quien lo pidió (puede registrar el resultado). El CRM no tiene que hacer nada especial: es la misma forma de fila retenida.

### 1.2 · `meta` de `GET outreach/assisted/queue`

```json
{ "ok": true, "data": [ … ], "meta": { "withheld": 3, "scanCapped": false } }
```

- **`withheld`** (entero ≥ 0, sin cambio de forma): cuántos trabajos **sin reclamar y no vencidos** de esa vista no se ofrecen ahora. Cuenta:
  - de forma **exacta**, los frenados (`brake`) y los de estrategia parada (`strategy_stopped`): un recuento en SQL;
  - como **cota inferior**, los retenidos por la persona (`opted_out`, `complaint_recent`, `policy_unknown`, `complaint_unknown`) que se encontraron al llenar la página.

  No cuenta los vencidos, ni nada reclamado. En `mine` es siempre 0. Sigue siendo «al menos N».
- **`scanCapped`** (booleano, **nuevo**, siempre presente): `true` si la lectura llegó a su tope (`MAX_SCAN` = 200 filas) sin llenar la página. Quiere decir «puede haber más trabajo ofrecible detrás de los retenidos». `false` en cualquier otro caso (también con la página llena: entonces no se sabe si hay más, como hasta ahora).

La respuesta de reclamar no cambia su `meta` (`{replayed}`).

### 1.3 · Códigos de error

- **Ninguno nuevo.**
- **Cambia (m-1):** la REPETICIÓN de `POST …/claim` por quien ya reclamó, con la inscripción cerrada (fuera de `active`, `waiting`, `handed_off`), daba `200` con `person` y el guion. Ahora da **409 `enrollment_closed`** («La inscripción de ese trabajo ya se cerró.»), el mismo código que ya daban la primera reclamación y el resultado.

### 1.4 · Cambio de comportamiento de la vista `all` (m-8)

En `all` ya **no salen** los trabajos reclamados por OTRA persona cuyo plazo pasó (siguen en la vista `mine` de quien los reclamó). Los reclamados por otra persona y aún en plazo siguen saliendo, como antes. `unclaimed` y `mine` no cambian.

### 1.5 · Textos propuestos (español)

Por el mismo patrón que los de `ASSISTED_WITHHELD_TEXTS` («no la contactes» + «registra el resultado»):

- **`strategy_stopped`:** «La estrategia de este trabajo ya no está en marcha (está pausada, apagada, revertida o archivada, o no se puede verificar): no contactes a esta persona por este trabajo. Si ya hablaste con ella, registra el resultado.»
- **`opted_out`:** «Esta persona pidió (o puede haber pedido) que no la contacten, o lo pidió alguien que comparte su número, o el número no es suyo: no la contactes. Si ya hablaste con ella, registra el resultado.»
  - Nota: la frase corta «Esta persona pidió que no la contacten» no es cierta en todos los casos que cubre el motivo: el hermano con el mismo número, `wrong_person` y la palabra de baja POSIBLE que una persona aún no ha revisado (SC-2). Por eso los incisos.
- **`policy_unknown`:** «No se puede comprobar si esta persona pidió que no la contacten (faltan datos de su ficha): no la contactes. Si ya hablaste con ella, registra el resultado.»
- **`meta.withheld`** (sustituye al actual, que solo nombra el freno y la queja): «Hay al menos N contactos que no se ofrecen ahora: hay un freno abierto, la estrategia no está en marcha, la persona pidió que no la contacten o se quejó, o no se puede comprobar.» (y la forma en singular).
- **`meta.scanCapped`:** «Puede haber más contactos que ofrecer detrás de los retenidos: la bandeja dejó de leer al llegar a su tope. Avisa al responsable de la automatización.»
- **409 `enrollment_closed` al reclamar** (si el CRM le da texto propio): «Este contacto ya se cerró (la persona salió de la estrategia): no la contactes.»

---

## 2 · Decisiones de diseño

**D-R3-1 · La regla de `opted_out` es la entrada de la escalera, no una regla nueva.**
- Se lee la persona con `CoreProjection::policyInput()` (la misma función que alimenta N1/N2 en `LadderInputBuilder::build()`) y se miran sus campos:
  - `optOut.doNotContact`, `optOut.siblingDoNotContact`;
  - `optOut.commercial`, solo con finalidad `commercial` (como N1);
  - `consent[<medio del trabajo>] === 'denied'` (el medio es `j.medium`: `whatsapp` o `voice`). La escalera da por `denied` también un `lead.consent_denied` que NO se sabe (falla cerrado); la bandeja retiene igual, pero ese caso es `policy_unknown`, no `opted_out` (se lee `SubjectFacts::policy('lead.consent_denied')` solo para distinguirlo; con los adaptadores de hoy nunca es nulo para una persona resuelta);
  - `voice.excluded`, solo con medio `voice` (N1 `voice_excluded`: la exclusión manual, el «no volver a llamar» dicho en una llamada y el RNE `listed`). **No estaba en la lista de security**; lo añado porque para un asistido por voz es exactamente el «no me contacten» de ese medio, y está en la misma foto sin consultas de más;
  - la supresión global con la regla de `SubjectSuppressions` (N2 `global_suppression`), sobre la clave canónica, como `LadderInputBuilder`. **Consecuencia que conviene que vea el propietario:** una palabra de baja POSIBLE (SC-2: `human_requested` con el detalle `opt_out_word`) deriva la inscripción a `handed_off` y el trabajo sigue en la bandeja (L9-66), pero ahora con `opted_out`: quien lo tenga no ve el enlace ni el guion hasta que una persona resuelva la baja. Es lo que la escalera ya hace hoy (la niega con `global_suppression` hasta que alguien la resuelva).
- **Fuera, a propósito:** `no_consent_for_purpose_channel` (un consentimiento que FALTA no es una negativa, y `consent.whatsapp.service` cae solo al cerrarse la ventana de 24 h: retendría todo el servicio), `rne_check_missing` (la vigencia de la consulta, no una petición de la persona), `subject_minor`, el presupuesto (el trabajo ya lo consumió), `human_in_control` (para el asistido ya hay una persona) y los frenos ajenos (D-3, decisión del propietario). Si el propietario quiere alguno, es una línea más en la misma función.

**D-R3-2 · `policy_unknown` = no se puede comprobar, y retiene.**
- Clave que no es de una persona (`x:<id>`), persona que la foto no trae o no resuelve (la escalera la niega con `unknown_subject`), medio fuera del conjunto, o alguno de los valores de D-R3-1 en `null`.
- Un `true` sabido gana a un desconocido: si se sabe que pidió no contactar, es `opted_out` aunque falte otro dato.
- Consecuencia: el trabajo de una persona suprimida por la 1581 (`x:<id>`) pasa de `complaint_unknown` a `policy_unknown` (prioridad más alta), también en finalidad de servicio, donde antes no tenía motivo.

**D-R3-3 · La foto: UNA por tanda, la misma de la queja, ampliada con la política.**
- `new FactSelection(facts: [], events: ['complaint_created'], contacts: true, policy: true)`: la queja (como antes) y la política de N1/N2. Sin `global` (los frenos ajenos no se miran, D-3) ni hechos de condición.
- `contacts: true` está solo porque `policyInput()` exige la lista de contactos de una persona resuelta (no la usamos). Es lo que más consultas añade; la alternativa sin ellas es sacar de `policyInput()` una función pública con `consent`/`optOut`/`voice` (tocar `CoreProjection`, que alimenta la escalera). No lo he hecho: prefiero no tocar la entrada de la escalera en esta ronda. El coste medido va en §5.
- `human_requested` sigue fuera (L9-66): la foto solo pide `complaint_created`.

**D-R3-4 · La supresión, en UNA consulta por tanda.**
- `SubjectSuppressions::isSuppressed()` es una consulta por persona: por fila sería N+1.
- Añado `areSuppressed()` (varias preguntas, una consulta) y saco la regla fila a fila a una función privada que usan las dos. `isSuppressed()` conserva su consulta y su resultado.
- El lote no lleva tope de filas: un tope podría dejar fuera justo la fila que suprime (falla abierto). Las filas que lee son solo las que suprimen, de ≤ 50 personas.

**D-R3-5 · La estrategia (N-2, opción a), con `LiveStrategy`.**
- Una (versión, parte) de inscripción está **en marcha** si la cadena la trae viva y `runnable() && ! paused() && chainStatus === 'active'`. Son las tres entradas de N5 (`published`, `paused`, `chainReadable`; `LadderInputBuilder.php:114-116`). `runnable()` incluye `chainReadable()`.
- Si la cadena no la trae (revertida, retirada, archivada, versión que no es de la estrategia): no está en marcha.
- Una lectura por petición: tras los frenos, una consulta con las (estrategia, versión, parte) distintas de la vista, y `liveStrategies()` solo de esas estrategias. Añado a `OutreachPublishedStrategies::liveStrategies()` un filtro opcional por ids; sin argumento lee todas, como hasta ahora.
- Lo SIN reclamar de una parte que no está en marcha se descarta en SQL (lista blanca de las partes en marcha: una parte que aparezca después de la lectura queda fuera, falla cerrado) y se cuenta de forma exacta con los frenados. Lo reclamado lleva `strategy_stopped`.
- El nombre es `strategy_stopped`, no `brake`: no es un freno y el CRM le pone otro texto.

**D-R3-6 · El orden de las lecturas.** Frenos → estrategias → filas → foto → supresión. Los frenos siguen siendo la PRIMERA consulta de la página y se leen antes de decidir y de escribir. Ninguna lectura lleva `catch`: si algo no se puede leer, la excepción sube (falla cerrado, 500 sin detalle).

**D-R3-7 · m-2: solo se vuelven a leer los frenos tras el `UPDATE`.**
- Es lo que pide security (una consulta). Si frena, la respuesta lleva `contactWithheld: 'brake'`; el trabajo queda reclamado, no se deshace nada ni se registra nada.
- **Queda** la misma carrera, de milisegundos, para la pausa de la estrategia, el DNC y la queja que llegan entre la comprobación y el `UPDATE`. Cerrarla costaría repetir la foto y la cadena. El freno es la parada de emergencia; lo demás lo ve la siguiente lectura.

**D-R3-8 · m-1: la repetición mira la inscripción.** En la repetición, antes de devolver nada, la inscripción tiene que seguir en `active`/`waiting`/`handed_off`; si no, 409 `enrollment_closed`. Se usa la misma comprobación (`requireOpenEnrollment()`) que la primera reclamación.

**D-R3-9 · m-3: `scanCapped`.** Es `true` cuando la lectura llega a `MAX_SCAN` filas sin llenar la página. Si justo hay 200 filas y ninguna detrás, también da `true`: el texto dice «puede haber».

**D-R3-10 · m-8: solo en `all`, solo los vencidos de otra persona.** Los reclamados por otra persona aún en plazo siguen en `all`, sin `person` ni motivo, como antes.

**D-R3-11 · El registro.** `outreach.assisted.claim_withheld` (en `warning`) conserva su forma para la queja (`{job, reason: complaint_recent, known}`). Para los demás motivos, también los nuevos, va `{job, reason}`. Sin teléfonos ni nombres. La m-2 no registra nada: es una reclamación que sí se hizo.

---

## 3 · Ficheros y líneas tocados

`git diff --stat` [C]: 6 ficheros modificados (+346 −93) y 2 pruebas nuevas. Sin migraciones, sin `config/*`, sin `routes/*`, sin el contrato. Ningún fichero fuera de la lista del encargo salvo los dos de `app/Services/Outreach/Run/` que explico (cambio mínimo para reutilizar una lectura).

**`app/Services/Outreach/Run/AssistedQueue.php`** (líneas del fichero final):
- `:21-75` docblock de la clase: los siete motivos, el orden de lecturas y D-3.
- `:85-103` las constantes `WITHHELD_STRATEGY_STOPPED`, `WITHHELD_OPTED_OUT`, `WITHHELD_POLICY_UNKNOWN` y `WITHHELD_REASONS` en el orden nuevo.
- `:120-123` `ROW_COLUMNS`: las columnas de la fila, ahora con `e.strategy_version_id as enrollment_version_id` y `e.part as enrollment_part` (para la lista blanca de partes en marcha). Las comparten `queueRows()` y `assistedJob()`.
- `:131` el constructor recibe `SubjectSuppressions`.
- `:159-207` `page()`: frenos → partes de la vista y cadena (una vez) → tandas → `heldCount()`; devuelve `scanCapped` (m-3).
- `:227-264` `claim()`: la repetición exige la inscripción abierta (m-1, `:241-242`); la cadena se lee para la repetición y para la reclamación nueva; tras el `UPDATE`, los frenos otra vez (m-2, `:260-261`).
- `:343-355` `requireNotWithheld()`: recibe las partes en marcha.
- `:370-394` `withholdReasons()`: `brake` → `strategy_stopped` → lo de la persona → `expired`.
- `:411-452` `personReasons()` (sustituye a `withheldByComplaint()`): una foto y una consulta de supresión por tanda; `opted_out` > `complaint_recent` > `policy_unknown` > `complaint_unknown`.
- `:460-463` `personSelection()`: la `FactSelection` de la foto.
- `:465-497` `optedOut()`: la regla de N-1 sobre `CoreProjection::policyInput()`.
- `:509-519` `runningParts()`, `:521` `partKey()`, `:531-541` `inRunningPart()` (la lista blanca en SQL), `:544-547` `viewParts()`.
- `:578-603` `queueRows()`: la lista blanca para lo sin reclamar (N-2) y lo reclamado por otra persona solo en plazo (m-8).
- `:613-633` `heldCount()` (sustituye a `brakedCount()`): frenados o de parte parada, exacto.
- `:635-647` `assistedJob()`: las mismas columnas que la página.

**`app/Http/Controllers/Crm/UltronStudio/UltronStudioOutreachAssistedController.php`** `:43-45`: `meta.scanCapped`.

**`app/Services/Outreach/Run/SubjectSuppressions.php`** (fuera de la bandeja; cambio mínimo, D-R3-4):
- `:50-58` `isSuppressed()`: la MISMA consulta (los mismos `join`, filtros y `limit(200)`), ahora montada por `suppressingRows()`, y la regla en `suppresses()`. Mismo resultado.
- `:69-82` `areSuppressed()` (nuevo): varias preguntas, una consulta, sin tope.
- `:85-91` `suppressingRows()` y `:98-118` `suppresses()`: el bucle de antes, sin cambios.
- Por qué: sin esto, una consulta por fila (N+1) en la página.

**`app/Services/Outreach/Run/OutreachPublishedStrategies.php`** `:60-81` (fuera de la bandeja; cambio mínimo, D-R3-5): `liveStrategies(?array $strategyIds = null)`. Con ids, solo esas estrategias; con `[]`, ninguna consulta; sin argumento, igual que antes (lo usan el planificador, el despachador, el conciliador, el monitor y los modelos de lectura, que no cambian). Por qué: sin el filtro, cada página verificaría la cadena de TODAS las estrategias no archivadas (2 consultas por estrategia en `verifyRow()`).

**Pruebas:**
- `tests/Feature/Outreach/Run/H9AssistedOptOutStrategyTest.php` (nuevo, 20 casos con los proveedores de datos).
- `tests/Unit/Outreach/Run/AssistedQueueOptedOutTest.php` (nuevo, 21 casos: la regla de N-1, pura).
- `tests/Feature/Outreach/Run/H9AssistedConditionsTest.php` y `OutreachApiTest.php`: aserciones cambiadas, una a una en §4.3.

## 4 · Pruebas: rojo antes, verde después

### 4.1 · Las pruebas nuevas y qué cubre cada una

`tests/Feature/Outreach/Run/H9AssistedOptOutStrategyTest.php` (27 casos contando los proveedores de datos; los 5 de m-R1 y m-R2 del revisor, en §4.5), sobre las clases reales; el reloj en UTC (`Carbon::setTestNow(..., 'UTC')`):

| Caso | Punto | Qué fija |
|---|---|---|
| `test_n1_la_senal_de_no_contactar…` × 5 (DNC, consentimiento denegado, *opt-out* comercial por WhatsApp, DNC del hermano con el mismo número, palabra de baja posible en el libro) | N-1 | La señal llega DESPUÉS de salir, por su camino real (`MarketingLead::forceFill()->save()` como `execMarkDoNotContact`, `MarketingAiAction::create`, `OutcomeRecorder::record` como `WhatsappTemplateOutcomes`). Sin reclamar: no se ofrece, 409 `not_in_queue`, `withheld` 1, registro `{job, reason: opted_out}`. El reclamante, en `mine`, en `all` y en la repetición: `[null, sin guion, opted_out]`. Otra persona: motivo `null`. Ningún teléfono. La bandeja no escribe. `recordOutcome()` sigue. |
| `test_n1_la_exclusion_de_voz…` | N-1 | Misma persona, un asistido por voz y otro por WhatsApp (de dos estrategias): la exclusión de voz retiene solo el de voz. |
| `test_n1_sin_poder_comprobarlo…` | N-1 | Persona sin resolver, con la queja apagada por configuración: `policy_unknown` al reclamante y en la repetición; el libre no se ofrece ni se reclama (registro `policy_unknown`). |
| `test_n2_la_estrategia_pausada…` | N-2 | «Pausar» en el Studio (una entrada `pause` firmada; ningún freno; inscripciones `active`): lo libre no se ofrece (409, registro `strategy_stopped`), `withheld` exacto 1 aunque haya OTRA estrategia en marcha con un libre que sí se ofrece; el reclamado y la repetición, `strategy_stopped`; reanudada, todo vuelve. |
| `test_n2_la_estrategia_revertida_retirada_archivada_o_ilegible…` × 4 | N-2 | Revertida (`rollback`), retirada (`retire`, estado `off`), revertida y archivada, firma rota (`signature_mismatch`): igual. |
| `test_n2_tras_la_reversion_y_el_conciliador…` | N-2 + m-1 | Con el conciliador real la inscripción queda `canceled`, la fila sale de `mine` y la repetición da 409 `enrollment_closed`. |
| `test_si_la_cadena_o_la_politica_no_se_pueden_leer…` × 2 | falla cerrado | Con la cadena (`ultron_studio_strategy_publications`) o una tabla de la política (`marketing_ai_actions`) ilegibles: la bandeja, `mine`, reclamar y la repetición lanzan `QueryException` con esa tabla; nada se reclama. |
| `test_un_solo_motivo_por_fila_en_el_orden_de_los_siete` | prioridad | Sobre UN trabajo reclamado se van sumando motivos: `expired` → `complaint_unknown` → `complaint_recent` → `opted_out` → `strategy_stopped` → `brake`; y, con otro sin resolver, `brake` → `strategy_stopped` → `policy_unknown` al soltar el freno y reanudar. Y la constante. |
| `test_m1_la_repeticion…` | m-1 | La repetición con la inscripción cerrada: 409 `enrollment_closed` con cualquier `clientRequestId`; no se deshace la reclamación. |
| `test_m2_un_freno_echado_entre_leer_los_frenos_y_reclamar…` | m-2 | Un oyente echa la PARADA GLOBAL justo después de la lectura de frenos de `claim()`: la respuesta es la fila reclamada (`replayed: false`) con `brake` y sin `person` ni `script`; el trabajo queda reclamado. |
| `test_m3_la_pagina_dice_scan_capped…` | m-3 | Página normal y página llena: `false`. 201 retenidos en cabeza y uno ofrecible detrás: `items` 0, `withheld` 200, `scanCapped` `true` (en `all` y en `unclaimed`); `mine`: `false`. |
| `test_m8_all_no_se_llena…` | m-8 | 20 reclamados por Ana y vencidos, 4 libres detrás: el `all` de Beto (límite 20) tiene los 4 y ninguno de Ana; Ana los conserva en `mine` (con `expired`) y en su `all`. |
| `test_las_lecturas_de_la_pagina_y_de_claim…` | orden y lotes | Página: los frenos son la primera consulta; la cadena antes de las filas; frenos, eventos y supresión, una vez; `max(seq)`, una vez. Reclamar: frenos → cadena → foto → supresión → `UPDATE` → frenos otra vez. |
| `test_por_http_meta_lleva_scan_capped…` | HTTP | `meta` = `{withheld: 0, scanCapped: false}`; DNC → 409 `not_in_queue` y `meta.withheld` 1; la repetición con la inscripción cerrada → 409 `enrollment_closed`, sin `data` ni teléfono. |

`tests/Unit/Outreach/Run/AssistedQueueOptedOutTest.php` (21 casos): la regla de `optedOut()` con fotos hechas a mano. Incluye lo que los adaptadores de hoy no producen (valores ausentes) y el consentimiento desconocido, que es `null` y no `true`.

### 4.2 · Rojo con `306b83e3` [C]

En una copia del árbol (fuera del repositorio) con los cuatro ficheros de `app/` tal como están en `306b83e3` (`git show 306b83e3:… | cmp` igual) y las pruebas finales:

```
H9AssistedOptOutStrategyTest   SQLite  Tests: 22, Assertions: 91, Failures: 22.
AssistedQueueOptedOutTest      SQLite  Tests: 21, Assertions: 0, Errors: 21.   (el método no existe)
H9AssistedConditionsTest       SQLite  Tests: 11, Assertions: 113, Failures: 5. (las 5 pruebas con aserciones cambiadas, §4.3)
OutreachApiTest                SQLite  Tests: 7, Assertions: 111, Failures: 1.  (meta)
```

Antes de escribir el arreglo, la batería nueva (entonces 20 casos) contra el árbol real sin cambios en `app/`: SQLite `Tests: 20, Assertions: 85, Failures: 20.` y PostgreSQL 14 `Tests: 20, Assertions: 85, Failures: 20.` Todos fallos de aserción, ningún error de montaje.

### 4.3 · Aserciones existentes que cambian, una a una

`H9AssistedComplaintTest` no cambia (4/4 en verde sin tocarla).

| Prueba | Antes | Ahora | Por qué |
|---|---|---|---|
| `H9AssistedConditionsTest::test_i3_los_frenos_se_leen_antes_de_decidir_y_de_escribir` | «una sola lectura de frenos» al reclamar, «ninguna lectura después» | dos lecturas: una antes de decidir y escribir (las aserciones de orden siguen) y otra DESPUÉS del `UPDATE` | m-2 pide exactamente releerlos tras el `UPDATE`. |
| `…::test_un_solo_motivo_por_fila_freno_queja_sin_datos_y_vencido_en_ese_orden` | `WITHHELD_REASONS` = 4 motivos | los 7 en el orden nuevo | N-1, N-2 y la prioridad del encargo. |
| `…::test_i4_la_pagina_se_llena_por_detras…` (dos aserciones) | `['items' => [], 'withheld' => 0]` y `… 59]` | más `'scanCapped' => false` | m-3: la clave nueva va siempre. |
| `…::test_i4_la_lectura_de_una_pagina_tiene_tope…` | `withheld` 200; `1 + 2 × 4` = 9 consultas | más `'scanCapped' => true`; `1 + (1 + 9) + 2 × 4` = 19, y una sola lectura de las partes de la vista | m-3, y N-2 lee la cadena UNA vez por página (las 10 consultas, detalladas en el comentario de la prueba). La foto de personas sin resolver no lee la política (0 consultas más por tanda). |
| `…::test_un_trabajo_de_una_persona_suprimida…` | el reclamante ve `complaint_unknown` | `policy_unknown` | La clave `x:<id>` no se lee: tampoco la política, y `policy_unknown` va antes que `complaint_unknown`. |
| `OutreachApiTest::test_la_bandeja_asistida_por_http…` | `meta` = `['withheld' => 0]` | `['withheld' => 0, 'scanCapped' => false]` | m-3. |

### 4.4 · Verde con el arreglo, estado final [C]

```
                                SQLite                               PostgreSQL 14 (ultron_studio_lead_ola5)
H9AssistedOptOutStrategyTest    OK (27 tests, 309 assertions)        OK (27 tests, 309 assertions)
AssistedQueueOptedOutTest       OK (21 tests, 21 assertions)         OK (21 tests, 21 assertions)
H9AssistedConditionsTest        OK (11 tests, 184 assertions)        OK (11 tests, 184 assertions)
H9AssistedComplaintTest         OK (4 tests, 39 assertions)          OK (4 tests, 39 assertions)
OutreachApiTest                 OK (7 tests, 137 assertions)         OK (7 tests, 137 assertions)
```

Vecinas y guardas [C]:
- `tests/Feature/Outreach/Run` entero, estado final:
  - SQLite: `OK, but some tests were skipped! Tests: 149, Assertions: 2169, Skipped: 2.` Las dos saltadas son las carreras que solo corren en PostgreSQL (`OutreachRaceTest`, `H9IdempotencyRaceTest`).
  - PostgreSQL 14: `OK (149 tests, 2262 assertions)`, sin saltadas: las dos carreras también pasan.
  - Las cifras incluyen las 5 pruebas de m-R1 y m-R2. Antes de añadirlas: 144 en los dos motores.
- `tests/Feature/Outreach/Guards`: `OK (19 tests, 12168 assertions)`.
- Las 15 guardas (`*Isolation*`, `*ReadOnly*`, `*StaticGuards*`, `SecretExposure*`): `OK (138 tests, 178728 assertions)`.
- `tests/Unit/Outreach` entero: `OK (1015 tests, 53228 assertions)`.
- Las vecinas de la supresión: `W9/OptOutAcrossMediaTest` `OK (3 tests, 71 assertions)`, `W9/OptOutWordHardeningTest` `OK (7 tests, 1209 assertions)`, `W9/WhatsappTemplateOutcomesTest` `OK (9 tests, 94 assertions)`, `B9Cierre/DetailCodeTextsTest` `OK (3 tests, 15 assertions)`. `V9/V9OpenGateTest` sale entera saltada en SQLite (`Skipped: 26`), por su propia condición, igual que antes.
- `vendor/bin/pint --test` sobre los 8 ficheros: `{"tool":"pint","result":"passed"}`.

### 4.5 · m-R1 y m-R2 del revisor (`informe-reviewer-ola-7.md`) [C]

El revisor aprobó `4a3d7eea` y dejó dos huecos de PRUEBA, no de código. Los cierro en `H9AssistedOptOutStrategyTest`, adaptados a esta ronda: `brakedCount()` es ahora `heldCount()` y cuenta también `strategy_stopped`.

**`test_mr1_withheld_cuenta_solo_lo_sin_reclamar_y_en_plazo…`** (×4: parada global, freno del canal `assisted`, freno de la estrategia y estrategia pausada).
- El montaje: Ana tiene reclamado un trabajo, otro está libre y un tercero libre ya venció.
- Sin retención, `withheld` = 0 en `all` y en `unclaimed`.
- Con la retención, Beto pide `page('all')`:
  - `withheld` es exactamente 1, y también en `unclaimed`;
  - la página tiene solo lo de Ana, sin `person`;
  - ni el libre ni el vencido.

**`test_mr2_con_mas_de_una_tanda_de_ofrecibles…`**: 120 ofrecibles (más de dos tandas). Con `limit` 20 (en `all` y en `unclaimed`), 1 y 50, la página son exactamente las `limit` primeras por `(expires_at, id)`, en orden. `withheld` 0 y `scanCapped` `false`.

**Las mutaciones del revisor, en la copia del árbol:**

| Mutación | SQLite | PG 14 | Cómo falla |
|---|---|---|---|
| (A) `heldCount()` sin `whereNull('j.assisted_claimed_by_admin_id')` | muerta (4 de 4) | muerta (4 de 4) | `Failed asserting that 2 is identical to 1.` en las cuatro variantes, también con la pausa |
| (B) `heldCount()` sin `where('j.expires_at', '>', …)` | muerta (4 de 4) | muerta (4 de 4) | ídem |
| (C) `break` en vez de `break 2` | muerta | muerta | la página trae 22 filas (las 20 primeras y dos más) |

- Restauradas con `cmp` igual.
- La batería entera: SQLite `OK (27 tests, 309 assertions)`; PostgreSQL 14 `OK (27 tests, 309 assertions)`.
- **Con `306b83e3`** (copia «antes»), las tres variantes de m-R1 con freno PASAN: el código ya era correcto, y lo que faltaba era la prueba que lo fijara. Fallan la variante de la pausa (no hay `strategy_stopped`) y m-R2 (no hay `scanCapped`): `Tests: 5, Assertions: 34, Errors: 1, Failures: 1.` Son pruebas para fijar el comportamiento frente a las mutaciones, no de regresión de esta ronda.

## 5 · Consultas por página, antes y después [C]

**Cómo se midió:**
- con una prueba de medición FUERA del repositorio (`DB::listen` y `hrtime`);
- en dos copias del árbol: «antes», con los cuatro ficheros de `app/` de `306b83e3`, y «después», con los actuales;
- el mismo mundo en las dos: 3 trabajos salidos por el planificador y el despachador reales, uno reclamado por Ana, y para el peor caso 201 personas RESUELTAS (con prospecto y conversación) con queja reciente en cabeza;
- los tiempos de PostgreSQL 14 son de la base local desechable, sin otra carga.

| Petición | Consultas antes | Consultas después | PG 14 antes | PG 14 después |
|---|---|---|---|---|
| A · página `all` (3 filas, 1 de Ana) | 13 | 33 | 5,6 ms | 14,9 ms |
| A · página `mine` (1 fila) | 9 | 29 | 3,7 ms | 12,8 ms |
| A · página `unclaimed` (2 filas) | 10 | 30 | 4,6 ms | 13,0 ms |
| A · `claim` nuevo | 12 | 32 | 5,5 ms | 10,0 ms |
| A · `claim` repetición | 9 | 29 | 3,6 ms | 8,2 ms |
| B · página `all`, 201 quejas en cabeza (4 tandas) | 21 | 77 | 26,9 ms | 67,2 ms |

**De dónde salen** (desglose por tabla de la página A, en SQLite; las cifras de consultas son iguales en los dos motores):
- **La cadena, UNA vez por petición, ≈ 10:**
  - las partes de la vista: 1;
  - `liveStrategies()` de sus estrategias: 7 por una estrategia (la estrategia, `max(seq)`, la entrada, la anterior, la estrategia y la persona de `verifyRow()`, las versiones), y 2 más por cada estrategia adicional;
  - los 2 dominios del catálogo (`plans`, `classes`) con que se relee el contenido. En la medición ya estaban en la caché de la instancia; en una petición real se suman, así que la página A real son **≈ 35** y el `claim` nuevo **≈ 34**.
- **La política en la foto, ≈ 10 POR TANDA** (solo si alguien de la tanda se resuelve):
  - `marketing_leads` +2 (las filas y todos los `do_not_contact` con teléfono, la regla del hermano);
  - `marketing_conversations`, `marketing_followups` y `marketing_ai_actions`;
  - `voice_contacts` ×2 (la política y el historial), `voice_consents`/`voice_exclusions`/`voice_rne_checks` (si hay contactos de voz) y `ultron_studio_canary_numbers`;
  - el historial de contactos: `marketing_messages` y `outreach_outcomes`. Estas ≈ 3 consultas por tanda están SOLO porque `policyInput()` exige los contactos (D-R3-3).
- **La supresión: 1 por tanda.**
- **Los frenos tras el `UPDATE` (m-2): 1 en `claim`.**
- **Ninguna consulta por fila nueva.** Las 2 por fila de `dto()` (`reasonOf()` y `contentOfVersion()`) son de antes (H-C).

**Lectura:**
- el coste es fijo por página y por tanda, no por fila;
- la peor página son 77 consultas y ≈ 67 ms en local, con 120 peticiones/min por credencial;
- si en producción pesa, hay dos palancas sin tocar la regla:
  1. sacar `consent`/`optOut`/`voice` de `policyInput()` a una función pública y no pedir los contactos (−3 consultas por tanda; toca `CoreProjection`, que alimenta la escalera);
  2. leer la cadena de todas las estrategias de una vez por proceso.

  Ninguna de las dos la he hecho: nadie ha medido lentitud y no es mi decisión (sería de `performance-specialist`, con datos de producción).

## 6 · Mutaciones [C]

- **Dónde:** en una COPIA del árbol en el directorio temporal de la sesión, con un autoload antepuesto para que `App\` y `Tests\` se carguen de la copia (comprobado con `ReflectionClass::getFileName()`). El fichero real no se ha mutado nunca.
- **Cómo:** cada mutación exige que su texto aparezca exactamente una vez. Después corre las pruebas que la deben matar, restaura el fichero desde el árbol real y lo compara byte a byte (`restaurada=True` en las 23). Al final: `cmp` real/copia igual, y en el repositorio ni `.mutbak` ni restos (`git status` solo enseña mis ficheros).

| Mutación (en `AssistedQueue.php`) | SQLite | PG 14 | La mata |
|---|---|---|---|
| N1 · sin `do_not_contact` | muerta | muerta | `senal` (DNC) |
| N1 · sin el hermano | muerta | muerta | `senal` (hermano) |
| N1 · sin el *opt-out* comercial | muerta | muerta | `senal` (*opt-out*) |
| N1 · sin el consentimiento denegado | muerta | muerta | `senal` (consentimiento) |
| N1 · consentimiento desconocido como sabido (`true`) | muerta | muerta | unitaria |
| N1 · sin la exclusión de voz | muerta | muerta | `voz` |
| N1 · sin la supresión del libro | muerta | muerta | `senal` (palabra de baja) |
| N1 · persona sin resolver como «se sabe que no» | muerta | muerta | `policy_unknown`, `siete` |
| N1 · un valor nulo como «se sabe que no» | muerta | muerta | unitaria |
| N2 · sin la comprobación en PHP | muerta | muerta | `n2`, `siete` |
| N2 · sin la lista blanca en SQL | muerta | muerta | `n2` (`withheld` cuenta doble) |
| N2 · sin las partes paradas en el recuento | muerta | muerta | `n2` (la otra estrategia en marcha) |
| N2 · sin `runnable()` | muerta | muerta | `n2` (firma rota) |
| N2 · sin `paused()` ni `active` | muerta | muerta | `n2`, `siete` |
| N2 · solo sin `paused()` | **viva (equivalente)** | **viva (equivalente)** | — |
| N2 · solo sin `active` | **viva (equivalente)** | **viva (equivalente)** | — |
| prioridad · estrategia antes que freno | muerta | muerta | `siete` |
| prioridad · queja antes que `opted_out` | muerta | muerta | `siete` |
| prioridad · `complaint_unknown` antes que `policy_unknown` | muerta | muerta | `H9AssistedConditionsTest` (`suprimida`) |
| m-1 · la repetición sin mirar la inscripción | muerta | muerta | `m1`, `conciliador`, `http` |
| m-2 · sin releer los frenos | muerta | muerta | `m2` |
| m-3 · `scanCapped` siempre `false` | muerta | muerta | `m3` |
| m-8 · lo ajeno vencido otra vez en `all` | muerta | muerta | `m8` |

**21 de 23 muertas en los dos motores; las 2 vivas son equivalentes.** Más las tres del revisor (§4.5): **24 de 26**.
- **Por qué son equivalentes:** la cadena solo admite el estado `off` con las dos partes vacías (`StrategyChainSignature.php:297`), así que una parte viva está en `active` o en `paused`, y `! paused()` y `chainStatus === 'active'` dicen lo mismo. Las dejo las dos porque son exactamente las dos entradas de N5 (`published` y `paused`).
- **Un falso positivo de PostgreSQL:** en la primera tanda, «solo sin `paused()`» salió muerta por 6 *errores*, no fallos. Repetida sola: `OK (7 tests, 66 assertions)`, es decir, viva como en SQLite. Fue otro proceso usando la misma base, como avisaba el encargo.
- **Dos mutaciones no se aplicaron en la primera tanda** (`N1-dnc`, `N1-hermano`): había cambiado la forma de la línea. Las repetí con el texto nuevo y murieron en los dos motores.

**Dos huecos que encontraron las mutaciones, ya cerrados:**
1. «Sin las partes paradas en el recuento» sobrevivía: en la prueba de la pausa, todo lo libre estaba parado y el recuento coincidía por casualidad. Ahora hay una segunda estrategia en marcha con un libre.
2. «Un valor nulo como sabido» sobrevivía: es inalcanzable con los adaptadores de hoy. Al escribir su prueba unitaria apareció un detalle real. La escalera da por `denied` un `lead.consent_denied` desconocido, y la bandeja lo habría dicho como `opted_out` («pidió que no la contacten») sin saberlo. Ahora es `policy_unknown` (D-R3-1).

## 7 · Propuesta de enmienda del contrato (no la he aplicado)

Mientras trabajaba se aplicó L9-117 (la ronda anterior) [L: `grep '^| L9-11' ULTRON_STUDIO_H9_CONTRACT.md`]. Propongo L9-118 encima. Los documentos del contrato aparecen modificados en el árbol, pero no por mí.

**D9-9.5, en el bloque «La bandeja retiene también por los frenos y por el plazo» (L9-117):**
- en el título, añadir «, por la persona y por la estrategia» y «N-1, N-2 y m-1 a m-8 de `informe-security-bandeja-condiciones.md`, L9-118»;
- sustituir la lista numerada por esta:
> 1. `brake`: la PARADA GLOBAL, el freno del canal `assisted` (también `legal_hold`) o el de la estrategia del trabajo, con la lectura de N2 (`OutreachStop::openScopes()`).
> 2. `strategy_stopped`: la (versión, parte) de su inscripción no está en marcha en la cadena: pausada, apagada, revertida, retirada, archivada o ilegible. Usa las entradas de N5 (`LiveStrategy::runnable()`, `paused()`, estado `active`), leídas una vez por petición.
> 3. `opted_out`: se SABE una señal de «no contactar» de N1/N2, con la entrada de la escalera (`CoreProjection::policyInput()`):
>    - `do_not_contact`, suyo o de un prospecto con su número;
>    - el *opt-out* comercial, con finalidad comercial;
>    - el consentimiento del medio denegado;
>    - la exclusión de voz, en un trabajo por voz;
>    - la supresión del libro (`SubjectSuppressions`), también la palabra de baja POSIBLE sin resolver (SC-2).
> 4. `complaint_recent`: N6, una queja dentro de la ventana.
> 5. `policy_unknown`: lo del punto 3 no se puede comprobar: persona sin resolver, la clave suprimida `x:<id>` o un valor desconocido.
> 6. `complaint_unknown`: N6 sin datos de eventos.
> 7. `expired`: `expires_at <= ahora`, el borde del despachador. La política se evaluó para ese plazo: pasado, no se contacta con esa decisión.
>
> Si los frenos, la cadena, la foto o el libro no se pueden leer, la bandeja y la reclamación fallan cerrado. Los frenos ajenos al H9 no se miran aquí (D9-14, D-3).
- en el párrafo de la reclamación, añadir:
> La respuesta de una reclamación NUEVA también lleva `contactWithheld: brake` si un freno se echó entre la comprobación y la escritura (se releen tras el `UPDATE`). La REPETICIÓN de E12 con la inscripción cerrada responde 409 `enrollment_closed`.
- el párrafo de la página queda así:
> La página se llena por detrás de lo retenido. Lo vencido, frenado o de una parte que no está en marcha, sin reclamar, se descarta en SQL. Lo de la persona va por tandas de 50, con una foto y una consulta de supresión por tanda, y un tope de 200 filas leídas. `meta.withheld` dice cuántos sin reclamar y no vencidos no se ofrecen: frenados y de estrategia parada, de forma exacta; lo de la persona, como cota inferior. `meta.scanCapped` dice si la lectura llegó al tope sin llenar la página. En `all` no sale lo reclamado por OTRA persona y ya vencido.

**D9-14, añadir tras «PARADA GLOBAL · Efecto» (si el propietario decide D-3 como recomienda security):**
> - En la bandeja asistida, la PARADA GLOBAL, el freno del canal `assisted` y el de la estrategia retienen lo que ya salió (`contactWithheld: brake`). **Pausar la estrategia en el Studio también** (`strategy_stopped`, L9-118). El cerrojo de ULTRON, la pausa del agente y el freno de voz protegen sistemas automáticos y NO retienen la bandeja: para parar a las personas, la PARADA GLOBAL o el freno del canal `assisted` (D-3).

**§7, E11 y E12:**
> | E11 | `GET assisted/queue` [O] | `commercial_automation.assisted_work` | Sin teléfonos; con un enlace a la ficha; sin los trabajos sin reclamar retenidos (los siete motivos de D9-9.5; L9-116 a L9-118); `contactWithheld` en cada fila; `meta.withheld` y `meta.scanCapped` (aditivos); en `all`, sin lo reclamado por otra persona y ya vencido |
> | E12 | `POST assisted/{jobUuid}/claim` [O] | Ídem | Una vez; 409 `not_in_queue` con cualquier motivo de retención; 409 `enrollment_closed` también en la repetición (L9-118); la respuesta lleva `contactWithheld` |

**L9-118, en el registro:**
> | L9-118 | backend (be-bandeja-ronda3) | Las condiciones N-1 y N-2 de security para abrir el asistido en vivo y los menores m-1, m-2, m-3 y m-8 (`informe-security-bandeja-condiciones.md`) | `AssistedQueue` añade tres motivos y queda con siete, por este orden: `brake`, `strategy_stopped`, `opted_out`, `complaint_recent`, `policy_unknown`, `complaint_unknown`, `expired`. N-1 con `CoreProjection::policyInput()` en la MISMA foto que la queja (ampliada con la política) y la supresión con `SubjectSuppressions::areSuppressed()` (nuevo: la regla de `isSuppressed()`, una consulta por tanda). N-2 con `liveStrategies()` (nuevo filtro por ids) una vez por petición; lo sin reclamar de una parte parada se descarta en SQL y se cuenta exacto en `meta.withheld`. m-1: la repetición exige la inscripción abierta (409 `enrollment_closed`). m-2: los frenos se releen tras el `UPDATE`. m-3: `meta.scanCapped`. m-8: `all` sin lo reclamado por otra persona y vencido. Sin escrituras ni migraciones. Consultas: la página normal pasa de 13 a ≈ 35 y la peor (4 tandas) de 21 a 77. Pruebas: `H9AssistedOptOutStrategyTest` (27, con m-R1 y m-R2 del revisor) y `AssistedQueueOptedOutTest` (21), rojo con `306b83e3` y verde en SQLite y en PG 14; aserciones cambiadas en 5 pruebas de `H9AssistedConditionsTest` y 1 de `OutreachApiTest`; 26 mutaciones en los dos motores, 24 muertas y 2 equivalentes |

## 8 · Lo que no he podido comprobar

- **Producción:** nada. Ni cuántos trabajos asistidos hay en vivo, ni cuántas estrategias no archivadas, ni cuántos prospectos con `do_not_contact` hay (la foto con política lee TODOS los `marketing_leads` con `do_not_contact = true` y teléfono en cada tanda, para la regla del hermano, como el despachador: con muchos, la página pesa más) [S].
- **El CRM:** no lo he tocado ni ejecutado. Los textos de §1.5 son propuestas.
- **La carrera m-2 con dos conexiones reales:** la simulo en una sola conexión con un oyente que echa la PARADA GLOBAL justo después de la lectura de frenos de `claim()`, como hizo security. La carrera equivalente para la pausa, el DNC y la queja (D-R3-7) queda abierta a propósito y sin prueba.
- **`assisted:voice` de extremo a extremo:** la prueba de la exclusión de voz siembra el trabajo por las filas del esquema, no por el planificador y el despachador reales (hoy no hay una estrategia asistida por voz en las pruebas de B9-run).
- **La batería completa:** no la he lanzado, por indicación del encargo.
- **La rama `null` de `optedOut()` con los adaptadores reales:** `LeadFacts` y `VoiceConsentFacts` dan siempre booleanos a una persona resuelta, así que hoy solo se alcanza con una foto hecha a mano (la prueba unitaria). Es defensiva.

## 9 · Casos límite para `tester`

- Una persona con DOS trabajos asistidos en dos estrategias, uno por WhatsApp y otro por voz, con la exclusión de voz: solo el de voz se retiene (cubierto en SQLite y PG; conviene por HTTP).
- La palabra de baja POSIBLE (SC-2) con la inscripción derivada: el trabajo sigue en la bandeja, con `opted_out`. Cuando una persona la resuelve marcando «no contactar», sigue `opted_out` (por `do_not_contact`); hoy no hay forma de «descartarla» (D-3 del informe sec-fix-baja).
- La pausa y la reanudación con un trabajo reclamado: el contacto vuelve al reanudar (cubierto). La reversión SIN el conciliador: `strategy_stopped`; CON el conciliador: la fila sale y la repetición da 409 `enrollment_closed` (cubierto).
- Dos estrategias en la misma página, una pausada: `meta.withheld` cuenta solo la pausada (cubierto) y la otra se ofrece.
- `scanCapped` con exactamente 200 filas y ninguna detrás: da `true` (falso positivo aceptado, D-R3-9).
- La vista `all` de quien reclamó: sus vencidos SÍ salen (con `expired`); los de otra persona, no (cubierto).
- Un freno echado entre la lectura y el `UPDATE` (cubierto, en una conexión); con dos procesos de verdad, sin probar.

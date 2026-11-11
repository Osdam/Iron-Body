# Revisión de seguridad · I-1: en sombra, «solo canario» avisa en lugar de negar

Autor: `security`. Fecha: 2026-11-11.
Árbol revisado: `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h9`, HEAD `72f7980f`, cambios **sin commit**.
Alcance: solo el cambio I-1 (`PolicyLadder.php`, `sec_ladder.py`, `policy-ladder-cases.json`, `README.md` del fixture, el tramo `:570-575` de `OutreachIsolationTest.php` y las dos pruebas nuevas). No he mirado el resto de cambios del árbol, que son de otros agentes.

Ha sido una revisión de **solo lectura**:
- no he editado código, pruebas ni fixtures;
- no he tocado producción ni la red;
- las comprobaciones propias están en el scratchpad de la sesión, fuera del árbol.

---

## 0. Veredicto

**APROBADO CON CONDICIONES.** No hay hallazgos críticos, altos ni medios.

- **Condición C1 (antes de desplegar):** confirmar en producción, con una consulta de solo lectura, que no hay decisiones de sombra de antes del cambio (§3).
- El resto son recomendaciones. Ninguna bloquea.

**Respuesta corta a la pregunta 1:** no. Este cambio no puede hacer que salga un mensaje, una llamada ni un trabajo asistido real hacia una persona que no sea canaria.
- Un `allow` obtenido en sombra **nunca se reutiliza** en vivo.
- Cada etapa vuelve a calcular la escalera con el modo actual o con el del trabajo.
- Hay tres barreras independientes (§1.1). Lo he comprobado leyendo el código y con dos escenarios ejecutados (§1.2).

---

## 1. ¿Puede salir algo real hacia alguien que no es canario? — **NO**

### 1.1 El camino completo

**1. Plan** (`OutreachPlanner::planDue`):
- `$modo = OutreachConfig::mode()` (`OutreachPlanner.php:327`).
- Solo coge inscripciones de **ese** modo: `dueEnrollments($modo)` filtra por `e.mode` (`OutreachStore.php:136`).
- La escalera se calcula con ese modo (`:465-466`, `LadderInputBuilder.php:89`).
- En sombra, el trabajo nace con el canal fijo `simulated` (`OutreachPlanner.php:577`) y sin reserva (`:588`).

**2. Reclamación** (`OutreachDispatcher::claim`):
- Solo reclama `j.mode = modo actual` (`OutreachDispatcher.php:174`).

**3. Proceso** (`OutreachDispatcher::process`):
- Usa el modo **del trabajo** (`:220`).
- Si el modo de configuración ya no coincide con el del trabajo, lo cancela con `mode_off` y la inscripción espera (`:224-225`).
- Después repite la escalera entera con el modo del trabajo (`:306-309`).
- Por último ejecuta el adaptador del **canal guardado en el trabajo** (`:327`). Para un trabajo de sombra, ese canal es siempre `SimulatedChannel`, que no hace nada.

**4. Canales reales** (la red de seguridad, que no depende de I-1):
- `WhatsappTemplateChannel.php:120-124` y `VoiceStrategyChannel.php:81-85` exigen `job.mode === live` y `OutreachConfig::isLive()`.
- `OutreachSendGate::SENDS_ENABLED_IN_CODE = false` (`OutreachSendGate.php:39`).
- `AssistedChannel.php:35` exige `isLive()`.
- La bandeja asistida solo enseña y deja reclamar trabajos `channel = assisted` y `mode = live` (`AssistedQueue.php:64`, `:196`).

**Las tres barreras de un trabajo de sombra:**
1. No se reclama en vivo.
2. Si ya estaba reclamado, se cancela al procesarlo.
3. Aunque ambas fallaran, su canal es `simulated` y la escalera se evaluaría con `mode = shadow` del propio trabajo.

**Inscripciones de sombra:**
- Las inscripciones de sombra que siguen abiertas al pasar a vivo no se planifican en vivo, porque `dueEnrollments` filtra por modo.
- Los índices únicos son por modo: `oenr_one_open` y `ojob_one_live` van sobre `(…, mode)` (migración `2026_11_12_100002…:436-437`).
- Vivo crea **su propia** inscripción, y N3 la niega.

**Ni caché ni evidencia de promoción:**
- No hay caché de decisiones: no hay ningún `Cache::` ni `remember(` en `app/Services/Outreach`.
- La evidencia de promoción del canario (`StudioStrategyEvidence.php:112-120`) cuenta solo `mode = live`, `part = canary` y `channel <> simulated`. Los `allow` de sombra no cuentan como evidencia.

### 1.2 Comprobado ejecutando

Hice una prueba propia, solo en el scratchpad:
`/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body-backend/a45051e2-9ace-49d3-9b11-81d0108198fe/scratchpad/probe/SecI1ModeSwitchProbeTest.php`

Usa las clases reales, la cadena firmada de `RunWorld` y un socio que no es canario. Resultado: **OK, 2 pruebas, 16 aserciones**.

- **A · Trabajo planificado en sombra y después se pasa a `live`.**
  - El despacho da `claimed 0`.
  - `planDue` en vivo da `due 0`: la inscripción de sombra no entra.
  - El barrido en vivo crea una inscripción `live` y la niega en N3 con `canary_only_declared`.
  - Quedan 0 trabajos `live` y 0 `marketing_messages`.
- **B · Trabajo reclamado en sombra (por la cola, sin procesar) y procesado tras pasar a `live`.**
  - `process()` da `canceled`, con `cancel_reason = mode_off`; el canal sigue siendo `simulated`.
  - Quedan 0 `marketing_messages` y 0 trabajos `live`.
  - `Http::preventStrayRequests()` estaba activo y no hubo ninguna petición.

---

## 2. ¿De dónde sale «sombra»? ¿Falla cerrado?

**Origen:**
- Sale de la variable de entorno `COMMERCIAL_AUTOMATION_MODE` (`config/outreach.php:60,69`). La normaliza el mismo fichero (`strtolower`, `trim`, lista cerrada; cualquier otra cosa es `off`), y otra vez `OutreachConfig::mode()` (`OutreachConfig.php:36-41`).
- Solo la escribe el mando de operaciones (`ops/h9-outreach-scope.sh`).
- No hay ninguna ruta HTTP ni ninguna tabla que la cambie, y no cambia a mitad de una pasada.

**Dentro de la escalera** (`PolicyLadder.php:172-173`): `$modo = in_array(mode, ['shadow','live'], true) ? mode : 'off'`, y `$sombra = $modo === 'shadow'`. Lo comprobé con la clase real y `canaryOnly = true`, con la persona fuera:

| `flags.mode` | Resultado |
|---|---|
| `"shadow"` | `allow`, con el aviso N3 |
| `"live"` | `deny N3 [canary_only_declared]` |
| `"SHADOW"`, `"Shadow"`, `" shadow"`, `"shadow "`, `"sombra"`, `""`, `"shadow\0"`, `"canary"`, `"off"` | `deny N2 [mode_off]` |
| `null`, `1`, `true`, `["shadow"]` | `InvalidArgumentException`: no hay decisión, la pasada falla |

Falla cerrado.

**¿El asistido corre con `mode = shadow`?**
- Solo como **evaluación**: en sombra, la escalera se calcula para los canales del paso, `assisted:*` incluidos.
- El trabajo, en cambio, nace siempre `simulated` (`OutreachPlanner.php:577`).
- Nunca existe un trabajo `assisted` en sombra, y la bandeja exige `mode = live`.
- En vivo, el asistido niega igual que antes. Lo cubren el caso nuevo de `n3` del fixture (asistido de voz con alcance `null`), `test_fuera_de_la_sombra_niega_en_n3_exactamente_como_antes` (la salida **entera**, en los 4 canales) y `test_en_vivo_el_asistido_sigue_negando_en_n3_a_quien_no_es_canario`.

---

## 3. Rastro auditable y datos personales

**El rastro:**
- La decisión `plan` guarda en `trace_json[0].policy.<canal>`:
  - la entrada: `studio.canaryOnly`, `strategy.inCanaryScope` y `strategy.part`;
  - la salida, con `warnings: [{level: N3, reason: canary_only_declared}]` y, si es la parte canaria, también N5 `canary_scope_mismatch`.
- Lo comprueba `H9ShadowCanaryOnlyTest` y lo he comprobado en la guarda de aislamiento.
- `outreach_decisions` es de solo inserción y tiene su `trace_hash`.
- El texto de la traza lo pinta como «Aviso: La versión comercial limita la estrategia a su alcance canario.» (`DecisionTraceText.php:125`, `:329-330`).
- Queda constancia de que esa persona estaba fuera del alcance canario.
- `would_scope` no existe como campo propio: el contrato lo cumple el aviso. No es un problema de seguridad.

**Datos personales:**
- El aviso añade **solo un código**. La entrada de la escalera ya se guardaba entera también en las `deny N3` de antes, y es la misma: seudónimos (`subject_ref`, `phone.refs`), códigos, enteros e instantes, sin nombres ni teléfonos.
- **El conjunto de personas no cambia.** La inscripción ya nacía antes de la escalera, en `evaluateCandidates`, con la puerta de audiencia.
- Lo que crece es el **número de filas por persona** en sombra: la decisión `plan`, el trabajo `simulated`, el resultado `not_attempted` y la decisión del despacho.

**Condición C1 (BAJA, de auditabilidad; ya la señalan el autor y la auditoría B):**
- `outreach:replay` reevalúa con el núcleo de ahora.
- Una decisión de sombra anterior a I-1 de una persona no canaria saldría `DIFFERENT`, y el replay es la herramienta de auditoría (D9-12).
- **Antes de desplegar:** `SELECT count(*) FROM outreach_decisions WHERE mode = 'shadow';` en producción, de solo lectura, tiene que dar 0.
- Si no da 0, hay que dejar escrito en el despliegue desde qué instante cambia la regla, para que un `DIFFERENT` anterior no se lea como manipulación.
- No he podido comprobarlo: no toco producción. El lead y la auditoría B §4.1 dicen que hay 0 decisiones.

---

## 4. Precedencia

En el código (`PolicyLadder.php:178-205`): N1 y N2 se evalúan antes, y el primer nivel que niega corta, con N3 `not_evaluated` y sin aviso. Dentro de N3 (`:322-345`), solo `canary_only_declared` cambia de lista. `studio_reference_invalid`, `catalog_incompatible`, `payment_links_disabled` y `template_violates_restriction` siguen en `$motivos` en los dos modos.

Lo cubren las pruebas, que he ejecutado:
- `test_la_precedencia_no_cambia…`: N1 `do_not_contact` y N2 `outreach_brake_global` sin aviso; los cuatro motivos de N3 niegan en sombra con el aviso al lado; N4, N5, N6 y N7 siguen negando o aplazando;
- los casos `shadow` nuevos del fixture: N1, N2 y la referencia inválida;
- `test_en_sombra_n1_sigue_negando_antes_que_el_aviso_del_canario`, con el planificador real.

`canary_only_declared` no está en `TEMPORAL`, así que en vivo sigue siendo una negativa permanente, sin `retryAt`.

---

## 5. Las pruebas y el fixture

| Qué | Resultado (ejecutado por mí, uno cada vez, SQLite) |
|---|---|
| `tests/Unit/Outreach/Core/H9ShadowCanaryOnlyLadderTest.php` | OK, 5 pruebas, 65 aserciones |
| `tests/Feature/Outreach/Run/H9ShadowCanaryOnlyTest.php` | OK, 4 pruebas, 28 aserciones |
| `tests/Unit/Outreach/Core/PolicyAndChannelParityTest.php` (paridad con el fixture, incluida la lista de avisos de sombra) | OK, 166 pruebas, 462 aserciones |
| `OutreachIsolationTest`: los recorridos en sombra y en vivo (`test_ninguna_escritura…`, `test_cero_red…_en_live`) | OK, 2 pruebas, 1 867 aserciones |
| Mi prueba del cambio de modo (§1.2) | OK, 2 pruebas, 16 aserciones |

**Las pruebas usan la clase real.** La unitaria llama a `PolicyLadder::decide` sobre el `baseInput` del fixture, que trae `mode = live`. La de integración usa `OutreachPlanner`, `OutreachDispatcher` y `OutreachReplay` reales. Cubren sombra, vivo y `off`.

**El cambio en `OutreachIsolationTest.php:570-575` es una actualización legítima, no una relajación:**
- Al socio 0 le siembra el mundo de la prueba una cartera vencida a propósito: `receivable(..., '2026-10-01')` en `:806`, más de 15 días antes del 17-11.
- Así que, sin el corte en N3, N6 lo niega con `collections_overdue_blocks_commercial`.
- La aserción sigue siendo **exacta en cada modo**: nivel, motivos y avisos. En vivo, `N3 [canary_only_declared]` sin avisos; en sombra, `N6` con los avisos N3 y N5. Los recuentos `[3, 1, 2]` no cambian.
- Lo que la guarda protege (escrituras solo en tablas del H9, cero red) no se toca.

**El fixture:**
- Lo regeneré con `build.py`, hacia un directorio del scratchpad. Los 11 JSON salen **idénticos byte a byte** a los del árbol, y `policy-ladder-cases.json` tiene sha256 `7ece0bf9…3490`, el que da el autor.
- Además, con las herramientas de **HEAD** más **solo** el `sec_ladder.py` nuevo, `policy-ladder-cases.json` sale idéntico. Con esas mismas herramientas, `catalog-snapshot.json`, `conditions-cases.json` y `strategy-content-cases.json` salen iguales a los de HEAD.
- Es decir: el fixture de I-1 **no depende** de los cambios de catálogo que otros agentes tienen sin commit en `sec_catalog.py` y `sec_strategy.py`. Se puede desplegar solo.

---

## 6. Efectos sobre datos y personas reales en sombra

He revisado cada consumidor. **Nada de la sombra gasta cupos reales, cuenta como contacto ni marca «contactado» fuera de la sombra:**

| Posible efecto | Qué pasa | Dónde |
|---|---|---|
| Presupuesto de contacto de N1 (día, semana, teléfono) | Solo cuentan los trabajos `live`. `simulated` nunca | `ContactHistoryFacts.php:173`, `OutreachStore.php:319` y `:348`, `OutreachPlanner.php:667` (excluye `source = simulated`) |
| Reservas de presupuesto en dinero o unidades | En sombra no hay parcelas | `OutreachPlanner.php:588` |
| Tope diario de la estrategia, cupo de inscripciones, enfriamiento, «otra finalidad hoy» | Se cuentan **por modo**: la sombra solo gasta los de la sombra | `OutreachStore.php:106, :120, :286, :302` |
| Supresión (`global_suppression`) | Solo la abren `opted_out`, `wrong_person` o `opt_out_word`, que en sombra no puede producir nadie: allí las fuentes son `simulated`, `system` y `crm_fact`. Las ingestas de WhatsApp, voz y bandeja filtran `mode = live` | `SubjectSuppressions.php:49-79`, `WhatsappTemplateOutcomes.php:84`, `VoiceCallOutcomes.php:87`, `AssistedQueue.php:64` |
| Bandeja que alguien pueda ejecutar a mano | Ninguna. La única acción sobre un trabajo es la bandeja asistida (`live` + `assisted`). El resto del panel solo lee | `UltronStudioOutreachController.php:91-94` |
| Notificaciones al personal, SSE, costes | Ninguna notificación desde `Outreach`. Los temas `outreach.*` del SSE siguen pendientes. Ninguna llamada externa | grep sin resultados; `StreamTopics.php:53` |
| `touches` y resultado `not_attempted` | Solo en la inscripción de sombra; no salen de la sombra | `OutreachDispatcher.php:349-351` |

**Efecto real, sin implicación de seguridad:**
- En sombra, el tope diario de la estrategia (`contactsPerDay`) se gastará ahora con toda la audiencia.
- Algunos canarios de sombra pueden salir aplazados con `strategy_daily_cap`. Es lo que la sombra quiere medir.

**Residuo operativo:**
- Al pasar de sombra a vivo, los trabajos `planned` y las inscripciones abiertas de sombra se quedan dormidos: el conciliador también filtra por el modo actual.
- Ya pasaba antes con los canarios. Con I-1 el volumen crece. Son inofensivos (canal `simulated`, sin reclamación posible), pero ensucian el panel.

---

## 7. Hallazgos

No hay críticos, altos ni medios.

| # | Severidad | Ubicación | Qué es | Corrección |
|---|---|---|---|---|
| C1 | **Baja** (auditabilidad) · **condición de despliegue** | `OutreachReplay.php:109` | Si producción tiene decisiones de sombra anteriores, su replay daría `DIFFERENT` sin que haya manipulación | La consulta de solo lectura del §3 antes de desplegar. Si da más de 0, anotar el instante del cambio en el registro del despliegue |
| R1 | Baja (cobertura) · recomendación | `OutreachDispatcher.php:174`, `:224-225`; `OutreachStore.php:136` | La invariante que hace seguro I-1 («un trabajo o inscripción de sombra nunca se reclama ni se planifica en vivo») no tiene prueba directa en el repo. Busqué cambios de `shadow` a `live` dentro de una misma prueba: solo existe uno, a nivel de canal (`WhatsappTemplateChannelTest.php:332-334`). Con I-1 esa invariante pasa a proteger a toda la audiencia, no solo a los canarios. Hoy no es explotable: hay tres barreras (§1.1) | Que **tester** incorpore los dos escenarios de §1.2 como prueba de regresión. El fichero del scratchpad sirve de punto de partida |
| R2 | Info | `tests/Feature/UltronStudio/OutreachIsolationTest.php:552` | El comentario de la guarda sigue diciendo «El socio 0 no es canario: lo niega N3», que ya solo es verdad en vivo. La explicación correcta está en `:570-571` | Ajustar el comentario cuando se toque el fichero |

---

## 8. Lo que no he podido verificar

- **El estado de producción** (C1): no lo he consultado. Lo de «0 estrategias y 0 decisiones» lo dicen el lead y la auditoría B.
- **PostgreSQL.** Lo ejecuté todo en SQLite. El filtro por modo de la reclamación es la misma consulta en los dos motores; en PostgreSQL solo se añade `FOR UPDATE SKIP LOCKED`. El autor dice que pasó la batería de `Run` en PostgreSQL 14; no lo he repetido.
- **Las baterías completas.** No he lanzado `tests/Unit/Outreach` ni `tests/Feature/Outreach` enteras, solo los ficheros de la tabla del §5. Tampoco `test_la_puerta_de_diff…` de la guarda: según el autor falla por cambios de otros agentes en `app/Services/Marketing/Ultron`, ajenos a I-1.
- **La copia del fixture en el CRM** (`sync-h9-fixtures.mjs`, `h9-fixtures.spec.ts`). No es de seguridad, pero sin ella falla la comprobación de bytes del CRM.

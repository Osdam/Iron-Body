# Informe del tester · I-6: pruebas de extremo a extremo de seis disparadores del H9

**11-11-2026 · tester · rama `feat/ultron-studio-h9`, HEAD `3161d3a3`, árbol `_worktrees/ultron-h8`.** Solo he añadido pruebas y este informe. No he tocado `app/` (al cerrar, `git status --porcelain app` da 0 líneas), ni producción, ni el CRM. Sin commit ni push.

Marcas: **[C]** comprobado ejecutando · **[L]** leído en el código, sin ejecutar · **[S]** suposición.

## Resumen para el lead

1. **Ficheros nuevos.** Los tres están en `tests/Feature/Outreach/Run/`:
   - `H9SweepTriggersEndToEndTest` (6 pruebas): `member.inactive`, `payment_link.abandoned` y `receivable.overdue`.
   - `H9CrmEventTriggersEndToEndTest` (4 pruebas): `lead_created`, `lead_qualified` y `renewal_window_opened`, desde su emisor real.
   - `H9RenewalWindowBogotaDayTest` (1 prueba): **roja a propósito**, es el defecto D-1.
2. **Cobertura.** En los seis disparadores, la persona que califica recorre en sombra el camino entero: barrido o ingesta → inscripción → `plan` con trabajo `simulated`, o la negativa N1 exacta → despacho. Quien no califica no se inscribe, y los recuentos son exactos. Los tres de barrido tienen además su prueba de varias páginas (páginas de 2).
3. **SQLite [C].** Mis 10 pruebas verdes pasan: 193 aserciones. `Run` entera: 96 pruebas, 1 514 aserciones; la única roja es D-1 y hay 2 omitidas ajenas (solo PG). Las guardas pasan las 138 (15 ficheros). `pint` pasa.
4. **PostgreSQL 14 [C]** (`ultron_studio_i6_unit`): las 10 pasan (115 + 78 aserciones). `Run` entera, antes de añadir D-1: 95 de 95, 1 588 aserciones. D-1 falla igual que en SQLite.
5. **Mutaciones [C].** Las apliqué en un clon APFS, cada una restaurada con su sha256 comprobado: **5 de 5 muertas**. M1 (inactivos), M2 (posición de los enlaces), M4 (emisor de `lead_qualified`) y M5 (ingesta de `lead_*`) **solo las detectan las pruebas nuevas**; las 1 126 de `Run`, `Facts` y `Unit/Outreach` no las ven.
6. **D-1 (defecto, en rojo).** `commercial:scan` abre la ventana de renovación con `Carbon::today()` en UTC (`ScanCommercialFacts.php:82`) y corre cada hora en punto UTC.
   - Por eso `renewal_window_opened` llega a las **19:00 de Bogotá, cuando quedan 11 días**, y no se repite.
   - Una estrategia con `days_to_expiry ≤ 10` no inscribe nunca a esa persona.
   - En el clon, con `Carbon::today('America/Bogota')`, D-1 pasa a verde y las otras 10 siguen verdes. La corrección no está aplicada: es de backend.
7. **Adyacente, comprobado con una sonda.**
   - **A-1:** `membership_expired` llega a las 19:00 de Bogotá **del último día válido**, porque `Carbon::yesterday()` va en UTC (`:115`). Una estrategia con `membership.active = false` no inscribe a nadie.
   - **O-1:** la segunda ocurrencia de la misma persona en una misma página no cae en ningún recuento (`OutreachPlanner.php:246`).
8. **Sin cubrir.** Lo detallo en §6:
   - el modo `live` de estos seis;
   - los canales W9 y V9;
   - la ingesta con más de una página de eventos;
   - el volumen real;
   - nada en producción.

---

## 1 · Qué cubre cada prueba

Las tres comparten la forma de la suite. Todas usan `RunWorld`, con la cadena firmada de verdad y la línea base del Studio, que declara «solo canario». El reloj está congelado en UTC el martes 17-11-2026 a las 15:00Z (10:00 de Bogotá), salvo D-1. La escalera, la puerta, el planificador, el despacho y la ingesta son las clases reales: no hay dobles.

Las pruebas respetan los tres cambios del 11-11:
- **I-1.** Quien no es canario se simula con los avisos exactos `[N3 canary_only_declared, N5 canary_scope_mismatch]`; el canario, sin avisos. Las dos ramas aparecen.
- **I-4.** Hay una prueba de varias páginas por cada disparador de barrido.
- **I-5.** Ninguno de los seis es de los eventos sin emisor.

### `H9SweepTriggersEndToEndTest` (6 pruebas, 115 aserciones)

| Prueba | Califica y recorre el camino | No califica | Aserciones exactas |
|---|---|---|---|
| `test_member_inactive_quien_dejo_de_venir_…` | Inactivo hace 20 días, NO canario → `plan` simulado con avisos N3 y N5. El borde de 14 días justos (23:59:59 de Bogotá del 03-11) es un **socio sin prospecto** → `deny` N1 `no_consent_for_purpose_channel` (I-2). | 13 días (00:00 de Bogotá del 04-11), vino hace 3, nunca vino, congelado: no son candidatos. Vence en 3 días: candidato, `not_in_audience` | Recuentos de la pasada completos `{3, 3, 2, 1, …, more: false}`; ciclo `inact:2026-W47`; una `enroll` por inscripción; `planDue` `[2, 1, 1]`; el trabajo `planned` / `simulated` / `shadow` con 0 reservado y sin contadores de presupuesto; los 7 niveles en `pass`; la negativa con `deny` + 6 `not_evaluated`, `blocked` / `wait` y espera hasta el 18-11 a las 15:00; despacho `claimed 1`, `simulated 1`, resultado `not_attempted` / `simulated`; repetir la pasada la misma semana da `already_enrolled 2`; ningún teléfono en las decisiones |
| `test_member_inactive_pagina_por_users_id_…` | 4 que califican, intercalados con no candidatos | Uno fuera de la audiencia | 4 pasadas con sus recuentos y la posición exacta `[cursorFrom, cursorNext]` = **ids de `users`**. Tres fichas sin usuario delante separan los ids de `members` y `users`. Al acabar la vuelta, cada uno inscrito una vez; la cuarta pasada vuelve a empezar |
| `test_payment_link_abandoned_…_converted` | Enlace de hace 7 h, NO canario. Enlace **solo con `user_id`** de hace 6 h justas (el borde `≤`), canario → los dos `plan` simulados | Candidato excluido: el enlace más un rechazo ayer (`declined_last_7d > 0`). No candidatos: 5 h 59 min 59 s, caducado, aprobado, sin URL, 174 h + 1 s y sin persona | Recuentos completos `{3, 3, 2, …, excluded 1}`; ciclo `tx:<id>`. Quien **paga entre planificar y despachar** sale `converted` (`canceled` / `success_reached`, inscripción `completed`, el canal no se llama); el otro, `simulated` |
| `test_payment_link_abandoned_pagina_por_id_de_transaccion_…` | 4 personas | Uno dentro del plazo | Posición = **id de la transacción**; hay 3 aprobadas delante para separarla de `members`. El **segundo enlace de la misma persona** en la página 2 da `open_enrollment 1` y conserva su ciclo `tx:L1` |
| `test_receivable_overdue_…_n1_la_niega_…` | La cuenta vencida hace 10 días es de un socio con autorización **comercial** `granted` y **canario**. Están también los dos extremos del intervalo cerrado [3, 30]; la de 3 días tiene un abono (generación 1). Los tres se inscriben y **N1 niega a los tres** con `no_consent_for_purpose_channel` (D9-15: «cobranza, ninguna hoy», P9-15) | Hace 2 días, hace 31, vence hoy y pagada | Recuentos completos; ciclos `rcv:<id>:0`, `rcv:<id>:1` y `rcv:<id>:0`; `purpose` `collections`; `planDue` `[3, 0, 3]`; consentimiento `missing`; sin avisos (N1 decide antes que N3); 0 trabajos; ningún importe en las decisiones; el despacho reclama 0 |
| `test_receivable_overdue_pagina_por_id_de_la_cuenta_…` | 4 socios | Una cuenta de hace 1 día | Posición = **id de `receivables`**; hay 2 cuentas no candidatas delante. La **segunda cuenta vencida del mismo socio** da `open_enrollment` |

### `H9CrmEventTriggersEndToEndTest` (4 pruebas, 78 aserciones)

Los eventos los registra el **emisor real**, no una fila sembrada:
- `MarketingLead::create` y `->update(['status' => …])` → `MarketingLeadCommercialObserver`;
- `artisan commercial:scan` → `ScanCommercialFacts`.

Así queda probado también el tramo emisor → `commercial_events` → ingesta.

| Prueba | Califica | No califica | Aserciones exactas |
|---|---|---|---|
| `test_lead_created_del_observador_…` | Prospecto nuevo `granted`, NO canario → `plan` simulado con avisos N3 y N5. Prospecto `pending` → se inscribe (la puerta no mira el consentimiento, D9-3) y **N1 lo niega** | Un `lead_created` de un prospecto que ya es socio: el emisor pone `member_id`, la persona es `m:` y queda `not_in_audience`. Un `lead_qualified`: no es de esta estrategia | Eventos registrados `{lead_created 3, lead_qualified 1}`; ingesta `events 4`, `cursor_to` = máximo y el cursor de la fuente; recuentos completos `{3, 3, 2, 1, …}`; ciclo `ce:<id del evento>`; `lead_id` sí y `member_id` nulo; `planDue` `[2, 1, 1]`; despacho `simulated 1`; la ingesta siguiente da `events 0` |
| `test_lead_created_registrado_antes_de_publicar_…` | El evento posterior a la publicación inscribe | El evento anterior **no** inscribe nunca: sin estrategias, la ingesta avanza el cursor igual (auditoría B §4.1, no retroactivo) | El cursor queda en el id del primer evento; `events 0` tras publicar; después, `candidates 1` y `enrolled 1` |
| `test_lead_qualified_del_observador_…` | Caliente (`hot`) y canario → `plan` **sin avisos** | Tibio → `not_in_audience`. Muy caliente con `staff_review_pending` → `excluded` (`human_in_control`). `cold` → el emisor no registra nada. `needs_human` → `human_requested`, no es de esta estrategia | `{human_requested 1, lead_qualified 3}`; recuentos completos `{3, 3, 1, 1, 1, …}`; ciclo `ce:<id>`. La **segunda calificación** (de `hot` a `interested`) da `open_enrollment 1` |
| `test_renewal_window_opened_de_commercial_scan_…` | Vence en 5 días, NO canario → `plan` simulado. Vence en 10 (el borde de la ventana del motor), **socio sin prospecto** → `deny` N1 (I-2) | Congelado en la ventana → `not_in_audience`. No hay evento con 11 días, 40 días, vencido ayer (sale `membership_expired`) ni un usuario sin ficha | Eventos `{membership_expired 1, renewal_window_opened 3}`; recuentos completos; ciclo `ce:<id>`; `planDue` `[2, 1, 1]`; despacho `simulated 1`. El escaneo de la hora siguiente no repite el hecho y la ingesta da `events 0` |

### `H9RenewalWindowBogotaDayTest` (1 prueba, **en rojo por D-1**)

Recorre las 17 pasadas horarias reales (escaneo + ingesta) desde las 18:00 de Bogotá del 17-11 hasta las 10:00 del 18-11. El socio vence el 28-11, la estrategia usa la misma ventana que el motor (`days_to_expiry ≤ 10`) y se espera **una** inscripción. La explico en §4.

## 2 · Resultados de ejecución [C]

`nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging …`, una cada vez.

| Batería | Motor | Resultado |
|---|---|---|
| `H9SweepTriggersEndToEndTest` | SQLite | **OK, 6 pruebas, 115 aserciones** |
| `H9CrmEventTriggersEndToEndTest` | SQLite | **OK, 4 pruebas, 78 aserciones** |
| `H9SweepTriggersEndToEndTest` | PostgreSQL 14 (`ultron_studio_i6_unit`, creada en el clúster desechable 127.0.0.1:55432) | **OK, 6 / 115** |
| `H9CrmEventTriggersEndToEndTest` | PostgreSQL 14 | **OK, 4 / 78** |
| `H9RenewalWindowBogotaDayTest` | SQLite y PostgreSQL | **1 fallo** (D-1), 19 aserciones; la salida está en §4 |
| `tests/Feature/Outreach/Run` con mis 3 ficheros | SQLite | **96 pruebas, 1 514 aserciones, 1 fallo (D-1), 2 omitidas.** Las omitidas son `H9IdempotencyRaceTest::test_13_…` y `OutreachRaceTest::test_16_…`, las dos «Solo PostgreSQL»: no son mías |
| `tests/Feature/Outreach/Run` con los 2 ficheros verdes, antes de añadir D-1 | SQLite | **OK, 95 pruebas, 1 495 aserciones, 2 omitidas** |
| `tests/Feature/Outreach/Run` con los 2 ficheros verdes, antes de añadir D-1 | PostgreSQL 14 | **OK, 95 pruebas, 1 588 aserciones** |
| Guardas estáticas (la orden del encargo, 15 ficheros) | SQLite | **OK, 138 pruebas, 178 690 aserciones** |
| `vendor/bin/pint` y `pint --test` sobre los 3 ficheros | — | `passed` |

La salida de D-1 la di primero tal cual y después repetí la ejecución en PostgreSQL. La batería `Run` entera en PostgreSQL **con** el fichero de D-1 no la he relanzado; D-1 solo, en PostgreSQL, falla igual (§4).

## 3 · Mutaciones [C]

**Dónde.** En un clon APFS del backend (`cp -c`, con `vendor`) en el scratchpad de la sesión, fuera de cualquier árbol de git. No las apliqué en el árbol para no darle código mutado al otro agente, que lanza `php artisan` sobre él.
- El guion muta un fichero, pasa `php -l`, ejecuta las pruebas y **restaura** el fichero desde el original, comprobando el sha256.
- Al final, `diff -rq` entre el `app/` del clon y el del árbol: idéntico.
- En el árbol, `git status --porcelain app`: 0 líneas.

| Mutante | Fichero | La matan | ¿La ven las 1 126 pruebas existentes de `Run`, `Facts` y `Unit/Outreach`? | sha256 al restaurar |
|---|---|---|---|---|
| **M1** · `member.inactive` ignora el parámetro `days` de la estrategia (usa 7) | `Run/TriggerPrefilters.php:96` | `test_member_inactive_quien_dejo_de_venir_…` | **No**: solo la nueva | `4a1a3701e7bf798a…` = original |
| **M2** · la posición de `payment_link.abandoned` guarda `member_id` en vez del id de la transacción | `Facts/PaymentFacts.php:160` | `test_payment_link_abandoned_pagina_…` | **No**: solo la nueva | `554369715cef25e1…` = original |
| **M3** · la autorización comercial de WhatsApp vale para cobranza | `Facts/LeadFacts.php:253` | `test_receivable_overdue_…_n1_la_niega_…` | Sí, también `LeadAndVoiceFactsTest::test_autorizacion_de_whatsapp_por_finalidad_…` | `df061a558281b2dd…` = original |
| **M4** · el emisor deja de registrar `lead_qualified` al pasar a `hot` | `Observers/Commercial/MarketingLeadCommercialObserver.php:23` | `test_lead_created_…` y `test_lead_qualified_…` | **No**: tampoco lo ven las 155 de `tests/Feature/Commercial` ni `OutreachCatalogEmittersTest` | `6f9a48fd73e7e78c…` = original |
| **M5** · la ingesta deja de repartir los eventos `lead_*` a sus estrategias | `Run/OutreachPlanner.php` (`ingest()`) | las 3 de `lead_*` | **No**: solo las nuevas | `6e13ccae7a513d38…` = original |
| **F1** · *corrección candidata, no es un mutante*: `Carbon::today('America/Bogota')` en la ventana de renovación | `Console/Commands/ScanCommercialFacts.php:82` | — | D-1 pasa a **verde**; las 11 juntas: OK, 212 aserciones | `63e4bfb0c7bac302…` = original |

## 4 · Defecto D-1: `renewal_window_opened` llega con 11 días de Bogotá y no se repite

**Qué pasa [C]:**
- `commercial:scan` corre cada hora en punto (`routes/console.php:328-331`).
- `scanRenewalWindow()` abre la ventana con `$today = Carbon::today()` (`ScanCommercialFacts.php:82`). La aplicación va en UTC (`config/app.php:78`).
- El primer escaneo del día UTC (00:00Z) son las **19:00 de Bogotá del día anterior**. A esa hora la ventana [hoy UTC, hoy UTC + 10] ya incluye a quien vence dentro de **11** días de Bogotá.
- El hecho se registra una sola vez por vencimiento (`"renewal:{$user->id}:{$endsAt}"`, `:105`).
- El H9 lo ingiere en ≤ 10 min y cuenta los días en Bogotá (contrato D9-4). Una estrategia con `membership.days_to_expiry ≤ 10`, la misma ventana que anuncia el motor, lo deja `not_in_audience`, y **nunca vuelve**.

**Salida de la prueba** (SQLite; en PostgreSQL es idéntica):

```
1) Tests\Feature\Outreach\Run\H9RenewalWindowBogotaDayTest::test_renewal_window_opened_inscribe_al_socio_cuando_le_quedan_10_dias_de_bogota_con_las_pasadas_horarias_reales
D-1 (I-6): el socio al que le quedan 10 días de Bogotá no se inscribió nunca. Pasadas: ["17-11 19:00 Bogotá → {\"candidates\":1,\"enrolled\":0,\"not_in_audience\":1}"]. `commercial:scan` abre la ventana con `Carbon::today()` en UTC: el hecho llega a las 19:00 de Bogotá con 11 días, la audiencia ≤ 10 lo deja fuera y no se repite.
Failed asserting that 0 is identical to 1.
tests/Feature/Outreach/Run/H9RenewalWindowBogotaDayTest.php:76
FAILURES!  Tests: 1, Assertions: 19, Failures: 1.
```

**Sonda previa** (en el clon, la misma situación paso a paso):
- a las 18:00 de Bogotá del 17-11 hay 0 eventos;
- a las 19:00 llega `["renewal_window_opened"]` y la ingesta da `{"candidates":1,"enrolled":0,"not_in_audience":1}`;
- el 18-11 a las 10:00 de Bogotá (quedan 10) hay 0 eventos nuevos y 0 inscripciones.

**Por qué lo trato como defecto, y la salvedad del contrato:**
- El propio emisor dice que su ventana es de 10 días (`commercial.thresholds.renewal_window_days`).
- D9-4 manda contar los días en Bogotá.
- La consecuencia es una persona que nunca se inscribe, y la prueba lo afirma sobre el **comportamiento observable**, no sobre la implementación.
- **Salvedad:** el contrato (§D9-1, línea 51) dice que las ventanas de `commercial:scan` «son fijas: el H9 no depende de ellas para las ventanas configurables». Si el lead decide que es una limitación documentada y no un defecto, la prueba tiene que cambiar de expectativa **por decisión explícita**, no para ponerla en verde.
- No la he marcado como omitida ni la he adaptado.

**Quién y cómo.**
- Es de **backend**: `ScanCommercialFacts` es del motor comercial, no del H9.
- F1 (§3) demuestra que el cambio local de `:82` la pone en verde. Pero el mismo comando usa `Carbon::today()` / `yesterday()` en UTC también en `:115`, `:151`, `:156`, `:184` y `:188`, así que la corrección debería mirar las cuatro familias. Ver A-1.
- **Mientras tanto, para quien escriba estrategias:** para una ventana exacta de días, usar el disparador periódico `membership.expiring_window`, que se calcula en días de Bogotá y está probado. Con `renewal_window_opened`, no repetir la ventana del motor en la audiencia.

## 5 · Hallazgos adyacentes (no son de los seis; no añadí pruebas al repositorio)

**A-1 · `membership_expired` llega antes de que la membresía venza en Bogotá [C, sonda en el clon]:**
- `scanExpired()` usa `Carbon::yesterday()` en UTC (`ScanCommercialFacts.php:115`).
- A las 00:00Z (las 19:00 de Bogotá del **último día válido**) emite `membership_expired` con la clave `expired:<user>:<fecha de fin>` (`:133`).
- El H9 ve a la persona `membership.active = true`, porque hasta el final de ese día de Bogotá la membresía sigue vigente.

Salida de la sonda:

```
[a] eventos: [{"event":"membership_expired","member_id":1,"dedupe_key":"membership_expired:expired:1:2026-11-17"}]
[a] ingesta 21:00 Bogotá: {"candidates":1,"evaluated":1,"enrolled":0,"not_in_audience":1,…}
[a] escaneo + ingesta 18-11 10:00 Bogotá: eventos nuevos 0; inscripciones 0
```

- Es **sistemático**: el primer escaneo del día UTC cae siempre en ese hueco. Una estrategia sobre `membership_expired` con audiencia `membership.active = false` no inscribiría a nadie [S para producción: no lo he medido allí].
- `OutreachPlannerTest::test_14_…` no lo ve porque su audiencia no mira `membership.active`.
- Lo mismo puede afectar a `inactivity_detected` (`:151-188`, la semana y el corte en UTC) [L, no ejecutado].
- Sugerencia: backend corrige las cuatro familias juntas y tester añade la prueba gemela de D-1 para `membership_expired`.

**O-1 · Una segunda ocurrencia de la misma persona en una misma página no cae en ningún recuento [C, sonda]:**
- `evaluateCandidates()` la salta con `if (isset($ciclos[$c->subjectKey])) continue;` (`OutreachPlanner.php:246`), sin contarla.
- Con dos enlaces abandonados de la misma persona en una página sale `{"candidates":2,"evaluated":1,"enrolled":1,…}` y nada más: `candidates` 2 frente a evaluados más saltados 1.
- No contradice el contrato, pero en el panel la pasada «pierde» un candidato sin motivo.
- Entre páginas sí cuenta (`open_enrollment`, fijado en las pruebas de paginación). Arreglarlo sería un contador (`same_subject_in_page` o similar): lo decide backend.

## 6 · Lo que no queda cubierto, y por qué

- **Modo `live` de estos seis.** El encargo era en sombra. La diferencia entre sombra y vivo (N3 niega fuera de la sombra, un trabajo de sombra nunca se despacha en vivo) ya la fijan, para todos los disparadores, `H9ShadowCanaryOnlyTest` y `H9ShadowNeverRunsLiveTest`. No he repetido esas pruebas por disparador.
- **Canales W9 (plantilla de WhatsApp) y V9 (voz).** Todas las estrategias usan `assisted:whatsapp`. En sombra los tres canales acaban en `simulated`. Los avisos de N4 propios de cada canal (`sends_disabled_in_code`, `budget_zero`) no los he fijado para estos disparadores.
- **La ingesta con más de una página de eventos** (`outreach.sweep.batch` también limita la ingesta). El encargo pedía la paginación de los de barrido. El avance por cursor sí está cubierto (`test_lead_created_registrado_antes_…` y la segunda ingesta de cada prueba), pero no el corte a mitad de lote.
- **Ramas que no recorro:**
  - `payment_link.abandoned` con un `user_id` sin ficha de socio (el resolutor lo descarta, [L]);
  - `receivable.overdue` con el reverso de un abono (nueva generación, nuevo ciclo) de extremo a extremo; la generación sí la fija el prefiltro (`MoneyAndAttendanceFactsTest`);
  - `member.inactive` en el cambio de semana ISO (con la inscripción abierta, `open_enrollment`);
  - la cobranza y lo comercial el mismo día (`purpose_conflict_today`): hoy la cobranza nunca pasa de N1.
- **`lead_created` con `denied`, `unknown` o `do_not_contact` de un hermano.** Esas negativas de N1 las fija `H9LeadFollowupTest` para los seguimientos y la escalera pura; aquí solo cubro `pending`.
- **Volumen y rendimiento.** `member.inactive` es «caro» en el catálogo. No he medido nada.
- **Producción.** Nada: ni el volumen real, ni si `COMMERCIAL_EVENTS_ENABLED` está encendida, ni que A-1 y D-1 ocurran allí. Hoy hay 0 estrategias, así que todavía no ha podido notarse.
- **La batería completa del backend** no la he lanzado: solo `Run`, `Facts`, `Unit/Outreach`, `tests/Feature/Commercial` (en el clon, para las mutaciones) y las guardas.

## 7 · Ficheros y estado del árbol

- **Nuevos (sin seguimiento, para el commit cuando el lead lo ordene):**
  - `tests/Feature/Outreach/Run/H9SweepTriggersEndToEndTest.php`
  - `tests/Feature/Outreach/Run/H9CrmEventTriggersEndToEndTest.php`
  - `tests/Feature/Outreach/Run/H9RenewalWindowBogotaDayTest.php` (rojo, D-1)
  - `docs/ultron-studio/plans/informes-motores/informe-tester-i6.md`
- **No son míos y no los he tocado:**
  - `docs/ultron-studio/plans/cierre/plan-quejas-consentimiento-cuatro-ojos.md` (sin seguimiento; otro agente);
  - las capturas de `docs/ultron-studio/ops/evidencia-h8|h9/2026-11-11/…`, que ya venían sin seguimiento.
- **Base de PostgreSQL creada:** `ultron_studio_i6_unit`, en el clúster desechable de 127.0.0.1:55432. La dejo por si hay que repetir.
- **Fuera del repositorio:** el clon de mutaciones, la sonda `ZzProbeI6Test.php` (solo en el clon) y el guion `mutate.py` están en el scratchpad de la sesión.
- Sin commit ni push.

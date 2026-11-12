# Informe del reviewer · Tanda 2 del motor comercial (`0d4ed88a` parte 1c y `2fc75a1f` escáner)

Árbol: `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h9`, HEAD `0d4ed88a`. Revisión hecha el 2026-11-12 (UTC).
No he tocado código, producción ni la red. Las mutaciones y sondas corrieron en una copia clonada fuera del árbol, en el scratchpad de la sesión.
Etiquetas: **[C]** = comprobado ejecutando; **[L]** = leído en el código, sin ejecutar.

---

## Veredicto

| Commit | Veredicto |
|---|---|
| `0d4ed88a` · el sujeto se resuelve dentro de la protección (1c) | **APROBADO** |
| `2fc75a1f` · `commercial:scan` mira a todos sus candidatos | **APROBADO** (con dos menores) |
| Desplegar los dos esta noche, encima de `181075b3` | **Nada lo impide** (§5) |

**Aparte, y urgente (NO lo introduce esta tanda ni lo arregla):** `ElectronicInvoiceCommercialObserver::updated()` revienta en CADA cambio de estado de una factura electrónica mientras `COMMERCIAL_EVENTS_ENABLED` esté encendido, que es como está producción. Con la bandera encendida, el job real de emisión (`EmitElectronicInvoiceJob`) falla al marcar validada, rechazada y error [C]. Ver §0. Debería ir a `backend`/`debugger` ya, en un commit aparte.

---

## 0 · Hallazgo previo, fuera del diff: el observador de facturas lanza `Error` en cada cambio de estado [C]

- **Dónde:**
  - `app/Observers/Commercial/ElectronicInvoiceCommercialObserver.php:46`: `match ((string) $invoice->status)`;
  - y `:63`: `(string) $invoice->status` en la clave.
- **Por qué falla:** `app/Models/ElectronicInvoice.php:53` castea `status` a `App\Enums\InvoiceStatus` (un enum respaldado). En PHP, `(string)` sobre un enum lanza `Error: Object of class App\Enums\InvoiceStatus could not be converted to string`.
  - El observador corre en el evento `updated` del modelo, FUERA de `record()`, así que nada lo contiene.
  - Sale por el `save()` de quien cambia el estado: `markProcessing()`, `markValidated()`, `markRejected()` y `markError()` (`ElectronicInvoice.php:135-175`), y `EmitCreditNoteJob.php:215`.
  - Esas líneas vienen de `991ac346` (2026-08-06). Ni `181075b3` ni esta tanda las tocan.
- **Cómo lo comprobé:**
  1. Sonda en la copia (SQLite): `ElectronicInvoice::update(['status' => 'validated'])` con la bandera encendida → `Error … could not be converted to string` en `ElectronicInvoiceCommercialObserver.php:46`.
  2. Las pruebas reales del job, en la copia:
     - `tests/Feature/Billing/InvoiceEmissionTest.php` con la bandera apagada: `OK (12 tests, 29 assertions)`;
     - con `COMMERCIAL_EVENTS_ENABLED=true`: `Tests: 12, Errors: 3`. Fallan `test_emit_job_validates_invoice_on_success`, `test_emit_job_marks_error_and_throws_on_server_error` y `test_emit_job_marks_rejected_without_throw_on_validation_error`, las tres con ese `Error`.
- **Escenario de fallo en producción [L, a confirmar allí]:**
  - Con la bandera encendida, `EmitElectronicInvoiceJob::handle()` llama a `markProcessing()` (`:272`) justo antes del POST a Factus. El `UPDATE` ya se ha ejecutado (el job no abre transacción) y después el observador lanza.
  - Resultado: el job falla antes de emitir. La cola lo reintenta, `failed()` vuelve a llamar a `markError()` (`:502`) y este vuelve a lanzar.
  - Una factura que sí llegara a validarse fallaría después del `UPDATE` de `markValidated()`, y lo que va detrás no correría.
  - Además, `invoice_validated` e `invoice_rejected` no se han registrado nunca como hechos comerciales.
- **Desde cuándo:**
  - Según el contrato, la bandera estaba en `false` en el Paso 0 (09-11 20:35 UTC) y está encendida desde L9-99. L9-114 dice: «El riesgo estaba vivo desde L9-99 (`events_enabled`)» y «reabre I-1 mientras `COMMERCIAL_EVENTS_ENABLED` siga encendido».
  - No he podido ver la fecha exacta ni si la emisión con Factus está activa en producción.
- **Qué comprobar en producción (solo lectura):**
  - en los logs de Laravel y de la cola, `InvoiceStatus could not be converted to string`;
  - en `electronic_invoices`, filas en `processing` o `error` con `last_attempt_at` posterior al encendido.
- **Corrección sugerida** (no la hago; para `backend`):
  - `$status = $invoice->status instanceof \BackedEnum ? $invoice->status->value : (string) $invoice->status;`;
  - usarlo en el `match` y en `emit($invoice, $event, $status)`. La clave queda `invoice:{id}:validated`, que es la forma prevista.
  - Prueba de regresión: `InvoiceEmissionTest`, o una nueva del observador, con `commercial.events_enabled = true`, que fije el camino feliz de `invoice_validated` e `invoice_rejected` (clave y payload).
  - Alternativa de emergencia sin código: apagar `COMMERCIAL_EVENTS_ENABLED` (es la vuelta atrás que ya documenta L9-114).
- **Relación con la tanda:**
  - Desplegar `0d4ed88a` no lo empeora ni lo arregla, y revertir tampoco.
  - Sí matiza el «no cambia qué se registra» del informe en el caso de las facturas: hoy solo se registra `invoice_requested`, antes y después.
  - Las 7 pruebas «si el resolver falla…» del caso `factura` solo cubren `created`, así que no lo podían ver.

---

## 1 · `0d4ed88a` (parte 1c): corrección y completitud

### 1.1 ¿Queda alguna lectura propia fuera del aislamiento? [L + C]

Los nueve de `CommercialEventServiceProvider::OBSERVERS` (`:46-56`):

| Observador | Lecturas a la base dentro de la transacción de quien escribe | Estado |
|---|---|---|
| Pagos | `fromMember` | ahora cierre, dentro de `isolated()` |
| Membresías (`User`) | `fromUser` (`Schema::hasTable` + `members` + `marketing_leads`) | cierre |
| Fichas (`Member`) | `fromMember` | cierre |
| Leads | ninguna: el sujeto sale del propio modelo | no le hace falta |
| Citas | `fromLead` | cierre |
| Conversaciones | `fromLead` | cierre |
| Quejas de la IA | `fromLead`, en `DB::afterCommit`: fuera de la transacción en producción, con su propio `try/catch` | no le hace falta |
| Asistencias | `entriesFor()` y `fromMember` | los dos dentro (`isolated()` + cierre) |
| Facturas | `find()` del origen (ya en `isolated()` desde 1b) y `fromMember`/`fromUser` | todo `subjectFor()` dentro del cierre |

- Lo que queda fuera: `(string) $invoice->status` (§0), que no es una lectura a la base y no lo cubre ningún aislamiento. El resto del cuerpo de los observadores es PHP puro: payloads, claves y `now()`.
- **Puntos de guardado anidados con el mismo nombre.** En las facturas, `record()` → `isolated()` → `subjectFor()` → `isolated()` abren dos `SAVEPOINT commercial_event_record` en la misma conexión. Lo comprobé con `psql` contra el PG 14 desechable:
  - `RELEASE` libera solo el más reciente;
  - un fallo en el interno vuelve al interno, y uno posterior en el externo vuelve al externo;
  - lo de quien llama se conserva (`{1,4}`).
  - Las cadenas de Wompi y Nequi de la factura pasan por ahí y están en verde. Correcto.
- **Conexión:** ningún modelo declara `$connection` (`grep`), así que el punto de guardado por defecto (el de `CommercialEvent`) está en la misma conexión que las lecturas del resolver y que `entriesFor()`.

### 1.2 Cambio de comportamiento y llamadores [L + C]

- **Firma `array|\Closure`.** Los únicos llamadores de `CommercialEventRecorder::record()` son los 9 observadores y `ScanCommercialFacts.php:340`. Los que no se tocaron pasan un array, que sigue valiendo. No rompe a nadie [L, `grep`].
- **Cierre que devuelve un sujeto vacío** [L]:
  - abre el punto de guardado, `subjectIds()` da `null`, el trabajo devuelve `null`, `RELEASE`, y `record()` devuelve `null` sin log;
  - igual que el array vacío de antes, salvo dos idas y vueltas (`SAVEPOINT`/`RELEASE`).
- **Cierre que devuelve algo que no es array** [L]:
  - `subjectIds(array)` lanza `TypeError` dentro de `isolated()`: vuelta al punto de guardado, sale por el `catch (\Throwable)` de `record()`, `record_failed` y `null`. Contenido.
  - Los 7 cierres están tipados `fn (): array`, así que un `null` lanzaría ya dentro del cierre: el mismo camino.
- **Lo que deja de ser ruidoso:**
  - El único camino que era ruidoso de verdad es el pago con socio (`transitionTo()` → `$fresh->save()` lanzaba, Wompi o la reconciliación reintentaban y el hecho acababa registrado). Ahora el pago se aprueba, el hecho `payment_approved` se pierde y queda `record_failed` (error).
  - Nadie dependía del ruido:
    - el escáner de enlaces abandonados mira el estado del pago, no el hecho;
    - `payment_approved` solo alimenta Supervisión y los disparadores del H9 (`OutreachCatalog.php:187`, `EngineFacts.php:52`);
    - y es la misma regla que `181075b3` ya aplicó al `INSERT` (perder un hecho es menor que perder un pago).
  - Las asistencias: antes un fallo del recuento salía por `Attendance::create` en autocommit, con la fila ya escrita y un 500 al torniquete. Ahora la entrada queda y no hay 500: es una mejora.
  - Nadie consume `commercial.event.record_failed` salvo los logs (`grep`), así que el `event` compuesto `first_checkin|attendance_milestone` no rompe nada.
- **Reentrada:** ninguno de los cierres escribe ni vuelve a llamar a `record()`. `queueEvaluation()` corre fuera del punto de guardado, como antes.
- **Coste [L]:** las mismas lecturas, ahora dentro del punto de guardado. El informe dice que solo la factura de mostrador paga un punto de guardado más. En realidad lo paga todo cierre que resuelve vacío:
  - pagos sin `member_id`;
  - usuarios sin ficha;
  - citas sin lead.

  Son dos sentencias cortas; no cambia ninguna decisión.

### 1.3 ¿Cambia algo de lo que se registra? [C]

Sonda en la copia, sobre SQLite, ejecutada con los 8 ficheros de `0d4ed88a` y con los de `2fc75a1f`. Cubre:
- los 7 observadores tocados, más el de leads;
- pago con y sin socio;
- alta de membresía;
- ficha enlazada;
- cita creada y completada, con lead con socio y con lead solo;
- traspaso a humano;
- 12 asistencias: primera visita, una salida, dos entradas el mismo día y el hito 10;
- facturas con origen con socio, con origen sin `member_id` pero con `user_id`, con origen inexistente y con clase inexistente.

Resultado: **14 filas idénticas** (evento, `dedupe_key`, `member_id`, `marketing_lead_id`, oportunidad, payload y `occurred_at`) y **logs `commercial.*` idénticos**.
- La primera visita y el hito se anclan a `captured_at` (`2026-11-08 12:00` y `2026-11-17 12:00`), igual que antes.
- La factura se compara solo en `created`: las transiciones no se pueden comparar por §0.

### 1.4 Hallazgos de 1c

- **MENOR · pruebas:** ninguna prueba del repositorio fija el camino feliz de asistencias (`first_checkin` o hito), citas ni facturas: clave, payload y `occurred_at`.
  - Hay un único `EV_ATTENDANCE_MILESTONE` en `CommercialE2ETest.php:254`, y es una fila sembrada, no observada.
  - Las 7 pruebas nuevas solo fijan el fallo. La equivalencia la he comprobado con la sonda de §1.3, pero queda sin red.
  - Sugerencia para `tester`: añadir el camino feliz de esos tres observadores, y el de `invoice_validated`/`invoice_rejected` cuando se corrija §0.

---

## 2 · `2fc75a1f`: el escáner

### 2.1 Corrección [L, con C donde se indica]

- **Nadie se queda atrás.** `chunkById` usa paginación por clave (`id > último`, `order by id asc`), así que una fila que entra o sale del filtro durante el recorrido no desplaza a nadie:
  - lo que sale no se ve;
  - lo que entra con id menor se ve en la pasada siguiente;
  - emitir solo escribe en `commercial_events` y no cambia el filtro.
- **El techo** cuenta lo que `emit()` devuelve:
  - devuelve 1 solo si `record()` registró;
  - lo ya registrado, lo que no tiene sujeto y los fallos dan 0 y no gastan techo, así que un candidato con fallo persistente no bloquea a los demás;
  - con claves semanales, diarias o por fecha de fin, la pasada siguiente atraviesa lo ya emitido y llega a lo que no cupo (M2 lo confirma).
- **Doble emisión:**
  - dentro de una pasada es imposible: los ids son estrictamente crecientes;
  - entre pasadas la para la clave, más el índice único;
  - y `withoutOverlapping()->onOneServer()` (`routes/console.php:328-331`).
- **Recorte:** se marca solo si queda un candidato tras llegar al techo. Si se llega justo con el último, no avisa. Correcto.
- **Última entrada por trozo frente al `max()` por socio:**
  - el mismo filtro (`member_id`, `action = 'entry'`) y el mismo agregado;
  - `toBase()` aplica los ámbitos globales, y `Attendance` no tiene ninguno;
  - el valor crudo es el mismo (`pluck` frente a `max`), con el mismo `Carbon::parse` en UTC y el mismo `$cutoff` de Bogotá;
  - un socio sin visitas no aparece en el `pluck`, da `null` y se salta, igual que antes.
  - Las pruebas de los bordes de Bogotá siguen en verde [C]. Ver M3 sobre lo que NO fijan.
- **Columnas:** cada `select([...])` incluye `id` (lo necesita `chunkById`) y todo lo que leen las clausuras. El `whereHas` es un `exists` correlado, sin `join`, así que `id` no es ambiguo (verde en PG [C]).
- **Memoria:** como mucho un trozo de 500 modelos más un `pluck` de 500.
- **Consultas** [L, sin medir aquí]. El coste dominante es el recorrido secuencial de `attendances` por trozo, mientras no exista el índice por `member_id` que propone el autor (§2.5 de su informe).

| Socios vigentes | Consultas de socios | Consultas agrupadas de asistencias | Por cada inactivo |
|---|---|---|---|
| 591 | 2 | 2 | ≈ 3 (`hasTable`, lead, `exists`), más el `INSERT` si es nuevo |
| 5.000 | 11 (la última vacía) | 10 | igual |

### 2.2 Hallazgos del escáner

1. **MENOR · prueba que falta (M3 sobrevive)**, `ScanCommercialFacts.php:216` (`->where('action', 'entry')` en la consulta por trozo).
   - Quitar ese filtro deja verdes las 13 pruebas: el techo, `ScanCommercialFactsBogotaDayTest` y los dos `H9*BogotaDay*Test`. Todas las asistencias de los fixtures son `entry`.
   - **Escenario:** si alguien cambia esa consulta y cuenta las salidas, un socio cuya última fila es una salida tras el corte deja de ser inactivo, y ninguna prueba lo ve.
   - El código actual es correcto; falta la red. Para `tester`: un socio con `entry` antes del corte y `exit` después, que debe salir como inactivo.
2. **MENOR · `--dry-run`**, `ScanCommercialFacts.php:95-103` y `:333-338`. En seco, `emit()` devuelve 1 sin mirar la deduplicación. Con eso:
   - lo ya registrado SÍ gasta techo, y el aviso «lo ya registrado no cuenta para el techo» es falso en seco;
   - en seco también se escribe `commercial.scan.truncated` (warning). `h9-outreach-scope.sh:181` lanza la orden en seco en producción por tinker, así que, si una familia pasa de 500, dejará ese warning en el log de producción;
   - las líneas `[dry]` salen ahora por id y, en inactividad, son hasta 500 inactivos de entre TODOS los vigentes (antes, los inactivos de los primeros 500).

   La expresión regular del guion solo lee las líneas de resumen (`^ {2}familia +N`), que no cambian: sigue siendo compatible.
   Sugerencia: en seco, otro texto o no registrar el warning. No impide desplegar.

---

## 3 · Ejecuciones [C]

| Qué | Motor | Resultado |
|---|---|---|
| `CommercialEventRecorderPostgresTest` + `CommercialEventRecorderContainmentTest` + `ScanCommercialFactsCeilingTest` + `ScanCommercialFactsBogotaDayTest` (HEAD) | PG 14 (`UNIT_DB=ultron_studio_rev_t2`) | `OK (40 tests, 276 assertions)` |
| los mismos cuatro | SQLite | `Tests: 40, Assertions: 147, Skipped: 13` (las de solo PG), sin fallos |
| `H9MembershipExpiredBogotaDayTest` + `H9RenewalWindowBogotaDayTest` | PG y SQLite | `OK (2 tests, 47 assertions)` en los dos |
| **Rojo de 1c**: copia con los 8 ficheros de `app/` devueltos a `2fc75a1f`, `CommercialEventRecorderPostgresTest` | PG | `Tests: 13, Errors: 1, Failures: 3` (detalle debajo) |
| Semántica de puntos de guardado anidados con el mismo nombre | `psql`, PG 14 | correcta (§1.1) |
| Sonda del camino feliz, `0d4ed88a` frente a `2fc75a1f` | SQLite | 14 filas y logs idénticos (§1.3) |
| `InvoiceEmissionTest` con la bandera apagada / encendida | SQLite | `OK (12)` / `Errors: 3` (§0) |

Detalle del rojo de 1c, idéntico al del informe del autor:
- `55P03` en el pago con socio;
- «la membresía no se aplicó»;
- «la factura pendiente se encoló» (`0 is identical to 1`), por Wompi y por Nequi.

### Mutaciones mías (en la copia; cada una parte del código de HEAD y se restaura con `diff -q`)

| # | Mutación | Pruebas | Resultado |
|---|---|---|---|
| M1 | 1c: el cierre se resuelve en `doRecord()` ANTES de `isolated()` | las dos de 1c, PG | **muere**: 5 (las 4 cadenas de Wompi y Nequi y `test_un_sujeto_que_no_se_puede_resolver…`) |
| M2 | escáner: el techo cuenta lo mirado (`$emit(); $n++`) | techo + Bogotá + H9 Bogotá, PG | **muere**: 2 |
| M3 | escáner: la consulta por trozo sin `action = 'entry'` | ídem | **SOBREVIVE**: 13/13 en verde (§2.2-1) |
| M4 | escáner: trozos por desplazamiento en orden de id DESCENDENTE (`orderByDesc(id)->chunk()` en lugar de `chunkById`) | ídem | **muere**: 3 |

M1 solo la matan las pruebas de PG. En SQLite las 7 de «si el resolver falla…» siguen en verde con M1, porque el `catch` de `record()` se traga igual una excepción de PHP. Es lo que dice el docblock de esas pruebas: fijan DÓNDE se resuelve, y la de PG fija la transacción.

---

## 4 · Lo que NO he podido revisar

- **Producción, nada:**
  - ni el estado real de `COMMERCIAL_EVENTS_ENABLED` ni desde cuándo;
  - ni si la emisión con Factus está activa;
  - ni los logs de §0;
  - ni la medición del lead (0 hechos nuevos en la primera pasada), que tomo como dato suyo.
- **La sonda de §1.3 solo en SQLite**, no en PG.
- **El volumen de 5.000 socios:** razonado, no medido.
- **Ni la batería completa ni las guardas** (puerta de diff), por instrucción. Según el autor, la puerta de diff está en rojo a propósito y lista estos ficheros.
- **`pint`.**
- **El guion SQL del otro agente** (`docs/ultron-studio/ops/pagos-aprobados-sin-activar.sql`), fuera de mi alcance.
- **El diagnóstico §1c.6 del autor** (`PaymentMembershipActivator::activate()`): lo he leído; no lo he reproducido.

## 5 · Despliegue

- **Qué entra encima de `181075b3`:** `git diff 181075b3 0d4ed88a` fuera de `docs/` son 9 ficheros de `app/` y 3 de pruebas. Sin migraciones, configuración, rutas ni `composer.lock` [C]. Es solo código: nada lo impide.
- **El escáner esta noche:** no veo motivo para no desplegarlo.
  - Renovación (195), vencidas y enlaces ya cabían en 500, y ya se miraban enteros.
  - En inactividad, el lead midió 0 nuevos.
  - Con el NBA apagado no se evalúa nada.
  - El techo de 500 hechos nuevos por familia sigue frenando una avalancha.
  - El coste por pasada es del orden del actual (2 recorridos de `attendances` con 591 vigentes).
- **Vuelta atrás:** volver a `181075b3` (o revertir esos 9 ficheros de `app/`).
  - Revertir 1c reabre el Importante 1 de rev-pagos: las lecturas del resolver vuelven a correr fuera del punto de guardado.
  - Revertir el escáner vuelve a dejar sin mirar a los vigentes que no entran en los 500.
  - Los hechos ya registrados son ciertos; no hay que borrarlos.
- **Independiente del despliegue:** §0 hay que mirarlo ya en producción. La vuelta atrás de emergencia (apagar `COMMERCIAL_EVENTS_ENABLED`) también lo detiene.

# Informe · `receivable.notify_failed` a las 06:00 UTC (cuentas por cobrar)

**Rol:** debugger. **Árbol:** `_worktrees/ultron-h8/Iron-Body/backend` (rama `feat/ultron-studio-h9`). **Fecha:** 10-11-2026.
**Sin commit, sin red, sin tocar producción. El código de la aplicación NO se cambió:** la corrección necesita una decisión (§6). El único archivo nuevo en el repositorio es este informe.
**Bases usadas:** PostgreSQL 14 local y desechable `ultron_studio_dbg` (puerto 55432, con `docs/ultron-studio/ops/pgunit.sh`) y SQLite en memoria (la suite por defecto).

> Contexto: mientras trabajaba, otros agentes modificaron archivos en este mismo árbol (Outreach, StreamTicket, middleware, pruebas). No toqué ninguno de ellos.

---

## 0. Respuesta corta

1. **Quién emite el aviso y qué consulta falla.**
   - El aviso lo escribe `ReceivableDueNotifier::notify()` (`app/Services/Caja/ReceivableDueNotifier.php:77-98`). El `Log::warning('receivable.notify_failed', …)` está en la línea **92**, dentro de un `catch` que se traga el error.
   - Lo invoca `DetectOverdueReceivables::emitirTransicion()` (`app/Console/Commands/DetectOverdueReceivables.php:204`), dentro de la transacción de la línea 157, y solo cuando la transición es nueva.
   - La tarea es `ironbody:detect-overdue-receivables`, con `dailyAt('06:00')` (`routes/console.php:100-101`). `app.timezone` es `UTC` (`config/app.php:78`), así que corre a las **06:00 UTC = 01:00 en Bogotá**.
   - Las transiciones son `due_soon`, `due_today`, `overdue` y `cleared` (`DetectOverdueReceivables.php:50-53`).
   - **La consulta que falla es** `insert into "trainer_tasks" (...)` **sin `member_id`**. La hace `TrainerTask::updateOrCreate()` dentro de `avisarEntrenador()` (`ReceivableDueNotifier.php:141-153`).
2. **Causa raíz.**
   - Cuando quien debe es un **entrenador**, `avisarEntrenador()` crea una fila en `trainer_tasks` sin `member_id`.
   - Esa columna es `NOT NULL` y tiene FK a `members` (`database/migrations/2026_06_02_000001_create_trainer_tasks_table.php:26` y `:49`).
   - En una deuda de entrenador `member_id` es **siempre null por diseño** (`app/Services/Caja/ReceivableService.php:103`; en `main`, línea 98).
   - Resultado: `SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column "member_id" of relation "trainer_tasks"`. Encaja con los dos prefijos que vio el lead (`SQLSTATE[2…` y `SQLSTATE[235…`).
   - Falla **el 100 % de las veces**, en cada transición de cualquier deuda de entrenador con plazo. Está en el código desde **39fb7dd9 (13-09-2026)** y ninguna prueba lo cubre.
   - **El dato de producción que lo dispara:** una fila de `receivables` con `debtor_type = 'trainer'` (y por tanto `member_id` null) y `due_at` no nulo. Hay dos orígenes en el código:
     - un **crédito de cafetería fiado a un entrenador**, que nace siempre con el plazo por defecto (`CajaController.php:326-335` → `PaymentTerm::resolve(…, PRODUCTS)`);
     - el **piso de comisiones** con `payer = 'trainer'` cuando se le pone fecha (`TrainerCommissions.php:130`, `$vence` es opcional; `:178-194`, caja GYM).
   - Que las cuentas 216 y 121 sean de entrenador se **deduce** del código y del registro; falta confirmarlo con la consulta de solo lectura de §4.
3. **¿Afecta al Hito 9? No.**
   - No afecta a `commercial_*` / `COMMERCIAL_EVENTS_ENABLED`.
   - No afecta a los hechos `receivable.has_overdue`, `receivable.overdue_days_max` ni `receivable.count_overdue`.
   - No afecta al disparador `receivable.overdue` del motor.
   - Tampoco toca dinero: ni saldos, ni abonos, ni estados, ni retenciones. El evento `automation_events` de cada transición **sí se guarda**. Detalle en §5.
   - **Lo que sí se pierde:**
     - el aviso al entrenador (nunca se ha creado ni una tarea);
     - **la alerta interna `cuenta_por_cobrar_vencida`** cuando la deuda de un entrenador vence. Administración nunca se entera por la bandeja; la deuda sigue visible en Caja, porque allí la mora se calcula al leer.
4. **Corrección: NO aplicada, hace falta decisión.**
   - Cualquier versión cambia lo que ve el personal en la bandeja de cobranza.
   - Además, arreglar solo el NOT NULL destapa un segundo defecto, verificado: la alerta y el aviso dirían «Membresía · saldo vencido», «Su membresía está retenida» y «Contactar al socio» a propósito del piso de un entrenador (§3.4).
   - Dejo una propuesta **validada sin tocar el repositorio**, con dos pruebas de regresión: rojas sobre el código actual y verdes con la propuesta (§6, §7).

Escalados aparte:
- **security**: el registro escribe nombres y montos (§8.1).
- **architect**: un riesgo latente de transición perdida en PostgreSQL que ya demostré (§8.2). La propuesta lo cierra de paso.

---

## 1. Reproducción

**Comandos** (`<scratch>` = `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body-backend/f41baf0c-788b-4dc6-a1e0-f95e9143ec0b/scratchpad`):

```bash
psql -h 127.0.0.1 -p 55432 -U ironbody -d postgres -c "CREATE DATABASE ultron_studio_dbg;"
UNIT_DB=ultron_studio_dbg docs/ultron-studio/ops/pgunit.sh <scratch>/repro/ReceivableNotifyReproTest.php   # PostgreSQL, commits reales
php -d memory_limit=2G vendor/bin/phpunit <scratch>/repro/ReceivableNotifyReproTest.php                     # SQLite
```

**Cómo es la prueba.** `ReceivableNotifyReproTest` es temporal y vive en el scratchpad, no en el repositorio.
- Usa `DatabaseTruncation` y no `RefreshDatabase`. Con `RefreshDatabase` la transacción del comando sería anidada; aquí es raíz, como en producción.
- Crea una deuda GYM con `debtorType: TRAINER`, `blocksBenefits: false` y vencimiento hoy (o ayer), igual que el piso.
- Corre `ironbody:detect-overdue-receivables` y vuelca: el registro, las transacciones (`TransactionBeginning/Committed/RolledBack` con su nivel), `automation_events`, `trainer_tasks` y `commercial_alerts`.

**Salida observada en PostgreSQL 14** (recortada; el texto es sintético, «Piso repro»):

```
===== due_today · 1a corrida (driver pgsql) =====
  TX begin  → nivel 1
  TX begin  → nivel 2
  TX rollback → nivel 1
  LOG warning receivable.notify_failed {"receivable_id":1,"transition":"due_today","error":"SQLSTATE[23502]: Not null violation: 7 ERROR:  null value in column \"member_id\" of relation \"trainer_tasks\" violates not-null constraint\nDETAIL:  Failing row contains (1, 1, null, null, receivable_due, Tu saldo vence hoy, … (… SQL: insert into \"trainer_tasks\" (\"idempotency_key\", \"trainer_id\", \"type\", \"title\", \"body\", \"priority\", \"status\", \"metadata\", \"due_at\", \"updated_at\", \"created_at\") values (…) returning \"id\")"}
  TX commit → nivel 0
  automation_events receivable.*: ["receivable.due_today · receivable.due_today:1:2026-11-10:0.0.0"]
  trainer_tasks: 0 · commercial_alerts 0
===== due_today · 2a corrida (mismo día) =====
  TX begin  → nivel 1
  TX commit → nivel 0                     ← no se repite: el evento ya existe
===== overdue · 1a corrida =====
  … la misma SQLSTATE[23502] con "transition":"overdue" …
  automation_events: ["receivable.overdue · receivable.overdue:1:2026-11-09:0.0.0"]
  trainer_tasks: 0 · commercial_alerts 0  ← administración NO recibe la alerta
===== cleared · corrida tras el pago =====
  … la misma SQLSTATE[23502] con "transition":"cleared" …
  automation_events: [overdue…, "receivable.cleared · receivable.cleared:1:1.1.1"]
===== cleared · corrida siguiente =====
  TX begin → nivel 1 / TX commit → nivel 0 (sin aviso)
OK (2 tests, 7 assertions)
```

**En SQLite** sale lo mismo con el código propio de ese motor: `SQLSTATE[23000]: Integrity constraint violation: 19 NOT NULL constraint failed: trainer_tasks.member_id`. **No depende del motor**: la suite lo habría detectado si alguna prueba recorriera el ciclo con un entrenador. Ninguna lo hace (§2, último punto).

---

## 2. Causa raíz: la cadena, de origen a síntoma

1. **Origen del dato.** `ReceivableService::create()` (`ReceivableService.php:103`) fija `member_id = null` cuando quien debe no es socio. Es el invariante documentado en `2026_09_10_000001_add_debtor_to_receivables_table.php:19-25`. Las deudas de entrenador nacen así.
2. **Selección.** El comando toma las cuentas por fecha y estado (`DetectOverdueReceivables.php:79-81`, `:98-99`, `:123-130`) sin mirar el tipo de deudor. Eso es correcto.
3. **Transición.**
   - `emitirTransicion()` abre la transacción (`:157`), bloquea la fila (`:158`) y emite el evento con su llave (`:189-197`).
   - Solo si el evento es nuevo, llama a `notify()` (`:204`).
4. **Ruta del aviso.** `notify()` (`ReceivableDueNotifier.php:80`) → `avisarAlDeudor()` (`:106-112`) → `DebtorType::TRAINER` (`:108`) → `avisarEntrenador()` (`:135-154`) → `TrainerTask::updateOrCreate(['idempotency_key' => $llave], [trainer_id, type, title, body, priority, status, metadata, due_at])`. **No hay `member_id` y no puede haberlo**: la deuda no tiene socio.
5. **Choque con el esquema.**
   - `trainer_tasks.member_id` es `NOT NULL` (`2026_06_02_000001_create_trainer_tasks_table.php:26`) con FK a `members` (`:49`).
   - La tabla modela «una tarea para el entrenador **sobre un socio asignado**» (docblock `:8-14`). La deuda propia de un entrenador no encaja en ese modelo.
   - Ninguna migración posterior toca `trainer_tasks`.
6. **Por qué falla una vez y no todas las noches.**
   - En Laravel v12.58.0 (la versión de `composer.lock`), `updateOrCreate()` → `firstOrCreate()` → `createOrFirst()` → `withSavepointIfNeeded()` (`vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php:706-745`, `:1957-1962`).
   - Como hay una transacción abierta, el INSERT va en un **SAVEPOINT**. PostgreSQL lo rechaza con 23502, Laravel hace `ROLLBACK TO SAVEPOINT` (la traza: nivel 2 → rollback → nivel 1) y la excepción sube.
   - La transacción exterior queda sana y el **COMMIT confirma el evento con su llave**. Por eso **cada par (cuenta, transición) se registra una sola vez**.
7. **Síntoma.** El `catch` de `notify()` (`:89-97`) lo convierte en `receivable.notify_failed` con `$e->getMessage()` completo.
8. **Daño colateral.** `avisarAlDeudor()` va primero dentro del mismo `try`:
   - en `overdue` se salta `alertarAdministracion()` (`:82-84`), y **administración nunca recibe la alerta de la mora de un entrenador** (verificado: `commercial_alerts 0`);
   - en `cleared` se salta `cerrarAlerta()` (`:86-88`).
9. **Por qué no lo detectó la suite.** Ninguna prueba recorre el ciclo con un deudor entrenador:
   - `grep -rln "detect-overdue-receivables" tests` → 5 archivos de `tests/Feature/Caja`, ninguno con `DebtorType::TRAINER`;
   - las que tienen entrenadores deudores (`tests/Feature/Commissions/*`, `ThermalReceiptIssueTest`) no corren el ciclo;
   - el `catch` convierte el fallo en un warning que no rompe ninguna aserción.

---

## 3. Evidencia

### 3.1 El fallo aparece y desaparece a voluntad (contraexperimento)

Mismo escenario en la base desechable, solo que antes se quita la restricción con `ALTER TABLE trainer_tasks ALTER COLUMN member_id DROP NOT NULL`:

```
===== due_today · SIN el NOT NULL de trainer_tasks.member_id (driver pgsql) =====
  TX begin  → nivel 1 / TX begin → nivel 2 / TX commit → nivel 1 / TX commit → nivel 0
  automation_events receivable.*: ["receivable.due_today · …"]
  trainer_tasks: 1 · commercial_alerts 0          ← sin aviso de fallo; la tarea se crea
```

La restricción `NOT NULL` de `trainer_tasks.member_id` es, por sí sola, condición necesaria y suficiente del aviso.

### 3.2 Discriminantes frente al registro de producción

| Lo que predice esta causa | Lo que predeciría otra (§9) |
|---|---|
| `SQLSTATE[23502]` y `column "member_id" of relation "trainer_tasks"` | 23505 (único), 23514 (CHECK), 23503 (FK), 22001 (longitud), 25P02 (transacción abortada) |
| Cada par `(receivable_id, transition)` aparece **una sola vez** | El mismo par repitiéndose cada noche: el modo de §8.2 |
| **Sin** `realtime.emit_failed` a la misma hora | `realtime.emit_failed … 25P02` justo después (el modo de §8.2 con un socio GYM) |
| La misma cuenta recorre `due_soon` → `due_today` → `overdue` → `cleared` en días sucesivos | — |

**Lectura de las dos líneas que vio el lead.** Lo más probable es que el `10-11` corresponda a `216 due_today` y el `09-11` a `121 cleared`, una línea cada día.
- **216**: deuda de entrenador con `due_at = 2026-11-10` y sin pagar a las 01:00 de Bogotá.
- **121**: deuda de entrenador que llegó a vencerse (su evento `receivable.overdue` existe; si no, `huboMora()` en `DetectOverdueReceivables.php:218-224` no la habría dejado pasar) y que se pagó completa entre las corridas del 08-11 y el 09-11.

**Predicción comprobable.** Si 216 no se paga el 10-11 (hora de Bogotá), mañana **11-11 a las 06:00 UTC** saldrá `receivable.notify_failed {"receivable_id":216,"transition":"overdue",…}` y **no** aparecerá ninguna alerta `cuenta_por_cobrar_vencida` de 216 en el CRM. En registros anteriores debería haber también un `"receivable_id":121,"transition":"overdue"`.

### 3.3 Por qué la transacción no se pierde (y cuándo sí se perdería)

Ver la traza de §1: `nivel 2 → rollback → nivel 1 → commit → nivel 0` y el evento guardado. El SAVEPOINT viene de `createOrFirst()`, no de un diseño del notificador. El aviso al socio (`AppNotification::create`, `:121-132`) **no** va en SAVEPOINT, y ahí la historia cambia (§8.2).

### 3.4 Lo que saldría si solo se quitara el NOT NULL (verificado)

Vencida de un entrenador, en la base desechable y sin la restricción:

```
alerta.title:            Membresía · saldo vencido de $50.000
alerta.summary:          <deudor> debe $50.000 de «Piso repro». Venció el 2026-11-09 (hace 1 día). Su membresía está retenida.
alerta.suggested_action: Contactar al socio: su membresía está retenida hasta que salde.
tarea.title:             Tu membresía quedó retenida
tarea.body:              Quedan $50.000 pendientes de «Piso repro». Tu membresía se reactiva en cuanto pagues.
```

Las cinco frases son falsas para un entrenador: no es socio, no tiene membresía y su piso nace con `blocks_benefits = false`. El texto sale de `mensaje()` (`:262-267`), `alertarAdministracion()` (`:208-214`) y `resumenAlerta()` (`:285`), que usan «GYM ⇒ membresía».

---

## 4. Qué dato de producción lo provoca y cómo confirmarlo sin datos personales

**La condición** es una fila de `receivables` que cumpla `debtor_type = 'trainer'` ∧ `due_at IS NOT NULL` y que entre en alguna transición:
- `due_soon`: el día antes;
- `due_today`: el día del plazo;
- `overdue`: el día después, si sigue debiendo;
- `cleared`: cuando se salda después de haber estado vencida.

Hay dos orígenes en el código:
- el crédito de cafetería fiado a un entrenador, que **siempre** nace con plazo (`CajaController.php:326-335`; `caja.payment_terms.products`, o 15 días por `CAJA_DEFAULT_PAYMENT_TERM_DAYS`);
- el piso de comisiones con `payer = 'trainer'` y fecha (de22567c y siguientes, 08/09-10-2026).

La consulta 1 dice cuál de los dos es cada una (`es_piso`).

**Consultas de solo lectura** (no devuelven nombres, documentos ni conceptos):

```sql
-- 1) ¿Son 121 y 216 deudas de entrenador? ¿Son pisos?
SELECT r.id, r.debtor_type, r.type, r.status, (r.member_id IS NULL) AS sin_socio, r.due_at,
       EXISTS (SELECT 1 FROM trainer_commission_charges c WHERE c.receivable_id = r.id) AS es_piso
FROM receivables r WHERE r.id IN (121, 216);

-- 2) Su historial de transiciones (las llaves no llevan datos personales)
SELECT event_type, idempotency_key, created_at FROM automation_events
WHERE idempotency_key LIKE 'receivable.%:121:%' OR idempotency_key LIKE 'receivable.%:216:%'
ORDER BY id;

-- 3) ¿Se ha creado alguna vez una tarea de deuda para un entrenador? (esperado: 0)
SELECT count(*) AS tareas_de_deuda FROM trainer_tasks WHERE type = 'receivable_due';

-- 4) Deudas de entrenador vencidas hoy que administración NO tiene en la bandeja
SELECT count(*) AS vencidas_de_entrenador_sin_alerta
FROM receivables r
WHERE r.debtor_type = 'trainer' AND r.status IN ('pending', 'partially_paid')
  AND r.due_at IS NOT NULL AND r.due_at < (now() AT TIME ZONE 'America/Bogota')::date
  AND NOT EXISTS (SELECT 1 FROM commercial_alerts a WHERE a.fingerprint = 'receivable_overdue:' || r.id);
```

**Registro.** Estos comandos extraen solo fragmentos sin datos personales; los validé contra las líneas sintéticas de mis corridas:

```bash
# pares (cuenta, transición) y cuántas veces: esperado 1 por par
grep -h 'receivable.notify_failed' storage/logs/laravel*.log \
  | grep -oE '"receivable_id":[0-9]+,"transition":"[a-z_]+"' | sort | uniq -c
# código y restricción: esperado SQLSTATE[23502] y column \"member_id\" of relation \"trainer_tasks\"
grep -h 'receivable.notify_failed' storage/logs/laravel*.log \
  | grep -oE 'SQLSTATE\[[0-9A-Z]+\]|column \\"[a-z_]+\\" of relation \\"[a-z_]+\\"' | sort | uniq -c
```

**No copien la línea completa** a informes ni chats: lleva nombres (§8.1).

---

## 5. ¿Afecta al Hito 9?

**No.** Ni a los eventos comerciales ni a los hechos o disparadores de cartera del motor de automatización. Tampoco al dinero.

| Consumidor | Qué lee | ¿Afectado? | Evidencia |
|---|---|---|---|
| Hechos de cartera H9 (`receivable.has_overdue`, `.overdue_days_max`, `.count_overdue`) | `receivables` directamente, al evaluar, filtrando `whereIn('member_id', …)` | **No** | `app/Services/Outreach/Facts/ReceivableFacts.php:31` (`TABLES = ['receivables','receivable_payments']`), `:41-48` |
| Disparador H9 `receivable.overdue` | `ReceivableFacts::overdueBetween()` sobre `receivables`, con `whereNotNull('member_id')`; su llave es `rcv:{receivable_id}:{generation}` | **No** | `TriggerPrefilters.php:87`, `ReceivableFacts.php:84-125` (`:96`), `OutreachCatalog.php:480` |
| Eventos comerciales (`commercial_events`, `COMMERCIAL_EVENTS_ENABLED`) | Observadores de `PaymentTransaction`, `User`, `Member`, `MarketingLead`, `MarketingAppointment`, `MarketingConversation`, `Attendance`, `ElectronicInvoice` | **No**: ninguno observa `Receivable`, `ReceivablePayment`, `TrainerTask`, `AppNotification`, `CommercialAlert` ni `AutomationEvent`; y el flag está apagado en producción (R9-8) | `CommercialEventServiceProvider.php:45-52`, `EngineFacts.php:32` |
| Disparadores y CHECK del esquema H9 | Solo tablas `outreach_*` | **No** | `2026_11_12_100002_create_outreach_tables.php:143-169` |
| `automation_events` del canal viejo (`receivable.*`, hacia n8n si `automation.enabled`) | El evento de cada transición | **No**: se guarda gracias al SAVEPOINT (§3.3) | traza §1 |
| `TrainerTaskService::createFromEvent` | `receivable.*` no está en `EVENT_MAP` | **No** (no hace nada) | `TrainerTaskService.php:37-68`, `:92` |
| Dinero: saldos, abonos, estado, retención | — | **No**: el notificador corre después del dinero y no lo escribe; la retención se calcula al leer | `MembershipFinancialStanding.php:98`, `Receivable.php:190-230` |

**Lo único que se pierde** es el aviso al entrenador (cero tareas desde el 13-09) y la alerta interna de mora de las deudas de entrenador. Las deudas de entrenador, además, **quedan fuera del H9 por diseño**: no tienen `member_id`.

---

## 6. Corrección propuesta: NO aplicada; decisión que se pide

**Dónde está la causa:** `app/Services/Caja/ReceivableDueNotifier.php:108` y `:135-154`, contra `database/migrations/2026_06_02_000001_create_trainer_tasks_table.php:26`.

**Por qué no la apliqué.** Cualquier arreglo de la causa hace que, desde la siguiente corrida, las moras de entrenador abran alertas nuevas en la bandeja de cobranza del CRM. Además, sin tocar el texto, esas alertas dirían «Membresía… su membresía está retenida» (§3.4). Es un cambio visible en el área de dinero y hay que elegir el canal. Es exactamente el caso en que el encargo pide describir y no aplicar.

### Decisión 1: ¿el entrenador debe recibir un aviso personal de su propia deuda?

- **Opción B (recomendada; sin esquema, un solo archivo).** La deuda de un entrenador se trata como la de administración o recepción: sin aviso personal, solo con la alerta interna.
  - Se quita la rama `DebtorType::TRAINER` (`:108`) y el método `avisarEntrenador()` (`:135-154`).
  - Pierde poco en la práctica: hoy las tareas del entrenador solo se consultan desde el CRM de administración (`routes/api.php:1901-1910`, `TrainerTaskController`; no hay ruta para el entrenador), así que el «aviso personal» lo leería el mismo personal que ve la alerta.
  - Ese aviso **nunca ha llegado** a crearse.
- **Opción A (si sí lo quieren).**
  - Migración para que `trainer_tasks.member_id` admita null. Es de **database-specialist**: en PostgreSQL es `ALTER TABLE trainer_tasks ALTER COLUMN member_id DROP NOT NULL`, solo catálogo, con un bloqueo de un instante; la vuelta atrás solo es posible si no quedan filas nulas.
  - Corregir el texto de `mensaje()` (`:262-267`) para quien no es socio.
  - Que **frontend** verifique que el CRM soporta una tarea con `member_id`/`member_name` en null. `TrainerTask::toPublicArray()` ya lo devuelve null sin romper, pero el submódulo `frontend` no está en este worktree y no pude comprobarlo.

### Lo que va con cualquiera de las dos opciones (incluido en la propuesta validada)

1. **Canales independientes, cada uno en su SAVEPOINT** (`notify()`, `:79-97`). Que falle el aviso al deudor no puede dejar a administración sin su alerta ni, en PostgreSQL, llevarse el evento de la transición (cierra §8.2).
2. **Texto por tipo de deudor** en la alerta (`:208-214` y `:285`): «Membresía» y «su membresía está retenida» solo si es GYM **y** el deudor es socio. Si no, «Gimnasio · saldo vencido de …» y «Contactar al deudor para cobrar el saldo del gimnasio». Esto también corrige las deudas GYM de administración o recepción, que hoy salen como «Membresía».

**Propuesta B completa:** un solo archivo, +62/−49 líneas. El diff está en el Anexo A y en `<scratch>/propuesta/propuesta-B.patch`.

- **Despliegue:** solo backend; sin migración y sin cambio de configuración.
- **Vuelta atrás:** revertir ese archivo. Las alertas creadas mientras tanto son legítimas y se quedan.
- **Efecto esperado desde la siguiente corrida de las 06:00 UTC:**
  - las deudas de entrenador dejan de producir `receivable.notify_failed`;
  - cada **nueva** mora de entrenador abre su alerta «Gimnasio · saldo vencido de …» (o «Cafetería · …»);
  - las moras que **ya** pasaron su transición no tendrán alerta retroactiva. Si se quiere, hace falta una carga única; la consulta 4 de §4 dice cuántas son.

### Pruebas de regresión (escritas; entran CON la corrección)

Las dos están en el Anexo B. Pido a **tester** que las incorpore cuando se decida.

1. `CajaDineroYAvisosTest::test_28_una_deuda_de_entrenador_vencida_avisa_sin_fallar_y_alerta_a_administracion` (SQLite, suite normal).
   - Afirma: 1 evento `receivable.overdue`, **ningún** `receivable.notify_failed`, una alerta y ningún «membresía» en su texto.
   - **Roja sobre el código actual** por el motivo correcto: `'overdue · SQLSTATE[23000]: … NOT NULL constraint failed: trainer_ta…'`. **Verde con la propuesta.**
2. `DeudaRegresionPostgresPropuestaTest::test_si_falla_el_aviso_al_socio_la_transicion_no_se_pierde` (PostgreSQL, `DatabaseTruncation`, mismo patrón que `DeletedPlanPostgresTest`).
   - Fuerza un fallo **real** de la base al guardar el aviso del socio (FK, desde un `creating`) y corre el ciclo dos veces.
   - Afirma: 1 evento y 1 solo aviso fallido.
   - **Roja sobre el código actual:** `Failed asserting that 0 is identical to 1`, es decir, el evento se perdió. **Verde con la propuesta.**

---

## 7. Verificación ejecutada

| Comando | Resultado |
|---|---|
| `pgunit.sh <scratch>/repro/ReceivableNotifyReproTest.php` (PG, código actual) | Reproduce: `SQLSTATE[23502]` en `due_today`, `overdue` y `cleared`; evento guardado; `commercial_alerts 0` en `overdue` |
| `phpunit <scratch>/repro/ReceivableNotifyReproTest.php` (SQLite, código actual) | Reproduce: `SQLSTATE[23000] … NOT NULL constraint failed: trainer_tasks.member_id` |
| `… --filter test_c_` (PG, sin el NOT NULL) | Desaparece: `trainer_tasks: 1`, sin aviso de fallo |
| `… --filter test_e_` (PG, sin el NOT NULL, vencida) | Alerta y tarea con texto de «membresía» (§3.4) |
| `… --filter test_d_` (PG, aviso al socio forzado a fallar) | `automation_events: []` y el aviso se repite en la 2.ª corrida (§8.2) |
| `phpunit tests/Feature/Caja` (SQLite, código actual) | `OK (690 tests, 12335 assertions)` |
| `pgunit.sh tests/Feature/Caja/CajaDineroYAvisosTest.php tests/Feature/Caja/OverdueReceivablesTest.php` (PG, código actual) | `OK (68 tests, 245 assertions)`: el camino del socio funciona en PostgreSQL |
| Regresión 1, código actual / propuesta (SQLite) | **Roja** (motivo correcto) / **verde** `OK (1 test, 5 assertions)` |
| Regresión 2, código actual / propuesta (PG) | **Roja** `0 is identical to 1` / **verde** `OK (1 test, 5 assertions)` |
| `phpunit --bootstrap <scratch>/propuesta/bootstrap-propuesta.php tests/Feature/Caja` (SQLite, propuesta) | `Tests: 690, Assertions: 12332, Failures: 1`: el fallo es **ambiental** y no viene de la propuesta (§7.1) |
| `pgunit.sh --bootstrap … CajaDineroYAvisosTest OverdueReceivablesTest ReceivableCancellationTest` (PG, propuesta) | `OK (105 tests, 408 assertions)` |
| `pgunit.sh --bootstrap … --filter 'test_a_\|test_b_' ReceivableNotifyReproTest` (PG, propuesta) | Ciclo del entrenador sin ningún `notify_failed`; eventos guardados; `due_today` sin alerta (correcto: un recordatorio no alerta al equipo); `overdue` → `commercial_alerts 1`; `cleared` → la alerta sigue contada porque `cerrarAlerta()` la marca resuelta, no la borra (el estado no lo imprimí; el cierre lo cubre `test_22`, en verde) |

**Cómo se validó la propuesta sin tocar el repositorio.** `bootstrap-propuesta.php` carga el autoloader y, antes de que nadie la use, define la variante de `ReceivableDueNotifier` del scratchpad. La corrida imprime de dónde la cargó: `[propuesta] ReceivableDueNotifier desde: <scratch>/propuesta/ReceivableDueNotifier.php`. `php -l` sale limpio.

### 7.1 Suite de Caja con la propuesta: el único fallo no es de la propuesta

- Falla `ThermalReceiptPrintTest::test_mas_de_50_abonos_se_rechazan_sin_validar_la_lista`: `Failed asserting that 3.1201910972595215 is less than 1.0` (`tests/Feature/Caja/ThermalReceiptPrintTest.php:367`).
- Es una aserción de **tiempo de reloj**: la petición con 20 000 ids debe tardar menos de 1 s.
- Corrida aislada **con el código original** (sin el bootstrap) falla igual. En ese momento la máquina tenía una carga media de 42-47, por un E2E y otra suite de otras sesiones.
- En la línea base (código original, menos carga) la misma prueba pasó: `OK (690 tests, 12335 assertions)`.
- Las 3 aserciones de diferencia (12 332 frente a 12 335) son las que esa prueba deja sin ejecutar al fallar en la línea 367.
- Las demás 689 pasan con la propuesta.

---

## 8. Hallazgos adyacentes (no tocados)

### 8.1 El registro escribe datos personales → **security**

- `notify()` registra `'error' => $e->getMessage()` (`:95`). En una `QueryException` eso incluye el `DETAIL: Failing row contains (…)` de PostgreSQL y el SQL **con sus valores**: título, cuerpo y `metadata` con el concepto y el saldo.
- El concepto de un piso es `'Piso de entrenador · {nombre del entrenador} → {nombre del cliente} · {mes} de {año}'` (`app/Services/Commissions/TrainerCommissions.php:400-410`). El de un crédito de cafetería lleva los productos y el código de venta, sin nombres (`CajaController.php:669-681`).
- Por eso, si la cuenta es un piso, la línea `receivable.notify_failed` de producción **lleva el nombre de un entrenador, el de un cliente y un monto**, en claro y en `storage/logs`. Si es un crédito de cafetería, lleva productos, montos y el `trainer_id`.
- Otros puntos del código ya registran solo la clase (`AutomationEventService.php:66-69`, `TrainerTaskService.php:119-122`).
- **Propuesta:** registrar el SQLSTATE y la clase, no el mensaje.
- **Decisiones de security:** si se depuran o recortan los registros existentes, y quién los ha leído. Con la corrección B el aviso deja de producirse para entrenadores, pero el `catch` seguiría escribiendo el mensaje completo ante cualquier otro fallo.

### 8.2 Riesgo latente: un aviso fallido puede borrar la transición en PostgreSQL → **architect**

- `notify()` corre **dentro** de la transacción del comando y su `catch` atrapa la excepción, pero no puede «desabortar» la transacción.
- Si falla una sentencia que **no** va en SAVEPOINT, como el `AppNotification::create` del socio:
  - PostgreSQL deja la transacción abortada;
  - el `RealtimeEvents::emit` posterior falla con `25P02`;
  - el **COMMIT termina en ROLLBACK sin error**, porque PDO no lo reporta;
  - el evento y su llave se pierden y la transición se reintenta cada noche.
- Lo verifiqué con un fallo natural de FK (regresión 2) y con un CHECK forzado (`test_d_`): `automation_events: []` y el aviso repetido en cada corrida.
- **Hoy no está pasando:** el fallo real es el del entrenador, y ese va en SAVEPOINT.
- La propuesta lo cierra con el SAVEPOINT por canal. La alternativa de diseño, avisar después del COMMIT, es más grande y es de architect.

### 8.3 «Membresía retenida» aunque la deuda no retenga → backend / producto

- `mensaje()` y `resumenAlerta()` dicen «retenida» para toda deuda GYM.
- `MembershipFinancialStanding.php:98` solo retiene si `blocks_benefits` es true (o null). Un socio con un piso que asume él pero que **no** castiga (`blocks_app = false`) recibe un aviso inexacto.
- No lo toqué porque cambia texto que ven los socios; la propuesta B solo corrige a quien no es socio.

---

## 9. Hipótesis descartadas

| Hipótesis | Por qué cae |
|---|---|
| 23505 (único) en `trainer_tasks.idempotency_key` o `commercial_alerts.fingerprint` | `updateOrCreate` busca primero y `createOrFirst` ya atrapa la violación de único; `alertarAdministracion` también busca primero; y ni `due_today` ni `cleared` llegan a crear alertas |
| 23514 (CHECK) en tablas de avisos | Ninguna migración pone CHECK ni disparadores en `app_notifications`, `trainer_tasks` ni `commercial_alerts`; los del H9 son de `outreach_*` |
| 23503 (FK) | PostgreSQL comprueba el NOT NULL antes que la FK; y en el camino del socio, `receivables.member_id` tiene FK `RESTRICT` a `members` |
| 22001 por la longitud de `receivable_due_today` | `app_notifications.type` es `varchar(255)` |
| 25P02 (transacción abortada) en la línea de 216 | Con el SAVEPOINT la transacción no queda abortada, y `createFromEvent` no hace nada con `receivable.*`. Solo aparecería en el modo de §8.2, que deja huellas distintas (§3.2) |
| Lo introdujo el despliegue del H9 | El defecto existe desde 39fb7dd9 (13-09-2026); los archivos implicados no cambian entre `main` y esta rama (`git diff main` vacío en notificador, comando, migración y modelo); `main` tiene el mismo invariante (`ReceivableService.php:98`) |
| El mismo aviso se repite cada noche | Con el SAVEPOINT el evento se guarda y no se reintenta (2.ª corrida limpia en §1). Mi lectura del registro es una línea por día y por par; si el lead ve el **mismo par** en dos días, es el modo de §8.2 y hay que mirarlo aparte |

## 10. Pendiente y riesgos abiertos

- **Confirmar en producción**, con las consultas y los `grep` de §4, que 121 y 216 son deudas de entrenador y que el código es 23502. No tengo acceso al servidor: la identificación de esas dos filas es una **deducción**, no un hecho comprobado.
- **Frontend del CRM** (solo para la opción A): no está en este worktree.
- **Alertas retroactivas** de las moras de entrenador que ya pasaron: decisión del propietario (consulta 4 de §4).
- **§8.1** a security y **§8.2** a architect.

## 11. Artefactos y limpieza

- Scratchpad (no está en el repositorio): `<scratch>/repro/ReceivableNotifyReproTest.php` (reproducción, pruebas a–e), `<scratch>/repro/DeudaEntrenadorRegresionPropuestaTest.php` y `<scratch>/repro/DeudaRegresionPostgresPropuestaTest.php` (regresiones), `<scratch>/propuesta/ReceivableDueNotifier.php`, `bootstrap-propuesta.php` y `propuesta-B.patch`.
- Instrumentación temporal en el código de la aplicación: **ninguna**. Todo lo marcado `[DEBUG-REPRO]` o `[DEBUG-PROPUESTA]` vive en el scratchpad.
- Base desechable `ultron_studio_dbg`: **borrada al terminar** (`DROP DATABASE`, 0 conexiones abiertas), porque los experimentos le alteraron el esquema. Para repetir la reproducción: `psql -h 127.0.0.1 -p 55432 -U ironbody -d postgres -c "CREATE DATABASE ultron_studio_dbg;"` y los comandos de §1.
- `storage/logs/laravel.log` local tiene 9+ líneas `receivable.notify_failed` de mis corridas, con datos sintéticos. No toqué el archivo porque es compartido.

---

## Anexo A · Propuesta B (diff contra `HEAD`, NO aplicado)

Validado como se describe en §7, cargando la copia del scratchpad con `bootstrap-propuesta.php`. Copia del diff: `<scratch>/propuesta/propuesta-B.patch`.

```diff
--- a/backend/app/Services/Caja/ReceivableDueNotifier.php
+++ b/backend/app/Services/Caja/ReceivableDueNotifier.php
@@ -7,23 +7,24 @@
 use App\Models\AppNotification;
 use App\Models\CommercialAlert;
 use App\Models\Receivable;
-use App\Models\TrainerTask;
 use App\Services\Billing\Money;
 use Illuminate\Support\Carbon;
+use Illuminate\Support\Facades\DB;
 use Illuminate\Support\Facades\Log;
 
 /**
  * Avisa de una deuda: al que debe, y a quien tiene que cobrarla.
  *
  * NO INVENTA NINGÚN CANAL. Usa los que ya existen —notificaciones in-app del
- * socio, tareas del entrenador, alertas comerciales del CRM— porque un aviso en
- * un sitio que nadie mira no es un aviso. Y NO usa SMS: el control de coste de
- * Twilio se acaba de cerrar y mandar recordatorios por ahí lo desharía.
+ * socio, alertas comerciales del CRM— porque un aviso en un sitio que nadie
+ * mira no es un aviso. Y NO usa SMS: el control de coste de Twilio se acaba de
+ * cerrar y mandar recordatorios por ahí lo desharía.
  *
- * QUIÉN RECIBE QUÉ. Al deudor se le avisa de su propia deuda por el canal que
- * tenga: el socio en la app, el entrenador en su bandeja. A un administrador o a
- * recepción no se les manda un aviso personal —no tienen app— y su deuda se ve
- * en la alerta interna como cualquier otra.
+ * QUIÉN RECIBE QUÉ. Al socio se le avisa de su propia deuda en la app. A un
+ * entrenador, a un administrador o a recepción no se les manda un aviso
+ * personal —no tienen bandeja de avisos propia: `trainer_tasks` es una tarea
+ * del entrenador SOBRE un socio— y su deuda se ve en la alerta interna como
+ * cualquier otra.
  *
  * A administración se le avisa SOLO cuando algo vence. Recordar que «vence
  * mañana» es asunto del deudor; si se alertara al equipo en cada recordatorio,
@@ -76,19 +77,34 @@
 
     public function notify(Receivable $cuenta, string $transicion, Carbon $hoy, string $llave): void
     {
-        try {
-            $this->avisarAlDeudor($cuenta, $transicion, $hoy, $llave);
+        // Cada canal por su lado: que falle el aviso al deudor no puede dejar a
+        // administración sin su alerta.
+        $this->intentar($cuenta, $transicion, fn () => $this->avisarAlDeudor($cuenta, $transicion, $hoy));
 
-            if ($transicion === self::OVERDUE) {
-                $this->alertarAdministracion($cuenta, $hoy);
-            }
+        if ($transicion === self::OVERDUE) {
+            $this->intentar($cuenta, $transicion, fn () => $this->alertarAdministracion($cuenta, $hoy));
+        }
 
-            if ($transicion === self::CLEARED) {
-                $this->cerrarAlerta($cuenta);
-            }
+        if ($transicion === self::CLEARED) {
+            $this->intentar($cuenta, $transicion, fn () => $this->cerrarAlerta($cuenta));
+        }
+    }
+
+    /**
+     * Un aviso que falla no puede tumbar la detección: el estado de la deuda es
+     * la verdad, la notificación solo la cuenta.
+     *
+     * Y no basta con atrapar la excepción. El comando llama aquí DENTRO de su
+     * transacción, y en PostgreSQL una sentencia fallida la deja abortada: el
+     * COMMIT acabaría en ROLLBACK sin decir nada y se llevaría el evento con su
+     * llave. Por eso cada canal va en su propio SAVEPOINT (`DB::transaction`
+     * anidada): si falla, se deshace ese paso y nada más.
+     */
+    private function intentar(Receivable $cuenta, string $transicion, \Closure $paso): void
+    {
+        try {
+            DB::transaction(fn () => $paso());
         } catch (\Throwable $e) {
-            // Un aviso que falla no puede tumbar la detección: el estado de la
-            // deuda es la verdad, la notificación solo la cuenta.
             Log::warning('receivable.notify_failed', [
                 'receivable_id' => $cuenta->id,
                 'transition' => $transicion,
@@ -99,15 +115,16 @@
 
     // ── El deudor ────────────────────────────────────────────────────────────
 
-    private function avisarAlDeudor(Receivable $cuenta, string $transicion, Carbon $hoy, string $llave): void
+    private function avisarAlDeudor(Receivable $cuenta, string $transicion, Carbon $hoy): void
     {
         [$titulo, $cuerpo] = $this->mensaje($cuenta, $transicion, $hoy);
 
         match ($cuenta->debtor_type) {
             DebtorType::MEMBER => $this->avisarSocio($cuenta, $transicion, $titulo, $cuerpo),
-            DebtorType::TRAINER => $this->avisarEntrenador($cuenta, $titulo, $cuerpo, $llave),
-            // Administración y recepción no tienen bandeja personal: su deuda
-            // aparece en la alerta interna, que es donde ya miran.
+            // Administración, recepción y entrenadores no tienen bandeja personal
+            // de avisos: su deuda aparece en la alerta interna, que es donde ya
+            // miran. (`trainer_tasks` es una tarea del entrenador SOBRE un socio
+            // y exige `member_id`; una deuda propia del entrenador no lo tiene.)
             default => null,
         };
     }
@@ -132,27 +149,6 @@
         ]);
     }
 
-    private function avisarEntrenador(Receivable $cuenta, string $titulo, string $cuerpo, string $llave): void
-    {
-        if ($cuenta->debtor_id === null) {
-            return;
-        }
-
-        TrainerTask::updateOrCreate(
-            ['idempotency_key' => $llave],
-            [
-                'trainer_id' => $cuenta->debtor_id,
-                'type' => 'receivable_due',
-                'title' => $titulo,
-                'body' => $cuerpo,
-                'priority' => 'normal',
-                'status' => TrainerTask::STATUS_PENDING,
-                'metadata' => $this->carga($cuenta),
-                'due_at' => $cuenta->due_at,
-            ],
-        );
-    }
-
     // ── Administración ───────────────────────────────────────────────────────
 
     /**
@@ -205,13 +201,18 @@
             'severity' => $this->severidad($dias),
             'status' => CommercialAlert::STATUS_OPEN,
             'member_id' => $cuenta->member_id,
-            'title' => ($cuenta->type === CashShiftType::GYM ? 'Membresía' : 'Cafetería')
-                .' · saldo vencido de '.$this->money($saldo),
+            'title' => match (true) {
+                $this->esMembresia($cuenta) => 'Membresía',
+                $cuenta->type === CashShiftType::GYM => 'Gimnasio',
+                default => 'Cafetería',
+            }.' · saldo vencido de '.$this->money($saldo),
             'summary' => $this->resumenAlerta($deudor, $saldo, $dias, $cuenta),
             'evidence' => $this->carga($cuenta),
-            'suggested_action' => $cuenta->type === CashShiftType::GYM
-                ? 'Contactar al socio: su membresía está retenida hasta que salde.'
-                : 'Contactar al deudor para cobrar el crédito de cafetería.',
+            'suggested_action' => match (true) {
+                $this->esMembresia($cuenta) => 'Contactar al socio: su membresía está retenida hasta que salde.',
+                $cuenta->type === CashShiftType::GYM => 'Contactar al deudor para cobrar el saldo del gimnasio.',
+                default => 'Contactar al deudor para cobrar el crédito de cafetería.',
+            },
             'opportunity_value' => $saldo->toFloat(),
             'detected_at' => now(),
             'due_at' => $cuenta->due_at,
@@ -282,10 +283,20 @@
             optional($cuenta->due_at)->toDateString() ?? 'sin fecha',
             $dias,
             $dias === 1 ? '' : 's',
-            $cuenta->type === CashShiftType::GYM ? ' Su membresía está retenida.' : '',
+            $this->esMembresia($cuenta) ? ' Su membresía está retenida.' : '',
         );
     }
 
+    /**
+     * ¿Es la membresía de un socio? Solo entonces hay algo que retener: el piso
+     * de un entrenador también es deuda del gimnasio, pero quien debe no es
+     * socio y no tiene membresía.
+     */
+    private function esMembresia(Receivable $cuenta): bool
+    {
+        return $cuenta->type === CashShiftType::GYM && $cuenta->debtor_type === DebtorType::MEMBER;
+    }
+
     /** Cuanto más tiempo lleva vencida, más alto sube en la bandeja. */
     private function severidad(int $dias): string
     {
```

## Anexo B · Pruebas de regresión (entran CON la corrección)

### B.1 · En `tests/Feature/Caja/CajaDineroYAvisosTest.php`, detrás de `test_27`

Usa los ayudantes de la clase (`$this->cajero`, `correrCiclo()`, `eventos()`). Imports nuevos: `App\Models\Trainer`, `App\Models\TrainerTask`, `Illuminate\Log\Events\MessageLogged`, `Illuminate\Support\Facades\Event`. La versión corrida (roja/verde, §7) es `<scratch>/repro/DeudaEntrenadorRegresionPropuestaTest.php`, con las mismas aserciones salvo la última, que depende de la opción.

```php
    // ── 28 · Deudor entrenador (informe-receivables-notify.md) ──────────────

    public function test_28_una_deuda_de_entrenador_vencida_avisa_sin_fallar_y_alerta_a_administracion(): void
    {
        $fallidos = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$fallidos): void {
            if ($m->message === 'receivable.notify_failed') {
                $fallidos[] = $m->context['transition'].' · '.substr((string) ($m->context['error'] ?? ''), 0, 90);
            }
        });
        $entrenador = Trainer::create([
            'full_name' => 'Entrenador Piso', 'document' => (string) random_int(10000000, 99999999), 'status' => 'active',
        ]);
        app(ReceivableService::class)->create(
            debtorType: DebtorType::TRAINER, debtorId: $entrenador->id,
            type: CashShiftType::GYM, concept: 'Piso de octubre',
            total: Money::fromAmount(50000), actor: $this->cajero,
            dueAt: Receivable::businessToday()->subDay(), blocksBenefits: false,
        );

        $this->correrCiclo();

        $this->assertSame(1, $this->eventos('receivable.overdue'));
        $this->assertSame([], $fallidos, 'Avisar a un entrenador no puede reventar contra trainer_tasks.member_id.');
        $alerta = CommercialAlert::where('type', Notifier::ALERT_TYPE)->first();
        $this->assertNotNull($alerta, 'Administración tiene que enterarse también de la mora de un entrenador.');
        $this->assertStringNotContainsStringIgnoringCase('membresía', $alerta->title.' '.$alerta->summary.' '.$alerta->suggested_action,
            'Un entrenador no tiene membresía que retener.');
        // Según la opción elegida en §6:
        //   B → $this->assertSame(0, TrainerTask::count());
        //   A → $this->assertSame(1, TrainerTask::whereNull('member_id')->where('trainer_id', $entrenador->id)->count());
    }
```

### B.2 · `tests/Feature/Caja/ReceivableNotifierPostgresTest.php` (nuevo, solo PostgreSQL)

Con commits reales (`DatabaseTruncation`), igual que `tests/Feature/Plans/DeletedPlanPostgresTest.php`. Se corre con `UNIT_DB=ultron_studio_<algo> docs/ultron-studio/ops/pgunit.sh tests/Feature/Caja/ReceivableNotifierPostgresTest.php`. En SQLite se omite por la misma razón que esa clase: allí una sentencia fallida no aborta la transacción, así que el caso no existe.

```php
<?php

namespace Tests\Feature\Caja;

use App\Enums\CashShiftType;
use App\Enums\DebtorType;
use App\Models\Admin;
use App\Models\AppNotification;
use App\Models\AutomationEvent;
use App\Models\Member;
use App\Models\Receivable;
use App\Models\User;
use App\Services\Billing\Money;
use App\Services\Caja\ReceivableService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Un aviso que falla no puede llevarse la transición (informe-receivables-notify.md §8.2).
 *
 * En PostgreSQL una sentencia fallida deja abortada la transacción del comando
 * y su COMMIT acaba en ROLLBACK sin error: el evento y su llave desaparecen y la
 * misma transición se reintenta cada noche.
 */
#[Group('pgsql')]
class ReceivableNotifierPostgresTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        if ((getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? $_SERVER['DB_CONNECTION'] ?? null)) !== 'pgsql') {
            $this->markTestSkipped('Solo en PostgreSQL: en SQLite una sentencia fallida no aborta la transacción.');
        }
        parent::setUp();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->truncateDatabaseTables();
            RefreshDatabaseState::$migrated = false;
        }
        parent::tearDown();
    }

    public function test_si_falla_el_aviso_al_socio_la_transicion_no_se_pierde(): void
    {
        $fallidos = 0;
        Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$fallidos): void {
            $fallidos += $m->message === 'receivable.notify_failed' ? 1 : 0;
        });
        $cajero = Admin::create(['name' => 'Caja', 'email' => 'c-'.uniqid().'@ironbody.test', 'password' => 'secret-password', 'role' => Admin::ROLE_SUPER_ADMIN, 'status' => 'active']);
        $user = User::create(['name' => 'Socio', 'email' => 's-'.uniqid().'@t.test', 'password' => 'x', 'plan' => 'Premium', 'membershipEndDate' => now()->addDays(30)->toDateString()]);
        $socio = Member::create(['user_id' => $user->id, 'full_name' => 'Socio Prueba', 'document_number' => (string) random_int(10000000, 99999999), 'access_hash' => 'tok-'.uniqid(), 'status' => Member::STATUS_ACTIVE]);
        app(ReceivableService::class)->create(
            debtorType: DebtorType::MEMBER, debtorId: $socio->id, type: CashShiftType::GYM, concept: 'Plan a plazos',
            total: Money::fromAmount(50000), actor: $cajero, dueAt: Receivable::businessToday(),
        );
        // Un fallo REAL de la base al guardar el aviso (llave foránea), no una
        // excepción de PHP: es lo que deja abortada la transacción en PostgreSQL.
        AppNotification::creating(fn (AppNotification $n) => $n->member_id = 2_000_000_000);

        $this->artisan('ironbody:detect-overdue-receivables')->assertSuccessful();
        $this->artisan('ironbody:detect-overdue-receivables')->assertSuccessful();

        $this->assertSame(1, AutomationEvent::where('event_type', 'receivable.due_today')->count(),
            'El aviso que falla no puede llevarse el evento: el COMMIT de una transacción abortada es un ROLLBACK mudo.');
        $this->assertSame(1, $fallidos, 'Una transición, un aviso fallido; no uno por corrida.');
        $this->assertSame('pgsql', DB::connection()->getDriverName());
    }
}
```

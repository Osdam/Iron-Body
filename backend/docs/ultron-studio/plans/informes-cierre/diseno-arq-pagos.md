# Apartado 12 · Pagos, membresías y eventos: diseño mínimo (arq-pagos, 22-11-2026)
Árbol `_worktrees/ultron-h8/Iron-Body/backend`, HEAD `4bed90a5`, solo lectura; producción sin tocar. **[C]** comprobado leyendo código o historial · **[H]** hipótesis o dato ajeno no comprobado por mí.

## 1. El activador dentro de la transacción del pago
**(a) Hechos**
- [C] `PaymentMembershipActivator::activate()` envuelve todo su cuerpo (`PaymentMembershipActivator.php:38-115`) en un `catch (Throwable)` que solo hace `Log::warning` (`:116-122`). Sin cambios desde `ce390bfd` (19-09).
- [C] Lo ejecutan DENTRO de la transacción del pago: Wompi `WompiTransactionService.php:254` (`DB::transaction`) → `:366` `save()` → `:370` `activate()` → `:425` `return $fresh->fresh()`; Nequi directo `NequiPushPaymentService.php:459` → `:482` → `:497`, sin ninguna sentencia propia después. El tercer llamador, `PaymentClaimAcceptanceService.php:159`, va FUERA de su transacción (`:99-147`) y ya convierte el fallo en `ChannelLog::error` (`:187-197`): sin riesgo de 25P02.
- [C] La activación solo corre al ENTRAR en `approved` (`WompiTransactionService.php:369`; Nequi sale en `:463-465`) y la reconciliación solo retoma pendientes (`WompiReconcile.php:26`, `reconcilePending`): nada la vuelve a intentar.
- [C] Desenlace según dónde caiga el fallo:
  - **Dentro de un punto de guardado de Laravel**: el INSERT de `Payment::firstOrCreate` (`:63`), `MembershipPeriod::apply()` (`MembershipPeriod.php:144-163`, `DB::transaction(…, 3)` anidado) o una excepción PHP en `applyLocked()`/`PlanUpgrades`. Vuelve al punto de guardado, el `catch` se lo traga y el pago queda **`approved` sin fila `payments` o sin periodo, en silencio y para siempre** (Wompi y Nequi). Medido en PG 14 por db-comercial (`informe-db-comercial.md` §1c.6).
  - **Fuera de un punto de guardado**: `forceFill()->save()` (`:42`), `Member::…->update()` (`:89`), `marcarFacturaSolicitada()` desde `:114`, o un interbloqueo en el `MembershipPeriod` anidado (Laravel no vuelve al punto de guardado). La transacción queda abortada (25P02). Wompi: `:425` lanza, se deshace todo, 500 y el pago sigue `pending`; el reintento del webhook o la reconciliación de 5 min lo aprueban y activan bien. Nequi directo: el COMMIT responde ROLLBACK sin excepción y **la aprobación se pierde en silencio** (§1c.6).
- [C] Lo desplegado el 12-11 (`181075b3` + `0d4ed88a`, en producción desde las 03:14 UTC, L9-115) cierra el vector más frecuente. Los 9 observadores comerciales registran y resuelven el sujeto dentro de `CommercialEventRecorder::isolated()` (`CommercialEventRecorder.php:251-265`; p. ej. `PaymentTransactionCommercialObserver.php:86`). La memoria «ocho observadores siguen con el riesgo» es del 11-11 00:51, anterior a la parte 1c: está desfasada. **No cubre** el `catch` del propio activador ni las capturas vecinas que corren en la misma transacción. Son `MarketingPaymentOutcomeService::handle()` (`:43-50`, observador en `AppServiceProvider.php:127`, salta en el `save()` de `:366` antes de activar, solo con clave `mkt-lead-…`), `InvoicingService::enqueue()`, `NotificationService::safe()` y `RealtimeEvents`.
- [C, medición del lead del 12-11] Producción: 0 pagos de pasarela aprobados sin activar desde que existe el periodo (14-09); 5 de 5 con plan desde octubre. El volumen es muy pequeño.
- [H] Nequi directo, probablemente apagado: `PAYMENT_NEQUI_PROVIDER` vale `disabled` por defecto (`config/services.php:254`) y `:251` dice que en producción Nequi se cobra por Wompi. Si es así, la pérdida silenciosa de la aprobación no está viva hoy.

**(b) Riesgo.** Si algo falla durante la activación, el socio queda **cobrado y sin membresía** y solo queda un `warning` en el log. Puede ser un bloqueo, un interbloqueo con un cobro de mostrador del mismo socio, un ALTER en un despliegue o un error de programación en `MembershipPeriod` (al menos 5 cambios entre el 08-10 y el 05-11). No se pierde dinero, se pierde el servicio. Probabilidad baja (0 casos en dos meses); impacto alto por caso.

**(c) Cambio mínimo: NO TOCAR el activador ahora.** (1) El punto de guardado propio (propuesta 1 de §1c.6), solo, **empeora** Wompi: convierte el fallo que hoy se deshace y se reintenta solo en un «aprobado sin membresía» silencioso; solo vale junto a una reactivación automática, que también es núcleo. (2) Quitar el `catch` (propuesta 3) rompe el «nunca lanza» del que depende `PaymentClaimAcceptanceService.php:156-159` y deja pagos cobrados en `pending` mientras el fallo persista. (3) El aviso del asunto 2 ve todos los desenlaces silenciosos, sea cual sea la causa. Se reabre solo si producción da `PAYMENT_NEQUI_PROVIDER=direct`, y entonces con las dos piezas de §1c.6 juntas.

**(d) Prueba.** Hoy no hay ninguna que lo fije: la sonda de §1c.6 (`scratchpad/mut/…/ActivatorSwallowProbeTest.php`) ya no existe [C]. Si algún día se toca: prueba en PG con el patrón de `CommercialEventRecorderPostgresTest.php` (segunda sesión con `pg_connect`, `users` `FOR UPDATE`, `lock_timeout = 300ms`; `ops/pgunit.sh`). Debe exigir «aprobado con fila y periodo» o «pendiente con excepción», nunca «aprobado sin fila».

**(e) Autorización.** Ninguna: no se toca. El propietario acepta el riesgo residual, mitigado por el aviso del asunto 2.

## 2. El barrido de pagos aprobados sin activar
**(a) Hechos**
- [C] `ops/pagos-aprobados-sin-activar.sql` es **solo una consulta de diagnóstico manual**: solo `SELECT`, para `psql` con `default_transaction_read_only=on` (`:3`). Clasifica cada `payment_transactions` `approved` (`:42`) contra su fila `payments` `origin='gateway'` con la misma `reference` (`:70-73`) y su periodo. `FALLO_*` = debió activar (`:49-66`); el corte se deduce de los datos (`:23-28`). No activa nada.
- [C] **No existe ninguna orden programada que lo vigile.** `routes/console.php` solo tiene `payments:wompi-reconcile` (`:213`, pendientes). IRON GUARD (`iron-guard:scan`, `:313`, cada 5 min) mira `stuckPayments` (`ChannelHealthDetector.php:109-143`, solo `pending` de 24 h), `repeatedPaymentDeclines` y `failingInvoices` (lista `:48-75`). Ningún aprobado sin activar.
- [H] La versión con `NORMAL_ANTERIOR_AL_PERIODO` no consta ejecutada en producción; solo la anterior, el 12-11.
- [C] `IRON_GUARD_ENABLED` vale `false` por defecto (`config/observability.php:21`). Su valor en producción está sin comprobar (`plans/informes-h9/informe-sec-cierre.md:232`).

**(b) Riesgo.** Sin aviso, un «cobrado sin servicio» solo se descubre cuando el socio reclama en la puerta.

**(c) Pieza mínima, solo aviso, fuera del núcleo de pagos.** Una comprobación nueva `approvedWithoutActivation` en `ChannelHealthDetector`, añadida a `$checks` (`:48-75`) con el patrón de `stuckPayments`:
- Solo lectura con `DB::table`.
- Toma `payment_transactions.status='approved'` de los últimos 7 días y aprobados hace ≥ 5 min, con usuario resuelto (`tx.user_id` o `members.user_id`) existente.
- Avisa si falta la fila `payments` `origin='gateway'` con su `reference`, o si la fila está `paid` con un plan de `duration_days > 0` y sin `period_start`/`period_end`. Son las clases `FALLO_*` de la consulta. La ventana reciente sustituye al corte (todo es posterior al 14-09). Las filas del activador nunca llevan `upgraded_from_plan_id` (`PaymentMembershipActivator.php:65-82`).
- Incidente `source 'wompi'`, `kind 'payments_approved_unactivated'`, `SEVERITY_CRITICAL` (el detector reserva crítico para la pérdida, `:131-132`), `fingerprint_keys ['approved_unactivated']`.
- Evidencia: recuento, ids, referencias y `oldest_at`; sin datos personales.
- No escribe en `payments` ni en `users` y no reactiva nada.

Depende de `IRON_GUARD_ENABLED=true` en producción. Si está apagado, el propietario elige: encender IRON GUARD (enciende todas sus comprobaciones) o una orden aparte `payments:approved-unactivated-check`, cada hora, con el mismo criterio y `Log::error`.

**(d) Pruebas** [tester], en `tests/Feature/IronGuard/IncidentDetectionTest.php`, SQLite y PG. Incidente crítico: aprobado con plan, usuario y sin fila; fila `paid` con plan y sin periodo; fila de CAJA con la misma referencia (no cuenta). Nada: con fila y periodo; sin usuario; fuera de la ventana. Dos pasadas: un incidente con 2 ocurrencias. En PG, el caso real: la cadena de verdad (`transitionTo` y la sesión que bloquea `users`, como en §1c.6) deja el pago aprobado sin fila y la comprobación lo detecta. Mutaciones: quitar la condición del periodo; no resolver el usuario por la ficha.

**(e) Autorización.** Un fichero de `app/` fuera del núcleo de pagos (`app/Services/IronGuard/ChannelHealthDetector.php`) y su prueba; sin migración, configuración ni rutas. Vuelta atrás: revertir el commit; los incidentes ya abiertos quedan como historial.

## 3. La prueba histórica en rojo
**(a) Hechos**
- [C] La prueba (`tests/Feature/Billing/PricingV2SalesAndPaymentsTest.php:200-220`) enciende V2, el IVA adicional y el emisor responsable (`:57`, `:60-64`). Usa un plan `base_plus_tax` de 80.000 con IVA 19 %, envía 95.200 y espera 201 con el desglose 80.000 / 15.200 / 95.200. Por `git log -L`, el cuerpo es de `ee517ee1` (29-07, Pricing V2). El commit del 02-09 (`e4149785`) solo tocó `setUp` (la caja del gimnasio).
- [C] **Regla 1**, `assertAmountMatchesPlan()` (`PaymentController.php:369-394`, mensaje `:385-391`, llamada en `:596`). Compara con `plan.price` crudo (±0,5), sin mirar `pricing_mode` ni el IVA. Es de `c8c89c9b` (08-09, «cambios pagos»), el único commit sobre esas líneas, y no tocó la prueba. Su docblock (`:354-359`) dice que replica la comprobación de V2 **porque V2 está apagado**: su intención era cubrir V2 apagado, no contradecir V2.
- [C] **Regla 2**, `applyAuthoritativePricing()` (`:1356-1403`, llamada en `:606`), solo con `billing.pricing.v2_enabled` (`:1358`): exige el bruto de `PricingService::quoteForPlan()`.
- [C] Desde el 08-09, con V2 encendido y un plan `base_plus_tax` con IVA > 0, **ningún importe sin override pasa las dos reglas**. Lo reprodujo tester-final (`informe-tester-final.md` A.3).
- [C] Para que se manifieste en producción hacen falta cuatro condiciones a la vez: (1) `BILLING_PRICING_V2_ENABLED` encendido (`config/billing.php:57`, `false` por defecto); (2) `BILLING_TAX_ON_TOP_ENABLED` encendido (`:58`, `false` por defecto; sin él, un plan `base_plus_tax` se cotiza como `legacy_inclusive`, `PricingService.php:257-264`); (3) un plan `base_plus_tax`; (4) emisor responsable de IVA (`TaxPolicy.php:51-53`, `false` por defecto; Iron Body es «No responsable», `:11`: tarifa efectiva 0). Con `legacy_inclusive`, bruto = precio (`PricingService.php:20-23`) y las dos reglas coinciden. [H, dato del lead] Los 26 planes de producción son `legacy_inclusive`; no he visto los valores de producción.
- **Veredicto: la prueba tiene razón.** El código tiene un **defecto real y latente**: una regresión accidental del 08-09, no un cambio de regla deliberado. Adaptar la prueba (esperar 422 o mandar `amount_override`) sería fijar el fallo: el mostrador nunca podría cobrar un plan `base_plus_tax` por su precio correcto.

**(b) Riesgo.** Hoy ninguno para el dinero: no se dan las cuatro condiciones. El día que Iron Body pase a ser responsable de IVA y se encienda el IVA adicional, el mostrador no podrá cobrar esos planes por su precio sin «justificar» el precio correcto.

**(c) Cambio mínimo (código, no prueba).** En `assertAmountMatchesPlan()`, tras `:373`: `if (config('billing.pricing.v2_enabled', false)) { return; }`, más una línea de docblock. Con V2 encendido, la regla 2 ya hace la comparación estricta con las mismas salidas (sin plan o precio ≤ 0, `:1358-1365`; override con motivo, `:1371-1390`).
- La alternativa, comparar contra `quoteForPlan()->grossAmount`, da el mismo resultado pero duplica la regla 2 y su `PricingException`.
- Riesgo: con V2 apagado, ningún cambio. Con V2 encendido y planes legacy, mismo resultado; solo cambia el texto del 422 («El total del plan…» en vez de «cuesta… y el cobro es de…»). Entre `:596` y `:606` no se escribe en la base [C]. Si el CRM depende de ese texto, no lo sé: no está en este árbol [H].
- Alternativa aceptable: no tocar hasta encender el IVA adicional, porque el defecto está inerte. Coste: un rojo permanente en `tests/baseline-failures.txt:1`, que además tapa cualquier fallo nuevo de esa prueba.

**(d) Pruebas.** La roja pasa a verde; `:223`, `:239` y `:254` siguen en verde (regla 2), y `tests/Feature/Caja/MixedPaymentTest.php` (V2 apagado, regla 1) sigue igual. Mutación: sin la guarda, vuelve a rojo. En el mismo commit se quita la línea de `tests/baseline-failures.txt` (la línea base queda vacía). No hace falta PG: no hay semántica de transacción.

**(e) Autorización.** Un método de `PaymentController.php` (núcleo: el cobro de mostrador) y una línea de la línea base. Vuelta atrás: revertir el commit; sin datos, esquema ni configuración.

## Orden recomendado
0. [lead o devops] Lectura de solo lectura en producción (el valor de cada clave, sin secretos): `IRON_GUARD_ENABLED`, `PAYMENT_NEQUI_PROVIDER`, `BILLING_PRICING_V2_ENABLED`, `BILLING_TAX_ON_TOP_ENABLED`, `tax_policy.issuer_is_vat_responsible`, el `lock_timeout` y el `statement_timeout` del rol, y la versión actual de `pagos-aprobados-sin-activar.sql` (esperado: `FALLO_*` = 0). El plan solo cambia si IRON GUARD está apagado o Nequi es `direct`.
1. [backend] El aviso del asunto 2 → [tester] → [reviewer] → corrección puntual. **Se hace sin tocar el núcleo de pagos.**
2. [backend] La guarda del asunto 3, si la autoriza el propietario. Es independiente del paso 1 (otros ficheros) y puede ir en paralelo. Toca el núcleo, pero no se nota en producción hasta encender el IVA adicional.
3. Asunto 1: no tocar. Se reabre si el aviso salta o si Nequi directo está encendido.

[security] Ningún paso toca autenticación ni secretos. Solo hay que vigilar que la evidencia del incidente no lleve datos personales.

## Lo que no he podido comprobar
- Los valores de producción del paso 0 y el commit exacto desplegado. Desde `9a6e3b1`, el activador y el cuerpo de `transitionTo` son idénticos: `45f18683` (17-11) solo cambia `authoritativeAmount()`.
- La política de reintentos de los webhooks de Wompi, la frecuencia real de interbloqueos y si el CRM depende del texto del 422.
- Los 55 `NORMAL_SIN_USUARIO`: dinero aprobado sin usuario. Es el flujo de enlace y reclamo (`MarketingPaymentOutcomeService::REVIEW_PAID_WITHOUT_MEMBER`, `:36`) y queda fuera de estos tres asuntos.

## Nota del lead · el paso 0, leído en producción el 22-11-2026 a las 07:06 UTC (solo lectura)
- IRON GUARD: **apagado** (`observability.enabled = false`). Solo apaga su barrido (`iron-guard:scan`); los incidentes que abren los monitores del Studio, de la voz y del H9 con `IncidentRecorder` no dependen de él. Consecuencia para el asunto 2: una comprobación dentro de `ChannelHealthDetector` NO correría hoy; el aviso va como orden programada propia.
- Nequi directo: `disabled`. La pérdida silenciosa de la aprobación del asunto 1 no está viva.
- Pricing V2: apagado. IVA adicional: apagado. Emisor responsable de IVA: no. El defecto del asunto 3 está inerte.
- El barrido de diagnóstico (`ops/pagos-aprobados-sin-activar.sql`): **0 filas FALLO**. Clases: `OK_ACTIVADO` 10, `NORMAL_ANTERIOR_AL_PERIODO` 9, `NORMAL_SIN_PLAN` 1, `NORMAL_SIN_USUARIO` 55.

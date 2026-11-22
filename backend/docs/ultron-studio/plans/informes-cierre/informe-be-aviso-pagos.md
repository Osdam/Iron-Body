# Informe be-aviso-pagos · aviso de «cobrado sin servicio» (22-11-2026)
Árbol `_worktrees/cierre-pagos/Iron-Body`, rama `cierre/pagos-aviso`, sobre `a040e889`. Asunto 2 de `diseno-arq-pagos.md`. Sin red y sin producción. **[C]** comprobado ejecutando o leyendo · **[H]** sin comprobar.

## 1. Qué se añadió
- `app/Services/IronGuard/UnactivatedApprovedPayments.php`: la única fuente del criterio, solo lectura (`DB::table`, sin escrituras).
  - `find()` (`:78`): una consulta.
  - Une `payments` por referencia Y origen `gateway` (`:88-94`), como el activador y su índice parcial.
  - Resuelve el usuario por `coalesce(t.user_id, m.user_id)` y exige que exista (`:101`).
  - Exige periodo (`:108`).
  - El corte se calcula solo si hay candidatas (`periodCutoff()`, `:158`).
  - `evidence()` (`:181`) e `incident()` (`:208`): crítico (`:220`) y huella fija (`:221`).
- `app/Console/Commands/ApprovedUnactivatedPaymentsCheck.php`: la orden `payments:approved-unactivated-check [--dry-run] [--json]` (`:37`).
  - Con la bandera apagada y sin `--dry-run` no lee ni escribe (`:48`).
  - Escribe primero `Log::error` (`:86`) y después `IncidentRecorder::record()` (`:89`).
  - `logError()` (`:114`): un log que no se puede escribir no impide el incidente y se dice en la salida (pasó en producción: ver `ChannelLog::write()`).
  - Sale 0 si no hay nada; 1 si hay que avisar o no pudo comprobar, como `ultron-studio:monitor`.
- `config/observability.php:96`: `approved_unactivated.{enabled,window_days,grace_minutes}`, con `false`, 7 y 5. No depende de `observability.enabled`.
- `routes/console.php:239`: bloque propio tras la bandera, al final de la sección de Wompi. Programa `everyThirtyMinutes()->withoutOverlapping(10)->onOneServer()`, sin argumentos.
- `.env.example:143-152`: `PAYMENTS_UNACTIVATED_CHECK_ENABLED=false`, `…_WINDOW_DAYS=7` y `…_GRACE_MINUTES=5`.
- `docs/ultron-studio/ops/aviso-pagos-aprobados-sin-activar.md`: cómo se enciende, cómo se prueba en seco y qué hacer cuando salta.
- `tests/Feature/IronGuard/ApprovedUnactivatedPaymentsCheckTest.php`: 22 pruebas.

## 2. Criterio frente a `ops/pagos-aprobados-sin-activar.sql` [C]
| Clase de la consulta | Aquí |
|---|---|
| `NORMAL_SIN_USUARIO` (`:49-50`) | no avisa (`:101`) |
| `FALLO_SIN_PAGO_NI_MEMBRESIA` y `FALLO_SIN_REGISTRO_DE_PAGO` (`:51-54`) | avisa (`p.id is null`), con la misma clase |
| `NORMAL_PAGO_REVERTIDO`, `NORMAL_SIN_PLAN` y `REVISAR_PLAN_SIN_DIAS` (`:55-60`) | no avisa (`paid`, `plan_id`, `duration_days > 0`) |
| `NORMAL_ANTERIOR_AL_PERIODO` (`:61-64`) | no avisa: el mismo corte, deducido igual (`:158`) |
| `FALLO_CON_PLAN_SIN_PERIODO` (`:65-66`) | avisa |

Diferencias, todas a propósito:
1. **Ventana** de 7 días sobre la misma fecha de aprobación (`coalesce(approved_at, paid_at, updated_at)`).
2. **Gracia** de 5 minutos.
3. El **corte se mantiene** aunque el arquitecto proponía que la ventana lo sustituyera. Así el criterio no cambia si alguien ensancha la ventana, y con la de 7 días no cuesta nada: solo se consulta si hay una candidata. Nunca esconde un fallo nuevo, porque una fila de hoy siempre es posterior al corte.
4. `REVISAR_*` **no** avisa: un plan sin días no extiende nada. Es mala configuración, no «cobrado sin servicio».

## 3. Pruebas [C]
Las 22 cubren lo pedido:
- **Avisa:** sin fila con plan; sin fila sin plan; fila pagada sin periodo o sin fin; usuario resuelto por la ficha; fila de CAJA con la misma referencia.
- **No avisa:** fila con periodo; lo que deja el **activador real** al acabar bien; sin usuario (dos casos); fuera de la ventana; dentro de la gracia (y sí al cumplirse); las `NORMAL_*`/`REVISAR_*` y los estados no aprobados; anterior al corte.
- **Incidente y log:** incidente crítico con `Log::error`; log roto con el incidente abierto igual; dos pasadas a 30 minutos dan 1 incidente con 2 ocurrencias; evidencia sin datos personales (lista blanca de claves y ausencia de nombre, correo, teléfono, documento, dirección e importe, en el incidente, sus eventos y el log).
- **Bandera y `--dry-run`:** bandera apagada da 0 consultas, 0 incidentes y 0 `Log::error`; `--dry-run` con la bandera apagada y encendida solo hace `SELECT` (`DB::listen`) y deja las filas idénticas; JSON; sin nada sale 0.
- **Programador:** apagada no se agenda, ni con IRON GUARD encendido; encendida, una entrada `*/30 * * * *`, cerrojo de 10 y `onOneServer`.

Resultados:
- SQLite: `OK (22 tests, 82 assertions)`.
- PostgreSQL 14.24 (`pgunit.sh`, base `ultron_studio_cierre_pagos`): `OK (22 tests, 82 assertions)`.
- `tests/Feature/IronGuard`: `OK (37 tests, 115 assertions)` en SQLite y en PG.
- Programador: VoiceMonitorSchedule 4, UltronStudioMonitor 12, …MonitorPrompts 10, OutreachFlagsGuard 3, ScanCommercialFactsBogotaDay 6, SyncMetaAdsInsightsJob 20, H9RenewalWindowBogotaDay 1, PruneRealtimeEvents 3, VoiceBoundary 8 y VoiceIsolation 15: todas `OK`.
- Guardas estáticas (la orden del encargo, 16 ficheros): `OK (141 tests, 182991 assertions)`.
- Pint `--test` en los ficheros nuevos: `passed`.

**Mutaciones (14/14 muertas; ficheros restaurados y comparados con `cmp`):**
- Las dos pedidas: sin la condición del periodo, matada por 4 pruebas (entre ellas «fila con periodo» y «activador real»); y sin resolver por la ficha, por 2.
- Las demás: sin el origen `gateway`, sin la gracia, sin la ventana, sin el corte, sin exigir usuario, sin exigir `paid`, sin exigir días, severidad alta y huella por pasada.
- Y tres de la orden: `--dry-run` anota, la bandera apagada no para y sin `Log::error`.
- No muté el `if` del programador; lo cubre la prueba de bandera apagada.

## 4. Plan en PG 14 y el índice [C]
Volumen sintético en la base desechable, dentro de una transacción deshecha al final: 50 000 transacciones (35 000 aprobadas en 2 años), 82 498 `payments`, 20 000 usuarios y fichas, y 3 fallos en la ventana. Producción tenía el 12-11 75 aprobadas y unos 9 700 `payments`.
- **Consulta principal: 7,8 ms**, 2 540 búferes, todos en caché. Hace un `Seq Scan` de `payment_transactions` (929 páginas; 335 filas en la ventana), luego `Index Scan using payments_gateway_reference_unique` (el índice parcial, ×335), y `plans_pkey`, `members_pkey` y `users_pkey` (solo índice).
- **Corte: 17,7 ms**, `Seq Scan` de `payments`. Solo corre si hay una candidata con fila y sin periodo.
- El resultado fueron los 3 fallos sembrados, y solo esos.
- **No necesita índice.** El barrido es lineal con el tamaño de `payment_transactions`: a esta escala cuesta 8 ms cada 30 minutos, y producción está unas 500 veces por debajo. El día que haga falta, la opción natural sería un índice parcial de expresión sobre `coalesce(approved_at, paid_at, updated_at)` con `status = 'approved'`. Eso es de database-specialist y no lo he creado.
- La prueba a 500 000 superó el `statement_timeout` de 600 s al sembrar, con la máquina a carga 51, y se deshizo entera (0 filas después). No la repetí.

## 5. Encender y probar en seco
- En seco, sin encender nada: `sudo -u www-data php artisan payments:approved-unactivated-check --dry-run [--json]`. Se espera 0, porque la consulta dio 0 `FALLO_*` el 22-11.
- Encender: `PAYMENTS_UNACTIVATED_CHECK_ENABLED=true`, `config:cache` y `schedule:list | grep approved-unactivated`.
- Vuelta atrás: la bandera en `false` y `config:cache`. Sin migración ni datos.

## 6. Desviaciones del encargo (para decidir)
- **Cada 30 minutos, no cada hora.** `IncidentRecorder::stillGrouping()` agrupa si `last_seen_at > now − 60 min` (`IRON_GUARD_GROUPING_MINUTES`). Con pasadas cada 60 minutos, la siguiente cae en el borde por segundos y unas veces hace `reopen()`: ocurrencias a 1, `first_seen_at` nuevo y un evento «Reabierto» por hora. Volver a `hourly()` es un cambio de una línea en `routes/console.php` y otra en la prueba, pero entonces «dos pasadas = dos ocurrencias» deja de cumplirse en producción.
- **No lo expuse a `ChannelHealthDetector`.** Al encender IRON GUARD aparecería un crítico nuevo sin haberlo elegido, y por dos caminos a la vez. Si se quiere, basta con un método que llame a `UnactivatedApprovedPayments::find()` e `incident()`.

## 7. Lo que no he podido verificar
- La orden en producción, en seco o real, y el valor de `IRON_GUARD_GROUPING_MINUTES` allí [H, por defecto 60].
- La cadena real en PG (el activador fallando por un bloqueo de `users` durante `transitionTo`), que el arquitecto proponía en §2(d). Atar el criterio al escritor real lo cubre solo el lado sano. El lado del fallo se cubre con filas de prueba que reproducen cada clase.
- Que ninguna referencia lleve datos personales. Se generan en el servidor (`IRON-AAAAMMDD-…`, `WompiTransactionService.php:614-616`) y la app no las manda (`WompiPaymentController.php:385`), pero no auditado en todos los llamadores [H].
- El volumen de 500 000 (§4).

## 8. Hallazgos fuera de alcance (no tocados)
1. **El mismo borde de agrupación en otros monitores horarios con `IncidentRecorder`**: `voice:webhook-health` va cada hora (`5 * * * *`) y abre incidentes (`VoiceWebhookHealth.php:413`) [C]. No he comprobado si hay más. Merece mirarlo quien lleve la voz.
2. El comentario de `config/observability.php:19-20` dice que con IRON GUARD apagado «no se crean incidentes». Es falso para `IncidentRecorder`, que no mira el interruptor; lo confirma la nota del lead.
3. `IRON_GUARD_*` no aparece en `.env.example`.
4. `vendor/bin/pint --test routes/console.php` ya falla en HEAD (`fully_qualified_strict_types`, `ordered_imports`); no es de este cambio.

[security] La evidencia y el log solo llevan recuentos, ids de `payment_transactions`, referencias y fechas. La salida de consola escapa referencia y proveedor (`OutputFormatter::escape`). No hay autenticación, rutas ni secretos.

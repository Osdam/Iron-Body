# ULTRON STUDIO · Hito 9 · Matriz de aceptación (lead, 10-11-2026)

**Veredicto (10-11-2026, tras el despliegue):** el Hito 9 está **completo, revisado, verificado y DESPLEGADO en su fase 1 (todo apagado)**: backend `ce7c150` y CRM `9572426` en producción con cero desvíos, smoke en verde y las 15 banderas apagadas (L9-76). Estado: **READY** para la fase 2 (sombra) en cuanto el propietario encienda las banderas por el mando, con las condiciones de §5. Los tres paquetes de cierre que no entregaron (E2E desde el CRM, rendimiento, documentación operativa) se pararon y quedan como pendientes de la fase 2: las filas marcadas **pendiente** lo dicen.

**Lectura del estado:** `READY` = listo y con evidencia · `READY_FOR_PAID_PLAN` = listo salvo lo que exige el plan de pago (solo Hito 8) · `BLOCKED` = depende de una decisión o acción del propietario · `pendiente` = en curso.

Fuentes: el contrato (`ULTRON_STUDIO_H9_CONTRACT.md`, decisiones L9-1 a L9-75), los informes de `plans/informes-h9/`, la regresión completa (L9-62) y el informe del revisor (`informe-reviewer.md`, §0–§16).

---

## 1. Lo que pidió el propietario el 10-11 (siete puntos)

| # | Petición | Estado | Evidencia |
|---|---|---|---|
| 1 | Consolidar los resultados de TEST9-b, PERF9, DOC9 y REV9 y resolver los bloqueantes | REV9 cerrado: **aprobado con condiciones, 0 bloqueantes** (L9-70, L9-73); todos sus hallazgos de código resueltos por el lead (§4). TEST9-b, PERF9 y DOC9: **pendiente** | `informe-reviewer.md` §14–§16; L9-63 a L9-72 |
| 2 | Estado del frontend de Automatizaciones comerciales y su E2E con Laravel | Módulo entregado (25 ficheros, 440/440 specs, `ng build` exit 0, 22 capturas) y alineado al código real de la ejecución (L9-61); integrado con las 10 llaves nuevas (L9-75: catálogo regenerado, enum, política de roles; 491 specs). **E2E con la pila real: pendiente** (TEST9-b, prueba #26) | `informe-frontend.md`, `capturas-fe9/`, L9-61, L9-75 |
| 3 | Clasificar los 56 rojos de la regresión sin ocultarlos | Clasificados (§7): 55 en la línea base conocida y 1 fuera de ella con la misma causa de entorno; **ninguno del Studio, la voz ni el H9**; el código de salida 2 de PHPUnit significa «errores», y así se reporta | L9-62; `scratchpad/regresion-h9-integrada.txt` |
| 4 | Pruebas finales tras las correcciones | Por bloques tras cada corrección: ejecución 69/69 (SQLite y PG), guardas 19/19, aislamiento 5/5, gobierno 32/32, canal 32/32, API 5/5, permisos 35/35, cobertura de autorización 40/40; pasada final del revisor 115/115. **Regresión completa final: pendiente** (se repite antes del commit) | L9-64 a L9-72; `informe-reviewer.md` §16 |
| 5 | Reglas configurables de vencimientos, eventos y seguimientos desde el CRM sin tocar código | **READY en el gobierno y la API; E2E pendiente.** Catálogo con 8 disparadores (`membership.expiring_window` y `membership.expired_window` = vencimientos; `class.upcoming_with_capacity` = eventos de clase; `lead.followup_due` = seguimientos; además `member.inactive`, `payment_link.abandoned`, `receivable.overdue`, `valuation.outdated`), condiciones con gramática cerrada y validación en vivo, secuencias con seguimientos (`waitFor`, `on.*`), plantillas, cuatro ojos y publicación en sombra; todo por la API y el constructor del CRM. Añadir un disparador NUEVO sí es código (EV9) | `OutreachCatalog.php`, `informe-studio.md` §2, `informe-frontend.md` (constructor), pruebas #1–#3, #6, #7 |
| 6 | Integración operativa con el Hito 8 y el canal WhatsApp sin despachos reales | Diseño cerrado en el contrato: WhatsApp por `MarketingMessageDispatcher` con método de plantillas (W-B, P9-5) y voz por `OutboundGate::requestForStrategy` (V9, enmienda del H8); hoy las dos puertas existen y **fallan cerradas** (`WhatsappTemplateChannel`/`VoiceStrategyChannel` → `failed_to_start` + `channel_not_live`, prueba #20); la constante `OutreachSendGate::SENDS_ENABLED_IN_CODE = false` solo se abre con un commit `outreach(sends-open):` que el despliegue rechaza en fase 1. La implementación real (W9, V9, C9) es de los hitos siguientes por contrato (§12, «S»). Prerrequisitos documentados en §8 | Contrato §9.3, §12; `OutreachDispatcherTest::test_20…`; `deploy-h9-backend-fase1.sh` (rechaza `outreach(sends-open)`) |
| 7 | Matriz de aceptación con evidencia | Este documento, más `ULTRON_STUDIO_H9_COMPLETITUD.md` (DOC9, **pendiente**) | — |

## 2. Las 26 pruebas del propietario (contrato §13.1)

Tabla autoritativa número a número en `plans/informes-h9/informe-run.md` §0.1 (TEST9-c). Resumen:

| Estado | Números | Dónde |
|---|---|---|
| ✓ en la ejecución (SQLite y PG) | 1, 2, 3, 4, 5, 10, 11, 14, 15, 16, 18, 19, 20, 21, 22, 23, 24, 25 | `tests/Feature/Outreach/Run/*` (B9-run + TEST9-c: `H9PauseCancelTest`, `H9RollbackTest`, `H9MonitorThresholdsTest`, `H9ChainOrphansTest`) |
| ✓ de punta a punta por el barrido | 6 (`H9LeadFollowupTest`, con la regresión del defecto D-1), 7 (`H9EventSegmentTest`) | TEST9-c |
| ✓ con dos procesos reales en PG | 13 (`H9IdempotencyRaceTest` + `Support/h13-worker.php`) | TEST9-c |
| ✓ en el núcleo puro (fixtures compartidos con el CRM) | 8, 9, 12, 17, 21 | `tests/Unit/Outreach/*` (962 pruebas) |
| **pendiente** | 26 (nueva estrategia desde el CRM, E2E con la pila real) | TEST9-b, `e2e/h9-nueva-estrategia` |

Pruebas T10–T23 (§13.2): cubiertas en `Run`, `Strategies` y `Stream` según `informe-run.md` §0.1 e `informe-tester.md`.

## 3. Lo que el contrato exige para la fase 1 (corte M) y su evidencia

| Pieza | Estado | Evidencia |
|---|---|---|
| Esquema (14 tablas, CHECK, únicos, índices parciales, disparadores, dueños y privilegios) | READY | DB9: `UltronStudioStrategySchemaTest` (34), `OutreachSchemaTest` (10); Anexo A aplicado (L9-60); despliegue del banco con esquema `123 137 9 25 51 75 3 8` (L9-71) |
| Núcleo puro (gramática, contenido, escalera, resultados, trazas) | READY | 962 pruebas, 803 casos de fixtures (`outreach-catalog/2026-11-10.2`), paridad byte a byte con el CRM |
| Hechos (solo lectura) | READY | `Facts/**` sin escrituras (REV9 grep), `informe-backend-facts.md` |
| Gobierno (30 rutas, cuatro ojos por persona, versiones, simulaciones, cadena firmada, publicación, pausa, reversión, vínculos) | READY | S9: 32 pruebas; `UltronStudioStrategyRoutesTest`; `OutreachIsolationTest` recorre el ciclo entero |
| Planificador en sombra, despachador, frenos y PARADA GLOBAL, conciliación, bandeja asistida, 13 rutas, 7 órdenes | READY | B9-run: `Run` 69/69 en SQLite y PG; L9-57, L9-58, L9-65 a L9-72 |
| Canal SSE único con 11 temas | READY | SSE9-b: 32/32 en SQLite y PG; SSE9-f en el CRM |
| CRM «Campañas automáticas (estrategias)» | READY (E2E pendiente) | L9-61, L9-75 |
| Todo nace apagado y es reversible | READY | 19 variables apagadas en el ejemplo de entorno; programador 0 tareas por defecto; `GET outreach/status` 200 y las otras 12 → 409 `outreach_disabled` con la maestra apagada (L9-68); constante de envío cerrada; smoke del banco 27 OK · 0 FALLO (L9-71) |
| Aislamiento (Legacy, n8n, OTP, motor comercial, voz del H8 intactos) | READY | `OutreachIsolationTest` (558 sentencias, 32 escrituras solo en tablas propias, cero red); puerta de diff del despliegue; REV9 `git status` vacío en las zonas protegidas |
| Seguridad (modelo de amenazas, guardas, BOLA/IDOR, datos personales) | READY | `ULTRON_STUDIO_H9_AMENAZAS.md`, 19 guardas, `informe-security.md`; riesgos R-1 a R-3 para el propietario (§5) |
| Mapa de permisos, catálogo, token compartido | READY | L9-59, L9-60; `auth:coverage --unmapped` vacío; 121 rutas = `StudioRouteCount::TOTAL` |
| Despliegue, smoke, mando, banco | READY | OPS9-a/b: `deploy-h9-backend-fase1.sh` (`PREVIO=a608450`), `smoke-h9.py`, `h9-outreach-scope.sh`, banco A y B en verde (L9-67, L9-71) |
| Supresión 1581 y rotación de `APP_KEY` | READY (con riesgo aceptado) | `supresion-1581.sql` (+276) ensayado; `ops/ROTACION_APP_KEY_H9.md` (rotar solo en fase 1; re-anclaje pendiente como enmienda) |
| Regresión completa del backend | READY | 15 763 pruebas, 0 rojos nuevos (L9-62) |
| Revisión independiente | READY | REV9: aprobado con condiciones, 0 bloqueantes (L9-73) |
| Rendimiento (presupuestos D9-16) | **pendiente** | PERF9 |
| Documentación operativa (matriz, runbook, pendientes del propietario) | **pendiente** | DOC9 |

## 4. Hallazgos resueltos durante la aceptación (todos con prueba de regresión)

| Hallazgo | Qué era | Corrección | Decisión |
|---|---|---|---|
| D-1 (TEST9-c) | Una inscripción en espera (`waiting`) nunca volvía a tocar: la autorización tardía y el «llámame el viernes» se perdían | `dueEnrollments()` abarca `active` y `waiting`; `waiting → active` al planificar | L9-65 |
| M-1 (REV9) | La parada por consola registraba una cuenta centinela contra L9-50 | Enmienda de L9-50 (actor `person` con `--by` o centinela 0; `system` solo para el monitor) | L9-63 |
| M-2 (REV9) | Guiones de OPS9 desfasados; Pint había reformateado `console.php` fuera del bloque | `console.php` = HEAD + bloque; OPS9-b al árbol integrado | L9-64, L9-67 |
| M-3 (REV9) | Cobertura declarada ≠ real (#23, #25, #6, #7, #18, #13) | 24 pruebas nuevas de TEST9-c; tabla §0.1 corregida | L9-67, L9-74 |
| M-4 (REV9) | Inscripciones que esperan la ventana no se cancelaban al revertir | `OutreachReconciler::cancelOrphanedByChain()` | L9-66 |
| M-5 (REV9) | Esa cancelación también actuaba cuando la cadena dejaba de VERIFICAR (rotar `APP_KEY` habría cancelado toda la sombra) | `LiveStrategy::orphanReason()`: huérfana cancela; cadena ilegible espera, en los tres sitios | L9-69 |
| m-10 (REV9) | Una entrada de reversión falsificada en la cabeza dejaba el par ausente y cancelaba | `unverifiedStrategyIds()`: par ausente bajo cabeza sin verificar = cadena ilegible | L9-72 |
| H-15 (OPS9-b) | La API no emitía el 409 `outreach_disabled` de L9-47 | `requireEnabled()` en las 12 rutas | L9-68 |
| m-1, m-2, m-3, m-4, m-7, m-8 (REV9) | Puerta real en las pruebas HTTP; bandeja con identidad y guion solo para quien reclamó; comentario; tabla D9-13; bandeja frente a inscripciones cerradas; prefiltro del conciliador | Hechos | L9-64, L9-66, L9-69 |
| Q-1..Q-12 de los paquetes | Lectores del Studio, rutas, actor, despacho `--inline`, admisiones del despliegue, autoría, rama, rotación | Decididas | L9-51, L9-55 a L9-58, L9-67 |

## 5. Pendientes y condiciones por fase (REV9 §16 + lead)

- **Fase 1 (todo apagado, desplegable):** la orden escrita del propietario (dada el 10-11); regresión completa final antes del commit; rama y commits; despliegue con respaldo, `--solo-comprobar`, `--acepta-main=a921fbb,307ad51,<commits de docs del H8 nuevos>`, migraciones, smoke, CRM y vigilancia.
- **Fase 2 (sombra):** TEST9-b (`exercise()` con las 13 rutas y 5 órdenes; E2E #26); PERF9 (presupuestos del smoke); m-9 (decisión `cancel` en la línea de tiempo, B9-run); H-1 (reloj del monitor); P9-24 (`COMMERCIAL_EVENTS_ENABLED`) y las dos primeras personas vinculadas por consola; R-1 del runbook de rotación antes de rotar `APP_KEY`; ≥ 7 días en sombra (P9-11).
- **Fase 3 (asistido):** P9-12; vigilar el carril `commercial` (H-12).
- **Riesgos para el propietario:** R-1 el tique del canal SSE viaja en la URL, vale 300 s, no es de un solo uso y también abre `*/face-image/*` (heredado del CRM; P9-25); R-2 cumplido (mapa y exclusión del token en el mismo cambio); R-3 rotación de `APP_KEY` sin re-anclaje (riesgo aceptado hasta la fase 2).
- **Hitos siguientes por contrato (§12, «S»):** W9 (WhatsApp por plantillas), C9 (consentimientos por finalidad y canal), AI9 (clasificador), V9 (voz por estrategia; exige las fases 4a/4b del H8), EV9 (actividades especiales y `app_push`), NBA9 (estrategias de fábrica).

## 6. Hito 8: estado frente al plan de pago (resumen; detalle en `ULTRON_STUDIO_H8_PREPARACION_PLAN_PAGO.md`)

- **READY_FOR_PAID_PLAN condicionado:** todo lo preparable sin plan está ensayado en el banco (102 OK · 0 FALLO, E16-32) y listado paso a paso con lo que necesita cada uno; hoy **nada de eso se ha ejecutado en producción** (hace falta tu autorización escrita por paso y tus datos: D-1/D-2 clave e importación, D-3 Twilio, D-6 voz y textos, D-7 segunda persona, D-13 canario de voz con la fase 2 del H6, E16-16, P-M2).
- **Solo con plan (o excepción D-4):** la primera sesión real del laboratorio y su veredicto a cuatro ojos → publicación canaria → smoke 4a.
- **Solo con prueba real:** la primera saliente (≈ 0,97 US$ + minutos), tres llamadas con post-llamada, 24 h sin incidentes, promoción a `rest`.

## 7. Los 56 rojos de la regresión completa (L9-62), uno a uno por familia

| Familia | Rojos | Causa (misma en todos) | ¿Línea base? | ¿Del H9? |
|---|---|---|---|---|
| `MemberContractTest` | 29 | `file_get_contents` de la plantilla de contrato ausente en `storage/app/private/contract_templates` (entorno local), `MemberContractTest.php:28` | 28 sí; 1 no (`test_register_with_the_email_of_another_member_creates_its_own_account`, añadida el 16-10 en `6850b81d`, después de escribir la línea base) | No |
| `FiscalReconciliationTest` | 12 | `RuntimeException: Factus: credencial 'client_id' no configurada` (`FactusTokenManager.php:137`) | Sí | No |
| `FactusPayloadNoVatTest` | 10 | ídem | Sí | No |
| `EmissionBlockedTest` | 2 | ídem | Sí | No |
| `ProductionEmissionGuardTest` | 2 | ídem | Sí | No |
| `PricingV2SalesAndPaymentsTest` | 1 (fallo) | 422 «El plan “Mensual” cuesta 80.000 y el cobro es de 95.200» (regla de precios del 28-10) | Sí | No |

PHPUnit terminó con código 2 (errores): se reporta tal cual. Pendiente menor: añadir la prueba de contratos del 16-10 a `tests/baseline-failures.txt`.

## 8. Integración operativa con el Hito 8 y WhatsApp (sin despachos)

| Canal | Hoy | Qué hace falta para activarlo (hito siguiente) |
|---|---|---|
| WhatsApp | `WhatsappTemplateChannel` vacío: `failed_to_start` + `channel_not_live`; `OUTREACH_WHATSAPP_PRICE_*` y `COMMERCIAL_AUTOMATION_WHATSAPP_*` apagados | W9: método de plantillas en `MarketingMessageDispatcher` → `WhatsappOutboxService` (diff enumerado), normalizadores de `meta_status`/`inbound_message` y la palabra de baja, plantillas aprobadas en S9 y en Meta, C9 (consentimientos por finalidad y canal); el H9 nunca escribe `marketing_*` salvo a través del despachador |
| Voz (Hito 8) | `VoiceStrategyChannel` vacío: `failed_to_start` + `channel_not_live`; `COMMERCIAL_AUTOMATION_VOICE_ENABLED=false` | V9 (enmienda del H8): entrada `OutboundGate::requestForStrategy`, `vc_requester_chk`, `purposeKey` en la plantilla de voz v2; exige las fases 4a/4b del H8 (salientes y contactos con consentimiento) |
| Simulado y asistido | Operativos en sombra y asistido (bandeja, resultados del personal, seguimientos) | Fase 2 y 3 del H9 |

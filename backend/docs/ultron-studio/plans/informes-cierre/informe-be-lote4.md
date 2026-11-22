# Informe de backend · Lote 4: quejas (B1, NB1-NB3, NB6), la bandeja con el libro (C-1), I-1, m-1 y el gemelo de C-5

**22-11-2026 · backend · rama `integracion/consent` (desde `dc01397a`), árbol `_worktrees/lote-4`.** Sin red, sin producción, sin push. Marcas: **[C]** comprobado ejecutando · **[L]** leído · **[S]** suposición.
Cada prueba nueva **cae con el código de antes** [C]: devolví a HEAD los ficheros de `app/` tocados, ejecuté la prueba y los restauré comparando con `cmp`. Las líneas finales son SQLite / PostgreSQL 14 (`ultron_studio_cierre_lote4`).

## Paso 0 · La integración
- **Un solo rojo, de la fusión** [C]: `H9AssistedEligibilityTest::test_m1_la_pagina_no_hace_ninguna_consulta_por_fila`.
  - Causa: la foto lee el libro con UNA sentencia por tramo, `VALUES (?, ?), …`, y el normalizador de la prueba solo colapsaba `in (?, …)`. Era la misma consulta, una vez con 4 claves y otra con 44.
  - Arreglo (`5e0344bc`): el normalizador colapsa también esa lista. El número de ejecuciones se sigue exigiendo idéntico.
- El resto del paso 0 ya estaba en verde: 18 ficheros en SQLite y en PG, `tests/Feature/Outreach` 810 en SQLite y las guardas (solo cae la puerta de diff).

## Qué cambió, punto por punto
1. **B1** (`f6dc7e65`). La llave nueva `commercial_automation.complaints_dismiss` está en:
   - `OutreachAccess.php:46` y `:50`; `PermissionCatalog.php:189`, `:278` y `:383`; la exclusión del Administrador en `CrmPermission.php:344`; y `AuthorizationMap.php:1065` (`CONTROLLER_ENFORCED`, como `simulate_real`: el catálogo la ofrece aunque ninguna ruta la nombre).
   - `MarketingInboxController.php:484` la exige con `not_a_complaint`, antes de leer la conversación y de abrir la transacción. Sin ella responde el 403 de `:509` y deja el aviso `commercial.complaint.dismiss_forbidden`.
   - Prueba `H9ComplaintDismissAuthorityTest` (5): las negativas de seguridad (Administrador, Recepción + `marketing.manage` y «Ventas» + `marketing.manage`: 403, 0 escrituras, revisión pendiente, queja frenando); con la llave, 200; el token compartido y la cuenta inactiva no escriben.
   - «Como hoy»: sin categoría, `complaint_confirmed` y `other`, con nota y sin ella, en los 4 roles, la misma respuesta y las mismas escrituras (verbo, tabla, columnas). **Esa prueba pasa igual con el controlador de antes**; la negativa cae («200 is identical to 403»). OK (5, 208) / OK (5, 208).
2. **NB1** (`488b00a1`). `MarketingStaffReviewService.php:87`: 409 `staff_review_not_pending`.
   - Prueba `H9ComplaintDismissalTest::test_sin_revision_pendiente_…` (`:163`).
   - Cambia una expectativa por la regla: repetir el descarte de una revisión ya resuelta daba 200 `dismissed: 0` y ahora da 409. Va explicado en el commit.
3. **NB2** (`7179e0a5`). Campo opcional `last_seen_ai_action_id`:
   - lo valida `MarketingInboxController.php:478` y lo pasa en `:499`; `MarketingStaffReviewService.php:93` responde 409 `complaint_newer_than_seen`, con el criterio de `:131` (`aiComplaintAfter`, en `marketing_ai_actions`: la queja fusionada no deja hecho).
   - Para que el CRM pueda enviarlo, `MarketingInboxService.php:269` añade `id` a `ai_actions[]`. **Es un fichero fuera de los dos que nombraba el encargo**: una línea aditiva.
   - Pruebas `:206` y `:243`. H9ComplaintDismissalTest OK (15, 148) / OK (15, 148).
4. **NB3** (`6cb58b47`).
   - La clave `config/outreach.php:156` (variable de `.env.example:480`) y su lector `OutreachConfig.php:230`: de 1 a 200; si no, 20.
   - `OutreachComplaintController.php:123-140`: transacción con `admins … FOR UPDATE`, 429 `complaint_daily_limit_reached` (+`limit`) y el registro con `subject_type`/`subject_id`. Cuenta `ComplaintCommercialEmitter.php:123`.
   - Pruebas `OutreachComplaintsApiTest` `:292`, `:323`, `:348` (solo PG: el orden bloquear → contar → insertar) y `:368`. 13 pruebas, 189 aserciones y 1 saltada / OK (13, 192).
5. **NB6** (`ccf63f7d`). `StudioMessageTemplates.php:225` (`OPT_OUT_NEGATIONS`) y `:1094`.
   - Prueba `MetaTemplateOptOutTextTest:110`. Las cinco propuestas siguen siendo válidas, porque la prueba que ya existía sigue en verde. OK (9, 740) / OK (9, 740).
6. **C-1** (`610b7296`). `AssistedQueue.php:602`, la línea del informe.
   - Pruebas `H9AssistedEligibilityTest` `:469` (lo libre y lo reclamado, con el servicio real del libro), `:490` (otra finalidad no retiene), `:504` (la que llega entre la comprobación y el UPDATE) y `:534` (libro vacío y sin tabla: lo de antes), más 6 casos unitarios.
   - **La de `:534` pasa con el AssistedQueue de antes**. También pasan sin cambios las baterías de la bandeja, que corren con el libro vacío: es la prueba de que producción no cambia.
7. **I-1** (`787146c5`). `AssistedQueue.php:599`.
   - Regresión `:580` (la sonda P-2): cae con el código de antes.
   - Cambio de expectativa explicado en el commit: el caso unitario «WhatsApp comercial sin consentimiento» pasa de `false` a `null`, más 5 casos nuevos.
   - **Siembras**: `RunWorld.php` (`canaryMember()`) da a un canario de WhatsApp su prospecto `granted` con conversación, como `qualifyingMember()`. Sin eso, 10 pruebas sembraban un estado que la escalera nunca deja salir. Con el código de antes y estas siembras, solo cae la regresión, y las demás dan los mismos números (309, 185…).
   - Eligibility OK (32, 466) / OK (32, 466) · OptedOut OK (59, 60) / OK (59, 60).
8. **m-1** (`7f514405`). `tests/Unit/Outreach/Run/AssistedQueueLadderParityTest.php`:
   - clasifica cada motivo, nivel por nivel; cada código existe en la bandeja; cada decisión de exclusión consta en el contrato; y al revés, la bandeja no retiene por nada que la escalera no tenga, salvo `expired`.
   - La misma tabla está en el contrato (D9-9.5, «La bandeja frente a la escalera»).
   - Caen las 3 mutaciones: un motivo nuevo en N1, uno nuevo en la bandeja y F-4 borrada del contrato. OK (3, 136) / OK (3, 136).
9. **Gemelo de C-5** (`a587536c`). `ContactHistoryFacts.php:220`: solo recuerda el «sí».
   - Prueba `AgendaEngineHistoryFactsTest:267`, con 0 consultas al catálogo tras el «sí». OK (10, 47) / OK (10, 47).
   - `SubjectAndSnapshotTest` OK (5, 58) / OK (5, 58): las consultas por lote no cambian.
10. **Documentación** (`bc423250` y este commit). Contrato: Q1-8 §7 (B1, NB1-NB3, NB6, las rutas y los límites NB4, NB5, NB7, NB8, NB9, más la ventana de NB2 y el falso positivo de NB6), C9 §6 (C-1, pendiente (e)), y D9-9.5 (motivos 4 y 9, nota del lote).

**Baterías finales** [C]:
- `tests/Unit/Outreach`: OK (1057, 53423).
- `tests/Feature/Outreach` (SQLite): OK, 828 pruebas, 45702 aserciones y 177 saltadas (solo PG).
- Permisos y rutas, en SQLite y PG: 25 ficheros, `tests/Feature/Access` incluido, más `InboxTest` OK (27, 132). Todo verde salvo el error previo de PG que va abajo.
- Guardas (SQLite): 141 pruebas, 1 fallo: la puerta de diff.
- Pint en verde en lo que toqué. `MarketingInboxController` y `MarketingInboxService` ya fallaban en HEAD con los mismos fixers; no los reformateé.

## La forma de las rutas para el CRM (Enmienda Q1-8 §7)
- **Resolver revisión** · `POST marketing/inbox/conversations/{id}/staff-review/resolve` `{note?, complaint_category?, last_seen_ai_action_id?}`. Con `not_a_complaint`:
  - **403** `{ok, code: forbidden, message, required_permission: commercial_automation.complaints_dismiss}`, aunque la conversación no exista;
  - **409** `staff_review_not_pending` · `complaint_newer_than_seen`;
  - **422** si `last_seen_ai_action_id` no es un entero ≥ 1.
  - Sin categoría, `complaint_confirmed` y `other`: sin cambios.
- **Detalle de la conversación** · `GET …/conversations/{id}`: cada `ai_actions[]` lleva `id`. El CRM manda el mayor que pintó.
- **Registrar queja** · `POST commercial-automation/complaints`: **429** `complaint_daily_limit_reached` (+`limit`).
- **Plantillas** · `opt_out_text_missing` también con una negación delante del verbo.

## Puerta de diff (`OutreachIsolationTest`), hoy [C]
Lista exactamente `backend/app/Observers/Commercial/ComplaintCommercialEmitter.php`, `backend/app/Observers/Commercial/MarketingAiActionCommercialObserver.php` y `backend/app/Services/Commercial/CommercialVocabulary.php`: los tres autorizados. El emisor gana `staffComplaintsRecordedBy()` (NB3); no entra ningún fichero nuevo. No edité la prueba.

## Lo que NO pude verificar
- **Producción y CRM.** Nada en producción (sin ssh), ni el CRM: no envía todavía `last_seen_ai_action_id` y, sin él, NB2 no actúa. No vi con qué llaves de sesión decide el CRM si enseña «No era una queja» [S].
- **Las carreras con dos procesos reales**, la de NB3 (dos peticiones de la misma cuenta) y la de NB2. Solo las cubrí en una conexión y sobre el SQL (el orden del bloqueo, en PG).
- **Con qué frecuencia** se dan I-1 y el tope de NB3 en producción.

## Lo que seguridad debe mirar primero
1. **B1 vive en el controlador.** `resolveComplaint()` recibe un `adminId`, no la cuenta: otro llamador futuro se saltaría la llave. Hoy el único es la ruta [L].
2. **NB2 es opcional**: sin el campo, la ventana de NB2 queda como antes. Además, `ai_actions[].id` es un id interno nuevo en la respuesta.
3. **I-1 y la siembra** de `canaryMember()`. I-1 no cubre la cobranza por WhatsApp: con la autorización de C9 perdida (el número cambió) no retiene. La decisión del lead nombra solo la comercial.
4. **El bloqueo `admins … FOR UPDATE` de NB3**: es breve, pero toca la tabla de cuentas.
5. **El falso positivo de NB6**: «Si no quiere, responda BAJA» se rechaza (el normalizador no ve la coma). Falla del lado seguro.

## Hallazgos fuera de alcance (sin tocar)
- **`AdminUserPasswordTest.php:353`** crea un disparador con sintaxis de SQLite. En PG da error de sintaxis: 150 pruebas, 1 error en `tests/Feature/Access`. Ese fichero no está en el diff del lote.
- **NB5 sigue igual**, como pide el encargo: la comprobación de repetición de `clientRequestId` va antes del bloqueo de la cuenta.
- **`AssistedQueueLadderParityTest` clasifica `no_whatsapp_identity` como `policy_unknown`**, y eso solo es cierto en la finalidad comercial. En servicio y cobranza no retiene, como dice el contrato.

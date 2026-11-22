# api-security · PT-18 (id del socio en autorizaciones y quejas) · 22-11-2026
`_worktrees/lote-4/Iron-Body`, `integracion/consent` @ `ba4b143e`, solo lectura (`git status` limpio al terminar). [C] ejecutado · [L] leído · [S] supuesto.

## Veredictos
- **PT-18: CERRADO.** 0 bloqueantes. La corrección no abre nada nuevo.
- **Desplegar el lote: APTO CON CONDICIONES** (las de mis colegas, sin cambios). Cae la de «no desplegar el lote 4 con este CRM mientras B-1 siga abierto»: el servidor lee ahora el `users.id` que ya manda el CRM.
- **Uso por el personal: APTO CON CONDICIONES.** El rechazo del revisor del CRM era solo por B-1. El CRM no cambia.
- Sigue en pie, y no es nueva: no dar `complaints_record` ni las llaves `consents_*` al rol Entrenador (NB-1, NB-2). Hoy son solo de Super Admin (`CrmPermission.php:336-341,439`).

## BLOQUEANTES
Ninguno.

## NO BLOQUEANTES (no se corrigen en este cierre)
- **NB-1 · La queja no aplica el alcance del entrenador** [C, P3]. Un Entrenador con `complaints_record` y `members.view` registra una queja sobre un socio ajeno (201, frena 30 días). Con un id inexistente recibe 404, así que además sabe si existe. `OutreachComplaintController.php:158-171` no llama a `TrainerMemberScope`. Es previo, y PT-18 solo cambia el tipo de id.
- **NB-2 · El alcance mira la ficha canónica** [C, P3]. Persona con dos fichas por identidad: el entrenador de la ficha NO canónica recibe 404 para su propio socio (falla cerrado); el de la canónica alcanza (200) el `users.id` de la otra ficha, que `GET /api/users/{id}` le oculta (404, `guardUsersOf`). Es la misma persona y el mismo libro. Con `members.id` pasaba igual (`forKeys(m:B)` → canónica): PT-18 no lo empeora.
- **NB-3 · El 403 de la queja delata el vínculo** [C, P4]. Un prospecto de un socio pedido con solo `marketing.view` da 403 `required_permission: members.view`, no 404: 1 bit de oráculo del vínculo, que las autorizaciones sí cierran (M-1). No escribe nada. Ya estaba así y documentado (`informe-be-quejas-y-baja.md:50`); PT-18 no lo toca.
- **NB-4 · Falta una prueba en el CRM** que fije `personId = UserSummary.id` (informe-be §7). Las suyas fingen al socio como `{id: 42}`.

## Las seis respuestas
**1 · ¿Cerrado de verdad?** Sí [C]. Mundo cruzado: `users.id` desde 870001, y el `users.id` de Ana es el `members.id` de Luis.
- Para Ana (P1): leer, pedir, confirmar, pedir otra, rechazar, retirar y la queja. Las 5 filas del libro son `m:<Ana>`, con el `member_id` y el `phone_ref` de Ana; la queja queda en `commercial_events.member_id` de Ana (`member:<Ana>:complaint…`). Luis sigue con su única fila: `revoked`, `numberRevoked: false`.
- Sin oráculo (P2): un id inexistente, un número que solo es `members.id`, un usuario sin ficha y 999999999999 dan el mismo `404 {"ok":false,"code":"person_not_found","message":"No existe."}`, byte a byte, en GET/POST consents, revoke y complaints (16 de 16).
- No escriben nada: 0 filas en el libro y en `commercial_events`. Igual en SQLite y en PG 14.

**2 · ¿Algo compara todavía un `users.id` con un `members.id`?** No [L+C].
- **Ver a la persona:** va por TIPO, no por id (`OutreachConsents.php:525,534`; `OutreachComplaintController.php:160,167`).
- **Entrenador:** `inTrainerScope` recibe el `members.id` canónico (`OutreachConsents.php:537,577`) y lo compara con `member_trainer_assignments.member_id` (`TrainerMemberScope.php:112-115`): mismo tipo y la persona pedida (P3 y la prueba del autor).
- **Número y `numberRevoked`:** salen del sujeto resuelto (`OutreachConsents.php:134,218,415` → `phoneRefs()` `:627`) (P1).
- **Idempotencia:** el libro compara el `subject_key` canónico (`:209,395`); la queja, `canonical` con `commercial_events.member_id` (`OutreachComplaintController.php:106-108`). El `clientRequestId` de Ana usado para Luis da 409 al pedir, al retirar y en la queja; el replay da 200 (P5).
- **Tope de quejas:** por cuenta, sin id (`OutreachComplaintController.php:125`); pasado, 429 (P5). **El emisor** recibe el `members.id` canónico (`:132` → `fromMember`).

**3 · `recordsAs`** (`OutreachConsents.php:496-507`) [L+C, P4]:
- **Socio:** el `users.id` que mandó el CRM, resuelto por `members.user_id`: la misma persona.
- **Prospecto de un socio:** `primaryUserId` (`SubjectWorld.php:85`), un `users.id` de la misma persona, también si la ficha canónica no tiene usuario.
- **«Sin usuario»:** `{lead, <el prospecto pedido>}`. El tipo es el correcto y nunca es un `members.id` ni otra persona. Lo guardado va a la ficha canónica (P4).
- **¿Delata algo?** No: llega después de `members.view` y del alcance (`OutreachConsents.php:534-539`). En la queja sin alcance (NB-1), lo que antes salía como `members.id` sale ahora como `users.id`, que `/api/users` le oculta al entrenador.

**4 · ¿Otro punto con la misma confusión?** No encontré ninguno [L; `git diff f1fe6cac..HEAD -- backend/app backend/routes` + grep].
- Las cuatro rutas con `personId` pasan todas por `requestedSubject()` (`OutreachConsents.php:474`). `confirm` y `reject` van por uuid: la fila → `forKeys` (`:557-582`).
- Inbox: el id de la conversación y `last_seen_ai_action_id`, acotado por `conversation_id` (`MarketingStaffReviewService.php:144-151`); el `lead_id` sale de la base.
- El resto de `SubjectKey::member/lead` del lote toma ids de la base (`keysOf`, `OutreachConsentFacts`).

**5 · Los dos adyacentes:** siguen siendo NO bloqueantes y la corrección no los empeora (NB-1 y NB-2, los dos comprobados en P3).

**6 · «Apto con condiciones»:** sigue en pie, para desplegar y para el uso por el personal. B-1 ya no bloquea.

## Sondas
Son mías: `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body-backend-docs-ultron-studio/831f7416-3de0-49d9-86f2-c2eec6cfdaaf/scratchpad/sonda/ApisecPt18ProbeTest.php`. Estuvieron en `backend/tests/` solo mientras corrían y están borradas.
- SQLite: `OK (5 tests, 92 assertions)`. PG 14 (`UNIT_DB=ultron_studio_cierre_apisecids`): `OK (5 tests, 92 assertions)`.
- Salida de P3, igual en las dos bases: `{"T1 consents uB":404,"T1 users/uB":200,"T2 consents uB":200,"T2 users/uB":404,"T2 consents ajeno":404,"T2 revoke ajeno":404,"T2 queja ajeno":201,"T2 queja inexistente":404}`.

## Lo que no verifiqué
- **El CRM** (otro repositorio): me baso en el B-1 del revisor (`member-details-modal.ts:458`, `member-picker.ts:338`).
- **Mi sonda contra `323a6118`:** no la corrí. Lo cubre el rojo del autor (9 de 9) y, por lo leído, P1 y P2 caerían.
- **Fuera de alcance:** producción, las diferencias de tiempo entre los 404 y las suites completas (las corrió el lead).

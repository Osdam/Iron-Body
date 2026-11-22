# Informe backend · PT-18 · el id de un socio en autorizaciones y quejas
22-11-2026 · `_worktrees/lote-4/Iron-Body`, `integracion/consent` desde `323a6118`. Commits: `ab0ba0ab` (la prueba en rojo), `4e339852` (la corrección) y el de documentación. Nada desplegado; sin red ni producción. **[C]** comprobado ejecutando · **[L]** leído · **[S]** supuesto.

## 1 · La reproducción en rojo [C]
El mundo de pruebas: `FactsWorld::usuariosDesde(870001)`, opcional (solo lo activan las dos clases de API). Con él, `users.id ≠ members.id` para todos. Ana es `users.id 870001` / `members.id 1` (en PG, el `members.id` que dé la secuencia), y el `members.id` de Luis es 870001. Con el código de `323a6118` caen **las 9 nuevas** y las 37 de antes siguen en verde:
- SQLite: `Tests: 46, Assertions: 638, Failures: 9, Skipped: 7`. PG 14 (`--filter pt18`): `Tests: 9, Assertions: 50, Failures: 9`.
- Pedir y retirar para Ana escriben `['m:870001', 870001, <phone_ref de Luis>]` en lugar de `['m:1', 1, <el de Ana>]`. La queja de Ana queda como `complaint_created:member:870001:…` (la de Luis).
- Leer a Ana devuelve el libro de Luis (`'revoked'` en vez de `'none'`). El entrenador de Luis lee a Ana (200) y no a Luis (404). Un `members.id` que no es el `users.id` de nadie da 200/201 en vez de 404.

## 2 · El cambio [C]
- `app/Services/Outreach/Run/OutreachConsents.php:474` `requestedSubject()`: el único punto de entrada.
  - `member` → `SubjectResolver::forUserIds([$id])` (`members.user_id`, que es único, y la identidad).
  - `lead` → `forKeys(['l:<id>'])`, como antes.
  - Devuelve null si no es nadie; `person()` (`:523-526`) responde 404.
- `:496` `recordsAs()`: nombra a la persona como el CRM. El socio pedido es él mismo. Un prospecto de un socio es el `users.id` de la ficha canónica (o el menor de la persona). Sin ningún usuario, el propio prospecto.
- `app/Http/Controllers/Crm/CommercialAutomation/OutreachComplaintController.php:161` usa los dos. `canonical` (`:169`) es lo que escribe (`:132`), lo que compara la idempotencia (`:106-108`) y lo que va al registro (`:141`). `recordsAs` solo va en la respuesta.
- `SubjectResolver.php` y las cabeceras: solo comentarios. La enmienda PT-18 está al final del contrato H9. Hay notas fechadas en `informe-be-consent.md` §4 y en `informe-be-quejas-y-baja.md` §A, que no decían qué id era `personId` de un socio. Ningún informe decía en palabras que fuera `members.id`.

## 3 · Lo que dependía del id pedido y cómo queda [C salvo marca]
- **Ver a la persona** (`members.view` / `marketing.view`): va por TIPO, no por id. Sin cambios; el 403 sigue antes de leer.
- **Alcance del entrenador**: `TrainerMemberScope` trabaja con `members.id` (`member_trainer_assignments.member_id`). Recibe el `members.id` canónico del sujeto, que ahora es el de la persona pedida. Prueba `test_pt18_el_entrenador_de_luis_…`.
- **Número, `numberRevoked`, `hasPhone` y `effective`**: salen del sujeto (`phoneRefs()`). Pedir y retirar guardan ahora el `phone_ref` de Ana.
- **Prospecto de un socio (M-1)**: la ruta del prospecto no cambia. Lo único nuevo es `recordsAs.id`, que es un `users.id`.
- **Tope de quejas**: va por cuenta; el id no entra.
- **Idempotencia**: el libro compara `subject_key` canónico. La queja compara ahora `canonical`, no `recordsAs`: repetirla da 200 `replayed`.
- **Lo guardado**: `outreach_consents.subject_key/member_id/lead_id`, `commercial_events.member_id`, la clave de la queja y `staff_recorded.subject_id` siguen con el sujeto canónico (`members.id`).
- **404**: un usuario inexistente, uno sin ficha y un número que solo es `members.id` dan el mismo cuerpo (comparado byte a byte).

## 4 · El resto del lote [L, `git diff f1fe6cac..HEAD -- backend/app` y grep]
- Solo estas dos rutas convertían un id del CRM con `SubjectKey::member()`.
- Los demás `SubjectKey::member`/`forMemberIds`/`forUserIds` toman ids de la base: `OutreachPlanner:202`, `PaymentFacts:148-150`, `ReceivableFacts:113`, `Membership/Attendance/Class/ValuationFacts`, `OutreachConsentFacts:163`, `SubjectWorld`.
- `confirm`/`reject` van por uuid.
- Resolver la revisión del Inbox recibe el id de la conversación y `last_seen_ai_action_id`, no ids de socio: **no se toca**.
- Las rutas nuevas son solo las de `routes/commercial-automation.php`.

## 5 · Pruebas que cambian de `members.id` a `users.id`
Es el **cambio de contrato decidido** (Enmienda PT-18), no la prueba adaptada a un fallo:
- `OutreachConsentsApiTest`: `peticion()`, `retirada()` y las URL `personId=`. `socioConWhatsapp()` devuelve también `user`.
- `OutreachComplaintsApiTest`: todo `queja('member', …)`.
- Las aserciones de `recordsAs` pasan del `members.id` al `users.id`: `test_el_texto_legal_viaja…` y `test_registra_el_mismo_hecho…`.

Las aserciones sobre la base (`member_id`, claves `m:`, `dedupe_key`, el registro) siguen en `members.id`, y `/api/admin/members/{id}` sigue con el suyo. Ninguna aserción se afloja ni se quita. Hay 9 pruebas nuevas (`test_pt18_*`), y dos de ellas son las negativas (404 y 0 filas).

## 6 · Verificación (líneas finales) [C]
- Las dos clases, SQLite: `Tests: 46, Assertions: 685, Skipped: 7` (solo PG). En PG 14: `OK (46 tests, 1029 assertions)`.
- Lote, 14 ficheros: `OutreachConsents/ComplaintsApiTest`, `OutreachConsentFactsTest`, `OutreachConsentsSchemaTest`, `OutreachConsentSuppression1581Test`, `H9ComplaintDismiss{Authority,al}Test`, `H9Assisted{ClaimRace,Complaint,Conditions,Eligibility,OptOutStrategy}Test` y `Unit/…/AssistedQueue{LadderParity,OptedOut}Test`. SQLite `Tests: 257, Assertions: 3750, Skipped: 16`; PG 14 `OK (257 tests, 4742 assertions)`.
- `tests/Feature/Outreach` entero, SQLite: `Tests: 928, Assertions: 50348, Skipped: 183`, 0 fallos.
- Guardas (17 ficheros, la orden del lead): `Tests: 149, Assertions: 186618, Failures: 1`. El rojo es SOLO la puerta de diff (`OutreachIsolationTest:374`), con `ComplaintCommercialEmitter.php`, `MarketingAiActionCommercialObserver.php` y `CommercialVocabulary.php`: lo esperado.
- `pint --test` sobre los 6 ficheros PHP tocados: `passed`. Sin phpstan en el proyecto.

## 7 · Lo que el CRM tiene que saber
- No cambia nada de lo que envía.
- La forma de las respuestas es la misma. Cambia el valor de `recordsAs.id` de un socio, que ahora es `users.id`. Con un socio, `recordsAs` coincide con `person` y el aviso «Este prospecto es de un socio…» ya no sale (antes salía de más). Con un prospecto de un socio, nombra «Socio #<users.id>».
- Sus pruebas fingen al socio como `{id: 42}`: conviene una que fije que `personId` es `UserSummary.id`.

## 8 · Para api-security y el revisor (primero)
1. `requestedSubject()` y que no quede ninguna conversión de un id del CRM a `m:`.
2. La rama de `recordsAs()` sin usuario [S: hoy no ocurre; en producción los 4 144 socios tienen usuario]. Decisión del lead.
3. Adyacente, previo y no corregido: **la queja no aplica `TrainerMemberScope`** (las autorizaciones sí). Una cuenta de entrenador con `complaints_record` y `members.view` frenaría a cualquier socio. Hoy esa llave solo la tiene Super Admin.
4. Adyacente: el alcance mira la ficha CANÓNICA. En una persona con dos fichas por identidad, difiere de `guardUsersOf` (la ficha del usuario).

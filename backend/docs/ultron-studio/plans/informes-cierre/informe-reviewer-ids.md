# Revisión · PT-18 (B-1, id del socio) y PT-10 (CRM con los lotes desplegados) · reviewer · un pase
22-11-2026 · Backend `_worktrees/lote-4/Iron-Body` `integracion/consent` `323a6118..ba4b143e` · CRM `_worktrees/cierre-crm/Iron-Body_Front` `cierre/crm-lote5` @ `9d5a8e6` (sin cambios). Solo lectura; `git status` limpio en los dos al terminar; sonda propia copiada a `backend/tests/` solo mientras corría y borrada. **[C]** ejecutado · **[L]** leído · **[S]** supuesto.

## Veredictos
- **PT-18: B-1 CERRADO.** El CRM manda `users.id` en las cuatro rutas que nombran a un socio, y el servidor lo lee ahora como `users.id`. Lo guardado sigue siendo el sujeto canónico.
- **PT-10: APROBADO CON CONDICIONES** para usar las tres pantallas nuevas con los lotes del backend desplegados. Condiciones: (a) el backend que se despliegue contiene `4e339852` (desplegar el lote 4 en `323a6118` reabre B-1 tal cual); (b) siguen en pie las condiciones de api-security para el uso por el personal (no son mías).
- **Bloqueantes: 0.**

## BLOQUEANTES: ninguno

## NO BLOQUEANTES (no se corrigen en este cierre)
- **NB-a · Persona sin ningún usuario** (`OutreachConsents.php:506`): un prospecto de un socio cuyas fichas no tienen `user_id` devuelve `recordsAs = {lead, <su id>}`, y el CRM no enseña «Este prospecto es de un socio…» aunque lo guardado vaya al socio. Sonda [C]: `{"type":"lead","id":2,"recordsAs":{"type":"lead","id":2}}`. Está documentado en la enmienda §5. Que en producción no haya casos es [S].
- **NB-b · Hueco de prueba en el CRM:** ningún spec fija que el `personId` de un socio sea `UserSummary.id`. `ca-consents.spec.ts` solo usa el camino `lead` (`:139,:477,:579`) y `complaint-record.spec.ts` usa `lead 17`. Ver la respuesta 5.
- **NB-c · Adyacentes y previos, de api-security** (ya los señala el autor, §8.3-8.4): la queja no aplica `TrainerMemberScope`. Y el alcance mira la ficha CANÓNICA: con dos fichas por identidad, un entrenador asignado a la no canónica ve a la persona en el buscador (`guardUsersOf`) y recibe 404. Fuera de mi alcance; no cambia con PT-18.

## Las seis respuestas
**1 · ¿B-1 cerrado de punta a punta? Sí** [L+C].
- **Ficha:** `member-details-modal.ts:458` `[personId]="member.id"`. `member` es `UserSummary` (`:1277`), que llega de `users-list.ts` (`getUser(id)` → `GET /api/users/{id}`, `api.service.ts:3389`; `UserController::index` hace `User::query()`).
- **Buscador:** `member-picker.ts:336-337` `id: Number(u.id)` de `GET /api/users`. Luego `ca-consents.ts:340` `{type:'member', id: s.id}` → `:383` `loadConsents`, y `:555` lo reutiliza en pedir y retirar.
- **Servidor:** las dos rutas pasan por `OutreachConsents::requestedSubject()` (`:474`): con `member`, `forUserIds([$id])` (`:477`). La queja lo usa en `OutreachComplaintController.php:161`. Escribe y compara con `canonical` (`:106,:132`).
- **Las seis operaciones:** leer, pedir y retirar nombran a la persona. Confirmar y rechazar van por el uuid, que sale del libro de la persona correcta (`visibleRequest`). La queja, igual.
- **Otro sitio del CRM:** no lo hay. En `a9e7e7a..9d5a8e6` los únicos que mandan un `personId` son estos y el Inbox (`context-panel.ts:108,114`), que manda `lead` con `lead_id`; ahí `member_id` solo se usa como booleano. Ningún enlace produce `?tipo=member`. Las rutas son `api/admin` y el token compartido da 403 (probado), así que n8n no las llama.

**2 · Lo que el CRM pinta: bien** [L+C].
- `person` devuelve el id pedido, así que «Socio #N · nombre» cuadra (`ca-consents.ts:412-415` compara por tipo e id).
- Con un socio, `recordsAs` es él mismo (`:503-504`): el aviso de `ca-consents.ts:150` ya no sale.
- Con un prospecto de un socio, el aviso dice «Socio #<users.id de la ficha canónica>», el mismo «ID #» que enseña la ficha (`member-details-modal.ts:81`). Sonda [C]: con dos fichas por identidad, el prospecto de la segunda nombra al usuario de la canónica.
- La clave de idempotencia (`commercial-automation-session.ts:775,802`) usa el `personId` enviado, no `recordsAs`. La queja no lee `recordsAs` (`complaint-record.ts:71`).
- Ningún fixture fija el significado anterior: `{member 42 → member 42}` y `{lead 17 → member 42}` valen con el nuevo. `h9-fixtures` no trae autorizaciones ni quejas.

**3 · Pruebas del servidor: correctas** [C+L].
- **Nada se afloja:** las líneas quitadas del diff son solo cambios de `['member']` a `['user']` y la firma de `socioConWhatsapp`. Aserciones: autorizaciones 270→323 y quejas 112→133. Pruebas: 24→31 y 13→15.
- **Los ids difieren** para todos los socios de las dos clases (`FactsWorld.php:38,68`, desde 870001, también vía `expiringMember`/`qualifyingMember`). Es opcional: las otras clases no lo activan, pero no reciben ids del CRM.
- **Ids cruzados:** Ana y Luis (`OutreachConsentsApiTest.php:929`; queja `:395`).
- **Negativas:** `:677` y la de quejas `:421`. Un `members.id` que no es `users.id` de nadie, y un usuario sin ficha, dan el mismo 404 byte a byte y 0 filas.
- **Entrenador:** `:651`.
- Que las 9 nuevas caen con el código anterior lo comprobó el lead; no lo repetí.

**4 · Regresiones en el lote: ninguna** [C].
- `SubjectResolver` solo cambia un comentario, y lo guardado no cambia de forma.
- La bandeja, el lector y el planificador pasan en SQLite y en PG (ver abajo).
- La sonda confirma que, con dos fichas, retirar por el `users.id` de la segunda escribe en `m:<canónica>`, y que la lectura por cualquiera de los dos usuarios lo ve.

**5 · La prueba del CRM** (`personId` = `UserSummary.id`): **NO BLOQUEANTE.**
- El código lo enlaza sin transformar (las dos líneas de la respuesta 1).
- El contrato queda fijado del lado del servidor con ids cruzados.
- Solo protegería de un cambio futuro en el CRM: va como seguimiento (NB-b).

**6 · PT-10: APROBADO CON CONDICIONES** (las del veredicto). El único motivo del RECHAZADO anterior era B-1. Lo demás lo dio por bueno mi colega y no lo reabro.

## Pruebas ejecutadas [C] (línea final)
- **SQLite:**
  - `OutreachConsentsApiTest`: `Tests: 31, Assertions: 473, Skipped: 6`.
  - `OutreachComplaintsApiTest`: `Tests: 15, Assertions: 212, Skipped: 1`.
- **PG 14** (`ultron_studio_cierre_revids`):
  - `OutreachConsentsApiTest`: `OK (31 tests, 814 assertions)`.
  - `OutreachComplaintsApiTest`: `OK (15 tests, 215 assertions)`.
  - Juntas: 46 pruebas y 1 029 aserciones, como dijo el lead.
- **Bandeja, lector y planificador, SQLite y PG**, todo OK:
  - `OutreachConsentFactsTest` 20/1185 en los dos.
  - `H9AssistedClaimRace`: SQLite 4 skipped; PG `OK (4, 114)`.
  - `H9AssistedComplaint` 4/39 · `Conditions` 11/185 · `Eligibility` 33/492 · `OptOutStrategy` 27/309.
  - `OutreachPlannerTest` 8/89 · `AssistedQueueLadderParity` 4/138 · `AssistedQueueOptedOut` 65/66.
- **Sonda propia** (3 pruebas: dos fichas por identidad, canónica sin usuario y queja por prospecto que se repite por socio; un `members.id` da 404): SQLite `OK (3 tests, 20 assertions)`; PG `OK (3 tests, 20 assertions)`. Borrada.
- **CRM** (`IRON_BODY_BACKEND_DIR`=lote-4; `ca-consents`, `commercial-automation-consents`, `complaint-record`, `member-picker`, `member-details-reclassification`, `h9-fixtures`): `Test Files 6 passed (6) · Tests 98 passed (98)`.

## No verificado
- Producción (sin acceso): si hay socios sin usuario (NB-a) y los recuentos de la enmienda [S].
- Que las 9 nuevas caen en `323a6118`: lo dice el lead, no lo repetí. No corrí `tests/Feature/Outreach` entero ni las guardas (928 según el autor).
- Ningún navegador ni servidor real. La autorización (api-security) no es mía.

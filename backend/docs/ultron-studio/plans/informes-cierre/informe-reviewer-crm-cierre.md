# Revisión final · CRM del cierre (PT-10) · reviewer · un pase
22-11-2026 · `_worktrees/cierre-crm/Iron-Body_Front`, `cierre/crm-lote5` @ `9d5a8e6` frente a `a9e7e7a` (10 commits). Solo lectura: `git status` limpio al terminar; los dos worktrees temporales (`a9e7e7a` y la punta, para las mutaciones) quitados. Contratos: lote-4 `323a6118` (producción de hoy = `f1fe6cac`); laboratorio `bdb93199`. **[C]** ejecutado · **[L]** leído · **[S]** supuesto.

## Veredictos
**(1) Publicar este CRM hoy contra el servidor de hoy: APROBADO CON CONDICIONES.** Sin las cuatro llaves no aparece la pestaña, ni «Registrar queja», ni «No era una queja». El servidor de hoy no las conoce: 0 apariciones en `f1fe6cac` [C]. Las condiciones:
- (a) El lead acepta, sabiéndolo, que «Resolver revisión» SÍ aparece hoy (NB-1).
- (b) No desplegar el lote 4 del backend con este CRM mientras B-1 siga abierto: al desplegarlo, el Super Admin activo recibe las cuatro llaves por defecto y B-1 queda expuesto.

**(2) Usarlo con los lotes del backend desplegados: RECHAZADO** (1 bloqueante: B-1). Lo demás cumple su contrato: el prospecto, el Inbox y el laboratorio.

## BLOQUEANTES
**B-1 · Para un SOCIO, el CRM manda `users.id` y el servidor lo lee como `members.id`.** → backend + frontend. Es una decisión de contrato: architect.
- **En el CRM:** ficha del socio, `member-details-modal.ts:458` `[personId]="member.id"`, y `UserSummary.id` es `users.id` (`api.service.ts:61-62`; `UserController::index` hace `User::query()`). Pestaña de autorizaciones, «Socio» con `app-member-picker` (`ca-consents.ts:115,373`; `member-picker.ts:338` `id: Number(u.id)`, de `GET /api/users`).
- **En el servidor:** `OutreachComplaintController.php:157` y `OutreachConsents.php:471` → `SubjectKey::member($id)` → `SubjectResolver.php:155-160`, que hace `DB::table('members')->whereIn('id', …)`.
  - Sus pruebas mandan `members.id` (`FactsWorld.php:47-62` devuelve `member` = `members.id`; `OutreachComplaintsApiTest.php:151,189,197`).
  - `members` tiene su propio autoincremental (`2026_05_18_000001_create_members_table.php:12`) y `user_id` aparte (`User::appMember()` es hasOne).
  - Las pruebas del CRM no lo ven: fingen al socio como `{id: 42}`.
- **Escenario:** Ana es `users.id 812` y `members.id 640`; el `members.id 812` es Luis.
  - En la ficha de Ana, «Registrar queja» da 201 «Queja registrada. El contacto automático queda en pausa hasta el …», y el freno de 30 días cae sobre LUIS.
  - En «Autorizaciones de contacto», elegir a Ana pinta «Socio #812 · Ana» con el libro de Luis; «Pedir», «Confirmar» y «Retirar» escriben en el de Luis.
  - Si no existe `members.id 812`: 404 «Esa persona no existe…» para un socio que sí existe.
- **Por qué impide (2):** escribe frenos y autorizaciones legales sobre la persona equivocada, con mensaje de éxito. No afecta a (1): hoy nadie tiene las llaves.
- **[S]** No comprobé en producción que los dos ids diverjan (sin acceso). Si coincidieran para todos los socios, el defecto quedaría latente.
- **Corrección mínima:** fijar qué id es «member» en el contrato. O el servidor acepta el id de la ficha (resuelve `personType=member` por `members.user_id`), o el CRM manda `members.id`, que hoy `/api/users` no le da. En los dos casos, con una prueba de `users.id ≠ members.id`. Las rutas de prospecto (`lead_id`) son correctas.

## NO BLOQUEANTES (no se corrigen en este cierre)
- **NB-1 · «Resolver revisión» aparece HOY sin llaves nuevas.**
  - `inbox-v2-page.ts:441` solo exige `can_resolve_review`, que hoy tienen Super Admin, Administrador, Recepción, Ventas y Asesor (`MarketingInboxAuthorizationService.php:59,62` en `f1fe6cac`). El servidor de hoy sí produce `reason=complaint` (`SalesEscalationService.php:51-52`).
  - Es seguro: el servidor lo autoriza, solo apaga la alerta (`MarketingStaffReviewService.php:9`) y «Sin indicar» manda `{}`. Con «Sí, era una queja» u «Otra cosa», el servidor de hoy resuelve e ignora la categoría, y el CRM lo dice («…este servidor no guardó la respuesta…»).
- **NB-2 · Hueco de prueba.** Si se quita `p.numberCurrent === true` de «Concedida» (`commercial-automation-text.ts:653`), siguen en verde 722 de 722 [C]. Hoy no falla nada: el servidor nunca manda `effective:true` sin `numberCurrent:true` (`OutreachConsents.php:151-155`).
- **NB-3 · A confirmar.** Las ayudas del diálogo de revisión («Sigue frenando: durante 30 días…») y el resultado «la queja sigue frenando» suponen `COMMERCIAL_EVENTS_ENABLED=true`. Con la bandera apagada, la queja de la IA no se registra y nada frena. En (2) lo cubre la condición (2)(a) de api-security.
- **NB-4 · Dos rojos previos fuera de la lista del lead:** `financial-realtime.integration.spec.ts` y `receivables-pagination.spec.ts`. Fallan igual aislados y en `a9e7e7a` (`Tests 2 failed | 23 passed (25)`) [C].

## Las siete preguntas
**1 · Servidor de hoy.**
- **Lo nuevo no aparece sin llaves** [L+C]: los permisos son los del servidor (`auth.service.ts:297`); la pestaña se filtra por llave (`commercial-automation-page.ts:217`) y `?pestana=autorizaciones` abre el Panel; la queja exige su llave (`complaint-record.ts:101`) y «No era una queja» también (`staff-review-complaint.ts:164`). Excepción: NB-1.
- **Mismo cuerpo de antes:** resolver sin elegir manda `{note}` y viaja `{}` (`marketing-inbox.service.ts:531-537`; el V1 llama con un solo argumento). Estimar y lanzar sin el motor van sin `fidelity` (`studio-lab-api.service.ts:100`), y la lista sin filtro también (`:150`).
- **Con llaves y sin rutas:** autorizaciones, 404 sin `code` → «Este servidor todavía no tiene las autorizaciones…»; queja, 404 sin `code` → «Este servidor todavía no permite registrar quejas.». En la práctica no ocurre: llaves y rutas llegan juntas.
- **Laboratorio:** `f1fe6cac` solo usa lo validado (`UltronStudioLabController.php:60,88,120`) e ignora `fidelity` [L]. El CRM marca `engineUnsupported` y no lanza (`studio-lab-session.ts:724,819`); si el filtro de la lista se ignora, avisa.

**2 · Contratos.**
- **Campos:** iguales a `OutreachConsents::list()/present()`, al validador de quejas y a `bdb93199` (`fidelity` en `:69,:214`; `fidelityMode` en `StudioLabRuns.php:509,955`) [L].
- **Idempotencia:** la misma clave solo sin respuesta clara (red, 5xx, 429, ilegible); otra tras un 2xx o un 4xx definitivo (`commercial-automation-session.ts:880`, `complaint-record.ts:292`).
- **Falla cerrado:** «Concedida» exige todas sus condiciones (`:653`) y un estado desconocido da «No se pudo leer». «Lo escribiría el modelo» solo con `origin === 'model'` estricto y cuadrado con la causa (`studio-lab-parse.ts:556`). Un 2xx de queja sin `source: staff` no se da por registrado (`complaint-record.ts:71`).
- **Excepción:** B-1.

**3 · Condiciones de seguridad.**
- `last_seen_ai_action_id` se lee en `ngOnInit`, al abrir, y viaja solo con `not_a_complaint` (`staff-review-complaint.ts:181`; `marketing-inbox.service.ts:535`): **CUMPLE**.
- `numberRevoked`: solo «Retirada para este número», sin ficha, autor, número ni fecha (`commercial-automation-text.ts:635-642`; «Desde el» no se pinta ahí, `ca-consents.ts:436`): **CUMPLE**.
- Evidencia interpolada (`ca-consents.ts:179,229,261`): **CUMPLE**.
- El texto de «No era una queja» dice su alcance (`staff-review-complaint.ts:19`): **CUMPLE**.

**4 · Cliente.**
- 0 `innerHTML`, `bypassSecurity`, almacenamiento, `console`, `window.open` o `eval` en las líneas añadidas fuera de los specs [C, grep]. Borradores, notas, evidencia y texto legal van interpolados.
- `?tipo=lead&id=<lead_id>` sigue el patrón que ya existía (`staff-record.ts:334` `{member: user_id}`, `va-requests-tab.ts:195`). No es un teléfono ni un seudónimo.
- Los permisos del cliente solo deciden qué se ofrece; el servidor niega (`AuthorizationMap`, B1 en ruta y servicio, capacidad del Inbox).

**5 · Los dos añadidos.** Son mínimos y seguros. En una conversación que no es de queja no cambia nada: no hay botón, está probado y mi mutación Ma lo confirma. El enlace lleva las llaves de la pestaña más `ultron.view` y la bandera (`context-panel.ts:693`). Matiz: NB-1.

**6 · Regresiones.**
- Pantallas existentes: `member-details-modal` gana `flex-wrap` y un host vacío sin llaves; `context-panel`, el bloque solo con llaves; `inbox-v2-page`, el botón y dos métodos que releen. El servicio del Inbox amplía la firma de forma compatible; `access-control` suma +4 a la exclusión del Administrador; la página filtra la pestaña.
- Condiciones y simulación: solo cambia la etiqueta; el valor sigue siendo la clave. `studio-lab-*`: la simulación pinta lo mismo (`studio-lab-case.ts:213` con `motor()` en false).
- `a3a5cc9`: la línea quitada de `ca-panel.ts` era inalcanzable (con `falta` vacío, `configured` es por fuerza true); lo demás son comentarios, un texto y su aserción. La versión `2026-11-22.1` solo se fija en specs [C].

**7 · Pruebas.**
- Página y componentes reales con `HttpTestingController`; se fingen el servidor y `AuthService`. 0 `skip`, `only`, `todo` o `xit` añadidos; las aserciones quitadas se sustituyen por los valores nuevos, no se aflojan.
- Mis mutaciones (worktree temporal, restauradas): Ma, el botón para cualquier motivo → 1 rojo; Mb, `last_seen` con otra categoría → 1 rojo; Md, lanzar con `engineUnsupported` → 1 rojo; Me, aceptar cualquier 2xx de la queja → 1 rojo; Mc **sobrevive** (NB-2).

## Pruebas ejecutadas [C]
- `commercial-automation/**/*.spec.ts` con `IRON_BODY_BACKEND_DIR`=lote-4: `Test Files 22 passed (22) · Tests 722 passed (722)`.
- Inbox V2, `complaint-record`, `modules/components` y `models`: `Test Files 2 failed | 50 passed (52) · Tests 2 failed | 1166 passed (1168)`. Los 2 rojos son NB-4, previos.
- `studio-lab*`, `studio-param-row`, `studio-prompts-text` y `access-control*`: `Test Files 1 failed | 27 passed (28) · Tests 6 failed | 319 passed (325)`. Los 6 rojos son los previos de `access-control.moderation`.

## No verificado
- En producción: si `users.id` y `members.id` divergen (B-1), `COMMERCIAL_EVENTS_ENABLED`, y `role_permissions` con claves desconocidas [S].
- Ningún navegador ni servidor real; ni PHPUnit ni artisan. No repetí la compilación de producción ni la batería entera del Studio.

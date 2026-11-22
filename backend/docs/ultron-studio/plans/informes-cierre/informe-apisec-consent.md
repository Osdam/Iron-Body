# C9 · Autorizaciones de contacto — revisión de api-security-specialist (un pase, con veredicto)

Rama `cierre/consent` · 22-11-2026 · diff `dfcee951..HEAD` (7 commits). Leídos `informe-db-consent.md`, `informe-be-consent.md`, la Enmienda C9 y D9-15.
Marcas: **[C]** comprobado ejecutando · **[L]** leído · **[S]** supuesto. Sondas fuera del repo (`scratchpad/apisec-consent/ApisecConsentProbeTest.php`, 15 pruebas P1–P15)
en **SQLite** y en **PostgreSQL 14.24** (`ultron_studio_cierre_consent`): `OK` en los dos, con las mismas salidas. Árbol sin tocar salvo este informe.

## Veredicto

- **(a) Integrar y desplegar INERTE: APTO.** Sin texto legal no se escribe ninguna petición ni confirmación [C]; con el libro vacío las decisiones son las de antes [C];
  ninguna ruta es alcanzable sin sesión ni con una llave más débil que la del mapa [C]; ningún 500 por la base y ninguna fuga del número [C]. Matiz: «sin conceder las
  llaves» no es literal: Super Admin las tiene por código (`CrmPermission::byRole()`, `ROLE_SUPER_ADMIN => self::all()`) y desde el primer día puede listar, rechazar y
  RETIRAR. Es la dirección segura, pero C-1 y C-2 ya afectan a esas retiradas.
- **(b) Empezar a registrar autorizaciones reales: APTO CON CONDICIONES** C-1 a C-5. C-1 es una línea, C-2 un diseño pequeño (base + lector), C-3 un candado de
  alcance, y C-4 y C-5 son decisión y operación.

## Hallazgos

### BLOQUEANTES — ninguno.

### CONDICIONES (antes de registrar autorizaciones reales)

**C-1 · Una retirada de C9 no retiene el trabajo asistido de WhatsApp ya planificado** [C] · Alta (Ley 1581, revocación; D9-15 «gana la negativa»).
`Run/AssistedQueue.php:484` solo da la señal si el `denied` viene de `lead.consent_denied`. Sonda P11: socio con prospecto `granted`, retirada comercial por
`POST consents/revoke` → la escalera da `denied`, pero `AssistedQueue::optedOut($s, 'commercial', 'whatsapp')` da `false`. El trabajo sigue en la bandeja hasta su
`expires_at` (≤ 24 h, `OutreachPlanner::JOB_TTL_HOURS`) y alguien del personal escribe a quien acaba de retirar. **Corrección mínima** (rama bandeja, backend): añadir a
`$senales` `$medium === OutreachCatalog::MEDIUM_WHATSAPP && $s->policy("c9.whatsapp.{$purpose}.revoked") === true`. **Prueba negativa:** planificar, retirar,
pedir la bandeja → retenido con su motivo.

**C-2 · La retirada vale para el REGISTRO, no para el NÚMERO** [C] · Alta-media. `do_not_contact` calla a todo prospecto con el mismo número (`LeadFacts.php:179` y la
puerta `MarketingMessageDispatcher::phoneOptedOut()`), pero la retirada de C9 se guarda sin número (`ocon_phone_chk`) y el lector solo mira las claves de la persona
(`OutreachConsentFacts.php:125-136`). Sonda P10: socio con su prospecto de WhatsApp (número X) y un prospecto SUELTO (sin `member_id`) con X en otro formato,
conversación de WhatsApp y `consent_status = granted`. Retirar la comercial del socio → socio `denied`; el suelto sigue `granted` con `optOut` todo `false`: N1 pasa
y la plantilla sale a X. Con `do_not_contact` en el prospecto del socio, el suelto sí da `siblingDoNotContact: true`. La pantalla dirá «retirada» y el número seguirá
recibiendo. No es regresión (antes no había retirada de socios), pero contradice «toda señal negativa de cualquier fuente gana». **Corrección** (database-specialist
+ backend; architect si se prefiere otra forma): guardar en `revoked` el `phone_ref` del número de hoy (aflojar `ocon_phone_chk` solo para `revoked`, anulable) y
que el lector dé `c9.whatsapp.<f>.revoked = true` también cuando una retirada viva de OTRA clave tenga un `phone_ref` de `FactPass::phonesOf()` de la persona (la
regla de hermanos). Mínimo operativo mientras tanto: que retirar avise de los prospectos sueltos con el mismo número para marcarlos `do_not_contact` en Mercadeo.
**Prueba negativa:** la sonda P10, con el suelto en `denied`.

**C-3 · Las rutas no aplican el alcance del entrenador** [C] · Alta si se concede la llave (hoy nadie fuera de Super Admin). `OutreachConsents::person()` (:442) y
`visibleRequest()` (:471) resuelven con `SubjectResolver` (`DB::table`, sin `TrainerMemberScope`) y el id llega por query o cuerpo, no como `{member}`, así que
`EnforceTrainerMemberScope` no lo ve. Sonda P2: cuenta `Entrenador` vinculada, sin asignaciones, con `members.view` + `consents_record` → `GET members/{id}` 404,
pero `GET consents?personType=member&personId={id}` 200 y `POST consents/revoke` 201 (escribe). **Corrección mínima:** tras resolver, si la persona tiene socio y
`! app(TrainerMemberScope::class)->allowsMember($memberId)`, `OutreachException::notFound('person')` en `person()` y `notFound('consent')` en `visibleRequest()`.
Hasta entonces, no dar `consents_*` al rol Entrenador. **Prueba negativa:** P2 → 404 y 0 filas.

**C-4 · Cuatro ojos por CUENTA: la base impide la misma cuenta, no la misma persona** [C] · decisión del propietario. Comprobado: otra sesión de la misma cuenta, o
repetir con otro `clientRequestId` → 403 `four_eyes_required`; en PG, un INSERT directo con confirmador = registrador → `ocon_four_eyes_chk`; con registrador falso o
persona ajena → `ocon_resolves_request` (P8). Pero la base compara dos ids (sin FK a `admins`) y **todo Super Admin tiene `users.manage`**: puede crearse una segunda
cuenta y confirmarse a sí mismo. Condición: el propietario lo acepta por escrito (pendiente (d) de la Enmienda) y `consents_confirm` no llega a quien reparta cuentas
fuera de Super Admin; cuatro ojos por persona cuando haya vínculos verificados.

**C-5 · Reiniciar los trabajadores de cola tras la migración** [L] · operación. `OutreachConsentFacts::hasLedger()` (:229) memoriza `Schema::hasTable` por proceso, y
`DispatchOutreachJob` (`ShouldQueue`, `OutreachDispatcher.php:146`) corre en `queue:work`, que vive entre trabajos: un trabajador arrancado antes de migrar ignora el
libro en `personState()` (también las retiradas) hasta reiniciarse. `php artisan queue:restart` después de la migración, en el runbook.

### MENORES

- **M-1 · Oráculo del vínculo prospecto→socio** [C]. Con `consents_record` + `marketing.view` (sin `members.view`): prospecto de un socio → 403
  `required_permission: members.view`; suelto → 200; inexistente → 404 (`OutreachConsents.php:453`). Si Mercadeo ya enseña el vínculo, aceptable; si no, 404.
- **M-2 · La repetición ignora el texto legal** [C P7]. Mismo `clientRequestId` con otra `legalHash` → 200 `replayed`, no 409. Inofensivo (se guarda la del
  servidor); para cumplir «otra petición con el mismo id → 409», añadir `legal_version`/`legal_hash` a `$mismo` (:185-186).
- **M-3 · El tope temprano de cuerpo no cubre el prefijo** [C P15]. `ValidatePostSizeWithStudioCap::STUDIO_PATHS` (:56) solo nombra `ultron-studio`; los 16 KB de la
  ruta corren después de `TrimStrings`: 200 KB sin sesión → 401 aquí (cuerpo ya decodificado) y 413 en el Studio. Paridad con el resto del CRM; una línea para la de B7-d.
- **M-4 · Desvincular un prospecto mueve la frontera** [C P12]. Una retirada pedida como `lead` de un socio se guarda en `m:` (:450-459); si luego se le quita el
  `member_id`, el prospecto vuelve a `granted`. Raro; que Mercadeo lo sepa.
- **M-5 · La supresión 1581 borra también la retirada** [C P13]: después, el lector da `missing`. Es la supresión; la negativa futura vive en `do_not_contact`.
- **M-6 · Auditoría (9b)** [L]. El libro (solo inserción; quién, qué, cuándo, evidencia y texto legal) basta como constancia de las ESCRITURAS. Los
  `outreach.consent.*` no llevan IP ni sesión y las LECTURAS (evidencia, nombres del personal) no se registran, como en el resto del CRM. Si se quiere, IP y sesión al log.
- **M-7 · La evidencia admite marcado** [C P6]: `<script>…</script>` → 201 (`PlainText` estricto mira invisibles, no HTML). El CRM debe pintarla como texto.

## 1 · Rutas (`/api/admin/commercial-automation/`) [C P1, los dos motores; `route:list -v`]

| Método · ruta | Middleware | Llave del mapa | Dentro, en el servidor |
|---|---|---|---|
| `GET consents` | `api` (ProtectAdminPaths → EnforceAdminAuthorization → EnforceTrainerMemberScope) + `LimitRequestBodySize:16384` + `throttle:ultron-studio-read` | `consents_record` ∣ `consents_confirm` | `requireAny` (Controller:90) + ver persona antes de leer (Consents:444, :453) |
| `POST consents` | ídem + `throttle:ultron-studio-write` | `consents_record` | :99; texto legal (:100, :171); ver persona |
| `POST consents/{uuid}/confirm` (`whereUuid`) | ídem | `consents_confirm` | :121; texto (:122, :264); por uuid → 404 (:471-488); cuatro ojos (:259) |
| `POST consents/{uuid}/reject` | ídem | `consents_confirm` | :132; por uuid → 404 |
| `POST consents/revoke` | ídem | `record` ∣ `confirm` | :145; ver persona |

Sin sesión: 401 en las cinco. Token compartido: 403 (dominio excluido y `requireAny` exige un `Admin`). PUT, DELETE, PATCH o GET sobre rutas POST → 405; mayúsculas o
uuid no válido → 404. Mismo grupo, misma autenticación y mismos cupos que `ultron-studio/outreach/**`; el tope es más estricto (16 KB frente a 64 KB), salvo M-3.

## 7 · La combinación, rehecha (P9: 8 748 combinaciones, canal × finalidad × 3⁶ valores × C9 en la propia finalidad o en otra; 0 discrepancias)

| Canal | `lead.consent_denied` | Prospecto concede f | C9 ganador en f | dnc / hermano | → |
|---|---|---|---|---|---|
| WA o voz | `true` o ausente | — | — | — | denied |
| WA | false | — | `revoked` | — | denied |
| WA o voz | false | sí | ninguno o `granted` | — | granted (N1 niega aparte por dnc) |
| WA | false | no | `granted`, número de hoy | los dos `false` | granted |
| WA | false | no | `granted` | alguno `true` o ausente | missing |
| WA | false | no | `granted` de otro número, ninguno, o solo en OTRA finalidad | — | missing |
| voz | false | no | cualquiera | — | missing (C9 no toca la voz) |

0 casos en que una retirada dé `granted`; 0 en que la comercial de C9 valga para cobranza o servicio (`consent.whatsapp.collections` es siempre false,
`LeadFacts.php:253`). El despacho (`WhatsappTemplateChannel.php:240`) nunca concede lo que la escalera niega; sin C9 solo cambia en 96 combinaciones, todas con
`lead.consent_denied` AUSENTE y hacia lo estricto. Lo que la combinación pura no ve: C-1 (bandeja) y C-2 (mismo número).

## Las demás preguntas

2. **BOLA** [C]: la llave de ver va antes de leer (403 sin decir si existe); inexistente 404; por uuid, invisible = inexistente = 404; tipo ajeno 422. Asignación masiva:
   `phone`, `phone_ref`, `subject_key`, `kind`, `recorded_by_admin_id`, `confirmed_by_admin_id`, `actor_type` y `legal_hash` en el cuerpo se ignoran (P4). Huecos: C-3, M-1.
3. **Cuatro ojos:** C-4.
4. **Teléfono** [C P4]: el cliente no lo puede fijar; ni respuestas, ni errores, ni los logs capturados llevan el número, su forma con 57 ni el `phone_ref`; el motor solo
   guarda booleanos `c9.*` [L]. `phone_ref` = HMAC-SHA256 con subclave de `APP_KEY` (`PhoneRef.php:15-16, :52-69`): con la base sola no se invierte; con la base y
   `APP_KEY` se enumeran los celulares (como en todo `outreach_*`). Rotar `APP_KEY` deja las confirmaciones en `missing`; las retiradas siguen (no llevan número).
5. **Texto legal** [C P5]: sin texto, 409 `consent_legal_text_missing` al pedir y al confirmar y 0 filas (también con texto solo de otra finalidad); huella distinta o texto
   cambiado entre pedir y confirmar → 409 `consent_legal_text_changed`. Se guarda el sha256 del texto servido en el GET: prueba que la pantalla lo recibió, no que la persona lo leyó.
6. **Entrada** [C P6, P7, P14]: 30 evidencias hostiles (NUL, saltos, U+2028, C1, series con espacios, puntos, guiones, `ª` o ZWSP, cifras de ancho completo y árabes,
   correo con NBSP, 300/301 caracteres, 300 emojis, UTF-8 roto, array, número, booleano, null) y 12 campos fuera de rango → 201 o 422, **nunca 500**, en los dos motores.
   Idempotencia: lo mismo → 200 `replayed` y un efecto; otro contenido, otra cuenta u otro verbo con el mismo id → 409 (salvo M-2). La tanda: `{values}` solo recibe
   `(?, ?)`; 1 sentencia, 4 bindings para 2 claves, ningún literal de clave en el SQL.
7. **Libro ilegible:** la excepción sube y la foto falla entera (falla cerrado) [L `OutreachConsentFacts.php:86-90`; el único `catch` del canal envuelve la puerta, :176-182].
8. **Libro vacío** [C]: `tests/fixtures/**` sin cambios; `--filter replay` de `Run` OK (3); `OutreachConsentFactsTest` OK (16, con los 972 casos contra `dfcee951`);
   `SubjectAndSnapshotTest` OK; P9 con C9 ausente o false = la regla anterior; `OutreachConsentsApiTest` en PG OK (17, 545).
9. (a) **C-1**, confirmado. (b) **M-6**. (c) 1 sentencia por lote (+1 a conversaciones solo si hay confirmación ganadora), constante por lote. Lo único que un usuario
   infla son filas por persona, acotado a 30 escrituras/min por persona y 120 por red y a llaves de Super Admin: sin coste relevante. El 40/40 de `SubjectAndSnapshotTest`
   caerá al fusionar con la bandeja: es un presupuesto de pruebas, no de seguridad.
10. **Persona suprimida** [C P13]: la lista da 200 con 0 eventos y estado `none`; confirmar o rechazar su uuid → 404; el motor, `missing`.

## Lo que no pude verificar

- Producción (sin ssh, por encargo): migración, idioma de la base (`[[:cntrl:]]`, `[:alpha:]`), trabajadores de cola, quién tiene hoy `users.manage`.
- La pantalla del CRM (no está en este diff): cómo pinta la evidencia (M-7) y si Mercadeo ya enseña el vínculo (M-1).
- C-1 de punta a punta: probé `optedOut()` por reflexión con la foto real, no un trabajo planificado y reclamado.
- Las carreras con dos conexiones no las repetí (corrí la prueba de los autores en PG, en verde). La batería completa la corre el lead.

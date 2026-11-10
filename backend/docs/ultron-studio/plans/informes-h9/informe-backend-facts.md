# Hito 9 · paquete B9-facts · informe del `backend` (adaptadores de hechos)

**Fecha:** 09-11-2026; entrega 21:52 UTC y añadido del `phone_ref` (L9-29) 22:00 UTC. **Árbol:** `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h8-voice`, HEAD `ab54a256` (el árbol ya traía cambios del H8 sin confirmar; no son míos y no los he tocado). **Sin commit. Sin red. Producción: nada, ni para leer.**

**Estado: ACEPTADO por el lead con un añadido (`phone_ref`, L9-29), ya hecho.** 50 pruebas en verde en SQLite (915 aserciones) y en la puerta de PostgreSQL 14.24 (`UNIT_DB=ultron_studio_h9_facts`, 921 aserciones); Pint limpio; 23 mutaciones cazadas de 23 efectivas.

## 1. Qué entrega

| # | Encargo del lead | Dónde |
|---|---|---|
| 1 | Un adaptador de solo lectura por fuente, con `unknown` honesto y prefiltros acotados | `Facts/{Membership,Attendance,Payment,Receivable,Lead,Class,Valuation,VoiceConsent,Engine,ContactHistory,Canary}Facts.php`, `OperationalBrakes.php`, `CatalogDomains.php` |
| 2 | Unificación de la persona y seudónimo | `SubjectKey.php`, `SubjectResolver.php` + `SubjectWorld.php`, `Subject.php`, `SubjectRef.php` |
| 2b | Seudónimo del TELÉFONO para el tope diario por teléfono (L9-29) | `PhoneRef.php`; `FactPass::primaryPhoneOf()`; `SubjectFacts::$phoneRef`/`$phoneRefs`; `CoreProjection::phoneKeys()`/`sharedPhones()` |
| 3 | Foto de hechos por lotes, reproducible y serializable con huella estable | `BatchSnapshotBuilder.php` → `BatchSnapshot.php` / `SubjectFacts.php`; `FactSelection.php`, `FactPass.php`, `FactNames.php`, `FactValues.php`, `PhoneKey.php` |
| — | La unión con el núcleo de B9-core (no estaba en el encargo; hizo falta) | `CoreProjection.php` |
| 4 | Modelo de lectura del historial unificado (L9-3) | `SubjectTimeline.php` (provisional sobre los nombres de §6.2) |
| 5 | Pruebas Feature (SQLite + PG), escuchador de consultas, recuento por lote | `tests/Feature/Outreach/Facts/**` |

Todo vive en `app/Services/Outreach/Facts/` (30 ficheros). El contrato (§11) reserva también `Outreach/Subjects/**` a este paquete; el lead me dio solo `Facts/**` y ahí está todo (Q-14).

## 2. Las reglas que fija cada adaptador (lo que no es obvio)

**Tiempo.** El reloj ENTRA como argumento (`FactPass::$now`) y nadie lo lee. Los días son de calendario de Bogotá con `Core\RelativeTime` (la definición única de B9-core: `daysAgo`, `daysUntilDate`, `hoursSince`, `windowStart`); las marcas de tiempo de la base son hora de pared UTC; las columnas `date` se comparan con intervalos semiabiertos `[d, d+1)` (valen con `date` en PG y con `Y-m-d 00:00:00` en SQLite y dejan usar el índice).

| Fuente | Reglas |
|---|---|
| **Membresía** (`users`, `members`, `plans`, `membership_subscriptions`, `payments`) | Activa ⇔ plan y hoy de Bogotá ≤ `membership_end_date` (paridad con `MembershipService::isActive()` probada a las 04:50 y a las 05:00 UTC). `days_to_expiry` = `end_date` = días hasta la fecha **con signo** (0 = vence hoy) y `days_since_expiry` su opuesto (tipos del catálogo: `int`/`date_relative`). **Congelada**: congelar pone la fecha AYER (`MembershipFreeze::freeze`), así que sus días son desconocidos, no está activa y los prefiltros la excluyen: nunca parece «vencida ayer». Plan por NOMBRE (`MembershipAccess::planKey`) → id entero; si casan dos vigentes, ambiguo = desconocido. `days_as_member` ≥ 0 (un periodo futuro es desconocido). Menor: manda la fecha de nacimiento (socio o usuario), cumplir 18 hoy ya es adulto, 29-02 cumple el 01-03; sin fecha, la marca `is_minor` solo si dice menor; si no, desconocido. Solo sale el booleano. `renewedSince()`: cobro de membresía cobrado (`PaymentController::PAID_STATUSES`) en [desde, ahora]. Prefiltros `endingBetween()` / `endedBetween()` (extremos dentro, `min ≥ 1`) |
| **Asistencia** (`attendances` por `user_id`, índice `(user_id, captured_at)`) | Solo entradas. **Quien nunca vino = desconocido** (`ScanCommercialFacts.php:167-171`). `count_last_30d` con la ventana de `RelativeTime` (desde las 00:00 de Bogotá de hoy − 30); `weekly_rate` en CENTÉSIMAS (`decimal` de escala 2: 2/4,3 → 47). Lo posterior a «ahora» no cuenta. Prefiltro `inactiveSince()` (activos que vinieron alguna vez y nada desde las 00:00 de hoy − (N − 1)) |
| **Pagos** (`payment_transactions`, suscripciones) | Por `member_id` o `user_id`, nunca por teléfono. Enlace abierto = en vuelo con `checkout_url` y sin caducar (como `CommercialSubject`); estados en minúsculas y mayúsculas sin `LOWER()`. `declined_last_7d` es un RECUENTO (tipo del catálogo). Ningún importe sale. Prefiltro `abandonedLinks()` en [ahora − (gracia + 7 d), ahora − gracia] |
| **Cartera** (`receivables`, `receivable_payments`) | Vencida = `Receivable::scopeOverdue()`: viva por estado, con `due_at` y el día pactado YA pasado (hoy no vence). Un prospecto sin ficha no puede deber: `has_overdue = false` (no desconocido, porque la escalera niega con un desconocido). Prefiltro `overdueBetween()` con la **generación** = número de abonos (sube al abonar, no baja al revertir) |
| **Prospectos y WhatsApp** (`marketing_leads`, `_conversations`, `_followups`, `marketing_ai_actions`) | Hechos de UNO (el prospecto más reciente); señales negativas de CUALQUIERA. `consent.whatsapp.commercial` ⇔ el prospecto de WhatsApp (con conversación de WhatsApp y número) cumple `canContactProactively()`; `.service` ⇔ admite respuesta y ventana abierta (< 24 h, `ContactPolicy`); `.collections` = false siempre (P9-15). **Hermanos**: otro prospecto con `do_not_contact` y el mismo número normalizado que cualquiera de la persona (prospectos y ficha de socio) calla a la persona — `PhoneKey` tiene paridad probada con `MarketingMessageDispatcher::normalizePhone()`. Opt-out comercial: el ÚLTIMO gesto, como `CommercialSubject`. Sin prospecto se SABE que no hay conversación (`human_in_control = false`, ventana cerrada, sin seguimiento). `lead.last_inbound_at` = −horas hasta el último entrante. Prefiltro `followupsDue()` (solo `message`/`call`) |
| **Clases** (`classes`, `class_reservations`, `class_sessions`, `class_attendances`) | Las reglas de `ClassBookingService` sin reinventarlas: ocurrencias de `MyClass::occurrenceDateTimeInWeek()` (pura), cupo con las reservas de la fecha MÁS las heredadas sin fecha, sesiones iniciadas o cerradas fuera. `reserved_next_7d`: clase activa, fecha en [hoy, hoy+7] o heredada sin fecha. `attended_type_last_60d`: el tipo (slug que empieza por letra) de la última asistencia presente/tarde en [hoy−60, hoy]; null si ninguna. `upcomingWithCapacity()` y `candidatesForOccurrence()` |
| **Valoraciones** (`valuation_appointments`, `physical_evaluations`) | Última = la más reciente entre cita completada y evaluación física; nunca valorado = desconocido (DF-K3). `has_upcoming` / `next_date` (≥ 0; en curso = 0). Prefiltro `outdated()` con `last_valuation_id` (Q-3) |
| **Voz** (`voice_contacts`, `voice_consents`, `voice_exclusions`, `voice_rne_checks`) | El patrón de `VoiceLeadSignals` (columnas justas, nunca `phone_encrypted`) y las reglas PURAS del H8 para no tener dos verdades: `VoiceConsentRule::hasConsent(…, false)` (cuatro ojos E3-4, grabación apagada P-7) y `VoiceExclusionRule` (alcance `contacts`, `voice.contacts.rne_validity_days`: 0 = ninguna vigente). Exclusiones (también «no volver a llamar») por huella del número. Voz para cobranza o servicio: nunca (D9-15) |
| **Motor comercial** (`commercial_opportunities`, `commercial_events`) | `nba.open_goal`: el abierto de mayor prioridad; sin ninguno, **null** (DF-T7). Eventos con la forma de `Core\FactSnapshot` (`{key, at, attrs}`; `attrs` solo `plan_id` de los de pago; de la carga nunca salen importes ni referencias). **Con `COMMERCIAL_EVENTS_ENABLED` apagada (hoy en producción) la lista es null = fuente sin datos (DF-C4)**, y también las transiciones |
| **Transiciones** | Las que PRUEBA el estado (vencida → `active→expired` a las 00:00 de Bogotá del día siguiente a la fecha; congelada → `active→frozen`) más las que prueban los eventos (`membership_expired/renewed/activated`, `payment_approved/failed/expired`) |
| **Historial de contactos** (`marketing_messages`+`_conversations`, `voice_calls`+`voice_contacts`, tablas del H9) | Solo los salientes PROACTIVOS de la IA (`kind ≠ reply`, como `ContactPolicy`), las salientes de voz en `VoiceFrequency::COUNTED_STATUSES` al número de un contacto de la persona y los asistidos registrados por una persona en `live`. Nunca respuestas, entrantes ni lo que no salió. Fuentes con los nombres de `ContactBudget` (`ai_proactive`, `voice_call`, `outreach`); de `metadata` solo se lee `kind` (el resto lleva el número del destinatario) |
| **Canarios** (`ultron_studio_canary_numbers`) | Teléfonos de la persona → E.164 → `PhoneFingerprint` (`ultron-canary-phone/1`) en memoria; `canary.whatsapp` / `canary.voice` ⇔ canario ACTIVO de ese canal |
| **Frenos ajenos** (`OperationalBrakes`, único fichero que los nombra) | `UltronAbortLatch::engaged()`, `MarketingAgentSwitch::isPaused()`, `VoiceBrake::isOpen()`, `commercial.autonomy_enabled`, `marketing.followups.scheduler_enabled`; claves = los motivos de N2. **Falla cerrado**: tabla ausente o error = frena y queda en `unreadable` (los servicios de ULTRON fallan abierto a propósito; el H9 inicia contactos) |

## 3. Persona y seudónimo

- `subject_key`: `m:<id>` / `l:<id>` (patrón estricto, sin ceros a la izquierda). El socio manda (`EvaluateCommercialSubject::lockKey`); las fichas de socio que comparten `identity_id` o `user_id` son UNA persona y su clave es la de **menor** id (estable: una ficha duplicada nueva no cambia la clave de quien ya está inscrito); el teléfono no une personas; una clave que no existe queda sin resolver (`unknown_subject`), y la foto conserva la clave pedida y la canónica (un prospecto que se hizo socio después lo ve B9-run).
- `subject_ref` = `HMAC-SHA256(subject_key)` con la subclave `HMAC-SHA256(APP_KEY, 'outreach-subject/1')`, 64 hex (L9-4). Vector probado contra la derivación literal, distinto de la etiqueta de canarios y de otra clave.
- Resolución por lotes: 3–5 sentencias para N personas (leads pedidos, socios, expansión por identidad/usuario, leads de los socios).
- **`phone_ref` (L9-29, el tope diario también por teléfono):** `HMAC-SHA256(número normalizado)` con la subclave `HMAC-SHA256(APP_KEY, 'outreach-phone/1')`, 64 hex; el número es el de `PhoneKey::normalize()` (la identidad de la puerta de WhatsApp y de la regla de los hermanos), así que `3001234567`, `+57 300 123 4567` y `573001234567` dan el mismo. Por persona salen dos cosas: `phone_ref`, el de su número PRINCIPAL (el del prospecto más reciente con un número válido; si no, el de su ficha de socio canónica; si no, el de sus otras fichas), y `phone_refs`, los de TODOS sus números, ordenados, para la lectura estricta del tope (un contacto de hoy a cualquiera de sus números ocupa el día de quien lo comparta). Sin teléfono, o con uno que la puerta no reconoce (un fijo), `phone_ref` es null y `phone_refs` `[]` (desconocido). Se calculan con la política (el tope es del presupuesto) sobre las filas que la política ya leyó: **cero consultas de más** (el recuento sigue en 39 y 8). Sin política, `phone_refs` es null (no se pidió). El teléfono no une personas: dos personas con el mismo número siguen siendo dos `subject_ref` y un solo `phone_ref`. Etiqueta propia: ni `outreach-subject/1`, ni `ultron-canary-phone/1`, ni `voice-phone`.

## 4. La foto por lotes y la unión con B9-core

`BatchSnapshotBuilder::build(keys ≤ 500, now, FactSelection)` → `BatchSnapshot` (`outreach-facts/1`): `{at, day, global, schema, sources, subjects: {clave: {canonical, contacts, events, facts, policy, ref, transitions}}}`, JSON canónico de `Core\CanonicalJson`, `hash()` = sha256. `fromArray()` es estricto (todas las rutas a la vez; rechaza hechos fuera del catálogo, tipos, nulos donde no caben, políticas inventadas, un `day` que no es el de Bogotá).

**La unión** (`CoreProjection`, pura): `selectionFor(CanonicalCondition::uses())` (solo lo que usa la estrategia + lo de N1–N4), `snapshot(SubjectFacts, strategyUuid)` → `Core\FactSnapshot::fromArray()` (contactos con `strategy` self/other/null), `budgetHistory()` → `ContactBudget::check()`, `phoneKeys()` (los `phone_refs` de la persona) y `sharedPhones()` (`phone_ref` → personas del lote que lo comparten, para que en una pasada solo una se lleve el contacto del día), `policyInput()` → las claves de persona de `PolicyLadder::SPEC` (`subject`, `consent`, `optOut`, `voice`, `history`, `conversation`, `identity` y los frenos/banderas ajenos). Probado contra el `ConditionEvaluator` y el `PolicyLadder` REALES con la `baseInput` del fixture de TEST9-a. `policyInput()` se niega (excepción) si la foto no trae los contactos de una persona resuelta: una lista vacía diría «nunca se le contactó».

**Ojo, B9-core cambió cuatro veces durante el paquete** (catálogo de 39 → 42 hechos y tipos nuevos, `FactSnapshot` por referencias → por listas, `ContactBudget::evaluate` → `check`, `RelativeTime::UNIT_*` retirado, `PolicyLadder::SPEC` rehecho). `FactsCoreUnionTest` ata nombres, tipos, nulos, fuentes del presupuesto, motivos de N2 y claves de la escalera: si vuelve a cambiar, esa prueba cae.

## 5. Contratos nuevos (para B9-run, S9 y SEC9)

- Hechos escalares: los 39 de `OutreachCatalog::export()['facts']` menos `contact.*` (`FactNames::FACT_TYPES`); política por persona (`FactNames::POLICY`, 14 booleanos: consentimiento por finalidad×canal, opt-outs, hermanos, exclusión y RNE de voz, canarios); frenos globales (`OperationalBrakes::KEYS`).
- Por persona, además: `phone_ref` (?64 hex) y `phone_refs` (lista ordenada de 64 hex, o null si no se pidió la política). `BatchSnapshot::fromArray()` exige que `phone_ref` sea uno de `phone_refs`.
- Prefiltros: devuelven `{candidates: list<Candidate{subjectKey, event, cycleParts, cursor}>, next: ?cursor}`, ≤ 500 por página, ordenados por id; la clave de ciclo la arma el núcleo con `OutreachCatalog::cycleKey($event, $cycleParts, $now)`.
- Historial: `SubjectTimeline::forSubject(subject_key, ≤ 50, cursor)` → `{entries: [{at, kind, channel, medium, direction, strategy, step, reason, result, level, mode, nextAction, nextAt}], next, available}`; cursor opaco estable (instante desc, clase, id desc).
- Sin rutas, sin migraciones, sin variables de entorno nuevas. Configuración que se LEE: `app.key`, `commercial.events_enabled`, `commercial.autonomy_enabled`, `marketing.followups.scheduler_enabled`, `voice.contacts.rne_validity_days`.

## 6. Verificación (ejecutada)

| Comando | Resultado |
|---|---|
| `php -d memory_limit=2G vendor/bin/phpunit tests/Feature/Outreach/Facts` | **OK, 50 pruebas, 915 aserciones** (SQLite) |
| `UNIT_DB=ultron_studio_h9_facts docs/ultron-studio/ops/pgunit.sh tests/Feature/Outreach/Facts` | **OK, 50 pruebas, 921 aserciones** (PostgreSQL 14.24, base desechable creada para el paquete) |
| `vendor/bin/pint --test app/Services/Outreach/Facts tests/Feature/Outreach/Facts` | `passed` |
| Escuchador de consultas (`FactsReadOnlyTest`) | **167 sentencias (169 en PG), 0 que no sean `select`**, ninguna `FOR UPDATE`, ninguna fila cambia en 35 tablas, y cada adaptador toca SOLO sus tablas enumeradas (`TABLES`) |
| Recuento por lote (`SubjectAndSnapshotTest`) | **39 consultas para la foto completa** (todos los hechos, política, frenos, contactos, transiciones y los 27 eventos), **igual con 3 que con 45 claves**; **8** si solo se pide membresía. El recuento es constante por lote (sin N+1) |
| Mutaciones (script en el scratchpad, restaurando cada fichero) | **23/23 cazadas**: las 3 del `phone_ref` (etiqueta de persona en vez de teléfono, principal del socio en vez del prospecto, número sin normalizar) y vence hoy, congelado (prefiltro y días), menor, nunca vino, inactividad, deuda el día pactado (filtro SQL), enlace caducado, hermanos, último gesto, ventana 24 h, grabación, respuestas, llamadas no salidas, eventos apagados, reservas heredadas, canónica, prospecto convertido, etiqueta del seudónimo, freno ilegible. Dos mutantes iniciales eran equivalentes o imposibles (la condición PHP de la deuda repite el filtro SQL; un `member_id` colgante lo impide la FK) y se cambiaron por los dos últimos de la lista |

Casos límite cubiertos: el mismo número escrito de tres formas en dos prospectos y un socio (mismo `phone_ref`, tres `subject_ref`), dos números distintos, sin teléfono, un fijo, una clave sin resolver, el principal frente a la lista, N y N+1 días, los dos extremos de cada intervalo (vencida 3..5, cartera 7..30, 30 días de asistencia al segundo, 60 días de clases, 30/29 de valoración, 14/13 de inactividad, 6 h justas de cortesía), el día de Bogotá frente al UTC a las 04:50 y a las 05:00 UTC, la medianoche de Bogotá, el 29-02, cumplir 18 hoy, desconocidos, prospecto convertido, fichas duplicadas por identidad, número compartido por una familia, futuros que no cuentan, fuente de eventos apagada.

**No verificado:** rendimiento con volumen real (es de PERF9; las 39 consultas son por lote, pero no medí tiempos ni planes con datos), los planes de PG con datos (la base de pruebas está vacía), producción (prohibido), y el historial contra las tablas reales de DB9 (no existen todavía: se probó con un esquema mínimo de §6.2 creado por la prueba).

## 7. Q-n y lo que decidió el lead (L9-26..L9-29)

| # | Duda | Estado |
|---|---|---|
| Q-1 | Con `COMMERCIAL_EVENTS_ENABLED` apagada (producción hoy), eventos y transiciones son null = fuente sin datos (DF-C4): toda condición con `event`, `count:event:` o `transition` da `unknown` | **Decidido:** dependencia de la fase de sombra; encender la bandera es del propietario (P9-24). Las condiciones de hechos (vencimientos, cartera, asistencia, clases, valoraciones) funcionan sin eventos. Sin cambio de código |
| Q-2 | `cls:{class_session_id}` no existe para una ocurrencia futura (la fila nace al iniciar la clase) | **Aceptado:** `cls:{class_id}:{session_date}`. Los `cycleParts` de `ClassFacts::candidatesForOccurrence()` ya llevan `class_id` y `session_date` (la prueba lo fija). **Falta en B9-core:** la plantilla del evento en `OutreachCatalog` y `validCyclePart()` para `class_id` (entero) y `session_date` (`Y-m-d`); hoy `session_date` caería en la rama de enteros y la clave saldría null |
| Q-3 | `val:{last_valuation_id}` mezcla ids de dos tablas | Abierta (propuesta: solo citas, o `val:{a|e}{id}`) |
| Q-4 | Los contactos de fuera del H9 llevan finalidad `commercial` | Abierta (es lo que exigen `Core\FactSnapshot` y `ContactBudget`) |
| Q-5 | `marketing_ai_actions` (N1 `commercial_opt_out`) no estaba en §6.3 | **Aceptado:** entra en §6.3 como tabla de solo lectura |
| Q-6 | Importaciones de las reglas puras del H8 y de `PaymentController::PAID_STATUSES` | **Aceptado:** SEC9 las añade a su lista |
| Q-7 | Persona canónica = la ficha de menor id del grupo | Abierta (estabilidad de las claves inscritas) |
| Q-8 | Dos personas con el mismo número no se unían en el tope diario | **Decidido (L9-29):** el tope diario se aplica TAMBIÉN por teléfono. Hecho aquí: `phone_ref`/`phone_refs` en la foto y `CoreProjection::phoneKeys()`/`sharedPhones()`. **Queda para B9-run:** aplicarlo entre pasadas (guardar el `phone_ref` en el trabajo y contar por él), y decidir si bloquea con `phone_ref` (el principal) o con `phone_refs` (todos: lo estricto, lo que recomiendo) |
| Q-9 | Un socio sin fecha de nacimiento: `is_minor` desconocido | **Decidido (L9-17):** `unknown`, sin negar; decide la asesoría. Sin cambio |
| Q-10 | `lead.followup_due` sin las tareas internas | Abierta |
| Q-11 | `class.reserved_next_7d`: [hoy, hoy + 7] y las heredadas cuentan | Abierta |
| Q-12 | `membership.active` sigue a `MembershipService` e ignora `users.status` | Abierta |
| Q-13 | `SubjectTimeline` provisional sobre §6.2 hasta que DB9 congele columnas | Abierta (se ajusta al llegar sus migraciones) |
| Q-14 | Informe y carpeta: los del encargo, no los de §11 | Abierta (informativa) |
| Q-15 | `facts_json` ≤ 4 KB frente a listas de contactos y eventos | Abierta: B9-run pide solo lo que usa la estrategia (`selectionFor`) y vigila el tope |
| Q-16 | `phone_ref` de un FIJO o de un número que la puerta de WhatsApp no reconoce: desconocido (no hay normalización de fijos en el código; el tope por teléfono no los junta) | Nueva, para el lead: ¿se quiere una normalización de fijos (`57` + 60X…) solo para el `phone_ref`? |

## 8. Para otros paquetes

### 8.1 Índices que pido a DB9 (`database-specialist`)

No son mis ficheros: los propongo y los decide DB9. Medido en el PG de pruebas con `pg_indexes` (09-11, 21:50 UTC); hoy son tablas pequeñas, pero el contrato presupone prefiltros indexados (D9-5, D9-16) y PERF9 no ha medido todavía con volumen.

| Tabla | Falta | Quién la consulta y cuándo | Propuesta |
|---|---|---|---|
| `users` | ningún índice en `membership_end_date` | `MembershipFacts::endingBetween()`/`endedBetween()` (barrido de vencimientos, cadencia de 60 min del catálogo) y el «activo hoy» de `AttendanceFacts::inactiveSince()`, `ValuationFacts::outdated()` y `ClassFacts::candidatesForOccurrence()` | `(membership_end_date)`, o parcial `WHERE membership_frozen_at IS NULL AND plan IS NOT NULL` |
| `payment_transactions` | ningún índice en `user_id` (en PG la FK no lo crea) | la foto (`member_id IN (…) OR user_id IN (…)`): con un lado sin índice no hay `BitmapOr` | `(user_id, status)` |
| `class_reservations` | `member_id` solo es la segunda columna del único `(class_id, member_id, session_date)` | `ClassFacts::facts()` (`member_id IN (…)`) y el `NOT EXISTS` de `candidatesForOccurrence()` | `(member_id, session_date)` |
| `receivables` | el índice con `due_at` empieza por `type`, que el prefiltro no filtra | `ReceivableFacts::overdueBetween()` (`status IN (…) AND due_at` en un intervalo) | `(status, due_at)`, o parcial `WHERE status IN ('pending','partially_paid')` |
| `voice_contacts` | ningún índice en `(source_type, source_id)` | `VoiceConsentFacts` y `ContactHistoryFacts` (la persona ↔ sus contactos de voz) | `(source_type, source_id)` |

Lo demás que filtran mis consultas ya tiene índice (comprobado con `pg_indexes` en el mismo PG): `attendances (user_id, captured_at)`, `marketing_ai_actions (lead_id, created_at)`, `ultron_studio_canary_numbers (channel, phone_hash)` parcial, `marketing_followups (status, due_at)`, `marketing_leads (member_id)` y `(do_not_contact)`, `marketing_conversations (lead_id, status)`, `marketing_messages (conversation_id, created_at)`, `commercial_events (member_id|marketing_lead_id, occurred_at)`, `commercial_opportunities (member_id|marketing_lead_id, status)`, `valuation_appointments (member_id, starts_at)`, `physical_evaluations (member_id, created_at)`, `class_attendances (member_id)`, `voice_calls (remote_hash, created_at)`, `voice_exclusions (phone_hash)`, `voice_rne_checks (phone_hash, checked_at)`, `members (user_id)` único e `(identity_id)`, `membership_subscriptions (user_id)` y `(member_id)`.

Para las tablas del H9 que lee `SubjectTimeline`: `outreach_decisions (subject_key, evaluated_at, id)`, `outreach_jobs (subject_key, created_at, id)` y, para los resultados por persona, `outreach_enrollments (subject_key)` con `outreach_outcomes (enrollment_id, occurred_at)`. Y, para el tope por teléfono de L9-29, que B9-run guarde el `phone_ref` en `outreach_jobs` con índice `(phone_ref, budget_day)` o un contador diario por `phone_ref` (como L9-2).

### 8.2 Los demás

- **B9-core:** la plantilla y las piezas de Q-2 (arriba).
- **B9-run:** usar `CoreProjection::selectionFor()` con lo que usa la estrategia; `cycleKey()` del núcleo con los `cycleParts`; una lista null es «no hay datos»; la clave canónica puede diferir de la inscrita (prospecto que se hizo socio); el tope por teléfono con `phoneKeys()` y `sharedPhones()` y, entre pasadas, con el `phone_ref` guardado.
- **SEC9:** la lista cerrada de tablas por fichero está en cada `TABLES` y la vigila `FactsReadOnlyTest`; las importaciones de Q-5/Q-6; `OperationalBrakes` es el único que nombra los frenos ajenos; `PhoneRef` y `SubjectRef` son las dos únicas derivaciones de `APP_KEY` de este espacio (etiquetas `outreach-phone/1` y `outreach-subject/1`); OPS9 debe añadir `phone_ref` a la nota de rotación de L9-4.
- **TEST9:** casos límite que conozco desde dentro: congelados con fecha artificial, fichas duplicadas por identidad, el número de la familia (un `phone_ref` y varios `subject_ref`), reservas heredadas sin fecha, la fuente de eventos apagada, `metadata.recipient` de los mensajes (nunca debe salir), los cambios de API del núcleo.
- **PERF9:** 39 consultas por lote para la foto completa (8 solo membresía); las agregaciones de asistencia y de eventos recorren el historial de las personas del lote.

## 9. Ficheros (todos nuevos, sin seguimiento)

- `app/Services/Outreach/Facts/`: `AttendanceFacts.php`, `BatchSnapshot.php`, `BatchSnapshotBuilder.php`, `CanaryFacts.php`, `Candidate.php`, `CatalogDomains.php`, `ClassFacts.php`, `ContactHistoryFacts.php`, `CoreProjection.php`, `EngineFacts.php`, `FactNames.php`, `FactPass.php`, `FactSelection.php`, `FactValues.php`, `LeadFacts.php`, `MembershipFacts.php`, `OperationalBrakes.php`, `PaymentFacts.php`, `PhoneKey.php`, `PhoneRef.php`, `ReceivableFacts.php`, `Subject.php`, `SubjectFacts.php`, `SubjectKey.php`, `SubjectRef.php`, `SubjectResolver.php`, `SubjectTimeline.php`, `SubjectWorld.php`, `ValuationFacts.php`, `VoiceConsentFacts.php`.
- `tests/Feature/Outreach/Facts/`: `AgendaEngineHistoryFactsTest.php` (8), `FactsBrakesTest.php` (3), `FactsCoreUnionTest.php` (3), `FactsReadOnlyTest.php` (1), `LeadAndVoiceFactsTest.php` (8), `MembershipFactsTest.php` (9), `MoneyAndAttendanceFactsTest.php` (7), `PhoneRefTest.php` (4), `SubjectAndSnapshotTest.php` (5), `SubjectTimelineTest.php` (2), `Support/FactsWorld.php`.
- `docs/ultron-studio/plans/informes-h9/informe-backend-facts.md` (este).

Nada fuera de esas carpetas: ni `Marketing/**`, `Telephony/**`, `UltronStudio/**`, `Core/**`, migraciones, rutas ni configuración. Una base nueva en el PG desechable local (`ultron_studio_h9_facts`).

## §Arreglo con DB9 (lead)

Encargo del lead (09-11): las 3 pruebas de `tests/Feature/Outreach/Facts/` que suponían que DB9 no existía (`informe-db.md` §10.3 y §12). Solo se tocaron `app/Services/Outreach/Facts/**` y `tests/Feature/Outreach/Facts/**`; sin commit.

**Qué cambió**

- `SubjectTimelineTest`: fuera el esquema provisional; las filas son reales, construidas con `OutreachSchemaFixtures` de DB9 (sin editarlo): `liveVersion()` → inscripción `live` en `waiting` de `m:7` → decisiones `enroll` y `defer` N1 (`retry_at`) de `m:7`, una `deny` N2 de `m:8` sin inscripción (sello de la versión), un trabajo asistido por WhatsApp `planned → claimed → dispatching → started` con su reclamación, y un resultado `staff` `no_answer` con `next_action = wait` y su hora. Pasan por todos los CHECK y disparadores de la base en los dos motores. La primera prueba ahora afirma que las 6 tablas existen y que una persona sin historial sale `{entries: [], next: null, available: true}`.
- `SubjectTimeline::outcomes()` (§12 de DB9): filtra por `o.subject_key` (antes `e.subject_key` vía la inscripción), toma `strategy_id`, `channel`, `next_action` y `next_action_at` del propio resultado (L9-3) y deja la inscripción solo para `mode` (que el resultado no lleva) y el trabajo para `medium` y `step_index`. **Misma forma de salida**; cambia el valor de `nextAction`/`nextAt` en la entrada `outcome`: antes `follow_up` + `next_due_at` deducidos de la inscripción viva, ahora la transición de D9-10 registrada (`next_step` | `retry_step` | `wait` | `end_success` | `end_exit` | `handoff_human`) y su hora (solo las tres primeras la llevan, `oout_next_action_chk`). La prueba comprueba además el SQL: la consulta de resultados lleva `"o"."subject_key" = ?` y no `"e"."subject_key"`. Plan en el PG de pruebas con `enable_seqscan = off`: `Index Scan Backward using oout_subject_idx … Index Cond: subject_key = 'm:7'`, y los tres JOIN por clave primaria. Fuera `LIVE_ENROLLMENT` (ya no se usa).
- `AgendaEngineHistoryFactsTest::test_historial_cuenta_…`: la aserción es honesta (`Schema::hasTable('outreach_outcomes')` y `sources.outreach_history` son `true`) y lo demuestra: un contacto asistido en vivo registrado por una persona (`answered`) sale como tercer contacto con `source = outreach` y su estrategia, y el resultado del canal simulado en sombra no cuenta. Caso de ausencia nuevo, `test_sin_las_tablas_del_h9_…`: el estado anterior a DB9 lo produce el `down()` de la 100002 dentro de `DB::beginTransaction()`/`rollBack()`, como hacen las pruebas de esquema de DB9 (se niega con filas; aquí no las hay): `outreach_history = false` y el historial sigue contando lo demás; al salir, las tablas vuelven y la detección se recalcula.
- `ContactHistoryFacts::hasOutreachSource()`: memoizado **por proceso y conexión** (`DB::connection()->getName()`), no por instancia; `forgetOutreachSource()` lo reinicia (pruebas que crean o quitan las tablas). `SubjectAndSnapshotTest::test_una_consulta_por_fuente…` olvida la detección, mide la primera foto del proceso y las dos siguientes, y afirma que solo la primera paga el catálogo y que 3 y 45 claves cuestan lo mismo. Tope en 40 con el porqué.

**Cifras (SQLite y PG `ultron_studio_h9_factsfix`, 09-11)**

- `tests/Feature/Outreach/Facts`: antes 50 pruebas, 1 error + 2 fallos; ahora **51 / 51** (SQLite 956 aserciones, 17 s; PG 962, 34 s). `AgendaEngineHistoryFactsTest` pasa de 8 a 9; `SubjectTimelineTest` sigue en 2.
- Consultas por lote de la foto completa: **39** con la detección memorizada (38 de la foto + 1 de `outreach_outcomes`), iguales con 3 y con 45 claves; **43** la primera del proceso (las 4 de catálogo). Corrección al diagnóstico de §10.3 de DB9: las 39 anteriores a DB9 ya incluían un `hasTable` (cortaba en la primera tabla ausente), por eso el lote no sube a 40. Solo membresía: 8.
- `FactsReadOnlyTest`: 175 sentencias revisadas en SQLite (antes 183: menos consultas de catálogo), 177 en PG; 0 escrituras.
- Pint sobre los 5 ficheros tocados: sin cambios.

**Para el lead**

- La entrada `outcome` del historial sigue leyendo `reason` de `detail_code`; L9-3 llama «motivo» a `reason_code` (un `blocked` del sistema lo lleva obligatoriamente y `detail_code` puede ir vacío). No lo cambié: decide si `reason` debe ser `reason_code ?? detail_code`.
- No toqué `SubjectTimeline::available()` (por instancia; no está en la ruta del lote) ni `OperationalBrakes` (lee el catálogo en cada pasada a propósito).
- Ficheros: `app/Services/Outreach/Facts/ContactHistoryFacts.php`, `app/Services/Outreach/Facts/SubjectTimeline.php`, `tests/Feature/Outreach/Facts/SubjectTimelineTest.php`, `tests/Feature/Outreach/Facts/AgendaEngineHistoryFactsTest.php`, `tests/Feature/Outreach/Facts/SubjectAndSnapshotTest.php` y este informe.

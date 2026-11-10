# catalog-snapshot.json · OutreachCatalog (D9-3, D9-4; API #1 «GET strategies/catalog»)
#   catalog        · la EXPORTACIÓN canónica del catálogo: OutreachCatalog::export() tiene que dar exactamente este objeto
#   catalogHash    · sha256 del JSON canónico de `catalog` (StudioContentHasher::canonicalJson)
#   annotations    · descripción, fuente y requisitos de cada entrada (NO entra en la huella: es documentación)
#   policyInputs   · las entradas de la escalera que no son hechos de condiciones (consentimiento, exclusiones, frenos):
#                    NO entran en la huella del catálogo; las fija policy-ladder-cases.json
#   compatibility  · casos: una versión de estrategia frente al catálogo vigente (catalog_incompatible)
#   cycleKeys      · casos: la clave de ciclo de cada disparador (la identidad de la ocurrencia, D9-3 y D9-8 etapa 2)
CATALOG_VERSION = "outreach-catalog/2026-11-10.2"  # .2 (L9-29): la clave de ciclo de las clases es cls:{class_id}:{session_date}, keyVersion 2
GRAMMAR = "outreach-conditions/1"

GOALS = ["collect_data", "book_visit", "book_assessment", "close_plan", "collect_payment", "recover_payment_link",
         "activate_membership", "complete_onboarding", "link_app", "increase_adherence", "renew", "upgrade", "cross_sell",
         "reactivate", "request_referral", "resolve_objection", "recover_satisfaction", "escalate"]
assert len(GOALS) == 18
VOCAB_EVENTS = ["lead_created", "lead_qualified", "offer_presented", "payment_link_created", "payment_approved", "payment_pending",
                "payment_failed", "payment_expired", "membership_activated", "membership_renewed", "first_checkin",
                "attendance_milestone", "inactivity_detected", "renewal_window_opened", "membership_expired",
                "appointment_created", "appointment_completed", "invoice_requested", "invoice_validated", "invoice_rejected",
                "app_linked", "objection_raised", "complaint_created", "customer_satisfied", "referral_opportunity",
                "offer_rejected", "human_requested"]
assert len(VOCAB_EVENTS) == 27 and len(set(VOCAB_EVENTS)) == 27
LEAD_PHASES = ["NEW_LEAD", "RAPPORT", "DISCOVERY", "GOAL_DISCOVERY", "BARRIER_DISCOVERY", "QUALIFICATION", "VALUE_BUILDING",
               "RECOMMENDATION", "OBJECTION_HANDLING", "BUYING_SIGNAL", "CLOSING", "PAYMENT_HANDOFF", "FOLLOW_UP", "NURTURE",
               "HUMAN_HANDOFF", "WON", "LOST", "DO_NOT_CONTACT"]
assert len(LEAD_PHASES) == 18
TEMPERATURES = ["cold", "warm", "hot", "very_hot", "risk"]          # SalesAgentDecisionSchema::TEMPERATURES
CONSENT_STATUSES = ["granted", "denied", "pending", "unknown"]      # MarketingLead::CONSENT_STATUSES
OUTCOMES = [  # D9-10, en el orden de la tabla del contrato, con su grupo
    ("blocked", "delivery"), ("not_attempted", "delivery"), ("failed", "delivery"), ("ambiguous", "delivery"),
    ("sent", "delivery"), ("delivered", "delivery"), ("read", "delivery"), ("no_answer", "delivery"),
    ("answered", "response"), ("replied", "response"), ("callback_requested", "response"), ("human_requested", "response"),
    ("not_interested", "response"), ("wrong_person", "response"), ("opted_out", "response"),
    ("appointment_booked", "business"), ("converted", "business"), ("no_response", "business"),
]
assert len(OUTCOMES) == 18 and len({o for o, _ in OUTCOMES}) == 18
OUTCOME_CODES = sorted(o for o, _ in OUTCOMES)
FOLLOWUP_TRANSITIONS = sorted(["next_step", "retry_step", "wait", "end_success", "end_exit", "handoff_human"])
PURPOSES = sorted(["commercial", "collections", "service"])
MEDIA = ["voice", "whatsapp"]
STEP_CHANNELS = [
    {"key": "assisted:voice", "channel": "assisted", "medium": "voice"},
    {"key": "assisted:whatsapp", "channel": "assisted", "medium": "whatsapp"},
    {"key": "voice_call", "channel": "voice_call", "medium": "voice"},
    {"key": "whatsapp_template", "channel": "whatsapp_template", "medium": "whatsapp"},
]
INT_OPS = sorted(["eq", "neq", "lt", "lte", "gt", "gte", "between"])
OPERATORS = {
    "any": ["is_known", "is_unknown"],
    "bool": ["eq"],
    "count": INT_OPS,
    "date_relative": sorted(["within_next", "within_last", "more_than_ago", "between_days"]),
    "decimal": INT_OPS,
    "duration": INT_OPS,
    "enum": sorted(["eq", "neq", "in", "not_in"]),
    "event": ["absent", "exists"],
    "int": INT_OPS,
}
ALL_OPERATORS = sorted({o for v in OPERATORS.values() for o in v})
LIMITS = {"maxCanonicalBytes": 8192, "maxCount": 100, "maxDepth": 5, "maxEnumValues": 50, "maxNodes": 50, "maxWindowDays": 730}
ENTITIES = {"lead_phase": sorted(LEAD_PHASES), "membership": sorted(["active", "expired", "frozen"]),
            "payment": sorted(["pending", "approved", "declined", "expired"])}
CONTACT_FILTERS = [
    {"name": "channel", "domain": MEDIA},
    {"name": "outcome", "domain": OUTCOME_CODES},
    {"name": "purpose", "domain": PURPOSES},
    {"name": "strategy", "domain": ["other", "self"]},
]
ALL_PURPOSES = PURPOSES

# ── Eventos (D9-3) ──────────────────────────────────────────────────────────


def P(name, unit, lo, hi, required=True):
    return {"max": hi, "min": lo, "name": name, "required": required, "type": "int", "unit": unit}


def EV(key, cls, *, subject, cycle, purposes, cost="cheap", cadence=None, params=(), constraints=(), attrs=(),
       triggerable=True, condition_usable=False, available=True, key_version=1):
    return {"attrs": list(attrs), "available": available, "cadenceMinutes": cadence, "class": cls, "conditionUsable": condition_usable,
            "cost": cost, "cycleKey": cycle, "key": key, "keyVersion": key_version, "paramConstraints": list(constraints),
            "params": sorted(params, key=lambda p: p["name"]), "purposes": sorted(purposes), "subject": subject,
            "triggerable": triggerable}


PLAN_ATTR = {"domain": {"dynamic": "plans"}, "name": "plan_id", "type": "enum"}
PAYMENT_EVENTS = {"payment_link_created", "payment_approved", "payment_pending", "payment_failed", "payment_expired"}
SERVICE_EVENTS = {"appointment_created": ["commercial", "service"], "appointment_completed": ["commercial", "service"],
                  "invoice_requested": ["service"], "invoice_validated": ["service"], "invoice_rejected": ["service"]}
NOT_TRIGGERABLE = {"human_requested", "complaint_created"}

EVENTS = []
for k in VOCAB_EVENTS:
    EVENTS.append(EV(k, "crm_fact", subject="member_or_lead", cycle="ce:{commercial_event_id}",
                     purposes=[] if k in NOT_TRIGGERABLE else SERVICE_EVENTS.get(k, ["commercial"]),
                     attrs=[PLAN_ATTR] if k in PAYMENT_EVENTS else [], triggerable=k not in NOT_TRIGGERABLE, condition_usable=True))
EVENTS += [
    EV("class.upcoming_with_capacity", "periodic", subject="member", cycle="cls:{class_id}:{session_date}", purposes=["commercial"],
       cost="expensive", cadence=60, params=[P("withinDays", "days", 1, 14)], key_version=2),  # L9-29: la fila de sesión nace al iniciar la clase
    EV("lead.followup_due", "periodic", subject="lead", cycle="fu:{marketing_followup_id}", purposes=["commercial"], cadence=15),
    EV("member.inactive", "periodic", subject="member", cycle="inact:{bogota_iso_week}", purposes=["commercial"], cost="expensive",
       cadence=60, params=[P("days", "days", 7, 365)]),
    EV("membership.expired_window", "periodic", subject="member", cycle="exp:{membership_end_date}", purposes=["commercial"],
       cadence=60, params=[P("daysAfterMin", "days", 1, 365), P("daysAfterMax", "days", 1, 365)],
       constraints=[{"left": "daysAfterMin", "op": "lte", "right": "daysAfterMax"}]),
    EV("membership.expiring_window", "periodic", subject="member", cycle="exp:{membership_end_date}", purposes=["commercial"],
       cadence=60, params=[P("daysBefore", "days", 0, 60)]),
    EV("payment_link.abandoned", "periodic", subject="member", cycle="tx:{payment_transaction_id}", purposes=["commercial"],
       cadence=15, params=[P("graceHours", "hours", 1, 168)]),
    EV("receivable.overdue", "periodic", subject="member", cycle="rcv:{receivable_id}:{generation}", purposes=["collections"],
       cadence=60, params=[P("daysMin", "days", 1, 730), P("daysMax", "days", 1, 730)],
       constraints=[{"left": "daysMin", "op": "lte", "right": "daysMax"}]),
    EV("valuation.outdated", "periodic", subject="member", cycle="val:{last_valuation_id}", purposes=["commercial"], cost="expensive",
       cadence=60, params=[P("days", "days", 1, 730)]),
    EV("outcome.callback_requested", "outcome", subject="member_or_lead", cycle="job:{outreach_job_uuid}", purposes=[], triggerable=False),
    EV("outcome.no_answer", "outcome", subject="member_or_lead", cycle="job:{outreach_job_uuid}", purposes=[], triggerable=False),
    EV("outcome.replied", "outcome", subject="member_or_lead", cycle="job:{outreach_job_uuid}", purposes=[], triggerable=False),
    EV("integration.inbound_message", "integration", subject="member_or_lead", cycle="msg:{marketing_message_id}", purposes=[],
       triggerable=False, available=False),
    EV("integration.meta_status", "integration", subject="member_or_lead", cycle="msg:{marketing_message_id}:{status}", purposes=[],
       triggerable=False, available=False),
    EV("integration.voice_call_status", "integration", subject="member_or_lead", cycle="call:{voice_call_id}:{status}", purposes=[],
       triggerable=False, available=False),
]
EVENTS.sort(key=lambda e: e["key"].encode())
EVENT_BY_KEY = {e["key"]: e for e in EVENTS}
assert len(EVENT_BY_KEY) == len(EVENTS) == 27 + 8 + 3 + 3

# ── Hechos (D9-4) ───────────────────────────────────────────────────────────


def FT(key, group, typ, *, unit=None, lo=None, hi=None, scale=None, domain=None, nullable=True, args=(), purposes=ALL_PURPOSES,
       prefilter=False, buckets=None):
    assert typ in ("bool", "int", "decimal", "enum", "duration", "date_relative")
    if typ in ("int", "decimal", "duration", "date_relative"):
        assert lo is not None and hi is not None and lo <= hi
    if typ == "enum":
        assert domain is not None
    return {"args": sorted(args, key=lambda a: a["name"]), "buckets": buckets, "domain": domain, "group": group, "key": key,
            "max": hi, "min": lo, "nullable": nullable, "prefilter": prefilter, "purposes": sorted(purposes), "scale": scale,
            "trace": "bucket" if buckets else "value", "type": typ, "unit": unit}


WITHIN_ARG = {"domain": None, "name": "within", "required": True, "type": "window"}
CHANNEL_ARG = {"domain": MEDIA, "name": "channel", "required": False, "type": "enum"}
PURPOSE_ARG = {"domain": PURPOSES, "name": "purpose", "required": False, "type": "enum"}
STRATEGY_ARG = {"domain": ["other", "self"], "name": "strategy", "required": False, "type": "enum"}
BUCKETS = [{"label": "0-7", "max": 7, "min": 0}, {"label": "8-30", "max": 30, "min": 8}, {"label": "31-90", "max": 90, "min": 31},
           {"label": ">90", "max": None, "min": 91}]

FACTS = [
    # Persona
    FT("subject.has_voice_contact", "subject", "bool", nullable=False),
    FT("subject.has_whatsapp_identity", "subject", "bool", nullable=False),
    FT("subject.is_lead", "subject", "bool", nullable=False),
    FT("subject.is_member", "subject", "bool", nullable=False),
    FT("subject.is_minor", "subject", "bool"),
    # Membresía
    FT("membership.active", "membership", "bool"),
    FT("membership.days_as_member", "membership", "duration", unit="days", lo=0, hi=36500),
    FT("membership.days_since_expiry", "membership", "int", unit="days", lo=-3650, hi=3650),
    FT("membership.days_to_expiry", "membership", "int", unit="days", lo=-3650, hi=3650, prefilter=True),
    FT("membership.end_date", "membership", "date_relative", unit="days", lo=-3650, hi=3650),
    FT("membership.frozen", "membership", "bool"),
    FT("membership.plan_duration_days", "membership", "int", unit="days", lo=1, hi=3650),
    FT("membership.plan_id", "membership", "enum", domain={"dynamic": "plans"}),
    # Asistencia
    FT("attendance.count_last_30d", "attendance", "int", unit="count", lo=0, hi=1000),
    FT("attendance.days_since_last", "attendance", "duration", unit="days", lo=0, hi=36500, prefilter=True),
    FT("attendance.weekly_rate", "attendance", "decimal", unit="visits_per_week", lo=0, hi=10000, scale=2),
    # Dinero
    FT("payment.approved_count", "money", "int", unit="count", lo=0, hi=100000),
    FT("payment.declined_last_7d", "money", "int", unit="count", lo=0, hi=1000),
    FT("payment.has_pending_link", "money", "bool"),
    FT("payment.pending_link_age_hours", "money", "duration", unit="hours", lo=0, hi=8760),
    FT("subscription.active", "money", "bool"),
    FT("subscription.failed_attempts", "money", "int", unit="count", lo=0, hi=1000),
    # Cartera (solo collections)
    FT("receivable.count_overdue", "receivable", "int", unit="count", lo=0, hi=1000, purposes=["collections"]),
    FT("receivable.has_overdue", "receivable", "bool", purposes=["collections"]),
    FT("receivable.overdue_days_max", "receivable", "int", unit="days", lo=0, hi=36500, purposes=["collections"], prefilter=True,
       buckets=BUCKETS),
    # Prospecto
    FT("lead.commercial_phase", "lead", "enum", domain=sorted(LEAD_PHASES)),
    FT("lead.consent_status", "lead", "enum", domain=sorted(CONSENT_STATUSES)),
    FT("lead.days_since_last_inbound", "lead", "duration", unit="days", lo=0, hi=36500),
    FT("lead.followup_due", "lead", "bool"),
    FT("lead.last_inbound_at", "lead", "date_relative", unit="hours", lo=-87600, hi=0),
    FT("lead.temperature", "lead", "enum", domain=sorted(TEMPERATURES)),
    # Conversación
    FT("conversation.human_in_control", "conversation", "bool"),
    FT("conversation.window_open", "conversation", "bool"),
    # Agenda
    FT("class.attended_type_last_60d", "agenda", "enum", domain={"dynamic": "class_types"}),
    FT("class.reserved_next_7d", "agenda", "bool"),
    FT("valuation.days_since_last", "agenda", "duration", unit="days", lo=0, hi=36500),
    FT("valuation.has_upcoming", "agenda", "bool"),
    FT("valuation.next_date", "agenda", "date_relative", unit="days", lo=0, hi=3650),
    # Motor
    FT("nba.open_goal", "engine", "enum", domain=sorted(GOALS)),
    # Historial (los calcula el evaluador desde snapshot.contacts)
    FT("contact.count", "history", "int", unit="count", lo=0, hi=100, args=[CHANNEL_ARG, PURPOSE_ARG, STRATEGY_ARG, WITHIN_ARG]),
    FT("contact.days_since_last", "history", "duration", unit="days", lo=0, hi=36500, args=[CHANNEL_ARG, PURPOSE_ARG]),
    FT("contact.last_outcome", "history", "enum", domain=OUTCOME_CODES, args=[CHANNEL_ARG, STRATEGY_ARG]),
]
FACTS.sort(key=lambda f: f["key"].encode())
FACT_BY_KEY = {f["key"]: f for f in FACTS}
assert len(FACT_BY_KEY) == len(FACTS) == 42
HISTORY_FACTS = {"contact.count", "contact.days_since_last", "contact.last_outcome"}

CATALOG = {
    "contactFilters": CONTACT_FILTERS,
    "contract": "outreach-catalog-export/1",
    "dynamicDomains": ["class_types", "plans"],
    "entities": ENTITIES,
    "eventClasses": sorted(["crm_fact", "periodic", "outcome", "integration"]),
    "events": EVENTS,
    "facts": FACTS,
    "followUpTransitions": FOLLOWUP_TRANSITIONS,
    "grammar": GRAMMAR,
    "limits": LIMITS,
    "operators": OPERATORS,
    "outcomes": sorted(({"code": o, "group": g} for o, g in OUTCOMES), key=lambda x: x["code"]),
    "purposes": PURPOSES,
    "stepChannels": STEP_CHANNELS,
    "types": sorted(["bool", "int", "decimal", "enum", "duration", "date_relative"]),
    "unitDays": {"days": 1, "weeks": 7},
    "unitHours": {"days": 24, "hours": 1, "weeks": 168},
    "units": ["days", "hours", "weeks"],
    "version": CATALOG_VERSION,
}
CATALOG_JSON = canonical(CATALOG)
CATALOG_HASH = sha256_hex(CATALOG_JSON)
# El catálogo canónico se escribe con la forma de PHP: comprobaciones a mano de que no hay rarezas (objetos vacíos → [])
assert '"contract":"outreach-catalog-export/1"' in CATALOG_JSON and "{}" not in CATALOG_JSON
assert CATALOG_JSON.startswith('{"contactFilters":[{"domain":["voice","whatsapp"],"name":"channel"}')

# ── Anotaciones (documentación; fuera de la huella) ─────────────────────────
EVENT_NOTES = {
    "human_requested": "Solo puede DETENER (D9-3, D9-7 N6 human_requested_recently): nunca es disparador.",
    "complaint_created": "No es disparador (DF-K4): una queja no abre un contacto automático; sí se puede leer en condiciones.",
    "class.upcoming_with_capacity": "Una sesión con cupo dentro de withinDays; la clave de ciclo es la sesión (una inscripción por persona y sesión). #7 de §13.1.",
    "lead.followup_due": "Seguimientos autorizados de marketing_followups. Solo si marketing.followups.scheduler_enabled = false; si no, N2 legacy_followups_active (D9-3). #6 de §13.1.",
    "member.inactive": "attendance.days_since_last ≥ days con membresía vigente; la clave de ciclo es la semana ISO del día de Bogotá (como ScanCommercialFacts.php:188).",
    "membership.expired_window": "membership.days_since_expiry ∈ [daysAfterMin, daysAfterMax], cerrado en los dos extremos (#3 de §13.1). daysAfterMin ≥ 1: quien vence hoy todavía no ha vencido (DF-K2).",
    "membership.expiring_window": "membership.days_to_expiry ∈ [0, daysBefore] (DF-K1). daysBefore admite 0 por #2 de §13.1 aunque D9-3 diga 1..60 (DF-K1, L9-11).",
    "payment_link.abandoned": "Enlace de pago pendiente con edad ≥ graceHours (como commercial.thresholds.payment_link_grace_hours).",
    "receivable.overdue": "receivable.overdue_days_max ∈ [daysMin, daysMax]; finalidad collections (D9-15).",
    "valuation.outdated": "valuation.days_since_last ≥ days; sin valoración previa el hecho es desconocido y no inscribe (DF-K3).",
}
SOURCES_BY_GROUP = {
    "subject": "Outreach\\Subjects (CommercialSubjectResolver, identities); subject.is_minor desde la fecha de nacimiento, solo el booleano",
    "membership": "Outreach\\Facts\\Membership (users.membership_end_date, plan; CommercialSubject)",
    "attendance": "Outreach\\Facts\\Attendance (attendances)",
    "money": "Outreach\\Facts\\Payments (payment_transactions, membership_subscriptions)",
    "receivable": "Outreach\\Facts\\Receivables (receivables, receivable_payments)",
    "lead": "Outreach\\Facts\\Leads (marketing_leads, marketing_conversations, marketing_followups)",
    "conversation": "Outreach\\Facts\\Leads (marketing_conversations)",
    "agenda": "Outreach\\Facts\\Agenda (class_sessions, class_reservations, classes, valuation_appointments, physical_evaluations)",
    "engine": "Outreach\\Facts\\EngineGoal (commercial_opportunities, solo lectura)",
    "history": "Outreach\\Facts\\ContactHistory (outreach_jobs live, marketing_messages proactivos de la IA, voice_calls salientes contadas): snapshot.contacts",
}
FACT_NOTES = {
    "membership.days_to_expiry": "Con signo: fecha de fin − hoy, en días de Bogotá (hoy = 0, vencida ayer = −1). Admite gt/lt (el ejemplo de D9-6 usa `gt 30`).",
    "membership.days_since_expiry": "Con signo: hoy − fecha de fin (= −days_to_expiry) (DF-T3).",
    "membership.end_date": "NUEVO (DF-T2): la misma fecha como date_relative (desplazamiento con signo en días de Bogotá), para los operadores relativos.",
    "lead.last_inbound_at": "NUEVO (DF-T2): el último entrante como date_relative en horas (≤ 0: siempre en el pasado).",
    "valuation.next_date": "NUEVO (DF-T2): la próxima valoración agendada como date_relative en días (≥ 0).",
    "attendance.weekly_rate": "decimal con scale 2: el valor y los literales son ENTEROS en centésimas (2,50 visitas/semana = 250) (DF-T4).",
    "receivable.overdue_days_max": "En la traza, `observed` es el tramo (0-7, 8-30, 31-90, >90), nunca el número (D9-4: «solo tramos») (DF-T6).",
    "membership.plan_id": "Dominio dinámico `plans` (planes vendibles): el validador recibe la instantánea del dominio (DF-T5, L9-13).",
    "class.attended_type_last_60d": "Dominio dinámico `class_types` (classes.type normalizado). Un solo valor: el tipo más asistido en 60 días, empate por orden de bytes (DF-T5, L9-13).",
    "nba.open_goal": "Sin objetivo abierto es null = desconocido (D9-4: «o nulo»): para pedir «sin objetivo» se usa is_unknown (DF-T7).",
    "contact.count": "Lo calcula el evaluador desde snapshot.contacts; `within` obligatorio; `strategy` ∈ {self, other} y sin él cuentan todas (DF-C9).",
    "contact.days_since_last": "Días de Bogotá desde el contacto más reciente que casa; sin contactos, desconocido.",
    "contact.last_outcome": "El resultado del contacto más reciente que casa; sin contactos, o con su resultado aún sin registrar, desconocido.",
}


def _fact_annotation(f):
    a = {"key": f["key"], "source": SOURCES_BY_GROUP[f["group"]]}
    if f["key"] in FACT_NOTES:
        a["note"] = FACT_NOTES[f["key"]]
    return a


ANNOTATIONS = {
    "events": [dict({"key": e["key"]}, **({"note": EVENT_NOTES[e["key"]]} if e["key"] in EVENT_NOTES else {}),
                    source={"crm_fact": "commercial_events (cursor monótono outreach_cursors; COMMERCIAL_EVENTS_ENABLED apagada = fuente sin datos)",
                            "periodic": "outreach:sweep con el prefiltro indexado de su hecho",
                            "outcome": "resultados del propio H9 (D9-10)",
                            "integration": "W9/V9: no disponible el 15-11"}[e["class"]]) for e in EVENTS],
    "facts": [_fact_annotation(f) for f in FACTS],
}

POLICY_INPUTS = {
    "note": "Entradas de PolicyLadder/ContactBudget/ChannelSelector que NO son hechos de condiciones: un árbol que las nombre da unknown_fact. Su forma exacta la fija policy-ladder-cases.json (baseInput).",
    "personFacts": [
        {"key": "consent.<purpose>.<medium>", "type": "enum", "domain": ["denied", "granted", "none"],
         "note": "Matriz finalidad × medio de D9-15; null o ausente = none (deniega por defecto)."},
        {"key": "optout.do_not_contact", "type": "bool"}, {"key": "optout.sibling_do_not_contact", "type": "bool"},
        {"key": "optout.commercial", "type": "bool"}, {"key": "voice.excluded", "type": "bool"}, {"key": "voice.rne_ok", "type": "bool"},
        {"key": "subject.known", "type": "bool"}, {"key": "subject.is_minor", "type": "bool"},
        {"key": "conversation.human_in_control", "type": "bool"},
        {"key": "receivable.known", "type": "bool", "note": "false = la fuente de cartera no se pudo leer: bloquea lo comercial (DF-L12)."},
        {"key": "receivable.overdueDaysMax", "type": "int", "note": "null = sin cartera vencida; para N6 collections_overdue_blocks_commercial (L9-20)."},
    ],
    "serverConfig": [
        {"key": "outreach.collections.commercial_block_days", "type": "int", "default": 15,
         "note": "P9-7 y L9-20: sin contacto comercial con cartera vencida de más de N días; la estrategia solo puede bajarlo (limits.commercialBlockOverdueDays)."},
    ],
    "context": ["flags (maestra, modo, canales, constante, autonomía y seguimientos del motor)", "frenos (global, canal, estrategia, ULTRON, agente, voz)",
                "estrategia publicada y su cadena", "referencia del Studio", "plantillas", "presupuestos y topes"],
}

CS = Fixture("catalog-snapshot.json", header(
    "catalog-snapshot.json",
    ["D9-3 (catálogo de eventos: cuatro clases, parámetros tipados, clave de ciclo, finalidad, coste; extensible solo por commit)",
     "D9-4 (catálogo de hechos tipados; null = desconocido)", "D9-5 (operadores por tipo, unidades, límites)",
     "D9-6 (canales de los pasos)", "D9-10 (los 18 resultados y las 6 transiciones)", "§7 #1 (GET strategies/catalog: versión y huella)"],
    [SOURCE_CONTRACT, SOURCE_HASHER,
     {"what": "Los conjuntos cerrados del motor que el H9 reutiliza en solo lectura",
      "url": "backend/app/Services/Commercial/CommercialVocabulary.php (GOALS, EVENTS); backend/app/Services/Marketing/CommercialPhaseMachine.php (PHASES); backend/app/Services/Marketing/SalesAgentDecisionSchema.php (TEMPERATURES); backend/app/Models/MarketingLead.php (CONSENT_STATUSES)",
      "consulted": "09-11-2026, en este árbol",
      "how": "Copiados literalmente (18 objetivos, 27 eventos, 18 fases, 5 temperaturas, 4 estados de consentimiento) y ordenados por bytes en la exportación."}],
    catalogVersion=CATALOG_VERSION,
    catalogHash=CATALOG_HASH,
    catalogCanonicalBytes=len(CATALOG_JSON.encode()),
    hashRule="catalogHash = sha256(StudioContentHasher::canonicalJson(catalog)). OutreachCatalog::export() tiene que devolver exactamente `catalog` (mismas claves, mismos valores, mismas listas en el mismo orden). Cambiar cualquier dato de `catalog` obliga a subir `version` (D9-3).",
    exportRules=[
        "Toda lista de claves va ordenada por bytes (eventos, hechos, dominios cerrados, operadores, finalidades, resultados por code), salvo `buckets` (en su orden) y `units` (days, hours, weeks).",
        "Todo evento y todo hecho llevan SIEMPRE todas sus claves (null o [] cuando no aplican): la forma es uniforme.",
        "Un dominio dinámico se escribe {\"dynamic\": \"plans\"|\"class_types\"}; sus valores no están en el catálogo (DF-T5).",
        "`annotations` y `policyInputs` NO entran en la huella.",
    ],
    decisions={
        "DF-K1": "membership.expiring_window inscribe si membership.days_to_expiry ∈ [0, daysBefore] (ventana, no el día exacto); daysBefore ∈ [0, 60]: el 0 lo exige #2 de §13.1 (ratificada por el lead: L9-11, D9-3 queda enmendado).",
        "DF-K2": "membership.expired_window exige daysAfterMin ≥ 1 (quien vence hoy no ha vencido) y daysAfterMin ≤ daysAfterMax; los dos extremos cerrados (#3).",
        "DF-K3": "valuation.outdated sin valoración previa: valuation.days_since_last desconocido → no inscribe (lado seguro).",
        "DF-K4": "Disparadores: los 27 eventos del vocabulario son legibles en condiciones; human_requested (contrato) y complaint_created (este fixture) no son disparadores. Finalidad: service solo appointment_* e invoice_*; collections solo receivable.overdue; el resto, commercial.",
        "DF-K5": "Claves de ciclo: ce:{commercial_event_id} · exp:{membership_end_date Y-m-d} · inact:{semana ISO del día de Bogotá, «o-\\WW»} · rcv:{receivable_id}:{generación} · tx:{payment_transaction_id} · fu:{marketing_followup_id} · cls:{class_id}:{session_date Y-m-d} (L9-29) · val:{id de la última valoración} · job:{outreach_job_uuid}. Sin el dato (p. ej. membership_end_date nulo) no hay clave y no se inscribe.",
        "DF-K6": "Compatibilidad: una versión es compatible si cada hecho que usa existe en el catálogo vigente y cada evento existe con la misma keyVersion; si no, catalog_incompatible (nivel N3 de la escalera, ratificada por el lead: L9-21) con la lista de lo que falta («fact:<clave>», «event:<clave>» o «event:<clave>@<keyVersion>»), ordenada por bytes. La cadena de versión del catálogo no decide sola (los cambios son aditivos).",
        "DF-T1": "(Ratificada por el lead: L9-14.) Tipos de los hechos de D9-4: los recuentos y los días con signo son int; los «días/horas desde lo último» son duration (≥ 0) con los mismos operadores que int y el literal entero en la unidad del hecho; date_relative es un desplazamiento con signo (futuro > 0, pasado < 0) en la unidad del hecho.",
        "DF-T2": "(Ratificada por el lead: L9-14.) Tres hechos nuevos de tipo date_relative, para que la gramática tenga fechas relativas (D9-4 no nombra ninguna): membership.end_date (días), valuation.next_date (días, ≥ 0) y lead.last_inbound_at (horas, ≤ 0).",
        "DF-T3": "membership.days_since_expiry = hoy − fecha de fin, con signo (el espejo de days_to_expiry): nunca desconocido si hay fecha.",
        "DF-T4": "decimal = entero en centésimas (scale 2): ni el hecho ni los literales llevan coma flotante, porque StudioContentHasher rechaza los decimales.",
        "DF-T5": "(Ratificada por el lead: L9-13.) Dominios dinámicos (plans, class_types): el catálogo declara el nombre y el validador recibe la instantánea de valores (dynamicDomains); un valor fuera de ella es value_not_in_domain.",
        "DF-T6": "receivable.overdue_days_max se compara con su número, pero en la traza `observed` es su tramo (0-7, 8-30, 31-90, >90).",
        "DF-T7": "Un hecho ausente de la foto o con null es desconocido; nba.open_goal sin objetivo es null (no hay valor «none»).",
    },
))
CS.extra["catalog"] = CATALOG
CS.extra["annotations"] = ANNOTATIONS
CS.extra["policyInputs"] = POLICY_INPUTS

# ── compatibility ───────────────────────────────────────────────────────────


def compatibility_ref(used):
    missing = []
    for k in used.get("facts", []):
        if k not in FACT_BY_KEY:
            missing.append("fact:" + k)
    for e in used.get("events", []):
        cur = EVENT_BY_KEY.get(e["key"])
        if cur is None:
            missing.append("event:" + e["key"])
        elif cur["keyVersion"] != e["keyVersion"]:
            missing.append(f"event:{e['key']}@{e['keyVersion']}")
    missing = sorted(set(missing), key=lambda s: s.encode())
    return {"result": "catalog_incompatible" if missing else "compatible", "missing": missing, "level": "N3" if missing else None}


def COMPAT(desc, used, result, missing, *, basis="decision-fixture", decision="DF-K6", ref="D9-3 (los cambios son aditivos; retirar un hecho → catalog_incompatible)"):
    exp = {"result": result, "missing": missing, "level": "N3" if missing else None}
    assert compatibility_ref(used) == exp, (desc, compatibility_ref(used))
    CS.add("compatibility", "version", desc, ref, basis, {"used": used}, exp, decision=decision)


COMPAT("todos los hechos y el evento existen con su keyVersion → compatible",
       {"catalogVersion": CATALOG_VERSION, "facts": ["membership.days_to_expiry", "contact.count"], "events": [{"key": "membership.expiring_window", "keyVersion": 1}]},
       "compatible", [])
COMPAT("una versión de un catálogo anterior que solo usa claves vigentes → compatible (los cambios son aditivos)",
       {"catalogVersion": "outreach-catalog/2026-11-01.1", "facts": ["attendance.days_since_last"], "events": [{"key": "member.inactive", "keyVersion": 1}]},
       "compatible", [])
COMPAT("un hecho retirado (membership.legacy_segment) → catalog_incompatible, denegada y no reinterpretada (§13.3)",
       {"catalogVersion": "outreach-catalog/2026-11-01.1", "facts": ["membership.legacy_segment", "membership.active"], "events": [{"key": "membership.expiring_window", "keyVersion": 1}]},
       "catalog_incompatible", ["fact:membership.legacy_segment"])
COMPAT("un evento con otra keyVersion → catalog_incompatible",
       {"catalogVersion": CATALOG_VERSION, "facts": [], "events": [{"key": "membership.expiring_window", "keyVersion": 2}]},
       "catalog_incompatible", ["event:membership.expiring_window@2"])
COMPAT("un evento que no existe y un hecho retirado: los dos, ordenados por bytes",
       {"catalogVersion": CATALOG_VERSION, "facts": ["subject.vip"], "events": [{"key": "gym.special_event", "keyVersion": 1}]},
       "catalog_incompatible", ["event:gym.special_event", "fact:subject.vip"])
COMPAT("una entrada de policyInputs no es un hecho del catálogo (consent.commercial.whatsapp) → catalog_incompatible",
       {"catalogVersion": CATALOG_VERSION, "facts": ["consent.commercial.whatsapp"], "events": []},
       "catalog_incompatible", ["fact:consent.commercial.whatsapp"])

# ── cycleKeys ───────────────────────────────────────────────────────────────


def iso_week_bogota(now_iso):
    d = bog_date(now_iso)
    y, w, _ = d.isocalendar()
    return f"{y}-W{w:02d}"


def cycle_ref(event, occ, now):
    e = EVENT_BY_KEY[event]
    tpl = e["cycleKey"]
    if "{bogota_iso_week}" in tpl:
        return tpl.replace("{bogota_iso_week}", iso_week_bogota(now))
    out = tpl
    for name in re.findall(r"\{([a-z_]+)\}", tpl):
        v = occ.get(name)
        if v is None:
            return None
        out = out.replace("{" + name + "}", str(v))
    return out


def CK(desc, event, occ, now, expected, *, basis="decision-fixture", decision="DF-K5", ref="D9-3 (clave de ciclo), D9-8 etapa 2 (UNIQUE strategy, subject, cycle, mode)"):
    got = cycle_ref(event, occ, now)
    assert got == expected, (desc, got)
    if expected is not None:
        assert len(expected) <= 96, "cycle_key ≤ 96 (D9 §6.2)"
    CS.add("cycleKeys", "derive", desc, ref, basis, {"event": event, "occurrence": occ, "now": now},
           {"cycleKey": expected, "enrolls": expected is not None}, decision=decision)


NOW = bog(2026, 11, 10, 10, 0)
CK("ventana de vencimiento: exp:<fecha de fin>", "membership.expiring_window", {"membership_end_date": "2026-11-17"}, NOW, "exp:2026-11-17",
   basis="contrato", ref="D9-3 (exp:<membership_end_date>)")
CK("vencida: la misma forma exp:<fecha de fin> (otra estrategia, otra fila)", "membership.expired_window", {"membership_end_date": "2026-11-03"}, NOW, "exp:2026-11-03")
CK("membership_end_date nulo: sin clave de ciclo y no se inscribe (§13.3)", "membership.expiring_window", {"membership_end_date": None}, NOW, None)
CK("inactividad: la semana ISO del día de Bogotá (martes 10-11-2026 → 2026-W46)", "member.inactive", {}, NOW, "inact:2026-W46",
   basis="contrato", ref="D9-3 (la semana ISO para la inactividad, como ScanCommercialFacts.php:188)", decision="DF-K5")
CK("inactividad el domingo 03-01-2027 23:30 de Bogotá (lunes 04:30 UTC): todavía 2026-W53, no 2027-W01", "member.inactive", {},
   "2027-01-04T04:30:00Z", "inact:2026-W53")
CK("inactividad el lunes 04-01-2027 00:00 de Bogotá: 2027-W01", "member.inactive", {}, bog(2027, 1, 4, 0, 0), "inact:2027-W01")
CK("inactividad el jueves 31-12-2026: 2026 tiene 53 semanas ISO (2026-W53)", "member.inactive", {}, bog(2026, 12, 31, 12, 0), "inact:2026-W53")
CK("cartera: rcv:<id>:<generación>", "receivable.overdue", {"receivable_id": 812, "generation": 3}, NOW, "rcv:812:3",
   basis="contrato", ref="D9-3 (rcv:<receivable_id>:<generación>, como DetectOverdueReceivables)")
CK("cartera sin generación: sin clave", "receivable.overdue", {"receivable_id": 812, "generation": None}, NOW, None)
CK("enlace abandonado: tx:<payment_transaction_id>", "payment_link.abandoned", {"payment_transaction_id": 5521}, NOW, "tx:5521",
   basis="contrato", ref="D9-3 (tx:<payment_transaction_id>)")
CK("hecho del CRM: ce:<commercial_event_id> (la misma ocurrencia entregada dos veces da la misma clave, #14)", "payment_failed",
   {"commercial_event_id": 90017}, NOW, "ce:90017", ref="D9-3, #14 de §13.1 (occurrence_key único)")
CK("seguimiento de mercadeo: fu:<marketing_followup_id>", "lead.followup_due", {"marketing_followup_id": 77}, NOW, "fu:77")
CK("clase con cupo: cls:<class_id>:<session_date> (L9-29, keyVersion 2)", "class.upcoming_with_capacity",
   {"class_id": 3301, "session_date": "2026-11-14"}, NOW, "cls:3301:2026-11-14", basis="supuesto-lead", ref="L9-29 (la fila de sesión solo nace al iniciar la clase)")
CK("valoración desactualizada: val:<id de la última valoración>", "valuation.outdated", {"last_valuation_id": 4410}, NOW, "val:4410")
CK("valoración desactualizada sin valoración previa: sin clave (DF-K3)", "valuation.outdated", {"last_valuation_id": None}, NOW, None, decision="DF-K3")

# contact-budget-cases.json · ContactBudget (D9-7 «Presupuesto de contacto omnicanal», P9-8; §13.2 T9: paridad con ContactPolicy)
#   effectiveLimits · el suelo más estricto y lo que la configuración del H9 puede (solo) endurecer
#   check           · historial + now + finalidad + medio → motivos de N1 (calendario y frecuencia), cuándo se suelta cada uno y retryAt
#   parity          · historiales solo de WhatsApp (escritos a mano y generados con semilla): lo que ContactPolicy niega por
#                     frecuencia u horario, ContactBudget también lo niega (la implicación de D9-7; T9)
import random

COUNTED_SOURCES = ["ai_proactive", "outreach", "voice_call"]
NOT_COUNTED_SOURCES = ["ai_reply", "inbound", "simulated"]
BUDGET_REASONS = ["calendar_outside_legal_hours", "calendar_sunday", "calendar_holiday", "daily_contact_limit", "weekly_contact_limit",
                  "min_hours_between_contacts", "cross_channel_week_rule", "collections_channel_week_rule"]
FLOOR = {"perDay": 1, "perWeek": 2, "minHoursBetween": 48}


def effective_limits(commercial_per_week=2, commercial_min_hours=48, voice_per_week=2, h9_per_week=None, h9_min_hours=None):
    floor_week = min(commercial_per_week, max(1, min(2, voice_per_week)) if isinstance(voice_per_week, int) else 2)
    per_week = floor_week
    if isinstance(h9_per_week, int) and not isinstance(h9_per_week, bool):
        per_week = min(floor_week, max(1, h9_per_week))
    min_hours = commercial_min_hours
    if isinstance(h9_min_hours, int) and not isinstance(h9_min_hours, bool):
        min_hours = max(commercial_min_hours, h9_min_hours)
    return {"perDay": 1, "perWeek": per_week, "minHoursBetween": min_hours}


def _next_bogota_midnight(now):
    d = bog_date(now) + dt.timedelta(days=1)
    return bog(d.year, d.month, d.day, 0, 0)


def budget_ref(history, now, purpose, medium, limits=None):
    limits = limits or FLOOR
    tnow = parse_iso(now)
    counted = [h for h in history if h["source"] in COUNTED_SOURCES and parse_iso(h["at"]) <= tnow]
    week = sorted((h for h in counted if parse_iso(h["at"]) >= tnow - dt.timedelta(days=7)), key=lambda h: h["at"])
    today = [h for h in counted if bog_date(h["at"]) == bog_date(now)]
    reasons, release = list(calendar_reasons(now)), {}
    if len(today) >= limits["perDay"]:
        reasons.append("daily_contact_limit")
        release["daily_contact_limit"] = _next_bogota_midnight(now)
    if len(week) >= limits["perWeek"]:
        k = len(week) - limits["perWeek"]           # el (k+1)-ésimo más antiguo tiene que salir de la ventana
        reasons.append("weekly_contact_limit")
        release["weekly_contact_limit"] = plus_seconds(week[k]["at"], 7 * 86400 + 1)
    if counted:
        last = max(counted, key=lambda h: h["at"])
        if (tnow - parse_iso(last["at"])).total_seconds() < limits["minHoursBetween"] * 3600:
            reasons.append("min_hours_between_contacts")
            release["min_hours_between_contacts"] = plus_seconds(last["at"], limits["minHoursBetween"] * 3600)
    other = [h for h in week if h["medium"] != medium]
    if other:
        code = "collections_channel_week_rule" if purpose == "collections" else "cross_channel_week_rule"
        reasons.append(code)
        release[code] = plus_seconds(max(h["at"] for h in other), 7 * 86400 + 1)
    reasons = [r for r in BUDGET_REASONS if r in reasons]
    retry = None
    if reasons:
        t0 = max([now] + list(release.values()), key=lambda s: parse_iso(s))
        retry = next_slot(t0)
    return {"allowed": not reasons, "reasons": reasons, "releaseAt": release, "retryAt": retry,
            "counts": {"today": len(today), "rolling7": len(week)}}


def contact_policy_ref(history, now, min_hours=48, max_week=2):
    """ContactPolicy::check, solo frecuencia y horario (ContactPolicy.php:92-172), sobre una conversación de WhatsApp."""
    tnow = parse_iso(now)
    proactive = sorted((h for h in history if h["source"] == "ai_proactive" and h["medium"] == "whatsapp"
                        and parse_iso(h["at"]) >= tnow - dt.timedelta(days=7)), key=lambda h: h["at"], reverse=True)
    if proactive:
        last = parse_iso(proactive[0]["at"])
        if (tnow - last).total_seconds() / 3600 < min_hours:
            return {"denied": True, "reason": "too_soon_since_last_contact", "retryAt": plus_seconds(proactive[0]["at"], min_hours * 3600)}
        if len(proactive) >= max_week:
            return {"denied": True, "reason": "weekly_contact_limit_reached", "retryAt": plus_seconds(proactive[-1]["at"], 7 * 86400)}
    local = to_bogota(now)
    if local.hour >= 21 or local.hour < 8:
        d = local.date() if local.hour < 8 else local.date() + dt.timedelta(days=1)
        return {"denied": True, "reason": "quiet_hours", "retryAt": bog(d.year, d.month, d.day, 8, 0)}
    return {"denied": False, "reason": None, "retryAt": None}


CB = Fixture("contact-budget-cases.json", header(
    "contact-budget-cases.json",
    ["D9-7 (ContactBudget: qué cuenta, qué nunca cuenta y el suelo: 1 al día sumando canales y finalidades, min(2, 2) a la semana, 48 h entre contactos, ventana horaria; la configuración solo endurece; paridad con ContactPolicy)",
     "D9-7 N1 (daily_contact_limit, cross_channel_week_rule, collections_channel_week_rule)", "D9-15 y P9-8 (tras un contacto directo, un solo canal en la semana; cobranza y comercial)",
     "§13.1 #5 y #11, §13.2 T9, §13.3 (presupuesto exactamente igual al tope)"],
    [SOURCE_CONTRACT,
     {"what": "ContactPolicy: frecuencia (48 h y 2 por semana, contados en los 7 días anteriores con created_at ≥ now − 7 d, solo salientes de la IA que no son respuesta) y horas de silencio 21:00–08:00 de Bogotá",
      "url": "backend/app/Services/Commercial/ContactPolicy.php:92-172 y backend/config/commercial.php:73-83",
      "consulted": "09-11-2026, en este árbol (Carbon 3.11.4: diffInHours devuelve decimales; a las 48 h justas ya no niega)",
      "how": "contact_policy_ref() del generador la reproduce para la sección parity; el propio ContactPolicy necesita base y configuración, así que la paridad se prueba contra este modelo y contra los literales."},
     {"what": "VoiceFrequency (lo que cuenta de voice_calls y el tope semanal de voz)",
      "url": "backend/app/Services/Telephony/VoiceFrequency.php:26-92",
      "consulted": "09-11-2026, en este árbol",
      "how": "PER_DAY = 1; per_week acotado a [1, 2]; su ventana móvil es (now − 7 d, now] — la de ContactPolicy es [now − 7 d, now]: el H9 usa la de ContactPolicy, la más estricta (DF-B3)."}],
    floor=FLOOR,
    countedSources=COUNTED_SOURCES,
    notCountedSources=NOT_COUNTED_SOURCES,
    reasonOrder=BUDGET_REASONS,
    interface={
        "check": "ContactBudget::check(history, now, purpose, medium, limits) → {allowed, reasons (en reasonOrder), releaseAt {motivo: instante}, retryAt, counts {today, rolling7}}. Todos sus motivos son temporales: si niega, aplaza (defer). Solo la ley y la frecuencia: el horario propio es N5 (L9-15).",
        "history": "[{at (UTC, Z), medium: whatsapp|voice, purpose, source}]: source ∈ countedSources cuenta; notCountedSources nunca (respuestas a la persona, entrantes, simulados). Lo posterior a now no cuenta.",
    },
    decisions={
        "DF-B1": "Cuentan los salientes de outreach live (también los asistidos, por su medio), los proactivos de la IA (sender_type = ai y metadata.kind ≠ reply) y las salientes de voice_calls contadas; nunca ai_reply, inbound ni simulated (D9-7).",
        "DF-B2": "«Al día» es el día de Bogotá de now; daily_contact_limit se suelta a las 00:00 de Bogotá del día siguiente (y el calendario lo lleva a las 08:00).",
        "DF-B3": "«A la semana» y la regla de un canal son los 7 días móviles CERRADOS [now − 7 d, now], como ContactPolicy (created_at ≥ now − 7 d): un contacto de hace exactamente 7 días todavía cuenta; deja de contar 1 s después (instantes al segundo). Es más estricta que la semana de calendario y que la ventana (now − 7 d, now] de VoiceFrequency.",
        "DF-B4": "Dos códigos para dos límites de D9-7: weekly_contact_limit (≤ 2 por semana) y min_hours_between_contacts (48 h; a las 48 h justas ya no niega, como ContactPolicy). Van en N1 después de daily_contact_limit (ratificada por el lead: L9-9).",
        "DF-B5": "Regla de un canal (P9-8, Ley 2300 art. 3): si en [now − 7 d, now] hubo un contacto contado por OTRO medio (whatsapp frente a voice; el asistido cuenta por su medio), niega; con finalidad collections el código es collections_channel_week_rule y con commercial o service, cross_channel_week_rule (L9-10). Cuenta cualquier contacto contado como «directo», de cualquier finalidad, también un intento sin respuesta (ratificada por el lead: L9-22).",
        "DF-B6": "retryAt = el primer hueco LEGAL (calendar-cases.json §nextSlot sin horario propio ni de la estrategia) a partir del más tardío entre now y los releaseAt; es el primer instante en que ContactBudget deja de negar si no hay contactos nuevos. La escalera calcula su propio retryAt con el horario propio y el de la estrategia (DF-L3).",
        "DF-B7": "Límites efectivos: perDay = 1 siempre; perWeek = min(commercial.max_proactive_per_week, VoiceFrequency::perWeekFromConfig()) y el del H9, si es entero, solo lo baja (acotado a ≥ 1); minHoursBetween = commercial.min_hours_between y el del H9, si es entero, solo lo sube. Un valor no entero se ignora (como perWeekFromConfig).",
        "DF-B8": "Paridad (T9): con historiales solo de WhatsApp, si ContactPolicy niega por frecuencia (too_soon_since_last_contact, weekly_contact_limit_reached) o por horario (quiet_hours), ContactBudget niega también. Al revés no hace falta: el H9 es más estricto (domingos, festivos, 19:00–21:00, otros medios).",
    },
))


def H(at, medium="whatsapp", purpose="commercial", source="ai_proactive"):
    return {"at": at, "medium": medium, "purpose": purpose, "source": source}


# ── effectiveLimits ─────────────────────────────────────────────────────────


def EL(desc, inp, expected, *, ref="D9-7 («la configuración del H9 solo puede endurecer»)", basis="decision-fixture", decision="DF-B7"):
    got = effective_limits(**inp)
    assert got == expected, (desc, got)
    CB.add("effectiveLimits", "limits", desc, ref, basis, {"config": inp}, expected, decision=decision)


EL("los valores de hoy: 1 al día, 2 a la semana, 48 h", {}, {"perDay": 1, "perWeek": 2, "minHoursBetween": 48}, basis="contrato", decision=None)
EL("el H9 pide 3 por semana: no afloja (sigue 2)", {"h9_per_week": 3}, {"perDay": 1, "perWeek": 2, "minHoursBetween": 48})
EL("el H9 pide 1 por semana: endurece", {"h9_per_week": 1}, {"perDay": 1, "perWeek": 1, "minHoursBetween": 48})
EL("el H9 pide 0 por semana: se acota a 1", {"h9_per_week": 0}, {"perDay": 1, "perWeek": 1, "minHoursBetween": 48})
EL("el H9 pide 24 h entre contactos: no afloja (sigue 48)", {"h9_min_hours": 24}, {"perDay": 1, "perWeek": 2, "minHoursBetween": 48})
EL("el H9 pide 72 h: endurece", {"h9_min_hours": 72}, {"perDay": 1, "perWeek": 2, "minHoursBetween": 72})
EL("el motor comercial afloja a 5 por semana: manda la voz (2)", {"commercial_per_week": 5}, {"perDay": 1, "perWeek": 2, "minHoursBetween": 48})
EL("el motor comercial endurece a 1 por semana: el suelo baja a 1", {"commercial_per_week": 1}, {"perDay": 1, "perWeek": 1, "minHoursBetween": 48})
EL("un valor del H9 que no es entero se ignora", {"h9_per_week": "3", "h9_min_hours": True}, {"perDay": 1, "perWeek": 2, "minHoursBetween": 48})

# ── check ───────────────────────────────────────────────────────────────────
NOW = bog(2026, 11, 10, 10, 0)     # martes


def CHK(desc, history, now, purpose, medium, reasons, retry, *, limits=None, ref="D9-7 (ContactBudget)", basis="decision-fixture",
        decision="DF-B3", release=None):
    got = budget_ref(history, now, purpose, medium, limits)
    assert got["reasons"] == reasons, (desc, got["reasons"])
    assert got["retryAt"] == retry, (desc, got["retryAt"], retry)
    if release is not None:
        assert got["releaseAt"] == release, (desc, got["releaseAt"])
    CB.add("check", "budget", desc, ref, basis,
           {"history": history, "now": now, "purpose": purpose, "medium": medium, "limits": limits or FLOOR}, got, decision=decision)


CHK("sin historial, martes 10:00 → permitido", [], NOW, "commercial", "whatsapp", [], None, basis="contrato", decision=None)
CHK("un contacto hoy a las 08:30 por WhatsApp → daily (1 al día) y 48 h; vuelve el jueves 08:30", [H(bog(2026, 11, 10, 8, 30))], NOW, "commercial",
    "whatsapp", ["daily_contact_limit", "min_hours_between_contacts"], bog(2026, 11, 12, 8, 30), basis="contrato", decision="DF-B2")
CHK("un contacto hoy por VOZ y se pide WhatsApp → daily (sumando canales), 48 h y la regla de un canal", [H(bog(2026, 11, 10, 8, 30), "voice", source="voice_call")],
    NOW, "commercial", "whatsapp", ["daily_contact_limit", "min_hours_between_contacts", "cross_channel_week_rule"], bog(2026, 11, 17, 8, 30, 1),
    ref="D9-7 (1 al día sumando canales), P9-8, #11", decision="DF-B5")
CHK("un contacto de COBRANZA hoy y se pide comercial → daily (sumando finalidades)", [H(bog(2026, 11, 10, 8, 30), purpose="collections", source="outreach")],
    NOW, "commercial", "whatsapp", ["daily_contact_limit", "min_hours_between_contacts"], bog(2026, 11, 12, 8, 30), ref="D9-7 (sumando finalidades), P9-7",
    decision="DF-B2")
CHK("una respuesta de la IA hoy (ai_reply) no cuenta", [H(bog(2026, 11, 10, 8, 30), source="ai_reply")], NOW, "commercial", "whatsapp", [], None,
    ref="D9-7 («nunca cuentan las respuestas»)", decision="DF-B1", basis="contrato")
CHK("un trabajo simulado hoy no cuenta", [H(bog(2026, 11, 10, 8, 30), source="simulated")], NOW, "commercial", "whatsapp", [], None,
    ref="D9-7 («ni los trabajos simulated»), D9-8 (sombra)", decision="DF-B1", basis="contrato")
CHK("un entrante de la persona no cuenta", [H(bog(2026, 11, 10, 8, 30), source="inbound")], NOW, "commercial", "whatsapp", [], None, decision="DF-B1")
CHK("un contacto posterior a now no cuenta (reloj inyectado)", [H(plus_seconds(NOW, 3600))], NOW, "commercial", "whatsapp", [], None,
    ref="§13.3 (reloj inyectado)", decision="DF-B3")
CHK("ayer a las 18:00: 48 h → vuelve el jueves 18:00", [H(bog(2026, 11, 9, 18, 0))], NOW, "commercial", "whatsapp", ["min_hours_between_contacts"],
    bog(2026, 11, 11, 18, 0), basis="codigo-existente", ref="D9-7 (48 h), ContactPolicy.php:122-128", decision="DF-B4")
CHK("hace 47 h 59 min → niega", [H(plus_seconds(NOW, -(48 * 3600 - 60)))], NOW, "commercial", "whatsapp", ["min_hours_between_contacts"], plus_seconds(NOW, 60),
    basis="codigo-existente", ref="ContactPolicy.php:122-128", decision="DF-B4")
CHK("hace exactamente 48 h → ya no niega (como ContactPolicy)", [H(plus_seconds(NOW, -48 * 3600))], NOW, "commercial", "whatsapp", [], None,
    basis="codigo-existente", ref="ContactPolicy.php:123 (diffInHours < 48)", decision="DF-B4")
W2 = [H(bog(2026, 11, 4, 10, 0)), H(bog(2026, 11, 6, 11, 0))]
CHK("dos contactos en la semana (el tope exacto, §13.3) → weekly; se suelta 7 d + 1 s después del más antiguo", W2, NOW, "commercial", "whatsapp",
    ["weekly_contact_limit"], bog(2026, 11, 11, 10, 0, 1), ref="D9-7 (2 a la semana), §13.3 (exactamente igual al tope)", decision="DF-B3",
    release={"weekly_contact_limit": bog(2026, 11, 11, 10, 0, 1)})
CHK("dos contactos y el más antiguo hace EXACTAMENTE 7 días → todavía cuenta", [H(plus_seconds(NOW, -7 * 86400)), H(bog(2026, 11, 6, 11, 0))], NOW,
    "commercial", "whatsapp", ["weekly_contact_limit"], plus_seconds(NOW, 1), basis="codigo-existente", ref="ContactPolicy.php:108 (created_at ≥ now − 7 d)",
    decision="DF-B3")
CHK("dos contactos y el más antiguo hace 7 días y 1 s → ya no cuenta", [H(plus_seconds(NOW, -7 * 86400 - 1)), H(bog(2026, 11, 6, 11, 0))], NOW,
    "commercial", "whatsapp", [], None, basis="codigo-existente", ref="ContactPolicy.php:108", decision="DF-B3")
CHK("un solo contacto en la semana (debajo del tope) → permitido", [H(bog(2026, 11, 6, 11, 0))], NOW, "commercial", "whatsapp", [], None)
CHK("tres contactos en la semana (legado) → se suelta cuando salen DOS", [H(bog(2026, 11, 3, 12, 0)), H(bog(2026, 11, 5, 12, 0)), H(bog(2026, 11, 7, 12, 0))],
    NOW, "commercial", "whatsapp", ["weekly_contact_limit"], bog(2026, 11, 12, 12, 0, 1), decision="DF-B3",
    release={"weekly_contact_limit": bog(2026, 11, 12, 12, 0, 1)})
CHK("con el H9 endurecido a 1 por semana, un contacto ya basta", [H(bog(2026, 11, 6, 11, 0))], NOW, "commercial", "whatsapp", ["weekly_contact_limit"],
    bog(2026, 11, 13, 11, 0, 1), limits={"perDay": 1, "perWeek": 1, "minHoursBetween": 48}, decision="DF-B7")
CHK("regla de un canal: WhatsApp el viernes y ahora se pide voz → cross_channel_week_rule hasta el viernes siguiente + 1 s (#11)",
    [H(bog(2026, 11, 6, 11, 0))], NOW, "commercial", "voice", ["cross_channel_week_rule"], bog(2026, 11, 13, 11, 0, 1), ref="P9-8, #11 de §13.1",
    decision="DF-B5")
CHK("regla de un canal en cobranza: el mismo caso con collections → collections_channel_week_rule", [H(bog(2026, 11, 6, 11, 0), source="outreach")], NOW,
    "collections", "voice", ["collections_channel_week_rule"], bog(2026, 11, 13, 11, 0, 1), ref="D9-15 (Ley 2300, art. 3)", basis="ley", decision="DF-B5")
CHK("regla de un canal con finalidad service → cross_channel_week_rule", [H(bog(2026, 11, 6, 11, 0))], NOW, "service", "voice", ["cross_channel_week_rule"],
    bog(2026, 11, 13, 11, 0, 1), decision="DF-B5")
CHK("el mismo medio no activa la regla de un canal", [H(bog(2026, 11, 6, 11, 0))], NOW, "commercial", "whatsapp", [], None, decision="DF-B5")
CHK("un asistido por voz cuenta como voz: se pide WhatsApp → regla de un canal", [H(bog(2026, 11, 6, 11, 0), "voice", source="outreach")], NOW, "commercial",
    "whatsapp", ["cross_channel_week_rule"], bog(2026, 11, 13, 11, 0, 1), decision="DF-B5")
CHK("domingo 10:00 sin historial → calendar_sunday, vuelve el lunes 08:00", [], bog(2026, 11, 8, 10, 0), "commercial", "whatsapp", ["calendar_sunday"],
    bog(2026, 11, 9, 8, 0), ref="D9-7 (ventana horaria), #17", decision="DF-B6")
CHK("viernes 19:30 con un contacto hoy → calendario, daily y 48 h; vuelve el martes 17-11 08:00 (48 h hasta el domingo y el lunes 16-11 es festivo)", [H(bog(2026, 11, 13, 9, 0))],
    bog(2026, 11, 13, 19, 30), "commercial", "whatsapp", ["calendar_outside_legal_hours", "daily_contact_limit", "min_hours_between_contacts"],
    bog(2026, 11, 17, 8, 0), ref="D9-7, #17", decision="DF-B6")
CHK("martes 08:30 sin historial → permitido: la ley ya abrió; el horario propio no es del presupuesto (es N5)", [], bog(2026, 11, 10, 8, 30), "commercial",
    "whatsapp", [], None, ref="D9-7, L9-15", basis="supuesto-lead", decision="DF-B6")
CHK("todo a la vez: domingo, contacto de voz hoy y otro el viernes por WhatsApp; se pide WhatsApp",
    [H(bog(2026, 11, 13, 10, 0)), H(bog(2026, 11, 15, 9, 30), "voice", source="voice_call")], bog(2026, 11, 15, 11, 0), "commercial", "whatsapp",
    ["calendar_sunday", "daily_contact_limit", "weekly_contact_limit", "min_hours_between_contacts", "cross_channel_week_rule"],
    bog(2026, 11, 23, 8, 0), decision="DF-B6")

# ── parity ──────────────────────────────────────────────────────────────────


def PAR(desc, history, now, *, ref="D9-7 (prueba de paridad), T9", basis="decision-fixture", decision="DF-B8", expect_policy=None, expect_budget=None):
    pol = contact_policy_ref(history, now)
    bud = budget_ref(history, now, "commercial", "whatsapp")
    if expect_policy is not None:
        assert pol["reason"] == expect_policy, (desc, pol)
    if expect_budget is not None:
        assert bud["reasons"] == expect_budget, (desc, bud["reasons"])
    if pol["denied"]:
        assert not bud["allowed"], ("PARIDAD ROTA", desc, pol, bud)
    CB.add("parity", "whatsapp", desc, ref, basis, {"history": history, "now": now, "purpose": "commercial", "medium": "whatsapp"},
           {"contactPolicy": pol, "contactBudget": {"allowed": bud["allowed"], "reasons": bud["reasons"], "retryAt": bud["retryAt"]},
            "implication": "si contactPolicy.denied, contactBudget.allowed = false"}, decision=decision)


PAR("borde 48 h: a las 47:59 niegan los dos", [H(plus_seconds(NOW, -(48 * 3600 - 60)))], NOW, expect_policy="too_soon_since_last_contact",
    expect_budget=["min_hours_between_contacts"])
PAR("borde 48 h: a las 48:00 no niega ninguno", [H(plus_seconds(NOW, -48 * 3600))], NOW, expect_policy=None, expect_budget=[])
PAR("borde semanal: el más antiguo a 7 d justos → niegan los dos", [H(plus_seconds(NOW, -7 * 86400)), H(bog(2026, 11, 6, 11, 0))], NOW,
    expect_policy="weekly_contact_limit_reached", expect_budget=["weekly_contact_limit"])
PAR("borde semanal: el más antiguo a 7 d + 1 s → no niega ninguno", [H(plus_seconds(NOW, -7 * 86400 - 1)), H(bog(2026, 11, 6, 11, 0))], NOW,
    expect_policy=None, expect_budget=[])
PAR("las respuestas no cuentan para ninguno", [H(bog(2026, 11, 9, 18, 0), source="ai_reply"), H(bog(2026, 11, 8, 18, 0), source="ai_reply")], NOW,
    expect_policy=None, expect_budget=[])
PAR("silencio: 20:59 permite ContactPolicy y niega el H9 (Ley 2300: hasta las 19:00)", [], bog(2026, 11, 10, 20, 59), expect_policy=None,
    expect_budget=["calendar_outside_legal_hours"])
PAR("silencio: 21:00 niegan los dos", [], bog(2026, 11, 10, 21, 0), expect_policy="quiet_hours", expect_budget=["calendar_outside_legal_hours"])
PAR("silencio: 07:59 niegan los dos", [], bog(2026, 11, 10, 7, 59), expect_policy="quiet_hours", expect_budget=["calendar_outside_legal_hours"])
PAR("08:00 no niega ninguno", [], bog(2026, 11, 10, 8, 0), expect_policy=None, expect_budget=[])
PAR("domingo 10:00: ContactPolicy permite y el H9 niega (más estricto)", [], bog(2026, 11, 8, 10, 0), expect_policy=None, expect_budget=["calendar_sunday"])

_rng = random.Random(20261110)       # semilla fija: la lista es reproducible
_base = parse_iso(bog(2026, 11, 2, 0, 0))
for i in range(40):
    now_t = _base + dt.timedelta(minutes=_rng.randrange(0, 14 * 24 * 60))
    now_s = now_t.strftime("%Y-%m-%dT%H:%M:%SZ")
    hist = []
    for _ in range(_rng.randrange(0, 5)):
        back = _rng.randrange(60, 10 * 24 * 3600)
        src = "ai_proactive" if _rng.random() < 0.8 else "ai_reply"
        hist.append(H((now_t - dt.timedelta(seconds=back)).strftime("%Y-%m-%dT%H:%M:%SZ"), source=src))
    hist.sort(key=lambda h: h["at"])
    PAR(f"generado {i + 1:02d} (semilla 20261110)", hist, now_s)

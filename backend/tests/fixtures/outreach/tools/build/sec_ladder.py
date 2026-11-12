# policy-ladder-cases.json · PolicyLadder::decide (D9-7; §13.1 #8, #9, #12, #17; §13.2 T16–T22)
#   n1 … n7     · un bloque por nivel: cada caso parte de BASE (todo permitido) y cambia lo que dice su `patch`
#   precedence  · lo que un nivel inferior NO puede levantar y el primer nivel que niega decide
#   shadow      · en sombra, N4 (y el alcance canario —N3 «solo canario» y N5— y la autonomía del motor) avisan en lugar de negar (D9-8, D9-11.6)
# Salida: {verdict: allow|deny|defer, allowed, level, reasons (los del nivel que decide, en el orden del contrato),
#          retryAt (solo defer), waitsForRelease (N2), effect (N6), levels [N1..N7 con pass/deny/defer/not_evaluated], warnings}
import copy

N_ORDER = {
    "N1": ["calendar_outside_legal_hours", "calendar_sunday", "calendar_holiday", "no_consent_for_purpose_channel", "consent_denied", "do_not_contact",
           "do_not_contact_sibling", "commercial_opt_out", "voice_excluded", "rne_check_missing", "subject_minor", "daily_contact_limit",
           "weekly_contact_limit", "min_hours_between_contacts", "cross_channel_week_rule", "collections_channel_week_rule", "unknown_subject"],
    "N2": ["master_flag_off", "mode_off", "outreach_brake_global", "outreach_brake_channel", "outreach_brake_strategy", "ultron_abort_latch",
           "marketing_agent_paused", "voice_braked", "human_in_control", "legacy_autonomy_on", "legacy_followups_active", "global_suppression"],
    "N3": ["studio_reference_invalid", "catalog_incompatible", "payment_links_disabled", "template_violates_restriction", "canary_only_declared"],
    "N4": ["sends_disabled_in_code", "channel_disabled", "channel_not_live", "template_not_approved", "template_category_mismatch", "no_whatsapp_identity",
           "no_voice_contact", "voice_policy:*", "budget_zero", "budget_exhausted", "channel_daily_cap", "assisted_capacity_reached"],
    "N5": ["strategy_not_published", "strategy_paused", "chain_unreadable", "canary_scope_mismatch", "calendar_outside_own_hours", "outside_strategy_schedule",
           "max_touches_reached", "strategy_daily_cap", "enrollment_window_over", "cooldown_active", "step_guard_false"],
    "N6": ["success_reached", "context_changed", "human_requested_recently", "complaint_recent", "collections_overdue_blocks_commercial",
           "purpose_conflict_today", "lower_priority"],
    "N7": ["callback_scheduled", "enrollment_window_over"],
}
TEMPORAL = {"calendar_outside_legal_hours", "calendar_sunday", "calendar_holiday", "daily_contact_limit", "weekly_contact_limit", "min_hours_between_contacts",
            "cross_channel_week_rule", "collections_channel_week_rule", "budget_exhausted", "channel_daily_cap", "assisted_capacity_reached",
            "calendar_outside_own_hours", "outside_strategy_schedule", "strategy_daily_cap", "cooldown_active", "purpose_conflict_today", "lower_priority",
            "callback_scheduled"}
SHADOW_WARN = {"sends_disabled_in_code", "channel_disabled", "channel_not_live", "budget_zero", "budget_exhausted", "channel_daily_cap",
               "assisted_capacity_reached", "canary_only_declared", "canary_scope_mismatch", "legacy_autonomy_on"}
PAID = {"whatsapp_template", "voice_call"}
MEDIUM = {"assisted:voice": "voice", "assisted:whatsapp": "whatsapp", "voice_call": "voice", "whatsapp_template": "whatsapp"}
N7_ANNOTATIONS = ["preference_applied", "instruction_ignored_by_policy"]

BASE = {
    "now": bog(2026, 11, 10, 10, 0),
    "purpose": "commercial",
    "channel": "assisted:whatsapp",
    "subject": {"known": True, "isMinor": False},
    "consent": {"voice": "granted", "whatsapp": "granted"},
    "optOut": {"commercial": False, "doNotContact": False, "siblingDoNotContact": False},
    "voice": {"excluded": False, "rneOk": True},
    "history": [],
    "limits": {"perDay": 1, "perWeek": 2, "minHoursBetween": 48},
    "ownHours": None,
    "flags": {"master": True, "mode": "live", "channelEnabled": True, "channelLive": True, "sendsEnabledInCode": False, "legacyAutonomy": False,
              "legacyFollowupsScheduler": False},
    "brakes": {"global": False, "channel": False, "strategy": False, "ultronAbortLatch": False, "marketingAgentPaused": False, "voiceBrake": False},
    "conversation": {"humanInControl": False},
    "globalSuppression": False,
    "studio": {"referenceValid": True, "catalogCompatible": True, "paymentLinksEnabled": True, "canaryOnly": False, "templateViolations": []},
    "receivable": {"known": True, "overdueDaysMax": None},
    "collectionsBlock": {"serverDays": 15, "strategyDays": None},
    "step": {"usesPaymentLink": False, "templateApproved": True, "templateCategoryMatches": True},
    "identity": {"voiceContact": True, "whatsapp": True},
    "voicePolicyReasons": [],
    "budget": {"dailyBudgetMicros": 0, "reservedTodayMicros": 0, "costMicros": 0, "channelDailyCap": 0, "channelUsedToday": 0, "assistedDailyCap": 20,
               "assistedUsedToday": 0},
    "strategy": {"trigger": "membership.expiring_window", "published": True, "paused": False, "chainReadable": True, "part": "rest", "inCanaryScope": True,
                 "schedule": None, "touches": 0, "maxTouches": 6, "dailyCap": 50, "usedToday": 0, "enrollmentWindowEndsAt": bog(2026, 11, 30, 0, 0),
                 "cooldownUntil": None, "stepGuard": "true"},
    "context": {"success": "false", "audience": "true", "exclusions": "false", "humanRequestedRecently": False, "complaintRecently": False,
                "otherPurposeToday": False, "lowerPriority": False},
    "conversational": {"callbackAt": None},
}


def deep_merge(a, b):
    out = copy.deepcopy(a)
    for k, v in b.items():
        if isinstance(v, dict) and isinstance(out.get(k), dict) and v:
            out[k] = deep_merge(out[k], v)
        else:
            out[k] = copy.deepcopy(v)
    return out


def _order(level, reasons):
    order = N_ORDER[level]

    def key(r):
        return order.index("voice_policy:*") if r.startswith("voice_policy:") else order.index(r)
    return sorted(reasons, key=key)


def ladder_ref(x):
    now, medium, ch = x["now"], MEDIUM[x["channel"]], x["channel"]
    mode = x["flags"]["mode"] if x["flags"]["mode"] in ("shadow", "live") else "off"
    shadow = mode == "shadow"
    st, own = x["strategy"], x["ownHours"]
    sched = st["schedule"]
    per = {}
    release = {}
    # N1
    r = []
    bud = budget_ref(x["history"], now, x["purpose"], medium, x["limits"])
    for k in bud["reasons"]:
        r.append(k)
        if k in bud["releaseAt"]:
            release[k] = bud["releaseAt"][k]
    c = x["consent"].get(medium)
    if c in (None, "none"):
        r.append("no_consent_for_purpose_channel")
    elif c == "denied":
        r.append("consent_denied")
    if x["optOut"]["doNotContact"]:
        r.append("do_not_contact")
    if x["optOut"]["siblingDoNotContact"]:
        r.append("do_not_contact_sibling")
    if x["purpose"] == "commercial" and x["optOut"]["commercial"]:
        r.append("commercial_opt_out")
    if medium == "voice" and x["voice"]["excluded"]:
        r.append("voice_excluded")
    if medium == "voice" and x["voice"]["rneOk"] is not True:
        r.append("rne_check_missing")
    if x["subject"]["isMinor"] is True:
        r.append("subject_minor")
    if not x["subject"]["known"]:
        r.append("unknown_subject")
    per["N1"] = r
    # N2
    f, b = x["flags"], x["brakes"]
    r = []
    if not f["master"]:
        r.append("master_flag_off")
    if mode == "off":
        r.append("mode_off")
    if b["global"]:
        r.append("outreach_brake_global")
    if b["channel"]:
        r.append("outreach_brake_channel")
    if b["strategy"]:
        r.append("outreach_brake_strategy")
    if medium == "whatsapp" and b["ultronAbortLatch"]:
        r.append("ultron_abort_latch")
    if medium == "whatsapp" and b["marketingAgentPaused"]:
        r.append("marketing_agent_paused")
    if medium == "voice" and b["voiceBrake"]:
        r.append("voice_braked")
    if x["conversation"]["humanInControl"]:
        r.append("human_in_control")
    if f["legacyAutonomy"]:
        r.append("legacy_autonomy_on")
    if f["legacyFollowupsScheduler"] and st["trigger"] == "lead.followup_due":
        r.append("legacy_followups_active")
    if x["globalSuppression"]:
        r.append("global_suppression")
    per["N2"] = r
    # N3
    s = x["studio"]
    r = []
    if not s["referenceValid"]:
        r.append("studio_reference_invalid")
    if not s["catalogCompatible"]:
        r.append("catalog_incompatible")
    if x["step"]["usesPaymentLink"] and not s["paymentLinksEnabled"]:
        r.append("payment_links_disabled")
    if s["templateViolations"]:
        r.append("template_violates_restriction")
    if s["canaryOnly"] and not st["inCanaryScope"]:
        r.append("canary_only_declared")
    per["N3"] = r
    # N4
    bu, stp, idn = x["budget"], x["step"], x["identity"]
    r = []
    if ch in PAID and not f["sendsEnabledInCode"]:
        r.append("sends_disabled_in_code")
    if not f["channelEnabled"]:
        r.append("channel_disabled")
    if not f["channelLive"]:
        r.append("channel_not_live")
    if not stp["templateApproved"]:
        r.append("template_not_approved")
    if not stp["templateCategoryMatches"]:
        r.append("template_category_mismatch")
    if medium == "whatsapp" and not idn["whatsapp"]:
        r.append("no_whatsapp_identity")
    if medium == "voice" and not idn["voiceContact"]:
        r.append("no_voice_contact")
    if ch == "voice_call":
        r += ["voice_policy:" + v for v in x["voicePolicyReasons"]]
    nxt = _next_bogota_midnight(now)
    if ch in PAID:
        if bu["dailyBudgetMicros"] == 0 or bu["channelDailyCap"] == 0:
            r.append("budget_zero")
        else:
            if bu["reservedTodayMicros"] + bu["costMicros"] > bu["dailyBudgetMicros"]:
                r.append("budget_exhausted")
                release["budget_exhausted"] = nxt
            if bu["channelUsedToday"] >= bu["channelDailyCap"]:
                r.append("channel_daily_cap")
                release["channel_daily_cap"] = nxt
    else:
        if bu["assistedDailyCap"] == 0:
            r.append("budget_zero")
        elif bu["assistedUsedToday"] >= bu["assistedDailyCap"]:
            r.append("assisted_capacity_reached")
            release["assisted_capacity_reached"] = nxt
    per["N4"] = r
    # N5
    r = []
    if not st["published"]:
        r.append("strategy_not_published")
    if st["paused"]:
        r.append("strategy_paused")
    if not st["chainReadable"]:
        r.append("chain_unreadable")
    if st["part"] == "canary" and not st["inCanaryScope"]:
        r.append("canary_scope_mismatch")
    if own is not None and not inside(now, own):
        r.append("calendar_outside_own_hours")
    if sched is not None and not inside(now, own, sched):
        r.append("outside_strategy_schedule")
    if st["touches"] >= st["maxTouches"]:
        r.append("max_touches_reached")
    if st["usedToday"] >= st["dailyCap"]:
        r.append("strategy_daily_cap")
        release["strategy_daily_cap"] = nxt
    if parse_iso(now) >= parse_iso(st["enrollmentWindowEndsAt"]):
        r.append("enrollment_window_over")
    if st["cooldownUntil"] is not None and parse_iso(now) < parse_iso(st["cooldownUntil"]):
        r.append("cooldown_active")
        release["cooldown_active"] = st["cooldownUntil"]
    if st["stepGuard"] != "true":
        r.append("step_guard_false")
    per["N5"] = r
    # N6
    cx = x["context"]
    r = []
    if cx["success"] == "true":
        r.append("success_reached")
    if cx["audience"] != "true" or cx["exclusions"] != "false":
        r.append("context_changed")
    if cx["humanRequestedRecently"]:
        r.append("human_requested_recently")
    # DF-L14: la queja reciente; null (fuente sin datos) niega, como en PolicyLadder (`!== false`).
    if cx["complaintRecently"] is not False:
        r.append("complaint_recent")
    rc, cb_ = x["receivable"], x["collectionsBlock"]
    if x["purpose"] == "commercial":
        days = cb_["serverDays"]
        if isinstance(cb_["strategyDays"], int) and not isinstance(cb_["strategyDays"], bool):
            days = min(days, cb_["strategyDays"])
        if not rc["known"] or (rc["overdueDaysMax"] is not None and rc["overdueDaysMax"] > days):
            r.append("collections_overdue_blocks_commercial")
    if cx["otherPurposeToday"]:
        r.append("purpose_conflict_today")
        release["purpose_conflict_today"] = nxt
    if cx["lowerPriority"]:
        r.append("lower_priority")
        release["lower_priority"] = nxt
    per["N6"] = r
    # N7
    r = []
    cb = x["conversational"]["callbackAt"]
    if cb is not None and parse_iso(cb) > parse_iso(now):
        slot = next_slot(cb, own, sched)
        if parse_iso(slot) >= parse_iso(st["enrollmentWindowEndsAt"]):
            r.append("enrollment_window_over")
        else:
            r.append("callback_scheduled")
            release["callback_scheduled"] = cb
    per["N7"] = r

    levels, warnings = [], []
    decided = None
    for lv in ("N1", "N2", "N3", "N4", "N5", "N6", "N7"):
        if decided is not None:
            levels.append({"level": lv, "status": "not_evaluated"})
            continue
        reasons = per[lv]
        if shadow:
            warn = [q for q in reasons if q in SHADOW_WARN]
            warnings += [{"level": lv, "reason": q} for q in _order(lv, warn)]
            reasons = [q for q in reasons if q not in SHADOW_WARN]
        elif lv == "N2" and "legacy_autonomy_on" in reasons and mode != "live":
            reasons = [q for q in reasons if q != "legacy_autonomy_on"]
        if not reasons:
            levels.append({"level": lv, "status": "pass"})
            continue
        reasons = _order(lv, reasons)
        temporal = all(q in TEMPORAL for q in reasons) and not (lv == "N5" and own == "invalid" and "calendar_outside_own_hours" in reasons)
        decided = {"level": lv, "reasons": reasons, "temporal": temporal}
        levels.append({"level": lv, "status": "defer" if temporal else "deny"})
    if decided is None:
        return {"verdict": "allow", "allowed": True, "level": None, "reasons": [], "retryAt": None, "waitsForRelease": False, "effect": None,
                "levels": levels, "warnings": warnings}
    retry = None
    if decided["temporal"]:
        t0 = max([now] + [release[q] for q in decided["reasons"] if q in release], key=lambda s: parse_iso(s))
        retry = next_slot(t0, own, sched)
    effect = None
    if decided["level"] == "N6":
        rs = decided["reasons"]
        effect = ("exit_converted" if "success_reached" in rs else "handoff" if "human_requested_recently" in rs
                  else "cancel_context_changed" if "context_changed" in rs else "exit_complaint" if "complaint_recent" in rs else None)
    return {"verdict": "defer" if decided["temporal"] else "deny", "allowed": False, "level": decided["level"], "reasons": decided["reasons"],
            "retryAt": retry, "waitsForRelease": decided["level"] == "N2", "effect": effect, "levels": levels, "warnings": warnings}


PL = Fixture("policy-ladder-cases.json", header(
    "policy-ladder-cases.json",
    ["D9-7 (la escalera N1–N7: pura, en orden, el primer nivel que niega decide, los inferiores solo restringen; motivos cerrados por nivel)",
     "D9-8 (etapas 4 y 6: la misma escalera al planificar y al despachar; sombra: N4 avisa; los frenos retienen)", "D9-9.5 (asistido: horario, frecuencia y consentimiento del medio)",
     "D9-15 (matriz de consentimiento finalidad × canal; menores; cobranza sin autorización hoy)", "D9-1 (legacy_autonomy_on en live)",
     "D9-11.6 (en sombra se evalúa la audiencia entera: el alcance canario de N3 y N5 es aviso, «would_scope»)",
     "D9-7 N6, enmienda aditiva del 11-11-2026 (Q1-3 del plan de quejas): complaint_recent, que ninguna estrategia quita y que niega también en sombra",
     "§13.1 #4, #5, #8, #9, #11, #12, #15, #17; §13.2 T16–T22"],
    [SOURCE_CONTRACT,
     {"what": "El calendario y el presupuesto de contacto de N1", "url": "tests/fixtures/outreach/calendar-cases.json y contact-budget-cases.json (este mismo paquete)",
      "consulted": "09-11-2026", "how": "N1 usa ContactBudget::check tal cual (motivos de calendario y frecuencia) y el primer hueco de calendar-cases.json §nextSlot."}],
    reasonOrder=N_ORDER,
    temporalReasons=sorted(TEMPORAL),
    shadowWarnings=sorted(SHADOW_WARN),
    n7Annotations=N7_ANNOTATIONS,
    baseInput=BASE,
    interface={
        "decide": "PolicyLadder::decide(input) → output. `input` es un DTO neutro (baseInput con el patch del caso ya aplicado: viene entero en cada caso); la prueba de B9-core lo traduce a sus clases sin cambiar el caso.",
        "output": "{verdict: allow|deny|defer, allowed, level: N1..N7|null, reasons (solo los del nivel que decide, en reasonOrder), retryAt (solo defer), waitsForRelease (true si decide N2: el trabajo espera a que se suelte, D9-8), effect (N6: exit_converted | handoff | exit_complaint | cancel_context_changed), levels: [{level, status: pass|deny|defer|not_evaluated}], warnings: [{level, reason}] (sombra)}",
        "medium": "assisted:whatsapp y whatsapp_template son WhatsApp; assisted:voice y voice_call son voz (D9-9.5: el asistido aplica las reglas de su medio).",
    },
    decisions={
        "DF-L1": "Dentro del nivel que decide se ACUMULAN todos sus motivos, en el orden de reasonOrder (el de la tabla de D9-7); los niveles anteriores salen pass y los posteriores not_evaluated.",
        "DF-L2": "Si todos los motivos del nivel que decide son temporales (temporalReasons), defer con retryAt; si alguno es permanente, deny sin retryAt.",
        "DF-L3": "retryAt = el primer hueco (calendar-cases.json §nextSlot, con el horario propio y el de la estrategia) a partir del más tardío entre now y lo que suelta cada motivo temporal del nivel que decide (presupuesto de contacto, 00:00 de Bogotá del día siguiente para los topes diarios, cooldownUntil, la hora pedida por la persona). Así N1 nunca aplaza a una hora que el horario propio volvería a negar.",
        "DF-L11": "Horario propio (L9-15): calendar_outside_own_hours es de N5, entre canary_scope_mismatch y outside_strategy_schedule, y aplaza al primer hueco del horario propio. Fuera de la ley decide N1 (calendar_outside_legal_hours) aunque también se esté fuera del propio. Con el horario propio mal configurado ('invalid') no hay hueco posible: deny sin retryAt.",
        "DF-L12": "Cartera vencida (P9-7, L9-20): en N6, después de human_requested_recently, collections_overdue_blocks_commercial niega (permanente) un contacto con finalidad commercial si receivable.overdueDaysMax > el umbral, o si la fuente de cartera no se pudo leer (receivable.known = false, el lado seguro). Umbral = outreach.collections.commercial_block_days (15 por defecto); la estrategia solo puede bajarlo (limits.commercialBlockOverdueDays: umbral = el menor). «Más de N días»: a los N justos no bloquea. Ni collections ni service se bloquean.",
        "DF-L13": "catalog_incompatible es de N3 (L9-21), justo después de studio_reference_invalid: la versión usa un hecho o un evento que el catálogo vigente ya no tiene (catalog-snapshot.json §compatibility).",
        "DF-L4": "N2: master_flag_off, mode_off y los frenos retienen (waitsForRelease = true): el trabajo planificado espera, no se cancela (D9-8). ultron_abort_latch y marketing_agent_paused aplican al medio WhatsApp (también al asistido); voice_braked al medio voz; human_in_control a todos los medios; legacy_autonomy_on solo en live (en sombra, aviso); legacy_followups_active solo a las estrategias con disparador lead.followup_due.",
        "DF-L5": "N4 en sombra: sends_disabled_in_code, channel_disabled, channel_not_live, budget_zero, budget_exhausted, channel_daily_cap y assisted_capacity_reached son avisos (D9-8 nombra los tres primeros y «los presupuestos»; channel_not_live se añade para que una estrategia de WhatsApp o voz se pueda simular antes de W9/V9; ratificada por el lead: L9-16). La plantilla sin aprobar o de otra categoría niega igual en sombra.",
        "DF-L6": "N4: sends_disabled_in_code solo en whatsapp_template y voice_call (el asistido sale con la constante cerrada, protección 2); budget_zero = presupuesto o tope del canal en 0 (en el asistido, su tope diario); budget_exhausted si lo reservado + el coste supera el presupuesto; los topes diarios se sueltan a las 00:00 de Bogotá. no_whatsapp_identity y no_voice_contact también en el asistido de su medio.",
        "DF-L7": "El canario en sombra (D9-11.6: «se evalúa la audiencia entera y se anota would_scope»): canary_scope_mismatch de N5 (parte canary y la persona fuera del alcance) y canary_only_declared de N3 (governance.canaryOnly, que toda versión del Studio declara por R-12) son avisos; en vivo, también en el asistido, los dos niegan igual (N3: I-1 de la auditoría de cierre B, aprobada por el lead el 11-11-2026). N5: step_guard_false con la guarda false o unknown (solo true deja pasar); outside_strategy_schedule y cooldown_active aplazan; max_touches_reached y enrollment_window_over niegan.",
        "DF-L8": "N6: context_changed si la audiencia ya no es true o las exclusiones ya no son false (unknown incluido); effect: success_reached → exit_converted (la inscripción cierra como converted, #4), human_requested_recently → handoff, context_changed → cancel_context_changed (#15), complaint_recent → exit_complaint (DF-L14). purpose_conflict_today y lower_priority aplazan al día siguiente.",
        "DF-L9": "N7: una petición de la persona de que la contacten más tarde (callbackAt posterior a now) aplaza al primer hueco legal a partir de esa hora (callback_scheduled); si ese hueco cae en o después del fin de la ventana de la inscripción, deny con enrollment_window_over (D9-7). Una hora ya pasada se ignora. preference_applied e instruction_ignored_by_policy son anotaciones del selector de canal, no motivos que nieguen (channel-selection-cases.json). Ratificada por el lead: L9-24.",
        "DF-L14": "Queja reciente (Q1-3 del plan de quejas; enmienda aditiva de D9-7 del 11-11-2026, autorizada por el lead): en N6, después de human_requested_recently, complaint_recent niega (permanente) si context.complaintRecently no es false: true = hay un complaint_created en la ventana; null = la fuente de eventos no tiene datos (el lado seguro, como L9-26). Ninguna estrategia lo quita y no es aviso de sombra: en sombra niega igual. effect exit_complaint: la inscripción sale (exited, exit_reason complaint_recent); si también hay success_reached, human_requested_recently o context_changed, manda el efecto del que va antes en reasonOrder. La ventana (días de Bogotá; por defecto la de human_requested, 30; 0 = apagado) y las finalidades (por defecto commercial y collections; service no) las aplica quien arma la entrada (LadderInputBuilder::complaintRecently): con una finalidad a la que no aplica, la entrada llega false. Hasta que el propietario decida D2 y D3.",
        "DF-L10": "N1: subject_minor solo con la marca de menor true; desconocido (lead o socio sin fecha) no se infiere ni se niega (ratificada por el lead: L9-17; la asesoría decide si debe negarse). commercial_opt_out solo con finalidad commercial. voice_excluded y rne_check_missing solo con el medio voz (también el asistido). Consentimiento del medio para la finalidad del caso: none o ausente → no_consent_for_purpose_channel; denied → consent_denied.",
    },
))


def LAD(sec, desc, patch, expected, *, ref, basis="contrato", decision=None):
    x = deep_merge(BASE, patch)
    got = ladder_ref(x)
    for k, v in expected.items():
        assert got[k] == v, (desc, k, "referencia:", got[k], "literal:", v)
    PL.add(sec, sec, desc, ref, basis, {"patch": patch, "input": x}, got, decision=decision)


ALLOW = {"verdict": "allow", "level": None, "reasons": [], "retryAt": None}


def DENY(level, reasons, **kw):
    return dict({"verdict": "deny", "level": level, "reasons": reasons, "retryAt": None}, **kw)


def DEFER(level, reasons, at, **kw):
    return dict({"verdict": "defer", "level": level, "reasons": reasons, "retryAt": at}, **kw)


SUN, MON_HOL, MON_2578 = bog(2026, 11, 15, 10, 0), bog(2026, 11, 16, 10, 0), bog(2026, 7, 13, 10, 0)

# ── N1 · seguridad, ley y consentimiento (T16, #8, #17) ─────────────────────
LAD("n1", "control: todo en orden → allow (asistido por WhatsApp en live)", {}, ALLOW, ref="D9-7")
LAD("n1", "domingo → defer al lunes 08:00 (#17)", {"now": bog(2026, 11, 8, 10, 0)}, DEFER("N1", ["calendar_sunday"], bog(2026, 11, 9, 8, 0)),
    ref="T16, #17", decision="DF-L3")
LAD("n1", "domingo 15-11-2026 antes de un lunes festivo → defer al martes 17-11 08:00", {"now": SUN},
    DEFER("N1", ["calendar_sunday"], bog(2026, 11, 17, 8, 0)), ref="T16, #17", basis="ley", decision="DF-L3")
LAD("n1", "lunes festivo trasladado (16-11-2026) → defer al martes 08:00", {"now": MON_HOL}, DEFER("N1", ["calendar_holiday"], bog(2026, 11, 17, 8, 0)),
    ref="T16 (lunes trasladado), #17", basis="ley", decision="DF-L3")
LAD("n1", "Ley 2578: lunes 13-07-2026 (Chiquinquirá, por confirmar) → defer al martes 08:00", {"now": MON_2578},
    DEFER("N1", ["calendar_holiday"], bog(2026, 7, 14, 8, 0)), ref="T16 (Ley 2578), #17", basis="ley", decision="DF-L3")
LAD("n1", "sábado 15:00 → defer al lunes 08:00", {"now": bog(2026, 11, 7, 15, 0)}, DEFER("N1", ["calendar_outside_legal_hours"], bog(2026, 11, 9, 8, 0)),
    ref="T16 (sábado a las 15:00), #17", decision="DF-L3")
LAD("n1", "lunes 07:59 → defer a las 08:00", {"now": bog(2026, 11, 9, 7, 59)}, DEFER("N1", ["calendar_outside_legal_hours"], bog(2026, 11, 9, 8, 0)),
    ref="T16 (07:59 de un lunes), #17", decision="DF-L3")
LAD("n1", "menor de edad → deny", {"subject": {"isMinor": True}}, DENY("N1", ["subject_minor"]), ref="T16, D9-15 («los menores, excluidos»)")
LAD("n1", "menor desconocido (lead sin fecha) → no se infiere: allow", {"subject": {"isMinor": None}}, ALLOW, ref="D9-15 (en un lead sin fecha queda desconocido y no se infiere)",
    basis="decision-fixture", decision="DF-L10")
LAD("n1", "do_not_contact de un lead hermano (mismo teléfono normalizado) → deny", {"optOut": {"siblingDoNotContact": True}},
    DENY("N1", ["do_not_contact_sibling"]), ref="T16, D9-7 (MarketingMessageDispatcher.php:64-88), §13.3 (teléfono compartido)")
LAD("n1", "do_not_contact propio → deny", {"optOut": {"doNotContact": True}}, DENY("N1", ["do_not_contact"]), ref="D9-7")
LAD("n1", "sin consentimiento de voz para comercial (asistido por voz) → deny (#8)", {"channel": "assisted:voice", "consent": {"voice": "none"}},
    DENY("N1", ["no_consent_for_purpose_channel"]), ref="#8 de §13.1, T16")
LAD("n1", "consentimiento de WhatsApp denegado → deny consent_denied", {"consent": {"whatsapp": "denied"}}, DENY("N1", ["consent_denied"]), ref="D9-7, D9-15",
    basis="decision-fixture", decision="DF-L10")
LAD("n1", "consentimiento ausente (null) = ninguno → deny", {"consent": {"whatsapp": None}}, DENY("N1", ["no_consent_for_purpose_channel"]),
    ref="D9-15 («se niega por defecto»)", basis="decision-fixture", decision="DF-L10")
LAD("n1", "cobranza por WhatsApp sin autorización de cobranza (P9-15: ninguna hoy) → deny", {"purpose": "collections", "consent": {"whatsapp": "none"}},
    DENY("N1", ["no_consent_for_purpose_channel"]), ref="D9-15 (collections: ninguna hoy), P9-15", basis="supuesto-lead")
LAD("n1", "opt-out comercial con finalidad commercial → deny", {"optOut": {"commercial": True}}, DENY("N1", ["commercial_opt_out"]),
    ref="D9-7 (solo para commercial)")
LAD("n1", "opt-out comercial con finalidad service → no aplica: allow", {"purpose": "service", "optOut": {"commercial": True}}, ALLOW,
    ref="D9-7 (commercial_opt_out solo para commercial)", basis="decision-fixture", decision="DF-L10")
LAD("n1", "voz excluida y sin consulta al RNE (asistido por voz) → deny con los dos", {"channel": "assisted:voice", "voice": {"excluded": True, "rneOk": False}},
    DENY("N1", ["voice_excluded", "rne_check_missing"]), ref="D9-7", basis="decision-fixture", decision="DF-L10")
LAD("n1", "exclusión de voz no afecta a WhatsApp → allow", {"voice": {"excluded": True, "rneOk": False}}, ALLOW, ref="D9-7", basis="decision-fixture",
    decision="DF-L10")
LAD("n1", "persona desconocida → deny", {"subject": {"known": False}}, DENY("N1", ["unknown_subject"]), ref="D9-7")
LAD("n1", "domingo y sin consentimiento → deny (lo permanente manda; sin retryAt)", {"now": bog(2026, 11, 8, 10, 0), "consent": {"whatsapp": "none"}},
    DENY("N1", ["calendar_sunday", "no_consent_for_purpose_channel"]), ref="D9-7", basis="decision-fixture", decision="DF-L2")
LAD("n1", "contacto de hoy por otra finalidad → daily_contact_limit y 48 h (#5: nunca dos el mismo día)",
    {"history": [H(bog(2026, 11, 10, 8, 30), purpose="collections", source="outreach")]},
    DEFER("N1", ["daily_contact_limit", "min_hours_between_contacts"], bog(2026, 11, 12, 8, 30)), ref="#5 de §13.1, D9-7", decision="DF-L3")
LAD("n1", "contacto por voz el viernes y ahora WhatsApp → cross_channel_week_rule (#11)", {"history": [H(bog(2026, 11, 6, 11, 0), "voice", source="voice_call")]},
    DEFER("N1", ["cross_channel_week_rule"], bog(2026, 11, 13, 11, 0, 1)), ref="#11 de §13.1 (P9-8)", basis="decision-fixture", decision="DF-B5")
LAD("n1", "cobranza tras un contacto directo por voz → collections_channel_week_rule",
    {"purpose": "collections", "consent": {"whatsapp": "granted"}, "history": [H(bog(2026, 11, 6, 11, 0), "voice", purpose="collections", source="outreach")]},
    DEFER("N1", ["collections_channel_week_rule"], bog(2026, 11, 13, 11, 0, 1)), ref="D9-15 (Ley 2300, art. 3)", basis="ley", decision="DF-B5")

# ── N2 · exclusiones y frenos globales (T17) ────────────────────────────────
LAD("n2", "bandera maestra apagada → deny y el trabajo espera", {"flags": {"master": False}}, DENY("N2", ["master_flag_off"], waitsForRelease=True), ref="T17, D9-8",
    basis="decision-fixture", decision="DF-L4")
LAD("n2", "modo off → deny", {"flags": {"mode": "off"}}, DENY("N2", ["mode_off"]), ref="T17, §3.1")
LAD("n2", "modo con un valor raro (cualquier otro es off) → deny mode_off", {"flags": {"mode": "LIVE"}}, DENY("N2", ["mode_off"]),
    ref="§3.1 («cualquier otro valor es off»)")
LAD("n2", "PARADA GLOBAL → deny", {"brakes": {"global": True}}, DENY("N2", ["outreach_brake_global"], waitsForRelease=True), ref="T17, D9-14", basis="decision-fixture",
    decision="DF-L4")
LAD("n2", "freno del canal → deny", {"brakes": {"channel": True}}, DENY("N2", ["outreach_brake_channel"]), ref="T17")
LAD("n2", "freno de la estrategia → deny", {"brakes": {"strategy": True}}, DENY("N2", ["outreach_brake_strategy"]), ref="T17")
LAD("n2", "UltronAbortLatch echado y medio WhatsApp → deny", {"brakes": {"ultronAbortLatch": True}}, DENY("N2", ["ultron_abort_latch"]), ref="T17, D9-2 (OperationalBrakes)")
LAD("n2", "UltronAbortLatch echado y medio voz → no aplica: allow", {"channel": "assisted:voice", "brakes": {"ultronAbortLatch": True}}, ALLOW, ref="D9-7 («(WhatsApp)»)",
    basis="decision-fixture", decision="DF-L4")
LAD("n2", "agente de mercadeo en pausa y medio WhatsApp → deny", {"brakes": {"marketingAgentPaused": True}}, DENY("N2", ["marketing_agent_paused"]), ref="T17")
LAD("n2", "freno de voz y asistido por voz → deny", {"channel": "assisted:voice", "brakes": {"voiceBrake": True}}, DENY("N2", ["voice_braked"]), ref="T17",
    basis="decision-fixture", decision="DF-L4")
LAD("n2", "una persona al mando de la conversación → deny", {"conversation": {"humanInControl": True}}, DENY("N2", ["human_in_control"]), ref="T17")
LAD("n2", "COMMERCIAL_AUTONOMY_ENABLED encendida y el H9 en live → deny legacy_autonomy_on", {"flags": {"legacyAutonomy": True}},
    DENY("N2", ["legacy_autonomy_on"]), ref="D9-1 (el H9 en live exige commercial.autonomy_enabled = false), T17")
LAD("n2", "COMMERCIAL_AUTONOMY_ENABLED = false (lo normal) → allow", {"flags": {"legacyAutonomy": False}}, ALLOW, ref="T17, D9-1")
LAD("n2", "seguimientos del motor encendidos y disparador lead.followup_due → deny", {"flags": {"legacyFollowupsScheduler": True},
                                                                                     "strategy": {"trigger": "lead.followup_due"}},
    DENY("N2", ["legacy_followups_active"]), ref="D9-3 (conflicto con marketing:dispatch-followups)")
LAD("n2", "seguimientos del motor encendidos con otro disparador → allow", {"flags": {"legacyFollowupsScheduler": True}}, ALLOW, ref="D9-3",
    basis="decision-fixture", decision="DF-L4")
LAD("n2", "supresión global (C9) → deny", {"globalSuppression": True}, DENY("N2", ["global_suppression"]), ref="D9-7")
LAD("n2", "maestra apagada, PARADA GLOBAL y persona al mando → los tres en orden", {"flags": {"master": False}, "brakes": {"global": True},
                                                                                   "conversation": {"humanInControl": True}},
    DENY("N2", ["master_flag_off", "outreach_brake_global", "human_in_control"]), ref="D9-7", basis="decision-fixture", decision="DF-L1")

# ── N3 · versión comercial aprobada (T18) ───────────────────────────────────
LAD("n3", "la referencia del Studio no cuadra → deny", {"studio": {"referenceValid": False}}, DENY("N3", ["studio_reference_invalid"]), ref="T18, D9-11.4")
LAD("n3", "la versión usa un hecho retirado del catálogo → catalog_incompatible (N3, L9-21)", {"studio": {"catalogCompatible": False}},
    DENY("N3", ["catalog_incompatible"]), ref="D9-3 (catalog_incompatible), L9-21", basis="supuesto-lead", decision="DF-L13")
LAD("n3", "referencia del Studio que no cuadra y catálogo incompatible: los dos, en orden", {"studio": {"referenceValid": False, "catalogCompatible": False}},
    DENY("N3", ["studio_reference_invalid", "catalog_incompatible"]), ref="D9-7 N3, L9-21", basis="supuesto-lead", decision="DF-L13")
LAD("n3", "paso con enlace de pago y paymentLinksEnabled = false → deny", {"step": {"usesPaymentLink": True}, "studio": {"paymentLinksEnabled": False}},
    DENY("N3", ["payment_links_disabled"]), ref="T18")
LAD("n3", "paso con enlace de pago y paymentLinksEnabled = true → allow", {"step": {"usesPaymentLink": True}}, ALLOW, ref="T18")
LAD("n3", "plantilla que viola R-02 (descuento) al despachar → deny", {"studio": {"templateViolations": ["R-02"]}}, DENY("N3", ["template_violates_restriction"]),
    ref="D9-7 N3")
LAD("n3", "governance.canaryOnly y la persona fuera del alcance canario → deny", {"studio": {"canaryOnly": True}, "strategy": {"inCanaryScope": False}},
    DENY("N3", ["canary_only_declared"]), ref="D9-7 N3")
LAD("n3", "governance.canaryOnly en el asistido de voz y la persona sin alcance canario conocido (null) → deny", {"channel": "assisted:voice", "studio": {"canaryOnly": True},
                                                                                                                 "strategy": {"inCanaryScope": None}},
    DENY("N3", ["canary_only_declared"]), ref="D9-7 N3, D9-11.6 («en el asistido, el del medio»)", decision="DF-L7")

# ── N4 · configuración del canal (T19) ──────────────────────────────────────
LAD("n4", "plantilla de WhatsApp en live con la constante cerrada → deny", {"channel": "whatsapp_template",
                                                                         "budget": {"dailyBudgetMicros": 1000000, "channelDailyCap": 10, "costMicros": 5000}},
    DENY("N4", ["sends_disabled_in_code"]), ref="T19, §3.2")
LAD("n4", "voz por estrategia el 15-11: constante cerrada y canal sin implementar (V9)", {"channel": "voice_call", "flags": {"channelLive": False},
                                                                                       "budget": {"dailyBudgetMicros": 1000000, "channelDailyCap": 3, "costMicros": 90000}},
    DENY("N4", ["sends_disabled_in_code", "channel_not_live"]), ref="T19, D9-9.4", basis="decision-fixture", decision="DF-L6")
LAD("n4", "asistido apagado → deny channel_disabled", {"flags": {"channelEnabled": False}}, DENY("N4", ["channel_disabled"]), ref="T19, §9.2 nivel 2")
LAD("n4", "plantilla sin aprobar → deny", {"step": {"templateApproved": False}}, DENY("N4", ["template_not_approved"]), ref="T19")
LAD("n4", "categoría que no cuadra (cobranza con plantilla marketing) → deny", {"purpose": "collections", "step": {"templateCategoryMatches": False}},
    DENY("N4", ["template_category_mismatch"]), ref="T19, D9-6")
LAD("n4", "socio sin lead de WhatsApp (asistido por WhatsApp) → deny no_whatsapp_identity", {"identity": {"whatsapp": False}},
    DENY("N4", ["no_whatsapp_identity"]), ref="D9-9.3 (socios sin lead), P9-14", basis="decision-fixture", decision="DF-L6")
LAD("n4", "sin voice_contacts (asistido por voz) → deny no_voice_contact", {"channel": "assisted:voice", "identity": {"voiceContact": False}},
    DENY("N4", ["no_voice_contact"]), ref="D9-7 N4", basis="decision-fixture", decision="DF-L6")
LAD("n4", "V9 (hipotético): motivos de OutboundPolicy como voice_policy:<motivo>, en su orden",
    {"channel": "voice_call", "flags": {"sendsEnabledInCode": True}, "voicePolicyReasons": ["no_voice_consent", "frequency_exceeded"],
     "budget": {"dailyBudgetMicros": 1000000, "channelDailyCap": 3, "costMicros": 90000}},
    DENY("N4", ["voice_policy:no_voice_consent", "voice_policy:frequency_exceeded"]), ref="D9-7 N4 (los 26 de OutboundPolicy, en V9)")
LAD("n4", "tope diario del asistido en 0 → deny budget_zero", {"budget": {"assistedDailyCap": 0}}, DENY("N4", ["budget_zero"]), ref="T19, §3.1 (topes en 0)",
    basis="decision-fixture", decision="DF-L6")
LAD("n4", "capacidad del asistido agotada → defer a mañana 08:00", {"budget": {"assistedUsedToday": 20}},
    DEFER("N4", ["assisted_capacity_reached"], bog(2026, 11, 11, 8, 0)), ref="T19", basis="decision-fixture", decision="DF-L6")
LAD("n4", "WhatsApp con presupuesto: la reserva no cabe → defer a mañana 08:00 (#16)",
    {"channel": "whatsapp_template", "flags": {"sendsEnabledInCode": True}, "budget": {"dailyBudgetMicros": 100000, "reservedTodayMicros": 96000,
                                                                                         "costMicros": 5000, "channelDailyCap": 10}},
    DEFER("N4", ["budget_exhausted"], bog(2026, 11, 11, 8, 0)), ref="#16 de §13.1, T19", basis="decision-fixture", decision="DF-L6")
LAD("n4", "WhatsApp con presupuesto: la reserva cabe justo (exactamente igual al tope) → allow",
    {"channel": "whatsapp_template", "flags": {"sendsEnabledInCode": True}, "budget": {"dailyBudgetMicros": 100000, "reservedTodayMicros": 95000,
                                                                                         "costMicros": 5000, "channelDailyCap": 10}},
    ALLOW, ref="§13.3 (presupuesto exactamente igual al tope)", basis="decision-fixture", decision="DF-L6")
LAD("n4", "WhatsApp: tope diario del canal alcanzado → defer", {"channel": "whatsapp_template", "flags": {"sendsEnabledInCode": True},
                                                               "budget": {"dailyBudgetMicros": 100000, "costMicros": 5000, "channelDailyCap": 10, "channelUsedToday": 10}},
    DEFER("N4", ["channel_daily_cap"], bog(2026, 11, 11, 8, 0)), ref="T19", basis="decision-fixture", decision="DF-L6")

# ── N5 · estrategia aprobada (T20) ──────────────────────────────────────────
LAD("n5", "estrategia no publicada → deny", {"strategy": {"published": False}}, DENY("N5", ["strategy_not_published"]), ref="T20")
LAD("n5", "estrategia en pausa (cadena) → deny (#23)", {"strategy": {"paused": True}}, DENY("N5", ["strategy_paused"]), ref="#23 de §13.1, T20")
LAD("n5", "cadena ilegible → deny", {"strategy": {"chainReadable": False}}, DENY("N5", ["chain_unreadable"]), ref="T20, D9-11.3")
LAD("n5", "canario en live y la persona fuera del alcance → deny", {"strategy": {"part": "canary", "inCanaryScope": False}},
    DENY("N5", ["canary_scope_mismatch"]), ref="T2, T20, D9-11.6")
LAD("n5", "horario propio L–V 09:00–18:00, martes 08:30 (la ley ya abrió) → N5 calendar_outside_own_hours, defer a las 09:00",
    {"now": bog(2026, 11, 10, 8, 30), "ownHours": {"weekdays": [540, 1080], "saturday": [540, 780]}}, DEFER("N5", ["calendar_outside_own_hours"], bog(2026, 11, 10, 9, 0)),
    ref="P9-17, L9-15 (T20: el horario propio)", basis="supuesto-lead", decision="DF-L11")
LAD("n5", "horario propio S 09:00–13:00, sábado 13:30 (la ley abre hasta las 15:00) → defer al martes 17-11 09:00 (lunes festivo)",
    {"now": bog(2026, 11, 14, 13, 30), "ownHours": {"weekdays": [540, 1080], "saturday": [540, 780]}}, DEFER("N5", ["calendar_outside_own_hours"], bog(2026, 11, 17, 9, 0)),
    ref="P9-17, L9-15", basis="supuesto-lead", decision="DF-L11")
LAD("n5", "horario propio mal configurado → deny sin retryAt (no hay hueco posible)", {"ownHours": "invalid"},
    DENY("N5", ["calendar_outside_own_hours"]), ref="P9-17, L9-15, OutboundSchedule (falla cerrado)", basis="decision-fixture", decision="DF-L11")
LAD("n5", "dentro del horario propio → allow", {"now": bog(2026, 11, 10, 10, 0), "ownHours": {"weekdays": [540, 1080], "saturday": [540, 780]}}, ALLOW, ref="P9-17, L9-15", basis="supuesto-lead",
    decision="DF-L11")
LAD("n5", "fuera del horario de la estrategia (martes a jueves 15:00–17:00) → defer a las 15:00",
    {"strategy": {"schedule": {"days": [2, 3, 4], "windows": [{"from": 900, "to": 1020}]}}},
    DEFER("N5", ["outside_strategy_schedule"], bog(2026, 11, 10, 15, 0)), ref="T20 (el horario propio), D9-6", basis="decision-fixture", decision="DF-L7")
LAD("n5", "tope de contactos de la inscripción alcanzado → deny", {"strategy": {"touches": 6}}, DENY("N5", ["max_touches_reached"]), ref="T20")
LAD("n5", "tope diario de la estrategia → defer a mañana 08:00", {"strategy": {"usedToday": 50}}, DEFER("N5", ["strategy_daily_cap"], bog(2026, 11, 11, 8, 0)),
    ref="T20", basis="decision-fixture", decision="DF-L7")
LAD("n5", "ventana de la inscripción vencida → deny", {"strategy": {"enrollmentWindowEndsAt": bog(2026, 11, 10, 9, 0)}}, DENY("N5", ["enrollment_window_over"]),
    ref="T20")
LAD("n5", "enfriamiento hasta el jueves 12:00 → defer a esa hora", {"strategy": {"cooldownUntil": bog(2026, 11, 12, 12, 0)}},
    DEFER("N5", ["cooldown_active"], bog(2026, 11, 12, 12, 0)), ref="T20", basis="decision-fixture", decision="DF-L7")
LAD("n5", "enfriamiento que termina un domingo → defer al lunes 08:00", {"strategy": {"cooldownUntil": bog(2026, 11, 8, 12, 0)}, "now": bog(2026, 11, 7, 10, 0)},
    DEFER("N5", ["cooldown_active"], bog(2026, 11, 9, 8, 0)), ref="T20, D9-7 (solo restringe)", basis="decision-fixture", decision="DF-L3")
LAD("n5", "guarda del paso false → deny", {"strategy": {"stepGuard": "false"}}, DENY("N5", ["step_guard_false"]), ref="D9-6 (guard)")
LAD("n5", "guarda del paso unknown → deny (solo true deja pasar)", {"strategy": {"stepGuard": "unknown"}}, DENY("N5", ["step_guard_false"]), ref="D9-5, D9-6",
    basis="decision-fixture", decision="DF-L7")

# ── N6 · contexto verificado (T21, #4, #15) ─────────────────────────────────
LAD("n6", "renovó entre planificar y despachar → success_reached, sale como converted (#4)", {"context": {"success": "true"}},
    DENY("N6", ["success_reached"], effect="exit_converted"), ref="#4 de §13.1, T21")
LAD("n6", "la audiencia dejó de cumplirse → context_changed (#15)", {"context": {"audience": "false"}},
    DENY("N6", ["context_changed"], effect="cancel_context_changed"), ref="#15 de §13.1, T21")
LAD("n6", "la audiencia pasó a unknown → context_changed", {"context": {"audience": "unknown"}}, DENY("N6", ["context_changed"], effect="cancel_context_changed"),
    ref="D9-5 («solo true»), T21", basis="decision-fixture", decision="DF-L8")
LAD("n6", "apareció una exclusión → context_changed (#15)", {"context": {"exclusions": "true"}}, DENY("N6", ["context_changed"], effect="cancel_context_changed"),
    ref="#15 de §13.1")
LAD("n6", "pidió hablar con una persona hace poco → para y deriva", {"context": {"humanRequestedRecently": True}},
    DENY("N6", ["human_requested_recently"], effect="handoff"), ref="T21, D9-7 N6")
LAD("n6", "comercial con cartera vencida de 16 días (umbral 15) → collections_overdue_blocks_commercial", {"receivable": {"overdueDaysMax": 16}},
    DENY("N6", ["collections_overdue_blocks_commercial"]), ref="P9-7, L9-20", basis="supuesto-lead", decision="DF-L12")
LAD("n6", "comercial con cartera vencida de 15 días justos → no bloquea («más de 15»)", {"receivable": {"overdueDaysMax": 15}}, ALLOW,
    ref="P9-7, L9-20", basis="supuesto-lead", decision="DF-L12")
LAD("n6", "la estrategia endurece el umbral a 7: 10 días ya bloquean", {"receivable": {"overdueDaysMax": 10}, "collectionsBlock": {"strategyDays": 7}},
    DENY("N6", ["collections_overdue_blocks_commercial"]), ref="L9-20 («la estrategia solo puede endurecerlo»)", basis="supuesto-lead", decision="DF-L12")
LAD("n6", "la estrategia intenta aflojar el umbral a 30: 20 días bloquean igual (manda el del servidor)",
    {"receivable": {"overdueDaysMax": 20}, "collectionsBlock": {"strategyDays": 30}}, DENY("N6", ["collections_overdue_blocks_commercial"]),
    ref="L9-20", basis="supuesto-lead", decision="DF-L12")
LAD("n6", "el servidor endurece a 5 (outreach.collections.commercial_block_days): 6 días bloquean",
    {"receivable": {"overdueDaysMax": 6}, "collectionsBlock": {"serverDays": 5}}, DENY("N6", ["collections_overdue_blocks_commercial"]),
    ref="L9-20", basis="supuesto-lead", decision="DF-L12")
LAD("n6", "sin cartera vencida (null) → no bloquea", {"receivable": {"overdueDaysMax": None}}, ALLOW, ref="L9-20", basis="supuesto-lead", decision="DF-L12")
LAD("n6", "la fuente de cartera no se pudo leer → bloquea (el lado seguro)", {"receivable": {"known": False}},
    DENY("N6", ["collections_overdue_blocks_commercial"]), ref="L9-20, D9-5 («solo true»)", basis="decision-fixture", decision="DF-L12")
LAD("n6", "cobranza con cartera vencida de 40 días → la regla no aplica a collections", {"purpose": "collections", "receivable": {"overdueDaysMax": 40}},
    ALLOW, ref="P9-7 (sin contacto COMERCIAL), L9-20", basis="supuesto-lead", decision="DF-L12")
LAD("n6", "service con cartera vencida de 40 días → no aplica", {"purpose": "service", "receivable": {"overdueDaysMax": 40}}, ALLOW, ref="P9-7, L9-20",
    basis="supuesto-lead", decision="DF-L12")
LAD("n6", "otra finalidad ya tiene contacto hoy → defer a mañana", {"context": {"otherPurposeToday": True}},
    DEFER("N6", ["purpose_conflict_today"], bog(2026, 11, 11, 8, 0)), ref="P9-7, D9-8 etapa 3", basis="decision-fixture", decision="DF-L8")
LAD("n6", "otra estrategia de más prioridad hoy → lower_priority, defer a mañana (#5, #12)", {"context": {"lowerPriority": True}},
    DEFER("N6", ["lower_priority"], bog(2026, 11, 11, 8, 0)), ref="#5 y #12 de §13.1", basis="decision-fixture", decision="DF-L8")
LAD("n6", "éxito y persona pedida a la vez: manda el éxito", {"context": {"success": "true", "humanRequestedRecently": True}},
    DENY("N6", ["success_reached", "human_requested_recently"], effect="exit_converted"), ref="D9-7", basis="decision-fixture", decision="DF-L8")
LAD("n6", "se quejó hace poco → para y la inscripción sale (complaint_recent)", {"context": {"complaintRecently": True}},
    DENY("N6", ["complaint_recent"], effect="exit_complaint"), ref="D9-7 N6 (enmienda Q1-3)", basis="supuesto-lead", decision="DF-L14")
LAD("n6", "la fuente de quejas no tiene datos (null) → niega (el lado seguro)", {"context": {"complaintRecently": None}},
    DENY("N6", ["complaint_recent"], effect="exit_complaint"), ref="D9-7 N6 (enmienda Q1-3), L9-26", basis="supuesto-lead", decision="DF-L14")
LAD("n6", "cobranza con una queja reciente → niega también (D3: comercial y cobranza)", {"purpose": "collections", "context": {"complaintRecently": True}},
    DENY("N6", ["complaint_recent"], effect="exit_complaint"), ref="D9-7 N6 (enmienda Q1-3)", basis="supuesto-lead", decision="DF-L14")
LAD("n6", "pidió una persona y se quejó: los dos motivos y manda el efecto handoff", {"context": {"humanRequestedRecently": True, "complaintRecently": True}},
    DENY("N6", ["human_requested_recently", "complaint_recent"], effect="handoff"), ref="D9-7 N6 (enmienda Q1-3)", basis="supuesto-lead", decision="DF-L14")
LAD("n6", "éxito y queja a la vez: manda el éxito", {"context": {"success": "true", "complaintRecently": True}},
    DENY("N6", ["success_reached", "complaint_recent"], effect="exit_converted"), ref="D9-7 N6 (enmienda Q1-3)", basis="supuesto-lead", decision="DF-L14")
LAD("n6", "la audiencia dejó de cumplirse y se quejó: manda cancel_context_changed (va antes en reasonOrder)", {"context": {"audience": "false", "complaintRecently": True}},
    DENY("N6", ["context_changed", "complaint_recent"], effect="cancel_context_changed"), ref="D9-7 N6 (enmienda Q1-3)", basis="supuesto-lead", decision="DF-L14")
LAD("n6", "comercial con queja y cartera vencida de 60 días: los dos motivos, efecto exit_complaint", {"context": {"complaintRecently": True}, "receivable": {"overdueDaysMax": 60}},
    DENY("N6", ["complaint_recent", "collections_overdue_blocks_commercial"], effect="exit_complaint"), ref="D9-7 N6 (enmienda Q1-3), L9-20", basis="supuesto-lead",
    decision="DF-L14")

# ── N7 · instrucciones conversacionales (T22) ───────────────────────────────
LAD("n7", "«llámame el domingo» → defer al primer hueco legal (lunes 08:00), nunca al domingo", {"conversational": {"callbackAt": bog(2026, 11, 15, 11, 0)}},
    DEFER("N7", ["callback_scheduled"], bog(2026, 11, 17, 8, 0)), ref="T22 (y el lunes 16-11 es festivo)", basis="decision-fixture", decision="DF-L9")
LAD("n7", "«escríbeme esta noche a las 21:00» → defer al día siguiente 08:00", {"conversational": {"callbackAt": bog(2026, 11, 10, 21, 0)}},
    DEFER("N7", ["callback_scheduled"], bog(2026, 11, 11, 8, 0)), ref="T22", basis="decision-fixture", decision="DF-L9")
LAD("n7", "«el jueves a las 11:00» → defer a esa hora exacta", {"conversational": {"callbackAt": bog(2026, 11, 12, 11, 0)}},
    DEFER("N7", ["callback_scheduled"], bog(2026, 11, 12, 11, 0)), ref="T22", basis="decision-fixture", decision="DF-L9")
LAD("n7", "el hueco pedido cae después de la ventana de la inscripción → deny enrollment_window_over",
    {"conversational": {"callbackAt": bog(2026, 12, 2, 10, 0)}}, DENY("N7", ["enrollment_window_over"]), ref="D9-7 N7 («si cae fuera, enrollment_window_over»)",
    basis="decision-fixture", decision="DF-L9")
LAD("n7", "una hora pedida que ya pasó se ignora → allow", {"conversational": {"callbackAt": bog(2026, 11, 10, 9, 0)}}, ALLOW, ref="T22",
    basis="decision-fixture", decision="DF-L9")
LAD("n7", "el hueco pedido respeta el horario de la estrategia (miércoles 15:00–17:00)",
    {"conversational": {"callbackAt": bog(2026, 11, 11, 9, 0)}, "strategy": {"schedule": {"days": [1, 2, 3, 4, 5], "windows": [{"from": 600, "to": 1020}]}}},
    DEFER("N7", ["callback_scheduled"], bog(2026, 11, 11, 10, 0)), ref="T22, D9-6", basis="decision-fixture", decision="DF-L3")

# ── precedence: lo que un nivel inferior no levanta (T16–T22, #12) ───────────
LAD("precedence", "sin consentimiento y con una petición de llamada para ahora: manda N1, N7 ni se evalúa", {"consent": {"whatsapp": "none"},
                                                                                                          "conversational": {"callbackAt": bog(2026, 11, 10, 10, 30)}},
    DENY("N1", ["no_consent_for_purpose_channel"]), ref="T22 («ninguna instrucción conversacional levanta una negación de N1–N6»)")
LAD("precedence", "domingo con una petición de llamada para el domingo: N1 decide", {"now": bog(2026, 11, 8, 9, 0),
                                                                                     "conversational": {"callbackAt": bog(2026, 11, 8, 11, 0)}},
    DEFER("N1", ["calendar_sunday"], bog(2026, 11, 9, 8, 0)), ref="T16, T22")
LAD("precedence", "menor con todo lo demás permitido: N1 aunque la estrategia esté publicada y el objetivo vivo", {"subject": {"isMinor": True}},
    DENY("N1", ["subject_minor"]), ref="T16 («ningún nivel inferior lo levanta»)")
LAD("precedence", "freno global y opt-out del hermano: decide N1 (antes que N2)", {"brakes": {"global": True}, "optOut": {"siblingDoNotContact": True}},
    DENY("N1", ["do_not_contact_sibling"]), ref="D9-7 («recorre los niveles en orden»)")
LAD("precedence", "N1 aplaza y N5 tiene un horario más estrecho: retryAt respeta los dos (sábado 15:00 → lunes 10:00)",
    {"now": bog(2026, 11, 7, 15, 0), "strategy": {"schedule": {"days": [1, 2, 3, 4, 5], "windows": [{"from": 600, "to": 720}]}}},
    DEFER("N1", ["calendar_outside_legal_hours"], bog(2026, 11, 9, 10, 0)), ref="#17, D9-7 («solo restringen»)", basis="decision-fixture", decision="DF-L3")
LAD("precedence", "07:30 con horario propio 09:00–18:00: decide la LEY (N1), no el horario propio; retryAt ya respeta el propio (09:00)",
    {"now": bog(2026, 11, 10, 7, 30), "ownHours": {"weekdays": [540, 1080], "saturday": [540, 780]}}, DEFER("N1", ["calendar_outside_legal_hours"], bog(2026, 11, 10, 9, 0)),
    ref="L9-15 («la traza distingue la ley de la política propia»), DF-L3", basis="supuesto-lead", decision="DF-L11")
LAD("precedence", "cartera vencida y persona pedida a la vez: decide N6 con los dos y el efecto handoff",
    {"receivable": {"overdueDaysMax": 60}, "context": {"humanRequestedRecently": True}},
    DENY("N6", ["human_requested_recently", "collections_overdue_blocks_commercial"], effect="handoff"), ref="D9-7 N6, L9-20", basis="decision-fixture",
    decision="DF-L12")
LAD("precedence", "se quejó y no tiene autorización: decide N1 y N6 ni se evalúa", {"consent": {"whatsapp": "none"}, "context": {"complaintRecently": True}},
    DENY("N1", ["no_consent_for_purpose_channel"]), ref="D9-7 («el primer nivel que niega decide»; enmienda Q1-3)", basis="supuesto-lead", decision="DF-L14")
LAD("precedence", "se quejó un domingo: N1 aplaza al lunes (N6 decidirá entonces)", {"now": bog(2026, 11, 8, 10, 0), "context": {"complaintRecently": True}},
    DEFER("N1", ["calendar_sunday"], bog(2026, 11, 9, 8, 0)), ref="D9-7 (enmienda Q1-3), #17", basis="supuesto-lead", decision="DF-L14")
LAD("precedence", "se quejó con la PARADA GLOBAL echada: N2 retiene antes que N6", {"brakes": {"global": True}, "context": {"complaintRecently": True}},
    DENY("N2", ["outreach_brake_global"], waitsForRelease=True), ref="D9-8 (PARADA GLOBAL; enmienda Q1-3)", basis="supuesto-lead", decision="DF-L14")
LAD("precedence", "se quejó y pidió que lo llamen ya: N6 niega y N7 ni se evalúa", {"context": {"complaintRecently": True},
                                                                                  "conversational": {"callbackAt": bog(2026, 11, 10, 11, 0)}},
    DENY("N6", ["complaint_recent"], effect="exit_complaint"), ref="T22 («ninguna instrucción conversacional levanta una negación de N1–N6»; enmienda Q1-3)",
    basis="supuesto-lead", decision="DF-L14")
LAD("precedence", "tope de contactos alcanzado aunque N7 pida otro contacto ya: N5 niega", {"strategy": {"touches": 6},
                                                                                           "conversational": {"callbackAt": bog(2026, 11, 10, 11, 0)}},
    DENY("N5", ["max_touches_reached"]), ref="T20 («aunque N6 o N7 quieran más contactos»)")
LAD("precedence", "dos reglas contradictorias: la de menos prioridad aplaza por N6 y nunca hay dos contactos el mismo día (#12)",
    {"context": {"lowerPriority": True}, "history": [H(bog(2026, 11, 10, 8, 15), source="outreach")]},
    DEFER("N1", ["daily_contact_limit", "min_hours_between_contacts"], bog(2026, 11, 12, 8, 15)), ref="#12 de §13.1 (gana la escalera)")

# ── shadow ──────────────────────────────────────────────────────────────────
SH = {"flags": {"mode": "shadow"}}
LAD("shadow", "plantilla de WhatsApp en sombra el 15-11: constante cerrada, canal apagado y sin presupuesto → allow con avisos",
    deep_merge(SH, {"channel": "whatsapp_template", "flags": {"channelEnabled": False, "channelLive": False}}),
    dict(ALLOW, warnings=[{"level": "N4", "reason": "sends_disabled_in_code"}, {"level": "N4", "reason": "channel_disabled"},
                          {"level": "N4", "reason": "channel_not_live"}, {"level": "N4", "reason": "budget_zero"}]),
    ref="D9-8 (sombra: N4 avisa)", basis="decision-fixture", decision="DF-L5")
LAD("shadow", "en sombra la plantilla sin aprobar sigue negando", deep_merge(SH, {"step": {"templateApproved": False}}), DENY("N4", ["template_not_approved"]),
    ref="D9-8 («todo lo demás se aplica igual»)", basis="decision-fixture", decision="DF-L5")
LAD("shadow", "en sombra el consentimiento se aplica igual", deep_merge(SH, {"consent": {"whatsapp": "none"}}), DENY("N1", ["no_consent_for_purpose_channel"]),
    ref="D9-8")
LAD("shadow", "en sombra el canario no limita: se anota would_scope (aviso) y allow", deep_merge(SH, {"strategy": {"part": "canary", "inCanaryScope": False}}),
    dict(ALLOW, warnings=[{"level": "N5", "reason": "canary_scope_mismatch"}]), ref="D9-11.6 («en sombra se evalúa la audiencia entera»)",
    basis="decision-fixture", decision="DF-L7")
LAD("shadow", "en sombra «solo canario» (governance.canaryOnly) tampoco limita: se anota en N3 y en N5 (would_scope) y allow",
    deep_merge(SH, {"studio": {"canaryOnly": True}, "strategy": {"part": "canary", "inCanaryScope": False}}),
    dict(ALLOW, warnings=[{"level": "N3", "reason": "canary_only_declared"}, {"level": "N5", "reason": "canary_scope_mismatch"}]),
    ref="D9-11.6 («en sombra se evalúa la audiencia entera y se anota would_scope»)", decision="DF-L7")
LAD("shadow", "plantilla de WhatsApp en sombra, «solo canario» y la persona sin alcance canario conocido: el aviso de N3 y los de N4 → allow",
    deep_merge(SH, {"channel": "whatsapp_template", "studio": {"canaryOnly": True}, "strategy": {"inCanaryScope": None}}),
    dict(ALLOW, warnings=[{"level": "N3", "reason": "canary_only_declared"}, {"level": "N4", "reason": "sends_disabled_in_code"},
                          {"level": "N4", "reason": "budget_zero"}]),
    ref="D9-11.6, D9-8 (sombra: N4 avisa)", decision="DF-L7")
LAD("shadow", "en sombra con «solo canario» la persona canaria no deja aviso", deep_merge(SH, {"studio": {"canaryOnly": True}, "strategy": {"part": "canary"}}),
    dict(ALLOW, warnings=[]), ref="D9-11.6", decision="DF-L7")
LAD("shadow", "en sombra con «solo canario» el consentimiento sigue negando antes (N1) y N3 ni se evalúa",
    deep_merge(SH, {"consent": {"whatsapp": "none"}, "studio": {"canaryOnly": True}, "strategy": {"inCanaryScope": False}}),
    DENY("N1", ["no_consent_for_purpose_channel"], warnings=[]), ref="D9-7 («el primer nivel que niega decide»), D9-11.6", decision="DF-L7")
LAD("shadow", "en sombra con «solo canario» el freno global sigue reteniendo antes (N2) y N3 ni se evalúa",
    deep_merge(SH, {"brakes": {"global": True}, "studio": {"canaryOnly": True}, "strategy": {"inCanaryScope": False}}),
    DENY("N2", ["outreach_brake_global"], waitsForRelease=True, warnings=[]), ref="D9-8 (PARADA GLOBAL), D9-11.6", decision="DF-L7")
LAD("shadow", "en sombra con «solo canario» lo demás de N3 sigue negando: la referencia del Studio que no cuadra → deny N3 sin el canario entre los motivos",
    deep_merge(SH, {"studio": {"canaryOnly": True, "referenceValid": False}, "strategy": {"inCanaryScope": False}}),
    DENY("N3", ["studio_reference_invalid"], warnings=[{"level": "N3", "reason": "canary_only_declared"}]), ref="D9-7 N3, D9-11.6", decision="DF-L7")
LAD("shadow", "en sombra la autonomía del motor encendida es aviso", deep_merge(SH, {"flags": {"legacyAutonomy": True}}),
    dict(ALLOW, warnings=[{"level": "N2", "reason": "legacy_autonomy_on"}]), ref="D9-1 (la regla es para live)", basis="decision-fixture", decision="DF-L4")
LAD("shadow", "en sombra el freno global retiene igual", deep_merge(SH, {"brakes": {"global": True}}), DENY("N2", ["outreach_brake_global"], waitsForRelease=True),
    ref="D9-8 (PARADA GLOBAL en las etapas 1, 4 y 6)")
LAD("shadow", "en sombra el calendario aplaza igual", deep_merge(SH, {"now": bog(2026, 11, 8, 10, 0)}), DEFER("N1", ["calendar_sunday"], bog(2026, 11, 9, 8, 0)),
    ref="D9-8")
LAD("shadow", "en sombra la queja reciente NIEGA igual que en vivo: no es un aviso", deep_merge(SH, {"context": {"complaintRecently": True}}),
    DENY("N6", ["complaint_recent"], effect="exit_complaint", warnings=[]), ref="D9-8 («todo lo demás se aplica igual»; enmienda Q1-3)", basis="supuesto-lead",
    decision="DF-L14")
LAD("shadow", "en sombra, «solo canario» y fuera del alcance: los avisos de N3 y N5 se anotan y la queja niega en N6",
    deep_merge(SH, {"studio": {"canaryOnly": True}, "strategy": {"part": "canary", "inCanaryScope": False}, "context": {"complaintRecently": True}}),
    DENY("N6", ["complaint_recent"], effect="exit_complaint", warnings=[{"level": "N3", "reason": "canary_only_declared"}, {"level": "N5", "reason": "canary_scope_mismatch"}]),
    ref="D9-11.6, D9-8 (enmienda Q1-3)", basis="supuesto-lead", decision="DF-L14")

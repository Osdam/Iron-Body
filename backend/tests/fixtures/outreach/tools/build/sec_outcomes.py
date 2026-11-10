# outcome-transitions.json · OutcomeTable (D9-10; §13.1 #10, #21, #22; §13.2 T14, T23) — el «outcome-cases.json» del encargo
#   table    · los 18 resultados: grupo, fuentes, transiciones permitidas, la de por defecto (*), efectos
#   matrix   · 18 × 6: ¿puede el `on` de una estrategia llevar este resultado a esta transición? (lo que valida StrategyContent)
#   resolve  · (resultado, fuente, confianza, on de la estrategia, intento) → apply | needs_review | ignore, transición, estado, efectos
OUTCOME_TABLE = {
    "blocked": {"group": "delivery", "sources": ["system"], "allowed": ["end_exit", "wait"], "default": "wait", "effects": []},
    "not_attempted": {"group": "delivery", "sources": ["simulated"], "allowed": [], "default": None, "effects": []},
    "failed": {"group": "delivery", "sources": ["meta_status", "system", "voice_call"], "allowed": ["end_exit", "next_step", "retry_step"], "default": "end_exit",
               "effects": []},
    "ambiguous": {"group": "delivery", "sources": ["system"], "allowed": [], "default": None, "effects": ["reconcile"]},
    "sent": {"group": "delivery", "sources": ["meta_status", "voice_call"], "allowed": [], "default": None, "effects": []},
    "delivered": {"group": "delivery", "sources": ["meta_status"], "allowed": [], "default": None, "effects": []},
    "read": {"group": "delivery", "sources": ["meta_status"], "allowed": [], "default": None, "effects": []},
    "no_answer": {"group": "delivery", "sources": ["staff", "voice_call"], "allowed": ["end_exit", "next_step", "retry_step", "wait"], "default": "end_exit",
                  "effects": []},
    "answered": {"group": "response", "sources": ["staff", "voice_call"], "allowed": ["end_exit", "end_success", "handoff_human", "next_step"],
                 "default": "end_exit", "effects": []},
    "replied": {"group": "response", "sources": ["inbound_message"], "allowed": ["end_exit", "handoff_human"], "default": "handoff_human", "effects": []},
    "callback_requested": {"group": "response", "sources": ["classifier", "staff"], "allowed": ["end_exit", "wait"], "default": "wait", "effects": []},
    "human_requested": {"group": "response", "sources": ["classifier", "inbound_message", "staff", "voice_call"], "allowed": ["handoff_human"],
                        "default": "handoff_human", "effects": ["assisted_task"]},
    "not_interested": {"group": "response", "sources": ["classifier", "staff"], "allowed": ["end_exit"], "default": "end_exit", "effects": ["cooldown"]},
    "wrong_person": {"group": "response", "sources": ["classifier", "staff", "voice_call"], "allowed": ["end_exit"], "default": "end_exit",
                     "effects": ["suppress_channel"]},
    "opted_out": {"group": "response", "sources": ["classifier", "inbound_message", "staff", "voice_call"], "allowed": ["end_exit"], "default": "end_exit",
                  "effects": ["cancel_pending_all_strategies", "suppress_purpose_channel"]},
    "appointment_booked": {"group": "business", "sources": ["crm_fact", "staff"], "allowed": ["end_success", "next_step"], "default": "end_success",
                           "effects": []},
    "converted": {"group": "business", "sources": ["crm_fact", "system"], "allowed": ["end_success"], "default": "end_success", "effects": []},
    "no_response": {"group": "business", "sources": ["system"], "allowed": ["end_exit"], "default": "end_exit", "effects": []},
}
assert sorted(OUTCOME_TABLE) == OUTCOME_CODES and all(OUTCOME_TABLE[o]["group"] == g for o, g in OUTCOMES)
for _o, _t in OUTCOME_TABLE.items():
    assert _t["default"] is None or _t["default"] in _t["allowed"], _o
SOURCES = sorted(["simulated", "staff", "crm_fact", "system", "meta_status", "inbound_message", "voice_call", "classifier"])
SOURCES_15_11 = ["crm_fact", "simulated", "staff", "system"]
CLASSIFIER_SAFE = ["end_exit", "handoff_human", "wait"]
THRESHOLDS = {"low": 50, "high": 90}
RETRY_MAX = {"failed": 1, "no_answer": 2}
STATUS_BY_TRANSITION = {"next_step": "active", "retry_step": "active", "wait": "waiting", "end_success": "completed", "end_exit": "exited",
                        "handoff_human": "handed_off"}


def resolve_ref(outcome, source, confidence=None, on=None, attempt=1):
    if source == "classifier" and outcome == "ambiguous":
        return {"verdict": "needs_review", "outcome": None, "transition": "handoff_human", "enrollmentStatus": "handed_off", "effects": ["assisted_task"],
                "incident": False}
    t = OUTCOME_TABLE.get(outcome)
    if t is None or source not in SOURCES or source not in t["sources"]:
        return {"verdict": "ignore", "outcome": None, "transition": None, "enrollmentStatus": None, "effects": [], "incident": True}
    if source == "classifier":
        need = THRESHOLDS["low"] if outcome == "opted_out" else THRESHOLDS["high"]
        if not isinstance(confidence, int) or confidence < need:
            return {"verdict": "needs_review", "outcome": None, "transition": "handoff_human", "enrollmentStatus": "handed_off", "effects": ["assisted_task"],
                    "incident": False}
    if not t["allowed"]:
        return {"verdict": "apply", "outcome": outcome, "transition": None, "enrollmentStatus": None, "effects": list(t["effects"]), "incident": False}
    choice = (on or {}).get(outcome, t["default"])
    incident = False
    if choice not in t["allowed"]:
        choice, incident = t["default"], True
    if source == "classifier" and choice not in CLASSIFIER_SAFE:
        choice = t["default"] if t["default"] in CLASSIFIER_SAFE else "end_exit"
    if choice == "retry_step" and attempt > RETRY_MAX.get(outcome, 0):
        choice = t["default"]
    status = STATUS_BY_TRANSITION[choice]
    if outcome == "opted_out":
        status = "opted_out"
    elif outcome == "no_response":
        status = "expired"
    return {"verdict": "apply", "outcome": outcome, "transition": choice, "enrollmentStatus": status, "effects": list(t["effects"]), "incident": incident}


OT = Fixture("outcome-transitions.json", header(
    "outcome-transitions.json",
    ["D9-10 (18 resultados normalizados, transiciones permitidas por resultado y la de por defecto; la regla asimétrica de la IA; estados de la inscripción)",
     "D9-8 etapas 8 y 9 (resultado y seguimiento)", "§6.2 (outreach_outcomes.source: 8 fuentes; confidence solo con classifier)",
     "§13.1 #10 (opted_out siempre end_exit), #21 (clasificación ambigua → needs_review), #22 (revisión humana → bandeja asistida y para la secuencia); §13.2 T14, T23"],
    [SOURCE_CONTRACT],
    namingNote="El encargo de TEST9-a lo llama outcome-cases.json; el contrato (D9-10 y §11) lo llama outcome-transitions.json: se entrega con el nombre del contrato (L9-8).",
    sources15_11=SOURCES_15_11,
    allSources=SOURCES,
    classifierSafeTransitions=CLASSIFIER_SAFE,
    classifierThresholds=THRESHOLDS,
    retryMax=RETRY_MAX,
    statusByTransition=STATUS_BY_TRANSITION,
    enrollmentStatuses=["active", "waiting", "completed", "exited", "opted_out", "handed_off", "expired", "canceled"],
    interface={"resolve": "OutcomeTable::resolve(outcome, source, confidence|null, on {resultado: transición}|null, attempt) → {verdict: apply|needs_review|ignore, outcome|null, transition|null, enrollmentStatus|null, effects, incident}"},
    decisions={
        "DF-O1": "(Ratificada por el lead: L9-12: un error del proveedor es failed, con el detalle en la traza.) Fuentes de cada resultado, traducidas a los 8 códigos de outreach_outcomes.source: «política» → system; «canal» → system el 15-11 (failed_to_start, ambiguous), meta_status en W9 y voice_call en V9; «asistido» → staff; «W9 entrante/opt-out del lead» → inbound_message; «success reevaluado» y «ventana vencida» → system.",
        "DF-O2": "Un código fuera de los 18, o una fuente que ese resultado no admite, se ignora y deja incidente (T14); la secuencia no avanza.",
        "DF-O3": "Clasificador (AI9): confidence entera de 0 a 100; opted_out se acepta desde 50 (umbral bajo) y el resto desde 90 (umbral alto); por debajo, o con la etiqueta ambiguous, needs_review: sin resultado del conjunto, la secuencia para y se abre una tarea asistida (transition handoff_human, #21 y #22). Ratificada por el lead: L9-12 (needs_review es un veredicto, no un resultado) y L9-18 (umbrales 50 y 90, solo AI9).",
        "DF-O4": "Con source classifier la transición elegida solo puede ser end_exit, handoff_human o wait; si la estrategia o el defecto dicen otra, se usa el defecto si es una de esas y, si no, end_exit.",
        "DF-O5": "Si el `on` de la estrategia pide una transición que ese resultado no admite (la validación ya debería haberla rechazado), rige la de por defecto y queda incidente. opted_out siempre end_exit, diga lo que diga la estrategia (#10).",
        "DF-O6": "retry_step cuenta intentos: failed admite 1 reintento (attempt ≤ 2) y no_answer 2 (attempt ≤ 3); agotados, rige la de por defecto.",
        "DF-O7": "Estado de la inscripción: el de la transición (statusByTransition), salvo opted_out → opted_out y no_response → expired (D9-8: la ventana vencida cierra como expired con no_response). Los resultados sin transición (not_attempted, ambiguous, sent, delivered, read) no cambian el estado.",
        "DF-O8": "Efectos: opted_out → cancelar los trabajos pendientes de TODAS las estrategias y suprimir finalidad y canal (#10); wrong_person → suprimir ese canal; not_interested → enfriamiento; human_requested y needs_review → tarea asistida; ambiguous → conciliar.",
    },
))
OT.extra["table"] = {o: OUTCOME_TABLE[o] for o in OUTCOME_CODES}

for o in OUTCOME_CODES:
    t = OUTCOME_TABLE[o]
    for tr in FOLLOWUP_TRANSITIONS:
        ok = tr in t["allowed"]
        OT.add("matrix", "on", f"{o} → {tr}: {'permitida' if ok else 'no'}", "D9-10 (tabla)", "contrato", {"outcome": o, "transition": tr},
               {"allowed": ok, "isDefault": t["default"] == tr, "error": None if ok else "transition_not_allowed"})


def RES(desc, outcome, source, expected, *, confidence=None, on=None, attempt=1, ref="D9-10", basis="contrato", decision=None):
    got = resolve_ref(outcome, source, confidence, on, attempt)
    for k, v in expected.items():
        assert got[k] == v, (desc, k, got[k], v)
    OT.add("resolve", "outcome", desc, ref, basis, {"outcome": outcome, "source": source, "confidence": confidence, "on": on, "attempt": attempt}, got,
           decision=decision)


# Las cuatro fuentes del 15-11 (T14)
RES("simulated → not_attempted, sin transición (la inscripción espera su ventana)", "not_attempted", "simulated", {"verdict": "apply", "transition": None},
    ref="T14, D9-8 (sombra)")
RES("staff → answered, por defecto end_exit", "answered", "staff", {"verdict": "apply", "transition": "end_exit", "enrollmentStatus": "exited"}, ref="T14")
RES("staff → answered con on.answered = next_step", "answered", "staff", {"transition": "next_step", "enrollmentStatus": "active"}, on={"answered": "next_step"},
    ref="T14, T23")
RES("staff → no_answer sin regla: end_exit (no avanza solo, T23)", "no_answer", "staff", {"transition": "end_exit"}, ref="T23 («solo si on.no_answer lo dice»)")
RES("staff → no_answer con on.no_answer = next_step: avanza (T23)", "no_answer", "staff", {"transition": "next_step"}, on={"no_answer": "next_step"}, ref="T23")
RES("staff → no_answer con retry_step en el intento 2: reintenta", "no_answer", "staff", {"transition": "retry_step"}, on={"no_answer": "retry_step"}, attempt=2,
    basis="decision-fixture", decision="DF-O6")
RES("staff → no_answer con retry_step en el intento 3: agotado, rige el defecto (end_exit)", "no_answer", "staff", {"transition": "end_exit"},
    on={"no_answer": "retry_step"}, attempt=3, basis="decision-fixture", decision="DF-O6")
RES("system → failed con retry_step en el intento 1: reintenta (≤ 1)", "failed", "system", {"transition": "retry_step"}, on={"failed": "retry_step"}, attempt=1,
    basis="decision-fixture", decision="DF-O6")
RES("system → failed con retry_step en el intento 2: agotado → end_exit", "failed", "system", {"transition": "end_exit"}, on={"failed": "retry_step"}, attempt=2,
    basis="decision-fixture", decision="DF-O6")
RES("staff → callback_requested: por defecto wait", "callback_requested", "staff", {"transition": "wait", "enrollmentStatus": "waiting"})
RES("staff → human_requested: handoff_human (la única) y tarea asistida (#22)", "human_requested", "staff",
    {"transition": "handoff_human", "enrollmentStatus": "handed_off", "effects": ["assisted_task"]}, ref="#22 de §13.1")
RES("staff → not_interested: end_exit con enfriamiento", "not_interested", "staff", {"transition": "end_exit", "effects": ["cooldown"]})
RES("staff → wrong_person: end_exit y supresión del canal", "wrong_person", "staff", {"transition": "end_exit", "effects": ["suppress_channel"]})
RES("staff → opted_out: end_exit, estado opted_out, cancela todo y suprime (#10)", "opted_out", "staff",
    {"transition": "end_exit", "enrollmentStatus": "opted_out", "effects": ["cancel_pending_all_strategies", "suppress_purpose_channel"]}, ref="#10 de §13.1",
    basis="decision-fixture", decision="DF-O8")
RES("opted_out con on.opted_out = next_step: se ignora la estrategia (end_exit) e incidente (#10)", "opted_out", "staff",
    {"transition": "end_exit", "enrollmentStatus": "opted_out", "incident": True}, on={"opted_out": "next_step"}, ref="#10 («diga lo que diga la estrategia»)",
    basis="decision-fixture", decision="DF-O5")
RES("crm_fact → appointment_booked: end_success", "appointment_booked", "crm_fact", {"transition": "end_success", "enrollmentStatus": "completed"})
RES("crm_fact → converted: end_success", "converted", "crm_fact", {"transition": "end_success", "enrollmentStatus": "completed"})
RES("system → converted (success reevaluado): end_success (#4)", "converted", "system", {"transition": "end_success"}, ref="#4 de §13.1")
RES("system → no_response: end_exit y estado expired", "no_response", "system", {"transition": "end_exit", "enrollmentStatus": "expired"},
    basis="decision-fixture", decision="DF-O7")
RES("system → blocked: wait por defecto", "blocked", "system", {"transition": "wait"})
RES("system → ambiguous: sin transición automática; se concilia", "ambiguous", "system", {"transition": None, "effects": ["reconcile"]})
RES("staff no puede producir converted → ignore e incidente (T14)", "converted", "staff", {"verdict": "ignore", "incident": True}, ref="T14",
    basis="decision-fixture", decision="DF-O2")
RES("simulated no puede producir answered → ignore", "answered", "simulated", {"verdict": "ignore", "incident": True}, ref="T14", basis="decision-fixture",
    decision="DF-O2")
RES("un código desconocido (maybe_later) → ignore e incidente (T14)", "maybe_later", "staff", {"verdict": "ignore", "incident": True}, ref="T14",
    basis="decision-fixture", decision="DF-O2")
RES("una fuente desconocida (n8n) → ignore", "answered", "n8n", {"verdict": "ignore", "incident": True}, ref="T14, §6.2", basis="decision-fixture", decision="DF-O2")
RES("staff → replied: replied solo lo produce inbound_message → ignore", "replied", "staff", {"verdict": "ignore"}, basis="decision-fixture", decision="DF-O1")
RES("on con una transición no permitida (answered → wait): rige el defecto e incidente", "answered", "staff", {"transition": "end_exit", "incident": True},
    on={"answered": "wait"}, basis="decision-fixture", decision="DF-O5")
# W9/V9 (fuentes que el 15-11 no existen; la tabla las fija igual)
RES("W9: inbound_message → replied: handoff_human (nunca otro contacto automático)", "replied", "inbound_message", {"transition": "handoff_human"})
RES("W9: replied con on.replied = end_exit", "replied", "inbound_message", {"transition": "end_exit"}, on={"replied": "end_exit"})
RES("W9: meta_status → delivered: no terminal", "delivered", "meta_status", {"verdict": "apply", "transition": None})
RES("V9: voice_call → no_answer (buzón): end_exit por defecto", "no_answer", "voice_call", {"transition": "end_exit"})
# Clasificador (AI9, apagado): la regla asimétrica (#21, P9-6)
RES("clasificador: etiqueta ambiguous → needs_review, para y tarea asistida (#21)", "ambiguous", "classifier",
    {"verdict": "needs_review", "transition": "handoff_human", "effects": ["assisted_task"]}, confidence=99, ref="#21 de §13.1, P9-6", basis="decision-fixture",
    decision="DF-O3")
RES("clasificador: opted_out con 55 (umbral bajo) → se acepta", "opted_out", "classifier", {"verdict": "apply", "transition": "end_exit"}, confidence=55,
    ref="D9-10 («un opted_out del clasificador se acepta con un umbral bajo»)", basis="decision-fixture", decision="DF-O3")
RES("clasificador: opted_out con 49 → needs_review", "opted_out", "classifier", {"verdict": "needs_review"}, confidence=49, basis="decision-fixture",
    decision="DF-O3")
RES("clasificador: not_interested con 89 (por debajo del alto) → needs_review", "not_interested", "classifier", {"verdict": "needs_review"}, confidence=89,
    basis="decision-fixture", decision="DF-O3")
RES("clasificador: not_interested con 90 → end_exit", "not_interested", "classifier", {"verdict": "apply", "transition": "end_exit"}, confidence=90,
    basis="decision-fixture", decision="DF-O3")
RES("clasificador: callback_requested con la estrategia pidiendo wait → wait (permitida a la IA)", "callback_requested", "classifier", {"transition": "wait"},
    confidence=95, on={"callback_requested": "wait"}, ref="D9-10 (regla asimétrica)", decision="DF-O4")
RES("clasificador: sin confidence → needs_review", "callback_requested", "classifier", {"verdict": "needs_review"}, confidence=None, basis="decision-fixture",
    decision="DF-O3")
RES("clasificador: human_requested con 95 → handoff_human", "human_requested", "classifier", {"transition": "handoff_human"}, confidence=95)
RES("clasificador: no puede producir answered (no es fuente) → ignore", "answered", "classifier", {"verdict": "ignore"}, confidence=99, basis="decision-fixture",
    decision="DF-O2")
RES("clasificador: callback_requested con on = next_step (ni la tabla ni la regla asimétrica lo permiten) → wait e incidente", "callback_requested", "classifier",
    {"transition": "wait", "incident": True}, confidence=95, on={"callback_requested": "next_step"}, ref="D9-10 (regla asimétrica, P9-6)",
    basis="decision-fixture", decision="DF-O5")

# followup-cases.json · FollowUpMachine (D9-8 etapa 9; D9-10; §13.2 T23; el encargo: «no_answer solo con regla, aplazamientos legales»)
#   apply · la inscripción + los pasos + un resultado (que resuelve OutcomeTable, outcome-transitions.json) + now
#           → estado de la inscripción, paso, intento y el SIGUIENTE trabajo (paso, intento, notBefore) o ninguno
# notBefore = el primer hueco permitido (calendar-cases.json §nextSlot, con el horario propio y el de la estrategia) a partir del más tardío
# entre el retraso mínimo del paso que se planifica, la espera pedida (wait) y la hora que pidió la persona: la máquina nunca acorta.
DUR_HOURS = {"hours": 1, "days": 24, "weeks": 168}


def followup_ref(enr, steps, outcome, source, now, *, on=None, confidence=None, callback_at=None, wait=None, max_touches=6, own=None, schedule=None):
    res = resolve_ref(outcome, source, confidence, on, enr["attempt"])
    out = {"resolution": {"verdict": res["verdict"], "transition": res["transition"]}, "status": enr["status"], "stepIndex": enr["stepIndex"],
           "attempt": enr["attempt"], "nextJob": None, "reason": None}
    if res["verdict"] == "ignore":
        out["reason"] = "outcome_ignored"
        return out
    tr = res["transition"]
    if tr is None:
        out["reason"] = "non_terminal"
        return out
    if res["enrollmentStatus"] in ("completed", "exited", "opted_out", "handed_off", "expired"):
        out["status"] = res["enrollmentStatus"]
        out["reason"] = "closed"
        return out
    if enr["touches"] >= max_touches:
        out["status"] = "exited"
        out["reason"] = "max_touches_reached"
        return out
    if tr == "next_step":
        idx, att = enr["stepIndex"] + 1, 1
    else:                                   # retry_step o wait: el mismo paso, intento + 1 (una fila nueva, D9-8)
        idx, att = enr["stepIndex"], enr["attempt"] + 1
    if idx >= len(steps):
        out.update({"status": "waiting", "stepIndex": enr["stepIndex"], "reason": "sequence_done_waits_window"})
        return out
    d = steps[idx]["delay"]
    base = enr["enrolledAt"] if d["from"] == "enrollment" else now
    cands = [plus_seconds(base, d["n"] * DUR_HOURS[d["unit"]] * 3600), now]
    if tr == "wait" and wait is not None:
        cands.append(plus_seconds(now, wait["n"] * DUR_HOURS[wait["unit"]] * 3600))
    if callback_at is not None:
        cands.append(callback_at)
    nb = next_slot(max(cands, key=lambda s: parse_iso(s)), own, schedule)
    if parse_iso(nb) >= parse_iso(enr["windowEndsAt"]):
        out.update({"status": "waiting", "reason": "window_over_no_job"})
        return out
    out.update({"status": "waiting" if tr == "wait" else "active", "stepIndex": idx, "attempt": att,
                "nextJob": {"stepIndex": idx, "attempt": att, "notBefore": nb}, "reason": tr})
    return out


FU = Fixture("followup-cases.json", header(
    "followup-cases.json",
    ["D9-8 etapa 9 (FollowUpMachine pura: aplica el `on` del paso dentro de la tabla de D9-10; un reintento de negocio es una fila nueva, attempt + 1)",
     "D9-10 (transiciones next_step, retry_step ≤ 2, wait {n, unit ≤ 14 días}, end_success, end_exit, handoff_human; estados solo hacia delante)",
     "D9-6 (delay: un intervalo desde la inscripción o desde el resultado anterior; limits: ≤ 6 contactos, ventana ≤ 60 días)",
     "§13.2 T23 (no_answer → next_step solo si on.no_answer lo dice; un resultado desconocido no avanza; nunca acorta un intervalo mínimo)", "§13.1 #17 y T22"],
    [SOURCE_CONTRACT, {"what": "La resolución de cada resultado", "url": "tests/fixtures/outreach/outcome-transitions.json (resolve)", "consulted": "09-11-2026",
                       "how": "Cada caso resuelve primero el resultado con la misma referencia y después aplica la máquina."}],
    interface={"apply": "FollowUpMachine::apply(enrollment {status, stepIndex, attempt, touches, enrolledAt, windowEndsAt}, steps [{id, delay {n, unit, from}}], resolution (OutcomeTable::resolve), now, extras {waitFor, callbackAt, maxTouches, ownHours, schedule}) → {status, stepIndex, attempt, nextJob {stepIndex, attempt, notBefore} | null, reason}"},
    decisions={
        "DF-F1": "next_step planifica el paso siguiente con intento 1; retry_step y wait planifican el MISMO paso con intento + 1 (una fila nueva); wait deja la inscripción en waiting y el resto en active.",
        "DF-F2": "notBefore = primer hueco permitido desde el más tardío entre now, base + delay del paso planificado (base = enrolledAt si from = enrollment, now si from = previous), now + la espera pedida y la hora que pidió la persona. Duraciones exactas (1 día = 24 h). Nunca antes: la máquina no acorta el retraso mínimo (T23).",
        "DF-F3": "Si no quedan pasos, la inscripción espera su ventana sin trabajo (D9-8: después vence con no_response); si el notBefore cae en o después del fin de la ventana, tampoco hay trabajo.",
        "DF-F4": "Con touches ≥ el tope de contactos (6), no se planifica nada y la inscripción sale (exited, max_touches_reached), aunque el resultado pidiera seguir.",
        "DF-F5": "Un resultado ignorado (outcome_ignored) o sin transición (non_terminal: not_attempted, ambiguous, sent, delivered, read) no cambia nada.",
    },
))
STEPS = [{"id": "s1", "delay": {"n": 0, "unit": "hours", "from": "enrollment"}}, {"id": "s2", "delay": {"n": 2, "unit": "days", "from": "previous"}},
         {"id": "s3", "delay": {"n": 1, "unit": "weeks", "from": "enrollment"}}]
ENR = {"status": "active", "stepIndex": 0, "attempt": 1, "touches": 1, "enrolledAt": bog(2026, 11, 9, 10, 0), "windowEndsAt": bog(2026, 11, 30, 0, 0)}
NOW = bog(2026, 11, 10, 10, 0)


def FUP(desc, outcome, source, expected, *, enr=None, steps=STEPS, now=NOW, ref="D9-8 etapa 9, D9-10", basis="contrato", decision=None, **kw):
    e = dict(ENR, **(enr or {}))
    got = followup_ref(e, steps, outcome, source, now, **kw)
    for k, v in expected.items():
        assert got[k] == v, (desc, k, got[k], v)
    fields = {"enrollment": e, "steps": steps, "outcome": outcome, "source": source, "now": now}
    for k in ("on", "confidence", "callback_at", "wait", "max_touches", "own", "schedule"):
        if k in kw:
            fields[{"callback_at": "callbackAt", "wait": "waitFor", "max_touches": "maxTouches", "own": "ownHours"}.get(k, k)] = kw[k]
    FU.add("apply", "machine", desc, ref, basis, fields, got, decision=decision)


FUP("no_answer SIN regla: no avanza (end_exit) (T23)", "no_answer", "staff", {"status": "exited", "nextJob": None}, ref="T23")
FUP("no_answer con on.no_answer = next_step: paso 2 dentro de 2 días (jueves 10:00)", "no_answer", "staff",
    {"status": "active", "nextJob": {"stepIndex": 1, "attempt": 1, "notBefore": bog(2026, 11, 12, 10, 0)}}, on={"no_answer": "next_step"}, ref="T23",
    basis="decision-fixture", decision="DF-F2")
FUP("no_answer con retry_step: el mismo paso, intento 2 (fila nueva)", "no_answer", "staff",
    {"status": "active", "nextJob": {"stepIndex": 0, "attempt": 2, "notBefore": NOW}}, on={"no_answer": "retry_step"}, basis="decision-fixture",
    decision="DF-F1")
FUP("un resultado desconocido no avanza (T23)", "maybe_later", "staff", {"status": "active", "stepIndex": 0, "nextJob": None, "reason": "outcome_ignored"},
    ref="T23", basis="decision-fixture", decision="DF-F5")
FUP("not_attempted en sombra: no cambia nada", "not_attempted", "simulated", {"status": "active", "nextJob": None, "reason": "non_terminal"},
    basis="decision-fixture", decision="DF-F5")
FUP("callback_requested (wait) para el domingo: el mismo paso, intento 2, el martes 17-11 08:00 (aplazamiento legal; el lunes es festivo)", "callback_requested",
    "staff", {"status": "waiting", "nextJob": {"stepIndex": 0, "attempt": 2, "notBefore": bog(2026, 11, 17, 8, 0)}}, callback_at=bog(2026, 11, 15, 11, 0),
    ref="T22, T23", basis="decision-fixture", decision="DF-F2")
FUP("la persona pide que la llamen en 1 hora pero el paso exige 2 días: manda el paso (nunca acorta, T23)", "answered", "staff",
    {"nextJob": {"stepIndex": 1, "attempt": 1, "notBefore": bog(2026, 11, 12, 10, 0)}}, on={"answered": "next_step"}, callback_at=bog(2026, 11, 10, 11, 0),
    ref="T23 («nunca acorta un intervalo mínimo»)", basis="decision-fixture", decision="DF-F2")
FUP("blocked (wait por defecto) con espera de 3 días: el mismo paso el viernes 13-11 10:00", "blocked", "system",
    {"status": "waiting", "nextJob": {"stepIndex": 0, "attempt": 2, "notBefore": bog(2026, 11, 13, 10, 0)}}, wait={"n": 3, "unit": "days"},
    basis="decision-fixture", decision="DF-F2")
FUP("next_step cuyo retraso cae en sábado 15:30 → lunes 08:00", "answered", "staff", {"nextJob": {"stepIndex": 1, "attempt": 1, "notBefore": bog(2026, 11, 9, 8, 0)}},
    on={"answered": "next_step"}, now=bog(2026, 11, 5, 15, 30), enr={"enrolledAt": bog(2026, 11, 5, 9, 0)}, ref="#17, D9-7 (aplazamiento legal)",
    basis="decision-fixture", decision="DF-F2")
FUP("paso 3 con delay desde la inscripción (1 semana desde el 09-11 10:00 → lunes 16-11 festivo → martes 08:00)", "answered", "staff",
    {"nextJob": {"stepIndex": 2, "attempt": 1, "notBefore": bog(2026, 11, 17, 8, 0)}}, on={"answered": "next_step"}, enr={"stepIndex": 1, "touches": 2},
    basis="decision-fixture", decision="DF-F2")
FUP("con horario de la estrategia (10:00–12:00): el paso 2 a las 10:00 del jueves sigue a las 10:00", "answered", "staff",
    {"nextJob": {"stepIndex": 1, "attempt": 1, "notBefore": bog(2026, 11, 12, 10, 0)}}, on={"answered": "next_step"},
    schedule={"days": [1, 2, 3, 4, 5], "windows": [{"from": 600, "to": 720}]}, basis="decision-fixture", decision="DF-F2")
FUP("con horario de la estrategia (14:00–16:00): el paso 2 pasa a las 14:00 del jueves", "answered", "staff",
    {"nextJob": {"stepIndex": 1, "attempt": 1, "notBefore": bog(2026, 11, 12, 14, 0)}}, on={"answered": "next_step"},
    schedule={"days": [1, 2, 3, 4, 5], "windows": [{"from": 840, "to": 960}]}, basis="decision-fixture", decision="DF-F2")
FUP("último paso y next_step: sin trabajo, espera su ventana (vencerá con no_response)", "answered", "staff",
    {"status": "waiting", "nextJob": None, "reason": "sequence_done_waits_window"}, on={"answered": "next_step"}, enr={"stepIndex": 2, "touches": 3},
    basis="decision-fixture", decision="DF-F3")
FUP("el siguiente hueco cae después de la ventana: sin trabajo", "answered", "staff", {"status": "waiting", "nextJob": None, "reason": "window_over_no_job"},
    on={"answered": "next_step"}, enr={"windowEndsAt": bog(2026, 11, 12, 9, 0)}, basis="decision-fixture", decision="DF-F3")
FUP("tope de contactos alcanzado: sale aunque la regla pida seguir", "answered", "staff", {"status": "exited", "nextJob": None, "reason": "max_touches_reached"},
    on={"answered": "next_step"}, enr={"touches": 6}, ref="T20, D9-6 (≤ 6)", basis="decision-fixture", decision="DF-F4")
FUP("opted_out: estado opted_out, sin trabajo (#10)", "opted_out", "staff", {"status": "opted_out", "nextJob": None}, ref="#10 de §13.1")
FUP("opted_out aunque la estrategia diga next_step: opted_out (#10)", "opted_out", "staff", {"status": "opted_out", "nextJob": None},
    on={"opted_out": "next_step"}, ref="#10 de §13.1")
FUP("human_requested: handed_off, sin trabajo (#22)", "human_requested", "staff", {"status": "handed_off", "nextJob": None}, ref="#22 de §13.1")
FUP("converted: completed", "converted", "system", {"status": "completed", "nextJob": None}, ref="#4 de §13.1")
FUP("no_response: expired", "no_response", "system", {"status": "expired", "nextJob": None}, basis="decision-fixture", decision="DF-O7")
FUP("clasificador con la etiqueta ambiguous: needs_review → handed_off, sin trabajo (#21)", "ambiguous", "classifier",
    {"status": "handed_off", "nextJob": None, "resolution": {"verdict": "needs_review", "transition": "handoff_human"}}, confidence=97,
    ref="#21 de §13.1", basis="decision-fixture", decision="DF-O3")
FUP("failed con retry_step en el intento 2: agotado (≤ 1) → exited", "failed", "system", {"status": "exited", "nextJob": None}, on={"failed": "retry_step"},
    enr={"attempt": 2}, basis="decision-fixture", decision="DF-O6")

# priority-cases.json · PriorityArbiter (D9-8 etapa 3; P9-7; §13.1 #5 y #12; §13.3 «dos estrategias con la misma prioridad»)
#   arbitrate · varias estrategias que califican para la MISMA persona el mismo día → una gana; las demás defer (lower_priority o
#               purpose_conflict_today). Orden: (1) nunca dos finalidades el mismo día, (2) service > collections > commercial,
#               (3) priority mayor, (4) GOAL_PRIORITY mayor, (5) uuid menor (orden de bytes)
PURPOSE_RANK = {"service": 3, "collections": 2, "commercial": 1}
GOAL_PRIORITY = {"escalate": 95, "recover_payment_link": 90, "collect_payment": 85, "activate_membership": 80, "recover_satisfaction": 78, "renew": 75,
                 "close_plan": 70, "resolve_objection": 65, "reactivate": 60, "complete_onboarding": 55, "increase_adherence": 50, "book_assessment": 48,
                 "book_visit": 45, "upgrade": 40, "link_app": 35, "cross_sell": 30, "collect_data": 25, "request_referral": 20}
assert sorted(GOAL_PRIORITY) == sorted(GOALS)


def arbiter_ref(cands, today_purpose=None):
    def key(c):
        return (-PURPOSE_RANK[c["purpose"]], -c["priority"], -GOAL_PRIORITY[c["goal"]], c["uuid"].encode())
    eligible = [c for c in cands if today_purpose is None or c["purpose"] == today_purpose]
    blocked = [c for c in cands if today_purpose is not None and c["purpose"] != today_purpose]
    ranked = sorted(eligible, key=key)
    out = {"winner": ranked[0]["uuid"] if ranked else None, "ranking": [c["uuid"] for c in ranked], "deferred": []}
    for c in ranked[1:]:
        out["deferred"].append({"uuid": c["uuid"], "reason": "purpose_conflict_today" if c["purpose"] != ranked[0]["purpose"] else "lower_priority"})
    for c in sorted(blocked, key=key):
        out["deferred"].append({"uuid": c["uuid"], "reason": "purpose_conflict_today"})
    return out


PR = Fixture("priority-cases.json", header(
    "priority-cases.json",
    ["D9-8 etapa 3 (por persona y día: (1) nunca dos finalidades el mismo día; (2) service > collections > commercial [P9-7]; (3) priority; (4) GOAL_PRIORITY; (5) uuid de la estrategia, estable)",
     "D9-6 (priority 1–100; goal de CommercialVocabulary::GOALS)", "§13.1 #5 (dos campañas simultáneas) y #12 (reglas contradictorias); §13.3 (misma prioridad: desempata el uuid)"],
    [SOURCE_CONTRACT, {"what": "GOAL_PRIORITY", "url": "backend/app/Services/Commercial/CommercialVocabulary.php:271-290", "consulted": "09-11-2026, en este árbol",
                       "how": "Copiada literalmente (18 objetivos; a más número, más prioridad)."}],
    goalPriority=GOAL_PRIORITY,
    purposeRank=["service", "collections", "commercial"],
    interface={"arbitrate": "PriorityArbiter::arbitrate(candidates [{uuid, purpose, priority, goal}], todayPurpose|null) → {winner|null, ranking (las que pueden salir hoy, de mejor a peor), deferred [{uuid, reason}]}"},
    decisions={
        "DF-P1": "A más `priority`, antes (como GOAL_PRIORITY); a igualdad de todo, el uuid menor por orden de bytes (en minúscula).",
        "DF-P2": "todayPurpose es la finalidad que la persona ya tiene hoy (un trabajo planificado o un contacto): las candidatas de otra finalidad quedan defer con purpose_conflict_today aunque tengan más rango; sin todayPurpose, la ganadora fija la finalidad del día y las de otra finalidad salen purpose_conflict_today, las de la misma lower_priority.",
    },
))


def ARB(desc, cands, today, winner, deferred, *, ref="D9-8 etapa 3", basis="contrato", decision=None):
    got = arbiter_ref(cands, today)
    assert got["winner"] == winner, (desc, got)
    assert got["deferred"] == deferred, (desc, got["deferred"])
    PR.add("arbitrate", "person-day", desc, ref, basis, {"candidates": cands, "todayPurpose": today}, got, decision=decision)


def S(n, purpose="commercial", priority=50, goal="renew"):
    return {"uuid": f"5{n:07x}-0000-4000-8000-{n:012x}", "purpose": purpose, "priority": priority, "goal": goal}


A1, A2, A3 = S(1), S(2), S(3)
ARB("una sola estrategia → gana sin aplazar a nadie", [A1], None, A1["uuid"], [])
ARB("#5: dos campañas comerciales: gana la de más priority; la otra lower_priority", [S(1, priority=40), S(2, priority=70)], None, S(2)["uuid"],
    [{"uuid": S(1)["uuid"], "reason": "lower_priority"}], ref="#5 de §13.1", decision="DF-P1", basis="decision-fixture")
ARB("misma priority: decide GOAL_PRIORITY (recover_payment_link 90 > renew 75)", [S(1, goal="renew"), S(2, goal="recover_payment_link")], None, S(2)["uuid"],
    [{"uuid": S(1)["uuid"], "reason": "lower_priority"}])
ARB("misma priority y mismo objetivo: desempata el uuid menor (§13.3)", [S(9), S(4)], None, S(4)["uuid"], [{"uuid": S(9)["uuid"], "reason": "lower_priority"}],
    ref="§13.3", decision="DF-P1", basis="decision-fixture")
ARB("service gana a commercial aunque tenga menos priority", [S(1, priority=100), S(2, "service", priority=1, goal="book_assessment")], None, S(2)["uuid"],
    [{"uuid": S(1)["uuid"], "reason": "purpose_conflict_today"}], ref="P9-7, D9-8 etapa 3")
ARB("collections gana a commercial", [S(1, priority=90, goal="renew"), S(2, "collections", priority=10, goal="collect_payment")], None, S(2)["uuid"],
    [{"uuid": S(1)["uuid"], "reason": "purpose_conflict_today"}], ref="P9-7")
ARB("service gana a collections", [S(1, "collections", goal="collect_payment"), S(2, "service", goal="book_assessment")], None, S(2)["uuid"],
    [{"uuid": S(1)["uuid"], "reason": "purpose_conflict_today"}], ref="P9-7")
ARB("tres: service, commercial alta y commercial baja → service; las comerciales purpose_conflict_today",
    [S(1, priority=90), S(2, "service", goal="book_assessment"), S(3, priority=10)], None, S(2)["uuid"],
    [{"uuid": S(1)["uuid"], "reason": "purpose_conflict_today"}, {"uuid": S(3)["uuid"], "reason": "purpose_conflict_today"}], ref="P9-7")
ARB("hoy ya hay cobranza: las comerciales no salen aunque haya una sola (purpose_conflict_today)", [S(1)], "collections", None,
    [{"uuid": S(1)["uuid"], "reason": "purpose_conflict_today"}], ref="P9-7 («nunca las dos el mismo día»)", decision="DF-P2", basis="decision-fixture")
ARB("hoy ya hay comercial: de las candidatas, solo compiten las comerciales", [S(1, "service", goal="book_assessment"), S(2, priority=10), S(3, priority=20)],
    "commercial", S(3)["uuid"], [{"uuid": S(2)["uuid"], "reason": "lower_priority"}, {"uuid": S(1)["uuid"], "reason": "purpose_conflict_today"}],
    ref="P9-7", decision="DF-P2", basis="decision-fixture")
ARB("#12: reglas contradictorias (una quiere renovar, otra reactivar) con la misma priority → gana renew (75 > 60)",
    [S(1, goal="reactivate"), S(2, goal="renew")], None, S(2)["uuid"], [{"uuid": S(1)["uuid"], "reason": "lower_priority"}], ref="#12 de §13.1")
ARB("el uuid desempata por bytes, no por el número: «5000000a…» va después de «50000009…»", [S(10), S(9)], None, S(9)["uuid"],
    [{"uuid": S(10)["uuid"], "reason": "lower_priority"}], ref="§13.3", decision="DF-P1", basis="decision-fixture")
ARB("la priority manda antes que GOAL_PRIORITY", [S(1, priority=60, goal="escalate"), S(2, priority=61, goal="request_referral")], None, S(2)["uuid"],
    [{"uuid": S(1)["uuid"], "reason": "lower_priority"}], ref="D9-8 etapa 3 (orden 3 antes que 4)")
ARB("el orden de llegada no importa (determinista)", [S(3), S(1), S(2)], None, S(1)["uuid"],
    [{"uuid": S(2)["uuid"], "reason": "lower_priority"}, {"uuid": S(3)["uuid"], "reason": "lower_priority"}], ref="D9-8 («determinista»)")

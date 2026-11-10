# channel-selection-cases.json · ChannelSelector::choose (D9-9.2; §13.1 #8 y #9; §13.2 T13, T19 y T22)
#   choose · lista ORDENADA de canales del paso + el veredicto de la escalera para cada canal (+ la preferencia de la persona)
#            → canal elegido, o defer al retryAt más temprano, o deny con los motivos de cada canal
# Los veredictos por canal se calculan con la MISMA referencia de policy-ladder-cases.json (ladder_ref) desde BASE + patch:
# la entrada del selector es lo que la escalera diría de cada canal, y el caso guarda también el patch para rehacerlo.


def selector_ref(channels, per, preferred=None):
    order = list(channels)
    notes = []
    if preferred is not None:
        pref = [c for c in order if MEDIUM[c] == preferred]
        if any(per[c]["verdict"] == "allow" for c in pref):
            order = pref + [c for c in order if MEDIUM[c] != preferred]
            notes.append("preference_applied")
        else:
            notes.append("instruction_ignored_by_policy")
    for c in order:
        if per[c]["verdict"] == "allow":
            return {"verdict": "selected", "channel": c, "retryAt": None, "order": order, "annotations": notes,
                    "perChannel": [dict({"channel": k}, **per[k]) for k in channels]}
    deferred = [per[c]["retryAt"] for c in order if per[c]["verdict"] == "defer"]
    if deferred:
        return {"verdict": "defer", "channel": None, "retryAt": min(deferred, key=lambda s: parse_iso(s)), "order": order, "annotations": notes,
                "perChannel": [dict({"channel": k}, **per[k]) for k in channels]}
    return {"verdict": "deny", "channel": None, "retryAt": None, "order": order, "annotations": notes,
            "perChannel": [dict({"channel": k}, **per[k]) for k in channels]}


def _summary(out):
    return {"verdict": out["verdict"], "level": out["level"], "reasons": out["reasons"], "retryAt": out["retryAt"]}


CS2 = Fixture("channel-selection-cases.json", header(
    "channel-selection-cases.json",
    ["D9-9.2 (selección determinista: recorre la lista ordenada del paso y elige el primer canal elegible; si ninguno y alguno solo falla por tiempo, defer al retryAt más temprano; si no, deny con los motivos de cada canal)",
     "D9-7 N7 (preference_applied: se reordena solo entre los permitidos; instruction_ignored_by_policy)",
     "§13.1 #8 (sin consentimiento de voz) y #9 (WhatsApp sí, voz no); §13.2 T13, T19, T22"],
    [SOURCE_CONTRACT, {"what": "El veredicto de la escalera por canal", "url": "tests/fixtures/outreach/policy-ladder-cases.json (baseInput y su referencia)",
                       "consulted": "09-11-2026", "how": "Cada canal se evalúa con baseInput + el patch del caso + {channel}; el selector solo mira verdict, level, reasons y retryAt."}],
    interface={
        "choose": "ChannelSelector::choose(channels (orden del paso), perChannel {canal: {verdict, level, reasons, retryAt}}, preferredMedium|null) → {verdict: selected|defer|deny, channel|null, retryAt|null, order (el orden efectivo), annotations, perChannel (en el orden declarado, para la traza «por qué este canal y no aquel»)}",
    },
    decisions={
        "DF-S1": "Preferencia de la persona (N7): si algún canal de su medio está permitido, los de ese medio pasan delante conservando su orden relativo (preference_applied); si ninguno de su medio está permitido, se ignora (instruction_ignored_by_policy) y manda el orden del paso. Nunca levanta una negativa.",
        "DF-S2": "Sin canal permitido: defer si al menos un canal sale defer (solo falla por tiempo), con el retryAt más temprano de esos; si todos niegan, deny con los motivos de cada canal (perChannel). Sin azar ni empates: a igualdad manda el orden.",
    },
))


def SEL(desc, channels, patch, *, by_channel=None, preferred=None, expect, ref, basis="contrato", decision=None):
    per, inputs = {}, {}
    for c in channels:
        p = deep_merge(patch, {"channel": c})
        if by_channel and c in by_channel:
            p = deep_merge(p, by_channel[c])
        inputs[c] = p
        per[c] = _summary(ladder_ref(deep_merge(BASE, p)))
    got = selector_ref(channels, per, preferred)
    for k, v in expect.items():
        assert got[k] == v, (desc, k, "referencia:", got[k], "literal:", v)
    CS2.add("choose", "select", desc, ref, basis,
            {"channels": channels, "preferredMedium": preferred, "perChannel": per, "ladderPatchByChannel": inputs}, got, decision=decision)


TPL = ["whatsapp_template", "voice_call", "assisted:whatsapp"]
LIVE_W9_V9 = {"flags": {"sendsEnabledInCode": True}, "budget": {"dailyBudgetMicros": 1000000, "channelDailyCap": 10, "costMicros": 5000}}
SEL("15-11: WhatsApp y voz automáticos no salen (constante y canal sin implementar) → el asistido", TPL, {"flags": {"channelLive": True}},
    by_channel={"whatsapp_template": {"flags": {"channelLive": False}}, "voice_call": {"flags": {"channelLive": False}}},
    expect={"verdict": "selected", "channel": "assisted:whatsapp"}, ref="T13, T19, D9-9.5")
SEL("#9: autorización de WhatsApp y no de voz → WhatsApp, nunca voz (aunque el paso ponga la voz primero)", ["voice_call", "whatsapp_template"],
    deep_merge(LIVE_W9_V9, {"consent": {"voice": "none"}}), expect={"verdict": "selected", "channel": "whatsapp_template"}, ref="#9 de §13.1, T13")
SEL("#8: sin consentimiento de voz → voz denegada en N1 y el selector toma WhatsApp", ["voice_call", "whatsapp_template", "assisted:whatsapp"],
    deep_merge(LIVE_W9_V9, {"consent": {"voice": "none"}}), expect={"verdict": "selected", "channel": "whatsapp_template"}, ref="#8 de §13.1")
SEL("#8: sin consentimiento de voz ni WhatsApp automático disponible → el asistido por WhatsApp", ["voice_call", "whatsapp_template", "assisted:whatsapp"],
    {"consent": {"voice": "none"}}, expect={"verdict": "selected", "channel": "assisted:whatsapp"}, ref="#8 de §13.1 («si no, asistido»)")
SEL("T13: sin autorización de WhatsApp → voz si hay voice_consents", TPL, deep_merge(LIVE_W9_V9, {"consent": {"whatsapp": "none"}}),
    expect={"verdict": "selected", "channel": "voice_call"}, ref="T13")
SEL("T13: sin autorización de WhatsApp ni de voz → nadie (el asistido exige el consentimiento de su medio): deny con todos los motivos",
    ["whatsapp_template", "voice_call", "assisted:whatsapp", "assisted:voice"], deep_merge(LIVE_W9_V9, {"consent": {"whatsapp": "none", "voice": "none"}}),
    expect={"verdict": "deny", "channel": None, "retryAt": None}, ref="T13 («sin el medio del asistido, deny con todos los motivos»), D9-9.5")
SEL("todos los canales solo fallan por tiempo (domingo) → defer al lunes 08:00", TPL, deep_merge(LIVE_W9_V9, {"now": bog(2026, 11, 8, 10, 0)}),
    expect={"verdict": "defer", "channel": None, "retryAt": bog(2026, 11, 9, 8, 0)}, ref="D9-9.2, #17")
SEL("uno niega por consentimiento y otro solo por frecuencia → defer al retryAt del de frecuencia", ["whatsapp_template", "voice_call"],
    deep_merge(LIVE_W9_V9, {"consent": {"voice": "none"}, "history": [H(bog(2026, 11, 9, 18, 0))]}),
    expect={"verdict": "defer", "channel": None, "retryAt": bog(2026, 11, 11, 18, 0)}, ref="D9-9.2", basis="decision-fixture", decision="DF-S2")
SEL("dos canales aplazados: gana el retryAt más temprano", ["whatsapp_template", "voice_call"],
    deep_merge(LIVE_W9_V9, {"history": [H(bog(2026, 11, 9, 18, 0))]}), by_channel={"voice_call": {"strategy": {"cooldownUntil": bog(2026, 11, 13, 9, 0)}}},
    expect={"verdict": "defer", "retryAt": bog(2026, 11, 11, 18, 0)}, ref="D9-9.2 («al retryAt más temprano»)", basis="decision-fixture", decision="DF-S2")
SEL("freno del canal WhatsApp → salta a voz", TPL, LIVE_W9_V9, by_channel={"whatsapp_template": {"brakes": {"channel": True}}},
    expect={"verdict": "selected", "channel": "voice_call"}, ref="§9.2 nivel 2, T19")
SEL("plantilla de WhatsApp sin aprobar → salta al siguiente permitido", TPL, LIVE_W9_V9, by_channel={"whatsapp_template": {"step": {"templateApproved": False}}},
    expect={"verdict": "selected", "channel": "voice_call"}, ref="T19")
SEL("el asistido apagado y los automáticos sin implementar → deny", TPL, {"flags": {"channelLive": True}},
    by_channel={"whatsapp_template": {"flags": {"channelLive": False}}, "voice_call": {"flags": {"channelLive": False}},
                "assisted:whatsapp": {"flags": {"channelEnabled": False}}},
    expect={"verdict": "deny", "channel": None}, ref="T19 («si no queda ninguno, deny»)")
SEL("«mejor por voz» con los dos permitidos → voz delante (preference_applied)", ["whatsapp_template", "voice_call"], LIVE_W9_V9, preferred="voice",
    expect={"verdict": "selected", "channel": "voice_call", "annotations": ["preference_applied"], "order": ["voice_call", "whatsapp_template"]},
    ref="T22, D9-7 N7", basis="decision-fixture", decision="DF-S1")
SEL("«mejor por WhatsApp» con WhatsApp denegado → se ignora (instruction_ignored_by_policy) y sale voz", ["whatsapp_template", "voice_call"],
    deep_merge(LIVE_W9_V9, {"consent": {"whatsapp": "denied"}}), preferred="whatsapp",
    expect={"verdict": "selected", "channel": "voice_call", "annotations": ["instruction_ignored_by_policy"]}, ref="T22 («solo reordena entre los permitidos»)",
    basis="decision-fixture", decision="DF-S1")
SEL("preferencia de un medio sin canales en el paso → se ignora", ["whatsapp_template", "assisted:whatsapp"], LIVE_W9_V9, preferred="voice",
    expect={"verdict": "selected", "channel": "whatsapp_template", "annotations": ["instruction_ignored_by_policy"]}, ref="T22", basis="decision-fixture",
    decision="DF-S1")
SEL("preferencia que no levanta un N1: todos denegados → deny aunque la persona lo pidiera", ["whatsapp_template", "voice_call"],
    deep_merge(LIVE_W9_V9, {"optOut": {"siblingDoNotContact": True}}), preferred="voice", expect={"verdict": "deny", "annotations": ["instruction_ignored_by_policy"]},
    ref="T22 («ninguna instrucción conversacional levanta una negación de N1–N6»)", basis="decision-fixture", decision="DF-S1")
SEL("un solo canal permitido en la lista → ese", ["assisted:voice"], {}, expect={"verdict": "selected", "channel": "assisted:voice"}, ref="D9-9.2")
SEL("sombra: el primer canal (WhatsApp automático) sale simulado aunque la constante esté cerrada", TPL, {"flags": {"mode": "shadow", "channelLive": False}},
    expect={"verdict": "selected", "channel": "whatsapp_template"}, ref="D9-8 (sombra), D9-9.4 («hasta entonces, se simulan»)", basis="decision-fixture",
    decision="DF-L5")

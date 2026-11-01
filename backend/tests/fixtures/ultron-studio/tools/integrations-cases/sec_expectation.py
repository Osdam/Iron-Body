# expectation · effectiveVersion, expectation y expectedVersion.stale (contrato §I4.6)
# row.isEngineCanary: lo que dio la coherencia (true | false | null).
# chain: lo que devuelve StudioPublications::state() reducido a {state, canary, rest}, o {throws: true}.
W, V = "whatsapp", "voice"
VA = "11111111-1111-4111-8111-111111111111"
VB = "22222222-2222-4222-8222-222222222222"
VC = "33333333-3333-4333-8333-333333333333"
PA = {"versionUuid": VA, "label": "Versión A"}
PB = {"versionUuid": VB, "label": "Versión B"}
PC = {"versionUuid": VC, "label": "Versión C"}


def chain(state, canary=None, rest=None):
    return {"throws": False, "state": state, "canary": canary, "rest": rest}


THROWS = {"throws": True}


def ev(uuid, status, integrity=True):
    return {"uuid": uuid, "status": status, "integrityOk": integrity}


def applied(part, p):
    return {"source": "publication_chain", "state": "applied", "part": part, "versionUuid": p["versionUuid"], "label": p["label"]}


def st(state):
    return {"source": "publication_chain", "state": state}


def none(reason):
    return {"source": "none", "reason": reason}


def XP(desc, ch, is_engine, ch_state, expected_version, eff, expectation, stale):
    add("expectation", "resolve", desc,
        {"row": {"channel": ch, "isEngineCanary": is_engine}, "chain": ch_state, "expectedVersion": expected_version},
        {"effectiveVersion": eff, "expectation": expectation, "expectedVersionStale": stale})


XP("canario del motor, cadena con canario B, se espera B", W, True, chain("applied", PB, PA), ev(VB, "CANARY"), applied("canary", PB), "match", False)
XP("canario del motor, cadena con canario B, se espera A (la que va al resto)", W, True, chain("applied", PB, PA), ev(VA, "PUBLISHED"), applied("canary", PB), "mismatch", False)
XP("sin parte canaria: rige la del resto (A) y se espera A", W, True, chain("applied", None, PA), ev(VA, "PUBLISHED"), applied("rest", PA), "match", False)
XP("sin parte canaria: rige A y se espera B", W, True, chain("applied", None, PA), ev(VB, "CANARY"), applied("rest", PA), "mismatch", False)
XP("applied con las dos partes nulas (defensivo): es legacy", W, True, chain("applied", None, None), ev(VB, "CANARY"), st("legacy"), "mismatch", False)
XP("cadena en legacy", W, True, chain("legacy"), ev(VB, "CANARY"), st("legacy"), "mismatch", False)
XP("cadena en off", W, True, chain("off"), ev(VB, "CANARY"), st("off"), "mismatch", False)
XP("cadena en fallback", W, True, chain("fallback"), ev(VB, "CANARY"), st("fallback"), "mismatch", False)
XP("cadena en off y sin versión esperada", W, True, chain("off"), None, st("off"), "none", None)
XP("cadena con canario B y sin versión esperada", W, True, chain("applied", PB, PA), None, applied("canary", PB), "none", None)
XP("la cadena no se puede leer", W, True, THROWS, ev(VB, "CANARY"), none("chain_unreadable"), "not_applicable", False)
XP("la cadena no se puede leer y no hay versión esperada: none antes que not_applicable", W, True, THROWS, None, none("chain_unreadable"), "none", None)
XP("WhatsApp que no es el canario del motor", W, False, chain("applied", PB, PA), ev(VB, "CANARY"), none("not_engine_canary"), "not_applicable", False)
XP("WhatsApp que no es el canario del motor: la cadena ni se consulta", W, False, THROWS, ev(VB, "CANARY"), none("not_engine_canary"), "not_applicable", False)
XP("WhatsApp con la coherencia ilegible", W, None, chain("applied", PB, PA), ev(VB, "CANARY"), none("coherence_unreadable"), "not_applicable", False)
XP("voz con versión esperada", V, None, chain("applied", PB, PA), ev(VB, "CANARY"), none("no_voice_engine"), "not_applicable", False)
XP("voz sin versión esperada", V, None, chain("applied", PB, PA), None, none("no_voice_engine"), "none", None)
XP("voz con la cadena ilegible", V, None, THROWS, ev(VB, "CANARY"), none("no_voice_engine"), "not_applicable", False)
XP("versión esperada RETIRED: dato viejo", W, True, chain("applied", None, PA), ev(VB, "RETIRED"), applied("rest", PA), "mismatch", True)
XP("versión esperada ROLLED_BACK con la cadena en legacy", W, True, chain("legacy"), ev(VB, "ROLLED_BACK"), st("legacy"), "mismatch", True)
XP("versión esperada no íntegra aunque coincida: match y dato viejo", W, True, chain("applied", PB, PA), ev(VB, "CANARY", False), applied("canary", PB), "match", True)
XP("versión esperada APPROVED íntegra: no es dato viejo", W, True, chain("applied", PB, PA), ev(VC, "APPROVED"), applied("canary", PB), "mismatch", False)
XP("voz con la versión esperada RETIRED: not_applicable y dato viejo", V, None, chain("legacy"), ev(VB, "RETIRED"), none("no_voice_engine"), "not_applicable", True)
XP("B promovida a las dos partes y se espera B", W, True, chain("applied", PB, PB), ev(VB, "PUBLISHED"), applied("canary", PB), "match", False)

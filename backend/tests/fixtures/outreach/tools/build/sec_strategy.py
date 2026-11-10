# strategy-content-cases.json · StrategyContent (D9-6; D9-11.4; §11 B9-core «StrategyContent (lector estricto del JSON)»)
#   valid    · JSON de estrategia válido → forma canónica (con sus condiciones canónicas), JSON canónico y content_hash
#   invalid  · 422 con TODAS las claves a la vez ({key, code}, raíz «content»)
#   versions · pares: qué cambia la huella de la versión (la referencia de la plantilla, sí; el orden de las claves, no)
import re as _re

CONTENT_FORMAT = "strategy-content/1"
CONTENT_KEYS_REQUIRED = ["audience", "canary", "format", "goal", "limits", "priority", "purpose", "sequence", "studio", "success", "trigger"]
CONTENT_KEYS_OPTIONAL = ["exclusions", "schedule"]
STEP_KEYS_REQUIRED = ["channels", "delay", "id", "templates"]
STEP_KEYS_OPTIONAL = ["guard", "on", "waitFor"]
LIMIT_RANGES = {"contactsPerDay": (1, 500), "cooldownDays": (0, 365), "enrollmentDays": (1, 60), "maxTouches": (1, 6), "newEnrollmentsPerDay": (1, 500)}
LIMIT_OPTIONAL = {"commercialBlockOverdueDays": (0, 365)}      # L9-20: solo commercial; el umbral efectivo es el menor con el del servidor
STRATEGY_LIMITS = {"maxContentBytes": 32768, "maxSteps": 6, "maxChannelsPerStep": 3, "priority": [1, 100], "maxWaitDays": 14, "maxDelayDays": 60,
                   "maxScheduleWindows": 4, "limits": {k: list(v) for k, v in LIMIT_RANGES.items()},
                   "limitsOptional": {k: list(v) for k, v in LIMIT_OPTIONAL.items()}}
HEX64 = _re.compile(r"^[0-9a-f]{64}$")
UUID = _re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$")
STEP_ID = _re.compile(r"^[a-z0-9][a-z0-9_-]{0,31}$")
STUDIO_CATALOG_VERSION = _re.compile(r"^\d{4}-\d{2}-\d{2}\.\d+$")
STRATEGY_ERROR_CODES = sorted(set(ERROR_CODES) | {"invalid_format", "trigger_not_allowed", "purpose_mismatch", "fact_purpose_not_allowed",
                                                  "transition_not_allowed", "template_missing", "constraint_violated", "schedule_outside_legal_window"})
STEP_CHANNEL_KEYS = [c["key"] for c in STEP_CHANNELS]


class SV(V):
    """El lector estricto: reutiliza el validador de condiciones (V) para audience, exclusions, success y guard."""

    def __init__(self, dyn, purpose):
        super().__init__(dyn)
        self.purpose = purpose

    def err(self, key, code):
        assert code in STRATEGY_ERROR_CODES, code
        self.errors.append((key, code))

    def n_fact(self, x, path, depth):
        out = super().n_fact(x, path, depth)
        f = x.get("fact")
        if isinstance(f, str) and f in FACT_BY_KEY and self.purpose in PURPOSES and self.purpose not in FACT_BY_KEY[f]["purposes"]:
            self.err(f"{path}.fact", "fact_purpose_not_allowed")
            return None
        return out

    def intval(self, v, path, lo, hi):
        return self.bounded(v, path, lo, hi)

    def keys(self, obj, path, required, optional):
        ok = True
        for k in obj:
            if k not in required and k not in optional:
                self.err(f"{path}.{k}", "unexpected_key")
                ok = False
        for k in required:
            if k not in obj:
                self.err(f"{path}.{k}", "missing_key")
                ok = False
        return ok

    def cond(self, tree, path):
        """Una condición con TODOS los límites de la gramática (profundidad, nodos y 8 192 bytes), bajo su clave."""
        before = len(self.errors)
        canon = self.node(tree, path, 1)
        if count_nodes(tree) > LIMITS["maxNodes"]:
            self.err(path, "too_many_nodes")
            canon = None
        if len(self.errors) == before and canon is not None and len(canonical(canon).encode()) > LIMITS["maxCanonicalBytes"]:
            self.err(path, "too_large")
            canon = None
        return canon

    def pattern(self, v, path, rx):
        if not isinstance(v, str):
            self.err(path, "invalid_type")
            return None
        if not rx.match(v):
            self.err(path, "invalid_format")
            return None
        return v


def strategy_ref(content, dyn=DYN):
    purpose = content.get("purpose") if isinstance(content, dict) else None
    v = SV(dyn, purpose)
    root = "content"
    if not is_obj(content):
        v.err(root, "not_an_object")
        return None, [{"key": root, "code": "not_an_object"}]
    c = as_dict(content)
    v.keys(c, root, CONTENT_KEYS_REQUIRED, CONTENT_KEYS_OPTIONAL)
    out = {}
    if "format" in c:
        if c["format"] != CONTENT_FORMAT:
            v.err(f"{root}.format", "value_not_in_domain")
        else:
            out["format"] = CONTENT_FORMAT
    if "goal" in c:
        g = v.enum_value(sorted(GOALS), c["goal"], f"{root}.goal")
        out["goal"] = g
    if "purpose" in c:
        p = v.enum_value(PURPOSES, c["purpose"], f"{root}.purpose")
        out["purpose"] = p
    if "priority" in c:
        out["priority"] = v.intval(c["priority"], f"{root}.priority", 1, 100)
    if "trigger" in c:
        t = c["trigger"]
        tp = f"{root}.trigger"
        if not is_obj(t):
            v.err(tp, "invalid_type")
        else:
            t = as_dict(t)
            v.keys(t, tp, ["event"], ["params"])
            ev = None
            if "event" in t:
                if not isinstance(t["event"], str):
                    v.err(f"{tp}.event", "invalid_type")
                elif t["event"] not in EVENT_BY_KEY:
                    v.err(f"{tp}.event", "value_not_in_domain")
                elif not EVENT_BY_KEY[t["event"]]["triggerable"] or not EVENT_BY_KEY[t["event"]]["available"]:
                    v.err(f"{tp}.event", "trigger_not_allowed")
                else:
                    ev = EVENT_BY_KEY[t["event"]]
                    if purpose in PURPOSES and purpose not in ev["purposes"]:
                        v.err(f"{tp}.event", "purpose_mismatch")
            params = {}
            if ev is not None:
                pr = t.get("params", {})
                if not is_obj(pr):
                    v.err(f"{tp}.params", "invalid_type")
                else:
                    pr = as_dict(pr)
                    decl = {p_["name"]: p_ for p_ in ev["params"]}
                    for k in pr:
                        if k not in decl:
                            v.err(f"{tp}.params.{k}", "unexpected_key")
                    for name, d in decl.items():
                        if name not in pr:
                            if d["required"]:
                                v.err(f"{tp}.params.{name}", "missing_key")
                            continue
                        params[name] = v.intval(pr[name], f"{tp}.params.{name}", d["min"], d["max"])
                    for cons in ev["paramConstraints"]:
                        a, b = params.get(cons["left"]), params.get(cons["right"])
                        if isinstance(a, int) and isinstance(b, int) and not a <= b:
                            v.err(f"{tp}.params", "constraint_violated")
            out["trigger"] = {"event": t.get("event")}
            if params:
                out["trigger"]["params"] = params
    for field in ("audience", "success", "exclusions"):
        if field in c:
            if field == "exclusions" and c[field] is None:
                continue
            out[field] = v.cond(c[field], f"{root}.{field}")
    if "limits" in c:
        lp = f"{root}.limits"
        if not is_obj(c["limits"]):
            v.err(lp, "invalid_type")
        else:
            lm = as_dict(c["limits"])
            v.keys(lm, lp, sorted(LIMIT_RANGES), sorted(LIMIT_OPTIONAL))
            out["limits"] = {k: v.intval(lm[k], f"{lp}.{k}", *LIMIT_RANGES[k]) for k in sorted(LIMIT_RANGES) if k in lm}
            for k, rng in sorted(LIMIT_OPTIONAL.items()):
                if k in lm:
                    if purpose != "commercial":
                        v.err(f"{lp}.{k}", "unexpected_key")
                    else:
                        out["limits"][k] = v.intval(lm[k], f"{lp}.{k}", *rng)
    if "schedule" in c and c["schedule"] is not None:
        sp = f"{root}.schedule"
        s = c["schedule"]
        if not is_obj(s):
            v.err(sp, "invalid_type")
        else:
            s = as_dict(s)
            v.keys(s, sp, ["days", "windows"], [])
            days = s.get("days")
            okd = []
            if isinstance(days, list) and days:
                for i, d in enumerate(days):
                    dd = v.intval(d, f"{sp}.days.{i}", 1, 6) if not (isinstance(d, int) and d == 7) else v.err(f"{sp}.days.{i}", "value_not_in_domain")
                    if dd is not None:
                        if dd in okd:
                            v.err(f"{sp}.days.{i}", "duplicate_value")
                        else:
                            okd.append(dd)
            elif "days" in s:
                v.err(f"{sp}.days", "empty_values" if days in ([], {}) else "invalid_type")
            wins = s.get("windows")
            okw = []
            if isinstance(wins, list) and wins:
                if len(wins) > STRATEGY_LIMITS["maxScheduleWindows"]:
                    v.err(f"{sp}.windows", "too_many_values")
                for i, w in enumerate(wins):
                    wp = f"{sp}.windows.{i}"
                    if not is_obj(w):
                        v.err(wp, "invalid_type")
                        continue
                    w = as_dict(w)
                    v.keys(w, wp, ["from", "to"], [])
                    a = v.intval(w["from"], f"{wp}.from", 0, 1439) if "from" in w else None
                    b = v.intval(w["to"], f"{wp}.to", 1, 1440) if "to" in w else None
                    if isinstance(a, int) and isinstance(b, int):
                        if a >= b:
                            v.err(wp, "invalid_between")
                        elif b <= 480 or a >= 1140:
                            v.err(wp, "schedule_outside_legal_window")
                        else:
                            okw.append({"from": a, "to": b})
            elif "windows" in s:
                v.err(f"{sp}.windows", "empty_values" if wins in ([], {}) else "invalid_type")
            out["schedule"] = {"days": sorted(okd), "windows": okw}
    if "canary" in c:
        cp = f"{root}.canary"
        if not is_obj(c["canary"]):
            v.err(cp, "invalid_type")
        else:
            cn = as_dict(c["canary"])
            v.keys(cn, cp, ["channel"], [])
            if "channel" in cn:
                out["canary"] = {"channel": v.enum_value(MEDIA, cn["channel"], f"{cp}.channel")}
    if "studio" in c:
        stp = f"{root}.studio"
        st = c["studio"]
        if not is_obj(st):
            v.err(stp, "invalid_type")
        else:
            st = as_dict(st)
            kind = st.get("kind")
            if kind == "version":
                v.keys(st, stp, ["contentHash", "kind", "uuid"], [])
                out["studio"] = {"contentHash": v.pattern(st.get("contentHash"), f"{stp}.contentHash", HEX64) if "contentHash" in st else None,
                                 "kind": "version", "uuid": v.pattern(st.get("uuid"), f"{stp}.uuid", UUID) if "uuid" in st else None}
            elif kind == "baseline":
                v.keys(st, stp, ["catalogHash", "catalogVersion", "kind"], [])
                out["studio"] = {"catalogHash": v.pattern(st.get("catalogHash"), f"{stp}.catalogHash", HEX64) if "catalogHash" in st else None,
                                 "catalogVersion": v.pattern(st.get("catalogVersion"), f"{stp}.catalogVersion", STUDIO_CATALOG_VERSION)
                                 if "catalogVersion" in st else None, "kind": "baseline"}
            elif "kind" not in st:
                v.err(f"{stp}.kind", "missing_key")
            else:
                v.err(f"{stp}.kind", "value_not_in_domain")
    if "sequence" in c:
        qp = f"{root}.sequence"
        seq = c["sequence"]
        if not isinstance(seq, list) and not (isinstance(seq, dict) and not seq):
            v.err(qp, "invalid_type")
        elif not seq:
            v.err(qp, "empty_values")
        else:
            if len(seq) > STRATEGY_LIMITS["maxSteps"]:
                v.err(qp, "too_many_values")
            ids, steps = [], []
            for i, s in enumerate(seq):
                sp = f"{qp}.{i}"
                if not is_obj(s):
                    v.err(sp, "invalid_type")
                    continue
                s = as_dict(s)
                v.keys(s, sp, STEP_KEYS_REQUIRED, STEP_KEYS_OPTIONAL)
                st_out = {}
                if "id" in s:
                    sid = v.pattern(s["id"], f"{sp}.id", STEP_ID)
                    if sid is not None:
                        if sid in ids:
                            v.err(f"{sp}.id", "duplicate_value")
                        ids.append(sid)
                    st_out["id"] = sid
                if "delay" in s:
                    d = s["delay"]
                    dp = f"{sp}.delay"
                    if not is_obj(d):
                        v.err(dp, "invalid_type")
                    else:
                        d = as_dict(d)
                        v.keys(d, dp, ["from", "n", "unit"], [])
                        n = v.intval(d["n"], f"{dp}.n", 0, None) if "n" in d else None
                        u = v.enum_value(CATALOG["units"], d["unit"], f"{dp}.unit") if "unit" in d else None
                        fr = v.enum_value(["enrollment", "previous"], d["from"], f"{dp}.from") if "from" in d else None
                        if isinstance(n, int) and u is not None and n * CATALOG["unitHours"][u] > STRATEGY_LIMITS["maxDelayDays"] * 24:
                            v.err(dp, "window_out_of_range")
                        st_out["delay"] = {"from": fr, "n": n, "unit": u}
                chans = []
                if "channels" in s:
                    ch = s["channels"]
                    cp = f"{sp}.channels"
                    if not isinstance(ch, list) and not (isinstance(ch, dict) and not ch):
                        v.err(cp, "invalid_type")
                    elif not ch:
                        v.err(cp, "empty_values")
                    else:
                        if len(ch) > STRATEGY_LIMITS["maxChannelsPerStep"]:
                            v.err(cp, "too_many_values")
                        for j, k in enumerate(ch):
                            kk = v.enum_value(STEP_CHANNEL_KEYS, k, f"{cp}.{j}")
                            if kk is not None:
                                if kk in chans:
                                    v.err(f"{cp}.{j}", "duplicate_value")
                                else:
                                    chans.append(kk)
                    st_out["channels"] = chans
                if "templates" in s:
                    tp = f"{sp}.templates"
                    tt = s["templates"]
                    if not is_obj(tt):
                        v.err(tp, "invalid_type")
                    else:
                        tt = as_dict(tt)
                        tout = {}
                        for k in tt:
                            if k not in chans:
                                v.err(f"{tp}.{k}", "unexpected_key")
                        for k in chans:
                            if k not in tt:
                                v.err(f"{tp}.{k}", "template_missing")
                                continue
                            ref = tt[k]
                            rp = f"{tp}.{k}"
                            if not is_obj(ref):
                                v.err(rp, "invalid_type")
                                continue
                            ref = as_dict(ref)
                            v.keys(ref, rp, ["hash", "uuid"], [])
                            tout[k] = {"hash": v.pattern(ref.get("hash"), f"{rp}.hash", HEX64) if "hash" in ref else None,
                                       "uuid": v.pattern(ref.get("uuid"), f"{rp}.uuid", UUID) if "uuid" in ref else None}
                        st_out["templates"] = tout
                if "guard" in s and s["guard"] is not None:
                    st_out["guard"] = v.cond(s["guard"], f"{sp}.guard")
                if "on" in s:
                    op = f"{sp}.on"
                    on = s["on"]
                    if not is_obj(on):
                        v.err(op, "invalid_type")
                    else:
                        on = as_dict(on)
                        oo = {}
                        for k, tr in on.items():
                            if k not in OUTCOME_TABLE:
                                v.err(f"{op}.{k}", "unexpected_key")
                                continue
                            if not isinstance(tr, str):
                                v.err(f"{op}.{k}", "invalid_type")
                            elif tr not in FOLLOWUP_TRANSITIONS:
                                v.err(f"{op}.{k}", "value_not_in_domain")
                            elif tr not in OUTCOME_TABLE[k]["allowed"]:
                                v.err(f"{op}.{k}", "transition_not_allowed")
                            else:
                                oo[k] = tr
                        if oo:
                            st_out["on"] = oo
                        if "wait" in on.values() and "waitFor" not in s:
                            v.err(f"{sp}.waitFor", "missing_key")
                if "waitFor" in s:
                    w = v.window(s["waitFor"], f"{sp}.waitFor", max_days=STRATEGY_LIMITS["maxWaitDays"])
                    st_out["waitFor"] = w
                steps.append(st_out)
            out["sequence"] = steps
    if v.errors:
        errs = sorted(set(v.errors), key=lambda e: (e[0].encode(), e[1].encode()))
        return None, [{"key": k, "code": cc} for k, cc in errs]
    if len(canonical(out).encode()) > STRATEGY_LIMITS["maxContentBytes"]:
        return None, [{"key": root, "code": "too_large"}]
    return out, []


ST = Fixture("strategy-content-cases.json", header(
    "strategy-content-cases.json",
    ["D9-6 (strategy-content/1: goal, purpose, priority, trigger, audience, exclusions, success, sequence ≤ 6 pasos, limits, schedule, canary, studio; ≤ 32 KB)",
     "D9-6 (cada paso: id, delay, 1–3 canales ordenados, una plantilla uuid + hash por canal, guard opcional, on restringido por D9-10)",
     "D9-10 (on: solo transiciones permitidas; wait ≤ 14 días)", "D9-11.4 (studio_ref: {kind: version, uuid, contentHash} o {kind: baseline, catalogVersion, catalogHash}) y P9-10",
     "D9-15 y P9-7 (finalidad cerrada; hechos receivable.* solo en collections)", "D9-12 (horario fuera de la ventana legal: bloqueante)"],
    [SOURCE_CONTRACT],
    contentFormat=CONTENT_FORMAT,
    requiredKeys=CONTENT_KEYS_REQUIRED,
    optionalKeys=CONTENT_KEYS_OPTIONAL,
    stepRequiredKeys=STEP_KEYS_REQUIRED,
    stepOptionalKeys=STEP_KEYS_OPTIONAL,
    limits=STRATEGY_LIMITS,
    errorCodes=STRATEGY_ERROR_CODES,
    catalogVersion=CATALOG_VERSION,
    catalogHash=CATALOG_HASH,
    dynamicDomains=DYN,
    interface={
        "read": "StrategyContent::fromArray(array) (lector estricto) → {canonical | null, errors [{key, code}] con la raíz «content»}; las condiciones se validan con la gramática de conditions-cases.json bajo su clave (content.audience…, content.sequence.N.guard…).",
        "hash": "contentHash = sha256(StudioContentHasher::canonicalJson(canonical)); es el content_hash de la versión (§6.1)",
    },
    decisions={
        "DF-E1": "El contenido lleva `format: \"strategy-content/1\"` (obligatorio) y las 10 claves de D9-6; exclusions y schedule son opcionales (null = sin ellas); success es obligatorio. Cualquier otra clave, unexpected_key.",
        "DF-E2": "limits = {maxTouches 1–6, enrollmentDays 1–60, newEnrollmentsPerDay 1–500, contactsPerDay 1–500, cooldownDays 0–365}, las cinco obligatorias (el tope de inscripciones nuevas al día es 500, el de la pasada de D9-8: ratificada por el lead, L9-18), más una opcional SOLO en commercial: commercialBlockOverdueDays 0–365, que endurece el umbral del bloqueo por cartera vencida (L9-20: el efectivo es el menor entre el servidor y la estrategia); en collections o service es unexpected_key.",
        "DF-E3": "trigger.event: un evento del catálogo disparable y disponible (si no, trigger_not_allowed) cuya finalidad admita la de la estrategia (si no, purpose_mismatch); sus params, con el esquema del catálogo (enteros en rango, obligatorios, sin claves de más; min ≤ max → constraint_violated).",
        "DF-E4": "Un hecho cuya lista purposes no incluye la finalidad de la estrategia es fact_purpose_not_allowed en su ruta (receivable.* fuera de collections: D9-4 «solo collections»).",
        "DF-E5": "Paso: id ^[a-z0-9][a-z0-9_-]{0,31}$ único; delay {n ≥ 0, unit, from: enrollment|previous} y como mucho 60 días; channels 1–3 sin repetir de stepChannels; templates: exactamente una referencia {uuid, hash} por canal (falta → template_missing; de más → unexpected_key); hash 64 hex en minúscula y uuid en minúscula (si no, invalid_format).",
        "DF-E6": "on: {resultado: transición} con resultados del conjunto (si no, unexpected_key), transiciones del conjunto (value_not_in_domain) y permitidas para ese resultado (transition_not_allowed); si alguna lleva a wait, el paso tiene que traer waitFor {n, unit} de como mucho 14 días.",
        "DF-E7": "schedule = {days: días ISO 1–6 sin repetir (el 7, domingo, no existe: value_not_in_domain), windows: 1–4 franjas {from, to} en minutos con from < to}; una franja que no toca la ventana legal (termina antes de las 08:00 o empieza a las 19:00 o después) es schedule_outside_legal_window (bloqueante de D9-12). La forma canónica ordena days.",
        "DF-E8": "studio: kind version exige uuid (minúscula) y contentHash (64 hex); kind baseline exige catalogVersion (AAAA-MM-DD.N) y catalogHash (64 hex). canary = {channel: whatsapp|voice}.",
    },
))

T_UUID_1, T_UUID_2 = "7e000000-0000-4000-8000-000000000001", "7e000000-0000-4000-8000-000000000002"
T_HASH_1, T_HASH_2 = sha256_hex("h9fixture-template-wa-renov-v1"), sha256_hex("h9fixture-template-asistido-v1")
STUDIO_BASELINE = {"kind": "baseline", "catalogVersion": "2026-10-19.1", "catalogHash": sha256_hex("h9fixture-legacy-baseline-v1")}
GOOD = {
    "format": CONTENT_FORMAT,
    "goal": "renew",
    "purpose": "commercial",
    "priority": 60,
    "trigger": {"event": "membership.expiring_window", "params": {"daysBefore": 7}},
    "audience": {"all": [F_("membership.active", "eq", True), F_("membership.frozen", "eq", False)]},
    "exclusions": {"event": "human_requested", "op": "exists", "within": W(30)},
    "success": {"any": [F_("membership.days_to_expiry", "gt", 30), {"event": "membership_renewed", "op": "exists", "within": W(14)}]},
    "sequence": [
        {"id": "aviso", "delay": {"n": 0, "unit": "hours", "from": "enrollment"}, "channels": ["whatsapp_template", "assisted:whatsapp"],
         "templates": {"whatsapp_template": {"uuid": T_UUID_1, "hash": T_HASH_1}, "assisted:whatsapp": {"uuid": T_UUID_2, "hash": T_HASH_2}},
         "on": {"no_answer": "next_step", "answered": "end_success"}},
        {"id": "recordatorio", "delay": {"n": 3, "unit": "days", "from": "previous"}, "channels": ["assisted:whatsapp"],
         "templates": {"assisted:whatsapp": {"uuid": T_UUID_2, "hash": T_HASH_2}}, "guard": F_("membership.days_to_expiry", "gte", 0),
         "on": {"callback_requested": "wait"}, "waitFor": W(2)},
    ],
    "limits": {"maxTouches": 3, "enrollmentDays": 14, "newEnrollmentsPerDay": 100, "contactsPerDay": 50, "cooldownDays": 30},
    "schedule": {"days": [5, 1, 2, 3, 4], "windows": [{"from": 540, "to": 1080}]},
    "canary": {"channel": "whatsapp"},
    "studio": STUDIO_BASELINE,
}


def SVALID(desc, content, *, ref="D9-6", basis="contrato", decision=None, check=None):
    canon, errs = strategy_ref(content)
    assert not errs, (desc, errs)
    if check:
        check(canon)
    cj = canonical(canon)
    ST.add("valid", "content", desc, ref, basis, {"input": content},
           {"canonical": canon, "canonicalJson": cj, "contentHash": sha256_hex(cj), "canonicalBytes": len(cj.encode()), "errors": []}, decision=decision)
    return sha256_hex(cj)


def SINVALID(desc, content, errors, *, ref="D9-6", basis="contrato", decision=None):
    canon, errs = strategy_ref(content)
    want = sorted(({"key": k, "code": c} for k, c in errors), key=lambda e: (e["key"].encode(), e["code"].encode()))
    assert errs == want, (desc, "referencia:", errs, "literal:", want)
    ST.add("invalid", "content", desc, ref, basis, {"input": content},
           {"http": 422, "canonical": None, "errors": want, "errorKeys": sorted({e["key"] for e in want}, key=lambda s: s.encode())}, decision=decision)


def mut(path, value):
    """Una copia de GOOD con `path` (lista de claves) cambiado; DEL lo quita."""
    c = copy.deepcopy(GOOD)
    cur = c
    for k in path[:-1]:
        cur = cur[k]
    if value is DEL:
        del cur[path[-1]]
    else:
        cur[path[-1]] = value
    return c


DEL = object()

H_GOOD = SVALID("renovación a 7 días con dos pasos (WhatsApp por plantilla y asistido), horario propio y línea base del Studio", GOOD,
                ref="D9-6, P9-10", check=lambda c: c["schedule"]["days"] == [1, 2, 3, 4, 5] and c["audience"]["all"][0]["fact"] == "membership.active")
SVALID("sin exclusions ni schedule (opcionales)", {k: v for k, v in GOOD.items() if k not in ("exclusions", "schedule")}, basis="decision-fixture", decision="DF-E1")
SVALID("exclusions null = sin exclusiones propias (las de N1 y N2 se añaden siempre)", mut(["exclusions"], None), basis="decision-fixture", decision="DF-E1")
SVALID("cobranza con hechos de cartera y disparador receivable.overdue", dict(mut(["purpose"], "collections"), goal="collect_payment",
                                                                              trigger={"event": "receivable.overdue", "params": {"daysMin": 8, "daysMax": 30}},
                                                                              audience=F_("receivable.overdue_days_max", "between", BTW(8, 30))),
       ref="D9-6, D9-15", basis="decision-fixture", decision="DF-E4")
SVALID("referencia a una versión del Studio aprobada", mut(["studio"], {"kind": "version", "uuid": "9a000000-0000-4000-8000-000000000012",
                                                                     "contentHash": sha256_hex("h9fixture-studio-v12")}), ref="D9-11.4", decision="DF-E8",
       basis="decision-fixture")
SVALID("comercial que endurece el bloqueo por cartera vencida a 7 días (limits.commercialBlockOverdueDays)",
       mut(["limits"], dict(GOOD["limits"], commercialBlockOverdueDays=7)), ref="P9-7, L9-20", basis="supuesto-lead", decision="DF-E2")
SVALID("vencidos entre 1 y 30 días: params en sus extremos", mut(["trigger"], {"event": "membership.expired_window", "params": {"daysAfterMin": 1, "daysAfterMax": 30}}),
       ref="#3 de §13.1")
SVALID("vence hoy: daysBefore 0 (#2)", mut(["trigger"], {"event": "membership.expiring_window", "params": {"daysBefore": 0}}), ref="#2 de §13.1",
       basis="decision-fixture", decision="DF-K1")
SVALID("seguimiento de prospecto (sin params)", dict(mut(["trigger"], {"event": "lead.followup_due"}), goal="close_plan",
                                                     audience=F_("lead.consent_status", "eq", "granted")), ref="#6 de §13.1")
SVALID("invitación a clase con cupo", dict(mut(["trigger"], {"event": "class.upcoming_with_capacity", "params": {"withinDays": 7}}), goal="increase_adherence",
                                           audience={"all": [F_("class.reserved_next_7d", "eq", False), {"not": F_("valuation.has_upcoming", "eq", True)}]}),
       ref="#7 de §13.1")
SVALID("seis pasos (el límite)", mut(["sequence"], [dict(GOOD["sequence"][1], id=f"p{i}") for i in range(6)]), ref="D9-6 (≤ 6 pasos)")
SVALID("tres canales en un paso (el límite)", mut(["sequence"], [dict(GOOD["sequence"][0], channels=["whatsapp_template", "voice_call", "assisted:voice"],
                                                                       templates={"whatsapp_template": {"uuid": T_UUID_1, "hash": T_HASH_1},
                                                                                  "voice_call": {"uuid": T_UUID_2, "hash": T_HASH_2},
                                                                                  "assisted:voice": {"uuid": T_UUID_2, "hash": T_HASH_2}})]),
       ref="D9-6 (1 a 3 canales)")
SVALID("dos franjas de horario y el sábado (los días se ordenan; una franja puede salirse en parte de la ley)", mut(["schedule"], {"days": [6, 2], "windows": [
    {"from": 480, "to": 720}, {"from": 840, "to": 1140}]}), basis="decision-fixture", decision="DF-E7")

SINVALID("una cadena libre en lugar del contenido", "renovar a los que vencen", [("content", "not_an_object")])
SINVALID("sin format ni success, con una clave de más (sql)", dict({k: v for k, v in GOOD.items() if k not in ("format", "success")}, sql="select 1"),
         [("content.format", "missing_key"), ("content.sql", "unexpected_key"), ("content.success", "missing_key")], basis="decision-fixture", decision="DF-E1")
SINVALID("format de otra versión", mut(["format"], "strategy-content/2"), [("content.format", "value_not_in_domain")], basis="decision-fixture", decision="DF-E1")
SINVALID("objetivo fuera de CommercialVocabulary::GOALS", mut(["goal"], "sell_more"), [("content.goal", "value_not_in_domain")])
SINVALID("finalidad inventada", mut(["purpose"], "marketing"), [("content.purpose", "value_not_in_domain")])
SINVALID("priority 0", mut(["priority"], 0), [("content.priority", "value_out_of_range")])
SINVALID("priority 101", mut(["priority"], 101), [("content.priority", "value_out_of_range")])
SINVALID("disparador que solo detiene (human_requested)", mut(["trigger"], {"event": "human_requested"}), [("content.trigger.event", "trigger_not_allowed")],
         ref="D9-3 (human_requested solo puede detener)")
SINVALID("una queja (complaint_created) no abre un contacto automático", mut(["trigger"], {"event": "complaint_created"}),
         [("content.trigger.event", "trigger_not_allowed")], basis="decision-fixture", decision="DF-K4")
SINVALID("disparador de integración (W9) el 15-11", mut(["trigger"], {"event": "integration.meta_status"}), [("content.trigger.event", "trigger_not_allowed")],
         basis="decision-fixture", decision="DF-E3")
SINVALID("disparador de cobranza en una estrategia comercial", mut(["trigger"], {"event": "receivable.overdue", "params": {"daysMin": 1, "daysMax": 30}}),
         [("content.trigger.event", "purpose_mismatch")], ref="D9-15 (cobranza y comercial nunca se mezclan)")
SINVALID("daysBefore 61 y un parámetro de más", mut(["trigger"], {"event": "membership.expiring_window", "params": {"daysBefore": 61, "hour": 9}}),
         [("content.trigger.params.daysBefore", "value_out_of_range"), ("content.trigger.params.hour", "unexpected_key")], ref="D9-3 (esquema de parámetros)")
SINVALID("expired_window con min > max", mut(["trigger"], {"event": "membership.expired_window", "params": {"daysAfterMin": 30, "daysAfterMax": 1}}),
         [("content.trigger.params", "constraint_violated")], basis="decision-fixture", decision="DF-K2")
SINVALID("expired_window sin daysAfterMax", mut(["trigger"], {"event": "membership.expired_window", "params": {"daysAfterMin": 3}}),
         [("content.trigger.params.daysAfterMax", "missing_key")])
SINVALID("hechos de cartera en una estrategia comercial (P9-7)", mut(["audience"], F_("receivable.has_overdue", "eq", False)),
         [("content.audience.fact", "fact_purpose_not_allowed")], ref="D9-4 (cartera: solo collections), P9-7", basis="decision-fixture", decision="DF-E4")
SINVALID("la audiencia con un hecho desconocido y una exclusión con SQL: las claves de las dos", dict(mut(["audience"], F_("users.phone", "eq", "x")),
                                                                                                      exclusions={"sql": "1=1"}),
         [("content.audience.fact", "unknown_fact"), ("content.exclusions", "unknown_node")], ref="D9-5, T11")
SINVALID("secuencia vacía", mut(["sequence"], []), [("content.sequence", "empty_values")])
SINVALID("siete pasos", mut(["sequence"], [dict(GOOD["sequence"][1], id=f"p{i}") for i in range(7)]), [("content.sequence", "too_many_values")],
         ref="D9-6 (≤ 6 pasos)")
SINVALID("dos pasos con el mismo id", mut(["sequence"], [GOOD["sequence"][1], GOOD["sequence"][1]]), [("content.sequence.1.id", "duplicate_value")],
         basis="decision-fixture", decision="DF-E5")
SINVALID("cuatro canales en un paso y uno repetido", mut(["sequence"], [dict(GOOD["sequence"][0], channels=["whatsapp_template", "assisted:whatsapp",
                                                                                                           "whatsapp_template", "voice_call"])]),
         [("content.sequence.0.channels", "too_many_values"), ("content.sequence.0.channels.2", "duplicate_value"),
          ("content.sequence.0.templates.voice_call", "template_missing")], ref="D9-6 (1 a 3)", basis="decision-fixture", decision="DF-E5")
SINVALID("un canal que no existe (sms) y app_push (EV9)", mut(["sequence"], [dict(GOOD["sequence"][0], channels=["sms", "app_push"])]),
         [("content.sequence.0.channels.0", "value_not_in_domain"), ("content.sequence.0.channels.1", "value_not_in_domain"),
          ("content.sequence.0.templates.assisted:whatsapp", "unexpected_key"), ("content.sequence.0.templates.whatsapp_template", "unexpected_key")],
         ref="D9-9.1 (app_push queda para EV9)", basis="decision-fixture", decision="DF-E5")
SINVALID("falta la plantilla de un canal y el hash no es hex64", mut(["sequence"], [dict(GOOD["sequence"][0], templates={
    "whatsapp_template": {"uuid": T_UUID_1, "hash": T_HASH_1.upper()}})]),
         [("content.sequence.0.templates.assisted:whatsapp", "template_missing"), ("content.sequence.0.templates.whatsapp_template.hash", "invalid_format")],
         ref="D9-6 (una referencia uuid + hash por canal)", basis="decision-fixture", decision="DF-E5")
SINVALID("on con una transición no permitida (no_answer → end_success) y un resultado inventado",
         mut(["sequence"], [dict(GOOD["sequence"][0], on={"no_answer": "end_success", "maybe": "end_exit"})]),
         [("content.sequence.0.on.maybe", "unexpected_key"), ("content.sequence.0.on.no_answer", "transition_not_allowed")], ref="D9-10 (tabla), T23")
SINVALID("on de opted_out a next_step: ninguna estrategia lo anula", mut(["sequence"], [dict(GOOD["sequence"][0], on={"opted_out": "next_step"})]),
         [("content.sequence.0.on.opted_out", "transition_not_allowed")], ref="D9-10, #10")
SINVALID("on de replied a next_step: nunca otro contacto automático", mut(["sequence"], [dict(GOOD["sequence"][0], on={"replied": "next_step"})]),
         [("content.sequence.0.on.replied", "transition_not_allowed")], ref="D9-10 (replied)")
SINVALID("wait sin waitFor", mut(["sequence"], [dict({k: v for k, v in GOOD["sequence"][1].items() if k != "waitFor"})]),
         [("content.sequence.0.waitFor", "missing_key")], basis="decision-fixture", decision="DF-E6")
SINVALID("waitFor de 15 días (≤ 14)", mut(["sequence"], [dict(GOOD["sequence"][1], waitFor=W(15))]), [("content.sequence.0.waitFor", "window_out_of_range")],
         ref="D9-10 (wait ≤ 14 días)")
SINVALID("delay de 61 días y unidad months", mut(["sequence"], [dict(GOOD["sequence"][1], delay={"n": 61, "unit": "days", "from": "previous"}),
                                                                dict(GOOD["sequence"][1], id="otro", delay={"n": 1, "unit": "months", "from": "previous"})]),
         [("content.sequence.0.delay", "window_out_of_range"), ("content.sequence.1.delay.unit", "value_not_in_domain")], basis="decision-fixture",
         decision="DF-E5")
SINVALID("id de paso con mayúsculas y espacios", mut(["sequence"], [dict(GOOD["sequence"][1], id="Paso 1")]), [("content.sequence.0.id", "invalid_format")],
         basis="decision-fixture", decision="DF-E5")
SINVALID("guarda del paso con un operador que no casa", mut(["sequence"], [dict(GOOD["sequence"][1], guard=F_("membership.active", "gt", True))]),
         [("content.sequence.0.guard.op", "operator_not_allowed")], ref="D9-6 (guard), D9-5")
SINVALID("cobranza con commercialBlockOverdueDays (solo es de commercial) → unexpected_key",
         dict(mut(["purpose"], "collections"), goal="collect_payment", trigger={"event": "receivable.overdue", "params": {"daysMin": 8, "daysMax": 30}},
              audience=F_("receivable.overdue_days_max", "between", BTW(8, 30)), limits=dict(GOOD["limits"], commercialBlockOverdueDays=7)),
         [("content.limits.commercialBlockOverdueDays", "unexpected_key")], ref="P9-7, L9-20", basis="supuesto-lead", decision="DF-E2")
SINVALID("commercialBlockOverdueDays negativo → value_out_of_range", mut(["limits"], dict(GOOD["limits"], commercialBlockOverdueDays=-1)),
         [("content.limits.commercialBlockOverdueDays", "value_out_of_range")], ref="L9-20", basis="supuesto-lead", decision="DF-E2")
SINVALID("límites por encima del contrato (7 contactos, 61 días) y uno de menos", mut(["limits"], {"maxTouches": 7, "enrollmentDays": 61,
                                                                                              "newEnrollmentsPerDay": 100, "contactsPerDay": 50}),
         [("content.limits.cooldownDays", "missing_key"), ("content.limits.enrollmentDays", "value_out_of_range"), ("content.limits.maxTouches", "value_out_of_range")],
         ref="D9-6 (≤ 6 contactos, ≤ 60 días)", basis="decision-fixture", decision="DF-E2")
SINVALID("horario con domingo y una franja nocturna (fuera de la ley)", mut(["schedule"], {"days": [7, 1], "windows": [{"from": 1200, "to": 1320}]}),
         [("content.schedule.days.0", "value_not_in_domain"), ("content.schedule.windows.0", "schedule_outside_legal_window")],
         ref="D9-6 (solo estrecha), D9-12 (horario fuera de la ventana legal: bloqueante)", basis="decision-fixture", decision="DF-E7")
SINVALID("franja con from ≥ to", mut(["schedule"], {"days": [1], "windows": [{"from": 720, "to": 600}]}), [("content.schedule.windows.0", "invalid_between")],
         basis="decision-fixture", decision="DF-E7")
SINVALID("canario de un canal que no existe", mut(["canary"], {"channel": "sms"}), [("content.canary.channel", "value_not_in_domain")], basis="decision-fixture",
         decision="DF-E8")
SINVALID("referencia del Studio de otro tipo", mut(["studio"], {"kind": "draft"}), [("content.studio.kind", "value_not_in_domain")], ref="D9-11.4")
SINVALID("referencia de versión con uuid en mayúsculas y sin contentHash", mut(["studio"], {"kind": "version", "uuid": "9A000000-0000-4000-8000-000000000012"}),
         [("content.studio.contentHash", "missing_key"), ("content.studio.uuid", "invalid_format")], ref="D9-11.4", basis="decision-fixture", decision="DF-E8")
SINVALID("línea base con una versión del catálogo mal escrita", mut(["studio"], dict(STUDIO_BASELINE, catalogVersion="v1")),
         [("content.studio.catalogVersion", "invalid_format")], ref="D9-11.4, P9-10", basis="decision-fixture", decision="DF-E8")
_big = mut(["audience"], {"any": [F_("membership.plan_id", "in", list(range(11, 61))) for _ in range(40)]})
for _i in range(5):
    _big["sequence"].append(dict(GOOD["sequence"][1], id=f"b{_i}", guard={"any": [F_("membership.plan_id", "in", list(range(11, 61))) for _ in range(40)]}))
_big["sequence"] = _big["sequence"][:6]
SINVALID("contenido canónico de más de 32 KB", _big, [("content", "too_large")], ref="D9-6 (≤ 32 KB)")


def SVER(desc, a, b, same, *, ref="§6.1 (content_hash), D9-6 (plantillas versionadas)", basis="contrato", decision=None):
    ca, ea = strategy_ref(a)
    cb, eb = strategy_ref(b)
    assert not ea and not eb
    ha, hb = content_hash(ca), content_hash(cb)
    assert (ha == hb) == same, (desc, ha, hb)
    ST.add("versions", "pair", desc, ref, basis, {"a": a, "b": b}, {"sameContentHash": same, "hashA": ha, "hashB": hb}, decision=decision)


SVER("el mismo contenido con las claves en otro orden → la misma huella", GOOD, json.loads(json.dumps(GOOD, sort_keys=True)), True)
SVER("la misma estrategia con la versión siguiente de una plantilla (otro uuid y otro hash) → otra huella (una versión nueva)", GOOD,
     mut(["sequence"], [dict(GOOD["sequence"][0], templates={"whatsapp_template": {"uuid": "7e000000-0000-4000-8000-000000000003",
                                                                                   "hash": sha256_hex("h9fixture-template-wa-renov-v2")},
                                                              "assisted:whatsapp": {"uuid": T_UUID_2, "hash": T_HASH_2}}), GOOD["sequence"][1]]), False,
     ref="D9-6 («una plantilla no se edita: se crea su versión siguiente»)")
SVER("los días del horario en otro orden → la misma huella (se ordenan)", GOOD, mut(["schedule"], {"days": [1, 2, 3, 4, 5], "windows": [{"from": 540, "to": 1080}]}),
     True, basis="decision-fixture", decision="DF-E7")
SVER("los canales de un paso en otro orden → otra huella (el orden es la preferencia)", GOOD,
     mut(["sequence"], [dict(GOOD["sequence"][0], channels=["assisted:whatsapp", "whatsapp_template"]), GOOD["sequence"][1]]), False, ref="D9-6 (lista ordenada)")
SVER("una condición de la audiencia en otra forma equivalente (all de un hijo) → la misma huella", mut(["audience"], F_("membership.active", "eq", True)),
     mut(["audience"], {"all": [F_("membership.active", "eq", True)]}), True, ref="D9-5 (canonicalización)")

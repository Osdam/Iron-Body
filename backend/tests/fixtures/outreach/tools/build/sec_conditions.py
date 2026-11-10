# conditions-cases.json · la gramática outreach-conditions/1 (D9-5; T10, T11, T12 de §13.2)
#   canonicalize · árboles válidos → forma canónica, su JSON canónico y su huella
#   equivalence  · pares: misma condición con otro orden de claves → misma huella; otro orden de hijos → otra (D9-5)
#   reject       · 422 con TODAS las claves de error a la vez (conjunto {key, code})
#   evaluate     · árbol canónico + foto de hechos + now → true/false/unknown, traza y traceHash
#   bogotaDays   · vectores del día de Bogotá que usan las ventanas (now, at) → días atrás
# La REFERENCIA de abajo (validate_ref, evaluate_ref) solo sirve para contrastar los literales escritos a mano:
# cada caso trae su veredicto (errores, valor y resultado de cada hoja) escrito a mano, y el generador exige que la
# referencia diga lo mismo. La traza completa y las huellas las calcula la referencia.
ROOT = "conditions"
NODE_KEYS = ["all", "any", "not", "fact", "event", "count", "transition"]
ALLOWED_KEYS = {"all": {"all"}, "any": {"any"}, "not": {"not"}, "fact": {"fact", "args", "op", "value"},
                "event": {"event", "op", "within", "where"}, "count": {"count", "op", "value"}, "transition": {"transition"}}
DYN = {"class_types": ["boxeo", "cardio", "cross_training", "funcional", "pilates", "spinning", "yoga"], "plans": list(range(1, 61))}
ERROR_CODES = sorted(["not_an_object", "unknown_node", "ambiguous_node", "unexpected_key", "missing_key", "invalid_type", "empty_group",
                      "unknown_fact", "unknown_event", "unknown_operator", "operator_not_allowed", "value_not_in_domain",
                      "value_out_of_range", "value_not_integer", "value_not_finite", "empty_values", "too_many_values",
                      "duplicate_value", "invalid_between", "unit_not_allowed", "window_out_of_range", "invalid_transition",
                      "depth_exceeded", "too_many_nodes", "too_large"])
MISSING = object()


def is_obj(v):
    """Un objeto JSON tal como lo ve PHP con json_decode(…, true): un array asociativo o VACÍO ({} y [] son lo mismo, DF-G2)."""
    return isinstance(v, dict) or (isinstance(v, list) and len(v) == 0)


def as_dict(v):
    return v if isinstance(v, dict) else {}


class V:
    def __init__(self, dyn):
        self.errors = []
        self.dyn = dyn

    def err(self, key, code):
        assert code in ERROR_CODES, code
        self.errors.append((key, code))

    # números: enteros; un decimal con parte fraccionaria no; un decimal entero se normaliza (3.0 → 3, -0.0 → 0)
    def num(self, v, path):
        if isinstance(v, bool):
            self.err(path, "invalid_type")
            return None
        if isinstance(v, int):
            return v
        if isinstance(v, float):
            if not math.isfinite(v):
                self.err(path, "value_not_finite")
                return None
            if v != int(v):
                self.err(path, "value_not_integer")
                return None
            return int(v)
        self.err(path, "invalid_type")
        return None

    def window(self, w, path, allowed_units=None, max_days=LIMITS["maxWindowDays"]):
        if not is_obj(w):
            self.err(path, "invalid_type")
            return None
        w = as_dict(w)
        for k in w:
            if k not in ("n", "unit"):
                self.err(f"{path}.{k}", "unexpected_key")
        n = unit = None
        ok = True
        if "unit" not in w:
            self.err(f"{path}.unit", "missing_key")
            ok = False
        elif not isinstance(w["unit"], str):
            self.err(f"{path}.unit", "invalid_type")
            ok = False
        elif w["unit"] not in CATALOG["units"]:
            self.err(f"{path}.unit", "value_not_in_domain")
            ok = False
        elif allowed_units is not None and w["unit"] not in allowed_units:
            self.err(f"{path}.unit", "unit_not_allowed")
            ok = False
        else:
            unit = w["unit"]
        if "n" not in w:
            self.err(f"{path}.n", "missing_key")
            ok = False
        else:
            n = self.num(w["n"], f"{path}.n")
            if n is None:
                ok = False
            elif n < 1:
                self.err(f"{path}.n", "value_out_of_range")
                ok = False
        if not ok:
            return None
        if n * CATALOG["unitHours"][unit] > max_days * 24:
            self.err(path, "window_out_of_range")
            return None
        return {"n": n, "unit": unit}

    def domain_of(self, d):
        if isinstance(d, dict):
            return self.dyn[d["dynamic"]]
        return d

    def enum_value(self, domain, v, path):
        dom = self.domain_of(domain)
        want_int = all(isinstance(x, int) for x in dom)
        if want_int:
            if isinstance(v, bool) or not isinstance(v, (int, float)):
                self.err(path, "invalid_type")
                return None
            n = self.num(v, path)
            if n is None:
                return None
            v = n
        elif not isinstance(v, str):
            self.err(path, "invalid_type")
            return None
        if v not in dom:
            self.err(path, "value_not_in_domain")
            return None
        return v

    def enum_list(self, domain, v, path):
        if not isinstance(v, list) and not (isinstance(v, dict) and len(v) == 0):
            self.err(path, "invalid_type")
            return None
        v = v if isinstance(v, list) else []
        if len(v) == 0:
            self.err(path, "empty_values")
            return None
        ok = True
        if len(v) > LIMITS["maxEnumValues"]:
            self.err(path, "too_many_values")
            ok = False
        seen = []
        for i, item in enumerate(v):
            c = self.enum_value(domain, item, f"{path}.{i}")
            if c is None:
                ok = False
            elif c in seen:
                self.err(f"{path}.{i}", "duplicate_value")
                ok = False
            else:
                seen.append(c)
        if not ok:
            return None
        dom = self.domain_of(domain)
        return sorted(seen, key=lambda x: dom.index(x))

    def bounded(self, v, path, lo, hi):
        n = self.num(v, path)
        if n is None:
            return None
        if (lo is not None and n < lo) or (hi is not None and n > hi):
            self.err(path, "value_out_of_range")
            return None
        return n

    def between(self, v, path, lo, hi):
        if not is_obj(v):
            self.err(path, "invalid_type")
            return None
        v = as_dict(v)
        keys = ["max", "maxInclusive", "min", "minInclusive"]
        for k in v:
            if k not in keys:
                self.err(f"{path}.{k}", "unexpected_key")
        out, ok = {}, True
        for k in ("min", "max"):
            if k not in v:
                self.err(f"{path}.{k}", "missing_key")
                ok = False
            else:
                n = self.bounded(v[k], f"{path}.{k}", lo, hi)
                if n is None:
                    ok = False
                out[k] = n
        for k in ("minInclusive", "maxInclusive"):
            if k not in v:
                self.err(f"{path}.{k}", "missing_key")
                ok = False
            elif not isinstance(v[k], bool):
                self.err(f"{path}.{k}", "invalid_type")
                ok = False
            else:
                out[k] = v[k]
        if not ok:
            return None
        low = out["min"] + (0 if out["minInclusive"] else 1)
        high = out["max"] - (0 if out["maxInclusive"] else 1)
        if low > high:
            self.err(path, "invalid_between")
            return None
        return out

    def between_days(self, v, path):
        if not is_obj(v):
            self.err(path, "invalid_type")
            return None
        v = as_dict(v)
        for k in v:
            if k not in ("min", "max"):
                self.err(f"{path}.{k}", "unexpected_key")
        out, ok = {}, True
        for k in ("min", "max"):
            if k not in v:
                self.err(f"{path}.{k}", "missing_key")
                ok = False
            else:
                n = self.bounded(v[k], f"{path}.{k}", -LIMITS["maxWindowDays"], LIMITS["maxWindowDays"])
                if n is None:
                    ok = False
                out[k] = n
        if not ok:
            return None
        if out["min"] > out["max"]:
            self.err(path, "invalid_between")
            return None
        return out

    def op_check(self, x, path, allowed):
        if "op" not in x:
            self.err(f"{path}.op", "missing_key")
            return None
        op = x["op"]
        if not isinstance(op, str):
            self.err(f"{path}.op", "invalid_type")
            return None
        if op not in ALL_OPERATORS:
            self.err(f"{path}.op", "unknown_operator")
            return None
        if op not in allowed:
            self.err(f"{path}.op", "operator_not_allowed")
            return None
        return op

    def node(self, x, path, depth):
        if depth > LIMITS["maxDepth"]:
            self.err(path, "depth_exceeded")
            return None
        if not is_obj(x):
            self.err(path, "not_an_object")
            return None
        x = as_dict(x)
        kinds = [k for k in NODE_KEYS if k in x]
        if not kinds:
            self.err(path, "unknown_node")
            return None
        if len(kinds) > 1:
            self.err(path, "ambiguous_node")
            return None
        kind = kinds[0]
        for k in x:
            if k not in ALLOWED_KEYS[kind]:
                self.err(f"{path}.{k}", "unexpected_key")
        return getattr(self, "n_" + kind)(x, path, depth)

    def _group(self, kind, x, path, depth):
        v = x[kind]
        if not isinstance(v, list) and not (isinstance(v, dict) and len(v) == 0):
            self.err(f"{path}.{kind}", "invalid_type")
            return None
        v = v if isinstance(v, list) else []
        if not v:
            self.err(f"{path}.{kind}", "empty_group")
            return None
        kids = [self.node(c, f"{path}.{kind}.{i}", depth + 1) for i, c in enumerate(v)]
        if any(k is None for k in kids):
            return None
        return kids[0] if len(kids) == 1 else {kind: kids}

    def n_all(self, x, path, depth):
        return self._group("all", x, path, depth)

    def n_any(self, x, path, depth):
        return self._group("any", x, path, depth)

    def n_not(self, x, path, depth):
        c = self.node(x["not"], f"{path}.not", depth + 1)
        return None if c is None else {"not": c}

    def n_fact(self, x, path, depth):
        f = x["fact"]
        if not isinstance(f, str):
            self.err(f"{path}.fact", "invalid_type")
            return None
        fd = FACT_BY_KEY.get(f)
        if fd is None:
            self.err(f"{path}.fact", "unknown_fact")
            return None
        ok = True
        args = None
        if not fd["args"]:
            if "args" in x:
                self.err(f"{path}.args", "unexpected_key")
                ok = False
        else:
            a = x.get("args", {})
            if not is_obj(a):
                self.err(f"{path}.args", "invalid_type")
                ok = False
            else:
                a = as_dict(a)
                decl = {d["name"]: d for d in fd["args"]}
                for k in a:
                    if k not in decl:
                        self.err(f"{path}.args.{k}", "unexpected_key")
                        ok = False
                args = {}
                for name, d in decl.items():
                    if name not in a:
                        if d["required"]:
                            self.err(f"{path}.args.{name}", "missing_key")
                            ok = False
                        continue
                    if d["type"] == "window":
                        w = self.window(a[name], f"{path}.args.{name}")
                        if w is None:
                            ok = False
                        args[name] = w
                    else:
                        c = self.enum_value(d["domain"], a[name], f"{path}.args.{name}")
                        if c is None:
                            ok = False
                        args[name] = c
        allowed = list(OPERATORS[fd["type"]]) + OPERATORS["any"]
        if fd["type"] == "date_relative" and fd["unit"] == "hours":
            allowed = [o for o in allowed if o != "between_days"]
        op = self.op_check(x, path, allowed)
        value = MISSING
        if op is None:
            ok = False
        elif op in ("is_known", "is_unknown"):
            if "value" in x:
                self.err(f"{path}.value", "unexpected_key")
                ok = False
        elif "value" not in x:
            self.err(f"{path}.value", "missing_key")
            ok = False
        else:
            value = self.fact_value(fd, op, x["value"], f"{path}.value")
            if value is None:
                ok = False
        if not ok:
            return None
        out = {"fact": f, "op": op}
        if args:
            out["args"] = args
        if value is not MISSING:
            out["value"] = value
        return out

    def fact_value(self, fd, op, v, path):
        t = fd["type"]
        if t == "bool":
            if not isinstance(v, bool):
                self.err(path, "invalid_type")
                return None
            return v
        if t in ("int", "decimal", "duration"):
            if op == "between":
                return self.between(v, path, fd["min"], fd["max"])
            return self.bounded(v, path, fd["min"], fd["max"])
        if t == "enum":
            if op in ("in", "not_in"):
                return self.enum_list(fd["domain"], v, path)
            return self.enum_value(fd["domain"], v, path)
        if t == "date_relative":
            if op == "between_days":
                return self.between_days(v, path)
            units = ["days", "weeks"] if fd["unit"] == "days" else ["days", "hours", "weeks"]
            return self.window(v, path, allowed_units=units)
        raise AssertionError(t)

    def n_event(self, x, path, depth):
        ok = True
        e = x["event"]
        ev = None
        if not isinstance(e, str):
            self.err(f"{path}.event", "invalid_type")
            ok = False
        elif e not in EVENT_BY_KEY or not EVENT_BY_KEY[e]["conditionUsable"]:
            self.err(f"{path}.event", "unknown_event")
            ok = False
        else:
            ev = EVENT_BY_KEY[e]
        op = self.op_check(x, path, OPERATORS["event"])
        if op is None:
            ok = False
        within = None
        if "within" not in x:
            self.err(f"{path}.within", "missing_key")
            ok = False
        else:
            within = self.window(x["within"], f"{path}.within")
            if within is None:
                ok = False
        where = {}
        if "where" in x and ev is not None:
            where = self.attrs(x["where"], f"{path}.where", {a["name"]: a for a in ev["attrs"]})
            if where is None:
                ok = False
        if not ok:
            return None
        out = {"event": e, "op": op, "within": within}
        if where:
            out["where"] = where
        return out

    def attrs(self, w, path, decl):
        if not is_obj(w):
            self.err(path, "invalid_type")
            return None
        w = as_dict(w)
        out, ok = {}, True
        for k, v in w.items():
            if k not in decl:
                self.err(f"{path}.{k}", "unexpected_key")
                ok = False
                continue
            c = self.enum_value(decl[k]["domain"], v, f"{path}.{k}")
            if c is None:
                ok = False
            out[k] = c
        return out if ok else None

    def n_count(self, x, path, depth):
        ok = True
        c = x["count"]
        spec = None
        if not is_obj(c):
            self.err(f"{path}.count", "invalid_type")
            ok = False
        else:
            c = as_dict(c)
            for k in c:
                if k not in ("of", "filter", "within"):
                    self.err(f"{path}.count.{k}", "unexpected_key")
                    ok = False
            decl = None
            spec = {}
            if "of" not in c:
                self.err(f"{path}.count.of", "missing_key")
                ok = False
            elif not isinstance(c["of"], str):
                self.err(f"{path}.count.of", "invalid_type")
                ok = False
            elif c["of"] == "contact":
                decl = {d["name"]: d for d in CONTACT_FILTERS}
                spec["of"] = "contact"
            elif c["of"].startswith("event:") and c["of"][6:] in EVENT_BY_KEY and EVENT_BY_KEY[c["of"][6:]]["conditionUsable"]:
                decl = {a["name"]: a for a in EVENT_BY_KEY[c["of"][6:]]["attrs"]}
                spec["of"] = c["of"]
            else:
                self.err(f"{path}.count.of", "value_not_in_domain")
                ok = False
            if "filter" in c and decl is not None:
                f = self.attrs(c["filter"], f"{path}.count.filter", decl)
                if f is None:
                    ok = False
                elif f:
                    spec["filter"] = f
            if "within" not in c:
                self.err(f"{path}.count.within", "missing_key")
                ok = False
            else:
                w = self.window(c["within"], f"{path}.count.within")
                if w is None:
                    ok = False
                spec["within"] = w
        op = self.op_check(x, path, OPERATORS["count"])
        value = None
        if op is None:
            ok = False
        elif "value" not in x:
            self.err(f"{path}.value", "missing_key")
            ok = False
        else:
            if op == "between":
                value = self.between(x["value"], f"{path}.value", 0, LIMITS["maxCount"])
            else:
                value = self.bounded(x["value"], f"{path}.value", 0, LIMITS["maxCount"])
            if value is None:
                ok = False
        if not ok:
            return None
        return {"count": spec, "op": op, "value": value}

    def n_transition(self, x, path, depth):
        t = x["transition"]
        p = f"{path}.transition"
        if not is_obj(t):
            self.err(p, "invalid_type")
            return None
        t = as_dict(t)
        ok = True
        for k in t:
            if k not in ("entity", "from", "to", "within"):
                self.err(f"{p}.{k}", "unexpected_key")
                ok = False
        states = None
        if "entity" not in t:
            self.err(f"{p}.entity", "missing_key")
            ok = False
        elif not isinstance(t["entity"], str):
            self.err(f"{p}.entity", "invalid_type")
            ok = False
        elif t["entity"] not in ENTITIES:
            self.err(f"{p}.entity", "value_not_in_domain")
            ok = False
        else:
            states = ENTITIES[t["entity"]]
        good = {}
        for k in ("from", "to"):
            if k not in t:
                self.err(f"{p}.{k}", "missing_key")
                ok = False
            elif not isinstance(t[k], str):
                self.err(f"{p}.{k}", "invalid_type")
                ok = False
            elif states is not None and t[k] not in states:
                self.err(f"{p}.{k}", "value_not_in_domain")
                ok = False
            elif states is not None:
                good[k] = t[k]
        if len(good) == 2 and good["from"] == good["to"]:
            self.err(p, "invalid_transition")
            ok = False
        within = None
        if "within" not in t:
            self.err(f"{p}.within", "missing_key")
            ok = False
        else:
            within = self.window(t["within"], f"{p}.within")
            if within is None:
                ok = False
        if not ok:
            return None
        return {"transition": {"entity": t["entity"], "from": t["from"], "to": t["to"], "within": within}}


def count_nodes(x):
    if not isinstance(x, dict):
        return 0
    n = 1
    for k in ("all", "any"):
        if k in x and isinstance(x[k], list):
            n += sum(count_nodes(c) for c in x[k])
    if "not" in x:
        n += count_nodes(x["not"])
    return n


def validate_ref(tree, dyn=DYN):
    v = V(dyn)
    canon = v.node(tree, ROOT, 1)
    if count_nodes(tree) > LIMITS["maxNodes"]:
        v.err(ROOT, "too_many_nodes")
        canon = None
    if not v.errors and canon is not None and len(canonical(canon).encode()) > LIMITS["maxCanonicalBytes"]:
        v.err(ROOT, "too_large")
        canon = None
    errs = sorted(set(v.errors), key=lambda e: (e[0].encode(), e[1].encode()))
    return (None if errs else canon), [{"key": k, "code": c} for k, c in errs]


# ── Evaluación (sobre el árbol CANÓNICO) ────────────────────────────────────
T, F, U = "true", "false", "unknown"


def bogota_days_ago(at, now):
    return (bog_date(now) - bog_date(at)).days


def in_window(at, now, w):
    if parse_iso(at) > parse_iso(now):
        return False
    if w["unit"] == "hours":
        return (parse_iso(now) - parse_iso(at)).total_seconds() <= w["n"] * 3600
    return bogota_days_ago(at, now) <= w["n"] * CATALOG["unitDays"][w["unit"]]


def contact_match(c, flt):
    for k, v in flt.items():
        if k == "strategy":
            if (v == "self") != (c.get("strategy") == "self"):
                return False
        elif c.get(k) != v:
            return False
    return True


def history_value(key, args, snap, now):
    contacts = snap.get("contacts")
    if contacts is None:
        return None
    flt = {k: v for k, v in (args or {}).items() if k != "within"}
    past = [c for c in contacts if parse_iso(c["at"]) <= parse_iso(now) and contact_match(c, flt)]
    if key == "contact.count":
        return sum(1 for c in past if in_window(c["at"], now, args["within"]))
    if not past:
        return None
    latest = None
    for c in past:
        if latest is None or parse_iso(c["at"]) >= parse_iso(latest["at"]):
            latest = c
    if key == "contact.days_since_last":
        return bogota_days_ago(latest["at"], now)
    return latest.get("outcome")


def compare(fd, op, obs, exp):
    if op == "is_known":
        return T if obs is not None else F
    if op == "is_unknown":
        return T if obs is None else F
    if obs is None:
        return U
    t = fd["type"]
    if t == "date_relative":
        if op == "between_days":
            r = exp["min"] <= obs <= exp["max"]
        else:
            factor = CATALOG["unitHours"][exp["unit"]] if fd["unit"] == "hours" else CATALOG["unitDays"][exp["unit"]]
            n = exp["n"] * factor
            r = {"within_next": 0 <= obs <= n, "within_last": -n <= obs <= 0, "more_than_ago": obs < -n}[op]
        return T if r else F
    r = num_compare(op, obs, exp)
    return T if r else F


def num_compare(op, obs, exp):
    if op == "eq":
        return obs == exp
    if op == "neq":
        return obs != exp
    if op == "lt":
        return obs < exp
    if op == "lte":
        return obs <= exp
    if op == "gt":
        return obs > exp
    if op == "gte":
        return obs >= exp
    if op == "between":
        lo = obs >= exp["min"] if exp["minInclusive"] else obs > exp["min"]
        hi = obs <= exp["max"] if exp["maxInclusive"] else obs < exp["max"]
        return lo and hi
    if op == "in":
        return obs in exp
    if op == "not_in":
        return obs not in exp
    raise AssertionError(op)


def trace_observed(fd, obs):
    if obs is None or fd["trace"] != "bucket":
        return obs
    for b in fd["buckets"]:
        if obs >= b["min"] and (b["max"] is None or obs <= b["max"]):
            return b["label"]
    raise AssertionError(obs)


def ev_node(n, path, snap, now, trace):
    if "all" in n or "any" in n:
        kind = "all" if "all" in n else "any"
        entry = {"path": path, "kind": kind, "observed": None, "op": None, "expected": None, "result": None}
        trace.append(entry)
        rs = [ev_node(c, f"{path}.{kind}.{i}", snap, now, trace) for i, c in enumerate(n[kind])]
        if kind == "all":
            r = F if F in rs else (U if U in rs else T)
        else:
            r = T if T in rs else (U if U in rs else F)
        entry["result"] = r
        return r
    if "not" in n:
        entry = {"path": path, "kind": "not", "observed": None, "op": None, "expected": None, "result": None}
        trace.append(entry)
        r = ev_node(n["not"], f"{path}.not", snap, now, trace)
        entry["result"] = {T: F, F: T, U: U}[r]
        return entry["result"]
    if "fact" in n:
        fd = FACT_BY_KEY[n["fact"]]
        if n["fact"] in HISTORY_FACTS:
            obs = history_value(n["fact"], n.get("args"), snap, now)
        else:
            obs = snap.get("facts", {}).get(n["fact"])
        r = compare(fd, n["op"], obs, n.get("value"))
        entry = {"path": path, "kind": "fact", "fact": n["fact"]}
        if "args" in n:
            entry["args"] = n["args"]
        entry.update({"observed": trace_observed(fd, obs), "op": n["op"], "expected": n.get("value"), "result": r})
        trace.append(entry)
        return r
    if "event" in n:
        evs = snap.get("events")
        if evs is None:
            obs, r = None, U
        else:
            obs = sum(1 for e in evs if e["key"] == n["event"] and in_window(e["at"], now, n["within"])
                      and all(e.get("attrs", {}).get(k) == v for k, v in n.get("where", {}).items()))
            r = T if (obs > 0) == (n["op"] == "exists") else F
        entry = {"path": path, "kind": "event", "event": n["event"]}
        if "where" in n:
            entry["where"] = n["where"]
        entry.update({"observed": obs, "op": n["op"], "expected": n["within"], "result": r})
        trace.append(entry)
        return r
    if "count" in n:
        spec = n["count"]
        if spec["of"] == "contact":
            rows = snap.get("contacts")
            match = (lambda c: contact_match(c, spec.get("filter", {})))
        else:
            rows = snap.get("events")
            key = spec["of"][6:]
            match = (lambda e: e["key"] == key and all(e.get("attrs", {}).get(k) == v for k, v in spec.get("filter", {}).items()))
        if rows is None:
            obs, r = None, U
        else:
            obs = sum(1 for c in rows if match(c) and in_window(c["at"], now, spec["within"]))
            r = T if num_compare(n["op"], obs, n["value"]) else F
        trace.append({"path": path, "kind": "count", "count": spec, "observed": obs, "op": n["op"], "expected": n["value"], "result": r})
        return r
    if "transition" in n:
        t = n["transition"]
        rows = snap.get("transitions")
        if rows is None:
            obs, r = None, U
        else:
            obs = sum(1 for x in rows if x["entity"] == t["entity"] and x["from"] == t["from"] and x["to"] == t["to"]
                      and in_window(x["at"], now, t["within"]))
            r = T if obs > 0 else F
        trace.append({"path": path, "kind": "transition", "transition": {"entity": t["entity"], "from": t["from"], "to": t["to"]},
                      "observed": obs, "op": "exists", "expected": t["within"], "result": r})
        return r
    raise AssertionError(n)


def evaluate_ref(canon, snap, now):
    trace = []
    r = ev_node(canon, ROOT, snap, now, trace)
    return r, trace


CC = Fixture("conditions-cases.json", header(
    "conditions-cases.json",
    ["D9-5 (gramática outreach-conditions/1: nodos, operadores por tipo, unidades, tres valores, límites, canonicalización, huella, evaluación y traza)",
     "D9-4 (hechos tipados; null = desconocido)", "D9-12 (misma entrada → misma traceHash)",
     "§13.2 T10 (gramática completa), T11 (nada sin tipar: 422 con todas las claves), T12 (reproducible)",
     "§13.3 (calendario: la medianoche de Bogotá)"],
    [SOURCE_CONTRACT, SOURCE_HASHER,
     {"what": "La forma del 422 «con todas las claves a la vez» y la paridad con el espejo del CRM",
      "url": "backend/app/Services/UltronStudio/StudioRuleCoherence.php (check(): errores por clave; coherence-cases.json del G)",
      "consulted": "09-11-2026, en este árbol",
      "how": "El mismo estilo: claves con puntos desde la raíz, el servidor manda y el CRM espeja la FORMA con este fixture (D9-5, «Paridad con el CRM»)."}],
    catalogVersion=CATALOG_VERSION,
    catalogHash=CATALOG_HASH,
    grammar=GRAMMAR,
    dynamicDomains=DYN,
    errorCodes=ERROR_CODES,
    interface={
        "canonicalize": "ConditionCanonicalizer: (árbol decodificado con json_decode(…, true), catálogo, dynamicDomains, raíz «conditions») → {canonical | null, errors: [{key, code}]}. Si hay errores, canonical = null.",
        "errors": "expected.errors es un CONJUNTO de pares {key, code}, escrito ordenado por key y code (bytes). La implementación puede emitirlos en otro orden, pero ni uno más ni uno menos. errorKeys = las claves sin repetir (lo que lleva el 422 de Laravel).",
        "hash": "hash = sha256(StudioContentHasher::canonicalJson(canonical)); canonicalJson es esa cadena exacta.",
        "evaluate": "ConditionEvaluator::evaluate(canonical, snapshot, now) → {value: 'true'|'false'|'unknown', trace, traceHash}; traceHash = sha256(canonicalJson(trace)). La traza es la lista PRE-ORDEN de nodos (el nodo y después sus hijos), con las rutas del árbol CANÓNICO.",
        "snapshot": "{facts: {clave: valor derivado | null}, contacts: [{at, channel, purpose, strategy, outcome}] | null, events: [{key, at, attrs}] | null, transitions: [{entity, from, to, at}] | null}. Una lista null = fuente sin datos → los nodos que la leen dan unknown. Instantes en UTC con Z.",
        "inputText": "Algunos casos traen `inputText` (el JSON crudo) en vez de `input`, para fijar números que JSON no conserva al reescribirse (3.0, 1e1, -0, 1e400): se decodifica con json_decode(inputText, true).",
    },
    traceShape={
        "all|any|not": "{path, kind, observed: null, op: null, expected: null, result}",
        "fact": "{path, kind: 'fact', fact, args? (canónicos, solo si hay), observed (valor derivado; tramo para receivable.overdue_days_max), op, expected (valor canónico; null en is_known/is_unknown), result}",
        "event": "{path, kind: 'event', event, where?, observed (cuántos casan en la ventana | null), op: exists|absent, expected: within, result}",
        "count": "{path, kind: 'count', count: {of, filter?, within}, observed (recuento | null), op, expected: value, result}",
        "transition": "{path, kind: 'transition', transition: {entity, from, to}, observed (cuántas casan | null), op: 'exists', expected: within, result}",
    },
    decisions={
        "DF-G1": "Los errores son pares {key, code} de un conjunto cerrado (errorCodes); key es la ruta con puntos desde la raíz «conditions» (all.0, not, args.within.n…); el llamador sustituye la raíz por su campo (audience, exclusions, success, sequence.N.guard).",
        "DF-G2": "Un objeto es lo que json_decode(…, true) da como array asociativo o como array VACÍO: {} y [] son lo mismo (así lo ve PHP). En la posición de un nodo, {} y [] son unknown_node; un array no vacío o un escalar, not_an_object.",
        "DF-G3": "Dependencias entre comprobaciones: un hecho o evento desconocido no deja comprobar su operador ni su valor (sí sus claves de más y, en los eventos, la ventana); un operador inválido no deja comprobar el valor; un nodo de más profundidad que el límite no se recorre. Todo lo demás se acumula.",
        "DF-G4": "Profundidad y número de nodos se miden sobre el árbol RECIBIDO (antes de colapsar), con la raíz a profundidad 1; too_many_nodes y depth_exceeded se informan aunque haya otros errores. El tamaño (too_large) se mide sobre el JSON canónico y solo cuando no hay otro error.",
        "DF-G5": "(Ratificada por el lead: L9-19.) Canonicalización: claves ordenadas (StudioContentHasher); números enteros (3.0 → 3, 1e1 → 10, -0.0 → 0; con parte fraccionaria, value_not_integer; no finitos, value_not_finite); all/any de un solo hijo se colapsan, recursivamente; los hijos NO se reordenan; not(not(X)) NO se simplifica; los valores de in/not_in se ordenan en el orden del dominio (sin repetidos); las duraciones conservan la unidad que se escribió (2 weeks ≠ 14 days); args, where y filter vacíos desaparecen.",
        "DF-G6": "Las ventanas ({n, unit}) exigen n entero ≥ 1 y como mucho 730 días (en horas, 17 520); en un hecho date_relative en días solo valen days y weeks (hours → unit_not_allowed); between_days solo en los date_relative en días.",
        "DF-G7": "between exige las cuatro claves (min, max, minInclusive, maxInclusive); un intervalo sin ningún entero dentro es invalid_between (también min = max con algún extremo abierto).",
        "DF-G8": "in/not_in: lista no vacía, ≤ 50 valores del dominio, sin repetidos (duplicate_value en la segunda aparición); el valor de un dominio dinámico de enteros (plans) es un entero.",
        "DF-C1": "Tres valores: un hecho null o ausente da unknown en cualquier operador salvo is_known/is_unknown (que nunca dan unknown); neq y not_in sobre un desconocido dan unknown, nunca true.",
        "DF-C2": "No hay cortocircuito: se evalúan todos los hijos, para que la traza explique cada hoja.",
        "DF-C3": "Ventanas sobre instantes: en horas, now − at ≤ n·3600 s; en días y semanas, días de calendario de Bogotá (fecha de Bogotá de now − la de at ≤ n·1 o n·7). Lo posterior a now no cuenta.",
        "DF-C4": "Un evento con la fuente sin datos (events null) da unknown con exists y con absent: ausencia no probada no es ausencia.",
        "DF-C5": "date_relative: within_next {n} ⇔ 0 ≤ v ≤ n; within_last {n} ⇔ −n ≤ v ≤ 0; more_than_ago {n} ⇔ v < −n; between_days ⇔ min ≤ v ≤ max; n convertido a la unidad del hecho (weeks = 7 días = 168 h; days = 24 h en un hecho en horas).",
        "DF-C6": "El valor del resultado y el de cada entrada de la traza son las cadenas 'true', 'false' y 'unknown'.",
        "DF-C7": "La traza va en pre-orden con las rutas del árbol canónico; las entradas lógicas llevan observed/op/expected a null.",
        "DF-C8": "contact.last_outcome es el resultado del contacto más reciente que casa (empate de instante: el último de la lista); si ese contacto aún no tiene resultado, desconocido.",
        "DF-C9": "Filtros de contactos: channel y purpose por igualdad; strategy = self cuenta solo los de esta estrategia y other todos los demás (también los de fuera del H9); sin strategy, todos.",
        "DF-C10": "Un count solo admite los operadores enteros (no is_known/is_unknown) y su valor va de 0 a 100; un evento solo exists/absent; una transición no lleva operador.",
        "DF-C11": "Un evento en una condición solo puede ser uno de los 27 del vocabulario (clase crm_fact); los periódicos, de resultado o de integración dan unknown_event. `within` es obligatorio en eventos, recuentos y transiciones.",
    },
))
CC.extra["errorCodes"] = ERROR_CODES

# ── Helpers de casos ────────────────────────────────────────────────────────


def F_(fact, op, value=MISSING, args=None):
    n = {"fact": fact, "op": op}
    if args is not None:
        n["args"] = args
    if value is not MISSING:
        n["value"] = value
    return n


def W(n, unit="days"):
    return {"n": n, "unit": unit}


def BTW(lo, hi, li=True, hi_inc=True):
    return {"min": lo, "max": hi, "minInclusive": li, "maxInclusive": hi_inc}


def check_valid(tree, expected_canon, dyn=DYN):
    canon, errs = validate_ref(tree, dyn)
    assert errs == [], ("se esperaba válido", errs)
    assert canon == expected_canon, ("canónico distinto", canon, expected_canon)
    return canon


def CANON(desc, tree, expected_canon, *, ref="D9-5", basis="contrato", decision=None, input_text=None):
    src = json.loads(input_text) if input_text is not None else tree
    canon = check_valid(src, expected_canon)
    cj = canonical(canon)
    assert len(cj.encode()) <= LIMITS["maxCanonicalBytes"]
    fields = {"inputText": input_text} if input_text is not None else {"input": tree}
    CC.add("canonicalize", "valid", desc, ref, basis, fields,
           {"canonical": canon, "canonicalJson": cj, "hash": sha256_hex(cj), "canonicalBytes": len(cj.encode()), "errors": []},
           decision=decision)
    return canon


def REJ(desc, tree, errors, *, ref="D9-5", basis="contrato", decision=None, input_text=None):
    src = json.loads(input_text) if input_text is not None else tree
    canon, errs = validate_ref(src)
    want = sorted(({"key": k, "code": c} for k, c in errors), key=lambda e: (e["key"].encode(), e["code"].encode()))
    assert len({(e["key"], e["code"]) for e in want}) == len(want), "error repetido en el literal"
    assert errs == want, (desc, "referencia:", errs, "literal:", want)
    fields = {"inputText": input_text} if input_text is not None else {"input": tree}
    CC.add("reject", "invalid", desc, ref, basis, fields,
           {"http": 422, "canonical": None, "errors": want, "errorKeys": sorted({e["key"] for e in want}, key=lambda s: s.encode())},
           decision=decision)


def EQV(desc, a, b, same, *, ref="D9-5 (canonicalización)", basis="contrato", decision=None, a_text=None, b_text=None):
    ca, ea = validate_ref(json.loads(a_text) if a_text else a)
    cb, eb = validate_ref(json.loads(b_text) if b_text else b)
    assert not ea and not eb, (desc, ea, eb)
    ha, hb = content_hash(ca), content_hash(cb)
    assert (ha == hb) == same, (desc, ha, hb)
    fields = {}
    fields.update({"aText": a_text} if a_text else {"a": a})
    fields.update({"bText": b_text} if b_text else {"b": b})
    CC.add("equivalence", "pair", desc, ref, basis, fields, {"sameHash": same, "hashA": ha, "hashB": hb}, decision=decision)


def leaf_results(trace):
    return [e["result"] for e in trace if e["kind"] not in ("all", "any", "not")]


def EVAL(desc, tree, snap, now, value, leaves, *, ref="D9-5 (tres valores y traza)", basis="contrato", decision=None, observed=None):
    canon, errs = validate_ref(tree)
    assert not errs, (desc, errs)
    got, trace = evaluate_ref(canon, snap, now)
    assert got == value, (desc, "referencia:", got, "literal:", value)
    assert leaf_results(trace) == leaves, (desc, "hojas referencia:", leaf_results(trace), "literal:", leaves)
    if observed is not None:
        got_obs = [e["observed"] for e in trace if e["kind"] not in ("all", "any", "not")]
        assert got_obs == observed, (desc, "observed referencia:", got_obs, "literal:", observed)
    th = content_hash(trace)
    # misma entrada → misma traza (D9-12): la referencia se repite y tiene que dar la misma huella
    assert content_hash(evaluate_ref(canon, json.loads(json.dumps(snap)), now)[1]) == th
    CC.add("evaluate", "eval", desc, ref, basis, {"input": tree, "snapshot": snap, "now": now},
           {"canonical": canon, "value": value, "leafResults": leaves, "trace": trace, "traceHash": th}, decision=decision)
    return th


def SNAP(facts=None, contacts=(), events=(), transitions=()):
    return {"facts": facts or {}, "contacts": None if contacts is None else list(contacts),
            "events": None if events is None else list(events), "transitions": None if transitions is None else list(transitions)}


NOW = bog(2026, 11, 10, 10, 0)          # martes 10-11-2026 10:00 de Bogotá = 15:00Z
assert NOW == "2026-11-10T15:00:00Z"

# ═══ canonicalize ═══════════════════════════════════════════════════════════
CANON("bool eq: ya canónico", F_("membership.active", "eq", True), F_("membership.active", "eq", True))
CANON("int lte (las claves llegan desordenadas: se ordenan)", {"value": 7, "op": "lte", "fact": "membership.days_to_expiry"},
      F_("membership.days_to_expiry", "lte", 7))
CANON("int con signo en el mínimo del hecho (−3650)", F_("membership.days_to_expiry", "gte", -3650), F_("membership.days_to_expiry", "gte", -3650))
CANON("int en el máximo del hecho (3650)", F_("membership.days_to_expiry", "lte", 3650), F_("membership.days_to_expiry", "lte", 3650))
CANON("int between cerrado en los dos extremos", F_("membership.days_since_expiry", "between", BTW(1, 30)),
      F_("membership.days_since_expiry", "between", BTW(1, 30)))
CANON("int between abierto a la derecha", F_("attendance.count_last_30d", "between", BTW(0, 4, True, False)),
      F_("attendance.count_last_30d", "between", BTW(0, 4, True, False)), decision="DF-G7", basis="decision-fixture")
CANON("between con min = max y los dos extremos cerrados (un solo valor) vale", F_("membership.plan_duration_days", "between", BTW(30, 30)),
      F_("membership.plan_duration_days", "between", BTW(30, 30)), decision="DF-G7", basis="decision-fixture")
CANON("duration con literal entero en la unidad del hecho (días)", F_("attendance.days_since_last", "gte", 14), F_("attendance.days_since_last", "gte", 14),
      decision="DF-T1", basis="decision-fixture")
CANON("duration en horas", F_("payment.pending_link_age_hours", "gt", 6), F_("payment.pending_link_age_hours", "gt", 6), decision="DF-T1",
      basis="decision-fixture")
CANON("decimal en centésimas (2,50 visitas por semana = 250)", F_("attendance.weekly_rate", "gte", 250), F_("attendance.weekly_rate", "gte", 250),
      decision="DF-T4", basis="decision-fixture")
CANON("enum eq", F_("lead.temperature", "eq", "hot"), F_("lead.temperature", "eq", "hot"))
CANON("enum in: los valores se ordenan en el orden del dominio", F_("lead.temperature", "in", ["warm", "hot", "cold"]),
      F_("lead.temperature", "in", ["cold", "hot", "warm"]), decision="DF-G5", basis="decision-fixture")
CANON("enum not_in con las 18 fases ordenadas", F_("lead.commercial_phase", "not_in", ["WON", "DO_NOT_CONTACT", "LOST"]),
      F_("lead.commercial_phase", "not_in", ["DO_NOT_CONTACT", "LOST", "WON"]), decision="DF-G5", basis="decision-fixture")
CANON("enum de dominio dinámico (plans): enteros ordenados numéricamente", F_("membership.plan_id", "in", [16, 3, 22]),
      F_("membership.plan_id", "in", [3, 16, 22]), decision="DF-G8", basis="decision-fixture")
CANON("enum in con exactamente 50 valores (el límite) vale", F_("membership.plan_id", "in", list(range(50, 0, -1))),
      F_("membership.plan_id", "in", list(range(1, 51))), decision="DF-G8", basis="decision-fixture")
CANON("enum de dominio dinámico (class_types)", F_("class.attended_type_last_60d", "eq", "spinning"),
      F_("class.attended_type_last_60d", "eq", "spinning"), decision="DF-T5", basis="decision-fixture")
CANON("el último resultado (nodo de D9-5)", F_("contact.last_outcome", "in", ["no_answer", "delivered"], {"channel": "whatsapp"}),
      F_("contact.last_outcome", "in", ["delivered", "no_answer"], {"channel": "whatsapp"}))
CANON("is_known sin value", F_("subject.is_minor", "is_known"), F_("subject.is_minor", "is_known"))
CANON("is_unknown sobre nba.open_goal (sin objetivo abierto)", F_("nba.open_goal", "is_unknown"), F_("nba.open_goal", "is_unknown"), decision="DF-T7",
      basis="decision-fixture")
CANON("date_relative within_next en días", F_("membership.end_date", "within_next", W(7)), F_("membership.end_date", "within_next", W(7)),
      decision="DF-T2", basis="decision-fixture")
CANON("date_relative within_next en semanas: la unidad se conserva (no pasa a 14 días)", F_("membership.end_date", "within_next", W(2, "weeks")),
      F_("membership.end_date", "within_next", W(2, "weeks")), decision="DF-G5", basis="decision-fixture")
CANON("date_relative more_than_ago", F_("membership.end_date", "more_than_ago", W(30)), F_("membership.end_date", "more_than_ago", W(30)),
      decision="DF-T2", basis="decision-fixture")
CANON("date_relative between_days con signo", F_("membership.end_date", "between_days", {"min": -3, "max": 3}),
      F_("membership.end_date", "between_days", {"min": -3, "max": 3}), decision="DF-G6", basis="decision-fixture")
CANON("date_relative en horas con ventana en horas", F_("lead.last_inbound_at", "within_last", W(24, "hours")),
      F_("lead.last_inbound_at", "within_last", W(24, "hours")), decision="DF-G6", basis="decision-fixture")
CANON("date_relative en horas con ventana en días (vale: 1 día = 24 h)", F_("lead.last_inbound_at", "within_last", W(1)),
      F_("lead.last_inbound_at", "within_last", W(1)), decision="DF-G6", basis="decision-fixture")
CANON("ventana de 730 días exactos (el límite)", F_("membership.end_date", "within_next", W(730)), F_("membership.end_date", "within_next", W(730)))
CANON("ventana de 104 semanas (728 días) vale", F_("membership.end_date", "within_last", W(104, "weeks")),
      F_("membership.end_date", "within_last", W(104, "weeks")), decision="DF-G6", basis="decision-fixture")
CANON("ventana de 17 520 horas (730 días) vale", F_("lead.last_inbound_at", "within_last", W(17520, "hours")),
      F_("lead.last_inbound_at", "within_last", W(17520, "hours")), decision="DF-G6", basis="decision-fixture")
CANON("contact.count con todos sus argumentos (args ordenados)",
      F_("contact.count", "lte", 2, {"within": W(7), "strategy": "self", "purpose": "commercial", "channel": "whatsapp"}),
      F_("contact.count", "lte", 2, {"channel": "whatsapp", "purpose": "commercial", "strategy": "self", "within": W(7)}))
CANON("contact.days_since_last con args vacíos: desaparecen", F_("contact.days_since_last", "gte", 2, {}),
      F_("contact.days_since_last", "gte", 2), decision="DF-G5", basis="decision-fixture")
CANON("evento exists con ventana", {"event": "payment_failed", "op": "exists", "within": W(30)},
      {"event": "payment_failed", "op": "exists", "within": W(30)})
CANON("evento absent con where (plan_id)", {"where": {"plan_id": 3}, "within": W(7), "op": "absent", "event": "payment_approved"},
      {"event": "payment_approved", "op": "absent", "where": {"plan_id": 3}, "within": W(7)})
CANON("evento con where vacío: desaparece", {"event": "human_requested", "op": "absent", "within": W(30), "where": {}},
      {"event": "human_requested", "op": "absent", "within": W(30)}, decision="DF-G5", basis="decision-fixture")
CANON("recuento de contactos previos (nodo count)", {"count": {"of": "contact", "filter": {"channel": "voice"}, "within": W(30)}, "op": "lte", "value": 2},
      {"count": {"filter": {"channel": "voice"}, "of": "contact", "within": W(30)}, "op": "lte", "value": 2})
CANON("recuento de un evento del vocabulario", {"count": {"of": "event:payment_failed", "within": W(14)}, "op": "gte", "value": 2},
      {"count": {"of": "event:payment_failed", "within": W(14)}, "op": "gte", "value": 2})
CANON("recuento con valor 100 (el límite)", {"count": {"of": "contact", "within": W(730)}, "op": "lt", "value": 100},
      {"count": {"of": "contact", "within": W(730)}, "op": "lt", "value": 100})
CANON("transición de membresía active → expired en 7 días", {"transition": {"to": "expired", "from": "active", "entity": "membership", "within": W(7)}},
      {"transition": {"entity": "membership", "from": "active", "to": "expired", "within": W(7)}})
CANON("transición de fase del lead", {"transition": {"entity": "lead_phase", "from": "CLOSING", "to": "LOST", "within": W(14)}},
      {"transition": {"entity": "lead_phase", "from": "CLOSING", "to": "LOST", "within": W(14)}})
CANON("all de un solo hijo se colapsa", {"all": [F_("membership.active", "eq", True)]}, F_("membership.active", "eq", True), decision="DF-G5",
      basis="decision-fixture")
CANON("any(all(X)) se colapsa entero", {"any": [{"all": [F_("membership.active", "eq", True)]}]}, F_("membership.active", "eq", True),
      decision="DF-G5", basis="decision-fixture")
CANON("not(not(X)) no se simplifica", {"not": {"not": F_("membership.frozen", "eq", True)}}, {"not": {"not": F_("membership.frozen", "eq", True)}},
      decision="DF-G5", basis="decision-fixture")
CANON("AND/OR/NOT anidados: los hijos conservan su orden",
      {"all": [F_("membership.active", "eq", True), {"any": [F_("membership.days_to_expiry", "lte", 7), {"not": F_("membership.frozen", "eq", False)}]}]},
      {"all": [F_("membership.active", "eq", True), {"any": [F_("membership.days_to_expiry", "lte", 7), {"not": F_("membership.frozen", "eq", False)}]}]})
CANON("número 3.0 (decimal entero) → 3", None, F_("membership.days_to_expiry", "lte", 3), decision="DF-G5", basis="decision-fixture",
      input_text='{"fact":"membership.days_to_expiry","op":"lte","value":3.0}')
CANON("número 1e1 → 10", None, F_("attendance.days_since_last", "gte", 10), decision="DF-G5", basis="decision-fixture",
      input_text='{"fact":"attendance.days_since_last","op":"gte","value":1e1}')
CANON("número -0.0 → 0", None, F_("membership.days_to_expiry", "eq", 0), decision="DF-G5", basis="decision-fixture",
      input_text='{"fact":"membership.days_to_expiry","op":"eq","value":-0.0}')
CANON("ventana con n 7.0 → 7", None, {"event": "lead_created", "op": "exists", "within": W(7)}, decision="DF-G5", basis="decision-fixture",
      input_text='{"event":"lead_created","op":"exists","within":{"n":7.0,"unit":"days"}}')


def chain(depth):
    n = F_("membership.active", "eq", True)
    for _ in range(depth - 1):
        n = {"not": n}
    return n


CANON("profundidad 5 exacta (el límite) vale", chain(5), chain(5), ref="D9-5 (profundidad ≤ 5)", decision="DF-G4", basis="decision-fixture")


def flat_any(k):
    return {"any": [F_("membership.days_to_expiry", "eq", i) for i in range(k)]}


CANON("50 nodos exactos (1 any + 49 hojas) vale", flat_any(49), flat_any(49), ref="D9-5 (nodos ≤ 50)", decision="DF-G4", basis="decision-fixture")


def sized_tree(target):
    """Un any de hojas plan_id in [...] cuyo JSON canónico mide exactamente `target` bytes (busca a mano el último hijo)."""
    full = list(range(11, 61))      # 50 valores de dos cifras
    one = len(canonical(F_("membership.plan_id", "in", full)).encode())
    shell = len(canonical({"any": []}).encode())                     # {"any":[]}
    empty_child = len(canonical(F_("membership.plan_id", "in", [])).encode())
    for k in range(1, 49):
        # k hijos llenos + 1 hijo variable, separados por comas
        rest = target - shell - k * one - k - empty_child
        for size in range(1, 51):
            for start in range(1, 61 - size + 1):
                vals = list(range(start, start + size))
                if len(",".join(str(v) for v in vals)) == rest:
                    t = {"any": [F_("membership.plan_id", "in", full) for _ in range(k)] + [F_("membership.plan_id", "in", vals)]}
                    assert len(canonical(t).encode()) == target and count_nodes(t) <= LIMITS["maxNodes"]
                    return t
    raise AssertionError("sin solución para " + str(target))


T8192 = sized_tree(8192)
CANON("JSON canónico de 8 192 bytes exactos (el límite) vale", T8192, T8192, ref="D9-5 (JSON canónico ≤ 8 192 bytes)", decision="DF-G4",
      basis="decision-fixture")
T8193 = sized_tree(8193)

# ═══ equivalence ════════════════════════════════════════════════════════════
X1 = {"all": [F_("membership.active", "eq", True), F_("membership.days_to_expiry", "between", BTW(0, 7))]}
X1_SHUFFLED = {"all": [{"value": True, "fact": "membership.active", "op": "eq"},
                       {"op": "between", "value": {"maxInclusive": True, "minInclusive": True, "max": 7, "min": 0}, "fact": "membership.days_to_expiry"}]}
EQV("las mismas claves en otro orden, a todos los niveles → la misma huella (T10)", X1, X1_SHUFFLED, True, ref="D9-5, T10")
EQV("los mismos valores de in en otro orden → la misma huella", F_("lead.temperature", "in", ["hot", "warm"]), F_("lead.temperature", "in", ["warm", "hot"]),
    True, decision="DF-G5", basis="decision-fixture")
EQV("all de un hijo frente al hijo solo → la misma huella", {"all": [F_("membership.active", "eq", True)]}, F_("membership.active", "eq", True), True)
EQV("los hijos en otro orden → OTRA huella (el orden es parte de la explicación)", X1,
    {"all": [F_("membership.days_to_expiry", "between", BTW(0, 7)), F_("membership.active", "eq", True)]}, False,
    ref="D9-5 («los hijos no se reordenan»)")
EQV("not(not(X)) frente a X → otra huella", {"not": {"not": F_("membership.frozen", "eq", True)}}, F_("membership.frozen", "eq", True), False,
    decision="DF-G5", basis="decision-fixture")
EQV("2 semanas frente a 14 días → otra huella (la unidad escrita se conserva)", F_("membership.end_date", "within_next", W(2, "weeks")),
    F_("membership.end_date", "within_next", W(14)), False, decision="DF-G5", basis="decision-fixture")
EQV("3.0 frente a 3 → la misma huella", None, F_("membership.days_to_expiry", "lte", 3), True, decision="DF-G5", basis="decision-fixture",
    a_text='{"fact":"membership.days_to_expiry","op":"lte","value":3.0}')
EQV("args vacíos frente a sin args → la misma huella", F_("contact.days_since_last", "gte", 2, {}), F_("contact.days_since_last", "gte", 2), True,
    decision="DF-G5", basis="decision-fixture")
EQV("between [5, 5] frente a eq 5 → otra huella (no se reescribe la semántica)", F_("membership.plan_duration_days", "between", BTW(5, 5)),
    F_("membership.plan_duration_days", "eq", 5), False, decision="DF-G5", basis="decision-fixture")
EQV("el hecho contact.count frente al nodo count de contactos → otra huella (misma evaluación: ver evaluate)",
    F_("contact.count", "lte", 1, {"within": W(7)}), {"count": {"of": "contact", "within": W(7)}, "op": "lte", "value": 1}, False,
    decision="DF-G5", basis="decision-fixture")

# ═══ reject ═════════════════════════════════════════════════════════════════
REJ("una cadena libre en lugar del árbol", "socios que vencen pronto y no han venido", [("conditions", "not_an_object")], ref="D9-5, T11")
REJ("null en lugar del árbol", None, [("conditions", "not_an_object")], ref="D9-5, T11")
REJ("un número en lugar del árbol", 7, [("conditions", "not_an_object")], ref="D9-5, T11")
REJ("una lista no vacía en lugar de un nodo", [F_("membership.active", "eq", True)], [("conditions", "not_an_object")], decision="DF-G2",
    basis="decision-fixture")
REJ("{} en la raíz: unknown_node (PHP no distingue {} de [])", {}, [("conditions", "unknown_node")], decision="DF-G2", basis="decision-fixture")
REJ("un nodo SQL", {"sql": "SELECT id FROM users WHERE membership_end_date < now()"}, [("conditions", "unknown_node")], ref="D9-5, T11")
REJ("un nodo de expresión regular", {"regex": "^30[0-9]{8}$", "fact": "lead.temperature"},
    [("conditions.regex", "unexpected_key"), ("conditions.op", "missing_key")], ref="D9-5, T11", decision="DF-G3",
    basis="decision-fixture")
REJ("un operador de expresión regular (matches)", F_("lead.temperature", "matches", "^h.*"), [("conditions.op", "unknown_operator")], ref="D9-5, T11")
REJ("una expresión regular como valor de un enum", F_("lead.temperature", "eq", "/h.*/"), [("conditions.value", "value_not_in_domain")], ref="D9-5, T11")
REJ("SQL como nombre de hecho", F_("users.membership_end_date < now()", "eq", True), [("conditions.fact", "unknown_fact")], ref="D9-5, T11")
REJ("un nombre de columna como hecho", F_("membership_end_date", "lte", 7), [("conditions.fact", "unknown_fact")], ref="D9-5, T11")
REJ("una entrada de la escalera (consent.commercial.whatsapp) no es un hecho de condiciones", F_("consent.commercial.whatsapp", "eq", "granted"),
    [("conditions.fact", "unknown_fact")], ref="D9-5, D9-7", decision="DF-T7", basis="decision-fixture")
REJ("hecho desconocido con operador y valor raros: solo unknown_fact (no se juzga lo demás)", F_("subject.phone", "like", {"x": 1}),
    [("conditions.fact", "unknown_fact")], decision="DF-G3", basis="decision-fixture")
REJ("otro hecho como valor (referencia entre hechos)", F_("membership.days_to_expiry", "gt", {"fact": "attendance.days_since_last"}),
    [("conditions.value", "invalid_type")], ref="D9-5 («ninguna referencia a otro hecho como valor»), T11")
REJ("aritmética como valor", F_("membership.days_to_expiry", "gt", "days_to_expiry + 3"), [("conditions.value", "invalid_type")], ref="D9-5, T11")
REJ("un número escrito como texto", F_("membership.days_to_expiry", "lte", "7"), [("conditions.value", "invalid_type")], ref="D9-5, T11")
REJ("un booleano donde va un entero", F_("membership.days_to_expiry", "lte", True), [("conditions.value", "invalid_type")], ref="D9-5, T11")
REJ("un entero donde va un booleano", F_("membership.active", "eq", 1), [("conditions.value", "invalid_type")], ref="D9-5, T11")
REJ("decimal con parte fraccionaria en un int", F_("membership.days_to_expiry", "lte", 7.5), [("conditions.value", "value_not_integer")],
    decision="DF-G5", basis="decision-fixture")
REJ("decimal 2.5 en attendance.weekly_rate (va en centésimas: 250)", F_("attendance.weekly_rate", "gte", 2.5), [("conditions.value", "value_not_integer")],
    decision="DF-T4", basis="decision-fixture")
REJ("número no finito (1e400)", None, [("conditions.value", "value_not_finite")], decision="DF-G5", basis="decision-fixture",
    input_text='{"fact":"membership.days_to_expiry","op":"lte","value":1e400}')
REJ("fuera del rango del hecho (−3651)", F_("membership.days_to_expiry", "gte", -3651), [("conditions.value", "value_out_of_range")])
REJ("operador que no casa: bool con gt", F_("membership.active", "gt", True), [("conditions.op", "operator_not_allowed")], ref="D9-5, T11")
REJ("operador que no casa: enum con lt", F_("lead.temperature", "lt", "hot"), [("conditions.op", "operator_not_allowed")], ref="D9-5, T11")
REJ("operador que no casa: int con in", F_("membership.days_to_expiry", "in", [1, 2]), [("conditions.op", "operator_not_allowed")], ref="D9-5, T11")
REJ("operador que no casa: date_relative con eq", F_("membership.end_date", "eq", 3), [("conditions.op", "operator_not_allowed")], ref="D9-5, T11")
REJ("operador que no casa: int con within_next", F_("membership.days_to_expiry", "within_next", W(7)), [("conditions.op", "operator_not_allowed")],
    ref="D9-5, T11")
REJ("between_days en un date_relative en horas", F_("lead.last_inbound_at", "between_days", {"min": -1, "max": 0}),
    [("conditions.op", "operator_not_allowed")], decision="DF-G6", basis="decision-fixture")
REJ("un operador de eventos (exists) sobre un hecho", F_("membership.active", "exists", True), [("conditions.op", "operator_not_allowed")])
REJ("sin op ni value: solo falta op (sin operador no se sabe si value hace falta)", {"fact": "membership.active"}, [("conditions.op", "missing_key")],
    decision="DF-G3", basis="decision-fixture")
REJ("con op y sin value", {"fact": "membership.active", "op": "eq"}, [("conditions.value", "missing_key")])
REJ("op no es texto", F_("membership.active", ["eq"], True), [("conditions.op", "invalid_type")])
REJ("is_known con value", F_("subject.is_minor", "is_known", True), [("conditions.value", "unexpected_key")])
REJ("una clave de más en una comparación (column)", dict(F_("membership.active", "eq", True), column="users.active"),
    [("conditions.column", "unexpected_key")])
REJ("dos nodos en uno (all y fact)", {"all": [F_("membership.active", "eq", True)], "fact": "membership.frozen", "op": "eq", "value": False},
    [("conditions", "ambiguous_node")])
REJ("all vacío", {"all": []}, [("conditions.all", "empty_group")])
REJ("any con un objeto en lugar de una lista", {"any": {"fact": "membership.active", "op": "eq", "value": True}}, [("conditions.any", "invalid_type")])
REJ("un hijo null dentro de all", {"all": [F_("membership.active", "eq", True), None]}, [("conditions.all.1", "not_an_object")])
REJ("un hijo cadena (texto libre) dentro de any", {"any": [F_("membership.active", "eq", True), "y que no sea moroso"]},
    [("conditions.any.1", "not_an_object")], ref="D9-5, T11")
REJ("in vacío", F_("lead.temperature", "in", []), [("conditions.value", "empty_values")], decision="DF-G8", basis="decision-fixture")
REJ("in con 51 valores", F_("membership.plan_id", "in", list(range(1, 52))), [("conditions.value", "too_many_values")], ref="D9-5 (≤ 50 valores)",
    decision="DF-G8", basis="decision-fixture")
REJ("in con un repetido", F_("lead.temperature", "in", ["hot", "warm", "hot"]), [("conditions.value.2", "duplicate_value")], decision="DF-G8",
    basis="decision-fixture")
REJ("in con un valor fuera del dominio", F_("lead.temperature", "in", ["hot", "tibio"]), [("conditions.value.1", "value_not_in_domain")])
REJ("plan_id como texto (el dominio plans es de enteros)", F_("membership.plan_id", "eq", "3"), [("conditions.value", "invalid_type")],
    decision="DF-G8", basis="decision-fixture")
REJ("plan_id que no está en la instantánea del dominio", F_("membership.plan_id", "eq", 999), [("conditions.value", "value_not_in_domain")],
    decision="DF-T5", basis="decision-fixture")
REJ("between con min > max", F_("membership.days_since_expiry", "between", BTW(30, 1)), [("conditions.value", "invalid_between")], decision="DF-G7",
    basis="decision-fixture")
REJ("between (5, 6) abierto en los dos extremos: ningún entero dentro", F_("membership.days_since_expiry", "between", BTW(5, 6, False, False)),
    [("conditions.value", "invalid_between")], decision="DF-G7", basis="decision-fixture")
REJ("between sin las marcas de inclusión", F_("membership.days_since_expiry", "between", {"min": 1, "max": 30}),
    [("conditions.value.maxInclusive", "missing_key"), ("conditions.value.minInclusive", "missing_key")], decision="DF-G7", basis="decision-fixture")
REJ("between con un extremo fuera de rango y una marca no booleana",
    F_("membership.days_since_expiry", "between", {"min": 1, "max": 9999, "minInclusive": "yes", "maxInclusive": True}),
    [("conditions.value.max", "value_out_of_range"), ("conditions.value.minInclusive", "invalid_type")], decision="DF-G7", basis="decision-fixture")
REJ("unidad months (ambigua)", F_("membership.end_date", "within_next", {"n": 1, "unit": "months"}), [("conditions.value.unit", "value_not_in_domain")],
    ref="D9-5 («sin meses»)")
REJ("horas en un date_relative en días", F_("membership.end_date", "within_next", W(48, "hours")), [("conditions.value.unit", "unit_not_allowed")],
    decision="DF-G6", basis="decision-fixture")
REJ("ventana de 731 días", F_("membership.end_date", "within_last", W(731)), [("conditions.value", "window_out_of_range")], ref="D9-5 (ventanas ≤ 730 días)")
REJ("ventana de 105 semanas (735 días)", F_("membership.end_date", "within_last", W(105, "weeks")), [("conditions.value", "window_out_of_range")],
    ref="D9-5 (ventanas ≤ 730 días)")
REJ("ventana de 17 521 horas", F_("lead.last_inbound_at", "within_last", W(17521, "hours")), [("conditions.value", "window_out_of_range")],
    ref="D9-5 (ventanas ≤ 730 días)", decision="DF-G6", basis="decision-fixture")
REJ("ventana con n 0", F_("membership.end_date", "within_next", W(0)), [("conditions.value.n", "value_out_of_range")], decision="DF-G6", basis="decision-fixture")
REJ("ventana con n 1.5", F_("membership.end_date", "within_next", {"n": 1.5, "unit": "days"}), [("conditions.value.n", "value_not_integer")],
    decision="DF-G6", basis="decision-fixture")
REJ("ventana sin unit y con una clave de más", F_("membership.end_date", "within_next", {"n": 7, "tz": "UTC"}),
    [("conditions.value.tz", "unexpected_key"), ("conditions.value.unit", "missing_key")], decision="DF-G6", basis="decision-fixture")
REJ("between_days fuera de ±730", F_("membership.end_date", "between_days", {"min": -731, "max": 0}), [("conditions.value.min", "value_out_of_range")],
    decision="DF-G6", basis="decision-fixture")
REJ("args en un hecho sin argumentos", F_("membership.active", "eq", True, {"channel": "whatsapp"}), [("conditions.args", "unexpected_key")])
REJ("contact.count sin within", F_("contact.count", "lte", 2, {"channel": "whatsapp"}), [("conditions.args.within", "missing_key")])
REJ("contact.count con un canal que no existe (sms) y un argumento de más (phone)",
    F_("contact.count", "lte", 2, {"channel": "sms", "phone": "+573000000000", "within": W(7)}),
    [("conditions.args.channel", "value_not_in_domain"), ("conditions.args.phone", "unexpected_key")])
REJ("recuento de 101", F_("contact.count", "lte", 101, {"within": W(7)}), [("conditions.value", "value_out_of_range")], ref="D9-5 (recuentos ≤ 100)")
REJ("un disparador periódico usado como evento de condición", {"event": "membership.expiring_window", "op": "exists", "within": W(7)},
    [("conditions.event", "unknown_event")], decision="DF-C11", basis="decision-fixture")
REJ("un evento de integración (no disponible) en una condición", {"event": "integration.meta_status", "op": "exists", "within": W(7)},
    [("conditions.event", "unknown_event")], decision="DF-C11", basis="decision-fixture")
REJ("evento sin within y con operador de enteros", {"event": "payment_failed", "op": "gt"},
    [("conditions.op", "operator_not_allowed"), ("conditions.within", "missing_key")], decision="DF-C11", basis="decision-fixture")
REJ("evento desconocido: igual se comprueban su op y su within", {"event": "sms_sent", "op": "exists", "within": W(999)},
    [("conditions.event", "unknown_event"), ("conditions.within", "window_out_of_range")], decision="DF-G3", basis="decision-fixture")
REJ("where con un atributo no declarado (amount: un importe) y un plan fuera del dominio",
    {"event": "payment_failed", "op": "exists", "within": W(30), "where": {"amount": 120000, "plan_id": 77}},
    [("conditions.where.amount", "unexpected_key"), ("conditions.where.plan_id", "value_not_in_domain")])
REJ("count de una fuente que no existe", {"count": {"of": "event:sms_sent", "within": W(7)}, "op": "lte", "value": 1},
    [("conditions.count.of", "value_not_in_domain")])
REJ("count de contactos con un resultado inventado y sin within", {"count": {"of": "contact", "filter": {"outcome": "maybe"}}, "op": "lte", "value": 1},
    [("conditions.count.filter.outcome", "value_not_in_domain"), ("conditions.count.within", "missing_key")])
REJ("count con is_known (solo operadores enteros)", {"count": {"of": "contact", "within": W(7)}, "op": "is_known"},
    [("conditions.op", "operator_not_allowed")], decision="DF-C10", basis="decision-fixture")
REJ("transición con el mismo estado de origen y destino", {"transition": {"entity": "membership", "from": "active", "to": "active", "within": W(7)}},
    [("conditions.transition", "invalid_transition")])
REJ("transición de una entidad y un estado inventados", {"transition": {"entity": "subscription", "from": "active", "to": "canceled", "within": W(7)}},
    [("conditions.transition.entity", "value_not_in_domain")])
REJ("transición con un estado que no es de la entidad y sin within", {"transition": {"entity": "payment", "from": "pending", "to": "refunded"}},
    [("conditions.transition.to", "value_not_in_domain"), ("conditions.transition.within", "missing_key")])
REJ("transición con un operador (no lleva)", {"transition": {"entity": "payment", "from": "pending", "to": "declined", "within": W(7)}, "op": "exists"},
    [("conditions.op", "unexpected_key")], decision="DF-C10", basis="decision-fixture")
REJ("profundidad 6", chain(6), [("conditions.not.not.not.not.not", "depth_exceeded")], ref="D9-5 (profundidad ≤ 5)", decision="DF-G4",
    basis="decision-fixture")
REJ("51 nodos", flat_any(50), [("conditions", "too_many_nodes")], ref="D9-5 (nodos ≤ 50)", decision="DF-G4", basis="decision-fixture")
REJ("JSON canónico de 8 193 bytes", T8193, [("conditions", "too_large")], ref="D9-5 (JSON canónico ≤ 8 192 bytes)", decision="DF-G4",
    basis="decision-fixture")
REJ("51 nodos y un hijo que no es nodo (texto): los dos errores a la vez", {"any": [F_("membership.days_to_expiry", "eq", i) for i in range(50)] + ["texto"]},
    [("conditions", "too_many_nodes"), ("conditions.any.50", "not_an_object")], decision="DF-G4", basis="decision-fixture")
REJ("siete errores en siete sitios: TODAS las claves a la vez (T11)",
    {"all": [
        F_("membership.days_to_expiry", "lte", "siete"),
        F_("lead.temperature", "gt", "hot"),
        F_("nope.fact", "eq", 1),
        {"event": "payment_failed", "op": "exists", "within": W(1, "months")},
        {"any": []},
        {"not": "socio moroso"},
        dict(F_("membership.active", "eq", True), sql="1=1"),
    ]},
    [("conditions.all.0.value", "invalid_type"), ("conditions.all.1.op", "operator_not_allowed"), ("conditions.all.2.fact", "unknown_fact"),
     ("conditions.all.3.within.unit", "value_not_in_domain"), ("conditions.all.4.any", "empty_group"), ("conditions.all.5.not", "not_an_object"),
     ("conditions.all.6.sql", "unexpected_key")], ref="D9-5 («422 con todas las claves a la vez»), T11", decision="DF-G1")

# ═══ evaluate ═══════════════════════════════════════════════════════════════
A, B_ = F_("membership.active", "eq", True), F_("subject.is_minor", "eq", False)
for facts, vals in [({"membership.active": True, "subject.is_minor": False}, ("true", "true")),
                    ({"membership.active": True, "subject.is_minor": None}, ("true", "unknown")),
                    ({"membership.active": False, "subject.is_minor": None}, ("false", "unknown")),
                    ({"membership.active": None, "subject.is_minor": None}, ("unknown", "unknown")),
                    ({"membership.active": False, "subject.is_minor": True}, ("false", "false"))]:
    a, b = vals
    all_v = "false" if "false" in vals else ("unknown" if "unknown" in vals else "true")
    any_v = "true" if "true" in vals else ("unknown" if "unknown" in vals else "false")
    EVAL(f"all({a}, {b}) = {all_v}", {"all": [A, B_]}, SNAP(facts), NOW, all_v, [a, b], ref="D9-5 (lógica de tres valores), T10",
         **({"decision": "DF-C2"} if vals == ("false", "unknown") else {}))
    EVAL(f"any({a}, {b}) = {any_v}", {"any": [A, B_]}, SNAP(facts), NOW, any_v, [a, b], ref="D9-5 (lógica de tres valores), T10")
EVAL("not(unknown) = unknown", {"not": A}, SNAP({"membership.active": None}), NOW, "unknown", ["unknown"], ref="D9-5 (not unknown = unknown)",
     decision="DF-C6")
EVAL("days_since_expiry con la membresía vigente es negativo (−5), nunca desconocido", F_("membership.days_since_expiry", "lt", 0),
     SNAP({"membership.days_since_expiry": -5}), NOW, "true", ["true"], basis="decision-fixture", decision="DF-T3")
EVAL("not(true) = false", {"not": A}, SNAP({"membership.active": True}), NOW, "false", ["true"])
EVAL("un hecho AUSENTE de la foto es desconocido, no falso", A, SNAP({}), NOW, "unknown", ["unknown"], decision="DF-T7", basis="decision-fixture")
EVAL("neq sobre un desconocido da unknown, nunca true", F_("lead.temperature", "neq", "cold"), SNAP({"lead.temperature": None}), NOW, "unknown", ["unknown"],
     decision="DF-C1", basis="decision-fixture")
EVAL("not_in sobre un desconocido da unknown", F_("lead.temperature", "not_in", ["cold"]), SNAP({}), NOW, "unknown", ["unknown"], decision="DF-C1",
     basis="decision-fixture")
EVAL("is_unknown sobre null = true (nunca unknown)", F_("subject.is_minor", "is_unknown"), SNAP({"subject.is_minor": None}), NOW, "true", ["true"])
EVAL("is_known sobre un valor = true", F_("subject.is_minor", "is_known"), SNAP({"subject.is_minor": False}), NOW, "true", ["true"])
EVAL("is_unknown: sin objetivo abierto del motor (nba.open_goal null)", F_("nba.open_goal", "is_unknown"), SNAP({"nba.open_goal": None}), NOW, "true",
     ["true"], decision="DF-T7", basis="decision-fixture")
EVAL("el estado de consentimiento «unknown» es un VALOR conocido (≠ null)",
     {"all": [F_("lead.consent_status", "eq", "unknown"), F_("lead.consent_status", "is_known")]}, SNAP({"lead.consent_status": "unknown"}), NOW,
     "true", ["true", "true"])
EVAL("lte en el borde: 7 ≤ 7", F_("membership.days_to_expiry", "lte", 7), SNAP({"membership.days_to_expiry": 7}), NOW, "true", ["true"])
EVAL("lte justo fuera: 8 ≤ 7 es falso (#1: N inscribe, N+1 no)", F_("membership.days_to_expiry", "lte", 7), SNAP({"membership.days_to_expiry": 8}), NOW,
     "false", ["false"], ref="D9-5, #1 de §13.1")
EVAL("days_to_expiry = 0 es «vence hoy»", F_("membership.days_to_expiry", "eq", 0), SNAP({"membership.days_to_expiry": 0}), NOW, "true", ["true"],
     ref="#2 de §13.1")
for obs, val in [(1, "true"), (30, "true"), (0, "false"), (31, "false")]:
    EVAL(f"between [1, 30] cerrado con {obs} (#3: los dos extremos)", F_("membership.days_since_expiry", "between", BTW(1, 30)),
         SNAP({"membership.days_since_expiry": obs}), NOW, val, [val], ref="D9-5, #3 de §13.1")
EVAL("between (1, 30) abierto con 1 → falso", F_("membership.days_since_expiry", "between", BTW(1, 30, False, False)),
     SNAP({"membership.days_since_expiry": 1}), NOW, "false", ["false"], decision="DF-G7", basis="decision-fixture")
EVAL("decimal en centésimas: 249 < 250", F_("attendance.weekly_rate", "gte", 250), SNAP({"attendance.weekly_rate": 249}), NOW, "false", ["false"],
     decision="DF-T4", basis="decision-fixture")
EVAL("enum in de dominio dinámico", F_("membership.plan_id", "in", [3, 16]), SNAP({"membership.plan_id": 16}), NOW, "true", ["true"])
EVAL("cartera: se compara el número, la traza enseña el tramo", F_("receivable.overdue_days_max", "gt", 15), SNAP({"receivable.overdue_days_max": 20}),
     NOW, "true", ["true"], decision="DF-T6", basis="decision-fixture", observed=["8-30"])
EVAL("cartera de 91 días: tramo >90", F_("receivable.overdue_days_max", "gte", 91), SNAP({"receivable.overdue_days_max": 91}), NOW, "true", ["true"],
     decision="DF-T6", basis="decision-fixture", observed=[">90"])
for obs, val in [(7, "true"), (8, "false"), (0, "true"), (-1, "false")]:
    EVAL(f"within_next 7 días con {obs}", F_("membership.end_date", "within_next", W(7)), SNAP({"membership.end_date": obs}), NOW, val, [val],
         decision="DF-C5", basis="decision-fixture")
EVAL("within_next 1 semana = 7 días (7 → verdadero)", F_("membership.end_date", "within_next", W(1, "weeks")), SNAP({"membership.end_date": 7}), NOW,
     "true", ["true"], decision="DF-C5", basis="decision-fixture")
for obs, val in [(-30, "true"), (-31, "false"), (1, "false")]:
    EVAL(f"within_last 30 días con {obs}", F_("membership.end_date", "within_last", W(30)), SNAP({"membership.end_date": obs}), NOW, val, [val],
         decision="DF-C5", basis="decision-fixture")
for obs, val in [(-31, "true"), (-30, "false")]:
    EVAL(f"more_than_ago 30 días con {obs} (estricto)", F_("membership.end_date", "more_than_ago", W(30)), SNAP({"membership.end_date": obs}), NOW,
         val, [val], decision="DF-C5", basis="decision-fixture")
for obs, val in [(-3, "true"), (3, "true"), (4, "false")]:
    EVAL(f"between_days [−3, 3] con {obs}", F_("membership.end_date", "between_days", {"min": -3, "max": 3}), SNAP({"membership.end_date": obs}), NOW,
         val, [val], decision="DF-C5", basis="decision-fixture")
for obs, val in [(-24, "true"), (-25, "false")]:
    EVAL(f"en horas: within_last 1 día = 24 h con {obs}", F_("lead.last_inbound_at", "within_last", W(1)), SNAP({"lead.last_inbound_at": obs}), NOW,
         val, [val], decision="DF-C5", basis="decision-fixture")

# Eventos y ventanas sobre instantes (días de Bogotá)
EV_PF = {"event": "payment_failed", "op": "exists", "within": W(30)}
EVAL("evento hace 30 días de Bogotá: dentro de within 30 días", EV_PF, SNAP(events=[{"key": "payment_failed", "at": bog(2026, 10, 11, 9, 0), "attrs": {}}]),
     NOW, "true", ["true"], decision="DF-C3", basis="decision-fixture", observed=[1])
EVAL("evento hace 31 días de Bogotá: fuera", EV_PF, SNAP(events=[{"key": "payment_failed", "at": bog(2026, 10, 10, 23, 0), "attrs": {}}]), NOW,
     "false", ["false"], decision="DF-C3", basis="decision-fixture", observed=[0])
NOW_MID = bog(2026, 11, 10, 0, 30)     # 00:30 de Bogotá = 05:30Z
EV_1D = {"event": "lead_created", "op": "exists", "within": W(1)}
EVAL("medianoche de Bogotá: hace 2 días de Bogotá aunque en UTC sea 1 (within 1 día → falso)", EV_1D,
     SNAP(events=[{"key": "lead_created", "at": bog(2026, 11, 8, 23, 30), "attrs": {}}]), NOW_MID, "false", ["false"],
     ref="D9-5 (días de Bogotá), §13.3 (la medianoche de Bogotá)", decision="DF-C3", basis="decision-fixture", observed=[0])
EVAL("medianoche de Bogotá: ayer a las 23:59 de Bogotá cuenta como hace 1 día (within 1 día → verdadero)", EV_1D,
     SNAP(events=[{"key": "lead_created", "at": bog(2026, 11, 9, 23, 59), "attrs": {}}]), NOW_MID, "true", ["true"],
     ref="D9-5 (días de Bogotá), §13.3", decision="DF-C3", basis="decision-fixture", observed=[1])
EV_24H = {"event": "lead_created", "op": "exists", "within": W(24, "hours")}
EVAL("ventana en horas: exactamente 24 h → dentro", EV_24H, SNAP(events=[{"key": "lead_created", "at": plus_seconds(NOW, -86400), "attrs": {}}]), NOW,
     "true", ["true"], decision="DF-C3", basis="decision-fixture", observed=[1])
EVAL("ventana en horas: 24 h y 1 s → fuera", EV_24H, SNAP(events=[{"key": "lead_created", "at": plus_seconds(NOW, -86401), "attrs": {}}]), NOW,
     "false", ["false"], decision="DF-C3", basis="decision-fixture", observed=[0])
EVAL("un evento posterior a now no cuenta (reloj inyectado distinto del de la base)", EV_24H,
     SNAP(events=[{"key": "lead_created", "at": plus_seconds(NOW, 60), "attrs": {}}]), NOW, "false", ["false"], ref="D9-5, §13.3 (reloj inyectado)",
     decision="DF-C3", basis="decision-fixture", observed=[0])
EV_ABS = {"event": "human_requested", "op": "absent", "within": W(30)}
EVAL("absent con la fuente de eventos sin datos → unknown (nunca true)", EV_ABS, SNAP(events=None), NOW, "unknown", ["unknown"],
     ref="D9-5, D9-3 (COMMERCIAL_EVENTS_ENABLED apagada = sin datos)", decision="DF-C4", basis="decision-fixture", observed=[None])
EVAL("absent con la fuente al día y sin eventos → true", EV_ABS, SNAP(events=[]), NOW, "true", ["true"], observed=[0])
EVAL("exists con la fuente sin datos → unknown", EV_PF, SNAP(events=None), NOW, "unknown", ["unknown"], decision="DF-C4", basis="decision-fixture")
EVAL("where filtra por plan_id", {"event": "payment_failed", "op": "exists", "within": W(30), "where": {"plan_id": 3}},
     SNAP(events=[{"key": "payment_failed", "at": bog(2026, 11, 9, 9, 0), "attrs": {"plan_id": 16}}]), NOW, "false", ["false"], observed=[0])

# Historial de contactos
C_WA_YDAY = {"at": bog(2026, 11, 9, 11, 0), "channel": "whatsapp", "purpose": "commercial", "strategy": "self", "outcome": "delivered"}
C_VO_3D = {"at": bog(2026, 11, 7, 10, 0), "channel": "voice", "purpose": "commercial", "strategy": None, "outcome": "no_answer"}
C_WA_8D = {"at": bog(2026, 11, 2, 10, 0), "channel": "whatsapp", "purpose": "commercial", "strategy": "other", "outcome": "read"}
HIST = [C_WA_8D, C_VO_3D, C_WA_YDAY]
EVAL("recuento de contactos previos en 7 días (2) lte 1 → falso", {"count": {"of": "contact", "within": W(7)}, "op": "lte", "value": 1}, SNAP(contacts=HIST),
     NOW, "false", ["false"], ref="D9-5 (recuento de contactos previos), T10", observed=[2])
EVAL("el hecho contact.count da lo mismo que el nodo count", F_("contact.count", "lte", 1, {"within": W(7)}), SNAP(contacts=HIST), NOW, "false", ["false"],
     observed=[2])
EVAL("contact.count solo de WhatsApp en 7 días (1)", F_("contact.count", "eq", 1, {"channel": "whatsapp", "within": W(7)}), SNAP(contacts=HIST), NOW,
     "true", ["true"], observed=[1])
EVAL("contact.count con strategy = other incluye los de fuera del H9", F_("contact.count", "eq", 2, {"strategy": "other", "within": W(14)}),
     SNAP(contacts=HIST), NOW, "true", ["true"], decision="DF-C9", basis="decision-fixture", observed=[2])
EVAL("días desde el último contacto (ayer = 1)", F_("contact.days_since_last", "gte", 2), SNAP(contacts=HIST), NOW, "false", ["false"], observed=[1])
EVAL("días desde el último contacto de voz (3)", F_("contact.days_since_last", "gte", 2, {"channel": "voice"}), SNAP(contacts=HIST), NOW, "true", ["true"],
     observed=[3])
EVAL("sin contactos, días desde el último = desconocido", F_("contact.days_since_last", "gte", 2), SNAP(contacts=[]), NOW, "unknown", ["unknown"],
     observed=[None])
EVAL("historial sin datos (contacts null): el recuento es desconocido", F_("contact.count", "eq", 0, {"within": W(7)}), SNAP(contacts=None), NOW,
     "unknown", ["unknown"], observed=[None])
EVAL("resultado del último contacto (el más reciente: delivered)", F_("contact.last_outcome", "in", ["no_answer", "delivered"]), SNAP(contacts=HIST), NOW,
     "true", ["true"], ref="D9-5 (último resultado), T10", observed=["delivered"])
EVAL("resultado del último contacto de voz (no_answer)", F_("contact.last_outcome", "eq", "no_answer", {"channel": "voice"}), SNAP(contacts=HIST), NOW,
     "true", ["true"], observed=["no_answer"])
EVAL("el último contacto aún sin resultado → desconocido", F_("contact.last_outcome", "eq", "delivered"),
     SNAP(contacts=[C_WA_8D, dict(C_WA_YDAY, outcome=None)]), NOW, "unknown", ["unknown"], decision="DF-C8", basis="decision-fixture", observed=[None])

# Transiciones
TR = {"transition": {"entity": "membership", "from": "active", "to": "expired", "within": W(7)}}
EVAL("transición active → expired hace 7 días de Bogotá: dentro", TR,
     SNAP(transitions=[{"entity": "membership", "from": "active", "to": "expired", "at": bog(2026, 11, 3, 0, 0)}]), NOW, "true", ["true"],
     ref="D9-5 (transición), T10", observed=[1])
EVAL("transición hace 8 días: fuera", TR, SNAP(transitions=[{"entity": "membership", "from": "active", "to": "expired", "at": bog(2026, 11, 2, 23, 59)}]),
     NOW, "false", ["false"], observed=[0])
EVAL("otra transición (frozen → expired) no casa", TR,
     SNAP(transitions=[{"entity": "membership", "from": "frozen", "to": "expired", "at": bog(2026, 11, 9, 0, 0)}]), NOW, "false", ["false"], observed=[0])
EVAL("transiciones sin datos → desconocido", TR, SNAP(transitions=None), NOW, "unknown", ["unknown"], observed=[None])

# Árboles de las pruebas del propietario (§13.1)
AUD_RENEW = {"all": [F_("membership.active", "eq", True), F_("membership.frozen", "eq", False),
                     {"not": {"event": "human_requested", "op": "exists", "within": W(30)}},
                     F_("contact.count", "eq", 0, {"strategy": "self", "within": W(14)})]}
EVAL("#1 renovación: vigente, sin congelar, sin pedir humano y sin contacto de esta estrategia en 14 días → true", AUD_RENEW,
     SNAP({"membership.active": True, "membership.frozen": False}, contacts=[C_WA_8D], events=[]), NOW, "true", ["true", "true", "false", "true"],
     ref="#1 de §13.1, D9-6 (audiencia)", decision="DF-C7")
EVAL("#1 renovación con la fuente de eventos sin datos → unknown (no inscribe: insufficient_facts)", AUD_RENEW,
     SNAP({"membership.active": True, "membership.frozen": False}, contacts=[], events=None), NOW, "unknown", ["true", "true", "unknown", "true"],
     ref="#1 de §13.1, D9-5 («solo true inscribe»)", decision="DF-C4", basis="decision-fixture")
SUCCESS_RENEW = {"any": [F_("membership.days_to_expiry", "gt", 30), {"event": "membership_renewed", "op": "exists", "within": W(7)}]}
EVAL("éxito de renew: renovó (evento) aunque los días aún no se hayan recalculado → true", SUCCESS_RENEW,
     SNAP({"membership.days_to_expiry": 5}, events=[{"key": "membership_renewed", "at": bog(2026, 11, 10, 8, 0), "attrs": {}}]), NOW, "true",
     ["false", "true"], ref="D9-6 (success de renew), #4 de §13.1")
AUD_LEAD = {"all": [F_("lead.followup_due", "eq", True), F_("lead.consent_status", "eq", "granted")]}
EVAL("#6 prospecto con seguimiento y consentimiento → true", AUD_LEAD, SNAP({"lead.followup_due": True, "lead.consent_status": "granted"}), NOW, "true",
     ["true", "true"], ref="#6 de §13.1")
EVAL("#6 prospecto con seguimiento y consentimiento pending → false", AUD_LEAD, SNAP({"lead.followup_due": True, "lead.consent_status": "pending"}), NOW,
     "false", ["true", "false"], ref="#6 de §13.1")
AUD_CLASS = {"all": [F_("class.reserved_next_7d", "eq", False), {"not": F_("valuation.has_upcoming", "eq", True)}]}
EVAL("#7 invitación: sin reserva y sin valoración próxima → true", AUD_CLASS, SNAP({"class.reserved_next_7d": False, "valuation.has_upcoming": False}),
     NOW, "true", ["true", "false"], ref="#7 de §13.1")
EVAL("#7 invitación: ya tiene reserva → false", AUD_CLASS, SNAP({"class.reserved_next_7d": True, "valuation.has_upcoming": False}), NOW, "false",
     ["false", "false"], ref="#7 de §13.1")

# ═══ bogotaDays ═════════════════════════════════════════════════════════════


def BD(desc, now, at, days, *, ref="D9-5 (días de Bogotá), §13.3"):
    assert bogota_days_ago(at, now) == days, (desc, bogota_days_ago(at, now))
    CC.add("bogotaDays", "vector", desc, ref, "decision-fixture", {"now": now, "at": at},
           {"daysAgo": days, "bogotaDateNow": str(bog_date(now)), "bogotaDateAt": str(bog_date(at))}, decision="DF-C3")


BD("mismo día de Bogotá", NOW, bog(2026, 11, 10, 7, 0), 0)
BD("las 23:59 de ayer en Bogotá (04:59Z de hoy en UTC) es ayer", NOW, "2026-11-10T04:59:00Z", 1)
BD("las 00:00 de hoy en Bogotá (05:00Z) es hoy", NOW, "2026-11-10T05:00:00Z", 0)
BD("now justo después de la medianoche de Bogotá", "2026-11-10T05:00:00Z", "2026-11-10T04:59:59Z", 1)
BD("29 de febrero de 2028 (bisiesto) → 1 de marzo", bog(2028, 3, 1, 9, 0), bog(2028, 2, 29, 18, 0), 1, ref="§13.3 (el 29 de febrero)")
BD("del 28 de febrero de 2028 al 1 de marzo son 2 días (hay 29)", bog(2028, 3, 1, 9, 0), bog(2028, 2, 28, 9, 0), 2, ref="§13.3 (el 29 de febrero)")
BD("cambio de año en Bogotá con UTC ya en el año siguiente", "2027-01-01T04:30:00Z", bog(2026, 12, 30, 12, 0), 1)
BD("730 días atrás (el límite de las ventanas)", NOW, bog(2024, 11, 10, 10, 0), 730)

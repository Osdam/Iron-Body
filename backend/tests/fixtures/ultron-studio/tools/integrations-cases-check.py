#!/usr/bin/env python3
"""Comprobador INDEPENDIENTE de tests/fixtures/ultron-studio/integrations-cases.json (Hito 6).

No importa nada del generador (tools/integrations-cases/): rehace cada `expected`
desde su entrada con las reglas del contrato H6 y de su README, escritas aquí
aparte. Toda la criptografía (HMAC-SHA256, SHA-256, HMAC-SHA1) va por `openssl`.
Después simula implementaciones equivocadas plausibles: cada una tiene que
hacer fallar al menos un caso (si no, el fixture no la detectaría).

  python3 -I integrations-cases-check.py            # estructura + derivación + variantes
  python3 -I integrations-cases-check.py --php      # además, el cruce con las primitivas de PHP del árbol
  python3 -I integrations-cases-check.py --fixture <otro.json>   # contra otra copia (p. ej., para ver que una corrupción se caza)
  python3 -I integrations-cases-check.py --tw       # mi derivación contra los fixtures confirmados del paquete TW
  python3 -I integrations-cases-check.py --impl     # las clases confirmadas (TwilioProbeSanitizer, ElevenLabsWebhookSignature/Envelope) contra este fixture

No lo ejecuta la suite. Sale con 0 solo si no hay ninguna discrepancia.
"""
import base64
import json
import re
import subprocess
import sys
import tempfile
import unicodedata
from datetime import datetime
from pathlib import Path

HERE = Path(__file__).resolve().parent
FIXTURE = HERE.parent / "integrations-cases.json"
BACKEND = HERE.parents[3]

DECLARED = {"phone": 88, "alias": 39, "fingerprint": 18, "canaryTransitions": 121, "coherence": 34, "expectation": 24,
            "campaignLinkRules": 64, "metaClassification": 39, "providerClassification": 21, "configFacts": 11, "configDrift": 8, "elevenlabsSignature": 52,
            "elevenlabsEnvelope": 13, "voiceDoctor": 38, "voiceVerify": 15, "voiceCallPolicy": 24, "twilioProbeSanitize": 28,
            "twilioSignatureOfficial": 7, "probeRules": 22, "acknowledgeRules": 9, "routeMatrix": 29, "errorOrder": 18}
MINIMUM = {"phone": 60, "alias": 15, "fingerprint": 8, "expectation": 12, "campaignLinkRules": 20, "metaClassification": 15,
           "configFacts": 6, "elevenlabsSignature": 20, "voiceDoctor": 12, "voiceCallPolicy": 16, "twilioProbeSanitize": 12,
           "twilioSignatureOfficial": 1}

BUG = None   # la implementación equivocada que se simula (None = la correcta)

# ── openssl ─────────────────────────────────────────────────────────────────
_CACHE = {}


def _openssl(args, data):
    k = (tuple(args), data)
    if k not in _CACHE:
        _CACHE[k] = subprocess.run(["openssl", *args], input=data, capture_output=True, check=True).stdout
    return _CACHE[k]


def hmac256_raw(key: bytes, msg: bytes) -> bytes:
    return _openssl(["dgst", "-sha256", "-mac", "HMAC", "-macopt", "hexkey:" + key.hex(), "-binary"], msg)


def hmac256_hex(key: bytes, msg: bytes) -> str:
    return hmac256_raw(key, msg).hex()


def sha256_hex(data: bytes) -> str:
    return _openssl(["dgst", "-sha256", "-binary"], data).hex()


def hmac1_b64(key: bytes, msg: bytes) -> str:
    return base64.b64encode(_openssl(["dgst", "-sha1", "-mac", "HMAC", "-macopt", "hexkey:" + key.hex(), "-binary"], msg)).decode()


# ── JSON canónico como StudioContentHasher::canonicalJson (PHP) ─────────────
def php_str(s):
    out = ['"']
    for ch in s:
        o = ord(ch)
        if ch == '"':
            out.append('\\"')
        elif ch == "\\":
            out.append("\\\\")
        elif ch in "\b\f\n\r\t":
            out.append({"\b": "\\b", "\f": "\\f", "\n": "\\n", "\r": "\\r", "\t": "\\t"}[ch])
        elif o < 0x20 or o in (0x2028, 0x2029) or (BUG == "configFacts:escape_unicode" and o > 0x7F):
            out.append("\\u%04x" % o)
        else:
            out.append(ch)
    out.append('"')
    return "".join(out)


def php_json(v):
    if v is None:
        return "null"
    if v is True:
        return "true"
    if v is False:
        return "false"
    if isinstance(v, int):
        return str(v)
    if isinstance(v, float):
        raise ValueError("la huella no admite decimales")
    if isinstance(v, str):
        return php_str(v)
    if isinstance(v, list):
        return "[" + ",".join(php_json(x) for x in v) + "]"
    if isinstance(v, dict):
        if not v:
            return "[]"
        items = sorted(v.items(), key=lambda kv: kv[0].encode("utf-8"))
        return "{" + ",".join(php_str(k) + ":" + php_json(x) for k, x in items) + "}"
    raise TypeError(type(v))


# ── utilidades ──────────────────────────────────────────────────────────────
def key_raw(app_key: str) -> bytes:
    if app_key.startswith("base64:") and BUG not in ("fingerprint:undecoded_key", "configFacts:undecoded_key"):
        return base64.b64decode(app_key[7:])
    return app_key.encode()


def filled(v):
    return v is not None and str(v).strip(" \t\n\r\0\x0b") != ""


E164 = re.compile(r"\+[1-9][0-9]{7,14}")


class Throws(Exception):
    pass


# ── phone ───────────────────────────────────────────────────────────────────
BUL = "•••"


def phone_parse(raw, channel, allowed):
    if BUG == "phone:strip_all_whitespace":
        s = re.sub(r"[\s\-.()]", "", raw)
    else:
        s = "".join(c for c in raw if c not in " -.()")
    if BUG == "phone:country_before_format":
        d = re.sub(r"[^0-9]", "", s)
        if not [c for c in allowed if d.startswith(c)]:
            return {"ok": False, "error": "country_not_allowed"}
    if BUG == "phone:dollar_newline":
        okf = re.match(r"^\+[1-9][0-9]{7,14}$", s) is not None
    elif BUG == "phone:unicode_digits":
        okf = re.fullmatch(r"\+[1-9]\d{7,14}", s) is not None
    else:
        okf = E164.fullmatch(s) is not None
    if not okf:
        return {"ok": False, "error": "invalid_format"}
    digits = s[1:]
    if BUG == "phone:first_prefix":
        cands = [c for c in allowed if digits.startswith(c)][:1]
    elif BUG == "phone:contains_prefix":
        cands = [c for c in allowed if c in digits]
    else:
        cands = [c for c in allowed if digits.startswith(c)]
    if not cands:
        return {"ok": False, "error": "country_not_allowed"}
    cc = max(cands, key=len)
    if cc == "57" or BUG == "phone:channel_rule_any_country":
        if channel == "whatsapp" and BUG != "phone:whatsapp_allows_landline":
            rule = r"\+573[0-9]{9}"
        else:
            rule = r"\+57(?:3[0-9]{9}|60[0-9]{8})"
        if re.fullmatch(rule, s) is None:
            return {"ok": False, "error": "not_allowed_for_channel"}
    last4 = s[-4:]
    sep = "" if BUG == "phone:masked_no_spaces" else " "
    return {"ok": True, "e164": s, "countryCode": cc, "last4": last4, "masked": f"+{cc}{sep}{BUL}{sep}{BUL}{sep}{last4}"}


def phone_masked(cc, last4):
    sep = "" if BUG == "phone:masked_no_spaces" else " "
    return f"+{cc}{sep}{BUL}{sep}{BUL}{sep}{last4}"


def d_phone(c):
    if c["kind"] == "masked":
        return {"masked": phone_masked(c["countryCode"], c["last4"])}
    return phone_parse(c["raw"], c["channel"], c["allowedCountryCodes"])


# ── alias ───────────────────────────────────────────────────────────────────
ALIAS_OK = set("ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789ÁÉÍÓÚÜÑáéíóúüñ .-_()")


CONTACT_RUN = r"\+?[0-9](?:[^A-Za-z0-9ÁÉÍÓÚÜÑáéíóúüñ]{0,3}[0-9]){7,}"
CONTACT_EMAIL = r"[^\s@]+@[^\s@]+\.[^\s@]+"


def d_alias(c):
    a = c["alias"]
    if BUG == "alias:trim":
        a = a.strip()
    if BUG == "alias:dollar_newline" and a.endswith("\n"):
        a = a[:-1]
    n = len(a.encode()) if BUG == "alias:bytes" else len(a)
    bad = not (3 <= n <= 40) or any(ch not in ALIAS_OK for ch in a) \
        or re.search(r"[0-9]{5,}" if BUG == "alias:run5" else r"[0-9]{4,}", a) is not None or "@" in a
    # AS-1 (Enmienda 3) cierra O-1: un dato de contacto (8 o más dígitos con hasta 3 separadores que no sean
    # letras ni dígitos entre ellos, o un correo) también invalida el alias. Variante errónea: alias:no_contact.
    if BUG != "alias:no_contact" and (re.search(CONTACT_RUN, a) is not None or re.search(CONTACT_EMAIL, a) is not None):
        bad = True
    return {"error": "invalid_alias" if bad else None}


# ── fingerprint ─────────────────────────────────────────────────────────────
def phone_fp(e164, app_key):
    if BUG == "fingerprint:dollar_newline":
        valid = re.match(r"^\+[1-9][0-9]{7,14}$", e164) is not None
    else:
        valid = E164.fullmatch(e164) is not None
    if not valid and BUG != "fingerprint:no_validation":
        raise Throws("InvalidArgumentException")
    raw = key_raw(app_key)
    if raw == b"":
        raise Throws("RuntimeException")
    label = b"ultron-studio-publication" if BUG == "fingerprint:h5_label" else b"ultron-canary-phone/1"
    if BUG == "fingerprint:swapped_subkey":
        sub = hmac256_raw(label, raw)
    elif BUG == "fingerprint:no_subkey":
        sub = raw
    else:
        sub = hmac256_raw(raw, label)
    msg = e164[1:] if BUG == "fingerprint:digits_only" else e164
    return sub, hmac256_hex(sub, msg.encode())


def d_fingerprint(c):
    try:
        sub, fp = phone_fp(c["e164"], c["appKey"])
    except Throws as t:
        return {"throws": str(t)}
    if c["kind"] == "vector" and bytes.fromhex(c["appKeyRawHex"]) != (base64.b64decode(c["appKey"][7:]) if c["appKey"].startswith("base64:") else c["appKey"].encode()):
        return {"error": "appKeyRawHex no es la APP_KEY cruda"}
    return {"subkeyHex": sub.hex(), "fingerprint": fp}


# ── canaryTransitions ───────────────────────────────────────────────────────
LINKABLE = {"APPROVED", "CANARY", "PUBLISHED"}
LIVE = ["pending", "authorized", "active"]
ALLOWED_FROM = {"authorize": ["pending"], "activate": ["authorized"], "deactivate": ["active"], "revoke": LIVE, "expectation": LIVE}


def r_err(status, code, payload=None, audit="absent"):
    d = {"status": status, "code": code}
    if payload is not None:
        d["payload"] = payload
    if audit != "absent":
        d["audit"] = audit
    return d


def head_checks(a, key):
    if not a["person"]:
        return r_err(403, "person_required")
    for k in (["ultron.view", key] if BUG != "canaryTransitions:no_view_check" else [key]):
        if k not in a["permissions"]:
            return r_err(403, "forbidden", {"required_permission": k})
    return None


def version_checks(v):
    if not v["exists"]:
        return r_err(404, "version_not_found")
    if BUG == "canaryTransitions:integrity_before_status" and not v["integrityOk"]:
        return r_err(409, "integrity_mismatch")
    if v["status"] not in LINKABLE:
        return r_err(409, "version_not_linkable", {"currentStatus": v["status"]})
    if not v["integrityOk"]:
        return r_err(409, "integrity_mismatch")
    return None


WD = r_err(409, "integrations_writes_disabled", audit=None)


def is_registrant(a, n):
    if BUG == "canaryTransitions:identity_by_name":
        return a["name"] == n["registeredByName"]
    if BUG == "canaryTransitions:no_four_eyes":
        return False
    return a["adminId"] == n["registeredByAdminId"]


def four_eyes(action):
    return r_err(403, "four_eyes_required", {"action": action},
                 audit={"event": "canary_number.four_eyes_denied", "from": None, "to": None, "diff": {"action": action}})


def d_canary(c):
    f, k = c["facts"], c["kind"]
    if k == "actions":
        return d_actions(f)
    a = f["actor"]
    key = {"register": "ultron.canary_manage", "authorize": "ultron.canary_authorize"}.get(k, "ultron.canary_manage")
    if BUG == "canaryTransitions:activate_needs_authorize" and k == "activate":
        key = "ultron.canary_authorize"
    h = head_checks(a, key)
    if h:
        return h
    if k == "register":
        if BUG == "canaryTransitions:replay_before_flag" and f["clientRequest"] == "replay":
            return {"status": 200, "code": "replayed"}
        if not f["writesEnabled"]:
            return WD
        if f["clientRequest"] == "replay":
            return {"status": 200, "code": "replayed"}
        if f["clientRequest"] != "new":
            return r_err(409, "client_request_reused")
        reg = f["registry"]
        dup = any(r["channel"] == f["channel"] and r["status"] != "revoked" for r in reg["sameNumber"])
        if BUG == "canaryTransitions:exists_before_limit" and dup:
            return r_err(409, "canary_number_exists")
        if BUG == "canaryTransitions:limit_before_version" and reg["liveInChannel"] >= reg["maxLive"]:
            return r_err(409, "canary_live_limit", {"max": reg["maxLive"], "channel": f["channel"]})
        if f["expectedVersionUuid"] is not None:
            v = version_checks(f["expectedVersion"])
            if v:
                return v
        if reg["liveInChannel"] >= reg["maxLive"]:
            return r_err(409, "canary_live_limit", {"max": reg["maxLive"], "channel": f["channel"]})
        if dup:
            return r_err(409, "canary_number_exists")
        return {"status": 201, "code": None, "toStatus": "pending",
                "audit": {"event": "canary_number.registered", "from": None, "to": "pending",
                          "diff": {"channel": f["channel"], "expectedVersionUuid": f["expectedVersionUuid"]}}}
    if not f["writesEnabled"]:
        return WD
    n = f["number"]
    if not n["exists"]:
        return r_err(404, "canary_not_found")
    st = n["status"]

    def state_ok():
        return st in ALLOWED_FROM[k]

    def it():
        return r_err(409, "invalid_transition", {"currentStatus": st, "allowedFrom": ALLOWED_FROM[k]})

    if k in ("authorize", "activate"):
        if BUG == "canaryTransitions:state_before_identity" and not state_ok():
            return it()
        if is_registrant(a, n):
            return four_eyes(k)
    if not state_ok():
        return it()
    if k == "authorize":
        return {"status": 200, "code": None, "toStatus": "authorized",
                "audit": {"event": "canary_number.authorized", "from": "pending", "to": "authorized"}}
    if k == "activate":
        over = f["activeInChannel"] > f["maxActive"] if BUG == "canaryTransitions:limit_gt" else f["activeInChannel"] >= f["maxActive"]
        if over:
            return r_err(409, "canary_active_limit", {"max": f["maxActive"], "channel": n["channel"]})
        return {"status": 200, "code": None, "toStatus": "active",
                "audit": {"event": "canary_number.activated", "from": "authorized", "to": "active"}}
    if k == "deactivate":
        return {"status": 200, "code": None, "toStatus": "authorized",
                "audit": {"event": "canary_number.deactivated", "from": "active", "to": "authorized", "diff": {"reason": f["reason"]}}}
    if k == "revoke":
        erased = True if BUG == "canaryTransitions:cipher_always" else n["channel"] == "voice"
        return {"status": 200, "code": None, "toStatus": "revoked",
                "audit": {"event": "canary_number.revoked", "from": st, "to": "revoked", "diff": {"reason": f["reason"], "cipherErased": erased}}}
    if k == "expectation":
        if f["versionUuid"] is not None:
            v = version_checks(f["version"])
            if v:
                return v
        return {"status": 200, "code": None, "toStatus": st,
                "audit": {"event": "canary_number.expectation_set", "from": None, "to": None, "diff": {"versionUuid": f["versionUuid"]}}}
    raise KeyError(k)


def d_actions(f):
    v, n = f["viewer"], f["number"]
    perms, st = v["permissions"], n["status"]
    reg = v["adminId"] == n["registeredByAdminId"]
    out = {}
    rules = {"authorize": ("ultron.canary_authorize", ["pending"], True, False),
             "activate": ("ultron.canary_manage", ["authorized"], True, True),
             "deactivate": ("ultron.canary_manage", ["active"], False, False),
             "revoke": ("ultron.canary_manage", LIVE, False, False),
             "expectation": ("ultron.canary_manage", LIVE, False, False)}
    for action, (perm, states, eyes, limit) in rules.items():
        checks = [("forbidden", perm not in perms or "ultron.view" not in perms), ("writes_disabled", not f["writesEnabled"]),
                  ("invalid_transition", st not in states), ("four_eyes_required", eyes and reg),
                  ("active_limit", limit and f["activeInChannel"] >= f["maxActive"])]
        if BUG == "canaryTransitions:actions_identity_before_state":
            checks[2], checks[3] = checks[3], checks[2]
        if BUG == "canaryTransitions:actions_limit_before_identity":
            checks[3], checks[4] = checks[4], checks[3]
        reason = next((name for name, bad in checks if bad), None)
        out[action] = {"allowed": reason is None, "reason": reason}
    return {"actions": out}


# ── coherence ───────────────────────────────────────────────────────────────
def normalize(phone):
    if phone is None or phone == "":
        return None
    d = re.sub(r"[^0-9]", "", phone)
    if len(d) == 10 and d.startswith("3") and BUG != "coherence:no_57":
        d = "57" + d
    e = "+" + d
    return e if E164.fullmatch(e) else None


def d_coherence(c):
    eng, rows, fail = c["engine"], c["rows"], c["failure"]
    active_w = None if fail else sum(1 for r in rows if r["channel"] == "whatsapp" and r["status"] == "active")
    if eng["canaryConversationId"] is None:
        return {"verdict": "no_engine_canary", "engineCanaryConfigured": False, "activeWhatsapp": active_w, "normalizedE164": None,
                "engineHash": None, "rows": [{"ref": r["ref"], "isEngineCanary": None} for r in rows]}
    if fail:
        return {"verdict": "unreadable", "engineCanaryConfigured": True, "activeWhatsapp": None, "normalizedE164": None,
                "engineHash": None, "rows": [{"ref": r["ref"], "isEngineCanary": None} for r in rows]}
    conv, lead = eng["conversation"], eng["lead"]
    phone = lead["phone"] if conv is not None and conv["leadId"] is not None and lead is not None else None
    e164 = normalize(phone)
    if e164 is None:
        verdict = "in_sync" if BUG == "coherence:default_in_sync" else "engine_canary_phone_unusable"
        return {"verdict": verdict, "engineCanaryConfigured": True, "activeWhatsapp": active_w, "normalizedE164": None, "engineHash": None,
                "rows": [{"ref": r["ref"], "isEngineCanary": None if r["channel"] == "voice" else False} for r in rows]}
    h = phone_fp(e164, c["appKey"])[1]
    stored = {r["ref"]: phone_fp(r["phone"], r["appKey"])[1] for r in rows}
    live = [r for r in rows if r["channel"] == "whatsapp" and stored[r["ref"]] == h
            and (r["status"] != "revoked" or BUG == "coherence:revoked_counts")]
    mine = live[0] if live else None
    if mine is None:
        verdict = "engine_canary_not_registered"
    elif mine["status"] in ("pending", "authorized"):
        verdict = "engine_canary_registered_inactive"
    else:
        others = [r for r in rows if r is not mine and r["status"] == "active"
                  and (r["channel"] == "whatsapp" or BUG == "coherence:voice_counts_extra")]
        verdict = "registry_has_extra_active" if others else "in_sync"
    return {"verdict": verdict, "engineCanaryConfigured": True, "activeWhatsapp": active_w, "normalizedE164": e164, "engineHash": h,
            "rows": [{"ref": r["ref"], "isEngineCanary": None if r["channel"] == "voice" else (r is mine)} for r in rows]}


# ── expectation ─────────────────────────────────────────────────────────────
def d_expectation(c):
    ch, ie = c["row"]["channel"], c["row"]["isEngineCanary"]
    chain, ev = c["chain"], c["expectedVersion"]

    def from_chain():
        if chain["throws"]:
            return {"source": "none", "reason": "chain_unreadable"}
        if chain["state"] != "applied":
            return {"source": "publication_chain", "state": chain["state"]}
        order = ["rest", "canary"] if BUG == "expectation:rest_first" else ["canary", "rest"]
        for part in order:
            if chain[part] is not None:
                return {"source": "publication_chain", "state": "applied", "part": part,
                        "versionUuid": chain[part]["versionUuid"], "label": chain[part]["label"]}
        return {"source": "publication_chain", "state": "legacy"}

    if ch == "voice":
        eff = {"source": "none", "reason": "no_voice_engine"}
    elif BUG == "expectation:chain_for_non_engine" and ie is not None:
        eff = from_chain()
    elif ie is None:
        eff = {"source": "none", "reason": "coherence_unreadable"}
    elif ie is False:
        eff = {"source": "none", "reason": "not_engine_canary"}
    else:
        eff = from_chain()
    if BUG == "expectation:not_applicable_first" and eff["source"] == "none":
        exp = "not_applicable"
    elif ev is None:
        exp = "none"
    elif eff["source"] == "none":
        exp = "not_applicable"
    elif eff.get("state") == "applied" and eff["versionUuid"] == ev["uuid"]:
        exp = "match"
    else:
        exp = "mismatch"
    stale = None if ev is None else (ev["status"] not in LINKABLE or (not ev["integrityOk"] and BUG != "expectation:stale_ignores_integrity"))
    return {"effectiveVersion": eff, "expectation": exp, "expectedVersionStale": stale}


# ── campaignLinkRules ───────────────────────────────────────────────────────
def d_campaign(c):
    f = c["facts"]
    a = f["actor"]
    if not a["person"]:
        return r_err(403, "person_required")
    for k in ["ultron.view", "ultron.campaigns_manage"]:
        if k not in a["permissions"]:
            return r_err(403, "forbidden", {"required_permission": k})
    if not f["writesEnabled"]:
        return WD
    if c["kind"] == "deactivate":
        link = f["link"]
        if not link["exists"]:
            return r_err(404, "link_not_found")
        if link["status"] != "active":
            return r_err(409, "invalid_transition", {"currentStatus": "inactive", "allowedFrom": ["active"]})
        return {"status": 200, "code": None, "toStatus": "inactive",
                "audit": [{"event": "campaign_link.deactivated", "from": "active", "to": "inactive",
                           "diff": {"reason": f["reason"], "replacedBy": None}}]}
    meta_bad = f["metaProblem"] is not None or (BUG == "campaignLinkRules:sync_disabled_is_not_configured" and not f["metaSyncEnabled"])
    if BUG == "campaignLinkRules:meta_before_replay" and meta_bad:
        return r_err(409, "meta_not_configured", {"reason": f["metaProblem"] or "sync_disabled"})
    if f["clientRequest"] == "replay":
        return {"status": 200, "code": "replayed"}
    if f["clientRequest"] != "new":
        return r_err(409, "client_request_reused")
    if meta_bad:
        return r_err(409, "meta_not_configured", {"reason": f["metaProblem"] or "sync_disabled"})
    if not f["campaignResolvable"]:
        return r_err(404, "campaign_not_found")
    if f["target"]["kind"] == "version":
        v = f["target"]["version"]
        if not v["exists"]:
            return r_err(404, "version_not_found")
        if BUG == "campaignLinkRules:integrity_before_status" and not v["integrityOk"]:
            return r_err(409, "integrity_mismatch")
        if v["status"] not in LINKABLE:
            return r_err(409, "version_not_linkable", {"currentStatus": v["status"]})
        if not v["integrityOk"]:
            return r_err(409, "integrity_mismatch")
    act, rep = f["activeLink"], f["replaces"]
    if act is not None:
        if rep is None:
            return r_err(409, "campaign_already_linked", {"current": act})
        if rep != act:
            return r_err(409, "campaign_link_moved", {"current": act})
    elif rep is not None:
        if BUG == "campaignLinkRules:moved_without_active_is_linked":
            return r_err(409, "campaign_already_linked", {"current": None})
        return r_err(409, "campaign_link_moved", {"current": None})
    count = f["activeTotal"] - (1 if act is not None and rep == act and BUG != "campaignLinkRules:limit_counts_replaced" else 0)
    if count >= f["maxActive"]:
        return r_err(409, "campaign_links_limit", {"max": f["maxActive"]})
    out = {"status": 201, "code": None, "replaced": act}
    audit = [{"event": "campaign_link.created", "from": None, "to": "active"}]
    if act is not None:
        out["replacedRow"] = {"status": "inactive", "deactivationReason": "Sustituida por otra asociación", "replacedBy": "new"}
        audit.append({"event": "campaign_link.deactivated", "from": "active", "to": "inactive"})
    out["audit"] = audit
    return out


# ── metaClassification ──────────────────────────────────────────────────────
def instant(s):
    return datetime.fromisoformat(s)


def d_meta(c):
    k, f = c["kind"], c["facts"]
    if k == "classify":
        p, st, sync = f["configurationProblem"], f["statusAll"], f["syncEnabled"]
        if BUG == "metaClassification:problem_after_sync" and not sync:
            return {"classification": "degraded", "state": st, "reason": "sync_disabled"}
        if p is not None:
            return {"classification": "not_configured", "state": "not_configured", "reason": p}
        if not sync and (BUG != "metaClassification:sync_disabled_only_when_ok" or st == "ok"):
            return {"classification": "degraded", "state": st, "reason": "sync_disabled"}
        if st in ("ok", "running"):
            return {"classification": "connected_read", "state": st, "reason": None}
        return {"classification": "degraded", "state": st, "reason": st}
    if k == "possiblyDeleted":
        runs = [r for r in f["runs"] if r["account"] == f["account"] and r["kind"] == "insights"
                and (r["status"] == "ok" or BUG == "metaClassification:any_status")
                and (r["trigger"] != "backfill" or BUG == "metaClassification:backfill_counts")]
        last = max(runs, key=lambda r: r["id"]) if runs else None
        if f["syncedAt"] is None:
            return {"possiblyDeleted": True, "referenceRunId": last["id"] if last else None}
        if last is None:
            return {"possiblyDeleted": False, "referenceRunId": None}
        if BUG == "metaClassification:string_compare":
            before = f["syncedAt"] < last["startedAt"]
        elif BUG == "metaClassification:le":
            before = instant(f["syncedAt"]) <= instant(last["startedAt"])
        else:
            before = instant(f["syncedAt"]) < instant(last["startedAt"])
        return {"possiblyDeleted": before, "referenceRunId": last["id"]}
    if k == "stale":
        return {"stale": f["accountState"] in ("stale", "failed")}
    if k == "order":
        def key(x):
            name = x["name"] if BUG == "metaClassification:case_sensitive" else x["name"].lower()
            return (x["effectiveStatus"] != "ACTIVE", name, x["entityId"])
        return {"order": [x["entityId"] for x in sorted(f["campaigns"], key=key)]}
    raise KeyError(k)


# ── providerClassification (ruta 39, §I7.2) ─────────────────────────────────
def d_status(c):
    f, k = c["facts"], c["kind"]
    if k == "twilio":
        over = f["estimatedSpentTodayUsd"] > f["dailyHardLimitUsd"] if BUG == "providerClassification:gt_hard" else f["estimatedSpentTodayUsd"] >= f["dailyHardLimitUsd"]
        rules = [("not_configured", "otp_driver_not_twilio", f["otpDriver"] != "twilio"),
                 ("not_configured", "verify_incomplete", not f["verifyActive"]),
                 ("degraded", "kill_switch", not f["sendEnabled"]),
                 ("degraded", "hard_limit_reached", over),
                 ("degraded", "no_verifications", BUG == "providerClassification:counts_degrade" and f["otpVerifiedLast24h"] == 0)]
        if BUG == "providerClassification:limit_before_killswitch":
            rules[2], rules[3] = rules[3], rules[2]
        hit = next(((cl, r) for cl, r, cond in rules if cond), ("connected_otp_only", None))
        return {"classification": hit[0], "reason": hit[1]}
    if k == "twilio_voice":
        frm = f["from"]
        if BUG == "providerClassification:voice_loose_e164" and isinstance(frm, str):
            frm = frm.replace(" ", "")
        if not (isinstance(frm, str) and E164.fullmatch(frm)):
            return {"classification": "not_configured"}
        return {"classification": "degraded" if f["enabled"] else "disabled"}
    m = {"ELEVENLABS_NOT_CONFIGURED": "not_configured", "ELEVENLABS_DISABLED": "disabled",
         "ELEVENLABS_UNVERIFIED": "degraded", "ELEVENLABS_VERIFIED": "connected_read"}
    return {"classification": m[f["state"]], "code": f["state"]}


# ── configFacts / configDrift ───────────────────────────────────────────────
ELL = "…"


def cfg_fp(value, app_key):
    k = hmac256_raw(key_raw(app_key), b"ultron-studio-config-fingerprint/1")
    return hmac256_hex(k, value.encode())[:8]


def absent():
    return {"present": False, "source": None} if BUG == "configFacts:absent_with_source" else {"present": False}


def host_fact(url, official):
    host = re.match(r"^[a-z][a-z0-9+.-]*://(?:[^/@?#]*@)?([^/:?#]+)", url or "")
    h = host.group(1) if host else None
    if BUG == "configFacts:host_contains":
        return {"official": True} if official in (url or "") else {"official": False, "host": h}
    return {"official": True} if h == official else {"official": False, "host": h}


def money(v):
    if BUG == "configFacts:float_costs":
        return repr(float(v))
    return "%.2f" % v


def d_config_facts(c):
    p, cfg, key = c["kind"], c["config"], c["appKey"]
    if p == "twilio":
        al = cfg["ultron_studio.integrations.twilio_aliases"]
        sid, tok = cfg["otp.twilio.sid"], cfg["otp.twilio.token"]
        vs, frm = cfg["otp.twilio.verify_service_sid"], cfg["otp.twilio.from"]
        ksid = cfg["ultron_studio.integrations.twilio_probe.key_sid"]
        ksec = cfg["ultron_studio.integrations.twilio_probe.key_secret"]
        restricted = filled(ksid) and (filled(ksec) or BUG == "configFacts:credential_any_key")
        facts = {
            "otpDriver": cfg["otp.driver"],
            "accountSid": {"present": True, "source": al["sid_source"], "ref": sid[:2] + ELL + sid[-4:]} if filled(sid) else absent(),
            "authToken": {"present": True, "source": al["token_source"], "fp": cfg_fp(tok, key)} if filled(tok) else absent(),
            "aliasesConsistent": {"sid": al["sid_consistent"], "token": al["token_consistent"]},
            "verifyServiceSid": {"present": True, "ref": vs[:2] + ELL + vs[-4:]} if filled(vs) else absent(),
            "from": {"present": True, "last4": frm[-4:]} if filled(frm) else absent(),
            "apiBase": host_fact(cfg["otp.twilio.base"], "api.twilio.com"),
            "verifyBase": host_fact(cfg["otp.twilio.verify_base"], "verify.twilio.com"),
            "rateLimitName": {"present": filled(cfg["otp.twilio.rate_limit_unique_name"])},
            "sendEnabled": cfg["otp.send_enabled"], "legacySenderEnabled": cfg["otp.legacy_sms_sender_enabled"],
            "costSoftUsd": money(cfg["otp.cost.daily_soft"]), "costHardUsd": money(cfg["otp.cost.daily_hard"]),
            "probe": {"enabled": cfg["ultron_studio.integrations.twilio_probe.enabled"], "credential": "restricted_key" if restricted else "otp"},
        }
    elif p == "meta_ads":
        tok = cfg["meta.ads.access_token"]
        facts = {"token": {"present": True, "fp": cfg_fp(tok, key)} if filled(tok) else absent(),
                 "accounts": [{"ref": "***" + a[-4:]} for a in cfg["meta.ads.ad_account_ids"]],
                 "graphVersion": cfg["meta.ads.graph_version"], "syncEnabled": cfg["meta.ads.sync_enabled"],
                 "syncDays": cfg["meta.ads.sync_days"], "staleMinutes": cfg["meta.ads.stale_after_minutes"],
                 "timezone": cfg["meta.ads.timezone"]}
    elif p == "voice":
        def secret(v):
            vv = v if BUG == "configFacts:voice_untrimmed" else (v.strip(PHP_TRIM) if isinstance(v, str) else v)
            return {"present": True, "fp": cfg_fp(vv, key)} if filled(v) else absent()
        ag, frm = cfg["voice.elevenlabs.agent_id"], cfg["voice.twilio.from"]
        facts = {"elevenlabs": {"enabled": cfg["voice.elevenlabs.enabled"], "apiKey": secret(cfg["voice.elevenlabs.api_key"]),
                                "agentId": {"present": True, "ref": ag[-4:]} if filled(ag) else absent(),
                                "webhookSecret": secret(cfg["voice.elevenlabs.webhook_secret"]),
                                "region": cfg["voice.elevenlabs.region"], "toolSecret": secret(cfg["voice.elevenlabs.tool_secret"]),
                                "phoneNumberId": {"present": filled(cfg["voice.elevenlabs.agent_phone_number_id"])}},
                 "twilioVoice": {"enabled": cfg["voice.twilio.enabled"],
                                 "from": {"present": True, "last4": frm[-4:]} if filled(frm) else absent(),
                                 "dailyCallCap": cfg["voice.twilio.daily_call_cap"]}}
    else:
        raise KeyError(p)
    try:
        cj = php_json(facts)
    except ValueError:
        return {"error": "canonicalJson rechaza los decimales"}
    return {"facts": facts, "canonicalJson": cj, "fingerprint": sha256_hex(cj.encode()), "mustNotContain": c["expected"]["mustNotContain"]}


def same_value(a, b):
    if BUG == "configDrift:raw_order":
        return json.dumps(a, ensure_ascii=False) == json.dumps(b, ensure_ascii=False)
    return php_json(a) == php_json(b)


def d_config_drift(c, fixture):
    order = fixture["closedSets"]["configFactsKeys"][c["kind"]]
    auth, eff = c["authorized"], c["effective"]
    efp = sha256_hex(php_json(eff).encode())
    if auth is None:
        return {"state": "never_authorized", "effectiveFingerprint": efp, "drift": []}
    if sha256_hex(php_json(auth["facts"]).encode()) != auth["fingerprint"]:
        return {"error": "la huella reconocida no es la de sus facts"}
    drift = [{"key": k, "authorized": auth["facts"][k], "effective": eff[k]} for k in order if not same_value(auth["facts"][k], eff[k])]
    if BUG == "configDrift:raw_order":
        state = "drift" if drift else "authorized"
    else:
        state = "drift" if auth["fingerprint"] != efp else "authorized"
    return {"state": state, "effectiveFingerprint": efp, "drift": drift}


# ── elevenlabsSignature / Envelope ──────────────────────────────────────────
PHP_TRIM = " \t\n\r\0\x0b"


def el_verify(body, header, secret, now):
    if secret == "" and BUG != "elevenlabsSignature:empty_secret_ok":
        raise Throws("InvalidArgumentException")
    strip = (lambda s: s.strip()) if BUG == "elevenlabsSignature:unicode_trim" else (lambda s: s.strip(PHP_TRIM))
    if header is None or strip(header) == "":
        return "missing_signature"
    ts, vs = [], []
    for part in (strip(p) for p in header.split(",")):
        if part == "":
            continue
        if "=" not in part:
            if BUG == "elevenlabsSignature:part_without_eq_ignored":
                continue
            return "malformed_signature"
        k, v = part.split("=", 1)
        if BUG == "elevenlabsSignature:trim_kv":
            k, v = k.strip(), v.strip()
        if BUG == "elevenlabsSignature:case_insensitive_keys":
            k = k.lower()
        if k == "t":
            ts.append(v)
        elif k == "v0":
            vs.append(v)
    hexes = [v for v in vs if re.fullmatch(r"[0-9a-fA-F]{64}", v)]
    if BUG == "elevenlabsSignature:lenient_v0":
        vs = hexes
    if len(ts) != 1 or re.fullmatch(r"[0-9]{1,12}", ts[0]) is None or not vs or len(hexes) != len(vs):
        return "malformed_signature"
    t = int(ts[0])

    def sig_ok():
        msg = (str(t) if BUG == "elevenlabsSignature:t_as_int" else ts[0]) + "."
        if BUG == "elevenlabsSignature:no_t_prefix":
            msg = ""
        exp = hmac256_hex(secret.encode(), (msg + body).encode()) if secret != "" else ""
        return any((v if BUG == "elevenlabsSignature:case_sensitive_v0" else v.lower()) == exp for v in vs)

    if BUG == "elevenlabsSignature:signature_first" and not sig_ok():
        return "mismatch"
    if (t <= now - 1800) if BUG == "elevenlabsSignature:le_expired" else (t < now - 1800):
        return "expired"
    if t > now + 300 and BUG != "elevenlabsSignature:no_future":
        return "from_future"
    return "ok" if sig_ok() else "mismatch"


def d_el_sig(c):
    try:
        return {"result": el_verify(c["rawBody"], c["header"], c["secret"], c["now"])}
    except Throws as t:
        return {"throws": str(t)}


TYPES = ["post_call_transcription", "post_call_audio", "call_initiation_failure"]


def d_el_env(c):
    raw = c["rawBody"]
    try:
        o = json.loads(raw)
    except ValueError:
        return {"type": None}
    t = o.get("type") if isinstance(o, dict) else None
    if isinstance(t, str) and BUG == "elevenlabsEnvelope:case_insensitive":
        t = t.lower()
    if isinstance(t, str) and BUG == "elevenlabsEnvelope:trim":
        t = t.strip()
    return {"type": t if isinstance(t, str) and t in TYPES else None}


# ── voiceDoctor / voiceVerify / voiceCallPolicy ─────────────────────────────
REGIONS = {"default": "https://api.elevenlabs.io", "eu": "https://api.eu.residency.elevenlabs.io"}


def valid_region(r):
    if BUG == "voiceDoctor:region_not_normalized":
        return r if r in REGIONS else None
    if r is None:
        return "default"
    r = r.strip(PHP_TRIM).lower()
    return r if r in REGIONS else None


def d_voice_doctor(c):
    k = c["kind"]
    if k == "status":
        cfg = c["config"]
        missing = []
        key = cfg["voice.elevenlabs.api_key"]
        if not (key is not None and key != "") if BUG == "voiceDoctor:key_not_trimmed" else not filled(key):
            missing.append("api_key")
        ag = cfg["voice.elevenlabs.agent_id"]
        pat = (lambda s: re.match(r"^[A-Za-z0-9_-]{1,64}$", s)) if BUG == "voiceDoctor:agent_dollar" else (lambda s: re.fullmatch(r"[A-Za-z0-9_-]{1,64}", s))
        if ag is None or pat(ag) is None:
            missing.append("agent_id")
        if not filled(cfg["voice.elevenlabs.webhook_secret"]):
            missing.append("webhook_secret")
        region = valid_region(cfg["voice.elevenlabs.region"])
        if region is None:
            missing.append("region")
        en = cfg["voice.elevenlabs.enabled"]
        state = "ELEVENLABS_NOT_CONFIGURED" if missing else ("ELEVENLABS_DISABLED" if en is not True else "ELEVENLABS_UNVERIFIED")
        provider = "elevenlabs" if (state == "ELEVENLABS_UNVERIFIED" or BUG == "voiceDoctor:provider_always_elevenlabs") else "none"
        return {"provider": provider, "state": state, "enabled": en, "missing": missing, "region": region}
    if k == "baseUrl":
        cfg = c["config"]
        region = valid_region(cfg["voice.elevenlabs.region"])
        base = cfg["voice.elevenlabs.base_url"]
        if BUG == "voiceDoctor:base_url_in_production" and filled(base):
            return {"baseUrl": base}
        if region is None:
            return {"baseUrl": None}
        if c["env"] == "production":
            return {"baseUrl": REGIONS[region]}
        return {"baseUrl": base if filled(base) else REGIONS[region]}
    if k == "studioState":
        st = c["providerState"]
        if st != "ELEVENLABS_UNVERIFIED":
            return {"state": st}
        now = instant(c["now"])
        probes = c["voiceProbes"]
        pool = probes if BUG == "voiceDoctor:any_ok" else probes[-1:]
        good = any(p["outcome"] == "ok" and (((now - instant(p["at"])).total_seconds() <= 2592000) if BUG == "voiceDoctor:le_30d"
                                             else ((now - instant(p["at"])).total_seconds() < 2592000)) for p in pool)
        return {"state": "ELEVENLABS_VERIFIED" if good else "ELEVENLABS_UNVERIFIED"}
    raise KeyError(k)


SNAP = {"credentials_ok": "ok", "invalid_credentials": "auth_failed", "agent_not_found": "degraded", "unreachable": "unreachable", "error": "error"}


def d_voice_verify(c):
    r = c["response"]
    if "connectionError" in r:
        o, status, ulaw = "unreachable", None, None
    else:
        status, ulaw = r["status"], None
        if status == 200:
            try:
                b = json.loads(r["rawBody"])
            except ValueError:
                b = None
            o = "credentials_ok" if (isinstance(b, dict) or BUG == "voiceVerify:non_object_is_ok") else "error"
            cc = b.get("conversation_config") if isinstance(b, dict) else None
            tts = (cc or {}).get("tts", {}).get("agent_output_audio_format") if isinstance(cc, dict) else None
            asr = (cc or {}).get("asr", {}).get("user_input_audio_format") if isinstance(cc, dict) else None
            if BUG == "voiceVerify:one_format_enough":
                if tts is not None or asr is not None:
                    ulaw = (tts or asr) == "ulaw_8000"
            elif tts is not None and asr is not None:
                ulaw = tts == "ulaw_8000" and asr == "ulaw_8000"
        elif status in (401, 403):
            o = "invalid_credentials"
        elif status == 404:
            o = "agent_not_found"
        else:
            o = "error"
    return {"outcome": o, "httpStatus": status, "agentAudioUlaw8000": ulaw, "snapshotOutcome": SNAP[o]}


def d_voice_policy(c):
    if c["kind"] == "context":
        v = c["voiceRows"] + (c["whatsappRows"] if BUG == "voiceCallPolicy:counts_whatsapp" else [])
        return {"canary": "active_voice" if "active" in v else ("not_active" if any(s != "revoked" for s in v) else "not_registered")}
    x = c["context"]
    rs = ["calls_disabled_in_code"]
    if not x["twilioVoiceEnabled"]:
        rs.append("twilio_voice_disabled")
    if not x["twilioVoiceFromConfigured"]:
        rs.append("no_voice_number")
    ps = {"ELEVENLABS_NOT_CONFIGURED": "elevenlabs_not_configured", "ELEVENLABS_DISABLED": "elevenlabs_disabled",
          "ELEVENLABS_UNVERIFIED": "elevenlabs_unverified", "ELEVENLABS_VERIFIED": "elevenlabs_unverified" if BUG == "voiceCallPolicy:verified_adds" else None}[x["providerState"]]
    if ps:
        rs.append(ps)
    if x["canary"] != "active_voice":
        rs.append("not_an_active_voice_canary")
    if x["source"] != "canary_registry":
        rs.append("source_not_allowed")
    if (x["dailyCap"] < 0) if BUG == "voiceCallPolicy:negative_only" else (x["dailyCap"] <= 0):
        rs.append("daily_cap_zero")
    if BUG == "voiceCallPolicy:first_only":
        rs = rs[:1]
    excl = {"not_an_active_voice_canary", "daily_cap_zero"} | ({"source_not_allowed"} if BUG == "voiceCallPolicy:integration_excludes_source" else set())
    it = [r for r in rs if r not in excl]
    return {"allowed": not rs, "reasons": rs,
            "levels": [{"id": "text_simulation", "available": True, "cost": "none", "where": "laboratorio"},
                       {"id": "integration_test", "available": not it, "reasons": it},
                       {"id": "real_call", "available": not rs, "reasons": rs}]}


# ── twilioProbeSanitize ─────────────────────────────────────────────────────
STEPS = ["account", "numbers", "caller_ids", "calls", "alerts"]
CALL_ST = {"queued", "ringing", "in-progress", "canceled", "completed", "failed", "busy", "no-answer"}
LEVELS = {"error", "warning", "notice", "debug"}


def counts(values):
    d = {}
    for v in values:
        d[v] = d.get(v, 0) + 1
    return {k: d[k] for k in sorted(d)} if d else []


def last4(pn):
    if BUG == "twilioProbeSanitize:last4_from_digits":
        d = re.sub(r"[^0-9]", "", str(pn)) if pn is not None else ""
        return d[-4:] if len(d) >= 4 else None
    pattern = r"\+[1-9][0-9]{6,14}" if BUG == "twilioProbeSanitize:e164_seven_digits" else r"\+[1-9][0-9]{7,14}"
    return pn[-4:] if isinstance(pn, str) and re.fullmatch(pattern, pn) else None


def alert_code(code):
    if isinstance(code, bool) or not isinstance(code, (str, int)):
        return "other"
    s = str(code)
    pattern = r"[1-9][0-9]{4}" if BUG == "twilioProbeSanitize:five_digit_codes" else r"[1-9][0-9]{0,5}"
    return s if re.fullmatch(pattern, s) else "other"


SID = re.compile(r"AC[0-9a-f]{32}")


def section(step, body):
    """None = cuerpo inesperado (el paso es error con el estado que vino)."""
    if not isinstance(body, dict):
        return None
    if step == "account":
        if not (isinstance(body.get("sid"), str) and SID.fullmatch(body["sid"])) and BUG != "twilioProbeSanitize:account_without_sid_ok":
            return None
        st, ty = body.get("status"), body.get("type")
        return {"status": st if st in ("active", "suspended", "closed") else None, "type": ty if ty in ("Trial", "Full") else None}
    key = {"numbers": "incoming_phone_numbers", "caller_ids": "outgoing_caller_ids", "calls": "calls", "alerts": "alerts"}[step]
    lst = body.get(key)
    if not isinstance(lst, list):
        return [] if BUG == "twilioProbeSanitize:missing_list_is_zero" else None
    if BUG == "twilioProbeSanitize:skip_non_object_entries":
        lst = [i for i in lst if isinstance(i, dict)]
    if not all(isinstance(i, dict) for i in lst):
        return None
    size = {"numbers": 20, "caller_ids": 20, "calls": 50, "alerts": 50}[step]
    trunc = len(lst) > size if BUG == "twilioProbeSanitize:truncated_gt" else len(lst) >= size
    if BUG != "twilioProbeSanitize:no_trim":
        lst = lst[:size]
    n = len(lst)
    if step == "numbers":
        def cap(item, k):
            caps_ = item.get("capabilities")
            v = caps_.get(k) if isinstance(caps_, dict) else None
            return bool(v) if BUG == "twilioProbeSanitize:caps_truthy" else v is True
        items = [{"last4": last4(i.get("phone_number")), "voice": cap(i, "voice"), "sms": cap(i, "sms")} for i in lst]
        if BUG == "twilioProbeSanitize:keep_friendly_name":
            for it_, i in zip(items, lst):
                it_["name"] = i.get("friendly_name")
        return {"count": n, "truncated": trunc, "voiceCapable": sum(1 for i in items if i["voice"]),
                "smsCapable": sum(1 for i in items if i["sms"]), "items": items}
    if step == "caller_ids":
        return {"count": n, "truncated": trunc}
    if step == "calls":
        sts = [i.get("status") if (i.get("status") in CALL_ST or BUG == "twilioProbeSanitize:keep_unknown_status") else "other" for i in lst]
        return {"total": n, "truncated": trunc, "byStatus": counts(sts)}
    codes = [alert_code(i.get("error_code")) for i in lst]
    levels = [i.get("log_level") if i.get("log_level") in LEVELS else "other" for i in lst]
    return {"total": n, "truncated": trunc, "byCode": counts(codes), "byLevel": counts(levels)}


def envelope(r):
    """Etiqueta y estado de un paso fallido, con la higiene de D-10/C16."""
    label = r.get("error")
    st = r.get("status")
    if BUG == "twilioProbeSanitize:raw_envelope":
        return label, st
    label = label if label in ("auth_failed", "unreachable", "error") else "error"
    st = st if isinstance(st, int) and not isinstance(st, bool) and 100 <= st <= 599 else None
    return label, st


def d_probe(c):
    res = {s: c["responses"].get(s, {"skipped": True}) for s in STEPS}
    secs, errors = {}, []
    for s in STEPS:
        r = res[s]
        if r.get("skipped"):
            secs[s] = None
            errors.append({"step": s, "outcome": "skipped", "httpStatus": None})
        elif r.get("ok") is not True:
            secs[s] = None
            label, st = envelope(r)
            errors.append({"step": s, "outcome": label, "httpStatus": st})
        else:
            sec = section(s, r.get("body"))
            if sec is None:
                errors.append({"step": s, "outcome": "error", "httpStatus": r.get("status")})
            secs[s] = sec
    acc = res["account"]
    acc_err = next((e for e in errors if e["step"] == "account"), None)
    if acc.get("skipped"):
        outcome = "error"
    elif acc_err is not None:
        outcome = acc_err["outcome"] if acc_err["outcome"] in ("auth_failed", "unreachable") else (
            "degraded" if BUG == "twilioProbeSanitize:degraded_on_account_error" else "error")
    else:
        outcome = "degraded" if errors else "ok"
    facts = {"credential": c["credential"], "account": secs["account"], "numbers": secs["numbers"], "callerIds": secs["caller_ids"],
             "calls7d": secs["calls"], "alerts24h": secs["alerts"], "errors": errors}
    return {"outcome": outcome, "facts": facts, "mustNotContain": c["expected"]["mustNotContain"]}


# ── twilioSignatureOfficial ─────────────────────────────────────────────────
def d_tw_official(c):
    pairs = c["params"]
    if BUG == "twilioSignatureOfficial:no_sort":
        base = c["url"] + "".join(k + v for k, v in pairs)
    elif BUG == "twilioSignatureOfficial:implode_duplicates":
        base = c["url"]
        for k in sorted({k for k, _ in pairs}):
            base += k + "".join(v for kk, v in pairs if kk == k)
    else:
        base = c["url"]
        for k in sorted({k for k, _ in pairs}):
            for v in sorted({v for kk, v in pairs if kk == k}):
                base += k + v
    calc = hmac1_b64(c["authToken"].encode(), base.encode())
    out = {"signature": c["expected"]["signature"], "valid": calc == c["expected"]["signature"]}
    if "body" in c:
        h = sha256_hex(c["body"].encode())
        out["bodySHA256"] = h if c["url"].endswith("&bodySHA256=" + h) else "la URL no lleva la huella del cuerpo"
    return out


# ── probeRules / acknowledgeRules ───────────────────────────────────────────
def d_probe_rules(c):
    f = c["facts"]
    a = f["actor"]
    if not a["person"]:
        return {"status": 403, "code": "person_required"}
    for k in ["ultron.view", "ultron.integrations_manage"]:
        if k not in a["permissions"]:
            return {"status": 403, "code": "forbidden", "payload": {"required_permission": k}}
    if BUG == "probeRules:requires_writes" and not f["writesEnabled"]:
        return {"status": 409, "code": "integrations_writes_disabled", "audit": None}
    if BUG == "probeRules:replay_before_flag" and f["clientRequest"] == "replay":
        return {"status": 200, "code": "replayed", "network": False}
    if not f["probeEnabled"]:
        return {"status": 409, "code": "probe_disabled", "audit": None}
    if f["clientRequest"] == "replay":
        return {"status": 200, "code": "replayed", "network": False}
    if f["clientRequest"] != "new":
        return {"status": 409, "code": "client_request_reused"}
    cool = f["lastProbeAgeSeconds"] is not None and (
        f["lastProbeAgeSeconds"] <= f["cooldownSeconds"] if BUG == "probeRules:cooldown_le" else f["lastProbeAgeSeconds"] < f["cooldownSeconds"])
    if BUG == "probeRules:cooldown_before_config" and cool:
        return {"status": 200, "code": "reused", "network": False, "audit": None}
    sid = f["otp"]["sid"]
    if not (isinstance(sid, str) and re.fullmatch(r"AC[0-9a-f]{32}", sid)):
        return {"status": 409, "code": "twilio_not_configured", "payload": {"reason": "missing_account_sid"}}
    if filled(f["probeKey"]["sid"]) and filled(f["probeKey"]["secret"]):
        cred = "restricted_key"
    elif filled(f["otp"]["token"]):
        cred = "otp"
    else:
        return {"status": 409, "code": "twilio_not_configured", "payload": {"reason": "missing_credential"}}
    if cool:
        return {"status": 200, "code": "reused", "network": False, "audit": None}
    return {"status": 201, "code": None, "credential": cred, "network": True, "audit": {"event": "integration.probe_run", "from": None, "to": None}}


def d_ack(c):
    f = c["facts"]
    a = f["actor"]
    if not a["person"]:
        return {"status": 403, "code": "person_required"}
    for k in ["ultron.view", "ultron.integrations_manage"]:
        if k not in a["permissions"]:
            return {"status": 403, "code": "forbidden", "payload": {"required_permission": k}}
    if BUG == "acknowledgeRules:requires_writes" and not f["writesEnabled"]:
        return {"status": 409, "code": "integrations_writes_disabled"}
    if BUG == "acknowledgeRules:changed_before_replay" and f["sentFingerprint"] != f["effectiveFingerprint"]:
        return {"status": 409, "code": "config_changed", "payloadIncludes": {"provider": f["provider"], "effectiveFingerprint": f["effectiveFingerprint"]}}
    if f["clientRequest"] == "replay":
        return {"status": 200, "code": "replayed"}
    if f["clientRequest"] != "new":
        return {"status": 409, "code": "client_request_reused"}
    if f["sentFingerprint"] != f["effectiveFingerprint"]:
        return {"status": 409, "code": "config_changed", "payloadIncludes": {"provider": f["provider"], "effectiveFingerprint": f["effectiveFingerprint"]}}
    return {"status": 201, "code": None, "snapshot": {"provider": f["provider"], "kind": "config", "actorType": "admin", "fingerprint": f["sentFingerprint"]},
            "audit": {"event": "integration.config_acknowledged", "from": None, "to": None}}


# ── routeMatrix / errorOrder ────────────────────────────────────────────────
def d_route(c, fixture):
    if c["kind"] == "legacy":
        return {"roles": {"sinSesion": 401, "tokenCompartido": 403, "Recepción": 403, "Entrenador": 403, "Administrativo": 403,
                          "Administrador": "unchanged", "Super Admin": "unchanged"}, "studioRoutesTotal": 38 + DECLARED["errorOrder"]}
    if c["kind"] == "noView":
        return {"status": 403, "code": "forbidden", "payload": {"required_permission": "ultron.view"}}
    admin = {"ultron.view"} | {p["key"] for p in fixture["closedSets"]["permissions"] if p["administradorDefault"]}
    if BUG == "routeMatrix:admin_has_all":
        admin |= {p["key"] for p in fixture["closedSets"]["permissions"]}
    return {"roles": {"sinSesion": 401, "tokenCompartido": 403, "Recepción": 403, "Entrenador": 403, "Administrativo": 403,
                      "Administrador": "allowed" if c["permission"] in admin else 403, "Super Admin": "allowed"}}


DERIVE = {"phone": d_phone, "alias": d_alias, "fingerprint": d_fingerprint, "canaryTransitions": d_canary, "coherence": d_coherence,
          "expectation": d_expectation, "campaignLinkRules": d_campaign, "metaClassification": d_meta, "providerClassification": d_status, "configFacts": d_config_facts,
          "configDrift": d_config_drift, "elevenlabsSignature": d_el_sig, "elevenlabsEnvelope": d_el_env, "voiceDoctor": d_voice_doctor,
          "voiceVerify": d_voice_verify, "voiceCallPolicy": d_voice_policy, "twilioProbeSanitize": d_probe,
          "twilioSignatureOfficial": d_tw_official, "probeRules": d_probe_rules, "acknowledgeRules": d_ack, "routeMatrix": d_route}
NEEDS_FIXTURE = {"configDrift", "routeMatrix"}

VARIANTS = [
    "phone:strip_all_whitespace", "phone:dollar_newline", "phone:unicode_digits", "phone:first_prefix", "phone:contains_prefix",
    "phone:channel_rule_any_country", "phone:whatsapp_allows_landline", "phone:country_before_format", "phone:masked_no_spaces",
    "alias:bytes", "alias:trim", "alias:dollar_newline", "alias:no_contact", "alias:run5",
    "fingerprint:undecoded_key", "fingerprint:swapped_subkey", "fingerprint:no_subkey", "fingerprint:h5_label", "fingerprint:digits_only",
    "fingerprint:no_validation", "fingerprint:dollar_newline",
    "canaryTransitions:no_view_check", "canaryTransitions:integrity_before_status", "canaryTransitions:identity_by_name",
    "canaryTransitions:no_four_eyes", "canaryTransitions:activate_needs_authorize", "canaryTransitions:replay_before_flag",
    "canaryTransitions:exists_before_limit", "canaryTransitions:limit_before_version", "canaryTransitions:state_before_identity",
    "canaryTransitions:limit_gt", "canaryTransitions:cipher_always", "canaryTransitions:actions_identity_before_state",
    "canaryTransitions:actions_limit_before_identity",
    "coherence:no_57", "coherence:default_in_sync", "coherence:revoked_counts", "coherence:voice_counts_extra",
    "expectation:rest_first", "expectation:chain_for_non_engine", "expectation:not_applicable_first", "expectation:stale_ignores_integrity",
    "campaignLinkRules:sync_disabled_is_not_configured", "campaignLinkRules:meta_before_replay", "campaignLinkRules:integrity_before_status",
    "campaignLinkRules:moved_without_active_is_linked", "campaignLinkRules:limit_counts_replaced",
    "metaClassification:problem_after_sync", "metaClassification:sync_disabled_only_when_ok", "metaClassification:any_status",
    "metaClassification:backfill_counts", "metaClassification:string_compare", "metaClassification:le", "metaClassification:case_sensitive",
    "providerClassification:gt_hard", "providerClassification:limit_before_killswitch", "providerClassification:voice_loose_e164",
    "providerClassification:counts_degrade",
    "configFacts:undecoded_key", "configFacts:escape_unicode", "configFacts:absent_with_source", "configFacts:host_contains",
    "configFacts:float_costs", "configFacts:credential_any_key", "configFacts:voice_untrimmed", "configDrift:raw_order",
    "elevenlabsSignature:empty_secret_ok", "elevenlabsSignature:unicode_trim", "elevenlabsSignature:part_without_eq_ignored",
    "elevenlabsSignature:trim_kv", "elevenlabsSignature:case_insensitive_keys", "elevenlabsSignature:lenient_v0",
    "elevenlabsSignature:t_as_int", "elevenlabsSignature:no_t_prefix", "elevenlabsSignature:case_sensitive_v0",
    "elevenlabsSignature:signature_first", "elevenlabsSignature:le_expired", "elevenlabsSignature:no_future",
    "elevenlabsEnvelope:case_insensitive", "elevenlabsEnvelope:trim",
    "voiceDoctor:region_not_normalized", "voiceDoctor:key_not_trimmed", "voiceDoctor:agent_dollar", "voiceDoctor:provider_always_elevenlabs",
    "voiceDoctor:base_url_in_production", "voiceDoctor:any_ok", "voiceDoctor:le_30d", "voiceVerify:one_format_enough", "voiceVerify:non_object_is_ok",
    "voiceCallPolicy:counts_whatsapp", "voiceCallPolicy:verified_adds", "voiceCallPolicy:negative_only", "voiceCallPolicy:first_only",
    "voiceCallPolicy:integration_excludes_source",
    "twilioProbeSanitize:missing_list_is_zero", "twilioProbeSanitize:truncated_gt", "twilioProbeSanitize:caps_truthy",
    "twilioProbeSanitize:keep_friendly_name", "twilioProbeSanitize:keep_unknown_status", "twilioProbeSanitize:degraded_on_account_error",
    "twilioProbeSanitize:last4_from_digits", "twilioProbeSanitize:e164_seven_digits", "twilioProbeSanitize:five_digit_codes",
    "twilioProbeSanitize:account_without_sid_ok", "twilioProbeSanitize:skip_non_object_entries", "twilioProbeSanitize:no_trim",
    "twilioProbeSanitize:raw_envelope",
    "twilioSignatureOfficial:no_sort", "twilioSignatureOfficial:implode_duplicates",
    "probeRules:requires_writes", "probeRules:replay_before_flag", "probeRules:cooldown_le", "probeRules:cooldown_before_config",
    "acknowledgeRules:requires_writes", "acknowledgeRules:changed_before_replay", "routeMatrix:admin_has_all",
]


def leaks(section_name, derived):
    """Lo que dice mustNotContain se busca en el JSON canónico de lo derivado."""
    if section_name in ("configFacts", "twilioProbeSanitize") and isinstance(derived, dict) and "facts" in derived:
        cj = php_json(derived["facts"])
        return [s for s in derived.get("mustNotContain", []) if s in cj]
    return []


def run_section(fx, s):
    bad = []
    for c in fx[s]:
        try:
            got = DERIVE[s](c, fx) if s in NEEDS_FIXTURE else DERIVE[s](c)
        except Exception as e:  # noqa: BLE001 — una variante puede romper la derivación: eso también la caza
            got = {"exception": f"{type(e).__name__}: {e}"}
        if got != c["expected"] or leaks(s, got):
            bad.append((c["name"], got))
    return bad


def check_structure(fx, raw_bytes):
    problems = []
    text = raw_bytes.decode("utf-8")
    if not text.endswith("\n"):
        problems.append("no termina en salto de línea")
    for ch in set(text):
        if ch not in "\n " and unicodedata.category(ch) in {"Cc", "Cf", "Zs", "Zl", "Zp", "Mn", "Co", "Cn"}:
            problems.append(f"carácter invisible literal U+{ord(ch):04X}")
    if fx.get("version") != 1 or fx.get("catalogVersion") != "2026-10-19.1":
        problems.append("version/catalogVersion")
    names = set()

    def walk(o, path):
        if isinstance(o, dict):
            if not o:
                problems.append(f"objeto vacío en {path}")
            for k, v in o.items():
                walk(v, f"{path}.{k}")
        elif isinstance(o, list):
            for i, v in enumerate(o):
                walk(v, f"{path}[{i}]")
        elif isinstance(o, float) and not path.endswith(("otp.cost.daily_soft", "otp.cost.daily_hard", "estimatedSpentTodayUsd", "dailyHardLimitUsd")):
            problems.append(f"decimal fuera de la configuración de entrada en {path}")

    walk(fx, "$")
    for s, n in DECLARED.items():
        got = len(fx.get(s, []))
        if got != n:
            problems.append(f"{s}: {got} casos, el README declara {n}")
        if s in MINIMUM and got < MINIMUM[s]:
            problems.append(f"{s}: {got} < mínimo {MINIMUM[s]} de I11")
        for c in fx.get(s, []):
            if c["name"] in names:
                problems.append(f"nombre repetido: {c['name']}")
            names.add(c["name"])
            if not c["name"].startswith(f"{s} · {c['kind']} · "):
                problems.append(f"nombre sin prefijo de sección y tipo: {c['name']}")
    extra = set(fx) - set(DECLARED) - {"version", "catalogVersion", "contract", "source", "keys", "closedSets"}
    if extra:
        problems.append(f"secciones no declaradas: {sorted(extra)}")
    verdicts = {c["expected"]["verdict"] for c in fx["coherence"]}
    if verdicts != set(fx["closedSets"]["coherenceVerdict"]):
        problems.append(f"coherence no cubre los 7 veredictos: {sorted(verdicts)}")
    kinds = {c["kind"] for c in fx["canaryTransitions"]}
    if not {"register", "authorize", "activate", "deactivate", "revoke", "expectation"} <= kinds:
        problems.append("canaryTransitions no cubre las 6 filas de I4.4")
    # Coherencias cruzadas entre transcripciones del contrato
    codes = {e["code"]: e for e in fx["closedSets"]["errorCodes"]}
    order_codes = {}
    for c in fx["errorOrder"]:
        rc = {st["code"] for st in c["expected"]["order"] if st["code"] not in (None, "replayed", "reused")}
        order_codes[c["route"]] = rc
        for code in rc:
            if code not in codes or c["route"] not in codes[code]["routes"]:
                problems.append(f"errorOrder {c['route']}: {code} no está declarado para esa ruta en closedSets.errorCodes")
    for code, e in codes.items():
        for r in e["routes"]:
            if code not in order_codes.get(r, set()):
                problems.append(f"closedSets.errorCodes: {code} declarado en {r} y ausente de su errorOrder")
    for sec, route_of in [("canaryTransitions", {"register": 49, "authorize": 50, "activate": 51, "deactivate": 52, "revoke": 53, "expectation": 54}),
                          ("campaignLinkRules", {"create": 47, "deactivate": 48}), ("probeRules", {"probe": 56}), ("acknowledgeRules", {"acknowledge": 55})]:
        for c in fx[sec]:
            code = c["expected"].get("code") if "status" in c["expected"] else None
            r = route_of.get(c["kind"])
            if r and code not in (None, "replayed", "reused") and code not in order_codes[r]:
                problems.append(f"{c['name']}: {code} no está en el orden de la ruta {r}")
    perm_keys = {p["key"] for p in fx["closedSets"]["permissions"]} | {"ultron.view"}
    for c in fx["routeMatrix"]:
        if c["kind"] == "route" and c["permission"] not in perm_keys:
            problems.append(f"{c['name']}: permiso desconocido")
    # Los centinelas tienen que existir de verdad en la entrada de algún caso
    for sec, field in [("configFacts", "config"), ("twilioProbeSanitize", "responses")]:
        blob = json.dumps([c[field] for c in fx[sec]], ensure_ascii=False)
        for c in fx[sec]:
            for s in c["expected"]["mustNotContain"]:
                if s not in blob:
                    problems.append(f"{c['name']}: el centinela «{s}» no aparece en ninguna entrada")
    return problems


PHP = r"""<?php
declare(strict_types=1);
require $argv[1];
use App\Services\UltronStudio\StudioContentHasher as H;
$fx = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
$fallos = 0; $n = [];
$k = static fn (string $ak): string => str_starts_with($ak, 'base64:') ? base64_decode(substr($ak, 7), true) : $ak;
$fp = static function (string $e164, string $ak) use ($k): string {
    $sub = hash_hmac('sha256', 'ultron-canary-phone/1', $k($ak), true);
    return hash_hmac('sha256', $e164, $sub);
};
foreach ($fx['fingerprint'] as $c) {
    if ($c['kind'] !== 'vector') { continue; }
    $n['phoneFp'] = ($n['phoneFp'] ?? 0) + 1;
    if (bin2hex(hash_hmac('sha256', 'ultron-canary-phone/1', $k($c['appKey']), true)) !== $c['expected']['subkeyHex']
        || $fp($c['e164'], $c['appKey']) !== $c['expected']['fingerprint']
        || bin2hex($k($c['appKey'])) !== $c['appKeyRawHex']) { $fallos++; echo "fp: {$c['name']}\n"; }
}
foreach ($fx['coherence'] as $c) {
    if ($c['expected']['engineHash'] === null) { continue; }
    $n['engineHash'] = ($n['engineHash'] ?? 0) + 1;
    if ($fp($c['expected']['normalizedE164'], $c['appKey']) !== $c['expected']['engineHash']) { $fallos++; echo "coh: {$c['name']}\n"; }
}
foreach (['configFacts', 'configDrift'] as $sec) {
    foreach ($fx[$sec] as $c) {
        $facts = $sec === 'configFacts' ? $c['expected']['facts'] : $c['effective'];
        $cj = H::canonicalJson($facts);
        $n[$sec] = ($n[$sec] ?? 0) + 1;
        $want = $sec === 'configFacts' ? $c['expected']['fingerprint'] : $c['expected']['effectiveFingerprint'];
        if ($sec === 'configFacts' && $cj !== $c['expected']['canonicalJson']) { $fallos++; echo "bytes: {$c['name']}\n"; }
        if (hash('sha256', $cj) !== $want || H::hash($facts) !== $want) { $fallos++; echo "huella: {$c['name']}\n"; }
        if ($sec === 'configDrift' && $c['authorized'] !== null && H::hash($c['authorized']['facts']) !== $c['authorized']['fingerprint']) { $fallos++; echo "huella reconocida: {$c['name']}\n"; }
        if ($sec === 'configFacts' && $c['kind'] === 'twilio') {
            foreach (['otp.cost.daily_soft' => 'costSoftUsd', 'otp.cost.daily_hard' => 'costHardUsd'] as $ck => $fk) {
                if (number_format((float) $c['config'][$ck], 2, '.', '') !== $facts[$fk]) { $fallos++; echo "importe: {$c['name']}\n"; }
            }
            $kc = hash_hmac('sha256', 'ultron-studio-config-fingerprint/1', $k($c['appKey']), true);
            if ($facts['authToken']['present'] && substr(hash_hmac('sha256', (string) $c['config']['otp.twilio.token'], $kc), 0, 8) !== $facts['authToken']['fp']) { $fallos++; echo "fp token: {$c['name']}\n"; }
        }
    }
}
foreach ($fx['elevenlabsSignature'] as $c) {
    $r = $c['expected']['result'] ?? null;
    if (! in_array($r, ['ok', 'mismatch'], true)) { continue; }
    $n['elevenlabs'] = ($n['elevenlabs'] ?? 0) + 1;
    $t = null; $vs = [];
    foreach (explode(',', (string) $c['header']) as $p) {
        $p = trim($p); if ($p === '' || ! str_contains($p, '=')) { continue; }
        [$kk, $vv] = explode('=', $p, 2);
        if ($kk === 't') { $t = $vv; } elseif ($kk === 'v0') { $vs[] = strtolower($vv); }
    }
    $esperada = hash_hmac('sha256', $t.'.'.$c['rawBody'], $c['secret']);
    $vale = false; foreach ($vs as $v) { $vale = $vale || hash_equals($esperada, $v); }
    if ($vale !== ($r === 'ok')) { $fallos++; echo "firma: {$c['name']}\n"; }
}
foreach ($fx['twilioSignatureOfficial'] as $c) {
    $n['twilio'] = ($n['twilio'] ?? 0) + 1;
    $por = [];
    foreach ($c['params'] as [$kk, $vv]) { $por[$kk][] = $vv; }
    ksort($por, SORT_STRING);
    $base = $c['url'];
    foreach ($por as $kk => $vals) { $vals = array_values(array_unique($vals)); sort($vals, SORT_STRING); foreach ($vals as $vv) { $base .= $kk.$vv; } }
    $vale = hash_equals(base64_encode(hash_hmac('sha1', $base, $c['authToken'], true)), $c['expected']['signature']);
    if ($vale !== $c['expected']['valid']) { $fallos++; echo "twilio: {$c['name']}\n"; }
    if (isset($c['body']) && hash('sha256', $c['body']) !== $c['expected']['bodySHA256']) { $fallos++; echo "bodySHA256: {$c['name']}\n"; }
}
// Pruebas de las trampas que el fixture fija (PCRE2 de este PHP)
$n['trampaDolarSinD'] = preg_match('/^\+[1-9]\d{7,14}$/', "+573000000001\n");
$n['trampaDolarConD'] = preg_match('/^\+[1-9]\d{7,14}$/D', "+573000000001\n");
$n['trampaUnicodeConU'] = preg_match('/^\+[1-9]\d{7,14}$/u', "+57\u{FF13}\u{FF10}\u{FF10}\u{FF10}\u{FF10}\u{FF10}\u{FF10}\u{FF10}\u{FF10}\u{FF11}");
$n['trampaUnicodeSinU'] = preg_match('/^\+[1-9]\d{7,14}$/', "+57\u{FF13}\u{FF10}\u{FF10}\u{FF10}\u{FF10}\u{FF10}\u{FF10}\u{FF10}\u{FF10}\u{FF11}");
try { H::canonicalJson(['costSoftUsd' => 6.0]); $n['canonicalJsonConDecimal'] = 'acepta'; } catch (\InvalidArgumentException) { $n['canonicalJsonConDecimal'] = 'InvalidArgumentException'; }
$n['trimDePhpQuitaNbsp'] = trim("\u{00A0}v0") === 'v0' ? 'sí' : 'no';
echo json_encode($n, JSON_UNESCAPED_UNICODE), "\nfallos PHP: {$fallos}\n";
exit($fallos === 0 ? 0 : 1);
"""


def php_crosscheck():
    hasher = BACKEND / "app/Services/UltronStudio/StudioContentHasher.php"
    with tempfile.TemporaryDirectory() as d:
        script = Path(d) / "crosscheck.php"
        script.write_text(PHP, encoding="utf-8")
        p = subprocess.run(["php", str(script), str(hasher), str(FIXTURE)], capture_output=True, text=True)
        print(p.stdout.strip())
        if p.stderr.strip():
            print(p.stderr.strip())
        return p.returncode == 0


IMPL_PHP = r"""<?php
declare(strict_types=1);
require $argv[1];
use App\Services\Twilio\TwilioProbeSanitizer;
use App\Services\Voice\ElevenLabs\ElevenLabsWebhookEnvelope;
use App\Services\Voice\ElevenLabs\ElevenLabsWebhookSignature;
$fx = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
$out = [];
foreach ($fx['twilioProbeSanitize'] as $c) {
    try { $got = TwilioProbeSanitizer::sanitize($c['responses'], $c['credential']); } catch (\Throwable $e) { $got = ['exception' => $e::class]; }
    $want = ['outcome' => $c['expected']['outcome'], 'facts' => $c['expected']['facts']];
    $out[] = ['section' => 'twilioProbeSanitize', 'name' => $c['name'], 'strict' => $got === $want, 'loose' => $got == $want, 'got' => $got];
}
foreach ($fx['elevenlabsSignature'] as $c) {
    try { $got = ['result' => ElevenLabsWebhookSignature::verify($c['rawBody'], $c['header'], $c['secret'], $c['now'])]; }
    catch (\InvalidArgumentException) { $got = ['throws' => 'InvalidArgumentException']; }
    catch (\Throwable $e) { $got = ['exception' => $e::class]; }
    $out[] = ['section' => 'elevenlabsSignature', 'name' => $c['name'], 'strict' => $got === $c['expected'], 'loose' => $got == $c['expected'], 'got' => $got];
}
foreach ($fx['elevenlabsEnvelope'] as $c) {
    try { $got = ['type' => ElevenLabsWebhookEnvelope::type($c['rawBody'])]; } catch (\Throwable $e) { $got = ['exception' => $e::class]; }
    $out[] = ['section' => 'elevenlabsEnvelope', 'name' => $c['name'], 'strict' => $got === $c['expected'], 'loose' => $got == $c['expected'], 'got' => $got];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
"""


def impl_crosscheck():
    """Las clases ya confirmadas de V y TW contra este fixture (no al revés: si discrepan, se corrige la clase o se enmienda)."""
    with tempfile.TemporaryDirectory() as d:
        script = Path(d) / "impl.php"
        script.write_text(IMPL_PHP, encoding="utf-8")
        p = subprocess.run(["php", str(script), str(BACKEND / "vendor/autoload.php"), str(FIXTURE)], capture_output=True, text=True)
    if p.returncode != 0:
        print("cruce con las clases: el script falló:", p.stderr.strip()[:500])
        return False
    rows = json.loads(p.stdout)
    ok = True
    for sec in ("twilioProbeSanitize", "elevenlabsSignature", "elevenlabsEnvelope"):
        mine = [r for r in rows if r["section"] == sec]
        strict = sum(1 for r in mine if r["strict"])
        loose = sum(1 for r in mine if r["loose"] and not r["strict"])
        bad = [r for r in mine if not r["loose"]]
        print(f"clase real · {sec}: {strict}/{len(mine)} idénticos" + (f", {loose} iguales salvo el orden de claves" if loose else "")
              + (f", {len(bad)} DISCREPAN" if bad else ""))
        for r in bad:
            print(f"  DISCREPA {r['name']}\n    la clase da: {json.dumps(r['got'], ensure_ascii=False)[:600]}")
        ok = ok and not bad and not loose
    return ok


def tw_crosscheck():
    """Mi derivación independiente contra los 17 casos confirmados del paquete TW (tests/Unit/Twilio/fixtures)."""
    base = BACKEND / "tests/Unit/Twilio/fixtures"
    cases = json.loads((base / "sanitize-cases.json").read_text(encoding="utf-8"))
    api = json.loads((base / "twilio-api-responses.json").read_text(encoding="utf-8"))

    def resolve(o):
        if isinstance(o, dict) and set(o) == {"$ref"}:
            return json.loads(json.dumps(api[o["$ref"]]))
        if isinstance(o, dict):
            return {k: resolve(v) for k, v in o.items()}
        if isinstance(o, list):
            return [resolve(v) for v in o]
        return o

    def empty_maps(o):
        if isinstance(o, dict):
            return [] if not o else {k: empty_maps(v) for k, v in o.items()}
        if isinstance(o, list):
            return [empty_maps(v) for v in o]
        return o

    bad = 0
    for c in cases["cases"]:
        got = d_probe({"responses": resolve(c["responses"]), "credential": c["credential"], "expected": {"mustNotContain": []}})
        want = empty_maps(c["expected"])
        leaked = [s for s in cases.get("sentinels", []) if s in php_json(got["facts"])]
        if {"outcome": got["outcome"], "facts": got["facts"]} != want or leaked:
            bad += 1
            print(f"  DISCREPA con TW {c['name']}\n    mi derivación: {json.dumps(got, ensure_ascii=False)[:600]}"
                  + (f"\n    centinelas filtrados: {leaked}" if leaked else ""))
    print(f"cruce con los fixtures de TW: {len(cases['cases'])} casos · discrepancias: {bad}")
    return bad == 0


def main():
    global BUG, FIXTURE
    if "--fixture" in sys.argv:
        FIXTURE = Path(sys.argv[sys.argv.index("--fixture") + 1]).resolve()
    raw = FIXTURE.read_bytes()
    fx = json.loads(raw)
    print(f"fixture: {FIXTURE.name} · {len(raw)} bytes · openssl: " + subprocess.run(["openssl", "version"], capture_output=True, text=True).stdout.strip())
    problems = check_structure(fx, raw)
    print("estructura:", "OK" if not problems else f"{len(problems)} problemas")
    for p in problems:
        print("  -", p)
    print("casos:", json.dumps({s: len(fx[s]) for s in DECLARED}, ensure_ascii=False), "total", sum(len(fx[s]) for s in DECLARED))
    BUG = None
    mismatches = 0
    for s in DERIVE:
        bad = run_section(fx, s)
        mismatches += len(bad)
        for name, got in bad[:5]:
            print(f"  DISCREPANCIA {name}\n    derivado: {json.dumps(got, ensure_ascii=False)[:400]}")
    errs = [c for c in fx["errorOrder"]]
    print(f"derivación independiente: {sum(len(fx[s]) for s in DERIVE)} casos rehechos · discrepancias: {mismatches}"
          f" (errorOrder, {len(errs)} rutas, se cruza en la estructura)")
    caught_all = True
    print(f"variantes equivocadas simuladas: {len(VARIANTS)}")
    for v in VARIANTS:
        BUG = v
        sec = v.split(":")[0]
        bad = run_section(fx, sec)
        if v.startswith("fingerprint:"):
            bad += run_section(fx, "coherence")
        if not bad:
            caught_all = False
        print(f"  {'cazada' if bad else 'NO CAZADA'} · {v} · {len(bad)} casos")
    BUG = None
    ok = not problems and mismatches == 0 and caught_all
    if "--php" in sys.argv:
        ok = php_crosscheck() and ok
    if "--tw" in sys.argv:
        ok = tw_crosscheck() and ok
    if "--impl" in sys.argv:
        ok = impl_crosscheck() and ok
    print("RESULTADO:", "OK" if ok else "FALLA")
    return 0 if ok else 1


if __name__ == "__main__":
    sys.exit(main())

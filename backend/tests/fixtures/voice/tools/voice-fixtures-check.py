#!/usr/bin/env python3
"""Comprobador INDEPENDIENTE de los fixtures de voz del Hito 8 (tests/fixtures/voice/*.json).

No importa el generador (tools/build/): vuelve a derivar cada `expected` con su propia implementación de referencia,
escrita aparte desde el contrato, y con otra maquinaria:
  - toda la criptografía (HMAC-SHA1, HMAC-SHA256, sha256) con el binario `openssl`, no con hmac/hashlib;
  - la Pascua con el algoritmo de Gauss (el generador usa el gregoriano anónimo) y el traslado al lunes con otra fórmula;
  - la hora de Bogotá con zoneinfo («America/Bogota»; el generador usa un desfase fijo).

Además:
  - valida la FORMA de cada fichero (cabecera, recuentos, nombres únicos, base y decisiones definidas, sin invisibles literales);
  - cruza ficheros (los estados que citan los cuerpos y la puerta existen y sus transiciones están permitidas);
  - prueba VARIANTES EQUIVOCADAS de cada regla: cada una tiene que hacer fallar al menos un caso;
  - --php: rehace el JSON canónico y su sha256 con StudioContentHasher (H5), las firmas de ElevenLabs con
    ElevenLabsWebhookSignature::verify y el sobre con ElevenLabsWebhookEnvelope::type (H6, sin cambios por D8-16),
    los HMAC con hash_hmac y la Pascua con easter_days(). Carga SOLO esas tres clases puras: no arranca Laravel;
  - --sdk-python RUTA: rehace sdkConstructEvent con el SDK oficial de ElevenLabs instalado en ese intérprete
    (el reloj se congela en `now`). Sin SDK no se ejecuta: los vectores quedan «por confirmar con el SDK».

Uso:  python3 -I tests/fixtures/voice/tools/voice-fixtures-check.py [--dir DIR] [--php] [--sdk-python RUTA] [--no-variants]
Sale con 0 si todo cuadra y con 1 si algo discrepa. No lo ejecuta la suite.
"""
import argparse
import base64
import copy
import datetime as dt
import json
import math
import re
import subprocess
import sys
import tempfile
import unicodedata
import urllib.parse
from pathlib import Path
from zoneinfo import ZoneInfo

HERE = Path(__file__).resolve().parent
BACKEND = HERE.parents[3]
FILES = ["twilio-signature-vectors.json", "elevenlabs-webhook-vectors.json", "elevenlabs-webhook-bodies.json", "twilio-webhook-bodies.json",
         "holidays-co-2026-2028.json", "policy-tables.json", "state-transitions.json", "compiler-cases.json", "outbound-gate-cases.json"]
BASES = {"contrato", "oficial", "ley", "supuesto-lead", "decision-fixture"}
BOG = ZoneInfo("America/Bogota")

problems = []
checked = {}


def bad(msg):
    problems.append(msg)


def tally(fname, n=1):
    checked[fname] = checked.get(fname, 0) + n


# ── openssl ─────────────────────────────────────────────────────────────────
_cache = {}


def ossl_hmac(digest: str, key: bytes, msg: bytes) -> bytes:
    k = (digest, key, msg)
    if k not in _cache:
        if not key:
            raise ValueError("clave vacía")
        r = subprocess.run(["openssl", "mac", "-digest", digest, "-macopt", "hexkey:" + key.hex(), "-binary", "HMAC"], input=msg,
                           capture_output=True, check=True)
        _cache[k] = r.stdout
    return _cache[k]


def ossl_sha256_hex(data: bytes) -> str:
    k = ("sha256", data)
    if k not in _cache:
        r = subprocess.run(["openssl", "dgst", "-sha256", "-binary"], input=data, capture_output=True, check=True)
        _cache[k] = r.stdout.hex()
    return _cache[k]


def b(s):
    return s.encode("utf-8")


# ── forma común ─────────────────────────────────────────────────────────────
HEADER_KEYS = ["fixture", "version", "contract", "audit", "contractSections", "generatedBy", "checkedBy", "readme", "basisLegend", "sources",
               "syntheticData", "decisions", "counts"]


def invisible(c):
    cat = unicodedata.category(c)
    return cat in ("Cf", "Zl", "Zp", "Co", "Cn", "Cs", "Mn", "Me") or (cat == "Cc" and c != "\n") or (cat == "Zs" and c != " ")


def check_shape(fname, text, doc):
    if not text.endswith("\n") or text.endswith("\n\n"):
        bad(f"{fname}: tiene que acabar en un único salto de línea")
    lits = sorted({f"U+{ord(c):04X}" for c in text if invisible(c)})
    if lits:
        bad(f"{fname}: caracteres invisibles literales {lits[:5]}")
    for k in HEADER_KEYS:
        if k not in doc:
            bad(f"{fname}: falta la cabecera {k}")
    if doc.get("fixture") != fname:
        bad(f"{fname}: fixture = {doc.get('fixture')!r}")
    if not doc.get("sources"):
        bad(f"{fname}: sin fuentes")
    for s in doc.get("sources", []):
        for k in ("what", "url", "consulted", "how"):
            if k not in s:
                bad(f"{fname}: una fuente sin {k}")
    counts = doc.get("counts", {})
    sections = [k for k in counts if k != "total"]
    total = 0
    names = set()
    used = set()
    for sec in sections:
        cases = doc.get(sec)
        if not isinstance(cases, list):
            bad(f"{fname}: la sección {sec} no es una lista")
            continue
        if len(cases) != counts[sec]:
            bad(f"{fname}: {sec} tiene {len(cases)} casos y counts dice {counts[sec]}")
        total += len(cases)
        for c in cases:
            for k in ("name", "kind", "contractRef", "basis", "expected"):
                if k not in c:
                    bad(f"{fname}: caso sin {k}: {c.get('name')}")
            n = c.get("name", "")
            if not n.startswith(f"{sec} · {c.get('kind')} · "):
                bad(f"{fname}: nombre sin el prefijo de su sección y tipo: {n}")
            if n in names:
                bad(f"{fname}: nombre repetido: {n}")
            names.add(n)
            if c.get("basis") not in BASES:
                bad(f"{fname}: base desconocida {c.get('basis')} en {n}")
            if c.get("basis") == "decision-fixture" and not c.get("decision"):
                bad(f"{fname}: decision-fixture sin decision: {n}")
            if not str(c.get("contractRef", "")).strip():
                bad(f"{fname}: contractRef vacío: {n}")
            stack = [c]
            while stack:
                x = stack.pop()
                if isinstance(x, dict):
                    for k, v in x.items():
                        if k == "decision" and isinstance(v, str):
                            used.add(v)
                        else:
                            stack.append(v)
                elif isinstance(x, list):
                    stack.extend(x)
    if counts.get("total") != total:
        bad(f"{fname}: counts.total {counts.get('total')} ≠ {total}")
    defined = set(doc.get("decisions", {}))
    if used - defined:
        bad(f"{fname}: decisiones usadas y no definidas en la cabecera: {sorted(used - defined)}")
    if defined - used:
        bad(f"{fname}: decisiones de la cabecera que ningún caso usa: {sorted(defined - used)}")
    for d, t in doc.get("decisions", {}).items():
        if not re.fullmatch(r"DF-[A-Z][0-9]+", d) or not t.strip():
            bad(f"{fname}: decisión mal formada {d}")
    return sections


# ── Twilio ──────────────────────────────────────────────────────────────────
def tw_base(url, params, v=frozenset()):
    groups = {}
    for k, val in params:
        groups.setdefault(k, []).append(val)
    keyf = (lambda s: s.lower()) if "tw_key_case_insensitive" in v else (lambda s: s.encode())
    out = url
    for k in sorted(groups, key=keyf):
        vals = groups[k] if "tw_no_dedupe" in v else list(dict.fromkeys(groups[k]))
        if "tw_numeric_value_sort" in v:
            vals = sorted(vals, key=lambda s: (0, float(s), s) if re.fullmatch(r"[0-9]+(\.[0-9]+)?", s) else (1, 0, s))
        elif "tw_no_sort" not in v:
            vals = sorted(vals, key=lambda s: s.encode())
        out += "".join(k + x for x in vals)
    if "tw_no_sort" in v:
        out = url + "".join(k + x for k, x in params)
    return out


def tw_sign(token, url, params, body, v=frozenset()):
    data = url if body is not None else tw_base(url, params, v)
    dig = "SHA256" if "tw_sha256" in v else "SHA1"
    return base64.b64encode(ossl_hmac(dig, b(token), b(data))).decode()


def _netloc_parts(url):
    p = urllib.parse.urlsplit(url)
    host = p.hostname
    return p, host


def tw_url_variants(url, v):
    p = urllib.parse.urlsplit(url)
    if "tw_fullurl" in v and p.query:
        q = "&".join(sorted(p.query.split("&")))
        url = urllib.parse.urlunsplit((p.scheme, p.netloc, p.path, q, p.fragment))
        p = urllib.parse.urlsplit(url)
    if "tw_no_port_tolerance" in v:
        return [url]
    host = p.netloc.rsplit(":", 1)[0] if p.port else p.netloc
    without = urllib.parse.urlunsplit((p.scheme, host, p.path, p.query, p.fragment))
    with_ = url if p.port else urllib.parse.urlunsplit((p.scheme, f"{host}:{443 if p.scheme == 'https' else 80}", p.path, p.query, p.fragment))
    return [without, with_]


def tw_validate(token, url, header, params, body, v=frozenset()):
    if token == "":
        return "throws"
    if not header:
        return False
    if body is not None:
        q = urllib.parse.parse_qs(urllib.parse.urlsplit(url).query)
        if "tw_ignore_body_hash" not in v:
            if "bodySHA256" not in q or ossl_sha256_hex(b(body)) != q["bodySHA256"][0]:
                return False
        params = []
    return any(tw_sign(token, u, params, None if body is None else "", v) == header for u in tw_url_variants(url, v))


def tw_sign_json_url(token, url, v=frozenset()):
    return base64.b64encode(ossl_hmac("SHA256" if "tw_sha256" in v else "SHA1", b(token), b(url))).decode()


def derive_twilio_sig(doc, v=frozenset()):
    out = []
    for c in doc["compute"]:
        sig = tw_sign(c["token"], c["url"], c["params"], c.get("rawBody"), v)
        got = {"signature": sig}
        if "rawBody" in c:
            got["bodySHA256"] = ossl_sha256_hex(b(c["rawBody"]))
        else:
            got["base"] = tw_base(c["url"], c["params"], v)
        out.append((c, got))
    for c in doc["validate"]:
        r = tw_validate(c["token"], c["url"], c["header"], c.get("params"), c.get("rawBody"), v)
        got = {"throws": "InvalidArgumentException"} if r == "throws" else {"valid": r}
        if "validWithoutPortTolerance" in c["expected"]:
            got["validWithoutPortTolerance"] = tw_validate(c["token"], c["url"], c["header"], c.get("params"), c.get("rawBody"),
                                                           v | {"tw_no_port_tolerance"})
        if "why" in c["expected"]:
            got["why"] = c["expected"]["why"]
        out.append((c, got))
    for c in doc["urlBuild"]:
        base = c["baseUrl"] if "tw_no_rtrim" in v else c["baseUrl"].rstrip("/")
        got = {"url": base + c["requestUri"]}
        if "why" in c["expected"]:
            got["why"] = c["expected"]["why"]
        out.append((c, got))
    return out


# ── ElevenLabs ──────────────────────────────────────────────────────────────
def php_trim(s):
    return s.strip(" \t\n\r\0\x0b")


def el_h6(raw, header, secret, now, v=frozenset()):
    if secret == "":
        return "throws"
    if header is None or php_trim(header) == "":
        return "missing_signature"
    ts, vs = [], []
    for part in header.split(","):
        part = part.strip() if "el_unicode_trim" in v else php_trim(part)
        if part == "":
            continue
        if "=" not in part:
            return "malformed_signature"
        k, val = part.split("=", 1)
        if k == "t":
            ts.append(val)
        elif k == "v0":
            vs.append(val)
    if len(ts) != 1 or not re.fullmatch(r"[0-9]{1,12}", ts[0]) or not vs:
        return "malformed_signature"
    if any(not re.fullmatch(r"[0-9a-fA-F]{64}", x) for x in vs):
        return "malformed_signature"
    t = int(ts[0])
    tol = 300 if "el_tolerance_300" in v else 1800
    if (t <= now - tol) if "el_le_tolerance" in v else (t < now - tol):
        return "expired"
    if "el_no_future" not in v and t > now + 300:
        return "from_future"
    body = raw
    if "el_reserialize" in v:
        try:
            body = json.dumps(json.loads(raw), separators=(",", ":"), ensure_ascii=False)
        except ValueError:
            pass
    msg = body if "el_no_t_prefix" in v else f"{ts[0]}.{body}"
    exp = ossl_hmac("SHA256", b(secret), b(msg)).hex()
    cands = vs[:1] if "el_first_v0_only" in v else vs
    if "el_case_sensitive" in v:
        return "ok" if any(x == exp for x in cands) else "mismatch"
    return "ok" if any(x.lower() == exp for x in cands) else "mismatch"


def el_doc(raw, header, secret, now):
    if not header or not secret:
        return "reject"
    parts = header.split(",")
    t = next((p[2:] for p in parts if p.startswith("t=")), None)
    s = next((p for p in parts if p.startswith("v0=")), None)
    if t is None or s is None or not re.fullmatch(r"-?[0-9]+", t):
        return "reject"
    if int(t) < now - 1800:
        return "reject"
    return "accept" if s == "v0=" + ossl_hmac("SHA256", b(secret), b(f"{t}.{raw}")).hex() else "reject"


def envelope_type(raw):
    try:
        d = json.loads(raw)
    except ValueError:
        return None
    if not isinstance(d, dict):
        return None
    t = d.get("type")
    return t if t in ("post_call_transcription", "post_call_audio", "call_initiation_failure") else None


def derive_eleven_sig(doc, v=frozenset()):
    out = []
    for c in doc["verify"]:
        r = el_h6(c["rawBody"], c["header"], c["secret"], c["now"], v)
        got = {"signatureResult": r, "http": 200 if r == "ok" else 401, "processed": r == "ok"}
        if r == "ok":
            got["envelopeType"] = envelope_type(c["rawBody"])
        got["sdkConstructEvent"] = el_doc(c["rawBody"], c["header"], c["secret"], c["now"])
        got["sdkVerified"] = False
        if "why" in c["expected"]:
            got["why"] = c["expected"]["why"]
        out.append((c, got))
    return out


# ── festivos ────────────────────────────────────────────────────────────────
def easter_gauss(y, v=frozenset()):
    a, bb, c = y % 19, y % 4, y % 7
    k = y // 100
    p = (13 + 8 * k) // 25
    q = k // 4
    M = (15 - p + k - q) % 30
    N = (4 + k - q) % 7
    d = (19 * a + M) % 30
    e = (2 * bb + 4 * c + 6 * d + N) % 7
    if d == 29 and e == 6:
        r = dt.date(y, 4, 19)
    elif d == 28 and e == 6 and (11 * M + 11) % 30 < 19:
        r = dt.date(y, 4, 18)
    else:
        day = 22 + d + e
        r = dt.date(y, 3, day) if day <= 31 else dt.date(y, 4, day - 31)
    if "ho_easter_week_late" in v:
        r += dt.timedelta(days=7)
    return r


def monday_on_or_after(d, v=frozenset()):
    if "ho_no_emiliani" in v:
        return d
    if "ho_monday_moves_too" in v:
        return d + dt.timedelta(days=7 - d.weekday())
    return d + dt.timedelta(days=(-d.weekday()) % 7)


def holidays_of(y, v=frozenset()):
    e = easter_gauss(y, v)
    fixed = [(1, 1), (5, 1), (7, 20), (8, 7), (12, 8), (12, 25)]
    moving = [(1, 6), (3, 19), (6, 29), (8, 15), (10, 12), (11, 1), (11, 11)]
    out = {dt.date(y, m, d) for m, d in fixed}
    out |= {monday_on_or_after(dt.date(y, m, d), v) for m, d in moving}
    hw = 1 if "ho_holy_week_off_by_one" in v else 0
    out |= {e - dt.timedelta(days=3 + hw), e - dt.timedelta(days=2 + hw)}
    asc = 39
    sc = 68
    for off in (asc, 60, sc):
        base = e + dt.timedelta(days=off)
        out.add(base if "ho_easter_no_move" in v else monday_on_or_after(base, v))
    return out


def derive_holidays(doc, v=frozenset()):
    out = []
    allh = set()
    for y in (2026, 2027, 2028):
        allh |= holidays_of(y, v)
    yrs = {y: sorted(d.isoformat() for d in holidays_of(int(y), v)) for y in doc["years"]}
    for y, rows in doc["years"].items():
        listed = [r["date"] for r in rows]
        if "ho_" not in "".join(v) and listed != yrs[y]:
            bad(f"holidays: la lista de {y} no es la de la regla: {sorted(set(listed) ^ set(yrs[y]))}")
        for r in rows:
            dd = dt.date.fromisoformat(r["date"])
            if r["weekday"] != ["lunes", "martes", "miércoles", "jueves", "viernes", "sábado", "domingo"][dd.weekday()]:
                bad(f"holidays: día de la semana mal en {r['date']}")
            if r["rule"] in ("emiliani", "pascua-emiliani") and dd.weekday() != 0 and not v:
                bad(f"holidays: {r['date']} trasladable que no cae en lunes")
    for c in doc["isHoliday"]:
        if c["kind"] == "date":
            d = dt.date.fromisoformat(c["date"])
            got = {"holiday": d in allh}
        else:
            bt = dt.datetime.fromisoformat(c["at"].replace("Z", "+00:00")).astimezone(BOG)
            d = bt.date()
            got = {"bogotaDate": d.isoformat(), "bogotaTime": bt.strftime("%H:%M:%S"), "holiday": d in allh}
        if got["holiday"]:
            got["name"] = c["expected"].get("name")
        if "why" in c["expected"]:
            got["why"] = c["expected"]["why"]
        out.append((c, got))
    return out, allh


# ── políticas ───────────────────────────────────────────────────────────────
WD = ["lunes", "martes", "miércoles", "jueves", "viernes", "sábado", "domingo"]


def bog(s):
    return dt.datetime.fromisoformat(s.replace("Z", "+00:00")).astimezone(BOG)


def legal_outside(t, v=frozenset()):
    if "sc_utc" in v:
        t = t.astimezone(dt.timezone.utc)
    hm = (t.hour, t.minute, t.second)
    wd = t.weekday()
    if wd == 6 and "sc_sunday_ok" not in v:
        return True
    if wd == 5:
        close = (19, 0, 0) if "sc_saturday_19" in v else (15, 0, 0)
        return not ((8, 0, 0) <= hm and (hm <= close if "sc_closed_interval" in v else hm < close))
    if wd == 6:
        return False
    return not ((7, 0, 0) <= hm and (hm <= (19, 0, 0) if "sc_closed_interval" in v else hm < (19, 0, 0)))


def parse_hm(s):
    if not isinstance(s, str) or not re.fullmatch(r"([01][0-9]|2[0-3]):[0-5][0-9]", s):
        return None
    h, m = s.split(":")
    return (int(h), int(m), 0)


def own_outside(t, own, v=frozenset()):
    if legal_outside(t, v):
        return True
    if own is None:
        return False
    win = own.get("saturday") if t.weekday() == 5 else own.get("weekdays")
    if win is None:
        return True
    a, z = parse_hm(win[0]), parse_hm(win[1])
    if a is None or z is None or a >= z:
        return "sc_own_invalid_open" not in v
    hm = (t.hour, t.minute, t.second)
    return not (a <= hm < z)


def derive_policy(doc, allh, v=frozenset()):
    out = []
    for c in doc["scheduleLegal"]:
        t = bog(c["at"])
        o = legal_outside(t, v)
        hol = (t.date() in allh) and "sc_ignore_holidays" not in v
        out.append((c, {"bogotaDate": t.date().isoformat(), "bogotaTime": t.strftime("%H:%M:%S"), "weekday": WD[t.weekday()],
                        "outsideHours": o, "holiday": hol, "allowed": (not o) and (not hol)}))
    for c in doc["scheduleOwn"]:
        t = bog(c["at"])
        out.append((c, {"bogotaTime": t.strftime("%H:%M:%S"), "weekday": WD[t.weekday()], "outsideHours": own_outside(t, c["ownWindow"], v)}))
    counted = {"requested", "dialing", "ambiguous", "completed", "busy", "no_answer", "failed", "hung_up"}
    if "fr_count_canceled" in v:
        counted |= {"canceled", "failed_to_start"}
    for c in doc["frequency"]:
        now = bog(c["now"])
        utc_now = dt.datetime.fromisoformat(c["now"].replace("Z", "+00:00"))
        start_week = (now - dt.timedelta(days=(now.weekday() + 1) % 7 if "fr_week_sunday" in v else now.weekday())).replace(
            hour=0, minute=0, second=0, microsecond=0)
        td = wk = rl = 0
        for h in c["history"]:
            if h["status"] not in counted or (h["direction"] != "outbound" and "fr_count_inbound" not in v):
                continue
            ht = bog(h["at"])
            hu = dt.datetime.fromisoformat(h["at"].replace("Z", "+00:00"))
            if "fr_utc_day" in v:
                td += hu.date() == utc_now.date()
            else:
                td += ht.date() == now.date()
            if "fr_rolling_as_week" in v:
                wk += utc_now - dt.timedelta(days=7) < hu <= utc_now
            else:
                wk += start_week <= ht <= now
            rl += utc_now - dt.timedelta(days=7) < hu <= utc_now
        lim = c["limits"]
        out.append((c, {"countToday": td, "countWeek": wk, "countRolling7Days": rl,
                        "frequencyExceeded": td >= lim["perDay"] or wk >= lim["perWeek"],
                        "frequencyExceededRolling7Days": td >= lim["perDay"] or rl >= lim["perWeek"]}))
    for c in doc["consent"]:
        evs = []
        for e in c["voiceConsents"]:
            if e["channel"] != "voice_call" or e["purpose"] != "commercial":
                continue
            if e["kind"] == "granted":
                if e["source"] == "admin_with_evidence" and "co_no_four_eyes" not in v and (e["approvedBy"] in (None, e["actorId"])):
                    continue
                if e["source"] == "recorded_inbound_request" and not c["recordingEnabled"] and "co_recorded_ok" not in v:
                    continue
                if e["source"] not in ("written_form", "admin_with_evidence", "recorded_inbound_request"):
                    continue
            evs.append(e)
        if "co_tie_low_id" in v:
            evs.sort(key=lambda e: (e["at"], -e["id"]))
        else:
            evs.sort(key=lambda e: (e["at"], e["id"]))
        if "co_first_event" in v:
            evs = evs[:1]
        has = bool(evs) and evs[-1]["kind"] == "granted"
        if "co_lead_counts" in v and c["leadConsentStatus"] == "granted":
            has = True
        out.append((c, {"hasVoiceConsent": has}))
    for c in doc["exclusions"]:
        now = dt.datetime.fromisoformat(c["now"].replace("Z", "+00:00"))
        lead = c["lead"] or {}
        rne = c["rneCheck"]
        excl = bool(c["exclusions"]) and not (c["scope"] == "canary" and "ex_canary_ignores" in v)
        excl = excl or bool(lead.get("doNotContact")) or (lead.get("consentStatus") == "denied" and "ex_ignore_denied" not in v)
        excl = excl or bool(rne and rne["result"] == "listed")
        missing = False
        if c["scope"] == "contacts" and not (rne and rne["result"] == "listed"):
            if rne is None:
                missing = True
            else:
                age = now - dt.datetime.fromisoformat(rne["checkedAt"].replace("Z", "+00:00"))
                lim = dt.timedelta(days=c["rneValidityDays"])
                missing = age >= lim if "ex_rne_boundary_exclusive" in v else age > lim
        out.append((c, {"excluded": excl, "rneCheckMissing": missing}))
    order = ["inbound_disabled", "calls_disabled_in_code", "voice_braked", "no_publication", "publication_unreadable",
             "not_an_active_voice_canary", "concurrency_exceeded", "daily_cap_reached"]
    if "in_code_first" in v:
        order = ["calls_disabled_in_code", "inbound_disabled"] + order[2:]
    for c in doc["inboundPolicy"]:
        f = c["facts"]
        res = {}
        for code_open in (True, False):
            r = set()
            if not f["inboundEnabled"] or f["scope"] != "canary":
                r.add("inbound_disabled")
            if not code_open:
                r.add("calls_disabled_in_code")
            if f["braked"]:
                r.add("voice_braked")
            if f["publication"] == "none":
                r.add("no_publication")
            if f["publication"] == "unreadable":
                r.add("publication_unreadable")
            if f["caller"] != "active_voice":
                r.add("not_an_active_voice_canary")
            if (f["activeOrDialing"] > f["maxConcurrent"]) if "in_concurrency_gt" in v else (f["activeOrDialing"] >= f["maxConcurrent"]):
                r.add("concurrency_exceeded")
            if f["inboundToday"] >= f["inboundDailyCap"]:
                r.add("daily_cap_reached")
            lst = [x for x in order if x in r]
            if "in_first_only" in v:
                lst = lst[:1]
            res["codeOpen" if code_open else "codeClosed"] = {"authorized": not lst, "reasons": lst, "callerAuthorized": "no" if lst else "si"}
        out.append((c, res))
    prices = doc["pricesMicroUsdPerMinute"]
    for c in doc["costReservation"]:
        if c["kind"] == "reserve":
            mins = c["maxDurationSeconds"] // 60 if "co_floor_minutes" in v else math.ceil(c["maxDurationSeconds"] / 60)
            leg = {"mobile": prices["twilioOutboundMobileCO"], "landline": prices["twilioOutboundLandlineCO"],
                   "inbound": prices["twilioInboundLocalCO"]}[c["destinationKind"]]
            per = prices["elevenlabs"] + leg + (0 if "co_no_media_streams" in v else prices["twilioMediaStreams"])
            total = mins * per
            if c["transferDestinationKind"] and "co_no_transfer_leg" not in v:
                total += mins * {"mobile": prices["twilioOutboundMobileCO"], "landline": prices["twilioOutboundLandlineCO"]}[c["transferDestinationKind"]]
            out.append((c, {"minutes": mins, "reserveMicroUsd": total}))
        else:
            raw = c["budgetRaw"]
            m = re.fullmatch(r"([0-9]+)(?:\.([0-9]+))?", raw or "")
            budget = int(m.group(1)) * 1_000_000 + int(((m.group(2) or "") + "000000")[:6]) if m else 0
            need = c["spentMicroUsd"] + c["reservedMicroUsd"] + c["newReserveMicroUsd"]
            fits = budget > 0 and ((need < budget) if "co_budget_lt" in v else (need <= budget))
            out.append((c, {"budgetMicroUsd": budget, "fits": fits, "reason": None if fits else "budget_exhausted"}))
    opens = {"guard_violation", "seal_mismatch", "agent_drift", "daily_hard_cap_reached", "usage_alert", "unknown_provider_call"}
    for c in doc["brake"]:
        if c["kind"] == "opens":
            o = c["trigger"] in opens
            out.append((c, {"opensBrake": o, "incident": o}))
        else:
            a, note, st = c["actor"], c["note"], c["brakeState"]
            if not a["person"] or "ultron.voice_publish" not in a["permissions"]:
                got = {"http": 403, "released": False}
            elif not (1 <= len(note) <= 300) or re.search(r"(?:\d[\s.\-()]*){8,}", note) or "@" in note:
                got = {"http": 422, "released": False}
            elif st != "open":
                got = {"http": 409, "released": False}
            else:
                got = {"http": 200, "released": True}
            out.append((c, got))
    key = base64.b64decode(doc["keys"]["appKeyBase64"][7:])
    for c in doc["phoneHash"]:
        e = c["e164"]
        if not re.fullmatch(r"\+[1-9][0-9]{7,14}", e):
            out.append((c, {"throws": "InvalidArgumentException"}))
            continue
        sk_v = ossl_hmac("SHA256", key, b(doc["keys"]["voicePhoneLabel"]))
        sk_c = ossl_hmac("SHA256", key, b(doc["keys"]["canaryPhoneLabel"]))
        out.append((c, {"remoteHash": ossl_hmac("SHA256", sk_v, b(e)).hex(), "remoteLast4": e[-4:],
                        "canaryFingerprint": ossl_hmac("SHA256", sk_c, b(e)).hex()}))
    return out


# ── estados ─────────────────────────────────────────────────────────────────
def derive_states(doc, v=frozenset()):
    out = []
    cs = doc["closedSets"]
    allowed = {(c["from"], c["to"], c["direction"]) for c in doc["allowed"]}
    finals = set(cs["finalStatuses"])
    if "st_backwards" in v:
        allowed.add(("dialing", "requested", "outbound"))
    if "st_dialing_cancel" in v:
        allowed.add(("dialing", "canceled", "outbound"))

    def ok(a, z, d):
        if a == z:
            return True
        if "st_final_mutable" in v and a in finals:
            return True
        return (a, z, d) in allowed

    for c in doc["allowed"]:
        if c["from"] in finals:
            bad(f"state-transitions: transición permitida desde un final: {c['name']}")
        out.append((c, {"allowed": True}))
    for c in doc["matrix"]:
        a, z, d = c["from"], c["to"], c["direction"]
        got = {"allowed": ok(a, z, d)}
        if a == z:
            got["noChange"] = True
        elif not got["allowed"]:
            got["why"] = c["expected"].get("why")
        out.append((c, got))
    for c in doc["insert"]:
        okk = c["status"] == cs["initialStatus"][c["direction"]] or ("st_insert_any" in v and c["status"] != "active" and c["direction"] == "outbound")
        out.append((c, {"allowed": okk}))
    for c in doc["sequences"]:
        cur, steps = None, []
        for i, s in enumerate(c["steps"]):
            acc = (s == cs["initialStatus"][c["direction"]]) if i == 0 else (ok(cur, s, c["direction"]) and cur != s)
            steps.append({"to": s, "accepted": acc})
            if acc:
                cur = s
        out.append((c, {"steps": steps, "finalStatus": cur}))
    for c in doc["columns"]:
        if c["kind"] == "immutable":
            out.append((c, {"allowed": False}))
        else:
            out.append((c, {"allowed": c["from"] is None}))
    for c in doc["eventDedupe"]:
        f, s = c["first"], c["second"]
        dup = f["provider"] == s["provider"] and f["kind"] == s["kind"] and f["providerEventKey"] == s["providerEventKey"] and f["providerEventKey"] is not None
        out.append((c, {"secondInserted": not dup}))
    return out


# ── compilador ──────────────────────────────────────────────────────────────
def php_key(k):
    if re.fullmatch(r"0|-?[1-9][0-9]*", k) and -2 ** 63 <= int(k) < 2 ** 63:
        return int(k)
    return k


def php_canon_json(x):
    """Reimplementación independiente del JSON canónico de PHP (para comparar con el fixture; --php usa el PHP real)."""
    def enc_str(s):
        r = []
        for ch in s:
            o = ord(ch)
            if ch == '"':
                r.append('\\"')
            elif ch == "\\":
                r.append("\\\\")
            elif ch in "\b\f\n\r\t":
                r.append({"\b": "\\b", "\f": "\\f", "\n": "\\n", "\r": "\\r", "\t": "\\t"}[ch])
            elif o < 0x20:
                r.append("\\u%04x" % o)
            elif o in (0x2028, 0x2029):
                r.append("\\u%04x" % o)
            else:
                r.append(ch)
        return '"' + "".join(r) + '"'

    def enc(v):
        if v is None:
            return "null"
        if v is True:
            return "true"
        if v is False:
            return "false"
        if isinstance(v, int):
            return str(v)
        if isinstance(v, float):
            raise ValueError("decimal")
        if isinstance(v, str):
            return enc_str(v)
        if isinstance(v, list):
            return "[" + ",".join(enc(i) for i in v) + "]"
        keys = [php_key(k) for k in v]
        if keys == list(range(len(keys))):
            return "[" + ",".join(enc(i) for i in v.values()) + "]"
        items = sorted(((str(pk), val) for pk, val in zip(keys, v.values())), key=lambda kv: kv[0].encode())
        if [php_key(k) for k, _ in items] == list(range(len(items))):
            return "[" + ",".join(enc(val) for _, val in items) + "]"
        return "{" + ",".join(enc_str(k) + ":" + enc(val) for k, val in items) + "}"
    return enc(x)


MONEY = [r"\$\s?\d", r"\b(cop|pesos)\b", r"\d{1,3}[.,]\d{3}(?!\d)", r"\d{4,}", r"\b\d{1,3}\s*(mil|k)\b"]
DISCOUNT_CATALOG = [r"\bgratis\b", r"2x1", r"\bprecio especial\b"]
DISCOUNT_ORIGIN = [r"\bdescuento\b", r"\brebaja\b", r"\b2\s*x\s*1\b", r"\bpromoci[oó]n\b", r"\bsin costo\b"]
URL = [r"https?://", r"\bwww\.", r"\b[a-z0-9-]+\.(com|co|net|org|io|app|test)\b"]
PHONE = [r"(?:\+?\d[\s.\-()]*){8,}"]
EMAIL = [r"[^\s@]+@[^\s@]+\.[a-z]{2,}"]
PICTO = re.compile("[\U0001F000-\U0001FAFF☀-➿]")


def has_invisible(s):
    for i, ch in enumerate(s):
        cat = unicodedata.category(ch)
        if ch == "\u200d":
            prev = s[i - 1] if i else ""
            nxt = s[i + 1] if i + 1 < len(s) else ""
            if (PICTO.match(prev) or prev == "\ufe0f" or "\U0001F3FB" <= prev <= "\U0001F3FF") and PICTO.match(nxt):
                continue
            return True
        if cat in ("Cf", "Zl", "Zp") or (cat == "Cc") or 0xE0000 <= ord(ch) <= 0xE007F:
            return True
    return False


def text_reason(s, v):
    low = s.lower()
    if "cm_strip_invisibles" in v:
        low = "".join(ch for ch in low if unicodedata.category(ch) != "Cf")
    checks = [("slot_money", MONEY), ("slot_discount", DISCOUNT_CATALOG + ([] if "cm_catalog_discount_only" in v else DISCOUNT_ORIGIN)),
              ("slot_url", URL), ("slot_phone", PHONE), ("slot_email", EMAIL)]
    found = [code for code, pats in checks if any(re.search(p, low) for p in pats)]
    if has_invisible(s) and "cm_strip_invisibles" not in v:
        found.append("slot_invisible")
    return found


def cap_of(raw, v=frozenset()):
    if raw is None or raw == "":
        val = 300
    elif re.fullmatch(r"-?[0-9]+", raw):
        val = int(raw)
    else:
        val = 0
    return val if "cm_no_clamp" in v else max(60, min(600, val))


def compile_reason(inp, notice, v=frozenset()):
    p, ver, c = inp["profile"], inp["version"], inp["profile"]["content"]
    approved_versions = {"APPROVED"} if "cm_only_approved" in v else {"APPROVED", "CANARY", "PUBLISHED"}
    if p["status"] != "approved" or (p["approvedBy"] == p["createdBy"] and "cm_self_approval" not in v):
        return ("profile_not_approved", None)
    if ver["status"] not in approved_versions:
        return ("version_not_approved", None)
    if p["storedHash"] != ossl_sha256_hex(b(php_canon_json(c))):
        return ("profile_hash_mismatch", None)
    if ver["contentHash"] != ver["recomputedContentHash"]:
        return ("version_hash_mismatch", None)
    fields = []
    for k in ("greetingInbound", "greetingOutbound"):
        if isinstance(c.get(k), str):
            fields.append((k, c[k]))
    for k in sorted(c.get("slots", {})):
        fields.append((f"slots.{k}", c["slots"][k]))
    found = {f: text_reason(t, v) for f, t in fields}
    for code in ["slot_money", "slot_discount", "slot_url", "slot_phone", "slot_email", "slot_invisible"]:
        for f, _ in fields:
            if code in found[f]:
                return (code, f)
    if any(t not in ("consultar_planes", "consultar_horarios", "solicitar_humano", "no_volver_a_llamar") for t in c["tools"]):
        return ("tool_not_in_catalog", None)
    if any(t not in ("end_call", "transfer_to_number") for t in c["systemTools"]):
        return ("system_tool_not_allowed", None)
    if "end_call" not in c["systemTools"] and "cm_end_call_optional" not in v:
        return ("end_call_missing", None)
    aliases = {d["alias"] for d in c["transferDestinations"]}
    if any(a not in aliases for a in ver.get("handoffAliases", [])):
        return ("transfer_destination_not_in_profile", None)
    if c["maxDurationSeconds"] > cap_of(inp["env"].get("VOICE_PER_CALL_MAX_SECONDS"), v):
        return ("max_duration_exceeds_cap", None)
    if c["privacy"]["recording"] and not inp["owner"]["recordingAllowed"]:
        return ("recording_enabled", None)
    rd = c["privacy"]["retentionDays"]
    if rd < 0 or rd > inp["owner"]["maxRetentionDays"]:
        return ("retention_exceeds_limit", None)
    if c.get("notice") != notice:
        return ("notice_missing", None)
    allowed = {"voiceId", "language", "greetingInbound", "greetingOutbound", "notice", "maxDurationSeconds", "transferDestinations", "tools",
               "systemTools", "privacy", "voicemailPolicy", "slots"}
    if set(c) - allowed:
        return ("profile_field_not_allowed", None)
    if c["language"] != "es":
        return ("language_not_allowed", None)
    if c["maxDurationSeconds"] < 60:
        return ("max_duration_below_minimum", None)
    if len(c["transferDestinations"]) > 3:
        return ("too_many_transfer_destinations", None)
    if c["privacy"]["audioStorage"]:
        return ("audio_storage_enabled", None)
    return (None, None)


def model_spec(inp, v=frozenset()):
    c = inp["profile"]["content"]
    tools = list(c["tools"]) if "cm_tools_order_matters" in v else sorted(c["tools"])
    spec = {"compilerVersion": inp["compilerVersion"], "studioContentHash": inp["version"]["contentHash"], "voiceId": c["voiceId"],
            "language": c["language"], "greetingInbound": c["greetingInbound"], "greetingOutbound": c["greetingOutbound"],
            "notice": c["notice"], "maxDurationSeconds": c["maxDurationSeconds"], "tools": tools, "systemTools": sorted(c["systemTools"]),
            "transferDestinations": sorted(c["transferDestinations"], key=lambda d: d["alias"]), "privacy": c["privacy"],
            "voicemailPolicy": c["voicemailPolicy"], "slots": c["slots"]}
    if "cm_hash_includes_uuid" in v:
        spec["profileUuid"] = inp["profile"]["uuid"]
    return ossl_sha256_hex(b(php_canon_json(spec)))


def derive_compiler(doc, notice, v=frozenset()):
    out = []
    for c in doc["compile"]:
        inp = c["input"]
        reason, field = compile_reason(inp, notice, v)
        if reason is None:
            cc = inp["profile"]["content"]
            inv = dict(c["expected"].get("invariants", {}))
            inv.update({"maxDurationSeconds": cc["maxDurationSeconds"], "tools": sorted(cc["tools"]), "systemTools": sorted(cc["systemTools"]),
                        "retentionDays": cc["privacy"]["retentionDays"], "voiceId": cc["voiceId"],
                        "transferDestinations": sorted(cc["transferDestinations"], key=lambda d: d["alias"]),
                        "firstMessageInboundStartsWith": notice, "firstMessageOutboundStartsWith": notice,
                        "firstMessageInboundContains": cc["greetingInbound"], "firstMessageOutboundContains": cc["greetingOutbound"],
                        "phonesOnlyInTransferDestinations": [d["e164"] for d in cc["transferDestinations"]],
                        "compilerVersion": doc["compilerVersion"], "dynamicVariableDefaults": {"caller_authorized": "no"},
                        "conversationConfigOverrideAllowed": False, "recordingEnabled": False, "twilioRecordingEnabled": False,
                        "audioStorage": False, "language": "es"})
            got = {"compiles": True, "invariants": inv}
        elif "reasonOneOf" in c["expected"]:
            ok = reason in c["expected"]["reasonOneOf"]
            got = {"compiles": False, "reasonOneOf": c["expected"]["reasonOneOf"] if ok else [reason], "http": 422}
            if field:
                got["field"] = field
        else:
            got = {"compiles": False, "reason": reason, "http": 422}
            if field:
                got["field"] = field
        out.append((c, got))
    for c in doc["capClamp"]:
        out.append((c, {"capSeconds": cap_of(c["raw"], v)}))
    for c in doc["hashRelations"]:
        out.append((c, {"sameSpecHash": model_spec(c["inputA"], v) == model_spec(c["inputB"], v)}))
    for c in doc["canonicalHash"]:
        try:
            canon = php_canon_json(c["value"])
            got = {"canonicalJson": canon, "sha256": ossl_sha256_hex(b(canon))}
        except ValueError:
            got = {"throws": "InvalidArgumentException"}
        out.append((c, got))
    return out


# ── puerta de salientes ─────────────────────────────────────────────────────
def gate_reasons(f, code_open, order, v=frozenset()):
    r = set()
    if not code_open:
        r.add("calls_disabled_in_code")
    if not f["flags"]["outboundEnabled"]:
        r.add("outbound_disabled")
    if not f["flags"]["twilioVoiceEnabled"]:
        r.add("twilio_voice_disabled")
    if not f["number"]["voiceFromConfigured"]:
        r.add("no_voice_number")
    elif f["number"]["voiceNumberIsWhatsapp"]:
        r.add("voice_number_is_whatsapp")
    r |= {{"ELEVENLABS_NOT_CONFIGURED": "elevenlabs_not_configured", "ELEVENLABS_DISABLED": "elevenlabs_disabled",
           "ELEVENLABS_UNVERIFIED": "elevenlabs_unverified"}.get(f["provider"]["state"], "")} - {""}
    pub = f["publication"]
    if pub["head"] == "none":
        r.add("no_publication")
    elif pub["head"] == "unreadable":
        r.add("publication_unreadable")
    elif pub["agentDrift"]:
        r.add("agent_drift")
    if pub["braked"]:
        r.add("voice_braked")
    t = f["target"]
    allowed_src = {"canary_registry"} if "gt_contacts_source_forbidden" in v else {"canary_registry", "voice_contacts"}
    if t["source"] not in allowed_src:
        r.add("source_not_allowed")
    if t["scope"] == "canary":
        if t.get("canary") != "active_voice":
            r.add("not_an_active_voice_canary")
        if t["excluded"] and "gt_canary_ignores_exclusion" not in v:
            r.add("excluded")
    else:
        if t.get("contactStatus") != "authorized":
            r.add("contact_not_authorized")
        if not t.get("voiceConsent"):
            r.add("no_voice_consent")
        if t["excluded"]:
            r.add("excluded")
        if t.get("rneCheckMissing"):
            r.add("rne_check_missing")
    if f["calendar"]["outsideHours"]:
        r.add("outside_hours")
    if f["calendar"]["holiday"]:
        r.add("holiday")
    q = f["quotas"]
    if q["frequencyExceeded"]:
        r.add("frequency_exceeded")
    if (q["activeOrDialing"] > q["maxConcurrent"]) if "gt_concurrency_gt" in v else (q["activeOrDialing"] >= q["maxConcurrent"]):
        r.add("concurrency_exceeded")
    if q["dailyCap"] <= 0:
        r.add("daily_cap_zero")
        if "gt_cap_zero_also_reached" in v:
            r.add("daily_cap_reached")
    elif q["placedToday"] >= q["dailyCap"]:
        r.add("daily_cap_reached")
    if not q["budgetFits"]:
        r.add("budget_exhausted")
    if f["request"]["clientRequestIdSeenForSamePerson"]:
        r.add("duplicate_request")
    lst = [x for x in order if x in r]
    if "gt_first_only" in v:
        lst = lst[:1]
    return lst


def wa_digits(raw, v):
    d = "".join(ch for ch in raw if ch in "0123456789")
    if "wa_no_national_prefix" not in v and len(d) == 10 and (d.startswith("3") or d.startswith("60")):
        d = "57" + d
    return d


def derive_gate(doc, v=frozenset()):
    out = []
    order = doc["closedSets"]["reasonsInOrder"]
    for c in doc["decide"]:
        res = {}
        for code_open, key in ((True, "codeOpen"), (False, "codeClosed")):
            lst = gate_reasons(c["facts"], code_open, order, v)
            http = 202 if not lst else 200 if lst == ["duplicate_request"] else 409
            res[key] = {"allowed": not lst, "reasons": lst, "http": http}
        out.append((c, res))
    for c in doc["whatsappGuard"]:
        prot = c["whatsappProtectedNumbers"] if c["whatsappProtectedNumbers"].strip() else c["whatsappDisplayPhone"]
        if "wa_literal" in v:
            nums = {x.strip() for x in prot.split(",") if x.strip()} | ({c["whatsappDisplayPhone"].strip()} - {""})
            got = c["voiceFrom"] in nums
        else:
            nums = {wa_digits(x, v) for x in prot.split(",")} | {wa_digits(c["whatsappDisplayPhone"], v)}
            nums.discard("")
            got = wa_digits(c["voiceFrom"], v) in nums
        out.append((c, {"voiceNumberIsWhatsapp": got}))
    return out


# ── cruces entre ficheros (sin variantes) ───────────────────────────────────
def cross_checks(docs):
    st = docs["state-transitions.json"]
    statuses = set(st["closedSets"]["statuses"])
    events = set(st["closedSets"]["events"]) | {"usage_alert"}
    allowed = {(c["from"], c["to"], c["direction"]) for c in st["allowed"]}
    eb = docs["elevenlabs-webhook-bodies.json"]
    n = 0
    for c in eb["postCall"]:
        e = c["expected"]
        tr = e.get("transition")
        call = c["given"].get("call")
        if tr:
            n += 1
            if (tr["from"], tr["to"], call["direction"]) not in allowed:
                bad(f"cruce: {c['name']} cita {tr} que state-transitions no permite")
            if tr["from"] != call["status"]:
                bad(f"cruce: {c['name']} parte de {call['status']} y la transición dice {tr['from']}")
        if call and call["status"] in st["closedSets"]["finalStatuses"] and tr:
            bad(f"cruce: {c['name']} cambia un estado final")
        for ev in e.get("events", []):
            if ev["kind"] not in events:
                bad(f"cruce: {c['name']} evento fuera del conjunto: {ev['kind']}")
        for s in e.get("mustNotPersist", []):
            if s not in c["rawBody"]:
                bad(f"cruce: {c['name']} el centinela {s!r} no está en el cuerpo (no prueba nada)")
        if e.get("envelopeType", "x") != "x" and e["envelopeType"] != envelope_type(c["rawBody"]):
            bad(f"cruce: {c['name']} envelopeType {e['envelopeType']} ≠ {envelope_type(c['rawBody'])}")
        if e.get("events") and envelope_type(c["rawBody"]):
            d = json.loads(c["rawBody"])["data"]
            for ev in e["events"]:
                if ev["providerEventKey"] != d["conversation_id"]:
                    bad(f"cruce: {c['name']} la clave del evento no es data.conversation_id")
    for c in eb["guard"]:
        n += 1
        for vi in c["expected"]["violations"]:
            if c["transcript"][vi["turn"]]["role"] != "agent":
                bad(f"cruce: {c['name']} marca un turno que no es del agente")
    ev_docs = docs["elevenlabs-webhook-vectors.json"]
    bodies_by_key = {c["bodyKey"]: c["rawBody"] for c in eb["postCall"]}
    for c in ev_docs["verify"]:
        n += 1
        if c["bodyKey"] in bodies_by_key and c["expected"]["signatureResult"] == "ok" and c["rawBody"] != bodies_by_key[c["bodyKey"]]:
            bad(f"cruce: {c['name']} firma un cuerpo distinto del de bodies con su misma clave")
    og = docs["outbound-gate-cases.json"]
    for c in og["job"]:
        n += 1
        s = c["expected"]["statusAfter"]
        if s not in statuses or ("requested", s, "outbound") not in allowed:
            bad(f"cruce: {c['name']} requested → {s} no está permitido")
        for ev in c["expected"].get("events", []):
            if ev not in events:
                bad(f"cruce: {c['name']} evento fuera del conjunto: {ev}")
        if (c["expected"]["request"] is None) != (c["elevenlabsResponse"] is None):
            bad(f"cruce: {c['name']} sin petición pero con respuesta (o al revés)")
    tb = docs["twilio-webhook-bodies.json"]
    for c in tb["callStatus"]:
        n += 1
        if c["expected"]["ourStatus"] not in statuses:
            bad(f"cruce: {c['name']} estado desconocido")
    for c in tb["usageTrigger"]:
        n += 1
        sig = c["headers"]["X-Twilio-Signature"]
        good = base64.b64encode(ossl_hmac("SHA1", b(tb["keys"]["subaccountSigningToken"]), b(tw_base(c["url"], c["params"])))).decode()
        if (sig == good) != (c["expected"]["http"] == 200):
            bad(f"cruce: {c['name']} la firma y el HTTP esperado no casan")
        if urllib.parse.parse_qsl(c["rawBody"], keep_blank_values=True) != [tuple(p) for p in c["params"]]:
            bad(f"cruce: {c['name']} rawBody no es el formulario de params")
        if c["url"] != tb["keys"]["voiceWebhookBaseUrl"] + c["requestUri"]:
            bad(f"cruce: {c['name']} url ≠ base + requestUri")
    page = tb["callsPage"][0]
    for call in page["page"]["calls"]:
        n += 1
        o = page["expected"]["perCall"][call["sid"]]
        if call["price"] is not None and o.get("costMicroUsd") is not None:
            whole, _, frac = call["price"].lstrip("-").partition(".")
            if int(whole) * 1_000_000 + int((frac + "000000")[:6]) != o["costMicroUsd"]:
                bad(f"cruce: callsPage {call['sid']} coste mal convertido")
        if o.get("statusAfter") and o.get("statusBefore") and o["statusAfter"] != o["statusBefore"]:
            if (o["statusBefore"], o["statusAfter"], "outbound") not in allowed:
                bad(f"cruce: callsPage {call['sid']} {o['statusBefore']} → {o['statusAfter']} no permitido")
    comp = docs["compiler-cases.json"]
    base_hash = comp["canonicalHash"][0]["expected"]["sha256"]
    if comp["compile"][0]["input"]["profile"]["storedHash"] != base_hash:
        bad("cruce: el primer canonicalHash no es la huella del perfil base")
    return n


# ── PHP (--php) ─────────────────────────────────────────────────────────────
PHP_SRC = r'''<?php
declare(strict_types=1);
$root = $argv[1];
require $root.'/app/Services/UltronStudio/StudioContentHasher.php';
require $root.'/app/Services/Voice/ElevenLabs/ElevenLabsWebhookSignature.php';
require $root.'/app/Services/Voice/ElevenLabs/ElevenLabsWebhookEnvelope.php';
use App\Services\UltronStudio\StudioContentHasher;
use App\Services\Voice\ElevenLabs\ElevenLabsWebhookSignature;
use App\Services\Voice\ElevenLabs\ElevenLabsWebhookEnvelope;
$in = json_decode((string) file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
$out = ['canon' => [], 'el' => [], 'env' => [], 'hmac' => [], 'easter' => [], 'tw' => []];
foreach ($in['canon'] as $i => $v) {
    try { $c = StudioContentHasher::canonicalJson($v); $out['canon'][$i] = [$c, StudioContentHasher::hash($v)]; }
    catch (InvalidArgumentException $e) { $out['canon'][$i] = 'throws'; }
}
foreach ($in['el'] as $i => $c) {
    try { $out['el'][$i] = ElevenLabsWebhookSignature::verify($c[0], $c[1], $c[2], $c[3]); }
    catch (InvalidArgumentException $e) { $out['el'][$i] = 'throws'; }
}
foreach ($in['env'] as $i => $raw) { $out['env'][$i] = ElevenLabsWebhookEnvelope::type($raw); }
foreach ($in['hmac'] as $i => $h) {
    $k = base64_decode(substr($h[0], 7));
    $out['hmac'][$i] = hash_hmac('sha256', $h[2], hash_hmac('sha256', $h[1], $k, true));
}
foreach ($in['tw'] as $i => $t) { $out['tw'][$i] = base64_encode(hash_hmac('sha1', $t[1], $t[0], true)); }
foreach ($in['easter'] as $y) { $d = (new DateTimeImmutable($y.'-03-21'))->modify('+'.easter_days((int) $y).' days'); $out['easter'][$y] = $d->format('Y-m-d'); }
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
'''


def php_check(docs):
    comp = docs["compiler-cases.json"]
    canon_in, canon_exp = [], []
    for c in comp["canonicalHash"]:
        canon_in.append(c["value"])
        canon_exp.append(c["expected"])
    for c in comp["compile"]:
        canon_in.append(c["input"]["profile"]["content"])
        canon_exp.append({"storedHash": c["input"]["profile"]["storedHash"], "mismatch": c["expected"].get("reason") == "profile_hash_mismatch"})
    ev = docs["elevenlabs-webhook-vectors.json"]["verify"]
    eb = docs["elevenlabs-webhook-bodies.json"]["postCall"]
    pt = docs["policy-tables.json"]
    hm = [(pt["keys"]["appKeyBase64"], pt["keys"][lab], c["e164"]) for c in pt["phoneHash"] if "remoteHash" in c["expected"]
          for lab in ("voicePhoneLabel", "canaryPhoneLabel")]
    tw = []
    for c in docs["twilio-signature-vectors.json"]["compute"]:
        tw.append((c["token"], c["url"] if "rawBody" in c else c["expected"]["base"], c["expected"]["signature"]))
    payload = {"canon": canon_in, "el": [[c["rawBody"], c["header"], c["secret"], c["now"]] for c in ev],
               "env": [c["rawBody"] for c in eb], "hmac": hm, "tw": [[t[0], t[1]] for t in tw], "easter": ["2026", "2027", "2028"]}
    with tempfile.TemporaryDirectory() as tmp:
        sp, ip = Path(tmp) / "voice-php-check.php", Path(tmp) / "in.json"
        sp.write_text(PHP_SRC)
        ip.write_text(json.dumps(payload, ensure_ascii=False))
        r = subprocess.run(["php", "-n", "-d", "error_reporting=E_ALL", str(sp), str(BACKEND), str(ip)], capture_output=True, text=True)
    if r.returncode != 0:
        bad(f"--php: PHP falló: {r.stderr.strip()[:400]}")
        return {}
    res = json.loads(r.stdout)
    fails = 0
    for i, exp in enumerate(canon_exp):
        got = res["canon"][i]
        if "storedHash" in exp:
            if (got[1] == exp["storedHash"]) == exp["mismatch"]:
                fails += 1
                bad(f"--php: la huella del perfil del caso de compilación {i} no cuadra con StudioContentHasher::hash")
        elif "throws" in exp:
            if got != "throws":
                fails += 1
                bad(f"--php: canonicalHash {i} PHP no lanza")
        elif got == "throws" or got[0] != exp["canonicalJson"] or got[1] != exp["sha256"]:
            fails += 1
            bad(f"--php: canonicalHash {i}: PHP {got} ≠ fixture {exp}")
    for i, c in enumerate(ev):
        if res["el"][i] != c["expected"]["signatureResult"]:
            fails += 1
            bad(f"--php: ElevenLabsWebhookSignature::verify da {res['el'][i]} en «{c['name']}» y el fixture {c['expected']['signatureResult']}")
    for i, c in enumerate(eb):
        exp = c["expected"].get("envelopeType")
        if res["env"][i] != exp:
            fails += 1
            bad(f"--php: ElevenLabsWebhookEnvelope::type da {res['env'][i]} en «{c['name']}» y el fixture {exp}")
    k = 0
    for c in pt["phoneHash"]:
        if "remoteHash" in c["expected"]:
            if res["hmac"][k] != c["expected"]["remoteHash"] or res["hmac"][k + 1] != c["expected"]["canaryFingerprint"]:
                fails += 1
                bad(f"--php: huella de teléfono de «{c['name']}» no cuadra con hash_hmac")
            k += 2
    for i, t in enumerate(tw):
        if res["tw"][i] != t[2]:
            fails += 1
            bad(f"--php: firma de Twilio {i} {res['tw'][i]} ≠ {t[2]}")
    hol = docs["holidays-co-2026-2028.json"]["easter"]
    for y in ("2026", "2027", "2028"):
        if res["easter"][y] != hol[y]:
            fails += 1
            bad(f"--php: easter_days({y}) da {res['easter'][y]} y el fixture {hol[y]}")
    return {"canonicalHash+perfiles": len(canon_in), "elevenlabsVerify": len(ev), "envelope": len(eb), "huellasTelefono": len(hm),
            "firmasTwilio": len(tw), "pascua": 3, "fallos": fails, "php": subprocess.run(["php", "-v"], capture_output=True, text=True).stdout.split("\n")[0]}


# ── SDK de ElevenLabs (--sdk-python) ────────────────────────────────────────
SDK_HARNESS = r'''
import json, sys, time
from elevenlabs.client import ElevenLabs
cases = json.load(open(sys.argv[1]))
client = ElevenLabs(api_key="h8fixture-not-a-key")
out = []
for c in cases:
    time.time = (lambda n: (lambda: float(n)))(c["now"])
    try:
        client.webhooks.construct_event(c["rawBody"], c["header"], c["secret"])
        out.append("accept")
    except Exception:
        out.append("reject")
print(json.dumps(out))
'''


def sdk_check(docs, python):
    ev = docs["elevenlabs-webhook-vectors.json"]["verify"]
    with tempfile.TemporaryDirectory() as tmp:
        hp, ip = Path(tmp) / "harness.py", Path(tmp) / "cases.json"
        hp.write_text(SDK_HARNESS)
        ip.write_text(json.dumps([{"rawBody": c["rawBody"], "header": c["header"], "secret": c["secret"], "now": c["now"]} for c in ev]))
        r = subprocess.run([python, "-I", str(hp), str(ip)], capture_output=True, text=True)
    if r.returncode != 0:
        bad(f"--sdk-python: el arnés falló: {r.stderr.strip()[:400]}")
        return None
    got = json.loads(r.stdout)
    diffs = [(c["name"], c["expected"]["sdkConstructEvent"], g) for c, g in zip(ev, got) if c["expected"]["sdkConstructEvent"] != g]
    for d in diffs:
        bad(f"--sdk-python: «{d[0]}»: fixture {d[1]}, SDK {d[2]}")
    return {"vectores": len(ev), "discrepancias": len(diffs)}


# ── comparación y variantes ─────────────────────────────────────────────────
def compare(pairs, fname, report=True):
    n = 0
    for c, got in pairs:
        exp = c["expected"]
        cmp_exp = {k: exp[k] for k in got if k in exp}
        missing = [k for k in got if k not in exp]
        if cmp_exp != got or missing:
            n += 1
            if report:
                bad(f"{fname}: DISCREPA «{c['name']}»\n      fixture: {json.dumps(cmp_exp, ensure_ascii=False)[:400]}\n      rehecho: {json.dumps(got, ensure_ascii=False)[:400]}")
    return n


VARIANTS = {
    "twilio-signature-vectors.json": ["tw_no_sort", "tw_no_dedupe", "tw_numeric_value_sort", "tw_key_case_insensitive", "tw_fullurl",
                                      "tw_ignore_body_hash", "tw_no_port_tolerance", "tw_sha256", "tw_no_rtrim"],
    "elevenlabs-webhook-vectors.json": ["el_no_t_prefix", "el_le_tolerance", "el_no_future", "el_case_sensitive", "el_first_v0_only",
                                        "el_reserialize", "el_tolerance_300"],
    "holidays-co-2026-2028.json": ["ho_no_emiliani", "ho_monday_moves_too", "ho_easter_no_move", "ho_holy_week_off_by_one", "ho_easter_week_late"],
    "policy-tables.json": ["sc_utc", "sc_closed_interval", "sc_saturday_19", "sc_sunday_ok", "sc_ignore_holidays", "sc_own_invalid_open",
                           "fr_count_canceled", "fr_count_inbound", "fr_utc_day", "fr_rolling_as_week", "fr_week_sunday",
                           "co_no_four_eyes", "co_recorded_ok", "co_tie_low_id", "co_first_event", "co_lead_counts",
                           "ex_canary_ignores", "ex_ignore_denied", "ex_rne_boundary_exclusive",
                           "in_code_first", "in_concurrency_gt", "in_first_only",
                           "co_floor_minutes", "co_no_media_streams", "co_no_transfer_leg", "co_budget_lt"],
    "state-transitions.json": ["st_final_mutable", "st_backwards", "st_dialing_cancel", "st_insert_any"],
    "compiler-cases.json": ["cm_self_approval", "cm_only_approved", "cm_strip_invisibles", "cm_catalog_discount_only", "cm_end_call_optional",
                            "cm_no_clamp", "cm_tools_order_matters", "cm_hash_includes_uuid"],
    "outbound-gate-cases.json": ["gt_first_only", "gt_cap_zero_also_reached", "gt_contacts_source_forbidden", "gt_canary_ignores_exclusion",
                                 "gt_concurrency_gt", "wa_no_national_prefix", "wa_literal"],
}


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--dir", default=str(HERE.parent))
    ap.add_argument("--php", action="store_true")
    ap.add_argument("--sdk-python")
    ap.add_argument("--no-variants", action="store_true")
    a = ap.parse_args()
    d = Path(a.dir)
    docs, texts = {}, {}
    for f in FILES:
        p = d / f
        if not p.is_file():
            bad(f"falta {f}")
            continue
        texts[f] = p.read_text(encoding="utf-8")
        docs[f] = json.loads(texts[f])
    if len(docs) != len(FILES):
        return finish({})
    counts = {}
    for f in FILES:
        check_shape(f, texts[f], docs[f])
        counts[f] = docs[f]["counts"]["total"]
    shape_problems = len(problems)
    notice = docs["elevenlabs-webhook-bodies.json"]["world"]["p9Notice"]
    hol_pairs, allh = derive_holidays(docs["holidays-co-2026-2028.json"])
    derivations = {
        "twilio-signature-vectors.json": lambda v: derive_twilio_sig(docs["twilio-signature-vectors.json"], v),
        "elevenlabs-webhook-vectors.json": lambda v: derive_eleven_sig(docs["elevenlabs-webhook-vectors.json"], v),
        "holidays-co-2026-2028.json": lambda v: derive_holidays(docs["holidays-co-2026-2028.json"], v)[0],
        "policy-tables.json": lambda v: derive_policy(docs["policy-tables.json"], allh if not any(x.startswith("ho_") for x in v) else allh, v),
        "state-transitions.json": lambda v: derive_states(docs["state-transitions.json"], v),
        "compiler-cases.json": lambda v: derive_compiler(docs["compiler-cases.json"], notice, v),
        "outbound-gate-cases.json": lambda v: derive_gate(docs["outbound-gate-cases.json"], v),
    }
    rehechos = 0
    disc = 0
    for f, fn in derivations.items():
        pairs = fn(frozenset())
        rehechos += len(pairs)
        tally(f, len(pairs))
        disc += compare(pairs, f)
    cruces = cross_checks(docs)
    print(f"ficheros: {len(docs)} · casos: {json.dumps(counts, ensure_ascii=False)} · total {sum(counts.values())}")
    print(f"openssl: {subprocess.run(['openssl', 'version'], capture_output=True, text=True).stdout.strip()}")
    print("forma: " + ("OK" if not shape_problems else f"{shape_problems} problemas"))
    print(f"derivación independiente: {rehechos} casos rehechos · discrepancias: {disc}")
    print(f"cruces entre ficheros: {cruces} comprobaciones")
    var_report = {}
    if not a.no_variants:
        caught = total = 0
        for f, names in VARIANTS.items():
            for name in names:
                total += 1
                n = compare(derivations[f](frozenset({name})), f, report=False)
                var_report[name] = n
                if n:
                    caught += 1
                else:
                    bad(f"variante NO cazada: {name} ({f})")
        print(f"variantes equivocadas: {total} · cazadas {caught}")
        print("  " + " ".join(f"{k}:{v}" for k, v in var_report.items()))
    if a.php:
        print("php: " + json.dumps(php_check(docs), ensure_ascii=False))
    if a.sdk_python:
        print("sdk: " + json.dumps(sdk_check(docs, a.sdk_python), ensure_ascii=False))
    else:
        print("sdk: no ejecutado (sin --sdk-python): sdkConstructEvent sigue «por confirmar con el SDK»")
    return finish(var_report)


def finish(_):
    for p in problems:
        print("  " + p)
    print("RESULTADO: " + ("OK" if not problems else f"FALLA ({len(problems)})"))
    return 0 if not problems else 1


if __name__ == "__main__":
    sys.exit(main())

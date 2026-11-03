#!/usr/bin/env python3
"""Genera los fixtures de voz del Hito 8 (tests/fixtures/voice/*.json).

Los casos están escritos A MANO en sec_*.py, leyendo
backend/docs/ultron-studio/ULTRON_STUDIO_H8_CONTRACT.md (congelado el
03-11-2026, commit 57bab8a) y la auditoría ULTRON_STUDIO_H8_VOICE_AUDIT.md.
Cada `expected` es un literal decidido desde el contrato, la ley o la
documentación oficial; donde el contrato no lo fija, el caso lo dice
(`basis: decision-fixture` y su `decision`, que el README explica).

Aquí solo hay:
  - las claves y los secretos SINTÉTICOS de prueba (nunca los de un entorno);
  - las primitivas criptográficas (hmac/hashlib) para lo que no se puede
    escribir a mano (firmas, huellas, sha256);
  - aritmética de fechas (día de la semana, Pascua) y la serialización.

Uso:  python3 -I build.py [directorio de salida]   (por defecto, ../../ = tests/fixtures/voice)
No lo ejecuta la suite: es la herramienta con la que se escribieron los fixtures.
Reproducible: dos ejecuciones dan los mismos bytes (sin fechas de generación).
"""
import base64
import calendar
import datetime as dt
import hashlib
import hmac
import json
import re
import sys
import unicodedata
from pathlib import Path

HERE = Path(__file__).resolve().parent
OUT_DIR = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else HERE.parent.parent

CONTRACT = "backend/docs/ultron-studio/ULTRON_STUDIO_H8_CONTRACT.md (congelado el 03-11-2026, commit 57bab8a)"
AUDIT = "backend/docs/ultron-studio/ULTRON_STUDIO_H8_VOICE_AUDIT.md (03-11-2026)"
GENERATED_BY = "tests/fixtures/voice/tools/build/build.py (python3 -I), con los casos a mano en sec_*.py"
CHECKED_BY = "tests/fixtures/voice/tools/voice-fixtures-check.py"

BASIS = {
    "contrato": "Lo dice el contrato H8 tal cual (contractRef).",
    "oficial": "Lo dice la documentación oficial o el SDK oficial del proveedor (source).",
    "ley": "Lo dice la ley citada (Ley 2300 de 2023, Ley 51 de 1983).",
    "supuesto-lead": "Sigue un supuesto de la congelación del lead (P-n), que el propietario puede cambiar.",
    "decision-fixture": "El contrato no lo fija: lo decide este fixture (campo decision; README §Decisiones). Lo congela el lead.",
}

# ── Claves y secretos de prueba (SINTÉTICOS) ────────────────────────────────
# APP_KEY: la misma del fixture del H6 (bytes 00..1f), para poder cruzar huellas.
APP_KEY_B64 = "base64:" + base64.b64encode(bytes(range(32))).decode()
assert APP_KEY_B64 == "base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8="
VOICE_PHONE_LABEL = "voice-phone"                 # D8-17 (etiqueta literal del contrato)
CANARY_PHONE_LABEL = "ultron-canary-phone/1"      # PhoneFingerprint::LABEL (H6), la del registro de canarios

TWILIO_OFFICIAL_TOKEN = "12345"                   # el de los vectores oficiales de Twilio
TWILIO_SUBACCOUNT_SID = "AC" + "8f" * 16          # subcuenta «Voz» inventada
TWILIO_SIGNING_TOKEN = "h8fixturesubaccountsigning000001"   # TWILIO_VOICE_SIGNING_TOKEN sintético (32 caracteres)
TWILIO_OTHER_TOKEN = "h8fixtureMASTERotpTOKEN000000002"     # el maestro del OTP, sintético: no vale para la voz
assert len(TWILIO_SIGNING_TOKEN) == 32 and len(TWILIO_OTHER_TOKEN) == 32

EL_POSTCALL_SECRET = "wsec_h8fixture_postcall_00000000000000000000000001"
EL_POSTCALL_OTHER = "wsec_h8fixture_postcall_00000000000000000000000002"
EL_TOOL_BEARER = "h8fixture-tool-bearer-0000000000000000000001"
EL_INITIATION_VALUE = "h8fixture-initiation-0000000000000000000001"

VOICE_WEBHOOK_BASE_URL = "https://crm.ironbody.test"   # VOICE_WEBHOOK_BASE_URL sintético (D8-5)

# Teléfonos de ficción (la serie del H6 +57 300 000 00xx y fijos de Bogotá 601 000 00xx).
VOICE_FROM = "+576010000090"          # TWILIO_VOICE_FROM: el número dedicado (fijo local, auditoría §c.3)
WHATSAPP_DISPLAY = "+573000000050"    # WHATSAPP_DISPLAY_PHONE de ficción
CANARY_A = "+573000000031"
CANARY_B = "+573000000032"
STRANGER = "+573000000077"
CONTACT_A = "+573000000041"
DEST_RECEPCION = "+576010000011"
DEST_GERENCIA = "+576010000012"


def key_bytes(app_key: str) -> bytes:
    return base64.b64decode(app_key[7:]) if app_key.startswith("base64:") else app_key.encode()


def subkey(label: str, app_key: str = APP_KEY_B64) -> bytes:
    """k = HMAC-SHA256(clave = APP_KEY cruda, mensaje = etiqueta): el patrón de PhoneFingerprint y UltronPublishedConfig."""
    return hmac.new(key_bytes(app_key), label.encode(), hashlib.sha256).digest()


def phone_hash(e164: str, label: str = VOICE_PHONE_LABEL) -> str:
    return hmac.new(subkey(label), e164.encode(), hashlib.sha256).hexdigest()


def sha256_hex(s) -> str:
    if isinstance(s, str):
        s = s.encode()
    return hashlib.sha256(s).hexdigest()


def tw_sig(token: str, base: str) -> str:
    return base64.b64encode(hmac.new(token.encode(), base.encode(), hashlib.sha1).digest()).decode()


def tw_base(url: str, pairs) -> str:
    """URL + cada clave ordenada (orden de bytes) con sus valores ordenados y sin repetir (vector oficial de claves repetidas)."""
    out = url
    for k in sorted({k for k, _ in pairs}, key=lambda x: x.encode()):
        for v in sorted({v for kk, v in pairs if kk == k}, key=lambda x: x.encode()):
            out += k + v
    return out


def el_sig(t: str, body: str, secret: str) -> str:
    return hmac.new(secret.encode(), f"{t}.{body}".encode(), hashlib.sha256).hexdigest()


def _php_key(k: str):
    """json_decode(..., true) convierte en entero una clave que es un entero decimal canónico."""
    if re.fullmatch(r"0|-?[1-9][0-9]*", k):
        v = int(k)
        if -2 ** 63 <= v < 2 ** 63:
            return v
    return k


def _php_canon(x, path="$"):
    """StudioContentHasher::canonicalize tal como lo ejecuta PHP sobre lo que da json_decode(…, true)."""
    if isinstance(x, bool) or x is None or isinstance(x, str):
        return x
    if isinstance(x, int):
        return x
    if isinstance(x, float):
        raise ValueError(f"decimal en {path}: StudioContentHasher lanza InvalidArgumentException")
    if isinstance(x, list):
        return [_php_canon(v, f"{path}[{i}]") for i, v in enumerate(x)]
    if isinstance(x, dict):
        keys = [_php_key(k) for k in x]
        if keys == list(range(len(keys))):          # array_is_list (también {} → [])
            return [_php_canon(v, f"{path}[{i}]") for i, v in enumerate(x.values())]
        pairs = sorted(((str(pk), _php_canon(v, f"{path}.{k}")) for pk, k, v in zip(keys, x.keys(), x.values())),
                       key=lambda kv: kv[0].encode())            # ksort(SORT_STRING): orden de bytes
        if [_php_key(k) for k, _ in pairs] == list(range(len(pairs))):   # json_encode decide otra vez lista u objeto
            return [v for _, v in pairs]
        return dict(pairs)
    raise TypeError(f"tipo no admitido en {path}: {type(x).__name__}")


def canonical(o) -> str:
    """JSON canónico de StudioContentHasher (H5), byte a byte como PHP 8.3: claves ordenadas a todos los niveles por bytes,
    listas en su orden, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES; además las rarezas de PHP (DF-C10):
    un objeto vacío o con claves 0..n-1 sale lista, y U+2028/U+2029 salen escapados."""
    text = json.dumps(_php_canon(o), ensure_ascii=False, separators=(",", ":"))
    return text.replace(chr(0x2028), "\\u2028").replace(chr(0x2029), "\\u2029")


def content_hash(o) -> str:
    return sha256_hex(canonical(o))


# ── Fechas ──────────────────────────────────────────────────────────────────
BOGOTA = dt.timezone(dt.timedelta(hours=-5), "America/Bogota")   # sin horario de verano desde 1993
WEEKDAYS = ["lunes", "martes", "miércoles", "jueves", "viernes", "sábado", "domingo"]


def epoch(y, mo, d, h=0, mi=0, s=0) -> int:
    return calendar.timegm((y, mo, d, h, mi, s, 0, 0, 0))


def utc_iso(y, mo, d, h=0, mi=0, s=0) -> str:
    return dt.datetime(y, mo, d, h, mi, s, tzinfo=dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def bog_to_utc_iso(y, mo, d, h=0, mi=0, s=0) -> str:
    t = dt.datetime(y, mo, d, h, mi, s, tzinfo=BOGOTA).astimezone(dt.timezone.utc)
    return t.strftime("%Y-%m-%dT%H:%M:%SZ")


def parse_iso(s: str) -> dt.datetime:
    return dt.datetime.fromisoformat(s.replace("Z", "+00:00"))


def to_bogota(s: str) -> dt.datetime:
    return parse_iso(s).astimezone(BOGOTA)


def weekday_es(d: dt.date) -> str:
    return WEEKDAYS[d.weekday()]


# ── Colección de casos ──────────────────────────────────────────────────────
FILES = {}
DECISIONS = {}      # registro único de decisiones del fixture (DF-…): cada fichero lista las que usan sus casos


def decisions(**kw):
    for k, v in kw.items():
        k = k.replace("_", "-")
        assert k not in DECISIONS or DECISIONS[k] == v, f"decisión {k} redefinida"
        DECISIONS[k] = v


class Fixture:
    def __init__(self, name, header):
        self.name = name
        self.header = header
        self.sections = {}
        self.names = set()
        FILES[name] = self

    def section(self, sec):
        self.sections.setdefault(sec, [])
        return self.sections[sec]

    def add(self, sec, kind, desc, ref, basis, fields, expected, *, decision=None, extra=None):
        assert basis in BASIS, basis
        if basis == "decision-fixture":
            assert decision, f"{desc}: un caso decision-fixture necesita su decision"
        name = f"{sec} · {kind} · {desc}"
        assert name not in self.names, f"nombre repetido: {name}"
        self.names.add(name)
        case = {"name": name, "kind": kind, "contractRef": ref, "basis": basis}
        if decision:
            case["decision"] = decision
        case.update(fields)
        if extra:
            case.update(extra)
        case["expected"] = expected
        self.section(sec).append(case)
        return case


def _needs_escape(c: str) -> bool:
    if c == "\n":
        return False          # los saltos de línea crudos de la salida son los de la sangría (json.dumps escapa los de los textos)
    cat = unicodedata.category(c)
    if cat in ("Cc", "Cf", "Cs", "Co", "Cn", "Zl", "Zp", "Mn", "Me"):
        return True
    if cat == "Zs" and c != " ":
        return True
    return False


def _escape_invisibles(text: str) -> str:
    out = []
    for c in text:
        if _needs_escape(c):
            cp = ord(c)
            if cp > 0xFFFF:
                cp -= 0x10000
                out.append("\\u%04x\\u%04x" % (0xD800 + (cp >> 10), 0xDC00 + (cp & 0x3FF)))
            else:
                out.append("\\u%04x" % cp)
        else:
            out.append(c)
    return "".join(out)


def vis(text: str) -> str:
    """Un texto con sus invisibles a la vista (para los nombres de los casos): U+200B → ⟦U+200B⟧."""
    return "".join(f"⟦U+{ord(c):04X}⟧" if _needs_escape(c) else c for c in text)


def serialize(obj) -> str:
    raw = json.dumps(obj, ensure_ascii=False, indent=2)
    text = _escape_invisibles(raw) + "\n"
    assert json.loads(text) == obj, "el escape de invisibles cambió el contenido"
    return text


def write_all():
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    report = {}
    for name, fx in FILES.items():
        doc = dict(fx.header)
        def refs(x):
            if isinstance(x, dict):
                for k, v in x.items():
                    if k == "decision" and isinstance(v, str):
                        yield v
                    else:
                        yield from refs(v)
            elif isinstance(x, list):
                for v in x:
                    yield from refs(v)
        used = sorted(set(refs(fx.sections)),
                      key=lambda d: (d.split("-")[1][0], int("".join(ch for ch in d.split("-")[1] if ch.isdigit()))))
        missing = [d for d in used if d not in DECISIONS]
        assert not missing, f"{name}: decisiones sin definir {missing}"
        doc["decisions"] = {d: DECISIONS[d] for d in used}
        counts = {s: len(v) for s, v in fx.sections.items()}
        doc["counts"] = dict(counts, total=sum(counts.values()))
        doc.update(fx.sections)
        path = OUT_DIR / name
        path.write_text(serialize(doc), encoding="utf-8")
        report[name] = doc["counts"]
    return report


def header(name, sections, sources, **extra):
    h = {
        "fixture": name,
        "version": 1,
        "contract": CONTRACT,
        "audit": AUDIT,
        "contractSections": sections,
        "generatedBy": GENERATED_BY,
        "checkedBy": CHECKED_BY,
        "readme": "tests/fixtures/voice/README.md",
        "basisLegend": BASIS,
        "sources": sources,
        "syntheticData": "Ningún dato real: SIDs, ids, secretos, tokens, URLs (.test) y teléfonos (+57 300 000 00xx, +57 601 000 00xx) inventados.",
    }
    for k, v in extra.pop("decisions", {}).items():
        decisions(**{k: v})
    h.update(extra)
    return h


SECTION_FILES = [
    "sec_twilio_sig.py", "sec_eleven_bodies.py", "sec_eleven_sig.py", "sec_twilio_bodies.py",
    "sec_holidays.py", "sec_policy.py", "sec_states.py", "sec_compiler.py", "sec_gate.py", "sec_chain.py",
]

if __name__ == "__main__":
    for f in SECTION_FILES:
        src = (HERE / f).read_text(encoding="utf-8")
        exec(compile(src, str(HERE / f), "exec"), globals())
    rep = write_all()
    for n, c in rep.items():
        print(f"{n}: {json.dumps(c, ensure_ascii=False)}")
    print("total:", sum(c["total"] for c in rep.values()))

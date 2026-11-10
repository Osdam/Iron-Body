#!/usr/bin/env python3
"""Genera los fixtures del Hito 9 (tests/fixtures/outreach/*.json): TEST9-a, paso 1.

Los casos están escritos A MANO en sec_*.py, leyendo
backend/docs/ultron-studio/ULTRON_STUDIO_H9_CONTRACT.md (congelado por el lead el
09-11-2026 20:30 UTC). Cada `expected` es un literal decidido desde el contrato, la
ley o el código que el contrato manda reutilizar; donde el contrato no lo fija, el
caso lo dice (`basis: decision-fixture` y su `decision`, DF-…, que el README explica).

Aquí solo hay:
  - las claves SINTÉTICAS de prueba (nunca las de un entorno);
  - las primitivas (sha256, HMAC, JSON canónico de StudioContentHasher) para lo que
    no se puede escribir a mano;
  - aritmética de fechas (día de Bogotá, Pascua) y la serialización;
  - las REFERENCIAS propias (validador, evaluador, escalera…) con las que el
    generador contrasta los literales escritos a mano: si una referencia y un
    literal discrepan, el generador se para (assert). Las trazas completas y las
    huellas las calcula la referencia; los veredictos son literales.

Uso:  python3 -I build.py [directorio de salida]   (por defecto, ../../ = tests/fixtures/outreach)
No lo ejecuta la suite: es la herramienta con la que se escribieron los fixtures.
Reproducible: dos ejecuciones dan los mismos bytes (sin fechas de generación).
"""
import base64
import calendar
import datetime as dt
import hashlib
import hmac
import json
import math
import re
import sys
import unicodedata
from pathlib import Path

HERE = Path(__file__).resolve().parent
OUT_DIR = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else HERE.parent.parent

CONTRACT = "backend/docs/ultron-studio/ULTRON_STUDIO_H9_CONTRACT.md (congelado por el lead el 09-11-2026 20:30 UTC; §13.2 renumerado T1–T23)"
GENERATED_BY = "tests/fixtures/outreach/tools/build/build.py (python3 -I), con los casos a mano en sec_*.py"
CHECKED_BY = "tests/Unit/Outreach/FixturesSanityTest.php (forma, unicidad, canonicalización y huellas, sin código del motor)"
README = "tests/fixtures/outreach/README.md"

BASIS = {
    "contrato": "Lo dice el contrato H9 tal cual (contractRef).",
    "ley": "Lo dice la ley citada (Ley 2300 de 2023, Ley 1581 de 2012, Ley 51 de 1983, Ley 2578 de 2026).",
    "codigo-existente": "Lo hace hoy el código que el contrato manda reutilizar o igualar (ContactPolicy, OutboundSchedule, ColombianHolidays, VoiceFrequency, StudioContentHasher…); la cita va en contractRef.",
    "supuesto-lead": "Sigue un supuesto de la congelación del lead (P9-n), que el propietario puede cambiar.",
    "decision-fixture": "El contrato no lo fija: lo decide este fixture (campo decision; README §Decisiones). Lo congela el lead.",
}

# ── Claves de prueba (SINTÉTICAS) ───────────────────────────────────────────
# APP_KEY: la misma de los fixtures del H6 y del H8 (bytes 00..1f), para poder cruzar huellas.
APP_KEY_B64 = "base64:" + base64.b64encode(bytes(range(32))).decode()
assert APP_KEY_B64 == "base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8="
RAW_APP_KEY = "h6-fixture-app-key-NOT-A-SECRET!"     # la APP_KEY sin base64: del fixture del H6 (32 bytes ASCII)


def key_bytes(app_key: str) -> bytes:
    return base64.b64decode(app_key[7:]) if app_key.startswith("base64:") else app_key.encode()


def subkey(label: str, app_key: str = APP_KEY_B64) -> bytes:
    """k = HMAC-SHA256(clave = APP_KEY cruda, mensaje = etiqueta): el patrón de PhoneFingerprint y UltronPublishedConfig."""
    return hmac.new(key_bytes(app_key), label.encode(), hashlib.sha256).digest()


def sha256_hex(s) -> str:
    if isinstance(s, str):
        s = s.encode()
    return hashlib.sha256(s).hexdigest()


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
        if [_php_key(k) for k, _ in pairs] == list(range(len(pairs))):
            return [v for _, v in pairs]
        return dict(pairs)
    raise TypeError(f"tipo no admitido en {path}: {type(x).__name__}")


def canonical(o) -> str:
    """JSON canónico de StudioContentHasher::canonicalJson (H5), byte a byte como PHP 8.3: claves ordenadas a todos los
    niveles por bytes, listas en su orden, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES; un objeto vacío sale [] y
    U+2028/U+2029 salen escapados (rarezas de PHP, DF-C10 del H8)."""
    text = json.dumps(_php_canon(o), ensure_ascii=False, separators=(",", ":"))
    return text.replace(chr(0x2028), "\\u2028").replace(chr(0x2029), "\\u2029")


def content_hash(o) -> str:
    return sha256_hex(canonical(o))


# ── Fechas ──────────────────────────────────────────────────────────────────
BOGOTA = dt.timezone(dt.timedelta(hours=-5), "America/Bogota")   # sin horario de verano desde 1993
WEEKDAYS = ["lunes", "martes", "miércoles", "jueves", "viernes", "sábado", "domingo"]


def utc_iso(y, mo, d, h=0, mi=0, s=0) -> str:
    return dt.datetime(y, mo, d, h, mi, s, tzinfo=dt.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def bog(y, mo, d, h=0, mi=0, s=0) -> str:
    """Una hora de pared de Bogotá escrita como instante UTC (Z), que es como viajan los instantes en los fixtures."""
    t = dt.datetime(y, mo, d, h, mi, s, tzinfo=BOGOTA).astimezone(dt.timezone.utc)
    return t.strftime("%Y-%m-%dT%H:%M:%SZ")


def parse_iso(s: str) -> dt.datetime:
    return dt.datetime.fromisoformat(s.replace("Z", "+00:00"))


def to_bogota(s: str) -> dt.datetime:
    return parse_iso(s).astimezone(BOGOTA)


def bog_date(s: str) -> dt.date:
    return to_bogota(s).date()


def weekday_es(d: dt.date) -> str:
    return WEEKDAYS[d.weekday()]


def plus_seconds(s: str, secs: int) -> str:
    return (parse_iso(s) + dt.timedelta(seconds=secs)).strftime("%Y-%m-%dT%H:%M:%SZ")


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
        self.extra = {}
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
        return False
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


def serialize(obj) -> str:
    raw = json.dumps(obj, ensure_ascii=False, indent=2)
    text = _escape_invisibles(raw) + "\n"
    assert json.loads(text) == obj, "el escape de invisibles cambió el contenido"
    return text


def _decision_sort_key(d):
    # DF-<GRUPO><n>: por grupo y por número
    m = re.fullmatch(r"DF-([A-Z]+)(\d+)", d)
    assert m, f"decisión mal nombrada: {d}"
    return (m.group(1), int(m.group(2)))


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
        used = sorted(set(refs(fx.sections)) | set(refs(fx.extra)), key=_decision_sort_key)
        missing = [d for d in used if d not in DECISIONS]
        assert not missing, f"{name}: decisiones sin definir {missing}"
        doc["decisions"] = {d: DECISIONS[d] for d in used}
        counts = {s: len(v) for s, v in fx.sections.items()}
        doc["counts"] = dict(counts, total=sum(counts.values()))
        doc.update(fx.extra)
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
        "contractSections": sections,
        "generatedBy": GENERATED_BY,
        "checkedBy": CHECKED_BY,
        "readme": README,
        "basisLegend": BASIS,
        "sources": sources,
        "syntheticData": "Ningún dato real: ids, uuids, claves y personas inventados; las personas se nombran por subject_key sintético (m:<id>, l:<id>); sin teléfonos, nombres, documentos ni importes.",
    }
    for k, v in extra.pop("decisions", {}).items():
        decisions(**{k: v})
    h.update(extra)
    return h


SOURCE_CONTRACT = {"what": "El contrato del H9 (D9-3 a D9-11, §5, §6, §13 y la Congelación)",
                   "url": "backend/docs/ultron-studio/ULTRON_STUDIO_H9_CONTRACT.md",
                   "consulted": "09-11-2026, en este árbol (sin seguimiento: lo congela el lead)",
                   "how": "Lectura completa; cada caso cita su sección en contractRef."}
SOURCE_HASHER = {"what": "JSON canónico y su sha256", "url": "backend/app/Services/UltronStudio/StudioContentHasher.php (canonicalJson, hash)",
                 "consulted": "09-11-2026, en este árbol (sin cambios desde el H5)",
                 "how": "Claves ordenadas por bytes a todos los niveles, listas en su orden, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES; rechaza los decimales. El generador lo reproduce y FixturesSanityTest lo cruza con la clase real."}

SECTION_FILES = [
    "sec_catalog.py", "sec_conditions.py", "sec_calendar.py", "sec_budget.py", "sec_ladder.py", "sec_channel.py", "sec_priority.py", "sec_outcomes.py", "sec_followup.py", "sec_strategy.py", "sec_chain.py",
]

if __name__ == "__main__":
    for f in SECTION_FILES:
        src = (HERE / f).read_text(encoding="utf-8")
        exec(compile(src, str(HERE / f), "exec"), globals())
    rep = write_all()
    for n, c in rep.items():
        print(f"{n}: {json.dumps(c, ensure_ascii=False)}")
    print("total:", sum(c["total"] for c in rep.values()))

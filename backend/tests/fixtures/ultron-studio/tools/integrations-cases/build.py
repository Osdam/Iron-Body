#!/usr/bin/env python3
"""Genera tests/fixtures/ultron-studio/integrations-cases.json (Hito 6).

Los casos están escritos A MANO en sec_*.py: cada `expected` es un literal
decidido leyendo ULTRON_STUDIO_H6_CONTRACT.md. Aquí solo hay:
  - las claves y los secretos sintéticos de prueba;
  - las primitivas criptográficas (hmac/hashlib) para los valores que no se
    pueden escribir a mano (huellas, firmas);
  - la serialización.
Uso: python3 -I build.py [salida.json]   (por defecto, ../../integrations-cases.json)
No lo ejecuta la suite: es la herramienta con la que se escribió el fixture.
"""
import base64
import hashlib
import hmac
import json
import sys
import unicodedata
from pathlib import Path

HERE = Path(__file__).resolve().parent
OUT = Path(sys.argv[1]) if len(sys.argv) > 1 else HERE.parent.parent / "integrations-cases.json"

# ── Claves de prueba (sintéticas; nunca las de un entorno real) ─────────────
B64KEY = "base64:" + base64.b64encode(bytes(range(32))).decode()   # bytes 00..1f
RAWKEY = "h6-fixture-app-key-NOT-A-SECRET!"                           # 32 bytes ASCII, sin base64:
assert B64KEY == "base64:AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8="
assert len(RAWKEY.encode()) == 32

PHONE_LABEL = b"ultron-canary-phone/1"
CFG_LABEL = b"ultron-studio-config-fingerprint/1"


def key_bytes(app_key: str) -> bytes:
    if app_key.startswith("base64:"):
        return base64.b64decode(app_key[7:])
    return app_key.encode()


def phone_subkey(app_key: str) -> bytes:
    return hmac.new(key_bytes(app_key), PHONE_LABEL, hashlib.sha256).digest()


def phone_fp(e164: str, app_key: str) -> str:
    return hmac.new(phone_subkey(app_key), e164.encode(), hashlib.sha256).hexdigest()


def cfg_subkey(app_key: str) -> bytes:
    return hmac.new(key_bytes(app_key), CFG_LABEL, hashlib.sha256).digest()


def cfg_fp(value: str, app_key: str) -> str:
    return hmac.new(cfg_subkey(app_key), value.encode(), hashlib.sha256).hexdigest()[:8]


def canonical(o) -> str:
    return json.dumps(o, ensure_ascii=False, separators=(",", ":"), sort_keys=True)


def sha256_hex(s: str) -> str:
    return hashlib.sha256(s.encode()).hexdigest()


def el_sig(t: str, body: str, secret: str) -> str:
    return hmac.new(secret.encode(), f"{t}.{body}".encode(), hashlib.sha256).hexdigest()


def tw_sig(token: str, base: str) -> str:
    return base64.b64encode(hmac.new(token.encode(), base.encode(), hashlib.sha1).digest()).decode()


# ── Colección de casos ──────────────────────────────────────────────────────
ORDER = [
    "phone", "alias", "fingerprint", "canaryTransitions", "coherence", "expectation",
    "campaignLinkRules", "metaClassification", "providerClassification", "configFacts", "configDrift",
    "elevenlabsSignature", "elevenlabsEnvelope", "voiceDoctor", "voiceVerify", "voiceCallPolicy",
    "twilioProbeSanitize", "twilioSignatureOfficial", "probeRules", "acknowledgeRules",
    "routeMatrix", "errorOrder",
]
SECTIONS = {s: [] for s in ORDER}
NAMES = set()
EXTRA = {}


def add(section: str, kind: str, desc: str, fields: dict, expected) -> dict:
    name = f"{section} · {kind} · {desc}"
    assert name not in NAMES, f"nombre repetido: {name}"
    NAMES.add(name)
    case = {"name": name, "kind": kind}
    case.update(fields)
    case["expected"] = expected
    SECTIONS[section].append(case)
    return case


NS = dict(globals())
for f in ["sec_phone.py", "sec_alias.py", "sec_fingerprint.py", "sec_canary.py", "sec_coherence.py",
          "sec_expectation.py", "sec_campaign.py", "sec_meta.py", "sec_status.py", "sec_config.py", "sec_elevenlabs.py",
          "sec_voice.py", "sec_twilio.py", "sec_api.py"]:
    exec(compile((HERE / f).read_text(encoding="utf-8"), f, "exec"), NS)

doc = {
    "version": 1,
    "catalogVersion": "2026-10-19.1",
    "contract": "ULTRON_STUDIO_H6_CONTRACT.md (congelado el 31-10-2026) y su Enmienda 1 (Q-1, Q-2)",
    "source": "escrito a mano desde ULTRON_STUDIO_H6_CONTRACT.md (I2–I7, I11); ver integrations-cases.README.md",
    "keys": {"appKeyBase64": B64KEY, "appKeyRaw": RAWKEY,
             "phoneFingerprintLabel": PHONE_LABEL.decode(), "configFingerprintLabel": CFG_LABEL.decode()},
    "closedSets": NS["CLOSED_SETS"],
}
for s in ORDER:
    assert SECTIONS[s], f"sección vacía: {s}"
    doc[s] = SECTIONS[s]


def walk(o, path="$"):
    if isinstance(o, dict):
        assert o, f"objeto vacío en {path} (PHP no distingue {{}} de [])"
        for k, v in o.items():
            assert isinstance(k, str)
            walk(v, f"{path}.{k}")
    elif isinstance(o, list):
        for i, v in enumerate(o):
            walk(v, f"{path}[{i}]")
    else:
        assert o is None or isinstance(o, (bool, int, float, str)), path


walk(doc)
text = json.dumps(doc, ensure_ascii=False, indent=2)

# Los caracteres invisibles o confusos van escapados (\uXXXX): el VALOR es el mismo.
ESCAPE = {"Cc", "Cf", "Zs", "Zl", "Zp", "Mn", "Co", "Cn"}
out = []
for ch in text:
    if ch in "\n " or unicodedata.category(ch) not in ESCAPE:
        out.append(ch)
    else:
        cp = ord(ch)
        assert cp <= 0xFFFF
        out.append("\\u%04x" % cp)
text = "".join(out) + "\n"
assert json.loads(text) == doc
OUT.write_text(text, encoding="utf-8")
counts = {s: len(SECTIONS[s]) for s in ORDER}
print(json.dumps(counts, ensure_ascii=False))
print("total", sum(counts.values()), "bytes", len(text.encode()))

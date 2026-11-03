# voice-chain-vectors.json · la cadena firmada de voz (D8-8 y Enmienda 1, E1-4): ultron_studio_voice_publications
#   subkey  · la subclave de la APP_KEY con el contexto ultron-studio-voice-chain (derivación del H5, copiada)
#   chain   · una cadena de 5 entradas (publicar en canario, promover, publicar otra, revertir, apagar) con su mensaje, huella y firma
#   verify  · el lector frente a firmas y mensajes buenos y malos
# Derivación del H5 (UltronPublishedConfig::subkey y PhoneFingerprint): k = HMAC-SHA256(clave = APP_KEY cruda, mensaje = etiqueta),
# con la APP_KEY leída como Laravel (prefijo base64: decodificado). Firma = hex(HMAC-SHA256(k, mensaje)), 64 hex en minúsculas.
CHAIN_LABEL = "ultron-studio-voice-chain"           # E1-4
H5_LABEL = "ultron-studio-publication"               # UltronPublishedConfig::KEY_LABEL (la cadena del motor)
RAW_APP_KEY = "h6-fixture-app-key-NOT-A-SECRET!"     # la APP_KEY sin base64: del fixture del H6 (32 bytes ASCII)
CHAIN_KEYS = ["actor_id", "agent_config_hash", "prev_hash", "profile_uuid", "provider_agent_id", "published_at", "scope", "seq", "spec_hash", "version_uuid"]
ZERO = "0" * 64


def chain_subkey(label=CHAIN_LABEL, app_key=APP_KEY_B64):
    return subkey(label, app_key)


def chain_sign(message, label=CHAIN_LABEL, app_key=APP_KEY_B64):
    return hmac.new(chain_subkey(label, app_key), message.encode(), hashlib.sha256).hexdigest()


VC = Fixture("voice-chain-vectors.json", header(
    "voice-chain-vectors.json",
    ["E1-4 (el mensaje firmado de la cadena de voz: JSON canónico de {seq, prev_hash, version_uuid, profile_uuid, spec_hash, agent_config_hash, provider_agent_id, scope, actor_id, published_at}; prev_hash = el de la entrada anterior o 64 ceros; HMAC-SHA256 con una subclave de la APP_KEY de contexto ultron-studio-voice-chain, como la cadena del H5)",
     "D8-8 (cadena propia de solo inserción, seq = cabeza + 1, CHECK seq ≥ 1; implementación única en Telephony\\VoicePublishedConfig::signatureFor(); un fallo deniega)",
     "D8-9 (revertir = una entrada que apunta al agente anterior)", "D8-20 (nivel 2: voz apagada = una entrada sin agente)"],
    [{"what": "La derivación de la subclave y la firma de la cadena del H5 (la que E1-4 manda copiar)",
      "url": "backend/app/Services/Marketing/Ultron/UltronPublishedConfig.php (subkey(), signatureFor()) y backend/app/Services/UltronStudio/StudioPublications.php (la inserción y verify())",
      "consulted": "03-11-2026, en este árbol (sin cambios desde el H5)",
      "how": "subkey() = hash_hmac('sha256', KEY_LABEL, APP_KEY cruda, true) con base64: decodificado; signatureFor() = hash_hmac('sha256', CONTRACT.\"\\n\".json, subkey()). La de voz copia la subclave con su propia etiqueta y firma el JSON canónico tal cual (E1-4 define así el mensaje: sin el prefijo de contrato del H5)."},
     {"what": "JSON canónico", "url": "backend/app/Services/UltronStudio/StudioContentHasher.php (canonicalJson)", "consulted": "03-11-2026",
      "how": "Claves ordenadas, sin espacios, UTF-8 sin escapar (E1-4); el comprobador lo cruza con PHP (--php)."}],
    keys={"appKeyBase64": APP_KEY_B64, "appKeyRaw": RAW_APP_KEY, "chainLabel": CHAIN_LABEL, "h5Label": H5_LABEL,
          "chainSubkeyHex": chain_subkey().hex(), "chainSubkeyHexFromRawAppKey": chain_subkey(app_key=RAW_APP_KEY).hex(),
          "h5SubkeyHex": chain_subkey(H5_LABEL).hex()},
    algorithm={
        "subkey": "k = HMAC-SHA256(clave = APP_KEY cruda (base64: decodificado, como EncryptionServiceProvider::parseKey), mensaje = 'ultron-studio-voice-chain'), 32 bytes",
        "message": "JSON canónico (StudioContentHasher::canonicalJson) del objeto de las 10 claves de E1-4, ni una más ni una menos",
        "entryHash": "sha256 hex del mensaje (como effective_hash del H5); es el prev_hash de la entrada siguiente",
        "signature": "hex(HMAC-SHA256(k, mensaje)): 64 hexadecimales en minúscula, sin el prefijo de contrato del H5",
        "genesis": "la primera entrada (seq = 1) lleva prev_hash = 64 ceros",
    },
    closedSets={"keys": CHAIN_KEYS, "scopes": ["canary", "rest", "off"],
                "verifyResults": ["ok", "malformed", "not_canonical", "signature_malformed", "signature_mismatch", "seq_gap", "chain_broken"]},
    decisions={
        "DF-V1": "prev_hash = entryHash de la entrada anterior = sha256 de su mensaje canónico (como effective_hash del H5), no su firma.",
        "DF-V2": "published_at en UTC y al segundo, «Y-m-d\\TH:i:s\\Z» (el issuedAt del H5): sin fracciones ni otro desfase.",
        "DF-V3": "scope ∈ {canary, rest, off}; una entrada off (voz apagada, D8-20 nivel 2) lleva a null version_uuid, profile_uuid, spec_hash, agent_config_hash y provider_agent_id; canary y rest los llevan todos.",
        "DF-V4": "El lector exige que los bytes guardados SEAN el JSON canónico de su propio contenido (recanonicaliza y compara); si no, not_canonical, aunque la firma cuadre con esos bytes.",
        "DF-V5": "La firma es 64 hex en minúscula (como HEX64 del H5); otra forma es signature_malformed.",
        "DF-V6": "Orden del lector: forma (malformed) → canónico (not_canonical) → forma de la firma → firma (signature_mismatch) → seq = anterior + 1 (seq_gap) → prev_hash = entryHash anterior (chain_broken). El primer fallo deniega (D8-8: «un fallo deniega»).",
    },
))


def entry(seq, prev, scope, *, version=None, profile=None, spec=None, payload=None, agent=None, actor=7, at="2026-11-04T15:00:00Z"):
    return {"seq": seq, "prev_hash": prev, "version_uuid": version, "profile_uuid": profile, "spec_hash": spec, "agent_config_hash": payload,
            "provider_agent_id": agent, "scope": scope, "actor_id": actor, "published_at": at}


V12, P03 = "9a000000-0000-4000-8000-000000000012", "9b000000-0000-4000-8000-000000000003"
V13, P04 = "9a000000-0000-4000-8000-000000000013", "9b000000-0000-4000-8000-000000000004"
S1, A1 = sha256_hex("h8fixture-spec-0001"), sha256_hex("h8fixture-agent-payload-0001")
S2, A2 = sha256_hex("h8fixture-spec-0002"), sha256_hex("h8fixture-agent-payload-0002")

PLAN = [
    ("publicar en canario la versión 12 (génesis: prev_hash = 64 ceros)", dict(scope="canary", version=V12, profile=P03, spec=S1, payload=A1, agent=AGENT_OLD, actor=7,
                                                                               at="2026-11-04T15:00:00Z"), "D8-8, E1-4"),
    ("promover la misma publicación a rest", dict(scope="rest", version=V12, profile=P03, spec=S1, payload=A1, agent=AGENT_OLD, actor=7, at="2026-11-05T15:00:00Z"),
     "D8-19, E1-4"),
    ("publicar en canario la versión 13 con otro agente", dict(scope="canary", version=V13, profile=P04, spec=S2, payload=A2, agent=AGENT_CANARY, actor=9,
                                                               at="2026-11-06T15:00:00Z"), "D8-7, E1-4"),
    ("revertir el canario al agente anterior (sigue existiendo)", dict(scope="canary", version=V12, profile=P03, spec=S1, payload=A1, agent=AGENT_OLD, actor=7,
                                                                       at="2026-11-06T16:30:00Z"), "D8-9, D8-20 nivel 1, E1-4"),
    ("apagar la voz: una entrada sin agente", dict(scope="off", actor=7, at="2026-11-06T17:00:00Z"), "D8-20 nivel 2, E1-4"),
]
CHAIN = []
prev = ZERO
for i, (desc, kw, ref) in enumerate(PLAN, start=1):
    e = entry(i, prev, **kw)
    msg = canonical(e)
    assert list(json.loads(msg)) == CHAIN_KEYS, "las claves canónicas en orden de bytes"
    h = sha256_hex(msg)
    sig = chain_sign(msg)
    CHAIN.append({"entry": e, "message": msg, "entryHash": h, "signature": sig})
    basis = "decision-fixture" if kw["scope"] == "off" else "contrato"
    VC.add("chain", "entry", f"seq {i} · {desc}", ref, basis, {"entry": e},
           {"message": msg, "entryHash": h, "signature": sig, "prevHashIsPreviousEntryHash": True},
           decision="DF-V3" if kw["scope"] == "off" else "DF-V1")
    prev = h

# Comprobaciones a mano de la forma (no se fían solo del generador)
assert CHAIN[0]["entry"]["prev_hash"] == ZERO and CHAIN[1]["entry"]["prev_hash"] == CHAIN[0]["entryHash"]
assert CHAIN[0]["message"].startswith('{"actor_id":7,"agent_config_hash":"') and '"prev_hash":"' + ZERO + '"' in CHAIN[0]["message"]
assert '"version_uuid":null' in CHAIN[4]["message"] and '"provider_agent_id":null' in CHAIN[4]["message"]

# ── subkey ──────────────────────────────────────────────────────────────────
VC.add("subkey", "derive", "subclave de la cadena de voz con la APP_KEY base64: (bytes 00..1f)", "E1-4", "contrato",
       {"appKey": APP_KEY_B64, "label": CHAIN_LABEL}, {"subkeyHex": chain_subkey().hex()})
VC.add("subkey", "derive", "subclave de la cadena de voz con una APP_KEY sin base64: (se usa tal cual, como Laravel)", "E1-4", "contrato",
       {"appKey": RAW_APP_KEY, "label": CHAIN_LABEL}, {"subkeyHex": chain_subkey(app_key=RAW_APP_KEY).hex()})
VC.add("subkey", "derive", "la subclave del H5 (ultron-studio-publication) es otra: una firma de un dominio no vale en el otro", "D8-8, E1-4", "contrato",
       {"appKey": APP_KEY_B64, "label": H5_LABEL}, {"subkeyHex": chain_subkey(H5_LABEL).hex(), "differsFromChain": True})
assert chain_subkey(H5_LABEL) != chain_subkey()


# ── verify ──────────────────────────────────────────────────────────────────
def VV(desc, message, signature, previous, result, *, basis="decision-fixture", decision="DF-V6", ref="D8-8, E1-4", app_key=APP_KEY_B64):
    fields = {"message": message, "signature": signature, "previous": previous}
    if app_key != APP_KEY_B64:
        fields["appKey"] = app_key
    VC.add("verify", "read", desc, ref, basis, fields, {"result": result, "denies": result != "ok"}, decision=decision)


def prevref(i):
    return None if i == 0 else {"seq": CHAIN[i - 1]["entry"]["seq"], "entryHash": CHAIN[i - 1]["entryHash"]}


for i, c in enumerate(CHAIN):
    VV(f"la entrada seq {i + 1} de la cadena verifica", c["message"], c["signature"], prevref(i), "ok", basis="contrato", decision=None)
C2 = CHAIN[1]
VV("firmada con la subclave del H5 (ultron-studio-publication) → signature_mismatch", C2["message"], chain_sign(C2["message"], H5_LABEL), prevref(1),
   "signature_mismatch", basis="contrato", decision=None)
VV("firmada con el prefijo de contrato del H5 («ultron-voice-config/1\\n» + mensaje) → signature_mismatch", C2["message"],
   hmac.new(chain_subkey(), ("ultron-voice-config/1\n" + C2["message"]).encode(), hashlib.sha256).hexdigest(), prevref(1), "signature_mismatch",
   basis="contrato", decision=None)
VV("firmada con otra APP_KEY (rotada) → signature_mismatch", C2["message"], chain_sign(C2["message"], app_key=RAW_APP_KEY), prevref(1),
   "signature_mismatch", basis="contrato", decision=None)
_alt = json.loads(C2["message"])
_alt["spec_hash"] = _alt["spec_hash"][:-1] + ("0" if _alt["spec_hash"][-1] != "0" else "1")
_alt_msg = canonical(_alt)
VV("un carácter de spec_hash cambiado con la firma original → signature_mismatch", _alt_msg, C2["signature"], prevref(1), "signature_mismatch",
   basis="contrato", decision=None)
_pretty = json.dumps(json.loads(C2["message"]), ensure_ascii=False, indent=1, sort_keys=True)
VV("bytes guardados no canónicos (con sangría) aunque la firma cuadre con ellos → not_canonical", _pretty, chain_sign(_pretty), prevref(1), "not_canonical",
   decision="DF-V4")
_unsorted = json.dumps(dict(reversed(list(json.loads(C2["message"]).items()))), ensure_ascii=False, separators=(",", ":"))
VV("claves en otro orden (sin espacios) aunque la firma cuadre → not_canonical", _unsorted, chain_sign(_unsorted), prevref(1), "not_canonical",
   decision="DF-V4")
VV("la firma en mayúsculas → signature_malformed", C2["message"], C2["signature"].upper(), prevref(1), "signature_malformed", decision="DF-V5")
VV("la firma de 63 caracteres → signature_malformed", C2["message"], C2["signature"][:63], prevref(1), "signature_malformed", decision="DF-V5")
VV("seq 2 detrás de una cabeza con seq 2 (no es cabeza + 1) → seq_gap", C2["message"], C2["signature"], {"seq": 2, "entryHash": CHAIN[0]["entryHash"]},
   "seq_gap", basis="contrato", decision="DF-V6", ref="D8-8 (seq = cabeza + 1), E1-4")
VV("seq 3 directamente detrás de seq 1 → seq_gap", CHAIN[2]["message"], CHAIN[2]["signature"], {"seq": 1, "entryHash": CHAIN[0]["entryHash"]},
   "seq_gap", basis="contrato", decision="DF-V6", ref="D8-8, E1-4")
VV("prev_hash que no es el de la entrada anterior → chain_broken", C2["message"], C2["signature"], {"seq": 1, "entryHash": sha256_hex("otra cabeza")},
   "chain_broken", basis="contrato", decision="DF-V6")
VV("prev_hash = la FIRMA de la anterior (no su huella) → chain_broken", canonical(dict(json.loads(C2["message"]), prev_hash=CHAIN[0]["signature"])),
   chain_sign(canonical(dict(json.loads(C2["message"]), prev_hash=CHAIN[0]["signature"]))), prevref(1), "chain_broken", decision="DF-V1")
_g = canonical(dict(CHAIN[0]["entry"], prev_hash=sha256_hex("no-hay-anterior")))
VV("la génesis sin los 64 ceros → chain_broken", _g, chain_sign(_g), None, "chain_broken", basis="contrato", decision="DF-V6")
_x = canonical(dict(CHAIN[1]["entry"], contract="ultron-voice-config/1"))
VV("una clave de más (contract) → malformed", _x, chain_sign(_x), prevref(1), "malformed", decision="DF-V6")
_m = canonical({k: v for k, v in CHAIN[1]["entry"].items() if k != "actor_id"})
VV("falta actor_id → malformed", _m, chain_sign(_m), prevref(1), "malformed", decision="DF-V6")
_ms = canonical(dict(CHAIN[1]["entry"], published_at="2026-11-05T15:00:00.123Z"))
VV("published_at con milisegundos → malformed", _ms, chain_sign(_ms), prevref(1), "malformed", decision="DF-V2")
_tz = canonical(dict(CHAIN[1]["entry"], published_at="2026-11-05T10:00:00-05:00"))
VV("published_at con desfase de Bogotá en vez de Z → malformed", _tz, chain_sign(_tz), prevref(1), "malformed", decision="DF-V2")
_sq = canonical(dict(CHAIN[1]["entry"], seq="2"))
VV("seq como texto → malformed", _sq, chain_sign(_sq), prevref(1), "malformed", decision="DF-V6")
_sc = canonical(dict(CHAIN[1]["entry"], scope="all"))
VV("scope «all» (fuera del conjunto) → malformed", _sc, chain_sign(_sc), prevref(1), "malformed", decision="DF-V3")
_off = canonical(dict(CHAIN[4]["entry"], provider_agent_id=AGENT_OLD))
VV("una entrada off con agente → malformed", _off, chain_sign(_off), prevref(4), "malformed", decision="DF-V3")
_can = canonical(dict(CHAIN[0]["entry"], spec_hash=None))
VV("una entrada canary sin spec_hash → malformed", _can, chain_sign(_can), None, "malformed", decision="DF-V3")
_z = canonical(dict(CHAIN[0]["entry"], seq=0))
VV("seq 0 (CHECK seq ≥ 1) → malformed", _z, chain_sign(_z), None, "malformed", basis="contrato", decision="DF-V6", ref="D8-8 (CHECK seq ≥ 1), E1-4")

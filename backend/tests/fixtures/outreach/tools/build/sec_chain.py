# strategy-chain-vectors.json · StrategyChainSignature (D9-11.3) — el «chain-vectors.json» del encargo, con el nombre del contrato (§11)
#   subkey · la subclave de la APP_KEY con la etiqueta ultron-studio-strategy-chain (la derivación del H5, copiada)
#   chain  · una cadena GLOBAL de 7 entradas de dos estrategias (publicar en canario, promover, pausar, reanudar, revertir, retirar):
#            mensaje canónico, huella (prev_hash de la siguiente) y firma
#   verify · el lector frente a mensajes y firmas buenos y malos (mismos resultados que voice-chain-vectors.json del H8)
CHAIN_LABEL = "ultron-studio-strategy-chain"
H5_LABEL = "ultron-studio-publication"
VOICE_LABEL = "ultron-studio-voice-chain"
CHAIN_CONTRACT = "ultron-strategy-config/1"
CHAIN_KEYS = ["actedByPerson", "action", "approvedByPerson", "contract", "issuedAt", "prevHash", "seq", "state", "strategy"]
ACTIONS = ["pause", "promote", "publish", "resume", "retire", "rollback"]
STATUSES = ["active", "off", "paused"]
ZERO = "0" * 64


def chain_subkey(label=CHAIN_LABEL, app_key=APP_KEY_B64):
    return subkey(label, app_key)


def chain_sign(message, label=CHAIN_LABEL, app_key=APP_KEY_B64):
    return hmac.new(chain_subkey(label, app_key), message.encode(), hashlib.sha256).hexdigest()


SC = Fixture("strategy-chain-vectors.json", header(
    "strategy-chain-vectors.json",
    ["D9-11.3 (cadena ultron_studio_strategy_publications: seq global = cabeza + 1, prev_hash = sha256 del mensaje canónico anterior, CHECK seq ≥ 1, solo inserción; mensaje firmado = JSON canónico con las claves ordenadas; HMAC-SHA256 con la subclave de la APP_KEY de etiqueta ultron-studio-strategy-chain, distinta de ultron-studio-publication y de ultron-studio-voice-chain; una sola implementación en Outreach\\Core\\StrategyChainSignature)",
     "D9-11.0 (publicar, promover, pausar, reanudar, revertir)", "§6.1 (action ∈ publish, promote, pause, resume, rollback, retire)", "§13.2 T1 (una entrada manipulada → chain_unreadable)"],
    [SOURCE_CONTRACT,
     {"what": "La derivación de la subclave y la firma del H5 y la cadena de voz del H8 (el patrón que D9-11.3 copia)",
      "url": "backend/app/Services/Marketing/Ultron/UltronPublishedConfig.php (subkey, signatureFor) y backend/tests/fixtures/voice/voice-chain-vectors.json (E1-4/E2-7 del H8)",
      "consulted": "09-11-2026, en este árbol",
      "how": "k = HMAC-SHA256(clave = APP_KEY cruda con base64: decodificado, mensaje = etiqueta); firma = hex(HMAC-SHA256(k, mensaje)) en minúscula; el mensaje es el JSON canónico tal cual, sin prefijo de contrato (como la cadena de voz, E2-7)."},
     SOURCE_HASHER],
    namingNote="El encargo de TEST9-a lo llama chain-vectors.json; el contrato (§11) lo llama strategy-chain-vectors.json: se entrega con el nombre del contrato (L9-8).",
    keys={"appKeyBase64": APP_KEY_B64, "appKeyRaw": RAW_APP_KEY, "chainLabel": CHAIN_LABEL, "h5Label": H5_LABEL, "voiceLabel": VOICE_LABEL,
          "chainSubkeyHex": chain_subkey().hex(), "chainSubkeyHexFromRawAppKey": chain_subkey(app_key=RAW_APP_KEY).hex(),
          "h5SubkeyHex": chain_subkey(H5_LABEL).hex(), "voiceSubkeyHex": chain_subkey(VOICE_LABEL).hex()},
    algorithm={
        "subkey": "k = HMAC-SHA256(clave = APP_KEY cruda (base64: decodificado), mensaje = 'ultron-studio-strategy-chain'), 32 bytes",
        "message": "JSON canónico (StudioContentHasher::canonicalJson) de las 9 claves de D9-11.3, ni una más ni una menos; el ejemplo del contrato las escribe en otro orden para leerlo, pero canónico = ordenadas por bytes (actedByPerson va antes que action)",
        "entryHash": "sha256 hex del mensaje; es el prevHash de la entrada siguiente (de cualquier estrategia: la cadena es global)",
        "signature": "hex(HMAC-SHA256(k, mensaje)): 64 hexadecimales en minúscula, sin prefijo",
        "genesis": "la primera entrada (seq = 1) lleva prevHash = 64 ceros",
    },
    closedSets={"keys": CHAIN_KEYS, "actions": ACTIONS, "statuses": STATUSES, "purposes": PURPOSES,
                "verifyResults": ["ok", "malformed", "not_canonical", "signature_malformed", "signature_mismatch", "seq_gap", "chain_broken"]},
    decisions={
        "DF-V1": "prevHash = entryHash de la entrada anterior de la cadena GLOBAL = sha256 de su mensaje canónico (no su firma), como E2-7 del H8.",
        "DF-V2": "issuedAt en UTC y al segundo («Y-m-d\\TH:i:s\\Z»): sin fracciones ni otro desfase.",
        "DF-V3": "(Ratificada por el lead: L9-23.) state = {canary: {contentHash, version} | null, rest: {contentHash, version} | null, status: active | paused | off}; status off exige canary y rest null; publish exige canary; approvedByPerson es la identidad que aprobó la versión en publish y promote, y null en pause, resume, rollback y retire (no hay aprobación); actedByPerson nunca es null.",
        "DF-V4": "El lector exige que los bytes guardados SEAN el JSON canónico de su contenido (recanonicaliza y compara); si no, not_canonical aunque la firma cuadre.",
        "DF-V5": "La firma son 64 hex en minúscula; otra forma es signature_malformed.",
        "DF-V6": "Orden del lector: forma (malformed) → canónico (not_canonical) → forma de la firma → firma (signature_mismatch) → seq = anterior + 1 (seq_gap) → prevHash = entryHash anterior (chain_broken). El primer fallo deniega; OutreachPublishedStrategies lo traduce a chain_unreadable (N5) y nunca lanza (D9-11.3).",
    },
))

P_AUTHOR = "1d000000-0000-4000-8000-000000000001"     # identidades sintéticas (identities.uuid)
P_APPROVER = "1d000000-0000-4000-8000-000000000002"
P_PUBLISHER = "1d000000-0000-4000-8000-000000000003"
SA = {"key": "renovacion-7d", "purpose": "commercial", "uuid": "5a000000-0000-4000-8000-000000000001"}
SB = {"key": "cobranza-8-30", "purpose": "collections", "uuid": "5b000000-0000-4000-8000-000000000002"}
VA1 = {"contentHash": sha256_hex("h9fixture-strategy-a-v1"), "version": "6a000000-0000-4000-8000-000000000001"}
VA2 = {"contentHash": sha256_hex("h9fixture-strategy-a-v2"), "version": "6a000000-0000-4000-8000-000000000002"}
VB1 = {"contentHash": sha256_hex("h9fixture-strategy-b-v1"), "version": "6b000000-0000-4000-8000-000000000001"}


def entry(seq, prev, action, strategy, canary, rest, status, at, acted=P_PUBLISHER, approved=P_APPROVER):
    return {"actedByPerson": acted, "action": action, "approvedByPerson": approved, "contract": CHAIN_CONTRACT, "issuedAt": at, "prevHash": prev,
            "seq": seq, "state": {"canary": canary, "rest": rest, "status": status}, "strategy": strategy}


PLAN = [
    ("publicar en canario la v1 de A (génesis: prevHash = 64 ceros)", dict(action="publish", strategy=SA, canary=VA1, rest=None, status="active",
                                                                          at="2026-11-14T15:04:05Z"), "D9-11.0 (canario), D9-11.3"),
    ("publicar en canario la v1 de B (otra estrategia: la cadena es global)", dict(action="publish", strategy=SB, canary=VB1, rest=None, status="active",
                                                                                  at="2026-11-14T15:30:00Z"), "D9-11.3 (seq global)"),
    ("promover la v1 de A a rest", dict(action="promote", strategy=SA, canary=None, rest=VA1, status="active", at="2026-11-16T14:00:00Z"), "D9-11.0 (promoción)"),
    ("publicar en canario la v2 de A con la v1 en rest", dict(action="publish", strategy=SA, canary=VA2, rest=VA1, status="active", at="2026-11-17T14:00:00Z"),
     "D9-11.0"),
    ("pausar A (sin aprobación)", dict(action="pause", strategy=SA, canary=VA2, rest=VA1, status="paused", at="2026-11-17T16:00:00Z", approved=None, acted=P_AUTHOR),
     "D9-11.0 (pausa, siempre)"),
    ("revertir la v2 de A: vuelve la v1 en rest", dict(action="rollback", strategy=SA, canary=None, rest=VA1, status="active", at="2026-11-17T16:10:00Z",
                                                      approved=None, acted=P_AUTHOR), "D9-11.0 (reversión), #25"),
    ("retirar B: apagada", dict(action="retire", strategy=SB, canary=None, rest=None, status="off", at="2026-11-18T13:00:00Z", approved=None), "§6.1 (retire)"),
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
    SC.add("chain", "entry", f"seq {i} · {desc}", ref, "contrato" if kw["action"] in ("publish", "promote") else "decision-fixture", {"entry": e},
           {"message": msg, "entryHash": h, "signature": sig, "prevHashIsPreviousEntryHash": True}, decision="DF-V1" if kw["action"] in ("publish", "promote") else "DF-V3")
    prev = h
assert CHAIN[0]["message"].startswith('{"actedByPerson":"' + P_PUBLISHER + '","action":"publish"') and '"prevHash":"' + ZERO + '"' in CHAIN[0]["message"]

SC.add("subkey", "derive", "subclave de la cadena de estrategias con la APP_KEY base64: (bytes 00..1f)", "D9-11.3", "contrato",
       {"appKey": APP_KEY_B64, "label": CHAIN_LABEL}, {"subkeyHex": chain_subkey().hex()})
SC.add("subkey", "derive", "con una APP_KEY sin base64: (se usa tal cual, como Laravel)", "D9-11.3", "contrato", {"appKey": RAW_APP_KEY, "label": CHAIN_LABEL},
       {"subkeyHex": chain_subkey(app_key=RAW_APP_KEY).hex()})
SC.add("subkey", "derive", "la del H5 (ultron-studio-publication) es otra", "D9-11.3 («una firma no vale en otro dominio»)", "contrato",
       {"appKey": APP_KEY_B64, "label": H5_LABEL}, {"subkeyHex": chain_subkey(H5_LABEL).hex(), "differsFromChain": True})
SC.add("subkey", "derive", "la de la voz del H8 (ultron-studio-voice-chain) es otra", "D9-11.3", "contrato", {"appKey": APP_KEY_B64, "label": VOICE_LABEL},
       {"subkeyHex": chain_subkey(VOICE_LABEL).hex(), "differsFromChain": True})
assert len({chain_subkey(), chain_subkey(H5_LABEL), chain_subkey(VOICE_LABEL)}) == 3


def prevref(i):
    return None if i == 0 else {"seq": CHAIN[i - 1]["entry"]["seq"], "entryHash": CHAIN[i - 1]["entryHash"]}


_UUIDRX = re.compile(r"^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$")
_HEXRX = re.compile(r"^[0-9a-f]{64}$")


def _version_ref_ok(v):
    return v is None or (isinstance(v, dict) and sorted(v) == ["contentHash", "version"] and isinstance(v["contentHash"], str)
                         and _HEXRX.match(v["contentHash"]) and isinstance(v["version"], str) and _UUIDRX.match(v["version"]))


def verify_ref(message, signature, previous, app_key=APP_KEY_B64):
    """El lector de referencia (DF-V6), solo para contrastar los literales de `verify`."""
    try:
        o = json.loads(message)
    except ValueError:
        return "malformed"
    if not isinstance(o, dict) or sorted(o) != sorted(CHAIN_KEYS):
        return "malformed"
    st = o["state"]
    ok = (isinstance(o["actedByPerson"], str) and _UUIDRX.match(o["actedByPerson"]) and o["action"] in ACTIONS and o["contract"] == CHAIN_CONTRACT
          and isinstance(o["issuedAt"], str) and re.fullmatch(r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z", o["issuedAt"])
          and isinstance(o["prevHash"], str) and _HEXRX.match(o["prevHash"]) and isinstance(o["seq"], int) and not isinstance(o["seq"], bool) and o["seq"] >= 1
          and isinstance(st, dict) and sorted(st) == ["canary", "rest", "status"] and st["status"] in STATUSES
          and _version_ref_ok(st["canary"]) and _version_ref_ok(st["rest"])
          and isinstance(o["strategy"], dict) and sorted(o["strategy"]) == ["key", "purpose", "uuid"]
          and isinstance(o["strategy"]["key"], str) and re.fullmatch(r"[a-z0-9][a-z0-9_-]{2,47}", o["strategy"]["key"])
          and o["strategy"]["purpose"] in PURPOSES and isinstance(o["strategy"]["uuid"], str) and _UUIDRX.match(o["strategy"]["uuid"]))
    if not ok:
        return "malformed"
    if st["status"] == "off" and (st["canary"] is not None or st["rest"] is not None):
        return "malformed"
    if o["action"] == "publish" and st["canary"] is None:
        return "malformed"
    if o["action"] in ("publish", "promote"):
        if not (isinstance(o["approvedByPerson"], str) and _UUIDRX.match(o["approvedByPerson"])):
            return "malformed"
    elif o["approvedByPerson"] is not None:
        return "malformed"
    if canonical(o) != message:
        return "not_canonical"
    if not (isinstance(signature, str) and _HEXRX.match(signature)):
        return "signature_malformed"
    if not hmac.compare_digest(chain_sign(message, app_key=app_key), signature):
        return "signature_mismatch"
    if previous is None:
        if o["seq"] != 1:
            return "seq_gap"
        return "ok" if o["prevHash"] == ZERO else "chain_broken"
    if o["seq"] != previous["seq"] + 1:
        return "seq_gap"
    return "ok" if o["prevHash"] == previous["entryHash"] else "chain_broken"


def VV(desc, message, signature, previous, result, *, basis="decision-fixture", decision="DF-V6", ref="D9-11.3", app_key=APP_KEY_B64):
    assert verify_ref(message, signature, previous, app_key) == result, (desc, verify_ref(message, signature, previous, app_key), result)
    fields = {"message": message, "signature": signature, "previous": previous}
    if app_key != APP_KEY_B64:
        fields["appKey"] = app_key
    SC.add("verify", "read", desc, ref, basis, fields, {"result": result, "denies": result != "ok", "n5Reason": None if result == "ok" else "chain_unreadable"},
           decision=decision)


for i, c in enumerate(CHAIN):
    VV(f"la entrada seq {i + 1} verifica", c["message"], c["signature"], prevref(i), "ok", basis="contrato", decision=None)
C3 = CHAIN[2]
VV("firmada con la subclave del H5 → signature_mismatch", C3["message"], chain_sign(C3["message"], H5_LABEL), prevref(2), "signature_mismatch", basis="contrato",
   decision=None, ref="D9-11.3 («una firma no vale en otro dominio»)")
VV("firmada con la subclave de la voz del H8 → signature_mismatch", C3["message"], chain_sign(C3["message"], VOICE_LABEL), prevref(2), "signature_mismatch",
   basis="contrato", decision=None)
VV("firmada con el prefijo de contrato del H5 («ultron-strategy-config/1\\n» + mensaje) → signature_mismatch", C3["message"],
   hmac.new(chain_subkey(), (CHAIN_CONTRACT + "\n" + C3["message"]).encode(), hashlib.sha256).hexdigest(), prevref(2), "signature_mismatch", decision="DF-V6")
VV("firmada con otra APP_KEY (rotada) → signature_mismatch", C3["message"], chain_sign(C3["message"], app_key=RAW_APP_KEY), prevref(2), "signature_mismatch",
   basis="contrato", decision=None)
_alt = json.loads(C3["message"])
_alt["state"]["rest"]["contentHash"] = _alt["state"]["rest"]["contentHash"][:-1] + ("0" if _alt["state"]["rest"]["contentHash"][-1] != "0" else "1")
VV("un carácter del contentHash cambiado con la firma original (UPDATE crudo, T1) → signature_mismatch", canonical(_alt), C3["signature"], prevref(2),
   "signature_mismatch", basis="contrato", decision=None, ref="T1 («una entrada manipulada»)")
_pretty = json.dumps(json.loads(C3["message"]), ensure_ascii=False, indent=1, sort_keys=True)
VV("bytes con sangría aunque la firma cuadre con ellos → not_canonical", _pretty, chain_sign(_pretty), prevref(2), "not_canonical", decision="DF-V4")
_unsorted = json.dumps(dict(reversed(list(json.loads(C3["message"]).items()))), ensure_ascii=False, separators=(",", ":"))
VV("claves en otro orden (como el ejemplo del contrato) aunque la firma cuadre → not_canonical", _unsorted, chain_sign(_unsorted), prevref(2), "not_canonical",
   decision="DF-V4")
VV("la firma en mayúsculas → signature_malformed", C3["message"], C3["signature"].upper(), prevref(2), "signature_malformed", decision="DF-V5")
VV("la firma de 63 caracteres → signature_malformed", C3["message"], C3["signature"][:63], prevref(2), "signature_malformed", decision="DF-V5")
VV("seq 3 detrás de una cabeza con seq 3 → seq_gap", C3["message"], C3["signature"], {"seq": 3, "entryHash": CHAIN[1]["entryHash"]}, "seq_gap", basis="contrato",
   decision="DF-V6", ref="D9-11.3 (cabeza + 1)")
VV("seq 4 directamente detrás de seq 2 → seq_gap", CHAIN[3]["message"], CHAIN[3]["signature"], prevref(2), "seq_gap", basis="contrato", decision="DF-V6")
VV("prevHash que no es el de la anterior → chain_broken", C3["message"], C3["signature"], {"seq": 2, "entryHash": sha256_hex("otra cabeza")}, "chain_broken",
   basis="contrato", decision="DF-V6")
_ps = canonical(dict(json.loads(C3["message"]), prevHash=CHAIN[1]["signature"]))
VV("prevHash = la FIRMA de la anterior (no su huella) → chain_broken", _ps, chain_sign(_ps), prevref(2), "chain_broken", decision="DF-V1")
_ps2 = canonical(dict(json.loads(C3["message"]), prevHash=CHAIN[0]["entryHash"]))
VV("prevHash = la huella de la última de la MISMA estrategia y no de la anterior global → chain_broken", _ps2, chain_sign(_ps2), prevref(2), "chain_broken",
   decision="DF-V1", ref="D9-11.3 (seq global)")
_g = canonical(dict(CHAIN[0]["entry"], prevHash=sha256_hex("no-hay-anterior")))
VV("la génesis sin los 64 ceros → chain_broken", _g, chain_sign(_g), None, "chain_broken", basis="contrato", decision="DF-V6")


def bad(i, **changes):
    e = copy.deepcopy(CHAIN[i]["entry"])
    for k, v in changes.items():
        if v is DEL:
            del e[k]
        else:
            e[k] = v
    m = canonical(e)
    return m, chain_sign(m)


for desc, (m, s_), dec in [
    ("una clave de más (reason) → malformed", bad(2, reason="x"), "DF-V6"),
    ("falta actedByPerson → malformed", bad(2, actedByPerson=DEL), "DF-V6"),
    ("contract de otra versión → malformed", bad(2, contract="ultron-strategy-config/2"), "DF-V6"),
    ("issuedAt con milisegundos → malformed", bad(2, issuedAt="2026-11-16T14:00:00.123Z"), "DF-V2"),
    ("issuedAt con desfase de Bogotá en vez de Z → malformed", bad(2, issuedAt="2026-11-16T09:00:00-05:00"), "DF-V2"),
    ("seq como texto → malformed", bad(2, seq="3"), "DF-V6"),
    ("action desconocida (archive no es de la cadena) → malformed", bad(2, action="archive"), "DF-V3"),
    ("status desconocido → malformed", bad(2, state={"canary": None, "rest": VA1, "status": "live"}), "DF-V3"),
    ("off con una versión en rest → malformed", bad(6, state={"canary": None, "rest": VB1, "status": "off"}), "DF-V3"),
    ("publish sin canary → malformed", bad(0, state={"canary": None, "rest": None, "status": "active"}), "DF-V3"),
    ("publish sin approvedByPerson → malformed", bad(0, approvedByPerson=None), "DF-V3"),
    ("finalidad inventada en strategy → malformed", bad(2, strategy=dict(SA, purpose="marketing")), "DF-V3"),
]:
    i = 0 if "publish sin" in desc else (6 if desc.startswith("off") else 2)
    VV(desc, m, s_, prevref(i), "malformed", decision=dec)
_z = canonical(dict(CHAIN[0]["entry"], seq=0))
VV("seq 0 (CHECK seq ≥ 1) → malformed", _z, chain_sign(_z), None, "malformed", basis="contrato", decision="DF-V6", ref="D9-11.3 (CHECK seq ≥ 1)")

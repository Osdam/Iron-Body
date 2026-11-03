# elevenlabs-webhook-vectors.json · ElevenLabs-Signature del webhook post-llamada (D8-16: ElevenLabsWebhookSignature::verify
# sin cambios, 1 800 s hacia atrás y 300 hacia delante, sobre el cuerpo crudo). Cuerpos de elevenlabs-webhook-bodies.json.
# El SDK oficial no está instalado en esta máquina y no hay red: los vectores salen del algoritmo DOCUMENTADO y llevan
# sdkVerified = false («por confirmar con el SDK»). El comprobador tiene el modo --sdk-python para rehacerlos con el SDK.
EV = Fixture("elevenlabs-webhook-vectors.json", header(
    "elevenlabs-webhook-vectors.json",
    ["D8-16 (POST /api/webhooks/voice/elevenlabs/post-call: ElevenLabs-Signature con ElevenLabsWebhookSignature::verify sin cambios; firma mala → 401)",
     "D8-3 (VoiceEventVerifier::verifyPostCall envuelve ElevenLabsWebhookSignature y ElevenLabsWebhookEnvelope sin cambiarlas)",
     "D8-5 (tres secretos distintos: post-llamada, herramientas e iniciación)"],
    [
        {"what": "Firma del webhook post-llamada: cabecera ElevenLabs-Signature, comprobada con constructEvent del SDK",
         "url": "https://elevenlabs.io/docs/agents-platform/workflows/post-call-webhooks", "consulted": "03-11-2026 (auditoría §d.1)",
         "how": "La página de hoy NO detalla el algoritmo ni la tolerancia (auditoría §d.1). El algoritmo de estos vectores es el que el H6 fijó en ElevenLabsWebhookSignature (investigación del 17-10-2026, ULTRON_STUDIO_ELEVENLABS_READY.md) y el del ejemplo de autenticación HMAC que publicaba esa página."},
        {"what": "SDK oficial (constructEvent / construct_event)", "url": "https://github.com/elevenlabs/elevenlabs-python (y @elevenlabs/elevenlabs-js)",
         "consulted": "NO consultado: sin red y sin copia local (pip, npm y cachés revisados el 03-11-2026)",
         "how": "sdkVerified = false en todos los casos. Ver sdkVerification para el comando."},
    ],
    keys={"postCallSecret": EL_POSTCALL_SECRET, "otherEnvironmentSecret": EL_POSTCALL_OTHER, "toolBearer": EL_TOOL_BEARER,
          "initiationValue": EL_INITIATION_VALUE},
    now=NOW, nowIso=utc_iso(2026, 11, 4, 15, 0, 0),
    algorithm={
        "header": "ElevenLabs-Signature: t=<segundos unix>,v0=<64 hex>",
        "signature": "v0 = hex(HMAC-SHA256(secreto del webhook, t + '.' + cuerpo crudo)), con t tal como viene en la cabecera",
        "h6Verify": "ElevenLabsWebhookSignature::verify (H6, sin cambios): partes recortadas, un t de 1-12 dígitos, uno o más v0 de 64 hex (comparados en minúsculas, vale cualquiera), t < now − 1800 → expired, t > now + 300 → from_future",
        "sdkDocumented": "referencia del ejemplo documentado: primer t= y primer v0= sin recortar; rechaza si t < now − 1800; acepta si 'v0=' + hex en minúsculas coincide; no mira el futuro. Es la expectativa de sdkConstructEvent, por confirmar con el SDK",
    },
    sdkVerified=False,
    sdkVerification={
        "status": "por confirmar con el SDK",
        "install": "en una máquina con red y fuera del repo: python3 -m venv /tmp/el-sdk && /tmp/el-sdk/bin/pip install 'elevenlabs==<versión fijada por SEC8>'",
        "run": "ya sin red: python3 -I tests/fixtures/voice/tools/voice-fixtures-check.py --sdk-python /tmp/el-sdk/bin/python",
        "what": "el comprobador ejecuta ElevenLabs(api_key='x').webhooks.construct_event(cuerpo, cabecera, secreto) con time.time congelado en now y compara con sdkConstructEvent",
    },
    decisions={
        "DF-E16": "Divergencias conocidas entre ElevenLabsWebhookSignature (H6) y el ejemplo documentado: v0 en mayúsculas, espacios alrededor de las partes, varios v0 y el futuro. Manda el H6 (D8-16 «sin cambios»); sdkConstructEvent solo informa.",
    },
))

T_OK = NOW - 60
DOC_TOLERANCE = 1800


def sdk_documented(raw_body, hdr, secret, now):
    """La referencia del ejemplo documentado (no el H6): primer t= y primer v0=, sin recortar; solo el pasado."""
    if not hdr or not secret:
        return "reject"
    parts = hdr.split(",")
    ts = next((p[2:] for p in parts if p.startswith("t=")), None)
    sig = next((p for p in parts if p.startswith("v0=")), None)
    if ts is None or sig is None:
        return "reject"
    try:
        if int(ts) * 1000 < now * 1000 - DOC_TOLERANCE * 1000:
            return "reject"
    except ValueError:
        return "reject"
    digest = "v0=" + hmac.new(secret.encode(), f"{ts}.{raw_body}".encode(), hashlib.sha256).hexdigest()
    return "accept" if hmac.compare_digest(sig, digest) else "reject"


HTTP_OF = {"ok": 200, "missing_signature": 401, "malformed_signature": 401, "expired": 401, "from_future": 401, "mismatch": 401}


def S(desc, body_key, hdr, result, *, secret=EL_POSTCALL_SECRET, now=NOW, body=None, basis="contrato", decision=None, ref="D8-16",
      envelope=None, why=None):
    rb = body if body is not None else BODIES[body_key]
    fields = {"bodyKey": body_key, "rawBody": rb, "header": hdr, "secret": secret, "now": now}
    exp = {"signatureResult": result, "http": HTTP_OF[result], "processed": result == "ok"}
    if result == "ok":
        exp["envelopeType"] = envelope
    exp["sdkConstructEvent"] = sdk_documented(rb, hdr, secret, now)
    exp["sdkVerified"] = False
    if why:
        exp["why"] = why
    if result != "ok" and decision is None and basis == "contrato":
        basis, decision = "decision-fixture", "DF-E1"
    EV.add("verify", "postCall", desc, ref, basis, fields, exp, decision=decision)


def hdr_for(key, t=T_OK, secret=EL_POSTCALL_SECRET, body=None):
    rb = body if body is not None else BODIES[key]
    return f"t={t},v0={el_sig(str(t), rb, secret)}"


# válidos con cada cuerpo
S("válida · transcripción de una saliente", "pc_completed", hdr_for("pc_completed"), "ok", envelope="post_call_transcription")
S("válida · otra serialización (\\u00f1 y espacios): se firma el crudo tal cual", "pc_inbound", hdr_for("pc_inbound"), "ok",
  envelope="post_call_transcription")
S("válida · post_call_audio (se verifica y después se descarta)", "pc_audio", hdr_for("pc_audio"), "ok", envelope="post_call_audio")
S("válida · call_initiation_failure busy", "pc_fail_busy", hdr_for("pc_fail_busy"), "ok", envelope="call_initiation_failure")
S("válida · tipo desconocido (200 y se ignora)", "pc_unknown_type", hdr_for("pc_unknown_type"), "ok", envelope=None)
S("válida · JSON roto con la firma buena (200 y se ignora)", "pc_broken_json", hdr_for("pc_broken_json"), "ok", envelope=None)
# tiempo
for d, res, label in [(1800, "ok", "borde: t = now − 1800 todavía vale"), (1801, "expired", "caducada: t = now − 1801"),
                      (86400, "expired", "caducada hace un día (un reintento tardío que conserve t: Q-9)")]:
    t = NOW - d
    S(label, "pc_completed", hdr_for("pc_completed", t=t), res, envelope="post_call_transcription" if res == "ok" else None)
S("borde del futuro: t = now + 300 todavía vale", "pc_completed", hdr_for("pc_completed", t=NOW + 300), "ok", envelope="post_call_transcription")
S("del futuro: t = now + 301 (el ejemplo documentado lo aceptaría: DF-E16)", "pc_completed", hdr_for("pc_completed", t=NOW + 301),
  "from_future", basis="decision-fixture", decision="DF-E16")
S("caducada y con la firma mala: manda el tiempo", "pc_completed", f"t={NOW - 1801},v0={'0' * 64}", "expired")
# cuerpo alterado
_rb = BODIES["pc_completed"]
assert '"call_duration_secs":187' in _rb
S("cuerpo alterado: la duración 187 → 18 (un carácter menos)", "pc_completed", hdr_for("pc_completed"), "mismatch",
  body=_rb.replace('"call_duration_secs":187', '"call_duration_secs":18'))
S("cuerpo alterado: el agent_id cambiado por el de otra publicación", "pc_completed", hdr_for("pc_completed"), "mismatch",
  body=_rb.replace(AGENT_CANARY, AGENT_OLD))
S("cuerpo vuelto a serializar con sangría: mismo JSON, otros bytes", "pc_completed", hdr_for("pc_completed"), "mismatch",
  body=json.dumps(json.loads(_rb), ensure_ascii=False, indent=2))
S("cuerpo con un salto de línea final que no se firmó", "pc_completed", hdr_for("pc_completed"), "mismatch", body=_rb + "\n")
_nfd = unicodedata.normalize("NFD", _rb)
assert _nfd != _rb
S("cuerpo en NFD (la misma tilde, otros bytes UTF-8)", "pc_completed", hdr_for("pc_completed"), "mismatch", body=_nfd)
# secreto
S("secreto equivocado: el de otro entorno", "pc_completed", hdr_for("pc_completed", secret=EL_POSTCALL_OTHER), "mismatch")
S("secreto equivocado: firmado con el de las herramientas (D8-5)", "pc_completed", hdr_for("pc_completed", secret=EL_TOOL_BEARER), "mismatch",
  ref="D8-5, D8-16")
S("secreto equivocado: firmado con el de iniciación (D8-5)", "pc_completed", hdr_for("pc_completed", secret=EL_INITIATION_VALUE), "mismatch",
  ref="D8-5, D8-16")
_good = el_sig(str(T_OK), _rb, EL_POSTCALL_SECRET)
_old = el_sig(str(T_OK), _rb, EL_POSTCALL_OTHER)
S("rotación: dos v0, el primero del secreto viejo y el segundo del vigente (el ejemplo documentado solo mira el primero)", "pc_completed",
  f"t={T_OK},v0={_old},v0={_good}", "ok", envelope="post_call_transcription", basis="decision-fixture", decision="DF-E16")
# forma de la cabecera (el detalle del analizador ya está en integrations-cases.json § elevenlabsSignature, 52 casos)
S("sin cabecera", "pc_completed", None, "missing_signature")
S("cabecera sin t", "pc_completed", f"v0={_good}", "malformed_signature")
S("v0 en mayúsculas: el H6 compara en minúsculas (el ejemplo documentado no)", "pc_completed", f"t={T_OK},v0={_good.upper()}", "ok",
  envelope="post_call_transcription", basis="decision-fixture", decision="DF-E16")
S("espacio tras la coma: el H6 recorta las partes (el ejemplo documentado no)", "pc_completed", f"t={T_OK}, v0={_good}", "ok",
  envelope="post_call_transcription", basis="decision-fixture", decision="DF-E16")
S("firma calculada sin el prefijo «t.»", "pc_completed", f"t={T_OK},v0={hmac.new(EL_POSTCALL_SECRET.encode(), _rb.encode(), hashlib.sha256).hexdigest()}",
  "mismatch")
S("firma calculada con SHA-1 rellenada a 64 hex", "pc_completed",
  f"t={T_OK},v0={hmac.new(EL_POSTCALL_SECRET.encode(), f'{T_OK}.{_rb}'.encode(), hashlib.sha1).hexdigest().ljust(64, '0')}", "mismatch")

# voiceDoctor · ElevenLabsConfig: status() / baseUrl() y el ELEVENLABS_VERIFIED del Studio (contrato §I6.1–I6.3)
# voiceVerify · ElevenLabsVoiceProvider::verifyCredentials(): respuesta → outcome (+ snapshot.outcome de §I5.3)
# voiceCallPolicy · VoiceCallPolicy::decide() y los niveles de la ruta 45 (§I6.5, §I6.6)
NC, DIS, UNV, VER = "ELEVENLABS_NOT_CONFIGURED", "ELEVENLABS_DISABLED", "ELEVENLABS_UNVERIFIED", "ELEVENLABS_VERIFIED"
KEY = "sk_h6fixture_elevenlabs_000000000000000001"
AGENT = "agent_h6fixture_0001"
HOOK = "wsec_h6fixture_webhook_000000000000000001"
A64 = "a" * 64
REG_DEFAULT, REG_EU = "https://api.elevenlabs.io", "https://api.eu.residency.elevenlabs.io"
MOCK = "http://127.0.0.1:8096"


def vcfg(enabled=False, key=KEY, agent=AGENT, hook=HOOK, region="default", **extra):
    c = {"voice.elevenlabs.enabled": enabled, "voice.elevenlabs.api_key": key, "voice.elevenlabs.agent_id": agent,
         "voice.elevenlabs.webhook_secret": hook, "voice.elevenlabs.region": region}
    c.update(extra)
    return c


def VS(desc, config, provider, state, enabled, missing, region):
    add("voiceDoctor", "status", desc, {"config": config},
        {"provider": provider, "state": state, "enabled": enabled, "missing": missing, "region": region})


VS("producción en H6: nada configurado", vcfg(key=None, agent=None, hook=None), "none", NC, False, ["api_key", "agent_id", "webhook_secret"], "default")
VS("piezas completas y bandera apagada", vcfg(), "none", DIS, False, [], "default")
VS("piezas completas y bandera encendida", vcfg(enabled=True), "elevenlabs", UNV, True, [], "default")
VS("bandera encendida sin la clave", vcfg(enabled=True, key=None), "none", NC, True, ["api_key"], "default")
VS("clave en blanco", vcfg(key="   "), "none", NC, False, ["api_key"], "default")
VS("agent_id con un espacio", vcfg(agent="agent h6"), "none", NC, False, ["agent_id"], "default")
VS("agent_id de 64 caracteres: vale", vcfg(agent=A64), "none", DIS, False, [], "default")
VS("agent_id de 65 caracteres", vcfg(agent=A64 + "a"), "none", NC, False, ["agent_id"], "default")
VS("agent_id con un salto de línea final ($ de PCRE sin D lo dejaría pasar)", vcfg(agent=AGENT + "\n"), "none", NC, False, ["agent_id"], "default")
VS("agent_id con tilde", vcfg(agent="agént_h6"), "none", NC, False, ["agent_id"], "default")
VS("agent_id vacío", vcfg(agent=""), "none", NC, False, ["agent_id"], "default")
VS("secreto del webhook vacío", vcfg(hook=""), "none", NC, False, ["webhook_secret"], "default")
VS("región eu", vcfg(region="eu"), "none", DIS, False, [], "eu")
VS("región us: no está en la lista cerrada", vcfg(region="us"), "none", NC, False, ["region"], None)
VS("región EU: se normaliza con strtolower(trim()) (Enmienda 1, E1.2)", vcfg(region="EU"), "none", DIS, False, [], "eu")
VS("región « eu » con espacios: se normaliza", vcfg(region=" eu "), "none", DIS, False, [], "eu")
VS("región nula: cuenta como default (E1.2)", vcfg(region=None), "none", DIS, False, [], "default")
VS("región vacía: falta", vcfg(region=""), "none", NC, False, ["region"], None)
VS("bandera encendida y región no válida", vcfg(enabled=True, region="us"), "none", NC, True, ["region"], None)
VS("todo mal: el orden es api_key, agent_id, webhook_secret, region", vcfg(key="", agent="bad agent", hook=None, region="xx"),
   "none", NC, False, ["api_key", "agent_id", "webhook_secret", "region"], None)
VS("tool_secret y agent_phone_number_id no se exigen", vcfg(**{"voice.elevenlabs.tool_secret": None, "voice.elevenlabs.agent_phone_number_id": None}),
   "none", DIS, False, [], "default")
VS("encendida, completa y en eu", vcfg(enabled=True, region="eu"), "elevenlabs", UNV, True, [], "eu")


def VU(desc, env, region, base, url):
    add("voiceDoctor", "baseUrl", desc, {"env": env, "config": {"voice.elevenlabs.region": region, "voice.elevenlabs.base_url": base}},
        {"baseUrl": url})


VU("producción ignora base_url", "production", "eu", MOCK, REG_EU)
VU("producción sin base_url", "production", "default", None, REG_DEFAULT)
VU("fuera de producción, base_url manda (el simulador del E2E)", "testing", "default", MOCK, MOCK)
VU("fuera de producción sin base_url: la de la región", "testing", "eu", None, REG_EU)
VU("fuera de producción con región no válida: null aunque haya base_url", "testing", "us", MOCK, None)
VU("producción con región no válida", "production", "us", None, None)
VU("producción con la región en mayúsculas: se normaliza", "production", "EU", None, REG_EU)
VU("región nula: default", "testing", None, None, REG_DEFAULT)


def VW(desc, state, probes, now, exp_state):
    add("voiceDoctor", "studioState", desc, {"providerState": state, "voiceProbes": probes, "now": now}, {"state": exp_state})


NOWV = "2026-11-01T15:00:00+00:00"
VW("sin verificar y la última verificación ok de hace 29 días", UNV, [{"outcome": "ok", "at": "2026-10-03T15:00:00+00:00"}], NOWV, VER)
VW("ok de hace exactamente 30 días: ya no vale", UNV, [{"outcome": "ok", "at": "2026-10-02T15:00:00+00:00"}], NOWV, UNV)
VW("ok de hace 30 días menos un segundo", UNV, [{"outcome": "ok", "at": "2026-10-02T15:00:01+00:00"}], NOWV, VER)
VW("sin ninguna verificación", UNV, [], NOWV, UNV)
VW("la última falló aunque otra anterior fue ok: cuenta la última", UNV,
   [{"outcome": "ok", "at": "2026-10-31T15:00:00+00:00"}, {"outcome": "auth_failed", "at": "2026-11-01T14:00:00+00:00"}], NOWV, UNV)
VW("la última es degraded", UNV, [{"outcome": "degraded", "at": "2026-11-01T14:00:00+00:00"}], NOWV, UNV)
VW("bandera apagada con un ok reciente: sigue DISABLED", DIS, [{"outcome": "ok", "at": "2026-10-31T15:00:00+00:00"}], NOWV, DIS)
VW("sin configurar con un ok reciente: sigue NOT_CONFIGURED", NC, [{"outcome": "ok", "at": "2026-10-31T15:00:00+00:00"}], NOWV, NC)


def VV(desc, response, outcome, status, ulaw, snap):
    add("voiceVerify", "verify", desc, {"response": response},
        {"outcome": outcome, "httpStatus": status, "agentAudioUlaw8000": ulaw, "snapshotOutcome": snap})


def body(tts=None, asr=None, extra=None):
    cc = {}
    if tts is not None:
        cc["tts"] = {"agent_output_audio_format": tts}
    if asr is not None:
        cc["asr"] = {"user_input_audio_format": asr}
    d = {"agent_id": AGENT, "name": "Agente de pruebas"}
    if cc:
        d["conversation_config"] = cc
    if extra:
        d.update(extra)
    return json.dumps(d)


VV("200 con los dos formatos en ulaw_8000", {"status": 200, "rawBody": body("ulaw_8000", "ulaw_8000")}, "credentials_ok", 200, True, "ok")
VV("200 con el de entrada en pcm_16000", {"status": 200, "rawBody": body("ulaw_8000", "pcm_16000")}, "credentials_ok", 200, False, "ok")
VV("200 sin conversation_config", {"status": 200, "rawBody": body()}, "credentials_ok", 200, None, "ok")
VV("200 con solo uno de los dos formatos", {"status": 200, "rawBody": body("ulaw_8000")}, "credentials_ok", 200, None, "ok")
VV("200 con un cuerpo que no es JSON: error (E1.2)", {"status": 200, "rawBody": "<html>ok</html>"}, "error", 200, None, "error")
VV("200 con una lista JSON: no es un objeto, error", {"status": 200, "rawBody": "[]"}, "error", 200, None, "error")
VV("200 con JSON null", {"status": 200, "rawBody": "null"}, "error", 200, None, "error")
VV("302 (sin seguir redirecciones)", {"status": 302, "rawBody": ""}, "error", 302, None, "error")
VV("401", {"status": 401, "rawBody": '{"detail":"invalid_api_key"}'}, "invalid_credentials", 401, None, "auth_failed")
VV("403", {"status": 403, "rawBody": '{"detail":"forbidden"}'}, "invalid_credentials", 403, None, "auth_failed")
VV("404", {"status": 404, "rawBody": '{"detail":"agent_not_found"}'}, "agent_not_found", 404, None, "degraded")
VV("500", {"status": 500, "rawBody": "error"}, "error", 500, None, "error")
VV("429", {"status": 429, "rawBody": '{"detail":"rate_limited"}'}, "error", 429, None, "error")
VV("sin conexión", {"connectionError": "unreachable"}, "unreachable", None, None, "unreachable")
VV("tiempo agotado", {"connectionError": "timeout"}, "unreachable", None, None, "unreachable")

# ── VoiceCallPolicy ──
CDIC = "calls_disabled_in_code"


def ctx(tv=True, frm=True, ps=VER, canary="active_voice", source="canary_registry", cap=5):
    return {"twilioVoiceEnabled": tv, "twilioVoiceFromConfigured": frm, "providerState": ps, "canary": canary, "source": source, "dailyCap": cap}


def VP(desc, c, reasons, integration):
    levels = [{"id": "text_simulation", "available": True, "cost": "none", "where": "laboratorio"},
              {"id": "integration_test", "available": False, "reasons": integration},
              {"id": "real_call", "available": False, "reasons": reasons}]
    add("voiceCallPolicy", "decide", desc, {"context": c}, {"allowed": False, "reasons": reasons, "levels": levels})


VP("todo listo salvo la constante del código", ctx(), [CDIC], [CDIC])
VP("producción en H6 (el ejemplo del contrato)", ctx(False, False, NC, "not_registered", "canary_registry", 0),
   [CDIC, "twilio_voice_disabled", "no_voice_number", "elevenlabs_not_configured", "not_an_active_voice_canary", "daily_cap_zero"],
   [CDIC, "twilio_voice_disabled", "no_voice_number", "elevenlabs_not_configured"])
VP("Twilio Voice apagado", ctx(tv=False), [CDIC, "twilio_voice_disabled"], [CDIC, "twilio_voice_disabled"])
VP("sin número de voz", ctx(frm=False), [CDIC, "no_voice_number"], [CDIC, "no_voice_number"])
VP("ElevenLabs sin configurar", ctx(ps=NC), [CDIC, "elevenlabs_not_configured"], [CDIC, "elevenlabs_not_configured"])
VP("ElevenLabs apagado", ctx(ps=DIS), [CDIC, "elevenlabs_disabled"], [CDIC, "elevenlabs_disabled"])
VP("ElevenLabs sin verificar", ctx(ps=UNV), [CDIC, "elevenlabs_unverified"], [CDIC, "elevenlabs_unverified"])
VP("ElevenLabs verificado no añade nada", ctx(ps=VER), [CDIC], [CDIC])
VP("el canario de voz no está activo", ctx(canary="not_active"), [CDIC, "not_an_active_voice_canary"], [CDIC])
VP("no hay canario de voz registrado", ctx(canary="not_registered"), [CDIC, "not_an_active_voice_canary"], [CDIC])
VP("la fuente es marketing_calls (T-7)", ctx(source="marketing_calls"), [CDIC, "source_not_allowed"], [CDIC, "source_not_allowed"])
VP("otra fuente", ctx(source="other"), [CDIC, "source_not_allowed"], [CDIC, "source_not_allowed"])
VP("cupo diario 0", ctx(cap=0), [CDIC, "daily_cap_zero"], [CDIC])
VP("cupo diario negativo", ctx(cap=-1), [CDIC, "daily_cap_zero"], [CDIC])
VP("cupo diario 1", ctx(cap=1), [CDIC], [CDIC])
VP("todo falla a la vez: se acumulan en el orden de REASONS", ctx(False, False, DIS, "not_active", "marketing_calls", 0),
   [CDIC, "twilio_voice_disabled", "no_voice_number", "elevenlabs_disabled", "not_an_active_voice_canary", "source_not_allowed", "daily_cap_zero"],
   [CDIC, "twilio_voice_disabled", "no_voice_number", "elevenlabs_disabled", "source_not_allowed"])
VP("marketing_calls con todo lo demás listo y el cupo máximo", ctx(source="marketing_calls", cap=20), [CDIC, "source_not_allowed"], [CDIC, "source_not_allowed"])
VP("apagado y sin número aunque ElevenLabs esté verificado", ctx(tv=False, frm=False), [CDIC, "twilio_voice_disabled", "no_voice_number"],
   [CDIC, "twilio_voice_disabled", "no_voice_number"])


def VC(desc, voice_rows, whatsapp_rows, canary):
    add("voiceCallPolicy", "context", desc, {"voiceRows": voice_rows, "whatsappRows": whatsapp_rows}, {"canary": canary})


VC("sin filas de voz", [], [], "not_registered")
VC("una de voz pendiente", ["pending"], [], "not_active")
VC("una de voz autorizada y otra revocada", ["authorized", "revoked"], [], "not_active")
VC("una de voz activa", ["revoked", "active"], [], "active_voice")
VC("solo revocadas de voz", ["revoked", "revoked"], [], "not_registered")
VC("un WhatsApp activo no cuenta para la voz", [], ["active"], "not_registered")

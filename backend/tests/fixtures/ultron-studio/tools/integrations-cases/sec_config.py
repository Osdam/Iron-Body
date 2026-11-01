# configFacts · facts de kind=config por proveedor, su JSON canónico y su huella (contrato §I5.3, Enmienda 1 Q-1)
# configDrift · estado y deriva de GET integrations/config (§I5.3, ruta 46)
# `config` va con las claves de config() tal cual las lee la clase (el arnés: config($case['config'])).
# fp(valor) = substr(HMAC-SHA256(kCfg, valor), 0, 8); kCfg = HMAC-SHA256(APP_KEY cruda, 'ultron-studio-config-fingerprint/1').
# Importes: texto con dos decimales (Enmienda 1, Q-1).
E = "…"   # … de los ref
TW_SID = "AC" + "0123456789abcdef0123456789abcdef"
TW_SID2 = "AC" + "fedcba9876543210fedcba9876543210"
TW_TOKEN = "h6fixturetwiliotoken000000000001"
TW_TOKEN2 = "h6fixturetwiliotoken000000000002"
VERIFY_SID = "VA" + "00000000000000000000000000009f0e"
FROM = "+15005550006"
KEY_SID = "SK" + "11111111111111111111111111111111"
KEY_SECRET = "h6fixtureprobekeysecret000000001"
META_TOKEN = "EAAh6fixturemetatoken000000000000000001"
ACCOUNTS = ["1000000000004714", "1000000000009023", "1000000000005856"]
EL_API_KEY = "sk_h6fixture_elevenlabs_000000000000000001"
EL_AGENT = "agent_h6fixture_0001"
EL_WEBHOOK = "wsec_h6fixture_webhook_000000000000000001"
EL_TOOL = "tsec_h6fixture_tool_000000000000000001"
EL_PHONE_ID = "phnum_h6fixture_0001"
VOICE_FROM = "+576010000099"


def CF(desc, provider, config, facts, must_not, app_key=B64KEY):
    cj = canonical(facts)
    for s in must_not:
        assert s not in cj, (desc, s)
    add("configFacts", provider, desc, {"appKey": app_key, "config": config},
        {"facts": facts, "canonicalJson": cj, "fingerprint": sha256_hex(cj), "mustNotContain": must_not})


def aliases(sid_source, token_source, sid_consistent, token_consistent):
    return {"sid_source": sid_source, "token_source": token_source, "sid_consistent": sid_consistent, "token_consistent": token_consistent}


def twilio_config(**kw):
    c = {
        "otp.driver": "twilio",
        "otp.twilio.sid": TW_SID, "otp.twilio.token": TW_TOKEN, "otp.twilio.verify_service_sid": VERIFY_SID,
        "otp.twilio.from": FROM, "otp.twilio.base": "https://api.twilio.com", "otp.twilio.verify_base": "https://verify.twilio.com",
        "otp.twilio.rate_limit_unique_name": "ironbody-otp-fixture",
        "otp.send_enabled": True, "otp.legacy_sms_sender_enabled": False,
        "otp.cost.daily_soft": 6.0, "otp.cost.daily_hard": 12.0,
        "ultron_studio.integrations.twilio_aliases": aliases("TWILIO_SID", "TWILIO_TOKEN", True, True),
        "ultron_studio.integrations.twilio_probe.enabled": False,
        "ultron_studio.integrations.twilio_probe.key_sid": None,
        "ultron_studio.integrations.twilio_probe.key_secret": None,
    }
    c.update(kw)
    return c


TWILIO_PROD_FACTS = {
    "otpDriver": "twilio",
    "accountSid": {"present": True, "source": "TWILIO_SID", "ref": "AC" + E + "cdef"},
    "authToken": {"present": True, "source": "TWILIO_TOKEN", "fp": cfg_fp(TW_TOKEN, B64KEY)},
    "aliasesConsistent": {"sid": True, "token": True},
    "verifyServiceSid": {"present": True, "ref": "VA" + E + "9f0e"},
    "from": {"present": True, "last4": "0006"},
    "apiBase": {"official": True}, "verifyBase": {"official": True},
    "rateLimitName": {"present": True},
    "sendEnabled": True, "legacySenderEnabled": False, "costSoftUsd": "6.00", "costHardUsd": "12.00",
    "probe": {"enabled": False, "credential": "otp"},
}
TW_SECRETS = [TW_SID, TW_TOKEN, VERIFY_SID, FROM, "ironbody-otp-fixture"]

CF("producción al congelar: los dos alias, Verify, remitente, hosts oficiales y sonda apagada", "twilio",
   twilio_config(), TWILIO_PROD_FACTS, TW_SECRETS)

CF("solo los nombres de la consola, hosts hostiles y la sonda con la llave restringida", "twilio",
   twilio_config(**{
       "otp.twilio.sid": TW_SID2, "otp.twilio.token": TW_TOKEN2, "otp.twilio.verify_service_sid": None, "otp.twilio.from": None,
       "otp.twilio.base": "https://api.twilio.com.evil.test", "otp.twilio.verify_base": "https://verify.twilio.com@evil.test/v2",
       "otp.twilio.rate_limit_unique_name": "", "otp.cost.daily_soft": 7.5, "otp.cost.daily_hard": 15.25,
       "ultron_studio.integrations.twilio_aliases": aliases("TWILIO_ACCOUNT_SID", "TWILIO_AUTH_TOKEN", None, None),
       "ultron_studio.integrations.twilio_probe.enabled": True,
       "ultron_studio.integrations.twilio_probe.key_sid": KEY_SID, "ultron_studio.integrations.twilio_probe.key_secret": KEY_SECRET}),
   {"otpDriver": "twilio",
    "accountSid": {"present": True, "source": "TWILIO_ACCOUNT_SID", "ref": "AC" + E + "3210"},
    "authToken": {"present": True, "source": "TWILIO_AUTH_TOKEN", "fp": cfg_fp(TW_TOKEN2, B64KEY)},
    "aliasesConsistent": {"sid": None, "token": None},
    "verifyServiceSid": {"present": False},
    "from": {"present": False},
    "apiBase": {"official": False, "host": "api.twilio.com.evil.test"}, "verifyBase": {"official": False, "host": "evil.test"},
    "rateLimitName": {"present": False},
    "sendEnabled": True, "legacySenderEnabled": False, "costSoftUsd": "7.50", "costHardUsd": "15.25",
    "probe": {"enabled": True, "credential": "restricted_key"}},
   [TW_SID2, TW_TOKEN2, KEY_SID, KEY_SECRET, "https://"])

CF("alias que no coinciden, driver dev, sin token y la llave de la sonda a medias", "twilio",
   twilio_config(**{
       "otp.driver": "dev", "otp.twilio.token": None, "otp.twilio.rate_limit_unique_name": "x",
       "otp.send_enabled": False, "otp.legacy_sms_sender_enabled": True,
       "ultron_studio.integrations.twilio_aliases": aliases("TWILIO_SID", None, False, None),
       "ultron_studio.integrations.twilio_probe.key_sid": KEY_SID}),
   {"otpDriver": "dev",
    "accountSid": {"present": True, "source": "TWILIO_SID", "ref": "AC" + E + "cdef"},
    "authToken": {"present": False},
    "aliasesConsistent": {"sid": False, "token": None},
    "verifyServiceSid": {"present": True, "ref": "VA" + E + "9f0e"},
    "from": {"present": True, "last4": "0006"},
    "apiBase": {"official": True}, "verifyBase": {"official": True},
    "rateLimitName": {"present": True},
    "sendEnabled": False, "legacySenderEnabled": True, "costSoftUsd": "6.00", "costHardUsd": "12.00",
    "probe": {"enabled": False, "credential": "otp"}},
   [TW_SID, VERIFY_SID, FROM, KEY_SID])

CF("valores en blanco: no están (filled)", "twilio",
   twilio_config(**{
       "otp.twilio.sid": "", "otp.twilio.token": "   ", "otp.twilio.verify_service_sid": "", "otp.twilio.from": "",
       "otp.twilio.rate_limit_unique_name": "  ",
       "ultron_studio.integrations.twilio_aliases": aliases(None, None, None, None),
       "ultron_studio.integrations.twilio_probe.enabled": True,
       "ultron_studio.integrations.twilio_probe.key_sid": "   ", "ultron_studio.integrations.twilio_probe.key_secret": KEY_SECRET}),
   {"otpDriver": "twilio",
    "accountSid": {"present": False},
    "authToken": {"present": False},
    "aliasesConsistent": {"sid": None, "token": None},
    "verifyServiceSid": {"present": False},
    "from": {"present": False},
    "apiBase": {"official": True}, "verifyBase": {"official": True},
    "rateLimitName": {"present": False},
    "sendEnabled": True, "legacySenderEnabled": False, "costSoftUsd": "6.00", "costHardUsd": "12.00",
    "probe": {"enabled": True, "credential": "otp"}},
   [KEY_SECRET])


def meta_config(**kw):
    c = {"meta.ads.access_token": META_TOKEN, "meta.ads.ad_account_ids": ACCOUNTS, "meta.ads.graph_version": "v26.0",
         "meta.ads.sync_enabled": True, "meta.ads.sync_days": 7, "meta.ads.stale_after_minutes": 180,
         "meta.ads.timezone": "America/Bogota"}
    c.update(kw)
    return c


CF("producción al congelar: token, 3 cuentas y sincronización encendida", "meta_ads", meta_config(),
   {"token": {"present": True, "fp": cfg_fp(META_TOKEN, B64KEY)},
    "accounts": [{"ref": "***4714"}, {"ref": "***9023"}, {"ref": "***5856"}],
    "graphVersion": "v26.0", "syncEnabled": True, "syncDays": 7, "staleMinutes": 180, "timezone": "America/Bogota"},
   [META_TOKEN] + ACCOUNTS)
CF("sin token, sin cuentas y sincronización apagada", "meta_ads",
   meta_config(**{"meta.ads.access_token": None, "meta.ads.ad_account_ids": [], "meta.ads.graph_version": "v21.0",
                  "meta.ads.sync_enabled": False, "meta.ads.sync_days": 1, "meta.ads.stale_after_minutes": 1}),
   {"token": {"present": False}, "accounts": [], "graphVersion": "v21.0", "syncEnabled": False, "syncDays": 1, "staleMinutes": 1,
    "timezone": "America/Bogota"},
   [META_TOKEN])
CF("el mismo token con otra APP_KEY da otra huella", "meta_ads",
   meta_config(**{"meta.ads.ad_account_ids": [ACCOUNTS[0]]}),
   {"token": {"present": True, "fp": cfg_fp(META_TOKEN, RAWKEY)}, "accounts": [{"ref": "***4714"}],
    "graphVersion": "v26.0", "syncEnabled": True, "syncDays": 7, "staleMinutes": 180, "timezone": "America/Bogota"},
   [META_TOKEN, ACCOUNTS[0]], app_key=RAWKEY)


def voice_config(**kw):
    c = {"voice.elevenlabs.enabled": False, "voice.elevenlabs.api_key": None, "voice.elevenlabs.agent_id": None,
         "voice.elevenlabs.webhook_secret": None, "voice.elevenlabs.region": "default",
         "voice.elevenlabs.agent_phone_number_id": None, "voice.elevenlabs.tool_secret": None,
         "voice.twilio.enabled": False, "voice.twilio.from": None, "voice.twilio.daily_call_cap": 0}
    c.update(kw)
    return c


VOICE_PROD_FACTS = {
    "elevenlabs": {"enabled": False, "apiKey": {"present": False}, "agentId": {"present": False}, "webhookSecret": {"present": False},
                   "region": "default", "toolSecret": {"present": False}, "phoneNumberId": {"present": False}},
    "twilioVoice": {"enabled": False, "from": {"present": False}, "dailyCallCap": 0}}
CF("producción en H6: nada de ElevenLabs ni de Twilio Voice", "voice", voice_config(), VOICE_PROD_FACTS, [])
CF("todo presente: las huellas de los secretos, el ref del agente y los 4 dígitos del número", "voice",
   voice_config(**{"voice.elevenlabs.enabled": True, "voice.elevenlabs.api_key": EL_API_KEY, "voice.elevenlabs.agent_id": EL_AGENT,
                   "voice.elevenlabs.webhook_secret": EL_WEBHOOK, "voice.elevenlabs.region": "eu",
                   "voice.elevenlabs.agent_phone_number_id": EL_PHONE_ID, "voice.elevenlabs.tool_secret": EL_TOOL,
                   "voice.twilio.enabled": True, "voice.twilio.from": VOICE_FROM, "voice.twilio.daily_call_cap": 5}),
   {"elevenlabs": {"enabled": True, "apiKey": {"present": True, "fp": cfg_fp(EL_API_KEY, B64KEY)},
                   "agentId": {"present": True, "ref": "0001"},
                   "webhookSecret": {"present": True, "fp": cfg_fp(EL_WEBHOOK, B64KEY)},
                   "region": "eu", "toolSecret": {"present": True, "fp": cfg_fp(EL_TOOL, B64KEY)},
                   "phoneNumberId": {"present": True}},
    "twilioVoice": {"enabled": True, "from": {"present": True, "last4": "0099"}, "dailyCallCap": 5}},
   [EL_API_KEY, EL_AGENT, EL_WEBHOOK, EL_TOOL, EL_PHONE_ID, VOICE_FROM])
CF("las huellas de voz son del secreto recortado (E1.2): las mismas que sin espacios", "voice",
   voice_config(**{"voice.elevenlabs.api_key": "  " + EL_API_KEY + "  ", "voice.elevenlabs.agent_id": EL_AGENT,
                   "voice.elevenlabs.webhook_secret": EL_WEBHOOK + "\n", "voice.elevenlabs.tool_secret": "\t" + EL_TOOL}),
   {"elevenlabs": {"enabled": False, "apiKey": {"present": True, "fp": cfg_fp(EL_API_KEY, B64KEY)},
                   "agentId": {"present": True, "ref": "0001"},
                   "webhookSecret": {"present": True, "fp": cfg_fp(EL_WEBHOOK, B64KEY)},
                   "region": "default", "toolSecret": {"present": True, "fp": cfg_fp(EL_TOOL, B64KEY)},
                   "phoneNumberId": {"present": False}},
    "twilioVoice": {"enabled": False, "from": {"present": False}, "dailyCallCap": 0}},
   [EL_API_KEY, EL_AGENT, EL_WEBHOOK, EL_TOOL])
CF("región no válida va tal cual, una clave en blanco no está y un agente mal formado sí está", "voice",
   voice_config(**{"voice.elevenlabs.api_key": "   ", "voice.elevenlabs.agent_id": "agent with space",
                   "voice.elevenlabs.webhook_secret": EL_WEBHOOK, "voice.elevenlabs.region": "us"}),
   {"elevenlabs": {"enabled": False, "apiKey": {"present": False}, "agentId": {"present": True, "ref": "pace"},
                   "webhookSecret": {"present": True, "fp": cfg_fp(EL_WEBHOOK, B64KEY)},
                   "region": "us", "toolSecret": {"present": False}, "phoneNumberId": {"present": False}},
    "twilioVoice": {"enabled": False, "from": {"present": False}, "dailyCallCap": 0}},
   [EL_WEBHOOK, "agent with space"])


# ── configDrift ──
def fp_of(facts):
    return sha256_hex(canonical(facts))


def DR(desc, provider, authorized_facts, effective, state, drift):
    auth = None if authorized_facts is None else {"facts": authorized_facts, "fingerprint": fp_of(authorized_facts)}
    add("configDrift", provider, desc, {"authorized": auth, "effective": effective},
        {"state": state, "effectiveFingerprint": fp_of(effective), "drift": drift})


def with_(base, **kw):
    d = json.loads(json.dumps(base))
    d.update(kw)
    return d


DR("sin reconocimiento", "twilio", None, TWILIO_PROD_FACTS, "never_authorized", [])
DR("reconocida y sin cambios", "twilio", TWILIO_PROD_FACTS, TWILIO_PROD_FACTS, "authorized", [])
_v2 = with_(TWILIO_PROD_FACTS, verifyServiceSid={"present": True, "ref": "VA" + E + "1a2b"})
DR("cambió el Verify Service SID", "twilio", TWILIO_PROD_FACTS, _v2, "drift",
   [{"key": "verifyServiceSid", "authorized": {"present": True, "ref": "VA" + E + "9f0e"}, "effective": {"present": True, "ref": "VA" + E + "1a2b"}}])
_v3 = with_(TWILIO_PROD_FACTS, authToken={"present": True, "source": "TWILIO_TOKEN", "fp": cfg_fp(TW_TOKEN2, B64KEY)},
            probe={"enabled": True, "credential": "otp"})
DR("cambiaron el token y la sonda: en el orden de las claves de facts", "twilio", TWILIO_PROD_FACTS, _v3, "drift",
   [{"key": "authToken", "authorized": TWILIO_PROD_FACTS["authToken"], "effective": _v3["authToken"]},
    {"key": "probe", "authorized": TWILIO_PROD_FACTS["probe"], "effective": _v3["probe"]}])
def _deep_reversed(o):
    if isinstance(o, dict):
        return {k: _deep_reversed(o[k]) for k in reversed(list(o))}
    if isinstance(o, list):
        return [_deep_reversed(x) for x in o]
    return o


_reord = _deep_reversed(TWILIO_PROD_FACTS)   # como lo devolvería un jsonb de PostgreSQL: otro orden en todos los niveles
assert list(_reord["accountSid"]) == ["ref", "source", "present"]
DR("lo reconocido con las claves en otro orden, en todos los niveles, es el mismo valor", "twilio", _reord, TWILIO_PROD_FACTS, "authorized", [])
_v4 = with_(TWILIO_PROD_FACTS, costSoftUsd="7.50")
DR("cambió el umbral blando de coste", "twilio", TWILIO_PROD_FACTS, _v4, "drift",
   [{"key": "costSoftUsd", "authorized": "6.00", "effective": "7.50"}])
_m1 = {"token": {"present": True, "fp": cfg_fp(META_TOKEN, B64KEY)},
       "accounts": [{"ref": "***4714"}, {"ref": "***9023"}, {"ref": "***5856"}],
       "graphVersion": "v26.0", "syncEnabled": True, "syncDays": 7, "staleMinutes": 180, "timezone": "America/Bogota"}
_m2 = with_(_m1, accounts=[{"ref": "***9023"}, {"ref": "***4714"}, {"ref": "***5856"}])
DR("las mismas cuentas en otro orden: el orden de una lista cuenta", "meta_ads", _m1, _m2, "drift",
   [{"key": "accounts", "authorized": _m1["accounts"], "effective": _m2["accounts"]}])
_vo2 = json.loads(json.dumps(VOICE_PROD_FACTS))
_vo2["elevenlabs"]["enabled"] = True
DR("voz: una clave anidada cambia toda su clave de primer nivel", "voice", VOICE_PROD_FACTS, _vo2, "drift",
   [{"key": "elevenlabs", "authorized": VOICE_PROD_FACTS["elevenlabs"], "effective": _vo2["elevenlabs"]}])

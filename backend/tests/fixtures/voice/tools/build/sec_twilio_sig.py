# twilio-signature-vectors.json · Telephony\TwilioRequestSignature (contrato D8-4, D8-16; auditoría §c.2 OTP-3, §d.2)
# Algoritmo publicado: base = URL exacta + (formulario) cada clave ordenada con sus valores ordenados y sin repetir;
# firma = base64(HMAC-SHA1(token, base)). Con cuerpo JSON, Twilio añade bodySHA256 = sha256 hex del cuerpo a la URL
# y la base es solo la URL. Fuente de los 6 vectores oficiales: twilio/twilio-python tests/unit/test_request_validator.py.
TW = Fixture("twilio-signature-vectors.json", header(
    "twilio-signature-vectors.json",
    ["D8-4 (TwilioRequestSignature: 6 vectores oficiales, bodySHA256, claves repetidas; URL = base canónica + URI tal como llega)",
     "D8-16 (usage-trigger de la subcuenta con X-Twilio-Signature)", "D8-5 (TWILIO_VOICE_SIGNING_TOKEN, VOICE_WEBHOOK_BASE_URL)",
     "Auditoría §c.2 OTP-2 y OTP-3, §d.2"],
    [
        {"what": "Algoritmo de X-Twilio-Signature (HMAC-SHA1, URL exacta, bodySHA256 con JSON, sin rangos de IP)",
         "url": "https://www.twilio.com/docs/usage/webhooks/webhooks-security", "consulted": "03-11-2026 (auditoría §d.2, leída por el architect)",
         "how": "No la abrí hoy (sin red): el algoritmo es el de la auditoría y el de los vectores oficiales."},
        {"what": "Los 6 vectores oficiales (formulario, JSON con bodySHA256, claves repetidas y tres de puerto) y la semántica de validate()",
         "url": "https://github.com/twilio/twilio-python/blob/main/tests/unit/test_request_validator.py",
         "consulted": "31-10-2026 (descargado por el tester del H6 como dato; sha256 del fichero 4522667e1aef9fcc1e598dad33f84315913feac4a7cc2cfe02b3f34ef5bc4509, informe-fixture.md §2.5)",
         "how": "Literales copiados del fixture del H6 (integrations-cases.json § twilioSignatureOfficial). El generador y el comprobador recalculan cada firma con HMAC-SHA1 y coinciden."},
    ],
    keys={"officialToken": TWILIO_OFFICIAL_TOKEN, "subaccountSigningToken": TWILIO_SIGNING_TOKEN,
          "otpMasterTokenSynthetic": TWILIO_OTHER_TOKEN, "voiceWebhookBaseUrl": VOICE_WEBHOOK_BASE_URL},
    algorithm={
        "form": "base = url + concat(clave + valor) con las claves en orden de bytes y, por clave, sus valores en orden de bytes y sin repetir; firma = base64(HMAC-SHA1(token, base))",
        "json": "la URL lleva bodySHA256 = sha256 hex (minúsculas) del cuerpo crudo; base = url (sin parámetros); además sha256(cuerpo) tiene que ser igual, byte a byte, al bodySHA256 de la consulta",
        "port": "como validate() del SDK oficial: vale la firma sobre la URL sin puerto o sobre la URL con el puerto por defecto añadido (443 https, 80 http) si no traía (DF-T2)",
        "url": "url = rtrim(VOICE_WEBHOOK_BASE_URL, '/') + la URI tal como llega (ruta y consulta crudas, sin decodificar, recodificar ni reordenar); nunca fullUrl() (DF-T1, T-1)",
    },
    decisions={
        "DF-T1": "URL = rtrim(base, '/') + requestUri crudo (getRequestUri()); el esquema y el host salen SOLO de la base.",
        "DF-T2": "Tolerancia de puerto como validate() del SDK oficial. Si el lead la descarta, vale expected.validWithoutPortTolerance.",
        "DF-T3": "Orden de bytes (strcmp / SORT_STRING) para claves y valores: '10' va antes que '9'. Las claves distinguen mayúsculas.",
        "DF-T4": "bodySHA256 se compara exacto con el sha256 hex en minúsculas del cuerpo crudo (como compare() del SDK).",
        "DF-T5": "Token de firma vacío = fallo de configuración: lanza InvalidArgumentException (el convenio de ElevenLabsWebhookSignature del H6), nunca 'válida'.",
    },
))

URI = "https://mycompany.com/myapp.php?foo=1&bar=2"
PARAMS = [["CallSid", "CA1234567890ABCDE"], ["Digits", "1234"], ["From", "+14158675309"], ["To", "+18005551212"], ["Caller", "+14158675309"]]
TW_BODY = '{"property": "value", "boolean": true}'
TW_BODY_HASH = "0a1ff7634d9ab3b95db5c9a2dfe9416e41502b283a80c7cf19632632f96e6620"
assert sha256_hex(TW_BODY) == TW_BODY_HASH
URI_BODY = URI + "&bodySHA256=" + TW_BODY_HASH
OFF_SRC = "twilio/twilio-python tests/unit/test_request_validator.py (main, 31-10-2026)"
SIG_CLASSIC = "RSOYDt4T1cUTdK1PDd93/VVr8B8="
SIG_JSON = "a9nBmqA0ju/hNViExpshrM61xv4="
SIG_DUP = "IK+Dwps556ElfBT0I3Rgjkr1wJU="
SIG_443 = "kvajT1Ptam85bY51eRf/AJRuM3w="
SIG_80 = "0ZXoZLH/DfblKGATFgpif+LLRf4="
SIG_1234 = "Zmvh+3yNM1Phv2jhDCwEM3q5ebU="
DUP = [["Sid", "CA123"], ["SidAccount", "AC123"], ["Digits", "5678"], ["Digits", "1234"], ["Digits", "1234"]]


def C(desc, url, pairs, sig, *, basis="oficial", decision=None, body=None, official=True, ref="D8-4"):
    calc = tw_sig(TWILIO_OFFICIAL_TOKEN if official else TWILIO_SIGNING_TOKEN, url if body is not None else tw_base(url, pairs))
    if sig is None:
        sig = calc
    assert calc == sig, (desc, calc, sig)
    tok = TWILIO_OFFICIAL_TOKEN if official else TWILIO_SIGNING_TOKEN
    fields = {"token": tok, "url": url, "params": pairs}
    exp = {"signature": sig}
    if body is not None:
        fields["rawBody"] = body
        exp["bodySHA256"] = sha256_hex(body)
    else:
        exp["base"] = tw_base(url, pairs)
    if official:
        fields["source"] = OFF_SRC
    TW.add("compute", "json" if body is not None else "form", desc, ref, basis, fields, exp, decision=decision)


# ── compute: los 6 oficiales ────────────────────────────────────────────────
C("oficial 1 · el vector clásico: URL con consulta y cinco parámetros", URI, PARAMS, SIG_CLASSIC)
C("oficial 2 · JSON: bodySHA256 en la URL y la base es solo la URL", URI_BODY, [], SIG_JSON, body=TW_BODY)
C("oficial 3 · claves repetidas: claves ordenadas, valores ordenados y sin repetir", URI, DUP, SIG_DUP)
C("oficial 4 · https con :443 en la URL", "https://mycompany.com:443/myapp.php?foo=1&bar=2", PARAMS, SIG_443)
C("oficial 5 · http con :80 en la URL", "http://mycompany.com:80/myapp.php?foo=1&bar=2", PARAMS, SIG_80)
C("oficial 6 · http con :1234 en la URL", "http://mycompany.com:1234/myapp.php?foo=1&bar=2", PARAMS, SIG_1234)

# ── compute: derivados del algoritmo (no oficiales), con el token sintético de la subcuenta ────────────────
USAGE_URL = VOICE_WEBHOOK_BASE_URL + "/api/webhooks/voice/twilio/usage-trigger"
USAGE_MIN = [["AccountSid", TWILIO_SUBACCOUNT_SID], ["UsageTriggerSid", "UT" + "1a" * 16], ["IdempotencyToken", "AC8f-UT1a-0001"]]
C("sin consulta: la URL de usage-trigger de la voz tal cual", USAGE_URL, USAGE_MIN, None, basis="oficial", official=False, ref="D8-4, D8-16")
C("consulta en el orden en que llega (?b=2&a=1): no se reordena", USAGE_URL + "?b=2&a=1", USAGE_MIN, None, basis="oficial", official=False)
C("consulta codificada tal cual (%20 y %2B): no se decodifica", USAGE_URL + "?q=a%20b%2Bc", USAGE_MIN, None, basis="oficial", official=False)
C("un valor vacío cuenta como la clave sola", USAGE_URL, USAGE_MIN + [["ForwardedFrom", ""]], None, basis="oficial", official=False)
C("valor con ñ y emoji: se firman los bytes UTF-8", USAGE_URL, USAGE_MIN + [["CallerName", "Peña Ñandú 🎉"]], None, basis="oficial", official=False)
C("valores numéricos repetidos: orden de bytes, '10' antes que '9'", USAGE_URL, USAGE_MIN + [["Digits", "9"], ["Digits", "10"]], None,
  basis="decision-fixture", decision="DF-T3", official=False)
C("claves que solo difieren en mayúsculas: orden de bytes (B, a, b)", USAGE_URL, [["b", "2"], ["B", "1"], ["a", "3"]], None,
  basis="decision-fixture", decision="DF-T3", official=False)
C("el + de un E.164 se firma como +, no como espacio ni %2B", USAGE_URL, USAGE_MIN + [["From", CANARY_A]], None, basis="oficial", official=False)
BODY_JSON_VOICE = '{"AccountSid":"%s","Kind":"ejemplo con ñ"}' % TWILIO_SUBACCOUNT_SID
C("JSON derivado: bodySHA256 de un cuerpo con ñ (bytes UTF-8)", USAGE_URL + "?bodySHA256=" + sha256_hex(BODY_JSON_VOICE), [], None,
  basis="oficial", official=False, body=BODY_JSON_VOICE)
# Comprobaciones a mano de la base (no se fían solo del generador):
assert tw_base(USAGE_URL, [["b", "2"], ["B", "1"], ["a", "3"]]) == USAGE_URL + "B1a3b2"
assert tw_base(URI, DUP) == URI + "Digits1234Digits5678SidCA123SidAccountAC123"
assert tw_base(USAGE_URL, [["Digits", "9"], ["Digits", "10"]]) == USAGE_URL + "Digits10Digits9"


# ── validate ────────────────────────────────────────────────────────────────
def V(desc, url, header_value, valid, *, pairs=None, body=None, token=TWILIO_OFFICIAL_TOKEN, basis="oficial", decision=None,
      ref="D8-4", why=None, no_port=None, throws=None):
    fields = {"token": token, "url": url, "header": header_value}
    if body is not None:
        fields["rawBody"] = body
    else:
        fields["params"] = pairs if pairs is not None else []
    if throws:
        exp = {"throws": throws}
    else:
        exp = {"valid": valid}
        if no_port is not None:
            exp["validWithoutPortTolerance"] = no_port
    if why:
        exp["why"] = why
    TW.add("validate", "json" if body is not None else "form", desc, ref, basis, fields, exp, decision=decision)


V("oficial: el clásico vale", URI, SIG_CLASSIC, True, pairs=PARAMS)
V("oficial: JSON con su bodySHA256 vale", URI_BODY, SIG_JSON, True, body=TW_BODY)
V("oficial: claves repetidas valen", URI, SIG_DUP, True, pairs=DUP)
V("oficial (validate): https sin puerto y la firma de :443 vale (añade el puerto)", URI, SIG_443, True, pairs=PARAMS,
  basis="decision-fixture", decision="DF-T2", no_port=False, why="el SDK prueba también la URL con :443")
V("oficial (validate): https con :1234 y la firma sin puerto vale (quita el puerto)", "https://mycompany.com:1234/myapp.php?foo=1&bar=2",
  SIG_CLASSIC, True, pairs=PARAMS, basis="decision-fixture", decision="DF-T2", no_port=False, why="el SDK prueba también la URL sin puerto")
V("oficial (validate): http sin puerto y la firma de :80 vale", "http://mycompany.com/myapp.php?foo=1&bar=2", SIG_80, True, pairs=PARAMS,
  basis="decision-fixture", decision="DF-T2", no_port=False)
V("oficial (validate): http con :1234 y la firma con :1234 vale", "http://mycompany.com:1234/myapp.php?foo=1&bar=2", SIG_1234, True,
  pairs=PARAMS, basis="decision-fixture", decision="DF-T2", no_port=True)
V("una letra cambiada en la firma", URI, SIG_CLASSIC[:-2] + "9=", False, pairs=PARAMS)
V("la firma sin el relleno '='", URI, SIG_CLASSIC.rstrip("="), False, pairs=PARAMS)
V("T-1: la firma oficial contra la URL en http (fullUrl() tras el proxy) no vale", "http://mycompany.com/myapp.php?foo=1&bar=2",
  SIG_CLASSIC, False, pairs=PARAMS, ref="D8-4 (T-1)", why="el esquema sale de la base canónica, nunca de la petición")
V("T-1: la consulta reordenada (?bar=2&foo=1, como fullUrl()) no vale", "https://mycompany.com/myapp.php?bar=2&foo=1",
  SIG_CLASSIC, False, pairs=PARAMS, ref="D8-4 (T-1, OTP-3)")
V("JSON: un carácter del cuerpo cambiado (la URL y la firma intactas)", URI_BODY, SIG_JSON, False, body=TW_BODY.replace("value", "valuE"),
  why="sha256 del cuerpo ≠ bodySHA256")
V("JSON: el mismo cuerpo con un salto de línea final que no se firmó", URI_BODY, SIG_JSON, False, body=TW_BODY + "\n")
_upper = URI + "&bodySHA256=" + TW_BODY_HASH.upper()
V("JSON: bodySHA256 en mayúsculas (firmada así): se compara exacto con el hex en minúsculas", _upper,
  tw_sig(TWILIO_OFFICIAL_TOKEN, _upper), False, body=TW_BODY, basis="decision-fixture", decision="DF-T4")
V("cabecera ausente", URI, None, False, pairs=PARAMS)
V("cabecera vacía", URI, "", False, pairs=PARAMS)
V("token vacío: fallo de configuración, nunca válida", URI, SIG_CLASSIC, None, pairs=PARAMS, token="", throws="InvalidArgumentException",
  basis="decision-fixture", decision="DF-T5")
V("un parámetro de más que no se firmó", URI, SIG_CLASSIC, False, pairs=PARAMS + [["CallStatus", "completed"]])
V("un parámetro de menos", URI, SIG_CLASSIC, False, pairs=PARAMS[:-1])
V("el + del From decodificado como espacio", URI, SIG_CLASSIC, False,
  pairs=[p if p[0] != "From" else ["From", " 14158675309"] for p in PARAMS], why="error típico al decodificar el formulario a mano")
_hdr256 = base64.b64encode(hmac.new(TWILIO_OFFICIAL_TOKEN.encode(), tw_base(URI, PARAMS).encode(), hashlib.sha256).digest()).decode()
V("firma calculada con HMAC-SHA256 en vez de SHA1", URI, _hdr256, False, pairs=PARAMS)
_usage_sig = tw_sig(TWILIO_SIGNING_TOKEN, tw_base(USAGE_URL, USAGE_MIN))
V("subcuenta: el aviso firmado con el token de la subcuenta vale", USAGE_URL, _usage_sig, True, pairs=USAGE_MIN,
  token=TWILIO_SIGNING_TOKEN, ref="D8-4, D8-5, D8-16")
V("OTP-2: el mismo aviso firmado con el token maestro del OTP no vale con el de la subcuenta", USAGE_URL,
  tw_sig(TWILIO_OTHER_TOKEN, tw_base(USAGE_URL, USAGE_MIN)), False, pairs=USAGE_MIN, token=TWILIO_SIGNING_TOKEN, ref="D8-4, auditoría OTP-2")
V("subcuenta: la URL con / final que Twilio no firmó", USAGE_URL + "/", _usage_sig, False, pairs=USAGE_MIN, token=TWILIO_SIGNING_TOKEN)
V("subcuenta: la URL en mayúsculas no es la firmada", USAGE_URL.replace("usage-trigger", "Usage-Trigger"), _usage_sig, False,
  pairs=USAGE_MIN, token=TWILIO_SIGNING_TOKEN)


# ── urlBuild: la URL con la que se calcula la firma (DF-T1) ─────────────────
def U(desc, base, request_uri, url, *, basis="contrato", decision=None, why=None):
    exp = {"url": url}
    if why:
        exp["why"] = why
    TW.add("urlBuild", "build", desc, "D8-4", basis, {"baseUrl": base, "requestUri": request_uri}, exp, decision=decision)


U("la base configurada + la URI tal como llega", VOICE_WEBHOOK_BASE_URL, "/api/webhooks/voice/twilio/usage-trigger", USAGE_URL)
U("la base con / final no dobla la barra", VOICE_WEBHOOK_BASE_URL + "/", "/api/webhooks/voice/twilio/usage-trigger", USAGE_URL,
  basis="decision-fixture", decision="DF-T1")
U("la consulta se conserva en su orden", VOICE_WEBHOOK_BASE_URL, "/api/webhooks/voice/twilio/usage-trigger?b=2&a=1", USAGE_URL + "?b=2&a=1")
U("la consulta se conserva codificada", VOICE_WEBHOOK_BASE_URL, "/api/webhooks/voice/twilio/usage-trigger?q=a%20b%2Bc", USAGE_URL + "?q=a%20b%2Bc")
U("tras el proxy la petición llega en http: el esquema sale de la base (https)", VOICE_WEBHOOK_BASE_URL,
  "/api/webhooks/voice/twilio/usage-trigger", USAGE_URL, why="nunca fullUrl(): sin trustProxies daría http:// (T-1)")

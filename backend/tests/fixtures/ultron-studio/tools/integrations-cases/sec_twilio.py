# twilioProbeSanitize · TwilioProbeSanitizer::sanitize($responses, $credential) (contrato §I5.4)
# twilioSignatureOfficial · vectores publicados por Twilio para X-Twilio-Signature (§I5.1, T-1/T-2)
# probeRules · ruta 56 (§I5.4)    acknowledgeRules · ruta 55 (§I5.3)
TW_SID = "AC" + "0123456789abcdef0123456789abcdef"
AUTH_IN_BODY = "h6fixtureauthtokenINBODY00000001"
HOOK_TOKEN = "h6fixtureHOOKTOKEN0001"
ALERT_TOKEN = "h6fixtureALERTTOKEN"
HOSTILE = ["+573000000021", "573000000021", "+573000000022", "573000000022", "+573000000023", "573000000023",
           "+573000000099", "573000000099", "evil.test", AUTH_IN_BODY, HOOK_TOKEN, ALERT_TOKEN, "(500) 555-0006", "+15005550006",
           "Iron Body", "Ana Prueba", "Gerencia", TW_SID, "Invalid parameter", "https://", "Channel=sms", "outbound-api", "<script>"]

ACCOUNT_BODY = {"sid": TW_SID, "friendly_name": "Iron Body +57 300 000 0021", "status": "active", "type": "Full",
                "auth_token": AUTH_IN_BODY, "owner_account_sid": TW_SID, "date_created": "Mon, 01 Jan 2024 00:00:00 +0000",
                "uri": f"/2010-04-01/Accounts/{TW_SID}.json", "subresource_uris": {"calls": f"/2010-04-01/Accounts/{TW_SID}/Calls.json"}}


def acct(status="active", typ="Full"):
    b = json.loads(json.dumps(ACCOUNT_BODY))
    b["status"], b["type"] = status, typ
    return b


def number(pn, caps, fn="(500) 555-0006"):
    n = {"sid": "PN" + "0" * 32, "phone_number": pn, "friendly_name": fn,
         "sms_url": f"https://hooks.evil.test/sms?token={HOOK_TOKEN}", "voice_url": ""}
    if caps is not None:
        n["capabilities"] = caps
    return n


def caps(voice, sms):
    return {"voice": voice, "sms": sms, "mms": False, "fax": False}


def numbers_body(items, next_uri=None):
    return {"incoming_phone_numbers": items, "page_size": 20, "next_page_uri": next_uri,
            "uri": f"/2010-04-01/Accounts/{TW_SID}/IncomingPhoneNumbers.json?PageSize=20"}


def caller_ids_body(items):
    return {"outgoing_caller_ids": items, "page_size": 20, "next_page_uri": None}


def caller_id(i):
    return {"sid": "PN" + "1" * 32, "phone_number": "+5730000000%02d" % (99 - i), "friendly_name": "Gerencia móvil +57 300 000 0099"}


def calls_body(items):
    return {"calls": items, "page_size": 50, "next_page_uri": None}


def call(status):
    return {"sid": "CA" + "2" * 32, "to": "+573000000023", "from": "+15005550006", "status": status,
            "direction": "outbound-api", "duration": "30", "price": "-0.01"}


def alerts_body(items):
    return {"alerts": items, "meta": {"page": 0, "page_size": 50, "key": "alerts", "next_page_url": None}}


def alert(code, level):
    return {"sid": "NO" + "3" * 32, "error_code": code, "log_level": level,
            "alert_text": f"Invalid parameter To: +573000000022 see https://evil.test/x?token={ALERT_TOKEN}",
            "request_url": "https://verify.twilio.com/v2/Services/VA00000000000000000000000000009f0e/Verifications",
            "request_variables": "To=%2B573000000022&Channel=sms", "response_body": '{"code":60200,"message":"<script>"}',
            "date_created": "2026-10-31T20:00:00Z"}


def ok(body):
    return {"ok": True, "status": 200, "body": body, "error": None}


def fail(status, error):
    return {"ok": False, "status": status, "body": None, "error": error}


SKIP = {"skipped": True}
GOOD = {"account": ok(acct()),
        "numbers": ok(numbers_body([number("+15005550006", caps(False, True))])),
        "caller_ids": ok(caller_ids_body([])),
        "calls": ok(calls_body([])),
        "alerts": ok(alerts_body([alert("60200", "warning"), alert("60200", "warning"), alert("60200", "error")]))}
F_ACCOUNT = {"status": "active", "type": "Full"}
F_NUMBERS = {"count": 1, "truncated": False, "voiceCapable": 0, "smsCapable": 1, "items": [{"last4": "0006", "voice": False, "sms": True}]}
F_CALLERS = {"count": 0, "truncated": False}
F_CALLS = {"total": 0, "truncated": False, "byStatus": []}     # mapa vacío: PHP lo escribe [] (ver README)
F_ALERTS = {"total": 3, "truncated": False, "byCode": {"60200": 3}, "byLevel": {"error": 1, "warning": 2}}


def facts(credential="otp", account=F_ACCOUNT, numbers=F_NUMBERS, callers=F_CALLERS, calls=F_CALLS, alerts=F_ALERTS, errors=None):
    return {"credential": credential, "account": account, "numbers": numbers, "callerIds": callers,
            "calls7d": calls, "alerts24h": alerts, "errors": errors or []}


def E(step, outcome, status):
    return {"step": step, "outcome": outcome, "httpStatus": status}


def TS(desc, responses, outcome, f, credential="otp"):
    cj = canonical(f)
    for s in HOSTILE:
        assert s not in cj, (desc, s)
    add("twilioProbeSanitize", "sanitize", desc, {"credential": credential, "responses": responses},
        {"outcome": outcome, "facts": f, "mustNotContain": HOSTILE})


def resp(**kw):
    r = dict(GOOD)
    r.update(kw)
    return r


SKIPPED_REST = [E("numbers", "skipped", None), E("caller_ids", "skipped", None), E("calls", "skipped", None), E("alerts", "skipped", None)]

TS("los cinco pasos bien (sin números ni textos del proveedor en facts)", resp(), "ok", facts())
TS("con la llave restringida", resp(), "ok", facts(credential="restricted_key"), credential="restricted_key")
TS("la cuenta da 401: se para y el resto queda sin comprobar", resp(account=fail(401, "auth_failed"), numbers=SKIP, caller_ids=SKIP, calls=SKIP, alerts=SKIP),
   "auth_failed", facts(account=None, numbers=None, callers=None, calls=None, alerts=None, errors=[E("account", "auth_failed", 401)] + SKIPPED_REST))
TS("la cuenta no responde: se para", resp(account=fail(None, "unreachable"), numbers=SKIP, caller_ids=SKIP, calls=SKIP, alerts=SKIP),
   "unreachable", facts(account=None, numbers=None, callers=None, calls=None, alerts=None, errors=[E("account", "unreachable", None)] + SKIPPED_REST))
TS("la cuenta da 500 y el resto sale bien: error", resp(account=fail(500, "error")), "error",
   facts(account=None, errors=[E("account", "error", 500)]))
TS("las alertas dan 403 (la llave no ve Monitor): degraded", resp(alerts=fail(403, "auth_failed")), "degraded",
   facts(alerts=None, errors=[E("alerts", "auth_failed", 403)]))
TS("las llamadas dan 500: degraded", resp(calls=fail(500, "error")), "degraded", facts(calls=None, errors=[E("calls", "error", 500)]))
TS("se agotan los 30 s antes de las alertas: skipped", resp(alerts=SKIP), "degraded", facts(alerts=None, errors=[E("alerts", "skipped", None)]))
TS("números hostiles: nombre con teléfono, capacidades que no son booleanos y sin capacidades",
   resp(numbers=ok(numbers_body([number("+573000000021", caps(True, False), fn="Ana Prueba +57 300 000 0021"),
                                  number("+15005550006", caps("true", "yes")),
                                  number("+576010000098", None)]))),
   "ok", facts(numbers={"count": 3, "truncated": False, "voiceCapable": 1, "smsCapable": 0,
                        "items": [{"last4": "0021", "voice": True, "sms": False}, {"last4": "0006", "voice": False, "sms": False},
                                  {"last4": "0098", "voice": False, "sms": False}]}))
_n20 = [number("+5760100000%02d" % i, caps(False, True)) for i in range(20)]
TS("20 números (la primera página llena): truncated y next_page_uri ignorado",
   resp(numbers=ok(numbers_body(_n20, next_uri=f"/2010-04-01/Accounts/{TW_SID}/IncomingPhoneNumbers.json?Page=1&PageSize=20&PageToken=PAfixture"))),
   "ok", facts(numbers={"count": 20, "truncated": True, "voiceCapable": 0, "smsCapable": 20,
                        "items": [{"last4": "00%02d" % i, "voice": False, "sms": True} for i in range(20)]}))
TS("19 números: no está truncado", resp(numbers=ok(numbers_body(_n20[:19]))), "ok",
   facts(numbers={"count": 19, "truncated": False, "voiceCapable": 0, "smsCapable": 19,
                  "items": [{"last4": "00%02d" % i, "voice": False, "sms": True} for i in range(19)]}))
TS("llamadas con to/from y un estado hostil: solo recuentos; lo desconocido va a other",
   resp(calls=ok(calls_body([call("completed"), call("no-answer"), call("busy"), call("completed"), call("llamar al +573000000023")]))),
   "ok", facts(calls={"total": 5, "truncated": False, "byStatus": {"busy": 1, "completed": 2, "no-answer": 1, "other": 1}}))
TS("50 llamadas (página llena)", resp(calls=ok(calls_body([call("completed")] * 50))), "ok",
   facts(calls={"total": 50, "truncated": True, "byStatus": {"completed": 50}}))
TS("alertas hostiles: códigos y niveles fuera de forma van a other",
   resp(alerts=ok(alerts_body([alert("60200", "warning"), alert("60200<script>", "error"), alert("0", "notice"),
                               alert("12345", "ERROR"), alert(None, "debug")]))),
   "ok", facts(alerts={"total": 5, "truncated": False, "byCode": {"12345": 1, "60200": 1, "other": 3},
                       "byLevel": {"debug": 1, "error": 1, "notice": 1, "other": 1, "warning": 1}}))
TS("números con 200 pero sin la lista: error del paso, no cero números",
   resp(numbers={"ok": True, "status": 200, "body": {"message": "nada"}, "error": None}), "degraded",
   facts(numbers=None, errors=[E("numbers", "error", 200)]))
TS("cuenta suspendida de prueba: el paso salió bien", resp(account=ok(acct("suspended", "Trial"))), "ok",
   facts(account={"status": "suspended", "type": "Trial"}))
TS("estado y tipo de cuenta desconocidos: null", resp(account=ok(acct("active; +573000000021", "Enterprise"))), "ok",
   facts(account={"status": None, "type": None}))
TS("un paso que falta en la entrada cuenta como skipped", {"account": ok(acct())}, "degraded",
   facts(numbers=None, callers=None, calls=None, alerts=None, errors=SKIPPED_REST))
TS("la cuenta saltada (cualquier otro caso): error", {"account": SKIP, "numbers": SKIP, "caller_ids": SKIP, "calls": SKIP, "alerts": SKIP},
   "error", facts(account=None, numbers=None, callers=None, calls=None, alerts=None, errors=[E("account", "skipped", None)] + SKIPPED_REST))
TS("20 caller IDs personales: solo el recuento", resp(caller_ids=ok(caller_ids_body([caller_id(i) for i in range(20)]))), "ok",
   facts(callers={"count": 20, "truncated": True}))

# ── Enmienda 1, E1.4 y las convenciones D-10 del paquete TW (README de tests/Unit/Twilio/fixtures) ──
TS("last4 solo de un E.164 estricto (^\\+[1-9][0-9]{7,14}$ con D, el de I4.3)",
   resp(numbers=ok(numbers_body([number("+1 500 555 0006", caps(False, True)), number("15005550006", caps(False, True)),
                                  number("+15005550006\n", caps(False, True)), number(15005550006, caps(False, True)),
                                  number("+1234567", caps(False, True)), number("+12345678", caps(False, True)),
                                  number("+15005550006", caps(True, True))]))),
   "ok", facts(numbers={"count": 7, "truncated": False, "voiceCapable": 1, "smsCapable": 7,
                        "items": [{"last4": None, "voice": False, "sms": True}] * 5
                        + [{"last4": "5678", "voice": False, "sms": True}, {"last4": "0006", "voice": True, "sms": True}]}))
TS("códigos de alerta de 1 a 6 cifras sin cero inicial; un entero se lee como texto",
   resp(alerts=ok(alerts_body([alert("7", "error"), alert("123456", "error"), alert("1234567", "error"), alert("060200", "warning"),
                               alert(30003, "warning"), alert("", "notice")]))),
   "ok", facts(alerts={"total": 6, "truncated": False, "byCode": {"123456": 1, "30003": 1, "7": 1, "other": 3},
                       "byLevel": {"error": 3, "notice": 1, "warning": 2}}))
TS("la ficha de la cuenta sin un sid AC válido (un error de Twilio con 200): error",
   resp(account=ok({"code": 20404, "message": "The requested resource was not found", "more_info": "https://www.twilio.com/docs/errors/20404",
                    "status": 404})),
   "error", facts(account=None, errors=[E("account", "error", 200)]))
TS("la cuenta dice ok sin cuerpo: error", resp(account={"ok": True, "status": 200, "body": None, "error": None}), "error",
   facts(account=None, errors=[E("account", "error", 200)]))
TS("una entrada de la lista que no es un objeto: el paso entero es error, sin recuentos a medias",
   resp(numbers=ok(numbers_body([number("+15005550006", caps(False, True)), "+573000000021"]))), "degraded",
   facts(numbers=None, errors=[E("numbers", "error", 200)]))
TS("la lista es un texto con un teléfono: error", resp(caller_ids=ok({"outgoing_caller_ids": "+573000000099", "page_size": 20})), "degraded",
   facts(callers=None, errors=[E("caller_ids", "error", 200)]))
_n22 = [number("+5760100000%02d" % i, caps(False, True)) for i in range(22)]
TS("más filas de las pedidas: truncated y se recorta a la página (20)", resp(numbers=ok(numbers_body(_n22))), "ok",
   facts(numbers={"count": 20, "truncated": True, "voiceCapable": 0, "smsCapable": 20,
                  "items": [{"last4": "00%02d" % i, "voice": False, "sms": True} for i in range(20)]}))
TS("sobres de fallo raros: etiqueta desconocida o estado que no es un entero de 100 a 599 cuentan como error sin estado",
   resp(numbers={"ok": False, "status": 999, "body": None, "error": "rate_limited"},
        caller_ids={"ok": False, "status": "500", "body": None, "error": None}),
   "degraded", facts(numbers=None, callers=None, errors=[E("numbers", "error", None), E("caller_ids", "error", None)]))

# ── twilioSignatureOfficial: base = URL + claves en orden; para una clave repetida, sus valores ordenados y sin repetir;
#    firma = base64(HMAC-SHA1(authToken, base)). Fuente: twilio/twilio-python tests/unit/test_request_validator.py (main, 31-10-2026).
SRC = "twilio/twilio-python · tests/unit/test_request_validator.py (rama main, descargado el 31-10-2026)"
URI = "https://mycompany.com/myapp.php?foo=1&bar=2"
PARAMS = [["CallSid", "CA1234567890ABCDE"], ["Digits", "1234"], ["From", "+14158675309"], ["To", "+18005551212"], ["Caller", "+14158675309"]]
TW_BODY = '{"property": "value", "boolean": true}'
TW_BODY_HASH = "0a1ff7634d9ab3b95db5c9a2dfe9416e41502b283a80c7cf19632632f96e6620"
assert sha256_hex(TW_BODY) == TW_BODY_HASH


def base_of(url, pairs):
    keys = sorted({k for k, _ in pairs})
    out = url
    for k in keys:
        for v in sorted({v for kk, v in pairs if kk == k}):
            out += k + v
    return out


def TO(desc, kind, url, pairs, sig, *, official=True, body=None, body_hash=None, valid=True):
    calc = tw_sig("12345", base_of(url, pairs))
    assert (calc == sig) == valid, (desc, calc, sig)
    fields = {"official": official, "source": SRC if official else "derivado a mano del vector oficial", "authToken": "12345", "url": url,
              "params": pairs}
    exp = {"signature": sig, "valid": valid}
    if body is not None:
        fields["body"] = body
        exp["bodySHA256"] = body_hash
    add("twilioSignatureOfficial", kind, desc, fields, exp)


TO("formulario: el vector clásico (URL con consulta y cinco parámetros)", "form", URI, PARAMS, "RSOYDt4T1cUTdK1PDd93/VVr8B8=")
TO("JSON: bodySHA256 en la URL y sin parámetros", "json", URI + "&bodySHA256=" + TW_BODY_HASH, [], "a9nBmqA0ju/hNViExpshrM61xv4=",
   body=TW_BODY, body_hash=TW_BODY_HASH)
TO("claves repetidas: valores ordenados y sin repetir", "duplicateKeys", URI,
   [["Sid", "CA123"], ["SidAccount", "AC123"], ["Digits", "5678"], ["Digits", "1234"], ["Digits", "1234"]], "IK+Dwps556ElfBT0I3Rgjkr1wJU=")
TO("la URL con :443 (el SDK prueba con y sin puerto)", "port", "https://mycompany.com:443/myapp.php?foo=1&bar=2", PARAMS, "kvajT1Ptam85bY51eRf/AJRuM3w=")
TO("http con :80", "port", "http://mycompany.com:80/myapp.php?foo=1&bar=2", PARAMS, "0ZXoZLH/DfblKGATFgpif+LLRf4=")
TO("http con :1234", "port", "http://mycompany.com:1234/myapp.php?foo=1&bar=2", PARAMS, "Zmvh+3yNM1Phv2jhDCwEM3q5ebU=")
TO("T-1: la firma oficial contra la URL en http (fullUrl() tras un proxy sin trustProxies) no vale", "derived",
   "http://mycompany.com/myapp.php?foo=1&bar=2", PARAMS, "RSOYDt4T1cUTdK1PDd93/VVr8B8=", official=False, valid=False)

# ── probeRules (ruta 56) ──
SA_P = ["ultron.view", "ultron.campaigns_manage", "ultron.canary_manage", "ultron.canary_authorize", "ultron.integrations_manage"]
SA7 = {"person": True, "adminId": 7, "name": "Persona 7", "role": "Super Admin", "permissions": SA_P}
AD9 = {"person": True, "adminId": 9, "name": "Persona 9", "role": "Administrador", "permissions": ["ultron.view", "ultron.campaigns_manage", "ultron.canary_manage"]}
NOVIEW15 = {"person": True, "adminId": 15, "name": "Persona 15", "role": "Rol a medida", "permissions": ["ultron.integrations_manage"]}
TOKEN = {"person": False, "adminId": None, "name": None, "role": None, "permissions": []}
TOK = "h6fixturetwiliotoken000000000001"
KSID, KSEC = "SK" + "1" * 32, "h6fixtureprobekeysecret000000001"


def PRB(desc, exp, *, a=SA7, enabled=True, writes=True, cr="new", sid=TW_SID, token=TOK, ksid=None, ksec=None, age=None):
    facts = {"actor": a, "probeEnabled": enabled, "writesEnabled": writes, "clientRequest": cr,
             "otp": {"sid": sid, "token": token}, "probeKey": {"sid": ksid, "secret": ksec},
             "lastProbeAgeSeconds": age, "cooldownSeconds": 300}
    add("probeRules", "probe", desc, {"facts": facts}, exp)


def run(credential):
    return {"status": 201, "code": None, "credential": credential, "network": True,
            "audit": {"event": "integration.probe_run", "from": None, "to": None}}


REUSED200 = {"status": 200, "code": "reused", "network": False, "audit": None}
REPLAY200 = {"status": 200, "code": "replayed", "network": False}
DISABLED = {"status": 409, "code": "probe_disabled", "audit": None}


def tnc(reason):
    return {"status": 409, "code": "twilio_not_configured", "payload": {"reason": reason}}


PRB("todo listo con la credencial del OTP", run("otp"))
PRB("con la llave restringida completa", run("restricted_key"), ksid=KSID, ksec=KSEC)
PRB("llave restringida a medias (solo el SID) y token del OTP: credencial del OTP", run("otp"), ksid=KSID)
PRB("llave a medias y sin token del OTP", tnc("missing_credential"), ksid=KSID, token=None)
PRB("solo el secreto de la llave y sin token del OTP", tnc("missing_credential"), ksec=KSEC, token="")
PRB("sin el SID de la cuenta", tnc("missing_account_sid"), sid=None)
PRB("SID de cuenta mal formado: nunca va a la ruta", tnc("missing_account_sid"), sid="AC123")
PRB("SID de cuenta en mayúsculas: no casa con ^AC[0-9a-f]{32}$", tnc("missing_account_sid"), sid="AC" + "0123456789ABCDEF" * 2)
PRB("sin SID y sin credencial: el SID primero", tnc("missing_account_sid"), sid="", token=None)
PRB("bandera de la sonda apagada", DISABLED, enabled=False)
PRB("sonda apagada y reintento: la bandera antes", DISABLED, enabled=False, cr="replay")
PRB("sonda apagada y sin permiso: el permiso antes", {"status": 403, "code": "forbidden", "payload": {"required_permission": "ultron.integrations_manage"}},
    a=AD9, enabled=False)
PRB("integrations_manage sin ultron.view", {"status": 403, "code": "forbidden", "payload": {"required_permission": "ultron.view"}}, a=NOVIEW15)
PRB("token compartido", {"status": 403, "code": "person_required"}, a=TOKEN)
PRB("reintento idéntico", REPLAY200, cr="replay")
PRB("reintento con la configuración ya rota: el reintento antes", REPLAY200, cr="replay", sid=None)
PRB("misma clientRequestId de otra persona", {"status": 409, "code": "client_request_reused"}, cr="reusedOtherActor")
PRB("la última sonda fue hace 299 s: se reutiliza sin red ni evento", REUSED200, age=299)
PRB("la última sonda fue hace 300 s: se vuelve a lanzar", run("otp"), age=300)
PRB("la última sonda fue hace 0 s", REUSED200, age=0)
PRB("escrituras apagadas y sonda encendida: la sonda no depende de esa bandera", run("otp"), writes=False)
PRB("sin configurar y en enfriamiento: la configuración antes", tnc("missing_account_sid"), sid=None, age=10)

# ── acknowledgeRules (ruta 55) ──
FP_A, FP_B = "a" * 64, "b" * 64


def ACK(desc, exp, *, a=SA7, writes=True, cr="new", sent=FP_A, effective=FP_A):
    facts = {"actor": a, "writesEnabled": writes, "clientRequest": cr, "provider": "twilio",
             "sentFingerprint": sent, "effectiveFingerprint": effective}
    add("acknowledgeRules", "acknowledge", desc, {"facts": facts}, exp)


ACK_OK = {"status": 201, "code": None, "snapshot": {"provider": "twilio", "kind": "config", "actorType": "admin", "fingerprint": FP_A},
          "audit": {"event": "integration.config_acknowledged", "from": None, "to": None}}
ACK("la huella enviada es la efectiva", ACK_OK)
ACK("con la bandera de escrituras apagada: no depende de ella", ACK_OK, writes=False)
ACK("la huella enviada ya no es la efectiva", {"status": 409, "code": "config_changed",
                                                "payloadIncludes": {"provider": "twilio", "effectiveFingerprint": FP_B}}, effective=FP_B)
ACK("reintento idéntico", {"status": 200, "code": "replayed"}, cr="replay")
ACK("reintento cuando la configuración ya cambió: el reintento antes", {"status": 200, "code": "replayed"}, cr="replay", effective=FP_B)
ACK("misma clientRequestId con otro cuerpo", {"status": 409, "code": "client_request_reused"}, cr="reusedOtherBody")
ACK("el Administrador no tiene integrations_manage", {"status": 403, "code": "forbidden", "payload": {"required_permission": "ultron.integrations_manage"}}, a=AD9)
ACK("integrations_manage sin ultron.view", {"status": 403, "code": "forbidden", "payload": {"required_permission": "ultron.view"}}, a=NOVIEW15)
ACK("token compartido", {"status": 403, "code": "person_required"}, a=TOKEN)

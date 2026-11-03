# outbound-gate-cases.json · la única puerta de las salientes (D8-12) y su job (D8-9, D8-12, D8-13, D8-21)
#   decide         · OutboundPolicy con los hechos ya calculados: cada motivo aislado, el orden y las combinaciones
#   whatsappGuard  · voice_number_is_whatsapp (D8-5)
#   job            · PlaceOutboundVoiceCall: relectura de la cabeza, deriva, freno y la respuesta de ElevenLabs
ORDER = ["calls_disabled_in_code", "outbound_disabled", "twilio_voice_disabled", "no_voice_number", "voice_number_is_whatsapp",
         "elevenlabs_not_configured", "elevenlabs_disabled", "elevenlabs_unverified",
         "no_publication", "publication_unreadable", "agent_drift", "voice_braked",
         "source_not_allowed", "not_an_active_voice_canary", "contact_not_authorized", "no_voice_consent", "excluded", "rne_check_missing",
         "outside_hours", "holiday",
         "frequency_exceeded", "concurrency_exceeded", "daily_cap_zero", "daily_cap_reached", "budget_exhausted",
         "duplicate_request"]
assert len(ORDER) == len(set(ORDER)) == 26

OG = Fixture("outbound-gate-cases.json", header(
    "outbound-gate-cases.json",
    ["D8-12 (OutboundPolicy: conjunto cerrado de motivos en este orden; idempotencia; job tries = 1; la petición a ElevenLabs)",
     "D8-9 (publication_moved)", "D8-5 (voice_number_is_whatsapp)", "D8-13 (estados y eventos de la saliente)",
     "D8-19 (409 voice_braked)", "D8-21 (deriva también en el job)", "H6 §I6.5 (VoiceCallPolicy::decide acumula los motivos en orden)"],
    [{"what": "POST /v1/convai/twilio/outbound-call: agent_id, agent_phone_number_id y to_number obligatorios; call_recording_enabled; telephony_call_config (ringing_timeout_secs, twilio_call_recording_enabled, twilio_machine_detection); conversation_initiation_client_data (dynamic_variables, user_id…); respuesta success, message, conversation_id y callSid",
      "url": "https://elevenlabs.io/docs/api-reference/twilio/outbound-call", "consulted": "03-11-2026 (auditoría §d.1, leída por el architect)",
      "how": "La cabecera xi-api-key es la del código del H6 (ElevenLabsVoiceProvider.php:82). El tipo de twilio_machine_detection no está en la auditoría: [por confirmar]."}],
    closedSets={"reasonsInOrder": ORDER, "allowedSources": ["canary_registry", "voice_contacts"], "forbiddenSources": ["marketing_calls", "other"],
                "scopes": ["canary", "contacts"], "providerStates": ["ELEVENLABS_NOT_CONFIGURED", "ELEVENLABS_DISABLED", "ELEVENLABS_UNVERIFIED", "ELEVENLABS_VERIFIED"],
                "contactStatuses": ["pending", "authorized", "suspended"], "jobOutcomes": ["started", "rejected", "ambiguous", "error"]},
    decisions={
        "DF-G1": "OutboundPolicy ACUMULA todos los motivos que apliquen, en el orden de D8-12 (como VoiceCallPolicy::decide del H6). codeClosed es lo que da el árbol compartido (CALLS_ENABLED_IN_CODE = false); codeOpen, la copia del banco con la constante girada.",
        "DF-G2": "source_not_allowed solo para orígenes fuera de {canary_registry, voice_contacts}; el alcance decide lo demás: en canary hace falta un canario de voz activo, en contacts un voice_contact authorized con consentimiento y RNE vigente.",
        "DF-G3": "daily_cap_reached solo con un cupo > 0 (con 0 ya sale daily_cap_zero); concurrency_exceeded si dialing + active ≥ el máximo.",
        "DF-G4": "HTTP: aceptada → 202 con la fila requested; rechazada → 409 con todos los motivos (D8-19 fija 409 para voice_braked).",
        "DF-G5": "duplicate_request: client_request_id ya usado por la MISMA persona para una solicitud aceptada → 200 con la fila existente; otra persona con el mismo id no es duplicado (UNIQUE por persona). Va el último y se acumula como los demás.",
        "DF-G6": "Un rechazo de la puerta no crea fila en voice_calls; canceled es de una fila requested que el job (o el corte) para antes de marcar.",
        "DF-G7": "La reserva de coste: se libera con failed_to_start y canceled; se mantiene con ambiguous hasta que la conciliación lo resuelva.",
        "DF-G8": "Respuestas raras de outbound-call: 200 con success = false → failed_to_start; 200 sin conversation_id o sin callSid, o un 3xx (no se siguen redirecciones) → ambiguous; un fallo local antes de enviar (descifrado, configuración) → error → canceled, sin petición.",
        "DF-G9": "El job vuelve a mirar, en este orden: la constante y las banderas, el freno, la cabeza de la cadena (publication_moved si cambió seq) y la deriva del agente (con su caché ≤ 5 min). No repite la frecuencia ni los cupos: ya están reservados.",
        "DF-G10": "Guarda del número de WhatsApp: se comparan los dígitos (como la lista de config/meta.php); un número nacional de 10 dígitos (3… o 60…) gana el 57 delante. Coincide con WHATSAPP_DISPLAY_PHONE o con cualquiera de WHATSAPP_PROTECTED_NUMBERS → voice_number_is_whatsapp.",
    },
))

BASE_CANARY = {
    "flags": {"outboundEnabled": True, "twilioVoiceEnabled": True},
    "number": {"voiceFromConfigured": True, "voiceNumberIsWhatsapp": False},
    "provider": {"state": "ELEVENLABS_VERIFIED"},
    "publication": {"head": "ok", "agentDrift": False, "braked": False},
    "target": {"scope": "canary", "source": "canary_registry", "canary": "active_voice", "excluded": False},
    "calendar": {"outsideHours": False, "holiday": False},
    "quotas": {"frequencyExceeded": False, "activeOrDialing": 0, "maxConcurrent": 1, "dailyCap": 3, "placedToday": 0, "budgetFits": True},
    "request": {"clientRequestIdSeenForSamePerson": False},
}
BASE_CONTACTS = dict(copy.deepcopy(BASE_CANARY), target={"scope": "contacts", "source": "voice_contacts", "contactStatus": "authorized",
                                                          "voiceConsent": True, "excluded": False, "rneCheckMissing": False})


def reasons_of(f, code_open):
    r = []
    if not code_open:
        r.append("calls_disabled_in_code")
    if not f["flags"]["outboundEnabled"]:
        r.append("outbound_disabled")
    if not f["flags"]["twilioVoiceEnabled"]:
        r.append("twilio_voice_disabled")
    if not f["number"]["voiceFromConfigured"]:
        r.append("no_voice_number")
    elif f["number"]["voiceNumberIsWhatsapp"]:
        r.append("voice_number_is_whatsapp")
    st = {"ELEVENLABS_NOT_CONFIGURED": "elevenlabs_not_configured", "ELEVENLABS_DISABLED": "elevenlabs_disabled",
          "ELEVENLABS_UNVERIFIED": "elevenlabs_unverified"}.get(f["provider"]["state"])
    if st:
        r.append(st)
    p = f["publication"]
    if p["head"] == "none":
        r.append("no_publication")
    elif p["head"] == "unreadable":
        r.append("publication_unreadable")
    elif p["agentDrift"]:
        r.append("agent_drift")
    if p["braked"]:
        r.append("voice_braked")
    t = f["target"]
    if t["source"] not in ("canary_registry", "voice_contacts"):
        r.append("source_not_allowed")
    if t["scope"] == "canary":
        if t.get("canary") != "active_voice":
            r.append("not_an_active_voice_canary")
    else:
        if t.get("contactStatus") != "authorized":
            r.append("contact_not_authorized")
        if not t.get("voiceConsent"):
            r.append("no_voice_consent")
    if t["excluded"]:
        r.append("excluded")
    if t["scope"] == "contacts" and t.get("rneCheckMissing"):
        r.append("rne_check_missing")
    if f["calendar"]["outsideHours"]:
        r.append("outside_hours")
    if f["calendar"]["holiday"]:
        r.append("holiday")
    q = f["quotas"]
    if q["frequencyExceeded"]:
        r.append("frequency_exceeded")
    if q["activeOrDialing"] >= q["maxConcurrent"]:
        r.append("concurrency_exceeded")
    if q["dailyCap"] <= 0:
        r.append("daily_cap_zero")
    elif q["placedToday"] >= q["dailyCap"]:
        r.append("daily_cap_reached")
    if not q["budgetFits"]:
        r.append("budget_exhausted")
    if f["request"]["clientRequestIdSeenForSamePerson"]:
        r.append("duplicate_request")
    return r


def setp(base, changes):
    f = copy.deepcopy(base)
    for path, v in changes.items():
        cur = f
        ks = path.split(".")
        for k in ks[:-1]:
            cur = cur[k]
        cur[ks[-1]] = v
    return f


def http_of(reasons):
    if not reasons:
        return 202
    if reasons == ["duplicate_request"]:
        return 200
    return 409


def GD(desc, base, changes, expected_open, *, basis="contrato", decision="DF-G1", ref="D8-12"):
    f = setp(base, changes)
    got = reasons_of(f, True)
    assert got == expected_open, (desc, got, expected_open)
    assert expected_open == sorted(expected_open, key=ORDER.index)
    closed = reasons_of(f, False)
    exp = {"codeOpen": {"allowed": not expected_open, "reasons": expected_open, "http": http_of(expected_open)},
           "codeClosed": {"allowed": False, "reasons": closed, "http": 409}}
    OG.add("decide", base["target"]["scope"], desc, ref, basis, {"facts": f}, exp, decision=decision)


GD("canario: todo abierto → permitida (solo con la constante girada)", BASE_CANARY, {}, [])
GD("contacto con consentimiento: todo abierto → permitida", BASE_CONTACTS, {}, [], ref="D8-12, D8-18, P-20")
# cada motivo, aislado
ISOLATED = [
    ("VOICE_OUTBOUND_ENABLED = false", BASE_CANARY, {"flags.outboundEnabled": False}, ["outbound_disabled"], "D8-12"),
    ("TWILIO_VOICE_ENABLED = false", BASE_CANARY, {"flags.twilioVoiceEnabled": False}, ["twilio_voice_disabled"], "D8-12"),
    ("sin TWILIO_VOICE_FROM", BASE_CANARY, {"number.voiceFromConfigured": False}, ["no_voice_number"], "D8-12"),
    ("el número de voz es el de WhatsApp", BASE_CANARY, {"number.voiceNumberIsWhatsapp": True}, ["voice_number_is_whatsapp"], "D8-5, D8-12"),
    ("ElevenLabs sin configurar", BASE_CANARY, {"provider.state": "ELEVENLABS_NOT_CONFIGURED"}, ["elevenlabs_not_configured"], "D8-12"),
    ("ElevenLabs apagado", BASE_CANARY, {"provider.state": "ELEVENLABS_DISABLED"}, ["elevenlabs_disabled"], "D8-12"),
    ("ElevenLabs sin verificar (o la última verificación ok tiene 30 días o más)", BASE_CANARY, {"provider.state": "ELEVENLABS_UNVERIFIED"},
     ["elevenlabs_unverified"], "D8-12"),
    ("sin publicación de voz", BASE_CANARY, {"publication.head": "none"}, ["no_publication"], "D8-12"),
    ("publicación ilegible: un fallo deniega", BASE_CANARY, {"publication.head": "unreadable"}, ["publication_unreadable"], "D8-8, D8-12"),
    ("deriva del agente sellado", BASE_CANARY, {"publication.agentDrift": True}, ["agent_drift"], "D8-12, D8-21"),
    ("freno abierto → 409 voice_braked", BASE_CANARY, {"publication.braked": True}, ["voice_braked"], "D8-12, D8-19"),
    ("origen marketing_calls (prohibido desde el H6)", BASE_CANARY, {"target.source": "marketing_calls"}, ["source_not_allowed"], "D8-12, H6 T-7"),
    ("origen other", BASE_CANARY, {"target.source": "other"}, ["source_not_allowed"], "D8-12"),
    ("alcance canary y el número no está registrado", BASE_CANARY, {"target.canary": "not_registered"}, ["not_an_active_voice_canary"], "D8-12"),
    ("alcance canary y el canario no está activo", BASE_CANARY, {"target.canary": "not_active"}, ["not_an_active_voice_canary"], "D8-12"),
    ("alcance contacts y el contacto está pendiente", BASE_CONTACTS, {"target.contactStatus": "pending"}, ["contact_not_authorized"], "D8-12, D8-18"),
    ("alcance contacts sin consentimiento de voz", BASE_CONTACTS, {"target.voiceConsent": False}, ["no_voice_consent"], "D8-12, D8-18"),
    ("alcance contacts con una exclusión", BASE_CONTACTS, {"target.excluded": True}, ["excluded"], "D8-12, D8-18"),
    ("alcance contacts sin consulta vigente al RNE", BASE_CONTACTS, {"target.rneCheckMissing": True}, ["rne_check_missing"], "D8-12, D8-18, P-14"),
    ("fuera de horario", BASE_CANARY, {"calendar.outsideHours": True}, ["outside_hours"], "D8-12, D8-15"),
    ("festivo", BASE_CANARY, {"calendar.holiday": True}, ["holiday"], "D8-12, D8-15"),
    ("frecuencia agotada (1 al día, 2 a la semana)", BASE_CANARY, {"quotas.frequencyExceeded": True}, ["frequency_exceeded"], "D8-12, D8-15"),
    ("concurrencia: 1 en curso de 1", BASE_CANARY, {"quotas.activeOrDialing": 1}, ["concurrency_exceeded"], "D8-12, D8-15"),
    ("cupo diario 0 (el defecto)", BASE_CANARY, {"quotas.dailyCap": 0}, ["daily_cap_zero"], "D8-12, D8-15"),
    ("cupo diario agotado: 3 de 3", BASE_CANARY, {"quotas.placedToday": 3}, ["daily_cap_reached"], "D8-12, D8-15"),
    ("la reserva de coste no cabe", BASE_CANARY, {"quotas.budgetFits": False}, ["budget_exhausted"], "D8-12, D8-15"),
    ("client_request_id ya usado por la misma persona → 200 con la misma fila", BASE_CANARY, {"request.clientRequestIdSeenForSamePerson": True},
     ["duplicate_request"], "D8-12"),
]
for desc, base, ch, exp, ref in ISOLATED:
    dec = {"voice_number_is_whatsapp": "DF-G10", "source_not_allowed": "DF-G2", "concurrency_exceeded": "DF-G3", "daily_cap_reached": "DF-G3",
           "duplicate_request": "DF-G5"}.get(exp[0], "DF-G1")
    GD(desc, base, ch, exp, basis="decision-fixture" if dec != "DF-G1" else "contrato", decision=dec, ref=ref)
# bordes
GD("concurrencia: 1 en curso de 2 → cabe", BASE_CANARY, {"quotas.activeOrDialing": 1, "quotas.maxConcurrent": 2}, [], basis="decision-fixture", decision="DF-G3")
GD("cupo diario: 2 de 3 → cabe", BASE_CANARY, {"quotas.placedToday": 2}, [], basis="decision-fixture", decision="DF-G3")
GD("cupo diario 0 y 0 hechas: solo daily_cap_zero, no también daily_cap_reached", BASE_CANARY, {"quotas.dailyCap": 0, "quotas.placedToday": 0},
   ["daily_cap_zero"], basis="decision-fixture", decision="DF-G3")
GD("sin número: no se mira si es el de WhatsApp", BASE_CANARY, {"number.voiceFromConfigured": False, "number.voiceNumberIsWhatsapp": True},
   ["no_voice_number"], basis="decision-fixture", decision="DF-G10")
GD("canario con exclusión (dijo «no me vuelvas a llamar») → excluded también en canary", BASE_CANARY, {"target.excluded": True}, ["excluded"],
   basis="decision-fixture", decision="DF-P6", ref="D8-12, D8-18")
GD("un voice_contact con alcance canary → not_an_active_voice_canary (el origen sí está permitido)", BASE_CANARY,
   {"target.source": "voice_contacts", "target.canary": None}, ["not_an_active_voice_canary"], basis="decision-fixture", decision="DF-G2")
GD("un canario con alcance contacts → contact_not_authorized y no_voice_consent", BASE_CONTACTS,
   {"target.source": "canary_registry", "target.contactStatus": None, "target.voiceConsent": False}, ["contact_not_authorized", "no_voice_consent"],
   basis="decision-fixture", decision="DF-G2")
GD("en alcance canary el RNE no se mira", BASE_CANARY, {"target.rneCheckMissing": True}, [], basis="contrato", decision="DF-G2", ref="D8-12, P-20")
GD("publicación ilegible: la deriva no se puede mirar (sale solo publication_unreadable)", BASE_CANARY,
   {"publication.head": "unreadable", "publication.agentDrift": True}, ["publication_unreadable"], basis="decision-fixture", decision="DF-G1")
# orden y acumulación
GD("freno y fuera de horario: los dos, en orden", BASE_CANARY, {"publication.braked": True, "calendar.outsideHours": True},
   ["voice_braked", "outside_hours"])
GD("duplicado con el freno abierto: se acumula (voice_braked y duplicate_request)", BASE_CANARY,
   {"publication.braked": True, "request.clientRequestIdSeenForSamePerson": True}, ["voice_braked", "duplicate_request"],
   basis="decision-fixture", decision="DF-G5")
GD("festivo en sábado y además cupo agotado", BASE_CANARY, {"calendar.holiday": True, "quotas.placedToday": 3}, ["holiday", "daily_cap_reached"])
GD("todo apagado como en producción hoy (fase 1)", BASE_CANARY,
   {"flags.outboundEnabled": False, "flags.twilioVoiceEnabled": False, "number.voiceFromConfigured": False,
    "provider.state": "ELEVENLABS_NOT_CONFIGURED", "publication.head": "none", "quotas.dailyCap": 0, "quotas.budgetFits": False},
   ["outbound_disabled", "twilio_voice_disabled", "no_voice_number", "elevenlabs_not_configured", "no_publication", "daily_cap_zero",
    "budget_exhausted"], ref="D8-12, protección 1")
GD("contacto con todo mal → todos los motivos del destino, el calendario y los cupos en orden", BASE_CONTACTS,
   {"target.contactStatus": "suspended", "target.voiceConsent": False, "target.excluded": True, "target.rneCheckMissing": True,
    "calendar.outsideHours": True, "calendar.holiday": True, "quotas.frequencyExceeded": True, "quotas.activeOrDialing": 1,
    "quotas.placedToday": 3, "quotas.budgetFits": False},
   ["contact_not_authorized", "no_voice_consent", "excluded", "rne_check_missing", "outside_hours", "holiday", "frequency_exceeded",
    "concurrency_exceeded", "daily_cap_reached", "budget_exhausted"])


# ── whatsappGuard (D8-5) ────────────────────────────────────────────────────
def digits_norm(raw):
    d = "".join(ch for ch in raw if ch.isdigit() and ch.isascii())
    if len(d) == 10 and (d.startswith("3") or d.startswith("60")):
        d = "57" + d
    return d


def WG(desc, voice_from, display, protected, is_wa):
    nums = [digits_norm(x) for x in (protected if protected.strip() else display).split(",") if digits_norm(x)]
    if display.strip() and digits_norm(display):
        nums.append(digits_norm(display))
    assert (digits_norm(voice_from) in nums) == is_wa, desc
    OG.add("whatsappGuard", "guard", desc, "D8-5", "decision-fixture",
           {"voiceFrom": voice_from, "whatsappDisplayPhone": display, "whatsappProtectedNumbers": protected},
           {"voiceNumberIsWhatsapp": is_wa}, decision="DF-G10")


WG("el número dedicado (fijo) no es el de WhatsApp", VOICE_FROM, WHATSAPP_DISPLAY, "", False)
WG("el número de voz es el visible de WhatsApp", WHATSAPP_DISPLAY, WHATSAPP_DISPLAY, "", True)
WG("el visible escrito con espacios y sin + coincide igual", WHATSAPP_DISPLAY, "57 300 000 0050", "", True)
WG("el visible en formato nacional (10 dígitos) coincide igual", WHATSAPP_DISPLAY, "300 000 0050", "", True)
WG("está en la lista de protegidos (separada por comas)", "+573000000051", WHATSAPP_DISPLAY, "+573000000051, +573000000052", True)
WG("la lista de protegidos no lo tiene ni el visible", "+573000000053", WHATSAPP_DISPLAY, "+573000000051,+573000000052", False)
WG("sin visible ni protegidos: no hay con qué chocar", VOICE_FROM, "", "", False)

# ── job: PlaceOutboundVoiceCall (D8-9, D8-12, D8-13, D8-21) ────────────────
JOB_ROW = {"status": "requested", "direction": "outbound", "callRef": call_ref(51), "sealedAgentId": AGENT_CANARY, "voicePublicationSeq": 3,
           "toEncrypted": "(cifrado de " + CANARY_A + ")", "reserveMicroUsd": 610500}
REQ_ASSERT = [
    {"path": "agent_id", "equals": AGENT_CANARY},
    {"path": "agent_phone_number_id", "equals": PHNUM},
    {"path": "to_number", "equals": CANARY_A},
    {"path": "call_recording_enabled", "equals": False},
    {"path": "telephony_call_config.twilio_call_recording_enabled", "equals": False},
    {"path": "conversation_initiation_client_data.dynamic_variables", "equals": {"caller_authorized": "si", "call_ref": call_ref(51)}},
    {"path": "conversation_initiation_client_data.user_id", "equals": call_ref(51)},
]
REQ_CONFIG = ["telephony_call_config.ringing_timeout_secs (P-10)", "telephony_call_config.twilio_machine_detection (P-10, tipo por confirmar)"]
REQ_FORBIDDEN = ["conversation_initiation_client_data.conversation_config_override", "conversation_initiation_client_data.source_info",
                 "conversation_initiation_client_data.branch_id"]


def J(desc, given, el_response, exp, *, basis="contrato", decision=None, ref="D8-12, D8-13"):
    OG.add("job", "place", desc, ref, basis, {"row": JOB_ROW, "given": given, "elevenlabsResponse": el_response}, exp, decision=decision)


GIVEN_OK = {"callsEnabledInCode": True, "outboundEnabled": True, "braked": False, "headSeq": 3, "agentDrift": False}
OK_BODY = {"status": 200, "body": {"success": True, "message": "Success", "conversation_id": conv(51), "callSid": call_sid(51)}}
SENT = {"method": "POST", "path": "/v1/convai/twilio/outbound-call", "times": 1, "header": "xi-api-key", "followRedirects": False,
        "bodyAssertions": REQ_ASSERT, "configurablePaths": REQ_CONFIG, "forbiddenPaths": REQ_FORBIDDEN}
J("todo bien: una petición y dialing con conversation_id y callSid", GIVEN_OK, OK_BODY,
  {"request": SENT, "statusAfter": "dialing", "providerConversationId": conv(51), "providerCallSid": call_sid(51), "events": ["outbound_started"],
   "reservation": "se mantiene", "retries": 0})
J("la cabeza de la cadena cambió (seq 3 → 4) → canceled publication_moved, sin petición", dict(GIVEN_OK, headSeq=4), None,
  {"request": None, "statusAfter": "canceled", "reason": "publication_moved", "events": [], "reservation": "se libera"}, ref="D8-9, D8-13",
  basis="decision-fixture", decision="DF-G7")
J("deriva del agente en el job → canceled agent_drift, freno y sin petición", dict(GIVEN_OK, agentDrift=True), None,
  {"request": None, "statusAfter": "canceled", "reason": "agent_drift", "brakeOpens": True, "events": [], "reservation": "se libera"},
  ref="D8-19, D8-21", basis="decision-fixture", decision="DF-G9")
J("freno abierto entre la solicitud y el job → canceled voice_braked, sin petición", dict(GIVEN_OK, braked=True), None,
  {"request": None, "statusAfter": "canceled", "reason": "voice_braked", "events": [], "reservation": "se libera"}, ref="D8-13, D8-19",
  basis="decision-fixture", decision="DF-G9")
J("la constante cerrada (el árbol compartido) → canceled calls_disabled_in_code, sin petición", dict(GIVEN_OK, callsEnabledInCode=False), None,
  {"request": None, "statusAfter": "canceled", "reason": "calls_disabled_in_code", "events": [], "reservation": "se libera"},
  basis="decision-fixture", decision="DF-G9", ref="protección 2, D8-13")
J("VOICE_OUTBOUND_ENABLED apagado después de pedirla (nivel 0) → canceled outbound_disabled", dict(GIVEN_OK, outboundEnabled=False), None,
  {"request": None, "statusAfter": "canceled", "reason": "outbound_disabled", "events": [], "reservation": "se libera"},
  basis="decision-fixture", decision="DF-G9", ref="D8-20, D8-13")
for code in (400, 401, 404, 409, 422, 429):
    J(f"ElevenLabs responde {code} → failed_to_start, reserva liberada, sin reintento", GIVEN_OK, {"status": code, "body": {"detail": "rechazada"}},
      {"request": SENT, "statusAfter": "failed_to_start", "events": ["outbound_rejected"], "reservation": "se libera", "retries": 0})
for code in (500, 502, 503, 504):
    J(f"ElevenLabs responde {code} → ambiguous, sin reintento (lo resuelve la conciliación)", GIVEN_OK, {"status": code, "body": None},
      {"request": SENT, "statusAfter": "ambiguous", "events": ["outbound_ambiguous"], "reservation": "se mantiene", "retries": 0},
      decision="DF-G7")
J("tiempo agotado → ambiguous, nunca failed", GIVEN_OK, {"timeout": True},
  {"request": SENT, "statusAfter": "ambiguous", "events": ["outbound_ambiguous"], "reservation": "se mantiene", "retries": 0},
  decision="DF-G7")
J("conexión rechazada → ambiguous", GIVEN_OK, {"connectionError": True},
  {"request": SENT, "statusAfter": "ambiguous", "events": ["outbound_ambiguous"], "reservation": "se mantiene", "retries": 0},
  decision="DF-G7")
J("200 con success = false → failed_to_start", GIVEN_OK, {"status": 200, "body": {"success": False, "message": "No se pudo", "conversation_id": None, "callSid": None}},
  {"request": SENT, "statusAfter": "failed_to_start", "events": ["outbound_rejected"], "reservation": "se libera", "retries": 0},
  basis="decision-fixture", decision="DF-G8")
J("200 sin callSid → ambiguous (no se puede confirmar)", GIVEN_OK, {"status": 200, "body": {"success": True, "message": "Success", "conversation_id": conv(52)}},
  {"request": SENT, "statusAfter": "ambiguous", "events": ["outbound_ambiguous"], "reservation": "se mantiene", "retries": 0},
  basis="decision-fixture", decision="DF-G8")
J("302 → ambiguous, sin seguir la redirección", GIVEN_OK, {"status": 302, "headers": {"Location": "https://evil.test/outbound-call"}},
  {"request": SENT, "statusAfter": "ambiguous", "events": ["outbound_ambiguous"], "reservation": "se mantiene", "retries": 0,
   "requestsToOtherHosts": 0}, basis="decision-fixture", decision="DF-G8")
J("fallo local antes de enviar (no se puede descifrar el número) → canceled, sin petición", dict(GIVEN_OK, decryptFails=True), None,
  {"request": None, "statusAfter": "canceled", "reason": "error", "events": [], "reservation": "se libera"}, basis="decision-fixture", decision="DF-G8")

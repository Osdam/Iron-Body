# elevenlabs-webhook-bodies.json · cuerpos que ElevenLabs envía a Laravel (D8-10, D8-11, D8-13, D8-16; auditoría §d.1)
#   postCall   · POST /api/webhooks/voice/elevenlabs/post-call   (post_call_transcription, post_call_audio, call_initiation_failure)
#   initiation · POST /api/webhooks/voice/elevenlabs/initiation  (y la respuesta conversation_initiation_client_data)
#   tools      · POST /api/voice/tools/{tool}                    (server tools «webhook», Bearer)
#   guard      · la guarda posterior sobre la transcripción, en memoria (D8-10 capa 4)
#   routeRules · tabla de respuestas HTTP de las tres rutas públicas (D8-16)
NOW = epoch(2026, 11, 4, 15, 0, 0)          # miércoles 04-11-2026, 10:00 en Bogotá
assert NOW == 1793804400
AI_NOTICE = ("Hola, te habla el asistente virtual de Iron Body Workout, un sistema de inteligencia artificial. "
             "Te atiendo para informarte sobre nuestros planes y servicios, y tus datos se tratan según nuestra política de privacidad. "
             "Si no quieres recibir más llamadas o prefieres hablar con una persona, dímelo en cualquier momento.")   # E1-3 (sustituye el de P-9)
UNAVAILABLE = "Lo siento, esta línea no está disponible en este momento. Gracias por llamar."
AGENT_CANARY = "agent_h8fx_canary_0003"     # el agente sellado de la parte canary vigente
AGENT_OLD = "agent_h8fx_canary_0002"        # el de la publicación anterior (sigue existiendo, D8-7)
AGENT_ROGUE = "agent_h8fx_rogue_0099"       # uno que ninguna publicación conoce
PHNUM = "phnum_h8fx_0001"                   # ELEVENLABS_AGENT_PHONE_NUMBER_ID sintético
VERSION_LABEL = "v12 · canario"


def call_sid(n):
    return "CA" + "%032x" % (0xA8000000 + n)


def conv(n):
    return "conv_h8fx_%04d" % n


def call_ref(n):
    return "8f000000-0000-4000-8000-%012d" % n


SENT_NAME = "Ana Prueba"
SENT_PHONE_SPOKEN = "300 000 0031"
SENT_EMAIL = "ana.prueba@correo.test"
SENT_SUMMARY = "La persona preguntó por el horario de la Sede Centro y colgó satisfecha."
AUDIO_B64 = base64.b64encode(b"ID3\x04\x00\x00\x00\x00\x00\x00h8fixture-mp3-falso" + bytes(range(32))).decode()

EB = Fixture("elevenlabs-webhook-bodies.json", header(
    "elevenlabs-webhook-bodies.json",
    ["D8-8 (sello posterior: observed_agent_id, observed_version_id, observed_branch_id, seal_verified)",
     "D8-10 (herramientas: catálogo cerrado, autenticación, esquema, comprobación de la llamada, 20 por llamada; guarda posterior)",
     "D8-11 (iniciación: InboundPolicy, respuesta solo con caller_authorized, call_ref y version_label)",
     "D8-13 (estados y eventos)", "D8-16 (rutas públicas, topes y códigos HTTP)", "D8-17 (sin transcripciones ni teléfonos en Laravel)",
     "D8-19 (freno: guarda, seal_mismatch, llamada desconocida)"],
    [
        {"what": "Webhooks post-llamada: tipos, campos de data (agent_id, conversation_id, branch_id, version_id, environment, transcript, analysis, metadata), deduplicar por conversation_id, desactivación tras 10 fallos",
         "url": "https://elevenlabs.io/docs/agents-platform/workflows/post-call-webhooks", "consulted": "03-11-2026 (auditoría §d.1, leída por el architect)",
         "how": "No la abrí hoy (sin red). Los nombres de campo que la auditoría no enumera (tool_calls/tool_results de cada turno, metadata.phone_call, metadata del fallo de inicio) son los de la documentación tal como la conozco, marcados [por confirmar] en cada cuerpo."},
        {"what": "Webhook de iniciación de las entrantes de Twilio (caller_id, agent_id, called_number, call_sid, conversation_id) y la respuesta conversation_initiation_client_data con dynamic_variables",
         "url": "https://elevenlabs.io/docs/eleven-agents/phone-numbers/twilio-integration/customising-calls.md", "consulted": "03-11-2026 (auditoría §d.1)", "how": "Campos de la auditoría."},
        {"what": "Server tools (webhook con cuerpo JSON propio, Bearer guardado como secreto, sin firma HMAC del cuerpo)",
         "url": "https://elevenlabs.io/docs/eleven-agents/customization/tools/server-tools", "consulted": "03-11-2026 (auditoría §d.1)",
         "how": "El cuerpo de cada herramienta lo define Laravel (DF-E8); que conversation_id y call_sid lleguen de system__* sin que los rellene el LLM es C-A3 [por confirmar]."},
        {"what": "Variables de sistema system__caller_id, system__call_sid, system__conversation_id",
         "url": "https://elevenlabs.io/docs/eleven-agents/customization/personalization/dynamic-variables.md", "consulted": "03-11-2026 (auditoría §d.1, vista)", "how": "—"},
    ],
    world={"now": NOW, "nowIso": utc_iso(2026, 11, 4, 15, 0, 0), "voiceFrom": VOICE_FROM, "agentPhoneNumberId": PHNUM,
           "sealedAgentCanary": AGENT_CANARY, "previousAgent": AGENT_OLD, "versionLabel": VERSION_LABEL, "aiNotice": AI_NOTICE,
           "canaryActive": CANARY_A, "stranger": STRANGER,
           "headers": {"initiation": "X-Voice-Initiation-Secret", "initiationValue": EL_INITIATION_VALUE,
                       "tools": "Authorization", "toolsValue": "Bearer " + EL_TOOL_BEARER},
           "limits": {"postCallBytes": 1048576, "initiationBytes": 8192, "toolBytes": 8192, "toolRequestsPerCall": 20}},
    decisions={
        "DF-E1": "Toda firma que no sea ok (missing, malformed, expired, from_future, mismatch) es «firma mala»: 401, sin leer el cuerpo.",
        "DF-E2": "La clave de deduplicación de post-llamada es (elevenlabs, <type>, data.conversation_id).",
        "DF-E3": "Un JSON no válido o sin type, con la firma buena, es «tipo desconocido válido»: 200 y se ignora (nunca un 4xx en bucle).",
        "DF-E4": "Un webhook sobre una llamada en estado final registra el evento y no cambia el estado.",
        "DF-E5": "Una post-llamada de una conversación sin fila en voice_calls es unknown_provider_call y abre el freno (R-2).",
        "DF-E6": "Iniciación autenticada pero con el cuerpo mal formado: 200 con caller_authorized = no (denegación explícita, no depender de C-A2) y sin fila (sin call_sid no hay a qué atarla).",
        "DF-E7": "La respuesta de iniciación lleva SIEMPRE las tres variables (también al denegar): ElevenLabs exige todas las del agente.",
        "DF-E8": "Esquema estricto de cada herramienta (additionalProperties = false); conversation_id y call_sid de system__* (C-A3).",
        "DF-E9": "Nombres de los veredictos de la comprobación de la llamada: call_not_found (sin fila), call_not_active (ni dialing ni active: E1-1; una entrante denegada está en denied, E1-2), voice_braked y tool_quota_exceeded.",
        "DF-E11": "Turnos de la guarda: índice 0 de data.transcript. Códigos de inspect() del motor más voice_notice_missing, voice_money_not_from_tool y voice_transfer_not_approved.",
        "DF-E12": "Iniciación con un agent_id distinto del sellado de la parte vigente: caller_authorized = no (fila denied, E1-2), evento seal_mismatch y freno.",
        "DF-E17": "La fila denied de E1-2 lleva la huella y los 4 últimos dígitos solo si caller_id es un E.164; con el caller ID oculto, los dos a null (la huella no se calcula sobre «anonymous»). El sello lo fija B8-b.",
        "DF-E13": "Iniciación para un called_number distinto de TWILIO_VOICE_FROM: caller_authorized = no.",
        "DF-E14": "Twilio canceled (marcada y cancelada antes de contestar) se concilia como failed; queued, ringing e in-progress siguen en dialing.",
        "DF-E15": "Autenticación fallida en la iniciación y en las herramientas → 401; herramienta fuera de la lista cerrada → 404; los rechazos de la comprobación de la llamada → 200 con un mensaje neutro (R-16: nunca silencio).",
    },
))


def turn(role, message, t, *, tool_calls=None, tool_results=None):
    return {"role": role, "message": message, "time_in_call_secs": t, "tool_calls": tool_calls or [],
            "tool_results": tool_results or [], "feedback": None, "conversation_turn_metrics": None}


def tool_call(name, req, typ="webhook", params="{}"):
    return {"type": typ, "tool_name": name, "request_id": req, "params_as_json": params, "tool_has_been_called": True, "tool_details": None}


def tool_result(name, req, value, typ="webhook"):
    return {"type": typ, "tool_name": name, "request_id": req, "result_value": value, "is_error": False, "tool_has_been_called": True}


HORARIOS = json.dumps({"sede": "Sede Centro", "horario": "lunes a viernes de 5:00 a 22:00; sábados de 7:00 a 14:00"}, ensure_ascii=False)
PLANES = json.dumps({"planes": [{"nombre": "Mensual", "condiciones": "Acceso a la sede, sin permanencia", "precio": "$ 89.000"}]}, ensure_ascii=False)

TRANSCRIPT_OK = [
    turn("agent", AI_NOTICE + " ¿En qué te puedo ayudar?", 0),
    turn("user", f"Hola, soy {SENT_NAME}, mi número es {SENT_PHONE_SPOKEN} y mi correo {SENT_EMAIL}. ¿A qué hora abren?", 9),
    turn("agent", "Déjame consultarlo.", 14, tool_calls=[tool_call("consultar_horarios", "toolreq_h8fx_0001")],
         tool_results=[tool_result("consultar_horarios", "toolreq_h8fx_0001", HORARIOS)]),
    turn("agent", "La Sede Centro abre de lunes a viernes de 5:00 a 22:00 y los sábados de 7:00 a 14:00. ¿Algo más?", 16),
    turn("user", "No, gracias.", 25),
    turn("agent", "Con gusto. Hasta luego.", 27, tool_calls=[tool_call("end_call", "toolreq_h8fx_0002", "system")]),
]


def post_call_body(conv_id, agent_id, *, ref, direction, external, duration=187, cost=412, version_id=None, branch_id=None,
                   transcript=None, termination="end_call tool was called", sid=None):
    return {
        "type": "post_call_transcription",
        "event_timestamp": NOW - 60,
        "data": {
            "agent_id": agent_id,
            "conversation_id": conv_id,
            "status": "done",
            "user_id": ref,
            "branch_id": branch_id,
            "version_id": version_id,
            "environment": "production",
            "transcript": transcript if transcript is not None else TRANSCRIPT_OK,
            "metadata": {
                "start_time_unix_secs": NOW - 60 - duration,
                "call_duration_secs": duration,
                "cost": cost,
                "termination_reason": termination,
                "phone_call": {"direction": direction, "phone_number_id": PHNUM, "agent_number": VOICE_FROM,
                               "external_number": external, "type": "twilio", "call_sid": sid or call_sid(1)},
            },
            "analysis": {"call_successful": "success", "transcript_summary": SENT_SUMMARY,
                         "evaluation_criteria_results": {}, "data_collection_results": {}},
            "conversation_initiation_client_data": {"dynamic_variables": {"caller_authorized": "si", "call_ref": ref,
                                                                          "version_label": VERSION_LABEL,
                                                                          "system__caller_id": external, "system__call_sid": sid or call_sid(1)}},
        },
    }


def raw(o, *, ascii_escape=False):
    if ascii_escape:
        return json.dumps(o)                       # separadores de Python por defecto y ñ: otra serialización válida
    return json.dumps(o, ensure_ascii=False, separators=(",", ":"))


SENTINELS_PC = [SENT_NAME, SENT_PHONE_SPOKEN, SENT_EMAIL, SENT_SUMMARY, CANARY_A, "573000000031", "¿A qué hora abren?"]
BODIES = {}     # nombre → cuerpo crudo, para los vectores de firma (sec_eleven_sig.py)


def PC(desc, key, body_obj, given, exp, *, ref="D8-13, D8-16", basis="contrato", decision=None, ascii_escape=False, sentinels=None):
    rb = raw(body_obj, ascii_escape=ascii_escape)
    BODIES[key] = rb
    fields = {"bodyKey": key, "given": given, "rawBody": rb}
    if sentinels is None:
        sentinels = [s for s in SENTINELS_PC if s in rb]
    for s in sentinels:
        assert s in rb, (desc, s)
    exp = dict(exp)
    exp["mustNotPersist"] = sentinels
    EB.add("postCall", body_obj.get("type", "sin-type") if isinstance(body_obj, dict) else "raro", desc, ref, basis, fields, exp,
           decision=decision)


def dialing(conv_id, ref, agent=AGENT_CANARY, sid=None, direction="outbound", status="dialing"):
    return {"call": {"direction": direction, "status": status, "providerConversationId": conv_id, "providerCallSid": sid or call_sid(1),
                     "callRef": ref, "sealedAgentId": agent, "callerAuthorized": "si"}}


# 1. Saliente contestada y completada
PC("saliente a un canario, contestada y con herramientas (ya active por E1-1): active → completed con su duración, sello verificado", "pc_completed",
   post_call_body(conv(1), AGENT_CANARY, ref=call_ref(1), direction="outbound", external=CANARY_A),
   dialing(conv(1), call_ref(1), status="active"),
   {"http": 200, "envelopeType": "post_call_transcription",
    "events": [{"provider": "elevenlabs", "kind": "post_call_transcription", "providerEventKey": conv(1)},
               {"provider": "elevenlabs", "kind": "guard_verdict", "providerEventKey": conv(1)}],
    "transition": {"from": "active", "to": "completed"}, "durationSecs": 187,
    "seal": {"observedAgentId": AGENT_CANARY, "observedVersionId": None, "observedBranchId": None, "sealVerified": True},
    "costRaw": 412, "costUnits": "por confirmar (D8-15)", "guard": {"violations": [], "brakeOpens": False}, "brakeOpens": False})
# 2. Entrante del canario (la misma forma, otra serialización)
_pc_in = post_call_body(conv(2), AGENT_CANARY, ref=call_ref(2), direction="inbound", external=CANARY_A, duration=95, cost=210, sid=call_sid(2))
PC("entrante de un canario: active → completed; serializado con \\u00f1 y separadores con espacio (se firma el crudo)", "pc_inbound",
   _pc_in, dialing(conv(2), call_ref(2), sid=call_sid(2), direction="inbound", status="active"),
   {"http": 200, "envelopeType": "post_call_transcription",
    "events": [{"provider": "elevenlabs", "kind": "post_call_transcription", "providerEventKey": conv(2)},
               {"provider": "elevenlabs", "kind": "guard_verdict", "providerEventKey": conv(2)}],
    "transition": {"from": "active", "to": "completed"}, "durationSecs": 95,
    "seal": {"observedAgentId": AGENT_CANARY, "observedVersionId": None, "observedBranchId": None, "sealVerified": True},
    "costRaw": 210, "costUnits": "por confirmar (D8-15)", "guard": {"violations": [], "brakeOpens": False}, "brakeOpens": False},
   ascii_escape=True, sentinels=[SENT_NAME, SENT_PHONE_SPOKEN, SENT_EMAIL, CANARY_A, "573000000031"])
# 2 bis. Saliente contestada sin ninguna herramienta: sigue en dialing hasta la post-llamada
TRANSCRIPT_NO_TOOLS = [turn("agent", AI_NOTICE, 0), turn("user", "No me interesa, gracias.", 7),
                       turn("agent", "Entendido. Que tengas un buen día.", 9, tool_calls=[tool_call("end_call", "toolreq_h8fx_0401", "system")])]
PC("saliente contestada sin herramientas (sigue en dialing): dialing → completed", "pc_completed_no_tools",
   post_call_body(conv(18), AGENT_CANARY, ref=call_ref(18), direction="outbound", external=CANARY_A, sid=call_sid(18), duration=12, cost=30,
                  transcript=TRANSCRIPT_NO_TOOLS),
   dialing(conv(18), call_ref(18), sid=call_sid(18)),
   {"http": 200, "envelopeType": "post_call_transcription",
    "events": [{"provider": "elevenlabs", "kind": "post_call_transcription", "providerEventKey": conv(18)},
               {"provider": "elevenlabs", "kind": "guard_verdict", "providerEventKey": conv(18)}],
    "transition": {"from": "dialing", "to": "completed"}, "durationSecs": 12,
    "seal": {"observedAgentId": AGENT_CANARY, "observedVersionId": None, "observedBranchId": None, "sealVerified": True},
    "costRaw": 30, "costUnits": "por confirmar (D8-15)", "guard": {"violations": [], "brakeOpens": False}, "brakeOpens": False},
   ref="D8-13, E1-1", sentinels=[CANARY_A, "573000000031"])
# 3. Agente observado distinto del sellado
PC("seal_mismatch: el agente observado no es el sellado → evento y freno (D8-8, D8-19)", "pc_seal_mismatch",
   post_call_body(conv(3), AGENT_OLD, ref=call_ref(3), direction="outbound", external=CANARY_A, sid=call_sid(3)),
   dialing(conv(3), call_ref(3), sid=call_sid(3)),
   {"http": 200, "envelopeType": "post_call_transcription",
    "events": [{"provider": "elevenlabs", "kind": "post_call_transcription", "providerEventKey": conv(3)},
               {"provider": "elevenlabs", "kind": "seal_mismatch", "providerEventKey": conv(3)},
               {"provider": "elevenlabs", "kind": "guard_verdict", "providerEventKey": conv(3)}],
    "transition": {"from": "dialing", "to": "completed"}, "durationSecs": 187,
    "seal": {"observedAgentId": AGENT_OLD, "observedVersionId": None, "observedBranchId": None, "sealVerified": False},
    "costRaw": 412, "costUnits": "por confirmar (D8-15)", "guard": {"violations": [], "brakeOpens": False}, "brakeOpens": True},
   ref="D8-8, D8-19")
# 4. version_id no nulo con P-2 (sin versionado nativo)
PC("el mismo agente pero con version_id y branch_id: se guardan tal cual y el sello verifica el agente (Q-7: ¿es deriva?)", "pc_version",
   post_call_body(conv(4), AGENT_CANARY, ref=call_ref(4), direction="outbound", external=CANARY_A, sid=call_sid(4),
                  version_id="agtvrsn_h8fx_0001", branch_id="agtbrch_h8fx_0001"),
   dialing(conv(4), call_ref(4), sid=call_sid(4)),
   {"http": 200, "envelopeType": "post_call_transcription",
    "events": [{"provider": "elevenlabs", "kind": "post_call_transcription", "providerEventKey": conv(4)},
               {"provider": "elevenlabs", "kind": "guard_verdict", "providerEventKey": conv(4)}],
    "transition": {"from": "dialing", "to": "completed"}, "durationSecs": 187,
    "seal": {"observedAgentId": AGENT_CANARY, "observedVersionId": "agtvrsn_h8fx_0001", "observedBranchId": "agtbrch_h8fx_0001",
             "sealVerified": True},
    "costRaw": 412, "costUnits": "por confirmar (D8-15)", "guard": {"violations": [], "brakeOpens": False}, "brakeOpens": False},
   ref="D8-8, P-2")
# 5. Llamada que Laravel no conoce
PC("conversación sin fila en voice_calls (iniciada fuera de Laravel): unknown_provider_call y freno", "pc_unknown",
   post_call_body(conv(5), AGENT_ROGUE, ref=call_ref(5), direction="outbound", external=STRANGER, sid=call_sid(5)),
   {"call": None},
   {"http": 200, "envelopeType": "post_call_transcription",
    "events": [{"provider": "elevenlabs", "kind": "unknown_provider_call", "providerEventKey": conv(5)}],
    "transition": None, "brakeOpens": True, "guard": None},
   ref="D8-13, D8-19", basis="decision-fixture", decision="DF-E5", sentinels=[STRANGER, SENT_PHONE_SPOKEN])
# 6. Duplicado
PC("duplicado: la misma conversación y el mismo tipo otra vez → 200 y nada nuevo", "pc_duplicate",
   post_call_body(conv(1), AGENT_CANARY, ref=call_ref(1), direction="outbound", external=CANARY_A),
   {"call": {"direction": "outbound", "status": "completed", "providerConversationId": conv(1), "providerCallSid": call_sid(1),
             "callRef": call_ref(1), "sealedAgentId": AGENT_CANARY, "callerAuthorized": "si"},
    "existingEvents": [{"provider": "elevenlabs", "kind": "post_call_transcription", "providerEventKey": conv(1)}]},
   {"http": 200, "envelopeType": "post_call_transcription", "events": [], "transition": None, "brakeOpens": False, "duplicate": True},
   ref="D8-13, D8-16", basis="decision-fixture", decision="DF-E2")
# 7. Llegada tardía sobre una llamada colgada por el corte de emergencia
PC("la post-llamada llega después del corte (hung_up): se registra y el estado final no cambia", "pc_after_hangup",
   post_call_body(conv(6), AGENT_CANARY, ref=call_ref(6), direction="outbound", external=CANARY_A, sid=call_sid(6),
                  termination="Call ended by remote party", duration=41, cost=90),
   {"call": {"direction": "outbound", "status": "hung_up", "providerConversationId": conv(6), "providerCallSid": call_sid(6),
             "callRef": call_ref(6), "sealedAgentId": AGENT_CANARY, "callerAuthorized": "si"}},
   {"http": 200, "envelopeType": "post_call_transcription",
    "events": [{"provider": "elevenlabs", "kind": "post_call_transcription", "providerEventKey": conv(6)},
               {"provider": "elevenlabs", "kind": "guard_verdict", "providerEventKey": conv(6)}],
    "transition": None, "statusAfter": "hung_up", "durationSecs": 41,
    "seal": {"observedAgentId": AGENT_CANARY, "observedVersionId": None, "observedBranchId": None, "sealVerified": True},
    "costRaw": 90, "costUnits": "por confirmar (D8-15)", "guard": {"violations": [], "brakeOpens": False}, "brakeOpens": False},
   basis="decision-fixture", decision="DF-E4")
# 7 bis. Entrante denegada (E1-2): la post-llamada llega y la fila sigue en denied
TRANSCRIPT_DENIED = [turn("agent", AI_NOTICE, 0), turn("agent", UNAVAILABLE, 6, tool_calls=[tool_call("end_call", "toolreq_h8fx_0301", "system")])]
PC("entrante denegada (fila denied, E1-2): la post-llamada se registra y el estado final no cambia; sin freno", "pc_denied",
   post_call_body(conv(22), AGENT_CANARY, ref=call_ref(22), direction="inbound", external=STRANGER, sid=call_sid(22), duration=9, cost=20,
                  transcript=TRANSCRIPT_DENIED),
   {"call": {"direction": "inbound", "status": "denied", "providerConversationId": conv(22), "providerCallSid": call_sid(22),
             "callRef": call_ref(22), "sealedAgentId": AGENT_CANARY, "callerAuthorized": "no"}},
   {"http": 200, "envelopeType": "post_call_transcription",
    "events": [{"provider": "elevenlabs", "kind": "post_call_transcription", "providerEventKey": conv(22)},
               {"provider": "elevenlabs", "kind": "guard_verdict", "providerEventKey": conv(22)}],
    "transition": None, "statusAfter": "denied", "durationSecs": 9,
    "seal": {"observedAgentId": AGENT_CANARY, "observedVersionId": None, "observedBranchId": None, "sealVerified": True},
    "costRaw": 20, "costUnits": "por confirmar (D8-15)", "guard": {"violations": [], "brakeOpens": False}, "brakeOpens": False},
   ref="D8-13, E1-2", basis="decision-fixture", decision="DF-E4", sentinels=[STRANGER, "573000000077"])
# 8. Audio: se descarta
PC("post_call_audio: 200, se descarta y queda el evento post_call_audio_discarded; el audio no se guarda", "pc_audio",
   {"type": "post_call_audio", "event_timestamp": NOW - 30, "data": {"agent_id": AGENT_CANARY, "conversation_id": conv(1), "full_audio": AUDIO_B64}},
   dialing(conv(1), call_ref(1)),
   {"http": 200, "envelopeType": "post_call_audio",
    "events": [{"provider": "elevenlabs", "kind": "post_call_audio_discarded", "providerEventKey": conv(1)}],
    "transition": None, "brakeOpens": False},
   ref="D8-16, D8-17", sentinels=[AUDIO_B64])


# 9-11. Fallo al iniciar la saliente (busy, no-answer, unknown)
def init_failure(conv_id, reason, sid, twilio_status):
    return {"type": "call_initiation_failure", "event_timestamp": NOW - 20,
            "data": {"agent_id": AGENT_CANARY, "conversation_id": conv_id, "failure_reason": reason,
                     "metadata": {"type": "twilio", "body": {"CallSid": sid, "AccountSid": TWILIO_SUBACCOUNT_SID, "From": VOICE_FROM,
                                                             "To": CANARY_A, "CallStatus": twilio_status, "Direction": "outbound-api",
                                                             "ApiVersion": "2010-04-01", "CallDuration": "0", "SipResponseCode": "486"}}}}


for n, reason, tw_status, to_status in [(7, "busy", "busy", "busy"), (8, "no-answer", "no-answer", "no_answer"), (9, "unknown", "failed", "failed")]:
    PC(f"call_initiation_failure = {reason}: dialing → {to_status}", f"pc_fail_{to_status}", init_failure(conv(n), reason, call_sid(n), tw_status),
       dialing(conv(n), call_ref(n), sid=call_sid(n)),
       {"http": 200, "envelopeType": "call_initiation_failure",
        "events": [{"provider": "elevenlabs", "kind": "call_initiation_failure", "providerEventKey": conv(n)}],
        "transition": {"from": "dialing", "to": to_status}, "brakeOpens": False},
       ref="D8-13", sentinels=[CANARY_A])
# 12. El fallo de inicio llega antes de que el job escriba dialing
PC("call_initiation_failure antes de que el job guarde dialing: se casa por user_id/call_ref y requested → busy", "pc_fail_race",
   dict(init_failure(conv(10), "busy", call_sid(10), "busy"), data=dict(init_failure(conv(10), "busy", call_sid(10), "busy")["data"], user_id=call_ref(10))),
   {"call": {"direction": "outbound", "status": "requested", "providerConversationId": None, "providerCallSid": None,
             "callRef": call_ref(10), "sealedAgentId": AGENT_CANARY, "callerAuthorized": "si"}},
   {"http": 200, "envelopeType": "call_initiation_failure",
    "events": [{"provider": "elevenlabs", "kind": "call_initiation_failure", "providerEventKey": conv(10)}],
    "transition": {"from": "requested", "to": "busy"}, "brakeOpens": False,
    "thenJobWritesDialing": "rechazado: busy es final (state-transitions.json)"},
   ref="D8-13", basis="decision-fixture", decision="DF-S2", sentinels=[CANARY_A])
# 13-14. Tipo desconocido y JSON roto, con la firma buena
PC("tipo desconocido con la firma buena: 200 y se ignora (D8-16)", "pc_unknown_type",
   {"type": "post_call_summary", "event_timestamp": NOW - 10, "data": {"agent_id": AGENT_CANARY, "conversation_id": conv(1)}},
   dialing(conv(1), call_ref(1)), {"http": 200, "envelopeType": None, "events": [], "transition": None, "brakeOpens": False}, ref="D8-16")
BODIES["pc_broken_json"] = '{"type":"post_call_transcription","data":{"agent_id":"%s"' % AGENT_CANARY
EB.add("postCall", "raro", "JSON no válido con la firma buena: 200 y se ignora (nunca un 4xx en bucle)", "D8-16", "decision-fixture",
       {"bodyKey": "pc_broken_json", "given": dialing(conv(1), call_ref(1)), "rawBody": BODIES["pc_broken_json"]},
       {"http": 200, "envelopeType": None, "events": [], "transition": None, "brakeOpens": False, "mustNotPersist": []}, decision="DF-E3")


# ── guard: la guarda posterior en memoria (D8-10 capa 4; P-3 según la congelación) ─────────────────────────────
def G(desc, transcript, exp, *, basis="contrato", decision="DF-E11", ref="D8-10", profile_destinations=None):
    fields = {"transcript": transcript, "profileTransferAliases": profile_destinations or ["Recepción"]}
    EB.add("guard", "inspect", desc, ref, basis, fields, exp, decision=decision)


ASK_PRICE = turn("user", "¿Cuánto vale el plan mensual?", 8)
PRICE_TOOL = turn("agent", "Lo consulto.", 10, tool_calls=[tool_call("consultar_planes", "toolreq_h8fx_0101")],
                  tool_results=[tool_result("consultar_planes", "toolreq_h8fx_0101", PLANES)])
G("sin violaciones: aviso en el primer turno, hechos de la herramienta", TRANSCRIPT_OK, {"violations": [], "brakeOpens": False, "storesText": False})
G("P-3: el precio dicho es exactamente el que devolvió consultar_planes en esa llamada → no es violación",
  [turn("agent", AI_NOTICE, 0), ASK_PRICE, PRICE_TOOL, turn("agent", "El plan Mensual cuesta $ 89.000 al mes, sin permanencia.", 12)],
  {"violations": [], "brakeOpens": False, "storesText": False,
   "note": "inspect() del motor da machine_reply_invented_price ante cualquier cifra: el adaptador de voz descuenta las que devolvió la herramienta"},
  basis="supuesto-lead", ref="D8-10, P-3")
G("P-3: una cifra distinta de la de la herramienta → violación y freno",
  [turn("agent", AI_NOTICE, 0), ASK_PRICE, PRICE_TOOL, turn("agent", "El plan Mensual te queda en $ 79.000.", 12)],
  {"violations": [{"turn": 3, "code": "voice_money_not_from_tool"}], "brakeOpens": True, "storesText": False},
  basis="supuesto-lead", ref="D8-10, P-3, D8-19")
G("P-3: una cifra sin haber llamado a la herramienta → violación",
  [turn("agent", AI_NOTICE, 0), ASK_PRICE, turn("agent", "Cuesta 89.000 pesos.", 9)],
  {"violations": [{"turn": 2, "code": "voice_money_not_from_tool"}], "brakeOpens": True, "storesText": False},
  basis="supuesto-lead", ref="D8-10, P-3, D8-19")
G("vocabulario de descuento (R-02) en un turno del agente → la guarda del motor lo marca",
  [turn("agent", AI_NOTICE, 0), turn("user", "¿Hay algo gratis?", 5), turn("agent", "Sí, la primera semana es gratis.", 7)],
  {"violations": [{"turn": 2, "code": "machine_reply_invented_discount"}], "brakeOpens": True, "storesText": False}, ref="D8-10, R-02, D8-19")
G("una URL en un turno del agente → machine_reply_url",
  [turn("agent", AI_NOTICE, 0), turn("agent", "Puedes ver todo en www.ironbody.co/planes", 6)],
  {"violations": [{"turn": 1, "code": "machine_reply_url"}], "brakeOpens": True, "storesText": False}, ref="D8-10, R-03, D8-19")
G("el primer turno del agente no lleva el aviso [P-9] → voice_notice_missing",
  [turn("agent", "¡Hola! ¿En qué te ayudo?", 0), turn("user", "Hola", 3)],
  {"violations": [{"turn": 0, "code": "voice_notice_missing"}], "brakeOpens": True, "storesText": False}, ref="D8-10, D8-17, P-9")
G("transferencia a un destino que no es del perfil → voice_transfer_not_approved (imposible por configuración, se comprueba igual)",
  [turn("agent", AI_NOTICE, 0), turn("agent", "Te comunico.", 6, tool_calls=[tool_call("transfer_to_number", "toolreq_h8fx_0201", "system",
                                                                                     json.dumps({"transfer_number": "+573000000099"}))])],
  {"violations": [{"turn": 1, "code": "voice_transfer_not_approved"}], "brakeOpens": True, "storesText": False}, ref="D8-10, D8-14, R-07")
G("los turnos del usuario no se juzgan: «gratis» y un teléfono en boca de la persona no son violación",
  [turn("agent", AI_NOTICE, 0), turn("user", f"Me dijeron que era gratis, llámame al {SENT_PHONE_SPOKEN}", 5), turn("agent", "Te cuento las condiciones del plan.", 8)],
  {"violations": [], "brakeOpens": False, "storesText": False}, ref="D8-10")


# ── initiation: webhook de iniciación de las entrantes (D8-11) ──────────────────────────────────────────────
def I(desc, body, given, exp, *, basis="contrato", decision=None, ref="D8-11", auth=True, raw_override=None):
    fields = {"headers": {"X-Voice-Initiation-Secret": EL_INITIATION_VALUE if auth is True else auth},
              "given": given, "rawBody": raw_override if raw_override is not None else raw(body)}
    EB.add("initiation", "initiate", desc, ref, basis, fields, exp, decision=decision)


def init_body(caller, sid, conv_id, agent=AGENT_CANARY, called=VOICE_FROM):
    return {"caller_id": caller, "agent_id": agent, "called_number": called, "call_sid": sid, "conversation_id": conv_id}


ALL_OPEN = {"inboundEnabled": True, "callsEnabledInCode": True, "braked": False, "publication": "ok", "scope": "canary",
            "sealedAgentId": AGENT_CANARY, "activeOrDialing": 0, "maxConcurrent": 1, "inboundToday": 0, "inboundDailyCap": 3}
DENY_SHAPE = {"type": "conversation_initiation_client_data", "dynamic_variables": {"caller_authorized": "no", "call_ref": "<uuid>", "version_label": "<texto>"}}
OK_SHAPE = {"type": "conversation_initiation_client_data", "dynamic_variables": {"caller_authorized": "si", "call_ref": "<uuid>", "version_label": VERSION_LABEL}}
FORBIDDEN_KEYS = ["conversation_config_override", "caller_id", "called_number", "user_id", "nombre", "telefono"]


def iexp(authorized, *, http=200, events=("initiation_received",), row=None, brake=False, reasons=None, note=None):
    e = {"http": http}
    if http == 200:
        e["response"] = OK_SHAPE if authorized else DENY_SHAPE
        e["responseMustNotContainKeys"] = FORBIDDEN_KEYS
        e["responseMustNotContain"] = [CANARY_A, STRANGER, "573000000031", "573000000077"]
    e["events"] = list(events)
    e["voiceCallRow"] = row
    e["brakeOpens"] = brake
    if reasons is not None:
        e["policyReasons"] = reasons
    if note:
        e["note"] = note
    return e


def denied_row(caller, sid):
    e164 = caller if re.fullmatch(r"\+[1-9][0-9]{7,14}", caller) else None
    return {"status": "denied", "direction": "inbound", "providerCallSid": sid, "callerAuthorized": "no",
            "remoteHash": phone_hash(e164) if e164 else None, "remoteLast4": e164[-4:] if e164 else None}


I("canario de voz activo, todo abierto → si, fila active idempotente por call_sid", init_body(CANARY_A, call_sid(21), conv(21)),
  {"policy": ALL_OPEN, "caller": "active_voice"},
  iexp(True, row={"status": "active", "direction": "inbound", "providerCallSid": call_sid(21), "callerAuthorized": "si", "remoteLast4": "0031",
                  "remoteHash": phone_hash(CANARY_A), "part": "canary", "stampState": "applied"}, reasons=[]))
I("número no registrado → no y fila denied (E1-2): «línea no disponible» y end_call (lo hace el agente)", init_body(STRANGER, call_sid(22), conv(22)),
  {"policy": ALL_OPEN, "caller": "not_registered"}, iexp(False, reasons=["not_an_active_voice_canary"], row=denied_row(STRANGER, call_sid(22))),
  ref="D8-11, E1-2")
I("caller ID oculto (anonymous) → no y fila denied sin huella ni últimos dígitos", init_body("anonymous", call_sid(23), conv(23)),
  {"policy": ALL_OPEN, "caller": "withheld"},
  iexp(False, reasons=["not_an_active_voice_canary"], row=denied_row("anonymous", call_sid(23))), basis="decision-fixture", decision="DF-E17",
  ref="D8-11, E1-2")
I("la misma iniciación dos veces (mismo call_sid) → la misma respuesta y una sola fila", init_body(CANARY_A, call_sid(21), conv(21)),
  {"policy": ALL_OPEN, "caller": "active_voice", "existingRowForCallSid": True},
  iexp(True, events=(), row="la existente (sin fila nueva)", reasons=[], note="call_ref igual al de la primera respuesta"))
I("freno abierto → no aunque sea canario, con fila denied", init_body(CANARY_A, call_sid(24), conv(24)), {"policy": dict(ALL_OPEN, braked=True), "caller": "active_voice"},
  iexp(False, reasons=["voice_braked"], row=denied_row(CANARY_A, call_sid(24))), ref="D8-11, D8-19, E1-2")
I("agent_id distinto del sellado de la parte vigente → no (fila denied), seal_mismatch y freno", init_body(CANARY_A, call_sid(25), conv(25), agent=AGENT_OLD),
  {"policy": ALL_OPEN, "caller": "active_voice"},
  iexp(False, events=("initiation_received", "seal_mismatch"), brake=True, row=denied_row(CANARY_A, call_sid(25))),
  basis="decision-fixture", decision="DF-E12")
I("called_number que no es TWILIO_VOICE_FROM → no, con fila denied", init_body(CANARY_A, call_sid(26), conv(26), called="+576010000091"),
  {"policy": ALL_OPEN, "caller": "active_voice"}, iexp(False, row=denied_row(CANARY_A, call_sid(26))), basis="decision-fixture", decision="DF-E13")
I("autenticada pero sin call_sid → 200 y no (denegación explícita), sin fila", {"caller_id": CANARY_A, "agent_id": AGENT_CANARY, "called_number": VOICE_FROM},
  {"policy": ALL_OPEN, "caller": "active_voice"}, iexp(False, events=()), basis="decision-fixture", decision="DF-E6")
I("secreto de la cabecera equivocado → 401 sin cuerpo útil ni fila (el agente se queda en caller_authorized = no por defecto, C-A2)",
  init_body(CANARY_A, call_sid(27), conv(27)), {"policy": ALL_OPEN, "caller": "active_voice"},
  iexp(False, http=401, events=()), auth="h8fixture-initiation-0000000000000000000009", ref="D8-11, D8-16",
  basis="decision-fixture", decision="DF-E15")
I("sin la cabecera del secreto → 401", init_body(CANARY_A, call_sid(28), conv(28)), {"policy": ALL_OPEN, "caller": "active_voice"},
  iexp(False, http=401, events=()), auth=None, ref="D8-16", basis="decision-fixture", decision="DF-E15")
I("el secreto de las herramientas en la cabecera de iniciación → 401 (tres secretos distintos, D8-5)", init_body(CANARY_A, call_sid(29), conv(29)),
  {"policy": ALL_OPEN, "caller": "active_voice"}, iexp(False, http=401, events=()), auth=EL_TOOL_BEARER, ref="D8-5, D8-16",
  basis="decision-fixture", decision="DF-E15")


# ── tools: POST /api/voice/tools/{tool} (D8-10, E1-1) ───────────────────────────────────────────────────────
TOOLS = ["consultar_planes", "consultar_horarios", "solicitar_humano", "no_volver_a_llamar"]
LIVE = {"status": "dialing", "direction": "outbound", "providerConversationId": conv(31), "providerCallSid": call_sid(31),
        "callerAuthorized": "si", "toolRequests": 0, "braked": False, "remoteHash": phone_hash(CANARY_A)}
ACTIVE_OUT = dict(LIVE, status="active", toolRequests=1)


def T(desc, tool, body, exp, *, call=LIVE, auth="Bearer " + EL_TOOL_BEARER, basis="contrato", decision=None, ref="D8-10, D8-16",
      extra_given=None, raw_override=None):
    given = {"call": call}
    if extra_given:
        given.update(extra_given)
    fields = {"tool": tool, "headers": {"Authorization": auth}, "given": given,
              "rawBody": raw_override if raw_override is not None else raw(body)}
    EB.add("tools", tool, desc, ref, basis, fields, exp, decision=decision)


def ids(c=31):
    return {"conversation_id": conv(c), "call_sid": call_sid(c)}


T("la primera herramienta de una saliente en dialing responde y la pasa a active (E1-1)", "consultar_horarios", ids(),
  {"http": 200, "verdict": "ok", "events": ["tool_call"], "transition": {"from": "dialing", "to": "active"},
   "responseMustNotContain": [CANARY_A, "573000000031"]}, ref="D8-10, E1-1")
T("una herramienta en una saliente ya active responde sin transición", "consultar_horarios", ids(),
  {"http": 200, "verdict": "ok", "events": ["tool_call"], "transition": None}, call=ACTIVE_OUT, ref="D8-10, E1-1")
T("una herramienta en una entrante active (canario autorizado) responde sin transición", "consultar_horarios", ids(),
  {"http": 200, "verdict": "ok", "events": ["tool_call"], "transition": None}, call=dict(LIVE, status="active", direction="inbound"),
  ref="D8-10, E1-1")
T("consultar_planes → nombres y condiciones del catálogo vigente y, por P-3, el precio exacto de la base", "consultar_planes", ids(),
  {"http": 200, "verdict": "ok", "events": ["tool_call"], "priceSource": "Plan::sellable()", "neverComputedOrDiscounted": True},
  call=ACTIVE_OUT, basis="supuesto-lead", ref="D8-10, P-3")
T("solicitar_humano en horario del personal → disponible y el alias, nunca el número", "solicitar_humano", ids(),
  {"http": 200, "verdict": "ok", "available": True, "alias": "Recepción", "responseMustNotContain": [DEST_RECEPCION, "576010000011"]},
  call=ACTIVE_OUT, extra_given={"staffHoursNow": True, "profileTransferAliases": ["Recepción"]}, ref="D8-10, D8-14")
T("solicitar_humano fuera del horario del personal → no disponible", "solicitar_humano", ids(),
  {"http": 200, "verdict": "ok", "available": False, "alias": None},
  call=ACTIVE_OUT, extra_given={"staffHoursNow": False, "profileTransferAliases": ["Recepción"]}, ref="D8-10, D8-11, D8-14")
T("no_volver_a_llamar → exclusión inmediata spoken_opt_out para el número de LA LLAMADA, sin confirmación del LLM", "no_volver_a_llamar", ids(),
  {"http": 200, "verdict": "ok", "events": ["tool_call"],
   "sideEffects": [{"table": "voice_exclusions", "source": "spoken_opt_out", "remoteHash": phone_hash(CANARY_A)}]},
  call=ACTIVE_OUT, ref="D8-10, D8-18 (Ley 2300)")
T("no_volver_a_llamar con un teléfono en el cuerpo → 422 por el esquema estricto y ninguna exclusión de ese número", "no_volver_a_llamar",
  dict(ids(), telefono="+573000000099"),
  {"http": 422, "verdict": "invalid_schema", "sideEffects": [], "mustNotCreateExclusionFor": [phone_hash("+573000000099")]},
  call=ACTIVE_OUT, basis="decision-fixture", decision="DF-E8")
T("Bearer equivocado → 401", "consultar_horarios", ids(), {"http": 401, "verdict": "unauthorized", "events": []},
  auth="Bearer h8fixture-tool-bearer-0000000000000000000009", basis="decision-fixture", decision="DF-E15")
T("sin Authorization → 401", "consultar_horarios", ids(), {"http": 401, "verdict": "unauthorized", "events": []}, auth=None,
  basis="decision-fixture", decision="DF-E15")
T("el secreto de iniciación como Bearer → 401 (tres secretos distintos)", "consultar_horarios", ids(),
  {"http": 401, "verdict": "unauthorized", "events": []}, auth="Bearer " + EL_INITIATION_VALUE, ref="D8-5, D8-10",
  basis="decision-fixture", decision="DF-E15")
T("herramienta fuera de la lista cerrada (aplicar_descuento) → 404", "aplicar_descuento", ids(), {"http": 404, "verdict": "unknown_tool", "events": []},
  basis="decision-fixture", decision="DF-E15")
T("conversation_id que no existe en voice_calls → rechazada, sin evento (no hay llamada a la que atarlo)", "consultar_horarios", ids(99),
  {"http": 200, "verdict": "call_not_found", "neutralMessage": True, "events": []}, call=None,
  basis="decision-fixture", decision="DF-E9")
T("la llamada ya terminó (completed) → rechazada", "consultar_horarios", ids(),
  {"http": 200, "verdict": "call_not_active", "neutralMessage": True, "events": ["tool_call"]}, call=dict(LIVE, status="completed"),
  basis="decision-fixture", decision="DF-E9", ref="D8-10, E1-1")
T("la saliente todavía requested (sin conversación) → rechazada", "consultar_horarios", ids(),
  {"http": 200, "verdict": "call_not_active", "neutralMessage": True, "events": ["tool_call"]}, call=dict(LIVE, status="requested"),
  basis="decision-fixture", decision="DF-E9", ref="D8-10, E1-1")
T("entrante denegada (fila denied, E1-2) → ninguna herramienta responde (C-A2)", "consultar_planes", ids(),
  {"http": 200, "verdict": "call_not_active", "neutralMessage": True, "events": ["tool_call"]},
  call=dict(LIVE, status="denied", direction="inbound", callerAuthorized="no"), basis="decision-fixture", decision="DF-E9",
  ref="D8-10, D8-11, E1-1, E1-2")
T("freno abierto → rechazada", "consultar_planes", ids(), {"http": 200, "verdict": "voice_braked", "neutralMessage": True, "events": ["tool_call"]},
  call=dict(ACTIVE_OUT, braked=True), basis="decision-fixture", decision="DF-E9", ref="D8-10, D8-19")
T("la petición 20 de la llamada todavía vale", "consultar_horarios", ids(), {"http": 200, "verdict": "ok", "events": ["tool_call"], "transition": None},
  call=dict(ACTIVE_OUT, toolRequests=19))
T("la petición 21 de la llamada → cupo agotado", "consultar_horarios", ids(),
  {"http": 200, "verdict": "tool_quota_exceeded", "neutralMessage": True, "events": ["tool_call"]}, call=dict(ACTIVE_OUT, toolRequests=20),
  basis="decision-fixture", decision="DF-E9")
T("un parámetro que el esquema no conoce (el LLM intenta salir de su llamada) → 422", "consultar_horarios",
  dict(ids(), conversation_id_override=conv(32)), {"http": 422, "verdict": "invalid_schema", "events": []}, call=ACTIVE_OUT,
  basis="decision-fixture", decision="DF-E8")
T("cuerpo que no es JSON → 422", "consultar_horarios", None, {"http": 422, "verdict": "invalid_schema", "events": []},
  call=ACTIVE_OUT, raw_override="conversation_id=conv_h8fx_0031", basis="decision-fixture", decision="DF-E8")


# ── routeRules: lo que D8-16 fija para las rutas públicas (tabla para las pruebas Feature) ───────────────────
def R(desc, route, scenario, http, *, basis="contrato", decision=None, ref="D8-16", note=None):
    exp = {"http": http}
    if note:
        exp["note"] = note
    EB.add("routeRules", "http", desc, ref, basis, {"route": route, "scenario": scenario}, exp, decision=decision)


PCR, INR, TLR = "POST /api/webhooks/voice/elevenlabs/post-call", "POST /api/webhooks/voice/elevenlabs/initiation", "POST /api/voice/tools/{tool}"
R("post-call · cuerpo de 1 048 576 bytes: pasa el tope (después decide la firma)", PCR, {"bodyBytes": 1048576}, "no 413")
R("post-call · cuerpo de 1 048 577 bytes → 413 payload_too_large antes de mirar la firma", PCR, {"bodyBytes": 1048577}, 413,
  basis="decision-fixture", decision="DF-E1")
R("post-call · firma mala (cualquier resultado que no sea ok) → 401", PCR, {"signature": "not ok"}, 401, basis="decision-fixture", decision="DF-E1")
R("post-call · duplicado → 200", PCR, {"duplicate": True}, 200)
R("post-call · tipo desconocido válido → 200 y se ignora", PCR, {"type": "desconocido", "signature": "ok"}, 200)
R("post-call · fallo propio de la base → 5xx para que ElevenLabs reintente", PCR, {"dbFailure": True}, "5xx")
R("post-call · post_call_audio → 200 y se descarta", PCR, {"type": "post_call_audio", "signature": "ok"}, 200)
R("initiation · 8 192 bytes pasan; 8 193 → 413", INR, {"bodyBytes": 8193}, 413)
R("initiation · ninguna petición saliente en la ruta (Http::preventStrayRequests sin falsos)", INR, {"network": "ninguna"}, 200, ref="D8-11 (PERF8)")
R("tools · 8 193 bytes → 413", TLR, {"bodyBytes": 8193}, 413)
R("tools · {tool} fuera de la lista cerrada → 404", TLR, {"tool": "aplicar_descuento"}, 404, basis="decision-fixture", decision="DF-E8")

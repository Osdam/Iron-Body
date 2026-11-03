# twilio-webhook-bodies.json · lo que la subcuenta «Voz» de Twilio envía o devuelve (D8-15, D8-16, D8-19, D8-21)
#   usageTrigger · POST /api/webhooks/voice/twilio/usage-trigger (formulario firmado con el token de la subcuenta)
#   callStatus   · callbacks de estado de una llamada (sin ruta de Laravel en el H8: los recibe ElevenLabs y reenvía el cuerpo
#                  en call_initiation_failure.metadata.body; sirven al simulador de OPS8 y a la conciliación)
#   callsPage    · GET de la lista de llamadas de la subcuenta (TelephonyCarrier::listCalls) para la conciliación
import urllib.parse

TB = Fixture("twilio-webhook-bodies.json", header(
    "twilio-webhook-bodies.json",
    ["D8-16 (usage-trigger de la voz: X-Twilio-Signature con TwilioRequestSignature y el token de la subcuenta; idempotencia por IdempotencyToken; se persiste y puede accionar el freno)",
     "D8-19 (el freno se abre solo con un aviso de la subcuenta o con una unknown_provider_call)",
     "D8-21 (conciliación: llamadas de la subcuenta frente a voice_calls; resuelve las ambiguous y registra el precio real)",
     "D8-3 (TelephonyCarrier::listCalls, una página, solo GET)"],
    [
        {"what": "Firma de los avisos (X-Twilio-Signature)", "url": "https://www.twilio.com/docs/usage/webhooks/webhooks-security",
         "consulted": "03-11-2026 (auditoría §d.2)", "how": "Algoritmo de twilio-signature-vectors.json."},
        {"what": "Parámetros del callback de un Usage Trigger (AccountSid, UsageTriggerSid, DateFired, Recurring, UsageCategory, TriggerBy, TriggerValue, CurrentValue, UsageRecordUri, IdempotencyToken)",
         "url": "https://www.twilio.com/docs/usage/api/usage-trigger", "consulted": "NO consultado hoy (sin red)",
         "how": "Lista de la documentación tal como la conozco y la que lee el controlador del OTP (TwilioUsageTriggerController.php:39-54). [por confirmar] el formato exacto de DateFired y de IdempotencyToken; FriendlyName lo lee el OTP pero no lo doy por enviado."},
        {"what": "Recurso Call y sus estados (queued, ringing, in-progress, completed, busy, failed, no-answer, canceled); forma de la lista Calls",
         "url": "https://www.twilio.com/docs/voice/api/call-resource", "consulted": "03-11-2026 (auditoría §d.2) y 31-10-2026 (tests/Unit/Twilio/fixtures/twilio-api-responses.json, calls_week)",
         "how": "La forma de cada llamada copia la de calls_week del paquete TW del H6."},
        {"what": "Parámetros de un callback de estado (CallSid, AccountSid, From, To, CallStatus, Direction, ApiVersion, CallDuration, CallbackSource, SequenceNumber, Timestamp)",
         "url": "https://www.twilio.com/docs/voice/twiml#request-parameters", "consulted": "NO consultado hoy (sin red)", "how": "[por confirmar] la lista completa."},
    ],
    keys={"subaccountSid": TWILIO_SUBACCOUNT_SID, "subaccountSigningToken": TWILIO_SIGNING_TOKEN, "otpMasterTokenSynthetic": TWILIO_OTHER_TOKEN,
          "voiceWebhookBaseUrl": VOICE_WEBHOOK_BASE_URL},
    decisions={
        "DF-W1": "Un aviso de gasto se persiste como evento de kind usage_alert, que NO está en el conjunto cerrado de D8-13 (Q-5: el lead lo añade o elige otro).",
        "DF-W2": "Firma de Twilio mala en usage-trigger de la voz → 401 (como la post-llamada; el del OTP da 403 y no se toca).",
        "DF-W3": "Sin IdempotencyToken: se persiste y abre el freno igual, sin deduplicación (abrir el freno es idempotente).",
        "DF-W4": "Un tramo de transferencia (parent_call_sid de una llamada conocida) se concilia con su llamada madre y suma su coste; no es unknown_provider_call (Q-6).",
        "DF-W5": "Una entrante denegada en la iniciación queda reconocida por su call_sid (evento initiation_received): la conciliación no la marca como desconocida (Q-6: si no, cualquier desconocido que llame abre el freno).",
        "DF-W6": "Precio de Twilio: el valor absoluto de price (texto con signo) en micro-US$ enteros; null = todavía sin tarificar (se reintenta).",
    },
))

USAGE_PATH = "/api/webhooks/voice/twilio/usage-trigger"
USAGE_URL = VOICE_WEBHOOK_BASE_URL + USAGE_PATH


def usage_params(n, recurring, value, current, category="totalprice", token=True):
    p = [["AccountSid", TWILIO_SUBACCOUNT_SID], ["UsageTriggerSid", "UT" + "%032x" % (0xB8000000 + n)],
         ["DateFired", "Wed, 04 Nov 2026 14:%02d:00 +0000" % n], ["Recurring", recurring], ["UsageCategory", category],
         ["TriggerBy", "price"], ["TriggerValue", value], ["CurrentValue", current],
         ["UsageRecordUri", f"/2010-04-01/Accounts/{TWILIO_SUBACCOUNT_SID}/Usage/Records/Today.json?Category={category}"]]
    if token:
        p.append(["IdempotencyToken", f"{TWILIO_SUBACCOUNT_SID}-FIRES-UT{n:04d}-20261104"])
    return p


def W(desc, params, exp, *, signer=TWILIO_SIGNING_TOKEN, header_value=None, basis="contrato", decision=None, ref="D8-16, D8-19", given=None):
    sig = header_value if header_value is not None else tw_sig(signer, tw_base(USAGE_URL, params))
    fields = {"url": USAGE_URL, "requestUri": USAGE_PATH, "params": params, "rawBody": urllib.parse.urlencode([tuple(x) for x in params]),
              "headers": {"X-Twilio-Signature": sig}, "given": given or {"brakeOpen": False, "seenIdempotencyTokens": []}}
    TB.add("usageTrigger", "usageTrigger", desc, ref, basis, fields, exp, decision=decision)


P_DAILY = usage_params(1, "daily", "4.00", "4.02")
W("aviso diario de la subcuenta, bien firmado → 200, se persiste y abre el freno", P_DAILY,
  {"http": 200, "persisted": True, "eventKind": "usage_alert", "dedupeKey": P_DAILY[-1][1], "brakeOpens": True,
   "incident": "IRON GUARD (mecanismo del monitor del H7)"}, basis="decision-fixture", decision="DF-W1")
P_MONTH = usage_params(2, "monthly", "90.00", "90.40")
W("aviso mensual → igual: cualquier aviso de la subcuenta abre el freno (P-17)", P_MONTH,
  {"http": 200, "persisted": True, "eventKind": "usage_alert", "dedupeKey": P_MONTH[-1][1], "brakeOpens": True},
  basis="decision-fixture", decision="DF-W1", ref="D8-19, P-17")
W("el mismo aviso otra vez (mismo IdempotencyToken) → 200 sin evento nuevo ni otra apertura", P_DAILY,
  {"http": 200, "persisted": False, "duplicate": True, "brakeOpens": False},
  given={"brakeOpen": True, "seenIdempotencyTokens": [P_DAILY[-1][1]]})
P_NOTOK = usage_params(3, "daily", "4.00", "4.10", token=False)
W("sin IdempotencyToken → se persiste y abre el freno, sin deduplicar", P_NOTOK,
  {"http": 200, "persisted": True, "eventKind": "usage_alert", "dedupeKey": None, "brakeOpens": True}, basis="decision-fixture", decision="DF-W3")
W("firma mala (un carácter) → 401, nada persistido", P_DAILY,
  {"http": 401, "persisted": False, "brakeOpens": False}, header_value=tw_sig(TWILIO_SIGNING_TOKEN, tw_base(USAGE_URL, P_DAILY))[:-2] + "A=",
  basis="decision-fixture", decision="DF-W2")
W("OTP-2: firmado con el token maestro del OTP → 401 (solo vale el de la subcuenta)", P_DAILY,
  {"http": 401, "persisted": False, "brakeOpens": False}, signer=TWILIO_OTHER_TOKEN, basis="decision-fixture", decision="DF-W2",
  ref="D8-4, D8-16, auditoría OTP-2")
W("sin cabecera X-Twilio-Signature → 401", P_DAILY, {"http": 401, "persisted": False, "brakeOpens": False}, header_value="",
  basis="decision-fixture", decision="DF-W2")


# ── callStatus ──────────────────────────────────────────────────────────────
def status_params(n, status, duration=None, seq=0):
    p = [["CallSid", call_sid(n)], ["AccountSid", TWILIO_SUBACCOUNT_SID], ["From", VOICE_FROM], ["To", CANARY_A],
         ["CallStatus", status], ["Direction", "outbound-api"], ["ApiVersion", "2010-04-01"], ["CallbackSource", "call-progress-events"],
         ["SequenceNumber", str(seq)], ["Timestamp", "Wed, 04 Nov 2026 15:0%d:00 +0000" % seq]]
    if duration is not None:
        p.append(["CallDuration", str(duration)])
    return p


for i, (st, ours, basis, dec) in enumerate([
        ("queued", "dialing", "decision-fixture", "DF-E14"), ("ringing", "dialing", "decision-fixture", "DF-E14"),
        ("in-progress", "dialing", "decision-fixture", "DF-E14"), ("completed", "completed", "contrato", None),
        ("busy", "busy", "contrato", None), ("no-answer", "no_answer", "contrato", None), ("failed", "failed", "contrato", None),
        ("canceled", "failed", "decision-fixture", "DF-E14")]):
    params = status_params(40 + i, st, duration=187 if st == "completed" else None, seq=i)
    TB.add("callStatus", st, f"estado de Twilio {st} → voice_calls.status {ours}", "D8-13, D8-21", basis,
           {"params": params, "rawBody": urllib.parse.urlencode([tuple(x) for x in params])},
           {"ourStatus": ours, "final": ours not in ("dialing",), "mustNotPersist": [CANARY_A, VOICE_FROM]}, decision=dec)


# ── callsPage: conciliación (D8-21) ─────────────────────────────────────────
def tw_call(n, status, *, to=CANARY_A, frm=VOICE_FROM, direction="outbound-api", duration="0", price=None, parent=None):
    sid = call_sid(n)
    return {"account_sid": TWILIO_SUBACCOUNT_SID, "annotation": None, "answered_by": None, "api_version": "2010-04-01",
            "caller_name": None, "date_created": "Wed, 04 Nov 2026 14:00:00 +0000", "date_updated": "Wed, 04 Nov 2026 14:05:00 +0000",
            "direction": direction, "duration": duration, "end_time": None if status in ("queued", "ringing", "in-progress") else "Wed, 04 Nov 2026 14:05:00 +0000",
            "forwarded_from": None, "from": frm, "from_formatted": frm, "group_sid": None, "parent_call_sid": parent,
            "phone_number_sid": "PN" + "%032x" % 0xC8, "price": price, "price_unit": "USD", "queue_time": "0", "sid": sid,
            "start_time": "Wed, 04 Nov 2026 14:00:00 +0000", "status": status,
            "subresource_uris": {"recordings": f"/2010-04-01/Accounts/{TWILIO_SUBACCOUNT_SID}/Calls/{sid}/Recordings.json"},
            "to": to, "to_formatted": to, "trunk_sid": None, "uri": f"/2010-04-01/Accounts/{TWILIO_SUBACCOUNT_SID}/Calls/{sid}.json"}


PAGE = {"calls": [
    tw_call(1, "completed", duration="187", price="-0.07050"),
    tw_call(11, "completed", duration="60", price="-0.03770"),
    tw_call(12, "no-answer"),
    tw_call(5, "completed", to=STRANGER, duration="30", price="-0.03770"),
    tw_call(13, "in-progress", duration="0"),
    tw_call(14, "canceled"),
    tw_call(15, "completed", to=DEST_RECEPCION, frm=VOICE_FROM, duration="120", price="-0.14000", parent=call_sid(1)),
    tw_call(16, "completed", to=VOICE_FROM, frm=STRANGER, direction="inbound", duration="9", price="-0.01418"),
    tw_call(17, "completed", duration="45", price=None),
], "end": 8, "first_page_uri": f"/2010-04-01/Accounts/{TWILIO_SUBACCOUNT_SID}/Calls.json?PageSize=50&Page=0", "next_page_uri": None,
    "page": 0, "page_size": 50, "previous_page_uri": None, "start": 0,
    "uri": f"/2010-04-01/Accounts/{TWILIO_SUBACCOUNT_SID}/Calls.json?PageSize=50&Page=0"}
KNOWN = {call_sid(1): "completed", call_sid(11): "ambiguous", call_sid(12): "ambiguous", call_sid(13): "dialing",
         call_sid(14): "ambiguous", call_sid(17): "completed"}
INITIATION_SEEN = [call_sid(16)]


def price_micro(p):
    if p is None:
        return None
    whole, _, frac = p.lstrip("-").partition(".")
    frac = (frac + "000000")[:6]
    return int(whole) * 1_000_000 + int(frac)


assert price_micro("-0.07050") == 70500 and price_micro("-0.14000") == 140000 and price_micro("-0.01418") == 14180
OUTCOMES = {
    call_sid(1): {"match": "known", "statusBefore": "completed", "statusAfter": "completed", "costMicroUsd": 70500, "events": ["reconciled", "cost_recorded"]},
    call_sid(11): {"match": "known", "statusBefore": "ambiguous", "statusAfter": "completed", "costMicroUsd": 37700, "events": ["reconciled", "cost_recorded"]},
    call_sid(12): {"match": "known", "statusBefore": "ambiguous", "statusAfter": "no_answer", "costMicroUsd": None, "events": ["reconciled"]},
    call_sid(5): {"match": "unknown", "events": ["unknown_provider_call"], "brakeOpens": True},
    call_sid(13): {"match": "known", "statusBefore": "dialing", "statusAfter": "dialing", "costMicroUsd": None, "events": []},
    call_sid(14): {"match": "known", "statusBefore": "ambiguous", "statusAfter": "failed", "costMicroUsd": None, "events": ["reconciled"],
                   "decision": "DF-E14"},
    call_sid(15): {"match": "transfer_leg", "parentCallSid": call_sid(1), "costMicroUsd": 140000, "events": ["cost_recorded"], "brakeOpens": False,
                   "decision": "DF-W4"},
    call_sid(16): {"match": "denied_inbound", "events": ["cost_recorded"], "costMicroUsd": 14180, "brakeOpens": False, "decision": "DF-W5"},
    call_sid(17): {"match": "known", "statusBefore": "completed", "statusAfter": "completed", "costMicroUsd": None, "events": ["reconciled"],
                   "note": "price null: todavía sin tarificar, se reintenta (DF-W6)", "decision": "DF-W6"},
}
TB.add("callsPage", "listCalls", "una página de la subcuenta frente a voice_calls: conocidas, ambiguas resueltas, desconocida, tramo de transferencia y entrante denegada",
       "D8-21, D8-19, D8-3", "decision-fixture",
       {"page": PAGE, "given": {"voiceCallsByCallSid": KNOWN, "initiationReceivedCallSids": INITIATION_SEEN, "brakeOpen": False}},
       {"perCall": OUTCOMES, "brakeOpens": True, "brakeReason": "unknown_provider_call",
        "mustNotPersist": [CANARY_A, STRANGER, DEST_RECEPCION]}, decision="DF-W6")
for sid, o in OUTCOMES.items():
    if "costMicroUsd" in o and o["costMicroUsd"] is not None:
        tc = next(c for c in PAGE["calls"] if c["sid"] == sid)
        assert price_micro(tc["price"]) == o["costMicroUsd"], sid

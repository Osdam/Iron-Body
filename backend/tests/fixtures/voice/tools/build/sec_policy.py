# policy-tables.json · las tablas puras que componen las políticas de la telefonía (D8-11, D8-15, D8-17, D8-18, D8-19)
#   scheduleLegal · horario de la Ley 2300 (art. 3) en Bogotá, con los festivos
#   scheduleOwn   · el horario propio, más estricto [P-13]: la intersección con el legal
#   frequency     · 1 al día (Ley 2300) y 2 a la semana [P-11]
#   consent       · consentimiento de voz (voice_consents, cuatro ojos, P-7)
#   exclusions    · exclusiones, señales del lead y consulta al RNE [P-14]
#   inboundPolicy · InboundPolicy en orden (D8-11)
#   costReservation · la reserva de coste en micro-US$ (D8-15)
#   brake         · qué abre el freno y quién lo suelta (D8-19, P-17)
#   phoneHash     · remote_hash (subclave voice-phone) y últimos 4 dígitos (D8-17)
PT = Fixture("policy-tables.json", header(
    "policy-tables.json",
    ["D8-11 (InboundPolicy, en orden)", "D8-15 (límites: horario, festivos, frecuencia, concurrencia, gasto)",
     "D8-17 (huella del teléfono con su subclave voice-phone y últimos 4 dígitos)",
     "D8-18 (voice_consents, voice_exclusions, voice_rne_checks y las señales del lead)", "D8-19 (freno)", "D8-22 (ultron.voice_publish)"],
    [
        {"what": "Ley 2300 de 2023, arts. 2, 3 y 5 (canales autorizados; lunes a viernes 7:00–19:00, sábados 8:00–15:00, ni domingos ni festivos; una vez al día)",
         "url": "https://normograma.dian.gov.co/dian/compilacion/docs/ley_2300_2023.htm", "consulted": "03-11-2026 (auditoría §d.3, leída por el architect)",
         "how": "Los límites exactos del intervalo (¿las 19:00 están dentro?) no los dice la ley: DF-P1."},
        {"what": "Precios oficiales de la reserva (ElevenLabs 0,08 US$/min; Twilio Colombia: entrante local 0,0945, saliente a móvil 0,0377, a fijo 0,0700, Media Streams 0,0044)",
         "url": "https://elevenlabs.io/pricing/api y https://www.twilio.com/en-us/voice/pricing/co", "consulted": "03-11-2026 (auditoría §d.1 y §d.2)",
         "how": "La página de Twilio dice «$» sin código de moneda; se leen como US$ (auditoría)."},
    ],
    keys={"appKeyBase64": APP_KEY_B64, "voicePhoneLabel": VOICE_PHONE_LABEL, "canaryPhoneLabel": CANARY_PHONE_LABEL},
    pricesMicroUsdPerMinute={"elevenlabs": 80000, "twilioOutboundMobileCO": 37700, "twilioOutboundLandlineCO": 70000,
                             "twilioInboundLocalCO": 94500, "twilioMediaStreams": 4400, "asOf": "2026-11-03"},
    decisions={
        "DF-P1": "Intervalos semiabiertos en hora de Bogotá: [07:00, 19:00) de lunes a viernes y [08:00, 15:00) los sábados; las 19:00:00 y las 15:00:00 ya están fuera. Se evalúa la hora de MARCAR (Q-8).",
        "DF-P2": "Horario propio: la intersección con el legal (nunca amplía); sin configurar = solo el legal; mal configurado (apertura ≥ cierre o mal escrito) = fuera de horario (falla cerrado).",
        "DF-P3": "Cuentan como intento: requested, dialing, active (una saliente en conversación, E1-1), ambiguous, completed, busy, no_answer, failed y hung_up. No cuentan canceled ni failed_to_start (no salió nada). Las entrantes nunca cuentan, tampoco las denied (E1-2).",
        "DF-P4": "«Hoy» y «la semana» en Bogotá: hoy = la fecha de Bogotá de now; semana = desde el lunes 00:00 de Bogotá (lectura de la ley). También se da el resultado con 7 días móviles (now − 7 días, now], la lectura más estricta, para que el propietario elija (P-11).",
        "DF-P5": "Consentimiento: manda el último evento de voice_consents por (at, id); granted solo vale con source written_form, admin_with_evidence aprobado por OTRA persona, o recorded_inbound_request si la grabación está encendida (P-7: apagada, no vale); revoked vale siempre. El consentimiento del lead del motor no es de voz.",
        "DF-P6": "Las exclusiones (y las señales do_not_contact y consent_status = denied) se miran en TODA saliente, también a canarios: D8-18 «se consulta antes de cada saliente» (Q-4: D8-12 las agrupa en el alcance contacts).",
        "DF-P7": "Una consulta al RNE vale si checked_at ≥ now − vigencia (el borde vale); listed excluye; sin consulta vigente, rne_check_missing (solo alcance contacts). Vigencia de ejemplo: 30 días [P-14].",
        "DF-P8": "InboundPolicy acumula todos los motivos que apliquen en el orden de D8-11, como VoiceCallPolicy::decide del H6.",
        "DF-P9": "Un caller ID oculto o ausente (anonymous, vacío) no es un canario: not_an_active_voice_canary.",
        "DF-P10": "Concurrencia: rechaza si dialing + active ≥ VOICE_MAX_CONCURRENT_CALLS (la nueva haría max + 1). Cupo diario: rechaza si las de hoy ≥ el cupo; un cupo 0 de entrantes da daily_cap_reached (D8-11 no tiene daily_cap_zero).",
        "DF-P11": "VOICE_INBOUND_SCOPE distinto de canary (el único del H8) = inbound_disabled: nunca abre a todos por un valor mal escrito.",
        "DF-P12": "Reserva = ceil(segundos máximos / 60) × (ElevenLabs + tramo de Twilio según el destino + Media Streams), más el tramo de transferencia al precio del destino si el perfil tiene destinos (Q-10: el tramo transferido no lo acota max_duration_seconds).",
        "DF-P13": "Presupuesto en micro-US$ enteros: «5.50» → 5 500 000; más de 6 decimales se truncan; vacío, negativo o mal escrito → 0. Cabe si gastado + reservado + nueva ≤ presupuesto (el borde vale); presupuesto 0 → budget_exhausted.",
        "DF-P14": "Soltar el freno: persona con ultron.voice_publish (si no, 403), con nota PlainText de 1 a 300 caracteres (si no, 422) y una sola vez (otra vez, 409). Una automatización nunca lo suelta.",
    },
))


# ── scheduleLegal ───────────────────────────────────────────────────────────
def legal_window(b):
    wd, t = b.weekday(), b.strftime("%H:%M:%S")
    if wd < 5:
        return "07:00:00" <= t < "19:00:00"
    if wd == 5:
        return "08:00:00" <= t < "15:00:00"
    return False


def SL(desc, at, outside, holiday, *, basis="ley", decision=None, naive_utc_would_say=None):
    b = to_bogota(at)
    assert legal_window(b) == (not outside), desc
    assert (b.date() in ALL) == holiday, desc
    exp = {"bogotaDate": b.date().isoformat(), "bogotaTime": b.strftime("%H:%M:%S"), "weekday": weekday_es(b.date()),
           "outsideHours": outside, "holiday": holiday, "allowed": (not outside) and (not holiday)}
    fields = {"at": at}
    if naive_utc_would_say is not None:
        fields["trap"] = "con la hora UTC sin convertir el resultado sería el contrario"
    PT.add("scheduleLegal", "legal", desc, "D8-15, D8-18 (Ley 2300 art. 3)", basis, fields, exp, decision=decision)


SL("lunes 06:59:59 de Bogotá: fuera", bog_to_utc_iso(2026, 11, 9, 6, 59, 59), True, False)
SL("lunes 07:00:00: dentro", bog_to_utc_iso(2026, 11, 9, 7, 0, 0), False, False, basis="decision-fixture", decision="DF-P1")
SL("lunes 18:59:59: dentro", bog_to_utc_iso(2026, 11, 9, 18, 59, 59), False, False)
SL("lunes 19:00:00: ya fuera (intervalo semiabierto)", bog_to_utc_iso(2026, 11, 9, 19, 0, 0), True, False, basis="decision-fixture", decision="DF-P1")
SL("lunes 18:30 de Bogotá = 23:30 UTC: dentro", "2026-11-09T23:30:00Z", False, False, naive_utc_would_say=True)
SL("lunes 06:30 de Bogotá = 11:30 UTC: fuera", "2026-11-09T11:30:00Z", True, False, naive_utc_would_say=True)
SL("viernes 18:59 de Bogotá = 23:59 UTC: dentro", "2026-11-06T23:59:00Z", False, False)
SL("viernes 19:00 de Bogotá = sábado 00:00 UTC: fuera (el día UTC ya es sábado)", "2026-11-07T00:00:00Z", True, False,
   basis="decision-fixture", decision="DF-P1", naive_utc_would_say=True)
SL("sábado 07:59:59: fuera", bog_to_utc_iso(2026, 11, 7, 7, 59, 59), True, False)
SL("sábado 08:00:00: dentro", bog_to_utc_iso(2026, 11, 7, 8, 0, 0), False, False, basis="decision-fixture", decision="DF-P1")
SL("sábado 14:59:59: dentro", bog_to_utc_iso(2026, 11, 7, 14, 59, 59), False, False)
SL("sábado 15:00:00: ya fuera", bog_to_utc_iso(2026, 11, 7, 15, 0, 0), True, False, basis="decision-fixture", decision="DF-P1")
SL("domingo 10:00: fuera", bog_to_utc_iso(2026, 11, 8, 10, 0, 0), True, False)
SL("lunes festivo (Independencia de Cartagena, 16-11-2026) a las 10:00: festivo dentro de horas", bog_to_utc_iso(2026, 11, 16, 10, 0, 0), False, True)
SL("lunes festivo a las 20:00: fuera y festivo", bog_to_utc_iso(2026, 11, 16, 20, 0, 0), True, True)
SL("sábado festivo (1 de mayo de 2027) a las 10:00: festivo", bog_to_utc_iso(2027, 5, 1, 10, 0, 0), False, True)
SL("martes 29-02-2028 (bisiesto) a las 10:00: dentro", "2028-02-29T15:00:00Z", False, False)
SL("jueves 31-12-2026 a las 18:30: dentro (el 1 de enero ya es festivo)", bog_to_utc_iso(2026, 12, 31, 18, 30, 0), False, False)
PT.add("scheduleLegal", "legal", "la misma hora escrita con desfase -05:00 da lo mismo", "D8-15", "ley",
       {"at": "2026-11-09T18:59:59-05:00"},
       {"bogotaDate": "2026-11-09", "bogotaTime": "18:59:59", "weekday": "lunes", "outsideHours": False, "holiday": False, "allowed": True})
PT.add("scheduleLegal", "legal", "la misma hora escrita con desfase +01:00 (00:30 del martes) es el lunes 18:30 en Bogotá", "D8-15", "ley",
       {"at": "2026-11-10T00:30:00+01:00"},
       {"bogotaDate": "2026-11-09", "bogotaTime": "18:30:00", "weekday": "lunes", "outsideHours": False, "holiday": False, "allowed": True})

# ── scheduleOwn [P-13] ──────────────────────────────────────────────────────
OWN = {"weekdays": ["09:00", "18:00"], "saturday": None}


def SO(desc, at, own, outside, *, decision="DF-P2", basis="decision-fixture"):
    b = to_bogota(at)
    exp = {"bogotaTime": b.strftime("%H:%M:%S"), "weekday": weekday_es(b.date()), "outsideHours": outside}
    PT.add("scheduleOwn", "own", desc, "D8-15, P-13", basis, {"at": at, "ownWindow": own}, exp, decision=decision)


SO("propio L-V 09:00–18:00: lunes 08:30 → fuera aunque la ley lo permita", bog_to_utc_iso(2026, 11, 9, 8, 30), OWN, True)
SO("propio: lunes 09:00 → dentro", bog_to_utc_iso(2026, 11, 9, 9, 0), OWN, False)
SO("propio: lunes 17:59:59 → dentro", bog_to_utc_iso(2026, 11, 9, 17, 59, 59), OWN, False)
SO("propio: lunes 18:00 → fuera", bog_to_utc_iso(2026, 11, 9, 18, 0), OWN, True)
SO("propio sin sábado: sábado 10:00 → fuera", bog_to_utc_iso(2026, 11, 7, 10, 0), OWN, True)
SO("propio más ancho que la ley (06:00–20:00): lunes 06:30 → fuera por la ley", bog_to_utc_iso(2026, 11, 9, 6, 30),
   {"weekdays": ["06:00", "20:00"], "saturday": ["06:00", "20:00"]}, True)
SO("propio más ancho que la ley: lunes 19:30 → fuera por la ley", bog_to_utc_iso(2026, 11, 9, 19, 30),
   {"weekdays": ["06:00", "20:00"], "saturday": ["06:00", "20:00"]}, True)
SO("propio más ancho: sábado 15:30 → fuera por la ley", bog_to_utc_iso(2026, 11, 7, 15, 30),
   {"weekdays": ["06:00", "20:00"], "saturday": ["06:00", "20:00"]}, True)
SO("sin horario propio: manda el legal (lunes 07:30 → dentro)", bog_to_utc_iso(2026, 11, 9, 7, 30), None, False)
SO("horario propio mal configurado (18:00–09:00) → fuera (falla cerrado)", bog_to_utc_iso(2026, 11, 9, 10, 0),
   {"weekdays": ["18:00", "09:00"], "saturday": None}, True)
SO("horario propio mal escrito (25:00) → fuera (falla cerrado)", bog_to_utc_iso(2026, 11, 9, 10, 0),
   {"weekdays": ["09:00", "25:00"], "saturday": None}, True)

# ── frequency [P-11] ────────────────────────────────────────────────────────
NOW_F = "2026-11-11T15:00:00Z"        # miércoles 10:00 de Bogotá; la semana empezó el lunes 09-11 a las 00:00 (05:00 UTC)
COUNTED = {"requested", "dialing", "active", "ambiguous", "completed", "busy", "no_answer", "failed", "hung_up"}


def F(desc, history, today, week, rolling, *, limits=None, basis="decision-fixture", decision="DF-P3", ref="D8-12, D8-15, P-11"):
    limits = limits or {"perDay": 1, "perWeek": 2}
    exceeded = today >= limits["perDay"] or week >= limits["perWeek"]
    exceeded_r = today >= limits["perDay"] or rolling >= limits["perWeek"]
    # comprobación con una referencia independiente de los literales
    nb = to_bogota(NOW_F)
    monday = (nb - dt.timedelta(days=nb.weekday())).replace(hour=0, minute=0, second=0)
    c_today = c_week = c_roll = 0
    for h in history:
        if h["direction"] != "outbound" or h["status"] not in COUNTED:
            continue
        hb = to_bogota(h["at"])
        c_today += hb.date() == nb.date()
        c_week += monday <= hb <= nb
        c_roll += parse_iso(NOW_F) - dt.timedelta(days=7) < parse_iso(h["at"]) <= parse_iso(NOW_F)
    assert (c_today, c_week, c_roll) == (today, week, rolling), (desc, c_today, c_week, c_roll)
    exp = {"countToday": today, "countWeek": week, "countRolling7Days": rolling, "frequencyExceeded": exceeded,
           "frequencyExceededRolling7Days": exceeded_r}
    PT.add("frequency", "count", desc, ref, basis, {"now": NOW_F, "limits": limits, "history": history}, exp, decision=decision)


def att(at, status="completed", direction="outbound"):
    return {"at": at, "status": status, "direction": direction}


F("sin historial → 0 y cabe", [], 0, 0, 0, basis="ley", decision=None)
F("una completada hoy a las 08:00 de Bogotá → la de ahora sería la segunda del día", [att("2026-11-11T13:00:00Z")], 1, 1, 1, basis="ley", decision=None)
F("ayer a las 23:30 de Bogotá es el mismo día UTC que ahora: no cuenta como hoy", [att("2026-11-11T04:30:00Z")], 0, 1, 1, decision="DF-P4")
F("una cancelada hoy no cuenta (no salió)", [att("2026-11-11T13:00:00Z", "canceled")], 0, 0, 0)
F("una failed_to_start hoy no cuenta (ElevenLabs la rechazó)", [att("2026-11-11T13:00:00Z", "failed_to_start")], 0, 0, 0)
F("una comunicando (busy) hoy cuenta: el reintento de negocio es otro día", [att("2026-11-11T13:00:00Z", "busy")], 1, 1, 1)
F("una sin respuesta (no_answer) hoy cuenta", [att("2026-11-11T13:00:00Z", "no_answer")], 1, 1, 1)
F("una ambigua hoy cuenta (pudo llamar)", [att("2026-11-11T13:00:00Z", "ambiguous")], 1, 1, 1)
F("una pedida (requested) hoy cuenta", [att("2026-11-11T14:50:00Z", "requested")], 1, 1, 1)
F("una colgada por el corte (hung_up) hoy cuenta", [att("2026-11-11T13:00:00Z", "hung_up")], 1, 1, 1)
F("una saliente en conversación (active, E1-1) hoy cuenta", [att("2026-11-11T14:55:00Z", "active")], 1, 1, 1, ref="D8-12, D8-15, E1-1")
F("una entrante denegada (denied, E1-2) no cuenta", [att("2026-11-11T13:00:00Z", "denied", "inbound")], 0, 0, 0, ref="D8-15, E1-2")
F("una entrante de hoy no cuenta (no es un contacto proactivo)", [att("2026-11-11T13:00:00Z", "completed", "inbound")], 0, 0, 0)
F("lunes y martes de esta semana → 2 en la semana: la tercera no cabe", [att("2026-11-09T14:00:00Z"), att("2026-11-10T14:00:00Z")], 0, 2, 2,
  basis="supuesto-lead", decision="DF-P4")
F("solo el lunes → 1 en la semana: cabe", [att("2026-11-09T14:00:00Z")], 0, 1, 1, basis="supuesto-lead", decision="DF-P4")
F("viernes y sábado de la semana pasada: la semana de calendario empieza en 0, los 7 días móviles ven 2",
  [att("2026-11-06T14:00:00Z"), att("2026-11-07T14:00:00Z")], 0, 0, 2, decision="DF-P4")
F("hace exactamente 7 días (04-11 15:00:00 UTC) ya no entra en los 7 días móviles", [att("2026-11-04T15:00:00Z")], 0, 0, 0, decision="DF-P4")
F("hace 7 días menos un segundo sí entra en los 7 días móviles", [att("2026-11-04T15:00:01Z")], 0, 0, 1, decision="DF-P4")
F("domingo pasado a las 23:59:59 de Bogotá no es de esta semana; el lunes 00:00:00 sí",
  [att("2026-11-09T04:59:59Z", "failed"), att("2026-11-09T05:00:00Z", "failed")], 0, 1, 2, decision="DF-P4")

# ── consent (D8-18, P-7, P-18) ─────────────────────────────────────────────
def ev(i, kind, source="written_form", at="2026-11-01T15:00:00Z", actor=7, approved=None):
    return {"id": i, "kind": kind, "channel": "voice_call", "purpose": "commercial", "source": source, "at": at,
            "actorId": actor, "approvedBy": approved}


def CS(desc, events, has, *, lead="unknown", recording=False, basis="decision-fixture", decision="DF-P5", ref="D8-18"):
    PT.add("consent", "voice", desc, ref, basis, {"voiceConsents": events, "leadConsentStatus": lead, "recordingEnabled": recording},
           {"hasVoiceConsent": has}, decision=decision)


CS("sin eventos → sin consentimiento", [], False, basis="contrato", decision=None)
CS("granted por formulario escrito → sí", [ev(1, "granted")], True, basis="contrato", decision=None)
CS("granted y después revoked → no", [ev(1, "granted"), ev(2, "revoked", at="2026-11-02T15:00:00Z")], False)
CS("revoked y después un granted nuevo → sí", [ev(1, "revoked"), ev(2, "granted", at="2026-11-02T15:00:00Z")], True)
CS("el lead del motor con consent_status = granted (WhatsApp) no es consentimiento de voz (Ley 2300 art. 2)", [], False, lead="granted",
   basis="ley", decision=None)
CS("admin_with_evidence sin la segunda persona → no (cuatro ojos pendientes)", [ev(1, "granted", "admin_with_evidence")], False,
   ref="D8-18, P-18")
CS("admin_with_evidence aprobado por quien lo cargó → no", [ev(1, "granted", "admin_with_evidence", approved=7)], False, ref="D8-18, P-18")
CS("admin_with_evidence aprobado por otra persona → sí", [ev(1, "granted", "admin_with_evidence", approved=9)], True, ref="D8-18, P-18")
CS("recorded_inbound_request con la grabación apagada (P-7) → no vale", [ev(1, "granted", "recorded_inbound_request")], False,
   ref="D8-18, P-7")
CS("recorded_inbound_request con la grabación encendida → vale", [ev(1, "granted", "recorded_inbound_request")], True, recording=True,
   ref="D8-18, P-7")
CS("granted y revoked con la misma hora: manda el id mayor (revoked)", [ev(1, "granted"), ev(2, "revoked")], False)
CS("revoked sin cuatro ojos sigue valiendo: revocar nunca espera a nadie", [ev(1, "granted"), ev(2, "revoked", "admin_with_evidence", at="2026-11-02T15:00:00Z")],
   False)
CS("un granted pendiente de aprobar no tapa uno anterior válido", [ev(1, "granted"), ev(2, "granted", "admin_with_evidence", at="2026-11-02T15:00:00Z")],
   True)

# ── exclusions (D8-18, P-14, P-20) ─────────────────────────────────────────
NOW_X = "2026-11-11T15:00:00Z"
LEAD_OK = {"doNotContact": False, "consentStatus": "granted"}


def X(desc, excl, lead, rne, scope, excluded, missing, *, basis="contrato", decision=None, ref="D8-18"):
    PT.add("exclusions", scope, desc, ref, basis,
           {"now": NOW_X, "scope": scope, "rneValidityDays": 30, "exclusions": excl, "lead": lead, "rneCheck": rne},
           {"excluded": excluded, "rneCheckMissing": missing}, decision=decision)


RNE_OK = {"checkedAt": "2026-11-01T15:00:00Z", "result": "not_listed"}
X("contacto limpio con consulta vigente → nada", [], LEAD_OK, RNE_OK, "contacts", False, False)
for src in ["rne", "spoken_opt_out", "lead_do_not_contact", "lead_consent_denied", "admin"]:
    X(f"exclusión {src} → excluded", [{"source": src, "at": "2026-11-05T15:00:00Z"}], LEAD_OK, RNE_OK, "contacts", True, False)
X("lead con do_not_contact (leído en vivo) → excluded", [], {"doNotContact": True, "consentStatus": "granted"}, RNE_OK, "contacts", True, False)
X("lead con consent_status = denied → excluded", [], {"doNotContact": False, "consentStatus": "denied"}, RNE_OK, "contacts", True, False)
X("contacto sin lead de origen (manual) → solo cuentan sus exclusiones", [], None, RNE_OK, "contacts", False, False)
X("una exclusión antigua no la levanta un consentimiento posterior (solo la supresión)", [{"source": "spoken_opt_out", "at": "2026-10-01T15:00:00Z"}],
  LEAD_OK, RNE_OK, "contacts", True, False)
X("sin consulta al RNE → rne_check_missing", [], LEAD_OK, None, "contacts", False, True, basis="decision-fixture", decision="DF-P7", ref="D8-18, P-14")
X("consulta de hace 31 días (vigencia 30) → rne_check_missing", [], LEAD_OK, {"checkedAt": "2026-10-11T14:59:59Z", "result": "not_listed"},
  "contacts", False, True, basis="decision-fixture", decision="DF-P7", ref="D8-18, P-14")
X("consulta de hace exactamente 30 días → todavía vale", [], LEAD_OK, {"checkedAt": "2026-10-12T15:00:00Z", "result": "not_listed"},
  "contacts", False, False, basis="decision-fixture", decision="DF-P7", ref="D8-18, P-14")
X("el RNE dice listed → excluded (no rne_check_missing)", [], LEAD_OK, {"checkedAt": "2026-11-10T15:00:00Z", "result": "listed"},
  "contacts", True, False, basis="decision-fixture", decision="DF-P7", ref="D8-18, P-14")
X("canario sin consulta al RNE → no hace falta en alcance canary", [], None, None, "canary", False, False, ref="D8-12, P-20")
X("canario que dijo «no me vuelvas a llamar» → excluded también en canary", [{"source": "spoken_opt_out", "at": "2026-11-05T15:00:00Z"}],
  None, None, "canary", True, False, basis="decision-fixture", decision="DF-P6", ref="D8-18, D8-12")

# ── inboundPolicy (D8-11) ───────────────────────────────────────────────────
IN_ORDER = ["inbound_disabled", "calls_disabled_in_code", "voice_braked", "no_publication", "publication_unreadable",
            "not_an_active_voice_canary", "concurrency_exceeded", "daily_cap_reached"]
IN_BASE = {"inboundEnabled": True, "scope": "canary", "braked": False, "publication": "ok", "caller": "active_voice",
           "activeOrDialing": 0, "maxConcurrent": 1, "inboundToday": 0, "inboundDailyCap": 3}


def IP(desc, change, reasons_open, *, basis="contrato", decision=None):
    facts = dict(IN_BASE, **change)
    closed = ["calls_disabled_in_code"] + [r for r in reasons_open if r != "calls_disabled_in_code"]
    closed.sort(key=IN_ORDER.index)
    for r in reasons_open:
        assert r in IN_ORDER
    assert reasons_open == sorted(reasons_open, key=IN_ORDER.index)
    exp = {"codeOpen": {"authorized": reasons_open == [], "reasons": reasons_open, "callerAuthorized": "si" if reasons_open == [] else "no"},
           "codeClosed": {"authorized": False, "reasons": closed, "callerAuthorized": "no"}}
    PT.add("inboundPolicy", "decide", desc, "D8-11", basis, {"facts": facts}, exp, decision=decision or "DF-P8")


IP("todo abierto y canario activo → si (con la constante del código girada)", {}, [], basis="decision-fixture")
IP("VOICE_INBOUND_ENABLED = false", {"inboundEnabled": False}, ["inbound_disabled"], basis="decision-fixture")
IP("alcance distinto de canary (all) → inbound_disabled", {"scope": "all"}, ["inbound_disabled"], basis="decision-fixture", decision="DF-P11")
IP("freno abierto", {"braked": True}, ["voice_braked"], basis="decision-fixture")
IP("sin publicación de voz", {"publication": "none"}, ["no_publication"], basis="decision-fixture")
IP("publicación ilegible (un fallo deniega)", {"publication": "unreadable"}, ["publication_unreadable"], basis="decision-fixture")
IP("número no registrado", {"caller": "not_registered"}, ["not_an_active_voice_canary"], basis="decision-fixture")
IP("canario registrado pero no activo", {"caller": "not_active"}, ["not_an_active_voice_canary"], basis="decision-fixture")
IP("caller ID oculto", {"caller": "withheld"}, ["not_an_active_voice_canary"], basis="decision-fixture", decision="DF-P9")
IP("concurrencia 1 de 1 → concurrency_exceeded", {"activeOrDialing": 1}, ["concurrency_exceeded"], basis="decision-fixture", decision="DF-P10")
IP("concurrencia 1 de 2 → cabe", {"activeOrDialing": 1, "maxConcurrent": 2}, [], basis="decision-fixture", decision="DF-P10")
IP("3 entrantes hoy con cupo 3 → daily_cap_reached", {"inboundToday": 3}, ["daily_cap_reached"], basis="decision-fixture", decision="DF-P10")
IP("2 entrantes hoy con cupo 3 → cabe", {"inboundToday": 2}, [], basis="decision-fixture", decision="DF-P10")
IP("cupo de entrantes 0 (el defecto, P-5) → daily_cap_reached siempre", {"inboundDailyCap": 0}, ["daily_cap_reached"], basis="decision-fixture",
   decision="DF-P10")
IP("todo mal a la vez → todos los motivos en el orden de D8-11",
   {"inboundEnabled": False, "braked": True, "publication": "unreadable", "caller": "not_registered", "activeOrDialing": 1, "inboundToday": 3},
   ["inbound_disabled", "voice_braked", "publication_unreadable", "not_an_active_voice_canary", "concurrency_exceeded", "daily_cap_reached"],
   basis="decision-fixture")

# ── costReservation (D8-15) ─────────────────────────────────────────────────
PRICES = PT.header["pricesMicroUsdPerMinute"]


def CR(desc, direction, dest, max_s, transfer_dest, reserve, *, budget=None):
    mins = -(-max_s // 60)
    leg = {"mobile": PRICES["twilioOutboundMobileCO"], "landline": PRICES["twilioOutboundLandlineCO"], "inbound": PRICES["twilioInboundLocalCO"]}[dest]
    calc = mins * (PRICES["elevenlabs"] + leg + PRICES["twilioMediaStreams"])
    if transfer_dest:
        calc += mins * {"mobile": PRICES["twilioOutboundMobileCO"], "landline": PRICES["twilioOutboundLandlineCO"]}[transfer_dest]
    assert calc == reserve, (desc, calc, reserve)
    fields = {"direction": direction, "destinationKind": dest, "maxDurationSeconds": max_s, "transferDestinationKind": transfer_dest}
    exp = {"minutes": mins, "reserveMicroUsd": reserve}
    PT.add("costReservation", "reserve", desc, "D8-15", "decision-fixture", fields, exp, decision="DF-P12")


CR("saliente a móvil, 300 s → 5 min × (80 000 + 37 700 + 4 400) = 610 500 µUS$", "outbound", "mobile", 300, None, 610500)
CR("saliente a fijo, 300 s → 5 × (80 000 + 70 000 + 4 400) = 772 000", "outbound", "landline", 300, None, 772000)
CR("entrante, 300 s → 5 × (80 000 + 94 500 + 4 400) = 894 500", "inbound", "inbound", 300, None, 894500)
CR("301 s son 6 minutos → 732 600", "outbound", "mobile", 301, None, 732600)
CR("60 s es 1 minuto → 122 100", "outbound", "mobile", 60, None, 122100)
CR("61 s son 2 minutos → 244 200", "outbound", "mobile", 61, None, 244200)
CR("saliente a móvil con transferencia a un fijo (recepción) → + 5 × 70 000 = 960 500", "outbound", "mobile", 300, "landline", 960500)


def BU(desc, budget_raw, budget_micro, spent, reserved, new, fits):
    fields = {"budgetRaw": budget_raw, "spentMicroUsd": spent, "reservedMicroUsd": reserved, "newReserveMicroUsd": new}
    exp = {"budgetMicroUsd": budget_micro, "fits": fits, "reason": None if fits else "budget_exhausted"}
    assert (budget_micro > 0 and spent + reserved + new <= budget_micro) == fits, desc
    PT.add("costReservation", "budget", desc, "D8-15", "decision-fixture", fields, exp, decision="DF-P13")


BU("cabe justo: 4 000 000 + 389 500 + 610 500 = 5 000 000", "5", 5000000, 4000000, 389500, 610500, True)
BU("un micro-US$ más ya no cabe", "5", 5000000, 4000001, 389500, 610500, False)
BU("presupuesto 0 (el defecto) → budget_exhausted siempre", "0", 0, 0, 0, 122100, False)
BU("«5.50» → 5 500 000", "5.50", 5500000, 0, 0, 610500, True)
BU("«0.61» → 610 000: una reserva de 610 500 no cabe", "0.61", 610000, 0, 0, 610500, False)
BU("más de 6 decimales se truncan: «1.2345679» → 1 234 567", "1.2345679", 1234567, 0, 0, 610500, True)
BU("vacío → 0", "", 0, 0, 0, 122100, False)
BU("negativo → 0", "-1", 0, 0, 0, 122100, False)
BU("notación científica → mal escrito → 0", "1e3", 0, 0, 0, 122100, False)
BU("texto → 0", "cinco", 0, 0, 0, 122100, False)

# ── brake (D8-19, P-17, D8-22) ──────────────────────────────────────────────
for trig, opens, ref in [("guard_violation", True, "D8-19, D8-10"), ("seal_mismatch", True, "D8-19, D8-8"), ("agent_drift", True, "D8-19, D8-21"),
                         ("daily_hard_cap_reached", True, "D8-19, D8-15"), ("usage_alert", True, "D8-19, D8-16"),
                         ("unknown_provider_call", True, "D8-19, D8-21"), ("post_call_transcription_clean", False, "D8-19"),
                         ("call_initiation_failure", False, "D8-19"), ("outbound_rejected", False, "D8-19"), ("tool_call_rejected", False, "D8-19")]:
    PT.add("brake", "opens", f"{trig} → {'abre' if opens else 'no abre'} el freno", ref, "supuesto-lead", {"trigger": trig},
           {"opensBrake": opens, "incident": opens})


def BR(desc, actor, note, state, http, released):
    PT.add("brake", "release", desc, "D8-19, D8-22", "decision-fixture", {"actor": actor, "note": note, "brakeState": state},
           {"http": http, "released": released}, decision="DF-P14")


SA = {"person": True, "adminId": 7, "permissions": ["ultron.voice_publish", "telephony.view"]}
AD = {"person": True, "adminId": 9, "permissions": ["telephony.view", "ultron.voice_manage"]}
TOKEN = {"person": False, "adminId": None, "permissions": ["ultron.voice_publish"]}
BR("Super Admin con ultron.voice_publish y nota → suelto", SA, "Revisada la guarda: falso positivo del aviso.", "open", 200, True)
BR("sin ultron.voice_publish → 403", AD, "Revisado.", "open", 403, False)
BR("una automatización (token compartido) nunca lo suelta → 403", TOKEN, "Revisado.", "open", 403, False)
BR("sin nota → 422", SA, "", "open", 422, False)
BR("nota con un teléfono → 422 (PlainText y sin datos de contacto)", SA, "Llamé al 300 000 0031 y todo bien", "open", 422, False)
BR("ya suelto → 409 (se suelta una vez)", SA, "Otra vez.", "released", 409, False)

# ── phoneHash (D8-17) ───────────────────────────────────────────────────────
def PH(desc, e164, *, valid=True):
    if valid:
        exp = {"remoteHash": phone_hash(e164), "remoteLast4": e164[-4:], "canaryFingerprint": phone_hash(e164, CANARY_PHONE_LABEL)}
        assert exp["remoteHash"] != exp["canaryFingerprint"]
    else:
        exp = {"throws": "InvalidArgumentException"}
    PT.add("phoneHash", "voice", desc, "D8-17", "contrato" if valid else "decision-fixture", {"e164": e164}, exp,
           decision=None if valid else "DF-P15")


decisions(**{"DF-P15": "La huella de voz no normaliza: recibe un E.164 estricto (^\\+[1-9][0-9]{7,14}$ con D, el de PhoneFingerprint) y lanza con cualquier otra cosa; normalizar es de quien llama (StudioCanaryPhone para Colombia)."})
PH("móvil de un canario", CANARY_A)
PH("el número dedicado (fijo de Bogotá)", VOICE_FROM)
PH("un E.164 de otro país también se huellea (la huella no decide el país)", "+12025550143")
PH("con espacios no es E.164 → lanza", "+57 300 000 0031", valid=False)
PH("sin + → lanza", "573000000031", valid=False)
PH("con un salto de línea final → lanza ($ con D)", "+573000000031\n", valid=False)
PH("vacío → lanza", "", valid=False)

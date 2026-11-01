# providerClassification · clasificación de los bloques twilio, twilio_voice y elevenlabs de la ruta 39 (contrato §I7.2)
# (la de meta_ads está en metaClassification). Las reglas se aplican en el orden del contrato: la primera que casa manda.


def PCT(desc, driver, verify_active, send_enabled, spent, hard, cls, reason, verified_24h=0):
    add("providerClassification", "twilio", desc,
        {"facts": {"otpDriver": driver, "verifyActive": verify_active, "sendEnabled": send_enabled,
                   "estimatedSpentTodayUsd": spent, "dailyHardLimitUsd": hard, "otpVerifiedLast24h": verified_24h}},
        {"classification": cls, "reason": reason})


PCT("producción al congelar", "twilio", True, True, 0.41, 12.0, "connected_otp_only", None, 14)
PCT("driver dev", "dev", True, True, 0.0, 12.0, "not_configured", "otp_driver_not_twilio")
PCT("driver labsmobile", "labsmobile", True, True, 0.0, 12.0, "not_configured", "otp_driver_not_twilio")
PCT("Verify incompleto", "twilio", False, True, 0.0, 12.0, "not_configured", "verify_incomplete")
PCT("interruptor de envío bajado", "twilio", True, False, 0.0, 12.0, "degraded", "kill_switch")
PCT("gasto estimado igual al techo duro", "twilio", True, True, 12.0, 12.0, "degraded", "hard_limit_reached")
PCT("gasto estimado por encima del techo duro", "twilio", True, True, 12.5, 12.0, "degraded", "hard_limit_reached")
PCT("gasto por encima del umbral blando y por debajo del duro: no degrada", "twilio", True, True, 7.0, 12.0, "connected_otp_only", None)
PCT("cero retos verificados: los recuentos nunca degradan", "twilio", True, True, 0.0, 12.0, "connected_otp_only", None, 0)
PCT("driver dev y el interruptor bajado: manda el driver", "dev", True, False, 0.0, 12.0, "not_configured", "otp_driver_not_twilio")
PCT("Verify incompleto y el techo alcanzado: manda Verify", "twilio", False, True, 13.0, 12.0, "not_configured", "verify_incomplete")
PCT("interruptor bajado y el techo alcanzado: manda el interruptor", "twilio", True, False, 13.0, 12.0, "degraded", "kill_switch")


def PCV(desc, from_, enabled, cls):
    add("providerClassification", "twilio_voice", desc, {"facts": {"from": from_, "enabled": enabled}}, {"classification": cls})


PCV("producción en H6: sin número", None, False, "not_configured")
PCV("sin número y con la bandera encendida", None, True, "not_configured")
PCV("número con espacios: no es E.164", "+57 601 000 0099", False, "not_configured")
PCV("número E.164 y bandera apagada", "+576010000099", False, "disabled")
PCV("número E.164 y bandera encendida: degraded (nada la usa en H6)", "+576010000099", True, "degraded")


def PCE(desc, state, cls):
    add("providerClassification", "elevenlabs", desc, {"facts": {"state": state}}, {"classification": cls, "code": state})


PCE("sin configurar (producción en H6)", "ELEVENLABS_NOT_CONFIGURED", "not_configured")
PCE("apagado", "ELEVENLABS_DISABLED", "disabled")
PCE("sin verificar", "ELEVENLABS_UNVERIFIED", "degraded")
PCE("verificado por el Studio", "ELEVENLABS_VERIFIED", "connected_read")

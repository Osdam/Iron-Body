# compiler-cases.json · StudioVoiceCompiler (S8) y la huella de su salida (D8-6, D8-7, D8-11, D8-14, D8-15, D8-17)
#   canonicalHash · el JSON canónico de StudioContentHasher y su sha256: la forma reproducible de specHash, payloadHash y la huella del perfil
#   capClamp      · VOICE_PER_CALL_MAX_SECONDS con su recorte a 60–600
#   compile       · compilaciones válidas (con invariantes de la salida) y las negativas de D8-6, una por motivo
#   hashRelations · qué cambia y qué no cambia specHash (relaciones, sin depender de la plantilla)
# La plantilla vive en el código de S8 (P-6): el fixture NO puede fijar el valor de specHash de una compilación sin conocerla,
# así que fija (1) el algoritmo de la huella con vectores exactos, (2) las negativas y (3) las relaciones entre huellas.
import copy

COMPILER_VERSION = "ultron-voice-compiler/1"
CATALOG_TOOLS = ["consultar_planes", "consultar_horarios", "solicitar_humano", "no_volver_a_llamar"]
SYSTEM_TOOLS = ["end_call", "transfer_to_number"]
APPROVED_VERSION_STATUSES = ["APPROVED", "CANARY", "PUBLISHED"]
REASONS = ["profile_not_approved", "version_not_approved", "profile_hash_mismatch", "version_hash_mismatch",
           "slot_money", "slot_discount", "slot_url", "slot_phone", "slot_email", "slot_invisible",
           "tool_not_in_catalog", "system_tool_not_allowed", "end_call_missing",
           "transfer_destination_not_in_profile",
           "max_duration_exceeds_cap", "recording_enabled", "retention_exceeds_limit",
           "notice_missing",
           "profile_field_not_allowed", "language_not_allowed", "max_duration_below_minimum", "too_many_transfer_destinations",
           "audio_storage_enabled"]

CO = Fixture("compiler-cases.json", header(
    "compiler-cases.json",
    ["D8-6 (entradas, salidas, plantilla y negativas del compilador)", "D8-7 (agente inmutable; laboratorio)", "D8-11 (caller_authorized = no por defecto; sin conversation_config_override)",
     "D8-14 (destinos de transferencia: como mucho 3, con alias)", "D8-15 (duración por llamada 60–7 200 en ElevenLabs, ≤ VOICE_PER_CALL_MAX_SECONDS con recorte a 60–600)",
     "D8-17 (aviso fijo al empezar cada llamada)", "Congelación: P-3, P-6, P-7, P-8, P-9"],
    [{"what": "StudioContentHasher (H5): sha256 del JSON canónico (claves ordenadas a todos los niveles, listas en su orden, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES; decimales rechazados)",
      "url": "backend/app/Services/UltronStudio/StudioContentHasher.php", "consulted": "03-11-2026 (en este árbol, sin cambios desde el H5)",
      "how": "Los vectores de canonicalHash se recalculan con PHP 8.3 cargando solo esa clase (voice-fixtures-check.py --php)."},
     {"what": "max_duration_seconds de 60 a 7 200 s", "url": "https://elevenlabs.io/docs/eleven-agents/customization/tools/system-tools/end-call",
      "consulted": "03-11-2026 (auditoría §d.1, vista)", "how": "—"}],
    compilerVersion=COMPILER_VERSION,
    closedSets={"catalogTools": CATALOG_TOOLS, "systemToolsAllowed": SYSTEM_TOOLS, "approvedVersionStatuses": APPROVED_VERSION_STATUSES,
                "reasonsInOrder": REASONS, "profileFields": ["voiceId", "language", "greetingInbound", "greetingOutbound", "notice",
                                                             "maxDurationSeconds", "transferDestinations", "tools", "systemTools",
                                                             "privacy", "voicemailPolicy", "slots"],
                "voicemailPolicies": ["hang_up", "leave_message"]},
    inputShape={
        "note": "Forma NEUTRA propuesta por el fixture (D8-3: «B8 las fija al congelar y van al fixture»). Si S8 usa otros nombres, la prueba traduce; el caso no cambia.",
        "compilerVersion": "la constante VoiceAgentCompiler::VERSION",
        "env.VOICE_PER_CALL_MAX_SECONDS": "texto crudo del entorno",
        "owner": "las decisiones del propietario que el compilador aplica: recordingAllowed (P-7) y maxRetentionDays (P-8)",
        "version": "la versión del Studio: status, contentHash guardado y el recalculado, createdBy, approvedBy, handoffAliases (los destinos que nombren sus parámetros)",
        "profile": "el perfil de voz: status, createdBy, approvedBy, storedHash (su huella guardada) y content (el conjunto CERRADO de D8-6)",
    },
    decisions={
        "DF-C1": "Motivos con nombre propuesto (D8-6 los enumera sin nombrarlos) y en el orden de las viñetas de D8-6, y dentro de cada viñeta en el orden del texto; el compilador da el PRIMERO (una excepción tipada, un 422 con el motivo). Al final, las comprobaciones de forma del perfil que D8-6 implica (DF-C5).",
        "DF-C2": "Versión aprobada = APPROVED, CANARY o PUBLISHED (las que pasaron los cuatro ojos y siguen vigentes, como linkableVersionStatuses del H6). Perfil aprobado = status approved y approvedBy distinto de createdBy.",
        "DF-C3": "Los textos libres del perfil (greetingInbound, greetingOutbound y cada slot) pasan las seis comprobaciones de D8-6 sobre el texto TAL CUAL (sin quitar invisibles antes: «des» + U+00AD + «cuento» es slot_invisible); el vocabulario de descuento es el de R-02 (gratis, 2x1, precio especial) más el de su origen DISCOUNT_PATTERN (descuento, rebaja, 2 x 1…); field dice cuál falló (el primero en el orden de closedSets.profileFields, y dentro de slots por nombre).",
        "DF-C4": "El aviso del perfil tiene que ser EXACTAMENTE el texto aprobado de P-9; ausente, vacío o distinto → notice_missing (Q-12).",
        "DF-C5": "Forma cerrada del perfil: un campo de más → profile_field_not_allowed; idioma distinto de es; duración < 60 (mínimo de ElevenLabs); más de 3 destinos; audio guardado encendido (P-8) → audio_storage_enabled.",
        "DF-C6": "VOICE_PER_CALL_MAX_SECONDS: ausente o vacío → 300 (Q-11: la protección 1 pide VOICE_* a 0); no entero → 0; después recorte a [60, 600]. La duración del perfil tiene que ser ≤ el tope (el borde vale).",
        "DF-C7": "specHash solo depende del comportamiento: no de uuids, identidades, fechas ni del tope del entorno; tools y systemTools son conjuntos y los destinos se ordenan por alias; el orden de las claves no cuenta (JSON canónico).",
        "DF-C8": "Invariantes de la salida en términos neutros (la prueba los traduce a VoiceAgentSpec): mensajes iniciales que EMPIEZAN por el aviso, variable caller_authorized = no por defecto, sin sobrescrituras, grabación apagada, retención del perfil, ninguna cifra monetaria en el prompt (R-01) y ningún teléfono fuera de los destinos.",
        "DF-C9": "handoffAliases: si los parámetros de la versión nombran un destino de transferencia (por alias) que el perfil no tiene → transfer_destination_not_in_profile (R-07).",
        "DF-C11": "Un teléfono con grupos de 3-4 cifras (300 000 0031) también es una «cifra» para la regla de precios del motor, y un correo lleva un dominio que un detector de URL marca: el contrato no fija los detectores, así que en esos casos vale cualquiera de los dos motivos (reasonOneOf). Lo que nunca puede pasar es que compile.",
        "DF-C10": "El JSON canónico es el que escribe PHP, también en sus rarezas: un objeto vacío sale [], un objeto con claves 0..n-1 sale lista, U+2028 y U+2029 salen escapados. Quien calcule huellas fuera de PHP tiene que imitarlo.",
    },
))

P9 = P9_NOTICE
CONTENT = {
    "voiceId": "h8fxVoice000000000001",
    "language": "es",
    "greetingInbound": "Gracias por llamar a Iron Body Workout.",
    "greetingOutbound": "Te llamamos de Iron Body Workout porque pediste información.",
    "notice": P9,
    "maxDurationSeconds": 300,
    "transferDestinations": [{"alias": "Recepción", "e164": DEST_RECEPCION}],
    "tools": list(CATALOG_TOOLS),
    "systemTools": ["end_call", "transfer_to_number"],
    "privacy": {"retentionDays": 30, "audioStorage": False, "recording": False},
    "voicemailPolicy": "hang_up",
    "slots": {"cierre": "Que tengas un buen día.", "presentacion": "Te ayudo con planes, horarios y la sede."},
}
VERSION_HASH = sha256_hex("h8fixture-version-content-0012")
BASE = {
    "compilerVersion": COMPILER_VERSION,
    "env": {"VOICE_PER_CALL_MAX_SECONDS": "300"},
    "owner": {"recordingAllowed": False, "maxRetentionDays": 30},
    "version": {"uuid": "9a000000-0000-4000-8000-000000000012", "status": "APPROVED", "catalogVersion": "2026-10-19.1",
                "contentHash": VERSION_HASH, "recomputedContentHash": VERSION_HASH, "createdBy": 7, "approvedBy": 9, "handoffAliases": ["Recepción"]},
    "profile": {"uuid": "9b000000-0000-4000-8000-000000000003", "status": "approved", "createdBy": 9, "approvedBy": 7,
                "storedHash": content_hash(CONTENT), "content": CONTENT},
}


def mut(base, *changes, rehash=True):
    x = copy.deepcopy(base)
    for path, value in changes:
        cur = x
        keys = path.split(".")
        for k in keys[:-1]:
            cur = cur[k]
        if value is DELETE:
            del cur[keys[-1]]
        else:
            cur[keys[-1]] = value
    if rehash:
        x["profile"]["storedHash"] = content_hash(x["profile"]["content"])
    return x


DELETE = object()


def NEG(desc, inp, reason, *, field=None, basis="contrato", decision="DF-C1", ref="D8-6"):
    if isinstance(reason, tuple):
        assert all(r in REASONS for r in reason), reason
        exp = {"compiles": False, "reasonOneOf": list(reason), "http": 422}
    else:
        assert reason in REASONS, reason
        exp = {"compiles": False, "reason": reason, "http": 422}
    if field:
        exp["field"] = field
    CO.add("compile", "negative", desc, ref, basis, {"input": inp}, exp, decision=decision)


def OK(desc, inp, *, extra=None, basis="contrato", decision="DF-C8", ref="D8-6"):
    c = inp["profile"]["content"]
    inv = {
        "compilerVersion": COMPILER_VERSION,
        "language": "es",
        "voiceId": c["voiceId"],
        "maxDurationSeconds": c["maxDurationSeconds"],
        "tools": sorted(c["tools"]),
        "systemTools": sorted(c["systemTools"]),
        "transferDestinations": sorted(c["transferDestinations"], key=lambda d: d["alias"]),
        "recordingEnabled": False,
        "twilioRecordingEnabled": False,
        "retentionDays": c["privacy"]["retentionDays"],
        "audioStorage": False,
        "dynamicVariableDefaults": {"caller_authorized": "no"},
        "conversationConfigOverrideAllowed": False,
        "firstMessageInboundStartsWith": P9,
        "firstMessageOutboundStartsWith": P9,
        "firstMessageInboundContains": c["greetingInbound"],
        "firstMessageOutboundContains": c["greetingOutbound"],
        "promptHasNoMoneyFigures": True,
        "phonesOnlyInTransferDestinations": [d["e164"] for d in c["transferDestinations"]],
        "specHashIsSha256OfCanonicalSpec": True,
        "payloadHashIsSha256OfCanonicalPayload": True,
    }
    if extra:
        inv.update(extra)
    CO.add("compile", "valid", desc, ref, basis, {"input": inp}, {"compiles": True, "invariants": inv}, decision=decision)


# ── compile · válidas ───────────────────────────────────────────────────────
OK("la base: versión APPROVED y perfil aprobado por otra persona", BASE)
OK("versión CANARY", mut(BASE, ("version.status", "CANARY")), basis="decision-fixture", decision="DF-C2")
OK("versión PUBLISHED", mut(BASE, ("version.status", "PUBLISHED")), basis="decision-fixture", decision="DF-C2")
OK("sin destinos de transferencia ni transfer_to_number", mut(BASE, ("profile.content.transferDestinations", []),
                                                              ("profile.content.systemTools", ["end_call"]), ("version.handoffAliases", [])))
OK("tres destinos (el máximo)", mut(BASE, ("profile.content.transferDestinations",
                                           [{"alias": "Recepción", "e164": DEST_RECEPCION}, {"alias": "Gerencia", "e164": DEST_GERENCIA},
                                            {"alias": "Ventas", "e164": "+573000000013"}])), ref="D8-6, D8-14")
OK("duración igual al tope (300 = 300): el borde vale", mut(BASE, ("profile.content.maxDurationSeconds", 300)), basis="decision-fixture", decision="DF-C6")
OK("tope del entorno 900 recortado a 600 y perfil de 600", mut(BASE, ("env.VOICE_PER_CALL_MAX_SECONDS", "900"), ("profile.content.maxDurationSeconds", 600)),
   basis="decision-fixture", decision="DF-C6", ref="D8-15")
OK("duración mínima de ElevenLabs (60)", mut(BASE, ("profile.content.maxDurationSeconds", 60)), ref="D8-15")
OK("emoji legítimos en un slot: corazón con U+FE0F y familia con ZWJ (InvisibleCharacters los deja pasar)",
   mut(BASE, ("profile.content.slots.cierre", "Te esperamos ❤\ufe0f 👨\u200d👩\u200d👧")), ref="D8-6 (InvisibleCharacters del H7)")
OK("números que no son dinero en un slot («a las 5», «24 horas», «365 días»)",
   mut(BASE, ("profile.content.slots.presentacion", "Abrimos a las 5, atendemos 24 horas los 365 días.")))
OK("solo dos herramientas del catálogo", mut(BASE, ("profile.content.tools", ["consultar_horarios", "no_volver_a_llamar"])))
OK("retención menor que la de P-8 (15 días)", mut(BASE, ("profile.content.privacy.retentionDays", 15)), ref="D8-6, P-8")
OK("política de buzón leave_message [P-10]", mut(BASE, ("profile.content.voicemailPolicy", "leave_message")), ref="D8-6, P-10")

# ── compile · negativas de D8-6 (una por motivo; cada una cambia UNA cosa de la base) ──
for st in ["draft", "submitted", "rejected"]:
    NEG(f"perfil en {st}", mut(BASE, ("profile.status", st)), "profile_not_approved")
NEG("perfil aprobado por quien lo creó (por identidad)", mut(BASE, ("profile.approvedBy", 9)), "profile_not_approved", basis="decision-fixture",
    decision="DF-C2")
for st in ["DRAFT", "VALIDATED", "REVIEW_REQUIRED", "REJECTED", "RETIRED", "ROLLED_BACK"]:
    NEG(f"versión en {st}", mut(BASE, ("version.status", st)), "version_not_approved", basis="decision-fixture", decision="DF-C2")
NEG("la huella guardada del perfil no cuadra con su contenido", mut(BASE, ("profile.content.greetingInbound", "Gracias por llamar."), rehash=False),
    "profile_hash_mismatch")
NEG("la huella guardada de la versión no cuadra con la recalculada", mut(BASE, ("version.recomputedContentHash", sha256_hex("otra"))),
    "version_hash_mismatch")
SLOT_NEG = [
    ("slot_money", "presentacion", "Planes desde $ 89.000 al mes."),
    ("slot_money", "presentacion", "Te sale en 80 mil pesos."),
    ("slot_money", "cierre", "El mensual vale 89000."),
    ("slot_money", "greetingOutbound", "Te llamo para ofrecerte el plan de 89.000."),
    ("slot_discount", "presentacion", "La primera semana es gratis."),
    ("slot_discount", "presentacion", "Hoy tenemos 2x1."),
    ("slot_discount", "cierre", "Pregunta por el precio especial."),
    ("slot_discount", "cierre", "Pide tu descuento."),
    ("slot_discount", "presentacion", "Promoción 2 x 1 con espacios."),
    ("slot_url", "presentacion", "Mira todo en www.ironbody.co"),
    ("slot_url", "cierre", "Entra a https://ironbody.test/planes"),
    ("slot_phone", "cierre", "Llámanos al 3 0 0 0 0 0 0 0 3 1."),
    (("slot_money", "slot_phone"), "cierre", "Llámanos al 300 000 0031."),
    (("slot_money", "slot_phone"), "greetingInbound", "Gracias por llamar al +57 601 000 0011."),
    (("slot_url", "slot_email"), "cierre", "Escríbenos a hola@ironbody.test."),
    ("slot_invisible", "presentacion", "Te ayudo\u200b con planes."),
    ("slot_invisible", "presentacion", "Te ayudo \u202econ planes."),
    ("slot_invisible", "cierre", "Hasta luego\U000e0041."),
    ("slot_invisible", "cierre", "Hasta\u2028luego."),
    ("slot_invisible", "presentacion", "Pide tu des\u00adcuento."),
]
for reason, fld, text in SLOT_NEG:
    path = f"profile.content.{fld}" if fld.startswith("greeting") else f"profile.content.slots.{fld}"
    shown = fld if fld.startswith("greeting") else f"slots.{fld}"
    if isinstance(reason, tuple):
        NEG(f"{' o '.join(reason)} en {shown}: «{vis(text)}»", mut(BASE, (path, text)), reason, field=shown, basis="decision-fixture",
            decision="DF-C11", ref="D8-6")
        continue
    NEG(f"{reason} en {shown}: «{vis(text)}»", mut(BASE, (path, text)), reason, field=shown, basis="contrato" if reason != "slot_invisible" or "\u00ad" not in text else "decision-fixture",
        decision="DF-C3", ref="D8-6 (R-02, InvisibleCharacters)" if reason in ("slot_discount", "slot_invisible") else "D8-6")
NEG("dos problemas en el mismo slot (cifra y «gratis»): sale el primero del orden, slot_money", mut(BASE, ("profile.content.slots.presentacion",
    "Desde $ 89.000 y la matrícula gratis.")), "slot_money", field="slots.presentacion")
NEG("dos campos con problemas distintos (URL en el saludo de entrada, cifra en un slot): manda el motivo, slot_money, y su campo",
    mut(BASE, ("profile.content.greetingInbound", "Gracias por llamar, mira www.ironbody.co"), ("profile.content.slots.cierre", "Desde $ 89.000.")),
    "slot_money", field="slots.cierre", basis="decision-fixture", decision="DF-C1")
NEG("herramienta fuera del catálogo (aplicar_descuento)", mut(BASE, ("profile.content.tools", CATALOG_TOOLS + ["aplicar_descuento"])), "tool_not_in_catalog")
NEG("herramienta de cobro (enviar_enlace_pago)", mut(BASE, ("profile.content.tools", ["consultar_planes", "enviar_enlace_pago"])), "tool_not_in_catalog",
    ref="D8-6, D8-10")
for st in ["skip_turn", "language_detection", "transfer_to_agent", "play_keypad_touch_tone", "voicemail_detection"]:
    NEG(f"herramienta de sistema {st}", mut(BASE, ("profile.content.systemTools", ["end_call", "transfer_to_number", st])), "system_tool_not_allowed")
NEG("falta end_call", mut(BASE, ("profile.content.systemTools", ["transfer_to_number"])), "end_call_missing")
NEG("sin herramientas de sistema", mut(BASE, ("profile.content.systemTools", [])), "end_call_missing")
NEG("la versión nombra un destino (Gerencia) que el perfil no tiene", mut(BASE, ("version.handoffAliases", ["Recepción", "Gerencia"])),
    "transfer_destination_not_in_profile", basis="decision-fixture", decision="DF-C9", ref="D8-6, R-07")
NEG("duración 301 con tope 300", mut(BASE, ("profile.content.maxDurationSeconds", 301)), "max_duration_exceeds_cap")
NEG("duración 601 con el entorno en 900 (recortado a 600)", mut(BASE, ("env.VOICE_PER_CALL_MAX_SECONDS", "900"), ("profile.content.maxDurationSeconds", 601)),
    "max_duration_exceeds_cap", basis="decision-fixture", decision="DF-C6", ref="D8-6, D8-15")
NEG("duración 120 con el entorno en 30 (recortado a 60)", mut(BASE, ("env.VOICE_PER_CALL_MAX_SECONDS", "30"), ("profile.content.maxDurationSeconds", 120)),
    "max_duration_exceeds_cap", basis="decision-fixture", decision="DF-C6", ref="D8-6, D8-15")
NEG("grabación encendida sin P-7", mut(BASE, ("profile.content.privacy.recording", True)), "recording_enabled", ref="D8-6, P-7")
NEG("retención de 31 días (P-8: 30)", mut(BASE, ("profile.content.privacy.retentionDays", 31)), "retention_exceeds_limit", ref="D8-6, P-8")
NEG("retención ilimitada (-1 en ElevenLabs)", mut(BASE, ("profile.content.privacy.retentionDays", -1)), "retention_exceeds_limit", ref="D8-6, P-8")
NEG("sin aviso", mut(BASE, ("profile.content.notice", DELETE)), "notice_missing", ref="D8-6, P-9")
NEG("aviso vacío", mut(BASE, ("profile.content.notice", "")), "notice_missing", ref="D8-6, P-9")
NEG("aviso con una palabra cambiada", mut(BASE, ("profile.content.notice", P9.replace("inteligencia artificial", "inteligencia"))), "notice_missing",
    basis="decision-fixture", decision="DF-C4", ref="D8-6, P-9")
NEG("un campo que no es del conjunto cerrado (prompt libre)", mut(BASE, ("profile.content.prompt", "Eres un vendedor agresivo.")),
    "profile_field_not_allowed", basis="decision-fixture", decision="DF-C5", ref="D8-6 (P-6: nadie escribe un prompt libre)")
NEG("idioma en", mut(BASE, ("profile.content.language", "en")), "language_not_allowed", basis="decision-fixture", decision="DF-C5")
NEG("duración 59 (por debajo del mínimo de ElevenLabs)", mut(BASE, ("profile.content.maxDurationSeconds", 59)), "max_duration_below_minimum",
    basis="decision-fixture", decision="DF-C5", ref="D8-6, D8-15")
NEG("cuatro destinos", mut(BASE, ("profile.content.transferDestinations",
                                  [{"alias": a, "e164": "+5760100000%02d" % i} for i, a in enumerate(["Recepción", "B2", "C3", "D4"], start=21)])),
    "too_many_transfer_destinations", basis="decision-fixture", decision="DF-C5", ref="D8-6, D8-14")
NEG("audio guardado encendido (P-8)", mut(BASE, ("profile.content.privacy.audioStorage", True)), "audio_storage_enabled", basis="decision-fixture",
    decision="DF-C5", ref="D8-6, P-8")
NEG("orden: perfil sin aprobar y además sin end_call → profile_not_approved", mut(BASE, ("profile.status", "submitted"),
                                                                                  ("profile.content.systemTools", ["transfer_to_number"])),
    "profile_not_approved")
NEG("orden: slot con URL y además grabación encendida → slot_url", mut(BASE, ("profile.content.slots.cierre", "www.ironbody.co"),
                                                                       ("profile.content.privacy.recording", True)), "slot_url", field="slots.cierre")

# ── capClamp (D8-15) ────────────────────────────────────────────────────────
for raw_v, cap, basis in [("300", 300, "contrato"), ("600", 600, "contrato"), ("900", 600, "contrato"), ("60", 60, "contrato"), ("30", 60, "contrato"),
                          ("0", 60, "decision-fixture"), (None, 300, "decision-fixture"), ("", 300, "decision-fixture"), ("abc", 60, "decision-fixture"),
                          ("-5", 60, "decision-fixture"), ("300.5", 60, "decision-fixture")]:
    CO.add("capClamp", "perCallMax", f"VOICE_PER_CALL_MAX_SECONDS = {raw_v!r} → tope {cap}", "D8-15", basis, {"raw": raw_v}, {"capSeconds": cap},
           decision="DF-C6" if basis != "contrato" else None)

# ── hashRelations (DF-C7) ───────────────────────────────────────────────────
def REL(desc, b, same, *, a=BASE, basis="decision-fixture", decision="DF-C7"):
    CO.add("hashRelations", "specHash", desc, "D8-6, D8-7", basis, {"inputA": a, "inputB": b}, {"sameSpecHash": same}, decision=decision)


_reordered = copy.deepcopy(BASE)
_reordered["profile"]["content"] = dict(reversed(list(BASE["profile"]["content"].items())))
REL("la misma entrada dos veces → la misma huella (determinista)", copy.deepcopy(BASE), True, basis="contrato", decision=None)
REL("las claves del perfil en otro orden → la misma huella", _reordered, True)
REL("otro uuid de perfil y de versión (mismo contenido) → la misma huella", mut(BASE, ("profile.uuid", "9b000000-0000-4000-8000-000000000099"),
                                                                                  ("version.uuid", "9a000000-0000-4000-8000-000000000099")), True)
REL("otras identidades de autor y aprobador → la misma huella", mut(BASE, ("profile.createdBy", 11), ("profile.approvedBy", 12)), True)
REL("herramientas en otro orden → la misma huella (conjunto)", mut(BASE, ("profile.content.tools", list(reversed(CATALOG_TOOLS)))), True)
REL("otro tope del entorno con el mismo perfil (300 ≤ 600) → la misma huella", mut(BASE, ("env.VOICE_PER_CALL_MAX_SECONDS", "600")), True)
REL("un carácter del saludo de entrada → otra huella", mut(BASE, ("profile.content.greetingInbound", "Gracias por llamar a Iron Body Workout!")), False,
    basis="contrato", decision=None)
REL("otra voz → otra huella", mut(BASE, ("profile.content.voiceId", "h8fxVoice000000000002")), False, basis="contrato", decision=None)
REL("otra duración (240) → otra huella", mut(BASE, ("profile.content.maxDurationSeconds", 240)), False, basis="contrato", decision=None)
REL("una herramienta menos → otra huella", mut(BASE, ("profile.content.tools", CATALOG_TOOLS[:3])), False, basis="contrato", decision=None)
REL("otro número de destino con el mismo alias → otra huella", mut(BASE, ("profile.content.transferDestinations",
                                                                          [{"alias": "Recepción", "e164": DEST_GERENCIA}])), False, basis="contrato", decision=None)
REL("otra retención → otra huella", mut(BASE, ("profile.content.privacy.retentionDays", 15)), False, basis="contrato", decision=None)
REL("otra versión del Studio (otro contenido) → otra huella", mut(BASE, ("version.contentHash", sha256_hex("v13")),
                                                                  ("version.recomputedContentHash", sha256_hex("v13"))), False, basis="contrato", decision=None)
_cv = mut(BASE, ("compilerVersion", "ultron-voice-compiler/2"))
REL("otra versión del compilador → otra huella", _cv, False, basis="contrato", decision=None)

# ── canonicalHash: vectores exactos (Python = PHP salvo las rarezas de DF-C10, escritas a mano) ──
def CH(desc, value, canon=None, *, throws=None, basis="contrato", decision=None):
    if throws:
        exp = {"throws": throws}
    else:
        if canon is None:
            canon = canonical(value)
        exp = {"canonicalJson": canon, "sha256": sha256_hex(canon)}
    CO.add("canonicalHash", "hash", desc, "D8-6 (StudioContentHasher)", basis, {"value": value}, exp, decision=decision)


CH("el contenido del perfil base: es la huella de profile.storedHash", CONTENT)
CH("claves ordenadas a todos los niveles; las listas conservan su orden", {"b": {"d": 1, "c": 2}, "a": [3, {"f": True, "e": None}, 1]},
   '{"a":[3,{"e":null,"f":true},1],"b":{"c":2,"d":1}}')
CH("Unicode y barras sin escapar", {"t": "Peña / Ñandú 🎉 «hola» \"comillas\" \\ barra"})
CH("las claves se ordenan por bytes: mayúsculas antes que minúsculas y «10» antes que «9»", {"b": 1, "B": 2, "10": 3, "9": 4},
   '{"10":3,"9":4,"B":2,"b":1}')
CH("un entero mayor que 2^53 conserva todas sus cifras", {"n": 9007199254740993}, '{"n":9007199254740993}')
CH("un objeto vacío: PHP lo escribe []", {"x": {}}, '{"x":[]}', basis="decision-fixture", decision="DF-C10")
CH("un objeto con claves 0 y 1: PHP lo escribe como lista", {"0": "a", "1": "b"}, '["a","b"]', basis="decision-fixture", decision="DF-C10")
CH("U+2028 dentro de un texto: PHP lo escapa", {"t": "a\u2028b"}, '{"t":"a\\u2028b"}', basis="decision-fixture", decision="DF-C10")
CH("un decimal se rechaza", {"precio": 89000.5}, throws="InvalidArgumentException")
assert canonical({"b": {"d": 1, "c": 2}, "a": [3, {"f": True, "e": None}, 1]}) == '{"a":[3,{"e":null,"f":true},1],"b":{"c":2,"d":1}}'
assert canonical({"b": 1, "B": 2, "10": 3, "9": 4}) == '{"10":3,"9":4,"B":2,"b":1}'

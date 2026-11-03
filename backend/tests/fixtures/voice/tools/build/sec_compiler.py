# compiler-cases.json · StudioVoiceCompiler (S8-a) y la huella de su salida (D8-6, D8-7, D8-11, D8-14, D8-15, D8-17; Enmienda 1: E1-3, E1-5..E1-9)
#   canonicalHash · el JSON canónico de StudioContentHasher y su sha256: la forma reproducible de specHash, payloadHash y la huella del perfil
#   capClamp      · VOICE_PER_CALL_MAX_SECONDS con su recorte a 60–600
#   compile       · compilaciones válidas (invariantes de la salida) y las negativas, una por motivo
#   hashRelations · qué cambia y qué no cambia specHash
# E1-9 alinea este fichero con el compilador real: emoji rechazado, `leave_message` no existe, transferir exige `solicitar_humano`,
# `no_volver_a_llamar` no se quita, el primer motivo es el del primer campo declarado y los uuids entran en specHash. Las huellas
# del compilador real (expected.realCompiler) salen de tools/compiler-export.php (instantánea en tools/build/compiler-export.json).
import copy

COMPILER_VERSION = "ultron-voice-compiler/1"
CATALOG_TOOLS = ["consultar_planes", "consultar_horarios", "solicitar_humano", "no_volver_a_llamar"]
SYSTEM_TOOLS = ["end_call", "transfer_to_number"]
APPROVED_VERSION_STATUSES = ["APPROVED", "CANARY", "PUBLISHED"]
TEXT_CHECKS = ["slot_invisible", "slot_email", "slot_phone", "slot_url", "slot_money", "slot_discount", "slot_characters"]
REASONS = ["version_not_approved", "version_hash_mismatch",
           "profile_not_approved", "profile_hash_mismatch", "profile_field_not_allowed", "language_not_allowed", "notice_missing",
           *TEXT_CHECKS,
           "max_duration_below_minimum", "max_duration_exceeds_cap",
           "tool_not_in_catalog", "system_tool_not_allowed", "end_call_missing", "opt_out_tool_missing", "transfer_requires_human_tool",
           "too_many_transfer_destinations", "recording_enabled", "audio_storage_enabled", "retention_exceeds_limit", "voicemail_not_allowed",
           "transfer_destination_not_in_profile"]
TEXT_FIELDS = ["greetingInbound", "greetingOutbound", "slots.presentacion", "slots.cierre"]

CO = Fixture("compiler-cases.json", header(
    "compiler-cases.json",
    ["D8-6 (entradas, salidas, plantilla y negativas del compilador)", "D8-7 (agente inmutable; laboratorio)", "D8-11 (caller_authorized = no por defecto; sin conversation_config_override)",
     "D8-14 (destinos de transferencia: como mucho 3, con alias)", "D8-15 (duración por llamada 60–7 200 en ElevenLabs, ≤ VOICE_PER_CALL_MAX_SECONDS con recorte a 60–600)",
     "D8-17 (aviso fijo al empezar cada llamada)", "Congelación: P-3, P-6, P-7, P-8",
     "Enmienda 1: E1-3 (aviso), E1-5 (call_direction), E1-6 (primer mensaje = solo el aviso), E1-7 (versiones que compilan), E1-8 (reglas propias del compilador), E1-9 (alineación con el compilador)"],
    [{"what": "StudioContentHasher (H5): sha256 del JSON canónico (claves ordenadas a todos los niveles, listas en su orden, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES; decimales rechazados)",
      "url": "backend/app/Services/UltronStudio/StudioContentHasher.php", "consulted": "03-11-2026 (en este árbol, sin cambios desde el H5)",
      "how": "Los vectores de canonicalHash se recalculan con PHP 8.3 cargando solo esa clase (voice-fixtures-check.py --php)."},
     {"what": "El compilador real de S8-a y su plantilla (E1-9: el fixture se alinea con él y reexporta sus huellas)",
      "url": "backend/app/Services/UltronStudio/StudioVoiceCompiler.php y StudioVoiceTemplate.php", "consulted": "03-11-2026 (commit 21c373ff más el aviso de E1-3 en el árbol de trabajo)",
      "how": "tools/compiler-export.php pasa cada caso por el compilador con la traducción de StudioVoiceCompilerParityTest (S8-a); ver realCompilerExport."},
     {"what": "max_duration_seconds de 60 a 7 200 s", "url": "https://elevenlabs.io/docs/eleven-agents/customization/tools/system-tools/end-call",
      "consulted": "03-11-2026 (auditoría §d.1, vista)", "how": "—"}],
    compilerVersion=COMPILER_VERSION,
    closedSets={"catalogTools": CATALOG_TOOLS, "systemToolsAllowed": SYSTEM_TOOLS, "approvedVersionStatuses": APPROVED_VERSION_STATUSES,
                "reasonsInOrder": REASONS, "textChecksInOrder": TEXT_CHECKS,
                "profileFields": ["voiceId", "language", "notice", "greetingInbound", "greetingOutbound", "slots", "maxDurationSeconds", "tools",
                                  "systemTools", "transferDestinations", "privacy", "voicemailPolicy"],
                "textFieldsInOrder": TEXT_FIELDS, "voicemailPolicies": ["hang_up"],
                "dynamicVariables": ["call_direction", "call_ref", "caller_authorized", "version_label"]},
    inputShape={
        "note": "Forma NEUTRA del fixture (D8-3). La prueba de paridad de S8 (StudioVoiceCompilerParityTest) y tools/compiler-export.php la traducen a la del compilador: notice → aiNotice, greetingInbound/greetingOutbound → greetings.{inbound,outbound}, slots.{presentacion,cierre} → holes.{presentation,closing}, transferDestinations[{alias,e164}] → transfer.destinations[{alias,phoneHash}] (huella voice-phone), privacy.audioStorage → privacy.storeAudio, voicemailPolicy → voicemail.",
        "compilerVersion": "la constante StudioVoiceCompiler::VERSION",
        "env.VOICE_PER_CALL_MAX_SECONDS": "texto crudo del entorno",
        "owner": "las decisiones del propietario que el compilador aplica: recordingAllowed (P-7) y maxRetentionDays (P-8)",
        "version": "la versión del Studio: status, contentHash guardado y el recalculado, createdBy, approvedBy, handoffAliases (DF-C9)",
        "profile": "el perfil de voz: status, createdBy, approvedBy, storedHash (sha256 del JSON canónico del content neutro) y content (el conjunto CERRADO de D8-6)",
    },
    decisions={
        "DF-C1": "Motivos con nombre NEUTRO (la paridad de S8 traduce los del compilador: text_* → slot_*, *_four_eyes → *_not_approved, notice_mismatch → notice_missing…) y el orden del COMPILADOR REAL (E1-9): primero la versión, después el perfil campo a campo en el orden declarado (closedSets.profileFields y textFieldsInOrder) y, dentro de cada texto, el orden de la plantilla (textChecksInOrder). Se da el PRIMERO.",
        "DF-C3": "Los textos se comprueban TAL CUAL (sin quitar invisibles antes: «des» + U+00AD + «cuento» es slot_invisible). Descuento = R-02 (gratis, 2x1, precio especial) más su origen DISCOUNT_PATTERN (descuento, rebaja, 2 x 1…). Caracteres (E1-8): sin llaves, comillas angulares, símbolos ni emojis.",
        "DF-C4": "El aviso del perfil tiene que ser EXACTAMENTE el de la plantilla vigente (E1-3); ausente, vacío o distinto → notice_missing (el compilador distingue notice_missing y notice_mismatch).",
        "DF-C5": "Forma cerrada del perfil: un campo de más → profile_field_not_allowed; idioma distinto de es; duración < 60 (mínimo de ElevenLabs); más de 3 destinos; audio guardado encendido (P-8) → audio_storage_enabled.",
        "DF-C6": "VOICE_PER_CALL_MAX_SECONDS: ausente o vacío → 300 (Q-11: la protección 1 pide VOICE_* a 0); no entero → 0; después recorte a [60, 600]. La duración del perfil tiene que ser ≤ el tope (el borde vale).",
        "DF-C7": "specHash: entran los uuids de la versión y del perfil (E1-9: la procedencia por artefacto) y sus huellas; NO entran las identidades, las fechas ni el tope del entorno; tools y systemTools son conjuntos y los destinos van ordenados por alias; el orden de las claves no cuenta (JSON canónico).",
        "DF-C8": "Invariantes de la salida en términos neutros: el primer mensaje es SOLO el aviso (E1-6) y los saludos van en el prompt; caller_authorized = no por defecto; las variables dinámicas de E1-5; sin sobrescrituras; grabación apagada; retención del perfil; los destinos por alias y por su huella voice-phone (ref); ningún teléfono en la especificación; ninguna cifra monetaria en el prompt (R-01).",
        "DF-C9": "handoffAliases: si los parámetros de la versión nombran un destino que el perfil no tiene → transfer_destination_not_in_profile (R-07). El compilador de S8-a no lo lee (R-07 se aplica al desplegar con assertDestinationsMatch): es la única discrepancia declarada (realCompiler.knownMismatch).",
        "DF-C10": "El JSON canónico es el que escribe PHP, también en sus rarezas: un objeto vacío sale [], un objeto con claves 0..n-1 sale lista, U+2028 y U+2029 salen escapados. Quien calcule huellas fuera de PHP tiene que imitarlo.",
        "DF-C12": "expected.realCompiler son las huellas y los motivos que da HOY el compilador real (realCompilerExport): una instantánea de regresión, no una derivación independiente. Si S8 cambia la plantilla a propósito, se reexporta (php tools/compiler-export.php) y el cambio de huellas se revisa; si cambia sin querer, la paridad lo caza.",
    },
))

CONTENT = {
    "voiceId": "h8fxVoice000000000001",
    "language": "es",
    "notice": AI_NOTICE,
    "greetingInbound": "Gracias por llamar a Iron Body Workout.",
    "greetingOutbound": "Te llamamos de Iron Body Workout porque pediste información.",
    "slots": {"presentacion": "Te ayudo con planes, horarios y la sede.", "cierre": "Que tengas un buen día."},
    "maxDurationSeconds": 300,
    "tools": list(CATALOG_TOOLS),
    "systemTools": ["end_call", "transfer_to_number"],
    "transferDestinations": [{"alias": "Recepción", "e164": DEST_RECEPCION}],
    "privacy": {"retentionDays": 30, "audioStorage": False, "recording": False},
    "voicemailPolicy": "hang_up",
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
DELETE = object()


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


# ── La instantánea del compilador real (E1-9) ──────────────────────────────
EXPORT_PATH = HERE / "compiler-export.json"
EXPORT = json.loads(EXPORT_PATH.read_text(encoding="utf-8")) if EXPORT_PATH.is_file() else None
KNOWN_MISMATCH = {"compile · negative · la versión nombra un destino (Gerencia) que el perfil no tiene":
                  "el compilador no lee handoffAliases: R-07 se aplica al desplegar (StudioVoiceCompiler::assertDestinationsMatch)"}
STALE = []


def real_for(name, inp, section="compile"):
    """Lo que da el compilador real para esta entrada, si la instantánea es de esta misma entrada."""
    if EXPORT is None:
        return None
    got = EXPORT[section].get(name)
    if got is None or got.get("inputSha256") != content_hash(inp):
        STALE.append(name)
        return None
    return got


def NEG(desc, inp, reason, *, field=None, basis="contrato", decision="DF-C1", ref="D8-6"):
    assert reason in REASONS, reason
    exp = {"compiles": False, "reason": reason, "http": 422}
    if field:
        exp["field"] = field
    name = f"compile · negative · {desc}"
    real = real_for(name, inp)
    if real is not None:
        if name in KNOWN_MISMATCH:
            assert real["compiles"] is True, name
            exp["realCompiler"] = {"compiles": True, "specHash": real["specHash"], "knownMismatch": KNOWN_MISMATCH[name], "decision": "DF-C12"}
        else:
            assert real["compiles"] is False and real["reason"] == reason and (field is None or real.get("field") == field) and real["http"] == 422, \
                (name, real, reason, field)
            exp["realCompiler"] = {"reason": real["compilerReason"], "field": real["compilerField"], "decision": "DF-C12"}
    CO.add("compile", "negative", desc, ref, basis, {"input": inp}, exp, decision=decision)


def OK(desc, inp, *, basis="contrato", decision="DF-C8", ref="D8-6"):
    c = inp["profile"]["content"]
    dests = sorted(c["transferDestinations"], key=lambda d: d["alias"].encode())
    inv = {
        "compilerVersion": COMPILER_VERSION,
        "language": "es",
        "voiceId": c["voiceId"],
        "maxDurationSeconds": c["maxDurationSeconds"],
        "tools": sorted(c["tools"]),
        "systemTools": sorted(c["systemTools"]),
        "transferDestinationAliases": [d["alias"] for d in dests],
        "transferDestinationRefs": [phone_hash(d["e164"]) for d in dests],
        "recordingEnabled": False,
        "twilioRecordingEnabled": False,
        "retentionDays": c["privacy"]["retentionDays"],
        "audioStorage": False,
        "dynamicVariableDefaults": {"caller_authorized": "no"},
        "dynamicVariables": ["call_direction", "call_ref", "caller_authorized", "version_label"],
        "conversationConfigOverrideAllowed": False,
        "firstMessageEquals": AI_NOTICE,
        "systemPromptContainsGreetings": [c["greetingInbound"], c["greetingOutbound"]],
        "promptHasNoMoneyFigures": True,
        "phonesNotInSpec": [d["e164"] for d in c["transferDestinations"]],
        "specHashIsSha256OfCanonicalSpec": True,
        "payloadHashIsSha256OfCanonicalPayload": True,
    }
    exp = {"compiles": True, "invariants": inv}
    name = f"compile · valid · {desc}"
    real = real_for(name, inp)
    if real is not None:
        assert real["compiles"] is True, (name, real)
        assert real["firstMessage"] == AI_NOTICE, name
        exp["realCompiler"] = {"specHash": real["specHash"], "voiceProfileHash": real["voiceProfileHash"], "decision": "DF-C12"}
    CO.add("compile", "valid", desc, ref, basis, {"input": inp}, exp, decision=decision)


# ── compile · válidas ───────────────────────────────────────────────────────
OK("la base: versión APPROVED y perfil aprobado por otra persona", BASE)
OK("versión CANARY", mut(BASE, ("version.status", "CANARY")), ref="D8-6, E1-7")
OK("versión PUBLISHED", mut(BASE, ("version.status", "PUBLISHED")), ref="D8-6, E1-7")
OK("sin destinos de transferencia ni transfer_to_number", mut(BASE, ("profile.content.transferDestinations", []),
                                                              ("profile.content.systemTools", ["end_call"]), ("version.handoffAliases", [])))
OK("tres destinos (el máximo)", mut(BASE, ("profile.content.transferDestinations",
                                           [{"alias": "Recepción", "e164": DEST_RECEPCION}, {"alias": "Gerencia", "e164": DEST_GERENCIA},
                                            {"alias": "Ventas", "e164": "+573000000013"}])), ref="D8-6, D8-14")
OK("duración igual al tope (300 = 300): el borde vale", mut(BASE, ("profile.content.maxDurationSeconds", 300)), basis="decision-fixture", decision="DF-C6")
OK("tope del entorno 900 recortado a 600 y perfil de 600", mut(BASE, ("env.VOICE_PER_CALL_MAX_SECONDS", "900"), ("profile.content.maxDurationSeconds", 600)),
   basis="decision-fixture", decision="DF-C6", ref="D8-15")
OK("duración mínima de ElevenLabs (60)", mut(BASE, ("profile.content.maxDurationSeconds", 60)), ref="D8-15")
OK("números que no son dinero en un slot («a las 5», «24 horas», «365 días»)",
   mut(BASE, ("profile.content.slots.presentacion", "Abrimos a las 5, atendemos 24 horas los 365 días.")))
OK("dos herramientas sin transferir (no_volver_a_llamar se queda)", mut(BASE, ("profile.content.tools", ["consultar_horarios", "no_volver_a_llamar"]),
                                                                        ("profile.content.systemTools", ["end_call"]),
                                                                        ("profile.content.transferDestinations", []), ("version.handoffAliases", [])),
   ref="D8-6, E1-8")
OK("sin consultar_planes (la voz no dice precios)", mut(BASE, ("profile.content.tools", ["consultar_horarios", "solicitar_humano", "no_volver_a_llamar"])),
   ref="D8-6, P-3")
OK("retención menor que la de P-8 (15 días)", mut(BASE, ("profile.content.privacy.retentionDays", 15)), ref="D8-6, P-8")
OK("herramientas y destinos en otro orden compilan igual", mut(BASE, ("profile.content.tools", list(reversed(CATALOG_TOOLS)))),
   basis="decision-fixture", decision="DF-C7")

# ── compile · negativas (cada una cambia UNA cosa de la base, salvo las de orden) ──
for st in ["draft", "submitted", "rejected"]:
    NEG(f"perfil en {st}", mut(BASE, ("profile.status", st)), "profile_not_approved")
NEG("perfil aprobado por quien lo creó (por identidad)", mut(BASE, ("profile.approvedBy", 9)), "profile_not_approved")
for st in ["DRAFT", "VALIDATED", "REVIEW_REQUIRED", "REJECTED", "RETIRED", "ROLLED_BACK"]:
    NEG(f"versión en {st}", mut(BASE, ("version.status", st)), "version_not_approved", ref="D8-6, E1-7")
NEG("la huella guardada del perfil no cuadra con su contenido", mut(BASE, ("profile.content.greetingInbound", "Gracias por llamar."), rehash=False),
    "profile_hash_mismatch")
NEG("la huella guardada de la versión no cuadra con la recalculada", mut(BASE, ("version.recomputedContentHash", sha256_hex("otra"))),
    "version_hash_mismatch")
SLOT_NEG = [
    ("slot_money", "slots.presentacion", "Planes desde $ 89.000 al mes."),
    ("slot_money", "slots.presentacion", "Te sale en 80 mil pesos."),
    ("slot_money", "slots.cierre", "El mensual vale 89000."),
    ("slot_money", "greetingOutbound", "Te llamo para ofrecerte el plan de 89.000."),
    ("slot_discount", "slots.presentacion", "La primera semana es gratis."),
    ("slot_discount", "slots.presentacion", "Hoy tenemos 2x1."),
    ("slot_discount", "slots.cierre", "Pregunta por el precio especial."),
    ("slot_discount", "slots.cierre", "Pide tu descuento."),
    ("slot_discount", "slots.presentacion", "Promoción 2 x 1 con espacios."),
    ("slot_url", "slots.presentacion", "Mira todo en www.ironbody.co"),
    ("slot_url", "slots.cierre", "Entra a https://ironbody.test/planes"),
    ("slot_phone", "slots.cierre", "Llámanos al 3 0 0 0 0 0 0 0 3 1."),
    ("slot_phone", "slots.cierre", "Llámanos al 300 000 0031."),
    ("slot_phone", "greetingInbound", "Gracias por llamar al +57 601 000 0011."),
    ("slot_email", "slots.cierre", "Escríbenos a hola@ironbody.test."),
    ("slot_invisible", "slots.presentacion", "Te ayudo\u200b con planes."),
    ("slot_invisible", "slots.presentacion", "Te ayudo \u202econ planes."),
    ("slot_invisible", "slots.cierre", "Hasta luego\U000e0041."),
    ("slot_invisible", "slots.cierre", "Hasta\u2028luego."),
    ("slot_invisible", "slots.presentacion", "Pide tu des\u00adcuento."),
    ("slot_characters", "slots.cierre", "Te esperamos ❤\ufe0f \U0001f468\u200d\U0001f469\u200d\U0001f467"),
    ("slot_characters", "slots.cierre", "Hasta pronto \U0001f600"),
    ("slot_characters", "greetingInbound", "Hola {{system__caller_id}}, gracias por llamar."),
    ("slot_characters", "slots.presentacion", "Te cuento sobre «nuestros planes»."),
    ("slot_characters", "slots.presentacion", "Somos la sede #1 de la ciudad."),
]
for reason, fld, text in SLOT_NEG:
    path = f"profile.content.{fld}"
    ref = "D8-6, E1-8" if reason == "slot_characters" else ("D8-6 (R-02, InvisibleCharacters)" if reason in ("slot_discount", "slot_invisible") else "D8-6")
    basis = "decision-fixture" if (reason == "slot_invisible" and "\u00ad" in text) else "contrato"
    NEG(f"{reason} en {fld}: «{vis(text)}»", mut(BASE, (path, text)), reason, field=fld, basis=basis, decision="DF-C3", ref=ref)
NEG("dos problemas en el mismo slot (cifra y «gratis»): manda el orden de la plantilla, slot_money",
    mut(BASE, ("profile.content.slots.presentacion", "Desde $ 89.000 y la matrícula gratis.")), "slot_money", field="slots.presentacion")
NEG("dos campos con problemas distintos (cifra en el saludo de entrada, URL en un slot): manda el primer campo declarado aunque su motivo vaya después, slot_money en greetingInbound",
    mut(BASE, ("profile.content.greetingInbound", "Gracias por llamar por el plan de 89.000."), ("profile.content.slots.cierre", "Mira www.ironbody.co")),
    "slot_money", field="greetingInbound", ref="D8-6, E1-9")
NEG("herramienta fuera del catálogo (aplicar_descuento)", mut(BASE, ("profile.content.tools", CATALOG_TOOLS + ["aplicar_descuento"])), "tool_not_in_catalog")
NEG("herramienta de cobro (enviar_enlace_pago)", mut(BASE, ("profile.content.tools", ["consultar_planes", "enviar_enlace_pago", "no_volver_a_llamar"])),
    "tool_not_in_catalog", ref="D8-6, D8-10")
for st in ["skip_turn", "language_detection", "transfer_to_agent", "play_keypad_touch_tone", "voicemail_detection"]:
    NEG(f"herramienta de sistema {st}", mut(BASE, ("profile.content.systemTools", ["end_call", "transfer_to_number", st])), "system_tool_not_allowed")
NEG("falta end_call", mut(BASE, ("profile.content.systemTools", ["transfer_to_number"])), "end_call_missing")
NEG("sin herramientas de sistema", mut(BASE, ("profile.content.systemTools", [])), "end_call_missing")
NEG("sin no_volver_a_llamar (la revocación oral de la Ley 2300)", mut(BASE, ("profile.content.tools", CATALOG_TOOLS[:3])), "opt_out_tool_missing",
    ref="D8-6, D8-10, E1-8")
NEG("transfer_to_number sin solicitar_humano", mut(BASE, ("profile.content.tools", ["consultar_horarios", "no_volver_a_llamar"])),
    "transfer_requires_human_tool", ref="D8-6, D8-14, E1-8")
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
NEG("buzón leave_message: no existe (solo hang_up)", mut(BASE, ("profile.content.voicemailPolicy", "leave_message")), "voicemail_not_allowed",
    ref="D8-6, P-10, E1-8")
NEG("sin aviso", mut(BASE, ("profile.content.notice", DELETE)), "notice_missing", ref="D8-6, E1-3")
NEG("aviso vacío", mut(BASE, ("profile.content.notice", "")), "notice_missing", ref="D8-6, E1-3")
NEG("aviso con una palabra cambiada", mut(BASE, ("profile.content.notice", AI_NOTICE.replace("inteligencia artificial", "inteligencia"))), "notice_missing",
    basis="decision-fixture", decision="DF-C4", ref="D8-6, E1-3")
NEG("el aviso antiguo de P-9 (sustituido por E1-3)", mut(BASE, ("profile.content.notice",
    "Hola, te habla el asistente virtual de Iron Body Workout, un sistema de inteligencia artificial. Esta llamada se procesa de forma automática y tus datos se tratan según nuestra política de privacidad. Si prefieres hablar con una persona, dímelo en cualquier momento.")),
    "notice_missing", basis="decision-fixture", decision="DF-C4", ref="D8-6, E1-3")
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
NEG("orden: versión sin aprobar y perfil sin aprobar → manda la versión", mut(BASE, ("version.status", "DRAFT"), ("profile.status", "draft")),
    "version_not_approved", ref="D8-6, E1-9")
NEG("orden: slot con URL y además grabación encendida → slot_url", mut(BASE, ("profile.content.slots.cierre", "www.ironbody.co"),
                                                                       ("profile.content.privacy.recording", True)), "slot_url", field="slots.cierre")

# ── capClamp (D8-15) ────────────────────────────────────────────────────────
for raw_v, cap, basis in [("300", 300, "contrato"), ("600", 600, "contrato"), ("900", 600, "contrato"), ("60", 60, "contrato"), ("30", 60, "contrato"),
                          ("0", 60, "decision-fixture"), (None, 300, "decision-fixture"), ("", 300, "decision-fixture"), ("abc", 60, "decision-fixture"),
                          ("-5", 60, "decision-fixture"), ("300.5", 60, "decision-fixture")]:
    CO.add("capClamp", "perCallMax", f"VOICE_PER_CALL_MAX_SECONDS = {raw_v!r} → tope {cap}", "D8-15", basis, {"raw": raw_v}, {"capSeconds": cap},
           decision="DF-C6" if basis != "contrato" else None)


# ── hashRelations (DF-C7, E1-9) ─────────────────────────────────────────────
def REL(desc, b, same, *, a=BASE, basis="decision-fixture", decision="DF-C7", ref="D8-6, D8-7"):
    exp = {"sameSpecHash": same}
    name = f"hashRelations · specHash · {desc}"
    if EXPORT is not None:
        got = EXPORT["hashRelations"].get(name)
        if got is None or got.get("inputSha256A") != content_hash(a) or got.get("inputSha256B") != content_hash(b):
            STALE.append(name)
        else:
            ra, rb = got["a"], got["b"]
            if "notApplicable" in rb:
                exp["realCompiler"] = {"specHashA": ra["specHash"], "notApplicable": rb["notApplicable"], "decision": "DF-C12"}
            else:
                assert ra["compiles"] and rb["compiles"], (name, ra, rb)
                assert (ra["specHash"] == rb["specHash"]) == same, (name, ra["specHash"], rb["specHash"], same)
                exp["realCompiler"] = {"specHashA": ra["specHash"], "specHashB": rb["specHash"], "decision": "DF-C12"}
    CO.add("hashRelations", "specHash", desc, ref, basis, {"inputA": a, "inputB": b}, exp, decision=decision)


_reordered = copy.deepcopy(BASE)
_reordered["profile"]["content"] = dict(reversed(list(BASE["profile"]["content"].items())))
TWO = mut(BASE, ("profile.content.transferDestinations", [{"alias": "Recepción", "e164": DEST_RECEPCION}, {"alias": "Gerencia", "e164": DEST_GERENCIA}]))
TWO_REV = mut(BASE, ("profile.content.transferDestinations", [{"alias": "Gerencia", "e164": DEST_GERENCIA}, {"alias": "Recepción", "e164": DEST_RECEPCION}]))
REL("la misma entrada dos veces → la misma huella (determinista)", copy.deepcopy(BASE), True, basis="contrato", decision=None)
REL("las claves del perfil en otro orden → la misma huella", _reordered, True)
REL("otro uuid de perfil y de versión (mismo contenido) → otra huella (E1-9: los uuids entran)",
    mut(BASE, ("profile.uuid", "9b000000-0000-4000-8000-000000000099"), ("version.uuid", "9a000000-0000-4000-8000-000000000099")), False,
    basis="contrato", decision=None, ref="D8-6, E1-9")
REL("otras identidades de autor y aprobador → la misma huella", mut(BASE, ("profile.createdBy", 11), ("profile.approvedBy", 12)), True)
REL("herramientas en otro orden → la misma huella (conjunto)", mut(BASE, ("profile.content.tools", list(reversed(CATALOG_TOOLS)))), True)
REL("destinos en otro orden → la misma huella (ordenados por alias)", TWO_REV, True, a=TWO)
REL("otro tope del entorno con el mismo perfil (300 ≤ 600) → la misma huella", mut(BASE, ("env.VOICE_PER_CALL_MAX_SECONDS", "600")), True)
REL("un carácter del saludo de entrada → otra huella", mut(BASE, ("profile.content.greetingInbound", "Gracias por llamar a Iron Body Workout!")), False,
    basis="contrato", decision=None)
REL("otra voz → otra huella", mut(BASE, ("profile.content.voiceId", "h8fxVoice000000000002")), False, basis="contrato", decision=None)
REL("otra duración (240) → otra huella", mut(BASE, ("profile.content.maxDurationSeconds", 240)), False, basis="contrato", decision=None)
REL("una herramienta menos (consultar_planes; no_volver_a_llamar no se quita) → otra huella",
    mut(BASE, ("profile.content.tools", ["consultar_horarios", "solicitar_humano", "no_volver_a_llamar"])), False, basis="contrato", decision=None,
    ref="D8-6, E1-8")
REL("otro número de destino con el mismo alias → otra huella", mut(BASE, ("profile.content.transferDestinations",
                                                                          [{"alias": "Recepción", "e164": DEST_GERENCIA}])), False, basis="contrato", decision=None)
REL("otra retención → otra huella", mut(BASE, ("profile.content.privacy.retentionDays", 15)), False, basis="contrato", decision=None)
REL("otra versión del Studio (otro contenido) → otra huella", mut(BASE, ("version.contentHash", sha256_hex("v13")),
                                                                  ("version.recomputedContentHash", sha256_hex("v13"))), False, basis="contrato", decision=None)
REL("otra versión del compilador → otra huella", mut(BASE, ("compilerVersion", "ultron-voice-compiler/2")), False, basis="contrato", decision=None)


# ── canonicalHash: vectores exactos (Python = PHP salvo las rarezas de DF-C10, escritas a mano) ──
def CH(desc, value, canon=None, *, throws=None, basis="contrato", decision=None):
    if throws:
        exp = {"throws": throws}
    else:
        if canon is None:
            canon = canonical(value)
        assert canonical(value) == canon, (desc, canonical(value), canon)
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

# ── La cabecera del método de exportación ───────────────────────────────────
if EXPORT is not None:
    CO.header["realCompilerExport"] = {
        "what": "expected.realCompiler: lo que da HOY el compilador real con la entrada traducida (DF-C12, E1-9). Instantánea de regresión, no derivación independiente.",
        "command": "php tests/fixtures/voice/tools/compiler-export.php > tests/fixtures/voice/tools/build/compiler-export.json && python3 -I tests/fixtures/voice/tools/build/build.py",
        "translation": "la de tests/Unit/UltronStudio/StudioVoiceCompilerParityTest.php (S8-a), copiada en tools/compiler-export.php (cabecera del fichero)",
        "compiler": EXPORT["compiler"],
        "versionVariants": EXPORT["variants"],
        "check": "voice-fixtures-check.py --php vuelve a ejecutar el exportador y compara cada huella y cada motivo con el fixture",
    }
    print(f"compiler-cases: instantánea del compilador real incrustada ({len(EXPORT['compile'])} casos de compilación, {len(EXPORT['hashRelations'])} relaciones)"
          + (f"; SIN instantánea para {len(STALE)} casos (vuelve a exportar)" if STALE else ""))
else:
    print("AVISO compiler-cases: sin tools/build/compiler-export.json: el fixture sale sin expected.realCompiler (ejecuta compiler-export.php)")

# coherence · StudioCanaryCoherence::read() e isEngineCanary por fila (contrato §I4.5)
# engine.canaryConversationId = config('marketing.ultron.canary_conversation_id');
# engine.conversation = la fila de marketing_conversations ({leadId}) o null si no existe;
# engine.lead = la fila de marketing_leads ({phone}) o null si no existe.
# rows = filas del registro; su phone_hash se calcula con el `appKey` de la fila.
W, V = "whatsapp", "voice"
P1, P2, P3 = "+573000000001", "+573000000002", "+573000000003"
FIJO = "+576010000001"


def ENG(phone, cid=501, conversation=True, lead=True, lead_id=9001):
    return {"canaryConversationId": cid,
            "conversation": ({"leadId": lead_id} if conversation else None),
            "lead": ({"phone": phone} if lead else None)}


NO_ENGINE = {"canaryConversationId": None, "conversation": None, "lead": None}


def R(ref, ch, status, phone, key=None):
    return {"ref": ref, "channel": ch, "status": status, "phone": phone, "appKey": key or B64KEY}


def X(verdict, configured, active, normalized, rows):
    return {"verdict": verdict, "engineCanaryConfigured": configured, "activeWhatsapp": active,
            "normalizedE164": normalized, "engineHash": phone_fp(normalized, B64KEY) if normalized else None,
            "rows": [{"ref": r, "isEngineCanary": v} for r, v in rows]}


def H(desc, engine, rows, exp, failure=None):
    add("coherence", "read", desc, {"appKey": B64KEY, "engine": engine, "failure": failure, "rows": rows}, exp)


UN = "engine_canary_phone_unusable"
NR = "engine_canary_not_registered"
RI = "engine_canary_registered_inactive"
XA = "registry_has_extra_active"
IS = "in_sync"

# 1-3 · sin canario y lectura caída (precedencia: no_engine_canary → unreadable)
H("sin canario del motor configurado", NO_ENGINE, [R("A", W, "active", P1), R("B", V, "active", FIJO)],
  X("no_engine_canary", False, 1, None, [("A", None), ("B", None)]))
H("sin canario del motor y la base caída: no se llega a leer", NO_ENGINE, [],
  X("no_engine_canary", False, None, None, []), failure="read_failed")
H("la lectura falla (cualquier Throwable)", ENG("3000000001"), [R("A", W, "active", P1)],
  X("unreadable", True, None, None, [("A", None)]), failure="read_failed")

# 4-12 · teléfono inservible
ROWS_A = [R("A", W, "active", P1)]
H("la conversación canaria no existe", ENG(None, conversation=False, lead=False), ROWS_A, X(UN, True, 1, None, [("A", False)]))
H("la conversación no tiene lead (lead_id nulo)", ENG(None, lead=False, lead_id=None), ROWS_A, X(UN, True, 1, None, [("A", False)]))
H("el lead no existe", ENG(None, lead=False), ROWS_A, X(UN, True, 1, None, [("A", False)]))
H("el lead sin teléfono (nulo)", ENG(None), ROWS_A, X(UN, True, 1, None, [("A", False)]))
H("teléfono vacío", ENG(""), ROWS_A, X(UN, True, 1, None, [("A", False)]))
H("teléfono sin dígitos", ENG("sin teléfono"), ROWS_A, X(UN, True, 1, None, [("A", False)]))
H("7 dígitos: no casa con E.164", ENG("3000001"), ROWS_A, X(UN, True, 1, None, [("A", False)]))
H("un 0 delante (0 + 57 + móvil): +0… no es E.164", ENG("0573000000001"), ROWS_A, X(UN, True, 1, None, [("A", False)]))
H("16 dígitos", ENG("5730000000011234"), ROWS_A, X(UN, True, 1, None, [("A", False)]))

# 13-18 · normalización (solo dígitos; 10 que empiezan por 3 → 57 delante; '+') y en sincronía
H("10 dígitos que empiezan por 3: se antepone 57", ENG("3000000001"), ROWS_A, X(IS, True, 1, P1, [("A", True)]))
H("con +57 y espacios", ENG("+57 300 000 0001"), ROWS_A, X(IS, True, 1, P1, [("A", True)]))
H("12 dígitos sin + (57…)", ENG("573000000001"), ROWS_A, X(IS, True, 1, P1, [("A", True)]))
H("con paréntesis y guion (10 dígitos)", ENG("(300) 000-0001"), ROWS_A, X(IS, True, 1, P1, [("A", True)]))
H("con el prefijo whatsapp:", ENG("whatsapp:+573000000001"), ROWS_A, X(IS, True, 1, P1, [("A", True)]))
H("un número extranjero: la coherencia no mira la lista de países", ENG("+1 202 555 0101"),
  [R("A", W, "active", "+12025550101")], X(IS, True, 1, "+12025550101", [("A", True)]))

# 19-21 · normalizado pero sin fila
H("9 dígitos que empiezan por 3: no se antepone 57 y queda otro E.164", ENG("300000001"), ROWS_A,
  X(NR, True, 1, "+300000001", [("A", False)]))
H("un fijo de 10 dígitos (601…) no lleva 57 delante", ENG("6010000001"), [R("A", W, "active", P1), R("B", V, "active", FIJO)],
  X(NR, True, 1, "+6010000001", [("A", False), ("B", None)]))
H("registro vacío", ENG("3000000001"), [], X(NR, True, 0, P1, []))

# 22-34 · estados de la fila del motor y las demás
H("la fila del motor pendiente", ENG("3000000001"), [R("A", W, "pending", P1)], X(RI, True, 0, P1, [("A", True)]))
H("la fila del motor autorizada", ENG("3000000001"), [R("A", W, "authorized", P1)], X(RI, True, 0, P1, [("A", True)]))
H("solo una fila revocada con ese teléfono", ENG("3000000001"), [R("A", W, "revoked", P1)], X(NR, True, 0, P1, [("A", False)]))
H("registrado solo en voz: no cuenta", ENG("3000000001"), [R("A", V, "active", P1)], X(NR, True, 0, P1, [("A", None)]))
H("activo y otro WhatsApp activo", ENG("3000000001"), [R("A", W, "active", P1), R("B", W, "active", P2)],
  X(XA, True, 2, P1, [("A", True), ("B", False)]))
H("activo y un activo de voz: la voz no cuenta como extra", ENG("3000000001"), [R("A", W, "active", P1), R("C", V, "active", FIJO)],
  X(IS, True, 1, P1, [("A", True), ("C", None)]))
H("pendiente y otro WhatsApp activo: manda el estado de la fila del motor", ENG("3000000001"),
  [R("A", W, "pending", P1), R("B", W, "active", P2)], X(RI, True, 1, P1, [("A", True), ("B", False)]))
H("activo y otros WhatsApp pendiente y autorizado: solo cuentan los activos", ENG("3000000001"),
  [R("A", W, "active", P1), R("B", W, "pending", P2), R("D", W, "authorized", P3)],
  X(IS, True, 1, P1, [("A", True), ("B", False), ("D", False)]))
H("activo y otro WhatsApp revocado", ENG("3000000001"), [R("A", W, "active", P1), R("E", W, "revoked", P3)],
  X(IS, True, 1, P1, [("A", True), ("E", False)]))
H("revocado y vuelto a registrar: la fila viva es la del motor", ENG("3000000001"),
  [R("A", W, "revoked", P1), R("A2", W, "pending", P1)], X(RI, True, 0, P1, [("A", False), ("A2", True)]))
H("la fila se registró con otra APP_KEY (rotada): ya no casa", ENG("3000000001"), [R("A", W, "active", P1, RAWKEY)],
  X(NR, True, 1, P1, [("A", False)]))
H("teléfono inservible con filas de los dos canales", ENG(None), [R("A", W, "active", P1), R("C", V, "active", FIJO)],
  X(UN, True, 1, None, [("A", False), ("C", None)]))
H("solo otro WhatsApp activo: el del motor no está", ENG("3000000001"), [R("B", W, "active", P2)],
  X(NR, True, 1, P1, [("B", False)]))

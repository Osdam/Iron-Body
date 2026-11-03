# state-transitions.json · voice_calls.status y voice_call_events (D8-13; disparador de DB8 «solo hacia delante», un final no cambia)
STATUSES = ["requested", "canceled", "failed_to_start", "ambiguous", "dialing", "active", "completed", "busy", "no_answer", "failed", "hung_up"]
FINALS = ["canceled", "failed_to_start", "completed", "busy", "no_answer", "failed", "hung_up"]
EVENTS = ["outbound_requested", "outbound_started", "outbound_rejected", "outbound_ambiguous", "initiation_received",
          "post_call_transcription", "post_call_audio_discarded", "call_initiation_failure",
          "tool_call", "guard_verdict", "hangup_requested",
          "reconciled", "seal_mismatch", "cost_recorded", "unknown_provider_call"]
assert len(STATUSES) == 11 and len(EVENTS) == 15
SEAL_COLUMNS = ["voice_publication_seq", "part", "stamp_state", "studio_version_uuid", "studio_content_hash", "voice_profile_hash",
                "spec_hash", "payload_hash", "compiler_version", "provider", "provider_agent_id"]
IDENTITY_COLUMNS = ["direction", "remote_hash", "remote_last4", "client_request_id", "attempt", "parent_call_id"]
AFTER_COLUMNS = ["observed_agent_id", "observed_version_id", "observed_branch_id", "seal_verified"]

# (de, a, dirección, base, decisión, disparador)
ALLOWED = [
    ("requested", "canceled", "outbound", "contrato", None, "antes de marcar: política, publicación movida (publication_moved), freno o corte"),
    ("requested", "failed_to_start", "outbound", "contrato", None, "ElevenLabs respondió 4xx a outbound-call; la reserva se libera"),
    ("requested", "ambiguous", "outbound", "contrato", None, "tiempo agotado, 5xx o sin conexión al iniciarla (nunca se reintenta)"),
    ("requested", "dialing", "outbound", "contrato", None, "ElevenLabs devolvió conversation_id y callSid"),
    ("requested", "busy", "outbound", "decision-fixture", "DF-S2", "call_initiation_failure busy antes de que el job guarde dialing (se casa por call_ref)"),
    ("requested", "no_answer", "outbound", "decision-fixture", "DF-S2", "ídem con no-answer"),
    ("requested", "failed", "outbound", "decision-fixture", "DF-S2", "ídem con unknown"),
    ("requested", "completed", "outbound", "decision-fixture", "DF-S2", "post_call_transcription antes de que el job guarde dialing"),
    ("ambiguous", "dialing", "outbound", "decision-fixture", "DF-S3", "la conciliación la encuentra en curso"),
    ("ambiguous", "completed", "outbound", "decision-fixture", "DF-S3", "la conciliación o la post-llamada la resuelven"),
    ("ambiguous", "busy", "outbound", "decision-fixture", "DF-S3", "ídem"),
    ("ambiguous", "no_answer", "outbound", "decision-fixture", "DF-S3", "ídem"),
    ("ambiguous", "failed", "outbound", "decision-fixture", "DF-S3", "la conciliación prueba que no hubo llamada, o Twilio canceled/failed"),
    ("dialing", "completed", "outbound", "contrato", None, "post_call_transcription con duración"),
    ("dialing", "busy", "outbound", "contrato", None, "call_initiation_failure = busy"),
    ("dialing", "no_answer", "outbound", "contrato", None, "call_initiation_failure = no-answer"),
    ("dialing", "failed", "outbound", "contrato", None, "call_initiation_failure = unknown u otro fallo final"),
    ("dialing", "hung_up", "outbound", "contrato", None, "corte de emergencia (D8-20, nivel 0b)"),
    ("active", "completed", "inbound", "contrato", None, "post_call_transcription con duración"),
    ("active", "hung_up", "inbound", "contrato", None, "corte de emergencia"),
    ("active", "failed", "inbound", "decision-fixture", "DF-S4", "otro fallo final (la conciliación la ve failed)"),
]
INITIAL = {"outbound": "requested", "inbound": "active"}
REACHABLE = {"outbound": {"requested", "canceled", "failed_to_start", "ambiguous", "dialing", "completed", "busy", "no_answer", "failed", "hung_up"},
             "inbound": {"active", "completed", "hung_up", "failed"}}

ST = Fixture("state-transitions.json", header(
    "state-transitions.json",
    ["D8-13 (voice_calls.status, 11 estados; transiciones solo hacia delante con un disparador de PostgreSQL; un final no cambia; voice_call_events con UNIQUE (provider, kind, provider_event_key))",
     "D8-8 (el sello se escribe al empezar)", "D8-9 (publication_moved)", "D8-12 (job tries = 1; ambiguous no se reintenta)", "D8-20 (corte de las dialing y active)",
     "Contratos de datos (voice_calls, voice_call_events)"],
    [{"what": "El contrato", "url": "(repositorio)", "consulted": "03-11-2026", "how": "Las transiciones que el contrato no escribe llevan su DF-S."}],
    closedSets={"statuses": STATUSES, "finalStatuses": FINALS, "nonFinalStatuses": [s for s in STATUSES if s not in FINALS],
                "events": EVENTS, "eventsProposed": ["usage_alert (DF-W1, Q-5)"], "directions": ["outbound", "inbound"],
                "initialStatus": INITIAL, "reachableByDirection": {k: sorted(v, key=STATUSES.index) for k, v in REACHABLE.items()},
                "sealColumns": SEAL_COLUMNS, "identityColumns": IDENTITY_COLUMNS, "afterColumns": AFTER_COLUMNS},
    decisions={
        "DF-S1": "Una actualización que no cambia status no es una transición: vale en cualquier estado, también en los finales (coste real, sello posterior), salvo las columnas inmutables (DF-S5).",
        "DF-S2": "requested → busy | no_answer | failed | completed: el webhook puede llegar antes de que el job guarde dialing; entonces el job ya no puede escribir dialing (final).",
        "DF-S3": "ambiguous se resuelve hacia delante: dialing si sigue en curso, o un final de llamada (completed, busy, no_answer, failed). Nunca canceled ni failed_to_start (ya se intentó).",
        "DF-S4": "active → failed para el «otro fallo final» de una entrante.",
        "DF-S5": "El sello y la identidad se escriben al insertar y no cambian nunca (sealColumns e identityColumns).",
        "DF-S6": "Las columnas posteriores (afterColumns) pasan de null a un valor una sola vez; cambiarlas después se rechaza.",
        "DF-S7": "Los eventos sin clave del proveedor (p. ej. hangup_requested de una persona) llevan provider_event_key NULL y no se deduplican (UNIQUE con NULL admite varias filas en PG y SQLite). El provider «laravel» del caso es de ejemplo: el conjunto de provider lo fija DB8.",
        "DF-S8": "Al insertar, status = el inicial de su dirección (outbound → requested, inbound → active); otro valor se rechaza.",
        "DF-S9": "Lista cerrada: toda transición que no está en allowed se rechaza, también las que el contrato no menciona (p. ej. dialing → canceled o requested → hung_up: el corte de una pedida es canceled).",
    },
))
ALLOWED_SET = {(a, b, d) for a, b, d, *_ in ALLOWED}
for a, b, d, *_ in ALLOWED:
    assert a not in FINALS, a
    assert a in REACHABLE[d] and b in REACHABLE[d], (a, b, d)
    assert STATUSES.index(a) != STATUSES.index(b)

for a, b, d, basis, dec, why in ALLOWED:
    ST.add("allowed", d, f"{a} → {b}", "D8-13", basis, {"from": a, "to": b, "direction": d, "trigger": why}, {"allowed": True}, decision=dec)

# Matriz completa: 11 × 11 por dirección (la diagonal es «sin cambio», DF-S1)
for d in ("outbound", "inbound"):
    for a in STATUSES:
        for b in STATUSES:
            if a == b:
                exp = {"allowed": True, "noChange": True}
                basis, dec = "decision-fixture", "DF-S1"
            else:
                ok = (a, b, d) in ALLOWED_SET
                exp = {"allowed": ok}
                if not ok:
                    exp["why"] = ("estado final" if a in FINALS else
                                  "estado imposible en esta dirección" if (a not in REACHABLE[d] or b not in REACHABLE[d]) else
                                  "no es una transición hacia delante prevista")
                row = next((x for x in ALLOWED if (x[0], x[1], x[2]) == (a, b, d)), None)
                basis, dec = (row[3], row[4]) if row else ("contrato" if a in FINALS else "decision-fixture", None if a in FINALS else "DF-S9")
            ST.add("matrix", d, f"{d}: {a} → {b}", "D8-13", basis, {"from": a, "to": b, "direction": d}, exp, decision=dec)

# Inserción
for d in ("outbound", "inbound"):
    for s in STATUSES:
        ok = s == INITIAL[d]
        ST.add("insert", d, f"insertar una {d} en {s}", "D8-13", "decision-fixture", {"direction": d, "status": s}, {"allowed": ok},
               decision="DF-S8")


# Secuencias de extremo a extremo: cada paso se acepta o se rechaza
def SEQ(desc, d, steps, final, *, basis="contrato", decision=None, ref="D8-13"):
    cur, out = None, []
    for i, to in enumerate(steps):
        if i == 0:
            ok = to == INITIAL[d]
        else:
            ok = (cur, to, d) in ALLOWED_SET
        out.append({"to": to, "accepted": ok})
        if ok:
            cur = to
    assert cur == final, (desc, cur, final)
    ST.add("sequences", d, desc, ref, basis, {"direction": d, "steps": steps}, {"steps": out, "finalStatus": final}, decision=decision)


SEQ("saliente contestada: requested → dialing → completed", "outbound", ["requested", "dialing", "completed"], "completed")
SEQ("comunicando: requested → dialing → busy", "outbound", ["requested", "dialing", "busy"], "busy")
SEQ("ambigua resuelta por la conciliación: requested → ambiguous → completed", "outbound", ["requested", "ambiguous", "completed"], "completed",
    basis="decision-fixture", decision="DF-S3")
SEQ("ambigua que seguía en curso: requested → ambiguous → dialing → completed", "outbound", ["requested", "ambiguous", "dialing", "completed"],
    "completed", basis="decision-fixture", decision="DF-S3")
SEQ("publicación movida: requested → canceled y el job ya no marca (dialing rechazado)", "outbound", ["requested", "canceled", "dialing"], "canceled",
    ref="D8-9, D8-13")
SEQ("corte y después la post-llamada: dialing → hung_up y completed rechazado (el evento sí se registra)", "outbound",
    ["requested", "dialing", "hung_up", "completed"], "hung_up", ref="D8-13, D8-20")
SEQ("el fallo de inicio llega antes que el job: requested → busy y dialing rechazado", "outbound", ["requested", "busy", "dialing"], "busy",
    basis="decision-fixture", decision="DF-S2")
SEQ("hacia atrás: dialing → requested rechazado", "outbound", ["requested", "dialing", "requested"], "dialing")
SEQ("ambigua nunca vuelve a requested ni se reintenta en la misma fila", "outbound", ["requested", "ambiguous", "requested"], "ambiguous", ref="D8-12, D8-13")
SEQ("un final no cambia: completed → failed rechazado", "outbound", ["requested", "dialing", "completed", "failed"], "completed")
SEQ("entrante: active → completed", "inbound", ["active", "completed"], "completed")
SEQ("entrante cortada: active → hung_up", "inbound", ["active", "hung_up"], "hung_up")
SEQ("una entrante no pasa por dialing", "inbound", ["active", "dialing", "completed"], "completed")
SEQ("una saliente no puede quedar active", "outbound", ["requested", "dialing", "active"], "dialing")


# Columnas inmutables (DF-S5, DF-S6)
for col in SEAL_COLUMNS + IDENTITY_COLUMNS:
    ST.add("columns", "immutable", f"cambiar {col} después de insertar se rechaza", "D8-8, D8-13", "decision-fixture",
           {"column": col, "from": "valor", "to": "otro valor"}, {"allowed": False}, decision="DF-S5")
for col in AFTER_COLUMNS:
    ST.add("columns", "writeOnce", f"{col}: de null a un valor vale", "D8-8", "decision-fixture", {"column": col, "from": None, "to": "valor"},
           {"allowed": True}, decision="DF-S6")
    ST.add("columns", "writeOnce", f"{col}: de un valor a otro se rechaza", "D8-8", "decision-fixture", {"column": col, "from": "valor", "to": "otro"},
           {"allowed": False}, decision="DF-S6")


# Deduplicación de voice_call_events
def EVD(desc, first, second, both, *, basis="contrato", decision=None):
    ST.add("eventDedupe", "unique", desc, "D8-13", basis, {"first": first, "second": second}, {"secondInserted": both}, decision=decision)


E1 = {"provider": "elevenlabs", "kind": "post_call_transcription", "providerEventKey": "conv_h8fx_0001"}
EVD("la misma terna dos veces → la segunda no entra (duplicado: 200 sin evento nuevo)", E1, E1, False)
EVD("misma conversación y otro tipo → entra", E1, dict(E1, kind="post_call_audio_discarded"), True)
EVD("misma clave y otro proveedor → entra", E1, dict(E1, provider="twilio"), True)
EVD("la clave distingue mayúsculas (bytes exactos)", E1, dict(E1, providerEventKey="CONV_H8FX_0001"), True)
EVD("dos hangup_requested sin clave del proveedor → entran los dos", {"provider": "laravel", "kind": "hangup_requested", "providerEventKey": None},
    {"provider": "laravel", "kind": "hangup_requested", "providerEventKey": None}, True, basis="decision-fixture", decision="DF-S7")

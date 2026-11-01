# canaryTransitions · máquina de estados de los números canarios (contrato §I4.4, §I4.9, §I7.3, §I8)
# Cada caso: los hechos que mira la ruta (ya calculados) → la PRIMERA comprobación que rechaza, o el éxito.
# `kind` = register (49) | authorize (50) | activate (51) | deactivate (52) | revoke (53) | expectation (54)
#        | actions (el bloque `actions` de CanaryNumberDto, calculado para quien mira).
W, V = "whatsapp", "voice"
VA = "11111111-1111-4111-8111-111111111111"
VB = "22222222-2222-4222-8222-222222222222"
VC = "33333333-3333-4333-8333-333333333333"

SA_P = ["ultron.view", "ultron.campaigns_manage", "ultron.canary_manage", "ultron.canary_authorize", "ultron.integrations_manage"]
AD_P = ["ultron.view", "ultron.campaigns_manage", "ultron.canary_manage"]


def actor(aid, perms, role, name=None):
    return {"person": True, "adminId": aid, "name": name or f"Persona {aid}", "role": role, "permissions": perms}


TOKEN = {"person": False, "adminId": None, "name": None, "role": None, "permissions": []}
SA7 = actor(7, SA_P, "Super Admin")
SA8 = actor(8, SA_P, "Super Admin")
AD9 = actor(9, AD_P, "Administrador")
AD10 = actor(10, AD_P, "Administrador")
VIEW11 = actor(11, ["ultron.view"], "Rol a medida")
AUTH12 = actor(12, ["ultron.view", "ultron.canary_authorize"], "Rol a medida")
NOVIEW13 = actor(13, ["ultron.canary_manage", "ultron.canary_authorize"], "Rol a medida")

LIVE = ["pending", "authorized", "active"]


def num(status, reg=9, channel=W, reg_name=None, reg_role="Administrador"):
    return {"exists": True, "channel": channel, "status": status, "registeredByAdminId": reg,
            "registeredByName": reg_name or f"Persona {reg}", "registeredByRole": reg_role}


NONE = {"exists": False}


def ver(status, integrity=True):
    return {"exists": True, "status": status, "integrityOk": integrity}


NOVER = {"exists": False}


def err(status, code, payload=None, audit="absent"):
    d = {"status": status, "code": code}
    if payload is not None:
        d["payload"] = payload
    if audit != "absent":
        d["audit"] = audit
    return d


def done(status, to, event, frm, diff=None):
    a = {"event": event, "from": frm, "to": to}
    if diff is not None:
        a["diff"] = diff
    return {"status": status, "code": None, "toStatus": to, "audit": a}


WD = err(409, "integrations_writes_disabled", audit=None)   # los rechazos por bandera no se auditan (§I8)
PR = err(403, "person_required")
NF = err(404, "canary_not_found")
REPLAYED = {"status": 200, "code": "replayed"}
REUSED = err(409, "client_request_reused")


def forb(p):
    return err(403, "forbidden", {"required_permission": p})


def it(cur, allowed):
    return err(409, "invalid_transition", {"currentStatus": cur, "allowedFrom": allowed})


def fe(action):
    return err(403, "four_eyes_required", {"action": action},
               audit={"event": "canary_number.four_eyes_denied", "from": None, "to": None, "diff": {"action": action}})


def nl(status):
    return err(409, "version_not_linkable", {"currentStatus": status})


VNF = err(404, "version_not_found")
INT = err(409, "integrity_mismatch")


# ── 49 · registrar ──────────────────────────────────────────────────────────
def REG(desc, a, exp, *, writes=True, cr="new", ch=W, ev=None, live=0, max_live=10, same=None):
    facts = {"actor": a, "writesEnabled": writes, "clientRequest": cr, "channel": ch,
             "expectedVersionUuid": ev[0] if ev else None, "expectedVersion": ev[1] if ev else None,
             "registry": {"liveInChannel": live, "maxLive": max_live, "sameNumber": same or []}}
    add("canaryTransitions", "register", desc, {"facts": facts}, exp)


def reg_ok(ch=W, ev=None):
    return done(201, "pending", "canary_number.registered", None, {"channel": ch, "expectedVersionUuid": ev})


REG("el Administrador registra un WhatsApp sin versión esperada", AD9, reg_ok())
REG("el Super Admin registra un número de voz", SA7, reg_ok(V), ch=V)
REG("con versión esperada CANARY íntegra", AD9, reg_ok(W, VB), ev=(VB, ver("CANARY")))
REG("versión esperada APPROVED", AD9, reg_ok(W, VA), ev=(VA, ver("APPROVED")))
REG("versión esperada PUBLISHED", AD9, reg_ok(W, VC), ev=(VC, ver("PUBLISHED")))
REG("versión esperada que no existe", AD9, VNF, ev=(VB, NOVER))
REG("versión esperada REJECTED", AD9, nl("REJECTED"), ev=(VB, ver("REJECTED")))
REG("versión esperada ROLLED_BACK", AD9, nl("ROLLED_BACK"), ev=(VB, ver("ROLLED_BACK")))
REG("versión esperada DRAFT", AD9, nl("DRAFT"), ev=(VB, ver("DRAFT")))
REG("versión esperada CANARY no íntegra", AD9, INT, ev=(VB, ver("CANARY", False)))
REG("versión esperada RETIRED y no íntegra: el estado antes que la integridad", AD9, nl("RETIRED"), ev=(VB, ver("RETIRED", False)))
REG("9 vivos de 10 en el canal", AD9, reg_ok(), live=9)
REG("10 vivos de 10 en el canal", AD9, err(409, "canary_live_limit", {"max": 10, "channel": W}), live=10)
REG("10 vivos de 10 en voz", AD9, err(409, "canary_live_limit", {"max": 10, "channel": V}), ch=V, live=10)
REG("el mismo número ya vivo (pendiente) en el canal", AD9, err(409, "canary_number_exists"), live=1, same=[{"channel": W, "status": "pending"}])
REG("el mismo número activo en el canal", AD9, err(409, "canary_number_exists"), live=1, same=[{"channel": W, "status": "active"}])
REG("el mismo número activo en voz: en WhatsApp es otra fila", AD9, reg_ok(), live=0, same=[{"channel": V, "status": "active"}])
REG("el mismo número revocado en el canal no lo impide", AD9, reg_ok(), live=0, same=[{"channel": W, "status": "revoked"}])
REG("el mismo número vivo y el canal en el tope: el tope antes que el INSERT", AD9, err(409, "canary_live_limit", {"max": 10, "channel": W}),
    live=10, same=[{"channel": W, "status": "pending"}])
REG("reintento idéntico", AD9, REPLAYED, cr="replay")
REG("reintento idéntico con la versión esperada ya no asociable", AD9, REPLAYED, cr="replay", ev=(VB, ver("RETIRED")))
REG("reintento idéntico con el canal en el tope", AD9, REPLAYED, cr="replay", live=10)
REG("misma clientRequestId con otro cuerpo", AD9, REUSED, cr="reusedOtherBody")
REG("misma clientRequestId de otra persona", AD9, REUSED, cr="reusedOtherActor")
REG("bandera de escrituras apagada", AD9, WD, writes=False)
REG("bandera apagada y reintento idéntico: la bandera antes que el reintento", AD9, WD, writes=False, cr="replay")
REG("bandera apagada y la misma clientRequestId con otro cuerpo", AD9, WD, writes=False, cr="reusedOtherBody")
REG("solo ultron.view", VIEW11, forb("ultron.canary_manage"))
REG("solo ultron.view y la bandera apagada: el permiso antes que la bandera", VIEW11, forb("ultron.canary_manage"), writes=False)
REG("canary_manage sin ultron.view", NOVIEW13, forb("ultron.view"))
REG("token compartido", TOKEN, PR)
REG("versión esperada inexistente y el canal en el tope: la versión antes que el tope", AD9, VNF, ev=(VB, NOVER), live=10)


# ── 50 · autorizar ──────────────────────────────────────────────────────────
def AUTHZ(desc, a, n, exp, *, writes=True):
    add("canaryTransitions", "authorize", desc, {"facts": {"actor": a, "writesEnabled": writes, "number": n}}, exp)


AUTH_OK = done(200, "authorized", "canary_number.authorized", "pending")
AUTHZ("otra persona con canary_authorize autoriza", SA7, num("pending", 9), AUTH_OK)
AUTHZ("quien registró no autoriza, aunque sea Super Admin", SA7, num("pending", 7, reg_role="Super Admin"), fe("authorize"))
AUTHZ("el Administrador no tiene canary_authorize", AD10, num("pending", 9), forb("ultron.canary_authorize"))
AUTHZ("el Administrador con la bandera apagada: el permiso antes que la bandera", AD10, num("pending", 9), forb("ultron.canary_authorize"), writes=False)
AUTHZ("bandera de escrituras apagada", SA7, num("pending", 9), WD, writes=False)
AUTHZ("número que no existe", SA7, NONE, NF)
AUTHZ("bandera apagada y número que no existe: la bandera antes que el recurso", SA7, NONE, WD, writes=False)
AUTHZ("sin permiso y número que no existe: el permiso antes que el recurso", AD10, NONE, forb("ultron.canary_authorize"))
AUTHZ("quien registró, con el número ya autorizado: la identidad antes que el estado", SA7, num("authorized", 7, reg_role="Super Admin"), fe("authorize"))
AUTHZ("quien registró, con el número revocado", SA7, num("revoked", 7, reg_role="Super Admin"), fe("authorize"))
AUTHZ("otra persona, número ya autorizado", SA7, num("authorized", 9), it("authorized", ["pending"]))
AUTHZ("otra persona, número activo", SA7, num("active", 9), it("active", ["pending"]))
AUTHZ("otra persona, número revocado", SA7, num("revoked", 9), it("revoked", ["pending"]))
AUTHZ("mismo nombre y otro id: la identidad es el id", actor(8, SA_P, "Super Admin", name="Ana Prueba"),
      num("pending", 7, reg_name="Ana Prueba", reg_role="Super Admin"), AUTH_OK)
AUTHZ("el mismo id con otro rol en la instantánea: sigue siendo quien registró", actor(9, SA_P, "Super Admin"),
      num("pending", 9, reg_role="Administrador"), fe("authorize"))
AUTHZ("un rol a medida con ultron.view y canary_authorize, sin canary_manage", AUTH12, num("pending", 9), AUTH_OK)
AUTHZ("canary_authorize sin ultron.view", NOVIEW13, num("pending", 9), forb("ultron.view"))
AUTHZ("token compartido", TOKEN, num("pending", 9), PR)
AUTHZ("un número de voz pendiente", SA7, num("pending", 9, V), AUTH_OK)


# ── 51 · activar ────────────────────────────────────────────────────────────
def ACT(desc, a, n, exp, *, writes=True, active=2, max_active=3):
    facts = {"actor": a, "writesEnabled": writes, "number": n, "activeInChannel": active, "maxActive": max_active}
    add("canaryTransitions", "activate", desc, {"facts": facts}, exp)


ACT_OK = done(200, "active", "canary_number.activated", "authorized")
ACT("quien autorizó (distinto de quien registró) activa con 2 de 3", SA7, num("authorized", 9), ACT_OK)
ACT("otro Administrador activa: basta canary_manage", AD10, num("authorized", 9), ACT_OK)
ACT("quien registró (Administrador) no activa", AD9, num("authorized", 9), fe("activate"))
ACT("quien registró (Super Admin) no activa, aunque tenga todas las llaves", SA7, num("authorized", 7, reg_role="Super Admin"), fe("activate"))
ACT("3 de 3 activos en el canal", SA7, num("authorized", 9), err(409, "canary_active_limit", {"max": 3, "channel": W}), active=3)
ACT("tope 1 con 1 activo", SA7, num("authorized", 9), err(409, "canary_active_limit", {"max": 1, "channel": W}), active=1, max_active=1)
ACT("tope 10 con 9 activos", SA7, num("authorized", 9), ACT_OK, active=9, max_active=10)
ACT("voz: el tope es por canal (0 activos de voz)", SA7, num("authorized", 9, V), ACT_OK, active=0)
ACT("voz: 3 de 3", SA7, num("authorized", 9, V), err(409, "canary_active_limit", {"max": 3, "channel": V}), active=3)
ACT("quien registró y en el tope: la identidad antes que el tope", AD9, num("authorized", 9), fe("activate"), active=3)
ACT("pendiente", SA7, num("pending", 9), it("pending", ["authorized"]))
ACT("pendiente y en el tope: el estado antes que el tope", SA7, num("pending", 9), it("pending", ["authorized"]), active=3)
ACT("ya activo", SA7, num("active", 9), it("active", ["authorized"]))
ACT("revocado", SA7, num("revoked", 9), it("revoked", ["authorized"]))
ACT("quien registró, con el número pendiente: la identidad antes que el estado", AD9, num("pending", 9), fe("activate"))
ACT("sin canary_manage (rol a medida con canary_authorize)", AUTH12, num("authorized", 9), forb("ultron.canary_manage"))
ACT("bandera de escrituras apagada", SA7, num("authorized", 9), WD, writes=False)
ACT("número que no existe", SA7, NONE, NF)
ACT("bandera apagada y número que no existe", SA7, NONE, WD, writes=False)


# ── 52 · desactivar ─────────────────────────────────────────────────────────
def DEACT(desc, a, n, exp, *, writes=True):
    add("canaryTransitions", "deactivate", desc,
        {"facts": {"actor": a, "writesEnabled": writes, "number": n, "reason": "Prueba terminada"}}, exp)


DEACT_OK = done(200, "authorized", "canary_number.deactivated", "active", {"reason": "Prueba terminada"})
DEACT("activo → autorizado", SA7, num("active", 9), DEACT_OK)
DEACT("quien registró también desactiva (cualquiera)", AD9, num("active", 9), DEACT_OK)
DEACT("autorizado", SA7, num("authorized", 9), it("authorized", ["active"]))
DEACT("pendiente", SA7, num("pending", 9), it("pending", ["active"]))
DEACT("revocado", SA7, num("revoked", 9), it("revoked", ["active"]))
DEACT("sin canary_manage", AUTH12, num("active", 9), forb("ultron.canary_manage"))
DEACT("bandera de escrituras apagada", SA7, num("active", 9), WD, writes=False)
DEACT("número que no existe", SA7, NONE, NF)


# ── 53 · revocar ────────────────────────────────────────────────────────────
def REV(desc, a, n, exp, *, writes=True):
    add("canaryTransitions", "revoke", desc,
        {"facts": {"actor": a, "writesEnabled": writes, "number": n, "reason": "Prueba terminada"}}, exp)


def rev_ok(frm, erased):
    return done(200, "revoked", "canary_number.revoked", frm, {"reason": "Prueba terminada", "cipherErased": erased})


REV("pendiente → revocado (WhatsApp: no había cifrado)", SA7, num("pending", 9), rev_ok("pending", False))
REV("autorizado → revocado", SA7, num("authorized", 9), rev_ok("authorized", False))
REV("activo → revocado", SA7, num("active", 9), rev_ok("active", False))
REV("voz activo: el cifrado se borra en la misma sentencia", SA7, num("active", 9, V), rev_ok("active", True))
REV("voz pendiente: también se borra el cifrado", SA7, num("pending", 9, V), rev_ok("pending", True))
REV("quien registró revoca (es el sentido seguro)", AD9, num("active", 9), rev_ok("active", False))
REV("un Administrador revoca lo que registró un Super Admin", AD10, num("authorized", 7, reg_role="Super Admin"), rev_ok("authorized", False))
REV("ya revocado", SA7, num("revoked", 9), it("revoked", LIVE))
REV("sin canary_manage", AUTH12, num("active", 9), forb("ultron.canary_manage"))
REV("bandera de escrituras apagada", SA7, num("active", 9), WD, writes=False)
REV("número que no existe", SA7, NONE, NF)


# ── 54 · versión esperada ───────────────────────────────────────────────────
def EXPV(desc, a, n, uuid, v, exp, *, writes=True):
    facts = {"actor": a, "writesEnabled": writes, "number": n, "versionUuid": uuid, "version": v}
    add("canaryTransitions", "expectation", desc, {"facts": facts}, exp)


def exp_ok(status, uuid):
    return {"status": 200, "code": None, "toStatus": status,
            "audit": {"event": "canary_number.expectation_set", "from": None, "to": None, "diff": {"versionUuid": uuid}}}


EXPV("una versión CANARY íntegra en un número pendiente", SA7, num("pending", 9), VB, ver("CANARY"), exp_ok("pending", VB))
EXPV("una versión APPROVED en un número activo", SA7, num("active", 9), VA, ver("APPROVED"), exp_ok("active", VA))
EXPV("una versión PUBLISHED en un número autorizado", SA7, num("authorized", 9), VC, ver("PUBLISHED"), exp_ok("authorized", VC))
EXPV("null quita la versión esperada", SA7, num("pending", 9), None, None, exp_ok("pending", None))
EXPV("versión que no existe", SA7, num("pending", 9), VB, NOVER, VNF)
EXPV("versión RETIRED", SA7, num("pending", 9), VB, ver("RETIRED"), nl("RETIRED"))
EXPV("versión DRAFT", SA7, num("pending", 9), VB, ver("DRAFT"), nl("DRAFT"))
EXPV("versión VALIDATED", SA7, num("pending", 9), VB, ver("VALIDATED"), nl("VALIDATED"))
EXPV("versión CANARY no íntegra", SA7, num("pending", 9), VB, ver("CANARY", False), INT)
EXPV("versión ROLLED_BACK y no íntegra: el estado antes que la integridad", SA7, num("pending", 9), VB, ver("ROLLED_BACK", False), nl("ROLLED_BACK"))
EXPV("número revocado con una versión válida", SA7, num("revoked", 9), VB, ver("CANARY"), it("revoked", LIVE))
EXPV("número revocado y versión que no existe: el estado del número antes que la versión", SA7, num("revoked", 9), VB, NOVER, it("revoked", LIVE))
EXPV("número que no existe y versión que no existe", SA7, NONE, VB, NOVER, NF)
EXPV("bandera de escrituras apagada", SA7, num("pending", 9), VB, ver("CANARY"), WD, writes=False)
EXPV("sin canary_manage", AUTH12, num("pending", 9), VB, ver("CANARY"), forb("ultron.canary_manage"))
EXPV("quien registró también fija la expectativa (cualquiera)", AD9, num("pending", 9), VB, ver("CANARY"), exp_ok("pending", VB))


# ── actions de CanaryNumberDto (precedencia forbidden → writes_disabled → invalid_transition → four_eyes_required → active_limit) ──
Y = {"allowed": True, "reason": None}


def no(reason):
    return {"allowed": False, "reason": reason}


F, WDR, ITR, FER, ALR = no("forbidden"), no("writes_disabled"), no("invalid_transition"), no("four_eyes_required"), no("active_limit")


def ACTIONS(desc, viewer, n, auth, act, deact, rev, expc, *, writes=True, active=0, max_active=3):
    facts = {"viewer": viewer, "writesEnabled": writes,
             "number": {"channel": n["channel"], "status": n["status"], "registeredByAdminId": n["registeredByAdminId"]},
             "activeInChannel": active, "maxActive": max_active}
    add("canaryTransitions", "actions", desc, {"facts": facts},
        {"actions": {"authorize": auth, "activate": act, "deactivate": deact, "revoke": rev, "expectation": expc}})


ACTIONS("Super Admin ante un pendiente de otro", SA7, num("pending", 9), Y, ITR, ITR, Y, Y)
ACTIONS("Super Admin ante un pendiente que registró él", SA7, num("pending", 7), FER, ITR, ITR, Y, Y)
ACTIONS("Administrador ante un pendiente de otro", AD10, num("pending", 9), F, ITR, ITR, Y, Y)
ACTIONS("Super Admin ante un autorizado de otro con 2 de 3", SA7, num("authorized", 9), ITR, Y, ITR, Y, Y, active=2)
ACTIONS("Super Admin ante un autorizado de otro con 3 de 3", SA7, num("authorized", 9), ITR, ALR, ITR, Y, Y, active=3)
ACTIONS("Super Admin ante un autorizado que registró él con 3 de 3: cuatro ojos antes que el tope", SA7, num("authorized", 7), ITR, FER, ITR, Y, Y, active=3)
ACTIONS("Super Admin ante un activo de otro", SA7, num("active", 9), ITR, ITR, Y, Y, Y, active=1)
ACTIONS("Super Admin ante un revocado", SA7, num("revoked", 9), ITR, ITR, ITR, ITR, ITR)
ACTIONS("bandera apagada, Super Admin ante un pendiente", SA7, num("pending", 9), WDR, WDR, WDR, WDR, WDR, writes=False)
ACTIONS("bandera apagada, Administrador ante un pendiente: el permiso antes que la bandera", AD10, num("pending", 9), F, WDR, WDR, WDR, WDR, writes=False)
ACTIONS("bandera apagada ante un revocado: la bandera antes que el estado", SA7, num("revoked", 9), WDR, WDR, WDR, WDR, WDR, writes=False)
ACTIONS("solo ultron.view", VIEW11, num("pending", 9), F, F, F, F, F)
ACTIONS("rol a medida con canary_authorize ante un pendiente de otro", AUTH12, num("pending", 9), Y, F, F, F, F)
ACTIONS("Administrador ante un autorizado que registró él: sin llave antes que cuatro ojos", AD9, num("authorized", 9), F, FER, ITR, Y, Y)
ACTIONS("Super Admin ante un activo que registró él con 3 de 3: el estado antes que cuatro ojos y tope", SA7, num("active", 7), ITR, ITR, Y, Y, Y, active=3)
ACTIONS("rol a medida con canary_authorize ante un pendiente que registró él", actor(12, ["ultron.view", "ultron.canary_authorize"], "Rol a medida"),
        num("pending", 12), FER, F, F, F, F)

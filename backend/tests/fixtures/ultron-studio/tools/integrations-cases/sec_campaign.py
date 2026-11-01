# campaignLinkRules · rutas 47 (crear) y 48 (desactivar) (contrato §I3.4, §I7.3, §I8)
# activeLink: la asociación ACTIVA de esta campaña ("L1") o null.
# replaces: null | "L1" (la activa) | "L0" (una inactiva de esta campaña) | "LX" (la activa de OTRA campaña)
#           | "L404" (un uuid que no existe).
# activeTotal: asociaciones activas de TODAS las campañas (incluida L1 si existe).
SA_P = ["ultron.view", "ultron.campaigns_manage", "ultron.canary_manage", "ultron.canary_authorize", "ultron.integrations_manage"]
AD_P = ["ultron.view", "ultron.campaigns_manage", "ultron.canary_manage"]


def actor(aid, perms, role):
    return {"person": True, "adminId": aid, "name": f"Persona {aid}", "role": role, "permissions": perms}


TOKEN = {"person": False, "adminId": None, "name": None, "role": None, "permissions": []}
SA7 = actor(7, SA_P, "Super Admin")
AD9 = actor(9, AD_P, "Administrador")
VIEW11 = actor(11, ["ultron.view"], "Rol a medida")
NOVIEW14 = actor(14, ["ultron.campaigns_manage"], "Rol a medida")

BASE = {"kind": "baseline"}


def TV(status, integrity=True):
    return {"kind": "version", "version": {"exists": True, "status": status, "integrityOk": integrity}}


TV404 = {"kind": "version", "version": {"exists": False}}

REASON_REPLACED = "Sustituida por otra asociación"


def created(replaced=None):
    audit = [{"event": "campaign_link.created", "from": None, "to": "active"}]
    d = {"status": 201, "code": None, "replaced": replaced}
    if replaced is not None:
        audit.append({"event": "campaign_link.deactivated", "from": "active", "to": "inactive"})
        d["replacedRow"] = {"status": "inactive", "deactivationReason": REASON_REPLACED, "replacedBy": "new"}
    d["audit"] = audit
    return d


def err(status, code, payload=None, audit="absent"):
    d = {"status": status, "code": code}
    if payload is not None:
        d["payload"] = payload
    if audit != "absent":
        d["audit"] = audit
    return d


WD = err(409, "integrations_writes_disabled", audit=None)
REPLAYED = {"status": 200, "code": "replayed"}
REUSED = err(409, "client_request_reused")


def forb(p):
    return err(403, "forbidden", {"required_permission": p})


def nl(s):
    return err(409, "version_not_linkable", {"currentStatus": s})


def mnc(reason):
    return err(409, "meta_not_configured", {"reason": reason})


def CREATE(desc, exp, *, a=AD9, writes=True, cr="new", meta=None, sync=True, resolvable=True, target=BASE,
           active=None, replaces=None, total=3, max_active=50):
    facts = {"actor": a, "writesEnabled": writes, "clientRequest": cr, "metaProblem": meta, "metaSyncEnabled": sync,
             "campaignResolvable": resolvable, "target": target, "activeLink": active, "replaces": replaces,
             "activeTotal": total, "maxActive": max_active}
    add("campaignLinkRules", "create", desc, {"facts": facts}, exp)


CREATE("línea base sin asociación activa", created())
CREATE("versión CANARY íntegra", created(), target=TV("CANARY"))
CREATE("versión APPROVED", created(), target=TV("APPROVED"))
CREATE("versión PUBLISHED", created(), target=TV("PUBLISHED"))
CREATE("versión que no existe", err(404, "version_not_found"), target=TV404)
for s in ["DRAFT", "VALIDATING", "VALIDATED", "REVIEW_REQUIRED", "REJECTED", "RETIRED", "ROLLED_BACK"]:
    CREATE(f"versión {s}: no asociable", nl(s), target=TV(s))
CREATE("versión CANARY no íntegra", err(409, "integrity_mismatch"), target=TV("CANARY", False))
CREATE("versión RETIRED y no íntegra: el estado antes que la integridad", nl("RETIRED"), target=TV("RETIRED", False))
CREATE("ya hay una activa y no se dice cuál se sustituye", err(409, "campaign_already_linked", {"current": "L1"}), active="L1")
CREATE("replaces = la activa: la sustituye", created("L1"), target=TV("CANARY"), active="L1", replaces="L1")
CREATE("la línea base sustituye a una asociación con versión", created("L1"), active="L1", replaces="L1")
CREATE("replaces = una inactiva de la misma campaña", err(409, "campaign_link_moved", {"current": "L1"}), active="L1", replaces="L0")
CREATE("replaces = la activa de otra campaña", err(409, "campaign_link_moved", {"current": "L1"}), active="L1", replaces="LX", total=4)
CREATE("sin activa y con replaces de una inactiva", err(409, "campaign_link_moved", {"current": None}), replaces="L0")
CREATE("sin activa y con un replaces que no existe", err(409, "campaign_link_moved", {"current": None}), replaces="L404")
CREATE("49 activas de 50", created(), total=49)
CREATE("50 de 50 sin activa en esta campaña", err(409, "campaign_links_limit", {"max": 50}), total=50)
CREATE("50 de 50 sustituyendo la activa: la sustituida no cuenta", created("L1"), active="L1", replaces="L1", total=50)
CREATE("50 de 50 con activa y sin replaces: ya asociada antes que el tope", err(409, "campaign_already_linked", {"current": "L1"}), active="L1", total=50)
CREATE("50 de 50 con un replaces equivocado: movida antes que el tope", err(409, "campaign_link_moved", {"current": "L1"}), active="L1", replaces="L0", total=50)
CREATE("tope 1 con 1 activa de otra campaña", err(409, "campaign_links_limit", {"max": 1}), total=1, max_active=1)
CREATE("tope 200 con 199", created(), total=199, max_active=200)
CREATE("tope 1 sustituyendo la única activa", created("L1"), active="L1", replaces="L1", total=1, max_active=1)
CREATE("Meta sin token", mnc("missing_access_token"), meta="missing_access_token")
CREATE("Meta sin cuentas", mnc("missing_ad_account_id"), meta="missing_ad_account_id")
CREATE("Meta con cuentas no válidas", mnc("invalid_ad_account_id"), meta="invalid_ad_account_id")
CREATE("Meta con la zona horaria no válida", mnc("invalid_timezone"), meta="invalid_timezone")
CREATE("Meta sin configurar y la campaña sin resolver", mnc("missing_access_token"), meta="missing_access_token", resolvable=False)
CREATE("la sincronización apagada no es «sin configurar»", created(), sync=False)
CREATE("campaña que no está en el espejo de las cuentas conectadas", err(404, "campaign_not_found"), resolvable=False)
CREATE("campaña de una cuenta que dejó de estar conectada", err(404, "campaign_not_found"), resolvable=False, active="L1", total=1)
CREATE("campaña sin resolver y versión que no existe: la campaña antes", err(404, "campaign_not_found"), resolvable=False, target=TV404)
CREATE("versión que no existe y campaña ya asociada: la versión antes", err(404, "version_not_found"), target=TV404, active="L1")
CREATE("versión no asociable y campaña ya asociada", nl("ROLLED_BACK"), target=TV("ROLLED_BACK"), active="L1")
CREATE("bandera de escrituras apagada", WD, writes=False)
CREATE("bandera apagada y Meta sin configurar: la bandera antes", WD, writes=False, meta="missing_access_token")
CREATE("sin campaigns_manage", forb("ultron.campaigns_manage"), a=VIEW11)
CREATE("sin campaigns_manage y la bandera apagada: el permiso antes", forb("ultron.campaigns_manage"), a=VIEW11, writes=False)
CREATE("campaigns_manage sin ultron.view", forb("ultron.view"), a=NOVIEW14)
CREATE("token compartido", err(403, "person_required"), a=TOKEN)
CREATE("reintento idéntico", REPLAYED, cr="replay")
CREATE("reintento idéntico con la bandera apagada: la bandera antes", WD, cr="replay", writes=False)
CREATE("reintento idéntico con Meta ya sin configurar: el reintento antes", REPLAYED, cr="replay", meta="missing_access_token")
CREATE("reintento idéntico cuando su asociación ya es la activa", REPLAYED, cr="replay", active="L1")
CREATE("misma clientRequestId con otro cuerpo", REUSED, cr="reusedOtherBody")
CREATE("misma clientRequestId de otra persona", REUSED, cr="reusedOtherActor")
CREATE("el Super Admin asocia", created(), a=SA7)


def DEACT(desc, exp, *, a=AD9, writes=True, link=None):
    facts = {"actor": a, "writesEnabled": writes, "link": link if link is not None else {"exists": True, "status": "active", "replacedBy": None, "createdByAdminId": 7},
             "reason": "Campaña terminada"}
    add("campaignLinkRules", "deactivate", desc, {"facts": facts}, exp)


DEACT_OK = {"status": 200, "code": None, "toStatus": "inactive",
            "audit": [{"event": "campaign_link.deactivated", "from": "active", "to": "inactive",
                       "diff": {"reason": "Campaña terminada", "replacedBy": None}}]}
ITX = err(409, "invalid_transition", {"currentStatus": "inactive", "allowedFrom": ["active"]})
DEACT("activa → inactiva", DEACT_OK)
DEACT("ya inactiva", ITX, link={"exists": True, "status": "inactive", "replacedBy": None, "createdByAdminId": 7})
DEACT("inactiva porque otra la sustituyó", ITX, link={"exists": True, "status": "inactive", "replacedBy": "L2", "createdByAdminId": 7})
DEACT("asociación que no existe", err(404, "link_not_found"), link={"exists": False})
DEACT("bandera de escrituras apagada", WD, writes=False)
DEACT("bandera apagada y asociación que no existe", WD, writes=False, link={"exists": False})
DEACT("sin campaigns_manage", forb("ultron.campaigns_manage"), a=VIEW11)
DEACT("sin permiso y asociación que no existe: el permiso antes", forb("ultron.campaigns_manage"), a=VIEW11, link={"exists": False})
DEACT("token compartido", err(403, "person_required"), a=TOKEN)
DEACT("un Administrador desactiva la que creó un Super Admin: sin cuatro ojos", DEACT_OK)
DEACT("quien la creó también la desactiva", DEACT_OK, link={"exists": True, "status": "active", "replacedBy": None, "createdByAdminId": 9})

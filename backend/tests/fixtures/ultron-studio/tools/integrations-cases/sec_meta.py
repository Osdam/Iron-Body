# metaClassification · clasificación de Meta Ads, possiblyDeleted, stale y orden (contrato §I3.3, §I3.4, §I7.2)
# classify: configurationProblem() × statusAll()['status'] × meta.ads.sync_enabled
#   → {classification, state, reason}: el bloque meta_ads de la ruta 39; state/reason son también connection.* de la ruta 40.


def MC(desc, problem, status, sync, cls, state, reason):
    add("metaClassification", "classify", desc,
        {"facts": {"configurationProblem": problem, "statusAll": status, "syncEnabled": sync}},
        {"classification": cls, "state": state, "reason": reason})


MC("configurada, al día", None, "ok", True, "connected_read", "ok", None)
MC("configurada, con una pasada en curso", None, "running", True, "connected_read", "running", None)
MC("configurada, dato viejo", None, "stale", True, "degraded", "stale", "stale")
MC("configurada, la última pasada falló", None, "failed", True, "degraded", "failed", "failed")
MC("sin token", "missing_access_token", "not_configured", True, "not_configured", "not_configured", "missing_access_token")
MC("sin cuentas", "missing_ad_account_id", "not_configured", True, "not_configured", "not_configured", "missing_ad_account_id")
MC("cuentas no válidas", "invalid_ad_account_id", "not_configured", True, "not_configured", "not_configured", "invalid_ad_account_id")
MC("zona horaria no válida", "invalid_timezone", "not_configured", True, "not_configured", "not_configured", "invalid_timezone")
MC("sincronización apagada y al día: degradada con sync_disabled", None, "ok", False, "degraded", "ok", "sync_disabled")
MC("sincronización apagada con una pasada en curso", None, "running", False, "degraded", "running", "sync_disabled")
MC("sincronización apagada y dato viejo: sync_disabled manda sobre stale", None, "stale", False, "degraded", "stale", "sync_disabled")
MC("sincronización apagada y la última falló: sync_disabled manda sobre failed", None, "failed", False, "degraded", "failed", "sync_disabled")
MC("sin token y sincronización apagada: manda el problema de configuración", "missing_access_token", "not_configured", False, "not_configured", "not_configured", "missing_access_token")
MC("sin cuentas y sincronización apagada", "missing_ad_account_id", "not_configured", False, "not_configured", "not_configured", "missing_ad_account_id")
MC("cuentas no válidas y sincronización apagada", "invalid_ad_account_id", "not_configured", False, "not_configured", "not_configured", "invalid_ad_account_id")
MC("zona horaria no válida y sincronización apagada", "invalid_timezone", "not_configured", False, "not_configured", "not_configured", "invalid_timezone")

# possiblyDeleted = synced_at nulo o ANTERIOR (estricto) al started_at de la ÚLTIMA fila (por id) de meta_sync_runs
# de su cuenta con kind = insights, status = ok y trigger ≠ backfill. Sin esa fila: solo si synced_at es nulo.
ACC, OTHER = "1000000000004714", "1000000000009023"


def run(i, started, status="ok", trigger="schedule", kind="insights", account=ACC):
    return {"id": i, "account": account, "kind": kind, "status": status, "trigger": trigger, "startedAt": started}


RUNS = [run(1, "2026-10-31T19:15:00+00:00"), run(2, "2026-10-31T20:15:00+00:00"), run(3, "2026-10-31T21:15:00+00:00")]


def PD(desc, synced, runs, deleted, ref):
    add("metaClassification", "possiblyDeleted", desc, {"facts": {"account": ACC, "syncedAt": synced, "runs": runs}},
        {"possiblyDeleted": deleted, "referenceRunId": ref})


PD("synced_at nulo", None, RUNS, True, 3)
PD("synced_at nulo y ninguna pasada", None, [], True, None)
PD("synced_at puesto y ninguna pasada con éxito: no se puede afirmar", "2026-10-31T21:20:00+00:00", [], False, None)
PD("synced_at justo igual al started_at de la última pasada: no es anterior", "2026-10-31T21:15:00+00:00", RUNS, False, 3)
PD("synced_at un segundo antes", "2026-10-31T21:14:59+00:00", RUNS, True, 3)
PD("synced_at un segundo después", "2026-10-31T21:15:01+00:00", RUNS, False, 3)
PD("un relleno posterior no cuenta (no refresca estados)", "2026-10-31T21:30:00+00:00", RUNS + [run(4, "2026-10-31T22:00:00+00:00", trigger="backfill")], False, 3)
PD("una pasada posterior fallida no cuenta", "2026-10-31T21:20:00+00:00", RUNS + [run(4, "2026-10-31T22:15:00+00:00", status="failed")], False, 3)
PD("una pasada posterior en curso no cuenta", "2026-10-31T21:20:00+00:00", RUNS + [run(4, "2026-10-31T22:15:00+00:00", status="running")], False, 3)
PD("una pasada posterior de otra cuenta no cuenta", "2026-10-31T21:20:00+00:00", RUNS + [run(4, "2026-10-31T22:15:00+00:00", account=OTHER)], False, 3)
PD("una pasada posterior de otro kind no cuenta", "2026-10-31T21:20:00+00:00", RUNS + [run(4, "2026-10-31T22:15:00+00:00", kind="reach")], False, 3)
PD("una pasada manual posterior sí cuenta (trigger ≠ backfill)", "2026-10-31T21:30:00+00:00", RUNS + [run(4, "2026-10-31T22:15:00+00:00", trigger="manual")], True, 4)
PD("el mismo instante con otra zona: no es anterior", "2026-10-31T16:15:00-05:00", RUNS, False, 3)
PD("instantes con zonas distintas: se comparan instantes, no textos", "2026-10-31T20:00:00+00:00",
   [run(1, "2026-10-31T19:15:00+00:00"), run(2, "2026-10-31T20:15:00+00:00"), run(3, "2026-10-31T16:15:00-05:00")], True, 3)
PD("entre la penúltima y la última: cuenta la última", "2026-10-31T20:30:00+00:00", RUNS, True, 3)
PD("solo rellenos y synced_at puesto", "2026-10-31T21:20:00+00:00", [run(1, "2026-10-31T19:00:00+00:00", trigger="backfill")], False, None)
PD("solo rellenos y synced_at nulo", None, [run(1, "2026-10-31T19:00:00+00:00", trigger="backfill")], True, None)

# stale (por campaña) = el estado de su cuenta en statusAll() es stale o failed.
for state, stale in [("ok", False), ("running", False), ("stale", True), ("failed", True)]:
    add("metaClassification", "stale", f"cuenta en {state}", {"facts": {"accountState": state}}, {"stale": stale})

# Orden: primero effective_status = 'ACTIVE'; después el nombre sin distinguir mayúsculas; después entity_id.


def C(eid, name, status):
    return {"entityId": eid, "name": name, "effectiveStatus": status}


add("metaClassification", "order", "activas primero, nombre sin mayúsculas y entity_id para desempatar",
    {"facts": {"campaigns": [C("120000000000000001", "Zeta", "ACTIVE"), C("120000000000000002", "alfa", "PAUSED"),
                             C("120000000000000003", "Beta", "ACTIVE"), C("120000000000000004", "beta", "ACTIVE"),
                             C("120000000000000005", "Alfa", "ARCHIVED"), C("120000000000000006", "gamma", None)]}},
    {"order": ["120000000000000003", "120000000000000004", "120000000000000001",
               "120000000000000002", "120000000000000005", "120000000000000006"]})
add("metaClassification", "order", "IN_PROCESS y WITH_ISSUES no van delante: solo ACTIVE",
    {"facts": {"campaigns": [C("120000000000000007", "b", "WITH_ISSUES"), C("120000000000000008", "a", "IN_PROCESS"),
                             C("120000000000000009", "c", "ACTIVE")]}},
    {"order": ["120000000000000009", "120000000000000008", "120000000000000007"]})

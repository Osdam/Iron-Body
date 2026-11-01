# closedSets · conjuntos cerrados (§I2), permisos (§I7.1), errores (§I7.3), etiquetas y constantes
# routeMatrix · las 18 rutas nuevas × roles (§I7.1) + la regla de las 38 anteriores
# errorOrder · el orden de comprobación de cada ruta (§I3.4, §I4.4, §I4.9, §I5.3, §I5.4, §I7.3)
CLOSED_SETS = {
    "classification": ["connected_read", "connected_otp_only", "degraded", "disabled", "not_configured", "registry"],
    "metaConnectionState": ["ok", "running", "stale", "failed", "not_configured"],
    "metaConnectionReason": [None, "missing_access_token", "missing_ad_account_id", "invalid_ad_account_id", "invalid_timezone",
                             "sync_disabled", "stale", "failed"],
    "metaEffectiveStatus": ["ACTIVE", "PAUSED", "ARCHIVED", "IN_PROCESS", "WITH_ISSUES", "DELETED", "unknown"],
    "campaignLinkStatus": ["active", "inactive"],
    "campaignLinkTargetKind": ["baseline", "version"],
    "linkableVersionStatuses": ["APPROVED", "CANARY", "PUBLISHED"],
    "versionStatusLabels": {"DRAFT": "Creada, sin enviar", "VALIDATING": "Validando", "VALIDATED": "Validada, espera revisión",
                            "REVIEW_REQUIRED": "Con avisos · espera revisión", "APPROVED": "Aprobada", "REJECTED": "Rechazada",
                            "CANARY": "Canario", "PUBLISHED": "Publicada", "RETIRED": "Sustituida", "ROLLED_BACK": "Revertida"},
    "canaryChannel": ["whatsapp", "voice"],
    "canaryStatus": ["pending", "authorized", "active", "revoked"],
    "canaryStatusLabels": {"pending": "Pendiente de autorizar (registro)", "authorized": "Autorizado (registro)",
                           "active": "Activo (registro)", "revoked": "Revocado"},
    "canaryActions": ["authorize", "activate", "deactivate", "revoke", "expectation"],
    "canaryAllowedFrom": {"authorize": ["pending"], "activate": ["authorized"], "deactivate": ["active"],
                          "revoke": ["pending", "authorized", "active"], "expectation": ["pending", "authorized", "active"]},
    "coherenceVerdict": ["in_sync", "engine_canary_not_registered", "engine_canary_registered_inactive", "registry_has_extra_active",
                         "engine_canary_phone_unusable", "no_engine_canary", "unreadable"],
    "coherenceVerdictPrecedence": ["no_engine_canary", "unreadable", "engine_canary_phone_unusable", "engine_canary_not_registered",
                                   "engine_canary_registered_inactive", "registry_has_extra_active", "in_sync"],
    "effectiveVersionSource": ["publication_chain", "none"],
    "effectiveVersionChainState": ["applied", "legacy", "off", "fallback"],
    "effectiveVersionNoneReason": ["not_engine_canary", "no_voice_engine", "coherence_unreadable", "chain_unreadable"],
    "expectation": ["none", "match", "mismatch", "not_applicable"],
    "actionReasonPrecedence": ["forbidden", "writes_disabled", "invalid_transition", "four_eyes_required", "active_limit"],
    "snapshotProvider": ["meta_ads", "twilio", "voice"],
    "snapshotKind": ["config", "probe"],
    "snapshotActorType": ["admin", "system"],
    "probeOutcome": ["ok", "degraded", "auth_failed", "unreachable", "error"],
    "probeStepOutcome": ["auth_failed", "unreachable", "error", "skipped"],
    "probeSteps": ["account", "numbers", "caller_ids", "calls", "alerts"],
    "probePageSizes": {"account": None, "numbers": 20, "caller_ids": 20, "calls": 50, "alerts": 50},
    "twilioCallStatuses": ["queued", "ringing", "in-progress", "canceled", "completed", "failed", "busy", "no-answer"],
    "twilioAlertLevels": ["error", "warning", "notice", "debug"],
    "twilioAccountStatuses": ["active", "suspended", "closed"],
    "twilioAccountTypes": ["Trial", "Full"],
    "configState": ["authorized", "drift", "never_authorized"],
    "configFactsKeys": {
        "meta_ads": ["token", "accounts", "graphVersion", "syncEnabled", "syncDays", "staleMinutes", "timezone"],
        "twilio": ["otpDriver", "accountSid", "authToken", "aliasesConsistent", "verifyServiceSid", "from", "apiBase", "verifyBase",
                   "rateLimitName", "sendEnabled", "legacySenderEnabled", "costSoftUsd", "costHardUsd", "probe"],
        "voice": ["elevenlabs", "twilioVoice"]},
    "voiceState": ["ELEVENLABS_NOT_CONFIGURED", "ELEVENLABS_DISABLED", "ELEVENLABS_UNVERIFIED", "ELEVENLABS_VERIFIED"],
    "voiceMissing": ["api_key", "agent_id", "webhook_secret", "region"],
    "voiceVerificationOutcome": ["credentials_ok", "invalid_credentials", "agent_not_found", "not_configured", "unreachable", "error"],
    "voiceVerificationToSnapshotOutcome": {"credentials_ok": "ok", "invalid_credentials": "auth_failed", "agent_not_found": "degraded",
                                           "unreachable": "unreachable", "error": "error"},
    "elevenlabsSignature": ["ok", "missing_signature", "malformed_signature", "expired", "from_future", "mismatch"],
    "elevenlabsRegions": {"default": "https://api.elevenlabs.io", "eu": "https://api.eu.residency.elevenlabs.io"},
    "elevenlabsWebhookTypes": ["post_call_transcription", "post_call_audio", "call_initiation_failure"],
    "voiceCallPolicyReasons": ["calls_disabled_in_code", "twilio_voice_disabled", "no_voice_number", "elevenlabs_not_configured",
                               "elevenlabs_disabled", "elevenlabs_unverified", "not_an_active_voice_canary", "source_not_allowed",
                               "daily_cap_zero"],
    "voiceCallContextCanary": ["active_voice", "not_active", "not_registered"],
    "voiceCallContextSource": ["canary_registry", "marketing_calls", "other"],
    "statusProviderIds": ["meta_ads", "twilio", "twilio_voice", "canaries", "elevenlabs"],
    "auditSubjectTypes": ["draft", "version", "rule", "system", "campaign_link", "canary_number", "integration"],
    "auditEvents": ["campaign_link.created", "campaign_link.deactivated", "canary_number.registered", "canary_number.authorized",
                    "canary_number.four_eyes_denied", "canary_number.activated", "canary_number.deactivated", "canary_number.revoked",
                    "canary_number.expectation_set", "integration.config_acknowledged", "integration.probe_run"],
    "permissions": [
        {"key": "ultron.campaigns_manage", "label": "Asociar campañas de Meta", "administradorDefault": True,
         "help": "Asociar campañas de Meta a la línea base o a una versión del Studio, y desactivar esas asociaciones. Solo queda registrado en el Studio: no cambia nada en Meta ni en lo que ULTRON dice o hace."},
        {"key": "ultron.canary_manage", "label": "Administrar números canarios", "administradorDefault": True,
         "help": "Registrar números de prueba con el consentimiento de su titular, activarlos cuando otra persona los autorizó, desactivarlos y revocarlos. Son datos personales: se guardan como huella, y cifrados solo si son de voz. Nadie activa un número que registró. El motor no los lee en este hito: ULTRON sigue atendiendo solo la conversación canaria configurada en el servidor."},
        {"key": "ultron.canary_authorize", "label": "Autorizar números canarios de otra persona", "administradorDefault": False,
         "help": "Autorizar números canarios registrados por otra persona (cuatro ojos): nadie autoriza lo que registró. No llega por defecto al Administrador."},
        {"key": "ultron.integrations_manage", "label": "Configuración autorizada y comprobación de Twilio", "administradorDefault": False,
         "help": "Reconocer como autorizada la configuración vigente de Meta Ads, Twilio y voz, sin ver ni editar secretos (solo huellas), y lanzar la comprobación de solo lectura de Twilio. No llega por defecto al Administrador."},
    ],
    "errorCodes": [
        {"code": "integrations_writes_disabled", "http": 409, "payload": [], "routes": [47, 48, 49, 50, 51, 52, 53, 54]},
        {"code": "meta_not_configured", "http": 409, "payload": ["reason"], "routes": [47]},
        {"code": "campaign_not_found", "http": 404, "payload": [], "routes": [47]},
        {"code": "link_not_found", "http": 404, "payload": [], "routes": [48]},
        {"code": "version_not_linkable", "http": 409, "payload": ["currentStatus"], "routes": [47, 49, 54]},
        {"code": "campaign_already_linked", "http": 409, "payload": ["current"], "routes": [47]},
        {"code": "campaign_link_moved", "http": 409, "payload": ["current"], "routes": [47]},
        {"code": "campaign_links_limit", "http": 409, "payload": ["max"], "routes": [47]},
        {"code": "canary_not_found", "http": 404, "payload": [], "routes": [50, 51, 52, 53, 54]},
        {"code": "canary_number_exists", "http": 409, "payload": [], "routes": [49]},
        {"code": "canary_live_limit", "http": 409, "payload": ["max", "channel"], "routes": [49]},
        {"code": "canary_active_limit", "http": 409, "payload": ["max", "channel"], "routes": [51]},
        {"code": "config_changed", "http": 409, "payload": ["provider", "effectiveFingerprint", "drift"], "routes": [55]},
        {"code": "probe_disabled", "http": 409, "payload": [], "routes": [56]},
        {"code": "twilio_not_configured", "http": 409, "payload": ["reason"], "routes": [56]},
        {"code": "four_eyes_required", "http": 403, "payload": ["action"], "routes": [50, 51]},
        {"code": "invalid_transition", "http": 409, "payload": ["currentStatus", "allowedFrom"], "routes": [48, 50, 51, 52, 53, 54]},
        {"code": "client_request_reused", "http": 409, "payload": [], "routes": [47, 49, 55, 56]},
        {"code": "integrity_mismatch", "http": 409, "payload": [], "routes": [47, 49, 54]},
        {"code": "version_not_found", "http": 404, "payload": [], "routes": [47, 49, 54]},
        {"code": "forbidden", "http": 403, "payload": ["required_permission"], "routes": list(range(39, 57))},
        {"code": "person_required", "http": 403, "payload": [], "routes": list(range(39, 57))},
    ],
    "twilioProbeNotConfiguredReasons": ["missing_account_sid", "missing_credential"],
    "constants": {
        "phoneFingerprintLabel": "ultron-canary-phone/1", "configFingerprintLabel": "ultron-studio-config-fingerprint/1",
        "signatureToleranceSeconds": 1800, "signatureFutureSkewSeconds": 300, "agentIdPattern": "/^[A-Za-z0-9_-]{1,64}$/",
        "probeCooldownSeconds": 300, "probeBudgetSeconds": 30, "canaryMaxActive": {"default": 3, "min": 1, "max": 10},
        "canaryMaxLive": 10, "campaignLinksMaxActive": {"default": 50, "min": 1, "max": 200}, "countryCodesDefault": ["57"],
        "countryCodePattern": "^[1-9]\\d{0,2}$", "e164Pattern": "^\\+[1-9]\\d{7,14}$", "twilioAccountSidPattern": "^AC[0-9a-f]{32}$",
        "twilioErrorCodePattern": "^[1-9]\\d{4}$", "replacedDeactivationReason": "Sustituida por otra asociación",
        "voiceVerifiedMaxAgeSeconds": 2592000, "callsEnabledInCode": False, "studioRoutes": 56, "newRoutes": 18},
}

# ── routeMatrix ──
ROUTES = [
    (39, "GET", "integrations/status", "ultron.view", ["ultron-studio-read"], "UltronStudioIntegrationController"),
    (40, "GET", "integrations/meta/campaigns", "ultron.view", ["ultron-studio-read"], "UltronStudioCampaignLinkController"),
    (41, "GET", "integrations/meta/links", "ultron.view", ["ultron-studio-read"], "UltronStudioCampaignLinkController"),
    (42, "GET", "integrations/canaries", "ultron.view", ["ultron-studio-read"], "UltronStudioCanaryController"),
    (43, "GET", "integrations/twilio", "ultron.view", ["ultron-studio-read"], "UltronStudioIntegrationController"),
    (44, "GET", "integrations/twilio/calls", "ultron.view", ["ultron-studio-read"], "UltronStudioIntegrationController"),
    (45, "GET", "integrations/voice", "ultron.view", ["ultron-studio-read"], "UltronStudioIntegrationController"),
    (46, "GET", "integrations/config", "ultron.view", ["ultron-studio-read"], "UltronStudioIntegrationController"),
    (47, "POST", "integrations/meta/links", "ultron.campaigns_manage", ["ultron-studio-write"], "UltronStudioCampaignLinkController"),
    (48, "POST", "integrations/meta/links/{uuid}/deactivate", "ultron.campaigns_manage", ["ultron-studio-write"], "UltronStudioCampaignLinkController"),
    (49, "POST", "integrations/canaries", "ultron.canary_manage", ["ultron-studio-write"], "UltronStudioCanaryController"),
    (50, "POST", "integrations/canaries/{uuid}/authorize", "ultron.canary_authorize", ["ultron-studio-write"], "UltronStudioCanaryController"),
    (51, "POST", "integrations/canaries/{uuid}/activate", "ultron.canary_manage", ["ultron-studio-write"], "UltronStudioCanaryController"),
    (52, "POST", "integrations/canaries/{uuid}/deactivate", "ultron.canary_manage", ["ultron-studio-write"], "UltronStudioCanaryController"),
    (53, "POST", "integrations/canaries/{uuid}/revoke", "ultron.canary_manage", ["ultron-studio-write"], "UltronStudioCanaryController"),
    (54, "POST", "integrations/canaries/{uuid}/expectation", "ultron.canary_manage", ["ultron-studio-write"], "UltronStudioCanaryController"),
    (55, "POST", "integrations/config/acknowledge", "ultron.integrations_manage", ["ultron-studio-write"], "UltronStudioIntegrationController"),
    (56, "POST", "integrations/twilio/probe", "ultron.integrations_manage", ["ultron-studio-write", "ultron-studio-probe"], "UltronStudioIntegrationController"),
]
ADMIN_DENIED = {50, 55, 56}
for n, method, path, perm, throttles, controller in ROUTES:
    roles = {"sinSesion": 401, "tokenCompartido": 403, "Recepción": 403, "Entrenador": 403, "Administrativo": 403,
             "Administrador": 403 if n in ADMIN_DENIED else "allowed", "Super Admin": "allowed"}
    add("routeMatrix", "route", f"{n} · {method} {path}",
        {"route": n, "method": method, "path": path, "permission": perm, "throttles": throttles, "controller": controller},
        {"roles": roles})
for n, method, path, perm, throttles, controller in ROUTES:
    if n >= 47:
        add("routeMatrix", "noView", f"{n} · {perm} sin ultron.view: 403 antes de buscar el recurso",
            {"route": n, "method": method, "path": path, "permissions": [perm], "uuidExists": False if "{uuid}" in path else None},
            {"status": 403, "code": "forbidden", "payload": {"required_permission": "ultron.view"}})
add("routeMatrix", "legacy", "las 38 rutas anteriores",
    {"count": 38},
    {"roles": {"sinSesion": 401, "tokenCompartido": 403, "Recepción": 403, "Entrenador": 403, "Administrativo": 403,
               "Administrador": "unchanged", "Super Admin": "unchanged"}, "studioRoutesTotal": 56})


# ── errorOrder ──
def S(check, status, code=None):
    return {"check": check, "status": status, "code": code}


HEADV = [S("session", 401), S("gate", 403), S("person", 403, "person_required"), S("view", 403, "forbidden")]
SHAPE = S("shape", 422)
HEAD = HEADV + [SHAPE]                      # lecturas: la llave es ultron.view (paso «view»)
PERM = S("permission", 403, "forbidden")
HEADW = HEADV + [PERM, SHAPE]               # escrituras: la llave de la ruta la exige la puerta de AuthorizationMap ANTES de validar la forma (Enmienda 4)
WRITES = S("writesFlag", 409, "integrations_writes_disabled")
REPLAY = [S("replay", 200, "replayed"), S("replayMismatch", 409, "client_request_reused")]
ORDERS = {n: HEAD + [S("done", 200)] for n in range(39, 47)}
ORDERS[47] = HEADW + [WRITES] + REPLAY + [
    S("metaConfigured", 409, "meta_not_configured"), S("campaignResolves", 404, "campaign_not_found"),
    S("versionExists", 404, "version_not_found"), S("versionLinkable", 409, "version_not_linkable"), S("versionIntegrity", 409, "integrity_mismatch"),
    S("noActiveWithoutReplaces", 409, "campaign_already_linked"), S("replacesIsActive", 409, "campaign_link_moved"),
    S("activeLimit", 409, "campaign_links_limit"), S("uniqueViolation", 409, "campaign_already_linked"), S("done", 201)]
ORDERS[48] = HEADW + [WRITES, S("linkExists", 404, "link_not_found"), S("state", 409, "invalid_transition"), S("done", 200)]
ORDERS[49] = HEADW + [WRITES] + REPLAY + [
    S("versionExists", 404, "version_not_found"), S("versionLinkable", 409, "version_not_linkable"), S("versionIntegrity", 409, "integrity_mismatch"),
    S("liveLimit", 409, "canary_live_limit"), S("uniqueViolation", 409, "canary_number_exists"), S("done", 201)]
ORDERS[50] = HEADW + [WRITES, S("numberExists", 404, "canary_not_found"), S("identity", 403, "four_eyes_required"),
                     S("state", 409, "invalid_transition"), S("done", 200)]
ORDERS[51] = HEADW + [WRITES, S("numberExists", 404, "canary_not_found"), S("identity", 403, "four_eyes_required"),
                     S("state", 409, "invalid_transition"), S("activeLimit", 409, "canary_active_limit"), S("done", 200)]
ORDERS[52] = HEADW + [WRITES, S("numberExists", 404, "canary_not_found"), S("state", 409, "invalid_transition"), S("done", 200)]
ORDERS[53] = list(ORDERS[52])
ORDERS[54] = HEADW + [WRITES, S("numberExists", 404, "canary_not_found"), S("state", 409, "invalid_transition"),
                     S("versionExists", 404, "version_not_found"), S("versionLinkable", 409, "version_not_linkable"),
                     S("versionIntegrity", 409, "integrity_mismatch"), S("done", 200)]
ORDERS[55] = HEADW + REPLAY + [S("fingerprintIsEffective", 409, "config_changed"), S("done", 201)]
ORDERS[56] = HEADW + [S("probeFlag", 409, "probe_disabled")] + REPLAY + [
    S("twilioConfigured", 409, "twilio_not_configured"), S("cooldown", 200, "reused"), S("done", 201)]
for n, method, path, perm, throttles, controller in ROUTES:
    add("errorOrder", "route", f"{n} · {method} {path}", {"route": n}, {"order": ORDERS[n]})

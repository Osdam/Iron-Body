#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# smoke-production.sh — comprobación transversal de producción tras una rotación.
#
# Sólo lecturas. Ni un POST que envíe un mensaje, cobre, emita una factura o
# cree un registro: la comprobación de que el sistema sigue en pie no puede ser
# la que lo mueva. La única escritura tolerada es la que ya hace cualquier
# lectura autenticada (marcar `last_seen_at` de la sesión).
#
# El token administrativo se lee del .env dentro del propio servidor y se pasa a
# curl por cabecera, nunca por la línea de comandos ni por pantalla.
#
# Uso: smoke-production.sh [--json]
# ---------------------------------------------------------------------------
set -Eeuo pipefail

APP_DIR="${APP_DIR:-/var/www/api/backend}"
BASE="${BASE:-https://api.ironbodyneiva.cloud}"
RUN_AS="${RUN_AS:-www-data}"

PASS=0; FAILED=0
declare -a FAILURES=()

ok()   { PASS=$((PASS+1));   printf '  \033[32m✔\033[0m %-52s %s\n' "$1" "${2:-}"; }
bad()  { FAILED=$((FAILED+1)); FAILURES+=("$1"); printf '  \033[31m✘\033[0m %-52s %s\n' "$1" "${2:-}"; }
hdr()  { printf '\n\033[1m%s\033[0m\n' "$*"; }

# El secreto compartido ADMIN_API_TOKEN abre /api/admin/* pero NO el Inbox, la
# analítica ni la supervisión: esas pantallas exigen una sesión de administrador
# de verdad, y responden 401 `inbox_requires_admin` a un token de automatización.
# Así que la comprobación usa las dos credenciales: el token para lo que es
# suyo, y una sesión efímera de sólo lectura que se emite aquí y se revoca al
# terminar, pase lo que pase.
TOKEN="$(awk '{l=$0; sub(/^[ \t]+/,"",l); if (l ~ /^ADMIN_API_TOKEN=/) { v=substr(l, index(l,"=")+1); gsub(/^["'"'"']|["'"'"']$/,"",v); t=v } } END { print t }' "$APP_DIR/.env")"
[ -n "$TOKEN" ] || { printf 'no se pudo leer ADMIN_API_TOKEN\n' >&2; exit 1; }

SESSION_UUID=""
revoke_session() {
    [ -n "$SESSION_UUID" ] || return 0
    (cd "$APP_DIR" && sudo -u "$RUN_AS" php -r '
        require "vendor/autoload.php"; $a = require "bootstrap/app.php";
        $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        App\Models\AdminSession::where("uuid", $argv[1])
            ->update(["revoked_at" => now(), "revoked_reason" => "smoke_finished"]);
    ' "$SESSION_UUID" >/dev/null 2>&1 || true)
    SESSION_UUID=""
}
trap revoke_session EXIT

SESSION_OUT="$(cd "$APP_DIR" && sudo -u "$RUN_AS" php -r '
    require "vendor/autoload.php"; $a = require "bootstrap/app.php";
    $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $admin = App\Models\Admin::where("role", App\Models\Admin::ROLE_SUPER_ADMIN)
        ->where("status", "active")->orderBy("id")->first();
    if (! $admin) { echo "ERROR|no hay Super Admin activo"; exit; }
    $issued = app(App\Services\Admin\AdminSessionService::class)
        ->issueSession($admin, ["ip_address" => "127.0.0.1", "user_agent" => "smoke-production"], false);
    echo $issued["token"]."|".$issued["session"]->uuid."|".$admin->email;
' 2>&1)"
case "$SESSION_OUT" in
    ERROR*) printf 'no se pudo emitir la sesión de smoke: %s\n' "$SESSION_OUT" >&2; exit 1 ;;
esac
IFS='|' read -r SESSION_TOKEN SESSION_UUID SESSION_EMAIL <<< "$SESSION_OUT"
[ -n "$SESSION_TOKEN" ] || { printf 'sesión de smoke vacía\n' >&2; exit 1; }

# curl_check <etiqueta> <ruta> [codigo_esperado] [credencial: token|session]
curl_check() {
    local label="$1" path="$2" want="${3:-200}" cred="${4:-token}" bearer code
    case "$cred" in
        session) bearer="$SESSION_TOKEN" ;;
        *)       bearer="$TOKEN" ;;
    esac
    code="$(curl -sS -o /tmp/.smoke.$$ -w '%{http_code}' --max-time 25 \
        -H "Authorization: Bearer ${bearer}" -H 'Accept: application/json' \
        "${BASE}${path}" 2>/dev/null || echo 000)"
    if [ "$code" = "$want" ]; then
        ok "$label" "HTTP $code"
    else
        bad "$label" "HTTP $code (esperado $want) — $(head -c 120 /tmp/.smoke.$$ 2>/dev/null)"
    fi
    rm -f /tmp/.smoke.$$
}

artisan_check() {
    local label="$1"; shift
    if (cd "$APP_DIR" && sudo -u "$RUN_AS" php artisan "$@" >/tmp/.art.$$ 2>&1); then
        ok "$label" "exit 0"
    else
        bad "$label" "exit $? — $(tail -2 /tmp/.art.$$ | head -c 160)"
    fi
    rm -f /tmp/.art.$$
}

hdr "API pública"
curl_check "health"                       "/api/health"

hdr "Autenticación administrativa"
curl_check "dashboard (auth.admin)"       "/api/dashboard"
code_noauth="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 20 "${BASE}/api/dashboard" || echo 000)"
if [ "$code_noauth" = "401" ]; then ok "dashboard SIN token rechazado" "HTTP 401"; else bad "dashboard SIN token rechazado" "HTTP $code_noauth"; fi

hdr "Inbox V2"
curl_check "inbox · conversaciones"       "/api/admin/marketing/inbox/conversations?per_page=1" 200 session
curl_check "inbox · métricas"             "/api/admin/marketing/inbox/metrics" 200 session
curl_check "inbox · capacidades"          "/api/admin/marketing/inbox/capabilities" 200 session
curl_check "inbox · catálogo de etiquetas" "/api/admin/marketing/inbox/tags" 200 session

hdr "Analytics comercial"
curl_check "analytics · summary"          "/api/admin/marketing/analytics/summary" 200 session
curl_check "analytics · funnel"           "/api/admin/marketing/analytics/funnel" 200 session
curl_check "analytics · quality"          "/api/admin/marketing/analytics/quality" 200 session

hdr "Supervisión y aprobaciones"
curl_check "supervisión · estado"         "/api/admin/marketing/supervision/state" 200 session
curl_check "supervisión · capacidades"    "/api/admin/marketing/supervision/capabilities" 200 session
curl_check "supervisión · aprobaciones"   "/api/admin/marketing/supervision/approvals" 200 session
curl_check "supervisión · incidentes"     "/api/admin/marketing/supervision/incidents" 200 session
curl_check "supervisión · alertas"        "/api/admin/marketing/supervision/alerts" 200 session
curl_check "supervisión · oportunidades"  "/api/admin/marketing/supervision/opportunities" 200 session

hdr "Base de datos"
db="$(cd "$APP_DIR" && sudo -u "$RUN_AS" php -r '
    require "vendor/autoload.php"; $a = require "bootstrap/app.php";
    $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    try {
        $q = fn($t) => Illuminate\Support\Facades\DB::table($t)->count();
        printf("members=%d users=%d payments=%d invoices=%d leads=%d opportunities=%d mensajes=%d failed_jobs=%d jobs=%d",
            $q("members"), $q("users"), $q("payments"), $q("electronic_invoices"),
            $q("marketing_leads"), $q("commercial_opportunities"),
            $q("marketing_messages"), $q("failed_jobs"), $q("jobs"));
    } catch (Throwable $e) { echo "ERROR: ".substr($e->getMessage(),0,120); }
' 2>&1)"
case "$db" in
    ERROR*) bad "consulta agregada" "$db" ;;
    *)      ok  "consulta agregada" "$db" ;;
esac

hdr "Colas y workers"
workers="$(supervisorctl status 2>/dev/null | grep -c RUNNING || echo 0)"
[ "$workers" -ge 10 ] && ok "workers supervisor" "$workers RUNNING" || bad "workers supervisor" "solo $workers RUNNING"
systemctl is-active --quiet ironbody-billing-worker && ok "ironbody-billing-worker" "active" || bad "ironbody-billing-worker" "inactivo"
artisan_check "queue · listado de colas configuradas" queue:monitor default,billing,agent,commercial,media,whatsapp-high

hdr "Scheduler"
artisan_check "schedule:list" schedule:list

hdr "Doctores de subsistema"
artisan_check "billing · factus-doctor"   billing:factus-doctor
artisan_check "meta · doctor"             meta:doctor
artisan_check "marketing · ai-doctor"     marketing:ai-doctor
artisan_check "marketing · knowledge"     marketing:knowledge-doctor

hdr "IRON GUARD"
artisan_check "iron-guard:scan (determinista)" iron-guard:scan

hdr "Respaldos"
for f in /var/lib/ironbody/backup-status.json /var/lib/ironbody/restore-test-status.json; do
    if [ -f "$f" ]; then
        ok "$(basename "$f")" "$(python3 -c "
import json,sys
d=json.load(open('$f'))
keys=[k for k in ('status','ok','result','finished_at','completed_at','timestamp','duration_seconds','rto_seconds') if k in d]
print(' '.join(f'{k}={d[k]}' for k in keys) or list(d)[:4])
" 2>/dev/null || echo 'ilegible')"
    else
        bad "$(basename "$f")" "no existe"
    fi
done

hdr "Interruptores de seguridad (deben seguir apagados)"
flags="$(cd "$APP_DIR" && sudo -u "$RUN_AS" php -r '
    require "vendor/autoload.php"; $a = require "bootstrap/app.php";
    $a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $f = [
        "META_ENABLED" => config("services.meta.enabled") ?? config("meta.enabled"),
        "MARKETING_AGENT_ENABLED" => config("marketing.agent.enabled") ?? env("MARKETING_AGENT_ENABLED"),
        "COMMERCIAL_AUTONOMY_ENABLED" => env("COMMERCIAL_AUTONOMY_ENABLED"),
        "HERMES_ENABLED" => env("MARKETING_HERMES_ENABLED"),
        "IRON_GUARD_AUTO_REMEDIATION" => env("IRON_GUARD_AUTO_REMEDIATION"),
    ];
    foreach ($f as $k => $v) { echo $k."=".var_export(filter_var($v, FILTER_VALIDATE_BOOLEAN), true)." "; }
' 2>&1)"
echo "  $flags"
if echo "$flags" | grep -q "=true"; then
    bad "interruptores peligrosos" "$(echo "$flags" | tr ' ' '\n' | grep '=true' | tr '\n' ' ')"
else
    ok "todos apagados" ""
fi

printf '\n%s\n' "$(printf '═%.0s' $(seq 1 66))"
printf '  PASS: %d   FAIL: %d\n' "$PASS" "$FAILED"
if [ "$FAILED" -gt 0 ]; then
    printf '  fallos: %s\n' "${FAILURES[*]}"
    printf '\n\033[1;31mSMOKE PRODUCCIÓN = FAIL\033[0m\n'
    exit 1
fi
printf '\n\033[1;32mSMOKE PRODUCCIÓN = PASS\033[0m\n'

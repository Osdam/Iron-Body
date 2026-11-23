#!/bin/bash
# Unidad 2 del cierre operativo (el laboratorio frente al motor) · ESTADO DE PRODUCCIÓN Y DE SUS DEPENDENCIAS · SOLO LECTURA.
# Se ejecuta en el servidor como root, ANTES y DESPUÉS del despliegue, con la misma forma, para comparar:
#     ssh ironbody-vps 'bash -s "<desde, UTC>"' < estado-antes-despues.sh   («desde» = desde cuándo contar errores y fallos)
# Se guarda la salida de antes y la de después y se comparan con diff (quitando la hora): lo que no sea el cambio declarado
# de la unidad es un hallazgo. La sección [6] es propia de la unidad 1 (las llamadas internas desde sus sondas).
# No escribe nada: psql con default_transaction_read_only=on; artisan solo con órdenes de lectura; de la configuración,
# solo valores que no son secretos (el modelo, los precios, los topes) y, de las claves, únicamente si están puestas.
set -u
# El idioma que trae la sesión de ssh no existe en el servidor: sin esto, psql (un envoltorio de perl) avisa en cada
# llamada y el aviso se mezcla con el dato.
export LC_ALL=C LANG=C
unset LANGUAGE LC_CTYPE 2>/dev/null || true
DESDE="${1:-2026-11-23 01:48:43}"
DESDE_NGINX=$(date -u -d "$DESDE" '+%d/%b/%Y:%H:%M:%S')
export PGOPTIONS="-c default_transaction_read_only=on -c statement_timeout=20000"
q() { sudo -E -u postgres psql -X -Atq -d ironbody -c "$1" 2>/dev/null; }
cd /var/www/api || exit 1

echo "[1] CÓDIGO Y PROCESOS · $(date -u '+%d-%m-%Y %H:%M:%S') UTC"
echo "  sirve: $(git rev-parse HEAD) · cambios con seguimiento: $(git status --porcelain --untracked-files=no | wc -l) · sin seguimiento: $(git ls-files --others --exclude-standard | wc -l)"
echo "  workers por estado: $(supervisorctl status 2>/dev/null | awk '{print $2}' | sort | uniq -c | tr -s ' ' | paste -sd ';' -)"
supervisorctl status 2>/dev/null | awk '{ n=$1; sub(/:.*/, "", n); c[n]++; if ($2 != "RUNNING") malo[n]++ } END { for (k in c) printf "    %s ×%d%s\n", k, c[k], (malo[k] ? " (NO todos en RUNNING)" : "") }' | sort
echo "  facturación: $(systemctl is-active ironbody-billing-worker 2>/dev/null) · sesiones de tmux: $(tmux ls 2>/dev/null | wc -l) · despliegues vivos: $(pgrep -fc 'deploy-cierre-lote|ironbody-migrate|entorno-lab' || true)"
cd backend || exit 1
L=storage/logs/laravel.log
echo "  production.ERROR desde $DESDE UTC: $(awk -v d="[$DESDE" 'substr($0,1,1)=="[" && substr($0,1,20) >= d && /production\.ERROR/ {n++} END {print n+0}' "$L") · 5xx: $(awk -v d="$DESDE_NGINX" 'substr($4,2) >= d && $9 ~ /^5/ {n++} END {print n+0}' /var/log/nginx/access.log) · 429: $(awk -v d="$DESDE_NGINX" 'substr($4,2) >= d && $9 == 429 {n++} END {print n+0}' /var/log/nginx/access.log)"
echo "  cachés: $(stat -c '%U %a %n' bootstrap/cache/config.php bootstrap/cache/routes-v7.php 2>/dev/null | sed 's#bootstrap/cache/##' | paste -sd ';' -) · fichero de entorno: $(stat -c '%U:%G %a, modificado %y' .env | cut -c1-48)"

echo "[2] COLAS"
echo "  failed_jobs desde $DESDE UTC: $(q "select count(*) from failed_jobs where failed_at >= '$DESDE'") · en total: $(q "select count(*) from failed_jobs")"
echo "  trabajos en espera por cola: $(q "select coalesce(string_agg(queue || '=' || n, ' · ' order by queue), 'ninguno') from (select queue, count(*) n from jobs group by queue) x")"

echo "[3] BASE DE DATOS"
echo "  migraciones registradas: $(q "select count(*) from migrations") · último lote: $(q "select max(batch) from migrations") · la última: $(q "select migration from migrations order by id desc limit 1")"
echo "  columna ultron_studio_lab_run_cases.engine: $(q "select coalesce((select data_type || ', nula=' || is_nullable || ', defecto=' || coalesce(column_default, 'ninguno') from information_schema.columns where table_schema='public' and table_name='ultron_studio_lab_run_cases' and column_name='engine'), 'NO EXISTE')")"
echo "  columnas de ultron_studio_lab_run_cases: $(q "select count(*) from information_schema.columns where table_schema='public' and table_name='ultron_studio_lab_run_cases'")"
echo "  ejecuciones del laboratorio por estado: $(q "select coalesce(string_agg(status || '=' || n, ' · ' order by status), 'ninguna') from (select status, count(*) n from ultron_studio_lab_runs group by status) x") · en curso (queued, running): $(q "select count(*) from ultron_studio_lab_runs where status in ('queued','running')")"
echo "  índices no válidos: $(q "select count(*) from pg_index where not indisvalid") · restricciones sin validar: $(q "select count(*) from pg_constraint where not convalidated") · transacciones abiertas hace más de 5 min: $(q "select count(*) from pg_stat_activity where xact_start < now() - interval '5 minutes' and state <> 'idle'")"
echo "  privilegios de iron_app en ultron_studio_lab_run_cases: $(q "select string_agg(p, ',' order by p) from unnest(array['SELECT','INSERT','UPDATE','DELETE','TRUNCATE','REFERENCES','TRIGGER']) p where has_table_privilege('iron_app', 'public.ultron_studio_lab_run_cases', p)")"
echo "  filas por tabla del Studio (ultron_studio_*):"
for t in $(q "select tablename from pg_tables where schemaname='public' and tablename like 'ultron\_studio\_%' order by 1"); do printf '    %-52s %s\n' "$t" "$(q "select count(*) from \"$t\"")"; done
echo "  huella de la lista anterior: se compara antes y después"

echo "[4] STUDIO, WHATSAPP Y LA CANARIA"
OUT=$(sudo -u www-data php artisan ultron-studio:publication-check 2>&1); C=$?
echo "  ultron-studio:publication-check → salida $C"
echo "$OUT" | grep -v '^$' | head -8 | cut -c1-220 | sed 's/^/    /'
T=$(sudo -u www-data php artisan ultron:prompt-trace --last=3 --json 2>/dev/null)
echo "  ultron:prompt-trace --last=3 --json → sha256 $(printf '%s' "$T" | sha256sum | cut -c1-16)…"
printf '%s' "$T" | python3 -I -c '
import json, sys
try:
    t = json.load(sys.stdin)
except Exception as e:
    print("    (no se pudo leer la traza:", str(e)[:80], ")"); sys.exit(0)
c = t.get("current") or {}
print("    llave de prompts:", t.get("key"), "· sello:", t.get("studioConfig"), "· canario configurado:", t.get("canaryConfigured"), "· base:", t.get("base"), "· compilador:", t.get("compiler"))
print("    decide ofrecería ahora a la canaria:", c.get("mode"), "· versión", c.get("version"), "· huella", str(c.get("bundleHash"))[:8], "· motivo", c.get("reason"))
for x in (t.get("lastTurns") or [])[:3]:
    print("    turno:", json.dumps(x, ensure_ascii=False)[:230])
'

echo "[5] CONFIGURACIÓN EFECTIVA (la caché; solo valores que no son secretos)"
sudo -u www-data php artisan tinker --execute='
$l = config("ultron_studio.lab"); $p = config("ultron_studio.publishing");
$s = fn ($v) => $v === null ? "null" : (is_bool($v) ? ($v ? "true" : "false") : (string) $v);
echo "  laboratorio: enabled=", $s($l["enabled"] ?? null), " · driver=", $s($l["driver"] ?? null), " · modelo=", $s($l["openai"]["model"] ?? null), " · esfuerzo=[", $s($l["openai"]["reasoning_effort"] ?? null), "] · temperatura=", $s($l["openai"]["temperature"] ?? null), PHP_EOL;
echo "  precios (USD por millón): entrada=", $s($l["price_input_usd_per_mtok"] ?? null), " · salida=", $s($l["price_output_usd_per_mtok"] ?? null), PHP_EOL;
echo "  presupuestos y topes: diario=", $s($l["daily_budget_usd"] ?? null), " · por ejecución=", $s($l["run_budget_usd"] ?? null), " · ejecuciones al día=", $s($l["max_runs_per_day"] ?? null), " · casos por ejecución=", $s($l["max_cases_per_run"] ?? null), " · espera=", $s($l["timeout_seconds"] ?? null), " s", PHP_EOL;
echo "  clave propia del laboratorio: ", (($l["openai"]["api_key"] ?? "") !== "" && ($l["openai"]["api_key"] ?? null) !== null) ? "PUESTA" : "FALTA", PHP_EOL;
echo "  puerta de publicar: lab_gate=", $s($p["lab_gate"] ?? null), " · lab_source=", $s($p["lab_source"] ?? null), PHP_EOL;
echo "  asesor de WhatsApp: driver=", $s(config("marketing.ai.driver")), " · modelo=", $s(config("marketing.ai.openai.model")), " · canario configurado=", config("marketing.ultron.canary_conversation_id") !== null ? "sí" : "no", PHP_EOL;
' 2>&1 | grep -v '^$' | cut -c1-260

echo "[6] LLAMADAS REALES DE n8n A /api/internal/ DESDE LAS 01:58 UTC (después de las sondas de la unidad 1)"
awk -v d="23/Nov/2026:01:58:00" 'substr($4,2) >= d && $7 ~ /^\/api\/internal\// { r=$7; sub(/\?.*/, "", r); sub(/context\/[0-9]+/, "context/N", r); k[substr($6,2)" "r" "$9]++ } END { n=0; for (x in k) { print "    " x " ×" k[x]; n++ } if (!n) print "    ninguna" }' /var/log/nginx/access.log | sort

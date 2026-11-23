#!/bin/bash
# Estado del servidor tras un despliegue del cierre operativo y su vigilancia (DEPLOY_CIERRE_LOTES.md §7) · SOLO LECTURA.
# Escrito para la unidad 1 (PT-19, 23-11-2026); sirve para las demás cambiando la hora. Lo específico de PT-19 son las
# dos últimas secciones (las llamadas a /api/internal/).
# Uso (desde el Mac): ssh ironbody-vps 'bash -s "2026-11-23 01:48:43"' < vigilancia-tras-despliegue.sh    (la hora, en UTC)
set -u
DESDE="${1:-2026-11-23 01:48:43}"
DESDE_NGINX=$(date -u -d "$DESDE" '+%d/%b/%Y:%H:%M:%S')
cd /var/www/api || exit 1
echo "reloj del servidor: $(date -u '+%d-%m-%Y %H:%M:%S') UTC · desde el despliegue ($DESDE UTC): $(( ( $(date -u +%s) - $(date -u -d "$DESDE" +%s) ) / 60 )) min"
echo "sirve: $(git rev-parse HEAD) · cambios con seguimiento: $(git status --porcelain --untracked-files=no | wc -l) · sin seguimiento: $(git ls-files --others --exclude-standard | wc -l) (el 23-11-2026 eran 89, todos de junio a octubre: comparar)"
echo "bootstrap/app.php que sirve: sha256 $(sha256sum backend/bootstrap/app.php | cut -c1-64)"
echo "workers: $(supervisorctl status 2>/dev/null | awk '{print $2}' | sort | uniq -c | tr -s ' ' | paste -sd ';' -) · el más antiguo lleva: $(supervisorctl status 2>/dev/null | awk '$2 == "RUNNING" {print $NF}' | sort | tail -1) · facturación: $(systemctl is-active ironbody-billing-worker 2>/dev/null)"
echo "sesión tmux pt19: $(tmux has-session -t pt19 2>/dev/null && echo 'sigue abierta' || echo 'terminada') · procesos de despliegue vivos: $(pgrep -fc 'deploy-cierre-lote|ironbody-migrate' || true)"
cd backend || exit 1
L=storage/logs/laravel.log
echo "production.ERROR desde el despliegue: $(awk -v d="[$DESDE" 'substr($0,1,1)=="[" && substr($0,1,20) >= d && /production\.ERROR/ {n++} END {print n+0}' "$L") · WARNING: $(awk -v d="[$DESDE" 'substr($0,1,1)=="[" && substr($0,1,20) >= d && /production\.WARNING/ {n++} END {print n+0}' "$L")"
echo "failed_jobs desde el despliegue: $(sudo -u postgres psql -X -Atq -d ironbody -c "set default_transaction_read_only=on; select count(*) from failed_jobs where failed_at >= '$DESDE'" | tail -1) · en total: $(sudo -u postgres psql -X -Atq -d ironbody -c "set default_transaction_read_only=on; select count(*) from failed_jobs" | tail -1)"
sudo -u www-data php artisan ultron-studio:publication-check > /dev/null 2>&1; echo "ultron-studio:publication-check: salida $?"
echo "cachés: $(stat -c '%U %a' bootstrap/cache/config.php bootstrap/cache/routes-v7.php 2>/dev/null | paste -sd ';' -) · route:list: $(sudo -u www-data php artisan route:list --json 2>/dev/null | grep -o '"uri"' | wc -l) rutas"
echo "respuestas de la API desde el despliegue, por clase: $(awk -v d="$DESDE_NGINX" '{ if (substr($4,2) >= d) c[substr($9,1,1)]++ } END { for (k in c) printf "%sxx=%d ", k, c[k] }' /var/log/nginx/access.log)"
echo "5xx desde el despliegue: $(awk -v d="$DESDE_NGINX" 'substr($4,2) >= d && $9 ~ /^5/ {n++} END {print n+0}' /var/log/nginx/access.log) · 429: $(awk -v d="$DESDE_NGINX" 'substr($4,2) >= d && $9 == 429 {n++} END {print n+0}' /var/log/nginx/access.log)"
echo "llamadas a /api/internal/ desde el despliegue (ruta → código × veces):"
awk -v d="$DESDE_NGINX" 'substr($4,2) >= d && $7 ~ /^\/api\/internal\// { r=$7; sub(/\?.*/, "", r); sub(/context\/[0-9]+/, "context/N", r); k[substr($6,2)" "r" "$9]++ } END { for (x in k) print "  " x " ×" k[x] }' /var/log/nginx/access.log | sort
echo "llamadas a /api/internal/ con 200 desde el despliegue (hora UTC, ruta); las de las sondas del lead son GET ai/doctor:"
awk -v d="$DESDE_NGINX" 'substr($4,2) >= d && $7 ~ /^\/api\/internal\// && $9 == 200 { r=$7; sub(/\?.*/, "", r); sub(/context\/[0-9]+/, "context/N", r); print "  " substr($4,14,8), substr($6,2), r }' /var/log/nginx/access.log | tail -12

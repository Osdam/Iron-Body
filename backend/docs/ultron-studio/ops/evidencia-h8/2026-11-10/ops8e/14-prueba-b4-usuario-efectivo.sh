#!/bin/bash
# OPS8-e · B-4: prueba aislada de quien_programa() de h7-monitor-scope.sh (el usuario EFECTIVO de cada línea activa de
# schedule:run). Extrae el python del mando del repo y le pasa crontabs sintéticos (sin tocar ningún crontab real).
# Uso: bash 14-prueba-b4-usuario-efectivo.sh <ruta a ops/h7-monitor-scope.sh>
set -euo pipefail
T=$(mktemp); trap 'rm -f "$T"' EXIT
python3 -I - "$1" "$T" <<'PY'
import sys
s = open(sys.argv[1], encoding='utf-8').read()
i = s.index("quien_programa() {"); j = s.index("python3 -I -c '", i) + len("python3 -I -c '"); k = s.index("'\n}", j)
open(sys.argv[2], 'w').write(s[j:k].replace('\\\\', '\\'))
PY
ok=0; mal=0
prueba() { local esperado=$1; shift; r=$(printf '%s\n' "$@" | python3 -I "$T"); if [ "$r" = "$esperado" ]; then echo "OK    $esperado"; ok=$((ok + 1)); else echo "FALLO esperado [$esperado] y salió [$r]"; mal=$((mal + 1)); fi; }
prueba "www-data=1" '#@crontab root' '* * * * * cd /var/www/api/backend && sudo -u www-data /usr/bin/php artisan schedule:run >> storage/logs/schedule.log' '#@crontab www-data'
prueba "root=1" '#@crontab root' '* * * * * cd /var/www/api/backend && php artisan schedule:run >> /dev/null 2>&1'
prueba "www-data=1" '#@crontab root' '#@crontab www-data' '* * * * * cd /var/www/api/backend && php artisan schedule:run'
prueba "ninguna" '#@crontab root' '# * * * * * cd /x && sudo -u www-data php artisan schedule:run' 'MAILTO=root' '#@crontab www-data'
prueba "www-data=1" '#@sistema /etc/cron.d/laravel' 'SHELL=/bin/sh' '* * * * * www-data cd /var/www/api/backend && php artisan schedule:run'
prueba "root=1" '#@sistema /etc/cron.d/laravel' '* * * * * root cd /var/www/api/backend && php artisan schedule:run'
prueba "www-data=1" '#@sistema /etc/crontab' '* * * * * root cd /var/www/api/backend && sudo -H -u www-data php artisan schedule:run'
prueba "root=1 www-data=1" '#@crontab root' '* * * * * php /var/www/api/backend/artisan schedule:run' '#@crontab www-data' '* * * * * php /var/www/api/backend/artisan schedule:run'
prueba "www-data=1" '#@crontab root' '@reboot cd /x && runuser -u www-data -- php artisan schedule:run'
prueba "www-data=1" '#@crontab root' '* * * * * sudo -uwww-data php /x/artisan schedule:run'
prueba "ninguna" '#@crontab root' '* * * * * cd /x && php artisan queue:work'
echo "$ok OK · $mal FALLO"

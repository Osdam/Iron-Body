# B-4 (OPS8-e) · SOLO LECTURA, en el servidor como root: ¿quién ejecuta schedule:run? y ¿qué HEAD sirve producción?
# No escribe nada: crontab -l, grep, systemctl list-timers, find -printf y git rev-parse/status.
for u in root www-data; do
  printf 'crontab de %-8s: líneas activas con schedule:run = ' "$u"
  crontab -l -u "$u" 2>/dev/null | grep -v '^[[:space:]]*#' | grep -c 'schedule:run'
  crontab -l -u "$u" 2>/dev/null | grep -v '^[[:space:]]*#' | grep 'schedule:run' | sed 's/^/    /'
done
r=$(grep -Hn 'schedule:run' /etc/crontab /etc/cron.d/* 2>/dev/null); echo '/etc/crontab y /etc/cron.d:'; if [ -n "$r" ]; then printf '%s\n' "$r" | sed 's/^/    /'; else echo '    (ninguna)'; fi
r=$(systemctl list-timers --all --no-pager 2>/dev/null | grep -iE 'artisan|schedule|laravel'); echo 'timers de systemd:'; if [ -n "$r" ]; then printf '%s\n' "$r" | sed 's/^/    /'; else echo '    (ninguno del programador)'; fi
echo 'dueños de los ficheros de storage tocados en las últimas 48 h (con el programador como root salen de root):'
find /var/www/api/backend/storage -xdev -type f -mmin -2880 -printf '%u\n' 2>/dev/null | sort | uniq -c | sed 's/^/    /'
echo 'ficheros de root en storage y bootstrap/cache (los 20 primeros):'
find /var/www/api/backend/storage /var/www/api/backend/bootstrap/cache -xdev -user root -printf '    %u:%g %m %TY-%Tm-%Td %p\n' 2>/dev/null | head -20
printf 'producción: HEAD %s · origin/main (última lectura, sin fetch) %s · cambios locales %s\n' \
  "$(git -C /var/www/api rev-parse --short=7 HEAD)" "$(git -C /var/www/api rev-parse --short=7 origin/main)" \
  "$(git -C /var/www/api status --porcelain --untracked-files=no | wc -l | tr -d ' ')"

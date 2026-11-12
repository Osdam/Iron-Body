#!/bin/bash
# Despliegue de una CORRECCIÓN PUNTUAL del backend (solo código de aplicación; sin migraciones, rutas ni configuración).
# Uso (servidor, root): bash -s -- <commit que sirve producción (7)> <commit esperado de origin/main (7)> <fichero permitido> [<fichero permitido>…]
# Aborta sin tocar nada si producción no está en el commit previo, si el árbol no está limpio, si origin/main no es el
# esperado, si no es un avance rápido o si el diff toca algo fuera de la lista (o una migración, una ruta o la
# configuración). Después: merge --ff-only con umask 022 (para que www-data lea lo reescrito), queue:restart (los workers
# cargan el código nuevo), y comprueba workers, 401 y ERROR. Imprime la vuelta atrás.
set -euo pipefail
export LC_ALL=C
cd /tmp
PREVIO=$1; ESPERADO=$2; shift 2
PERMITIDOS=" $* "
APP=/var/www/api
aborta() { echo "ABORTA (no se ha tocado nada): $*"; exit 1; }
g() { git -C "$APP" "$@"; }
[ "$(id -u)" = 0 ] || aborta "se ejecuta como root"
[ "$(g rev-parse --short=7 HEAD)" = "$PREVIO" ] || aborta "producción no está en $PREVIO (está en $(g rev-parse --short=7 HEAD))"
[ -z "$(g status --porcelain --untracked-files=no)" ] || aborta "el árbol desplegado tiene cambios sin confirmar"
g fetch -q origin main
[ "$(g rev-parse --short=7 origin/main)" = "$ESPERADO" ] || aborta "origin/main no es $ESPERADO (es $(g rev-parse --short=7 origin/main))"
g merge-base --is-ancestor HEAD origin/main || aborta "origin/main no desciende de lo desplegado"
CAMBIADOS=$(g diff --name-only HEAD origin/main)
for f in $CAMBIADOS; do
  case "$f" in backend/database/*|backend/routes/*|backend/config/*|backend/composer.*|backend/bootstrap/*) aborta "la corrección toca $f (migración, ruta, configuración o dependencias): no es una corrección puntual" ;; esac
  case "$PERMITIDOS" in *" $f "*) ;; *) aborta "la corrección toca $f, que no está en la lista permitida" ;; esac
done
echo "0. producción $PREVIO limpia · origin/main $ESPERADO · $(g rev-list --count HEAD..origin/main) commit(s) · ficheros: $(echo $CAMBIADOS)"
W0=$(supervisorctl status 2>/dev/null | grep -c RUNNING || true)
E0=$(grep -h -c "production.ERROR" "$APP"/backend/storage/logs/laravel*.log 2>/dev/null | paste -sd+ - | bc)
( umask 022; g merge -q --ff-only origin/main )
echo "1. código: HEAD=$(g rev-parse --short=7 HEAD)"
for f in $CAMBIADOS; do [ -f "$APP/$f" ] && echo "   $(stat -c '%U %a' "$APP/$f") $f"; done
( cd "$APP/backend" && sudo -u www-data php artisan queue:restart >/dev/null )
sleep 12
W1=$(supervisorctl status 2>/dev/null | grep -c RUNNING || true); WX=$(supervisorctl status 2>/dev/null | grep -vc RUNNING || true)
echo "2. workers RUNNING: $W0 → $W1 (otros: $WX)"
C=$(curl -s -o /dev/null -w '%{http_code}' https://api.ironbodyneiva.cloud/api/admin/voice/status)
echo "3. admin/voice/status sin token → $C (esperado 401)"
E1=$(grep -h -c "production.ERROR" "$APP"/backend/storage/logs/laravel*.log 2>/dev/null | paste -sd+ - | bc)
echo "4. production.ERROR en laravel*.log: $E0 → $E1"
echo "   vuelta atrás: ( cd $APP && umask 022 && git checkout -q --detach $PREVIO ) && cd $APP/backend && sudo -u www-data php artisan queue:restart"
[ "$W1" = "$W0" ] && [ "$WX" = 0 ] && [ "$C" = 401 ] && [ "$E1" = "$E0" ] && echo "CORRECCIÓN DESPLEGADA SIN DESVÍOS" || { echo "CORRECCIÓN DESPLEGADA CON DESVÍOS: revisar arriba"; exit 1; }

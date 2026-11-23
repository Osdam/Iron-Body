#!/bin/bash
# SOLO LECTURA (lead, 23-11-2026). Lo que hay que ver en el servidor antes de abrir la cuenta técnica y después de
# cerrarla: que el guion de la cuenta es el del repositorio y que no queda ningún cierre automático pendiente.
#     ssh ironbody-vps 'bash -s' < previas.sh
export LC_ALL=C LANG=C
echo "hora: $(date -u '+%Y-%m-%d %H:%M:%S') UTC"
echo "guion de la cuenta técnica (sha256): $(sha256sum /root/lab-tecnica-cuenta.sh | cut -c1-64)"
echo "fichero de sesión en el servidor: $([ -e /root/.lab-tecnica-sesion.json ] && echo EXISTE || echo no existe)"
n=$(ps -eo pid=,ppid=,etimes=,args= | grep "[s]leep 1500" | wc -l | tr -d ' ')
echo "cierres automáticos pendientes (procesos «sleep 1500»): $n"
ps -eo pid=,ppid=,etimes=,args= | grep "[s]leep 1500" | awk '{ printf "    pid %s · padre %s · %s s en marcha\n", $1, $2, $3 }'
echo "API que sirve producción: $(git -C /var/www/api rev-parse --short=8 HEAD 2>/dev/null || echo '?')"

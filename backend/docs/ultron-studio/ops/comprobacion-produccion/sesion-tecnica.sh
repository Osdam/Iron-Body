#!/bin/bash
# Sesión de la cuenta técnica (id 32, «Studio smoke») para las lecturas de SOLO LECTURA de la unidad 2, desde el Mac.
#   SESION_TECNICA_DIR=<carpeta temporal> bash sesion-tecnica.sh abrir
#                                  activa la cuenta en el servidor (lab-tecnica-cuenta.sh abrir) y trae la sesión a un fichero
#                                  600 de ESA carpeta (.sesion-tecnica.json), sin imprimirla
#   bash sesion-tecnica.sh cerrar  deja la cuenta en reposo en el servidor, comprueba que la sesión ya no vale y borra la copia
# La sesión dura como mucho 25 minutos (cierre automático en el servidor).
set -u
# La copia local de la sesión NUNCA dentro del repositorio: va a la carpeta que diga SESION_TECNICA_DIR (obligatoria; la
# temporal de la sesión de trabajo). El 23-11-2026 se usó con esa carpeta en el scratchpad del lead.
: "${SESION_TECNICA_DIR:?pon SESION_TECNICA_DIR con una carpeta temporal fuera del repositorio}"
[ -d "$SESION_TECNICA_DIR" ] || { echo "SESION_TECNICA_DIR no es una carpeta"; exit 2; }
case "$(cd "$SESION_TECNICA_DIR" && pwd -P)/" in "$(cd "$(dirname "$0")/../../../../.." 2>/dev/null && pwd -P)/"*) echo "SESION_TECNICA_DIR está dentro del repositorio: usa una carpeta temporal"; exit 2 ;; esac
F="$SESION_TECNICA_DIR/.sesion-tecnica.json"
O=(-o BatchMode=yes -o ConnectTimeout=15 -o ServerAliveInterval=5 -o ServerAliveCountMax=24)
limpia() { grep -v "^perl: warning\|^	LANGUAGE\|^	LC_ALL\|^	LC_CTYPE\|^	LANG =\|are supported and installed\|Falling back to"; }
case "${1:-}" in
  abrir)
    ssh "${O[@]}" ironbody-vps "export LC_ALL=C; bash /root/lab-tecnica-cuenta.sh abrir" 2>&1 | limpia || exit 1
    ( umask 077; ssh "${O[@]}" ironbody-vps "cat /root/.lab-tecnica-sesion.json" > "$F" 2>/dev/null )
    [ -s "$F" ] && echo "copia local de la sesión: $(stat -f '%Sp %z bytes' "$F") (no se imprime) · $(date -u +%H:%M:%S) UTC" || { echo "no se pudo traer la sesión"; exit 1; }
    ;;
  cerrar)
    ssh "${O[@]}" ironbody-vps "export LC_ALL=C; bash /root/lab-tecnica-cuenta.sh cerrar" 2>&1 | limpia
    [ -f "$F" ] && rm -P "$F" 2>/dev/null; [ -f "$F" ] && rm -f "$F"
    echo "copia local borrada: $([ -f "$F" ] && echo NO || echo sí) · $(date -u +%H:%M:%S) UTC"
    ;;
  *) echo "uso: sesion-tecnica.sh abrir|cerrar"; exit 2 ;;
esac

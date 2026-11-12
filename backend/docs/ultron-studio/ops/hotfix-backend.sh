#!/bin/bash
# Despliegue de una CORRECCIÓN PUNTUAL del backend (solo código de aplicación; sin migraciones, rutas ni configuración).
# Uso (servidor, root): bash -s -- <commit que sirve producción (7)> <commit esperado de origin/main (7)> <fichero permitido> [<fichero permitido>…]
# Aborta sin tocar nada si producción no está en el commit previo, si el árbol no está limpio, si origin/main no es el
# esperado, si no es un avance rápido o si el diff toca algo fuera de la lista (o una migración, una ruta o la
# configuración), o si cambia VoiceCallPolicy.php en algo que no sea CERRAR la constante de llamadas (abrirla es solo
# de deploy-h8-backend-fase1.sh --fase=4a; I-4 de la revisión de la apertura), o si la constante de envíos de la
# automatización (OutreachSendGate) pasa a abierta. Después: merge --ff-only con umask 022 (para que www-data lea lo reescrito), queue:restart (los workers
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
# La constante de llamadas del H8 (I-4 b de la revisión de la apertura, 12-11-2026). Abrirla es SOLO de
# deploy-h8-backend-fase1.sh --fase=4a (protección 2: la autorización citada, la fase 2 completa y las llamadas cerradas),
# y con ella abierta ningún commit toca VoiceCallPolicy.php (h8-voice-scope.sh la vería «incoherente»). Por esta vía, en
# el diff NETO de lo desplegado a origin/main, el fichero no cambia; o, como vuelta atrás hacia delante, cambia SOLO la
# declaración abierta por la cerrada (`= true;` → `= false;`, misma sangría), y además está en la lista permitida.
VCP=backend/app/Services/Voice/VoiceCallPolicy.php
LINEA_ABIERTA='^[[:space:]]*public const CALLS_ENABLED_IN_CODE = true;[[:space:]]*$'
estado_vcp() { # <commit> → cerrada | abierta | incoherente (…), con las reglas de deploy-h8-backend-fase1.sh (REV8 m-4)
  local t c a x y
  t=$(g show "$1:$VCP" 2>/dev/null) || { echo "incoherente (el fichero no está en $1)"; return 0; }
  c=$(printf '%s\n' "$t" | grep -cE '^[[:space:]]*public const CALLS_ENABLED_IN_CODE = false;[[:space:]]*$' || true)
  a=$(printf '%s\n' "$t" | grep -cE "$LINEA_ABIERTA" || true)
  x=$(printf '%s\n' "$t" | grep -cE 'CALLS_ENABLED_IN_CODE[[:space:]]*=[[:space:]]*false' || true)
  y=$(printf '%s\n' "$t" | grep -cE 'CALLS_ENABLED_IN_CODE[[:space:]]*=[[:space:]]*true' || true)
  if [ "$c $y" = "1 0" ]; then echo cerrada; elif [ "$a $x" = "1 0" ]; then echo abierta
  else echo "incoherente (declaraciones = false: $c, = true: $a; menciones de false: $x, de true: $y)"; fi
}
E_ANTES=$(estado_vcp HEAD); E_DESPUES=$(estado_vcp origin/main)
case "$E_ANTES" in cerrada|abierta) ;; *) aborta "VoiceCallPolicy::CALLS_ENABLED_IN_CODE en lo desplegado ($PREVIO): $E_ANTES" ;; esac
case "$E_DESPUES" in cerrada|abierta) ;; *) aborta "VoiceCallPolicy::CALLS_ENABLED_IN_CODE en origin/main ($ESPERADO): $E_DESPUES" ;; esac
CIERRA=0
if grep -qxF "$VCP" <<< "$CAMBIADOS"; then   # sin tubería: con pipefail, el SIGPIPE de un «grep -q» daría «no está»
  [ "$E_ANTES $E_DESPUES" = "abierta cerrada" ] || aborta "la corrección cambia $VCP (constante $E_ANTES en lo desplegado y $E_DESPUES en origin/main): abrir las llamadas es solo de deploy-h8-backend-fase1.sh --fase=4a, y con la constante abierta nadie toca ese fichero; esta vía solo admite CERRARLA (una línea, = true; → = false;)"
  CUERPO=$(g diff -U0 HEAD origin/main -- "$VCP" | sed -n '/^@@ /,$p' | tail -n +2)
  MENOS=$(printf '%s\n' "$CUERPO" | sed -n 1p); MAS=$(printf '%s\n' "$CUERPO" | sed -n 2p)
  ESPERADA=${MENOS#-}; ESPERADA=${ESPERADA/= true;/= false;}
  [ "$(g diff --numstat HEAD origin/main -- "$VCP")" = "1"$'\t'"1"$'\t'"$VCP" ] && [ "$(printf '%s\n' "$CUERPO" | grep -c .)" = 2 ] \
    && [ "${MENOS:0:1}" = - ] && [ "${MAS:0:1}" = + ] && [[ "${MENOS#-}" =~ $LINEA_ABIERTA ]] && [ "${MAS#+}" = "$ESPERADA" ] \
    || aborta "la corrección cambia $VCP en algo más que cerrar la constante (solo se admite la declaración = true; → = false;, una línea, misma sangría)"
  CIERRA=1
fi
# La constante de envíos de la automatización del H9 (el hueco gemelo, 12-11-2026): abrir los envíos reales tampoco es de
# esta vía. De lo desplegado a origin/main no puede pasar a abierta ni quedar ilegible; y, si ya está abierta, el
# fichero no cambia por aquí salvo para cerrarla. Con la constante cerrada en los dos lados el fichero sí puede cambiar.
OSG=backend/app/Services/Outreach/Run/OutreachSendGate.php
estado_osg() { # <commit> → cerrada | abierta | incoherente (…), con las mismas reglas que estado_vcp
  local t c a x y
  t=$(g show "$1:$OSG" 2>/dev/null) || { echo "incoherente (el fichero no está en $1)"; return 0; }
  c=$(printf '%s\n' "$t" | grep -cE '^[[:space:]]*public const SENDS_ENABLED_IN_CODE = false;[[:space:]]*$' || true)
  a=$(printf '%s\n' "$t" | grep -cE '^[[:space:]]*public const SENDS_ENABLED_IN_CODE = true;[[:space:]]*$' || true)
  x=$(printf '%s\n' "$t" | grep -cE 'SENDS_ENABLED_IN_CODE[[:space:]]*=[[:space:]]*false' || true)
  y=$(printf '%s\n' "$t" | grep -cE 'SENDS_ENABLED_IN_CODE[[:space:]]*=[[:space:]]*true' || true)
  if [ "$c $y" = "1 0" ]; then echo cerrada; elif [ "$a $x" = "1 0" ]; then echo abierta
  else echo "incoherente (declaraciones = false: $c, = true: $a; menciones de false: $x, de true: $y)"; fi
}
S_ANTES=$(estado_osg HEAD); S_DESPUES=$(estado_osg origin/main)
case "$S_ANTES/$S_DESPUES" in
  cerrada/cerrada|abierta/cerrada) ;;
  abierta/abierta) if grep -qxF "$OSG" <<< "$CAMBIADOS"; then aborta "la corrección cambia $OSG con la constante de envíos abierta: por esta vía solo se puede cerrar"; fi ;;
  *) aborta "OutreachSendGate::SENDS_ENABLED_IN_CODE pasa de «$S_ANTES» en lo desplegado ($PREVIO) a «$S_DESPUES» en origin/main ($ESPERADO): abrir los envíos reales de la automatización no es de esta vía" ;;
esac
for f in $CAMBIADOS; do
  case "$f" in backend/database/*|backend/routes/*|backend/config/*|backend/composer.*|backend/bootstrap/*) aborta "la corrección toca $f (migración, ruta, configuración o dependencias): no es una corrección puntual" ;; esac
  case "$PERMITIDOS" in *" $f "*) ;; *) aborta "la corrección toca $f, que no está en la lista permitida" ;; esac
done
echo "0. producción $PREVIO limpia · origin/main $ESPERADO · $(g rev-list --count HEAD..origin/main) commit(s) · ficheros: $(echo $CAMBIADOS)"
if [ "$CIERRA" = 1 ]; then
  echo "   constante de llamadas (VoiceCallPolicy): abierta → CERRADA (la declaración, una línea): después, bash /root/h8-voice-scope.sh estado tiene que decir «constante de llamadas: cerrada»"
else
  echo "   constante de llamadas (VoiceCallPolicy): $E_ANTES, sin cambios (el fichero no cambia)"
fi
echo "   constante de envíos (OutreachSendGate): $S_ANTES → $S_DESPUES"
W0=$(supervisorctl status 2>/dev/null | grep -c RUNNING || true)
E0=$({ grep -h -c "production.ERROR" "$APP"/backend/storage/logs/laravel*.log 2>/dev/null || true; } | paste -sd+ - | bc)
( umask 022; g merge -q --ff-only origin/main )
echo "1. código: HEAD=$(g rev-parse --short=7 HEAD)"
for f in $CAMBIADOS; do [ -f "$APP/$f" ] && echo "   $(stat -c '%U %a' "$APP/$f") $f"; done
( cd "$APP/backend" && sudo -u www-data php artisan queue:restart >/dev/null )
sleep 12
W1=$(supervisorctl status 2>/dev/null | grep -c RUNNING || true); WX=$(supervisorctl status 2>/dev/null | grep -vc RUNNING || true)
echo "2. workers RUNNING: $W0 → $W1 (otros: $WX)"
C=$(curl -s -o /dev/null -w '%{http_code}' https://api.ironbodyneiva.cloud/api/admin/voice/status)
echo "3. admin/voice/status sin token → $C (esperado 401)"
E1=$({ grep -h -c "production.ERROR" "$APP"/backend/storage/logs/laravel*.log 2>/dev/null || true; } | paste -sd+ - | bc)
echo "4. production.ERROR en laravel*.log: $E0 → $E1"
echo "   vuelta atrás: ( cd $APP && umask 022 && git checkout -q --detach $PREVIO ) && cd $APP/backend && sudo -u www-data php artisan queue:restart"
[ "$CIERRA" = 0 ] || echo "   OJO: esa vuelta atrás vuelve a ABRIR la constante de llamadas ($PREVIO la tiene abierta): solo con la autorización escrita de la apertura"
[ "$W1" = "$W0" ] && [ "$WX" = 0 ] && [ "$C" = 401 ] && [ "$E1" = "$E0" ] && echo "CORRECCIÓN DESPLEGADA SIN DESVÍOS" || { echo "CORRECCIÓN DESPLEGADA CON DESVÍOS: revisar arriba"; exit 1; }

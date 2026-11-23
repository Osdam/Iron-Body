#!/bin/bash
# BANCO EN EL PROPIO SERVIDOR para entorno-lab.sh (lead, 23-11-2026 · unidad 2). NO toca la aplicación ni su fichero de
# entorno: trabaja sobre un árbol de pega en /var/tmp con un fichero de entorno INVENTADO (ningún secreto), el vendor real
# por un enlace (solo para que Dotenv cargue) y sus propios respaldos. Ejercita el guion ORIGINAL (tiene que fallar cerrado,
# como falló) y el CORREGIDO (comprobar, aplicar, comprobar, vuelta) con el sudo, el php y el Dotenv de producción.
#   ssh ironbody-vps 'bash -s' < banco-en-servidor.sh
# Antes hay que dejar en /root/cierre-lote-5/ los dos guiones que compara: entorno-lab.sh (el ORIGINAL del commit 9c2325a1,
# sha256 ead13949…) y entorno-lab.corregido.sh (el de hoy, 1fac5d1f…), y entorno-lab.txt. Resultado del 23-11-2026:
# evidencia-h9/2026-11-23/04-despliegue-de-la-unidad-2.txt. Sirve de plantilla para ensayar en el servidor cualquier guion
# que cruce a www-data: el banco local no tiene el sudo de producción (Defaults env_reset).
set -u
export LC_ALL=C
ORI=/root/cierre-lote-5/entorno-lab.sh
COR=/root/cierre-lote-5/entorno-lab.corregido.sh
B=$(mktemp -d /var/tmp/banco-entorno-lab.XXXXXX) || exit 1
chmod 755 "$B"
mkdir -p "$B/app/backend" && chmod 755 "$B/app" "$B/app/backend"
ln -s /var/www/api/backend/vendor "$B/app/backend/vendor"
cat > "$B/app/backend/.env" <<'ENV'
APP_NAME="Banco de ensayo"
APP_ENV=testing
# un comentario
export OTRA_CLAVE=valor
ULTRON_STUDIO_LAB_ENABLED=true
ULTRON_STUDIO_LAB_OPENAI_MODEL=modelo-de-prueba
ULTRON_STUDIO_LAB_OPENAI_REASONING_EFFORT=medium
ULTRON_STUDIO_LAB_PRICE_INPUT_USD_PER_MTOK=1.00
ULTRON_STUDIO_LAB_PRICE_OUTPUT_USD_PER_MTOK="5.00"
CON_ESPACIOS="un valor con espacios"
VACIA=
ENV
chown www-data:www-data "$B/app/backend/.env" && chmod 600 "$B/app/backend/.env"
cp "$B/app/backend/.env" "$B/env.partida" && chmod 600 "$B/env.partida"
cp /root/cierre-lote-5/entorno-lab.txt "$B/entorno-lab.txt"
for v in ORI COR; do
  src=${!v}
  sed -e "s#^APP=/var/www/api\$#APP=$B/app#" -e "s#^BK_RAIZ=/var/backups/ironbody\$#BK_RAIZ=$B/respaldos#" "$src" > "$B/$v.sh"
  echo "banco de $v: $(diff <(cat "$src") "$B/$v.sh" | grep -c '^[<>]') líneas sustituidas (2 de menos y 2 de más: APP y BK_RAIZ) · sha256 del guion probado: $(sha256sum "$src" | cut -c1-16)…"
done
echo "diferencia entre el original y el corregido: $(diff "$ORI" "$COR" | grep -c '^[<>]') líneas (1 de código cambiada y 6 de comentario añadidas)"
diff "$ORI" "$COR" | grep '^[<>]' | grep -v '^> #' | cut -c1-150
corre() { echo "── $1"; shift; "$@" 2>&1 | sed 's/^/   | /' | cut -c1-230; echo "   código de salida: ${PIPESTATUS[0]}"; }
cd "$B" || exit 1
corre "ORIGINAL · comprobar" bash "$B/ORI.sh" comprobar
corre "ORIGINAL · aplicar (tiene que fallar cerrado, como en producción)" bash "$B/ORI.sh" aplicar
echo "   el fichero de pega, tras el original: $(cmp -s "$B/app/backend/.env" "$B/env.partida" && echo 'INTACTO' || echo 'CAMBIADO')"
corre "CORREGIDO · comprobar" bash "$B/COR.sh" comprobar
# El respaldo se llama por el segundo en que se crea: sin esta pausa, el del corregido choca con el que dejó el original
# («el respaldo … ya existe») y la prueba no prueba nada (pasó en la primera pasada del banco).
sleep 2
corre "CORREGIDO · aplicar" bash "$B/COR.sh" aplicar
echo "   el fichero de pega, tras el corregido: $(stat -c '%U:%G %a' "$B/app/backend/.env") · líneas que cambian frente a la partida:"
diff "$B/env.partida" "$B/app/backend/.env" | sed 's/^/   | /'
corre "CORREGIDO · comprobar (después de aplicar)" bash "$B/COR.sh" comprobar
RESP=$(ls -d "$B"/respaldos/entorno-lab-* 2>/dev/null | tail -1)
echo "   respaldo del corregido: $(ls "$RESP" | paste -sd ' ' -) (env.nuevo ya no debe estar)"
corre "CORREGIDO · vuelta=<respaldo> (sin --regenera)" bash "$B/COR.sh" "vuelta=$RESP"
echo "   el fichero de pega, tras la vuelta: $(cmp -s "$B/app/backend/.env" "$B/env.partida" && echo 'IGUAL A LA PARTIDA, byte a byte' || echo 'DISTINTO') · $(stat -c '%U:%G %a' "$B/app/backend/.env")"
cd / && rm -rf "$B" && echo "banco borrado: $([ -e "$B" ] && echo NO || echo sí)"
echo "la aplicación real no se ha tocado: $(stat -c '%U:%G %a, modificado %y' /var/www/api/backend/.env | cut -c1-52) · producción sirve $(git -C /var/www/api rev-parse --short=8 HEAD)"

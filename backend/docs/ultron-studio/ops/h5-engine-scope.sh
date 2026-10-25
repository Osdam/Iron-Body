#!/bin/bash
# Hito 5 · alcance de lectura del motor sobre el Studio. Se ejecuta EN EL SERVIDOR como root y SOLO con la
# autorización expresa del propietario para la fase que corresponda:
#   bash h5-engine-scope.sh off      (fase 1 / vuelta atrás: el motor no consulta la cadena; Legacy exacto)
#   bash h5-engine-scope.sh canary   (fase 2: el motor lee en la conversación canaria y el Studio puede publicar)
#   bash h5-engine-scope.sh all      (fase 3: todas las conversaciones)
# Escribe las dos llaves de entorno (MARKETING_ULTRON_STUDIO_CONFIG y ULTRON_STUDIO_PUBLISHING_ENABLED), regenera la
# caché de configuración como www-data con modo 600, reinicia los workers y comprueba la configuración efectiva sin
# imprimir valores sensibles. Nunca toca la clave de OpenAI, la pausa, el freno ni el canario del motor.
# `canary` y `all` abren también la llave de publicar del Studio (fases 2 y 3 del contrato, §A5). Si el propietario
# quiere que el motor solo selle, sin publicaciones nuevas, tras aplicar el alcance pon a mano
# ULTRON_STUDIO_PUBLISHING_ENABLED=false y regenera la caché igual que aquí (N-9 de la revisión de seguridad).
set -euo pipefail
export LC_ALL=C
ALCANCE="${1:?uso: off|canary|all}"
case "$ALCANCE" in
  off)    PUBLICAR=false ;;
  canary) PUBLICAR=true ;;
  all)    PUBLICAR=true ;;
  *) echo "alcance no válido: $ALCANCE (off|canary|all)"; exit 2 ;;
esac
cd /var/www/api/backend
ENVF=.env
set_var() { # escribe la clave con la sangría de dos espacios del fichero (la añade si falta)
  if grep -Eq "^\s*$1=" "$ENVF"; then sed -i -E "s/^(\s*$1=).*/\1$2/" "$ENVF"; else printf '  %s=%s\n' "$1" "$2" >> "$ENVF"; fi
}
mkdir -p /var/backups/ironbody
RESPALDO="/var/backups/ironbody/env-pre-h5-scope-$ALCANCE-$(date +%Y%m%d-%H%M%S)"
install -m 600 -o root -g root "$ENVF" "$RESPALDO"
[ -z "$(tail -c1 "$ENVF")" ] || printf '\n' >> "$ENVF"   # B-1: nunca pegar una clave a una última línea sin salto final
set_var MARKETING_ULTRON_STUDIO_CONFIG "$ALCANCE"
set_var ULTRON_STUDIO_PUBLISHING_ENABLED "$PUBLICAR"
grep -Eq "^\s*ULTRON_STUDIO_PUBLISH_LAB_GATE=" "$ENVF" || set_var ULTRON_STUDIO_PUBLISH_LAB_GATE required
echo "1. llaves → motor=$ALCANCE publicar=$PUBLICAR (permisos $(stat -c '%U %a' $ENVF); copia previa en $RESPALDO)"
# B-1: el fichero tiene que parsear antes de tocar la caché (solo se imprime la clase del error).
sudo -u www-data php -r 'require "vendor/autoload.php"; try { Dotenv\Dotenv::createArrayBacked(".")->load(); } catch (Throwable $e) { fwrite(STDERR, get_class($e).PHP_EOL); exit(1); }' \
  || { echo "   el fichero de entorno NO parsea: restáuralo desde $RESPALDO"; exit 1; }
# N-7: la caché nace ya con 600 (umask 077), no 644 y chmod después.
sudo -u www-data sh -c 'umask 077; php artisan config:cache -q'
chmod 600 bootstrap/cache/config.php
echo "2. caché: $(stat -c '%U %a' bootstrap/cache/config.php)"
EFECTIVA=$(sudo -u www-data php artisan tinker --execute='echo json_encode(["engine_scope" => config("marketing.ultron.studio_config"), "publishing_enabled" => config("ultron_studio.publishing_enabled") === true, "lab_gate" => config("ultron_studio.publishing.lab_gate"), "canary_set" => config("marketing.ultron.canary_conversation_id") !== null, "ultron_enabled" => (bool) config("marketing.ultron.enabled")]), PHP_EOL;' 2>&1 | tail -1)
echo "   $EFECTIVA"
# N-8: lo escrito tiene que ser lo efectivo, o no se reinicia nada.
case "$EFECTIVA" in
  *"\"engine_scope\":\"$ALCANCE\""*"\"publishing_enabled\":$PUBLICAR"*) ;;
  *) echo "   LA CONFIGURACIÓN EFECTIVA NO ES motor=$ALCANCE publicar=$PUBLICAR: revisa el fichero de entorno (una clave repetida o con espacios gana a la escrita)"; exit 1 ;;
esac
sudo -u www-data php artisan queue:restart -q
supervisorctl restart 'ironbody-lab:*' > /dev/null
sleep 8
echo "3. workers RUNNING: $(supervisorctl status | grep -c RUNNING) · BACKOFF/FATAL: $(supervisorctl status | grep -c 'BACKOFF\|FATAL')"
echo "4. status sin token → $(curl -s -o /dev/null -w '%{http_code}' https://api.ironbodyneiva.cloud/api/admin/ultron-studio/status)"
echo "ALCANCE $ALCANCE APLICADO"

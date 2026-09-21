#!/usr/bin/env bash
#
# Despliegue de produccion, en el orden que importa.
#
# Existe porque la receta vivia en la memoria de quien desplegaba, y dos de sus
# pasos ya se olvidaron con consecuencias:
#
#   - `config:cache` escribe bootstrap/cache/config.php como root. Sin el
#     `chown www-data` posterior, el `chmod 600` deja el fichero ilegible para
#     los workers y los doce acaban en BACKOFF. Paso 3.
#   - La cache de RUTAS no se regeneraba nunca. Sobrevivio once horas a un
#     despliegue sin dano solo porque aquel commit no tocaba routes/; el
#     siguiente que anada una ruta daria un 404 que nadie sabria atribuir.
#     Paso 4.
#
# Se limpia en vez de cachear las rutas a proposito: una cache de rutas vieja
# es un fallo silencioso y una cache ausente solo cuesta unos milisegundos de
# arranque. Correccion antes que microoptimizacion.
#
# Uso:  ssh ironbody-vps 'bash /var/www/api/backend/scripts/deploy-produccion.sh'
set -euo pipefail

RAIZ="${RAIZ:-/var/www/api}"
BACKEND="$RAIZ/backend"
ESPERADOS="${SUPERVISOR_ESPERADOS:-12}"
# Artisan corre como www-data, como en todos los guiones de operacion de este
# repo. No es cosmetica: cualquiera de estos comandos arranca el framework, y si
# en esa ventana nace el fichero del dia de un canal `daily` queda root:root.
# El 0664 de config/logging.php no salva nada cuando el dueno es root: www-data
# entra como «otros», y «otros» no escribe. Eso es lo que hizo que el webhook de
# Meta contestara 500 en vez de 403, y esta escrito en ese mismo fichero.
RUN_AS="${RUN_AS:-www-data}"

paso() { printf '\n\033[1m== %s\033[0m\n' "$1"; }

# A partir del paso 5 el codigo nuevo YA esta sirviendo peticiones, asi que
# decir «aborta» ahi seria mentir: le diria al operador que no se desplego nada,
# y a las tres de la madrugada esa creencia falsa es la que decide lo siguiente
# que hace. Lo que falla en esos pasos es la VERIFICACION, no el despliegue.
#
# No hay vuelta atras automatica, y es deliberado: revertir produccion es una
# decision de persona. Aqui solo se dice la verdad y se deja el dato que hace
# falta para tomarla.
sin_verificar() {
  printf '\n\033[1;31m== DESPLEGADO PERO SIN VERIFICAR: %s\033[0m\n' "$1"
  echo "El codigo nuevo YA esta sirviendo. No se ha revertido nada."
  echo "Commit anterior, por si hay que volver: $ANTES"
  echo "La vuelta atras se hace a conciencia y con el runbook delante, no desde aqui."
  exit 1
}

paso "1. Codigo"
cd "$RAIZ"
ANTES="$(git rev-parse --short HEAD)"
sucio="$(git status --porcelain -uno)"
if [ -n "$sucio" ]; then
  echo "ABORTA: el arbol de produccion tiene cambios sin commitear:"; echo "$sucio"; exit 1
fi
git pull --ff-only
echo "HEAD: $ANTES -> $(git rev-parse --short HEAD)"

paso "2. Configuracion"
cd "$BACKEND"
sudo -u "$RUN_AS" php artisan config:clear
sudo -u "$RUN_AS" php artisan config:cache

paso "3. Permisos del config cacheado (el paso que ya se olvido una vez)"
# Con el paso 2 corriendo como www-data el fichero ya nace suyo y este chown
# sobra. Se queda como red por si alguien ejecuta los comandos a mano como root.
chown www-data:www-data bootstrap/cache/config.php
chmod 600 bootstrap/cache/config.php
ls -l bootstrap/cache/config.php

paso "4. Cache de rutas: fuera la vieja"
sudo -u "$RUN_AS" php artisan route:clear
rm -f bootstrap/cache/routes-v7.php

paso "5. Codigo vivo para las peticiones web"
# Los hijos de FPM fijan el codigo al arrancar y el master lleva dias vivo.
# Opcache revalida por timestamp, pero eso es una inferencia: un reload la
# convierte en un hecho y es gracioso (no corta conexiones).
systemctl reload php8.3-fpm
systemctl is-active php8.3-fpm

paso "6. Workers"
sudo -u "$RUN_AS" php artisan queue:restart
sleep 10
sup="$(supervisorctl status | grep -c RUNNING || echo 0)"
billing="$(systemctl is-active ironbody-billing-worker.service 2>/dev/null || true)"
echo "supervisor: $sup/$ESPERADOS RUNNING"
echo "systemd billing: $billing"
[ "$sup" -eq "$ESPERADOS" ] || sin_verificar "faltan workers de supervisor ($sup/$ESPERADOS)"
case "$billing" in active|activating) ;; *) sin_verificar "el worker de facturacion no esta vivo (${billing:-desconocido})" ;; esac

paso "7. Segunda lectura de los workers (un crash-loop no se ve en la primera)"
sleep 15
sup2="$(supervisorctl status | grep -c RUNNING || echo 0)"
echo "supervisor: $sup2/$ESPERADOS RUNNING"
[ "$sup2" -eq "$ESPERADOS" ] || sin_verificar "los workers no se sostienen ($sup2/$ESPERADOS)"

paso "8. Canarios"
# Son dos variables y nada en el codigo las contrasta. Ya divergieron una vez y
# el canario quedo inerte en silencio: la unica conversacion que podia cobrar
# era la unica a la que ULTRON no contestaba.
sudo -u "$RUN_AS" php artisan marketing:ai-doctor | tail -6
sudo -u "$RUN_AS" php artisan marketing:ai-doctor > /dev/null || sin_verificar "los canarios no cuadran"

paso "DESPLIEGUE OK"

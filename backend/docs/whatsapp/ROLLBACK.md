# Volver atrás — canal de WhatsApp

Procedimiento de reversión, de lo más barato a lo más drástico. Casi siempre
basta con el primer nivel.

## Nivel 0 — Apagar sin desplegar (segundos)

Todo lo nuevo está detrás de flags. **Ninguno de estos cambios requiere tocar
código ni reiniciar nada más que la caché.**

```bash
cd /var/www/api/backend

# El agente deja de razonar sobre lo que entra
MARKETING_INBOUND_AUTO_ANALYZE=false

# El canal deja de procesar entrantes (los eventos se siguen guardando)
MARKETING_INBOUND_META_ENABLED=false

# Hermes fuera; se vuelve a OpenAI directo
MARKETING_HERMES_ENABLED=false

# Deja de descargar adjuntos (los mensajes se siguen viendo)
WHATSAPP_MEDIA_DOWNLOAD_ENABLED=false

# IRON GUARD deja de abrir incidentes
IRON_GUARD_ENABLED=false

php artisan config:cache
```

Nada de esto pierde información: los eventos crudos siguen guardándose y se
pueden reprocesar después con `marketing:replay-webhooks`.

## Nivel 1 — Parar Hermes

```bash
cd /opt/hermes && docker compose down
```

Laravel degrada solo: el cortacircuitos abre tras tres fallos y el cerebro pasa
a OpenAI. No hace falta tocar el backend.

## Nivel 2 — Volver al código anterior

El commit previo a este trabajo es **`3c0dbf0`**.

```bash
cd /var/www/api/backend
git log --oneline -8              # localizar 3c0dbf0
git checkout 3c0dbf0 -- .         # sin reset --hard: no se descarta nada
composer install --no-dev --optimize-autoloader
php artisan optimize:clear
php artisan config:cache && php artisan route:cache
php artisan queue:restart
supervisorctl restart ironbody-queue-worker:ironbody-queue-worker_00
```

**Las migraciones NO hay que revertirlas.** Las cinco son aditivas: crean tablas
nuevas (`meta_webhook_events`, `marketing_message_attachments`,
`marketing_message_statuses`, `incidents`, `incident_events`) y añaden columnas
nullable a `marketing_messages`. El código viejo las ignora y sigue funcionando.

Revertirlas sería más arriesgado que dejarlas: `down()` borra tablas con datos.

## Nivel 3 — Frontend

```bash
# El respaldo del CRM publicado se hizo antes de sustituirlo
ls -lt /root/app-backups/ | head -3

rm -rf /var/www/app/*
tar -xzf /root/app-backups/app-20260806-050840.tar.gz -C /var/www/app
chown -R www-data:www-data /var/www/app
nginx -t && systemctl reload nginx
```

## Nivel 4 — Restaurar la base de datos (último recurso)

**Solo si hay corrupción de datos.** Restaurar pierde todo lo ocurrido desde el
backup: pagos, membresías, mensajes. Antes de esto, agotar los niveles anteriores.

```bash
ls -lt /root/db-backups/ | head -3
# ironbody-before-whatsapp-agent-20260806-050533.dump  (2,7 MB, 1475 objetos)

# Verificar el dump ANTES de destruir nada
pg_restore --list /root/db-backups/ironbody-before-whatsapp-agent-20260806-050533.dump | head

# Requiere parar la aplicación primero
supervisorctl stop ironbody-queue-worker:ironbody-queue-worker_00
systemctl stop ironbody-billing-worker.service
systemctl stop php8.3-fpm

sudo -u postgres pg_restore -d ironbody --clean --if-exists \
  /root/db-backups/ironbody-before-whatsapp-agent-20260806-050533.dump

systemctl start php8.3-fpm
systemctl start ironbody-billing-worker.service
supervisorctl start ironbody-queue-worker:ironbody-queue-worker_00
```

## Comprobar que se volvió bien

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://ironbodyneiva.cloud/
curl -s "https://api.ironbodyneiva.cloud/api/webhooks/meta?hub.mode=subscribe&hub.verify_token=<TOKEN>&hub.challenge=OK"
supervisorctl status
systemctl is-active ironbody-billing-worker.service nginx php8.3-fpm postgresql
tail -20 /var/www/api/backend/storage/logs/laravel.log
```

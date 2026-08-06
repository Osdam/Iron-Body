# Activar el número real de WhatsApp — paso a paso

Procedimiento para encender el canal con el número oficial de Iron Body Neiva
(+57 314 345 5483).

**Nada de esto se ha ejecutado.** El registro por OTP es irreversible sobre el
número productivo y requiere una decisión del propietario.

Léelo entero antes de empezar. Cada fase tiene una comprobación que hay que
superar antes de pasar a la siguiente; si una falla, se para y se vuelve atrás.

---

## Fase 0 — Antes de tocar nada

```bash
ssh root@2.25.167.216

# 1. Respaldo fresco de la base
sudo -u postgres pg_dump -Fc ironbody -f /tmp/ironbody-antes-de-meta-$(date +%Y%m%d-%H%M%S).dump
mkdir -p /root/db-backups && mv /tmp/ironbody-antes-de-meta-*.dump /root/db-backups/
chmod 600 /root/db-backups/ironbody-antes-de-meta-*.dump

# 2. Respaldo del .env
cd /var/www/api/backend
cp -a .env /root/env-backups/.env.antes-de-activar-meta-$(date +%Y%m%d_%H%M%S)
chmod 600 /root/env-backups/.env.antes-de-activar-meta-*

# 3. El canal tiene que estar sano ANTES de encenderlo
php artisan iron-guard:scan --force        # debe decir: sin incidentes
php artisan security:check-secret-exposure # debe decir: nada expuesto
supervisorctl status                       # RUNNING
systemctl is-active ironbody-billing-worker.service php8.3-fpm nginx postgresql
```

**No sigas si alguna comprobación falla.**

---

## Fase 1 — Registrar el número en Meta (paso manual del propietario)

Esto ocurre **en el panel de Meta**, no en el servidor. Es el único paso que
nadie más puede dar por ti, y es irreversible en la práctica: una vez el número
queda en Cloud API, deja de funcionar en la app de WhatsApp Business.

1. Entra en <https://business.facebook.com> → **Configuración del negocio** →
   **Cuentas de WhatsApp** → la WABA de Iron Body.
2. **Números de teléfono** → localiza `+57 314 345 5483`.
3. Si el número está hoy en la app de WhatsApp Business, primero hay que
   **darlo de baja allí** (Ajustes → Cuenta → Eliminar cuenta). Sin esto, Meta
   rechaza el registro.
4. En el panel, **Agregar número de teléfono** / **Registrar**.
5. Meta envía un **código OTP** por SMS o llamada a ese número. Ten el teléfono
   a mano: el código caduca en minutos.
6. Introduce el OTP y define el **PIN de verificación en dos pasos** de seis
   dígitos. **Anótalo en el gestor de contraseñas del negocio**: hará falta para
   cualquier migración futura y Meta no lo muestra otra vez.
7. Confirma que el número aparece como **Conectado** y con calidad **Verde**.

Anota el `phone_number_id` que muestra el panel: tiene que coincidir con el que
ya está configurado en el servidor. Si no coincide, **para aquí** y avísalo.

---

## Fase 2 — Suscribir el webhook

En el panel de Meta, en la app → **WhatsApp** → **Configuración** → **Webhook**:

- URL de devolución: `https://api.ironbodyneiva.cloud/api/webhooks/meta`
- Token de verificación: el valor de `META_VERIFY_TOKEN` del `.env`
- Campos suscritos: **`messages`** (obligatorio). Con eso llegan mensajes y
  estados de entrega.

Meta hará una llamada GET de verificación. Debe responder 200. Compruébalo:

```bash
tail -f /var/www/api/backend/storage/logs/channel-$(date +%F).log
```

Tienes que ver una línea `meta.webhook.received`. Si ves
`meta.webhook.rejected` con `invalid_signature`, el `META_WEBHOOK_SECRET` no
coincide con el App Secret de la app.

---

## Fase 3 — Encender el canal en modo escucha

Todavía **sin** agente automático. Solo queremos ver entrar mensajes.

```bash
cd /var/www/api/backend
```

Variables a poner (sin valores aquí; los reales ya están en el servidor):

| Variable | Valor | Por qué |
|---|---|---|
| `META_ENABLED` | `true` | Permite hablar con Graph API |
| `MARKETING_INBOUND_META_ENABLED` | `true` | Procesa los entrantes |
| `MARKETING_INBOUND_AUTO_ANALYZE` | `false` | **El agente todavía no opina** |
| `MARKETING_INBOUND_AUTO_EXECUTE` | `false` | No ejecuta herramientas |
| `MARKETING_AGENT_ENABLED` | `false` | Segundo cerrojo del agente |
| `MARKETING_HERMES_ENABLED` | `false` | Hermes sigue fuera (ver COMPARATIVA.md) |
| `IRON_GUARD_ENABLED` | `true` | **Enciéndelo aquí**: quieres vigilancia desde el primer mensaje |

```bash
php artisan config:cache
php artisan queue:restart
supervisorctl restart ironbody-queue-worker:ironbody-queue-worker_00
```

### Primer mensaje controlado

Desde **tu propio móvil**, escribe al número del gimnasio: `hola prueba 1`.

```bash
# 1. Llegó el evento crudo
php artisan tinker --execute='$e=\App\Models\MetaWebhookEvent::latest("id")->first();
  echo "evento #{$e->id} estado={$e->status} mensajes={$e->messages_count}\n";'

# 2. Se convirtió en mensaje
php artisan tinker --execute='$m=\App\Models\MarketingMessage::latest("id")->first();
  echo "mensaje #{$m->id} dir={$m->direction} cuerpo={$m->body}\n";'

# 3. El hilo completo en el log
grep "$(php artisan tinker --execute='echo \App\Models\MetaWebhookEvent::latest("id")->first()->correlation_id;' | tail -1)" \
  storage/logs/channel-$(date +%F).log
```

**Debe verse:** `meta.webhook.received` → `meta.webhook.queued` →
`meta.event.started` → `meta.message.saved`, todas con el mismo
`correlation_id`.

Abre el CRM y comprueba que la conversación aparece en el inbox con tu mensaje.

### Prueba de multimedia

Manda desde el móvil, en este orden: **una foto con pie de foto**, **una nota de
voz**, **un PDF**.

```bash
php artisan tinker --execute='foreach(\App\Models\MarketingMessageAttachment::latest("id")->take(5)->get() as $a)
  echo "{$a->kind} {$a->status} {$a->detected_mime_type} {$a->size_bytes}b motivo={$a->failure_reason}\n";'
```

Los tres deben quedar en `stored`. Si alguno queda en `failed`, revisa el token
de Meta antes de seguir. En el inbox tienen que verse: la foto, un reproductor
de audio y el documento descargable.

---

## Fase 4 — Respuesta manual (sin IA)

Desde el CRM, contesta a mano a tu propio mensaje.

```bash
php artisan tinker --execute='$m=\App\Models\MarketingMessage::where("direction","outbound")->latest("id")->first();
  echo "estado={$m->status} meta_id={$m->meta_message_id} intentos={$m->send_attempts}\n";'
```

Debe quedar en `sent` y, en segundos, pasar a `delivered` y luego `read` cuando
lo abras en el móvil:

```bash
php artisan tinker --execute='foreach(\App\Models\MarketingMessageStatus::latest("id")->take(5)->get() as $s)
  echo "{$s->status} aplicado=".($s->applied?"si":"no")." error={$s->error_code}\n";'
```

**Si el mensaje no llega al móvil, para aquí.** Sin envío manual fiable no tiene
sentido encender el agente.

---

## Fase 5 — Canary del agente

Solo cuando las fases 3 y 4 hayan ido bien y hayan pasado **al menos 24 horas**
de tráfico real recibido sin incidentes.

```bash
MARKETING_INBOUND_AUTO_ANALYZE=true    # el agente ANALIZA pero no ejecuta
# MARKETING_INBOUND_AUTO_EXECUTE sigue en false
# MARKETING_AGENT_ENABLED sigue en false

php artisan config:cache
```

En este estado el agente decide y **registra** su decisión, pero no envía nada.
Durante uno o dos días, revisa en el CRM lo que HABRÍA contestado:

```bash
php artisan tinker --execute='foreach(\App\Models\MarketingAiAction::latest("id")->take(20)->get() as $a)
  echo "{$a->action_type} {$a->status} {$a->reason}\n";'
```

Busca: ¿inventó precios? ¿ofreció algo no autorizado? ¿escaló cuando debía?

Solo si las decisiones son buenas:

```bash
MARKETING_AGENT_ENABLED=true
MARKETING_INBOUND_AUTO_EXECUTE=true
php artisan config:cache
```

Vigila la primera hora **en directo**:

```bash
tail -f storage/logs/channel-$(date +%F).log | grep -E "outbox|message.routed"
watch -n 30 'php artisan iron-guard:scan --force'
```

---

## Rollback inmediato

Si algo va mal en cualquier fase, **el apagado no requiere desplegar**:

```bash
cd /var/www/api/backend

# Nivel 1 — callar al agente, seguir recibiendo (5 segundos)
sed -i 's/^MARKETING_INBOUND_AUTO_EXECUTE=.*/MARKETING_INBOUND_AUTO_EXECUTE=false/' .env
sed -i 's/^MARKETING_AGENT_ENABLED=.*/MARKETING_AGENT_ENABLED=false/' .env
php artisan config:cache

# Nivel 2 — cortar el canal entero
sed -i 's/^META_ENABLED=.*/META_ENABLED=false/' .env
php artisan config:cache
```

Con `META_ENABLED=false` los mensajes **se siguen guardando** (el evento crudo
se persiste igual): no se pierde ningún prospecto, solo deja de salir nada. Se
reprocesan después con `php artisan marketing:replay-webhooks --include-dead`.

Para volver al código anterior o restaurar la base, ver `ROLLBACK.md`.

---

## Cuándo hay que apagar `META_ENABLED` sin discutirlo

Apaga primero y analiza después si ocurre cualquiera de estas:

1. **El agente manda algo que no debía** a un cliente real: un precio inventado,
   una promoción inexistente, una promesa médica.
2. **Dos respuestas al mismo mensaje.** Indica que la protección de doble envío
   falló, y es lo que peor se ve desde fuera.
3. **Meta marca la calidad del número en Rojo** o llega un aviso de restricción.
   Seguir enviando puede costar el número.
4. **Errores 131031** (cuenta restringida) o un pico de **130429** (límite de
   envíos) en IRON GUARD.
5. **Mensajes a números que pidieron no ser contactados.**
6. **Incidente `events_stuck` en crítico** con mensajes esperando: hay gente sin
   respuesta y encima el agente sigue trabajando sobre datos incompletos.
7. **Coste de OpenAI disparado** sin un aumento de conversaciones que lo explique.
8. Cualquier duda razonable. El canal apagado no pierde mensajes; encendido y
   descontrolado, sí pierde clientes.

```bash
# El botón rojo
cd /var/www/api/backend \
  && sed -i 's/^META_ENABLED=.*/META_ENABLED=false/' .env \
  && php artisan config:cache \
  && echo "canal apagado"
```

---

## Checklist final

| # | Paso | Comprobación | ✓ |
|---|---|---|---|
| 0 | Respaldo de base y `.env` | dump verificado con `pg_restore --list` | ☐ |
| 0 | Canal sano | `iron-guard:scan` sin incidentes | ☐ |
| 1 | Número dado de baja en la app WhatsApp Business | ya no aparece en el móvil | ☐ |
| 1 | Registro por OTP en Cloud API | número **Conectado**, calidad Verde | ☐ |
| 1 | PIN de dos pasos guardado | en el gestor de contraseñas | ☐ |
| 1 | `phone_number_id` coincide con el `.env` | comparado a mano | ☐ |
| 2 | Webhook suscrito a `messages` | GET de verificación con 200 | ☐ |
| 3 | `META_ENABLED=true`, agente en false | `config:cache` hecho | ☐ |
| 3 | `IRON_GUARD_ENABLED=true` | scan responde | ☐ |
| 3 | Primer mensaje de prueba | evento `processed` + visible en el inbox | ☐ |
| 3 | Foto, audio y PDF | los tres en `stored` y visibles | ☐ |
| 4 | Respuesta manual | llega al móvil, pasa a `read` | ☐ |
| 5 | 24 h de tráfico sin incidentes | `iron-guard:scan` limpio | ☐ |
| 5 | Canary: `AUTO_ANALYZE=true` | decisiones revisadas a mano | ☐ |
| 5 | Agente completo | primera hora vigilada en directo | ☐ |

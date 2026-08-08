# Activación del número real en Meta — checklist

**Nada de este documento se ha ejecutado.** Es el único trabajo que queda entre
el estado actual y tener el canal en producción, y es administrativo: exige
acceso al Business Manager de Iron Body y recibir un código por el número real.

Antes de empezar, el estado actual: todo construido, probado y desplegado con
las funciones peligrosas apagadas. El sistema no puede mandar un mensaje aunque
alguien lo intente, porque `META_ENABLED=false` corta antes de la red.

---

## 0. Antes de tocar nada

- [ ] Confirmar que el número **+57 314 345 5483** no está dado de alta en la
      app de WhatsApp Business ni en WhatsApp normal. Si lo está, hay que
      borrarlo de ahí primero: un número no puede estar en los dos sitios.
- [ ] Avisar al equipo. Durante la migración, el número deja de recibir
      mensajes por la app y pasa a llegar solo al CRM.
- [ ] Hacer copia de seguridad de la base de datos.

## 0.b Respaldo, antes de nada más

**Esto bloquea el go-live, no la preparación.** A fecha de la auditoría F.13 no
hay automatización: `crontab` no tiene ninguna entrada de respaldo, no hay
temporizador de systemd, `archive_mode` de PostgreSQL está en `off`, y el volcado
completo más reciente es manual y del 1 de julio de 2026.

Mientras Meta está apagado eso es un riesgo acotado: los datos cambian despacio.
En el momento en que el número se registra empiezan a entrar conversaciones de
clientes, que son irremplazables —no se pueden volver a pedir—. Un volcado de
hace cinco semanas significa perder cinco semanas de conversaciones y pagos.

Antes del OTP tiene que existir:

- volcado de PostgreSQL automatizado (cron o timer), comprimido;
- retención declarada y permisos `0600` en el destino;
- **una restauración probada** sobre una base desechable — un respaldo que nunca
  se restauró es una suposición, no un respaldo;
- aviso cuando el respaldo falle (un respaldo silencioso que lleva un mes roto es
  el escenario habitual).

Y justo antes del OTP, un snapshot manual de: base de datos, `backend/`,
`frontend/dist`, `/etc/nginx`, `/etc/supervisor/conf.d`,
`/etc/systemd/system/ironbody-*`, y el inventario de variables de entorno **por
nombre, sin valores**.

## 0.c Rotación de credenciales

En `/root/backups/` hay un volcado de entorno (`env-before-guard-*`) y en la
máquina de desarrollo un `backend/.env.backup-*`. Ninguno se ha versionado nunca
—el histórico de git está limpio— y los dos están a `0600`, así que no hay fuga
demostrada. Pero son copias de credenciales fuera del gestor de secretos.

Antes de registrar el número, rotar y volver a desplegar:

- `APP_KEY` no: rotarla invalida sesiones y datos cifrados. Se deja constancia.
- Token de acceso de Meta y App Secret: se generan nuevos en la activación, así
  que la rotación es implícita.
- `OPENAI_API_KEY`, llaves de Wompi (`private`, `integrity`, `events`),
  credenciales de Factus, contraseña de PostgreSQL y SMTP: **rotar**.
- Tras rotar, borrar las copias sueltas y añadir `.env.backup-*` a `.gitignore`.

## 1. Registro administrativo

- [ ] Entrar al Business Manager con la cuenta de Iron Body.
- [ ] WhatsApp → Configuración de la API → **Agregar número de teléfono**.
- [ ] Elegir verificación por **SMS** o **llamada**. Hay que tener el teléfono
      a mano: el código caduca en minutos.

## 2. OTP

- [ ] Introducir el código recibido.
- [ ] Confirmar que el número aparece como **Conectado**.

> Este paso es irreversible en la práctica: el número queda ligado a la Cloud
> API y vuelve a la app de WhatsApp Business solo borrándolo aquí.

## 3. Credenciales

- [ ] Copiar el **phone_number_id** nuevo. El que hay configurado hoy es de
      pruebas y hay que sustituirlo.
- [ ] Copiar el **WABA ID**.
- [ ] Generar un **token permanente** de sistema, no el temporal de 24 h.
- [ ] Copiar el **App Secret**.

En el servidor, en `/var/www/api/backend/.env`:

```
META_WHATSAPP_PHONE_NUMBER_ID=<nuevo>
META_WHATSAPP_BUSINESS_ACCOUNT_ID=<WABA ID nuevo>
META_ACCESS_TOKEN=<token permanente>
META_APP_SECRET=<app secret>
META_VERIFY_TOKEN=<cadena larga inventada, la misma que en el paso 4>
META_WEBHOOK_SECRET=<opcional: si se omite, se usa META_APP_SECRET>
```

> La variable del WABA es `META_WHATSAPP_BUSINESS_ACCOUNT_ID`. Este documento
> decía `META_WABA_ID`, que **no la lee nadie** (`config/meta.php` sólo mira la
> primera): escribirla habría dejado el WABA anterior en uso y el fallo no se
> vería hasta intentar operar sobre la cuenta.

Después: `php artisan config:clear && php artisan config:cache`.

**Con `META_ENABLED` todavía en `false`.** Las credenciales pueden estar puestas
sin que el canal envíe nada; eso permite comprobar la configuración antes de
abrir la puerta.

## 4. Webhook

- [ ] URL: `https://api.ironbodyneiva.cloud/api/webhooks/meta`
- [ ] Verify token: el mismo que `META_VERIFY_TOKEN`.
- [ ] Suscribir los campos: `messages`, `message_template_status_update`.
- [ ] Comprobar que Meta marca la verificación como correcta.

> La URL es `/api/webhooks/meta`. Este documento decía `/api/meta/webhook`, que
> **no existe** (`routes/api.php` registra `webhooks/meta` en GET y POST, y es la
> que imprime `php artisan meta:doctor`). Con la URL invertida, la verificación
> de Meta falla con 404 y no llega ni un solo mensaje entrante.

## 5. Comprobación antes de encender

```bash
php artisan tinker --execute='print_r(app(App\Services\Meta\MetaDoctorService::class)->check());'
```

- [ ] Credenciales presentes.
- [ ] `phone_number_id` responde en Graph.
- [ ] Webhook verificado.

## 6. Prueba de entrada, con el canal apagado

- [ ] Escribir al número desde un teléfono cualquiera.
- [ ] El mensaje debe aparecer en el Inbox del CRM.
- [ ] **No debe salir ninguna respuesta**: con `META_ENABLED=false` el sistema
      registra y no entrega.

Si el mensaje entra y no sale nada, el canal está bien montado y el freno
funciona. Ese es exactamente el estado que se quiere antes de encender.

## 7. Encender el canal

```
META_ENABLED=true
```

- [ ] `php artisan config:cache`
- [ ] Responder **a mano** desde el Inbox a la conversación de prueba.
- [ ] Comprobar que llega al teléfono.
- [ ] Comprobar que los estados —enviado, entregado, leído— vuelven al CRM.

## 8. Multimedia

- [ ] Enviar una imagen desde el compositor.
- [ ] Enviar un documento.
- [ ] Grabar y enviar una nota de voz.
- [ ] Recibir una imagen y una nota de voz desde el teléfono.

> Las notas de voz necesitan `ffmpeg` en el servidor. Ya está instalado y
> verificado; si el botón del micrófono no aparece, es que dejó de estarlo.

## 9. Modo observación

El agente sigue apagado. Durante unos días:

- [ ] El equipo atiende a mano desde el Inbox.
- [ ] Se revisa el Centro de supervisión: decisiones, alertas, incidentes.
- [ ] Se comprueba que la atribución de las pautas llega bien.

Esto no es burocracia: es la única forma de ver qué habría contestado el agente
antes de dejarle contestar.

## 10. Canary

- [ ] `MARKETING_AGENT_ENABLED=true` con `COMMERCIAL_AUTONOMY_ENABLED=false`.
- [ ] El agente redacta y **propone**; una persona aprueba y envía.
- [ ] Revisar cada respuesta durante los primeros días.

## 11. Autonomía gradual

Solo después de que el canary demuestre que las respuestas son buenas:

- [ ] `COMMERCIAL_AUTONOMY_ENABLED=true`.
- [ ] Vigilar la cola de aprobaciones: nada que mueva dinero se ejecuta solo.
- [ ] Mantener `IRON_GUARD_AUTO_REMEDIATION=false`.

## 12. Vuelta atrás

En cualquier punto, y sin desplegar nada:

```
META_ENABLED=false          # deja de salir cualquier mensaje
MARKETING_AGENT_ENABLED=false   # el agente deja de decidir
COMMERCIAL_AUTONOMY_ENABLED=false  # ninguna herramienta con efecto
```

`php artisan config:cache` y listo. Los mensajes siguen entrando y quedando en
el Inbox; solo se corta la salida automática.

Para volver atrás el frontend: `git checkout <commit anterior>` en el
submódulo, `npm run build:prod` y `rsync` a `/var/www/app`. El sello de versión
en `/version.json` dice siempre qué está sirviéndose.

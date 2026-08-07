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
META_WABA_ID=<nuevo>
META_ACCESS_TOKEN=<token permanente>
META_APP_SECRET=<app secret>
META_VERIFY_TOKEN=<cadena larga inventada, la misma que en el paso 4>
```

Después: `php artisan config:clear && php artisan config:cache`.

**Con `META_ENABLED` todavía en `false`.** Las credenciales pueden estar puestas
sin que el canal envíe nada; eso permite comprobar la configuración antes de
abrir la puerta.

## 4. Webhook

- [ ] URL: `https://api.ironbodyneiva.cloud/api/meta/webhook`
- [ ] Verify token: el mismo que `META_VERIFY_TOKEN`.
- [ ] Suscribir los campos: `messages`, `message_template_status_update`.
- [ ] Comprobar que Meta marca la verificación como correcta.

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

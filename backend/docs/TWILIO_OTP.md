# Twilio OTP — verificación por SMS en producción (Bloque 7)

La lógica de OTP vive 100% en el backend (`OtpService`): código **hasheado**
(`Hash::make`), **vigencia** (`OTP_TTL_SECONDS`), **intentos** y **reenvíos**
acotados con **cooldown**, un solo reto pendiente por miembro y logs sin datos
sensibles (el código nunca se loguea). El canal de envío es pluggable.

## Drivers
- `dev` — NO envía SMS; registra el código en el log y (si `OTP_EXPOSE_CODE=true`)
  lo devuelve en la respuesta. **Solo desarrollo.**
- `twilio` — SMS real vía Twilio Messages API.
- `labsmobile` — SMS real vía LabsMobile.

### Garantías de producción (ya forzadas en código)
- `SmsSenderFactory` **lanza excepción** si en producción el driver no es
  `twilio`/`labsmobile` (falla cerrado: nunca queda un OTP "de mentira").
- `OtpService::exposeCode()` devuelve **false** en producción aunque la config
  diga lo contrario (el código jamás viaja en la respuesta).

## Activar Twilio (intervención manual — NO commitear secretos)

1. Crear cuenta en [Twilio](https://www.twilio.com/) y comprar/usar un número con
   capacidad de **SMS hacia Colombia (+57)** (o un Messaging Service).
2. En el `.env` del **servidor** (no en el repo):
   ```env
   APP_ENV=production
   OTP_DRIVER=twilio
   OTP_EXPOSE_CODE=false
   TWILIO_SID=ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
   TWILIO_TOKEN=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
   TWILIO_FROM=+1XXXXXXXXXX        # tu número Twilio (formato E.164)
   ```
3. `php artisan config:clear`.

Si las credenciales están incompletas, `TwilioSmsSender` registra un warning y no
envía (el reto sigue creado; el usuario puede reenviar). El número destino se
normaliza a E.164 con prefijo Colombia (+57) cuando aplica.

## Validar un número de Colombia
- Los celulares colombianos son de 10 dígitos (3xxxxxxxxx). El backend antepone
  `+57` si no viene en formato internacional.
- En cuentas Twilio trial solo se puede enviar a números **verificados** en la
  consola de Twilio. Para producción, salir del modo trial.

## Probar
- **Local (sin SMS):** `OTP_DRIVER=dev`, `OTP_EXPOSE_CODE=true` → el código aparece
  en la respuesta (`dev_code`) y en el log. La app lo muestra solo en dev.
- **Staging con Twilio:** `OTP_DRIVER=twilio`, número de prueba verificado.
- **Tests automáticos:** `php artisan test --filter Otp` (driver dev/fake; valida
  que producción NO permite `dev`).

## Troubleshooting
| Síntoma | Causa probable | Acción |
|---------|----------------|--------|
| No llega el SMS | Cuenta trial / número no verificado | Verificar número o salir de trial |
| `credenciales incompletas` en logs | Falta `TWILIO_SID/TOKEN/FROM` | Rellenar `.env` + `config:clear` |
| Excepción al arrancar OTP en prod | `OTP_DRIVER=dev` en producción | Cambiar a `twilio` (guard de seguridad) |
| Error 21608 de Twilio | Número destino no verificado (trial) | Verificar en consola Twilio |

## Variables (`.env.example`)
`OTP_DRIVER`, `OTP_CODE_LENGTH`, `OTP_TTL_SECONDS`, `OTP_MAX_ATTEMPTS`,
`OTP_MAX_RESENDS`, `OTP_RESEND_COOLDOWN`, `OTP_EXPOSE_CODE`, `OTP_SKIP_WHEN_NO_PHONE`,
`TWILIO_SID`, `TWILIO_TOKEN`, `TWILIO_FROM`, `TWILIO_BASE`.

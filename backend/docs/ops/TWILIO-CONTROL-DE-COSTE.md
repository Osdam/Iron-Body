# Control de coste de Twilio · runbook

Qué hacer cuando el gasto en SMS se dispara, qué palancas existen y cómo se
revierte cada una. Nada aquí es aspiracional: todo lo que se afirma se midió
contra producción o está cubierto por `tests/Feature/Otp/TwilioCostControlTest.php`.

Origen: auditoría del 12 de septiembre de 2026. Base: `4f86904`.

---

## 1. Cómo factura Twilio (y por qué el panel enseña dos cifras)

Verify cobra **dos cosas distintas por el mismo evento**:

| Categoría en Usage Records | Qué cuenta | Precio en Colombia |
|---|---|---|
| `authy-sms-outbound` (= `sms`, `sms-outbound`, `sms-outbound-longcode`) | cada SMS enviado | 0,0592 USD |
| `authy-phone-verifications` | cada verificación **completada** | 0,0500 USD |

Por eso el panel muestra, por ejemplo, 498 y 348: no son dos tráficos. Son los
SMS enviados y, de esos, los que acabaron en un login bueno. **La diferencia son
los códigos que se pagaron y nadie usó.**

`Messages.json` devuelve 0: no existe tráfico por Programmable Messaging. El
único emisor es `TwilioVerifyService`.

---

## 2. Las palancas, de más rápida a más profunda

### 2.1 Interruptor de emergencia (kill switch)

```
TWILIO_SEND_ENABLED=false
php artisan config:cache
```

- **Corta**: todo envío nuevo — `members/login`, `resend`, cambio de número,
  borrado de cuenta, acceso profesional.
- **NO corta**: comprobar un código ya enviado, las sesiones vivas, ni nada que
  no pase por Twilio. Quien tiene el SMS en la mano sigue pudiendo entrar.
- **Respuesta al cliente**: `503` con `{"ok":false,"code":"kill_switch","retry_after":N}`.
  La app publicada lo muestra como mensaje en línea; no necesita versión nueva.
- **Revertir**: `TWILIO_SEND_ENABLED=true` + `config:cache`. Efecto inmediato.

### 2.2 Techo de gasto diario

```
TWILIO_DAILY_SOFT_LIMIT_USD=6
TWILIO_DAILY_HARD_LIMIT_USD=12
```

El contador vive en la cache compartida (`database` en producción), así que
sobrevive a reinicio, despliegue y varios workers. Se guarda en diezmilésimas de
dólar como entero para que sumar 0,0592 cientos de veces no derive.

- **Blando**: escribe `otp.cost_guard.soft_limit` en el log **una sola vez al
  día** y no corta nada.
- **Duro**: corta los envíos nuevos igual que el interruptor, con
  `code: temporarily_unavailable`.
- Es una **estimación** para decidir en caliente. La cifra real es siempre la de
  los Usage Records de Twilio.

Consultar el gasto estimado de hoy:

```php
app(\App\Services\Otp\OtpPolicy::class)->costSnapshot()
```

Calibración: el peor día auditado costó 9,99 USD *antes* de las protecciones; ese
mismo día con reuso y login adaptativo queda en unos 5,6 USD. De ahí 6 y 12.

### 2.3 Cupos por teléfono, cuenta e IP

```
OTP_PHONE_WINDOW_LIMIT=3     OTP_PHONE_DAILY_LIMIT=8      # llave FUERTE
OTP_ACCOUNT_WINDOW_LIMIT=4   OTP_ACCOUNT_DAILY_LIMIT=10   # red de apoyo
OTP_IP_WINDOW_LIMIT=40       OTP_IP_DAILY_LIMIT=200       # señal SECUNDARIA
OTP_START_COOLDOWN=60
```

**La IP va holgada a propósito.** En producción hay una sola IP que da servicio a
24 socios legítimos (la recepción del gimnasio). Endurecerla dejaría fuera a
gente que no ha hecho nada. El teléfono es lo que Twilio cobra, y es la llave que
manda.

Del tráfico real: el 71,5 % de los pares (socio, día) pide **un** código y el
89,1 % pide dos o menos. Un tope de 8/24 h habría tocado 4 envíos de 528.

### 2.4 Reuso de verificación viva

No tiene configuración: si ya hay un código vigente para ese socio, ese propósito
y ese mismo teléfono, se devuelve el reto existente y **no se llama a Twilio**.
Es la protección que más ahorra de las que dependen del código (24,4 %).

Va atado al socio, no sólo al teléfono: si dos socios comparten número, uno no
puede montarse en la verificación del otro.

### 2.5 Login adaptativo

```
SECURITY_ADAPTIVE_LOGIN=true
```

Es la palanca de mayor impacto (−34,3 %) y **la única con efecto sobre la postura
de seguridad**: en un equipo ya vinculado al socio y sin señales de riesgo, la
posesión se demuestra con vínculo dispositivo↔socio + biometría local del
teléfono + reautenticación por OTP cada 30 días, en vez de un SMS en cada entrada.

Condiciones que fuerzan OTP igualmente (ver `AccountRiskService::loginTier()`):

- el equipo no está vinculado a ese socio;
- cualquier señal adversarial en la última hora (umbral local = 1);
- han pasado más de `SECURITY_TRUSTED_REAUTH_DAYS` (30) desde el último OTP;
- la app pide OTP explícitamente (`prefer_otp`).

La app publicada 2.0.4 ya lo soporta y **cae limpiamente a OTP** si no hay
biometría, si falla o si el ticket caduca (`login_screen.dart:247`).

**Revertir**: `SECURITY_ADAPTIVE_LOGIN=false` + `config:cache`. Las otras
protecciones siguen en pie: esta bandera se apaga sola, sin desmontar nada más.

---

## 3. Qué mirar cuando algo va mal

Todos los eventos salen al log con `otp.event` y contexto estructurado. Nunca
llevan el código, ni el teléfono en claro, ni la IP en claro, ni credenciales.

```bash
grep '"event":"otp.provider_called"' storage/logs/laravel.log | wc -l   # SMS de hoy
grep '"event":"otp.reused"'          storage/logs/laravel.log | wc -l   # SMS ahorrados
grep '"event":"otp.rate_limited"'    storage/logs/laravel.log | wc -l   # cortes por cupo
grep 'otp.cost_guard'                storage/logs/laravel.log           # avisos de gasto
```

Eventos: `otp.requested`, `otp.provider_called`, `otp.reused`, `otp.rate_limited`,
`otp.cost_guard_blocked`, `otp.provider_failed`, `otp.verified`, `otp.invalid`.

**Ratio que importa**: `provider_called / requested`. Cuanto más baje, mejor
funciona el reuso. Si sube hacia 1, algo dejó de reutilizar.

---

## 4. Canario tras activar el login adaptativo

Durante las primeras 24 h, comparar contra el día anterior:

| Señal | Dónde | Qué sería malo |
|---|---|---|
| OTP iniciados por hora | `otp.requested` | sin cambio (la bandera no está aplicando) |
| SMS por hora | `otp.provider_called` | sin bajar |
| Logins completados | `otp.verified` + sesiones nuevas | **caída material** |
| Caídas a OTP | `POST members/login` con `prefer_otp` | subida fuerte (la biometría falla) |

**Si los logins completados caen de forma material: apagar
`SECURITY_ADAPTIVE_LOGIN` y nada más.** El resto de protecciones no tiene que ver
con eso y quitarlas devolvería el problema de coste.

---

## 5. Lo que NO se hizo, y por qué

- **Enumeración de cédulas.** `members/login` responde 404 «Documento no
  encontrado» y eso permite comprobar si una cédula existe. No se corrigió
  porque la app publicada 2.0.4 distingue ese 404 para mostrar el mensaje en
  línea (`login_screen.dart:210`): cambiarlo dejaría al socio que se equivoca
  tecleando sin saber qué pasó. Queda como mejora que requiere versión móvil.
- **Fraud Guard y Geo Permissions** no son consultables por API y se revisan en
  la consola de Twilio.
- **Migrar de Verify a Messaging directo** ahorraría los 0,05 USD por
  verificación, pero obligaría a generar, cifrar, guardar y validar el código
  nosotros y a perder las protecciones del proveedor. El desperdicio no estaba en
  el precio unitario.

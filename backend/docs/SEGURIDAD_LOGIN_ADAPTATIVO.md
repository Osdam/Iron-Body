# Login adaptativo — dispositivo principal sin 2FA tedioso

Objetivo: que el dispositivo **principal/verificado** no pida OTP + reconocimiento
facial en cada ingreso, sin sacrificar la seguridad contra robo, suplantación o
préstamo de cuenta.

La pila completa **ya está implementada** (backend Laravel + app Flutter). Solo
hay que **activarla** en el `.env` del servidor; el código no cambia.

## Cómo se comporta

Controlado por `SECURITY_ADAPTIVE_LOGIN`:

| Estado | Comportamiento de login |
|--------|-------------------------|
| `false` (default) | Clásico: **OTP + cara siempre**, en todos los equipos. |
| `true` | Adaptativo por confianza del equipo + puntaje de riesgo (abajo). |

Con `SECURITY_ADAPTIVE_LOGIN=true`, el backend decide el "tier" en cada login
(`AccountRiskService::loginTier`):

| Situación | Tier | Qué pide el usuario |
|-----------|------|---------------------|
| Equipo **no vinculado** (nuevo) | `otp_face` | OTP por SMS **+** match facial. Vincula el equipo. |
| Vinculado + riesgo **alto** (≥ `SECURITY_WARN_THRESHOLD`) | `otp_face` | OTP + cara (step-up por riesgo). |
| Vinculado + riesgo **medio** (≥ `SECURITY_LOCAL_THRESHOLD`) | `otp` | Solo OTP por SMS. |
| Vinculado + riesgo **bajo** (< `SECURITY_LOCAL_THRESHOLD`) | `local` | **Desbloqueo local** (Face ID/huella del equipo), sin SMS ni match facial. |

Un equipo es "confiable" cuando ya completó un login fuerte antes y quedó
vinculado en `member_device_bindings` (vínculo equipo↔titular).

### Experiencia del dispositivo principal
1. **Primer ingreso** en el equipo: OTP + cara → vincula el equipo.
2. **Ingresos siguientes** (riesgo bajo): solo **Face ID/huella local** → entra.
   No llega SMS ni se pide foto del rostro.
3. Si el equipo acumula señales de riesgo (OTP fallidos, intentos concurrentes,
   fallos faciales, equipo nuevo), el login **sube de nivel** automáticamente.

### Fallbacks (el usuario nunca queda atrapado)
- Sin biometría local disponible, o si el usuario la cancela/falla → cae a **OTP**.
- Ticket de desbloqueo vencido o equipo ya no confiable (410/409) → cae a **OTP**.
- Acciones sensibles (eliminar cuenta, revocar/desvincular dispositivos) → siguen
  exigiendo **2FA** siempre, sin importar este flag.

## Cómo activarlo (servidor)

En el `.env` de producción (no en el repo):

```env
SECURITY_ADAPTIVE_LOGIN=true
SECURITY_LOCAL_THRESHOLD=1     # 1 ⇒ solo riesgo CERO usa biometría local
SECURITY_LOCAL_TICKET_TTL=180  # vigencia del ticket de desbloqueo local (seg)
```

Luego:

```bash
php artisan config:clear
```

`SECURITY_LOCAL_THRESHOLD` regula qué tan permisivo es el desbloqueo local:
subirlo hace el login del equipo principal más cómodo (tolera más señales antes
de exigir OTP); bajarlo lo hace más estricto. Empezar conservador (`1`) y ajustar
tras observar el comportamiento real.

## Cómo probarlo (iPhone/Android)

1. Con el flag activo, login en un equipo nuevo → debe pedir **OTP + cara** y dejar
   el equipo vinculado.
2. Cerrar sesión y volver a entrar en el **mismo** equipo → debe ofrecer
   **desbloqueo local** (Face ID/huella) y entrar sin SMS ni cara.
3. Forzar riesgo (varios documentos/OTP fallidos) → el siguiente login del mismo
   equipo debe subir a OTP (o OTP + cara si el riesgo es alto).
4. Cancelar la biometría local en el paso 2 → debe caer limpiamente a OTP.

## Piezas (referencia)

- Backend: `config/security.php`, `app/Services/AccountRiskService.php`,
  `app/Http/Controllers/Api/AuthController.php` (`login`, `verifyOtp`,
  `trustedUnlock`, `isTrustedDevice`).
- App: `lib/features/auth/services/member_login_service.dart`
  (`startLogin`/`trustedUnlock`), `lib/features/auth/screens/login_screen.dart`
  (`_handleLocalUnlock`).
- Tablas: `member_device_bindings`, `member_device_sessions` (columnas de
  confianza), `member_auth_challenges` (`risk_tier`, `purpose`).

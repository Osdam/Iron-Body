# Cierre: clases solo en la tablet, con rostro; entrenadores con SMS

Reglas del propietario (2026-10-20). Este documento dice qué está hecho, qué
falta y cómo se termina, con las órdenes exactas. Cada fase se puede deshacer
sin tocar datos.

## Fase 0 — hecha y desplegada (2026-10-20)

| Pieza | Commit | Qué cambió |
|---|---|---|
| Backend | `ed9ad36`, `b6fb993` | Un código de enrolamiento ya no invalida un rostro válido; `biometric.verifiable`. |
| Backend | `24549ad` | El rostro solo vale desde un equipo firmado (tablet). Sin código para clases. `legacy_claim` prohibido a quien ya tiene rostro. `dev-login` solo desde un equipo que ese entrenador ya usaba. |
| Backend | `713d99a` | El portal en la tablet (rostro) tiene su interruptor propio, encendido; el login facial público sigue apagado. |
| CRM | `e0f7ab5`, `1b3e2bf` | Textos de renovación; sin opción de código para clases; «Declarado sin verificar (teléfono)». |
| App | `9333146` (rama `feat/employee-attendance`) | Teléfono: entrada con SMS, sin iniciar/finalizar. Tablet: portal solo con rostro, reintento sin códigos. Versión 2.0.7+19. |

Interruptores en producción tras la fase 0:

| Variable | Valor | Efecto |
|---|---|---|
| `WORKFORCE_CLASS_FALLBACK_CODE` | (sin poner → false) | Ninguna clase se opera con código. |
| `WORKFORCE_LEGACY_FACE_CLAIM` | true | El teléfono aún declara el rostro, solo quien NO tiene rostro válido. |
| `WORKFORCE_LEGACY_FACE_CLAIM_UNTIL` | (sin poner) | Sin fecha límite todavía. |
| `WORKFORCE_DEVICE_POLICY_CLASSES` | monitor | Sin firma se acepta solo lo declarado (lo anterior). |
| `WORKFORCE_KIOSK_TRAINER_PORTAL_FACE` | (sin poner → true) | Portal con rostro en la tablet. |
| `TRAINER_FACE_LOGIN_ENABLED` | false | Login facial público (sin firma) apagado. |
| `TRAINER_OTP_DEV_BYPASS` | true | `dev-login` vive, pero solo desde un equipo conocido del entrenador. |
| `TRAINER_TRUSTED_DEVICE_NOT_BEFORE` | (sin poner) | Se respeta la confianza actual de los teléfonos. |

## Fase 1 — física (pendiente)

1. **Tablet**: instalar `_apk/IronBody-Empleados-2.0.7-build19-9333146.apk`
   (SHA-256 `8721eaaf260fcabe7e65f55f14d818dbcfaf2173db7af1c89b2c4fef1e57e19d`)
   **como actualización**: abrir el archivo en la tablet → «Actualizar», o
   `adb install -r <apk>`. **No desinstalar**: borra la clave que la vincula y
   habría que vincularla de nuevo con un código.
2. **Rostros**: cada entrenador y cada empleado sin rostro válido se registra
   una vez (CRM → Personal → la persona → «Abrir biometría» → «Autorizar
   enrolamiento» → en la tablet «Acceso para empleados» → «Registrar mi rostro»).
   Comprobar quién falta:
   ```sql
   -- solo lectura
   select t.id, t.full_name from trainers t
   left join trainer_face_references r on r.identity_id = t.identity_id
     and r.status = 'active' and r.template is not null and r.consent_version is not null
   where t.status in ('active','activo') and r.id is null;
   ```
3. **Tiendas**: subir `_apk/IronBody-Play-2.0.7-build19-9333146.aab`
   (SHA-256 `5e9fe33f8fc1de73947072429321a1be0edf45384ff6e5c7eec0ff4f0a401b48`)
   a Google Play. iOS: compilar y subir la misma versión (requiere el arreglo
   de iOS de la rama `fix/ios-tflite-symbols` y la firma de App Store).

## Fase 2 — cerrar el inicio de clases desde teléfonos

Cuando la consulta de la fase 1.2 devuelva **cero** entrenadores y la tablet
tenga la 2.0.7+19:

```bash
ssh ironbody-vps
cd /var/www/api/backend
cp .env .env.antes-fase2-$(date +%Y%m%d)            # respaldo
# en .env:  WORKFORCE_LEGACY_FACE_CLAIM=false
#           WORKFORCE_DEVICE_POLICY_CLASSES=enforce
bash /var/www/api/backend/scripts/deploy-produccion.sh   # regenera config:cache
```

Efecto: iniciar o finalizar sin firma de la tablet → 403 `device_not_authorized`;
`face_verified: true` → 422 `face_required`, también para apps viejas y llamadas
directas. La consulta de clases y la asistencia desde el teléfono siguen.
Alternativa con fecha: `WORKFORCE_LEGACY_FACE_CLAIM_UNTIL=AAAA-MM-DD` (último día
aceptado, hora de Bogotá).

Vuelta atrás: restaurar el `.env` del respaldo y repetir el script.

## Fase 3 — cerrar la entrada con la cédula sola

Cuando la app con SMS (2.0.7+19 o posterior) esté instalada en los teléfonos de
los entrenadores, Android **y** iPhone:

```bash
cp .env .env.antes-fase3-$(date +%Y%m%d)
# en .env:  TRAINER_OTP_DEV_BYPASS=false
#           TRAINER_TRUSTED_DEVICE_NOT_BEFORE=<fecha de hoy, AAAA-MM-DD>
bash /var/www/api/backend/scripts/deploy-produccion.sh
curl -s -o /dev/null -w '%{http_code}\n' -X POST https://api.ironbodyneiva.cloud/api/trainer/auth/dev-login   # debe dar 404
```

Efecto: `dev-login` desaparece (404). Cada teléfono confirma una vez por SMS
(la confianza creada con la cédula sola deja de contar); después entra sin SMS
durante 30 días. Las sesiones abiertas siguen vivas hasta que el entrenador
cierre sesión. Para forzar el SMS ya: el CRM no tiene botón para cerrar las
sesiones de un entrenador; existe la API `POST /api/admin/trainers/{id}/sessions/revoke-all`
(token de administrador), y desactivar al entrenador también las cierra todas.

Vuelta atrás: restaurar el `.env` del respaldo y repetir el script.

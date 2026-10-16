# Informe de compatibilidad Flutter

## Conclusión

**NO SE REQUIERE NUEVA APK** para ninguna de las dos apps. Hay **una regresión funcional aceptada** por el propietario en la app de socios: «Registrar rostro» del perfil deja de funcionar para socios con registro completo (cierre de seguridad; ver §3).

## 1. Apps y versiones

| App | Código revisado | Versión | Dónde está |
|---|---|---|---|
| App de socios y entrenadores (publicada) | `Iron_Body_App` `origin/main` `3a4b4d3` | 2.0.4+15 | Google Play / App Store |
| APK de empleados (kiosko de jornada) | worktree `employee-attendance` `d57e836` (no fusionada en main) | 2.0.4+15 | Tablet «Tablet Recepcion» (SM-X133, Android), registrada como dispositivo autorizado el 15-10-2026, `app_version=2.0.4+15` |

Nota: las dos llevan el mismo número de versión. Una actualización de la app de socios publicada en esa tablet quitaría el kiosko (la APK de empleados no está en main).

## 2. Tablet de jornada (kiosko)

- El backend expone **11 rutas `api/kiosk/*`** (firmadas por dispositivo); la APK de empleados consume exactamente esas 11. **Ningún cambio de esta entrega las toca** (comprobado con `route:list --path=kiosk` en producción tras cada despliegue; ver `INFORME_DESPLIEGUE_PRODUCCION.md`).
- Cargos y capacidades los decide el servidor; la app los muestra como texto opaco. «Administrativo» y «Aseo y mantenimiento» funcionan sin cambiar la app.
- **VERIFICADO MANUALMENTE por el propietario (16-10-2026)**: la tablet reconoce a Yuri como ADMINISTRATIVA y consulta su identidad laboral; el CRM permite autorizar su enrolamiento.
- **PENDIENTE (requiere a la persona presente y su consentimiento)**: enrolamiento facial real de Yuri y una jornada completa de entrada y salida. Probado en local con un dispositivo de prueba y código de respaldo (entrada 201, reintento idempotente, salida 200, sin cálculo de puntualidad).

## 3. App de socios (publicada)

| Función | Efecto de esta entrega |
|---|---|
| Login (OTP, rostro, desbloqueo local) | Sin cambios de contrato. El login solo acepta retos de propósito `login` (un reto de otra acción responde como inexistente): la app solo usa retos de login ahí |
| Alta nueva (`members/register` → identidad → contrato → rostro u omitir) | Sigue funcionando: los pasos se aceptan mientras el alta está en curso (ventana de 2 h por alta) |
| Reanudar un alta a medias | Sigue funcionando (estados de alta en curso, sin sesiones de dispositivo) |
| «Registrar rostro» del perfil (`members/{id}/biometric` con el token público) | **Deja de funcionar para socios con registro completo** (responde 409 y la app muestra «No pudimos registrar tu rostro»). Decisión del propietario: bloquear el reemplazo de rostro por esta ruta, que permitía a cualquiera con el token de la APK suplantar el rostro de acceso de un socio. Alternativas: CRM → Punto físico (personal) o el reenrolamiento con OTP del login |
| Membresía, pagos, clases, IRON IA, etc. | Sin cambios. Tras un traslado de plan la app recibe una señal silenciosa y refresca funciones |
| Funciones por plan | Tras trasladar a «Élite», la app muestra el nombre y los beneficios de «Élite» |

## 4. Cuándo SÍ haría falta una APK nueva (no incluido en esta entrega)

1. Recuperar «Registrar rostro» del perfil de forma segura: enviarlo con la sesión del socio a una ruta autenticada.
2. Cerrar del todo la reanudación por documento de un alta a medias ajena (exige verificar el teléfono por OTP antes de registrar).
3. Fusionar la APK de empleados en main para que una actualización de la app no quite el kiosko de la tablet.

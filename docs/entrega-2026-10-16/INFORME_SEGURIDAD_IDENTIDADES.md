# Informe de seguridad — identidades, registro público, biometría y acceso

Todos los hallazgos son **previos** a este trabajo salvo que se indique. Ninguno muestra rastro de explotación en los registros de nginx disponibles (~14 días). Estados: CORREGIDO-DESPLEGADO · CORREGIDO-PENDIENTE DE DESPLIEGUE · ABIERTO (con motivo).

## 1. Hallazgos y estado

| # | Severidad | Hallazgo | Estado |
|---|---|---|---|
| S1 | **CRÍTICO** | `DELETE api/members/{id}` con el token de la APK pública borraba **cualquier** socio y su usuario; las FK propagan en cascada a **pagos**, asistencias, contratos y 40 tablas más; los ficheros se borraban antes de la transacción | CORREGIDO-DESPLEGADO (`43e6206`); desde la 2.ª iteración el DELETE público ya no borra nada |
| S2 | **CRÍTICO** | `/crm/*` (CRM antiguo en Blade) **sin autenticación**: lectura anónima de entrenadores y rutinas; escritura (crear entrenadores con identidad y roles del portal, asignar socios) con el token CSRF del propio formulario | CORREGIDO-DESPLEGADO (`43e6206`): 404 salvo `LEGACY_WEB_CRM_ENABLED=true`; verificado en producción (antes 200, ahora 404) |
| S3 | ALTO | `register` respondía 409 sobre un socio activo pero confirmaba la reescritura de su usuario (nombre, correo, teléfono → el OTP iba al atacante en 94 socios sin teléfono propio); por correo cambiaba el documento de otro usuario; secuestraba altas a medias; revivía fichas suspendidas | CORREGIDO-DESPLEGADO (`43e6206`); verificado en producción: 409 sin `access_hash` y usuario intacto |
| S4 | ALTO | Pasos públicos (`identity`, `legal-consent`, `signature`, `biometric`) pisaban datos, consentimientos y el **rostro de acceso** de socios activos ajenos | CORREGIDO-DESPLEGADO (`43e6206`). Decisión del propietario: bloquear el reemplazo de rostro también desde el perfil de la app |
| S5 | ALTO | El token compartido de automatizaciones podía **descargar todas las imágenes faciales** y leer las credenciales del torniquete | CORREGIDO-DESPLEGADO (`43e6206`); verificado: 403 (antes 200) |
| S6 | MEDIO | `members/incomplete` volcaba los datos personales de todas las altas a medias | CORREGIDO-DESPLEGADO (`43e6206`): fuera del grupo público (405) |
| S7 | MEDIO | `turnstile/webhook/fire` público (SSRF que devolvía el cuerpo) | CORREGIDO-DESPLEGADO (`43e6206`: exige persona y `classes.manage`); SSRF residual (redirecciones, cuerpo, destinos internos) CORREGIDO-DESPLEGADO (`ed2aabb`) |
| S8 | ALTO (nuevo en la revisión del cierre) | Un cliente del CRM sin ficha de socio y con historia se podía reclamar solo con la cédula (toma de cuenta y acceso físico con su plan). Hoy en producción hay **0** usuarios sin ficha | CORREGIDO-DESPLEGADO (`ed2aabb`): exige teléfono coincidente, no pisa datos ni baja estado |
| S9 | ALTO | Reanudar por documento un alta a medias (incluidas las completas que omitieron el rostro, que quedaban reanudables para siempre) entregaba su `access_hash` | CORREGIDO-DESPLEGADO (`ed2aabb`): exige el mismo teléfono guardado |
| S10 | MEDIO | La ventana de los pasos (2 h) se alcanzaba por id secuencial | CORREGIDO-DESPLEGADO (`ed2aabb`): 15 min, atada a la red que hizo `register`; `biometric` rechazado si se declaró omitido |
| S11 | BAJO | 409 de `register` revelaba ids; sin límite de peticiones en el registro; cédulas y contratos legibles con el token compartido; credenciales del torniquete en claro para Recepción; `members/login/resend` reenviaba retos de otros flujos | CORREGIDO-DESPLEGADO (`ed2aabb`); verificado: el 409 ya no lleva ids |
| N1 | BAJO (verificación de la 2.ª iteración) | Al reanudar, el teléfono se comparaba con el usuario encontrado por correo: con una ficha sin usuario, el de quien hacía la petición; entregaba el `access_hash` ajeno. Hoy en producción hay 0 fichas sin usuario | CORREGIDO-DESPLEGADO (`ed2aabb`): se compara con el usuario de la propia ficha |
| N2 | BAJO-MEDIO (hipótesis) | El SSRF ciego del torniquete permite como destino la IP pública del propio VPS (servicios en `0.0.0.0` ocultos por el firewall) | ABIERTO: añadir las IPs propias a los destinos prohibidos (requiere listar los puertos en escucha) |
| N3 | BAJO | `zkteco/open` devolvía y guardaba el detalle de la conexión: servía de escáner de puertos | CORREGIDO-DESPLEGADO (`ed2aabb`): mensaje genérico; el detalle solo en el log |
| N4 | BAJO | Quien tiene `classes.manage` puede cambiar el destino del torniquete y reutilizar la credencial guardada | ABIERTO: exigir reescribir la credencial al cambiar el host |
| N5 | INFO | `phone[]` como array en register produce un 500 (falla cerrado) | ABIERTO (previo) |
| N6 | INFO | Adivinar el teléfono de una cédula conocida solo lo frena el cupo de 10/min por red | ABIERTO: contador de fallos por huella del documento |
| H1 | ALTO (introducido y cerrado antes del primer despliegue) | El código del vínculo socio↔identidad, dictado al administrador, servía para abrir la app del socio | CORREGIDO-DESPLEGADO (`b1ce780`): login solo con retos de login; vínculo apagado; código del vínculo ligado al reto (`43e6206`) |

## 2. Riesgos residuales (aceptados o pendientes de decisión)

1. **El teléfono no prueba posesión** (S8, S9): quien conozca la cédula y el celular de alguien aún puede enlazar o reanudar. Cierre robusto: OTP durante el registro → requiere APK nueva.
2. Dentro de la ventana de 15 min, desde la misma red y conociendo el id, un tercero podría adelantarse a subir un rostro en un alta ajena recién creada (la APK no manda ningún secreto propio en ese paso).
3. SSRF hacia la LAN: permitido a propósito para el relé de la sede; ciego (sin cuerpo ni redirecciones) y solo para personas con `classes.manage`.
4. «Registrar rostro» del perfil de la app: bloqueado para socios activos (decisión de seguridad); recuperarlo exige APK nueva con la sesión del socio.
5. 94 socios activos no tienen ningún teléfono (ni en la ficha ni en el usuario): no pueden recibir OTP. `OTP_SKIP_WHEN_NO_PHONE` está en `false` en producción (no entran solo con el documento).

## 3. Identidades

- Una persona = una identidad (`identities.document_normalized` único). El perfil laboral cuelga de la identidad; la ficha de socio solo se vincula con verificación.
- **No se vincula nunca** por nombre, documento ni teléfono no verificado. La comprobación previa del alta solo informa («coincide por documento, sin verificar»).
- Vínculo verificado por SMS: **apagado** (`WORKFORCE_MEMBER_LINK_ENABLED=false`). Precondiciones cumplidas en el código (código propio ligado al reto, fuera de Twilio Verify; rastro de cada cambio de `members.phone` con cuarentena de 7 días). Para encenderlo: `TWILIO_FROM` válido para Colombia y una prueba con la persona presente.
- Yuri: perfil laboral Administrativo activo (identidad 15); ficha de socio **sin vincular** (no se ha forzado); su acceso gratuito y su plan comercial intactos.

## 4. Biometría: dos sistemas separados

| | Biometría LABORAL (tablet de jornada) | Biometría de ACCESO (gimnasio) |
|---|---|---|
| Para qué | Identificar al personal y registrar entrada/salida laboral | Abrir el torniquete (software local) y tercer factor del login de la app |
| Tabla | `trainer_face_references` (por identidad) | `member_biometrics` (por ficha de socio) |
| Dato | Vector facial (192 dimensiones) **cifrado con APP_KEY** | Imagen JPEG/PNG en disco privado, **sin cifrado de aplicación** |
| Dónde se compara | En el servidor | En el software local, en el navegador del CRM o en la app |
| Sale del servidor | Nunca | Sí (lo descarga el lector con sesión de Recepción) |
| Consentimiento | Versión y fecha por plantilla | Consentimiento genérico de tratamiento de datos en la app; ninguno específico en el CRM |
| Revocación | Automática al desactivar el perfil laboral | Manual (CRM o borrado de cuenta) |
| Comparten algo | **No**: ni tabla, ni formato, ni proveedor, ni código | — |

Reglas: no reutilizar plantillas entre sistemas; no registrar el rostro de otra persona bajo la identidad de un empleado; enrolar solo con la persona presente y con su consentimiento. Pendientes de decisión: cifrar las imágenes de acceso; retirar la imagen de acceso y el `EmployeeAccess` manual al dar de baja a un trabajador; filtrar `face-references` por estado.

## 5. Verificación

Pruebas por hallazgo (cada corrección tiene una prueba que falla sin ella), revisiones independientes de api-security antes de cada despliegue y pruebas de humo en producción con transacción revertida (0 escrituras). Detalle en `INFORME_PRUEBAS.md` y `INFORME_DESPLIEGUE_PRODUCCION.md`.

# Informe de implementación — Personal, traslados de planes y cierre de seguridad

Repositorios: backend `Osdam/Iron-Body` (Laravel, `backend/`), CRM `Osdam/Iron-Body_Front` (Angular). App Flutter sin cambios.

## Fase 1 — Funcionalidades (desplegadas el 16-10-2026, backend `b1ce780`, CRM `5886982`)

### A. Ficha laboral unificada (Supervisión → Personal y biometría)

| Commit | Qué |
|---|---|
| backend `fb205a3` | Ficha de una persona (`GET admin/workforce/staff/{identity}`, 404 uniforme para identidades solo de socios), comprobación previa del alta (`POST …/staff/lookup`, no crea nada), `identity_id` opcional en el alta (409 `identity_mismatch`), vínculo socio↔identidad por OTP, cupos por administrador, los fallos del código no suman riesgo al socio |
| backend `87e7864` | El vínculo solo usa el teléfono propio de una ficha de socio activa |
| backend `b08a1dc` | Vínculo apagado por defecto (`WORKFORCE_MEMBER_LINK_ENABLED`); el login y el tique facial solo canjean retos de propósito `login` (H1) |
| CRM `5255be7`, `c52b264`, `dc66007` | Vista de ficha (cargo, jornada, biometría, código de respaldo, membresía pagada y acceso gratuito por separado, permisos en solo lectura), alta con «Comprobar», diálogo de vínculo (el código nunca se pinta), estados de bloqueo explicados |

Sin migraciones, sin permisos nuevos, sin cambios en kiosko ni APK. Administrativo sin horario fijo: ya soportado por `WorkdayService` (sin horario ni puntualidad).

### B. Traslado de socios entre planes (Planes → «Trasladar socios»)

| Commit | Qué |
|---|---|
| backend `7fd22ca` | Tablas `plan_transfer_batches`/`plan_transfer_items` (migraciones aditivas), `PlanConditions`, `PlanTransferEvaluator` (candidatos y simulación sobre un clon en memoria con las clases reales), `PlanTransfers` (ejecución atómica con revalidación bajo bloqueo, huella, idempotencia, auditoría por lote y por socio, reversión como lote nuevo), permiso `plans.transfer`, limitadores por administrador, documentos enmascarados, tope de 300 |
| backend `366bac3` | Aislamiento de las pruebas en PostgreSQL |
| CRM `c00592b` | Asistente (planes → candidatos → simulación → confirmación con motivo → resultado), historial, detalle y reversión; catálogo de permisos regenerado |

## Fase 2 — Cierre de seguridad y acceso (desplegado el 16-10-2026, backend `43e6206`, CRM `6a1566c`)

| Rama / commit | Qué | Por qué |
|---|---|---|
| `fix/members-register-ownership` `6850b81` | Registro público: comprobaciones antes de escribir y con la ficha bloqueada; solo se reanudan altas en curso; los pasos (`identity`, `legal-consent`, `signature`, `biometric`, `biometric-skip`) exigen un alta en curso con ventana abierta (409 `registration_closed`); el borrado público solo descarta altas sin historia, con los ficheros borrados después del commit; `members/incomplete` sale del grupo público; rechazos registrados sin datos en claro | Con el token que viaja en la APK se podían **borrar socios con sus pagos y contratos en cascada**, reescribir datos de socios activos, cambiar el documento de otro usuario por correo, secuestrar altas a medias, revivir fichas suspendidas, cambiar el rostro de acceso de cualquier socio y volcar los datos de las altas a medias |
| `fix/access-hardening` `b070ba2` | `face-references`, `face-image`, `face-enrollment` y `turnstile*` exigen persona; `webhook/fire` deja de ser público; `usedEntriesFor` excluye entradas de empleado | El token compartido de automatizaciones podía descargar todas las imágenes faciales y las credenciales del torniquete; SSRF; las entradas como empleado gastaban cupo del plan |
| `feat/member-link-secure-channel` `d6b9478` | Código del vínculo propio (hash) por SMS normal, nunca por Twilio Verify; `MemberPhoneObserver`/`MemberPhoneTrail` registran cada cambio real de `members.phone` | Precondiciones para poder encender el vínculo (la bandera sigue apagada) |
| `fix/legacy-web-crm-disabled` `22dc5a5` | `/crm/*` (CRM antiguo en Blade) responde 404 salvo `LEGACY_WEB_CRM_ENABLED=true` | **Sin autenticación**: lectura y escritura anónimas de entrenadores, asignaciones y rutinas |
| CRM `fix/attendance-employee-access` `0e0ac42` | «Punto físico» deja entrar a quien el servidor autoriza como empleado | Exigía pago confirmado e ignoraba `EmployeeAccess` |

Decisión del propietario aplicada: **bloquear el reemplazo de rostro desde la app** (la función «Registrar rostro» del perfil deja de funcionar para socios activos).

## Fase 3 — Segunda iteración de endurecimiento (desplegada el 16-10-2026, backend `ed2aabb`)

| Commit | Qué |
|---|---|
| `e8aa158` | Registro público: un cliente del CRM sin ficha y con historia solo se enlaza con su teléfono, sin pisar datos ni bajar su estado; reanudar exige el mismo teléfono guardado; ventana de los pasos de 15 min atada a la red de `register` (`App\Support\Network\ClientNetwork`); `biometric` rechazado si se declaró omitido; 409 sin ids; cupos por red (`member-register` 10/min, pasos 30/min, descarte 10/min); el `DELETE` público ya no borra nada. Torniquete: sin redirecciones, sin devolver ni guardar el cuerpo, destinos de loopback/link-local/metadatos rechazados con la IP resuelta fijada (`App\Support\Network\OutboundDestination`), credenciales enmascaradas en la lectura (el PUT conserva las guardadas). Cédulas y contratos exigen una persona. `members/login/resend` solo reenvía retos de login |
| `7d80a0a` | N1: reanudar compara con el usuario de la propia ficha; N3: ZKTeco devuelve un mensaje genérico (el detalle, solo al log) |

Compatibilidad: la APK publicada sigue registrando (alta nueva, sin rostro, reanudación con el mismo teléfono, reintento tras cambio de red). Quien corrija su teléfono a mitad de un alta tiene que pasar por recepción o entrar por el login con OTP.

## Lo que NO se implementó (y por qué)

- Retirada de «INGRESO EMPLEADOS»: bloqueada por el software local (ver `PLAN_RETIRO_INGRESO_EMPLEADOS.md`).
- Cambios en el software local de ingreso: su código no está disponible.
- Encender el vínculo por SMS: requiere `TWILIO_FROM` y una prueba con la persona presente.
- APK nueva: no hace falta (ver `INFORME_COMPATIBILIDAD_FLUTTER.md`).
- Fallos previos documentados y no tocados: anular un cobro no restaura el plan; `isMemberInside` del Punto físico; el acceso del Punto físico se calcula solo al abrir; `captured_at` es la hora de subida; `face-references` no filtra por estado; la baja laboral no retira el `EmployeeAccess` manual ni la imagen de acceso; 500 en PostgreSQL ante un tique inventado (`AdaptiveLoginGuardsTest`, `PhoneRecoveryTest`).

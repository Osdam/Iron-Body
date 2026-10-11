# Asistencia laboral, biometría y dispositivos autorizados — diseño

Rama `feat/employee-attendance` (backend sobre `89db408`, CRM sobre `7ee4835`, app sobre
`3a4b4d3`). Contrato de API: [CONTRATO.md](CONTRATO.md).

## 1. Qué había (auditoría del 2026-10-10)

| Pieza | Estado encontrado |
|---|---|
| Identidad | `identities` (documento normalizado) agrupa socio y entrenador. Recepción solo existía como cuenta del CRM (`admins`), sin documento ni identidad. |
| Roles | `trainer_roles`: `trainer_floor`, `trainer_functional` (en producción los 11 funcionales son también de planta). |
| Biometría de entrenadores | MobileFaceNet 192-D calculado en el teléfono; el servidor compara 1:1 (`trainer_face_references`, 4 filas, JSON en claro). Sin prueba de vida. El enrolamiento SOBRESCRIBÍA. |
| Inicio/fin de clase | `face_verified: true` enviado por la app; la app solo comprobaba que la cámara devolvió un archivo. |
| Acceso por documento | `dev-login` sin segundo factor (`TRAINER_OTP_DEV_BYPASS=true`): 134 de 158 logins. |
| Login facial | Público; aceptaba un vector casi nulo (sin control de norma) → **apagado en producción el 2026-10-11** (`TRAINER_FACE_LOGIN_ENABLED=false`). |
| Dispositivos | `device_id` aleatorio declarado por la app; sin registro ni prueba criptográfica. |
| Tiempo real | Outbox tipado `class_events` + stream del CRM `proto=2` con cursor/resync. PHP-FPM con 24 workers; cada stream ocupa uno ~25 s. |
| `attendances` / `employee_accesses` | Puerta de socios y pase gratuito al torniquete: **no** son jornada laboral y no se tocan. |

## 2. Arquitectura

Respeta los patrones del proyecto (servicios en `app/Services/<Dominio>`, soporte puro en
`app/Support/<Dominio>`, excepciones con código estable, controladores finos, mapa
central de autorización). Sin frameworks nuevos ni dependencias nuevas.

```
Dominio (sin HTTP)        app/Support/Workforce/{FaceVector, Positions, DeviceSignature, KeyAttestation, Secrets}
Aplicación                app/Services/Workforce/{EmployeeDirectory, FaceReferenceService, AuthorizationCodeService,
                                                  DeviceRegistry, KioskTokens, WorkdayService, ClassIdentityVerifier,
                                                  BiometricRetention, WorkforceEvents}
Infraestructura           migraciones 2026_10_11_7000xx, modelos, middleware device.signed, observer de retención,
                          comando workforce:maintenance, outbox class_events (subject/audience)
Presentación (API)        Api/Kiosk/* (tablet), Api/Admin/{WorkforceSupervision, WorkforceStaff, BiometricAdmin, DeviceAdmin},
                          ampliaciones de TrainerAuth/TrainerClass/TrainerAdmin/ClassSupervision/ClassEnrollment(stream)
```

**Invariante principal:** registro de clase ≠ jornada laboral. Tablas, estados y
eventos separados; un entrenador de planta que dicta clase registra las dos cosas por
caminos distintos y terminar la clase no toca la jornada (probado).

## 3. Modelo de datos (migraciones aditivas)

| Migración | Qué hace |
|---|---|
| `700001_create_staff_profiles_table` | Perfil laboral por identidad (recepción, administración, mantenimiento). |
| `700002_make_face_references_belong_to_identities` | `identity_id` único, `template` cifrado (APP_KEY), estado, consentimiento, dispositivo, quién autorizó, revocación. Rellena las filas existentes y vacía el JSON en claro. Reversible (se niega si hay plantillas de no-entrenadores). |
| `700003_create_authorized_devices_tables` | Dispositivos, códigos de vinculación (HMAC), desafíos (nonce único), intentos de seguridad. |
| `700004_create_workforce_codes_and_tokens_tables` | Códigos de administrador (enrolamiento/respaldo) y tokens de kiosko (HMAC). |
| `700005_create_workdays_tables` | Jornadas + historial inmutable. Índice único PARCIAL `workdays_one_open_per_identity` (misma sintaxis en PostgreSQL y SQLite). `operation_id` único. |
| `700006_add_device_and_identity_method_to_class_sessions` | Dispositivo verificado/declarado y método de identidad en inicio/fin de clase. Históricos en NULL. |
| `700007_add_subject_and_audience_to_class_events` | El outbox lleva hechos de personal con el permiso necesario para recibirlos. |
| `700008_add_expires_at_to_trainer_device_sessions` | Caducidad de las sesiones del portal abiertas en una tablet compartida (kiosko). NULL en las existentes. |

## 4. Reglas de negocio

1. Una persona = una identidad. Cargos = roles de entrenador activos ∪ cargos de su perfil de personal activo.
2. Registra jornada quien tiene un cargo con `workday: true` (planta, recepción, administración, mantenimiento). El entrenador SOLO funcional no.
3. El documento identifica; nunca verifica. Verificación = rostro comparado en el servidor o código de un administrador.
4. Una jornada abierta por persona (índice parcial). Entrada repetida → 409 con la abierta. Salida sin abierta → 409.
5. Hora oficial = servidor. `business_date` = día de Bogotá de la entrada (las jornadas cruzan la medianoche).
6. `operation_id` hace idempotentes los reintentos; reutilizarlo para otra persona/acción → 409.
7. Salida olvidada (>16 h, configurable): pasa a incidencia «salida no registrada» sin inventar hora; la persona puede entrar de nuevo; un administrador la corrige con motivo (hora de salida o descarte). Una salida sobre una jornada de >16 h no la cierra: la marca. El planificador marca cada hora.
8. Desactivar a quien tiene la jornada abierta (y ya no conserva un cargo con jornada) la deja como incidencia «desactivado con la jornada abierta», también sin hora inventada; se resuelve igual.
9. Cerrar la pantalla o la sesión nunca cierra una jornada.
10. Plantilla facial: nunca se sobrescribe una activa; cambiarla exige código de administrador. 1:N contra las demás personas al registrar. Tres capturas coherentes. Consentimiento versionado.
11. Revocar BORRA la plantilla y conserva la traza. Desactivar a alguien que ya no conserva ningún perfil activo revoca y borra (decisión 2026-10-10).
12. Respaldo si el rostro falla: código de un solo uso generado en el CRM para esa persona y esa operación (decisión 2026-10-10).

## 5. Flujos

**Jornada (kiosko compartido):** pantalla neutral → identificar (documento o lista) →
verificar (rostro o código) → la app muestra el estado y la ÚNICA acción permitida →
entrada/salida con `operation_id` → confirmación → vuelve sola a neutral. Nada de la
persona persiste en la tablet; la verificación dura 120 s y sirve una vez.

**Sin rostro registrado:** el administrador genera un código en el CRM (Personal y
biometría) → la persona lo dicta en la tablet → consentimiento → 3 capturas → el
servidor enrola y devuelve una verificación → la app registra la entrada en el mismo
flujo.

**Clases:** igual que hoy, pero iniciar/finalizar mandan `embedding` (comparado en el
servidor) o `fallback_code`, y la firma del dispositivo. La app publicada sigue
funcionando (`legacy_claim`) mientras dure la transición.

## 6. Seguridad del dispositivo

- Clave EC P-256 no exportable (Android Keystore con StrongBox si existe; Secure Enclave en iOS).
- Vinculación: código de un administrador (15 min, un uso) + prueba de posesión firmada. En Android, cadena de atestación verificada contra las raíces de Google fijadas por huella; el desafío es el código de vinculación.
- Cada operación: nonce del servidor (120 s, un uso, se quema aunque la firma falle) firmado junto con método, ruta y hash del cuerpo.
- Revocar desde el CRM corta al instante (también los nonces ya emitidos).
- Intentos desconocidos, revocados, de firma inválida o desafío reutilizado quedan registrados y visibles en el CRM.
- Declarado ≠ verificado: el CRM distingue «Tablet Recepción 01» (firmado) de «Android · no verificado» (sesión de la app anterior) y de «No registrado» (histórico).
- Sin Play Integrity: exige credenciales de Google Cloud y el canal de distribución; queda como mejora (ver riesgos).

**Límite honesto:** un dispositivo vinculado y robado firma igual. Por eso la defensa es
combinada: dispositivo autorizado + identidad de la persona (rostro/código) + rol +
hora del servidor + auditoría + revocación inmediata.

## 7. Permisos (CRM)

| Permiso | Para |
|---|---|
| `workforce.view` | Pestaña Empleados, Personal (lectura), indicadores, stream. |
| `workforce.manage` | Alta/edición de personal, resolver incidencias, códigos de respaldo. |
| `biometrics.view` | Estado e historial biométrico. |
| `biometrics.manage` | Autorizar enrolamiento, revocar (también «Borrar rostro» de la ficha del entrenador). |
| `devices.view` | Dispositivos, intentos, datos técnicos de cada movimiento. |
| `devices.manage` | Vincular, renombrar, revocar dispositivos. |

Por defecto solo Super Admin y Administrador. Ninguno lo recibe el token compartido de
automatizaciones. Registrar la propia jornada no es un permiso del CRM: lo decide el cargo.

## 8. Tiempo real

Mismo stream del CRM (`admin/classes/stream?proto=2`), mismo outbox. Nuevos hechos
`workday.updated`, `staff.updated`, `biometric.updated`, `device.updated` con `subject`
(uuid) y sin datos personales; cada conexión recibe solo los de sus permisos y los
filtrados avanzan el cursor sin emitirse. Se escriben en la transacción del cambio: lo
que se revierte no se anuncia.

## 9. Configuración (`config/workforce.php`)

| Variable | Defecto | Uso |
|---|---|---|
| `WORKFORCE_DEVICE_POLICY_CLASSES` | `monitor` | `enforce` exige firma para iniciar/finalizar clase. |
| `WORKFORCE_LEGACY_FACE_CLAIM` | `true` | `false` deja de aceptar `face_verified: true` sin prueba. |
| `WORKFORCE_LEGACY_SELF_ENROLLMENT` | `deny` | `first_only` reabre el autoenrolamiento del portal para quien no tiene plantilla (no recomendado: sin código de administrador). |
| `WORKFORCE_WORKDAY_STALE_HOURS` | `16` | Umbral de salida olvidada. |
| `WORKFORCE_FACE_ENROLLMENT_CONSISTENCY` | `0.9` | Distancia máxima entre capturas del enrolamiento. |

## 10. Despliegue seguro (requiere autorización; nada de esto se ha ejecutado)

Orden: backend → CRM → app. Cada paso es compatible con el anterior.

1. **Backend**: copia de la base; `git pull` del commit;
   `PGOPTIONS='-c lock_timeout=5s' php artisan migrate --force` (aditivas; la 700002 cifra
   las 4 plantillas existentes con la APP_KEY actual). Los ADD COLUMN de `class_sessions` y
   `class_events` toman un lock exclusivo breve: con el límite, si hay una consulta larga
   delante, esa migración se deshace (en PostgreSQL cada una va en su transacción) y se
   reintenta, en vez de encolar detrás todo el tráfico de clases. `PGOPTIONS` llega a la
   conexión de Laravel (comprobado en local: `show lock_timeout` → 5s);
   `config:cache` como `www-data` + `chmod 600`; `queue:restart` como `www-data`;
   comprobar 12 workers RUNNING dos veces. Sin variables nuevas obligatorias.
2. Verificación en producción (solo lectura + un fixture QA borrado al final): rutas
   `kiosk/*` responden 401 sin firma; `admin/workforce/*` 403 con Recepción; stream de
   clases sigue emitiendo; `class-sessions` trae `start_device`.
3. **CRM**: `npm run deploy` en `/var/www/app-source` (no borra chunks).
4. **App**: publicar la versión nueva; vincular cada tablet desde el CRM.
4b. **Enrolar al personal ANTES de usar la app nueva en clases.** Hoy ningún entrenador
   tiene plantilla que verifique (las 4 heredadas quedan «pendiente de renovación», los
   otros 7 no tienen). Con la app nueva, iniciar o finalizar una clase manda el rostro;
   sin plantilla activa el servidor responde `biometric_not_enrolled` y la app pide un
   código de administrador en CADA inicio y fin. Para cada entrenador: CRM → Personal y
   biometría → «Autorizar enrolamiento» → en la tablet, Acceso para empleados →
   Entrenadores (o Jornada laboral si ficha jornada) → código → consentimiento → 3
   capturas. Comprobar en el CRM que pasa a «Activa». Mientras tanto, la app publicada
   (2.0.x) sigue funcionando como `legacy_claim`.
5. Cuando todas las tablets estén vinculadas y con la app nueva:
   `WORKFORCE_DEVICE_POLICY_CLASSES=enforce`, `WORKFORCE_LEGACY_FACE_CLAIM=false`,
   `WORKFORCE_LEGACY_SELF_ENROLLMENT=deny`, `TRAINER_OTP_DEV_BYPASS=false`
   (cada uno con autorización y comprobación).
6. `TRAINER_FACE_LOGIN_ENABLED` puede volver a `true` tras el paso 1 (el control de norma
   ya está desplegado), o dejarse apagado: la app nueva usa `kiosk/verify`.

## 11. Reversión

- CRM: volver a publicar el commit anterior (`deploy.mjs`, chunks conservados).
- Backend sin datos nuevos: `migrate:rollback --step=8` (probado en SQLite: el esquema
  vuelve idéntico y la plantilla heredada vuelve en claro) + commit anterior.
- Backend con datos ya creados: la 700002 se niega a bajar si hay plantillas de personas
  sin perfil de entrenador (revocarlas antes); las tablas nuevas se pierden al bajar
  (jornadas registradas): exportarlas antes.
- Interruptores sin desplegar: las políticas de §9 vuelven a sus valores de transición.

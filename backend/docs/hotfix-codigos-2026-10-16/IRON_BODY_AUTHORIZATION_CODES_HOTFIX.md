# IRON_BODY_AUTHORIZATION_CODES_HOTFIX — 16-10-2026

Estado: **corrección lista y probada, NO desplegada** (falta autorización) · **sin prueba real en tablet**.

## 1. CAUSA DEL ERROR

**Los códigos no fallaban en el servidor.** Cada código vale para **una operación de una persona**: el hash es `propósito | identidad | número`.

Evidencia de producción (solo lectura):

- **Hoy (16-10), de 16:06 a 16:41 (Bogotá)**, para Juan Alejandro Horta (entrenador 5, identidad 5, sin rostro) se generaron:
  - **3 códigos «Registrar la jornada»** (`fallback_workday`), la opción que el CRM marcaba **por defecto**;
  - **3 códigos «Registrar el rostro»** (`enrollment`).
- Los 6 se escribieron en la tablet en el diálogo **«Usar código de administrador» de INICIAR CLASE**, que solo acepta códigos `fallback_class`. Según nginx, en `POST /api/trainer/classes/78|80/start` se alternan:
  - **409** `biometric_not_enrolled`: sin rostro, la tablet pide el código;
  - **422** `authorization_code_invalid`: el código era de otra operación.
- **Ninguno de los 6 códigos registró ni un intento.** Así se comporta el sistema cuando no hay un código vivo de esa operación para esa persona: el número escrito nunca se llega a comparar.
- La tablet **nunca abrió el registro de rostro**: hoy no hubo ni una llamada a `kiosk/employees/identify` ni a `kiosk/enrollment/start`.
- El mismo patrón aparece con Yuri (16-10, 00:27):
  - su código de rostro se escribió primero en el respaldo de la jornada (`verify-code`, 422 dos veces);
  - después sí en «Registrar mi rostro» (`enrollment/start`, 200);
  - el registro se abandonó: no hubo `enrollment/complete`, y el código quedó gastado sin plantilla.

**Lo que sí era defectuoso** (y por eso se repitió sin que nadie supiera qué pasaba):

1. **CRM:** el diálogo «Código de respaldo» marcaba **«Registrar la jornada» por defecto**, incluso para quien da clases.
2. **Backend:** la misma respuesta (`authorization_code_invalid` → «El código no es válido, caducó o ya se usó») para todo: código de otra operación, de otra persona, ya usado, caducado o número equivocado. Además, ningún rechazo quedaba registrado (`WorkforceException::report()` → false, sin auditoría).
3. **CRM y tablet:** ningún texto decía **dónde** se escribe cada código.

Hipótesis descartadas con evidencia:

| Hipótesis | Por qué se descarta |
|---|---|
| Propósito generado ≠ validado en el código | Angular `workday/class` → `fallback_*`; `enrollment` → `enrollment`; Flutter usa los mismos |
| Identificadores Trainer / Identity / User distintos | Juan: trainer 5 = identity 5 |
| Expiración incorrecta, zona horaria, reloj | DB y PHP en UTC; TTL de 30 min |
| Formato o ceros iniciales | Probado con `0041 2345` |
| Hash | Ayer se consumieron bien 2 códigos de jornada (identidad 7) |
| Autenticación | Sesiones válidas |
| APK 2.0.4+15 | Mismo contrato |
| Cambios recientes | Ninguno toca este módulo desde el 11-10 salvo el vínculo de socio |
| Conflicto biometría laboral vs. de clases | `referenceForTrainer` → la misma identidad |
| Consumo del código antes de iniciar la clase | `start()`/`end()` no fallan después de validar el código |

## 2. CÓDIGOS AFECTADOS

| Código | Propósito | Se genera en el CRM | Se escribe en la tablet | Endpoint que lo consume |
|---|---|---|---|---|
| Registrar / renovar rostro | `enrollment` | Personal → persona → «Abrir biometría» → «Autorizar enrolamiento» (`biometrics.manage`) | «Acceso para empleados» → identificarse → **«Registrar mi rostro»** | `POST /api/kiosk/enrollment/start` (se gasta al empezar; 10 min para completar) |
| Iniciar / finalizar clase | `fallback_class` | «Código de respaldo» → **«Iniciar o finalizar una clase»** (`workforce.manage` + `trainers.manage`) | Al iniciar o finalizar → «Usar código de administrador» | `POST /api/trainer/classes/{id}/start` y `/end`; `kiosk/verify-code` con `trainer_session` |
| Entrada / salida laboral | `fallback_workday` | «Código de respaldo» → «Registrar la jornada» (`workforce.manage`) | «Registrar entrada y salida» → «Usar código de administrador» | `POST /api/kiosk/verify-code` (`workday`) → `clock-in` / `clock-out` |
| Vincular tablet | *pairing* | Dispositivos | Alta del equipo | `kiosk/device/register` |
| Vínculo con ficha de socio | SMS | — | — | Apagado (`WORKFORCE_MEMBER_LINK_ENABLED`) |

Todos los de autorización: **8 dígitos**, un solo uso, caducan a los 30 min, admiten 5 intentos, y al emitir uno nuevo se anula el anterior del mismo propósito.

**Afectados por el incidente:** el respaldo de **clase**, porque nunca se generó uno de clase, y el **registro de rostro**, porque nunca se usó en su pantalla.

## 3. CÓDIGOS QUE FUNCIONABAN

**Todos**, usados en su sitio:

- Producción: el 15-10, dos códigos de jornada de la identidad 7 se consumieron bien.
- Pruebas sobre el código desplegado (`e1e8577`):
  - rostro en el kiosko, y después clase por rostro **sin código**;
  - renovación del rostro;
  - clase con código (iniciar y finalizar, uno cada uno);
  - jornada con código (entrada y salida);
  - ceros iniciales;
  - límite de 5 intentos;
  - permisos para generar cada tipo.

## 4. ARCHIVOS MODIFICADOS

**Backend** (rama `hotfix/authorization-codes` sobre `e1e8577`):
- `app/Services/Workforce/AuthorizationCodeService.php`: diagnóstico del rechazo y su auditoría.
- `app/Exceptions/WorkforceException.php`: `authorization_code_not_issued`, `authorization_code_expired`, `authorization_code_used`.
- `app/Services/Workforce/FaceReferenceService.php`: evento `biometric.code_rejected`.
- `app/Http/Controllers/Api/Admin/BiometricAdminController.php`: etiqueta «Código rechazado» en el historial.
- `tests/Feature/Workforce/AuthorizationCodePurposeTest.php` (nuevo, 18 pruebas).
- `tests/Feature/Workforce/KioskWorkdayFlowTest.php`: 2 aserciones pasan al código específico; la propiedad de seguridad que comprueban se mantiene.

**CRM** (rama `hotfix/authorization-codes` sobre `f40df2c`):
- `staff-supervision/fallback-code-dialog.ts`: sin opción por defecto cuando caben las dos; dice dónde se escribe.
- `staff-supervision/staff-labels.ts`: `fallbackCodeWhere()`.
- `staff-supervision/biometric-panel.ts`: dónde se escribe el código de rostro.
- `staff-record.spec.ts` y `staff-labels.spec.ts`: pruebas.

**Tablet:** sin cambios.

## 5. CORRECCIÓN IMPLEMENTADA

- **La validación no se relaja.** Siguen rechazándose los códigos caducados, reutilizados, de otra persona y de otra operación. Con un código vivo de la operación correcta, un número equivocado sigue contando intento, y a los 5 se bloquea.
- **Sin código vivo de esa operación**, la respuesta dice por qué y añade, si la persona tiene un código vigente de **otra** operación, para qué sirve y dónde se escribe:
  - `authorization_code_used`: ya se usó;
  - `authorization_code_expired`: caducó;
  - `authorization_code_not_issued`: no hay ninguno.

  El número tecleado **no se compara** con los códigos de otras operaciones, así que no hay oráculo.
- **Cada rechazo queda en el historial de biometría del CRM** («Código rechazado: Para iniciar o finalizar clases: no había un código vigente (tenía uno vigente para registrar el rostro)»). No se guarda ni el código ni su hash.
- **El registro está acotado**: como mucho 5 rechazos iguales (misma persona, operación y motivo) cada 15 minutos, igual que los fallos de rostro. Así repetir un código falso no tapa el resto del historial. Lo pidió la revisión de seguridad.
- **CRM:** hay que elegir el tipo a mano cuando caben los dos, y cada código dice dónde se escribe y para qué no sirve.

## 6. PRUEBAS APROBADAS

- **Backend, pruebas nuevas:** 18/18.
  - Contra el código desplegado fallaban las 8 del comportamiento nuevo (y 1 daba error); pasaban las de regresión.
  - La de la auditoría acotada falló antes de corregir: 8 rechazos daban 8 filas.
  - Cubren los 16 casos pedidos: correcto, incorrecto, vencido, usado, otra persona, otro propósito, uso doble, ceros iniciales, registro facial, renovación, inicio de clase, fin de clase, entrada laboral, salida laboral, permisos y compatibilidad con la tablet (códigos de error y mensajes).
- **Backend, personal:** 218/218.
- **Backend, suite completa:** 8839 pruebas. Fallan **las mismas 58, una a una, con y sin el hotfix** (comparado por nombre contra `e1e8577` limpio): contratos de socio (29), facturación electrónica (26), exposición de secretos (2, depende del entorno local) y precios (1). **El hotfix no añade ninguna.**
- **CRM, módulo de personal:** 76/76. La prueba nueva del diálogo **falla con el original** (venía marcada la jornada) y pasa con el corregido.
- **CRM, suite completa:** 1573 correctas y 16 fallidas. Las **16 mismas fallan en `origin/main` sin estos cambios** (preexistentes, de otros módulos).
- **CRM:** literales correctos; compilación de producción correcta.
- **Revisión de seguridad:** **sin bloqueantes**.
  - No hay oráculo: el número escrito no se compara con otros propósitos.
  - Los intentos siguen en 5 por código.
  - La exposición de información es baja: solo metadatos operativos, nunca el código ni su hash.
  - Hallazgo bajo, corregido: la auditoría no tenía límite.
- **Concurrencia:** el bloqueo (`lockForUpdate`) no se puede ejercitar en SQLite. Se probó el doble envío secuencial: se gasta una sola vez.

## 7. CAMBIOS DESPLEGADOS

**Ninguno.** Falta tu autorización.

Plan preparado:
- **Backend:** commit + push + `git merge --ff-only` en el VPS. PHP recoge los cambios solo (opcache revalida cada 2 s), sin reinicios ni migraciones.
- **CRM:** `npm run build:prod` + `rsync` **sin** `--delete`.
- **Rollback:** backend con `git revert` + ff; CRM volviendo a desplegar el build de `f40df2c`.

## 8. ¿NECESITA APK NUEVA?

**No.** La APK 2.0.4+15 muestra el mensaje del servidor para los códigos de error que no conoce (`workforceMessage` → `serverMessage`). Los nuevos mensajes llegan a la tablet tal cual. Verificado en el código de la APK (`d57e836`).

## 9. ¿CÓMO REGISTRAR EL ROSTRO AHORA?

Ya, sin esperar ningún despliegue:

1. CRM → Personal → Juan Alejandro Horta → «Abrir biometría» → **«Autorizar enrolamiento»** → motivo → «Generar código». Hacerlo con Juan junto a la tablet: vale 30 min.
2. Tablet → **«Acceso para empleados» → «Clases y portal profesional» → «Ingresar con rostro»** → identificarse con documento o nombre.
3. Tocar el **botón «Registrar mi rostro»**, no el enlace «¿Problemas con la cámara? Usar código de administrador».
4. Escribir el código, Juan acepta el consentimiento y mira 3 veces a la cámara. No abandonar: hay 10 minutos.

## 10. ¿CÓMO INICIAR CLASES SIN CÓDIGO?

Con el rostro ya registrado: portal del entrenador → «Mis clases» → la clase de hoy → **Iniciar**, mirando a la cámara. Finalizar funciona igual. No hace falta ningún código; queda registrado como `face`.

Solo si el rostro falla: CRM → «Código de respaldo» → **«Iniciar o finalizar una clase»**, elegido a mano. Se escribe en «Usar código de administrador». Hace falta uno para iniciar y otro para finalizar.

## 11. PENDIENTES

1. **Autorización** para commit, push y despliegue de backend y CRM.
2. **Prueba real en la tablet** con Juan: registro de rostro → iniciar y finalizar una clase por rostro. **Hasta entonces, el problema no está declarado resuelto.**
3. Tras desplegar: comprobar en producción un rechazo real (mensaje en la tablet y entrada en el historial).
4. Yuri: su código de rostro quedó gastado sin plantilla; hace falta uno nuevo, con ella presente.
5. Opcional (APK futura): la tablet podría explicar mejor el enlace «Usar código de administrador» junto a «Registrar mi rostro». No es imprescindible.

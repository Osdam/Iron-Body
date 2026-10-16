# Informe de pruebas

Regla aplicada: una prueba solo cuenta si se ejecutó y su resultado está registrado. «Línea base» = la misma suite sobre el código de producción del mismo día, para separar fallos previos de fallos nuevos.

## Líneas base y resultados de las suites completas

| Momento | Backend (SQLite, `phpunit`) | CRM (`npx ng test --watch=false`) |
|---|---|---|
| Base `808516d` / `3701558` (15-10) | 8.558 pruebas, **56 fallos previos** (facturación electrónica/Factus, contratos por plantilla ausente en el entorno local, etc.) | 1.507 pruebas, **16 fallos previos** (moderación, control de acceso, tiempo real) |
| Despliegue 1 `b1ce780` / `5886982` | 8.684, los mismos 56 | 1.581, los mismos 16 |
| Despliegue 2 `43e6206` / `6a1566c` | 8.773, los mismos 56 (1 renombrada, misma causa de entorno: falta el PDF de plantilla en `storage`) | 1.587, los mismos 16 |
| Despliegue 3 `ed2aabb` | 8.821, los mismos 56 que producción (0 nuevos, 0 desaparecidos) | sin cambios en el CRM |

Además: `npm run check:literals` correcto y `ng build --configuration=production` sin errores en cada versión del CRM; Pint limpio en los ficheros tocados.

## Por capas

| Capa | Qué se probó | Resultado | Evidencia |
|---|---|---|---|
| 1 Unitarias | Servicios nuevos (ficha, vínculo, traslado, condiciones, huella), `ZktecoTurnstileService` | Verde | Ficheros `tests/Feature/Workforce/*`, `tests/Feature/Plans/*`, `tests/Unit/ZktecoErrorDetailTest.php` |
| 2 Integración | Rutas reales con base de datos; fixture tipo Yuri; lote de 25 y 26 socios; reversión parcial | Verde | `StaffRecordTest`, `MemberOwnershipLinkTest`, `PlanTransferExecutionTest`, `PlanTransferReversalTest` |
| 3 Autorización | Recepción, Entrenador, Administrativo, token compartido y anónimo en todas las rutas nuevas (también GET); guarda de `readOnly()` | Verde | `PlanTransferPermissionsTest`, `StaffRecordTest`, `RolePolicyTest`, `AuthorizationCoverageTest` |
| 4 Seguridad | Cada hallazgo con una prueba que **falla sin la corrección** (se deshizo la corrección para comprobarlo): borrado público, reescritura por register, por correo, reanudación ajena, pasos sobre activos, rostros con token compartido, torniquete, SSRF (60 cargas), H1 (código del vínculo no abre la app), N1, N3, `/crm/*` | Verde | `MemberRegistrationOwnershipTest` (41), `MemberRegistrationTakeoverTest`, `FaceBiometricAccessTest`, `TurnstileAccessTest`, `MemberLinkBoundChannelTest`, `LoginResendPurposeTest`, `IdentityAndContractDocumentsRequirePersonTest`, `LegacyWebCrmDisabledTest`; 4 revisiones independientes de api-security |
| 5 Regresión | Suite completa contra la línea base del mismo día (tabla de arriba) | 0 fallos nuevos | junit en el scratchpad de la sesión |
| 6 Compatibilidad | Contratos del kiosko (11 rutas) frente a la APK de empleados; flujo de alta de la APK publicada (register → identity → contrato → rostro / omitido; reanudación; reintento tras cambio de red); login OTP y re-enrolamiento facial; lector local (sesión de Recepción) | Verde | Pruebas de las ramas + lectura del código de la app; `test_el_lector_de_la_sede_hace_su_recorrido_completo_con_sesion_de_recepcion` |
| 7 Migraciones | `migrate`, `migrate:rollback --step=2`, `migrate` en PostgreSQL 14; `migrate --pretend` en producción antes de aplicar | Verde | 2 migraciones aditivas aplicadas en 47 ms y 20 ms con `lock_timeout=5s` |
| 8 Interfaz | Specs con componentes reales (ficha, alta con comprobación, vínculo, traslado, Punto físico); E2E con Chrome headless en 1440×900 y 375×812 contra backend local | Verde | `staff-record.spec.ts`, `plan-transfers.spec.ts`, `attendance-employee-access.spec.ts`; capturas `e2e/shots/` |
| 9 Entorno desplegado | Pruebas de humo HTTP y prueba autenticada dentro de una transacción revertida (0 escrituras) | Verde | `INFORME_DESPLIEGUE_PRODUCCION.md` |
| 10 Físicas | Rostro y jornada de Yuri en la tablet; lector local con una persona sin membresía; entrega real de SMS del vínculo | **PENDIENTES** (requieren personas presentes y autorización) | `CHECKLIST_VALIDACION_OPERATIVA.md` |

## PostgreSQL 14 (lo que SQLite no ve)

Clústeres locales desechables. Resultados en la versión integrada: 437 pruebas de las áreas tocadas y 509 de login/OTP/accesos con **los mismos fallos que la base en el mismo motor** (3 previos: un trigger con sintaxis de SQLite y dos 500 ante un uuid inválido en el login adaptativo). `FOR UPDATE`, orden de bloqueos, unicidad dentro de transacción y huella iguales entre motores: verificados. Se corrigió un defecto de aislamiento de las propias pruebas (el truncado borraba los roles sembrados).

## Pruebas de extremo a extremo (local, datos ficticios)

Casos A (ficha laboral y vínculo), B (traslado de 26 socios escribiendo exactamente «Élite», concurrencia real con 8 workers, reversión parcial, plan por entradas) y C (no regresión): todos **PASS**. Jornada de administrativo por la API real del kiosko con un dispositivo de prueba: entrada 201, reintento idempotente 200, salida 200, sin puntualidad.

## No ejecutado (y por qué)

- Enrolamiento facial real y jornada completa de Yuri: requieren su presencia y consentimiento.
- Software local de ingreso: código no disponible; solo evidencia del servidor.
- Envío real de SMS del vínculo: bandera apagada; requiere remitente configurado y persona presente.
- Escrituras reales del traslado en producción: no autorizadas.
- `DELETE members/{id}` contra fichas reales: no se prueban operaciones destructivas en producción (se verificó la ruta con `route:list`).

# Estado final — 16-10-2026

## Veredicto: **PARTIAL_VERIFICATION**

Todo el trabajo técnico posible dentro de lo autorizado está implementado, probado, desplegado y verificado en producción. No es `PRODUCTION_VERIFIED` porque quedan verificaciones **obligatorias** que requieren personas presentes o autorización del propietario: la biometría y la jornada reales de Yuri, la verificación del software local de ingreso, y la retirada del plan comercial de empleados (bloqueada por el software local).

## Producción ahora

| | Versión |
|---|---|
| Backend | `ed2aabb` (`main`) |
| CRM | `6a1566c` (`main`, `main-VLQ5RT26.js`) |
| Migraciones | 299 (2 nuevas y aditivas: `plan_transfer_batches`, `plan_transfer_items`) |
| Workers | 12 RUNNING |
| Banderas | `WORKFORCE_MEMBER_LINK_ENABLED=false`, `LEGACY_WEB_CRM_ENABLED=false` (por defecto) |
| Respaldos | 3 nuevos en `/var/backups/ironbody/` (verificados); 2 clústeres de restauración parados en `/var/lib/postgresql/restore-check-*` |

## Criterios de aceptación

| Criterio | Estado |
|---|---|
| Vulnerabilidad `members/register` corregida y probada | ✅ Desplegada y verificada en producción (y el resto del grupo público: borrado, pasos, listado) |
| Autenticación de socios sigue funcionando | ✅ Pruebas de login/OTP/re-enrolamiento; el lector y la app siguen operando (0 errores, 0 5xx) |
| Vinculación segura de identidades implementada y probada | ✅ Implementada y probada (código ligado al reto, rastro de teléfono). ⏳ Encenderla requiere `TWILIO_FROM` y una prueba con la persona presente |
| Ficha laboral funciona | ✅ Verificada por el propietario (Camilo, Yuri) y por pruebas |
| Roles laborales funcionan | ✅ |
| Permisos validados en el backend | ✅ (Recepción 403 en Personal y Traslados; token compartido 403 en rostros, torniquete, cédulas y contratos) |
| Yuri conserva cargo Administrativo y jornada flexible | ✅ (perfil activo; sin horario ni puntualidad) |
| Tablet reconoce empleados | ✅ Verificado por el propietario |
| Biometría laboral con personas reales | ⏳ PENDIENTE: Yuri sin plantilla (su código de enrolamiento de las 05:27 caducó sin usarse) |
| Entrada y salida funcionan | ✅ En local con la API real del kiosko. ⏳ Jornada real de Yuri pendiente |
| EmployeeAccess integrado con la autorización de ingreso | ✅ Backend y Punto físico del CRM. ⏳ Software local: **bloqueado** (evidencia de que decide por su cuenta) |
| Software local verificado o bloqueo explícito | ✅ Bloqueo explícito documentado (`INFORME_INTEGRACION_SOFTWARE_LOCAL.md`) |
| No depender de planes comerciales artificiales | ⏳ En el backend no hace falta; en la puerta física todavía probablemente sí |
| Plan «INGRESO EMPLEADOS» auditado | ✅ (2 nombres, 8 personas, 7 dependen del plan) |
| Retirada preparada | ✅ `PLAN_RETIRO_INGRESO_EMPLEADOS.md` (no ejecutada) |
| Herramienta de traslados funciona | ✅ |
| Dry-run actualizado y validado | ✅ 27 elegibles a las 12:38 UTC (cambia porque el plan 22 se sigue vendiendo) |
| Plan 22 revisado | ✅ Sigue vendible y visible (4 ventas desde anoche); retirada preparada, no ejecutada |
| Facturación íntegra | ✅ Ningún cambio toca pagos ni facturas; 0 escrituras en las pruebas de producción |
| Historial comercial íntegro | ✅ |
| Flutter compatible | ✅ No hace falta APK. Regresión aceptada: «Registrar rostro» del perfil para socios activos |
| Repositorios sincronizados | ✅ `main` = producción en los dos repos; ramas de trabajo publicadas |
| Despliegues autorizados verificados | ✅ 3 despliegues con respaldo, smoke y monitoreo |
| Respaldos disponibles | ✅ |
| Informes completos | ✅ 12 en `docs/entrega-2026-10-16/` |
| Sin errores críticos conocidos sin corregir o documentar | ✅ Los 2 críticos encontrados (borrado público, `/crm/*` abierto) están corregidos; los abiertos son bajos y documentados |

## Operaciones pendientes del propietario

Ver `CHECKLIST_VALIDACION_OPERATIVA.md`: biometría y jornada de Yuri; software local (código/configuración o piloto); conceder `EmployeeAccess` a los 7 dependientes y después dejar caducar el plan; retirar el plan 22 de la venta y ejecutar el traslado con nueva simulación; encender el vínculo por SMS; decidir sobre los clústeres de verificación de respaldos.

## Riesgos abiertos (documentados)

- Software local: probablemente no respeta `EmployeeAccess`; deja pasar membresías que aún no empiezan o vencidas (106 en 30 días).
- Registro público: el teléfono no prueba posesión (cierre robusto = OTP en el registro, exige APK nueva); dentro de 15 min y desde la misma red se puede adelantar un rostro en un alta ajena.
- Torniquete: SSRF ciego hacia la LAN y la IP propia (N2); reutilización de la credencial guardada al cambiar el host (N4).
- 94 socios activos sin ningún teléfono (no pueden recibir OTP).
- La baja laboral no retira el `EmployeeAccess` manual ni la imagen de acceso; las imágenes faciales de acceso no están cifradas.
- Fallos previos: anular un cobro no restaura el plan; 500 en PostgreSQL ante un uuid inválido en el login adaptativo; Punto físico calcula el acceso solo al abrir.

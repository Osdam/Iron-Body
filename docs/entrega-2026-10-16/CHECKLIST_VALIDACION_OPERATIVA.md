# Checklist de validación operativa

Para el propietario y administración. Cada punto dice quién lo hace, qué hace falta y cómo saber que salió bien. Ninguno se ha ejecutado por iniciativa técnica: requieren autorización o personas presentes.

## A. Pruebas con personas presentes (requieren consentimiento)

- [ ] **Biometría laboral de Yuri**: Ficha → Abrir biometría → Autorizar enrolamiento → en la tablet, Yuri introduce el código, acepta el consentimiento y hace las 3 capturas.
  Correcto si: la ficha muestra la biometría «Registrada». Nunca usar el rostro de otra persona.
- [ ] **Jornada completa de Yuri**: entrada con rostro (o código de respaldo) → salida horas después.
  Correcto si: Supervisión de empleados y clases → Empleados muestra entrada, salida y duración reales, sin puntualidad.
- [ ] **Entrada repetida**: intentar una segunda entrada con la jornada abierta → la tablet la rechaza.

## B. Software local de ingreso (bloqueo de verificación)

- [ ] Conseguir del proveedor o del equipo de recepción: código o versión instalada, configuración (URL, cuenta usada, intervalo de sincronización, modo sin conexión), qué campos usa para decidir (¿`access.can_enter` o fecha de vencimiento?).
- [ ] **Piloto autorizado**: una persona trabajadora, sin membresía vigente y con `EmployeeAccess` activo, frente al lector.
  Correcto si: el torniquete abre y queda una asistencia con `access_via=employee`. Si no abre, el lector debe pasar a usar `access.can_enter`.
- [ ] Confirmar que la cuenta de Recepción que usa el lector conserva su permiso de registrar asistencias (si se le quita, las entradas se pierden en silencio).

## C. «INGRESO EMPLEADOS» (después de B)

- [ ] Conceder `EmployeeAccess` a los 7 dependientes (users 584, 670, 7742, 1026, 1032, 7517, 7521), uno a uno, con motivo.
- [ ] Alta del perfil laboral de quienes trabajan aquí y no lo tienen (confirmar cargo de 584, 670, 7517).
- [ ] No renovar el plan; dejar que caduque. No borrar el plan 16 (lo referencian pagos).
- Detalle: `PLAN_RETIRO_INGRESO_EMPLEADOS.md`.

## D. Traslado «TOTAL ACCESS - ELITE» → «Élite» (autorización independiente)

- [ ] Retirar el plan 22 de la venta: Planes → editar → desmarcar «Vendible» y «Visible en la app» (no borrarlo).
- [ ] El mismo día: Planes → Trasladar socios → simular de nuevo y revisar (hoy: 26 elegibles, 6 con advertencias).
- [ ] Ejecutar con motivo. Correcto si: 26 ítems aplicados, fechas iguales, ningún cobro ni factura nuevos.
- Detalle: `GUIA_MIGRACION_PLANES.md`.

## E. Vínculo verificado de la ficha de socio (opcional)

- [ ] Configurar en el servidor un remitente de SMS normal de Twilio (`TWILIO_FROM`) válido para Colombia.
- [ ] Con una persona del personal que sea socia y esté en el mostrador: encender `WORKFORCE_MEMBER_LINK_ENABLED=true`, vincular y comprobar que el SMS llega por Twilio Messaging (no Verify) y que el mismo código no abre la app. Apagar si algo falla.
- [ ] Vincular la ficha de socio de Yuri solo así, con ella presente.

## F. Decisiones pendientes

- [ ] ¿Los trabajadores sin plan deben tener la app completa (IRON IA, clases, nutrición)? Hoy la app exige membresía activa.
- [ ] ¿La baja laboral debe retirar automáticamente el `EmployeeAccess` manual y la imagen facial de acceso?
- [ ] ¿Se borra el clúster de verificación de respaldo (`/var/lib/postgresql/restore-check-…`, 1,1 GB con datos personales)?
- [ ] «Registrar rostro» del perfil de la app: queda bloqueado para socios activos (decisión de seguridad del 16-10-2026). Recuperarlo de forma segura exige una APK nueva.

## G. Comprobaciones rápidas después de cada despliegue

- [ ] `https://ironbodyneiva.cloud/version.json` muestra el commit desplegado.
- [ ] El CRM carga Personal y biometría, Planes → Trasladar socios y Punto físico.
- [ ] Recepción no ve Personal ni la herramienta de traslados.
- [ ] La tablet sigue identificando al personal.

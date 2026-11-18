# Informe final: ciclo de valoraciones (18-11-2026)

**Base:**
- auditoría de `auditoria-ciclo-2026-11-18/`;
- contratos `CONTRATO_CICLO_VALORACIONES.md` y `CONTRATO_SOLICITUDES_VALORACION.md`.

**Desplegado en producción:**
- backend `3c5c68f1`, a las ~20:08 UTC;
- CRM `e2ffe6c`, a las 20:10 UTC;
- reconciliación aplicada a las 20:12 UTC.

**App:** parche OTA en *staging* (§17).

## 1. Finalización automática
Al «Enviar al miembro» (`ProfessionalAssessmentService::submit`), el servidor bloquea la valoración y busca la cita abierta del mismo socio el mismo día de America/Bogota.
- **Con exactamente una candidata del mismo entrenador:** la completa y la enlaza en la misma transacción.
  - `completed_at` = fecha real del envío;
  - historial de la cita con origen `trainer_assessment`;
  - recordatorios pendientes cancelados.
- **Sin candidatas:** aviso «Valoración realizada sin cita».
- **Varias candidatas, o una de otro entrenador:** aviso «Valoración por revisar», sin tocar ninguna cita.
- **Nunca cierran citas:** los borradores ni las enmiendas.
- **Doble envío concurrente** (probado en PostgreSQL con dos procesos): un cierre, un aviso y una evaluación física. Antes del cambio, el doble envío generaba **dos** evaluaciones físicas: fallo demostrado y corregido.

## 2. SSE
Los avisos se escriben **después del commit**, con `event_key` idempotente, en la bandeja del CRM. Viajan por el stream que ya existía (`/api/admin/notifications/stream`), con `required_permission = valuations.view_all`.
- **Refresco del CRM:** la agenda, el panel de seguimiento y la lista de solicitudes se recargan al llegar un aviso `valuation`, una sola vez por aviso aunque llegue dos veces.
- **Si el CRM estaba desconectado:** la fila queda en la bandeja.
- **Respaldo:** el sondeo existente.

## 3. Aviso administrativo
- **«Valoración completada»:** entrenador, socio (nombre corto) y fecha. Abre la cita.
- **«Valoración realizada sin cita»** y **«Valoración por revisar»:** abren el seguimiento del socio.
- **«Solicitud de valoración»:** abre la solicitud.
- **Sin medidas** ni datos de salud.
- Los avisos nuevos **no suenan**: la voz diría «valoración próxima». Quedan en la campanita.

## 4. Historial
Pestaña **Seguimiento** del módulo de valoraciones. Por socio muestra:
- última valoración (fecha, entrenador y origen);
- estado;
- intervalo y su origen;
- próximo control sugerido;
- próxima cita;
- historial cronológico, en el que una cita completada y su valoración enlazada salen en una sola fila.

## 5. Buscador
`/api/admin/valuations/members/search`:
- ignora mayúsculas y tildes («Mari» encuentra «María», «NUÑEZ» encuentra «Núñez»), en PostgreSQL y en SQLite;
- debounce en el CRM;
- con el alcance de la agenda: una cuenta vinculada a un entrenador solo ve a sus socios asignados.

## 6. Intervalos personalizados
- **Opciones:** 15, 20, 30, 60 y 90 días, «Personalizar intervalo» (1–365), «Usar el del gimnasio», «Fecha puntual» y «Pausar» o «Reactivar».
- **Precedencia:** socio > gimnasio, que tiene **60 días**. El nivel de plan **no se implementó**: no existe ninguna regla comercial validada.
- **Cambios:** con versión optimista y auditados (antes y después).

## 7. Próximo control sugerido
- **Cálculo:** fecha de la última valoración + intervalo efectivo, en días de calendario y en America/Bogota.
  - Desde el 18-11-2026: +15 → 03-12, +20 → 08-12, +30 → 18-12, +60 → 17-01-2027, +90 → 16-02-2027.
- **Se calcula al leer:** nunca reescribe el histórico.
- **Fecha puntual:** caduca cuando hay una valoración posterior a la última edición.
- **No es una cita:** una fecha sugerida nunca reserva un horario.

## 8. Reagendamiento
«Programar próxima valoración» abre el **asistente existente** con:
- el socio ya elegido;
- el entrenador sugerido;
- la fecha sugerida.

Si ya hay una cita futura, el servidor responde 409 con esa cita y el CRM ofrece «Conservar» o «Reprogramar». **Nunca hay dos citas futuras:** probado con dos peticiones simultáneas en PostgreSQL.

## 9. Citas históricas reconciliadas: **23**
- **Comando:** `valuations:link-assessments`, ensayo y después `--apply`.
- **Cifras:** citas completadas 1 → 24 y enlazadas 0 → 23.
- **Sin efectos colaterales:** 268 avisos antes y 268 después (ningún aviso), y las evaluaciones físicas y valoraciones no cambian.
- **Verificación independiente** de los 23 pares: 23/23 con valoración enviada, mismo socio, mismo entrenador, mismo día de Bogotá y cita abierta sin enlace. Son 23 valoraciones y 23 citas distintas.
- **En cada una:** `completed_at` = fecha real del envío, traza `historical_reconciliation` y 0 recordatorios pendientes.
- **Idempotencia:** la segunda ejecución enlaza 0.

## 10. Casos excluidos
- **Cita ya cerrada o valoración ya enlazada:** 4.
- **Cita cancelada:** 1.
- **Ambiguos y de otro entrenador:** 0.
- **Siguen en «Programada»:** **20 citas** que no tienen una valoración el mismo día. Hay que cerrarlas a mano, como completadas o «no asistió».

## 11. Detector de 60 días
`evaluation.outdated` solo se emite para socios con:
- membresía vigente;
- seguimiento activo;
- próximo control vencido;
- sin cita futura.

Además:
- **una vez por vencimiento**, con clave `evaluation.outdated:{socio}:{fecha}`;
- **ya no** se emite para quien nunca se valoró.

**Compatibilidad:** la misma forma de evento que espera el enrutador de n8n. **Tareas de entrenador:** como mucho una abierta por socio.

**Antes:** unos 4.000 eventos al mes, todos «sent», sin ningún aviso entregado a socios.

## 12. Horarios de silencio
Los push al socio de citas pasan por la regla de silencio:
- horas de silencio del socio y ventana del gimnasio (7–22 Bogotá);
- si el aviso aún sirve, se pospone a la siguiente hora permitida;
- si no, queda en la app sin push.

Confirmación y reprogramación ahora son push. El push lleva `action_route` (`/evaluation`), así que al tocarlo abre Valoración física. **No se tocó** `NotificationDispatcher`, `CampaignService` ni las campañas. La campaña #4 sigue programada.

## 13. Pruebas
**Backend, pasada de integración** (valoraciones, notificaciones, accesos, automatización, entrenadores, admin y seguridad):

| Motor | Resultado |
|---|---|
| SQLite | 1.794 pruebas, 0 fallos |
| PostgreSQL | 1.794 pruebas; solo 2 rojos de entorno que ya existían en `main` (trigger con sintaxis de SQLite; exige un rol no superusuario) |

**Tras los arreglos de la revisión:** carpeta de valoraciones en verde (263) en los dos motores.

**Carreras en PostgreSQL con procesos reales:**
- doble envío;
- dos «próxima cita» simultáneas;
- dos solicitudes simultáneas;
- agendar frente a rechazar.

**Mutaciones:** las pruebas fallan sin cada arreglo.

**CRM:**
- suite completa: 4.524 en verde y los mismos 16 fallos que ya hay en `main`;
- build de producción y literales, en verde.

**App:** 26 + 3 pruebas nuevas; 169 de progreso, navegación, notificaciones y OTA; `flutter analyze` sin avisos nuevos.

**Revisión independiente:** APROBADO CON NOTAS, sin bloqueantes. Las 3 notas menores están corregidas: alcance del buscador, citas de otros entrenadores en el panel y un comentario.

## 14. Commits y push

| Repositorio | Commits | Push a `main` |
|---|---|---|
| Backend | `0d115e0f` (informes de auditoría), `3c5c68f1` (implementación) | `8a4b51d1..3c5c68f1` |
| CRM | `e2ffe6c` | `ce695d7..e2ffe6c` |
| App | `46a9422` | `2812117..46a9422` |

## 15. Despliegue y salud
**Respaldos** en `/var/backups/ironbody/`:

| Respaldo | Contenido |
|---|---|
| `ciclo-valoraciones-20261118-200832` | Volcado completo (51 MB, con sha256), commit previo `45f18683` y cachés |
| `crm-pre-e2ffe6c-20261118-201045` | CRM previo |
| `reconciliacion-valoraciones-20261118-201202` | Volcado justo antes de reconciliar |

**Migraciones:** las 4 aplicadas.
- `iron_app` con privilegios en las tablas nuevas;
- los índices `valuation_appointments_assessment_uq` y `valuation_requests_one_pending_per_member`;
- `follow_up_interval_days` = 60.

**Salud:**
- 14 de 14 workers en marcha;
- 0 errores y **0 respuestas 5xx en 2.701 peticiones** tras desplegar;
- las rutas nuevas responden 401 sin sesión.

**CRM público:** `e2ffe6c`, con el `main` nuevo y el anterior respondiendo 200.

## 16. Riesgos residuales
- **«Solicitar valoración» en la app** llega por el parche OTA. Mientras no esté en `stable`, el backend y el CRM lo soportan, pero el botón no aparece a los socios. Las versiones ≤ 2.0.9 no reciben parches.
- **Detector:** el lunes 23-11 a las 09:00 cambia la clave del evento, así que los socios que ya recibieron el de noviembre y siguen vencidos recibirán uno más.
- **«Valoración realizada sin cita»** será un aviso frecuente; es lo que pide el contrato.
- **Canal al socio:** está encendido, y ahora la confirmación y la reprogramación llevan push, respetando el silencio.
- **Solicitudes:**
  - anonimizar a un socio no cierra su solicitud pendiente;
  - no existe «cancelar solicitud» desde la app.
- **Seguimiento:** si se pone en blanco el intervalo del gimnasio, quien no tenga uno propio queda «al día» sin próximo control, porque no hay un estado propio de «sin seguimiento».
- **Ya existían:**
  - el canal de avisos del CRM puede quedar muerto si se inicia sesión sin recargar (hipótesis H-1);
  - la app no permite corregir una valoración enviada;
  - más hallazgos de la auditoría, §«Hallazgos adyacentes».

## 17. App: parche OTA
Va en `46a9422` (solo Dart: sin dependencias, cambios nativos ni assets).
- Se publica primero en *staging* (`docs/OTA_SHOREBIRD.md`) y luego en `stable` tras probarlo en un dispositivo.
- Estado: ver la nota al final.

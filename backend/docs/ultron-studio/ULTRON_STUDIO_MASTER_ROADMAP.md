# ULTRON STUDIO: hoja de ruta maestra

Estado REAL del proyecto, actualizado después de cada despliegue. Un hito solo se marca completado cuando está desplegado, verificado y con informe.

| Hito | Contenido | Estado | Evidencia |
|---|---|---|---|
| **1. Auditoría y arquitectura** | Auditoría del motor real (Laravel + n8n), diez capas, decisiones (Laravel conserva la autoridad; sin Python) | **COMPLETADO** (17-10-2026) | `ULTRON_STUDIO_ARCHITECTURE_AUDIT.md`, `ULTRON_STUDIO_TECH_STACK.md` |
| **2. Frontend visual** | Tablero Foblex Flow con las diez capas, conexiones del motor, inspector, validación; vista previa protegida | **COMPLETADO** (desplegado 18-10-2026 02:56 UTC, CRM `4f7d774`; en GitHub) | `ULTRON_STUDIO_VISUAL_REPORT.md` |
| **3. Motor de control** | **F** persistencia (borradores, versiones, reglas, auditoría, permisos `ultron.*`, catálogo servido por el backend) · **G** control funcional de las diez capas (configuración real por capa, lecturas del motor de solo lectura, reglas con condiciones verificables, historial y comparación) | **COMPLETADO** (`HITO_3_COMPLETED`) — F desplegado y verificado el 18-10-2026 15:28 UTC (backend `9acddc3`, CRM `020db86`); G desplegado y verificado el 19-10-2026 06:00–06:04 UTC (backend `e651dd9`, código `dfb30f5`; CRM `44c0e8c`): migración aditiva, 21 rutas, 8 reglas del código resincronizadas, smoke en producción 29/29, E2E con pila real 126/126, revisiones de `security`, `api-security-specialist`, `tester` y `reviewer` cerradas | `ULTRON_STUDIO_G_CONTRACT.md`, `ULTRON_STUDIO_BACKEND_REPORT.md`, `ULTRON_STUDIO_SECURITY_REPORT.md`, `ULTRON_STUDIO_VISUAL_REPORT.md`, `ULTRON_STUDIO_FINAL_REPORT.md` |
| **4. Laboratorio de IA** | Escenarios sintéticos (catálogo de 12 y del personal), simulaciones del Studio (no es ULTRON), pruebas deterministas con las guardas puras del motor, evaluación asistida (OpenAI con clave propia, `store: false`), propuesta del laboratorio y veredicto de una persona con cuatro ojos; worker y libro propios | **IMPLEMENTADO, REVISADO Y VERIFICADO EN RAMA — pendiente de despliegue por fases** (19-10-2026; rama `feat/ultron-studio-h4`, backend `6dbf485`, CRM `3e630eb`). **No es `HITO_4_COMPLETED`:** eso exige desplegarlo, una ejecución real con OpenAI validada y un veredicto de una segunda persona; sin la clave del propietario queda en `HITO_4_IMPLEMENTED_WITH_EXTERNAL_DEPENDENCY`. Revisiones de `security`, `api-security-specialist` y `tester` cerradas, con sus correcciones en `6dbf485` y en el CRM; validación del tester sobre el código final con el worker real (carreras 10/10 y 21 casos límite), con un hallazgo abierto sin gasto (H-P2-1) cuya corrección decide la Enmienda 5 (`403a12b`), pendiente de implementar; E2E con la pila real 122/122; Studio en PG 934/934. Revisión final del `reviewer`: <!-- PENDIENTE: veredicto del reviewer --> pendiente al escribir esto | `ULTRON_STUDIO_H4_CONTRACT.md`, `ULTRON_STUDIO_AI_EVALUATION_REPORT.md`, `ULTRON_STUDIO_BACKEND_REPORT.md`, `ULTRON_STUDIO_SECURITY_REPORT.md`, `ULTRON_STUDIO_VISUAL_REPORT.md`, `ULTRON_STUDIO_FINAL_REPORT.md` («Hito 4»), runbook en `ULTRON_STUDIO_DEPLOYMENT_PLAN.md` |
| **5. Gobierno y versionado** | Ciclo completo borrador → validación → simulación → revisión → aprobación → canario → publicación; integración con el motor por contrato verificable | **PENDIENTE** (el ciclo hasta APPROVED ya existe desde F; publicar/revertir siguen bloqueados a propósito; el Hito 4 guarda la huella de la versión en cada ejecución para que este hito pueda exigir un veredicto del laboratorio antes de aprobar, si se decide) | — |
| **6. Integraciones comerciales** | Meta Ads (solo lectura), Twilio, números canarios, adaptador ElevenLabs (`ELEVENLABS_NOT_CONFIGURED`) | **PENDIENTE** | `ULTRON_STUDIO_META_REPORT.md`, `ULTRON_STUDIO_TWILIO_REPORT.md`, `ULTRON_STUDIO_ELEVENLABS_READY.md` (diseño) |
| **7. Seguridad y producción** | Revisión integral, runbooks, monitoreo, plan de activación canaria | **PENDIENTE** | Lista de «Pendientes del propietario (Hito 7)», más abajo |

## Protecciones vigentes (no se retiran sin autorización expresa)

- El motor de ULTRON **no lee** nada del Studio: `StudioPublishing::ENGINE_READS_VERSIONS = false` y `ULTRON_STUDIO_PUBLISHING_ENABLED` apagada → publicar y revertir responden 409 siempre.
- Sin llamadas reales, sin cambios en campañas Meta, sin ElevenLabs, sin consumo pagado sin autorización.
- La aplicación corre en PostgreSQL con el rol solo-DML `iron_app`; las migraciones, con el rol dueño `iron` mediante `ironbody-migrate`.
- **Tope de cuerpo de 64 KB** (65 536 bytes) en las 21 rutas del Studio, que responde 413 `payload_too_large`, y validación de listas en dos fases. Rige en producción desde el despliegue de G (19-10-2026, 06:00–06:04 UTC). En la rama del Hito 4 cubre también las 12 rutas `lab/*` (33 en total).
- **Puerta PostgreSQL obligatoria antes de cada despliegue del Studio:** `tests/Feature/UltronStudio tests/Unit/UltronStudio`, en verde en un PostgreSQL 14 desechable (934/934 en `6dbf485`). Solo PostgreSQL detecta que falta el cerrojo de las reglas o el del presupuesto del laboratorio, y solo PostgreSQL tiene los CHECK; la suite SQLite por defecto no.

**Protecciones del Hito 4** (en la rama; rigen en producción cuando se despliegue):
- **Cuatro cerrojos antes de gastar**, independientes: la bandera `ULTRON_STUDIO_LAB_ENABLED` apagada, el driver `fake`, el presupuesto diario 0 y la clave ausente o compartida con el motor. Con los cuatro abiertos, además: la estimación aceptada de forma explícita, la reserva atómica contra el tope diario (día de Bogotá) y el limitador de 10 lanzamientos por hora y persona.
- **Tope por ejecución en tiempo de ejecución:** el coste real de una ejecución no pasa de `min(tope por ejecución, máximo aceptado)` más, como mucho, un caso. Sin reintentos automáticos (`tries = 1`, sin `retry()`), con `max_output_tokens` en cada llamada, un caso a la vez, plazo de 30 min y parada tras 2 errores seguidos del proveedor.
- **Worker aislado:** `ironbody-lab`, un proceso en un fichero de supervisor aparte, en su propio carril (`studio-lab`, P5); no comparte cola con el puente de ULTRON a n8n.
- **La clave del laboratorio solo en el entorno del servidor:** nunca en DTOs, libro, logs ni cliente. `store: false` siempre y URL fija en producción.
- **Nada se aprueba solo:** la propuesta del laboratorio no es un veredicto; el veredicto lo emite una persona distinta del autor de la versión, uno por ejecución e inmutable, y no cambia la versión ni el motor.

## Pendientes del propietario (Hito 7)

Fuera del alcance de G (Enmienda 2 del contrato G); cada uno con su propia revisión:

- **`no-store` en la API administrativa.** Hoy responde `Cache-Control: no-cache, private`, y por ella pasan las lecturas del motor y la auditoría con nombres del personal, en puestos compartidos.
- **La clase de M-2 en otros controladores del CRM:** listas con comodín (`lista.*`) validadas sin un tope de tamaño previo. No se ha auditado fuera del Studio; el trait `ValidatesListSizesFirst` es genérico y se puede reutilizar.
- **`TrimStrings` y `ConvertEmptyStringsToNull`** recorren cuerpos de hasta `post_max_size` (8 MB) antes de que actúe el tope de ruta. Opciones: un tope por grupo `api`, o un `post_max_size` menor para JSON.
- **Caracteres invisibles** (U+061C y U+E0000–E007F, entre otros) que dejan pasar `PlainText` y la API interna de la base de conocimiento: riesgo de instrucciones ocultas al modelo. Revisión aparte, de `security` y `api-security-specialist`.
- **Datos personales en las reglas** (B-1 de `security`, Ley 1581). Falta el procedimiento documentado para redactar el libro de auditoría con el rol dueño si llega una solicitud de supresión (architect y devops). Opcional: rechazar teléfonos y correos por su forma. Hoy solo hay un aviso fijo en la interfaz. **Con el Hito 4, el procedimiento tiene que cubrir también las cinco tablas del laboratorio** (escenarios, respuestas del actor, opiniones del juez, notas de veredicto y libro), que tampoco se pueden borrar.
- **`StudioAudit::recordDenied` (F)** toma su cerrojo de deduplicación sin fijar READ COMMITTED; el del laboratorio ya lo fija (Enmienda 4.6).

## Pendientes del propietario (Hito 4)

Ninguno bloquea las fases 1 (apagado) y 2 (`fake`). Los cuatro primeros son condición de la fase 3:

- **Clave y proyecto de OpenAI:** un proyecto dedicado al laboratorio, con presupuesto propio en el panel de OpenAI y una clave nueva, distinta de `OPENAI_API_KEY` y de la credencial de n8n. La escribe el propietario en el servidor, nunca por chat.
- **Retención:** confirmar qué conserva OpenAI con `store: false` en su cuenta. Por defecto, los registros de vigilancia de abusos se conservan hasta 30 días (documentación oficial consultada el 19-10-2026).
- **Precios oficiales y modelo:** elegirlos con la página oficial de precios; los del plan son de terceros y solo orientativos.
- **Topes:** confirmar los propuestos (US$ 2 al día, US$ 0,50 por ejecución, 20 ejecuciones al día y 12 casos) o fijar otros.
- **`.env.example` con un valor sin comillas** en la configuración de facturación: `FACTUS_CONSUMER_FINAL_NAME=Consumidor final` (línea 812, del commit `02e24a9`) tiene un espacio sin comillas y Dotenv lo rechaza («Encountered unexpected whitespace») al copiarlo tal cual. No es del laboratorio; el arreglo es entrecomillarlo.
- **Colores globales de los avisos:** una regla global del CRM (`p, small, … { color: … !important }`) pisa el color de acento de todos los avisos de datos personales, los de G incluidos. Se leen con buen contraste; recuperar el color exige un selector que gane en las hojas del Studio, nunca en `ultron-studio.scss`.
- **Asistente flotante en móvil:** a 390 px, el asistente del shell tapa parte de «Archivar la regla» en G y flota sobre la esquina inferior derecha del laboratorio. Ya ocurría antes del Hito 4; no se comprobó si intercepta toques.

**Pendiente de implementar (Enmienda 5, `403a12b`):**
- **H-P2-1, ejecución huérfana** (tester, media-baja): si un paso falla antes de reclamar un caso, la ejecución queda en curso sin ningún paso y cancelar no la cierra. No gasta dinero ni bloquea otras; hay una salida operativa en el runbook. Decidido: que cancelar la cierre en el acto, que el plazo vencido se presente como `stopped: deadline` y que `failed()` sin caso reclamado reencole un paso acotado.
- **H-P2-2 y H-P2-3** (cobertura): la prueba estática vigilará también las escrituras crudas en la tabla de veredictos, y una prueba de dos sesiones cubrirá el «ocupado» dentro del reclamo.

**Decisión pendiente del lead:**
- **La etiqueta de `budget_exhausted` en el CRM** sigue diciendo «Se agotó el presupuesto del día», pero desde la Enmienda 4.1 la parada también puede venir del tope por ejecución o del máximo aceptado (el motivo exacto va en el libro, `budgetCap`).

## Registro de despliegues

| Fecha (UTC) | Qué | Backend | CRM | Respaldo |
|---|---|---|---|---|
| 18-10-2026 02:56 | Hito 2: vista previa visual | — | `4f7d774` | `/var/backups/ironbody/crm-pre-ultron-studio-20261018-025639/` |
| 18-10-2026 15:24–15:28 | Hito 3-F: persistencia (migraciones, API, permisos, rol `iron_app`) y CRM integrado | `9acddc3` | `020db86` | `/var/backups/ironbody/ultron-studio-f-20261018-151812/` |
| 19-10-2026 06:00–06:04 | Hito 3-G: control funcional (columna `conditions`, coherencia de reglas, lecturas del motor, comparación, inspector funcional) | `e651dd9` | `44c0e8c` | `/var/backups/ironbody/ultron-studio-g-20261019-060047/` (+ CRM `ultron-studio-g-crm-20261019-060251/`) |

# ULTRON STUDIO: hoja de ruta maestra

Estado REAL del proyecto, actualizado después de cada despliegue. Un hito solo se marca completado cuando está desplegado, verificado y con informe.

| Hito | Contenido | Estado | Evidencia |
|---|---|---|---|
| **1. Auditoría y arquitectura** | Auditoría del motor real (Laravel + n8n), diez capas, decisiones (Laravel conserva la autoridad; sin Python) | **COMPLETADO** (17-10-2026) | `ULTRON_STUDIO_ARCHITECTURE_AUDIT.md`, `ULTRON_STUDIO_TECH_STACK.md` |
| **2. Frontend visual** | Tablero Foblex Flow con las diez capas, conexiones del motor, inspector, validación; vista previa protegida | **COMPLETADO** (desplegado 18-10-2026 02:56 UTC, CRM `4f7d774`; en GitHub) | `ULTRON_STUDIO_VISUAL_REPORT.md` |
| **3. Motor de control** | **F** persistencia (borradores, versiones, reglas, auditoría, permisos `ultron.*`, catálogo servido por el backend) · **G** control funcional de las diez capas (configuración real por capa, lecturas del motor de solo lectura, reglas con condiciones verificables, historial y comparación) | **EN DESARROLLO** — F **DESPLEGADO Y VERIFICADO** (18-10-2026 15:28 UTC: backend `9acddc3`, CRM `020db86`); G **implementado y revisado, pendiente de despliegue**: rama `feat/ultron-studio-g`, backend `391cffb` (código en `dfb30f5`) y CRM `7d3ad2a`. Revisado por `security`, `api-security-specialist` y `tester`, con sus hallazgos corregidos o decididos. E2E con la pila real: 124/126, y los 2 fallos son de H-1 del E2E (CRM: «Ver S-NNN» no lleva a la regla), abierto. **Faltan el veredicto del reviewer y la decisión sobre H-1 y H-2 del E2E** | `ULTRON_STUDIO_G_CONTRACT.md`, `ULTRON_STUDIO_BACKEND_REPORT.md`, `ULTRON_STUDIO_SECURITY_REPORT.md`, `ULTRON_STUDIO_VISUAL_REPORT.md`, `ULTRON_STUDIO_FINAL_REPORT.md` |
| **4. Laboratorio de IA** | Escenarios, simulaciones, evaluación asistida (OpenAI con clave propia), veredicto con revisión humana | **PENDIENTE** | `ULTRON_STUDIO_AI_EVALUATION_REPORT.md` (diseño) |
| **5. Gobierno y versionado** | Ciclo completo borrador → validación → simulación → revisión → aprobación → canario → publicación; integración con el motor por contrato verificable | **PENDIENTE** (el ciclo hasta APPROVED ya existe desde F; publicar/revertir siguen bloqueados a propósito) | — |
| **6. Integraciones comerciales** | Meta Ads (solo lectura), Twilio, números canarios, adaptador ElevenLabs (`ELEVENLABS_NOT_CONFIGURED`) | **PENDIENTE** | `ULTRON_STUDIO_META_REPORT.md`, `ULTRON_STUDIO_TWILIO_REPORT.md`, `ULTRON_STUDIO_ELEVENLABS_READY.md` (diseño) |
| **7. Seguridad y producción** | Revisión integral, runbooks, monitoreo, plan de activación canaria | **PENDIENTE** | Lista de «Pendientes del propietario (Hito 7)», más abajo |

## Protecciones vigentes (no se retiran sin autorización expresa)

- El motor de ULTRON **no lee** nada del Studio: `StudioPublishing::ENGINE_READS_VERSIONS = false` y `ULTRON_STUDIO_PUBLISHING_ENABLED` apagada → publicar y revertir responden 409 siempre.
- Sin llamadas reales, sin cambios en campañas Meta, sin ElevenLabs, sin consumo pagado sin autorización.
- La aplicación corre en PostgreSQL con el rol solo-DML `iron_app`; las migraciones, con el rol dueño `iron` mediante `ironbody-migrate`.
- **Tope de cuerpo de 64 KB** (65 536 bytes) en las 21 rutas del Studio, que responde 413 `payload_too_large`, y validación de listas en dos fases. Llega con G: **rige en producción cuando G se despliegue.** Hasta entonces, las 19 rutas de F no tienen tope de cuerpo propio y solo las limita `post_max_size` (8 MB).
- **Puerta PostgreSQL obligatoria antes de cada despliegue del Studio:** `tests/Feature/UltronStudio tests/Unit/UltronStudio`, en verde en un PostgreSQL 14 desechable. Solo PostgreSQL detecta que falta el cerrojo de las reglas; la suite SQLite por defecto no.

## Pendientes del propietario (Hito 7)

Fuera del alcance de G (Enmienda 2 del contrato G); cada uno con su propia revisión:

- **`no-store` en la API administrativa.** Hoy responde `Cache-Control: no-cache, private`, y por ella pasan las lecturas del motor y la auditoría con nombres del personal, en puestos compartidos.
- **La clase de M-2 en otros controladores del CRM:** listas con comodín (`lista.*`) validadas sin un tope de tamaño previo. No se ha auditado fuera del Studio; el trait `ValidatesListSizesFirst` es genérico y se puede reutilizar.
- **`TrimStrings` y `ConvertEmptyStringsToNull`** recorren cuerpos de hasta `post_max_size` (8 MB) antes de que actúe el tope de ruta. Opciones: un tope por grupo `api`, o un `post_max_size` menor para JSON.
- **Caracteres invisibles** (U+061C y U+E0000–E007F, entre otros) que dejan pasar `PlainText` y la API interna de la base de conocimiento: riesgo de instrucciones ocultas al modelo. Revisión aparte, de `security` y `api-security-specialist`.
- **Datos personales en las reglas** (B-1 de `security`, Ley 1581). Falta el procedimiento documentado para redactar el libro de auditoría con el rol dueño si llega una solicitud de supresión (architect y devops). Opcional: rechazar teléfonos y correos por su forma. Hoy solo hay un aviso fijo en la interfaz.

## Registro de despliegues

| Fecha (UTC) | Qué | Backend | CRM | Respaldo |
|---|---|---|---|---|
| 18-10-2026 02:56 | Hito 2: vista previa visual | — | `4f7d774` | `/var/backups/ironbody/crm-pre-ultron-studio-20261018-025639/` |
| 18-10-2026 15:24–15:28 | Hito 3-F: persistencia (migraciones, API, permisos, rol `iron_app`) y CRM integrado | `9acddc3` | `020db86` | `/var/backups/ironbody/ultron-studio-f-20261018-151812/` |

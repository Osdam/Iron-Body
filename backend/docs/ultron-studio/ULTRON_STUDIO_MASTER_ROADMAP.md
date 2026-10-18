# ULTRON STUDIO: hoja de ruta maestra

Estado REAL del proyecto, actualizado después de cada despliegue. Un hito solo se marca completado cuando está desplegado, verificado y con informe.

| Hito | Contenido | Estado | Evidencia |
|---|---|---|---|
| **1. Auditoría y arquitectura** | Auditoría del motor real (Laravel + n8n), diez capas, decisiones (Laravel conserva la autoridad; sin Python) | **COMPLETADO** (17-10-2026) | `ULTRON_STUDIO_ARCHITECTURE_AUDIT.md`, `ULTRON_STUDIO_TECH_STACK.md` |
| **2. Frontend visual** | Tablero Foblex Flow con las diez capas, conexiones del motor, inspector, validación; vista previa protegida | **COMPLETADO** (desplegado 18-10-2026 02:56 UTC, CRM `4f7d774`; en GitHub) | `ULTRON_STUDIO_VISUAL_REPORT.md` |
| **3. Motor de control** | **F** persistencia (borradores, versiones, reglas, auditoría, permisos `ultron.*`, catálogo servido por el backend) · **G** control funcional de las diez capas | **EN DESARROLLO** — F **DESPLEGADO Y VERIFICADO** (18-10-2026 15:28 UTC: backend `9acddc3`, CRM `020db86`); G pendiente | `ULTRON_STUDIO_BACKEND_REPORT.md`, `ULTRON_STUDIO_SECURITY_REPORT.md`, `ULTRON_STUDIO_FINAL_REPORT.md` |
| **4. Laboratorio de IA** | Escenarios, simulaciones, evaluación asistida (OpenAI con clave propia), veredicto con revisión humana | **PENDIENTE** | `ULTRON_STUDIO_AI_EVALUATION_REPORT.md` (diseño) |
| **5. Gobierno y versionado** | Ciclo completo borrador → validación → simulación → revisión → aprobación → canario → publicación; integración con el motor por contrato verificable | **PENDIENTE** (el ciclo hasta APPROVED ya existe desde F; publicar/revertir siguen bloqueados a propósito) | — |
| **6. Integraciones comerciales** | Meta Ads (solo lectura), Twilio, números canarios, adaptador ElevenLabs (`ELEVENLABS_NOT_CONFIGURED`) | **PENDIENTE** | `ULTRON_STUDIO_META_REPORT.md`, `ULTRON_STUDIO_TWILIO_REPORT.md`, `ULTRON_STUDIO_ELEVENLABS_READY.md` (diseño) |
| **7. Seguridad y producción** | Revisión integral, runbooks, monitoreo, plan de activación canaria | **PENDIENTE** | — |

## Protecciones vigentes (no se retiran sin autorización expresa)

- El motor de ULTRON **no lee** nada del Studio: `StudioPublishing::ENGINE_READS_VERSIONS = false` y `ULTRON_STUDIO_PUBLISHING_ENABLED` apagada → publicar y revertir responden 409 siempre.
- Sin llamadas reales, sin cambios en campañas Meta, sin ElevenLabs, sin consumo pagado sin autorización.
- La aplicación corre en PostgreSQL con el rol solo-DML `iron_app`; las migraciones, con el rol dueño `iron` mediante `ironbody-migrate`.

## Registro de despliegues

| Fecha (UTC) | Qué | Backend | CRM | Respaldo |
|---|---|---|---|---|
| 18-10-2026 02:56 | Hito 2: vista previa visual | — | `4f7d774` | `/var/backups/ironbody/crm-pre-ultron-studio-20261018-025639/` |
| 18-10-2026 15:24–15:28 | Hito 3-F: persistencia (migraciones, API, permisos, rol `iron_app`) y CRM integrado | `9acddc3` | `020db86` | `/var/backups/ironbody/ultron-studio-f-20261018-151812/` |

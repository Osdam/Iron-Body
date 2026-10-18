# ULTRON STUDIO: backend (estado)

**Fecha:** 17-10-2026. **Estado: NO IMPLEMENTADO.** El Hito C/D no toca el backend: la vista previa no tiene endpoints, tablas ni permisos nuevos. Este informe fija el diseño con el que arrancará el Hito F (persistencia y reglas), derivado de la auditoría (`ULTRON_STUDIO_ARCHITECTURE_AUDIT.md`).

## Decisiones ya tomadas

- **Laravel conserva la autoridad** (autenticación, RBAC, configuraciones, aprobaciones y reglas). El Studio es un cliente de administración del CRM; **no reutiliza** las rutas internas de n8n (`internal/marketing/*`).
- **Sin Python.** El razonamiento ya corre en n8n con un contrato firmado; un segundo motor podría contradecirlo.
- **Primero solo lectura, después escritura con aprobación.** Orden de fases: (1) manifiesto generado desde el código y estado no secreto; (2) borradores, versiones y aprobaciones; (3) base de conocimiento con aprobación a cuatro ojos; (4) edición de prompts solo cuando el commit registre la versión de cada turno.

## Modelo de datos previsto (nombres a adaptar a las convenciones del repo)

| Entidad | Para qué | Nota |
|---|---|---|
| `ultron_configuration_drafts` | Borrador por persona: layout, aristas, parámetros, estado | Sustituye al `localStorage` de la vista previa |
| `ultron_versions` | Versiones inmutables (DRAFT → VALIDATING → VALIDATED → REVIEW_REQUIRED → APPROVED → CANARY → PUBLISHED → RETIRED / ROLLED_BACK) con hash del contenido | Cada conversación nueva se vincula a una versión; no se cambia a mitad de conversación |
| `ultron_rules` / `ultron_rule_versions` | Reglas con estructura verificable (id, descripción, tipo, capas, condiciones, restricciones, prioridad, versión, autor, estado) | Importación `LEGACY_BASELINE_V1` desde el código y la base de conocimiento, sin borrar nada |
| `ultron_approvals`, `ultron_audit_events` | Quién aprobó, publicó o revirtió, cuándo y qué cambió | Auditoría obligatoria |
| `ultron_evaluations`, `ultron_test_scenarios`, `ultron_test_runs` | Laboratorio y evaluación asistida | Tokens y coste por ejecución |
| `ultron_campaign_associations` | Vínculo campaña Meta (id opaco) ↔ estrategia/versión | Distinto de «campaña activa en Meta» |
| `ultron_canary_numbers`, `ultron_call_tests` | Números de prueba (E.164 cifrado + hash) y llamadas de prueba | Auditado como `marketing_agent_settings` |

**Reutilización, no duplicación:** planes, clases, base de conocimiento, atribuciones, campañas sincronizadas de Meta y la pausa del agente ya existen y se leen de sus tablas.

## Permisos previstos (`ultron.*`, validados en Laravel en cada endpoint)

`ultron.view`, `ultron.edit_draft`, `ultron.manage_rules`, `ultron.manage_campaigns`, `ultron.run_simulations`, `ultron.manage_canaries`, `ultron.request_call_test`, `ultron.approve`, `ultron.publish`, `ultron.rollback`, `ultron.manage_settings`. Separados: quien edita, quien aprueba, quien publica y quien administra integraciones. Tener `marketing.view` no autoriza nada de esto. Todas las rutas se mapean en `AuthorizationMap` (falla cerrado).

## Lo que el backend no dejará cambiar desde el Studio

Dinero (precio y vendibilidad, patrones de descuento, `PaymentFactGuard`, banderas de cobro), consentimiento, derivación humana, el orden de los 28 controles del commit, encender ULTRON, soltar el freno o mover el canario, y cualquier secreto.

## Migraciones

Aditivas y reversibles, verificadas en PostgreSQL 14 antes de proponerlas, y **no se ejecutan en producción sin autorización**.

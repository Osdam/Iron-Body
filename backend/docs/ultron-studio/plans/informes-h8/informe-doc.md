# Hito 8 · DOC8 · informe de `documentation-specialist` — ENTREGA PARCIAL (corte por límite de uso)

**Fecha:** 08-11-2026. **Base:** backend `8d3c41f` (Enmienda 9), CRM `26ef142` (paso 2). **Sin commit.** No he tocado código, pruebas, scripts ni el contrato. El árbol ya venía con cambios ajenos (`ops/perf-h8/resultados/*.json` y `ops/evidencia-h8/`, del lead): no los toqué.

## 1. Estado

| Entregable | Estado |
|---|---|
| `ULTRON_STUDIO_H8_VOZ.md` (manual: piezas, flujo, 2 diagramas Mermaid, 34 + 4 + 1 rutas, comandos, tareas, variables, estados, matriz de evidencia, qué NO hace) | **Hecho** (descriptivo + referencia) |
| `ULTRON_STUDIO_H8_RIESGOS.md` | **Hecho, compacto**; falta fundir lo que añada §SEC8-b (aún no escrito en `informe-security.md`) |
| Guía de administración, §14 (H8) | **NO hecho.** El manual remite a «§14»: referencia colgante hasta escribirla |
| Checklist operativo, §5 (H8) | **NO hecho.** El manual remite a «§5»: ídem |
| Correcciones fechadas de E1-12 en la guía (§2, §8, §9) y en el checklist (§1, §4) | **NO hechas** (ver D-1) |

## 2. Cómo verifiqué (ejecutado)

- `php artisan route:list --path=voice --json` (backend `8d3c41f`): 21 rutas del Studio + ruta 45 + 13 del CRM de telefonía + 4 públicas, con sus topes y limitadores (`voice-admin-read/write`, `ultron-studio-*`).
- `php artisan tinker` con `PermissionCatalog::all()` (108) y `CrmPermission::byRole()`: Super Admin 111 permisos y las 7 llaves; **Administrador: `ultron.approve`, `ultron.voice_manage`, `telephony.view`**; Administrativo, Recepción y Entrenador: ninguna de voz.
- Leído: `routes/voice.php`, `routes/ultron-studio.php`, `routes/console.php:420-469`, `config/voice.php` (entero), `config/ultron_studio.php:270-316`, `config/queue.php:186-204`, el fichero de ejemplo de entorno (líneas 395–513, solo nombres; todos los secretos vacíos), `AuthorizationMap.php:773-861`, `CrmPermission.php:143-330`, los 4 comandos `voice:*`, `VoiceBrake`, `VoiceReconciler`, `VoiceCostGuard`, `InboundGate`, `OutboundGate`, `OutboundPolicy`, `InboundPolicy`, `VoicePublishedConfig`, `StudioVoicePublishing`, `StudioVoiceEvidence`, `StudioVoiceLabConfig`; en el CRM, `studio-voice-page.ts` y `git show HEAD:…/studio-voice-text.ts`.
- **No ejecutado:** nada en producción ni en el banco; `voice:doctor --telefonia` no lo corrí (tocaría la base del árbol compartido).

## 3. Discrepancias (reportadas, no resueltas)

| # | Dónde | Qué | Lo real (evidencia) | Dueño |
|---|---|---|---|---|
| D-1 | Guía §2, §8 («Hoy»), §9; checklist §1 (`integrations-doctor --expect=…ELEVENLABS_NOT_CONFIGURED`, `voice:doctor` = `NOT_CONFIGURED`) y §4 | Obsoletos desde E1-12 (03-11-2026): hoy esas comprobaciones diarias **fallan** | `voice:doctor` = `ELEVENLABS_UNVERIFIED`; Studio `ELEVENLABS_VERIFIED` hasta el 03-12-2026 22:55 UTC (E1-12, E8-7, plan «Hito 8») | DOC8 (E1-12 se lo asigna): corregir con fecha |
| D-2 | `DEPLOYMENT_PLAN`, «Bloqueantes» 4, smoke («Ritmo») e incidencia «login 429» | Dice que las 13 rutas del CRM usan `throttle:120,1`/`30,1` | Ya son `throttle:voice-admin-read/write` por persona (E9-4; `routes/voice.php:57,65`) | devops |
| D-3 | `DEPLOYMENT_PLAN`, «Cómo se suelta el freno» | Nota de 5 a 500 | 5 a 300 (`StudioVoiceEvidence::NOTE_MAX`, `VoiceBrake::NOTE_MAX`) | devops |
| D-4 | `informe-devops.md`, Notas | `voice:prune-rate-limits` «cada hora» | A diario a las 04:40 de Bogotá (`routes/console.php:452-456`, E9-2) | devops |
| D-5 | Contrato D8-23 | `ULTRON_STUDIO_VOICE_LAB_ENABLED/_DRIVER/_DAILY_BUDGET_USD` | `VOICE_LAB_*` en `config/voice.php` (E2-4 solo renombra la bandera) | lead |
| D-6 | Contrato, protección 1 | «todas las `VOICE_*` en false o 0» | Techos no nulos (`VOICE_MAX_CONCURRENT_CALLS=1`, `VOICE_PER_CALL_MAX_SECONDS=300`, `VOICE_LAB_MAX_SESSIONS_PER_DAY=5`…); B8-a §4.7 lo avisó; sin enmienda | lead |
| D-7 | Congelación, P-18 | «Administrador: solo lectura y laboratorio hablado» | Código = D8-22: el laboratorio es solo Super Admin (ejecutado `byRole()`); LAB8 §2.1 lo avisó | lead |
| D-8 | `DEPLOYMENT_PLAN` y E2-2 | Rotación con `el-verificar.sh` y «pasos 1–6 de ELEVENLABS_READY» | `el-verificar.sh` **no existe en el repo** (`git grep`); ELEVENLABS_READY no lo nombra | lead / devops |
| D-9 | `ULTRON_STUDIO_ELEVENLABS_READY.md` (cabecera «Estado: ELEVENLABS_NOT_CONFIGURED») | Obsoleto desde E1-12 | Ver D-1 | DOC8 (fuera de mi lista de ficheros): marcar con fecha |
| D-10 | Comentarios de `config/voice.php:3-21, 50` y del bloque H6 del fichero de ejemplo (líneas 395–419) | «en este hito no se hace ninguna llamada», «no hay ruta» | Hay rutas H8; comentarios del H6 | backend (B8) |
| D-11 | `voice:doctor` (salida de texto) | Línea fija «Llamadas: cerradas en el código. En este hito no se hace ninguna llamada.» | No depende de la constante: será falsa tras `voice(calls-open)` (`VoiceDoctorCommand.php:79`) | backend |
| D-12 | Contrato, fila DOC8 | `ULTRON_STUDIO_VOICE.md` y lista amplia (TWILIO_REPORT, PRUEBAS_FISICAS nivel 3, ROLLBACK, roadmap, FINAL/SECURITY/BACKEND/VISUAL, `ops/README.md`) | El encargo del lead fija `ULTRON_STUDIO_H8_VOZ.md` y 4 ficheros; el resto queda sin hacer, en especial **la guía de pruebas físicas de voz** | lead |
| D-13 | `DEPLOYMENT_PLAN`, fase 3 | No nombra `ULTRON_STUDIO_VOICE_PUBLISHING_ENABLED=true` | Publicar el canario la exige, y solo es posible tras `voice(calls-open)` (asignar el número está cerrado por la constante) | devops |
| D-14 | `DEPLOYMENT_PLAN`, fase 4b | No exige una publicación promovida | El alcance `contacts` lee la parte `rest` (`VoicePublishedConfig.php:252-255`) | devops |

## 4. Hallazgos nuevos (deducidos del código, sin ensayar)

- **H-D1 (R8-13).** Con `VOICE_DAILY_BUDGET_USD=0`, cualquier fila de `voice_calls` del día (también la `denied` que deja la iniciación de prueba O-2 en la fase 2, o la primera entrante del canario en la fase 3) hace que la siguiente conciliación eche el freno `cost_cap` (`VoiceReconciler.php:326-336`, `VoiceCostGuard::committedMicros`, `InboundGate.php:123-129`). El plan solo sube el presupuesto en la 4a. Decide devops/lead.
- **H-D2.** `voice:reconcile` no es de solo lectura (escribe eventos y puede frenar); el plan lo usa como diagnóstico antes de soltar el freno.
- **H-D3.** Un perfil con destinos de transferencia no se puede probar en el laboratorio (`voice_lab_transfers_not_supported`) y, con la puerta `required`, no se puede publicar: las transferencias de la fase 3 quedan bloqueadas sin dispensa (`informe-lab.md` §6.4; `informe-tester.md` §TEST8-b).

## 5. Lo que queda (para retomar)

1. Guía §14 y checklist §5 (con O-2, la caducidad del 03-12-2026, `ULTRON_STUDIO_MONITOR_EXPECT`, requisitos del propietario por fase) y las correcciones fechadas de D-1.
2. Fundir §SEC8-b en el registro de riesgos cuando exista.
3. Al cerrar FE8-3: quitar las marcas **[paso 3]**.
4. Pasarlo a `reviewer` con la evidencia de §2.

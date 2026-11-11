# Auditoría de cierre · Frente A: núcleo del Studio y su conexión con WhatsApp

**Fecha:** 11-11-2026, 19:00 UTC aprox. **Autor:** `architect`, solo lectura (no se tocó código, pruebas, producción ni red). **Base leída:** backend `6f9d463e` (= API de producción `2687119` + un commit de documentos) y CRM `5f2a454` (= producción `b07eda5` + un commit de pruebas). Rutas del backend relativas a `backend/`; las del CRM, con el prefijo `CRM:` = `Iron-Body_Front/src/app/modules/ultron-studio/`. Hechos de producción: `plans/cierre/estado-produccion-20261111-1842Z.txt`, `ULTRON_STUDIO_CIERRE_9_HITOS.md` §0 y las filas L9-104 a L9-110 del contrato del H9 (no se volvieron a leer).

**Ejecutado hoy:** el comprobador del flujo de n8n (`node docs/ultron-studio/ops/motores/verificar-flujo.cjs n8n/flujos/ultron-studio-s1/flujo-s1.json`): **OK (111 comprobaciones)**, incluida «fixture PHP (profesional + 200): studio con la huella calculada en PHP (E3)». La prueba PHPUnit se lanzó después de las 14:10 hora local; su resultado está en la §6.

**Convención:** «comprobado» = leído en el código o en un hecho de producción citado; **[SUPOSICIÓN]** = no verificado.

---

## 1. Matriz

Estados: **OPERATIVO Y PROBADO** · **LISTO PARA ACTIVACIÓN AUTORIZADA** · **EN OBSERVACIÓN** · **BLOQUEADO EXTERNAMENTE** · **INCOMPLETO**. Ruta de la API: prefijo `api/admin/ultron-studio/`. Banderas: las de producción del 11-11 18:42Z (publicar `true`, motor `canary`, prompts `canary`, puerta del laboratorio `required`, ventana 07:00–19:00, laboratorio `true`, escrituras de integraciones `true`).

| # | Módulo · funcionalidad | Frontend (componente · ruta CRM) | Backend (ruta · controlador · servicio) | Tablas | Dep. externa | Bandera efectiva | Estado | Causa demostrada | Corrección | Prueba | Resultado esperado |
|---|---|---|---|---|---|---|---|---|---|---|---|
| V1 | Versiones · borrador por persona, crear, validar, enviar | `ultron-studio-page.ts`, `studio-versions-panel.ts` · `/marketing/ultron-studio` | `PUT draft`, `POST versions`, `…/validate`, `…/submit` · `UltronStudioController`, `UltronStudioVersionController` · `StudioDrafts`, `StudioVersions` | `ultron_studio_drafts`, `ultron_studio_versions` | — | sin bandera | OPERATIVO Y PROBADO | 1 versión APPROVED en producción (recuento 18:42Z) | — | `UltronStudioVersionLifecycleTest` | — |
| V2 | Versiones · diez capas, validación y restricciones | `studio-inspector.ts`, `studio-param-row.ts` | `GET catalog`, `POST versions/{uuid}/validate` · `StudioCatalog`, `StudioGraphValidator`, `StudioRuleCoherence` | idem | — | — | OPERATIVO Y PROBADO como editor; el efecto real de cada capa, en §3.1 | Los textos del inspector niegan el efecto de los cuatro gobernados (§3.7, T1) | Capa de textos en el CRM según `status.engine.prompts.key` (INC-1) | specs del inspector | El inspector dice qué gobierna la versión publicada |
| V3 | Aprobación por otra persona y no autoaprobación | `studio-versions-panel.ts` | `POST versions/{uuid}/approve|reject` · `StudioVersions` | `ultron_studio_version_reviews`, `ultron_studio_audit_events` | — | — | OPERATIVO Y PROBADO | Exigido por identidad de cuenta; 1 APPROVED en producción (no consta quién la creó ni quién la aprobó) | Textos del diálogo (T4, T5) | §3.5 | — |
| V4 | Vista previa de publicar con prompts compilados | `studio-publication-dialog.ts`, `studio-prompts-changes.ts` | `GET versions/{uuid}/publication-preview` · `StudioPublications::preview()` → `StudioPromptBundle` → `UltronPromptCompiler` | lee `ultron_studio_publications` | — | prompts `canary` | LISTO PARA ACTIVACIÓN AUTORIZADA | Nunca abierta sobre una versión con cambios en producción (0 publicaciones) | — | `UltronStudioPublicationPromptsTest`, `UltronStudioPublicationV2Test` | `effect = prompts`, clase `prompt` en tono y longitud, huella `702890f7` |
| V5 | **Publicar en canario: la versión gobierna tono, emojis, longitud y objetivo en la canaria** | idem + `studio-publication-bar.ts` | `POST versions/{uuid}/publish` · `StudioPublications::publish()`; motor: `UltronPromptOffer`, `UltronDecideService`, `UltronCommitService`, `UltronPromptStamp` | `ultron_studio_publications`, `marketing_ai_actions.metadata` | n8n (copia S1) + OpenAI | publicar on · motor `canary` · prompts `canary` · puerta `required` · ventana | **LISTO PARA ACTIVACIÓN AUTORIZADA** | 0 publicaciones y 0 veredictos; falta una segunda cuenta, una ejecución del laboratorio sobre la versión con su veredicto y publicar en ventana (§3.6). Sin bloqueo técnico encontrado | Ninguna de código | Backend §3.5; comprobador del flujo E3 (hoy OK 111) | Turno canario `applied`, versión V, huella observada `702890f7…`, `flowRev S1`; respuestas de usted y cortas |
| V6 | Vigente y versión ejecutada por turno | `studio-publication-bar.ts`, `studio-prompts-trace.ts` | `GET status` (`engine.publication`, `engine.prompts`) · `StudioPublications::engineBlock()`, `promptsTrace()`; consola `ultron:prompt-trace` | `marketing_ai_actions.metadata.studio` y `.studio_prompts` | n8n | — | OPERATIVO Y PROBADO | Turno 941514 (L9-109): `legacy · no_publication · observada 3f6a5674 S1` | — | `UltronPromptsTraceCommandTest`, `UltronStudioPublicationPromptsTest::test_los_ultimos_turnos_…` | — |
| V7 | Revertir y volver a Legacy | `studio-versions-panel.ts`, barra | `POST versions/{uuid}/rollback`, `POST publications/legacy` · `StudioPublications` | idem | — | no dependen de llaves ni ventana | LISTO PARA ACTIVACIÓN AUTORIZADA | Sin nada publicado no hay qué revertir; se ejerce en E5 | — | `UltronStudioPublicationRollbackTest` (11) | Turno siguiente `legacy` (`part_legacy`), huella `3f6a5674` |
| V8 | Promover a todas | barra | `POST versions/{uuid}/promote` · `StudioPublications::promote()` | idem | — | exige motor `all` (`StudioPublications.php:1137-1141`), canario ≥ 24 h y ≥ 3 turnos | LISTO PARA ACTIVACIÓN AUTORIZADA (solo declaración) | Aunque se promueva, los prompts gobernados solo llegan a la canaria: la llave solo admite `off|canary` (`app/Services/Marketing/Ultron/Prompts/UltronPromptOffer.php:110-130`) | — | `UltronStudioPublicationV2Test`, `UltronStudioPublicationCanaryTest` | — |
| V9 | Inmutabilidad de versiones y de la cadena | — | disparadores y `REVOKE` (`database/migrations/2026_10_18_100002_add_ultron_studio_guards.php:82-93`; H7) | versiones, cadena, libro | — | — | OPERATIVO Y PROBADO | Esquema verificado en el despliegue (L9-106) | — | §3.5 | — |
| V10 | Historial, comparación y `verified` por entrada | `studio-history-list.ts`, `studio-compare-view.ts`, `studio-publication-history.ts:48-52` | `GET versions`, `GET compare`, `GET publications` | idem | — | — | OPERATIVO Y PROBADO (cadena vacía) | — | — | `UltronStudioPublicationVerifiedTest` | — |
| R1 | Reglas S-NNN · constructor, condiciones, prioridad, coherencia, revisión, copia en la versión | `studio-rules-panel.ts`, `studio-conditions-editor.ts` | `POST/PATCH rules`, `POST rules/{uuid}/archive` · `UltronStudioRuleController` · `StudioRules`, `StudioRuleCoherence` | `ultron_studio_rules` | — | — | OPERATIVO Y PROBADO como registro | **0 reglas S-NNN en producción:** el recuento solo tiene `active · 12`, y una S-NNN nunca está activa (CHECK `usr_studio_never_active_chk`, migración `…_100002_…guards.php:136`); las 12 son R-01…R-12 del código | — | `UltronStudioRulesTest`, `UltronStudioRuleConditionsTest` | — |
| R2 | Activar y desactivar una S-NNN | — | no existe: solo `draft`/`archived` (`app/Models/UltronStudio/StudioRule.php:103-107`) | — | — | — | INCOMPLETO (por contrato) | La regla «nunca activa» es estructural | Ver INC-4 | — | — |
| R3 | S-NNN en la simulación del laboratorio de texto | `studio-lab-launcher.ts:123` | `StudioLabPrompts::selectRules()` (`:337-367`); `StudioLabRuns.php:668-679` | `ultron_studio_lab_*` | OpenAI | laboratorio on | OPERATIVO (simulación) | El actor y el juez reciben hasta 20 S-NNN (`StudioLabPrompts.php:51-52`, `:117-136`) | — | `UltronStudioLabRunsTest` | — |
| R4 | **S-NNN con efecto en WhatsApp** | — | ninguno | — | — | — | **INCOMPLETO** | §3.2 | INC-4 | ~50 backend + ~10 CRM | Turno canario sin la frase prohibida |
| R5 | S-NNN con efecto en la voz | — | `StudioVoiceCompiler::forbiddenPhrases()` (`:775-824`), usado en `:239-252` | — | ElevenLabs | laboratorio hablado on; publicar la voz off; llamadas cerradas | LISTO PARA ACTIVACIÓN AUTORIZADA (solo en el laboratorio hablado) | Implementado; nunca ejercido (0 S-NNN) | — | pruebas del compilador de voz | El agente compilado lleva la frase en «nunca digas» |
| R6 | S-NNN con efecto en la automatización (H9) | — | `StudioStrategyValidator::studioReference()` solo lee `rules.paymentLinksEnabled` y `governance.canaryOnly` (`:144-178`) | — | — | — | INCOMPLETO (fuera de todo contrato; no recomendado antes del 15-11) | — | — | — | — |
| A1 | Libro: autor, revisor, aprobaciones, publicaciones, reversiones, rechazos | `studio-audit-panel.ts` | `GET audit` · `StudioAudit`; actor en cada entrada de la cadena (`StudioPublications.php:151-155`); rechazos deduplicados (`:1935-1949`) | `ultron_studio_audit_events`, `ultron_studio_version_reviews`, cadena | — | — | OPERATIVO Y PROBADO | — | — | `UltronStudioAuditTest`, `UltronStudioPublicationAccessTest` | — |
| A2 | Permisos | Configuración → Roles | `app/Support/Access/PermissionCatalog.php:152-159`, `:238-245`; `CrmPermission.php:302-310`; `AuthorizationMap.php:710-753` | — | — | — | OPERATIVO Y PROBADO | `ultron.publish`, `ultron.rollback` y `ultron.lab_run` solo llegan al Super Admin; cuatro ojos **por cuenta**, no por persona | — | cobertura `auth:coverage` en cada despliegue | — |
| A3 | Alerta automática si n8n aplica otra cosa (M1-A) | — | no existe | — | — | monitor del H7 on | **INCOMPLETO** | `mismatch`/`legacy_drift` solo quedan en el log y en rojo en el CRM (M1-13) | INC-2 | ~8 | Incidente de IRON GUARD con `mismatch` |
| L1 | Laboratorio de texto · objetivo (versión, borrador, línea base), estimar, ejecutar, modelo y coste | `studio-lab-launcher.ts`, `studio-lab-runs.ts`, `studio-lab-run-view.ts` · `/marketing/ultron-studio/laboratorio` | `POST lab/runs` · `UltronStudioLabController` · `StudioLabRuns`, `StudioLabRunner`, `StudioLabOpenAiClient` | `ultron_studio_lab_*` | OpenAI | laboratorio on (driver y presupuesto, según el registro del 02-11 **[SUPOSICIÓN: no releídos]**) | OPERATIVO Y PROBADO | 1 ejecución real (02-11, línea base, 0,014 US$) | — | `UltronStudioLabRunsTest`, `UltronStudioLabRunnerTest` | — |
| L2 | Veredicto humano del laboratorio | `studio-lab-verdict.ts` | `POST lab/runs/{uuid}/verdict` · `StudioLabVerdicts` | `ultron_studio_lab_verdicts` | — | — | LISTO PARA ACTIVACIÓN AUTORIZADA | 0 veredictos; exige ejecución OpenAI completada sobre una VERSIÓN y una cuenta distinta de su autora (`StudioLabVerdicts.php:20-31`, `:50-64`) | — | `UltronStudioLabVerdictTest` | — |
| L3 | La interfaz distingue simulación y ULTRON | `studio-lab-page.ts:57`, `:105`; `studio-lab-run-view.ts:61` | `StudioLabRuns::FIDELITY` (`:54`) | — | — | — | **INCOMPLETO** (texto) | Lo dice, pero la explicación es falsa desde M-1 (§3.4) | INC-1 | specs + 1 prueba de backend | Texto de §3.4 |
| L4 | El laboratorio prueba los prompts que se publican | — | el actor tiene instrucciones y modelo propios (`StudioLabPrompts.php:117-136`) | — | OpenAI | — | **INCOMPLETO** (estructural) | La puerta `required` certifica una simulación distinta de los cinco prompts de n8n (`STUDIO_MOTORES_PLAN.md:27`; M1-2) | INC-5 | — | — |
| W1 | `intent.fallback` en WhatsApp | inspector | — | — | — | — | INCOMPLETO (no aplicable por prompt) | «humano» choca con R-07: lo impiden `Validar Estrategia` y `HumanHandoffAuthority` (`STUDIO_MOTORES_PLAN.md:42`) | INC-6 (decisión + texto) | — | — |
| W2 | Prompts gobernados fuera de la canaria | — | `UltronPromptOffer.php:121-130` | — | — | llave `off|canary` | INCOMPLETO (por diseño) | `all` vale `off` en esta entrega | No para el 15-11 | — | — |
| W3 | Legacy byte a byte con la copia S1 | — | `UltronPromptBase`, selector «Elegir Prompts» | — | n8n | — | OPERATIVO Y PROBADO | L9-109; comprobador de hoy OK (111) | — | `UltronPromptBaseTest`, `verificar-flujo.cjs` | — |
| C1 | Números canarios · registrar, autorizar, activar, revocar | `studio-canary-numbers.ts`, `studio-canary-register-dialog.ts` · `/marketing/ultron-studio/integraciones` | `POST integrations/canaries…` · `UltronStudioCanaryController` · `StudioCanaryNumbers` | `ultron_studio_canary_numbers` | — | escrituras de integraciones on | LISTO PARA ACTIVACIÓN AUTORIZADA | 0 números; registrar y autorizar exigen dos personas (`ultron.canary_authorize` solo Super Admin, `CrmPermission.php:311-315`) | — | `UltronStudioIntegrationsCanaryTest`, `…RaceTest` | — |
| C2 | Números canarios · coherencia con el canario del motor | `studio-canary-numbers.ts:75-82`, `:140-142` | `GET integrations/canaries` · `StudioCanaryCoherence` | idem + lectura del lead canario | — | — | OPERATIVO (dice la verdad) | Con registro vacío y canario configurado da `engine_canary_not_registered` (`StudioCanaryCoherence.php:27-33`) **[SUPOSICIÓN: no se leyó la pantalla de producción]** | — | `UltronStudioIntegrationsCanaryTest` | — |
| C3 | Números canarios · qué gobierna el registro, por canal | `studio-integrations-text.ts:59`, `:145`, `:213`, `:417` | `StudioCanaryNumbers::resolveExpectation()` (`:538-541`) | — | — | — | INCOMPLETO (texto) | Dice «sin efecto en el motor», y el registro sí gobierna la voz y el H9 (§3.3) | INC-7 | specs | Texto por canal |

---

## 2. Lo INCOMPLETO, por cercanía al criterio «un cambio aprobado y publicado se refleja en una ejecución real del motor»

Nota previa: **el criterio en sí (V5) no está incompleto: está LISTO PARA ACTIVACIÓN AUTORIZADA**; no encontré nada de código que impida E3–E5 (§3.6). Lo que sigue es lo que hay que corregir para que el CRM diga la verdad mientras se hace la prueba y para que el resultado sea vigilable, y después lo que amplía el alcance.

| Orden | Id | Qué | Ficheros | Tamaño | Antes del 15-11 |
|---|---|---|---|---|---|
| 1 | INC-1 | **Textos que niegan el efecto real** justo donde trabajan las dos personas de E3–E5: inspector de los cuatro gobernados, diálogo y aviso de aprobar, franja de fidelidad del laboratorio, aviso del veredicto (T1–T7 de §3.7) | CRM `studio-param-row.ts`, `studio-engine-text.ts` (función nueva con la llave, como `studioPublicationReading()` en `:187-195`), `studio-inspector.ts`, `studio-versions-panel.ts`, `studio-lab-verdict.ts`, `studio-lab-text.ts`; backend `app/Services/UltronStudio/StudioLabRuns.php:54` (literal del contrato del H4, `ULTRON_STUDIO_H4_CONTRACT.md:453`: necesita una enmienda del lead; lo copia `CRM:studio-lab-fixtures.testing.ts` y lo nombra `UltronStudioEngineReadOnlyTest`) | S: medio día; ~12 specs y 1–2 pruebas de backend | **Sí, antes de E3** (sin tocar el catálogo congelado, M1-10) |
| 2 | INC-2 | **Alerta M1-A:** un `mismatch` o `legacy_drift` en los turnos de la canaria abre un incidente de IRON GUARD | `app/Console/Commands/UltronStudio/MonitorCommand.php` (cuarto `check`, `prompts`), `StudioCanaryMeasures.php` (`promptTurns()`, `:107-119`, ya lee esos turnos) | S–M: medio día; ~8 pruebas (los dos estados, ventana desde la última pasada, sin ids ni textos, deduplicación) | Sí (el monitor ya está encendido) |
| 3 | INC-3 | **E14, E2E automático de la cadena entera** (versión con cuatro ojos → publicar → `decide` ofrece → selector generado → commit `applied` → revertir → `legacy`) | CRM `e2e/ultron-studio/mock-n8n.cjs`, `specs/motores-prompts.cjs` (nuevo), `specs/h5-seed.php` | M: un día | Recomendable, no bloqueante: los eslabones ya tienen prueba propia (§3.5) |
| 4 | INC-4 | **S-NNN con efecto en WhatsApp**: propuesta de §3.2 | §3.2 | M: 1,5–2 días con revisiones | Solo si E4 sale bien el 12-11 (§3.2) |
| 5 | INC-5 | Un laboratorio que use los prompts reales de WhatsApp (actor = los cinco prompts compilados y el modelo de n8n) | `StudioLabPrompts`, `StudioLabRunner`, `StudioLabRuns` y su contrato | L | No |
| 6 | INC-6 | `intent.fallback` en WhatsApp: decidir «no aplicable» y decirlo, o gobernar solo «preguntar» | CRM (texto del inspector y del diálogo) | S (texto) | Sí (texto) |
| 7 | INC-7 | Registro de canarios: texto por canal y aviso en la vista previa (§3.3) | CRM `studio-integrations-text.ts`; backend `StudioPublications::previewFrom()` (`:955`) | S | Sí |
| 8 | INC-8 | `no_voice_engine` para los números de voz, desfasado desde el H8 | `StudioCanaryNumbers.php:540-541` (leer la cadena de la voz) | S–M (frente de voz) | Opcional |
| 9 | — | Prompts gobernados fuera de la canaria (W2) y S-NNN en el H9 (R6) | — | L | No |

---

## 3. Respuestas

### 3.1 Qué gobierna cada capa (pregunta 1)

Fuentes: `StudioCatalog::parameterBindings()` (`app/Services/UltronStudio/StudioCatalog.php:676-726`) y línea base (`:553-567`); clasificación al publicar (`StudioPublicationDeclaration.php:218-279`); parámetros del compilador (`app/Services/Marketing/Ultron/Prompts/UltronPromptCompiler.php:71`); voz (`StudioVoiceCompiler.php:20-25`, `:398-415`, `:775-824`); H9 (`StudioStrategyValidator.php:34-40`, `:144-178`).

Leyenda de WhatsApp: **GOBERNADO** = con la llave de prompts en `canary`, la versión publicada cambia los prompts de la conversación canaria (clase `prompt`) · **must_match** = el valor de la versión tiene que coincidir con el motor o no se publica (`engine_value_mismatch`); no cambia nada · **declarado** = el valor fijo del código; si difiere, «sin efecto» · **sin efecto** = hay que reconocerlo al publicar (`noEffect`).

| Capa · clave | Origen | WhatsApp | Voz (agente compilado) | Automatización (H9) |
|---|---|---|---|---|
| knowledge.sources | fixed | sin efecto | no | no |
| knowledge.strictFacts | fixed = true | declarado (false bloquea la validación, R-01) | cerrojo: si no es true, no compila | no |
| **persona.tone** | proposal | **GOBERNADO** (Composer ×2, Critic ×2) | **sí** (tono de la plantilla) | no |
| **persona.emojis** | proposal | **GOBERNADO** (Composer ×2, Critic ×2) | no | no |
| **persona.maxChars** | proposal | **GOBERNADO** (Composer ×2, Critic ×2; solo múltiplos de 20, M1-11) | no | no |
| intent.driver | env_flag | must_match («Según el entorno» casa con cualquier lectura) | no | no |
| intent.fallback | proposal | sin efecto | **sí** (respuesta a lo que no entiende) | no |
| context.windowTurns | fixed = 10 | declarado | no | no |
| memory.useLeadProfile | fixed = true | declarado | no | no |
| **strategy.goal** | proposal | **GOBERNADO** (Strategist) | **sí** (objetivo de la plantilla) | no |
| strategy.offerPriority | engine_data | sin efecto (manda el módulo de Planes) | no | no |
| strategy.discountsAllowed | fixed = false | declarado (true bloquea, R-02) | cerrojo: false | no |
| rules.handoffOnlyCorroborated | fixed = true | declarado (false bloquea, R-07) | cerrojo: true | no |
| rules.paymentLinksEnabled | env_flag | must_match | cerrojo: false | **sí**: un paso con enlace de pago y false = `payment_links_disabled` |
| composer.critic · composer.retry | fixed (n8n) | sin efecto (topología del flujo) | no | no |
| integrations.channel · appointments | fixed | declarado | no | no |
| governance.enabled | env_flag | must_match | no | no |
| governance.canaryOnly | env_flag | must_match | no | **sí**, como aviso `canary_only_declared` |
| governance.autoBrake | fixed = true | declarado | no | no |
| Reglas S-NNN | — | **sin efecto** (siempre `rule:S-NNN` en `noEffect`, `StudioPublicationDeclaration.php:266-268`) | **sí**: las frases `forbid text contains` de las reglas vivas cuyo «si» se cumple van a «nunca digas» | no |
| Reglas R-xx | — | describen el código, que las aplica siempre | — | — |

Comprobado además: el motor de WhatsApp lee del Studio **una sola tabla**, la cadena (`app/Services/Marketing/Ultron/UltronPublishedConfig.php:121`), y de ella solo las cuatro `must_match` (`declared`) y, en `/2`, los cuatro gobernados y la huella (`StudioPublicationDeclaration.php:175-178`). La voz compila, pero hoy solo llega al **laboratorio hablado**: publicar la voz está apagado y las llamadas, cerradas por constante (`estado-voz-20261111.txt:2-6`). El H9 está en sombra.

### 3.2 Las reglas S-NNN (pregunta 2)

**¿Dónde influye hoy una regla aprobada en una decisión real de un motor? En ninguno de producción.** Pruebas, todas comprobadas:

1. En producción no existe ninguna S-NNN: el recuento de reglas solo tiene `active · 12`; una S-NNN solo puede estar en `draft` o `archived` (`app/Models/UltronStudio/StudioRule.php:103-107`; CHECK `usr_studio_never_active_chk`, `database/migrations/2026_10_18_100002_add_ultron_studio_guards.php:136`). Las 12 activas son R-01…R-12 (`source = legacy_baseline`, `enforcement = code`).
2. WhatsApp: la clasificación manda cada S-NNN a «sin efecto» aunque la llave de prompts esté encendida (`StudioPublicationDeclaration.php:256-268`); el compilador solo conoce cuatro parámetros (`UltronPromptCompiler.php:71`); la parte firmada no lleva reglas (`StudioPublicationDeclaration.php:175-178`); el motor solo lee `ultron_studio_publications` (`UltronPublishedConfig.php:121`).
3. Voz: sí compila las frases prohibidas (`StudioVoiceCompiler.php:775-824`), pero hoy solo hay laboratorio hablado; ni publicación de voz ni llamadas.
4. H9: la estrategia solo lee dos interruptores de la versión (`StudioStrategyValidator.php:144-178`); 0 estrategias en producción.

**Propuesta mínima y segura (no implementada).** Opciones:

| | A. Frases prohibidas compiladas en sitios anclados (recomendada) | B. Guarda determinista en el commit | C. Texto de la regla en el prompt |
|---|---|---|---|
| Qué entra | Solo las frases de `forbid text contains` de las S-NNN vivas de la versión cuyo «si» se cumple: la misma semántica que ya usa la voz | Las mismas frases, comprobadas por Laravel sobre el texto final | La descripción libre |
| Dónde | Composer y Composer Reintento, tras `FORMA:`; Critic y Critic 2, tras `naturalness:` (las anclas ya únicas y vigiladas) | `UltronCommitService` | Prompts |
| Riesgo | Bajo: textos fijos del compilador, frases con juego de caracteres cerrado; Legacy idéntico (lista vacía = sin parche) | Medio-alto: toca el camino caliente del commit; una coincidencia acaba en rendición | Alto: texto libre del personal en producción (M1-2) |
| Garantía | Probabilística (modelo + Critic), visible en la traza | Determinista | — |
| n8n | **Sin cambios**: el selector acepta cualquier paquete cuya huella cuadre | Sin cambios | — |

**→ Recomendación: A.** Ficheros (mismo contrato de huella y de caída a Legacy que M-1):

- Motor: `UltronPromptCompiler.php` (`VERSION` 1 → 2; parámetro `rules.forbiddenPhrases`: lista ordenada y sin repetidos, ≤ 12 frases de 2–60 caracteres, solo letras, cifras, espacio y `, . ¿ ? ¡ ! -`; base `[]` sin parche, así que `compile(base) = base` se conserva); `UltronPublishedConfig.php` (lectura estricta de la quinta clave de `governed`, ligada a `compiler = 2`); `UltronPromptOffer.php` sin lógica nueva (lo firmado con el compilador 1 cae a `compiler_changed`).
- Studio: clase pura nueva `StudioRulePhrases` extraída de `StudioVoiceCompiler::forbiddenPhrases()` (la voz pasa a llamarla: misma `specHash`, con prueba); `StudioPromptBundle::governedFrom()` recibe el contenido entero; `StudioPublicationDeclaration` (`GOVERNED` + la clave; las S-NNN con frases pasan a la clase `prompt`, las demás siguen en `noEffect`); `StudioPublications` (vista previa y `ENGINE_MESSAGES_PROMPTS`, `:137-140`); `PermissionCatalog.php:238`, `:244-245`.
- CRM: `studio-publication-text.ts:38`, `ultron-studio-page.ts:257`, `studio-rules-panel.ts:138`, `studio-prompts-changes.ts` (etiqueta del parámetro).
- Sin migración (sigue `/2`) y sin tocar `StudioCatalog::VERSION` (la gramática de condiciones ya existe desde G): no rompe la congelación de la voz ni del H9.

**Riesgos:** (1) **orden con E4**: subir el compilador hace caer a Legacy (`compiler_changed`) cualquier parte `/2` firmada antes; si V2 está en canario al desplegar, hay que volver a publicarla. Desplegar antes de E4 o después de revertirla. (2) El cumplimiento depende del modelo; el Critic lo vigila, pero no es una guarda de Laravel. (3) Los textos curados de rendición de Laravel no conocen las frases. (4) La puerta del laboratorio sigue sin probar los prompts reales (INC-5).

**Pruebas:** unas 50 de backend (compilador: parches, juego de caracteres, topes y `compile(base) = base`; lector: formas exactas; clasificación; paridad de las frases con la voz; vista previa, publicar y oferta), unas 10 specs del CRM y un fixture nuevo para el comprobador del flujo.

**¿Viable y prudente antes del 15-11?** Viable: 1,5–2 días con seguridad y reviewer. Prudente **solo si E4 (tono y longitud) pasa el 12-11**; si no, se cierra el 15-11 con «S-NNN en WhatsApp: INCOMPLETO» y este plan. Mi recomendación: no abrir este frente hasta tener E4 verde.

### 3.3 Números canarios (pregunta 3)

**¿El registro controla el enrutamiento de algún motor?** WhatsApp (ULTRON por n8n): **no**; solo documenta una autorización. El canario de ULTRON es `marketing.ultron.canary_conversation_id` del entorno (`StudioCanaryCoherence.php:12-16`; `UltronPromptOffer.php:127-130`). **Sí gobierna otros dos motores**, ninguno activo hoy: la voz (una saliente canaria solo marca a un número de voz `active` del registro, `app/Services/Telephony/VoiceCanaryDirectory.php:13-24`, `:27-46`, `:54-67`; constante de llamadas cerrada) y el H9 (en canario una estrategia solo alcanza a personas con un número `active`, `app/Services/Outreach/Facts/CanaryFacts.php:10-21`; en sombra solo se anota).

**¿Qué enseña el CRM con el registro vacío y el canario configurado?** En Integraciones → Números canarios (`studio-canary-numbers.ts:75-82`): «Frente al canario del motor: **El teléfono de la conversación canaria del motor no está en el registro.**» (tono de aviso; `studio-integrations-text.ts:346`), «Conversación canaria del motor: Configurada en el servidor», «WhatsApp activos en el registro: 0» y el texto fijo «Este registro no cambia a quién responde ULTRON…» (`:59`). El servidor ya calcula, por número, «es el teléfono del canario del motor» (`isEngineCanary`, `StudioCanaryCoherence.php:129-138`; chip en `studio-canary-numbers.ts:140-142`). **Esa pantalla dice la verdad** sobre la discrepancia [SUPOSICIÓN: no se leyó la pantalla de producción; con los hechos de hoy el código da ese veredicto]. Lo arreglo propuesto por el lead (veredicto calculado en el servidor y aviso) **ya existe**.

**Lo que no dice la verdad y el arreglo mínimo (INC-7):**

1. `studio-integrations-text.ts:145` («El motor no los lee en este hito») y `:213` («Registro del Studio (sin efecto en el motor)»). Debería decir: «Registro del Studio. En WhatsApp no decide a quién responde ULTRON: eso lo fija la conversación canaria del servidor. En la voz decide a qué número puede llamar una saliente canaria y, en las campañas automáticas, a quién alcanza una estrategia en canario. Hoy las llamadas y los envíos automáticos están cerrados en el código.»
2. El aviso solo vive en la pestaña de integraciones. En la vista previa de publicar, el requisito `engineCanary` (`StudioPublications.php:955`) puede llevar una nota no bloqueante con el veredicto de `StudioCanaryCoherence::read()`: «El motor tiene una conversación canaria configurada; el registro de números no la documenta». Ficheros: `StudioPublications::facts()` y `previewFrom()`, `CRM:studio-publication-text.ts:319`. Sin cambiar el enrutamiento.
3. `studio-integrations-text.ts:417` («La voz no tiene motor en este hito»), que sale del motivo `no_voice_engine` del servidor (`StudioCanaryNumbers.php:540-541`): desfasado desde el H8, que tiene su propia cadena de publicaciones (INC-8, frente de voz).

### 3.4 Laboratorio de texto (pregunta 4)

Dónde lo dice: la etiqueta fija de la cabecera «Simulación del Studio · no es ULTRON» (`CRM:studio-lab-page.ts:57`, `studio-lab-text.ts:44`), la franja con el texto del servidor (`studio-lab-page.ts:105`) y la misma frase en cada ejecución (`studio-lab-run-view.ts:61`), las dos con `StudioLabRuns::FIDELITY` (`app/Services/UltronStudio/StudioLabRuns.php:54`). **Lo distingue con claridad, pero la explicación ya es falsa**: «No es ULTRON: el motor no lee el Studio y sus prompts viven en n8n». Desde M-1 el motor lee la cadena y, en la canaria, sus prompts salen del repositorio y de la versión publicada. Además el actor del laboratorio sigue «Si no entiende» y las S-NNN, que el WhatsApp real no aplica (`StudioLabPrompts.php:117-136`).

Texto propuesto para `StudioLabRuns.php:54`: «Simulación del Studio: un modelo de laboratorio, con sus propias instrucciones, interpreta la versión, incluidas «Si no entiende» y las reglas S-NNN, que ULTRON por WhatsApp no aplica. No es ULTRON: no usa los cinco prompts de WhatsApp ni su modelo. Lo que cambia a ULTRON en la conversación canaria es publicar la versión; compruébalo en «Turnos de la canaria».» En la ficha de la ejecución, la etiqueta «Prompts» (`studio-lab-run-view.ts:64`) debería ser «Instrucciones del laboratorio», para que no se confunda con el paquete de prompts del motor.

### 3.5 Flujo de versiones: garantía → prueba automática (pregunta 5)

| Garantía | Prueba |
|---|---|
| Inmutabilidad de la versión | `UltronStudioImmutabilityTest::test_la_base_rechaza_cambiar_el_contenido_de_una_version_aunque_sea_el_mismo`, `::test_eloquent_no_cambia_nada_inmutable_de_una_version` |
| Inmutabilidad de lo publicado | `UltronStudioPublicationSchemaTest::test_la_cadena_admite_insertar_y_rechaza_cambiar_o_borrar`; `UltronStudioPublicationVerifiedTest`; `UltronStudioPublicationLifecycleTest::test_una_cabeza_con_la_huella_tocada_a_mano_no_se_copia_aunque_su_firma_verifique` |
| No autoaprobación | `UltronStudioVersionLifecycleTest::test_aprobar_exige_otra_persona_y_el_intento_propio_queda_auditado`, `::test_los_cuatro_ojos_comparan_la_identidad_y_no_el_rol` |
| Quien publica no es quien creó | `UltronStudioPublicationAccessTest::test_los_cuatro_ojos_por_identidad_al_publicar_y_al_promover_y_quedan_en_el_libro`; `UltronStudioPublicationRaceTest::test_veinte_intentos_simultaneos_de_la_autora_en_repeatable_read_dejan_un_solo_four_eyes_denied` |
| Veredicto del laboratorio de otra persona | `UltronStudioLabVerdictTest::test_la_autora_de_la_version_no_lo_emite_y_su_intento_queda_una_vez` |
| Canario único | `UltronStudioPublicationSchemaTest::test_como_mucho_una_version_canary_y_una_published`; `UltronStudioPublicationRaceTest::test_veinte_versiones_distintas_publicadas_a_la_vez_dejan_una_sola_en_canario`, `::test_el_indice_usv_one_canary_…` |
| Reversión | `UltronStudioPublicationRollbackTest` (11 pruebas); con prompts: `UltronStudioPublicationPromptsTest::test_con_la_llave_encendida_se_publica_el_paquete_que_enseno_la_vista_previa_y_el_motor_lo_ofrece` (termina revirtiendo: oferta `legacy · part_legacy`), `::test_la_parte_que_se_arrastra_se_ensena_y_revertir_la_conserva_en_v2` |
| Puerta del laboratorio | `UltronStudioPublicationLifecycleTest::test_publicar_con_la_puerta_del_laboratorio_requerida_registra_el_veredicto` y las 58 puertas del fixture (`::test_publicar_por_http_en_el_orden_del_fixture`, `StudioPublicationGatesParityTest`) |
| Ventana horaria | `outside_publication_window` en el fixture de puertas (las mismas dos pruebas); revertir sin ventana: `UltronStudioPublicationRollbackTest::test_sin_llaves_sin_cuatro_ojos_y_sin_ventana_revertir_y_legacy_siguen_abiertos` |
| Lo publicado es lo ofrecido y lo aplicado | `UltronStudioPublicationPromptsTest::test_la_oferta_de_status_es_la_de_decide_en_cada_estado`; `UltronDecidePromptsTest`; `UltronCommitPromptsTest`; `UltronPromptStampTest`; comprobador del flujo (E3, hoy OK 111) |

**Sin prueba automática:** (1) E14, la cadena entera con la pila real y el doble de n8n (no existe `specs/motores-prompts.cjs`; `plans/informes-motores/informe-tester-guardas.md:67`); (2) la alerta M1-A (no hay código); (3) que la puerta del laboratorio pruebe los prompts que se publican (no lo hace, L4); (4) «quien aprueba no es quien publica» **no es una garantía del código**: solo se exige que publicar no lo haga quien creó (`StudioPublications.php:1277`, `:1918-1932`). Es la decisión que permite trabajar a un equipo de dos.

### 3.6 La prueba de aceptación pendiente (pregunta 6)

**Comprobado que no hay bloqueo técnico**, puerta por puerta (`StudioPublications::publishRefusal()`, `:1085-1131`, sobre `facts()`, `:1195-1293`):

- **Compila:** tono `profesional` y 200 (múltiplo de 20 entre 160 y 480) son valores del compilador (`UltronPromptCompiler.php:85-94`). La prueba del backend con exactamente esa versión da `publishable`, `effect = prompts`, `noEffect = []` y la huella `702890f7…` (`tests/Feature/UltronStudio/UltronStudioPublicationPromptsTest.php:126-169`); el selector generado de n8n acepta ese paquete con la huella calculada en PHP (comprobador de hoy, «fixture PHP (profesional + 200)»). **Huella esperada en la traza: `702890f7`** (si emojis y objetivo quedan como en la línea base).
- **`engine_value_mismatch`:** el contenido parte de la línea base (driver «Según el entorno», que casa con cualquier lectura; enlaces de pago false; ULTRON encendido; canario true). Producción: ULTRON encendido y canario configurado (hechos). Enlaces de pago: `MARKETING_ULTRON_PAYMENT_LINKS_ENABLED` (`config/marketing.php:36`) **[SUPOSICIÓN: apagada, según el catálogo y la auditoría del 10-11; no releída]**. Si estuviera encendida, la vista previa lo dice antes de publicar.
- **Revalidación:** pura sobre el contenido y el catálogo congelado (`StudioVersions.php:609-618`); no cambia entre aprobar y publicar.
- **Commit:** `Preparar Commit` arma el cuerpo clave a clave y solo añade `prompts` (`n8n/flujos/ultron-studio-s1/preparar-commit.js`); los ≈113 KB de prompts no viajan al commit, y el tope de 64 KB solo aplica a `admin/ultron-studio` (`routes/ultron-studio.php:115`, `:292`).
- Puerta de seguridad de E2–E5 (`engine.publication.rest` a null): se cumple, hay 0 publicaciones.

**La versión APPROVED que ya existe:** se desconoce su contenido y su autor. Sirve solo si su vista previa enseña exactamente tono y longitud en la clase `prompt` y `shortHash = 702890f7`, y si la publica alguien que no la creó. Si no, versión nueva.

**Pasos humanos exactos** (P1 = el propietario, Super Admin; P2 = la segunda persona, con una cuenta propia y `ultron.edit_draft`, que el Administrador tiene por defecto):

1. **P2**, en `/marketing/ultron-studio`: inspector de «Personalidad y tono» → Tono «Profesional» y Longitud máxima 200, sin tocar nada más → guardar → «Crear una versión» → «Validar» → «Enviar a revisión».
2. **P1:** «Aprobar» (reconociendo los avisos, si los hay).
3. **P1**, en `/marketing/ultron-studio/laboratorio`: ejecución en modo OpenAI **sobre esa versión** (ni el borrador ni la línea base), al menos un escenario; al completarse, veredicto «Aprobada» reconociendo los casos que no pasaron. Unos céntimos.
4. **P1, entre las 07:00 y las 19:00 (hora de Bogotá):** «Publicar en canario»; comprobar en la vista previa `publishable`, «cambia lo que ULTRON dice en la conversación canaria», tono y longitud como cambios y la huella `702890f7`. Confirmar.
5. **Propietario**, desde el teléfono canario: 2–3 mensajes. **Lead:** `php artisan ultron:prompt-trace --last=5` → `applied`, versión V, observada `702890f7`, `S1`, ningún `mismatch` ni `legacy_drift`; lo mismo en «Turnos de la canaria». Respuestas de usted y de unos 200 caracteres.
6. **P1** (o quien tenga `ultron.rollback`): «Revertir» con motivo → el turno siguiente sale `legacy` (`part_legacy`), observada `3f6a5674`.

**Lo que no puede hacer una sola cuenta:** aprobar (paso 2), dar el veredicto (paso 3) y publicar (paso 4) sobre una versión creada por ella misma (`StudioVersions` / `StudioLabVerdicts.php:59-64` / `StudioPublications.php:1918-1932`). Lanzar la ejecución y revertir no exigen otra persona. Si P2 fuera quien publica, P1 tendría que concederle a mano `ultron.publish`, `ultron.lab_run` y `ultron.rollback` (`CrmPermission.php:302-310`).

**Riesgo de gobierno que no se debe usar como atajo:** los cuatro ojos son por cuenta; una segunda cuenta del mismo propietario pasaría todas las puertas (`ULTRON_STUDIO_MASTER_ROADMAP.md:136`, citado por la auditoría del 10-11; no releído). La prueba solo vale con una persona real distinta.

**[SUPOSICIONES de este punto, no releídas hoy]:** el laboratorio sigue en `openai` con presupuesto disponible (registro del 02-11); el freno de ULTRON está suelto (el turno de las 15:39 salió); la clave de OpenAI del laboratorio no se ha rotado ni revocado.

### 3.7 Textos del CRM que no dicen la verdad (pregunta 7)

Con la llave de prompts en `canary` (producción hoy). Los T1–T7 son INC-1; los T8–T10 son INC-7 e INC-8.

| # | Dónde | Qué dice | Qué debería decir |
|---|---|---|---|
| T1 | `CRM:studio-param-row.ts:119-121` con `StudioCatalog.php:686-688` y `:702` (tono, emojis, longitud, objetivo) | «Sin mando en el motor: el tono sale de los prompts de n8n…», «Lo cambia de verdad: Prompts de n8n…» y «Propuesta del borrador: el motor no la lee.» | «Gobierna los prompts de ULTRON en la conversación canaria cuando una versión con este valor está publicada en canario; fuera de ella, los prompts de siempre.» y «Lo cambia de verdad: publicar una versión en canario desde el Studio.» (en el CRM según la llave, sin tocar el catálogo congelado) |
| T2 | `CRM:studio-engine-text.ts:91` (lectura del motor de esos cuatro) | «Sin equivalente en el motor» | «Motor: lo fija la versión publicada en canario (hoy: Legacy)», con `status.engine.prompts.current` |
| T3 | `StudioCatalog.php:286`, `:356`, `:393`, `:420`, `:433`, pintados en `CRM:studio-inspector.ts:183-192` | «Los prompts, en n8n (sin versión por turno)», «No hay versiones publicables ni versión por turno», «No se registra por turno la versión del workflow, del prompt ni del modelo» | Una nota del CRM en la tarjeta de la capa: «Desde el 11-11, en la conversación canaria los prompts salen del Studio y cada turno anota la versión, la huella de los prompts y la revisión del flujo» |
| T4 | `CRM:studio-versions-panel.ts:263` | «Aprobar no publica nada: el motor de ULTRON no lee versiones del Studio.» | «Aprobar no publica nada. Solo una versión publicada en canario cambia lo que ULTRON dice, y solo en la conversación canaria.» |
| T5 | `CRM:studio-versions-panel.ts:519` | «Aprobada. No cambia al agente: el motor de ULTRON no lee versiones.» | «Aprobada. Todavía no cambia al agente: falta el veredicto del laboratorio y que otra persona la publique en canario.» |
| T6 | `StudioLabRuns.php:54` en `CRM:studio-lab-page.ts:105` y `studio-lab-run-view.ts:61` | «…No es ULTRON: el motor no lee el Studio y sus prompts viven en n8n.» | El texto de §3.4 |
| T7 | `CRM:studio-lab-verdict.ts:39`, `:85` | «No cambia la versión ni el motor.» | «No cambia la versión ni el motor por sí solo: un veredicto «Aprobada» es la condición para que otra persona pueda publicarla en canario.» |
| T8 | `CRM:studio-rules-panel.ts:138` | «…el motor de ULTRON no las aplica.» | «ULTRON por WhatsApp no las aplica. En la voz, las frases prohibidas de una regla sí entran en el agente compilado.» |
| T9 | `CRM:studio-integrations-text.ts:145`, `:213` | «El motor no los lee en este hito» / «sin efecto en el motor» | El texto por canal de §3.3 |
| T10 | `CRM:studio-integrations-text.ts:417` (motivo `no_voice_engine`) | «La voz no tiene motor en este hito: no rige ninguna versión.» | «La voz se rige por su propia cadena (Voz → Publicación); hoy no hay ninguna publicada.» |

Comprobados y **correctos**: el aviso de la página del Studio según la llave (`CRM:ultron-studio-page.ts:255-262`), los textos de publicar, revertir y Legacy según la llave (`studio-publication-text.ts:33-131`), los requisitos de la vista previa, que distinguen «apagado en el servidor» de «falta otra persona», «falta el veredicto» y «fuera de la ventana» (`:303-332`), y el texto del permiso (`PermissionCatalog.php:238-245`). Comentario de código desfasado, no visible: `CrmPermission.php:303-304` («el servidor las rechaza siempre: el motor no lee versiones»).

---

## 4. Riesgos

- **Desplegar INC-4 con V2 en canario** la devuelve a Legacy (`compiler_changed`) hasta volver a publicarla (§3.2).
- **La puerta del laboratorio aprueba una simulación**, no los prompts reales (L4). Hasta INC-5, la evidencia de lo publicado es la traza del turno real y la huella, no el veredicto.
- **Cuatro ojos por cuenta:** una segunda cuenta de la misma persona vacía el control (§3.6).
- **Fuera de este frente, visto de paso:** con 1 versión APPROVED, una estrategia del H9 ya no puede referenciar la línea base (`StudioStrategyValidator.php:34-37`, P9-10). No lo verifiqué contra producción.

## 5. Preguntas abiertas

1. ¿La versión APPROVED que ya existe es la de la prueba (tono profesional, 200 caracteres) y quién la creó? Cambia el paso 1 de §3.6.
2. ¿Se abre INC-4 (S-NNN en WhatsApp) antes del 15-11? Recomiendo decidirlo después de E4 (§3.2).

## 6. Resultado de la prueba ejecutada

`tests/Feature/UltronStudio/UltronStudioPublicationPromptsTest.php` (incluye la versión «profesional + 200» → oferta `studio` con huella `702890f7` → revertir → `part_legacy`), una sola vez, con `nice -n 10` y en SQLite, programada para arrancar a las 14:10 hora local por la regla de carga de la máquina. **Estado: PENDIENTE al cerrar este informe**; si esta línea no se sustituye por el resultado, no se llegó a ver. Evidencia anterior: la batería completa sobre `815b3ee` (L9-108) la incluyó, sin fallos fuera de los dos que cita esa fila; comprobado con `git diff --stat 815b3ee..HEAD` sobre `app/Services/UltronStudio/`, `app/Services/Marketing/Ultron/`, `app/Http/Controllers/Api/Internal/` y `config/marketing.php`: desde entonces solo cambiaron `StudioMetaTemplates.php` y `StudioVoiceLabSessions.php`, ninguno del camino de publicar ni del motor de WhatsApp.

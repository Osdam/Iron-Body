# Hito 9 · DOC9 · informe del `documentation-specialist`

**Fecha:** 11-11-2026, entre las 00:00 y las 01:30 UTC. **Encargo:** cerrar la brecha B-T11 de `ULTRON_STUDIO_AUDITORIA_9_HITOS.md`.

**Árbol:** `_worktrees/ultron-h8/Iron-Body/backend`, rama `feat/ultron-studio-h9`, HEAD `d701b0c8`, con el trabajo de otros agentes sin commit. El CRM, en `_worktrees/ultron-h8/Iron-Body_Front`, se leyó sin tocarlo.

**Límites del encargo, todos respetados:**
- solo documentación: no se tocó código, pruebas, guiones de ops ni los dos contratos;
- nada de producción, red, commits ni secretos.

**Excepción a «no tocar documentos del lead»:** una línea de enlace en `ULTRON_STUDIO_ACTIVACION_POR_ETAPAS.md`, como pedía el encargo.

## 0. Resumen

| Entregable | Fichero | Tipo y público |
|---|---|---|
| 1 y 4 · Matriz de completitud, con «Pendientes del propietario» | `docs/ultron-studio/ULTRON_STUDIO_H9_COMPLETITUD.md` (nuevo) | De referencia, para el propietario y el lead: separa siempre la implementación de la certificación operativa |
| 2 · Runbook de las etapas 4 a 7 | `docs/ultron-studio/ops/RUNBOOK_H9.md` (nuevo) | Operativo, para quien opera el servidor como root y para las personas del CRM |
| 2 · Enlace | `ULTRON_STUDIO_ACTIVACION_POR_ETAPAS.md` (una línea, después del párrafo «Mandos») | — |
| 3 · Sección del H9 | `ULTRON_STUDIO_DEPLOYMENT_PLAN.md` (antes de «Fases siguientes») | Operativo: la fase 1 ejecutada, la vuelta atrás, las incidencias y lo que no tiene guion |
| 3 · Fila del H9 en «Estado» y sección «Hito 9» | `ULTRON_STUDIO_FINAL_REPORT.md` | Descriptivo, con el estilo del H8 |
| 3 · Fila 9 de la tabla y su línea en «Registro de despliegues» | `ULTRON_STUDIO_MASTER_ROADMAP.md` | Descriptivo |
| Este informe | `plans/informes-h9/informe-doc9.md` | — |

**Por qué en esos sitios:**
- la matriz, junto a la del H8, con el mismo nombre de patrón;
- el runbook en `ops/`, como pidió el lead, junto al mando que describe;
- las secciones, donde la auditoría (B-T11, §6 puntos 2 a 4) señaló que faltaban.

**No toqué `ops/README.md`.** Es un fichero de devops que está en uso. Le falta una línea que apunte a `RUNBOOK_H9.md`: queda para su dueño.

## 1. Cómo verifiqué

Cada afirmación sale del código del árbol, citado con `fichero:línea`, o de un registro fechado del contrato (L9-n) o de un informe. **Producción no se leyó:** lo de producción es de L9-76 y L9-77.

**Lectura directa del código (lo esencial):**
- **El mando entero** (`ops/h9-outreach-scope.sh`, 692 líneas): las órdenes, las claves que escribe cada una, las negativas, la salida y los códigos de salida.
  - Comparé la versión de `ce7c150` con la del árbol: `git show ce7c1506:… | shasum -a 256` da `aca123d6…0e24`, con `TAREAS_PROGRAMADOR=3`; la del árbol da `5458df40…7976`, con 5.
- `config/outreach.php`, `OutreachConfig` (el horario propio con `;`), `OutreachSendGate` (la constante en `:38` del árbol y en `:35` de `ce7c150`; `LIVE_CHANNELS`) y el bloque del programador (`routes/console.php:495-539` en el árbol y `:484-513` en `ce7c150`).
- Los permisos: `PermissionCatalog.php:172-181`, `AuthorizationMap.php:824-869` y el reparto por rol en `CrmPermission.php:288-343` (`ce7c150`).
- Los frenos y su suelta: `OutreachStop.php:71-76` y `:304-346`, y `UltronStudioOutreachController.php:118-123` (`requireEnabled`, que solo mira la maestra) y `:215-241`.
- El monitor (`OutreachMonitor::checkBudgets()`).
- La evidencia del canario (`StudioStrategyEvidence.php:103-129`: solo trabajos `live`, `started` y no simulados).
- El alcance del canario en vivo (`LadderInputBuilder.php:106` en `ce7c150`; `PolicyLadder.php:428-429`; `CanaryFacts.php:10-21`).
- Los cuatro ojos: al aprobar y publicar (`StudioStrategyPublishing.php:337-346`) y el vínculo exigido para pausar y revertir (`:165`, `:212`).
- El arranque de los vínculos (`PersonBindingsCommand.php:33-40`, `StudioPersonBindings.php:290-311`).
- Las tablas de las migraciones del H9 y los 4 índices de la `100003` (`:56-61`).
- El guion de la fase 1 del H9: `PREVIO` en `:88`, la puerta de diff en `:96-108`, el rechazo de `outreach(sends-open)` en `:400`, los mensajes de `:503`, `:506` y `:422`, y la vuelta atrás en `:47-53`.

**Comprobaciones ejecutadas (solo lectura):**
- `git status`, `git diff HEAD --stat` y `git show <commit>:…` sobre los ficheros citados.
- `grep` de la tabla de W9 en los guiones del H9: no aparece.
- La lista de `ops/perf-h9/`: no existe.
- `exercise()` de `OutreachIsolationTest`: 0 rutas `outreach/`.
- El defecto H9-E2E-1, en los dos repositorios: `StudioStrategies.php:274` y CRM `commercial-automation-parse.ts:406`, ninguno corregido.
- Las 9 pestañas del CRM (`commercial-automation-page.ts`, en `9572426`).
- La evidencia del E2E del 11-11 a las 00:30 UTC: `resumen.txt` y los nombres de las 12 comprobaciones fallidas en `checks-h9.jsonl`, sin leer datos.

**No ejecuté pruebas, guiones, el banco ni el smoke.** Ninguna cifra de pruebas de los documentos es mía: todas van citadas.

## 2. Lo que no pude verificar (queda marcado en los documentos)

1. **Todo lo que es producción:** la lectura a las 24 h, que no consta; qué versión del mando hay en `/root`; el reparto de llaves en la pantalla de Roles; el estado de `COMMERCIAL_EVENTS_ENABLED`, que el paso 0 del lead leyó el 09-11.
2. **Las salidas esperadas del runbook** salen del código del mando y del banco (L9-71), no de una ejecución en producción. El runbook lo dice: «NUNCA EJECUTADO EN PRODUCCIÓN».
3. **Lo marcado como *deducido del código*:**
   - que bajar un cupo por debajo de lo ya reservado ese día (`asistido off`, `canal … off`) abre el freno `budget_breach` en la siguiente pasada del monitor. Lo confirma el comentario nuevo de b9-cierre en `OutreachMonitor` («bajarlos por debajo de lo reservado frena el canal»), pero no está ensayado;
   - que, tras `apagar`, soltar un freno exige volver a encender la maestra;
   - que `gobierno off` no para la ejecución: nada en `app/Services/Outreach` lee `ultron_studio.strategies.enabled`.
4. **El resultado final del E2E #26:** el tester no ha cerrado su informe (§B.4). Usé la evidencia de la última vuelta (182/194) y no expliqué la causa del fallo del botón de soltar la parada.
5. **Lo que está en curso** (b9-cierre, pm2-voz, sec-cierre, tester y devops OPS8-e) va marcado con ⏳ y **«PENDIENTE DE CIERRE DEL LEAD»**, sin describir su comportamiento final. En particular, el nombre de la orden que registra lo aprobado por Meta está «por confirmar».
6. **El uuid de las identidades para el arranque:** comprobé que es `identities.uuid`, el mismo que usa el módulo de personal (`StaffRecord.php:50-52`). No documenté cómo se lee sin exponer datos: es un hueco (§5, pregunta 4).

## 3. Contradicciones entre documentos y código

| # | Dónde | Qué dice | Qué hace el código (o qué es cierto) | Determinación | Para |
|---|---|---|---|---|---|
| 1 | `ULTRON_STUDIO_ACTIVACION_POR_ETAPAS.md:66` (paso 4.5) | «evidencia del canario (≥ 24 h y ≥ 3 toques simulados)» | Solo cuentan los trabajos `started` en modo `live` que no son simulados (`StudioStrategyEvidence.php:112-115`; L9-40). En sombra no se puede promover | **El código es lo correcto** (y coincide con el contrato §14: la promoción es de la fase 3). Error del documento | Lead (su documento) |
| 2 | `ULTRON_STUDIO_ACTIVACION_POR_ETAPAS.md:62` (paso 4.1) | «mando del motor comercial» para `COMMERCIAL_EVENTS_ENABLED` | No existe. El mando del H9 deja fuera el motor comercial a propósito (`h9-outreach-scope.sh:62-63`) y ningún guion de `ops/` escribe esa variable | Hueco de procedimiento, no un error de código | Lead y `devops` |
| 3 | `ULTRON_STUDIO_ACTIVACION_POR_ETAPAS.md:65` (paso 4.4) | `sombra on` → `programador on` → `monitor on` | `sombra on` ya escribe el programador y el monitor (`h9-outreach-scope.sh:381`): las dos órdenes siguientes responden «nada que escribir» | Redundante, no dañino | Lead |
| 4 | `ULTRON_STUDIO_ACTIVACION_POR_ETAPAS.md:73` (etapa 5) | No menciona los números canarios | Con la estrategia en `CANARY` y en vivo, el asistido solo alcanza a personas con un número canario ACTIVO del H6 para el medio del paso (D9-11.6; `LadderInputBuilder.php:106`; `PolicyLadder.php:428-429`). Sin la fase 2 del H6 no hay evidencia ni promoción | Falta una precondición en el documento | Lead |
| 5 | Contrato H9 L9-52, Q-7 (`ULTRON_STUDIO_H9_CONTRACT.md:1511`) | «`apagar` (nivel 0) también pone los topes a 0 y apaga el clasificador» | `apagar` escribe 7 claves, sin cupos, presupuesto ni clasificador (`h9-outreach-scope.sh:348-352`). Ya lo preguntaba el `informe-devops.md` §9, Q-7 | **Bug del mando**: la decisión del lead no se implementó | `devops` |
| 6 | P9-17 (contrato `:1400`) y `config/outreach.php` (`ce7c150` `:111-119`) | Horario propio con tramo de sábados (`HH:MM-HH:MM;HH:MM-HH:MM`) | El mando solo acepta una franja (`h9-outreach-scope.sh:202-207`, `:453-456`). `OutreachConfig::ownHours()` sí admite `;` (`ce7c150` `:129-149`) | Limitación del mando | `devops` |
| 7 | `config/outreach.php:96` y `OutreachConfig.php:95` (`ce7c150`) | «0 = sin precio = `budget_zero`» (el precio de WhatsApp) | La escalera solo da `budget_zero` si el presupuesto o el cupo son ≤ 0 (`PolicyLadder.php:392-395`). Con precio 0 se reserva 1 unidad y 0 micros (`OutreachPlanner.php:641-642`) y el canal solo exige la unidad (`WhatsappTemplateChannel.php:128`, árbol): **el presupuesto en US$ no limita nada** | **El comentario es falso; el código es el que manda.** Riesgo operativo (W9), y el mando no escribe los precios | B9-run o lead (comentario); `devops` (mando) |
| 8 | `PermissionCatalog.php:257` (texto de `ultron.strategies_publish`) | «los cuatro ojos: quien aprueba no publica» | El código solo impide publicar o promover a la **autora** (`StudioStrategyPublishing.php:337-346`), igual que el contrato D9-11.2 («publica alguien distinto de la autora»). Quien aprueba sí puede publicar | El texto de la llave es inexacto (se ve en Configuración → Roles) | B9-run o lead |
| 9 | `OutreachSendGate.php:24-29` (árbol) | «la voz (V9) sigue con su puerta VACÍA y responde `channel_not_live`» | `:41-45` declara `voice_call` en `LIVE_CHANNELS` (L9-81, G-2) | Comentario desfasado | Lead |
| 10 | `h9-outreach-scope.sh:621-622` (árbol) | «agenda sus 3 tareas» | `TAREAS_PROGRAMADOR=5` (`:90`) | Comentario desfasado | `devops` |
| 11 | Contrato H9 `:610`, `:1155`, `:1187`, `:1322`, `:1399`; `config/outreach.php:101` (`ce7c150`); `informe-v9.md:3` | «Enmienda 16 del H8» para V9 | La de V9 es la **Enmienda 17** (`ULTRON_STUDIO_H8_CONTRACT.md:988`; L9-81). La 16 fue el cierre integral del H8 | Numeración desfasada | Lead (contratos) |
| 12 | `ULTRON_STUDIO_H9_ACEPTACION.md:41` | «Esquema (14 tablas…)» | 15 en producción (7 + 8) y 16 en el árbol con W9 | Ya estaba en la auditoría (§6, punto 5) | Lead |
| 13 | `ops/smoke-run-h9.sh:285` | «33 tablas (25 del Studio y 8 de outreach)» | El recuento es dinámico (`LIKE`); con W9 serán 34 (26 + 8) | El mensaje quedará desfasado al desplegar W9 | `devops` |
| 14 | `ops/deploy-h9-backend-fase1.sh:47` | La vuelta atrás, «en `ULTRON_STUDIO_DEPLOYMENT_PLAN.md`, «Hito 9», y `ULTRON_STUDIO_ROLLBACK.md`» | El plan **ya tiene** la sección del H9 (añadida hoy). **`ULTRON_STUDIO_ROLLBACK.md` no tiene nada del H9** | La mitad corregida; falta ROLLBACK | DOC9 (pendiente, §4) |
| 15 | `ops/h9-e2e/README.md:37` | «`ultron-studio:person-bindings --bootstrap`, P9-24» | El arranque de los vínculos es P9-4 y P9-11; P9-24 es `COMMERCIAL_EVENTS_ENABLED` | Etiqueta equivocada | `tester` |
| 16 | `plans/informes-h9/informe-run.md:142` | El bloque del programador «COMENTADO» | Activo detrás de su bandera (`routes/console.php:484-513` en `ce7c150`) | Ya estaba en la auditoría (§6, punto 11) | B9-run (informe histórico: marcarlo con fecha) |

**Observaciones de operación** (no son contradicciones, pero las recojo en el runbook):
- **El mando tiene que ser del mismo commit que el código.** El del árbol (5 tareas) sobre `ce7c150` (3 tareas) acaba con un desvío del programador. Lo mismo pasa con `smoke-run-h9.sh` (`TAREAS_SOMBRA` 3 → 5).
- **`gobierno off` no sirve de freno:**
  - la ejecución no consulta esa bandera;
  - además deja sin efecto pausar y revertir: todas las rutas `strategies/**` responden 409 con el gobierno apagado (`StudioStrategyConfig.php:15-21`).
- **El comprobante de clase del mando es débil.** `canal whatsapp on` y `canal voz on` comprueban que exista una clase cuyo nombre contenga `WhatsappTemplateChannel` o `Voice` (`clases_outreach`, `:195`, `:409`, `:420`). Las puertas vacías de `ce7c150` ya la cumplen. No abre nada, porque la constante cierra antes, pero la comprobación no distingue entre la puerta vacía y la real.
- **Pausar y revertir exigen un vínculo verificado** (`StudioStrategyPublishing.php:165`, `:212`). Quien no lo tenga usa el freno de la estrategia o la PARADA GLOBAL, que no lo exigen (L9-39).

## 4. Documentación obsoleta encontrada (no corregida: fuera de los cuatro entregables)

| Documento | Qué | Recomendación |
|---|---|---|
| `ULTRON_STUDIO_FINAL_REPORT.md:22-34`, «Los siete hitos, de un vistazo (02-11-2026)» | Da el H7 como «sin desplegar» y no tiene los hitos 8 ni 9 | Marcarlo con su fecha como foto histórica, o rehacerlo como «Los nueve hitos» (auditoría §6, punto 3) |
| `ULTRON_STUDIO_MASTER_ROADMAP.md:14` (fila 8) y el registro de despliegues | La fila del H8 da producción en `fe29126` / `45dd73a` y tiene 3 celdas en una tabla de 4. El registro no tiene el 1b del H8 (10-11, 02:20–02:24 UTC, `a608450` / `e0f7983`, E16-30) y su fila del H8 tiene 4 celdas en una tabla de 5 | Corregir: dato de E16-30 y formato |
| `ULTRON_STUDIO_MASTER_ROADMAP.md:64` | «Pendientes del propietario … (consolidado al 02-11-2026)» | Marcarlo con fecha y apuntar a `ULTRON_STUDIO_H9_COMPLETITUD.md` §6 y a la auditoría §4.2 |
| `ULTRON_STUDIO_GUIA_ADMINISTRACION.md:480` | El texto viejo de B-T8 («la constante … hasta la fase 3») | Corregir conforme a E16-16 (el CRM ya lo hizo en L9-79) |
| Lo que el contrato da a DOC9 (§11) y este encargo no pedía | `ULTRON_STUDIO_AUTOMATIZACION.md`, la guía de administración, la lista operativa, ROLLBACK, SECURITY, BACKEND, VISUAL y `ops/README.md` del H9 | Pendiente: un encargo aparte cuando W9 y V9 tengan commit |

## 5. Huecos de información (preguntas concretas)

1. **Lead o `devops`:** ¿con qué procedimiento se enciende `COMMERCIAL_EVENTS_ENABLED` (P9-24)? ¿Un mando nuevo, o una orden de `h9-outreach-scope.sh` que hoy lo excluye a propósito?
2. **`devops`:** ¿quién escribe `OUTREACH_WHATSAPP_PRICE_*`, los cupos de voz del H9 y el tramo de los sábados? ¿Ampliáis el mando?
3. **`devops`:** ¿cuál será el guion que despliegue W9, V9, L9-82, L9-83 y P9-25 (G-9), y cuál el del commit `outreach(sends-open):`?
4. **Lead:** ¿cómo obtiene el operador el uuid de la identidad de las dos primeras personas sin exponer datos? ¿Desde el módulo de personal del CRM?
5. **Lead:** ¿qué versión del mando hay hoy en `/root`? La activación por etapas dice que está; el runbook pide comparar la huella con la de `ce7c150`.
6. **`tester`:** la causa del fallo «a quien abrió la parada no se le ofrece soltarla» del E2E del 11-11.
7. **Propietario (vía lead):** ¿el criterio para salir de la sombra es solo «sin hallazgos» (contrato §14), o quiere umbrales concretos? El runbook propone qué mirar, pero no lo convierte en criterio.

## 6. Riesgos

- **Secretos:** no encontré ninguno expuesto en lo que leí ni reproduje ninguno.
  - `ULTRON_STUDIO_ACTIVACION_POR_ETAPAS.md:20` y `ULTRON_STUDIO_PROVEEDORES_ESTADO_REAL.md:12` llevan **completo** el identificador del número importado en ElevenLabs (`phnum_…`). No es una credencial, pero la norma de estos documentos es enmascarar identificadores: lo decide `security`. Yo no lo copié.
  - Los hashes de commit, las rutas de respaldo y de registro y las huellas sha256 de los guiones que cito no son secretos.
- **Para el propietario:**
  1. **H9-E2E-1 está desplegado y dormido:** en cuanto se encienda el gobierno, crear una estrategia desde el CRM fallará.
  2. **Con el precio de WhatsApp en 0, el presupuesto diario en US$ no frena nada** (#7).
  3. **La etapa 5 no puede acumular evidencia del canario sin la fase 2 del H6** (#4).
- **Concurrencia:**
  - `ULTRON_STUDIO_DEPLOYMENT_PLAN.md` tenía cambios sin commit de devops (OPS8-e, dentro del H8). Mi cambio es un único bloque añadido antes de «Fases siguientes» y no toca el suyo.
  - `config/outreach.php`, `OutreachConfig`, `OutreachDispatcher`, `OutreachMonitor` y `OutreachPlanner` cambiaron mientras escribía (b9-cierre). Por eso fijé en `ce7c150` las citas de líneas de esos ficheros.
- **Comunicación:** no tuve disponible la herramienta de mensajes del equipo (SendMessage). Este informe y el resumen final son el canal.

## 7. Para el lead (resumen)

- **Hecho:** los cuatro entregables, más este informe.
  - El H9 queda documentado en la matriz (con los pendientes del propietario), el runbook de las etapas 4 a 7, el plan de despliegue, el informe final y la hoja de ruta.
  - Lo que está en curso va con ⏳ y «PENDIENTE DE CIERRE DEL LEAD».
- **Lo que necesita decisión o arreglo:**
  - **H9-E2E-1** (S9, bloquea la etapa 4.3);
  - el mando: `apagar` contra L9-52 Q-7, el horario de los sábados, los precios y los cupos de voz, y P9-24 sin procedimiento;
  - el comentario falso del precio de WhatsApp y el texto de la llave `strategies_publish`;
  - la precondición de los canarios del H6 en la etapa 5;
  - el «≥ 3 toques simulados» de tu documento de activación;
  - la numeración «Enmienda 16 → 17» en el contrato.
- **No verificado:** producción, el mando en `/root`, el final del E2E #26 y las deducciones marcadas.

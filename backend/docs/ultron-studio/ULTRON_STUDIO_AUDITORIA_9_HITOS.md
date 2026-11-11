# ULTRON STUDIO · Auditoría real de los nueve hitos (fase 1)

**Fecha:** 10-11-2026 (UTC). **Encargo:** del propietario, «Fase 1 — auditoría real de los nueve hitos», por medio del lead. **Autor:** `architect`, en solo lectura: no se tocó código, ningún documento existente ni producción.

**Qué se leyó:** backend `feat/ultron-studio-h9` = `origin/main` en `d701b0c` (producción corre `ce7c150`; `d701b0c` solo añade el registro del despliegue) y CRM `feat/ultron-studio-h9` = `main` en `9572426` (= producción). Las citas `fichero:línea` son de esos árboles: las de código, relativas a `backend/`; las de documentos, relativas a `backend/docs/ultron-studio/`; las del CRM llevan el prefijo «CRM».

**Regla de esta auditoría:** un hito no está completo por sus pruebas unitarias. Manda el criterio de cierre de su contrato, con sus enmiendas y matrices. Lo que no está probado se dice como no probado; lo que es una dependencia externa no se cuenta como hecho.

---

## 0. Cómo leer este documento

### 0.1 Hechos de producción usados (no releídos por esta auditoría)

Lectura del lead del 10-11-2026:

- Backend `ce7c150` y CRM `9572426`, desplegados el 10-11-2026 entre las 20:21 y las 20:27 UTC, con vigilancia de 15 minutos sin incidencias (L9-76 y L9-77, `ULTRON_STUDIO_H9_CONTRACT.md:1535-1536`).
- Hito 8 en fase 1 + 1b, con **todas** las banderas de voz ausentes o apagadas; piezas de ElevenLabs presentes (clave, agente, dos secretos y región); Twilio Voice sin configurar; worker `ironbody-voice` sin instalar; 1 perfil de voz aprobado; 0 publicaciones.
- Hito 9 en fase 1: sus 15 variables apagadas, 15 tablas vacías, 121 rutas del Studio y el programador sin tareas del H9.
- Plan de ElevenLabs gratuito.
- Una llamada real salió por Twilio a mano, desde la consola de ElevenLabs y fuera de Laravel, con un agente creado a mano que habló en inglés (`ULTRON_STUDIO_H8_COMPLETITUD.md:11`). El propietario la comunicó el 09-11; según la sonda del lead, es la única del historial de la cuenta y se hizo el 03-11-2026 a las 22:48 UTC, con 53 s (`PROV:22`).

**Sonda de proveedores del lead** (10-11-2026, solo GET a ElevenLabs y Twilio): `ULTRON_STUDIO_PROVEEDORES_ESTADO_REAL.md`, sin commit al cerrar esta auditoría (`PROV`). Añade hechos que aquí se usan: el número se importó en ElevenLabs con el **Auth Token maestro de la cuenta raíz de Twilio, la del OTP** (A-1 confirmado, `PROV:13`); el perfil de voz 1 ya tiene la voz «Lina - Colombian Warm & Confident» (`PROV:11`); en ElevenLabs no hay webhooks ni secretos de Laravel (`PROV:14-15`); la cuenta de Twilio no tiene subcuentas, API keys ni números propios (`PROV:21`, `PROV:24`).

Lo que esas lecturas no cubren se toma del último registro fechado y se marca **«según el registro del dd-mm; no releído»**.

### 0.2 Taxonomía de estados del CRM (literal del propietario) y regla de asignación

| Estado | Cuándo se usa aquí |
|---|---|
| «disponible y operativa» | Está en producción, ninguna bandera la cierra y hace lo que promete |
| «configurada y lista para activación» | El código y la configuración están en su sitio; falta solo encenderla con el mando y la autorización escrita |
| «pausada voluntariamente» | Está apagada por decisión (bandera), no por un requisito incumplido |
| «bloqueada por condición contractual» | La impide un requisito del contrato aún no cumplido: segunda persona, veredicto, evidencia del canario, visto bueno legal o una decisión del propietario |
| «pendiente de proveedor externo» | La impide algo que solo da un proveedor: plan de ElevenLabs, cuenta, claves o número de Twilio, plantillas de Meta |
| «error técnico real» | Falla hoy en producción |

Cuando concurren varias causas, el estado es la **primera que se encuentra en la cadena de activación** y las demás van en «por qué y qué falta».

### 0.3 Método del porcentaje (§5)

**% = 0,4 × K + 0,2 × D + 0,4 × O**, donde:

- **K, construcción:** la fracción de los entregables del contrato confirmados: paquetes, revisiones sin bloqueantes, pruebas automáticas y E2E. Se descuenta lo que la matriz del propio hito marca como pendiente de desarrollo.
- **D, despliegue:** el código del hito en producción y verificado por su smoke.
- **O, activación y operación real:** la fracción de los criterios de cierre que piden activar u operar de verdad (fases posteriores a la 1, validaciones con proveedores reales, cuatro ojos reales), cumplidos con evidencia en producción. Si el contrato no tiene fases de activación (hitos 1–3), O = 1 cuando sus funciones están encendidas y verificadas en producción.

Construcción y operación pesan lo mismo porque el encargo prohíbe dar un hito por completo por sus pruebas; el despliegue de la fase 1 pesa la mitad porque es necesario, pero no demuestra operación. **El porcentaje mide criterios cumplidos, no esfuerzo restante:** un hito al 70 % puede necesitar solo una sesión del propietario.

### 0.4 Abreviaturas de las fuentes

| Sigla | Documento |
|---|---|
| `HR` | `ULTRON_STUDIO_MASTER_ROADMAP.md` (el encargo lo nombra `ULTRON_STUDIO_ROADMAP.md`: ese fichero no existe) |
| `IF` | `ULTRON_STUDIO_FINAL_REPORT.md` |
| `PDS` | `ULTRON_STUDIO_DEPLOYMENT_PLAN.md` |
| `PF` | `ULTRON_STUDIO_PRUEBAS_FISICAS.md` |
| `CG`, `C4` … `C9` | `ULTRON_STUDIO_G_CONTRACT.md`, `ULTRON_STUDIO_H4_CONTRACT.md` … `ULTRON_STUDIO_H9_CONTRACT.md` |
| `M8` | `ULTRON_STUDIO_H8_COMPLETITUD.md` |
| `CI8` | `ULTRON_STUDIO_H8_CIERRE_INTEGRAL.md` |
| `PP8` | `ULTRON_STUDIO_H8_PREPARACION_PLAN_PAGO.md` |
| `A9` | `ULTRON_STUDIO_H9_ACEPTACION.md` |
| `PROV` | `ULTRON_STUDIO_PROVEEDORES_ESTADO_REAL.md` (sonda del lead del 10-11; sin commit al cerrar esta auditoría) |
| E*n*-*m*, L9-*n* | Enmiendas del contrato del H8 y decisiones del lead del contrato del H9 |
| A-*n*, B-*n*, C-*n* | Pendientes consolidados de `HR:68-116` |
| D-*n* | Dependencias del propietario del H8 (`CI8:75-89`) |
| B-T*n*, B-A*n* | Brechas que identifica esta auditoría (§4.1) |

---

## 1. Resumen ejecutivo

1. **Construido:** los nueve hitos tienen su entregable y los hitos 2–9 tienen todo su código en producción (backend `ce7c150`, CRM `9572426`). Las pruebas automáticas y los E2E con simuladores son extensos (§2), pero **ningún E2E se ha hecho contra proveedores reales** y **la única prueba física con un proveedor real hecha a través de Laravel es la validación 1 del laboratorio con OpenAI** (02-11-2026, 0,014 US$). Lo demás en producción son smokes automáticos de despliegue y un uso real del Studio: el perfil de voz creado y aprobado el 09-11.
2. **Operando de verdad hoy:** el tablero y su registro (hitos 2–3), el laboratorio con OpenAI (hito 4, según el registro del 02-11), la lectura de Meta Ads y el panel del OTP de Twilio (hito 6) y el endurecimiento del hito 7. **Todo lo demás está desplegado y apagado:** la publicación (H5), las escrituras y la sonda de integraciones (H6), el monitor (H7), la voz entera (H8) y la automatización comercial (H9).
3. **Nada de lo construido cambia hoy lo que ULTRON dice o hace por WhatsApp, y la publicación del H5 tampoco lo cambiaría al encenderla:** el contrato eligió la opción A, una «declaración» firmada que el motor sella en cada turno; la fila «Aplicación» dice «Vacío. El motor solo sella» (`C5:9`, `C5:35`, `C5:286`; `CG:22-27`). Solo la voz (H8) y las estrategias (H9) convierten lo aprobado en comportamiento. Si el propietario espera gobernar a ULTRON desde el editor de las diez capas, eso no está en ningún contrato (brecha de alcance B-A1, §4.1).
4. **El bloqueante que más se repite no es el plan de pago: es una segunda persona real con cuenta propia.** Frena la validación 2 del H4, las fases 2 y 3 del H5, el T1 del H6, el laboratorio y la publicación de la voz del H8 y el gobierno del H9.
5. **El plan de pago de ElevenLabs solo bloquea una cadena de cinco pasos de la voz:** sesión real del laboratorio hablado → publicación canaria → smoke 4a → primera saliente → evidencia y promoción; por arrastre, la fase 4b y la voz por estrategia del H9 (V9). Todo lo demás se puede hacer sin plan (`PP8:160-167`).
6. **Brechas técnicas reales que no dependen del plan: 17** (§4.1). Las de más impacto: **no existe vía para desplegar el commit `voice(calls-open)`** (`ops/deploy-h8-backend-fase1.sh:70`, `:333-338`), sin el cual no hay salientes aunque se pague el plan; la guarda de la voz frena 13 de 15 negativas naturales (M-2); la supresión de la Ley 1581 no cubre las 11 tablas de voz y **los contactos de voz se pueden registrar hoy sin bandera**; y del H9 faltan TEST9-b (E2E #26), PERF9 y DOC9.
7. **Ninguna función visible del CRM está en «error técnico real»** según la evidencia del 10-11 (smoke del H9 37 OK · 0 FALLO; vigilancia sin respuestas 5xx). Hay defectos latentes que saldrían al activar (§4.1).
8. **Riesgo de seguridad confirmado hoy, independiente del plan:** ElevenLabs custodia el Auth Token maestro de la cuenta de Twilio que firma el OTP de todos los socios (A-1, `PROV:13`), y la clave de ElevenLabs pasó por el chat (A-2). Rotar el token maestro y la clave, y reimportar el número con una credencial de alcance mínimo, va primero (§8).

| Hito | Cierre según su contrato | En producción | % (§5) | Qué falta para cerrarlo |
|---|---|---|---|---|
| 1. Arquitectura | `ARCHITECTURE_APPROVED_FOR_BUILD` (17-10) | Documentos | **100 %** | Nada del contrato; queda abierta la pregunta Q6 (B-A2) |
| 2. Editor de las diez capas | Completado (`ULTRON_VISUAL_PREVIEW_READY`) | Sí, desde el 18-10 | **100 %** | Nada del contrato; menores en §2 |
| 3. Persistencia y motor de control | `HITO_3_COMPLETED` | Sí (F 18-10, G 19-10) | **100 %** | Nada del contrato; la aprobación a cuatro ojos nunca se ha ejercido en producción |
| 4. Laboratorio de IA | `HITO_4_IMPLEMENTED_WITH_EXTERNAL_DEPENDENCY` | Fases 1–3 | **70 %** | Validación 2 y cotejo del coste con el panel de OpenAI |
| 5. Gobierno y versionado | Fase 1 (`HITO_5_COMPLETED` exige las fases 1–3) | Fase 1, apagada | **60 %** | Fases 2 y 3 |
| 6. Integraciones | `HITO_6_DEPLOYED_PHASE_1` | Fase 1 | **70 %** | Fase 2: T1, configuración reconocida y sonda |
| 7. Seguridad, permisos y monitorización | `HITO_7_COMPLETED` «con desviación» | Sí, desde el 03-11 | **80 %** | El monitor sigue apagado (A-7) |
| 8. Voz y telefonía | `HITO_8_NOT_YET_COMPLETE` | Fase 1 + 1b, apagada | **56 %** | Fases 2, 2a y 4a (3 y 4b); 5 pendientes técnicos; el plan de pago |
| 9. Autonomía comercial | Fase 1 (`HITO_9_COMPLETED` exige sombra y asistido) | Fase 1, apagada | **52 %** | TEST9-b, PERF9 y DOC9; fases 2 y 3 |

---

## 2. Hito por hito

Cada tabla tiene las nueve columnas del encargo. Si el hito tiene varias piezas, cada fila es una pieza y su nombre va en negrita al principio de la primera columna.

### Hito 1 · Arquitectura de ULTRON Studio

| Código implementado | Código desplegado (commit) | Configuración disponible | Dependencias conectadas | Pruebas automatizadas | Pruebas E2E | Pruebas físicas | Condiciones pendientes | Estado de operación real |
|---|---|---|---|---|---|---|---|---|
| **Auditoría y decisiones (documental).** Motor real (Laravel + n8n, 28 controles del commit, canario, vigía), las diez capas frente a lo que existe, reglas, grafo de dependencias y decisiones: Laravel conserva la autoridad, sin Python, el grafo es el del motor real, Foblex Flow 19.3.0 (`ULTRON_STUDIO_ARCHITECTURE_AUDIT.md` §1–§14; `ULTRON_STUDIO_TECH_STACK.md`; `IF:824-831`) | No aplica: documentos en `origin/main` del backend | No aplica | No aplica | No aplica | No aplica | No aplica. Lo «por verificar en el servidor» (`ULTRON_STUDIO_ARCHITECTURE_AUDIT.md:160`) lo cubrieron las lecturas de G, H5 y H6 | De las 6 preguntas de §14 (`ULTRON_STUDIO_ARCHITECTURE_AUDIT.md:153-158`): **Q6** (¿el Studio escribe prompts en n8n o solo la base de conocimiento?) **sin respuesta registrada**; **Q3** (número de voz) respondida por el H8 (el número es un caller ID verificado, E16-1), con la decisión del propietario pendiente (D-3, D-10); **Q1** parcial (el H5 sella la versión del Studio en cada turno; no consta que se añadiera la versión del flujo de n8n); Q2, Q4 y Q5, resueltas por el H6, el H8 (P-3) y el H4 | `ARCHITECTURE_APPROVED_FOR_BUILD` el 17-10-2026 (`HR:7`). Vigente: los hitos 2–9 la respetan |

### Hito 2 · Editor de las diez capas

En el contrato, el «editor» es el Hito 2 (tablero visual) más la configuración funcional por capa de G, que es parte del Hito 3 (fila G, abajo).

| Código implementado | Código desplegado (commit) | Configuración disponible | Dependencias conectadas | Pruebas automatizadas | Pruebas E2E | Pruebas físicas | Condiciones pendientes | Estado de operación real |
|---|---|---|---|---|---|---|---|---|
| **Tablero visual.** CRM `src/app/modules/ultron-studio/` (`studio-graph.ts`, `studio-inspector.ts`, `studio-a11y.ts`…): las diez capas como nodos, las 19 aristas del turno real (17 obligatorias), validación del grafo, deshacer y rehacer, teclado, lector de pantalla y tres tamaños de pantalla (`IF:42-52`). Ruta `/marketing/ultron-studio` tras la bandera de compilación y `ultron.view` (CRM `src/app/app.routes.ts:237-244`; menú en CRM `src/app/app.html:126`) | CRM `4f7d774`, 18-10-2026 02:56 UTC (`HR:8`, `IF:861-868`); hoy dentro de `9572426` (comprobado: `4f7d774` es ancestro) | `ultronStudioPreviewEnabled: true` en el build de producción (CRM `src/environments/environment.prod.ts:24`). Sin variables de servidor | Foblex Flow 19.3.0, sin red. Desde F, el catálogo lo sirve el backend (`GET catalog`) | Módulo 46/46; suite del CRM 2 066 con los 16 rojos de la línea base; `check:literals`, `tsc` y build en verde (`IF:71-76`) | 44/44 en Chrome real (`IF:77`) con un guion que vivía en un scratchpad (`scratchpad/e2e-studio/studio.cjs`), **no en el repo**; hoy cubren la página CRM `e2e/ultron-studio/specs/{f-versions,g-inspector-compare,cierre}.cjs` | Verificación pública tras publicar (`IF:868`). **Sin probar** en Safari, Firefox y con gestos táctiles reales (`IF:874`) | Ninguna del contrato. Menores abiertos: F-4, la hoja `ultron-studio.scss` en 27,88 de los 32 kB que rompen el build (`HR:103`); el asistente flotante tapa «Archivar la regla» a 390 px (`HR:197`); una regla global pisa el color de los avisos (`HR:196`) | En producción para Super Admin y Administrador. **Lo que se edita no cambia el agente:** posición, configuración y reglas S-NNN no tienen efecto en el motor (`CG:22-27`) |

### Hito 3 · Persistencia y motor de control Laravel (F y G)

| Código implementado | Código desplegado (commit) | Configuración disponible | Dependencias conectadas | Pruebas automatizadas | Pruebas E2E | Pruebas físicas | Condiciones pendientes | Estado de operación real |
|---|---|---|---|---|---|---|---|---|
| **F · Persistencia.** 5 tablas y sus guardas (`database/migrations/2026_10_18_100001_create_ultron_studio_tables.php`, `…_100002_add_ultron_studio_guards.php`); borrador por persona con conflicto 409; versiones inmutables `DRAFT → VALIDATED / REVIEW_REQUIRED → APPROVED / REJECTED` con cuatro ojos **por cuenta**; las 12 reglas del código como `LEGACY_BASELINE_V1`; libro de solo inserción; llaves `ultron.*` validadas ruta a ruta (`IF:84-91`) | Backend `9acddc3` y CRM `020db86`, 18-10-2026 15:24–15:28 UTC (`IF:97-101`) | Sin bandera propia. La aplicación corre con el rol `iron_app` (solo DML) y migra el rol dueño por `ironbody-migrate`, que hoy corre **como root** (B-2, `HR:85`) | PostgreSQL 14. Ninguna externa | Unit 144 + Feature 92 + Access 150 en SQLite; 236 en PostgreSQL; 57 carreras; CRM 238 specs (`IF:95`) | 32/32 con la pila real y PostgreSQL, en local (`IF:108`); hoy CRM `e2e/ultron-studio/specs/f-versions.cjs` | Smoke en producción 22/22 con la cuenta temporal 32 (`IF:104`). **El ciclo crear → enviar → aprobar por otra persona nunca se ha hecho en producción** (`IF:108`); `PF` §2 y §4.1 sin ejecutar (`PF:9`, `:781-782`) | Ninguna del contrato. Para la prueba física: dos personas reales y aceptar filas permanentes en el libro (`PF:799-801`) | En producción, operativo como registro. Ningún documento registra cuántas versiones, reglas o aprobaciones reales hay |
| **G · Control funcional de las diez capas.** Columna `conditions` (`database/migrations/2026_10_19_100001_add_conditions_to_ultron_studio_rules.php`), configuración real por capa con su origen (`env_flag`, `engine_setting`, `engine_data`, `proposal`, `fixed`), coherencia de reglas, lecturas del motor (`GET engine/readings`), historial y comparación; 21 rutas (`HR:9`; `CG:31-40`) | Backend `e651dd9` (código `dfb30f5`) y CRM `44c0e8c`, 19-10-2026 06:00–06:04 UTC (`HR:9`) | Sin bandera. «El motor no lee el Studio» (`CG:14`, `CG:25`) | Lecturas de solo lectura del entorno y de `marketing_agent_settings` (`CG:27`) | Revisiones de `security`, `api-security-specialist`, `tester` y `reviewer`, cerradas (`HR:9`) | 126/126 con la pila real (`HR:9`); hoy CRM `e2e/ultron-studio/specs/g-inspector-compare.cjs` | Smoke en producción 29/29 (`HR:9`). `PF` §1 sin ejecutar como prueba física | C-7 / H-6 de SEC7: caracteres invisibles en las condiciones de las reglas y en el texto del juez, abierto según `HR:108` | En producción. Las reglas S-NNN «no hacen nada» y las R-xx solo describen el motor (`CG:26`) |

### Hito 4 · Laboratorio de IA con OpenAI

| Código implementado | Código desplegado (commit) | Configuración disponible | Dependencias conectadas | Pruebas automatizadas | Pruebas E2E | Pruebas físicas | Condiciones pendientes | Estado de operación real |
|---|---|---|---|---|---|---|---|---|
| **Laboratorio.** 5 tablas `ultron_studio_lab_*` (`database/migrations/2026_10_19_100002_create_ultron_studio_lab_tables.php`, `…_100003_add_ultron_studio_lab_guards.php`); 12 rutas `lab/*`; 11 escenarios de catálogo; actor y juez con OpenAI (`store: false`, `max_output_tokens`, sin reintentos); cuatro cerrojos antes de gastar, reserva atómica y tope por ejecución; worker propio; propuesta del laboratorio y veredicto humano a cuatro ojos (`HR:10`, `HR:24-29`). CRM `/marketing/ultron-studio/laboratorio` (CRM `src/app/app.routes.ts:248-253`), pestañas Escenarios y Ejecuciones (CRM `src/app/modules/ultron-studio/studio-lab-page.ts:15-16`) | Fases 1–2: backend `fef7c87` (código `18dae99`) y CRM `5c1dbd7`, 25-10-2026 01:15–01:25 UTC. Fase 3, solo configuración, el 02-11-2026 00:59 UTC sobre `7ba0ce8` (`HR:208-209`) | **Según el registro del 02-11; no releído:** `ULTRON_STUDIO_LAB_ENABLED=true`, `ULTRON_STUDIO_LAB_DRIVER=openai`, modelo `gpt-6.1-terra`, precios 1,00 / 5,00 US$ por millón de tokens, `…_DAILY_BUDGET_USD=2`, `…_RUN_BUDGET_USD=0.50`, topes de salida 800 / 1 600 y la clave dedicada: 10 variables (`IF:364-365`). Por defecto: apagado, `fake` y presupuesto 0 (`config/ultron_studio.php:134-175`). El 02-11 a las 04:52 UTC el guion del H6 leyó `lab_driver openai` (`PDS:1002`) | **OpenAI conectada** con proyecto y clave propios; la clave pasó por el chat: rotarla y poner límite de gasto en el panel está pendiente (B-1, `HR:84`; `IF:367`). Worker `ironbody-lab` en supervisor, RUNNING al desplegar (`IF:351`) | Studio 946 en PostgreSQL y Studio + Access + Chaos 1 197 en SQLite (`IF:345`); observaciones del `reviewer` cerradas en las Enmiendas 4–6 de `C4` | 122/122 con la pila real y OpenAI simulado sobre `6dbf485` + `3e630eb` (`IF:345`). El arnés original se perdió con la limpieza de `/tmp`; el del repo, CRM `e2e/ultron-studio/specs/h4-lab-fake.cjs`, cubre solo el modo `fake` | **Validación 1 hecha** (02-11-2026 01:12 UTC): ejecución real `2d25ed20-96c5-4a06-8179-ee0b413b281b`, 2 escenarios sobre la línea base, 0,014 US$ (`IF:366`). Smokes 20/20 y 17/17 (`HR:10`) | **A-1:** validación 2 (una ejecución sobre una versión y el veredicto `approved` de otra persona); **A-2:** cotejar los 0,014 US$ con el panel de OpenAI; **B-1** (`HR:72-73`, `HR:84`) | Operativo con OpenAI real, según el registro del 02-11. Una sola ejecución real registrada. `HITO_4_IMPLEMENTED_WITH_EXTERNAL_DEPENDENCY` (`IF:368`) |

### Hito 5 · Gobierno, versionado y aprobaciones

| Código implementado | Código desplegado (commit) | Configuración disponible | Dependencias conectadas | Pruebas automatizadas | Pruebas E2E | Pruebas físicas | Condiciones pendientes | Estado de operación real |
|---|---|---|---|---|---|---|---|---|
| **Gobierno y versionado.** Cadena firmada `ultron_studio_publications` (HMAC con subclave de la `APP_KEY`, solo inserción, como mucho una versión en `CANARY` y una en `PUBLISHED`); publicar → canario → promover; revertir y volver a Legacy; evidencia del canario medida en el servidor; ventana 07:00–19:00; puerta del laboratorio `required`; lector del motor `UltronPublishedConfig` que sella cada turno; 38 rutas (`HR:11`, `HR:31-41`). `StudioPublishing::ENGINE_READS_VERSIONS = true` (`app/Services/UltronStudio/StudioPublishing.php:39`) | Backend `7ba0ce8` y CRM `ab78a6a`, 01-11-2026 01:12–01:16 UTC (`HR:211`) | 7 variables (`ops/deploy-h5-backend-fase1.sh:29`): `MARKETING_ULTRON_STUDIO_CONFIG=off` (se lee en `config/marketing.php:102`), `ULTRON_STUDIO_PUBLISHING_ENABLED=false` (publicar y promover → 409), `ULTRON_STUDIO_PUBLISH_LAB_GATE=required`, canario de 24 h y 3 turnos, ventana `07:00-19:00` | El motor de ULTRON, que con la llave en `off` **no consulta la cadena ni sella** (`HR:18`). Ningún proveedor externo | PostgreSQL 1 447 sobre `cf59cef`; Studio + Access + Chaos 1 697 en SQLite; Marketing 4 977 (`HR:11`) | 408/408 con la pila real sobre `cf59cef` + `9fe1817` (`HR:11`); CRM `e2e/ultron-studio/specs/h5-governance.cjs` | Smoke de la fase 1: 35 + 14 + 3 OK; estable a las 24 h (`IF:32`). Ninguna publicación; `PF` §4.3–§4.4 sin ejecutar | **A-3:** fase 2 (motor en `canary` y primera publicación) con la puerta del laboratorio cerrada por la validación 2 del H4 y dos cuentas reales; **A-4:** fase 3 (24 h y 3 turnos sellados, sin frenos); autorización escrita por fase (`HR:74-75`) | Desplegado y apagado; 0 publicaciones; revertir y Legacy, siempre disponibles. **Encendido tampoco cambiaría lo que ULTRON dice o hace:** «Aplicación … Vacío. El motor solo sella» (`C5:35`; texto obligatorio en `C5:286`) |

### Hito 6 · Integraciones Meta, WhatsApp, Twilio OTP y ElevenLabs

Común a las filas: 18 rutas nuevas (56 en total), 4 permisos y 3 tablas (`database/migrations/2026_11_01_100001_create_ultron_studio_integration_tables.php`, `…_100002_add_ultron_studio_integration_guards.php`); desplegado con backend `1e5f9a0` (código `4e2e8cf`) y CRM `16c433c` el 02-11-2026 entre las 04:37 y las 04:57 UTC (`PDS:995-1010`); 8 variables (`ops/deploy-h6-backend-fase1.sh:55`). Pruebas: SQLite 2 879 y PostgreSQL 2 455 sobre `e3a0fdd`, 45/45 carreras, E2E 660/660 con la pila real, `reviewer` aprobado con observaciones; smoke en producción 78 + 26 OK (`HR:12`). La verificación a las 24 h no consta (`IF:33`).

| Código implementado | Código desplegado (commit) | Configuración disponible | Dependencias conectadas | Pruebas automatizadas | Pruebas E2E | Pruebas físicas | Condiciones pendientes | Estado de operación real |
|---|---|---|---|---|---|---|---|---|
| **Meta Ads.** Lectura del espejo de la sincronización existente y asociación campaña ↔ versión (`ultron_studio_campaign_links`); el Studio no llama a la API Graph (`HR:47`). Pestaña «Campañas de Meta» (CRM `src/app/modules/ultron-studio/studio-integrations-page.ts:16`) | `1e5f9a0` / `16c433c` | `ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED=false`: asociar → 409 `integrations_writes_disabled` (`config/ultron_studio.php:205`); `ULTRON_STUDIO_CAMPAIGN_LINKS_MAX_ACTIVE=50` | **Meta conectada en lectura** por la sincronización existente (17 campañas al desplegar, `PDS:1006`). Alcances y caducidad del token sin revisar (B-8, `HR:91`) | Las comunes | Dentro de 660/660 | Smoke. Asociar en producción no es condición del cierre (`C6:1329`) | La fase 2 (dos personas) para escribir; B-8 | **Lectura operativa con datos reales**; asociación apagada |
| **WhatsApp.** Solo el **registro de números canarios** por canal (`whatsapp` y `voice`; cuatro ojos; huella HMAC y 4 dígitos) y su coherencia con la conversación canaria del motor, T0/T1 (`C6:278-294`, `C6:405`). **El H6 no envía nada por WhatsApp:** el envío gobernado es W9, posterior al H9 (`C9:32`) | `1e5f9a0` / `16c433c` | Escrituras apagadas: registrar, autorizar y activar → `writes_disabled` (`app/Services/UltronStudio/StudioCanaryNumbers.php:515`); `ULTRON_STUDIO_CANARY_MAX_ACTIVE=3`, país `57` | El canario del motor sigue siendo el del entorno (T0/T1). WhatsApp de ULTRON Legacy (n8n) queda fuera del Studio | Carreras y CHECK de cuatro ojos en PostgreSQL (`HR:52`) | Dentro de 660/660 | **T1 sin hacer**; 0 números | **A-5** (dos personas para el T1), **A-6** (`log_error_verbosity = terse` antes) y C-8 (`HR:76-77`, `HR:109`) | Apagado; veredicto `engine_canary_not_registered` |
| **Twilio OTP.** Panel de solo lectura del OTP (uso y gasto del día, coherencia de los alias de credenciales, total de llamadas) y sonda de 5 GET con enfriamiento y cupo (`HR:45`). Pestaña «Twilio» (CRM `studio-integrations-page.ts:18`) | `1e5f9a0` / `16c433c` | `ULTRON_STUDIO_TWILIO_PROBE_ENABLED=false`: sonda → 409 `probe_disabled` (`config/ultron_studio.php:209`). Sin key restringida para la sonda (`config/ultron_studio.php:215-216`): usaría la credencial maestra del OTP | **Twilio conectada solo para el OTP** (`connected_otp_only`); `TWILIO_FROM` vacía; el validador de `usage-trigger` rechaza las URL con consulta (`HR:172`) | Paridad con el fixture oráculo de 704 casos (Enmienda 2 de `C6`) | Dentro de 660/660 | **Sonda nunca ejecutada**; `PF` §6.1 no ejecutado en producción (`PF:814`) | B-6: key restringida y rotar el Auth Token maestro (`HR:89`); la sonda va en la fase 2 (`C6:1328`) | **Panel operativo con datos reales**; sonda apagada |
| **Configuración autorizada por huellas.** Instantáneas en `ultron_studio_integration_snapshots`; «reconocer» la configuración, solo Super Admin y sin depender de la bandera (`C6:1327`) | `1e5f9a0` / `16c433c` | Sin bandera | Las huellas de Meta, Twilio y voz. La de voz cambió con E1-12 del H8 y cambiará con D-1 y D-5 (`PP8:59`, `PP8:64`) | Las comunes | Dentro de 660/660 | Ninguna | Reconocer `meta_ads`, `twilio` y `voice` (fase 2 y cada cambio de la voz) | `never_authorized` en los tres el 03-11 (`IF:816`); no consta un reconocimiento posterior |
| **ElevenLabs (lo que trajo el H6).** Adaptador, `voice:doctor`, firma y política; estado final previsto `ELEVENLABS_NOT_CONFIGURED` (`C6:1348`) | `1e5f9a0` / `16c433c` | `ELEVENLABS_ENABLED=false`, `TWILIO_VOICE_ENABLED=false`, `TWILIO_VOICE_DAILY_CALL_CAP=0` | **Superado por el H8:** desde E1-12 están la clave, el agente, dos secretos y la región; el Studio da ElevenLabs por verificado hasta el 03-12-2026 y la telefonía, `ELEVENLABS_UNVERIFIED` (`PP8:5`) | — | — | — | El criterio 6 del cierre del H6 ya no se puede cumplir tal como está escrito (§6) | Ver el Hito 8 |

### Hito 7 · Seguridad, permisos y monitorización

| Código implementado | Código desplegado (commit) | Configuración disponible | Dependencias conectadas | Pruebas automatizadas | Pruebas E2E | Pruebas físicas | Condiciones pendientes | Estado de operación real |
|---|---|---|---|---|---|---|---|---|
| **Endurecimiento (DB7, B7, SEC7).** Migración `database/migrations/2026_11_02_100001_add_ultron_studio_hardening.php` (cadena encadenada por disparador, `CHECK (seq >= 1)`, `REVOKE UPDATE, DELETE` de `iron_app` en la cadena, el libro y las instantáneas); `Cache-Control: no-store` en `api/admin/*`; tope de 64 KB antes de `TrimStrings`; `verified` por entrada; invisibles (`PlainText` estricto en los 19 sitios del Studio); restablecer la contraseña cierra las demás sesiones; topes de listas en 8 rutas (`HR:13`, `HR:54-62`) | Producción `cfcd0d1` (= commit final `d688418` + 2 commits ajenos) desde el 03-11-2026 18:29 UTC, por un despliegue manual ajeno, sin respaldo propio; regularizado por el lead a las 20:03 UTC (`IF:812-821`) | Sin bandera | PostgreSQL (roles y privilegios) | PostgreSQL 3 854 (2 rojos del entorno, también en la base); por nombre 57 = 57 rojos, 0 nuevos; 53/53 mutantes; motor 5 015/5 015 con la llave en `off` y en `all` (`HR:13`) | E2E 660/660; recuperación 21/21 y vuelta atrás 13/13 en el banco (`HR:13`) | Smoke en producción 37 OK · 0 FALLO · 2 OMITIDO; presupuestos 15 OK; esquema de 44 disparadores y 50 CHECK (`IF:815-817`) | B-5 (`composer audit`: 46 avisos, 2 críticos; `npm audit`: 6), B-7, B-9, B-13 (`HR:88-96`) | **Activo** |
| **Monitor (OPS7).** `ultron-studio:monitor` cada 30 minutos e `integrations-snapshot` diario; abre o suma incidentes de IRON GUARD; sin red (`routes/console.php:386-413`); mando `ops/h7-monitor-scope.sh` | `cfcd0d1` | `ULTRON_STUDIO_MONITOR_ENABLED=false` (`config/ultron_studio.php:361`; `ops/deploy-h7-backend.sh:86`) y `ULTRON_STUDIO_MONITOR_EXPECT` vacía (`config/ultron_studio.php:370`) | IRON GUARD; el programador (B-4: `schedule:run` en el crontab de root, por comprobar, `HR:87`) | 12/12 en SQLite y PostgreSQL; el mando, 16/16 en el banco (`HR:110`) | — | `ultron-studio:monitor --dry-run` → «El Studio cuadra» (03-11, `IF:816`) | **A-7:** encenderlo con autorización (`HR:78`). Antes, fijar la expectativa: la del código (`elevenlabs:ELEVENLABS_NOT_CONFIGURED`) ya no es la de producción (`PP8:52`); y B-4 | **Apagado: hoy no hay vigilancia automática** del Studio, la voz ni el H9 |
| **Operación segura (OPS7).** `ops/ironbody-migrate-propuesta.sh` (migrar sin root), `ops/supresion-1581.sql` (supresión de la Ley 1581, en seco primero), política de respaldos | `cfcd0d1` (solo `ops/`) | El fichero de entorno sigue en `www-data 600` («fichero 600», `plans/informes-h9/informe-devops.md:226`) | — | Supresión 17/17 en el banco (`HR:126`) | — | **Supresión nunca ejecutada en producción**; la propuesta de `ironbody-migrate`, **sin instalar** | **B-2** (migrar sin root), **B-3** (entorno en `root:www-data 640`), **C-14** abierto (§4.1, B-T14), B-10 (copia cifrada fuera del VPS), B-12 (`HR:85-95`, `HR:115`) | Hoy se migra como root ejecutando cachés de `www-data`: riesgo medio de SEC7 (`HR:85`) |
| **Permisos.** `app/Support/Access/PermissionCatalog.php`: 121 permisos tras el H9, 19 de ellos `ultron.*` (L9-75); `auth:coverage --unmapped` vacío en cada despliegue; token compartido excluido. Cuatro ojos **por cuenta** en F–H8 y **por persona verificada** solo en el H9 (P9-4) | En producción con cada hito | Por rol: el Super Admin tiene todas; varias no llegan por defecto al Administrador, como `telephony.*` (`PermissionCatalog.php:253-255`) | — | Cobertura de autorización 40/40 y permisos 35/35 en el H9 (`A9:18`) | — | — | «Cuatro ojos por cuenta, no por persona»: un Super Admin puede crearse otra cuenta y aprobar lo suyo; control organizativo, sin alerta (`HR:136`) | Activo |

### Hito 8 · Voz y telefonía con Twilio y ElevenLabs

Común a las filas: 11 tablas (`database/migrations/2026_11_10_100001_create_ultron_studio_voice_tables.php` y `…_100002_create_voice_tables.php`: 4 del Studio y 7 de telefonía), 38 rutas (21 del Studio, 13 del CRM y 4 públicas), 4 comandos y 4 tareas programadas (`M8:19-22`). **Fase 1:** backend `fe29126` y CRM `45dd73a`, 09-11-2026 12:06 y 12:09 UTC (E15-2 a E15-4, `C8:943-945`). **1b** (cierre integral, solo código): backend `a608450` y CRM `e0f7983`, 10-11-2026 02:20–02:24 UTC (E16-30, `C8:984`). Hoy, dentro de `ce7c150` / `9572426`. **Configuración de la fase 1:** 9 variables apagadas o en 0 (`ops/deploy-h8-backend-fase1.sh:119`: `VOICE_INBOUND_ENABLED`, `VOICE_OUTBOUND_ENABLED`, `VOICE_LAB_ENABLED`, `VOICE_MONITOR_ENABLED`, `ULTRON_STUDIO_VOICE_PUBLISHING_ENABLED` en `false`; `VOICE_INBOUND_DAILY_CAP`, `VOICE_DAILY_BUDGET_USD` y `VOICE_LAB_DAILY_BUDGET_USD` en 0; `VOICE_LAB_DRIVER=fake`) y la constante `VoiceCallPolicy::CALLS_ENABLED_IN_CODE = false` (`app/Services/Voice/VoiceCallPolicy.php:23`). **Pruebas tras B8-h:** telefonía + voz + unitarias, 776 en SQLite y 776 en PostgreSQL (un rojo ajeno, del H9), y Studio voz y laboratorio, 309 (E16-26, `C8:980`); regresión global de 14 061 pruebas sin rojos nuevos del H8 (E16-29, `C8:983`). **E2E del backend con simuladores** sobre `b8d00f7f`: cerrada 6/6, abierta 9/9 y salientes 11/11, 661 comprobaciones, 0 fallos y 0 hosts fuera de 127.0.0.1 (`ops/evidencia-h8/2026-11-10/20261110T021157Z-b8d00f7f/resumen.txt:4-7`). **Banco de OPS8-d:** 102 OK · 0 FALLO (`PP8:15`). **REV8-c:** aprobada con condiciones, 0 bloqueantes (E16-23 y E16-27).

| Código implementado | Código desplegado (commit) | Configuración disponible | Dependencias conectadas | Pruebas automatizadas | Pruebas E2E | Pruebas físicas | Condiciones pendientes | Estado de operación real |
|---|---|---|---|---|---|---|---|---|
| **Gobierno de la voz (S8).** Perfil de voz a cuatro ojos; compilador puro con la plantilla `ultron-voice-template/2` (español de Colombia, aviso de IA, sección fija de seguridad); un agente inmutable por publicación; cadena firmada por parte (canario y resto); promover exige 7 medidas de evidencia (`CI8:45`). Pestañas Perfil y Publicación (CRM `src/app/modules/ultron-studio/studio-voice-page.ts:19-20`) | Fase 1 + 1b | `ULTRON_STUDIO_VOICE_PUBLISHING_ENABLED=false` (`config/ultron_studio.php:272`); puerta del laboratorio `ULTRON_STUDIO_VOICE_LAB_GATE=required` (`:277`). Publicar exige además el veredicto del laboratorio sobre esa `specHash` y un cupo diario > 0 (H-26, `PP8:156`) | ElevenLabs: clave, agente, 2 secretos y región presentes en el entorno de Laravel (E1-12); en ElevenLabs no hay ningún secreto de Laravel registrado (`PROV:15`). Faltan `ELEVENLABS_INITIATION_SECRET`, las 3 referencias `*_REF` y `VOICE_WEBHOOK_BASE_URL` (`PP8:5`); 0 webhooks en el workspace (`M8:34`) | Compilador: 882 casos más 98 del compilador real (`CI8:45`) | Con simuladores (común) | **Producción:** 1 perfil aprobado, creado por la cuenta 27 el 09-11 a las 18:24 UTC y aprobado a las 18:47 UTC (E16-30; el código exige otra cuenta, no consta si fue otra persona), con la voz «Lina - Colombian Warm & Confident» según la sonda del lead (`PROV:11`, que corrige a `PP8:41`) y el trato tú/usted sin elegir; 0 agentes compilados; 0 publicaciones | D-6 (ya reducida a aprobar los textos de los avisos y el trato, `PROV:11`), D-7 (segunda persona), el veredicto del laboratorio (plan), `publicar on`, H-26 | Apagado |
| **Laboratorio hablado (LAB8).** Sesión web con un agente de laboratorio por sesión y la misma `specHash` que la publicación; 4 cerrojos; reserva atómica; URL firmada sin persistir; veredicto a cuatro ojos; cliente `@elevenlabs/client` 1.30.0 alojado en el CRM (`M8:23`). El modo `fake` no admite veredicto (`app/Services/UltronStudio/StudioVoiceLabSessions.php:165`) | Fase 1 + 1b | `VOICE_LAB_ENABLED=false`, `VOICE_LAB_DRIVER=fake`, presupuestos en 0 (`config/voice.php:214-219`): todo `voice-lab/*` salvo el estado responde 409 (`StudioVoiceLabSessions.php:173`) | **Plan de ElevenLabs gratuito**, sin minutos de agente (`M8:33`); clave por rotar (D-1, A-2); secretos y webhook sin registrar (D-5); no se sabe si el plan gratuito admite secretos y webhooks del workspace (H-23, `PP8:151`) | 35 pruebas y E2E con simulador (`M8:40`) | Banco 2a: 26 OK, con una sesión `elevenlabs` contra el simulador y veredicto a cuatro ojos (`PP8:21`) | **Ninguna sesión real** | D-4 (plan o excepción escrita), D-1, D-5, D-6 y D-7 | Apagado |
| **Telefonía (B8).** Puerta única de salientes `OutboundGate` (28 motivos); job de un intento con recomprobación antes de marcar; entrantes por el webhook de iniciación; post-llamada firmada; conciliación y deriva del número; freno automático que suelta una persona; guarda de la transcripción con `VoiceTranscriptLexicon`; costes y techos; comandos `voice:doctor`, `voice:hangup`, `voice:reconcile` y `voice:prune-rate-limits` (`CI8:46`; `M8:21`) | Fase 1 + 1b | Constante cerrada; `VOICE_OUTBOUND_ENABLED=false`, `VOICE_INBOUND_ENABLED=false`, `TWILIO_VOICE_ENABLED=false`, cupos y presupuesto en 0, `VOICE_MONITOR_ENABLED=false`; ninguna `TWILIO_VOICE_*` (`PP8:5`; `config/voice.php:118-294`) | **Twilio Voice sin configurar:** sin subcuenta, keys ni token de firma (D-3). El número empresarial está importado en ElevenLabs como «Outbound Only», que es un caller ID verificado de Twilio y **no recibe llamadas** (E16-1, `C8:955`); **se importó con el Auth Token maestro de la cuenta raíz del OTP** (A-1 confirmado, `PROV:13`), una cuenta sin subcuentas ni API keys (`PROV:21`). **Worker `ironbody-voice` sin instalar** (su conf está lista: `backend/docs/ops/supervisor/ironbody-voice.conf:79-90`). Los mandos de OPS8-c **no están copiados a `/root`** (`PP8:5`) | Las 776 comunes; guarda de la transcripción: 18 ataques cazados y 0 falsos positivos en 20 frases legítimas (`CI8:46`) | Salientes 11/11 con simuladores (común) | **Ninguna llamada por Laravel.** La única llamada real (03-11 a las 22:48 UTC, comunicada el 09-11; `PROV:22`) salió por la consola de ElevenLabs con un agente hecho a mano | D-1, D-2, D-3, D-8, D-9, D-10, D-13 (canario de voz ⇒ fase 2 del H6), E16-16 y P-M2 (`CI8:77-89`; `PP8:43`). Técnicas: B-T1, B-T2 y B-T5 (§4.1) | Apagado; `voice:doctor --telefonia` en `ELEVENLABS_UNVERIFIED` (E16-30) |
| **Contactos y consentimientos (fase 4b).** Contactos con el teléfono cifrado, consentimiento cargado por una persona y confirmado por otra, exclusiones, consultas al RNE, horario de la Ley 2300 y festivos (`M8:76`). Pestaña Contactos (CRM `studio-voice-page.ts:23`) | Fase 1 + 1b | **Sin bandera:** `VoiceContacts::register()` no consulta ninguna (`app/Services/Telephony/VoiceContacts.php:75-119`; controlador `app/Http/Controllers/Crm/Voice/VoiceContactController.php:36-48`); solo la llave `telephony.contacts_manage`, que no llega por defecto al Administrador (`PermissionCatalog.php:255`) | — | Dentro de las 776 | En la fase `salientes` del E2E con simuladores (`tests/Feature/Voice/E2E/H8E2ESalientesPrimeroTest.php`) | Ninguna | **D-12:** visto bueno legal (Ley 1581, Ley 2300, RNE). **La supresión de la Ley 1581 no cubre las tablas de voz** (H-24, `PP8:139`, `PP8:152`) | Técnicamente disponible hoy para el Super Admin; el contrato lo reserva para la 4b (B-T4) |

### Hito 9 · Autonomía comercial omnicanal

Común a las filas: **15 tablas** (7 de gobierno en `database/migrations/2026_11_12_100001_create_ultron_studio_strategy_tables.php` y 8 de ejecución en `…_100002_create_outreach_tables.php`; índices en `…_100003`); 121 rutas del Studio, `StudioRouteCount::TOTAL` = 77 + 30 + 13 + 1 (`app/Http/Controllers/Crm/UltronStudio/StudioRouteCount.php:13`; `UltronStudioStrategyController.php:105`); desplegado con backend `ce7c150` y CRM `9572426` el 10-11-2026 entre las 20:21 y las 20:27 UTC (L9-76, `C9:1535`). **15 variables** (`ops/deploy-h9-backend-fase1.sh:143`): 14 apagadas o en 0 y `ULTRON_STUDIO_STRATEGIES_SIMULATION_GATE=required`. Constante `OutreachSendGate::SENDS_ENABLED_IN_CODE = false` (`app/Services/Outreach/Run/OutreachSendGate.php:35`); el guion de la fase 1 rechaza un commit `outreach(sends-open)` (`A9:20`). Regresión completa final: 15 787 pruebas con 56 rojos, los mismos 56 de la línea base, 0 nuevos (L9-76). REV9: aprobado con condiciones, 0 bloqueantes (L9-73, `C9:1532`).

| Código implementado | Código desplegado (commit) | Configuración disponible | Dependencias conectadas | Pruebas automatizadas | Pruebas E2E | Pruebas físicas | Condiciones pendientes | Estado de operación real |
|---|---|---|---|---|---|---|---|---|
| **Gobierno de estrategias (S9).** Estrategias, versiones, plantillas y simulaciones; cadena firmada propia; cuatro ojos **por persona verificada** (vínculos cuenta → identidad); canario, promoción, pausa y reversión; 30 rutas (`A9:44`; `C9:25-27`) | `ce7c150` / `9572426` | `ULTRON_STUDIO_STRATEGIES_ENABLED=false` y `…_STRATEGIES_PUBLISHING_ENABLED=false` (`config/ultron_studio.php:308-310`); puerta de simulación `required` (`:339`) | Ninguna externa. Vínculos de personas: 0; las dos primeras se crean por consola (P9-11) | S9: 32 pruebas, `UltronStudioStrategyRoutesTest`; `OutreachIsolationTest` recorre el ciclo (`A9:44`) | **#26 (nueva estrategia desde el CRM con la pila real): pendiente**; no existe `e2e/h9-nueva-estrategia` en ninguno de los dos repos | Ninguna | P9-4 y P9-11 (dos personas vinculadas), TEST9-b, autorización de la fase 2 | Apagado; tablas vacías |
| **Ejecución (B9-run).** Planificador de nueve etapas en sombra; despachador con reevaluación justo antes de despachar; reserva atómica de presupuesto; frenos y PARADA GLOBAL; conciliación; bandeja asistida; resultados normalizados y seguimientos; 13 rutas y 7 órdenes `outreach:*`; bloque del programador con `outreach:sweep`, `outreach:dispatch --inline` y `outreach:monitor` (`routes/console.php:484-513`) | `ce7c150` / `9572426` | `COMMERCIAL_AUTOMATION_ENABLED=false` y `COMMERCIAL_AUTOMATION_MODE=off` (`config/outreach.php:60-65`): 12 de las 13 rutas responden 409 `outreach_disabled` y `status`, 200 (`A9:48`); programador, monitor, asistido, WhatsApp, voz y clasificador, apagados (`config/outreach.php:72-119`) | Hechos del CRM en solo lectura. **`COMMERCIAL_EVENTS_ENABLED=false`** en producción: las condiciones sobre eventos dan `unknown` (P9-24; `C9:1407`, `C9:1452`). El despacho va `--inline`, sin worker | `Run` 69/69 en SQLite y PostgreSQL; 24 pruebas de TEST9-c; la carrera #13 con dos procesos reales en PostgreSQL (`A9:29-32`; L9-74) | 25 de las 26 pruebas del propietario en verde (`A9:27-33`); `exercise()` todavía sin las 13 rutas ni las 5 órdenes (TEST9-b) | Smoke en producción 37 OK · 0 FALLO · 1 OMITIDO, el de los presupuestos de PERF9 (L9-76); vigilancia de 15 minutos sin incidencias (L9-77) | m-9, H-1 (reloj del monitor), PERF9, P9-24 y R-1 del runbook de rotación de la `APP_KEY` (L9-73); ≥ 7 días en sombra (P9-11); P9-12 para el asistido | Apagado: 0 filas en `outreach_*` y 0 tareas |
| **Núcleo puro y hechos (B9-core, B9-facts).** Catálogo de 8 disparadores (`membership.expiring_window`, `membership.expired_window`, `class.upcoming_with_capacity`, `lead.followup_due`, `member.inactive`, `payment_link.abandoned`, `receivable.overdue`, `valuation.outdated`); gramática tipada de condiciones con lógica de tres valores; escalera N1–N7; selección de canal; trazas explicables (`A9:19`) | `ce7c150` / `9572426` | — | Hechos del CRM en solo lectura (`Facts/**` sin escrituras, `A9:43`) | 962 pruebas y 803 casos de fixtures (`outreach-catalog/2026-11-10.2`) con paridad byte a byte con el CRM (`A9:42`) | — | — | Un disparador nuevo exige código (EV9) | Sin uso hasta encender el gobierno y la sombra |
| **Canal en vivo (SSE9).** Un único canal SSE del Studio con 11 temas, también para los paneles de voz (E16-18) (`A9:46`) | `ce7c150` / `9572426` | `ULTRON_STUDIO_STREAM_ENABLED=false` (`config/ultron_studio_stream.php:41`): el CRM sondea | — | 32/32 en SQLite y PostgreSQL (`A9:46`) | — | — | **R-1:** el tique viaja en la URL, vale 300 s, no es de un solo uso y abre también `*/face-image/*` (P9-25, `A9:79`) | Apagado |
| **CRM «Campañas automáticas (estrategias)».** `/marketing/automatizaciones` (CRM `src/app/app.routes.ts:282-291`; menú en CRM `src/app/app.html:134`), 9 pestañas (CRM `src/app/modules/commercial-automation/commercial-automation-page.ts:40-48`) y 10 llaves nuevas (L9-75) | CRM `9572426` | Con el gobierno apagado, las pestañas de gobierno se atenúan (CRM `commercial-automation-page.ts:157`) | — | FE9 440/440; con las llaves, 491 specs (`A9:16`; L9-75) | **E2E con la pila real: pendiente** (TEST9-b) | Ninguna en producción; 22 capturas en el banco (`plans/informes-h9/capturas-fe9/`) | TEST9-b | Visible; solo el Panel lee datos |
| **Canales WhatsApp y voz por estrategia.** `WhatsappTemplateChannel` y `VoiceStrategyChannel` **vacíos**: fallan al empezar con `channel_not_live` (`app/Services/Outreach/Run/Channels/WhatsappTemplateChannel.php:26`; `A9:105-106`) | `ce7c150` / `9572426` | `COMMERCIAL_AUTOMATION_WHATSAPP_*` y `COMMERCIAL_AUTOMATION_VOICE_ENABLED`, apagadas; constante de envío cerrada | W9: plantillas aprobadas por Meta y `MarketingMessageDispatcher`; V9: fases 4a y 4b del H8 | Prueba #20 (`OutreachDispatcherTest`, `A9:20`) | — | — | **Fuera del H9 por contrato** (§12, «S»: W9, C9, AI9, V9, EV9 y NBA9); visto bueno legal (P9-22) | El envío no existe |

---

## 3. Funciones visibles en el CRM y su estado

Todas viven bajo Mercadeo, detrás de `ultronStudioPreviewEnabled` (encendida en producción, CRM `src/environments/environment.prod.ts:24`) y de `ultron.view`; cada acción la vuelve a autorizar el servidor con su llave (CRM `src/app/app.routes.ts:234-291`).

### 3.1 Tablero (`/marketing/ultron-studio`)

| # | Función | Estado | Por qué y qué falta | Evidencia |
|---|---|---|---|---|
| 1 | Tablero de las diez capas, conexiones y validación del grafo | «disponible y operativa» | Es un registro: no cambia el motor | `HR:8`; `CG:24` |
| 2 | Inspector: configuración de borrador por capa y lecturas del motor | «disponible y operativa» | Las lecturas son de solo lectura; la configuración no la lee el motor | `CG:25-27`; smoke 29/29 (`HR:9`) |
| 3 | Mi borrador en el servidor (guardar, descartar, conflicto 409) | «disponible y operativa» | — | Smoke 22/22 (`IF:104`) |
| 4 | Reglas: las R-xx de solo lectura y las S-NNN con condiciones (crear, editar, archivar) | «disponible y operativa» | Las S-NNN no se aplican (`enforcement = none`) | `CG:26` |
| 5 | Versiones: crear, validar, enviar a revisión, comparar e historial | «disponible y operativa» | El ciclo completo nunca se ha ejercido en producción | `IF:108` |
| 6 | Aprobar o rechazar una versión (cuatro ojos por cuenta) | «disponible y operativa» | Nunca usada en producción por dos personas: falta la segunda persona (D-7) | `IF:108`; `PF:782` |
| 7 | Libro de auditoría | «disponible y operativa» | — | `IF:89` |
| 8 | Publicar en canario y promover | «bloqueada por condición contractual» | Fase 2 del H5: veredicto `approved` del laboratorio sobre esa versión (la validación 2 del H4), dos cuentas reales, ventana y autorización escrita. Mecanismo: `ULTRON_STUDIO_PUBLISHING_ENABLED=false` (409) y el motor en `off` | `HR:74`; `ops/deploy-h5-backend-fase1.sh:29` |
| 9 | Revertir y volver a Legacy | «disponible y operativa» | No dependen de las llaves; hoy no hay nada publicado | `HR:18`, `HR:33` |
| 10 | Evidencia del canario e historial de publicaciones | «disponible y operativa» | Vacíos: no hay cadena. El CRM no enseña el `verified` por entrada que la API da desde el H7 (B-T15) | `HR:128`; búsqueda sin resultados de `verified` en CRM `studio-publication-history.ts` |
| 11 | Indicador «en vivo» (páginas del Studio y de Automatizaciones) | «pausada voluntariamente» | `ULTRON_STUDIO_STREAM_ENABLED=false`: el CRM sondea. Antes de encenderlo, decidir R-1 (P9-25) | `config/ultron_studio_stream.php:41`; `A9:79` |

### 3.2 Laboratorio (`/marketing/ultron-studio/laboratorio`)

| # | Función | Estado | Por qué y qué falta | Evidencia |
|---|---|---|---|---|
| 12 | Escenarios (catálogo de 11 y los del personal, con filtro de datos personales) | «disponible y operativa» | — | `IF:351`, `IF:354` |
| 13 | Ejecuciones con OpenAI (estimar, aceptar, reservar, ejecutar) | «disponible y operativa» | Según el registro del 02-11 (driver `openai`, 2 US$ al día); no releído. Pendiente B-1: rotar la clave y poner límite de gasto | `IF:364-366` |
| 14 | Veredicto humano sobre una ejecución (cuatro ojos) | «disponible y operativa» | Nunca usado: es la validación 2 del H4 y falta la segunda persona | `IF:368`; `HR:72` |

### 3.3 Integraciones (`/marketing/ultron-studio/integraciones`)

| # | Función | Estado | Por qué y qué falta | Evidencia |
|---|---|---|---|---|
| 15 | Estado de los proveedores | «disponible y operativa» | Clasificación del 02-11: Meta `connected_read`, Twilio `connected_otp_only`, voz de Twilio `not_configured`; ElevenLabs cambió con E1-12 del H8 | `PDS:1008` |
| 16 | Campañas de Meta: lectura | «disponible y operativa» | Datos reales del espejo | `PDS:1006` |
| 17 | Campañas de Meta: asociar campaña ↔ versión | «bloqueada por condición contractual» | Fase 2 del H6 (dos personas, A-6). Mecanismo: `ULTRON_STUDIO_INTEGRATIONS_WRITES_ENABLED=false` (409) | `C6:1321-1329` |
| 18 | Números canarios: registrar, autorizar y activar (T1 de WhatsApp y canario de voz) | «bloqueada por condición contractual» | Lo mismo; además es requisito del canario de voz del H8 (D-13) | `app/Services/UltronStudio/StudioCanaryNumbers.php:515`; `PP8:43` |
| 19 | Twilio: panel del OTP | «disponible y operativa» | Lectura con datos reales | `HR:45` |
| 20 | Twilio: sonda de 5 GET | «configurada y lista para activación» | `h6-integrations-scope.sh probe on`, coste 0. Sin key restringida usa la credencial del OTP: conviene B-6 antes | `config/ultron_studio.php:209-216`; `C6:1328` |
| 21 | Configuración autorizada: reconocer las huellas | «disponible y operativa» | Solo Super Admin y sin depender de la bandera. Hoy `never_authorized` (dato del 03-11) | `C6:1327`; `IF:816` |
| 22 | Voz: estado de ElevenLabs y de su verificación | «disponible y operativa» | La verificación del Studio caduca el 03-12-2026 a las 22:55 UTC (D-11); después dirá «ElevenLabs encendido, sin verificar» | `ULTRON_STUDIO_GUIA_ADMINISTRACION.md:488`; `CI8:87` |

### 3.4 Voz y telefonía (`/marketing/ultron-studio/voz`)

| # | Función | Estado | Por qué y qué falta | Evidencia |
|---|---|---|---|---|
| 23 | Estado | «disponible y operativa» | Enseña todo apagado y la constante cerrada | CRM `studio-voice-page.ts:18` |
| 24 | Perfil de voz: crear, enviar y aprobar a cuatro ojos | «disponible y operativa» | 1 aprobado, con la voz «Lina» (español colombiano) según la sonda del lead; falta elegir el trato tú/usted y aprobar los textos de los avisos (D-6) | E16-30; `PROV:11` |
| 25 | Previsualizar la compilación (`compile-preview`) | «disponible y operativa» | Sin red; dice qué piezas faltan | `routes/ultron-studio.php:154`; `PP8:21` |
| 26 | Publicar el canario de voz y promover | «pendiente de proveedor externo» | La puerta `required` exige el veredicto de una sesión real del laboratorio: plan de ElevenLabs o la excepción escrita D-4. Además: D-7, `publicar on` y cupo > 0, que exige `voice(calls-open)`, el worker y `salientes on` (H-26) | `PP8:96`, `PP8:156` |
| 27 | Revertir y apagar la voz | «disponible y operativa» | No hay publicación que revertir | `routes/ultron-studio.php:211-212` |
| 28 | Freno de voz: ver y soltar con nota | «disponible y operativa» | 0 frenos | `routes/ultron-studio.php:157`, `:213` |
| 29 | Llamadas: registro, detalle y métricas | «disponible y operativa» | 0 llamadas | `routes/voice.php:58-61` |
| 30 | Pedir una saliente y colgar | «pendiente de proveedor externo» | Twilio Voice sin cuenta, keys, token ni número (D-3) y sin publicación canaria (plan). Además: la constante cerrada sin vía de despliegue (B-T1), el worker (D-8), el canario de voz (D-13 ⇒ fase 2 del H6), E16-16 y P-M2 (B-T2) | `PP8:84-101`; `CI8:77-89` |
| 31 | Entrantes (fase 3) | «pendiente de proveedor externo» | Imposible con el número actual, que es un caller ID verificado sin entrantes: hace falta un número de Twilio con entrantes o la decisión D-10 de no tenerlas; además, la salud del webhook (B-T5) | E16-1 (`C8:955`); `M8:52-53`; `CI8:86` |
| 32 | Costes y métricas | «disponible y operativa» | En 0 | CRM `studio-voice-page.ts:22` |
| 33 | Contactos y consentimientos | «disponible y operativa» | **Atención:** no hay barrera técnica, pero el contrato la reserva para la fase 4b (visto bueno legal, D-12) y la supresión de la Ley 1581 no cubre la voz. No debería usarse hasta entonces (B-T3, B-T4) | `app/Services/Telephony/VoiceContacts.php:75-119`; `PermissionCatalog.php:255`; `PP8:152` |
| 34 | Laboratorio hablado en modo `fake` (fontanería sin agente) | «configurada y lista para activación» | Copiar los mandos a `/root` y `h8-voice-scope.sh lab on --driver=fake`: sin red ni gasto. No admite veredicto | `PP8:50`, `PP8:72-74`; `StudioVoiceLabSessions.php:165` |
| 35 | Laboratorio hablado real (ElevenLabs) | «pendiente de proveedor externo» | Minutos de agente: plan de pago o la excepción escrita D-4. Antes: D-1 (rotar la clave), D-5 (secretos y webhook; H-23), D-6 y D-7 | `PP8:78`, `PP8:126`, `PP8:151` |

### 3.5 Automatizaciones (`/marketing/automatizaciones`)

| # | Función | Estado | Por qué y qué falta | Evidencia |
|---|---|---|---|---|
| 36 | Panel: banderas, modo, constante, fuentes y coste del día | «disponible y operativa» | `GET outreach/status` responde 200 con todo apagado | `A9:48` |
| 37 | Botón PARADA GLOBAL | «pausada voluntariamente» | Con la maestra apagada, la API responde 409 `outreach_disabled`: no hay nada que parar. Por consola, `outreach:stop --all` sí escribe el freno | `A9:48`; L9-71 (`C9:1530`) |
| 38 | Estrategias, Constructor, Simulación y Plantillas | «bloqueada por condición contractual» | Está a un interruptor (`h9-outreach-scope.sh gobierno on`), pero la fase 1 es «todo apagado» y salir de ella exige las condiciones de REV9 para la fase 2, empezando por el E2E #26, que nunca se ha ejecutado | `C9:1317`; L9-73 (`C9:1532`) |
| 39 | Gobierno de estrategias: aprobar, publicar en canario, pausar y revertir | «bloqueada por condición contractual» | Cuatro ojos por persona verificada: dos personas vinculadas por consola (P9-4, P9-11). Mecanismo: `ULTRON_STUDIO_STRATEGIES_PUBLISHING_ENABLED=false` | `C9:1318`, `C9:1339` |
| 40 | Vínculos de personas | «bloqueada por condición contractual» | Las dos primeras, por consola y con autorización; exige la segunda persona real | `C9:1318`; `A9:77` |
| 41 | Bandeja asistida | «bloqueada por condición contractual» | Fase 3: sombra de al menos 7 días sin hallazgos y P9-12; después `MODE=live` y `COMMERCIAL_AUTOMATION_ASSISTED_ENABLED` | `C9:1319` |
| 42 | Historial por persona | «pausada voluntariamente» | 409 con la maestra apagada; sin datos | `A9:48` |
| 43 | Canales WhatsApp y voz por estrategia (indicadores del Panel) | «bloqueada por condición contractual» | El envío no existe: W9 y V9 son hitos posteriores por contrato. W9 depende además de plantillas aprobadas por Meta (proveedor) y del visto bueno legal; V9, de las fases 4a y 4b del H8 | `C9:32`; `A9:105-106` |

**«error técnico real»: ninguna función.** El 10-11 el smoke del H9 dio 37 OK · 0 FALLO y la vigilancia de 15 minutos no vio respuestas 5xx, colas fallidas nuevas ni ERROR nuevos (L9-76, L9-77). Lo que fallaría al activar está en §4.1 (B-T1, B-T2, B-T7, B-T13, B-T14).

---

## 4. Brechas

### 4.1 Brechas técnicas reales que NO dependen del plan de pago (implementar o configurar)

Marcadas con 🔒 las que pasan por `security`; con 🧪, las que cubre `tester`.

| Id | Brecha | Hito | Evidencia | Qué bloquea | Dueño propuesto |
|---|---|---|---|---|---|
| B-T1 🔒 | **No hay vía para desplegar el commit `voice(calls-open)`.** El único guion de la voz aborta si la constante no es exactamente `false` en `origin/main` («la fase 1 no despliega la constante abierta») y su `PREVIO` sigue en `fe29126`, cuando producción está en `ce7c150`; además vería los commits del H9 como ajenos. El banco abrió la constante a mano en su clon, sin el guion, y `PP8:92` dice que se despliega «con el guion de la fase 1» | H8 | `ops/deploy-h8-backend-fase1.sh:70`, `:333-338`; `ops/banco-h8/ensayar-ops8d.sh:20`, `:462` | La fase 4a, aunque se pague el plan | `devops`: un guion o modo «4a» que solo admita un commit `voice(calls-open):` de una línea, con `PREVIO=ce7c150`; revisión de `security` |
| B-T2 | **M-2:** la guarda de la transcripción echa el freno con 13 de 15 negativas naturales que la plantilla no fija palabra por palabra | H8 | E16-28 (`C8:982`); `M8:66` | La 4a se frenaría en la primera llamada real | Propietario (P-M2) y S8: plantilla con frases exactas (lo recomendado) |
| B-T3 🔒 | **La supresión de la Ley 1581 no cubre las 11 tablas de la voz**; la propuesta (7 cambios) está sin probar | H8 / H7 | `PP8:139`, `PP8:152` (H-24); L9-71 (H-11, `C9:1530`) | La 4b; y ya hay datos del personal en `ultron_studio_voice_profiles` | `database-specialist` |
| B-T4 🔒 | **Los contactos de voz se pueden registrar hoy sin bandera**, antes del visto bueno legal y sin supresión de la voz | H8 | `app/Services/Telephony/VoiceContacts.php:75-119`; `app/Http/Controllers/Crm/Voice/VoiceContactController.php:36-48`; `PermissionCatalog.php:255` | Riesgo de cumplimiento (Ley 1581) mientras exista la llave | `security`: una bandera o no conceder `telephony.contacts_manage` hasta la 4b |
| B-T5 | Salud del webhook automatizada (D8-21, decisión f), sin desarrollar | H8 | `M8:52` | La fase 3 | `backend` |
| B-T6 🧪 | E2E del CRM de la voz sin pasada limpia: 262/264 el 09-11; el H-1 se corrigió después y no se repitió | H8 | `plans/informes-h8/informe-tester.md:27`; `CI8:48` | Evidencia de la interfaz de voz contra la pila real | `tester` |
| B-T7 | La expectativa «sin perfiles de voz» del paso 4 del guion y del smoke quedó caduca: cada smoke del H8 da 1 FALLO falso | H8 | E16-30, pendiente (a) (`C8:984`) | Los smokes de las fases 2a y 4a | `devops` |
| B-T8 | Texto del CRM desfasado: «la constante del código sigue cerrada hasta la fase 3» contradice E16-16 (la 4a va antes que la 3, que es imposible con el número actual) | H8 | CRM `src/app/modules/ultron-studio/studio-voice-text.ts:61`; E16-16 (`C8:970`) | Confunde a quien opera | `frontend` |
| B-T9 🧪 | **TEST9-b:** `OutreachIsolationTest::exercise()` sin las 13 rutas ni las 5 órdenes, y el **E2E #26** (nueva estrategia desde el CRM con la pila real) no existe | H9 | `A9:16`, `A9:33`; L9-73 | La fase 2 del H9 | `tester` |
| B-T10 | **PERF9:** los presupuestos de rendimiento no se han medido (el smoke los omite) | H9 | `A9:56`; L9-76 | La fase 2 | `performance-specialist` |
| B-T11 | **DOC9 sin entregar:** no existen `ULTRON_STUDIO_H9_COMPLETITUD.md` ni el runbook de las fases 2–3; el H9 no figura en la hoja de ruta, el plan de despliegue ni el informe final | H9 | `A9:21`, `A9:57`; `HR:5-14`, `HR:201-213`; el plan de despliegue termina en el H8 (`PDS:1849`) | Operar las fases 2–3 | `documentation-specialist` |
| B-T12 | m-9 (la decisión `cancel` en la línea de tiempo) y H-1 (el reloj del monitor) | H9 | L9-73 (`C9:1532`) | La fase 2 | `backend` |
| B-T13 | **El monitor del H7 no se puede encender tal cual:** su expectativa por defecto (`elevenlabs:ELEVENLABS_NOT_CONFIGURED`) ya no es la de producción, y `schedule:run` corre en el crontab de root (B-4, por comprobar) | H7 | `PP8:52`; `HR:87`; `config/ultron_studio.php:370` | A-7: la «monitorización» del H7 | `devops` (con la autorización del propietario) |
| B-T14 | **C-14:** dos mandos exigen el fichero de entorno en 600 exacto y abortarían con el 640 de la propuesta de `ironbody-migrate`, también al apagar el monitor | H6 / H7 | `ops/h6-integrations-scope.sh:110`; `ops/h7-monitor-scope.sh:144`; `HR:115` | B-2 y B-3; la fase 2 del H6 si antes se aplica B-3 | `devops` |
| B-T15 | El CRM no enseña el `verified` por entrada que la API da desde el H7 | H7 / H5 | `HR:128`; ninguna aparición de `verified` en CRM `studio-publication-history.ts` | Ver la integridad de cada entrada sin la API | `frontend` |
| B-T16 | Un worker muerto con trabajo reservado no lo ven `queue:health`, el monitor ni IRON GUARD (H-7 de OPS7, C-3) | H7 (afecta a H4 y H8) | `HR:104`, abierto al 02-11 y sin constancia de corrección | Vigilar `ironbody-lab` y, antes de la 4a, `ironbody-voice` | `backend` / observabilidad |
| B-T17 🔒 | `security:check-secret-exposure` no vigila ninguna clave de la voz (ElevenLabs y Twilio Voice) ni las 13 que ya dejaba fuera (H-4 de SEC7); H-6 (invisibles en las condiciones de las reglas y en el texto del juez) sigue abierto según `HR:108` | H7 / H8 | `app/Console/Commands/CheckSecretExposure.php:45-54` (comprobado hoy); `HR:108` | Detectar un fichero legible con secretos de la voz | `backend` + `security` |

**Brechas de alcance (decisiones, no defectos):**

| Id | Brecha | Evidencia | Qué implica |
|---|---|---|---|
| B-A1 | **Ningún hito hace que la configuración del Studio cambie lo que ULTRON dice o hace por WhatsApp.** El H5 eligió la opción A (declaración firmada y sello; «Aplicación: Vacío»). Solo la voz (H8) y las estrategias (H9) convierten lo aprobado en comportamiento | `C5:9`, `C5:35`, `C5:286`; `CG:22-27` | Si el propietario quiere gobernar a ULTRON desde el editor de las diez capas, hace falta un hito o una enmienda de «aplicación», con su contrato, sus riesgos y su puerta del motor (`architect` + propietario) |
| B-A2 | Pregunta abierta Q6 de la arquitectura: ¿el Studio escribe prompts en n8n o solo la base de conocimiento? | `ULTRON_STUDIO_ARCHITECTURE_AUDIT.md:158` | Va ligada a B-A1 |

### 4.2 Pendientes del propietario o de terceros que NO dependen del plan

**Transversal: la segunda persona real con cuenta propia (D-7).** Es el bloqueante más repetido: validación 2 del H4 (A-1), fases 2 y 3 del H5 (A-3, A-4), T1 del H6 (A-5), laboratorio y publicación de la voz (D-7) y vínculos y gobierno del H9 (P9-4, P9-11). Los cuatro ojos de F a H8 comparan **cuentas**; los del H9, **personas verificadas**.

**Seguridad, urgentes (no esperan a ninguna fase):** D-1, rotar la clave de ElevenLabs (A-2: con el número importado permite hoy llamar como Iron Body fuera de Laravel); D-2 / A-1, **confirmado por la sonda del lead:** el número se importó con el Auth Token maestro de la cuenta del OTP, así que ElevenLabs guarda la credencial que firma el OTP de todos los socios (`PROV:13`): rotar ese token y reimportar con una credencial de alcance mínimo o desde la subcuenta (b′); B-1, rotar la clave de OpenAI del laboratorio y poner límite de gasto; B-6, key restringida de Twilio y rotar el Auth Token maestro (`CI8:77-78`; `HR:84`, `HR:89`).

| Hito | Pendientes del propietario o de terceros (sin plan) |
|---|---|
| H4 | A-2 (cotejar el coste con el panel de OpenAI) y la validación 2 |
| H5 | Autorización escrita de las fases 2 y 3 |
| H6 | A-5 (fase 2), A-6 (`log_error_verbosity = terse`), C-8 (READ COMMITTED de `iron_app` y `opcache.validate_timestamps`), B-8 (alcances del token de Meta); lectura de las 24 h de la fase 1, que no consta |
| H7 | A-7 (encender el monitor, tras B-T13), B-2, B-3, B-4, B-5, B-7, B-9, B-10, B-12 y B-13 |
| H8 | D-3 (subcuenta de Twilio (b′), claves restringidas y token), D-5 (registro de secretos y webhook; H-23 podría convertirlo en «solo con plan»), D-6, D-8 (instalar el worker), D-9 (orden del commit `voice(calls-open)`, que además necesita B-T1), D-10 (entrantes sí o no), **D-11 (renovar la verificación antes del 03-12-2026 a las 22:55 UTC)**, D-12 (visto bueno legal), D-13 (fase 2 del H6 y canario de voz), ratificar E16-16, decidir P-M2 y copiar los mandos a `/root` (`CI8:77-89`; `PP8:33-52`) |
| H9 | P9-24 (encender `COMMERCIAL_EVENTS_ENABLED`, que cambia el motor comercial: leer antes qué escribe y cuánto cuesta), P9-11, P9-12, P9-17, P9-18, P9-20, R-1 del runbook de rotación de la `APP_KEY`, R-1 del canal SSE (P9-25) y, antes de cualquier envío automático, el visto bueno legal (P9-22) y las plantillas aprobadas por Meta (W9) (`C9:1394-1407`; `A9:77-79`) |

**Costes que no son el plan de ElevenLabs:** un número de Twilio con entrantes (17,50 US$ al mes) solo si se quieren entrantes (D-10); cada saliente (la reserva por llamada es 0,6105 US$ y el contrato estima unos 0,97 US$ con los minutos; `PP8:94`, `PP8:100`); el laboratorio de OpenAI (tope de 2 US$ al día); WhatsApp por mensaje (W9).

### 4.3 Lo que SÍ depende del plan de pago de ElevenLabs

Una sola cadena, porque los cinco pasos dependen del mismo hecho: hablar con un agente de ElevenLabs (`PP8:126`).

1. **2a.7** · Primera sesión real del laboratorio hablado y su veredicto `approved` por otra persona (o la excepción escrita D-4, que solo vale para el laboratorio).
2. **4a.11** · Publicación canaria de la voz: la puerta `required` exige ese veredicto.
3. **4a.13** · Smoke 4a en verde: necesita la publicación.
4. **4a.15** · Primera saliente real al propietario.
5. **4a.16** · Evidencia (3 llamadas con post-llamada, 24 h, 0 incidentes) y promoción a `rest`.

Por arrastre: la fase 4b (además, el visto bueno legal) y la voz por estrategia del H9 (V9, que exige las fases 4a y 4b). **Por confirmar:** si el plan gratuito admite secretos y webhooks del workspace (H-23); lo dice `h8-secrets-register.sh --solo-comprobar`, que solo hace GET (`PP8:151`).

**Nada de los hitos 1–7, ni de las fases 1–3 del H9, depende del plan de ElevenLabs.** Y dentro del H8, todo lo anterior a 2a.7 se puede dejar hecho hoy (`PP8:107-126`), incluida la fase 4a menos la publicación y la llamada, siempre que antes se cierren B-T1 y B-T2.

---

## 5. Porcentaje por hito (sustentado)

Fórmula de §0.3: **% = 0,4 × K + 0,2 × D + 0,4 × O**.

| Hito | K · construcción | D · despliegue | O · activación y operación real | % |
|---|---|---|---|---|
| 1 | 1: auditoría y decisiones aprobadas | No aplica (documental) | No aplica | **100 %** (solo K) |
| 2 | 1: módulo 46/46, E2E 44/44 y la revisión con todo corregido (`IF:71-79`) | 1: `4f7d774` publicado y verificado | 1: el contrato no tiene fases de activación; la vista previa está publicada y verificada | **100 %** |
| 3 | 1: F y G con sus suites, E2E 32/32 y 126/126, revisiones cerradas | 1: F y G en producción, smokes 22/22 y 29/29 | 1: sin fases de activación; funciones encendidas y verificadas por smoke. **Advertencia:** la aprobación a cuatro ojos nunca se ha ejercido en producción; el contrato no lo exige | **100 %** |
| 4 | 1 | 1: fases 1–3 aplicadas | 0,25 = ejecución real validada contra el panel: ½ (la ejecución está; falta el cotejo A-2) · veredicto de otra persona sobre una versión: 0 (`IF:340`) | **70 %** |
| 5 | 1 | 1 | 0 = fase 2: 0 · fase 3: 0 (`C5:12`) | **60 %** |
| 6 | 1 | 1 | 0,25 = T1 en `in_sync`: 0 · configuración de los 3 proveedores reconocida: 0 · sonda con su instantánea: 0 · panel coherente con la clasificación: 1. El criterio 6 queda fuera: el H8 lo superó (`C6:1342-1348`) | **70 %** |
| 7 | 1: los siete paquetes confirmados y REV7 sin bloqueantes | 1: en producción desde el 03-11 | 0,5 = endurecimiento activo y verificado: 1 · monitor encendido con autorización: 0 (`HR:78` lo pone como condición de `HITO_7_COMPLETED`) | **80 %** |
| 8 | 0,9: de las 48 filas de `M8`, 5 siguen pendientes de desarrollo (salud del webhook, vía de `voice(calls-open)`, E2E del CRM limpio, supresión 1581 de la voz y M-2 a medias: `M8:49`, `M8:52`, `M8:66`, `M8:69`, `M8:80`) | 1: fase 1 y 1b | 0 = fases 2, 2a, 4a, 3 y 4b: ninguna activa (`C8:660-665`; `CI8:121-131`) | **56 %** |
| 9 | 0,79: 8 de 11 paquetes confirmados; TEST9 en 2 de 3 (falta TEST9-b); PERF9 y DOC9 sin entregar (`C9:1328-1331`) | 1: fase 1 con smoke 37 OK | 0 = sombra: 0 · asistido: 0 | **52 %** |

**Lectura del conjunto:** la construcción media de los nueve hitos es del 97 %; la activación real de los hitos que la tienen (4 a 9) es del 17 %. El sistema está construido y probado con simuladores; lo que falta es operarlo, y eso depende sobre todo de personas y decisiones, no del código ni del plan de pago.

---

## 6. Discrepancias documentales encontradas (para `documentation-specialist`)

| # | Discrepancia | Dónde |
|---|---|---|
| 1 | El encargo cita `ULTRON_STUDIO_ROADMAP.md`; el fichero es `ULTRON_STUDIO_MASTER_ROADMAP.md` | — |
| 2 | La hoja de ruta no tiene el H9: la tabla termina en el H8 y el registro de despliegues no incluye el 1b del H8 ni el H9. Su fila del H8 aún da producción en `fe29126` / `45dd73a`, y su consolidado de pendientes es del 02-11 | `HR:5-14`, `HR:64`, `HR:203-213` |
| 3 | El informe final dice en «Los siete hitos, de un vistazo (02-11-2026)» que el H7 está «implementado en rama, sin desplegar», y en otra fila que está en producción. No tiene sección del H9 | `IF:22-34` frente a `IF:18` e `IF:821` |
| 4 | El plan de despliegue no tiene sección del H9; el runbook del H9 solo vive en los guiones y en el informe de devops | `PDS:1849`; `plans/informes-h9/informe-devops.md` |
| 5 | Tablas del H9: el commit `4ad9cb5` y la matriz de aceptación dicen 14; las migraciones crean 15 (7 + 8) y L9-76 cuenta 15 | `A9:41`; migraciones `2026_11_12_100001` y `…_100002` |
| 6 | Variables del H8 en producción: E15-2 y E16-30 dicen que las 9 claves están escritas con su valor apagado; `PP8:5` dice que no hay ninguna `VOICE_*` ni `ULTRON_STUDIO_VOICE_*`. Las dos lecturas significan «apagado»; lo resuelve `h8-voice-scope.sh estado`, que es de solo lectura | `C8:943`, `C8:984`; `PP8:5` |
| 7 | El procedimiento de pruebas físicas es del 01-11: da el H6 por no desplegado, espera `ELEVENLABS_NOT_CONFIGURED` en §6.3 y dice que no se ha ejecutado ninguna prueba; solo su §9 (voz) se actualizó | `PF:9`, `PF:21`, `PF:527` |
| 8 | Cierre del H7: el pendiente A-7 ata `HITO_7_COMPLETED` a encender el monitor, y el hito se declaró completado con el monitor apagado; el contrato es ambiguo («monitor apagado por defecto y encendido con autorización») | `HR:78` frente a `HR:13` e `IF:821`; `C7:24` |
| 9 | El criterio 6 del cierre del H6 (`ELEVENLABS_NOT_CONFIGURED`) no se puede cumplir desde E1-12 del H8: hace falta una enmienda que lo reescriba | `C6:1348`; `PP8:5` |
| 10 | La matriz del H8 marca el worker de voz como «pendiente de desarrollo», pero su conf ya existe y está ensayada | `M8:63` frente a `PP8:134` |
| 11 | El informe de B9-run dice que el bloque del programador del H9 está «COMENTADO»; en el código está activo detrás de su bandera | `plans/informes-h9/informe-run.md:142` frente a `routes/console.php:484-513` |
| 12 | La guía de administración repite el texto desfasado del CRM sobre la constante y la fase 3 (B-T8) | `ULTRON_STUDIO_GUIA_ADMINISTRACION.md:480` |
| 13 | `PP8:41` dice que el perfil de voz aprobado lleva la voz de pruebas de los fixtures; la sonda del lead lee «Lina - Colombian Warm & Confident» | `PP8:41` frente a `PROV:11` |
| 14 | Fecha de la llamada manual: los documentos del H8 la sitúan el 09-11 (cuando el propietario la comunicó) y `PP8:90` cuenta la ventana de 48 h de la conciliación hasta el 11-11; según el historial de Twilio fue el 03-11 a las 22:48 UTC, ya fuera de esa ventana | `M8:11`, `PP8:90` frente a `PROV:22` |

---

## 7. Lo que esta auditoría no verificó

- **Producción:** no se entró al servidor. Todo valor de producción es la lectura del lead del 10-11 o el último registro fechado. En particular, no constan: el estado actual del laboratorio del H4 (driver, presupuesto y clave: dato del 02-11); la verificación a las 24 h de la fase 1 del H6; las lecturas a las 24 h del H8 (fase 1, desde el 10-11 a las 12:06 UTC; 1b, desde el 11-11 a las 02:24 UTC) y del H9 (desde el 11-11 hacia las 20:27 UTC); si alguien reconoció la configuración después del 03-11; cuántas versiones, reglas y aprobaciones reales hay; y quién aprobó el perfil de voz 1.
- **Pruebas:** esta auditoría no ejecutó ninguna; las cifras son las de los informes y la evidencia citados.
- **Verificado directamente en el código, hoy:** las constantes (`app/Services/Voice/VoiceCallPolicy.php:23`, `app/Services/Outreach/Run/OutreachSendGate.php:35`, `app/Services/UltronStudio/StudioPublishing.php:39`); las banderas y sus valores por defecto (`config/ultron_studio.php`, `config/voice.php`, `config/outreach.php`, `config/ultron_studio_stream.php`, `config/marketing.php:102`); los recuentos de rutas; las 15 tablas del H9; el guion de despliegue de la voz (`PREVIO` y la comprobación de la constante); los contactos de voz sin bandera; las claves que vigila `security:check-secret-exposure`; las rutas, pestañas, menús y textos del CRM; que no existe el E2E #26 ni especificaciones del H8 o del H9 en el arnés E2E del CRM; y que el historial de publicaciones del CRM no enseña `verified`.
- **Consolas de proveedores** (OpenAI, ElevenLabs, Twilio, Meta): no se consultaron; lo de ElevenLabs y Twilio viene de la sonda del lead (`PROV`).
- **Trabajo sin commit de otros agentes, aparecido durante esta auditoría (no evaluado):** en el backend, 13 ficheros de código modificados (entre ellos `app/Services/Outreach/Run/OutreachSendGate.php`, citado aquí: la constante sigue en `false`, ahora en la línea 38; `Channels/WhatsappTemplateChannel.php`, `Channels/VoiceStrategyChannel.php`, `app/Services/Telephony/OutboundGate.php`, `MarketingMessageDispatcher.php`, el tique del canal SSE y `SecurityHeaders.php`), 10 pruebas y `tests/baseline-failures.txt`, 8 clases nuevas, 2 migraciones nuevas (`2026_11_13_100001_allow_strategy_requester_on_voice_calls.php`, `2026_11_13_200001_create_ultron_studio_template_meta_checks_table.php`), `ops/h9-e2e/`, `plans/informes-h9/informe-tester-final.md` y dos documentos del lead (`ULTRON_STUDIO_ACTIVACION_POR_ETAPAS.md` y `PROV`); en el CRM, 2 ficheros modificados y 7 nuevos (un panel de activación). Por los nombres, apuntan a W9 y V9, al tique del SSE (R-1), al E2E #26 (B-T9) y a los rojos de la regresión: **hipótesis sin verificar**. Nada de eso está en producción ni cambia esta auditoría, que es de HEAD (`d701b0c` / `9572426`); ninguna brecha de §4.1 se da por cerrada sin commit, pruebas y revisión.

---

## 8. Siguiente paso recomendado

1. **Ya, sin esperar fases (`security` + propietario):** D-1 y A-1 confirmado (rotar la clave de ElevenLabs y el Auth Token maestro de Twilio, y reimportar el número con una credencial de alcance mínimo), B-1 (clave de OpenAI) y decidir B-T4 (contactos de voz sin bandera).
2. **Conseguir la segunda persona real:** desbloquea a la vez el H4, el H5, el H6, el laboratorio y la publicación del H8 y el gobierno del H9.
3. **Antes de pagar el plan de ElevenLabs:** cerrar B-T1 y B-T2 (sin ellos el plan no basta para la 4a), decidir D-3, correr `h8-secrets-register.sh --solo-comprobar` para resolver H-23 y copiar los mandos a `/root`.
4. **Antes de la sombra del H9:** B-T9, B-T10, B-T11, B-T12 y la decisión P9-24.
5. **Decidir B-A1:** si el Studio debe gobernar o no el comportamiento de ULTRON por WhatsApp. Es la diferencia más grande entre lo que el nombre «editor de las diez capas» sugiere y lo que los contratos construyeron.
6. **Poner al día** la hoja de ruta, el informe final, el plan de despliegue y las pruebas físicas (§6).

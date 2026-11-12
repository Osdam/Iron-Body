# ULTRON STUDIO FULL · Cierre V2 · Matriz única de control y punto de control

**Encargo:** «MASTER CLOSURE LOOP V2», del propietario, 12-11-2026 (fecha límite: 15-11-2026).
**Para qué sirve este fichero:** es la única matriz del cierre y el punto de control para retomar. Si la sesión se interrumpe, se empieza por el apartado 0 de este fichero, no por el apartado 0 del encargo.

---

## 0. Punto de control

| Campo | Valor |
|---|---|
| Actualizado | 12-11-2026, 21:49 UTC (16:49 en Bogotá) |
| Apartado actual | **3 · Laboratorio de aceptación y WhatsApp canario** |
| Estado del apartado | **ESPERANDO INTERVENCIÓN HUMANA** |
| Gate pendiente | **3-B · Publicación canaria, paso 2 de 4: los mensajes desde el WhatsApp canario** (después, de uno en uno: 3, revertir; 4, un mensaje más para comprobar la vuelta a Legacy) |
| Qué se le pidió | Paso 2 de 4 del gate 3-B: desde su WhatsApp canario, en la conversación de siempre con el gimnasio, enviar tres mensajes DE UNO EN UNO, esperando la respuesta de cada uno: (1) «Hola, buenas tardes»; (2) «¿Qué planes tienen y cuánto cuesta la mensualidad?»; (3) «¿Cuál es el horario de atención entre semana?». Sin pedir asesor, visita, pago ni baja, que disparan otros flujos |
| Evidencia esperada | Que diga «listo» (y, si puede, una captura del chat con las tres respuestas). Se verifica en el servidor |
| Cómo se comprueba | Solo lectura en producción: `php artisan ultron:prompt-trace --last=5` (tres turnos nuevos: declarada y ofrecida la versión `4017639c…`, observada `702890f7` por la copia S1, estado `applied`, sin `mismatch` ni `legacy_drift`) y los tres mensajes salientes nuevos de la conversación canaria (`ops/comprobacion-produccion/canaria-estado.php`): su longitud frente a los 200 caracteres y su tono |
| Commits | Backend `143c91d0` (código; antes `5595fd78`, `d6330d56`, `78c81840`, `5f2ec53f`, `3b2aae44`) y `12fc286a` (documentación del apartado), todos en `origin/main`. CRM sin cambios: `eeb4b79` |
| Producción | API `143c91d0` (12-11 20:54 UTC); CRM `eeb4b79`. Llamadas y envíos cerrados en el código; automatización en sombra |
| Pruebas | Batería completa del backend sobre `143c91d0`: 17 847 pruebas, 458 627 aserciones, solo el fallo de la línea base, 0 regresiones. Dos revisiones independientes, sin bloqueantes. Seis ejecuciones reales del laboratorio en producción (US$ 0,4688) |
| Agentes | Ninguno abierto. `rev-lab-a3` y `rev-lab-a3b` (reviewer), cerrados |
| Próximo paso exacto | Cuando el propietario diga que envió los mensajes: (1) leer la traza y los mensajes salientes en producción; (2) si los turnos son `applied` con `702890f7`, registrar la evidencia y pedirle el paso 3: en el Studio, «Versiones» → la versión → «Revertir», con un motivo; (3) verificar la cadena (canario: Legacy); (4) pedirle el paso 4: un mensaje más, y comprobar que el turno vuelve a `legacy 3f6a5674`. Solo entonces se cierra el apartado 3. Si un turno sale `mismatch`, `legacy_drift` o sin respuesta: NO pedir más mensajes, investigar y quedarse en este paso |

**Mientras este gate esté abierto no se trabaja en ningún apartado posterior, no se abren agentes y no se despliega nada.**

---

## 1. Matriz

Estados de apartado: NO INICIADO · EN DESARROLLO · EN PRUEBAS · ESPERANDO INTERVENCIÓN HUMANA · EN VERIFICACIÓN · CERRADO · BLOQUEADO EXTERNAMENTE CON DECISIÓN PENDIENTE.
Estados de funcionalidad: OPERATIVO Y PROBADO · IMPLEMENTADO Y LISTO PARA ACTIVACIÓN · EN OBSERVACIÓN · BLOQUEADO POR DEPENDENCIA EXTERNA · INCOMPLETO.

| Apartado | Subtarea | Estado técnico | Prueba | Gate humano | Evidencia | Resultado |
|---|---|---|---|---|---|---|
| 1 · Recuperar el estado | Repositorios, ramas y árboles; cambios de otras sesiones; producción; procesos y agentes anteriores | Hecho el 12-11 19:38 UTC | Lectura directa | Ninguno | Backend `41d75b61` = `origin/main`, limpio; CRM `eeb4b79` = `origin/main`, limpio; producción API `298b855a` (sin ficheros rastreados modificados) y CRM `eeb4b79`; ningún proceso ni agente vivo | **CERRADO** |
| 1 | Evidencia de las olas 5 a 7 y de la ronda final | Leída | — | Ninguno | Filas L9-114 a L9-118 del contrato del H9; `ops/evidencia-h9/2026-11-12/` | **CERRADO** |
| 2 · Coordinación multiagente | Paralelismo solo dentro del apartado activo; pausa sincronizada ante un gate | Adoptada | — | Ninguno | Este fichero; un solo agente usado en el apartado 3 y cerrado antes del gate | **CERRADO** (es una regla, no una tarea) |
| 3 · Laboratorio de aceptación | C-11, «escenario ausente» | Investigado: no falta | Lectura del catálogo y de producción | Ninguno | `StudioLabCatalog` (retirado en `2026-10-20.1`, Enmienda 6 de H4): el catálogo tiene 11 escenarios y producción, los mismos 11 | **CERRADO** |
| 3 | C-04, C-06, C-07 y C-08, y los siete casos «para revisión» | Causa demostrada y corregida: era la simulación (actor y juez), no la versión | Unit del Studio, 2 031; laboratorio y publicación, 315; batería completa; ejecuciones reales en producción | Ninguno | Contrato H4, Enmienda 8; `StudioLabPrompts` `2026-11-12.6`; L9-119; `informe-reviewer-lab-a3.md` y `informe-reviewer-lab-a3b.md`; `ops/evidencia-h9/2026-11-12/laboratorio-a3/` | **CERRADO** en lo técnico: con `2026-11-12.6`, versión 10 pasan · 1 para revisar · 0 fallan, y línea base igual. El «para revisar» es C-02 c3 (deseable): responde el precio y no propone el paso siguiente |
| 3 | Veredicto del laboratorio sobre la versión «Prueba de aceptación · profesional 200» | Veredicto «Aprobada», registrado por el propietario | Lectura de producción (`ultron_studio_lab_verdicts`) | 3-A, CERRADO | Veredicto 1: cuenta #27 «Desarrolladores» (Super Admin), 12-11 21:32:47 UTC (4:32 p. m. en Bogotá), ejecución `a6169238-f652-480d-ab03-7d3d7159c5ed`, huella `905b5925`, C-02 reconocido | **CERRADO** |
| 3 | Publicación canaria, 2–3 mensajes desde el WhatsApp canario, traza y reversión | Paso 1 (publicar): hecho por el propietario y verificado. Pasos 2 a 4, pendientes | `publication-check` y `prompt-trace` en producción | **3-B, ABIERTO (paso 2 de 4)** | `ops/evidencia-h9/2026-11-12/canario-a3/01-publicacion.txt`: cadena, entrada 1 (`publish`, 12-11 21:45:13 UTC, cuenta #27, firma verificada), canario = la versión, resto = Legacy; la canaria ofrece ahora `studio · 4017639c… · 702890f7`; freno suelto | **ESPERANDO INTERVENCIÓN HUMANA** |
| 4 · Studio gobierna WhatsApp | Las diez capas, contrato Laravel → n8n, frases prohibidas, promoción por etapas, reversión | NO INICIADO en V2 | — | 4 | Punto de partida: auditoría A (`plans/cierre/auditoria-a-studio-whatsapp.md`), compilador 2 (L9-113) | NO INICIADO |
| 5 · Laboratorio de texto frente al motor real | Prueba de los prompts compilados, comparación con el motor, escenarios mínimos | NO INICIADO en V2 | — | 5 | Punto de partida: Enmiendas 7 y 8 de H4 (el laboratorio es una simulación) | NO INICIADO |
| 6 · Sofía, ElevenLabs y publicación de voz | Revisión vigente del perfil, agente publicado desde Laravel, canario, reversión | NO INICIADO en V2 | — | 6 | Punto de partida: auditoría C; L9-110 | NO INICIADO |
| 7 · Twilio Voice | Capacidad existente, salientes, entrantes, apertura de las tres llaves | NO INICIADO en V2 | — | 7-A y 7-B | Punto de partida: `ops/RUNBOOK_PRIMERA_LLAMADA.md`, `ops/apertura-llamadas/` | NO INICIADO |
| 8 · Automatización H9 (W9, V9, clasificador) | Oportunidades, decisiones, W9, V9, clasificador, sombra con estrategias | NO INICIADO en V2 | — | 8 | Punto de partida: sombra hasta el 18-11, 0 estrategias publicadas | NO INICIADO |
| 9 · Bandeja asistida | R3-1, R3-2, R3-3, conciliador, consultas por fila, carga sintética | NO INICIADO en V2 | — | 9 | Punto de partida: L9-118, `informe-security-bandeja-ronda3.md`, `informe-reviewer-ronda3.md` | NO INICIADO |
| 10 · Consentimientos, quejas y cuatro ojos | Fuente de consentimiento por canal, quejas presenciales y telefónicas, cuatro ojos por regla | NO INICIADO en V2 | — | 10 | Punto de partida: `plans/cierre/plan-quejas-consentimiento-cuatro-ojos.md` | NO INICIADO |
| 11 · Meta Ads y plantillas | Permisos, borradores, envío a Meta, estados, plantillas para W9 | NO INICIADO en V2 | — | 11 | Punto de partida: auditoría B | NO INICIADO |
| 12 · Pagos, membresías y eventos | Activador de membresías, barrido, la prueba histórica `PricingV2SalesAndPaymentsTest` | NO INICIADO en V2 | — | 12 | Punto de partida: L9-114, L9-115, `informe-db-comercial.md` | NO INICIADO |
| 13 · Credenciales e infraestructura | Rotar la clave de ElevenLabs, respaldos de entorno, workers y monitores | NO INICIADO en V2 | — | 13 | Punto de partida: apartado 4 del informe del propietario (puntos 5 y 8) | NO INICIADO |
| 14 · Pruebas finales y certificación | Los doce recorridos | NO INICIADO en V2 | — | 14 | Línea base: backend 17 845 (ahora 17 847), CRM 4 371 con 16 fallos históricos | NO INICIADO |

---

## 2. Registro de gates

| Gate | Abierto | Qué se pidió | Respuesta del propietario | Verificación | Cerrado |
|---|---|---|---|---|---|
| 3-A · Veredicto del laboratorio | 12-11-2026, 21:16 UTC | Revisar la ejecución nueva del laboratorio (12-11, 3:54 p. m.) y registrar el veredicto | «Aprobada», con C-02 reconocido, a las 4:32 p. m. | Leído en producción: veredicto 1, cuenta #27, `approved`, huella `905b5925` igual a la de la ejecución; evento `run.verdict_recorded` | 12-11-2026, 21:34 UTC |
| 3-B · Publicación canaria (paso 1: publicar) | 12-11-2026, 21:38 UTC | Publicar en canario la versión «Prueba de aceptación · profesional 200» desde el Studio | Publicada a las 4:45 p. m. con «Desarrolladores» | Leído en producción: entrada 1 de la cadena (`publish`, 21:45:13 UTC, cuenta #27, `verifica`), `publication-check` salida 0 («cuadra con el motor»), `prompt-trace`: la canaria ofrece `studio · 702890f7` | 12-11-2026, 21:46 UTC |
| 3-B · Publicación canaria (paso 2: mensajes) | 12-11-2026, 21:49 UTC (16:49 en Bogotá) | Paso 2 de 4 del gate 3-B: desde su WhatsApp canario, en la conversación de siempre con el gimnasio, enviar tres mensajes DE UNO EN UNO, esperando la respuesta de cada uno: (1) «Hola, buenas tardes»; (2) «¿Qué planes tienen y cuánto cuesta la mensualidad?»; (3) «¿Cuál es el horario de atención entre semana?». Sin pedir asesor, visita, pago ni baja, que disparan otros flujos | — | — | — |

---

## 3. Reglas del encargo (resumen fiel; el texto literal está en la conversación del 12-11-2026)

1. **Apartado por apartado, en orden (0 a 19).** Lo técnico seguro y autorizado se hace de forma autónoma. Si algo depende del propietario, se para todo.
2. **Qué cuenta como intervención humana:** abrir una plataforma externa, cambiar una configuración a mano, crear o instalar credenciales, configurar Twilio, ElevenLabs o Meta, comprar o asignar un número, un segundo factor, aprobar versiones, emitir veredictos, autorizar publicaciones, registrar consentimientos, vincular personas, registrar canarios, contestar llamadas, enviar WhatsApp, hablar por el laboratorio, revisar algo en el CRM, decisiones legales y autorizar cambios productivos sensibles.
3. **Al detectar una:** terminar solo lo que esté en curso y no se pueda cortar sin riesgo; no asignar trabajo nuevo; parar los agentes en un punto seguro; registrar el estado aquí; pedir UNA sola acción con el formato «ULTRON STUDIO — INTERVENCIÓN HUMANA REQUERIDA» (apartado, avance, bloqueo, qué hacer, dónde, instrucciones, resultado esperado, evidencia, riesgo o coste, estado); esperar; comprobar el resultado; reanudar el mismo apartado.
4. **Con un gate abierto está prohibido:** avanzar al apartado siguiente, empezar trabajo de apartados posteriores, abrir agentes para saltarse el bloqueo, desplegar lo que dependa del gate, tomar el silencio por autorización, inventar datos y dar el punto por terminado.
5. **Una acción cada vez.** Si una plataforma pide cinco pasos manuales, primero el primero.
6. **Al reanudar:** comprobar qué se hizo, revisar la evidencia, no dar por bueno lo contradictorio; si falló, explicar la corrección y seguir en el mismo punto.
7. **Dependencia que no se puede resolver ya (proveedor, decisión legal):** demostrarla, documentarla, dar las alternativas y pedir la decisión. Solo se pasa al apartado siguiente si el propietario lo autoriza expresamente.
8. **Nunca:** registrar un veredicto o una aprobación por el propietario; suplantar a un revisor independiente; contar una cuenta de QA como aprobación comercial; acortar los siete días de sombra; llamar o escribir a personas reales sin autorización concreta; pedir secretos por el chat; romper Twilio OTP, WhatsApp Legacy, Wompi, Factus, Firebase o la API móvil.
9. **Declaraciones finales permitidas:** «ULTRON STUDIO FULL — TÉCNICAMENTE TERMINADO» (alcance técnico completo y probado) y «ULTRON STUDIO FULL — OPERATIVO Y CERTIFICADO» (además, validaciones humanas, pruebas externas y autorizaciones). Sin porcentajes.
10. **Condición de parada:** todos los apartados cerrados, o sus bloqueos externos identificados y aceptados por el propietario. No se abren olas nuevas indefinidamente.

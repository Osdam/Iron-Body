# ULTRON STUDIO FULL · Cierre de los nueve hitos (fases 5 a 8 del encargo del 10-11-2026)

**Estado del documento:** la **sección 0 es lo vigente** (11-11-2026, 19:00 UTC). Las secciones 1 a 13 son el borrador del 10-11 y quedan como historia: donde contradigan a la sección 0, manda la sección 0. Los hechos de la sección 0 salen de las filas L9-103 a L9-111 del contrato del H9, de la Enmienda M-1 del contrato del H5 y de la Enmienda 18 del contrato del H8 (E18-8 a E18-10).

---

## 0. Estado vigente (11-11-2026, 19:00 UTC)

### 0.1 Qué hay en producción

| Pieza | Valor |
|---|---|
| API | `2d81221b` desde el 11-11 21:07 UTC (L9-112); `origin/main` va por delante solo con documentos |
| CRM | `fd02a4c` desde el 11-11 21:10 UTC (L9-112) |
| Respaldo de antes de la ola | `/var/backups/ironbody/ultron-studio-m1-20261111-143829` (base, fichero de entorno, cachés) |
| n8n | atiende la copia «Studio S1» (`QSklqHlsDIxucRfk`); el flujo de siempre (`YspRwsXnqt33NouP`) está despublicado e intacto, como reserva |
| Motor de WhatsApp | lee la publicación del Studio **solo en la conversación canaria** (`canary`); publicar desde el CRM, abierto; puerta del laboratorio `required` |
| Prompts de WhatsApp | llave en `canary`; sin nada publicado todavía la canaria recibe los de siempre (`legacy · no_publication`) |
| Plantillas de Meta | `full`: crear, aprobar, enviar a Meta y ver el estado desde el CRM; sincronización cada 5 minutos |
| Laboratorio hablado | encendido (fase 2a), perfil «Sofia Medellin» revisión 2; con una sesión abierta atiende las dos consultas de solo lectura (E18-8); la sede y el horario, fijados (E18-10) |
| Monitor de voz y publicación de voz | **encendidos** el 11-11 18:47 UTC con el mando de voz; publicar exige todavía versión y perfil aprobados, veredicto del laboratorio de otra persona y ventana |
| Monitor de integraciones (H7) | **encendido** desde el 11-11 18:14 UTC, con la expectativa de hoy; primera pasada: «El Studio cuadra», 0 incidentes |
| Autonomía comercial (H9) | en **sombra** desde el 11-11 05:23 UTC: decide y registra, no envía nada |
| Envíos y llamadas automáticos | cerrados por dos constantes del código (`SENDS_ENABLED_IN_CODE` y `CALLS_ENABLED_IN_CODE` en `false`) |
| OTP por SMS de Twilio, Factus, Wompi, WhatsApp de siempre | sin tocar |

### 0.2 Estado de cada componente

Etiquetas: **OPERATIVO Y PROBADO** (funciona en producción y hay una prueba real que lo demuestra) · **EN INTEGRACIÓN** (desplegado y encendido, falta la prueba real que lo cierra) · **IMPLEMENTADO PERO DESHABILITADO** (construido y probado en automático, apagado a propósito) · **BLOQUEADO** (no puede avanzar sin algo que no es código) · **NO IMPLEMENTADO**.

| Frente | Componente | Etiqueta | Evidencia o qué falta |
|---|---|---|---|
| A · Voz | Laboratorio hablado desde el CRM con Sofía | **OPERATIVO Y PROBADO** | Sesión real del 11-11 11:37 UTC: aviso de IA, entiende, responde, se despide y cuelga; 67 s, US$ 0,09 (L9-103; `ops/evidencia-h8/2026-11-11/`) |
| A · Voz | Que Sofía informe planes y horarios en el laboratorio | **OPERATIVO Y PROBADO** (sesión real del 11-11 18:55 UTC con la revisión 3 del perfil, enviada a revisión: consultó planes, precios, sede y horario, y coinciden con la base; evidencia en `ops/evidencia-h8/2026-11-11/laboratorio-sesion-real/intento-4-…`). Para PUBLICAR esa revisión falta que otra persona la apruebe. Historia del mismo día: | Hecho y desplegado el 11-11: el código (E18-8, `0d65694`, con revisión de seguridad) y la sede y el horario en producción (E18-10; leído por reflexión: `consultar_horarios` disponible y `consultar_planes` con 14 planes y su precio). **Falta lo que no es código:** una revisión 3 del perfil con las dos herramientas (la revisión 2 solo tiene «no volver a llamar»), que crea y aprueba el propietario con otra persona; después, una sesión real, en la que se verá además qué manda ElevenLabs como `call_sid` en una conversación web (si fuera un texto vacío, la consulta daría 422 y la línea `voice.tool.invalid_schema` lo diría) |
| A · Voz | Publicación canaria de la voz y primera llamada saliente | **BLOQUEADO** | Decisión de Twilio (subcuenta o clave restringida solo de voz), un veredicto del laboratorio dado por otra cuenta y la autorización expresa para esa llamada. El arreglo de seguridad que era obligatorio antes de la primera llamada (E18-9: el `call_ref` de una llamada no la alcanza desde otra conversación) está desplegado (`99f8bb2`) |
| A · Voz | Llamadas entrantes | **BLOQUEADO** | La cuenta de Twilio no tiene números propios |
| A · Voz | Rotar la clave de ElevenLabs que pasó por el chat | **BLOQUEADO** | La genera el propietario en ElevenLabs; el mando de registro ya existe |
| B · WhatsApp | El Studio compila y ofrece los cinco prompts de ULTRON; n8n los elige por la copia S1 | **OPERATIVO Y PROBADO** (con los prompts de siempre) | Turno real del propietario el 11-11 15:39 UTC: ejecución 941514 de la copia S1, traza `legacy · no_publication · observada 3f6a5674 S1` (L9-109). Los prompts usados son byte a byte los de antes |
| B · WhatsApp | Un cambio publicado en el Studio cambia lo que ULTRON responde (**criterio de aceptación**) | **EN INTEGRACIÓN** | Falta E3–E5: publicar en canario una versión con tono «profesional» y 200 caracteres, ver `applied` en un turno real y revertir. Exige dos personas y un veredicto del laboratorio de texto |
| B · WhatsApp | Gobernar desde el Studio la intención de reserva (`intent.fallback`) y las reglas S-NNN | **NO IMPLEMENTADO** | Declarado en la Enmienda M-1: hoy el Studio gobierna tono, emojis, longitud máxima y objetivo |
| B · WhatsApp | ULTRON para todos los clientes | **IMPLEMENTADO PERO DESHABILITADO** | Hoy solo la conversación canaria pasa por n8n; abrirlo es una decisión del propietario |
| B · WhatsApp | Alerta automática si n8n aplica algo distinto de lo ofrecido | **EN INTEGRACIÓN** | Desplegada el 11-11 (L9-112): el monitor la comprueba cada 30 minutos y abre el incidente `prompts_drift`. En producción su pasada en seco lee bien; ningún `mismatch` real la ha disparado todavía |
| C · Autonomía | Motor en sombra | **OPERATIVO Y PROBADO** | Corre cada minuto desde el 11-11; los siete días terminan el 18-11 |
| C · Autonomía | Modo asistido | **IMPLEMENTADO PERO DESHABILITADO** | No antes del 18-11; cupos a 0 |
| C · Autonomía | Envíos de WhatsApp y llamadas por estrategia | **IMPLEMENTADO PERO DESHABILITADO** | Las dos constantes del código, cerradas |
| C · Autonomía | Vínculos de persona (cuatro ojos reales) | **BLOQUEADO** | 0 vínculos: el propietario dice quiénes son las dos personas |
| D · Plantillas | Pestaña «Plantillas» del CRM: crear, vista previa de WhatsApp, aprobar con otra persona, enviar a Meta, ver estado | **EN INTEGRACIÓN** | Desplegada y encendida (`full`); probada con el doble de Meta y los smokes. Ninguna plantilla se ha enviado todavía a Meta: falta «Comprobar permisos» y la primera plantilla |
| D · Plantillas | Que el motor use una plantilla aprobada por Meta | **IMPLEMENTADO PERO DESHABILITADO** | Mismo cierre que los envíos del H9 |
| E · Infra | Despliegue con respaldo, mandos con vuelta atrás y smokes | **OPERATIVO Y PROBADO** | Dos ensayos en el banco, despliegue sin desvíos, vigilancia de 15 minutos limpia (L9-106, L9-107) |
| E · Infra | Monitor de integraciones del H7 | **OPERATIVO Y PROBADO** | Encendido el 11-11 18:14 UTC con el mando corregido (contaba la sincronización de plantillas como tarea suya) y la expectativa de hoy (`expectativa fase1`: OTP de Twilio conectado, voz de Twilio sin configurar, ElevenLabs verificado). Primera pasada real: publicación, integraciones y colas en OK, 0 incidentes. Corre cada 30 minutos. **Ojo:** la verificación de ElevenLabs caduca el **03-12-2026** (30 días desde el 03-11); ese día abrirá un incidente verdadero y el laboratorio dirá `credentials_unverified` si antes no se renueva con `ultron-studio:integrations-snapshot --verify-voice`. Y cada etapa de la voz que cambie una integración lleva su `expectativa` |
| E · Infra | Presupuestos de rendimiento del H9 (PERF9) | **NO IMPLEMENTADO** | El smoke los omite y lo dice |

### 0.3 Pruebas de esta ola

- Backend, batería completa sobre `815b3ee`: 17 381 pruebas, 451 794 aserciones, 632 omitidas (las de PostgreSQL). Un fallo de la línea base (`PricingV2SalesAndPaymentsTest`) y una regresión, corregida y desplegada en `0ee0868` (L9-108). Las 15 guardas estáticas y las pruebas de plantillas, 196 en verde después de la corrección.
- CRM: batería completa sobre `89a24f9`, 4 184 pruebas con los 16 fallos de la línea base; después, el módulo de plantillas entero en cada cambio (552 pruebas en 18 ficheros en `b07eda5`).
- Smokes en producción, en el estado de hoy: H9 30 OK · 0 FALLO · 1 OMITIDO; H8 48 OK · 0 FALLO · 3 OMITIDOS (el del H8, repetido tras E18-8).
- Voz, tras E18-8 y E18-9: las 15 guardas estáticas (138), telefonía 477 (137 omitidas, las de PostgreSQL), voz y telefonía unitarias 366 y laboratorio hablado 42, en verde.
- Baterías completas sobre los commits finales (`6f9d463` y `5f2a454`): backend 17 412 pruebas, 0 regresiones (solo la línea base); CRM 4 184 pruebas, los 16 fallos de la línea base.
- **No probado:** un turno real con una versión PUBLICADA (E3–E5; con los prompts de siempre sí, L9-109); una plantilla enviada a Meta real; una publicación de voz; una llamada; PostgreSQL para los cambios de la tarde del 11-11.
- **Hallazgo aparte, sin tocar:** `UltronStudioVoiceLabSessionsTest::test_la_lista_filtra_y_pagina_sin_consultas_por_fila` es intermitente (1 de 40), igual con el código anterior.

### 0.4 Lo que falta, y de quién depende

**Del propietario (nadie más puede hacerlo):**

1. ~~Un mensaje de WhatsApp desde el teléfono canario~~ **Hecho el 11-11 15:39 UTC** (L9-109): E1 y E2, cerradas.
2. **Dos personas reales con su cuenta.** Con ellas: los vínculos de persona del H9, el veredicto del laboratorio y publicar (E3–E5), aprobar plantillas y aprobar la revisión 3 del perfil de voz.
3. **E3–E5 desde el CRM**, entre las 07:00 y las 19:00: versión nueva con tono «profesional» y 200 caracteres → la aprueba la otra persona → ejecución y veredicto del laboratorio de texto → publicar en canario (quien publica no es quien la escribió) → dos o tres mensajes desde el teléfono canario → revertir.
4. **Revisión 3 del perfil «Sofia Medellin»** con las herramientas de consultar planes y horarios, y una sesión del laboratorio hablado con su propia voz.
5. **Twilio:** elegir subcuenta o clave restringida solo de voz, y autorizar por escrito la primera llamada canaria.
6. **Meta:** confirmar en el Administrador de WhatsApp que la integración puede gestionar plantillas, pulsar «Comprobar permisos» en el CRM y enviar la primera plantilla.
7. **Rotar la clave de ElevenLabs.**
8. Decidir si se limpian los 81 ficheros de basura sin seguimiento de `/var/www/api/backend` y la retención de las copias `env-pre-*` de `/root` (18 el 11-11; cada una lleva todos los secretos, en 600 de root).
9. Renovar la verificación de ElevenLabs antes del 03-12-2026 (y después de rotar la clave).

**Del tiempo:** los siete días de sombra del H9 terminan el 18-11; el modo asistido no se enciende antes.

**Del equipo técnico:** las reglas S-NNN con efecto en WhatsApp, la sombra del H9 evaluando a toda la audiencia, el barrido completo y los disparadores sin emisor (L9-112, en curso), la opción estructural de E18-9 (el agente en el cuerpo de las herramientas) y la batería completa sobre el commit final. Hechas el 11-11: la sesión del laboratorio con las herramientas (L9-111) y la alerta M1-A (L9-112).

### 0.5 Vuelta atrás, de lo último a lo primero

| Qué | Orden |
|---|---|
| Monitor de integraciones | `bash /root/h7-monitor-scope.sh off on` |
| Sede y horario de la voz | `bash /root/h8-voice-scope.sh hechos borrar` (el fichero vuelve idéntico) |
| Plantillas de Meta | `bash /root/meta-templates-scope.sh read full` (deja de enviar) · `off read` · de urgencia, `off full` |
| Llave de prompts | `bash /root/motores-prompts-scope.sh off canary` |
| Motor y publicar | `bash /root/h5-engine-scope.sh off canary` |
| n8n | despublicar `QSklqHlsDIxucRfk` y publicar `YspRwsXnqt33NouP` (`ops/motores/n8n-copia-s1/LEEME.md`) |
| Código | la que imprime `deploy-m1-backend.sh` con el respaldo del día: volver a `728f208` y regenerar cachés; el esquema se queda; nunca `migrate:rollback` |
| CRM | no hace falta volverlo (tolera la ausencia de los campos nuevos) |

### 0.6 Veredicto

**No está «listo para ventas» todavía.** Lo construido está desplegado, encendido hasta donde se puede sin el propietario y probado en automático; faltan las pruebas reales que solo él puede hacer (0.4) y los siete días de sombra. Lo que está incompleto en el código y lo que espera al propietario, por separado, en las tres auditorías de `plans/cierre/` y en L9-112.

---

**Estado del borrador del 10-11 (historia):** BORRADOR DEL LEAD, en curso. Las secciones marcadas «⏳» se completan con las cifras finales de los agentes que siguen trabajando (b9-cierre, pm2-voz, sec-cierre, doc9, test-final, ops8-e) y con la regresión final. Nada de lo que aquí figura como «en el árbol» está en commit ni en producción: el encargo exige la autorización del propietario para commit, push, despliegue, cambios de producción y rotaciones.

Fuentes: la auditoría de los nueve hitos (`ULTRON_STUDIO_AUDITORIA_9_HITOS.md`, fase 1), el estado real de los proveedores (`ULTRON_STUDIO_PROVEEDORES_ESTADO_REAL.md`, sondas GET del 10-11), la activación por etapas (`ULTRON_STUDIO_ACTIVACION_POR_ETAPAS.md`), la preparación para el plan de pago del H8 (`ULTRON_STUDIO_H8_PREPARACION_PLAN_PAGO.md`), los contratos H8 (Enmienda 17) y H9 (L9-55…L9-83) y los informes de `plans/informes-h8/` y `plans/informes-h9/`.

---

## 1. Veredictos ⏳

| Veredicto | Estado | Qué lo sostiene | Qué falta |
|---|---|---|---|
| **READY_FOR_PAID_PLAN** | ⏳ | ⏳ | ⏳ |
| **FULLY_OPERATIONAL** | **NO** | Exige el plan contratado, el laboratorio real, llamadas reales autorizadas, automatizaciones reales validadas, la vigilancia superada (siete días de sombra del H9 como mínimo), los criterios de aceptación de los nueve hitos y la autorización comercial con aprobación independiente | Ver §9: solo acciones del propietario, pruebas reales y tiempo |

---

## 2. Matriz de los nueve hitos ⏳

Método del porcentaje: el de la auditoría (§0.3): **% = 0,4 × K (construcción) + 0,2 × D (despliegue) + 0,4 × O (activación y operación real)**. K y D se recalculan al cierre de esta ola; O no cambia mientras no se active nada.

| Hito | Construcción (K) | Despliegue (D) | Activación (O) | % | Evidencia principal |
|---|---|---|---|---|---|
| 1 · Arquitectura | ⏳ | — | — | ⏳ | |
| 2 · Editor de las diez capas | ⏳ | ⏳ | ⏳ | ⏳ | |
| 3 · Persistencia y motor de control | ⏳ | ⏳ | ⏳ | ⏳ | |
| 4 · Laboratorio de IA (OpenAI) | ⏳ | ⏳ | ⏳ | ⏳ | |
| 5 · Gobierno y versionado | ⏳ | ⏳ | ⏳ | ⏳ | |
| 6 · Integraciones | ⏳ | ⏳ | ⏳ | ⏳ | |
| 7 · Seguridad y monitorización | ⏳ | ⏳ | ⏳ | ⏳ | |
| 8 · Voz y telefonía | ⏳ | ⏳ | ⏳ | ⏳ | |
| 9 · Autonomía comercial | ⏳ | ⏳ | ⏳ | ⏳ | |

---

## 3. Preparación de producción (fase 5): elemento por elemento

Leyenda de la última columna: **(a)** solo tu autorización escrita · **(b)** un dato o una decisión tuya (credencial, persona, elección) · **(c)** depende de verdad del plan de pago de ElevenLabs · **(d)** exige una prueba real (consume o contacta). «Preparado» quiere decir que el mando, el guion o el código ya existen en el repositorio y están probados en banco o en pruebas automáticas.

| # | Elemento | Estado en producción (backend `ce7c150`, CRM `9572426`) | Preparado en el repositorio | Necesita |
|---|---|---|---|---|
| 1 | Mandos del H8 y del H9 | El del H9 (`h9-outreach-scope.sh`) está en `/root` en su versión de la fase 1. Los cuatro del H8 (`h8-voice-scope.sh`, `h8-secrets-register.sh`, `h8-twilio-check.sh`, `el-verificar.sh`) **no** están copiados | Las versiones nuevas: el del H9 cuenta 5 tareas del programador (L9-81); el del H8 con los modos de ops8-e ⏳ | (a) copiarlos con su huella |
| 2 | Respaldos y restauración | Cada despliegue hizo su respaldo completo de la base en `/var/backups/ironbody/` (el último, el de la fase 1 del H9, 10-11) | `ULTRON_STUDIO_ROLLBACK.md` por hito; la restauración está ensayada en el banco de PostgreSQL desechable, nunca ejecutada en producción | (a) antes de cada despliegue, ya incluido en los guiones |
| 3 | Migraciones | Las del H8 (fase 1 + 1b) y las tres del H9 aplicadas | Nuevas de esta ola: `2026_11_13_100001` (V9, `vc_requester_chk`, solo PG) y `2026_11_13_200001` (W9, `ultron_studio_template_meta_checks`) ⏳ las de b9-cierre | (a) despliegue de la ola |
| 4 | Permisos de ficheros | El entorno en 600 de root; los mandos exigen 600 exacto (C-14) | B-T14: ops8-e ⏳ | (a) |
| 5 | Variables de configuración | Las 15 del H9 explícitamente apagadas; ninguna `VOICE_*` encendida; `TWILIO_VOICE_*` ausentes | Variables nuevas, todas apagadas por defecto: `VOICE_CONTACTS_ENABLED`, `COMMERCIAL_AUTOMATION_VOICE_DAILY_CAP`, `…_VOICE_DAILY_BUDGET_USD`, `…_VOICE_RESERVE_USD` (sin ellas en el entorno valen 0) | (a) solo al activar cada etapa |
| 6 | Secretos | La clave de ElevenLabs pasó por el chat (A-2). ElevenLabs custodia el Auth Token **maestro** de la cuenta de Twilio del OTP (A-1). Los dos secretos de Laravel y el webhook post-llamada **no** están registrados en ElevenLabs | `el-verificar.sh`, `h8-secrets-register.sh --solo-comprobar` (solo GET), el mando de rotación del token del OTP de ops8-e ⏳; `security:check-secret-exposure` vigila ya las claves de la voz (L9-82) | (b) clave nueva de ElevenLabs y token nuevo de Twilio, más (a) |
| 7 | Workers | 13 en RUNNING; `ironbody-voice` sin instalar (duerme hasta la 4a); el carril `commercial` no lo necesita (el despacho del H9 va en línea, L9-58) | `docs/ops/supervisor/ironbody-voice.conf`; B-T16: un worker muerto con trabajo reservado ya se ve (L9-82) | (a) antes de la etapa 3 |
| 8 | Programador | `schedule:run` en el crontab de root como `www-data`; el bloque del H9 inerte (bandera apagada) | El bloque del H9 con 5 tareas (L9-81); el de la voz con 5 tareas al encender el monitor (L9-82) | (a) etapa 4 |
| 9 | Webhooks | Meta (WhatsApp actual) y el `usage-trigger` de Twilio funcionando; el post-llamada y la iniciación de ElevenLabs **sin registrar** | `h8-secrets-register.sh`; `voice:webhook-health` (B-T5) los vigila una vez registrados | (a); H-23: si el plan gratuito no admite el registro, pasa a (c) |
| 10 | Monitores | El del H7 apagado (su expectativa por defecto ya no es la de producción, B-T13); el de voz y el del H9 apagados | B-T13 corregido por ops8-e ⏳; `h8-voice-scope.sh expectativa fase2`; `h9-outreach-scope.sh monitor on` | (a) |
| 11 | Límites de gasto | Laboratorio de OpenAI con sus presupuestos del H4; voz: presupuestos y cupos a 0; H9: cupos y techos a 0 | Los mandos los fijan al encender cada etapa (`lab on --presupuesto-dia=…`, `salientes on --cupo=1 --presupuesto=2.00`, `asistido on --cupo=N`, `canal whatsapp on --cupo=N --presupuesto=USD`) | (a) con la cifra que decidas |
| 12 | Perfil de voz | Un perfil aprobado (id 1, revisión 1): español, voz **Lina** (colombiana), trato tú/usted **sin elegir** | La plantilla v3 con las negativas exactas (P-M2) ⏳ | (b) D-6: el trato y los textos del aviso de IA |
| 13 | Versiones aprobadas | La aprobación a cuatro ojos nunca se ha ejercido en producción | Todo el flujo probado en E2E con dos identidades | (b) una segunda persona real con cuenta propia |
| 14 | Configuración de Twilio | Cuenta raíz del OTP (`AC…2dca`): 2 caller IDs (…5483 y …6026), **0 números propios** (las entrantes son imposibles), 0 API keys | `h8-voice-scope.sh fase 2 secreto|set` y `h8-twilio-check.sh` | (b) D-3 (subcuenta b′ recomendada) y las claves restringidas solo de Voz, más (a) |
| 15 | Canarios de prueba | Ningún canario del H6 activo; el canario de voz del propietario sin registrar (D-13) | `h6-integrations-scope.sh` y CRM → Integraciones → canarios | (a) + (b) la segunda persona que autoriza |
| 16 | Verificación de integraciones | La sonda y el T1 del H6 sin hacer (fase 2 del H6) | `smoke-run-h6`, la sonda del Studio | (a) + (b) |
| 17 | Procedimiento de despliegue | Los guiones de la fase 1b del H8 y de la fase 1 del H9 se ejecutaron (E16-30, L9-76) | La vía del commit `voice(calls-open)` (B-T1, ops8-e ⏳) y el guion de esta ola (G-9) ⏳ | (a) |
| 18 | Smoke tests | Smokes del H8 y del H9 de la fase 1 en verde (el del H8 con un FALLO falso conocido, B-T7) | B-T7 corregido por ops8-e ⏳; el smoke del H9 espera 5 tareas en la fase 2 (L9-81) | — |
| 19 | Reversión completa | Documentada por hito y por etapa | `ULTRON_STUDIO_ROLLBACK.md`, la vuelta atrás de cada etapa en la activación, `h9-outreach-scope.sh apagar` / `parada-global`, `h8-voice-scope.sh … off` | — |

**Lo único que depende de verdad del pago** (auditoría §4.3, sin cambios): la sesión real del laboratorio hablado y su veredicto, la publicación canaria de la voz y su smoke 4a, la primera saliente y la promoción, y por arrastre la fase 4b y la voz por estrategia (V9) en real.

---

## 4. Activación por etapas (fase 6)

El orden, los mandos exactos y la vuelta atrás de cada etapa están en `ULTRON_STUDIO_ACTIVACION_POR_ETAPAS.md` (etapas 0 a 7) y, para el H9, en el runbook de doc9 ⏳. Los controles que deben seguir apagados cuando no se usan (parada global, exclusiones, cupos y presupuestos, cuatro ojos, ventana horaria) no se tocan para aparentar completitud.

**Matriz de entrega frente al 15-11:** la sombra del H9 exige siete días desde que se enciende. Encendida el 11-11 terminaría el 18-11, después del 15-11. Por eso se entrega distinguiendo **implementación terminada** (lo que este cierre deja en el árbol y, con tu autorización, en producción) de **certificación operativa pendiente** (lo que exige el plan, pruebas reales o tiempo).

---

## 5. Pruebas físicas (fase 7) ⏳

| # | Qué demuestra | Se puede hacer hoy sin coste | Necesita | Procedimiento | Estado |
|---|---|---|---|---|---|
| 1 | Las diez capas se configuran y guardan | Sí | Tu sesión en el CRM | `ULTRON_STUDIO_PRUEBAS_FISICAS.md` §1 y §2 | ⏳ |
| 2 | Las versiones se revisan y aprueban | Sí, con dos personas | (b) la segunda persona | §4.1 | ⏳ |
| 3 | El laboratorio de OpenAI funciona | Consume créditos de OpenAI | (a) autorizar el consumo | §3B | ⏳ |
| 4 | El perfil de voz se compila | Sí (la compilación es local y el perfil ya está aprobado) | — | ⏳ | ⏳ |
| 5 | El laboratorio hablado conversa en español colombiano | No | (c) plan o tu excepción escrita D-4, más (d) | Preparación del plan, 2a.7 | Pendiente del plan |
| 6 | ElevenLabs recibe las instrucciones aprobadas | No (la publicación canaria exige el veredicto del laboratorio) | (c) + (a) | 4a | Pendiente del plan |
| 7 | Twilio origina una llamada autorizada desde el CRM | No | (c) + (d) ≈ 0,97 US$ más minutos | 4a.15 | Pendiente del plan y de tu autorización |
| 8 | El resultado regresa a PostgreSQL | No (exige la llamada real) | (d) | 4a.16 | Pendiente |
| 9 | El CRM muestra costes, resultados y auditoría | En parte (costes del laboratorio de OpenAI; el H9 en sombra) | (a) para la sombra | ⏳ | ⏳ |
| 10 | El H9 detecta automáticamente una oportunidad | Sí, en sombra (sin contactos) | (a) etapa 4 + (b) personas vinculadas y la segunda persona que aprueba | Activación 4.1–4.4 | ⏳ |
| 11 | El motor comprueba consentimiento y reglas | Sí, en sombra (la línea de tiempo enseña la escalera N1–N6) | Igual que 10 | Igual que 10 | ⏳ |
| 12 | El sistema planifica una acción sin intervención manual por contacto | Sí, en sombra (trabajos simulados) | Igual que 10 | Igual que 10 | ⏳ |
| 13 | W9 o V9 ejecuta únicamente acciones autorizadas | Probado en automático (suites W9 y V9 y la vía abierta en una copia); en real, no | (a) los commits `outreach(sends-open)` y, para la voz, `voice(calls-open)`, más plantillas aprobadas por Meta, consentimientos, visto bueno legal y (d) | Etapa 6 | ⏳ |
| 14 | WhatsApp y las llamadas no se duplican | Probado en automático (un trabajo, un mensaje; una llamada viva por número; idempotencia de la puerta) | (d) para la prueba real | ⏳ | ⏳ |
| 15 | La parada global bloquea nuevos despachos | Sí, en sombra | (a) etapa 4 | `h9-outreach-scope.sh parada-global --motivo=…` | ⏳ |
| 16 | Los fallos externos no producen contactos repetidos | Probado en automático (ambiguos sin reintento; reserva liberada solo cuando se sabe que no salió) | (d) para fallos reales del proveedor | ⏳ | ⏳ |
| 17 | Todas las operaciones críticas pueden auditarse y revertirse | En parte (libros del Studio, decisiones del H9, eventos de voz; mandos de vuelta atrás) | (a) para ejercitar la reversión en producción | `ULTRON_STUDIO_ROLLBACK.md` | ⏳ |

---

## 6. Commits y revisiones desplegadas ⏳

- Producción: backend `ce7c150`, CRM `9572426` (10-11-2026, 20:21–20:27 UTC; L9-76, L9-77). `origin/main` = backend `d701b0c` (solo documentación encima de `ce7c150`) y CRM `9572426`.
- Esta ola, en el árbol de `feat/ultron-studio-h9` sin commit: ⏳ (lista de ficheros y commits propuestos).

## 7. Resultados de pruebas ⏳

## 8. Configuraciones pendientes ⏳

## 9. Lo único que falta, y de quién depende ⏳

## 10. Costes previstos

| Concepto | Cifra conocida | Fuente |
|---|---|---|
| Plan de ElevenLabs con minutos de agente | Por confirmar por ti (hoy plan gratuito: 10 000 caracteres, 5 694 usados, sin minutos de agente) | `PROVEEDORES_ESTADO_REAL` §1 |
| Llamada saliente de prueba a un móvil | ≈ 0,97 US$ reservados por llamada con minutos (≈ 0,61 US$ sin ellos); presupuesto inicial propuesto 2,00 US$ al día con cupo 1 | `H8_PREPARACION_PLAN_PAGO` 4a.9 y §final |
| WhatsApp por plantillas | Meta cobra cada mensaje entregado según su categoría (marketing o utilidad); las tarifas de Colombia están **por confirmar**; sin precio configurado el H9 niega por `budget_zero` | Contrato H9, §W9 |
| Laboratorio de OpenAI | Con los presupuestos diarios del H4; falta cotejar el coste real con el panel de OpenAI (A-2 del H4) | Auditoría §2, H4 |
| Twilio | Saldo actual de la cuenta raíz: 21,80 US$; subcuenta de voz (b′) sin coste | `PROVEEDORES_ESTADO_REAL` §2 |

## 11. Riesgos ⏳

## 12. Procedimiento de activación y de reversión

- Activación: `ULTRON_STUDIO_ACTIVACION_POR_ETAPAS.md` y el runbook del H9 ⏳.
- Reversión: `ULTRON_STUDIO_ROLLBACK.md`; por etapa, la vuelta atrás de cada fila de la activación; la parada global (`outreach:stop --all`) no toca el entorno.

## 13. Estado final exacto y porcentaje sustentado ⏳

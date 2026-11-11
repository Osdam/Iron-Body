# ULTRON STUDIO · Hito 9 · Matriz de completitud (DOC9, 11-11-2026)

**Para quién:** el propietario y el lead, para decidir qué se activa y qué falta. **Qué es:** el estado real del Hito 9 (autonomía comercial: estrategias, sombra, asistido y canales automáticos), pieza a pieza, separando siempre la **implementación** (¿está construido y probado?) de la **certificación operativa** (¿qué falta para que funcione de verdad en producción?). El procedimiento para operar las etapas 4 a 7 está en `ops/RUNBOOK_H9.md`; el orden de activación de todo el Studio, en `ULTRON_STUDIO_ACTIVACION_POR_ETAPAS.md`.

**Veredicto al escribirla:** el hito **no está cerrado**. No es `HITO_9_COMPLETED`, porque el contrato exige además PERF9 y DOC9 confirmados y la sombra y el asistido ejecutados con su evidencia (`ULTRON_STUDIO_H9_CONTRACT.md` §15). Tampoco es `HITO_9_IMPLEMENTED_WITH_EXTERNAL_DEPENDENCY`: además de las dependencias externas faltan piezas propias (§4), como el defecto que impide abrir en el CRM una estrategia recién creada (H9-E2E-1), PERF9 y m-9. **La fase 1 (todo apagado) está en producción** desde el 10-11-2026, entre las 20:21 y las 20:27 UTC (backend `ce7c150`, CRM `9572426`; L9-76, L9-77). **Nada del H9 ha contactado a nadie.**

**Fuentes, por orden de autoridad:**
1. El código de la rama `feat/ultron-studio-h9`: HEAD `d701b0c8` (= `ce7c150` en código) más el trabajo sin commit del árbol, leído el 11-11-2026 entre las 00:00 y las 01:00 UTC.
2. El contrato del H9: D9-\*, P9-\*, L9-1 a L9-83.
3. La Enmienda 17 del contrato del H8.
4. `ULTRON_STUDIO_H9_ACEPTACION.md` (`A9`), `ULTRON_STUDIO_ACTIVACION_POR_ETAPAS.md`, `ULTRON_STUDIO_PROVEEDORES_ESTADO_REAL.md` (`PROV`) y `ULTRON_STUDIO_AUDITORIA_9_HITOS.md`.
5. Los informes de `plans/informes-h9/` y `plans/informes-h8/`.

**Producción no se leyó para escribir esto.** Los datos de producción son los del registro del lead (L9-76 y L9-77) y van citados.

**Cómo leer las columnas:**
- **Implementación:**
  - **Terminada · en producción**.
  - **Terminada · en el árbol** (sin commit ni despliegue).
  - **⏳ En curso**: otro agente la está haciendo ahora; queda **PENDIENTE DE CIERRE DEL LEAD**.
  - **Pendiente de desarrollo**.
- **Certificación operativa pendiente:** las letras de la activación por etapas.
  - **(a)** tu autorización escrita.
  - **(b)** un dato o una decisión tuya.
  - **(c)** el plan de pago de ElevenLabs.
  - **(d)** una prueba real, que consume o contacta.
  - **tiempo:** los 7 días de sombra.
  - **—** si no falta nada.

---

## 1. Fase 1 · todo apagado (EN PRODUCCIÓN)

| Pieza | Implementación | Certificación operativa pendiente | Evidencia |
|---|---|---|---|
| **Esquema:** 15 tablas, 7 de gobierno (`2026_11_12_100001`: `ultron_studio_strategies`, `…_strategy_versions`, `…_strategy_reviews`, `…_strategy_simulations`, `…_message_templates`, `…_strategy_publications`, `…_person_bindings`) y 8 de ejecución (`…_100002`: `outreach_runs`, `…_enrollments`, `…_decisions`, `…_jobs`, `…_outcomes`, `…_brakes`, `…_budget_counters`, `…_cursors`), más 4 índices de lectura (`…_100003`). Con CHECK, disparadores de solo inserción y privilegios de `iron_app` | Terminada · en producción | — | L9-76: esquema `123 137 9 25 51 75 3 8`, 33 funciones sin SECURITY DEFINER, 15 tablas vacías y privilegios comprobados uno a uno. *`A9:41` dice «14 tablas»: son 15 (auditoría §6, punto 5)* |
| **Núcleo puro:** 8 disparadores, condiciones con lógica de tres valores, escalera N1–N7, selección de canal y trazas explicables | Terminada · en producción | Se usa al encender el gobierno | 962 pruebas y 803 casos de fixtures (`outreach-catalog/2026-11-10.2`) con paridad byte a byte con el CRM (`A9:42`) |
| **Hechos de solo lectura** (`Facts/**`) | Terminada · en producción | **(b) P9-24:** con `COMMERCIAL_EVENTS_ENABLED=false`, como hoy en producción, N6 niega a todas las personas (L9-26, L9-54) | `A9:43` |
| **Gobierno:** 30 rutas `strategies/**`, cuatro ojos por persona verificada, versiones, simulaciones, cadena firmada, canario, promoción con evidencia, pausa, reversión y vínculos | Terminada · en producción, **apagada**: `ULTRON_STUDIO_STRATEGIES_ENABLED=false`, así que las 30 rutas responden 409 `strategies_disabled`, también las lecturas (`config/ultron_studio.php:296-310`) | **(a)** `gobierno on` y `publicar on` · **(b)** una segunda persona real y las dos primeras vinculadas por consola (P9-4, P9-11) · **H9-E2E-1** sin corregir (§4) | S9: 32 pruebas (`A9:44`); L9-76 |
| **Ejecución:** planificador, despachador, frenos y PARADA GLOBAL, conciliación, bandeja asistida, 13 rutas `outreach/**` y 7 órdenes `outreach:*` | Terminada · en producción, **apagada**: con `COMMERCIAL_AUTOMATION_ENABLED=false`, 12 de las 13 rutas responden 409 `outreach_disabled`; `GET outreach/status` responde siempre 200 (L9-47, L9-68) | **(a)** `sombra on` · las condiciones de REV9 para la fase 2 (§4: TEST9-b, PERF9, m-9, H-1) · **tiempo** | `Run`: 69/69 en SQLite y en PostgreSQL; carrera #13 con dos procesos reales (`A9:29-32`, L9-74) |
| **Canal en vivo (SSE)** con 11 temas | Terminada · en producción, **apagada** (`ULTRON_STUDIO_STREAM_ENABLED=false`: el CRM consulta periódicamente, `config/ultron_studio_stream.php:41`) | **(a)** `stream on`; recomendable con el vale de un solo uso ya desplegado (P9-25, §2) | 32/32 en SQLite y en PostgreSQL (`A9:46`) |
| **CRM «Campañas automáticas (estrategias)»** (`/marketing/automatizaciones`): Panel, Estrategias, Constructor, Simulación, Plantillas, Gobierno, Bandeja asistida, Historial por persona y Vínculos de personas | Terminada · en producción (CRM `9572426`) | **E2E #26 con la pila real:** ⏳ en curso (§3) | FE9 440/440; 491 specs con las llaves (L9-61, L9-75) |
| **Permisos:** 10 llaves nuevas (121 en el catálogo); `commercial_automation` fuera del token compartido; `auth:coverage --unmapped` vacío | Terminada · en producción | **(b)** a quién se reparte cada llave (§6.2). Por defecto, el Administrador no tiene `ultron.strategies_publish`, `ultron.person_bindings_manage`, `commercial_automation.simulate_real` ni `commercial_automation.release` (`app/Support/Access/CrmPermission.php:322-331` en `ce7c150`) | L9-59, L9-75 |
| **Programador:** bloque propio bajo `COMMERCIAL_AUTOMATION_SCHEDULER_ENABLED`, con 3 tareas en el código desplegado (`routes/console.php:484-513` en `ce7c150`) | Terminada · en producción, sin tareas programadas | **(a)** lo enciende `sombra on` | L9-76: programador `39 0 0 0` |
| **Constante de envío:** `OutreachSendGate::SENDS_ENABLED_IN_CODE = false` (`:35` en `ce7c150`). El guion de la fase 1 aborta si viaja un commit `outreach(sends-open):` (`ops/deploy-h9-backend-fase1.sh:400`) | Terminada · en producción | **(a)** un commit de una línea `outreach(sends-open):` y el visto bueno legal (etapa 6). **No existe todavía la vía para desplegarlo** (§4) | `A9:20` |
| **Mando `ops/h9-outreach-scope.sh`** | Terminada; ensayado en el banco (L9-71). **Nunca ha escrito nada en producción** | **(a)** por orden; copiar a `/root` la versión que corresponda al código desplegado (`ops/RUNBOOK_H9.md` §2.1) | `informe-devops.md` §3, §11 |
| **Supresión de la Ley 1581 del H9** (sección del H9 de `ops/supresion-1581.sql`, `outreach:suppress-subject`) y runbook de rotación de la `APP_KEY` (`ops/ROTACION_APP_KEY_H9.md`) | Terminada; ensayada en el banco. **Nunca ejecutada en producción** | **(a)** cuando llegue una solicitud. Rotar la `APP_KEY` solo antes de la primera publicación de una estrategia | L9-67 |
| **Despliegue, smoke y vigilancia** | Hechos: respaldo `ultron-studio-h9-fase1-20261110-202129`; smoke **37 OK · 0 FALLO · 1 OMITIDO** (los presupuestos de PERF9); vigilancia de 15 minutos sin incidencias | **La lectura a las 24 h** (11-11, hacia las 20:27 UTC), de solo lectura: **no consta** | L9-76, L9-77 |
| **Revisión independiente (REV9)** | Aprobado con condiciones, **0 bloqueantes** | Sus condiciones de la fase 2 (§4) | L9-70, L9-73 |
| **Regresión completa** | 15 787 pruebas; 56 rojos, los mismos 56 de la línea base, 0 nuevos | — | L9-76 |

---

## 2. Terminado en el árbol, pendiente de autorización para desplegarse

Está en la rama `feat/ultron-studio-h9`, **sin commit**, y no está en producción.

**Ni W9 ni V9 pueden contactar a nadie desde el árbol:**
- las dos constantes siguen en `false`: `OutreachSendGate::SENDS_ENABLED_IN_CODE` (`:38` en el árbol) y `VoiceCallPolicy::CALLS_ENABLED_IN_CODE`;
- los cupos y presupuestos siguen a 0.

| Pieza | Implementación | Certificación operativa pendiente | Evidencia |
|---|---|---|---|
| **W9 · WhatsApp por plantillas.** <ul><li>`WhatsappTemplateChannel`, con 8 cerrojos propios antes de la puerta.</li><li>`MarketingMessageDispatcher::dispatchWhatsappTemplate()` (+168/−0).</li><li>La rama de plantillas del outbox (+41/−2).</li><li>El normalizador `outreach:whatsapp-outcomes`: estados de Meta, respuesta → `replied` y 27 frases de baja → `opted_out`.</li><li>La tabla `ultron_studio_template_meta_checks` (migración `2026_11_13_200001`, la tabla 16 del H9): sin una comprobación aprobada, el envío falla con `template_not_approved_by_meta`.</li><li>`whatsapp_template` en `LIVE_CHANNELS`.</li></ul>Sin variables, rutas ni permisos nuevos | Terminada · en el árbol | <ul><li>**(a)** commit y despliegue (§2.1).</li><li>⏳ **Pendiente de cierre del lead:** la orden que registra lo que aprobó Meta (§3). Hasta entonces, el canal falla cerrado.</li><li>**(b)** P9-13, P9-15 y P9-22.</li><li>Las plantillas aprobadas por Meta.</li><li>**(d)** el primer mensaje a un canario (etapa 6).</li></ul>**Limitación aceptada:** una estrategia de WhatsApp de varios pasos solo avanza si la persona responde o si el envío falla. El `no_answer` por tiempo no se hace en este cierre (gancho 3 de W9, L9-80) | W9: 37/37 en SQLite y en PostgreSQL; regresión dirigida de Legacy 328/328 (`informe-w9.md` §0). La regresión completa de Marketing **no se corrió** (`informe-w9.md` §9) |
| **V9 · Voz por estrategia (Enmienda 17 del H8).** <ul><li>`OutboundGate::requestForStrategy()`: solo añade código (+270/−0); `request()` y `decide()` quedan byte a byte.</li><li>La migración `2026_11_13_100001`: `vc_requester_chk` admite `strategy`. Solo afecta a PostgreSQL, y `down()` se niega si hay filas de estrategia.</li><li>`VoiceStrategyChannel`, `VoiceCallOutcomes` con `outreach:voice-outcomes`, y `VoiceCallStates` (solo lectura).</li><li>`voice_call` declarada en `LIVE_CHANNELS`.</li></ul> | Terminada · en el árbol | <ul><li>**Revisión de seguridad de la enmienda:** ⏳ en curso (sec-cierre).</li><li>⏳ **Pendiente de cierre del lead:** cupo, techo y reserva de voz del H9 (§3). Sin ellos, la voz queda en `budget_zero`.</li><li>**(c) + (d):** las etapas 1 a 3 del H8, es decir, la 4a con evidencia y la 4b (P9-16).</li><li>**(b)** D-V9-1: solo la finalidad comercial tiene consentimiento de voz.</li><li>**(a)** `canal voz on`.</li></ul> | V9: 71 pruebas en SQLite y en PostgreSQL; la vía abierta, 25/25 en una copia (`v9-abierta.sh`); verificación del lead: 255 pruebas en SQLite y 196 en PostgreSQL, con 0 fallos al final (L9-81) |
| **Programador con 5 tareas:** `outreach:whatsapp-outcomes` cada minuto y `outreach:voice-outcomes` cada 5 minutos, antes del despacho (`routes/console.php:495-539`). El mando y `smoke-run-h9.sh` pasan a esperar 5 | Terminada · en el árbol | Desplegar el código y el mando nuevo **juntos**: el mando del árbol sobre el código de `ce7c150` daría un desvío del programador (`ops/RUNBOOK_H9.md` §2.1) | L9-81 (G-7) |
| **P9-25 · vale de la URL** (R-1 de SEC9): un solo uso, ≤ 60 s y ligado a su alcance (el del canal no abre `face-image` ni al revés); el CRM pide un vale por conexión y por foto; el limitador `studio-stream` pasa a contar por persona | Terminada · en el árbol (backend y CRM) | **(a)** desplegar **primero el CRM y después el backend**, y recargar las pestañas de recepción y del punto facial entre los dos pasos (`informe-p9-25.md` §4). No verificado: la caché en PostgreSQL, un navegador real y nginx real | 83/83 (L9-78) |
| **Panel «Estado de activación» del CRM** (L9-79): 6 funciones de voz y 7 de campañas automáticas, con la taxonomía de seis estados. Incluye B-T8 (texto de la constante) y B-T15 (`verified` por entrada) | Terminada · en el árbol del CRM (sin commit) | **(a)** despliegue del CRM. *Sus specs las estaba verificando el lead* | `tsc` 0 (`informe-frontend-estados.md`) |
| **Endurecimiento del H8** (L9-82, E17-4): <ul><li>B-T4: `VOICE_CONTACTS_ENABLED`, apagada.</li><li>B-T17: `security:check-secret-exposure` vigila las claves de voz.</li><li>B-T5: `voice:webhook-health`; el monitor de voz pasa a 5 tareas.</li><li>B-T16: los trabajos reservados de un worker muerto se ven.</li></ul> | Terminada · en el árbol | **Visto bueno de seguridad** de las dos guardas de SEC8 ampliadas: ⏳ sec-cierre. Si lo niega, se revierte B-T5 · **(a)** | `informe-backend-h8e.md` §6 |
| **Supresión de la Ley 1581 de la voz** (L9-83, E17-5): `ops/supresion-1581.sql` cubre las 11 tablas de voz | Terminada · en el árbol; **nunca ejecutada en producción** | **(a)** por solicitud. La propuesta H-A (`vct_source_chk`) está sin aplicar; la decide el lead con `architect` | PostgreSQL 14: 1 prueba, 1 119 aserciones |
| **Línea base de pruebas** (tester final): de 119 entradas a 1 | Terminada · en el árbol | Queda un defecto real de precios para el dueño de pagos (`PricingV2SalesAndPaymentsTest`); no es del H9 | `informe-tester-final.md` §A |

### 2.1 Lo que hoy impide desplegar lo del árbol (comprobado)

1. **No hay guion para hacerlo:**
   - `ops/deploy-h9-backend-fase1.sh` tiene `PREVIO=a608450` (`:88`), y producción está en `ce7c150`.
   - Su puerta de diff exige cero cambios en `app/Services/Marketing`, `Voice`, `Telephony` y `config/voice.php` (`:104-108`). W9 toca Marketing; V9 y L9-82 tocan Telephony y `config/voice.php`.
   - El gancho G-9 de V9 lo asigna a OPS (L9-81). Es un caso parecido a B-T1 del H8 antes de OPS8-e.
2. **Los guiones del H9 no conocen la tabla 16** (`ultron_studio_template_meta_checks`): no la nombran ni `h9-schema-check.sql`, ni el paso 4 del despliegue, ni `smoke-h9.py`. El lanzador del smoke cuenta las tablas con `LIKE`, pero su mensaje dice «33 tablas (25 del Studio y 8 de outreach)» (`ops/smoke-run-h9.sh:285`).
3. **No constan:**
   - una revisión independiente (REV) de W9, V9, P9-25, L9-79, L9-82 y L9-83;
   - una regresión completa del árbol después de W9 y V9;
   - la revisión de seguridad de la Enmienda 17 (en curso).
4. **Falta tu orden escrita** de commit, push y despliegue.

---

## 3. ⏳ En curso: PENDIENTE DE CIERRE DEL LEAD

Lo están haciendo otros agentes mientras se escribe esto. **No se documenta como hecho.** Su descripción y sus valores definitivos los fija el lead al cerrarlo.

| ⏳ | Qué | Quién | Por qué importa |
|---|---|---|---|
| ⏳ **PENDIENTE DE CIERRE DEL LEAD** | Las variables `COMMERCIAL_AUTOMATION_VOICE_DAILY_CAP`, `COMMERCIAL_AUTOMATION_VOICE_DAILY_BUDGET_USD` y `COMMERCIAL_AUTOMATION_VOICE_RESERVE_USD`, **apagadas** (ganchos G-3 a G-6 de V9) | b9-cierre | Sin ellas, la voz por estrategia queda en `budget_zero` (`informe-v9.md` §4). El mando no las escribe todavía (§4) |
| ⏳ **PENDIENTE DE CIERRE DEL LEAD** | Una orden de consola que registre lo que aprobó Meta en `ultron_studio_template_meta_checks`. **Nombre por confirmar** (gancho 4 de W9) | b9-cierre | Sin una fila aprobada, todo envío de W9 falla con `template_not_approved_by_meta` |
| ⏳ **PENDIENTE DE CIERRE DEL LEAD** | La espera ante una pausa que llegue entre la escalera y la puerta (gancho 1 de W9). Hoy esa carrera termina en `failed`, que es el lado seguro, pero la persona pierde el ciclo | b9-cierre | — |
| ⏳ **PENDIENTE DE CIERRE DEL LEAD** | Los textos de las decisiones (los códigos nuevos de W9 no tienen frase en el CRM, gancho 6), **m-9** (la decisión `cancel` en la línea de tiempo) y **H-1** (el reloj del monitor) | b9-cierre | m-9 y H-1 son condiciones de REV9 para la fase 2 (L9-73) |
| ⏳ **PENDIENTE DE CIERRE DEL LEAD** | La plantilla de voz `ultron-voice-template/3` con negativas exactas (P-M2, E17-6) | pm2-voz | Sin ella, la guarda de la transcripción frena con 13 de 15 negativas naturales (B-T2). Lo arrastra V9 |
| ⏳ en curso | **E2E #26** (`ops/h9-e2e/`). La última vuelta con evidencia (11-11, 00:30 UTC, sobre `d701b0c8` y CRM `9572426`) dio **194 comprobaciones: 182 OK y 12 FALLO**: <ul><li>9 por H9-E2E-1;</li><li>1 por H9-E2E-2;</li><li>1 «a quien abrió la parada no se le ofrece soltarla (el botón no aparece)», cuya causa aún no está documentada;</li><li>1 por el código de salida del guion.</li></ul>El informe del tester no está cerrado (`informe-tester-final.md` §B.4) | tester | Es TEST9-b, condición de la fase 2 |
| ⏳ en curso | La revisión de seguridad de la Enmienda 17 y de las guardas de SEC8 ampliadas | sec-cierre | Condiciona desplegar V9 y L9-82 |
| ⏳ en curso | Los guiones del H8 de OPS8-e: modo `--fase=4a` del despliegue de la voz (B-T1) y expectativa del monitor del H7 por etapa (B-T13). Ensayados en el banco y **sin ejecutar** | devops | V9 arrastra la 4a del H8 |

---

## 4. Pendiente de desarrollo (no lo hace nadie todavía)

| Pieza | Por qué falta (comprobado) | Bloquea | Dueño propuesto |
|---|---|---|---|
| **H9-E2E-1 · BLOQUEANTE** | <ul><li>Crear una estrategia desde el CRM deja un borrador vacío que el CRM no puede abrir.</li><li>`StudioStrategies::draftDto()` decodifica el `'{}'` guardado como un array PHP y responde `"content": []` (`app/Services/UltronStudio/StudioStrategies.php:274`, `:291-295`).</li><li>El CRM exige un objeto o `null` (CRM `commercial-automation-parse.ts:406`).</li><li>El código está desplegado y dormido. Ninguno de los dos repositorios está corregido.</li></ul> | Etapa 4.3: crear la primera estrategia desde el CRM | S9 (`backend`, lo recomendado) o FE9 |
| **H9-E2E-2** (medio) | La simulación sintética de una persona sin eventos niega en N6 con una explicación falsa: dice «la persona pidió hablar con alguien». No impide aprobar, pero engaña a quien revisa | La revisión de la simulación | FE9 + B9-run |
| **TEST9-b · `exercise()`** | `OutreachIsolationTest::exercise()` no recorre las 13 rutas de la ejecución ni las 5 órdenes: el fichero no contiene ninguna ruta `outreach/` | Fase 2 (L9-73) | `tester` |
| **PERF9** | Los presupuestos de rendimiento de D9-16 no se han medido: `ops/perf-h9/` no existe y el smoke los deja OMITIDOS | Fase 2 (L9-73) | `performance-specialist` |
| **Mando: `apagar`** | Escribe 7 claves y deja los cupos, el presupuesto de WhatsApp y el clasificador como estén (`ops/h9-outreach-scope.sh:348-352`). Contradice L9-52, Q-7: «`apagar` también pone los topes a 0 y apaga el clasificador» | La vuelta atrás de nivel 0 completa | `devops` |
| **Mando: horario de los sábados** | `horario set` solo admite una franja `HH:MM-HH:MM` (`:202-207`, `:453-456`), que vale para todos los días y se cruza con la ley. La configuración admite un tramo propio para los sábados con `;` (`app/Services/Outreach/Run/OutreachConfig.php:129-149` en `ce7c150`). El horario que recomienda P9-17 (sábados de 09:00 a 13:00) no se puede escribir con el mando | P9-17 tal como está recomendado | `devops` |
| **Mando: cupos de voz del H9** | `canal voz on` no lleva opciones (`:419`) y el mando no escribe las variables de §3 | Etapa 6.4 | `devops`, tras b9-cierre |
| **Procedimiento para P9-24** (`COMMERCIAL_EVENTS_ENABLED`) | Ningún guion de `ops/` lo escribe, y el mando del H9 deja fuera el motor comercial a propósito (`:62-63`). La activación por etapas cita un «mando del motor comercial» que no existe | Etapa 4.1 | `devops`. Antes: leer qué escribe en `commercial_events` y cuánto cuesta |
| **Vía de despliegue** para lo del árbol (G-9) y para el commit `outreach(sends-open):` | El guion de la fase 1 los rechaza a propósito (§2.1; `:400`). No existe un modo equivalente al `--fase=4a` del H8 | §2 y la etapa 6 | `devops` + `security` |
| **Rederivar los seudónimos tras rotar la `APP_KEY`** (`outreach:rederive-refs`) | Rotar la clave con estrategias publicadas deja la cadena en `chain_unreadable` sin vuelta atrás por software (`ops/ROTACION_APP_KEY_H9.md` §0) | Solo si hay que rotar | B9-run |
| **`no_answer` por tiempo en WhatsApp** (gancho 3 de W9) | Cambia la tabla de resultados de D9-10 y su CHECK en la base: es una enmienda propuesta | Las estrategias de WhatsApp de varios pasos | Propietario + `architect` |
| **`unknown_send` del monitor y evidencia real del canario de W9** | Fuera de este cierre (L9-80, gancho 5) | Vigilar los envíos sin trabajo | B9-run |
| **`purposeKey` en el agente de voz** (D-V9-1) | Compilar una variable nueva cambia la plantilla, el compilador, 882 casos de fixtures y el CRM | Voz por estrategia con finalidad distinta de la comercial | S8 + B8 + TEST8 + FE8 |

**Menores, sin efecto en la operación:**
- La cabecera de `OutreachSendGate.php:24-29` aún dice que la puerta de la voz está vacía, frente a `:41-45`.
- El comentario de `ops/h9-outreach-scope.sh:621-622` dice 3 tareas, frente a `TAREAS_PROGRAMADOR=5` (`:90`).
- El texto de la llave `ultron.strategies_publish` dice «quien aprueba no publica» (`PermissionCatalog.php:257`), pero el código, como el contrato (D9-11.2), solo impide publicar a la **autora**: quien aprueba sí puede publicar (`StudioStrategyPublishing.php:337-346`).

---

## 5. Qué exige cada etapa de activación

Numeración de `ULTRON_STUDIO_ACTIVACION_POR_ETAPAS.md`. Las etapas 0 a 3 son del H8: V9 las arrastra.

### Etapa 4 · sombra (≥ 7 días, P9-11). Ni un contacto

| Paso | Qué exige | Bloqueos propios hoy |
|---|---|---|
| 4.0 | Que la fase 1 lleve 24 h estable, con su lectura de solo lectura | La lectura no consta |
| 4.1 | **(a) + (b) P9-24** (`COMMERCIAL_EVENTS_ENABLED`) · **(a) + (b)** las dos primeras personas vinculadas por consola: dos cuentas activas y dos identidades distintas (P9-4, P9-11) | Sin procedimiento para P9-24 (§4) |
| 4.2 | **(a)** `gobierno on`, `publicar on`, ventana y mínimos del canario · **(b) P9-17** (horario propio) y **P9-18** (topes) | El horario de los sábados no se puede escribir con el mando (§4) |
| 4.3 | **(b)** la primera estrategia (por ejemplo, vencimientos) y sus plantillas, con dos personas: una crea y simula, otra aprueba | **H9-E2E-1** |
| 4.4 | **(a)** `sombra on` (enciende también el programador y el monitor) y, si se quiere, `stream on` | Las condiciones de REV9 (L9-73): TEST9-b, PERF9, m-9 y H-1 |
| 4.5 | **tiempo:** 7 días mirando decisiones y volúmenes. **En sombra no se acumula evidencia del canario:** solo cuentan los trabajos `started` en vivo que no son simulados (`app/Services/UltronStudio/StudioStrategyEvidence.php:112-115`, L9-40). La promoción llega en la etapa 5 | — |

### Etapa 5 · asistido

| Paso | Qué exige | Bloqueos propios hoy |
|---|---|---|
| 5.1 | **(a)** `asistido on --cupo=N` · **(b) P9-12**: quién trabaja la bandeja (`commercial_automation.assisted_work`) y cuántos trabajos al día · que la sombra haya salido sin hallazgos | — |
| 5.2 | **(d)** el personal contacta a personas reales y registra el resultado. **Con la estrategia en canario y en vivo, solo se alcanza a personas con un número canario ACTIVO del registro del H6 para el medio del paso**, WhatsApp o voz (D9-11.6; `LadderInputBuilder.php:106` en `ce7c150`, `PolicyLadder.php:428-429`). Eso exige la **fase 2 del H6** (A-5: dos personas para el T1 y escrituras encendidas) | La fase 2 del H6 no está hecha |
| 5.3 | **Promoción a `PUBLISHED`** por una persona distinta de la autora, en la ventana. Evidencia mínima: ≥ 24 h, ≥ 3 contactos que salieron en vivo, 0 negativas de N1 detectadas después, 0 `ambiguous` y *opt-out* ≤ 5 % | — |

### Etapa 6 · canales automáticos

| Paso | Qué exige | Bloqueos propios hoy |
|---|---|---|
| 6.0 | **(a)** desplegar W9 y V9 (§2) | Sin vía de despliegue (§2.1) |
| 6.1 | **(b)** plantillas aprobadas por Meta (P9-13) y su registro en `ultron_studio_template_meta_checks` · consentimientos por finalidad y canal (C9; P9-14, P9-15) · **visto bueno legal** (P9-22) · precio por mensaje (`OUTREACH_WHATSAPP_PRICE_*`; las tarifas de Colombia están por confirmar) · números canarios de WhatsApp activos (fase 2 del H6) | ⏳ La orden de registro de Meta. C9 no existe |
| 6.2 | **(a)** el commit `outreach(sends-open):` con tu orden citada, su revisión de seguridad y su despliegue | Sin guion para desplegarlo |
| 6.3 | **(a)** `canal whatsapp on --cupo=N --presupuesto=USD` · **(d)** el primer mensaje, a un canario | — |
| 6.4 | **(c) + (d)** las etapas 1 a 3 del H8 superadas: laboratorio real con plan, publicación de la voz, `voice(calls-open)`, primera saliente y 4b con el visto bueno legal · **(a)** `canal voz on` · **(b)** D-V9-1 | ⏳ Cupos de voz del H9 y P-M2 · revisión de la Enmienda 17 |

### Etapa 7 · operación supervisada

**(b)** Un horario propio más estricto que el legal y vigilancia diaria del panel: frenos, incidentes, gasto y bajas. **Revisión semanal a cuatro ojos.** El clasificador (AI9) no existe: queda apagado.

---

## 6. Pendientes del propietario (consolidado)

Coherente con la activación por etapas y con el estado real de los proveedores (`PROV`). Cada punto dice para qué etapa hace falta.

### 6.1 Decisiones

| # | Decisión | Para | Recomendación (contrato) |
|---|---|---|---|
| P9-24 | Encender `COMMERCIAL_EVENTS_ENABLED`, después de leer qué escribe (`commercial_events`, desde los observadores de pagos, socios, prospectos, citas, conversaciones, asistencias y facturas) y cuánto cuesta (sin medir) | 4.1 | Sí, antes de la sombra |
| P9-11 | Al menos 7 días en sombra, y las dos primeras personas vinculadas por consola | 4 | Confirmar |
| P9-17 | Horario propio | 4.2 | Lunes a viernes de 09:00 a 18:00 y sábados de 09:00 a 13:00. **El mando solo escribe una franja para todos los días (§4)** |
| P9-18 | Topes por canal y día, por estrategia y día, inscripciones nuevas al día y presupuesto en US$ | 4.2 | — |
| P9-19 | Canario y promoción | 5.3 | 24 h, 3 contactos, 0 incidencias y cuatro ojos (son los valores por defecto del código) |
| P9-20 | Umbrales de los frenos automáticos | 4.4 | *Opt-out* > 5 % y fallos > 20 % en 24 h, con una muestra mínima de 5 (los valores por defecto, `config/outreach.php:79-82` en `ce7c150`); quien abre un freno no lo suelta |
| P9-12 | Quién trabaja la bandeja y cuántos trabajos al día | 5.1 | Administrador y, si se quiere, los asesores comerciales |
| P9-13 | Contenido, categoría e idioma de las plantillas; si `first_name` puede ir como variable; quién las registra en Meta; precio y techo diario | 6.1 | — |
| P9-14, P9-15 | Cómo se recoge la autorización de los socios sin prospecto de WhatsApp (C9), y qué fuentes de autorización son válidas por finalidad y canal | 6.1 | Hasta la asesoría: WhatsApp comercial solo con `consent_status = granted`; la voz solo con `voice_consents`; la cobranza, por ninguno |
| P9-16 / E17-1 | Ratificar el cambio de principio del H8: «solo una estrategia publicada a cuatro ojos llama, por su canal y por la puerta» | 6.4 | — |
| D-V9-1 | La voz por estrategia solo con finalidad comercial y el agente actual, o un paquete propio para `purposeKey` | 6.4 | — |
| Gancho 3 de W9 | ¿Una enmienda para que un WhatsApp entregado sin respuesta avance la secuencia (`no_answer` de sistema)? | 6 | Decidir con `architect` |
| P9-25 | Autorizar el despliegue del vale de un solo uso (R-1), en el orden CRM y después backend | Antes de `stream on` (recomendado) | — |
| `APP_KEY` | Si hay que rotarla, hacerlo **antes** de la primera publicación de una estrategia | 4.3 | `ops/ROTACION_APP_KEY_H9.md` |
| P9-23 | Eventos especiales del gimnasio y la app como canal (EV9) | Después | — |

### 6.2 Personas y permisos

- **Una segunda persona real con cuenta propia** (D-7 del H8). Es el bloqueante que más se repite: sin ella no hay vínculos, ni aprobación, ni publicación, ni se puede soltar un freno.
- **Los datos para el arranque de los vínculos:** el id de cada una de las dos cuentas y el uuid de la identidad de cada persona (`identities.uuid`, el mismo que usa el módulo de personal: `routes/api.php:1218`, `app/Services/Workforce/StaffRecord.php:50-52`). Ambas cuentas tienen que estar activas y ser de personas distintas (`StudioPersonBindings.php:290-307`).
- **El reparto de las llaves** desde Configuración → Roles. Por defecto, solo el Super Admin tiene `ultron.strategies_publish`, `ultron.person_bindings_manage`, `commercial_automation.simulate_real` y `commercial_automation.release`. El Administrador tiene `commercial_automation.view`, `…timeline_view`, `…assisted_work` y `…stop`, y además `ultron.strategies_edit` (incluye la simulación sintética), `ultron.strategies_rollback` y `ultron.approve` (`CrmPermission.php:288-343` en `ce7c150`). La pantalla de Roles puede haber cambiado este reparto en producción: no se ha leído.
- **Quién trabaja la bandeja** (P9-12): la llave `commercial_automation.assisted_work`.

### 6.3 Credenciales y proveedores

- **Meta (W9):** las plantillas aprobadas, y su correspondencia registrada en el Studio (⏳ la orden está en curso).
- **WhatsApp (W9, y la etapa 5 por WhatsApp):** los números canarios activos de la fase 2 del H6, con dos personas para el T1 (A-5) y `log_error_verbosity = terse` antes (A-6). **El universo autorizable hoy por WhatsApp comercial es de 86 prospectos `granted` de 837**; los socios de recepción no tienen autorización registrada (lectura del lead del 09-11; contrato, «Paso 0 leído en producción»).
- **Voz (V9), lo que arrastra del H8** (`PROV`):
  - **Seguridad, urgente aunque no sea del H9 (A-1, A-2):**
    - el número está importado en ElevenLabs con el Auth Token maestro de la cuenta de Twilio del OTP;
    - la clave de ElevenLabs pasó por el chat.
    - Hay que rotar las dos y reimportar el número con una credencial de alcance mínimo.
  - **D-3:** claves restringidas de Twilio solo de voz, o una subcuenta (b′). La cuenta tiene hoy 0 API keys y 0 subcuentas.
  - **D-5:** los dos secretos de Laravel y el webhook post-llamada registrados en ElevenLabs. Hoy hay 0.
  - **D-6:** el trato tú/usted y los textos del aviso de IA. La voz «Lina» ya está elegida.
- **OpenAI (AI9, más adelante):** la clave o el proyecto, y su presupuesto. AI9 no está construido.

### 6.4 El plan de ElevenLabs

**Solo lo necesita la voz por estrategia (6.4)**, a través de la cadena del H8: laboratorio real → publicación canaria de la voz → smoke 4a → primera saliente → evidencia. **Nada de las etapas 4 y 5 ni de W9 depende del plan.** Hoy el plan es el gratuito, sin minutos de agente (`PROV` §1).

### 6.5 Pruebas reales (consumen o contactan)

- **Etapa 5:** los contactos asistidos que hace el personal a personas reales, solo canarios mientras la estrategia esté en canario.
- **Etapa 6.3:** el primer WhatsApp automático, a un canario.
- **Etapa 6.4:** la primera llamada por estrategia al canario de voz, después de la primera saliente del H8 (unos 0,61 US$ reservados por llamada; unos 0,97 US$ con los minutos).

### 6.6 Autorizaciones escritas

1. El commit, el push y el despliegue de lo terminado en el árbol (§2), cuando exista el guion (§2.1).
2. Cada orden del mando que encienda algo: `gobierno on`, `publicar on`, `ventana`, `canario`, `horario`, `sombra on`, `stream on`, `asistido on`, `canal … on`, y `simulacion waived` y `ventana set ninguna`, que además llevan `--orden-escrita`.
3. El arranque de los vínculos: la referencia de la autorización va dentro de la orden (`--authorization`, de 10 a 160 caracteres).
4. Los commits de una línea `outreach(sends-open):` y `voice(calls-open):`, cada uno con tu orden citada.
5. Cada ejecución de la supresión de la Ley 1581, por solicitud.

---

## 7. Transversal

| Requisito | Estado |
|---|---|
| **Seguridad:** <ul><li>modelo de amenazas (`ULTRON_STUDIO_H9_AMENAZAS.md`) y 19 guardas;</li><li>R-1: corregido en el árbol (L9-78);</li><li>R-2: cumplido;</li><li>R-3: riesgo aceptado hasta la fase 2 (rotación de la `APP_KEY` sin re-anclaje).</li></ul> | Terminada en la fase 1 · la Enmienda 17 y L9-82: ⏳ sec-cierre |
| **Revisión independiente** | REV9: 0 bloqueantes para la fase 1 · lo nuevo del árbol: no consta |
| **Documentación (DOC9)** | <ul><li>**Hecho el 11-11:** esta matriz, `ops/RUNBOOK_H9.md` y las secciones del H9 en `ULTRON_STUDIO_DEPLOYMENT_PLAN.md`, `ULTRON_STUDIO_FINAL_REPORT.md` y `ULTRON_STUDIO_MASTER_ROADMAP.md`.</li><li>**Pendiente** del alcance que da a DOC9 el contrato (§11): `ULTRON_STUDIO_AUTOMATIZACION.md`, la guía de administración (que conserva en `:480` el texto viejo de B-T8), la lista operativa, ROLLBACK, SECURITY, BACKEND, VISUAL y `ops/README.md`.</li></ul> |

**`HITO_9_COMPLETED`** solo cuando concurran:
- PERF9 confirmado y TEST9-b cerrado (E2E #26 en verde);
- H9-E2E-1 corregido;
- la sombra (≥ 7 días) y el asistido ejecutados con su evidencia en producción;
- esta documentación al día.

El envío automático (W9 en adelante) se cierra en su propia enmienda y **no condiciona** este cierre (contrato §15).

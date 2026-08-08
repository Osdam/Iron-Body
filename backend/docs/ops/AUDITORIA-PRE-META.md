# Auditoría final PRE-META · F.13

Verificación de cierre antes de poder empezar el procedimiento de activación de
Meta. Nada de lo que hay aquí es aspiracional: cada afirmación se comprobó contra
el código o contra el servidor, y lo que no se pudo comprobar se dice.

Fecha: 8 de agosto de 2026 · Commit candidato: `8127260`

---

## 1. Inventario real

Contado sobre el árbol, no sobre documentación anterior.

| Backend | | Infra (producción) | |
|---|---|---|---|
| Controladores | 121 | Nginx + PHP-FPM | activos |
| Servicios | 259 | PostgreSQL 127.0.0.1:5432 | activo |
| Jobs | 10 | Supervisor | 12 procesos |
| Modelos | 141 | systemd `ironbody-billing-worker` | activo |
| Middleware | 9 | Cron `schedule:run` | cada minuto |
| Observadores | 10 | Disco | 16 G / 194 G (8 %) |
| Comandos artisan | 74 | `archive_mode` PostgreSQL | **off** |
| Migraciones | 224 | Automatización de respaldo | **no existe** |
| Ficheros de prueba | 216 | | |
| Tareas programadas | 32 | | |

**Políticas de autorización: 0 ficheros en `app/Policies`.** La autorización no
se hace con Policies sino con middleware (`auth.admin`, `ProtectAdminPaths`) y
comprobaciones explícitas en los servicios de autorización de marketing. No es un
hallazgo, es una decisión de diseño distinta de la habitual, y conviene saberlo
antes de buscar Policies que no están.

Frontend (Angular): Inbox V2, Analítica, Supervisión, aprobaciones, incidentes y
alertas — 107 pruebas + 13 de contrato de maquetación.

Integraciones reales: Meta Cloud API, OpenAI, Wompi, Factus, app móvil (FCM),
n8n, IRON GUARD interno. Hermes existe como código **inerte** (ver §5).

---

## 2. Arquitectura final

El camino que recorre un mensaje, con los carriles de cola donde de verdad corre:

```
                    ┌─────────────── WhatsApp (cliente) ───────────────┐
                    ▼                                                  ▲
            Meta Cloud API                                     Meta Cloud API
                    │                                                  ▲
                    ▼ POST /api/webhooks/meta                          │
        ┌───────────────────────────┐                                  │
        │ Validación de firma HMAC  │ ← rechaza antes de persistir     │
        │ (X-Hub-Signature-256)     │   nada y antes de encolar        │
        └───────────┬───────────────┘                                  │
                    ▼                                                  │
        meta_webhook_events (cuerpo crudo)  ← sobrevive al worker      │
                    │                                                  │
                    ▼  cola: whatsapp-high  (P0 · 4 workers)           │
        ┌───────────────────────────────────────────┐                  │
        │ ProcessMetaWebhookEvent                   │                  │
        │  · lead + conversación                    │                  │
        │  · mensaje entrante (idempotente por      │                  │
        │    meta_message_id)                       │                  │
        │  · atribución                             │                  │
        │  · estados sent/delivered/read (monótono) │                  │
        └───┬──────────────────────┬────────────────┘                  │
            │                      │                                   │
            │ adjunto              │ texto analizable                   │
            ▼ cola: media (P3·2)   ▼ cola: agent (P2·4)                │
   DownloadWhatsappMedia     AnalyzeInboundMessage                     │
    · URL firmada de Meta     · reevalúa takeover/opt-out AHORA        │
    · MIME por bytes          · cerrojo por conversación               │
    · disco privado           └──► OpenAI ──► SalesAgentDecisionValidator
    · ffmpeg si aplica                             │                   │
                                                   ▼                   │
                                         ToolExecutor (registro cerrado)
                                          · flag · schema · sin extras │
                                          · aprobación humana · idempotencia
                                                   │                   │
                                                   ▼                   │
                                     Servicios CRM tipados             │
                                                   │                   │
                                                   ▼                   │
                                   MarketingMessageDispatcher          │
                                                   │                   │
                                                   ▼                   │
                                   WhatsappOutboxService ──────────────┘
                                    · si hay meta_message_id, NO reenvía
                                    · takeover humano cancela el reintento
                                    · backoff con dispersión, techo 4
```

Ramas:

```
Wompi     POST /api/webhooks/wompi → checksum → dedupe por hash del cuerpo
          → monto/moneda contra el importe FIRMADO → máquina de estados con
          lockForUpdate → approved activa membresía UNA vez → evento comercial
          Timeout del POST /transactions → pending «desenlace desconocido»,
          nunca error: lo resuelven webhook o reconciliación.

Factus    payment aprobado → invoice request → cola billing (1 worker)
          → payload congelado → guardarraíl de importe → emisión
          → CUFE + número obligatorios para marcar validada → PDF/XML

Comercial CommercialEventRecorder → cola commercial (P4·2)
          → OpportunityReconciler (cierra lo cumplido)
          → NextBestActionEngine (12 reglas ordenadas, elige UNA)
          → ContactPolicy (opt-out, takeover, frecuencia, horario Neiva)
          → CommercialAlertService (dedupe por huella)

IRON GUARD ChannelHealthDetector (14 comprobaciones) → IncidentRecorder
          (dedupe por fingerprint de CLASE) → panel de Supervisión
          Remediación automática: APAGADA, y con allowlist además del flag.

Takeover  takeover() → human_takeover + ai_enabled=false → bloquea decisión del
          motor, bloquea envío del outbox para mensajes de la IA (no los del
          asesor), y bloquea las oportunidades abiertas.
          release() → resumen del traspaso en `summary` → la IA retoma con
          contexto nuevo, no con el de antes.
```

---

## 3. Matriz función → implementación → prueba

Solo figura PASS lo que tiene implementación **y** prueba.

| Función | Backend | Frontend | Pruebas | Estado |
|---|---|---|---|---|
| Inbound WhatsApp | `MetaWebhookIngestService`, `ProcessMetaWebhookEvent` | Inbox V2 | F.3 ×52, F.6 ×13, aislamiento ×9 | PASS |
| Firma de webhook | middleware + `MetaWebhookService` | — | F6.36, F6.36b | PASS |
| Multimedia | `MetaMediaService`, `DownloadWhatsappMedia` | Inbox | F6.14–18 (11) | PASS |
| Notas de voz | `voice` en adjunto + transcripción | Inbox | `InboundMessageSurfaceTest` | PASS |
| Estados de mensaje | `recordStatus` (rango monótono) | Inbox | F6.32–34 | PASS |
| Tags | `MarketingConversationTagService` | Inbox | `TagCatalogTest` | PASS |
| Atribución | `LeadAttributionService`, `MarketingAttributionService` | Analítica | F9 §9, `LeadAttributionTest` | PASS |
| Analítica | `CampaignAnalyticsService` | Analítica | `CampaignAnalyticsTest` | PASS |
| Agente comercial | `SalesAgentOrchestratorService` + `AnalyzeInboundMessage` | — | F6.01–07, F.9 | PASS |
| NBA | `NextBestActionEngine` (12 reglas) | Supervisión | F.9 ×24 | PASS |
| NBO | `planLadder` + `offer/alternative/floor` | Supervisión | F9.19, F9.7 | PASS |
| Oportunidades | `CommercialOpportunity` + reconciliador | Supervisión | invariantes 1,2,9 | PASS |
| OpenAI | `OpenAiSalesResponder` + validador | — | F6.01–04 | PASS |
| Hermes | código presente, **inerte** | — | `HermesFallbackTest` | INERTE (§5) |
| Wompi | 5 servicios + máquina de estados | app | F6.19–25 (10) | PASS |
| Membresías | `PaymentMembershipActivator` | app/CRM | invariante 3, F.9 | PASS |
| Agenda | `MarketingAppointmentService` + `BookAppointmentTool` | CRM | F6.38, F6.39 | PASS |
| Facturación | `EmitElectronicInvoiceJob` + Factus | CRM | F6.26–29 (7) | PASS |
| App linking | `hasAppAccount` desde `users` | app | F6.40, F9.5 | PASS |
| Supervisión | `SupervisionService` | Supervisión | `SupervisionTest`, F9 §9 | PASS |
| Aprobaciones | `ApprovalQueueService` | Aprobaciones | F6.43, F6.44 | PASS |
| Human takeover | `MarketingManualTakeoverService` | Inbox | F6.42, F9.17, invariante 5 | PASS |
| IRON GUARD | `ChannelHealthDetector`, `IncidentRecorder` | Incidentes | F6.10b, F6.45, salud de colas ×9 | PASS |
| Alertas comerciales | `CommercialAlertService` | Alertas | F.6 observabilidad ×12 | PASS |
| Colas / workers | 5 carriles + `QueueHealthService` | Supervisión | aislamiento ×9, salud ×9 | PASS |
| Respaldos | — | — | — | **NO EXISTE** (§7) |

---

## 4. Topología de workers desplegada

| Carril | Cola | Procesos | timeout | retry_after | tries | Responsabilidad |
|---|---|---|---|---|---|---|
| P0 | `whatsapp-high` | 4 | 60 s | **120 s** | 3 | persistir entrante, estados |
| P2 | `agent` | 4 | 240 s | **360 s** | 3 | el modelo |
| P3 | `media` | 2 | 420 s | **600 s** | 3 | descargas/subidas |
| P4 | `commercial` | 2 | 120 s | **300 s** | 3 | eventos, oportunidades, alertas |
| P4 | `billing` | 1 (systemd) | 600 s | **900 s** | 3 | Factus |

`retry_after > timeout` en los cinco, comprobado por prueba y no por comentario
(`ChaosLaneIsolationTest::test_retry_after_supera_el_timeout_de_cada_carril`).
Sin esa desigualdad, un trabajo lento sigue corriendo mientras otro worker
empieza el mismo: dos respuestas al cliente, o dos números fiscales.

**OpenAI lento no bloquea la entrada**, y no por la separación de colas sino
porque la llamada al modelo salió del job que persiste el mensaje. Medido, ráfaga
de 25 trabajos mixtos: espera p95 en `whatsapp-high` de **36.288 ms → 92 ms**, y
el tiempo de proceso de la ingesta de **2.800 ms → 20 ms**. Con 100 trabajos, p95
de 246 ms contra un compromiso de 500 ms. CPU 0,8 %, 55 MB por proceso.

Nada queda en `default`. Un job nuevo al que se le olvide el carril lo delata
`test_ningun_job_se_queda_en_la_cola_retirada`, que recorre las clases reales.

---

## 5. Banderas — valores EFECTIVOS en producción

Leídos con `config()` sobre la caché de configuración desplegada, no del `.env`.

| Bandera | Efectivo | Default | Qué habilita | Riesgo si se enciende antes de tiempo |
|---|---|---|---|---|
| `META_ENABLED` | **false** | false | Envío y recepción reales | Mensajes reales a personas |
| `MARKETING_AGENT_ENABLED` | **false** | false | Ejecución de herramientas del agente | El agente actúa solo |
| `COMMERCIAL_AUTONOMY_ENABLED` | **false** | false | Herramientas que escriben | Acciones comerciales sin humano |
| `marketing.ai.hermes.enabled` | **false** | false | Motor Hermes | Razonador no auditado |
| `IRON_GUARD_AUTO_REMEDIATION` | **false** | false | Remediación automática | Acciones sin persona (además exige allowlist) |
| `marketing.ai.driver` | `openai` | `fake` | Responder del agente | Inerte mientras el agente esté apagado |
| `marketing.inbound.analyze_inline` | false | false | Modelo dentro de la ingesta | Vuelve al cuello de botella de F.6 |
| `commercial.tools.*` | false | false | Cada herramienta por separado | Acción concreta habilitada |
| `billing.enabled` / `FACTUS_TAX_DECISION_CONFIRMED` | ver `.env` | false | Emisión fiscal real | Facturas reales |

**Hermes está inerte por partida triple**: `driver` es `openai` (no `hermes`),
`hermes.enabled` es false, y sin `base_url` degrada a OpenAI y de ahí a reglas.
Apagar el flag devuelve el comportamiento exacto de hoy.

Orden de activación y vuelta atrás: `docs/whatsapp/ACTIVACION-META.md`.

---

## 6. Secretos

Revisado sin imprimir un solo valor: histórico de git, árbol versionado,
`.env` de producción y de desarrollo, logs, respaldos, docs, pruebas y build del
frontend.

| Secreto | Estado | ¿Rotar? |
|---|---|---|
| Token de acceso de Meta | seguro | implícito en la activación |
| App Secret de Meta | seguro | implícito en la activación |
| Verify token del webhook | seguro | implícito en la activación |
| `OPENAI_API_KEY` | seguro, con copia suelta en disco | **sí** |
| Wompi `private` / `integrity` / `events` | seguro, con copia suelta en disco | **sí** |
| Credenciales de Factus | seguro, con copia suelta en disco | **sí** |
| Contraseña de PostgreSQL | seguro, con copia suelta en disco | **sí** |
| `APP_KEY` | seguro, con copia suelta en disco | **no** — rotarla invalida sesiones y datos cifrados |
| SMTP | seguro, con copia suelta en disco | **sí** |
| Firebase / service account | seguro | no |

**Nunca ha habido exposición pública ni versionada.** El histórico de git no
contiene ningún `.env`; los patrones que aparecen en el árbol
(`EAAA`, `prv_prod`, `base64:`) son, uno por uno: un token falso en una prueba de
adjuntos, las constantes de prefijo de `WompiConfigValidator`, el nombre del
campo `pdf_base_64` de Factus, y la propia prueba de exposición del proyecto.
`php artisan security:check-secret-exposure` → «Ningún fichero con credenciales
está expuesto». `.env` de producción a `0600 www-data:www-data`. Cero
coincidencias de secretos en logs.

Lo que sí hay son **dos copias de credenciales fuera del gestor**:
`/root/backups/env-before-guard-*` en el servidor y
`backend/.env.backup-before-iron-ai-local-*` en la máquina de desarrollo. Las dos
a `0600` y nunca versionadas, así que no hay fuga demostrada — pero
`.gitignore` cubre `.env.backup` exacto y **no** `.env.backup-*`, así que la del
desarrollo está a un `git add -A` de acabar en el histórico. De ahí la rotación:
no porque se sepa que se filtró, sino porque no se puede demostrar que no.

---

## 7. Respaldos — el hallazgo que bloquea el go-live

Estado comprobado en el servidor:

- `crontab -l` → **ninguna entrada de respaldo**;
- systemd → **ningún temporizador** de respaldo;
- `archive_mode` de PostgreSQL → **off** (sin WAL archiving, sin recuperación a
  un punto en el tiempo);
- volcados existentes: manuales y sueltos en `/root`, el completo más reciente
  del **1 de julio de 2026** — 38 días;
- restauración: **nunca probada**.

Con Meta apagado el riesgo está acotado: los datos cambian despacio y no entra
nada irremplazable. En el momento del OTP eso cambia de golpe, porque lo primero
que entra son conversaciones de clientes, y una conversación perdida no se puede
volver a pedir.

**Clasificación: REQUIRED BEFORE GO-LIVE, no bloqueante de PRE-META READY.** No
es un defecto del software —no hay nada que arreglar en el código— sino un
prerrequisito operativo del primer paso del propio runbook de activación, que
literalmente empieza por «respaldar». Un volcado de hace cinco semanas no lo
cumple.

No se ha tocado la infraestructura de producción para arreglarlo: hacerlo exige
decidir destino, retención y ventana, y eso es una decisión del dueño del
sistema, no de esta auditoría.

---

## 8. Integridad de datos (producción, solo lectura)

| Comprobación | Resultado | Lectura |
|---|---|---|
| Mensajes sin conversación | 0 | — |
| Conversaciones sin lead | 0 | — |
| Leads con miembro inexistente | 0 | — |
| Miembros sin usuario | 0 | — |
| Referencias de venta duplicadas | **1** | ver abajo |
| Teléfonos con más de un miembro | **11** | familia, no duplicación |
| Facturas validadas sin CUFE | 0 | la corrección de F.6 se sostiene |
| Facturas sin origen | 0 | — |
| Atribuciones sin lead / duplicadas por lead | 0 / 0 | — |
| Oportunidades sin sujeto | 0 | — |
| Incidentes sin evidencia | 0 | — |
| Alertas sin entidad | 0 | — |
| Pagos indeterminados abiertos | 0 | — |
| Eventos de Meta `dead` | 0 | — |
| Salientes `dead` | 0 | — |
| `failed_jobs` | 0 | — |
| Mensajes sin `correlation_id` | 164 | anteriores a la corrección de F.3 |

**Los 11 teléfonos compartidos no son duplicación de identidad**: cero con el
mismo nombre y todos con documento distinto. Son familias compartiendo un número,
que en un gimnasio de barrio es lo normal.

**La referencia duplicada es real y merece una decisión humana.** `REC-1001`
aparece dos veces en `payments`, las dos como `cash`, con 38 días de diferencia
(21 de junio y 29 de julio) y 1.780.000 en total. No es un cobro contado dos
veces: son dos cobros en efectivo distintos que reutilizaron el mismo número de
recibo por la vía de registro manual, que no pasa por el `firstOrCreate` del
activador. No afecta al camino de Wompi —sus referencias llevan prefijo `IRON-`
y no pueden colisionar con `REC-`— y no se toca desde aquí: son datos productivos
y corregirlos requiere saber qué recibo era cuál.

---

## 9. Seguridad del agente

Comprobado con pruebas, no por inspección: el modelo **no puede** ejecutar shell,
entrar al VPS, consultar PostgreSQL, tocar `.env`, fijar un precio, inventar un
descuento, marcar un pago aprobado, crear una membresía, saltarse una aprobación,
ejecutar algo fuera del registro, ni convertir texto libre en una llamada a
herramienta.

El único camino es `ToolExecutor`, y todas las barreras viven ahí y no en cada
herramienta: existencia en registro cerrado → flag → validación de esquema →
**rechazo de campos de más** → autorización y aprobación humana → reclamo de
clave de idempotencia en base **antes** de salir a la red → ejecución con
presupuesto de tiempo → acta cerrada.

El importe sale siempre del catálogo. `{"price": 1}` se rechaza con
`unexpected_arguments` y queda auditado, porque que el modelo intente fijar un
precio es información que alguien tiene que ver (F6.07, tres variantes).

---

## 10. Privacidad y logs

Cero coincidencias de tokens, llaves o contraseñas en `storage/logs`. Los
`correlation_id` sí están, y son lo que permite ir del webhook al mensaje. Los
errores se recortan a 200–2000 caracteres según el campo; la evidencia de los
incidentes se comprueba contra fuga de secretos en cada prueba de F.6
(`assertNoSecretsLeaked`). `safeRaw`/`safePayload` quitan token, CVC y PAN antes
de persistir cualquier respuesta de pasarela.

---

## 11. Economía

Clasificación derivada de hechos, sin doble conteo: **adquisición** (primer pago
aprobado del miembro), **renovación** (posteriores), **mejora** (posterior con
importe mayor y plan distinto), **reactivación** (posterior sin ningún aprobado
en los 90 días anteriores). Verificado en F.9 §4 y en los invariantes 7–8.

Atribución: primer toque estable, último toque solo con evidencia nueva, una fila
por persona. Contribución del agente: `autonomous` / `assisted` / `influenced` /
`none`, con un recorrido verificable por categoría.

**ROAS: no disponible, y se dice.** No hay contabilidad de gasto de Meta ni de
consumo de IA conectada. `SupervisionService` devuelve
`ai_cost.available = false` y `roi.available = false` en lugar de un cero, porque
un cero afirmaría que es gratis.

---

## 12. Runbook de incidentes

Para los doce escenarios: detectar → mitigar → investigar → recuperar → validar →
cerrar. La columna «detectar» no es teórica: es la comprobación de IRON GUARD que
lo levanta.

| Escenario | Detectar | Mitigar | Recuperar | Validar |
|---|---|---|---|---|
| Meta caído | `error_code_spike`, `messages_dead` | nada: el outbox reintenta con backoff y techo 4 | `marketing:retry-outbox` | `queue:health`, mensajes sin `meta_message_id` |
| OpenAI caído | `agent.analysis_failed` en logs, backlog en `agent` | el responder degrada a reglas; la conversación se guarda igual | reintento del job (3, backoff 30/120/300) | decisión registrada con `openai_fallback_*` |
| Wompi caído | `payments_stuck` (60 min) | ninguna: el estado queda `pending`, nunca `error` | webhook o `wompi:reconcile` | ningún pago indeterminado >2 h |
| Factus caído | `invoices_failing` | la solicitud queda `error`, reintentable | reintento con payload congelado | facturas validadas siempre con CUFE |
| PostgreSQL caído | todo falla; `failed_jobs` crece | los eventos crudos sobreviven en `meta_webhook_events` | levantar base + `marketing:replay-webhooks` | eventos `processed` = recibidos |
| Storage caído | `disk_unavailable` (crítico) | el adjunto queda `failed`, **nunca `stored`** | reintento del job de media | ningún adjunto `stored` sin fichero |
| Worker caído | `queue_unattended` (crítico si es P0) | `supervisorctl start <carril>` | la cola drena sola | `queue:health` al día |
| Cola creciendo | `queue_backlog` (nunca crítico) | subir `numprocs` del carril afectado | drenaje | espera p95 dentro del compromiso |
| Disco lleno | `disk_unavailable` | `PruneWhatsappMedia`, rotar logs | liberar espacio | `df -h` |
| Duplicación detectada | referencias duplicadas en `payments` | **no borrar**: identificar el origen | corrección manual con acta | conteo de duplicados |
| Pago indeterminado | `payments_stuck` + alerta «pago a medias» | ninguna automática: **no crear un segundo cobro** | webhook, reconciliación o panel de Wompi | una venta por referencia |
| IA contestó tras un takeover | mensaje `ai` posterior a `manual_takeover_at` | `takeover()` cancela el reintento pendiente | revisar el hilo con el cliente | ningún saliente `ai` posterior al takeover |

---

## 13. Shadow y canary

**Shadow es técnicamente posible hoy y sin tocar código.** Con
`META_ENABLED=true` y `MARKETING_AGENT_ENABLED=false`: el cliente escribe, el
sistema ingiere y persiste, `AnalyzeInboundMessage` corre y registra la decisión
completa —respuesta propuesta, acción, herramientas pedidas, confianza,
exclusiones y motivo— en `marketing_ai_actions` y `commercial_opportunities`, y
**no envía nada** porque `auto_execute` exige las dos banderas. El humano compara
en el Inbox y decide.

Canary, con puerta GO/NO-GO en cada etapa:

| Etapa | Qué se enciende | GO si | NO-GO si |
|---|---|---|---|
| 0 | Meta + humano manual | firma válida, estados llegan, cero duplicados | cualquier mensaje duplicado |
| 1 | IA en shadow | decisión registrada en <30 s p95, cero envíos | un solo envío no autorizado |
| 2 | IA responde a un subconjunto de bajo riesgo | cero respuestas erróneas, escalado funciona | una respuesta con precio inventado |
| 3 | ampliación controlada | espera p95 de `whatsapp-high` ≤500 ms, incidentes 0 críticos | acoso, o cola sin atender |
| 4 | herramientas de bajo riesgo (consulta) | cero acciones fuera de registro | una herramienta ejecutada sin flag |
| 5 | autonomía dentro de política | cero dobles efectos financieros, opt-out respetado | un doble cobro o una oferta tras un «no» |

---

## 14. Matriz consolidada de la fase F

| Sub-fase | Alcance | Estado |
|---|---|---|
| F.1 | Baseline y suites completas | PASS |
| F.2 | Concurrencia (5 carreras) | PASS |
| F.3 | 52 recorridos E2E por webhook firmado | PASS |
| F.4 | Seguridad (13 comprobaciones) | PASS |
| F.6 | Inyección de fallos, 52 escenarios | PASS |
| F.7 | Validación visual en producción, 4 resoluciones | PASS |
| F.9 | Ciclo comercial largo, 24 pruebas + 100×180 días | PASS |
| F.10 | Topología de workers (equivale al hallazgo operativo de F.6) | PASS |
| F.11 | Rendimiento comparado antes/después | PASS |
| F.12 | Ensayo de vuelta atrás | PASS (documentada y probada en el despliegue de colas) |
| F.13 | Documentación integral y auditoría final | PASS |
| F.14 | Activación de Meta | **BLOCKED EXTERNAL** — requiere actos administrativos en Meta |
| — | Automatización de respaldos | **REQUIRED BEFORE GO-LIVE** |
| — | Rotación de credenciales | **REQUIRED BEFORE GO-LIVE** |

---

## 15. Congelación

Desde el commit `8127260` se declara **FEATURE FREEZE PRE-META**. A partir de
aquí solo entran: corrección de fallos, seguridad, activación, observabilidad y
operación. Ninguna característica nueva.

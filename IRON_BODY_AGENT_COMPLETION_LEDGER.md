# IRON BODY — Ledger del canal propio de WhatsApp

Estado del trabajo para operar el número oficial de Iron Body Neiva
(+57 314 345 5483) con Meta WhatsApp Cloud API, sin BSP y sin Baileys.

Cada fila dice qué se comprobó, qué se cambió y qué prueba lo respalda. Una fila
solo pasa a **PASS** cuando hay una prueba ejecutada que lo demuestra.

---

## Línea base (2026-08-05, antes de tocar nada)

| Dato | Valor |
|---|---|
| Suite backend | **1640 pasan / 4 fallan** (10 976 aserciones, 154 s) |
| Fallos previos | `MembershipCancellationTest` (3) y `AppStateRealtimeTest` (1) |
| Naturaleza | Dominio de **membresías**, dependientes de la fecha del sistema. Fuera del alcance autorizado. |
| Commit de partida | `3c0dbf0` |

## Estado final

| Dato | Valor |
|---|---|
| Suite backend | **1873 pasan / 0 fallan** (11 685 aserciones, 80 s) |
| Pruebas añadidas | **+233** |
| Build Angular | Correcto (avisos de presupuesto CSS preexistentes, en archivos no tocados) |
| Los 4 fallos de la línea base | Pasan hoy **solos**. Eran sensibles a la fecha: no se tocó nada del módulo de membresías. Siguen siendo frágiles. |

## Segunda fase (2026-08-06)

### 10. Secretos en disco — **PASS**

57 ficheros `.env` con credenciales vivas a `644` en el directorio de la
aplicación, incluido el `.env` en uso. **No eran accesibles por web** (docroot
`public/` + `deny all` de nginx, comprobado con traversal en claro, codificado y
doble codificado), pero sí por cualquier usuario con shell.

56 movidos a `/root/env-backups` a `600`; el `.env` en uso pasado a `600`.
`security:check-secret-exposure` + `SecretExposureTest` (18) impiden la
reincidencia. Inventario y propuesta de borrado en `docs/whatsapp/INVENTARIO-ENV.md`
— **no se ha borrado nada**.

### 11. Optimización de Hermes — **FAIL objetivo, decisión tomada**

| | OpenAI directo | Hermes minimal |
|---|---|---|
| Tokens de entrada | **37** | 12 465 |
| p95 con 10 simultáneas | **1,19 s** | 14,62 s |
| Éxito con 10 simultáneas | **10/10** | 7/10 |

Se creó `iron-sales-minimal` con todo desactivado: el prompt fijo cayó un 93 %
(40 985 B → 2 986 B) y el coste real solo un 13 %. Un prompt de dos palabras
cuesta 12 440 tokens: **el gasto es fijo y estructural del runtime**, fuera del
alcance de la configuración. Detalle en `docs/hermes/COMPARATIVA.md`.

**Hermes permanece apagado.** Cumple 1 de 5 criterios objetivos.

### 12. Fallback probado — **PASS** (9 pruebas)

Degradación en la misma petición · cortacircuitos tras fallos repetidos ·
enfriamiento y llamada de prueba · techo de gasto · kill switch sin desplegar ·
con ambos motores caídos, el mensaje queda esperando a un humano.

### 13. Inbox operado sin Meta — **PASS** (13 pruebas)

Respuesta manual con autor · imagen, audio, video y documento · nota de voz
distinguida · adjunto fallido que se explica · pausar y reanudar IA · asignación
· reintento de un 429 hasta que entra · **dos operadores a la vez sin pisarse** ·
**un operador respondiendo impide que la IA conteste encima** · una reentrega
nunca genera una segunda respuesta.

## Tercera fase (2026-08-06) — motor comercial

### 15. Decisión arquitectónica aplicada — **PASS**

Hermes queda fuera del canal comercial por decisión del propietario, respaldada
por la medición de la fase 2. El motor es **Laravel → OpenAI directo →
herramientas Laravel → Meta Cloud API**. Hermes sigue instalado, apagado y
disponible para IRON GUARD y análisis internos no interactivos.

### 16. Motor Next Best Action / Next Best Offer — **PASS** (25 pruebas)

Tres tablas nuevas (`commercial_opportunities`, `commercial_segments`,
`commercial_events`) y un motor **determinista en PHP**: la IA redacta, Laravel
decide. Cada oportunidad guarda objetivo, acción, oferta principal, alternativa,
mínimo aceptable, momento, prioridad, confianza, razón, **exclusiones** y
evidencia.

Reglas implementadas, en orden de prioridad: escalado humano · recuperar enlace
de pago · reintentar pago rechazado · activar tras pago · onboarding de miembro
nuevo · rescate de miembro en riesgo · renovación · reactivación · upgrade ·
cierre de prospecto · calificación · referidos.

Decisiones de criterio que quedan fijadas por prueba:

- Un miembro nuevo que **no ha venido** nunca recibe oferta de plan más largo.
- Quien **paga y no aparece** recibe una pregunta, no un empujón de renovación.
- El upgrade exige **uso demostrado** (≥2,5 visitas/semana) y ≥21 días de
  antigüedad.
- Cuando sí toca subir, se ofrece la **escalera completa** para que una negativa
  al anual acabe en renovación mensual y no en cliente perdido.
- `wait` es una decisión de pleno derecho, con fecha de reintento.

Dos fallos reales que encontraron las pruebas y se corrigieron **en el código**:
«nunca vino» se deducía de una fecha ausente y marcaba como desaparecido a quien
había venido 17 veces ese mes; y el catálogo se cacheaba en una `static`, de modo
que un plan desactivado se habría seguido ofreciendo hasta reiniciar el worker.

### 17. Política de contacto — **PASS** (15 pruebas)

El freno que impide que el motor se vuelva un acoso. Límite **por persona** y no
por oportunidad; las respuestas y los mensajes humanos no consumen cuota; horas
de silencio medidas en Neiva y no en el reloj UTC del servidor; detección de la
ventana de 24 h de Meta para exigir plantilla. Cada rechazo dice por qué y
cuándo se podrá reintentar.

### 18. Pendiente de esta fase — **NO ENTREGADO**

Se declara explícitamente lo que **no** está hecho, para que nadie lo dé por
supuesto:

| Área | Estado |
|---|---|
| Rediseño total del Inbox V2 (layout 3 columnas, scroll propio, virtualización) | **TODO** |
| Herramientas del agente para Wompi / membresías / agenda / Factus / app | **TODO** — la infraestructura existe (`WompiPaymentLinkService`, `InvoicingService`, `MembershipService`), falta exponerla como herramientas tipadas del agente |
| Panel de supervisión humana (P3) | **TODO** |
| Métricas económicas atribuibles | **TODO** — el esquema las soporta (`estimated_value`, `realized_value`, `outcome`) |
| Recorder de eventos + listeners que disparan el motor | **ENTREGADO** — ver §19 |
| Flujos E2E A–J | **TODO** |
| Pruebas de frontend y verificación visual en 4 resoluciones | **TODO** |
| Máquina de estados comercial explícita | Parcial: los estados existen en la oportunidad; falta el objeto de transiciones |

### 14. Plan de activación — **ENTREGADO**

`docs/whatsapp/ACTIVACION.md`: cinco fases, variables exactas sin valores, orden
del registro por OTP, canary, rollback inmediato, ocho condiciones que obligan a
apagar `META_ENABLED` y checklist de 15 puntos. **No ejecutado.**

**Recursos del VPS medidos antes de decidir arquitectura** (`srv1728633`):
4 vCPU · 15,9 GB RAM (14,2 GB disponibles) · 194 GB disco con 184 GB libres ·
Docker 29.5.3 con n8n · sin Redis · PostgreSQL 14 en loopback.

Dos decisiones salen de esa medición:

- **Medios en disco local privado, no MinIO.** Hay sitio de sobra y un solo
  gimnasio. MinIO añadiría un contenedor, su RAM, su backup y su superficie de
  ataque para una escala que este negocio no tiene. La abstracción queda puesta:
  `WHATSAPP_MEDIA_DISK` apunta a un disco S3 el día que haga falta.
- **Logs JSON + tablas, no Prometheus/Grafana/Loki.** La pila completa cuesta
  más RAM y mantenimiento que el valor que da aquí. Los logs ya salen en el
  formato que Loki ingiere sin cambios si algún día se justifica.

---

## Matriz de aceptación

### 1. Meta y webhook — **PASS**

| Criterio | Estado inicial | Prueba | Estado |
|---|---|---|---|
| Verificación GET | Existía | Smoke producción: `HTTP 200 body=PRUEBA123` | **PASS** |
| GET con token inválido | Existía | Smoke producción: `HTTP 403` | **PASS** |
| Firma POST válida | Existía | Smoke producción: `HTTP 200` | **PASS** |
| Firma inválida rechazada | Existía | Smoke `HTTP 403` + no deja **nada** guardado | **PASS** |
| **Persistencia del evento crudo** | **No existía** | `WebhookDurabilityTest::…survives_even_if_no_worker_ever_runs` | **PASS** |
| Respuesta rápida, sin IA en el request | Existía | — | **PASS** |
| Duplicados no duplican | Solo por `meta_message_id` | Barrera añadida por SHA-256 del cuerpo | **PASS** |
| Protección de replay | **No existía** | `…replaying_a_captured_signed_body_changes_nothing` | **PASS** |
| Límite de tamaño | **No existía** | Smoke producción: `HTTP 413` | **PASS** |
| Payloads múltiples | El log solo miraba el primero | `…every_entry_and_change_in_one_post_is_processed` | **PASS** |
| Status callbacks | UPDATE ciego | `MessageStatusReconciliationTest` (10) | **PASS** |
| Errores de Meta visibles | **No existía** | Código, título y detalle junto al mensaje | **PASS** |

### 2. Superficie de mensajes — **PASS** (15 pruebas)

Texto · botones · listas · botones de plantilla · imagen · audio · nota de voz ·
video · documento · sticker · ubicación · contactos · reacciones · citas ·
referidos de anuncios · tipos desconocidos. Todo se registra; nada se pierde.

Decisiones que importan: pulsar un botón **es** hablar (el título entra al
agente); una foto **con** pie de foto se atiende y **sin** él se escala a un
humano en vez de que el agente adivine; una reacción se registra y **no** se
responde.

### 3. Medios y archivos — **PASS** (19 + 13 + 6 pruebas)

| Criterio | Prueba | Estado |
|---|---|---|
| Descarga fuera del webhook | `MediaPipelineTest` | **PASS** |
| Disco privado, nada en PostgreSQL | idem | **PASS** |
| MIME real por bytes, no por etiqueta | `…file_lying_about_its_type_is_rejected` | **PASS** |
| HTML/SVG nunca llegan al disco | `…html_disguised_as_an_image_never_reaches_the_disk` | **PASS** |
| Límite de tamaño (doble corte) | `…refused_before_being_written` | **PASS** |
| SSRF | `…media_url_outside_meta_is_never_fetched` | **PASS** |
| Path traversal | `…path_owes_nothing_to_the_client_filename` + 6 casos | **PASS** |
| Hash y deduplicación | `…same_file_twice_is_stored_only_once` | **PASS** |
| URL firmada de vida corta + RBAC | `AttachmentAccessTest` (13) | **PASS** |
| Retención con barrido diario | `MediaRetentionTest` (6) | **PASS** |
| Transcripción | Columnas y config listas, **apagada** | **PENDIENTE (por diseño)** |

### 4. Salida hacia Meta — **PASS** (15 pruebas)

Reintento de fallos transitorios con espera creciente y dispersa · fallos
definitivos a `dead` sin insistir · **ningún mensaje con `meta_message_id` se
reenvía jamás** · plantillas, adjuntos y acuses de lectura · citas al mensaje del
cliente.

### 5. Observabilidad — **PASS**

| Criterio | Evidencia | Estado |
|---|---|---|
| `correlation_id` de punta a punta | Producción: un solo id en `webhook.received` → `webhook.queued` → `event.started` | **PASS** |
| Logs JSON estructurados | `storage/logs/channel-2026-08-06.log`, una línea = un JSON | **PASS** |
| Teléfonos enmascarados | Producción: `expected_phone_number_id: "12**********4405"` | **PASS** |
| Secretos fuera del log | Producción: `has_signature: "[redacted]"` | **PASS** |
| Redacción probada | `LogRedactorTest` (27) | **PASS** |

### 6. IRON GUARD — **PASS** (15 pruebas)

Detección **determinista** sobre estado propio (no analiza logs con un modelo) ·
agrupamiento por fingerprint · severidad que distingue defenderse de averiarse ·
códigos de Meta traducidos · panel CRM con evidencia y timeline · remediación con
doble llave (flag + allowlist de 4 acciones reversibles).

Pasada contra producción real: **0 incidentes**.

### 7. Hermes — **PASS con salvedad**

| Criterio | Estado |
|---|---|
| Instalado desde el origen oficial | **PASS** — `nousresearch/hermes-agent` |
| Versión fijada | **PASS** — `v2026.7.30`, digest `sha256:b869e64d…` |
| Servicio estable + health check | **PASS** — ambos contenedores `healthy` |
| Solo loopback | **PASS** — `127.0.0.1:8642` y `127.0.0.1:8643` |
| Bearer obligatorio | **PASS** — sin token 401, token malo 401, bueno 200 |
| `iron-sales` sin shell | **PASS** — 16 toolsets desactivados, verificado |
| `iron-guard` separado | **PASS** — contenedor y volumen propios |
| Usuario sin privilegios | **PASS** — corre como `hermes` (uid 10000) |
| OpenAI sin exponer la clave | **PASS** — reutiliza la del backend |
| Laravel puede invocarlo | **PASS** — razonamiento end-to-end verificado |
| Timeout, fallback y circuit breaker | **PASS** — `HermesResponderTest` (14) |
| **Encendido** | **NO** — inerte a propósito, ver abajo |

### 8. Seguridad del agente — **PASS** (19 pruebas adversariales)

8 intentos de prompt injection · ningún mensaje activa una membresía · una
conversación tomada por una persona **no** recibe respuestas automáticas ni con
el cliente insistiendo · opt-out respetado · dos asesores a la vez sin perder
respuestas · cerebro caído sin perder el mensaje · nunca inventa un precio.

### 9. Despliegue — **PASS**

Backup verificado (1475 objetos) · 5 migraciones aditivas · cachés · workers
reiniciados · frontend con respaldo previo de 54 MB · nginx recargado · smoke
tests correctos · **todos los flags apagados**.

---

## El hallazgo que cambia una recomendación

Hermes antepone su propio andamiaje a cada prompt. Medido en el servidor:

```
prompt_tokens: 14 351      completion_tokens: 52
```

Catorce mil tokens de entrada para «¿cuánto vale la mensualidad?». Con el límite
de 30 000 TPM de la cuenta de OpenAI eso son **dos clasificaciones por minuto**
antes de los rechazos, reproducido tres veces:

```
Rate limit reached for gpt-4.1 ... (TPM): Limit 30000, Used 20238, Requested 14553
```

**Hermes no puede ser hoy el cerebro principal del canal.** Un sábado con diez
personas escribiendo lo tumbaría. Queda instalado, verificado e inerte; OpenAI
directo sigue siendo el responder efectivo y hace lo mismo por una fracción.

Encenderlo exige subir el tier de OpenAI o recortar el andamiaje de Hermes.
Ninguna de las dos es una decisión técnica que corresponda tomar aquí.

## Riesgos y observaciones abiertas

1. **El canal nunca se ha ejercitado contra Meta real.** `META_ENABLED=false`
   desde antes de este trabajo. Todo está cubierto con dobles fieles a la forma
   de Cloud API, pero el primer mensaje real sigue siendo una verificación
   pendiente.
2. **~50 respaldos de `.env` en `/var/www/api/backend/`**, varios con permisos
   `644`, conteniendo credenciales vivas (Meta, Wompi producción, Factus,
   OpenAI). No son accesibles por web —el docroot es `public/`— pero cualquier
   usuario del sistema puede leerlos. **No se han tocado**: borrarlos es
   destructivo y es una decisión del propietario.
3. **Los 4 fallos de la línea base** dependen de la fecha del sistema. Hoy pasan
   solos. Siguen siendo frágiles y están fuera del alcance autorizado.
4. **El simulador dejó datos en producción**: lead y conversación del número
   ficticio `573001112299` (conversación 22, mensaje 160). Es un prospecto
   claramente simulado; se puede borrar cuando se quiera.

## Commits

| Hash | Qué entra |
|---|---|
| `17ccbae` | Persistencia del evento crudo, superficie completa de mensajes, capa de medios, reconciliación de estados, logs estructurados |
| `6dbb6e8` | URLs firmadas de adjuntos, retención, rescate de eventos atascados |
| `85a61da` | Outbox con reintentos, sin doble envío, plantillas y adjuntos salientes |
| `a37bd20` | Hermes instalado, aislado e inerte + cortacircuitos |
| `a5c299c` | IRON GUARD: detección determinista, agrupamiento, panel, remediación |
| `00eb771` | Suite adversarial (injection, takeover, concurrencia) |
| `9366134` | Frontend: adjuntos, estados y errores en el inbox (repo `Iron-Body_Front`) |

## Único paso manual pendiente

El registro del número real en Meta mediante OTP **no se ha ejecutado**: es una
acción irreversible sobre el número productivo y está fuera de lo autorizado.
Ver el procedimiento exacto en el informe de entrega.

---

## Fase A — Eventos reales conectados al motor

Commits: `3801fcf` (correcciones) · `991ac34` (alta de hechos) · `883ff30` (ciclo).
Suite: **1899 pasan, 0 fallan** (+26). Desplegado con **todos los flags apagados**.

### 19. Cableado de hechos — **ENTREGADO**

Los 24 hechos del encargo quedan conectados. Decisión de diseño central: se
observan **tablas**, no servicios.

La razón es que un pago se aprueba por cuatro caminos —webhook de Wompi,
reconciliación cada 5 minutos, cobro recurrente y push de Nequi—. Enganchar en
cada servicio obligaría a acordarse de los cuatro y del quinto que se escriba
más adelante. El observer escucha donde queda el resultado, así que ninguno se
escapa, y **no se tocó ni una línea de pagos ni de facturación**, que el encargo
marca como zona intocable.

| Hecho | Origen observado |
|---|---|
| lead creado / calificado / pide humano | `marketing_leads` |
| enlace de pago generado | `payment_transactions.created` con `checkout_url` |
| pago aprobado / pendiente / rechazado / expirado | `payment_transactions.status` (solo al ENTRAR en el estado) |
| membresía creada / renovada | `users.membership_end_date` |
| app vinculada | `members.user_id` (null → valor) |
| cita creada / completada | `marketing_appointments` |
| primera asistencia / hito | `attendances` (entradas distintas por día) |
| factura solicitada / validada / rechazada | `electronic_invoices` (**solo lectura**) |
| atención humana | `marketing_conversations.human_takeover` |
| vencimiento próximo / vencida / inactividad / enlace abandonado | `commercial:scan` (cada hora) |

**Idempotencia.** Cada hecho lleva una clave construida con *lo que pasó*
(«pago 4821 aprobado»), no con *cuándo se observó*. La reconciliación puede ver
la misma aprobación veinte veces y produce un evento. Índice único en
`commercial_events.dedupe_key`; la violación de unicidad es el árbitro cuando
dos procesos coinciden.

**El recorder nunca lanza.** Corre dentro de la transacción de un cobro. Perder
un evento comercial es un problema menor; revertir un pago real por un fallo del
motor sería inaceptable.

### 20. Ciclo de decisión — **ENTREGADO**

`EvaluateCommercialSubject` (job, `afterCommit`) ejecuta, en este orden:

1. **Reconciliar** — cerrar lo que los hechos ya dan por cumplido.
2. **Evaluar** — elegir el objetivo siguiente.
3. **Marcar evaluado** — al final, para que un fallo deje el hecho pendiente.

El orden no es arbitrario: al revés, el motor volvería a proponer el objetivo
recién conseguido y la persona recibiría un recordatorio de un pago que ya hizo.

Cerrar en el paso 1 es lo que hace ejecutable el principio del módulo: *ninguna
venta termina la relación comercial*. Probado — tras cerrar el cobro aparece un
objetivo nuevo y distinto.

Distinciones que el reconciliador respeta:
- **Bloqueada ≠ perdida.** Quien pidió hablar con una persona no fracasó, se
  apartó; su caso tiene que poder retomarse y no debe ensuciar la conversión.
- Una renovación **no** se da por ganada al abrirse: la membresía vigente que se
  quiere renovar es justo la que haría verdadera la condición.

**Concurrencia:** candado por *persona*, no por evento; el lead convertido y su
ficha de miembro comparten clave.

### 21. Tres defectos corregidos del motor anterior

Los encontró la prueba del ciclo al construir el sujeto contra datos reales en
lugar de a mano. Los tres estaban en código ya desplegado:

1. Las asistencias se filtraban por `action='in'`; la columna es
   `enum('entry','exit')`. La consulta no coincidía nunca: **todo socio parecía
   no haber pisado el gimnasio**, y sin visitas nadie alcanza el umbral de uso,
   así que la mejora de plan no se habría ofrecido jamás.
2. `CommercialSubject` lanzaba `TypeError` en cuanto un socio tenía membresía
   vigente (`Carbon\Carbon` vs `Illuminate\Support\Carbon`). Ocurría fuera del
   `try`, así que **se llevaba por delante la evaluación entera**.
3. La fecha de alta solo se leía de `membership_subscriptions`, que no existe
   para quien paga de una sola vez —la mayoría—, dejando nulo `days_as_member`.

### 22. Flags

`COMMERCIAL_EVENTS_ENABLED` es **independiente** de `COMMERCIAL_NBA_ENABLED`.
Observar es inofensivo: permite registrar hechos durante semanas y comprobar que
se detectan bien y a tiempo, antes de dejar que alguien decida sobre ellos.
Ambos apagados en producción.

### 23. Pendiente tras la Fase A

| Área | Estado |
|---|---|
| Herramientas operativas tipadas (Fase B) | **TODO** — siguiente paso |
| Recorridos E2E 1–10 (Fase C) | **TODO** |
| Inbox V2 (Fase D) | **TODO** — bloqueado por B y C |
| Supervisión y métricas (Fase E) | **TODO** |
| Verificación visual (Fase F) | **TODO** |

El motor ya **decide** y los eventos ya lo **automatizan**. Falta que las
herramientas **ejecuten**: hoy una decisión no se convierte en una acción real.

---

## Fase D — Inbox V2

Commits: `a0c35bb` (API de contexto) · `2f2f3d2` (frontend) · `2f73a01` (submódulo).
Backend **1952 pasan / 4 fallan** · Frontend **56 pasan** · Build de producción correcto.
Desplegado con `whatsappInboxV2Enabled = false`.

### 30. Wireframe implementado

```
┌──────────────────┬───────────────────────────────┬──────────────────┐
│ Conversaciones   │ Conversación                  │ Contexto         │
│ ── buscador fijo │ ── cabecera fija              │ ── 5 pestañas    │
│ ── 6 filtros     │ ── aviso de estado            │    Cliente ·     │
│ ── lista scroll  │ ── hilo con SCROLL PROPIO     │    Comercial ·   │
│    propio        │    · ancla al cargar arriba   │    Operación ·   │
│ ── máx. 2        │    · no salta con adjuntos    │    Actividad ·   │
│    indicadores   │    · aviso de nuevos          │    Técnico       │
│    por fila      │ ── compositor fijo            │ ── colapsable    │
└──────────────────┴───────────────────────────────┴──────────────────┘
```

### 31. Componentes

| Archivo | Qué resuelve |
|---|---|
| `inbox-v2-page.ts` | Contenedor de tres columnas, estado, envío, control humano |
| `conversation-list.ts` | Lista, filtros, búsqueda con retardo, paginación |
| `chat-thread.ts` | Hilo, autores, adjuntos, estados de entrega, fallos |
| `context-panel.ts` | Diez bloques en cinco pestañas |
| `scroll-anchor.ts` | Los tres cálculos de scroll, puros y probados |
| `inbox-theme.css` | Identidad Iron Body en variables CSS |
| `inbox-preview.ts` | Banco de pruebas visual (no se enruta en producción) |

### 32. APIs consumidas

Todas preexistentes salvo una añadida en esta fase:

`GET conversations` · `GET conversations/{id}` · `GET capabilities` ·
`POST messages` · `POST takeover` · `POST release` · `GET attachments/{id}/link` ·
**`GET conversations/{id}/context`** (nueva, solo lectura, 15 pruebas).

### 33. Verificación visual — capturas inspeccionadas

| Resolución | Resultado |
|---|---|
| 1920×1080 | **PASS** |
| 1440×900 | **PASS** |
| 1366×768 | **PASS** |

Medido en el navegador, no supuesto: `bodyScroll = no`, `rows: SCROLL`,
`stream: SCROLL`. La página no se desplaza y cada columna gestiona su espacio.

### 34. Defectos que encontraron las capturas

1. **Filas de 136 px con el avatar encima del texto.** Un `<button>` encierra su
   contenido en una caja anónima, así que `display:flex` en el botón **no**
   convierte a sus hijos en elementos flexibles. El flex bajó a un envoltorio
   interno. El estilo computado decía `display:flex` y aun así apilaba: sin
   medir en el navegador esto no se encuentra.
2. **La quinta pestaña del panel se cortaba** contra el borde en las tres
   resoluciones. Acortar el texto no bastó; ahora envuelven.
3. **El build de producción fallaba** con un error que apuntaba a otro sitio.
   Causa: una comilla invertida dentro de un comentario CSS, que parte el
   literal de plantilla. Ocurrió dos veces.

### 35. Matriz PASS/FAIL del Inbox V2

| Requisito | Estado |
|---|---|
| Tres columnas | **PASS** |
| La página no se desplaza | **PASS** (medido) |
| Scroll independiente por columna | **PASS** (medido) |
| Cabecera y compositor fijos | **PASS** |
| Conservación de posición al cargar arriba | **PASS** (12 pruebas) |
| Sin salto con adjuntos de altura tardía | **PASS** (ResizeObserver + pruebas) |
| Autoscroll solo cerca del final | **PASS** |
| Aviso y botón de volver al final | **PASS** |
| Autores diferenciados sin depender del color | **PASS** |
| Estados de entrega con explicación y reintento | **PASS** |
| Enter envía · Shift+Enter salta | **PASS** |
| Prevención de doble envío | **PASS** |
| Ventana de 24 h explicada | **PASS** |
| Pausar IA al responder | **PASS** |
| Panel derecho colapsable con pestañas | **PASS** |
| Diagnóstico solo con permiso | **PASS** |
| Acciones no autorizadas mostradas como estado | **PASS** |
| Máx. 2 indicadores por fila | **PASS** |
| Esqueleto, vacío, error, sin selección | **PASS** |
| Responsive < 900 px (drawer y navegación) | **PARCIAL** — implementado, **no verificado visualmente** |
| Carga incremental hacia arriba | **NO** — el backend no pagina el detalle |
| Adjuntar archivos desde el compositor | **NO** — el backend solo acepta texto |
| Tiempo real sin recarga | **NO** — hoy recarga tras cada acción |

### 36. Pendiente real para Fases E y F

- **Fase E**: panel de supervisión y métricas económicas.
- **Fase F**: verificación visual del responsive, accesibilidad, concurrencia
  entre operadores en la interfaz, y matriz final.
- **Backend que falta** para completar el Inbox V2: paginación del detalle
  (`before_id`), envío de adjuntos, y transporte de tiempo real.

Los 4 fallos del backend son los **mismos de la línea base**
(`MembershipCancellationTest` ×3, `AppStateRealtimeTest` ×1): dependen de la
hora del sistema y rompen entre las 19:00 y las 24:00 locales, cuando UTC ya
cambió de día. **Comprobado que no los causa este trabajo**: desactivando por
completo los observers comerciales, fallan exactamente igual.

---

## Estado a 8 de agosto de 2026 — Fases D.2 y E cerradas

**Suites:** backend 2.164 · frontend 107 + 13 de contrato · **0 fallos.**
Los 4 fallos históricos de zona horaria quedaron corregidos en D.2.9.

### Cerrado desde la entrada anterior

| Fase | Qué quedó | Commit |
|---|---|---|
| D.2.5/6 | Adjuntos salientes y notas de voz | `6653b9f`, `02026aa` |
| D.2.7 | Historial paginado por cursor | `f0f7ca2` |
| D.2.8 | Rendimiento medido y optimizado | `b2e1046`, `0221090` |
| D.2.3 | Contexto de atribución para el agente | `3dba517` |
| D.2.4 | Analítica de pautas, panel e insights | `e00baeb`, `81e412c`, `59bdb9f` |
| E | Centro de supervisión, aprobaciones, alertas | `03d1f0e`, `6ecea61` |
| UX | Compositor de barra única | `bfa3bb1` |

### Defectos reales encontrados midiendo, no mirando

Se dejan escritos porque los cinco pasaban las comprobaciones que había:

1. **Cursor con microsegundos** — repetía el mensaje del borde en SQLite y no
   en PostgreSQL. Habría aparecido en producción como mensajes duplicados.
2. **N+1 en la bandeja** — 36 consultas para 20 filas; 20 eran la misma.
3. **El índice de trigramas existía y no se usaba** — `whereHas` hacía 5.004
   escaneos. Invertido con `DISTINCT`: 173 ms → 44 ms.
4. **`iron_guard.auto_remediation` no existe como clave** — el panel mostraba
   «Apagada» y acertaba por casualidad.
5. **El compositor heredaba `height: 100%`** de la hoja compartida y dejaba el
   hilo en cero píxeles; la columna recortaba lo que sobraba y las capturas
   parecían correctas.

Cada uno tiene hoy una prueba o un verificador que falla si vuelve.

### Afirmaciones que corregí

- Dije que **no existía entidad de incidentes**. Era falso: `Incident`,
  `IncidentRecorder` y el detector ya existían. Lo que faltaba era vigilar
  Wompi, Factus y almacenamiento, y enseñarlo en supervisión.
- Dije que la Fase D estaba **desplegada** habiendo desplegado solo el backend.

### Estado de producción

`META_ENABLED`, `MARKETING_AGENT_ENABLED`, `COMMERCIAL_AUTONOMY_ENABLED`,
`HERMES_ENABLED` y la remediación automática: **todos en `false`**. Número real
sin registrar. Cero mensajes reales y cero operaciones financieras de prueba.

### Lo que falta

**Fase F**: verificación integral pre-Meta —50 flujos E2E, concurrencia,
inyección de fallos, seguridad, accesibilidad y matriz final—. El proyecto
**no está terminado** hasta cerrarla.

### Fase F — parcial

Cubierto y verificado: baseline, suites completas (2.182 backend · 107 + 13
frontend · builds), concurrencia (5 carreras), seguridad (13 comprobaciones),
validación visual en **producción real** a 4 resoluciones sobre Inbox,
Analítica y Supervisión, rendimiento comparado, y el checklist de activación en
Meta documentado sin ejecutar.

**Sin cubrir**: simulación del ciclo comercial largo (F.9), ensayo de vuelta
atrás (F.12) y el repaso completo de documentación (F.13).

**El proyecto NO está PRE-META READY.** Falta cerrar esos tres puntos.

### F.6 — inyección de fallos · PASS

52 escenarios sobre los 45 exigidos, 0 FAIL. Cada uno rompe una dependencia de
verdad —timeout, 429, 5xx, JSON ilegible, disco caído, deadlock, worker muerto—
y sigue el rastro completo: evento → persistencia → job → servicio → fallo →
reintento → auditoría → incidente → recuperación.

**Cinco fallos reales encontrados y corregidos**, todos de la misma familia: el
sistema afirmaba por escrito algo que nadie había comprobado.

1. **Un timeout de Wompi enterraba cobros reales.** El POST sin respuesta se
   sellaba como `error`, que es TERMINAL; el webhook posterior con la
   aprobación no podía mover el estado. Cliente cobrado, sin membresía, sin
   rastro. Ahora un desenlace desconocido queda `pending` y lo resuelven el
   webhook o la reconciliación.
2. **Doble cobro al reintentar tras ese timeout.** Sin `client_request_id` de
   la app, el segundo intento abría una transacción nueva mientras la primera
   podía estar viva en Wompi.
3. **Adjuntos marcados `stored` con el disco caído.** No se miraba el resultado
   de la escritura: el inbox enseñaba un comprobante inexistente y la descarga
   no se reintentaba porque ya constaba guardada.
4. **Facturas `validated` sin CUFE ni número.** Un 201 con el cuerpo incompleto
   —o vacío— daba por bueno un documento indefendible ante la DIAN, y
   `validated` es terminal.
5. **La IA hablaba después del humano.** Una respuesta suya atascada en la cola
   de reintentos salía igual aunque una persona hubiera tomado la conversación.

Medido además el reparto de capacidad: **un solo worker** atiende la cola
`default` (entrantes, agente, multimedia, eventos comerciales, alertas);
`billing` tiene el suyo. Con el agente apagado sobra. Con el agente encendido
el rendimiento cae de 4,46 a 0,52 trabajos/s y una ráfaga de 50 mensajes deja
al último esperando ~97 s. Hay propuesta de separación de colas con números,
sin ejecutar.

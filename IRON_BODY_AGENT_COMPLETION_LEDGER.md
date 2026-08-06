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
| Suite backend | **1793 pasan / 0 fallan** (11 413 aserciones, 77 s) |
| Pruebas añadidas | **+153** |
| Build Angular | Correcto (avisos de presupuesto CSS preexistentes, en archivos no tocados) |
| Los 4 fallos de la línea base | Pasan hoy **solos**. Eran sensibles a la fecha: no se tocó nada del módulo de membresías. Siguen siendo frágiles. |

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

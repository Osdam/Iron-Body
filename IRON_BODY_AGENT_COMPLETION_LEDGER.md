# IRON BODY — Ledger del canal propio de WhatsApp

Estado vivo del trabajo para operar el número oficial de Iron Body Neiva
(+57 314 345 5483) con Meta WhatsApp Cloud API, sin BSP y sin Baileys.

Este archivo no es un resumen de intenciones: cada fila dice qué se comprobó,
qué se cambió y qué prueba lo respalda. Una fila solo pasa a **PASS** cuando hay
una prueba ejecutada que lo demuestra.

---

## Línea base (2026-08-05, antes de tocar nada)

| Dato | Valor |
|---|---|
| Suite backend | **1640 pasan / 4 fallan** (10 976 aserciones, 154 s) |
| Fallos previos | `MembershipCancellationTest` (3) y `AppStateRealtimeTest` (1) |
| Naturaleza de esos fallos | Dominio de **membresías**, sensibles a fecha. Fuera del alcance de este trabajo y dentro de lo explícitamente prohibido de tocar. No se han corregido; se vigila que no aumenten. |
| Rama | `main` (limpia salvo el submódulo `frontend`, con trabajo ajeno sin relación) |
| Commit de partida | `3c0dbf0` |

**Recursos del VPS medidos antes de decidir arquitectura** (`srv1728633`):
4 vCPU · 15,9 GB RAM (14,2 GB disponibles) · 194 GB disco con 184 GB libres ·
Docker 29.5.3 con n8n y su Postgres · sin Redis · Postgres 14 en loopback.

Dos decisiones salen de esa medición y quedan justificadas por ella:

- **Almacenamiento de medios: disco local privado, no MinIO.** Hay sitio de
  sobra y un solo gimnasio. Un contenedor MinIO añadiría RAM, backup propio y
  superficie de ataque para resolver una escala que este negocio no tiene. La
  abstracción está puesta: `WHATSAPP_MEDIA_DISK` apunta a un disco S3 el día que
  haga falta y ninguna otra capa se entera.
- **Observabilidad: logs JSON + tablas, no Prometheus/Grafana/Loki.** La pila
  completa cuesta más RAM y mantenimiento que el valor que da en un gimnasio.
  Los logs ya salen en el formato que Loki ingiere sin cambios si algún día se
  justifica.

---

## Matriz

Leyenda: **PASS** verificado con prueba · **WIP** en curso · **TODO** sin empezar
· **BLOQUEADO** requiere una acción externa.

### 1. Meta y webhook

| Área | Estado inicial | Cambio realizado | Prueba | Estado |
|---|---|---|---|---|
| Verificación GET (challenge) | Ya existía | Sin cambios | `MetaWebhookTest` | **PASS** |
| Firma HMAC POST válida / inválida | Ya existía | Se refuerza: una firma inválida ya no deja **nada** guardado | `InboundWebhookTest::…invalid_signature_is_rejected_and_never_persisted` | **PASS** |
| **Persistencia del evento crudo** | **No existía**: el payload iba directo a la cola; si el worker moría, el mensaje del prospecto se perdía sin rastro | Tabla `meta_webhook_events` escrita de forma síncrona dentro del request, con estado, intentos y último error | `WebhookDurabilityTest::…survives_even_if_no_worker_ever_runs` | **PASS** |
| Respuesta rápida (200) | Ya existía | Se mantiene; ninguna lógica de IA corre en el request | `WebhookDurabilityTest` | **PASS** |
| Duplicados / reentregas | Solo por `meta_message_id` | Primera barrera por SHA-256 del cuerpo (`payload_hash` único) | `…redelivering_the_same_body_does_not_duplicate_work` | **PASS** |
| Protección de replay | **No existía** | Un POST firmado capturado y reenviado no vuelve a ejecutarse | `…replaying_a_captured_signed_body_changes_nothing` | **PASS** |
| Límite de tamaño | **No existía** | Techo de 512 KB, rechazado antes de persistir | `…absurdly_large_body_is_refused` | **PASS** |
| Payloads múltiples (varias entry/changes) | El log solo miraba la primera | Se recorren todas; 3 mensajes en un POST llegan los 3 | `…every_entry_and_change_in_one_post_is_processed` | **PASS** |
| Status callbacks | Se guardaban con UPDATE ciego | Reconciliación por rango + historial completo | `MessageStatusReconciliationTest` (10) | **PASS** |
| Errores de Meta visibles | **No existía** | Código, título y detalle de Meta guardados junto al mensaje y en el historial | `…failure_keeps_metas_error_code_where_the_inbox_can_read_it` | **PASS** |
| Recuperación de un evento fallido | **No existía** | Un evento a medias se reprocesa sin duplicar | `…replaying_a_failed_event_recovers_it_without_duplicating` | **PASS** |
| Pruebas de contrato con payloads reales | Parcial | Ampliado a toda la superficie de Cloud API | `InboundMessageSurfaceTest` (15) | **PASS** |

### 2. Superficie de mensajes entrantes

| Área | Estado inicial | Cambio realizado | Prueba | Estado |
|---|---|---|---|---|
| Texto | Soportado | Sin cambios | `InboundWebhookTest` | **PASS** |
| Respuestas interactivas (botón / lista) | **Se descartaban como "no soportado"** | El título elegido se lee como lo que la persona dijo | `…button_reply_is_read_as_what_the_person_said` | **PASS** |
| Botón de plantilla | No soportado | Reconocido | `…template_quick_reply_button_is_also_readable` | **PASS** |
| Imagen / audio / nota de voz / video / documento / sticker | Registrados sin contenido | Ficha de adjunto + descarga encolada | `InboundMessageSurfaceTest`, `MediaPipelineTest` | **PASS** |
| Pie de foto | Se perdía | Si hay texto legible, se atiende; el archivo sigue su camino | `…image_with_a_caption_is_answered_and_still_stored` | **PASS** |
| Ubicación / contactos / pedido / sistema | No soportados | Normalizados y escalados a humano | `…location_is_kept_and_handed_to_a_human` | **PASS** |
| Reacciones | No soportadas | Se registran y **no** se responden (no es una consulta) | `…reaction_is_recorded_but_never_answered` | **PASS** |
| Respuesta a mensaje citado | Se perdía | `context.quoted_meta_message_id` conservado | `…quoted_reply_remembers_what_it_answered` | **PASS** |
| Referido de anuncio click-to-WhatsApp | Se perdía | Origen del prospecto conservado para atribución | `…click_to_whatsapp_ad_referral_is_preserved` | **PASS** |
| Tipo desconocido / futuro | Riesgo de perder el mensaje | Se guarda y se escala, nunca se descarta | `…type_invented_after_this_code_was_written_is_still_kept` | **PASS** |

### 3. Medios y archivos

| Área | Estado inicial | Cambio realizado | Prueba | Estado |
|---|---|---|---|---|
| Descarga desde Meta | **No existía** | `MetaMediaService` + `DownloadWhatsappMedia` fuera del webhook | `MediaPipelineTest::…genuine_image_is_downloaded_hashed_and_stored_privately` | **PASS** |
| Almacenamiento privado | **No existía** | Disco `whatsapp` privado; binarios nunca en PostgreSQL | idem | **PASS** |
| MIME real vs declarado | **No existía** | Detección por bytes (`finfo`); allowlist estricta | `…file_lying_about_its_type_is_rejected` | **PASS** |
| HTML / SVG / ejecutables | **No existía** | Fuera de la allowlist: nunca llegan al disco | `…html_disguised_as_an_image_never_reaches_the_disk` | **PASS** |
| Límite de tamaño | **No existía** | Doble corte: cabecera y bytes reales | `…file_over_the_size_limit_is_refused_before_being_written` | **PASS** |
| SSRF | **No existía** | Solo HTTPS y hosts de Meta; nunca se pide una URL ajena con el token | `…media_url_outside_meta_is_never_fetched` | **PASS** |
| Path traversal | **No existía** | Nombre aleatorio; el nombre del cliente solo se muestra | `…stored_path_owes_nothing_to_the_client_filename` + 6 casos de saneado | **PASS** |
| Hash SHA-256 y deduplicación | **No existía** | El mismo archivo ocupa disco una vez | `…same_file_twice_is_stored_only_once` | **PASS** |
| Error y reintento acotado | **No existía** | Transitorio reintenta; política rechaza sin reintentar | `…transient_meta_failure_stays_retryable`, `…after_exhausting_attempts_it_stops_retrying` | **PASS** |
| Retención | **No existía** | `expires_at` fijado al guardar (180 días por defecto) | `…genuine_image_is_downloaded…` | **PASS** |
| URL firmada y descarga con permiso | **No existía** | — | — | **TODO** |
| Reproductor de audio / preview en el inbox | **No existía** | — | — | **TODO** |
| Transcripción opcional | **No existía** | Configurada y **apagada**; columnas listas | — | **TODO** |

### 4. Observabilidad (cimientos)

| Área | Estado inicial | Cambio realizado | Prueba | Estado |
|---|---|---|---|---|
| `correlation_id` de punta a punta | **No existía** | Nace en el webhook y sobrevive a la cola vía `Context` | `…whole_thread_shares_one_correlation_id` | **PASS** |
| Logs JSON estructurados | Prosa mezclada en `laravel.log` | Canal `channel.log`, una línea = un JSON con esqueleto fijo | `InboundWebhookTest::…full_thread_from_edge_to_saved_message` | **PASS** |
| Redacción de secretos | **Riesgo**: el webhook logueaba el texto del prospecto | Ningún token ni firma llega al log; teléfonos enmascarados | `LogRedactorTest` (27) | **PASS** |
| Taxonomía estable de eventos | No existía | `meta.webhook.*` (borde), `meta.event.*` (cola), `meta.message.*`, `meta.status.*`, `media.*` | `InboundWebhookTest` | **PASS** |
| Métricas, incidentes y panel IRON GUARD | **No existía** | — | — | **TODO** |

### 5. Pendiente

| Área | Estado |
|---|---|
| Outbox transaccional y envío saliente endurecido (plantillas, interactivos, medios, 429/5xx) | **TODO** |
| Instalación de Hermes Agent en el VPS (perfiles `iron-sales` / `iron-guard`) | **TODO** |
| Cliente Laravel→Hermes con circuit breaker, presupuesto y fallback | Parcial: existe `HermesSalesResponder` inerte; falta endurecerlo | **TODO** |
| IRON GUARD completo (detección, agrupamiento, causa raíz, panel, remediación allowlisted) | **TODO** |
| Inbox Angular: adjuntos, estados, errores, reintento, concurrencia | **TODO** |
| Evaluaciones reproducibles del agente comercial | Parcial: existe `SalesAgentScenariosTest`; falta el arnés de evaluación | **TODO** |
| Inyección de fallos completa | Parcial (medios, duplicados, fuera de orden hechos) | **WIP** |
| Despliegue progresivo y smoke tests en producción | **TODO** |
| Registro del número real en Meta (paso manual del propietario) | **BLOQUEADO** por diseño: es el paso que no se ejecuta sin autorización |

---

## Riesgos abiertos

1. **Los 4 fallos de la línea base siguen rojos.** Son del dominio de membresías
   y están fuera del alcance autorizado. Se vigilan para que no crezcan.
2. **La descarga de medios no se ha ejercitado contra Meta real**, porque
   `META_ENABLED=false`. Está cubierta con dobles de HTTP fieles a la forma de
   Graph API, pero el primer archivo real es una verificación pendiente del
   despliegue.

## Commits

| Hash | Qué entra |
|---|---|
| (pendiente) | Persistencia del evento crudo, superficie completa de mensajes, capa de medios, reconciliación de estados, logs estructurados |

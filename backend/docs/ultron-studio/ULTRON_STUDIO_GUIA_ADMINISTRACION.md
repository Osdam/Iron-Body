# ULTRON STUDIO: guía de administración

**Qué es.** Lo que hace cada pantalla de ULTRON Studio en el CRM, quién puede hacer cada cosa, cómo se crea, aprueba y publica una versión, qué es el canario y qué evidencia exige, qué hace y qué no hace el laboratorio, qué significa cada estado de las integraciones y qué **no** hace el Studio.

**Para quién.** El propietario y el personal que usa el Studio (Super Admin y Administrador). Hace falta conocer el CRM; no hace falta conocer el código. Los comandos de consola de esta guía son solo de lectura y los ejecuta quien opera el servidor; los procedimientos de despliegue están en `ULTRON_STUDIO_DEPLOYMENT_PLAN.md` y la lista de comprobaciones periódicas en `ULTRON_STUDIO_CHECKLIST_OPERATIVO.md`.

**Tipo.** Referencia y uso. No sustituye a los contratos (`ULTRON_STUDIO_F_CONTRACT.md` … `ULTRON_STUDIO_H7_CONTRACT.md`), que mandan sobre esta guía, ni al procedimiento de pruebas con personas reales (`ULTRON_STUDIO_PRUEBAS_FISICAS.md`).

**Fecha:** 02-11-2026. Escrita sobre el backend `5ed9525` (rama `feat/ultron-studio-h7`) y el CRM `16c433c`, y revisada sobre `3fbb155` (F-1 y F-2 y la primera pasada de REV7), leyendo el código: rutas y permisos (`app/Support/Access/AuthorizationMap.php`, `CrmPermission.php`), configuración (`config/ultron_studio.php`), servicios del Studio y las pantallas del CRM (`src/app/modules/ultron-studio/`). Cada cifra lleva su fuente.

---

## 1. Lo primero que hay que saber

1. **Nada de lo que se hace en el Studio cambia lo que ULTRON dice o hace.** Publicar una versión es una **declaración firmada** de cómo está configurado ULTRON; el motor solo la anota en cada turno (el «sello»), y hoy, con su llave en `off`, ni siquiera la lee. Encender o apagar ULTRON, el cobro, la pausa, el freno, los planes, la base de conocimiento y los prompts de n8n se gobiernan fuera del Studio (contrato H5, opción A).
2. **Lo que se crea no se borra.** Versiones, el libro de auditoría, la cadena de publicaciones, las ejecuciones y veredictos del laboratorio, las asociaciones de campañas, los números canarios y las instantáneas de configuración son de solo inserción o tienen transiciones controladas. Las reglas y los escenarios se archivan. El borrador es lo único que se reescribe.
3. **Cuatro ojos.** Nadie aprueba, publica ni promueve lo que creó, y nadie autoriza ni activa un número canario que registró. El control compara **cuentas**: una misma persona con dos cuentas lo burlaría, así que es una regla organizativa que el propietario tiene que hacer cumplir.
4. **Sin datos personales en texto libre.** Ni nombres de socios o prospectos, ni teléfonos, ni correos, en etiquetas, notas, motivos, descripciones, condiciones o escenarios: quedan en libros que no se pueden corregir (Ley 1581). Usa textos como «Prueba 1: …».
5. **Si no hay botón, el motivo está escrito.** Los botones salen de lo que el servidor permite a tu cuenta en ese momento; cuando algo no se puede hacer, la pantalla lo dice en texto.

## 2. Estado en producción (02-11-2026)

| Pieza | Estado | Qué significa para quien usa el Studio | Fuente |
|---|---|---|---|
| Producción | Backend `1e5f9a0`, CRM `16c433c` | — | `ULTRON_STUDIO_MASTER_ROADMAP.md`, registro de despliegues |
| Hito 3 (F y G) | Desplegado | Borrador, versiones, reglas con condiciones, libro, lecturas del motor y comparación | Hoja de ruta |
| Hito 4 (laboratorio) | Fase 3 desde el 02-11-2026: modo `openai` con `gpt-6.1-terra`, 2 US$ al día y 0,50 US$ por ejecución | Las ejecuciones gastan dinero real, dentro de esos topes. Lanzar solo lo puede el Super Admin por defecto | `ULTRON_STUDIO_FINAL_REPORT.md`, «Hito 4: fase 3» |
| Hito 5 (publicación) | Fase 1: llave del motor en `off` y publicar apagado | Se puede crear, aprobar y probar versiones; **publicar y promover responden 409**: el CRM no ofrece el botón y escribe el motivo, en la versión y en la barra de la publicación. Revertir y volver a Legacy funcionan siempre | `ULTRON_STUDIO_DEPLOYMENT_PLAN.md`, «Hito 5» |
| Hito 6 (integraciones) | Fase 1: escrituras, sonda y voz apagadas | Se ve todo; **asociar campañas, registrar números canarios y la comprobación de Twilio responden 409** hasta la fase 2. Reconocer la configuración no depende de esa bandera | `ULTRON_STUDIO_DEPLOYMENT_PLAN.md`, «Hito 6» |
| Hito 7 (seguridad y producción) | Implementado en la rama, **sin desplegar** | Ver §11 | `ULTRON_STUDIO_FINAL_REPORT.md`, «Hito 7» |

[PENDIENTE: actualizar esta tabla cuando se despliegue el Hito 7 y cuando el propietario autorice las fases 2 del H5 y del H6 (lead).]

## 3. Quién puede hacer qué

El CRM tiene **105 permisos**; 12 son del Studio (`ultron.*`). Lo comprobado el 02-11-2026 con `PermissionCatalog::all()` y `CrmPermission::byRole()` sobre `5ed9525` y otra vez sobre `3fbb155`, con el mismo resultado (102 derivados de las rutas más 3 alias antiguos de caja):

| Llave | Qué abre | Super Admin | Administrador | Recepción · Entrenador · Administrativo |
|---|---|---|---|---|
| `ultron.view` | Ver el Studio, el laboratorio y las integraciones; validar en el servidor; estimar una ejecución; leer la evidencia del canario y el historial | sí | sí | no |
| `ultron.edit_draft` | Guardar y descartar el borrador, crear versiones y enviar la propia a revisión | sí | sí | no |
| `ultron.manage_rules` | Crear, editar y archivar reglas del Studio | sí | sí | no |
| `ultron.approve` | Aprobar o rechazar versiones ajenas y emitir el veredicto del laboratorio | sí | sí | no |
| `ultron.lab_manage` | Crear, editar y archivar escenarios del laboratorio | sí | sí | no |
| `ultron.lab_run` | Lanzar y cancelar ejecuciones del laboratorio (gastan presupuesto) | sí | **no** | no |
| `ultron.publish` | Publicar en canario y promover a todas (versiones ajenas) | sí | **no** | no |
| `ultron.rollback` | Revertir una versión y volver a Legacy | sí | **no** | no |
| `ultron.campaigns_manage` | Asociar y desactivar campañas de Meta | sí | sí | no |
| `ultron.canary_manage` | Registrar, activar, desactivar y revocar números canarios y fijar su versión esperada | sí | sí | no |
| `ultron.canary_authorize` | Autorizar números canarios de otra persona | sí | **no** | no |
| `ultron.integrations_manage` | Reconocer la configuración de las integraciones y la comprobación de Twilio | sí | **no** | no |

- **Son los valores por defecto.** El propietario los cambia por rol en Configuración › Roles; el servidor vuelve a comprobar la llave en cada petición.
- **El token compartido** (automatizaciones) no recibe ninguna llave `ultron.*`: el Studio exige una persona.
- **El submenú** Mercadeo › ULTRON Studio (con la etiqueta «preview») solo aparece con `ultron.view`. Sin ella, la página dice «Tu cuenta no puede abrir ULTRON Studio» y qué permiso falta.

## 4. Las pantallas

Todo está en `https://ironbodyneiva.cloud/marketing/ultron-studio` y sus dos páginas hijas, `…/laboratorio` e `…/integraciones`. Las tres piden `ultron.view`.

### 4.1 El tablero (`/marketing/ultron-studio`)

| Elemento | Qué es |
|---|---|
| «Qué se ve en el tablero» | El selector: «Mi borrador» (lo único editable), la línea base del código (solo lectura) y cada versión con su estado |
| Aviso bajo la cabecera | «El motor de ULTRON solo anota la versión publicada: guardar, crear, aprobar o publicar versiones no cambia lo que ULTRON dice ni hace.» |
| Chip del motor y «lecturas» | Lo que lee hoy el motor (solo lectura, sin secretos). El botón vuelve a leerlo |
| Barra de la publicación | «Declaración vigente» (Legacy, el canario y el resto, o «En todas»), «Llave del motor» y «Sello». Si lo declarado no coincide con el motor, dice «La declaración vigente no dice lo mismo que el motor. No se revierte sola: se enseña aquí y bloquea promover». Botones «Historial» y, con `ultron.rollback`, «Volver a Legacy» |
| Inspector | Las diez capas: qué implementa hoy cada una, qué parámetro tiene mando real y dónde se cambia de verdad |
| «Validar» | Revisa el grafo y la configuración del borrador; el número es el de bloqueos |
| «Guardar borrador» · «Descartar» | El borrador vive en el servidor, uno por persona. Descartar no borra la fila |
| «Crear versión» | Congela el borrador como versión inmutable (ver §5) |
| Paneles «Versiones», «Reglas» y «Auditoría» | Se abren a la derecha |
| Enlaces «Laboratorio» e «Integraciones» | Las dos páginas hijas |

**Panel «Versiones».** Cada versión con su estado y sus acciones. Los estados, con el rótulo que usa el servidor (`StudioVersionStatus`):

| Estado | Rótulo | Qué significa |
|---|---|---|
| `DRAFT` | Creada, sin enviar | Congelada, todavía no está en revisión |
| `VALIDATED` | Validada, espera revisión | Enviada sin avisos |
| `REVIEW_REQUIRED` | Con avisos · espera revisión | Enviada con avisos: quien apruebe tiene que reconocerlos uno a uno |
| `APPROVED` | Aprobada | Lista para el laboratorio y para publicarse |
| `REJECTED` | Rechazada | Terminal; queda el motivo |
| `CANARY` | Canario | Publicada solo para la conversación canaria (como mucho una a la vez) |
| `PUBLISHED` | Publicada | Declarada para todas las conversaciones (como mucho una) |
| `RETIRED` | Sustituida | La sustituyó otra publicación o se volvió a Legacy |
| `ROLLED_BACK` | Revertida | Terminal: para volver a publicarla hace falta otra versión |

Acciones (cada una solo si el servidor la permite a tu cuenta): «Enviar a revisión», «Aprobar», «Rechazar», «Abrir en el tablero», «Probar en el laboratorio» (deshabilitada si la huella de la versión no coincide), comparar con otra versión, «Publicar en canario», «Promover a todas» y «Revertir». Debajo, la parte de la cadena de publicaciones que toca a esa versión.

**Panel «Reglas».** Las 12 reglas del código (R-01 a R-12, la línea base `LEGACY_BASELINE_V1`) se ven en solo lectura. Las del Studio (S-NNN) admiten condiciones verificables («si… entonces…»), y el servidor rechaza las que contradicen un control del motor u otra regla. **Ninguna regla S-NNN se aplica en el motor:** son parte de lo que una versión declara.

**Panel «Auditoría».** El libro de solo inserción: quién hizo qué y cuándo, sin IP ni navegador. Los eventos del borrador de otras personas solo los ve quien tenga `audit.view` (Super Admin por defecto).

### 4.2 El laboratorio (`/marketing/ultron-studio/laboratorio`)

Dos pestañas: **«Escenarios»** (los 11 del catálogo y los del personal) y **«Ejecuciones»** (lanzar, seguir el progreso, ver cada caso, emitir el veredicto). Siempre a la vista: «Simulación del Studio · no es ULTRON» y el presupuesto del día. Qué hace y qué no, en §7.

### 4.3 Integraciones (`/marketing/ultron-studio/integraciones`)

Cinco pestañas: **«Estado»**, **«Campañas de Meta»**, **«Números canarios»**, **«Twilio»** y **«Voz»**. Siempre a la vista: «Registro del Studio: no cambia lo que ULTRON dice o hace». Los estados, en §8.

## 5. Cómo se crea, aprueba y publica una versión

**Quién:** la autora (persona A) y al menos otra persona (B). Las cuentas temporales de smoke no hacen nunca de A ni de B.

1. **A prepara el borrador** en el tablero («Mi borrador»), lo valida («Validar») y lo guarda («Guardar borrador»).
2. **A crea la versión** («Crear versión», `ultron.edit_draft`). Con bloqueos de validación, el servidor responde 422 `validation_blocked` y no crea nada.
3. **A la envía a revisión** («Enviar a revisión»; solo quien la creó). Queda «Validada, espera revisión» o «Con avisos · espera revisión».
4. **B la aprueba o la rechaza** (`ultron.approve`, B ≠ A). Si tiene avisos, B los reconoce uno a uno; si A lo intenta, 403 `four_eyes_required`.
5. **Laboratorio** («Probar en el laboratorio»; §7): una ejecución sobre esa versión y un **veredicto de una persona distinta de la autora**. Con la puerta del laboratorio en `required` (la de producción), publicar exige un veredicto `approved` sobre **la misma huella** y la versión de prompts vigente; cuenta el más reciente. Solo el propietario puede dispensarla (`waived`) escribiéndolo en el entorno del servidor, y queda escrito en cada publicación; el 01-11-2026 pidió respetarla.
6. **Publicar en canario** (`ultron.publish`; quien publica ≠ A, puede ser quien aprobó). Solo **de 07:00 a 19:00 de Bogotá** (la ventana es `[07:00, 19:00)`; `ULTRON_STUDIO_PUBLISH_WINDOW`). El diálogo enseña la vista previa, «Lo que declara frente a lo que lee el motor» (los cuatro parámetros con mando real tienen que coincidir con el motor) y «Sin efecto en este hito», con una casilla por elemento que hay que marcar. Toda publicación empieza en el canario.
7. **Promover a todas** (`ultron.publish`, quien promueve ≠ A, dentro de la ventana), solo con la evidencia del canario suficiente (§6) y la llave del motor en `all`.

**Rechazos que se verán** (todos dejan la versión como estaba): fuera de la ventana, 409 `outside_publication_window`; la autora, 403 `four_eyes_required`; sin veredicto válido, 409 `lab_verdict_required`; lo declarado distinto del motor, 409 `engine_value_mismatch`; una lectura del motor caída, 409 `engine_reading_unavailable`; la vista previa cambió, 409 `preview_stale`; lo «sin efecto» sin reconocer entero, 409 `no_effect_not_acknowledged`; el freno echado, 409 `engine_braked`; otra versión en canario, 409 `canary_in_progress`; la publicación apagada (fase 1 del H5, hoy), 409 `publishing_disabled`; promover sin la llave del motor en `all`, 409 `scope_not_allowed`; la cabeza de la cadena no verifica, 409 `publication_head_invalid`; otra persona publicó a la vez, 409 `publication_moved`.

## 6. El canario y su evidencia

- **Qué es el canario.** Una conversación de WhatsApp concreta, configurada en el servidor (no en el Studio). Una versión publicada «en canario» se declara solo para esa conversación; el resto sigue en lo que tuviera. Con el motor en `canary`, sus turnos quedan sellados con la versión.
- **La evidencia** (`GET versions/{uuid}/canary-evidence`; el CRM la enseña en «Promover a todas») se calcula en el servidor y exige, por defecto (`ULTRON_STUDIO_CANARY_MIN_HOURS` = 24 y `ULTRON_STUDIO_CANARY_MIN_TURNS` = 3; mínimos duros de 1 h y 1 turno):
  1. al menos **24 horas** desde que la versión entró en canario;
  2. al menos **3 turnos** de la conversación canaria;
  3. **todos** sellados con esta versión (`stamped` = `turns`): un turno sellado `fallback` en la ventana impide para siempre promover ese canario;
  4. **ningún freno** desde la entrada y el freno de ahora suelto;
  5. **el acta del canario a cero** (`ultron:canary-report`, salida 0 y total 0);
  6. **sin deriva** entre lo declarado y el motor;
  7. **la firma de la cabeza correcta**.
- **Poco tráfico.** Con unos pocos turnos de ULTRON por semana, reunir 3 turnos sellados puede tardar días: sin turnos no hay promoción, y es lo correcto.
- **El Studio no frena ni revierte solo.** Enseña la deriva, bloquea la promoción y `ultron-studio:publication-check` sale con 1.
- La evidencia y la promoción comparten un límite de 10 consultas por minuto y persona y 30 por minuto y red.
- **Cuánto tarda:** con el código de producción de hoy (`1e5f9a0`), la evidencia tarda alrededor de 1 s con 200 turnos analizados y unos 4 s con 1 000 (medido en local, PERF7 F-1). **Con el Hito 7 desplegado**, unos 70 ms con 200 turnos y menos de 100 ms con 1 000 (p95 medido en local), con el mismo resultado byte a byte: es la corrección F-1 (`e2411e3`; `plans/informes-h7/informe-backend.md`, §F-1).

## 7. El laboratorio: qué hace y qué NO hace

**Qué hace.** Prueba una versión (o tu borrador, o la línea base) contra escenarios **sintéticos** antes de publicarla. Por cada escenario, un **actor de laboratorio** (un modelo de OpenAI con la clave propia del laboratorio) contesta como lo haría un asesor que siguiera esa configuración; los **filtros del motor** (los mismos que usa ULTRON, como funciones de solo lectura) y las comprobaciones del Studio marcan lo que el motor marcaría; un segundo modelo, el **juez**, opina criterio a criterio; el código **propone** (`approve`, `review` o `reject`) y una **persona** emite el veredicto (`approved`, `rejected` o `needs_changes`).

**Qué NO hace:**
- **No es ULTRON.** No usa n8n, ni sus prompts, ni el motor. Dice lo que un filtro **marcaría** en un texto sintético, nunca lo que ULTRON haría. La pantalla lo dice en cada ejecución («Actor de laboratorio (simulación del Studio, no es ULTRON)»).
- **No aprueba solo.** La propuesta no es un veredicto. El veredicto lo emite una persona con `ultron.approve` distinta de la autora de la versión, uno por ejecución, y no se puede cambiar.
- **No cambia la versión ni el motor**, no llama a nadie, no escribe en Meta, no usa ElevenLabs y no lee conversaciones reales.

**Lo que cuesta y sus frenos** (configuración de producción del 02-11-2026; `ULTRON_STUDIO_FINAL_REPORT.md`, «Hito 4: fase 3»):
- Antes de lanzar, el CRM enseña cuánto costará **como mucho** y hay que aceptarlo. El servidor reserva ese dinero contra el tope del día de Bogotá (2 US$) y por ejecución (0,50 US$); por encima, 409 (`lab_daily_budget_exceeded`, `lab_run_budget_exceeded`). Un cambio de precios entre estimar y lanzar da 409 `estimate_changed` y obliga a reconfirmar.
- Como mucho 20 ejecuciones al día (`lab_daily_runs_exceeded`), 12 casos por ejecución, 10 lanzamientos por hora y persona (y 30 por hora y red; limitador `ultron-studio-lab-run`), sin reintentos automáticos, un caso a la vez, plazo de 30 minutos y parada tras 2 errores seguidos del proveedor.
- La validación 1 (02-11-2026) gastó 0,014 US$ en una ejecución real de 2 escenarios.
- **El proyecto de OpenAI del laboratorio debe tener su propio límite de gasto** en el panel de OpenAI; el tope del laboratorio es la segunda barrera. [PENDIENTE del propietario: límite de gasto del proyecto y rotación de la clave, que viajó por el chat.]

## 8. Integraciones: qué significa cada estado

**Pestaña «Estado»: la clasificación de cada área** (`studio-integrations-text.ts`):

| Valor | Rótulo | Qué significa | Hoy |
|---|---|---|---|
| `connected_read` | Conectada en lectura | Meta Ads: el Studio lee el espejo local que llena la sincronización horaria. Nunca escribe en Meta | Meta Ads |
| `connected_otp_only` | Conectada solo para OTP | Twilio se usa para los códigos de acceso (Verify por SMS), no para llamar | Twilio |
| `degraded` | Degradada | Responde, pero con un dato viejo, una pasada fallida o la sincronización apagada. **Es un dato, no una avería del Studio** | — |
| `disabled` | Apagada | Configurada y apagada | — |
| `not_configured` | No configurada | Sin variables ni número | Twilio Voice |
| `registry` | Registro del Studio (sin efecto en el motor) | Los números canarios | Números canarios |
| `ELEVENLABS_NOT_CONFIGURED` | ElevenLabs no está configurado | Cero credenciales: no hay voz | ElevenLabs |

Un área que el servidor no pudo leer dice «No se pudo leer en el servidor», sin valores inventados.

**«Campañas de Meta».** Cada campaña con dos columnas que no se mezclan: «En Meta» (lo que dice el espejo) y «En ULTRON Studio» (la asociación con la línea base o con una versión `APPROVED`, `CANARY` o `PUBLISHED`). Asociar, sustituir y desactivar (con motivo) exigen `ultron.campaigns_manage` y la bandera de escrituras (hoy apagada). **La asociación es un registro: no cambia nada en Meta ni en ULTRON.** Ojo con el rótulo «Última sincronización correcta»: enseña el éxito **más antiguo** entre las cuentas, es decir, el de la cuenta más atrasada; con una cuenta atrasada y las demás al día parecerá que toda la sincronización está parada (m-4 del reviewer del H6, sin corregir en `16c433c`).

**«Números canarios».** Un registro auditado del teléfono de la conversación canaria, comparado en cada lectura con el canario del motor. **No cambia a quién responde ULTRON**: el motor sigue atendiendo solo la conversación canaria configurada en el servidor.

| Estado (rótulo del servidor) | Cómo se llega | Quién |
|---|---|---|
| Pendiente de autorizar (registro) | Registrar: canal, teléfono, alias por función (no el nombre de una persona) y la casilla de consentimiento | `ultron.canary_manage` |
| Autorizado (registro) | Autorizar | `ultron.canary_authorize`, **distinta de quien registró** |
| Activo (registro) | Activar (como mucho 3 activos por canal) | `ultron.canary_manage`, **distinta de quien registró** |
| Autorizado (registro) | Desactivar, con motivo | `ultron.canary_manage` |
| Revocado | Revocar, con motivo. **Definitivo**; borra el teléfono cifrado si es de voz | `ultron.canary_manage` |

- El teléfono completo solo existe en el diálogo de registro: después se ve enmascarado (`+57 ••• ••• NNNN`) y no vuelve a salir.
- **Revocar no es suprimir:** la fila conserva una huella del teléfono, sus 4 últimos dígitos y el alias. Con la `APP_KEY` del servidor, la huella permite recuperar el número. Para suprimir, §10.
- **El aviso de coherencia** dice `in_sync` solo si el teléfono de la conversación canaria del motor está registrado y activo y es el único WhatsApp activo. Los demás avisos: no está en el registro, está registrado pero no activo, hay otros WhatsApp activos, la conversación canaria no tiene un teléfono comparable, el motor no tiene canario configurado, o no se pudo comparar. Nunca dice `in_sync` por defecto.

**«Twilio».** El OTP (códigos y coste del día), las credenciales solo como huellas cortas, el aviso `usage-trigger` y las llamadas en recuentos. La **comprobación de solo lectura** (5 consultas GET a Twilio, gratis) solo la ve quien tiene `ultron.integrations_manage` (el Super Admin por defecto) y solo con su bandera encendida (hoy apagada); una segunda comprobación en menos de 5 minutos devuelve la misma, sin volver a consultar Twilio, y hay un cupo de 6 por hora y persona y 20 por hora y red.

**«Voz».** ElevenLabs en `ELEVENLABS_NOT_CONFIGURED` y la política de llamadas «No permite llamar». **Ni un botón:** en el código, `VoiceCallPolicy::CALLS_ENABLED_IN_CODE = false`.

**«Configuración autorizada»** (en las pestañas de Meta, Twilio y Voz): una huella de la configuración que el Super Admin reconoce como buena (`ultron.integrations_manage`).

| Estado | Rótulo | Qué significa |
|---|---|---|
| `never_authorized` | Nunca autorizada | Nadie la ha reconocido todavía (el estado de hoy, en la fase 1 del H6) |
| `authorized` | Autorizada | Coincide con lo reconocido |
| `drift` | Cambió desde que se autorizó | Algo cambió en el servidor desde el reconocimiento; se lee clave a clave («autorizado … · ahora …»). Encender o apagar la comprobación de Twilio también cambia su huella |

Aquí no se ven ni se editan secretos: solo huellas. Las credenciales se cambian en el servidor.

## 9. Lo que el Studio NO hace

- **No cambia lo que ULTRON dice ni hace**, ni sin publicar ni publicando: no enciende ni apaga ULTRON, no mueve el canario, no toca el cobro, la pausa, el freno, los planes, la base de conocimiento ni n8n, y no aplica reglas S-NNN.
- **No llama a nadie:** no hay ruta de voz ni de webhook, y las llamadas están cerradas en el código.
- **No escribe en Meta:** solo lee el espejo local; no llama a la API de Meta.
- **No activa ElevenLabs:** sigue en `ELEVENLABS_NOT_CONFIGURED`. Encenderlo exige una autorización expresa aparte (`ULTRON_STUDIO_ELEVENLABS_READY.md`).
- **No envía mensajes** (la comprobación de Twilio solo lee) ni toca el OTP.
- **No frena ni revierte solo:** avisa, bloquea la promoción y, con el monitor del Hito 7 encendido, abre un incidente en IRON GUARD.
- **No borra:** archiva, revoca o desactiva.
- **El motor no lee** el registro de números canarios, las asociaciones de campañas ni la configuración autorizada. Que lo lea (T2/T3) exige autorización expresa.

## 10. Datos personales y solicitudes de supresión (Ley 1581)

- **Antes de escribir:** cada campo de texto libre del Studio avisa de que queda en un libro que no se puede corregir. Los motivos y notas de integraciones rechazan teléfonos y correos (422 «Sin teléfonos ni correos: este texto queda en el libro y no se puede corregir.»).
- **Si llega una solicitud de supresión:** la supresión es por **redacción**, nunca por borrado (las filas no se pueden borrar sin romper la cadena), y la hace quien opera el servidor con el rol dueño de la base, **en seco primero**, con `docs/ultron-studio/ops/supresion-1581.sql`. El procedimiento está en la cabecera de ese fichero; se ensayó 17/17 en un banco local (`plans/informes-h7/informe-devops-h7.md` §4.4) y **no se ha ejecutado nunca en producción**.
  - Busca un texto (nombre, correo), un teléfono, la huella de un número canario **revocado** o una persona del personal por su id, en las 14 tablas del Studio, y lo sustituye por «[suprimido]». Deja una fila `system.personal_data_redacted` en el libro, con el radicado y los recuentos, nunca el dato.
  - **No redacta** la configuración firmada o con huella (la cadena de publicaciones, el contenido de las versiones y su copia en el laboratorio): si el dato está ahí, el remedio es una versión nueva sin él. Lo decide el propietario con su jurídico.
  - Suprimir a una persona del personal quita su nombre de la autoría de lo que hizo (queda el id): lo decide el propietario.
  - **Los respaldos siguen llevando el dato** hasta que caducan (los automáticos, unos 3 meses); una restauración obliga a volver a aplicar cada supresión. OPS7 recomienda un registro de supresiones (§7 de su informe).
  - [PENDIENTE: la cabecera de `supresion-1581.sql` remite a un runbook completo en `informe-devops-h7.md`, «Supresión», que no existe como sección (solo el ensayo, §4.4). Hasta que `devops` lo escriba, el procedimiento es la cabecera del SQL más esta lista.]

## 11. Qué cambia con el Hito 7 (cuando se despliegue)

**Implementado en la rama `feat/ultron-studio-h7`; no está en producción.** [PENDIENTE: fecha del despliegue (lead).] Para quien usa el CRM:

- **Textos con caracteres invisibles, en el Studio:** si un texto pegado trae un carácter invisible o de control, el Studio lo rechaza con «El texto contiene un carácter invisible o de control (U+XXXX) que no se puede guardar. Suele colarse al copiar y pegar: escríbelo de nuevo sin pegarlo.», junto al campo. Los emojis compuestos (con ZWJ o U+FE0F) se aceptan. Afecta a etiquetas, notas, motivos y descripciones del Studio (`plans/informes-h7/informe-security.md` §1). Un texto guardado antes del H7 con uno de esos caracteres solo se rechaza si se vuelve a guardar: al guardar el borrador o al crear una versión desde él; enviar, aprobar o publicar una versión ya creada no lo vuelve a mirar (REV7, M-1).
- **Fuera del Studio no cambia nada:** la agenda de valoraciones y la Caja siguen con la regla de antes. Es la corrección de M-1 del reviewer (opción b), que `security` está terminando. [PENDIENTE: confirmar que el commit desplegado lleva esa corrección (lead).]
- **Restablecer la contraseña de un administrador desde el CRM cierra sus demás sesiones**, incluidos los «recuérdame»: la persona afectada vuelve al login en su siguiente petición. Si quien restablece es la propia cuenta, conserva la sesión con la que lo hace. Afecta a todo el CRM, no solo al Studio (B7, H-2 de SEC7).
- **Nada visible** en el resto: las respuestas de la API administrativa dejan de guardarse en la caché del navegador (`no-store`); las peticiones de más de 64 KB al Studio se rechazan antes de autenticar; el historial de publicaciones trae `verified` por entrada, pero **el CRM `16c433c` no lo enseña** (no lo lee); y el monitor nace apagado.
- **El monitor**, cuando el propietario autorice encenderlo, comprueba cada 30 minutos la publicación frente al motor, las integraciones frente a lo esperado y las colas, y abre un incidente en IRON GUARD (fuente `ultron_studio`) si algo no cuadra. No frena, no revierte, no llama a ningún proveedor y no notifica fuera.

## 12. Si algo va mal

| Lo que ves | Qué hacer | Quién |
|---|---|---|
| «No se pudo abrir el Studio» | «Reintentar». Si persiste, avisar a quien opera el servidor | Cualquiera |
| La barra de la publicación dice que la declaración no coincide con el motor | Nada se revierte solo. Quien tenga `ultron.rollback` decide si «Volver a Legacy». Avisar al lead | Super Admin |
| Una acción responde 409 con un código | Leer el motivo en pantalla: casi siempre es una regla (ventana, cuatro ojos, bandera apagada), no una avería | — |
| La cabecera de la cadena «no verifica» (`publication_head_invalid`) | Publicar, promover y revertir quedan bloqueados; la salida es «Volver a Legacy». No improvisar con la base: procedimiento en `ULTRON_STUDIO_ROLLBACK.md`, «Hito 5» | Super Admin + lead |
| El laboratorio no deja lanzar por presupuesto | Es el tope del día de Bogotá; se reinicia al cambiar el día | — |
| Un dato personal se escribió en un texto libre | No intentar «corregirlo»: no se puede. Avisar al propietario (§10) | Quien lo vea |
| Te echan del CRM tras restablecer tu contraseña (con el H7) | Es lo esperado: entrar con la contraseña nueva | — |

## 13. Fuentes

Contratos `ULTRON_STUDIO_{F,G,H4,H5,H6,H7}_CONTRACT.md`; `ULTRON_STUDIO_FINAL_REPORT.md`; `ULTRON_STUDIO_DEPLOYMENT_PLAN.md`; `ULTRON_STUDIO_ROLLBACK.md`; `plans/informes-h7/*`; en el backend, `app/Support/Access/AuthorizationMap.php` (las 56 rutas del Studio y su llave), `app/Support/Access/CrmPermission.php` (`byRole()`), `config/ultron_studio.php` (ventana, puerta del laboratorio, umbrales del canario, laboratorio, integraciones y monitor), `app/Services/UltronStudio/StudioVersionStatus.php`, `StudioCanaryEvidence.php` y `StudioCanaryNumbers.php`; en el CRM `16c433c`, `src/app/app.routes.ts`, `src/app/app.html` y `src/app/modules/ultron-studio/` (`ultron-studio-page.ts`, `studio-versions-panel.ts`, `studio-publication-bar.ts`, `studio-lab-page.ts`, `studio-integrations-page.ts` y `studio-integrations-text.ts`).

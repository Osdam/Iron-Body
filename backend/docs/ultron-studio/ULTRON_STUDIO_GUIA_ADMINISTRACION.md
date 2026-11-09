# ULTRON STUDIO: guía de administración

**Qué es.** Lo que hace cada pantalla de ULTRON Studio en el CRM, quién puede hacer cada cosa, cómo se crea, aprueba y publica una versión, qué es el canario y qué evidencia exige, qué hace y qué no hace el laboratorio, qué significa cada estado de las integraciones y qué **no** hace el Studio.

**Para quién.** El propietario y el personal que usa el Studio (Super Admin y Administrador). Hace falta conocer el CRM; no hace falta conocer el código. Los comandos de consola de esta guía son solo de lectura y los ejecuta quien opera el servidor; los procedimientos de despliegue están en `ULTRON_STUDIO_DEPLOYMENT_PLAN.md` y la lista de comprobaciones periódicas en `ULTRON_STUDIO_CHECKLIST_OPERATIVO.md`.

**Tipo.** Referencia y uso. No sustituye a los contratos (`ULTRON_STUDIO_F_CONTRACT.md` … `ULTRON_STUDIO_H7_CONTRACT.md`), que mandan sobre esta guía, ni al procedimiento de pruebas con personas reales (`ULTRON_STUDIO_PRUEBAS_FISICAS.md`).

**Fecha:** 02-11-2026. Escrita sobre el backend `5ed9525` (rama `feat/ultron-studio-h7`) y el CRM `16c433c`, y revisada sobre `3fbb155` (F-1 y F-2 y la primera pasada de REV7) y sobre `cdade1e` (la corrección de M-1 y el runbook de la supresión), leyendo el código: rutas y permisos (`app/Support/Access/AuthorizationMap.php`, `CrmPermission.php`), configuración (`config/ultron_studio.php`), servicios del Studio y las pantallas del CRM (`src/app/modules/ultron-studio/`). Cada cifra lleva su fuente.

**Ampliada el 08-11-2026 con el Hito 8 (voz y telefonía):** §14, escrita sobre el backend `8d3c41f` (rama `feat/ultron-studio-h8-voice`) y el CRM `26ef142` (paso 2), con las correcciones fechadas de §2, §8 y §9 que pide la Enmienda 1 del contrato H8 (E1-12). La referencia técnica de la voz está en `ULTRON_STUDIO_H8_VOZ.md`.

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
| Hito 8 (voz y telefonía) | **Sin desplegar** (08-11-2026): la fase 1 está preparada y ensayada en el banco | Ver §14 | `ULTRON_STUDIO_DEPLOYMENT_PLAN.md`, «Hito 8» |

*Actualizado el 03-11-2026: el Hito 7 está en producción; las fases 2 del H5 y del H6 siguen pendientes de la autorización del propietario.*

*Actualizado el 08-11-2026 (DOC8):* **ElevenLabs ya tiene credenciales en producción** desde el 03-11-2026 a las 22:55 UTC. El propietario creó la clave y el agente de verificación y autorizó su configuración; el lead la hizo con el procedimiento del H6 (`backend/docs/ultron-studio/ops/el-verificar.sh`). Desde entonces el Studio dice `ELEVENLABS_VERIFIED` («ElevenLabs verificado») y `voice:doctor` dice `ELEVENLABS_UNVERIFIED`: cada estado vive en su sitio. **No se ha hecho ninguna llamada:** la constante del código sigue cerrada. La verificación caduca el **03-12-2026 a las 22:55 UTC** si nadie la renueva (§14.15). *Fuentes: contrato H8, E1-12 y E8-7; `ULTRON_STUDIO_DEPLOYMENT_PLAN.md`, «Hito 8» («Dónde vive cada estado de ElevenLabs»).*

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
| `ELEVENLABS_NOT_CONFIGURED` | ElevenLabs no está configurado | Cero credenciales: no hay voz | ElevenLabs hasta el 03-11-2026 |
| `ELEVENLABS_VERIFIED` | ElevenLabs verificado | La clave abre el agente de verificación y la última verificación «ok» tiene menos de 30 días. **No llama a nadie** | ElevenLabs desde el 03-11-2026 22:55 UTC (E1-12) |

*Corregido el 08-11-2026 (DOC8):* la columna «Hoy» decía `ELEVENLABS_NOT_CONFIGURED` para ElevenLabs. Desde E1-12 el estado es `ELEVENLABS_VERIFIED` (clasificación `connected_read`), y pasará a `ELEVENLABS_UNVERIFIED` («ElevenLabs encendido, sin verificar», clasificación `degraded`) si la verificación caduca. *Fuentes: contrato H8, E1-12; `app/Services/UltronStudio/StudioIntegrations.php:58-63`; CRM `26ef142`, `studio-integrations-text.ts:533-536`.*

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

**«Voz».** ElevenLabs en `ELEVENLABS_NOT_CONFIGURED` y la política de llamadas «No permite llamar». **Ni un botón:** en el código, `VoiceCallPolicy::CALLS_ENABLED_IN_CODE = false`. *(Corregido el 08-11-2026: desde el 03-11-2026 ElevenLabs sale `ELEVENLABS_VERIFIED`; la política sigue diciendo «No permite llamar» y sigue sin haber botones. Con el Hito 8, la voz tiene su propia página, «Voz y telefonía», en §14.)*

**«Configuración autorizada»** (en las pestañas de Meta, Twilio y Voz): una huella de la configuración que el Super Admin reconoce como buena (`ultron.integrations_manage`).

| Estado | Rótulo | Qué significa |
|---|---|---|
| `never_authorized` | Nunca autorizada | Nadie la ha reconocido todavía (el estado de hoy, en la fase 1 del H6) |
| `authorized` | Autorizada | Coincide con lo reconocido |
| `drift` | Cambió desde que se autorizó | Algo cambió en el servidor desde el reconocimiento; se lee clave a clave («autorizado … · ahora …»). Encender o apagar la comprobación de Twilio también cambia su huella |

Aquí no se ven ni se editan secretos: solo huellas. Las credenciales se cambian en el servidor.

## 9. Lo que el Studio NO hace

- **No cambia lo que ULTRON dice ni hace**, ni sin publicar ni publicando: no enciende ni apaga ULTRON, no mueve el canario, no toca el cobro, la pausa, el freno, los planes, la base de conocimiento ni n8n, y no aplica reglas S-NNN.
- **No llama a nadie:** no hay ruta de voz ni de webhook, y las llamadas están cerradas en el código. *(Nota del 08-11-2026: con el Hito 8 desplegado sí habrá rutas de voz y webhooks, todas cerradas en la fase 1, y las llamadas seguirán cerradas en el código hasta la fase 3: §14.)*
- **No escribe en Meta:** solo lee el espejo local; no llama a la API de Meta.
- **No activa ElevenLabs:** sigue en `ELEVENLABS_NOT_CONFIGURED`. Encenderlo exige una autorización expresa aparte (`ULTRON_STUDIO_ELEVENLABS_READY.md`). *(Corregido el 08-11-2026: el propietario lo autorizó y el lead lo configuró el 03-11-2026 (E1-12); solo verifica la clave con un GET. El Studio sigue sin activar nada por sí mismo, y las llamadas siguen cerradas en el código.)*
- **No envía mensajes** (la comprobación de Twilio solo lee) ni toca el OTP.
- **No frena ni revierte solo:** avisa, bloquea la promoción y, con el monitor del Hito 7 encendido, abre un incidente en IRON GUARD.
- **No borra:** archiva, revoca o desactiva.
- **El motor no lee** el registro de números canarios, las asociaciones de campañas ni la configuración autorizada. Que lo lea (T2/T3) exige autorización expresa.

## 10. Datos personales y solicitudes de supresión (Ley 1581)

- **Antes de escribir:** cada campo de texto libre del Studio avisa de que queda en un libro que no se puede corregir. Los motivos y notas de integraciones rechazan teléfonos y correos (422 «Sin teléfonos ni correos: este texto queda en el libro y no se puede corregir.»).
- **Si llega una solicitud de supresión:** la supresión es por **redacción**, nunca por borrado (las filas no se pueden borrar sin romper la cadena), y la hace quien opera el servidor con el rol dueño de la base, **en seco primero**, con `docs/ultron-studio/ops/supresion-1581.sql`. Qué hace y qué no, en la cabecera de ese fichero; **el procedimiento paso a paso, en `plans/informes-h7/informe-devops-h7.md` §10** (ensayado en un banco local: la lógica 17/17 y las órdenes 12/12). **No se ha ejecutado nunca en producción.** Lo esencial:
  - Hace falta un número de radicado y la **autorización del propietario**: es una modificación de la base de producción.
  - Si fue un número canario, primero se **revoca** desde el CRM: con uno vivo, el procedimiento se niega sin cambiar nada.
  - El dato no pasa por la línea de órdenes ni por la pantalla: va a un fichero de parámetros solo de root, y la huella del teléfono la calcula la aplicación, con el teléfono por la entrada estándar.
  - Antes se comprueba que PostgreSQL no registrará el texto de las sentencias: `log_statement` en `none` o `ddl`, y `log_min_duration_statement` nunca en `0`.
  - Primero **en seco** (solo cuenta) y, con un respaldo fresco, la redacción real. Después, la cadena sigue verificando y una pasada en seco da 0 campos.
  - Busca un texto (nombre, correo), un teléfono, la huella de un número canario **revocado** o una persona del personal por su id, en las 14 tablas del Studio, y lo sustituye por «[suprimido]». Deja una fila `system.personal_data_redacted` en el libro, con el radicado y los recuentos, nunca el dato.
  - **No redacta** la configuración firmada o con huella (la cadena de publicaciones, el contenido de las versiones y su copia en el laboratorio): si el dato está ahí, el remedio es una versión nueva sin él. Lo decide el propietario con su jurídico.
  - Suprimir a una persona del personal quita su nombre de la autoría de lo que hizo (queda el id): lo decide el propietario.
  - **Los respaldos siguen llevando el dato** hasta que caducan (los automáticos, unos 3 meses); una restauración obliga a volver a aplicar cada supresión. OPS7 recomienda un registro de supresiones (§7 de su informe).
  - *(Hasta el 02-11-2026 aquí había un marcador de pendiente: la cabecera del SQL remitía a una sección «Supresión» que no existía. `devops` la escribió en `0d43401`, y la cabecera remite ahora a ella.)*

## 11. Qué cambia con el Hito 7 (cuando se despliegue)

**En producción desde el 03-11-2026.** Para quien usa el CRM:

- **Textos con caracteres invisibles, en el Studio:** si un texto pegado trae un carácter invisible o de control, el Studio lo rechaza con «El texto contiene un carácter invisible o de control (U+XXXX) que no se puede guardar. Suele colarse al copiar y pegar: escríbelo de nuevo sin pegarlo.», junto al campo. Los emojis compuestos (con ZWJ o U+FE0F) se aceptan. Afecta a etiquetas, notas, motivos y descripciones del Studio (`plans/informes-h7/informe-security.md` §1). Un texto guardado antes del H7 con uno de esos caracteres solo se rechaza si se vuelve a guardar: al guardar el borrador o al crear una versión desde él; enviar, aprobar o publicar una versión ya creada no lo vuelve a mirar (REV7, M-1).
- **Fuera del Studio no cambia nada:** la agenda de valoraciones y la Caja siguen con la regla de antes y el mensaje de siempre («El texto contiene caracteres no permitidos.»). El guion blando y los demás caracteres nuevos se siguen aceptando allí, y un emoji con ZWJ se sigue rechazando, como antes. Es la corrección de M-1 del reviewer (opción b), en `601398c`, dentro de lo que se despliega: la lectura de producción no encontró ningún texto guardado que se vea afectado (`plans/informes-h7/informe-security.md` §7).
- **Restablecer la contraseña de un administrador desde el CRM cierra sus demás sesiones**, incluidos los «recuérdame»: la persona afectada vuelve al login en su siguiente petición. Si quien restablece es la propia cuenta, conserva la sesión con la que lo hace. Afecta a todo el CRM, no solo al Studio (B7, H-2 de SEC7).
- **Nada visible** en el resto: las respuestas de la API administrativa dejan de guardarse en la caché del navegador (`no-store`); las peticiones de más de 64 KB al Studio se rechazan antes de autenticar; el historial de publicaciones trae `verified` por entrada, pero **el CRM `16c433c` no lo enseña** (no lo lee); y el monitor nace apagado.
- **El monitor**, cuando el propietario autorice encenderlo, comprueba cada 30 minutos la publicación frente al motor, las integraciones frente a lo esperado y las colas, y abre un incidente en IRON GUARD (fuente `ultron_studio`) si algo no cuadra. No frena, no revierte, no llama a ningún proveedor y no notifica fuera. No se enciende desde el CRM: lo enciende y lo apaga quien opera el servidor, con `docs/ultron-studio/ops/h7-monitor-scope.sh` (runbook del H7, paso 10). Apagarlo quita sus 2 tareas programadas, pero no borra los incidentes ya abiertos.

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

---

## 14. Hito 8: voz y telefonía

**Para quién.** El propietario y el personal que use la voz (Super Admin y Administrador). No hace falta conocer el código. La referencia técnica (rutas, variables, comandos) está en `ULTRON_STUDIO_H8_VOZ.md`; lo que mira quien opera el servidor, en `ULTRON_STUDIO_CHECKLIST_OPERATIVO.md` §5; los riesgos, en `ULTRON_STUDIO_H8_RIESGOS.md`; las pruebas con personas reales, en `ULTRON_STUDIO_PRUEBAS_FISICAS.md` §9.

> **Estado el 08-11-2026: nada del Hito 8 está en producción.** Cuando se despliegue la fase 1 todo nacerá apagado. El CRM del paso 3 (formas reales en las siete pestañas y laboratorio con micrófono) está confirmado en la rama desde el 09-11-2026 (`45dd73a`).

### 14.1 Lo primero que hay que saber

1. **Con la fase 1 no cambia nada para nadie, y nadie llama hasta la fase 3.** La fase 2 trae credenciales, el número y el laboratorio hablado, pero ni una llamada telefónica. La primera llamada real solo es posible en la fase 3, tras un cambio de una línea en el código (el commit `voice(calls-open)`) con la autorización escrita del propietario.
2. **La voz dice lo que aprueba el Studio, y nada más.** Una versión aprobada del Studio y un perfil de voz aprobado se convierten en un **agente nuevo** de ElevenLabs que ya no cambia. Nadie escribe instrucciones libres para la voz. **El panel de ElevenLabs no se toca:** cualquier cambio allí se detecta y echa el freno.
3. **Cuatro ojos en todo:** el perfil, el veredicto del laboratorio, la publicación y cada consentimiento de la fase 4b. El control compara **cuentas**: usar dos cuentas propias lo burlaría, y hacerlo cumplir es del propietario.
4. **Sin datos personales a la vista:** de una llamada solo se ven un alias y los 4 últimos dígitos. Laravel no guarda transcripciones ni audio, y la grabación está apagada.
5. **El freno se abre solo y lo suelta una persona.** Nunca se suelta solo.
6. **Lo que se crea no se borra:** perfiles, agentes, la cadena de publicaciones de voz, las llamadas, los eventos, los consentimientos, las exclusiones y los frenos se quedan.

*Fuentes: contrato H8, «Objetivo», protecciones 1, 2 y 13, «Fases de despliegue», D8-7, D8-17, D8-19, D8-22.*

### 14.2 Dónde está

- **La página «Voz y telefonía»:** `https://ironbodyneiva.cloud/marketing/ultron-studio/voz`. Se llega con el enlace «Voz y telefonía» de la barra del tablero del Studio, tras «Integraciones». Pide `ultron.view`.
- **La cabecera, siempre a la vista:** la fase que calcula el servidor (por ejemplo, «Fase 1 Todo apagado»), el freno («Freno suelto», «Freno echado» o «sin leer: cuenta como echado») y, mientras la constante del código esté cerrada, «Hoy no se hace ninguna llamada: la constante del código sigue cerrada hasta la fase 3, con la autorización escrita del propietario.»
- **Siete pestañas:** «Estado», «Perfil de voz», «Publicación», «Llamadas», «Costes y métricas», «Contactos» y «Laboratorio hablado». Se recorren con las flechas, Inicio y Fin.
- Las siete pestañas leen las respuestas reales del servidor (CRM del paso 3, 09-11-2026); si una no puede leerse, lo dice sin enseñar nada inventado.

*Fuentes: CRM `26ef142`, `studio-voice-page.ts:14-23` y `studio-voice-text.ts:58-61`; `plans/informes-h8/informe-front.md` §2 y §3.3.*

### 14.3 Quién puede qué

El CRM pasa a tener **111 permisos**; 7 filas de la voz (6 llaves nuevas y `ultron.approve`, que ya existía). Comprobado el 08-11-2026 con `PermissionCatalog::all()` y `CrmPermission::byRole()` sobre `8d3c41f`:

| Llave | Qué abre | Super Admin | Administrador | Recepción · Entrenador · Administrativo |
|---|---|---|---|---|
| `ultron.voice_manage` | Crear un perfil de voz, enviarlo a revisión y previsualizar su compilación | sí | sí | no |
| `ultron.approve` | Aprobar o rechazar un perfil ajeno y dar el veredicto de una sesión ajena del laboratorio hablado | sí | sí | no |
| `ultron.voice_publish` | Publicar en canario, promover, revertir y apagar la voz, y **soltar el freno** | sí | **no** | no |
| `ultron.voice_lab_run` | Abrir sesiones del laboratorio hablado (**gastan**) | sí | **no** | no |
| `telephony.view` | Ver el registro de llamadas sin números, las métricas y los costes | sí | sí | no |
| `telephony.call_place` | Pedir una llamada saliente autorizada y cortar una llamada | sí | **no** | no |
| `telephony.contacts_manage` | Contactos, consentimientos, exclusiones y consultas al RNE (datos personales) | sí | **no** | no |

- Son los valores por defecto. El propietario los cambia por rol en Configuración › Roles, y el servidor vuelve a comprobar la llave en cada petición.
- **Las automatizaciones (el token compartido) no reciben ninguna llave de voz:** la voz exige siempre una persona.
- Los botones salen de lo que el servidor permite a tu sesión (`can` de la ruta 45).

*Fuentes: `app/Support/Access/CrmPermission.php:143-168, 288-322` y `PermissionCatalog.php:165-170, 239-244` (ejecutados con `tinker`); contrato D8-22, E6-7; `informe-studio.md` §14.1.*

### 14.4 Las pestañas

| Pestaña | Qué ves | Qué necesitas |
|---|---|---|
| **Estado** | **La línea**: los siete cerrojos que cruza una llamada (llamadas en el código → ElevenLabs → Twilio Voice → publicación de voz → freno → entrantes → salientes), con «La línea se corta aquí» en el primero cerrado. Debajo, el número y la subcuenta (sin el número completo), las banderas con su variable, lo que rige en cada parte y el freno | `ultron.view`; los datos de telefonía, `telephony.view` |
| **Perfil de voz** | Los perfiles con su estado («Borrador», «Enviado a revisión», «Aprobado», «Rechazado»), «Nueva revisión» y la previsualización | Crear y previsualizar, `ultron.voice_manage`; aprobar, `ultron.approve` |
| **Publicación** | Lo que rige en el canario y en el resto, la ventana, la puerta del laboratorio, la evidencia del canario y la cadena con el número de cada entrada | Publicar y lo demás, `ultron.voice_publish` |
| **Llamadas** | El registro sin números (alias y 4 dígitos), el estado, el sello, los eventos sin transcripción, «Pedir una llamada» y «Cortar la llamada» | Ver, `telephony.view`; pedir y cortar, `telephony.call_place` |
| **Costes y métricas** | Lo gastado y reservado del día frente al techo, y los últimos 7 días | `telephony.view` |
| **Contactos** | Los contactos de la fase 4b, su consentimiento, las exclusiones y las consultas al RNE | `telephony.contacts_manage` |
| **Laboratorio hablado** | Los cuatro cerrojos, el presupuesto y el cupo, el historial y el veredicto | Ver, `ultron.view`; abrir, `ultron.voice_lab_run`; veredicto, `ultron.approve` |

*Fuentes: `informe-front.md` §1–§2; CRM `26ef142`, `studio-voice-text.ts` (`voiceLineNodes`, estados de perfil); `app/Support/Access/AuthorizationMap.php:794-835`.*

### 14.5 Crear y aprobar un perfil de voz (cuatro ojos)

**Qué es.** El perfil es lo único propio de la voz que se configura: la voz, los saludos de entrantes y salientes, la duración máxima (de 60 a 300 segundos por defecto), hasta 3 destinos de transferencia, las herramientas activas, la retención (hasta 30 días) y dos textos cortos (presentación y despedida, hasta 160 caracteres). **El aviso de IA no se escribe:** lo pone el servidor, y es provisional hasta que el propietario lo apruebe antes de la fase 3.

**Qué haces** (A y B, personas distintas):
1. **A crea el perfil** («Nueva revisión»). Nace como borrador, con el contenido ya fijo: un perfil no se edita, se crea otra revisión.
2. **A lo envía a revisión.** Solo quien lo creó puede enviarlo (si lo intenta otra cuenta: 403 `not_profile_owner`).
3. **B lo aprueba o lo rechaza** (rechazar exige una nota de 5 a 500 caracteres). Si A lo intenta: 403 `four_eyes_required`. Aprobado o rechazado es definitivo.

**Qué ves si el servidor lo rechaza:** la negativa del compilador junto al campo que la causa («Dónde: Saludo de las entrantes»). Los motivos más habituales: cifras de dinero o palabras de descuento, una URL, un teléfono o un correo en un texto, caracteres invisibles, llaves, comillas angulares o emojis, quitar `no_volver_a_llamar`, una duración por encima del tope o transferir sin la herramienta «Pedir una persona».

**Qué NO hagas:**
- No escribas datos personales ni números en los textos: quedan para siempre.
- Un destino de transferencia lleva su número **solo en el formulario**; después se ve como alias y 4 dígitos y no vuelve a salir.
- **El perfil del canario, sin destinos de transferencia:** hoy un perfil con destinos no se puede probar en el laboratorio hablado y, con la puerta en `required`, no se puede publicar (limitación aceptada hasta la fase 3; `ULTRON_STUDIO_H8_RIESGOS.md`, R8-14).

*Fuentes: contrato D8-6, E1-3, E1-8, E6-8; `informe-studio.md` §8.1–§8.2, §14.1; `informe-lab.md` §6.4; decisión del lead del 08-11-2026 sobre las transferencias; CRM `26ef142`, `studio-voice-text.ts:67-70`.*

### 14.6 Previsualizar y publicar

**Previsualizar** (`ultron.voice_manage`) compila la versión y el perfil **sin salir a la red ni escribir nada**, y devuelve su huella (`specHash`). Publicar compara esa huella con la de ese momento: **lo que viste es lo que se publica**.

**Para publicar en canario hacen falta, a la vez:**
- la bandera de publicar encendida en el servidor (`ULTRON_STUDIO_VOICE_PUBLISHING_ENABLED`, desde la fase 3);
- `ultron.voice_publish` y **no haber creado ni la versión ni el perfil**;
- la ventana, de 07:00 a 19:00 de Bogotá;
- el freno suelto;
- un veredicto `approved` del laboratorio hablado sobre **la misma huella**, de otra persona (puerta `required`; solo el propietario la dispensa);
- el número importado en ElevenLabs y las piezas de la fase 2.

**Qué pasa al publicar:** se crea un agente nuevo, el servidor lo lee de vuelta para comprobar que es exactamente lo compilado, le asigna el número y escribe una entrada firmada en la cadena. Toda publicación empieza en el canario. Las llamadas en curso siguen con el agente con el que empezaron. Conviene publicar sin ninguna llamada del canario en curso.

**Rechazos que verás** (ninguno deja nada a medias): la bandera apagada, 409 `voice_publishing_disabled`; la autora, 403 `four_eyes_required`; fuera de la ventana, 409 `outside_publication_window`; el freno echado, 409 `voice_braked`; la compilación rechazada, 422 `voice_compile_refused`; **la huella cambió, 409 `spec_changed`**; sin veredicto, 409 `lab_verdict_required`; faltan piezas, 409 `voice_not_deployable`; el agente no es lo compilado, 409 `voice_agent_rejected`; otra persona publicó a la vez, 409 `publication_moved`.

**Qué NO hagas:** ante `spec_changed`, no repitas a ciegas: vuelve a previsualizar, revisa lo que cambió y confirma otra vez.

*Fuentes: contrato D8-7, D8-9, D8-19, E4-1, E5-6, E6-9; `informe-studio.md` §8.2, §10 (decisiones 4–6), §14.2; código: `app/Services/UltronStudio/StudioVoicePublishing.php:225-312, 962-1001`.*

### 14.7 El canario de voz

**Qué es.** Los teléfonos del registro de números canarios del Hito 6 con **canal voz** y estado activo (como mucho 3 a la vez). En la fase 3 solo ellos hablan con la voz; cualquier otro que llame oye «línea no disponible» y su llamada queda registrada como denegada.

**Qué hace falta:**
- la fase 2 del Hito 6 (las escrituras del registro encendidas);
- **dos personas reales**: una registra el número y otra lo autoriza y lo activa;
- **al menos dos personas canario de voz** cuyo titular haya consentido las llamadas de prueba;
- la autorización expresa del «T3 de voz».

**La evidencia para promover** (la calcula el servidor; por defecto):
1. al menos **24 horas** desde la publicación en canario;
2. al menos **3 llamadas** selladas con esa publicación;
3. **cero** violaciones de la guarda, sellos que no cuadran, derivas, llamadas que Laravel no inició y techos de gasto superados;
4. la cadena verificada, el freno suelto y el veredicto del laboratorio.

**Ojo:** las derivas, las llamadas desconocidas y los techos de gasto cuentan **en todo el sistema** desde la publicación. Uno solo en esa ventana impide para siempre promover esa publicación: hará falta publicar otra vez. Con pocas llamadas, reunir 3 puede tardar días; sin llamadas no hay promoción, y es lo correcto.

*Fuentes: contrato D8-19, «Criterio de cierre»; `informe-backend.md` §B8-b tanda E4 §2; código: `app/Services/UltronStudio/StudioVoiceEvidence.php:62-170`, `app/Services/Telephony/VoiceEvidence.php:57-83`, `config/ultron_studio.php:282-283`; §8 de esta guía (registro de canarios).*

### 14.8 Promover, revertir y apagar

- **Promover a todas** (`ultron.voice_publish`, otra persona que la autora, dentro de la ventana): exige la evidencia que viste y la huella del agente canario. La parte «todas» (`rest`) es la que usan las salientes a contactos de la fase 4b; las entrantes siguen solo en canario durante todo el Hito 8.
- **Revertir** (`ultron.voice_publish`, con un motivo de 5 a 500 caracteres): vuelve al agente anterior de esa parte, que sigue existiendo, y le devuelve el número. **Sin ventana, sin cuatro ojos y sin depender de la bandera de publicar.**
- **Apagar la voz** (lo mismo): una entrada sin agente y el número sin agente: **no atiende nadie**. Si ya estaba apagada, 409 `voice_already_off`.
- Ninguna de las tres borra nada ni corta una llamada en curso. Para cortar, §14.12.

*Fuentes: contrato D8-20 (niveles 1 y 2); `informe-studio.md` §8.1, §10.3; código: `StudioVoicePublishing.php:385-470`.*

### 14.9 El freno

**Qué lo abre, siempre solo:**

| Motivo (texto del CRM) | Qué pasó |
|---|---|
| «Violación de la guarda en una llamada» | El agente dijo algo que el motor marca (un descuento, un precio que no salió del catálogo, un enlace…) o faltó el aviso |
| «El agente que atendió no es el sellado» | La llamada la atendió un agente distinto del que se selló al empezar |
| «Deriva del agente en el proveedor» | Alguien cambió el agente en ElevenLabs, o el número apunta a otro agente |
| «Techo duro de gasto» | Lo comprometido del día superó el presupuesto de voz |
| «Aviso de la subcuenta de Twilio» | Twilio avisó de gasto en la subcuenta de voz |
| «Una llamada que Laravel no inició» | Twilio enseña una llamada sin su fila en el registro |

**Con el freno echado:** no sale ninguna llamada, las entrantes oyen «línea no disponible», el agente no recibe datos de las herramientas y no se puede publicar. Cada apertura abre un incidente en IRON GUARD.

**Quién lo suelta y cómo:** una persona con `ultron.voice_publish` (Super Admin por defecto), con una nota de **5 a 300 caracteres** sin datos personales. **Cada apertura se suelta por separado y una sola vez:** si hay tres aperturas, hay que soltar las tres. En el CRM: «Estado» → el freno → «Soltar».

**Qué NO hagas:**
- **No sueltes un freno sin conocer su causa.** Antes, quien opera el servidor la aclara (checklist §5). Soltarlo deja pasar otra vez lo que lo abrió.
- No lo sueltes para «probar si ya funciona».

**Con el presupuesto de voz en 0, la primera llamada del día echaría el «Techo duro de gasto»** en la siguiente conciliación, también la de prueba de la fase 2: **corrección en curso (B8-d)**, antes de la fase 2. Con ella, el freno de gasto solo salta con gasto real comprometido, y una llamada denegada no cuenta (Enmienda 10, E10-8).

*Fuentes: contrato D8-19, E6-1; código: `app/Services/Telephony/VoiceBrake.php:48-56, 87-176`, `app/Models/Voice/VoiceBrakeEntry.php:21`; CRM `26ef142`, `studio-voice-text.ts:76, 333-343`; decisión del lead del 08-11-2026 (B8-d); `informe-front.md` §3.3.*

### 14.10 El laboratorio hablado

**Qué es.** Hablar con la voz **desde el navegador, sin teléfono**, contra un agente de laboratorio que nunca atiende llamadas. Sirve para escuchar cómo suena una versión y un perfil antes de publicarlos, y su veredicto es la llave de la publicación.

**Qué ves antes de abrir:** los cuatro cerrojos en orden (la bandera, el modo, el presupuesto y la credencial verificada), el presupuesto del día de Bogotá, lo que reservará la sesión y tu cupo del día (5 sesiones por persona por defecto).

**Qué haces:**
1. Dar permiso al micrófono: el CRM lo pide **antes** de abrir la sesión, y si no das permiso no se gasta nada (E10-12).
2. **Abrir la sesión** (`ultron.voice_lab_run`; **gasta**): se reserva su coste antes de empezar. Hablar; como mucho 5 minutos.
3. **Terminar** (solo quien la abrió) y anotar lo observado con las casillas del CRM.
4. **Otra persona da el veredicto** (`ultron.approve`): ni quien abrió la sesión ni quien creó la versión. «Aprobado» o «Rechazado» (este, con nota). Es definitivo y va sobre la huella que se ve.

**Qué NO hace:** no llama a teléfonos; sus herramientas responden que no hay llamada; no prueba perfiles con destinos de transferencia ni de más de 5 minutos; el modo simulador no admite veredicto.

**Qué NO hagas:** no compartas la sesión ni el enlace; habla con casos inventados (tu voz es un dato personal y ElevenLabs la retiene hasta 30 días).

*Fuentes: contrato D8-23, P-19, E10-10, E10-12; `informe-lab.md` §2–§3, §6; código: `app/Services/UltronStudio/StudioVoiceLabConfig.php:42-102`; CRM `26ef142`, `studio-voice-text.ts:82`.*

### 14.11 Las llamadas, sin datos personales

- **Lo que ves de cada llamada:** el alias del canario o del destino y los 4 últimos dígitos (por ejemplo, «Móvil de pruebas 1 · ••••4821»), la dirección, el estado, el sello (la publicación con la que empezó), si se verificó, y el motivo si se denegó o se canceló. Los eventos no llevan texto de la conversación.
- **Lo que no existe en el CRM ni en Laravel:** el número completo, la transcripción y el audio. El CRM lo dice: «Sin datos personales: de cada llamada solo se ven el alias del canario o del destino y los 4 últimos dígitos. Laravel no guarda las transcripciones.»

*Fuentes: contrato D8-17; `informe-front.md` §2; CRM `26ef142`, `studio-voice-text.ts:64`.*

### 14.12 Salientes: pedir una llamada y cortarla (fases 4a y 4b)

- **«Pedir una llamada»** sale solo con `telephony.call_place`, la constante abierta, las salientes encendidas y el freno suelto. El destino es un canario de voz (fase 4a) o un contacto con consentimiento (fase 4b). Si la puerta lo rechaza, el CRM enseña **todos sus motivos en orden** (por ejemplo, fuera de horario, festivo, sin consentimiento, techo del día alcanzado).
- **Una petición es una llamada.** No hay reintentos automáticos: si no contestó o comunicaba, otro intento es otra petición. Una llamada «ambigua» (el proveedor no confirmó) **no se repite** hasta que la conciliación la resuelva.
- **«Cortar la llamada»** (`telephony.call_place`) cuelga una llamada en curso. Quien opera el servidor tiene además el corte de todas (`voice:hangup --all --confirm`). El botón vive en «Llamadas».
- **Hoy las salientes no pasan la puerta** aunque todo esté encendido: la telefonía todavía no lee la verificación de ElevenLabs (falla cerrado con `elevenlabs_unverified`); el lector llega antes de la fase 3.

*Fuentes: contrato D8-12, D8-20, E4-6, E6-6; `informe-front.md` §2; código: `app/Services/Telephony/OutboundPolicy.php:38-46`, `app/Console/Commands/Voice/VoiceHangupCommand.php`.*

### 14.13 Contactos y consentimientos (fase 4b)

**Antes de la fase 4b:** el visto bueno legal (Ley 2300, RNE, Ley 1581), las decisiones del propietario sobre intentos, horario propio y RNE, y **una publicación promovida a «todas»** (§14.8).

- **Alta de un contacto:** el número solo viaja en el formulario («El número solo viaja en este envío: el servidor guarda su cifrado, una huella y sus 4 últimos dígitos, y nunca lo devuelve.»).
- **Consentimiento, a cuatro ojos:** A lo carga (fuente `written_form`, un formulario escrito, o `admin_with_evidence`, con una evidencia de hasta 300 caracteres sin teléfonos) y **B lo confirma**. Sin confirmar no habilita ninguna llamada. **Revocarlo no espera a nadie.** El consentimiento de WhatsApp **no vale** para la voz.
- **Exclusiones:** inmediatas. Llegan del RNE, de la herramienta «No volver a llamar» (lo pide la persona durante la llamada), de las marcas del prospecto (`do_not_contact` o consentimiento denegado) o de una persona. **Solo las quita el procedimiento de supresión.**
- **RNE:** la consulta se hace a mano en el portal de la CRC y se anota; cuánto vale una consulta lo fija el propietario (hasta entonces, ninguna vale y no se llama).
- **Horario legal** (hora de Bogotá): de lunes a viernes de 07:00 a 19:00 y sábados de 08:00 a 15:00; ni domingos ni festivos. Un horario propio solo puede ser más estricto. Los festivos son 19 por año mientras siga vigente la Ley 2578 de 2026 (la Virgen de Chiquinquirá, demandada ante la Corte).
- **Frecuencia:** una llamada al día por persona (Ley 2300) y, por defecto, como mucho dos a la semana.

**Qué NO hagas:** no cargues un consentimiento sin su evidencia; no confirmes uno que cargaste tú (el servidor no lo deja); no excluyas a alguien «para probar»: una exclusión no se quita desde el CRM.

*Fuentes: contrato D8-15, D8-18, E2-1, E3-4; `informe-db.md` §3.2; código: `config/voice.php:141-168, 233-235`, `app/Services/Telephony/{OutboundSchedule,ColombianHolidays,VoiceConsentRule,VoiceContacts}.php`, `VoicePublishedConfig.php:252-255`; CRM `26ef142`, `studio-voice-text.ts:79, 91`.*

### 14.14 Costes y techos

| Techo | Defecto | Dónde se cambia |
|---|---|---|
| Salientes al día | 0 (como mucho 20) | En el servidor, por fase |
| Entrantes al día | 0 (como mucho 20) | Ídem |
| Llamadas a la vez | 1 (como mucho 4) | Ídem |
| Duración por llamada | 300 s (60–600) | Ídem; el perfil no puede pedir más |
| Presupuesto de voz del día de Bogotá | 0 US$ (con 0 no sale ninguna llamada) | Ídem |
| Laboratorio hablado | 0 US$ al día y por sesión; 5 sesiones por persona y día; 5 min por sesión | Ídem |

- **Cuánto se reserva por llamada** (con los precios oficiales del 03-11-2026 y 300 s): unos **0,96 US$** una saliente a un móvil, **1,24 US$** una entrante del canario y **0,18 US$** una entrante denegada. Es el peor caso; el coste real llega después del proveedor y de la conciliación.
- **Costes fijos:** el número local, 17,50 US$ al mes, y el plan de pago de ElevenLabs con telefonía (lo elige el propietario). El coste del modelo de lenguaje de ElevenLabs está por confirmar.
- **Dónde se mira:** la pestaña «Costes y métricas». La segunda barrera son el presupuesto y los avisos de la propia subcuenta de Twilio, que fija el propietario en su consola (P-5): un aviso de gasto echa el freno.
- **Aviso:** con el presupuesto en 0 y una llamada en el día, la conciliación echa hoy el freno de gasto (§14.9): **corrección en curso (B8-d)**.

*Fuentes: `config/voice.php:100-101, 136-137, 237-260`; código: `app/Services/Telephony/VoiceCostGuard.php:88-102` (los importes son el cálculo de su fórmula); `ULTRON_STUDIO_H8_VOICE_AUDIT.md` (precios, punto 7); contrato D8-15, P-5, P-22.*

### 14.15 Los avisos del CRM

| Lo que ves | Qué significa |
|---|---|
| «Hoy no se hace ninguna llamada: la constante del código sigue cerrada hasta la fase 3…» | Fases 1 y 2: es lo esperado |
| «El servidor todavía no tiene voz y telefonía (Hito 8)» | El backend en producción no tiene el Hito 8 |
| «La publicación de voz está apagada en el servidor (ULTRON_STUDIO_VOICE_PUBLISHING_ENABLED).» | Hasta la fase 3 |
| «El laboratorio hablado está apagado en el servidor (VOICE_LAB_ENABLED).» | Hasta la fase 2 |
| «Las salientes están apagadas en el servidor (VOICE_OUTBOUND_ENABLED).» | Hasta la fase 4a |
| «El alcance de las salientes es «canario»: los contactos de la fase 4b todavía no se llaman.» | Fase 4a |
| «El aviso de IA lo pone la plantilla vigente y no se edita aquí…» | El texto del aviso es del código; lo aprueba el propietario |
| «Freno echado» o «sin leer: cuenta como echado» | §14.9 |
| «ElevenLabs encendido, sin verificar» (Integraciones) | La verificación caducó: quien opera el servidor la renueva. **Caduca el 03-12-2026 a las 22:55 UTC** si nadie la renueva antes |

*Fuentes: CRM `26ef142`, `studio-voice-text.ts:58-126` y `studio-integrations-text.ts:533-536`; `ULTRON_STUDIO_DEPLOYMENT_PLAN.md`, «Hito 8», paso 10.*

### 14.16 Lo que la voz NO hace

- **No es un segundo agente comercial** ni toca a ULTRON de WhatsApp.
- **No atiende al público:** en el Hito 8 solo atiende a los canarios de voz; abrir el número a todos queda fuera del hito.
- **No cobra, no da enlaces de pago, no hace descuentos, no activa membresías ni agenda citas.**
- **No graba** y Laravel no guarda transcripciones ni audio.
- **No reintenta sola** ni repite una llamada ambigua.
- **No cambia un agente publicado** y no se recupera sola de un freno.
- **No toca el OTP** ni el número de WhatsApp, y no usa el consentimiento de WhatsApp.

*Fuentes: contrato «Objetivo» («Fuera del H8»), D8-10, D8-12, D8-17, D8-18; `ULTRON_STUDIO_H8_VOZ.md` §13.*

### 14.17 Si algo va mal

| Lo que ves | Qué hacer | Quién |
|---|---|---|
| «Freno echado» | No soltarlo todavía: avisar a quien opera el servidor para aclarar la causa; después lo suelta un Super Admin con la nota de la causa | Super Admin + quien opera |
| Un canario oye «línea no disponible» | Puede ser el freno, las entrantes apagadas, el cupo del día, que su número no esté activo como canario de voz o el secreto de iniciación mal puesto. Avisar a quien opera el servidor | Cualquiera |
| 409 `spec_changed` al publicar o promover | Volver a previsualizar (o a cargar el canario) y confirmar otra vez | Quien publica |
| 409 `lab_verdict_required` | Falta un veredicto «aprobado» de otra persona sobre esa misma huella | — |
| 409 `outside_publication_window` | Esperar a la ventana (07:00–19:00 de Bogotá) | — |
| «No se pudo leer…» en una pestaña | «Reintentar»; si persiste, avisar a quien opera el servidor (puede ser el cupo del CRM, el servidor o la red) | Cualquiera |
| Una persona pide durante una llamada que no la llamen más | Ya está: la herramienta «No volver a llamar» crea la exclusión en el acto | — |
| Una solicitud de supresión (Ley 1581) sobre la voz | Avisar al propietario. **La sección de voz del procedimiento de supresión todavía no existe**: llega antes de la fase 3 | Propietario + quien opera |

*Fuentes: contrato D8-10, D8-17, D8-19; `ULTRON_STUDIO_DEPLOYMENT_PLAN.md`, «Hito 8» (O-2); `informe-devops.md` §OPS8-b (pendientes).*

### 14.18 Fuentes

Contrato `ULTRON_STUDIO_H8_CONTRACT.md` (cuerpo y Enmiendas 1–9) y su auditoría `ULTRON_STUDIO_H8_VOICE_AUDIT.md`; `ULTRON_STUDIO_DEPLOYMENT_PLAN.md` («Hito 8»); `plans/informes-h8/informe-{backend,studio,lab,db,devops,front,security,tester,performance}.md`; `ULTRON_STUDIO_H8_VOZ.md`. En el backend `8d3c41f`: `app/Support/Access/{AuthorizationMap,CrmPermission,PermissionCatalog}.php` (el reparto por rol, ejecutado con `tinker`), `config/voice.php`, `config/ultron_studio.php`, `app/Services/Telephony/*.php`, `app/Services/UltronStudio/StudioVoice*.php`. En el CRM `26ef142`: `src/app/modules/ultron-studio/studio-voice-page.ts`, `studio-voice-text.ts` y `studio-integrations-text.ts`.

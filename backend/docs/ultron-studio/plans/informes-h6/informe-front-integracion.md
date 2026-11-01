# Hito 6 · paso 3 · informe del frontend: la integración de las integraciones comerciales en el CRM (I10)

**Fecha:** 01-11-2026 (Bogotá).
**Árbol:** `_worktrees/ultron-h6/Iron-Body_Front`, rama `feat/ultron-studio-h6`.
- Base: `ede716b` (la capa de datos).
- El lead confirmó mi trabajo como WIP en **`f38699d`**, tal como estaba cuando se cortó la sesión. La verificación de §5 se hizo **sobre `f38699d`**, con el árbol limpio.
- Desde entonces no he cambiado nada. **Sin commit mío.**

**Contrato:** `ULTRON_STUDIO_H6_CONTRACT.md` §I10 entero; las formas de §I2–§I7; Enmienda 1 (E1.1, E1.5) y Enmienda 2 (O-2, O-3, O-4, Q-6, Q-7).
**Backend de referencia:** el paquete S en `4e6f5fe`. Solo lo leí y exporté sus fixtures a mi scratchpad; no toqué nada.

**Intacto:** producción; `ultron-studio.scss` y `studio-inspector.scss` (los dos con su sha256 de siempre, fijado por spec); `package.json` y `package-lock.json`.

---

## 0. En una pantalla

**Página `marketing/ultron-studio/integraciones`:**
- Ruta hija diferida, en su propio chunk, con `AuthGuard` y `ultron.view`, tras `ultronStudioPreviewEnabled`.
- Enlace «Integraciones» junto a «Laboratorio» en la barra del tablero.
- Siempre a la vista: «Registro del Studio: no cambia lo que ULTRON dice o hace».

**Cinco pestañas:**
- **Estado:**
  - las banderas del proceso web con su nota obligatoria;
  - cada área con su clasificación en texto, su «cable» y su evidencia;
  - lo que puede hacer la cuenta.
- **Campañas de Meta:**
  - el espejo y sus cuentas;
  - cada campaña con dos carriles que nunca se mezclan («En Meta…» en cian y «En ULTRON Studio…» en violeta);
  - asociar o sustituir (línea base o versión `APPROVED|CANARY|PUBLISHED`);
  - desactivar con motivo, el historial y la configuración autorizada de Meta.
- **Números canarios:**
  - la coherencia siempre a la vista, con el texto obligatorio;
  - la lista enmascarada, con la etiqueta del servidor;
  - registrar: E.164, canal, alias en NFC, la casilla del consentimiento y su aviso;
  - autorizar y activar con cuatro ojos; desactivar y revocar con motivo;
  - la versión esperada frente a la que rige (`match`, `mismatch`, `stale`…).
- **Twilio:**
  - OTP, coste, credenciales sin valores, el aviso `usage-trigger` y las llamadas en recuentos;
  - la comprobación con su botón explícito, su texto, el enfriamiento y la cuenta atrás del 429;
  - la configuración autorizada con su deriva y «Reconocer».
- **Voz:**
  - `ELEVENLABS_NOT_CONFIGURED` en grande, con «En este hito no se hace ninguna llamada.»;
  - las piezas que faltan, la política con sus motivos en orden y los tres niveles;
  - **ni un botón**.

**Botones:**
- Salen solo de `can`, `flags` y `actions` del servidor.
- Si no hay botón, el motivo se lee en texto (`[data-motivo-accion]`).
- Las escrituras siguen la regla I-1 del H5.

**Con un backend del H5** (404 sin código), la página dice «El servidor todavía no tiene integraciones (Hito 6)» y el resto del Studio no cambia.

| Verificación (sobre `f38699d`) | Resultado |
|---|---|
| `ng test --watch=false --include='src/app/modules/ultron-studio/**/*.spec.ts' --include='src/app/models/**/*.spec.ts'` | **70 ficheros, 1096 pruebas, todas en verde** (antes, 60 y 1005) |
| `tsc -p tsconfig.app.json --noEmit` y `-p tsconfig.spec.json` | salida 0 y 0 |
| `npm run check:literals` | «350 ficheros revisados · literales correctos» |
| `npm run build:prod` | salida 0; **36 avisos de presupuesto, nombre a nombre los mismos que en `ede716b`**; ninguno de una hoja nueva; `build-info.ts` restaurado, árbol limpio |
| Mutaciones | **12/12 tumbadas**; la huella del árbol (`git diff` + no seguidos), `b0c16e7c…`, igual antes y después |
| Chrome con la API simulada, a 1440 y 390 px | **93/93**, sin errores de JavaScript; 32 capturas |
| Paridad con el export oficial de S | `backend-integrations.testing.ts` es **byte a byte** el de `4e6f5fe` (sha256 `23b4de10…`); los 16 ejemplos reales se leen con los lectores estrictos |

---

## 1. Ficheros (`‹F›` = `src/app/modules/ultron-studio`; líneas totales y diff frente a `ede716b`)

**Nuevos: la página y sus piezas**

| Fichero | Líneas | Qué |
|---|---|---|
| `‹F›/studio-integrations-page.ts` | 166 | `app-ultron-studio-integrations`: fases (cargando, `unavailable`, sin permiso, error), cabecera, pestañas con teclado y `?pestana=`. Provee la sesión |
| `‹F›/studio-integrations-session.ts` | 588 | `StudioIntegrationsSession`: lecturas por pestaña (una vez cada una), bloqueos por llave y bandera, escrituras con la I-1, relecturas, 404 del H5 |
| `‹F›/studio-integrations-status.ts` | 137 | `us-integrations-status`: banderas, áreas y permisos |
| `‹F›/studio-meta-campaigns.ts` | 380 | `us-meta-campaigns`: espejo, filtros, lista de dos carriles, acciones, historial y la configuración de Meta |
| `‹F›/studio-campaign-link-dialog.ts` | 193 | `us-campaign-link-dialog`: asociar o sustituir; `campaign_already_linked` y `campaign_link_moved` |
| `‹F›/studio-canary-numbers.ts` | 385 | `us-canary-numbers`: coherencia, topes, lista, acciones por `actions`, motivo (desactivar y revocar) y versión esperada |
| `‹F›/studio-canary-register-dialog.ts` | 175 | `us-canary-register-dialog`: el formulario del registro (el teléfono solo vive aquí) |
| `‹F›/studio-twilio-panel.ts` | 355 | `us-twilio-panel`: lo de hoy, la sonda con su 429 y la configuración de Twilio |
| `‹F›/studio-voice-panel.ts` | 167 | `us-voice-panel`: estado, piezas, política y niveles |
| `‹F›/studio-config-drift.ts` | 220 | `us-config-drift`: estado, huellas, deriva y «Reconocer» con su diálogo |

**Nuevas: hojas** (bytes de fuente y comprimidos con sass; el límite es de 8000 por hoja)

| Hoja | Líneas | Fuente | Comprimida |
|---|---|---|---|
| `‹F›/studio-integrations-page.scss` | 77 | 4824 | 3769 |
| `‹F›/studio-integrations-kit.scss` | 70 | 4826 | 3109 |
| `‹F›/studio-integrations-status.scss` | 18 | 1178 | 939 |
| `‹F›/studio-meta-campaigns.scss` | 19 | 1184 | 914 |
| `‹F›/studio-campaign-link-dialog.scss` | 13 | 914 | 633 |
| `‹F›/studio-canary-numbers.scss` | 24 | 1758 | 1223 |
| `‹F›/studio-canary-register-dialog.scss` | 18 | 1305 | 1017 |
| `‹F›/studio-twilio-panel.scss` | 19 | 1272 | 992 |
| `‹F›/studio-voice-panel.scss` | 25 | 1856 | 1520 |
| `‹F›/studio-config-drift.scss` | 15 | 1163 | 861 |

**Nuevos: specs y ayudas de prueba**

| Fichero | Líneas | Pruebas |
|---|---|---|
| `‹F›/studio-integrations-session.spec.ts` | 373 | 29 |
| `‹F›/studio-integrations-page.spec.ts` | 214 | 9 |
| `‹F›/studio-meta-campaigns.spec.ts` | 225 | 8 |
| `‹F›/studio-canary-numbers.spec.ts` | 231 | 9 |
| `‹F›/studio-twilio-panel.spec.ts` | 133 | 5 |
| `‹F›/studio-voice-panel.spec.ts` | 92 | 2 |
| `‹F›/studio-config-drift.spec.ts` | 115 | 3 |
| `‹F›/studio-integrations-hostile-text.spec.ts` | 148 | 5 |
| `‹F›/studio-integrations-unknown-permission.spec.ts` | 159 | 5 |
| `‹F›/studio-integrations-sheets.spec.ts` | 80 | 5 |
| `‹F›/studio-integrations-ui.testing.ts` | 80 | (ayudas: montar una pestaña con su sesión, contestar, leer el texto) |
| `‹F›/studio-integrations-examples.testing.ts` | 1097 | (los ejemplos escritos a mano de la capa de datos; ver D-F1) |

**Modificados**

| Fichero | Líneas | Diff | Qué |
|---|---|---|---|
| `src/app/app.routes.ts` | 347 | +10 | La ruta hija |
| `‹F›/ultron-studio-page.ts` | 1269 | +3 | **Solo la plantilla**: el enlace `data-integraciones-enlace` |
| `‹F›/ultron-studio-page.spec.ts` | 712 | +5 | El enlace, junto a «Laboratorio» |
| `‹F›/studio-audit-panel.ts` | 116 | +10 −1 | Los 3 sujetos nuevos en el filtro (etiquetas de `INTEGRATION_AUDIT_SUBJECT_LABELS`); con un backend del H5, un 422 de esos filtros se dice como tal (D-F12) |
| `‹F›/studio-audit-panel.spec.ts` | 181 | +26 | Lo anterior |
| `‹F›/testing/backend-integrations.testing.ts` | 1432 | +206 −199 | **Regenerado**: el export de S, byte a byte (D-F1) |
| `‹F›/studio-integrations-fixtures.testing.ts` | 56 | +21 −7 | `ejemploH6` (escritos a mano) y `ejemploServidor` (export) |
| `‹F›/studio-integrations-parity.spec.ts` | 315 | +97 −14 | Los 16 ejemplos reales con los lectores estrictos; huellas de los dos juegos; O-2 |
| `‹F›/studio-integrations-api.types.ts` | 1104 | +2 −1 | `CanaryCoherenceDto.activeWhatsapp: number \| null` (D-F2) |
| `‹F›/studio-integrations-parse.ts` | 1432 | +15 −6 | Q-6 (D-F2) y `whatsappNeedsMobile` de O-2 (D-F3) |
| `‹F›/studio-integrations-parse.spec.ts` | 569 | +20 −4 | Q-6 y O-2 |
| `‹F›/studio-integrations-text.ts` | 781 | +144 −3 | O-2, `TEXTO_BANDERAS`, la cabecera, el aviso de la nota y las vistas de la página (cables, estados, «ya estaba hecho», sonda en llano…) |
| `‹F›/studio-integrations-text.spec.ts` | 246 | +14 −2 | O-2 y la sonda en llano |

**Fuera del CRM** (en `‹B›/docs/ultron-studio/plans/informes-h6/`):
- este informe;
- `capturas-crm/arnes/mock.cjs` (292 líneas) y `capturas-crm/arnes/shoot.cjs` (331 líneas);
- `capturas-crm/chrome-resultados.json`;
- 32 capturas.

**Regenerados sin cambios** (`permissions:export`): `src/app/models/backend-permission-catalog.ts` es idéntico al export de `4e6f5fe` (sha256 `3740edf4…`). Los otros 5 ficheros de `export-fixtures` son idénticos byte a byte a los del CRM.

---

## 2. Comportamiento, pieza a pieza

### 2.1 Sesión (`StudioIntegrationsSession`)

**Carga:**
- Lee `integrations/status`. Hasta entonces, nada se ofrece (`can` y `flags` en falso).
- Un **404 sin código** → `unavailable` con `TEXTO_SIN_INTEGRACIONES`, sin pedir nada más. También si el servidor pierde las rutas a mitad.
- 403 → `forbidden`; sin red o un DTO ilegible → `error`, con «Reintentar».

**Cada pestaña** pide lo suyo al abrirse, una sola vez (`ensure`). Un fallo al releer deja lo último bueno y dice el fallo.

**Bloqueos** (antes de pedir nada; la sesión vuelve a comprobarlos):

| Acción | Necesita |
|---|---|
| Asociar y desactivar asociaciones | `campaignsManage` y `writesEnabled` |
| Registrar | `canaryManage` y `writesEnabled` (y el `limits.writesEnabled` de la lista, si ya se leyó) |
| Transiciones de un número | `actions.<acción>.allowed` de ese número |
| Reconocer la configuración | `integrationsManage`, **sin** la bandera (decisión 11) |
| Sonda | `integrationsManage` y `twilioProbeEnabled` (y `probe.enabled` del panel) |

**Escrituras con `clientRequestId`** (asociar, registrar, reconocer y la sonda) siguen la regla **I-1 del H5**:
- la misma clave al repetir el mismo cuerpo tras sin red, un 5xx, un 429 o un 2xx ilegible;
- otra si el cuerpo cambia o el servidor contesta algo definitivo (un 2xx legible o un 4xx que no es 429).

La clave va por acción y sujeto (`link:create:<campaña>`, `canary:register`, `config:ack:<proveedor>`, `twilio:probe`). Se relee lo que la escritura pudo cambiar (D-F7).

**Las transiciones** (48, 50–54) no llevan clave: un 409 `invalid_transition` cuyo `currentStatus` es el de destino se lee como «ya estaba hecho» (I4.4).

### 2.2 Estado
- **Banderas** en texto, con la nota del servidor o, si no llega, `TEXTO_BANDERAS`.
- **Áreas** en el orden del servidor, cada una con:
  - el nombre, el **cable** (`data-cable`) y la clasificación en texto;
  - el código de ElevenLabs y el motivo (Meta y Twilio);
  - la evidencia en pares etiqueta / valor.
- Un área `available: false` dice «No se pudo leer en el servidor» con el cable roto, sin valores. Los bloques ilegibles se cuentan.
- **Lo que puede hacer tu cuenta:** las 4 llaves con su etiqueta literal y sí / no, según `can`.

### 2.3 Campañas de Meta
- **Espejo:** estado, motivo, sincronización, antigüedad (la del servidor, nunca el reloj del navegador) y cuentas con `***NNNN`.
- **Texto obligatorio** siempre, bajo el título.
- **Filtros:** estado en Meta, asociación y cuenta. Un filtro nuevo vuelve a la página 1.
- **Cada campaña:**
  - nombre, `ref` y cuenta;
  - carril **Meta**: `campaignMetaBadges` («En Meta: Activa · hace 42 min», «Posiblemente eliminada», «Dato viejo»);
  - carril **ULTRON Studio**: la insignia, el destino, quién y cuándo.
  - Los dos carriles son columnas separadas, con borde cian y violeta.
- **Acciones** (solo con la llave y la bandera; si no, el motivo en `[data-motivo-accion="campaigns"]`):
  - sin asociación: «Asociar»;
  - con asociación: «Sustituir» y «Desactivar» (`ib-reason-dialog` con `AVISO_MOTIVO_INTEGRACIONES`);
  - siempre: «Historial», que filtra el historial por esa campaña y le pone el foco.
- **Diálogo de asociar:**
  - línea base o versión asociable (leídas del Studio, sin escribir: `GET versions?status=APPROVED,CANARY,PUBLISHED&per_page=50`);
  - sin elegir versión, el error va junto al campo;
  - `campaign_already_linked` → el mensaje del servidor y «Sustituir la activa», sin reintento automático;
  - `campaign_link_moved` → toma la activa de ahora, o pasa a asociar si ya no hay ninguna.
- **Historial** paginado, con los filtros todas, activas y desactivadas.
- **Abajo:** la configuración autorizada de Meta.

### 2.4 Números canarios
- **Coherencia** siempre a la vista:
  - el veredicto en frase (`coherenceView`), con el tono en el borde y el texto;
  - `TEXTO_CANARIOS`;
  - si el canario del motor está configurado;
  - cuántos WhatsApp hay activos, o «No se pudieron contar» con `null` (Q-6).
- **Topes y países**, en una frase.
- **«Registrar un número»** solo con la llave y la bandera. Si no, el motivo; y la bandera apagada se dice **una vez** arriba (D-F8).
- **Cada número:**
  - alias, `masked` (tabular) y canal;
  - el estado con la **etiqueta del servidor** e icono y tono del CRM;
  - «Es el teléfono de la conversación canaria del motor» si `isEngineCanary === true`;
  - quién registró, autorizó, activó o revocó, con su motivo;
  - la versión esperada (con «vieja» si `stale`), la que rige (`effectiveVersionText`) y la expectativa (`data-expectativa`).
- **Botones** solo de `actions`.
- **Motivos** en `[data-motivo-accion="<acción>"]`, solo para las acciones que tienen sentido en ese estado y no son `writes_disabled` ni `invalid_transition`. Ejemplos:
  - quien registró lee «Autorizar: Nadie autoriza un número que registró…»;
  - el Administrador lee la llave que le falta.
- **Autorizar y activar** son un clic: el cuerpo es vacío en el contrato.
- **Desactivar y revocar:** `ib-reason-dialog` con aviso de datos personales. Revocar uno de voz dice que su cifrado se borra.
- **«Versión esperada»:** `ib-dialog` con las asociables o «Ninguna» (viaja `null`).
- **Respuestas:**
  - «ya estaba hecho» → aviso en la región viva;
  - otro error → `role=alert` junto a su número.
- **Diálogo de registro:**
  - canal, teléfono (`type=tel`, `autocomplete=off`), función con `TEXTO_ALIAS` y versión esperada opcional;
  - la casilla con `TEXTO_CONSENTIMIENTO` y `TEXTO_CANARIOS` como descripción;
  - comprobaciones locales por campo, sin pedir nada: E.164 con O-2, alias y consentimiento;
  - el alias viaja en NFC (O-3);
  - un 422 del servidor va junto a su campo y no borra lo escrito;
  - la vista previa solo enseña la forma enmascarada;
  - al registrarse, el diálogo se cierra con el teléfono: no queda en la pantalla (lo comprueban la spec y Chrome).

### 2.5 Twilio
- **Cabecera:** cable, clasificación y motivo.
- **Bloques:**
  - OTP;
  - coste del día (cifra grande, aviso y techo);
  - credenciales: `ref` y huella cortas, de qué variable salen y los dos nombres;
  - el aviso `usage-trigger`, tal cual lo describe el servidor;
  - Twilio Voice;
  - llamadas en recuentos (`[]` o `{}`, O-4) y, a petición, la lista paginada sin datos del prospecto.
- **Comprobación de solo lectura:**
  - `TEXTO_SONDA` junto al botón, que solo sale con la llave y su bandera; si no, el motivo;
  - la credencial con la que lee y el enfriamiento en llano;
  - `reused` y `replayed`, en un aviso;
  - **429**: el botón queda deshabilitado con «Comprobar (en N s)» y no se pide antes de tiempo.
- **Última comprobación:**
  - resultado, cuándo y quién;
  - cuenta y números del gimnasio por sus 4 dígitos;
  - llamadas y alertas en llano («3: 1 de error, 2 de aviso; códigos 60200 (3)»);
  - los pasos que fallaron.
- **Abajo:** la configuración autorizada de Twilio.

### 2.6 Voz
- **El estado en grande:** un cable punteado que no llega a nada, la frase en la letra del Studio y el código en mono.
- **`TEXTO_VOZ`** destacado.
- **ElevenLabs:** bandera, región, piezas que faltan por su nombre y la última verificación con su audio μ-law, o «se hace por consola, nunca desde aquí».
- **Twilio Voice.**
- **Política:** «No permite llamar» y los motivos numerados en orden.
- **Niveles:** el de texto, «Sin coste» con un enlace al laboratorio; los otros dos, con sus motivos.
- **Ningún botón**: lo comprueban la spec y Chrome.

### 2.7 Configuración autorizada (`us-config-drift`, en Meta, Twilio y Voz)
- `TEXTO_CONFIGURACION`, el estado y la huella vigente (12 caracteres; la entera, en el título).
- Quién autorizó y cuándo, con su huella y su nota.
- Lo observado por el sistema y «Qué cambió desde que se autorizó» (`driftText`).
- «Ver la configuración vigente (sin secretos)», plegable.
- **«Reconocer como autorizada»:**
  - sale solo con la llave y si el estado no es `authorized`; si no, el motivo;
  - el diálogo dice qué huella se reconoce y pide una nota opcional, con su aviso de datos personales (las instantáneas no se borran);
  - un **409 `config_changed`** enseña «La configuración cambió desde que la abriste», con lo nuevo; la siguiente confirmación envía la **huella nueva** (lo fija la mutación M8).

---

## 3. Decisiones fuera del contrato (D-F), con su motivo

- **D-F1. El fixture del servidor, byte a byte, y los ejemplos a mano aparte.**
  - S exportó `backend-integrations.testing.ts` con **otros datos sintéticos**. La estructura (conjuntos, códigos, permisos, límites y textos) es idéntica; la Q-8 de la capa de datos lo preveía.
  - Lo copié tal cual (sha256 `23b4de10…`). La paridad lee ahora **los 16 ejemplos reales** con los lectores estrictos: sin descartes, sin evidencia ni hechos de la sonda en `null` en silencio, y con la huella del reconocimiento igual a la efectiva.
  - Los ejemplos escritos a mano pasaron a `studio-integrations-examples.testing.ts`, propiedad del CRM, como `studio-api-fixtures.testing.ts` en el H5. Las specs de lectores y componentes necesitan escenarios concretos (voz pendiente junto al canario activo, sonda con un paso fallido, deriva…).
  - Así 24 specs de la capa de datos siguen probando lo mismo sin reescribirlas contra datos sintéticos que pueden volver a cambiar. Las huellas de los dos juegos se comprueban igual.
- **D-F2. Un desacuerdo real encontrado al regenerar (Q-6).**
  - Con la coherencia `unreadable`, S manda `activeWhatsapp: null`; con `no_engine_canary`, también si el recuento falló (`StudioCanaryCoherence::read`).
  - El lector exigía un entero, así que **la pestaña de canarios entera habría quedado ilegible justo cuando la coherencia falla**.
  - Ahora `null` se acepta solo con esos dos veredictos (con los demás sigue siendo ilegible) y la página dice «No se pudieron contar». Lo prueban la parse spec, la paridad y la mutación M11.
- **D-F3. O-2.**
  - `checkCanaryPhone` devuelve `whatsappNeedsMobile`: canal WhatsApp y un número que no empieza por `+573`.
  - El texto general es «Ese número no sirve para este canal.»; la pista «(WhatsApp exige un móvil)» va solo en ese caso.
  - El servidor de `4e6f5fe` todavía manda la pista siempre. La paridad acepta que su texto sea **uno de los dos** del CRM hasta que S lo alinee (Q-1); los otros tres textos se comparan exactos.
- **D-F4. El kit del laboratorio reutilizado.**
  - La página carga `studio-lab-kit.scss` (botones, campos, avisos y estados `lab-*`) junto a su hoja y su kit propios.
  - Declara los 24 tokens `--us-*` en `.ig-root`; `studio-integrations-sheets.spec.ts` compara nombres y valores con `ultron-studio.scss`, como el laboratorio.
  - El presupuesto `anyComponentStyle` del build se mide **por hoja** (`budget-stats.js`), así que tres hojas en la página no suman un aviso.
- **D-F5. La regla global del CRM `p, small { color: var(--ib-muted) !important }`** (`src/styles.css:351-367`).
  - Pisaba el color de **todos** los `<p>` y `<small>`: el rojo de los errores junto al campo, las alertas y el violeta de los textos obligatorios. Lo cazó la revisión de las capturas.
  - Los textos con color propio van ahora en `div` y `span`, con una nota en el kit. Chrome comprueba el color calculado (error `#fda4af`, alerta `#fecdd3`).
  - **El laboratorio tiene el mismo problema latente** (`<p class="lab-alert">`): no lo toqué (Q-5).
- **D-F6. `.lab-btn:hover` del kit del laboratorio** pone un fondo liso que le quita el degradado al botón principal, que se ve apagado con el ratón encima (en el laboratorio también).
  - Lo corregí **solo bajo `.ig-root`**, en mi kit; Chrome lo comprueba.
  - En el laboratorio sigue igual (Q-5).
- **D-F7. Cuándo se relee tras escribir:**
  - sí, con un 2xx (legible o no), un 403, un 404 o un 409, como en el H5;
  - no, con un 422, un 413, un 429, un 5xx o sin red: no cambian nada.
- **D-F8. Motivos por número**, como D-F1 del H5:
  - solo para las acciones que tienen sentido en su estado; `invalid_transition` nunca se menciona;
  - `writes_disabled` se dice **una vez** arriba, en vez de repetirse en cada número.
- **D-F9. Activar sin motivo.**
  - El mensaje del lead decía «activar/desactivar/revocar con motivo», pero la ruta 51 tiene **cuerpo vacío** en el contrato. Activar y autorizar son un clic.
  - Desactivar y revocar llevan `ib-reason-dialog`.
- **D-F10. La configuración autorizada en las tres pestañas** (Meta, Twilio y Voz), cada proveedor en la suya.
  - I10 solo la nombra en Twilio, pero la fase 2, paso 3, pide reconocer los tres.
  - «Reconocer» no se ofrece si ya está `authorized`.
  - La nota lleva aviso de datos personales (`AVISO_DATOS_NOTA_CONFIGURACION`).
- **D-F11. Pestañas diferidas.** Solo se monta la abierta y cada sección se lee una vez. `?pestana=<id>` (enlaces y capturas) ignora valores desconocidos.
- **D-F12. El libro con un backend del H5.** El H5 valida `subjectType` con 4 sujetos: elegir uno de los 3 nuevos da un 422. El panel lo dice como «El servidor todavía no tiene integraciones (Hito 6): este filtro todavía no existe en el servidor.», sin parecer un fallo. Con un sujeto de siempre, un 422 sigue siendo un fallo normal.
- **D-F13. El teléfono en memoria.**
  - Vive en la señal del diálogo y en la firma de su clave de idempotencia **hasta la respuesta definitiva**, el mismo tiempo que en el formulario.
  - Nunca va en una señal de la sesión, ni al almacenamiento, ni al DOM tras registrarse.
- **D-F14. 390 px en el tablero.** El quinto icono de la barra («Integraciones») añade una fila a la cabecera del tablero en móvil (`movil-16`).
  - El sitio lo fija el contrato y la cabecera la gobierna `ultron-studio.scss`, intocable.
  - Es cosmético; lo dejo dicho (Q-3).
- **D-F15. La nota de las banderas:** la del servidor (`note`); si no llega, `TEXTO_BANDERAS` (la paridad comprueba que el export trae esa misma).
- **D-F16. El asistente flotante (D-F9 del H5).**
  - La página deja 96–104 px abajo, y los pies de mis cuatro diálogos le dejan su esquina a 390 px (`padding-right: 68px`). Chrome comprueba que no tapa el botón.
  - El de `ib-reason-dialog` es compartido y no lo toqué, como en el H5.

---

## 4. Atributos `data-*` para el E2E del tester (`specs/h6-integrations.cjs`)

| Pieza | Atributos |
|---|---|
| Página | `[data-integraciones-pagina][data-integraciones-fase="loading\|ready\|unavailable\|forbidden\|error"]`, `[data-integraciones-registro]`, `[data-integraciones-pestana="estado\|meta\|canarios\|twilio\|voz"]` (`role=tab`, `aria-selected`), `#ig-panel-<id>`, `[data-integraciones-sin-h6]`, `[data-integraciones-sin-permiso]`, `[data-integraciones-error]` + `[data-integraciones-reintentar]`, `[data-integraciones-volver]`. Enlace del tablero: `a[data-integraciones-enlace]` |
| Estado | `[data-integraciones-banderas]`, `[data-integraciones-nota-banderas]`, `[data-proveedor="<id>"][data-clasificacion="<clasificación>\|no-disponible"]` > `.ig-cable[data-cable="solid\|dashed\|dotted\|double\|broken"]`, `[data-proveedor-clasificacion]`, `[data-proveedor-codigo]`, `[data-proveedor-motivo]`, `[data-proveedor-evidencia]`; `[data-integraciones-descartados]`; `[data-permiso="ultron.*"][data-concedido]` |
| Campañas | `[data-meta-conexion][data-estado]`, `[data-meta-motivo]`, `[data-meta-cuenta]`; `[data-texto-campanas]`; `[data-motivo-accion="campaigns"]`; filtros `[data-campanas-filtro-estado\|-asociacion\|-cuenta]`; `[data-campana="campaign:…"]` > `[data-campana-meta]`, `[data-campana-ultron][data-asociada="si\|no"]`, `[data-campana-destino]`, `[data-campana-asociar\|-sustituir\|-desactivar\|-historial]`; `[data-campanas-vacio]`; historial `[data-asociaciones]`, `[data-asociaciones-filtro="todas\|active\|inactive"]`, `[data-asociacion="<uuid>"][data-estado]`, `[data-asociacion-desactivada]`, `[data-asociaciones-campana]`, `[data-asociaciones-todas]`; aviso `[data-meta-aviso]`; desactivar `[data-desactivar-dialogo]` (aviso `#mc-desactivar-datos`) |
| Asociar | `[data-asociar-dialogo][data-asociar-modo="asociar\|sustituir"]`, `[data-asociar-tipo="baseline\|version"]`, `[data-asociar-version]`, `[data-asociar-actual]`, `[data-asociar-enviar]`, `[data-asociar-error]`, `[data-asociar-sustituir-actual]`, `[data-asociar-sin-versiones]` |
| Canarios | `[data-coherencia][data-veredicto]`, `[data-coherencia-texto]`, `[data-coherencia-activos]`, `[data-texto-canarios]`; `[data-canario-registrar]`; `[data-motivo-accion="register\|writes"]`; `[data-canarios-topes]`; filtros `[data-canarios-filtro-estado\|-canal]`; `[data-canario="<uuid>"][data-estado][data-canal]` > `[data-canario-numero]`, `[data-canario-estado]`, `[data-canario-motor]`, `[data-canario-revocado]`, `[data-canario-esperada]`, `[data-canario-esperada-vieja]`, `[data-canario-rige]`, `[data-canario-expectativa][data-expectativa]`, `[data-canario-accion="authorize\|activate\|deactivate\|revoke\|expectation"]`, `[data-motivo-accion="<acción>"]`, `[data-canario-error]`; aviso `[data-canarios-aviso]`; motivo `[data-canario-dialogo="deactivate\|revoke"]` (aviso `#cn-motivo-datos`); esperada `[data-esperada-dialogo]`, `[data-esperada-version]`, `[data-esperada-enviar]`, `[data-esperada-error]` |
| Registrar | `[data-registrar-dialogo]`, `[data-registrar-canal="whatsapp\|voice"]`, `[data-registrar-telefono]`, `[data-registrar-alias]`, `[data-registrar-version]`, `[data-registrar-consentimiento]`, `[data-texto-consentimiento]`, `[data-registrar-previa]`, `[data-registrar-error="phone\|alias\|consentAttested\|channel\|expectedVersionUuid"]`, `[data-registrar-error-form]`, `[data-registrar-enviar]` |
| Twilio | `[data-twilio-estado][data-clasificacion]`, `[data-twilio-motivo]`, `[data-twilio-otp\|-coste\|-credenciales\|-webhooks\|-voz\|-llamadas]`, `[data-twilio-webhook="usage_trigger"]`, `[data-twilio-llamadas-total]`, `[data-twilio-llamadas-ver]`, `[data-twilio-llamadas-lista]`; sonda `[data-sonda]`, `[data-texto-sonda]`, `[data-sonda-lanzar]` (deshabilitado durante la espera), `[data-motivo-accion="probe"]`, `[data-sonda-aviso]`, `[data-sonda-error]`, `[data-sonda-ultima][data-resultado]`, `[data-sonda-hechos]`, `[data-sonda-errores]`, `[data-sonda-nunca]` |
| Voz | `[data-voz-estado][data-estado]`, `[data-voz-codigo]`, `[data-texto-voz]`, `[data-voz-falta="<pieza>"]`, `[data-voz-verificacion]`, `[data-voz-politica][data-permitida]`, `[data-voz-motivo="<motivo>"]`, `[data-voz-nivel="<id>"][data-disponible]` |
| Configuración | `[data-config="meta_ads\|twilio\|voice"][data-config-estado]`, `[data-texto-configuracion]`, `[data-config-huella]`, `[data-config-autorizada]`, `[data-config-observada]`, `[data-config-deriva]` > `[data-config-deriva-clave]`, `[data-config-efectiva]`, `[data-config-reconocer]`, `[data-motivo-accion="acknowledge"]`; diálogo `[data-config-dialogo]`, `[data-config-nota]`, `[data-config-cambio]`, `[data-config-reconocer-enviar]`, `[data-config-dialogo-error]`; aviso `[data-config-aviso]` |

Como en el H5, `waitForSelector({hidden:true})` no sirve con `ib-dialog`: su host mide 0×0. Para esperar a que un diálogo se cierre, comprueba que el selector ya no está en el DOM.

---

## 5. Verificación (salidas reales)

### 5.1 Suite, tipos y literales (sobre `f38699d`)

| Comando | Resultado |
|---|---|
| `node_modules/.bin/ng test --watch=false --include='src/app/modules/ultron-studio/**/*.spec.ts' --include='src/app/models/**/*.spec.ts'` | `Test Files 70 passed (70) · Tests 1096 passed (1096)`. Las nuevas (91): 80 en los 10 specs nuevos (comprobado: «Test Files 10 passed · Tests 80 passed») y 11 en specs ampliados (parity +7, parse +2, texto +1, auditoría +1). La de la página del tablero se amplió dentro de un `it` existente |
| `node_modules/.bin/tsc -p tsconfig.app.json --noEmit` | salida 0 |
| `node_modules/.bin/tsc -p tsconfig.spec.json --noEmit` | salida 0 |
| `npm run -s check:literals` | «350 ficheros revisados · literales correctos» |

### 5.2 Build de producción (`npm run build:prod`; después, `git restore src/environments/build-info.ts` y el árbol queda limpio)

La base `ede716b` se construyó en un worktree temporal del scratchpad, ya borrado.

| Chunk | `ede716b` | Ahora | Transferido ahora |
|---|---|---|---|
| Inicial total | 818,43 kB | 818,85 kB | 169,48 kB |
| `main` | 139,26 kB | 139,43 kB | 26,54 kB |
| `ultron-studio-page` | 719,04 kB | 719,52 kB | 148,55 kB |
| `studio-lab-page` | 188,54 kB | 188,58 kB | 44,67 kB |
| **`studio-integrations-page`** (nuevo, diferido) | — | **162,46 kB** | **38,03 kB** |

- **Avisos de presupuesto:** 36 en la base y 36 ahora. `diff` de los nombres: **vacío**. El único del Studio sigue siendo el de `ultron-studio.scss`, que ya existía.
- **Hojas nuevas:** ninguna da aviso. La mayor, comprimida, pesa 3769 bytes.
- `innerHTML`, `outerHTML`, `bypassSecurityTrust` e `insertAdjacentHTML`: 0 en los ficheros nuevos (lo vigila `studio-integrations-sheets.spec.ts`).

### 5.3 Mutaciones (cada una cambia una cadena exacta del código de producción, corre las specs que la deberían tumbar y restaura)

Huella del árbol (`git diff` + ficheros no seguidos): `b0c16e7c8e200563`, igual antes y después de cada tanda. El script está en el scratchpad (`mutar.py`).

| # | Mutación | Resultado |
|---|---|---|
| M1 | Una transición de número sin mirar `actions` | cae (1) |
| M2 | I-1: la clave siempre gastada | cae (7) |
| M3 | El alias sin NFC (O-3) | cae (2) |
| M4 | Un backend del H5 tratado como error | cae (2) |
| M5 | El botón de un número sin mirar `actions` | cae (2) |
| M6 | El alias como `[innerHTML]` | cae (2: texto hostil y hojas) |
| M7 | La sonda ignora el 429 | cae (1) |
| M8 | Tras `config_changed` se reenvía la huella vieja | cae (1) |
| M9 | El filtro del libro con un H5 parece un fallo | cae (1) |
| M10 | Botones de campañas sin la llave ni la bandera | cae (2) |
| M11 | El lector vuelve a exigir `activeWhatsapp` entero (Q-6) | cae (2) |
| M12 | El texto obligatorio de canarios alterado | cae (1) |

### 5.4 Chrome real con la API simulada (no es el E2E con la pila real)

**Cómo se probó:**
- `ng serve` en `127.0.0.1:4317` y puppeteer-core del repo con Chrome (`/Applications/Google Chrome.app`).
- `127.0.0.1:8080` lo respondía `capturas-crm/arnes/mock.cjs`, que tiene:
  - las formas del **export oficial de S**;
  - estado mutable para asociar, registrar, autorizar, activar, revocar, reconocer y la sonda;
  - un 409 `config_changed` la primera vez y un 429 en la segunda sonda;
  - el resto del Studio delegado al simulador del H5.

**Resultado: 93/93** a 1440 y 390 px, sin errores de JavaScript (`capturas-crm/chrome-resultados.json`).

**Qué se comprueba en cada ancho:**
- **Fase 1 de producción** (escrituras y sonda apagadas):
  - cabecera, nota de las banderas y las 5 clasificaciones;
  - campañas sin botones y con el motivo; los dos carriles;
  - canarios sin registrar, con la bandera dicha una vez;
  - Twilio sin sonda y con el motivo;
  - voz `ELEVENLABS_NOT_CONFIGURED` sin ningún botón;
  - **cero escrituras**.
- **Super Admin, campañas:**
  - asociar a una versión: una sola petición;
  - foco atrapado con Tab y Mayús+Tab; el asistente no tapa el botón;
  - desactivar con el aviso de datos personales y el historial con su motivo.
- **Super Admin, canarios:**
  - los errores por campo sin pedir nada, en rojo (color calculado);
  - «Registrar» conserva su degradado con el ratón encima;
  - la vista previa enmascarada, y el número nuevo pendiente y enmascarado;
  - **el teléfono completo no está en la pantalla**;
  - cuatro ojos sobre el número propio;
  - autorizar y activar el de voz registrado por Ana;
  - revocar dice que se borra el cifrado; Escape cierra y devuelve el foco a «Revocar».
- **Administrador:** sin «Autorizar» y con la llave que le falta.
- **Twilio:**
  - la sonda (201), luego un 429 con cuenta atrás y el botón deshabilitado (2 peticiones);
  - la alerta en su color;
  - reconocer: 409 `config_changed` → confirmar con la huella nueva → `authorized`.
- **Backend del H5:** el texto de I10, y el tablero sigue igual con su enlace.
- **Sin desplazamiento horizontal** en el documento ni en la raíz de la página en ningún paso.

**Capturas** (en `capturas-crm/`, `escritorio-NN-…` y `movil-NN-…`):

| N.º | Qué enseña |
|---|---|
| 01 | Estado (fase 1: banderas apagadas) |
| 02 | Campañas en solo lectura |
| 03 | Canarios en solo lectura |
| 04 | Twilio en solo lectura |
| 05 | Voz |
| 06 | Diálogo de asociar a una versión |
| 07 | Motivo de desactivar |
| 08 | Campañas tras escribir |
| 09 | Registrar con errores por campo |
| 10 | Canarios del Super Admin |
| 11 | Motivo de revocar (voz) |
| 12 | Administrador |
| 13 | Sonda con 429 |
| 14 | Reconocer tras `config_changed` |
| 15 | Backend del H5 |
| 16 | Tablero con el enlace |

**Lo que se corrigió al mirar las capturas:**
- La regla global de `p` y `small` (D-F5).
- El degradado perdido con el ratón encima (D-F6).
- Las columnas de las campañas desalineadas entre filas (la de acciones ahora tiene ancho fijo).
- Los dobles puntos tras las fechas («p. m..»).
- La sonda en llano.

Una franja clara al pie de una captura la producía el propio arnés: `scrollIntoView({block:'start'})` desplazaba por programa el lienzo del shell, que tiene `overflow:hidden`. Medido: para quien usa la página solo se desplaza `.ig-root`; no hay doble scroll. El arnés usa ahora `nearest`.

**Para repetirlo:**
1. `ng serve --port 4317 --host 127.0.0.1` en el CRM.
2. Desde `capturas-crm/`: `CRM_DIR=<CRM> node arnes/shoot.cjs http://127.0.0.1:4317 <CRM>/node_modules/puppeteer-core .`

---

## 6. Dudas para S y el lead

- **Q-1 (S). O-2 en el servidor.**
  - `StudioCanaryPhone::MESSAGES[not_allowed_for_channel]` sigue siendo «… (WhatsApp exige un móvil).» para todo caso, también con un móvil de longitud equivocada y en voz.
  - El CRM ya lo hace como fija O-2 y su paridad tolera los dos textos.
  - Cuando S lo alinee, se vuelve a exportar `backend-integrations.testing.ts` y la paridad de ese mensaje pasa a ser exacta.
- **Q-2 (S). `activeWhatsapp: null` también en `no_engine_canary`** si el recuento falló. Lo acepto (D-F2); conviene dejarlo escrito en `informe-api.md` (Q-6 de la Enmienda 2 se lo encargaba a S).
- **Q-3 (lead). 390 px del tablero** (D-F14): el quinto icono añade una fila a la cabecera. Arreglarlo exige tocar `ultron-studio.scss` (prohibido). Propongo dejarlo así.
- **Q-4 (lead y DOC).** Sigue en pie la Q-7 de la capa de datos: el runbook de reversión, nivel 3, dice que el CRM del H5 pinta en genérico los sujetos nuevos del libro. No es así: los descarta como ilegibles. No se pierde nada.
- **Q-5 (lead). Dos defectos del kit del laboratorio, fuera de mi alcance:**
  - la regla global de `p` y `small` deja `<p class="lab-alert">` en gris (D-F5);
  - el hover del botón principal le quita el degradado (D-F6).
  - En las integraciones están resueltos solo bajo `.ig-root`. Si se quieren arreglar en el laboratorio, es un cambio aparte de sus plantillas y de `studio-lab-kit.scss`.
- **Q-6 (lead). Activar sin motivo** (D-F9): tu mensaje lo pedía con motivo, pero el contrato (ruta 51) no tiene cuerpo. Si se quiere un motivo, hace falta una enmienda en el backend.

## 7. No verificado

- **La pila real.** Ni las specs ni la prueba en Chrome usan el backend del paso S: usan su export y un simulador con sus formas. El E2E con la pila real (`h6-integrations.cjs`) es del tester.
- **La suite completa del CRM** fuera de `ultron-studio` y `models`. Esta vez no la corrí; el lead pedía estos dos grupos.
- **Lectores de pantalla reales.** Se comprobó la semántica en las specs (roles, `aria-*`, foco) y el teclado en Chrome (pestañas, Tab, Mayús+Tab y Escape).
- **El pie de `ib-reason-dialog` a 390 px** frente al asistente flotante: es compartido y no lo toqué, como en el H5.

## 8. Para security y para el tester

**Security:**
- No hay tokens ni sesión nuevos; la página no consulta permisos locales (lo prueba una spec estática).
- Todo texto del servidor se interpola (`studio-integrations-hostile-text.spec.ts` y la mutación M6); cero `innerHTML`.
- **El teléfono:** viaja solo en `POST integrations/canaries`; se ve solo enmascarado; no queda en pantalla, en la sesión ni en el almacenamiento (D-F13; Chrome lo comprueba).
- Los motivos y la nota de la configuración llevan su aviso de datos personales, enlazado con `aria-describedby`.
- De las credenciales solo se pintan presencia, origen, `ref` y huellas cortas.

**Tester, los flujos críticos:**
- Registrar como Administrador → el Administrador no ve «Autorizar» → autorizar y activar como Super Admin → `in_sync`.
- Un segundo WhatsApp activo → `registry_has_extra_active`.
- Revocar.
- Asociar a la línea base, sustituir por una versión y desactivar.
- La sonda con `reused` y el 429 (11 o más por hora).
- Reconocer frente a un cambio de configuración.
- Voz sin botones.
- La página con un backend del H5.
- Con teclado: las pestañas con flechas, Inicio y Fin; Tab y Mayús+Tab dentro de los cuatro diálogos; Escape devuelve el foco al botón que abrió.

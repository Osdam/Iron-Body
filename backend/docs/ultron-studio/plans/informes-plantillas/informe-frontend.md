# Plantillas de WhatsApp de Meta en el CRM · informe de `frontend` (pasos 1d y 4)

> **Autor:** fe-tpl (frontend), 2026-11-11. **CRM:** `Iron-Body_Front`, rama `feat/ultron-studio-h9`, HEAD `a539690`. **Sin commit ni push.**
> **Plan:** `plans/PLANTILLAS_META_PLAN.md` §1.7, §5.1, §5.4, §5.8 y §5.13; L9-96. **Contrato:** `informes-plantillas/dtos-para-frontend.md` (JSON reales).
> **Skill:** `frontend-design` cargada antes de diseñar (§2).

## Resumen para el lead (lo primero)

- **Hecho y en verde:** la pestaña «Plantillas» de Campañas automáticas, rehecha para las plantillas de Meta:
  - la franja «Conexión con Meta», con la sonda de permisos;
  - una lista con dos carriles por fila (Studio y Meta) y la columna «Lista para campañas»;
  - el editor a ancho completo con la vista previa del WhatsApp del socio en vivo;
  - el cajón de detalle con el «Camino a las campañas»;
  - «Enviar a Meta» con un diálogo que pide confirmar, y «Comprobar ahora»;
  - el historial, la versión corregida y el refresco cada 60 s mientras Meta revisa.
- **Notas de seguridad del lead, aplicadas:**
  - **PM-5:** una comprobación `meta_api` se pinta como «Registrada por la sincronización con Meta (en nombre de X)». Los eventos de lectura de Meta del historial los firma la sincronización.
  - **Diálogo:** dice en dos líneas destacadas que crea una plantilla **REAL** y que **no se puede deshacer desde el CRM**. La casilla que se confirma repite las dos cosas.
- **Cerrado contra los DTO reales** de `dtos-para-frontend.md`: lectores estrictos, más 4 rutas en el servicio y en la sesión.
- **Cifras:**
  - specs: **20 ficheros y 578 pruebas en verde** (los 18 del módulo, `permission-contract` y `access-control.trainer`);
  - `tsc` de app y de spec limpios;
  - build de producción: exit 0, **38 avisos, los mismos de antes; ninguno en ficheros tocados**;
  - **20 capturas** a 390 y 1 440 px, WhatsApp en claro y en oscuro, **ninguna con desplazamiento horizontal** y **0 errores de página**.
- **Fuera del módulo toqué 3 ficheros, todos por la llave nueva** (§5):
  - `models/permissions.enum.ts`: +1 entrada;
  - `services/access-control.service.ts`: excluyo la llave del Administrador, como hace el backend en `CrmPermission.php:336`;
  - `models/permission-contract.spec.ts`: la lista de llaves `ultron.*`.
  - **Sin el segundo, el CRM le concedería la llave al Administrador.** Lo señalo a `security`.
  - **No toqué** `ultron-studio/**` ni `backend-permission-catalog.ts`; este último lo modificó be-tpl.
- **Para el commit:**
  - van los ficheros de §5;
  - **no** van los de `src/app/modules/ultron-studio/**` que hay en el árbol: son de otro agente («prompts», `studio-publication-*`);
  - `backend-permission-catalog.ts` es de be-tpl y va con este trabajo.
- **No verificado:**
  - el E2E con navegador contra el backend real (el paso `plantillas` de `recorridos-h9.cjs` sigue con sus selectores, comprobado por spec);
  - el envío real a Meta;
  - lectores de pantalla reales;
  - la batería completa del CRM (la corre el lead).
- **Ya fallaba antes, no es regresión mía:** `access-control.moderation.spec.ts` da 6 rojos de 14 también en una copia limpia de HEAD `f5cd0d2` (§6.3).

---

## 1. Qué hay

| Fichero (módulo `commercial-automation/`) | Líneas | Qué |
|---|---|---|
| `ca-wa-format.ts` (1d, del agente anterior, terminado) | 542 | Puro: el formato de WhatsApp en fichas (`*negrita*`, `_cursiva_`, `~tachado~`, monoespaciado, código, saltos), los avisos de lo que no casaría, la paleta de WhatsApp con contraste AA y, nuevo, `waRenumber()` |
| `ca-wa-preview.ts` + `.scss` (1d) | 276 + 110 | La vista previa: el teléfono del socio solo con CSS, la burbuja, la hora y el doble check, claro y oscuro, «Ejemplos/Marcadores», «Socio/Gimnasio» y la parte que se edita señalada |
| `ca-meta-text.ts` (nuevo) | 537 | El vocabulario de Meta en español y lo que se deduce para enseñarlo: el carril del ciclo de vida, el chip de la lista, la franja de conexión, los avisos del estado, el filtro, quién registró qué (PM-5) y los nombres propuestos |
| `ca-templates.ts` + `.scss` (rehecho) | 285 + 44 | La pestaña: cabecera, conexión con Meta, filtros (también «En Meta»), la lista y la orquestación del editor y del cajón |
| `ca-tpl-editor.ts` + `.scss` (nuevo) | 720 + 115 | El editor a ancho completo: el formulario por secciones, el teléfono fijo y la hoja inferior en móvil |
| `ca-tpl-detail.ts` + `.scss` (nuevo) | 541 + 95 | El cajón: el camino a las campañas, los avisos, «¿lista?», las acciones, la vista previa, lo que dice Meta, el Studio, el historial y los diálogos (aprobar, rechazar y enviar a Meta) |
| `commercial-automation-api.types.ts` | +183 | Los DTO de §5.8.2 / dtos §1–§5, los 12 códigos de error nuevos y el contenido ampliado |
| `commercial-automation-parse.ts` | +203 | Los lectores: catálogo `templates.meta`, `meta` de la fila y del detalle, `waPreview`, `status`, sonda y `refresh` |
| `commercial-automation-api.service.ts` | +34 | Las 4 rutas: `metaTemplatesStatus`, `probeMetaTemplates`, `submitTemplateToMeta` y `refreshTemplateMeta` |
| `commercial-automation-session.ts` | +76 | La sección `metaStatus`, la sonda, enviar (idempotente), comprobar y el refresco silencioso |
| `commercial-automation-permissions.ts` | +2 | `metaTemplatesSubmit` |
| `ca-builder.ts` | 1 línea | El selector de plantillas de un paso dice «lista para campañas» o «aún no lista en Meta» (plan §5.13.2) |
| `testing/ca-examples.testing.ts` | +201 | Los ejemplos de Meta copiados de los JSON reales de `dtos-para-frontend.md` |

**Specs nuevas:**
- `ca-meta-api.spec.ts`: 15 pruebas;
- `ca-meta-text.spec.ts`: 17;
- `ca-templates.spec.ts`: 16;
- `ca-wa-format.spec.ts` y `ca-wa-preview.spec.ts` (de 1d, terminadas).

**Arnés de capturas:** `capturas/arnes/capture-plantillas.mjs`. Está en estos documentos y no en el CRM, para no tocar el CRM fuera del módulo.

## 2. Decisiones de diseño (`frontend-design`)

**El sistema del Studio manda:**
- tokens `--us-*`, Lexend en los títulos e Inter en la interfaz;
- los kits `lab-*`, `ig-*` y `ca-*`;
- el cable de Integraciones (`ig-cable`) y los carriles `ig-lane` (violeta = Studio, cian = Meta).

Nada inventa un sistema paralelo.

**Plan de color, dentro del sistema:**
- noche del Studio (`#06060a` / `#10101a`);
- violeta `#8b5cf6` para lo que decide el Studio;
- cian `#22d3ee` para lo que dice Meta;
- iris (el degradado del Studio) solo para «Lista para campañas», el momento en que la plantilla entra en W9;
- la paleta de WhatsApp (`#efeae2`, `#d9fdd3`, `#0b141a`…) encerrada en el teléfono.

**La apuesta, en un solo sitio:** el **teléfono del socio**.
- Es la única superficie clara en un producto oscuro y usa la tipografía del sistema operativo, como el WhatsApp de verdad: se lee como «esto es el mundo del socio».
- Lo que se escribe se ve en vivo. La sección que se edita se marca en cian en el formulario y la misma parte se señala en la burbuja, de modo que ambos lados quedan conectados.
- La otra pieza con carácter es el **carril vertical**: encadena los dos colores según quién decidió cada paso y termina en el iris. Es una secuencia de verdad, por eso lleva marcas de paso.

**Contra el diseño «por defecto»:**
- La salida genérica habría sido una cuadrícula de tarjetas con píldoras y un modal de formulario.
- Se cambió por:
  - una tabla con dos carriles por fila;
  - un editor a ancho completo, porque la vista previa necesita sitio fijo y un modal de 720 px no lo tiene;
  - un carril que dice qué autoridad decidió cada paso, en lugar de un stepper genérico.
- No hay etiquetas en mayúsculas inventadas: las cabeceras de la tabla son las de `ca-tabla`, que ya existían.
- El movimiento se limita a un pulso suave en el paso en curso, y respeta `prefers-reduced-motion`.

**Textos:**
- En llano y desde quien usa la pantalla: «Enviar a Meta» → «Enviada a Meta».
- Los errores dicen qué pasó y qué hacer.
- Los vacíos invitan a actuar («Crear una versión»).
- Los de Meta, del plan §5.13.4 tal cual: «No sabemos si Meta la recibió…», «Meta tiene otro texto o botones…», «Meta la pausó por baja calidad…».

## 3. Qué hace cada estado

**Conexión con Meta** (de `GET templates/meta/status`):

| Estado | Cable | Qué dice |
|---|---|---|
| Servidor sin el módulo (404 sin código) | punteado | «Este servidor todavía no tiene las plantillas de Meta». No es un error. |
| Apagada en el servidor | punteado | Apagada en el servidor (se pueden escribir y aprobar igual) |
| Sin configurar | roto | El problema en llano (`missing_waba`, `missing_token`…) |
| Sin permiso de plantillas, o credencial rechazada | roto | El resultado de la sonda en llano |
| Modo de prueba (`driver: fake`) | discontinuo | Modo de prueba (sin red) |
| Conectada | continuo | Graph vX y la credencial (del CRM o del servidor). Ámbar si no hay sonda en 24 h |

Además:
- **Banderas:** «Enviar a Meta» y «Comprobación automática cada 5 min».
- **Recuentos** por estado en Meta.
- **«Comprobar permisos»:** solo con `can.probe`. Dice siempre que la sonda **no prueba crear plantillas**, porque `writeVerified` es siempre `false`.

**Lista:**
- **Columnas:** Clave · versión (el canal debajo), Finalidad · categoría, **Studio** (violeta), **Meta** (cian) y **Lista para campañas**, más Actualizada y «Ver».
- **Chip de Meta:** sin enviar · enviándose · envío fallido · sin respuesta · en revisión · en apelación · aprobada · rechazada · pausada · desactivada · ya no está · estado desconocido. Siempre lleva icono y texto.
- **«Lista para campañas»:** sale de `campaignReady` del servidor; el CRM no la calcula.
- **Filtro «En Meta»:** en el cliente, sobre la página leída. El servidor no tiene ese filtro (dtos §9) y la etiqueta lo dice.
- **Estados de la pantalla:** cargando, error con reintento, vacío con «Crear una versión», paginación.

**Editor (WhatsApp):**

1. **Identidad:**
   - clave, canal y finalidad (los tres bloqueados en una versión corregida);
   - nombre en Meta, que propone `<clave>_v<n>` (la versión siguiente entre las leídas), comprueba `[a-z0-9_]` en vivo y avisa de que no se reutiliza;
   - idioma;
   - categoría fijada por la finalidad.
2. **Encabezado:** ninguno o texto, con contador de 60.
3. **Cuerpo:**
   - contador de 1 024;
   - fichas «Insertar dato»: ponen `{{n}}` en el cursor, reutilizan el número si el dato ya está y traen el ejemplo del catálogo;
   - un selector por cada `{{n}}`;
   - «Renumerar» si hay huecos.
4. **Ejemplos:** uno por dato, con el aviso «datos inventados, nunca de un socio real».
5. **Pie:** con el atajo a la línea de baja.
6. **Botones (hasta 3):**
   - respuesta rápida, con la marca «es el botón de baja» y un texto de la lista cerrada;
   - URL y «Llamar» solo si el catálogo los admite (el número lo pone el servidor y se enseña);
   - subir, bajar y quitar.
7. **Casilla de la Ley 2300.**

- **El servidor decide.** El botón de crear exige solo lo mínimo (clave, nombre, cuerpo, datos asignados y la casilla). La vista previa avisa de lo que no casaría y la barra de abajo cuenta cuántas cosas hay por corregir.
- **Los 422** se pintan junto a su campo (`aria-invalid` y `aria-describedby`), sin perder lo escrito.
- **Con un servidor sin `templates.meta`:** no salen encabezado, pie, botones ni ejemplos, y se manda el contenido de siempre. Hay prueba de ello.
- **Asistido y voz:** conservan sus campos y **todos los selectores del E2E** (`data-ca-plantilla-{form,clave,canal,finalidad,medio,script,enviar}`, más `-abrir`, `-detalle`, `-aprobar` y `-aprobar-confirmar` en el cajón).

**Cajón de detalle:**

| Paso del carril | Hecho | En curso | Con aviso o fallo (y su frase) |
|---|---|---|---|
| Borrador | aprobada o más | espera a otra persona | — |
| Aprobada en el Studio | APPROVED | — | rechazada (corregir con versión nueva) · retirada |
| Enviada a Meta | SUBMITTED (cuándo y quién) · registrada a mano | sin enviar · enviándose | **FAILED** con el mensaje de Meta · **UNKNOWN**: «no la vuelvas a enviar» |
| Revisión de Meta | terminó | PENDING · IN_APPEAL | **REJECTED** con el motivo en llano y qué hacer |
| Aprobada por Meta | APPROVED con su calidad | — | pausada (3 h / 6 h / desactiva) · desactivada · ya no está · borrada · estado desconocido |
| Lista para campañas | `campaignReady` (iris) | — | aprobada pero **no casa**: los `campaignReasons` en llano |

Además del carril:
- **Avisos del estado:** sin respuesta, envío fallido (con código y mensaje), no casa, pausada, sin quien dé fe y recategorización anunciada.
- **«¿Lista para campañas?»:** con «Qué falta».
- **Acciones según `actions.*`:**
  - «Enviar a Meta» y «Comprobar ahora» **solo si el servidor lo permite Y la sesión trae la llave**;
  - si no, el candado con el motivo en llano;
  - no se repite «nunca se envió» junto a «Comprobar», porque ya lo dice el carril.
- **«Crear versión corregida»:** con `can.edit`.
- **El resto del cajón:** la vista previa compacta (de `waPreview`, con los avisos del servidor), «Lo que dice Meta», «En el Studio» (las claves en español) y el historial (violeta del Studio, cian de Meta).

**Diálogo «Enviar a Meta»:**
- enseña el teléfono y **lo que sale exactamente**: nombre, idioma, categoría, versión con su huella y quién la aprobó;
- dice que crea una plantilla **REAL** y que **no se puede deshacer desde el CRM**;
- pide marcar la casilla;
- **manda solo `{clientRequestId}`** y repite la misma clave tras un fallo sin respuesta clara, para no crear dos plantillas en Meta;
- un 409 o 422 se dice dentro del diálogo y el detalle se relee.

**Refresco:**
- cada 60 s mientras el envío está `SENDING` o `UNKNOWN`, o Meta está en `PENDING` o `IN_APPEAL`;
- en pausa con la pestaña oculta;
- **como mucho 30 min**, y entonces lo dice;
- relee sin pasar por «cargando», y si falla se queda lo que había.

## 4. Contrato que consume

| Ruta | Uso |
|---|---|
| `GET strategies/templates/meta/status` | La franja de conexión (también apagada) |
| `POST strategies/templates/meta/probe` | «Comprobar permisos» (cuerpo vacío) |
| `POST strategies/templates/{uuid}/meta/submit` | «Enviar a Meta» (`{clientRequestId}`) |
| `POST strategies/templates/{uuid}/meta/refresh` | «Comprobar ahora» (cuerpo vacío; `check` en la raíz) |
| `GET strategies/templates`, `GET strategies/templates/{uuid}`, `GET strategies/catalog` | Los bloques nuevos `meta`, `waPreview` y `templates.meta` |

**Qué lee el parser y con qué rigor:**
- **Estricto** con lo que decide algo: `state` en su dominio cerrado, `campaignReady`, `actions`, los topes del catálogo.
- **Tolerante** con lo informativo:
  - una entrada del libro ilegible se descarta;
  - una calidad desconocida se enseña como «sin datos»;
  - `counts: []` de PHP se lee como `{}`;
  - un `metaStatus` desconocido se enseña como «estado de Meta desconocido (X)».
- **Un servidor anterior** (sin `meta`, `waPreview` ni `templates.meta`) sigue leyéndose.
- **El CRM nunca manda la plantilla a Meta:** el servidor la reconstruye desde la versión aprobada.

## 5. Ficheros tocados fuera del módulo (imprescindibles)

| Fichero | Cambio | Por qué |
|---|---|---|
| `src/app/models/permissions.enum.ts` | +6: `ULTRON_META_TEMPLATES_SUBMIT` | El catálogo regenerado ya la trae; sin la entrada en el enum falla `permission-contract.spec` («ningún permiso del backend queda sin representar») |
| `src/app/services/access-control.service.ts` | +2: la llave entre las excluidas del Administrador | El Administrador del CRM es «todo el enum menos las excluidas». Sin esto, la plantilla del rol le concedería la llave y el backend no (`CrmPermission.php:336`; plan §5.10: solo Super Admin) |
| `src/app/models/permission-contract.spec.ts` | +3 −2 | La lista explícita de llaves `ultron.*` (19 → 20) y la aserción de que el Administrador **no** la tiene |

## 6. Verificación

### 6.1 Specs y compilación

| Comando | Resultado |
|---|---|
| `ng test --include='src/app/modules/commercial-automation/**/*.spec.ts' --include=…/permission-contract.spec.ts --include=…/access-control.trainer.spec.ts` | **20 ficheros, 578 pruebas en verde** |
| Las nuevas, por separado | `ca-meta-api` 15 · `ca-meta-text` 17 · `ca-templates` 16 · `ca-wa-format` y `ca-wa-preview` en verde (al llegar, `ca-wa-preview` tenía 2 rojos, ambos del spec y no del componente: §6.4) |
| `npx tsc -p tsconfig.app.json --noEmit` y `-p tsconfig.spec.json` | limpios |
| `npx ng build --configuration=production` | exit 0, 38 avisos (NG8107 y presupuestos de otros módulos, los mismos de la tarea 1); **0 en ficheros tocados**. Chunk perezoso `commercial-automation-page` **405,32 → 529,88 kB en crudo (+124,6) / 88,41 → 115,45 kB transferidos (+27,0)**; inicial 831,22 → 832,22 kB (+1,0, la entrada del enum) |

**Qué cubren:**
- **Las rutas:** URL, método y cuerpo EXACTO de cada una.
- **La lectura:** los JSON reales del documento y un servidor anterior.
- **La sesión:** la misma clave tras un corte y otra tras una respuesta definitiva; `FAILED` es un aviso, no un error HTTP.
- **La pantalla:**
  - la lista, los filtros, la sonda y los estados de conexión;
  - el editor (insertar, ejemplos, vista previa, la sección señalada, el **contenido exacto** que sale, el 422 junto al campo, asistido con los selectores del E2E, servidor sin `templates.meta`, renumerar);
  - el cajón (carril, «Enviar a Meta» con confirmación, sin llave, `FAILED`, lista para campañas y «Comprobar ahora»);
  - el refresco con temporizadores falsos (pestaña oculta, se detiene al aprobarse, tope de 30 min);
  - la versión corregida;
  - PM-5 y el texto de irreversibilidad.

### 6.2 Capturas (`capturas/`, 20 PNG)

**Cómo se hicieron:**
- con `capturas/arnes/capture-plantillas.mjs`: el build de producción servido en local, la sesión en `sessionStorage` (Super Admin con la llave) y **toda la API interceptada**, con los preflight `OPTIONS` contestados con CORS;
- respuestas con las formas reales de los DTO, datos inventados.

**Resultado:**
- **0 errores de página.**
- **Sin desplazamiento horizontal en las 20.**
- Peticiones sin contestar: solo las del shell (`stream-ticket`, `notifications/stream` y `unread-count`), que no son de esta pestaña.
- **Única salida a la red:** `fonts.gstatic.com` (Inter, Lexend y los iconos vienen de Google Fonts; sin ellos los iconos salen como texto). Todo lo demás se aborta.

| 1 440 px | 390 px |
|---|---|
| `plantillas-lista` | `plantillas-lista`, `plantillas-lista-tabla` |
| `plantillas-detalle-en-revision`, `-lista`, `-lista-final` (Meta, Studio, historial), `-rechazada` | las mismas |
| `plantillas-enviar-a-meta` | `plantillas-enviar-a-meta` |
| `plantillas-editor-arriba`, `plantillas-editor-pie-claro`, `plantillas-editor-pie-oscuro` (WhatsApp en claro y en oscuro) | `plantillas-editor-arriba`, `plantillas-editor-pie`, `plantillas-vista-claro` y `plantillas-vista-oscuro` (la hoja «Ver cómo llega») |

«Claro y oscuro» es el tema del WhatsApp de la vista previa: el Studio solo tiene tema oscuro. El CRM desplaza un contenedor interno, así que las tomas «completas» son de la vista; las de más abajo están desplazadas con `scrollIntoView`.

### 6.3 Un rojo que ya existía

`src/app/services/access-control.moderation.spec.ts` da **6 rojos de 14**:
- con mis cambios;
- **y también en una copia limpia de HEAD `f5cd0d2`** (`git archive` en el scratchpad, sin tocar el árbol).

Son de la matriz de moderación y no tienen relación con la llave nueva. No los toco.

### 6.4 Arreglos de causa raíz que salieron por el camino

- **El spec de la vista previa, al retomar 1d:**
  - Comparaba el texto de los botones con el icono pegado.
  - Buscaba un `<b>` en todo el componente, cuando el único `<b>` es el nombre del chat de la cabecera.
  - Se acotaron las comprobaciones. El componente no cambió.
- **`<select [value]>` con las opciones generadas por `@for`:**
  - Angular fija el valor antes de que existan las opciones, y el select se quedaba en la primera.
  - En la pestaña anterior, por ejemplo, el canal por defecto (WhatsApp) se veía como «Asistido».
  - Ahora cada opción lleva `[selected]`.
- **El editor programaba un `afterNextRender` tras un `await`** aunque ya estuviera destruido (al salir con la creación en vuelo): `NG0205`. Ahora tiene una guarda.
- **En la tabla, `ca-tabla` partía los chips y el botón «Ver» letra a letra:** ahora sin corte. El canal pasa bajo la clave, porque el plan no pide esa columna.

## 7. Accesibilidad y responsive

**Accesibilidad:**
- **Foco:** visible en todo (`.ig-root :focus-visible`, cian). Al abrir el editor va a la clave, o al título si es una versión corregida; al cerrar vuelve a «Nueva versión»; tras un 422, al primer campo con error.
- **Formulario:** cada sección es un `fieldset` con su `legend`. Los radios son nativos. Hay contadores visibles.
- **Botones de icono:** subir, bajar y quitar llevan `aria-label`. «Mostrar» y «Ocultar» la vista llevan `aria-expanded` y `aria-controls`.
- **Carril:** es un `ol`, con `aria-current="step"` y el estado de cada paso dicho para lectores de pantalla.
- **Avisos:** con `role="alert"` o `role="status"`.
- **Vista previa:** `role="figure"` con etiqueta; los botones dibujados no son controles y llevan una lista equivalente.
- **Color:** el rojo y el ámbar nunca van solos (icono y texto). La paleta de WhatsApp pasa AA y el spec lo comprueba.
- **Contenido del usuario:** se pinta por interpolación: **ni `innerHTML` ni `bypassSecurityTrust*`**. El spec prueba un `<img onerror>` en un ejemplo.

**Responsive:**
- **≥ 1 100 px:** formulario y teléfono fijo.
- **700–1 099 px:** la vista previa arriba y plegable.
- **< 700 px:** barra fija «Ver cómo llega» con la hoja inferior (`ib-dialog`).
- **Tabla:** se apila en móvil con su etiqueta por dato.
- **Cajón:** a 390 px ocupa la pantalla.

## 8. Qué NO verifiqué, riesgos y pendientes

- **El E2E con navegador real contra el backend** (`recorridos-h9.cjs`, paso `plantillas`): sus selectores siguen (lo prueba el spec de asistido), pero no lo corrí. Tampoco hay todavía un paso E2E para Meta.
- **El envío real a Meta y lo que Meta devuelve** (V-1 del plan). Solo el doble, vía los DTO.
- **Lectores de pantalla reales** (VoiceOver o NVDA): solo la estructura, por spec.
- **La batería completa del CRM:** la corre el lead.
- **Tamaño:** el chunk perezoso crece 124,6 kB en crudo (27 kB transferidos); lo baja solo quien abre Campañas automáticas. Si molesta, se puede pasar el editor y el cajón a `@defer` (no lo hice: cambia el arranque de los specs y de los selectores del E2E).
- **La calidad no sale en la lista:** el resumen de la lista no la trae; sale en el detalle. Si se quiere en la lista, hay que pedirlo a backend.
- **El filtro «En Meta» es de la página.** Uno de servidor hay que pedirlo (dtos §9).
- **Para `security`:**
  - la exclusión de la llave del Administrador en `access-control.service.ts`;
  - PM-5;
  - el diálogo de envío;
  - que el CRM solo manda `{clientRequestId}`.
- **Para `tester`, flujos críticos:**
  - crear WhatsApp ampliado → aprobar con otra persona → «Enviar a Meta» (confirmar) → refresco → aprobada o lista;
  - envío `FAILED` y reenvío;
  - `UNKNOWN` («no la vuelvas a enviar»);
  - rechazada → «Crear versión corregida» (nombre `_v<n+1>`);
  - sin la llave (sin botón y con motivo);
  - servidor con el módulo apagado.

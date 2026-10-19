# ULTRON STUDIO: informe visual (Hito C)

**Fecha:** 17-10-2026. **Qué es:** el tablero visual de las diez capas de ULTRON, con datos de demostración, sin conexión al motor. Vive en el CRM en **Mercadeo › ULTRON Studio** (`/marketing/ultron-studio`).

## Qué se construyó

| Pieza | Fichero | Qué hace |
|---|---|---|
| Página | `src/app/modules/ultron-studio/ultron-studio-page.ts` | Barra superior, biblioteca de capas, lienzo Foblex, controles flotantes, panel de validación, aviso de vista previa |
| Inspector | `studio-inspector.ts` | Resumen (qué implementa la capa hoy, qué es configurable, qué está fijo), Configuración (parámetros de borrador, con confirmación en los importantes), Reglas, Dependencias |
| Almacén | `studio-store.ts` | Borrador en el navegador por persona: posiciones, aristas y parámetros; deshacer/rehacer (100 pasos); guardar; restaurar disposición; saneado de lo guardado |
| Reglas del grafo | `studio-graph.ts` | Funciones puras: conexiones permitidas (tipadas y del motor real), ciclos dentro de un turno, caminos obligatorios, validación con niveles ok/aviso/bloqueo |
| Modelo | `studio-model.ts` | `GraphLayout`, `GraphDefinition`, `LayerConfiguration`, `PublishedAgentVersion`, reglas con estructura verificable, puertos tipados |
| Catálogo | `studio-catalog.ts` | Las diez capas con sus componentes reales (nombres de clases, sin rutas), 30 puertos, 19 aristas, 3 caminos obligatorios, 10 reglas de configuración (datos, no código), parámetros y reglas comerciales |
| Estilos | `ultron-studio.scss` | Identidad propia acotada a `.us-root`; del tema de Foblex solo lo esencial |
| Menú | `app.html`, `app.css` | Submenú bajo Mercadeo con orbe iridiscente (gira una vez al pasar el ratón; nada permanente) |

**Separación que pedía el encargo (§01):** mover un nodo solo cambia `positions`; conectar o quitar pasa por las reglas del grafo y solo toca `edgeIds` del borrador; editar un parámetro solo toca `configs`; **nada escribe en el motor ni en el backend**, y «Publicar» está deshabilitado con su explicación.

## Las conexiones representan el motor real

- **Solo existen los caminos que el motor tiene.** Una salida solo se puede soltar en las entradas que el catálogo conecta (`fCanBeConnectedTo`); si se intenta otra cosa, el lienzo no la crea y el almacén explica por qué («el motor real no conecta X con Y», «tipos incompatibles»).
- **Los controles son obligatorios:** las 17 aristas que son controles del motor no se pueden quitar (Supr sobre ellas avisa). Solo son opcionales las dos que hoy están apagadas por bandera (enlace de pago y resultado de Wompi), y aparecen como aviso en la validación.
- **El commit es un segmento sellado:** no hay ninguna arista de «Construcción de respuesta» a «Integraciones»; todo pasa por «Reglas del negocio», que además recibe directamente de «Memoria comercial» (pagos, membresía, perfil) y de «Conocimiento» (planes, precio, horario, `brand_copy`), como hace el commit real.
- **Sin ciclos dentro de un turno:** el mensaje entrante (abre el turno) y lo que ocurre tras el turno (memoria, vigía) se dibujan distinto y no cuentan como ciclo.
- **La validación también mira la configuración:** diez reglas del catálogo (datos, no código) bloquean lo que el motor no admite (hechos sin verificar, descuentos, sin Critic, derivación sin corroborar, Hermes, tráfico general) y exigen coherencia entre la bandera de cobro y las conexiones de pago. Todo parámetro cuya consecuencia promete validación la tiene, y una prueba lo vigila.
- **Versión publicada de demostración:** el selector de versión enseña `LEGACY_BASELINE_V1` como línea base de solo lectura (todo gesto se rechaza con aviso y el nodo vuelve a su sitio); el borrador local se conserva al volver.

## Evaluación visual (capturas reales de Chrome)

Capturas tomadas por el E2E (Chrome 155, API simulada, datos de demostración) en [`capturas/`](capturas/): [`01-studio-escritorio.png`](capturas/01-studio-escritorio.png) (1440×900, tablero completo con el inspector plegado), [`02-studio-inspector.png`](capturas/02-studio-inspector.png) (una capa seleccionada: conexiones iluminadas e inspector abierto), [`02b-studio-detalle-100.png`](capturas/02b-studio-detalle-100.png) (detalle de una capa al 100 %), [`03-studio-validacion.png`](capturas/03-studio-validacion.png) (panel de validación) y [`04-studio-movil.png`](capturas/04-studio-movil.png) (390×844, inspector como hoja).

| Criterio | Resultado |
|---|---|
| **Jerarquía** | Barra superior (marca, versión, estado, acciones) → aviso de vista previa → tres columnas: biblioteca, lienzo, inspector. El inspector se pliega cuando no hay selección (el lienzo gana 368 px) y se abre al seleccionar |
| **Identidad** | Negro profundo con dos halos (violeta y cian), orbe iridiscente, texto de marca con degradado, acentos violeta, azul, cian y magenta. Distinta del dorado del CRM sin romper la coherencia (misma tipografía Lexend e Inter, mismos iconos) |
| **Legibilidad** | Al 100 %: título de capa 13,5 px en Lexend 700, responsabilidad 11,5 px, chips de 10,5 px. Texto principal `#ecebff` sobre `#10101a` (contraste > 15:1); secundario `#b9b7d6` (> 9:1); atenuado `#85839f` (≈ 5:1) |
| **Nodos** | Icono con acento de la capa, número, nombre, responsabilidad (2 líneas), chips (crítica, n reglas, borrador), puertos de entrada a la izquierda y de salida a la derecha, con color por tipo de dato |
| **Conexiones** | Bézier; obligatorias continuas, opcionales discontinuas, «abre el turno» en celeste, «tras el turno» punteadas y atenuadas. Al seleccionar una capa, sus conexiones se iluminan y las capas no relacionadas se atenúan |
| **Movimiento** | Orbe y marca con una animación de entrada única; las conexiones activas se animan solo con una capa seleccionada; todo se apaga con `prefers-reduced-motion` (verificado) |
| **Estados** | Cargando (orbe), vacío del inspector, sin guardar / guardado hace N min, bloqueado/aviso/ok en la validación, toasts con motivo |
| **Densidad** | Sin tarjetas enormes ni KPI decorativos; la información detallada vive en el inspector, no en el lienzo |
| **Móvil (390 px)** | Cabecera compacta en dos filas, biblioteca como tira horizontal, inspector como hoja inferior opaca, sin desplazamiento horizontal (0 px) |

**Lo que se corrigió a partir de las capturas:** el inspector vacío ocupaba 368 px y dejaba el grafo pequeño; el minimapa quedaba bajo el asistente IRON del CRM (ahora a 112 px del borde); el aviso emergente pisaba el minimapa (ahora arriba a la izquierda); en móvil la cabecera era demasiado alta y el inspector dejaba ver el texto de debajo; el contenido del inspector queda tapado en su última línea por el asistente IRON (margen inferior de 130 px).

**Limitaciones conocidas:**
- A 1440 px el ajuste inicial deja el grafo a ~60 % (diez capas en seis columnas). El detalle se lee al acercar o al centrar una capa desde la biblioteca.
- El asistente IRON del CRM flota en la esquina inferior derecha en todas las pantallas, también aquí.
- Probado en Chrome 155 (escritorio y emulación móvil, incluidos gestos de ratón reales). No se probó con gestos táctiles reales ni en Safari o Firefox.

## Rendimiento

| Medida | Valor |
|---|---|
| Chunk del Studio (diferido, solo quien abre la ruta) | 480,5 kB en bruto, **102 kB transferidos** (incluye Foblex Flow) |
| Bundle inicial | 816,76 kB (antes 813,47): +3,3 kB por la ruta y el submenú |
| Estilos del componente | 27,3 kB (presupuesto de aviso 8 kB, de error 32 kB). Del tema de Foblex solo se incluyen lienzo, conexiones, marcadores, fondo y minimapa |
| Render | `fFullRendered` llega inmediatamente tras cargar la ruta en el E2E; sin diagnósticos `FF1xxx` de Foblex |
| Arrastre | Posiciones en el modelo solo al soltar (`fMoveNodes`), ajuste a rejilla de 16 px; sin recálculo del grafo durante el gesto |

## Pruebas

| Suite | Resultado |
|---|---|
| `studio-graph.spec.ts` | 12: conexiones permitidas y rechazadas con motivo exacto («no conecta» y «tipos incompatibles» por separado), ciclos, realimentación, obligatorias, validación del grafo y de la configuración (valores prohibidos, coherencia valor ↔ aristas) |
| `studio-store.spec.ts` | 13: mover con rejilla, deshacer/rehacer, conectar/quitar, configuración, guardar por persona, saneado, restaurar (y restaurar sin cambios no apunta nada), tope del historial, validación con configuración, Supr múltiple en un paso, «sin guardar» sin depender del orden, versión publicada de solo lectura |
| `studio-catalog.spec.ts` | 11: diez capas en orden, puertos y aristas coherentes, commit sellado, línea base publicable, posiciones en rejilla, parámetros, reglas, **sin rutas ni textos operativos**, saneado con el catálogo real, las diez reglas de configuración contra el catálogo real, dependencias del commit con Memoria y Conocimiento |
| `ultron-studio-page.spec.ts` | 10 (jsdom): carga con diez capas y conexiones (inspector `inert` plegado), inspector por biblioteca (patrón tablist, dependencias E18/E19), mover y deshacer con teclado, conectar/quitar (motivo al soltar sobre un puerto no permitido, Supr múltiple), validación con configuración (insignia y panel), versión publicada de solo lectura, validar, guardar por persona, conexión en el inspector, limpieza del shell |
| **Suite completa del CRM** | **2064 pasan; fallan los mismos 16 de la línea base, ninguno nuevo** |
| `test:agenda-tz` / `check:literals` | 394 de 394 / correcto |
| Build de producción | Correcto |

**E2E en Chrome real (44 de 44):** carga; submenú; diez capas; 17 conexiones con trazo SVG real; el indicador de zoom refleja el ajuste inicial; zoom con rueda y botones; minimapa; pan; arrastre de una capa (posición en el modelo y conexiones que la siguen); deshacer/rehacer **con la conexión medida a menos de 10 px del puerto** (geometría SVG en pantalla, no solo el `transform`); clic en el vacío y en una capa; atenuado; inspector plegable; conectar arrastrando (E14) con su aviso; conexión imposible no creada **y explicada** al soltar sobre el puerto; Supr sobre obligatoria; validación; **un parámetro prohibido («descuentos») bloquea y Ctrl+Z lo deshace**; guardar; recarga con restauración; restaurar disposición (conexiones pegadas a los puertos); **versión publicada: PUBLISHED, línea base, arrastrar no mueve y lo explica, volver al borrador lo conserva**; **la cabecera no desborda a 1100 ni 1280 px**; teclado (flechas, Espacio, anuncios para lector de pantalla); sin permiso no hay submenú ni ruta; reduced-motion; móvil sin desplazamiento horizontal e inspector como hoja; sin errores de JavaScript.

## Hito F: el Studio contra la API (persistencia)

Capturas en [`capturas/`](capturas/): [`f1-studio-api-escritorio.png`](capturas/f1-studio-api-escritorio.png) (carga desde la API: «Mi borrador», chip «Motor: no lee versiones», botones Versiones/Reglas/Auditoría, «Sin guardar en el servidor»), [`f2-studio-conflicto.png`](capturas/f2-studio-conflicto.png) (409 de otra pestaña: diálogo recargar/sobrescribir), [`f3-studio-version-detalle.png`](capturas/f3-studio-version-detalle.png) (versión recién creada), [`f4-studio-version-aprobada.png`](capturas/f4-studio-version-aprobada.png) (tubería de estados con lo alcanzable resaltado, avisos reconocidos, aprobada por otra persona, «Publicar» bloqueado con el motivo del servidor), [`f5-studio-auditoria.png`](capturas/f5-studio-auditoria.png), [`f6-studio-api-movil.png`](capturas/f6-studio-api-movil.png).

**Qué cambia respecto a la vista previa:** ya no hay datos de demostración ni `localStorage` (salvo la importación única del borrador de la vista previa, que se ofrece y se borra); el catálogo, el borrador, las versiones, las reglas y la auditoría vienen de `/admin/ultron-studio/*`; la validación del servidor (al guardar) se enseña junto a la del cliente y manda si discrepan; Guardar (Ctrl+S) envía `baseRevision` y un 409 abre el diálogo de conflicto; «Crear versión» reutiliza el mismo `clientRequestId` al reintentar; sin `ultron.edit_draft` el lienzo es de solo lectura; «Publicar» sigue deshabilitado con el mensaje del servidor; los diálogos (`ib-dialog`, `ib-reason-dialog`) van fuera de la cabecera y la página sube de capa con `:has(ib-dialog)` para que el menú del CRM no los tape. Cabecera: tres filas a 1440 px (marca y versión; paneles y estado de guardado; acciones). Hoja de estilos de la página: 31,04 de 32 kB (a 1 kB del error de presupuesto: la siguiente fase debe mover parte a los paneles).

**E2E en Chrome real (32 de 32, `scratchpad/e2e-studio/studio-f.cjs`, con el mock de la API que valida con el validador de referencia):** carga desde la API (status, catálogo, borrador, reglas y versiones); diez capas y 17 conexiones del servidor; chip del motor; Publicar deshabilitado con motivo; mover deja «sin guardar»; Guardar → `PUT /draft` 200 y revisión 1; posición guardada en el servidor; 409 de otra pestaña abre el conflicto y «sobrescribir» reintenta con la revisión nueva; la validación del servidor bloquea «descuentos»; no se crea una versión con bloqueos; `POST /versions` 201 con las 12 reglas copiadas; el panel enseña la versión; enviar → REVIEW_REQUIRED; la propia no se puede aprobar; otra persona aprueba solo tras reconocer los dos avisos (confirmar deshabilitado hasta marcarlos) → APPROVED; Publicar sigue bloqueado para el Super Admin; reglas del código de solo lectura y S-001 nace en borrador; auditoría con los eventos; línea base de solo lectura; volver al borrador; sin `ultron.view` ni submenú ni página; con solo `ultron.view` el lienzo es de solo lectura; importación única del borrador local (se guarda y se borra la clave); móvil sin desplazamiento horizontal; sin errores de JavaScript (los 4xx esperados no cuentan).

## Hito G: el inspector funcional (CRM)

**Estado:** implementado en la rama `feat/ultron-studio-g` del CRM, **no desplegado**. Commits sobre `020db86` (= producción):
- `ddb0958`: capa de datos y núcleo TS;
- `b0d9635`: fixtures oficiales exportados del backend. Se volvieron a exportar desde `5074f82`, sin diferencias;
- `688da4a`: la integración, con 40 ficheros y +5436/−255 líneas;
- `7d3ad2a`: el aviso B-1, en 6 ficheros.

Todo queda dentro de `src/app/modules/ultron-studio/`: ningún fichero fuera del módulo. **`ultron-studio.scss` no gana ni un byte:** sigue en 32 718 bytes, el mismo fichero que en F. Sin dependencias nuevas.

**Qué cambia para quien lo usa:**
- cada parámetro del inspector enseña, debajo del control del borrador, lo que dice el motor y quién lo cambia de verdad;
- la página lee el motor al cargar, sin bloquearse;
- las reglas del Studio se escriben con condiciones («Si… entonces…») y avisan de las contradicciones antes de guardar;
- el historial se lee en frases, y se pueden comparar versiones.

### Componentes nuevos

Cada componente es standalone, `OnPush` y tiene su propia hoja y su spec.

| Pieza | Fichero | Qué hace |
|---|---|---|
| Fila de parámetro | `studio-param-row.ts` (`us-param-row`) | Debajo del control del borrador de siempre, la franja «Motor (solo lectura)»: lo que dice el motor («Motor: …», «Fijo en código: …», «Vive en n8n» o «Sin equivalente en el motor»), la insignia de origen, el efecto, «Lo cambia de verdad», las reglas que lo citan con su estado, «≠ motor», «Usar el valor del motor» y «Volver a la línea base». Solo propone: aplicar es cosa del inspector |
| Editor de condiciones | `studio-conditions-editor.ts` (`us-conditions-editor`) | «Si…» (de 0 a 3 filas) y «Entonces…» (de 1 a 3). El parámetro va agrupado por capa, y los operadores y el valor dependen del tipo. Ofrece las conexiones del catálogo (las obligatorias, marcadas) y, solo en «entonces», frases. Construye únicamente las claves de cada combinación y pinta cada mensaje del 422 junto a su fila y su campo |
| Historial | `studio-history-list.ts` (`us-history-list`) | Pide el historial de 100 en 100 (el borrador por su uuid, una regla o una versión; la línea base dice que no tiene) o pinta los eventos que recibe. Filtro por capa con la nota «entre los N cambios más recientes del borrador», «Ver cambios más antiguos», «(recortado por la auditoría)» y la insignia «Disposición» en lo que no cambia nada |
| Comparación | `studio-compare-view.ts` (`us-compare-view`) | Pide `GET compare` y pinta los dos lados y las diferencias, agrupadas en Configuración, Conexiones, Reglas y Avisos. Si no hay ninguna: «Sin diferencias de comportamiento.» |
| Núcleo de reglas | `studio-rules-coherence.ts` (puro) | La misma semántica que `StudioRuleCoherence` (canonicalizar, analizar, contradicciones y evaluación), más `ruleOutcome` (cumple, no cumple, no aplica o no comprobable) y `ruleRelations` (qué exige o prohíbe cada arista y qué usa cada parámetro; su forma la fijó el CRM, el contrato no la daba) |
| Textos | `studio-engine-text.ts`, `studio-conditions-text.ts` y `studio-diff-text.ts` (puros) | Las lecturas del motor en llano, las condiciones en frase y las líneas del historial y de la comparación. Lo que el catálogo no conoce sale en crudo |
| Modelo del inspector | `studio-inspector-model.ts` (puro) | Qué reglas tocan una capa, la prevención de cambios (`newProblems`) y el aviso del lienzo (`ruleChangeNotice`) |

**Modificados:**
- `studio-inspector.ts`: las cinco pestañas. Ahora inyecta `StudioSession`;
- `studio-rules-panel.ts`;
- `studio-versions-panel.ts`: «Comparar con…»;
- `studio-audit-panel.ts`: `us-history-list` en lugar del `<pre>`, y el filtro «Mi borrador»;
- `ultron-studio-page.ts`: solo TypeScript y plantilla;
- la capa de datos: `studio-session.ts`, `studio-parse.ts`, `studio-api.types.ts`, `studio-api.service.ts` y `studio-model.ts`.

### El inspector de cinco pestañas

Resumen · Configuración · Reglas · Dependencias · Historial, con el patrón de tablist de F (flechas, Inicio y Fin). Para que quepan las cinco en 368 px, la barra de pestañas baja a 11 px, solo en el inspector.

1. **Resumen.** Lo de F, más dos bloques:
   - «Motor ahora», con las lecturas de la capa:
     - gobierno: interruptor, pausa, freno, canario y el estado efectivo («Atiende: no», «Atiende: solo la conversación canaria», «Atiende: todo el tráfico» o «Atiende: no se sabe (falta alguna lectura)»);
     - conocimiento, planes, driver, y la bandera y el canario del cobro;
     - estados «sin leer», «leyendo», fallo con «Reintentar», y «Nada de esta capa se lee en vivo: está fijo en código o en n8n.»;
   - «Conflictos y avisos de la capa».
2. **Configuración.** El aviso fijo «Borrador: el motor no lo lee.» y una `us-param-row` por parámetro. «≠ motor» y «Usar el valor del motor» solo aparecen si se cumplen las cuatro condiciones:
   - el parámetro es comparable;
   - el motor tiene valor;
   - los valores difieren;
   - el borrador no dice «Según el entorno».
3. **Reglas.** Las que tocan la capa, por `layers` o por los objetivos de sus condiciones. De cada una:
   - la frase;
   - «La aplica el motor» o «El motor no la aplica»;
   - la verificación;
   - su estado con los valores abiertos, y qué valor falla;
   - los `issues` del análisis.
4. **Dependencias.** Cada conexión, con su estado y quién la exige o la prohíbe. Por parámetro, los controles, las reglas y las conexiones que activa.
5. **Historial.** El de la capa en el borrador (filtrado entre los 100 cambios más recientes) o el de la versión abierta. La línea base no tiene.

**Prevención.**
- Al proponer un valor (también con «Usar el valor del motor» y «Volver a la línea base»), el inspector calcula los hallazgos de F y los de reglas con el valor nuevo.
- Pide confirmación solo si aparece un bloqueo o un `regla-*` **nuevo**. Cancelar devuelve el control al valor del borrador.
- En el lienzo, conectar o quitar una arista opcional avisa si cambia el cumplimiento de alguna regla.
- El inspector de arista enseña «Exigida por» y «Prohibida por», con lo que pesa ahora.

### Reglas con condiciones, historial y comparación

- **Panel de reglas:**
  - el editor de condiciones y las menciones a la base de conocimiento (las 14 categorías y sus claves);
  - análisis en vivo y contradicciones calculadas antes de enviar. **Avisan, pero no bloquean el envío:** manda el 409 o el 422 del servidor. Lo que no tiene forma (filas a medias, valores fuera de rango, capas que faltan) sí se corrige antes, porque daría un 422 seguro;
  - el 409 `rule_contradiction` lista los conflictos con «Ver S-NNN»: aparca el formulario con lo escrito, resalta la regla y ofrece «Volver a la regla». En Chrome real no llevaba el foco ni la vista a la regla (H-1 del E2E): el diálogo, al cerrarse, devolvía el foco a «Nueva regla» después del desplazamiento. **Corregido en `38b238b`:** el desplazamiento y el foco se encolan detrás del foco que devuelve el diálogo (prueba con 51 reglas y el `ib-dialog` real; E2E 126/126);
  - el 422 de un control trae dos mensajes en la misma clave (el de la restricción y el de su R-xx), y se pintan los dos (`StudioApiError.fieldMessages`);
  - «Historial» en cada regla.
- **Panel de versiones:** «Comparar con…» la línea base, «Mi borrador» u otra versión. Siempre de lo anterior a lo posterior: línea base → versión, versión → borrador y versión más antigua → más reciente.
- **Panel de auditoría:** una lista legible en lugar del `<pre>`, y el filtro «Mi borrador», que va por el uuid; sin fila lo dice y no pide nada. El filtro genérico pasa a llamarse «Borradores».
- **Página:**
  - `session.init(userId, {readEngine: true})` arranca la lectura del motor sin bloquear la carga. Si falla, el Studio sigue y el inspector dice «No se pudo leer el motor» con «Reintentar»;
  - la cabecera gana un botón para releer el motor;
  - la validación y el inspector usan la lista combinada (hallazgos de F y de reglas), y «el servidor manda si discrepan» se calcula sobre ella.

### Planos con color fijo y aviso de datos personales

- **Planos.** Cada plano de A1 tiene su color en todo el Studio: borrador violeta, motor cian, regla magenta y disposición gris punteado. La lectura del motor va **debajo** del control, en una franja con filo cian: lo editable y lo real nunca comparten caja.
- **Aviso B-1** (`7d3ad2a`): «No escribas datos personales (nombres, teléfonos, correos): las reglas y su historial no se pueden borrar.»
  - Siempre visible, y enlazado al campo con `aria-describedby`.
  - En el formulario de reglas, va bajo la descripción y bajo las menciones; en el editor, en cada fila con texto libre (frases y etiquetas).
  - No toca el contrato, el fixture ni el backend.

### Atributos `data-*` para el E2E

- **Fila de parámetro:** `[data-param="<clave>"]`, `[data-param-real]` (`ok|pending|error|unavailable|none`), `[data-param-origen]`, `[data-param-usar-motor]`, `[data-param-difiere]`, `[data-param-restablecer]`, `[data-param-propuesta]` y `[data-param-regla]`.
- **Lecturas del motor:** `[data-lecturas-motor]` con `[data-lecturas-estado]` (`idle|loading|ready|error`), filas `[data-lectura="<id>"]` y `[data-lecturas-reintentar]`. En la cabecera, `[data-lecturas-boton]`.
- **Editor de condiciones:** `[data-regla-condiciones]`, `[data-condicion-when="i"]`, `[data-condicion-then="i"]`, `[data-condicion-add]`, `[data-condicion-error="then.0"]`, `[data-analisis]`, `[data-contradiccion]`, `[data-ver-regla="S-001"]` y `[data-regla-aparcada]`.
- **Historial y comparación:** `[data-historial]` (`draft|rule|version|baseline|eventos`), `[data-historial-item]`, `[data-comparar]`, `[data-comparacion]` y `[data-comparacion-grupo]`.
- **Inspector:** `[data-conflictos-capa]`, `[data-dependencia="<arista>"]` con `[data-dependencia-estado="presente|ausente"]` y `[data-razon]` con `data-aplica`.
- **Prevención:** `[data-prevencion-hallazgo]` y `[data-confirmar-cambio]`.
- **Aviso B-1:** `[data-aviso-datos-personales]`, cuyo `textContent` es el texto exacto.

### Decisiones fuera del contrato

1. **Relecturas.** Las reglas se releen igual que en F: no tras `rule_limit_reached` ni tras `rule_conflict`.
2. **«Vive en n8n» frente a «Fijo en código».** Para un `fixed` sin valor, se decide por si `changeVia` nombra n8n. Hoy acierta en los tres casos (`knowledge.sources`, `composer.critic` y `composer.retry`). Si un texto cambia, el peor caso es decir «Fijo en código» de algo que vive en n8n; el efecto, que va debajo, lo aclara.
3. **El inspector inyecta `StudioSession`** y relee el historial cada vez que se abre su pestaña.
4. **`readCompare` admite revisiones `null`.** Es solo defensa: el servidor manda enteros.
5. **El 413 `payload_too_large` de la Enmienda 2 no está en `StudioErrorCode`:** el CRM enseña el mensaje genérico. Ningún cuerpo que genere el CRM llega a 64 KB (el mayor medido mide 38 052 bytes). Se añadirá cuando se toque `studio-api.types.ts`; el lead lo aceptó así.

### Pruebas y medidas

| Comprobación | Resultado |
|---|---|
| Specs del módulo (`ng test --include='src/app/modules/ultron-studio/*.spec.ts'`) | **22 ficheros, 489 pruebas en verde.** En F eran 216; con la capa de datos, 340 (124 de ellas de paridad); 487 en `688da4a` y 2 más con B-1 |
| `src/app/models/*.spec.ts` | 22, en verde |
| Paridad de coherencia | 124/124 contra `coherence-cases.json` |
| Suite completa (`ng test --watch=false`) | **2522 en verde; 16 fallos, los de la línea base:** `access-control.moderation` ×6, `auth.moderation-menu` ×7, `auth.guard.moderation` ×1, `financial-realtime.integration` ×1 y `receivables-pagination` ×1. Ninguno es nuevo ni del Studio. No se reprodujeron en un HEAD limpio: coinciden en número y ficheros con la línea base |
| `tsc` de la aplicación y de los specs | Limpio |
| `check:literals` | 292 ficheros, correcto |
| Build de producción | Exit 0 y 0 errores; 29 avisos de presupuesto, los mismos 29 que dejó F. Ninguna hoja nueva ni modificada está entre ellos |
| Chunk del Studio | 716,51 kB en bruto y 153,56 kB transferidos (F: 578,11 y 122,48). **0 apariciones** de `UltronCommitService`, `SalesAgentOrchestratorService`, `SalesAiConfig`, `MarketingAgentSwitch`, `UltronAbortLatch`, `R-01`, `R-02`, `R-12`, `payment_links_enabled`, `canary_conversation_id`, `MARKETING_`, `ULTRON_STUDIO_` y `webhook`. `.env` y `config(` dan falsos positivos: `this.enviando` y el `_config()` interno de Foblex |
| Mutaciones | 15, todas detectadas por su spec. Entre ellas: «Usar el valor del motor» sin «≠ motor», quitar la prevención, el 422 con un solo mensaje por clave, el historial sin filtro por capa, comparar al revés, la página sin leer el motor, lo de n8n presentado como «Fijo en código», el efecto del servidor como `[innerHTML]` y las tres del aviso B-1 |
| Chrome real con la API simulada | **20/20** (ver abajo) |

**Tamaño de las hojas.** Fuente → `sass --style=compressed`, sin la encapsulación de Angular. El build no avisa de ninguna, así que todas compilan por debajo de los 8 kB del aviso.
- Nuevas: `studio-param-row` 3017 → 2178 B · `studio-conditions-editor` 2630 → 1966 B · `studio-history-list` 1841 → 1380 B · `studio-compare-view` 2329 → 1842 B.
- Modificadas: `studio-inspector` 8012 → 6309 B (global, sin encapsular) · `studio-rules-panel` 5098 → 4000 B · `studio-versions-panel` 5857 → 4592 B · `studio-audit-panel` 1225 → 898 B.

El build mide hoy `ultron-studio.scss` en 27,87 kB, y el informe final de F decía 31,04 kB. La fuente es byte a byte la misma, así que la diferencia es de medición, no de G.

**En Chrome real (no es el E2E con la pila real).** `ng serve`, `puppeteer-core` y Chrome, con la API del Studio simulada con las formas del informe del backend, y la validación y el análisis hechos por las mismas funciones TS empaquetadas. **20/20:**
- la lectura del motor no bloquea, y «Motor ahora» de Gobierno;
- «≠ motor», la prevención, las reglas con su estado y las dependencias;
- el historial filtrado;
- la contradicción en vivo con su enlace, el 409 con la lista, y «Ver S-001», que aparca y resalta;
- el 422 de R-02 junto a su fila;
- la comparación, y la auditoría sin `<pre>`;
- escritorio a 1440, tableta a 820 y móvil a 390 px, sin desplazamiento horizontal ni desbordes del inspector;
- ningún error de JavaScript.

Esa pasada encontró y corrigió dos cosas: la quinta pestaña quedaba oculta tras un desplazamiento horizontal (449 px de pestañas en 368), y había un doble punto en «Leído … p. m..». Sus capturas **no se han incorporado** a `capturas/`.

### E2E con la pila real (tester, 18/19-10-2026)

**La pila.** Backend en instantánea (`git archive`) de `c8d9cf4` y, después, de `391cffb`, con `artisan serve --no-reload` (8 workers) y PostgreSQL 14 en una base propia. CRM `7d3ad2a` con `ng serve`. Chrome real, con login por el formulario y tres cuentas reales (Super Admin, Administrador y Recepción). El entorno de prueba tiene el interruptor, el canario y la bandera de cobro puestos, para que se vean «≠ motor» y «Usar el valor del motor»; solo los lee el Studio.

**Resultado:** **126/126 sobre el código final** (backend `dfb30f5`, CRM `44c0e8c`, ejecutado por el lead el 19-10-2026); pasadas previas del tester: 124/126 en `391cffb` y 122/126 en `c8d9cf4`.
- Las dos que solo fallan en `c8d9cf4` son las del freno sin tabla: salía como «freno suelto» leído. Es I-1 de `security`, corregido en `dfb30f5`.
- Las dos que fallaban en las pasadas previas eran de **H-1 del E2E** (este H-1 no es el del tester de backend), corregido en `38b238b`; H-2 (cabecera que se encogía con el inspector abierto) corregido en el mismo commit con `flex: none` (+10 bytes compilados); I-1 del reviewer (falsa discrepancia tras escribir reglas) corregido en `44c0e8c`.

| # | Escenario | `391cffb` |
|---|---|---|
| E1 | Carga: fase `ready`, lecturas reales de Gobierno («Atiende: solo la conversación canaria»), una fuente caída (`plans`) sale como no disponible con el Studio usable, el freno sin tabla da «Atiende: no se sabe (falta alguna lectura)» | 18/18 |
| E2 | Inspector, `rules.paymentLinksEnabled`: «Motor: sí», «≠ motor», «Usar el valor del motor» con prevención (`config-cobro-sin-camino`); cancelar no cambia nada | 13/13 |
| E3 | Reglas con condiciones: S-001 «≤ 300» (201 canónica), «≥ 320» con aviso en vivo y 409, «descuentos = sí» con 422 y sus dos mensajes, quitar condiciones (`conditions: null`), auditoría | 25/27 (**H-1**) |
| E4 | Borrador: prevención con `regla-S-001`, hallazgos en vivo y «el servidor manda» cuando otra persona crea una regla | 9/9 |
| E5 | Versiones y cuatro ojos: formato 2 con 14 reglas copiadas, `REVIEW_REQUIRED`, aprobación por otra persona tras reconocer los 5 avisos, publicar → 409 (403 sin la llave), comparación e historial | 24/24 |
| E6 | Permisos: el Administrador sin `ultron.manage_rules` (retirado en Roles) recibe 403 y no ve «Nueva regla»; Recepción, sin menú y con 403 en `status`, `engine/readings` y `compare` | 8/8 |
| E7 | Texto hostil en regla, historial, editor, inspector, etiqueta de versión, comparación y nombre de plan: literal, sin crear elementos ni abrir diálogos | 13/13 |
| E8 | Teclado en las cinco pestañas y móvil a 390 px, sin desplazamiento horizontal | 9/9 (+2 observaciones) |
| E9 | Cierre: la huella de las tablas del motor y de las colas es igual al empezar y al terminar; sin errores de JS, sin 5xx (252 peticiones) y `laravel.log` sin ERROR | 5/5 |

El E2E detecta las 6 mutaciones que se le probaron, todas en copias:
- lecturas del motor sin fallo suave;
- sin 409 `rule_contradiction`;
- publicar respondiendo 200;
- la descripción de una regla como `[innerHTML]`;
- sin prevención;
- el sí/no del motor invertido.

**Hallazgos del E2E (abiertos, del CRM):**
- **H-1 (medio).** «Ver S-NNN», tras el 409, no lleva el foco ni la vista a la regla: el foco vuelve a «Nueva regla» y la regla queda fuera de la vista.
  - **Causa:** `verRegla` enfoca y desplaza en un `afterNextRender`, y después la restauración del foco del diálogo (`ib-dialog`, un `queueMicrotask` al destruirse) devuelve el foco al botón.
  - **Por qué no lo vieron las pruebas:** la revisión con la API simulada tenía 5 reglas, con S-001 visible sin desplazar, y el spec del panel no mira el foco.
  - **Dueño:** frontend.
- **H-2 (bajo; ya ocurría en F, y G lo agrava).** Con el inspector abierto, la fila de acciones de la cabecera se pinta encima del aviso principal «El motor de ULTRON no lee nada de esto…». A 1440 px, «Validar» tapa parte del texto; a 1680 y 1920, los botones se pintan sobre la franja. Es cosmético, porque los botones funcionan, pero tapa el aviso de seguridad principal en el ancho de escritorio más habitual.
  - **Causa probable** (no demostrada en el CSS): el chip nuevo «Motor leído …» de la cabecera.
  - **El dilema:** corregirlo seguramente toca `ultron-studio.scss`, que G tiene a cero bytes. Lo decide el lead.
- **Observaciones:**
  - En las pestañas sin nada enfocable, Tab sale del inspector. El patrón APG recomienda `tabindex="0"` en ese `tabpanel`.
  - El texto real de una fuente caída es «El servidor no pudo leer esta fuente.».
  - El Administrador tiene `ultron.manage_rules` por defecto.

**Spec de texto hostil, sin commit.** `studio-hostile-text.spec.ts` añade 6 pruebas: comparación, panel de reglas (409 y 422), inspector, editor de condiciones, historial y fila de parámetro.
- Con él, el módulo pasa a 23 ficheros y 495 pruebas, y la suite completa a 2528 en verde, con los mismos 16 fallos de la línea base.
- Con `[innerHTML]` en los seis componentes, fallan las 6.
- Está en el árbol del CRM, pendiente de que el lead lo revise y lo confirme.

**Qué no cubre el E2E:**
- la pila de producción: nginx con FPM (5 workers), `config:cache` y `route:cache`, y el build de producción, porque corre con `ng serve` en modo desarrollo;
- los cupos (429), con `CACHE_STORE=array`;
- el 413, porque el CRM no llega a 64 KB;
- la tableta a 820 px (solo la revisión con la API simulada);
- Safari, Firefox, Windows y lectores de pantalla reales.

**Qué no se declara verificado:**
- lectores de pantalla reales;
- Safari, Firefox y Windows;
- el CRM de G sobre la pila de producción;
- el tamaño compilado exacto de cada hoja con la encapsulación.

## Revisión final independiente (`reviewer`) de la vista previa (Hito C)

Veredicto inicial **BLOQUEADO** por un hallazgo confirmado en Chrome; todo lo que sigue quedó corregido y cubierto por pruebas antes de publicar.

| Hallazgo | Corrección |
|---|---|
| **Bloqueante.** `.us-node` tenía `transition: transform 0.25s`; Foblex coloca el nodo con `transform` y redibuja sus conexiones 1–4 ms después, así que tras Deshacer, Rehacer, Restaurar o mover con teclado las líneas se quedaban donde estaba el nodo (medido: 300 px). El E2E no lo veía porque comparaba el `transform`, no la geometría | Transición solo de `box-shadow`, con el motivo comentado en el SCSS. El E2E mide ahora la distancia real entre el extremo de la conexión y el puerto (< 10 px) tras deshacer, rehacer y restaurar |
| **Importante.** La validación no miraba la configuración, aunque los textos de consecuencia prometían «la validación lo bloquearía» | Diez reglas de configuración en el catálogo (datos, no código: `ConfigConstraint`), aplicadas por `validateDraft`; una prueba exige que toda consecuencia que mencione la validación tenga su regla |
| **Importante.** Faltaban las dependencias «en el commit» de la auditoría: el commit lee memoria (pago, membresía, perfil) y conocimiento (planes, precio, horario, `brand_copy`) | Aristas obligatorias E18 (Memoria → Reglas) y E19 (Conocimiento → Reglas) con sus puertos |
| Los puertos conectados nunca se rellenaban (clase antigua de Foblex) | `.f-connector-connected` |
| El indicador de zoom decía 100 % tras el ajuste inicial | `fitToScreen` con emisión del cambio |
| Lector de pantalla: índices «2 de 10», ids de conector en las conexiones, `role="listitem"` sobre botones, pestañas sin `aria-controls` ni flechas, nodos atenuados con poco contraste | Índices de Foblex (ya empiezan en 1), nombres «Capa (puerto)», sin roles sobre botones, patrón tablist completo (flechas, Inicio, Fin), opacidad 0,55 |
| Cancelar un `select` importante dejaba el valor rechazado a la vista | `cancel()` devuelve el `<select>` al valor del borrador |
| Inspector superpuesto (< 1280 px) tapaba la cabecera y seguía en el orden de tabulación plegado | `position: relative` en el cuerpo e `inert` cuando está plegado |
| Soltar sobre un puerto no permitido no explicaba nada (Foblex no entrega destino) | Se mira qué puerto hay bajo el puntero y se da el motivo (tipos o camino inexistente) |
| `setTimeout` sin cancelar al salir; Supr múltiple con un paso por conexión; restaurar sin cambios apuntaba pasos; «sin guardar» dependía del orden de las aristas; dos avisos iguales no se re-anunciaban; cargador animado tras `is-done` | Cancelación en `ngOnDestroy`; `disconnectMany` (un paso, un aviso); restaurar sin cambios no apunta; huella con aristas ordenadas; aviso rastreado por `seq`; animación detenida |
| El selector de versión era decorativo | `LEGACY_BASELINE_V1` enseña la línea base en un almacén de solo lectura; el borrador se conserva |
| `driver: 'openai'` como línea base no verificada | Línea base «Según el entorno (no verificado)»; las reglas muestran «transcritas del código el 17-10-2026» |
| Posible desbordamiento de la cabecera entre 900 y 1440 px (no verificado por el revisor) | Cabecera con `flex-wrap` por debajo de 1440 px; el E2E lo mide a 1100 y 1280 px |

Quedan anotados para la fase de persistencia (no son fallos de la vista previa): `GraphLayout` y `LayerConfiguration` están declarados y el almacén usa un solo snapshot; conviene separarlos cuando exista el backend.

**Qué no se declara verificado:** gestos táctiles reales, Safari y Firefox, y el rendimiento con un grafo mayor de diez nodos (no aplica a esta fase).

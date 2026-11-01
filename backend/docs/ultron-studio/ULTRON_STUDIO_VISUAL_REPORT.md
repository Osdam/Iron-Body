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

**Estado:** implementado en la rama `feat/ultron-studio-g` del CRM, **no desplegado** *(así se escribió. **Actualización del 19-10-2026:** desplegado y verificado ese día, CRM `44c0e8c`; ver `ULTRON_STUDIO_FINAL_REPORT.md`)*. Commits sobre `020db86` (= producción):
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
- la pila de producción: nginx con PHP-FPM 8.3 (24 procesos; la cifra de 5 que figuraba aquí era del pool inactivo de PHP 8.1, corregida el 19-10-2026), `config:cache` y `route:cache`, y el build de producción, porque corre con `ng serve` en modo desarrollo;
- los cupos (429), con `CACHE_STORE=array`;
- el 413, porque el CRM no llega a 64 KB;
- la tableta a 820 px (solo la revisión con la API simulada);
- Safari, Firefox, Windows y lectores de pantalla reales.

**Qué no se declara verificado:**
- lectores de pantalla reales;
- Safari, Firefox y Windows;
- el CRM de G sobre la pila de producción;
- el tamaño compilado exacto de cada hoja con la encapsulación.

## Hito 4: el laboratorio de IA en el CRM

**Estado:** implementado en la rama `feat/ultron-studio-h4` del CRM (HEAD `3e630eb`), **no desplegado** *(así se escribió. **Actualización del 31-10-2026:** desplegado el 25-10-2026 con el CRM `5c1dbd7`, posterior a `3e630eb`: lleva el segundo lote de correcciones (entre ellas, los textos por tipo de tope, `2b6375b`) y la fusión con `origin/main`; ver `ULTRON_STUDIO_FINAL_REPORT.md`, «Hito 4: despliegue y verificación»)*. Commits sobre `44c0e8c` (= producción):

| Commit | Qué |
|---|---|
| `dbdf73d` | Capa de datos: tipos, parse, servicio, sesión, dinero y specs |
| `e3d1a0d` | Formas de las Enmiendas 2 y 3 (`excludedRules`, `judgeError`, `invalid_driver`, `mode: null`, `target_contains_personal_data`) y dos lecturas de G |
| `7da6f08` | Permisos `ultron.lab_manage` y `ultron.lab_run` y exclusión del Administrador para `lab_run` |
| `4c022f8` | La página, sus componentes, la ruta y las dos entradas desde el tablero |
| `32c03d3` | Fixture oficial de los escenarios exportado desde el backend (12 entonces; 11 tras retirar C-11 en la Enmienda 6, regenerado en el commit final) |
| `564cf14` | Correcciones de la Enmienda 4: aviso al archivar, archivados recortados y `lab-invisibles` |
| `3e630eb` | H-1 del E2E (frase del filtro del motor sin duplicar) y aviso al rechazar una versión |

70 ficheros: 64 dentro de `src/app/modules/ultron-studio/` y 6 fuera (`app.routes.ts`, `permissions.enum.ts`, `backend-permission-catalog.ts`, `permission-contract.spec.ts`, `access-control.service.ts` y `shared/components/reason-dialog/reason-dialog.ts`). **`ultron-studio.scss` no gana ni un byte** y no hay dependencias nuevas (`package.json` y `package-lock.json` sin cambios). El inspector del tablero (`studio-inspector.ts`) no cambia en este hito.

### Página hija y entradas

- **Ruta** `marketing/ultron-studio/laboratorio`, con `ULTRON_VIEW` y dentro del bloque de la bandera `ultronStudioPreviewEnabled`; `loadComponent` carga `studio-lab-page` (selector `app-ultron-studio-lab`). Ver la página basta con `ultron.view`; escenarios, ejecuciones y veredictos los autoriza el servidor con `ultron.lab_manage`, `ultron.lab_run` y `ultron.approve`.
- **Entradas desde el tablero**, solo en plantilla:
  - «Laboratorio» en la cabecera (`ultron-studio-page.ts`, icono `biotech`; en móvil solo el icono, con el nombre accesible);
  - «Probar en el laboratorio» en el detalle de cada versión (`studio-versions-panel.ts`): abre «Ejecuciones» con `?version=<uuid>`. Con `integrityOk: false` es un botón deshabilitado con su motivo, porque el servidor daría `integrity_mismatch`.
- **Por qué una página y no un panel:** el panel del tablero es una columna lateral, los resultados necesitan anchura y `ultron-studio.scss` no admite ni un byte. El coste: los 24 tokens `--us-*` viven en el host del tablero, así que la página los declara en su hoja y `studio-lab-tokens.spec.ts` compara nombres y valores con `ultron-studio.scss` (leído del disco) para que no deriven.

### Componentes

Cada componente es standalone y `OnPush`, con su hoja y su spec.

| Pieza | Selector | Qué hace |
|---|---|---|
| `studio-lab-page.ts` (+ `studio-lab-kit.scss`) | `app-ultron-studio-lab` | La página: provee `StudioLabSession`, cabecera, fases (`loading`, `ready`, `disabled`, `forbidden`, `error`), pestañas «Escenarios» y «Ejecuciones» y las dos columnas |
| `studio-lab-budget.ts` | `us-lab-budget` | Presupuesto del día de Bogotá: gastado, reservado, lo que queda, ejecuciones del día y tope por ejecución. La barra es `aria-hidden`; las cifras van en texto |
| `studio-lab-scenarios.ts` | `us-lab-scenarios` | Lista con filtro de estado y de categoría, ficha (el catálogo, «solo lectura»), crear, editar y archivar, e historial plegable |
| `studio-lab-scenario-editor.ts` | `us-lab-scenario-editor` | El editor de escenarios (ver abajo) |
| `studio-lab-transcript.ts` | `us-lab-transcript` | El guion como conversación y los hechos sintéticos |
| `studio-lab-launcher.ts` | `us-lab-launcher` | «Nueva ejecución»: objetivo, escenarios, estimación y «Ejecutar (máx. US$ …)» |
| `studio-lab-runs.ts` | `us-lab-runs` | Lista de ejecuciones con alcance (todas o mías), objetivo y paginador |
| `studio-lab-run-view.ts` | `us-lab-run-view` | La ejecución abierta: progreso, gradilla, propuesta, casos, veredicto e historial |
| `studio-lab-case.ts` | `us-lab-case` | Un caso: guion, respuesta, hallazgos, opinión de la IA y coste |
| `studio-lab-verdict.ts` | `us-lab-verdict` | El formulario del veredicto y el veredicto emitido |
| `studio-lab-compare.ts` | `us-lab-compare` | «Comparar con la línea base», con el `GET compare` de G en solo lectura |
| `studio-lab-history.ts` | `us-lab-history` | El libro del laboratorio en frases |
| `studio-lab-text.ts`, `studio-lab-dom.ts` y `studio-lab-money.ts` (puros) | — | Las etiquetas (planos, estados con icono y texto, motivos, paradas y clases de fallo); llevar a la vista sin mover el shell; importes en micro-USD con `Intl.NumberFormat('es-CO', …)` |
| `studio-lab-api.types.ts`, `studio-lab-parse.ts`, `studio-lab-api.service.ts` y `studio-lab-session.ts` | — | La capa de datos: las 12 rutas, el parse que falla cerrado y la sesión con su sondeo |

**Ficheros de G que cambian:** `ultron-studio-page.ts` y `studio-versions-panel.ts` (plantilla e importación de `RouterLink`); `studio-rules-panel.ts` y `studio-versions-panel.ts` (el aviso de datos personales al archivar una regla y al rechazar una versión); `studio-conditions-editor.ts` (la constante del aviso); `studio-parse.ts` (solo exporta, al final del fichero, sus herramientas de lectura). Fuera del módulo, `ib-reason-dialog` gana una ranura opcional `[rd-aviso]` y la entrada `avisoId`: sin ellas pinta lo mismo, y sus otros cuatro usos siguen en verde (331/331).

### Lo que se ve

Capturas del E2E final (Chrome, pila real local con el simulador de OpenAI) en `scratchpad/e2e-studio/shots-h4-6dbf485/`; no se han incorporado a `capturas/`.

- **Cabecera:** «Laboratorio de ULTRON STUDIO» y, debajo, fijo y en cian, «Simulación del Studio · no es ULTRON». A la derecha, el modo: «Ensayo sin IA», «OpenAI · <modelo>» o «Modo no válido» (`mode: null`). Debajo, el presupuesto a todo lo ancho y una franja con el texto `fidelity` del servidor; si no está configurado, los motivos en llano.
- **Planos con color fijo y siempre con texto:** «Comprobación del Studio» en violeta, «Filtro del motor» en cian, «Opinión de la IA» en magenta y la propuesta con el tono de lo que propone. Ningún estado es solo color.
- **La gradilla:** un tubo por caso que se llena con el tono de su resultado, con el código, el icono y el estado en su nombre accesible. El que está en curso late en opacidad, nunca en `transform`, y respeta `prefers-reduced-motion`.
- **Disposición:** en escritorio (≥ 1100 px) la raíz no se desplaza y cada columna tiene su scroll; en tableta y móvil todo se apila.

**Editor de escenarios.** Límites de `status.limits` contados en caracteres reales, contadores y un medidor de tamaño frente a los 16 KB. Turnos de 1 a 8 (el último, de la persona), criterios con las reglas citables de `GET rules` de G. No envía lo que el servidor rechazaría seguro, y cada 422 va junto a su campo y su fila (`turns.2.text`, `criteria.0.rules.0`…). El 409 `scenario_conflict` ofrece «Cargar su versión» o «Guardar los míos sobre la suya». El `clientRequestId` se genera al abrir y se reutiliza en los reintentos. **Aviso fijo**, enlazado con `aria-describedby`: «No escribas datos personales (nombres, teléfonos, correos, documentos): los escenarios son sintéticos, se envían a un proveedor externo y no se pueden borrar.», con una pista de lo que rechaza el filtro (emojis compuestos, «$1.200.000», «15-10-2026»).

**Lanzador.** Objetivo (una versión, «Mi borrador (revisión N)» o la línea base; `?version` manda), escenarios agrupados en catálogo y personal, y la estimación: «Como mucho US$ …», lo esperado en su línea y el desglose (casos, modo, prompts, el día, tope por ejecución y ejecuciones de hoy), con `excludedRules` y la negativa del servidor. «Ejecutar (máx. US$ …)» envía exactamente ese máximo; se deshabilita, con el motivo **en texto visible**, sin la llave, sin configurar, sin estimar, si la selección cambió o si la estimación lo niega. Cualquier cambio de objetivo o de escenarios descarta la estimación.

**La ejecución.** Objetivo, estado, fidelidad, modo, prompts, autoría, fechas y coste real frente al máximo. El progreso está en `aria-live="polite"` («1 de 2 casos cerrados, 1 por hacer…») y se actualiza cada 5 s, solo mientras esté en cola o en curso y con la pestaña visible. Cancelar (con su motivo si no se puede), «Comparar con la línea base», la propuesta («Propuesta del laboratorio: no es un veredicto»; en un ensayo sin IA, «Ensayo sin IA: no hay propuesta ni veredicto.»), las reglas que recibió el actor, los casos, el veredicto y el historial.

**El caso.** Abierto por defecto si falla, hay que revisarlo o dio error. La respuesta va rotulada «Actor de laboratorio (simulación del Studio, no es ULTRON)» y se pinta literal. Cada hallazgo lleva su plano y su nivel en icono y texto; los del motor se leen tal como los manda el servidor en el `title`, «El filtro real del motor marcaría este texto (<motivo>).», con el código del filtro debajo. Después, la opinión de la IA criterio a criterio (evidencia y razón), las reglas, los riesgos y el coste con tokens y exceso. Si el juez falló: «La IA no pudo opinar (<clase>): sin su opinión, el caso llega como mucho a «Revisar».».

**El veredicto.** Tres decisiones con lo que significa cada una. `approved` exige marcar exactamente los casos que no pasaron («Faltan N casos por reconocer»); la nota es obligatoria salvo al aprobar. Aviso fijo: «No escribas datos personales (nombres, teléfonos, correos): la nota del veredicto queda en un libro que no se puede borrar.». Si no procede, el formulario se deshabilita con el motivo (versión propia, ensayo sin IA, parcial, objetivo que no es una versión o sin `approve`). Texto fijo: «Uno por ejecución y no se puede cambiar. No cambia la versión ni el motor.».

**Avisos al archivar y al rechazar** (Enmienda 4.7): «No escribas datos personales (nombres, teléfonos, correos): el motivo queda en un historial que no se puede borrar.», en archivar un escenario (`564cf14`), archivar una regla de G (`564cf14`) y rechazar una versión de G (`3e630eb`), dentro del diálogo y anunciado por el campo.

**Archivados recortados** (Enmienda 4.3): con `meta.truncated`, la lista dice «Mostrando los 200 más recientes de N.» encima de los elementos.

**Accesibilidad.** Pestañas con roles, `aria-selected`, `aria-controls`, `tabindex` itinerante y flechas, Inicio y Fin; detalles nativos, radios y casillas reales; el foco va al título al abrir una ejecución, una ficha o el editor (con `preventScroll`), y el foco visible es cian.

### Atributos `data-*` para el E2E

Todos los de A9 del contrato, más algunos de apoyo:
- **Página:** `[data-lab-pagina]` con `data-lab-fase`, `[data-lab-apagado]`, `[data-lab-modo]` con `data-lab-modo-valor` (`openai`, `fake` o `invalido`), `[data-lab-presupuesto]` con `data-lab-presupuesto-estado` (`disponible`, `agotado` o `sin-tope`) y `[data-lab-pestana="escenarios|ejecuciones"]`.
- **Entradas en G:** `[data-lab-enlace]` y `[data-version-lab="<uuid>"]`.
- **Escenarios:** `[data-lab-escenario="<code>"]`, `[data-lab-escenario-nuevo]`, `[data-lab-escenario-guardar]`, `[data-lab-escenario-archivar]`, `[data-lab-aviso-datos]` (en el editor, en el veredicto y en el diálogo de archivar: ya no es único), `[data-lab-editor-campo-error="<clave>"]` y `[data-lab-archivados-truncados]` (solo con `truncated: true`).
- **Lanzador:** `[data-lab-objetivo]`, `[data-lab-estimacion]` con `data-lab-estimacion-estado` (`permitida` o `rechazada`), `[data-lab-reglas-fuera]`, `[data-lab-negativa]`, `[data-lab-ejecutar]` y `[data-lab-ejecutar-motivo]`.
- **Ejecución:** `[data-lab-run="<uuid>"]`, `[data-lab-run-estado]` con `data-estado`, `[data-lab-cancelar]`, `[data-lab-tubo="<code>"]`, `[data-lab-progreso]`, `[data-lab-caso="<code>"]` con `data-lab-caso-estado`, `[data-lab-hallazgo="<id>"]` con `data-plano` (`studio` o `engine`), `[data-lab-juez]` con `data-lab-juez-estado` (`opina`, `fallo` o `sin-opinion`) y `[data-lab-propuesta]` con `data-lab-propuesta-valor`.
- **Veredicto:** `[data-lab-veredicto]` con `data-lab-veredicto-estado` (`disponible`, `no-disponible` o `registrado`), `[data-lab-veredicto-decision="approved|needs_changes|rejected"]`, `[data-lab-veredicto-reconocer="<code>"]`, `[data-lab-veredicto-nota]` y `[data-lab-veredicto-enviar]`.
- **Avisos de G:** `#rp-archivar-datos` y `#vp-rechazar-datos` con `[data-aviso-datos-personales]`.

### Decisiones fuera del contrato

1. Dos hojas de página (`studio-lab-page.scss` y `studio-lab-kit.scss`), globales bajo `.lab-root` y con el prefijo `lab-`: en una sola eran 8,6 kB de fuente.
2. `studio-lab-dom.ts` (`labReveal`): solo desplaza el contenedor con scroll propio y enfoca con `preventScroll`. La revisión en Chrome vio que `scrollIntoView` y `focus()` movían los contenedores del shell con `overflow: hidden`; `.lab-root` pasa a `overflow: clip` en escritorio.
3. El estado (activos o archivados) se filtra en el servidor; la categoría, en local.
4. La capa de datos falla cerrado ante una forma desconocida, pero conserva los motivos de configuración que no conoce y lee suave `targetSnapshot` y `meta`.
5. Desde `3e630eb`, la frase de A4 («marcaría», nunca «rechazaría») solo la pone el backend: el CRM pinta el `title` tal cual. «Parte del filtro del motor no está disponible» ya no sale vestido de «marcaría».

### Pruebas y medidas

| Comprobación | Resultado |
|---|---|
| Specs del módulo (`ng test --include='src/app/modules/ultron-studio/*.spec.ts'`) | 499 en G → 622 con la capa de datos (`dbdf73d`) → 730 con la página (`4c022f8`) → 737 (`564cf14`) → **739/739 en `3e630eb`**, 43 ficheros |
| Suite completa (`ng test --watch=false`) | **2786 pruebas en `564cf14`, con 16 fallos idénticos nombre a nombre a la línea base** (moderación ×14, `financial-realtime.integration` y `receivables-pagination`); ninguno nuevo ni del Studio. En `3e630eb` solo se repitió el módulo: nadie fuera de él importa los ficheros tocados |
| `tsc` de la aplicación y de los specs | Limpio en `3e630eb` |
| `check:literals` | 313 ficheros, correcto |
| Build de producción | Exit 0 y 0 errores; los mismos 29 avisos de presupuesto de siempre, ninguno del laboratorio. Todas las hojas nuevas compilan por debajo de los 8 kB del aviso, y un spec lo vigila |
| Chunk del laboratorio (`studio-lab-page`) | **186,75 kB en bruto y 44,14 kB transferidos en `4c022f8`** (idéntico en `32c03d3`); 187,77 kB en `564cf14` y **187,58 kB en `3e630eb`**. El tablero baja de 716,51 a 636,94 kB: unos 80 kB de módulos de G pasan a chunks compartidos. En el cierre del chunk: 0 apariciones de `UltronCommitService`, `SalesAgentOrchestratorService`, `SalesAiConfig`, R-01/R-02/R-12, `OPENAI_API_KEY`, `ULTRON_STUDIO_LAB`, `api_key`, `sk-`, `MARKETING_`, `webhook`, `Bearer`, `innerHTML` y `bypassSecurityTrust` |
| `ultron-studio.scss` | 0 bytes de diff |
| Catálogo de permisos | `backend-permission-catalog.ts` idéntico byte a byte al exportado por el backend (sha256 `1075374b…`) |
| Mutaciones | **18 + 6 + 3, todas tumbadas** (página y componentes en `4c022f8`; correcciones en `564cf14`; H-1 y aviso de rechazar en `3e630eb`), más 11 de la capa de datos en `dbdf73d`. Entre ellas: el botón que enseña lo esperado en vez del máximo, «Ejecutar» sin `lab_run`, aprobar sin reconocer todos los casos, el progreso sin `aria-live`, la respuesta del actor como `[innerHTML]`, un token que deriva del tablero, el Administrador con `lab_run`, quitar cada aviso y volver a envolver el título del motor |
| Chrome real con la API simulada (no es el E2E con la pila real) | **40/40** (`4c022f8`, a 1440, 820 y 390 px sin desplazamiento horizontal), **19/19** (`564cf14`) y **13/13** (`3e630eb`), las dos últimas a 1440 y 390 px; sin errores de JavaScript |

### E2E con la pila real (19-10-2026)

**122/122 en 183 s** sobre el backend `6dbf485` y el CRM `3e630eb` (`scratchpad/e2e-studio/h4-run.sh`, salida en `h4-run-final-out.txt`): backend con `artisan serve`, PostgreSQL 14, el worker real `queue:work lab`, un simulador local de OpenAI y Chrome con login real. Por escenario: E1 bandera apagada 5/5, E2 encendido, editor y Recepción 17/17, E3 estimar y ejecutar 28/28, E4 veredicto 9/9, E5 cancelar, cuota, `incomplete` y ensayo sin IA 15/15, E6 presupuesto y `estimate_changed` 8/8, E7 texto hostil 7/7, E8 teclado y tres tamaños 21/21 (más el aviso al archivar, ya esperado), E9 cierre 12/12. La pasada anterior, sobre `4fbce0a` y `32c03d3`, dio 121/122 por H-1, corregido en `3e630eb`. El detalle está en `ULTRON_STUDIO_FINAL_REPORT.md`.

### Pendiente y no verificado

- **Etiqueta de `budget_exhausted`** (**pendiente; lo decide el lead**): el CRM dice «Se agotó el presupuesto del día», pero desde la Enmienda 4.1 la parada también puede venir del tope por ejecución o del máximo aceptado. El motivo exacto va en el libro de la ejecución (`budgetCap`). Opciones del backend: un texto genérico en el CRM, o exponer `budgetCap` en el DTO.
- **Color de los avisos:** ningún aviso de datos personales sale con su color de acento (tampoco los de G), porque una regla global del CRM con `!important` lo pisa. Se leen con buen contraste. Fuera de alcance.
- **El asistente flotante del shell** tapa a 390 px parte de «Archivar la regla» en G, y en el laboratorio flota sobre la esquina inferior derecha. Ya ocurría; no se comprobó si intercepta toques.
- **Riesgo previo más visible:** el tablero no tiene guarda de salida para cambios sin guardar del borrador; ir al laboratorio por el enlace nuevo, igual que por el menú, abandona lo no guardado.
- **No verificado:** Safari (`overflow: clip` necesita Safari 16 o posterior), Firefox, Windows, lectores de pantalla reales, gestos táctiles y el CRM del laboratorio sobre la pila de producción.

## Hito 5: la publicación en el CRM

**Estado:** implementado en la rama `feat/ultron-studio-h5` del CRM (HEAD `a29bcc8`, fusionada con `origin/main`, `4105016`, el 31-10-2026 en `9fe1817`), **no desplegado**. Producción sirve `4105016`, cuyo módulo del Studio es el de `5c1dbd7`. Commits sobre `5c1dbd7`:

| Commit | Qué |
|---|---|
| `3989f90` | Arnés E2E con la pila real como activo del repo (`e2e/ultron-studio/`, A10.5) |
| `9b70337` | Capa de datos de la publicación (tipos, lectores, servicio y sesión) y la carrera de «Validar en el servidor» (A10.3) |
| `e7ba41b` | La integración (A11): panel de versiones, los dos diálogos, la barra, el historial, comparar y las lecturas del motor |
| `33821e4` | Ajustes tras el informe de la API: la puerta dispensada con el mensaje del servidor, una sola vez (C-1 del E2E), y los bloqueos sin fila propia |
| `24a691d` | D-1 (`publication_head_invalid`) y paridad con `backend-publication.testing.ts`, copiado byte a byte del backend |
| `a29bcc8` | E2E del Hito 5 con la pila real (`specs/h5-governance.cjs`, 276 comprobaciones) y arreglos del arnés |
| `9fe1817` | Fusión de `origin/main` (`4105016`), 31-10-2026: otros módulos (caja, pagos, biometría, miembros, planes e IRON IA); fuera del módulo cambian el catálogo de permisos y su enumeración |

Todo queda dentro de `src/app/modules/ultron-studio/`, salvo los comentarios de `ULTRON_PUBLISH` y `ULTRON_ROLLBACK` en `permissions.enum.ts` (la ayuda que ven los Roles la sirve el backend) y el arnés de `e2e/`. **`ultron-studio.scss` no gana ni un byte** (sha256 `fc558002…`, fijado por `studio-h5-sheets.spec.ts`) y no hay dependencias nuevas.

**Qué cambia para quien lo usa**, con un backend del Hito 5 (`engine.readsVersions: true`):
- la **barra de la publicación**, bajo el aviso del motor: qué rige (en el canario, en todas o Legacy), la llave del motor y si el sello está bien o hay deriva; desde ella, el historial y «Volver a Legacy»;
- el **detalle de cada versión** ofrece «Publicar en canario», «Promover a todas» y «Revertir» según `actions` del servidor; si no se puede, no hay botón y el motivo se lee en el panel;
- dos **diálogos** nuevos: publicar, con la vista previa, y promover, con la evidencia del canario;
- el **historial** de la cadena, paginado;
- la insignia **«Huella alterada»** al comparar;
- la lectura del motor **`governance.studioPublication`**, con su texto;
- «Volver a preguntar al servidor»: ante `validation_stale`, un aviso informativo en vez de un error.

Con un backend anterior (`readsVersions: false`), todo sigue como en F/G: «Publicar» deshabilitado con el motivo del servidor, sin barra y con el aviso «El motor de ULTRON no lee nada de esto». Lo comprobó el E2E con la pila real: el CRM nuevo con el backend anterior da lo mismo que la línea base, 131/131.

### Componentes nuevos

Cada componente es standalone y `OnPush`, con su hoja y su spec.

| Pieza | Selector | Qué hace |
|---|---|---|
| `studio-publication-dialog.ts` (+ `.scss`) | `us-publication-dialog` | «Publicar en canario»: la vista previa y el envío con `previewHash` |
| `studio-canary-evidence.ts` (+ `.scss`) | `us-canary-evidence` | «Promover a todas»: la evidencia de A6, la cuenta atrás del 429 y el envío con `evidenceHash` |
| `studio-publication-history.ts` (+ `.scss`) | `us-publication-history` | La cadena paginada, o una lista fija en el detalle de una versión |
| `studio-publication-bar.ts` (+ `.scss`) | `us-publication-bar` | La barra de estado, «Volver a Legacy» con motivo y el historial en un diálogo |
| `studio-publication-text.ts` (puro) | — | El texto obligatorio de A11 y los nombres de llaves, acciones, puertas, clases y veredictos; las filas de requisitos y de evidencia; el sello; la deriva en llano |

**Ficheros que cambian:** `studio-versions-panel.ts` (botones, motivos, texto obligatorio, entradas de la cadena, los diálogos y el color de los cuatro estados nuevos); `studio-compare-view.ts` (la insignia de huella); `studio-engine-text.ts` (la lectura nueva y su descriptor); `ultron-studio-page.ts` (solo plantilla, más una línea de lógica en `releerServidor()`); y la capa de datos (`studio-api.types.ts`, `studio-parse.ts`, `studio-api.service.ts` y `studio-session.ts`).

**Tamaño de las hojas** (bytes de fuente; un spec exige menos de 8000): `studio-publication-dialog.scss` 6289, `studio-canary-evidence.scss` 3311, `studio-publication-history.scss` 2516, `studio-publication-bar.scss` 3467, `studio-versions-panel.scss` 7208 (era 6200) y `studio-compare-view.scss` 2761 (era 2329). Ninguna da aviso de presupuesto compilada.

### La capa de datos (`9b70337`)

- **Lo que no existe en el backend de hoy es opcional en los tipos y no se inventa al leer.** `status.engine.publication` se lee tolerante (si falta o llega roto, el Studio arranca igual y `publicationUnreadable` dice por qué); `actions.promote` es opcional; las entradas ilegibles de `version.publications` se descartan y se cuentan; `governance.studioPublication` falla suave. Las llaves que el CRM no conoce se ignoran. Lo prueba `studio-publication-compat.spec.ts` con los DTO de hoy y con los del Hito 5 con llaves inventadas en cada nivel.
- **Las cuatro escrituras** (publicar, promover, revertir y Legacy) envían un cuerpo canónico y reutilizan el `clientRequestId` en los reintentos. Tras responder, se releen versiones, `status`, la versión abierta y el historial; sin red, con un 5xx o con un 429, no cambia nada. Un `preview_stale` trae la vista previa nueva en el propio error.
- **La evidencia respeta su limitador:** nunca dos peticiones a la vez y, tras un 429, no se vuelve a pedir hasta que pasa su `retry_after` (60 s si no lo dice).
- **La carrera de «Validar en el servidor» (A10.3):** antes, con el orden «GET enviado → guardado confirmado (revisión 8) → GET respondido (revisión 7)», el CRM cargaba la 7 encima de lo recién guardado. Ahora (a) con un guardado en curso responde `busy` sin pedir nada; (b) una respuesta con una revisión anterior no se aplica y avisa «Se guardó mientras validabas: vuelve a validar.»; y (b′) tampoco se aplica la que llega con un guardado en vuelo. Con el cuerpo anterior, los cuatro casos del spec fallan.

### Lo que se ve

- **Panel de versiones.** Los botones salen de `actions` del servidor. Sin la llave (como el Administrador) o con el servidor negando, no hay botón y el motivo va en texto visible, solo en el estado en que la acción tendría sentido: publicar en `APPROVED`, promover en `CANARY` y revertir en `CANARY` o `PUBLISHED` (D-F1). El detalle enseña el **texto obligatorio** («Publicar registra esta versión como la configuración oficial de ULTRON y el motor la anota en cada turno. En este hito no cambia lo que ULTRON dice ni hace.»), las entradas de la cadena que nombran la versión y la tubería con las etiquetas del servidor: `CANARY` en cian, `PUBLISHED` iridiscente, `RETIRED` («Sustituida») en gris y `ROLLED_BACK` en rosa, siempre con texto.
- **Publicar en canario.** Al abrirse pide la vista previa y lo anuncia mientras llega. Enseña los diez requisitos en el orden del servidor, con su motivo (icono, palabra y texto oculto «se cumple / no se cumple»); la puerta del laboratorio dice su modo, y **dispensada sale como alerta en rojo aunque deje publicar**. Después, «Lo que declara frente a lo que lee el motor» (los cuatro `must_match`, con la versión en violeta y el motor en cian), lo que coincide con el código (plegable) y «Sin efecto en este hito», con una casilla por elemento (las reglas S-NNN, en magenta, con su descripción). El envío se deshabilita con el motivo en texto visible (faltan N por reconocer, bloqueada, sin llave o esperando la vista previa) y lleva `previewHash` y el conjunto reconocido. Una vista previa nueva borra lo reconocido (D-F5); `preview_stale` la trae y obliga a reconocer otra vez; `no_effect_not_acknowledged` marca lo que faltaba. La nota opcional lleva aviso de datos personales, porque va a una cadena que no se borra (D-F7).
- **Promover a todas.** Desde cuándo cuenta, los umbrales y un veredicto en una línea («Alcanza para promover.» o «Aún no alcanza: horas en canario, turnos del canario.»), con la tabla de A6: horas, turnos, sellados, reparto por estado del sello, frenos, acta, recuperaciones, deriva y firma; cada medida, con lo que exige su puerta y si pasa. Tras un 429, «Actualizar» se deshabilita con cuenta atrás («Actualizar (en 41 s)») y se conserva la evidencia que ya se veía. `scope_not_allowed` añade que promover exige la llave del motor en todo el tráfico.
- **Revertir y Volver a Legacy** usan `ib-reason-dialog` (de 5 a 500 caracteres) con su ranura de aviso de datos personales. Ante `restore_integrity_mismatch`, el CRM dice que la salida es «Volver a Legacy», en la barra.
- **La barra de la publicación** (solo en la fase `ready`, D-F3): «En el canario: «X»; fuera de él: Legacy», «En todas: «X»» o «Legacy: el Studio no declara ninguna versión»; la llave del motor, por su efecto; y el sello («Correcto: firma verificada y sin deriva», «Deriva en N parámetros», «La firma de la cabeza no se verifica…», el motor sella Legacy o «Ninguno: la llave del motor está apagada»), con el número de la cabeza. El anillo del sello es el único gesto visual: iridiscente y quieto si está bien, rosa discontinuo con un problema y gris sin sello. La deriva se enseña parámetro a parámetro («No se revierte sola: se enseña aquí y bloquea promover»). «Volver a Legacy» solo aparece con `ultron.rollback` y si algo rige. Sin `engine.publication` (backend anterior) no pinta nada.
- **Comparar** lleva «Huella alterada» (icono y palabra) en el lado con `integrityOk === false`; el diff no cambia. **`governance.studioPublication`** se lee en frases («Llave: solo la conversación canaria. Cabeza n.º 1, firma correcta. Partes: canario con versión, resto en Legacy. Canario del motor configurado: sí.»); lo no comprobado dice «sin comprobar», nunca «correcta», y nunca se nombra la conversación canaria. El aviso del motor pasa a «El motor de ULTRON solo anota la versión publicada: guardar, crear, aprobar o publicar versiones no cambia lo que ULTRON dice ni hace.».

**Capturas** (Chrome con la API simulada, no la pila real; `plans/informes-h5/capturas-crm/`, a 1440 y 390 px):

| N.º | Qué enseña | Escritorio | Móvil |
|---|---|---|---|
| 01 | Tablero con la barra en Legacy | [01](plans/informes-h5/capturas-crm/escritorio-01-tablero-barra-legacy.png) | [01](plans/informes-h5/capturas-crm/movil-01-tablero-barra-legacy.png) |
| 02 | Detalle de una versión aprobada | [02](plans/informes-h5/capturas-crm/escritorio-02-detalle-aprobada.png) | [02](plans/informes-h5/capturas-crm/movil-02-detalle-aprobada.png) |
| 03–04 | Vista previa de publicar, y con todo reconocido | [03](plans/informes-h5/capturas-crm/escritorio-03-publicar-vista-previa.png) · [04](plans/informes-h5/capturas-crm/escritorio-04-publicar-reconocido.png) | [03](plans/informes-h5/capturas-crm/movil-03-publicar-vista-previa.png) · [04](plans/informes-h5/capturas-crm/movil-04-publicar-reconocido.png) |
| 05 | Publicada en canario | [05](plans/informes-h5/capturas-crm/escritorio-05-publicada-en-canario.png) | [05](plans/informes-h5/capturas-crm/movil-05-publicada-en-canario.png) |
| 06 | Comparar con la huella alterada | [06](plans/informes-h5/capturas-crm/escritorio-06-comparar-huella-alterada.png) | [06](plans/informes-h5/capturas-crm/movil-06-comparar-huella-alterada.png) |
| 07 | Publicar bloqueada | [07](plans/informes-h5/capturas-crm/escritorio-07-publicar-bloqueada.png) | [07](plans/informes-h5/capturas-crm/movil-07-publicar-bloqueada.png) |
| 08–09 | Barra y detalle en canario | [08](plans/informes-h5/capturas-crm/escritorio-08-tablero-barra-canario.png) · [09](plans/informes-h5/capturas-crm/escritorio-09-detalle-canario.png) | [08](plans/informes-h5/capturas-crm/movil-08-tablero-barra-canario.png) · [09](plans/informes-h5/capturas-crm/movil-09-detalle-canario.png) |
| 10 | Motivo de revertir | [10](plans/informes-h5/capturas-crm/escritorio-10-revertir-motivo.png) | [10](plans/informes-h5/capturas-crm/movil-10-revertir-motivo.png) |
| 11 | Historial | [11](plans/informes-h5/capturas-crm/escritorio-11-historial.png) | [11](plans/informes-h5/capturas-crm/movil-11-historial.png) |
| 12 | Barra con deriva | [12](plans/informes-h5/capturas-crm/escritorio-12-barra-deriva.png) | [12](plans/informes-h5/capturas-crm/movil-12-barra-deriva.png) |
| 13 | Motivo de Legacy | [13](plans/informes-h5/capturas-crm/escritorio-13-legacy-motivo.png) | [13](plans/informes-h5/capturas-crm/movil-13-legacy-motivo.png) |
| 14–15 | Evidencia que pasa, y con el 429 | [14](plans/informes-h5/capturas-crm/escritorio-14-evidencia-pasa.png) · [15](plans/informes-h5/capturas-crm/escritorio-15-evidencia-429.png) | [14](plans/informes-h5/capturas-crm/movil-14-evidencia-pasa.png) · [15](plans/informes-h5/capturas-crm/movil-15-evidencia-429.png) |
| 16 | Promovida | [16](plans/informes-h5/capturas-crm/escritorio-16-promovida.png) | [16](plans/informes-h5/capturas-crm/movil-16-promovida.png) |
| 17 | Administrador, sin botones y con el motivo | [17](plans/informes-h5/capturas-crm/escritorio-17-administrador.png) | [17](plans/informes-h5/capturas-crm/movil-17-administrador.png) |

### Accesibilidad y 390 px

- El foco no sale de los diálogos de publicar y de Legacy con Tab y Mayús+Tab, y Escape cierra y devuelve el foco al botón que abrió (Chrome con la API simulada).
- Mientras llega la vista previa, el diálogo lo dice en `role=status`; cada requisito lleva icono, palabra y un texto oculto «se cumple / no se cumple»; ningún estado es solo color.
- Los avisos de datos personales de la nota y del motivo van enlazados al campo con `aria-describedby`.
- Los espacios entre elementos en línea se escriben con `&ngsp;`: Angular quitaba los nodos que solo tienen espacios y «Sello correcto(cabeza n.º 1)» salía pegado, también para el lector de pantalla (D-F8).
- A 390 px, la columna de la puerta de la evidencia se pliega bajo el valor, y ni el tablero, ni los dos diálogos, ni el historial, ni comparar, ni la deriva se desplazan en horizontal. El pie de los dos diálogos nuevos deja libre la esquina del asistente flotante del shell (`padding-right: 68px`); el de `ib-reason-dialog`, compartido, no se tocó (D-F9).

### Atributos `data-*` para el E2E

Los de A11 y los de apoyo (detalle en `informe-front-integracion.md` §4):
- **Panel:** `[data-publicar]`, `[data-promover]` y `[data-revertir]` (solo si la acción está permitida); `[data-motivo-accion="publish|promote|rollback"]`; `[data-ayuda-publicar]`; `[data-publicaciones]` > `[data-publicacion-entrada="<uuid>"]` con `data-accion` y `[data-publicacion-puerta="required|waived"]`. Con un backend de F, `[data-publicar-version]`, deshabilitado.
- **Publicar:** `[data-publicacion-dialogo]` con `data-publicacion-estado="cargando|publicable|bloqueada|error"`; `[data-publicacion-requisito="<id>"][data-ok]`; `[data-publicacion-param="layer.key"][data-publicacion-clase="must_match|matches|no_effect"]`; `[data-publicacion-sin-efecto="<id>"]` y su fila, con `data-falta`; `[data-publicacion-enviar]`, `[data-publicacion-falta]`, `[data-publicacion-cambio]`, `[data-publicacion-error]`, `[data-publicacion-recalcular]`, `[data-publicacion-otros-bloqueos] [data-bloqueo]` y `[data-publicacion-nota]`.
- **Evidencia:** `[data-evidencia]` con `data-evidencia-estado="cargando|pasa|no-pasa|error"` y `data-evidencia-pasa`; `[data-evidencia-medida="<id>"]` con `data-ok` (las informativas no lo llevan); `[data-evidencia-veredicto]`, `[data-evidencia-actualizar]`, `[data-evidencia-espera]`, `[data-evidencia-enviar]`, `[data-evidencia-falta]`, `[data-evidencia-cambio]` y `[data-evidencia-error]`.
- **Revertir:** `[data-revertir-dialogo]` (aviso `#vp-revertir-datos`).
- **Barra:** `[data-estado-publicacion]` con `data-estado-publicacion-sello="applied|legacy|off|fallback"` (o `ilegible`); `[data-estado-publicacion-vigente][data-vigente="legacy|all|split"]`; `[data-estado-publicacion-llave][data-llave]`; `[data-estado-publicacion-deriva][data-deriva=N]`; `[data-legacy]` → `[data-legacy-dialogo]` (aviso `#pb-legacy-datos`); `[data-publicaciones-abrir]` → `[data-publicaciones-dialogo]`; `[data-estado-publicacion-releer]` y `[data-estado-publicacion-aviso]`.
- **Comparar:** `[data-integridad-lado="from|to"]` y `[data-integridad-nota]`.

El host de `ib-dialog` mide 0×0 (su contenido es `position: fixed`): para esperar a que un diálogo se cierre, se comprueba que el selector ya no está en el DOM, no `waitForSelector({hidden: true})`.

### Decisiones fuera del contrato

Congeladas en la Enmienda 2 (§E2.3) del contrato:
1. **D-F1.** Sin la llave o con el servidor negando, no hay botón y el motivo se lee en texto visible, solo en el estado en que la acción aplicaría.
2. **D-F2.** Con un backend del Hito 5, «Publicar» sale de cada versión y no de la cabecera; con uno de F, sigue el de la cabecera, deshabilitado. Si siguieran los dos, `[data-publicar]` sería ambiguo para el E2E.
3. **D-F3.** «Volver a Legacy» y el historial viven en la barra: son de la cadena, no de una versión. La barra solo se pinta en la fase `ready`.
4. **D-F4.** El detalle abierto se relee cuando se mueve la cabeza de la cadena (una versión solo cambia de estado con una entrada nueva), y una sola vez tras escribir.
5. **D-F5.** Lo reconocido se borra con cualquier `previewHash` nuevo, como al aprobar en F; tras `no_effect_not_acknowledged` se marca lo que faltaba.
6. **D-F6.** Descriptor del CRM para `governance.studioPublication`: el backend no la mete en el catálogo de lecturas (D-5), y sin descriptor el «Motor ahora» no la enseñaría. Si el catálogo la declara algún día, manda el suyo.
7. **D-F7.** Aviso de datos personales en la nota de publicar y de promover: va a `reason`, en una cadena que no se borra.
8. **D-F8.** Espacios entre elementos en línea con `&ngsp;`.
9. **D-F9.** Margen en el pie de los dos diálogos nuevos a 390 px por el asistente flotante del shell; `ib-reason-dialog`, compartido, no se tocó.

### Pruebas y medidas

| Comprobación | Resultado |
|---|---|
| Specs del módulo (`ng test --include='src/app/modules/ultron-studio/*.spec.ts'`) | 801 con la capa de datos (`9b70337`) → 871/871 en `33821e4` (70 nuevas) → **878/878 en `24a691d`** (D-1 y la paridad), 54 ficheros |
| Suite completa (`ng test --watch=false`) | 2920 pruebas con **16 fallos idénticos nombre a nombre** a los de `9b70337` limpio, el mismo día (moderación ×14, `financial-realtime.integration` y `receivables-pagination`); ninguno nuevo |
| `tsc` de la aplicación y de los specs | Limpio |
| `check:literals` | 321 ficheros en `24a691d`, correcto |
| Build de producción | Exit 0, los mismos 29 avisos de presupuesto de siempre, ninguno de las hojas nuevas |
| Chunk `ultron-studio-page` | De 645,36 kB en bruto y 134,72 kB transferidos (`9b70337`) a 719,05 kB y 148,30 kB (`33821e4`): +73,7 kB y +13,6 kB. El inicial no cambia (817,83 kB). 0 `innerHTML` y `bypassSecurityTrust` en los ficheros nuevos |
| `ultron-studio.scss` | 0 bytes de diff (sha256 `fc558002…`) |
| Catálogo de permisos | `backend-permission-catalog.ts` idéntico al que exporta el backend en el paso 3 (sha256 `aeb9699c…`, 96 permisos, ninguna llave nueva); antes de la fusión del 31-10-2026 |
| Paridad con `backend-publication.testing.ts` | 6/6, sin ningún código de error desconocido desde `24a691d` |
| Mutaciones | **18, todas tumbadas** (entre ellas: enviar sin reconocer todo, enviar `contentHash` en vez de `previewHash`, ignorar la cuenta atrás del 429, el botón sin mirar `actions`, Legacy sin `ultron.rollback`, la deriva que no manda sobre el sello, el motivo de la cadena como `[innerHTML]`, la puerta dispensada sin rojo y la lectura que dice «correcta» de lo no comprobado). Cada restauración, idéntica (`cmp`) |
| Chrome real con la API simulada (no es el E2E con la pila real) | **71/71** sobre `33821e4`, a 1440 y 390 px, sin errores de JavaScript; 34 capturas |

**Tras la fusión** (el reviewer, 31-10-2026, sobre `9fe1817`): módulo **878/878** (54 ficheros); `tsc` limpio; `check:literals`, 332 ficheros, correcto; suite completa con 3131 pruebas y **16 rojas**, las de la línea base (moderación ×14, `financial-realtime.integration` y `receivables-pagination`), ninguna nueva; el catálogo de permisos, idéntico al que exporta el backend fusionado (101 permisos); y `ultron-studio.scss` sin cambios. [PENDIENTE: build de producción del CRM y presupuestos de chunk sobre el commit final (lead; el reviewer no lo ejecutó).]

### E2E con la pila real (25-10-2026 y 31-10-2026)

**Pasada de cierre del lead (31-10-2026, 21:26–21:30 UTC) sobre el backend `cf59cef` y el CRM final `9fe1817`: 408/408** (F 40, G 25, H4 27, turno 30, `h5-governance` 276 y cierre 10, en 257 s): sin 5xx en 465 peticiones del CRM, `laravel.log` sin ERROR, colas vacías, 0 violaciones de los simuladores de OpenAI y de n8n, la valla de red vacía y ninguno de los 4 secretos de la ejecución en 19 registros ni en 9 tablas. Es la mejor pasada registrada del arnés.

**Antes, el 25-10-2026: 404/408** en la ejecución definitiva sobre el backend `2bc053b` y el CRM `e7ba41b`, con turnos reales y Chrome con login real (`Iron-Body_Front/e2e/ultron-studio/run.sh`; `informe-tester-e2e.md`). Del lado del CRM: publicar, promover y revertir desde el detalle, Legacy desde la barra, el Administrador sin botones y con el motivo, la barra en cada estado y 116 respuestas del CRM sin el id del canario ni firmas. Su único hallazgo del CRM, **C-1** (con la puerta dispensada, el diálogo lo decía dos veces), está corregido en `33821e4`: con ese CRM, 405/408, y los tres rojos restantes son de B-1 del backend (= M-1, corregido en `d24c512`). El CRM nuevo con el backend anterior da 131/131, igual que la línea base. La regresión `f-versions.cjs` se adaptó al modo del Hito 5 (el aviso del motor, la revisora sin botón y el 422 con el cuerpo vacío), con los ids de F intactos. Detalle en `ULTRON_STUDIO_FINAL_REPORT.md` («Hito 5: E2E con la pila real»).

### Pendiente y no verificado

- **I-1 del reviewer** (importante, antes de la fase 2): la sesión reutiliza la `clientRequestId` de una escritura de la cadena también después de un éxito (`studio-session.ts`). Un segundo «Volver a Legacy» con el mismo motivo en la misma pestaña recibe la entrada vieja: si entretanto otra persona publicó, la salida no se ejecuta y la barra dice «Ya estaba en Legacy con esta misma petición». Corrección propuesta: olvidar la clave tras un 2xx legible o un 4xx definitivo, y conservarla solo para reintentar sin red, tras un 5xx, un 429 o un 2xx ilegible. Lo está corrigiendo `frontend` (31-10-2026), previsiblemente antes del build de la fase 1. **Corregido** en el CRM `ab78a6a` (31-10-2026, `frontend`): la clave de idempotencia se olvida tras cualquier respuesta definitiva (2xx legible, 4xx) y se conserva solo tras 429, 5xx, sin red o 2xx ilegible; 31 specs nuevas que fallaban sin la corrección; `ab78a6a` es el build desplegado en la fase 1.
- **Teclado, lectores de pantalla y 390 px de los diálogos nuevos con la pila real:** el E2E solo usó Escape para cerrar dos diálogos; lo demás se probó con la API simulada.
- **`ib-reason-dialog` y el asistente flotante a 390 px** (D-F9): ya ocurría; no se comprobó si el asistente intercepta toques.
- **Carga diferida (`@defer`) de los diálogos:** no se hizo; +13,6 kB transferidos en un chunk diferido que usan pocas personas no la justifican, y complicaría el foco al abrir.
- **No verificado:** Safari, Firefox, Windows y el CRM del Hito 5 sobre la pila de producción.

## Hito 6: las integraciones comerciales en el CRM

**Estado:** implementado en la rama `feat/ultron-studio-h6` del CRM, **no desplegado**. La capa de datos está terminada (`0a83d74` → `ede716b`, `plans/informes-h6/informe-front-datos.md`). La página «Integraciones» está **terminada y verificada en `f38699d`**: el lead la confirmó como WIP («en curso, sin verificar») cuando se cortó la sesión de `frontend`, y después `frontend` la verificó sin cambiarla (`plans/informes-h6/informe-front-integracion.md`, confirmado en el backend en `7d2c6d8`). Producción sirve `ab78a6a` (Hito 5). El arnés E2E del Hito 6 (`037a2cd` → `e8386dc`) es del tester (ver «E2E con la pila real», abajo).

| Commit | Qué |
|---|---|
| `0a83d74` → `ede716b` | Capa de datos: tipos, lectores, textos, el servicio de las 18 rutas, el fixture con el formato del exportador, 101 specs nuevas y los 4 permisos |
| `037a2cd` → `e8386dc` | Infraestructura del E2E del Hito 6 (tester) |
| `f38699d` | La página, la sesión, los ocho componentes, sus hojas y sus specs |

Todo queda dentro de `src/app/modules/ultron-studio/`, salvo una entrada en `src/app/app.routes.ts`, los 4 permisos en `permissions.enum.ts`, el catálogo regenerado (`backend-permission-catalog.ts`, idéntico al `permissions:export` del backend), `access-control.service.ts` y su spec de contrato. **`ultron-studio.scss` y `studio-inspector.scss` no cambian** (su sha256 lo fija una spec) y no hay dependencias nuevas.

### Página hija y entradas

- **`marketing/ultron-studio/integraciones`**: ruta hija diferida, en su propio chunk (`studio-integrations-page`, 162,46 kB; 38,03 kB transferidos), con `AuthGuard` y `ultron.view`, tras `ultronStudioPreviewEnabled`, como el laboratorio. `StudioIntegrationsSession` se provee en la página.
- **Enlace «Integraciones»** junto a «Laboratorio» en la barra del tablero (solo la plantilla de `ultron-studio-page.ts`).
- **Siempre a la vista:** «Registro del Studio: no cambia lo que ULTRON dice o hace».
- **Con un backend del Hito 5** (404 sin código en `integrations/*`): «El servidor todavía no tiene integraciones (Hito 6)», y el resto del Studio no cambia. En el libro, filtrar por uno de los 3 sujetos nuevos contra ese backend da un 422, que el panel dice como «este filtro todavía no existe en el servidor», sin parecer un fallo (D-F12).

### Componentes

Cada componente es standalone y `OnPush`, con su hoja y su spec.

| Pieza | Selector | Qué hace |
|---|---|---|
| `studio-integrations-page.ts` | `app-ultron-studio-integrations` | Fases (cargando, `unavailable`, sin permiso, error), cabecera y pestañas con teclado y `?pestana=` |
| `studio-integrations-session.ts` | — | Lecturas por pestaña (una vez cada una), bloqueos por llave y bandera, escrituras con la regla I-1 del H5 y relecturas |
| `studio-integrations-status.ts` | `us-integrations-status` | Las banderas del proceso web, cada área con su clasificación en texto y su evidencia, y lo que puede hacer la cuenta |
| `studio-meta-campaigns.ts` | `us-meta-campaigns` | El espejo y sus cuentas, los filtros, la lista de dos carriles, las acciones, el historial y la configuración de Meta |
| `studio-campaign-link-dialog.ts` | `us-campaign-link-dialog` | Asociar o sustituir; `campaign_already_linked` y `campaign_link_moved` |
| `studio-canary-numbers.ts` | `us-canary-numbers` | La coherencia, los topes, la lista enmascarada, las acciones según `actions`, el motivo y la versión esperada |
| `studio-canary-register-dialog.ts` | `us-canary-register-dialog` | El formulario del registro: el teléfono solo vive aquí |
| `studio-twilio-panel.ts` | `us-twilio-panel` | OTP, coste, credenciales, el aviso `usage-trigger`, las llamadas, la sonda con su 429 y la configuración de Twilio |
| `studio-voice-panel.ts` | `us-voice-panel` | El estado, las piezas que faltan, la política y los tres niveles. Ningún botón |
| `studio-config-drift.ts` | `us-config-drift` | La configuración autorizada: estado, huellas, deriva y «Reconocer como autorizada» |

**Ficheros que cambian:** `studio-audit-panel.ts` (los 3 sujetos nuevos en el filtro), `ultron-studio-page.ts` (solo la plantilla del enlace), la capa de datos (Q-6 y O-2) y el fixture del backend, regenerado.

**Tamaño de las hojas** (bytes de fuente; el límite es 8000): la mayor, `studio-integrations-kit.scss`, 4826 (3109 comprimida), y `studio-integrations-page.scss`, 4824 (3769 comprimida); las otras ocho, entre 914 y 1856. Ninguna da aviso de presupuesto. La página carga también `studio-lab-kit.scss` y declara los 24 tokens `--us-*`, comprobados contra `ultron-studio.scss` por una spec (D-F4).

### La capa de datos (`ede716b`)

- **Estricto** en la estructura obligatoria, los conjuntos cerrados del contrato y los formatos de privacidad, y en las invariantes de las que depende lo que se ofrece o se afirma (por ejemplo, `in_sync` ⇒ exactamente un WhatsApp activo; una acción permitida ⇔ sin motivo; `ELEVENLABS_VERIFIED` ⇒ una verificación `credentials_ok`). **Tolerante** con las claves desconocidas y con un elemento ilegible de una lista (se descarta y se cuenta). **Suave** con la evidencia, los hechos de la sonda, el coste y el webhook: `null` si no se puede creer, nunca un valor inventado.
- **Privacidad:** el motivo de un fallo en `masked`, `ref`, `fp` o `last4` no repite el valor; las claves que no son del contrato nunca se copian; dentro de una credencial solo se aceptan `present`, `source`, `ref`, `fp` y `last4`. Si un proveedor de la configuración no cumple esas reglas, no se enseña y no se puede reconocer a ciegas.
- **Cada escritura** construye su cuerpo campo a campo, y una respuesta que no es la de esa petición sale como ilegible. **El teléfono solo viaja en `POST integrations/canaries`.** Un uuid de ruta que no está en minúsculas no se pide.
- **Hallazgo en el código del H5, corregido aquí:** el panel del libro solo aceptaba 4 sujetos y habría descartado como ilegibles los eventos del Hito 6. El CRM del H6 los pinta en genérico; **el CRM del H5 (`ab78a6a`) sigue descartándolos** (`ULTRON_STUDIO_ROLLBACK.md`, «Hito 6», nivel 3).

### Lo que se ve

- **Estado:** las banderas en texto con la nota del servidor; cada área en el orden del servidor, con su nombre, su «cable», la clasificación en texto, el código de ElevenLabs y el motivo, y la evidencia en pares etiqueta / valor. Un área `available: false` dice «No se pudo leer en el servidor», sin valores.
- **Campañas de Meta:** cada campaña con dos carriles que nunca se mezclan, en columnas separadas: «En Meta…» (borde cian: «Activa · hace 42 min», «Posiblemente eliminada», «Dato viejo») y «En ULTRON Studio…» (borde violeta). Asociar (a la línea base o a una versión `APPROVED`, `CANARY` o `PUBLISHED`), sustituir y desactivar con motivo; el historial paginado. Texto obligatorio bajo el título: «La asociación queda registrada en el Studio. No cambia nada en Meta ni en lo que ULTRON dice o hace.»
- **Números canarios:** la coherencia siempre a la vista con el texto obligatorio («Este registro no cambia a quién responde ULTRON: el motor sigue atendiendo solo la conversación canaria configurada en el servidor.»); la lista enmascarada con la etiqueta del servidor; «Es el teléfono de la conversación canaria del motor» si lo es; la versión esperada frente a la que rige. Registrar: canal, teléfono (`type=tel`, `autocomplete=off`), alias por función (enviado en NFC, O-3) con «Usa una función, no el nombre de una persona (p. ej., «Móvil de pruebas 1»).», la casilla del consentimiento con su texto literal y la vista previa solo enmascarada. Al registrarse, el diálogo se cierra con el teléfono: no queda en la pantalla. Autorizar y activar son un clic (el contrato no les da cuerpo, D-F9); desactivar y revocar llevan `ib-reason-dialog` con aviso de datos personales, y revocar uno de voz dice que su cifrado se borra.
- **Twilio:** OTP, el coste del día en grande, credenciales con `ref` y huellas cortas y de qué variable salen, el aviso `usage-trigger` tal como lo describe el servidor y las llamadas en recuentos. La comprobación de solo lectura con su texto obligatorio («Solo lee: no envía mensajes ni hace llamadas. Puede tardar unos segundos.»), el botón solo con `can.integrationsManage` y `flags.twilioProbeEnabled`, el enfriamiento en llano y, tras un 429, «Comprobar (en N s)» deshabilitado. La última comprobación en llano («3: 1 de error, 2 de aviso; códigos 60200 (3)»).
- **Voz:** `ELEVENLABS_NOT_CONFIGURED` en grande, con «En este hito no se hace ninguna llamada.»; las piezas que faltan por su nombre; la política («No permite llamar») con sus motivos numerados en orden; los tres niveles. **Ni un botón.**
- **Configuración autorizada**, en las pestañas de Meta, Twilio y Voz (D-F10): «Aquí no se ven ni se editan secretos: solo huellas. Las credenciales se cambian en el servidor.»; la huella vigente; quién autorizó, cuándo y con qué nota; lo observado por el sistema; la deriva clave a clave. Un 409 `config_changed` enseña lo nuevo y la siguiente confirmación envía la huella nueva.
- **Botones:** solo salen de `can`, `flags` y `actions` del servidor; si no hay botón, el motivo se lee en texto (`[data-motivo-accion]`). La bandera de escrituras apagada se dice una vez arriba, no en cada número (D-F8).

### Accesibilidad y 390 px

Pestañas con teclado (flechas, Inicio y Fin), foco atrapado en los cuatro diálogos (Tab y Mayús+Tab) y devuelto al botón que los abrió con Escape, `role=alert` en los errores, insignias con texto y no solo color, y tarjetas en lugar de tablas a 390 px. La página deja 96–104 px abajo y los pies de sus diálogos dejan su esquina al asistente flotante (D-F16). **Dos cosas visibles:**
- **D-F14:** a 390 px, el quinto icono de la barra del tablero («Integraciones») añade una fila a la cabecera (captura `movil-16`). Arreglarlo exige tocar `ultron-studio.scss`, que no se toca. [PENDIENTE: decisión del lead (Q-3 de `informe-front-integracion.md`); `frontend` propone dejarlo así.]
- **D-F5:** la regla global del CRM `p, small { color: … !important }` pisaba el color de los errores, las alertas y los textos obligatorios; los textos con color propio van ahora en `div` y `span`, comprobado en Chrome por el color calculado. **El laboratorio tiene el mismo problema latente** (`<p class="lab-alert">`), y también el degradado perdido de su botón principal con el ratón encima (D-F6); los dos quedan fuera (Q-5).

### Atributos `data-*` para el E2E

Los enumera `informe-front-integracion.md` §4, pieza a pieza: la página (`[data-integraciones-pagina][data-integraciones-fase]`, `[data-integraciones-pestana]`), el estado (`[data-proveedor][data-clasificacion]`, `[data-cable]`), las campañas (`[data-campana]`, `[data-campana-meta]`, `[data-campana-ultron][data-asociada]`), los canarios (`[data-coherencia][data-veredicto]`, `[data-canario][data-estado][data-canal]`, `[data-canario-accion]`), el registro (`[data-registrar-*]`), Twilio (`[data-sonda]`, `[data-sonda-lanzar]`, `[data-sonda-ultima][data-resultado]`), la voz (`[data-voz-estado]`, `[data-voz-politica][data-permitida]`) y la configuración (`[data-config][data-config-estado]`, `[data-config-reconocer]`). Como en el H5, `waitForSelector({hidden:true})` no sirve con `ib-dialog` (su host mide 0×0): se comprueba que el selector ya no está en el DOM.

### Pruebas y medidas

Sobre `f38699d`, con el árbol limpio (`informe-front-integracion.md` §5):

| Comprobación | Resultado |
|---|---|
| Módulo del Studio y modelos (`ng test` con `ultron-studio/**` y `models/**`) | **70 ficheros, 1096/1096** (antes, 60 y 1005 en `ede716b`): 80 pruebas en los 10 specs nuevos y 11 en specs ampliados |
| `tsc` de la aplicación y de las specs | Salida 0 y 0 |
| `check:literals` | 350 ficheros, literales correctos |
| `npm run build:prod` | Salida 0; **36 avisos de presupuesto, nombre a nombre los mismos que en `ede716b`**, ninguno de una hoja nueva; el único del Studio sigue siendo el de `ultron-studio.scss`, que ya existía |
| Mutaciones | **12/12 tumbadas** (botones sin `actions`, la clave I-1 siempre gastada, el alias sin NFC, el backend del H5 como error, el alias como `[innerHTML]`, la sonda sin respetar el 429, el reconocimiento con la huella vieja tras `config_changed`, el texto obligatorio alterado…) |
| Paridad con el export de S | `backend-integrations.testing.ts` es **byte a byte** el export de `4e6f5fe` (sha256 `23b4de10…`); los 16 ejemplos reales se leen con los lectores estrictos |
| Chrome con la API simulada (no es el E2E con la pila real), a 1440 y 390 px | **93/93**, sin errores de JavaScript (`capturas-crm/chrome-resultados.json`): la fase 1 de producción sin ninguna escritura; asociar con una sola petición; el teléfono completo nunca en pantalla; cuatro ojos sobre el número propio; el Administrador sin «Autorizar»; la sonda (201 y después 429 con cuenta atrás); reconocer tras `config_changed`; el backend del H5; sin desplazamiento horizontal |

**Capturas** (en `plans/informes-h6/capturas-crm/`, `escritorio-NN-…` a 1440 px y `movil-NN-…` a 390 px; 32 en total, confirmadas en `d5da123`):

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

### Decisiones fuera del contrato

D-F1 a D-F16 de `informe-front-integracion.md` §3 (y D-F1 a D-F11 de la capa de datos, `informe-front-datos.md` §8). Las que cambian lo que se ve: el fixture del servidor byte a byte y los ejemplos escritos a mano aparte (D-F1); `activeWhatsapp: null` aceptado solo con `unreadable` y `no_engine_canary`, para que la pestaña no quede ilegible justo cuando la coherencia falla (D-F2); O-2 en el cliente (D-F3); la configuración autorizada en las tres pestañas (D-F10); activar sin motivo (D-F9); y la fila de más en la cabecera a 390 px (D-F14).

### E2E con la pila real

[PENDIENTE: los 15 escenarios de `specs/h6-integrations.cjs` con la página del CRM (`f38699d` o el commit final), entre ellos registrar como Administrador → autorizar y activar como Super Admin → `in_sync`, un segundo WhatsApp activo → `registry_has_extra_active`, revocar, asociar → sustituir → desactivar, la sonda con `reused` y su 429, reconocer frente a un cambio de configuración, la voz sin botones, la página con un backend del H5 y el teclado en los cuatro diálogos.] Ni las specs ni la prueba en Chrome usan el backend real: usan su export y un simulador con sus formas.

### Pendiente y no verificado

- **El fixture del CRM es el export de `4e6f5fe`, anterior a la tanda final de S** (`3e1ee20`). En el backend actual, `phoneNotAllowedForChannel` es el texto general («Ese número no sirve para este canal.»), hay claves nuevas (`phoneNotMobileForWhatsapp` y `contactData`) y el ejemplo `unreadable` lleva `activeWhatsapp: 1` (comprobado el 01-11-2026 sobre `e3a0fdd` y en la copia del CRM). La paridad del CRM tolera los dos textos de O-2. **Hecho (lead, 01-11-2026, CRM `3bac1e2`):** `backend-integrations.testing.ts` reexportado desde el backend final (`ultron-studio:export-fixtures` sobre `e3a0fdd`+docs) y copiado byte a byte; `permissions:export` idéntico al catálogo del CRM (105 llaves); specs de paridad y de canarios alineadas (texto general de O-2, `phoneNotMobileForWhatsapp`, `contactData` y `activeWhatsapp: 1` con coherencia ilegible, tolerando `null`); módulo + `models` 70/70 ficheros en verde. La Q-1 de `informe-front-integracion.md` («el servidor todavía manda la pista siempre») es cierta para `4e6f5fe` y ya no para `3e1ee20`.
- **La suite completa del CRM** fuera de `ultron-studio` y `models`: no se corrió esta vez.
- **La segunda pasada de `security` sobre la página** (contrato, «Orden», paso 5): **Hecho (01-11-2026, `security`, apartado «Segunda pasada» de `plans/informes-h6/informe-security.md`, confirmado en `8c3eb7e`): sin bloqueantes, críticas, altas, medias ni bajas; dos notas: N-6 (el lector de las bases de Twilio descarta un host IPv6 o con guion bajo y deja el panel sin leer; hoy no ocurre con las bases oficiales) y N-7 (fixture del CRM desfasado, cerrada en `3bac1e2`). Cero sumideros de HTML en los 22 ficheros, ninguna URL con datos del servidor, teléfono solo enmascarado y solo en la ruta 49, nada en almacenamiento ni consola, botones solo de `can`/`flags`/`actions`, foco atrapado y sin autofoco en el teléfono; specs del módulo 1074 + 60 en verde.
- **Los avisos locales del formulario** son anteriores a AS-1 (`3e1ee20`): `checkCanaryAlias` solo mira la longitud, los caracteres, `\d{4,}` y `@`, y `checkIntegrationReason` solo la longitud (`studio-integrations-parse.ts:1414-1426` del CRM, leído el 01-11-2026). Un alias o un motivo con un teléfono escrito en grupos o con un correo pasa el aviso local, y el servidor lo rechaza con 422 junto al campo, sin perder lo escrito. [PENDIENTE: alinear los avisos locales con AS-1 (dato de contacto en alias y motivos) es un ajuste de usabilidad sin impacto de seguridad (el servidor ya rechaza con 422 y el CRM lo enseña junto al campo); va a la lista del Hito 7 para `frontend`.]
- **Lectores de pantalla reales;** la semántica se comprobó en las specs y el teclado, en Chrome.
- **El pie de `ib-reason-dialog` a 390 px frente al asistente flotante:** es compartido y no se tocó, como en el H5.
- **No verificado:** Safari, Firefox, Windows y el CRM del Hito 6 sobre la pila de producción.

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

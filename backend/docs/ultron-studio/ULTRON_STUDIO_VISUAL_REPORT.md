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

## Revisión final independiente (`reviewer`)

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

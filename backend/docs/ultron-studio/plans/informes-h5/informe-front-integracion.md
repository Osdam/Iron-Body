# Hito 5 · paso 4 · informe del frontend: integración de la publicación en el CRM (A11)

**Fecha:** 25-10-2026, madrugada (Bogotá).
**Árbol:** `_worktrees/thermal-receipts/Iron-Body_Front`, rama `feat/ultron-studio-h5`, desde `9b70337` (limpio).
**Commits locales, sin push:** `e7ba41b` (la integración), `33821e4` (ajustes tras leer `informe-api.md`) y `24a691d` (D-1 y la paridad con la exportación oficial, tras tu visto bueno). Árbol limpio al cerrar.
**Contrato:** `ULTRON_STUDIO_H5_CONTRACT.md` §A1, §A2, §A5, §A6, §A7, §A10.1, §A10.3, §A11 y Enmienda 1. Capa de datos: `informe-front-datos.md` (paso 1d).
**Paso 3 (API):** se construyó en paralelo y ya está confirmado en el backend: `2bc053b` en `feat/ultron-studio-h5` (más `5df1b1d`, m-3, y `61048ff`, docs). Mientras se construía trabajé contra el contrato y leí su trabajo en vuelo, solo lectura. Al final leí `informe-api.md` y ajusté lo que tocaba (§7).
**Fixtures oficiales:** `backend-publication.testing.ts` copiado byte a byte al CRM en `24a691d`, con su spec de paridad en el repo (§7).
**Producción:** nada tocado y sin despliegue. `ultron-studio.scss` intacto (0 bytes de diff; un spec fija su sha256). No toqué `e2e/`.

---

## 0. En una pantalla

**Qué se ve ahora con un backend del Hito 5** (`engine.readsVersions: true`):
- **La barra de la publicación**, bajo el aviso del motor. Dice qué rige (en el canario, en todas o Legacy), la llave del motor y si el sello está bien o hay deriva. Desde ella se abren el historial y «Volver a Legacy».
- **El detalle de cada versión** ofrece «Publicar en canario», «Promover a todas» y «Revertir» según `actions` del servidor. Si no se puede, no hay botón y el motivo se lee en el panel.
- **Los dos diálogos nuevos**: publicar (con la vista previa) y promover (con la evidencia del canario).
- **El historial de la cadena**, paginado.
- **La insignia «Huella alterada»** en comparar.
- **La lectura del motor** `governance.studioPublication`, con su texto.
- **«Volver a preguntar al servidor»**: ante `validation_stale` avisa en informativo, no como error.

**Con un backend anterior** (`readsVersions: false`) todo sigue como en F/G: «Publicar» deshabilitado con el motivo del servidor, sin barra y con el aviso «El motor de ULTRON no lee nada de esto».

| Verificación | Resultado |
|---|---|
| `tsc` de la app y de los specs | limpio |
| `check:literals` | 320 ficheros, correcto |
| Specs del módulo | **871/871** en `33821e4` (801 de antes y 70 nuevas); **878/878** en `24a691d` (+7: D-1 y paridad) |
| Suite completa (`ng test`) | 2920 pruebas, **16 fallos, los mismos 16 nombre a nombre** que en `9b70337` limpio, el mismo día |
| Build de producción | exit 0, los mismos 29 avisos de presupuesto, **0 de hojas nuevas** |
| Mutaciones | **18, todas tumbadas**; cada restauración idéntica (`cmp`) y la huella del árbol, igual antes y después |
| Chrome con la API simulada, a 1440 y 390 px | **71/71** sobre `33821e4`, sin errores de JavaScript; 34 capturas |
| Paridad con `backend-publication.testing.ts` | 6/6; primero en una copia temporal, ya como spec del repo (`24a691d`) |

---

## 1. Ficheros

Todos dentro de `src/app/modules/ultron-studio/`, salvo uno: el comentario de `src/app/models/permissions.enum.ts`.

| Fichero | Qué |
|---|---|
| `studio-publication-text.ts` (nuevo, puro) | Texto obligatorio de A11 y nombres de llaves, acciones, puertas, clases y veredictos del laboratorio. Las filas de requisitos (el `message` del servidor va por delante), de evidencia (pasa o no lo dice `failing`), el sello (la deriva manda), las partes, la deriva en llano y los bloqueos sin fila propia |
| `studio-publication-dialog.ts` + `.scss` (nuevos) | `us-publication-dialog`: vista previa y envío con `previewHash` |
| `studio-canary-evidence.ts` + `.scss` (nuevos) | `us-canary-evidence`: evidencia de A6, cuenta atrás del 429 y envío con `evidenceHash` |
| `studio-publication-history.ts` + `.scss` (nuevos) | `us-publication-history`: la cadena paginada o una lista fija (el detalle de una versión) |
| `studio-publication-bar.ts` + `.scss` (nuevos) | `us-publication-bar`: la barra de estado, Legacy con motivo y el historial en un diálogo |
| `studio-versions-panel.ts` + `.scss` | Botones, motivos, texto obligatorio, entradas de la cadena, diálogos (publicar, promover, revertir) y colores de los cuatro estados nuevos |
| `studio-compare-view.ts` + `.scss` | La insignia «Huella alterada» por lado y su nota (el diff no cambia) |
| `studio-engine-text.ts` | `engineScopeText`, `studioPublicationReadingText`, el caso en `textoDeExtra` y el descriptor del CRM para la lectura nueva (§3, D-F6) |
| `ultron-studio-page.ts` | **Solo plantilla** (más `imports` y el comentario de cabecera): barra, aviso del motor según el backend, «Publicar» de la cabecera solo con backend F y botón «Volver a preguntar» deshabilitado al guardar. **Una línea de lógica** en `releerServidor()`: `validation_stale` va como aviso informativo |
| `permissions.enum.ts` | Solo comentarios de `ULTRON_PUBLISH` y `ULTRON_ROLLBACK`. La ayuda que ven los Roles la sirve el backend, y el paso 3 ya lleva ahí el texto obligatorio |
| Specs nuevos | `studio-publication-text` (16), `studio-publication-dialog` (12), `studio-canary-evidence` (7), `studio-publication-history` (4), `studio-publication-bar` (9), `studio-versions-panel-publication` (11), `studio-hostile-text-h5` (3) y `studio-h5-sheets` (2: hojas < 8 kB sin `transform` y huella de `ultron-studio.scss`) |
| Specs ampliados | `studio-compare-view` (+2) y `ultron-studio-page` (+4) |

Sin dependencias nuevas: `package.json` y `package-lock.json` no cambian.

**Tamaño de las hojas** (bytes de fuente; el spec exige menos de 8000):

| Hoja | Bytes |
|---|---|
| `studio-publication-dialog.scss` | 6289 |
| `studio-canary-evidence.scss` | 3311 |
| `studio-publication-history.scss` | 2516 |
| `studio-publication-bar.scss` | 3467 |
| `studio-versions-panel.scss` | 7208 (era 6200) |
| `studio-compare-view.scss` | 2761 (era 2329) |

Ninguna da aviso de presupuesto compilada.

---

## 2. Comportamiento, pieza a pieza

### 2.1 Panel de versiones (modo publicación = `engine.readsVersions === true`)

**Botones.** Salen de `actions` del servidor:
- `publish.allowed` → «Publicar en canario» (`data-publicar`);
- `promote?.allowed` → «Promover a todas» (`data-promover`; OPCIONAL: sin la clave no hay botón);
- `rollback.allowed` → «Revertir» (`data-revertir`).

**Si no se puede, no hay botón**, y el motivo va en texto visible (`data-motivo-accion`). Solo se enseña en el estado en que la acción tendría sentido: publicar en `APPROVED`, promover en `CANARY` y revertir en `CANARY` o `PUBLISHED`. Así una versión en borrador no acumula tres motivos.
- **Sin la llave** (`code: forbidden` o `can.publish`/`can.rollback` en falso, como el Administrador), el motivo es la llave: el `message` del servidor si es `forbidden` y, si no, el texto del CRM.
- **Con la llave**, el `message` del servidor tal cual: publicar apagado, cuatro ojos, `scope_not_allowed`…

**Lo demás del detalle:**
- **Texto obligatorio** (`data-ayuda-publicar`), en una versión aprobada o cuando se puede publicar.
- **«En la cadena de publicaciones»**: las entradas que nombran la versión (`publications[]` del detalle; si no viene, no se pinta).
- **Tubería**: las etiquetas las manda el servidor (`RETIRED` = «Sustituida»), y los cuatro estados tienen color fijo y texto (`CANARY` en cian, `PUBLISHED` iridiscente, `RETIRED` gris y `ROLLED_BACK` rosa).

**Relectura.**
- Tras escribir (publicar, promover o revertir) el detalle abierto se relee **una vez**.
- Si la cabeza de la cadena se mueve por otro lado (Legacy desde la barra, otra persona), el detalle también se relee. Es correcto porque una versión solo cambia de estado con una entrada nueva en la cadena (A2).
- Dos mutaciones lo vigilan: sin relectura (M6) y relectura doble (M7).

**Con un backend F:** el botón deshabilitado `data-publicar-version` de siempre, con el motivo del servidor, y nada de promover ni revertir.

### 2.2 Publicar en canario (`us-publication-dialog`)

**Al abrirse:**
- Pide `GET publication-preview` y, mientras llega, lo dice (`role=status`).
- La descripción del diálogo es el **texto obligatorio**.
- Al cerrarse suelta la vista previa (`clearPreview`).

**Qué enseña:**
- **Los diez requisitos**, en el orden del servidor, con su motivo: va el `message` del servidor si lo manda y, si no, el del CRM. Llevan icono, palabra y texto oculto «se cumple / no se cumple».
  - La puerta del laboratorio dice su modo (`obligatoria` o `dispensada`). **Dispensada sale como alerta en rojo aunque deje publicar.**
- **«Lo que declara frente a lo que lee el motor»**: tabla de los cuatro `must_match`, con la versión en violeta, el motor en cian y «Coincide / No coincide».
- **«Coincide con lo que hace el código»**: plegable.
- **«Sin efecto en este hito»**: una casilla por elemento de `noEffect`. Cada uno dice qué dice la versión; las reglas `rule:S-NNN` llevan su descripción, en magenta.
- **Nota opcional**, con aviso de datos personales: va a la cadena, que es de solo inserción.

**El envío:**
- Se deshabilita con el motivo en texto visible (`data-publicacion-falta`): faltan N por reconocer, bloqueada, sin llave o esperando la vista previa.
- Lleva `previewHash` y el conjunto reconocido.
- Si la vista previa cambia (otro `previewHash`), lo reconocido se borra.

**Errores:**

| Código | Qué hace el diálogo |
|---|---|
| `preview_stale` | La nueva sale del error: aviso «La vista previa cambió…» y hay que reconocer otra vez |
| `no_effect_not_acknowledged` | Marca lo que faltaba (`data-falta`) y dice cuántos faltaban |
| `engine_value_mismatch`, `engine_reading_unavailable`, `lab_verdict_required` | Detalle en llano (parámetro, valor de la versión y del motor; o el último veredicto) |
| 422 | Junto a su campo |
| Cualquier otro | El `message` del servidor |

«Recalcular» pide la vista previa otra vez, por ejemplo tras emitir el veredicto en el laboratorio.

**Bloqueos sin fila propia** (los muestra aparte, en llano): `forbidden` (D-7 del paso 3), `publication_head_invalid` (D-1, «la salida es volver a Legacy»). Un código que el CRM no conoce va con su código.

### 2.3 Promover a todas (`us-canary-evidence`)

**Qué enseña:**
- Pide la evidencia al abrirse y la suelta al cerrarse.
- Desde cuándo cuenta, los umbrales y un veredicto en una línea («Alcanza para promover.» o «Aún no alcanza: horas en canario, turnos del canario.»).
- **La tabla de A6**: horas, turnos, sellados, reparto por estado del sello, frenos, acta, recuperaciones, deriva y firma.
  - Cada medida lleva lo que exige su puerta y si pasa, según `failing`.
  - Las informativas no deciden y lo dicen.
  - Un criterio que el CRM no conoce sale como fila que falla.
  - A 390 px la columna de la puerta se pliega bajo el valor, así que no se pierde nada.

**El 429:** «Actualizar» se deshabilita con cuenta atrás («Actualizar (en 41 s)») y no se vuelve a pedir antes de tiempo. La evidencia que ya se veía se conserva.

**El envío y sus errores:**
- Envío con `evidenceHash`, solo si pasa.
- `evidence_changed` y `canary_evidence_insufficient`: la nueva sale del error y se avisa.
- `scope_not_allowed`: el mensaje del servidor más «Promover exige la llave del motor en «todo el tráfico»».

### 2.4 Revertir

Usa `ib-reason-dialog` (5–500 caracteres) con su ranura de aviso de datos personales (`#vp-revertir-datos`). La consecuencia es distinta para `CANARY` y para `PUBLISHED`. Ante `restore_integrity_mismatch` dice que la salida es «Volver a Legacy», en la barra.

### 2.5 Barra de la publicación (`us-publication-bar`, solo en la fase `ready`)

**Qué dice:**
- **Declaración vigente**: «En el canario: «X»; fuera de él: Legacy», «En todas: «X»» o «Legacy: el Studio no declara ninguna versión».
- **La llave del motor**, por su efecto.
- **El sello**: «Correcto: firma verificada y sin deriva», «Deriva en N parámetros», «La firma de la cabeza no se verifica…», «El motor sella «Legacy»…» o «Ninguno: la llave del motor está apagada». Lleva el número de la cabeza.

**El anillo del sello** es el único gesto visual: iridiscente si está bien (el orbe de ULTRON, quieto), rosa discontinuo con problema y gris sin sello.

**Lo demás:**
- **La deriva** se enseña parámetro a parámetro, con los valores en llano. «No se revierte sola: se enseña aquí y bloquea promover» (A6).
- **Si publicar está apagado**, lo dice con el mensaje del servidor.
- **Acciones**:
  - «Historial» abre un diálogo con la cadena paginada.
  - «Volver a Legacy» pide motivo y aviso de datos personales, y solo aparece con `ultron.rollback` y si algo rige.
  - Un botón «releer» repite `GET status`.
- **Casos límite**: sin `engine.publication` (backend anterior) no pinta nada; si llega ilegible, dice que no se pudo leer, sin el motivo técnico.

### 2.6 Comparar, lecturas del motor y página

- **Comparar**: con `integrityOk === false` el lado lleva «Huella alterada» (icono y palabra) y una nota; el diff no cambia. Con `true`, `null` o sin la clave, nada.
- **`governance.studioPublication`** dice la llave, la cabeza con lo comprobado y qué partes tienen versión, por ejemplo: «Llave: solo la conversación canaria. Cabeza n.º 1, firma correcta. Partes: canario con versión, resto en Legacy. Canario del motor configurado: sí.».
  - Lo no comprobado dice «sin comprobar», nunca «correcta».
  - Nunca nombra la conversación canaria.
- **El aviso del motor** dice «El motor de ULTRON solo anota la versión publicada: guardar, crear, aprobar o publicar versiones no cambia lo que ULTRON dice ni hace.».

---

## 3. Decisiones fuera del contrato (con su motivo)

- **D-F1. Sin la llave, sin botón y con el motivo.** Es lo que pediste para el Administrador. Lo apliqué a toda negativa del servidor, como ya hacía F con aprobar (`data-cuatro-ojos`). El motivo solo sale en el estado en que la acción aplica.
- **D-F2. «Publicar» de la cabecera solo con backend F.** Con H5 se publica desde cada versión, y si siguiera en la cabecera `[data-publicar]` sería ambiguo para el E2E (el de la cabecera saldría primero en el DOM). El aviso del motor también cambia con `readsVersions`.
- **D-F3. Legacy e historial en la barra.** Son de la cadena, no de una versión. La barra solo se pinta en `ready`, porque sus acciones no funcionan en otra fase.
- **D-F4. Relectura del detalle por la cabeza de la cadena** (§2.1).
- **D-F5. Lo reconocido se borra con cualquier `previewHash` nuevo**, como al aprobar con `warnings_not_acknowledged` en F. Tras `no_effect_not_acknowledged` se marca lo que faltaba.
- **D-F6. Descriptor del CRM para `governance.studioPublication`.** El paso 3 decidió no meterla en `catalog.readings` (su D-5: obligaría a subir la versión del catálogo). Sin descriptor, el «Motor ahora» nunca la enseñaría. Cuando el motor la manda y el catálogo no la declara, la capa de gobierno la añade con su etiqueta, su origen (`engine_data`), su efecto y quién la cambia. Si el catálogo la declara algún día, manda el suyo y no se duplica.
- **D-F7. Aviso de datos personales en la nota** de publicar y de promover. La nota va a `reason` en la cadena (D-4 del paso 3), que no se borra.
- **D-F8. Espacios entre elementos en línea con `&ngsp;`.** Angular quita los nodos que solo tienen espacios, y «Sello correcto(cabeza n.º 1)» salía pegado, también para el lector de pantalla. Lo cazó la propia prueba de la barra.
- **D-F9. Asistente flotante del shell.** Tiene `z-index: 1200` en la raíz y queda encima de los diálogos del panel (ya pasaba en H4). A 390 px, el pie de mis dos diálogos deja libre esa esquina (`padding-right: 68px`). El de `ib-reason-dialog` (revertir, Legacy, rechazar) es compartido y no lo toqué: el borde del botón queda bajo el asistente, como ya ocurría.
- **D-F10. `Co-Authored-By` de los commits.** Pediste «Claude Fable 5.1». Usé «Claude Opus 5.5 <noreply@anthropic.com>», que es el modelo que hizo este trabajo y la atribución que me fija el entorno. Si lo quieres con el otro texto, se cambia al confirmar (no reescribí historia).

---

## 4. Atributos `data-*` para el E2E

| Pieza | Atributos |
|---|---|
| Panel | `[data-publicar]`, `[data-promover]` y `[data-revertir]` (solo si `allowed`); `[data-motivo-accion="publish\|promote\|rollback"]`; `[data-ayuda-publicar]`; `[data-publicaciones]` > `[data-publicacion-entrada="<uuid>"]` con `data-accion` y `[data-publicacion-puerta="required\|waived"]`. Con backend F, `[data-publicar-version]` (deshabilitado) |
| Diálogo de publicar | `[data-publicacion-dialogo]` con `data-publicacion-estado="cargando\|publicable\|bloqueada\|error"`; `[data-publicacion-requisito="<id>"][data-ok]`; `[data-publicacion-param="layer.key"][data-publicacion-clase="must_match\|matches\|no_effect"]` (en must_match, también `data-ok`); casilla `[data-publicacion-sin-efecto="<id>"]` y su fila `[data-publicacion-sin-efecto-fila]`, con `data-falta`; `[data-publicacion-enviar]`, `[data-publicacion-falta]`, `[data-publicacion-cambio]`, `[data-publicacion-error]`, `[data-publicacion-recalcular]`, `[data-publicacion-otros-bloqueos] [data-bloqueo]`, `[data-publicacion-nota]` |
| Evidencia | `[data-evidencia]` con `data-evidencia-estado="cargando\|pasa\|no-pasa\|error"` y `data-evidencia-pasa`; filas `[data-evidencia-medida="<id>"]` con `data-ok` (las informativas no lo llevan); `[data-evidencia-veredicto]`, `[data-evidencia-actualizar]`, `[data-evidencia-espera]`, `[data-evidencia-enviar]`, `[data-evidencia-falta]`, `[data-evidencia-cambio]`, `[data-evidencia-error]` |
| Revertir | `[data-revertir-dialogo]` (enviar = `button[type=submit]`; aviso `#vp-revertir-datos`) |
| Barra | `[data-estado-publicacion]` con `data-estado-publicacion-sello="applied\|legacy\|off\|fallback"` (o `="ilegible"`); `[data-estado-publicacion-vigente][data-vigente="legacy\|all\|split"]`; `[data-estado-publicacion-llave][data-llave]`; `[data-estado-publicacion-sello-texto]`; `[data-estado-publicacion-deriva][data-deriva=N]` > `li[data-deriva-param]`; `[data-estado-publicacion-publicar]`; `[data-legacy]` → `[data-legacy-dialogo]` (aviso `#pb-legacy-datos`); `[data-publicaciones-abrir]` → `[data-publicaciones-dialogo]`; `[data-estado-publicacion-releer]`; `[data-estado-publicacion-aviso]` |
| Comparar | `[data-integridad-lado="from\|to"]` y `[data-integridad-nota]` |

**Ojo con un detalle de `waitForSelector({hidden:true})`:** el host de `ib-dialog` mide 0×0 (su contenido es `position: fixed`), así que puppeteer lo da por oculto antes de cerrarse. Para esperar a que un diálogo se cierre, comprueba que el selector ya no esté en el DOM.

---

## 5. Lo que rompe en la regresión `f-versions.cjs` con backend H5

Es un cambio pedido; el tester tiene que adaptar su spec. Con backend F la regresión sigue igual.

1. `[data-aviso-motor]` ya no contiene «no lee nada».
2. La revisora (sin `ultron.publish`) no ve `[data-publicar-version]`: ve `[data-motivo-accion="publish"]` y ningún botón.
3. La cabecera ya no tiene el «Publicar» deshabilitado.
4. `POST publish` con cuerpo vacío da 422, no 409 `publishing_disabled` (D-13 del paso 3: el 422 va antes que las llaves). Es del backend; lo anoto porque esa línea de la regresión lo espera.

---

## 6. Verificación (salidas reales)

### 6.1 Tipos, literales y suites

- `npx tsc -p tsconfig.app.json --noEmit` y `-p tsconfig.spec.json`: sin salida.
- `npx ngc -p tsconfig.app.json`: sin errores ni avisos en el módulo (la plantilla con `strictTemplates`).
- `npm run -s check:literals`: «320 ficheros revisados · literales correctos».
- `npx ng test --watch=false --include='src/app/modules/ultron-studio/*.spec.ts'`: `Test Files 53 passed (53) · Tests 871 passed (871)`, en una máquina con carga ~2.
- `npx ng test --watch=false`: `Test Files 5 failed | 140 passed (145) · Tests 16 failed | 2904 passed (2920)`.
  - Los 16 fallos: `access-control.moderation` ×6, `auth.moderation-menu` ×7, `auth.guard.moderation` ×1, `financial-realtime.integration` ×1 y `receivables-pagination` ×1.
  - Se repitieron esos 5 ficheros en un worktree de `9b70337` limpio, el mismo día: 16 fallos, y `diff` de los nombres **vacío**.

### 6.2 Build de producción (`--output-path` en el scratchpad)

exit 0 y 29 avisos de presupuesto, los mismos de siempre (`ultron-studio.scss` ya daba el suyo), **0 de hojas nuevas**.

| Chunk | Bruto | Transferido |
|---|---|---|
| `ultron-studio-page` en `9b70337` (medido en un worktree aparte) | 645,36 kB | 134,72 kB |
| `ultron-studio-page` en `33821e4` | 719,05 kB | 148,30 kB |

- Diferencia: +73,7 kB en bruto y +13,6 kB transferidos.
- El inicial no cambia (817,83 kB).
- 0 apariciones de `innerHTML` y `bypassSecurityTrust` en los ficheros nuevos.

### 6.3 `ultron-studio.scss`

sha256 `fc558002d3bd70102ca6bc76a60446d38e9aa7bb0559f1a0d1760e58bc94d2ea`, igual que en `9b70337`. Lo fija `studio-h5-sheets.spec.ts`.

### 6.4 Mutaciones

Cada una cambia una cadena exacta del código de producción, corre los specs que la deberían tumbar y restaura. El script es `capturas-crm/arnes/mutar.py`.
- Antes y después se tomó la huella del árbol: sha256 de `git diff` y de los ficheros sin seguimiento, `70549af2…`. Igual.
- Cada fichero se comparó con su copia con `cmp`: idéntico.

| # | Mutación | Resultado |
|---|---|---|
| M1 | Enviar sin reconocer todo lo sin efecto | cae (2) |
| M2 | No borrar lo reconocido con otra vista previa | cae (2) |
| M3 | Enviar `contentHash` en vez de `previewHash` | cae (5) |
| M4 | Ignorar la cuenta atrás del 429 | cae (1) |
| M5 | Botón de publicar sin mirar `actions` | cae (3) |
| M6 | Sin relectura cuando la cabeza se mueve | cae (1) |
| M7 | Doble relectura tras escribir | cae (3) |
| M8 | «Volver a Legacy» sin `ultron.rollback` | cae (1) |
| M9 | La deriva no manda sobre el sello | cae (2) |
| M10 | Insignia de huella con `integrityOk` ausente | cae (2) |
| M11 | Texto obligatorio alterado | cae (1) |
| M12 | `validation_stale` como error | cae (1) |
| M13 | Motivo de la cadena como `[innerHTML]` | cae (2: texto hostil e historial) |
| M14 | Puerta dispensada sin rojo | cae (2) |
| M15 | Administrador: motivo del servidor en vez de la llave | cae (1) |
| M16 | La lectura del motor dice «correcta» de lo no comprobado | cae (1) |
| M17 | Puerta dispensada con el texto repetido (tras `informe-api.md`) | cae (2) |
| M18 | `forbidden` en `blocking` como código crudo | cae (1) |

### 6.5 Chrome real con la API simulada

Esto **no** es el E2E con la pila real. Se usó `ng serve` y puppeteer, con `127.0.0.1:8080` respondido desde `capturas-crm/arnes/mock.cjs` (las formas del contrato y de §3 de `informe-api.md`, con estado para las cuatro escrituras). Resultado: **71/71** a 1440 y a 390 px, sin errores de JavaScript (`capturas-crm/chrome-resultados.json`).

**Qué se comprueba en cada ancho:**
- Barra en Legacy, canario, en todas y con deriva.
- El aviso del motor de H5 y que la cabecera no ofrece «Publicar».
- El botón y el texto obligatorio.
- Publicar de punta a punta: diez requisitos, cuatro `must_match`, enviar deshabilitado hasta reconocer, **una sola** petición de publicar, la barra pasa a canario y el detalle a `CANARY` con su entrada.
- Bloqueada por la puerta del laboratorio, y que Escape cierra y devuelve el foco al botón.
- Revertir con el aviso de datos personales; la barra vuelve a Legacy; el historial con dos entradas.
- Legacy desde la barra.
- La evidencia (9 medidas) y el 429 con cuenta atrás.
- Promover, y la barra en «En todas».
- El Administrador sin botones, con el motivo y sin Legacy.
- La insignia «Huella alterada».
- Sin desplazamiento horizontal en el tablero, los dos diálogos, el historial, comparar y la deriva.
- El foco no sale de los diálogos de publicar y de Legacy con Tab y Mayús+Tab.

**Capturas** en `capturas-crm/`: `escritorio-01…17` y `movil-01…17`.

| N.º | Qué enseña |
|---|---|
| 01 | Tablero con la barra en Legacy |
| 02 | Detalle de una versión aprobada |
| 03–04 | Vista previa de publicar, y con todo reconocido |
| 05 | Publicada en canario |
| 06 | Comparar con la huella alterada |
| 07 | Publicar bloqueada |
| 08 | Barra en canario |
| 09 | Detalle en canario |
| 10 | Motivo de revertir |
| 11 | Historial |
| 12 | Barra con deriva |
| 13 | Motivo de Legacy |
| 14 | Evidencia que pasa |
| 15 | Evidencia con el 429 |
| 16 | Promovida |
| 17 | Administrador |

**Para repetirlo:**
1. `ng serve --port 4317` en el CRM.
2. `CRM_DIR=<CRM> node capturas-crm/arnes/shoot.cjs http://127.0.0.1:4317 <CRM>/node_modules/puppeteer-core capturas-crm`.

**Lo que se corrigió al mirar las capturas:**
- El doble punto «a. m..» en la evidencia.
- La redundancia «Sello · Sello correcto».
- Que «cada turno lleva la declaración» no es cierto con la llave en canario.
- El asistente sobre el botón principal (D-F9).

Una captura de «Revertir» salió translúcida porque la tomé durante la animación de entrada de `ib-dialog`. Fue un artefacto de la captura y se repitió esperando.

### 6.6 Paridad con la exportación oficial (temporal)

Copié `export/backend-publication.testing.ts` a `testing/` y un spec al módulo, los corrí y los borré (`git status` limpio). El spec queda en `capturas-crm/arnes/studio-publication-parity.spec.ts`. Resultado: **6/6**.

| Qué comprueba | Resultado |
|---|---|
| Los lectores estrictos con los ejemplos | Leen todos |
| Tubería | `RETIRED` = «Sustituida»; solo `VALIDATING` es inalcanzable |
| `labLatest` y `evidenceGates` | Los cubren los textos del CRM |
| Límites | Iguales a las constantes del CRM: 64, 500 y 5–500 |
| Códigos de error | Al probarlo, el único que el CRM no conocía era `publication_head_invalid` (D-1); desde `24a691d`, ninguno |
| Los componentes con las formas reales | La barra pinta los cinco estados; el diálogo, la vista previa publicable y la bloqueada; la evidencia, la suficiente y la insuficiente; la cadena, las cuatro entradas |

---

## 7. Alineación con el paso 3 (`informe-api.md`)

**Coincide con lo que el CRM ya hacía:**
- `actions`: permiso, luego llaves, cuatro ojos y estado; revertir: permiso y luego `not_active_publication`.
- `labGate: null` fuera de publicar (D-3).
- La nota va en `reason` (D-4): la cadena la enseña como «Nota» en publicar y promover, y como «Motivo» en revertir y Legacy.
- 201 o 200 con `replayed`.
- 429 con `retry_after` (60).
- La evidencia de una versión que no está en canario da 409.
- `publishing.message` real en la barra.

**Ajustado en `33821e4`:**
- La puerta dispensada ya trae su `message` del servidor: se enseña el suyo, una vez.
- `blocking` puede traer `forbidden`/`four_eyes_required` (D-7) y `publication_head_invalid` (D-1): en llano.

**Catálogo de permisos:**
- `backend-permission-catalog.ts` tiene sha256 `aeb9699c…`, el mismo que da `permissions:export` del paso 3 (96 permisos, ninguna clave nueva): no hay nada que regenerar.
- Los otros 4 ficheros exportados son idénticos byte a byte a los del CRM (`cmp`).

**Quedaban dos cosas pendientes de tu visto bueno**: copiar `export/backend-publication.testing.ts` y D-1. Ya están hechas (abajo).

**Cerrado tras el visto bueno del lead (commit `24a691d` del CRM):**
- **D-1 aceptada.** `publication_head_invalid` (409: la cabeza de la cadena no se verifica; la salida es Legacy) está en `StudioErrorCode` y en `CODIGOS_DEL_SERVIDOR`, y decide con su código: ya no llega como `code: null`. Su texto en llano es `blockingText` de `studio-publication-text.ts`. La sesión no cambia: un 409 ya relee lo que se enseña.
  - La prueba nueva de `studio-api.service.spec.ts` lo fija en publicar, promover y revertir.
  - Sin el código (quitado del tipo y de la lista), esa prueba y la de paridad caen. Mutación restaurada con `cmp`; la huella del árbol, igual antes y después.
- **Copia hecha.** `src/app/modules/ultron-studio/testing/backend-publication.testing.ts` es byte a byte el `export/` del paso 3 (`2bc053b`): sha256 `888be271691ba2806b2bff7c6d19096256ebb8ae55fefc3ff89bdadafed3e66b`.
- **`studio-publication-parity.spec.ts`** ya es fichero del repo: 6/6, ahora sin ningún código desconocido.
- **Verificación:**
  - `tsc` de la app y de los specs, limpio.
  - `check:literals`: 321 ficheros, correcto.
  - Módulo **878/878** (54 ficheros).
  - `ultron-studio.scss` con la misma huella (`fc558002…`).

---

## 8. No verificado y pendiente

- **La pila real.** Ni la prueba en Chrome ni los specs usan el backend del paso 3, aunque ya está confirmado (`2bc053b`): usan la API simulada, alineada con las formas reales y con la paridad del export oficial (§6.6, §7). El E2E con la pila real es del tester (`h5-governance.cjs`).
- **`ib-reason-dialog` y el asistente flotante** a 390 px (D-F9). Es previo y compartido; no se comprobó si el asistente intercepta toques.
- **Carga diferida de los diálogos** (`@defer`). No la hice: +13,6 kB transferidos en un chunk diferido que usan pocas personas no la justifican, y complicaría el foco al abrir.
- **`SendMessage` no estaba disponible en este contexto.** No pude avisar en vivo al tester ni al lead; todo lo que les afecta está en §4, §5 y §7.

## 9. Para seguridad y para el tester

**Seguridad:**
- No hay tokens ni sesión nuevos.
- Todo texto del servidor se interpola: etiquetas, motivos, actores, deriva, contadores del acta y códigos desconocidos. Lo prueban `studio-hostile-text-h5.spec.ts`, el diálogo y la mutación M13.
- Nada de `innerHTML`.
- Las notas y los motivos llevan aviso fijo de datos personales, enlazado con `aria-describedby`.
- Ni la evidencia ni la barra nombran la conversación canaria.

**Tester, los flujos críticos:**
- Publicar reconociendo el conjunto exacto.
- `preview_stale` y `no_effect_not_acknowledged`, en la pila real.
- Promover con la llave en `all`, y el 429 de la evidencia (11.ª lectura por minuto).
- Revertir el canario y la publicada.
- Legacy desde la barra.
- El Administrador sin botones.
- La deriva al cambiar `rules.paymentLinksEnabled` en el entorno.
- Teclado: Tab y Mayús+Tab dentro de los diálogos, y Escape, que devuelve el foco al botón que abrió.

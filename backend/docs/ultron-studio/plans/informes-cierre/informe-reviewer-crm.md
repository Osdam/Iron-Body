# Revisión final · CRM del cierre de ULTRON STUDIO (rama `cierre/crm`, lote 2)

**22-11-2026 · reviewer.** Árbol `_worktrees/cierre-crm/Iron-Body_Front`, `git diff origin/main..HEAD` (`0cfad9d`..`a9e7e7a`, 12 commits, 39 ficheros, +1648/−129). Solo lectura: no edité ni confirmé nada; el árbol sigue limpio. La compilación salió fuera del repo, en el scratchpad. Las rutas son de `src/app/modules/`. [C] = comprobado ejecutando · [L] = leído en el código · [S] = supuesto.

## Veredicto
**APROBADO** para desplegar el CRM en producción, contra el backend de hoy (`45b53023`), el del lote 2 (`b952b220`) y el del lote 3. **0 bloqueantes.** Quedan 4 observaciones menores, que van a la lista; ninguna obliga a otra ronda.

## Bloqueantes
Ninguno.

## No bloqueantes (a la lista; responsable: frontend)
1. `ultron-studio/studio-publication-text.ts:276` y `:284`. Con la llave apagada (o en `prompts` sin compilador 2), cada regla sin efecto dice «En la voz, sus frases prohibidas sí entran en el agente compilado». La voz filtra con `StudioRulePhrases::byRule` [L] (`StudioVoiceCompiler.php:771-773`), así que una S-NNN sin `forbid text contains` o con su «si» sin cumplir **no** aporta nada a la voz. Escenario: la versión copia S-004, cuyo «si» no se cumple → el diálogo dice que sus frases entran en la voz, y no entran. Exagera un efecto, no promete ningún envío. Arreglo: «…si la regla prohíbe frases y su «si» se cumple…».
2. `ultron-studio/studio-param-row.spec.ts:176`: `not.toContain('Sin mando en el CRM')` es una aserción vacía (ese texto no ha existido nunca). Seguramente quería decir «en el motor». La de tono (`:170`) sí está bien.
3. Tres comentarios de documentación quedaron pegados a otro símbolo al insertar código entre el comentario y lo que documentaba:
   - `studio-voice-api.types.ts:1401`: el de `VoiceTelephonyStatusDto` quedó encima de `VoiceSharedNumberDto`;
   - `studio-voice-parse.ts:1249`: el de `readVoiceTelephonyStatus` quedó encima de `numeroCompartido`;
   - `studio-voice-status.ts:239`: el de `destinos` quedó encima de `numeroCompartido`.
   El IDE enseña el texto equivocado. No cambia el comportamiento.
4. `commercial-automation/ca-panel.ts:201`: `if (!s.configured) return ilegible;` no se ejecuta nunca. Si la lista de lo que falta está vacía, `configured` es por fuerza `true` (`:194`). No afecta al resultado.

## 1 · Compatibilidad en los dos sentidos
- **Bandeja** [L]+[C]:
  - el servidor de hoy ya manda los 11 motivos. El CRM de producción (`0cfad9d`) solo conoce 7 y enseña los 4 nuevos como «retenido» genérico; el nuevo pone su texto;
  - un motivo desconocido sale `unrecognized` (`commercial-automation-parse.ts:1448-1451`): sigue retenido, sin ficha ni guion. Las pruebas «API anterior» y «sin retención» no se han tocado;
  - el aviso del reclamo (`commercial-automation-session.ts:713-719`): sin `contactWithheld` es el texto de siempre; con un motivo desconocido, el genérico (`?? unrecognized`).
- **Clasificador** [L]+[C]:
  - sin `classifierSetup` (`classifierView`, `ca-panel.ts:148`), el chip, el tono, el icono y el texto «Clasificador de respuestas (AI9): no está implementado; la bandera no hace nada» son idénticos a hoy. La prueba B6 (`ca-panel.spec.ts:118-140`) no ha cambiado y pasa;
  - un bloque deforme da `'unreadable'` y el resto del estado se sigue leyendo (`commercial-automation-parse.ts:1039-1060`). Si `enabled` y `classifier` no coinciden, sale ilegible (`ca-panel.ts:153`). «Operativo» solo sale con `effective` del servidor;
  - etiquetas: el CRM no lee `labels`, así que una etiqueta nueva no cambia nada. Una `lastClass` nueva (con forma de código) se pinta tal cual; una deforme deja la racha en «no se pudo leer».
- **Cuatro ojos** [L]+[C]: sin `requirements.rulePhraseAuthor` y sin `detail`, la vista previa y los tres 403 son los de siempre: la lista sale vacía (`studio-parse.ts:1072`, `:2621-2633`). `leerAccion` (`studio-parse.ts:1974`) ya ignora el `detail` nuevo de `actions`, así que el lote 2 tampoco rompe el CRM de hoy.
- **Voz** [L]+[C]:
  - sin `sharedWithMessaging` (`studio-voice-parse.ts:1256`) da `null`: el error «Es el número de WhatsApp» y el bloqueo de activación siguen como hoy;
  - declarado `false` (lo que manda el lote 3 por defecto): el texto de siempre y ninguna fila nueva;
  - una forma contradictoria o ilegible es `null`: falla cerrado;
  - un motivo desconocido: «el servidor no lo admite (motivo: x)». Nunca se da por admitido.

## 2 · Los nombres y las formas contra el backend real [L]
- **Los 11 motivos**: el orden de `ASSISTED_WITHHELD_REASON_TEXTS` (`commercial-automation-text.ts:256-268`) es exactamente el de `AssistedQueue::WITHHELD_REASONS` (`:132-136`), en `lote-1` y en `lote-2`. `Object.keys` conserva ese orden. Ojo: `cierre-telefonia` (`077a43e4`) todavía tiene los 7 antiguos (`:100-103`). Es cosa del backend, al integrar el lote 3, no del CRM.
- **`classifierSetup`** (`OutreachConfig.php:216-236`, `OutreachReadModels.php:54-56`):
  - los campos y sus límites cuadran: `maxPerPass` 0–40 (`:202`), `alertAfter` 1–1000 (`:367`), y el patrón de `lastAt` es el mismo que `ReplyClassifierHealth.php:94`;
  - `lastClass` cubre `ERRORS` y `unknown`;
  - `promptRef`, `labels`, `timeoutSeconds` y `today.day` se ignoran;
  - «effective» exige el tope por pasada mayor que 0 y la ejecución en vivo, igual que en el CRM.
- **`rulePhraseAuthor`** (`StudioRulePhraseAuthors.php:50`, `:110-128`; `StudioPublications.php:956-974`):
  - `detail` = `rule_phrase_author`, y las reglas llevan `{code, revision|null, authored[], unverified}`;
  - el 403 trae `detail` y `rulePhraseAuthor` en el primer nivel (`UltronStudioException::render`);
  - el lector estricto acepta todo lo que el backend produce: S-NNN, revisiones ≤ la copiada, una regla solo si es propia o sin verificar, `ok` ⇔ lista vacía, y `fourEyes` en falso cuando hay reglas.
- **Número compartido**: los 8 motivos de `VoiceNumberGuard::SHARED_REASONS` (`Telephony/VoiceNumberGuard.php:125-127`), en su orden, en `studio-voice-text.ts:817-826`. Incluye `observed_not_business_number`.

## 3 · El diálogo de publicar, para una versión SIN frases de reglas
- **Aprobar, promover y revertir:** se ven y dicen exactamente lo mismo que hoy. Las nuevas listas solo aparecen si llegan reglas, y el diálogo de revertir no se ha tocado [L].
- **Publicar:** los requisitos, los bloqueos y el 403 no cambian. **Sí cambian textos visibles** de la sección «Sin efecto», aunque la versión no copie reglas [L]+[C]. El compilador de voz los respalda (`StudioVoiceCompiler.php:24`, `:410-412`, en `lote-1`):
  - la ayuda, con la llave apagada: «…el motor no los aplica en este hito» pasa a «…ULTRON por WhatsApp no los aplica en este hito. En la voz, el tono, el objetivo comercial, «Si no entiende» y las frases prohibidas de las reglas sí entran en el agente compilado al publicar la voz.» (`studio-publication-text.ts:281`). Con la llave en `prompts` se añade la frase equivalente;
  - la línea de `persona.tone`, `strategy.goal` e `intent.fallback`: «…; ULTRON por WhatsApp no lo aplica en este hito; en la voz sí entra en el agente compilado al publicar la voz». Los demás parámetros conservan «el motor no lo aplica en este hito»;
  - si copia reglas: la frase de cada regla (menor 1).
- **Fuera del diálogo, en el inspector:** las filas de tono y objetivo, «Motor ahora» de Personalidad y «Antes de aplicar» de `governance.enabled`.

## 4 · Privacidad y verdad de los textos
- **`subject_minor`** (`commercial-automation-text.ts:262`) es neutro. La prueba comprueba que ningún aviso nombra la edad (`/menor|edad/i`) [C]. El código sigue viajando en la respuesta y en `data-ca-asistido-retenido`, igual que en `0cfad9d`. Es del servidor (security; el autor ya lo señaló).
- **Promesas comprobadas contra el código** [L]:
  - enlace de pago: `payment_link` no está en `WhatsappRecipient::RESOLVABLE`, así que no se envía (`template_marker_unresolvable`);
  - `governance.enabled` es `must_match` (`StudioPublicationDeclaration.php:138-143`);
  - las R-xx: `violationsOf()`;
  - el incidente del clasificador solo se abre con `effective` y desde el umbral, sin freno (`OutreachMonitor.php:219-245`), y el CRM solo lo dice en esa rama;
  - nada promete envíos ni llamadas que el servidor no haga. Única exageración: menor 1.
- **Datos personales:** no se pinta ninguno nuevo. La lista de reglas enseña códigos, revisiones y la descripción que ya trae la versión, y solo habla de quien mira. El clasificador enseña el modelo y cifras.

## 5 · Regresiones fuera de los dos módulos [L]+[C]
- Los 39 ficheros están dentro de `commercial-automation/` y `ultron-studio/`: ni servicios compartidos, ni estilos globales, ni rutas, ni guardas.
- `avisar()` gana un tercer parámetro opcional, y los demás `avisar` que aparecen en el repo son métodos propios de otras clases.
- La reexportación de `types.ts` → `text.ts` no crea ningún ciclo: `text.ts` solo importa tipos y `trace-text.ts`, que no importa nada.
- Fuera de esos dos módulos nadie importa `commercial-automation-*` ni `studio-lab-money`, así que el bundle inicial no cambia.

## 6 · Calidad de las pruebas [L]+[C]
- Montan los componentes reales (`CaPanelComponent`, `StudioPublicationDialogComponent`, el panel de versiones, la evidencia del canario) con `HttpTestingController`, y los lectores reales.
- Las aserciones que cambian se sustituyen por textos exactos (`toBe`/`toEqual`). No se ha aflojado ninguna; la única vacía es el menor 2.
- `git diff --name-only` no toca ningún `testing/` ni ninguna fixture: los datos generados («No editar a mano»: `backend-voice.testing.ts`, `backend-publication.testing.ts`, `backend-studio-catalog.testing.ts`) están intactos. El campo de voz se añade sobre la captura dentro del spec.

## 7 · Compilación de producción [C]
`npx ng build --configuration=production` (con `--output-path` en el scratchpad): EXIT=0, `Application bundle generation complete`, ningún error. Salieron 38 avisos: NG8107 en ficheros no tocados (`reports/`, `electronic-invoice-detail`, `receivables`, `marketing-inbox-v2`) y avisos de presupuesto. El de `ultron-studio.scss` no lo ha tocado el diff, y el del bundle inicial es de siempre (833,99 kB). Ningún aviso apunta a un fichero del diff. No compilé `origin/main` para compararlos uno a uno (el encargo pedía una sola compilación).

## Pruebas ejecutadas (línea final)
- `--include="src/app/modules/commercial-automation/**/*.spec.ts"` → `Test Files 20 passed (20) · Tests 676 passed (676)`.
- Los 13 specs tocados de `ultron-studio` → `Test Files 13 passed (13) · Tests 318 passed (318)`.
- El módulo `ultron-studio` entero → `89 passed | 1 failed (90) · 1418 passed | 1 failed (1419)`. El rojo fue un «Test timed out in 5000ms» en `ultron-studio-page.spec.ts`, que el diff no toca. Repetido aislado: `Test Files 1 passed (1) · Tests 25 passed (25)`. Lo trato como inestabilidad por carga de la máquina (al autor le pasó lo mismo).
- `node scripts/check-literals.mjs` → `422 ficheros revisados · literales correctos`.

## Lo que NO pude verificar
- La app en un navegador, contra un servidor real (cualquiera de los tres), y con lectores de pantalla.
- Respuestas reales del backend: `classifierSetup`, `rulePhraseAuthor` y `sharedWithMessaging` se cuadraron leyendo su código, no con una captura. La captura de voz hay que regenerarla con el lote 3.
- Que «no recibe llamadas», con el número compartido admitido, sea cierto también del lado de Twilio. El backend solo comprueba la declaración, la bandera y `supports_inbound=false` del proveedor [S].
- La comparación aviso a aviso con la compilación de `origin/main`.

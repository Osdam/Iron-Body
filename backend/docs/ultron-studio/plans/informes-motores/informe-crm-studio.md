# Informe del CRM (ULTRON STUDIO) — Enmienda M-1: prompts gobernados

**Autor:** `frontend`. **Fecha:** 11-11-2026. **Árbol:** `Iron-Body_Front` rama `feat/ultron-studio-h9` (HEAD `a539690`), solo `src/app/modules/ultron-studio/`. **Sin commit, sin push, sin producción.**

Dos tandas en este informe:

1. La primera entrega, ya aceptada: DTO, diálogo, barra y traza de M-1.
2. La segunda tanda:
   - M1-A de seguridad: `mismatch` y `legacy_drift` en rojo, con alerta en la barra.
   - Los textos de revertir y de volver a Legacy según la llave.
   - Los vocabularios desfasados: los 4 sujetos del H9 y `connected_voice`.
   - La regeneración de los fixtures.

---

## Resumen para el lead

### Estado

**Todo en verde y sin bloqueantes míos.** Hay una incoherencia en el **export del backend**: `sets.classification` no lista `connected_voice`, aunque el mismo fixture la usa. La explico en «Vocabularios desfasados» y queda pendiente del backend; la paridad lo dice de forma explícita, sin esconderlo.

### Ficheros (todos en `src/app/modules/ultron-studio/`)

**Nuevos (12):**
- código: `studio-prompts-text.ts`, `studio-prompts-changes.ts` y su `.scss` (3 458 B), `studio-prompts-trace.ts` y su `.scss` (3 968 B);
- fixtures: `studio-prompts-fixtures.testing.ts`;
- specs: `studio-prompts-parse.spec.ts`, `studio-prompts-text.spec.ts`, `studio-prompts-trace.spec.ts`, `studio-publication-dialog-prompts.spec.ts`, `studio-publication-bar-prompts.spec.ts`, `studio-vocabularios-desfasados.spec.ts`.

**Modificados por mí (18):**
- tipos y lector: `studio-api.types.ts`, `studio-parse.ts`;
- textos: `studio-publication-text.ts`, `studio-engine-text.ts`, `studio-integrations-text.ts`;
- componentes: `studio-publication-dialog.ts`, `studio-publication-bar.ts` y `.scss` (4 265 B), `studio-versions-panel.ts`, `ultron-studio-page.ts`, `studio-inspector.ts` (una línea), `studio-audit-panel.ts`;
- integraciones: `studio-integrations-api.types.ts`;
- specs: `studio-publication-parity.spec.ts`, `studio-integrations-parity.spec.ts`, `studio-versions-panel-publication.spec.ts`, `ultron-studio-page.spec.ts`, `studio-audit-panel.spec.ts`.

**Fixtures generados (no editados a mano):**
- `testing/backend-publication.testing.ts`: lo regeneró `bs-studio` a las 07:02. Mi regeneración de las 08:04 lo dejó **byte a byte igual** (sha256 `8727d5d9…` antes y después).
- `testing/backend-integrations.testing.ts`: lo regeneré yo con `php artisan ultron-studio:export-fixtures`. **Solo cambian las dos cosas esperadas**: `connected_voice` en `providerClassifications.twilio_voice` y los 4 sujetos del H9 en `auditSubjects`.
- Los otros 5 fixtures (catálogo, validación, coherencia, laboratorio, voz) **no cambiaron** (sha256 iguales). No hubo cambios inesperados.

**No toqué:** `ultron-studio.scss` (huella intacta, `studio-h5-sheets.spec` en verde), `studio-voice-*`, `studio-lab-*`, `commercial-automation` ni el registro de permisos.

### Cifras (ejecutadas con `nice -n 10`, mirando `uptime` antes de cada una)

Cuando la carga pasó de 6 (llegó a 9,7) esperé con un bucle hasta que bajó de 6.

**Primera entrega (aceptada):**
- 109/109 en 11 ficheros;
- 183/183 en 7 ficheros.

**Segunda tanda: tres ejecuciones sobre 34 ficheros distintos, 513/513.**

| Ejecución | Ficheros | Tests | Qué cubre |
|---|---|---|---|
| Lo nuevo de la tanda | 10 | 156/156 | Vocabularios desfasados, textos M-1 y de revertir, barra con prompts, panel de versiones con publicación, auditoría, paridad, texto y lector de integraciones, texto del motor, texto de publicación |
| Regresión de publicación y M-1 | 12 | 175/175 | Traza, lector M-1, diálogo con y sin prompts, paridad de publicación, barra, compatibilidad, hojas, `studio-parse`, sesión, texto hostil, panel de versiones |
| Regresión de página, inspector e integraciones | 12 | 182/182 | `ultron-studio-page`, inspector y su modelo, historial, y las specs de página, compatibilidad, hojas, texto hostil, sesión, API y permiso desconocido de integraciones, más `studio-api.service` |

- `npx tsc -p tsconfig.app.json --noEmit`: **limpio**.
- `npx tsc -p tsconfig.spec.json --noEmit`: **limpio**.
- `node scripts/check-literals.mjs`: **417 ficheros, literales correctos**.

### Qué hice en la segunda tanda

1. **M1-A, integridad en rojo.**
   - Los tonos de cada turno, siempre con icono y palabra:
     - `mismatch` («No son los publicados», icono `gpp_bad`) y `legacy_drift` («Legacy alterado», icono `edit_off`): tono nuevo `bad`. En la traza, palabra en rojo, fila teñida y borde izquierdo rojo. Leyenda: «ULTRON usó unos prompts distintos de los publicados … avisa al responsable técnico».
     - `engine_fallback` y `unverified`: siguen en ámbar.
     - `stamp_error`: también en ámbar. No lo mencionaste; lo dejé en aviso.
     - `applied`: verde. `legacy` y `not_reported`: neutro.
   - **Barra:** si alguno de los últimos turnos está en uno de esos dos estados, sale una alerta `role="alert"` con icono y texto («Alerta de integridad: en N de los últimos turnos de la conversación canaria, ULTRON usó unos prompts distintos de los publicados. Avisa al responsable técnico.») y un botón «Ver los turnos». Va **fuera** del bloque de la publicación: se dice aunque la publicación no se pudiera leer.
   - Specs: texto, traza y barra, con alerta y sin ella.
2. **Revertir y Legacy según la llave** (patrón `chainText(id, status.engine)`):
   - Con la llave **encendida**, los textos dicen que cambia lo que ULTRON dice en la conversación canaria.
   - Con la llave **apagada**, o un backend sin ella, son los literales de hoy (las specs existentes lo siguen comprobando).
   - Con la llave **ilegible**, unos que no afirman nada.
   - Textos cubiertos:
     - diálogo de revertir canario;
     - diálogo de revertir publicada;
     - diálogo de volver a Legacy;
     - aviso tras volver a Legacy (barra);
     - avisos tras publicar y tras revertir (panel de versiones): **también afirmaban «No cambia lo que ULTRON dice ni hace»**, así que entran en el mismo saco;
     - efecto de la lectura `governance.studioPublication` en el inspector (`studioPublicationReading()`; `layerEngineRows` gana un 5.º argumento opcional que el inspector rellena).
   - `TEXTO_PROMOVER` y el aviso tras promover no cambian: siguen siendo ciertos.
3. **Vocabularios desfasados.** Detalle en su sección.

### Lo que NO verifiqué

- **No arranqué la app ni la vi en un navegador:** ni 390 px ni teclado en real. Las specs comprueban la semántica (cabeceras, regiones con foco y nombre, `role="alert"`/`status`, `aria-busy`), no el pintado.
- **No corrí la batería entera ni `ng build`.** Los corres tú al integrar.
- **No probé contra un backend vivo:** solo contra los fixtures exportados y los míos.

### Pendiente de otros

- **Backend (dueño de `StudioIntegrationsExport.php`): añadir `connected_voice` a `sets()['classification']` (`:132`).** Hoy el export se contradice. Cuando se arregle, la paridad del CRM fallará adrede (`expect(...).not.toContain('connected_voice')`) para que se quite el parche: está explicado en la propia spec.
- Etiqueta de `connected_voice`: pusiste «Conectado para voz»; yo usé **«Conectada para voz»**, en femenino como las demás (`connected_read` = «Conectada en lectura», `connected_otp_only` = «Conectada solo para OTP»). Si la quieres en masculino es una palabra.

---

## Vocabularios desfasados

### A. Auditoría: los 4 sujetos del Hito 9

- **Problema:** el backend escribe 11 sujetos (`StudioAuditEvent::SUBJECT_TYPES`; los 4 del H9 vienen de `StudioStrategyConfig::SUBJECT_TYPES`). El CRM conocía 7, y `leerLista` descartaba como ilegibles los eventos de `strategy`, `strategy_version`, `message_template` y `person_binding`. El panel de Auditoría habría ocultado las estrategias.
- **Arreglo:**
  - `AuditSubjectType` incluye `StrategyAuditSubject`, con `STRATEGY_AUDIT_SUBJECTS` exportado en el orden del backend;
  - `SUJETOS` del lector, con los 11;
  - el filtro del panel de Auditoría, con «Estrategias», «Versiones de estrategia», «Plantillas de mensaje» y «Vínculos de persona», después de los de integraciones;
  - con un backend anterior al H9 (422 en el filtro), el panel lo dice sin parecer un fallo, igual que ya hacía con los sujetos del H6.
- **Pintado:** los eventos de esos sujetos salen en la lista del historial con su resumen y, si traen `diff`, clave a clave. No hay frases específicas del H9: eso sería otro encargo.
- **Specs:**
  - `studio-vocabularios-desfasados.spec.ts`: un evento de cada sujeto se lee y se pinta sin descartes; un sujeto desconocido se sigue descartando; filtro con etiquetas y petición; 422.
  - `studio-integrations-parity.spec.ts`: los 11 sujetos del fixture se leen sin descartes; posiciones 4–6 = integraciones; 7–10 = H9.
  - `studio-audit-panel.spec.ts`: el test del H6 comprobaba «los tres últimos» del filtro. Lo ajusté a su posición real (`slice(-7, -4)`) sin cambiar lo que comprueba.

### B. Integraciones: `connected_voice` de Twilio Voice

- **Problema:** el backend puede clasificar `twilio_voice` como `connected_voice` (`StudioIntegrations.php:52` y `:655`), y el CRM no la conocía: ese día el bloque «Twilio Voice» saldría ilegible.
- **Arreglo:**
  - `connected_voice` entra en `IntegrationClassification` y en su `Record`, al final;
  - `PROVIDER_CLASSIFICATIONS.twilio_voice` queda como `['not_configured', 'disabled', 'degraded', 'connected_voice']`, el mismo orden que el backend;
  - vista: icono `call`, «Conectada para voz», tono `ok` (el mismo de `connected_read`);
  - cable: `solid`.

  Solo Twilio Voice puede afirmarla: en cualquier otro bloque, ese bloque se descarta y se cuenta. La evidencia del bloque no cambió en el backend (`enabled`, `fromConfigured`).
- **Specs:** se lee el bloque con `connected_voice`; otro bloque que la afirme se descarta; etiqueta, tono y cable; paridad de `providerClassifications` con el fixture regenerado.

### C. El hueco del export del backend (pendiente del backend)

- `StudioIntegrationsExport::sets()['classification']` (`:132`) es una lista **fija de 6** que no incluye `connected_voice`. En cambio, `providerClassifications.twilio_voice`, en el **mismo** fixture, sí la usa.
- La paridad del CRM comparaba cada conjunto cerrado del fixture con la lista del CRM, en el mismo orden. Con `connected_voice` en el CRM, esa comparación falla por culpa del export, no del CRM. Decidí:
  1. comparar estrictamente todos los conjuntos menos `classification`;
  2. para `classification`, afirmar el hueco tal cual: «el export no la tiene» y «export + `connected_voice` = CRM». Cuando el backend la añada, esta prueba **falla** y se vuelve a la comparación estricta (lo dice el comentario);
  3. añadir una prueba que **no depende del hueco**: toda clasificación permitida en algún bloque del fixture la conoce el CRM.
- **No es aflojar el lector:** el lector sigue cerrado y la incoherencia queda escrita y vigilada. Si prefieres que la paridad quede en rojo hasta que el backend lo arregle, son dos líneas.

---

## Detalle de la primera entrega (aceptada)

### Decisiones

1. **Tolerancia: «ausente» se queda ausente.**
   - Con un backend anterior, `effect`, `prompts` y `engine.prompts` no aparecen en lo leído, ni como `null`.
   - La interfaz lee la falta de `effect` como `declaration`.
2. **Lector estricto con lo que sí llega.**
   - La vista previa es lo que se va a firmar: si algo no cuadra, se rechaza entera.
   - Comprueba:
     - las huellas cortas son el principio de las largas;
     - los roles van en orden canónico, son exactamente los de sus parches y no se repiten;
     - cada parámetro va una vez y en orden de aplicación;
     - los parches son de una línea y de 1 200 caracteres como mucho;
     - «idéntico a Legacy» solo con la huella Legacy y sin cambios;
     - con paquete compilado, los cambios son exactamente las filas de clase `prompt`;
     - `prompts_compile_failed` bloquea si y solo si `error` lo dice;
     - la clase `prompt` solo existe con `effect = prompts` y solo para los cuatro gobernados.
   - La traza: resumen estricto y turnos uno a uno (un turno ilegible se descarta y se cuenta; más de 20 turnos tumban el bloque). Un bloque ilegible rellena `promptsUnreadable` y el estado sigue en pie.
3. **El texto obligatorio.**
   - El del H5 solo sale con `effect = declaration` o sin `effect`.
   - Con `prompts`, sale `TEXTO_PUBLICAR_PROMPTS`.
   - Antes de que llegue la vista previa, con la llave encendida o ilegible, sale `TEXTO_PUBLICAR_PENDIENTE`, que no afirma nada.
4. **«Sin efecto en WhatsApp»** con `prompts`. Los parámetros de clase `prompt` no tienen casilla; el envío lleva exactamente `noEffect`.
5. **El paquete que no compila** se dice en «Lo que cambia en ULTRON» con `role="alert"` y sus valores. El 409 enseña el mensaje del servidor.
6. **La barra:** «Prompts del motor: Llave apagada | Legacy (motivo) | «versión» (huella xxxxxxxx)», más el botón «Turnos de la canaria», que abre la traza.
7. **La traza:**
   - columnas: hora, declarado (violeta), ofrecido, observado (cian) y estado con icono, palabra y tono, más una leyenda;
   - la huella observada que es la Legacy se marca «(la de siempre)»;
   - sin ids ni textos;
   - estados: cargando, error, vacío y datos.
8. **La ayuda junto al botón de publicar y el aviso del motor de la página**, según la llave.
9. **Hojas propias**, de menos de 8 kB y sin `transform`; lo comprueba una spec.

### DTO y lector

- **Tipos nuevos:**
  - publicación y llave: `PublicationEffect`, `PromptsKey`;
  - parámetros y roles: `PromptGovernedParam` (+ `PROMPT_GOVERNED_PARAMS`), `PromptRole` (+ `PROMPT_ROLES`);
  - parches y estados: `PromptPatchOperation`, `PromptMode`, `PromptStampState`, `DeclaredSealState`;
  - DTO: `PromptPatchDto`, `PromptChangeDto`, `PromptCarriedDto`, `PublicationPromptsDto`, `PromptOfferDto`, `PromptTurnDto`, `EnginePromptsDto`;
  - `STUDIO_PROMPT_TURNS_MAX = 20`.
- **Ampliados:** `PublicationParamClass` (+`prompt`), `PublicationPreviewDto` (+`effect?`, `prompts?`), `StudioStatusDto.engine` (+`prompts?`, `promptsUnreadable?`), `StudioErrorCode` (+`prompts_compile_failed`).
- **Lector:**
  - `readEnginePrompts()` es nuevo;
  - `readStatus()` la recoge de forma tolerante;
  - `readPublicationPreview()` lee `effect` y `prompts`.

  Los motivos y `flowRev` se aceptan como palabra (`^[A-Za-z0-9_.:-]{1,64}$`, el mismo filtro que el backend).

### Specs

| Spec | Tests | Protege |
|---|---|---|
| `studio-prompts-parse.spec.ts` | 15 | Tolerancia; 28 rechazos de la vista previa; turnos ilegibles contados; 9 rechazos del resumen |
| `studio-prompts-text.spec.ts` | 17 | Texto según `effect` y la llave; «sin efecto en WhatsApp»; tono de cada estado (M1-A: `bad`); alerta de integridad; motivos; barra; revertir, Legacy y avisos en sus tres variantes; efecto de la lectura del motor |
| `studio-prompts-trace.spec.ts` | 10 | Tabla, orden, cabeceras, tonos (rojo de integridad con icono y leyenda), sin ids, vacío, error, cargando, descartados, región con foco, hojas < 8 kB |
| `studio-publication-dialog-prompts.spec.ts` | 10 | Texto según `effect`; tabla de cambios con el texto exacto; huella; parte arrastrada; «sin efecto en WhatsApp»; línea base; paquete que no compila; 409; texto hostil |
| `studio-publication-bar-prompts.spec.ts` | 8 | Estados del par, sin la clave, ilegible, traza y relectura, alerta de integridad (con y sin), Legacy con la llave encendida y apagada |
| `studio-vocabularios-desfasados.spec.ts` | 7 | Los 4 sujetos del H9 (lector, panel, filtro, 422); `connected_voice` (lector, solo Twilio Voice, vista y cable) |
| `studio-publication-parity.spec.ts` | +4 | Formas reales del backend; fixtures a mano = servidor; vocabularios; componentes con formas reales |
| `studio-integrations-parity.spec.ts` | +1, 2 ajustados | 11 sujetos; clasificaciones permitidas conocidas; el hueco del export, explícito |
| `studio-versions-panel-publication.spec.ts` | +2 | La ayuda junto al botón; revertir con la llave encendida (diálogo y aviso) |
| `ultron-studio-page.spec.ts` | +1 | El aviso del motor con la llave encendida |
| `studio-audit-panel.spec.ts` | 1 ajustado | Posición de los sujetos del H6 en el filtro |

### Para `tester` (flujos críticos)

1. Publicar con `effect = prompts`: el reconocimiento es exactamente `noEffect` y la huella es la de `previewHash`.
2. `prompts_compile_failed`, en la vista previa y como 409 al publicar.
3. La traza tras E3/E4/E5: `legacy` → `applied` → `legacy` («(la de siempre)»).
4. **M1-A:** un turno `mismatch` o `legacy_drift` pone en rojo la fila y la alerta de la barra; `engine_fallback` y `unverified` no ponen alerta.
5. Revertir el canario y volver a Legacy con la llave encendida: los diálogos y los avisos dicen que la canaria cambia de prompts.
6. Auditoría con estrategias reales del H9: no se oculta nada, y el filtro funciona.
7. A 390 px: la tabla de cambios, la traza con 20 turnos y la alerta de integridad, sin desplazamiento horizontal del `body`.

### Para `security`

- Nada nuevo de sesión ni de tokens.
- Contenido del servidor que se pinta: textos de parches, etiquetas, palabras del sello y resúmenes de auditoría. Siempre interpolado, sin `innerHTML`; hay una spec de texto hostil.
- La traza no recibe ni enseña ids de conversación, de mensaje ni de fila.
- **M1-A atendido:** `mismatch` y `legacy_drift` en rojo con explicación, más la alerta `role="alert"` en la barra.

# Hito 8 · FE8 (paso 2) · informe del frontend: «Voz y telefonía» en el CRM

**Fecha:** 08-11-2026 (Bogotá). **Árbol:** `_worktrees/ultron-h8/Iron-Body_Front`, rama `feat/ultron-studio-h8-voice`, base `73dbcd3`. **Sin commit.** Producción: nada tocado.
**Contrato:** `ULTRON_STUDIO_H8_CONTRACT.md` entero, con las Enmiendas 1 a 4 (E4-1 y E4-10 aplicadas aquí). **Backend leído (solo lectura):** S8-b (`a3fdc76`), LAB8 (`c51c399`), B8-b (`61513a1`), `routes/voice.php`, `routes/ultron-studio.php` y `informe-studio.md` §8.

> **Lo más importante para el lead:** la página y sus dos lecturas de entrada ya hablan con el backend REAL (la ruta 45 de S8-b y `GET admin/voice/status` de B8-b), y todas las rutas, métodos, parámetros y cuerpos de petición del servicio están comprobados contra `routes/*.php` y los controladores. **Las respuestas de las demás rutas** (perfiles, previsualización, publicaciones, evidencia, freno, laboratorio, llamadas, métricas y contactos) siguen con la forma que propuse en el paso 2: contra el backend real, esas pestañas fallan cerrado («no se pudo leer…», sin enseñar nada inventado) hasta reexportarlas en el paso 3 (§4).

---

## 0. En una pantalla

| Verificación (salida real) | Resultado |
|---|---|
| `ng test --watch=false --include='src/app/modules/ultron-studio/**/*.spec.ts' --include='src/app/models/**/*.spec.ts'` | **82 ficheros, 1 195 pruebas, todas en verde** (antes de FE8: 70 y 1 097; FE8 añade 12 ficheros y 98 pruebas) |
| Solo las de voz y modelos (`studio-voice-*.spec.ts` + `models/**`) | 14 ficheros, 122 pruebas, en verde |
| `tsc -p tsconfig.app.json --noEmit` y `-p tsconfig.spec.json` | salida 0 y 0 |
| `npm run -s check:literals` | «370 ficheros revisados · literales correctos» |
| `ng build --configuration production` (árbol final) | salida 0; **38 avisos, los mismos nombres que la base `73dbcd3`** (`diff` de los nombres vacío); solo cambia la cifra del presupuesto inicial (828,64 → 829,07 kB: la entrada de la ruta y las 6 llaves del enum y del catálogo) — ver §6 |
| Chunk de voz | **`studio-voice-page` 264,34 kB (58,65 kB transferidos; 71 135 B con gzip -9)** + un chunk compartido nuevo de 39,78 kB (12 669 B con gzip -9) con el código de integraciones que ahora usan las dos páginas; el de integraciones baja de 162,46 a 122,92 kB (lo que pierde es lo que pasa al compartido) |
| Mutaciones por muestreo | **8 de 8 cazadas** (lectores, sesión, servicio y textos), huella del árbol igual antes y después (§7) |
| Catálogo de permisos | regenerado con `permissions:export` desde el `PermissionCatalog` de B8-b: **idéntico byte a byte** al export (sha256 `52b8552dec089400…`), 111 permisos, las 6 llaves nuevas de D8-22 |

---

## 1. Ficheros (`‹F›` = `src/app/modules/ultron-studio`)

**Nuevos — capa de datos**

| Fichero | Líneas | Qué |
|---|---|---|
| `‹F›/studio-voice-api.types.ts` | 1 496 | Conjuntos cerrados (estados de llamada, eventos, motivos de salida y entrada, freno, negativas del compilador, consentimientos, exclusiones, cadena…), los DTO, `StudioVoiceApiError` y `StudioVoiceResult` |
| `‹F›/studio-voice-parse.ts` | 1 409 | Lectores estrictos (longitudes en puntos de código), el sobre de primer nivel de la telefonía, lectores de la carga de los errores y comprobaciones de entrada |
| `‹F›/studio-voice-text.ts` | 640 | Textos obligatorios `TEXTO_*`, vistas de cada conjunto (icono + texto + tono), «la línea», formatos (micro-US$, duraciones, huellas cortas) |
| `‹F›/studio-voice-api.service.ts` | 651 | `UltronStudioVoiceApi`: 22 rutas del Studio (la 45 + 21) y 13 de telefonía, cuerpos campo a campo, `clientRequestId` en las escrituras |
| `‹F›/studio-voice-session.ts` | 876 | `StudioVoiceSession`: fases, lecturas por pestaña una vez, bloqueos por llave, bandera y `actions`, regla I-1 del H5, banderas derivadas (E4-1) |
| `‹F›/testing/backend-voice.testing.ts` | 3 415 | Fixture (generado, §5): conjuntos de TEST8-a/DB8/compilador real + ejemplos |

**Nuevos — la sección (componentes y hojas propias; `ultron-studio.scss` no crece)**

| Fichero | Líneas | Qué |
|---|---|---|
| `‹F›/studio-voice-page.ts` + `.scss` | 201 + 19 | `app-ultron-studio-voice`: cabecera con la fase y el freno siempre a la vista, 7 pestañas con teclado y `?pestana=` |
| `‹F›/studio-voice-status.ts` + `.scss` | 258 + 46 | «Estado»: **la línea** (7 cerrojos y dónde se corta), telefonía, banderas con su variable, publicación vigente, freno con «Soltar», permisos |
| `‹F›/studio-voice-profiles.ts` + `.scss` | 351 + 28 | «Perfil de voz»: lista con cuatro ojos y la previsualización con la negativa del servidor junto a su campo |
| `‹F›/studio-voice-profile-dialog.ts` + `.scss` | 359 + 24 | Crear o revisar un perfil (el teléfono de un destino nuevo solo vive en el formulario) |
| `‹F›/studio-voice-publications.ts` + `.scss` | 393 + 18 | «Publicación»: partes, acciones por `actions`, evidencia, cadena |
| `‹F›/studio-voice-calls.ts` + `.scss` | 288 + 20 | «Llamadas»: registro sin datos personales, eventos, corte de emergencia |
| `‹F›/studio-voice-call-dialog.ts` | 139 | Pedir una saliente a un canario de voz (o a un contacto con el alcance `contacts`) |
| `‹F›/studio-voice-costs.ts` + `.scss` | 135 + 20 | «Costes y métricas»: medidores con texto y `role=meter`, 7 días en tabla |
| `‹F›/studio-voice-contacts.ts` + `.scss` | 181 + 4 | «Contactos» (fase 4b) |
| `‹F›/studio-voice-contact-dialog.ts` | 229 | Alta, cargar/confirmar/revocar el consentimiento, excluir y consulta al RNE |
| `‹F›/studio-voice-lab.ts` + `.scss` | 210 + 14 | «Laboratorio hablado» (esqueleto: cerrojos, historial y veredicto; ningún botón que gaste) |

**Nuevos — pruebas y ayudas**

| Fichero | Pruebas |
|---|---|
| `‹F›/studio-voice-parse.spec.ts` | 26 |
| `‹F›/studio-voice-parity.spec.ts` | 14 |
| `‹F›/studio-voice-session.spec.ts` | 16 |
| `‹F›/studio-voice-page.spec.ts` | 9 |
| `‹F›/studio-voice-status.spec.ts` | 5 |
| `‹F›/studio-voice-profiles.spec.ts` | 5 |
| `‹F›/studio-voice-publications.spec.ts` | 3 |
| `‹F›/studio-voice-calls.spec.ts` | 4 |
| `‹F›/studio-voice-costs.spec.ts` | 3 |
| `‹F›/studio-voice-contacts.spec.ts` | 4 |
| `‹F›/studio-voice-lab.spec.ts` | 3 |
| `‹F›/studio-voice-guards.spec.ts` | 5 (texto hostil, sumideros de HTML, almacenamiento y consola, hojas) |
| `‹F›/studio-voice-fixtures.testing.ts` (54) y `‹F›/studio-voice-ui.testing.ts` (75) | ayudas |

**Modificados**

| Fichero | Diff | Qué |
|---|---|---|
| `src/app/app.routes.ts` | +11 | La ruta hija `marketing/ultron-studio/voz`, diferida, `AuthGuard` + `ultron.view`, tras `ultronStudioPreviewEnabled` |
| `‹F›/ultron-studio-page.ts` | +3 | **Solo la plantilla**: el enlace «Voz y telefonía» (`data-voz-enlace`) tras «Integraciones» |
| `‹F›/ultron-studio-page.spec.ts` | +5 | El enlace, dentro del `it` que ya comprueba el de «Integraciones» |
| `src/app/models/backend-permission-catalog.ts` | +14 | **Regenerado** (`permissions:export`), no editado a mano |
| `src/app/models/permissions.enum.ts` | +34 | Las 6 llaves nuevas, con etiqueta y ayuda literales del `PermissionCatalog` en el JSDoc |
| `src/app/services/access-control.service.ts` | +7 | El Administrador del CRM excluye `ultron.voice_publish`, `ultron.voice_lab_run`, `telephony.call_place` y `telephony.contacts_manage` (patrón del H6, como `CrmPermission::byRole()`) |
| `src/app/models/permission-contract.spec.ts` | +22 −2 | De 12 a 15 llaves `ultron.*`; una prueba nueva de las 3 `telephony.*` con los valores por defecto del backend |

**Intactos:** `studio-voice-panel.ts` (la pestaña «Voz» del H6, idéntico a `HEAD`), `ultron-studio.scss` (su huella la fija `studio-integrations-sheets.spec.ts`), `package.json` y `package-lock.json` (sin cliente de ElevenLabs en este paso), `src/app/modules/voice/**` (no hizo falta: todo vive en `studio-voice-*`, como el H6).
**Fuera del CRM:** este informe. Los generadores del fixture están en el scratchpad de esta sesión (§5).

---

## 2. Qué hace

**Página `marketing/ultron-studio/voz`** (enlace en la barra del tablero, como «Integraciones»):
- **Cabecera, siempre:** la fase que calcula el servidor («Fase 1 Todo apagado»), el freno («Freno suelto», «Freno echado» o «sin leer: cuenta como echado») y, con la constante del código cerrada, «Hoy no se hace ninguna llamada…».
- **Fases de carga:** cargando; `unavailable` (backend sin `telephonyState` en la ruta 45, o un 404 sin código: «El servidor todavía no tiene voz y telefonía (Hito 8)», sin pedir nada más); sin permiso (con el permiso que pide el 403); error con «Reintentar».
- **Siete pestañas** con flechas, Inicio y Fin; solo se monta la abierta y cada sección se lee una vez.

**Estado.** El elemento principal es **la línea**: los siete cerrojos en el orden en que los cruza una llamada (código → ElevenLabs → Twilio Voice → publicación → freno → entrantes → salientes), con el tramo cortado desde el primero cerrado («La línea se corta aquí»). Debajo: la telefonía real (número, subcuenta, alcance, techos, horario, directorio de destinos), las banderas con su variable, la publicación vigente por parte (agente «…» + 4, compilación, verificación de la entrada), el freno con «Soltar» (nota obligatoria) y lo que puede la cuenta.

**Perfil de voz.** Lista con su estado y cuatro ojos (Root envía el suyo y revisa el de Ana; nunca al revés); «Nueva revisión» (la aprobada no se edita); el aviso de IA se lee, no se escribe. **Previsualizar** compila sin publicar y enseña la negativa del servidor **tal cual**, con su título, su mensaje y su campo («Dónde: Saludo de las entrantes»), y marca ese campo en el perfil.

**Publicación.** Lo que rige en cada parte, la ventana y la puerta del laboratorio; publicar envía la huella que se VIO al compilar; promover, la evidencia que se VIO; revertir y apagar, con motivo. Evidencia del canario con sus 7 criterios y la cadena con su número de entrada.

**Llamadas.** Sin datos personales: «Móvil de pruebas 1 · ••••4821»; estado, sello (`applied`, `off`, `fallback`, `inherited`), verificación, motivo de la denegada (con el texto de la puerta de ENTRADA) o de la cancelada; eventos sin transcripción; «Cortar la llamada» solo con la llave y la llamada en curso. «Pedir una llamada» solo con la llave, la constante abierta, la bandera de salientes y el freno suelto; el rechazo de la puerta enseña todos sus motivos en orden.

**Costes y métricas.** Medidores con su texto al lado y `role=meter` (con techo 0 no hay medidor: se dice «Techo 0…», porque un medidor vacío engañaría); los 7 días en una tabla que se desplaza en su caja.

**Contactos (4b).** Alta (el teléfono solo en el formulario), cargar el consentimiento con su evidencia, confirmarlo (otra persona), revocarlo (fuente y evidencia), excluir y la consulta manual al RNE; cada botón por `actions`.

**Laboratorio hablado (esqueleto).** Los cuatro cerrojos en su orden, presupuesto y cupos, la puerta de publicación, el historial, terminar y el veredicto de otra persona (el rechazo con motivo). **Ningún botón que gaste**: abrir una sesión llega con el micrófono (§8).

**Transversal:** botones solo por `can`/banderas/`actions` y, si no hay botón, el motivo en texto; foco atrapado en los diálogos (`ib-dialog`, `ib-reason-dialog`); 390 px con las tablas, el prompt compilado y las pestañas desplazándose en su propio contenedor; ningún sumidero de HTML; ningún teléfono en URL, almacenamiento ni consola (lo vigila `studio-voice-guards.spec.ts`).

---

## 3. Alineación con el backend real (E4-1 y E4-10)

### 3.1 E4-1: una sola bandera de publicar

`VoiceFlags` ya no viaja en un bloque: la sesión la **deriva** de lo que dice el servidor.
- `publishingEnabled` ← ruta 45 `publishing.enabled` (S8-b). Encendida ⇔ `code === null` (si no, no se enseña).
- `labEnabled` y `monitorEnabled` ← `GET admin/voice/status` `flags.lab` y `flags.monitor` (B8-b). **`flags.publishing` de la telefonía se ignora aunque llegue** (lo fija una prueba de la sesión y la mutación M4).
- Entrantes, salientes y alcance ← ruta 45 `telephonyState`; la constante del código ← la telefonía o, sin `telephony.view`, la política del H6 (`calls_disabled_in_code` va primera si está cerrada).
- El texto de la bandera dice `ULTRON_STUDIO_VOICE_PUBLISHING_ENABLED`.

### 3.2 E4-10: cada ruta del servicio contra `routes/*.php`

**Telefonía (`/api/admin/voice`, 13 de B8-b):** las 13 están en el servicio con su método y su ruta. Corregido en este paso:
- **sobre de primer nivel** (`{ok:true, …}`, sin `data`): `readTelephonyEnvelope` (acepta también `data`);
- `perPage` en camelCase (hasta 100) en `calls` y `contacts`;
- `POST contacts/{uuid}/consents/confirm` `{clientRequestId}` (antes lo mandaba por `consents` con `step`);
- `POST contacts` lleva `number` (no `phone`);
- `POST contacts/{uuid}/revoke` lleva `{source, evidence}` (el diálogo de revocar usa ahora el mismo formulario que cargar);
- `POST calls` lleva `target {kind: canary|contact, uuid}`;
- `POST calls/{uuid}/hangup` **sin cuerpo** y con `{ok, result}` (`hung_up`, `canceled`, `not_live`, `no_carrier_id`); el diálogo ya no pide nota, porque el servidor no la guarda.

**Studio (`/api/admin/ultron-studio`, 21 de S8-b y LAB8 + la 45):** todas con su método y su ruta; añadidas `GET voice/profiles/{uuid}` y `GET voice-lab/sessions/{uuid}`; `GET voice/brakes` pasa a `?limit≤50`.

### 3.3 Lo que NO está alineado (las respuestas de las demás rutas) — paso 3

| Ruta | Forma real (S8-b §8 / LAB8 / B8-b) | Lo que lee hoy el CRM |
|---|---|---|
| `GET voice/profiles` | paginador de **resúmenes sin contenido** con `actions` | `{template, profiles}` con contenido (la plantilla no la manda ninguna ruta: §9, Q-2) |
| `GET voice/profiles/{uuid}` | `VoiceProfileDto`, destinos `{alias, last4}` | destinos `{key, alias, masked}` |
| `POST voice/profiles` | `{clientRequestId, label 3–80, basedOn?, content}`; el servidor pone formato, idioma, aviso y privacidad | mando además `aiNotice` y `privacy.retentionDays` en `content` (por confirmar si los rechaza) |
| `POST voice/compile-preview` | `{specHash, spec, labVerdict, deployable.missing}` | la especificación aplanada |
| `GET voice/publications` | `{state, publishing, history}` | `{head, actions, window, labGate, entries}` |
| `POST voice/publish` | `{clientRequestId, versionUuid, profileUuid, note?}` → 201 `{entries, agent, number}` | mando además `specHash` (§9, Q-3); leo `{replayed, entry}` |
| `POST voice/promote`, `/rollback`, `/off` | cuerpos como los míos; respuesta a confirmar | `{replayed, entry}` |
| `GET voice/canary-evidence` | `VoiceCanaryEvidenceDto` de S8-b | el de mi propuesta |
| `GET voice/brakes` | `{state, brakes}` (de `VoiceBrake::list`) | un paginador |
| `POST voice/brakes/{uuid}/release` | `{clientRequestId, note 5–300}` → `{brakeUuid, releaseUuid, releasedAt, state}` | el cuerpo ya va como el real (nota 5–300, comprobada sin pedir nada); leo `{replayed, brake}` |
| `voice-lab/*` (6) | LAB8 §2 | el de mi propuesta |
| `GET calls`, `GET calls/{uuid}` (`{ok, call}`), `GET metrics`, `GET contacts`, las respuestas de las escrituras de contactos y de `POST calls` | B8-b | las de mi propuesta |

**Qué pasa hoy con el backend real:** la cabecera, la línea y la pestaña «Estado» funcionan; en las demás pestañas, la primera lectura devuelve «No se pudo leer…: la respuesta del servidor no tiene la forma esperada» (falla cerrado, sin enseñar nada inventado, con «Reintentar»). Los botones que dependen de esas lecturas no aparecen. **Para el paso 3:** que S8-b, LAB8 y B8-b exporten un `backend-voice.testing.ts` con sus serializadores reales (como el H6), y alinear lectores y vistas contra él.

---

## 4. Permisos (D8-22)

- **Procedimiento del H6:** `php artisan permissions:export --path=…` desde el backend en `61513a1` → copiado tal cual a `src/app/models/backend-permission-catalog.ts`. sha256 del export y de la copia: `52b8552dec089400…` (los dos).
- **Llaves nuevas:** `ultron.voice_manage`, `ultron.voice_publish`, `ultron.voice_lab_run`, `telephony.view`, `telephony.call_place`, `telephony.contacts_manage` (+ `ultron.approve`, que ya existía: las 7 filas de D8-22). Super Admin todas; **Administrador: `telephony.view` y `ultron.voice_manage`**, como la tabla de D8-22.
- **El enum** las nombra con la etiqueta y la ayuda literales del `PermissionCatalog`; `permission-contract.spec.ts` lo comprueba («ningún permiso del CRM es inventado» y «ninguno del backend queda sin representar» siguen en verde).
- **La ruta 45 no manda `can`.** Mientras no lo haga, la sesión toma las 7 llaves de la **sesión del CRM** (`AuthService.hasPermission`, los permisos que dio el servidor al entrar) solo para enseñar botones; el servidor autoriza cada petición (`AuthorizationMap`). «Estado» lo dice en texto. Ver §9, Q-1.

---

## 5. El fixture `testing/backend-voice.testing.ts`

- **Generado** por `gen-backend-voice.py` y `compile-real.php` (scratchpad de esta sesión: `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body-backend/8ee090c7-72e3-4e92-b470-2a1a6cbe18d1/scratchpad/fe8/gen/`). Se reproduce con `python3 -I gen-backend-voice.py <backend> <crm> compile-real.json`.
- **`sets`** salen de fuentes que el CRM no escribe: TEST8-a (`state-transitions`, `outbound-gate-cases`, `policy-tables`, `compiler-cases`), las constantes y los CHECK de las dos migraciones de DB8 y el compilador real. El orden de los motivos de entrada se deduce del fixture por orden topológico, no del CRM, y coincide.
- **`compiler`**: la versión, la plantilla y su huella, el aviso de E1-3, los topes, el patrón de caracteres y **las 69 negativas reales** de `compiler-cases.json` (`expected.realCompiler`).
- **`examples`**: la ruta 45, el estado de la telefonía y colgar tienen ya la **forma real**; la previsualización sale del **compilador real** (`specHash 61c8b0d8…`, la misma con propósito `lab`), sin los `ref` de los destinos; los demás son propuesta FE8 (§3.3).
- **Paridad** (`studio-voice-parity.spec.ts`): conjuntos y órdenes; cada negativa real se lee y su campo va a un sitio del formulario; el patrón de caracteres de PHP y el del CRM comparados **por comportamiento** sobre 768 + 15 caracteres (el empaquetador normaliza los escapes de `.source`); las 7 llaves en el catálogo; ningún teléfono, huella ni transcripción en el fixture.

---

## 6. Build de producción (árbol final)

`node_modules/.bin/ng build --configuration production --output-path=<scratchpad>` (sin `build:prod`, así `build-info.ts` no cambia). La base `73dbcd3` se construyó igual, con el árbol limpio, al empezar.

| Chunk | Base `73dbcd3` | Ahora |
|---|---|---|
| Inicial total | 828,64 kB (172,27 kB transferidos) | 829,07 kB (172,34 kB) |
| **`studio-voice-page`** (nuevo, diferido) | — | **264,34 kB (58,65 kB transferidos; 71 135 B con gzip -9)** |
| Compartido nuevo (integraciones + voz) | — | 39,78 kB (12 669 B con gzip -9) |
| `studio-integrations-page` | 162,46 kB | 122,92 kB |

- El chunk compartido de 24 kB que ya cargaba el Studio cambia de huella (+9 B: el enum de permisos).
- **38 avisos antes y después, con los mismos nombres** (`diff` vacío); solo cambia la cifra del presupuesto inicial. Ninguna hoja nueva da aviso: la mayor, `studio-voice-status.scss`, pesa menos de 3 kB de fuente.
- Para quien mida (PERF8): el chunk de voz carga las siete pestañas; con `@defer` por pestaña bajaría lo que se descarga al abrir «Estado». No lo hice: medir primero era el encargo.

## 7. Mutaciones por muestreo

Cada una cambia una cadena exacta del código de producción, corre la spec que la debería tumbar y restaura el fichero. Huella del árbol (`git status` + contenido): **`6ac1984cfced1f27` antes y después**. Script: `…/scratchpad/fe8/mutar.py`.

| # | Mutación | Fichero | Resultado |
|---|---|---|---|
| M1 | «Colgar» se ofrece sin mirar el estado de la llamada | `studio-voice-parse.ts` | cazada (1 de 26) |
| M2 | I-1: el `clientRequestId` siempre nuevo | `studio-voice-session.ts` | cazada (1 de 16) |
| M3 | Un backend del H6 se toma por H8 | `studio-voice-parse.ts` | cazada (1 de 26) |
| M4 | E4-1: publicar sale de la telefonía y no de la ruta 45 | `studio-voice-session.ts` | cazada (1 de 16) |
| M5 | Publicar encendido aunque traiga código | `studio-voice-parse.ts` | cazada (1 de 26) |
| M6 | La saliente con la forma vieja (`canary_registry`) en el cable | `studio-voice-api.service.ts` | cazada (1 de 16) |
| M7 | El motivo de una llamada sin mirar su dirección | `studio-voice-text.ts` | cazada (1 de 4) |
| M8 | Un teléfono en claro donde va enmascarado | `studio-voice-parse.ts` | cazada (1 de 26) |

Antes, en la primera pasada de este paso (sin la alineación de E4), otras 3 (colgar, I-1 y la dirección del motivo) también se cazaron.

## 8. Lo que depende del paso 3

- **El cliente de ElevenLabs** (versión exacta en `package.json`/`package-lock.json`, con la revisión de la cadena de suministro de SEC8), **el micrófono** y la **CSP** `connect-src` (hosts por confirmar, WebRTC incluido): no están; el laboratorio no tiene botón de abrir sesión. `createLabSession` existe en el servicio y su lector **no lee la URL firmada** (no sale de la respuesta).
- **La reexportación del fixture** desde el backend real y la alineación de las respuestas de §3.3.
- **Lo que la ruta 45 no trae:** la deriva del agente y del número (D8-21, E2-3) y el motivo del freno abierto. La pestaña «Estado» ya no los enseña (la sección de deriva salió); el motivo del freno sale de `GET voice/brakes` cuando esté alineado.
- **El E2E** con la pila real y los simuladores (TEST8) y las capturas a 1 440 y 390 px en Chrome: no las hice en este paso.

---

## 9. Peticiones y dudas

- **Q-1 (S8-b).** `can` en la ruta 45: las 7 llaves de D8-22 como booleanos, como `integrations/status` del H6. Sin él, el CRM enseña botones con los permisos de la sesión (§4).
- **Q-2 (S8-b).** La plantilla vigente para el formulario (aviso, `greetingMax`, `textMin`, huecos con su tope, herramientas con su descripción, duración mínima y tope del servidor, retención máxima, destinos máximos): no la manda ninguna ruta. Propuesta: en `GET voice/profiles` (como hice) o una ruta propia de lectura.
- **Q-3 (S8-b).** Publicar no comprueba la huella que la persona VIO (`specHash`), como hacía el H5 con `previewHash`. Hoy, si la versión o el perfil cambian entre previsualizar y publicar, se publica lo nuevo. Propuesta: aceptar `specHash` y responder 409 si no cuadra.
- **Q-4 (B8-b).** `GET admin/voice/status` enseña el `providerAgentId` entero en `publication`; el Studio lo enmascara («…» + 4). El CRM no lo lee.
- **Q-5 (B8-b).** Colgar no guarda nota ni lleva `clientRequestId`; es idempotente por naturaleza. Si se quiere una nota en el libro, hace falta en el backend.
- **Q-6 (lead).** Las 8 dudas de diseño que tomé (§10) y la decisión de no tocar `studio-voice-panel.ts` (la pestaña del H6) en este paso.

## 10. Decisiones (D-F)

- **D-F1.** Todo vive en `studio-voice-*` dentro del módulo del Studio, como el H6; `src/app/modules/voice/**` no hizo falta.
- **D-F2.** El contenido de un perfil es un **conjunto cerrado**: un campo de más no se enseña (se aprueba lo que se ve).
- **D-F3.** Un freno abierto con un motivo desconocido se enseña con su código (nunca se esconde).
- **D-F4.** Dos guardas de texto: `pintable` (etiquetas y notas) y `sinTelefono` (lo que dice o lee la voz y la evidencia), esta última con las copias exactas de `StudioLabPersonalData` (7 dígitos con separadores cuentan como teléfono, también una fecha, como en el servidor).
- **D-F5.** La firma del cuerpo para reutilizar el `clientRequestId` (I-1) es un **resumen de 53 bits**, no el JSON: un teléfono escrito no se queda en claro en la sesión mientras se espera (mejora sobre el H6).
- **D-F6.** Importes en micro-US$ enteros; por debajo de un centavo, «menos de US$ 0,01».
- **D-F7.** La dirección de una llamada viaja como `inbound`/`outbound` (DB8) y se enseña «Entrante»/«Saliente».
- **D-F8.** Espacios entre elementos en línea con `&ngsp;`: Angular quita los espacios entre etiquetas y un lector de pantalla leía «Fijo de recepción+57…» (lo cazó una spec).
- **D-F9.** El motivo de una entrante denegada usa el texto de la puerta de ENTRADA («Quien llama…») y el de una saliente, el de salida (lo cazó una spec; mutación M7).
- **D-F10.** La pestaña «Voz» del H6 (`studio-voice-panel.ts`) queda igual: su lector ignora las claves nuevas de la ruta 45.

## 11. Para security y para el tester

**Security:**
- Teléfonos: solo viajan en `POST voice/profiles` (destinos nuevos) y `POST admin/voice/contacts`; nunca en una URL, señal de la sesión, almacenamiento ni consola; el diálogo se cierra con ellos y no quedan en el DOM (lo comprueban specs).
- Ningún `innerHTML`, `bypassSecurityTrust`, `localStorage`, `sessionStorage` ni `console.` en `studio-voice-*` (spec de guardas); el texto hostil del servidor se pinta como texto.
- Sin `can` del servidor, los botones salen de los permisos de la sesión: es solo presentación; la autorización es del servidor.
- La URL firmada del laboratorio no se lee en este paso.

**Tester, flujos críticos (E2E del paso 3):** la página con un backend del H6 y con el H8 en la fase 1 (línea cortada en el código, ningún botón que gaste); perfil a cuatro ojos (crear como Root, aprobar como Ana); previsualización con una negativa por campo; soltar el freno con nota; pedir una saliente a un canario con la puerta cerrada (motivos en orden) y abierta en la copia del banco; cortar una llamada en curso; contacto con consentimiento cargado por una persona y confirmado por otra; teclado en pestañas y diálogos (Tab, Mayús+Tab, Escape); 390 px sin desplazamiento horizontal.

## 12. No verificado

- La pila real: ninguna spec ni prueba usa el backend vivo; lo alineado (§3.1, §3.2) se comprobó leyendo el código de S8-b, LAB8 y B8-b.
- Chrome real a 1 440 y 390 px y lectores de pantalla reales (la semántica se comprobó en specs).
- La suite completa del CRM fuera de `ultron-studio` y `models`.

---

# §FE8-3 · paso 3 (08-11-2026) — A MEDIAS: cortado por límite de uso

**Árbol:** el mismo worktree y rama, sobre `26ef142`. **Sin commit.** Backend: solo lectura (nada escrito en el repo del backend salvo este informe).

## Estado real

| Pieza | Estado |
|---|---|
| Formas reales (tipos, lectores, servicio, sesión) | **Hecho.** `tsc -p tsconfig.app.json` sin errores |
| Las 7 pestañas y los diálogos con las formas reales | **Hecho.** `ng build --configuration production` salió con 0: 39 avisos, 38 de la base y 1 mío (NG8102 en `studio-voice-profiles.ts:193`), **ya corregido pero sin volver a construir** |
| S8-c: `can`, `template` y `expectedSpecHash` con el 409 `spec_changed` | **Hecho** en la sesión y en las pestañas |
| Laboratorio hablado con el cliente oficial | **Hecho** (adaptador diferido + flujo completo) |
| Specs | **A MEDIAS: `ng test` NO COMPILA ahora.** Reescritos: `parity`, `parse`, `session`, `status` y `guards`. Faltan los de `profiles`, `publications`, `calls`, `costs`, `contacts`, `lab` y `page` (citan nombres viejos). **Ninguno se ha ejecutado** en este paso |
| `tsc -p tsconfig.spec.json`, mutaciones, tamaños finales y capturas | **No hechos** |

## Formas: capturadas, compuestas y derivadas

- **Mecanismo del H6:** `ultron-studio:export-fixtures` → `StudioIntegrationsExport::shapes()`. Para la voz no hay exportador. **Para hacerlo oficial haría falta en el backend:**
  - un `app/Services/UltronStudio/StudioVoiceExport.php` con `shapes()`: datos sintéticos fijos con `SYNTHETIC_APP_KEY`, los mismos presentadores de S8-b, LAB8 y B8-b, y las rutas del CRM;
  - un séptimo fichero, `backend-voice.testing.ts`, en `ExportStudioFixturesCommand`;
  - su prueba en `ExportStudioFixturesCommandTest`.
- **Mientras tanto, CAPTURADAS:**
  - `git archive` de `af6bd805` en mi scratchpad, con `vendor` clonado (APFS); el autoload apunta a la copia (comprobado con `ReflectionClass`);
  - un capturador en `TestCase::call()` de la copia, nunca del repo;
  - las pruebas Feature de voz, laboratorio y telefonía del propio backend: **31/31 ficheros OK, 642 respuestas reales**.
- Generador: `…/scratchpad/fe8/gen/gen-backend-voice-3.py`. Scratchpad: `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY-Iron-Body-Crm/bee5b9e8-7d99-4d6d-875a-94ea20871824/scratchpad`; se purga a los 3 días.
- **`testing/backend-voice.testing.ts`:** 75 ejemplos, cubren **35 rutas** (la 45, 21 del Studio y 13 de telefonía). `provenance` dice de qué prueba sale cada uno:
  - **71 capturados**;
  - **2 compuestos** de capturas: la ruta 45 con el freno echado y con una cadena publicada;
  - **2 derivados** del código: el 202 y el 200 `duplicate` de `POST admin/voice/calls`, porque ninguna saliente pasa la puerta en el banco (E8-3).
  - `sets` y `compiler` se conservan del paso 2 y cuadran con lo capturado: plantilla `6914e2e1…`, aviso, topes y variables.
- B8-c tenía cambios sin confirmar en la telefonía. Las claves de `metrics()` se comprobaron iguales; no se recapturó sobre su HEAD final.

## Lo que cambió (ficheros, `‹F›` = `src/app/modules/ultron-studio`)

- **Nuevo:** `‹F›/studio-voice-lab-conversation.ts`.
- **Reescritos o modificados:**
  - capa de datos: `‹F›/studio-voice-{api.types,parse,api.service,session,text}.ts`;
  - pestañas: `‹F›/studio-voice-{status,profiles,profile-dialog,publications,calls,call-dialog,costs,contacts,contact-dialog,lab}.ts`;
  - hojas: `‹F›/studio-voice-{lab,costs}.scss`;
  - pruebas: `‹F›/studio-voice-{fixtures,ui}.testing.ts`, `‹F›/testing/backend-voice.testing.ts` y los 5 specs reescritos.
- **Dependencia:** `package.json` y `package-lock.json` (+14 paquetes).
- **Entorno:** el `node_modules` de este árbol era un enlace en cadena (H8 → H7 → `thermal-receipts`). Lo sustituí por un **clon APFS propio** de ese directorio y solo en él instalé el cliente. Los árboles ajenos no se tocaron; está ignorado por git.

## Cliente de ElevenLabs

- **`@elevenlabs/client` 1.30.0 exacta**, publicada el **21-10-2026T22:20Z** (fuente: `npm view @elevenlabs/client time`).
  - La 1.31.0 (26-10) no cumple las 2 semanas.
  - Licencia MIT; integridad `sha512-3JCmanYb…` (comprobada contra el tarball).
- **Instalación:** `--save-exact --before=2026-10-25T00:00:00Z --ignore-scripts`. Los transitivos nuevos se publicaron todos antes del 25-10; el más reciente, `livekit-client` 2.23.0, el 16-10.
- **Carga diferida:** solo `import()` en el adaptador. Chunk propio de **642 567 B (168 556 B con gzip -9)**, que solo lo importa el de la voz. El de voz pasa de 264 kB a **303,7 kB** (82 077 B con gzip -9).
- **Flujo:** cerrojos, presupuesto y reserva visibles → **permiso de micrófono ANTES de gastar** → `POST voice-lab/sessions` con `acceptedMaxCostMicros` = `estimate.reservedMicros` → la URL firmada pasa directa a `Conversation.startSession({signedUrl, connectionType: 'websocket'})` → colgar o silenciar → contar lo observado → veredicto de otra persona con la `specHash` visible.
  - **D-F11:** el micrófono va antes de crear la sesión, no después de la URL como decía el encargo. Así, con el micrófono negado no se gasta nada.
- **La URL firmada** no entra en ninguna señal, ni en el almacenamiento, ni en la consola, ni en la URL del navegador (lo fija `studio-voice-guards.spec.ts`).
  - **Límite que no controla el CRM:** si el WebSocket falla, el propio navegador puede escribir en la consola de desarrollo la URL que falló. Es de un solo uso y caduca en ≤ 15 min.

## CSP: vive en Nginx (no en `src/index.html`). No la toqué. Para OPS8

Con la región `default`, lo mínimo es:
- `connect-src … wss://api.elevenlabs.io`. El SDK abre la URL firmada tal cual más `&source=js_sdk&version=1.30.0`. Con la región `eu`: `wss://api.eu.residency.elevenlabs.io`. LiveKit no hace falta: se fuerza WebSocket.
- `script-src` (o `script-src-elem`) `blob: data:` para los AudioWorklet del SDK.
  - Lo estricto sería alojar los worklets: copiar `@elevenlabs/client/worklets/*` como recursos en `angular.json` y pasar `workletPaths`. `angular.json` está fuera de mi lista: **decisión del lead**.
- Si hay `Permissions-Policy`: `microphone=(self)`.

## Para security (nuevo)

- `POST voice/compile-preview` manda en `spec.transfer.destinations[].ref` la **huella `voice-phone`** de cada destino.
  - El CRM no la lee, pero llega al navegador de quien tiene `ultron.voice_manage`.
  - Contradice el espíritu de D8-17 y lo que daba por hecho el paso 2. Propuesta para S8: quitar `ref` de la previsualización.

## Lo que queda (en orden)

1. Reescribir los specs de `profiles`, `publications`, `calls`, `costs`, `contacts`, `lab` y `page`, hasta que `ng test` compile y pase.
2. `tsc -p tsconfig.spec.json`.
3. Rebuild: confirmar 38 avisos y los tamaños.
4. Mutaciones por muestreo.
5. 390 px y capturas en Chrome.
6. Para el E2E con TEST8-b: el laboratorio con el emisor del simulador, publicar y promover con `spec_changed`, y los contactos a cuatro ojos.

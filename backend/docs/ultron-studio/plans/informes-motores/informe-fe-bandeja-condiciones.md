# Informe de frontend · La bandeja asistida y las cuatro condiciones (contactWithheld, meta.withheld, fuente de eventos, 409)

**12-11-2026 · `frontend` · árbol `_worktrees/ultron-h8/Iron-Body_Front`, rama `feat/ultron-studio-h9`, sobre HEAD `d16e26a`.** No he hecho commit, push, `build:prod` ni despliegue. No he lanzado la batería completa del CRM. No he tocado los fixtures que se sincronizan (`testing/sync-h9-fixtures.mjs` ni `testing/fixtures/`), ni nada fuera de `src/app/modules/commercial-automation/` salvo la línea de `supervision-page.ts`.

Marcas: **[C]** comprobado ejecutando · **[L]** leído en el código, sin ejecutar · **[S]** suposición.

## Veredicto

Los seis puntos del encargo están hechos y probados con las clases reales del módulo: sesión real, componente real y `HttpTestingController`, sin dobles. **Hay 17 casos nuevos. Los 17 fallan con los fuentes de HEAD y pasan con el cambio [C].** Los dos tsc, el control de literales y los specs del módulo y de supervisión salen en verde [C].

Durante el trabajo, el backend añadió a su informe la §5, «Lo que tiene que hacer el CRM». Según el encargo, esa sección manda, y he adoptado sus textos. Las diferencias con los textos del encargo están en §3.

La bandeja **no pide** el estado (§6.1, decidido así por el lead): solo sabe que la fuente de eventos está apagada si el «Panel» ya leyó el estado en esa visita. `meta.withheld` cubre el caso. Los textos finales son los de §3 (con las dos decisiones del lead de §9).

## 1 · Ficheros y líneas tocados [C: `git diff -U0`, `git status --short`]

11 ficheros, +386 / −18 (con las ampliaciones de §8 y §9). Las líneas son las del árbol final.

| Fichero | Líneas | Qué |
|---|---|---|
| `commercial-automation-api.types.ts` | `:1138-1156` | `AssistedWithheldReason`, `ASSISTED_WITHHELD_REASONS` (en el orden del servidor) y `AssistedContactWithheld` (= motivo \| `'unrecognized'`) |
| | `:1177-1181` | `AssistedJobDto.contactWithheld: AssistedContactWithheld \| null` |
| | `:1185-1195` | `AssistedQueueDto { items, discarded, withheld: number \| null }` |
| | `:1333` | `'enrollment_closed'` en `CA_ERROR_CODES` |
| `commercial-automation-parse.ts` | `:3`, `:6`, `:9` | importaciones |
| | `:1356` | `readAssistedJob` lee `contactWithheld` |
| | `:1361-1370` | `retencion()`: ausente o `null` da `null`; un motivo conocido se queda tal cual; **cualquier otro valor** da `'unrecognized'` |
| | `:1375-1386` | `readAssistedQueuePage(body)`: `data` y `meta.withheld`. Solo vale un entero ≥ 0; si no, `null` |
| `commercial-automation-api.service.ts` | `:12`, `:72`, `:430-435` | `assistedQueue()` devuelve `AssistedQueueDto` por `readAssistedQueuePage`. `claimAssisted()` ya leía la fila con `readAssistedJob`, así que el `data` de E12 trae `contactWithheld` sin tocarlo |
| `commercial-automation-session.ts` | `:13`, `:159`, `:202` | la sección de la bandeja usa `AssistedQueueDto` |
| | `:696-714` | `claimAssisted`: con un 409 `not_in_queue`, `already_claimed` o `enrollment_closed`, olvida la clave **y relee la bandeja** con el último filtro. Con otro 409, solo olvida la clave, como antes |
| `commercial-automation-text.ts` | `:2` | importación |
| | `:234-262` | `ASSISTED_WITHHELD_TEXTS`, `assistedWithheldText(n)` y `TEXTO_BANDEJA_SIN_EVENTOS` |
| | `:378` | `enrollment_closed` en `STATE_409_TEXTS` |
| `ca-assisted.ts` | `:1`, `:6`, `:9-23` | importaciones |
| | `:37-44` | documentación de la clase |
| | `:79-84` | encima de la lista: la línea de la fuente apagada y la de `meta.withheld > 0` |
| | `:86-90` | lista vacía: si `withheld > 0`, ya no dice «No hay trabajos asistidos vigentes» |
| | `:99-101` | el aviso de la fila retenida |
| | `:106`, `:120` | la ficha y el guion pasan por `persona(j)` / `guion(j)`, que dan `null` con un motivo |
| | `:170-176`, `:202-219` | `textoSinEventos`, `sinEventos` (computed), `persona`, `guion`, `textoRetenido` y `retenidos` |
| `ca-assisted.spec.ts` | `:9`, `:11-13`, `:25-31`, `:121-264` | 16 casos nuevos (describe «lo que el servidor retiene») |
| `commercial-automation-api.service.spec.ts` | `:394-431` | 1 caso nuevo: el lector por el cliente HTTP (E11 y E12) |
| `supervision/supervision-page.ts` | `:168` | «Motor» pasa a «Modelo del agente anterior». Solo esa etiqueta |
| `ca-panel.ts` | `:197` | el recuento vivo `live.assistedQueue` deja de llamarse «en la bandeja asistida» y pasa a «asistidos sin resultado del personal»; se le añade `data-ca-vivo-asistidos`. El número no cambia (§8) |
| `ca-panel.spec.ts` | `:99-112` | 1 caso nuevo: la etiqueta de ese recuento |

## 2 · Lo que pedía el encargo, punto por punto

1. **Leer los campos nuevos** [C]
   - `contactWithheld` es un conjunto cerrado. Ausente o `null` es `null`. Un valor desconocido (`'legal_review'`, `'BRAKE'`, `''`, `true`, `0`, `{…}`) es `'unrecognized'`, es decir, **retenido**.
   - `meta.withheld` ausente, o `meta` sin esa clave, `-1`, `'2'` o `1.5`, es `null`.
   - Pruebas: `commercial-automation-api.service.spec.ts:394` y `ca-assisted.spec.ts` («meta.withheld ausente (o ilegible)»).
2. **El aviso en la fila** [C]
   - Es un `div.lab-alert.is-warn` con `role="status"` y `data-ca-asistido-retenido="<motivo>"`. Empieza por «**Contacto retenido.**» y sigue con el motivo, así que no depende solo del color.
   - Con un motivo, la plantilla no ofrece ni la ficha ni el guion **aunque el servidor los mandara**. Lo prueba un DTO que trae `person` y `script` junto con el motivo.
   - «Registrar el resultado» sigue disponible para quien lo reclamó.
   - El aviso sale siempre que `contactWithheld` no sea `null`, sea quien sea el reclamante. El servidor solo lo manda al reclamante [L: `AssistedQueue.php:485`], así que en la práctica solo aparece en «Reclamado por ti».
3. **`meta.withheld > 0`** [C]
   - Es una línea `lab-alert is-warn` con `role="status"` encima de la lista, también cuando la lista está vacía. Concuerda el singular (1) y el plural (3).
   - Con 0 o con `null` no se pinta nada. Nunca sale un «0».
   - Con `withheld > 0` y la lista vacía, el texto de vacío pasa a «Ahora no hay trabajos asistidos que se puedan ofrecer.» (§5.4 del backend: «No hay trabajos asistidos vigentes» dejaría de ser cierto). Con 0 o `null`, el texto de vacío es el de siempre.
4. **La fuente de eventos apagada** [C]
   - Es una línea `lab-alert is-info` con `role="status"`.
   - Sale solo si el estado que ya tiene la sesión dice `sources.commercialEvents === false`. Sin estado leído, o con la fuente encendida, no sale.
   - La bandeja **no pide** el estado: la prueba termina con `http.verify()` sin ninguna petición a `status` hecha por la bandeja. Ver §6.1.
5. **El 409 al reclamar** [C]
   - Con `not_in_queue`, `already_claimed` o `enrollment_closed`, la bandeja se relee con el **mismo filtro** (la prueba usa `unclaimed`) y la fila desaparece.
   - `enrollment_closed` está en `CA_ERROR_CODES` (`isCaErrorCode` da `true`) y en `STATE_409_TEXTS`.
   - El aviso de error de la sesión sigue enseñando el mensaje del servidor, como antes.
6. **Supervisión** [C: `git diff`]: `supervision-page.ts:168` dice «Modelo del agente anterior». Los specs de supervisión solo prueban etiquetas puras y ninguno monta la página, así que esa línea no tiene prueba propia (§5).

## 3 · Los textos finales

Fuente: `commercial-automation-text.ts:234-262` y `:378`, más `ca-assisted.ts:86-90` y `ca-panel.ts:197`. **Son los textos finales**, con las decisiones del lead de §9. Cada texto está comprobado palabra por palabra en las pruebas [C].

| Dónde | Texto | Origen |
|---|---|---|
| fila, `complaint_recent` | «Contacto retenido. Esta persona tiene una queja reciente: no la contactes. Registra el resultado que corresponda.» | **decisión del lead** (§9): el servidor comprueba una queja dentro de la ventana, no que sea posterior al reclamo. El encargo y la §5 decían «se quejó después de que reclamaras el contacto» |
| fila, `complaint_unknown` | «Contacto retenido. No se puede comprobar si esta persona se quejó (no hay datos): no la contactes. Registra el resultado que corresponda.» | **§5 del backend** (el encargo decía «…no la contactes hasta que se pueda.») |
| fila, `brake` | «Contacto retenido. Hay un freno abierto (la parada global, el del canal asistido o el de esta estrategia): no contactes a esta persona mientras siga abierto. Si ya hablaste con ella, registra el resultado.» | **§5 del backend** (el encargo decía «Hay un freno abierto en la automatización: no la contactes mientras siga abierto.»). La §5 avisa de que el texto del encargo no decía «registra el resultado» |
| fila, `expired` | «Contacto retenido. El plazo de este contacto ya pasó: no contactes a esta persona con esta decisión. Registra el resultado que corresponda.» | el encargo, con «no la contactes» cambiado a «no contactes a esta persona» porque «la» no tenía antecedente. La §5 lo escribe igual |
| fila, motivo desconocido | «Contacto retenido. El servidor retuvo este contacto: no contactes a esta persona. Registra el resultado que corresponda.» | ídem |
| encima, `withheld = 1` | «Hay al menos 1 contacto que no se ofrece ahora: hay un freno abierto, la persona se quejó hace poco o no se puede comprobar si se quejó.» | §5 del backend con «al menos» (**decisión del lead**, §9: es una cota inferior) y el singular concordado |
| encima, `withheld = N > 1` | «Hay al menos N contactos que no se ofrecen ahora: hay un freno abierto, la persona se quejó hace poco o no se puede comprobar si se quejó.» | §5 del backend con «al menos» (**decisión del lead**, §9). El texto del encargo («…por un freno o porque la persona se quejó hace poco») **no era exacto**: el servidor también cuenta `complaint_unknown` [L: `AssistedQueue.php:139-147`; `withheldByComplaint` retiene con `null`, `:358-362`] |
| encima, fuente apagada | «La fuente de eventos comerciales está apagada: los contactos comerciales y de cobranza sin reclamar no se ofrecen hasta que se encienda.» | el encargo, sin cambios (§6.3) |
| lista vacía con `withheld > 0` | «Ahora no hay trabajos asistidos que se puedan ofrecer.» (más « con ese filtro» fuera de «Todos») | nuevo, por la §5.4 del backend |
| 409 `enrollment_closed` | «La inscripción de ese trabajo ya se cerró.» | el mensaje que ya manda el servidor [L: `AssistedQueue.php:257`, `:277`] |
| Supervisión | «Modelo del agente anterior» | el encargo |

## 4 · Órdenes y su salida final [C]

Todas se lanzaron desde `Iron-Body_Front`, con el árbol final.

```
$ npx tsc -p tsconfig.app.json --noEmit                → exit 0, sin salida
$ npx tsc -p tsconfig.spec.json --noEmit               → exit 0, sin salida
$ node scripts/check-literals.mjs                      → "417 ficheros revisados · literales correctos", exit 0
$ IRON_BODY_BACKEND_DIR=…/Iron-Body/backend bash -c "nice -n 10 npx ng test --watch=false --include='src/app/modules/commercial-automation/**/*.spec.ts'"
    Test Files  20 passed (20)
         Tests  615 passed (615)                        → exit 0   (614 antes de la ampliación de §8)
$ IRON_BODY_BACKEND_DIR=…/Iron-Body/backend bash -c "nice -n 10 npx ng test --watch=false --include='src/app/modules/supervision/**/*.spec.ts'"
    Test Files  1 passed (1)
         Tests  21 passed (21)                          → exit 0
```

El proyecto no tiene lint configurado: no hay script `lint` ni configuración de ESLint [C: `package.json`].

### 4.1 · Línea base: los casos nuevos fallan sin el cambio [C]

Cómo lo comprobé:
1. Copié mis ficheros al directorio temporal de la sesión.
2. Devolví a `HEAD` los seis fuentes (`ca-assisted.ts`, `api.service`, `api.types`, `parse`, `session`, `text`).
3. Al `text.ts` de HEAD le añadí solo la constante `TEXTO_BANDEJA_SIN_EVENTOS`, porque el spec la importa.
4. Puse `// @ts-nocheck` en las dos copias de los specs, para que compilaran contra los tipos antiguos.
5. Lancé los dos specs.
6. Restauré mis ficheros y comprobé con `cmp` que eran idénticos byte a byte y que no quedaba ningún `ts-nocheck`. Después de restaurar volví a lanzar los dos specs: 39 de 39.

Resultado con HEAD: **17 fallan y 22 pasan (los de antes)**, exit 1. Los motivos:

| Casos | Por qué fallan con HEAD |
|---|---|
| los 5 motivos (`brake`, `complaint_recent`, `complaint_unknown`, `expired`, `legal_review` → `unrecognized`) | `expected undefined to be '<motivo>'`: HEAD no lee la clave |
| `meta.withheld` = 0, 1 y 3 | `expected undefined to be 0` / `1` / `3` |
| `meta.withheld` ausente o ilegible, API anterior, API nueva con `null` | `expected undefined to be null` |
| 409 `not_in_queue`, `already_claimed`, `enrollment_closed` | `Expected one matching request … found none` en `const releer = http.expectOne(…)`: HEAD no relee |
| `enrollment_closed` es un código conocido | `expected false to be true` |
| la fuente de eventos apagada | `expected undefined to be 'status'`: no hay línea |
| el lector por HTTP (service spec) | `expected [ undefined, … ] to deeply equal [ … ]` |

## 5 · Lo que no he podido comprobar

- **La pantalla real** [S]: no hay contactos asistidos en vivo (la automatización está en sombra) y no he levantado la app. Solo lo he visto en jsdom. No he comprobado cómo se ven en el navegador el aviso dentro de la fila ni las dos líneas encima de la lista, ni en anchos estrechos. Uso clases que ya existen (`lab-alert is-warn` / `is-info`, que tienen `overflow-wrap: anywhere` [L: `studio-lab-kit.scss:29-31`]) y no he añadido estilos.
- **Supervisión**: el cambio de etiqueta está en el diff, pero ninguna prueba monta `supervision-page.ts`. No he añadido una, para no salirme de «solo esa etiqueta». La plantilla no la compila ningún spec; `tsc` no comprueba plantillas.
- **El backend real**: he probado contra la forma de la §0 y la §4 de `informe-be-bandeja-condiciones.md` y contra `AssistedQueue.php` / el controlador [L], no contra un servidor levantado. `AssistedQueue.php` cambió en disco mientras trabajaba (el otro agente); lo he leído, pero no lo he tocado.
- **La batería completa del CRM** y `build:prod`: no los he lanzado, por indicación del encargo.

## 6 · Del contrato: lo que faltaba o no cuadraba

1. **El estado, en la bandeja, solo a veces** [L]
   - Quien carga el estado es `ca-panel.ts:590`, la pestaña «Panel», que es la de entrada. La bandeja usa la sesión de la página, así que, si se pasó por el «Panel», tiene el estado.
   - Si se entra directamente con `?pestana=bandeja`, el estado está en `idle` y la línea de la fuente apagada **no sale**.
   - Para tenerlo siempre haría falta una petición nueva desde la bandeja: `GET outreach/status`, una ruta que ya existe. No la he añadido, como pedía el encargo.
   - Ojo: esa ruta exige `commercial_automation.view` [L: `UltronStudioOutreachController.php:127`] y la bandeja exige `commercial_automation.assisted_work` [L: `UltronStudioOutreachAssistedController.php:35`]. Una cuenta que solo trabaje la bandeja recibiría un 403.
   - **Decisión del lead (§9): no se añade.** Queda como está.
   - Mitigación parcial: con la fuente apagada, el servidor retiene lo comercial y la cobranza sin reclamar y lo cuenta en `meta.withheld` (como `complaint_unknown`). Así que la línea de `meta.withheld`, que sí llega siempre, ya dice «o no se puede comprobar si se quejó».
2. **`meta.withheld` es una cota inferior en la parte de la queja** (D-4 de la §7 del backend) [L]
   - El texto de la §5 dice «Hay N contactos…». Si la página se llena (el CRM no manda `limit`, así que son 20) o se llega a las 200 filas leídas, puede haber más.
   - **Decisión del lead (§9): el texto dice «Hay al menos N contactos…»**, que es cierto siempre.
3. **«La fuente de eventos apagada»: cuándo es cierto el texto** [L]
   - Es cierto con la configuración por defecto:
     - con `commercial.events_enabled` apagado, `EngineFacts::events()` da `null` [L: `EngineFacts.php:107-115`];
     - `complaintRecently()` da `null`, siempre que la finalidad esté en `complaint_purposes` y `complaint_days` no sea 0 [L: `LadderInputBuilder.php:177-185`];
     - por defecto, esas finalidades son todas menos `service`, es decir, cobranza y comercial [L: `OutreachConfig.php:253-261`].
   - Si alguien pone `outreach.complaint_days = 0` o cambia esas finalidades, el texto dejaría de ser exacto. La pantalla no ve esa configuración.
4. **«Se quejó después de que reclamaras»** [L/S]
   - El servidor comprueba «una queja dentro de la ventana», no «posterior al reclamo».
   - En la práctica coinciden, porque reclamar ya exige que no haya queja en la ventana [L: `AssistedQueue.php:198-199`, `:288-299`].
   - Excepciones posibles [S]: un evento registrado tarde con `occurred_at` anterior al reclamo, o un reclamo hecho antes de `155e7adc`.
   - **Decisión del lead (§9): el texto pasa a «Esta persona tiene una queja reciente»**, que es lo que el servidor comprueba.
5. **Un 409 distinto de esos tres** (por ejemplo `outreach_disabled`) no relee: solo olvida la clave, como antes. Lo he limitado a los tres códigos del encargo.
6. **H-A del backend** (`status.live.assistedQueue` cuenta otra cosa): resuelto en el «Panel» cambiando la etiqueta (§8).

## 7 · Para `tester`, `security` y `reviewer`

- **Flujos críticos para `tester`:**
  - reclamar → 409 → la fila desaparece sin pulsar «Releer»;
  - una fila mía con un motivo: se ve el aviso, no se ven la ficha ni el guion, y el resultado se registra igualmente;
  - soltar un freno: en la lectura siguiente vuelven la ficha y el guion (el único motivo que se va solo, §5.6 del backend);
  - entrar por `?pestana=bandeja` frente a pasar antes por «Panel», con la fuente apagada (§6.1);
  - anchos estrechos con un aviso largo (el de `brake`).
- **`security`:**
  - Quitar la ficha y el guion en el cliente es solo presentación: la autoridad es el servidor, que los manda en `null` [L: `AssistedQueue.php:470`, `:483-484`].
  - No he añadido tokens, almacenamiento, HTML sin escapar ni `innerHTML`. Los textos nuevos son constantes y el número se interpola con `{{ }}`.
  - Un motivo desconocido falla cerrado.
- **`reviewer`:** el diff está sin confirmar en el árbol del CRM (11 ficheros). La copia de trabajo de la línea base está en el directorio temporal de la sesión y no va al repositorio.

## 8 · Ampliación: las tres precisiones del lead (sacadas de la §4, §5 y §7 del backend)

1. **`meta.withheld` (no cuenta los vencidos; en «Los míos» siempre es 0; es una cota inferior; el aviso también con la lista vacía)** [C]
   - Ya estaba hecho con el texto de la §5.
   - Con `withheld > 0` y la lista vacía, no se pinta «No hay trabajos asistidos vigentes»; sale «Ahora no hay trabajos asistidos que se puedan ofrecer.» debajo del aviso.
   - Prueba: los casos `meta.withheld = 1` y `= 3` de `ca-assisted.spec.ts` comprueban los dos textos con `ok([], { withheld: n })`. El caso `= 0` comprueba que vuelve el texto de vacío de siempre.
   - Con la vista «Los míos», el servidor manda 0 y no sale ninguna línea: es el mismo camino que el caso 0.
   - La cota inferior queda resuelta con «al menos» (§9).
2. **`brake`, el único motivo que se va solo** [C]
   - Ya estaba hecho con el texto de la §5: «…no contactes a esta persona mientras siga abierto. Si ya hablaste con ella, registra el resultado.»
   - El lead escribe «no la contactes»; he mantenido «no contactes a esta persona», que es el texto de la §5. Aquí «la» no tiene antecedente, porque la frase empieza por el freno.
   - Cuando se suelta el freno, la ficha y el guion vuelven solos en la lectura siguiente: la plantilla los enseña en cuanto `contactWithheld` vuelve a `null` (lo cubre el caso «la API nueva sin retención»).
3. **El número del «Panel» que ya no cuadra con la bandeja** [C]
   - `ca-panel.ts:197` pintaba `status.live.assistedQueue` como «en la bandeja asistida».
   - El servidor cuenta ahí los asistidos `started`, en vivo, sin resultado del personal [L: `OutreachReadModels.php:69-70`]. No filtra ni el plazo, ni los frenos, ni la queja, ni el estado de la inscripción.
   - Cambio: la etiqueta pasa a «asistidos sin resultado del personal». El número es el mismo.
   - Prueba nueva en `ca-panel.spec.ts:99-112`: con `live: {jobs: 4, openEnrollments: 2, assistedQueue: 7}`, el recuento de 7 se llama «asistidos sin resultado del personal» y el bloque ya no dice «bandeja asistida». El recuento se busca por su número, no por el atributo nuevo, para que la línea base falle por la etiqueta.
   - Línea base con el `ca-panel.ts` de HEAD [C]: 1 falla (`expected 'en la bandeja asistida' to be 'asistidos sin resultado del personal'`) y los 6 de antes pasan. Restaurado y comprobado con `cmp`.
   - Matiz [L]: este recuento siempre cuenta el modo `live` (`OutreachConfig::MODE_LIVE`). Los otros dos recuentos del bloque cuentan el modo activo. La etiqueta no lo dice; no lo he tocado.

Órdenes finales tras la ampliación [C]: los dos `tsc` dan exit 0; `check-literals` da «417 ficheros revisados · literales correctos»; los specs del módulo dan 20 ficheros y **615/615**; los de supervisión, **21/21**.

## 9 · Cierre: las decisiones del lead sobre la §6

1. **§6.2, la cota inferior** [C]
   - `assistedWithheldText(n)` (`commercial-automation-text.ts:249-259`):
     - con 1: «Hay al menos 1 contacto que no se ofrece ahora: hay un freno abierto, la persona se quejó hace poco o no se puede comprobar si se quejó.»;
     - con N: «Hay al menos N contactos que no se ofrecen ahora: …».
   - Las pruebas `meta.withheld = 1` y `= 3` comprueban el texto exacto.
2. **§6.4, la queja reciente** [C]
   - `ASSISTED_WITHHELD_TEXTS.complaint_recent` dice ahora «Esta persona tiene una queja reciente: no la contactes. Registra el resultado que corresponda.». La fila lo pinta con el prefijo «Contacto retenido.».
   - La prueba del motivo `complaint_recent` comprueba el texto exacto.
   - No queda ningún resto del texto anterior en el módulo [C: `grep` sin resultados].
3. **§6.1, el estado desde la bandeja**: no se añade la petición. Sin cambios.
4. **La etiqueta de `ca-panel.ts:197`** («asistidos sin resultado del personal»), con su prueba, ya estaba hecha (§8).

Órdenes finales, con el árbol final [C]:

```
$ npx tsc -p tsconfig.app.json --noEmit                → exit 0
$ npx tsc -p tsconfig.spec.json --noEmit               → exit 0
$ node scripts/check-literals.mjs                      → "417 ficheros revisados · literales correctos", exit 0
$ … ng test --include='src/app/modules/commercial-automation/**/*.spec.ts'   → Test Files 20 passed (20) · Tests 615 passed (615), exit 0
$ … ng test --include='src/app/modules/supervision/**/*.spec.ts'             → Test Files 1 passed (1) · Tests 21 passed (21), exit 0
```

La línea base de §4.1 no la he repetido para estos dos cambios de texto. Los casos afectados fallan con HEAD por su primera aserción, que no ha cambiado: el lector de HEAD no da ni `contactWithheld` ni `withheld`.

Estado del árbol del CRM: 11 ficheros modificados, sin commit ni push [C: `git status --short`].

# Informe de frontend · La bandeja asistida, tercera ronda (los motivos nuevos, `scanCapped` y la relectura al volver, m-4)

**12-11-2026 · `frontend` · árbol `_worktrees/ultron-h8/Iron-Body_Front`, rama `feat/ultron-studio-h9`, sobre HEAD `b10d593`.**
- Sin commit, push, `build:prod` ni despliegue. No he lanzado la batería completa del CRM.
- Solo he tocado `src/app/modules/commercial-automation/`. No he tocado los fixtures sincronizados (`testing/fixtures/`, `testing/sync-h9-fixtures.mjs`) ni `testing/ca-examples.testing.ts`.
- **Contrato:** la §1 de `informe-be-bandeja-ronda3.md`. El backend todavía no la ha confirmado (ver §5).

Marcas: **[C]** comprobado ejecutando · **[L]** leído en el código, sin ejecutar · **[S]** suposición.

## Veredicto

Los cuatro puntos del encargo están hechos y probados con las clases reales del módulo: sesión real, componente real, `HttpTestingController` y el `document` de jsdom.
- **19 casos nuevos.** Con los fuentes de HEAD, los 19 fallan, y también los 2 casos de `withheld` cuyo texto cambia: **21 en rojo y 37 en verde [C]**. Con el cambio pasan todos.
- Verde en las dos compilaciones de `tsc` y en el control de literales [C].
- Verde en los specs del módulo: **634/634** (antes 615) [C].
- **Aserciones que cambian:** las dos de `meta.withheld = 1` y `= 3` (`ca-assisted.spec.ts:173-174`), por el texto nuevo. No cambia ninguna otra.

## 1 · Ficheros y líneas [C: `git diff -U0`, `git status --short`]

7 ficheros, +316 / −36. Las líneas son las del árbol final.

| Fichero | Líneas | Qué |
|---|---|---|
| `commercial-automation-api.types.ts` | `:1138-1150` | `AssistedWithheldReason` y `ASSISTED_WITHHELD_REASONS` con los 7 motivos, en el orden del servidor: `brake`, `strategy_stopped`, `opted_out`, `complaint_recent`, `policy_unknown`, `complaint_unknown`, `expired`. Comentario actualizado |
| | `:1188-1206` | `AssistedQueueDto`: comentario de `withheld` actualizado y campo nuevo `scanCapped: boolean \| null` |
| `commercial-automation-parse.ts` | `:1375-1386` | `readAssistedQueuePage` lee `meta.scanCapped` con `booleanoSuave`, que ya existía (`:140`). Solo `true` o `false`; ausente, `null`, `'true'`, `1` o sin `meta` dan `null` («no se sabe») |
| `commercial-automation-api.service.ts` | `:430` | solo el comentario de `assistedQueue()` |
| `commercial-automation-text.ts` | `:234-248` | `ASSISTED_WITHHELD_TEXTS`: los tres motivos nuevos. Las claves van ahora en el orden del servidor: `brake` sube al principio, con el mismo texto |
| | `:250-266` | `assistedWithheldText(n)`: texto nuevo (singular y plural) y comentario nuevo: qué cuenta de forma exacta y qué como cota inferior |
| | `:268-274` | `TEXTO_BANDEJA_TOPE` (nuevo) |
| `ca-assisted.ts` | `:1-2`, `:18` | importaciones (`DOCUMENT`, `DestroyRef`, `TEXTO_BANDEJA_TOPE`) |
| | `:29-30` | `CA_BANDEJA_RELECTURA_MS = 15_000` (exportada, para la prueba) |
| | `:43-56` | documentación de la clase: los motivos, `scanCapped` y la relectura al volver |
| | `:96-98` | el aviso del tope: `div.lab-alert.is-warn`, `role="status"`, `data-ca-bandeja-tope`, debajo de la línea de `withheld` y encima de la lista |
| | `:99-108` | lista vacía: con `scanCapped === true` no se pinta ningún texto de vacío. Si no, lo de antes |
| | `:184`, `:189` | `document` (por `inject(DOCUMENT)`) y `textoTope` |
| | `:201-221` | `releidaAlVolver`, el oyente `alVolver` y su alta y baja en el constructor (`DestroyRef.onDestroy`) |
| `ca-assisted.spec.ts` | `:3`, `:8`, `:32-37` | importaciones y cabecera |
| | `:145`, `:146`, `:148` | los tres motivos nuevos en el `it.each` de motivos |
| | `:173-174` | **aserción cambiada**: el texto nuevo de `withheld` (1 y 3) |
| | `:274-334` | `describe('meta.scanCapped…')`: 9 casos |
| | `:336-448` | `describe('al volver a la pestaña (m-4)')`: 5 casos |
| `commercial-automation-api.service.spec.ts` | `:10`, `:433-460` | 2 casos: el lector de `scanCapped` y los motivos nuevos (más el 201 con `brake`, m-2) |

## 2 · Los textos finales [C: comprobados palabra por palabra en las pruebas]

| Dónde | Texto |
|---|---|
| fila, `strategy_stopped` | «**Contacto retenido.** La estrategia de este contacto ya no está en marcha (pausada, apagada, revertida o archivada, o no se puede verificar): no contactes a esta persona por este trabajo. Si ya hablaste con ella, registra el resultado.» |
| fila, `opted_out` | «**Contacto retenido.** Esta persona pidió que no la contacten (o lo pidió alguien que comparte su número, retiró su autorización o el número no es suyo): no la contactes. Si ya hablaste con ella, registra el resultado.» |
| fila, `policy_unknown` | «**Contacto retenido.** No se puede comprobar si esta persona pidió que no la contacten (no hay datos de su ficha): no la contactes. Si ya hablaste con ella, registra el resultado.» |
| encima, `withheld = 1` | «Hay al menos 1 contacto que no se ofrece ahora: hay un freno abierto, la estrategia no está en marcha, la persona pidió que no la contacten o se quejó, o no se puede comprobar.» |
| encima, `withheld = N > 1` | «Hay al menos N contactos que no se ofrecen ahora: hay un freno abierto, la estrategia no está en marcha, la persona pidió que no la contacten o se quejó, o no se puede comprobar.» |
| encima, `scanCapped = true` | «Puede haber más contactos que ofrecer detrás de los retenidos: la bandeja dejó de leer al llegar a su tope. Avisa al responsable de la automatización.» |

- **Origen de `strategy_stopped`:** es el texto **del encargo del lead**, no el de la §1.5 del backend. La §1.5 decía «La estrategia de **este trabajo** ya no está en marcha (**está** pausada, …)».
- **Origen de los demás:** coinciden letra por letra con la §1.5 del backend y con el encargo.
- Los textos de `brake`, `complaint_recent`, `complaint_unknown`, `expired` y el del motivo desconocido no cambian.

## 3 · Decisiones

1. **`scanCapped`, dónde y cómo se pinta** [C]
   - Encima de la lista y debajo de la línea de `withheld`, con `lab-alert is-warn` y `role="status"`. Es el mismo patrón que las otras dos líneas: el texto dice lo que pasa sin depender del color.
   - Sale también cuando hay filas, porque el encargo dice «cuando sea `true`». Con filas, `true` significa que la página no se llenó y se llegó a las 200 filas leídas.
2. **`scanCapped = true` con la lista vacía** [C]
   - No se pinta **ningún** texto de vacío: ni «Ahora no hay trabajos asistidos que se puedan ofrecer» ni «No hay trabajos asistidos vigentes».
   - Si `withheld` es 0, solo queda el aviso del tope. La prueba de `:290` lo cubre.
3. **La relectura al volver: no había un mecanismo que reutilizar** [L]
   - En el módulo no hay nada. La bandeja no está en el canal de eventos: `ca-live-indicator` solo lo monta el «Panel» (`ca-panel.ts:102`), y `StudioStreamService.refreshes()` no mira la visibilidad (`studio-stream.service.ts:249-256`).
   - En el CRM, cada pieza escribe su propio oyente. No hay una utilidad común:
     - `valuations-tab.ts:622-636`;
     - `studio-lab-session.ts:381-396`;
     - `version-watcher.service.ts:74`;
     - `marketing-appointments.service.ts:389-404`.
   - He seguido la forma de `studio-lab-session.ts`: `inject(DOCUMENT)`, el oyente como función flecha y la baja con `DestroyRef.onDestroy`.
4. **Los 15 s cuentan desde la última relectura al volver**, no desde cualquier lectura («Releer», reclamar o registrar) [C]
   - Es la lectura literal del encargo y no toca la sesión.
   - Consecuencia: si reclamas y vuelves a la pestaña a los 5 s, se relee una vez más, aunque la reclamación ya acababa de releer.
   - Contarlos desde cualquier lectura exigiría sellar la hora en `CommercialAutomationSession.cargar()`. No lo he hecho. Si el lead lo prefiere, es un cambio pequeño.
5. **Si ya está leyendo, no relee y no gasta los 15 s** [C]
   - Solo se sella la hora cuando de verdad se pide.
   - Al terminar esa lectura, volver otra vez sí relee. Lo cubre la prueba de `:419`.
6. **«El último filtro» es el de la sesión** [C]
   - Es `loadAssistedQueue()` sin argumento, igual que las relecturas tras reclamar, registrar o un 409 (`commercial-automation-session.ts:708`, `:711`, `:723`).
   - Lo que **ya pasaba** [L]: si el componente se vuelve a montar (de «Bandeja» a «Panel» y vuelta), el selector arranca en «Todos» y la sesión conserva el último filtro. El botón «Releer» usa el del selector. No lo he tocado: está fuera del encargo.
7. **Otros casos de la relectura** [L]
   - También relee desde el estado `error` (sirve de reintento) y sin la llave `assistedWork`, igual que la primera lectura del constructor.
   - Solo escucha `visibilitychange`, no `focus`. Con dos ventanas a la vista a la vez, cambiar de una a otra no cambia la visibilidad del documento y no relee.

## 4 · Órdenes y su salida final [C]

Todas lanzadas desde `Iron-Body_Front`, con el árbol final.

```
$ npx tsc -p tsconfig.app.json --noEmit                → exit 0, sin salida
$ npx tsc -p tsconfig.spec.json --noEmit               → exit 0, sin salida
$ node scripts/check-literals.mjs                      → "417 ficheros revisados · literales correctos", exit 0
$ IRON_BODY_BACKEND_DIR=…/Iron-Body/backend bash -c "nice -n 10 npx ng test --watch=false --include='src/app/modules/commercial-automation/**/*.spec.ts'"
    Test Files  20 passed (20)
         Tests  634 passed (634)                        → exit 0   (615 en b10d593 + 19 nuevos)
```

### 4.1 · Línea base: los casos nuevos fallan sin el cambio [C]

Cómo lo comprobé:
1. Copié mis 7 ficheros al directorio temporal de la sesión.
2. Devolví a `HEAD` los 5 fuentes (`ca-assisted.ts`, `api.service`, `api.types`, `parse`, `text`).
3. Al `ca-assisted.ts` de HEAD le añadí solo `export const CA_BANDEJA_RELECTURA_MS = 15_000;`, sin comportamiento, porque el spec la importa.
4. Puse `// @ts-nocheck` en las dos copias de los specs.
5. Lancé los dos specs.
6. Restauré mis ficheros y comprobé con `cmp` que eran idénticos byte a byte y que no quedaba ningún `ts-nocheck`.

Resultado con HEAD: **21 fallan y 37 pasan (58)**, exit 1.

| Casos | Primer fallo con HEAD |
|---|---|
| motivos `strategy_stopped`, `opted_out`, `policy_unknown` (3) | `expected 'unrecognized' to be '<motivo>'` |
| `withheld = 1` y `= 3` (aserción cambiada, 2) | `expected 'Hay al menos 1 contacto que no se ofr…' to be …` (el texto anterior) |
| `scanCapped` `true`: vacía con `withheld`, vacía sin `withheld`, con filas (3) | `expected undefined to be true`. Además, con HEAD no hay aviso y sí hay texto de vacío |
| `scanCapped` `false` / ausente / ilegible, vacía (4) y con filas (2) | `expected undefined to be false` / `to be null` |
| relee con el último filtro y quita la ficha retenida | `Expected one matching request … found none` |
| como mucho una vez cada 15 s | ídem (la primera relectura no llega) |
| con el documento oculto no relee; visible, sí | ídem, en el paso «visible» |
| si ya está leyendo no pide otra; al terminar, sí | ídem, en el último paso |
| al destruir se quita el oyente | `expected "removeEventListener" to be called with arguments: [ 'visibilitychange', Any<Function> ]` |
| service spec: el lector de `scanCapped` | `expected undefined to be true` |
| service spec: los motivos de la tercera ronda | `expected [ Array(3) ] to deeply equal [ 'strategy_stopped', … ]` |

**Qué hace fallar cada caso con HEAD:**
- Los 6 casos de `scanCapped` `false`, ausente o ilegible fallan con HEAD solo por el lector: la sesión no tiene la clave y da `undefined`. Sus aserciones de pantalla (sin aviso del tope, el texto de vacío de siempre) también pasarían con HEAD. Son guardas de que lo de antes no cambia.
- El caso del service spec incluye el 201 de reclamar con `brake` (m-2). Esa parte sola pasaría con HEAD, porque el CRM ya leía esa forma. Ese caso falla con HEAD por los motivos nuevos.

## 5 · Lo que no he podido comprobar

- **El servidor todavía no manda `scanCapped`** [L]
  - El controlador solo devuelve `meta: {withheld}` (`UltronStudioOutreachAssistedController.php:44`), aunque `AssistedQueue.php:196` ya lo calcula.
  - Con el backend tal como está en disco, el CRM lee `scanCapped = null` y no pinta el aviso. Es el comportamiento correcto para «no se sabe», pero el aviso no se verá hasta que el controlador lo mande.
  - El orden de los motivos sí coincide con `AssistedQueue::WITHHELD_REASONS` en disco (`AssistedQueue.php:97-100`) [L].
  - El backend está cambiando y su informe aún no tiene las §3 a §8.
- **La pantalla real** [S]: solo lo he visto en jsdom. No he comprobado en el navegador ni en anchos estrechos las tres líneas encima de la lista, ni el aviso más largo (`opted_out`). Uso las clases que ya existían (`lab-alert is-warn`) y no he añadido estilos.
- **El 201 con `brake` (m-2)** [L]: la sesión avisa «Trabajo reclamado: ahora es tuyo hasta registrar el resultado.» (`commercial-automation-session.ts:707`). Sigue siendo cierto, y la relectura que viene después pinta la fila con el aviso de freno. No lo he cambiado.
- **`enrollment_closed` en la repetición** (m-1): el CRM ya lo trata (relee y la fila desaparece; el aviso enseña el mensaje del servidor). No he adoptado el texto propuesto en la §1.5 («Este contacto ya se cerró…»), porque el encargo no lo pedía. `STATE_409_TEXTS.enrollment_closed` sigue diciendo «La inscripción de ese trabajo ya se cerró.».
- **La vista `all` sin los reclamados vencidos de otra persona** (m-8): no hace falta tocar nada en el CRM; la fila simplemente no llega. Sin prueba propia.
- **La batería completa del CRM** y `build:prod`: no los he lanzado, por indicación del encargo.

## 6 · Para `tester`, `security` y `reviewer`

- **`tester`, flujos críticos:**
  - Reclamar una fila, cambiar de pestaña del navegador, echar un freno o marcar «no contactar» a esa persona y volver: la ficha y el guion desaparecen y sale el aviso, sin pulsar «Releer».
  - Volver a la pestaña varias veces en menos de 15 s: una sola petición.
  - Con el servidor que ya mande `scanCapped: true`, la lista vacía no dice que no hay trabajos.
- **`security`:**
  - La relectura al volver (m-4) acorta el tiempo en que se ve un enlace retenido, pero no lo cierra: mientras la pestaña sigue visible, no se relee sola. La autoridad sigue siendo el servidor, que manda `person` y `script` en `null`.
  - No he añadido tokens, almacenamiento, HTML sin escapar ni `innerHTML`. Los textos nuevos son constantes y el número se interpola con `{{ }}`.
  - Un motivo desconocido sigue fallando cerrado (`unrecognized`).
- **`reviewer`:** el diff está sin confirmar en el árbol del CRM (7 ficheros, encima de `b10d593`). La copia de la línea base está en el directorio temporal de la sesión y no va al repositorio.

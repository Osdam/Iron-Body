# PT-14 (servidor y CRM) y enmienda PT-19 del guion · revisión final independiente · 23-11-2026 (00:20 UTC)

**Revisado:** servidor `748f17ab` (`_worktrees/cierre-a4n`, sobre `b1f66c32`) · CRM `17bad24` (`_worktrees/cierre-crm`, sobre `9d5a8e6`) · guion `df095ad9` (`cierre/ops-pt19`, frente a `feat/ultron-studio-h9`).
**Marcas:** [V] verificado ejecutando · [L] leído en el código · [S] supuesto.
**Fuera de mi encargo, por aviso del lead:** `PlanCommercialText::authorChanges` y las pruebas A 4 y A 6. Están afectadas por el bloqueante B-1 de security, que se corrige aparte. Tampoco repito la revisión de seguridad.

## Veredictos

| Pieza | Veredicto | Bloqueantes míos | No bloqueantes |
|---|---|---|---|
| 1 · Servidor `748f17ab` | **APROBADO CON CONDICIONES**. Condición única: que la corrección de B-1 (security) pase la revisión de su delta. Mientras tanto, ese bloqueante sigue abierto | 0 | 4 |
| 2 · CRM `17bad24` | **APROBADO** | 0 | 3 |
| 3 · Guion `df095ad9` | **APROBADO** | 0 | 2 |

---

## Verificado (salidas reales)

**Servidor**, después de que terminara la batería del lead (00:08:31 UTC, `REGRESIONES: 0`). Un solo PHPUnit a la vez, comprobando antes de cada uno que no hubiera otro en marcha.
- **SQLite, 338 pruebas** [V]: `tests/Feature/Plans` (completo), `AuditChangesTest`, `VoiceToolsPublishedCatalogTest`, `VoiceToolsTest`, `VoiceToolsBenefitsTest`, `VoiceLabToolsTest`, `VoiceTransferDestinationsTest`, `TelephonyIsolationTest`, `OtpUntouchedByVoiceTest` y, del Studio, `VoiceCatalog`, `VoicePublishing`, `VoiceOutboundOnly`, `VoiceChain`, `VoiceRace`, `VoiceTrace`, `VoiceNoNetwork` y `EngineReadOnly` → `OK, but some tests were skipped! Tests: 338, Assertions: 221445, Skipped: 11.` (25,7 s).
- **PostgreSQL 14, 124 pruebas** [V], en la base desechable `ultron_studio_cierre_rev14`, creada y **borrada** al terminar: las tres clases nuevas, `PlanArchiveTest`, `AuditChangesTest`, `VoiceLabToolsTest`, `VoiceTransferDestinationsTest`, `VoicePublishing`, `VoiceOutboundOnly` y `VoiceChain` → `OK (124 tests, 1758 assertions)`.
- **Mutaciones: 13 de 13 caen** [V]. El procedimiento: copiar el fichero a mi carpeta temporal, sustituir un texto literal, ejecutar una sola prueba y restaurar; la huella sha256 coincide antes y después en todos. Al final, `git status --porcelain` vacío en `cierre-a4n`.

| Id | Mutación (código de producto) | Prueba | Resultado |
|---|---|---|---|
| A1 | `store` decide la venta con `$plan->isSellable()` (lo que decía el diseño) en vez de con SQL | A 5 `crear_o_duplicar…` | `Expected response status code [403] but received 201.` → confirma la desviación 1 |
| A2 | `reasonFor` nunca devuelve `text_changed` | A 2 `no_cambia_los_beneficios…` | `[403] but received 200` |
| A3 | `commercialText()` compara la cadena cruda, no por valor | A 3 `reenviar_el_mismo_texto…` | `[200] but received 403` |
| A4 | `locked()` no mira si el plan es vendible | A 8 `commercial_text_locked…` | `two arrays are identical` |
| B1 | `plans()` no compara el nombre publicado | `renombrado_despues_de_publicar` | `two arrays are identical` |
| B2 | `benefitsOf()` no filtra los beneficios publicados | `solo_salen_los_publicados…` | ídem |
| B3 | `partForCall()` no compara el agente que selló la llamada | `sellada_a_otro_agente…` | ídem |
| B4 | `readCommercialCatalog()` no comprueba la huella | `catalogo_manipulado` (7 casos) | cae el caso «huella» |
| C1 | `assertSeenCatalog()` no hace nada | `exige_la_huella…` | `[409] but received 201` |
| C2 | los cuatro ojos no miran la autoría del catálogo | `autor_del_texto_no_publica…` | `[403] but received 201` |
| C3 | promover mira los autores de **ahora**, no los de la instantánea | `autor_del_catalogo_del_canario…` | `[403] but received 409` |
| C4 | la deriva no detecta `renamed` | `la_deriva_del_catalogo` | `two arrays are identical` |
| G1 | `catalogOf()` hace `Plan::query()->whereKey(1)->value('name')` | `EngineReadOnly::test_solo_dos_ficheros…` | `'Plan::query(' matches PCRE pattern …` → la guarda nueva caza una lectura no permitida, no solo una escritura |

- **Sonda propia de casos límite** [V]. Es un fichero de prueba en mi carpeta temporal, fuera del repositorio, ejecutado con el `phpunit.xml` del árbol: `OK (3 tests, 12 assertions)`.
  - **Un beneficio con coma dentro de una lista JSON** (`["Acceso 24/7, todos los días","Toalla"]`): el Administrador cambia el precio reenviando la cadena cruda → 200. Tras publicar, Sofía dice `["Acceso 24/7, todos los días","Toalla"]`, sin partirlo, y `$ 95.000`.
  - **Plan publicado, archivado y reactivado:** archivado → `planes: []`; reactivado → vuelve con el mismo texto, deriva `ok`.
  - **Plan publicado sin beneficios al que luego se le añaden dos:** el plan sale sin `beneficios`, con `codes={"benefitsUnpublished":2}` y deriva `benefits_changed`, y la nota es `BENEFITS_NOTE` (es la desviación 7 en marcha).

**CRM** [V], ejecutado solo después de comprobar que no corría ningún PHPUnit ni la batería: la orden del lead, con `IRON_BODY_BACKEND_DIR=…/lote-3/Iron-Body/backend` → `Test Files 18 passed (18) · Tests 240 passed (240)` (4,75 s).
- **Ojo:** el árbol del CRM tiene **2 cambios sin confirmar que no son míos**, de las 23:56 UTC, posteriores a mi primera lectura limpia de las 23:46:
  - `studio-voice-text.ts`: «Lo escribiste tú» → «Lo escribiste o lo pusiste a la venta tú»;
  - `studio-voice-publications.spec.ts`: el nombre de la prueba.
- Parecen de la corrección de B-1. La ejecución fue sobre el árbol de trabajo, así que incluye ese texto; el resto es `17bad24`. No los he tocado.

**Guion** [V], sin repetir el ensayo del banco:
- **Huellas:** `df095ad9` = `ca03c1b5…03be`; `feat/ultron-studio-h9` = `7c7298d8…ebacb`, la revisada.
- **Huellas de `bootstrap/app.php`:** en `5cd201eb` y `c78e248e`, `d8e9cd27…cb710`, la de la ficha; en `f1fe6cac`, `294e57ab…387c`.
- **Alcance:** `f1fe6cac..c78e248e`, fuera de `docs/`, son exactamente `bootstrap/app.php` (+16) y la prueba.
- **Las funciones nuevas, extraídas del guion** y ejecutadas con bash 3.2 y el awk de BSD, con un `curl` falso:
  - `lee_marcador`: 12 casos (CRLF, comentario, dos líneas, estados iguales, `?`, `..`, `$()`, mayúsculas, comillas, POST, vacío). Acepta los válidos y rechaza el resto;
  - `marcador_lee`: 7 casos. Toma el último estado si hay `103 Early Hints` o un proxy con `CONNECT`. No confunde `x-ratelimit-limit-extra` ni un valor que contenga el nombre. Si curl falla, `000 ausente`;
  - `plazo_marcador`: 7 casos. Sin línea → 32; `60` → 90; `300 ; comentario` → 330; `301` y `"60"` → «no sabe esperar»; `0060` → 90; `08` → 38.
- **La evidencia del ensayo** no contiene `APP_KEY`, `base64:`, `Bearer`, contraseñas ni valores de `x-ratelimit-limit`.

---

## 1 · Servidor (`748f17ab`)

### a. ¿Es lo decidido y lo diseñado? Sí [L]+[V]

Contrastado con el §5 línea a línea:
- **A:**
  - `commercial_text_locked` en todas las respuestas del plan (`index`, `show`, crear, editar, archivar y reactivar), `null` sin sesión;
  - `commercial_text_by` en `$hidden`;
  - 403 `{ok:false, code:"plan_commercial_text_forbidden", reasons:[…], message}`, con los dos textos literales iguales al §5;
  - etiqueta y ayuda del permiso, literales;
  - por defecto solo Super Admin (`CrmPermission.php`, junto a `PLANS_ARCHIVE`).
- **B, vista previa:** `catalog {format, hash, shortHash, plans[{id, name, benefits, authoredByYou, authorRecorded}], fourEyes}`, y `null` con propósito `lab`.
- **B, publicar:** `expectedCatalogHash` obligatorio y sha256 (422). 409 `catalog_changed {expected, actual}` con su texto literal. 403 `four_eyes_required {detail:"plan_text_author", planTextAuthor:[{planId, name}]}` con «publicarla»/«promoverla».
- **B, `GET publications`:** `state.*.catalog {hash, shortHash, plans, drift{outcome, changes[{planId, name, change}]}}`, `null` sin agente; `history[].catalog`.
- **Telefonía:** los contadores `plansUnpublished`/`benefitsUnpublished` y su par en la línea de registro.
- Precio y condiciones, en vivo.

**Las ocho desviaciones del §6: justificadas; ninguna cambia lo decidido.**
1. **El alta decide la venta con SQL tras insertar.** El diseño tenía aquí un agujero: `new Plan($data)` sin `sellable` daba «no vendible». La mutación A1 lo demuestra: con la versión del diseño, el duplicado del CRM entra a la venta (201).
2. **Una sola lectura para el catálogo y los autores.** Cierra una carrera real.
3. **La captura, al empezar.** El diseño ponía los cuatro ojos del catálogo dentro de `assertFourEyes`, que va antes de la ventana. El orden resultante (403 antes de 409) es el de los cuatro ojos que ya existían.
4. **`orderBy('id')` para desempatar.** Solo fija un orden que antes no estaba definido.
5. **Primero el tope de 18 y después el filtro de 255.** La instantánea queda como subconjunto de lo que examina la voz.
6. **Funciones auxiliares de más.** Es organización interna.
7. **`BENEFITS_NOTE` también con beneficios sin publicar.** Es la nota ya existente, en el caso exacto que describe; la forma no cambia (lo vi en la sonda).
8. **Dos claves de más en el `diff` del libro.** Copian el precedente de las frases de reglas.

### b. Lógica duplicada: no, en lo que pide el requisito [L]

La definición del texto comercial está en un solo sitio, `Plan::commercialText()`. La usan A (`reasonFor` vía `commercialTextEquals`), la captura, la deriva y `consultar_planes`, que compara el nombre con `commercialText()['name']` y los beneficios con la misma `benefitsArray()`. La lectura estricta (`readCommercialCatalog`) también es una sola, para la telefonía y para el Studio. `partForCall` se movió, no se copió. Los dos restos menores están en NB-S1 y NB-S2.

### c. Precio y condiciones intactos, y la misma forma: sí [V]

`precio` sale de `self::price((float) $p->price)` y `condiciones`, de `duration_days` y `benefit_only` de ahora. En la sonda, el precio subido después de publicar sale como `$ 95.000`. Las claves siguen siendo `planes[{nombre, condiciones, precio, beneficios?}]` y `nota`.

### d. Regresiones de quien sí tiene permiso: ninguna [V]

- **El Super Admin edita:** A 7, `AuditChangesTest` (2, con el actor cambiado a Super Admin y las mismas aserciones) y `PlanArchiveTest`.
- **El Administrador cambia precios reenviando el mismo texto:** A 3 y la sonda con comas dentro de la lista JSON.
- **La voz se publica, se promueve y se revierte:** `VoicePublishingTest` (16), `VoiceChainTest`, `VoiceRaceTest`, `VoiceTraceTest`, `VoiceOutboundOnlyTest` y `VoiceCatalogTest` 6 (revertir y apagar). Todo en verde en SQLite y, los principales, en PostgreSQL 14.
- **Los destinos de transferencia**, tras mover `partFor`: `VoiceTransferDestinationsTest` en verde en los dos motores.
- **Los llamadores de `payload()`**, cuya firma cambió: los 6 están actualizados (`PlanController.php:66, 208, 240, 309, 428, 454`) y ninguno quedó sin el segundo argumento. Las escrituras van todas bajo `auth.admin` (`routes/api.php:721-753`), así que `AdminActor::from()` nunca da `null` ahí.

### e. Las pruebas miden lo que dicen; ninguna aserción existente se aflojó [V]+[L]

- Las mutaciones de la tabla: 4 de la clase A, 4 de la B, 4 de la C y 1 de la guarda.
- **`UltronStudioVoiceOutboundOnlyTest`:** la igualdad exacta de `evidence` pasa a «claves exactamente `['number', 'catalog']`» más `number` exacto. El contenido de `catalog` lo fija con `assertSame` completo `VoiceCatalogTest::test_publicar_guarda_el_catalogo…`, así que entre las dos no se pierde nada.
- **`AuditChangesTest`:** solo cambia el actor (Administrador → Super Admin), y las aserciones sobre la traza son las mismas. La traza no depende del rol.
- **`PlanArchiveTest`:** el `setUp` concede además la llave nueva. La premisa «con `plans.archive` sola, reactiva» se sustituye por un 403 explícito y por el 200 con las dos llaves. Es más estricto, no menos.
- **`VoiceToolsBenefitsTest`, `VoiceToolsTest` y `VoiceLabToolsTest`:** publican con el catálogo sembrado antes de preguntar; las comparaciones siguen siendo exactas. En `test_c1_ii…` se vuelve a publicar para seguir midiendo la guarda del nombre, con `plansWithheld: 2` intacto.

### f. La guarda `UltronStudioEngineReadOnlyTest`: correcta [V]

- Los dos ficheros entran en `ENGINE_READERS`, en su orden alfabético; la lista solo se usa en la línea 204.
- La comprobación nueva exige tres cosas:
  - que del motor nombren solo `App\Models\Plan`;
  - que de `Plan` usen solo las 4 lecturas y la constante;
  - que ninguna sentencia que lo nombre escriba.
- La mutación G1, una lectura no permitida, cae.
- Lo del alias y las cadenas es N-3 de security, y el lead dice que se endurece en el delta de B-1.

### g. Lógica y casos límite [V]+[L]

**Comprobados en verde:**
- plan sin beneficios (sonda, y `VoiceCatalogTest` 1 con «Anual» `[]`);
- 20 planes (`VoiceToolsBenefitsTest`, publicado);
- beneficios con comas y JSON legado (A 3 y la sonda);
- plan archivado (sonda);
- publicación anterior sin catálogo (`sin_catalogo…` y la deriva `missing`);
- catálogo manipulado (7 casos);
- llamada sellada a otro agente, y la vuelta atrás que recupera el catálogo del agente.

Los comentarios dicen lo que hace el código. La llamada siempre existe cuando `consultar_planes` llega a `partForCall` (`VoiceTools.php:169-171`).

### No bloqueantes (servidor)

- **NB-S1 · `Plan.php:111` y `VoiceTools.php:118`:** el tope de 18 está en dos constantes (`COMMERCIAL_CATALOG_MAX_BENEFITS` y `MAX_BENEFITS_EXAMINED`). Si alguien sube solo la de la voz, los beneficios 19.º y siguientes saldrían siempre como `benefitsUnpublished`: falla cerrado, pero sin motivo visible. **Corrección:** `MAX_BENEFITS_EXAMINED = Plan::COMMERCIAL_CATALOG_MAX_BENEFITS`.
- **NB-S2 · `VoicePublishedConfig.php:420` y `StudioVoicePublications.php:438`:** «la entrada `publish` de un agente» se resuelve dos veces, una en SQL correlacionado y otra en Eloquent. Hoy significan lo mismo (`action = publish`, el `seq` menor). Basta con una referencia cruzada en los comentarios.
- **NB-S3 · `Plan.php:361` y `:398`:** la instantánea descarta los beneficios que no son UTF-8 válido, pero no mira el nombre, y la huella usa `JSON_THROW_ON_ERROR`. Un nombre no UTF-8 daría 500 en la vista previa, en publicar y en `publications`. En PostgreSQL con codificación UTF8 no puede ocurrir; solo pasa en SQLite. Se documenta.
- **NB-S4 (para security, sin repetir su revisión):** B-1 coincide con lo que vi al leer: X escribe el texto con el plan fuera de la venta, Y lo enciende y queda como autor, y X publica. Las listas anidadas son su N-2 y la guarda por alias, su N-3. No los cuento como hallazgos míos.

---

## 2 · CRM (`17bad24`)

**¿Cumple el §4.4 y el §5? Sí** [L]+[V]:
- el permiso en el enum, en el catálogo y en el espejo del Administrador (excluido);
- `PlanSummary.commercial_text_locked?`;
- el editor bloquea y omite;
- duplicar, activar y reactivar enseñan el `message` del 403 de PT-14;
- tipos y lectores de los tres `catalog`, del 409 y del 403;
- `expectedCatalogHash` en la sesión y en el servicio, que entra en la firma de idempotencia;
- los textos de la deriva, literales al §5.

**Quien aprueba ve lo que aprueba:**
- tras «Previsualizar» se pinta «Lo que Sofía dirá de los planes»: huella corta (la entera en `title`), número de planes, nombre y beneficios de cada uno, y las marcas;
- se envía `c.hash` de **esa misma** vista previa;
- el botón se deshabilita con `fourEyes.ok === false` o sin catálogo (`studio-voice-publications.ts:393`), y `aria-describedby` apunta al motivo visible (`role=alert`);
- el 409 borra la vista previa y obliga a repetirla.

Todo cubierto en `studio-voice-publications.spec.ts`, incluido el texto hostil, que se pinta como texto.

**El editor de planes:**
- bloqueado, no manda `name` ni `benefits` (`edit-plan-modal.ts:1738`) y no rellena `defaultBenefits` (`:1657`);
- no bloqueado, todo sigue igual, también con `false`, `null` o la clave ausente;
- al reutilizar el modal se vuelve a decidir;
- la pista va enlazada con `aria-describedby` en los dos campos.

**Con un servidor viejo:**
- `commercial_text_locked` ausente se lee como «no bloqueado»;
- `catalog` ausente se lee `null` en los tres sitios, sin romper nada;
- publicar queda deshabilitado con un motivo visible: es la desviación 4.

**Sistema de diseño:** usa las piezas del Studio que ya existen (`lab-alert`, `lab-state`, `ig-section`, `ig-rows`) y los tokens `--us-*`. El gris del campo deshabilitado es el de `routine-modal.ts:1274-1275` (`#181716`/`#cfcac2`), como dice el informe.

**Desviaciones del §5 del informe: justificadas.**
1. **El catálogo copiado es el `.integracion.ts`.** El otro borraría las llaves del lote 4.
2. **`planActionError` en reactivar.** Sin el cambio, el 403 ocultaba qué llave falta; hay prueba en rojo.
3. **El espejo de permisos.** Sin el cambio, le daría la llave al Administrador.
4. **Con un servidor viejo no se publica.** Más estricto que el §8.3 del diseño; ver NB-C3.
5. **Textos propios del CRM.** No son del contrato.

**Aserciones existentes aflojadas: ninguna** [L].
- En `parity.spec`, `conRef` pasa de `['compilePreview']` a `['compilePreview', 'compilePreviewCatalog']`, con una comprobación añadida de que el lector no lee `ref` en ninguno de los dos.
- `session.spec` y `publications.spec` cambian de premisa porque ahora el cuerpo lleva `expectedCatalogHash`.

### No bloqueantes (CRM)

- **NB-C1 · `studio-voice-parse.ts:161`, `:257` y `:526`.** El lector exige que el nombre y cada beneficio sean texto de una línea, no vacío y sin control C0/C1. El servidor no filtra eso en la instantánea (solo UTF-8 y ≤ 255). Un plan con un `\x0B`, un U+0085 o un salto de línea en el nombre, de datos antiguos, deja ilegible **toda** la vista previa. Falla cerrado (no se publica), pero el error es de lectura y no dice qué plan. Es raro; se documenta.
- **NB-C2 · `edit-plan-modal.ts:1657`, comportamiento anterior a PT-14 con un efecto nuevo.** En un editor no bloqueado (Super Admin, o Administrador con la llave), un plan sin beneficios se abre con `defaultBenefits`. Guardar cualquier campo, por ejemplo el precio, los escribe. Desde PT-14, eso además convierte a quien guarda en autor del texto: no podrá publicar la voz, y Sofía dejará de decir esos beneficios hasta volver a publicar (`benefits_changed`). Conviene que lo decidan el lead o architect; no lo arreglo.
- **NB-C3.** La desviación 4 contradice la frase del §8.3 del diseño: «el campo de más en `publish` se ignora». Es la opción segura, pero hay que actualizar el §8.3 (y E21-7, si lo repite) para que diga que, con un CRM nuevo y un servidor viejo, no se publica.

---

## 3 · Guion (`df095ad9`)

**Sin las banderas, el comportamiento es el de `7c7298d8`** [L]. Cada línea nueva está condicionada a `BOOT_SHA`, a `F_marcador` o a `$BK/decl/marcador`. Con ellas vacías:
- `DECLS` itera y salta `marcador`;
- el `case` de `app.php` aborta igual que antes, solo que con un mensaje más largo;
- los `say` y el `printf` del respaldo no se ejecutan;
- la vuelta atrás de un respaldo sin `decl/marcador` no cambia.

Lo único distinto son los textos de `uso` y de ese aborto.

**Falla cerrado en todos los caminos** [L]+[V]:
- **Banderas mal formadas:** un `--bootstrap` que no sea sha256 en minúsculas o que se repita da 2; `--bootstrap=` vacío cae en `*)`, 2. La una sin la otra, `--bootstrap` con `--rutas` o cualquiera de las dos en la vuelta atrás: 2.
- **Huella equivocada, `app.php` borrado o `--bootstrap` sin `app.php` en el diff:** ABORTA, 1.
- **Marcador mal formado** (más de una línea, estados iguales, caracteres fuera de `[a-z0-9_/-]` en la ruta o `[a-z0-9-]` en la cabecera): ABORTA o 2.
- **En el paso 0**, aborta si el marcador no está en el estado del previo y si `revalidate_freq` no es un número de como mucho 300.
- **En el paso 4**, si el marcador no llega al estado del nuevo dentro del plazo, `falla` → `FALLOS` → `revertir_auto` (`:1352`), código 4.
- **En la vuelta atrás**, si no vuelve al estado del previo, `ok=0` → PARAR.

**No hay falso verde** [L]:
- el estado del nuevo exige a la vez el código y la cabecera;
- el paso 0 fija antes el estado del previo, así que ni un proxy ni una caché pueden fabricar el del nuevo, solo impedirlo, y eso acaba en vuelta atrás;
- `HEAD = N` y la huella de `app.php` en `N` se comprueban aparte.

**Qué imprime:** el código, «presente/ausente», 16 cifras de huellas públicas, el plazo y líneas `opcache.*` en un aborto. Nunca una cabecera ni su valor; el `stderr` de curl va a `/dev/null`.

**Inyección:** imposible.
- La ruta y la cabecera solo admiten `[a-z0-9_/-]` y `[a-z0-9-]`.
- La cabecera llega a awk con `-v` y la ruta a curl entre comillas.
- `eval "F_$k=\$v"` no expande el valor.
- La huella solo admite hexadecimal.

**La ficha §5 ter** [V]: órdenes coherentes con el guion.
- Las huellas, comprobadas.
- `mkdir` antes de subir el marcador.
- El texto esperado del paso 0 coincide con el `say` del guion.
- `$P $N` en el servidor sigue la convención de los lotes 3, 4 y 5. Si no se definen, el guion da 2.

### No bloqueantes (guion)

- **NB-G1 · `deploy-cierre-lote.sh:293`; patrón anterior, 5 apariciones en `7c7298d8`.** `«$l»`, con bash 3.2 y `LC_CTYPE` UTF-8 (macOS), toma el primer byte de `»` como parte del nombre de la variable. Con `set -u`, rechazar una línea mal formada termina en `l\xC2: unbound variable` en vez del mensaje. Lo vi en mis pruebas aisladas. Sigue fallando cerrado (salida distinta de 0 → ABORTA o 2); en bash 5 con glibc no debería pasar [S]. **Corrección:** `«${l}»`.
- **NB-G2 · `:373-375`.** `plazo_marcador` no ve un `php_admin_value[opcache.revalidate_freq]` del pool. Con uno mayor, la espera se queda corta y salta una **vuelta atrás innecesaria**: es un falso rojo, no un falso verde. La ficha ya lo asume (§10).

---

## Lo que NO he podido verificar

- **El delta de la corrección de B-1** (dos autorías, `soldBy` en la instantánea, beneficios ilegibles, guarda contra alias) y los 2 cambios sin confirmar del CRM: quedan para la revisión que me pase el lead.
- **La batería completa:** no la ejecuté. Me apoyo en la del lead (`REGRESIONES: 0`, salida 0, 00:08:31 UTC) y en mis 338 + 124 pruebas acotadas.
- **El CRM en un navegador real** (foco, lector de pantalla, 390 px) y `ng build` de producción, que no repetí.
- **El guion con bash 5, glibc y mawk**, que no tengo en el Mac (solo bash 3.2 y el awk de BSD), y el ensayo de punta a punta en el banco, que no repetí. Lo que solo tiene el servidor (nginx con HTTP/2, el `revalidate_freq` real) lo cubre el `--solo-comprobar` de la ficha §9.
- **La migración con datos y transacciones concurrentes:** es de database-specialist.

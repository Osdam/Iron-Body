# Informe del backend · Apartado 4 · H4-9 (el tono publicado se cumple) y H4-10 (la pregunta por «los planes» recibe más de un plan)

18/19-11-2026 · rama `feat/ultron-studio-h9` (= `origin/main` = `938c6180`) · **sin commit**: todo está en el árbol de trabajo.
Guía: `plan-arquitecto-a4-resto.md` (§2, §3, §5) con las decisiones T-1 a T-5 y P-1 del lead, que mandan donde difieren.
Marcas: **[C]** comprobado ejecutando · **[L]** leído en el código · **[S]** supuesto o no comprobado.

---

## 1. Resumen

- **H4-9 (tono):** con la versión en «profesional», el commit comprueba el registro del borrador aprobado y todo texto de Laravel sale de usted. Los tres textos de la prueba real del 18-11 quedan así por el endpoint **[C]**:

  | Turno | Qué pasa |
  |---|---|
  | 1 | Sale tal cual (`register.action = ok`). |
  | 2 | `«tus»` → se descarta → sale la lista de precios neutra (y además es panorama con precio). |
  | 3 | `«te», «quieres»` → se descarta → sale el horario ajustado a 200. |

- **H4-10 (panorama):** solo con `studio`.
  - «¿Qué planes tienen y cuánto cuestan?» → siempre la lista.
  - «¿Qué planes tienen?» con un borrador de un plan → la lista; con dos planes → el modelo.
  - «¿Cuánto cuesta el Mensual?» y «la mensualidad» → el modelo.
  - Con el revisor caído → la lista **[C]**.
- **Legacy, `cercano`, `entusiasta` y un token sin `t`: no hacen nada.**
  - La instantánea `legacy-a4.json` (33 turnos × 2 modos) sigue en verde **sin regenerarse**.
  - `a4-textos-ajustados.md` sigue en verde sin regenerarse.
  - `SalesConversationReplyService.php`, `SalesObjectionResponderService.php`, `OutboundContentGuard.php`, `CourtesyRequestService.php`, `UltronPromptCompiler.php` y `UltronPromptStamp.php`: diff cero **[C]** (`git diff --quiet HEAD`).
- **Para aprobar:** `a4-textos-usted.md` (generado y fijado por una prueba). Tiene 149 casos «hoy → de usted», cada uno ajustado a 200 y a 160 con su longitud; ninguna variante lleva marcas del detector **[C]**.

## 2. Ficheros tocados

| Fichero | Estado | Qué |
|---|---|---|
| `app/Services/Marketing/Ultron/UltronRegisterDetector.php` | **nuevo** (puro) | T-2: el detector del tuteo; 63 marcas en lista cerrada. |
| `app/Services/Marketing/Ultron/UltronFormalReplies.php` | **nuevo** (puro) | T-4: la tabla «original → de usted» (64 frases o fragmentos, 2 colas, apertura y cierre de la ficha, línea del cobro). |
| `app/Services/Marketing/Ultron/Prompts/UltronPromptOffer.php` | +15 / −3 | T-1: `sp.t`. |
| `app/Services/Marketing/Ultron/UltronCommitService.php` | +274 / −88 | T-1 (lectura), T-3, T-4 (todos los puntos que escriben) y P-1. |
| `app/Services/Marketing/Ultron/CommercialTurnPolicy.php` | +81 | P-1: `pideElPanorama()`, `preguntaPorPrecio()` y la constante de la violación. **`decide()` y `violations()`: sin cambios.** |
| `app/Services/Marketing/Ultron/StrategyContract.php` | +40 | T-5: `PREGUNTAS_DE_USTED` y `conElRegistro()`. |
| `app/Services/Marketing/Ultron/UltronDecideService.php` | **+8 (fuera de la lista del encargo: §5.1)** | T-5: la pregunta sugerida de usted con el `sp` del turno. |
| `app/Services/Marketing/Ultron/UltronReplyFitter.php` | +16 / −2 | `withoutFinalQuestion()` extraída de `draft()`, sin cambio de comportamiento (las 27 pruebas del ajustador, en verde). |
| `tests/Unit/Marketing/Ultron/UltronRegisterDetectorTest.php` | nuevo | 84 pruebas puras: cada marca con su caso y cada exclusión. |
| `app/Services/Marketing/Ultron/ConversationMemoryService.php` | +31 / −4 (**§12**) | Ofertas de usted en `offerKindOf()`, solo con «profesional». |
| `tests/Unit/Marketing/Ultron/UltronUstedDetectoresTest.php` | nuevo (**§12**) | 8 pruebas puras de los detectores de usted. |
| `tests/Unit/Marketing/Ultron/UltronCatalogStaticGuardsTest.php` | nuevo (**§11**) | La guarda del catálogo escrito a mano (V-3). |
| `tests/fixtures/ultron-a4/legacy-usted.json` | nuevo (**§12**) | La instantánea Legacy de los borradores de usted. |
| `tests/Unit/Marketing/Ultron/CommercialTurnPolicyPanoramaTest.php` | nuevo | 39 pruebas puras: panoramas, no panoramas y la lista de filtros. |
| `tests/Feature/Marketing/UltronA4RegistroYPanoramaTest.php` | nuevo | 27 pruebas por el endpoint `decide` → `commit`, más el generador del documento. |
| `tests/Feature/Marketing/UltronDecidePromptsTest.php` | **1 aserción** | La forma del `sp` de `studio` (la permitida, §7). |
| `tests/Feature/Marketing/UltronA4LongitudYPreciosTest.php` | **el fixture `publica()`, sin tocar ninguna aserción** | Ver §5.2: **decisión para que la revises**. |
| `docs/.../a4-textos-usted.md` | nuevo | Lo que aprueba el propietario. |
| `docs/.../a4-evidencia/33…38` | nuevo | Salidas de PHPUnit. |

**No tocados:** compilador, fixtures del compilador y `n8n/`; los prompts base de `resources/`; `config/`, rutas y migraciones; `UltronPromptStamp`.

## 3. Qué hace cada cambio

### 3.1 T-1 · El tono, firmado

- **`UltronPromptOffer.php:204-213`.** `sp.t` = `governed['persona.tone']`.
  - Solo en el paso 8 (`studio`) y solo si es distinto de `UltronPromptCompiler::BASELINE[TONE]` (se usa la constante).
  - Legacy: `sp` idéntico, sin la clave.
  - La respuesta a n8n no cambia **[C]** (`test_t_no_viaja_en_la_respuesta_a_n8n`).
- **`UltronCommitService::turnoDelStudio()` (`:5179`).** Devuelve `{studio, tope, tono}`.
  - Sin `studio`: `TURNO_LEGACY` (`:150`, ahora con `'tono' => null`).
  - `tono` solo si es un valor de `UltronPromptCompiler::TONES`. Uno desconocido se ignora con el aviso `ultron.commit.tone_invalid`, que lleva el tipo y no el valor **[C]**.
  - El tope se lee igual que antes; solo se reordenó la función.

### 3.2 T-2 · El detector (`UltronRegisterDetector::tuteo(string, array $planes = []): list<string>`)

En este orden:
1. Quita las URL (`https?://` y `www.`), los `{{…}}`, lo citado entre «…», "…" y “…”, y los nombres de plan del catálogo, con y sin tildes y por secuencia de palabras, sin *lookbehind*.
2. Pasa a minúsculas con `mb_strtolower`, que conserva las tildes.
3. Trocea con `[a-záéíóúñü]+`, sin `\p{…}`.
4. Compara contra la lista cerrada `MARCAS`. Devuelve cada marca una vez, en orden de aparición, y como mucho 5.

Nunca marca «su/sus», «usted», «té», «esta/estas» ni «te» dentro de otra palabra: cada caso tiene su prueba.

**Marcas añadidas por mí** (decisión T-2: «puedes proponer»), cada una con su caso de prueba:
- **Imperativos sin tilde** (`cuentame`, `dimelo`, `escribeme`, `avisame`, `preguntame`, `cuentanos`, `escribenos`, `avisanos`, `podrias`): la base de los prompts va sin tildes y el modelo las copia así. «estas» sin tilde **no** se añade, porque es otra palabra.
- **`has`** («¿ya has entrenado?», sale en `replyFor`).
- **Subjuntivos de tú:** `vengas`, `seas`, `puedas`, `quieras`, `sientas`.
- **`crees` y `decides`**, que salen en las objeciones.
- **Infinitivos con «te»:** `ayudarte`, `contarte`, `darte`, `orientarte`, `responderte`, `resolverte`, `recomendarte`. Salen en `replyFor`, en `loQueSiTenemos()`, en `NADA_QUE_CONFIRMAR` y en las objeciones.

### 3.3 T-3 · El registro en el commit (`UltronCommitService.php:1608-1647`)

**Dónde va.** Después del recorte por longitud y antes de `violations()`. Solo actúa si `UltronFormalReplies::aplica($turno['tono'])`, es decir, con `profesional`; con `cercano`, `entusiasta` o null no se ejecuta nada.

**Qué hace:**
1. Marcas = `tuteo($replyFinal, $catalogo)`.
2. Si hay marcas, se quita la pregunta final con `UltronReplyFitter::withoutFinalQuestion()`. Es la misma función que usa ahora `draft()`: la regla F1, sin cambiar ni una línea de la regla.
3. Si el texto sin la pregunta ya no tiene marcas → `trimmed` y sale sin ella. Si no → `discarded`.
4. Si la longitud ya descartó el borrador, solo se mira: no se recorta un borrador que de todos modos va a caer.
5. `metadata.register = {expected:'usted', found:[marcas], action: ok|trimmed|discarded}`. Va sin texto de la persona; las marcas son palabras de la lista cerrada.
6. Si hay marcas, deja el registro `ultron.commit.register_mismatch` con la acción y el número de marcas.

**La violación (`:1678-1680`).** `discarded` añade `policy_register_mismatch` (`UltronCommitService::VIOLACION_REGISTRO`, `:147`) después de recalcular `violations()`, como `VIOLACION_LONGITUD`. El borrador entra por la rama de la política que ya existe.

**Los añadidos de después.** 13.ter (`courtesyResumption`) sale de usted. 13.quater (la frase del precio verificado) ya era neutra.

### 3.4 T-4 · Los textos de Laravel, de usted

**Mecanismo (`UltronFormalReplies`).** El commit sigue llamando al productor de hoy y, **solo con «profesional»**, pasa su texto por `deUsted()`, que hace `strtr` sobre `FRASES`:
- son textos enteros o fragmentos fijos;
- `strtr` prueba primero la clave más larga en cada posición;
- los datos de en medio no se tocan: franja, fecha y hora de la fila, dirección, nombre y precio del plan.

No se duplica la lógica de los productores (estados de la cita, si ya pasó, qué dato falta). Con cualquier otro tono, el commit no llama a nada de esto. **El texto libre de la base de datos no pasa por la tabla:**
- horario y `planReply`: solo cambia la cola fija (`cola()`, `COLAS`);
- ficha del gimnasio: solo la apertura y el cierre (`FICHA_APERTURA`, `FICHA_CIERRE`).

**Dónde se aplica.** En todo punto del commit que escribe texto propio:

| Familia | Puntos |
|---|---|
| Últimos recursos | `ultimoRecurso()` y sus 6 llamadas, incluidas el turno de cobro, la recuperación por repetición o reventa, la excusa de precio y el punto de control. |
| Bienvenida | `saludoDeBienvenida()`. |
| Memoria de la visita | `respaldoDeMemoria()`, que ahora envuelve a `memoriaDeLaVisita()`. |
| Ficha del gimnasio | `respuestaConHechos()`. |
| Respaldos | `respaldoSemantico()`, `respaldoPorIntencion()` y el `replyFor` de `safeFallback()`, mediante `textoDeLaIntencion()` (`:5212`). |
| Política | `planReply`, `courtesyReply` y `courtesyResumption`. |
| Hora de la visita | `contestarLaHoraDeLaVisita()`. |
| Guardas que pegan texto de Laravel al borrador | `sinNegarUnCobroDisponible`, `sinConstancia…`, `sinCambioDeVisita…`, `sinConfirmar…`, `conLaFechaDeLaCita` y `sinServiciosInventados`→`loQueSiTenemos`. |
| Cobro | `conCheckout` y `execPaymentLink`, aunque los enlaces de pago están apagados. |

Las guardas que pegan texto **tenían** que ir: corren antes del detector y, si su texto tuteara, el detector descartaría un borrador por culpa de Laravel.

**Última red (`antesDeEnviar()`, `:5252-5277`).** Con «profesional», si lo que va a salir tutea:
- deja el aviso `ultron.commit.register_fallback_mismatch` con las marcas;
- añade `metadata.register.fallback_mismatch = [marcas]`, por fusión: crea `register` si no existía;
- **el texto sale igual.**

Corre antes del bloque de la longitud, así que también actúa sin tope. En el desbordamiento, el último recurso sale de usted.

**Textos.**
- Las 36 variantes del plan §2.3 van **tal cual**.
- Los demás los redacté con la regla del encargo: la misma frase pasada a usted, sin muletillas y con el mismo número y orden de frases:
  - pago pendiente;
  - las 5 objeciones;
  - visita y retomar la visita;
  - el acta (actaDe, actaDeLaFila ×3, estadoDe ×2 y preguntaDeHora ×2);
  - las constantes del commit (`CORTESIA_SIN_CONFIRMAR`, `NADA_QUE_CONFIRMAR`, `SIN_ENLACE_PERO_SIN_MENTIR`) y sus 6 frases sueltas;
  - el cobro.
- «Tranquilo» → «Por supuesto» (`delay_objection`). «ir» → «venir» donde habla el gimnasio (como hace el plan en la ubicación).
- El respaldo de precios no cambia: ya era neutro.

### 3.5 T-5 · La pregunta sugerida (`StrategyContract.php:126-160`, `UltronDecideService.php:403-410`)

- `PREGUNTAS_DE_USTED`: las cinco del plan §2.3, sin tildes, en el orden de `preguntaAdaptativa()`.
- `conElRegistro($hints, $sp)` sustituye `suggested_question` solo si `sp.m = studio` y `sp.t = profesional`.

**Cómo llega el tono sin otra lectura de la cadena.** `decide` ya calcula la oferta del turno (`$this->promptOffer->forConversation()`, la misma llamada que firma el `sp` del token). Justo después, `UltronDecideService` pasa ese `sp` a `conElRegistro()`.
- Es la misma sentencia de la cadena que ya se leía; la prueba de la matriz de `UltronDecidePromptsTest`, que cuenta las sentencias, sigue en verde **[C]**.
- La pregunta sugerida no va firmada en el token (solo `hot_lead_fast_path` y `lifecycle_mode`), así que modificarla después de la oferta no cambia el token.

### 3.6 P-1 · El panorama de planes

**`CommercialTurnPolicy::pideElPanorama()` (`:940`)**, pura. Es verdadera si el texto normalizado casa con `PIDE_PANORAMA` (`:108`), salvo en estos casos:
- `choose_plan`;
- `reception_mode`, `memory_mode` o `goal_takes_turn`;
- un filtro de `FILTRO_DE_PLANES` (`:120`), lista cerrada con sus pruebas: para estudiantes, parejas, dos, niños, adultos mayores, familias, empresas o principiantes; por días, por semana, de un mes o por meses; con descuento o promoción; con entrenador o personalizado; familiar.

`PIDE_PANORAMA` es el patrón del plan más «qué/cuáles otros + planes/hay/tienen…», necesario para «el Mensual y qué otros hay».

**`preguntaPorPrecio()` (`:956`)** expone el `PREGUNTA_PRECIO` que ya existía (§5.3).

**Commit, camino normal (`:1681-1705`).** Con `studio` y panorama:
- con la intención de precios, o una pregunta de precio, o un borrador que no nombra al menos 2 planes vendibles distintos (`plansNamedIn` sobre `activePlans()`) → `policy_plans_overview`;
- `$precios` se calcula también con esa violación;
- en la rama de la política, la lista **manda sobre los sustitutos de un plan** (`:1800-1810`, por ejemplo `planReply`): la persona pidió los planes. `$plan = null` por la regla F3 que ya existía.

**Revisor caído (`safeFallback`, `:2128`).** Precios también si `pideElPanoramaEnElRespaldo()` (`:5225`), que usa la misma política con la intención del turno. `respaldoSemantico()` da la lista si hay precios: hoy solo los hay con precios o panorama, así que no cambia nada de lo anterior.

## 4. Pruebas (los números)

Desde `backend/`, una ejecución de PHPUnit cada vez. Cada salida lleva una cabecera con su comando.

| # | Qué | Resultado |
|---|---|---|
| 33 | **Rojo, ANTES** (`app/` intacto: `git diff --quiet HEAD -- app` = 0), las tres pruebas nuevas | **150 pruebas: 130 errores y 14 fallos** [C]. Los errores son casi todos `Class not found` (las dos clases nuevas). Los 14 fallos son de comportamiento por el endpoint: tuteo, panorama y pregunta sugerida. Del endpoint, 21 de 27 rojas; las 6 verdes fijan lo que ya era correcto y no debe cambiar: cercano y entusiasta, un token sin `t`, `t` fuera de la respuesta a n8n, sin studio, y «¿cuánto cuesta el Mensual?» no es panorama. |
| 34 | Pruebas **existentes** con el cambio y **sin tocar ninguna** | **354 pruebas, 22 rojas** [C]: 20 de `UltronA4LongitudYPreciosTest` (§5.2) y 2 de `UltronDecidePromptsTest::test_la_matriz_de_la_oferta` (la forma del `sp`, §7). La instantánea Legacy, en verde. |
| 35 | **Verde, SQLite** (nuevas, A4, oferta, prompts del commit, traza, `UltronCommitTest` y ajustador) | **OK (504 pruebas, 5 182 aserciones)** [C] |
| 36 | **PostgreSQL 14** (`UNIT_DB=ultron_studio_lead_ola5`): el endpoint nuevo, A4 con su instantánea y la oferta | **OK (266 pruebas, 4 192 aserciones)** [C] |
| 37 | **Guardas** (Isolation, ReadOnly, StaticGuards, SecretExposure) | **138 pruebas, 1 roja: la esperada** (`OutreachIsolationTest::test_la_puerta_de_diff…`). Hoy lista 6 ficheros versionados; **tras el commit listará 8** (más `UltronRegisterDetector.php` y `UltronFormalReplies.php`), y entre ellos `UltronDecideService.php`, `CommercialTurnPolicy.php` y `StrategyContract.php`. La enmienda tiene que cubrir los 8. |
| 38 | **Marketing completo, SQLite** (`tests/Feature/Marketing tests/Unit/Marketing`) | **OK (6 312 pruebas, 37 349 aserciones)** [C] |
| 39 | **Final**: nuevas + A4, tras corregir el generador del documento (horario con su regla `schedule`, fecha del acta en hora de Bogotá) y regenerar `a4-textos-usted.md` | **OK (251 pruebas, 2 033 aserciones)** [C]. Esa corrección solo toca el generador de la prueba, no el código: la 38 corrió con el documento anterior y la 39 con el nuevo. |
| — | `vendor/bin/pint` sobre los 13 ficheros tocados | `passed` [C] (corrigió espacios en dos ficheros nuevos; ningún otro cambio) |

**Lo que cubren las pruebas del plan §5** (todas, salvo la 5, que es del compilador):
- §5.1 detector;
- §5.2 registro y panorama por el endpoint;
- §5.3 completitud y documento;
- §5.4 Legacy byte a byte: la instantánea sin regenerar, un token sin `t`, cercano y entusiasta;
- §5.6 el punto de control único (la prueba estructural de A4, en verde).

## 5. Decisiones que tomé fuera de lo escrito

1. **`UltronDecideService.php` (+8 líneas), que no está en la lista del encargo.** T-5 pide que cambie el contenido de `decide`. `StrategyContract::hints()` se llama antes de la oferta y no recibe el tono, así que no hay otro sitio sin una lectura nueva de la cadena. Es una sola llamada pura tras la oferta (§3.5). Si prefieres otra forma, se quita y T-5 queda solo en `StrategyContract`, sin efecto.
2. **REVÍSALO. `UltronA4LongitudYPreciosTest::publica()` pasa de «profesional» a «cercano» (la línea base), sin tocar ni una aserción.**
   - **Por qué:** esa prueba mide longitud y precios, y publicaba «profesional» porque era el valor de la canaria. Con T-3/T-4, ese tono descarta sus borradores de tú y cambia sus respaldos: 20 rojas en `34`, todas por el tono y ninguna por la longitud.
   - Con la línea base, **todas sus aserciones y `a4-textos-ajustados.md` siguen siendo las de antes**, byte a byte. «profesional» lo prueba `UltronA4RegistroYPanoramaTest`.
   - La alternativa era cambiar ~20 expectativas, que prohibiste. Si no aceptas esto, dime cómo lo quieres.
3. **`CommercialTurnPolicy::preguntaPorPrecio()`**, una segunda función pura y pública. Solo expone `PREGUNTA_PRECIO` para que el commit sepa si el panorama pide precio. `decide()` y `violations()` no cambian.
4. **«Un plan nombrado» no excluye el panorama si además se piden los planes.** El plan se contradice: §3, en el reconocimiento, excluye `hard_required_plan` con un plan; pero §3 y §5 piden que «el Mensual y qué otros hay» sea panorama, con la lista y el Mensual primero.
   - Seguí el ejemplo y la prueba.
   - «¿Cuánto cuesta el Mensual?» no es panorama porque no pide los planes.
   - Añadí «qué/cuáles otros (planes|hay|tienen…)» al patrón; «¿qué otros días abren?» no casa.
5. **En la rama de la política, la lista del panorama manda** sobre `planReply`, la visita y la ficha. La persona pidió los planes, y el sustituto de un plan vuelve a darle uno.
6. **F2 (el acta de la visita registrada) también con `policy_register_mismatch`.**
   - **Por qué:** un borrador que tutea y acompaña a una cortesía ya ejecutada se descarta, y la persona perdía el día y la hora; es el mismo motivo de I-2.
   - El acta va de usted y se ajusta al tope solo si lo hay.
   - Probado indirectamente: `test_el_acta_de_una_visita_registrada_sale_de_usted`, con la guarda de la fecha. No hay prueba propia del descarte más acta **[S]**.
7. **Si la longitud ya descartó el borrador, el registro solo mira:** la traza dice `discarded`, sin recortar.
8. **Mecanismo de tabla (`strtr`) en lugar de una copia de cada productor** (§3.4). La completitud exige que cada clave siga existiendo tal cual en el código de su productor; si un productor cambia un texto, la prueba se pone roja.

## 6. Las dos preguntas

**(i) ¿Se puede volver a publicar tal cual una versión en CANARY firmada con un compilador anterior?** **No** [L, y lo fija una prueba existente].
- Publicar exige `APPROVED`: `StudioPublications.php:977`, `$si($estado !== StudioVersionStatus::Approved, 'invalid_transition')`.
- Una versión `CANARY` no lo es, y `ROLLED_BACK` es terminal (`StudioVersionStatus.php:15`).
- Lo fija `UltronStudioPublicationRulePhrasesTest::test_lo_publicado_con_un_compilador_anterior_cae_a_legacy_hasta_revertirlo_y_publicar_otra_version` (`:479-498`):
  1. lo publicado con un compilador anterior cae a `legacy · compiler_changed`;
  2. se revierte (pasa a `part_legacy`);
  3. se crea **otra versión** con el mismo contenido y se publica. Pasa otra vez por los cuatro ojos (`:976`), el veredicto del laboratorio (`:981`) y la ventana (`:988`).
- Con el compilador 4: la versión 2 (`2e243853…`) hay que **revertirla y crear una versión 3** con el mismo contenido.
- Hasta entonces, con este cambio desplegado y sin el compilador 4, la versión 2 **sigue aplicada** (`sp.t = profesional`): T-3/T-4 actúan en la canaria con el compilador 3.

**(ii) ¿Tutea `knowledge_base.tone` o la ficha de producción?** No lo puedo saber; es contenido de producción [S].
- Lo que sí queda fijado: `test_la_ultima_red_anota_una_ficha_que_tutea_y_sale_igual`, con una pieza `business_identity` «Iron Body Neiva es tu gimnasio en Neiva.».
  - El texto sale igual: «Con gusto le cuento. Iron Body Neiva es tu gimnasio…».
  - `metadata.register.fallback_mismatch = ['tu']` y el aviso, una vez **[C]**.
- `knowledge_base.tone` no lo emite Laravel: va al modelo en `decide` (`context.knowledge_base`). Si tutea, empuja al modelo hacia el tuteo, y el detector descartaría esos borradores. El texto del compilador 4 debería decir que el registro manda también sobre eso.

## 7. Aserciones existentes cambiadas

**Una sola:** `UltronDecidePromptsTest.php:219`.
- La forma del `sp` de `studio` gana `'t' => 'profesional'` junto a `'n' => 200`; esa matriz publica «profesional, 200».
- Motivo: T-1.
- Las ramas Legacy siguen exigiendo el `sp` sin la clave.

Además, el fixture de A4 de §5.2, que **no toca aserciones**.

## 8. Hallazgos adyacentes

> **19-11:** los puntos 1 y 2 **entran en esta tanda** por decisión del lead y quedan resueltos en §12.

1. **El detector de ofertas solo entiende el tú.**
   - `ConversationMemoryService::offerKindOf()` (`:463`) exige `quieres|te gustaria|te interesa|prefieres|deseas|te parece`.
   - Con «profesional», «¿Quiere que le pase la app?» (nuestro pago pendiente), «¿Quiere que le cuente los demás?» (`planReply`) o los borradores de usted del modelo **no se anotan como oferta** (`last_agent_offer`). Un «sí» posterior no se resuelve contra ellos.
   - Ya pasaba con los borradores de usted del modelo antes de este cambio. Es el hallazgo de más impacto para la canaria **[L]**.
2. **Las guardas comerciales que leen el texto solo conocen el tú.**
   - `StrategyContract::PREGUNTA_DESCUBRIMIENTO` (la usa `violations()` en recepción y memoria) no reconoce «¿cuál es su objetivo?», «¿qué busca lograr?» ni «¿ha entrenado antes?».
   - `CommercialTurnPolicy::VENDE_EN_MEMORIA` tiene «tu plan» y «te (cuento|explico)…», pero no sus formas de usted.
   - Con «profesional», un saludo que pregunta el objetivo de usted pasa la política **[L]**. No las toqué: `violations()` no cambia en esta tanda.
3. **El mensaje aparte del enlace de pago** sale de usted, pero sigue fuera del punto de control (A41-6). Los enlaces están apagados.
4. **La puerta de diff tras el commit listará 8 ficheros** (§4, salida 37).

## 9. Lo que no pude comprobar

- **Nada contra n8n, producción ni la canaria.** Todo es PHPUnit local (SQLite y PostgreSQL 14 desechable).
- **El PCRE de producción (10.39).** Las dos clases nuevas usan clases de caracteres explícitas, sin `\p{…}` ni *lookbehind*. No las ejecuté en el servidor, como sí se hizo con el ajustador en `a4-evidencia/32` **[S]**.
- **El texto real de `knowledge_base.tone` y de la ficha de producción** (ii).
- **Cuántos borradores descartará T-3 en la canaria** hasta el compilador 4. Más respaldos, pero de usted; se mide con `metadata.register` en la prueba real.
- Studio completo y batería completa: no los ejecuté (no estaban en el encargo).

## 10. Variables, migraciones y configuración

Nada nuevo: sin migraciones, rutas, claves de `config/` ni variables de entorno. La metadata nueva (`register`) es aditiva.

**Vuelta atrás:** revertir los 6 ficheros de `app/` y borrar los 2 nuevos. No hay datos que deshacer.

**Despliegue:** lista cerrada de **9** ficheros de producto (los 8 de antes más `ConversationMemoryService.php`, §12). Hace falta una enmienda para la puerta de diff y las protecciones 5 a 8 del H9.

---

## 11. Añadido del propietario: los datos comerciales salen del CRM, en vivo (V-1 a V-4)

19-11-2026 · mismo árbol, sin commit. **Tu lectura es correcta**, con cuatro matices que marco en ⚠.

### 11.1 Ruta real de los datos comerciales (V-1)

**(1) Qué endpoint y qué lectura.**
- n8n llama a `POST /api/internal/marketing/ai/decide` y `…/ai/commit`: `routes/marketing.php:90-91`, bajo el prefijo `internal/marketing` (`:45`). Llegan a `Api/Internal/UltronController::decide` (`:83` → `UltronDecideService::decide`, `:339`) y `::commit` (`:159` → `UltronCommitService::commit`).
- El catálogo se lee de **una sola fuente**, `MarketingKnowledgeBaseService::activePlans()` (`:125-167`): `Plan::sellable()`, es decir activo, vendible, sin archivar, precio > 0 y días > 0 (`Plan.php:302-311`). Ordena por `is_recommended DESC, sort_order`.
- En `decide` van al modelo `context.active_plans = plansWithoutPrice()` (`UltronDecideService.php:477`, `:648-676`) y `context.default_plan_id = defaultMonthlyPlan()?->id` (`:528`) [L, y por el endpoint en V-2 (d) y (e)].

**(2) Uno o varios.** `decide` da **todos** los vendibles (13 hoy, sin precio: V-2 (e)). El modelo elige **un** `recommended_plan_id`, y por eso solo puede citar un precio:
- el commit lo resuelve contra una sola fila: `resolvePlan()` (`UltronCommitService.php:2705`), `Plan::find` (`:2720`), `isSellable()` (`:2746`);
- sustituye todos los marcadores con ese plan: `placeholders->resolve($sanitized['reply'], $plan)` (`:625`); `UltronDraftPlaceholders::resolve(string, ?Plan)` (`:131-147`) recibe **un** `Plan`;
- una cifra escrita por el modelo no pasa los guards.

Dos `{{PLAN_PRICE}}` en el mismo borrador salen con la misma cifra.

**(3) De dónde salen precios y beneficios. ¿Hay caché?**
- **Precio:** `Plan::price` leído **al componer**, en el commit, no en `decide`. V-2 (a) cambia el precio entre `decide` y `commit` y sale el nuevo [C].
- **Nombre y duración:** de la misma fila (`{{PLAN_NAME}}`, `{{PLAN_DURATION}}`).
- **Beneficios:** `Plan::benefitsArray()` → `active_plans` → el modelo los redacta **en prosa**, sin marcador.
  - ⚠ **Ningún guard determinista comprueba que los beneficios que escribe el modelo sean los del plan.** Lo pide el prompt base (`composer.txt:50`: «sus beneficios son los de active_plans, sin adornos») y lo vigila el revisor. `sinServiciosInventados()` mira clases y ficha, no beneficios de plan [L].
  - En la prueba real del 18-11 salieron bien (dictamen §5).
  - `planReply()` (`CommercialTurnPolicy.php:723`) sí los toma del catálogo; la lista de precios no los lleva.
- **Caché: ninguna** entre el CRM y la respuesta. No hay `Cache::` en `MarketingKnowledgeBaseService`, `Plan`, `UltronDraftPlaceholders` ni `UltronDecideService`. En el commit solo hay `Cache::lock` (`:207`), que es el cerrojo de la conversación y no guarda datos. Cada lectura es una consulta [C, por `grep` y por V-2].
- El `knowledge_version` firmado en el token es de la base de conocimiento, no de los planes.
- n8n no guarda datos de planes: `elegir-prompts.js` y `preparar-commit.js` no llevan ninguno [C, guarda V-3].

**(4) ¿Un cambio en el CRM llega a WhatsApp sin tocar prompts?** **Sí** [C]. V-2 cambia, sin tocar prompts, n8n ni código entre un turno y el siguiente:
- el precio;
- el nombre;
- `sellable` y `archived_at`;
- `sort_order` e `is_recommended`;
- y un catálogo sintético entero.

Todo sale en el turno siguiente, y el precio incluso entre `decide` y `commit`.
- ⚠ Si un plan deja de venderse **entre** `decide` y `commit` y el modelo lo había elegido, el commit lo rechaza (422 `plan_not_sellable`, `:2746`) y n8n hace su rendición. Es lo correcto: nunca cotiza un plan que no se vende.

**(5) «La mensualidad» entre Valera y Mensual.**
- ⚠ No la decide `is_recommended`, sino `defaultMonthlyPlan()` (`MarketingKnowledgeBaseService.php:179-190`): el primer vendible por `sort_order` con 28 a 31 días **o** con «ensual» en el nombre; si no hay ninguno, el primero por `sort_order`.
- Hoy: Valera (`sort_order` 3) antes que Mensual (4) → `default_plan_id = 3`.
- **Lo cambia `sort_order`** (V-2 (d): con el Mensual en 0 → `default_plan_id = 4`) [C].
- **`is_recommended` no lo cambia** (V-2 (d): `default_plan_id` sigue en 4) [C]. Sí cambia otras dos cosas: pone ese plan el primero de `active_plans` y de la lista, y lo convierte en el plan obligatorio cuando la persona pide recomendación (`planInsignia()`, `CommercialTurnPolicy.php:771`).
- ⚠ El `orWhere('name', 'like', '%ensual%')` es una regla por **nombre**: un plan llamado «Mensual…» entra aunque no dure un mes. Es previo; lo señalo para H4-8.

**(6) Cómo presenta la lista varios planes dentro de 200 sin inventar ni ocultar.** `respaldoDePrecios()` (`UltronCommitService.php:5323`).

*Cuáles salen:*
1. el plan que nombró la persona;
2. después los vendibles de 28 a 31 días, en el orden de `activePlans()`;
3. **como mucho 3**, y nunca los rechazados;
4. sin ninguno de un mes, los 3 primeros con su duración.

*Las cifras* salen de `Plan::price` (`Plan::query()->whereIn`, `:5345`).

*Qué dice de los que no nombra:* solo **qué duraciones quedan** (`:5390`): 1 día → «por día»; 5 a 9 → «por semana»; 32 o más → «de varios meses». No los nombra ni los cuenta.

*Cómo cabe* (`UltronReplyFitter::pricingLayout`, `:375`): se suelta «También…», después el 3.er plan, después la pregunta y después el 2.º. Con producción mide 191 a 200 (los 3 y «También…») y 133 a 160 (sin «También…»).

- ⚠ **Hueco de redacción (no de datos):** con 4 vendibles de un mes (hoy Valera, Mensual, Élite y PROMO X4), el 4.º no se nombra, y «También hay…» no lo menciona porque es de la misma duración. «Claro. Planes de un mes: A, B, C.» no dice que sean todos, pero tampoco que haya más.
- **Propuesta para el propietario** (V-4, texto exacto, **no implementada**): cuando haya más planes de un mes de los que caben, el encabezado pasa a ser **`Claro. Algunos planes de un mes: `**. Es lo único que haría falta.

### 11.2 Pruebas de que la fuente es dinámica (V-2), por el endpoint

En `UltronA4RegistroYPanoramaTest`, con las clases reales y la versión aplicada en la línea base del tono (para medir la fuente y no el registro):

| Caso | Qué cambia en `plans` | Qué sale | Prueba |
|---|---|---|---|
| (a) | `price` del Mensual 80 000 → 85 000; y → 87 500 **entre `decide` y `commit`** | `{{PLAN_PRICE}}` = «$85.000 COP», después «$87.500 COP» (`price_source.price` = 87 500); la lista, «Plan Mensual, $87.500 COP» | `test_v2a_…` |
| (b) | `name` Plan Valera → «Plan Ónix» | `active_plans` con el nombre nuevo y sin el viejo; `{{PLAN_NAME}}` = «Plan Ónix»; la lista empieza por «Plan Ónix, $65.000 COP» | `test_v2b_…` |
| (c) | Valera `sellable = false`; Mensual `archived_at` | Los dos fuera de `active_plans`; la lista pasa a ser «Élite, $180.000 COP; PROMO X4, $75.000 COP» | `test_v2c_…` |
| (d) | Mensual `sort_order` = 0; después Élite `is_recommended` | `default_plan_id` 3 → 4 y la lista con el Mensual primero; con el recomendado, Élite primero en `active_plans` y en la lista, y `default_plan_id` sigue en 4 | `test_v2d_…` |
| (e) | — | `active_plans` con los 13 vendibles, ninguno con `price` ni `original_price`, y ninguna cifra de dinero en el JSON | `test_v2e_…` |
| (f) | Catálogo **sintético**: Plan Zafiro $123.456 (30 días), Plan Ámbar $98.765 (30) y Membresía Cuarzo $234.567 (90), el resto no vendible | Lo de abajo | `test_v2f_…` |

Lo que sale en (f):
- `active_plans` = esos tres y `default_plan_id` = Zafiro;
- la lista, exacta: «Claro. Planes de un mes: Plan Zafiro, $123.456 COP; Plan Ámbar, $98.765 COP. También hay planes de varios meses. ¿Sobre cuál amplío la información?»;
- `{{PLAN_NAME}}/{{PLAN_PRICE}}/{{PLAN_DURATION}}` = «El Plan Ámbar cuesta $98.765 COP y dura un mes.»;
- y con «profesional, 200» y «¿Qué planes tienen?», la misma lista (P-1).

**Ninguno de esos nombres ni cifras existe en el código, los prompts ni n8n** (lo garantiza V-3).

Estas pruebas fijan una propiedad que **ya se cumplía** antes de este cambio, salvo el último caso de (f), que depende de P-1 (el panorama con el revisor caído). Por eso no hay rojo «antes» que tenga sentido.

### 11.3 La guarda estática (V-3): `tests/Unit/Marketing/Ultron/UltronCatalogStaticGuardsTest.php`

**Dónde mira:**
- `resources/ultron-prompts/*/*.txt`, todo el texto;
- `n8n/flujos/ultron-studio-s1/*.js`, todo el texto, comentarios incluidos: lo más estricto, y hoy no estorba;
- el código **no comentario** de `app/Services/Marketing/Ultron/`, con `token_get_all` sin `T_COMMENT`/`T_DOC_COMMENT`, el mismo criterio que `StudioLabIsolationTest::tokens()`. Las cadenas y las regex cuentan.

**Contra qué catálogo:**
- el de producción del 12-11 (13 planes);
- **más `PlansSeeder`, leído del fichero en cada ejecución**: nombres, precios, precios originales y los beneficios de sus constantes `BENEFITS_*`;
- más «Total Access».

**Cómo compara:** por palabra entera, sin mayúsculas ni tildes. Los precios, en sus formas «80000», «80.000», «80,000», «80 000», «80 mil» y «80k». Una prueba del propio buscador fija que `0x10000`, `T_TONE_PRO_COMPOSER`, «profesional» y los marcadores no cuentan.

Su nombre contiene `StaticGuards`, así que entra en el lote de guardas que diste.

**Excepciones vivas.** Si una desaparece, la guarda falla para que se borre también de aquí:

| Fichero | Término | Qué es |
|---|---|---|
| `CommercialTurnPolicy.php:86` (`VENDE_EN_MEMORIA`) y `:89` (`VENDE_EN_SALUDO`) | «total access» ×2, «plan elite» ×1 | **Lo que me pediste mirar.** Es vocabulario de un detector, no una fuente: forma parte de las expresiones con las que `violations()` decide que un saludo puro o un turno de memoria «se puso a vender». «Total Access» es el nombre del tier *premium* de `PlansSeeder` (`:12`, `:55`, `:90`); fue el plan insignia y **hoy no hay ningún plan con ese nombre**. «plan elite» es el Élite de hoy. La misma regla ya mira los planes del catálogo vivo con `plansNamedIn()`, así que estos dos literales son redundantes y están envejecidos. Sin arreglar. |
| `ReferenceResolver.php:64` (`NOMBRE_DE_CALENDARIO`) | trimestre, semestre, anualidad | Palabras del calendario (lista cerrada del idioma) que, por coincidir con un plan, **no** bastan para elegirlo. No es una fuente. |
| `resources/ultron-prompts/n8n-29de5119/composer.txt:50` y su copia en `elegir-prompts.js:15` | «trimestre» ×2 | Un ejemplo del prompt base: «si se llama "trimestre", no es "plan trimestre"». No es precio ni beneficio, y la base no se edita. |

**Lo que cazó en mi propio código:** mi filtro del panorama llevaba «trimestre|semestre» como palabras de duración. **Lo quité.** Ahora solo usa «días, semanas, mes, meses, años», y el docblock dice por qué.

**Mutación** (`a4-evidencia/40`): un fichero temporal con «Plan Mensual, $80.000» en un comentario y «Plan Valera por $65.000» en una cadena. La guarda se pone **roja y nombra solo lo de la cadena** («plan valera», 65000). El fichero se borró (`git status` limpio de él).

Fuera del alcance de la guarda, pero señalado: `n8n/flujos/ultron-studio-s1/flujo-s1.json` y `operaciones-mcp.json` llevan el mismo código embebido. La guarda mira solo los `.js`, como pediste. Por el escaneo previo (los dos JSON con los mismos términos y en los mismos sitios que los `.js`) no llevan datos del catálogo, pero no comprobé si `verificar-flujo.cjs` exige que coincidan con los `.js` [S].

### 11.4 P-1 sigue 100 % derivada (V-4)

El panorama usa `respaldoDePrecios()` **sin cambiar ni una línea**:
- nombres, precios, duraciones y «También hay planes…» salen del catálogo;
- lo único fijo son las palabras de unión ya aprobadas en A41-9 («Claro. Planes de un mes: », «Claro. Nuestros planes: », « También hay planes … », « ¿Sobre cuál amplío la información?») y «por día / por semana / de varios meses»;
- **P-1 no necesita ninguna palabra de unión nueva.**

La única propuesta es la del encabezado de §11.1 (6), para cuando hay más planes de un mes de los que caben, y queda pendiente de que el propietario la apruebe.

### 11.5 Pregunta de diseño: que el modelo redacte varios planes con sus precios del CRM (solo análisis)

**Mecanismo mínimo.** Marcadores con el id: `{{PLAN_PRICE:4}}`, `{{PLAN_NAME:4}}`, `{{PLAN_DURATION:4}}`.
- El id tiene que estar en `active_plans` de ese turno.
- Como mucho 3 ids distintos.
- La propuesta lleva `plan_ids` (lista), además del `recommended_plan_id` de hoy, que sigue siendo «el que se cotiza» para el cobro y la memoria.

**Qué tocaría:**
- **Prompts.** Si se toca la base, cambian **la base y la huella Legacy** (`3f6a5674…`) y las constantes Legacy de n8n: **afecta al agente Legacy**, el riesgo mayor. La alternativa sin ese riesgo es un **parche del compilador solo para versiones del Studio**: el texto que enseña los marcadores con id al redactor, y al revisor «hasta 3 precios, cada uno junto a su plan». Esa opción no toca la base y sube el número del compilador (hay que volver a publicar).
- **Contrato con n8n.** `plan_ids` en la propuesta: validación (`SalesAgentDecisionValidator`, el esquema de la propuesta), `preparar-commit.js` y el comprobador del flujo.
- **`UltronDraftPlaceholders`.** El `PATTERN` con `:id`; `resolve()` con un mapa id → `Plan` (cada uno con `resolvePlan()`: vendible y en `active_plans`); `needsPlan()`; `unknown()`.
- **Invariantes del dinero** (lo más delicado):
  - hoy `planOwningPrice()` exige que la cifra tenga dueño en su oración; habría que comprobar **por oración** que `{{PLAN_PRICE:4}}` va junto al nombre del plan 4, y no al del 3;
  - `plansNamedIn()`/`primerPlanNombrado()` contra `required_plan_id`;
  - el guard sobre el esqueleto.
- **Memoria y traza.** `recordSentTurn()` solo anota un plan (hoy lo suple `conPreciosEntregados()` para la lista); `price_source` es uno; `marketing_ai_actions` guarda un `plan_id`; el enlace de pago es de un plan.
- **Longitud.** Tres nombres con su precio ocupan ~110-130 caracteres. A 160 o 200 el redactor tiene poco margen, y el recorte F1 solo quita la pregunta final: muchos se descartarían y saldría… la lista determinista.

**Riesgo frente a la lista determinista:**
- **Atribución.** Un precio pegado al plan equivocado es justo el fallo que más ha costado (el «210.000 por 80.000»). La lista no puede cometerlo por construcción.
- **Selección.** El modelo elige qué planes enseña: puede omitir el recomendado o el más barato, o mezclar duraciones. La lista sigue reglas fijas del catálogo.
- **Coste.** M-L: compilador, contrato, validador, marcadores, invariantes, memoria, revisor y una prueba real.
- **Ganancia.** La respuesta puede adaptarse («para lo que me cuenta, el Mensual; también tiene el Valera y el Élite»).

**Recomendación:** la lista determinista para «panorama con precio», como en P-1. Si el propietario quiere la versión del modelo, que sea con un parche del compilador solo para Studio, comprobación de dueño por oración y la lista como respaldo cuando falle.

### 11.6 Ficheros y números de este añadido

**Ficheros:**
- `tests/Unit/Marketing/Ultron/UltronCatalogStaticGuardsTest.php`: nuevo, 3 pruebas.
- `tests/Feature/Marketing/UltronA4RegistroYPanoramaTest.php`: +6 pruebas (V-2).
- `app/Services/Marketing/Ultron/CommercialTurnPolicy.php`: el filtro sin «trimestre|semestre» y su comentario.
- Nada más de producto.

| # | Qué | Resultado |
|---|---|---|
| 40 | Mutación de la guarda (literal en cadena y en comentario) | **Roja**, solo con lo de la cadena [C]; fichero temporal borrado |
| 41 | Verde, SQLite: guarda, detector, panorama, endpoint (con V-2), A4, oferta, prompts del commit, `UltronCommitTest` y ajustador | **OK (508 pruebas, 5 253 aserciones)** [C] |
| 42 | PostgreSQL 14, primera pasada: endpoint y A4 | 134 pruebas, **1 error de la prueba**: `plans_pkey`, porque la secuencia no avanza con los ids explícitos del catálogo; los planes sintéticos de (f) usaban ids automáticos |
| 43 | PostgreSQL 14, segunda pasada (planes sintéticos con ids 901-903) | **OK (134 pruebas, 1 916 aserciones)** [C] |
| 44 | Guardas (con la nueva) | **141 pruebas, 1 roja: la esperada** (la puerta de diff) [C] |
| 45 | Marketing completo, SQLite (`tests/Feature/Marketing tests/Unit/Marketing`) | **OK (6 321 pruebas, 37 451 aserciones)** [C] |
| — | `pint` sobre los ficheros tocados | `passed` [C] |


---

## 12. Añadido del lead (19-11): decisiones aceptadas y los detectores entienden el usted

### 12.1 Decisiones aceptadas

El lead aceptó las decisiones (1) a (8) de §5.

- **(2):** el comentario junto al cambio de `publica()` ya está, en el docblock de `UltronA4LongitudYPreciosTest::publica()`.
- **(6):** tiene ahora prueba directa, `test_el_acta_sale_de_usted_cuando_el_registro_descarta_un_borrador_con_cortesia_ejecutada`.
  - El escenario es el borrador de R7, que tutea en el cuerpo, con «profesional» y sin tope: solo actúa el registro.
  - Sale «Dejé registrada su solicitud de cortesía para el sábado 21 de noviembre a las 10:00 a. m. El equipo de Iron Body la revisará para tener todo preparado.».
  - Lleva `policy_register_mismatch` y no `policy_length_exceeded`; `price_source` es null [C].

### 12.2 Quién usa `ConversationMemoryService` (lo pediste antes de tocarlo)

[C, por `grep` en `app/`]
- **Fuera de ULTRON solo lo usa `MarketingPaymentOutcomeService`** (`:10`, `:40`), y únicamente `recordPaymentOutcome()` (`:71`), que no pasa por `offerKindOf()`.
- Las coincidencias en `SalesAgentOrchestratorService` y `SalesConversationMemoryService` son de **otra clase** (`SalesConversationMemoryService`, del agente Legacy).
- `recordSentTurn()` → `offerKindOf()` solo lo llama `UltronCommitService` (3 sitios).

Es de ULTRON, así que seguí.

### 12.3 Qué cambió (fichero y línea)

**La condición del lead se cumple:** sin la versión en «profesional», los tres detectores son exactamente los de hoy. Las formas de usted se miran **solo** con `tono === 'profesional'`, que llega por un parámetro nuevo con valor por defecto `null`; la llamada de hoy queda intacta.

| Detector | Cambio |
|---|---|
| `ConversationMemoryService` | `OFERTAS_DE_USTED` (`:56`): las mismas clases y en el mismo orden, con «inscribirse», «pase a conocer», «le cuente más / sobre / cómo», «le explique…» y «le recomiende». `VERBO_DE_OFERTA` (el de siempre, sacado a constante) y `VERBO_DE_OFERTA_DE_USTED`: «quiere», «le gustaría», «le interesa», «prefiere», «desea», «le parece». `recordSentTurn(…, bool $esUltimoRecurso = false, ?string $tono = null)` y `offerKindOf($question, ?string $tono = null)` (`:486`): con «profesional», cada clase se reconoce por su patrón de tú **o** por el de usted, en el mismo orden de precedencia. |
| `StrategyContract` | `PREGUNTA_DESCUBRIMIENTO_DE_USTED` (`:46`). `asksDiscovery($text, ?string $tono = null)` (`:178`) y `asksDiscoveryToReadyBuyer(…, ?string $tono = null)` (`:292`) comparten `preguntaDeDescubrimiento()` (`:184`). La de tú cuenta en cualquier sitio, como siempre. **La de usted, solo dentro de una pregunta**, porque de usted coincide con la tercera persona: «nuestro equipo tiene experiencia» no se caza, y lo fija una prueba. |
| `CommercialTurnPolicy` | `VENDE_EN_MEMORIA_DE_USTED` (`:93`): «su plan» y «le cuento / le explico (más) sobre / de las clases / los planes / el plan». `violations(…, array $knownFacts = [], ?string $tono = null)` (`:592`) pasa el tono a `asksDiscovery()` en recepción y en memoria, y mira la de usted en memoria (`:620`). |
| `UltronCommitService` | Pasa `$turno['tono']` a las 3 llamadas a `violations()`, a `asksDiscoveryToReadyBuyer()` y a las 3 de `recordSentTurn()` (`$delStudio['tono']` en la de la hora de la visita). En `safeFallback()` y en la hora de la visita, `esUltimoRecurso` va explícito como `false`, el valor por defecto que ya tenían. |

`asksDiscoveryToReadyBuyer()` no estaba en tu lista. Lo incluí porque usa la misma expresión: solo añade una bandera de riesgo (`discovery_question_to_ready_buyer`) a la fila y no cambia lo que sale.

### 12.4 Pruebas

| Prueba | Qué fija |
|---|---|
| **(a)** `test_a_el_respaldo_de_pago_ofrece_de_usted_y_el_si_se_resuelve_contra_esa_oferta` | Con «profesional, 200», el revisor caído en `payment_link_request` da «…¿Quiere que le pase la app para descargarla?». La memoria la anota como oferta (`generic_offer`, igual que la de tú) y el «Sí, por favor» siguiente se resuelve como `accept_offer` [C]. |
| **(a)** `test_a_una_oferta_de_usted_del_modelo_queda_anotada_con_su_clase` | «¿Quiere que le cuente cómo empezar?» se anota como `explain_how_to_start` y el «Sí» se resuelve `accept_offer` de esa clase [C]. |
| **(b)** `test_b_un_saludo_que_pregunta_el_objetivo_de_usted_lo_caza_la_politica_igual_que_el_de_tu` | «…¿Ya ha entrenado antes?» en un saludo da `policy_greeting_sells` y la bienvenida de usted. La misma de tú con «cercano» se caza igual. De usted fuera de una pregunta no se caza [C]. |
| `test_un_turno_de_memoria_que_ofrece_los_planes_de_usted_lo_caza_la_politica` | «Si quiere, le cuento sobre los planes.» ante «¿A qué hora era mi visita?» da `policy_memory_turn_sells` y la memoria de usted (M1) [C]. |
| `UltronUstedDetectoresTest` (8, puras) | Cada pregunta de usted cuenta solo con «profesional», y nunca sin tono, con «cercano» ni con «entusiasta». La de tú, con cualquier tono. El modo memoria, igual [C]. |
| **Legacy** `test_legacy_un_borrador_de_usted_da_lo_mismo_que_antes` (2 modos: token sin `sp` y llave apagada) | Instantánea nueva `tests/fixtures/ultron-a4/legacy-usted.json`, **tomada antes de tocar los detectores** (con `ConversationMemoryService` sin diff y `PREGUNTA_DESCUBRIMIENTO`/`VENDE_EN_MEMORIA` sin tocar). Hoy en Legacy pasa esto: el saludo y la memoria de usted pasan sin violación; la oferta de usted no se anota (`oferta: null`); el «Sí» siguiente es `affirmation_without_offer`. Tras el cambio, **idéntica** [C]. |

Puse el caso en una instantánea propia para que `legacy-a4.json` siga **sin regenerarse**: añadirle un caso habría sido regenerarla. `legacy-a4.json` sigue en verde.

### 12.5 Números (una ejecución de PHPUnit cada vez)

| # | Qué | Resultado |
|---|---|---|
| 46 | **Rojo, ANTES** de los detectores: las 7 del añadido | **4 rojas** (2 errores, 2 fallos): (a)×2, (b) y la de memoria. Verdes: la del acta (F2 ya extendido) y las dos Legacy (la instantánea recién tomada) [C] |
| 47 | **FINAL, SQLite:** ajustador, detector, detectores de usted, panorama, guarda del catálogo, los dos A4, `UltronDecidePromptsTest`, `UltronCommitPromptsTest` y `UltronCommitTest` | **OK (523 pruebas, 5 346 aserciones)** [C] |
| 48 | **FINAL, PostgreSQL 14:** el A4 nuevo y A4 | **OK (141 pruebas, 1 969 aserciones)** [C] |
| 49 | **FINAL, guardas** | **141 pruebas, 1 roja: la esperada** (la puerta de diff). Hoy lista 7 ficheros versionados (con `ConversationMemoryService.php`); **tras el commit, 9** con los dos nuevos [C] |
| 50 | **FINAL, Marketing completo, SQLite** (`tests/Feature/Marketing tests/Unit/Marketing`) | **OK (6 336 pruebas, 37 544 aserciones)** [C] |
| — | `pint --test` sobre los 16 ficheros tocados (9 de producto y 7 de prueba) | `passed` [C] |

### 12.6 Lo que no cubre

- Otros detectores de texto de la memoria que solo conocen el tú y no estaban en el encargo. Por ejemplo, `recordSentTurn()` anota el hecho «cómo empezar» con «te inscribes / vienes con / llegas y» (`ConversationMemoryService.php:198`). Con «profesional», una explicación de usted de cómo empezar no lo anota como hecho entregado. Es menor, y se arreglaría con la misma pauta.
- `ReferenceResolver` no lo toqué. El «sí» se resuelve por la oferta anotada, que ahora existe.

---

## 13. Cierre (19-11): el encabezado veraz de la lista (hueco 2)

**Estado:** el texto nuevo está **PENDIENTE de la aprobación del propietario**, que lo presenta el lead en el gate. Si lo rechaza, se vuelve a `Claro. Planes de un mes: ` en `UltronCommitService.php:5395-5420`; es un cambio de una función.

El hueco 1 (beneficios sin guarda determinista) y los literales «total access» y «plan elite» (`CommercialTurnPolicy.php:86/89`, excepción documentada de la guarda) **no se tocan**: los lleva el lead a pendientes.

### 13.1 Qué cambió

**`UltronCommitService::respaldoDePrecios()` (`:5395-5420`).** El encabezado sale del catálogo:

| Caso | Encabezado |
|---|---|
| Todos los nombrados son de un mes y quedan planes de un mes vendibles **sin nombrar** (no se cuentan los rechazados de F6) | **`Claro. Algunos planes de un mes: `** |
| Se nombran **todos** los de un mes | `Claro. Planes de un mes: ` (el de hoy) |
| Mixto o sin planes de un mes | `Claro. Nuestros planes: ` (sin cambios) |

**Cómo se decide.** Sobre lo que sale de verdad:
1. Primero, con el encabezado de la lista entera.
2. Si el tope suelta un plan y así queda alguno de un mes sin nombrar, se vuelve a ajustar con «Algunos». Puede soltar uno más, nunca devolver uno soltado.

Si un nombre largo no cabe, se suelta «También hay…» como hasta ahora (el orden de caída de `UltronReplyFitter::pricingLayout` no cambia). `metadata.length.from` mide la lista entera con su propio encabezado.

**Producción** (Valera, Mensual, Élite y PROMO X4 de un mes; se nombran tres): **199 a 200**, cabe; **141 a 160** (sin «También…») [C].

### 13.2 Pruebas

**Nuevas:**
- `test_el_encabezado_dice_algunos_solo_si_quedan_planes_de_un_mes_sin_nombrar`:
  - producción → «Algunos…», mide **199**;
  - **tres de un mes exactos** (PROMO X4 fuera de la venta) → «Planes de un mes», sin «Algunos» (191);
  - los mismos tres con el Élite renombrado largo a 160 → el tope suelta el tercero → «Claro. Algunos planes de un mes: Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP. ¿Sobre cuál amplío la información?» [C].
- **V-2 (f), el catálogo sintético**, ampliado:
  - con sus dos de un mes, los dos nombrados → sigue «Planes de un mes» (esa parte **no cambia**);
  - con un 3.º y un 4.º sintéticos (Plan Ópalo $111.111, Plan Topacio $222.222) → «Claro. Algunos planes de un mes: Plan Zafiro, $123.456 COP; Plan Ámbar, $98.765 COP; Plan Ópalo, $111.111 COP. También hay planes de varios meses. ¿Sobre cuál amplío la información?» [C].

**Actualizadas, solo el literal del encabezado:**
- `UltronA4LongitudYPreciosTest`, 8 literales: `PRECIOS_200`, `PRECIOS_160` y 6 aserciones. Lo usan 12 pruebas: `precios_con_tope_200`, `_160`, `con_studio_sin_tope`, `el_plan_que_nombra_la_persona`, `la_regla_depende_del_orden`, `un_borrador_que_cierra_con_el_precio`, `la_pregunta_final_con_el_unico_precio`, `si_no_se_puede_recortar`, `r1`, `precios_en_la_rama_de_la_politica`, `el_respaldo_de_precios_no_nombra_un_plan_rechazado` (solo su caso «el rechazado se vuelve a nombrar»; el caso **sin Valera**, con 3 de un mes todos nombrados, **sigue** en «Planes de un mes») y `las_demas_duraciones`.
- `UltronA4RegistroYPanoramaTest`, 6 literales.
- La salida `51` muestra las 20 rojas antes de actualizar: todas por el encabezado y ninguna por otra cosa.

**Sin cambios:** `UltronReplyFitterTest`, que recibe el encabezado como dato; V-2 (c) (Élite y PROMO X4: los dos de un mes nombrados); `legacy-a4.json`, porque Legacy no tiene respaldo de precios.

### 13.3 `a4-textos-ajustados.md`: exactamente qué cambia

Regenerado con `A4_TEXTOS=escribir` (`a4-evidencia/52`, el diff). **9 líneas**: 3 secciones × las 3 filas con versión aplicada (320, 200 y 160). En las tres pasa «Planes de un mes» → «Algunos planes de un mes», y la longitud de 191/191/133 a **199/199/141**:
1. `pricing_question` — entrante «¿Qué planes tienen y cuánto cuesta la mensualidad?» (sección «Por intención»).
2. «¿Qué planes tienen y cuánto cuesta la mensualidad?» (sección «Precios»).
3. «¿Y el Élite cuánto cuesta? ¿Qué planes tienen?» (nombra un plan; Élite primero).

**No cambian:** las filas «Hoy (Legacy)»; «¿Cuánto vale el plan día?…» y «¿Y el Trimestre…?», que mezclan duraciones y van con «Nuestros planes»; ni ningún otro texto.

### 13.4 Números del cierre

| # | Qué | Resultado |
|---|---|---|
| 51 | Encabezado hecho, pruebas sin actualizar | 168 pruebas, **20 rojas, todas por el literal del encabezado** [C] |
| 52 | Diff de `a4-textos-ajustados.md` | 9 líneas, las de §13.3 [C] |
| 53 | **Cierre, SQLite:** ajustador, detector, detectores de usted, panorama, guarda del catálogo, los dos A4, `UltronDecidePromptsTest`, `UltronCommitPromptsTest` y `UltronCommitTest` | **OK (524 pruebas, 5 359 aserciones)** [C] |
| 54 | **Cierre, PostgreSQL 14:** el A4 nuevo y A4 | **OK (142 pruebas, 1 982 aserciones)** [C] |
| 55 | **Cierre, guardas** | **141 pruebas, 1 roja: la esperada** (la puerta de diff) [C] |
| 56 | **Cierre, Marketing completo, SQLite** (`tests/Feature/Marketing tests/Unit/Marketing`) | **OK (6 337 pruebas, 37 557 aserciones)** [C] |
| — | `pint --test` sobre todos los ficheros tocados | `passed` [C] |

### 13.5 Ficheros de producto tocados en toda la tanda (la lista exacta para el despliegue)

**Nuevos (2):**
1. `app/Services/Marketing/Ultron/UltronRegisterDetector.php`
2. `app/Services/Marketing/Ultron/UltronFormalReplies.php`

**Modificados (7):**
1. `app/Services/Marketing/Ultron/UltronCommitService.php` (+306/−97)
2. `app/Services/Marketing/Ultron/CommercialTurnPolicy.php` (+100/−4)
3. `app/Services/Marketing/Ultron/StrategyContract.php` (+78/−5)
4. `app/Services/Marketing/Ultron/ConversationMemoryService.php` (+31/−4)
5. `app/Services/Marketing/Ultron/UltronReplyFitter.php` (+16/−2)
6. `app/Services/Marketing/Ultron/Prompts/UltronPromptOffer.php` (+15/−3)
7. `app/Services/Marketing/Ultron/UltronDecideService.php` (+8)

Sin migraciones, rutas, `config/` ni n8n. **Diff cero** en `SalesConversationReplyService.php`, `SalesObjectionResponderService.php`, `OutboundContentGuard.php`, `CourtesyRequestService.php`, `UltronPromptCompiler.php`, `UltronPromptStamp.php` y los prompts base [C].

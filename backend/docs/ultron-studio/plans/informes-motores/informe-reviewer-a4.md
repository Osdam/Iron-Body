# Informe del reviewer · Apartado 4 · commit `daedbbd8` (tope de longitud y respaldo de precios)

12-11-2026 · revisión final independiente, una sola pasada · solo lectura sobre el repositorio.
Revisado: `git diff 7aa46911 daedbbd8 -- backend/app backend/tests` (los tres ficheros de producto, las pruebas nuevas, la aserción cambiada y la instantánea), con el informe del backend, el plan del arquitecto, `a4-textos-ajustados.md` y `canario-a3/02-mensajes-y-diagnostico.txt`.
Marcas: **[C]** comprobado ejecutando · **[L]** leído en el código · **[S]** supuesto, sin comprobar.

> **Cómo ejecuté sin pisar a nadie.** No ejecuté PHPUnit en el árbol compartido. Usé dos copias aisladas en el scratchpad, sacadas con `git archive` (no `git worktree`) y con `vendor/` clonado (`cp -c`):
> - `new` = `daedbbd8`;
> - `old` = `daedbbd8` con los dos ficheros de `app/` de `7aa46911` (y, en una segunda pasada, con `UltronReplyFitter.php` añadido para aislar el comportamiento).
>
> SQLite en memoria, como `phpunit.xml`. Los escenarios propios del revisor estaban en una clase de prueba **solo en el scratchpad** (`RevisorA4Test`), que no está en el repositorio.
> El árbol compartido tiene ahora cambios sin commit de otro agente (compilador 3: `UltronPromptCompiler.php`, `StudioPublications.php`, `UltronDecidePromptsTest.php`, fixtures…). **No son parte de esta revisión.** Los tres ficheros de producto revisados no tienen cambios respecto de `daedbbd8` (`git diff --quiet`).

---

## Veredicto

**RECHAZADO.** Equivale a REQUIERE CAMBIOS, y hay un solo bloqueante.

El diseño se sostiene:
- Legacy no ejecuta ninguna rama nueva, y la instantánea es válida y no circular **[C]**;
- el único punto de control está justo antes de cada envío **[C]**;
- el guard mira el esqueleto **[C]**;
- las pruebas pasan por el endpoint con las clases reales y detectan casi todas las mutaciones **[C]**.

Pero **el recorte del borrador aprobado (D-4) produce respuestas absurdas con borradores corrientes**:
- «Claro.», «Listo.», «Te entiendo.» o «¡Hola de nuevo!» salen como respuesta entera;
- no queda ninguna violación anotada;
- es el camino normal, el que más se va a ejercitar en la repetición de la prueba real.

Además hay cuatro IMPORTANTES, uno de ellos sobre la memoria, que pediste vigilar.

---

## Verificado (lo que ejecuté y su resultado real)

| Qué | Dónde | Resultado |
|---|---|---|
| Ajustador + A4 | copia `new` | **OK (104 pruebas, 1 164 aserciones)** [C] |
| `UltronDecidePromptsTest` | copia `new` | **OK (138, 2 358)** [C] |
| Instantánea de Legacy contra el **código anterior** | copia `old` | **OK (2, 198)** [C]: la instantánea es la salida de `7aa46911`/`3b917bac` |
| A4 contra el código anterior | copia `old` | 104 pruebas: 82 errores y 17 fallos [C]. Casi todos los errores son `Class "UltronReplyFitter" not found` |
| A4 contra el código anterior **con el ajustador disponible** | copia `old` | 80 pruebas: **38 rojas** (2 errores y 36 fallos). Están en rojo todas las de comportamiento; las 42 verdes son casos de la matriz que ya cabían y las dos de Legacy [C] |
| 14 mutaciones | copia `new` | 12 detectadas, 2 sobreviven (abajo) [C] |
| `pint --test` | los 6 ficheros | `passed` [C] |
| Guion PHP suelto contra `UltronReplyFitter` | copia `new` | 15 entradas de borde (abajo) [C] |
| Longitud del `lastResorts()` más largo, todas las intenciones | — | **102** (< `MIN_CAP` 160) [C] |
| Escenarios propios por el endpoint (`RevisorA4Test`, scratchpad) | — | R1–R8, abajo [C] |
| Marketing completo (`tests/Feature/Marketing` + `tests/Unit/Marketing`) | copia `new` | **6 133 pruebas, 10 rojas, todas por el entorno**: leen `../n8n/…`, que la primera copia no tenía. Con `n8n/` de `daedbbd8` añadido, `UltronPromptsN8nCommandTest` + `UltronPromptCompileCasesTest` dan **OK (655, 4 822)**. **Marketing en verde** [C] |

No hay PHPStan/Larastan configurado (no existe `phpstan.neon`). No ejecuté las guardas del H9 ni PostgreSQL.

### Mutaciones (copia `new`, ajustador + A4)

| # | Mutación | Resultado |
|---|---|---|
| M1 | Activar con Legacy (`studio = true`, tope 200 sin `sp`) | **roja**: las 2 de Legacy, el documento de textos y 2 más |
| M2 | Quitar el recorte del borrador | **roja** (4) |
| M3 | Quitar el control de `antesDeEnviar` | **roja** (1, la de reflexión) |
| M4 | Medir con las URL | **roja** (3) |
| M5 | No proteger la frase del precio | **roja** (3) |
| M6 | 13.quater sin ajuste | **roja** (1) |
| M7 | Horario con la pregunta al final (orden del plan) | **roja** (4) |
| M8 | Quitar `conPreciosEntregados` en el respaldo | **roja** (1) |
| M9 | Tolerancia `<` en vez de `<=` | **roja** (1, pura) |
| M10 | Cabecera «Planes de un mes» aunque la lista mezcle duraciones | **SOBREVIVE** |
| M11 | Quitar los precios de la rama de la política | **roja** (1) |
| M12 | D-6 en la rama de la política colgando del tope en vez de `studio` | **SOBREVIVE** |
| M13 | Guard de la rama de la política sobre el texto en vez del esqueleto | **roja** (1) |
| M14 | 13.ter con prioridad | **roja** (1) |

---

## Los siete puntos

### 1. Legacy idéntico → SÍ [C][L]

Recorrí cada punto tocado con `TURNO_LEGACY` (`studio=false`, `tope=null`):

- **`UltronCommitService.php:398`** `turnoDelStudio()`:
  - con `sp` ausente o `m≠studio` devuelve `['studio'=>false,'tope'=>null]` sin escribir log (`:5014-5018`);
  - `n` solo se lee con `studio` (`:5017`).
- **Recorte** (`:1580-1593`): `if ($turno['tope'] !== null)`.
- **`VIOLACION_LONGITUD`** (`:1620`): solo si `$longitud['action'] === 'discarded'`, y `$longitud` es null en Legacy.
- **`$precios`** (`:1625-1629`): exige `$turno['studio']`.
- **`respaldoPorIntencion`**:
  - `$alTope` devuelve el texto tal cual sin tope;
  - `ultimoRecurso(…, null)` y `respuestaConHechos(null)` son el código de antes;
  - las ramas de precios y de horario exigen `$precios !== null` y `$tope !== null`.
- **Sustitutos** `dePlanes`/`deVisita` (`:1669`, `:1686`): exigen tope.
- **13.ter** (`:1728-1735`): sin tope es `rtrim($replyFinal).' '.$cola`, igual que antes. El `elseif` no se alcanza sin tope.
- **13.quater** (`:1748-1749`): sin tope, idéntico.
- **`antesDeEnviar`** (`:5019-5021`): sin tope devuelve el texto y no escribe.
- **`conPreciosEntregados`** (`:5156`): con `$precios === null` devuelve la misma memoria.
- **`safeFallback`** (`:1962-1997`):
  - `$precios` exige `studio`;
  - `$esDePrecios` es false;
  - el ajuste exige tope;
  - el guard inspecciona `$curado`, como antes.
- **`contestarLaHoraDeLaVisita`** (`:2462`): exige tope.
- Todas las llamadas pasan `$turno` (`grep`). Los valores por defecto `TURNO_LEGACY` no los usa nadie: son defensivos.
- **`UltronPromptOffer`** (`:189-201`): `n` solo en el paso 8 (`studio`) y solo si ≠ `BASELINE`. La respuesta a n8n no cambia [C] (`test_n_viaja_en_el_token…`).

**La instantánea no es circular:**
- pasa con el código anterior [C];
- la mutación M1 la pone roja [C];
- cubre los dos modos no-studio (token sin `sp` y llave de prompts apagada): las 27 intenciones con el revisor caído y 6 borradores aprobados (largo, que no cabe, saludo que vende, precio en duda, enlaces).

**Límites de la instantánea** (no invalidan la conclusión, porque las ramas están guardadas y lo comprobé leyendo):
- compara texto, desenlace, `fallback_mode`, saliente, las **claves** de `metadata` (no sus valores), violaciones, herramientas, precios entregados y oferta;
- no recorre `contestarLaHoraDeLaVisita` ni `handleRecovery` en Legacy.

### 2. El orden en `execute()` → CORRECTO, salvo lo que hace el propio recorte (B-1, I-2, I-3) [C][L]

- **Orden.** El recorte (`:1580`) va después de los enlaces (13.bis), del checkout en línea, de las cuatro guardas de cortesía y precio y de `sinServiciosInventados`, y antes de `violations()` (`:1595`).
  - `withoutRepeatedGreeting` (`:1606`) solo quita.
  - 13.ter (`:1725`) añade solo si cabe.
  - 13.quater (`:1745`) ajusta con prioridad.
  - `antesDeEnviar` es la sentencia inmediatamente anterior a cada `dispatchWhatsapp` (`:1765`, `:2259`, `:2470`). La prueba estructural lo fija y la mutación M3 la pone roja.
- **Nada crece después del último control.** Después de `antesDeEnviar` no se toca el texto.
- **Envíos.** No hay otro envío en el commit. El cuarto (`:3038`, el enlace de pago) queda fuera por decisión.
- **`textoSustituido` / `llevaLosEnlaces`.** El recorte nunca suelta la frase de la URL. Si 13.quater o el punto de control quitan los enlaces, `llevaLosEnlaces()` lo detecta y la herramienta se anota `skipped/reply_replaced` [L].
- **Recuperación por repetición (`:1018`).** Su último recurso sin tope pasa después por el recorte y el control [L].
- **`recordSentTurn`.** Recibe el texto ya controlado en los tres caminos. **Pero** en la rama de la política lo recibe con el plan del modelo (→ I-1).
- **Matiz menor (m-6).** El recorte va antes de `withoutRepeatedGreeting` y nunca suelta la primera frase. Si esa frase es el saludo repetido, se sueltan frases con contenido para hacer sitio a un saludo que se quita a continuación. En R8 ni siquiera se quitó: salió «¡Hola de nuevo!» solo (→ B-1).

### 3. `UltronReplyFitter` → bien construida y pura, con tres defectos de entrada [C]

**Bien:**
- `measure()`, `fits()` (tolerancia 0, M9 roja) y `cap()`.
- `curated()`: obligatorias la primera y la pregunta.
- `pieces()`: llenado en orden, y devuelve null si no caben la apertura y la pregunta.
- `schedule()`:
  - el día preguntado es obligatorio («festivos», «domingo», «sábados y domingos»; sin día → laborables);
  - «fin de semana» no se reconoce y cae en laborables, pero a 160 salen igualmente sábado y domingo.
- `pricingLayout()`/`composePricing()`: el orden de caída es el documentado, y «También hay» solo va con todos los planes.
- `join()` conserva el separador anterior: el `\n\n` antes de los enlaces sobrevive.
- Las abreviaturas funcionan («Av.», «Cl.»); «No.» y «etc.» son frontera; «a. m.» no parte.

**Defectos:**
- **`draft()` (`:253-274`)** puede dejar solo la primera frase cuando es una muletilla → **B-1**.
- **`FRONTERA` (`:48`)** parte tras «1.», «2.»… de una lista numerada, y el recorte deja un «3.» colgando → **I-3**.
- **`draft()` protege solo el precio y la URL** → suelta la confirmación de una herramienta ya ejecutada → **I-2**.
- **`append()` con prioridad** devuelve la cola sola aunque la cola supere el tope (guion: 171 > 160). Hoy la cola del precio mide unos 50; el punto de control lo cubriría. Menor, no se alcanza.

### 4. El respaldo de precios → la selección y el texto son correctos; la memoria no (I-1); el formato mixto no está aprobado (I-4) [C]

- **Selección** (`:5059-5072`):
  - el plan nombrado va primero solo si es vendible;
  - después, los de 28 a 31 días en el orden de `activePlans()`, como mucho 3;
  - sin planes de un mes, los 3 primeros.
- **Planes nombrados.** Con «plan día» o «Trimestre» la cabecera pasa a «Nuestros planes» con duraciones (R3). **No dice «Planes de un mes» con un plan que no lo es** [C]. Pero ninguna prueba lo protege (M10 sobrevive).
- **«También hay».** Solo sale si quedan vendibles no nombrados, y solo en la variante con todos los planes. Nunca sale tras soltar un plan elegido [L][C].
- **Esqueleto.** El texto y el esqueleto se componen con la misma cabecera, «También», pregunta y `layout`, así que siempre son paralelos [L]. La mutación del guard sobre el texto final es roja en los dos caminos (la prueba del «gratis» y M13).
- **Un defecto del texto (I-4):** «PLAN DÍA (**1 días**)», que sale de `UltronDraftPlaceholders::duration()` (`:160`). La prueba `test_sin_planes_de_un_mes…` lo fija como esperado.
- **Duraciones (m-5).** Un plan de 2 a 27 días se describe «por semana» (`:5100`). Hoy solo hay uno de 7; uno de 15 días sería falso.
- **Memoria:**
  - en el respaldo (`safeFallback`) es correcta: el plan va null y `conPreciosEntregados` anota solo lo que está literal en el texto [C];
  - en la rama de la política **anota como entregado el precio del plan del modelo aunque no salga** → **I-1** [C].
- **Fuera de mi alcance (lo ve `security`).** Nombrar «INGRESO EMPLEADOS» puede ponerlo primero con $1. Es la exposición previa del plan §2.2 (b) y la decide el propietario.

### 5. Las pruebas → sólidas, con huecos concretos [C]

- **Son reales.** Pasan por `/api/internal/marketing/ai/decide` → `/commit` con las clases reales, el catálogo y la ficha de producción. No hay dobles del commit ni del ajustador; solo `Http::fake()` y un espía de `Log`. `antesDeEnviar` se prueba por reflexión sobre la instancia real.
- **El rojo es real, pero el número del informe lo sobrestima.** «81 errores y 14 fallos» son sobre todo «clase no encontrada». Con la clase disponible, el código anterior deja **38 de 80 rojas**, y todas las de comportamiento lo están.
- **Mutaciones que sobreviven:**
  - M10 (la cabecera mixta, que es justo la falsedad que preguntabas);
  - M12 (la rama de la política con la versión y sin tope).
- **Lo que ninguna prueba cubre** (por eso se escapan B-1, I-2 e I-3):
  - un borrador cuya única frase recortable sea la del contenido;
  - un borrador con herramienta ejecutada;
  - una lista numerada.
- **El documento de textos lo fija bien una prueba:**
  - igualdad exacta con lo que genera el endpoint, con el reloj fijo;
  - pero no incluye la cabecera «Nuestros planes» (T2) ni el formato con duraciones → I-4;
  - y por diseño no cubre los recortes del borrador del modelo.
- **La aserción cambiada (`UltronDecidePromptsTest.php:218`) no afloja nada.** Sigue siendo `assertSame` exacto; Legacy sigue sin la clave y `studio` exige `n = 200`.

### 6. Calidad [L]

- **Tamaño.** +412 líneas en un servicio que pasa de 4 780 a 5 192. Lo puro está bien sacado a `UltronReplyFitter`. Podrían salir:
  - `respaldoDePrecios()` (`:5059-5125`), casi puro: catálogo + modelos → texto/esqueleto. Sería testeable sin endpoint;
  - `conPreciosEntregados()`, cuyo sitio natural es `ConversationMemoryService`. El lead ya decidió dejarlo en el commit.

  No lo considero un defecto para esta tanda.
- **Comentarios que no dicen lo que hace el código:**
  - `antesDeEnviar` (`:5005`): «garantiza que lo que sale cabe». Si `ultimoRecurso()` devuelve null o una variante que no cabe, `?? $texto` (`:5030`) envía el texto pasado. Hoy no se alcanza (máximo 102 < 160) → m-1;
  - `conPreciosEntregados` (`:5145-5147`): «el respaldo de precios sale sin plan». Falso en la rama de la política → I-1;
  - la cabecera de `UltronReplyFitter` («NUNCA corta una frase por la mitad») → I-3.
- **Docblock duplicado** antes de `respaldoSemantico` (`:4865` y `:4873`): el primero, el descriptivo, queda huérfano → m-4.
- **Nombre.** En `contestarLaHoraDeLaVisita` el turno del Studio se llama `$delStudio` porque `$turno` ya existía con otro significado. Es legible, pero incoherente con el resto.
- **Código muerto.** Los valores por defecto `TURNO_LEGACY` de métodos privados que todos los llamadores ya pasan. Inofensivo.

### 7. Lo que queda fuera: ¿puede hacer fallar la repetición?

- **Lo que sí la hace fallar es de este cambio: B-1.**
  - La causa C3 del diagnóstico dice que el redactor se pasa del tope en 4 de cada 5 borradores.
  - El prompt del redactor le pide abrir con «Listo», «Claro», «Te entiendo» (`composer.txt:42`).
  - Un borrador aprobado de 205 a 245 caracteres con una frase central larga sale como «Claro.» o «Listo.».
  - Es el camino normal, no un respaldo. Con el compilador 2 de hoy, el escenario es probable.
- **Tono:**
  - excluido por decisión, y no lo debía cubrir este cambio;
  - el criterio de aceptación de `02-mensajes…txt` §6 es «tono **y** longitud»;
  - con la conversación canaria en CLOSING/READY (causa C1) seguirá habiendo respaldos;
  - los respaldos tutean salvo el de precios y el horario recortado, que son neutros;
  - un turno de respaldo fallará en tono aunque cumpla la longitud. Que el propietario lo sepa antes.
- **H4-4:** la respuesta de respaldo cabe y contesta (horario a 186). Correcto.
- **`single_plan`:**
  - si el revisor tumba el plan único, sale el respaldo de precios, como se busca;
  - si lo aprueba, la persona recibe un solo plan. Es el camino del modelo y está fuera, coherente.
- **Tope 320 sin medida:** correcto por M1-4.
- **El enlace de pago:** apagado en producción. El caso «checkout acuñado y descartado, anotado `inlined`» (informe §8.3) ya existía; el descarte por longitud lo hace algo más probable → pendiente m-9.
- **El compilador 3** no está en este commit (hay trabajo sin commit de otro agente en el árbol). La tasa de recortes y descartes en la canaria dependerá de él [S].

---

## Bloqueantes

1. **`app/Services/Marketing/Ultron/UltronReplyFitter.php:253-274` (`draft()`), usado en `UltronCommitService.php:1580-1593`. El recorte puede dejar solo la primera frase, y si es una muletilla, la respuesta queda vacía de contenido.**

   **Cómo falla.** `draft()` suelta frases desde el final hasta que cabe y nunca la primera, pero no exige que quede algo más que la primera. Si la frase central (la que contesta) mide más que el tope menos la apertura, se suelta y sale la apertura sola. No se anota ninguna violación, porque la política no lo ve, y `metadata.length.action` dice `trimmed`.

   **Escenarios comprobados [C]**, por el endpoint, con el revisor aprobando y la versión del Studio aplicada:

   | Intención | Entrante | Borrador | A 160 sale | A 200 sale |
   |---|---|---|---|---|
   | `beginner_fear` | «Nunca he entrenado y me da miedo» | «Te entiendo. Muchas personas… pendiente de ti. ¿Qué es lo que más te preocupa?» (241) | **«Te entiendo.»** (12) | lo mismo |
   | `time_objection` | — | «Te entiendo perfectamente. Con tres sesiones…» (234) | **«Te entiendo perfectamente.»** | lo mismo |
   | `schedule_question` | «¿Cuál es el horario de atención entre semana?» | «Claro. El horario … de 8:00 a. m. a 2:00 p. m. para que entrenes cuando mejor te quede. ¿Te sirve?» (205) | **«Claro.»** (6), sin horario | correcto (194) |
   | `goal_fat_loss` | — | «Listo. Para bajar de peso… según tu nivel y tu disponibilidad. ¿Ya vienes entrenando?» (235) | **«Listo.»** | lo mismo |
   | `beginner_fear` (R8), tras un saludo previo | — | «¡Hola de nuevo! Muchas personas…» (244) | **«¡Hola de nuevo!»** (15), como respuesta a «me da miedo» | — |

   **Corrección sugerida.** Que `draft()` devuelva null (descarte → `policy_length_exceeded` → el respaldo ajustado de la intención, que sí contesta) cuando el recorte deje solo la primera frase sin precio ni URL. Una regla más robusta: descartar si se suelta más de la mitad del texto medido. Hay que añadir estos cinco borradores como pruebas por el endpoint (rojas hoy). La regla exacta la decide el lead; el criterio mínimo es que **no salga una respuesta que no contesta nada**.

   → asignado a **backend**.

## Importantes (antes de abrir más alcance que la canaria)

1. **I-1 · `UltronCommitService.php:1631-1636`, `:1813-1818` y `:1839`. La memoria y `price_source` mienten cuando el respaldo de precios sustituye un borrador en la rama de la política.**

   **Cómo falla.** `$plan` conserva el plan del modelo. Solo se anula en recepción, memoria y visita. `recordSentTurn()` ve una cifra en el texto y anota `deliverPrice($plan->id)` y `discussPlan($plan->id)` aunque ese plan no esté en el texto.

   **Escenario [C] (R1):**
   - pregunta de precios, a 200;
   - borrador aprobado sobre el **Trimestre** (`recommended_plan_id` 5), descartado por longitud;
   - sale «Planes de un mes: Plan Valera…; Plan Mensual…; Élite…»;
   - la memoria queda con `priceDeliveredFor(5) = true` y la respuesta a n8n con `price_source = {plan_id:5, price:210000}`.

   El turno siguiente cree que el precio del Trimestre ya se dio. Es la misma «repetición» que el revisor castiga en la causa C1 del diagnóstico. Hoy no lo cubre ninguna prueba: la del descarte usa el plan 8, que está en la lista. El docblock de `conPreciosEntregados` (`:5145-5147`) afirma lo contrario.

   **Corrección.** `$plan = null` cuando `$alternativa === $precios['texto']`, y una prueba con un plan del modelo fuera de la lista.

   → **backend**.

2. **I-2 · `UltronReplyFitter.php:261-266` y `:422-425`. El recorte suelta la confirmación de una herramienta ya ejecutada.**

   **Escenario [C] (R7):**
   - `courtesy_request` ejecutada: la cita se crea y la memoria queda `requested`;
   - borrador «Listo, ya te dejo anotado. En la primera visita te muestran… Queda registrada tu solicitud de visita para el sábado a las 10:00 a. m. y el equipo la revisará.» (242);
   - a 200 y a 160 sale sin la última frase. La persona no lee qué día y qué hora quedaron registrados.

   El arquitecto lo advirtió (plan §3.2, «Descartado: … la frase que acompaña a una herramienta ya ejecutada»), y D-4 solo protegió el precio y la URL.

   **Corrección.** Proteger la frase con fecha u hora cuando el turno ejecutó una herramienta de visita (o las frases que tocó `conLaFechaDeLaCita`), con su prueba. **Decisión del lead:** qué más se protege.

   → **backend**.

3. **I-3 · `UltronReplyFitter.php:48` (`FRONTERA`). Una lista numerada se parte tras el número y el recorte deja «3.» colgando.**

   **Escenario [C] (R2):**
   - a 160, borrador «Te cuento lo que incluye el Élite:\n1. Acceso total…\n2. Clases grupales…\n3. Seguimiento con IRON IA en la app. ¿Te animas…?» (205);
   - sale «…\n2. Clases grupales funcionales todos los días.\n**3.**».

   El redactor puede enumerar: «Sin viñetas salvo que enumerar sea imprescindible, y nunca mas de cuatro» (`composer.txt:39`).

   **Corrección.** Tratar un trozo que acaba en `(^|\n)\d{1,2}\.` como no-frontera (igual que las abreviaturas), con su prueba pura.

   → **backend**.

4. **I-4 · `UltronCommitService.php:5078-5087`, `UltronDraftPlaceholders.php:160` y `a4-textos-ajustados.md`. El formato «Nuestros planes» llega al cliente sin aprobar y con una errata.**

   **Escenario [C] (R3):** «¿Cuánto vale el plan día? ¿Qué planes tienen?» a 200 →
   «Claro. Nuestros planes: PLAN DÍA (**1 días**), $10.000 COP; Plan Valera (un mes), $65.000 COP; Plan Mensual (un mes), $80.000 COP. ¿Sobre cuál amplío la información?».

   Pasa con cualquier plan nombrado que no sea de un mes (Día, Semana, Trimestre…) y con el catálogo de producción. El documento que aprueba el propietario solo enseña «Élite», así que T2 y el formato con duraciones no los ha visto nadie. La prueba `test_sin_planes_de_un_mes…` fija «(1 días)» como correcto, y M10 sobrevive.

   **Corrección:**
   - añadir al generador del documento un plan nombrado que no sea de un mes;
   - una prueba de la cabecera mixta (que mate M10);
   - corregir el singular en el respaldo (no en `duration()`, que comparte el camino Legacy del modelo, salvo que se decida).

   → **backend**, y aprobación del propietario.

## Menores (a la lista de pendientes)

1. **m-1 · `UltronCommitService.php:5005` y `:5030`.** «Garantiza que lo que sale cabe» no es literal:
   - si `ultimoRecurso()` devuelve null, o la variante más corta segura que no cabe (`:4738-4744`), sale el texto pasado;
   - hoy no se alcanza (el último recurso más largo mide 102 [C]);
   - ajustar el comentario o hacer explícito el caso.
2. **m-2 · `UltronCommitService.php:2462-2468`.** En la hora de la visita, si `curated()` no cabe, se marca `last_resort` pero se deja el texto pasado, y el cambio lo hace el punto de control con un `error` de «defecto nuestro». Es un camino previsto registrado como defecto (improbable: textos cortos).
3. **m-3 · `metadata.length` impreciso:**
   - en la rama de la política, `action`, `from` y `dropped` describen el borrador, no el sustituto (R5: `fit`, `from` 137, sale una bienvenida de 110);
   - `dropped` de 13.quater (`:1753`) se calcula por diferencia de frases y falla si el cuerpo no acaba en puntuación;
   - en `general_info`, `from` mide las piezas ya ajustadas (154) y no la ficha (444).

   Solo afecta a la traza del CRM.
4. **m-4 · `UltronCommitService.php:4865` y `:4873`.** Dos docblocks seguidos: el descriptivo queda huérfano. Fusionarlos.
5. **m-5 · `UltronCommitService.php:5100`.** De 2 a 27 días → «por semana»: un plan de 14, 15 o 21 días se describiría mal. Hoy no existe ninguno.
6. **m-6 · `UltronCommitService.php:1580` frente a `:1605`.** El recorte va antes de quitar el saludo repetido y protege la primera frase (el saludo). Se pierde contenido para hacer sitio a un saludo que se va a quitar. Se resuelve en parte con B-1.
7. **m-7 · Huecos de prueba:** M12 (precios en la rama de la política con la versión y sin tope) y el rojo del informe (ver el punto 5).
8. **m-8 · `append()` con prioridad** devuelve la cola sola aunque no quepa (`UltronReplyFitter.php:293-296`). No se alcanza.
9. **m-9 · Checkout acuñado y descartado, anotado `inlined`** (informe §8.3). Ya existía; el descarte por longitud lo hace más probable. Con los enlaces de pago apagados no se alcanza.

---

## Notas

**Revisado y correcto:**
- `sp.n` firmado: solo `studio`, solo ≠ 320, nada hacia n8n;
- el rango de `cap()` coincide con el compilador (160–480);
- el aviso `length_cap_invalid` no lleva el valor;
- el único punto de control, y el inventario de envíos;
- el guard sobre el esqueleto en los dos caminos;
- `conPreciosEntregados` en el respaldo (anota solo lo literal);
- «¿Sobre cuál amplío…?» no se registra como oferta (prueba con el detector real);
- el horario a 186 y a 150, y el día preguntado obligatorio;
- `general_info` por piezas;
- `pint`;
- la instantánea de Legacy tomada con el código anterior;
- `SalesConversationReplyService.php` sin tocar.

**No verificado:**
- PostgreSQL 14;
- las guardas del H9 (la puerta de diff roja esperada no la ejecuté);
- el CRM;
- n8n y producción;
- la tasa real de recortes con el redactor (depende del compilador 3) [S];
- la seguridad (precios de la BD en texto de máquina; los nombres de plan fuera del guard): la revisa `security`.

**Reproducción (efímera).** Los escenarios R1–R8 están en el scratchpad de esta sesión (`RevisorA4Test.php`, `borde.php`, `mutar.sh`), fuera del repositorio.

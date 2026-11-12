# Plan del arquitecto · Apartado 4 · Bloqueantes H4-1, H4-2 y H4-3

12-11-2026 · rama `feat/ultron-studio-h9` = `origin/main` = `3b917bac` · solo análisis: no se ha tocado código, pruebas ni n8n.
Revisión 2 (con los datos de producción que leyó el lead a las 23:15 UTC: catálogo, horario y alcance de `SalesConversationReplyService`).
Marcas: **[C]** comprobado ejecutando · **[L]** leído en el código o los documentos · **[P]** dato de producción leído por el lead · **[S]** supuesto (hay que verificarlo).

---

## 1. Veredicto (cinco líneas)

1. **Se toca, solo del lado de ULTRON:**
   - `UltronCommitService.php`: respaldo de precios, ajuste al tope de todo texto de Laravel y medida determinista del borrador;
   - una clase pura nueva `app/Services/Marketing/Ultron/UltronReplyFitter.php`;
   - `Prompts/UltronPromptOffer.php`: el tope viaja firmado en el token como `sp.n`;
   - `Prompts/UltronPromptCompiler.php`: compilador **3**, con margen para el redactor.
2. **No se toca:** `SalesConversationReplyService.php` (lo usa el agente Legacy con clientes reales), n8n, los prompts base, la huella Legacy `3f6a5674…`, `config/`, rutas, migraciones, `OutboundContentGuard`, la antirrepetición, los hechos de pago y membresía, OTP, Wompi, Factus ni la API móvil.
3. **Legacy queda igual byte a byte:** todo cuelga de `sp.n`, que solo existe si `decide` ofreció prompts del Studio con `persona.maxChars` ≠ 320.
4. **Es un cambio de contrato** (el commit se ramifica por un valor gobernado; hoy se dice lo contrario en `UltronPromptStamp.php:37` y en M1-18). Hacen falta una enmienda, la cesión de la puerta de diff del H9 y la aprobación de los textos nuevos.
5. **D1 necesita antes una decisión del propietario sobre el catálogo:** hay 13 vendibles **[P]**, ninguno marcado como recomendado, y entre ellos `INGRESO EMPLEADOS` a $1 y un plan heredado. El código propone una regla de selección, pero «qué planes son del público» no se puede deducir de los datos.

---

## 2. D1 · Planes y precios

### 2.1 Qué pasa hoy

- Con el revisor tumbando los dos borradores, `safeFallback()` (`UltronCommitService.php:1859`) llama a `respaldoSemantico()` (`:4691`). Este devuelve `null` para `PRICING_QUESTION` porque está en `RESPALDO_PROPIO` (`:4673-4684`), así que sale `replyFor(PRICING_QUESTION)` (`SalesConversationReplyService.php:53`): pide el objetivo y no da precio. **[L]**
- **Trampa:** `OutboundContentGuard::inspect()` (`:577`) tumba cualquier texto de máquina que lleve un precio (`containsPrice`, `:651`), y el texto curado pasa por él (`UltronCommitService.php:1899-1907`). `PRICE_PATTERN` (`SalesAgentDecisionSchema.php:142`) caza incluso `\bcop\b`. **[L]** Un respaldo que diga «$65.000 COP» no pasa el guard tal cual.
- La salida existe para el modelo: primero se valida el texto con `{{PLAN_PRICE}}` y después se sustituye por `Plan::price` (`UltronDraftPlaceholders.php`, cabecera). **[L]**
- **El catálogo:**
  - `activePlans()` (`MarketingKnowledgeBaseService.php:125`) = `Plan::sellable()` (`Plan.php:289`), con `is_recommended` primero y luego `sort_order`. **[L]**
  - En producción **ningún plan tiene `is_recommended`**, así que `CommercialTurnPolicy::planInsignia()` (`:736`) devuelve `null` y el orden es solo `sort_order`. **[P]**
  - `defaultMonthlyPlan()` (`:178`) da el **Plan Valera**, no el Mensual. **[P]**
  - Los 13 **[P]**: #25 PLAN DÍA $10.000 (1) · #2 Plan Semana $45.000 (7) · #3 Plan Valera $65.000 (30) · #4 Plan Mensual $80.000 (30) · #5 Trimestre $210.000 (90) · #6 Semestre $390.000 (180) · #7 Anualidad $624.000 (365) · #8 Élite $180.000 (30) · #9 Pro $499.000 (90) · #10 Prime $950.000 (180) · #11 Evolution $1.800.000 (365) · #14 PROMO X4 $75.000 (30, «heredado») · #16 INGRESO EMPLEADOS $1 (360).

### 2.2 Regla de selección (propuesta) y lo que decide el propietario

**Propuesta de mecanismo** (no depende de `is_recommended`; ningún id ni nombre escrito en el código):
1. Si la persona nombró un plan, ese va primero (`hard_required_plan`, que ya calcula `CommercialTurnPolicy`).
2. Después, los vendibles **de un mes** (28–31 días, el mismo criterio que `defaultMonthlyPlan`) en el orden del catálogo (`activePlans()`). **Como mucho 3**: es el mismo límite que aplica el revisor («nombra mas de tres planes», `critic.txt:40`).
3. Si no hay ninguno de un mes, los 3 primeros de `activePlans()` con su duración (`UltronDraftPlaceholders::duration`).
4. El resto no se nombra ni se cuenta. Se dice qué **duraciones** quedan, derivadas del catálogo: 1 día → «por día», 7 → «por semana», ≥ 88 → «de varios meses». Así no se enseña ni se cuenta ningún plan concreto que no sea del público.

**Con el catálogo de hoy, la regla nombra Plan Valera, Plan Mensual y Élite** (los tres primeros de 30 días por `sort_order`). PROMO X4 queda cuarto y no sale, pero solo por el orden.

**Lo que tiene que decidir el propietario** (no lo he inventado; tú se lo preguntas):
- **(a)** ¿Qué planes puede nombrar ULTRON? Opciones:
  - la regla de arriba (los de un mes por `sort_order`);
  - una lista explícita que él dé;
  - los más cobrados, que hoy son Mensual, Trimestre y Élite **[P]**. Esta no la recomiendo: mete una consulta de pagos en el commit y cambia sola cada día.
- **(b)** ¿Deben seguir vendibles #16 INGRESO EMPLEADOS ($1) y #14 PROMO X4 (heredado)?
  - Es un dato, no código.
  - **Ya hoy, sin este plan,** `decide` manda los 13 al modelo como catálogo (`UltronDecideService.php:660`) y la herramienta de voz `consultar_planes` los lista por `sort_order` (`VoiceTools.php:313`, `limit(20)`). Los dos los podrían decir a un cliente. Es una exposición que ya existe y es independiente de D1. **[L]**
  - Quitarles `sellable` puede afectar a cómo se vende hoy el plan de empleados en el CRM **[S]**.
- **(c)** ¿Valera y Mensual, los dos de un mes y con los mismos beneficios **[P]**, se enseñan juntos? Si no, el remedio es de datos (orden o vendible), no de código.
- **(d)** ¿Marcar un plan insignia (`is_recommended`)? D1 no lo necesita, pero cambia el comportamiento del modelo para todos: lo pone primero en el catálogo y en la política. No es para esta tanda.

### 2.3 Diseño

- **Dónde:** `respaldoDePrecios()` en `UltronCommitService`, usado en dos sitios:
  - en `respaldoSemantico()`, **antes** del corte por `RESPALDO_PROPIO`, cuando `intent === PRICING_QUESTION` y hay `sp.n`;
  - en `respaldoPorIntencion()` (`:4616`), cuando la política o la medida de longitud descartan el borrador (§3.2).
- **El guard sigue delante:** el builder devuelve dos cadenas, el esqueleto (cada nombre como `{{PLAN_NAME}}` y cada precio como `{{PLAN_PRICE}}`) y el texto final.
  - `safeFallback` pasa por `inspect()` el **esqueleto**.
  - Los nombres van con marcador para que un nombre de catálogo no salte `DISCOUNT_PATTERN` (`SalesAgentDecisionSchema.php:163`).
  - Las cifras salen de `formatCop((float) $plan->price)`, leídas en el commit.
  - `formatCop` es público en `SalesConversationReplyService`: se usa, no se cambia.
- **Antirrepetición:** como todo respaldo semántico, no aplica (`:1913`, condición `$semantico === null`).
- **Alcance:** solo con `sp.n`, para cumplir (c). Extenderlo a Legacy es arreglar el mismo defecto para todos, pero cambia bytes de Legacy: **decisión del propietario**.

**Segmentos y prioridad** (§3.1):
- obligatorio: encabezado + primer plan con su precio;
- se suelta en este orden: «También hay…» → plan 3 → pregunta final → plan 2.

**Texto exacto propuesto** (tuteo, como los curados de hoy; longitudes **[C]**, catálogo de hoy):
- Completo, cabe en 200 (193): `Claro. Planes de un mes: Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP; Élite, $180.000 COP. También hay planes por día, por semana y de varios meses. ¿Cuál se ajusta más a lo que buscas?`
- Tope 160, suelta «También…» (135): `Claro. Planes de un mes: Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP; Élite, $180.000 COP. ¿Cuál se ajusta más a lo que buscas?`
- **[S]** Que «¿Cuál se ajusta más…?» no lo lea como oferta el detector de ofertas (aviso en `lastResorts()`, `SalesConversationReplyService.php:439`). Hay que probarlo con el detector real.

### 2.4 Descartado

- **`pricingReply(?Plan)`** (`SalesConversationReplyService.php:201`): un solo plan (con `defaultMonthlyPlan()` sería Valera), no pasaría el guard y es del agente Legacy.
- **Arreglar el camino del modelo** (`single_plan` ante «¿qué planes tienen?», que el revisor 2 tumbó por «solo menciona un plan»): lo decide el Strategist con su prompt base. Cambiarlo toca la huella Legacy y n8n. **Fuera.**

---

## 3. D2 · El límite de longitud de la versión publicada

### 3.0 De dónde lee el commit el `persona.maxChars`

**Hoy no puede:**
- el sello `$studio` (`forConversation()`, `:325`) no lleva `governed`;
- `partFor()` sí lo lleva, pero está documentado como «la llama solo `UltronPromptOffer`, en decide (M1-6)» (`UltronPublishedConfig.php:207-213`);
- `x.sp` lleva `m r h v c e s` (`UltronPromptOffer.php:236-247`). **[L]**

**Guardas estáticas: ninguna lo impide.** `UltronEngineIsolationTest` (`:330-337`, `:625-639`) fija **qué ficheros** nombran `UltronPublishedConfig` (el commit, la oferta y el propio lector), no qué métodos llaman. El commit ya está en la lista. **[L]** Lo que sí fija la forma de `sp` es `UltronDecidePromptsTest.php:218` (`assertSame` exacto), además de un token de menos de 1 500 bytes (`:216`). **[L]**

**Recomendado: `sp.n` en el token.** En `UltronPromptOffer::studio()` (`:180-189`) se añade `n` = `governed['persona.maxChars']` **solo si ≠ 320**. Legacy no gana claves.
- Es lo que se le pidió al modelo en ese turno, firmado.
- No hay otra lectura de la tabla ni carrera con una reversión entre `decide` y `commit`.
- M1-6 queda como está.
- `UltronPromptStamp::offered()` lee `sp` por clave, así que una clave más no lo rompe.
- **Por qué se excluye el 320:** respeta M1-4 («la línea base significa como hoy»). Con 320 el compilador no parchea (`UltronPromptCompiler.php:507-509`), y medir sin habérselo pedido al modelo descartaría unas 12 de cada 30 respuestas (12 de las 30 del historial pasan de 320, `02-mensajes…txt:74-113` **[L]**).
- **Un token emitido antes del despliegue no lleva `n`:** ese turno no se mide (falla hacia hoy).
- **Cambia:** `UltronDecidePromptsTest.php:218`.

**Alternativa: `partFor()` en el commit.**
- Ventajas: sin cambio de token, y lee lo vigente al hacer commit.
- Inconvenientes: otra consulta en la transacción del commit, una enmienda a M1-6, y puede dar un valor distinto del que vio el modelo (si se revirtió entre medias, se mediría con Legacy un texto pedido con tope, o al revés).
- **Descartada.**

### 3.1 (a) Ningún texto de Laravel supera el tope

**`UltronReplyFitter`** (pura: sin BD, sin config, sin reloj), del lado de ULTRON. Trabaja **sobre el texto que ya devuelven** `replyFor()`, `welcomeReplies()`, `lastResorts()` y los builders del commit, **sin cambiarlos**.
- **Partir:** frases enteras por `/(?<=[.!?])\s+(?=[¿¡A-ZÁÉÍÓÚÑ])/u`. Corta tras «p. m.» cuando sigue una mayúscula («…10:00 p. m. Sabados»), que es una frontera real, y **no** dentro de «5:00 a. m. a 10:00», porque sigue minúscula. **[C]**, sobre la línea real del horario.
- **Regla general:**
  - obligatorias: la primera frase y la pregunta final, si la hay;
  - se sueltan las del medio en orden, de la segunda en adelante;
  - después, la pregunta final;
  - nunca se corta una frase.
- **Orden de caída**, en `safeFallback` antes del guard (`:1899`), en `respaldoPorIntencion` y en un único punto antes de cada `dispatchWhatsapp` del commit (`:1682`, `:2130`, `:2329`):
  1. texto entero;
  2. ajustado con la regla;
  3. las variantes de `lastResorts($intent)` que quepan (ya aprobadas; todas miden menos de 150 **[C]**);
  4. la más corta segura. **Nunca un turno mudo.**

**Resultado de la regla con los textos de hoy [C]** (solo los de más de 160):

| Intención | Hoy | Tope 200 | Tope 160 |
|---|---|---|---|
| `payment_link_request` / `high_intent_close` | 236 | 185 (suelta «También puedes pagar en el gimnasio…») | 139 (además, sin la pregunta) |
| `medical_risk_escalation` | 266 | 151 «Gracias por contarlo. Te dejo marcado que necesitas revisión del equipo, y mientras tanto…» (la promesa casa con la marca de `:1955-1960`) | 151 |
| `invoice_request` | 215 | 125 «Claro. Te dejo marcada la solicitud de facturación. Si quieres…» | 125 |
| `fraud_or_payment_claim` | 195 | entero | 132 (suelta la frase de la membresía) |
| `goal_recomposition` | 183 | entero | 134 |
| `insecurity_body` | 162 | entero | 112 |
| `general_info` | 249 | 87 «Con gusto te cuento. ¿Qué te cuento más a fondo…?» | 87 |
| `schedule_question` (rama sin horario) | 233 | 93 (honesto, sin la pista de las clases) | 93 |

- Con esto **ya no hace falta ninguna variante compacta nueva**: queda fuera la T5 de la revisión 1.
- Los resultados se enseñan al propietario como **recortes** de textos ya aprobados, no como textos nuevos.
- `general_info` pierde contenido. Pero en ULTRON ese texto solo sale si `respuestaConHechos()` (`:4346`) devuelve `null`; con la ficha de producción sale la ficha, que se ajusta por piezas.

**Horario: prioridad por día, no la regla general** (la general soltaría «Lunes a viernes» lo primero). Es una sola línea de la base de conocimiento, de 253 caracteres **[P]**. Partida, da **[C]**:
`[Claro.] [Horario general de atencion: Lunes a viernes de 5:00 a. m. a 10:00 p. m.] [Sabados …] [Domingos …] [Festivos …] [Este es el horario de apertura del gimnasio, no el horario de las clases.] [¿En qué franja te queda mejor entrenar?]`
- **Obligatorio:** el segmento del día que se preguntó. Se busca en el entrante normalizado: «entre semana|lunes|martes|…|viernes» → laborables; «sabado», «domingo», «festivo». Si no nombra ninguno, el primero.
- **Se suelta en este orden:** aclaración → días no preguntados, del último al primero → pregunta.
- **Tope 200 (190 [C]):** `Claro. Horario general de atencion: Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados de 8:00 a. m. a 2:00 p. m. Domingos de 8:00 a. m. a 2:00 p. m. ¿En qué franja te queda mejor entrenar?`
- **Tope 160 (119 [C]):** `Claro. Horario general de atencion: Lunes a viernes de 5:00 a. m. a 10:00 p. m. ¿En qué franja te queda mejor entrenar?`
- **Si alguien reescribe la ficha** y no aparece ningún día reconocible, se aplica la regla general. Si aun así no cabe, sale la primera frase de la ficha si cabe y, si no, el último recurso. Prueba con una ficha sin días.

**Textos que arma el commit:**
- `respuestaConHechos()`: cada pieza de la base de conocimiento es un segmento; obligatorias la primera y la pregunta.
- `saludoDeBienvenida()`: de 80 a 110 **[C]**.
- `respaldoDeMemoria()`, `courtesyReply()`, `loQueSiTenemos()` y `planReply()`: la regla general.

### 3.2 (b) Que el redactor deje de pasarse

**Recomendado: las dos medidas, en el mismo despliegue.**

**(b1) Medida determinista en el commit:**
- Tras sustituir los marcadores (`:593`) y antes de la política (`:1546`), si hay `sp.n` y la prosa final supera `n`, se añade la violación nueva `policy_length_exceeded`.
- Entra por la rama que ya existe (`:1572`): `respaldoPorIntencion()`, ampliado con `PRICING_QUESTION` → `respaldoDePrecios()`, `SCHEDULE_QUESTION` → horario ajustado y el resto → curado ajustado.
- Queda `policy_discarded_draft` como evidencia (`:1588`).
- **Tolerancia: 0.**
- **Qué se mide:** `mb_strlen` sin el bloque de URL de `{{APP_LINKS}}` (unos 192 caracteres que pone Laravel, `:4790-4799` **[L]**). Si se contara, con topes de 160–240 no se podría mandar la app. **Decisión del propietario.**
- **[S]** Hay que confirmar en qué punto se expanden los enlaces respecto a `:1546` (`:1383`, `:3853`).
- **Descartado: recortar el borrador del modelo por frases.** Puede soltar el precio, la pregunta o la frase que acompaña a una herramienta ya ejecutada, y la memoria apuntaría un texto que nadie validó.

**(b2) Margen para el redactor, compilador 3:**
- Solo cambia `T_MAX_COMPOSER` (`UltronPromptCompiler.php:217`). `T_MAX_CRITIC` sigue en «~N».
- Texto propuesto (ASCII; 290 caracteres sin sustituir **[C]**): `TOPE DE LONGITUD PUBLICADO: el limite es {N} caracteres por mensaje y Laravel lo mide antes de enviar; apunta a unos {M} (dos o tres frases cortas y una sola pregunta), contando cada marcador como el texto que lo sustituye; si no cabe, da lo esencial de lo que se pregunto y ofrece ampliar.`
- **M = 80 % de N redondeado a la decena por abajo** (200 → 160; 160 → 120; 480 → 380). Que baje la tasa de 4 de 5 es una hipótesis **[S]**: se mide en el laboratorio y en la canaria.
- **Qué implica:**
  - `VERSION` pasa de 2 a 3.
  - Cambian las huellas de todo paquete con `maxChars` ≠ 320 (la de «profesional 200», `702890f7…311c`, deja de valer). **Legacy `3f6a5674…` no cambia.**
  - Lo publicado con el compilador 2 se ofrece como `legacy · compiler_changed` hasta publicar una versión nueva (M1-18). Hoy no hay nada publicado.
  - n8n no cambia: el selector solo comprueba la huella de los cinco roles (M1-18).
  - **[S]** El lector estricto del CRM y el smoke del H9 esperan `compiler: 2`: hay que comprobarlos antes de desplegar.

### 3.3 (c) Legacy

- `SalesConversationReplyService.php` **no aparece en el diff**: el agente Legacy (`SalesAgentOrchestratorService`) no cambia.
- Sin `sp.n` no se ejecuta nada nuevo.
- `compile(línea base)` y la huella Legacy siguen igual.
- `sp` de Legacy queda idéntico.

### 3.4 Contrato y textos del CRM

- **Enmienda nueva** (propuesta: «Enmienda A4-1 del H5»). Recoge:
  - que el commit lee `sp.n` y descarta o ajusta (contra `UltronPromptStamp.php:37` y M1-18);
  - D1 solo con versión aplicada;
  - el compilador 3 y su texto (M1-3);
  - la cesión de las protecciones 5–8 del H9 en los cuatro ficheros más el nuevo (precedentes M1-15 y M1-18).
- **`status.engine.message`** (`StudioPublications.php:141-142`): «no es un filtro que bloquee el envío» se refiere a las frases prohibidas y sigue siendo cierto. Propuesta opcional, solo para el personal: añadir `; la longitud, además, se mide antes de enviar y ningún texto la supera`. Ninguna prueba fija esa frase (**[C]**, `grep` en `tests/`).

---

## 4. Textos fijos nuevos para aprobar

| # | Lo lee | Texto exacto | Longitud **[C]** |
|---|---|---|---|
| T1 | cliente | `Claro. Planes de un mes: ` | 25 |
| T2 | cliente | `Claro. Nuestros planes: ` (si no hay ninguno de un mes) | 24 |
| T3 | cliente | ` También hay planes ` + duraciones derivadas («por día», «por semana», «de varios meses», unidas con «, » e «y») + `.` | 58 con las tres; 36 con una |
| T4 | cliente | ` ¿Cuál se ajusta más a lo que buscas?` | 37 |
| T5 | modelo | `T_MAX_COMPOSER` del compilador 3 (§3.2) | 290 sin sustituir |
| T6 | personal (CRM) | el añadido opcional de `status.engine.message` (§3.4) | ≈75 |

Separadores de la lista de planes: `, ` entre nombre y precio, `; ` entre planes y un punto al final.
Además, para que el propietario los vea: los **recortes** de la tabla de §3.1 y los dos del horario. No son textos nuevos.

---

## 5. Pruebas

Sobre las clases reales, por el endpoint de commit; PostgreSQL 14 en lo transaccional. Cada una, roja con `3b917bac` y verde con el arreglo, salvo las de regresión de Legacy (verdes antes y después). Datos de las pruebas: el catálogo y la ficha **de producción** tal cual (§2.1 y §3.1).

1. **D1:** dos rechazos + `PRICING_QUESTION` + `sp.n=200` → Valera, Mensual y Élite con su `Plan::price`, ≤200, `SAFE_CURATED_REPLY`, y nunca «EMPLEADOS» ni «$1». Hoy: «Cuéntame qué buscas…».
2. **D1, guard:** un plan con «gratis» en el nombre no tumba el respaldo. Mutación: inspeccionar el texto final en vez del esqueleto → roja.
3. **D1, catálogo:** sin planes de un mes; con un plan nombrado por la persona; con PROMO X4 movido delante por `sort_order`. Esta última documenta que la regla depende del orden.
4. **Horario:** «¿Cuál es el horario de atención entre semana?» (el texto real del turno 3), dos rechazos, `n=200` → 190 y con «Lunes a viernes de 5:00 a. m. a 10:00 p. m.»; `n=160` → 119; «¿y los sábados?» → con «Sabados…». Ninguna frase partida. Ficha sin días → regla general.
5. **Tope para todas las intenciones:** proveedor sobre `SalesAgentDecisionSchema::INTENTS` × {160, 200} → todo respaldo ≤ n, no vacío y que pasa el guard. Se fijan los valores de la tabla de §3.1.
6. **(b1):** borrador aprobado por el revisor de 230 caracteres con `n=200` → `policy_length_exceeded` y respaldo ajustado. Con `{{APP_LINKS}}` no cuentan las URL.
7. **Legacy:** las mismas entradas sin `sp.n` dan la salida de hoy (instantánea tomada **antes** de cambiar código). Token sin `n` → sin medida. `git diff --name-only origin/main -- app/Services/Marketing/SalesConversationReplyService.php` vacío.
8. **Token:** Studio con 200 → `sp.n = 200`; con 320 → sin `n`; Legacy → `sp` como hoy. Cambia `UltronDecidePromptsTest.php:218`.
9. **Compilador 3:**
   - cambian `UltronPromptCompilerTest.php:94`, `StudioPromptDeclarationTest.php:77,313`, `UltronStudioPublicationRulePhrasesTest.php:200`, `UltronStudioPublicationPromptsTest.php:156` y `UltronStudioStatusCatalogTest.php:42`;
   - se regeneran `tests/fixtures/ultron-prompts/compile-cases.json` y `tests/fixtures/ultron-studio/publication-cases-v2.json` con sus derivadores;
   - cambian `tools/parches-m1.cjs` (`COMPILER`, texto) y `mutaciones/spec-v2.php`;
   - **se mueven** todas las huellas con `maxChars` ≠ 320, incluida `702890f7`; **fija**, `3f6a5674…c902` (aserción explícita).
10. **Guardas:**
    - `OutreachIsolationTest::test_la_puerta_de_diff…` (`:365`, zona `app/Services/Marketing/Ultron`) **se pondrá roja** hasta subir el cambio (M1-15). `SalesConversationReplyService.php` ni siquiera está en `DIFF_GATE` (`:161-165`), y además no se toca.
    - `UltronEngineIsolationTest` sigue verde: no hay un fichero nuevo que nombre al lector.
    - No he repasado una por una las 15 guardas **[S]**: el backend las corre todas.

---

## 6. Despliegue y vuelta atrás

1. **Antes** (permisos del propietario):
   - las decisiones de §2.2 y las de abajo;
   - aprobar T1–T5 (T6 si se quiere) y la enmienda;
   - comprobar que en la canaria no hay nada publicado (`publication-check`, `prompt-trace`);
   - revisión de `security` (precios de la BD en texto de máquina, orden guard → sustitución) y de `reviewer`.
2. **Despliegue de solo código** con la puerta de diff de lista cerrada (como `ops/deploy-m1-backend.sh`). Sin migraciones, rutas, `config/` ni n8n. **[S]** Si el lector del CRM rechaza `compiler: 3`, hace falta un despliegue del CRM antes, y es otro gate.
3. **Comprobación:** `ultron:prompt-trace` y `publication-check` (Legacy sigue en `3f6a5674`).
4. **Vuelta atrás:** «Volver a Legacy» en el Studio si hubiera algo publicado; después, revertir el código. La metadata nueva es aditiva: no hay datos que deshacer.

---

## 7. Conversación canaria limpia y versión para repetir la prueba

**Variables** (`config/marketing.php`):
- `MARKETING_ULTRON_CANARY_CONVERSATION_ID` (`:83`). La leen:
  - `UltronEventEmitter.php:84` (quién entra en n8n);
  - `UltronCommitService.php:292` (el commit rechaza a otras);
  - `UltronPromptOffer.php:127`;
  - `UltronPublishedConfig.php:288,506`;
  - `StudioCanaryCoherence.php:171`, `StudioCanaryMeasures.php:274` y `StudioEngineReadings.php:196,211` (el CRM);
  - `MarketingAgentSwitch.php:229`, `SupervisionService.php:95,133,191`, `UltronTurnWatchdog.php:166`, `MarketingAiDoctor.php:77-78` y `UltronPromptTrace.php:41`. **[L]**
- `MARKETING_ULTRON_PAYMENT_CANARY_CONVERSATION_ID` (`:49`): `SalesPaymentReadinessService.php:85,95` y el doctor. Con los enlaces apagados no tiene efecto, pero se mueve por coherencia. **[L]**

**Pasos** (cada uno, permiso; producción):
1. Respaldar el `.env` y anotar los valores actuales (273).
2. Crear la conversación (mismo lead que la 273, `whatsapp`, `open`, `ai_enabled`, sin takeover, `memory = ConversationMemory::blank()->toArray()`, `commercial_phase = NEW_LEAD`) en una transacción.
3. **Hacer que el entrante caiga en ella.** `MetaLeadService::ensureConversation()` (`:68`) y el despachador (`MarketingMessageDispatcher.php:131-137`) eligen la `open`, después la de `last_message_at` más reciente y después el id mayor. Con la 273 abierta, **el WhatsApp seguiría entrando en la 273**. Recomendado: cerrar la 273 (reversible).
4. Apuntar las dos variables; `config:cache` y `queue:restart` (los workers guardan la configuración **[S]**). Es un reinicio: permiso.
5. Comprobar con `prompt-trace`, el doctor, la coherencia del canario en el CRM y `publication-check`.
6. **Vuelta atrás:** restaurar las variables, `config:cache`, `queue:restart` y reabrir la 273.

**Riesgos:**
- **«Limpia» es la conversación, no el lead.**
  - La ficha del lead vive en `lead->metadata` y `lead->objective` (`LeadProfileService.php:300,614`).
  - Las visitas se leen por lead.
  - La bienvenida dirá «Hola de nuevo», porque existe otra conversación del lead (`UltronCommitService.php:4768-4769`).
  - **[S]** Si la temperatura vuelve a `READY` por datos del lead.
  - Un lead limpio de verdad exige otro teléfono registrado como canario del Studio: es un gate mayor.
- La 273 cerrada deja de atenderse mientras dure la prueba.

**Versión para repetir:**
- Una **nueva**: la de hoy es `ROLLED_BACK`, terminal, y con el compilador 3 cambia su huella.
- Mismos parámetros («profesional», 200).
- M1-7: laboratorio y veredicto de otra persona antes de publicar.
- Publicar **después** de mover el canario.

---

## 8. Riesgos y lo que queda fuera

- **H4-4** (los revisores se contradicen en cierre con los enlaces apagados): no se arregla. Exige tocar `critic.txt` y `critic_retry.txt`, es decir, la huella Legacy y n8n. Con `NEW_LEAD` es menos probable; si aparece, el respaldo responde y cabe, pero con el texto de Laravel.
- **El tono de los respaldos:** tutean. Con «profesional», un turno que acabe en respaldo sale de tú. **La repetición puede fallar por esto** si el propietario mira el tono. Darles el tono de la versión son variantes «de usted», del lado de ULTRON, de unos 25 textos, todas para aprobar: es un paquete aparte.
- **No determinismo del modelo:** (b2) lo reduce; la garantía la da (b1), a costa de más respaldos.
- **El catálogo:** los 13 vendibles, con el de empleados a $1, ya llegan al modelo y a la voz (§2.2 b). Es independiente de este plan, pero un cliente lo puede oír ya hoy.
- **H4-6 y H4-7:** fuera.

---

## Decisiones que necesito

1. **Catálogo (§2.2):** qué planes puede nombrar ULTRON; si #16 y #14 siguen vendibles; Valera y Mensual juntos o no.
2. ¿D1 solo con versión aplicada (respeta (c)) o también en Legacy?
3. ¿El tope se mide solo con `maxChars` ≠ 320 (recomendado)?
4. ¿Los enlaces de `{{APP_LINKS}}` fuera de la cuenta (recomendado)?
5. Borrador que se pasa: ¿se descarta y sale el respaldo ajustado (recomendado) o se recorta?
6. ¿Compilador 3 con margen del 80 % y el texto T5?
7. ¿El tono de los respaldos queda fuera de esta tanda?
8. Canaria limpia: ¿mismo lead cerrando la 273, o un teléfono nuevo?
9. Aprobar la enmienda y T1–T5 (T6 si se quiere).

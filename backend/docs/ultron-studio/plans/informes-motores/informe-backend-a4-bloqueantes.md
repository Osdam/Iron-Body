# Informe del backend · Apartado 4 · Bloqueantes H4-2 (planes y precios) y H4-1 + H4-3 (tope de longitud)

12-11-2026 · rama `feat/ultron-studio-h9` (= `origin/main` = `3b917bac`) · **sin commit**: todo está en el árbol de trabajo.
Guía: `plan-arquitecto-a4-bloqueantes.md` (revisión 2) con las decisiones D-1 a D-6 del lead, que mandan donde difieren.
Marcas: **[C]** comprobado ejecutando · **[L]** leído en el código · **[S]** supuesto o no comprobado.

---

## 1. Resumen

- **H4-2 (planes y precios):** con la versión del Studio aplicada (`sp.m = studio`), a «¿qué planes tienen y cuánto cuesta?» con el revisor caído sale
  `Claro. Planes de un mes: Plan Valera, $65.000 COP; Plan Mensual, $80.000 COP; Élite, $180.000 COP. También hay planes por día, por semana y de varios meses. ¿Sobre cuál amplío la información?` (191 caracteres) **[C]**.
  Nunca «EMPLEADOS» ni «$1». El guard inspecciona el esqueleto.
- **H4-1 + H4-3 (tope de longitud; H4-1 los respaldos de Laravel, H4-3 el redactor):**
  - el tope publicado viaja firmado en el token (`sp.n`) y el commit lo cumple en los cuatro caminos que envían;
  - el horario de producción a tope 200 sale en 186 caracteres con los cuatro grupos de días;
  - un borrador aprobado que se pasa se recorta y, si no se puede, se descarta (`policy_length_exceeded`).
- **Legacy:** sin `studio` no se ejecuta ninguna rama nueva.
  - La instantánea de 33 turnos × 2 modos, tomada con el código de hoy **antes** de tocar nada, sigue idéntica **[C]**.
  - `SalesConversationReplyService.php`: diff cero **[C]**.

---

## 2. Ficheros tocados

| Fichero | Estado | Qué |
|---|---|---|
| `app/Services/Marketing/Ultron/UltronReplyFitter.php` | **nuevo** (puro) | Medir, partir en frases y ajustar: regla general, horario, borrador del modelo, añadidos 13.ter y 13.quater, respaldo de precios. |
| `app/Services/Marketing/Ultron/UltronCommitService.php` | modificado (+412/−22) | Lectura del turno, recorte del borrador, ajuste de respaldos, respaldo de precios, punto de control único y memoria de precios. |
| `app/Services/Marketing/Ultron/Prompts/UltronPromptOffer.php` | modificado (+18/−2) | `sp.n` en la oferta del Studio. |
| `tests/Unit/Marketing/Ultron/UltronReplyFitterTest.php` | nuevo | 22 pruebas puras con los textos reales. |
| `tests/Feature/Marketing/UltronA4LongitudYPreciosTest.php` | nuevo | 79 pruebas por el endpoint `decide` → `commit`, con el catálogo y la ficha de producción. |
| `tests/fixtures/ultron-a4/legacy-a4.json` | nuevo | Instantánea de Legacy tomada con `3b917bac` antes del arreglo. |
| `tests/Feature/Marketing/UltronDecidePromptsTest.php` | **1 aserción** | La única permitida (§7). |
| `docs/.../a4-textos-ajustados.md` | nuevo | Los textos para el propietario, generados por la prueba (§9). |
| `docs/.../a4-evidencia/` | nuevo | Salidas de PHPUnit: rojo, verde, mutación, guardas, PostgreSQL, línea base. |

**No tocados** (comprobado con `git diff --name-only origin/main`, salida vacía **[C]**):
- `SalesConversationReplyService.php`, `OutboundContentGuard.php`;
- `UltronPromptCompiler.php` y `UltronPromptStamp.php`;
- `resources/`, `config/`, `routes/`, `database/`;
- n8n.

---

## 3. Qué hace cada cambio

### 3.1 `sp.n` en el token (D-2)

**`UltronPromptOffer.php:189-201`.**
- En el paso 8 (`studio`), `n` = `governed['persona.maxChars']` de la parte firmada.
- Solo si es distinto de `UltronPromptCompiler::BASELINE[MAX_CHARS]`: se usa la constante, no el 320 escrito.
- Solo en `sp`: la respuesta a n8n (`studio_prompts`) no cambia **[C]** (`test_n_viaja_en_el_token_y_no_en_la_respuesta_a_n8n`).
- Legacy: `sp` idéntico, sin la clave **[C]**.
- `UltronPromptStamp::offered()` lee `sp` por clave: el sello no cambia **[L]**.

### 3.2 Lectura en el commit (D-1)

**`UltronCommitService.php:398`** → `turnoDelStudio()` (`:4984`).
- Devuelve `{studio, tope}`:
  - `studio` = `sp.m === 'studio'`;
  - `tope` = `sp.n` solo con `studio` y si `UltronReplyFitter::cap()` lo admite (entero de 160 a 480: las constantes del compilador).
- Un `n` no válido se ignora y deja el aviso `ultron.commit.length_cap_invalid`, con el tipo y sin el valor **[C]**.
- Viaja como argumento (`$turno`) a los tres caminos que envían, como `$studio` y `$studioPrompts`.
- Por defecto es `TURNO_LEGACY` (`:142`).

### 3.3 Qué se mide (D-3)

**`UltronReplyFitter::measure()`:** `mb_strlen` del texto sin `https?://\S+`. Tolerancia 0.
- «Android:  · iPhone:  · Web: » y los saltos de línea cuentan.
- Es la única función de medida, y la usan todos los caminos.

### 3.4 El borrador aprobado: recortar y, si no, descartar (D-4)

**`:1574-1594`**, después de `sinServiciosInventados` y antes de `CommercialTurnPolicy::violations()`.
- `UltronReplyFitter::draft()` suelta frases enteras desde el final.
- Nunca la primera, ni una con URL, ni una con precio (`\$\s?\d` o `\d\s?cop`).
- Si no cabe: `policy_length_exceeded` (`:1620-1622`, constante pública `VIOLACION_LONGITUD`, `:139`). Entra por la rama que ya existe, con `policy_discarded_draft` como evidencia **[C]**.
- **13.ter** (`:1724-1735`): la frase que retoma la visita solo se añade si cabe. A 200 se añade y a 160 no **[C]**.
- **13.quater** (`:1744-1760`): el precio verificado tiene prioridad.
  - Se sueltan frases del cuerpo con la misma regla y, si ni así cabe, sale solo.
  - Las frases que suelta se suman a `length.dropped` **[C]**.

### 3.5 Respaldos de Laravel con tope (D-5)

Se trabaja sobre el texto que ya devuelven `replyFor()`, `welcomeReplies()` y `lastResorts()`, sin cambiarlos.

**Regla general** (`UltronReplyFitter::curated()`):
- obligatorias la primera frase y la pregunta final;
- se sueltan las del medio desde la segunda;
- después, la pregunta.

Los números de la tabla §3.1 del plan coinciden todos **[C]**: 185/139, 151, 125, 195/132, 183/134, 162/112, 87 y 93.

**Horario** (`UltronReplyFitter::schedule()`):
- obligatorio el grupo de días preguntado;
- se suelta en este orden: aclaración → pregunta → grupos no preguntados, del último al primero.

Resultados con la ficha de producción **[C]**:

| Tope | Sale | Longitud |
|---|---|---|
| 200 | los cuatro grupos, sin pregunta | **186** (tu cuenta, confirmada) |
| 160 | laborables, sábados y domingos | **150** |

- Una ficha sin días sigue la regla general.

**`safeFallback()`** (`:1980-2075`). Orden de caída: entero → ajustado → `lastResorts` que quepan → la más corta segura.
- El guard (`:2027`) y la antirrepetición miran el texto ya ajustado.
- `respuestaConHechos($tope)` (`:4488`) ajusta por piezas de la base de conocimiento.
- `ultimoRecurso($tope)` (`:4729`) filtra las variantes que caben.

**`respaldoPorIntencion()`** (`:4785`):
- bienvenida y memoria, ajustadas;
- `general_info`, por piezas;
- precios (D-6);
- **horario ajustado** con tope (plan §3.2; ver decisión 5.3);
- el resto, el último recurso que quepa.

**Sustitutos de la política con tope** (`planReply` `:1671`, `courtesyReply` `:1687`, `respuestaConHechos` `:1704`): se ajustan o no se usan.

**Hora de la visita** (`:2462-2470`): regla general antes del punto de control. La pregunta de la hora es obligatoria.

### 3.6 Punto de control único (D-5)

**`antesDeEnviar()` (`:5017`).** Toda respuesta pasa por él en la sentencia anterior a `dispatchWhatsapp` (`:1765`, `:2259`, `:2470`).
- Sin tope: devuelve el texto y no escribe nada.
- Con tope:
  - si algo llega pasado, `ChannelLog::error('ultron.commit.length_cap_overflow')` y el último recurso que quepa (nunca un turno mudo);
  - escribe `metadata.length`.
- Una prueba estructural fija que los tres envíos pasan por él y que el cuarto es el del enlace de pago **[C]**.

### 3.7 Respaldo de precios (D-6)

**`respaldoDePrecios()` (`:5059`)**, usado en dos sitios:
- `respaldoSemantico()` (`:4884`), antes del corte por `RESPALDO_PROPIO`, pasando por `safeFallback` (`:1980`);
- `respaldoPorIntencion()` (`:4805`), en la rama de la política (`:1624-1629`).

Solo con `studio`. Los sustitutos que ya existen siguen después, sin cambios.

**Selección.** Ningún id ni nombre escrito en el código:
- primero `hard_required_plan` (con la misma `CommercialTurnPolicy::decide()`, `planNombradoEn()` `:5128`);
- después los vendibles de 28 a 31 días en el orden de `activePlans()`; como mucho 3;
- sin ninguno de un mes, los 3 primeros con su duración.

**Texto.**
- Las cifras salen de `Plan::price` + `formatCop()`.
- Las duraciones salen de `UltronDraftPlaceholders::resolve('{{PLAN_DURATION}}')`, el método público, porque `duration()` es privado.
- «También hay planes …» se deriva de los vendibles no nombrados: 1 → por día; 2 a 27 → por semana; 32 o más → de varios meses.
- Segmentos y orden de caída: «También…» → plan 3 → pregunta → plan 2.

**Guard sobre el esqueleto.** Un plan llamado «…gratis…» no lo tumba **[C]**. La mutación «inspeccionar el texto final» pone la prueba en rojo **[C]** (`a4-evidencia/03-…txt`).

**Memoria** (`conPreciosEntregados()`, `:5154`, llamada en `:1818` y `:2276`).
- **Comprobado con el detector real** **[C]**: con `$plan = null`, `recordSentTurn()` anota los tres planes como *discutidos* (por nombre), pero **no** sus precios como *entregados*.
- Por eso, después de `recordSentTurn()`, el commit anota `deliverPrice()` de cada plan cuyo «nombre, precio» está literalmente en el texto que salió. Verificado: planes 3, 4 y 8 entregados; 16 no.
- `last_agent_offer` queda en `null`: «¿Sobre cuál amplío la información?» no tiene verbo de ofrecimiento para `offerKindOf()`, así que no se lee como oferta. Es lo mismo que hace hoy cualquier respaldo curado.
- `last_agent_question` = esa pregunta.

---

## 4. Inventario de envíos del commit

| # | Línea | Camino | Clasificación |
|---|---|---|---|
| 1 | `:1766` | Camino normal: el borrador del modelo o sus sustitutos (política, recuperación por repetición/reventa, 13.ter, 13.quater). Incluye el checkout en línea, con los enlaces de pago encendidos. | **Con tope**, por `antesDeEnviar` (`:1765`). |
| 2 | `:2260` | `safeFallback`: revisor caído (`handleCriticFailure`) y rendición (`handleRecovery`). | **Con tope**, por `antesDeEnviar` (`:2259`). |
| 3 | `:2471` | `contestarLaHoraDeLaVisita` (§7.ter). | **Con tope**, regla general (`:2465`) y `antesDeEnviar` (`:2470`). |
| 4 | `:3038` | `execPaymentLink`: el mensaje aparte del enlace de pago. | **Fuera del tope**, como decidiste: lleva importe y enlace, y no se recorta. Con los enlaces de pago apagados en producción, no se alcanza. |

No hay más llamadas a `dispatchWhatsapp` en `app/Services/Marketing/Ultron/` **[C]** (`grep`). `blocked()` y las salidas `no_reply` / `NO_REPLY_AND_HANDOFF` no envían.

---

## 5. Decisiones que tomé fuera de lo escrito

1. **Encabezado «Nuestros planes» también cuando la lista mezcla duraciones.** Si la persona nombra un plan que no es de un mes (p. ej. Trimestre) y hay planes de un mes, «Planes de un mes: Trimestre, …» sería falso. Entonces se usa `Claro. Nuestros planes: ` con `{nombre} ({duración}), {precio}` para todos.
2. **Memoria de precios escrita desde el commit.** `conPreciosEntregados()` usa la API pública de `ConversationMemory` (`deliverPrice`) y guarda `memory` con `forceFill`, como hace `ConversationMemoryService`. Así no hace falta tocar ese servicio, que no está en la lista de ficheros permitidos. Si prefieres un método `recordPricesDelivered()` en el servicio, es un cambio de 10 líneas.
3. **`respaldoPorIntencion` + horario con tope.** El plan §3.2 amplía la rama de la política así: «`SCHEDULE_QUESTION` → horario ajustado y **el resto → curado ajustado**».
   - **Implementé solo el horario**, y solo con tope: es el caso medido, y hoy esa rama da el último recurso, que no contesta.
   - **«El resto → curado ajustado» no lo hice**: cambiaría el sustituto de todas las violaciones de política para todas las intenciones con versión aplicada. Además, algunos curados (`replyFor(PRICING)`, objetivo sin precio) incumplen la política que acaba de tumbar el borrador. **Decisión tuya.**
4. **`metadata.length`** (aditiva, solo con tope): `{cap, action, from, to, dropped}` y `overflow: true` si actuó el punto de control.
   - `action` ∈ `fit` · `trimmed` · `discarded` (borrador) · `fitted` · `last_resort` (textos de Laravel).
   - `from` = medida del primer candidato; `to` = lo que salió; `dropped` = frases soltadas (incluye las que soltó 13.quater).
   - Lectores comprobados: `UltronCanaryReport`, `UltronPromptTrace`, `StudioCanaryMeasures` y `StudioCanaryEvidence` leen `metadata` por clave, y una clave más no los rompe **[L]**.
   - El CRM no lee `marketing_ai_actions` directamente; pasa por esas clases **[L]**.
5. **El resto de usos de `ultimoRecurso()`** (recuperación por repetición/reventa `:1018`, turno de cobro `:869`, excusa de precio `:4723`) no recibe el tope.
   - Sus variantes miden todas menos de 150 **[C]**, y el texto pasa después por el recorte y por el punto de control.
   - Así el diff es mínimo.
6. **Las pruebas fijan el documento para el propietario.** `test_los_textos_ajustados_que_aprueba_el_propietario` genera `a4-textos-ajustados.md` con `A4_TEXTOS=escribir` y, sin esa variable, exige que el código produzca exactamente esos textos. Si un texto cambia, la prueba falla hasta regenerar el documento y volver a aprobarlo. En esa prueba sube el limitador interno (`automation.rate_limits.marketing_ai`): son unos 260 turnos en el mismo minuto congelado.

---

## 6. Pruebas ejecutadas

Una sola ejecución de PHPUnit cada vez. Desde `backend/`.

| Qué | Comando | Resultado |
|---|---|---|
| **Rojo, con el código de hoy** (antes de tocar `app/`) | `phpunit --no-logging tests/Unit/.../UltronReplyFitterTest.php tests/Feature/.../UltronA4LongitudYPreciosTest.php` | **100 pruebas: 81 errores, 14 fallos, 5 verdes**. Verdes: las 2 de Legacy contra su propia instantánea, las 2 de `do_not_contact` (no hay texto que medir) y la de «`n` no va en la respuesta». `a4-evidencia/01-rojo-antes-del-arreglo.txt`. **Corrección (§13):** ese número sobrestima el rojo de comportamiento. Casi todos los errores eran `Class "UltronReplyFitter" not found`. Con la clase disponible, el código anterior deja **38 rojas de 80** (2 errores y 36 fallos), según midió el revisor (`informe-reviewer-a4.md`, «Verificado»); las 42 verdes son casos de la matriz que ya cabían y las dos de Legacy. Ese dato es del revisor: yo no lo volví a medir. |
| Instantánea de Legacy | `A4_INSTANTANEA=escribir … --filter test_legacy_da_exactamente_la_salida_de_hoy` | Escrita con `3b917bac`, cuando el árbol solo tenía los dos ficheros de prueba nuevos (`git status`). |
| **Verde, SQLite** | las dos nuevas + `UltronDecidePromptsTest` | **OK (239 pruebas, 3463 aserciones)**. `a4-evidencia/02-verde-despues-sqlite.txt` |
| Archivo A4 tras `pint` | `UltronA4LongitudYPreciosTest.php` | **OK (79, 943)** |
| Mutación §5.2 | el guard sobre el texto final | **Rojo**, como debe; restaurado (`cmp` idéntico). `a4-evidencia/03-…txt` |
| **Marketing completo, SQLite** | `tests/Feature/Marketing tests/Unit/Marketing` | **OK (6129 pruebas, 35803 aserciones)**, sin tocar ninguna existente salvo la de §7. `a4-evidencia/07-…txt` |
| Studio completo, SQLite | `tests/Feature/UltronStudio tests/Unit/UltronStudio` | 3767 pruebas, 277 omitidas, **1 roja: la esperada** (`OutreachIsolationTest::test_la_puerta_de_diff…`) |
| **Guardas** (el comando que diste) | Isolation, ReadOnly, StaticGuards y SecretExposure | 138 pruebas, **1 roja: la esperada** (lista `UltronPromptOffer.php` y `UltronCommitService.php`). `a4-evidencia/04-guardas.txt` |
| **PostgreSQL 14** (`UNIT_DB=ultron_studio_lead_ola5`) | A4, `UltronCommitTest`, `UltronCommitPromptsTest`, `UltronCommitStudioStampTest`, `UltronDecidePromptsTest` y el ajustador | 351 pruebas, **2 rojas previas y ajenas** (§8.1). `a4-evidencia/05-postgresql.txt` |
| PostgreSQL, línea base | las 2 rojas, con los dos ficheros de `HEAD` puestos un momento | **Fallan igual con `3b917bac`**. Mis ficheros restaurados. `a4-evidencia/06-…txt` |
| PostgreSQL, final | A4, ajustador, decide, prompts y sello | **OK (304 pruebas, 3921 aserciones)**. `a4-evidencia/05b-…txt` |
| `pint` | los 6 ficheros tocados | `passed` |

**Las pruebas del plan §5:**
- §5.1-5.8: todas, salvo la 9 (compilador).
- **D-4:** recorta; salta la frase del precio y la de los enlaces; descarta si no cabe; 13.ter y 13.quater con tope.
- Punto de control: estructural y de comportamiento (por reflexión sobre la instancia real).
- `n` fuera de rango, con su aviso.

---

## 7. Aserciones existentes cambiadas

**Una sola:** `tests/Feature/Marketing/UltronDecidePromptsTest.php:218-219`.
- La forma exacta de `sp` gana `+ ['n' => 200]` solo cuando la oferta es `studio`; esa matriz publica «profesional, 200».
- Motivo: D-2.
- Las ramas Legacy de la matriz siguen exigiendo `sp` sin la clave.

---

## 8. Hallazgos adyacentes (no arreglados)

1. **`UltronCommitTest::test_allowed_v1_tools_are_executed` y `test_staff_review_without_a_cause_is_not_granted` fallan en PostgreSQL** desde antes de este cambio (comprobado con `3b917bac`).
   - Usan `assertSame` sobre `metadata.staff_review`, y `jsonb` no conserva el orden de las claves.
   - En SQLite pasan. Es de la prueba, no del código.
2. **`general_info` con tope pierde todos los hechos si la ficha es una pieza grande.** → **Resuelto con el ajuste 2 (§12).**
   - Con la ficha del horario como única pieza (225 caracteres), a 200 sale «Con gusto te cuento. ¿Qué te cuento más a fondo…?» (87). La regla general suelta la pieza entera.
   - Si eso entra por `VIOLACION_SIN_HECHO`, el sustituto ya no aporta ningún hecho.
   - En producción hay más piezas (identidad, ubicación) **[S]**, que se sueltan desde la segunda (la identidad, primero).
   - Para que el propietario lo mire en `a4-textos-ajustados.md`.
3. **Checkout en línea y descarte** (con los enlaces de pago apagados en producción, no se alcanza).
   - Si un borrador con el checkout pegado no cabe ni recortado, se descarta y el enlace acuñado no sale, pero la fila lo anota como `inlined`.
   - Hoy ya pasa igual cuando la política descarta un borrador con checkout. Es previo y lo comparte la rama de la política.
4. **La puerta de diff del H9** solo mira ficheros versionados. Hoy lista dos ficheros; **tras el commit listará tres** (`UltronReplyFitter.php`, nuevo). Para la enmienda.
5. **El tono:** salvo el de precios (neutro), los respaldos siguen tuteando; con «profesional» sale de tú (plan §8). Fuera de esta tanda.

---

## 9. `a4-textos-ajustados.md`

**Generado** por la prueba, con el catálogo de 13 planes y la ficha de 253 caracteres de producción. Cubre las 27 intenciones de `SalesAgentDecisionSchema::INTENTS`, en cuatro modos: Legacy, versión sin tope (320), 200 y 160.

| Bloque | Variantes |
|---|---|
| Horario | entre semana, sábado y sin día |
| Precios | sin nombrar plan y nombrando «Élite» |

Es la propuesta que aprueba el propietario. La prueba la mantiene: si cambia un texto, se pone roja.

---

## 10. Lo que no pude comprobar

- **Nada contra n8n, producción ni la canaria real.** Todo es PHPUnit local (SQLite y PostgreSQL 14 desechable).
- **El compilador 3** (el margen del redactor) no es mío. Con el compilador 2 de hoy, el redactor seguirá pasándose a menudo y el commit recortará o descartará más. La tasa real se mide en el laboratorio o en la canaria **[S]**.
- **El frontend del CRM.** Comprobé los lectores del backend de `metadata`, no el código del CRM **[L]**.
- **El resto de la base de conocimiento de producción** (identidad, ubicación…): no la tengo. `general_info` está probada solo con la ficha del horario.
- **El tiempo:** las cuatro partes del orden de trabajo están hechas y probadas. Pasé de los 90 minutos sobre todo por las ejecuciones completas (Marketing, Studio, guardas y PostgreSQL).

---

## 11. Variables, migraciones y configuración

Nada nuevo:
- sin migraciones, rutas, claves de `config/` ni variables de entorno;
- la metadata es aditiva.

**Vuelta atrás:** revertir los tres ficheros de `app/` (y borrar el nuevo). No hay datos que deshacer.

**Revisión:**
- `security`: cifras de la base de datos en texto de máquina; el orden guard → sustitución;
- `reviewer`: antes de desplegar.

---

## 12. Ajustes tras la revisión del lead

Las cinco preguntas, contestadas por el lead:
- (1) «El resto → curado ajustado»: **no**, se queda como está.
- (2) «Nuestros planes» con duraciones cuando la lista mezcla: **sí**.
- (3) Memoria de precios desde el commit: **sí**.
- (4) `general_info`: lo resuelve el ajuste 2.
- (5) La puerta de diff con tres ficheros: la recoge el lead en la enmienda.

### Ajuste 1 · Abreviaturas en el partidor de frases

**`UltronReplyFitter::sentences()` y la constante `ABREVIATURA`.** Hoy «Estamos en la Av. La Toma # 10-20, barrio Altico.» se partía tras «Av.» y el recorte del borrador podía dejar «Estamos en la Av.».
- Ahora un trozo que termina en una abreviatura de la lista se pega al siguiente con su mismo separador.
- La lista: Av, Avda, Cra, Kra, Cr, Cl, Cll, Dg, Diag, Tv, Transv, Nro, Num, Núm, Sr, Sra, Srta, Dr, Dra, Ing, Lic, Ed, Edif, Apto, Urb, Km, Tel, Cel, Ext, Int, Mz, Of y Loc, sin distinguir mayúsculas.
- No es un *lookbehind*: así no depende de cómo resuelva el PCRE de producción los de longitud variable.
- «etc.», «a. m.» y «p. m.» seguidos de mayúscula siguen siendo frontera.

**Pruebas puras [C]:**
- la dirección de la Av. no se parte;
- «Cl. 24 Sur #33-53, Neiva, Huila. ¿Vas a ir por primera vez?» da dos frases;
- la ficha del horario sigue dando las mismas siete;
- cada abreviatura, con o sin mayúscula;
- el recorte ya no deja «Estamos en la Av.».

**«No», fuera de la lista (decisión del lead).** La negación al final de frase es mucho más frecuente que «No.» seguido de mayúscula, y «No. 12» ya no era frontera porque sigue una cifra.
- Prueba pura nueva: «Creo que no. Mañana te cuento.» → dos frases **[C]**.
- Ajustador + A4 en SQLite: **OK (104 pruebas, 1164 aserciones)** (`a4-evidencia/11-sin-no-sqlite.txt`).
- `pint`: `passed`.
- El documento de textos sigue igual (la prueba que lo fija pasa).

**Ningún texto de respaldo cambió por esto [C]:** el diff del documento solo toca `general_info`, por el ajuste 2.

### Ajuste 2 · La ficha por piezas: llenado en orden

**`UltronReplyFitter::pieces()`** (lo usa `respuestaConHechos($tope)`):
- obligatorias la apertura y la pregunta;
- después entran las piezas del medio en su orden mientras quepan; la que no cabe se salta y se prueba la siguiente;
- devuelve null si ni la apertura con la pregunta caben.

**Datos de producción en la prueba:**
- identidad (66);
- ubicación, con un teléfono ficticio que `sinTelefonos` retira;
- horario (224);
- sin `gym_info`.

**Resultado [C]:** a 200 y a 160 sale `Con gusto te cuento. Iron Body Neiva es un centro de acondicionamiento físico en Neiva. ¿Qué te cuento más a fondo: los planes, las clases o cómo empezar?` (154). Antes salían 87, sin ningún hecho.
- Pruebas puras: llenado, salto de la pieza que no cabe, entero si cabe y null.
- Prueba de funcionalidad por el endpoint (`test_informacion_general_a_200_y_160_conserva_la_identidad`), que además comprueba que sin la versión aplicada sale la ficha entera como hoy.
- El rojo de la regla anterior está en `a4-evidencia/08-ajustes-rojo-antes.txt`, a nivel de la clase. La prueba de funcionalidad la escribí después de cambiar `pieces()`, así que su rojo no lo ejecuté.

**Documento regenerado.** Las tres piezas solo están en la prueba que genera `a4-textos-ajustados.md` y en la de `general_info`, con el ayudante `fichaDeProduccion()`.
- Diff del documento: solo cambian la cabecera y `general_info` (Legacy 444; 200 y 160, 154).

**La instantánea de Legacy NO se tocó.** Sus datos de prueba no cambian (no lleva las piezas nuevas), así que no hacía falta volver a tomarla. El fichero conserva la fecha de su captura con `3b917bac` (12-11, 18:42), y su prueba sigue en verde.

### Ajuste 3 · Etiquetas

H4-2 = planes y precios; H4-1 + H4-3 = tope de longitud (H4-1 los respaldos de Laravel, H4-3 el redactor). Corregido en:
- el título y el §1 de este informe;
- el docblock de `UltronReplyFitter` («bloqueantes H4-1 y H4-3»);
- el de `UltronA4LongitudYPreciosTest`.

En `UltronCommitService` no había ninguna etiqueta H4 (`grep`).

### Pruebas de esta ronda

| Qué | Resultado |
|---|---|
| Rojo de los ajustes 1 y 2 (pruebas puras, antes de cambiar el ajustador) | 24 pruebas, **2 fallos** (las dos nuevas). `a4-evidencia/08-ajustes-rojo-antes.txt` |
| SQLite: ajustador + A4 + `UltronDecidePromptsTest` | **OK (242 pruebas, 3522 aserciones)**. `a4-evidencia/09-ajustes-verde-sqlite.txt` |
| PostgreSQL 14: A4 | **OK (80 pruebas, 952 aserciones)**. `a4-evidencia/10-ajustes-postgresql-a4.txt` |
| `pint --test` (los 6 ficheros) | `passed` |

No he vuelto a pasar en esta ronda las suites completas de Marketing y Studio, ni las guardas. El cambio se limita a `UltronReplyFitter`, que solo se ejecuta con tope, y a pruebas nuevas.


---

## 13. Correcciones tras las revisiones de seguridad y del revisor

12-11-2026 · sobre `50cbd3a6` (el cambio revisado es `daedbbd8`) · **sin commit**: todo está en el árbol de trabajo.
Guía: el encargo del lead con las decisiones F1 a F7, que cierran B-1, I-1 a I-4, S-1 y la observación funcional de `security`, y los menores m-1, m-3 (lo barato), m-4, m-5 y m-8.
Marcas: **[C]** comprobado ejecutando · **[L]** leído en el código · **[S]** supuesto o no comprobado.

### 13.1 Qué cambió (fichero y línea, en el árbol de ahora)

**`app/Services/Marketing/Ultron/UltronReplyFitter.php`**

| Línea | Cambio |
|---|---|
| `:18-31` | Docblock de la clase: hay dos reglas, una para los textos de Laravel y otra para el borrador del modelo (solo su pregunta final), y se explica por qué. «Nunca corta una frase por la mitad» incluye ahora el número de una lista. |
| `:71-81` | `NUMERO_DE_LISTA` (F4): uno o dos dígitos y un punto, al inicio del texto o tras un espacio o un salto de línea. |
| `:83-84` | `CIFRA` (`/\d/u`): la pregunta final que la lleva no se suelta (decisión del lead tras F1, §13.8). |
| `:141` | `sentences()`: un trozo que acaba en un número de lista se pega al siguiente, igual que una abreviatura. |
| `:263-317` | **`draft()` (F1).** Toma la última frase que no sea el bloque de enlaces. Si es una pregunta, no es la primera y **no lleva ninguna cifra**, la suelta y, si así cabe, devuelve `dropped` 1. En cualquier otro caso devuelve null (descarte). El docblock explica B-1, S-1, I-2 y la cifra. |
| `:319-348` | **`append()`.** Devuelve ahora `{text, dropped}` o null. Con prioridad: el cuerpo se ajusta con la misma regla (solo su pregunta final, y no si lleva cifra). Si ni así cabe, sale la frase sola si cabe (`dropped` = frases del cuerpo). Si ni ella cabe, null (m-8). |
| — | Fuera: `PRECIO` y `protegida()`. Con la regla nueva no los usa nadie (`grep`). |

**`app/Services/Marketing/Ultron/UltronCommitService.php`**

| Línea | Cambio |
|---|---|
| `:1569-1582` | Comentario del recorte con la regla nueva. Sigue antes de `violations()`. |
| `:1630` | La rama de la política pasa la política entera a `respaldoDePrecios()` (F6). |
| `:1712-1732` | **F2.** Con tope, el borrador descartado por longitud y la cortesía ejecutada en el turno, la alternativa es el acta de la fila (`CourtesyRequestService::actaDeLaFila()`), ajustada con `curated()` y pasada por el guard. El plan va null. |
| `:1734-1754` | **m-3:** si el texto se sustituyó, `metadata.length` = `{action: replaced, from: medida del borrador, dropped: 0}`, y `to` lo pone el punto de control. **F3:** si la alternativa es el respaldo de precios, `$plan = null`. |
| `:1769` | 13.ter con el nuevo contrato de `append()`. Sin cambio de comportamiento. |
| `:1790-1811` | 13.quater: `dropped` lo da el ajustador, no la diferencia de frases (m-3). Si `append()` devuelve null, el cuerpo sale como estaba y se registra el aviso `ultron.commit.verified_price_skipped_cap` (m-8). |
| `:2033` | `safeFallback()`: `respaldoDePrecios($this->politicaDePrecios(...))` (F6). |
| `:3705-3709`, `:3755-3770` | `citaDelTurno()` sale de `conLaFechaDeLaCita()`, sin cambio de comportamiento, para que F2 lea la misma cita. |
| `:4931-4942` | m-4: los dos docblocks de `respaldoSemantico()` quedan en uno. |
| `:5066-5085` | m-1: el comentario de `antesDeEnviar()` dice lo que hace. Si no hay último recurso que quepa, sale igualmente un texto (el más corto de los seguros o, si no hay ninguno, el que llegó) con `overflow: true`. |
| `:5110-5223` | **`respaldoDePrecios(array $policy, ?int $tope)`:** <ul><li>F6: fuera `forbidden_plan_ids`, salvo `hard_required_plan`; también fuera de «También hay…»;</li><li>F5: «1 día» en el respaldo;</li><li>m-5: las duraciones de «También hay…» (1 → por día; de 5 a 9 → por semana; 32 o más → de varios meses; el resto no se menciona).</li></ul> |
| `:5225-5246` | `planNombradoEn()` → `politicaDePrecios()`: devuelve el `decide()` entero (plan nombrado y rechazados). |
| `:5248-5262` | F3: el docblock de `conPreciosEntregados()` corregido. |

**Pruebas:** `tests/Unit/Marketing/Ultron/UltronReplyFitterTest.php` y `tests/Feature/Marketing/UltronA4LongitudYPreciosTest.php` (§13.3 y §13.4).

**Documento:** `a4-textos-ajustados.md`, regenerado por su prueba: **+14 líneas, ninguna cambiada**. Son las dos secciones nuevas de §13.5.

**No tocados:**
- `SalesConversationReplyService.php`, `UltronDraftPlaceholders.php`, `OutboundContentGuard`, `UltronPromptOffer.php` y el compilador;
- `config/`, rutas, migraciones y n8n;
- la instantánea `legacy-a4.json` (no se regeneró y sigue en verde).

### 13.2 Hallazgo → corrección → prueba

| Hallazgo | Corrección | Prueba (rojo con `50cbd3a6` → verde) |
|---|---|---|
| **B-1** (bloqueante): salían «Te entiendo.», «Claro.», «Listo.» o «¡Hola de nuevo!» solos | F1: el recorte solo quita la pregunta final | <ul><li>`test_b1_el_recorte_nunca_deja_solo_la_apertura`: los 5 borradores × 160 y 200 por el endpoint, 9 rojas de 10;</li><li>`test_los_borradores_de_b1_no_quedan_en_su_apertura` (pura).</li></ul> |
| **S-1**: la frase de la condición se soltaba | F1 | <ul><li>`test_la_frase_que_pone_la_condicion_no_se_suelta`: el literal del informe, puro;</li><li>`test_s1_un_borrador_con_la_condicion_al_final_se_descarta_no_se_recorta`: por el endpoint (ver §13.6.1).</li></ul> |
| **I-2**: la visita registrada sin su día y su hora | F1 deja de soltar la constancia, y como el borrador entonces se descarta, F2 pone el acta de la fila | `test_r7_la_constancia_de_una_visita_registrada_no_se_pierde` (R7, a 200 y 160, y Legacy igual) |
| **I-1**: memoria y `price_source` con el plan del modelo | F3 | `test_r1_la_memoria_no_anota_el_precio_del_plan_del_modelo_si_no_salio` (R1, Trimestre) |
| **I-3**: «3.» colgando | F4 | <ul><li>`test_una_lista_numerada_no_se_parte_tras_el_numero` (pura, con la lista de R2 y las cifras);</li><li>`test_r2_una_lista_numerada_sale_entera_o_no_sale` (endpoint).</li></ul> |
| **I-4**: «1 días» y el formato con duraciones sin aprobar | F5 en el respaldo, y dos casos nuevos en el documento | <ul><li>`test_un_plan_nombrado_que_no_es_de_un_mes_no_va_bajo_planes_de_un_mes` (mata M10);</li><li>`test_sin_planes_de_un_mes…` (cambiada);</li><li>la del documento.</li></ul> |
| **M12** sobrevivía | — (el código era correcto) | `test_precios_en_la_rama_de_la_politica_con_la_version_y_sin_tope`: verde con `50cbd3a6`, roja con la mutación [C] |
| **Observación de `security`**: planes rechazados | F6, en los dos caminos | `test_el_respaldo_de_precios_no_nombra_un_plan_rechazado` (revisor caído, rama de la política, y el rechazado que se vuelve a nombrar) |
| **m-1** | Comentario | — |
| **m-3** (lo barato) | `replaced` en la rama de la política; `dropped` de 13.quater desde el ajustador | <ul><li>`test_la_traza_dice_que_el_texto_se_sustituyo` (R5);</li><li>`test_si_no_se_puede_recortar…` (cambiada).</li></ul> |
| **m-4** | Un solo docblock | — |
| **m-5** | Duraciones de «También hay…» | `test_las_demas_duraciones_solo_se_nombran_si_se_describen_bien` (un plan de 15 días) |
| **m-8** | `append()` null y el cuerpo sale como estaba, con aviso | <ul><li>`test_si_la_frase_del_precio_no_cabe_ni_sola_el_cuerpo_sale_como_estaba` (endpoint, plan de nombre larguísimo);</li><li>la de `append()` (pura).</li></ul> |
| **Pregunta final con cifra** (decisión del lead, §13.8) | `draft()` no suelta una pregunta con un dígito; `append()` hereda la regla | <ul><li>`test_una_pregunta_final_con_una_cifra_no_se_suelta` (pura: precio, hora, sin cifras y el cuerpo de 13.quater);</li><li>`test_la_pregunta_final_con_el_unico_precio_no_se_suelta` (endpoint, a 200).</li></ul> |

### 13.3 Pruebas nuevas

**Ajustador (puras):**
- `test_el_borrador_solo_suelta_su_pregunta_final`;
- `test_los_borradores_de_b1_no_quedan_en_su_apertura`;
- `test_la_frase_que_pone_la_condicion_no_se_suelta`;
- `test_el_recorte_salta_la_linea_de_los_enlaces_y_suelta_la_pregunta_de_antes`;
- `test_una_lista_numerada_no_se_parte_tras_el_numero`;
- `test_una_pregunta_final_con_una_cifra_no_se_suelta` (§13.8).

**A4 (por el endpoint, con las clases reales):**
- `test_b1_…` (10 casos);
- `test_s1_…`;
- `test_r2_…`;
- `test_r7_…`;
- `test_r1_…`;
- `test_un_plan_nombrado_que_no_es_de_un_mes_…`;
- `test_precios_en_la_rama_de_la_politica_con_la_version_y_sin_tope`;
- `test_el_respaldo_de_precios_no_nombra_un_plan_rechazado`;
- `test_las_demas_duraciones_…`;
- `test_la_traza_dice_que_el_texto_se_sustituyo`;
- `test_si_la_frase_del_precio_no_cabe_ni_sola_…`;
- `test_la_pregunta_final_con_el_unico_precio_no_se_suelta` (§13.8).

### 13.4 Pruebas existentes cambiadas (fijaban el recorte antiguo o el texto con errata)

1. **`UltronReplyFitterTest::test_el_borrador_se_recorta_desde_el_final`** → `test_el_borrador_solo_suelta_su_pregunta_final`. Esperaba soltar 2 frases, una de ellas enunciativa.
2. **`UltronReplyFitterTest::test_el_recorte_salta_la_frase_con_precio_y_la_que_lleva_url`** → `test_el_recorte_salta_la_linea_de_los_enlaces_y_suelta_la_pregunta_de_antes`. Esperaba «Claro, te cuento. Su valor es…», soltando la del medio.
3. **`UltronReplyFitterTest::test_si_no_se_puede_recortar_se_descarta`.** Se integra en la 1, con los mismos dos casos.
4. **`UltronReplyFitterTest::test_la_frase_del_precio_verificado_tiene_prioridad_…`.** Ahora con `{text, dropped}`. A 120 sale la frase del precio sola (antes, el cuerpo sin «También…»). Se añaden 150 (solo la pregunta del cuerpo) y 40 (null, m-8).
5. **`A4::test_el_recorte_salta_la_frase_del_precio`** → `test_un_borrador_que_cierra_con_el_precio_no_se_recorta_se_descarta`. Antes salía «Claro, te cuento del Élite. Su valor es $180.000 COP al mes.»; ahora sale el respaldo de precios con el Élite primero.
6. **`A4::test_los_enlaces_de_la_app_no_cuentan_y_su_linea_no_se_suelta`.**
   - Borrador nuevo (161): la pregunta va justo antes de los enlaces y se suelta; los enlaces se quedan con su salto (137).
   - El borrador antiguo (164 sin la pregunta) ahora se **descarta**, y la prueba lo fija: los enlaces no constan como enviados (§13.6.2).
7. **`A4::test_si_no_se_puede_recortar_se_descarta_y_sale_el_respaldo_ajustado`.** `metadata.length` pasa de `discarded` a `replaced` (m-3) con `from` y `to` exactos, y `price_source` null (F3).
8. **`A4::test_el_precio_verificado_tiene_prioridad_sobre_el_cuerpo`.**
   - A 160 sale «El precio actual del Plan Mensual es $80.000 COP.», con `{trimmed, from 179, to 49, dropped 3}`. Antes salía el cuerpo sin «Además…» y con el precio detrás.
   - Se añade el caso en que basta quitar la pregunta del cuerpo (137).
9. **`A4::test_sin_planes_de_un_mes_nombra_los_tres_primeros_con_su_duracion`.** «(1 días)» pasa a «(1 día)».
10. **`A4::test_los_textos_ajustados_que_aprueba_el_propietario`.** Dos casos nuevos en el generador (F5); el documento, regenerado.

### 13.5 Números de las pruebas

Desde `backend/`, una ejecución de PHPUnit cada vez.

| Qué | Resultado |
|---|---|
| **Rojo**: pruebas nuevas y cambiadas con el código de `50cbd3a6` (`git diff --quiet HEAD -- app` antes de ejecutar). Ajustador + A4 | **126 pruebas: 30 rojas (1 error y 29 fallos)** [C]. Rojas: todas las nuevas y cambiadas, salvo dos verdes a propósito, porque fijan un comportamiento que ya era correcto: M12 y «B-1 horario a 200», que `50cbd3a6` ya recortaba solo por la pregunta. Las 96 restantes, verdes, son las que no cambian, incluida la instantánea de Legacy. `a4-evidencia/17-correcciones-rojo-antes.txt` |
| **Por qué hace falta F2**: mutación con F1 hecho y el bloque de F2 desactivado | `test_r7_…` **roja**. La rama de la política daba «Con gusto te cuento. ¿Qué te cuento más a fondo: los planes, las clases o cómo empezar?», sin día ni hora [C]. Restaurado (`cmp` idéntico). `a4-evidencia/19-correcciones-mutacion-f2-sin-acta.txt` |
| Mutación M10 (cabecera «Planes de un mes» siempre) | `test_un_plan_nombrado_…` **roja** [C]. Restaurado (`cmp`). `a4-evidencia/20-correcciones-mutacion-m10.txt` |
| Mutación M12 (precios de la rama de la política colgando del tope) | `test_precios_en_la_rama_de_la_politica_…` **roja** [C]. Restaurado (`cmp`). `a4-evidencia/21-correcciones-mutacion-m12.txt` |
| Documento de textos | Regenerado con `A4_TEXTOS=escribir`: **+14 líneas, 0 cambiadas** [C]. Las dos secciones nuevas: «plan día» (Legacy 125; 320: 209; 200: 160; 160: 160) y «Trimestre» (Legacy 125; 320: 225; 200: 167; 160: 131). Antes de regenerar, solo fallaba esa prueba y su diff solo añadía: `a4-evidencia/18-correcciones-documento-antes-de-regenerar.txt` |
| **Verde, SQLite**: ajustador, A4, `UltronDecidePromptsTest`, `UltronCommitTest` y `UltronCommitPromptsTest` | **OK (347 pruebas, 4 283 aserciones)** [C]. `a4-evidencia/22-correcciones-verde-sqlite.txt` |
| **Marketing completo, SQLite** (`tests/Feature/Marketing tests/Unit/Marketing`) | **OK (6 160 pruebas, 36 481 aserciones)** [C]. `a4-evidencia/23-correcciones-marketing-sqlite.txt` |
| **PostgreSQL 14**: A4 (`UNIT_DB=ultron_studio_lead_ola5 … pgunit.sh`) | Primera pasada: 100 pruebas, **1 roja, de la prueba**: un `assertSame` mío sobre `metadata.length`, y `jsonb` no conserva el orden de las claves. Cambiado a `assertEquals`, como las de al lado (`a4-evidencia/24-correcciones-postgresql-a4-primera.txt`). Segunda: **OK (100 pruebas, 1 170 aserciones)** [C] (`a4-evidencia/25-correcciones-postgresql-a4.txt`). |
| `pint` sobre los 4 ficheros tocados | `passed`, sin cambios [C] |

Las salidas están en `a4-evidencia/`, de la 17 a la 29. Cada una lleva una cabecera con qué es y el comando exacto. Las de la regla de la cifra (26 a 29) están en §13.8. Son salidas de PHPUnit con datos de prueba: borradores inventados, el catálogo de planes y la ficha del gimnasio. Las revisé buscando teléfonos de 10 cifras, correos, `Bearer`, `secret`, `token`, `password`, `wamid` y cadenas de conexión, y no hay ninguna coincidencia [C]. Como en las 01-16, llevan la ruta local del árbol.

### 13.6 Lo que conviene que sepas (consecuencias de F1 tal como está escrita)

1. **El literal de S-1 no llega al recorte por el endpoint.** La autoridad de promesas lo tumba antes («te reservo» → `unsupported_future_action_promise`, 422) [C]. Por eso el literal está en la prueba pura, y la prueba por el endpoint usa la misma forma (afirmación + «Ojo: …» con la condición) con una condición de horario.
2. **LÍMITE CONOCIDO (no se arregla ahora, decisión del lead):** con tope, un borrador con los enlaces de la app que no cabe ni sin su pregunta se descarta, y los enlaces no salen en ese turno (`skipped/reply_replaced`). Pasa, por ejemplo, con «Pásame la app» a 160 y el borrador de 164.
   - La herramienta queda `skipped/reply_replaced`, que es lo honesto: la memoria no los da por enviados [C].
   - Antes se soltaba la frase del medio y los enlaces salían.
   - Es la regla decidida; lo señalo porque es el camino de la app.
3. **RESUELTO (§13.8). La pregunta final se soltaba aunque llevara el único precio del borrador.** Al quitar la protección por precio (F1: «si ya no se usa»), un «…¿Te animas con el Élite por $180.000 COP?» perdía la cifra al recortarse.
   - `violations()` no exige un precio en una respuesta de precios [L], y 13.quater solo lo repone cuando hay duda de precio.
   - El lead lo cerró: una pregunta final con cifra no se suelta (§13.8). Por el endpoint se midió el caso: antes salía «El Plan Mensual incluye…» sin ninguna cifra [C].
4. **13.quater con un cuerpo que no se puede ajustar** deja la frase del precio sola: «El precio actual del Plan Mensual es $80.000 COP.». Contesta a la duda, pero se pierde el resto del cuerpo. Es lo que dice F1.
5. **F2 solo actúa con el descarte por longitud.** Si la cortesía se ejecutó y el borrador cae por otra violación (también en Legacy), la rama de la política sigue como antes. Es anterior a este cambio y no lo toqué.
6. **F6 también saca los rechazados de «También hay…».** Si la persona rechazó el único plan de un día, no se le dice «también hay planes por día». Decisión mía, por coherencia con «no se nombran». **El lead la acepta: se queda.**
7. **F4 es conservador.** «…a las 10. Cerramos…» ya no se parte: queda una frase más larga, nunca media frase.

### 13.7 Lo que no pude comprobar

- PostgreSQL solo sobre el fichero A4, como pedía el encargo. `UltronCommitTest` y `UltronCommitPromptsTest` no los pasé en PostgreSQL: allí ya fallaban dos de staff review (§8.1).
- Las suites del Studio y las guardas no las repetí, como indicaba el encargo. **No hay ficheros nuevos en `app/`**, así que la puerta de diff lista los mismos.
- Nada contra n8n, producción ni la canaria.
- **Contrato:** `UltronReplyFitter::append()` cambia su retorno a `{text, dropped}|null`. Es una clase pura y solo la usa el commit (`grep`).
- **Respuesta a n8n:** `price_source` va null cuando sale el respaldo de precios por la rama de la política (solo con la versión aplicada). n8n no lee esa clave (`grep` en `n8n/`) [C].

Quedan fuera, en la lista de pendientes: S-2, S-3, m-2, m-6, m-9 y el tono de los respaldos.

### 13.8 Último ajuste del lead: una pregunta final con cifra no se suelta

**Decisión del lead.** Una pregunta final que lleve una cifra (cualquier dígito: un precio, una hora, una fecha) no se suelta, y el borrador se descarta (`policy_length_exceeded`): sale el respaldo de su intención. Motivo: «¿Te interesa el Plan Mensual por $80.000 COP?» o «¿Te queda bien mañana a las 6 p. m.?» no solo devuelven la pelota, llevan el dato. Cierra el punto 3 de §13.6.

**Cambio.**
- `UltronReplyFitter.php`:
  - `:83-84`: `CIFRA = '/\d/u'`.
  - `:263-317`: `draft()` devuelve null si la última frase (sin contar el bloque de enlaces) lleva un dígito. El docblock dice por qué y el precio de la regla: una pregunta inocente con un número («¿Ya entrenas 3 veces por semana?») también hace descartar.
  - `:319-348`: `append()` con prioridad la hereda, porque ajusta el cuerpo con `draft()`.
- Los comentarios de la cabecera del ajustador y del recorte en el commit (`UltronCommitService.php:1569-1582`) dicen lo mismo.

**Pruebas (rojo → verde).**
- `UltronReplyFitterTest::test_una_pregunta_final_con_una_cifra_no_se_suelta`, pura:
  - pregunta con precio → null (sin ella, 90 caracteres, cabría a 120);
  - pregunta con hora → null;
  - pregunta sin cifras → se suelta como siempre;
  - cuerpo de 13.quater cuya pregunta lleva «el 1 de diciembre» → sale la frase del precio sola.
- `UltronA4LongitudYPreciosTest::test_la_pregunta_final_con_el_unico_precio_no_se_suelta`, por el endpoint, a 200:
  - borrador de 229 cuya pregunta final lleva el único precio. Sin la regla, salía «El Plan Mensual incluye acceso completo… desde el primer día.» (176), **sin ninguna cifra**, a quien preguntó cuánto cuesta [C].
  - Ahora: `Claro. Planes de un mes: Plan Mensual, $80.000 COP; Plan Valera, $65.000 COP; Élite, $180.000 COP. También hay planes por día, por semana y de varios meses. ¿Sobre cuál amplío la información?`, con `metadata.length` = `{replaced, from 229}` [C].
- Ninguna prueba existente cambia: ningún borrador de las anteriores lleva una cifra en la pregunta final. El documento de textos no cambia (son textos de Laravel).

| Qué | Resultado |
|---|---|
| **Rojo** con el código de §13.1-13.7 (ajustador + A4) | **128 pruebas: 2 fallos**, justo las dos nuevas [C]. `a4-evidencia/26-cifra-rojo-antes.txt` |
| **Verde, SQLite**: ajustador + A4 | **OK (128 pruebas, 1 422 aserciones)** [C]. La prueba del documento y la instantánea de Legacy, incluidas. `a4-evidencia/27-cifra-verde-sqlite.txt` |
| **PostgreSQL 14**: A4 | **OK (101 pruebas, 1 175 aserciones)** [C]. `a4-evidencia/28-cifra-postgresql-a4.txt` |
| **Marketing completo, SQLite**, primera pasada | 6 162 pruebas: **1 error y 1 fallo, los dos tiempos agotados** de procesos node de n8n (`verificar-flujo.cjs` >120 s; el selector `elegir-prompts.js` >20 s), con la máquina cargada (*load* ≈ 8,4). No pasan por el ajustador ni por el commit, y en §13.5 estaban en verde [C]. `a4-evidencia/29-cifra-marketing-sqlite-primera.txt` |
| Esos dos ficheros, solos | **OK (655 pruebas, 4 822 aserciones)** [C]. `a4-evidencia/30-cifra-n8n-repeticion.txt` |
| **Marketing completo, SQLite**, segunda pasada | MARKETING_PENDIENTE |
| `pint --test` sobre los 4 ficheros de código | `passed` [C] |

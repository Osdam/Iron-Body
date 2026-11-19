# Informe del reviewer · Apartado 4 · commit `8e710104` (el tono publicado y el panorama de planes, H4-9 y H4-10)

19-11-2026 · revisión final independiente, una sola pasada · solo lectura sobre el repositorio.
Revisado: `git diff bc5167cb 8e710104 -- backend/app backend/tests` (los 9 ficheros de producto y sus pruebas), con `informe-backend-a4-tono-y-panorama.md`, `plan-arquitecto-a4-resto.md`, `a4-textos-usted.md`, `canario-a4/04-mensajes-y-dictamen.txt` y mi informe anterior (`informe-reviewer-a4.md`, que no repito).
Marcas: **[C]** comprobado ejecutando · **[L]** leído en el código · **[S]** supuesto, sin comprobar.

> **Cómo ejecuté sin pisar a nadie.** Nada de PHPUnit en el árbol compartido (allí otro agente cambia el compilador; no es parte de esta revisión). Tres copias en el scratchpad con `git archive 8e710104 backend n8n`, `vendor/` clonado y un `.env` con una `APP_KEY` de prueba generada al momento (no la real):
> - `new` = el commit, más una clase de prueba mía (`RevisorA4bTest`, solo en el scratchpad) con los escenarios por el endpoint;
> - `old` = el commit con los 7 ficheros de producto de `bc5167cb` (y, para medir el rojo, con las dos clases puras nuevas añadidas);
> - `mut` = el commit limpio, para las mutaciones y la batería completa.
>
> SQLite en memoria, como `phpunit.xml`. No ejecuté PostgreSQL ni las guardas del H9.

---

## Veredicto

**RECHAZADO** (equivale a REQUIERE CAMBIOS): dos bloqueantes, los dos en el camino normal y los dos comprobados por el endpoint.

El mecanismo se sostiene:
- `sp.t` firmado, el detector puro y la tabla de usted;
- Legacy no ejecuta nada nuevo: las dos instantáneas pasan con el código anterior **[C]**;
- el detector no dio ni un falso positivo con 20 borradores de usted realistas, y caza 39 de las 40 citas de tú del prompt base **[C]**;
- las seis mutaciones que pediste mueren **[C]**.

Pero:
- **B-1.** El reconocimiento del panorama se dispara con entradas que no piden la lista: «¿Qué opciones de pago tienen?», «No me interesan los planes, gracias», «Quiero hablar con un asesor sobre los planes», «¿Cuál de los planes me recomienda para bajar de peso?». La lista de precios **sustituye una respuesta correcta**, con cualquier tono del Studio.
- **B-2.** Con «profesional», quitar la pregunta final que tutea deja respuestas vacías («Claro.», «Sí, claro.», «¡Hola, buenas tardes!»). Es el mismo defecto que el B-1 de mi revisión anterior, ahora por el camino del registro, que es mucho más frecuente.

---

## Verificado (lo que ejecuté y su resultado real)

| Qué | Dónde | Resultado |
|---|---|---|
| Las dirigidas del informe (ajustador, detector, detectores de usted, panorama, guarda del catálogo, los dos A4, `UltronDecidePromptsTest`, `UltronCommitPromptsTest`, `UltronCommitTest`) | `new` | **OK (524 pruebas, 5 359 aserciones)** [C], igual que la salida 53 |
| Las dos instantáneas de Legacy (`legacy-a4.json`, `legacy-usted.json`) **contra el código anterior** | `old` | **OK (4 pruebas, 218 aserciones)** [C]: no son circulares |
| `UltronA4RegistroYPanoramaTest` contra el código anterior **con las dos clases puras disponibles** | `old` | **41 pruebas: 30 rojas** (6 errores, 24 fallos) [C]. Rojas todas las de comportamiento; verdes las 11 que fijan lo que no debe cambiar (cercano/entusiasta, token sin `t`, `t` fuera de la respuesta, Legacy ×2, plan nombrado, sin studio, V-2 a/c/e). El rojo es real |
| Marketing completo (`tests/Feature/Marketing tests/Unit/Marketing`), con `n8n/` del commit | `mut` | **OK (6 337 pruebas, 37 557 aserciones)** [C], igual que la salida 56 |
| 30 mutaciones (6 pedidas + 24 mías) | `mut` | 21 mueren, 9 sobreviven (tabla abajo) [C] |
| Guion del detector: 24 borradores de usted y 30 de tú | `new` | 0 falsos positivos de usted (2 marcas legítimas: un eslogan que tutea y un nombre propio con «Tu»); 11 falsos negativos de tú (punto 3) [C] |
| Guion del detector contra las citas de tú de `composer.txt` | `new` | 40 citas con tuteo aparente; se escapa 1 («cuando llegas») [C] |
| Guion del panorama: 43 entradas | `new` | punto 6 [C] |
| Escenarios propios por el endpoint (`RevisorA4bTest`, scratchpad) | `new` | R1-R8, abajo [C] |
| Guarda del catálogo con literales metidos en código, prompt y `flujo-s1.json` | `mut` | roja por el código y el prompt; **no ve** el JSON (punto 7) [C] |
| `pint`, PostgreSQL 14, guardas del H9 | — | **no ejecutados** |

### Mutaciones (`mut`; ajustador, detector, detectores de usted, panorama y los dos A4: 300 pruebas)

| # | Mutación | Resultado |
|---|---|---|
| M1 | Sin comprobación de registro | **roja** (7) |
| M2 | Registro con cualquier tono del Studio (también cercano) | **roja** (11) |
| M3 | Últimos recursos sin la variante de usted | **roja** (4) |
| M4 | Sin panorama | **roja** (3) |
| M5 | «Planes de un mes» aunque quede un plan de un mes sin nombrar | **roja** (22) |
| M6 | `offerKindOf` sin el usted | **roja** (2) |
| M7 | El registro nunca recorta, siempre descarta | **roja** (1) |
| **M8** | **Panorama solo con «profesional»** (en vez de con `studio`) | **SOBREVIVE** |
| M9 | Revisor caído sin panorama | **roja** (2) |
| M10 | `respaldoSemantico` vuelve a exigir `pricing_question` | **roja** (2) |
| M11 | Sin la última red de `antesDeEnviar()` | **roja** (1) |
| M12 | El acta sin `policy_register_mismatch` | **roja** (1) |
| M13 | La lista no anula el plan del modelo | **roja** (4) |
| M14 | Sin la segunda pasada del encabezado «Algunos» | **roja** (1) |
| **M15** | **Hora de la visita sin usted** (`:2603`) | **SOBREVIVE** |
| **M16** | `conLaFechaDeLaCita` sin usted | **SOBREVIVE** |
| **M17** | `sinCambioDeVisitaQueNoSeHizo` sin usted | **SOBREVIVE** |
| **M18** | `SIN_ENLACE_PERO_SIN_MENTIR` sin usted | **SOBREVIVE** |
| **M19** | `loQueSiTenemos` sin usted | **SOBREVIVE** |
| M20 | La ficha sin apertura y cierre de usted | **roja** (4) |
| M21 | 13.ter sin usted | **roja** (1) |
| **M22** | **`planReply` sin usted** (`:1737`) | **SOBREVIVE** |
| **M23** | **`courtesyReply` sin usted** (`:1763`) | **SOBREVIVE** |
| **M24** | `sinConfirmarUnaCortesia…` sin usted | **SOBREVIVE** |
| **M25** | La constancia de cortesía sin usted | **SOBREVIVE** |
| M26 | La pregunta sugerida sin usted | **roja** (1) |
| M27 | El panorama sin filtros | **roja** (15) |
| M28 | La memoria que vende sin el usted | **roja** (2) |
| M29 | La lista no manda sobre los sustitutos | **roja** (1) |
| M30 | `sp.t` también con la línea base | **roja** (1) |

---

## Los nueve puntos

### 1. Legacy idéntico y los otros tonos → Legacy SÍ [C][L]; «cercano ni un byte», NO [C]

**Legacy (sin `sp` o `m ≠ studio`).** Recorrí cada punto tocado:
- `turnoDelStudio()` (`UltronCommitService.php:5176`) devuelve `TURNO_LEGACY` con `tono = null`;
- con `tono = null`, todas las llamadas nuevas son la identidad:
  - `UltronFormalReplies::segun/colaSegun(null, …)` devuelven el texto;
  - `violations(…, null)`, `asksDiscovery(…, null)`, `asksDiscoveryToReadyBuyer(…, null)` y `offerKindOf(…, null)` evalúan exactamente las expresiones de antes;
  - `conElRegistro()` no toca las pistas sin `m = studio`;
  - el bloque del registro (`:1628`) y la última red (`:5268`) no se ejecutan;
- el panorama (`:1695`, `:2130`) exige `studio`;
- el resto de cambios del commit solo añaden parámetros con valor por defecto.

**Las dos instantáneas prueban lo que dicen:**
- pasan con el código anterior [C];
- M2 (el registro con cualquier tono del Studio) no las toca, porque Legacy no tiene `studio`; mueren las de cercano y entusiasta.
- Límite: `legacy-a4.json` compara texto, desenlace, saliente, claves de `metadata` (no valores), violaciones y memoria. No recorre la hora de la visita ni `handleRecovery` en Legacy; ahí lo comprobé leyendo: las dos ramas pasan `$turno`/`$delStudio` con `tono = null`.

**Con `cercano` + tope 200 (el caso ya desplegado) SÍ cambian dos cosas [C]**, y con `entusiasta` también:
1. **el panorama**, que cuelga de `studio` y no del tono (`:1695`): con cercano, «No me interesan los planes, gracias» recibe la lista (R1);
2. **el encabezado «Algunos planes de un mes»**, que cambia la lista ya desplegada (los 9 renglones de `a4-textos-ajustados.md` y 8 literales de `UltronA4LongitudYPreciosTest`).

Es lo que dice el diseño (P-1: «solo con `studio`»), pero contradice dos cosas:
- tu condición «con cercano/entusiasta NI UN BYTE»;
- el mensaje del commit, «Sin una versión del Studio en «profesional» en el turno no se ejecuta nada nuevo».

Además, **ninguna prueba fija el panorama con un tono distinto de «profesional»**: M8 sobrevive. → **I-1**.

### 2. El fixture cambiado (`publica()` → cercano) → la decisión es razonable, pero se pierde cobertura real [C]

Con cercano, el token no lleva `t` y `UltronA4LongitudYPreciosTest` vuelve a medir exactamente lo de antes (longitud y precios). Eso está bien. Lo que deja de estar cubierto es la configuración **real de la canaria (profesional, 200)** en los caminos que solo existen con los dos a la vez. Ninguna prueba los ejercita:

| Caso «profesional + tope» | ¿Cubierto? |
|---|---|
| Recorte por longitud → descarte por registro (turno 2 real) | **Sí** (`test_turno_2_real…`) |
| Descarte por longitud **y** marcas («si la longitud ya lo descartó, solo se mira»): las dos violaciones juntas | **No** |
| Acta de la visita (F2) **con tope** tras un descarte por registro: el acta de usted ajustada con `curated` | **No**: la prueba del acta va a 320 |
| 13.ter de usted **con tope** (`UltronReplyFitter::append` con la cola de usted) | **No**: la prueba va a 320 |
| 13.quater con «profesional» | Neutro: no cambia nada respecto de cercano |
| El desbordamiento de `antesDeEnviar()` → último recurso de usted | **No** |
| `sinExcusasDePrecio` → último recurso de usted | **No** |
| `planReply` y `courtesyReply` de usted en la rama de la política, con o sin tope | **No** (M22 y M23 sobreviven) |
| La hora de la visita de usted | **No** (M15 sobrevive) |
| Las guardas que pegan texto de Laravel (M16-M19, M24, M25) | **No**. Si una pierde el usted, el registro descarta el borrador entero y sale un respaldo: el resultado sigue siendo de usted, pero peor, y no salta ninguna prueba |

→ **I-5**.

### 3. El detector → sin falsos positivos de usted; falsos negativos acotados [C]

**Usted (24 borradores, 0 falsos positivos)** [C]. Probé, entre otros:
- «Le cuento…», «¿Desea…?», «Su plan…», «Cuénteme…», «Tenga en cuenta… este plan», «teléfono», «turno», «Sí, claro»;
- «Venga cuando quiera», «Anímese», «Dígame…», «Escríbanos…», «Mire…», «Tómese su tiempo»;
- «Usted dijo «quiero algo para mí solo»», «Estas son las opciones», «Un té».

**Las 25 marcas añadidas** («has», «puedas», «vengas», «seas», «quieras», «sientas», «crees», «decides» y los infinitivos con «-te») son formas que **solo** existen de tú. Ninguna coincide con un usted ni con una tercera persona. No metieron ningún falso positivo [C].

**Las dos marcas que salieron en mi corpus son legítimas:**
- un eslogan que tutea («Iron Body Neiva te espera en tu sede»);
- un nombre propio con «Tu» («la app Tu Rutina»). Solo se quitan los nombres de **plan**, no los de clase ni los de la app. Hoy no existe ninguno [S] → m-2.

**Falsos negativos de tú (11 de 30)** [C]:
- «Ven a conocernos», «Anímate», «Pásate… cuando tengas», «Cuando estés listo», «Inscríbete», «Mira», «Llámanos/visítanos», «Aprovecha», «¿Ya entrenaste?», «Acércate», «Cuenta conmigo para lo que necesites»;
- y, del prompt base, «cuando llegas».

La mayoría de los borradores reales llevan además «te/tu», así que se cazan por ahí: de las citas de `composer.txt`, se escapa 1 de 40.
- **Se pueden añadir sin riesgo:** «anímate/animate», «pásate/pasate», «inscríbete/inscribete», «acércate/acercate», «estés», «tengas», «necesites», «llegas», «entrenaste».
- **No conviene añadir** «ven», «mira», «aprovecha», «recuerda» ni «cuenta»: coinciden con la tercera persona («ellos ven», «el plan aprovecha»).

→ m-1.

### 4. El orden en el commit → el tuteo del modelo no se escapa; el panorama sí pisa respuestas buenas; la memoria miente en una rama [C][L]

**Orden real:** longitud `:1596` → registro `:1628` → `violations()` `:1649` → saludo repetido `:1661` → panorama `:1695` → rama de la política `:1710-1855` → 13.ter → 13.quater → `antesDeEnviar()` → envío.

**¿Sale tuteo del modelo con «profesional»? No, salvo los falsos negativos del detector** [L][C]:
- la recuperación por repetición (`:1025`) y el turno de cobro (`:876`) ponen un último recurso de usted **antes** del registro, que vuelve a mirarlo;
- 13.ter añade texto de Laravel de usted (M21 roja) y 13.quater una frase neutra;
- la hora de la visita, `safeFallback` y la rendición (`handleRecovery` → `safeFallback`) nunca envían el borrador del modelo: solo lo guardan como evidencia (`discarded_draft`);
- la rama de la política sin alternativa enviaría el borrador, pero `ultimoRecurso()` solo devuelve null si todas sus variantes son inseguras (no se alcanza);
- borde comprobado: si tras el recorte queda solo un saludo repetido, sale la bienvenida de usted, no un texto vacío [C].

**¿El panorama pisa una buena respuesta? Sí** → **B-1** y **I-3**:
- un plan nombrado a secas, un filtro y `CHOOSE_PLAN` están bien excluidos [C];
- pero no lo están las intenciones sensibles, la negativa, la recomendación, «opciones de pago/horario» ni la cortesía ejecutada.

**¿La memoria dice lo que salió?**
- Lista: sí. `$plan = null` (`:1846`) y los precios anotados son los de la lista [C].
- Acta: sí.
- Oferta de usted anotada: sí (las pruebas (a)).
- **Descarte por registro con otro respaldo (último recurso, horario, ficha): no.** `$plan` conserva el plan del modelo. La memoria lo anota como tratado (`plans_discussed = [4]`) y `price_source` devuelve `{plan_id: 4, price: 80000}` aunque el texto que salió no lo nombra (R5) [C].
  - Ya pasaba con el descarte por longitud [L].
  - El registro lo vuelve frecuente: con el compilador 3, dos de cada tres borradores de la prueba real tutearon. → **I-4**.

### 5. `UltronFormalReplies` → completa para lo que sale hoy; la prueba fija la tabla, no los puntos que la usan [C]

- **Completitud.**
  - Cada clave de `FRASES`/`COLAS` existe tal cual en su productor (aserción de la prueba) [C].
  - Barrí todos los literales que tutean en los 5 productores con el detector, después de `deUsted()`. Los que quedan son:
    - fragmentos de concatenación cubiertos por su frase entera;
    - expresiones regulares;
    - textos que ULTRON no usa (`staffReviewReply`, `pricingReply`, `goodbyeReply`, `escalationReply`, `scrubLinkOffer`…: el commit solo llama a `replyFor`, `lastResorts`, `welcomeReplies` y `paymentLinkMessage`);
    - un mensaje al modelo, no a la persona (`:487`) [C].
  - La prueba de completitud genera el documento con los productores reales y compara byte a byte: si un productor cambia, se pone roja.
- **Ajuste al tope.** Comparé el número de frases ajustadas a 200 y a 160, original contra usted, en las 50 frases enteras. Solo cambia una, a propósito: «Listo, tranquilo. No hay problema.» → «Entendido, no hay problema.», de 3 frases a 2. No se pierde contenido [C]. `a4-textos-usted.md` no tiene ninguna variante que no quepa [C].
- **Sentido y trato.** No encontré cambios de sentido ni mezclas de tú y usted en el documento [L]. Dos notas de texto, para el propietario y no defectos:
  - «¡Hola! 💪 Aquí tiene su link…» lleva el emoji en «profesional» (enlaces apagados);
  - «ir» → «venir» donde habla el gimnasio es correcto.
- **Lo que la prueba NO fija:** que cada punto del commit pase su texto por la tabla. Nueve mutaciones así sobreviven.
  - Tres de ellas salen **después** del detector y enviarían tú con «profesional», con solo un aviso de la última red: la hora de la visita (`:2603`), `planReply` (`:1737`) y `courtesyReply` (`:1763`). → **I-5**.

### 6. El panorama → falsos positivos que sustituyen respuestas correctas (B-1) y falsos negativos frecuentes (I-2) [C]

Guion sobre `pideElPanorama()` (`CommercialTurnPolicy.php:955`) y, en los casos marcados, por el endpoint:

| Entrada | Panorama | Qué sale por el endpoint (profesional, 200, salvo que se indique) |
|---|---|---|
| «¿Qué planes tienen?», «…y cuánto cuestan?» | sí | La lista. Correcto |
| «¿Cuánto cuesta el Mensual?», «la mensualidad», «¿qué incluye el plan?», «¿qué plan me recomiendas?», «¿tienen plan para parejas?» | no | El modelo. Correcto |
| «¿Qué planes tienen para estudiantes / de un mes / con entrenador?» | no | Correcto (filtro) |
| **«¿Qué opciones de pago tienen?»** (`payment_link_request`) | **sí** | **La lista de precios en vez de los medios de pago**, con cercano y con profesional, también con el revisor caído (R1) |
| **«No me interesan los planes, gracias»** (`not_interested`) | **sí** | **La lista de precios** a quien acaba de decir que no, con cercano y con el revisor caído (R1) |
| **«Quiero hablar con un asesor sobre los planes»** (`human_request`) | **sí** | **La lista** en vez de la constancia de la derivación; también con el revisor caído (R1) |
| **«¿Cuál de los planes me recomienda para bajar de peso?»**, «Ya vi los planes, ¿cuál me recomiendas?» | **sí** | **La lista** en vez de la recomendación (R6) |
| **«¿Qué opciones de horario hay?»**, «¿Qué opciones hay para bajar de peso?» | **sí** | **La lista** en vez del horario o del objetivo (R1) |
| «Tengo una lesión de rodilla, ¿qué planes tienen?» (`medical_risk_escalation`) | sí | La lista sin la cautela médica (R1) |
| **«¿Cuál es el horario y cuánto cuestan los planes?»** | sí | Solo la lista: **el horario se pierde** (R1) → I-3 |
| **«¿Cuáles son los precios?», «¿Qué precios manejan?», «Info de precios», «¿Cuánto valen las membresías?», «¿Tienen planes?»** | **no** | **Un solo plan del modelo**: H4-10 sigue abierto con estas formas (R2) → I-2 |
| «Mándame la lista de planes» | no | El modelo |

**¿Puede mentir el encabezado?**
- «Algunos planes de un mes» y «Planes de un mes» son veraces [C]: se cuentan los vendibles de un mes sin los rechazados, y se recalculan tras soltar uno.
- **«Nuestros planes:» sí puede engañar** [C]. Con un plan nombrado que no es de un mes, «¿Y el Trimestre cuánto cuesta? ¿Qué planes tienen?» da «Claro. Nuestros planes: Trimestre (tres meses), $210.000 COP; Plan Valera (un mes), $65.000 COP; Plan Mensual (un mes), $80.000 COP. ¿Sobre cuál amplío la información?».
  - Ya a 200 suelta «También hay…» (a 160, además, un plan).
  - Presenta 2 o 3 de 13 como si fueran todos: es la omisión que el propietario pidió evitar, y «Algunos» solo la cubre en un encabezado. → **I-6**.

### 7. Las pruebas → reales, con rojo previo real; huecos concretos [C]

- **Son reales.** Pasan por `/api/internal/marketing/ai/decide` → `/commit` con las clases reales, el catálogo y la ficha de producción. El token se retoca y se vuelve a firmar con el firmador real. Sin dobles del commit, del detector ni de la tabla.
- **El rojo previo es real:** 30 de 41 con las clases puras disponibles (arriba).
- **Las seis mutaciones que pediste mueren:** M1 a M6.
- **Sobreviven:**
  - **M8**, el panorama solo con «profesional»: no hay prueba del panorama con cercano o entusiasta;
  - **M15-M19 y M22-M25**: los puntos que aplican la tabla (punto 5).
- **Ninguna prueba cubre:**
  - los falsos positivos del panorama (B-1): el corpus de `CommercialTurnPolicyPanoramaTest` solo tiene preguntas por planes;
  - el recorte por registro que deja una sola frase (B-2);
  - la memoria tras un descarte por registro con otro respaldo (I-4).
- **La guarda estática nueva** (`UltronCatalogStaticGuardsTest`) prueba lo que dice para prompts, `.js` y código PHP. Metí «el Plan Élite por 180 mil» en una constante y «valera a 65.000» en `critic.txt`, y salió roja por las dos cifras y por «elite» [C].
  - No caza «valera» sin «plan» (busca el nombre del catálogo, «Plan Valera»).
  - **No mira `flujo-s1.json`** (lo comprobé: un «Plan Mensual 80000» metido en el JSON no la pone roja). Lo que ejecuta n8n es ese JSON, y `verificar-flujo.cjs`, que no es PHPUnit, es quien compara su código con los `.js` [L]. El mensaje del commit («en lo que ejecuta n8n») exagera → m-5.
- **Las seis pruebas «la fuente es el CRM» (V-2)** prueban lo que dicen: cambian la fila y ven el cambio en `decide`, en el marcador y en la lista. Y, como dice el informe, pasan también con el código anterior (V-2 a/c/e verdes en `old`), salvo lo que depende de P-1 y del encabezado [C].

### 8. Calidad [L]

- **`UltronCommitService` pasa de 5 300 a 5 509 líneas.** Lo que conviene sacar ahora:
  - **`respaldoDePrecios()` con su encabezado**, a una clase pura (catálogo + política → texto/esqueleto): el encabezado y la selección se probarían sin endpoint;
  - **los `?string $tono = null` de los métodos privados**, que todos los llamadores pasan. Un llamador nuevo que lo olvide envía tú sin que salte nada (I-5). Sin valor por defecto, PHP obligaría a pasarlo.
- **Comentario huérfano y ya falso:** el docblock F2 «LA VISITA YA ESTÁ REGISTRADA… Solo con tope» (`:1789-1798`).
  - Ahora lo separa de su código el bloque del panorama (`:1799-1809`).
  - Su «Solo con tope» ya no es cierto: el acta sale también sin tope tras un descarte por registro. → m-3.
- **Docblock de `UltronFormalReplies`:** dice «con el mismo número de frases»; una variante fusiona dos (punto 5). Inofensivo → m-4.
- **Código muerto:** no encontré. `cola()` con `FRASES[$texto]` sirve para el S2 (horario sin ficha).

### 9. Lo que queda fuera → dos riesgos para la tercera prueba real que este cambio sí debía cubrir [C][S]

- **Lo que hace fallar la tercera prueba es de este cambio, no de lo que queda fuera:**
  - B-1, si el propietario pregunta por pagos, por un asesor o por una recomendación «de los planes»;
  - B-2, en cuanto el modelo cierre con «¿Te cuento…?» un borrador de dos frases;
  - I-2, si pregunta «¿Cuáles son los precios?».
- **El compilador 4.** Hasta que llegue, el revisor de n8n no falla el tuteo y la mayoría de los borradores de tú llegarán al commit (2 de 3 en la prueba del 18-11).
  - Con este cambio se descartan, y para las intenciones sin respaldo propio (objetivos, miedo de principiante, objeciones con el borrador descartado…) sale el último recurso genérico: «Claro, con gusto le ayudo. Cuénteme, ¿en qué le ayudo hoy?».
  - Lo comprobé con «Quiero bajar de peso» (R5). Es de usted, pero no contesta, y es un fallo de calidad visible en la prueba [C].
  - Sin tope (320), un horario que tutea tampoco recibe el horario, sino ese mismo último recurso (lo fija `test_turno_3_real_sin_tope…`). En la canaria (200) sí sale el horario.
  - **Condición:** no hacer la tercera prueba con este cambio sin el compilador 4. Y valorar que, con «profesional», `respaldoPorIntencion()` use el `replyFor` de usted de la intención (ya existe en la tabla: objetivos, miedo, objeciones) en lugar del último recurso genérico.
- **Beneficios sin guarda determinista:** no es de este cambio. En la prueba real salieron bien. Sin efecto sobre la tercera prueba más allá de lo que ya revisa el revisor [S].
- **«total access» / «plan elite» en `VENDE_EN_*`:** redundantes con `plansNamedIn()`; no cambian ninguna salida [L].
- **`knowledge_base.tone` y la ficha de producción:** si tutean, el modelo tuteará más y habrá más descartes; la ficha sale igual, con solo un aviso. No lo puedo saber sin leer producción [S].

---

## Bloqueantes

1. **B-1 · `CommercialTurnPolicy.php:115-119` (`PIDE_PANORAMA`) y `:955-966` (`pideElPanorama`), usados en `UltronCommitService.php:1695-1700`, `:1806-1809`, `:2130` y `:5075`. El panorama se reconoce en entradas que no piden la lista, y la lista sustituye una respuesta correcta.**

   **Causa:**
   - `(que|cuales)\s+(planes|membresias|opciones)` acepta «opciones» de cualquier cosa (pago, horario, objetivo);
   - `\b(los|todos\s+los)\s+planes\b` acepta «los planes» en cualquier frase (negativa, derivación, recomendación);
   - `pideElPanorama()` no mira la intención;
   - sin precio, el commit exige que el borrador nombre 2 planes, cosa que una respuesta sobre pagos o una derivación nunca hace.
   - Con el revisor caído pasa lo mismo, porque ahora la lista va antes de `RESPALDO_PROPIO` (`:5075`).

   **Escenarios comprobados por el endpoint [C]** (R1, R6), con borradores correctos aprobados por el revisor:

   | Entrante (intención) | Borrador del modelo | Sale |
   |---|---|---|
   | «¿Qué opciones de pago tienen?» (`payment_link_request`), cercano 200 y profesional 200 | «Puede pagar desde la app Iron Body Workout con Nequi, PSE, tarjeta o Daviplata, o directamente en el gimnasio. ¿Le paso la app?» | «Claro. Algunos planes de un mes: Plan Valera, $65.000 COP; …» |
   | «No me interesan los planes, gracias» (`not_interested`), cercano 200 | «Entendido, no hay problema…» | la lista de precios, y la memoria anota 3 precios entregados |
   | «Quiero hablar con un asesor sobre los planes» (`human_request`) | «Claro, dejo marcada su solicitud…» | la lista |
   | «¿Cuál de los planes me recomienda para bajar de peso?» (`goal_fat_loss`) | «…le recomiendo el Élite, porque…» | la lista |
   | «¿Qué opciones de horario hay?» (`schedule_question`) | el horario | la lista |
   | Las tres primeras con el revisor caído | — | la lista |

   **Corrección sugerida** (la regla exacta, del lead):
   - que el panorama exija una intención que la lista contesta (`pricing_question`, `general_info`, `unknown`…) y excluya las sensibles, `not_interested`, `human_request`, `payment_link_request`, `schedule_question`, `location_question`, los objetivos y las objeciones;
   - «opciones» solo con «de planes/membresías»;
   - excluir la petición de recomendación (`PIDE_RECOMENDACION` en singular, «cuál… me recomienda») y la negativa («no me interesa», «ya vi»);
   - mover el corpus de arriba a `CommercialTurnPolicyPanoramaTest` y tres casos por el endpoint (pago, negativa, recomendación), rojos hoy.

   → asignado a **backend**.

2. **B-2 · `UltronCommitService.php:1628-1647` con `UltronReplyFitter::withoutFinalQuestion()` (`UltronReplyFitter.php:313-331`). Con «profesional», quitar la pregunta final que tutea deja respuestas que no contestan.**

   **Cómo falla.** La regla F1 quita la última frase si es pregunta y no es la primera, pero no exige que quede algo más que la primera. El borrador típico del redactor es «apertura + pregunta con te/tu», y entonces sale solo la apertura, con `register.action = trimmed` y sin ninguna violación.
   - Por longitud esto casi no ocurría: un borrador corto no se recorta.
   - Por registro es el caso más común.

   **Escenarios comprobados por el endpoint [C]** (R3), profesional 200, revisor aprobando:

   | Entrante | Borrador | Sale |
   |---|---|---|
   | «¿Tienen clases de spinning?» | «Claro. ¿Quieres que te cuente qué clases tenemos esta semana?» | **«Claro.»** |
   | «¿Puedo pagar con tarjeta?» | «Sí, claro. ¿Quieres que te pase la app para pagar desde ahí?» | **«Sí, claro.»** |
   | «Hola» | «¡Hola, buenas tardes! ¿En qué te puedo ayudar hoy?» | **«¡Hola, buenas tardes!»** (la conversación se queda parada) |
   | «Nunca he entrenado y me da pena» | «Es muy normal al inicio. ¿Qué es lo que más te preocupa?» | «Es muy normal al inicio.» |
   | «Hola, quisiera información» | «¡Hola! Con gusto. ¿Qué te gustaría saber?» | la ficha de usted, porque `policy_answer_without_fact` la rescata. Solo se salva cuando hay una regla que mira hechos |

   Es la misma clase de defecto que el B-1 de mi revisión anterior («Claro.» como respuesta entera), que fue bloqueante.

   **Corrección sugerida:**
   - en el registro, `trimmed` solo si sin la pregunta quedan **al menos dos frases** (o si la que queda no es solo la primera);
   - si no, `discarded` → sale el respaldo de usted de la intención: la bienvenida de usted en un saludo, que es mejor que «¡Hola, buenas tardes!»;
   - los cinco borradores, como pruebas por el endpoint, rojas hoy.

   No toca la regla F1 de la longitud.

   → asignado a **backend**.

## Importantes (antes de abrir más alcance que la canaria)

1. **I-1 · `UltronCommitService.php:1695`, `:2130` y `:5408`. Con `cercano`/`entusiasta` sí cambia el comportamiento ya desplegado, y no lo fija ninguna prueba.**
   - Con cualquier versión del Studio cambian el panorama (y su lista) y el encabezado «Algunos…».
   - El mensaje del commit y tu condición dicen lo contrario.
   - M8 (el panorama solo con «profesional») sobrevive.

   **Decisión del lead:**
   - o el panorama y el encabezado van solo con «profesional»;
   - o se acepta que van con `studio`, se corrige la afirmación y se añade una prueba con cercano.

   → **lead**, y **backend** para la prueba.

2. **I-2 · `CommercialTurnPolicy.php:115`. H4-10 sigue abierto con formas corrientes de pedir el panorama.**
   - «¿Cuáles son los precios?», «¿Qué precios manejan?», «Info de precios», «¿Cuánto valen las membresías?» y «¿Tienen planes?» no son panorama.
   - Sale el plan único del modelo con su precio (R2) [C].

   **Corrección:** «precios/tarifas/valores» en plural, «las membresías» y «tienen planes» como panorama, con sus pruebas. Hay que cuidar que «el precio del Mensual» siga sin serlo.

   → **backend**.

3. **I-3 · `UltronCommitService.php:1806-1829`. El panorama se come la otra mitad de la pregunta y la constancia de la visita.**
   - «¿Cuál es el horario y cuánto cuestan los planes?» → solo la lista; el horario del borrador se pierde [C].
   - «quiero ir el sábado a las 10, ¿y qué planes tienen?» con la cortesía **ejecutada** y un borrador que la confirma y nombra un plan → sale solo la lista. La persona no lee que su visita quedó registrada (R7) [C].
     - El acta solo gana con `policy_length_exceeded` o `policy_register_mismatch`, no con `policy_plans_overview`.
     - Es el I-2 de mi revisión anterior, reabierto por otro camino.

   **Corrección:**
   - con una cita ejecutada en el turno, el acta va delante (o en vez) de la lista;
   - con otra pregunta en el mismo mensaje, o se conserva el borrador si nombra dos planes, o la lista no lo sustituye.

   → **backend**.

4. **I-4 · `UltronCommitService.php:1710-1855` y `:1988`. Tras un descarte por registro con un respaldo que no es la lista, la memoria y `price_source` citan el plan del modelo.**
   - Escenario [C] (R5): «Quiero bajar de peso», borrador «…te recomiendo el Plan Mensual, que cuesta {{PLAN_PRICE}}…» (plan 4).
   - Sale «Claro, con gusto le ayudo. Cuénteme, ¿en qué le ayudo hoy?».
   - Pero `plans_discussed = [4]` y `price_source = {plan_id: 4, price: 80000}`.
   - El turno siguiente cree que se habló del Mensual.
   - Ya pasaba con el descarte por longitud; el registro lo multiplica.

   **Corrección:** `$plan = null` siempre que `$alternativa` sustituya el borrador y no nombre ese plan; prueba por el endpoint.

   → **backend**.

5. **I-5 · Huecos de prueba de «profesional» (puntos 2, 5 y 7).** Nueve mutaciones que quitan el usted de un punto del commit sobreviven (M15-M19, M22-M25).
   - Tres salen después del detector y enviarían tú con solo un aviso:
     - la hora de la visita (`:2603`);
     - `planReply` (`:1737`);
     - `courtesyReply` (`:1763`).
   - Faltan, además, los casos «profesional + tope» del punto 2 (dos violaciones a la vez, el acta con tope, 13.ter con tope, el desbordamiento).

   **Corrección:** una prueba por el endpoint por punto, con el texto exacto de usted.

   → **tester** (con **backend**).

6. **I-6 · `UltronCommitService.php:5408-5409`. «Nuestros planes:» presenta una lista parcial como si fuera completa.**
   - Ocurre con un plan nombrado que no es de un mes, en cuanto se suelta «También hay…». En el Trimestre ya pasa a 200 (R8) [C].
   - Es la omisión que el propietario pidió evitar, y «Algunos» solo la corrige en un encabezado.

   **Corrección:** «Claro. Algunos de nuestros planes: », o equivalente que apruebe el propietario, cuando queden vendibles sin nombrar.

   → **backend** + aprobación del **propietario**.

## Menores (a pendientes)

1. **m-1 · `UltronRegisterDetector.php` (`MARCAS`).** Falsos negativos de tú sin «te/tu» (punto 3).
   - Añadir «anímate/animate», «pásate/pasate», «inscríbete/inscribete», «acércate/acercate», «estés», «tengas», «necesites», «llegas» y «entrenaste», cada una con su caso.
   - No añadir «ven», «mira», «aprovecha», «recuerda» ni «cuenta».
2. **m-2 · `UltronRegisterDetector.php`.**
   - Solo quita los nombres de **plan**: el nombre de una clase o de la app con «Tu» daría un falso positivo (hoy no existe ninguno [S]).
   - Y quita todo lo entrecomillado: las frases de la casa entre comillas («te acompañamos», `composer.txt:42`) pasarían.
3. **m-3 · `UltronCommitService.php:1789-1798`.** El docblock F2 está separado de su código y su «Solo con tope» ya no es cierto.
4. **m-4 · `UltronFormalReplies.php` (docblock).** «El mismo número de frases» tiene una excepción (`not_interested`).
5. **m-5 · Mensaje del commit e informe §11.3.** La guarda del catálogo no mira `flujo-s1.json` ni `operaciones-mcp.json`, que es lo que importa n8n. O se amplía al JSON, o se dice «los `.js` fuente».
6. **m-6 · `ConversationMemoryService.php:163` y `:198`.** Otros dos detectores de memoria que solo conocen el tú:
   - la recomendación (`te recomiendo`), así que «le recomiendo» no anota `last_recommendation`;
   - «cómo empezar» (§12.6 del informe).
   
   Misma pauta que `offerKindOf` [L].
7. **m-7 · `UltronCommitService.php:4992`.** Con «profesional» y sin tope, un horario que tutea recibe el último recurso genérico y no el horario de usted. Lo fija `test_turno_3_real_sin_tope…`. En la canaria (200) no pasa.
8. **m-8 · Tamaño y firmas (punto 8).** Sacar `respaldoDePrecios()` a una clase pura y quitar los valores por defecto `?string $tono = null` de los métodos privados.

---

## Notas

**Revisado y correcto:**
- `sp.t`: solo en `studio`, solo distinto de la línea base, nunca en la respuesta a n8n; un tono desconocido se ignora con aviso y sin el valor;
- `turnoDelStudio()`, refactorizado sin cambio para `n`;
- `withoutFinalQuestion()`, extraída de `draft()` sin cambio de comportamiento (las pruebas del ajustador, en verde);
- la tabla `strtr`: la clave más larga gana, y los datos de en medio no se tocan;
- el registro no reescribe al modelo: lo acepta, le quita la pregunta o lo descarta;
- `metadata.register` sin texto de la persona;
- la última red anota y envía, sin dejar un turno mudo;
- las preguntas de descubrimiento de usted, solo dentro de una pregunta;
- las ofertas de usted, solo con «profesional»;
- `conElRegistro()`, sin otra lectura de la cadena (`UltronPromptOffer::MODE_STUDIO` es un alias de `UltronPromptStamp::MODE_STUDIO`);
- el encabezado «Algunos…/Planes de un mes», veraz en los casos de un mes, sin contar los rechazados;
- la memoria en la rama de la lista y del acta;
- la aserción cambiada de `UltronDecidePromptsTest` (sigue siendo `assertSame` exacto);
- `a4-textos-usted.md`, generado y fijado por la prueba.

**No verificado:**
- PostgreSQL 14, las guardas del H9 y `pint`;
- n8n, la canaria y producción;
- el texto real de `knowledge_base.tone` y de la ficha [S];
- el PCRE 10.39 de producción: las clases nuevas usan clases explícitas y no `\p{…}` [L];
- la seguridad (la revisa `security`).

**Escenarios del revisor (efímeros, solo en el scratchpad):**
- R1: falsos positivos del panorama, incluidos los tres con el revisor caído;
- R2: falsos negativos del panorama;
- R3: el recorte por registro;
- R4: el saludo repetido tras el recorte (correcto);
- R5: la memoria tras un descarte por registro;
- R6: la recomendación «de los planes»;
- R7: el panorama con la cortesía ejecutada;
- R8: el encabezado mixto a 200 y 160.

Guiones: `detector.php`, `panorama.php`, `literales.php`, `ajuste.php`, `base.php` y `mutar.sh`.

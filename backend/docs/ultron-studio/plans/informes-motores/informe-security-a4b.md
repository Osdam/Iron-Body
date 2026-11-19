# Informe de seguridad — apartado 4 del cierre V2, segunda parte (commit `8e710104`)

Agente: `security`. Fecha: 2026-11-18. Una sola pasada.
Alcance: `git diff bc5167cb 8e710104 -- backend/app` (nueve ficheros de producto en
`app/Services/Marketing/Ultron/`). Los números de línea son los del árbol en `8e710104`.
No repito S-1, S-2 ni S-3 de `informe-security-a4.md`: siguen abiertos y conocidos.

Origen de cada afirmación:
- **[E]**: comprobado ejecutando. Siempre sobre el commit exportado al scratchpad (`git archive 8e710104 backend`), con `vendor/composer` copiado para que las clases se carguen del commit y no del árbol compartido. Hay dos tipos:
  - guiones PHP sueltos contra las clases puras;
  - pruebas de sondeo añadidas solo a la copia del scratchpad de `UltronA4RegistroYPanoramaTest`, por el endpoint `decide` → `commit`, con SQLite en memoria.
- **[L]**: leído en el código.
- **[S]**: supuesto, sin verificar.

En el repositorio no he ejecutado nada ni he escrito otra cosa que este fichero.

## Veredicto

**APTO CON CONDICIONES** para desplegar en la conversación canaria.

- **Ningún hallazgo bloquea la canaria.** El cambio no abre superficie a un atacante:
  - no hay endpoints nuevos;
  - `sp.t` va firmado, se valida contra la lista de tonos y uno desconocido se ignora **[E]**;
  - las expresiones son lineales al tamaño real de entrada **[E]**;
  - los registros no llevan texto ni datos personales;
  - Legacy no ejecuta ninguna rama nueva: las dos instantáneas siguen en verde **[E]**;
  - el opt-out duro sigue intacto **[E]**.
- **Hay un hallazgo funcional serio, S-4.** La lista de planes sustituye respuestas que no debe sustituir: una queja, un pago reclamado, un relevo humano autorizado, una negativa o una cortesía recién registrada **[E]**.
  - No expone datos ni salta el opt-out, y hoy solo lo vería el propietario. Por eso no lo marco como bloqueante.
  - **Recomiendo corregirlo antes de la prueba real del propietario.** El arreglo es pequeño, y con él sin corregir esa prueba puede dar respuestas absurdas.
  - **Es condición obligatoria antes de abrir más alcance.**
- **Condiciones:**
  1. S-4 corregido, o el propietario avisado, antes de su prueba real. Corregido sin excusa antes de abrir más alcance que la canaria.
  2. No encender los enlaces de pago automáticos con una versión en «profesional» sin resolver S-5.
  3. S-1, S-2 y S-3, como ya estaba: antes de abrir más alcance.

## Hallazgos

### S-4 · IMPORTANTE (el primero que hay que arreglar): el panorama no mira la intención ni lo que el turno ya ejecutó, y su lista pisa respuestas sensibles

**Dónde:**
- `CommercialTurnPolicy.php:955-963` (`pideElPanorama`). Solo excluye estos casos:
  - `choose_plan`;
  - recepción, memoria y `goal_takes_turn`;
  - los filtros.
- `PIDE_PANORAMA` (`:115`): `\b(los|todos\s+los)\s+planes\b` casa con **cualquier** mención de «los planes».
- `UltronCommitService.php:1695-1701`: con `studio` y un borrador que nombra menos de 2 planes, añade `policy_plans_overview`.
- `:1806-1808`: la lista **manda** sobre cualquier sustituto.
- Camino del revisor caído:
  - `:2130` y `:5227` hacen lo mismo;
  - `respaldoSemantico` devuelve la lista en `:5075`, **antes** de la protección de `RESPALDO_PROPIO` (`:5078`). Esa lista existe precisamente para que las intenciones sensibles no reciban un texto genérico.

**Prueba [E]** (por el endpoint, versión «profesional, 320», revisor aprobando un borrador correcto):

| Entrante | Intención y herramientas propuestas | Qué sale |
|---|---|---|
| «Ya pagué uno de los planes y no me llega nada» | `fraud_or_payment_claim` + `staff_review` | «Claro. Algunos planes de un mes: Plan Valera, $65.000 COP; …» |
| «Quiero poner una queja por los planes, me cobraron de más» | `complaint` + `staff_review` | la misma lista |
| «Ya vi los planes, no me interesan» | `not_interested` | la misma lista |
| «Ya no quiero saber nada, dejen de mandarme los planes» | `not_interested` | la misma lista |
| «Gracias, ya vi los planes» | `thanks` | la misma lista |
| «Quiero hablar con una persona del equipo sobre los planes» | `human_request` + `staff_review`, con relevo **autorizado** (HTTP 200) | la misma lista: el relevo se ejecuta y no se le dice nada |
| «Quiero ir el sábado a las 10 de la mañana. ¿Qué planes tienen?» | `courtesy_request`, **ejecutada** (se crea la cita) | la misma lista: la visita queda registrada en el CRM y no se le dice; la acta que pegó `conLaFechaDeLaCita` (`:1535`) se pierde |

Más resultados:
- Lo mismo con «cercano» **[E]**. El panorama cuelga de `studio`, no del tono.
- Con 12 frases de rechazo, queja o tema distinto que contienen «los planes», `pideElPanorama` da `true` en las 12 **[E]**.

**Qué no pasa [E]:**
- Con `do_not_contact_request`, o con la herramienta `mark_do_not_contact`, sale `no_reply`, no se envía nada y el lead queda en DNC. El opt-out duro está intacto.
- La cita y el `staff_review` sí se ejecutan. El fallo es lo que se le dice a la persona, no la acción.

**Impacto real:**
- Responder con precios a «me cobraron de más» o a «ya pagué y no me llega nada» daña la confianza y puede acabar en queja formal.
- Responder con precios a «dejen de mandarme los planes» es insistir a quien dijo que no.
- Una visita registrada que no se anuncia puede dar duplicados o que la persona no se presente.
- Antes de este cambio, en todos esos turnos salía el borrador aprobado.
- Hoy solo lo vería el propietario en la canaria.

**Corrección** (acotada, de `backend`; no la implemento):
1. `pideElPanorama` y el commit no deben activarse en estos casos:
   - con una intención de `RESPALDO_PROPIO` distinta de `pricing_question`; o, mejor, solo con una lista blanca (`pricing_question`, `general_info`, `unknown` y las de plan);
   - si el turno ejecutó `courtesy_request`, `staff_review` o `mark_do_not_contact`;
   - si autorizó un relevo.
2. En `respaldoSemantico`, la comprobación de `RESPALDO_PROPIO` debe ir **antes** de devolver la lista.
3. Estrechar `\b(los|todos\s+los)\s+planes\b`, o exigir que no haya negación ni rechazo («no me…», «ya vi», «dejen de»). Además, «los planes de pareja» no lo coge el filtro (`para pareja` sí): funcional.
4. Una prueba de regresión por cada fila de la tabla.

### S-5 · MENOR hoy (IMPORTANTE antes de encender los enlaces de pago con «profesional»): un cobro acuñado se anota como entregado aunque el texto que lo llevaba se descarte

**Dónde:**
- `UltronCommitService.php:1463-1475`: el cobro se pega al borrador (`conCheckout`) y la fila lo anota `executed` / `delivery: inlined`.
- `:3437`: `recordPaymentLink` deja en la memoria `link_sent_at` y `status: pending`.
- Después, el registro (`:1627-1679`) o el panorama (`:1806`) sustituyen el texto. `$textoSustituido` (`:1836`) solo corrige los enlaces de la app, no el cobro.

**Qué pasa [L]:**
- Con «profesional», un borrador que tutea y lleva el cobro pegado se descarta. Sale el respaldo sin el enlace, pero la fila y la memoria dicen que se envió.
- En el turno siguiente la conversación puede quedar en `payment_pending` con un enlace que la persona nunca vio.
- El patrón ya existía con el descarte por longitud y otras violaciones. Lo nuevo es que el descarte por registro será frecuente: en la prueba real, 2 de 3 borradores tutearon.

**Por qué no afecta hoy [L]:** los enlaces automáticos están apagados. `mintCheckout` sale con `automatic_links_disabled` si `canOfferLink()` es falso (`:4055-4057`), y las pruebas usan `payment_links_enabled=false`. No lo he ejecutado con los enlaces encendidos **[S]**.

**Corrección:** si se sustituye el texto de un turno con el cobro pegado, hacer una de dos cosas:
- volver a pegar la línea del cobro al sustituto;
- o anotar el cobro como `skipped` (`reply_replaced`) y no llamar a `recordPaymentLink`, igual que con los enlaces de la app.

### S-6 · MENOR: el detector se puede cegar con comillas rectas

**Dónde:** `UltronRegisterDetector.php:80`. Quita todo lo que haya entre dos `"`, sin límite de longitud ni de frases. La última red (`:5268`) usa el mismo detector.

**Prueba [E]:**
- `"Hola, te cuento que tienes acceso y puedes venir cuando quieras. ¿Quieres?"` (el mensaje entero entre comillas) da `[]`.
- `El "Plan Mensual te da acceso libre y tienes clases; el Valera" también.` da `[]`.
- Una comilla sin cerrar no se come nada (no da falso negativo).

**Impacto:** el modelo, o una persona que se lo pida («contéstame entre comillas»), puede sacar un mensaje de tú con «profesional». Solo afecta al trato, en la conversación de quien lo provoca. No toca datos, dinero ni guardas.

**Corrección:** solo quitar citas cortas (por ejemplo ≤ 60 caracteres y sin `.?!` dentro), o no quitar nada si la cita cubre la mayor parte del texto.

### S-7 · MENOR: el verbo de oferta de usted también casa con la tercera persona y puede anotar una oferta de cobro que no existió

**Dónde:** `ConversationMemoryService.php:67` (`quiere|desea|prefiere|le parece`, sin anclar) y `:486-500`.

**Prueba [E]:** con «profesional», estas preguntas dan `send_payment_link`; sin tono, o con «cercano», dan `null`:
- «¿Sabe qué quiere decir el enlace de pago?»
- «¿Sabes qué quiere decir el enlace de pago?»
- «¿El equipo desea revisar su pago por el link?»

**Efecto si después llega un «sí» [L]:**
- `accept_offer` con `send_payment_link`;
- `StrategyContract.php:218` activa la vía rápida de compra;
- `UltronCommitService.php:3410` (`aceptoElCobro`) corrobora el cobro y se acuña un enlace, si los enlaces están encendidos y la intención es de pago.

**Por qué es menor:**
- La oferta solo se lee en el texto que **envió el bot**, nunca en el del cliente (`:212`).
- La clase `send_payment_link` de usted es idéntica a la de tú.
- Un cliente ya puede pedir el enlace directamente («mándame el link», `send_it`). No amplía lo que un atacante puede conseguir.
- Hoy los enlaces están apagados.

**Corrección:** excluir «quiere decir» y exigir que el verbo abra la pregunta o vaya con «que le…», por ejemplo `^¿?\s*(le\s+)?(quiere|desea|prefiere)\b` o `\b(quiere|desea|prefiere)\s+que\s+le\b`.

### S-8 · MENOR: la guarda estática del catálogo es de higiene y se salta con facilidad

**Dónde:** `tests/Unit/Marketing/Ultron/UltronCatalogStaticGuardsTest.php:203-226`.

**Qué caza [E]:**
- el literal en cualquier caja y con o sin tildes;
- «80.000», «80 000», «80k» y «80 mil».

**Qué no caza [E]:**
- `80_000`: el separador numérico de PHP, el que escribiría Pint o una persona;
- la concatenación (`'Plan '.'Mensual'`);
- `sprintf`, `0x13880` y `"\x50lan Mensual"`;
- un nombre con doble espacio o NBSP;
- los precios por debajo de 10.000: el plan interno de $1 solo se caza por su nombre.

**Alcance [L]:**
- No mira el resto de `app/Services/Marketing/`. Ahí está `MarketingKnowledgeBaseService` con `%ensual%`, y es donde viven los textos que comparte Legacy.
- Tampoco mira los JSON de n8n (`flujo-s1.json`, `operaciones-mcp.json`), que llevan el mismo código embebido.

**Las tres excepciones son seguras [L]:**
- `VENDE_EN_MEMORIA` y `VENDE_EN_SALUDO` son vocabulario de un detector que solo puede añadir violaciones; no sale de ahí ningún texto ni precio.
- `NOMBRE_DE_CALENDARIO` impide elegir plan.
- El ejemplo de `composer.txt` es una instrucción de copia.
- El recuento es exacto: un término nuevo en esos ficheros hace fallar la guarda.

**Corrección mínima:** añadir `_` a la clase de separadores (`[.,_\s]?`). Para la concatenación no hay guarda estática razonable: es aceptable.

## Respuestas a las nueve preguntas

### 1. Integridad de `sp.t`

**Mismo tratamiento que `sp.n`.**

- **Firmado [L]:** `UltronPromptOffer.php:212` lo pone solo en `$sp`, dentro de `x` del token. `UltronDecideToken` firma el cuerpo entero con HMAC y lo verifica con `hash_equals` antes de decodificarlo. n8n no lo ve.
- **Validado [E]** (`turnoDelStudio`, `UltronCommitService.php:5181-5206`):
  - `profesional` → `profesional`; `cercano` → `cercano`.
  - Dan `null` con el aviso `ultron.commit.tone_invalid`, que lleva `{conversation_id, type}`: `"Profesional"`, `"SECRETO-desconocido"`, un array, `1`, `null` y `true`. **El valor nunca aparece en el log.**
- **`sp` malformado [E]:**
  - `null`, una cadena, `m=legacy` o `m` como array dan `TURNO_LEGACY`.
  - Ninguna entrada lanza una excepción: no tumba el turno.
- **Solo «profesional» exacto activa algo [E]:** `UltronFormalReplies::aplica()` (`:169`) compara con `===`. «Profesional» y « profesional» no activan nada.
- **En `decide` [E]:** `conElRegistro` lee el `t` de la oferta recién calculada, no de nada que venga del cliente, y exige `m=studio`. Con `m=legacy` y `t=profesional` no cambia la pregunta sugerida.

### 2. El detector

- **ReDoS: no [E].**
  - Hasta 200 000 caracteres adversarios (`«`, `"`, `“`, `{{`, `http://`, «plan », «te ») la parte de regex tarda menos de 10 ms. Lo más lento es tapar los nombres de plan: 150-226 ms a 200 000 caracteres, lineal, y unos 4 ms a 4 000, que es el máximo de `reply_draft` (`UltronController.php:172`).
  - `StrategyContract.php:193` (`[^.!?¿]*\?`) es cuadrática **sin JIT**: 30 s a 200 000 caracteres. Pero a 4 000 tarda 13 ms sin JIT y menos de 1 ms con JIT **[E]**, y solo se aplica al borrador del bot. No es un hallazgo.
- **PCRE 10.39 [L] + [S]:**
  - Las expresiones nuevas usan clases explícitas, `\S`, `\s`, `\b`, `/u` y `.{0,20}`. No usan `\p{…}` ni *lookbehind*.
  - Con `/u`, PHP activa UCP, así que `\b` se comporta igual en 10.39 y en 10.47.
  - No lo he ejecutado en 10.39 (aquí hay 10.47).
- **Texto que no es UTF-8 [E]:**
  - `preg_replace` devuelve `null` y el detector da `[]` en silencio (falso negativo). En la práctica no ocurre: el borrador llega por JSON.
  - Un nombre de plan con bytes inválidos no rompe nada, porque `mb_strtolower` los sustituye.
  - Un `name` que no fuera cadena lanzaría una excepción, pero `activePlans()` lo lee de una columna de texto.
- **Comillas [E]:** con comillas rectas emparejadas se puede tapar medio mensaje, o el mensaje entero: es **S-6**. Una comilla sin cerrar no se come nada. Al revés (marcar de más) no pasa por las comillas.
- **Falsos positivos [E]:** vienen de palabras de la lista cerrada usadas en otro sentido: «has» en inglés, o una clase o un plan llamado «Te…». Efecto: se descarta el borrador y sale un respaldo de usted. Es funcional.
- **Un plan llamado «TE» [E]** tapa todos los «te». Hace falta permiso de edición de planes en el CRM.
- **Contenido de la base en la última red [L]:** solo anota (`:5268-5276`); el texto sale igual.

### 3. Descartar y sustituir

- **Todo pasa el guard, o su esqueleto, igual que antes [L]:**
  - `trimmed` quita una frase de un borrador que ya pasó las guardas. Al ser un subconjunto, no añade coincidencias.
  - `policy_register_mismatch` entra en los respaldos de siempre:
    - `respaldoPorIntencion` los envuelve en `$seguro`;
    - `ultimoRecurso` los inspecciona;
    - el acta se inspecciona en `:1818-1825`;
    - `dePlanes`, `deVisita` y `conHechos` siguen sin guard, como antes: son textos fijos de Laravel.
  - La lista del panorama inspecciona su esqueleto: `:1807` y `:2142` → `inspect`. S-2 sigue igual.
- **Nada se envía fuera de `antesDeEnviar()` [E + L]:**
  - `dispatchWhatsapp(` aparece 4 veces en `bc5167cb` y 4 en `8e710104`. Todos los caminos pasan por `antesDeEnviar` antes de enviar.
  - El mensaje aparte del cobro (`:3189`) sigue fuera, como ya estaba.
- **No sale vacío [L]:** `withoutFinalQuestion` nunca suelta la primera frase, y la lista no puede salir vacía (`respaldoDePrecios` devuelve `null` sin planes).
- **Opt-out [E]:** intacto (ver S-4, «qué no pasa»).
- **Relevo humano y cortesía: anunciados o callados de forma incoherente [E].**
  - Con el panorama, un relevo autorizado y una cortesía recién registrada quedan **sin anunciar**: es **S-4**.
  - Con `policy_register_mismatch` y una cortesía ejecutada, el acta de usted sustituye al borrador (`:1818`), y es coherente. Pero si además se pidió el panorama, gana el acta y la lista se pierde. Es un mal menor.
- **Enlaces de la app [L]:** coherentes. `$textoSustituido` (`:1836`) los marca como `skipped`.
- **Cobro pegado [L]:** incoherente, es **S-5**.

### 4. `UltronFormalReplies`

- **Solo texto de Laravel [L]:** he revisado las 24 llamadas a `segun`/`colaSegun`.
  - Nunca reciben el borrador del modelo, texto del cliente ni la ficha.
  - La ficha solo cambia apertura y cierre (`respuestaConHechos`).
  - El horario y `planReply` solo cambian la cola fija; `cola()` no hace `strtr` sobre el contenido.
- **Datos que van en medio:**
  - el nombre del plan, en `paymentLinkMessage` (enlaces apagados);
  - fechas y horas formateadas por Laravel;
  - la franja;
  - la dirección, que es constante.
- **Ninguna clave contiene una cifra, `$` ni una URL [E],** salvo la frase entera que lleva la dirección constante. **No puede cambiar una cifra, un precio ni un enlace.**
- **Si una clave aparece dentro de un nombre de plan, se reescribe [E].** Un plan llamado «Tu visita está confirmada para » sale como «Su visita…». Es cosmético y necesita permiso de edición de planes.
- **Claves cortas [E]:** las tres de menos de 25 caracteres son «. Te esperamos.», «Y sobre tu visita: » y «¿Te refieres ». No pueden aparecer en una fecha.
- **Ninguna variante de usted tiene marcas del detector [E].**

### 5. Los detectores de usted

- **La oferta se lee solo en lo que el bot envió [L]** (`ConversationMemoryService.php:212`, última pregunta de `$replyFinal`). Un mensaje del cliente no puede anotar una oferta.
- **Efectos al aceptar una oferta [L]:**
  - `send_payment_link`: corrobora el cobro (`UltronCommitService.php:3410`) y activa la vía rápida de compra (`StrategyContract.php:218`);
  - `explain_how_to_start`: vía rápida de inicio;
  - `book_visit`: abre el objetivo de cortesía (`ConversationMemoryService.php:244`) y cuenta como señal de compra. La cita en sí sigue necesitando día y hora, y la autoridad de cortesías.
- **Superficie:**
  - Las clases de usted son las mismas que las de tú, y la de cobro es idéntica.
  - Lo único que amplía son falsos positivos de tercera persona: es **S-7**.
  - `VENDE_EN_MEMORIA_DE_USTED` y `PREGUNTA_DESCUBRIMIENTO_DE_USTED` solo pueden añadir violaciones, es decir, más descartes. No producen efectos.
  - La de descubrimiento de usted solo cuenta dentro de una pregunta **[E]**: «Nuestro equipo tiene experiencia.» da `false`.

### 6. El panorama de planes

- **Qué planes puede nombrar [L]:**
  - Solo los de `activePlans()` (`Plan::sellable()`), cargados con `Plan::query()->whereIn` sobre esos ids. El plan nombrado tiene que estar en `$ids`.
  - Un plan no vendible, archivado o inactivo no puede salir.
  - Las pruebas V-2 y las de rechazados pasan en el scratchpad (8 pruebas) **[E]**.
- **Qué controla el cliente:** si sale la lista (ver S-4) y qué plan vendible va primero, nombrándolo.
- **Rechazados:** fuera de la lista, salvo el que se acaba de nombrar **[L]**.
- **S-3:**
  - «¿Cuánto cuesta INGRESO EMPLEADOS y qué otros planes hay?» es panorama con precio **[E]**. Ahora sale **siempre** la lista con ese plan primero a $1, sin pasar por el revisor **[L]**.
  - El alcance no cambia: hay que nombrarlo, y el camino del modelo ya lo cotizaba.
  - Sigue siendo una decisión pendiente de H4-8 antes de abrir más alcance.

### 7. La guarda estática

Es eficaz contra lo accidental y fácil de saltar a propósito. Sus excepciones son seguras. Detalle en **S-8**.

En el scratchpad no he podido ejecutar la prueba entera, porque `n8n/` no se exportó y `test_mira_donde_tiene_que_mirar` falla por eso. Sí he ejecutado el buscador.

### 8. Registros y metadatos

Nada sensible **[L]**, y **[E]** para `tone_invalid`.

**Registros:**
- `ultron.commit.register_mismatch`: id, acción y **número** de marcas.
- `register_fallback_mismatch`: ids, intención y marcas. Las marcas son siempre palabras de la lista cerrada `MARCAS`, nunca texto libre.
- `tone_invalid`: id y tipo.

**Metadatos:** `metadata.register = {expected, found (≤ 5 de la lista cerrada), action, fallback_mismatch}`.

**Ya existía:** `policy_discarded_draft` (400 caracteres del borrador del bot). Ahora también se llena con los descartes por registro y por panorama, y puede llevar lo que la persona dijo (nombre, fecha). Es el patrón conocido; no es nuevo.

### 9. Legacy

**No hay forma de entrar en una rama nueva.**

- **Instantáneas [E]:** en el scratchpad pasan las dos instantáneas Legacy, sin regenerar:
  - `legacy-a4.json`, 33 turnos × 2 modos;
  - `legacy-usted.json`, × 2.
- **Otros tonos [E]:** pasan las 7 pruebas de cercano, entusiasta, token sin `t` y sin studio.
- **Código [L]:**
  - Todos los parámetros nuevos valen `null` por defecto.
  - `TURNO_LEGACY` lleva `tono => null`.
  - Todo `$turno` sale de `TURNO_LEGACY` o de `turnoDelStudio`, que siempre llevan la clave.
  - Cada rama del tono se activa con `aplica($tono)`, que compara con `=== 'profesional'`.
  - Ningún `??` cae en «profesional».
- **Matiz:** el panorama (P-1) cuelga de `studio`, **no del tono**. Por diseño, también actúa con versiones en «cercano» y «entusiasta», y por eso S-4 las afecta igual **[E]**.

## Qué no se verificó

- **PCRE 10.39:** no ejecutado (aquí hay 10.47).
- **Suites:** no ejecuté PHPUnit en el árbol compartido. En el scratchpad solo ejecuté las pruebas citadas y las sondas.
- **S-5 con los enlaces encendidos** y la activación tras el pago: sin recorrer de punta a punta.
- **La skill `security-review`:** no la usé. Revisa el diff del árbol de trabajo, que incluye los cambios de otro agente en el compilador, ajenos a esta revisión.

**Material en el scratchpad de la sesión:**
- guiones `scripts/{det,redos,disc,disc2,offers,pan,turno,guard}.php`;
- las sondas por el endpoint, en `sondas-security-a4b.diff`.

Ruta: `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body/cae0b5ae-a178-45cc-ad1f-09e137ef7225/scratchpad/`.

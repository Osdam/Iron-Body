# Informe de seguridad — apartado 4 del cierre V2 (commit `daedbbd8`)

Agente: `security`. Fecha: 2026-11-12. Una sola pasada, solo lectura.
Alcance: `git diff 7aa46911 daedbbd8 -- backend/app` (tres ficheros de producto:
`UltronPromptOffer.php`, `UltronReplyFitter.php` (nueva) y `UltronCommitService.php`).
Números de línea del árbol en `daedbbd8`.

Origen de cada afirmación:
- **[E]**: comprobado ejecutando un guion PHP suelto contra `UltronReplyFitter` (sin PHPUnit, sin base de datos).
- **[L]**: comprobado leyendo el código.
- **[S]**: supuesto, sin verificar.

## Veredicto

**APTO CON CONDICIONES** para desplegar en la conversación canaria.

- No hay ningún hallazgo bloqueante. El cambio no abre superficie nueva a un atacante:
  - no añade endpoints;
  - el tope viaja dentro del token firmado con HMAC;
  - las consultas nuevas usan ids enteros;
  - las expresiones regulares son lineales;
  - los registros nuevos no llevan texto de mensajes ni datos personales;
  - Legacy no ejecuta ninguna rama nueva.
- Las condiciones **no** afectan a la canaria. Son para **antes de abrir más alcance**:
  - **S-1**: el recorte puede quitar una frase con negación o condición.
  - **S-3**: el plan interno a $1 sigue a la venta (decisión del propietario, H4-8, anterior a este cambio).

## Hallazgos

### S-1 · IMPORTANTE: el recorte puede soltar la frase que matiza y deja una afirmación sin su condición

- **Dónde:**
  - `UltronReplyFitter.php:253-274` (`draft()`): suelta frases desde el final y solo protege la que lleva URL o precio (`protegida()`, `:422-425`).
  - `UltronCommitService.php:1581-1593`: el recorte, aplicado al borrador aprobado.
  - `UltronReplyFitter.php:284-297` (`append()` con prioridad): recorta el cuerpo con la misma regla para que quepa el precio verificado.
- **Prueba [E]:** con tope 160 y el borrador «Te reservo el cupo para mañana a las 6 p. m. Ojo: todavía no está confirmado hasta que pagues en recepción, porque…», `draft()` devuelve «Te reservo el cupo para mañana a las 6 p. m.» (`dropped: 1`).
- **Por qué no lo caza nadie [L]:**
  - Las guardas de hechos y contenido se ejecutan **antes** del recorte, sobre el borrador entero:
    - pago, `:532`;
    - membresía, `:546`;
    - contenido, `assertSafe` en `:556`;
    - además, el estilo y la novedad.
  - Después del recorte solo corre `CommercialTurnPolicy::violations` (`:1595`) y, en modo memoria, el cotejo de compromisos.
  - El revisor de n8n aprobó el texto entero, no el recortado.
- **Qué NO pasa [L]:** el recorte no puede saltarse esas guardas.
  - `PaymentFactGuard` evalúa frase a frase. Parte por `[.!?\n]+` (`PaymentFactGuard.php:226`), más fino que la frontera del ajustador, que siempre cae tras `[.!?]`. Cada frase de la guarda queda entera dentro o entera fuera.
  - `OutboundContentGuard` y `MembershipFactGuard` buscan presencia (`str_contains`, `preg_match`) en el texto. Un subconjunto de frases no añade coincidencias.
  - Toda frase que sobrevive ya era aceptable por sí sola para las guardas.
  - El riesgo es de **sentido**, no de saltarse un filtro.
- **Impacto real:** el informe del implementador dice que el redactor se pasó del tope en 4 de 5 borradores, así que recortar será lo normal. Las frases que suelen ir al final son justo las que matizan: «no está confirmado», «solo aplica…», «hasta que pagues». Puede salir un compromiso a nombre del gimnasio sin la condición que el revisor aprobó.
- **Por qué no bloquea la canaria:** hoy solo la ve el propietario, en su propia conversación.
- **Corrección propuesta:** decisión de diseño (D-4), así que la pasa `architect`.
  - Proteger también en `protegida()` las frases con marcadores de negación o condición: `no`, `aún no`, `todavía no`, `solo`, `únicamente`, `excepto`, `si `, `hasta que`, `pendiente`, `sin confirmar`, `depende`.
  - O bien: si la frase que se iba a soltar los lleva, **descartar** (`policy_length_exceeded`) en vez de recortar.
  - En los dos casos, una prueba de regresión con el ejemplo de arriba.

### S-2 · MENOR: los nombres del catálogo salen sin pasar por el guard (por diseño; igual que hoy)

- **Dónde:**
  - `UltronCommitService.php:5087` (`$items[$id]`, nombre y precio en crudo);
  - `:2027` (safeFallback inspecciona `esqueleto`);
  - `:4804-4805` (`respaldoPorIntencion` inspecciona `esqueleto` y devuelve `texto`).
- **Qué pasa [L]:**
  - El guard mira un texto que solo se diferencia del que sale en los nombres, las cifras y las duraciones. Encabezado, «También hay…» y pregunta son literales fijos y salen idénticos (`composePricing` con el mismo `layout`).
  - En la práctica, el guard sobre el esqueleto no comprueba nada variable: lo variable es `Plan::name`, que escribe el personal del CRM.
  - Lo mismo pasa hoy con `{{PLAN_NAME}}` en el borrador del modelo y con la frase del precio verificado (13.quater). El patrón no es nuevo; lo que cambia es que ahora se nombran hasta 3 planes que la persona no pidió.
- **Nombre raro [L] + [E]:**
  - Con una URL, sale tal cual y **no cuenta para el tope**: `measure()` quita las URL, `UltronReplyFitter.php:86-89`.
  - «gratis» y los saltos de línea salen tal cual.
  - Con 200 caracteres:
    - con tope, `pricingLayout` devuelve null (`:310-330`), `respaldoDePrecios` devuelve null y se usa el respaldo de antes;
    - sin tope (versión publicada con 320), sale largo.
  - No hay inyección de marcadores: el nombre no se vuelve a resolver.
- **Riesgo real:** bajo. Hace falta permiso de edición de planes en el CRM, y ese permiso ya permite poner el mismo texto en el enlace de pago y en la ficha.
- **Corrección (defensa en profundidad):**
  - antes de enviar, pasar por el texto final las comprobaciones que no dependen del precio: `containsUrl`, `containsCardDataRequest`, `forbiddenSignalIn`, `unsafeSignalIn`;
  - o validar `Plan::name` al guardarlo (sin URL, sin saltos de línea, longitud máxima).

### S-3 · MENOR para este cambio (IMPORTANTE como problema anterior): el plan interno a $1

- **Dónde:**
  - `UltronCommitService.php:5066` (el plan nombrado va primero);
  - `:1626-1628` (`hard_required_plan`);
  - `:1981` y `:5128-5142` (`planNombradoEn`).
- **Qué pasa [L]:**
  - `INGRESO EMPLEADOS` (#16, $1, 360 días) solo entra en el respaldo si la persona lo nombra.
  - «Nombrarlo» es escribir el nombre completo como palabra entera (`CommercialTurnPolicy.php:974-990`, `nombraPalabraCompleta`) o que la referencia de la memoria apunte a él (`planDeLaReferencia`, ids de `activePlans()`).
  - `ReferenceResolver` no tiene ninguna regla de «el más barato» que lo eligiera solo.
  - Sin nombrarlo no sale nunca: no es de un mes. `PROMO X4` (30 días) no sale **solo por el orden** del catálogo (es el cuarto plan de un mes).
- **Comparado con hoy:**
  - **El alcance no cambia.** El disparador es el mismo: el catálogo entero ya llega a `decide` y a la voz, y `hard_required_plan` ya obliga al modelo a nombrar ese plan con `{{PLAN_PRICE}}`.
  - **Se vuelve determinista** en el caso concreto «lo nombra y el revisor tumba los borradores». Antes salía «Cuéntame qué buscas…»; ahora sale «INGRESO EMPLEADOS (…), $1 COP».
- **Problema anterior, fuera de este diff [L] + [S]:** #16 es `sellable` y su precio es mayor que 0.
  - `SalesPaymentGuardrailService::assertCanGeneratePaymentLink` solo comprueba `isSellable` y el precio (`:100-115`).
  - Así que, en principio, se puede generar un **enlace de pago de $1 por 360 días** si el modelo lo propone. No lo he verificado de punta a punta: no he recorrido `PromiseAuthority` ni la activación tras el pago de Wompi.
  - **Corrección:** es la decisión (b) del H4-8 del arquitecto: quitar `sellable` a #16 (y decidir #14), o marcar los planes internos y excluirlos de `activePlans()` y de los enlaces de pago. Va al propietario y a `architect` **antes de abrir más alcance**.

### Observación funcional (no es de seguridad; para `reviewer`)

El respaldo de precios no excluye `forbidden_plan_ids` (los planes que la persona acaba de rechazar) y no pasa por `CommercialTurnPolicy::violations`, a diferencia de `dePlanes` (`:1673-1675`).

- Puede volver a listar un plan rechazado, salvo cuando `VIOLACION_PLAN_EQUIVOCADO` lo sustituye por `dePlanes`.
- Origen: [L].

## Respuestas a las nueve preguntas

### 1. Integridad del tope

Nadie entre `decide` y `commit` puede alterar `sp.n` sin invalidar la firma. **[L]**

- **La firma:**
  - `UltronDecideToken::issue` firma con `hash_hmac('sha256', …, app.key)` el cuerpo entero, `x` incluido (`UltronDecideToken.php:60`, `:143-146`).
  - `verify` compara con `hash_equals` antes de decodificar y devuelve `extra` solo del payload verificado (`:96`, `:131`).
  - El token está atado a la conversación y al mensaje (`c`, `m`) y caduca a los 120 s.
- **Uso en commit:**
  - `commit` rechaza un token inválido (`UltronCommitService.php:353-369`).
  - `turnoDelStudio` lee el `sp` de ese `extra` (`:398`).
  - n8n solo ve el token opaco: `offer()` añade `n` solo a `sp`, nunca a `response` (`UltronPromptOffer.php:197-203`).
- **Validación:**
  - `sp` que no es array: `studio=false`, `tope=null`, es decir, Legacy.
  - Token viejo sin `n` y con `m=studio`: `studio=true`, `tope=null`. El respaldo de precios funciona y no se mide la longitud. Es lo que se diseñó (D-1).
  - `n` fuera de rango o de otro tipo: `cap()` lo rechaza, se registra un aviso con `get_debug_type` (sin el valor) y no se mide (`UltronCommitService.php:4984-4998`).
  - **[E]** `cap()`: `200 → 200`. Dan null: `'200'`, `200.0`, `null`, `[]`, `159`, `481` y `true`.
  - El JSON del token conserva los enteros.
- **`sp.m` falsificado:** imposible sin la `APP_KEY`. Si la clave se filtrara, el riesgo ya es mucho mayor que este campo; el problema sería anterior a este cambio.

### 2. Precios y nombres en texto de máquina

- **Ningún camino nuevo envía un texto sin pasar por el guard. [L]**
  - El texto de respaldo se ajusta **antes** del guard (`:1995-2010`, luego `:2027`).
  - `respaldoPorIntencion` envuelve en `$seguro()` el texto ya ajustado (`:4790-4800`).
  - `ultimoRecurso` inspecciona en las dos ramas del tope (`:4741`, `:4751`).
  - `antesDeEnviar` solo cambia el texto por `ultimoRecurso` (`:5030`).
- **La única diferencia entre lo que mira el guard y lo que sale** son los nombres, cifras y duraciones del catálogo del respaldo de precios. Es por diseño y ya ocurre igual hoy (ver **S-2**).
- **Nombres con contenido raro:** ver S-2.

### 3. Exposición de planes internos

- Un cliente que escriba el nombre completo `INGRESO EMPLEADOS` recibe su precio en el respaldo.
- **El alcance no cambia** (mismo disparador que hoy por el camino del modelo y por la voz).
- **Se vuelve determinista** en el caso «el revisor tumba». Ver **S-3**, que incluye el problema anterior del enlace de pago de $1.

### 4. El recorte del borrador

- **Orden real en `execute()` [L]:**
  1. Guardas sobre el borrador con marcadores: URL y tarjeta, pago (`:532`), membresía (`:546`) y contenido (`:556`).
  2. `sanitize`.
  3. Se ponen los marcadores (`:615`).
  4. Estilo y novedad.
  5. `sinExcusasDePrecio` (`:1554`) y `sinServiciosInventados` (`:1566`).
  6. **Recorte** (`:1581-1593`).
  7. `violations` (`:1595`) y memoria (`:1617`).
  8. Respaldo.
  9. 13.ter y 13.quater (`append`).
  10. `antesDeEnviar` (`:1765`).
  11. Envío.
- Las guardas de pago, membresía y contenido **no** se vuelven a ejecutar después del recorte. No hace falta para saltárselas (subconjunto de frases enteras; ver S-1), pero el recorte **sí puede cambiar el sentido** quitando la frase que matiza (**[E]**, **S-1**).

### 5. Nunca mudo frente a nunca pasado

- **Sin guard: no hay ningún camino nuevo. [L]**
  - `antesDeEnviar` solo cambia el texto por `ultimoRecurso`, que pasa por el guard.
  - Si `ultimoRecurso` devuelve null, sale el mismo texto que ya pasó su camino. Ahí gana «nunca mudo» y la fila lo marca con `overflow: true`.
  - Con tope y ninguna variante que quepa, sale la más corta de las seguras (`:4740-4744`).
- **Vacío: no sale.**
  - En `safeFallback`, `NO_REPLY_AND_HANDOFF` vuelve antes de `antesDeEnviar` (`:2239-2257`).
  - En `execute`, `draft()` nunca suelta la primera frase, y `append()` siempre devuelve como mínimo la frase añadida.
- **Opt-out: intacto.**
  - En `safeFallback` se ejecuta antes del envío (`:2211-2230`).
  - En `execute` las herramientas corren antes del envío.
  - El despachador bloquea `do_not_contact` y sus hermanos (`MarketingMessageDispatcher.php:51-53`, `:90`).
  - Este cambio no toca ninguna de esas piezas.
- **Enlace de pago:** `execPaymentLink` (`:2982-3040`) no está en el diff y no pasa por `antesDeEnviar`. Queda fuera y sin cambios.

### 6. Expresiones regulares

- **Sin riesgo de ReDoS. [E]**
  - Entradas adversarias de unos 200 000 caracteres contra `FRONTERA`, `ABREVIATURA`, `URL` y `PRECIO`, y `schedule()` con un entrante de cliente de ese tamaño: entre 1 y 8 ms, sin `preg_last_error`.
  - Los patrones no tienen cuantificadores anidados. Las miradas hacia atrás y hacia delante son de un solo carácter.
- **Versión de PCRE:** la de producción (10.39) no la puedo ejecutar; aquí hay 10.47. Los patrones solo usan clases explícitas, `\s`, `\S`, `\b`, `\d`, `/u` y `/i`. No usan `\p{…}` (Extended_Pictographic es de 10.40), así que deberían compilar igual. **[L]** + **[S]**: no ejecutado en 10.39.
- **Texto que no es UTF-8 válido:** `measure()` daría 0, porque `preg_replace` devuelve null. Pero el borrador llega por JSON y los textos de Laravel son UTF-8 válido. No tiene efecto práctico.

### 7. Registros y metadatos

Nada sensible. **[L]**

- **Registros nuevos:**
  - `ultron.commit.draft_trimmed_to_cap`: id de conversación, tope, longitud y frases soltadas (`:1588`).
  - `goal_resumption_skipped_cap`: id y tope (`:1734`).
  - `length_cap_invalid`: id y tipo, sin el valor (`:4992`).
  - `length_cap_overflow`: ids, tope, longitud e intención (`:5026`).
- **Metadatos nuevos:** `metadata.length` solo guarda números y una etiqueta (`:5035`).
- Ningún texto de mensaje, dato personal ni secreto.
- **Ya existía:** `policy_discarded_draft` (400 caracteres del borrador del bot). Ahora también se llena en el descarte por longitud.
- **Lectores:** `Admin/MarketingController` no expone `metadata` en crudo (`:162`), y el controlador interno solo arma campos explícitos.

### 8. Legacy

No hay forma de entrar en una rama nueva sin `sp.m === 'studio'`. **[L]**

- El valor por defecto de los cuatro métodos que reciben el turno es `TURNO_LEGACY`.
- Cada rama nueva depende de `$turno['tope'] !== null` o de `$turno['studio']`. Con null:
  - `$alTope`, `respuestaConHechos`, `ultimoRecurso`, 13.ter y 13.quater devuelven lo de siempre;
  - `antesDeEnviar` y `conPreciosEntregados` salen en la primera línea.
- `studio` solo se ofrece a la conversación canaria configurada (`UltronPromptOffer.php`, `offer()`: `canary_conversation_id`).
- El agente Legacy (`SalesAgentOrchestratorService`) no pasa por `UltronCommitService`, y `SalesConversationReplyService` no está en el diff.
- No he ejecutado la instantánea de 33 turnos (regla de este encargo); la cita el implementador.

### 9. Memoria

No hay condición de carrera nueva. **[L]**

- `conPreciosEntregados` corre dentro de `Cache::lock('marketing:conversation:{id}')` (`:199-200`, TTL 60 s), el mismo cerrojo que la ingesta y el job.
- `ConversationMemory::deliverPrice` modifica la memoria en el sitio (`ConversationMemory.php:189-196`). Sobre la memoria que devolvió `recordSentTurn` (leída de `fresh()`), `save()` sobre la instancia vieja solo escribe `memory`, que es el único atributo sucio. No hay mutaciones de `$conversation` sin guardar en el servicio.
- **Lo único:** son dos escrituras seguidas sin transacción. Si el proceso muere entre las dos, se pierde la anotación de precios entregados. Es un efecto funcional (repetición de precios), no de seguridad, y el mismo patrón que el resto de `ConversationMemoryService`.

## Qué no se verificó

- **PCRE 10.39 de producción:** no se ejecutó (solo 10.47 en local).
- **Suites:** no se ejecutaron PHPUnit, ni la instantánea Legacy, ni PostgreSQL (prohibido en este encargo).
- **Enlace de pago de $1 (S-3, problema anterior):** no se recorrió de punta a punta (autoridad de promesas y activación tras el pago).

Guion usado: fuera del repositorio, en el scratchpad de la sesión
(`/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body/41b80b4b-320f-428a-a2e1-96623b6a8d00/scratchpad/fitter_probe.php`).

**Veredicto: APROBADO CON CONDICIONES.** No hay ningún bloqueante. Hay cuatro hallazgos IMPORTANTES; dos de ellos (I-1 e I-2) conviene cerrarlos antes de la tercera prueba real porque son baratos y afectan justo a esa prueba. Los demás hallazgos son MENORES.

# Informe del revisor · Apartado 4 · «Regenerar antes de sustituir» (commit `cd431e20`)

- **Agente:** `reviewer`. **Fecha:** 21-11-2026. **Pasadas:** una sola.
- **Alcance:** `git diff a6e2ccdf cd431e20 -- backend/app backend/routes backend/tests n8n backend/docs/ultron-studio/ops/motores` (commits `2d3d02ba`, `ee8e0df0`, `d63fbd50`, `ab64134a` y `cd431e20`). Los números de línea son los de `cd431e20`.
- **Criterio:** las diez condiciones del propietario (`MATRIZ_CIERRE_V2.md` §5).
- **Base de todo lo ejecutado:** el commit `cd431e20` exportado a mi scratchpad, nunca el árbol de trabajo. La copia se hizo con `git archive cd431e20 backend n8n`, con `vendor/` clonado con `cp -c` (lo comprobé con `ReflectionClass::getFileName()`: las clases se cargan de la copia) y un `.env` con una `APP_KEY` de prueba generada al momento y nunca impresa. Todo corrió en SQLite en memoria y con `nice -n 15`.
- **Lo que no he hecho:** en el repositorio compartido no he ejecutado PHPUnit ni ningún git que escriba, y no he tocado producción ni n8n. Este fichero es lo único que he escrito.
- **Marcas:**
  - **[C]**: comprobado ejecutando;
  - **[L]**: leído en el código o en un documento;
  - **[S]**: supuesto, sin verificar.

---

## 1. Qué ejecuté y qué salió

| # | Qué | Resultado |
|---|---|---|
| V1 | Dirigidas del cambio: `UltronCheckEndpointTest`, `UltronCheckCommitParityTest`, `UltronRegenerationCommitTest`, `UltronA4ListaDelModeloTest`, `UltronOriginReportCommandTest`, `UltronPromptsN8nS2CommandTest`, `UltronPromptsN8nCommandTest`, `InternalSecretAuthoritySurfaceTest`, `UltronDecidePromptsTest`, `CriticContractTest` y `tests/Unit/Marketing/Ultron` entero | **OK: 1 559 pruebas, 34 834 aserciones, 1 omitida** (solo PostgreSQL) **[C]** |
| V2 | Las dos instantáneas de Legacy (`UltronA4LongitudYPreciosTest` y `UltronA4RegistroYPanoramaTest`), `UltronCommitTest`, `UltronCommitPromptsTest`, `UltronCommitStudioStampTest`, `UltronDecideTest`, `UltronPromptsTraceCommandTest` y las 16 guardas (`*Isolation*`, `*ReadOnly*`, `*StaticGuards*`, `SecretExposure*`) | **510 pruebas, 4 rojas, las cuatro por mi copia** **[C]**: la puerta de diff del H9 y `test_git_never_tracks…` necesitan git, que la copia no tiene, y las otras dos eran mi `.env` de prueba legible por todos. Con el `.env` en 600, `SecretExposureTest` queda con una sola roja, la de git |
| V3 | Comprobadores offline sobre la copia: `verificar-flujo.cjs` (S1) y `verificar-flujo.cjs --rev=S2` | **S1: OK, 121 comprobaciones. S2: OK, 226 comprobaciones, ningún ✗** **[C]** |
| V4 | `php artisan ultron:prompts-n8n` (S1) y `--rev=S2`, a un directorio aparte, comparados con `diff -r` con el repositorio | **S1 y S2, idénticos** **[C]**. Frente a `a6e2ccdf`, en `n8n/flujos/ultron-studio-s1/` solo cambian los dos fixtures `decide-studio*.json` (la huella del compilador 4) |
| V5 | **El rojo previo:** las pruebas nuevas del commit contra `app/` y `routes/` de `a6e2ccdf` | **Es real** **[C]**: lista del modelo, 23 de 26 rojas; traza del commit, 24 de 24; comprobación, 57 de 57; informe de origen, 7 de 8 (1 omitida); S2, 22 de 23. La paridad ni siquiera carga: su proveedor nombra una clase nueva |
| V6 | **Mutaciones mías en el servidor**, una a una, con sustitución literal, restauración y `cmp` contra el commit | **15 de 16 mueren; la que sobrevive es equivalente** (§3, punto 8) **[C]** |
| V7 | **Mutaciones mías en S2**, sobre copias de `flujo-s2.json`, pasadas por el comprobador | **8 de 8 mueren**, y por las comprobaciones de fondo (apartado I), no solo por la de conexiones exactas **[C]** |
| V8 | Enumeración propia del grafo de S2 (todos los caminos simples de Composer a Preparar Commit) | **17 caminos**. Como mucho 3 redactores, 3 revisores y 2 comprobaciones; en todos, el último borrador lo juzga un revisor **[C]** |
| V9 | **Sondas mías** por los endpoints reales (`decide`, `ai/check` y `commit` del mismo turno), con la canaria «profesional, 200» y los 13 planes de producción del escenario de las pruebas | Resultados en §3, puntos 4, 5 y 10 **[C]** |
| V10 | El bloque `regeneration` con la forma EXACTA que arma Preparar Commit en siete recorridos (`tokens: null`, `rewrites: []`, `codes: []`…), mandado al commit real | **7 de 7 con 200**, `consistent: true` y `tokens: null` conservado **[C]** |
| V11 | `git branch -r --contains cd431e20` y `merge-base` con `origin/main` | Solo está en `origin/feat/ultron-studio-h9`; **no está en `origin/main`** (`bc5167cb`) **[C]** |

---

## 2. Las diez condiciones del propietario

| # | Condición | ¿Se cumple? | Evidencia |
|---|---|---|---|
| 1 | La IA sigue siendo el generador principal | **Sí** | El texto fijo solo sale tras los borradores o por una regla del commit. La lista de planes la redacta el modelo y Laravel solo sustituye los marcadores: sonda S2, «Claro. Tenemos Plan Valera ($65.000 COP), Plan Mensual ($80.000 COP) y Élite ($180.000 COP)…», sin `policy_violations` **[C]**. Matiz: con I-2, el borrador más probable del turno de precios gasta su primera reescritura en una nota que no pide la lista |
| 2 | Reescritura guiada, como mucho dos reintentos; el respaldo solo si fallan o lo exige una restricción | **En gran parte** | El tope está en la forma del grafo: 3 redactores (V8 y mutaciones N1, N2 y N4, todas muertas). El servidor se niega en el tercer borrador (R1 muere) y pasados 25 s (R2 y R2b mueren) **[C]**. Lo que le resta: I-2 (nota equivocada para el caso típico), I-3 (una nota que pide un dato que el modelo no recibe), m-1 (el tercer borrador puede arrancar pasados los 25 s) y E1–E9 (el commit sustituye sin haber pedido reescritura, siempre como red) |
| 3 | El backend es la fuente de nombres, precios y beneficios; nada inventado ni escrito a mano | **Sí en nombres y precios; en parte en beneficios (límite declarado, L-5)** | `item()` toma nombre y precio del MISMO `Plan`, leído en el commit (`UltronPlansOverview.php:258-269`). Mueren R8 (expandir un plan archivado) y R13 (un precio que no es el del catálogo de ese momento). Un `PLAN_k` fuera de la lista firmada da 422 (R3a y R3b mueren) **[C]**. Los beneficios: P-4 es literal y por frase. Pendiente: R-1 (nombres con llaves) y el agujero de I-1 (un marcador mal escrito sale literal) |
| 4 | Los 106 textos, como respaldo y con sus variantes de usted | **Sí** | `UltronFormalReplies.php`, `a4-textos-*.md` y `tests/fixtures/ultron-a4/`, sin una línea cambiada en el rango (`git diff --stat` vacío). Las instantáneas pasan **[C]** |
| 5 | Métricas: modelo, regeneradas y respaldos; el 90 % como objetivo | **En parte** | El comando existe, es de solo lectura y clasifica bien los caminos principales (pruebas en verde, V1). Pero el porcentaje frente al 90 % **excluye del denominador** las respuestas `*_recovered`, que son texto del servidor enviado, y cuenta como del modelo varias sustituciones del servidor (I-4) **[C/L]** |
| 6 | Sin reintentos ilimitados ni costos o latencia descontrolados; los controles de seguridad, intactos | **Sí, con una salvedad** | Como mucho 3 borradores y 2 comprobaciones; comprobación de 3 s sin reintentos; 25 s; el commit, intacto como red (V3, V7, V8; mutaciones R1, R2 y R7) **[C]**. Salvedad: m-1. El cubo compartido de 120 por minuto (R-2 de seguridad) es condición antes de ampliar |
| 7 | Pruebas, regresiones y conservar lo que funciona | **Sí** en lo que he ejecutado | V1 a V5. La batería completa no la he corrido yo: es un paso del lead |
| 8 | Limitarse al problema, sin reconstruir WhatsApp | **Sí** | No se tocan los prompts base, el CRM, `config/`, las migraciones, `canary-report` ni el n8n en marcha (el diff de `n8n/` es S2 nuevo más dos fixtures de S1). Lo nuevo es lo que trae el plan (ruta, S2, informe y parche del motor). La única regla de negocio nueva que sustituye texto es P-4 (`policy_benefit_misattributed`), decidida (L-5) **[L/C]** |
| 9 | No desplegar todavía; mantener la versión 2 | **Sí** | V11 **[C]**. Producción no la miré (no está permitido); según la matriz sirve `e861e2c8` **[L]** |
| 10 | El protocolo HUMAN GATE | **Sí** | Nada del código lo salta. El despliegue, S2 y la versión 3 pasan por el gate 4-H (plan §9; `n8n-copia-s2/LEEME.md`, sin ejecutar) **[L]** |

---

## 3. Los diez puntos del encargo

### Punto 1 · Las diez condiciones

Ver §2.

### Punto 2 · Sin versión del Studio no cambia nada

**Lo comprobado [C]:**
- **`decide`** solo ofrece `regeneration` con `sp.m = studio` (`UltronDecideService.php:683`). La mutación R6 (ofrecerla siempre) tumba 126 pruebas.
- **La lista** exige `sp.o = 1` y la oferta del Studio (`:309-312`).
- **La comprobación** contesta `legacy` sin leer nada; la mutación R9 (quitarle ese atajo) tumba 3.
- **El commit:** `listaOfrecida` y P-4 exigen `$turno['studio']` (`:542` y `:1775`), y `unknown($draft, 0)` y `allowed(0)` son exactamente `ALLOWED`.
- **S2 sin la oferta** recorre exactamente lo de S1: mismas llamadas y mismos `values[1]`, sin `ai/check` y con la misma salida de Preparar Commit. Lo prueban los apartados G e I del comprobador en verde (V3) y la mutación «Legacy pregunta al servidor», que muere.
- **Las dos instantáneas de Legacy:** sin regenerar (diff vacío en el rango) y en verde (V2).
- **S1 se genera byte a byte igual** (V4).

**«Un commit sin el bloque deja la fila idéntica a la de antes»:**
- **Legacy: sí**, y lo prueban las instantáneas.
- **Studio sin bloque** (S1 con el backend nuevo): la fila **no** es la de antes, y es a propósito: ahí ya viajan la lista del modelo, `metadata.plans_overview` y P-4.
- `test_con_el_bloque_la_fila_lo_lleva_y_sin_el_es_la_de_hoy` compara con y sin bloque **dentro del código nuevo**, no contra `a6e2ccdf`. El nombre de la prueba promete algo más de lo que prueba (MENOR, solo de nombre).

**La única rama nueva que alcanza Legacy** es R-4 de seguridad (la traza y `critic.attempt = 3` se guardan sin `studio`).

### Punto 3 · La comprobación y el commit dicen lo mismo

**La paridad prueba lo que dice [L+C]:**
- sobre el mismo turno (la misma conversación, el mismo token y la memoria escrita antes de `decide`), primero la comprobación y después el commit;
- «si y solo si» por cada código, en 23 + 19 borradores, más E1–E3 con su divergencia exacta;
- en verde (V1), y la mutación M12 de la ola 4b («la puerta no marca la política») la tumba.

**No son siete excepciones sino nueve (E1–E9):**
- E8 y E9 se añadieron en la ola 4b;
- E5 se recortó con la política.

**En ninguna de las nueve sale un texto peor que antes.** El commit sigue siendo la red y lo peor es:
- **una reescritura perdida**, con el mismo respaldo que antes: E1, E4, E6 y E9;
- **o una llamada de más**: E2, E3 y E5.

**Faltan en la lista (las he buscado):**
- **E10 [C], la única que sí empeora lo que sale:** un turno con herramientas. Con `app_links_send` en la propuesta, la comprobación se salta la regla de la lista (`UltronDraftDefects.php:90`) y el commit tampoco la exige (`UltronCommitService.php:1811-1814`, G1.2). Así que un borrador con los marcadores mal escritos pasa las dos y **sale literal**. Es I-1.
- **E11 [L]:** «profesional» y los enlaces de la app ya enviados hace menos de una hora. La comprobación cuenta los enlaces (texto neutro); el commit pega `LINKS_ALREADY_SENT`, «te los dejé aquí mismo…» (`MobileAppCatalog.php:97`, de tú), y lo descarta por el registro. Es H4-12 (b), anterior a este cambio: una reescritura no lo arregla y no empeora nada. Conviene declararla.
- **E6 tiene también la otra dirección:**
  - cuando la intención del estratega no está en `INTENCIONES_DEL_PANORAMA`, la comprobación pide la lista (`preguntaPorPrecio` del entrante) y el commit no la habría exigido;
  - cuesta una llamada y empuja hacia lo que pidió el propietario (H4-10); no es un defecto;
  - el docblock la da solo como «más laxa».

### Punto 4 · La lista del modelo

| Pregunta | Respuesta |
|---|---|
| ¿Un marcador sin expandir por WhatsApp? | **Con llaves dobles, no [C]:** un `PLAN_k` desconocido da 422; uno no vendible no se sustituye y el texto va a la lista fija o al último recurso (`:1836-1838` y `:1970-1972`). **Mal escrito, sí:** `PLAN_1` sin llaves o `{PLAN_1}` con una herramienta en el turno salen tal cual. Es I-1 (sondas S4 y S5) |
| ¿Un plan que no es vendible? | **No [C].** `po` sale de `activePlans()`, y el commit vuelve a consultar `Plan::sellable()` (`:5598` y siguientes). La mutación R8 (quitar `sellable()`) tumba 5 |
| ¿Un precio que no es el del catálogo en ese momento? | **No [C].** `item()` lee el `Plan` del commit. La mutación R13 (un precio fijo) tumba 11 |
| ¿Un nombre con el precio de otro plan? | **No [C/L]:** nombre y precio salen de la misma fila, por posición sobre `po` firmado. Queda R-1 de seguridad (un nombre con `{{PLAN_PRICE}}` se reexpande) |
| ¿La longitud y el registro se miden sobre el texto expandido? | **Sí [C].** La expansión va en el paso 9.bis, antes de todo lo demás. Sonda S2: `length.from` = 132, la medida expandida |
| ¿La memoria anota lo que salió? | **Sí [L+C]:** una segunda llamada a `conPreciosEntregados()` con los textos expandidos. Las pruebas de la lista con tres y con dos marcadores están en verde |
| ¿Y si el modelo NO usa los marcadores? | **Se le pide reescribir y, si no lo consigue, sale la lista fija, como antes [C]:** sondas S1, S3 y S6, todas con `policy_plans_overview` y la lista fija de 199 caracteres. **Pero** con el nombre escrito a mano la nota es la equivocada: I-2 |

### Punto 5 · La reescritura guiada, de extremo a extremo

He seguido los seis recorridos en `flujo-s2.json` (conexiones volcadas y enumeradas, V8) y en los `.js`.

| Recorrido | Qué pasa |
|---|---|
| Pasa a la primera | Composer → comprobación 1 (pasa) → Critic → Preparar Commit. Bloque `drafts 1 · critics 1 · checks 1` |
| El servidor pide una | Leer 1 con `rewrite` → Nota del Servidor 1 (forma del revisor) → Composer Reintento → comprobación 2 → Critic 2 → Decidir Intento 3 → Preparar Commit. El borrador 1 **no** llega al revisor ni al commit: se reemplaza |
| El revisor pide una | Igual que en S1, más la comprobación 2 |
| Dos reescrituras | En cualquier combinación, hasta Composer Reintento 2 → Critic Comercial 3 → Preparar Commit. **El tercero no se comprueba; lo juzga Critic 3 y la red es el commit** |
| Servidor caído (404, 5xx, red, mal formada) | El nodo HTTP tiene `neverError` y `continueRegularOutput`, sin reintentos y con 3 s. Leer Comprobación solo acepta un 200 con la forma entera y `attempt === K`; lo demás es `error: true`, sin reescritura. Decidir Intento 3 exige `error === false`, así que **sin tercer borrador**: el camino de hoy |
| Presupuesto agotado | `may_rewrite: false`, así que no se reescribe y el borrador va al revisor; Decidir Intento 3 da `false` |

- **¿Más de tres redactores?** Ningún camino (V8, y las mutaciones N1, N2 y N4 mueren). **[C]**
- **¿Un borrador rechazado por el servidor llega al commit sin revisor?** No. Con defectos pero sin presupuesto, va al revisor; con `rewrite`, se reemplaza. La mutación N6 («¿Reescribir 2?» sí → Preparar Commit) la mata «todo borrador lo juzga un revisor». **[C]**
- **¿n8n se queda sin responder?**
  - No por los nodos nuevos: los Code no lanzan (todo en `try`, salvo leer el redactor que acaba de correr), y los HTTP siguen con `continueRegularOutput` (N8, quitarlo, muere).
  - Los dos OpenAI nuevos copian los reintentos de S1, así que su comportamiento ante un fallo de OpenAI es el de hoy. **[C/L]**
- **¿El bloque `regeneration` cuadra con el commit?**
  - Sí en los siete recorridos de V10: 200, `consistent: true` y `tokens: null` aceptado. **[C]**
  - Preparar Commit no manda el bloque si `rewrites ≠ drafts − 1` o un valor sale de rango; con eso el commit no puede dar un 422 por la traza.
- **Una pérdida de traza (MENOR, m-2):** Preparar Rendicion es el de S1 y **no reenvía** el bloque. Los turnos que acaban en la rendición se quedan sin `metadata.regeneration`, aunque su origen se clasifica bien (respaldo `recovery`). El criterio A5 de §12 («en los tres, `metadata.regeneration` presente») fallaría en un turno así.

### Punto 6 · Las notas al modelo

| Nota | ¿Una reescritura que la obedezca pasa? | ¿Empuja contra otra regla? |
|---|---|---|
| N-R (tuteo) | Sí: el detector mira marcas cerradas sobre el texto expandido (sonda S8) | No |
| N-L (longitud) | En general sí. Pero «cada marcador cuenta como el texto que lo sustituye» le pide al modelo medir algo que no ve (los precios); puede hacer falta otra vuelta (MENOR) | No |
| N-P1 (falta la lista) | Sí (sondas S3 y S6) | El texto pone «PLAN_1, PLAN_2 o PLAN_3» sin llaves (para que no haya `{{` en la nota), y es justo la forma que, copiada, sale literal con herramientas (I-1) |
| N-P2 (mezcla) | Sí con tres ofrecidos | Nombra siempre PLAN_3, también con `K = 2`, lo que invita a `unknown_marker` (m-6). Con el catálogo de hoy siempre son tres [L] |
| **N-P3 (nombre duplicado)** | **No cuando no hay ningún marcador de la lista:** quitar el nombre deja `missing` (I-2) | — |
| N-P4, N-P5, N-P6 y N-B | Sí | No |
| Saludo, visita, dato, solo preguntas y memoria (las cuatro corregidas por el lead, §6.9) | Sí, cada una dice lo que mira `violations()` (`CommercialTurnPolicy.php:658-757`). La del dato pide copiar «tal cual», como exige `factPresent()` | El saludo pide «Saluda»; con `greet_required = false`, el commit recorta el saludo de más y no sustituye. Benigno |
| **Plan equivocado (corregida por el lead)** | **No del todo:** pide no nombrar ninguno de `forbidden_plan_ids`, un campo que **no viaja al redactor** (`UltronDecideService.php:252-262`, y lo dice su comentario en `:603-606`). El modelo solo ve `allowed_plan_ids` (I-3) | Con un plan insignia que no sea el primero de la lista, «en el orden en que vienen» (T_PLANS_COMPOSER) choca con «el primero tiene que ser `required_plan_id`». Es R8 del plan; hoy ningún plan está marcado (m-9) |

### Punto 7 · Las métricas

**`classify()` clasifica bien lo que mira [C, V1]:**
- el modelo al primer intento;
- las regeneradas, por el servidor o por el revisor y con sus códigos, desde la traza;
- el respaldo del revisor, de la rendición y de la política;
- la hora de la visita, marcada aparte como texto del servidor y fuera del objetivo desde §6.9.

**Un turno no se cuenta dos veces:** una fila por turno, idempotente; el reintento del job da 409 y no escribe fila.

**Pero el 90 % no se mide del todo con lo que escribió el modelo (I-4):**
- **(a) [C, lo fija la propia prueba** (`UltronOriginReportCommandTest.php:78`)**]:** las filas `repeated_reply_recovered` y `resell_blocked_recovered`, que son **último recurso del servidor enviado al cliente**, quedan fuera del denominador (`UltronOriginReport.php:67` y `:170`). Con 9 respuestas del modelo y 1 repetida recuperada, el informe da 9 de 9 (100 %); lo real es 9 de 10 (90 %). La rama `_recovered` de `classify()` (`UltronReplyOrigin.php:117`) es código muerto para el informe.
- **(b) [L]:** se cuentan como «del modelo» textos que puso el servidor:
  - el último recurso de la realineación en un turno de cobro (`UltronCommitService.php:941-945`, `plan_price_realigned.fallback`);
  - el texto curado del cobro cuando el borrador lo negaba (`:1536-1537`);
  - el último recurso del desbordamiento en `antesDeEnviar()` (`:5530`, `length.overflow`);
  - el último recurso de una excusa de precio que deja el texto vacío.

  Son raros, pero son exactamente lo que la condición 5 pide separar.

### Punto 8 · Las pruebas

Usan clases reales y pasan por el endpoint, con HTTP falso y el conductor de IA en `fake`. El rojo previo es real (V5). **Mis mutaciones (V6 y V7):**

| # | Mutación | Pruebas que caen |
|---|---|---|
| R1 | Sin la negativa del servidor en el tercer borrador (`>=` → `>`) | 2 |
| R2 / R2b | Sin el tope de 25 s / con el tope a 120 s | 1 / 1 (cobertura justa: una sola prueba, m-8) |
| R3a | El commit admite `PLAN_k` fuera de la lista firmada | 9 |
| R3b | `posicion()` sin el límite `n` | 3 |
| R3c | El id por defecto si falta la posición | 0: **equivalente** (`posicion()` ya garantiza `k ≤ n`) |
| R4a / R4b / R4c | La lista vale en un turno de cobro: en el commit / en la comprobación / `isMoneyTurn()` sin las intenciones | 5 / 2 / 6 |
| R5a / R5b / R5c | La comprobación escribe: un `UPDATE` que no cambia nada / un `cache()->put()` / el lector guarda la conversación | 3 / 2 / 1 |
| R6 | Un turno Legacy recibe `regeneration` en `decide` | 126 |
| R7 | `rewrite` sin mirar el presupuesto | 2 |
| R8 | El commit expande un plan archivado | 5 |
| R9 | La comprobación sin el atajo de Legacy | 3 |
| R11 | Los defectos de la lista sin la condición de las herramientas | 2 |
| R12 | Una lista mezclada se da por válida | 10 |
| R13 | Un precio de la lista que no es el de `Plan` en ese momento | 11 |
| N1 / N2 | S2 con un ciclo (Nota 2 → Composer Reintento; «¿Intento 3?» → Composer Reintento) | ✗ en I (bucle, más de 3 redactores) |
| N3 | El cuerpo de la comprobación 2 con `attempt: 1` | ✗ en D y J |
| N4 | Un cuarto redactor y un cuarto revisor | ✗ en B e I |
| N5 / N6 | Un borrador al commit sin revisor (la salida «no» de ¿Reescribir 1? / la «sí» de ¿Reescribir 2?) | ✗ «todo borrador lo juzga un revisor» y recorridos |
| N7 / N8 | Tiempo máximo de 30 s / sin `continueRegularOutput` | ✗ en D |

**Lo que les falta a las pruebas:**
- E10 (I-1) y la nota de I-2: la matriz de paridad no tiene turnos de lista con herramientas, y nada mira la nota que recibe el caso típico del 18-11;
- y el informe con filas `_recovered` en el denominador (lo fija al revés).

### Punto 9 · Calidad

- **`UltronCommitService` crece +194 líneas netas** (227/−33). `listaFirmada()` y `listaDelModelo()` (`:5552` y `:5598`) caben en `UltronPlansOverview`, que ya tiene `signedList()` e `isMoneyTurn()`; solo les falta la consulta de `Plan`. Sería un paso más del m-8 de H4-12 (m-5).
- **Duplicación que puede divergir (m-4).** Son la misma regla, escrita dos veces:
  - la condición del panorama, en `UltronDraftDefects.php:90-95` y en `UltronCommitService.php:1825-1827`;
  - el plan que impone la política, en `UltronDraftCheck.php:228-232` y en `UltronCommitService.php:852-854`;
  - los recortes F1 y G2-bis, en `ofPolicy()` y en el commit.

  Hoy los ata la paridad, y por eso no es más que MENOR. Lo sano es una función compartida para cada una.
- **Comentarios que dicen lo que el código no hace (m-3):**
  - `UltronPlansOverview.php:38`, «Nadie la llama todavía para la lista del modelo (ola 1…)», y `UltronRegenerationNotes.php:17`, «Nadie la llama todavía»: hoy las llaman `decide`, el commit y la comprobación;
  - `UltronDraftCheck.php:51`, «no dice nada… del dinero»: `price_written` es exactamente la regla del dinero del validador (R-5 de seguridad).
- **Código muerto:** la rama `_recovered` de `classify()` vista desde el informe (I-4 a).

### Punto 10 · Lo que puede hacer fallar la tercera prueba real

| Mensaje | Riesgo que este cambio debería haber cubierto |
|---|---|
| Saludo | Bajo. Si el modelo vende, la nota del saludo dice lo que mira la regla [L] |
| «¿Qué planes tienen y cuánto cuestan?» | **El más probable, I-2 [C, sonda S1]:** el 18-11 el modelo contestó «Plan Valera» + `{{PLAN_PRICE}}`. Con ese borrador la comprobación da solo `name_written` con N-P3 («su marcador ya pone el nombre»), que no pide la lista. Si el modelo obedece, quita el nombre y le queda un plan; la comprobación 2 dará `missing` con N-P1, pero el tercer borrador ya no se comprueba. Si tampoco trae la lista, sale la lista fija (respaldo) y falla A4. **Segundo, I-1 [C, sondas S4 y S5]:** si el estratega pide `app_links_send` y el redactor escribe `PLAN_1` sin llaves o `{PLAN_1}`, sale literal por WhatsApp. **Tercero [S], R5 del plan:** que el revisor tumbe la lista; no recibe `plans_overview` |
| «¿Cuál es el horario entre semana?» | Cubierto: el horario de tú pide reescritura con N-R (sonda S8) [C]; si se agota, sale el horario curado de usted, 186 caracteres |
| «Quiero bajar de peso» | [S] Un falso positivo de P-4 con los beneficios reales del catálogo (los de las pruebas son todos iguales), que llevaría a un respaldo. No lo puedo medir sin el catálogo de producción |

**Fuera de este cambio, pero condiciona la prueba:**
- la versión 2 cae a Legacy al desplegar el compilador 4, y hace falta la versión 3 aprobada;
- S2 se importa con `importar-s2.sh`, sin ejecutar;
- la ruta nueva exige `route:cache`; sin él, `ai/check` da 404 y S2 se porta como S1, sin errores pero sin reescrituras.

---

## 4. Hallazgos

### Bloqueantes

Ninguno.

### Importantes

**I-1 · Un marcador de la lista mal escrito sale literal por WhatsApp en un turno con herramientas.** Se reparte entre `backend` y `security`.
- **Dónde:**
  - `UltronDraftDefects.php:90`: con herramientas en la propuesta, no se mira la lista;
  - `UltronCommitService.php:1811-1814`: el commit no exige el panorama con herramientas;
  - `antesDeEnviar()` (`:5508`): no mira marcadores.
- **Reproducción [C, sondas S4 y S5]:** canaria «profesional, 200», entrante «¿Qué planes tienen y cuánto cuestan?» (`po = [3,4,8]` firmado), intención `pricing_question` y `tools_requested = ["app_links_send"]`, con el revisor aprobando:
  - el borrador «Claro. Tenemos PLAN_1 y PLAN_2, los dos de un mes. Le dejo la app.» → la comprobación da `defects: []` y el commit, 200 → **sale «Claro. Tenemos PLAN_1 y PLAN_2, los dos de un mes. Le dejo la app.» con los enlaces debajo**;
  - con «{PLAN_1} y {PLAN_2}», igual.
- **Por qué es de este cambio:** la instrucción nueva (T_PLANS_COMPOSER) y las notas N-P1, N-P2 y N-P5 le enseñan al modelo el nombre `PLAN_1` sin llaves.
- **Corrección:** ampliar la red que R-1 ya va a poner en `antesDeEnviar()`, para que salte también con `\bPLAN_[0-9]+\b`, `\{\s*PLAN_`, `\bPLAN_(PRICE|NAME|DURATION)\b` y `\bAPP_LINKS\b`, además de `{{`/`}}`, con último recurso y aviso. Y que esa sustitución quede en la metadata (ver I-4 b).
- **Prueba:** las dos formas de S4 y S5, con y sin herramientas, sin que en lo que sale aparezca `PLAN_` ni una llave.
- **Clasificación:** es de la canaria (solo turnos del Studio) y requiere dos condiciones (un marcador mal escrito y una herramienta). Por eso no lo pongo como bloqueante, pero **recomiendo meterlo en la misma ronda que R-1**, que toca la misma línea.

**I-2 · Para el borrador más probable del turno de precios, la comprobación da la nota que no corresponde.** Asignado a `backend`; el texto, si cambia, lo aprueba el propietario en el gate 4-H.
- **Dónde:** la precedencia de `UltronPlansOverview::verify()` (`:344-355`): `name_written` va antes que `missing`.
- **Reproducción [C, sonda S1]:** el mismo turno y el borrador «Claro. El Plan Valera cuesta {{PLAN_PRICE}} al mes e incluye acceso total al gimnasio. ¿Le gustaría conocer más?» (plan 3; es el patrón del 18-11).
  - La comprobación devuelve **solo** `plans_overview/name_written` y la nota «NOMBRE DUPLICADO: no escribas el nombre de un plan de la lista; su marcador ya pone el nombre y el precio.»
  - Una reescritura que la obedezca (quitar el nombre) da `missing` (sonda S6).
  - Se gasta la primera reescritura y la lista solo se pide en la segunda, la del tercer borrador, que no se comprueba.
- **Corrección:** si el borrador no lleva ningún marcador de la lista (`$validos === []`), devolver `missing` antes que `name_written`, o mandar N-P1 y N-P3 juntas. Es una línea, y conviene antes de la tercera prueba.
- **Prueba:** el borrador de S1 tiene que dar `missing` con N-P1.

**I-3 · La nota del plan equivocado pide algo que el modelo no ve.** Asignado al lead: es el texto que corrigió en §6.9 y que aprobará el propietario.
- **Dónde:** `UltronRegenerationNotes.php:123` («no nombres ninguno de forbidden_plan_ids»). `forbidden_plan_ids` no viaja al redactor (`UltronDecideService.php:252-262`; el comentario de `:603-606` dice por qué).
- **Escenario [L]:** la persona rechazó el Plan Valera y el borrador lo nombra. La nota remite a un campo que no está en el contexto, y el modelo tiene que deducirlo del historial. Si no lo deduce, sale el respaldo de un plan.
- **Corrección:** «…y nombra solo planes de commercial_turn_policy.allowed_plan_ids…», que sí viaja y es el complemento. Alternativa: exponer `forbidden_plan_ids`, aunque es justo lo que el comentario decidió no hacer.

**I-4 · El porcentaje frente al 90 % no se calcula del todo con lo que escribió el modelo.** Asignado a `backend`.
- **(a) [C]** Las filas `*_recovered/executed` (último recurso del servidor, enviado) se quedan fuera del denominador (`UltronOriginReport.php:67` y `:170`). Con 9 del modelo y 1 recuperada, da 100 % en vez de 90 %.
- **(b) [L]** Las sustituciones del servidor dentro del camino del modelo cuentan como del modelo: las de `:941-945`, `:1536-1537`, `:5530` y la de la excusa de precio vaciada.
- **Corrección:**
  - meter `*_recovered/executed` en el reparto como respaldo `last_resort` (`classify()` ya lo sabe hacer), con la cifra de la línea base en su línea aparte, como se hizo con la hora de la visita en §6.9;
  - y marcar en `classify()` como texto del servidor `plan_price_realigned.fallback`, `length.overflow` y los otros dos (o anotarlos en la metadata donde ocurren).
- **Prueba:** 9 del modelo + 1 `repeated_reply_recovered` → 90 %.
- **Plazo:** antes de juzgar el 90 % con la muestra de 30 turnos, no antes de la tercera prueba.

### Menores (a pendientes)

- **m-1 · El presupuesto de 25 s no se vuelve a mirar antes del tercer borrador** que pide el revisor.
  - `decidir-intento-3.js:37` usa el `may_rewrite` de la comprobación 2, que se calculó antes de Critic 2. Con la comprobación 2 a los 24 s y un Critic 2 lento, el tercer borrador arranca pasados los 25 s.
  - Lo declaró el implementador (decisión 14 de la ola 5). El tope de 3 se mantiene, y A6 lo medirá.
  - Si se quiere cerrar: que `ai/check` devuelva `elapsed_s` y que Decidir Intento 3 exija margen.
- **m-2 · La rendición pierde la traza:** Preparar Rendicion (de S1) no reenvía `regeneration` (§3, punto 5).
- **m-3 · Comentarios desfasados:** `UltronPlansOverview.php:38`, `UltronRegenerationNotes.php:17` y `UltronDraftCheck.php:51`.
- **m-4 · Duplicación entre la comprobación y el commit** (§3, punto 9).
- **m-5 · `listaFirmada()` y `listaDelModelo()`, fuera del commit.**
- **m-6 · N-P1 y N-P2 nombran PLAN_3 aunque `K = 2`.** Con el catálogo de hoy la lista siempre tiene tres [L].
- **m-7 · La lista de excepciones del docblock de la paridad:** son nueve (E1–E9), no siete, y faltan E10 (I-1) y E11 (los enlaces ya enviados con «profesional»).
- **m-8 · El tope de 25 s lo defiende una sola prueba** (R2 y R2b dejan una roja cada una).
- **m-9 · El orden de la lista frente al plan insignia** (R8 del plan; hoy no se alcanza).
- **m-10 · El nombre de `test_con_el_bloque…_y_sin_el_es_la_de_hoy`:** compara dentro del código nuevo, no contra la fila de antes.

---

## 5. R-1, R-4 y R-8 de seguridad, tal como se proponen

- **R-1 (nombres con llaves): bien, con dos añadidos.**
  - Quitar `{` y `}` en `item()` y en el `NAME` de `resolve()` cierra la reexpansión.
  - Pero la red de `antesDeEnviar()` tiene que saltar también con los marcadores **mal escritos** (I-1), no solo con `{{…}}`.
  - Y tiene que dejar constancia en la metadata, para que el informe de origen lo cuente como texto del servidor (I-4 b).
- **R-4 (la traza en Legacy): bien en lo esencial.**
  - Dos matices: condicionar la traza a la **oferta firmada** (`sp.m = studio` en el token, que es cuando `decide` ofreció `regeneration`) y no a `$turno['studio']`. Con una versión revertida entre `decide` y `commit` se perdería una traza que es verdad.
  - Y, en vez de recortar en silencio `critic.attempt` a 2 en Legacy, guardar el valor con un aviso en el log: un 3 en Legacy es una anomalía de n8n que conviene ver.
- **R-8 (nota vacía, 500): bien.** El marcador o «ese plan» si el nombre queda vacío. Como defensa añadida, que un `InvalidArgumentException` de `note()` deje el defecto sin nota en vez de un 500. n8n ya trata el 500 como «no reescribir», así que no es urgente.

---

## 6. Lo que NO he podido verificar

- **PostgreSQL:** todo lo mío corrió en SQLite. El implementador pasó la paridad y la lectura en PostgreSQL (evidencias 107 y 118) **[L]**.
- **La batería completa:** no la he ejecutado; es el paso (3) del lead.
- **El PCRE 10.39 de producción:** no lo he probado.
- **n8n real:** si el nodo de OpenAI 2.3 entrega `usage`, la forma exacta del HTTP con `fullResponse` ante un tiempo agotado y la trampa de los datos fijados en el ensayo **[S]**.
- **El modelo:** si sigue la instrucción de los marcadores y si el revisor acepta la lista (R5 del plan) **[S]**; no corre ningún modelo.
- **Los beneficios reales del catálogo de producción:** no los he visto, así que no puedo medir los falsos positivos de P-4 **[S]**.
- **Producción:** no la he mirado.

**Copias de trabajo (fuera del repositorio):**
- `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body/2e552fad-c575-456f-832e-c56036b54202/scratchpad/exp/backend`: la copia del commit, donde corrieron las pruebas y las mutaciones. Se comprobó con `cmp` contra `cd431e20` al terminar.
- `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body/2e552fad-c575-456f-832e-c56036b54202/scratchpad/exp2/backend/tests/Feature/Marketing/SondaReviewerTest.php`: las sondas S1–S9.
- `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body/2e552fad-c575-456f-832e-c56036b54202/scratchpad/sonda.json`: sus resultados.
- `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body/2e552fad-c575-456f-832e-c56036b54202/scratchpad/mut/`: las mutaciones y su salida.
- `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body/2e552fad-c575-456f-832e-c56036b54202/scratchpad/mutn8n/`: las mutaciones de S2.

**Secretos:** no he visto, impreso ni usado ninguno real. La `APP_KEY` de las copias era de prueba y no se imprimió.

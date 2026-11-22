# Backend · A4, corrección (3): los beneficios de cada plan junto a su marcador en `plans_overview`

**Base:** rama `cierre/a4-notas` sobre las correcciones (1) y (1b) (`informe-backend-a4-notas.md`). **Sin desplegar**, sin red, sin ssh y sin modelos. Rutas relativas a `backend/`. **Marcas:** [C] comprobado ejecutando · [L] leído · [H] hipótesis. Numeración del depurador en su versión de 161 líneas: (1) = notas, (2) = la (1b) de aquí, (3) = esta.
**Separable:** `0b52e8df` y este documento. Revertirlos deja (1) y (1b) como estaban: sus baterías pasaron en `398b2331`, antes de la (3) [C].

## Qué cambió
- `app/Services/Marketing/Ultron/UltronPlansOverview.php:232-249`:
  - `items()` añade `benefits` a cada elemento: los de SU plan (`plan_id`).
  - Salen del mismo `activePlans()` y con el mismo filtro que `active_plans` (`UltronDecideService::plansWithoutPrice()`): la lista ya limpia por `Plan::benefitsArray()` (recortada y sin vacíos), tal cual, sin la clave si está vacía.
  - No hay límite de número ni de longitud, porque hoy tampoco lo hay en `active_plans` [L].
- `app/Services/Marketing/Ultron/UltronDecideService.php:283`: solo el docblock, sin código.
- **Cuándo sale:** `items()` solo la llama `listaDelPanorama()`, que exige `sp.m = studio`, `sp.o = 1` y la pregunta del panorama. En Legacy nada cambia.
- **Lo que no se toca:** prompts, compilador, oferta, n8n, `ai/check`, el commit ni la comprobación de beneficios.

## Qué viaja ahora al modelo que antes no viajaba en ese objeto (para `security`)
- **El campo:** `commercial_turn_policy.plans_overview.plans[k].benefits`, una copia exacta de `active_plans[id].benefits` de los 2 o 3 planes de la lista.
- **Nada nuevo para el modelo:** ni precios ni cifras. Esos mismos textos ya le llegan en `active_plans`.
- **Quién lo recibe** (`n8n/flujos/ultron-studio-s2/flujo-s2.json`) [C]:
  - El estratega y los tres redactores: «Validar Estrategia» devuelve `context: ctx` sin filtrar. Composer lee `$json.context.context.commercial_turn_policy` y los reintentos, `$("Pedir Contexto al CRM")…commercial_turn_policy`, enteros.
  - Los revisores no: reciben solo seis campos de la política.
- **Sin persistencia:** no se guarda ni se registra. El token firma solo los ids (`po`) y la fila del commit, `offered` y `used` [L].
- **Cuánto crece:** con los beneficios de producción que leyó el lead (sonda, fuera del repositorio) [C]:
  - Valera: 5 beneficios, de 28, 26, 52, 38 y 18 caracteres.
  - Mensual: 4, de 28, 26, 52 y 38.
  - Élite: 6, de 24, 34, 31, 34, 32 y 44.
  - `plans_overview` pasa de 254 a 849 bytes. La respuesta de `decide` crece exactamente lo mismo, de 134.286 a 134.881 (+595).
  - Las listas de producción son más largas (la nota del lead acaba en «…»): el aumento real es el JSON de esos beneficios más 12 bytes por plan (`,"benefits":`).
  - Lo reciben cuatro llamadas al modelo: unos 150 tokens más cada una con estas listas, a 4 bytes por token [H].

## Legacy: la respuesta de `decide`, la de hoy
- **Sonda** con la pregunta del panorama, versión «profesional»/200 publicada en la canaria: sha256 de la respuesta entera de `decide` sin el token, con el `UltronPlansOverview.php` de antes y el de ahora [C]:
  - sin negociar: `12fc1a54…7c65` las dos veces (13.955 bytes);
  - llave de prompts apagada: `b14e4708…eec8` las dos veces (14.281 bytes);
  - en el Studio, solo cambia `plans_overview` (además, `studio_prompts.entry`, el UUID de la publicación de cada pasada).
- **Las instantáneas que ya existen** (`tests/fixtures/ultron-a4/legacy-a4.json` y `legacy-usted.json`) no guardan la respuesta de `decide`: fijan las salidas del commit y `resolved_reference.type`.
  - Pasan sin regenerar (las corre `UltronA4LongitudYPreciosTest` / `…RegistroYPanoramaTest`).
  - `test_decide_no_ofrece_la_lista_fuera_del_panorama_ni_sin_el_parche_ni_en_legacy` sigue exigiendo que no haya `plans_overview` en Legacy.

## La comprobación determinista de beneficios que ya existe (no tocada)
- **`UltronBenefitAttribution::misattributed()`** (código `benefit_misattributed`, P-4): literal por frase, por palabras completas. Declara que no ve paráfrasis.
- **Con el borrador 3 real y los beneficios de producción** («el Élite… incluye acceso ilimitado, asesoría y seguimiento con entrenador») da `[]`: **no lo habría cazado** [C].
- **El literal** «El Élite… incluye acceso ilimitado al gimnasio», en cambio, sí se caza: plan 8 ← beneficio 0 de los planes 3 y 4 [C].

## La (1) no depende de la (3)
- Sus notas dicen «en su mencion, para quien es» y no dicen dónde están los beneficios: son correctas con la (3) y sin ella.
- El parche firmado del compilador («usa solo los benefits de su plan_id en active_plans», `UltronPromptCompiler.php:331`) sigue siendo cierto: son los mismos datos.

## Pruebas
- **Regresión:** `UltronA4NotasDelServidorTest::test_decide_da_junto_a_cada_marcador_los_beneficios_de_su_plan` (`:301`).
  - Con los beneficios de producción: `plans[2]` es el id 8 con los del Élite, no contiene «Acceso ilimitado al gimnasio» y cada elemento es igual a su `active_plans`.
  - Sin cifras; un plan sin beneficios va sin la clave, como en `active_plans`; en Legacy no hay lista.
  - Con el código de antes: `ErrorException: Undefined array key "benefits"` (`Tests: 1, Errors: 1`) [C].
- **Forma actualizada a propósito** (el cambio pedido): `UltronPlansOverviewTest::test_items_…` y `UltronA4ListaDelModeloTest::test_decide_ofrece_la_lista_firmada…`.
- **SQLite:**
  - los 53 ficheros que llaman a `ai/decide` o miran la lista o el flujo, `OK, but some tests were skipped! Tests: 1587, Assertions: 13854, Skipped: 1` (la de `jsonb`);
  - `tests/Unit/Marketing`, `OK (4080 tests, 47708 assertions)`.
- **`tests/Feature/Marketing` entero** se corrió una vez, antes de la (3) (`Tests: 2789 … Skipped: 1`). No lo he repetido.
- **PostgreSQL 14** (lote del A4 y `UltronPlansOverviewTest`): `OK (527 tests, 21393 assertions)`.
- **Huellas:** idénticas (base `3f6a5674…`, compilador 4, paquete `26d57565…`, índice de `n8n` y prompts `8f296c97…`).
- **Guardas:** cae solo la puerta de diff del H9, ahora con siete ficheros del motor: los cinco de antes más `UltronDecideService.php` y `UltronPlansOverview.php`.

## Para el despliegue y lo que no se pudo verificar
- **Producto:** `UltronPlansOverview.php` (y `UltronDecideService.php`, que solo cambia un comentario).
- **Sin verificar:**
  - Si el modelo usa los beneficios de al lado del marcador y deja de cruzar `PLAN_3` con el id 3: solo lo dirá el laboratorio o la canaria limpia.
  - Que copiar un beneficio largo, por ejemplo uno de 52 caracteres, no lo saque del presupuesto: la nota de la (1) le dice la R, no el tamaño de cada beneficio.

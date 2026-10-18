# `coherence-cases.json`: paridad de la coherencia de reglas (Hito G)

**Procedencia.** Lo escribió el tester **a mano**, a partir de `docs/ultron-studio/ULTRON_STUDIO_G_CONTRACT.md` (§A3 y §A9) y del catálogo real `2026-10-19.1`. Ningún `expected` sale de ejecutar una implementación. No es un oráculo regenerable como `validation-cases.json` (F), y por eso va en un fichero aparte.

**Quién lo consume:** `StudioCoherenceParityTest` (PHP) y `studio-rules-coherence.parity.spec.ts` (TS, a través de `testing/backend-coherence-cases.testing.ts`). Los dos tienen que reproducir cada `expected` **exactamente**. Si una implementación discrepa, se corrige ella. Este fichero solo cambia con una enmienda del contrato.

**Recuento:** 123 casos. `canonicalize` 55 · `analyze` 16 · `conflicts` 33 · `evaluate` 19.

## Forma

```jsonc
{ "version": 1, "catalogVersion": "2026-10-19.1", "source": "…",
  "canonicalize": [ { "name", "kind": "canonicalize", "input": { "layers", "conditions" }, "expected": { "conditions", "errorKeys" } } ],
  "analyze":      [ { "name", "kind": "analyze", "rules": [RuleInput], "expected": { "<code>": { "verification", "issues": [ { "id", "level", "with", "target" } ] } } } ],
  "conflicts":    [ { "name", "kind": "conflicts", "candidate": RuleInput, "live": [RuleInput], "expected": { "controls": [], "rules": [] } } ],
  "evaluate":     [ { "name", "kind": "evaluate", "rules": [RuleInput], "definition": { "layers", "edgeIds" }, "configs": {…}, "format"?: 1|2, "expected": [Finding] } ] }
```

- Las cuatro secciones son las de §A9. `kind` repite el nombre de la sección en cada caso, así que la lista se puede aplanar sin perder nada. `name` es único en todo el fichero.
- `RuleInput` = `{code, source, enforcement, status, description, layers, conditions, refs}`, como en §A9.
  - `refs` lleva siempre sus cuatro listas.
  - Las R-xx son las de G: `legacy_baseline` · `code` · `active`, con las condiciones y las `refs.edges` de la tabla de transcripción de §A3.
- `Finding` = `{id, level, title, detail}`, más `layer` solo en `regla-<code>`, igual que en F.
- **`canonicalize.input`** son las dos cosas que necesita la función: las `conditions` tal y como llegan y las `layers` de la regla, que hacen falta para la comprobación de capas.
- **`analyze.expected`** tiene una entrada por cada regla de `rules`, en el mismo orden.
- **`evaluate.expected`** son **solo los hallazgos de reglas**: los que se añaden al final de los de F. Los de F no se repiten aquí porque ya los fija `validation-cases.json`.
- **`evaluate.format`** es opcional. Ausente o `2` significa borrador o versión de formato 2. `1` es una versión de F: la evaluación de reglas no corre y el resultado es `[]` (§A3, «Con qué reglas se evalúa»).

## Cómo se compara

- Igualdad de valores JSON. Las listas cuentan en orden.
- Las claves de cada objeto van en el orden de los ejemplos de §A3 y §A9. El arnés de PHP compara con `assertSame`, así que la implementación tiene que devolver ese orden o el arnés tiene que normalizarlo:
  - predicado de configuración: `kind`, `target`, `layer`, `key`, `op`, `value` o `values`;
  - predicado de arista: `kind`, `target`, `edge`, `op`;
  - predicado de texto: `kind`, `target`, `op`, `phrases`.
- **`errorKeys` es un conjunto.** Va ordenado por bytes y sin repetidos. El arnés ordena las claves que devuelve la implementación y compara listas.
- **No hay ningún `{}` en el fichero.** PHP decodifica `{}` como `[]` (`json_decode(…, true)`), y un caso que dependiera de esa diferencia daría resultados distintos en cada lado.
- El catálogo es siempre el real de G. Un caso de `evaluate` depende del texto nuevo de la opción `entorno` de `intent.driver`, «Según el entorno» (§A2).

## Lo que el contrato no fijaba y fija este fichero

Cada punto cita los casos que lo fijan. **El lead tiene que congelarlos** con una enmienda del contrato o con una nota de lo que ya acepta.

### Canonicalización

1. **Claves de 422.**

   | Clave | Cuándo |
   |---|---|
   | `conditions` | No es un objeto, tiene claves que no son `when` ni `then`, o supera el tamaño |
   | `conditions.when` · `conditions.then` | Falta, no es una lista, o tiene un tamaño fuera de 0–3 (`when`) o de 1–3 (`then`) |
   | `conditions.<lista>.<i>` | El elemento no es un objeto; tiene una clave que su combinación no admite; repite la variable de un elemento anterior de la misma lista; o es imposible (en `then`), nunca se aplicaría (en `when`) o se contradice con su propio `when` (en `then`) |
   | `….target` | Falta, es desconocido o es `text` dentro de `when` |
   | `….layer` · `….key` · `….edge` | Falta o el catálogo no lo tiene. Con una capa desconocida solo sale `layer` |
   | `….op` | Falta o el tipo no lo admite. Entonces `value`/`values` no se juzgan |
   | `….kind` | Falta, es desconocido, o es `limit` fuera de `lte`/`gte` sobre un `range` |
   | `….value` · `….values` · `….phrases` | Falta o tiene una forma que no vale. Un elemento malo de la lista sale como `….values.<j>` o `….phrases.<j>`, con el índice de la entrada |
   | `layers` | La regla no incluye la capa de un objetivo de configuración, o no incluye ninguna de las dos capas de una arista |

   - Una clave que sobra se señala en su objeto padre y nunca con su nombre, como hace el normalizador de F (`StudioDraftNormalizer`).
   - Casos: todos los de `canonicalize` con errores.
2. **Forma canónica.**
   - Cada objeto sale con sus claves en el orden de arriba, aunque llegue desordenado.
   - Los predicados conservan su orden.
   - Casos: «cada objeto sale con sus claves en el orden canónico» y las válidas.
3. **Tamaño.**
   - Se miden los bytes UTF-8 del JSON compacto de la entrada, sin escapar Unicode ni barras: `json_encode(…, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)`, o `JSON.stringify` medido con `TextEncoder`.
   - Más de 4096 bytes da **solo** `conditions`.
   - Los dos casos de «tamaño» se eligieron para delatar los dos errores probables:
     - medir unidades UTF-16 (`.length`): el caso de emojis cabría;
     - medir el JSON escapado con `\u`: el caso de `ñ` no cabría.
   - El caso válido ocupa menos de 4000 bytes, así que también vale si alguien entiende «4 KB» como 4000.
4. **Caracteres.**
   - Son puntos de código, no unidades UTF-16: 31 emojis caben en 60.
   - Las longitudes se miden sobre el valor ya canónico. En las etiquetas, tras recortar; en las frases, tras recortar y colapsar: `" a "` es una frase de 1 carácter.
5. **`when` es obligatorio**, aunque esté vacío. Caso «falta when».
6. **`layers` cuenta también los objetivos de `when`**, no solo los de `then`. Caso «capas: también cuentan los objetivos de when».
7. **`lte` y `gte` admiten cualquier entero de `[min, max]`**, esté o no en la rejilla: manda la tabla de §A3.
   - El ejemplo de «imposible» de §A3 (`maxChars gte 500` con un máximo de 480) es en realidad un error de valor: `….value`.
   - Con el catálogo real, una afirmación `range` con un valor válido nunca es imposible.

### Controles y contradicciones

8. **Lista de controles**, en este orden:
   1. las restricciones `blocking` del catálogo, en su orden, como `control:<id>`;
   2. las aristas obligatorias, en el orden del catálogo, como `edge:<id>`, que equivale a «require Exx present»;
   3. las `legacyConditions`, como `R-01`, `R-02`, `R-07` y `R-12`.

   - `conflicts.expected.controls` y el `with` de `regla-contradice-control` filtran esa lista y conservan su orden. Cada identificador sale una vez.
   - Una restricción con varias aristas cuenta como un solo control.
   - Casos: «orden de los controles…» y «cobro-sin-camino: … sale una vez».
9. **Contradicción por pares, al pie de la letra** (§A3).
   - Solo se cruzan las variables comunes a los dos `when`, y una afirmación solo se compara con afirmaciones.
   - Consecuencias que el fichero fija a propósito:
     - Dos reglas cuyos `when` no comparten ninguna variable sí se contradicen («when sin variables comunes», en `analyze` y en `conflicts`).
     - Una arista de cobro exigida como ausente y **sin `when`** contradice a `cobro-sin-camino` (`conflicts` «cobro-sin-camino por pares»). Para proponerlo sin chocar, hace falta `when paymentLinksEnabled = no`.
   - Cadena de tres reglas: es la limitación declarada y no se detecta (`conflicts` «limitación declarada»).
10. **`conflicts`.**
    - Se calculan siempre las dos listas, sin cortocircuito.
    - `rules` va en el orden de `live` y cada código sale una vez.
    - De `live` no cuentan:
      - la propia regla (el mismo `code`: el PATCH);
      - las archivadas;
      - las R-xx, que ya están como controles.
11. **Las R-xx nunca reciben issues de contradicción.** Sus condiciones son controles, y la S-NNN que las contradice es la que lleva `regla-contradice-control` con el código de la R-xx. Una R-xx solo podría salir como incompatible. Caso «una R-xx nunca se marca…».

### Análisis

12. **Issues.**
    - Hay uno por cada par `(id, objetivo)`.
    - Van ordenados por `id`, en el orden de la lista de §A3: `regla-incompatible`, `regla-contradice-control`, `regla-contradice-regla`, `regla-inaplicable`, `regla-vacia`.
    - Dentro del mismo `id`, el orden es el de aparición del objetivo: `when`, luego `then`, luego `refs.configs`, luego `refs.edges`.
    - `with` va en el orden de los controles (punto 8) o en el de `rules`.
    - `regla-incompatible`, `regla-inaplicable` y `regla-vacia` llevan `with: []`.
    - Los objetivos se escriben `config:capa.clave`, `config:capa.clave#etiqueta`, `edge:Exx` o `text#frase`: son los nombres de variable de §A3.
    - Casos: «orden de los issues…» y «varias reglas contra el mismo objetivo…».

### Evaluación

13. **Qué reglas se evalúan.**
    - Solo las S-NNN no archivadas. Las R-xx del conjunto se ignoran.
    - Una archivada no se evalúa y tampoco cuenta en `reglas-sin-aplicacion`.
14. **«Comprobadas: …» (hallazgo `reglas`)** lista las S-NNN que cumplen tres cosas: su `when` se cumple, tienen al menos una afirmación evaluable y todas se cumplen.
    - No entran una regla cuyo `when` no se cumple, una de solo texto, una sin condiciones ni una cuya única afirmación cae sobre una variable que el contenido no tiene.
    - Una variable falta cuando falta su capa o su clave en `configs`.
15. **Una regla que está en `reglas-contradictorias` se evalúa igual**, y puede dar además su `regla-<code>`.
16. **Pares de `reglas-contradictorias`.**
    - Van por regla A, en orden, con los pares de control antes que los de regla, como los issues del punto 12.
    - Un par entre dos S-NNN se escribe una vez, desde la primera.
    - El detalle no lleva punto final, y el de `regla-<code>` tampoco: es el literal de §A3.
17. **Valores.**
    - Una opción de `select` que no existe se enseña tal cual («fidelizar»).
    - Varias afirmaciones incumplidas van separadas por `; ` dentro del mismo paréntesis.
    - `layer` es la capa del primer objetivo incumplido; en una arista, la de su puerto destino (E14 → `integrations`, E16 → `memory`).

## Lo que no cubre, y por qué

- **El diff de §A4 (`StudioContentDiff::compare`).**
  - §A9 no lo incluye en este fichero: lo prueba `StudioContentDiffTest` en PHP, y el CRM solo lo pinta.
  - Además, §A4 no fija lo necesario para escribir un `expected` exacto:
    - la forma de entrada de los dos contenidos;
    - el orden de `configs`;
    - qué entra en `fields` y en qué orden;
    - cómo se detecta que una regla cambió;
    - la etiqueta de lo que el catálogo ya no conoce;
    - los `warnings`, que exigen la validación entera de F.
- **`ruleRelations`.** El contrato no da la forma de su salida.
- **Parámetros `text` y `textarea`.** El catálogo real no tiene ninguno, así que el 422 de «tipo sin operadores» no se puede escribir.
- **Mensajes de los 422.** §A3 los deja solo en el servidor, y los textos del análisis los pone el CRM. Abajo va una guía para las pruebas Feature, que no forma parte de la paridad.
- **Ambigüedades que el fichero evita a propósito**, para no fijar una decisión sin base:
  - una variable que falta dentro de un `when`;
  - un valor de `range` del contenido fuera de la rejilla: el normalizador de F no exige el `step`;
  - `forbid` frente a un valor actual que no está en el dominio;
  - la misma frase repetida en dos afirmaciones de texto;
  - mezclar en un caso errores de forma con errores semánticos, que dependería del orden de fases. Sí se fija «todas las claves a la vez» dentro de una misma fase;
  - en `reglas-contradictorias`:
    - el «título» de una arista obligatoria;
    - la etiqueta de una variable de texto;
    - varios pares entre las mismas dos reglas;
  - un mismo elemento incompatible citado en las condiciones y en `refs`;
  - qué issue lleva en el análisis una afirmación imposible o autocontradictoria que no es de arista obligatoria: §A3 no le da `id`.
- **Concurrencia y cerrojos.** No se prueban aquí: van en las pruebas de pila real de §A9.

## Guía de mensajes del servidor (no es paridad)

Son las plantillas que se deducen de §A3, todas bajo `conditions.then.<i>`:

| Contradice | Mensaje | Ejemplo |
|---|---|---|
| Una R-xx | `Contradice <R-xx> (la aplica el código): «<label>» tiene que ser «<valor>».` | `conflicts` «descuentos…» da el literal del contrato para R-02 |
| Una restricción | `Contradice un control del motor («<título>»).` | `conflicts` «hechos…»: «Hechos sin verificar» |
| Una arista obligatoria | `«<label de la arista>» es obligatoria en el motor: no se puede exigir que falte.` | `conflicts` «arista obligatoria…»: E10 |

Cuando chocan una restricción y su R-xx, no está fijado si salen los dos mensajes o uno solo.

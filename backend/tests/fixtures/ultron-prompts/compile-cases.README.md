# `compile-cases.json`: casos de compilación de los prompts de ULTRON (Enmienda M-1)

**Procedencia.** Lo genera el tester, **sin leer `UltronPromptCompiler.php`**, con un guion Node propio que aplica la tabla de parches del contrato sobre la base del repositorio:

- **Contrato:** `docs/ultron-studio/plans/STUDIO_MOTORES_PLAN.md` §B.3, congelado por la Enmienda M-1 del H5 (`ULTRON_STUDIO_H5_CONTRACT.md`, M1-3: «Aprobados los borradores de B.3 tal cual»).
- **Base:** `resources/ultron-prompts/n8n-29de5119/*.txt`.

Es la segunda implementación, independiente, con la que se contrasta la del motor. Ningún `expect` sale de ejecutar el compilador de PHP.

| Fichero | Qué hace |
|---|---|
| `tools/parches-m1.cjs` | Los textos de §B.3 transcritos a mano, las anclas, las dos operaciones, el orden, los límites y la fórmula de la huella. Es puro. |
| `tools/derivar-compile-cases.cjs` | Escribe el fixture; con `--comprobar` sale 1 si el del disco no es el que sale hoy. |
| `tools/verificar-selector.cjs` | E3: ejecuta el selector «Elegir Prompts» de n8n en `vm` contra estos casos. |

```bash
node backend/tests/fixtures/ultron-prompts/tools/derivar-compile-cases.cjs              # regenera
node backend/tests/fixtures/ultron-prompts/tools/derivar-compile-cases.cjs --comprobar  # ¿está al día?
node backend/tests/fixtures/ultron-prompts/tools/verificar-selector.cjs [elegir-prompts.js | export.json]
```

**Antes de escribir nada**, el guion se niega a seguir si algo de esto falla:

1. Cada texto transcrito aparece **literalmente** en §B.3 del plan: entre `«…»`, con `N` en lugar del número; el reemplazo del Critic, entre comillas invertidas. Cada ancla y la fórmula de la huella, también.
2. Cada `<rol>.txt` es, byte a byte, `responses.values[0].content` de su nodo en el respaldo del flujo vivo (`n8n/respaldos/20261111-ultron-29de5119/ultron-commercial-advisor.json`, `versionId` 29de5119) y `<nodo>.system` de `docs/marketing/prompts/2026-09-22-voz/nuevo4.json`.
3. Compilar la línea base devuelve la base (sin parches); el resultado no depende del orden de las claves; las 306 huellas son distintas entre sí; los 26 inválidos son inválidos.

La prueba que lo usa es `tests/Unit/Marketing/Ultron/Prompts/UltronPromptCompileCasesTest.php`. Ejecuta además el `--comprobar` y el verificador del selector, así que necesita Node en el PATH, como E10.

## Compilador 2 (11-11-2026, reglas S-NNN en WhatsApp)

Desde el compilador 2 lo gobernado lleva una quinta clave, `rules.forbiddenPhrases` (las frases que prohíben las reglas S-NNN de la versión; opción A de `plans/cierre/auditoria-a-studio-whatsapp.md` §3.2). Su parche no es texto congelado de §B.3, así que este guion **solo deriva la lista vacía**, que no aplica ningún parche: los 306 casos llevan `"rules.forbiddenPhrases": []` y sus `expect` son **byte a byte los del compilador 1** (comprobado al regenerar: 306/306 iguales; Legacy y `profesional + 200` sin cambios). Se añadieron 4 inválidos (falta la clave —la forma del compilador 1—, nula, texto y objeto). Las listas no vacías las prueba `tests/Unit/Marketing/Ultron/Prompts/UltronPromptCompilerPhrasesTest.php`; cuando el lead congele su texto en el contrato, este guion puede derivarlas también.

## Compilador 3 (12-11-2026, Enmienda A4-1: el margen del tope para el redactor)

Solo cambia el texto de `maxChars` para Composer y Composer Reintento: el del apartado «Compilador 3» de §B.3 (sustituye al del compilador 1–2, que sigue en la tabla de §B.3 como histórico). Lleva `{M}`, `{P}` y `{F}` además de `{N}`; el guion los calcula **por su cuenta** (`margen()` en `parches-m1.cjs`, con `Math.floor`) y, antes de escribir nada, exige que las tres fórmulas y las 16 filas de la tabla N → M, P, F estén escritas tal cual en §B.3, además del texto (con sus letras). Al regenerar: **288 casos cambian** (los de `maxChars` ≠ 320: 16 × 18) y **18 no** (los de 320, con la línea base: byte a byte los del compilador 2); cambian los 16 textos `maxChars.composer@N` y ningún otro; los 30 inválidos, iguales. Legacy no se mueve; `profesional + 200` pasa de `702890f7…311c` (113 672 B) a `af9e0d23…4d51` (114 206 B).

## Forma

- `contract`, `base`, `compiler`, `roles` (orden canónico), `baseline`, `limits`: el marco, y la prueba lo ata a las constantes publicadas del código.
- `baseRoles.<rol> = {sha256, bytes}`: la base. `legacyBundleHash`: la huella de §B.3 sobre la base.
- `texts.<clave> = {parameter, value, text}`: cada texto de parche con `N` ya sustituido (`maxChars.composer@200`, …).
- `cases[]`: **los 306 valores válidos** (3 tonos × 2 emojis × 17 `maxChars` × 3 objetivos), uno por línea:
  - primero la línea base (`c000`), después los 21 de un solo parámetro cambiado y después las combinaciones (88 de dos, 132 de tres, 64 de cuatro);
  - cada caso lleva `governed`, `changed` (parámetros distintos de la línea base) y `changedRoles` (en orden canónico);
  - `expect` lleva:
    - `bundleHash`;
    - `bytes` (la suma de los cinco roles en UTF-8);
    - `roles.<rol> = [sha256, bytes]`, **solo de los roles que cambian**: los demás tienen que ser la base, y la prueba lo comprueba igual;
    - `patches = [[rol, operación, ancla, clave en texts], …]` en orden de aplicación.
- `invalid[]`: 26 entradas que el contrato no admite, que deben dar `invalid_governed` en `compile()`, `patches()` y `validate()`:
  - `maxChars` fuera de la rejilla, de los límites o con tipo equivocado;
  - `tone` y `goal` con mayúsculas, espacios o desconocidos;
  - `emojis` que no es booleano;
  - claves que faltan, que sobran o mal escritas;
  - `{}`.

## Lecturas del contrato que fija el guion

Todas coinciden hoy con el compilador de PHP. Si alguna no es la intención del propietario, cambiarla es un cambio de contrato (compilador `VERSION` 2 y nueva publicación), no de esta prueba.

1. **`insert_after_line`** («inserta una línea nueva tras la línea que contiene el ancla») inserta `"\n" + texto` justo antes del salto de línea que cierra la línea del ancla. Si el ancla está en la última línea, añade al final; con la base de hoy no ocurre.
2. **Varios parches sobre la misma ancla** «se aplican en el orden de los parámetros» (`tone`, `emojis`, `maxChars`, `goal`), cada uno sobre el resultado del anterior. **Consecuencia:** dos inserciones tras la misma línea quedan en orden **inverso** al de aplicación. En `critic` y `critic_retry`, con tono distinto de `cercano` y sin emojis, el texto queda así: `naturalness: …` → `EMOJIS: …` → `REGISTRO PUBLICADO: …`. La otra lectura («el texto final en el orden de los parámetros») daría otro paquete.
3. **`naturalness:` y `progressive_disclosure:`** («a principio de línea»; del segundo, «la línea que empieza por»). El guion las busca como subcadena y como principio de línea, y exige que las dos lecturas den una sola línea, la misma. Hoy coinciden; si un día no, el guion falla en vez de elegir.
4. **`maxChars`:** 17 valores (160–480, paso 20). El 320 es la línea base, así que no lleva parche. En el Critic se aplica primero el reemplazo de `mas de ~900 caracteres` y después la inserción tras `progressive_disclosure:` (el orden de la celda). Para N ≠ 900 el resultado es el mismo en cualquier orden.
5. **El orden ENTRE roles** dentro de un parámetro no lo fija el contrato (los roles no interactúan): la prueba compara los parches rol a rol.

## Cifras de control (cruces independientes)

| | Este guion (Node `crypto`) | Otras fuentes |
|---|---|---|
| Huella Legacy | `3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902` | `UltronPromptBase::LEGACY_BUNDLE_HASH`; Python `hashlib` (B-M) |
| V2 de aceptación (`profesional` + `200`), compilador 3 | `af9e0d23de42368f1cda211b03dc3164ce1a341acc7e6f22abf3d71afbdb4d51`, 114 206 B, 10 parches | `UltronPromptCompiler` de PHP (`UltronPromptCompilerPhrasesTest`); con los compiladores 1 y 2 era `702890f7d86a27fef027abd672a99903e64b6661c2d8f8a72a45ddea3d6d311c`, 113 672 B (informe de B-M, `api-compilador.md` §4) |
| Máximos (compilador 3) | Rol más grande: 29 570 B (`strategist` con `goal=venta`), con tope de 40 960. Paquete más grande: 114 721 B, con tope de 163 840. Texto de parche más largo: 487 B (`maxChars.composer`), con tope de 1 200. Hasta 15 parches por caso. Con el compilador 2: 114 187 B y 326 B | §B.3, «Límites» |

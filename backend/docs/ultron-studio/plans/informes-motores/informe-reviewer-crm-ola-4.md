# Informe reviewer · CRM ola 4: frases de las reglas en los prompts (compilador 2) y cierre del H9

**11-11-2026 · `reviewer` · solo lectura.** Árbol `_worktrees/ultron-h8/Iron-Body_Front`, rama `feat/ultron-studio-h9`, HEAD `fd02a4c` + 35 ficheros sin commit (`git status`: ningún fichero sin seguimiento). No edité código, no hice commit ni toqué producción. No ejecuté nada del backend (ni `php artisan` ni phpunit). Lo único que escribí es este informe; las sondas están en el scratchpad de la sesión.

## Veredicto

**APROBADO CON OBSERVACIONES.** Ningún bloqueante. Hay dos menores y una nota de despliegue.

- El lector es estricto y falla cerrado. Toda vista previa con error del paquete queda no publicable.
- Con el servidor de producción de hoy (compilador 1), la vista previa y los textos son los de antes, literales. Lo comprobé con el fixture REAL que exportaba el servidor en `HEAD`, no solo con los escritos a mano.

## Verificado (salida real)

**Specs** (una cada vez, `nice -n 10 npx ng test --watch=false --include=…`):

| Spec | Resultado |
|---|---|
| `studio-prompts-parse.spec.ts` | 33/33 |
| `studio-publication-dialog-prompts.spec.ts` | 23/23 |
| `studio-publication-parity.spec.ts` (sin cambios en el diff; compara con `toEqual` la vista previa exportada con `promptsVistaDto()`) | 10/10 |
| `ca-activation.spec.ts` | 44/44 |
| `h9-fixtures.spec.ts` (con `IRON_BODY_BACKEND_DIR`; no salió el aviso «sin árbol del backend») | 8/8 |

**Sonda 1: compatibilidad con producción.** Empaqueté con esbuild el `studio-parse.ts` del árbol y lo alimenté con `git show HEAD:…/testing/backend-publication.testing.ts`, que es la forma del servidor del compilador 1:

- `preview`, `previewBlocked` y `previewPrompts` se leen.
- `previewPrompts` → `compiler: 1`, `phrases` ausente, `carried` sin `phrases`, publicable.
- `publishText` = `TEXTO_PUBLICAR_PROMPTS` (el de antes, literal).
- `noEffectText(...).rule` = «Las reglas del Studio no tienen efecto en WhatsApp.»
- Con el fixture regenerado del árbol (compilador 2, sin frases): también se lee, publicable, con el texto de las frases.

**Sonda 2: falla cerrado.** Doce mutaciones sobre los fixtures del autor. Se rechazan:

- un valor del cambio con un espacio al final;
- una frase de más en `phrases.list`;
- una regla que aporta frases y va en `noEffect`;
- lo arrastrado que no cabe, marcado como publicable y sin bloqueo;
- lo arrastrado que no cabe con `error: null`;
- lo arrastrado que no cabe con huella;
- «no caben» con el bloqueo de otro código;
- «no caben» con `error: prompts_compile_failed`;
- `phrases` con el compilador 1;
- `carried.phrases` con `baseline: true`.

Se aceptan, como está diseñado, la vista previa con frases y la del compilador 2 sin `carried.phrases` (ver menor 2).

**Fixtures del H9.** Comparé byte a byte las 6 copias con el árbol del backend y con su `manifest.json`: las 6 coinciden (`c397b1c2`, `4e2b63d5`, `ae0eae27`, `7ece0bf9`, `f9e38189`, `c8695df6`), con el catálogo `outreach-catalog/2026-11-11.1`.

**`backend-publication.testing.ts` (GENERADO).**
- Los 6 ficheros generados tienen el mismo sello (17:34:26).
- `engineMessagesPrompts.canary` y `.all` son idénticos a `StudioPublications::ENGINE_MESSAGES_PROMPTS` del árbol.
- El diff se limita a `compiler`, los mensajes, `previewHash`, `carried.phrases: []` y `phrases`: es lo que produce el generador.
- No veo edición a mano.

**No ejecuté** `tsc`, `check-literals` ni los dos módulos enteros (1 979): me fío de la salida del autor y lo indico.

## Bloqueantes

Ninguno.

## Importantes

Ninguno.

## Menores

### 1. Nombre de la versión en el 409 `part: carried`

**Dónde:** `ultron-studio/studio-publication-dialog.ts:404`.

**Problema:** la línea «La versión que hoy rige el resto es «X».» toma `X` de `this.preview()?.prompts?.carried?.label` sin comprobar que esa parte sea la que no cabe. Hay que precisar que no es la vista previa vieja: tras un 409, `escribir` espera a `renovarTrasFallo`, que vuelve a pedir la vista previa antes de devolver el error (`studio-session.ts:1346`). La línea usa la vista previa recién pedida.

**Escenario de fallo** (carrera doble, improbable):
1. El 409 `carried` se debe a un resto `/1` con frases que no caben.
2. Entre el 409 y la nueva lectura, la cabeza vuelve a moverse («Volver a Legacy» y otra publicación promovida).
3. La vista previa nueva trae un `carried` que sí compila, o el de otra versión.
4. El diálogo atribuye el problema a esa versión. El bloque de la vista previa nueva dice otra cosa.

**Corrección mínima:** añadir la línea solo si `carried?.problems?.length`; si no, solo los problemas.

**Para:** `frontend`.

### 2. `carried.phrases` es opcional también con el compilador 2

**Dónde:** `ultron-studio/studio-parse.ts:868` y `:888`.

**Problema:** con el compilador ≥ 2, `leerArrastrada` acepta una parte arrastrada que compila y viene sin `phrases`, y el diálogo no enseña nada de lo que arrastra. E-1 («nadie publica lo que no vio») depende entonces de que el servidor siempre lo mande.

**Por qué es menor:**
- El backend final siempre lo manda (`StudioPublications::promptsDto()`: `'phrases' => $arrastrada['phrases'] ?? []`).
- La forma de la primera vuelta, sin ese campo, nunca se ha hecho commit ni desplegado.

**Escenario de fallo:** solo si se despliega un backend del compilador 2 sin la vuelta 2. Una parte del resto `/1` con frases se arrastraría sin verse, y se reabriría B-1.

**Corrección mínima, si el lead la quiere:** con `conFrases`, exigir `phrases` en `carried`. Rompe la prueba `promptsVistaSinVuelta2Dto`, que hoy fija lo contrario.

**Para:** decisión del lead; la implementaría `frontend`.

## Notas

### Lo que revisé y está bien

**Lector de la vista previa** (`studio-parse.ts`):
- `phrases` viene si y solo si el compilador es ≥ 2.
- El cambio `rules.forbiddenPhrases`:
  - va el último;
  - su valor es idéntico, elemento a elemento y en el mismo orden, a `phrases.list`;
  - lleva de 1 a 12 frases, sin repetir y de ≤ 60 caracteres;
  - su parche tiene un tope de 2 048.
- `error ⇔ blocking` con el mismo código, comprobado para cada código. Además, `publishable ⇔ blocking` vacío, que ya existía. Así, un error del paquete siempre deshabilita el envío: `motivoEnviar` usa `p.publishable`, y `session.publish` lo vuelve a comprobar.
- Las tres invariantes del contrato final (§2 de la vuelta 2) están codificadas como el backend las enuncia.
- La forma de lo arrastrado que no cabe es estricta: huella, huella corta y `baseline` a `null`, y `problems` con al menos uno.
- Comprobé en el backend que las reglas que aportan frases pasan a `classify` aunque sus frases no quepan (`StudioPublications.php:1286`). La regla del CRM «una regla con frases no puede ir en `noEffect`» no rompe el caso «no caben».

**Lo que se pinta es lo que se firma:**
- La lista se pinta desde `c.value` (que ha de ser igual a `phrases.list`), las reglas desde `phrases.rules` y los parches desde `c.patches`. Todo es interpolación, sin transformar nada (ni recortes, ni ordenación propia, ni `slice`).
- El único `slice` nuevo está en el texto fijo del motivo `too_many`.
- En `studio-prompts-changes.scss` no hay `line-clamp`, `text-overflow`, `max-height` ni `nowrap`. `.ibd-body` y `.pc-scroll` desplazan. No lo vi en un navegador.

**XSS:** ningún `innerHTML`, `bypassSecurityTrust*` ni `[innerHtml]` en el diff ni en los dos módulos. Las frases y las etiquetas van por `{{ }}`, y hay prueba con `<img onerror>`.

**Textos:**
- `rulePhrasesReading` = `prompts` solo con la llave en `canary` **y** el compilador ≥ 2; ilegible, no afirma nada; en el resto, lo de siempre. Tiene pruebas en `off`, en `canary` con el compilador 1 y el 2, y con la llave ilegible.
- La vista previa decide por la presencia de `phrases`.
- Comprobé en `StudioRulePhrases::byRule()` que «viva» significa «no archivada». Por eso el texto «está archivada, no prohíbe frases o su «si» no se cumple» es exacto.
- No hay fechas ni recuentos de datos fijos. Los números del contrato (12 y 3–60) son los del mensaje del servidor, y el lector ya exige `max === 12`.

**`personBindings`:**
- Comprobé el refactor `recuentoDelPanel` en el código: `dosPersonasVinculadas() === null` implica `personasVerificadas() === null` (`ca-activation.ts:146`, `:195`). Con un servidor anterior, las dos filas dan exactamente el texto, los huecos y las fuentes de antes.
- Con `persons`, las dos filas usan el mismo número de personas distintas.
- Con solo `verified`, se habla de «vínculos», nunca de «personas».
- El backend siempre manda el objeto (`OutreachReadModels.php:73`), nunca `null`.

**Pruebas:**
- Las aserciones eliminadas se sustituyen por la variante del compilador 2 y una hermana explícita del compilador 1: `TEXTO_PUBLICAR_PROMPTS`, «compilador 1», la nota de reglas, la insignia y `composer`, que sale del bucle de capas y gana su propia prueba.
- La versión y la huella del catálogo se actualizan a las del fixture.
- No vi dobles que sustituyan la clase real. La traza en sombra usa `explainDecision` y la escalera del servidor.

**Alcance:** no hay dependencias nuevas ni cambios de maquetación fuera de tres reglas de estilo para las filas nuevas. Los tres textos de la «tanda anterior» (nota de «Motor ahora» y «Motor ahora» de Construcción de respuesta) no estaban en mi encargo. El autor los declara pedidos, pero no pude confirmarlo contra el encargo original.

### Preexistente (no lo introduce este diff)

Con un servidor sin `personBindings.persons` y la lista de vínculos legible, `dosPersonasVinculadas` y `personasVerificadas` siguen contando vínculos como «personas»: una persona con dos cuentas cuenta como dos. Con el backend del H9 desplegado manda `persons` y deja de pasar. El autor lo declara en su §5.2.

### Despliegue

- **El CRM puede salir antes que el backend:** lo comprueba la sonda 1.
- **El backend no debe salir antes que el CRM** si va a haber reglas con frases o un resto `/1` con reglas. El CRM de hoy (`fd02a4c`):
  - rechaza una vista previa con frases (falla cerrado);
  - ignora `carried.phrases`, así que no enseñaría lo que se arrastra (B-1 y E-1).
- Es el orden que ya piden `informe-be-reglas.md` §4 y la condición E-1 de `security`.

### Lo que NO pude verificar

- La pantalla en un navegador: la fila de frases, el peor caso de 12×60, los problemas y la parte arrastrada, en estrecho y en ancho.
- `build:prod`.
- Un servidor real, también el 409 `carried` por HTTP.
- `tsc` y la batería completa de los dos módulos.
- La regeneración de `backend-publication.testing.ts` con `php artisan`, que no ejecuté por la batería que corre en el backend. Lo comprobé por contenido y sello, no regenerando.

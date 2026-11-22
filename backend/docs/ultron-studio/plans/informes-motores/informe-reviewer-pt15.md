# Informe del reviewer · PT-15 (las dos condiciones previas a ampliar WhatsApp)

**22-11-2026 · rama `cierre/planes-condiciones`, punta `2f37a2cb`, base de producto `f1fe6cac` · una pasada.**
**Marcas:** **[C]** comprobado ejecutando · **[L]** leído en el código · **[S]** supuesto, sin comprobar.

## Veredicto

**APROBADO CON CONDICIONES. 0 bloqueantes · 9 no bloqueantes.**

Nada de lo que encontré hace inseguro desplegar el cambio ni rompe un comportamiento existente:
- las frases de las pruebas reales siguen recibiendo la lista;
- Legacy da lo mismo byte a byte;
- la puerta del secreto no cambia;
- en las rutas, lo único que cambia es el limitador de `ai/check`.

Las condiciones son de proceso, no abren otra ronda de código:
1. **Al desplegar**, comprobar a mano el middleware de `ai/check` (N-7). El guion genérico de lotes no lo ve.
2. **Antes de dar C-1 por cerrada como condición para ampliar**, dejar escrito en A46-7 que los casos de C-1 están cerrados pero la clase de defecto no (N-1 y N-2). La decisión de ampliar es del lead o del propietario.

## Cómo verifiqué

- **Sonda de la función real [C].** Un script en mi scratchpad carga el `CommercialTurnPolicy` de la punta y una copia renombrada (`CommercialTurnPolicyOld`) del de `f1fe6cac`, sacada con `git show` a la carpeta temporal y sin tocar el árbol. Llama a `pideElPanorama()` con la política de la prueba unitaria. Cada línea sale como `antes ahora texto`: `L` = lista, `-` = sin lista.
- **Contra el código anterior [C].** Para las pruebas de rutas, de Legacy y de R-2 seguí el procedimiento acordado: copiar el fichero actual al scratchpad, poner el de `f1fe6cac`, ejecutar y restaurar con un `trap`. `git status --porcelain` salió vacío después de cada paso y al final.
- **Un solo PHPUnit cada vez.** No ejecuté la batería completa.

| Qué | Resultado |
|---|---|
| 8 clases sobre la punta: `CommercialTurnPolicyPanoramaTest`, `UltronA4RegistroYPanoramaTest`, `LimitadoresInternosTest`, `UltronCheckEndpointTest`, `UltronCheckCommitParityTest`, `UltronA4NotasDelServidorTest`, `InternalSecretAuthoritySurfaceTest`, `UltronRegenerationCommitTest` | `OK (462 tests, 3182 assertions)` [C] |
| `LimitadoresInternosTest` con rutas, proveedor y configuración de `f1fe6cac` | `Tests: 6, Assertions: 13, Failures: 2`: caen las dos primeras `test_r2_*` con `Failed asserting that 429 is identical to 422`. **La tercera pasa también con el código de antes** (N-5) [C] |
| `test_c1_legacy…`, `test_c1_lo_que_no_pide…` y `test_c1_las_frases…` con el `CommercialTurnPolicy.php` de `f1fe6cac` | `Tests: 15, Failures: 10`. Caen las 10 entradas de C-1 por el endpoint: el rojo previo es real. **La de Legacy y las 4 frases reales pasan** [C] |
| `php artisan route:list --json`, punta frente a `routes/marketing.php` de `f1fe6cac` | 1046 rutas en las dos, ninguna sola en una. Una sola diferencia: `POST api/internal/marketing/ai/check`, middleware `[api, automation.internal, throttle:internal-marketing-ai]` → `[…, throttle:internal-marketing-ai-check]`. Misma acción y mismo nombre. Ninguna ruta interna con comodín que pueda capturar `ai/check` [C] |
| `pint --test` sobre los 7 ficheros | Pasan `CommercialTurnPolicy.php`, `routes/marketing.php` y las 3 pruebas. Avisan `AppServiceProvider.php` y `config/automation.php`, pero sus versiones de `f1fe6cac` dan **exactamente los mismos fixers**: es anterior a este cambio [C] |
| Las clases que llaman a `ai/check` con `MARKETING_AI_CHECK_RATE_LIMIT_PER_MINUTE=30`, y con `=5` para confirmar que la variable se lee | Con 30: `OK (320 tests)`. Con 5 caen 2: la variable sí se lee (N-6) [C] |
| `git diff --numstat f1fe6cac..HEAD -- backend/tests` | Solo líneas añadidas: 68/0, 141/0, 154/0, 3338/0 [C] |
| Producto fuera de los dos commits | `git diff f1fe6cac..084dd977~1 -- backend/app backend/routes backend/config backend/tests backend/database n8n` sale vacío [C] |

## Respuestas a las preguntas

### 1. Las frases de las pruebas reales siguen recibiendo la lista → sí [C]

Con `general_info` y con `pricing_question`:

```
L L   ¿Qué planes tienen y cuánto cuesta la mensualidad?
L L   ¿Qué planes tienen?
L L   ¿Qué planes tienen y cuánto cuestan?
L L   ¿Cuáles son los precios?
```

- Por el endpoint, `test_c1_las_frases_de_las_pruebas_reales_siguen_recibiendo_la_lista`: verde con el código nuevo y con el anterior.
- Las 10 formas de R2 del revisor anterior (`informe-reviewer-a4b.md:487-490`) también siguen dando `L L` [C].
- Las 27 de `siguenSiendoPanorama` dan `L L` las 27 [C].

### 2. Los casos de C-1 ya no reciben la lista → sí [C]. Hay casos legítimos que la pierden (N-3)

- **H1 a H9** y las demás de `informe-reviewer-a4b.md:494-508` dan `L -` todas, con las dos intenciones.
- **Las pruebas nuevas:** las 13 de `caracteristicasDeLosPlanes` y las 28 de `otroTemaC1` daban `L` con el código de antes y dan `-` ahora. La afirmación del docblock («Todas daban la lista antes») es cierta [C].
- **Casos legítimos que ahora se quedan sin lista:** van en N-3. Ninguno es una frase de prueba real y todos caen en el camino normal (el modelo). Lo más frecuente, y lo que más se parece a la frase real:
  - la coma en lugar de «y»: «¿Qué planes tienen, cuánto cuestan?»;
  - «¿Qué planes tienen y cuánto **es** la mensualidad?».

  **Gravedad:** sin lista, el turno vuelve al defecto de H4-10, el modelo cotizando UN plan. No hay riesgo: no se ejecuta ninguna acción ni sale ninguna promesa. Pero para esas formas se pierde la mejora de la lista.

### 3. ¿Se aflojó alguna aserción? → no [C]

- `git diff f1fe6cac..HEAD -- backend/tests` no borra ni cambia ninguna línea. Todo son añadidos: tres métodos de `LimitadoresInternosTest`, dos líneas en su `setUp` y las pruebas y la instantánea de C-1.
- El `setUp` añade `marketing_ai_check = 3` y su `clear()`. No toca las tres pruebas que ya había, que siguen pasando.

### 4. ¿La prueba de Legacy mide de verdad que Legacy no cambia? → sí [C]

- **La instantánea es la salida anterior.** Con el `CommercialTurnPolicy.php` de `f1fe6cac`, `test_c1_legacy_no_cambia_byte_a_byte` pasa; con el de la punta, también.
- **Mide cosas con poder de discriminación.** Por cada una de las 14 entradas, en los dos modos («sin negociar» y «prompts del Studio apagados»), guarda:
  - la política entera de `decide` (17 claves);
  - si el token firma `po`;
  - el estado y el cuerpo de `ai/check` (200, `mode: legacy`);
  - la huella del commit, aprobado y con el revisor caído: `reply_final`, `outcome`, el saliente, las claves de la fila, las violaciones y la memoria.

  Si Legacy recibiera la lista, `reply_final` pasaría de `C1_BORRADOR` a `PRECIOS_200` y la prueba caería.
- **La razón de fondo [L].** Las tres llamadas a `pideElPanorama()` exigen el Studio:
  - `UltronDecideService.php:312-313`: `sp.m === studio && sp.o === 1`;
  - `UltronCommitService.php:1820` y `:2390-2391`: `$turno['studio']`.

  Las constantes nuevas o cambiadas solo se usan dentro de `pideElPanorama()`. Lo comprobé con `grep` por `self::` y fuera de la clase.
- **Límite:** cubre esas 14 entradas, no todas las frases posibles. La garantía general viene de esas guardas [L].

### 5. Condición 2 → correcta [C]

- **Rutas:** ver la tabla. Solo cambia el limitador de `ai/check`.
- **Las tres `test_r2_*`:**
  - `test_r2_las_comprobaciones_no_gastan_el_cupo_de_decide_ni_del_commit` mide lo que dice: con el cubo común, el `decide` posterior da 429 [C].
  - `test_r2_decide_y_commit_no_gastan_el_cupo_de_la_comprobacion` mide lo que dice: con el cubo común, el `check` posterior da 429 [C].
  - `test_r2_la_comprobacion_tiene_su_propio_tope` **no** mide ni «propio» ni «configurable»: pasa con el código de antes (N-5).
- **Nada más dependía del cubo compartido:**
  - ninguna prueba ni guion espera un 429 de `commit` provocado por `check` (`git grep` de 429, `RateLimiter` e `internal-marketing-ai`) [C];
  - n8n tolera el 429: `Comprobar Borrador 1` y `2` llevan `fullResponse` + `neverError: true` + `onError: continueRegularOutput`, y `leer-comprobacion-1.js:32` y `leer-comprobacion-2.js:32` hacen `statusCode !== 200 → noReescribir()`. Leído en el fichero del flujo, sin ejecutar n8n [C sobre el fichero].
- **Dos dependencias indirectas:** seis clases de prueba suben solo `marketing_ai` (N-6), y los guiones de despliegue no miran el middleware (N-7).

### 6. Calidad → N-1, N-2, N-4, N-8 y N-9

## Bloqueantes

Ninguno.

## No bloqueantes

**N-1 · importante · `CommercialTurnPolicy.php:151-165` y `:1195`: otra pregunta ANTES de la petición, en la misma frase, sigue tapada por la lista. Es la clase de C-1 con el orden invertido.**
- **Qué pasa.** La cola solo restringe lo que va detrás de la petición. En `otraPreguntaEnElMensaje()`, `PIDE_PANORAMA` no está anclada, así que una frase que *contiene* la petición cuenta como «la propia petición».
- **Escenario [C]:**
  ```
  L L   ¿Tienen sauna y qué planes manejan?
  L L   ¿Es seguro y qué planes tienen?
  L L   ¿Abren los domingos y qué planes tienen?
  L L   ¿Tienen piscina y cuáles son los precios?
  L L   ¿Aceptan menores y qué planes tienen?
  L L   ¿Hay entrenador y cuáles son los precios?
  ```
  Partidas en dos frases sí se ven: `L -  ¿Tienen sauna? ¿Qué planes manejan?`. Y «¿Qué planes tienen? ¿Es seguro?» es un caso literal de C-1 que ya no da la lista, pero «¿Es seguro y qué planes tienen?» sí la da.
- **No es regresión:** antes también daba `L`.
- **Corrección sugerida (backend):** aceptar la frase solo si lo que precede a la coincidencia de `PIDE_PANORAMA` es saludo, conector o una lista cerrada de verbos de petición («quisiera saber», «me puedes decir»…). Con su prueba.
- **Alternativa:** documentarlo en A46-7 como hueco conocido antes de ampliar.

**N-2 · importante · `CommercialTurnPolicy.php:205` y `:209` (`PRECIO_A_SECAS`): el objeto del precio es libre, una o dos palabras cualesquiera, así que la lista tapa el precio de otra cosa.**
- **Escenario [C]:**
  ```
  L L   ¿Qué planes tienen y cuánto cuesta el entrenador personal?
  L L   ¿Qué planes tienen y cuánto cuesta el parqueadero?
  L L   ¿Qué planes tienen y cuánto vale la valoración?
  L L   ¿Qué planes tienen y cuánto vale la nutricionista?
  L L   ¿Qué planes tienen y cuánto cuesta el spinning?
  L L   ¿Qué planes tienen? ¿Cuánto cuesta el parqueadero?
  L L   ¿Cuánto cuesta el entrenador personal? ¿Qué planes tienen?
  ```
- **Por qué importa.** En la forma de dos frases, la red nueva da por buena «¿cuánto cuesta el parqueadero?» como «pregunta de precio a secas» (`PREGUNTA_DE_PRECIO_A_SECAS`, `:212`). Además, el docblock de `COLA_DEL_PANORAMA` (`:180-182`) promete «Cualquier otra palabra antes del final … deja la petición sin valor», y para el objeto del precio no es así.
- **No es regresión** (antes, `L`).
- **Corrección sugerida (backend):** cerrar el objeto a la mensualidad, «cada uno/plan», los sustantivos de la lista y un plan nombrado del catálogo (eso ya lo sabe `plansNamedIn()`), o corregir el comentario y documentar el hueco.

**N-3 · importante · `CommercialTurnPolicy.php:184-188` y `:205-209`: variantes legítimas y frecuentes que AHORA se quedan sin lista (antes `L`, ahora `-`) [C].**
- **La coma en lugar de «y»:**
  ```
  L -   ¿Qué planes tienen, cuánto cuestan?
  L -   que planes tienen,cuanto cuestan
  ```
  Con «, y» sí funciona.
- **Verbos de precio que faltan:**
  ```
  L -   ¿Qué planes tienen y cuánto es la mensualidad?
  L -   … y cuánto es?
  L -   … y cuánto sería?
  L -   … y cuánto me sale?
  L -   … y en cuánto sale la mensualidad?
  L -   … y cuál es el precio de la mensualidad?
  L -   … y cuanto es el valor de la mensualidad?
  L -   … y cuánto cuesta cada plan?
  ```
  `PREGUNTA_PRECIO` (`:294`), en la misma clase, ya admite `cuanto (es|son|seria|serian)`. `PRECIO_A_SECAS` es una segunda lista, más estrecha, del mismo concepto.
- **Lo que la lista sí contesta:**
  ```
  L -   Cuánto valen los planes mensuales?
  L -   Cuánto cuestan los planes al mes?
  ```
  `PRECIOS_200` dice «Algunos planes de un mes».
- **El caso de uso documentado de «qué otros hay» con un plan nombrado:**
  ```
  L -   ¿Qué otros planes tienen aparte del mensual?
  L -   ¿Y qué otros planes hay además del mensual?
  ```
- **Menos frecuentes:**
  ```
  L -   Hola, ¿qué más? ¿Qué planes tienen?        (saludo colombiano, falta en SALUDO_QUE_PREGUNTA, :215)
  L -   Tengo 30 años, ¿qué planes tienen?         (\d{1,2}\s+anos, :256)
  L -   ¿Qué planes tienen en esta sede?
  L -   Qué planes tienen para mí?
  L -   Quiero información de los planes y precios, soy nuevo
  ```
- **Gravedad:** el turno va al modelo, el camino normal; no hay riesgo, solo se pierde la mejora de H4-10 para esas formas.
- **Corrección sugerida (backend), cada caso con su prueba:**
  - aceptar `[,;]` sin «y» antes de la pregunta de precio de la cola;
  - igualar los verbos de `PRECIO_A_SECAS` a los de `PREGUNTA_PRECIO` y añadir `en cuanto`, `me sale` y `cada plan`;
  - aceptar `mensuales|al mes` en la cola;
  - añadir `que mas` al saludo.

  Cuidado con N-2 al ampliar el objeto.

**N-4 · menor · `ULTRON_STUDIO_H5_CONTRACT.md:516` (A46-3): contradice la prueba.**
- A46-3 dice que «la frase larga informal de la prueba de paridad» sigue recibiendo la lista.
- `test_c1_la_frase_larga_con_no_se_cual_me_sirve_no_cambia` afirma que NO la recibe, ni antes ni ahora. Yo también lo veo así:
  ```
  - -   oye cuentame de los planes q tienen y cuanto valen, es q quiero empezar ya mismo y no se cual me sirve mas
  ```
  El motivo es el «no» de `NO_PIDE_EL_PANORAMA`.
- **Corrección (lead, documento):** quitar esa frase de A46-3 o decir que queda fuera por la recomendación.

**N-5 · menor · `LimitadoresInternosTest.php:174-177` (`test_r2_la_comprobacion_tiene_su_propio_tope`): no mide «propio» ni «configurable».**
- Con el código de `f1fe6cac` pasa [C]: los dos cubos están a 3 en `setUp`, y 8 comprobaciones dan 5 rechazos con el cubo común igual que con el propio.
- **A confirmar [S, no lo ejecuté]:** un limitador que leyera `marketing_ai` en vez de `marketing_ai_check` pasaría las tres `test_r2_*`.
- **Corrección (tester):** en esa prueba, `marketing_ai_check` distinto de `marketing_ai` (por ejemplo 2 → 6 rechazos).

**N-6 · menor · seis clases suben a 100000 solo `marketing_ai`, «el limitador no es lo que se prueba aquí», y no `marketing_ai_check`.**
- Las líneas: `UltronCheckEndpointTest.php:155`, `UltronCheckCommitParityTest.php:196`, `UltronA4NotasDelServidorTest.php:131`, `UltronRegenerationCommitTest.php:121`, `UltronA4RegistroYPanoramaTest.php:167` y `UltronA4ListaDelModeloTest.php:119`.
- Sus `ai/check` pasaron de un tope efectivo de 100000 a 60. Hoy sobra: con 30 por minuto pasan todas [C], y `test_c1_legacy_no_cambia_byte_a_byte` hace 28 comprobaciones en una sola prueba.
- **El riesgo:** una prueba futura con más de 60 daría 429, y la instantánea de Legacy, que guarda el estado de `ai/check`, podría congelarlo.
- **Corrección (tester):** subir también `marketing_ai_check` en esos `setUp`.

**N-7 · menor, para despliegue · `ops/deploy-cierre-lote.sh:426-436` (`rutas_canon`): compara solo «MÉTODO uri».**
- Si PT-15 se despliega con ese guion, la diferencia de rutas sale vacía y nada comprueba que `ai/check` lleve `throttle:internal-marketing-ai-check`. Tampoco detectaría una caché de rutas vieja [C, leído y razonado].
- La comprobación manual que piden el informe de backend y A46-4 es **obligatoria**, no opcional.
- **De paso:** `ops/deploy-a4-regenerar-backend.sh:119` marcaría `check=mal:sin_limitador` si se volviera a ejecutar. Sus precondiciones («route:list … SIN check») lo impiden, así que solo lo dejo anotado.
- → devops / lead.

**N-8 · menor · `CommercialTurnPolicy.php:1178-1185` (docblock de `otraPreguntaEnElMensaje()`): el comentario dice más de lo que hace el código.**
- Dice «no es la propia petición de la lista (PIDE_PANORAMA)»; el código comprueba «contiene una petición», sin anclar. Es la causa de N-1.
- **Corrección:** ajustar el comentario o el código (N-1).

**N-9 · menor · `CommercialTurnPolicy.php:251-256`: palabras nuevas de la red que quitan la lista en frases inocuas.**
- `\d{1,2}\s+anos` («Tengo 30 años») y `sedes?` («en esta sede») [C].
- Es la dirección segura y va con el diseño. Solo lo documento.

## Notas

**Revisado y bien:**
- `PIDE_PANORAMA`: la precedencia de la alternancia (`(?:…)COLA | ^…$`) es la que pretende el comentario. La forma anclada no cambia [L, y lo confirman las R2].
- `NO_PIDE_EL_PANORAMA`: el añadido «gracias por los precios» funciona (H8) [C].
- Las 28 entradas de otro tema están cubiertas por las dos redes [C].
- Ningún comentario de `routes/marketing.php` contradice el cambio [L].
- La condición 2 está bien estructurada: mismo prefijo, el mismo secreto y la misma acción; un limitador con nombre propio y su configuración con valor por defecto, en `AppServiceProvider.php:905-907` y `config/automation.php:35` [L + C].
- Los errores de PCRE van en la dirección segura: si falla `PIDE_PANORAMA`, no hay lista [L].

**Lo que NO verifiqué:**
- La batería completa y el paquete de guardas: los lleva el lead.
- El análisis ReDoS y el del limitador: los lleva security.
- El PCRE 10.39 de producción (yo tengo 10.47 en local).
- n8n ejecutándose: solo leí el flujo.
- Ningún modelo real; la etiqueta de intención que pondría un modelo a mis frases de sonda, que pasé con `general_info` y `pricing_question` fijas.
- La mutación de N-5, que solo razoné.
- PostgreSQL, producción y la integración con otras ramas.

**Artefactos** (solo en mi scratchpad: `pt15/`): `sonda.php`, `OldPolicy.php`, `q1.txt` a `q6.txt`, `routes-old.json` y `routes-new.json`, y las copias de respaldo en `bak/`. El árbol de la rama quedó limpio (`git status --porcelain` vacío).

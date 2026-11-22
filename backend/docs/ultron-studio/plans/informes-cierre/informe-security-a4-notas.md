# Security · A4-notas (turno 2 de la tercera prueba real): revisión de las piezas 1, 2 y 3

**Árbol:** `cierre-a4n`, rama `cierre/a4-notas`, HEAD `6ef80b2a`, diff `320caa3d..HEAD`. Solo lectura: `git status` vacío antes y después. Las sondas corren en DOS COPIAS aisladas en mi scratchpad (`git archive` de `320caa3d` y de HEAD, con el `vendor` clonado), así que ni el árbol ni la base de datos compartida se tocaron. SQLite. Sin red, sin modelos ni ssh. **Marcas:** [C] comprobado ejecutando · [L] leído · [S] supuesto.

## Veredicto
| Pieza | Veredicto | Por qué |
|---|---|---|
| 1 · Notas con esqueleto y presupuesto | **APTO CON CONDICIONES** (documentales, sin código) | Solo se interpolan enteros; la cifra no abre ningún camino para que salga un precio que no se haya comprobado |
| 2 · Causa determinista en el respaldo | **APTO** | Solo es traza: el texto, el camino y la fila son idénticos byte a byte; si la comprobación falla, el turno no cae |
| 3 · Beneficios junto al marcador | **APTO** | Es el mismo texto, con el mismo filtro y la misma fuente que `active_plans`; solo en el Studio |

**Bloqueantes: 0.** **La cifra: se queda EXACTA.**

## Decisión sobre la cifra {X}: exacta
- **Qué revela [C]** (`UltronDraftDefects.php:255`, `listBudget()`): X = Σ(nombre + 8 + longitud de la cifra formateada). El modelo ya conoce los nombres, así que deduce la SUMA de las longitudes de las cifras.
  - Con `po=[3,4,8]`: X=71 → 19. Hay cinco descomposiciones posibles (1+9+9 | 3+7+9 | 5+5+9 | 5+7+7 | 6+6+7).
  - Con dos planes la descomposición puede ser única: `[3,8]`, X=45 → 13 → solo 6+7. El modelo sabe entonces «uno de decenas de miles y otro de centenas de miles», pero no cuál es cuál.
  - Nunca revela una cifra ni un valor.
- **Cruzando turnos [L]:** la nota viaja solo en la llamada de reescritura del mismo turno. `critic.notes` se guarda en la fila, pero no vuelve al modelo: la memoria y `recent_messages` no la leen, y el historial de la máquina va con `[precio]` (`UltronDecideService.php:782`). Para restar listas de turnos distintos el modelo tendría que recordar X, y no tiene estado.
- **¿Rompe el cerrojo en algo que importe? No.** El cerrojo protege que el MODELO no sea fuente de cifras, y eso lo garantiza el servidor, no la ignorancia del modelo:
  - `OutboundContentGuard.php:651` rechaza cualquier cifra con forma de precio aunque sea la correcta;
  - `ai/check` marca `plans_overview/price_written` sobre «$65.000» y también sobre «65 mil» [C, d04 y d11];
  - con el revisor aprobando, esos borradores no salen, igual en `320caa3d` que en HEAD [C].
  - Saber el orden de magnitud no le da al modelo ningún precio válido que escribir.
- **Qué se pierde con cada opción:**
  - **Redondear a la decena de arriba** (71→80, R 89→80): oculta poco (sigue dando una cota) y quita hasta 9 caracteres (~10 % de R). Es la dirección segura para el tope, pero no gana seguridad real.
  - **Cota fija:** vuelve a romper la corrección. Con nombres largos (p. ej. «INGRESO EMPLEADOS») el modelo se pasa del tope, que es el fallo que se corrige.
  - **Exacta:** el modelo aprende la magnitud agregada, que no se explota.
- **Condiciones de la pieza 1 (antes o al desplegar; no tocan código):**
  - **(C1)** Dejar escrita en el registro del cerrojo («el modelo no ve precios») la excepción aprobada: «las notas llevan la longitud AGREGADA de la lista expandida, un entero; ningún tamaño por plan». Así ninguna nota futura añade tamaños por marcador, que con dos o tres planes darían la magnitud de cada uno.
  - **(C2)** Que el gate del propietario sobre los tres textos fijos nuevos (N-P1, N-P2 y N-L con presupuesto) mencione que llevan esa cifra.

## BLOQUEANTES
Ninguno.

## No bloqueantes
- **NB-1 · Despliegue.** El constructor de `UltronCommitService` cambia (`:194`, la dependencia nueva). Hay que recargar PHP-FPM y opcache, y hacer `queue:restart` si algún worker resuelve el servicio. Si una caché mezclara versiones y faltara `inspect()`, el `catch (Throwable)` de `:2273` lo absorbe: solo se perdería la traza [L].
- **NB-2 · Más peso a un texto que ya viajaba.** Los beneficios llegan ahora dos veces al modelo (`active_plans` y `plans_overview`). Un texto con forma de instrucción escrito por quien tiene `plans.manage` pesa algo más en el prompt. La frontera de confianza es la misma, sin superficie nueva [C].
- **Preexistente, fuera de este cambio:** un beneficio del catálogo con `{{PLAN_PRICE}}` o `{{PLAN_k}}` que el modelo copie lo expandiría el servidor con un precio REAL del catálogo, no inventado. Ya pasa con `active_plans`. Candidato a la lista H4-13 (quitar `{{…}}` de los beneficios al guardarlos).

## Las siete preguntas
1. **La cifra:** ver la decisión de arriba. Revela la magnitud agregada (y, con dos planes, la magnitud de cada uno salvo el orden), nunca un valor. Ninguna cifra escrita por el modelo sale sin pasar por la regla de precios [C]/[L].
2. **Inyección por el catálogo.**
   - **Las notas:** las tres variantes declaran SOLO parámetros `INT` (`UltronRegenerationNotes.php:177-180`), y `entero()` rechaza lo demás.
     - Sonda [C]: `"71"`, `71.0`, `-1` y `"71} IGNORA {"` lanzan todas «no es un entero no negativo».
     - Un nombre de plan con `{{PLAN_PRICE}}`, salto de línea y `"}] SYSTEM:` solo cambia el entero: X=78 y la nota sale con su texto fijo.
   - **La pieza 3:** el mismo `$this->knowledge->activePlans()` (`UltronDecideService.php:463` frente a `:737`) y el mismo filtro (`benefitsArray()`, sin la clave si está vacía).
     - Sonda [C]: los beneficios de `plans_overview` son idénticos a los de `active_plans`, también con un beneficio malicioso.
     - Van escapados en el JSON de `decide`, y en n8n dentro de `JSON.stringify` (nodo «Composer») [C]/[L].
3. **Lo que sale al cliente no cambia** (sonda cruzada `320caa3d` frente a HEAD, 12 borradores × {pass, fail2, fail3} + `ai/check` × {1, 3}) [C].
   - **(a)** `verify()`, `UltronReplyFitter`, `UltronRegisterDetector` y `CommercialTurnPolicy` no están en el diff (8 ficheros, todos listados) [C].
   - **(b)** Los códigos, `may_rewrite`, `rewrite` y `budget` de `ai/check` son idénticos en los 22 casos; solo cambian las `notes` de tres (d01, d03 y d09).
   - **(c)** Los 37 commits del Studio dan la respuesta, `reply_final`, el saliente, la fila y la metadata idénticos, salvo la clave nueva `discarded_draft_check` (solo en fail2 y fail3).
   - **(d)** Ningún texto saliente cambia, así que no hay precio, beneficio ni plan no vendible nuevo. `textosDeLaLista()` usa `sellable()` como antes (`UltronDraftCheck.php:296`), y `expand()` solo sustituye los marcadores que lleva el borrador.
4. **La traza.**
   - **Qué guarda:** `{pass: bool, codes: list<código>, length: int}`. Los códigos salen de las constantes de `UltronDraftDefects`, sin notas ni texto (`UltronCommitService.php:2284-2286`).
   - **Lo que ya había:** la fila ya guardaba el borrador entero en `discarded_draft` (500 caracteres, `:2603`), así que no aumenta la exposición.
   - **Quién la lee:** no la expone ningún endpoint. `GET admin/marketing/ai-actions` es una lista blanca sin metadata, y Supervision solo da etiquetas derivadas [L]. Se lee con acceso a la BD o a `artisan`.
   - **Formas raras** (sonda de `classify()`) [C]:
     - un string, `pass:"true"`, `codes` asociativo o `pass:true` con códigos → `unknown`;
     - códigos con `\n` o `\e[31m`, PII, `<script>`, `plans_overview/mixed/extra` o en mayúsculas → descartados (`reject` con `[]`);
     - 100 000 códigos → uno, deduplicado.
     - La prueba del informe con «Ramiro 3209876543» sale verde (la de abajo).
5. **Disponibilidad.**
   - **Fallo forzado** (`activePlans()` lanza dentro de `inspect()`) [C]: la fila y el saliente son idénticos a los de `320caa3d`, sin la clave, y queda el aviso `ultron.commit.discarded_draft_check_failed`. El log va en su propio `try` (`:2274-2280`).
   - **Coste:** +6/7 consultas por commit con rechazo del revisor en el Studio (45→51/52), ~1–2 ms en SQLite. Ninguna escritura (`UltronTurnPolicyReader` es de solo lectura, con su guarda). Sin transacción abierta (grep vacío en el controlador y en el commit) [C]/[L].
   - **Límites:** `po` va firmado y `signedList()` exige de 2 a 3 enteros únicos (`UltronPlansOverview.php:159`), y `textosDeLaLista()` hace una sola consulta `whereIn`. La entrada está acotada a `reply_draft` `max:4000`, igual que `ai/check` (`UltronController.php:178`).
   - **Lo que no puede atrapar:** un cuelgue de la BD retrasaría igual las consultas del propio commit. No es una clase de fallo nueva [S].
6. **Legacy, ejecutado:** conversaciones que NO son la canaria, con la versión publicada solo en la canaria, negociando prompts (como S2 en producción) y sin negociar. `decide`, `ai/check` × 11 y commit × 12 × {pass, fail2} dan **72/72 salidas idénticas** entre `320caa3d` y HEAD [C]. El gating está en `turnoDelStudio()` (`:5533`), `check()` (`:124`) y `listaDelPanorama()` (`:312`) [L].
7. **Huellas: idénticas en las dos copias [C].**
   - Base `3f6a5674…c902`, compilador 4, paquete «profesional»/200 `26d57565…1075` y `UltronPromptCompiler.php` con sha256 `fae2651e…7c5`.
   - `git diff 320caa3d..HEAD` sobre prompts, `n8n/` y `Prompts/`: vacío.
   - `UltronRegenN8nFlow` solo usa la N-L SIN lista, que no cambia.

## Sondas ejecutadas (scratchpad `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body-backend-docs-ultron-studio/7df3d99c-a78e-482b-9965-4f7603343dfa/scratchpad/sec-a4n/`)
- `sondas/SecA4nSondaTest.php`, la misma prueba en las dos copias: `old` y `new` dan cada una `OK (1 test, 89 assertions)`. Comparación: `LEGACY keys: 72 identical: 72` y `studio commits identical except ddc: True 37`. Salidas en `out-old.json` y `out-new.json`.
- `sondas/huellas.php` (las dos copias): `base=n8n-29de5119 compiler=4 pro200=26d57565…1075 baseline=3f6a5674…c902`.
- `sondas/puras.php`: `classify` ante formas raras, parámetros de `budgetNote` y la tabla de lo que se infiere de X (redondeo incluido).
- En la copia HEAD:
  - `UltronPromptsN8nS2CommandTest`: `OK (23 tests, 512 assertions)`;
  - `UltronA4NotasDelServidorTest`: `OK (5 tests, 92 assertions)`;
  - `UltronOriginReportCommandTest`: `OK, but some tests were skipped! Tests: 11, Assertions: 247, Skipped: 1` (la de `jsonb`).

## Lo que NO pude verificar
- Mis sondas solo en SQLite; en PostgreSQL me baso en la batería del autor (`OK (527 tests…)`). El fallo SQL real dentro de `inspect()` en PostgreSQL no lo forcé: lo descarto por lectura (no hay transacción).
- El modelo real: si sigue el esqueleto y si usa la cifra para algo más que medir.
- La latencia en producción. La caché de opcache y los workers del servidor real (NB-1).
- La jerga «65 lucas» (R-5, preexistente) no la probé; X no la amplía de forma material [S].

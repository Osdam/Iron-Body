# Reviewer · A4-notas (turno 2 de la tercera prueba real): veredicto para desplegar

**Árbol:** `cierre-a4n`, rama `cierre/a4-notas`, diff `320caa3d..6ef80b2a` (6 commits, 8 ficheros de producto). Solo lectura: `git status` vacío antes y después. Sin red, sin ssh, sin modelos. Rutas relativas a `backend/`. **[C]** comprobado ejecutando · **[L]** leído · **[S]** supuesto. Sondas: `/private/tmp/claude-501/-Users-alejodev-Documents-Dev-IRON-BODY--worktrees-ultron-h8-Iron-Body-backend-docs-ultron-studio/c1183641-ae1c-4f78-9a48-1868d9cec369/scratchpad/sonda/`.

## Veredicto
- **Pieza 1 (`71b10fff`, notas con esqueleto y presupuesto): APROBADO CON CONDICIONES.** La única condición es que el propietario apruebe los tres textos en su gate. Técnicamente no bloquea nada.
- **Pieza 2 (`fbaee580` + `de0513ed`, causa determinista en la fila): APROBADO.**
- **Pieza 3 (`0b52e8df`, beneficios junto al marcador): APROBADO.** **Recomiendo desplegarla junto a las otras dos**, si `security` no objeta:
  - en Legacy no cambia nada [C];
  - solo actúa en el turno del panorama de la canaria;
  - pone los beneficios correctos junto a la mención que la nota pide («para quien es»);
  - se puede revertir sola.
- **Bloqueantes: 0.**

## No bloqueantes (a la lista)
1. **La afirmación «ninguna combinación pierde una nota que antes llegaba» es falsa fuera del peor caso fijo** (`tests/Unit/Marketing/Ultron/UltronRegenerationBudgetNotesTest.php:186-263`; docblock `UltronRegenerationNotes.php:164-166`).
   - **Por qué la prueba no lo ve:** fija N-R y N-B en su longitud MÁXIMA, que es el peor caso para «cabe», no para «se pierde», y limita la política a 4 notas cuando caben hasta 6 dentro de `MAX_DEFECTS` = 8.
   - **Fuerza bruta [C]:** recorrí 42.223.728 combinaciones con `mixed` (la única variante más larga que antes), todas las longitudes reales de N-R (194-253) y de N-B (150-268) y las cifras de 1 a 6 dígitos. Resultado: 34.176 pierden una nota que antes entraba. PERDIDAS_POR_NOTA.
   - **Caso concreto con el `merge()` real [C]:** N-P2 + N-B («PLAN_3», «Sauna»: 159) + `policy_greeting_sells`. Antes entraban las tres (499 caracteres); ahora se cae la de la política (388).
   - **Por qué no bloquea:** solo caen notas de política, las de menos prioridad. Además hace falta un N-B de 162 caracteres o menos (plan + beneficio de 14 o menos), y los beneficios de producción de la prueba miden 18 o más [C]; con el catálogo entero, no lo he comprobado [S]. Y la política tendría que ser saludo, memoria o «solo preguntas», que son improbables en un turno del panorama con marcadores.
   - **Corrección:** iterar las longitudes de N-R/N-B y hasta 6 de política, y corregir la frase del informe y del docblock.
   - **Lo que sí es cierto [C]:**
     - las 22 combinaciones de lista + longitud caben enteras (máximo 498);
     - con `missing` y los demás detalles no se pierde nada (todas sus notas acortan);
     - antes, N-P1 + N-L medía hasta 569-572 y se perdía N-L entera.
2. **La N-L con presupuesto sale con lista y tope, aunque la lista no se esté juzgando** (`UltronDraftDefects.php:104-110` y `:145-148`). Pasa, por ejemplo, si el borrador pide herramientas: la lista no se mira (`:115-123`), pero la nota dice «los marcadores ya ocupan X: quedan R» y le quita al redactor espacio que sí tiene. Además, X cuenta siempre los K ofrecidos: con un borrador de 2 planes, la nota dice 71 cuando usa 51. Menor.
3. **Con nombres muy largos, X ≥ M y R = 0** («quedan 0 de 160») [C, prueba `:155`]. La N-P2 con presupuesto no trae entonces «si no caben, dos»: la instrucción es imposible y la lista fija sigue de red. Menor.
4. **La N-P1 nueva pierde «de commercial_turn_policy.plans_overview» y «al menos dos»**, que traía la de antes. El parche del motor del paquete `26d57565` los sigue diciendo [L], pero conviene que el propietario lo vea en el gate.
5. **«Sin dato» del informe de origen mezcla cosas distintas** (`UltronReplyOrigin.php:90-97`; `UltronOriginReport.php:515-519`): las filas de antes del 22-11, TODAS las de Legacy (que nunca llevan la comprobación), los borradores vacíos y las lecturas fallidas. El docblock dice solo «antes del 22-11». En el informe de clientes reales, todo será «sin dato». Rótulo sugerido: «sin dato (Legacy o anterior)».

## 1. Lo que no puede cambiar: no cambia
**Sonda diferencial [C].** Es una copia del test de A4 que carga, con un autoloader previo, los 8 ficheros de producto de `320caa3d`. Recorre 10 escenarios × revisor aprueba/rechaza: `decide` → `ai/check` ×3 → commit (respuesta, fila y saliente), y compara JSON contra HEAD.
- **(a) Legacy** (panorama, horario, plan nombrado; sin negociar, no canaria negociando, llave de prompts apagada): **0 diferencias**, quitando el `uniqid` del propio test. Incluye los 403/422 de la no canaria y de la llave apagada.
- **(b) Studio sin lista** (plan nombrado largo, horario con tuteo):
  - `decide` y `ai/check` iguales byte a byte (la N-L sin lista es la de siempre);
  - el texto que sale es el mismo;
  - única diferencia: en el rechazo del revisor, la fila gana `metadata.discarded_draft_check` (p. ej. `{false,["length_over_cap"],261}`), que es la pieza 2.
- **(c) `ai/check`:**
  - con y sin lista, mismos `defects`, `issues`, `pass`, `may_rewrite`, `rewrite`, `budget`, `attempt` y estado HTTP; solo cambia `notes` con lista y tope [C];
  - `MAX_DRAFTS`/`LEGACY_MAX_DRAFTS` sin tocar [L];
  - el camino del commit es el mismo: el texto final es igual con aprobado y con rechazado en los 20 casos [C].
- **(d) Las reglas deterministas no se tocan:** `verify()`, el tope, el registro y `ofPolicy()` siguen igual [L]. La paridad (`UltronCheckCommitParityTest`) está en verde en SQLite y en PG [C].
- **(e) Huellas [C]:**
  - `git diff 320caa3d HEAD` sobre `resources/ultron-prompts`, `n8n` y `app/Services/Marketing/Ultron/Prompts`: vacío;
  - las pruebas que fijan la base, el compilador 4 y el paquete `26d57565…1075` (`UltronPromptCompilerTest:434`, `UltronPublishedConfigPhrasesTest:80`), en verde;
  - en la sonda, el `sp` firmado (h/v/c) es idéntico antes y después;
  - `UltronRegenN8nFlow` usa solo `note()`, no la variante.

## 2. Pieza 1
- **La variante sale solo con lista y tope** [C]:
  - sin tope: la de antes (sonda «sin tope» y prueba `:173-193`);
  - sin lista o en un turno de cobro (`poDelTurno`=[]): la de antes;
  - solo `missing` y `mixed`; `name_written`, `unknown_marker` y `price_written`, igual que antes.
  - Salvedad: la N-L (no bloqueante 2).
- **Enteros [C]:**
  - 3 planes: 25 + 26 + 20 = 71, R 89 de 160;
  - 2 planes: 51 / 109;
  - un plan que ya no se vende cuenta como su marcador literal: 61 / 99;
  - R nunca negativo (`listBudget()` `:255`, con `max(0)`).
- **Tope mínimo:** 160 → M 120 y R 49. La medida es la del tope (`measure()`) [L].
- **Límite de 500:** véase el no bloqueante 1. Todo ASCII [C], así que el corte de n8n mide lo mismo.

## 3. Pieza 2
- **`check()` igual que antes:** `inspect()` es un extracto literal [L]. Las diferencias:
  - una consulta por todos los `po` en vez de por los nombrados, con el mismo texto expandido [C en la sonda];
  - `?? ''` en campos que el controlador ya exige.
- **Dentro del commit:**
  - `causaDeterminista()` (`UltronCommitService.php:2258-2287`) va ANTES de `safeFallback`, solo con `turno.studio` y con borrador no vacío, y envuelta en `catch (Throwable)` con log protegido [L];
  - no hay `DB::transaction` en el servicio, y el commit va bajo `Cache::lock()->block()` (`:228`) [L], así que una lectura fallida no envenena nada en PG;
  - antes de `:470` no hay escrituras [L];
  - coste: las lecturas de un `ai/check` (milisegundos) [S].
- **Constructor:**
  - nadie hace `new UltronCommitService` (grep en app, tests, database y ops) [C];
  - todos lo resuelven con `app()`;
  - sin dependencias circulares (`UltronTurnPolicyReader` no depende del commit) [L].
- **Informe de origen:**
  - las filas antiguas y las de Legacy dan `unknown` [C, pruebas];
  - el código viejo ignora la clave nueva [L].
- **Rótulo viejo:** solo aparece en evidencias históricas (`a4-evidencia/*.txt`) y en un encargo. Ningún guion ni prueba lo busca [C, grep].

## 4. Pieza 3
- **Origen de los beneficios:** el mismo que `active_plans`: `knowledge->activePlans()` → `benefitsArray()`. Con el mismo filtro: sin la clave si está vacío (`UltronPlansOverview.php:249` frente a `UltronDecideService.php:720-736`) [L, y la prueba lo compara].
- **Cuándo sale:** solo desde `listaDelPanorama()`, con `sp.m=studio`, `o=1` y la pregunta del panorama [L]. En Legacy, nada [C].
- **`too_large`** limita el prompt compilado (rol/paquete), no el contexto: este campo no cuenta para ningún límite [L].
- **n8n S2** pasa `commercial_turn_policy` entera al estratega y a los tres redactores. Los revisores reciben 6 campos. Ningún nodo filtra `plans_overview` [C, lectura de `flujo-s2.json`].
- **Separable [C]:** `git diff 398b2331 HEAD -- app` toca solo `UltronPlansOverview.php` y un comentario de `UltronDecideService.php`. Las pruebas de `398b2331` con esa clase anterior: 57/58 en verde. El rojo es un artefacto de mi copia: la prueba lee el fuente relativo a `__DIR__`.

## 5. ¿Arregla el fallo? (razonado, sin modelo)
- **Lo que gana el redactor:** con `mixed` + `length_over_cap` recibe 462 caracteres enteros con:
  - cuántos planes (K) y con qué marcadores, una vez cada uno;
  - cuánto ocupan (71) y cuánto queda (89 de 160);
  - qué hacer si no caben: dos planes, y una pregunta corta o ninguna.
  - Con `missing` + longitud, ahora le llegan las dos notas (antes se perdía la N-L) [C].
- **Contradicciones que quedan** en lo que el modelo sigue recibiendo [L]:
  - `composer.txt:50` y `:38` piden el precio de UN plan;
  - `composer_retry.txt:4` usa `{{PLAN_NAME}}`;
  - el estratega empuja `ask_discovery` y `suggested_question` (~40 caracteres);
  - el parche del motor dice «esto manda», pero va condicional dentro de unos 27 KB.
- **Lo que NO arregla:**
  - el primer borrador sin lista (estratega y bases);
  - el tercer borrador sin comprobar;
  - el revisor sin `plans_overview` y con su ruido (puede tumbar una lista válida);
  - que «para quién es» × 3 en 89 caracteres es justo: un beneficio de 52 copiado lo revienta, y el ajuste solo quita la pregunta final.
- **Probabilidad:** la mejora es real en el segundo y el tercer borrador. Le veo una probabilidad moderada, no alta, de pasar la prueba al primer intento. La medida es el laboratorio (×10) antes de la canaria.

## 6. Calidad de las pruebas
- Son clases reales (los `Plan` en memoria solo en la prueba pura) y nada se ha aflojado [L].
- Los cambios de expectativa son solo los literales de las notas, la forma de `plans[]`, el rótulo y la lista de claves de `classify()` [C, diff].
- El reloj va fijado (`setTestNow` en UTC), y la prueba pura no lo usa [L].
- El documento del gate solo se escribe con `A4_REGENERAR=escribir` [L].
- La laguna es la del no bloqueante 1.

## 7. Despliegue
- **El guion vale:** son solo los 8 ficheros de `app/`, sin migraciones, rutas ni configuración [C]. Ojo: hay que pasarle también como permitidos los tests y docs del diff entre lo desplegado y `origin/main`.
- **PHP-FPM:** con OPcache por defecto (`validate_timestamps=1`) no hace falta recargarlo [S sobre la configuración de prod]. Hay una ventana de ≤ 2 s con ficheros mezclados [S]:
  - por ejemplo, `UltronDraftDefects` nuevo con `UltronRegenerationNotes` viejo: `Error` que `defecto()` no captura → `ai/check` 500 → n8n sigue el camino de hoy;
  - en el commit, lo captura `Throwable`;
  - Legacy no llama a nada nuevo.
  - Recargar FPM sería opcional y solo con autorización.
- **Después, antes de la prueba real:**
  - lo que imprime el guion (workers, 401, ERROR);
  - 0 entradas de `ultron.commit.discarded_draft_check_failed` y de `ultron.check.note_unavailable`;
  - el siguiente `decide` de la canaria sigue `studio` con el paquete `26d57565` (no `compiler_changed`/`base_changed`);
  - `ultron:origin-report` (lectura) muestra el rótulo nuevo.
- **Vuelta atrás:** la que imprime el guion (`checkout --detach PREVIO` + `queue:restart`). Las filas que ya llevan la clave nueva son inocuas para el código viejo [L].

## Pruebas ejecutadas (línea final)
- **SQLite:**
  - unitarias nuevas y cambiadas (5): `OK (98 tests, 16772 assertions)`;
  - Feature nuevas y cambiadas (4): `Tests: 118, Assertions: 1180, Skipped: 1` (la de `jsonb`);
  - paridad + A4 Longitud/Registro (instantáneas Legacy): `OK (271 tests, 2994 assertions)`;
  - huellas (7 de Prompts/Studio): `OK (231 tests, 16365 assertions)`;
  - `N8nS2`: `OK (23, 512)`;
  - `N8n`: `OK (8, 118)`.
- **PostgreSQL 14:**
  - A4Notas `OK (5, 92)`;
  - A4Lista `OK (41, 390)`;
  - CheckEndpoint `OK (61, 451)`;
  - OriginReport `OK (11, 252)`;
  - Paridad `OK (45, 307)`.
- **Guardas (16):** `Tests: 141, Assertions: 183140, Failures: 1`. Es la puerta de diff del H9, por los 7 del motor: `UltronCommitService`, `UltronDecideService`, `UltronDraftCheck`, `UltronDraftDefects`, `UltronPlansOverview`, `UltronRegenerationNotes` y `UltronReplyOrigin`. Es lo esperado.

## Lo que no pude verificar
- El comportamiento del modelo y del revisor con las notas nuevas.
- La configuración de OPcache en producción.
- Los beneficios de todo el catálogo de producción (solo los 3 de la prueba).
- La batería completa (no lanzada, por instrucción).
- Lo de seguridad (lo que revela X), que es de `security`.

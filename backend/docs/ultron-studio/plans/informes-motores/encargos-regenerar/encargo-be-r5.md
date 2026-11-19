Eres el agente `backend` de ULTRON STUDIO (Iron Body: API Laravel 12 / PHP 8.3; n8n). Implementas, con pruebas, la OLA 5 de «regenerar antes de sustituir» en el motor de WhatsApp (ULTRON): el flujo de n8n «S2», GENERADO desde el repositorio y COMPROBADO sin tocar el n8n que está en marcha. Todo en español, con el estilo de la casa. Eres el único agente trabajando en este árbol.

## De qué va (para situarte)

El propietario decidió (19-11) que, cuando una protección rechaza el borrador del modelo por algo corregible, el modelo reescriba con el defecto exacto, como mucho dos veces más, y que el texto fijo quede como última red. El servidor ya sabe decir si aceptaría un borrador (`POST /api/internal/marketing/ai/check`, de solo lectura; hecho en la ola 4). Falta que el flujo de n8n le pregunte entre el redactor y el revisor, reescriba con la nota del servidor y pueda llegar a un tercer borrador. Sus diez condiciones, literales: `docs/ultron-studio/MATRIZ_CIERRE_V2.md` §5 (las tuyas: 2, 6 y 7: máximo dos reintentos, sin bucles ilimitados, sin romper lo que funciona).

## El plan manda

`docs/ultron-studio/plans/informes-motores/plan-arquitecto-a4-regenerar.md`. Lee ENTEROS: §1 (H2 a H6, H8, H13), §3 P-2 y P-6, §4 (el contrato: lo que devuelve `ai/check`, lo que añade `decide` y lo que acepta el commit en el bloque `regeneration`), **§5 entero** (el diagrama, los 14 nodos nuevos, las dos anclas de Preparar Commit, los recorridos, qué genera el generador, cómo se comprueba y la vuelta atrás), §6 «Ola 5», §8 y §13 (decisiones del lead, que mandan: L-6 es la tuya). Lo ya hecho en el servidor: `docs/ultron-studio/plans/informes-motores/informe-backend-a4-regenerar.md` (olas 1 a 4: el contrato REAL tal como quedó; si difiere del plan en algún nombre de campo, manda lo implementado y lo dices).

Material de partida, que es tu precedente exacto: `app/Services/Marketing/Ultron/Prompts/UltronPromptsN8nFlow.php`, `app/Console/Commands/Marketing/UltronPromptsN8n.php`, `n8n/flujos/ultron-studio-s1/` (el flujo S1 generado, sus `.js`, sus huellas y sus fixtures), `docs/ultron-studio/ops/motores/verificar-flujo.cjs`, `docs/ultron-studio/ops/motores/n8n-copia-s1/` (LEEME e `importar-s1.sh`) y `tests/Feature/Marketing/UltronPromptsN8nCommandTest.php`.

Árbol: `/Users/alejodev/Documents/Dev/IRON_BODY/_worktrees/ultron-h8/Iron-Body/backend` (y `n8n/` en la raíz del repositorio), rama `feat/ultron-studio-h9`. Mira `git log --oneline -5` y `git status`: árbol limpio. Nada de esta rama está desplegado.

## Qué haces (plan §6, Ola 5)

1. `Prompts/UltronRegenN8nFlow.php` (nuevo, PURO): `build(S1)` → el flujo S2 = los 25 nodos de S1 más los 14 de §5.2, con ids y posiciones fijos y las conexiones de §5.1. Desenrollado: NINGÚN bucle. El límite de tres redacciones por turno está en la FORMA del grafo.
2. `UltronPromptsN8nFlow::selectorCode()` con la revisión como parámetro con valor por defecto: lo que se genera para S1 sale BYTE A BYTE igual que hoy.
3. La orden `ultron:prompts-n8n --rev=S1|S2` (por defecto S1, como hoy) que escribe `n8n/flujos/ultron-studio-s2/`: `flujo-s2.json`, los `.js` de los nodos Code (`preparar-commit.js`, `leer-comprobacion.js`, `nota-del-servidor.js`, `decidir-intento-3.js`), `huellas.json`, `fixtures/` (respuestas de `decide` con y sin Studio, y de `ai/check`: pasa, pide reescritura, legacy, 404, 422, error de red y mal formada) y `LEEME.md`.
4. El comprobador offline en modo S2 (`docs/ultron-studio/ops/motores/verificar-flujo.cjs --rev=S2` o un hermano), con los apartados A a J de §5.4, ejecutado por una prueba PHP como hoy.
5. `docs/ultron-studio/ops/motores/n8n-copia-s2/LEEME.md` e `importar-s2.sh`, derivados de los de S1: el procedimiento para importar S2 como copia INACTIVA, ensayarlo con datos fijados y cambiar de S1 a S2, con su vuelta atrás. **ESCRITOS Y SIN EJECUTAR.**

Lo que tiene que cumplirse (el comprobador lo fija y las pruebas lo mutan):
- Los 25 nodos de S1 quedan IDÉNTICOS en S2, salvo el código de Preparar Commit y la revisión del selector: los prompts y los `values[1]` de los nodos de modelo de S1 no se tocan.
- Sin la oferta del servidor (`decide` no devuelve `regeneration` con el contrato `ultron-regen/1`: turno Legacy, o un servidor anterior), el recorrido y los datos enviados son EXACTAMENTE los de hoy: mismas llamadas al modelo, ninguna llamada a `ai/check`, y Preparar Commit produce la misma salida que el de S1, sin el bloque.
- Con la oferta: como mucho 3 redactores y 3 revisores en cualquier camino; la comprobación se llama como mucho 2 veces, con un tiempo máximo de 3 s y SIN reintentos; cualquier respuesta que no sea un 200 bien formado significa «no reescribir» y se sigue el camino de hoy; el tercer borrador no se comprueba pero SÍ pasa por el revisor; Preparar Commit añade el bloque `regeneration` (quién pidió cada reescritura y con qué códigos, cuántos borradores, revisores y comprobaciones, errores de comprobación y tokens si el nodo de OpenAI los entrega) dentro de un `try`, de modo que nunca se quede sin respuesta.
- El cuerpo de la petición a `ai/check` lleva solo las claves del contrato. La credencial se referencia por nombre, como en los otros nodos HTTP: NINGÚN secreto en el repositorio.

## Pruebas (primero ROJAS; guarda la salida)

`tests/Feature/Marketing/UltronPromptsN8nS2CommandTest.php` (o como encaje con el precedente): la generación es determinista; **S1 sale byte a byte igual que antes**; el comprobador pasa sobre lo generado y FALLA con cada mutación (un redactor de más; un IF que salta la comprobación; el `values[1]` de un nodo de S1 tocado; una clave de más en el cuerpo del check; Preparar Commit sin el `try` del bloque; un bucle que cierre el grafo). Los nodos Code nuevos, ejecutados en `vm` de Node con los fixtures (como hace hoy el comprobador con S1). `UltronCatalogStaticGuardsTest` ampliada a `n8n/flujos/ultron-studio-s2/*.js`.

Desde `backend/`, UNA ejecución de PHPUnit cada vez: `nice -n 10 php -d memory_limit=2G vendor/bin/phpunit --no-logging <ficheros>`. Al final: las nuevas, `UltronPromptsN8nCommandTest`, `tests/Unit/Marketing/Ultron/Prompts`, `tests/Feature/Marketing tests/Unit/Marketing` enteros, las guardas (una roja esperada: la puerta de diff del H9) y `vendor/bin/pint` sobre lo tocado. `node docs/ultron-studio/ops/motores/verificar-flujo.cjs` en los dos modos: pega las dos salidas en el informe.

## Límites (estrictos)

- **NADA en el n8n en marcha:** ni MCP de n8n, ni su API, ni su base de datos, ni importar, ni publicar. Tampoco producción.
- No toques el servidor de las olas anteriores (`UltronDraftCheck`, el controlador, el commit, `decide`) salvo que encuentres un fallo del contrato: entonces PARA y dímelo con la línea.
- No toques los ficheros generados de `n8n/flujos/ultron-studio-s1/` (si la orden por defecto los regenera idénticos, bien; si cambian, PARA y dímelo).
- Nada de git que escriba. No relajes ninguna prueba. Sin secretos ni credenciales en ningún fichero.

## Entregable

Código, artefactos y pruebas en el árbol, SIN commit. Una sección nueva en `docs/ultron-studio/plans/informes-motores/informe-backend-a4-regenerar.md` («7. Ola 5»): ficheros tocados, el recuento de nodos y de conexiones de S2, los seis recorridos con sus llamadas, qué comprueba cada apartado del comprobador y qué mutaciones mata, decisiones tuyas, lo que NO se pudo comprobar sin un n8n real (por ejemplo, si el nodo de OpenAI devuelve `usage`, o el comportamiento de un IF con un nodo que no corrió) y cómo se comprobará en el ensayo con datos fijados. Salidas en `a4-evidencia/` con el número siguiente al último que haya. Respuesta final a mí en pocas líneas, con los números y los ficheros tocados.

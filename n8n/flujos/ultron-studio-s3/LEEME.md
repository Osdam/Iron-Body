# S3 · contrato inicial de la lista

Artefacto **local, inactivo y no disponible en MCP** generado desde S2, que permanece intacto. El generador fija explícitamente `active:false` y `settings.availableInMCP:false`, aunque S2 conserve su ajuste histórico. Los siete pasos de modelo usan un Code de preparación, un HTTP Request v4.4 con la referencia de credencial OpenAI existente y un adaptador con el nombre original. El cuerpo explícito comparte las plantillas del cliente PHP del laboratorio. No requiere adivinar cómo una versión del nodo OpenAI transforma roles o `strict`.

El contexto declara `generation_contract: "ultron-list-turn/1"`. La variante del prompt se elige antes de inferir, con el contrato validado del backend. Legacy conserva sus dos mensajes `user`; el turno de lista usa `instructions`, un solo mensaje de datos y esquema estricto. El adaptador rechaza respuestas incompletas, negativas y salidas fuera del esquema; no modifica el texto del modelo.

Desde la raíz del repositorio:

```sh
php backend/docs/ultron-studio/ops/motores/generar-flujo-s3.php --output=n8n/flujos/ultron-studio-s3/flujo-s3.json
node backend/docs/ultron-studio/ops/motores/verificar-flujo-s3.cjs n8n/flujos/ultron-studio-s3/flujo-s3.json
```

Antes de una integración futura, comparar un export **local** de S2 y el export S3 preparado:

```sh
node backend/docs/ultron-studio/ops/motores/verificar-flujo-s3.cjs export-local-s3.json --s2=export-local-s2.json
```

El comparador rechaza cambios en nodos, parámetros, referencias de credenciales, código completo de los cuerpos, conexiones y ajustes del workflow. Exige que `active` y `settings.availableInMCP` sean los booleanos `false`, incluso si la referencia suministrada también es insegura; rechaza ausencia, `null`, `0`, `"false"` y `true`. Solo ignora identificadores y posiciones de nodos y metadatos conocidos del workflow. La fuente S2 se valida con una lista blanca: `pinData`, `staticData` y otros datos fijados o de ejecución se rechazan, sin copiarlos ni mostrarlos. Un resultado local correcto lleva `runtimeVerified:false`: no afirma que la instancia remota tenga estos nodos, credenciales, opciones o versión. No se importa, activa ni publica nada con estos comandos.

Estos ajustes no convierten S3 en un entorno de pruebas ni impiden que un operador lo ejecute manualmente desde el editor. Conserva destinos operativos de OpenAI y Laravel; no debe ejecutarse, reintentarse ni usarse como webhook de prueba sin autorización específica. La validación de esta revisión usa exclusivamente el comparador y la paridad offline con respuestas sintéticas. No modifica el MCP global, credenciales, webhooks ni workflows productivos.

La [revisión local S3/MCP](../../../backend/docs/ultron-studio/plans/informes-motores/pt17-c9-s3-mcp-revision-1.md) conserva las evidencias anteriores y registra las nuevas huellas y comprobaciones.

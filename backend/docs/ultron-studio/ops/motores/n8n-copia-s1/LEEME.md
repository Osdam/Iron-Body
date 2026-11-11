# n8n · la copia «Studio S1» del flujo de ULTRON (Enmienda M-1)

**Por qué una copia y no el borrador del flujo vivo.** El cambio S1 lleva un nodo con 119 KB de código (la base Legacy
embebida). Por el MCP habría que teclearlo; se lleva de máquina a máquina con el importador oficial de n8n
(`n8n import:workflow`), como un flujo NUEVO e INACTIVO. El flujo vivo no se toca hasta el cambio, y después queda
intacto como reserva: la vuelta atrás no depende del historial de versiones de n8n.

| | Flujo vivo | Copia S1 |
|---|---|---|
| id | `YspRwsXnqt33NouP` | `QSklqHlsDIxucRfk` |
| nombre | Iron Body - ULTRON - Commercial Advisor | … (Studio S1) |
| versión | `29de5119-2e5a-4bc8-a479-60f4fe37c5b3` (publicada) | borrador, sin publicar |
| webhook | `POST iron-body-ultron` (registrado) | el mismo path, sin registrar mientras esté inactiva |

## Lo hecho el 11-11-2026 (11:46–11:51 UTC)

1. `ultron-s1-import.json` = `n8n/flujos/ultron-studio-s1/flujo-s1.json` (generado y verificado) con id nuevo, nombre,
   `active: false` y `versionId` nuevo. Comprobador sobre el fichero: OK (111).
2. `importar-s1.sh <id> <sha256 del selector>` en el servidor: foto del flujo vivo antes y después (INTACTO), respaldo
   de sus filas en `/var/backups/ironbody/n8n-ultron-pre-s1-20261111-114631`, importación en el proyecto dueño de las
   credenciales, y comprobaciones (25 nodos, 8 con credenciales, selector = el generado, ajustes iguales).
3. Lo que n8n guardó, exportado de su base y pasado por el comprobador: **OK (111)**.
4. **Ensayo dentro de n8n** (`test_workflow` del MCP, ejecución 941511; el disparador, las dos llamadas al CRM y los
   cinco nodos de OpenAI con datos fijados: cero llamadas a Laravel y a OpenAI): éxito hasta «Responder al CRM», y
   «Preparar Commit» emite `prompts = {mode: legacy, bundle_hash: 3f6a5674…c902, reason: offered_prompts_off,
   flow_rev: S1, execution_id}`. **La huella Legacy la calculó el selector dentro del runner de n8n y es la del
   repositorio**: las constantes Legacy de n8n son la base byte a byte.
5. **Suposición del plan verificada** (flujo de prueba `A7o79a9nJQWwAVCX`, archivado; ejecución 941512): una cadena
   devuelta por una expresión NO se vuelve a evaluar. `{{PLAN_NAME}}`, `{{PLAN_PRICE}}`, `{{APP_LINKS}}`, `{{ $json.x }}`
   y `{{ 1 + 1 }}` salen literales en las tres formas (`={{ $json.t }}`, `={{ $('Nodo').first().json.t }}` y
   concatenada).

## El cambio (etapa E1; después de E0 y con la revisión cerrada)

```
unpublish_workflow(YspRwsXnqt33NouP)  →  publish_workflow(QSklqHlsDIxucRfk)
```

Comprobar en la base de n8n que `webhook_entity` apunta a `QSklqHlsDIxucRfk` y que `activeVersionId` de la copia no es
nulo. Entre las dos órdenes pasan unos segundos sin flujo publicado: solo afecta a la conversación canaria (la única
que pasa por n8n) y, si justo entra un mensaje, lo cubre el vigía.

**Vuelta atrás:** `unpublish_workflow(QSklqHlsDIxucRfk)` → `publish_workflow(YspRwsXnqt33NouP)` (su versión
`29de5119…` es su borrador vigente: no depende de la poda del historial). Verificación: `webhook_entity` vuelve a
`YspRwsXnqt33NouP` y el siguiente turno canario sale `not_reported` en `ultron:prompt-trace`.

**Si cambia el selector** (versión nueva del generador): rehacer `ultron-s1-import.json` con el MISMO id y reimportar
con la copia INACTIVA (el guion se niega si está publicada); comprobador y ensayo otra vez.

**No pendiente de comprobar aquí:** un turno real con OpenAI en la copia (se ve en el primer turno canario de E1, con
las llaves en `off`: la traza debe decir `legacy`, `flowRev S1`, huella Legacy).

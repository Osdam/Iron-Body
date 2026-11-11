# ULTRON STUDIO · cambio «S1» del flujo de n8n (GENERADO)

> Generado por `php artisan ultron:prompts-n8n` desde la base `n8n-29de5119` del repositorio y el respaldo del flujo `YspRwsXnqt33NouP` versión `29de5119-2e5a-4bc8-a479-60f4fe37c5b3`. **No se edita a mano**: si hay que cambiar algo, se cambia el generador o la base y se vuelve a generar. Contrato: Enmienda M-1 del H5 y `docs/ultron-studio/plans/STUDIO_MOTORES_PLAN.md` §B.7–B.8.

## Qué contiene

| Fichero | Qué es |
|---|---|
| `elegir-prompts.js` | El código del nodo nuevo «Elegir Prompts» (Code v2, JavaScript, «Run Once for All Items»), con la base embebida como constantes Legacy. |
| `preparar-commit.js` | El `jsCode` completo de «Preparar Commit» en S1 (el de 29de5119 + el bloque `prompts`). |
| `operaciones-mcp.json` | Las operaciones para `update_workflow` del MCP, en orden: 1 `addNode`, 1 `removeConnection`, 2 `addConnection`, 5 `setNodeParameter` de `/responses` (se reescribe el array entero: el MCP no indexa arrays; `values[1]` va intacto), 1 de `/jsonBody` y 1 de `/jsCode`. **Los nombres de campo de cada operación hay que contrastarlos con el esquema del MCP antes de usarlos**; el contenido (nodos, rutas, valores) es el verificado. |
| `flujo-s1.json` | El flujo completo esperado tras aplicar las operaciones. **Solo para el comprobador. NUNCA se importa en n8n.** |
| `huellas.json` | Huellas esperadas (base, Legacy, selector, Preparar Commit, cuerpo de decide, contenidos de sistema, `values[1]`). |
| `fixtures/decide-studio.json`, `fixtures/decide-legacy.json` | Respuestas sintéticas de `decide` (sin datos de nadie) para ejecutar el selector a mano en la copia. La `studio` es `persona.tone = profesional` + `persona.maxChars = 200`, compilada en PHP. |

## Huellas

- Huella del paquete Legacy (`LEGACY_BUNDLE_HASH`): `3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902`
- Selector: 118831 bytes, sha256 `456e4d495115cf4b126277773b6f4998530a78635f850eac6b8b7ec4e7350d0f`
- Preparar Commit: sha256 `fb35df55dbfe6825482baa7f30b4f41050ede24d6eecfc92f23585a9f8fff7ce`
- Contrato `ultron-prompts/1`, base `n8n-29de5119`, compilador 1, revisión del flujo `S1`

| Rol | Nodo | Bytes | sha256 |
|---|---|---|---|
| `strategist` | Strategist | 29243 | `c84387582f581640493545996c7c61ab9a46d11829d740f1319c34b5c50a6917` |
| `composer` | Composer | 27132 | `78437378e4a3b7bfba0b407d73b9e165b3a28e1e0555371bf771d0e47780e866` |
| `critic` | Critic Comercial | 15583 | `180aa6c37257448f24a15e0ba7dafab918693a60cecf19e6b8141471e200c896` |
| `composer_retry` | Composer Reintento | 25023 | `92d97a04316cf5a6fecb4a70c3f0e573c49d54f881bf6f2733b96524cb279ccd` |
| `critic_retry` | Critic Comercial 2 | 15235 | `a03940c2f4d0d5f21f3ee49007162b1057106bb619aa162292a22673009315ed` |

## Cómo se aplica (lo hace el lead; con orden del propietario, etapa E1)

1. **Copia primero.** Duplicar el flujo en n8n (inactivo, otro path de webhook, nombre «… (copia Studio S1)»). Aplicar las operaciones de `operaciones-mcp.json` a la copia. Si el MCP no admite un `jsCode` de este tamaño o confirma sin escribir, aplicarlas con el editor de n8n o su API pública.
2. Exportar la copia y pasar el comprobador (desde la raíz del repositorio):
   `node backend/docs/ultron-studio/ops/motores/verificar-flujo.cjs <export.json>` → tiene que terminar con `OK` y código 0.
3. Ensayo manual en la copia con `Ejecutar en el CRM` **desactivado**, fijando datos en `Entrada ULTRON` y `Pedir Contexto al CRM` con los fixtures de `fixtures/`.
4. Producción: las mismas operaciones en un borrador del flujo real → `versionId` nuevo → exportar → comprobador → publicar (`publish_workflow` con el UUID completo o sin `versionId`) → comprobar `versionId == activeVersionId` → primer turno canario con las llaves en `off`: `php artisan ultron:prompt-trace` tiene que dar `legacy`, `flowRev S1` y la huella Legacy de arriba.

## Vuelta atrás

Restaurar y publicar la versión `29de5119-2e5a-4bc8-a479-60f4fe37c5b3` **desde el historial de versiones de n8n**. **Nunca reimportando un JSON** (ni el respaldo ni `flujo-s1.json`): no llevan los secretos de las credenciales y todos los turnos morirían. Verificación: `versionId` activo = `29de5119-2e5a-4bc8-a479-60f4fe37c5b3` y el siguiente turno canario sale `not_reported` (el flujo viejo no negocia) con respuesta normal.

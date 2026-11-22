# ULTRON STUDIO · flujo «S2» de n8n: regenerar antes de sustituir (GENERADO)

> Generado por `php artisan ultron:prompts-n8n --rev=S2` desde S1 (que sale del respaldo `YspRwsXnqt33NouP` versión `29de5119-2e5a-4bc8-a479-60f4fe37c5b3` y de la base del repositorio). **No se edita a mano**: si hay que cambiar algo, se cambia el generador (`UltronRegenN8nFlow`) y se vuelve a generar. Plan: `docs/ultron-studio/plans/informes-motores/plan-arquitecto-a4-regenerar.md` §5. Contrato `ultron-regen/1` (`POST /api/internal/marketing/ai/check`, de solo lectura).

## Qué es

S2 = los 25 nodos de S1 + 14 nuevos, **desenrollado (sin bucles)**. Entre el redactor y el revisor, n8n le pregunta al servidor si aceptaría el borrador; si pide reescribir, el redactor de la reescritura recibe la nota del servidor donde hoy recibe la del revisor (`critic_issues`, `critic_notes`: su `values[1]` no cambia). Como mucho hay **3 borradores**: el tercero no se comprueba, pero lo juzga un revisor. El tope está en la forma del grafo, no en un contador.

```
Composer → ¿Comprobar 1? ─no (sin oferta)→ Critic Comercial
             └sí→ Comprobar Borrador 1 → Leer Comprobación 1 → ¿Reescribir 1? ─no→ Critic Comercial
                                                                 └sí→ Nota del Servidor 1 → Composer Reintento
Critic Comercial → Critic aprueba? ─sí→ Preparar Commit · ─no→ Composer Reintento        (como en S1)
Composer Reintento → ¿Comprobar 2? ─no (sin oferta)→ Critic Comercial 2
             └sí→ Comprobar Borrador 2 → Leer Comprobación 2 → ¿Reescribir 2? ─no→ Critic Comercial 2
                                                                 └sí→ Nota del Servidor 2 → Composer Reintento 2
Critic Comercial 2 → Decidir Intento 3 → ¿Intento 3? ─no→ Preparar Commit
                                           └sí→ Composer Reintento 2 → Critic Comercial 3 → Preparar Commit
(Preparar Commit → Ejecutar en el CRM → … → rendición: como en S1)
```

**Sin la oferta del servidor** (`decide` no devuelve `regeneration` con `ultron-regen/1`: un turno Legacy o un servidor anterior) el recorrido es el de S1: las mismas llamadas al modelo con los mismos datos, ninguna a `ai/check`, y Preparar Commit con la misma salida, sin el bloque. Lo único que cambia es la revisión que sella el selector (`flowRev S2`).

**Con la oferta:** como mucho 3 redactores y 3 revisores en cualquier camino; la comprobación se llama como mucho 2 veces, con 3000 ms de tiempo máximo y **sin reintentos**; cualquier respuesta que no sea un 200 bien formado del contrato es «no reescribir» y se sigue el camino de hoy; Preparar Commit añade el bloque `regeneration` (borradores, revisores, comprobaciones y sus errores, quién pidió cada reescritura y con qué códigos, y los tokens si OpenAI los entrega) dentro de un `try`: si falla, sale sin bloque, nunca sin respuesta.

## Qué contiene

| Fichero | Qué es |
|---|---|
| `flujo-s2.json` | El flujo completo: 39 nodos (12 con credencial, todas por referencia y sin secretos), 33 orígenes y 45 enlaces. Sin `id` ni `versionId` y con `active: false`: importarlo tal cual no pisa ningún flujo. Para el comprobador y, con id y `versionId` nuevos, para importarlo como copia INACTIVA. **Nunca** encima de un flujo publicado ni como vuelta atrás. |
| `preparar-commit.js` | El `jsCode` de «Preparar Commit» en S2: el de S1 + el tercer borrador + el bloque `regeneration`. |
| `leer-comprobacion-1.js`, `leer-comprobacion-2.js` | «Leer Comprobación 1» y «2» (difieren en el borrador que leen y en el intento que esperan). |
| `nota-del-servidor.js` | «Nota del Servidor 1» y «2» (el mismo código). |
| `decidir-intento-3.js` | «Decidir Intento 3». |
| `huellas.json` | Las huellas esperadas (nodos, conexiones, selector, Preparar Commit, Code nuevos, cuerpo de la comprobación, contenidos de sistema, `values[1]`, vocabulario). |
| `fixtures/decide-*.json` | Respuestas sintéticas de `decide` (sin datos de nadie): `studio` (con `regeneration`) y `legacy` (sin él). |
| `fixtures/check-*.json` | Salidas sintéticas del nodo HTTP de la comprobación (`fullResponse`): `pasa`, `reescribe` (con las notas del servidor), `legacy`, `404`, `422`, `error-de-red` y `mal-formada`. |

Los nodos nuevos: ¿Comprobar 1? · Comprobar Borrador 1 · Leer Comprobación 1 · ¿Reescribir 1? · Nota del Servidor 1 · ¿Comprobar 2? · Comprobar Borrador 2 · Leer Comprobación 2 · ¿Reescribir 2? · Nota del Servidor 2 · Decidir Intento 3 · ¿Intento 3? · Composer Reintento 2 · Critic Comercial 3.

## Huellas

- Selector («Elegir Prompts», `FLOW_REV = "S2"`): 118831 bytes, sha256 `2aa62c3f3dcf1624c56d0e42781e8afd0f19ea77167fd583d8ad523f813997c5`
- Preparar Commit: sha256 `8258faaefe8e7df2ed497dc0d7d935e1438f6992ec27b81be312c6a3ef76c109`
- Comprobación: `https://api.ironbodyneiva.cloud/api/internal/marketing/ai/check`, 3000 ms, 0 reintentos, la credencial de «Pedir Contexto al CRM»

| Nodo | Fichero | Bytes | sha256 |
|---|---|---|---|
| Leer Comprobación 1 | `leer-comprobacion-1.js` | 3353 | `b34438a011038155c5d40607c717b5634c87ec3ed509908d4611c49c30abd605` |
| Leer Comprobación 2 | `leer-comprobacion-2.js` | 3363 | `31dc4a9eb753a434715bd9003bdb48df02283b272bf196275cc27247dcbe8bb1` |
| Nota del Servidor 1 | `nota-del-servidor.js` | 903 | `dea0ed184c9f5550cc05f995e8d2ea7082dad7f804c335a1343bc59a1db3c247` |
| Nota del Servidor 2 | `nota-del-servidor.js` | 903 | `dea0ed184c9f5550cc05f995e8d2ea7082dad7f804c335a1343bc59a1db3c247` |
| Decidir Intento 3 | `decidir-intento-3.js` | 1640 | `133872f4b7e062fd89d1a93fe7efdf554db278547125e6de811133647c2aca59` |

## Los recorridos (plan §5.2)

| Caso | Nodos de modelo | Comprobaciones |
|---|---|---|
| Legacy, pasa | Strategist, Composer y Critic | 0 |
| Legacy, el revisor falla | + Composer Reintento y Critic 2 (igual que hoy) | 0 |
| Studio, pasa | Strategist, Composer y Critic | 1 |
| Studio, el servidor pide 1 | Strategist, Composer, Composer Reintento y Critic 2 | 2 |
| Studio, el revisor pide 1 | Strategist, Composer, Critic, Composer Reintento y Critic 2 | 2 |
| Studio, dos reescrituras | hasta Strategist, 3 Composer y 3 Critic (7 llamadas) | 2 |

El comprobador los ejecuta todos (y los del servidor caído) con los IF y los Code reales.

## Cómo se comprueba (sin red y sin tocar n8n)

```bash
# desde la raíz del repositorio
node backend/docs/ultron-studio/ops/motores/verificar-flujo.cjs --rev=S2 <export.json> \
     [--s1=n8n/flujos/ultron-studio-s1/flujo-s1.json] [--generado=n8n/flujos/ultron-studio-s2]
```

Sale con 0 solo si todo cuadra (apartados A a J); con 1 ante cualquier diferencia; con 2 si no puede leer las entradas.

## Cómo se importa, se ensaya y se cambia

`backend/docs/ultron-studio/ops/motores/n8n-copia-s2/LEEME.md` (con `importar-s2.sh`). **ESCRITO Y SIN EJECUTAR:** el n8n en marcha no se toca antes del gate 4-H (L-6 del plan); el cambio de S1 a S2 lleva orden del propietario.

## Vuelta atrás

`unpublish(S2) → publish(QSklqHlsDIxucRfk)`: la copia S1 queda intacta como reserva. Verificación: el siguiente turno canario sale con `flowRev S1` en `php artisan ultron:prompt-trace`. **Nunca reimportando un JSON** (no lleva los secretos de las credenciales).

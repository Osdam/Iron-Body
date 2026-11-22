# n8n · la copia «Studio S2» del flujo de ULTRON («regenerar antes de sustituir», ola 5)

> **EJECUTADO el 22-11-2026 (03:20 a 03:33 UTC) por el lead, con la autorización del propietario (gate 4-H) y después del backend.** La copia S2 es `4CIwgGbPRneqUjLF` (versión `a68cf2bc…`), importada inactiva, comprobada, ensayada dentro de n8n y **publicada a las 03:33 UTC en lugar de la copia S1**, que queda despublicada e intacta como reserva. Evidencia: `plans/informes-motores/a4-evidencia/152` (importación), `153` (ensayo) y `154` (el cambio). Respaldo de las filas de antes: `/var/backups/ironbody/n8n-ultron-pre-s2-20261122-032421`.
>
> **Lo que el ensayo confirmó de lo marcado [S] más abajo:** (1) la trampa de los datos fijados EXISTE (ejecución 1078390: con «Composer Reintento» fijado de más, Preparar Commit dio `critic.attempt = 2`): se fijan solo los nodos de OpenAI que corren, y para generarlos está `pins-s2.cjs`; (2) `fullResponse` entrega `statusCode` como número (ejecución 1078398, una llamada REAL de n8n a `ai/check` con un token sintético: 422 `invalid_token` en 245 ms, con la credencial aceptada). **El primer turno canario de verdad (22-11, 03:44 UTC, en modo Legacy) salió por S2:** ejecución 1078399, `legacy 3f6a5674 S2` en `ultron:prompt-trace` (`a4-evidencia/157`). **Sigue sin verse** hasta el primer turno con una versión del Studio: el bloque `regeneration` de verdad, la forma de un tiempo agotado y si OpenAI entrega `usage`.
>
> Escrito el 21-11-2026 por el agente `backend`; derivado de `../n8n-copia-s1/LEEME.md` e `importar-s1.sh`. Lo que sigue es el procedimiento tal como se ejecutó; sirve para repetirlo (reimportar con el mismo id exige la copia S2 inactiva).

**Qué es S2.** El flujo S1 con 14 nodos más, generado desde el repositorio (`php artisan ultron:prompts-n8n --rev=S2` → `n8n/flujos/ultron-studio-s2/`, con su `LEEME.md`): entre el redactor y el revisor le pregunta al servidor (`POST /api/internal/marketing/ai/check`) si aceptaría el borrador, reescribe con la nota del servidor y puede llegar a un tercer borrador como mucho. Sin la oferta de `decide` (Legacy, o un backend sin la ola 4) se comporta como S1.

**Por qué una copia y no el borrador del flujo publicado.** Lo mismo que en S1: el selector lleva 119 KB de código y S2 añade cinco Code y catorce nodos; se lleva de máquina a máquina con el importador oficial (`n8n import:workflow`) como un flujo NUEVO e INACTIVO. Lo publicado no se toca hasta el cambio, y después queda intacto como reserva: la vuelta atrás no depende del historial de versiones de n8n.

| | Reserva (el de siempre) | Copia S1 (publicada hoy) | Copia S2 (nueva) |
|---|---|---|---|
| id | `YspRwsXnqt33NouP` | `QSklqHlsDIxucRfk` | `4CIwgGbPRneqUjLF` (elegida el 22-11-2026) |
| nombre | Iron Body - ULTRON - Commercial Advisor | … (Studio S1) | Iron Body - ULTRON - Commercial Advisor (Studio S2) |
| estado desde el 22-11-2026, 03:33 UTC | despublicado, intacto | despublicado, intacto: la reserva para volver atrás | **publicado** (versión activa `a68cf2bc…`) |
| webhook | `POST iron-body-ultron` (sin registrar) | el mismo path, sin registrar | el mismo path, **registrado** |

## Orden y condiciones

1. **Primero el backend** con la ruta `ai/check` (plan §9, paso 1). S2 sobre un backend sin la ola 4 también funciona —`decide` no ofrece `regeneration` y S2 hace lo de S1—, pero nunca usaría la comprobación.
2. Todo esto con la copia S1 publicada y atendiendo la canaria: importar una copia inactiva no la toca (lo comprueba el guion).
3. **Nada se publica sin orden del propietario** (paso 5).

## Paso 0 · En el repositorio (sin red)

```bash
cd backend && php artisan ultron:prompts-n8n --rev=S2 && cd ..        # no puede dejar diferencias: git status limpio en n8n/
node backend/docs/ultron-studio/ops/motores/verificar-flujo.cjs --rev=S2 n8n/flujos/ultron-studio-s2/flujo-s2.json   # → OK (… comprobaciones)
```

Si el generador cambió después de una importación anterior: se regenera, se vuelve a pasar el comprobador y se reimporta con el MISMO id (paso 1), con la copia S2 todavía inactiva (el guion se niega si está publicada).

## Paso 1 · El fichero de importación (en la máquina de trabajo)

`flujo-s2.json` viene sin `id` ni `versionId` y con `active: false` a propósito: importarlo tal cual no puede pisar ningún flujo. Para importarlo se le pone un id nuevo (o el de la copia S2 si se reimporta) y un `versionId` nuevo, y se le quita `_generado`:

```bash
# desde la raíz del repositorio; imprime el id (para reimportar, pasarlo como argumento)
node -e '
const fs = require("fs"), crypto = require("crypto");
const f = JSON.parse(fs.readFileSync("n8n/flujos/ultron-studio-s2/flujo-s2.json", "utf8"));
const abc = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789";
const id = process.argv[1] || Array.from(crypto.randomBytes(16), b => abc[b % 62]).join("");
delete f._generado; delete f.updatedAt;
Object.assign(f, { id, active: false, versionId: crypto.randomUUID() });
fs.writeFileSync("ultron-s2-import.json", JSON.stringify(f), { mode: 0o600 });
console.log(id);
' [<id de la copia S2, si se reimporta>]
node backend/docs/ultron-studio/ops/motores/verificar-flujo.cjs --rev=S2 ultron-s2-import.json   # → OK, también sobre el preparado
```

El fichero lleva las credenciales como en S1: **referencias** de n8n (id y nombre de «IRON BODY Internal API» y «OpenAI account»), nunca el secreto. Se sube al servidor como `/root/n8n-s2/ultron-s2-import.json`, de root y en 600, y se borra de la máquina de trabajo.

## Paso 2 · Importar la copia INACTIVA (servidor, root)

```bash
H=n8n/flujos/ultron-studio-s2/huellas.json     # en la máquina de trabajo: los dos sha256 que exige el guion
node -p 'require("./'$H'").selector.jsCodeSha256'; node -p 'require("./'$H'").preparaCommitJsCodeSha256'

bash importar-s2.sh <id> <sha256 del selector> <sha256 de Preparar Commit> --solo-comprobar   # nada se importa
bash importar-s2.sh <id> <sha256 del selector> <sha256 de Preparar Commit>
```

Lo que hace `importar-s2.sh` (y aborta, sin importar nada, si algo no cuadra):

1. **El fichero**, con el Node del contenedor de n8n y por la entrada estándar: el id pedido, `active: false`, el nombre, `versionId` y sin `_generado`; 39 nodos y 12 con credencial; el selector y Preparar Commit con los sha256 de `huellas.json`; las dos comprobaciones con 3 s, sin reintentos y `continueRegularOutput`.
2. **Antes:** la copia S1 publicada (con versión activa), la reserva despublicada y el webhook `POST iron-body-ultron` en la copia S1. Si el id ya existe, solo se reimporta si está inactivo.
3. **Respaldo** de las filas de la copia S1, de la reserva y del webhook en `/var/backups/ironbody/n8n-ultron-pre-s2-<fecha>` (700, ficheros 600 y `SHA256SUMS`).
4. **Importa** en el proyecto dueño de las credenciales (`TEt5qxIcC0S7DedW`, el de S1).
5. **Después:** la copia S1, la reserva y el webhook INTACTOS (misma versión, nodos, conexiones y ajustes); el nuevo inactivo, sin versión publicada, 39 nodos, 12 con credencial, selector y Preparar Commit iguales a los generados, los ajustes los de la copia S1 (flujo de errores incluido).
6. **Exporta** el flujo tal como quedó a `/root/n8n-s2/exportado-de-n8n.json` (600).

Termina con `COPIA S2 IMPORTADA, INACTIVA Y COMPROBADA` y código 0; con cualquier problema, código 1.

## Paso 3 · El comprobador sobre lo que guardó n8n

Traer `exportado-de-n8n.json` (referencias de credenciales, sin secretos) y, desde la raíz del repositorio:

```bash
node backend/docs/ultron-studio/ops/motores/verificar-flujo.cjs --rev=S2 exportado-de-n8n.json   # → OK
```

Es la prueba de que lo importado es exactamente lo generado: los 25 de S1 iguales a S1, los 14 nuevos con sus parámetros, las conexiones, y los recorridos ejecutados con los Code y los IF tal como los guardó n8n.

## Paso 4 · Ensayo dentro de n8n con datos fijados (sobre la copia S2, inactiva)

Como en S1 (`test_workflow` del MCP, ejecución 941511): **cero llamadas a Laravel y a OpenAI**. En cada ejecución se fijan datos en:

- **siempre:** «Entrada ULTRON», «Pedir Contexto al CRM» (con `fixtures/decide-studio.json` o `fixtures/decide-legacy.json`), «Ejecutar en el CRM» (un 200 del commit) y **las dos comprobaciones** («Comprobar Borrador 1» y «2», con el `fixtures/check-*.json` del caso). Fijar las comprobaciones aunque no corran es inocuo: lo que se mira después es «Leer Comprobación k», un Code, que no se fija nunca;
- **solo los nodos de OpenAI que ese recorrido ejecuta** (la tabla), con la forma `{"output":[{"content":[{"text":{…}}]}]}` que ya leen los `values[1]` de S1: el Strategist de siempre; los redactores con `reply_draft`; los revisores con `verdict` (`pass` o `fail`), `issues`, `notes` y `hard_fail`.

**Cuidado [S, sin comprobar]:** en una ejecución manual, n8n puede devolver los datos fijados de un nodo que NO corrió cuando otro lo referencia con `$('Nodo').first()` (lo que en el código de n8n hace `getPinDataIfManualExecution`). Si se fija «Composer Reintento» en un recorrido en el que no corre, Preparar Commit creería que hubo segundo borrador (también en S1). Por eso se fijan solo los de OpenAI que corren. La primera ejecución puede servir para comprobarlo: «Studio, pasa» con «Composer Reintento» fijado de más; si Preparar Commit sale con `critic.attempt = 2`, la trampa existe y se confirma la regla. Y si un recorrido llega a un nodo de OpenAI sin fijar, ese nodo llamaría a OpenAI de verdad: **es un fallo del ensayo**; se para y se informa.

| Recorrido | decide | Comprobaciones | OpenAI fijados (revisores: veredicto) | Preparar Commit tiene que dar |
|---|---|---|---|---|
| Legacy, pasa | legacy | (no corren) | Strategist, Composer, Critic Comercial: pass | `critic.attempt` 1, sin `regeneration` (igual que S1, salvo `prompts.flow_rev = S2`) |
| Legacy, el revisor falla | legacy | (no corren) | + Composer Reintento, Critic Comercial: fail, Critic Comercial 2: pass | `attempt` 2, sin `regeneration`; «Decidir Intento 3» corre y dice `false` |
| Studio, pasa | studio | 1: `check-pasa` | Strategist, Composer, Critic Comercial: pass | `attempt` 1; `regeneration` = {drafts 1, critics 1, checks 1, check_errors 0, rewrites []} |
| Studio, el servidor pide 1 | studio | 1: `check-reescribe`; 2: `check-pasa` con `attempt: 2` | Strategist, Composer, Composer Reintento, Critic Comercial 2: pass | `attempt` 2; {2, 1, 2, 0, [server con `register_tuteo` y `length_over_cap`]}; en la entrada de «Composer Reintento», `critic_notes` = la nota del servidor |
| Studio, el revisor pide 1 | studio | 1: `check-pasa`; 2: `check-pasa` con `attempt: 2` | + Critic Comercial: fail, Composer Reintento, Critic Comercial 2: pass | `attempt` 2; {2, 2, 2, 0, [critic con sus issues]} |
| Studio, dos reescrituras (peor caso) | studio | 1: `check-pasa`; 2: `check-pasa` con `attempt: 2` | los siete: Critic Comercial: fail, Critic Comercial 2: fail, Critic Comercial 3: pass | `attempt` 3 y el texto del tercer borrador; {3, 3, 2, 0, [critic, critic]} |
| Studio, el servidor caído | studio | 1: `check-404`; 2: `check-error-de-red` | + Critic Comercial: fail, Composer Reintento, Critic Comercial 2: fail | `attempt` 2, sin tercer borrador; {2, 2, 2, 2, [critic]} |

Los `tokens` del bloque salen `null` si algún nodo de OpenAI fijado no lleva `usage`, que es lo normal con datos fijados.

## Paso 5 · El cambio (con orden del propietario)

```
unpublish_workflow(QSklqHlsDIxucRfk)  →  publish_workflow(<id de la copia S2>)
```

Con el UUID completo o sin `versionId` (trampa conocida del MCP). Comprobar en la base de n8n que `webhook_entity` apunta a la copia S2 y que su `activeVersionId` no es nulo. Entre las dos órdenes pasan unos segundos sin flujo publicado: solo afecta a la conversación canaria (la única que pasa por n8n) y, si justo entra un mensaje, lo cubre el vigía.

**Verificación:** el siguiente turno canario sale con `flowRev S2` en `php artisan ultron:prompt-trace`. Con una versión Legacy, la fila no lleva `metadata.regeneration` (S2 hace lo de S1); con la versión 3 publicada en canario, sí, con `drafts` ≤ 3 y `consistent = true` (criterio A5 del plan, §12).

## Vuelta atrás

```
unpublish_workflow(<id de la copia S2>)  →  publish_workflow(QSklqHlsDIxucRfk)
```

La copia S1 queda intacta como reserva (el mismo mecanismo que S1 frente a `YspRwsXnqt33NouP`). Verificación: `webhook_entity` vuelve a `QSklqHlsDIxucRfk` y el siguiente turno canario sale con `flowRev S1` en `ultron:prompt-trace`. **Nunca reimportando un JSON** como vuelta atrás: no lleva los secretos de las credenciales y, encima de un flujo publicado, todos los turnos morirían. Si hiciera falta, el respaldo del paso 2 tiene las filas de antes.

## Lo que no está comprobado (y dónde se comprueba)

- **Que el importador conserve los 39 nodos y sus referencias** tal cual: lo dicen los pasos 2 y 3 (como en S1, que conservó los 25).
- **La forma exacta de lo que deja el nodo HTTP** ante un tiempo agotado con `continueRegularOutput` (`fixtures/check-error-de-red.json` es una suposición) y si `fullResponse` da `statusCode` como número: «Leer Comprobación» trata cualquier cosa que no sea un 200 bien formado como «no reescribir», así que una forma distinta acaba en el camino de hoy. El ensayo no lo puede ver (las comprobaciones se fijan con la forma supuesta). Se ve en el primer turno canario: con el servidor sano tiene que salir `check_errors = 0`; el caso del tiempo agotado no se provoca, se lee si ocurre.
- **Si el nodo de OpenAI 2.3 entrega `usage`** (los tokens del bloque): con datos fijados no se puede saber. Se ve en el primer turno canario de verdad: `metadata.regeneration.tokens` con números o `null` (`php artisan ultron:origin-report`, línea «Tokens»).
- **La trampa de los datos fijados** del paso 4 (marcada [S] arriba).
- **Los 3 s de la comprobación** frente a su latencia real (el servidor lee de 6 a 12 sentencias `select`; el plan supone de 0,1 a 0,3 s): se ve en `ultron.check.result` del log y en la latencia del informe de origen.

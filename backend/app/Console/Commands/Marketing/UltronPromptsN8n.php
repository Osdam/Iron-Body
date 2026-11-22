<?php

namespace App\Console\Commands\Marketing;

use App\Services\Marketing\Ultron\Prompts\UltronPromptBase;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler;
use App\Services\Marketing\Ultron\Prompts\UltronPromptsN8nFlow;
use App\Services\Marketing\Ultron\Prompts\UltronRegenN8nFlow;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

/**
 * GENERA EL CAMBIO «S1» DEL FLUJO DE n8n (Enmienda M-1 del H5; plan de motores
 * §B.7). Nadie lo escribe a mano: sale de la base del repositorio y del
 * respaldo del flujo vivo 29de5119.
 *
 * Escribe en `--out` (por defecto `n8n/flujos/ultron-studio-s1`, relativo a
 * la RAÍZ del repositorio, donde vive `n8n/`):
 *
 *   elegir-prompts.js        el código del nodo nuevo, con la base embebida
 *   preparar-commit.js       el código de `Preparar Commit` con el bloque `prompts`
 *   operaciones-mcp.json     las operaciones atómicas para `update_workflow`
 *   flujo-s1.json            el flujo completo esperado (SOLO para el comprobador; nunca se importa)
 *   huellas.json             las huellas esperadas
 *   fixtures/decide-*.json   respuestas sintéticas de `decide` para el comprobador (studio, studio con frases, legacy)
 *   LEEME.md                 cómo se aplica, se verifica y se revierte
 *
 * Con `--rev=S2` («regenerar antes de sustituir», ola 5; plan
 * `plan-arquitecto-a4-regenerar.md` §5.3) genera, desde ese mismo S1, el flujo
 * S2 en `--out` (por defecto `n8n/flujos/ultron-studio-s2`):
 *
 *   flujo-s2.json            el flujo completo (para el comprobador y, con id y versionId nuevos, para importarlo como copia INACTIVA)
 *   preparar-commit.js       «Preparar Commit» de S2 (el de S1 + el tercer borrador + el bloque `regeneration`)
 *   leer-comprobacion-1.js, leer-comprobacion-2.js, nota-del-servidor.js, decidir-intento-3.js   los Code nuevos
 *   huellas.json             las huellas esperadas
 *   fixtures/                respuestas sintéticas de `decide` (studio, legacy) y de `ai/check` (pasa, reescribe, legacy, 404, 422, red, mal formada)
 *   LEEME.md                 qué es, cómo se comprueba y dónde está el procedimiento de importación
 *
 * Sin `--rev` (o con `--rev=S1`), S1 exactamente como siempre.
 *
 * Determinista: dos ejecuciones dan los mismos bytes. No toca n8n, ni la red,
 * ni la base de datos.
 */
class UltronPromptsN8n extends Command
{
    protected $signature = 'ultron:prompts-n8n
        {--rev=S1 : revisión del flujo: S1 (el selector de prompts) o S2 (S1 + «regenerar antes de sustituir»)}
        {--out= : directorio de salida (relativo a la raíz del repositorio; por defecto n8n/flujos/ultron-studio-s1 o -s2, según --rev)}
        {--from=n8n/respaldos/20261111-ultron-29de5119/ultron-commercial-advisor.json : respaldo del flujo 29de5119 (relativo a la raíz del repositorio)}';

    protected $description = 'Genera el flujo de n8n de ULTRON desde la base del repositorio: S1 (selector «Elegir Prompts», operaciones del MCP, huellas y LEEME) o, con --rev=S2, S2 («regenerar antes de sustituir»).';

    /** El directorio de salida de cada revisión, si no se da `--out`. */
    private const SALIDAS = ['S1' => 'n8n/flujos/ultron-studio-s1', 'S2' => 'n8n/flujos/ultron-studio-s2'];

    public function handle(): int
    {
        $rev = (string) $this->option('rev');
        if (! isset(self::SALIDAS[$rev])) {
            $this->error('--rev tiene que ser S1 o S2; no '.json_encode($rev).'. No se ha generado nada.');

            return self::FAILURE;
        }
        $desde = $this->ruta((string) $this->option('from'));
        $salida = $this->ruta((string) ($this->option('out') ?: self::SALIDAS[$rev]));

        return $rev === 'S2' ? $this->generaS2($desde, $salida) : $this->generaS1($desde, $salida);
    }

    private function generaS1(string $desde, string $salida): int
    {
        try {
            $s1 = UltronPromptsN8nFlow::build(UltronPromptsN8nFlow::decode((string) @file_get_contents($desde)));
            $fixtures = UltronPromptsN8nFlow::fixtures();
        } catch (JsonException) {
            $this->error("No se puede leer el respaldo: {$desde}");

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('No se puede generar S1: '.$e->getMessage());

            return self::FAILURE;
        }

        $ficheros = [
            'elegir-prompts.js' => $s1['selector']."\n",
            'preparar-commit.js' => $s1['commit']."\n",
            'operaciones-mcp.json' => self::json(['workflowId' => UltronPromptsN8nFlow::WORKFLOW_ID, 'fromVersionId' => UltronPromptsN8nFlow::SOURCE_VERSION, 'flowRev' => UltronPromptsN8nFlow::FLOW_REV, 'operations' => $s1['operations']]),
            'flujo-s1.json' => self::json($s1['flow']),
            'huellas.json' => self::json($s1['hashes']),
            'fixtures/decide-studio.json' => self::json($fixtures['studio']),
            'fixtures/decide-studio-frases.json' => self::json($fixtures['studioPhrases']),
            'fixtures/decide-legacy.json' => self::json($fixtures['legacy']),
            'LEEME.md' => self::leeme($s1['hashes']),
        ];

        if (! $this->escribe($salida, $ficheros)) {
            return self::FAILURE;
        }

        $this->info('S1 generado en '.$salida);
        $this->line('  huella Legacy:    '.UltronPromptBase::LEGACY_BUNDLE_HASH);
        $this->line('  selector:         '.$s1['hashes']['selector']['jsCodeBytes'].' bytes, sha256 '.$s1['hashes']['selector']['jsCodeSha256']);
        $this->line('  Preparar Commit:  sha256 '.$s1['hashes']['preparaCommitJsCodeSha256']);
        $this->line('  operaciones:      '.count($s1['operations']));
        $this->line('Verificar: node backend/docs/ultron-studio/ops/motores/verificar-flujo.cjs '.$this->relativa($salida.'/flujo-s1.json'));

        return self::SUCCESS;
    }

    /**
     * S2 desde el S1 que se genera hoy (en memoria: el mismo que verifica
     * `UltronPromptsN8nCommandTest` contra el repositorio). Nada se escribe
     * si algo no cuadra.
     */
    private function generaS2(string $desde, string $salida): int
    {
        try {
            $s1 = UltronPromptsN8nFlow::build(UltronPromptsN8nFlow::decode((string) @file_get_contents($desde)));
            $s2 = UltronRegenN8nFlow::build($s1['flow']);
        } catch (JsonException) {
            $this->error("No se puede leer el respaldo: {$desde}");

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('No se puede generar S2: '.$e->getMessage());

            return self::FAILURE;
        }

        $ficheros = ['flujo-s2.json' => self::json($s2['flow']), 'huellas.json' => self::json($s2['hashes'])];
        foreach ($s2['code'] as $nombre => $js) {
            $ficheros[$nombre] = $js."\n";
        }
        foreach ($s2['fixtures'] as $nombre => $fixture) {
            $ficheros['fixtures/'.$nombre.'.json'] = self::json($fixture);
        }
        $ficheros['LEEME.md'] = self::leemeS2($s2['hashes']);

        if (! $this->escribe($salida, $ficheros)) {
            return self::FAILURE;
        }

        $h = $s2['hashes'];
        $this->info('S2 generado en '.$salida);
        $this->line('  nodos:            '.$h['nodes']['total'].' ('.$h['nodes']['s1'].' de S1 + '.$h['nodes']['new'].'), '.$h['nodes']['withCredentials'].' con credencial');
        $this->line('  conexiones:       '.$h['connections']['sources'].' orígenes, '.$h['connections']['links'].' enlaces');
        $this->line('  selector:         '.$h['selector']['jsCodeBytes'].' bytes, sha256 '.$h['selector']['jsCodeSha256']);
        $this->line('  Preparar Commit:  sha256 '.$h['preparaCommitJsCodeSha256']);
        $this->line('Verificar: node backend/docs/ultron-studio/ops/motores/verificar-flujo.cjs --rev=S2 '.$this->relativa($salida.'/flujo-s2.json'));

        return self::SUCCESS;
    }

    /** @param array<string,string> $ficheros */
    private function escribe(string $salida, array $ficheros): bool
    {
        foreach ($ficheros as $nombre => $contenido) {
            $ruta = $salida.'/'.$nombre;
            if (! is_dir(dirname($ruta)) && ! mkdir(dirname($ruta), 0775, true) && ! is_dir(dirname($ruta))) {
                $this->error('No se puede crear '.dirname($ruta));

                return false;
            }
            if (file_put_contents($ruta, $contenido) === false) {
                $this->error("No se puede escribir {$ruta}");

                return false;
            }
        }

        return true;
    }

    /** Una ruta absoluta tal cual; una relativa, contra la raíz del repositorio (el padre de `backend/`). */
    private function ruta(string $ruta): string
    {
        if ($ruta !== '' && $ruta[0] === '/') {
            return rtrim($ruta, '/');
        }

        return rtrim(dirname(base_path()).'/'.preg_replace('#^\./#', '', $ruta), '/');
    }

    private function relativa(string $ruta): string
    {
        $raiz = dirname(base_path()).'/';

        return str_starts_with($ruta, $raiz) ? substr($ruta, strlen($raiz)) : $ruta;
    }

    private static function json(mixed $valor): string
    {
        return json_encode($valor, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    }

    /** @param array<string,mixed> $h */
    private static function leemeS2(array $h): string
    {
        $codigo = '';
        foreach ($h['code'] as $nodo => $c) {
            $codigo .= "| {$nodo} | `{$c['file']}` | {$c['bytes']} | `{$c['sha256']}` |\n";
        }
        $nuevos = implode(' · ', $h['nodes']['newNames']);
        $copiaS1 = UltronRegenN8nFlow::S1_COPY_ID;
        $plan = 'docs/ultron-studio/plans/informes-motores/plan-arquitecto-a4-regenerar.md';

        return <<<MD
# ULTRON STUDIO · flujo «S2» de n8n: regenerar antes de sustituir (GENERADO)

> Generado por `php artisan ultron:prompts-n8n --rev=S2` desde S1 (que sale del respaldo `{$h['from']['workflowId']}` versión `{$h['from']['sourceVersionId']}` y de la base del repositorio). **No se edita a mano**: si hay que cambiar algo, se cambia el generador (`UltronRegenN8nFlow`) y se vuelve a generar. Plan: `{$plan}` §5. Contrato `{$h['contract']}` (`POST /api/internal/marketing/ai/check`, de solo lectura).

## Qué es

S2 = los {$h['nodes']['s1']} nodos de S1 + {$h['nodes']['new']} nuevos, **desenrollado (sin bucles)**. Entre el redactor y el revisor, n8n le pregunta al servidor si aceptaría el borrador; si pide reescribir, el redactor de la reescritura recibe la nota del servidor donde hoy recibe la del revisor (`critic_issues`, `critic_notes`: su `values[1]` no cambia). Como mucho hay **{$h['maxDrafts']} borradores**: el tercero no se comprueba, pero lo juzga un revisor. El tope está en la forma del grafo, no en un contador.

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

**Sin la oferta del servidor** (`decide` no devuelve `regeneration` con `{$h['contract']}`: un turno Legacy o un servidor anterior) el recorrido es el de S1: las mismas llamadas al modelo con los mismos datos, ninguna a `ai/check`, y Preparar Commit con la misma salida, sin el bloque. Lo único que cambia es la revisión que sella el selector (`flowRev {$h['flowRev']}`).

**Con la oferta:** como mucho 3 redactores y 3 revisores en cualquier camino; la comprobación se llama como mucho {$h['maxChecks']} veces, con {$h['checkTimeoutMs']} ms de tiempo máximo y **sin reintentos**; cualquier respuesta que no sea un 200 bien formado del contrato es «no reescribir» y se sigue el camino de hoy; Preparar Commit añade el bloque `regeneration` (borradores, revisores, comprobaciones y sus errores, quién pidió cada reescritura y con qué códigos, y los tokens si OpenAI los entrega) dentro de un `try`: si falla, sale sin bloque, nunca sin respuesta.

## Qué contiene

| Fichero | Qué es |
|---|---|
| `flujo-s2.json` | El flujo completo: {$h['nodes']['total']} nodos ({$h['nodes']['withCredentials']} con credencial, todas por referencia y sin secretos), {$h['connections']['sources']} orígenes y {$h['connections']['links']} enlaces. Sin `id` ni `versionId` y con `active: false`: importarlo tal cual no pisa ningún flujo. Para el comprobador y, con id y `versionId` nuevos, para importarlo como copia INACTIVA. **Nunca** encima de un flujo publicado ni como vuelta atrás. |
| `preparar-commit.js` | El `jsCode` de «Preparar Commit» en S2: el de S1 + el tercer borrador + el bloque `regeneration`. |
| `leer-comprobacion-1.js`, `leer-comprobacion-2.js` | «Leer Comprobación 1» y «2» (difieren en el borrador que leen y en el intento que esperan). |
| `nota-del-servidor.js` | «Nota del Servidor 1» y «2» (el mismo código). |
| `decidir-intento-3.js` | «Decidir Intento 3». |
| `huellas.json` | Las huellas esperadas (nodos, conexiones, selector, Preparar Commit, Code nuevos, cuerpo de la comprobación, contenidos de sistema, `values[1]`, vocabulario). |
| `fixtures/decide-*.json` | Respuestas sintéticas de `decide` (sin datos de nadie): `studio` (con `regeneration`) y `legacy` (sin él). |
| `fixtures/check-*.json` | Salidas sintéticas del nodo HTTP de la comprobación (`fullResponse`): `pasa`, `reescribe` (con las notas del servidor), `legacy`, `404`, `422`, `error-de-red` y `mal-formada`. |

Los nodos nuevos: {$nuevos}.

## Huellas

- Selector («Elegir Prompts», `FLOW_REV = "{$h['selector']['flowRev']}"`): {$h['selector']['jsCodeBytes']} bytes, sha256 `{$h['selector']['jsCodeSha256']}`
- Preparar Commit: sha256 `{$h['preparaCommitJsCodeSha256']}`
- Comprobación: `{$h['check']['url']}`, {$h['checkTimeoutMs']} ms, {$h['checkRetries']} reintentos, la credencial de «Pedir Contexto al CRM»

| Nodo | Fichero | Bytes | sha256 |
|---|---|---|---|
{$codigo}
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

`unpublish(S2) → publish({$copiaS1})`: la copia S1 queda intacta como reserva. Verificación: el siguiente turno canario sale con `flowRev S1` en `php artisan ultron:prompt-trace`. **Nunca reimportando un JSON** (no lleva los secretos de las credenciales).

MD;
    }

    /** @param array<string,mixed> $h */
    private static function leeme(array $h): string
    {
        $base = '';
        foreach ($h['baseSha256'] as $rol => $sha) {
            $base .= "| `{$rol}` | {$h['nodes'][$rol]} | {$h['baseBytes'][$rol]} | `{$sha}` |\n";
        }
        $compilador = UltronPromptCompiler::VERSION;

        return <<<MD
# ULTRON STUDIO · cambio «S1» del flujo de n8n (GENERADO)

> Generado por `php artisan ultron:prompts-n8n` desde la base `{$h['base']}` del repositorio y el respaldo del flujo `{$h['workflowId']}` versión `{$h['sourceVersionId']}`. **No se edita a mano**: si hay que cambiar algo, se cambia el generador o la base y se vuelve a generar. Contrato: Enmienda M-1 del H5 y `docs/ultron-studio/plans/STUDIO_MOTORES_PLAN.md` §B.7–B.8.

## Qué contiene

| Fichero | Qué es |
|---|---|
| `elegir-prompts.js` | El código del nodo nuevo «Elegir Prompts» (Code v2, JavaScript, «Run Once for All Items»), con la base embebida como constantes Legacy. |
| `preparar-commit.js` | El `jsCode` completo de «Preparar Commit» en S1 (el de 29de5119 + el bloque `prompts`). |
| `operaciones-mcp.json` | Las operaciones para `update_workflow` del MCP, en orden: 1 `addNode`, 1 `removeConnection`, 2 `addConnection`, 5 `setNodeParameter` de `/responses` (se reescribe el array entero: el MCP no indexa arrays; `values[1]` va intacto), 1 de `/jsonBody` y 1 de `/jsCode`. **Los nombres de campo de cada operación hay que contrastarlos con el esquema del MCP antes de usarlos**; el contenido (nodos, rutas, valores) es el verificado. |
| `flujo-s1.json` | El flujo completo esperado tras aplicar las operaciones. **Solo para el comprobador. NUNCA se importa en n8n.** |
| `huellas.json` | Huellas esperadas (base, Legacy, selector, Preparar Commit, cuerpo de decide, contenidos de sistema, `values[1]`). |
| `fixtures/decide-studio.json`, `fixtures/decide-studio-frases.json`, `fixtures/decide-legacy.json` | Respuestas sintéticas de `decide` (sin datos de nadie) para ejecutar el selector a mano en la copia. La `studio` es `persona.tone = profesional` + `persona.maxChars = 200`, compilada en PHP; la `studio-frases`, lo mismo más seis frases prohibidas de reglas S-NNN sintéticas (compilador 2: el selector la acepta igual, sin cambiar el flujo). |

## Huellas

- Huella del paquete Legacy (`LEGACY_BUNDLE_HASH`): `{$h['legacyBundleHash']}`
- Selector: {$h['selector']['jsCodeBytes']} bytes, sha256 `{$h['selector']['jsCodeSha256']}`
- Preparar Commit: sha256 `{$h['preparaCommitJsCodeSha256']}`
- Contrato `{$h['contract']}`, base `{$h['base']}`, compilador {$compilador}, revisión del flujo `{$h['flowRev']}`

| Rol | Nodo | Bytes | sha256 |
|---|---|---|---|
{$base}
## Cómo se aplica (lo hace el lead; con orden del propietario, etapa E1)

1. **Copia primero.** Duplicar el flujo en n8n (inactivo, otro path de webhook, nombre «… (copia Studio S1)»). Aplicar las operaciones de `operaciones-mcp.json` a la copia. Si el MCP no admite un `jsCode` de este tamaño o confirma sin escribir, aplicarlas con el editor de n8n o su API pública.
2. Exportar la copia y pasar el comprobador (desde la raíz del repositorio):
   `node backend/docs/ultron-studio/ops/motores/verificar-flujo.cjs <export.json>` → tiene que terminar con `OK` y código 0.
3. Ensayo manual en la copia con `Ejecutar en el CRM` **desactivado**, fijando datos en `Entrada ULTRON` y `Pedir Contexto al CRM` con los fixtures de `fixtures/`.
4. Producción: las mismas operaciones en un borrador del flujo real → `versionId` nuevo → exportar → comprobador → publicar (`publish_workflow` con el UUID completo o sin `versionId`) → comprobar `versionId == activeVersionId` → primer turno canario con las llaves en `off`: `php artisan ultron:prompt-trace` tiene que dar `legacy`, `flowRev S1` y la huella Legacy de arriba.

## Vuelta atrás

Restaurar y publicar la versión `{$h['sourceVersionId']}` **desde el historial de versiones de n8n**. **Nunca reimportando un JSON** (ni el respaldo ni `flujo-s1.json`): no llevan los secretos de las credenciales y todos los turnos morirían. Verificación: `versionId` activo = `{$h['sourceVersionId']}` y el siguiente turno canario sale `not_reported` (el flujo viejo no negocia) con respuesta normal.

MD;
    }
}

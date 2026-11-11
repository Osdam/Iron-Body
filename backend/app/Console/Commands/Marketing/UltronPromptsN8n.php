<?php

namespace App\Console\Commands\Marketing;

use App\Services\Marketing\Ultron\Prompts\UltronPromptBase;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler;
use App\Services\Marketing\Ultron\Prompts\UltronPromptsN8nFlow;
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
 * Determinista: dos ejecuciones dan los mismos bytes. No toca n8n, ni la red,
 * ni la base de datos.
 */
class UltronPromptsN8n extends Command
{
    protected $signature = 'ultron:prompts-n8n
        {--out=n8n/flujos/ultron-studio-s1 : directorio de salida (relativo a la raíz del repositorio)}
        {--from=n8n/respaldos/20261111-ultron-29de5119/ultron-commercial-advisor.json : respaldo del flujo 29de5119 (relativo a la raíz del repositorio)}';

    protected $description = 'Genera el cambio S1 del flujo de n8n de ULTRON (selector «Elegir Prompts», operaciones del MCP, huellas y LEEME) desde la base del repositorio.';

    public function handle(): int
    {
        $desde = $this->ruta((string) $this->option('from'));
        $salida = $this->ruta((string) $this->option('out'));

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

        foreach ($ficheros as $nombre => $contenido) {
            $ruta = $salida.'/'.$nombre;
            if (! is_dir(dirname($ruta)) && ! mkdir(dirname($ruta), 0775, true) && ! is_dir(dirname($ruta))) {
                $this->error('No se puede crear '.dirname($ruta));

                return self::FAILURE;
            }
            if (file_put_contents($ruta, $contenido) === false) {
                $this->error("No se puede escribir {$ruta}");

                return self::FAILURE;
            }
        }

        $this->info('S1 generado en '.$salida);
        $this->line('  huella Legacy:    '.UltronPromptBase::LEGACY_BUNDLE_HASH);
        $this->line('  selector:         '.$s1['hashes']['selector']['jsCodeBytes'].' bytes, sha256 '.$s1['hashes']['selector']['jsCodeSha256']);
        $this->line('  Preparar Commit:  sha256 '.$s1['hashes']['preparaCommitJsCodeSha256']);
        $this->line('  operaciones:      '.count($s1['operations']));
        $this->line('Verificar: node backend/docs/ultron-studio/ops/motores/verificar-flujo.cjs '.$this->relativa($salida.'/flujo-s1.json'));

        return self::SUCCESS;
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

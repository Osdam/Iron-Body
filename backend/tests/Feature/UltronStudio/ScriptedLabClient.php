<?php

namespace Tests\Feature\UltronStudio;

use App\Services\UltronStudio\StudioLabModelClient;
use App\Services\UltronStudio\StudioLabPrompts;
use Closure;
use RuntimeException;

/**
 * Un proveedor GUIONIZADO para las pruebas del laboratorio (contrato H4 §A3,
 * «modo fake»): se registra SOLO en el contenedor de la prueba
 * (`$this->app->instance(StudioLabModelClient::class, …)`) y el runner lo usa
 * en las ejecuciones `openai` en lugar del cliente real. Nunca sale a la red.
 *
 * Cada llamada consume el siguiente paso del guion. Un paso es:
 *  - `['reply' => '…', 'usage' => [in, out]]` para el actor;
 *  - `['judge' => 'pass'|'fail'|'unclear'|[...], 'usage' => [in, out]]` para el
 *    juez: con una cadena, ese veredicto a todos los criterios y `pass` (o
 *    `not_applicable`) a todas las reglas; con un array, esa salida tal cual;
 *  - `['class' => 'timeout'|'auth'|…, 'usage' => [in, out]|null, 'http' => 401]`
 *    para un fallo clasificado.
 * Sin guion para una llamada, falla la prueba: ninguna llamada pasa sin verse.
 */
final class ScriptedLabClient implements StudioLabModelClient
{
    /** @var list<array{role:string,input:string,maxOutputTokens:int}> */
    public array $calls = [];

    /**
     * Se ejecuta EN MITAD de cada llamada, antes de contestar: lo que haría
     * otro proceso mientras este espera al proveedor (otro paso de la misma
     * ejecución, una cancelación…). Recibe el número de la llamada (desde 1).
     *
     * @var (Closure(int): void)|null
     */
    public ?Closure $whileWaiting = null;

    /** @param  list<array<string, mixed>>  $script */
    public function __construct(private array $script) {}

    public function complete(string $role, string $instructions, string $inputJson, array $schema, int $maxOutputTokens): array
    {
        StudioLabPrompts::schemaName($role);
        $this->calls[] = ['role' => $role, 'input' => $inputJson, 'maxOutputTokens' => $maxOutputTokens];
        if ($this->whileWaiting !== null) {
            ($this->whileWaiting)(count($this->calls));
        }
        $paso = array_shift($this->script);
        if ($paso === null) {
            throw new RuntimeException("El guion no tiene paso para la llamada {$role} número ".count($this->calls).'.');
        }

        $uso = isset($paso['usage']) && is_array($paso['usage'])
            ? ['inputTokens' => (int) $paso['usage'][0], 'outputTokens' => (int) $paso['usage'][1], 'reasoningTokens' => 0]
            : null;

        if (isset($paso['class'])) {
            return ['status' => self::STATUS_ERROR, 'output' => null, 'usage' => $uso, 'class' => $paso['class'], 'http' => $paso['http'] ?? null, 'latencyMs' => 1, 'exception' => $paso['exception'] ?? null];
        }

        if ($role === self::ROLE_ACTOR) {
            $salida = ['reply' => (string) ($paso['reply'] ?? '')];
        } else {
            $salida = is_array($paso['judge'] ?? null) ? $paso['judge'] : self::judge((string) ($paso['judge'] ?? 'pass'), $inputJson);
        }

        return ['status' => self::STATUS_OK, 'output' => $salida, 'usage' => $uso ?? ['inputTokens' => 0, 'outputTokens' => 0, 'reasoningTokens' => 0], 'class' => null, 'http' => 200, 'latencyMs' => 1, 'exception' => null];
    }

    /** Lo que queda del guion sin consumir. */
    public function remaining(): int
    {
        return count($this->script);
    }

    /** @return array<string, mixed> */
    private static function judge(string $veredicto, string $inputJson): array
    {
        $objetivos = StudioLabPrompts::judgeTargets($inputJson) ?? ['criteria' => [], 'rules' => []];

        return [
            'criteria' => array_map(static fn (string $id): array => ['id' => $id, 'verdict' => $veredicto, 'evidence' => '', 'reason' => 'Guion de la prueba.'], $objetivos['criteria']),
            'rules' => array_map(static fn (string $code): array => ['code' => $code, 'verdict' => $veredicto === 'fail' ? 'not_applicable' : ($veredicto === 'unclear' ? 'unclear' : 'pass'), 'reason' => ''], $objetivos['rules']),
            'risks' => [],
            'summary' => 'Guion de la prueba.',
        ];
    }
}

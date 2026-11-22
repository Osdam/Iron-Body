<?php

namespace Tests\Feature\Outreach\AI9\Support;

use App\Services\Outreach\Run\Classifier\ReplyClassifierTransport;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * El DOBLE de la frontera externa de AI9 (el transporte hacia el modelo): lo
 * único que se finge. Apunta cada llamada tal como le llega (para comprobar
 * qué saldría hacia el proveedor y desde qué nivel de transacción) y contesta
 * lo que la prueba le dice: un resultado fijo, una función del texto (un
 * modelo «comprometido» que obedece al texto) o una excepción.
 */
final class FakeReplyTransport implements ReplyClassifierTransport
{
    /** @var list<array{model: string, instructions: string, text: string, schema: array<string, mixed>, maxOutputTokens: int, timeoutSeconds: int, transactionLevel: int}> */
    public array $calls = [];

    /** @param  array<string, mixed>|Closure(string): array<string, mixed>|null  $reply */
    public function __construct(private array|Closure|null $reply = null, public bool $isConfigured = true, private ?string $recordTo = null) {}

    /** Lo que diría el proveedor con una etiqueta y una confianza (y un `usage` corriente). @return array<string, mixed> */
    public static function says(string $label, int $confidence, int $inputTokens = 300, int $outputTokens = 12): array
    {
        return ['error' => null, 'output' => ['label' => $label, 'confidence' => $confidence], 'usage' => ['inputTokens' => $inputTokens, 'outputTokens' => $outputTokens], 'latencyMs' => 7];
    }

    /** Un fallo del proveedor. @return array<string, mixed> */
    public static function fails(string $error): array
    {
        return ['error' => $error, 'output' => null, 'usage' => null, 'latencyMs' => 7];
    }

    public function configured(): bool
    {
        return $this->isConfigured;
    }

    public function classify(string $model, string $instructions, string $text, array $schema, int $maxOutputTokens, int $timeoutSeconds): array
    {
        $this->calls[] = compact('model', 'instructions', 'text', 'schema', 'maxOutputTokens', 'timeoutSeconds') + ['transactionLevel' => DB::transactionLevel()];
        if ($this->recordTo !== null) {
            // La prueba de los dos procesos: cada llamada, una línea (con bloqueo, los dos escriben en el mismo fichero).
            file_put_contents($this->recordTo, getmypid()."\n", FILE_APPEND | LOCK_EX);
            usleep(300_000);
        }
        $r = $this->reply instanceof Closure ? ($this->reply)($text) : ($this->reply ?? self::says('none', 99));

        return $r;
    }
}

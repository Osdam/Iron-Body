<?php

namespace App\Services\Nutrition\Ai;

use App\Models\Member;
use App\Models\NutritionAiRun;
use Illuminate\Support\Facades\Log;

/**
 * Motor central de la IA de Nutrición: orquesta interruptor → cost guard →
 * caché por hash → llamada al proveedor → auditoría (`nutrition_ai_runs`).
 * Las reglas anti-corrupción (validación de schema) las aplica cada flujo con
 * NutritionAiResponseValidator antes de `record()`.
 */
class NutritionAIEnrichmentService
{
    public function __construct(
        private NutritionAiClient $client,
        private NutritionAiCostGuard $guard,
        private NutritionAiHashCache $cache,
    ) {
    }

    public function isEnabled(): bool
    {
        return (bool) config('nutrition.ai.enabled');
    }

    /**
     * Ejecuta guard+cache+proveedor. Persiste SOLO los fallos del proveedor y
     * los bloqueos de guard; el éxito lo persiste el flujo con `record()` tras
     * validar. Devuelve el "outcome" para que el flujo decida.
     *
     * @return array{outcome:string,error_code:?string,raw:?array,hash:?string,model:?string}
     */
    public function prepare(string $mode, ?Member $member, string $model, array $messages, string $inputForHash, array $meta = []): array
    {
        if (! $this->isEnabled()) {
            return $this->out('disabled', 'ai_disabled');
        }

        $check = $this->guard->check($member);
        if (! $check['allowed']) {
            $status = $check['reason'] === 'rate_limit_per_user'
                ? NutritionAiRun::STATUS_RATE_LIMITED
                : NutritionAiRun::STATUS_REJECTED;
            $this->record($mode, $member, $meta, null, null, $status, null, null, [], $check['reason']);
            return $this->out('guard', $check['reason']);
        }

        $hash = $this->cache->hash($mode, NutritionAiPrompts::version(), $inputForHash);
        if ($cached = $this->cache->get($hash)) {
            Log::info('nutrition:ai:cache_hit', ['mode' => $mode]);
            return ['outcome' => 'cache', 'error_code' => null, 'raw' => $cached, 'hash' => $hash, 'model' => null];
        }

        $res = $this->client->complete($model, $messages, true);
        if ($res['status'] !== 'success') {
            $status = match ($res['status']) {
                'timeout'      => NutritionAiRun::STATUS_TIMEOUT,
                'rate_limited' => NutritionAiRun::STATUS_RATE_LIMITED,
                default        => NutritionAiRun::STATUS_FAILED,
            };
            $this->record($mode, $member, $meta, $hash, $model, $status, null, null, [], $res['error_code']);
            return $this->out('provider', $res['error_code'], $hash, $model);
        }

        return ['outcome' => 'provider', 'error_code' => null, 'raw' => $res['json'], 'hash' => $hash, 'model' => $res['model']];
    }

    /** Persiste una ejecución de IA (auditoría + base de caché por hash). */
    public function record(string $mode, ?Member $member, array $meta, ?string $hash, ?string $model, string $status, ?float $confidence, ?array $responseJson, array $warnings = [], ?string $errorCode = null): NutritionAiRun
    {
        return NutritionAiRun::create([
            'member_id'        => $member?->id,
            'food_id'          => $meta['food_id'] ?? null,
            'barcode'          => $meta['barcode'] ?? null,
            'mode'             => $mode,
            'provider'         => (string) config('nutrition.ai.provider', 'openai'),
            'model'            => $model,
            'input_hash'       => $hash,
            'confidence_score' => $confidence,
            'status'           => $status,
            'error_code'       => $errorCode,
            'prompt_version'   => NutritionAiPrompts::version(),
            'response_json'    => $responseJson,
            'warnings'         => $warnings ?: null,
        ]);
    }

    private function out(string $outcome, ?string $code = null, ?string $hash = null, ?string $model = null): array
    {
        return ['outcome' => $outcome, 'error_code' => $code, 'raw' => null, 'hash' => $hash, 'model' => $model];
    }
}

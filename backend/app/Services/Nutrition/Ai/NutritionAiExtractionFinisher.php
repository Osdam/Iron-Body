<?php

namespace App\Services\Nutrition\Ai;

use App\Models\Member;
use App\Models\NutritionAiRun;

/**
 * Lógica común a los flujos de extracción/parser/estimación: ejecuta el motor,
 * valida el JSON contra el schema (anti-corrupción) y arma la respuesta
 * controlada con badge y nivel de confianza. NUNCA marca verified.
 */
class NutritionAiExtractionFinisher
{
    public function __construct(
        private NutritionAIEnrichmentService $engine,
        private NutritionAiResponseValidator $validator,
        private NutritionDataConfidenceService $confidence,
    ) {
    }

    public function run(string $mode, ?Member $member, string $model, array $messages, string $inputHash, string $source, array $meta = []): array
    {
        $prep = $this->engine->prepare($mode, $member, $model, $messages, $inputHash, $meta);

        switch ($prep['outcome']) {
            case 'disabled':
                return ['ok' => false, 'status' => 'ai_unavailable', 'error_code' => 'ai_disabled',
                    'message' => 'La asistencia con IA no está disponible. Completa los datos manualmente.'];
            case 'guard':
                return ['ok' => false, 'status' => 'rate_limited', 'error_code' => $prep['error_code'],
                    'message' => 'Alcanzaste el límite de análisis con IA por hoy. Intenta más tarde o completa manualmente.'];
            case 'provider':
                if ($prep['raw'] === null) {
                    return ['ok' => false, 'status' => $this->statusFor($prep['error_code']), 'error_code' => $prep['error_code'],
                        'message' => 'No pudimos analizar con IA. Intenta otra foto o completa manualmente.'];
                }
                break;
        }

        $valid = $this->validator->validateExtraction($prep['raw'], $source);
        if (! $valid['ok']) {
            if ($prep['outcome'] === 'provider') {
                $this->engine->record($mode, $member, $meta, $prep['hash'], $prep['model'],
                    NutritionAiRun::STATUS_VALIDATION_FAILED, null, null, $valid['errors'], 'validation_failed');
            }
            return ['ok' => false, 'status' => 'validation_failed', 'error_code' => 'validation_failed',
                'errors' => $valid['errors'],
                'message' => 'La IA no devolvió datos válidos. Revisa o completa manualmente.'];
        }

        $data = $valid['data'];
        $conf = (float) ($data['confidence_score'] ?? 0);
        $data['confidence_label'] = $this->confidence->label($conf);
        $data['badge'] = $this->badgeFor($source);
        $data['verification_status'] = 'unverified'; // la IA NUNCA verifica

        if ($prep['outcome'] === 'provider') {
            $this->engine->record($mode, $member, $meta, $prep['hash'], $prep['model'],
                NutritionAiRun::STATUS_SUCCESS, $conf, $data, $valid['warnings']);
        }

        return [
            'ok'      => true,
            'status'  => $data['is_complete'] ? 'success' : 'partial',
            'cached'  => $prep['outcome'] === 'cache',
            'data'    => $data,
        ];
    }

    private function statusFor(?string $errorCode): string
    {
        return match ($errorCode) {
            'timeout'      => 'timeout',
            'rate_limited' => 'rate_limited',
            default        => 'ai_unavailable',
        };
    }

    private function badgeFor(string $source): string
    {
        return match ($source) {
            'ai_estimated' => 'Estimado por IA · No verificado',
            default        => 'Extraído por IA',
        };
    }
}

<?php

namespace App\Services\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Services\Marketing\Contracts\AiSalesResponderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Responder OpenAI: clasifica el mensaje y propone una respuesta humana. SOLO
 * RECOMIENDA; Laravel valida (SalesAgentDecisionValidator) y ejecuta. Ante
 * cualquier error (red, timeout, JSON inválido, schema inválido) NO lanza 500:
 *
 *   - fail_closed=true  → devuelve una decisión SEGURA (unknown), responder='fallback'.
 *   - fail_closed=false → delega en FakeAiSalesResponder (reglas), responder='fallback'.
 *
 * NUNCA imprime la OPENAI_API_KEY ni loguea prompts por defecto.
 */
class OpenAiSalesResponder implements AiSalesResponderInterface
{
    public function __construct(
        private readonly FakeAiSalesResponder $fake,
        private readonly SalesAgentPromptBuilder $prompt,
        private readonly SalesAgentDecisionValidator $validator,
    ) {
    }

    public function classify(string $body, array $context = []): array
    {
        if (! SalesAiConfig::openAiReady()) {
            return $this->fallback($body, $context, 'not_ready');
        }

        try {
            $lead         = $context['lead'] ?? null;
            $conversation = $context['conversation'] ?? null;

            $raw = $this->callOpenAi(
                $this->prompt->systemPrompt(),
                $this->prompt->userPrompt($lead instanceof MarketingLead ? $lead : new MarketingLead(), $body,
                    $conversation instanceof MarketingConversation ? $conversation : null),
            );

            $decision = $this->validator->sanitize($raw);
            $decision['responder'] = 'openai';

            return $decision;
        } catch (Throwable $e) {
            // Sin secretos ni payloads sensibles.
            Log::warning('marketing.openai.error', ['error' => class_basename($e)]);
            return $this->fallback($body, $context, 'error');
        }
    }

    public function name(): string
    {
        return 'openai';
    }

    /**
     * Llama a Chat Completions y devuelve el objeto JSON decodificado del modelo.
     * @throws \RuntimeException si la respuesta no es JSON válido.
     */
    private function callOpenAi(string $system, string $user): array
    {
        $cfg     = (array) config('marketing.ai.openai');
        $apiKey  = (string) config('services.openai.api_key');
        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com'), '/');
        $retries = max(1, (int) ($cfg['max_retries'] ?? 1) + 1);

        if ((bool) ($cfg['log_prompts'] ?? false)) {
            Log::debug('marketing.openai.prompt', ['system_len' => strlen($system), 'user_len' => strlen($user)]);
        }

        $resp = Http::withToken($apiKey)
            ->timeout((int) ($cfg['timeout'] ?? 20))
            ->retry($retries, 200, throw: false)
            ->post($baseUrl.'/v1/chat/completions', [
                'model'           => SalesAiConfig::model(),
                'temperature'     => (float) ($cfg['temperature'] ?? 0.2),
                'max_tokens'      => (int) ($cfg['max_output_tokens'] ?? 1200),
                'response_format' => ['type' => 'json_object'],
                'messages'        => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user',   'content' => $user],
                ],
            ]);

        if ($resp->failed()) {
            throw new \RuntimeException('openai_http_'.$resp->status());
        }

        $content = (string) $resp->json('choices.0.message.content', '');
        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('openai_invalid_json');
        }

        return $decoded;
    }

    /** Decisión de respaldo segura (sin 500). */
    private function fallback(string $body, array $context, string $why): array
    {
        if (SalesAiConfig::failClosed()) {
            return [
                'intent'            => SalesIntents::UNKNOWN,
                'confidence'        => 0.0,
                'extracted_fields'  => [],
                'missing_fields'    => [],
                'reply'             => null,
                'force_escalate'    => false,
                'escalation_reason' => null,
                'risk_flags'        => ['openai_fallback_'.$why],
                'responder'         => 'fallback',
            ];
        }

        // fail_closed=false → reglas deterministas (fake).
        $f = $this->fake->classify($body, $context);
        $f['responder']  = 'fallback';
        $f['risk_flags'] = array_values(array_unique(array_merge($f['risk_flags'] ?? [], ['openai_fallback_'.$why])));

        return $f;
    }
}

<?php

namespace App\Services\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Services\Marketing\Contracts\AiSalesResponderInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tercera implementación del cerebro comercial, junto a Fake y OpenAI.
 *
 * Hermes SOLO razona: recibe el contexto ya saneado por SalesAgentPromptBuilder
 * y devuelve la misma decisión estructurada. Lo que NO puede hacer, por diseño
 * de esta clase y no por confianza en el modelo:
 *
 *   - no habla con PostgreSQL: solo recibe el contexto que Laravel le arma;
 *   - no controla WhatsApp: el envío es de MarketingMessageDispatcher;
 *   - no se salta Laravel: su salida pasa por SalesAgentDecisionValidator y por
 *     los guardrails, exactamente igual que la de OpenAI;
 *   - no ejecuta herramientas: solo las solicita, y la lista blanca vive en
 *     SalesAgentDecisionSchema::ALLOWED_TOOLS.
 *
 * Ante CUALQUIER problema (flag apagado, timeout, red, JSON inválido, schema
 * inválido) delega en el responder OpenAI inyectado, que a su vez cae a reglas
 * deterministas. Nunca lanza y nunca deja al prospecto sin respuesta.
 *
 * Kill switch: MARKETING_HERMES_ENABLED=false + config:cache devuelve el
 * comportamiento exacto anterior sin desplegar código.
 */
class HermesSalesResponder implements AiSalesResponderInterface
{
    public function __construct(
        private readonly OpenAiSalesResponder $fallback,
        private readonly SalesAgentPromptBuilder $prompt,
        private readonly SalesAgentDecisionValidator $validator,
    ) {}

    public function classify(string $body, array $context = []): array
    {
        if (! SalesAiConfig::hermesReady()) {
            return $this->degrade($body, $context, 'not_ready');
        }

        $startedAt = microtime(true);

        try {
            $lead = $context['lead'] ?? null;
            $conversation = $context['conversation'] ?? null;

            $raw = $this->callHermes(
                $this->prompt->systemPrompt(),
                $this->prompt->userPrompt(
                    $lead instanceof MarketingLead ? $lead : new MarketingLead,
                    $body,
                    $conversation instanceof MarketingConversation ? $conversation : null,
                ),
            );

            $decision = $this->validator->sanitize($raw);
            $decision['responder'] = 'hermes';

            Log::info('marketing.hermes.ok', [
                'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
                'intent' => $decision['intent'] ?? null,
            ]);

            return $decision;
        } catch (Throwable $e) {
            // Sin secretos, sin prompts, sin datos del prospecto.
            Log::warning('marketing.hermes.error', [
                'error' => class_basename($e),
                'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);

            return $this->degrade($body, $context, 'error');
        }
    }

    public function name(): string
    {
        return 'hermes';
    }

    /**
     * Llama a Hermes por su API compatible con OpenAI y devuelve el objeto JSON
     * decodificado. Sin reintentos por defecto: un prospecto en WhatsApp espera
     * segundos, no minutos, así que es mejor degradar a OpenAI que insistir.
     *
     * @throws \RuntimeException si la respuesta no es JSON válido.
     */
    private function callHermes(string $system, string $user): array
    {
        $cfg = (array) config('marketing.ai.hermes');
        $baseUrl = rtrim((string) ($cfg['base_url'] ?? ''), '/');
        $retries = max(1, (int) ($cfg['max_retries'] ?? 0) + 1);

        $request = Http::timeout((int) ($cfg['timeout'] ?? 15))
            ->retry($retries, 200, throw: false);

        if (! empty($cfg['api_key'])) {
            $request = $request->withToken((string) $cfg['api_key']);
        }

        $resp = $request->post($baseUrl.'/v1/chat/completions', [
            'model' => (string) ($cfg['model'] ?? 'gpt-4.1'),
            'temperature' => (float) ($cfg['temperature'] ?? 0.2),
            'max_tokens' => (int) ($cfg['max_output_tokens'] ?? 1200),
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ]);

        if ($resp->failed()) {
            throw new \RuntimeException('hermes_http_'.$resp->status());
        }

        $decoded = json_decode((string) $resp->json('choices.0.message.content', ''), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('hermes_invalid_json');
        }

        return $decoded;
    }

    /**
     * Degrada a OpenAI (que internamente cae a reglas si tampoco está listo) y
     * deja la traza de por qué, para poder medir la tasa de degradación.
     */
    private function degrade(string $body, array $context, string $why): array
    {
        $decision = $this->fallback->classify($body, $context);
        $decision['risk_flags'] = array_values(array_unique(array_merge(
            $decision['risk_flags'] ?? [],
            ['hermes_fallback_'.$why],
        )));

        return $decision;
    }
}

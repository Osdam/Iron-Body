<?php

namespace App\Services\Subscriptions;

use App\Exceptions\SubscriptionException;
use App\Models\WompiPaymentSource;
use App\Services\Wompi\WompiAcceptanceService;
use App\Services\Wompi\WompiClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Fuentes de pago de Wompi para COBRO AUTOMÁTICO. Tokeniza (en Flutter) → crea la
 * fuente en Wompi (POST /payment_sources) y persiste SOLO referencias seguras:
 * `wompi_payment_source_id`, marca, últimos 4 y expiración. NUNCA PAN/CVC/token
 * completo ni payloads sensibles.
 *
 * Reglas de método (decisión de negocio):
 *   - Solo TARJETA está habilitada para pago automático.
 *   - NEQUI queda modelado pero APAGADO por flag (`wompi.recurring.methods.nequi`).
 *   - PSE / DaviPlata / Bancolombia NUNCA se aceptan (no soportan cobro desatendido).
 *
 * INERTE si `wompi.recurring.enabled=false`: no llega a tocar Wompi (el guard
 * lanza SubscriptionException; además WompiClient es inerte como defensa extra).
 */
class WompiPaymentSourceService
{
    public function __construct(
        private WompiClient $client,
        private WompiAcceptanceService $acceptance,
        private array $cfg,
    ) {
    }

    public static function make(): self
    {
        return new self(
            WompiClient::fromConfig(),
            WompiAcceptanceService::make(),
            (array) config('wompi'),
        );
    }

    /** ¿El método (interno) puede usarse para pago automático? */
    public function isMethodAllowed(string $method): bool
    {
        $method = strtolower(trim($method));
        // PSE/DaviPlata/Bancolombia jamás; solo card|nequi según flags.
        if (! in_array($method, ['card', 'nequi'], true)) {
            return false;
        }
        return (bool) ($this->cfg['recurring']['methods'][$method] ?? false);
    }

    /**
     * Crea una fuente de pago para el miembro a partir de un token ya generado en
     * el cliente (tok_... para tarjeta). Devuelve el modelo persistido con su
     * estado local mapeado. Idempotente por token de aceptación + creación única.
     *
     * @param  array  $data  {
     *     member_id, user_id, type ('CARD'|'NEQUI'), token, customer_email,
     *     card_brand?, card_last_four?, exp_month?, exp_year?
     *   }
     */
    public function createForMember(array $data): WompiPaymentSource
    {
        $this->assertRecurringEnabled();

        $type   = strtoupper((string) ($data['type'] ?? 'CARD'));
        // Método interno REAL a partir del tipo Wompi (no forzar a 'card': PSE/
        // DaviPlata/Bancolombia deben caer y ser rechazados, no colarse como tarjeta).
        $method = match ($type) {
            'CARD'  => 'card',
            'NEQUI' => 'nequi',
            default => strtolower($type), // pse | daviplata | bancolombia_transfer | …
        };

        if (! $this->isMethodAllowed($method)) {
            throw SubscriptionException::unsupportedMethod($method);
        }

        $token = trim((string) ($data['token'] ?? ''));
        $email = trim((string) ($data['customer_email'] ?? ''));
        if ($token === '' || $email === '') {
            throw SubscriptionException::paymentSourceUnavailable('Faltan datos del método de pago.');
        }

        // Tokens de aceptación VIGENTES (los DOS que exige Wompi). Sin ellos no se
        // crea la fuente. Se registran de forma segura (permalinks públicos).
        $tokens = $this->acceptance->freshTokensForTransaction();
        if (empty($tokens['acceptance_token']) || empty($tokens['accept_personal_auth_token'])) {
            throw SubscriptionException::paymentSourceUnavailable('No pudimos validar los términos de pago.');
        }

        // Registro local PRIMERO (pending), con solo datos NO sensibles.
        $source = WompiPaymentSource::create([
            'uuid'           => (string) Str::uuid(),
            'member_id'      => $data['member_id'] ?? null,
            'user_id'        => $data['user_id'] ?? null,
            'provider'       => 'wompi',
            'type'           => in_array($type, [WompiPaymentSource::TYPE_CARD, WompiPaymentSource::TYPE_NEQUI], true) ? $type : WompiPaymentSource::TYPE_CARD,
            'status'         => WompiPaymentSource::STATUS_PENDING,
            'card_brand'     => $data['card_brand'] ?? null,
            'card_last_four' => $this->safeLast4($data['card_last_four'] ?? null),
            'exp_month'      => $this->safeExp($data['exp_month'] ?? null, 2),
            'exp_year'       => $this->safeExp($data['exp_year'] ?? null, 4),
            'customer_email' => $email,
            'environment'    => $this->cfg['env'] ?? 'sandbox',
        ]);

        // Llamada a Wompi (INERTE si recurring off → error controlado, sin red).
        $res = $this->client->createPaymentSource([
            'type'                 => $type,
            'token'                => $token, // NO se persiste ni se loguea.
            'customer_email'       => $email,
            'acceptance_token'     => $tokens['acceptance_token'],
            'accept_personal_auth' => $tokens['accept_personal_auth_token'],
        ], $source->uuid);

        if (! $res['ok']) {
            $source->forceFill([
                'status'         => WompiPaymentSource::STATUS_FAILED,
                'status_message' => $this->safeMessage($res['error'] ?? null),
            ])->save();
            Log::info('subscriptions.payment_source.create_failed', [
                'source_uuid' => $source->uuid,
                'error_code'  => $res['error_code'] ?? null,
            ]);
            return $source->fresh();
        }

        return $this->applyWompiSource($source, is_array($res['data']) ? $res['data'] : []);
    }

    /** Consulta el estado real de la fuente en Wompi y lo sincroniza localmente. */
    public function refreshStatus(WompiPaymentSource $source): WompiPaymentSource
    {
        $this->assertRecurringEnabled();

        if (! $source->wompi_payment_source_id) {
            return $source;
        }
        $res = $this->client->getPaymentSource($source->wompi_payment_source_id);
        if (! $res['ok']) {
            return $source->fresh();
        }
        return $this->applyWompiSource($source, is_array($res['data']) ? $res['data'] : []);
    }

    /** Mapea el estado de Wompi (payment_source) al estado local. */
    public function mapStatus(?string $wompiStatus): string
    {
        return match (strtoupper((string) $wompiStatus)) {
            'AVAILABLE' => WompiPaymentSource::STATUS_AVAILABLE,
            'PENDING'   => WompiPaymentSource::STATUS_PENDING,
            'DECLINED'  => WompiPaymentSource::STATUS_DECLINED,
            'ERROR'     => WompiPaymentSource::STATUS_FAILED,
            default     => WompiPaymentSource::STATUS_PENDING,
        };
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Persiste la respuesta de Wompi en el modelo, solo campos NO sensibles. */
    private function applyWompiSource(WompiPaymentSource $source, array $ws): WompiPaymentSource
    {
        $status = $this->mapStatus($ws['status'] ?? null);

        $source->forceFill(array_filter([
            'wompi_payment_source_id' => $ws['id'] ?? $source->wompi_payment_source_id,
            'status'                  => $status,
            'three_ds_status'         => isset($ws['status']) ? strtolower((string) $ws['status']) : $source->three_ds_status,
            // Datos NO sensibles si Wompi los devuelve en public_data.
            'card_brand'     => data_get($ws, 'public_data.brand') ?? $source->card_brand,
            'card_last_four' => $this->safeLast4(data_get($ws, 'public_data.last_four')) ?? $source->card_last_four,
            'exp_month'      => $this->safeExp(data_get($ws, 'public_data.exp_month'), 2) ?? $source->exp_month,
            'exp_year'       => $this->safeExp(data_get($ws, 'public_data.exp_year'), 4) ?? $source->exp_year,
        ], fn ($v) => $v !== null))->save();

        return $source->fresh();
    }

    private function assertRecurringEnabled(): void
    {
        if (! (bool) ($this->cfg['recurring']['enabled'] ?? false)) {
            throw SubscriptionException::recurringDisabled();
        }
    }

    private function safeLast4(mixed $v): ?string
    {
        $s = preg_replace('/\D/', '', (string) $v);
        return $s !== '' ? substr($s, -4) : null;
    }

    private function safeExp(mixed $v, int $len): ?string
    {
        $s = preg_replace('/\D/', '', (string) $v);
        return $s !== '' ? substr($s, -$len) : null;
    }

    private function safeMessage(?string $msg): ?string
    {
        return $msg ? mb_substr($msg, 0, 200) : null;
    }
}

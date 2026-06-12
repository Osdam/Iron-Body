<?php

namespace App\Services\Wompi;

use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cobro con PSE vía Wompi. Tras crear la transacción, Wompi devuelve la URL
 * OFICIAL de autenticación del banco (`payment_method.extra.async_payment_url`),
 * que la app abre en WebView interno (no es un "botón Wompi"). La confirmación
 * REAL llega por webhook/reconciliación; jamás se aprueba por la redirección.
 *
 * La lista de bancos se obtiene de GET /pse/financial_institutions (cacheada),
 * nunca hardcodeada.
 */
class WompiPsePaymentService extends AbstractWompiPaymentService
{
    private const INSTITUTIONS_CACHE_KEY = 'wompi:pse:institutions';

    public static function make(): self
    {
        return new self(
            WompiClient::fromConfig(),
            WompiTransactionService::make(),
            WompiSignatureService::fromConfig(),
            WompiAcceptanceService::make(),
            (array) config('wompi'),
        );
    }

    protected function method(): string
    {
        return 'pse';
    }

    protected function buildPaymentMethod(array $data, PaymentTransaction $transaction): ?array
    {
        $bank = trim((string) ($data['financial_institution_code'] ?? $data['bank'] ?? ''));
        if ($bank === '') {
            return null;
        }

        $personType = strtolower((string) ($data['user_type'] ?? $data['person_type'] ?? 'natural'));
        // Wompi: 0 = persona natural, 1 = persona jurídica.
        $userType = in_array($personType, ['1', 'juridica', 'business'], true) ? 1 : 0;

        $legalIdType = strtoupper((string) (
            $data['user_legal_id_type'] ?? $data['doc_type']
            ?? $transaction->customer_legal_id_type ?? 'CC'
        ));
        $legalId = (string) (
            $data['user_legal_id'] ?? $data['doc_number']
            ?? $transaction->customer_legal_id ?? ''
        );
        if ($legalId === '') {
            return null;
        }

        return [
            'type'                      => 'PSE',
            'user_type'                 => $userType,
            'user_legal_id_type'        => $legalId !== '' ? $legalIdType : 'CC',
            'user_legal_id'             => $legalId,
            'financial_institution_code'=> $bank,
            'payment_description'       => mb_substr($this->description($transaction), 0, 60),
        ];
    }

    /**
     * Lista de bancos PSE (cacheada y ordenada). Degradación segura si Wompi
     * está caído: devuelve el cache previo o lista vacía con bandera.
     *
     * @return array{institutions: array, available: bool}
     */
    public function institutions(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::INSTITUTIONS_CACHE_KEY);
        }
        $ttl = (int) ($this->cfg['pse_cache_ttl'] ?? 3600);

        $cached = Cache::get(self::INSTITUTIONS_CACHE_KEY);
        $res = $this->client->getPseInstitutions();

        if (! $res['ok']) {
            Log::warning('wompi.pse.institutions.failed', ['status' => $res['status']]);
            return [
                'institutions' => is_array($cached) ? $cached : [],
                'available'    => is_array($cached),
            ];
        }

        $list = collect(is_array($res['data']) ? $res['data'] : [])
            ->map(fn ($i) => [
                'financial_institution_code' => $i['financial_institution_code'] ?? null,
                'financial_institution_name' => $i['financial_institution_name'] ?? null,
            ])
            ->filter(fn ($i) => $i['financial_institution_code'] && $i['financial_institution_name'])
            ->sortBy('financial_institution_name', SORT_FLAG_CASE | SORT_NATURAL)
            ->values()
            ->all();

        Cache::put(self::INSTITUTIONS_CACHE_KEY, $list, $ttl);

        return ['institutions' => $list, 'available' => true];
    }
}

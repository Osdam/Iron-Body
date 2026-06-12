<?php

namespace App\Services\Wompi;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Tokens de aceptación vigentes de Wompi (los DOS exigidos):
 *   - presigned_acceptance            → términos y condiciones / política de uso.
 *   - presigned_personal_data_auth    → autorización de tratamiento de datos.
 *
 * Se obtienen de GET /merchants/{public_key}. Los `acceptance_token` son
 * efímeros y rotan: se cachean por poco tiempo y se invalidan si cambian las
 * versiones. A Flutter SOLO se le exponen los ENLACES de los documentos y un
 * flag; el `acceptance_token` se resuelve en backend al crear la transacción
 * (así la app nunca tiene que custodiar tokens). NUNCA se hardcodean PDFs.
 */
class WompiAcceptanceService
{
    private const CACHE_KEY = 'wompi:acceptance:tokens';

    public function __construct(private WompiClient $client, private array $cfg)
    {
    }

    public static function make(): self
    {
        return new self(WompiClient::fromConfig(), (array) config('wompi'));
    }

    /**
     * Tokens + enlaces vigentes (cacheados). Estructura:
     *   [
     *     'acceptance_token'             => string|null,
     *     'accept_personal_auth_token'   => string|null,
     *     'terms_link'                   => string|null,
     *     'privacy_link'                 => string|null,
     *     'available'                    => bool,
     *   ]
     */
    public function tokens(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        $ttl = (int) ($this->cfg['acceptance_cache_ttl'] ?? 600);

        return Cache::remember(self::CACHE_KEY, $ttl, function () {
            $res = $this->client->getMerchant();
            if (! $res['ok']) {
                Log::warning('wompi.acceptance.fetch_failed', [
                    'status'     => $res['status'],
                    'error_code' => $res['error_code'],
                ]);
                return [
                    'acceptance_token'           => null,
                    'accept_personal_auth_token' => null,
                    'terms_link'                 => null,
                    'privacy_link'               => null,
                    'available'                  => false,
                ];
            }

            $data = $res['data'];

            return [
                'acceptance_token'           => data_get($data, 'presigned_acceptance.acceptance_token'),
                'accept_personal_auth_token' => data_get($data, 'presigned_personal_data_auth.acceptance_token'),
                'terms_link'                 => data_get($data, 'presigned_acceptance.permalink'),
                'privacy_link'               => data_get($data, 'presigned_personal_data_auth.permalink'),
                'available'                  => (bool) data_get($data, 'presigned_acceptance.acceptance_token'),
            ];
        });
    }

    /**
     * Tokens FRESCOS para crear una transacción (no se confía en el cache para
     * el valor que se envía a Wompi: se pide vigente y se cachea de paso).
     */
    public function freshTokensForTransaction(): array
    {
        return $this->tokens(fresh: true);
    }

    /** Lo único que ve la app: enlaces de los documentos + disponibilidad. */
    public function publicForApp(): array
    {
        $t = $this->tokens();

        return [
            'terms_link'   => $t['terms_link'],
            'privacy_link' => $t['privacy_link'],
            'available'    => $t['available'],
            // Etiquetas sugeridas (la app usa su propio copy/UI).
            'requires_terms_acceptance'        => true,
            'requires_personal_data_authorization' => true,
        ];
    }
}

<?php

namespace App\Services\Billing\Factus;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Gestiona el access_token OAuth2 (password grant) de Factus.
 *
 * - Cachea el token con TTL acotado (config('billing.token_cache_seconds'),
 *   recortado por el expires_in real que devuelva Factus).
 * - NUNCA loguea credenciales ni el token.
 * - Reintenta solo la obtención (operación segura) con backoff corto.
 *
 * La RUTA del endpoint de token ('/oauth/token') y el grant deben confirmarse
 * contra la colección/doc oficial de Factus V2 (ver preguntas para Halltec).
 */
class FactusTokenManager
{
    /** Ruta del endpoint de token (confirmar en doc Factus V2). */
    private const TOKEN_PATH = '/oauth/token';

    public function __construct(private array $cfg)
    {
    }

    public static function fromConfig(): self
    {
        return new self((array) config('billing'));
    }

    private function cacheKey(): string
    {
        return 'billing.factus.token.' . ($this->cfg['env'] ?? 'sandbox');
    }

    /** Devuelve un access_token válido (cacheado o recién emitido). */
    public function accessToken(): string
    {
        $cached = Cache::get($this->cacheKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->refresh();
    }

    /** Fuerza la obtención de un token nuevo y lo cachea. */
    public function refresh(): string
    {
        $creds = (array) ($this->cfg['credentials'] ?? []);
        foreach (['username', 'password', 'client_id', 'client_secret'] as $k) {
            if (empty($creds[$k])) {
                throw new RuntimeException("Factus: credencial '{$k}' no configurada.");
            }
        }

        $response = $this->http()->asForm()->post(self::TOKEN_PATH, [
            'grant_type'    => 'password',
            'client_id'     => $creds['client_id'],
            'client_secret' => $creds['client_secret'],
            'username'      => $creds['username'],
            'password'      => $creds['password'],
        ]);

        if (! $response->successful()) {
            // No exponemos el cuerpo (puede traer pistas de credenciales).
            throw new RuntimeException('Factus: fallo al obtener token (HTTP ' . $response->status() . ').');
        }

        $token = (string) ($response->json('access_token') ?? '');
        if ($token === '') {
            throw new RuntimeException('Factus: respuesta de token sin access_token.');
        }

        $ttl = (int) ($this->cfg['token_cache_seconds'] ?? 3000);
        $expiresIn = (int) ($response->json('expires_in') ?? 0);
        if ($expiresIn > 0) {
            // Margen de 60s para no usar un token a punto de expirar.
            $ttl = min($ttl, max(60, $expiresIn - 60));
        }

        Cache::put($this->cacheKey(), $token, $ttl);

        return $token;
    }

    /** Invalida el token cacheado (p. ej. ante un 401). */
    public function forget(): void
    {
        Cache::forget($this->cacheKey());
    }

    private function http(): PendingRequest
    {
        $http = (array) ($this->cfg['http'] ?? []);

        return Http::baseUrl((string) ($this->cfg['base_url'] ?? ''))
            ->timeout((int) ($http['timeout'] ?? 30))
            ->connectTimeout((int) ($http['connect_timeout'] ?? 10))
            ->acceptJson()
            ->retry(2, 300, throw: false);
    }
}

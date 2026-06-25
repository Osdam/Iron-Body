<?php

namespace App\Services\Billing\Factus;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Gestiona el access_token OAuth2 de Factus V2.
 *
 * Estrategia:
 *   - Si hay un refresh_token cacheado → intenta grant_type=refresh_token
 *     (renueva sin reenviar credenciales).
 *   - Si no hay, o el refresh falla → grant_type=password.
 *   - Cachea access_token (TTL = expires_in - 60s) y refresh_token (TTL largo).
 *   - NUNCA loguea credenciales ni tokens.
 *
 * Endpoint y grants confirmados contra la colección oficial:
 *   POST /oauth/token  (password | refresh_token)
 */
class FactusTokenManager
{
    private const TOKEN_PATH = '/oauth/token';

    public function __construct(private array $cfg)
    {
    }

    public static function fromConfig(): self
    {
        return new self((array) config('billing'));
    }

    private function env(): string
    {
        return (string) ($this->cfg['env'] ?? 'sandbox');
    }

    private function accessKey(): string
    {
        return 'billing.factus.token.' . $this->env();
    }

    private function refreshKey(): string
    {
        return 'billing.factus.refresh.' . $this->env();
    }

    /** Devuelve un access_token válido (cacheado o recién emitido). */
    public function accessToken(): string
    {
        $cached = Cache::get($this->accessKey());
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->refresh();
    }

    /**
     * Obtiene un token nuevo: primero por refresh_token (si existe), luego por
     * password. Cachea ambos tokens. Devuelve el access_token.
     */
    public function refresh(): string
    {
        $refreshToken = Cache::get($this->refreshKey());
        if (is_string($refreshToken) && $refreshToken !== '') {
            $response = $this->request([
                'grant_type'    => 'refresh_token',
                'client_id'     => $this->cred('client_id'),
                'client_secret' => $this->cred('client_secret'),
                'refresh_token' => $refreshToken,
            ]);
            if ($response->successful()) {
                return $this->store($response);
            }
            // Refresh inválido/expirado: lo descartamos y caemos a password.
            Cache::forget($this->refreshKey());
        }

        return $this->passwordGrant();
    }

    private function passwordGrant(): string
    {
        $response = $this->request([
            'grant_type'    => 'password',
            'client_id'     => $this->cred('client_id'),
            'client_secret' => $this->cred('client_secret'),
            'username'      => $this->cred('username'),
            'password'      => $this->cred('password'),
        ]);

        if (! $response->successful()) {
            // No exponemos el cuerpo (puede traer pistas de credenciales).
            throw new RuntimeException('Factus: fallo al obtener token (HTTP ' . $response->status() . ').');
        }

        return $this->store($response);
    }

    /** Persiste access_token (y refresh_token si vino) en cache. */
    private function store(Response $response): string
    {
        $token = (string) ($response->json('access_token') ?? '');
        if ($token === '') {
            throw new RuntimeException('Factus: respuesta de token sin access_token.');
        }

        $ttl = (int) ($this->cfg['token_cache_seconds'] ?? 3000);
        $expiresIn = (int) ($response->json('expires_in') ?? 0);
        if ($expiresIn > 0) {
            $ttl = min($ttl, max(60, $expiresIn - 60));
        }
        Cache::put($this->accessKey(), $token, $ttl);

        $refresh = (string) ($response->json('refresh_token') ?? '');
        if ($refresh !== '') {
            // El refresh dura mucho más que el access; lo guardamos aparte.
            Cache::put($this->refreshKey(), $refresh, (int) ($this->cfg['refresh_cache_seconds'] ?? 1209600));
        }

        return $token;
    }

    /** Invalida el access_token cacheado (p. ej. ante un 401). */
    public function forget(): void
    {
        Cache::forget($this->accessKey());
    }

    private function cred(string $key): string
    {
        $creds = (array) ($this->cfg['credentials'] ?? []);
        if (empty($creds[$key])) {
            throw new RuntimeException("Factus: credencial '{$key}' no configurada.");
        }

        return (string) $creds[$key];
    }

    private function request(array $form): Response
    {
        return $this->http()->asForm()->post(self::TOKEN_PATH, $form);
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

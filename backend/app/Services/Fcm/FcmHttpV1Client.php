<?php

namespace App\Services\Fcm;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Cliente de la API HTTP v1 de Firebase Cloud Messaging. Se autentica con una
 * cuenta de servicio (service account JSON): firma un JWT RS256, lo canjea por
 * un access token OAuth2 (cacheado) y envía mensajes. Sin librerías externas.
 */
class FcmHttpV1Client
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SCOPE     = 'https://www.googleapis.com/auth/firebase.messaging';
    private const CACHE_KEY = 'fcm.access_token';

    /** @var array{client_email:string,private_key:string,project_id:string}|null */
    private ?array $credentials = null;

    /** ¿Hay credenciales válidas para operar? */
    public function isConfigured(): bool
    {
        return (bool) config('fcm.enabled', false) && $this->loadCredentials() !== null;
    }

    public function projectId(): ?string
    {
        $explicit = config('fcm.project_id');
        if ($explicit) {
            return (string) $explicit;
        }
        return $this->loadCredentials()['project_id'] ?? null;
    }

    /**
     * Envía un mensaje FCM. Devuelve true si la API lo aceptó. `false` con
     * `$unregistered=true` indica que el token ya no es válido (borrarlo).
     */
    public function send(array $message, bool &$unregistered = false): bool
    {
        $unregistered = false;
        $projectId = $this->projectId();
        $accessToken = $this->accessToken();
        if (! $projectId || ! $accessToken) {
            return false;
        }

        try {
            $response = Http::withToken($accessToken)
                ->timeout(15)
                ->post("https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send", [
                    'message' => $message,
                ]);

            if ($response->successful()) {
                return true;
            }

            $status = $response->status();
            $error  = (string) $response->json('error.status', '');
            if ($status === 404 || $error === 'NOT_FOUND' || $error === 'UNREGISTERED') {
                $unregistered = true;
            }
            Log::warning('FCM: envío no exitoso', ['status' => $status, 'body' => $response->body()]);
            return false;
        } catch (\Throwable $e) {
            Log::warning('FCM: excepción al enviar', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /** Access token OAuth2 (cacheado ~55 min). */
    private function accessToken(): ?string
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $creds = $this->loadCredentials();
        if (! $creds) {
            return null;
        }

        try {
            $jwt = $this->buildJwt($creds);
            $response = Http::asForm()->timeout(15)->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]);

            if (! $response->successful()) {
                Log::warning('FCM: no se pudo obtener access token', ['body' => $response->body()]);
                return null;
            }

            $token = (string) $response->json('access_token', '');
            if ($token === '') {
                return null;
            }

            Cache::put(self::CACHE_KEY, $token, (int) config('fcm.token_ttl', 3300));
            return $token;
        } catch (\Throwable $e) {
            Log::warning('FCM: excepción obteniendo token', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function buildJwt(array $creds): string
    {
        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss'   => $creds['client_email'],
            'scope' => self::SCOPE,
            'aud'   => self::TOKEN_URL,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];

        $segments = [
            $this->b64url(json_encode($header)),
            $this->b64url(json_encode($claims)),
        ];
        $signingInput = implode('.', $segments);

        $signature = '';
        $ok = openssl_sign($signingInput, $signature, $creds['private_key'], OPENSSL_ALGO_SHA256);
        if (! $ok) {
            throw new RuntimeException('No se pudo firmar el JWT de FCM (clave inválida).');
        }
        $segments[] = $this->b64url($signature);

        return implode('.', $segments);
    }

    /** @return array{client_email:string,private_key:string,project_id:string}|null */
    private function loadCredentials(): ?array
    {
        if ($this->credentials !== null) {
            return $this->credentials;
        }

        $path = (string) config('fcm.credentials', '');
        if ($path === '') {
            return null;
        }
        if (! str_starts_with($path, '/')) {
            $path = base_path($path);
        }
        if (! is_file($path)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($path), true);
        if (! is_array($json) || empty($json['client_email']) || empty($json['private_key'])) {
            Log::warning('FCM: service account JSON inválido o incompleto.');
            return null;
        }

        return $this->credentials = [
            'client_email' => (string) $json['client_email'],
            'private_key'  => (string) $json['private_key'],
            'project_id'   => (string) ($json['project_id'] ?? config('fcm.project_id', '')),
        ];
    }

    private function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

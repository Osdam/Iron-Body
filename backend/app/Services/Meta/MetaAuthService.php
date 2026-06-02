<?php

namespace App\Services\Meta;

/**
 * Acceso central a la configuración de Meta. Los tokens viven SOLO aquí (config
 * → env del servidor); nunca se exponen a Angular/Flutter. Mientras
 * `META_ENABLED=false`, `enabled()` es false y los servicios no contactan Graph.
 */
class MetaAuthService
{
    public function enabled(): bool
    {
        return (bool) config('meta.enabled');
    }

    /** ¿Hay credenciales mínimas para operar contra Graph API? */
    public function isConfigured(): bool
    {
        return $this->enabled()
            && (string) config('meta.access_token') !== ''
            && (string) config('meta.app_secret') !== '';
    }

    public function accessToken(): ?string
    {
        return config('meta.access_token');
    }

    public function timeout(): int
    {
        return (int) config('meta.timeout', 20);
    }

    /** URL absoluta de un nodo/edge de la Graph API. */
    public function graphUrl(string $path): string
    {
        $base = rtrim((string) config('meta.graph_base'), '/');
        $version = trim((string) config('meta.graph_version'), '/');
        $path = ltrim($path, '/');

        return "{$base}/{$version}/{$path}";
    }
}

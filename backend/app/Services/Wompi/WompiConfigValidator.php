<?php

namespace App\Services\Wompi;

use RuntimeException;

/**
 * Valida la coherencia de la configuración de Wompi para impedir el error más
 * caro en una pasarela: mezclar ambientes (procesar producción con llaves de
 * sandbox o viceversa).
 *
 * Reglas:
 *   - sandbox     → llaves *_test_*  + api_url sandbox.wompi.co
 *   - production  → llaves *_prod_*  + api_url production.wompi.co
 *
 * Filosofía de fallo:
 *   - MISMATCH (llave presente con prefijo del ambiente equivocado) → SIEMPRE
 *     es un error duro: assertValid() lanza excepción (no arrancamos así).
 *   - Llaves vacías (placeholders en dev/sandbox sin credenciales aún) → NO es
 *     error: solo se reporta como "no configurado". En producción, en cambio,
 *     unas llaves vacías SÍ son error.
 */
class WompiConfigValidator
{
    public function __construct(private array $cfg)
    {
    }

    public static function fromConfig(): self
    {
        return new self((array) config('wompi'));
    }

    /** @return string[] Lista de problemas (vacía = configuración válida). */
    public function issues(): array
    {
        $issues = [];
        $env = $this->cfg['env'] ?? 'sandbox';
        $isProd = $env === 'production';

        if (! in_array($env, ['sandbox', 'production'], true)) {
            $issues[] = "WOMPI_ENV inválido: '{$env}' (usa sandbox|production).";
        }

        $expected = $isProd ? 'prod' : 'test';

        // public_key: pub_test_* | pub_prod_*
        $issues = array_merge($issues, $this->checkKey('public_key', 'WOMPI_PUBLIC_KEY', "pub_{$expected}_", $isProd));
        // private_key: prv_test_* | prv_prod_*
        $issues = array_merge($issues, $this->checkKey('private_key', 'WOMPI_PRIVATE_KEY', "prv_{$expected}_", $isProd));
        // integrity_secret: contiene "test"|"prod" según ambiente.
        $issues = array_merge($issues, $this->checkSecretEnv('integrity_secret', 'WOMPI_INTEGRITY_SECRET', $expected, $isProd));
        // events_secret: contiene "test"|"prod" según ambiente.
        $issues = array_merge($issues, $this->checkSecretEnv('events_secret', 'WOMPI_EVENTS_SECRET', $expected, $isProd));

        // URL base coherente con el ambiente.
        $apiUrl = (string) ($this->cfg['api_url'] ?? '');
        if ($isProd && ! str_contains($apiUrl, 'production.wompi.co')) {
            $issues[] = "WOMPI: en producción la api_url debe apuntar a production.wompi.co (actual: {$apiUrl}).";
        }
        if (! $isProd && $apiUrl !== '' && str_contains($apiUrl, 'production.wompi.co')) {
            $issues[] = 'WOMPI: en sandbox la api_url NO puede ser production.wompi.co.';
        }

        return $issues;
    }

    /** Solo errores DUROS (mismatch / faltantes en prod): los que impiden arrancar. */
    public function hardIssues(): array
    {
        return array_values(array_filter($this->issues(), fn ($i) => ! str_contains($i, '[soft]')));
    }

    /** Lanza si hay problemas duros. Llamado en boot (guardado). */
    public function assertValid(): void
    {
        $hard = $this->hardIssues();
        if ($hard !== []) {
            throw new RuntimeException('Configuración Wompi inválida: '.implode(' | ', $hard));
        }
    }

    public function isConfigured(): bool
    {
        return ! empty($this->cfg['public_key'])
            && ! empty($this->cfg['private_key'])
            && ! empty($this->cfg['integrity_secret'])
            && ! empty($this->cfg['events_secret']);
    }

    /** @return string[] */
    private function checkKey(string $key, string $envName, string $prefix, bool $isProd): array
    {
        $val = (string) ($this->cfg[$key] ?? '');
        if ($val === '') {
            // Vacío: error duro solo en producción; en sandbox es "no configurado".
            return $isProd ? ["{$envName} es obligatorio en producción."] : [];
        }
        if (! str_starts_with($val, $prefix)) {
            return ["{$envName} no corresponde al ambiente ({$this->cfg['env']}): se esperaba prefijo '{$prefix}'."];
        }
        return [];
    }

    /** @return string[] */
    private function checkSecretEnv(string $key, string $envName, string $expected, bool $isProd): array
    {
        $val = strtolower((string) ($this->cfg[$key] ?? ''));
        if ($val === '') {
            return $isProd ? ["{$envName} es obligatorio en producción."] : [];
        }
        $other = $expected === 'prod' ? 'test' : 'prod';
        // Si el secreto trae explícitamente el prefijo del OTRO ambiente → mismatch.
        if (str_contains($val, $other) && ! str_contains($val, $expected)) {
            return ["{$envName} parece de '{$other}' pero el ambiente es '{$this->cfg['env']}'."];
        }
        return [];
    }
}

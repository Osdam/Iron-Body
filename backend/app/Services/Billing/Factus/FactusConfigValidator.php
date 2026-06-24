<?php

namespace App\Services\Billing\Factus;

use RuntimeException;

/**
 * Valida la coherencia de la configuración de facturación electrónica (Factus)
 * para impedir el error más caro: arrancar producción con credenciales/URL de
 * sandbox o emitir sin datos fiscales del emisor.
 *
 * Reglas:
 *   - env válido: sandbox | production.
 *   - production NO debe apuntar a una base_url de sandbox.
 *   - Si FACTUS_ENABLED=true: credenciales OAuth2 + datos del emisor + rango de
 *     numeración deben estar presentes.
 *
 * Filosofía de fallo (idéntica a WompiConfigValidator):
 *   - Problemas DUROS (hardIssues): en producción abortan el arranque; en local
 *     solo se advierten para no bloquear el desarrollo con el flag apagado.
 *   - Con FACTUS_ENABLED=false las credenciales vacías NO son problema: el
 *     módulo está inerte a propósito.
 */
class FactusConfigValidator
{
    public function __construct(private array $cfg)
    {
    }

    public static function fromConfig(): self
    {
        return new self((array) config('billing'));
    }

    /** @return string[] Lista de problemas (vacía = configuración válida). */
    public function issues(): array
    {
        $issues = [];

        $env = $this->cfg['env'] ?? 'sandbox';
        if (! in_array($env, ['sandbox', 'production'], true)) {
            $issues[] = "FACTUS_ENV inválido: '{$env}' (usa sandbox|production).";
        }
        $isProd = $env === 'production';

        $baseUrl = (string) ($this->cfg['base_url'] ?? '');
        if ($baseUrl === '') {
            $issues[] = 'FACTUS_BASE_URL vacío.';
        }
        // En producción jamás debe quedar apuntando al sandbox.
        if ($isProd && str_contains($baseUrl, 'sandbox')) {
            $issues[] = "FACTUS: en producción la base_url no puede apuntar a sandbox (actual: {$baseUrl}).";
        }

        // Con el módulo apagado no exigimos credenciales: está inerte a propósito.
        if (! ($this->cfg['enabled'] ?? false)) {
            return $issues;
        }

        // A partir de aquí, FACTUS_ENABLED=true → todo debe estar configurado.
        $creds = (array) ($this->cfg['credentials'] ?? []);
        foreach (['username', 'password', 'client_id', 'client_secret'] as $key) {
            if (empty($creds[$key])) {
                $issues[] = "FACTUS habilitado pero falta credencial '{$key}'.";
            }
        }

        $company = (array) ($this->cfg['company'] ?? []);
        foreach (['nit', 'name'] as $key) {
            if (empty($company[$key])) {
                $issues[] = "FACTUS habilitado pero falta dato del emisor '{$key}'.";
            }
        }

        if (empty(($this->cfg['numbering'] ?? [])['range_id'])) {
            $issues[] = 'FACTUS habilitado pero falta FACTUS_NUMBERING_RANGE_ID.';
        }

        $consumer = (array) ($this->cfg['consumer_final'] ?? []);
        foreach (['document_type', 'document_number'] as $key) {
            if (empty($consumer[$key])) {
                $issues[] = "FACTUS habilitado pero falta consumidor final '{$key}'.";
            }
        }

        return $issues;
    }

    /**
     * Problemas que deben abortar el arranque en producción. Hoy todos los
     * issues son duros cuando el módulo está habilitado; el método existe para
     * paralelismo con WompiConfigValidator y para afinar la severidad después.
     *
     * @return string[]
     */
    public function hardIssues(): array
    {
        return $this->issues();
    }

    /** @throws RuntimeException si hay problemas duros. */
    public function assertValid(): void
    {
        $hard = $this->hardIssues();
        if ($hard !== []) {
            throw new RuntimeException(implode(' | ', $hard));
        }
    }
}

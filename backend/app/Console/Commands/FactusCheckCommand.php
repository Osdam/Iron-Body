<?php

namespace App\Console\Commands;

use App\Services\Billing\Factus\FactusClient;
use App\Services\Billing\Factus\FactusTokenManager;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Diagnóstico READ-ONLY de la integración Factus (sandbox).
 *
 * - Valida que se pueda obtener un token (password grant) SIN imprimirlo.
 * - Lista los rangos de numeración (GET /v2/numbering-ranges) para elegir
 *   FACTUS_NUMBERING_RANGE_ID.
 *
 * No emite nada, no modifica nada, no imprime tokens ni secretos.
 * Exige ambiente sandbox y no-producción.
 *
 * Uso:  php artisan billing:factus-check
 */
class FactusCheckCommand extends Command
{
    protected $signature = 'billing:factus-check';
    protected $description = 'Valida credenciales Factus sandbox y lista rangos de numeración (read-only).';

    public function handle(): int
    {
        if (! $this->guards()) {
            return self::FAILURE;
        }

        // 1) Credenciales / token (sin imprimirlo).
        $this->line('Validando credenciales (POST /oauth/token)…');
        try {
            $token = FactusTokenManager::fromConfig()->accessToken();
            if ($token === '') {
                $this->error('  ✖ No se obtuvo access_token.');
                return self::FAILURE;
            }
            $this->info('  ✔ Credenciales OK (token recibido, no se muestra).');
        } catch (Throwable $e) {
            $this->error('  ✖ Fallo de autenticación: ' . $e->getMessage());
            return self::FAILURE;
        }

        // 2) Rangos de numeración.
        $this->line('');
        $this->line('Consultando rangos de numeración (GET /v2/numbering-ranges)…');
        $res = FactusClient::make()->getNumberingRanges();
        if (! $res['ok']) {
            $this->error('  ✖ No se pudieron listar rangos (HTTP ' . $res['status'] . ', ' . $res['error_class'] . ').');
            return self::FAILURE;
        }

        // La respuesta real pagina los rangos en data.data[].
        $ranges = Arr::get($res['body'], 'data.data', Arr::get($res['body'], 'data', $res['body']));
        $ranges = is_array($ranges) ? $ranges : [];
        if ($ranges === []) {
            $this->warn('  No hay rangos configurados en la cuenta sandbox.');
            return self::SUCCESS;
        }

        $rows = [];
        foreach ($ranges as $r) {
            if (! is_array($r)) {
                continue;
            }
            $rows[] = [
                $r['id'] ?? $r['numbering_range_id'] ?? '?',
                $r['document'] ?? $r['document_code'] ?? '',
                $r['prefix'] ?? '',
                $r['from'] ?? '',
                $r['to'] ?? '',
                $r['current'] ?? '',
                ($r['is_active'] ?? $r['active'] ?? null) ? 'sí' : 'no',
            ];
        }
        $this->table(['id', 'document', 'prefix', 'from', 'to', 'current', 'activo'], $rows);
        $this->line('');
        $this->info('Copia el "id" del rango de FACTURA DE VENTA a FACTUS_NUMBERING_RANGE_ID en .env y corre: php artisan config:clear');

        return self::SUCCESS;
    }

    private function guards(): bool
    {
        if ($this->laravel->environment('production')) {
            $this->error('Bloqueado: no se ejecuta en producción.');
            return false;
        }
        if (config('billing.env') !== 'sandbox' || ! str_contains((string) config('billing.base_url'), 'sandbox')) {
            $this->error('Bloqueado: requiere FACTUS_ENV=sandbox y base_url de sandbox.');
            return false;
        }

        return true;
    }
}

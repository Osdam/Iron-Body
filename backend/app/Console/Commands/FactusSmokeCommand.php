<?php

namespace App\Console\Commands;

use App\Enums\InvoiceStatus;
use App\Jobs\EmitElectronicInvoiceJob;
use App\Models\ElectronicInvoice;
use App\Models\Payment;
use App\Services\Billing\Factus\FactusClient;
use App\Services\Billing\InvoicingService;
use Illuminate\Console\Command;
use Throwable;

/**
 * SMOKE TEST controlado: emite UNA factura electrónica real en el ambiente
 * SANDBOX de Factus, a partir de un Payment `paid` existente.
 *
 * Seguridad (todas obligatorias):
 *   - No corre en producción.
 *   - Exige FACTUS_ENV=sandbox y base_url de sandbox.
 *   - Exige credenciales configuradas.
 *   - Exige confirmación explícita (--confirm o prompt).
 *   - Activa billing.enabled SOLO en runtime (no toca .env).
 *
 * NUNCA imprime tokens ni secretos.
 *
 * Uso:
 *   php artisan billing:factus-smoke --payment-id=123
 *   php artisan billing:factus-smoke --payment-id=123 --confirm
 *   php artisan billing:factus-smoke --payment-id=123 --cleanup
 */
class FactusSmokeCommand extends Command
{
    protected $signature = 'billing:factus-smoke
        {--payment-id= : ID de un Payment con status=paid}
        {--confirm : Omite el prompt de confirmación}
        {--cleanup : Borra la factura de prueba (local + DELETE en Factus)}';

    protected $description = 'Emite una factura de prueba en Factus sandbox usando un payment paid (controlado).';

    public function handle(): int
    {
        if (! $this->guards()) {
            return self::FAILURE;
        }

        $paymentId = (int) $this->option('payment-id');
        if ($paymentId <= 0) {
            $this->error('Falta --payment-id.');
            return self::FAILURE;
        }

        /** @var Payment|null $payment */
        $payment = Payment::find($paymentId);
        if ($payment === null) {
            $this->error("Payment #{$paymentId} no existe.");
            return self::FAILURE;
        }
        if ($payment->status !== 'paid') {
            $this->error("Payment #{$paymentId} no está 'paid' (está '{$payment->status}').");
            return self::FAILURE;
        }

        // Asegura la factura SIN disparar emisión (enabled=false).
        config(['billing.enabled' => false]);
        $invoice = $payment->electronicInvoice()->first()
            ?? app(InvoicingService::class)->enqueueForPayment($payment);

        if ($invoice === null) {
            $this->error('No se pudo crear la factura local (revisa datos del payment/plan).');
            return self::FAILURE;
        }

        if ($this->option('cleanup')) {
            return $this->cleanup($invoice);
        }

        if ($invoice->status === InvoiceStatus::VALIDATED) {
            $this->warn('La factura ya estaba VALIDADA. No se reemite.');
            $this->report($invoice->refresh());
            return self::SUCCESS;
        }

        if (! $this->option('confirm')
            && ! $this->confirm("¿Emitir factura REAL en SANDBOX para el payment #{$paymentId} (monto {$payment->amount})?")) {
            $this->line('Cancelado.');
            return self::SUCCESS;
        }

        // Activa la emisión SOLO en runtime y corre el job síncrono.
        config(['billing.enabled' => true]);
        $this->line('Emitiendo en sandbox (POST /v2/bills/validate)…');
        try {
            $this->laravel->call([new EmitElectronicInvoiceJob($invoice->id), 'handle']);
        } catch (Throwable $e) {
            $this->error('Error técnico al emitir: ' . $e->getMessage());
            $this->report($invoice->refresh());
            return self::FAILURE;
        }

        $invoice->refresh();
        $this->report($invoice);

        return $invoice->status === InvoiceStatus::VALIDATED ? self::SUCCESS : self::FAILURE;
    }

    private function report(ElectronicInvoice $i): void
    {
        $this->line('');
        $this->table(['Campo', 'Valor'], [
            ['Estado interno', $i->status->value],
            ['Estado DIAN', $i->dian_status ?? '—'],
            ['Número', $i->full_number ?? '—'],
            ['CUFE', $i->cufe ?? '—'],
            ['PDF', $i->pdf_path ?: ($i->pdf_url ?: '—')],
            ['XML', $i->xml_path ?: ($i->xml_url ?: '—')],
            ['Motivo (si falló)', $i->failure_reason ?? '—'],
            ['Reference', $i->uuid],
        ]);
        if ($i->status === InvoiceStatus::VALIDATED) {
            $this->info('✔ Factura validada en sandbox.');
        } else {
            $this->warn('La factura NO quedó validada. Revisa el motivo y los logs (electronic_invoice_logs).');
        }
    }

    private function cleanup(ElectronicInvoice $invoice): int
    {
        $ref = $invoice->uuid;
        config(['billing.enabled' => true]);
        try {
            $res = FactusClient::make()->destroyByReference($ref);
            $this->line($res['ok']
                ? '✔ Eliminada en Factus sandbox.'
                : '⚠ No se pudo eliminar en Factus (HTTP ' . $res['status'] . '); puede estar ya validada por DIAN.');
        } catch (Throwable $e) {
            $this->warn('⚠ Error al eliminar en Factus: ' . $e->getMessage());
        }
        $invoice->delete();
        $this->info("Fila local eliminada (reference {$ref}).");

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
        $creds = (array) config('billing.credentials');
        foreach (['username', 'password', 'client_id', 'client_secret'] as $k) {
            if (empty($creds[$k])) {
                $this->error("Bloqueado: falta credencial '{$k}' en .env.");
                return false;
            }
        }

        return true;
    }
}

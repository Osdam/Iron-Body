<?php

namespace App\Console\Commands;

use App\Models\ElectronicInvoice;
use App\Services\Billing\Factus\FactusClient;
use App\Services\Billing\InvoicePdfStorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Backfill READ-SAFE de PDF/XML de facturas ya validadas (Fase 10B).
 *
 * Cierra el caso "DIAN aceptó la factura pero el CRM falló guardando los
 * archivos": re-descarga (GET) el PDF/XML por número y los guarda en disco
 * privado. NUNCA emite facturas, NUNCA crea notas crédito, NUNCA toca totales,
 * estado, CUFE ni número. Solo escribe pdf_path/xml_path (y urls) si faltan.
 *
 * Reglas:
 *   - Solo facturas (type=invoice) con status=validated.
 *   - Solo si tienen full_number REAL (sin guiones; no el uuid interno).
 *   - Solo si falta pdf_path o xml_path.
 *   - --dry-run: no llama a Factus, solo reporta qué haría.
 *   - --limit=50 por defecto.
 *
 * Uso:
 *   php artisan billing:factus-backfill-files --dry-run
 *   php artisan billing:factus-backfill-files --limit=20
 */
class FactusBackfillFilesCommand extends Command
{
    protected $signature = 'billing:factus-backfill-files {--dry-run : Solo reporta, no descarga} {--limit=50 : Máximo de facturas a procesar}';
    protected $description = 'Recupera PDF/XML faltantes de facturas validadas (read-safe, no emite).';

    public function handle(FactusClient $client, InvoicePdfStorageService $storage): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));

        $candidates = ElectronicInvoice::query()
            ->where('type', 'invoice')
            ->where('status', 'validated')
            ->whereNotNull('full_number')
            ->where(fn ($q) => $q->whereNull('pdf_path')->orWhereNull('xml_path'))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $processed = 0;
        $recoveredPdf = 0;
        $recoveredXml = 0;
        $skipped = 0;

        foreach ($candidates as $invoice) {
            $number = (string) $invoice->full_number;

            // Salta números que no son fiscales reales (uuid interno con guiones).
            if (str_contains($number, '-')) {
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $this->line("  [dry-run] recuperaría archivos de #{$invoice->id} ({$number})");
                $processed++;
                continue;
            }

            try {
                $files = $storage->fetchAndStore($invoice, $client, $number);
            } catch (Throwable $e) {
                Log::warning('billing.backfill_failed', ['invoice' => $invoice->id, 'error' => $e->getMessage()]);
                $this->warn("  ✖ #{$invoice->id} ({$number}): " . $e->getMessage());
                continue;
            }

            $attrs = [];
            if (empty($invoice->pdf_path) && ! empty($files['pdf_path'])) {
                $attrs['pdf_path'] = $files['pdf_path'];
                $recoveredPdf++;
            }
            if (empty($invoice->xml_path) && ! empty($files['xml_path'])) {
                $attrs['xml_path'] = $files['xml_path'];
                $recoveredXml++;
            }

            if ($attrs !== []) {
                // READ-SAFE: solo se escriben rutas de archivo; nada más.
                $invoice->forceFill($attrs)->save();
                Log::info('billing.backfill_recovered', array_merge(['invoice' => $invoice->id, 'number' => $number], $attrs));
                $this->info("  ✔ #{$invoice->id} ({$number}): " . implode(', ', array_keys($attrs)));
            } else {
                $this->line("  · #{$invoice->id} ({$number}): sin archivos recuperables todavía");
            }

            $processed++;
        }

        $this->newLine();
        $this->table(['Métrica', 'Valor'], [
            ['Candidatas', (string) $candidates->count()],
            ['Procesadas', (string) $processed],
            ['Saltadas (sin número real)', (string) $skipped],
            ['PDF recuperados', (string) $recoveredPdf],
            ['XML recuperados', (string) $recoveredXml],
            ['Modo', $dryRun ? 'dry-run (sin cambios)' : 'aplicado'],
        ]);

        return self::SUCCESS;
    }
}

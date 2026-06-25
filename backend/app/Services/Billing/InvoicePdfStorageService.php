<?php

namespace App\Services\Billing;

use App\Models\ElectronicInvoice;
use App\Services\Billing\Factus\FactusClient;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Persiste el PDF/XML del comprobante en disco PRIVADO.
 *
 * Factus V2 entrega el contenido como base64 anidado en la respuesta de los
 * endpoints de descarga:
 *   download-pdf → body.data.pdf_base_64_encoded
 *   download-xml → body.data.xml_base_64_encoded
 *
 * Se decodifica, se VALIDA (PDF empieza con %PDF; XML con <?xml o contiene
 * <Invoice) y se guarda en:
 *   storage/app/private/invoices/{invoice.uuid}/factura.pdf|.xml
 * (disco 'local' = storage/app/private en Laravel 11+).
 *
 * El pdf_url/xml_url público se conserva en su propio campo, pero NUNCA
 * sustituye a pdf_path/xml_path (que apunta a la copia privada).
 */
class InvoicePdfStorageService
{
    /**
     * Guarda archivos provenientes de un payload ya mapeado (p. ej. inline en el
     * create) y/o las URLs públicas. Valida el base64 antes de escribir.
     *
     * @param  array<string,mixed>  $mapped
     * @return array<string,?string>
     */
    public function store(ElectronicInvoice $invoice, array $mapped): array
    {
        $out = [];

        if (! empty($mapped['pdf_base64'])
            && ($p = $this->decodeAndSave($invoice, 'pdf', (string) $mapped['pdf_base64']))) {
            $out['pdf_path'] = $p;
        }
        if (! empty($mapped['pdf_url'])) {
            $out['pdf_url'] = (string) $mapped['pdf_url'];
        }

        if (! empty($mapped['xml_base64'])
            && ($x = $this->decodeAndSave($invoice, 'xml', (string) $mapped['xml_base64']))) {
            $out['xml_path'] = $x;
        }
        if (! empty($mapped['xml_url'])) {
            $out['xml_url'] = (string) $mapped['xml_url'];
        }

        return $out;
    }

    /**
     * Descarga PDF y XML por número fiscal y los guarda en disco privado.
     * Best-effort: registra el fallo y devuelve solo lo que sí pudo guardar.
     *
     * @return array<string,?string>  pdf_path / xml_path (solo los logrados)
     */
    public function fetchAndStore(ElectronicInvoice $invoice, FactusClient $client, string $number): array
    {
        $out = [];

        $pdf = $client->downloadPdf($number);
        if ($pdf['ok']) {
            $b64 = $this->extractBase64($pdf['body'], [
                'data.pdf_base_64_encoded', 'data.pdf_base_64', 'data.pdf_base64',
                'pdf_base_64_encoded', 'pdf_base_64', 'pdf_base64',
            ]);
            if ($b64 !== null && ($p = $this->decodeAndSave($invoice, 'pdf', $b64))) {
                $out['pdf_path'] = $p;
            }
        } else {
            Log::warning('billing.download_pdf_failed', [
                'invoice' => $invoice->id, 'number' => $number, 'status' => $pdf['status'] ?? null,
            ]);
        }

        $xml = $client->downloadXml($number);
        if ($xml['ok']) {
            $b64 = $this->extractBase64($xml['body'], [
                'data.xml_base_64_encoded', 'data.xml_base_64', 'data.xml_base64',
                'xml_base_64_encoded', 'xml_base_64', 'xml_base64',
            ]);
            if ($b64 !== null && ($x = $this->decodeAndSave($invoice, 'xml', $b64))) {
                $out['xml_path'] = $x;
            }
        } else {
            Log::warning('billing.download_xml_failed', [
                'invoice' => $invoice->id, 'number' => $number, 'status' => $xml['status'] ?? null,
            ]);
        }

        return $out;
    }

    // ── Internos ────────────────────────────────────────────────────────────

    /** @param  string[]  $keys */
    private function extractBase64(array $body, array $keys): ?string
    {
        foreach ($keys as $k) {
            $v = Arr::get($body, $k);
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }

        return null;
    }

    /**
     * Decodifica, valida el tipo y guarda. Devuelve la ruta o null (sin escribir
     * archivos corruptos). Loguea cualquier fallo de decodificación/validación.
     */
    private function decodeAndSave(ElectronicInvoice $invoice, string $type, string $base64): ?string
    {
        $bytes = base64_decode($base64, true);
        if ($bytes === false || $bytes === '') {
            Log::warning('billing.file_decode_failed', ['invoice' => $invoice->id, 'type' => $type]);
            return null;
        }
        if (! $this->isValid($type, $bytes)) {
            Log::warning('billing.file_invalid', ['invoice' => $invoice->id, 'type' => $type]);
            return null;
        }

        $disk = (string) config('billing.storage.disk', 'local');
        $base = trim((string) config('billing.storage.path', 'invoices'), '/');
        $path = $base . '/' . $invoice->uuid . '/factura.' . $type;

        Storage::disk($disk)->put($path, $bytes);

        return $path;
    }

    /** PDF debe empezar con %PDF; XML con <?xml o contener <Invoice. */
    private function isValid(string $type, string $bytes): bool
    {
        if ($type === 'pdf') {
            return str_starts_with($bytes, '%PDF');
        }
        $head = ltrim($bytes);

        return str_starts_with($head, '<?xml') || str_contains(substr($bytes, 0, 4000), '<Invoice');
    }
}

<?php

namespace App\Services\Billing;

use App\Models\ElectronicInvoice;
use Illuminate\Support\Facades\Storage;

/**
 * Persiste el PDF/XML del comprobante. Si Factus devuelve base64, se guarda en
 * un disco PRIVADO (config('billing.storage')) y se sirve luego por endpoint
 * autenticado; si devuelve URL, se guarda la URL. Nunca expone los archivos de
 * forma pública.
 *
 * @return array<string,?string> atributos a fusionar en la factura
 *   (pdf_path, pdf_url, xml_path, xml_url) — solo los que apliquen.
 */
class InvoicePdfStorageService
{
    /**
     * @param  array<string,mixed>  $mapped  Salida de FactusResponseMapper::map()
     * @return array<string,?string>
     */
    public function store(ElectronicInvoice $invoice, array $mapped): array
    {
        $disk = (string) config('billing.storage.disk', 'local');
        $base = trim((string) config('billing.storage.path', 'invoices'), '/');
        $dir  = $base . '/' . $invoice->uuid;
        $out  = [];

        if (! empty($mapped['pdf_base64'])) {
            $path = $dir . '/factura.pdf';
            Storage::disk($disk)->put($path, base64_decode((string) $mapped['pdf_base64']));
            $out['pdf_path'] = $path;
        } elseif (! empty($mapped['pdf_url'])) {
            $out['pdf_url'] = (string) $mapped['pdf_url'];
        }

        if (! empty($mapped['xml_base64'])) {
            $path = $dir . '/factura.xml';
            Storage::disk($disk)->put($path, base64_decode((string) $mapped['xml_base64']));
            $out['xml_path'] = $path;
        } elseif (! empty($mapped['xml_url'])) {
            $out['xml_url'] = (string) $mapped['xml_url'];
        }

        return $out;
    }
}

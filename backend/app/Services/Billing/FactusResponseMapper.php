<?php

namespace App\Services\Billing;

use Illuminate\Support\Arr;

/**
 * Traduce la respuesta cruda de Factus V2 a los campos internos del comprobante
 * (número, CUFE, QR, estado DIAN, urls/base64 de PDF/XML).
 *
 * IMPORTANTE: las RUTAS exactas de cada dato dentro de la respuesta dependen del
 * contrato real de Factus V2 (ver preguntas pendientes para Halltec). Aquí se
 * cubren los nombres más probables con fallbacks; al confirmar la colección
 * Postman se ajustan las claves en un solo lugar (este mapper) sin tocar el
 * resto del sistema.
 */
class FactusResponseMapper
{
    /**
     * @param  array  $body  Cuerpo JSON ya decodificado de la respuesta de Factus.
     * @return array{
     *   factus_id: ?string, number: ?string, prefix: ?string, full_number: ?string,
     *   cufe: ?string, dian_status: ?string, qr_url: ?string, qr_data: ?string,
     *   pdf_url: ?string, xml_url: ?string, pdf_base64: ?string, xml_base64: ?string,
     *   is_validated: bool, is_rejected: bool, reason: ?string
     * }
     */
    /**
     * Traduce la respuesta de una NOTA CRÉDITO.
     *
     * No vale `map()`: en la respuesta de una nota crédito, `data.bill` es la
     * FACTURA REFERENCIADA, no el documento emitido. Al pasarla por `map()` se
     * guardó en NC1 el número y el CUFE de IBFE10 —la factura que anula— y el
     * comprobante quedó registrado como si fuese ella misma. La nota vive
     * directamente en `data`, y su identificador único es el **CUDE**, no el
     * CUFE.
     *
     * @param  array  $body  Cuerpo JSON ya decodificado.
     * @return array<string,mixed> mismo shape que {@see map()}
     */
    public function mapCreditNote(array $body): array
    {
        $mapped = $this->map(['data' => Arr::get($body, 'data', [])], skipBill: true);

        // Trazabilidad hacia la factura anulada, que sí está en data.bill.
        $mapped['references_number'] = Arr::get($body, 'data.bill.number');

        return $mapped;
    }

    public function map(array $body, bool $skipBill = false): array
    {
        // Factus anida la FACTURA en data.bill. Para una nota crédito ese nodo
        // es la factura referenciada, así que el llamador pide saltárselo.
        $bill = $skipBill
            ? Arr::get($body, 'data', $body)
            : Arr::get($body, 'data.bill', Arr::get($body, 'data', $body));

        $number = $this->first($bill, ['number', 'document_number', 'invoice_number']);
        $prefix = $this->first($bill, ['prefix', 'numbering_range_prefix']);
        $cufe = $this->first($bill, ['cufe', 'cude', 'cufe_cude']);

        $dianStatus = $this->first($bill, ['status', 'dian_status', 'status_document', 'document_status']);

        $statusMap = (array) config('billing.status_map', []);
        $mapped = $dianStatus !== null ? ($statusMap[$dianStatus] ?? null) : null;

        // Heurística de éxito/rechazo cuando no hay mapa configurado todavía.
        $isValidated = $mapped === 'validated'
            || $cufe !== null
            || $this->looksLike($dianStatus, ['valid', 'aprob', 'autoriz', 'acept']);
        $isRejected = $mapped === 'rejected'
            || $this->looksLike($dianStatus, ['rechaz', 'reject', 'error']);

        return [
            'factus_id' => $this->first($bill, ['id', 'bill_id', 'document_id']),
            'number' => $number,
            'prefix' => $prefix,
            // full_number = el número fiscal real (SETP990006967). JAMÁS el
            // reference_code (uuid interno) ni name. Si no hay full_number
            // explícito, se usa data.number; último recurso prefix+number.
            'full_number' => $this->first($bill, ['full_number'])
                ?? $number
                ?? ($prefix && $number ? $prefix.$number : null),
            'cufe' => $cufe,
            'dian_status' => $dianStatus,
            'qr_url' => $this->first($bill, ['links.qr', 'qr_image', 'qr_url', 'qr']),
            'qr_data' => $this->first($bill, ['qr_data', 'qr_code', 'qrcode']),
            'pdf_url' => $this->first($bill, ['links.public_url', 'pdf_url', 'public_url', 'pdf']),
            'xml_url' => $this->first($bill, ['xml_url', 'xml']),
            'pdf_base64' => $this->first($bill, ['pdf_base_64', 'pdf_base64', 'pdf_content']),
            'xml_base64' => $this->first($bill, ['xml_base_64', 'xml_base64', 'xml_content']),
            'is_validated' => $isValidated && ! $isRejected,
            'is_rejected' => $isRejected,
            'reason' => $this->first($body, ['message', 'error', 'errors']) === null
                ? null
                : (is_array(Arr::get($body, 'errors')) ? json_encode(Arr::get($body, 'errors')) : (string) $this->first($body, ['message', 'error'])),
        ];
    }

    /** Primer valor no nulo entre varias claves candidatas. */
    private function first(array $data, array $keys): ?string
    {
        foreach ($keys as $k) {
            $v = Arr::get($data, $k);
            if ($v !== null && $v !== '') {
                return is_scalar($v) ? (string) $v : null;
            }
        }

        return null;
    }

    private function looksLike(?string $value, array $needles): bool
    {
        if ($value === null) {
            return false;
        }
        $v = mb_strtolower($value);
        foreach ($needles as $n) {
            if (str_contains($v, $n)) {
                return true;
            }
        }

        return false;
    }
}

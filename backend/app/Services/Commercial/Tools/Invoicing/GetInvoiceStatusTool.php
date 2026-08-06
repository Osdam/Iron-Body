<?php

namespace App\Services\Commercial\Tools\Invoicing;

use App\Enums\InvoiceStatus;
use App\Models\ElectronicInvoice;
use App\Models\Payment;
use App\Services\Commercial\Tools\BaseTool;
use App\Services\Commercial\Tools\ToolContext;
use App\Services\Commercial\Tools\ToolResult;

/**
 * Estado de la factura electrónica de esta persona.
 *
 * Solo lectura, y con eso basta para el 90 % de los casos reales: casi todas
 * las preguntas sobre facturación son «¿ya me la mandaron?». La emisión en sí
 * queda fuera del alcance del agente por decisión del encargo —las acciones
 * fiscales sensibles exigen revisión humana—, así que aquí no hay ninguna vía
 * para provocar una emisión.
 *
 * El caso interesante es el rechazo: se devuelve el motivo para que el agente
 * pueda pedir el dato concreto que faltaba en vez de soltar un «hubo un
 * problema» que no lleva a ninguna parte.
 */
class GetInvoiceStatusTool extends BaseTool
{
    public function name(): string
    {
        return 'get_invoice_status';
    }

    public function description(): string
    {
        return 'Consulta si la factura electrónica de esta persona ya fue validada, '
            .'está en proceso o fue rechazada, y por qué.';
    }

    public function schema(): array
    {
        return $this->strictSchema([]);
    }

    public function rules(): array
    {
        return [];
    }

    public function featureFlag(): ?string
    {
        return 'commercial.tools.invoicing';
    }

    public function mutates(): bool
    {
        return false;
    }

    public function timeoutSeconds(): int
    {
        return 8;
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $memberId = $context->memberId();

        if ($memberId === null) {
            return ToolResult::ok(['found' => false], 'Esta persona todavía no es socia; no hay facturas.');
        }

        // Los comprobantes cuelgan de su origen por morph. Se buscan los pagos
        // de esta persona y desde ahí las facturas: nunca al revés, para no
        // poder llegar a la factura de otro.
        $paymentIds = Payment::query()
            ->where('member_id', $memberId)
            ->pluck('id');

        if ($paymentIds->isEmpty()) {
            return ToolResult::ok(['found' => false], 'No hay pagos con factura asociada.');
        }

        $invoice = ElectronicInvoice::query()
            ->where('source_type', Payment::class)
            ->whereIn('source_id', $paymentIds)
            ->latest('id')
            ->first();

        if ($invoice === null) {
            return ToolResult::ok(['found' => false], 'Todavía no se ha solicitado factura para esos pagos.');
        }

        $status = (string) $invoice->status;
        $validated = in_array($status, [
            InvoiceStatus::VALIDATED->value,
            InvoiceStatus::CREDIT_NOTE_VALIDATED->value,
        ], true);

        return ToolResult::ok([
            'found' => true,
            'validated' => $validated,
            'status' => $status,
            'full_number' => $invoice->full_number,
            'issued_at' => $invoice->issued_at?->toIso8601String(),
            // El motivo del rechazo es lo que permite pedir el dato que falta.
            'failure_reason' => $invoice->failure_reason,
            'has_pdf' => filled($invoice->pdf_path) || filled($invoice->pdf_url),
            'has_xml' => filled($invoice->xml_path) || filled($invoice->xml_url),
        ], match (true) {
            $validated => 'La factura está validada y disponible.',
            $status === InvoiceStatus::REJECTED->value => 'La DIAN la rechazó. Pide el dato que falta y avisa a una persona.',
            default => 'La factura sigue en proceso.',
        });
    }
}

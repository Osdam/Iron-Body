<?php

namespace App\Services\Commercial\Tools\Payments;

use App\Models\Plan;
use App\Services\Commercial\Tools\BaseTool;
use App\Services\Commercial\Tools\ToolContext;
use App\Services\Commercial\Tools\ToolResult;
use App\Services\Marketing\WompiPaymentLinkService;

/**
 * Genera el enlace de pago de un plan.
 *
 * Fíjese en lo que el esquema NO tiene: no hay `amount`, no hay `price`, no hay
 * `discount`. Solo `plan_id`. El importe lo calcula
 * {@see WompiPaymentLinkService} leyendo el catálogo, y no hay ninguna vía por
 * la que un número propuesto desde la conversación llegue a la pasarela. Un
 * modelo que decide cobrar 50.000 «porque el cliente insistió» describe una
 * intención; el campo simplemente no existe.
 *
 * Tampoco confirma nada. Devolver un enlace no significa que se haya cobrado:
 * eso lo dirá el webhook firmado de Wompi o la consulta oficial, nunca esta
 * herramienta ni una captura de pantalla del cliente.
 *
 * La idempotencia es doble, y es a propósito. El servicio ya reutiliza la
 * transacción en vuelo del mismo (lead, plan) y se niega si hay un pago
 * aprobado; encima, el ejecutor reclama la clave antes de llamar. Cualquiera de
 * las dos bastaría casi siempre; juntas cubren también el caso de dos workers
 * llamando a la vez.
 */
class CreatePaymentLinkTool extends BaseTool
{
    /**
     * El servicio se construye con su factory dentro de `execute()`, no por
     * inyección. Depende de configuración de Wompi que el contenedor no sabe
     * autoconectar, y pedirla en el constructor haría que construir el CATÁLOGO
     * de herramientas fallara entero: una herramienta rota dejaría al agente sin
     * ninguna, incluida la de ceder la conversación a una persona.
     */
    private function links(): WompiPaymentLinkService
    {
        return WompiPaymentLinkService::make();
    }

    public function name(): string
    {
        return 'create_payment_link';
    }

    public function description(): string
    {
        return 'Genera el enlace de pago de un plan concreto para este prospecto. '
            .'El precio lo pone el sistema desde el catálogo: tú solo eliges el plan. '
            .'Recibir el enlace NO significa que esté pagado.';
    }

    public function schema(): array
    {
        return $this->strictSchema([
            'plan_id' => $this->intProp('Identificador del plan, tal como lo devolvió list_plans.'),
            'wants_invoice' => [
                'type' => 'boolean',
                'description' => 'true solo si la persona pidió factura electrónica.',
            ],
        ], ['plan_id']);
    }

    public function rules(): array
    {
        return [
            'plan_id' => ['required', 'integer', 'exists:plans,id'],
            'wants_invoice' => ['sometimes', 'boolean'],
        ];
    }

    public function featureFlag(): ?string
    {
        return 'commercial.tools.payments';
    }

    public function timeoutSeconds(): int
    {
        return 20;
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $lead = $context->lead;

        if ($lead === null) {
            return ToolResult::failed('no_lead_in_context', 'No hay prospecto al que asociar el pago.');
        }

        $plan = Plan::query()->find($arguments['plan_id']);

        // Se vuelve a comprobar que el plan siga ACTIVO. La validación confirmó
        // que existe; entre aquel instante y este, alguien pudo retirarlo del
        // catálogo desde el CRM, y cobrar por un plan retirado es un problema
        // que se descubre tarde y mal.
        if ($plan === null || ! $plan->active) {
            return ToolResult::failed(
                'plan_not_available',
                'Ese plan ya no está disponible. Consulta el catálogo otra vez.',
            );
        }

        $outcome = $this->links()->generateForLead($lead, $plan, [
            'conversation_id' => $context->conversation?->id,
            'channel' => 'whatsapp',
            'wants_invoice' => (bool) ($arguments['wants_invoice'] ?? false),
        ]);

        if (($outcome['configured'] ?? false) === false) {
            // Falta configuración de la pasarela: es un problema nuestro, no del
            // cliente, y se puede reintentar cuando se arregle.
            return ToolResult::failed(
                (string) ($outcome['error'] ?? 'wompi_not_configured'),
                'La pasarela de pago no está configurada. Avisa a una persona del equipo.',
                retryable: true,
            );
        }

        // Ya pagó este plan. No se genera un segundo enlace: es la vía más
        // directa a un cobro duplicado.
        if (($outcome['already_paid'] ?? false) === true) {
            return ToolResult::skipped(
                'Esta persona ya tiene un pago aprobado para ese plan.',
                [
                    'reference' => $outcome['reference'] ?? null,
                    'status' => $outcome['status'] ?? null,
                ],
            );
        }

        if (blank($outcome['payment_url'] ?? null)) {
            return ToolResult::failed(
                'payment_link_unavailable',
                'No se pudo generar el enlace de pago.',
                retryable: true,
            );
        }

        return ToolResult::ok([
            'payment_url' => $outcome['payment_url'],
            'reference' => $outcome['reference'] ?? null,
            // El importe se devuelve para que el agente pueda decirlo en voz
            // alta, pero viene del catálogo, no de la conversación.
            'amount' => $outcome['amount'] ?? (float) $plan->price,
            'currency' => $outcome['currency'] ?? 'COP',
            'plan_name' => $plan->name,
            'plan_id' => $plan->id,
        ], 'Enlace generado. El pago no está confirmado hasta que lo notifique la pasarela.');
    }
}

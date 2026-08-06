<?php

namespace App\Services\Commercial\Tools\Commercial;

use App\Models\Plan;
use App\Services\Commercial\Tools\BaseTool;
use App\Services\Commercial\Tools\ToolContext;
use App\Services\Commercial\Tools\ToolResult;

/**
 * El catálogo vigente, que es la única fuente de precios.
 *
 * Esta herramienta existe para que el modelo NUNCA tenga que recordar un
 * precio. Un modelo de lenguaje que cita de memoria «el mensual está en
 * 120.000» acierta hasta el día en que el gimnasio sube la tarifa, y entonces
 * le promete a un cliente un precio que ya no existe. Aquí los lee, y si no
 * hay catálogo no hay oferta.
 *
 * Es de solo lectura y por eso puede estar suelta: consultar precios es
 * inofensivo y es justo lo que hace falta para poder recomendar bien.
 */
class ListPlansTool extends BaseTool
{
    public function name(): string
    {
        return 'list_plans';
    }

    public function description(): string
    {
        return 'Lista los planes de membresía vigentes con su precio oficial y duración. '
            .'Úsala siempre antes de mencionar un precio: nunca cites uno de memoria.';
    }

    public function schema(): array
    {
        return $this->strictSchema([
            'max_duration_days' => [
                'type' => 'integer',
                'description' => 'Opcional. Solo planes de hasta esta duración, para no ofrecer un anual a quien pidió algo corto.',
            ],
        ]);
    }

    public function rules(): array
    {
        return ['max_duration_days' => ['sometimes', 'integer', 'min:1', 'max:730']];
    }

    public function featureFlag(): ?string
    {
        return 'commercial.tools.catalog';
    }

    public function mutates(): bool
    {
        return false;
    }

    public function timeoutSeconds(): int
    {
        return 5;
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $query = Plan::query()
            ->where('active', true)
            ->orderBy('duration_days');

        if (isset($arguments['max_duration_days'])) {
            $query->where('duration_days', '<=', (int) $arguments['max_duration_days']);
        }

        $plans = $query->get(['id', 'name', 'price', 'duration_days', 'tier', 'benefits', 'is_recommended']);

        if ($plans->isEmpty()) {
            // Sin catálogo no se inventa nada. Es preferible que el agente diga
            // que va a consultar a que se saque un precio de la manga.
            return ToolResult::failed(
                'no_active_plans',
                'No hay planes activos en el catálogo. No ofrezcas precios; deriva a una persona.',
            );
        }

        return ToolResult::ok([
            'plans' => $plans->map(fn (Plan $p) => [
                'plan_id' => $p->id,
                'name' => $p->name,
                // El precio sale del catálogo, siempre. Se entrega como número
                // para que no haya que interpretarlo.
                'price' => (float) $p->price,
                'currency' => 'COP',
                'duration_days' => (int) $p->duration_days,
                'tier' => $p->tier,
                'recommended' => (bool) $p->is_recommended,
            ])->all(),
        ]);
    }
}

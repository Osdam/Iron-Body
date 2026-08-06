<?php

namespace App\Services\Commercial\Tools\Agenda;

use App\Models\MarketingAppointment;
use App\Services\Commercial\Tools\BaseTool;
use App\Services\Commercial\Tools\ToolContext;
use App\Services\Commercial\Tools\ToolResult;
use App\Services\Marketing\MarketingAppointmentService;
use Illuminate\Support\Carbon;

/**
 * Reserva una visita, una llamada o una valoración.
 *
 * La hora se interpreta en el huso de Neiva y no en el del servidor. Parece un
 * detalle y no lo es: el servidor corre en UTC, así que una cita «a las 9» sin
 * huso explícito acabaría citando a la persona a las 4 de la madrugada.
 *
 * Encapsula {@see MarketingAppointmentService}, que es el mismo camino que usa
 * el CRM cuando un asesor agenda a mano. Así una cita creada por el agente y
 * una creada por una persona son indistinguibles para el resto del sistema.
 */
class BookAppointmentTool extends BaseTool
{
    public function __construct(private readonly MarketingAppointmentService $agenda) {}

    public function name(): string
    {
        return 'book_appointment';
    }

    public function description(): string
    {
        return 'Agenda una visita, llamada o valoración. Usa la hora local de Neiva. '
            .'Confirma la hora con la persona antes de reservar.';
    }

    public function schema(): array
    {
        return $this->strictSchema([
            'type' => $this->stringProp('Tipo de cita.', [
                MarketingAppointment::TYPE_VISIT,
                MarketingAppointment::TYPE_CALL,
                MarketingAppointment::TYPE_ASSESSMENT,
            ]),
            'scheduled_at' => $this->stringProp('Fecha y hora local de Neiva, formato Y-m-d H:i.'),
            'duration_minutes' => $this->intProp('Duración; 30 si no se indica.'),
            'notes' => $this->stringProp('Contexto útil para quien la atienda.'),
        ], ['type', 'scheduled_at']);
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', 'in:visit,call,assessment'],
            'scheduled_at' => ['required', 'date_format:Y-m-d H:i'],
            'duration_minutes' => ['sometimes', 'integer', 'min:15', 'max:180'],
            'notes' => ['sometimes', 'string', 'max:500'],
        ];
    }

    public function featureFlag(): ?string
    {
        return 'commercial.tools.agenda';
    }

    public function timeoutSeconds(): int
    {
        return 10;
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        $lead = $context->lead;

        if ($lead === null) {
            return ToolResult::failed('no_lead_in_context', 'No hay prospecto al que citar.');
        }

        $timezone = (string) config('commercial.contact_limits.timezone', 'America/Bogota');

        try {
            // Se interpreta en Neiva y se guarda en el huso de la aplicación.
            $scheduledAt = Carbon::createFromFormat(
                'Y-m-d H:i',
                (string) $arguments['scheduled_at'],
                $timezone,
            )->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return ToolResult::failed('invalid_datetime', 'No entendí la fecha y hora.');
        }

        if ($scheduledAt->isPast()) {
            return ToolResult::failed('scheduled_in_the_past', 'Esa fecha ya pasó. Propón otra.');
        }

        // No se cita a alguien a un año vista: casi siempre es un error de
        // interpretación del año, y produce una agenda llena de basura.
        if ($scheduledAt->greaterThan(now()->addMonths(3))) {
            return ToolResult::failed('scheduled_too_far', 'Esa fecha está demasiado lejos. Confirma el día.');
        }

        // Anti duplicado: una cita viva del mismo tipo para la misma persona.
        // Sin esto, un cliente que dice dos veces «sí, el martes» acaba con dos
        // reservas y alguien del equipo pierde una hora.
        $existing = MarketingAppointment::query()
            ->where('marketing_lead_id', $lead->id)
            ->where('status', MarketingAppointment::STATUS_SCHEDULED)
            ->where('scheduled_at', '>=', now())
            ->first();

        if ($existing !== null) {
            return ToolResult::skipped('Esta persona ya tiene una cita agendada.', [
                'appointment_id' => $existing->id,
                'scheduled_at' => $existing->scheduled_at?->toIso8601String(),
            ]);
        }

        $appointment = $this->agenda->create([
            'marketing_lead_id' => $lead->id,
            'marketing_conversation_id' => $context->conversation?->id,
            'type' => $arguments['type'],
            'title' => $this->titleFor((string) $arguments['type'], $lead->name),
            'notes' => $arguments['notes'] ?? null,
            'scheduled_at' => $scheduledAt,
            'duration_minutes' => $arguments['duration_minutes'] ?? 30,
        ], null);

        return ToolResult::ok([
            'appointment_id' => $appointment->id,
            'type' => $appointment->type,
            'scheduled_at_local' => $scheduledAt->copy()->setTimezone($timezone)->format('Y-m-d H:i'),
        ], 'Cita agendada.');
    }

    private function titleFor(string $type, ?string $name): string
    {
        $who = $name ?: 'prospecto';

        return match ($type) {
            MarketingAppointment::TYPE_VISIT => "Visita al gimnasio — {$who}",
            MarketingAppointment::TYPE_CALL => "Llamada — {$who}",
            default => "Valoración física — {$who}",
        };
    }
}

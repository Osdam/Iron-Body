<?php

namespace Tests\Feature\Valuations\Seguimiento;

use App\Models\Member;
use App\Models\ProfessionalAssessment;
use App\Models\Trainer;
use App\Models\ValuationAppointment;
use App\Models\ValuationAppointmentEvent;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Valuations\ValuationFixtures;

/**
 * Datos del seguimiento de valoraciones (CONTRATO_CICLO_VALORACIONES.md §3).
 *
 * Las valoraciones y las citas cerradas se crean directamente con sus modelos:
 * lo que se prueba es cómo el seguimiento LEE esas filas, no cómo nacen (eso es
 * de la agenda y de la app del entrenador). Las horas se escriben en hora de
 * Bogotá y se guardan en UTC, como en producción; el reloj va en UTC
 * ({@see ValuationFixtures::clock()}).
 */
trait SeguimientoFixtures
{
    use ValuationFixtures;

    /** Valoración profesional enviada a esa hora de Bogotá. */
    protected function valoracion(Member $m, Trainer $t, string $bogota, array $extra = []): ProfessionalAssessment
    {
        return ProfessionalAssessment::create(array_merge([
            'member_id' => $m->id,
            'trainer_id' => $t->id,
            'status' => ProfessionalAssessment::STATUS_SUBMITTED,
            'version' => 1,
            'weight_kg' => 70,
            'submitted_at' => Carbon::parse($bogota, 'America/Bogota')->utc(),
        ], $extra));
    }

    /**
     * Cita con esa hora de inicio (Bogotá) y ese estado. Completada: con
     * `completed_at` (por defecto, la hora de inicio), la valoración enlazada y,
     * si se da, el evento `completed` con su `metadata.source`.
     */
    protected function cita(Member $m, Trainer $t, string $bogota, string $estado = ValuationAppointment::STATUS_COMPLETED, ?ProfessionalAssessment $enlazada = null, ?string $fuente = null, ?string $completadaBogota = null): ValuationAppointment
    {
        $inicio = Carbon::parse($bogota, 'America/Bogota')->utc();
        $completada = $estado === ValuationAppointment::STATUS_COMPLETED
            ? ($completadaBogota !== null ? Carbon::parse($completadaBogota, 'America/Bogota')->utc() : $inicio->copy())
            : null;

        $cita = ValuationAppointment::create([
            'member_id' => $m->id,
            'trainer_id' => $t->id,
            'type' => ValuationAppointment::TYPE_INITIAL,
            'status' => $estado,
            'starts_at' => $inicio,
            'ends_at' => $inicio->copy()->addHour(),
            'duration_minutes' => 60,
            'completed_at' => $completada,
            'professional_assessment_id' => $enlazada?->id,
        ]);

        if ($fuente !== null) {
            ValuationAppointmentEvent::create([
                'appointment_id' => $cita->id,
                'action' => ValuationAppointmentEvent::ACTION_COMPLETED,
                'actor_name' => 'Entrenador',
                'from_status' => ValuationAppointment::STATUS_SCHEDULED,
                'to_status' => ValuationAppointment::STATUS_COMPLETED,
                'metadata' => ['source' => $fuente],
                'created_at' => $completada ?? $inicio,
            ]);
        }

        return $cita;
    }

    protected function seguimiento(array $headers, Member $m): TestResponse
    {
        return $this->getJson("/api/admin/valuations/members/{$m->id}/follow-up", $headers);
    }

    /** PUT entero; lo que no se dé, como está (intervalo heredado, activo, sin fecha). */
    protected function cambiar(array $headers, Member $m, array $cuerpo): TestResponse
    {
        return $this->putJson("/api/admin/valuations/members/{$m->id}/follow-up", array_merge([
            'interval_days' => null,
            'enabled' => true,
            'due_on' => null,
            'expected_version' => 0,
        ], $cuerpo), $headers);
    }
}

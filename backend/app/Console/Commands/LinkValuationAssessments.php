<?php

namespace App\Console\Commands;

use App\Models\ProfessionalAssessment;
use App\Models\ValuationAppointment;
use App\Services\Valuations\ValuationAgenda;
use App\Services\Valuations\ValuationAvailability;
use App\Services\Valuations\ValuationTime;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Conciliación histórica de citas y valoraciones (CONTRATO_CICLO_VALORACIONES.md §2).
 *
 *   php artisan valuations:link-assessments            (ENSAYO: no escribe)
 *   php artisan valuations:link-assessments --apply    (enlaza y completa)
 *   … --json                                           (el informe en JSON)
 *
 * Recorre las valoraciones ENVIADAS que tienen alguna cita del mismo socio el
 * mismo día de Bogotá, en el orden en que se enviaron, y les aplica EXACTAMENTE
 * la regla del envío ({@see ValuationAgenda::matchAssessment()}): la misma
 * función, no una copia.
 *
 * «Enviadas» son las RAÍCES (`parent_id` nulo) con `submitted_at`, en estado
 * `submitted` o `amended`: es lo que habría disparado el envío (§1), y una
 * raíz corregida después sigue siendo la valoración de ese día. Las enmiendas
 * no cierran citas (§1: ni la enmienda) y quedan fuera.
 *
 * Al aplicar, por cada `linked` y en su propia transacción:
 * {@see ValuationAgenda::completeFromAssessment()} con el origen
 * `historical_reconciliation`: `completed_at = submitted_at`, evento `completed`
 * con `reconciled_at`, avisos pendientes anulados. NINGÚN aviso (ni al CRM ni
 * al socio). No crea valoraciones, rutinas ni medidas.
 *
 * IDEMPOTENTE: una valoración ya enlazada da `already_closed` (y el índice
 * único parcial impide que cierre dos citas); solo se tocan citas abiertas y
 * sin enlace. El ensayo simula el orden: la cita que «enlazaría» una valoración
 * ya no es candidata para la siguiente.
 *
 * Informe por valoración, SIN nombres (uuids, fechas y categoría):
 *  · linked             — 1 candidata del mismo entrenador (se enlaza / se enlazaría);
 *  · ambiguous          — 2 o más candidatas;
 *  · different_trainer  — 1 candidata de otro entrenador;
 *  · already_closed     — sin candidatas: la cita del día ya estaba cerrada
 *                         (completada o «no asistió»), o la valoración ya cerró una;
 *  · cancelled          — sin candidatas: las citas de ese día están canceladas.
 * Las valoraciones sin ninguna cita ese día no salen.
 */
class LinkValuationAssessments extends Command
{
    protected $signature = 'valuations:link-assessments {--apply : Enlazar y completar (por defecto solo ensaya)} {--json : Informe en JSON}';

    protected $description = 'Concilia las valoraciones enviadas con las citas de valoración del mismo día (ensayo por defecto)';

    public const CATEGORIES = ['linked', 'ambiguous', 'different_trainer', 'already_closed', 'cancelled'];

    public function handle(ValuationAgenda $agenda): int
    {
        $aplicar = (bool) $this->option('apply');
        $filas = [];
        $simuladas = [];

        $valoraciones = ProfessionalAssessment::query()
            ->whereNull('parent_id')
            ->whereIn('status', [ProfessionalAssessment::STATUS_SUBMITTED, ProfessionalAssessment::STATUS_AMENDED])
            ->whereNotNull('submitted_at')
            ->whereIn('member_id', ValuationAppointment::query()->select('member_id'))
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->get(['id', 'uuid', 'member_id', 'trainer_id', 'status', 'submitted_at']);

        foreach ($valoraciones as $v) {
            [$desde, $hasta] = ValuationAgenda::bogotaDay($v->submitted_at);
            $delDia = ValuationAppointment::query()
                ->where('member_id', (int) $v->member_id)
                ->where('starts_at', '>=', ValuationAvailability::db($desde))
                ->where('starts_at', '<', ValuationAvailability::db($hasta))
                ->get(['id', 'status']);
            if ($delDia->isEmpty()) {
                continue;
            }

            if ($aplicar) {
                try {
                    $r = DB::transaction(fn () => $agenda->completeFromAssessment($v, ValuationAgenda::ORIGIN_RECONCILIATION));
                } catch (UniqueConstraintViolationException) {
                    // El índice único: otra pasada la enlazó mientras tanto.
                    $r = ['outcome' => ValuationAgenda::MATCH_ALREADY_LINKED, 'appointment' => null, 'candidates' => 0];
                }
            } else {
                $r = $agenda->matchAssessment($v, false, $simuladas);
                if ($r['outcome'] === ValuationAgenda::MATCH_LINKED) {
                    $simuladas[] = (int) $r['appointment']->id;
                }
            }

            $categoria = match ($r['outcome']) {
                ValuationAgenda::MATCH_LINKED => 'linked',
                ValuationAgenda::MATCH_AMBIGUOUS => 'ambiguous',
                ValuationAgenda::MATCH_DIFFERENT_TRAINER => 'different_trainer',
                ValuationAgenda::MATCH_ALREADY_LINKED => 'already_closed',
                // Sin candidatas: o la cerró alguien (o la «enlazó» este mismo
                // ensayo), o todas las de ese día están canceladas.
                default => $delDia->contains(fn ($c) => $c->status !== ValuationAppointment::STATUS_CANCELLED)
                    ? 'already_closed'
                    : 'cancelled',
            };

            $filas[] = [
                'date' => ValuationTime::local($v->submitted_at)->toDateString(),
                'assessment_uuid' => $v->uuid,
                'appointment_uuid' => $r['appointment']?->uuid,
                'candidates' => (int) $r['candidates'],
                'category' => $categoria,
                'applied' => $aplicar && $categoria === 'linked',
            ];
        }

        $recuento = array_fill_keys(self::CATEGORIES, 0);
        foreach ($filas as $f) {
            $recuento[$f['category']]++;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'mode' => $aplicar ? 'apply' : 'dry_run',
                'counts' => $recuento,
                'rows' => $filas,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line($aplicar
            ? 'MODO: APLICAR (enlaza y completa; sin avisos)'
            : 'MODO: ENSAYO (no se escribe nada; usa --apply para enlazar)');
        $this->table(['Categoría', 'Valoraciones'], collect($recuento)->map(fn ($n, $c) => [$c, $n])->values()->all());
        if ($filas !== []) {
            $this->table(
                ['Día (Bogotá)', 'Valoración', 'Cita', 'Candidatas', 'Categoría', 'Aplicada'],
                array_map(fn ($f) => [
                    $f['date'], $f['assessment_uuid'], $f['appointment_uuid'] ?? '', $f['candidates'], $f['category'], $f['applied'] ? 'sí' : 'no',
                ], $filas),
            );
        }
        $this->info($aplicar
            ? "Enlazadas y completadas: {$recuento['linked']} citas."
            : "Se enlazarían: {$recuento['linked']} citas.");

        return self::SUCCESS;
    }
}

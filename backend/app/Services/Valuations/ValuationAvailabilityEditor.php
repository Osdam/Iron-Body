<?php

namespace App\Services\Valuations;

use App\Exceptions\ValuationException;
use App\Models\ValuationAppointment;
use App\Models\ValuationAvailabilityBlock;
use App\Models\ValuationAvailabilityRule;
use App\Services\Audit\AuditTrail;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Las ESCRITURAS de la disponibilidad: reglas semanales y bloqueos.
 *
 * Las reglas se sustituyen ENTERAS (el CRM edita la semana completa y la manda
 * de una vez), validadas: sin solapes entre franjas activas del mismo día cuyas
 * vigencias se crucen. Cambiar reglas o crear un bloqueo NO toca las citas que
 * ya existen: la agenda nunca cancela por su cuenta; un bloqueo que pisa citas
 * activas exige `force` y aun así solo avisa.
 *
 * Ambas escrituras bloquean la fila del entrenador, la misma que toma crear una
 * cita: un bloqueo y una reserva a la vez sobre la misma hora se ordenan.
 */
final class ValuationAvailabilityEditor
{
    public function __construct(
        private readonly ValuationAgenda $agenda,
        private readonly AuditTrail $audit,
    ) {}

    /** @return list<array<string,mixed>> */
    public function rules(int $trainerId): array
    {
        return ValuationAvailabilityRule::query()
            ->where('trainer_id', $trainerId)
            ->orderBy('weekday')
            ->orderBy('start_time')
            ->orderBy('id')
            ->get()
            ->map(fn (ValuationAvailabilityRule $r) => self::presentRule($r))
            ->all();
    }

    /** @return array<string,mixed> */
    public static function presentRule(ValuationAvailabilityRule $r): array
    {
        return [
            'id' => (int) $r->id,
            'weekday' => (int) $r->weekday,
            'start_time' => $r->startHm(),
            'end_time' => $r->endHm(),
            'valid_from' => $r->validFromDate(),
            'valid_until' => $r->validUntilDate(),
            'active' => (bool) $r->active,
        ];
    }

    /**
     * @param  list<array{weekday: int, start_time: string, end_time: string, valid_from?: string|null, valid_until?: string|null, active?: bool|null}>  $reglas  ya validadas en forma
     * @return list<array<string,mixed>>
     */
    public function replaceRules(ValuationAccess $access, int $trainerId, array $reglas, Request $request): array
    {
        $normalizadas = array_map(fn (array $r) => [
            'weekday' => (int) $r['weekday'],
            'start_time' => substr((string) $r['start_time'], 0, 5),
            'end_time' => substr((string) $r['end_time'], 0, 5),
            'valid_from' => $r['valid_from'] ?? null,
            'valid_until' => $r['valid_until'] ?? null,
            'active' => array_key_exists('active', $r) && $r['active'] !== null ? (bool) $r['active'] : true,
        ], $reglas);

        $this->exigirSinSolapes($normalizadas);

        DB::transaction(function () use ($access, $trainerId, $normalizadas, $request): void {
            $this->agenda->withTrainerAndMemberLocks([$trainerId], [], fn () => null);

            $antes = ValuationAvailabilityRule::query()->where('trainer_id', $trainerId)->count();
            ValuationAvailabilityRule::query()->where('trainer_id', $trainerId)->delete();

            foreach ($normalizadas as $r) {
                ValuationAvailabilityRule::create(array_merge($r, [
                    'trainer_id' => $trainerId,
                    'created_by_admin_id' => $access->actor->id,
                ]));
            }

            $this->audit->record($request, [
                'action' => 'update', 'module' => 'Valoraciones', 'entity' => 'disponibilidad_entrenador',
                'entity_id' => $trainerId, 'summary' => 'Cambió la disponibilidad semanal de valoraciones',
                'metadata' => [
                    'rules_before' => $antes,
                    'rules_after' => count($normalizadas),
                    'rules' => $normalizadas,
                ],
            ]);
        });

        return $this->rules($trainerId);
    }

    /** @return list<array<string,mixed>> */
    public function blocks(int $trainerId, Carbon $desde, Carbon $hasta): array
    {
        return ValuationAvailabilityBlock::query()
            ->where('trainer_id', $trainerId)
            ->where('starts_at', '<', ValuationAvailability::db($hasta))
            ->where('ends_at', '>', ValuationAvailability::db($desde))
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->map(fn (ValuationAvailabilityBlock $b) => self::presentBlock($b))
            ->all();
    }

    /** @return array<string,mixed> */
    public static function presentBlock(ValuationAvailabilityBlock $b): array
    {
        return [
            'id' => (int) $b->id,
            'trainer_id' => (int) $b->trainer_id,
            'starts_at' => $b->starts_at->copy()->utc()->toIso8601String(),
            'ends_at' => $b->ends_at->copy()->utc()->toIso8601String(),
            'local_date' => ValuationTime::local($b->starts_at)->toDateString(),
            'local_start' => ValuationTime::hm($b->starts_at),
            'local_end_date' => ValuationTime::local($b->ends_at)->toDateString(),
            'local_end' => ValuationTime::hm($b->ends_at),
            'kind' => $b->kind,
            'kind_label' => ValuationAvailabilityBlock::KIND_LABELS[$b->kind] ?? $b->kind,
            'reason' => $b->reason,
        ];
    }

    /**
     * @param  array{starts_at: string, ends_at: string, kind: string, reason?: string|null, force?: bool|null}  $datos
     * @return array{block: ValuationAvailabilityBlock, overlapping: list<array<string,mixed>>}
     */
    public function createBlock(ValuationAccess $access, int $trainerId, array $datos, Request $request): array
    {
        $inicio = ValuationTime::parseInstant((string) $datos['starts_at'], 'starts_at');
        $fin = ValuationTime::parseInstant((string) $datos['ends_at'], 'ends_at');
        if (! $fin->gt($inicio)) {
            throw new ValuationException('El bloqueo tiene que terminar después de empezar.', 'validation_failed', 422, [
                'errors' => ['ends_at' => ['Debe ser posterior al inicio.']],
            ]);
        }
        if ($inicio->diffInDays($fin) > 366) {
            throw new ValuationException('Un bloqueo no puede durar más de un año.', 'validation_failed', 422, [
                'errors' => ['ends_at' => ['Un bloqueo no puede durar más de un año.']],
            ]);
        }
        $forzar = (bool) ($datos['force'] ?? false);

        return DB::transaction(function () use ($access, $trainerId, $datos, $request, $inicio, $fin, $forzar): array {
            $this->agenda->withTrainerAndMemberLocks([$trainerId], [], fn () => null);

            $pisadas = $this->appointmentsUnder($trainerId, $inicio, $fin);
            if ($pisadas !== [] && ! $forzar) {
                throw ValuationException::blockOverlapsAppointments($pisadas);
            }

            $bloqueo = ValuationAvailabilityBlock::create([
                'trainer_id' => $trainerId,
                'starts_at' => $inicio->copy()->utc(),
                'ends_at' => $fin->copy()->utc(),
                'kind' => $datos['kind'],
                'reason' => isset($datos['reason']) && trim((string) $datos['reason']) !== '' ? trim((string) $datos['reason']) : null,
                'created_by_admin_id' => $access->actor->id,
            ]);

            $this->audit->record($request, [
                'action' => 'create', 'module' => 'Valoraciones', 'entity' => 'bloqueo_entrenador',
                'entity_id' => $bloqueo->id,
                'summary' => $pisadas === [] ? 'Bloqueó la agenda de valoraciones' : 'Bloqueó la agenda de valoraciones sobre citas activas (no se cancelan)',
                'metadata' => [
                    'trainer_id' => $trainerId, 'kind' => $bloqueo->kind,
                    'starts_at' => $bloqueo->starts_at->copy()->utc()->toIso8601String(),
                    'ends_at' => $bloqueo->ends_at->copy()->utc()->toIso8601String(),
                    'overlapping_appointments' => array_column($pisadas, 'uuid'),
                ],
            ]);

            return ['block' => $bloqueo, 'overlapping' => $pisadas];
        });
    }

    public function deleteBlock(int $trainerId, int $blockId, Request $request): void
    {
        DB::transaction(function () use ($trainerId, $blockId, $request): void {
            $this->agenda->withTrainerAndMemberLocks([$trainerId], [], fn () => null);

            $bloqueo = ValuationAvailabilityBlock::query()
                ->where('trainer_id', $trainerId)
                ->whereKey($blockId)
                ->first();
            if ($bloqueo === null) {
                throw ValuationException::notFound('El bloqueo');
            }

            $resumen = self::presentBlock($bloqueo);
            $bloqueo->delete();

            $this->audit->record($request, [
                'action' => 'delete', 'module' => 'Valoraciones', 'entity' => 'bloqueo_entrenador',
                'entity_id' => $blockId, 'summary' => 'Quitó un bloqueo de la agenda de valoraciones',
                'metadata' => [
                    'trainer_id' => $trainerId, 'kind' => $resumen['kind'],
                    'starts_at' => $resumen['starts_at'], 'ends_at' => $resumen['ends_at'],
                ],
            ]);
        });
    }

    /**
     * Citas activas que pisa un intervalo: uuid y horas, SIN nombres.
     *
     * @return list<array<string,mixed>>
     */
    private function appointmentsUnder(int $trainerId, Carbon $inicio, Carbon $fin): array
    {
        return ValuationAppointment::query()
            ->where('trainer_id', $trainerId)
            ->whereIn('status', ValuationAppointment::ACTIVE_STATUSES)
            ->where('starts_at', '<', ValuationAvailability::db($fin))
            ->where('ends_at', '>', ValuationAvailability::db($inicio))
            ->orderBy('starts_at')
            ->get(['uuid', 'starts_at', 'ends_at', 'status'])
            ->map(fn (ValuationAppointment $a) => [
                'uuid' => $a->uuid,
                'starts_at' => $a->starts_at->copy()->utc()->toIso8601String(),
                'local_date' => ValuationTime::local($a->starts_at)->toDateString(),
                'local_start' => ValuationTime::hm($a->starts_at),
                'local_end' => ValuationTime::hm($a->ends_at),
                'status' => $a->status,
            ])
            ->all();
    }

    /**
     * Franjas activas del mismo día cuyas vigencias se cruzan no pueden
     * solaparse. Los bordes que se tocan (12:00–12:00) no son solape.
     *
     * @param  list<array{weekday: int, start_time: string, end_time: string, valid_from: ?string, valid_until: ?string, active: bool}>  $reglas
     *
     * @throws ValuationException 422 rules_overlap
     */
    private function exigirSinSolapes(array $reglas): void
    {
        $errores = [];
        $n = count($reglas);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $reglas[$i];
                $b = $reglas[$j];
                if (! $a['active'] || ! $b['active'] || $a['weekday'] !== $b['weekday']) {
                    continue;
                }
                $vigenciasSeCruzan = ($a['valid_from'] === null || $b['valid_until'] === null || $a['valid_from'] <= $b['valid_until'])
                    && ($b['valid_from'] === null || $a['valid_until'] === null || $b['valid_from'] <= $a['valid_until']);
                if ($vigenciasSeCruzan && $a['start_time'] < $b['end_time'] && $b['start_time'] < $a['end_time']) {
                    $errores["rules.{$j}"][] = "Se solapa con la franja {$a['start_time']}–{$a['end_time']} del mismo día (regla ".($i + 1).').';
                }
            }
        }

        if ($errores !== []) {
            throw ValuationException::rulesOverlap($errores);
        }
    }

    /** @return Collection<int, ValuationAvailabilityBlock> */
    public function upcomingBlocks(int $trainerId, int $dias = 60): Collection
    {
        $desde = Carbon::now()->utc();

        return ValuationAvailabilityBlock::query()
            ->where('trainer_id', $trainerId)
            ->where('ends_at', '>', ValuationAvailability::db($desde))
            ->where('starts_at', '<', ValuationAvailability::db($desde->copy()->addDays($dias)))
            ->orderBy('starts_at')
            ->get();
    }
}

<?php

namespace App\Services\Trainer\Recovery;

use App\Exceptions\IntegralPlanException;
use App\Models\IronIaRecoveryItem;
use App\Models\Member;
use App\Models\MemberTrainerAssignment;
use App\Models\NutritionAiRun;
use App\Models\Routine;
use App\Models\Trainer;
use App\Services\Trainer\IntegralPlanGenerationService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Recuperación de rutinas de Iron IA del flujo antiguo (docs/iron-ia/RECUPERACION-RUTINAS.md).
 *
 * EL PROBLEMA. Hasta la app 2.0.9 lo que el entrenador escribía en «Contexto
 * para el plan» no llegaba al backend: los días salían del modelo o de un
 * supuesto. El formulario nunca se guardó, así que NO hay forma de saber qué
 * disponibilidad pidió el entrenador: ninguna propuesta puede publicarse sola.
 *
 * QUÉ HACE. Para cada rutina afectada que el socio sigue usando, deja una
 * PROPUESTA en borrador (invisible para el socio) con el reparto muscular de
 * 6 días, marcada en el nombre como «por confirmar». El entrenador la revisa en
 * su portal, la ajusta (quitar días recalcula la frecuencia) y la publica con
 * el botón de siempre; al publicarla, la original se retira sin borrarse
 * (RoutinePublisher).
 *
 * QUÉ NO HACE. No publica nada, no toca rutinas de otros socios, no escribe en
 * el perfil ni en la meta del socio, no cambia potencias, no consume los cupos
 * del entrenador y no crea guías nutricionales.
 *
 * Idempotente y reanudable: una fila por (campaña, rutina original); la fila
 * se reclama con un UPDATE atómico, la llamada al modelo va fuera de cualquier
 * transacción, y una ejecución interrumpida adopta el borrador que dejó en vez
 * de pagar otra generación.
 */
class RoutineRecoveryCampaign
{
    /** Disponibilidad PROPUESTA (sin confirmar) de la campaña aprobada el 13-11-2026. */
    public const PROPOSED_DAYS = 6;

    public const MAX_ATTEMPTS = 3;

    /** Una fila «generating» más vieja que esto quedó de una ejecución interrumpida. */
    public const STALE_MINUTES = 15;

    /** El entrenador ve la propuesta por el nombre: el portal no enseña la descripción. */
    public const NAME_PREFIX = 'Propuesta: %d días por confirmar · ';

    private const BUSINESS_TZ = 'America/Bogota';

    /** Holgura entre la corrida y la rutina que creó (se guarda dentro de la corrida). */
    private const RUN_SLACK_SECONDS = 5;

    public function __construct(private readonly IntegralPlanGenerationService $generator) {}

    /**
     * Evalúa TODAS las rutinas de Iron IA: cuáles entran en la campaña y por qué
     * no las demás. No escribe nada.
     *
     * @param  string  $cutoffDate  último día (Bogotá) de generaciones que se tratan, Y-m-d
     * @return list<array{routine_id: int, member_id: ?int, trainer_id: ?int, days: ?int, power: ?int, eligible: bool, reason: string}>
     */
    public function evaluate(string $campaign, string $cutoffDate): array
    {
        $out = [];
        $rutinas = Routine::query()
            ->where('generated_by_ai', true)
            ->where(fn ($q) => $q->where('is_template', false)->orWhereNull('is_template'))
            ->orderBy('id')
            ->get();

        foreach ($rutinas as $r) {
            $out[] = $this->decide($r, $campaign, $cutoffDate);
        }

        // Un socio, una propuesta: si tuviera dos rutinas candidatas, solo la más reciente.
        $ultima = [];
        foreach ($out as $i => $d) {
            if ($d['eligible']) {
                $ultima[$d['member_id']] = max($ultima[$d['member_id']] ?? 0, $d['routine_id']);
            }
        }
        foreach ($out as $i => $d) {
            if ($d['eligible'] && $ultima[$d['member_id']] !== $d['routine_id']) {
                $out[$i]['eligible'] = false;
                $out[$i]['reason'] = 'older_affected_routine';
            }
        }

        return $out;
    }

    /**
     * Procesa UNA rutina original: comprueba de nuevo que sigue siendo candidata,
     * reclama su fila y deja la propuesta en borrador.
     *
     * @return array{routine_id: int, outcome: string, reason?: string, proposal_routine_id?: int, error_code?: string}
     */
    public function process(string $campaign, int $originalRoutineId, string $cutoffDate, int $maxTokens = 0): array
    {
        $original = Routine::find($originalRoutineId);
        if (! $original instanceof Routine) {
            return ['routine_id' => $originalRoutineId, 'outcome' => 'skipped', 'reason' => 'routine_missing'];
        }

        $existente = IronIaRecoveryItem::where('campaign', $campaign)->where('original_routine_id', $originalRoutineId)->first();
        if ($existente !== null && in_array($existente->status, [IronIaRecoveryItem::STATUS_PROPOSED, IronIaRecoveryItem::STATUS_PUBLISHED, IronIaRecoveryItem::STATUS_DISCARDED], true)) {
            return ['routine_id' => $originalRoutineId, 'outcome' => 'already_done', 'reason' => $existente->status];
        }

        // La elegibilidad se comprueba AHORA, no la de la simulación: entre una y
        // otra el entrenador puede haber publicado algo o el socio haber vencido.
        $decision = $this->decide($original, $campaign, $cutoffDate);
        if (! $decision['eligible']) {
            return ['routine_id' => $originalRoutineId, 'outcome' => 'skipped', 'reason' => $decision['reason']];
        }

        // El tope de la campaña se mira AQUÍ, justo antes de gastar: así vale
        // también cuando cada propuesta va a una cola (--queue).
        if ($maxTokens > 0 && $this->tokensSpent($campaign) >= $maxTokens) {
            return ['routine_id' => $originalRoutineId, 'outcome' => 'skipped', 'reason' => 'token_cap'];
        }

        $item = $existente ?? $this->createItem($campaign, $original, $decision);

        // Una ejecución anterior se cortó después de guardar el borrador: se adopta.
        if ($item->status === IronIaRecoveryItem::STATUS_GENERATING
            && $item->claimed_at !== null
            && $item->claimed_at->lt(now()->subMinutes(self::STALE_MINUTES))
            && ($adoptado = $this->orphanProposal($campaign, $item)) !== null) {
            $this->markProposed($item, $adoptado['routine'], $adoptado['run']);

            return ['routine_id' => $originalRoutineId, 'outcome' => 'adopted', 'proposal_routine_id' => $adoptado['routine']->id];
        }

        // Reclamo atómico: dos ejecuciones a la vez no generan dos propuestas.
        $reclamada = IronIaRecoveryItem::query()
            ->whereKey($item->getKey())
            ->where(function ($q) {
                $q->where('status', IronIaRecoveryItem::STATUS_PENDING)
                    ->orWhere(fn ($q) => $q->where('status', IronIaRecoveryItem::STATUS_FAILED)->where('attempts', '<', self::MAX_ATTEMPTS))
                    ->orWhere(fn ($q) => $q->where('status', IronIaRecoveryItem::STATUS_GENERATING)->where('attempts', '<', self::MAX_ATTEMPTS)->where('claimed_at', '<', now()->subMinutes(self::STALE_MINUTES)));
            })
            ->update([
                'status' => IronIaRecoveryItem::STATUS_GENERATING,
                'claimed_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
                'trainer_id' => $decision['trainer_id'],
                'updated_at' => now(),
            ]);
        // El reclamo se hizo en la base: la fila en memoria se relee. Sin esto,
        // un reintento que vuelve a fallar no escribe «failed» (Eloquent no ve
        // cambio frente al «failed» que tenía en memoria) y la fila se queda
        // en «generating» (lo detectó la prueba de reintentos).
        $item->refresh();
        if ($reclamada === 0) {
            return ['routine_id' => $originalRoutineId, 'outcome' => 'not_claimed', 'reason' => $item->status.($item->status === IronIaRecoveryItem::STATUS_FAILED ? ' (sin intentos)' : '')];
        }

        $trainer = Trainer::find($decision['trainer_id']);
        $member = Member::find($decision['member_id']);

        try {
            // FUERA de cualquier transacción: la llamada al modelo tarda segundos.
            $res = $this->generator->generateRecoveryProposal($trainer, $member, $campaign, self::PROPOSED_DAYS);
        } catch (IntegralPlanException $e) {
            $this->markFailed($item, $e->code_, $e->getMessage());

            return ['routine_id' => $originalRoutineId, 'outcome' => 'failed', 'error_code' => $e->code_];
        } catch (Throwable $e) {
            $this->markFailed($item, 'exception', $e::class);
            report($e);

            return ['routine_id' => $originalRoutineId, 'outcome' => 'failed', 'error_code' => 'exception'];
        }

        $this->markProposed($item, $res['routine'], $res['run']);

        return ['routine_id' => $originalRoutineId, 'outcome' => 'proposed', 'proposal_routine_id' => $res['routine']->id];
    }

    /**
     * Tokens ya gastados por la campaña: TODAS sus corridas, también las que
     * fallaron (un intento inválido también se paga). Son las corridas de
     * recuperación de sus socios desde que la campaña empezó.
     */
    public function tokensSpent(string $campaign): int
    {
        $items = IronIaRecoveryItem::where('campaign', $campaign);
        $desde = (clone $items)->min('created_at');
        if ($desde === null) {
            return 0;
        }

        return (int) NutritionAiRun::query()
            ->where('mode', IntegralPlanGenerationService::RECOVERY_RUN_MODE)
            ->whereIn('member_id', (clone $items)->select('member_id'))
            ->where('created_at', '>=', $desde)
            ->sum('total_tokens');
    }

    /**
     * El entrenador regenera la propuesta con lo que confirmó con el socio
     * (RECUPERACION-REVISION-CONTRATO.md §3): una propuesta nueva en borrador y
     * la anterior retirada, sin borrarse. Va por la campaña: no gasta los cupos
     * del entrenador; el tope es MAX_REGENERATIONS por propuesta.
     *
     * @param  array<string,mixed>  $confirmed  days_per_week, session_minutes, training_level, objective, injuries[, observations]
     *
     * @throws IntegralPlanException
     */
    public function regenerate(IronIaRecoveryItem $item, Trainer $trainer, array $confirmed): Routine
    {
        if (! $item->isPending()) {
            throw new IntegralPlanException('Esta propuesta ya está publicada: ya no se regenera.', 'recovery_not_pending');
        }
        $member = Member::find($item->member_id);
        if (! $member instanceof Member) {
            throw new IntegralPlanException('El socio de esta propuesta ya no existe.', 'routine_without_member');
        }

        // Reserva ATÓMICA de un intento ANTES de llamar al modelo: la condición y
        // el incremento van en la misma sentencia, así que dos peticiones a la vez
        // no pasan las dos con el último intento libre, y un intento que falla
        // también cuenta (se paga igual).
        $reservado = IronIaRecoveryItem::query()
            ->whereKey($item->getKey())
            ->whereIn('status', [IronIaRecoveryItem::STATUS_PROPOSED, IronIaRecoveryItem::STATUS_DISCARDED])
            ->where('regenerations', '<', IronIaRecoveryItem::MAX_REGENERATIONS)
            ->where('regeneration_attempts', '<', IronIaRecoveryItem::MAX_REGENERATION_ATTEMPTS)
            ->update(['regeneration_attempts' => DB::raw('regeneration_attempts + 1'), 'updated_at' => now()]);
        if ($reservado === 0) {
            $item->refresh();
            if (! $item->isPending()) {
                throw new IntegralPlanException('Esta propuesta ya está publicada: ya no se regenera.', 'recovery_not_pending');
            }
            throw new IntegralPlanException(
                'Ya no quedan regeneraciones para esta propuesta. Ajústala en el editor o genera un plan nuevo con el formulario.',
                'recovery_regeneration_limit',
            );
        }

        $dias = (int) $confirmed['days_per_week'];
        // FUERA de cualquier transacción: la llamada al modelo tarda segundos.
        try {
            $res = $this->generator->generateRecoveryProposal($trainer, $member, $item->campaign, $dias, $confirmed);
        } catch (IntegralPlanException $e) {
            // Otra generación en curso para el socio: no se llamó al modelo, así
            // que el intento se devuelve (un 409 es reintentable y no se paga).
            if ($e->code_ === 'generation_in_progress') {
                IronIaRecoveryItem::query()->whereKey($item->getKey())->where('regeneration_attempts', '>', 0)
                    ->update(['regeneration_attempts' => DB::raw('regeneration_attempts - 1'), 'updated_at' => now()]);
            }
            throw $e;
        }
        $nueva = $res['routine'];
        $run = $res['run'];

        try {
            $resultado = $this->linkRegenerated($item, $nueva, $run);
        } catch (Throwable $e) {
            // La rutina nueva ya existe (la guardó la generación). Si no se pudo
            // enlazar, se retira y se marca como sustituida: un borrador suelto
            // se podría publicar por la vía normal, sin confirmación.
            $nueva->update(['status' => 'Retirada']);
            DB::transaction(function () use ($item, $nueva) {
                $fila = IronIaRecoveryItem::query()->whereKey($item->getKey())->lockForUpdate()->first();
                if ($fila !== null) {
                    $fila->forceFill(['superseded_routine_ids' => array_values(array_unique([
                        ...array_map('intval', (array) $fila->superseded_routine_ids), (int) $nueva->id,
                    ]))])->save();
                }
            });
            throw $e;
        }

        if ($resultado === null) {
            $item->refresh();
            if ($item->isPending()) {
                throw new IntegralPlanException(
                    'Otra regeneración simultánea gastó la última: la nueva queda retirada. Revisa la propuesta vigente.',
                    'recovery_regeneration_limit',
                );
            }
            throw new IntegralPlanException('Esta propuesta se publicó mientras se regeneraba: la nueva queda retirada.', 'recovery_not_pending');
        }

        return $resultado;
    }

    /**
     * Enlaza la propuesta regenerada con la fila (o, si ya no procede, la deja
     * retirada y sustituida y devuelve null). Orden de bloqueo: la fila y
     * después las rutinas, como publish y retire.
     */
    private function linkRegenerated(IronIaRecoveryItem $item, Routine $nueva, NutritionAiRun $run): ?Routine
    {
        return DB::transaction(function () use ($item, $nueva, $run) {
            $fila = IronIaRecoveryItem::query()->whereKey($item->getKey())->lockForUpdate()->firstOrFail();
            $sustituidas = array_map('intval', (array) $fila->superseded_routine_ids);
            // Pendiente y aún con regeneraciones: otra petición simultánea pudo
            // terminar antes y gastar la última.
            if (! $fila->isPending() || (int) $fila->regenerations >= IronIaRecoveryItem::MAX_REGENERATIONS) {
                // Se publicó mientras se regeneraba (o una petición simultánea gastó
                // la última): la nueva no sustituye a nada, queda retirada y marcada
                // para que nadie la publique después.
                $nueva->update(['status' => 'Retirada']);
                $fila->forceFill(['superseded_routine_ids' => array_values(array_unique([...$sustituidas, (int) $nueva->id]))])->save();

                return null;
            }

            $anterior = $fila->proposal_routine_id !== null ? Routine::find($fila->proposal_routine_id) : null;
            if ($anterior instanceof Routine && ! (bool) $anterior->is_assigned) {
                // Retirada, no borrada: queda en el historial del socio.
                $anterior->update(['status' => 'Retirada']);
            }
            if ($fila->proposal_routine_id !== null) {
                $sustituidas[] = (int) $fila->proposal_routine_id;
            }

            $base = (string) $nueva->name;
            $dias = (int) $nueva->days_per_week;
            $nueva->update(['name' => mb_substr(sprintf(self::NAME_PREFIX, $dias).$base, 0, 255)]);

            $fila->forceFill([
                'status' => IronIaRecoveryItem::STATUS_PROPOSED,
                'proposal_routine_id' => $nueva->id,
                'proposed_days' => $dias,
                'run_id' => $run->id,
                'tokens' => (int) $fila->tokens + (int) $run->total_tokens,
                'routine_power' => $run->routine_power,
                'proposal_base_name' => mb_substr($base, 0, 255),
                'regenerations' => (int) $fila->regenerations + 1,
                'superseded_routine_ids' => array_values(array_unique($sustituidas)),
                'proposed_at' => now(),
                'discarded_at' => null,
            ])->save();

            return $nueva->fresh();
        });
    }

    // ── Elegibilidad ────────────────────────────────────────────────────────

    /**
     * @return array{routine_id: int, member_id: ?int, trainer_id: ?int, days: ?int, power: ?int, eligible: bool, reason: string}
     */
    private function decide(Routine $r, string $campaign, string $cutoffDate): array
    {
        $base = [
            'routine_id' => (int) $r->id,
            'member_id' => $r->member_id !== null ? (int) $r->member_id : null,
            'trainer_id' => null,
            'days' => $r->days_per_week !== null ? (int) $r->days_per_week : null,
            'power' => null,
        ];
        $no = fn (string $motivo) => $base + ['eligible' => false, 'reason' => $motivo];

        $run = $this->runOf($r);
        if ($run === null) {
            return $no('not_from_integral_plan');
        }
        $base['power'] = $run->routine_power !== null ? (int) $run->routine_power : null;
        if (is_array($run->response_json) && array_key_exists('trainer_context', $run->response_json)) {
            return $no('corrected_generation');
        }

        $limite = Carbon::parse($cutoffDate, self::BUSINESS_TZ)->endOfDay()->utc();
        if ($r->created_at === null || $r->created_at->gt($limite)) {
            return $no('after_cutoff');
        }

        if ($r->status === 'Borrador') {
            return $no('never_published');
        }
        if (! $this->visible($r)) {
            return $no('not_visible');
        }
        if ($this->hasLaterVisibleRoutine($r)) {
            return $no('superseded');
        }
        if ($this->hasLaterCorrectedGeneration($r)) {
            return $no('corrected_draft_exists');
        }
        // Un nombre de campaña distinto (o mal escrito) no paga dos veces.
        if (IronIaRecoveryItem::query()
            ->where('original_routine_id', $r->getKey())
            ->where('campaign', '!=', $campaign)
            ->whereIn('status', [IronIaRecoveryItem::STATUS_GENERATING, IronIaRecoveryItem::STATUS_PROPOSED, IronIaRecoveryItem::STATUS_PUBLISHED])
            ->exists()) {
            return $no('in_other_campaign');
        }

        $member = $r->member_id !== null ? Member::with('user')->find($r->member_id) : null;
        if (! $member instanceof Member) {
            return $no('member_missing');
        }
        if ($member->anonymized_at !== null) {
            return $no('member_anonymized');
        }
        $hoy = Carbon::now(self::BUSINESS_TZ)->toDateString();
        $fin = $member->user?->membership_end_date;
        if ($fin === null || Carbon::parse($fin)->toDateString() < $hoy) {
            return $no('membership_expired');
        }

        $asignacion = MemberTrainerAssignment::query()
            ->where('member_id', $member->getKey())
            ->where('status', MemberTrainerAssignment::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->first();
        if ($asignacion === null) {
            return $no('no_active_trainer');
        }
        $base['trainer_id'] = (int) $asignacion->trainer_id;
        $entrenador = Trainer::find($asignacion->trainer_id);
        if (! $entrenador instanceof Trainer || ($entrenador->status ?? 'active') !== 'active') {
            return $no('trainer_inactive');
        }

        if ((int) $r->days_per_week >= self::PROPOSED_DAYS) {
            return $no('already_six_days');
        }

        return $base + ['eligible' => true, 'reason' => 'eligible'];
    }

    /** La corrida del plan integral que creó esta rutina (se guarda DURANTE la corrida). */
    private function runOf(Routine $r): ?NutritionAiRun
    {
        if ($r->member_id === null || $r->created_at === null) {
            return null;
        }

        return NutritionAiRun::query()
            ->where('mode', IntegralPlanGenerationService::RUN_MODE)
            ->where('member_id', $r->member_id)
            ->where('status', 'ok')
            ->where('created_at', '<=', $r->created_at->copy()->addSeconds(self::RUN_SLACK_SECONDS))
            ->where('updated_at', '>=', $r->created_at->copy()->subSeconds(self::RUN_SLACK_SECONDS))
            ->orderByDesc('created_at')
            ->first();
    }

    private function visible(Routine $r): bool
    {
        return (bool) $r->is_assigned || $r->assignments()->exists();
    }

    /** ¿El socio ya tiene una rutina personalizada visible posterior a esta? */
    private function hasLaterVisibleRoutine(Routine $r): bool
    {
        return Routine::query()
            ->where('member_id', $r->member_id)
            ->whereKeyNot($r->getKey())
            ->where('created_at', '>', $r->created_at)
            ->where(fn ($q) => $q->where('is_template', false)->orWhereNull('is_template'))
            ->where(fn ($q) => $q->where('is_assigned', true)->orWhereHas('assignments'))
            ->exists();
    }

    /** ¿Hay ya una generación posterior que recibió el contexto (borrador corregido)? */
    private function hasLaterCorrectedGeneration(Routine $r): bool
    {
        $posteriores = Routine::query()
            ->where('member_id', $r->member_id)
            ->whereKeyNot($r->getKey())
            ->where('generated_by_ai', true)
            ->where('created_at', '>', $r->created_at)
            ->get();
        foreach ($posteriores as $p) {
            $run = $this->runOf($p);
            if ($run !== null && is_array($run->response_json) && array_key_exists('trainer_context', $run->response_json)) {
                return true;
            }
        }

        return false;
    }

    // ── Escritura del seguimiento ───────────────────────────────────────────

    /** @param  array<string,mixed>  $decision */
    private function createItem(string $campaign, Routine $original, array $decision): IronIaRecoveryItem
    {
        try {
            return IronIaRecoveryItem::create([
                'campaign' => $campaign,
                'original_routine_id' => $original->id,
                'member_id' => $decision['member_id'],
                'trainer_id' => $decision['trainer_id'],
                'status' => IronIaRecoveryItem::STATUS_PENDING,
                'original_days' => $decision['days'],
                'proposed_days' => self::PROPOSED_DAYS,
                'routine_power' => $decision['power'],
            ]);
        } catch (UniqueConstraintViolationException) {
            // Otra ejecución la creó a la vez: la misma fila para las dos.
            return IronIaRecoveryItem::where('campaign', $campaign)->where('original_routine_id', $original->id)->firstOrFail();
        }
    }

    private function markProposed(IronIaRecoveryItem $item, Routine $rutina, NutritionAiRun $run): void
    {
        DB::transaction(function () use ($item, $rutina, $run) {
            $base = (string) $rutina->name;
            $prefijo = sprintf(self::NAME_PREFIX, self::PROPOSED_DAYS);
            if (! str_starts_with($base, $prefijo)) {
                $rutina->update(['name' => mb_substr($prefijo.$base, 0, 255)]);
            } else {
                $base = mb_substr($base, mb_strlen($prefijo));
            }

            $item->forceFill([
                'status' => IronIaRecoveryItem::STATUS_PROPOSED,
                'proposal_routine_id' => $rutina->id,
                'run_id' => $run->id,
                'tokens' => $run->total_tokens,
                'routine_power' => $run->routine_power,
                'proposal_base_name' => mb_substr($base, 0, 255),
                'proposed_at' => now(),
                'error_code' => null,
                'error_message' => null,
            ])->save();
        });
    }

    private function markFailed(IronIaRecoveryItem $item, string $code, string $message): void
    {
        $item->forceFill([
            'status' => IronIaRecoveryItem::STATUS_FAILED,
            'error_code' => mb_substr($code, 0, 60),
            'error_message' => mb_substr($message, 0, 500),
        ])->save();
    }

    /**
     * El borrador que dejó una ejecución interrumpida: una corrida OK de esta
     * campaña para este socio, posterior al reclamo, y la rutina que creó.
     *
     * @return array{routine: Routine, run: NutritionAiRun}|null
     */
    private function orphanProposal(string $campaign, IronIaRecoveryItem $item): ?array
    {
        $corridas = NutritionAiRun::query()
            ->where('mode', IntegralPlanGenerationService::RECOVERY_RUN_MODE)
            ->where('member_id', $item->member_id)
            ->where('status', 'ok')
            ->where('created_at', '>=', $item->claimed_at)
            ->orderByDesc('created_at')
            ->get();
        foreach ($corridas as $run) {
            if (($run->response_json['recovery'] ?? null) !== $campaign) {
                continue;
            }
            $rutina = Routine::query()
                ->where('member_id', $item->member_id)
                ->where('generated_by_ai', true)
                ->where('status', 'Borrador')
                ->where('created_at', '>=', $run->created_at->copy()->subSeconds(self::RUN_SLACK_SECONDS))
                ->where('created_at', '<=', $run->updated_at->copy()->addSeconds(self::RUN_SLACK_SECONDS))
                ->whereNotIn('id', IronIaRecoveryItem::query()->whereNotNull('proposal_routine_id')->select('proposal_routine_id'))
                ->orderByDesc('id')
                ->first();
            if ($rutina !== null) {
                return ['routine' => $rutina, 'run' => $run];
            }
        }

        return null;
    }
}

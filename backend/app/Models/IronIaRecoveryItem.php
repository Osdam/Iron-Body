<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Seguimiento de una rutina original en una campaña de recuperación de Iron IA
 * (docs/iron-ia/RECUPERACION-RUTINAS.md). No es un estado de la rutina: los
 * flujos normales no dependen de esta tabla.
 */
class IronIaRecoveryItem extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_GENERATING = 'generating';

    /** Hay una propuesta en borrador, invisible para el socio, esperando al entrenador. */
    public const STATUS_PROPOSED = 'proposed';

    public const STATUS_FAILED = 'failed';

    /** El entrenador la publicó: es la vigente y la original quedó retirada (sin borrarse). */
    public const STATUS_PUBLISHED = 'published';

    /** El entrenador retiró la propuesta sin publicarla. */
    public const STATUS_DISCARDED = 'discarded';

    /** Regeneraciones que puede pedir el entrenador por propuesta (las que salen bien). */
    public const MAX_REGENERATIONS = 3;

    /**
     * Llamadas al modelo por propuesta al regenerar, salgan bien o mal. Un fallo
     * no gasta una regeneración, pero sí se paga: sin este tope, repetir una
     * regeneración que falla sería gasto sin límite (revisión de seguridad, 13-11).
     */
    public const MAX_REGENERATION_ATTEMPTS = 6;

    protected $table = 'iron_ia_recovery_items';

    protected $fillable = [
        'campaign', 'original_routine_id', 'member_id', 'trainer_id', 'proposal_routine_id', 'run_id',
        'status', 'original_days', 'proposed_days', 'routine_power', 'proposal_base_name', 'attempts',
        'error_code', 'error_message', 'tokens', 'claimed_at', 'proposed_at', 'published_at',
        'published_by_trainer_id', 'original_retired_at', 'discarded_at',
    ];

    protected $casts = [
        'claimed_at' => 'datetime',
        'proposed_at' => 'datetime',
        'published_at' => 'datetime',
        'original_retired_at' => 'datetime',
        'discarded_at' => 'datetime',
        'confirmation' => 'array',
        'confirmed_at' => 'datetime',
        'regenerations' => 'integer',
        'regeneration_attempts' => 'integer',
        'superseded_routine_ids' => 'array',
        'attempts' => 'integer',
        'tokens' => 'integer',
    ];

    /** ¿Espera todavía la revisión del entrenador? (propuesta o descartada, sin publicar) */
    public function isPending(): bool
    {
        return in_array($this->status, [self::STATUS_PROPOSED, self::STATUS_DISCARDED], true);
    }

    /** La fila de la campaña de la que esta rutina es la propuesta, si lo es. */
    public static function forProposal(int $routineId): ?self
    {
        return self::query()->where('proposal_routine_id', $routineId)->first();
    }

    /**
     * La fila de la campaña cuya propuesta ANTERIOR fue esta rutina (se
     * regeneró y quedó sustituida), si lo es. Se busca por el id de la rutina
     * entre TODAS las filas con sustituidas, no por su socio actual: quitarle
     * el socio y volver a asignarla no la convierte en otra (revisión del
     * 13-11). En PHP: son pocas y así no depende del JSON de cada motor.
     */
    public static function supersededBy(int $routineId, bool $lock = false): ?self
    {
        $q = self::query()->whereNotNull('superseded_routine_ids')->orderBy('id');
        if ($lock) {
            $q->lockForUpdate();
        }

        return $q->get()->first(fn (self $i) => in_array($routineId, array_map('intval', (array) $i->superseded_routine_ids), true));
    }

    /**
     * Una propuesta de recuperación pendiente SOLO se publica desde el portal del
     * entrenador, con su confirmación (RoutinePublisher); una sustituida, nunca.
     * El CRM y la API de administración las rechazan con este mensaje.
     */
    public static function blocksDirectPublish(int $routineId): bool
    {
        if ((bool) self::forProposal($routineId)?->isPending()) {
            return true;
        }

        return self::supersededBy($routineId) !== null;
    }

    /**
     * El `recovery` de cualquier rutina para el portal: el de su propuesta,
     * `superseded` si la sustituyó otra al regenerar, o null.
     *
     * @return array<string,mixed>|null
     */
    public static function apiFor(Routine $routine): ?array
    {
        $item = self::forProposal((int) $routine->getKey());
        if ($item !== null) {
            return $item->toApi();
        }
        $item = self::supersededBy((int) $routine->getKey());

        return $item === null ? null : [
            'campaign' => $item->campaign,
            'status' => 'superseded',
            'needs_confirmation' => false,
            'proposed_days' => null,
            'original_routine_id' => $item->original_routine_id,
            'original_days' => $item->original_days,
            'regenerations_left' => 0,
            'replaced_by_routine_id' => $item->proposal_routine_id,
        ];
    }

    public const DIRECT_PUBLISH_MESSAGE = 'Esta rutina es una propuesta de recuperación de Iron IA: solo la publica el entrenador desde su portal, después de confirmar con el socio sus días, minutos, nivel, objetivo y lesiones.';

    /**
     * Lo que ve el portal del entrenador (docs/iron-ia/RECUPERACION-REVISION-CONTRATO.md §1).
     *
     * @return array<string,mixed>
     */
    public function toApi(): array
    {
        return [
            'campaign' => $this->campaign,
            'status' => $this->status,
            'needs_confirmation' => $this->isPending(),
            'proposed_days' => (int) $this->proposed_days,
            'original_routine_id' => $this->original_routine_id,
            'original_days' => $this->original_days,
            'regenerations_left' => $this->regenerationsLeft(),
        ];
    }

    /** Las que quedan: el menor de los dos topes (buenas y llamadas pagadas). */
    public function regenerationsLeft(): int
    {
        return max(0, min(
            self::MAX_REGENERATIONS - (int) $this->regenerations,
            self::MAX_REGENERATION_ATTEMPTS - (int) $this->regeneration_attempts,
        ));
    }

    public function originalRoutine(): BelongsTo
    {
        return $this->belongsTo(Routine::class, 'original_routine_id');
    }

    public function proposalRoutine(): BelongsTo
    {
        return $this->belongsTo(Routine::class, 'proposal_routine_id');
    }
}

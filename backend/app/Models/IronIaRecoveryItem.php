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
        'attempts' => 'integer',
        'tokens' => 'integer',
    ];

    public function originalRoutine(): BelongsTo
    {
        return $this->belongsTo(Routine::class, 'original_routine_id');
    }

    public function proposalRoutine(): BelongsTo
    {
        return $this->belongsTo(Routine::class, 'proposal_routine_id');
    }
}

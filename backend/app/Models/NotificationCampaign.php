<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Campaña manual lanzada desde el CRM.
 *
 * Nace SIEMPRE como borrador. El envío es una acción aparte, explícita y
 * confirmada: no hay ningún camino que cree y envíe en un solo paso, porque el
 * error caro aquí no es equivocarse de texto, es mandárselo a mil personas.
 */
class NotificationCampaign extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'name',
        'category',
        'title',
        'body',
        'action_route',
        'status',
        'audience',
        'scheduled_for',
        'started_at',
        'finished_at',
        'estimated_recipients',
        'sent_count',
        'suppressed_count',
        'failed_count',
        'created_by',
        'approved_by',
        'approved_at',
        'cancelled_by',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'audience' => 'array',
            'scheduled_for' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'estimated_recipients' => 'integer',
            'sent_count' => 'integer',
            'suppressed_count' => 'integer',
            'failed_count' => 'integer',
        ];
    }

    /** Solo un borrador puede enviarse; nada que ya salió se reenvía. */
    public function isSendable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SCHEDULED], true);
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, [self::STATUS_DRAFT, self::STATUS_SCHEDULED], true);
    }
}

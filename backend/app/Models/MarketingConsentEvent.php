<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una línea del expediente del consentimiento. Se escribe y no se toca.
 *
 * @property int $marketing_lead_id
 * @property bool $from_do_not_contact
 * @property bool $to_do_not_contact
 * @property ?string $from_consent_status
 * @property ?string $to_consent_status
 * @property string $source
 * @property string $reason
 * @property ?int $actor_admin_id
 * @property ?string $evidence
 * @property ?int $marketing_message_id
 */
class MarketingConsentEvent extends Model
{
    protected $fillable = [
        'marketing_lead_id',
        'from_do_not_contact', 'to_do_not_contact',
        'from_consent_status', 'to_consent_status',
        'source', 'reason', 'actor_admin_id', 'evidence', 'marketing_message_id',
    ];

    protected $casts = [
        'from_do_not_contact' => 'boolean',
        'to_do_not_contact' => 'boolean',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(MarketingLead::class, 'marketing_lead_id');
    }
}

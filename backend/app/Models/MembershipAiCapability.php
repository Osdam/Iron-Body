<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipAiCapability extends Model
{
    protected $fillable = [
        'membership_plan_id',
        'plan_code',
        'ai_enabled',
        'free_trial_messages',
        'monthly_messages_limit',
        'daily_messages_limit',
        'max_output_tokens',
        'context_level',
        'progress_analysis_enabled',
        'smart_recommendations_enabled',
        'weekly_summary_enabled',
        'proactive_notifications_enabled',
        'fair_use_limit',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'ai_enabled'                      => 'boolean',
            'free_trial_messages'            => 'integer',
            'monthly_messages_limit'         => 'integer',
            'daily_messages_limit'           => 'integer',
            'max_output_tokens'              => 'integer',
            'progress_analysis_enabled'      => 'boolean',
            'smart_recommendations_enabled'  => 'boolean',
            'weekly_summary_enabled'         => 'boolean',
            'proactive_notifications_enabled'=> 'boolean',
            'fair_use_limit'                 => 'integer',
            'is_active'                      => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'membership_plan_id');
    }

    /** Capacidades como arreglo plano (para serializar / mezclar con config). */
    public function toCapabilities(): array
    {
        return [
            'ai_enabled'                      => (bool) $this->ai_enabled,
            'free_trial_messages'            => (int) $this->free_trial_messages,
            'monthly_messages_limit'         => $this->monthly_messages_limit,
            'daily_messages_limit'           => $this->daily_messages_limit,
            'max_output_tokens'              => (int) $this->max_output_tokens,
            'context_level'                  => $this->context_level ?: 'basic',
            'progress_analysis_enabled'      => (bool) $this->progress_analysis_enabled,
            'smart_recommendations_enabled'  => (bool) $this->smart_recommendations_enabled,
            'weekly_summary_enabled'         => (bool) $this->weekly_summary_enabled,
            'proactive_notifications_enabled'=> (bool) $this->proactive_notifications_enabled,
            'fair_use_limit'                 => $this->fair_use_limit,
        ];
    }
}

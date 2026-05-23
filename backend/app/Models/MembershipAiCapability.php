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
        'ai_chat_enabled',
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
        'ai_voice_chat_enabled',
        'ai_realtime_voice_enabled',
        'ai_image_analysis_enabled',
        'ai_file_upload_enabled',
        'ai_audio_monthly_limit',
        'ai_image_monthly_limit',
        'ai_max_audio_seconds',
        'ai_max_image_size_mb',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'ai_enabled'                      => 'boolean',
            'ai_chat_enabled'                => 'boolean',
            'free_trial_messages'            => 'integer',
            'monthly_messages_limit'         => 'integer',
            'daily_messages_limit'           => 'integer',
            'max_output_tokens'              => 'integer',
            'progress_analysis_enabled'      => 'boolean',
            'smart_recommendations_enabled'  => 'boolean',
            'weekly_summary_enabled'         => 'boolean',
            'proactive_notifications_enabled'=> 'boolean',
            'fair_use_limit'                 => 'integer',
            'ai_voice_chat_enabled'          => 'boolean',
            'ai_realtime_voice_enabled'      => 'boolean',
            'ai_image_analysis_enabled'      => 'boolean',
            'ai_file_upload_enabled'         => 'boolean',
            'ai_audio_monthly_limit'         => 'integer',
            'ai_image_monthly_limit'         => 'integer',
            'ai_max_audio_seconds'           => 'integer',
            'ai_max_image_size_mb'           => 'integer',
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
            'ai_chat_enabled'                => $this->ai_chat_enabled === null ? true : (bool) $this->ai_chat_enabled,
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
            'ai_voice_chat_enabled'          => (bool) $this->ai_voice_chat_enabled,
            'ai_realtime_voice_enabled'      => (bool) $this->ai_realtime_voice_enabled,
            'ai_image_analysis_enabled'      => (bool) $this->ai_image_analysis_enabled,
            'ai_file_upload_enabled'         => (bool) $this->ai_file_upload_enabled,
            'ai_audio_monthly_limit'         => $this->ai_audio_monthly_limit,
            'ai_image_monthly_limit'         => $this->ai_image_monthly_limit,
            'ai_max_audio_seconds'           => (int) ($this->ai_max_audio_seconds ?: 60),
            'ai_max_image_size_mb'           => (int) ($this->ai_max_image_size_mb ?: 5),
        ];
    }
}

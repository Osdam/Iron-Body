<?php

namespace App\Models;

use App\Services\Valuations\ValuationSettings;
use Illuminate\Database\Eloquent\Model;

/**
 * Ajustes de la agenda de valoraciones: UNA fila (la crea la migración).
 *
 * Patrón de `billing_settings`: datos de negocio no sensibles que se editan
 * desde el CRM. Los rangos válidos los impone
 * {@see ValuationSettings}.
 */
class ValuationSetting extends Model
{
    public const FIELDS = [
        'default_duration_minutes', 'slot_interval_minutes', 'buffer_minutes', 'min_notice_minutes',
        'max_horizon_days', 'block_on_class_conflict', 'confirmation_enabled', 'reminder_long_enabled',
        'reminder_long_hours', 'reminder_short_enabled', 'reminder_short_minutes', 'internal_alert_enabled',
        'internal_alert_minutes', 'internal_unattended_enabled', 'member_app_enabled',
    ];

    /** Los valores del contrato, por si la fila no existiera. */
    public const DEFAULTS = [
        'default_duration_minutes' => 60,
        'slot_interval_minutes' => 30,
        'buffer_minutes' => 0,
        'min_notice_minutes' => 0,
        'max_horizon_days' => 90,
        'block_on_class_conflict' => true,
        'confirmation_enabled' => true,
        'reminder_long_enabled' => true,
        'reminder_long_hours' => 24,
        'reminder_short_enabled' => true,
        'reminder_short_minutes' => 60,
        'internal_alert_enabled' => true,
        'internal_alert_minutes' => 15,
        'internal_unattended_enabled' => true,
        'member_app_enabled' => false,
    ];

    protected $fillable = [...self::FIELDS, 'updated_by_admin_id'];

    protected function casts(): array
    {
        return [
            'default_duration_minutes' => 'integer',
            'slot_interval_minutes' => 'integer',
            'buffer_minutes' => 'integer',
            'min_notice_minutes' => 'integer',
            'max_horizon_days' => 'integer',
            'block_on_class_conflict' => 'boolean',
            'confirmation_enabled' => 'boolean',
            'reminder_long_enabled' => 'boolean',
            'reminder_long_hours' => 'integer',
            'reminder_short_enabled' => 'boolean',
            'reminder_short_minutes' => 'integer',
            'internal_alert_enabled' => 'boolean',
            'internal_alert_minutes' => 'integer',
            'internal_unattended_enabled' => 'boolean',
            'member_app_enabled' => 'boolean',
        ];
    }
}

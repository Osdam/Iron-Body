<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento de auditoría de seguridad de la cuenta de un miembro.
 */
class MemberSecurityEvent extends Model
{
    public const UPDATED_AT = null; // sólo created_at

    public const TYPE_OTP_SENT          = 'login_otp_sent';
    public const TYPE_OTP_RESENT        = 'login_otp_resent';
    public const TYPE_LOGIN_VERIFIED    = 'login_verified';
    public const TYPE_LOGIN_FAILED      = 'login_failed';
    public const TYPE_OTP_BLOCKED       = 'login_otp_blocked';
    public const TYPE_NEW_DEVICE        = 'new_device_login';
    public const TYPE_CONCURRENT        = 'concurrent_session_revoked';
    public const TYPE_CONCURRENT_BLOCKED = 'concurrent_login_blocked';
    public const TYPE_DEVICE_REVOKED    = 'device_revoked';
    public const TYPE_BIOMETRIC_UNLOCK  = 'biometric_unlock';
    public const TYPE_SUSPICIOUS        = 'suspicious_login_velocity';
    public const TYPE_LOGOUT            = 'logout';
    public const TYPE_FACE_VERIFIED     = 'face_verified';
    public const TYPE_FACE_FAILED       = 'face_failed';
    public const TYPE_DEVICE_BOUND      = 'device_bound';
    public const TYPE_DEVICE_MISMATCH   = 'device_account_mismatch';
    public const TYPE_DEVICE_RELEASED   = 'device_binding_released';
    public const TYPE_FACE_REENROLL_REQUIRED  = 'biometric_reenrollment_required';
    public const TYPE_FACE_REENROLL_REQUESTED = 'biometric_reenrollment_requested';
    public const TYPE_FACE_REENROLL_COMPLETED = 'biometric_reenrollment_completed';
    public const TYPE_FACE_LOCKED             = 'biometric_locked';

    // Acciones sensibles con 2FA (Bloque 1).
    public const TYPE_SENSITIVE_OTP_SENT      = 'sensitive_action_otp_sent';
    public const TYPE_ACCOUNT_DELETE_REQUESTED = 'account_delete_requested';
    public const TYPE_ACCOUNT_DELETED         = 'account_deleted';
    public const TYPE_DEVICE_UNBOUND          = 'device_unbound';

    // Cambio de número + soporte de acceso (Bloque 2).
    public const TYPE_PHONE_CHANGE_REQUESTED = 'phone_change_requested';
    public const TYPE_PHONE_CHANGED          = 'phone_changed';
    public const TYPE_SUPPORT_REPORT         = 'support_security_report';

    protected $fillable = [
        'member_id',
        'type',
        'description',
        'device_id',
        'device_name',
        'platform',
        'ip_address',
        'user_agent',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata'   => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}

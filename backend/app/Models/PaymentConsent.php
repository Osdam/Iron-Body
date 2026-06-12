<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Auditoría de la aceptación de los DOS consentimientos exigidos por Wompi
 * (términos/política + autorización de tratamiento de datos) en el momento de
 * un pago. Guarda los enlaces/versiones aceptados, ip y user agent. No es
 * secreto: el `acceptance_token` presigned es de un solo uso.
 */
class PaymentConsent extends Model
{
    protected $fillable = [
        'uuid', 'reference', 'payment_transaction_id', 'member_id', 'user_id',
        'acceptance_token', 'accept_personal_auth_token', 'terms_link',
        'privacy_link', 'accepted_at', 'ip', 'user_agent', 'environment',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plantilla de notificación. El texto vive en base de datos para que el CRM
 * pueda corregirlo sin desplegar, pero el catálogo base se siembra desde código
 * ({@see App\Services\Notifications\NotificationCatalog}).
 */
class NotificationTemplate extends Model
{
    protected $fillable = [
        'key',
        'category',
        'supplement_kind',
        'title',
        'body',
        'action_route',
        'version',
        'is_active',
        'is_seeded',
        'disclaimer',
        'requires_active_membership',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_active' => 'boolean',
            'is_seeded' => 'boolean',
            'requires_active_membership' => 'boolean',
        ];
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    /**
     * Plantillas que puede recibir alguien SIN membresía al día.
     *
     * No es censura: es tono. A quien no puede entrar hoy se le habla de
     * volver, no de revisar su rutina.
     */
    public function scopeForLapsedMembership($q)
    {
        return $q->where('requires_active_membership', false);
    }

    /** Texto final que verá el socio, con el aviso educativo si lo hay. */
    public function renderedBody(): string
    {
        $body = trim((string) $this->body);
        $disclaimer = trim((string) $this->disclaimer);

        return $disclaimer === '' ? $body : $body.' '.$disclaimer;
    }
}

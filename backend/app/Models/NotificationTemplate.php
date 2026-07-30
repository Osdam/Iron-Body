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
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_active' => 'boolean',
            'is_seeded' => 'boolean',
        ];
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    /** Texto final que verá el socio, con el aviso educativo si lo hay. */
    public function renderedBody(): string
    {
        $body = trim((string) $this->body);
        $disclaimer = trim((string) $this->disclaimer);

        return $disclaimer === '' ? $body : $body.' '.$disclaimer;
    }
}

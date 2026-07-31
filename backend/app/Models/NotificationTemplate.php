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
        'slots',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_active' => 'boolean',
            'is_seeded' => 'boolean',
            'requires_active_membership' => 'boolean',
            'slots' => 'array',
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

    /**
     * ¿Sirve esta plantilla para esa franja del día?
     *
     * Sin franjas declaradas vale para todas: es el caso mayoritario y el que
     * tenían las plantillas antes de que existiera la columna, así que una
     * plantilla vieja o recién creada desde el CRM sigue estando disponible en
     * vez de desaparecer en silencio.
     *
     * El filtro se hace en PHP y no en SQL a propósito: consultar dentro de un
     * JSON tiene una sintaxis distinta en PostgreSQL y en SQLite, y el catálogo
     * completo cabe de sobra en memoria.
     */
    public function servesSlot(?string $slot): bool
    {
        $slots = $this->slots;

        if ($slot === null || ! is_array($slots) || $slots === []) {
            return true;
        }

        return in_array($slot, $slots, true);
    }

    /** Texto final que verá el socio, con el aviso educativo si lo hay. */
    public function renderedBody(): string
    {
        $body = trim((string) $this->body);
        $disclaimer = trim((string) $this->disclaimer);

        return $disclaimer === '' ? $body : $body.' '.$disclaimer;
    }
}

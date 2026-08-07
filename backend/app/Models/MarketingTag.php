<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Una etiqueta del catálogo.
 *
 * La distinción que sostiene el modelo es `kind`: separa lo que alguien opina
 * de lo que el sistema sabe. Una etiqueta manual es un juicio del equipo; una
 * de atribución es evidencia de por dónde llegó una persona. Dejar que las dos
 * se editen igual convertiría la segunda en la primera, y la analítica de pauta
 * dejaría de significar nada.
 */
class MarketingTag extends Model
{
    public const CATEGORY_COMMERCIAL = 'commercial';

    public const CATEGORY_OPERATIONAL = 'operational';

    public const CATEGORY_ATTRIBUTION = 'attribution';

    /** La pone una persona. */
    public const KIND_MANUAL = 'manual';

    /** La deduce el motor comercial a partir de segmentos. */
    public const KIND_AUTOMATIC = 'automatic';

    /** La pone el sistema por un hecho operativo (revisión, control humano). */
    public const KIND_SYSTEM = 'system';

    /** Viene de la atribución. Es evidencia, no opinión. */
    public const KIND_SOURCE = 'source';

    protected $fillable = [
        'slug', 'name', 'description', 'category', 'kind',
        'color', 'locked', 'active', 'sort_order',
    ];

    protected $casts = [
        'locked' => 'boolean',
        'active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * ¿Puede una persona ponerla o quitarla a mano?
     *
     * Las de atribución no: si alguien pudiera retirar «Meta Ads» de una
     * conversación que vino de un anuncio, nadie sabría después por qué los
     * números de esa campaña no cuadran.
     */
    public function isManuallyEditable(): bool
    {
        return ! $this->locked && $this->kind !== self::KIND_SOURCE;
    }

    /**
     * Prioridad para decidir cuáles se ven en la lista de conversaciones.
     *
     * Solo caben dos por fila. Se enseña lo que cambia una decisión: primero lo
     * que exige atención, después de dónde vino la persona, y al final el resto.
     */
    public function listPriority(): int
    {
        return match (true) {
            $this->category === self::CATEGORY_OPERATIONAL => 10,
            $this->category === self::CATEGORY_ATTRIBUTION => 20,
            default => 30,
        };
    }

    /** @return array<string,mixed> */
    public function present(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'category' => $this->category,
            'kind' => $this->kind,
            'color' => $this->color,
            'locked' => $this->locked,
            'editable' => $this->isManuallyEditable(),
        ];
    }
}

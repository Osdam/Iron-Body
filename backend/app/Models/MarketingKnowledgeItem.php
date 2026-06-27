<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Ítem de la base de conocimiento comercial (Fase 3.5). Contenido editable que
 * alimenta el prompt del cerebro IA. No guarda secretos: es información pública
 * orientada a la venta (planes, políticas, tono, objeciones, faq).
 */
class MarketingKnowledgeItem extends Model
{
    /** Categorías permitidas. */
    public const CATEGORIES = [
        'business_identity', 'location', 'schedule', 'plans', 'pricing_policy',
        'payment_policy', 'membership_policy', 'invoice_policy', 'objections',
        'tone', 'restrictions', 'faq', 'human_escalation',
    ];

    protected $fillable = [
        'category', 'key', 'title', 'content', 'priority', 'is_active',
        'valid_from', 'valid_until', 'source', 'metadata',
    ];

    protected $casts = [
        'priority'    => 'integer',
        'is_active'   => 'boolean',
        'valid_from'  => 'datetime',
        'valid_until' => 'datetime',
        'metadata'    => 'array',
    ];

    /** Activos y vigentes (respeta valid_from / valid_until). */
    public function scopeActiveNow(Builder $query): Builder
    {
        $now = now();
        return $query->where('is_active', true)
            ->where(fn (Builder $q) => $q->whereNull('valid_from')->orWhere('valid_from', '<=', $now))
            ->where(fn (Builder $q) => $q->whereNull('valid_until')->orWhere('valid_until', '>=', $now))
            ->orderBy('priority')
            ->orderBy('id');
    }

    public function scopeCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }
}

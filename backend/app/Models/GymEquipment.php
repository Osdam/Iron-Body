<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Equipo/máquina física del gimnasio.
 *
 * Fuente de verdad de "qué máquinas tenemos en la instalación". IRON IA usa
 * {@see GymEquipment::aiCatalog()} para no recomendar ejercicios con equipos
 * inexistentes. Ver App\Services\GymEquipmentContextService y la ruta
 * GET /api/iron-ai/equipment-catalog.
 */
class GymEquipment extends Model
{
    use SoftDeletes;

    protected $table = 'gym_equipment';

    public const STATUSES = ['operational', 'maintenance', 'out_of_service'];

    public const CATEGORIES = [
        'strength_machine', // máquina guiada de fuerza
        'free_weights',     // peso libre (mancuernas, barras, discos, bancos)
        'cardio',           // cardiovascular (caminadora, elíptica, bici)
        'functional',       // funcional (kettlebells, TRX, cajones, bandas)
        'accessory',        // accesorios (colchonetas, rodillos, cuerdas)
        'bodyweight',       // estructuras de peso corporal (barras dominadas, paralelas)
    ];

    protected $fillable = [
        'uuid',
        'name',
        'slug',
        'category',
        'muscle_groups',
        'aliases',
        'brand',
        'model',
        'serial_number',
        'zone',
        'quantity',
        'status',
        'image_url',
        'notes',
        'is_available_for_ai',
        'acquired_at',
        'last_maintenance_at',
    ];

    protected $casts = [
        'muscle_groups'       => 'array',
        'aliases'             => 'array',
        'quantity'            => 'integer',
        'is_available_for_ai' => 'boolean',
        'acquired_at'         => 'date',
        'last_maintenance_at' => 'date',
    ];

    protected static function booted(): void
    {
        // uuid y slug se autocompletan si no llegan desde el controlador.
        static::creating(function (GymEquipment $equipment): void {
            $equipment->uuid ??= (string) Str::uuid();
            if (empty($equipment->slug) && ! empty($equipment->name)) {
                $equipment->slug = Str::slug($equipment->name);
            }
        });

        static::updating(function (GymEquipment $equipment): void {
            if ($equipment->isDirty('name') && ! $equipment->isDirty('slug')) {
                $equipment->slug = Str::slug($equipment->name);
            }
        });
    }

    /** Equipos operativos (disponibles físicamente). */
    public function scopeOperational(Builder $query): Builder
    {
        return $query->where('status', 'operational');
    }

    /** Equipos que la IA debe tener en cuenta: operativos y marcados como disponibles. */
    public function scopeForAi(Builder $query): Builder
    {
        return $query->where('status', 'operational')
            ->where('is_available_for_ai', true);
    }

    /** Representación compacta para la capa de IA (sin datos administrativos). */
    public function toAiReference(): array
    {
        return [
            'name'          => $this->name,
            'slug'          => $this->slug,
            'category'      => $this->category,
            'muscle_groups' => $this->muscle_groups ?? [],
            'aliases'       => $this->aliases ?? [],
            'zone'          => $this->zone,
            'quantity'      => $this->quantity,
        ];
    }

    /**
     * Catálogo compacto y agrupado de equipos disponibles para la IA.
     *
     * Forma estable (contrato con la capa de IA):
     * [
     *   'generated_at' => ISO8601,
     *   'total'        => int,
     *   'names'        => ["Press de banca", ...],          // lista plana, útil para validación rápida
     *   'by_category'  => ['cardio' => [ {name, aliases, muscle_groups, ...}, ... ], ...],
     *   'items'        => [ {name, slug, category, aliases, muscle_groups, zone, quantity}, ... ],
     * ]
     */
    public static function aiCatalog(): array
    {
        $items = static::query()->forAi()->orderBy('category')->orderBy('name')->get();

        $byCategory = [];
        foreach ($items as $item) {
            $byCategory[$item->category][] = $item->toAiReference();
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'total'        => $items->count(),
            'names'        => $items->pluck('name')->values()->all(),
            'by_category'  => $byCategory,
            'items'        => $items->map(fn (GymEquipment $e) => $e->toAiReference())->all(),
        ];
    }
}

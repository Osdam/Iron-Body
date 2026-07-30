<?php

namespace App\Models;

use App\Support\Notifications\NotificationCategory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

/**
 * Lo que un socio quiere recibir, y cuándo.
 *
 * Funciona SIN fila en la tabla: `forMember()` devuelve una instancia con los
 * valores por defecto. Eso permite desplegar esto sobre los socios que ya
 * existen sin rellenarles nada y sin cambiarles el comportamiento actual.
 */
class MemberNotificationPreference extends Model
{
    protected $fillable = [
        'member_id',
        'timezone',
        'quiet_hours_enabled',
        'quiet_hours_start',
        'quiet_hours_end',
        'categories',
        'supplement_kinds',
        'max_per_day',
        'max_wellness_per_week',
        'opted_out_at',
    ];

    protected function casts(): array
    {
        return [
            'quiet_hours_enabled' => 'boolean',
            'quiet_hours_start' => 'integer',
            'quiet_hours_end' => 'integer',
            'categories' => 'array',
            'supplement_kinds' => 'array',
            'max_per_day' => 'integer',
            'max_wellness_per_week' => 'integer',
            'opted_out_at' => 'datetime',
        ];
    }

    /**
     * Categorías que nacen APAGADAS y exigen que el socio las encienda.
     *
     * Suplementos porque rozan la salud: no podemos saber quién está embarazada,
     * toma medicación o es menor, y el sistema tiene prohibido inferirlo — así
     * que la única forma segura de acertar es que lo pida quien lo quiere.
     * Promociones porque el consentimiento comercial va aparte del operativo.
     *
     * Lo demás (pagos, clases, rutinas…) nace encendido: ya funcionaba así y
     * apagárselo de golpe a los socios reales sería una regresión, no una mejora.
     */
    public const OFF_BY_DEFAULT = [
        NotificationCategory::SUPPLEMENTS,
        NotificationCategory::PROMOTIONS,
    ];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    /** Preferencias del socio, o los valores por defecto si nunca las tocó. */
    public static function forMember(int $memberId): self
    {
        return static::query()->firstWhere('member_id', $memberId)
            ?? new self(['member_id' => $memberId]);
    }

    public function timezoneName(): string
    {
        return $this->timezone ?: 'America/Bogota';
    }

    public function dailyLimit(): int
    {
        return $this->max_per_day ?? 4;
    }

    public function weeklyWellnessLimit(): int
    {
        return $this->max_wellness_per_week ?? 3;
    }

    /** ¿Quiere el socio esta categoría? */
    public function allows(string $category): bool
    {
        // Lo obligatorio (seguridad de la cuenta) no se negocia, ni siquiera con
        // el interruptor general: si alguien entra en tu cuenta, te enteras.
        if (NotificationCategory::isMandatory($category)) {
            return true;
        }

        if ($this->opted_out_at !== null) {
            return false;
        }

        $map = $this->categories ?? [];
        if (array_key_exists($category, $map)) {
            return (bool) $map[$category];
        }

        return ! in_array($category, self::OFF_BY_DEFAULT, true);
    }

    /**
     * ¿Quiere este suplemento en concreto?
     *
     * Exige las DOS cosas: la categoría encendida y el subtipo encendido. Quien
     * apaga "Suplementos" no debería seguir recibiendo creatina porque el
     * subtipo quedara marcado de antes.
     */
    public function allowsSupplement(string $kind): bool
    {
        if (! $this->allows(NotificationCategory::SUPPLEMENTS)) {
            return false;
        }

        $map = $this->supplement_kinds ?? [];

        // Sin elección explícita, encender "Suplementos" vale para todos.
        return (bool) ($map[$kind] ?? true);
    }

    /**
     * ¿Está el socio en horas de silencio AHORA (en SU hora local)?
     *
     * Soporta el tramo que cruza la medianoche (21:00 → 07:00), que es el caso
     * normal y el que se rompe si se compara con un simple `between`.
     */
    public function inQuietHours(?CarbonImmutable $now = null): bool
    {
        if (! ($this->quiet_hours_enabled ?? true)) {
            return false;
        }

        $start = $this->quiet_hours_start ?? 21;
        $end = $this->quiet_hours_end ?? 7;
        if ($start === $end) {
            return false;
        }

        try {
            $local = ($now ?? CarbonImmutable::now())->setTimezone($this->timezoneName());
        } catch (Throwable) {
            // Zona horaria corrupta: no es motivo para tragarse el aviso.
            return false;
        }

        $hour = (int) $local->format('G');

        return $start < $end
            ? ($hour >= $start && $hour < $end)
            : ($hour >= $start || $hour < $end);
    }

    /** Momento siguiente en que se puede enviar sin molestar. */
    public function nextAllowedAt(?CarbonImmutable $now = null): CarbonImmutable
    {
        $now ??= CarbonImmutable::now();
        if (! $this->inQuietHours($now)) {
            return $now;
        }

        try {
            $local = $now->setTimezone($this->timezoneName());
        } catch (Throwable) {
            return $now;
        }

        $end = $this->quiet_hours_end ?? 7;
        $target = $local->setTime($end, 0);
        if ($target <= $local) {
            $target = $target->addDay();
        }

        return $target->setTimezone($now->getTimezone());
    }

    /** Estado completo para la app y el CRM. */
    public function toStateArray(): array
    {
        $categories = [];
        foreach (NotificationCategory::ALL as $category) {
            $categories[$category] = $this->allows($category);
        }

        $supplements = [];
        foreach (NotificationCategory::SUPPLEMENT_KINDS as $kind) {
            $supplements[$kind] = $this->allowsSupplement($kind);
        }

        return [
            'timezone' => $this->timezoneName(),
            'quiet_hours_enabled' => (bool) ($this->quiet_hours_enabled ?? true),
            'quiet_hours_start' => $this->quiet_hours_start ?? 21,
            'quiet_hours_end' => $this->quiet_hours_end ?? 7,
            'max_per_day' => $this->dailyLimit(),
            'max_wellness_per_week' => $this->weeklyWellnessLimit(),
            'opted_out' => $this->opted_out_at !== null,
            'categories' => $categories,
            'supplement_kinds' => $supplements,
            'mandatory' => NotificationCategory::MANDATORY,
        ];
    }
}

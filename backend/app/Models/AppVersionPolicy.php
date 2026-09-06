<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Qué versión de la app puede seguir usándose, por plataforma.
 *
 * La decisión la toma el SERVIDOR, no el cliente: la app manda su build y
 * recibe un veredicto. Si la comparación viviera en el cliente, cualquiera
 * podría seguir usando una versión retirada sin más que no actualizar.
 */
class AppVersionPolicy extends Model
{
    public const ANDROID = 'android';

    public const IOS = 'ios';

    /** La app está al día. */
    public const CURRENT = 'current';

    /** Hay una más nueva, pero la suya sigue sirviendo. */
    public const OPTIONAL = 'optional_update';

    /** Su versión ya no es compatible. */
    public const REQUIRED = 'required_update';

    protected $fillable = [
        'platform', 'latest_build', 'latest_version',
        'minimum_build', 'store_url', 'message', 'updated_by_admin_id',
    ];

    protected $casts = [
        'latest_build' => 'integer',
        'minimum_build' => 'integer',
    ];

    public static function platforms(): array
    {
        return [self::ANDROID, self::IOS];
    }

    public static function forPlatform(string $platform): ?self
    {
        return static::query()->where('platform', strtolower(trim($platform)))->first();
    }

    /**
     * Mínimo EFECTIVO. Cero significa que no se exige nada.
     *
     * Exigir un build que la tienda todavía no ofrece deja al socio sin salida:
     * app bloqueada y botón de actualizar hacia una versión que no existe. Es
     * el error más caro de este sistema.
     *
     * Ante esa configuración imposible NO se exige nada. Limitar el mínimo al
     * último publicado parecía la corrección obvia, pero convierte un dedazo
     * —minimum 99 en vez de 9— en la actualización forzosa de toda la base. Un
     * ajuste que nadie pudo teclear conscientemente no debe bloquear a nadie:
     * quien lo escribió mal lo verá en el log y lo corregirá, y mientras tanto
     * lo único que se pierde es un aviso, no el acceso a la app.
     */
    public function effectiveMinimumBuild(): int
    {
        return $this->isMisconfigured() ? 0 : (int) $this->minimum_build;
    }

    /** ¿La configuración pide algo imposible? Se corrige, pero conviene verlo. */
    public function isMisconfigured(): bool
    {
        return (int) $this->minimum_build > (int) $this->latest_build;
    }

    /**
     * Veredicto para un build concreto.
     *
     * La obligatoriedad la decide `minimum_build`, no un booleano aparte: un
     * flag y un número pueden contradecirse, y entonces hay que elegir a cuál
     * creer. Con un solo criterio no hay ambigüedad posible.
     */
    public function statusFor(int $build): string
    {
        if ($build >= (int) $this->latest_build) {
            return self::CURRENT;
        }

        return $build >= $this->effectiveMinimumBuild()
            ? self::OPTIONAL
            : self::REQUIRED;
    }

    /** Respuesta pública para la app. Sin ids internos ni datos de admin. */
    public function toAppArray(int $build): array
    {
        return [
            'platform' => $this->platform,
            'status' => $this->statusFor($build),
            'current_build' => $build,
            'latest_build' => (int) $this->latest_build,
            'latest_version' => $this->latest_version,
            'minimum_build' => $this->effectiveMinimumBuild(),
            'store_url' => $this->store_url,
            'message' => $this->message,
        ];
    }
}

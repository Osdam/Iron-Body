<?php

namespace App\Services\Outreach\Run\Classifier;

use App\Services\Outreach\Core\RelativeTime;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AI9 · LA RACHA DE FALLOS DEL PROVEEDOR del clasificador (C4 de security):
 * que un fallo sostenido se VEA. Sin esto, con la clave revocada, la cuota
 * agotada, el límite de peticiones, el proveedor caído o un modelo que no
 * cumple el esquema, el clasificador falla cerrado (las respuestas quedan
 * como hoy) y nadie se entera de que los frenos de la IA no están.
 *
 * Cuenta las llamadas SEGUIDAS sin una salida que se pueda usar (cualquier
 * clase de {@see ReplyClassifierTransport::ERRORS}, o una salida fuera del
 * esquema) y guarda la última clase y cuándo. Una salida que se puede leer
 * (también `none` o una confianza baja: el proveedor contestó) la vuelve a 0
 * y conserva la última clase. Lo que no llega a llamar (apagado, sin cupo,
 * sin texto) no la toca. Solo códigos y cifras: nunca el texto, la persona
 * ni el trabajo. La leen el estado (`classifierSetup.providerFailures`) y el
 * monitor, que abre un incidente sin freno a partir de su umbral.
 *
 * Vive en la CACHÉ de la aplicación (la `database` en producción, como los
 * contadores de `OtpCostGuard`) y no en una tabla: es un aviso, no una
 * garantía. Si la caché se vacía, la racha vuelve a 0 y el aviso tarda otras
 * tantas llamadas en volver; dos pasadas a la vez pueden perder una suma
 * (leer y escribir no es atómico) y el aviso llega una llamada más tarde.
 * Nada de eso cambia lo que protege: el fallo ya es cerrado. NUNCA lanza.
 */
final class ReplyClassifierHealth
{
    public const CACHE_KEY = 'outreach:classifier:provider_failures';

    /** Una racha sin llamadas en una semana caduca: lo que diría ya no es de ahora. */
    public const TTL_SECONDS = 7 * 86400;

    /** La clase de un fallo que el puerto no nombra. Solo se guardan las de {@see ReplyClassifierTransport::ERRORS} y esta. */
    public const UNKNOWN = 'unknown';

    /** Una llamada sin salida usable: suma uno a la racha, con su clase. */
    public function failed(string $class, DateTimeInterface $now): void
    {
        $clase = in_array($class, ReplyClassifierTransport::ERRORS, true) ? $class : self::UNKNOWN;
        $this->write(static fn (array $r): array => ['consecutive' => $r['consecutive'] + 1, 'lastClass' => $clase, 'lastAt' => RelativeTime::format($now)]);
    }

    /** Una salida que se puede leer: la racha vuelve a 0 (la última clase y su instante se conservan). */
    public function answered(): void
    {
        $this->write(static fn (array $r): ?array => $r['consecutive'] === 0 ? null : ['consecutive' => 0] + $r);
    }

    /** @return array{consecutive: int, lastClass: string|null, lastAt: string|null} */
    public function read(): array
    {
        try {
            return self::shape(Cache::get(self::CACHE_KEY));
        } catch (Throwable $e) {
            // Sin la caché no se sabe la racha: se dice 0 (el aviso no sale) y queda en el registro; la pasada sigue.
            Log::warning('outreach.classifier.health_failed', ['error_class' => $e::class]);

            return self::shape(null);
        }
    }

    /** @param  callable(array{consecutive: int, lastClass: string|null, lastAt: string|null}): ?array<string, mixed>  $change */
    private function write(callable $change): void
    {
        try {
            $nuevo = $change(self::shape(Cache::get(self::CACHE_KEY)));
            if ($nuevo !== null) {
                Cache::put(self::CACHE_KEY, self::shape($nuevo), self::TTL_SECONDS);
            }
        } catch (Throwable $e) {
            // La racha es un aviso: si la caché falla, la clasificación sigue igual (nunca lanza) y queda en el registro.
            Log::warning('outreach.classifier.health_failed', ['error_class' => $e::class]);
        }
    }

    /** @return array{consecutive: int, lastClass: string|null, lastAt: string|null} */
    private static function shape(mixed $v): array
    {
        $v = is_array($v) ? $v : [];
        $clase = $v['lastClass'] ?? null;
        $cuando = $v['lastAt'] ?? null;

        return [
            'consecutive' => is_int($v['consecutive'] ?? null) ? max(0, $v['consecutive']) : 0,
            'lastClass' => is_string($clase) && ($clase === self::UNKNOWN || in_array($clase, ReplyClassifierTransport::ERRORS, true)) ? $clase : null,
            'lastAt' => is_string($cuando) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $cuando) === 1 ? $cuando : null,
        ];
    }
}

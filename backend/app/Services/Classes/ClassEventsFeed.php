<?php

namespace App\Services\Classes;

use App\Models\ClassEvent;
use Illuminate\Support\Collection;

/**
 * Lectura de `class_events` para el canal del CRM, por cursor.
 *
 * Separado del controlador para poder probarlo: el stream en sí es un bucle
 * de 25 s que no se ejercita por HTTP.
 */
final class ClassEventsFeed
{
    /**
     * Cuántos ids hacia atrás se vuelven a mirar en cada lectura.
     *
     * En PostgreSQL el id se asigna al INSERTAR, no al confirmar: una fila con
     * id 10 puede hacerse visible después que la 11. Leer siempre «> cursor»
     * se la saltaría para siempre. Se relee una ventana y se descarta lo ya
     * enviado por id.
     */
    public const OVERLAP = 50;

    /** Máximo de hechos por lectura. */
    public const LIMIT = 100;

    /**
     * Desde dónde se lee y si hay que releer todo.
     *
     * `resync` = no se puede garantizar continuidad: no hay cursor (primera
     * conexión), el cursor es de otra base o lo siguiente ya se podó.
     *
     * @return array{cursor:int, resync:bool}
     */
    public static function open(?int $afterId): array
    {
        $max = (int) (ClassEvent::max('id') ?? 0);

        if ($afterId === null || $afterId < 0) {
            return ['cursor' => $max, 'resync' => true];
        }

        $min = ClassEvent::min('id');
        $podado = $min !== null && $afterId < ((int) $min) - 1;
        $ajeno = $afterId > $max;

        return $podado || $ajeno
            ? ['cursor' => $max, 'resync' => true]
            : ['cursor' => $afterId, 'resync' => false];
    }

    /**
     * Desde dónde lee una conexión. Si continúa (resync=false), desde un poco
     * ANTES de su cursor: un hecho con id menor que confirmó tarde, durante el
     * hueco de la reconexión, quedaba por debajo para siempre. El CRM descarta
     * por id lo que ya vio. En una apertura con resync, desde la cabeza: lo
     * anterior ya lo cubre la relectura.
     *
     * @param  array{cursor:int, resync:bool}  $apertura
     */
    public static function floorFor(array $apertura): int
    {
        return $apertura['resync'] ? $apertura['cursor'] : max(0, $apertura['cursor'] - self::OVERLAP);
    }

    /**
     * Hechos posteriores al suelo de la conexión que aún no se enviaron.
     *
     * @param  array<int, true>  $enviados  ids ya emitidos en esta conexión
     * @return Collection<int, ClassEvent>
     */
    public static function pending(int $suelo, int $cursor, array $enviados): Collection
    {
        $desde = max($suelo, $cursor - self::OVERLAP);

        // Se leen LIMIT + OVERLAP para que la ventana ya enviada no se coma
        // la lectura, y se entregan como mucho LIMIT: lo demás sale en el
        // siguiente latido, desde el cursor.
        return ClassEvent::query()
            ->where('id', '>', $desde)
            ->orderBy('id')
            ->limit(self::LIMIT + self::OVERLAP)
            ->get()
            ->reject(fn (ClassEvent $e) => isset($enviados[(int) $e->id]))
            ->take(self::LIMIT)
            ->values();
    }

    /**
     * Lo que viaja: el hecho, sin datos de nadie.
     *
     * @return array<string, mixed>
     */
    public static function payload(ClassEvent $e): array
    {
        return [
            'id' => (int) $e->id,
            'type' => $e->type,
            'class_id' => $e->class_id,
            'session_date' => $e->session_date?->toDateString(),
            'source' => $e->source,
            'reason' => $e->reason,
            'at' => $e->created_at?->toIso8601String(),
        ];
    }
}

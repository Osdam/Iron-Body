<?php

namespace App\Support\Cache;

use Illuminate\Cache\DatabaseStore;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Borra las claves CADUCADAS de UN prefijo del almacén del LIMITADOR de
 * Laravel cuando ese almacén es la caché `database` (PERF8, hallazgo P8-3; lo
 * usa `voice:prune-rate-limits`).
 *
 * Por qué hace falta: `DatabaseStore` solo borra una fila caducada cuando
 * alguien vuelve a leer su clave. Las claves que no se repiten (las de una
 * llamada, las de una IP que no vuelve) se quedan en la tabla para siempre.
 *
 * Qué borra: solo filas con `expiration <= ahora` (lo mismo que
 * `DatabaseStore::forgetIfExpired()`: para la caché ya no existen) y cuya
 * clave empieza EXACTAMENTE por el prefijo del almacén más `$keyPrefix` (LIKE
 * con escape: un `%` o un `_` del prefijo no es comodín). Nunca toca una clave
 * viva ni otra cosa de la caché. En tandas cortas (un DELETE de como mucho
 * {@see BATCH} filas cada vez), para no retener cerrojos con un atraso grande.
 *
 * El almacén es el del limitador, como lo resuelve `CacheServiceProvider`
 * (`cache.limiter` o, sin él, el de por defecto). Con otro controlador (redis,
 * array, file…) no hay nada que purgar aquí: caducan solos o no persisten.
 *
 * Genérica a propósito: lee la configuración de la CACHÉ (`cache.*`), que no
 * es de ningún dominio; quien la llama solo aporta su prefijo.
 */
final class ExpiredCachePruner
{
    /** Filas por DELETE. */
    public const BATCH = 1000;

    /** Tandas como mucho en una ejecución (un millón de filas); lo que quede, en la siguiente. */
    public const MAX_BATCHES = 1000;

    /**
     * @return array{store: string, driver: string|null, supported: bool, dryRun: bool, expired: int, deleted: int, batches: int}
     *
     * @throws InvalidArgumentException sin prefijo: nunca se purga la caché entera
     */
    public static function prune(string $keyPrefix, bool $dryRun = false): array
    {
        if ($keyPrefix === '') {
            throw new InvalidArgumentException('Hace falta un prefijo: nunca se purga la caché entera.');
        }
        $nombre = (string) (config('cache.limiter') ?: config('cache.default'));
        $driver = config("cache.stores.{$nombre}.driver");
        $resumen = ['store' => $nombre, 'driver' => is_string($driver) ? $driver : null, 'supported' => false, 'dryRun' => $dryRun, 'expired' => 0, 'deleted' => 0, 'batches' => 0];
        $store = $driver === 'database' ? Cache::store($nombre)->getStore() : null;
        if (! $store instanceof DatabaseStore) {
            return $resumen;
        }

        $tabla = (string) config("cache.stores.{$nombre}.table", 'cache');
        $conexion = $store->getConnection();
        $clave = $conexion->getQueryGrammar()->wrap('key');
        $patron = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $store->getPrefix().$keyPrefix).'%';
        // El instante de la caché (`InteractsWithTime`), fijo para toda la ejecución.
        $ahora = now()->getTimestamp();
        $caducadas = static fn () => $conexion->table($tabla)->where('expiration', '<=', $ahora)->whereRaw("{$clave} LIKE ? ESCAPE '!'", [$patron]);

        $resumen['supported'] = true;
        $resumen['expired'] = $caducadas()->count();
        if ($dryRun || $resumen['expired'] === 0) {
            return $resumen;
        }
        do {
            // Se vuelve a exigir la caducidad: una clave renovada entre la selección y el borrado se queda.
            $borradas = $conexion->table($tabla)
                ->whereIn('key', $caducadas()->select('key')->limit(self::BATCH))
                ->where('expiration', '<=', $ahora)
                ->delete();
            $resumen['deleted'] += $borradas;
            $resumen['batches']++;
        } while ($borradas === self::BATCH && $resumen['batches'] < self::MAX_BATCHES);

        return $resumen;
    }
}

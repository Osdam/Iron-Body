<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Tamaño de página negociable por la petición (`?per_page=`), con techo.
 *
 * Los listados del CRM se paginan en el servidor. Sin un `per_page` acotado, el
 * cliente terminaba recorriendo TODAS las páginas en secuencia (una request por
 * cada 20 filas) y los módulos tardaban muchísimo con datos reales; con un techo
 * se evita además que una petición pida la tabla completa de golpe.
 */
trait ResolvesPagination
{
    /** Resuelve `per_page` acotado a [1, $max] con `$default` como respaldo. */
    protected function resolvePerPage(Request $request, int $default = 20, int $max = 100): int
    {
        $perPage = (int) $request->input('per_page', $default);

        if ($perPage <= 0) {
            $perPage = $default;
        }

        return max(1, min($perPage, $max));
    }

    /**
     * Operador LIKE insensible a mayúsculas para la conexión en uso. PostgreSQL
     * necesita `ilike` (su `LIKE` distingue mayúsculas); en MySQL/SQLite el
     * collation por defecto ya es insensible.
     */
    protected function likeOperator(?string $driver = null): string
    {
        $driver ??= DB::connection()->getDriverName();

        return $driver === 'pgsql' ? 'ilike' : 'like';
    }

    /** Envuelve un término de búsqueda en comodines, escapando `%` y `_`. */
    protected function likeTerm(string $search): string
    {
        return '%'.str_replace(['%', '_'], ['\%', '\_'], trim($search)).'%';
    }
}

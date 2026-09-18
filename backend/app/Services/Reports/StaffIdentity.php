<?php

namespace App\Services\Reports;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;

/**
 * Quién hizo una operación, dicho de una forma que se pueda agrupar y filtrar.
 *
 * El problema real: la misma persona aparece en tres tablas con tres formas de
 * identificarse —id de la cuenta en unas, nombre congelado en otras— y a veces
 * sin ninguna, porque el cobro es anterior a que se registrara quién cobraba.
 * Agrupar por id perdía a los que solo tienen nombre; agrupar por nombre juntaba
 * a dos empleados homónimos y separaba a quien se hubiera cambiado el nombre.
 *
 * La clave es por tanto: id si lo hay, nombre si no, y un grupo aparte para lo
 * que NO CONSTA. Ese último grupo es información, no un error: dice cuánto del
 * dinero del periodo no se puede atribuir a nadie, y es lo que impide repartir
 * a ciegas entre el equipo lo que nadie firmó.
 */
final class StaffIdentity
{
    public const NONE = 'none';

    public const UNKNOWN_LABEL = 'Sin registrar quién';

    /** Clave estable de una persona a partir de lo que guarde su tabla. */
    public static function key(?int $adminId, ?string $name): string
    {
        if ($adminId !== null && $adminId > 0) {
            return 'admin-'.$adminId;
        }

        $nombre = trim((string) $name);

        return $nombre !== '' ? 'name-'.mb_strtolower($nombre) : self::NONE;
    }

    /**
     * Restringe una consulta a las operaciones de esa persona.
     *
     * `none` no es «sin filtro»: es el filtro de lo no atribuido, y devuelve
     * justo las filas que no tienen ni cuenta ni nombre.
     */
    public static function constrain(BuilderContract $query, string $key, string $idColumn, string $nameColumn): void
    {
        if ($key === self::NONE) {
            $query->whereNull($idColumn)->where(fn ($q) => $q->whereNull($nameColumn)->orWhere($nameColumn, ''));

            return;
        }

        if (str_starts_with($key, 'admin-')) {
            $query->where($idColumn, (int) substr($key, 6));

            return;
        }

        if (str_starts_with($key, 'name-')) {
            $query->whereRaw('LOWER('.$nameColumn.') = ?', [substr($key, 5)]);

            return;
        }

        // Clave que no entendemos: no se devuelve todo «por si acaso».
        $query->whereRaw('1 = 0');
    }
}

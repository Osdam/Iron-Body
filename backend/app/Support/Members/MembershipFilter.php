<?php

namespace App\Support\Members;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;

/**
 * Qué significa que un socio esté activo, vencido o por vencer, en SQL.
 *
 * Existe porque esa respuesta estaba escrita tres veces con reglas distintas, y
 * la del listado de Miembros era la equivocada: filtraba `where('status', ?)`
 * contra la columna, exacto y sensible a mayúsculas. Dos consecuencias reales:
 *
 *  - «Vencidos» no devolvía casi nada. Un socio con la membresía caducada
 *    conserva `status = 'active'` y lo que cambió es `membership_end_date`; el
 *    valor `expired` apenas existe en la tabla.
 *  - «Inactivos» se saltaba las filas importadas del sistema anterior, que
 *    traen `inactivo` en español.
 *
 * La definición buena ya la aplicaban Analítica y el módulo de exportación:
 *   estado de la CUENTA  → columna `status`, normalizada (vacío = activa)
 *   situación de la MEMBRESÍA → fechas, no la columna
 * y «activo» exige las dos cosas: cuenta activa y membresía no vencida.
 *
 * Las fechas se calculan en la zona del negocio: «vence hoy» es hoy en Neiva,
 * no en UTC, y con el servidor en UTC eso desplaza un día cada noche.
 */
final class MembershipFilter
{
    /** Ventana de «por vencer» por defecto. Igual que Asistencia y Analítica. */
    public const EXPIRING_SOON_DAYS = 7;

    public const TZ = 'America/Bogota';

    /** Un socio vencido hace más de esto ya no es una renovación caliente. */
    public const RECENT_EXPIRY_DAYS = 30;

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TZ)->startOfDay();
    }

    /** Estado de la cuenta normalizado. Vacío o nulo cuentan como activa. */
    public static function accountExpr(): string
    {
        return "LOWER(COALESCE(NULLIF(users.status, ''), 'active'))";
    }

    /** Grafías equivalentes de cada estado de cuenta. @return list<string> */
    public static function accountAliases(string $estado): array
    {
        return match ($estado) {
            'active' => ['active', 'activo', 'activa'],
            'inactive' => ['inactive', 'inactivo', 'inactiva'],
            'pending' => ['pending', 'pendiente'],
            'expired' => ['expired', 'vencido', 'vencida'],
            default => [$estado],
        };
    }

    /**
     * Aplica un filtro de los que ofrece el CRM.
     *
     * Un valor desconocido NO se ignora: se compara contra la columna sin
     * distinguir mayúsculas. Ignorarlo devolvería la lista entera como si el
     * filtro no existiera, que es la forma más silenciosa de mentir.
     */
    public static function apply(BuilderContract $query, string $filtro): void
    {
        $hoy = self::today()->toDateString();

        match ($filtro) {
            'all', '' => null,

            // Cuenta activa Y membresía vigente. Sin la segunda condición,
            // «Activos» incluía a los vencidos y no cuadraba con Analítica.
            'active' => $query
                ->where(fn ($q) => self::whereAccount($q, 'active'))
                ->where(fn ($q) => self::whereNotExpired($q, $hoy)),

            'inactive' => self::whereAccount($query, 'inactive'),
            'pending' => self::whereAccount($query, 'pending'),

            // Por estado O por fecha: las dos son vencimiento.
            'expired' => $query->where(fn ($q) => self::whereAccount($q, 'expired')
                ->orWhere('users.membership_end_date', '<', $hoy)),

            // Vencidas hace poco: a quien conviene llamar para recuperar.
            'expired_recent' => $query
                ->whereNotNull('users.membership_end_date')
                ->whereBetween('users.membership_end_date', [
                    self::today()->subDays(self::RECENT_EXPIRY_DAYS)->toDateString(),
                    $hoy,
                ]),

            'none' => $query->whereNull('users.membership_end_date'),

            // Pagadas pero todavía sin empezar: pagó hoy para iniciar el lunes.
            // Cuentan también como «activas» —la membresía es suya y no está
            // vencida—, pero Asistencia no las deja entrar hasta ese día.
            'scheduled' => $query
                ->whereNotNull('users.membership_start_date')
                ->where('users.membership_start_date', '>', $hoy),

            default => self::applyExpiringOrRaw($query, $filtro, $hoy),
        };
    }

    /**
     * Filtros con ventana: `expiring_7`, `expiring_15`, `expiring_30`…
     *
     * Van juntos y no por separado porque «por vencer» sin plazo no dice nada:
     * lo que se quiere saber siempre es en cuántos días.
     */
    private static function applyExpiringOrRaw(BuilderContract $query, string $filtro, string $hoy): void
    {
        if (preg_match('/^expiring_(\d{1,3})$/', $filtro, $m)) {
            $query
                ->whereNotNull('users.membership_end_date')
                ->whereBetween('users.membership_end_date', [
                    $hoy,
                    self::today()->addDays((int) $m[1])->toDateString(),
                ]);

            return;
        }

        if ($filtro === 'expiring') {
            self::applyExpiringOrRaw($query, 'expiring_'.self::EXPIRING_SOON_DAYS, $hoy);

            return;
        }

        self::whereAccount($query, strtolower(trim($filtro)));
    }

    /**
     * Compara el estado normalizado contra sus grafías.
     *
     * Con whereRaw y no con whereIn: el primer argumento de whereIn es un
     * NOMBRE de columna, y aquí es una expresión; Laravel la entrecomillaría
     * como si fuera una columna llamada «LOWER(COALESCE(...))».
     */
    private static function whereAccount(BuilderContract $query, string $estado): BuilderContract
    {
        $valores = self::accountAliases($estado);

        return $query->whereRaw(
            self::accountExpr().' IN ('.implode(', ', array_fill(0, count($valores), '?')).')',
            $valores,
        );
    }

    /** Vigente: sin fecha de fin, o con una que no ha pasado. */
    private static function whereNotExpired(BuilderContract $query, string $hoy): void
    {
        $query->whereNull('users.membership_end_date')
            ->orWhere('users.membership_end_date', '>=', $hoy);
    }

    /**
     * Opciones para los desplegables del CRM. Una sola lista para el listado de
     * Miembros y para el módulo de exportación: si divergieran, el mismo nombre
     * significaría cosas distintas en dos pantallas.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return [
            ['value' => 'all', 'label' => 'Todas'],
            ['value' => 'active', 'label' => 'Activas'],
            ['value' => 'scheduled', 'label' => 'Programadas (inician después)'],
            ['value' => 'expiring_7', 'label' => 'Vencen en 7 días'],
            ['value' => 'expiring_15', 'label' => 'Vencen en 15 días'],
            ['value' => 'expiring_30', 'label' => 'Vencen en 30 días'],
            ['value' => 'expired_recent', 'label' => 'Vencidas hace menos de '.self::RECENT_EXPIRY_DAYS.' días'],
            ['value' => 'expired', 'label' => 'Vencidas'],
            ['value' => 'none', 'label' => 'Sin membresía'],
        ];
    }

    /** Valores que `apply()` entiende, para validar la petición. @return list<string> */
    public static function allowedValues(): array
    {
        return [
            'all', 'active', 'scheduled', 'inactive', 'pending', 'expired', 'expired_recent', 'none',
            'expiring', 'expiring_7', 'expiring_15', 'expiring_30', 'expiring_60', 'expiring_90',
        ];
    }
}

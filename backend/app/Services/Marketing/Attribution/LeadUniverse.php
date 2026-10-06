<?php

namespace App\Services\Marketing\Attribution;

use App\Models\MarketingLead;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Qué cuenta como lead REAL y cuándo es la misma persona (regla D6).
 *
 * Vive en un solo sitio porque lo preguntan el panel, el resolutor de
 * identidades, la auditoría y el relleno de atribuciones, y las cuatro
 * respuestas tienen que coincidir: si el panel excluye las pruebas y la
 * auditoría no, sus cifras no se pueden comparar.
 */
final class LeadUniverse
{
    /**
     * Orígenes de los leads de prueba. Todos los leads que crea el canal llegan
     * con `inbound` (MetaLeadService); estos los creó alguien a mano para probar.
     */
    public const TEST_SOURCES = ['manual_test', 'internal_test', 'manual_phone_test'];

    /**
     * Restringe una consulta a los leads reales.
     *
     * El origen vacío también queda fuera: el canal siempre lo rellena, y la
     * auditoría de producción contó el único lead sin origen entre los de prueba.
     */
    public static function constrain(Builder $query, string $column = 'marketing_leads.source'): Builder
    {
        return $query->whereNotNull($column)
            ->where($column, '<>', '')
            ->whereNotIn($column, self::TEST_SOURCES);
    }

    public static function isReal(?string $source): bool
    {
        return $source !== null && $source !== '' && ! in_array($source, self::TEST_SOURCES, true);
    }

    /**
     * Los 10 últimos dígitos de un teléfono, o null si no los tiene.
     *
     * Es la única comparación que cruza formatos: el lead llega con el 57
     * delante (12 dígitos, desde el `wa_id`) y la ficha del socio se guarda con
     * 10, o como la escribió quien la creó en el mostrador. La misma regla que
     * usan los reclamos de pago.
     */
    public static function last10(?string $phone): ?string
    {
        $digits = substr(preg_replace('/\D+/', '', (string) $phone) ?? '', -10);

        return strlen($digits) === 10 ? $digits : null;
    }

    /**
     * Expresión SQL con los 10 últimos caracteres de un teléfono, quitados los
     * separadores habituales (espacio, +, -, paréntesis, punto y barra), para
     * buscar leads de la misma persona en lote. Portable entre PostgreSQL y
     * SQLite. Un teléfono más corto da menos de 10 caracteres y no casa con
     * ningún número buscado.
     *
     * Es solo un filtro previo: la regla es {@see last10()}, y quien la use
     * tiene que volver a comprobar cada fila con {@see personKey()}.
     */
    public static function phoneTailSql(string $column): string
    {
        $clean = $column;
        foreach ([' ', '+', '-', '(', ')', '.', '/'] as $separator) {
            $clean = "REPLACE({$clean}, '{$separator}', '')";
        }

        return "SUBSTR({$clean}, LENGTH({$clean}) - 9)";
    }

    /**
     * La persona del lead: su teléfono o, si no lo tiene (Instagram, Facebook),
     * el identificador del canal. Dos leads con la misma clave son una persona.
     */
    public static function personKey(MarketingLead $lead): string
    {
        $phone = self::last10($lead->phone);
        if ($phone !== null) {
            return 'tel:'.$phone;
        }

        if (filled($lead->meta_user_id)) {
            return $lead->channel.':'.$lead->meta_user_id;
        }

        return 'lead:'.$lead->id;
    }

    /** Expresión SQL del primer contacto: el primer mensaje o, si falta, el alta. */
    public static function firstContactSql(string $table = 'marketing_leads'): string
    {
        return "COALESCE({$table}.first_message_at, {$table}.created_at)";
    }

    /** El primer contacto de un lead, en UTC. */
    public static function firstContactAt(MarketingLead $lead): CarbonImmutable
    {
        $at = $lead->first_message_at ?? $lead->created_at ?? now();

        return CarbonImmutable::instance($at)->utc();
    }
}

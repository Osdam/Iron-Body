<?php

namespace App\Services\Audit;

use App\Models\AuditLog;

/**
 * Qué plan declaró un cobro AL NACER, según su traza de creación.
 *
 * Existe porque `payments.plan_id` puede mentir por omisión: la llave foránea
 * era ON DELETE SET NULL, y borrar un plan del catálogo dejó sus cobros «sin
 * plan» (el plan 22, el 16-10-2026: 31 cobros). La única prueba que queda de a
 * qué plan pertenecían es la fila `pago / create` que escribió
 * {@see FinancialAudit::paymentCreated()} con `metadata.plan_id`.
 *
 * Solo lee esa prueba; no adivina por nombre, fecha ni importe. Un cobro sin
 * traza de creación (los `MIGR-*` importados, los de pasarela) devuelve una
 * lista vacía, y uno cuya traza no trae `plan_id` devuelve `[null]`: no es lo
 * mismo «no hay traza» que «la traza dice que no tenía plan».
 *
 * La lectura de JSON se hace en PHP y no con operadores del motor: tiene que
 * dar lo mismo en PostgreSQL (producción) y en SQLite (la suite).
 */
class PaymentCreationAudit
{
    /** `whereIn` por tandas: SQLite limita los parámetros de una consulta. */
    private const CHUNK = 500;

    /**
     * Los `plan_id` que declaran las trazas de creación de cada cobro.
     *
     * Por id de cobro; los que no tienen traza de creación no aparecen.
     *
     * @param  list<int>  $paymentIds
     * @return array<int, array{plan_ids: list<int|null>, audit_ids: list<int>}>
     */
    public function declaredPlans(array $paymentIds): array
    {
        $resultado = [];

        foreach (array_chunk(array_values(array_unique($paymentIds)), self::CHUNK) as $tanda) {
            $filas = AuditLog::query()
                ->where('action', 'create')
                ->where('entity', 'pago')
                ->whereIn('entity_id', array_map('strval', $tanda))
                ->orderBy('id')
                ->get(['id', 'entity_id', 'metadata']);

            foreach ($filas as $fila) {
                if (! ctype_digit((string) $fila->entity_id)) {
                    continue;
                }
                $pago = (int) $fila->entity_id;
                $resultado[$pago] ??= ['plan_ids' => [], 'audit_ids' => []];
                $resultado[$pago]['audit_ids'][] = (int) $fila->id;
                $plan = self::planIdOf(is_array($fila->metadata) ? $fila->metadata : []);
                if (! in_array($plan, $resultado[$pago]['plan_ids'], true)) {
                    $resultado[$pago]['plan_ids'][] = $plan;
                }
            }
        }

        return $resultado;
    }

    /**
     * El plan que declara UNA traza, o null si no lo declara o no es un id.
     * Acepta el entero que escribe Eloquent y el texto «22» que escribiría un
     * cliente; nada más («22.0», «22 » o un nombre no son un id).
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function planIdOf(array $metadata): ?int
    {
        $valor = $metadata['plan_id'] ?? null;
        if (is_int($valor)) {
            return $valor > 0 ? $valor : null;
        }
        if (is_string($valor) && ctype_digit($valor) && (int) $valor > 0) {
            return (int) $valor;
        }

        return null;
    }
}

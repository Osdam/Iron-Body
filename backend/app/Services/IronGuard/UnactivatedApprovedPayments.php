<?php

namespace App\Services\IronGuard;

use App\Models\Incident;
use App\Support\Caja\PaymentOrigin;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * «Cobrado sin servicio»: pagos de pasarela APROBADOS que se quedaron sin su
 * membresía. Hoy eso solo se descubre cuando el socio reclama en la puerta.
 *
 * SOLO LEE. No activa nada, no escribe en `payments` ni en `users` y no toca el
 * activador (`PaymentMembershipActivator`): reactivar es núcleo de pagos. Es la
 * única fuente de verdad del criterio; la orden
 * `payments:approved-unactivated-check` la usa tal cual.
 *
 * EL CRITERIO es el de las clases `FALLO_*` de
 * `docs/ultron-studio/ops/pagos-aprobados-sin-activar.sql`, leído en el
 * activador (`PaymentMembershipActivator::activate()`):
 *
 *  - Solo `payment_transactions.status = 'approved'` con usuario RESUELTO y
 *    existente: el de la transacción o, si falta, el de la ficha del socio
 *    (`members.user_id`). Sin él el activador sale sin hacer nada a propósito
 *    (`NORMAL_SIN_USUARIO`, el flujo de enlace y reclamo): no es un fallo.
 *  - Falta la fila `payments` de origen `gateway` con la misma `reference`:
 *    con plan, `FALLO_SIN_PAGO_NI_MEMBRESIA`; sin plan,
 *    `FALLO_SIN_REGISTRO_DE_PAGO`. Una fila de CAJA (`counter`) con la misma
 *    referencia no cuenta: el activador busca por referencia Y origen.
 *  - O la fila está `paid`, con un plan que existe y dura más de 0 días, y sin
 *    `period_start`/`period_end`: `FALLO_CON_PLAN_SIN_PERIODO`. Salvo que sea
 *    anterior al corte del periodo (`NORMAL_ANTERIOR_AL_PERIODO`), que se
 *    deduce de los datos igual que en la consulta.
 *  - No se avisa de `NORMAL_PAGO_REVERTIDO`, `NORMAL_SIN_PLAN` ni
 *    `REVISAR_PLAN_SIN_DIAS` (un plan sin días no extiende nada: no es
 *    «cobrado sin servicio», es un plan mal configurado).
 *
 * LO QUE AÑADE A LA CONSULTA, y por qué:
 *  - Una ventana reciente (`window_days`, 7 por defecto) sobre la fecha de
 *    aprobación (`approved_at`, si no `paid_at`, si no `updated_at`, como la
 *    consulta): el aviso mira lo que está pasando; la historia la cuenta la
 *    consulta de diagnóstico.
 *  - Una gracia (`grace_minutes`, 5 por defecto): un pago aprobado hace
 *    segundos puede estar activándose todavía.
 */
final class UnactivatedApprovedPayments
{
    public const SOURCE = 'wompi';

    public const KIND = 'payments_approved_unactivated';

    /** La CLASE del incidente: todos estos fallos son el mismo problema. */
    public const FINGERPRINT_KEY = 'approved_unactivated';

    public const CLASS_NO_PAYMENT_WITH_PLAN = 'FALLO_SIN_PAGO_NI_MEMBRESIA';

    public const CLASS_NO_PAYMENT = 'FALLO_SIN_REGISTRO_DE_PAGO';

    public const CLASS_NO_PERIOD = 'FALLO_CON_PLAN_SIN_PERIODO';

    public const CHECK_COMMAND = 'php artisan payments:approved-unactivated-check --dry-run';

    public const DIAGNOSTIC_QUERY = 'backend/docs/ultron-studio/ops/pagos-aprobados-sin-activar.sql';

    public const RUNBOOK = 'backend/docs/ultron-studio/ops/aviso-pagos-aprobados-sin-activar.md';

    /** Cuántos ids y referencias viajan en la evidencia; el recuento es siempre el total. */
    public const EVIDENCE_SAMPLE = 20;

    /** La fecha de aprobación, con la misma prioridad que la consulta de diagnóstico. */
    private const APPROVED_ON = 'coalesce(t.approved_at, t.paid_at, t.updated_at)';

    /**
     * @return array{window_days:int,grace_minutes:int,from:string,to:string,payments:list<array{transaction_id:int,reference:string,provider:string,class:string,approved_at:string}>}
     */
    public function find(?CarbonInterface $now = null): array
    {
        $ahora = CarbonImmutable::instance($now ?? now())->utc();
        $dias = max(1, (int) config('observability.approved_unactivated.window_days', 7));
        $gracia = max(0, (int) config('observability.approved_unactivated.grace_minutes', 5));
        $desde = $ahora->subDays($dias);
        $hasta = $ahora->subMinutes($gracia);

        $filas = DB::table('payment_transactions as t')
            ->leftJoin('members as m', 'm.id', '=', 't.member_id')
            ->leftJoin('payments as p', function ($join): void {
                // Referencia Y origen, como el activador y su índice único
                // parcial (`payments_gateway_reference_unique`).
                $join->on('p.reference', '=', 't.reference')
                    ->where('p.origin', '=', PaymentOrigin::GATEWAY->value)
                    ->whereNotNull('p.reference');
            })
            ->leftJoin('plans as pl', 'pl.id', '=', 'p.plan_id')
            ->where('t.status', 'approved')
            ->whereRaw(self::APPROVED_ON.' >= ?', [$desde->format('Y-m-d H:i:s')])
            ->whereRaw(self::APPROVED_ON.' <= ?', [$hasta->format('Y-m-d H:i:s')])
            // El usuario resuelto como lo resuelve el activador: el de la
            // transacción o, si falta, el de la ficha; y que exista.
            ->whereRaw('exists (select 1 from users u where u.id = coalesce(t.user_id, m.user_id))')
            ->where(function ($q): void {
                $q->whereNull('p.id')
                    ->orWhere(function ($q): void {
                        $q->where('p.status', 'paid')
                            ->whereNotNull('p.plan_id')
                            ->where('pl.duration_days', '>', 0)
                            ->where(fn ($q) => $q->whereNull('p.period_start')->orWhereNull('p.period_end'));
                    });
            })
            ->orderByRaw(self::APPROVED_ON)
            ->orderBy('t.id')
            ->get([
                't.id', 't.reference', 't.provider', 't.plan_id',
                DB::raw(self::APPROVED_ON.' as approved_on'),
                'p.id as payment_id', 'p.created_at as payment_created_at',
            ]);

        $corte = $this->periodCutoff($filas->whereNotNull('payment_id')->isNotEmpty());

        $pagos = [];
        foreach ($filas as $f) {
            if ($f->payment_id !== null && $corte !== null && $f->payment_created_at !== null
                && CarbonImmutable::parse((string) $f->payment_created_at, 'UTC')->lt($corte)) {
                continue; // NORMAL_ANTERIOR_AL_PERIODO: la fila es de antes de que existiera el periodo.
            }

            $pagos[] = [
                'transaction_id' => (int) $f->id,
                'reference' => (string) $f->reference,
                'provider' => (string) $f->provider,
                'class' => match (true) {
                    $f->payment_id !== null => self::CLASS_NO_PERIOD,
                    $f->plan_id !== null => self::CLASS_NO_PAYMENT_WITH_PLAN,
                    default => self::CLASS_NO_PAYMENT,
                },
                'approved_at' => CarbonImmutable::parse((string) $f->approved_on, 'UTC')->toIso8601String(),
            ];
        }

        return [
            'window_days' => $dias,
            'grace_minutes' => $gracia,
            'from' => $desde->toIso8601String(),
            'to' => $hasta->toIso8601String(),
            'payments' => $pagos,
        ];
    }

    /**
     * El corte del periodo, deducido de los datos como en la consulta: el
     * `created_at` más antiguo de una fila de pasarela o de caja con
     * `period_start` (desde la migración 2026_09_14_000002 solo
     * `MembershipPeriod` lo escribe, al cobrar; las `legacy` se excluyen porque
     * esa migración les rellenó periodos antiguos). Solo se calcula si hay una
     * fila candidata: lo normal es que no haya ninguna y no cueste nada.
     */
    private function periodCutoff(bool $needed): ?CarbonImmutable
    {
        if (! $needed) {
            return null;
        }

        $min = DB::table('payments')
            ->whereIn('origin', [PaymentOrigin::GATEWAY->value, PaymentOrigin::COUNTER->value])
            ->whereNotNull('period_start')
            ->min('created_at');

        return $min === null ? null : CarbonImmutable::parse((string) $min, 'UTC');
    }

    /**
     * Lo que se puede enseñar de una pasada: recuentos, ids internos de la
     * transacción, referencias de pasarela y la fecha del más antiguo. NADA de
     * la persona (ni nombre, ni teléfono, ni correo, ni documento) ni importes.
     * Lo mismo va al incidente y al log.
     *
     * @param  array{window_days:int,grace_minutes:int,from:string,to:string,payments:list<array<string,mixed>>}  $result
     * @return array<string,mixed>
     */
    public static function evidence(array $result): array
    {
        $pagos = collect($result['payments']);

        return [
            'count' => $pagos->count(),
            'by_class' => $pagos->countBy('class')->sortKeys()->all(),
            'transaction_ids' => $pagos->pluck('transaction_id')->take(self::EVIDENCE_SAMPLE)->values()->all(),
            'references' => $pagos->pluck('reference')->take(self::EVIDENCE_SAMPLE)->values()->all(),
            'oldest_at' => $pagos->min('approved_at'),
            'window_days' => $result['window_days'],
            'grace_minutes' => $result['grace_minutes'],
            'check_command' => self::CHECK_COMMAND,
            'diagnostic_query' => self::DIAGNOSTIC_QUERY,
            'runbook' => self::RUNBOOK,
        ];
    }

    /**
     * El incidente de IRON GUARD de una pasada, o null si no encontró nada.
     * Crítico: es dinero cobrado sin el servicio (el detector reserva crítico
     * para la pérdida). Una sola clase (`fingerprint_keys` fija): dos pasadas
     * sobre los mismos pagos suman ocurrencias al mismo incidente.
     *
     * @param  array{window_days:int,grace_minutes:int,from:string,to:string,payments:list<array<string,mixed>>}  $result
     * @return array<string,mixed>|null
     */
    public static function incident(array $result): ?array
    {
        if ($result['payments'] === []) {
            return null;
        }

        $n = count($result['payments']);

        return [
            'source' => self::SOURCE,
            'kind' => self::KIND,
            'title' => sprintf('%d pago(s) de pasarela aprobado(s) sin su membresía (cobrado sin servicio)', $n),
            'severity' => Incident::SEVERITY_CRITICAL,
            'fingerprint_keys' => [self::FINGERPRINT_KEY],
            'evidence' => self::evidence($result),
            'correlation_ids' => array_map(
                static fn (array $p): string => 'payment_transaction:'.$p['transaction_id'],
                array_slice($result['payments'], 0, 10),
            ),
            'affected_conversations' => 0,
            'affected_messages' => 0,
        ];
    }
}

<?php

namespace App\Services\Marketing\Attribution;

use App\Enums\CashShiftType;
use App\Http\Controllers\Api\PaymentController;
use App\Models\MarketingLead;
use App\Models\MarketingLeadIdentity;
use App\Services\Caja\CashReceipts;
use App\Services\Reports\ReportSql;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Qué dinero cobró el gimnasio a la persona de cada lead, dentro de su ventana
 * de atribución (decisiones D1, D2 y D4).
 *
 * EL DINERO es el de {@see CashReceipts}, la misma regla del arqueo y de
 * Reportes, y solo el de planes y membresías:
 *
 *   · pagos que no son el asiento de un plan a plazos (`method = receivable`
 *     es el contrato, no caja), con estado pagado, importe MAYOR QUE CERO y
 *     fecha COALESCE(paid_at, created_at);
 *   · abonos aplicados, de importe mayor que cero, a las cuentas por cobrar del
 *     gimnasio de su ficha de socio, por la fecha del abono.
 *
 * Un «cobro» de $0 (un plan de cortesía, el ingreso de un empleado, un pago
 * heredado sin importe) no es dinero: no convierte, no cuenta como ingreso y
 * tampoco hace «cliente previo» a quien después paga de verdad.
 *
 * Fuera: la tienda y la cafetería (`product_sales` y sus créditos) y las
 * deudas sin cobrar. Todo se calcula sobre el ESTADO ACTUAL: anular un pago o
 * revertir un abono lo saca de aquí sin hacer nada más.
 *
 * LA VENTANA va del primer contacto del lead a `window_days` días después, sin
 * pasar de ahora. Si la persona ya había pagado algo antes de escribir, es una
 * renovación; si no, un cliente nuevo. Solo hay conversión si lo cobrado en la
 * ventana suma más de cero.
 *
 * UNA PERSONA, UNA VEZ. Si dos leads llevan al mismo usuario, sus pagos se
 * asignan solo al de primer contacto más antiguo; el otro queda `duplicate`
 * con `duplicate_of`. Así un cliente con dos conversaciones no cuenta doble.
 *
 * Solo lee: la identidad de cada lead se resuelve en memoria
 * ({@see LeadIdentityResolver::resolveMany()}); la tabla derivada la escribe
 * únicamente el comando `marketing:resolve-lead-identities`.
 *
 * Una consulta en lote por tipo de dato, nunca una por lead.
 */
class ConversionLedger
{
    public const KIND_NEW = 'new';

    public const KIND_RENEWAL = 'renewal';

    /** Enlazado a una persona, pero sin cobros en su ventana. */
    public const KIND_NONE = 'none';

    /** No se sabe quién es: sin teléfono o sin coincidencias. */
    public const KIND_UNLINKED = 'unlinked';

    /** El teléfono señala a varias personas: no se atribuye a ninguna. */
    public const KIND_AMBIGUOUS = 'ambiguous';

    /** La misma persona que otro lead (`duplicate_of`), que es el que cuenta sus cobros. */
    public const KIND_DUPLICATE = 'duplicate';

    public const KINDS = [
        self::KIND_NEW, self::KIND_RENEWAL, self::KIND_NONE, self::KIND_UNLINKED, self::KIND_AMBIGUOUS, self::KIND_DUPLICATE,
    ];

    public const TYPE_PAYMENT = 'payment';

    public const TYPE_INSTALLMENT = 'installment';

    private const CHUNK = 500;

    /** Fecha efectiva de un cobro, la misma que usan Reportes y el arqueo. */
    private const PAYMENT_DATE = 'COALESCE(payments.paid_at, payments.created_at)';

    public function __construct(
        private readonly LeadIdentityResolver $identities,
        private readonly CashReceipts $cash,
    ) {}

    /** Días de la ventana de atribución [DECISIÓN DEL USUARIO]. */
    public static function windowDays(): int
    {
        return max(1, (int) config('marketing.attribution.window_days', 30));
    }

    /**
     * Un registro por lead, en el orden recibido y con el id del lead como clave.
     *
     * @param  Collection<int, MarketingLead>  $leads
     * @return Collection<int, array{lead_id: int, user_id: ?int, member_id: ?int, identity: string, kind: string,
     *     first_contact_at: string, window_end: string, payments: list<array{type: string, id: int, amount: float, at: string}>,
     *     revenue: float, duplicate_of: ?int}>
     */
    public function forLeads(Collection $leads): Collection
    {
        $leads = $leads->filter(fn ($lead) => $lead instanceof MarketingLead)
            ->keyBy(fn (MarketingLead $lead) => (int) $lead->id);

        if ($leads->isEmpty()) {
            return collect();
        }

        // En memoria: una lectura no escribe la tabla derivada.
        $identities = $this->identities->resolveMany($leads->values());
        $now = CarbonImmutable::now('UTC');
        $days = self::windowDays();

        // 1) Primer contacto, ventana y persona de cada lead.
        $base = [];
        foreach ($leads as $id => $lead) {
            $identity = $identities[$id];
            $first = LeadUniverse::firstContactAt($lead);
            $end = $first->addDays($days);

            $base[$id] = [
                'identity' => $identity,
                'first' => $first,
                // Sin pasar de ahora: una ventana abierta no promete cobros futuros.
                'end' => $end->greaterThan($now) ? $now : $end,
                'person' => $this->personOf($identity),
            ];
        }

        // 2) El lead que se queda con los pagos de cada persona: el más antiguo.
        $primary = [];
        foreach ($base as $id => $b) {
            if ($b['person'] === null) {
                continue;
            }

            $current = $primary[$b['person']] ?? null;
            if ($current === null
                || $b['first']->lessThan($base[$current]['first'])
                || ($b['first']->equalTo($base[$current]['first']) && $id < $current)) {
                $primary[$b['person']] = $id;
            }
        }

        // 3) El dinero de esas personas, en lote.
        $userIds = [];
        $memberIds = [];
        $from = null;
        $to = null;
        foreach ($primary as $leadId) {
            $b = $base[$leadId];
            if ($b['identity']['user_id'] !== null) {
                $userIds[$b['identity']['user_id']] = true;
            }
            if ($b['identity']['member_id'] !== null) {
                $memberIds[$b['identity']['member_id']] = true;
            }
            $from = $from === null || $b['first']->lessThan($from) ? $b['first'] : $from;
            $to = $to === null || $b['end']->greaterThan($to) ? $b['end'] : $to;
        }
        $userIds = array_keys($userIds);
        $memberIds = array_keys($memberIds);

        $payments = $from !== null ? $this->paymentsBetween($userIds, $from, $to) : [];
        $installments = $from !== null ? $this->installmentsBetween($memberIds, $from, $to) : [];
        $firstPayment = $this->firstPaymentAt($userIds);
        $firstInstallment = $this->firstInstallmentAt($memberIds);

        // 4) Un registro por lead.
        $out = collect();
        foreach ($base as $id => $b) {
            $identity = $b['identity'];
            $record = [
                'lead_id' => $id,
                'user_id' => $identity['user_id'],
                'member_id' => $identity['member_id'],
                'identity' => $identity['method'],
                'kind' => self::KIND_NONE,
                'first_contact_at' => $b['first']->toIso8601String(),
                'window_end' => $b['end']->toIso8601String(),
                'payments' => [],
                'revenue' => 0.0,
                'duplicate_of' => null,
            ];

            if ($identity['method'] === MarketingLeadIdentity::METHOD_AMBIGUOUS) {
                $record['kind'] = self::KIND_AMBIGUOUS;
            } elseif ($b['person'] === null) {
                $record['kind'] = self::KIND_UNLINKED;
            } elseif ($primary[$b['person']] !== $id) {
                $record['kind'] = self::KIND_DUPLICATE;
                $record['duplicate_of'] = $primary[$b['person']];
            } else {
                $record = $this->settle($record, $b, $payments, $installments, $firstPayment, $firstInstallment);
            }

            $out->put($id, $record);
        }

        return $out;
    }

    // ── Cálculo de un lead ──────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $b
     * @param  array<int, list<array{type: string, id: int, amount: float, at: CarbonImmutable}>>  $payments
     * @param  array<int, list<array{type: string, id: int, amount: float, at: CarbonImmutable}>>  $installments
     * @param  array<int, CarbonImmutable>  $firstPayment
     * @param  array<int, CarbonImmutable>  $firstInstallment
     * @return array<string, mixed>
     */
    private function settle(array $record, array $b, array $payments, array $installments, array $firstPayment, array $firstInstallment): array
    {
        $userId = $b['identity']['user_id'];
        $memberId = $b['identity']['member_id'];

        $candidates = array_merge(
            $userId !== null ? ($payments[$userId] ?? []) : [],
            $memberId !== null ? ($installments[$memberId] ?? []) : [],
        );

        $inWindow = array_values(array_filter(
            $candidates,
            fn (array $p) => $p['at']->greaterThanOrEqualTo($b['first']) && $p['at']->lessThanOrEqualTo($b['end']),
        ));

        $revenue = round(array_sum(array_column($inWindow, 'amount')), 2);

        // Sin dinero no hay conversión: un cobro de $0 no es una venta.
        if ($inWindow === [] || $revenue <= 0) {
            return $record;
        }

        usort($inWindow, fn (array $x, array $y) => [$x['at'], $x['type'], $x['id']] <=> [$y['at'], $y['type'], $y['id']]);

        // ¿Había pagado algo ANTES de escribir? Entonces ya era cliente.
        $before = ($userId !== null && isset($firstPayment[$userId]) && $firstPayment[$userId]->lessThan($b['first']))
            || ($memberId !== null && isset($firstInstallment[$memberId]) && $firstInstallment[$memberId]->lessThan($b['first']));

        $record['kind'] = $before ? self::KIND_RENEWAL : self::KIND_NEW;
        $record['payments'] = array_map(fn (array $p) => [
            'type' => $p['type'],
            'id' => $p['id'],
            'amount' => $p['amount'],
            'at' => $p['at']->toIso8601String(),
        ], $inWindow);
        $record['revenue'] = $revenue;

        return $record;
    }

    /**
     * La persona financiera de una identidad, o null si no hay vínculo.
     *
     * @param  array{user_id: ?int, member_id: ?int, method: string}  $identity
     */
    private function personOf(array $identity): ?string
    {
        if (! in_array($identity['method'], MarketingLeadIdentity::LINKED, true)) {
            return null;
        }

        if ($identity['user_id'] !== null) {
            return 'u:'.$identity['user_id'];
        }

        return $identity['member_id'] !== null ? 'm:'.$identity['member_id'] : null;
    }

    // ── Consultas en lote ───────────────────────────────────────────────────

    /**
     * Pagos válidos de estos usuarios en [desde, hasta], por usuario.
     *
     * @param  list<int>  $userIds
     * @return array<int, list<array{type: string, id: int, amount: float, at: CarbonImmutable}>>
     */
    private function paymentsBetween(array $userIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        foreach (array_chunk($userIds, self::CHUNK) as $ids) {
            $rows = $this->validPayments()
                ->whereIn('payments.user_id', $ids)
                ->whereRaw(self::PAYMENT_DATE.' >= ?', [$from])
                ->whereRaw(self::PAYMENT_DATE.' <= ?', [$to])
                ->toBase()
                ->select(['payments.id', 'payments.user_id', 'payments.amount'])
                ->selectRaw(self::PAYMENT_DATE.' as cobrado_en')
                ->get();

            foreach ($rows as $r) {
                $out[(int) $r->user_id][] = [
                    'type' => self::TYPE_PAYMENT,
                    'id' => (int) $r->id,
                    'amount' => round((float) $r->amount, 2),
                    'at' => CarbonImmutable::parse((string) $r->cobrado_en, 'UTC'),
                ];
            }
        }

        return $out;
    }

    /**
     * Abonos aplicados a cuentas del gimnasio de estas fichas en [desde, hasta].
     *
     * @param  list<int>  $memberIds
     * @return array<int, list<array{type: string, id: int, amount: float, at: CarbonImmutable}>>
     */
    private function installmentsBetween(array $memberIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        foreach (array_chunk($memberIds, self::CHUNK) as $ids) {
            $rows = $this->gymInstallments($ids)
                ->where('receivable_payments.created_at', '>=', $from)
                ->where('receivable_payments.created_at', '<=', $to)
                ->select(['receivable_payments.id', 'receivable_payments.amount', 'receivable_payments.created_at', 'receivables.member_id'])
                ->get();

            foreach ($rows as $r) {
                $out[(int) $r->member_id][] = [
                    'type' => self::TYPE_INSTALLMENT,
                    'id' => (int) $r->id,
                    'amount' => round((float) $r->amount, 2),
                    'at' => CarbonImmutable::parse((string) $r->created_at, 'UTC'),
                ];
            }
        }

        return $out;
    }

    /**
     * El primer pago válido de cada usuario, de toda su historia.
     *
     * @param  list<int>  $userIds
     * @return array<int, CarbonImmutable>
     */
    private function firstPaymentAt(array $userIds): array
    {
        $out = [];
        foreach (array_chunk($userIds, self::CHUNK) as $ids) {
            $rows = $this->validPayments()
                ->whereIn('payments.user_id', $ids)
                ->toBase()
                ->select('payments.user_id')
                ->selectRaw('MIN('.self::PAYMENT_DATE.') as primero')
                ->groupBy('payments.user_id')
                ->get();

            foreach ($rows as $r) {
                if ($r->primero !== null) {
                    $out[(int) $r->user_id] = CarbonImmutable::parse((string) $r->primero, 'UTC');
                }
            }
        }

        return $out;
    }

    /**
     * El primer abono aplicado de cada ficha, de toda su historia.
     *
     * @param  list<int>  $memberIds
     * @return array<int, CarbonImmutable>
     */
    private function firstInstallmentAt(array $memberIds): array
    {
        $out = [];
        foreach (array_chunk($memberIds, self::CHUNK) as $ids) {
            $rows = $this->gymInstallments($ids)
                ->select('receivables.member_id')
                ->selectRaw('MIN(receivable_payments.created_at) as primero')
                ->groupBy('receivables.member_id')
                ->get();

            foreach ($rows as $r) {
                if ($r->primero !== null) {
                    $out[(int) $r->member_id] = CarbonImmutable::parse((string) $r->primero, 'UTC');
                }
            }
        }

        return $out;
    }

    /**
     * Pagos que son dinero (sin el asiento de los plazos), están pagados y
     * tienen importe. Vale para la ventana y para «ya era cliente».
     */
    private function validPayments(): Builder
    {
        $pagados = PaymentController::PAID_STATUSES;

        return $this->cash->cashPayments()
            ->whereRaw('LOWER(payments.status) IN ('.ReportSql::marks($pagados).')', $pagados)
            ->where('payments.amount', '>', 0);
    }

    /**
     * Abonos aplicados, con importe, de las cuentas del GIMNASIO de estas fichas.
     * Una cuenta de productos es un fiado de cafetería, y la tienda queda fuera
     * (D2). La caja la decide CashReceipts; el join solo trae la ficha de la
     * cuenta.
     *
     * @param  list<int>  $memberIds
     */
    private function gymInstallments(array $memberIds): \Illuminate\Database\Query\Builder
    {
        return $this->cash->appliedInstallments(CashShiftType::GYM)
            ->where('receivable_payments.amount', '>', 0)
            ->toBase()
            ->join('receivables', 'receivables.id', '=', 'receivable_payments.receivable_id')
            ->whereIn('receivables.member_id', $memberIds);
    }
}

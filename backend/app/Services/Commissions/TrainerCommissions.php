<?php

namespace App\Services\Commissions;

use App\Enums\CashShiftType;
use App\Models\Admin;
use App\Models\Receivable;
use App\Models\TrainerCommissionAgreement;
use App\Models\TrainerCommissionCharge;
use App\Services\Billing\Money;
use App\Services\Caja\CashShiftService;
use App\Services\Caja\ReceivableService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * EL PISO DE LOS ENTRENADORES: pactarlo, cobrarlo y saber a quién le falta.
 *
 * Tres decisiones gobiernan esta clase, y conviene leerlas antes de tocarla.
 *
 * 1. EL DINERO VIVE EN LAS CUENTAS POR COBRAR, NO AQUÍ. Cada mes cobrado abre
 *    una deuda de la caja del GIMNASIO con el mecanismo que ya existía: tiene
 *    abonos, plazos, anulación con motivo y aparece en Pagos y en los informes
 *    del gimnasio sin tocar una línea de ellos. Esta clase no guarda saldos;
 *    los pregunta. Dos números que pueden discrepar es, con dinero, el peor
 *    error que se puede cometer de entrada.
 *
 * 2. NADA SE GENERA SOLO. El panel del mes enseña a quién le falta; abrir la
 *    deuda es siempre un acto de alguien, con su nombre. Una deuda automática
 *    a nombre de un entrenador que ya no trabaja aquí es una reclamación que
 *    nadie pactó.
 *
 * 3. LO QUE SE COBRÓ QUEDA CONGELADO. El importe, quién lo asumía y a quién se
 *    le reclamó se copian en el cobro del mes. Si en noviembre se renegocia,
 *    octubre siguió costando lo que costó.
 */
class TrainerCommissions
{
    public function __construct(
        private readonly ReceivableService $receivables,
        private readonly CashShiftService $shifts,
    ) {}

    /**
     * EL PANEL DEL MES: cada acuerdo vigente con lo que pasó en ese mes.
     *
     * Es la pantalla entera, resuelta de una vez. Se trae todo en tres
     * consultas y no una por fila: con veinte entrenadores y sus clientes, una
     * consulta por acuerdo es lo que convierte una pantalla en una espera.
     *
     * @return array<string, mixed>
     */
    public function board(?CarbonImmutable $mes = null): array
    {
        $periodo = ($mes ?? CarbonImmutable::now())->startOfMonth();

        /** @var Collection<int, TrainerCommissionAgreement> $acuerdos */
        $acuerdos = TrainerCommissionAgreement::query()
            ->where('active', true)
            ->with(['trainer:id,full_name,document', 'member:id,full_name,document_number'])
            ->get();

        $cobros = TrainerCommissionCharge::query()
            ->whereIn('agreement_id', $acuerdos->pluck('id')->all())
            ->whereDate('period', $periodo->toDateString())
            ->with('receivable.payments')
            ->get()
            ->keyBy('agreement_id');

        $filas = $acuerdos->map(function (TrainerCommissionAgreement $a) use ($cobros, $periodo): array {
            $cobro = $cobros->get($a->id);

            return [
                'agreement_id' => $a->id,
                'trainer' => ['id' => $a->trainer_id, 'name' => $a->trainer?->full_name],
                'member' => ['id' => $a->member_id, 'name' => $a->member?->full_name],
                'amount' => (float) $a->amount,
                'payer' => $a->payer,
                'payer_label' => $a->payerLabel(),
                'blocks_app' => (bool) $a->blocks_app,
                'period' => $periodo->toDateString(),
                'charge' => $cobro ? $this->chargeArray($cobro) : null,
                'state' => $this->stateOf($cobro),
            ];
        })->values()->all();

        return [
            'period' => $periodo->toDateString(),
            'rows' => $filas,
            'totals' => $this->totals($filas),
        ];
    }

    /**
     * Cobra el mes de un acuerdo.
     *
     * Abre la deuda SIEMPRE —es el asiento de que ese mes se reclamó— y la
     * salda en el acto si la persona está pagando ahora. Las dos cosas en una
     * sola operación porque en el mostrador son una sola: «vengo a pagar
     * octubre».
     *
     * @param  string|null  $method  Medio de pago si paga ahora; null deja la deuda abierta.
     * @return array<string, mixed>
     */
    public function charge(
        TrainerCommissionAgreement $acuerdo,
        CarbonImmutable $mes,
        ?string $method = null,
        ?Admin $actor = null,
        ?CarbonImmutable $vence = null,
        ?float $importe = null,
        ?string $notas = null,
    ): array {
        $periodo = $mes->startOfMonth();
        // El importe del acuerdo, salvo que se corrija en este mes concreto:
        // un mes a medias, un descuento puntual. Lo que se decida queda
        // congelado en el cobro y no toca el trato.
        $total = Money::fromAmount($importe ?? (float) $acuerdo->amount);

        if (! $total->isPositive()) {
            throw new RuntimeException('El valor del piso tiene que ser mayor que cero.');
        }

        if ($this->chargeFor($acuerdo, $periodo)) {
            throw new RuntimeException(
                'Ese mes ya está registrado para este acuerdo. Si hay que corregirlo, anula la deuda.'
            );
        }

        // SI VIENE A PAGAR, LA CAJA SE EXIGE ANTES DE ESCRIBIR NADA.
        //
        // Esto se escribió primero al revés: se abría la deuda y se intentaba
        // saldarla después, para no perder el asiento de que el mes se había
        // reclamado. Pero el caso real no es ese. Quien pulsa «pagó octubre»
        // tiene el dinero delante; si falla, lo que queda es una deuda abierta
        // a nombre de alguien que acaba de pagar en efectivo, y el mes que
        // viene se le reclama otra vez.
        //
        // O entra el dinero y queda saldado, o no pasa nada y se dice por qué.
        if ($method !== null) {
            $this->shifts->requireOpen(CashShiftType::GYM);
        }

        $cobro = DB::transaction(function () use ($acuerdo, $periodo, $total, $actor, $vence, $notas) {
            $deuda = $this->receivables->create(
                $acuerdo->debtorType(),
                $acuerdo->debtorId(),
                // Caja del GIMNASIO: esto es ingreso del gimnasio, no de la
                // tienda. Es lo que lo hace aparecer en Pagos y no en la caja
                // de productos.
                CashShiftType::GYM,
                $this->concept($acuerdo, $periodo),
                $total,
                $actor,
                null,
                $notas,
                $vence,
                // Solo castiga si el trato lo dice, y solo tiene efecto cuando
                // el deudor es el socio: un entrenador no tiene app que perder.
                (bool) $acuerdo->blocks_app,
            );

            return TrainerCommissionCharge::create([
                'agreement_id' => $acuerdo->id,
                'period' => $periodo->toDateString(),
                'amount' => $total->toDatabase(),
                'payer' => $acuerdo->payer,
                'debtor_type' => $acuerdo->debtorType()->value,
                'debtor_id' => $acuerdo->debtorId(),
                'receivable_id' => $deuda->id,
                'created_by' => $actor?->id,
                'created_by_name' => $actor?->name,
            ]);
        });

        if ($method !== null) {
            $this->receivables->settle(
                $cobro->receivable,
                $total,
                $method,
                $actor,
                null,
                null,
                $notas,
            );
        }

        return $this->chargeArray($cobro->fresh(['receivable.payments']));
    }

    /**
     * El histórico de un acuerdo: todos sus meses, del más reciente al más
     * antiguo. Es lo que contesta «¿desde cuándo viene pagando?».
     *
     * @return list<array<string, mixed>>
     */
    public function history(TrainerCommissionAgreement $acuerdo, int $limite = 24): array
    {
        return TrainerCommissionCharge::query()
            ->where('agreement_id', $acuerdo->id)
            ->with('receivable.payments')
            ->orderByDesc('period')
            ->limit($limite)
            ->get()
            ->map(fn (TrainerCommissionCharge $c) => $this->chargeArray($c))
            ->all();
    }

    /** El cobro de un mes concreto, si ya existe. */
    public function chargeFor(TrainerCommissionAgreement $acuerdo, CarbonImmutable $mes): ?TrainerCommissionCharge
    {
        return TrainerCommissionCharge::query()
            ->where('agreement_id', $acuerdo->id)
            ->whereDate('period', $mes->startOfMonth()->toDateString())
            ->with('receivable.payments')
            ->first();
    }

    // ────────────────────────────────────────────────────────────────────────

    /**
     * Lo que se lee en Pagos y en la lista de deudas.
     *
     * Tiene que decir las cuatro cosas que alguien necesita para entender un
     * apunte suelto meses después: qué es, de quién, por quién y de cuándo.
     */
    public function concept(TrainerCommissionAgreement $acuerdo, CarbonImmutable $mes): string
    {
        $entrenador = $acuerdo->trainer?->full_name ?? 'Entrenador';
        $cliente = $acuerdo->member?->full_name ?? 'Cliente';

        $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
            'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        return 'Piso de entrenador · '.$entrenador.' → '.$cliente
            .' · '.$meses[(int) $mes->month].' de '.$mes->year;
    }

    /**
     * El estado de un mes, dicho como lo diría recepción.
     *
     * Se deriva de la deuda, nunca se guarda: la deuda es la que sabe si se
     * abonó, si se anuló o si se pasó la fecha.
     */
    private function stateOf(?TrainerCommissionCharge $cobro): string
    {
        if (! $cobro) {
            return 'pending';          // nadie lo ha registrado todavía
        }

        $deuda = $cobro->receivable;

        if (! $deuda || $deuda->isCancelled()) {
            return 'cancelled';
        }

        if ($deuda->balance()->isZero()) {
            return 'paid';
        }

        $vence = $deuda->due_at;

        return $vence && CarbonImmutable::parse($vence)->startOfDay()->isPast()
            ? 'overdue'
            : 'open';
    }

    /** @return array<string, mixed> */
    private function chargeArray(TrainerCommissionCharge $cobro): array
    {
        $deuda = $cobro->receivable;
        $saldo = $deuda && ! $deuda->isCancelled() ? $deuda->balance()->toFloat() : 0.0;

        return [
            'id' => $cobro->id,
            'period' => CarbonImmutable::parse($cobro->period)->toDateString(),
            'period_label' => $cobro->periodLabel(),
            'amount' => (float) $cobro->amount,
            'paid' => max(0.0, (float) $cobro->amount - $saldo),
            'balance' => $saldo,
            'payer' => $cobro->payer,
            'state' => $this->stateOf($cobro),
            'receivable_id' => $cobro->receivable_id,
            'due_at' => $deuda?->due_at?->toDateString(),
            'by' => $cobro->created_by_name,
            'created_at' => $cobro->created_at?->toIso8601String(),
        ];
    }

    /**
     * El resumen del mes. Lo que se mira primero al abrir la pantalla: cuánto
     * hay pactado, cuánto entró y cuánto falta.
     *
     * @param  list<array<string, mixed>>  $filas
     * @return array<string, mixed>
     */
    private function totals(array $filas): array
    {
        $pactado = 0.0;
        $cobrado = 0.0;
        $pendiente = 0.0;
        $sinRegistrar = 0;
        $vencidos = 0;

        foreach ($filas as $fila) {
            $pactado += (float) $fila['amount'];
            $cobro = $fila['charge'];

            if ($cobro === null) {
                $sinRegistrar++;

                continue;
            }
            if ($fila['state'] === 'cancelled') {
                continue;
            }

            $cobrado += (float) $cobro['paid'];
            $pendiente += (float) $cobro['balance'];
            if ($fila['state'] === 'overdue') {
                $vencidos++;
            }
        }

        return [
            'agreed' => $pactado,
            'collected' => $cobrado,
            'outstanding' => $pendiente,
            'unregistered' => $sinRegistrar,
            'overdue' => $vencidos,
        ];
    }
}

<?php

namespace App\Services\Commissions;

use App\Enums\CashShiftType;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\Trainer;
use App\Models\TrainerCommissionAgreement;
use App\Models\TrainerCommissionCharge;
use App\Services\Audit\AuditTrail;
use App\Services\Billing\Money;
use App\Services\Caja\CashShiftService;
use App\Services\Caja\ReceivableService;
use App\Services\Payments\MembershipPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
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
    /** Lo que se usa si el acuerdo no dice otra cosa. */
    public const DEFAULT_CYCLE_DAYS = 30;

    /** El mismo texto venga la carrera por donde venga (ver createAgreement()). */
    public const DUPLICATE_AGREEMENT = 'Ya hay un acuerdo vigente entre ese entrenador y ese cliente. Edítalo en vez de crear otro.';

    /** Igual: lo dice la comprobación rápida y la que se hace bajo cerrojo (charge()). */
    public const MONTH_ALREADY_CHARGED = 'Ese mes ya está cobrado para este acuerdo. Si hay que corregirlo, anúlalo primero.';

    /** Ventana por defecto del seguimiento: «próximo a vencer» es dentro de una semana. */
    public const FOLLOW_UP_WINDOW = 7;

    /** Los estados del seguimiento de vencimientos, en el orden en que se atienden. */
    public const DUE_STATES = ['vencido', 'vence_hoy', 'proximo', 'sin_registrar', 'al_dia', 'inactivo'];

    public function __construct(
        private readonly ReceivableService $receivables,
        private readonly CashShiftService $shifts,
        private readonly TrainerCommissionAccess $access,
    ) {}

    /**
     * EL PANEL DEL MES: cada acuerdo en vigor ese mes, y cada acuerdo que tuvo
     * un cobro vigente en ese mes aunque hoy esté terminado, con lo que pasó.
     *
     * Es la pantalla entera, resuelta de una vez. Se trae todo en un puñado de
     * consultas y no una por fila: con veinte entrenadores y sus clientes, una
     * consulta por acuerdo es lo que convierte una pantalla en una espera.
     *
     * @return array<string, mixed>
     */
    public function board(?CarbonImmutable $mes = null): array
    {
        $periodo = ($mes ?? CarbonImmutable::now())->startOfMonth();

        // Los cobros del mes van PRIMERO: deciden qué acuerdos terminados
        // siguen saliendo. Puede haber VARIAS filas del mismo mes: las anuladas
        // se conservan para que el histórico pueda contestar «¿se le cobró
        // octubre?» con «sí, y se anuló porque…». Se agrupan y abajo se elige
        // la que vale.
        $cobros = TrainerCommissionCharge::query()
            ->whereDate('period', $periodo->toDateString())
            ->with($this->chargeRelations())
            ->orderBy('id')
            ->get()
            ->groupBy('agreement_id');

        // EL HISTÓRICO SE CONSERVA. Un acuerdo terminado, o fuera de vigencia,
        // que tiene un cobro vigente en ese mes sigue saliendo en ese mes: ese
        // dinero se pactó y se cobró, y esconderlo dejaría el mes sin explicar.
        // Uno con solo cobros anulados no sale: no hay nada que ver ni cobrar.
        $conCobroVigente = $cobros
            ->filter(fn (Collection $delMes) => $delMes->contains(fn (TrainerCommissionCharge $c) => ! $this->isCancelled($c)))
            ->keys()
            ->map(fn ($id) => (int) $id)
            ->all();

        /** @var Collection<int, TrainerCommissionAgreement> $acuerdos */
        $acuerdos = TrainerCommissionAgreement::query()
            ->where(fn ($q) => $q->where('active', true)->orWhereIn('id', $conCobroVigente))
            ->with(['trainer:id,full_name,document', 'member:id,full_name,document_number'])
            ->orderBy('id')
            ->get()
            // La vigencia la decide el modelo y no un WHERE: es UNA regla, la
            // misma con la que charge() se niega a cobrar fuera de ella.
            ->filter(fn (TrainerCommissionAgreement $a) => $a->inForceDuring($periodo)
                || in_array((int) $a->id, $conCobroVigente, true))
            ->values();

        $recibos = $this->receiptNumbersOf($cobros->flatten(1));

        $filas = $acuerdos->map(function (TrainerCommissionAgreement $a) use ($cobros, $periodo, $recibos): array {
            /** @var Collection<int, TrainerCommissionCharge> $delMes */
            $delMes = $cobros->get($a->id) ?? collect();

            // EL QUE MANDA ES EL VIGENTE. Si todos están anulados, el mes
            // vuelve a estar por cobrar: eso es para lo que sirve anular.
            $cobro = $delMes->first(fn (TrainerCommissionCharge $c) => ! $this->isCancelled($c));
            $anulados = $delMes->filter(fn (TrainerCommissionCharge $c) => $this->isCancelled($c))->count();

            return [
                'agreement_id' => $a->id,
                'trainer' => ['id' => $a->trainer_id, 'name' => $a->trainer?->full_name],
                'member' => ['id' => $a->member_id, 'name' => $a->member?->full_name],
                'amount' => (float) $a->amount,
                'cycle_days' => (int) ($a->cycle_days ?? self::DEFAULT_CYCLE_DAYS),
                'payer' => $a->payer,
                'payer_label' => $a->payerLabel(),
                'blocks_app' => (bool) $a->blocks_app,
                // La vigencia del trato y si ese mes cae dentro. Una fila fuera
                // de vigencia solo está por su histórico: se ve, no se cobra.
                'in_force' => $a->inForceDuring($periodo),
                'starts_on' => $a->starts_on?->toDateString(),
                'ends_on' => $a->ends_on?->toDateString(),
                'period' => $periodo->toDateString(),
                'charge' => $cobro ? $this->chargeArray($cobro, $recibos) : null,
                'state' => $this->stateOf($cobro),
                // Para poder decir «hubo un cobro anulado» sin esconderlo ni
                // dejar la fila bloqueada por él.
                'cancelled_attempts' => $anulados,
            ];
        })->values()->all();

        return [
            'period' => $periodo->toDateString(),
            'rows' => $filas,
            'totals' => $this->totals($filas),
        ];
    }

    /**
     * PACTA EL PISO de un cliente con un entrenador.
     *
     * Las mismas reglas que `POST /api/admin/commissions/agreements`, que ahora
     * es quien la llama; vive aquí para que un procedimiento (la reclasificación
     * de un cobro) pacte exactamente igual que el mostrador y no con una copia:
     *  · si ya hay un acuerdo ACTIVO entre los dos → RuntimeException;
     *  · si lo hubo y terminó → se REABRE esa fila (el índice único es por
     *    pareja: el trato con esta persona es el mismo, se retomó);
     *  · y la traza de siempre en AuditTrail, firmada por `$actor`.
     *
     * Bajo cerrojo de la fila que ya existiera, y la traza en la misma
     * transacción: dos altas a la vez no reabren dos veces, y sin traza no hay
     * acuerdo.
     *
     * @param  array{amount: mixed, payer: string, cycle_days?: ?int, blocks_app?: ?bool, starts_on?: ?string, ends_on?: ?string, notes?: ?string}  $data
     *
     * @throws RuntimeException si ya hay un acuerdo vigente entre los dos
     */
    public function createAgreement(Trainer $trainer, Member $member, array $data, ?Admin $actor = null): TrainerCommissionAgreement
    {
        try {
            return DB::transaction(function () use ($trainer, $member, $data, $actor): TrainerCommissionAgreement {
                $existente = TrainerCommissionAgreement::query()
                    ->where('trainer_id', $trainer->id)
                    ->where('member_id', $member->id)
                    ->lockForUpdate()
                    ->first();

                if ($existente && $existente->active) {
                    throw new RuntimeException(self::DUPLICATE_AGREEMENT);
                }

                // Lo que no llegue no se toca: al reabrir, unas fechas o una
                // nota que no se mandan se quedan como estaban. Es lo que hacía
                // el alta del controlador, y no se cambia aquí.
                $acuerdo = $existente ?: new TrainerCommissionAgreement;
                $acuerdo->fill(array_merge(
                    Arr::only($data, ['amount', 'payer', 'cycle_days', 'blocks_app', 'starts_on', 'ends_on', 'notes']),
                    [
                        'trainer_id' => $trainer->id,
                        'member_id' => $member->id,
                        'cycle_days' => (int) ($data['cycle_days'] ?? self::DEFAULT_CYCLE_DAYS),
                        'blocks_app' => (bool) ($data['blocks_app'] ?? false),
                        'active' => true,
                        'ended_at' => null,
                        'ended_by_name' => null,
                        'created_by' => $acuerdo->created_by ?? $actor?->id,
                        'created_by_name' => $acuerdo->created_by_name ?? $actor?->name,
                    ],
                ));
                $acuerdo->save();
                $acuerdo->setRelation('trainer', $trainer)->setRelation('member', $member);

                $this->auditAgreement($acuerdo, $actor, 'create', 'Pactó el piso de un cliente personalizado');

                return $acuerdo;
            }, 3);
        } catch (UniqueConstraintViolationException) {
            // Sin fila previa no hay nada que bloquear: si otra alta del mismo
            // par insertó entre la lectura y este INSERT, el índice único la
            // frena y es exactamente el caso del acuerdo duplicado.
            throw new RuntimeException(self::DUPLICATE_AGREEMENT);
        }
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
        ?int $dias = null,
        ?CarbonImmutable $desde = null,
    ): array {
        $periodo = $mes->startOfMonth();

        // FUERA DE VIGENCIA NO SE COBRA. Ni un mes antes de que empezara el
        // trato ni uno después de que acabara, ni ninguno de un acuerdo
        // terminado: sería reclamar un piso que nadie pactó para ese mes. Antes
        // de escribir nada, y con la misma regla con la que el panel decide
        // qué filas salen (TrainerCommissionAgreement::inForceDuring).
        if (! $acuerdo->inForceDuring($periodo)) {
            throw new RuntimeException($this->outOfForceMessage($acuerdo, $periodo));
        }

        // EL TRAMO QUE CUBRE. Por defecto arranca el día en que el trato está en
        // vigor ese mes —el 1, o el día en que empezó si fue a mitad de mes— y
        // dura lo que diga el trato; las dos cosas se pueden corregir en este
        // cobro —entró a mitad de mes, se le dan quince días— sin tocar el
        // acuerdo.
        $cubreDias = max(1, (int) ($dias ?? $acuerdo->cycle_days ?? self::DEFAULT_CYCLE_DAYS));
        $cubreDesde = $desde ?? $acuerdo->coverageStartFor($periodo);
        $cubreHasta = $cubreDesde->addDays($cubreDias - 1);
        // El importe del acuerdo, salvo que se corrija en este mes concreto:
        // un mes a medias, un descuento puntual. Lo que se decida queda
        // congelado en el cobro y no toca el trato.
        $total = Money::fromAmount($importe ?? (float) $acuerdo->amount);

        if (! $total->isPositive()) {
            throw new RuntimeException('El valor del piso tiene que ser mayor que cero.');
        }

        // LA INVARIANTE ES «UN COBRO VIGENTE POR MES», no «una fila por mes».
        // Un mes anulado vuelve a estar libre: anular existe para corregir y
        // registrar bien, y si bloqueara el mes no serviría de nada. Aquí se
        // mira deprisa; la comprobación que cuenta es la de dentro, bajo cerrojo.
        if ($this->chargeFor($acuerdo, $periodo)) {
            throw new RuntimeException(self::MONTH_ALREADY_CHARGED);
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

        $cobro = DB::transaction(function () use ($acuerdo, $periodo, $total, $actor, $vence, $notas, $cubreDesde, $cubreHasta, $cubreDias) {
            // EL MES, DE UNO EN UNO. Desde que se permitió volver a cobrar un
            // mes anulado (2026_10_09_000005) la base ya no tiene índice único
            // por acuerdo y mes: la invariante vive solo aquí. Sin cerrojo, dos
            // «cobrar» a la vez —doble clic, dos recepcionistas— pasaban los dos
            // la comprobación de arriba y dejaban dos cobros vigentes (y, con
            // medio de pago, dos abonos). La fila del acuerdo se bloquea y el
            // mes se vuelve a mirar ya bloqueado: el segundo espera y lo ve
            // cobrado. Releída, además, por si el trato terminó entretanto.
            $vigente = TrainerCommissionAgreement::query()->whereKey($acuerdo->id)->lockForUpdate()->first();
            if ($vigente === null || ! $vigente->inForceDuring($periodo)) {
                throw new RuntimeException($this->outOfForceMessage($vigente ?? $acuerdo, $periodo));
            }
            if ($this->chargeFor($acuerdo, $periodo)) {
                throw new RuntimeException(self::MONTH_ALREADY_CHARGED);
            }

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
                'covers_from' => $cubreDesde->toDateString(),
                'covers_to' => $cubreHasta->toDateString(),
                'cycle_days' => $cubreDias,
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

        // Pagado el piso, el entrenador entra hasta que se le acabe. Se
        // recalcula siempre —también cuando NO se pagó— porque abrir una deuda
        // nueva no concede nada y conviene que eso se vea reflejado.
        if ($acuerdo->trainer) {
            $this->access->sync($acuerdo->trainer);
        }

        return $this->chargeArray($cobro->fresh($this->chargeRelations()));
    }

    /**
     * CORRE EL TRAMO DE UN COBRO YA REGISTRADO. No mueve dinero.
     *
     * El caso es de todos los meses: se pagó el mes, pero una semana no
     * entrenaron —el entrenador se enfermó, el cliente viajó— y los días
     * pagados no se gastaron. Hasta ahora solo se podía corregir al cobrar el
     * SIGUIENTE mes, empezándolo más tarde; el que ya estaba registrado no se
     * tocaba, y la única salida era anularlo y volver a cobrarlo, que mueve
     * dinero que nadie movió y ensucia el arqueo de dos turnos.
     *
     * Esto cambia solo FECHAS: desde cuándo cuenta y cuántos días dura. El
     * importe, el pagador y los abonos se quedan como están, porque no ha
     * pasado nada con el dinero.
     *
     * Y SE RECALCULA EL ACCESO, que es lo que de verdad cambia: alargar el
     * tramo le alarga al entrenador los días que puede entrar.
     *
     * @return array<string, mixed>
     */
    public function moveCoverage(
        TrainerCommissionCharge $cobro,
        ?CarbonImmutable $desde,
        ?int $dias,
        string $motivo,
        ?Admin $actor = null,
    ): array {
        if ($this->isCancelled($cobro)) {
            throw new RuntimeException('Ese mes está anulado: no hay nada que correr.');
        }

        $nuevoDesde = $desde ?? ($cobro->covers_from
            ? CarbonImmutable::parse($cobro->covers_from->format('Y-m-d'))
            : CarbonImmutable::parse($cobro->period->format('Y-m-d')));

        $nuevosDias = max(1, (int) ($dias ?? $cobro->cycle_days ?? self::DEFAULT_CYCLE_DAYS));

        $antes = [
            'from' => $cobro->covers_from?->toDateString(),
            'to' => $cobro->covers_to?->toDateString(),
            'days' => $cobro->cycle_days,
        ];

        $cobro->update([
            'covers_from' => $nuevoDesde->toDateString(),
            'covers_to' => $nuevoDesde->addDays($nuevosDias - 1)->toDateString(),
            'cycle_days' => $nuevosDias,
        ]);

        // La nota se añade a la deuda, no se pisa: lo que explicaba el cobro
        // original sigue siendo cierto.
        $cobro->receivable?->update([
            'notes' => trim(($cobro->receivable->notes ? $cobro->receivable->notes."\n" : '')
                .'Tramo corregido ('.($antes['from'] ?? '—').' → '.($antes['to'] ?? '—').'): '.$motivo),
        ]);

        if ($cobro->agreement?->trainer) {
            $this->access->sync($cobro->agreement->trainer);
        }

        return $this->chargeArray($cobro->fresh($this->chargeRelations()));
    }

    /**
     * ANULA UN COBRO: el mes no debía cobrarse, o se cobró mal.
     *
     * Deshace las dos cosas que ese cobro produjo, en este orden:
     *
     *   1. el DINERO, si se había pagado: cada abono se revierte con motivo,
     *      y al revertirse sale del arqueo del turno en que entró. No se borra
     *      nada: queda el abono y queda su reverso;
     *   2. la DEUDA, que se anula con motivo y autor.
     *
     * El cobro del mes tampoco se borra. Queda apuntado a una deuda anulada,
     * que es lo que permite contestar «¿se le cobró octubre?» con un «sí, y se
     * anuló el día tal porque…», en vez de con un silencio.
     *
     * Y el ACCESO se recalcula: si ese mes era el que le tenía la puerta
     * abierta al entrenador, deja de tenerla.
     *
     * @return array<string, mixed>
     */
    public function cancelCharge(TrainerCommissionCharge $cobro, string $motivo, ?Admin $actor = null, bool $compensaReclasificacion = false): array
    {
        $deuda = $cobro->receivable;

        if (! $deuda) {
            throw new RuntimeException('Ese cobro ya no tiene deuda asociada.');
        }

        if ($deuda->isCancelled()) {
            throw new RuntimeException('Ese mes ya estaba anulado.');
        }

        // UN MES QUE LLEGÓ POR RECLASIFICACIÓN NO SE ANULA POR DESCUIDO. Su
        // dinero es el de un cobro que ya está anulado: anular este mes, sin
        // reconfirmar a la vez aquel cobro, lo haría desaparecer de la caja y
        // de todos los informes. Solo se admite como paso 1 de la compensación
        // documentada (docs/ops/RECLASIFICACION-DE-COBROS.md §10), que lo
        // declara explícitamente; el CRM nunca lo hace.
        $reclasificacion = $cobro->reclassification()->with('payment:id,status')->first();
        if ($reclasificacion !== null && ! $compensaReclasificacion
            && in_array(strtolower((string) $reclasificacion->payment?->status), ['cancelled', 'canceled'], true)) {
            throw new RuntimeException(
                'Este mes se registró al reclasificar el cobro #'.$reclasificacion->payment_id
                .': su dinero es el de ese cobro, que está anulado. No se anula desde aquí; para corregirlo'
                .' sigue la compensación documentada (anular este mes y reconfirmar el cobro original, juntos).'
            );
        }

        // El dinero primero. Revertir después de anular la deuda dejaría un
        // abono aplicado sobre algo que ya no se debe.
        //
        // Todo o nada: anular un abono de un turno ya cerrado exige además
        // administrar la caja (403 closed_shift_requires_manage), y sin esta
        // transacción un mes con dos abonos podía quedar con uno anulado y el
        // otro no.
        DB::transaction(function () use ($deuda, $motivo, $actor, $compensaReclasificacion): void {
            foreach ($deuda->payments()->get() as $abono) {
                if ($abono->isApplied()) {
                    $compensaReclasificacion
                        ? $this->receivables->reverseReclassificationCompensation($abono, $motivo, $actor)
                        : $this->receivables->reverse($abono, $motivo, $actor);
                }
            }

            $this->receivables->cancel($deuda->fresh(), $motivo, $actor);
        }, 3);

        if ($cobro->agreement?->trainer) {
            $this->access->sync($cobro->agreement->trainer);
        }

        return $this->chargeArray($cobro->fresh($this->chargeRelations()));
    }

    /**
     * El histórico de un acuerdo: todos sus meses, del más reciente al más
     * antiguo, los anulados incluidos. Contesta «¿desde cuándo viene pagando?»
     * y «¿pagó a tiempo?».
     *
     * Cada mes es el de siempre (chargeArray()) y además:
     *  · `due_date`: el plazo de la deuda, si alguien lo puso (confirmado). Si
     *    no se puso no hay plazo con el que comparar, y lo honrado es decirlo:
     *    `due_date` null y, como referencia, el inicio del tramo marcado como
     *    «no confirmado». Inventar un plazo convertiría cualquier pago en un
     *    retraso;
     *  · `paid_at`: el último abono aplicado, en Bogotá;
     *  · `days_late`: días entre ese pago y el plazo CONFIRMADO, solo si son
     *    más de cero;
     *  · `cancelled`: cuándo, por qué y quién anuló el mes;
     *  · `adjustments`: los movimientos de tramo auditados (moveCoverage()).
     *
     * Solo lee, y con pocas consultas: los meses con su deuda y la suma de sus
     * abonos, los abonos, las constancias de reclasificación, quién anuló, los
     * recibos y la auditoría del acuerdo, una vez cada cosa.
     *
     * @return list<array<string, mixed>>
     */
    public function history(TrainerCommissionAgreement $acuerdo, int $limite = 24): array
    {
        $cobros = TrainerCommissionCharge::query()
            ->where('agreement_id', $acuerdo->id)
            ->with([...$this->chargeRelations(), 'receivable.cancelledBy:id,name'])
            ->orderByDesc('period')
            // Dentro del mismo mes, el último cobro primero: el vigente va
            // delante de los anulados que corrigió.
            ->orderByDesc('id')
            ->limit($limite)
            ->get();

        $recibos = $this->receiptNumbersOf($cobros);
        $ajustes = $this->coverageAdjustments($acuerdo);

        return $cobros
            ->map(fn (TrainerCommissionCharge $c) => $this->historyEntry($c, $recibos, $ajustes))
            ->all();
    }

    /**
     * Un mes del histórico: chargeArray() más el plazo, el pago y lo que le pasó.
     *
     * @param  array<int, string>  $recibos
     * @param  array{by_charge: array<int, list<array<string, mixed>>>, by_period: array<string, list<array<string, mixed>>>}  $ajustes
     * @return array<string, mixed>
     */
    private function historyEntry(TrainerCommissionCharge $cobro, array $recibos, array $ajustes): array
    {
        $fila = $this->chargeArray($cobro, $recibos);
        $deuda = $cobro->receivable;

        $plazo = $deuda?->due_at?->toDateString();
        $abono = $this->lastAppliedPayment($deuda);
        $pagadoEn = $abono?->created_at !== null
            ? CarbonImmutable::parse($abono->created_at)->setTimezone(MembershipPeriod::TZ)
            : null;
        $retraso = $plazo !== null && $pagadoEn !== null
            ? self::daysBetween($plazo, $pagadoEn->toDateString())
            : null;

        return array_merge($fila, [
            'due_date' => $plazo,
            'due_date_confirmed' => $plazo !== null,
            'expected_due_date' => $plazo === null ? $fila['covers_from'] : null,
            'expected_due_date_label' => $plazo === null && $fila['covers_from'] !== null ? 'inicio del tramo, no confirmado' : null,
            'paid_at' => $pagadoEn?->toIso8601String(),
            'days_late' => $retraso !== null && $retraso > 0 ? $retraso : null,
            'cancelled' => $deuda !== null && $deuda->isCancelled() ? [
                'at' => $deuda->cancelled_at?->toIso8601String(),
                'reason' => $deuda->cancellation_reason,
                'by' => $deuda->cancelledBy?->name,
            ] : null,
            // Los que nombran este cobro; los anteriores a que la traza llevara
            // su id, por su mes.
            'adjustments' => $ajustes['by_charge'][(int) $cobro->id]
                ?? $ajustes['by_period'][$fila['period']]
                ?? [],
        ]);
    }

    /** El último abono aplicado de una deuda (por fecha y, a igualdad, por id). */
    private function lastAppliedPayment(?Receivable $deuda): ?ReceivablePayment
    {
        if ($deuda === null) {
            return null;
        }

        $abonos = $deuda->relationLoaded('payments') ? $deuda->payments : $deuda->payments()->get();

        return $abonos
            ->filter(fn (ReceivablePayment $p) => $p->isApplied())
            ->sortBy(fn (ReceivablePayment $p) => sprintf('%020d-%020d', $p->created_at?->getTimestamp() ?? 0, $p->id))
            ->last();
    }

    /**
     * Los movimientos de tramo de un acuerdo, de su auditoría (moveCoverage()
     * firma uno por cada corrección). Los cambios del TRATO van con la misma
     * entidad pero no traen tramo, y se dejan fuera.
     *
     * @return array{by_charge: array<int, list<array<string, mixed>>>, by_period: array<string, list<array<string, mixed>>>}
     */
    private function coverageAdjustments(TrainerCommissionAgreement $acuerdo): array
    {
        $porCobro = [];
        $porMes = [];

        $trazas = AuditLog::query()
            ->where('module', 'Comisiones')
            ->where('entity', 'piso de entrenador')
            ->where('action', 'update')
            ->where('entity_id', (string) $acuerdo->id)
            ->orderBy('id')
            ->get(['id', 'actor_name', 'metadata', 'created_at']);

        foreach ($trazas as $traza) {
            $m = is_array($traza->metadata) ? $traza->metadata : [];
            if (! isset($m['period'], $m['covers_from'])) {
                continue;
            }
            $ajuste = [
                'at' => $traza->created_at?->toIso8601String(),
                'by' => $traza->actor_name,
                'covers_from' => $m['covers_from'],
                'covers_to' => $m['covers_to'] ?? null,
                'reason' => $m['reason'] ?? null,
            ];
            if (isset($m['charge_id'])) {
                $porCobro[(int) $m['charge_id']][] = $ajuste;
            } else {
                $porMes[substr((string) $m['period'], 0, 10)][] = $ajuste;
            }
        }

        return ['by_charge' => $porCobro, 'by_period' => $porMes];
    }

    /**
     * SEGUIMIENTO DE VENCIMIENTOS: hasta cuándo tiene pagado cada acuerdo y qué
     * hay que reclamar. Solo lee. Ver docs/ops/VENCIMIENTOS-PERSONALIZADOS.md.
     *
     * EL MODELO, en cuatro reglas:
     *  · la obligación es mensual —un cobro vigente por acuerdo y mes— y lo que
     *    dura cada cobro es su tramo exacto [covers_from, covers_to];
     *  · el VENCIMIENTO es el último día cubierto por pisos PAGADOS (saldo
     *    cero, reclasificados incluidos), que es también hasta cuándo entra el
     *    entrenador (TrainerCommissionAccess::paidThrough());
     *  · la fecha del pago NUNCA mueve la cobertura: pagar antes o después no
     *    desplaza vencimientos. Solo la mueven el tramo del cobro y
     *    moveCoverage(), que queda auditado. Un pago parcial no cubre nada: es
     *    una deuda registrada, que no es lo mismo que estar vencido;
     *  · «hoy» es el día del gimnasio, en Bogotá.
     *
     * Un número FIJO de consultas para todo el gimnasio, sea cual sea el número
     * de acuerdos y meses: acuerdos, entrenadores, clientes, cobros, sus deudas
     * con la suma de abonos aplicados, los abonos y las constancias de
     * reclasificación (con su recibo, si las hay).
     *
     * @param  CarbonImmutable|null  $hoy  Un INSTANTE; cuenta su día en Bogotá.
     * @return array{summary: array<string, mixed>, rows: list<array<string, mixed>>}
     */
    public function followUp(?CarbonImmutable $hoy = null, int $ventana = self::FOLLOW_UP_WINDOW): array
    {
        $hoy = ($hoy ?? CarbonImmutable::now())->setTimezone(MembershipPeriod::TZ)->startOfDay();
        $ventana = max(1, min(30, $ventana));

        $acuerdos = TrainerCommissionAgreement::query()
            ->with(['trainer:id,full_name', 'member:id,full_name'])
            ->orderBy('id')
            ->get();

        $cobros = TrainerCommissionCharge::query()
            ->whereIn('agreement_id', $acuerdos->modelKeys())
            ->with($this->chargeRelations())
            ->orderBy('period')
            ->orderBy('id')
            ->get()
            ->groupBy('agreement_id');

        $rango = array_flip(self::DUE_STATES);
        $filas = $acuerdos
            ->map(fn (TrainerCommissionAgreement $a) => $this->followUpRow($a, $cobros->get($a->id) ?? collect(), $hoy, $ventana))
            // Lo urgente primero, y dentro de cada estado lo que menos margen
            // tiene: el vencido hace más días, el que vence antes.
            ->sortBy(fn (array $f) => sprintf('%d-%012d-%012d', $rango[$f['due_state']],
                ($f['days_remaining'] ?? 999_999) + 500_000, $f['agreement_id']))
            ->values()
            ->all();

        return ['summary' => $this->followUpSummary($filas, $hoy, $ventana), 'rows' => $filas];
    }

    /**
     * La fila de un acuerdo en el seguimiento.
     *
     * @param  Collection<int, TrainerCommissionCharge>  $cobros  todos los suyos, por mes y por id
     * @return array<string, mixed>
     */
    private function followUpRow(TrainerCommissionAgreement $a, Collection $cobros, CarbonImmutable $hoy, int $ventana): array
    {
        $dia = $hoy->toDateString();

        // PAGADO es el estado `paid` de siempre: deuda viva y saldo cero. Un
        // abono parcial no cubre ni un día.
        $pagados = $cobros->filter(fn (TrainerCommissionCharge $c) => $this->stateOf($c) === 'paid')->values();
        $bloque = self::referenceBlock(self::mergeSpans(
            $pagados->map(fn (TrainerCommissionCharge $c) => self::paidSpan($c, $a))->all()
        ), $dia);

        $hasta = $bloque['to'] ?? null;
        $restantes = $hasta !== null ? self::daysBetween($dia, $hasta) : null;
        $inactivo = ! $a->active || ($a->ends_on !== null && $a->ends_on->toDateString() < $dia);

        $estado = match (true) {
            $inactivo => 'inactivo',
            $restantes === null => 'sin_registrar',
            $restantes > $ventana => 'al_dia',
            $restantes >= 1 => 'proximo',
            $restantes === 0 => 'vence_hoy',
            default => 'vencido',
        };

        $deuda = $this->registeredDebt($cobros, $dia);
        // Vigente HOY: lo que no ha empezado todavía no se reclama.
        $vigenteHoy = ! $inactivo && ($a->starts_on === null || $a->starts_on->toDateString() <= $dia);
        $ultimo = $pagados->last();

        return [
            'agreement_id' => (int) $a->id,
            'trainer' => ['id' => $a->trainer_id, 'name' => $a->trainer?->full_name],
            'member' => ['id' => $a->member_id, 'name' => $a->member?->full_name],
            'amount' => (float) $a->amount,
            'payer' => $a->payer,
            'payer_label' => $a->payerLabel(),
            'cycle_days' => (int) ($a->cycle_days ?? self::DEFAULT_CYCLE_DAYS),
            'starts_on' => $a->starts_on?->toDateString(),
            'ends_on' => $a->ends_on?->toDateString(),
            'active' => (bool) $a->active,
            'last_paid' => $ultimo === null ? null : [
                'charge_id' => (int) $ultimo->id,
                'period' => CarbonImmutable::parse($ultimo->period)->toDateString(),
                'period_label' => $ultimo->periodLabel(),
                'covers_from' => $ultimo->covers_from?->toDateString(),
                'covers_to' => $ultimo->covers_to?->toDateString(),
                'amount' => (float) $ultimo->amount,
                'reclassified_from' => $this->reclassifiedFrom($ultimo),
            ],
            'last_payment' => $this->lastPaymentOf($cobros),
            'covered_until' => $hasta,
            'next_due' => $hasta,
            'days_remaining' => $restantes,
            'days_overdue' => $restantes === null ? null : max(0, -$restantes),
            'due_state' => $estado,
            // ¿Sale de tramos guardados en cobros pagados, o hubo que deducir
            // alguno (cobro antiguo sin tramo)? Sin tramos pagados, false.
            'confirmed' => (bool) ($bloque['confirmed'] ?? false),
            'registered_debt' => $deuda,
            'needs_review' => in_array($estado, ['vencido', 'vence_hoy'], true)
                || ($deuda['overdue'] ?? false)
                || ($estado === 'sin_registrar' && $vigenteHoy),
        ];
    }

    /**
     * El tramo que cubre un cobro pagado, como fechas `Y-m-d`. Un cobro sin
     * tramo guardado (anterior a que existiera) cubre desde el inicio de su mes
     * lo que dure, y se marca como no confirmado.
     *
     * @return array{from: string, to: string, confirmed: bool}
     */
    private static function paidSpan(TrainerCommissionCharge $c, TrainerCommissionAgreement $a): array
    {
        $desde = $c->covers_from?->toDateString();
        $hasta = $c->covers_to?->toDateString();
        $confirmado = $desde !== null && $hasta !== null;
        $dias = max(1, (int) ($c->cycle_days ?? $a->cycle_days ?? self::DEFAULT_CYCLE_DAYS));

        $desde ??= CarbonImmutable::parse($c->period)->toDateString();
        $hasta ??= CarbonImmutable::parse($desde)->addDays($dias - 1)->toDateString();

        return ['from' => $desde, 'to' => $hasta, 'confirmed' => $confirmado];
    }

    /**
     * Los tramos pagados, fusionados cuando se solapan o se tocan: 01-10 → 30-10
     * y 31-10 → 29-11 son un solo bloque, cubierto sin huecos hasta el 29-11.
     *
     * @param  list<array{from: string, to: string, confirmed: bool}>  $tramos
     * @return list<array{from: string, to: string, confirmed: bool}>
     */
    private static function mergeSpans(array $tramos): array
    {
        usort($tramos, fn (array $x, array $y) => [$x['from'], $x['to']] <=> [$y['from'], $y['to']]);

        $bloques = [];
        foreach ($tramos as $t) {
            $i = array_key_last($bloques);
            if ($i !== null && $t['from'] <= CarbonImmutable::parse($bloques[$i]['to'])->addDay()->toDateString()) {
                $bloques[$i]['to'] = max($bloques[$i]['to'], $t['to']);
                $bloques[$i]['confirmed'] = $bloques[$i]['confirmed'] && $t['confirmed'];

                continue;
            }
            $bloques[] = $t;
        }

        return $bloques;
    }

    /**
     * El bloque que manda hoy: el que contiene hoy; si no, el último que
     * terminó antes de hoy (hay un hueco y hoy no está cubierto); si no, el
     * primero futuro (lo pagó por adelantado y aún no empieza).
     *
     * @param  list<array{from: string, to: string, confirmed: bool}>  $bloques  ordenados y sin solapes
     * @return array{from: string, to: string, confirmed: bool}|null
     */
    private static function referenceBlock(array $bloques, string $hoy): ?array
    {
        foreach ($bloques as $b) {
            if ($b['from'] <= $hoy && $hoy <= $b['to']) {
                return $b;
            }
        }

        $pasados = array_values(array_filter($bloques, fn (array $b) => $b['to'] < $hoy));
        if ($pasados !== []) {
            return $pasados[array_key_last($pasados)];
        }

        return array_values(array_filter($bloques, fn (array $b) => $b['from'] > $hoy))[0] ?? null;
    }

    /**
     * La cuenta por cobrar ABIERTA del acuerdo, si la hay: la que vence primero
     * entre las vencidas y, si ninguna lo está, la del mes más antiguo.
     *
     * Deuda registrada NO es vencido: es un mes reclamado que no se ha saldado.
     * Vencida es la que tiene plazo y ya pasó (el día del plazo todavía vale,
     * como en Receivable::isOverdue()). Sin plazo no hay vencimiento que
     * declarar.
     *
     * @param  Collection<int, TrainerCommissionCharge>  $cobros
     * @return array<string, mixed>|null
     */
    private function registeredDebt(Collection $cobros, string $hoy): ?array
    {
        $abiertos = $cobros->filter(function (TrainerCommissionCharge $c): bool {
            $d = $c->receivable;

            return $d !== null && ! $d->isCancelled() && ! $d->balance()->isZero();
        })->values();

        $vencida = fn (TrainerCommissionCharge $c): bool => $c->receivable->due_at !== null
            && $c->receivable->due_at->toDateString() < $hoy;
        $cobro = $abiertos->filter($vencida)
            ->sortBy(fn (TrainerCommissionCharge $c) => $c->receivable->due_at->toDateString())
            ->first() ?? $abiertos->first();

        if ($cobro === null) {
            return null;
        }

        $d = $cobro->receivable;

        return [
            'receivable_id' => (int) $d->id,
            'charge_id' => (int) $cobro->id,
            'period_label' => $cobro->periodLabel(),
            'balance' => $d->balance()->toFloat(),
            'due_at' => $d->due_at?->toDateString(),
            'overdue' => $vencida($cobro),
        ];
    }

    /**
     * El último dinero que entró por este acuerdo: el abono aplicado más
     * reciente de sus meses no anulados. La fecha, en Bogotá: un abono de las
     * 01:43 UTC es del día anterior en el gimnasio.
     *
     * @param  Collection<int, TrainerCommissionCharge>  $cobros
     * @return array<string, mixed>|null
     */
    private function lastPaymentOf(Collection $cobros): ?array
    {
        $abono = $cobros
            ->filter(fn (TrainerCommissionCharge $c) => $c->receivable !== null && ! $c->receivable->isCancelled())
            ->map(fn (TrainerCommissionCharge $c) => $this->lastAppliedPayment($c->receivable))
            ->filter()
            ->sortBy(fn (ReceivablePayment $p) => sprintf('%020d-%020d', $p->created_at?->getTimestamp() ?? 0, $p->id))
            ->last();

        if ($abono === null) {
            return null;
        }

        return [
            'receivable_payment_id' => (int) $abono->id,
            'reference' => self::paymentReference($abono),
            'paid_at' => $abono->created_at !== null
                ? CarbonImmutable::parse($abono->created_at)->setTimezone(MembershipPeriod::TZ)->toIso8601String()
                : null,
            'amount' => (float) $abono->amount,
            'method' => $abono->method,
            'method_label' => self::methodLabel($abono->method),
            'cash_shift_id' => $abono->cash_shift_id !== null ? (int) $abono->cash_shift_id : null,
            'by' => $abono->created_by_name,
        ];
    }

    /**
     * Los recuentos que se miran primero. Describen TODOS los acuerdos: el
     * filtro del endpoint solo recorta las filas.
     *
     * @param  list<array<string, mixed>>  $filas
     * @return array<string, mixed>
     */
    private function followUpSummary(array $filas, CarbonImmutable $hoy, int $ventana): array
    {
        $porEstado = array_fill_keys(self::DUE_STATES, 0);
        $proximos = 0.0;
        $vencidos = 0.0;
        $revisar = 0;

        foreach ($filas as $f) {
            $porEstado[$f['due_state']]++;
            if (in_array($f['due_state'], ['proximo', 'vence_hoy'], true)) {
                $proximos += (float) $f['amount'];
            } elseif ($f['due_state'] === 'vencido') {
                $vencidos += (float) $f['amount'];
            }
            if ($f['needs_review']) {
                $revisar++;
            }
        }

        return [
            'total' => count($filas),
            'by_state' => $porEstado,
            'upcoming' => $porEstado['proximo'] + $porEstado['vence_hoy'],
            'overdue' => $porEstado['vencido'],
            'needs_review' => $revisar,
            // Lo pactado de esos acuerdos: lo que se va a reclamar.
            'amount_upcoming' => round($proximos, 2),
            'amount_overdue' => round($vencidos, 2),
            'window_days' => $ventana,
            'today' => $hoy->toDateString(),
        ];
    }

    /** Días de calendario de una fecha `Y-m-d` a otra; negativo si la segunda es anterior. */
    private static function daysBetween(string $desde, string $hasta): int
    {
        return (int) round(CarbonImmutable::parse($desde, 'UTC')->diffInDays(CarbonImmutable::parse($hasta, 'UTC'), false));
    }

    /**
     * El cobro VIGENTE de un mes, si lo hay.
     *
     * Los anulados no cuentan: siguen en la tabla para el histórico, pero no
     * ocupan el sitio del mes.
     */
    public function chargeFor(TrainerCommissionAgreement $acuerdo, CarbonImmutable $mes): ?TrainerCommissionCharge
    {
        return TrainerCommissionCharge::query()
            ->where('agreement_id', $acuerdo->id)
            ->whereDate('period', $mes->startOfMonth()->toDateString())
            ->with('receivable.payments')
            ->orderBy('id')
            ->get()
            ->first(fn (TrainerCommissionCharge $c) => ! $this->isCancelled($c));
    }

    /** Anulado es: sin deuda, o con la deuda anulada. */
    private function isCancelled(TrainerCommissionCharge $cobro): bool
    {
        return $cobro->receivable === null || $cobro->receivable->isCancelled();
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

    /**
     * Cómo se pagó un mes: cada abono con su medio, su importe y su fecha.
     *
     * Es la pregunta que llega después de «¿pagó?»: en qué quedó, si fue en
     * efectivo o por transferencia, y quién lo recibió. Sin esto hay que ir a
     * Cuentas por cobrar a buscarlo.
     *
     * @return list<array<string, mixed>>
     */
    private function paymentsOf(?Receivable $deuda, array $recibos = []): array
    {
        if (! $deuda) {
            return [];
        }

        // Los abonos ya traídos, si se trajeron: preguntarlos otra vez por cada
        // mes convertía el panel en una consulta por fila.
        $abonos = $deuda->relationLoaded('payments')
            ? $deuda->payments->sortBy('id')->values()
            : $deuda->payments()->orderBy('id')->get();

        return $abonos
            ->map(fn (ReceivablePayment $abono) => [
                'id' => $abono->id,
                'receivable_payment_id' => (int) $abono->id,
                // La referencia interna del abono, la que se dice en caja. La
                // externa (transferencia, voucher) es otra cosa y no es única.
                'reference' => self::paymentReference($abono),
                'receipt_number' => $recibos[(int) $abono->id] ?? null,
                'amount' => (float) $abono->amount,
                'method' => $abono->method,
                'method_label' => self::methodLabel($abono->method),
                'at' => $abono->created_at?->toIso8601String(),
                'cash_shift_id' => $abono->cash_shift_id !== null ? (int) $abono->cash_shift_id : null,
                'by' => $abono->created_by_name,
                'reversed' => ! $abono->isApplied(),
            ])
            ->values()
            ->all();
    }

    /** «RP-123»: el número interno de un abono. */
    private static function paymentReference(ReceivablePayment $abono): string
    {
        return 'RP-'.$abono->id;
    }

    private static function methodLabel(?string $medio): ?string
    {
        return self::METHOD_LABELS[strtolower((string) $medio)] ?? $medio;
    }

    /**
     * El número de recibo térmico de cada abono de esos cobros, en UNA consulta.
     *
     * @param  iterable<TrainerCommissionCharge>  $cobros
     * @return array<int, string>
     */
    private function receiptNumbersOf(iterable $cobros): array
    {
        $ids = [];
        foreach ($cobros as $cobro) {
            foreach ($cobro->receivable?->payments ?? [] as $abono) {
                $ids[] = (int) $abono->id;
            }
        }

        return ReceivablePayment::receiptNumbersFor(array_values(array_unique($ids)));
    }

    /**
     * Lo que pinta un cobro (chargeArray()), cargado por adelantado: la deuda
     * con la SUMA de sus abonos aplicados —el saldo sale de ahí, sin una
     * consulta por fila— y sus abonos; y, si el mes salió de reclasificar un
     * cobro que ya había entrado, la constancia con el recibo original.
     *
     * @return array<int|string, mixed>
     */
    private function chargeRelations(): array
    {
        return [
            'receivable' => fn ($q) => $q->withSum('appliedPayments', 'amount'),
            'receivable.payments',
            'reclassification.originalReceipt',
        ];
    }

    /** Los medios de pago, dichos como se dicen. */
    private const METHOD_LABELS = [
        'cash' => 'Efectivo',
        'card' => 'Tarjeta',
        'transfer' => 'Transferencia',
        'nequi' => 'Nequi',
        'daviplata' => 'Daviplata',
        'wompi' => 'Wompi',
    ];

    /**
     * @param  array<int, string>|null  $recibos  Números de recibo por abono, ya
     *                                            resueltos por quien pinta una lista
     *                                            (receiptNumbersOf()); sin ellos se
     *                                            buscan los de este cobro.
     * @return array<string, mixed>
     */
    private function chargeArray(TrainerCommissionCharge $cobro, ?array $recibos = null): array
    {
        $deuda = $cobro->receivable;
        $recibos ??= $this->receiptNumbersOf([$cobro]);
        $saldo = $deuda && ! $deuda->isCancelled() ? $deuda->balance()->toFloat() : 0.0;

        return [
            'id' => $cobro->id,
            'period' => CarbonImmutable::parse($cobro->period)->toDateString(),
            'period_label' => $cobro->periodLabel(),
            'covers_from' => $cobro->covers_from?->toDateString(),
            'covers_to' => $cobro->covers_to?->toDateString(),
            'cycle_days' => $cobro->cycle_days,
            'amount' => (float) $cobro->amount,
            'paid' => max(0.0, (float) $cobro->amount - $saldo),
            'balance' => $saldo,
            'payer' => $cobro->payer,
            'state' => $this->stateOf($cobro),
            'receivable_id' => $cobro->receivable_id,
            'due_at' => $deuda?->due_at?->toDateString(),
            'by' => $cobro->created_by_name,
            'created_at' => $cobro->created_at?->toIso8601String(),
            // Cómo se pagó: medio, importe y quién lo recibió.
            'payments' => $this->paymentsOf($deuda, $recibos),
            // Lo que explica una anulación meses después.
            'cancelled_at' => $deuda?->cancelled_at?->toIso8601String(),
            'cancel_reason' => $deuda?->cancellation_reason,
            'concept' => $deuda?->concept,
            // Si el mes salió de reclasificar un cobro que ya había entrado.
            'reclassified_from' => $this->reclassifiedFrom($cobro),
        ];
    }

    /**
     * De dónde salió un mes registrado RECLASIFICANDO un cobro que ya había
     * entrado (el piso cobrado por error como venta de un plan): el cobro
     * original, su recibo —que sigue siendo el comprobante de ese dinero— y
     * cuándo se corrigió. Null en un mes cobrado por la vía normal.
     *
     * @return array{reclassification_id: int, payment_id: int, receipt_number: ?string, performed_at: ?string}|null
     */
    private function reclassifiedFrom(TrainerCommissionCharge $cobro): ?array
    {
        $r = $cobro->reclassification;
        if ($r === null) {
            return null;
        }

        return [
            'reclassification_id' => (int) $r->id,
            'payment_id' => (int) $r->payment_id,
            'receipt_number' => $r->originalReceipt?->number,
            'performed_at' => $r->performed_at?->toIso8601String(),
        ];
    }

    /**
     * El resumen del mes. Lo que se mira primero al abrir la pantalla: cuánto
     * hay pactado, cuánto entró y cuánto falta.
     *
     * CUADRA SIEMPRE: pactado = cobrado + pendiente + sin registrar. Por eso
     * el pactado de un mes cobrado es lo que se cobró —un mes a medias o un
     * descuento puntual quedan congelados en el cobro— y el de uno sin cobrar,
     * lo que dice hoy el trato. Si se sumara el del trato en los dos casos, un
     * mes cobrado por menos dejaría una diferencia que nadie podría explicar.
     *
     * Un mes con solo cobros anulados no tiene cobro que valga: cuenta como
     * sin registrar, que es lo que es.
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
        $importeSinRegistrar = 0.0;
        $vencidos = 0;

        foreach ($filas as $fila) {
            $cobro = $fila['charge'];

            if ($cobro === null || $fila['state'] === 'cancelled') {
                $pactado += (float) $fila['amount'];
                $importeSinRegistrar += (float) $fila['amount'];
                $sinRegistrar++;

                continue;
            }

            $pactado += (float) $cobro['amount'];
            $cobrado += (float) $cobro['paid'];
            $pendiente += (float) $cobro['balance'];
            if ($fila['state'] === 'overdue') {
                $vencidos++;
            }
        }

        // Redondeado a centavos: son sumas de importes con dos decimales, y
        // así la identidad se cumple en la respuesta sin restos de coma flotante.
        return [
            'agreed' => round($pactado, 2),
            'collected' => round($cobrado, 2),
            'outstanding' => round($pendiente, 2),
            'unregistered' => $sinRegistrar,
            'unregistered_amount' => round($importeSinRegistrar, 2),
            'overdue' => $vencidos,
        ];
    }

    /** Por qué no se cobra ese mes, dicho para que se sepa qué corregir. */
    private function outOfForceMessage(TrainerCommissionAgreement $acuerdo, CarbonImmutable $mes): string
    {
        if (! $acuerdo->active) {
            return 'Ese acuerdo está terminado: ya no se le cobra ningún mes. Si el trato se retomó, vuelve a pactarlo.';
        }

        // Activo y fuera de vigencia: alguna de las dos fechas existe.
        $desde = $acuerdo->starts_on?->format('d-m-Y');
        $hasta = $acuerdo->ends_on?->format('d-m-Y');
        $vigencia = match (true) {
            $desde !== null && $hasta !== null => "del {$desde} al {$hasta}",
            $desde !== null => "desde el {$desde}",
            default => "hasta el {$hasta}",
        };

        return 'El acuerdo no está en vigor en '.self::monthLabel($mes).' (vale '.$vigencia.'): ese mes no se cobra. '
            .'Si el trato empezó antes o siguió después, corrige sus fechas.';
    }

    /** «noviembre de 2026», como se dice en el mostrador. */
    private static function monthLabel(CarbonImmutable $mes): string
    {
        $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
            'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

        return $meses[(int) $mes->month].' de '.$mes->year;
    }

    /**
     * La traza del alta de un acuerdo: la misma fila que escribía el
     * controlador.
     *
     * AuditTrail firma con quien venga en la petición, no con un actor suelto.
     * Se duplica la petición en curso —la HTTP del mostrador o la de consola
     * de un procedimiento— con el actor puesto: la traza dice quién y desde
     * dónde, y la petición en curso no se toca. Sin actor no hay persona y no
     * se firma nada, igual que con el token compartido.
     */
    private function auditAgreement(TrainerCommissionAgreement $a, ?Admin $actor, string $accion, string $resumen): void
    {
        if ($actor === null) {
            return;
        }

        $peticion = request()->duplicate();
        $peticion->attributes->set('auth_admin', $actor);

        app(AuditTrail::class)->record($peticion, [
            'action' => $accion,
            'module' => 'Comisiones',
            'entity' => 'piso de entrenador',
            'entity_id' => (string) $a->id,
            'target_name' => $a->trainer?->full_name,
            'summary' => $resumen,
            'metadata' => [
                'trainer_id' => $a->trainer_id,
                'member_id' => $a->member_id,
                'amount' => (float) $a->amount,
                'payer' => $a->payer,
            ],
        ]);
    }
}

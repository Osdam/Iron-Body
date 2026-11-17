<?php

namespace App\Services\Payments;

use App\Enums\CashShiftType;
use App\Enums\DebtorType;
use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Exceptions\CorrectionException;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PlanController;
use App\Models\Admin;
use App\Models\CashRefund;
use App\Models\CashShift;
use App\Models\ElectronicInvoice;
use App\Models\EmployeeAccess;
use App\Models\Identity;
use App\Models\Member;
use App\Models\MembershipAdjustment;
use App\Models\Payment;
use App\Models\PaymentReclassification;
use App\Models\PaymentSplit;
use App\Models\Plan;
use App\Models\Receipt;
use App\Models\ReceiptSource;
use App\Models\Receivable;
use App\Models\ReceivablePayment;
use App\Models\ReceivablePaymentSplit;
use App\Models\Trainer;
use App\Models\TrainerCommissionAgreement;
use App\Models\TrainerCommissionCharge;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use App\Services\Audit\FinancialAudit;
use App\Services\Billing\Money;
use App\Services\Billing\PendingInvoiceCancellation;
use App\Services\Caja\CashReceipts;
use App\Services\Caja\CashShiftReport;
use App\Services\Caja\CashShiftTotalsService;
use App\Services\Caja\CorrectionGuards;
use App\Services\Caja\PaymentCorrections;
use App\Services\Commissions\TrainerCommissionAccess;
use App\Services\Commissions\TrainerCommissions;
use App\Services\Membership\DeletedPlanRestorer;
use App\Services\Membership\MembershipAccess;
use App\Services\Membership\MembershipFreeze;
use App\Services\Membership\MembershipTransfers;
use App\Services\Membership\PlanArchive;
use App\Services\NotificationService;
use App\Services\Reports\MoneyLedger;
use App\Services\Reports\ReportRange;
use App\Services\Trainer\TrainerProfileService;
use App\Support\Access\CrmPermission;
use App\Support\Caja\PaymentMethodKind;
use App\Support\Caja\PaymentOrigin;
use App\Support\DocumentNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PDOException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * RECLASIFICAR UN COBRO QUE YA ENTRÓ: el dinero era el piso de un entrenador y
 * se registró como la venta de un plan.
 *
 * Lo ejecuta `payments:reclassify-to-commission`. El contrato, las decisiones
 * y el procedimiento de compensación están en
 * docs/ops/RECLASIFICACION-DE-COBROS.md; aquí, cómo se hace sin romper nada.
 *
 * EN UNA SOLA TRANSACCIÓN, y por las VÍAS OFICIALES:
 *
 *   1. Idempotencia: si el cobro ya tiene su constancia, no se toca nada.
 *   2. Precondiciones, leídas bajo cerrojo (turno compartido → socio → cobro,
 *      el orden de PaymentController::applyUpdate).
 *   3. Entrenador (alta oficial con TrainerProfileService, sin roles, o el que
 *      ya existe con ese documento) y acuerdo (TrainerCommissions::createAgreement).
 *   4. Anulación del cobro por PaymentController::update(): devuelve la
 *      membresía con MembershipPeriod::revert y deja su traza financiera.
 *   5. El cobro del mes con TrainerCommissions::charge(), sin dinero.
 *   6. El abono que salda esa deuda EN EL TURNO ORIGINAL y con la fecha en que
 *      entró el dinero. Replica lo que escribe ReceivableService::settle(),
 *      que no sirve aquí porque exige el turno abierto.
 *   7. El borrador de factura del concepto equivocado, cancelado
 *      (PendingInvoiceCancellation, la regla de billing:cancel-test-requests).
 *   8. El plan, archivado solo si se pide y si no le cambia el acceso a nadie.
 *   9. La constancia inmutable (`payment_reclassifications`) y su traza.
 *  10. Las invariantes, con los servicios reales: el turno sigue cuadrando con
 *      su cierre firmado, el día económico recauda lo mismo y por los mismos
 *      medios, la membresía queda como dijo la vista previa, la deuda saldada,
 *      ningún recibo, devolución ni cobro nuevos, y solo se escribió en las
 *      tablas y filas de la lista blanca (comprobado con DB::listen).
 *
 * Sin `--apply` es un ENSAYO: hace todo lo anterior y termina en ROLLBACK, así
 * que el informe es exactamente lo que pasaría. Con `--apply` confirma solo si
 * todas las invariantes se cumplen. En PostgreSQL toma además un cerrojo
 * consultivo de transacción: dos reclasificaciones nunca corren a la vez.
 */
final class PaymentReclassifier
{
    public const PROCEDURE = 'payments:reclassify-to-commission';

    public const VERSION = 1;

    /** Lo que firma las trazas que escribe el procedimiento (User-Agent). */
    public const USER_AGENT = 'IronBody payments:reclassify-to-commission/1';

    /** `client_request_id` del abono: uno por cobro, único en la base. */
    public const REQUEST_PREFIX = 'reclass:payment:';

    /** El alta del entrenador que no existía. */
    public const TRAINER_CONTRACT = 'Independiente';

    /** Origen que queda en la traza del enlace de identidad del entrenador. */
    public const IDENTITY_SOURCE = 'payments_reclassify';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_DRY_RUN = 'dry_run';

    public const STATUS_ALREADY = 'already_applied';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_INVARIANT = 'invariant_failed';

    public const STATUS_ERROR = 'error';

    public const EXIT_OK = 0;

    public const EXIT_REJECTED = 1;

    public const EXIT_INVARIANT = 2;

    public const EXIT_ERROR = 3;

    /** Estados con los que el cobro reclasificado sigue anulado. */
    public const CANCELLED_STATUSES = ['cancelled', 'canceled', 'anulado', 'anulada'];

    /** Cerrojo consultivo de PostgreSQL (arbitrario, solo de este procedimiento). */
    private const ADVISORY_KEY = 202_611_180;

    private const ATTEMPTS = 3;

    /**
     * Lo único que el procedimiento puede escribir. Cualquier otra escritura es
     * un invariante roto. Los UPDATE y DELETE se comprueban además fila a fila
     * (ver comprobarEscrituras()): solo las del cobro, su socia y lo que el
     * propio procedimiento crea.
     */
    private const WRITABLE = [
        'payments' => ['update'],
        'users' => ['update'],
        'audit_logs' => ['insert'],
        'member_realtime_events' => ['insert', 'delete'],
        'trainers' => ['insert', 'update'],
        'identities' => ['insert'],
        'trainer_audit_logs' => ['insert'],
        'trainer_commission_agreements' => ['insert', 'update'],
        'receivables' => ['insert', 'update'],
        'trainer_commission_charges' => ['insert', 'update'],
        'receivable_payments' => ['insert'],
        'receivable_payment_splits' => ['insert'],
        'employee_accesses' => ['insert', 'update'],
        'electronic_invoices' => ['update'],
        'electronic_invoice_logs' => ['insert'],
        'plans' => ['update'],
        'payment_reclassifications' => ['insert'],
    ];

    /** @var list<array{firma: string, sql: string, bindings: array<int, mixed>}> */
    private array $escrituras = [];

    private bool $grabando = false;

    private bool $escuchando = false;

    public function __construct(
        private readonly PaymentCorrections $corrections,
        private readonly TrainerCommissions $commissions,
        private readonly TrainerProfileService $profiles,
        private readonly PendingInvoiceCancellation $invoices,
        private readonly CashShiftTotalsService $totals,
        private readonly CashShiftReport $shiftReport,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * La reclasificación como la leen el CRM y los listados (contrato §5).
     *
     * Usa `charge` y `agreement.trainer` si vienen cargadas; quien pinte una
     * lista debe cargarlas antes para no hacer una consulta por fila.
     *
     * @return array{id: int, label: string, kind: string, performed_at: ?string, economic_at: ?string, trainer_name: ?string, period_label: ?string, charge_id: ?int, receivable_payment_id: ?int}
     */
    public static function summary(PaymentReclassification $r): array
    {
        return [
            'id' => (int) $r->id,
            'label' => $r->label(),
            'kind' => (string) $r->kind,
            'performed_at' => optional($r->performed_at)->toIso8601String(),
            'economic_at' => optional($r->economic_at)->toIso8601String(),
            'trainer_name' => $r->agreement?->trainer?->full_name,
            'period_label' => $r->charge?->periodLabel(),
            'charge_id' => $r->charge_id !== null ? (int) $r->charge_id : null,
            'receivable_payment_id' => $r->receivable_payment_id !== null ? (int) $r->receivable_payment_id : null,
        ];
    }

    /**
     * Lo mismo que {@see summary()}, para quien lo lee. El token compartido de
     * automatizaciones (n8n, ULTRON) no es una persona y no necesita el nombre
     * de un tercero: se le omite (revisión de seguridad H-4).
     *
     * @return array<string, mixed>
     */
    public static function summaryFor(PaymentReclassification $r, bool $conPersona): array
    {
        $s = self::summary($r);
        if (! $conPersona) {
            $s['trainer_name'] = null;
        }

        return $s;
    }

    /**
     * La reclasificación de un cobro, si está EN VIGOR: existe su constancia y
     * el cobro sigue anulado. Si se compensara (el abono revertido y el cobro
     * reconfirmado, ver la documentación) el cobro vuelve a ser la venta de un
     * plan y se pinta como tal; la constancia sigue ahí, inmutable, como
     * historia. Usa la relación `reclassification` si viene cargada.
     */
    public static function inEffect(Payment $pago): ?PaymentReclassification
    {
        $r = $pago->reclassification;

        return $r !== null && in_array(strtolower(trim((string) $pago->status)), self::CANCELLED_STATUSES, true) ? $r : null;
    }

    /** `payments:reclassify-to-commission@1`: lo que se guarda en `procedure`. */
    public static function procedure(): string
    {
        return self::PROCEDURE.'@'.self::VERSION;
    }

    /**
     * Ejecuta la reclasificación (o su ensayo) y devuelve el informe.
     *
     * Nunca lanza: todo desenlace —aplicada, ensayo, ya aplicada, rechazada,
     * invariante roto o error— vuelve en el informe con su `exit_code`, y en
     * todos salvo `applied` no queda nada escrito.
     *
     * @param  array{payment_id: int, member_id: int, trainer_id: ?int, trainer_from_member_id: ?int, actor_id: int, received_by: ?int, starts_on: ?string, cycle_days: int, payer: string, blocks_app: bool, reason: string, archive_plan: bool}  $o
     * @return array<string, mixed>
     */
    public function run(array $o, bool $aplicar): array
    {
        // 0 · Ya hecha: se informa sin abrir transacción ni escribir nada.
        if ($ya = PaymentReclassification::query()->where('payment_id', (int) $o['payment_id'])->first()) {
            return $this->yaAplicada($this->informeVacio($o, $aplicar), $ya);
        }

        $this->escucharEscrituras();
        $raiz = DB::transactionLevel() === 0;

        for ($intento = 1; ; $intento++) {
            $informe = $this->informeVacio($o, $aplicar);
            $informe['attempts'] = $intento;
            $nivel = DB::transactionLevel();
            $peticionPrevia = app()->bound('request') ? app('request') : null;
            $entrenadorCreado = null;

            try {
                $actor = $this->actor($o);
                // Todo lo que escriba trazas por su cuenta (deuda, acuerdo,
                // entrenador) lee la petición global: que diga quién y desde dónde.
                app()->instance('request', $this->peticion($actor, 'POST', '/'.self::PROCEDURE));

                DB::beginTransaction();
                if (DB::connection()->getDriverName() === 'pgsql') {
                    DB::select('SELECT pg_advisory_xact_lock(?)', [self::ADVISORY_KEY]);
                }

                $hecha = $this->procedimiento($o, $actor, $informe);
                if ($hecha instanceof PaymentReclassification) {
                    DB::rollBack($nivel);

                    return $this->yaAplicada($informe, $hecha);
                }
                $entrenadorCreado = $hecha;

                $rotas = array_values(array_filter($informe['invariants'], fn (array $i) => ! $i['ok']));
                if ($rotas !== []) {
                    DB::rollBack($nivel);

                    return $this->cerrar($informe, self::STATUS_INVARIANT, self::EXIT_INVARIANT,
                        'Invariante roto: '.implode('; ', array_map(fn (array $i) => $i['check'], $rotas))
                        .'. Se hizo ROLLBACK: nada quedó escrito.');
                }

                if (! $aplicar) {
                    DB::rollBack($nivel);

                    return $this->cerrar($informe, self::STATUS_DRY_RUN, self::EXIT_OK,
                        'Ensayo correcto: todas las invariantes se cumplen. Se hizo ROLLBACK: nada quedó escrito. Repite con --apply para confirmar.');
                }

                DB::commit();
            } catch (ReclassificationRejected $e) {
                DB::rollBack($nivel);

                return $this->rechazada($informe, $e);
            } catch (UniqueConstraintViolationException $e) {
                DB::rollBack($nivel);
                // Otra ejecución del mismo cobro confirmó antes: la clave única
                // de `payment_reclassifications.payment_id` es la última red.
                if ($ya = PaymentReclassification::query()->where('payment_id', (int) $o['payment_id'])->first()) {
                    return $this->yaAplicada($informe, $ya);
                }

                return $this->fallo($informe, $e);
            } catch (Throwable $e) {
                DB::rollBack($nivel);
                // Solo la transacción raíz se puede repetir: dentro de otra, un
                // interbloqueo ya abortó la de fuera.
                if ($raiz && $intento < self::ATTEMPTS && (new ConcurrencyErrorDetector)->causedByConcurrencyError($e)) {
                    continue;
                }

                return $this->fallo($informe, $e);
            } finally {
                $this->grabando = false;
                if ($peticionPrevia !== null) {
                    app()->instance('request', $peticionPrevia);
                }
            }

            // Después del COMMIT, como hace el alta de entrenadores por la API:
            // el aviso al panel no forma parte de la operación.
            if ($entrenadorCreado instanceof Trainer) {
                app(NotificationService::class)->notifyTrainerCreated($entrenadorCreado);
            }

            return $this->cerrar($informe, self::STATUS_APPLIED, self::EXIT_OK,
                'Reclasificación aplicada (COMMIT). Constancia #'.$informe['reclassification_id'].'.');
        }
    }

    // ────────────────────────────────────────────────────────────────────────
    //  Dentro de la transacción
    // ────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $o
     * @param  array<string, mixed>  $informe
     * @return PaymentReclassification|Trainer|null la constancia si ya existía; si no, el entrenador creado (o null)
     */
    private function procedimiento(array $o, Admin $actor, array &$informe): PaymentReclassification|Trainer|null
    {
        $pagoId = (int) $o['payment_id'];
        $previo = Payment::query()->find($pagoId)
            ?? throw new ReclassificationRejected('payment_not_found', "No existe el cobro #{$pagoId}.");

        // 1 · Cerrojos en el orden de PaymentController::applyUpdate: turno
        //     (compartido) → socio → cobro. Un cierre, una anulación o un abono
        //     en paralelo esperan a esta corrección, o ella a ellos.
        if ($previo->cash_shift_id) {
            CashShift::whereKey($previo->cash_shift_id)->sharedLock()->first();
        }
        if ($previo->user_id) {
            User::whereKey($previo->user_id)->lockForUpdate()->first();
        }
        $pago = Payment::whereKey($pagoId)->lockForUpdate()->firstOrFail();

        // 2 · Idempotencia, otra vez y ya bajo cerrojo: quien esperaba detrás
        //     de otra ejecución del mismo cobro la encuentra aquí.
        if ($ya = PaymentReclassification::query()->where('payment_id', $pagoId)->first()) {
            return $ya;
        }

        // 3 · Precondiciones. Todo se decide con lo leído bajo cerrojo.
        $c = $this->precondiciones($pago, $o, $actor);

        // 4 · Lo que hará la anulación, calculado con el mismo código que la
        //     hará (y deshecho). Debe coincidir después.
        $vista = $this->corrections->preview($pago, 'cancel', $actor);
        if (! $vista['allowed']) {
            throw new ReclassificationRejected('cancel_blocked', 'La anulación oficial del cobro está bloqueada: '
                .implode(' ', array_map(fn (array $b) => $b['message'], $vista['blockers'])));
        }
        $membresia = $vista['effects']['membership'];
        if ($pago->plan_id && $pago->period_start && $pago->period_end && (int) ($membresia['days_removed'] ?? 0) <= 0) {
            throw new ReclassificationRejected('membership_chain_broken',
                'La cadena de periodos de la socia no explica su vencimiento actual: anular el cobro no le devolvería '
                .'sus días (MembershipPeriod::revert lo deja intacto). Revisa sus periodos antes de reclasificar.');
        }
        $finAntes = $c['usuario']->membershipEndDate;
        $informe['membership'] = [
            'end_before' => $finAntes,
            'preview_end_after' => $membresia['end_after'] ?? $finAntes,
            'preview_days_removed' => (int) ($membresia['days_removed'] ?? 0),
            'end_after' => null,
            'days_removed' => null,
        ];

        // 5 · La foto de antes, y desde aquí cada escritura queda anotada.
        $antes = $this->foto($pago, $c);
        $informe['before'] = $antes;
        $this->escrituras = [];
        $this->grabando = true;

        // 6 · Entrenador y acuerdo, por las vías oficiales.
        [$entrenador, $creado] = $this->entrenador($c);
        try {
            $acuerdo = $this->commissions->createAgreement($entrenador, $c['socia'], [
                'amount' => (float) $pago->amount,
                'payer' => (string) $o['payer'],
                'cycle_days' => (int) $o['cycle_days'],
                'blocks_app' => (bool) $o['blocks_app'],
                'starts_on' => $c['desde']->toDateString(),
                'notes' => mb_substr($this->nota($pago, $c), 0, 255),
            ], $actor);
        } catch (RuntimeException $e) {
            throw $this->dominio($e, 'agreement_rejected', 'No se pudo pactar el acuerdo');
        }

        // 7 · La anulación oficial del cobro, con su membresía y su traza.
        $this->anular($pago, $actor, (string) $o['reason']);
        $pago->refresh();
        $usuario = $c['usuario']->fresh();
        $informe['membership']['end_after'] = $usuario->membershipEndDate;
        $informe['membership']['days_removed'] = $this->diasEntre($usuario->membershipEndDate, $finAntes);

        // 8 · El cobro del mes, por la vía oficial y SIN dinero: abre la deuda.
        try {
            $resultado = $this->commissions->charge(
                acuerdo: $acuerdo,
                mes: $c['mes'],
                method: null,
                actor: $actor,
                importe: (float) $pago->amount,
                notas: $this->nota($pago, $c),
                dias: (int) $o['cycle_days'],
                desde: $acuerdo->coverageStartFor($c['mes']),
            );
        } catch (RuntimeException $e) {
            throw $this->dominio($e, 'charge_rejected', 'No se pudo registrar el cobro del mes');
        }
        $cobro = TrainerCommissionCharge::query()->findOrFail((int) $resultado['id']);
        $deuda = Receivable::query()->findOrFail((int) $resultado['receivable_id']);

        // La deuda y el cobro del mes nacen con la fecha en que ENTRÓ el
        // dinero; su `updated_at` es la hora real de la corrección.
        $deuda->forceFill(['created_at' => $c['economico']])->save();
        $cobro->forceFill(['created_at' => $c['economico']])->save();
        $this->auditarCobro($acuerdo, $cobro, $pago);

        // 9 · El abono histórico que salda la deuda en el turno original.
        $abono = $this->abono($pago, $deuda, $c, $actor);

        // 10 · El borrador de factura del concepto equivocado.
        $facturaCancelada = $this->cancelarFactura($c['factura'], $pago, $actor);

        // 11 · El plan, si se pidió y es seguro.
        $informe['plan_archive'] = $o['archive_plan'] ? $this->archivarPlan($pago, $o, $actor) : null;

        // 12 · La foto de después (la que se guarda) y la constancia.
        $despues = $this->foto($pago, $c, $entrenador);
        // «Lo que no entró» depende de la constancia, que todavía no existe: se
        // guarda lo que dirá con ella —los anulados de verdad, igual; el
        // reclasificado, aparte— y al final se comprueba contra MoneyLedger.
        $noEntroAntes = $antes['day']['not_collected'];
        $despues['day']['not_collected'] = [
            'cancelled_payments' => $noEntroAntes['cancelled_payments'],
            'reclassified_payments' => [
                'amount' => round((float) $noEntroAntes['reclassified_payments']['amount'] + (float) $pago->amount, 2),
                'count' => (int) $noEntroAntes['reclassified_payments']['count'] + 1,
            ],
        ];
        $reclasificacion = PaymentReclassification::create([
            'payment_id' => $pago->id,
            'kind' => PaymentReclassification::KIND_TRAINER_COMMISSION,
            'amount' => number_format((float) $pago->amount, 2, '.', ''),
            'method' => $pago->method,
            'economic_at' => $c['economico'],
            'cash_shift_id' => $c['turno']->id,
            'agreement_id' => $acuerdo->id,
            'charge_id' => $cobro->id,
            'receivable_id' => $deuda->id,
            'receivable_payment_id' => $abono->id,
            'original_receipt_id' => $c['recibo']?->id,
            'cancelled_invoice_id' => $facturaCancelada?->id,
            'membership_end_before' => $finAntes,
            'membership_end_after' => $usuario->membershipEndDate,
            'membership_days_removed' => $informe['membership']['days_removed'],
            'reason' => (string) $o['reason'],
            'performed_by' => $actor->id,
            'performed_by_name' => $actor->name,
            'performed_at' => now(),
            'procedure' => self::procedure(),
            'before_snapshot' => $antes,
            'after_snapshot' => $despues + ['executed_by' => self::ejecutor()],
        ]);
        $this->auditarReclasificacion($reclasificacion, $pago, $c);
        $this->grabando = false;

        $informe['reclassification_id'] = $reclasificacion->id;
        $informe['after'] = $despues;
        $informe['created'] = [
            'trainer_id' => $entrenador->id,
            'trainer_created' => $creado,
            'identity_id' => $entrenador->fresh()?->identity_id,
            'agreement_id' => $acuerdo->id,
            'charge_id' => $cobro->id,
            'charge_period' => $cobro->period?->toDateString(),
            'covers_from' => $cobro->covers_from?->toDateString(),
            'covers_to' => $cobro->covers_to?->toDateString(),
            'receivable_id' => $deuda->id,
            'receivable_payment_id' => $abono->id,
            'cancelled_invoice_id' => $facturaCancelada?->id,
            'original_receipt_id' => $c['recibo']?->id,
            'original_receipt_number' => $c['recibo']?->number,
        ];
        $informe['invoice'] = $c['factura'] === null ? null : [
            'id' => $c['factura']->id,
            'status_before' => PendingInvoiceCancellation::statusOf($c['factura']),
            'status_after' => $facturaCancelada !== null ? PendingInvoiceCancellation::statusOf($facturaCancelada) : null,
        ];

        // 13 · Las invariantes, con los servicios reales.
        [$informe['invariants'], $informe['writes'], $informe['unexpected_writes']] =
            $this->invariantes($pago, $c, $antes, $despues, $usuario, $acuerdo, $cobro, $deuda, $abono, $facturaCancelada, $entrenador, $creado, $reclasificacion, $informe['membership']);

        return $creado ? $entrenador : null;
    }

    /**
     * Todo lo que tiene que ser cierto ANTES de escribir. Si algo falla, se
     * rechaza con su motivo y la transacción se deshace entera.
     *
     * @param  array<string, mixed>  $o
     * @return array<string, mixed>
     */
    private function precondiciones(Payment $pago, array $o, Admin $actor): array
    {
        $no = fn (string $codigo, string $mensaje) => throw new ReclassificationRejected($codigo, $mensaje);
        $id = $pago->id;

        // El cobro.
        if ((string) $pago->status !== 'paid') {
            $no('payment_not_paid', "El cobro #{$id} está «{$pago->status}»: solo se reclasifica un cobro pagado.");
        }
        if (PaymentOrigin::fromStored($pago->origin) !== PaymentOrigin::COUNTER) {
            $no('payment_not_counter', "El cobro #{$id} no es de mostrador (origen «".($pago->origin ?? 'sin dato').'»): su dinero no está en un turno de caja.');
        }
        if (app(CashReceipts::class)->isAccrual($pago->method)) {
            $no('payment_is_accrual', "El cobro #{$id} es el asiento de un plan a plazos: no es dinero.");
        }
        if (CorrectionGuards::isGatewayPayment($pago)) {
            $no('payment_is_gateway', "El cobro #{$id} entró por la pasarela: no está en ningún turno de caja.");
        }
        if ($pago->upgraded_from_plan_id) {
            $no('payment_is_upgrade', "El cobro #{$id} es una mejora de plan: no se reclasifica.");
        }
        if ((float) $pago->amount <= 0 || $pago->paid_at === null || $pago->user_id === null) {
            $no('payment_incomplete', "El cobro #{$id} no tiene importe, fecha de cobro o socio.");
        }

        // El turno: cerrado, del gimnasio y cuadrando con su cierre firmado.
        $turno = $pago->cash_shift_id ? CashShift::query()->find($pago->cash_shift_id) : null;
        if ($turno === null) {
            $no('payment_without_shift', "El cobro #{$id} no pertenece a ningún turno de caja.");
        }
        if ($turno->type !== CashShiftType::GYM) {
            $no('shift_not_gym', "El turno #{$turno->id} no es de la caja del gimnasio.");
        }
        if ($turno->isOpen() || $turno->closed_at === null) {
            $no('shift_open', "El turno #{$turno->id} sigue abierto: ahí la corrección es la de siempre "
                .'(anular el cobro y cobrar el piso en el mostrador). Este procedimiento es para turnos cerrados.');
        }
        if ($turno->sales_total === null) {
            $no('shift_without_snapshot', "El turno #{$turno->id} no tiene sus totales congelados.");
        }

        // Las partes, si las tiene: se tienen que poder replicar en el abono.
        $partes = PaymentSplit::query()->where('payment_id', $id)->orderBy('id')->get();
        if ($pago->isMixed()) {
            $centavos = $partes->sum(fn (PaymentSplit $p) => (int) round((float) $p->amount * 100));
            $replicables = $partes->count() >= 2
                && $centavos === (int) round((float) $pago->amount * 100)
                && $partes->every(fn (PaymentSplit $p) => PaymentMethodKind::isKnown($p->method)
                    && ! in_array(strtolower(trim((string) $p->method)), [Payment::MIXED_METHOD, CashReceipts::ACCRUAL_METHOD], true));
            if (! $replicables) {
                $no('splits_not_replicable', "El desglose del cobro #{$id} no se puede replicar en el abono (partes que no suman o con medios que la caja no cuenta).");
            }
        } elseif ($partes->isNotEmpty()) {
            $no('splits_not_replicable', "El cobro #{$id} tiene desglose pero no es mixto: revísalo antes de reclasificar.");
        }

        // La factura: solo un borrador que nunca llegó a la DIAN.
        if ($pago->invoice_requested) {
            $no('invoice_requested', "El cliente pidió factura del cobro #{$id}: esa decisión no la toma este procedimiento.");
        }
        $facturas = ElectronicInvoice::query()
            ->where('source_type', $pago->getMorphClass())
            ->where('source_id', $id)
            ->orderBy('id')
            ->get();
        foreach ($facturas as $f) {
            $estado = PendingInvoiceCancellation::statusOf($f);
            if ($f->type !== InvoiceType::INVOICE) {
                $no('invoice_credit_note', "El cobro #{$id} tiene una nota crédito (#{$f->id}): ya pasó por la DIAN.");
            }
            if (! in_array($estado, [InvoiceStatus::PENDING->value, InvoiceStatus::CANCELLED->value], true)) {
                $no('invoice_not_pending', "La factura #{$f->id} del cobro está «{$estado}»: solo se cancela un borrador pendiente que nunca se envió.");
            }
            if (filled($f->cufe) || filled($f->full_number)) {
                $no('invoice_emitted', "La factura #{$f->id} del cobro ya tiene CUFE o número: exigiría nota crédito.");
            }
        }
        if (app(CorrectionGuards::class)->bundleInvoiceFor($pago) !== null) {
            $no('invoice_bundle', "El cobro #{$id} está dentro de un lote de facturación manual.");
        }
        $pendiente = $facturas->first(fn (ElectronicInvoice $f) => PendingInvoiceCancellation::statusOf($f) === InvoiceStatus::PENDING->value);

        // Lo que cuelga del cobro.
        if ($this->corrections->dependentUpgradeExists($pago)) {
            $no('upgrade_depends', "Una mejora de plan cobrada depende del cobro #{$id}.");
        }
        if (MembershipTransfers::liveFromPayment((int) $id) !== null) {
            $no('membership_transferred', "El periodo del cobro #{$id} se traspasó a otra persona y el traspaso sigue vivo.");
        }
        $deuda = Receivable::query()->where('source_type', $pago->getMorphClass())->where('source_id', $id)
            ->get()->first(fn (Receivable $r) => ! $r->isCancelled());
        if ($deuda !== null) {
            $no('receivable_alive', "El cobro #{$id} tiene viva la cuenta por cobrar #{$deuda->id}.");
        }

        // La membresía de la socia.
        $usuario = User::query()->findOrFail($pago->user_id);
        if (app(MembershipFreeze::class)->isFrozen($usuario)) {
            $no('membership_frozen', 'La membresía de la socia está congelada: reanúdala o espera a que vuelva antes de reclasificar.');
        }
        $ajustada = MembershipAdjustment::query()
            ->where('user_id', $usuario->id)
            ->where('kind', MembershipAdjustment::KIND_DAYS)
            ->where('created_at', '>=', $pago->created_at)
            ->exists();
        if ($ajustada) {
            $no('membership_adjusted', 'La membresía de la socia tiene ajustes de días posteriores al cobro: la cadena de periodos ya no explica su vencimiento.');
        }

        // La socia del acuerdo: la titular del cobro.
        $socia = Member::query()->find((int) $o['member_id'])
            ?? $no('member_not_found', "No existe la ficha de socio #{$o['member_id']}.");
        if ((int) $socia->user_id !== (int) $pago->user_id) {
            $no('member_not_holder', "La ficha #{$socia->id} no es la titular del cobro #{$id}.");
        }

        // El entrenador: el indicado, o el que ya existe con ese documento, o
        // uno nuevo (que se crea más tarde, ya grabando).
        [$entrenador, $ficha] = $this->entrenadorPrevio($o, $socia);
        if ($entrenador === null && ! CrmPermission::allows($actor, 'trainers.manage')) {
            $no('actor_forbidden', 'Dar de alta al entrenador exige además «trainers.manage», y --actor no lo tiene.');
        }

        // Fechas: cuándo entró el dinero, su día de negocio y su mes.
        $economico = CarbonImmutable::parse($pago->paid_at);
        $dia = $economico->setTimezone(MembershipPeriod::TZ)->toDateString();
        $desde = CarbonImmutable::parse($o['starts_on'] ?: $dia)->startOfDay();
        $mes = CarbonImmutable::parse($dia)->startOfMonth();

        if ($o['archive_plan'] && ! $pago->plan_id) {
            $no('archive_without_plan', "El cobro #{$id} no tiene plan: --archive-plan no tiene nada que archivar.");
        }

        // El recibo que ya tiene ese dinero: el primero emitido para el cobro.
        $recibo = Receipt::query()->whereIn('id', ReceiptSource::query()
            ->where('source_type', ReceiptSource::TYPE_PAYMENT)
            ->where('source_id', $id)
            ->select('receipt_id'))
            ->orderBy('id')
            ->first();

        $c = [
            'archivar' => (bool) $o['archive_plan'],
            'turno' => $turno,
            'usuario' => $usuario,
            'socia' => $socia,
            'entrenador' => $entrenador,
            'ficha_entrenador' => $ficha,
            'partes' => $partes,
            'factura' => $pendiente,
            'recibo' => $recibo,
            'economico' => $economico,
            'dia' => $dia,
            'desde' => $desde,
            'mes' => $mes,
            'pagos_de_la_socia' => Payment::query()->where('user_id', $pago->user_id)->pluck('id')->map(fn ($v) => (int) $v)->all(),
        ];
        $c['recibio'] = $this->receptor($pago, $o['received_by'] ?? null);

        // El turno tiene que cuadrar con su cierre ANTES de empezar: si ya no
        // cuadra, hay algo que revisar a mano y no es esta corrección.
        $vivo = $this->totals->for($turno);
        $descuadres = $this->descuadres($turno, $vivo);
        if ($descuadres !== []) {
            $no('shift_snapshot_mismatch', "El turno #{$turno->id} ya no cuadra con su cierre firmado (".implode(', ', $descuadres)
                .'): revísalo antes de reclasificar.');
        }
        if (! ($this->shiftReport->for($turno)['consistency']['matches'] ?? false)) {
            $no('shift_report_mismatch', "El informe del turno #{$turno->id} no cuadra su detalle con el cierre: revísalo antes de reclasificar.");
        }

        return $c;
    }

    /**
     * El entrenador del acuerdo, sin escribir nada: el de `--trainer`, o el que
     * ya existe con el documento de `--trainer-from-member`. Null si hay que
     * darlo de alta (se devuelve también la ficha de la que saldrán sus datos).
     *
     * @param  array<string, mixed>  $o
     * @return array{0: ?Trainer, 1: ?Member}
     */
    private function entrenadorPrevio(array $o, Member $socia): array
    {
        if (! empty($o['trainer_id'])) {
            $t = Trainer::query()->find((int) $o['trainer_id'])
                ?? throw new ReclassificationRejected('trainer_not_found', "No existe el entrenador #{$o['trainer_id']}.");

            return [$t, null];
        }

        $ficha = Member::query()->find((int) $o['trainer_from_member_id'])
            ?? throw new ReclassificationRejected('trainer_member_not_found', "No existe la ficha de socio #{$o['trainer_from_member_id']}.");
        if ((int) $ficha->id === (int) $socia->id) {
            throw new ReclassificationRejected('trainer_is_client', 'El entrenador no puede ser la misma ficha que la socia del acuerdo.');
        }
        $documento = DocumentNormalizer::normalize($ficha->document_number);
        if ($documento === null || trim((string) $ficha->full_name) === '') {
            throw new ReclassificationRejected('trainer_member_incomplete',
                "La ficha #{$ficha->id} no tiene nombre o documento: sin documento no se puede saber si el entrenador ya existe.");
        }

        // EL MISMO DOCUMENTO, NORMALIZADO, ES LA MISMA PERSONA: por la ficha
        // profesional y por la identidad. Son pocos entrenadores; se comparan
        // con el mismo normalizador que las identidades.
        $porFicha = Trainer::query()->whereNotNull('document')->get(['id', 'document', 'identity_id', 'status'])
            ->filter(fn (Trainer $t) => DocumentNormalizer::normalize($t->document) === $documento);
        $identidad = Identity::query()->where('document_normalized', $documento)->first();
        $porIdentidad = $identidad === null ? collect() : Trainer::query()->where('identity_id', $identidad->id)->get(['id', 'document', 'identity_id', 'status']);
        $candidatos = $porFicha->concat($porIdentidad)->unique('id')->values();

        if ($candidatos->count() > 1) {
            throw new ReclassificationRejected('trainer_ambiguous',
                'Hay '.$candidatos->count().' entrenadores con ese documento (#'.$candidatos->pluck('id')->implode(', #').'): indica cuál con --trainer.');
        }

        return [$candidatos->isEmpty() ? null : Trainer::query()->findOrFail($candidatos->first()->id), $ficha];
    }

    /**
     * El entrenador: el que ya había o el alta oficial a partir de la ficha de
     * socio. La misma vía que `POST /api/trainers` (TrainerProfileService), SIN
     * roles (no entra al portal profesional) y SIN enlazar la ficha de socio a
     * la identidad: eso exige que la persona pruebe que es suya
     * (MemberOwnershipLink, decisión del 15-10).
     *
     * @param  array<string, mixed>  $c
     * @return array{0: Trainer, 1: bool}
     */
    private function entrenador(array $c): array
    {
        if ($c['entrenador'] !== null) {
            return [$c['entrenador'], false];
        }

        /** @var Member $ficha */
        $ficha = $c['ficha_entrenador'];
        $entrenador = Trainer::create([
            'full_name' => mb_substr(trim((string) $ficha->full_name), 0, 255),
            'document' => mb_substr((string) $ficha->document_number, 0, 50),
            'phone' => $ficha->phone !== null ? mb_substr((string) $ficha->phone, 0, 30) : null,
            'contract_type' => self::TRAINER_CONTRACT,
            // INACTIVO a propósito: es un entrenador EXTERNO. Su acuerdo de piso
            // funciona igual (el módulo no exige que esté activo), pero no sale
            // en el listado público de entrenadores ni puede abrir sesión en el
            // portal (el login exige un entrenador activo). Si el gimnasio quiere
            // mostrarlo o darle portal, se activa en Entrenadores (revisiones de
            // seguridad H-3 y de calidad 5).
            'status' => 'inactive',
        ]);
        $this->profiles->linkIdentityAndRoles($entrenador, null, self::IDENTITY_SOURCE);

        return [$entrenador->fresh(), true];
    }

    /**
     * La anulación oficial: PaymentController::update(), como si la pidiera el
     * actor desde el CRM. Por consola no corre el middleware; los permisos ya
     * se comprobaron en actor() y los vuelven a exigir las guardas de dentro.
     */
    private function anular(Payment $pago, Admin $actor, string $motivo): void
    {
        $peticion = $this->peticion($actor, 'PATCH', '/api/payments/'.$pago->id, [
            'status' => 'cancelled',
            'reason' => $motivo,
        ]);

        try {
            app(PaymentController::class)->update($peticion, $pago);
        } catch (ValidationException $e) {
            throw new ReclassificationRejected('cancel_rejected',
                'La anulación oficial rechazó el cobro: '.(collect($e->errors())->flatten()->first() ?? $e->getMessage()));
        } catch (CorrectionException $e) {
            throw new ReclassificationRejected('cancel_rejected', "La anulación oficial rechazó el cobro ({$e->code_}): {$e->getMessage()}");
        } catch (HttpResponseException $e) {
            $cuerpo = json_decode((string) $e->getResponse()->getContent(), true);
            throw new ReclassificationRejected('cancel_forbidden', 'La anulación oficial lo denegó: '.($cuerpo['message'] ?? 'sin permiso'));
        } catch (HttpExceptionInterface $e) {
            throw new ReclassificationRejected('cancel_forbidden', 'La anulación oficial lo denegó: '.$e->getMessage());
        }

        if ((string) $pago->fresh()->status !== 'cancelled') {
            throw new ReclassificationRejected('cancel_not_applied', "El cobro #{$pago->id} no quedó anulado.");
        }
    }

    /**
     * El abono que salda la deuda del mes, replicando EXACTAMENTE lo que
     * escribe ReceivableService::settle() —saldo leído con la deuda bloqueada,
     * antes y después, estado recalculado y la misma traza—, con tres
     * diferencias que son el motivo de no llamarlo:
     *
     *   · el turno es el ORIGINAL, que está cerrado (settle() exige uno abierto);
     *   · `created_at` es cuándo entró el dinero, no ahora: todos los lectores
     *     de dinero fechan los abonos por ahí, y la fecha de hoy movería el
     *     ingreso de día;
     *   · lo firma quien RECIBIÓ el dinero, no quien corrige.
     *
     * El observador CommissionAccessObserver se dispara igual que con settle():
     * recalcula el acceso del entrenador, que solo se escribe si el entrenador
     * tiene ficha de socio enlazada a su identidad.
     *
     * @param  array<string, mixed>  $c
     */
    private function abono(Payment $pago, Receivable $deuda, array $c, Admin $actor): ReceivablePayment
    {
        $fresca = Receivable::query()->whereKey($deuda->id)->lockForUpdate()->firstOrFail();
        $importe = Money::fromAmount($pago->amount);
        $saldoAntes = $fresca->balance();

        if ($fresca->isCancelled() || $saldoAntes->isZero() || $importe->greaterThan($saldoAntes)) {
            throw new ReclassificationRejected('receivable_unexpected', "La deuda #{$fresca->id} del mes no está como se acaba de abrir: no se abona.");
        }

        $saldoDespues = $saldoAntes->minus($importe);
        $esMixto = $c['partes']->isNotEmpty();

        $abono = new ReceivablePayment([
            'receivable_id' => $fresca->id,
            'amount' => $importe->toDatabase(),
            // Lo que settle() haría con el medio del cobro original: `mixed`
            // sin normalizar si se repartió, y el medio canónico si no.
            'method' => $esMixto ? PaymentSplits::MIXED : PaymentMethodKind::normalize($pago->method)->value,
            'cash_shift_id' => $c['turno']->id,
            'balance_before' => $saldoAntes->toDatabase(),
            'balance_after' => $saldoDespues->toDatabase(),
            'client_request_id' => self::REQUEST_PREFIX.$pago->id,
            'status' => ReceivablePayment::STATUS_APPLIED,
            'created_by' => $c['recibio']['id'],
            'created_by_name' => $c['recibio']['name'],
            'reference' => $pago->reference !== null ? mb_substr((string) $pago->reference, 0, 120) : null,
            'notes' => $this->nota($pago, $c),
        ]);
        $abono->created_at = $c['economico'];
        $abono->save();

        if ($esMixto) {
            app(PaymentSplits::class)->assertMatchesTotal(
                $c['partes']->map(fn (PaymentSplit $p) => ['method' => $p->method, 'amount' => $p->amount])->all(),
                $importe->toFloat(),
            );
            foreach ($c['partes'] as $parte) {
                $s = new ReceivablePaymentSplit([
                    'receivable_payment_id' => $abono->id,
                    'method' => $parte->method,
                    'amount' => round((float) $parte->amount, 2),
                    'reference' => $parte->reference,
                ]);
                $s->created_at = $c['economico'];
                $s->save();
            }
        }

        // El estado es consecuencia del saldo, en la misma transacción.
        $fresca->refresh();
        $fresca->update(['status' => $fresca->statusForBalance()]);

        app(FinancialAudit::class)->receivablePaid($abono, $fresca, $actor, request());

        return $abono->fresh();
    }

    /**
     * Cancela el borrador `pending` del concepto equivocado con la regla de
     * `billing:cancel-test-requests`, releyéndolo bajo cerrojo.
     */
    private function cancelarFactura(?ElectronicInvoice $factura, Payment $pago, Admin $actor): ?ElectronicInvoice
    {
        if ($factura === null) {
            return null;
        }

        $fresca = ElectronicInvoice::query()->whereKey($factura->id)->lockForUpdate()->firstOrFail();
        if ($motivo = $this->invoices->blocker($fresca)) {
            throw new ReclassificationRejected('invoice_not_pending', "La factura #{$fresca->id} ya no es un borrador cancelable: {$motivo}.");
        }

        $this->invoices->cancel(
            $fresca,
            mb_substr("El cobro #{$pago->id} se reclasificó a piso de entrenador: el concepto de este borrador ya no existe", 0, 255),
            "admin #{$actor->id} vía ".self::PROCEDURE,
            "Origen: el cobro #{$pago->id} se reclasificó como piso de entrenador (payment_reclassifications); "
            .'el dinero sigue en su turno y ese concepto nunca debió facturarse.',
        );

        return $fresca->fresh();
    }

    /**
     * Archiva el plan del cobro con PlanArchive SOLO si es seguro:
     *
     *   · después de la corrección nadie tiene un periodo vigente o futuro con
     *     ese plan;
     *   · archivarlo no le cambia el acceso a nadie que lo tenga como
     *     `users.plan` con vencimiento futuro. Se comprueba con MembershipAccess
     *     antes y después, dentro de un punto de guardado: si algo cambia, se
     *     deshace solo el archivo.
     *
     * Si no es seguro no se archiva, y el informe dice por qué.
     *
     * @param  array<string, mixed>  $o
     * @return array<string, mixed>
     */
    private function archivarPlan(Payment $pago, array $o, Admin $actor): array
    {
        $plan = Plan::query()->findOrFail($pago->plan_id);
        $hoy = MembershipPeriod::today();
        $salida = ['plan_id' => $plan->id, 'requested' => true, 'archived' => false, 'already' => false, 'reason' => null,
            'live_periods' => 0, 'members_checked' => 0, 'access_changed' => 0];

        if ($plan->isArchived()) {
            return ['archived' => true, 'already' => true, 'reason' => 'El plan ya estaba archivado.'] + $salida;
        }

        $pagados = PaymentController::PAID_STATUSES;
        $salida['live_periods'] = Payment::query()
            ->where('plan_id', $plan->id)
            ->whereRaw('LOWER(status) IN ('.implode(',', array_fill(0, count($pagados), '?')).')', $pagados)
            ->whereNotNull('period_end')
            ->whereDate('period_end', '>=', $hoy->toDateString())
            ->count();
        if ($salida['live_periods'] > 0) {
            return $this->bloquearCobroEnMostrador($plan, $pago, $o, $actor, $salida,
                "No se archiva: {$salida['live_periods']} cobro(s) pagado(s) con este plan tienen un periodo vigente o futuro.");
        }

        $clave = MembershipAccess::planKey((string) $plan->name);
        $socios = User::query()
            ->whereNotNull('plan')
            ->whereNotNull('membership_end_date')
            ->whereDate('membership_end_date', '>=', $hoy->toDateString())
            ->get()
            ->filter(fn (User $u) => MembershipAccess::planKey((string) $u->plan) === $clave)
            ->values();
        $salida['members_checked'] = $socios->count();
        $ahora = CarbonImmutable::now(MembershipAccess::TZ);
        // Instancias nuevas: MembershipAccess guarda los planes en memoria.
        $antes = $socios->isEmpty() ? [] : (new MembershipAccess)->statesFor($socios, $ahora);

        DB::beginTransaction();
        try {
            app(PlanArchive::class)->archive(
                $this->peticion($actor, 'POST', '/api/plans/'.$plan->id.'/archive'),
                $plan,
                mb_substr("Reclasificación del cobro #{$pago->id}: el plan se vendía por error. ".$o['reason'], 0, 500),
            );
            $despues = $socios->isEmpty() ? [] : (new MembershipAccess)->statesFor($socios->map(fn (User $u) => $u->fresh()), $ahora);
            $cambian = array_keys(array_filter($antes, fn (array $estado, int $uid) => json_encode($estado) !== json_encode($despues[$uid] ?? null), ARRAY_FILTER_USE_BOTH));

            if ($cambian !== []) {
                DB::rollBack();
                $salida['access_changed'] = count($cambian);

                return $this->bloquearCobroEnMostrador($plan, $pago, $o, $actor, $salida,
                    'No se archiva: le cambiaría el acceso a '.count($cambian).' socio(s) que tienen este plan.');
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return ['archived' => true] + $salida;
    }

    /**
     * Cuando archivar no es seguro (alguien lo tiene vigente, o archivarlo le
     * cambiaría el acceso), el plan se queda ACTIVO —nadie pierde nada— pero
     * deja de poder cobrarse en recepción: «Se cobra en recepción» = no, con la
     * EDICIÓN OFICIAL del plan (valida, bloquea la fila y audita el antes y el
     * después). Fuera de recepción ya no se vendía: no es vendible. Es lo que
     * impide que el error que se está corrigiendo se repita.
     *
     * @param  array<string, mixed>  $o
     * @param  array<string, mixed>  $salida
     * @return array<string, mixed>
     */
    private function bloquearCobroEnMostrador(Plan $plan, Payment $pago, array $o, Admin $actor, array $salida, string $porque): array
    {
        $plan = $plan->fresh();
        if ($plan->isSellableAtCounter()) {
            app(PlanController::class)->update(
                $this->peticion($actor, 'PATCH', '/api/plans/'.$plan->id, ['counter_sellable' => false]),
                $plan,
            );
        }
        $plan = $plan->fresh();

        return [
            'reason' => $porque.' Se bloquea su cobro en recepción («Se cobra en recepción» = no); sigue activo para que nadie pierda acceso.',
            'counter_blocked' => ! $plan->isSellableAtCounter(),
        ] + $salida;
    }

    /**
     * Las comprobaciones finales, dentro de la transacción y antes de decidir.
     *
     * @param  array<string, mixed>  $c
     * @param  array<string, mixed>  $antes
     * @param  array<string, mixed>  $despues
     * @param  array<string, mixed>  $membresia
     * @return array{0: list<array{check: string, ok: bool, expected: mixed, actual: mixed}>, 1: array<string, int>, 2: list<string>}
     */
    private function invariantes(
        Payment $pago,
        array $c,
        array $antes,
        array $despues,
        User $usuario,
        TrainerCommissionAgreement $acuerdo,
        TrainerCommissionCharge $cobro,
        Receivable $deuda,
        ReceivablePayment $abono,
        ?ElectronicInvoice $factura,
        Trainer $entrenador,
        bool $entrenadorCreado,
        PaymentReclassification $reclasificacion,
        array $membresia,
    ): array {
        $r = [];
        $check = function (string $nombre, mixed $esperado, mixed $real, ?bool $ok = null) use (&$r): void {
            $r[] = ['check' => $nombre, 'ok' => $ok ?? ($esperado === $real), 'expected' => $esperado, 'actual' => $real];
        };

        // El turno: en vivo igual que su cierre firmado, igual que antes, y su
        // informe cuadra.
        $check('turno.cuadra_con_su_cierre', [], $this->descuadres(CashShift::query()->findOrFail($c['turno']->id), $despues['shift']['live']));
        $check('turno.totales_en_vivo_sin_cambios', $antes['shift']['live'], $despues['shift']['live']);
        $check('turno.informe_consistency_matches', true, $despues['shift']['consistency']['matches']);

        // El día económico (Bogotá): mismo recaudo, mismas operaciones, mismos
        // medios. Lo único que cambia es por qué puerta entró.
        $check('dia.recaudado_total', $antes['day']['collected'], $despues['day']['collected']);
        $check('dia.operaciones', $antes['day']['count'], $despues['day']['count']);
        $check('dia.recaudado_por_medio', $antes['day']['by_method'], $despues['day']['by_method']);
        $noEntro = (new MoneyLedger(ReportRange::fromRequest(['from' => $c['dia']])))->reversals();
        $check('dia.lo_que_no_entro_sin_cambios', $antes['day']['not_collected']['cancelled_payments'], $noEntro['cancelled_payments']);
        $check('dia.reclasificado_aparte', $despues['day']['not_collected'], [
            'cancelled_payments' => $noEntro['cancelled_payments'],
            'reclassified_payments' => $noEntro['reclassified_payments'],
        ]);

        // El cobro original: anulado y sin periodo.
        $p = $despues['member_payments'][$pago->id];
        $check('cobro.anulado_sin_periodo', ['status' => 'cancelled', 'period_start' => null, 'period_end' => null],
            ['status' => $p['status'], 'period_start' => $p['period_start'], 'period_end' => $p['period_end']]);

        // La membresía, como dijo la vista previa.
        $check('membresia.vencimiento_como_la_vista_previa', $membresia['preview_end_after'], $usuario->membershipEndDate);
        $check('membresia.dias_como_la_vista_previa', $membresia['preview_days_removed'], $membresia['days_removed']);

        // Los demás cobros de la socia: los posteriores, recalculados y con sus
        // días; los anteriores, intactos.
        [$fallosPosteriores, $fallosOtros] = $this->cadena($pago, $antes['member_payments'], $despues['member_payments'], $usuario->membershipEndDate);
        $check('cobros.posteriores_recalculados', [], $fallosPosteriores);
        $check('cobros.anteriores_intactos', [], $fallosOtros);

        // La deuda: saldada, enlazada, y ninguna otra deuda abierta nueva.
        $deuda->refresh();
        $check('deuda.saldada', ['status' => Receivable::STATUS_PAID, 'balance' => '0.00'],
            ['status' => $deuda->status, 'balance' => $deuda->balance()->toDatabase()]);
        $check('deuda.sin_deudas_abiertas_nuevas', $antes['debts'], $despues['debts']);
        $check('cobro_del_mes.enlaces', [$acuerdo->id, $deuda->id], [(int) $cobro->agreement_id, (int) $cobro->receivable_id]);

        // El abono: en el turno original, con la fecha en que entró el dinero,
        // de quien lo recibió, por el importe y el medio del cobro.
        $check('abono.asiento', [
            'cash_shift_id' => $c['turno']->id,
            'created_at' => $c['economico']->utc()->format('Y-m-d H:i:s'),
            'created_by' => (int) $c['recibio']['id'],
            'amount' => number_format((float) $pago->amount, 2, '.', ''),
            'status' => ReceivablePayment::STATUS_APPLIED,
            'balance_after' => '0.00',
            'client_request_id' => self::REQUEST_PREFIX.$pago->id,
        ], [
            'cash_shift_id' => (int) $abono->cash_shift_id,
            'created_at' => $abono->created_at?->utc()->format('Y-m-d H:i:s'),
            'created_by' => (int) $abono->created_by,
            'amount' => (string) $abono->amount,
            'status' => $abono->status,
            'balance_after' => (string) $abono->balance_after,
            'client_request_id' => $abono->client_request_id,
        ]);

        // Sin efectos colaterales: ningún recibo, devolución ni cobro nuevos.
        $check('sin_recibo_nuevo', $antes['receipts'], $despues['receipts']);
        $check('abono.sin_recibo', 0, ReceiptSource::query()->where('source_type', ReceiptSource::TYPE_RECEIVABLE_PAYMENT)->where('source_id', $abono->id)->count());
        $check('sin_devolucion', $antes['refunds'], $despues['refunds']);
        $check('sin_cobro_nuevo', count($antes['member_payments']), count($despues['member_payments']));

        // La factura, si había borrador.
        if ($factura !== null) {
            $check('factura.cancelada', InvoiceStatus::CANCELLED->value, PendingInvoiceCancellation::statusOf($factura));
        }

        // La constancia.
        $check('constancia.enlaces', [
            'payment_id' => $pago->id, 'charge_id' => $cobro->id, 'receivable_id' => $deuda->id,
            'receivable_payment_id' => $abono->id, 'cash_shift_id' => $c['turno']->id,
        ], [
            'payment_id' => (int) $reclasificacion->payment_id, 'charge_id' => (int) $reclasificacion->charge_id,
            'receivable_id' => (int) $reclasificacion->receivable_id, 'receivable_payment_id' => (int) $reclasificacion->receivable_payment_id,
            'cash_shift_id' => (int) $reclasificacion->cash_shift_id,
        ]);

        // Las escrituras, contra la lista blanca y fila a fila.
        [$conteo, $inesperadas] = $this->comprobarEscrituras($pago, $c, $acuerdo, $cobro, $deuda, $factura, $entrenador, $entrenadorCreado);
        $check('escrituras.lista_blanca', [], $inesperadas);
        $check('escrituras.una_vez', [
            'receivable_payments' => 1, 'receivables' => 1, 'trainer_commission_charges' => 1, 'payment_reclassifications' => 1,
            'receivable_payment_splits' => $c['partes']->count(),
        ], [
            'receivable_payments' => $conteo['insert receivable_payments'] ?? 0,
            'receivables' => $conteo['insert receivables'] ?? 0,
            'trainer_commission_charges' => $conteo['insert trainer_commission_charges'] ?? 0,
            'payment_reclassifications' => $conteo['insert payment_reclassifications'] ?? 0,
            'receivable_payment_splits' => $conteo['insert receivable_payment_splits'] ?? 0,
        ]);
        $check('escrituras.altas_como_mucho_una', true,
            ($conteo['insert trainers'] ?? 0) <= 1 && ($conteo['insert identities'] ?? 0) <= 1 && ($conteo['insert trainer_commission_agreements'] ?? 0) <= 1);

        return [$r, $conteo, $inesperadas];
    }

    /**
     * Los demás cobros de la socia, antes y después.
     *
     * Posteriores (pagados, con plan y con un periodo que empezaba en o después
     * del fin del reclasificado: los que recoge MembershipPeriod::revert)
     * conservan sus días, no se solapan y el último termina donde termina la
     * membresía. Todos los demás —los anteriores, los no pagados— quedan
     * idénticos.
     *
     * @param  array<int, array<string, mixed>>  $antes
     * @param  array<int, array<string, mixed>>  $despues
     * @return array{0: list<string>, 1: list<string>} fallos de los posteriores y de los demás
     */
    private function cadena(Payment $pago, array $antes, array $despues, ?string $finMembresia): array
    {
        $propio = $antes[$pago->id] ?? null;
        $finPropio = $propio['period_end'] ?? null;
        $pagados = PaymentController::PAID_STATUSES;

        $posteriores = collect($antes)
            ->filter(fn (array $p, int $id) => $id !== (int) $pago->id && $finPropio !== null
                && in_array(strtolower((string) $p['status']), $pagados, true)
                && $p['plan_id'] !== null && $p['period_start'] !== null && $p['period_end'] !== null
                && $p['period_start'] >= $finPropio)
            ->sortBy(fn (array $p, int $id) => $p['period_start'].'#'.str_pad((string) $id, 12, '0', STR_PAD_LEFT));

        $fallos = [];
        $cursor = $propio['period_start'] ?? null;
        $ultimo = null;
        foreach ($posteriores as $id => $p) {
            $nuevo = $despues[$id] ?? null;
            if ($nuevo === null || $nuevo['period_start'] === null || $nuevo['period_end'] === null) {
                $fallos[] = "el cobro posterior #{$id} perdió su periodo";

                continue;
            }
            if ($this->diasEntre($p['period_start'], $p['period_end']) !== $this->diasEntre($nuevo['period_start'], $nuevo['period_end'])) {
                $fallos[] = "el cobro posterior #{$id} cambió de duración";
            }
            if ($cursor !== null && $nuevo['period_start'] < $cursor) {
                $fallos[] = "el cobro posterior #{$id} empieza antes de que termine el anterior";
            }
            $cursor = $nuevo['period_end'];
            $ultimo = $nuevo['period_end'];
        }
        if ($ultimo !== null && $ultimo !== $finMembresia) {
            $fallos[] = "la membresía termina el {$finMembresia} y el último periodo el {$ultimo}";
        }

        $otros = [];
        foreach ($antes as $id => $p) {
            if ($id !== (int) $pago->id && ! $posteriores->has($id) && ($despues[$id] ?? null) !== $p) {
                $otros[] = "el cobro #{$id}, que no es posterior, cambió";
            }
        }

        return [$fallos, $otros];
    }

    /**
     * Cada escritura contra la lista blanca: tabla y operación permitidas, y en
     * UPDATE/DELETE la fila tiene que ser una de las del procedimiento.
     *
     * @param  array<string, mixed>  $c
     * @return array{0: array<string, int>, 1: list<string>}
     */
    private function comprobarEscrituras(
        Payment $pago,
        array $c,
        TrainerCommissionAgreement $acuerdo,
        TrainerCommissionCharge $cobro,
        Receivable $deuda,
        ?ElectronicInvoice $factura,
        Trainer $entrenador,
        bool $entrenadorCreado,
    ): array {
        $socioApp = Member::query()->where('user_id', $pago->user_id)->value('id');
        $usuarioEntrenador = app(TrainerCommissionAccess::class)->userOf($entrenador->fresh());
        $filas = [
            'payments' => $c['pagos_de_la_socia'],
            'users' => [(int) $pago->user_id],
            // Solo el entrenador dado de alta aquí (su enlace de identidad).
            'trainers' => $entrenadorCreado ? [(int) $entrenador->id] : [],
            'trainer_commission_agreements' => [(int) $acuerdo->id],
            'receivables' => [(int) $deuda->id],
            'trainer_commission_charges' => [(int) $cobro->id],
            'electronic_invoices' => $factura !== null ? [(int) $factura->id] : [],
            'plans' => $c['archivar'] ? [(int) $pago->plan_id] : [],
            'employee_accesses' => $usuarioEntrenador === null ? []
                : EmployeeAccess::query()->where('user_id', $usuarioEntrenador->id)->pluck('id')->map(fn ($v) => (int) $v)->all(),
        ];
        // De quién puede ser una fila insertada o borrada sin id.
        $duenos = [
            'member_realtime_events' => ['member_id', $socioApp !== null ? [(int) $socioApp] : []],
            'employee_accesses' => ['user_id', $usuarioEntrenador !== null ? [(int) $usuarioEntrenador->id] : []],
        ];

        $conteo = [];
        $inesperadas = [];
        foreach ($this->escrituras as $e) {
            $conteo[$e['firma']] = ($conteo[$e['firma']] ?? 0) + 1;
            [$op, $tabla] = explode(' ', $e['firma'], 2);

            if (! in_array($op, self::WRITABLE[$tabla] ?? [], true)) {
                $inesperadas[] = "{$e['firma']}: tabla u operación fuera de la lista blanca";

                continue;
            }

            if ($op === 'insert') {
                if (isset($duenos[$tabla]) && ! in_array((int) $this->valorDeColumna($e, $duenos[$tabla][0]), $duenos[$tabla][1], true)) {
                    $inesperadas[] = "{$e['firma']}: fila de otra persona";
                }

                continue;
            }

            // UPDATE o DELETE: acotado por id a una de las filas permitidas, o
            // (señales en tiempo real) al socio titular.
            if (preg_match('/\bwhere\s+["`]?id["`]?\s*=\s*\?\s*$/i', $e['sql']) === 1) {
                $fila = (int) end($e['bindings']);
                if (! in_array($fila, $filas[$tabla] ?? [], true)) {
                    $inesperadas[] = "{$e['firma']} #{$fila}: fila ajena al procedimiento";
                }

                continue;
            }
            if ($tabla === 'member_realtime_events' && preg_match('/\bwhere\s+["`]?member_id["`]?\s*=\s*\?/i', $e['sql']) === 1
                && in_array((int) ($e['bindings'][0] ?? 0), $duenos['member_realtime_events'][1], true)) {
                continue;
            }

            $inesperadas[] = "{$e['firma']}: escritura no acotada por id";
        }

        ksort($conteo);

        return [$conteo, $inesperadas];
    }

    /** El valor de una columna en un INSERT de una sola fila, por su posición. */
    private function valorDeColumna(array $e, string $columna): mixed
    {
        if (preg_match('/\(\s*(.+?)\s*\)\s*values/is', $e['sql'], $m) !== 1) {
            return null;
        }
        $columnas = array_map(fn (string $c) => trim($c, " \"`\t\n"), explode(',', $m[1]));
        $i = array_search($columna, $columnas, true);

        return $i === false ? null : ($e['bindings'][$i] ?? null);
    }

    // ────────────────────────────────────────────────────────────────────────
    //  Fotos, trazas y piezas
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Lo que se compara antes y después. Sin datos personales: ids, importes,
     * estados, fechas y totales.
     *
     * @param  array<string, mixed>  $c
     * @return array<string, mixed>
     */
    private function foto(Payment $pago, array $c, ?Trainer $entrenador = null): array
    {
        $turno = CashShift::query()->findOrFail($c['turno']->id);
        $vivo = $this->totals->for($turno);
        $contraste = $this->shiftReport->for($turno)['consistency'];
        $libro = new MoneyLedger(ReportRange::fromRequest(['from' => $c['dia']]));
        $totales = $libro->totals();
        $noEntro = $libro->reversals();
        $usuario = User::query()->findOrFail($pago->user_id);

        $deudor = fn (DebtorType $tipo, int $id) => Receivable::query()->outstanding()
            ->where('debtor_type', $tipo->value)->where('debtor_id', $id)->count();
        $entrenadorId = $entrenador?->id ?? $c['entrenador']?->id;

        return [
            'shift' => [
                'id' => $turno->id,
                'status' => $turno->status->value,
                'closed_at' => optional($turno->closed_at)->toIso8601String(),
                'frozen' => $this->congelados($turno),
                'live' => [
                    'gross_total' => $vivo['gross_total'],
                    'cash_total' => $vivo['cash_total'],
                    'transfer_total' => $vivo['transfer_total'],
                    'card_total' => $vivo['card_total'],
                    'wompi_total' => $vivo['wompi_total'],
                    'other_total' => $vivo['other_total'],
                    'operations_count' => (int) $vivo['operations_count'],
                    'expected_cash' => $vivo['expected_cash'],
                    'refunds_total' => $vivo['refunds_total'],
                ],
                'consistency' => [
                    'frozen_total' => $contraste['frozen_total'],
                    'detail_total' => $contraste['detail_total'],
                    'matches' => (bool) $contraste['matches'],
                ],
            ],
            'day' => [
                'date' => $c['dia'],
                'collected' => $totales['collected'],
                'count' => $totales['count'],
                'by_source' => collect($totales['by_source'])->mapWithKeys(fn (array $f) => [$f['key'] => $f['amount']])->all(),
                'by_method' => collect($libro->byMethod())->mapWithKeys(fn (array $m) => [$m['key'] => $m['amount']])->sortKeys()->all(),
                'not_collected' => [
                    'cancelled_payments' => $noEntro['cancelled_payments'],
                    'reclassified_payments' => $noEntro['reclassified_payments'] ?? null,
                ],
            ],
            'member_payments' => Payment::query()->where('user_id', $pago->user_id)->orderBy('id')
                ->get(['id', 'status', 'amount', 'method', 'plan_id', 'starts_on', 'period_start', 'period_end', 'cash_shift_id'])
                ->mapWithKeys(fn (Payment $p) => [(int) $p->id => [
                    'status' => (string) $p->status,
                    'amount' => number_format((float) $p->amount, 2, '.', ''),
                    'method' => $p->method,
                    'plan_id' => $p->plan_id !== null ? (int) $p->plan_id : null,
                    'starts_on' => $p->starts_on?->toDateString(),
                    'period_start' => $p->period_start?->toDateString(),
                    'period_end' => $p->period_end?->toDateString(),
                    'cash_shift_id' => $p->cash_shift_id !== null ? (int) $p->cash_shift_id : null,
                ]])
                ->all(),
            'membership' => [
                'user_id' => (int) $usuario->id,
                'start' => $usuario->membershipStartDate,
                'end' => $usuario->membershipEndDate,
            ],
            'debts' => [
                'outstanding_member' => $deudor(DebtorType::MEMBER, (int) $c['socia']->id),
                'outstanding_trainer' => $entrenadorId === null ? 0 : $deudor(DebtorType::TRAINER, (int) $entrenadorId),
            ],
            'receipts' => ['payment' => ReceiptSource::query()->where('source_type', ReceiptSource::TYPE_PAYMENT)->where('source_id', $pago->id)->count()],
            'refunds' => ['payment' => CashRefund::query()->where('refundable_type', $pago->getMorphClass())->where('refundable_id', $pago->id)->count()],
        ];
    }

    /** @return array<string, string|int|null> los totales congelados al cerrar, normalizados */
    private function congelados(CashShift $turno): array
    {
        $dinero = fn ($v) => $v === null ? null : number_format((float) $v, 2, '.', '');

        return [
            'sales_total' => $dinero($turno->sales_total),
            'cash_sales_total' => $dinero($turno->cash_sales_total),
            'transfer_total' => $dinero($turno->transfer_total),
            'card_total' => $dinero($turno->card_total),
            'wompi_total' => $dinero($turno->wompi_total),
            'other_total' => $dinero($turno->other_total),
            'operations_count' => $turno->operations_count !== null ? (int) $turno->operations_count : null,
            'expected_amount' => $dinero($turno->expected_amount),
        ];
    }

    /**
     * Dónde no coinciden los totales en vivo con los congelados del cierre
     * (las columnas congeladas vacías, de turnos antiguos, no se comparan).
     *
     * @param  array<string, mixed>  $vivo
     * @return list<string>
     */
    private function descuadres(CashShift $turno, array $vivo): array
    {
        $congelado = $this->congelados($turno);
        $pares = [
            'sales_total' => 'gross_total', 'cash_sales_total' => 'cash_total', 'transfer_total' => 'transfer_total',
            'card_total' => 'card_total', 'wompi_total' => 'wompi_total', 'other_total' => 'other_total',
            'operations_count' => 'operations_count', 'expected_amount' => 'expected_cash',
        ];

        $out = [];
        foreach ($pares as $columna => $clave) {
            if ($congelado[$columna] === null) {
                continue;
            }
            $real = $columna === 'operations_count' ? (int) $vivo[$clave] : number_format((float) $vivo[$clave], 2, '.', '');
            if ($real !== $congelado[$columna]) {
                $out[] = "{$columna}: congelado {$congelado[$columna]}, en vivo {$real}";
            }
        }

        return $out;
    }

    /**
     * La traza del cobro del mes: la misma que deja `POST
     * /api/admin/commissions/agreements/{id}/charge` al abrir la deuda.
     */
    private function auditarCobro(TrainerCommissionAgreement $acuerdo, TrainerCommissionCharge $cobro, Payment $pago): void
    {
        $this->audit->record(request(), [
            'action' => 'create',
            'module' => 'Comisiones',
            'entity' => 'piso de entrenador',
            'entity_id' => (string) $acuerdo->id,
            'target_name' => $acuerdo->trainer?->full_name,
            'summary' => 'Abrió la deuda del piso de '.$cobro->periodLabel().' (reclasificación del cobro #'.$pago->id.')',
            'metadata' => [
                'period' => $cobro->period?->toDateString(),
                'amount' => (float) $cobro->amount,
                'payer' => $cobro->payer,
                'receivable_id' => $cobro->receivable_id,
                'reclassified_payment_id' => $pago->id,
                'origin' => self::procedure(),
            ],
        ]);
    }

    /**
     * La traza de la reclasificación: en la historia del cobro, con todos los
     * enlaces. `create` y no `update`: el informe del turno ya lista la
     * reclasificación como corrección posterior al cierre (y la anulación del
     * cobro tiene su propia traza); repetirla la contaría dos veces.
     *
     * @param  array<string, mixed>  $c
     */
    private function auditarReclasificacion(PaymentReclassification $r, Payment $pago, array $c): void
    {
        $this->audit->record(request(), [
            'action' => 'create',
            'module' => 'Pagos',
            'entity' => 'pago',
            'entity_id' => (string) $pago->id,
            'target_name' => $pago->reference,
            'summary' => "Reclasificó el cobro #{$pago->id}: el dinero era el piso de un entrenador y sigue en el turno #{$c['turno']->id} como el abono #{$r->receivable_payment_id}",
            'metadata' => [
                'event' => 'payment.reclassified',
                'reclassification_id' => $r->id,
                'kind' => $r->kind,
                'amount' => (string) $r->amount,
                'cash_shift_id' => $r->cash_shift_id,
                'agreement_id' => $r->agreement_id,
                'charge_id' => $r->charge_id,
                'receivable_id' => $r->receivable_id,
                'receivable_payment_id' => $r->receivable_payment_id,
                'original_receipt_id' => $r->original_receipt_id,
                'cancelled_invoice_id' => $r->cancelled_invoice_id,
                'economic_at' => optional($r->economic_at)->toIso8601String(),
                'membership_end_before' => optional($r->membership_end_before)->toDateString(),
                'membership_end_after' => optional($r->membership_end_after)->toDateString(),
                'membership_days_removed' => $r->membership_days_removed,
                'reason' => $r->reason,
                'procedure' => $r->procedure,
            ],
        ]);
    }

    /**
     * Quién recibió el dinero: `--received-by` o quien registró el cobro. Con el
     * nombre que el cobro congeló entonces, si es la misma persona.
     *
     * @return array{id: int, name: string}
     */
    private function receptor(Payment $pago, mixed $pedido): array
    {
        $id = $pedido !== null && $pedido !== '' ? (int) $pedido : ($pago->registered_by !== null ? (int) $pago->registered_by : null);
        if ($id === null) {
            throw new ReclassificationRejected('received_by_unknown', "No consta quién recibió el dinero del cobro #{$pago->id}: indica --received-by.");
        }
        // Cuando el cobro dice quién lo registró, el dinero lo recibió esa
        // persona: --received-by no puede moverlo en silencio a otro cajero en
        // un turno cerrado (revisión de seguridad H-6).
        if ($pago->registered_by !== null && (int) $pago->registered_by !== $id) {
            throw new ReclassificationRejected('received_by_mismatch',
                "El cobro #{$pago->id} lo registró el administrador #{$pago->registered_by}: --received-by no puede ser otro.");
        }
        $admin = Admin::query()->find($id)
            ?? throw new ReclassificationRejected('received_by_not_found', "--received-by={$id} no es un administrador.");

        $mismo = (int) $pago->registered_by === $id && filled($pago->registered_by_name);

        return ['id' => (int) $admin->id, 'name' => $mismo ? (string) $pago->registered_by_name : (string) $admin->name];
    }

    /**
     * El actor: un administrador ACTIVO con las llaves de cada vía oficial que
     * el procedimiento recorre. Por consola no corre el middleware, así que se
     * comprueban aquí: anular cobros, administrar la caja del gimnasio (el
     * turno está cerrado), pactar y cobrar pisos; y archivar planes si se pide.
     *
     * @param  array<string, mixed>  $o
     */
    private function actor(array $o): Admin
    {
        $actor = Admin::query()->find((int) $o['actor_id']);
        if ($actor === null || ! $actor->isActive()) {
            throw new ReclassificationRejected('actor_invalid', "--actor={$o['actor_id']} no es un administrador activo: la corrección necesita una persona que la firme.");
        }

        $necesarias = ['payments.cancel', CashShiftType::GYM->managePermission(), CrmPermission::COMMISSIONS_MANAGE, CrmPermission::COMMISSIONS_CHARGE];
        if (! empty($o['archive_plan'])) {
            $necesarias[] = CrmPermission::PLANS_ARCHIVE;
            // Si archivar no es seguro, se bloquea su cobro en recepción con la
            // edición oficial del plan, que pide este permiso.
            $necesarias[] = 'plans.manage';
        }
        $faltan = array_values(array_filter($necesarias, fn (string $p) => ! CrmPermission::allows($actor, $p)));
        if ($faltan !== []) {
            throw new ReclassificationRejected('actor_forbidden', 'A --actor le faltan permisos: '.implode(', ', $faltan).'.');
        }

        return $actor;
    }

    /** Una petición firmada por el actor, desde esta máquina y con este procedimiento como User-Agent. */
    private function peticion(Admin $actor, string $metodo, string $ruta, array $cuerpo = []): Request
    {
        $peticion = Request::create($ruta, $metodo, $cuerpo, server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_USER_AGENT' => self::USER_AGENT,
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $peticion->attributes->set('auth_admin', $actor);

        return $peticion;
    }

    /**
     * Lo que se lee en la deuda, el abono y el acuerdo: de qué cobro sale y
     * cuál es su comprobante. Sin datos personales.
     *
     * @param  array<string, mixed>  $c
     */
    private function nota(Payment $pago, array $c): string
    {
        $recibo = $c['recibo']?->number;

        return "Reclasificación del cobro #{$pago->id}".($recibo ? " ({$recibo})" : '')
            .': piso de entrenador registrado por error como venta de plan. El dinero entró el '
            .$c['dia'].' en el turno #'.$c['turno']->id.'.';
    }

    /** Días de calendario de `$desde` a `$hasta`; negativo si `$hasta` es anterior. */
    private function diasEntre(?string $desde, ?string $hasta): ?int
    {
        if ($desde === null || $hasta === null) {
            return null;
        }

        $a = CarbonImmutable::parse($desde, MembershipPeriod::TZ)->startOfDay();
        $b = CarbonImmutable::parse($hasta, MembershipPeriod::TZ)->startOfDay();

        return (int) round($a->diffInDays($b, false));
    }

    /** Un fallo de dominio de otra pieza, convertido en rechazo; uno de la base sigue siendo un error. */
    private function dominio(RuntimeException $e, string $codigo, string $prefijo): Throwable
    {
        return $e instanceof PDOException ? $e : new ReclassificationRejected($codigo, "{$prefijo}: {$e->getMessage()}");
    }

    /**
     * Quién ejecutó la orden en el servidor, además del actor que la firma:
     * el usuario del sistema, el que hizo sudo y el equipo. No son datos de
     * socios (revisión de seguridad H-6).
     *
     * @return array{system_user: ?string, sudo_user: ?string, host: ?string}
     */
    private static function ejecutor(): array
    {
        $usuario = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
            ? (posix_getpwuid(posix_geteuid())['name'] ?? null)
            : (get_current_user() ?: null);

        return [
            'system_user' => $usuario,
            'sudo_user' => getenv('SUDO_USER') ?: null,
            'host' => gethostname() ?: null,
        ];
    }

    /** ¿Escribe en las tablas de la caché de la aplicación (las de la configuración)? */
    private static function esEscrituraDeCache(string $sql): bool
    {
        $tablas = array_filter([
            (string) config('cache.stores.database.table', 'cache'),
            (string) (config('cache.stores.database.lock_table') ?: 'cache_locks'),
        ]);
        $alternativas = implode('|', array_map(fn (string $t) => preg_quote($t, '/'), $tablas));

        return preg_match('/^\s*(?:insert\s+into|update|delete\s+from)\s+"?(?:'.$alternativas.')"?[\s(]/i', $sql) === 1;
    }

    /** Anota cada sentencia que escribe mientras `grabando` está encendido. */
    private function escucharEscrituras(): void
    {
        if ($this->escuchando) {
            return;
        }
        $this->escuchando = true;

        DB::listen(function (QueryExecuted $q): void {
            // La caché de la aplicación (CACHE_STORE=database en producción:
            // p. ej. los permisos por rol, que caducan cada 300 s) no es un dato
            // de negocio: que se renueve a mitad del procedimiento no debe
            // tumbar la lista blanca (revisión de calidad 6).
            if (self::esEscrituraDeCache($q->sql)) {
                return;
            }
            if ($this->grabando && ($firma = DeletedPlanRestorer::writeSignature($q->sql)) !== null) {
                $this->escrituras[] = ['firma' => $firma, 'sql' => $q->sql, 'bindings' => array_values($q->bindings)];
            }
        });
    }

    // ────────────────────────────────────────────────────────────────────────
    //  El informe
    // ────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function informeVacio(array $o, bool $aplicar): array
    {
        return [
            'procedure' => self::procedure(),
            'mode' => $aplicar ? 'apply' : 'dry_run',
            'status' => null,
            'exit_code' => null,
            'message' => null,
            'problem' => null,
            'payment_id' => (int) $o['payment_id'],
            'reclassification_id' => null,
            'attempts' => 0,
            'membership' => null,
            'created' => null,
            'invoice' => null,
            'plan_archive' => null,
            'before' => null,
            'after' => null,
            'invariants' => [],
            'writes' => [],
            'unexpected_writes' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function cerrar(array $informe, string $estado, int $salida, string $mensaje): array
    {
        $informe['status'] = $estado;
        $informe['exit_code'] = $salida;
        $informe['message'] = $mensaje;

        return $informe;
    }

    /** @return array<string, mixed> */
    private function yaAplicada(array $informe, PaymentReclassification $r): array
    {
        $informe['reclassification_id'] = $r->id;
        $informe['created'] = [
            'agreement_id' => $r->agreement_id,
            'charge_id' => $r->charge_id,
            'receivable_id' => $r->receivable_id,
            'receivable_payment_id' => $r->receivable_payment_id,
            'cancelled_invoice_id' => $r->cancelled_invoice_id,
            'original_receipt_id' => $r->original_receipt_id,
        ];
        $informe['membership'] = [
            'end_before' => optional($r->membership_end_before)->toDateString(),
            'end_after' => optional($r->membership_end_after)->toDateString(),
            'days_removed' => $r->membership_days_removed,
        ];

        return $this->cerrar($informe, self::STATUS_ALREADY, self::EXIT_OK,
            "Ya aplicada: el cobro #{$r->payment_id} se reclasificó el ".optional($r->performed_at)->toIso8601String()
            ." (constancia #{$r->id}). No se escribió nada.");
    }

    /** @return array<string, mixed> */
    private function rechazada(array $informe, ReclassificationRejected $e): array
    {
        $informe['problem'] = ['code' => $e->code_, 'message' => $e->getMessage()];

        return $this->cerrar($informe, self::STATUS_REJECTED, self::EXIT_REJECTED,
            'Precondición no cumplida: '.$e->getMessage().' Nada quedó escrito.');
    }

    /**
     * Un error inesperado. Al informe va el tipo, no el mensaje: el de la base
     * llevaría SQL y nombres de tabla. El detalle completo queda en el log.
     *
     * @return array<string, mixed>
     */
    private function fallo(array $informe, Throwable $e): array
    {
        report($e);
        $informe['problem'] = ['code' => 'unexpected_error', 'message' => class_basename($e)];

        return $this->cerrar($informe, self::STATUS_ERROR, self::EXIT_ERROR,
            'Error inesperado ('.class_basename($e).'). Se hizo ROLLBACK: nada quedó escrito. El detalle está en el log.');
    }
}

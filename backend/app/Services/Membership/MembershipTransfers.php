<?php

namespace App\Services\Membership;

use App\Exceptions\MembershipTransferException;
use App\Http\Controllers\Api\PaymentController;
use App\Models\Attendance;
use App\Models\ClassAttendance;
use App\Models\EmployeeAccess;
use App\Models\Member;
use App\Models\MembershipAdjustment;
use App\Models\MembershipTransfer;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Receivable;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use App\Services\Caja\MembershipFinancialStanding;
use App\Services\Classes\ClassBookingService;
use App\Services\Payments\MembershipPeriod;
use App\Services\RealtimeEvents;
use App\Support\Access\AdminActor;
use App\Support\Access\TrainerMemberScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * TRANSFERIR MEMBRESÍA: la titular (A) cede lo que le queda del periodo de UN
 * cobro a otra persona (B). Contrato: docs/ops/TRANSFERENCIA-DE-MEMBRESIAS.md.
 *
 * SE TRANSFIERE EL DERECHO DE USO, NADA MÁS. Se escriben cuatro columnas de
 * `users` —las mismas que lee la puerta, la app y el CRM— y una fila de traza:
 *
 *   A → `membership_end_date` = ayer (D − 1), y el inicio solo si era posterior.
 *       Plan y estado, igual. Con el fin en el pasado la deniegan el
 *       torniquete local (fin ≥ hoy), la app (MembershipService::isActive) y
 *       el CRM (MembershipAccess). Es el mismo truco que el congelamiento,
 *       sin tocar el congelamiento.
 *   B → plan = nombre EXACTO del plan del cobro, inicio = hoy (D), fin = el que
 *       tenía A (E), estado `active`: lo mismo que escribe
 *       MembershipPeriod::apply() cuando alguien paga.
 *
 * El cobro, su factura, sus abonos y sus recibos siguen siendo de A: no se crea
 * cobro, ni venta, ni factura, y no se cambia el dueño de nada histórico. Por
 * eso la fila de `membership_transfers` es imprescindible: es lo único que
 * explica la vigencia de B y lo que impide que alguien «reconstruya» a A desde
 * sus pagos o anule el cobro que B está usando.
 *
 * SOLO LO QUE TIENE POLÍTICA. Todo lo demás se bloquea con su motivo
 * ({@see evaluate()}): congelada, vencida, plan por entradas, mejora, deuda,
 * vigencia que no explica un único cobro, reservas por delante…
 *
 * BAJO BLOQUEO, EN ORDEN FIJO: los dos socios por id ascendente, el cobro de
 * origen y sus deudas — el orden de toda la caja (socio → cobro → deuda). La
 * vista previa NUNCA se da por buena: se vuelve a evaluar con todo bloqueado.
 * Dos cesiones del mismo cobro esperan la una a la otra en la fila de A; la
 * segunda ve la primera ya confirmada. Y si algo se cuela, el índice único
 * parcial (un traspaso vivo por cobro) la para.
 *
 * Las señales a la app salen DESPUÉS del commit, como en PlanTransfers.
 */
class MembershipTransfers
{
    /** Ficha de socio con la que B puede registrar su rostro (AttendanceController::faceEnrollmentPending). */
    public const BENEFICIARY_MEMBER_STATUSES = [
        Member::STATUS_ACTIVE,
        Member::STATUS_PENDING_REGISTRATION,
        Member::STATUS_INCOMPLETE,
    ];

    /** Estados de cuenta que el torniquete trata como baja. */
    private const INACTIVE_ACCOUNT_STATUSES = ['inactive', 'expired', 'inactivo', 'vencido'];

    /** Avisos que solo informan. */
    private const INFO_WARNINGS = ['holder_employee_access', 'beneficiary_employee_access'];

    public const EVENT_TRANSFERRED = 'membership.transferred';

    public const EVENT_REVERTED = 'membership.transfer.reverted';

    public function __construct(
        private readonly PlanTransferEvaluator $evaluator,
        private readonly MembershipFinancialStanding $standing,
        private readonly ClassBookingService $classes,
        private readonly AuditTrail $audit,
    ) {}

    public static function today(): CarbonImmutable
    {
        return MembershipPeriod::today();
    }

    // ────────────────────────────────────────────────────────────────────────
    //  Vista previa
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Qué pasaría, sin escribir nada. Sin beneficiario, solo la parte de A
     * (`allowed` dice entonces si A puede ceder).
     *
     * @return array<string, mixed>
     */
    public function preview(User $holder, ?User $beneficiary): array
    {
        return $this->present($this->evaluate($holder, $beneficiary));
    }

    // ────────────────────────────────────────────────────────────────────────
    //  Transferir
    // ────────────────────────────────────────────────────────────────────────

    /**
     * @param  array{reason: string, evidence?: ?string, client_request_id: string}  $input
     * @return array{transfer: MembershipTransfer, replayed: bool}
     *
     * @throws MembershipTransferException
     */
    public function transfer(Request $request, User $holder, int $beneficiaryId, array $input): array
    {
        $llave = $input['client_request_id'];

        // EL REINTENTO VA PRIMERO: el doble clic encuentra el traspaso del primero.
        if ($previo = $this->replay($llave, (int) $holder->id, $beneficiaryId)) {
            return ['transfer' => $previo, 'replayed' => true];
        }

        try {
            $resultado = DB::transaction(function () use ($request, $holder, $beneficiaryId, $input, $llave): array {
                // (1) LOS DOS SOCIOS, por id ascendente.
                $ids = array_values(array_unique([(int) $holder->id, $beneficiaryId]));
                sort($ids);
                $users = User::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $a = $users->get($holder->id);
                $b = $users->get($beneficiaryId);
                if ($a === null || $b === null) {
                    throw MembershipTransferException::blocked([$this->blocker('member_not_found', 'Uno de los dos socios ya no existe.')]);
                }

                // Otra petición con la misma llave pudo confirmarse mientras
                // esta esperaba los bloqueos.
                if ($previo = $this->replay($llave, (int) $a->id, (int) $b->id)) {
                    return ['transfer' => $previo, 'replayed' => true];
                }

                // (2) y (3): el cobro de origen y sus deudas, bloqueados dentro.
                $ev = $this->evaluate($a, $b, lock: true);
                if ($ev['blockers'] !== []) {
                    throw MembershipTransferException::blocked($ev['blockers']);
                }
                // Sin cobro, plan o fin no hay nada que escribir: si algún día
                // una regla deja pasar eso sin bloqueo, se dice, no se revienta.
                if ($ev['source'] === null || $ev['plan'] === null || $ev['end'] === null || $ev['days'] === null) {
                    throw MembershipTransferException::blocked([$this->blocker('no_source_payment',
                        'Su vigencia no la explica un cobro pagado. No se puede ceder.')]);
                }

                return ['transfer' => $this->apply($request, $a, $b, $ev, $input), 'replayed' => false];
            }, 3);
        } catch (UniqueConstraintViolationException $e) {
            // Se resuelve FUERA de la transacción: en PostgreSQL la violación la
            // deja abortada y cualquier lectura dentro fallaría.
            if ($previo = $this->replay($llave, (int) $holder->id, $beneficiaryId)) {
                return ['transfer' => $previo, 'replayed' => true];
            }
            // La otra ganó con OTRA llave: el cobro ya está cedido.
            if ($this->liveTransferOf($holder) !== null) {
                throw MembershipTransferException::blocked([$this->alreadyTransferred()]);
            }

            throw $e;
        }

        if (! $resultado['replayed']) {
            $this->signal([(int) $holder->id, $beneficiaryId]);
        }

        return $resultado;
    }

    /**
     * @param  array<string, mixed>  $ev
     * @param  array{reason: string, evidence?: ?string, client_request_id: string}  $input
     */
    private function apply(Request $request, User $a, User $b, array $ev, array $input): MembershipTransfer
    {
        /** @var Payment $origen */
        $origen = $ev['source'];
        /** @var Plan $plan */
        $plan = $ev['plan'];
        $hoy = $ev['today'];
        $fin = $ev['end'];
        $ayer = $hoy->subDay();
        $ahora = now();
        $actor = AdminActor::from($request);

        $antesA = $this->snapshot($a);
        $antesB = $this->snapshot($b);

        // A: pierde el acceso en el acto. Plan y estado, intactos.
        $a->membership_end_date = $ayer->toDateString();
        if ($antesA['start'] === null || $antesA['start'] > $ayer->toDateString()) {
            $a->membership_start_date = $ayer->toDateString();
        }
        $a->save();

        // B: lo mismo que escribe MembershipPeriod::apply() al cobrar.
        $b->plan = $plan->name;
        $b->membership_start_date = $hoy->toDateString();
        $b->membership_end_date = $fin->toDateString();
        $b->status = 'active';
        $b->save();

        $traspaso = MembershipTransfer::create([
            'holder_user_id' => $a->id,
            'beneficiary_user_id' => $b->id,
            'source_payment_id' => $origen->id,
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'effective_date' => $hoy->toDateString(),
            'end_date' => $fin->toDateString(),
            'days' => $ev['days'],
            'holder_plan_before' => $antesA['plan'],
            'holder_start_before' => $antesA['start'],
            'holder_end_before' => $antesA['end'],
            'holder_status_before' => $antesA['status'],
            'beneficiary_plan_before' => $antesB['plan'],
            'beneficiary_start_before' => $antesB['start'],
            'beneficiary_end_before' => $antesB['end'],
            'beneficiary_status_before' => $antesB['status'],
            'reason' => $input['reason'],
            'evidence' => $input['evidence'] ?? null,
            'holder_consent_at' => $ahora,
            'beneficiary_acceptance_at' => $ahora,
            'created_by' => $actor?->id,
            'created_by_name' => $actor?->name,
            'client_request_id' => $input['client_request_id'],
        ]);

        $despuesA = $this->snapshot($a);
        $despuesB = $this->snapshot($b);
        $comun = [
            'event' => self::EVENT_TRANSFERRED,
            'transfer_id' => $traspaso->id,
            'holder_user_id' => (int) $a->id,
            'beneficiary_user_id' => (int) $b->id,
            'payment_id' => (int) $origen->id,
            'plan_id' => (int) $plan->id,
            'plan_name' => $plan->name,
            'effective_date' => $hoy->toDateString(),
            'end_date' => $fin->toDateString(),
            'days' => $ev['days'],
            'reason' => $input['reason'],
            'evidence' => $input['evidence'] ?? null,
            'changed' => ['membership', 'features', 'history'],
        ];

        $this->audit->record($request, [
            'action' => 'update',
            'module' => 'Miembros',
            'entity' => 'membresía',
            'entity_id' => $a->id,
            'target_name' => $a->name,
            'summary' => 'Cedió su membresía «'.$plan->name.'» a '.$b->name.': '.$ev['days'].' '.($ev['days'] === 1 ? 'día' : 'días').
                ' (traspaso #'.$traspaso->id.')',
            'changes' => $this->changes($antesA, $despuesA),
            'metadata' => $comun + ['user_id' => (int) $a->id, 'role' => 'holder', 'before' => $antesA, 'after' => $despuesA],
        ]);
        $this->audit->record($request, [
            'action' => 'update',
            'module' => 'Miembros',
            'entity' => 'membresía',
            'entity_id' => $b->id,
            'target_name' => $b->name,
            'summary' => 'Recibió la membresía «'.$plan->name.'» de '.$a->name.': '.$ev['days'].' '.($ev['days'] === 1 ? 'día' : 'días').
                ', del '.$hoy->toDateString().' al '.$fin->toDateString().' (traspaso #'.$traspaso->id.')',
            'changes' => $this->changes($antesB, $despuesB),
            'metadata' => $comun + ['user_id' => (int) $b->id, 'role' => 'beneficiary', 'before' => $antesB, 'after' => $despuesB],
        ]);

        return $traspaso;
    }

    // ────────────────────────────────────────────────────────────────────────
    //  Revertir
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Deshace un traspaso equivocado: devuelve a los dos los valores guardados.
     * Solo mientras nada haya cambiado después y B no haya entrado.
     *
     * @return array{transfer: MembershipTransfer, already: bool}
     *
     * @throws MembershipTransferException
     */
    public function revert(Request $request, MembershipTransfer $transfer, string $reason): array
    {
        if (! $transfer->isLive()) {
            return ['transfer' => $transfer, 'already' => true];
        }

        $resultado = DB::transaction(function () use ($request, $transfer, $reason): array {
            // Socios → cobro → traspaso. El mismo orden que al transferir.
            $ids = array_values(array_filter([(int) $transfer->holder_user_id, (int) $transfer->beneficiary_user_id]));
            sort($ids);
            $users = User::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($transfer->source_payment_id !== null) {
                Payment::whereKey($transfer->source_payment_id)->lockForUpdate()->first();
            }
            $t = MembershipTransfer::query()->whereKey($transfer->id)->lockForUpdate()->firstOrFail();
            if (! $t->isLive()) {
                return ['transfer' => $t, 'already' => true];
            }

            $a = $t->holder_user_id !== null ? $users->get($t->holder_user_id) : null;
            $b = $t->beneficiary_user_id !== null ? $users->get($t->beneficiary_user_id) : null;
            $bloqueos = $this->revertBlockers($t, $a, $b);
            if ($bloqueos !== []) {
                throw MembershipTransferException::revertBlocked($bloqueos);
            }

            $actor = AdminActor::from($request);
            $antesA = $this->snapshot($a);
            $antesB = $this->snapshot($b);

            $a->forceFill([
                'plan' => $t->holder_plan_before,
                'membership_start_date' => $t->day('holder_start_before'),
                'membership_end_date' => $t->day('holder_end_before'),
                'status' => $t->holder_status_before,
            ])->save();
            $b->forceFill([
                'plan' => $t->beneficiary_plan_before,
                'membership_start_date' => $t->day('beneficiary_start_before'),
                'membership_end_date' => $t->day('beneficiary_end_before'),
                'status' => $t->beneficiary_status_before,
            ])->save();

            $t->forceFill([
                'reverted_at' => now(),
                'reverted_by' => $actor?->id,
                'reverted_by_name' => $actor?->name,
                'revert_reason' => $reason,
            ])->save();

            $despuesA = $this->snapshot($a);
            $despuesB = $this->snapshot($b);
            $comun = [
                'event' => self::EVENT_REVERTED,
                'transfer_id' => $t->id,
                'holder_user_id' => (int) $a->id,
                'beneficiary_user_id' => (int) $b->id,
                'payment_id' => $t->source_payment_id,
                // Si el plan se borró del catálogo, el del cobro de origen; si
                // tampoco, nulo: el canal del CRM deja pasar estos dos eventos
                // sin plan (MembershipRealtimeController).
                'plan_id' => $t->plan_id ?? ($t->source_payment_id !== null
                    ? Payment::whereKey($t->source_payment_id)->value('plan_id') : null),
                'plan_name' => $t->plan_name,
                'reason' => $reason,
                'changed' => ['membership', 'features', 'history'],
            ];
            foreach ([['holder', $a, $antesA, $despuesA], ['beneficiary', $b, $antesB, $despuesB]] as [$rol, $socio, $antes, $despues]) {
                $this->audit->record($request, [
                    'action' => 'update',
                    'module' => 'Miembros',
                    'entity' => 'membresía',
                    'entity_id' => $socio->id,
                    'target_name' => $socio->name,
                    'summary' => 'Se revirtió el traspaso #'.$t->id.' de la membresía «'.$t->plan_name.'»: '.
                        ($rol === 'holder' ? 'recupera su vigencia hasta el '.($despues['end'] ?? '—') : 'vuelve a como estaba antes'),
                    'changes' => $this->changes($antes, $despues),
                    'metadata' => $comun + ['user_id' => (int) $socio->id, 'role' => $rol, 'before' => $antes, 'after' => $despues],
                ]);
            }

            return ['transfer' => $t, 'already' => false];
        }, 3);

        if (! $resultado['already']) {
            $this->signal(array_values(array_filter([(int) $transfer->holder_user_id, (int) $transfer->beneficiary_user_id])));
        }

        return $resultado;
    }

    /**
     * Por qué NO se puede revertir. Sin esto, devolver los valores guardados
     * pisaría lo que pasó después: un cobro de B, una congelación, un ajuste.
     *
     * @return list<array{code: string, message: string}>
     */
    public function revertBlockers(MembershipTransfer $t, ?User $a, ?User $b): array
    {
        if (! $t->isLive()) {
            return [$this->blocker('already_reverted', 'Este traspaso ya se revirtió.')];
        }
        if ($a === null || $b === null) {
            return [$this->blocker('member_not_found', 'Uno de los dos socios ya no existe: no hay a quién devolverle nada.')];
        }

        $bloqueos = [];
        $desde = $t->created_at;
        $miembroB = Member::query()->where('user_id', $b->id)->first();

        $entroPorLaPuerta = Attendance::query()->where('user_id', $b->id)->where('captured_at', '>=', $desde)->exists();
        $fueAClase = $miembroB !== null
            && ClassAttendance::query()->where('member_id', $miembroB->id)->where('created_at', '>=', $desde)->exists();
        if ($entroPorLaPuerta || $fueAClase) {
            $bloqueos[] = $this->blocker('beneficiary_entered',
                $b->name.' ya usó la membresía (tiene asistencias desde el traspaso). No se revierte: corrígelo a mano.');
        }

        // Lo mismo que se le exige a la titular para ceder: devolverle a B su
        // estado anterior le quita la vigencia, y sus reservas no caducan con
        // ella (siguen ocupando cupo y el entrenador le puede marcar asistencia).
        if ($miembroB !== null) {
            $reservas = count($this->classes->upcomingFor((int) $miembroB->id, 100));
            if ($reservas > 0) {
                $bloqueos[] = $this->blocker('beneficiary_upcoming_reservations',
                    $b->name.' tiene '.$reservas.' '.($reservas === 1 ? 'reserva' : 'reservas').' de clase por delante. '.
                    'Cancélalas antes de revertir (Clases → inscritos): sin membresía seguirían ocupando cupo.');
            }
        }

        $ayer = CarbonImmutable::parse($t->day('effective_date'), MembershipPeriod::TZ)->subDay()->toDateString();
        $inicioA = $t->day('holder_start_before');
        $esperadoA = [
            'plan' => $t->holder_plan_before,
            'start' => ($inicioA === null || $inicioA > $ayer) ? $ayer : $inicioA,
            'end' => $ayer,
            'status' => $t->holder_status_before,
            'frozen' => false,
        ];
        $esperadoB = [
            'plan' => $t->plan_name,
            'start' => $t->day('effective_date'),
            'end' => $t->day('end_date'),
            'status' => 'active',
            'frozen' => false,
        ];
        if ($this->snapshot($a) !== $esperadoA) {
            $bloqueos[] = $this->blocker('holder_changed',
                'La membresía de '.$a->name.' cambió después del traspaso (plan, fechas, estado o congelamiento). Revísalo a mano.');
        }
        if ($this->snapshot($b) !== $esperadoB) {
            $bloqueos[] = $this->blocker('beneficiary_changed',
                'La membresía de '.$b->name.' cambió después del traspaso (plan, fechas, estado o congelamiento). Revísalo a mano.');
        }

        // Lo que se aplicó ya lo delata la comparación de arriba (cambia las
        // fechas); esto atrapa además lo que aún no se aplicó —un cobro
        // pendiente—. Estrictamente después: un cobro del mismo segundo que el
        // traspaso no puede ser posterior a él.
        $ids = [(int) $a->id, (int) $b->id];
        if (Payment::query()->whereIn('user_id', $ids)->where('created_at', '>', $desde)->exists()) {
            $bloqueos[] = $this->blocker('paid_after', 'Hay cobros registrados después del traspaso. Revísalo a mano.');
        }
        if (MembershipAdjustment::query()->whereIn('user_id', $ids)->where('created_at', '>', $desde)->exists()) {
            $bloqueos[] = $this->blocker('adjusted_after', 'Hay ajustes de días o entradas después del traspaso. Revísalo a mano.');
        }

        return $bloqueos;
    }

    // ────────────────────────────────────────────────────────────────────────
    //  Lectura
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Traspasos cedidos y recibidos por un socio, para su ficha. Lo último primero.
     *
     * @return list<array<string, mixed>>
     */
    public function forUser(User $user): array
    {
        $filas = MembershipTransfer::query()
            ->involving((int) $user->id)
            ->with(['holder:id,name', 'beneficiary:id,name'])
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return $filas->map(function (MembershipTransfer $t) use ($user): array {
            $fila = $this->toArray($t);
            $fila['role'] = (int) $t->holder_user_id === (int) $user->id ? 'holder' : 'beneficiary';
            if ($t->isLive()) {
                $a = $t->holder_user_id !== null ? User::find($t->holder_user_id) : null;
                $b = $t->beneficiary_user_id !== null ? User::find($t->beneficiary_user_id) : null;
                $fila['revert_blockers'] = $this->revertBlockers($t, $a, $b);
                $fila['revertible'] = $fila['revert_blockers'] === [];
            } else {
                $fila['revert_blockers'] = [];
                $fila['revertible'] = false;
            }

            return $fila;
        })->values()->all();
    }

    /**
     * El traspaso como lo lee el CRM.
     *
     * Un ENTRENADOR solo ve a los socios que tiene asignados (TrainerMemberScope:
     * `User::find` le devuelve null para los demás). Si la otra persona del
     * traspaso no es suya, se vacían sus datos, sus valores previos, el cobro
     * (si es de ella), el motivo y la evidencia: ni su nombre ni por qué cedió
     * o recibió una membresía son asunto de quien no la atiende.
     *
     * @return array<string, mixed>
     */
    public function toArray(MembershipTransfer $t): array
    {
        $visible = function (string $rel, ?int $id) use ($t): array {
            if ($id === null) {
                return [false, null];
            }
            $user = $t->relationLoaded($rel) ? $t->getRelation($rel) : User::find($id);

            return [$user === null && app(TrainerMemberScope::class)->applies(), $user];
        };
        [$titularOculta, $titular] = $visible('holder', $t->holder_user_id);
        [$beneficiarioOculto, $beneficiario] = $visible('beneficiary', $t->beneficiary_user_id);
        $oculto = $titularOculta || $beneficiarioOculto;
        $vacio = ['plan' => null, 'start' => null, 'end' => null, 'status' => null];

        return [
            'id' => (int) $t->id,
            'status' => $t->isLive() ? 'active' : 'reverted',
            'holder' => $titularOculta ? ['id' => null, 'name' => null] : ['id' => $t->holder_user_id, 'name' => $titular?->name],
            'beneficiary' => $beneficiarioOculto ? ['id' => null, 'name' => null] : ['id' => $t->beneficiary_user_id, 'name' => $beneficiario?->name],
            // El cobro es de la titular.
            'source_payment_id' => $titularOculta ? null : $t->source_payment_id,
            'plan' => ['id' => $t->plan_id, 'name' => $t->plan_name],
            'effective_date' => $t->day('effective_date'),
            'end_date' => $t->day('end_date'),
            'days' => (int) $t->days,
            'holder_before' => $titularOculta ? $vacio : [
                'plan' => $t->holder_plan_before,
                'start' => $t->day('holder_start_before'),
                'end' => $t->day('holder_end_before'),
                'status' => $t->holder_status_before,
            ],
            'beneficiary_before' => $beneficiarioOculto ? $vacio : [
                'plan' => $t->beneficiary_plan_before,
                'start' => $t->day('beneficiary_start_before'),
                'end' => $t->day('beneficiary_end_before'),
                'status' => $t->beneficiary_status_before,
            ],
            'reason' => $oculto ? null : $t->reason,
            'evidence' => $oculto ? null : $t->evidence,
            'holder_consent_at' => $t->holder_consent_at?->toIso8601String(),
            'beneficiary_acceptance_at' => $t->beneficiary_acceptance_at?->toIso8601String(),
            'created_by_name' => $t->created_by_name,
            'created_at' => $t->created_at?->toIso8601String(),
            'reverted_at' => $t->reverted_at?->toIso8601String(),
            'reverted_by_name' => $t->reverted_by_name,
            'revert_reason' => $oculto ? null : $t->revert_reason,
        ];
    }

    /** El traspaso VIVO del que A es titular, si lo hay. */
    public function liveTransferOf(User $holder): ?MembershipTransfer
    {
        return MembershipTransfer::query()->live()->where('holder_user_id', $holder->id)->latest('id')->first();
    }

    /** ¿Tiene este socio algún traspaso vivo, cedido o recibido? Lo preguntan el rebuild y future-dates. */
    public static function involvedInLive(int $userId): ?MembershipTransfer
    {
        return MembershipTransfer::query()->live()->involving($userId)->orderBy('id')->first();
    }

    /** El traspaso vivo que salió de este cobro, si lo hay. Lo preguntan anular y devolver. */
    public static function liveFromPayment(int $paymentId): ?MembershipTransfer
    {
        return MembershipTransfer::query()->live()->where('source_payment_id', $paymentId)->first();
    }

    // ────────────────────────────────────────────────────────────────────────
    //  La evaluación: bloqueos, avisos y efectos
    // ────────────────────────────────────────────────────────────────────────

    /**
     * TODO lo que decide si se puede, con lo que hay en la base en este
     * momento. La vista previa la llama sin bloquear; la ejecución, dentro de
     * la transacción y con `$lock`, que bloquea el cobro de origen y sus deudas
     * (los socios ya los bloqueó quien llama).
     *
     * @return array{blockers: list<array{code: string, message: string}>, warnings: list<array{code: string, severity: string, message: string}>, source: ?Payment, plan: ?Plan, today: CarbonImmutable, end: ?CarbonImmutable, days: ?int, holder: User, beneficiary: ?User, holder_member: ?Member, beneficiary_member: ?Member}
     */
    public function evaluate(User $a, ?User $b, bool $lock = false): array
    {
        $hoy = self::today();
        $bloqueos = [];
        $avisos = [];
        $miembroA = Member::query()->where('user_id', $a->id)->first();

        $inicio = $this->day($a->membershipStartDate);
        $fin = $this->day($a->membershipEndDate);
        $vigente = false;

        // ── A: su vigencia ─────────────────────────────────────────────────
        if ($a->membership_frozen_at !== null) {
            $bloqueos[] = $this->blocker('holder_frozen', 'Su membresía está congelada. Una membresía en pausa no se cede: reanúdala antes.');
        } elseif ($fin === null) {
            $bloqueos[] = $this->blocker('holder_no_membership', 'No tiene una membresía registrada: no hay nada que ceder.');
        } elseif ($inicio !== null && $inicio->greaterThan($hoy)) {
            $bloqueos[] = $this->blocker('holder_not_started', 'Su membresía empieza el '.$inicio->toDateString().': todavía no hay vigencia que ceder.');
        } elseif ($fin->lessThan($hoy)) {
            $bloqueos[] = $this->blocker('holder_expired', 'Su membresía venció el '.$fin->toDateString().': no queda nada que ceder.');
        } else {
            $vigente = true;
        }
        if (in_array(strtolower(trim((string) $a->status)), self::INACTIVE_ACCOUNT_STATUSES, true) && $a->membership_frozen_at === null) {
            $bloqueos[] = $this->blocker('holder_account_inactive', 'Su cuenta está «'.$a->status.'»: no puede entrar y su vigencia no se cede.');
        }
        // Su ficha de socio: una cuenta eliminada o suspendida por seguridad no
        // autoriza nada, tampoco ceder su membresía.
        if ($miembroA !== null && ($miembroA->status === Member::STATUS_DELETED || $miembroA->anonymized_at !== null)) {
            $bloqueos[] = $this->blocker('holder_deleted', 'Su cuenta está eliminada: no puede ceder su membresía.');
        } elseif ($miembroA !== null && $miembroA->isSuspended()) {
            $bloqueos[] = $this->blocker('holder_suspended', 'Su cuenta está suspendida: no puede ceder su membresía mientras siga así.');
        }
        if (MembershipTransfer::query()->live()->where('beneficiary_user_id', $a->id)->exists()) {
            $bloqueos[] = $this->blocker('holder_received_by_transfer', 'Su vigencia actual la recibió por un traspaso: no se vuelve a ceder.');
        }

        // ── A: el cobro que explica su vigencia ────────────────────────────
        [$origen, $plan, $bloqueosCobro] = $vigente ? $this->sourceOf($a, $hoy, $fin, $lock) : [null, null, []];
        array_push($bloqueos, ...$bloqueosCobro);

        // ── A: lo que dejaría algo colgando ────────────────────────────────
        if ($a->membership_cancellation_requested_at !== null) {
            $bloqueos[] = $this->blocker('holder_cancellation_requested', 'Pidió cancelar su membresía. Resuelve esa solicitud antes de ceder la vigencia.');
        }
        $hechos = $this->evaluator->blockingFacts(collect([$a]), null);
        if (isset($hechos['pending'][(int) $a->id])) {
            $bloqueos[] = $this->blocker('holder_pending_payment', 'Tiene un cobro de plan pendiente o un pago en curso. Resuélvelo antes: al confirmarse le volvería a dar días.');
        }
        if (isset($hechos['subscription'][(int) $a->id])) {
            $bloqueos[] = $this->blocker('holder_live_subscription', 'Tiene una suscripción automática activa. Cancélala antes: el próximo cobro le volvería a dar días.');
        }
        if ($miembroA !== null && $this->standing->isOverdue($miembroA)) {
            $bloqueos[] = $this->blocker('holder_overdue', 'Está en mora con el gimnasio. Primero tiene que ponerse al día.');
        }
        if ($miembroA !== null) {
            $reservas = count($this->classes->upcomingFor((int) $miembroA->id, 100));
            if ($reservas > 0) {
                // Las reservas no caducan con la membresía: siguen ocupando cupo
                // y el entrenador puede marcarle asistencia (ver §8).
                $bloqueos[] = $this->blocker('holder_upcoming_reservations',
                    'Tiene '.$reservas.' '.($reservas === 1 ? 'reserva' : 'reservas').' de clase por delante. Cancélalas antes '.
                    '(Clases → inscritos): sin membresía seguirían ocupando cupo.');
            }
        }
        if (EmployeeAccess::query()->where('user_id', $a->id)->where('active', true)->exists()) {
            $avisos[] = $this->warning('holder_employee_access', 'Tiene acceso de empleado: seguirá entrando por esa puerta aunque ceda su membresía.');
        }

        // ── B ─────────────────────────────────────────────────────────────
        $miembroB = null;
        if ($b !== null) {
            $miembroB = (int) $b->id === (int) $a->id ? $miembroA : Member::query()->where('user_id', $b->id)->first();
            [$bloqueosB, $avisosB] = $this->beneficiaryChecks($a, $b, $miembroA, $miembroB, $hoy);
            array_push($bloqueos, ...$bloqueosB);
            array_push($avisos, ...$avisosB);
        }

        $dias = $vigente && $fin !== null ? (int) $hoy->diffInDays($fin) + 1 : null;

        return [
            'blockers' => $bloqueos,
            'warnings' => $avisos,
            'source' => $origen,
            'plan' => $plan,
            'today' => $hoy,
            'end' => $vigente ? $fin : null,
            'days' => $dias,
            'holder' => $a,
            'beneficiary' => $b,
            'holder_member' => $miembroA,
            'beneficiary_member' => $miembroB,
        ];
    }

    /**
     * El ÚNICO cobro pagado que explica la vigencia de A: su periodo cubre hoy
     * y termina donde termina A. Cualquier otra cosa —ajuste manual, cortesía,
     * importación, renovación encadenada, mejora, cobro anulado— no tiene
     * política de traspaso y se bloquea.
     *
     * @return array{0: ?Payment, 1: ?Plan, 2: list<array{code: string, message: string}>}
     */
    private function sourceOf(User $a, CarbonImmutable $hoy, CarbonImmutable $fin, bool $lock): array
    {
        $candidatos = Payment::query()
            ->where('user_id', $a->id)
            ->whereNotNull('plan_id')
            ->whereNotNull('period_start')
            ->whereNotNull('period_end')
            ->whereRaw('LOWER(status) IN ('.implode(',', array_fill(0, count(PaymentController::PAID_STATUSES), '?')).')',
                PaymentController::PAID_STATUSES)
            ->whereDate('period_end', '>=', $hoy->toDateString())
            ->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get();

        if ($candidatos->isEmpty()) {
            return [null, null, [$this->blocker('no_source_payment',
                'Su vigencia no la explica un cobro pagado (ajuste manual, cortesía, importación o cobro anulado). No se puede ceder.')]];
        }
        // Una mejora de plan sustituye el periodo de su cobro origen: los dos
        // siguen «cubriendo» hoy y ninguno lo explica solo. Se dice qué es.
        if ($candidatos->contains(fn (Payment $p): bool => (bool) $p->upgraded_from_plan_id)) {
            return [null, null, [$this->blocker('source_is_upgrade', 'Su periodo actual viene de una mejora de plan: una mejora no se cede.')]];
        }
        if ($candidatos->count() > 1) {
            return [null, null, [$this->blocker('source_not_unique',
                'Su vigencia la forman varios cobros (una renovación encadenada o un periodo futuro ya pagado). Solo se cede el periodo de un único cobro.')]];
        }

        /** @var Payment $origen */
        $origen = $candidatos->first();
        $bloqueos = [];

        $desde = $this->day($origen->period_start->format('Y-m-d'));
        $hasta = $this->day($origen->period_end->format('Y-m-d'));
        if ($desde->greaterThan($hoy) || ! $hasta->equalTo($fin)) {
            return [null, null, [$this->blocker('source_mismatch',
                'El fin de su membresía ('.$fin->toDateString().') no coincide con el periodo de su cobro #'.$origen->id.
                ' ('.$desde->toDateString().' → '.$hasta->toDateString().'). Tiene ajustes u otros periodos: revísalo antes.')]];
        }
        if (str_starts_with(strtoupper((string) $origen->reference), 'MIGR-')) {
            $bloqueos[] = $this->blocker('source_migrated', 'Su cobro viene del sistema anterior (importado): no se cede.');
        }

        $plan = Plan::find($origen->plan_id);
        if ($plan === null) {
            $bloqueos[] = $this->blocker('source_plan_missing', 'El plan de su cobro ya no existe en el catálogo.');
        } else {
            if (MembershipAccess::planKey((string) ($a->plan ?? '')) !== MembershipAccess::planKey((string) $plan->name)) {
                $bloqueos[] = $this->blocker('plan_mismatch',
                    'Su plan («'.($a->plan ?? 'ninguno').'») no es el del cobro («'.$plan->name.'»). Revísalo antes de ceder.');
            }
            if (PlanAccessRules::fromPlan($plan)->isByEntries()) {
                $bloqueos[] = $this->blocker('entries_plan', '«'.$plan->name.'» es un plan por entradas: no se cede.');
            }
            if (mb_strlen((string) $plan->name) > PlanTransferEvaluator::MAX_PLAN_NAME) {
                $bloqueos[] = $this->blocker('plan_name_too_long', 'El nombre del plan no cabe en la ficha del beneficiario. Acórtalo antes.');
            }
        }

        if (self::liveFromPayment((int) $origen->id) !== null) {
            $bloqueos[] = $this->alreadyTransferred();
        }

        // Sus deudas: bloqueadas DESPUÉS del cobro. Una deuda que no esté
        // pagada —abierta, a medias o anulada con el cobro aún en pie— es
        // dinero sin cerrar sobre ese periodo.
        $deudas = Receivable::query()
            ->where('source_type', $origen->getMorphClass())
            ->where('source_id', $origen->id)
            ->orderBy('id')
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->get();
        if ($deudas->contains(fn (Receivable $d): bool => $d->status !== Receivable::STATUS_PAID)) {
            $bloqueos[] = $this->blocker('source_receivable_unpaid',
                'Su cobro es un plan a plazos y la cuenta por cobrar no está pagada. Se cede cuando esté saldada.');
        }

        return [$origen, $plan, $bloqueos];
    }

    /**
     * @return array{0: list<array{code: string, message: string}>, 1: list<array{code: string, severity: string, message: string}>}
     */
    private function beneficiaryChecks(User $a, User $b, ?Member $miembroA, ?Member $miembroB, CarbonImmutable $hoy): array
    {
        if ((int) $a->id === (int) $b->id) {
            return [[$this->blocker('same_person', 'La titular y el beneficiario son la misma persona.')], []];
        }
        if ($miembroA?->identity_id !== null && $miembroB?->identity_id !== null
            && (int) $miembroA->identity_id === (int) $miembroB->identity_id) {
            return [[$this->blocker('same_identity', 'Las dos fichas son de la misma persona (misma identidad).')], []];
        }

        $bloqueos = [];
        $avisos = [];

        // Su ficha de socio: sin ella no aparece en la lista de rostros
        // pendientes ni entra en la app, y los días cedidos no le servirían.
        if ($miembroB === null) {
            $bloqueos[] = $this->blocker('beneficiary_no_member_profile',
                $b->name.' no tiene ficha de socio en la app: no podría registrar su rostro ni entrar. Créale la ficha antes.');
        } elseif ($miembroB->status === Member::STATUS_DELETED || $miembroB->anonymized_at !== null) {
            $bloqueos[] = $this->blocker('beneficiary_deleted', 'La cuenta de '.$b->name.' está eliminada.');
        } elseif ($miembroB->isSuspended()) {
            $bloqueos[] = $this->blocker('beneficiary_suspended', 'La cuenta de '.$b->name.' está suspendida.');
        } elseif (! in_array($miembroB->status, self::BENEFICIARY_MEMBER_STATUSES, true)) {
            $bloqueos[] = $this->blocker('beneficiary_no_member_profile',
                'La ficha de socio de '.$b->name.' está «'.$miembroB->status.'»: no es una ficha válida para registrar su rostro.');
        }

        $inicio = $this->day($b->membershipStartDate);
        $fin = $this->day($b->membershipEndDate);
        if ($b->membership_frozen_at !== null) {
            $bloqueos[] = $this->blocker('beneficiary_frozen', $b->name.' tiene una membresía congelada.');
        } elseif ($fin !== null && $fin->greaterThanOrEqualTo($hoy)) {
            $bloqueos[] = ($inicio !== null && $inicio->greaterThan($hoy))
                ? $this->blocker('beneficiary_scheduled', $b->name.' tiene una membresía programada desde el '.$inicio->toDateString().'.')
                : $this->blocker('beneficiary_active', $b->name.' tiene una membresía vigente hasta el '.$fin->toDateString().'.');
        }

        if ($miembroB !== null && $bloqueos === []) {
            if (! $miembroB->biometric()->exists()) {
                $avisos[] = $this->warning('beneficiary_no_face',
                    $b->name.' no tiene rostro registrado: regístralo con el flujo habitual (rostros pendientes) para que pueda entrar.');
            }
            if ($this->standing->isOverdue($miembroB)) {
                $avisos[] = $this->warning('beneficiary_overdue',
                    $b->name.' está en mora: la app le retendrá los beneficios hasta que pague. La puerta no cambia.');
            }
        }
        if ($b->membership_cancellation_requested_at !== null) {
            $avisos[] = $this->warning('beneficiary_cancellation_requested',
                $b->name.' tiene una solicitud de cancelación anterior: la app mostrará la membresía como «cancelación solicitada».');
        }
        if (EmployeeAccess::query()->where('user_id', $b->id)->where('active', true)->exists()) {
            $avisos[] = $this->warning('beneficiary_employee_access', $b->name.' ya entra por acceso de empleado.');
        }

        return [$bloqueos, $avisos];
    }

    /**
     * La respuesta de la vista previa.
     *
     * @param  array<string, mixed>  $ev
     * @return array<string, mixed>
     */
    private function present(array $ev): array
    {
        /** @var User $a */
        $a = $ev['holder'];
        /** @var User|null $b */
        $b = $ev['beneficiary'];
        /** @var Payment|null $origen */
        $origen = $ev['source'];
        /** @var Plan|null $plan */
        $plan = $ev['plan'];
        $hoy = $ev['today'];
        $fin = $ev['end'];
        $ayer = $hoy->subDay()->toDateString();
        $inicioA = $a->membershipStartDate;

        return [
            'allowed' => $ev['blockers'] === [],
            'today' => $hoy->toDateString(),
            'blockers' => $ev['blockers'],
            'warnings' => $ev['warnings'],
            'holder' => [
                'id' => (int) $a->id,
                'name' => $a->name,
                'plan' => $a->plan,
                'start' => $inicioA,
                'end' => $a->membershipEndDate,
                'status' => $a->status,
                'frozen' => $a->membership_frozen_at !== null,
                'days' => $ev['days'],
                'has_member_profile' => $ev['holder_member'] !== null,
            ],
            'beneficiary' => $b === null ? null : [
                'id' => (int) $b->id,
                'name' => $b->name,
                'plan' => $b->plan,
                'start' => $b->membershipStartDate,
                'end' => $b->membershipEndDate,
                'status' => $b->status,
                'frozen' => $b->membership_frozen_at !== null,
                'has_member_profile' => $ev['beneficiary_member'] !== null,
                'member_status' => $ev['beneficiary_member']?->status,
                'has_face' => $ev['beneficiary_member'] !== null && $ev['beneficiary_member']->biometric()->exists(),
            ],
            'effects' => [
                'effective_date' => $hoy->toDateString(),
                'end_date' => $fin?->toDateString(),
                'days' => $ev['days'],
                'source_payment' => $origen === null ? null : [
                    'id' => (int) $origen->id,
                    'plan_id' => (int) $origen->plan_id,
                    'plan_name' => $plan?->name,
                    'period_start' => $origen->period_start?->format('Y-m-d'),
                    'period_end' => $origen->period_end?->format('Y-m-d'),
                    'paid_at' => $origen->paid_at?->toIso8601String(),
                ],
                'holder' => $fin === null ? null : [
                    'end_before' => $a->membershipEndDate,
                    'end_after' => $ayer,
                    'start_after' => ($inicioA === null || $inicioA > $ayer) ? $ayer : $inicioA,
                    'plan_after' => $a->plan,
                    'status_after' => $a->status,
                ],
                'beneficiary' => ($b === null || $fin === null || $plan === null) ? null : [
                    'plan_after' => $plan->name,
                    'start_after' => $hoy->toDateString(),
                    'end_after' => $fin->toDateString(),
                    'status_after' => 'active',
                ],
                // Nada de esto se crea: lo dice para que el CRM lo enseñe.
                'creates_payment' => false,
                'creates_invoice' => false,
            ],
        ];
    }

    // ────────────────────────────────────────────────────────────────────────

    /**
     * El traspaso que ya atendió esta llave, o null. Con otros socios, la
     * llave está gastada en otra cosa: conflicto.
     *
     * @throws MembershipTransferException
     */
    private function replay(string $llave, int $holderId, int $beneficiaryId): ?MembershipTransfer
    {
        $previo = MembershipTransfer::query()->where('client_request_id', $llave)->first();
        if ($previo === null) {
            return null;
        }
        if ((int) $previo->holder_user_id !== $holderId || (int) $previo->beneficiary_user_id !== $beneficiaryId) {
            throw MembershipTransferException::requestIdConflict();
        }

        return $previo;
    }

    /** @return array{plan: ?string, start: ?string, end: ?string, status: ?string, frozen: bool} */
    private function snapshot(User $user): array
    {
        return [
            'plan' => $user->plan,
            'start' => $user->membershipStartDate,
            'end' => $user->membershipEndDate,
            'status' => $user->status,
            'frozen' => $user->membership_frozen_at !== null,
        ];
    }

    /**
     * @param  array<string, mixed>  $antes
     * @param  array<string, mixed>  $despues
     * @return list<array{field: string, from: mixed, to: mixed}>
     */
    private function changes(array $antes, array $despues): array
    {
        $campos = ['plan' => 'plan', 'start' => 'membership_start_date', 'end' => 'membership_end_date', 'status' => 'status'];
        $cambios = [];
        foreach ($campos as $clave => $campo) {
            if (($antes[$clave] ?? null) !== ($despues[$clave] ?? null)) {
                $cambios[] = ['field' => $campo, 'from' => $antes[$clave] ?? null, 'to' => $despues[$clave] ?? null];
            }
        }

        return $cambios;
    }

    /**
     * La señal silenciosa a la app de los dos, con todo confirmado: «tu
     * membresía y tus funciones cambiaron, vuelve a preguntar». Las mismas dos
     * que el traslado de planes.
     *
     * @param  list<int>  $userIds
     */
    private function signal(array $userIds): void
    {
        foreach (Member::query()->whereIn('user_id', $userIds)->pluck('id') as $memberId) {
            RealtimeEvents::membership((int) $memberId);
            RealtimeEvents::features((int) $memberId);
        }
    }

    /** @return array{code: string, message: string} */
    private function alreadyTransferred(): array
    {
        return $this->blocker('source_already_transferred', 'El periodo de este cobro ya se cedió y no se ha revertido.');
    }

    /** @return array{code: string, message: string} */
    private function blocker(string $code, string $message): array
    {
        return ['code' => $code, 'message' => $message];
    }

    /** @return array{code: string, severity: string, message: string} */
    private function warning(string $code, string $message): array
    {
        return [
            'code' => $code,
            'severity' => in_array($code, self::INFO_WARNINGS, true) ? 'info' : 'warning',
            'message' => $message,
        ];
    }

    private function day(?string $fecha): ?CarbonImmutable
    {
        return $fecha ? CarbonImmutable::parse(substr($fecha, 0, 10), MembershipPeriod::TZ)->startOfDay() : null;
    }
}

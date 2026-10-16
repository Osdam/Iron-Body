<?php

namespace App\Services\Membership;

use App\Exceptions\PlanTransferException;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlanTransferBatch;
use App\Models\PlanTransferItem;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use App\Services\Payments\MembershipPeriod;
use App\Services\RealtimeEvents;
use App\Support\Access\AdminActor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * TRASLADAR A UN GRUPO DE SOCIOS DE UN PLAN A OTRO, y deshacerlo.
 *
 * QUÉ ES TRASLADAR, Y NADA MÁS. Por socio se escribe UNA columna:
 * `users.plan` pasa al nombre EXACTO del plan destino (en PostgreSQL la
 * comparación por nombre distingue tildes, y el destino real es «Élite»). No
 * se cobra, no se devuelve, no se factura, no se crea una cuenta por cobrar ni
 * un contrato, y no se toca ni una fecha: ni el inicio, ni el vencimiento, ni
 * el congelamiento, ni el estado. Los pagos siguen siendo del plan con que se
 * pagaron, que es la verdad histórica.
 *
 * SIMULAR ANTES, SIEMPRE. La ejecución recibe la HUELLA de la simulación que
 * vio el operador y vuelve a simular bajo bloqueo; si no coincide, no aplica
 * nada y devuelve la simulación nueva. Lo que se aplica es exactamente lo que
 * se vio, o nada.
 *
 * TODO O NADA. Los socios que no se pueden trasladar quedan fuera y anotados
 * en el lote; los que sí, se trasladan todos en una transacción o ninguno.
 *
 * EL ORDEN DE LOS BLOQUEOS es el del resto del sistema —primero el socio,
 * después los planes— (PaymentController::update, PlanUpgrades::upgrade):
 * dos operaciones que bloquean en el mismo orden no pueden esperarse en
 * círculo.
 *
 * LA REVERSIÓN ES OTRO LOTE. Devuelve a cada socio el texto que tenía
 * (variante de escritura incluida) mientras nadie haya cambiado nada después;
 * si alguien lo cambió, ese socio se queda fuera y se dice por qué.
 *
 * Al socio no se le notifica nada. Solo se le manda a la app la señal
 * silenciosa de «tu membresía cambió, vuelve a preguntar», DESPUÉS del commit:
 * mandarla dentro avisaría de un cambio que todavía puede deshacerse.
 */
class PlanTransfers
{
    /** Lo que deshacer NO devuelve, dicho antes de pedir confirmación. */
    public const IRREVERSIBLE = [
        'Las entradas que consumió y los marcajes que registró con el plan destino siguen contando.',
        'Las reservas de clase que hizo con el plan destino no se cancelan.',
        'Lo que usó de IRON IA con el plan destino ya está usado.',
        'Las señales que recibió la app y lo que ya se exportó o salió en informes no se retiran.',
        'Los cambios que se hayan hecho después en el catálogo de planes no se deshacen.',
        'Los socios bloqueados se quedan como están: revísalos a mano.',
        'El historial del traslado y de la reversión permanece.',
    ];

    public function __construct(
        private readonly PlanTransferEvaluator $evaluator,
        private readonly PlanConditions $conditions,
        private readonly AuditTrail $audit,
    ) {}

    // ────────────────────────────────────────────────────────────────────────
    //  Trasladar
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Ejecuta un traslado ya simulado.
     *
     * @param  list<int>  $userIds
     * @return array{batch: PlanTransferBatch, replayed: bool}
     *
     * @throws PlanTransferException
     */
    public function execute(
        Request $request,
        int $fromId,
        int $toId,
        array $userIds,
        string $fingerprint,
        string $reason,
        string $clientRequestId,
        bool $confirmWarnings,
    ): array {
        $ids = array_values(array_unique(array_map('intval', $userIds)));
        sort($ids);

        // EL REINTENTO VA PRIMERO: el doble clic encuentra el lote del primero.
        if ($previo = $this->replay($clientRequestId, $fingerprint, PlanTransferBatch::KIND_TRANSFER, null, $fromId, $toId)) {
            return ['batch' => $previo, 'replayed' => true];
        }

        try {
            $resultado = DB::transaction(function () use ($request, $fromId, $toId, $ids, $fingerprint, $reason, $clientRequestId, $confirmWarnings): array {
                // (a) LOS SOCIOS PRIMERO, en orden de id.
                $users = User::query()->whereKey($ids)->orderBy('id')->lockForUpdate()->get();
                // (b) Después los planes, también en orden: nadie puede
                // desactivar el destino ni renombrar el origen a mitad.
                $planes = Plan::query()->whereKey([$fromId, $toId])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $from = $planes->get($fromId);
                $to = $planes->get($toId);
                if ($from === null || $to === null) {
                    throw new PlanTransferException('Uno de los dos planes ya no existe.', 'plan_not_found');
                }

                // (c) Otra petición con la misma llave pudo confirmarse mientras
                // esta esperaba los bloqueos.
                if ($previo = $this->replay($clientRequestId, $fingerprint, PlanTransferBatch::KIND_TRANSFER, null, $fromId, $toId)) {
                    return ['batch' => $previo, 'replayed' => true, 'signal' => []];
                }

                // (d) La misma simulación, ahora sobre los socios bloqueados.
                $simulacion = $this->evaluator->simulate($from, $to, $ids, $users);

                // (e) Si cambió algo, no se aplica nada: se enseña lo nuevo.
                if (! hash_equals($simulacion['fingerprint'], $fingerprint)) {
                    throw PlanTransferException::stale($simulacion);
                }

                // (f)
                $elegibles = array_values(array_filter($simulacion['items'], fn (array $i): bool => $i['eligible']));
                if ($elegibles === []) {
                    throw PlanTransferException::nothingToTransfer();
                }
                if ($simulacion['requires_confirmation'] && ! $confirmWarnings) {
                    throw PlanTransferException::warningsNotConfirmed();
                }

                // (g)
                return $this->applyTransfer($request, $from, $to, $users->keyBy('id'), $simulacion, $elegibles, $fingerprint, $reason, $clientRequestId);
            });
        } catch (UniqueConstraintViolationException $e) {
            // Dos peticiones con la misma llave y socios distintos llegaron a
            // insertar el lote a la vez, y el índice único paró a la segunda.
            // Se resuelve AQUÍ, fuera de la transacción: en PostgreSQL un
            // error deja la transacción abortada y cualquier lectura dentro
            // fallaría con «current transaction is aborted».
            $previo = $this->replay($clientRequestId, $fingerprint, PlanTransferBatch::KIND_TRANSFER, null, $fromId, $toId);
            if ($previo === null) {
                throw $e;
            }

            return ['batch' => $previo, 'replayed' => true];
        }

        $this->signal($resultado['signal']);

        return ['batch' => $resultado['batch'], 'replayed' => $resultado['replayed']];
    }

    /**
     * @param  Collection<int, User>  $users  bloqueados, por id
     * @param  array<string, mixed>  $simulacion
     * @param  list<array<string, mixed>>  $elegibles
     * @return array{batch: PlanTransferBatch, replayed: false, signal: list<int>}
     */
    private function applyTransfer(
        Request $request,
        Plan $from,
        Plan $to,
        Collection $users,
        array $simulacion,
        array $elegibles,
        string $fingerprint,
        string $reason,
        string $clientRequestId,
    ): array {
        $lote = PlanTransferBatch::create([
            'kind' => PlanTransferBatch::KIND_TRANSFER,
            'status' => PlanTransferBatch::STATUS_APPLIED,
            'from_plan_id' => $from->id,
            'to_plan_id' => $to->id,
            'from_plan_name' => $from->name,
            'to_plan_name' => $to->name,
            'from_conditions' => $simulacion['from_plan'],
            'to_conditions' => $simulacion['to_plan'],
            'reason' => $reason,
            'fingerprint' => $fingerprint,
            'client_request_id' => $clientRequestId,
            'members_count' => count($elegibles),
            'excluded' => $this->excluded($simulacion['items'], 'user_id'),
            'business_day' => MembershipPeriod::today()->toDateString(),
            'executed_at' => now(),
            ...$this->author($request),
        ]);

        $this->audit->record($request, [
            'action' => 'create',
            'module' => 'Planes',
            'entity' => 'traslado de plan',
            'entity_id' => $lote->id,
            'target_name' => $from->name.' → '.$to->name,
            'summary' => 'Trasladó '.count($elegibles).' '.(count($elegibles) === 1 ? 'socio' : 'socios').
                ' de «'.$from->name.'» a «'.$to->name.'»',
            'metadata' => [
                'event' => 'plan.transfer.executed',
                'batch_id' => $lote->id,
                'from_plan_id' => $from->id,
                'to_plan_id' => $to->id,
                'members_count' => count($elegibles),
                'excluded_count' => count($lote->excluded ?? []),
                'reason' => $reason,
            ],
        ]);

        $trasladados = [];
        foreach ($elegibles as $item) {
            /** @var User $user */
            $user = $users->get($item['user_id']);
            $previo = $user->plan;

            // UNA columna, con Eloquent sobre el modelo bloqueado: guarda solo
            // lo que cambió (`plan` y `updated_at`).
            $user->plan = $to->name;
            $user->save();

            $fila = PlanTransferItem::create([
                'batch_id' => $lote->id,
                'user_id' => $user->id,
                'user_name' => $user->name,
                'before' => $item['before'],
                'after' => $item['after'],
                'warnings' => $item['warnings'],
                'status' => PlanTransferItem::STATUS_APPLIED,
            ]);

            $this->audit->record($request, [
                'action' => 'update',
                'module' => 'Miembros',
                'entity' => 'membresía',
                'entity_id' => $user->id,
                'target_name' => $user->name,
                'summary' => 'Cambió de plan sin cobro: de «'.$previo.'» a «'.$to->name.'» (traslado #'.$lote->id.')',
                'changes' => [['field' => 'plan', 'from' => $previo, 'to' => $to->name]],
                'metadata' => [
                    'event' => 'membership.plan.transferred',
                    'user_id' => $user->id,
                    'plan_id' => $to->id,
                    'batch_id' => $lote->id,
                    'item_id' => $fila->id,
                    'changed' => ['membership', 'features', 'plans'],
                ],
            ]);

            $trasladados[] = (int) $user->id;
        }

        return ['batch' => $lote, 'replayed' => false, 'signal' => $trasladados];
    }

    // ────────────────────────────────────────────────────────────────────────
    //  Revertir
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Qué pasaría al deshacer el traslado para estos socios (o para todos).
     * No escribe nada.
     *
     * @param  list<int>|null  $userIds
     * @param  Collection<int, User>|null  $lockedUsers
     * @param  Collection<int, PlanTransferItem>|null  $lockedItems
     * @param  Collection<int, Plan>|null  $lockedPlans
     * @return array<string, mixed>
     *
     * @throws PlanTransferException
     */
    public function simulateRevert(
        PlanTransferBatch $batch,
        ?array $userIds,
        ?Collection $lockedUsers = null,
        ?Collection $lockedItems = null,
        ?Collection $lockedPlans = null,
    ): array {
        if (! $batch->isTransfer()) {
            throw PlanTransferException::notRevertible();
        }

        $ahora = $this->evaluator->now();
        $hoy = $ahora->startOfDay();
        $items = $this->selectItems($lockedItems ?? $batch->items()->orderBy('id')->get(), $userIds);
        $users = ($lockedUsers ?? User::query()->whereKey($items->pluck('user_id')->filter()->all())->orderBy('id')->get())
            ->keyBy('id');
        $planes = $lockedPlans ?? Plan::query()->whereKey(array_filter([$batch->from_plan_id, $batch->to_plan_id]))->get()->keyBy('id');
        $origen = $batch->from_plan_id !== null ? $planes->get($batch->from_plan_id) : null;
        $destino = $batch->to_plan_id !== null ? $planes->get($batch->to_plan_id) : null;

        // Los que se pueden mirar: el ítem sigue aplicado y el socio existe.
        // Se proyecta por texto original —cada uno vuelve a SU escritura—.
        $proyecciones = [];
        $vivos = $items->filter(fn (PlanTransferItem $i): bool => $i->isApplied() && $users->has($i->user_id));
        foreach ($vivos->groupBy(fn (PlanTransferItem $i): string => (string) ($i->before['plan'] ?? '')) as $texto => $grupo) {
            $proyecciones += $this->evaluator->project(
                $grupo->map(fn (PlanTransferItem $i): User => $users->get($i->user_id))->values(),
                (string) $texto,
                $origen?->name ?? $batch->from_plan_name,
                $batch->to_plan_id,
                $batch->to_plan_name,
                null,
                $ahora,
            );
        }

        $marcas = Payment::query()
            ->whereIn('user_id', $vivos->pluck('user_id')->all())
            ->whereNotNull('plan_id')
            ->groupBy('user_id')
            ->selectRaw('user_id, MAX(id) as last_id')
            ->pluck('last_id', 'user_id');

        $filas = [];
        foreach ($items as $item) {
            $user = $item->user_id !== null ? $users->get($item->user_id) : null;
            $bloqueos = $this->revertBlockers($item, $user, $origen, $batch, $marcas);
            $p = $user !== null ? ($proyecciones[(int) $user->id] ?? null) : null;

            $avisos = [];
            if ($p !== null && $bloqueos === []) {
                $avisos = $p['warnings'];
                $entradas = $p['after_entries'];
                // Al volver a un plan por consumo puede no quedarle ninguna. En
                // un traslado eso lo bloquea; aquí se avisa, porque deshacer un
                // error no puede depender de que el socio no haya entrenado.
                if ($entradas !== null && $entradas['left'] <= 0 && ! $entradas['used_today']) {
                    $avisos[] = [
                        'code' => PlanTransferEvaluator::BLOCK_ENTRIES_EXHAUSTED,
                        'severity' => 'warning',
                        'message' => 'Al volver a «'.($origen?->name ?? $batch->from_plan_name).'» no le quedarán entradas en este periodo (usó '.
                            $entradas['used'].' de '.$entradas['total'].').',
                    ];
                }
            }

            $filas[] = [
                'item_id' => (int) $item->id,
                'user_id' => $item->user_id !== null ? (int) $item->user_id : null,
                'name' => $user?->name ?? $item->user_name,
                'revertible' => $bloqueos === [],
                'restore_to' => ['plan' => $item->before['plan'] ?? null, 'plan_id' => $origen?->id],
                'current' => $this->current($user, $item),
                'before' => $p['before'] ?? null,
                'after' => $p['after'] ?? null,
                'blockers' => $bloqueos,
                'warnings' => $avisos,
            ];
        }

        $revertibles = array_values(array_filter($filas, fn (array $f): bool => $f['revertible']));
        $conAvisos = array_values(array_filter($revertibles, fn (array $f): bool => PlanTransferEvaluator::hasWarnings($f['warnings'])));

        return [
            'fingerprint' => PlanTransferEvaluator::fingerprint([
                'batch_id' => (int) $batch->id,
                'today' => $hoy->toDateString(),
                'from' => $destino ? $this->conditions->of($destino) : null,
                'to' => $origen ? $this->conditions->of($origen) : null,
                'items' => $this->evaluator->fingerprintItems($filas, 'item_id'),
                ...$this->evaluator->watermarks($users->keys()->map(fn ($id): int => (int) $id)->all()),
            ]),
            'batch_id' => (int) $batch->id,
            'today' => $hoy->toDateString(),
            'summary' => [
                'requested' => count($filas),
                'revertible' => count($revertibles),
                'blocked' => count($filas) - count($revertibles),
                'with_warnings' => count($conAvisos),
            ],
            'requires_confirmation' => $conAvisos !== [],
            'irreversible' => self::IRREVERSIBLE,
            'items' => $filas,
        ];
    }

    /**
     * Deshace el traslado para los socios que se pueda, en un lote nuevo.
     *
     * @param  list<int>|null  $userIds
     * @return array{batch: PlanTransferBatch, replayed: bool}
     *
     * @throws PlanTransferException
     */
    public function revert(
        Request $request,
        PlanTransferBatch $batch,
        ?array $userIds,
        string $fingerprint,
        string $reason,
        string $clientRequestId,
        bool $confirmWarnings,
    ): array {
        if (! $batch->isTransfer()) {
            throw PlanTransferException::notRevertible();
        }
        if ($previo = $this->replay($clientRequestId, $fingerprint, PlanTransferBatch::KIND_REVERSAL, (int) $batch->id, $batch->to_plan_id, $batch->from_plan_id)) {
            return ['batch' => $previo, 'replayed' => true];
        }

        // Qué socios hay que bloquear: se lee sin bloqueo para saberlo, y la
        // simulación de dentro vuelve a leer los ítems ya bloqueados.
        $idsSocio = $this->selectItems($batch->items()->orderBy('id')->get(), $userIds)
            ->pluck('user_id')->filter()->map(fn ($id): int => (int) $id)->sort()->values()->all();

        try {
            $resultado = DB::transaction(function () use ($request, $batch, $userIds, $idsSocio, $fingerprint, $reason, $clientRequestId, $confirmWarnings): array {
                // Socios, planes, lote original, ítems. Siempre en este orden.
                $users = User::query()->whereKey($idsSocio)->orderBy('id')->lockForUpdate()->get();
                $planes = Plan::query()->whereKey(array_filter([$batch->from_plan_id, $batch->to_plan_id]))
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $lote = PlanTransferBatch::query()->whereKey($batch->id)->lockForUpdate()->firstOrFail();
                $items = PlanTransferItem::query()->where('batch_id', $lote->id)->orderBy('id')->lockForUpdate()->get();

                if ($previo = $this->replay($clientRequestId, $fingerprint, PlanTransferBatch::KIND_REVERSAL, (int) $lote->id, $lote->to_plan_id, $lote->from_plan_id)) {
                    return ['batch' => $previo, 'replayed' => true, 'signal' => []];
                }

                $simulacion = $this->simulateRevert($lote, $userIds, $users, $items, $planes);
                if (! hash_equals($simulacion['fingerprint'], $fingerprint)) {
                    throw PlanTransferException::stale($simulacion);
                }

                $revertibles = array_values(array_filter($simulacion['items'], fn (array $f): bool => $f['revertible']));
                if ($revertibles === []) {
                    throw PlanTransferException::nothingToTransfer('No queda ningún socio de este lote que se pueda revertir.');
                }
                if ($simulacion['requires_confirmation'] && ! $confirmWarnings) {
                    throw PlanTransferException::warningsNotConfirmed();
                }

                return $this->applyReversal($request, $lote, $users->keyBy('id'), $items->keyBy('id'), $planes, $simulacion, $revertibles, $fingerprint, $reason, $clientRequestId);
            });
        } catch (UniqueConstraintViolationException $e) {
            // Mismo motivo que en execute(): fuera de la transacción abortada.
            $previo = $this->replay($clientRequestId, $fingerprint, PlanTransferBatch::KIND_REVERSAL, (int) $batch->id, $batch->to_plan_id, $batch->from_plan_id);
            if ($previo === null) {
                throw $e;
            }

            return ['batch' => $previo, 'replayed' => true];
        }

        $this->signal($resultado['signal']);

        return ['batch' => $resultado['batch'], 'replayed' => $resultado['replayed']];
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  Collection<int, PlanTransferItem>  $items
     * @param  Collection<int, Plan>  $planes
     * @param  array<string, mixed>  $simulacion
     * @param  list<array<string, mixed>>  $revertibles
     * @return array{batch: PlanTransferBatch, replayed: false, signal: list<int>}
     */
    private function applyReversal(
        Request $request,
        PlanTransferBatch $lote,
        Collection $users,
        Collection $items,
        Collection $planes,
        array $simulacion,
        array $revertibles,
        string $fingerprint,
        string $reason,
        string $clientRequestId,
    ): array {
        /** @var Plan $origen */
        $origen = $planes->get($lote->from_plan_id);
        $destino = $lote->to_plan_id !== null ? $planes->get($lote->to_plan_id) : null;
        $ahora = now();

        $reversion = PlanTransferBatch::create([
            'kind' => PlanTransferBatch::KIND_REVERSAL,
            'reverts_batch_id' => $lote->id,
            'status' => PlanTransferBatch::STATUS_APPLIED,
            // Al revés: se sale del destino y se vuelve al origen.
            'from_plan_id' => $lote->to_plan_id,
            'to_plan_id' => $lote->from_plan_id,
            'from_plan_name' => $destino?->name ?? $lote->to_plan_name,
            'to_plan_name' => $origen->name,
            'from_conditions' => $destino ? $this->conditions->of($destino) : $lote->to_conditions,
            'to_conditions' => $this->conditions->of($origen),
            'reason' => $reason,
            'fingerprint' => $fingerprint,
            'client_request_id' => $clientRequestId,
            'members_count' => count($revertibles),
            'excluded' => $this->excluded($simulacion['items'], 'user_id', 'revertible'),
            'business_day' => MembershipPeriod::today()->toDateString(),
            'executed_at' => $ahora,
            ...$this->author($request),
        ]);

        $this->audit->record($request, [
            'action' => 'update',
            'module' => 'Planes',
            'entity' => 'traslado de plan',
            'entity_id' => $reversion->id,
            'target_name' => $reversion->from_plan_name.' → '.$reversion->to_plan_name,
            'summary' => 'Revirtió el traslado #'.$lote->id.' para '.count($revertibles).' '.
                (count($revertibles) === 1 ? 'socio' : 'socios'),
            'metadata' => [
                'event' => 'plan.transfer.reverted',
                'batch_id' => $reversion->id,
                'reverts_batch_id' => $lote->id,
                'from_plan_id' => $reversion->from_plan_id,
                'to_plan_id' => $reversion->to_plan_id,
                'members_count' => count($revertibles),
                'excluded_count' => count($reversion->excluded ?? []),
                'reason' => $reason,
            ],
        ]);

        $revertidos = [];
        foreach ($revertibles as $fila) {
            /** @var PlanTransferItem $original */
            $original = $items->get($fila['item_id']);
            /** @var User $user */
            $user = $users->get($fila['user_id']);
            $previo = $user->plan;
            $texto = (string) $original->before['plan'];

            // El texto ORIGINAL, tal como estaba escrito.
            $user->plan = $texto;
            $user->save();

            $nueva = PlanTransferItem::create([
                'batch_id' => $reversion->id,
                'user_id' => $user->id,
                'user_name' => $user->name,
                'before' => $fila['before'],
                'after' => $fila['after'],
                'warnings' => $fila['warnings'],
                'status' => PlanTransferItem::STATUS_APPLIED,
                'reverts_item_id' => $original->id,
            ]);
            $original->forceFill([
                'status' => PlanTransferItem::STATUS_REVERTED,
                'reverted_at' => $ahora,
            ])->save();

            $this->audit->record($request, [
                'action' => 'update',
                'module' => 'Miembros',
                'entity' => 'membresía',
                'entity_id' => $user->id,
                'target_name' => $user->name,
                'summary' => 'Se revirtió su cambio de plan: de «'.$previo.'» vuelve a «'.$texto.'» (traslado #'.$lote->id.')',
                'changes' => [['field' => 'plan', 'from' => $previo, 'to' => $texto]],
                'metadata' => [
                    'event' => 'membership.plan.transfer.reverted',
                    'user_id' => $user->id,
                    'plan_id' => $origen->id,
                    'batch_id' => $reversion->id,
                    'item_id' => $nueva->id,
                    'reverts_batch_id' => $lote->id,
                    'changed' => ['membership', 'features', 'plans'],
                ],
            ]);

            $revertidos[] = (int) $user->id;
        }

        $quedan = $items->contains(fn (PlanTransferItem $i): bool => $i->isApplied());
        $lote->forceFill([
            'status' => $quedan ? PlanTransferBatch::STATUS_PARTIALLY_REVERTED : PlanTransferBatch::STATUS_REVERTED,
        ])->save();

        return ['batch' => $reversion, 'replayed' => false, 'signal' => $revertidos];
    }

    // ────────────────────────────────────────────────────────────────────────
    //  Lectura
    // ────────────────────────────────────────────────────────────────────────

    /**
     * El lote como lo enseña el CRM, con cómo está cada socio HOY.
     *
     * @return array<string, mixed>
     */
    public function detail(PlanTransferBatch $batch): array
    {
        $batch->load([
            'items' => fn ($q) => $q->orderBy('id'),
            'items.reversal:id,batch_id,reverts_item_id',
            'reversals' => fn ($q) => $q->orderBy('id'),
        ]);
        $users = User::query()->whereKey($batch->items->pluck('user_id')->filter()->all())->get()->keyBy('id');

        return [
            'id' => (int) $batch->id,
            'kind' => $batch->kind,
            'status' => $batch->status,
            'from_plan' => ['id' => $batch->from_plan_id, 'name' => $batch->from_plan_name],
            'to_plan' => ['id' => $batch->to_plan_id, 'name' => $batch->to_plan_name],
            'reason' => $batch->reason,
            'created_by_name' => $batch->created_by_name,
            'created_by_role' => $batch->created_by_role,
            'executed_at' => $batch->executed_at?->toIso8601String(),
            'business_day' => $batch->business_day?->format('Y-m-d'),
            'members_count' => (int) $batch->members_count,
            'excluded' => $batch->excluded ?? [],
            'fingerprint' => $batch->fingerprint,
            'reverts_batch_id' => $batch->reverts_batch_id,
            'reversals' => $batch->reversals->map(fn (PlanTransferBatch $r): array => [
                'id' => (int) $r->id,
                'executed_at' => $r->executed_at?->toIso8601String(),
                'created_by_name' => $r->created_by_name,
                'members_count' => (int) $r->members_count,
            ])->values()->all(),
            'from_conditions' => $batch->from_conditions,
            'to_conditions' => $batch->to_conditions,
            'items' => $batch->items->map(fn (PlanTransferItem $i): array => [
                'id' => (int) $i->id,
                'user_id' => $i->user_id,
                'user_name' => $i->user_name,
                'status' => $i->status,
                'before' => $i->before,
                'after' => $i->after,
                'warnings' => $i->warnings ?? [],
                'current' => $this->current($i->user_id !== null ? $users->get($i->user_id) : null, $i),
                'reverted_in_batch_id' => $i->reversal?->batch_id,
            ])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function row(PlanTransferBatch $batch): array
    {
        return [
            'id' => (int) $batch->id,
            'kind' => $batch->kind,
            'status' => $batch->status,
            'from_plan' => ['id' => $batch->from_plan_id, 'name' => $batch->from_plan_name],
            'to_plan' => ['id' => $batch->to_plan_id, 'name' => $batch->to_plan_name],
            'members_count' => (int) $batch->members_count,
            'reverted_count' => (int) ($batch->reverted_count ?? 0),
            'created_by_name' => $batch->created_by_name,
            'executed_at' => $batch->executed_at?->toIso8601String(),
            'reason' => $batch->reason,
        ];
    }

    // ────────────────────────────────────────────────────────────────────────

    /**
     * El lote que ya atendió esta llave, o null. Con otra huella, otro tipo de
     * operación u otros planes, la llave está gastada en otra cosa: conflicto.
     *
     * Los planes se comparan aunque la huella coincida: el replay se decide
     * ANTES de simular, con la huella que manda el cliente, y sin esto la
     * misma llave y la misma huella con otro `to_plan_id` respondían «ya
     * estaba hecho» enseñando un traslado que no es el que se pidió.
     *
     * @throws PlanTransferException
     */
    private function replay(
        string $clientRequestId,
        string $fingerprint,
        string $kind,
        ?int $revertsBatchId,
        ?int $fromPlanId,
        ?int $toPlanId,
    ): ?PlanTransferBatch {
        $lote = PlanTransferBatch::query()->where('client_request_id', $clientRequestId)->first();
        if ($lote === null) {
            return null;
        }

        if (! hash_equals($lote->fingerprint, $fingerprint)
            || $lote->kind !== $kind
            || $lote->from_plan_id !== $fromPlanId
            || $lote->to_plan_id !== $toPlanId
            || ($revertsBatchId !== null && (int) $lote->reverts_batch_id !== $revertsBatchId)) {
            throw PlanTransferException::idempotencyConflict();
        }

        return $lote;
    }

    /**
     * Por qué un socio NO se puede devolver a su plan anterior.
     *
     * @param  Collection<int|string, mixed>  $marcas  último cobro con plan, por socio
     * @return list<array{code: string, message: string}>
     */
    private function revertBlockers(PlanTransferItem $item, ?User $user, ?Plan $origen, PlanTransferBatch $batch, Collection $marcas): array
    {
        if (! $item->isApplied()) {
            return [$this->evaluator->blocker('item_not_applied', 'Ya se revirtió.')];
        }
        if ($user === null) {
            return [$this->evaluator->blocker(PlanTransferEvaluator::BLOCK_NOT_FOUND, 'El socio ya no existe.')];
        }
        if ($origen === null) {
            return [$this->evaluator->blocker('origin_plan_missing',
                'El plan «'.$batch->from_plan_name.'» ya no existe: no hay a dónde devolverlo.')];
        }

        $despues = $item->after ?? [];
        $bloqueos = [];
        if ($user->plan !== ($despues['plan'] ?? null)) {
            $bloqueos[] = $this->evaluator->blocker('plan_changed_after',
                'Su plan cambió después del traslado (ahora «'.($user->plan ?? 'ninguno').'»). Revísalo a mano.');
        }
        if ($this->membershipChanged($user, $despues)) {
            $bloqueos[] = $this->evaluator->blocker('membership_changed_after',
                'Su membresía cambió después del traslado (fechas, estado o congelamiento). Revísalo a mano.');
        }
        $ultimo = $marcas[$user->id] ?? null;
        $marca = $item->before['last_payment_id'] ?? null;
        if ($ultimo !== null && ($marca === null || (int) $ultimo > (int) $marca)) {
            $bloqueos[] = $this->evaluator->blocker('paid_after',
                'Tiene cobros registrados después del traslado. Revísalo a mano.');
        }

        return $bloqueos;
    }

    /** @param  array<string, mixed>  $foto */
    private function membershipChanged(User $user, array $foto): bool
    {
        return $user->membershipStartDate !== ($foto['membership_start_date'] ?? null)
            || $user->membershipEndDate !== ($foto['membership_end_date'] ?? null)
            || $user->status !== ($foto['status'] ?? null)
            || ($user->membership_frozen_at !== null) !== (bool) ($foto['frozen'] ?? false);
    }

    /** @return array{plan: string|null, membership_end_date: string|null, changed_since: bool}|null */
    private function current(?User $user, PlanTransferItem $item): ?array
    {
        if ($user === null) {
            return null;
        }

        $despues = $item->after ?? [];

        return [
            'plan' => $user->plan,
            'membership_end_date' => $user->membershipEndDate,
            'changed_since' => $user->plan !== ($despues['plan'] ?? null) || $this->membershipChanged($user, $despues),
        ];
    }

    /**
     * @param  Collection<int, PlanTransferItem>  $items
     * @param  list<int>|null  $userIds
     * @return Collection<int, PlanTransferItem>
     */
    private function selectItems(Collection $items, ?array $userIds): Collection
    {
        if ($userIds === null) {
            return $items->values();
        }

        $pedidos = array_values(array_unique(array_map('intval', $userIds)));
        $faltan = array_values(array_diff($pedidos, $items->pluck('user_id')->filter()->map(fn ($id): int => (int) $id)->all()));
        if ($faltan !== []) {
            throw PlanTransferException::notInBatch($faltan);
        }

        return $items->filter(fn (PlanTransferItem $i): bool => in_array((int) $i->user_id, $pedidos, true))->values();
    }

    /**
     * Quiénes se pidieron y se quedaron fuera, con el primer motivo.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array{user_id: int|null, code: string}>
     */
    private function excluded(array $items, string $key, string $flag = 'eligible'): array
    {
        $fuera = [];
        foreach ($items as $item) {
            if (! $item[$flag]) {
                $fuera[] = ['user_id' => $item[$key], 'code' => $item['blockers'][0]['code'] ?? 'blocked'];
            }
        }

        return $fuera;
    }

    /** @return array{created_by: int|null, created_by_name: string|null, created_by_role: string|null} */
    private function author(Request $request): array
    {
        $actor = AdminActor::from($request);

        return [
            'created_by' => $actor?->id,
            'created_by_name' => $actor?->name,
            'created_by_role' => $actor?->role,
        ];
    }

    /**
     * La señal silenciosa a la app, ya con todo confirmado: «tu membresía y
     * tus funciones cambiaron, vuelve a preguntar». Sin notificación, sin
     * texto. Mismas dos señales que la mejora de plan, sin la de pago, porque
     * aquí no hubo pago.
     *
     * @param  list<int>  $userIds
     */
    private function signal(array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        foreach (Member::query()->whereIn('user_id', $userIds)->pluck('id') as $memberId) {
            RealtimeEvents::membership((int) $memberId);
            RealtimeEvents::features((int) $memberId);
        }
    }
}

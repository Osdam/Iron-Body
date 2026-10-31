<?php

namespace App\Services\Membership;

use App\Exceptions\PlanArchiveException;
use App\Models\MembershipSubscription;
use App\Models\Plan;
use App\Services\Audit\AuditTrail;
use App\Support\Access\AdminActor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ARCHIVAR Y REACTIVAR UN PLAN.
 *
 * Archivar es lo que se hace en vez de borrar un plan con historia: deja de
 * venderse por cualquier vía y de verse en la app, y sigue existiendo para sus
 * cobros, sus traslados y los socios que lo tienen.
 *
 * QUÉ BANDERAS. `sellable=false` y `visible_in_app=false` siempre. Y también
 * `active=false`, porque `active` NO afecta a quien ya tiene el plan:
 *   · acceso: MembershipAccess::plansByName() carga todos los planes, sin
 *     filtrar por `active` (MembershipAccess.php:694);
 *   · funciones de la app: Member::resolvedFeatures() y
 *     MemberPayload::featuresFor() buscan por nombre (Member.php:404,
 *     MemberPayload.php:48);
 *   · IA: IronAiMembershipAccessService busca por nombre
 *     (IronAiMembershipAccessService.php:109);
 *   · renovación automática: cobra el importe CONGELADO de la suscripción, sin
 *     leer el plan (RecurringBillingService.php:145);
 *   · su membresía en la app sale de `users`, no del catálogo.
 * Donde `active` sí se mira es en lo que VENDE o lleva a alguien a ese plan:
 * mejora (PlanUpgrades.php:303), traslado hacia él
 * (PlanTransferEvaluator.php:107), suscripción nueva
 * (MembershipSubscriptionService.php:83), catálogo de la app
 * (MembershipPlanController.php:15) y la venta comercial (Plan::sellable(),
 * SalesPaymentGuardrailService, CreatePaymentLinkTool, UltronCommitService).
 * Es justo lo que un plan archivado no debe hacer.
 *
 * Ni archivar ni reactivar tocan socios, cobros, deudas ni facturas.
 *
 * Bajo cerrojo de la fila del plan: dos clics no archivan dos veces, y la
 * segunda petición responde `already`.
 */
class PlanArchive
{
    /** El evento del catálogo que el canal del CRM ya publica (plan.updated). */
    private const EVENT = 'plan.updated';

    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * @return array{plan: Plan, already: bool}
     */
    public function archive(Request $request, Plan $plan, string $reason): array
    {
        return DB::transaction(function () use ($request, $plan, $reason): array {
            $p = Plan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            if ($p->isArchived()) {
                return ['plan' => $p, 'already' => true];
            }

            $antes = $p->getOriginal();
            $p->forceFill([
                'active' => false,
                'sellable' => false,
                'visible_in_app' => false,
                'archived_at' => now(),
                'archived_by' => AdminActor::id($request),
                'archive_reason' => $reason,
            ])->save();

            $this->audit->record($request, [
                'action' => 'status',
                'module' => 'Planes',
                'entity' => 'plan',
                'entity_id' => $p->id,
                'target_name' => $p->name,
                'summary' => "Archivó el plan {$p->name}",
                'changes' => $this->audit->changesOf($p, $antes, ['active', 'sellable', 'visible_in_app']),
                'metadata' => [
                    'event' => self::EVENT,
                    'operation' => 'archive',
                    'plan_id' => (int) $p->id,
                    'reason' => $reason,
                    'before' => ['active' => (bool) ($antes['active'] ?? false), 'sellable' => (bool) ($antes['sellable'] ?? false),
                        'visible_in_app' => (bool) ($antes['visible_in_app'] ?? false)],
                    'changed' => ['plans', 'features'],
                ],
            ]);

            return ['plan' => $p, 'already' => false];
        }, 3);
    }

    /**
     * @return array{plan: Plan, already: bool}
     *
     * @throws PlanArchiveException 422 si el plan no se puede volver a vender
     */
    public function reactivate(Request $request, Plan $plan, string $reason, ?bool $visibleInApp): array
    {
        return DB::transaction(function () use ($request, $plan, $reason, $visibleInApp): array {
            $p = Plan::query()->whereKey($plan->id)->lockForUpdate()->firstOrFail();
            if (! $p->isArchived() && $p->active && $p->sellable) {
                return ['plan' => $p, 'already' => true];
            }

            $motivos = $this->reactivationErrors($p);
            if ($motivos !== []) {
                throw PlanArchiveException::notReactivable($motivos);
            }

            $antes = $p->getOriginal();
            $archivo = [
                'archived_at' => $p->archived_at?->toIso8601String(),
                'archived_by' => $p->archived_by,
                'archive_reason' => $p->archive_reason,
            ];
            $p->forceFill([
                'active' => true,
                'sellable' => true,
                'visible_in_app' => $visibleInApp ?? true,
                'archived_at' => null,
                'archived_by' => null,
                'archive_reason' => null,
            ])->save();

            $this->audit->record($request, [
                'action' => 'status',
                'module' => 'Planes',
                'entity' => 'plan',
                'entity_id' => $p->id,
                'target_name' => $p->name,
                'summary' => "Reactivó el plan {$p->name}",
                'changes' => $this->audit->changesOf($p, $antes, ['active', 'sellable', 'visible_in_app']),
                'metadata' => [
                    'event' => self::EVENT,
                    'operation' => 'reactivate',
                    'plan_id' => (int) $p->id,
                    'reason' => $reason,
                    // El archivo que se levanta: la fila ya no lo guarda.
                    'previous_archive' => $archivo,
                    'changed' => ['plans', 'features'],
                ],
            ]);

            return ['plan' => $p, 'already' => false];
        }, 3);
    }

    /**
     * Por qué NO se puede volver a vender, con lo mismo que exige el alta o la
     * edición de un plan y lo que exige cobrarlo.
     *
     * @return list<array{code: string, message: string}>
     */
    public function reactivationErrors(Plan $plan): array
    {
        $motivos = [];
        $nombre = trim((string) $plan->name);

        if ($nombre === '' || mb_strlen($nombre) > 255) {
            $motivos[] = ['code' => 'plan_name_invalid', 'message' => 'El plan no tiene un nombre válido.'];
        } elseif (mb_strlen($nombre) > PlanTransferEvaluator::MAX_PLAN_NAME) {
            // `users.plan` guarda el nombre y es varchar(100): cobrarlo lo escribe ahí.
            $motivos[] = ['code' => 'plan_name_too_long', 'message' => 'El nombre del plan pasa de '.
                PlanTransferEvaluator::MAX_PLAN_NAME.' caracteres y no cabe en la ficha del socio. Acórtalo antes.'];
        }
        if ((float) $plan->price <= 0) {
            $motivos[] = ['code' => 'plan_price_invalid', 'message' => 'El plan no tiene un precio mayor que cero.'];
        }
        if ((int) $plan->duration_days <= 0) {
            $motivos[] = ['code' => 'plan_duration_invalid', 'message' => 'El plan no tiene una duración mayor que cero.'];
        }
        if ($plan->access_mode === PlanAccessRules::MODE_ENTRIES && (int) ($plan->entry_credits ?? 0) <= 0) {
            $motivos[] = ['code' => 'plan_entries_invalid', 'message' => 'Es un plan por entradas y no dice cuántas incluye.'];
        }

        $clave = MembershipAccess::planKey($nombre);
        $gemelo = Plan::query()->sellable()->whereKeyNot($plan->id)->get(['id', 'name', 'active', 'sellable', 'archived_at', 'price', 'duration_days'])
            ->first(fn (Plan $otro): bool => MembershipAccess::planKey((string) $otro->name) === $clave);
        if ($gemelo !== null) {
            $motivos[] = ['code' => 'plan_name_conflict', 'message' => 'Ya se vende otro plan con el mismo nombre («'.
                $gemelo->name.'», #'.$gemelo->id.'): la ficha del socio no podría distinguirlos.'];
        }

        return $motivos;
    }

    /**
     * Lo que conviene saber al archivar, sin bloquearlo: cuántos socios lo
     * tienen hoy (lo conservan) y cuántas renovaciones automáticas siguen
     * vivas (siguen cobrando el importe congelado).
     *
     * @return array{members_with_plan: int, live_subscriptions: int}
     */
    public function effects(Plan $plan): array
    {
        return [
            'members_with_plan' => self::membersNamed((string) $plan->name),
            'live_subscriptions' => DB::table('membership_subscriptions')
                ->where('plan_id', $plan->id)
                ->whereIn('status', MembershipSubscription::LIVE_STATUSES)
                ->count(),
        ];
    }

    /**
     * Socios cuyo `users.plan` es este nombre, comparado como lo lee la puerta
     * (sin mayúsculas ni tildes). `users.plan` guarda el NOMBRE, no el id.
     * Con DB::table: el scope del entrenador sobre User no puede esconder a
     * nadie aquí.
     */
    public static function membersNamed(string $planName): int
    {
        $clave = MembershipAccess::planKey($planName);

        return (int) DB::table('users')
            ->select('plan', DB::raw('COUNT(*) as total'))
            ->whereNotNull('plan')
            ->groupBy('plan')
            ->get()
            ->filter(fn ($f): bool => MembershipAccess::planKey((string) $f->plan) === $clave)
            ->sum('total');
    }
}

<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Access\CrmPermission;
use App\Support\SseStream;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cambios de membresía y catálogo para las pantallas abiertas del CRM.
 * La auditoría escrita con la mutación es el evento, como en el canal de
 * cuentas administrativas. El cliente vuelve a consultar las APIs canónicas.
 */
class MembershipRealtimeController extends Controller
{
    private const MEMBERSHIP_EVENTS = [
        'membership.plan.upgraded',
        'membership.plan.upgrade.cancelled',
        'membership.plan.upgrade.restored',
        'membership.plan.upgrade.updated',
    ];

    private const EVENTS = [...self::MEMBERSHIP_EVENTS, 'plan.created', 'plan.updated', 'plan.deleted'];

    private const CHANGED = ['membership', 'payments', 'history', 'features', 'plans'];

    public function stream(Request $request): Response
    {
        $admin = $request->attributes->get('auth_admin');
        if (! $admin instanceof Admin || ! collect(['members.view', 'plans.view', 'payments.view'])
            ->contains(fn (string $permission): bool => CrmPermission::allows($admin, $permission))) {
            return response()->json(['ok' => false, 'message' => 'No tienes permiso para el canal de membresías.'], 403);
        }

        $request->validate(['after_id' => ['sometimes', 'integer', 'min:0']]);
        $lastId = $request->header('Last-Event-ID');
        $hasCursor = $request->filled('after_id') || (is_string($lastId) && ctype_digit($lastId));
        $latest = (int) (AuditLog::max('id') ?? 0);
        $requested = $request->filled('after_id') ? (int) $request->query('after_id') : (int) $lastId;
        $cursor = $hasCursor ? min($requested, $latest) : $latest;
        $visible = (int) AuditLog::whereIn('metadata->event', self::EVENTS)->where('id', '<=', $cursor)->count();

        return $this->response(function () use (&$cursor, &$visible, $admin): void {
            // Los IDs se reservan antes del commit. Una transacción anterior
            // puede hacerse visible después de otra con un ID mayor. Ese
            // cambio bajo el cursor exige releer los datos canónicos.
            $count = (int) AuditLog::whereIn('metadata->event', self::EVENTS)->where('id', '<=', $cursor)->count();
            if ($count !== $visible) {
                SseStream::emit('ready', ['cursor' => $cursor, 'resync' => true], $cursor);
            }
            $logs = AuditLog::query()
                ->where('id', '>', $cursor)
                ->whereIn('metadata->event', self::EVENTS)
                ->orderBy('id')
                ->limit(50)
                ->get();

            foreach ($logs as $log) {
                $cursor = (int) $log->id;
                $event = $this->eventOf($log, $admin);
                if ($event !== null) {
                    SseStream::emit('memberships', $event, $log->id);
                }
            }
            // No contar otra vez aquí: ocultaría un commit tardío ocurrido
            // durante este tick. El siguiente tick debe poder detectarlo.
            $visible = $count + $logs->count();
        }, function () use (&$cursor): void {
            // También al reconectar: un commit bajo el cursor pudo ocurrir
            // mientras el cliente estaba desconectado. Se conserva el replay.
            SseStream::emit('ready', ['cursor' => $cursor, 'resync' => true], $cursor);
        });
    }

    /** La envoltura del transporte también permite probar ticks reales sin esperar 25 segundos. */
    protected function response(callable $tick, callable $onOpen): StreamedResponse
    {
        return SseStream::response($tick, 25, 1500, $onOpen);
    }

    /** Lista blanca: nunca transmite la auditoría completa ni sus importes o datos personales. */
    private function eventOf(AuditLog $log, Admin $admin): ?array
    {
        $meta = is_array($log->metadata) ? $log->metadata : [];
        $type = $meta['event'] ?? null;
        $isUpgrade = in_array($type, self::MEMBERSHIP_EVENTS, true);
        if ($isUpgrade) {
            if ($log->module !== 'Pagos' || $log->entity !== 'mejora de plan') {
                return null;
            }
            $userId = filter_var($meta['user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            // User lleva el scope global del entrenador. Incluso en un canal
            // compartido no se confirma la existencia de un socio ajeno.
            if (! $userId || ! User::whereKey($userId)->exists()) {
                return null;
            }
        } else {
            if (! in_array($type, self::EVENTS, true) || $log->module !== 'Planes' || $log->entity !== 'plan') {
                return null;
            }
            $userId = null;
        }

        $planId = filter_var($meta['plan_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! $planId) {
            return null;
        }
        $changed = array_values(array_intersect(
            is_array($meta['changed'] ?? null) ? array_filter($meta['changed'], 'is_string') : [],
            self::CHANGED,
        ));
        if ($isUpgrade && ! CrmPermission::allows($admin, 'members.view') && ! CrmPermission::allows($admin, 'payments.view')) {
            // Quien solo ve Planes puede refrescar sus métricas sin conocer
            // qué socio cambió de plan.
            $userId = null;
            $changed = ['plans'];
        }

        return [
            'type' => $type,
            'user_id' => $userId,
            'plan_id' => $planId,
            'changed' => $changed,
            'event_id' => (int) $log->id,
            'timestamp' => $log->created_at?->toIso8601String(),
        ];
    }
}

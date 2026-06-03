<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Estado de cuenta del miembro autenticado. Es la FUENTE DE VERDAD del "gate"
 * de acceso de la app móvil (ActivationGate): el Home/AppShell solo es
 * accesible con membresía activa O un pago aprobado/verificado.
 *
 * No muta nada; solo lee. Pensado para llamarse en cada entrada a la app
 * (splash/login/otp/post-pago) y resolver AppShell vs ActivationGate.
 */
class MemberAccountController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');
        $member->loadMissing('user');
        $user = $member->user;

        // Membresía activa: hay plan vinculado y la fecha de fin no está vencida.
        $endsAt = $user && $user->membership_end_date
            ? Carbon::parse($user->membership_end_date)->endOfDay()
            : null;
        $hasPlan = (bool) ($user && $user->plan);
        $membershipActive = $hasPlan && (! $endsAt || $endsAt->isFuture());

        // Pago aprobado/verificado del miembro (el CRM usa "paid" == "approved").
        $hasApprovedPayment = Payment::where('member_id', $member->id)
            ->whereRaw('LOWER(status) IN (?, ?)', ['approved', 'paid'])
            ->exists();

        // Regla de negocio: membresía activa O pago aprobado (o el CRM ya marcó
        // al miembro como activo) habilita el acceso completo a la app.
        $canAccessApp = $member->status === Member::STATUS_ACTIVE
            || $membershipActive
            || $hasApprovedPayment;

        $daysRemaining = $endsAt
            ? max(0, (int) Carbon::now()->startOfDay()->diffInDays($endsAt->copy()->startOfDay(), false))
            : null;

        return response()->json([
            'ok' => true,
            // 'active' = puede entrar al Home; 'activation_required' = ActivationGate.
            'account_status' => $canAccessApp ? 'active' : 'activation_required',
            'can_access_app' => $canAccessApp,
            'membership_active' => $membershipActive,
            'has_approved_payment' => $hasApprovedPayment,
            'member_status' => $member->status,
            'plan_name' => $hasPlan ? $user->plan : null,
            'membership_end_date' => $endsAt?->toDateString(),
            'days_remaining' => $daysRemaining,
        ]);
    }
}

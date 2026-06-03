<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountDeletionRequest;
use App\Models\Member;
use App\Models\Payment;
use App\Services\DeviceSessionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

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

    // ── Eliminación de cuenta / datos (App Store Guideline 5.1.1(v)) ──────────

    /** GET member/account/deletion-status — estado de la última solicitud. */
    public function deletionStatus(Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');
        $req = AccountDeletionRequest::where('member_id', $member->id)
            ->latest('id')->first();

        return response()->json([
            'ok' => true,
            'has_request' => (bool) $req,
            'status' => $req?->status,
            'requested_at' => $req?->requested_at?->toIso8601String(),
            'completed_at' => $req?->completed_at?->toIso8601String(),
        ]);
    }

    /** POST member/account/delete-request — registra la solicitud (idempotente). */
    public function deleteRequest(Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        $req = AccountDeletionRequest::where('member_id', $member->id)
            ->whereIn('status', [
                AccountDeletionRequest::STATUS_PENDING,
                AccountDeletionRequest::STATUS_PROCESSING,
            ])
            ->latest('id')->first()
            ?? AccountDeletionRequest::create([
                'member_id' => $member->id,
                'user_id' => $member->user_id,
                'status' => AccountDeletionRequest::STATUS_PENDING,
                'reason' => $data['reason'] ?? null,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
                'requested_at' => now(),
                'metadata' => ['source' => 'mobile_app'],
            ]);

        return response()->json([
            'ok' => true,
            'status' => $req->status,
            'requested_at' => $req->requested_at?->toIso8601String(),
        ]);
    }

    /**
     * POST member/account/delete-confirm — ejecuta el borrado:
     *  - revoca todas las sesiones (logout inmediato y bloqueo de login);
     *  - borra datos personales NO requeridos legalmente (rostro/documento,
     *    tokens y vínculos de dispositivo, retos de auth, biometría);
     *  - anonimiza el PII del miembro y del usuario;
     *  - CONSERVA contratos firmados y pagos/facturación por obligación legal.
     *
     * Funciona aunque el miembro no tenga membresía activa (cuenta creada sin
     * pagar también se puede eliminar).
     */
    public function deleteConfirm(Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');
        $member->loadMissing(['user', 'identityDocument', 'biometric']);
        $user = $member->user;

        $req = AccountDeletionRequest::where('member_id', $member->id)
            ->whereIn('status', [
                AccountDeletionRequest::STATUS_PENDING,
                AccountDeletionRequest::STATUS_PROCESSING,
            ])
            ->latest('id')->first()
            ?? AccountDeletionRequest::create([
                'member_id' => $member->id,
                'user_id' => $member->user_id,
                'status' => AccountDeletionRequest::STATUS_PROCESSING,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 512),
                'requested_at' => now(),
                'metadata' => ['source' => 'mobile_app'],
            ]);

        // 1) Revocar todas las sesiones activas (cierra sesión en todos lados).
        $sessions = app(DeviceSessionService::class);
        foreach ($sessions->activeSessions($member) as $session) {
            $sessions->revoke($session, 'account_deleted');
        }

        // 2) Borrar archivos personales NO requeridos legalmente (rostro/doc).
        //    NUNCA se tocan plantillas ni los PDFs de contratos firmados.
        $deletedFiles = 0;
        foreach ([
            $member->biometric?->face_path,
            $member->identityDocument?->front_path,
            $member->identityDocument?->back_path,
        ] as $path) {
            if ($path && Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
                $deletedFiles++;
            }
        }

        DB::transaction(function () use ($member, $user, $req, $deletedFiles): void {
            // 3) Borrar PII sensible no contable + credenciales de dispositivo.
            $member->biometric()->delete();
            $member->identityDocument()->delete();
            foreach ([
                'member_device_tokens', 'member_device_bindings',
                'member_reenrollment_tokens', 'member_auth_challenges',
            ] as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'member_id')) {
                    DB::table($table)->where('member_id', $member->id)->delete();
                }
            }

            // 4) Anonimizar el miembro (se conserva el id para contratos/pagos).
            $member->forceFill([
                'full_name' => 'Cuenta eliminada',
                'email' => null,
                'phone' => null,
                'gender' => null,
                'goal' => null,
                'injuries' => null,
                'birth_date' => null,
                // Libera el documento real y rompe el login por documento.
                'document_number' => 'DEL-'.$member->id,
                'biometric_status' => Member::BIOMETRIC_SKIPPED,
                'status' => Member::STATUS_DELETED,
                'anonymized_at' => now(),
            ])->save();

            // 5) Anonimizar el usuario vinculado (conserva pagos/facturación).
            if ($user) {
                $user->forceFill([
                    'name' => 'Cuenta eliminada',
                    'email' => 'deleted-'.$user->id.'@deleted.local',
                    'document' => 'DEL-'.$user->id,
                    'phone' => null,
                ])->save();
            }

            // 6) Cerrar la solicitud con auditoría de lo realizado/conservado.
            $req->forceFill([
                'status' => AccountDeletionRequest::STATUS_COMPLETED,
                'completed_at' => now(),
                'metadata' => array_merge((array) $req->metadata, [
                    'deleted_files' => $deletedFiles,
                    'anonymized' => ['member', $user ? 'user' : null],
                    'retained_for_legal' => ['member_contracts', 'payments'],
                ]),
            ])->save();
        });

        return response()->json([
            'ok' => true,
            'status' => AccountDeletionRequest::STATUS_COMPLETED,
            'message' => 'Tu cuenta y tus datos personales fueron eliminados. '
                .'Conservamos únicamente lo exigido por obligación legal/contable.',
        ]);
    }
}

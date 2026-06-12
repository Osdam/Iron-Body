<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Wompi\WompiCardPaymentRequest;
use App\Http\Requests\Wompi\WompiDaviplataPaymentRequest;
use App\Http\Requests\Wompi\WompiNequiPaymentRequest;
use App\Http\Requests\Wompi\WompiPsePaymentRequest;
use App\Models\Member;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\MembershipService;
use App\Services\Wompi\WompiAcceptanceService;
use App\Services\Wompi\WompiCardPaymentService;
use App\Services\Wompi\WompiDaviplataPaymentService;
use App\Services\Wompi\WompiNequiPaymentService;
use App\Services\Wompi\WompiPsePaymentService;
use App\Services\Wompi\WompiTransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Endpoints Wompi para la app. Flujo:
 *   - El miembro se toma de `auth.member` (NO del body): anti suplantación.
 *   - El monto es autoritativo del backend (Plan::price).
 *   - Tarjeta: la app envía SOLO el token (PCI). PSE/3DS/DaviPlata completan su
 *     autenticación OFICIAL; nunca se aprueba localmente.
 *   - La activación de membresía ocurre solo por webhook/reconciliación.
 *
 * Errores SANITIZADOS: el detalle técnico va a logs; al cliente, JSON amigable.
 */
class WompiPaymentController extends Controller
{
    public function __construct(private MembershipService $memberships)
    {
    }

    // ── Config / aceptación / bancos ─────────────────────────────────────────

    /** GET /payments/wompi/config — datos públicos para la app (llave PÚBLICA). */
    public function config(): JsonResponse
    {
        $cfg = (array) config('wompi');

        return response()->json([
            'environment' => $cfg['env'] ?? 'sandbox',
            'public_key'  => $cfg['public_key'] ?? null, // NO secreta
            'currency'    => $cfg['currency'] ?? 'COP',
            'methods'     => $cfg['methods'] ?? [],
            // La app valida que su llave pública y el backend sean del mismo ambiente.
            'api_url'     => $cfg['api_url'] ?? null,
        ]);
    }

    /** GET /payments/wompi/acceptance — enlaces de términos + tratamiento de datos. */
    public function acceptance(): JsonResponse
    {
        return response()->json(WompiAcceptanceService::make()->publicForApp());
    }

    /** GET /payments/wompi/pse/institutions — bancos PSE reales (cacheados). */
    public function pseInstitutions(Request $request): JsonResponse
    {
        $fresh = filter_var($request->query('refresh', false), FILTER_VALIDATE_BOOLEAN);
        $result = WompiPsePaymentService::make()->institutions($fresh);

        return response()->json([
            'data'      => $result['institutions'],
            'available' => $result['available'],
        ]);
    }

    // ── Cobros por método ────────────────────────────────────────────────────

    public function payCard(WompiCardPaymentRequest $request): JsonResponse
    {
        return $this->runPayment(
            fn (array $data) => WompiCardPaymentService::make()->process($data, $request->ip(), $request->userAgent()),
            $request
        );
    }

    public function payNequi(WompiNequiPaymentRequest $request): JsonResponse
    {
        return $this->runPayment(
            fn (array $data) => WompiNequiPaymentService::make()->process($data, $request->ip(), $request->userAgent()),
            $request
        );
    }

    public function payPse(WompiPsePaymentRequest $request): JsonResponse
    {
        return $this->runPayment(
            fn (array $data) => WompiPsePaymentService::make()->process($data, $request->ip(), $request->userAgent()),
            $request
        );
    }

    public function daviplataStart(WompiDaviplataPaymentRequest $request): JsonResponse
    {
        return $this->runPayment(
            fn (array $data) => WompiDaviplataPaymentService::make()->start($data, $request->ip(), $request->userAgent()),
            $request
        );
    }

    // ── DaviPlata OTP ────────────────────────────────────────────────────────

    public function daviplataSendOtp(Request $request, string $reference): JsonResponse
    {
        $tx = $this->ownedTransaction($request, $reference);
        $res = WompiDaviplataPaymentService::make()->sendOtp($tx);

        return response()->json($res, $res['ok'] ? 200 : 422);
    }

    public function daviplataValidateOtp(Request $request, string $reference): JsonResponse
    {
        $data = $request->validate(['otp' => 'required|string|min:4|max:8']);
        $tx = $this->ownedTransaction($request, $reference);
        $res = WompiDaviplataPaymentService::make()->validateOtp($tx, $data['otp']);

        return response()->json(array_merge($res, [
            'transaction' => $tx->fresh()->toWompiPublicArray(),
        ]), $res['ok'] ? 200 : 422);
    }

    public function daviplataResendOtp(Request $request, string $reference): JsonResponse
    {
        $tx = $this->ownedTransaction($request, $reference);
        $res = WompiDaviplataPaymentService::make()->resendOtp($tx);

        return response()->json($res, $res['ok'] ? 200 : 422);
    }

    // ── Estado / historial ───────────────────────────────────────────────────

    /** GET /payments/{reference}/status — estado real + membresía vigente. */
    public function status(Request $request, string $reference): JsonResponse
    {
        $tx = $this->ownedTransaction($request, $reference);

        $member = $tx->member_id ? Member::find($tx->member_id) : null;
        $user   = $tx->user_id ? User::find($tx->user_id) : ($member?->user);
        $active = $user ? $this->memberships->isActive($user) : false;

        return response()->json(array_merge($tx->toWompiPublicArray(), [
            'membership_active' => $active,
            'can_access_home'   => $active,
            'message'           => $this->statusMessage($tx->status),
        ]));
    }

    /** GET /payments/wompi/history — historial de transacciones Wompi del miembro. */
    public function history(Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');
        $limit = min(max((int) $request->query('limit', 50), 1), 100);

        $items = PaymentTransaction::query()
            ->where('provider', 'wompi')
            ->where(function ($q) use ($member) {
                $q->where('member_id', $member->id);
                if ($member->user_id) {
                    $q->orWhere('user_id', $member->user_id);
                }
            })
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (PaymentTransaction $t) => $t->toWompiPublicArray())
            ->values();

        return response()->json(['data' => $items]);
    }

    // ── Internos ─────────────────────────────────────────────────────────────

    /**
     * Ejecuta un cobro: resuelve el sujeto desde el miembro autenticado, mezcla
     * idempotencia y delega en el servicio. Cualquier error se sanitiza.
     */
    private function runPayment(callable $process, Request $request): JsonResponse
    {
        try {
            $data = $this->resolveSubject($request);
            $tx = $process($data);

            return response()->json($tx->toWompiPublicArray());
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->failSafe($e);
        }
    }

    /**
     * Construye el payload de pago con datos del MIEMBRO AUTENTICADO. El plan es
     * obligatorio salvo compras de tienda (purpose=store).
     */
    private function resolveSubject(Request $request): array
    {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');
        if (! $member) {
            throw ValidationException::withMessages(['member' => ['Sesión no válida.']]);
        }

        $data = $request->all();
        $isStore = ($data['purpose'] ?? null) === 'store';
        if (! $isStore && empty($data['plan_id'])) {
            throw ValidationException::withMessages([
                'plan_id' => ['El plan de membresía es obligatorio para procesar el pago.'],
            ]);
        }

        $user = $this->ensureUserForMember($member);

        $data['member_id'] = $member->id;
        $data['user_id'] = $user->id;
        $data['idempotency_key'] = $data['client_request_id'] ?? null;
        $data['customer'] = array_merge([
            'name'       => $member->full_name,
            'email'      => $member->email ?: $user->email,
            'phone'      => $member->phone,
            'doc_number' => $member->document_number,
            'doc_type'   => 'CC',
            'country'    => 'CO',
        ], (array) ($data['customer'] ?? []));

        return $data;
    }

    /** Localiza una transacción Wompi del miembro autenticado (404 si ajena). */
    private function ownedTransaction(Request $request, string $reference): PaymentTransaction
    {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');

        $tx = PaymentTransaction::query()
            ->where('provider', 'wompi')
            ->where('reference', $reference)
            ->where(function ($q) use ($member) {
                $q->where('member_id', $member->id);
                if ($member->user_id) {
                    $q->orWhere('user_id', $member->user_id);
                }
            })
            ->first();

        // Mismo 404 si no existe o no es del miembro (no filtra referencias ajenas).
        abort_if(! $tx, 404, 'Pago no encontrado.');

        return $tx;
    }

    private function ensureUserForMember(Member $member): User
    {
        if ($member->user) {
            return $member->user;
        }
        $user = User::query()->where('document', $member->document_number)->first();
        if (! $user && $member->email) {
            $user = User::query()->where('email', $member->email)->first();
        }
        if (! $user) {
            $email = $member->email ?: "member-{$member->id}@ironbody.local";
            if (User::query()->where('email', $email)->exists()) {
                $email = "member-{$member->id}-{$member->document_number}@ironbody.local";
            }
            $user = User::create([
                'name'     => $member->full_name,
                'email'    => $email,
                'password' => Hash::make(\Illuminate\Support\Str::random(40)),
                'document' => $member->document_number,
                'phone'    => $member->phone,
                'status'   => 'pending',
            ]);
        }
        $member->forceFill(['user_id' => $user->id])->save();

        return $user;
    }

    private function statusMessage(string $status): string
    {
        return match ($status) {
            PaymentTransaction::STATUS_APPROVED        => 'Pago confirmado. Tu membresía fue activada.',
            PaymentTransaction::STATUS_DECLINED        => 'El pago fue rechazado por el banco.',
            PaymentTransaction::STATUS_VOIDED          => 'El pago fue anulado.',
            PaymentTransaction::STATUS_ERROR           => 'No pudimos procesar el pago.',
            PaymentTransaction::STATUS_EXPIRED         => 'El pago expiró. Genera uno nuevo.',
            PaymentTransaction::STATUS_REQUIRES_ACTION => 'Completa la autenticación para finalizar tu pago.',
            default                                    => 'Tu pago está pendiente de confirmación.',
        };
    }

    private function failSafe(Throwable $e): JsonResponse
    {
        Log::error('Pago Wompi: error controlado', [
            'type'   => get_class($e),
            'detail' => mb_substr($e->getMessage(), 0, 300),
        ]);

        return response()->json([
            'ok'        => false,
            'status'    => 'error',
            'reference' => null,
            'reason'    => 'No pudimos procesar el pago. No se realizó ningún cobro. '
                . 'Intenta nuevamente o usa otro método.',
        ], 200);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\EpaycoApiClient;
use App\Services\EpaycoPaymentService;
use App\Services\MembershipService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Endpoints de pago ePayco — flujo 100% IN-APP (sin navegador/WebView).
 *
 * La app habla con: create (opcional, idempotencia), pay-card/pse/nequi/
 * daviplata, y {reference}/status. ePayco llama `confirmation` (S2S).
 * El pago se procesa por API ePayco desde Laravel; las llaves viven solo aquí.
 */
class EpaycoPaymentController extends Controller
{
    public function __construct(
        private EpaycoPaymentService $epayco,
        private EpaycoApiClient $api,
        private MembershipService $memberships,
    ) {
    }

    private array $createRules = [
        'amount'              => 'required|numeric|min:1',
        'currency'            => 'nullable|string|size:3',
        'description'         => 'nullable|string|max:160',
        'reference'           => 'nullable|string|max:120',
        'idempotency_key'     => 'nullable|string|max:120',
        'order_id'            => 'nullable|integer',
        'member_id'           => 'nullable|integer|exists:members,id',
        'user_id'             => 'nullable|integer',
        'plan_id'             => 'nullable|integer|exists:plans,id',
        'customer'            => 'nullable|array',
        'customer.name'       => 'nullable|string|max:120',
        'customer.last_name'  => 'nullable|string|max:120',
        'customer.email'      => 'nullable|email|max:160',
        'customer.phone'      => 'nullable|string|max:30',
        'customer.doc_type'   => 'nullable|string|max:5',
        'customer.doc_number' => 'nullable|string|max:30',
        'customer.city'       => 'nullable|string|max:60',
        'customer.address'    => 'nullable|string|max:120',
        'customer.country'    => 'nullable|string|max:2',
        'dues'                => 'nullable|integer|min:1|max:36',
    ];

    /** POST /api/payments/epayco/create — crea (o reutiliza) la transacción. */
    public function create(Request $request)
    {
        $data = $request->validate($this->createRules);
        $data = $this->resolvePaymentSubject($data);
        try {
            $tx = $this->epayco->createOrReuse($data);
            return response()->json($tx->toPublicArray(), 201);
        } catch (Throwable $e) {
            return $this->failSafe($e, $data['idempotency_key'] ?? null);
        }
    }

    /** POST /api/payments/epayco/pay-card — cobro con tarjeta por API. */
    public function payCard(Request $request)
    {
        $data = $request->validate($this->createRules + [
            'card'           => 'required|array',
            'card.number'    => 'required|string|min:13|max:19',
            'card.exp_month' => 'required|string|max:2',
            'card.exp_year'  => 'required|string|max:4',
            'card.cvc'       => 'required|string|min:3|max:4',
        ]);

        return $this->runPay('card', $data, $request->ip());
    }

    /**
     * POST /api/payments/epayco/pay-nequi — Smart Checkout v2. NO fuerza el
     * endpoint directo de Nequi (la cuenta puede no tenerlo habilitado): crea una
     * sesión y el usuario completa el pago en el checkout OFICIAL de ePayco.
     */
    public function payNequi(Request $request)
    {
        $data = $request->validate($this->createRules + [
            // El teléfono es opcional (solo prefill del checkout): el usuario
            // completa el pago dentro de ePayco.
            'phone' => 'nullable|string|min:10|max:13',
        ]);

        return $this->runCheckout('nequi', $data, $request->ip());
    }

    /**
     * POST /api/payments/epayco/checkout-session — Smart Checkout v2 genérico
     * para billeteras (nequi|daviplata, default nequi). Mismo contrato.
     */
    public function checkoutSession(Request $request)
    {
        $data = $request->validate($this->createRules + [
            'method' => 'nullable|string|in:nequi,daviplata',
            'phone'  => 'nullable|string|min:10|max:13',
        ]);
        $method = $data['method'] ?? 'nequi';
        unset($data['method']);

        return $this->runCheckout($method, $data, $request->ip());
    }

    /**
     * POST /api/payments/epayco/pay-pse — PSE exige redirección externa por
     * reglas del proveedor: queda en fallo controlado con mensaje (no navegador).
     */
    public function payPse(Request $request)
    {
        $data = $request->validate($this->createRules + [
            'pse'             => 'nullable|array',
            'pse.bank'        => 'required|string|max:10',
            'pse.person_type' => 'nullable|string|in:natural,juridica',
            'pse.doc_type'    => 'nullable|string|max:5',
            'pse.doc_number'  => 'nullable|string|max:30',
        ]);

        return $this->runPay('pse', $data, $request->ip());
    }

    /**
     * POST /api/payments/epayco/pay-daviplata — Smart Checkout v2. La cuenta
     * respondió "DaviPlata no habilitado" por API directa, así que (igual que
     * Nequi) se usa el checkout OFICIAL de ePayco vía sesión + bridge WebView.
     */
    public function payDaviplata(Request $request)
    {
        $data = $request->validate($this->createRules + [
            'phone' => 'nullable|string|min:10|max:13',
        ]);

        return $this->runCheckout('daviplata', $data, $request->ip());
    }

    /**
     * Ejecuta el cobro y SANITIZA cualquier error: el técnico va a logs; al
     * cliente solo le llega un JSON amigable (jamás SQL, rutas ni queries).
     */
    private function runPay(string $method, array $data, ?string $ip)
    {
        $data['ip'] = $ip ?? '127.0.0.1';
        $data = $this->resolvePaymentSubject($data);
        try {
            $tx = $this->epayco->payInApp($method, $data, $this->api);
            return response()->json($tx->toPublicArray());
        } catch (Throwable $e) {
            return $this->failSafe($e, $data['idempotency_key'] ?? null);
        }
    }

    /**
     * Smart Checkout v2 para billeteras (Nequi/DaviPlata): crea la sesión y
     * devuelve el contrato con flow=smart_checkout + session_id + bridge URL.
     */
    private function runCheckout(string $method, array $data, ?string $ip)
    {
        $data['ip'] = $ip ?? '127.0.0.1';
        $data = $this->resolvePaymentSubject($data);
        try {
            $tx = $this->epayco->startCheckoutSession($method, $data, $this->api);
            return response()->json($this->checkoutResponse($tx, $method));
        } catch (Throwable $e) {
            return $this->failSafe($e, $data['idempotency_key'] ?? null);
        }
    }

    /** Contrato que espera Flutter para abrir el Smart Checkout. */
    private function checkoutResponse(PaymentTransaction $tx, string $method): array
    {
        $pub = $tx->toPublicArray();
        // ok = se puede ABRIR el checkout: hay bridge URL (con sessionId o con
        // fallback de llave pública) y la transacción no falló.
        $ok = $tx->status !== PaymentTransaction::STATUS_FAILED
            && ! empty($pub['checkout_bridge_url']);

        return array_merge($pub, [
            'ok'                  => $ok,
            'method'              => $method,
            'flow'                => 'smart_checkout',
            'message'             => $ok
                ? 'Continúa en ePayco para completar el pago.'
                : ($tx->failure_reason ?: 'No pudimos iniciar el pago. Intenta nuevamente.'),
        ]);
    }

    private function resolvePaymentSubject(array $data): array
    {
        if (empty($data['plan_id'])) {
            throw ValidationException::withMessages([
                'plan_id' => ['El plan de membresia es obligatorio para procesar el pago.'],
            ]);
        }

        if (!empty($data['member_id'])) {
            $member = Member::query()->with('user')->find($data['member_id']);

            if (!$member) {
                throw ValidationException::withMessages([
                    'member_id' => ['El miembro seleccionado no existe.'],
                ]);
            }

            $user = $this->ensureUserForMember($member);
            $data['member_id'] = $member->id;
            $data['user_id'] = $user->id;

            if (empty($data['customer'])) {
                $data['customer'] = [];
            }

            $data['customer'] = array_merge([
                'name' => $member->full_name,
                'email' => $member->email ?: $user->email,
                'phone' => $member->phone,
                'doc_number' => $member->document_number,
                'doc_type' => 'CC',
                'country' => 'CO',
            ], $data['customer']);

            return $data;
        }

        if (!empty($data['user_id'])) {
            if (!User::whereKey($data['user_id'])->exists()) {
                throw ValidationException::withMessages([
                    'user_id' => ['El usuario seleccionado no existe. Envia member_id para pagos creados desde la app.'],
                ]);
            }

            return $data;
        }

        throw ValidationException::withMessages([
            'member_id' => ['Debes enviar member_id o user_id para asociar el pago.'],
        ]);
    }

    private function ensureUserForMember(Member $member): User
    {
        if ($member->user) {
            return $member->user;
        }

        $user = User::query()
            ->where('document', $member->document_number)
            ->first();

        if (!$user && $member->email) {
            $user = User::query()
                ->where('email', $member->email)
                ->first();
        }

        if (!$user) {
            $email = $member->email ?: "member-{$member->id}@ironbody.local";

            if (User::query()->where('email', $email)->exists()) {
                $email = "member-{$member->id}-{$member->document_number}@ironbody.local";
            }

            $user = User::create([
                'name' => $member->full_name,
                'email' => $email,
                'password' => Hash::make('default-password'),
                'document' => $member->document_number,
                'phone' => $member->phone,
                'status' => 'pending',
            ]);
        }

        $member->forceFill(['user_id' => $user->id])->save();

        return $user;
    }

    /**
     * Respuesta de error sanitizada (HTTP 200 con estado `failed`) para que la
     * app muestre un mensaje amable y enrute a "pago fallido". El detalle
     * técnico (sin tarjeta/CVV/tokens/llaves) queda en el log del servidor.
     */
    private function failSafe(Throwable $e, ?string $idempotencyKey)
    {
        Log::error('Pago ePayco: error controlado', [
            'type'      => get_class($e),
            'sqlstate'  => $e->getCode(),
            // Mensaje recortado: nunca se expone al cliente.
            'detail'    => mb_substr($e->getMessage(), 0, 300),
        ]);

        // Si el error fue una carrera de unicidad, intenta recuperar el estado
        // real por idempotency_key (no se crea ni se cobra de nuevo).
        if ($idempotencyKey) {
            $tx = PaymentTransaction::where('idempotency_key', $idempotencyKey)
                ->first();
            if ($tx) {
                return response()->json($tx->toPublicArray());
            }
        }

        return response()->json([
            'status'    => 'failed',
            'reference' => null,
            'reason'    => 'No pudimos procesar el pago. No se realizó ningún '
                . 'cobro. Intenta nuevamente o usa otro método.',
        ]);
    }

    /**
     * GET /api/payments/epayco/history — historial de pagos (datos públicos).
     * Solo expone `toPublicArray()`; nunca raw_response/tarjeta/CVV/tokens.
     */
    public function history(Request $request)
    {
        $limit = min(max((int) $request->query('limit', 50), 1), 100);
        $items = PaymentTransaction::query()
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (PaymentTransaction $t) => $t->toPublicArray())
            ->values();

        return response()->json(['data' => $items]);
    }

    /** GET /api/payments/{reference}/status — estado real (refresca si aplica). */
    public function status(string $reference)
    {
        $tx = $this->epayco->statusFor($reference);
        if (!$tx) {
            return response()->json(['message' => 'Transacción no encontrada'], 404);
        }

        // Acceso REAL al Home: nunca depende del estado local de Flutter. Misma
        // regla que /member/app-state: member activo O membresía vigente O pago
        // aprobado. El Home solo se desbloquea si esto es true.
        $member = $tx->member_id ? Member::find($tx->member_id) : null;
        $user   = $tx->user_id ? User::find($tx->user_id) : ($member?->user);
        $membershipActive = $user ? $this->memberships->isActive($user) : false;
        $hasApprovedPayment = $tx->member_id
            ? Payment::where('member_id', $tx->member_id)
                ->whereRaw('LOWER(status) IN (?, ?)', ['approved', 'paid'])->exists()
            : false;
        $canAccessHome = ($member && $member->status === Member::STATUS_ACTIVE)
            || $membershipActive
            || $hasApprovedPayment;

        return response()->json(array_merge($tx->toPublicArray(), [
            'membership_active' => $membershipActive,
            'can_access_home'   => $canAccessHome,
            'message'           => $this->statusMessage($tx->status),
        ]));
    }

    /** Mensaje funcional mínimo por estado (la app puede usar el suyo). */
    private function statusMessage(string $status): string
    {
        return match ($status) {
            PaymentTransaction::STATUS_APPROVED  => 'Pago confirmado. Tu membresía fue activada.',
            PaymentTransaction::STATUS_FAILED    => 'No pudimos procesar el pago.',
            PaymentTransaction::STATUS_CANCELLED => 'El pago fue cancelado.',
            PaymentTransaction::STATUS_EXPIRED   => 'El pago expiró. Genera uno nuevo.',
            default                              => 'Tu pago está pendiente de confirmación.',
        };
    }

    /**
     * GET /payments/epayco/checkout-bridge/{reference} — página WEB que abre el
     * Smart Checkout de ePayco (checkout-v2.js). Protegida por firma+TTL (?exp&t).
     *
     * Dos modos (definitivo y a prueba de fallos de session/create):
     *  1) Con sessionId (Smart Checkout Session v2): configure({sessionId}).
     *  2) Fallback con LLAVE PÚBLICA (no es secreta) + datos del backend:
     *     configure({key}) + checkout.open({...invoice/amount/...}). Así el pago
     *     SIEMPRE se puede abrir aunque session/create no devuelva sessionId.
     * NUNCA incluye private_key ni p_key. No activa membresía (solo webhook).
     */
    public function checkoutBridge(Request $request, string $reference)
    {
        $exp = (int) $request->query('exp', 0);
        $token = (string) $request->query('t', '');
        if (! $this->epayco->verifyBridgeToken($reference, $exp, $token)) {
            abort(403, 'Enlace de pago inválido o expirado.');
        }

        $tx = PaymentTransaction::where('reference', $reference)->first();
        if (! $tx || ! $tx->isInFlight()) {
            abort(404, 'Sesión de pago no encontrada o ya finalizada.');
        }

        $raw = is_array($tx->raw_response) ? $tx->raw_response : [];
        $sessionId = $raw['session_id'] ?? null;
        $cfg = config('services.epayco');
        $publicKey = (string) ($cfg['public_key'] ?? '');

        // Sin sessionId NI llave pública no hay forma de abrir el checkout.
        if (! $sessionId && $publicKey === '') {
            abort(404, 'No fue posible iniciar el pago.');
        }

        return response()
            ->view('payments.epayco_checkout_bridge', [
                'sessionId'    => $sessionId,
                'publicKey'    => $sessionId ? null : $publicKey, // solo en fallback
                'test'         => (bool) $cfg['test'],
                'checkoutJs'   => $cfg['checkout_js'] ?? 'https://checkout.epayco.co/checkout-v2.js',
                'responseUrl'  => $this->epayco->responseUrl(),
                'confirmationUrl' => $this->epayco->confirmationUrl(),
                'reference'    => $tx->reference,
                // Datos NO sensibles para el modo fallback (amount autoritativo).
                'amount'       => number_format((float) $tx->amount, 0, '.', ''),
                'currency'     => strtoupper((string) $tx->currency),
                'description'  => $tx->description ?: 'Membresía Iron Body',
                'memberId'     => (string) ($tx->member_id ?? ''),
                'planId'       => (string) ($tx->plan_id ?? ''),
                'method'       => (string) ($raw['requested_method'] ?? ''),
                'billing'      => is_array($tx->customer) ? $tx->customer : [],
            ])
            ->header('Cache-Control', 'no-store');
    }

    /** POST /api/payments/epayco/confirmation — webhook ePayco (idempotente). */
    public function confirmation(Request $request)
    {
        $this->epayco->handleConfirmation($request->all());

        return response()->json(['received' => true]);
    }

    /**
     * GET /api/payments/epayco/response — destino S2S de `url_response` de
     * ePayco (NO es una pantalla para el usuario; la app nunca abre navegador).
     * Refresca el estado para que la app lo recupere por /status.
     */
    public function response(Request $request)
    {
        $refPayco  = $request->query('ref_payco') ?? $request->query('x_ref_payco');
        $reference = $request->query('x_extra1')
            ?? $request->query('extra1')
            ?? $request->query('x_id_invoice');

        $tx = $reference
            ? PaymentTransaction::where('reference', $reference)->first()
            : null;

        if ($tx && $refPayco && !$tx->provider_ref) {
            $tx->provider_ref = $refPayco;
            $tx->save();
        }
        if ($tx) {
            try {
                $this->epayco->refreshFromProvider($tx);
            } catch (\Throwable $e) {
                // se ignora: la app reintenta por /status
            }
        }

        return response()->json(['received' => true]);
    }
}

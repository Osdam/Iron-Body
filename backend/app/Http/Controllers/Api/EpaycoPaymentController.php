<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\EpaycoApiClient;
use App\Services\EpaycoPaymentService;
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

    /** POST /api/payments/epayco/pay-nequi — push a la app Nequi (sin navegador). */
    public function payNequi(Request $request)
    {
        $data = $request->validate($this->createRules + [
            'phone' => 'required|string|min:10|max:10',
        ]);

        return $this->runPay('nequi', $data, $request->ip());
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

    /** POST /api/payments/epayco/pay-daviplata — requiere validación externa. */
    public function payDaviplata(Request $request)
    {
        $data = $request->validate($this->createRules + [
            'phone' => 'nullable|string|min:10|max:10',
        ]);

        return $this->runPay('daviplata', $data, $request->ip());
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

        return response()->json($tx->toPublicArray());
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

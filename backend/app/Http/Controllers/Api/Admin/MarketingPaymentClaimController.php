<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Member;
use App\Models\PaymentTransaction;
use App\Services\Marketing\ApprovedPaymentClaimer;
use App\Services\Marketing\MarketingInboxAuthorizationService;
use App\Services\Marketing\PaymentClaimAcceptanceService;
use App\Services\Marketing\PaymentClaimException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La acción del equipo sobre un pago del CRM que se quedó sin dueño.
 *
 * {@see ApprovedPaymentClaimer} solo PROPONE el enlace
 * cuando alguien se registra en la app con el número del prospecto que pagó, y
 * levanta la revisión interna en su conversación. Estos dos endpoints son la
 * forma de cerrar esa propuesta: aceptarla (enlaza el pago y activa la
 * membresía) o descartarla.
 *
 * AUTORIZACIÓN: exactamente la misma que resolver cualquier otra revisión del
 * Inbox (`POST conversations/{id}/staff-review/resolve`) —sesión de
 * administrador activa vía el blindaje global de /api/admin/*, más la capacidad
 * CAP_RESOLVE_REVIEW de MarketingInboxAuthorizationService—. No se inventa una
 * política nueva a propósito: esto ES resolver esa revisión, y dos matrices de
 * permisos para la misma alerta acabarían diciendo cosas distintas.
 *
 * Las respuestas siguen la forma del Inbox: `{ok, data}` o `{ok:false, code,
 * message}`, con un código estable por precondición para que la pantalla pueda
 * decirle a quien está en recepción qué hacer.
 */
class MarketingPaymentClaimController extends Controller
{
    public function __construct(
        private readonly PaymentClaimAcceptanceService $claims,
        private readonly MarketingInboxAuthorizationService $authz,
    ) {}

    /** POST payment-claims/{transaction}/accept */
    public function accept(Request $request, int $transaction): JsonResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        $data = $request->validate([
            // `exists` y no solo entero: enlazar dinero a un id inventado no
            // puede llegar a la capa de servicio como «socio sin usuario».
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'force' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $tx = PaymentTransaction::find($transaction);
        if (! $tx instanceof PaymentTransaction) {
            return $this->notFound();
        }

        $member = Member::find((int) $data['member_id']);
        if (! $member instanceof Member) {
            return $this->notFound();
        }

        /*
         * Saltarse la coincidencia de teléfono es la ÚNICA prueba de identidad
         * que tiene este flujo, y se anulaba con un booleano del cuerpo. Quien
         * la anula tiene que ser quien responde por ello: rol pleno, el mismo
         * nivel que el proyecto ya exige para decisiones de este calibre.
         */
        $force = (bool) ($data['force'] ?? false);
        if ($force && ! $this->authz->isFull($this->admin($request))) {
            return response()->json([
                'ok' => false,
                'code' => 'force_requires_full_role',
                'message' => 'Enlazar un pago a un socio cuyo teléfono no coincide exige un rol pleno.',
            ], 403);
        }

        try {
            $result = $this->claims->accept(
                $tx,
                $member,
                $this->actor($request),
                $data['note'] ?? null,
                $force,
            );
        } catch (PaymentClaimException $e) {
            return $this->refuse($e);
        }

        return response()->json(['ok' => true, 'data' => $result]);
    }

    /** POST payment-claims/{transaction}/reject */
    public function reject(Request $request, int $transaction): JsonResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }

        // El motivo es obligatorio: dentro de un mes nadie recordará por qué se
        // descartó el pago de alguien que sí pagó.
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $tx = PaymentTransaction::find($transaction);
        if (! $tx instanceof PaymentTransaction) {
            return $this->notFound();
        }

        try {
            $result = $this->claims->reject($tx, $this->actor($request), (string) $data['reason']);
        } catch (PaymentClaimException $e) {
            return $this->refuse($e);
        }

        return response()->json(['ok' => true, 'data' => $result]);
    }

    // ── Plumbing ──────────────────────────────────────────────────────────────

    /**
     * Misma puerta que el resto del Inbox: la política vive en el servicio de
     * autorización, no repartida por los controladores.
     */
    private function guard(Request $request): ?JsonResponse
    {
        $deny = $this->authz->deny(
            $this->admin($request),
            MarketingInboxAuthorizationService::CAP_RESOLVE_REVIEW,
        );

        if ($deny === null) {
            return null;
        }

        return response()->json([
            'ok' => false,
            'code' => $deny['code'],
            'message' => $deny['message'],
        ], $deny['status']);
    }

    private function admin(Request $request): ?Admin
    {
        $admin = $request->attributes->get('auth_admin');

        return $admin instanceof Admin ? $admin : null;
    }

    /**
     * El actor, ya garantizado por guard(): sin admin resuelto no se llega
     * aquí. Enlazar dinero exige una persona con nombre, nunca el secreto
     * compartido de automatización.
     */
    private function actor(Request $request): Admin
    {
        $admin = $this->admin($request);
        if (! $admin instanceof Admin) {
            abort(401);
        }

        return $admin;
    }

    /** El motivo del rechazo, tal cual lo define el dominio. */
    private function refuse(PaymentClaimException $e): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => $e->errorCode,
            'message' => $e->getMessage(),
        ], $e->status);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => 'not_found',
            'message' => 'Ese pago no existe.',
        ], 404);
    }
}

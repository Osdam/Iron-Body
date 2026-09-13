<?php

namespace App\Http\Middleware;

use App\Models\Member;
use App\Services\Caja\MembershipFinancialStanding;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corta el acceso a un beneficio de membresía cuando el socio tiene una deuda
 * de gimnasio vencida.
 *
 * POR QUÉ UN MIDDLEWARE Y NO UN `if` EN CADA CONTROLADOR. La condición vive en
 * un solo sitio; la lista de lo que protege vive en las rutas, donde se puede
 * leer de un vistazo y auditar. Repartir la comprobación por veinte
 * controladores garantiza que el vigésimo primero se olvide.
 *
 * POR QUÉ ADEMÁS DE `app-state`. La app publicada ya deja de abrir el Home
 * cuando el backend le dice `can_access_home=false`, y con eso basta para la
 * experiencia normal. Pero eso es cooperación del cliente, no autoridad: quien
 * hable directamente con la API se saltaría el gate. Esta es la capa que
 * decide de verdad.
 *
 * LO QUE NO TOCA. No se aplica al login, ni al perfil, ni al estado de cuenta,
 * ni a nada que sirva para pagar. Bloquear a alguien y a la vez impedirle
 * saldar su deuda sería encerrarlo: el bloqueo existe para que pague, no para
 * que no pueda.
 */
class EnsureMembershipBenefitsAvailable
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Member|null $member */
        $member = $request->attributes->get('auth_member');

        // Sin socio resuelto no es asunto de este guard: de la autenticación se
        // encarga `auth.member`, que corre antes.
        if (! $member instanceof Member) {
            return $next($request);
        }

        $standing = app(MembershipFinancialStanding::class);
        $resumen = $standing->summary($member);

        if (! $resumen['overdue']) {
            return $next($request);
        }

        return response()->json([
            'ok' => false,
            'code' => 'membership_payment_overdue',
            'message' => 'Tu membresía está retenida por un saldo vencido. Acércate a recepción o realiza el pago para reactivarla.',
            'balance' => $resumen['balance'],
            'due_date' => $resumen['due_date'],
            'days_overdue' => $resumen['days_overdue'],
        ], 403);
    }
}

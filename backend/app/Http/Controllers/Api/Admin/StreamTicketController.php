<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminSession;
use App\Services\Admin\StreamTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Entrega el vale con el que el CRM abre un stream SSE o carga una foto.
 *
 *   GET /api/admin/stream-ticket[?scope=stream|face-image]
 *
 * Se pide con la cabecera Authorization normal, así que el token de sesión no
 * pasa por la URL en ningún momento. El vale es de UN SOLO USO y de una sola
 * finalidad: el CRM pide uno por conexión y por foto. Sin `scope` es de
 * `stream`, que es lo que pedía el CRM antes de existir la finalidad.
 *
 * LIMITADOR (SC-7 de la revisión de seguridad del cierre): cada vale deja una
 * anotación en la caché `database` hasta que se canjea o la purga
 * (`admin:prune-stream-tickets`). Una sesión robada o un bucle del CRM las
 * acumularía sin techo, así que hay un cupo por PERSONA y finalidad
 * ({@see StreamTicketService::perMinute()}), holgado para el uso real: el
 * catálogo facial pide un vale por socio con foto y todos a la vez. Va aquí, en
 * el controlador, y no en la ruta: `routes/api.php` no se toca en el H9.
 * Pasado el cupo, 429 con `Retry-After`, sin anotar nada.
 *
 * La respuesta lleva el vale en el cuerpo: `Cache-Control: no-store` (lo pone
 * NoStoreAdminResponses en todo /api/admin) y `Referrer-Policy: no-referrer`.
 */
class StreamTicketController extends Controller
{
    public function __construct(private readonly StreamTicketService $tickets) {}

    public function issue(Request $request): JsonResponse
    {
        $session = $request->attributes->get('auth_admin_session');

        // El fallback de secreto compartido (n8n) no abre sesión: no hay nada
        // que representar en un vale, y esas automatizaciones no usan SSE.
        if (! $session instanceof AdminSession) {
            return response()->json([
                'ok' => false,
                'message' => 'Los vales de stream requieren una sesión de administrador.',
            ], 403);
        }

        $scope = $request->query('scope', StreamTicketService::SCOPE_STREAM);
        if (! is_string($scope) || ! in_array($scope, StreamTicketService::SCOPES, true)) {
            return response()->json([
                'ok' => false,
                'code' => 'ticket_scope_invalid',
                'message' => 'Finalidad de vale desconocida.',
            ], 422);
        }

        // El cubo es la persona de la sesión (todas sus pestañas y equipos), por finalidad:
        // la ráfaga del catálogo facial no deja sin vales a los canales, ni al revés.
        $cubo = StreamTicketService::rateKey($scope, (int) $session->admin_id);
        if (RateLimiter::tooManyAttempts($cubo, $this->tickets->perMinute($scope))) {
            $espera = max(1, RateLimiter::availableIn($cubo));

            return response()->json([
                'ok' => false,
                'code' => 'too_many_requests',
                'message' => 'Demasiados vales seguidos. El CRM vuelve a intentarlo solo.',
                'retry_after' => $espera,
            ], 429)->header('Retry-After', (string) $espera);
        }
        RateLimiter::hit($cubo, StreamTicketService::RATE_WINDOW_SECONDS);

        return response()
            ->json(['ok' => true, 'data' => $this->tickets->issue($session, $scope)])
            ->header('Referrer-Policy', 'no-referrer');
    }
}

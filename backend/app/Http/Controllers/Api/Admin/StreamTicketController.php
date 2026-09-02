<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminSession;
use App\Services\Admin\StreamTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Entrega el vale con el que el CRM abre los streams SSE.
 *
 * Se pide con la cabecera Authorization normal, así que el token de sesión no
 * pasa por la URL en ningún momento.
 */
class StreamTicketController extends Controller
{
    public function __construct(private readonly StreamTicketService $tickets)
    {
    }

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

        return response()->json(['ok' => true, 'data' => $this->tickets->issue($session)]);
    }
}

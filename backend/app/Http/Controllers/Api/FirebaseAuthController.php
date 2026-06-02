<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FirebaseCustomTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Endpoints relacionados con la integración Iron Body × Firebase Auth.
 *
 * Único endpoint actual:
 *   POST /api/app/firebase/custom-token
 *
 * Devuelve un Firebase Custom Token cuyo `uid` es el id real del member
 * autenticado. La app móvil usa ese token con `signInWithCustomToken`
 * para tener una sesión Firebase Auth válida — habilitando uploads
 * seguros a Firebase Storage con reglas del tipo
 * `request.auth.uid == userId`.
 */
class FirebaseAuthController extends Controller
{
    public function __construct(
        private readonly FirebaseCustomTokenService $tokenService,
    ) {
    }

    /**
     * POST /api/app/firebase/custom-token
     *
     * Protegido por middleware `auth.member`. **No acepta `user_id` ni
     * ningún campo en el body** — el uid se deriva exclusivamente del
     * member autenticado por el middleware. Esto es intencional: si la
     * app envía un user_id, lo ignoramos. Defense in depth.
     *
     * Respuesta éxito (200):
     *   {
     *     "success": true,
     *     "token":   "eyJhbGciOi...",
     *     "uid":     "42"
     *   }
     *
     * Respuesta error (500):
     *   {
     *     "success": false,
     *     "message": "No pudimos generar el token de Firebase."
     *   }
     */
    public function customToken(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');

        // El middleware auth.member ya garantiza esto, pero blindamos:
        // si por algún error de configuración el atributo no está, no
        // generamos token "anónimo" — fail-closed.
        if (!$member || !isset($member->id)) {
            return response()->json([
                'success' => false,
                'message' => 'Miembro no autenticado.',
            ], 401);
        }

        try {
            $token = $this->tokenService->createCustomToken((int) $member->id);

            return response()->json([
                'success' => true,
                'token'   => $token,
                'uid'     => (string) $member->id,
            ]);
        } catch (Throwable $e) {
            // Log SIN el token. Solo metadata de fallo — el service
            // account, secretos y tokens jamás se loggean.
            Log::error('Firebase custom token generation failed', [
                'member_id'       => $member->id,
                'exception_class' => $e::class,
                'error_message'   => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'No pudimos generar el token de Firebase.',
            ], 500);
        }
    }
}

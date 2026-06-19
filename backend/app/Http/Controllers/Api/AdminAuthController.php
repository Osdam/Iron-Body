<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginAdminRequest;
use App\Models\Admin;
use App\Models\AdminSession;
use App\Services\Admin\AdminSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Login del panel/CRM por email + contraseña. Emite un token de sesión opaco
 * (ver {@see AdminSessionService}) que el front envía como `Authorization:
 * Bearer <token>`. `auth.admin` (EnsureAdminAuth) lo valida en las rutas admin.
 *
 * Anti-enumeración: credenciales inválidas y cuenta deshabilitada responden con
 * el MISMO 401 genérico, sin revelar si el email existe.
 */
class AdminAuthController extends Controller
{
    private const INVALID_MESSAGE = 'Credenciales incorrectas.';

    public function __construct(private readonly AdminSessionService $sessions) {}

    public function login(LoginAdminRequest $request): JsonResponse
    {
        $data = $request->validated();

        $admin = Admin::where('email', $data['email'])->first();

        if ($admin) {
            $passwordOk = Hash::check($data['password'], $admin->password);
        } else {
            // Gastamos ~el mismo tiempo aunque el email no exista (hash bcrypt
            // real) para no filtrar su existencia por timing.
            Hash::make($data['password']);
            $passwordOk = false;
        }

        if (! $admin || ! $passwordOk || ! $admin->isActive()) {
            Log::info('auth:admin:login_failed', [
                'email' => $data['email'],
                'ip' => $request->ip(),
                'reason' => ! $admin ? 'no_admin' : (! $passwordOk ? 'bad_password' : 'disabled'),
            ]);

            return response()->json([
                'ok' => false,
                'code' => 'invalid_credentials',
                'message' => self::INVALID_MESSAGE,
            ], 401);
        }

        $issued = $this->sessions->issueSession(
            $admin,
            [
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ],
            (bool) ($data['remember'] ?? false),
        );

        $admin->forceFill(['last_login_at' => now()])->saveQuietly();

        return response()->json([
            'ok' => true,
            'token' => $issued['token'],
            'user' => $admin->toPublicArray(),
            'expiresAt' => optional($issued['session']->expires_at)->getTimestampMs(),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->attributes->get('auth_admin');

        return response()->json([
            'ok' => true,
            'user' => $admin->toPublicArray(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $session = $request->attributes->get('auth_admin_session');

        if ($session instanceof AdminSession) {
            $this->sessions->revoke($session, 'logout');
        }

        return response()->json(['ok' => true]);
    }
}

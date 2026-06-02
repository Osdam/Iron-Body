<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MemberDeviceTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Registro de tokens FCM del dispositivo del miembro (auth.member).
 *
 *  POST   /api/app/device-tokens                   → registrar/actualizar
 *  DELETE /api/app/device-tokens/{id}              → desactivar (propio)
 *  POST   /api/app/device-tokens/deactivate-current → desactivar por token
 */
class DeviceTokenController extends Controller
{
    public function __construct(private readonly MemberDeviceTokenService $service)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        if (!$member) {
            return response()->json(['success' => false, 'message' => 'No autenticado.'], 401);
        }

        $data = $request->validate([
            'token' => 'required|string|max:512',
            'platform' => 'nullable|string|in:android,ios',
            'device_name' => 'nullable|string|max:120',
            'app_version' => 'nullable|string|max:40',
            'notification_permission' => 'nullable|string|in:authorized,denied,provisional',
        ]);

        $tokenRow = $this->service->register($member, $data);

        return response()->json([
            'success' => true,
            'data' => ['id' => $tokenRow->id, 'is_active' => $tokenRow->is_active],
        ], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        if (!$member) {
            return response()->json(['success' => false, 'message' => 'No autenticado.'], 401);
        }
        $ok = $this->service->deactivateOwned($member, $id);
        return response()->json(['success' => $ok]);
    }

    public function deactivateCurrent(Request $request): JsonResponse
    {
        $member = $request->attributes->get('auth_member');
        if (!$member) {
            return response()->json(['success' => false, 'message' => 'No autenticado.'], 401);
        }
        $data = $request->validate(['token' => 'required|string|max:512']);
        $this->service->deactivate($data['token']);
        return response()->json(['success' => true]);
    }
}

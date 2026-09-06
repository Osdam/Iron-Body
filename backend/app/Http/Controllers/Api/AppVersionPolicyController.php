<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppVersionPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Política de versión mínima de la app.
 *
 * PÚBLICO a propósito: la app necesita saber si puede funcionar ANTES de que
 * nadie inicie sesión, y una versión retirada probablemente ya no sepa
 * autenticarse contra este backend.
 *
 * El cliente solo aporta su plataforma y su build. Todo lo demás —qué versión
 * es la última, cuál es la mínima y a qué tienda se envía— sale de la base de
 * datos. Aceptar cualquiera de esos valores por parámetro permitiría al cliente
 * decidir que su propia versión es válida, que es justo lo que este endpoint
 * existe para impedir.
 */
class AppVersionPolicyController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'platform' => 'required|string|in:android,ios',
            'build' => 'required|integer|min:0|max:2147483647',
        ]);

        $policy = AppVersionPolicy::forPlatform($data['platform']);

        // Sin política configurada NO se inventa una: se responde que no está
        // disponible y la app sigue funcionando. Devolver aquí un "actualiza"
        // por defecto convertiría un despiste de configuración en una app
        // inutilizable para todo el mundo.
        if (! $policy) {
            Log::warning('app.version_policy.missing', ['platform' => $data['platform']]);

            return response()->json([
                'ok' => false,
                'code' => 'VERSION_POLICY_UNAVAILABLE',
            ], 200);
        }

        if ($policy->isMisconfigured()) {
            Log::warning('app.version_policy.misconfigured', [
                'platform' => $policy->platform,
                'minimum_build' => $policy->minimum_build,
                'latest_build' => $policy->latest_build,
            ]);
        }

        return response()->json([
            'ok' => true,
            'data' => $policy->toAppArray((int) $data['build']),
        ]);
    }
}

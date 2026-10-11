<?php

namespace App\Http\Middleware;

use App\Services\Workforce\DeviceRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige que la petición venga firmada por un dispositivo autorizado (ver
 * {@see DeviceRegistry::verifyRequest()} y el contrato §1). Deja el dispositivo
 * verificado en `auth_device`.
 *
 * Las rutas del kiosko no tienen sesión de persona: la persona se verifica en
 * cada operación (rostro o código) y el dispositivo con esta firma.
 */
class VerifyDeviceSignature
{
    public function __construct(private readonly DeviceRegistry $devices) {}

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('auth_device', $this->devices->verifyRequest($request));

        return $next($request);
    }
}

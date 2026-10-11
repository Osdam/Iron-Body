<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Controller;
use App\Models\AuthorizedDevice;
use App\Services\Workforce\DeviceRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Vinculación y desafíos del dispositivo (contrato §1). Registrar no lleva
 * firma: lleva la prueba de posesión de la clave dentro del cuerpo y exige el
 * código que generó un administrador.
 */
class KioskDeviceController extends Controller
{
    public function __construct(private readonly DeviceRegistry $devices) {}

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pairing_code' => ['required', 'string', 'max:20'],
            'public_key' => ['required', 'string', 'max:2000'],
            'key_algorithm' => ['required', 'string', 'in:ES256'],
            'proof' => ['required', 'string', 'max:500'],
            'key_storage' => ['nullable', 'string', 'max:20'],
            'attestation' => ['nullable', 'array'],
            'attestation.platform' => ['nullable', 'string', 'max:20'],
            'attestation.certificate_chain' => ['nullable', 'array', 'max:10'],
            'attestation.certificate_chain.*' => ['string', 'max:8000'],
            'device' => ['nullable', 'array'],
            'device.platform' => ['nullable', 'string', 'max:20'],
            'device.manufacturer' => ['nullable', 'string', 'max:80'],
            'device.model' => ['nullable', 'string', 'max:80'],
            'device.os_version' => ['nullable', 'string', 'max:40'],
            'device.app_version' => ['nullable', 'string', 'max:40'],
        ]);

        $device = $this->devices->register($data, $request);
        $replayed = (bool) $device->getAttribute('replayed');

        return response()->json(['ok' => true, 'device' => self::present($device)], $replayed ? 200 : 201);
    }

    public function challenge(Request $request): JsonResponse
    {
        $data = $request->validate(['device_id' => ['required', 'string', 'max:64']]);
        $challenge = $this->devices->challenge($data['device_id'], $request);

        return response()->json([
            'ok' => true,
            'nonce' => $challenge->nonce,
            'expires_at' => $challenge->expires_at->toIso8601String(),
            'ttl' => (int) config('workforce.devices.challenge_ttl', 120),
        ]);
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json(['ok' => true, 'device' => self::present($request->attributes->get('auth_device'))]);
    }

    public static function present(AuthorizedDevice $device): array
    {
        return [
            'id' => $device->uuid,
            'name' => $device->name,
            'location' => $device->location,
            'status' => $device->status,
            'registered_at' => $device->registered_at?->toIso8601String(),
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'attestation' => [
                'status' => $device->attestation_status,
                'security_level' => $device->attestation_security_level,
            ],
        ];
    }
}

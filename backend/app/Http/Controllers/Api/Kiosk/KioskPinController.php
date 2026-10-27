<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Exceptions\WorkforceException;
use App\Http\Controllers\Controller;
use App\Models\AuthorizedDevice;
use App\Models\Identity;
use App\Models\WorkforceAuthorizationCode;
use App\Services\Workforce\AuthorizationCodeService;
use App\Services\Workforce\EmployeeDirectory;
use App\Services\Workforce\FaceReferenceService;
use App\Services\Workforce\KioskTokens;
use App\Services\Workforce\WorkforceEvents;
use App\Services\Workforce\WorkforcePins;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Crear o cambiar el PIN personal en la tablet (contrato §10.2).
 *
 * Tres cosas a la vez, en una sola petición: el código que dio un
 * administrador que tiene a la persona delante (el factor presencial), un
 * rostro que coincide con su plantilla (que el código no lo use cualquiera) y
 * un PIN que no se adivine. El código se gasta solo si lo demás pasa: un PIN
 * débil o una mala captura no obligan a pedir otro.
 *
 * Sin la regla de ambigüedad completa: a quien está en un par su rostro no lo
 * separa del otro, y por eso mismo necesita el PIN; quien lo autoriza es el
 * administrador, no la cámara. Sí se exige que ninguna otra plantilla esté más
 * cerca que la propia: con el código de uno, el otro del par (ya registrado)
 * no puede cambiarle el PIN (revisión de seguridad 2026-10-27, B-1).
 */
class KioskPinController extends Controller
{
    public function __construct(
        private readonly KioskTokens $tokens,
        private readonly EmployeeDirectory $directory,
        private readonly AuthorizationCodeService $codes,
        private readonly FaceReferenceService $faces,
        private readonly WorkforcePins $pins,
        private readonly WorkforceEvents $events,
    ) {}

    public function setup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_ref' => ['required', 'string', 'max:2000'],
            'authorization_code' => ['required', 'string', 'max:20'],
            'embedding' => ['required', 'array'],
            'pin' => ['required', 'string', 'regex:/^\d{6}$/'],
        ]);
        /** @var AuthorizedDevice $device */
        $device = $request->attributes->get('auth_device');
        $identity = $this->tokens->resolveRef($data['employee_ref'], $device);
        if (! $this->directory->profile($identity)->isActive()) {
            throw WorkforceException::employeeNotFound();
        }

        // Sin código vivo no se mira nada más: ni la cara (intentos de más) ni
        // la fortaleza del PIN, que mira el documento de quien se le parece y
        // delataría si la persona tiene un parecido (tester 2026-10-27, D-2).
        $this->codes->assertLive($identity, WorkforceAuthorizationCode::PURPOSE_PIN_SETUP);
        $this->pins->assertStrong($identity, $data['pin']);
        $this->assertOwnFace($identity, $data['embedding'], $request);

        // Fuera de toda transacción: el código confirma su intento fallido
        // antes de lanzar, y una transacción alrededor lo desharía.
        $code = $this->codes->consume($identity, WorkforceAuthorizationCode::PURPOSE_PIN_SETUP, $data['authorization_code'], $device->getKey());
        $pin = $this->pins->set($identity, $data['pin'], $device->getKey(), $code->getKey());

        $this->faces->audit(FaceReferenceService::EVENT_PIN_SET, $this->faces->referenceForIdentity($identity), [
            'identity_id' => $identity->getKey(),
            'device_id' => $device->getKey(),
        ], $request);
        $this->events->publish(WorkforceEvents::BIOMETRIC_UPDATED, 'pin_set', (string) $identity->uuid, WorkforceEvents::SOURCE_KIOSK);

        return response()->json(['ok' => true, 'pin' => ['status' => $pin->status(), 'set_at' => $pin->set_at?->toIso8601String()]], 201);
    }

    /**
     * La captura coincide con la plantilla validada de la persona. Sin PIN ni
     * regla de ambigüedad (ver la cabecera), con su propio contador de intentos.
     */
    private function assertOwnFace(Identity $identity, mixed $embedding, Request $request): void
    {
        $this->faces->assertWithinThreshold(
            $this->faces->referenceForIdentity($identity),
            $embedding,
            'pin-setup:identity:'.$identity->getKey(),
            $request,
        );
    }
}

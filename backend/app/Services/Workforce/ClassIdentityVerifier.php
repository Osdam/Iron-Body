<?php

namespace App\Services\Workforce;

use App\Exceptions\WorkforceException;
use App\Models\AuthorizedDevice;
use App\Models\DeviceSecurityEvent;
use App\Models\Identity;
use App\Models\Trainer;
use App\Models\TrainerDeviceSession;
use App\Models\WorkforceAuthorizationCode;
use Illuminate\Http\Request;

/**
 * Prueba de identidad y de dispositivo al iniciar o finalizar una clase.
 *
 * Hasta ahora la app mandaba `face_verified: true` y el servidor se lo creía:
 * la app solo comprobaba que la cámara había devuelto una foto. Ahora:
 *
 *  · `embedding` → el rostro se compara aquí contra la plantilla de la persona.
 *  · `fallback_code` → código de respaldo que generó un administrador.
 *  · solo `face_verified: true` (app publicada) → se acepta mientras dure la
 *    transición y queda registrado como `legacy_claim`, distinguible en el CRM.
 *
 * Dispositivo: si la petición viene firmada se verifica (y un fallo es error).
 * Sin firma, según la política: `monitor` guarda lo que DECLARA la sesión y
 * deja pasar; `enforce` lo rechaza.
 */
class ClassIdentityVerifier
{
    public const METHOD_FACE = 'face';

    public const METHOD_ADMIN_CODE = 'admin_code';

    public const METHOD_LEGACY_CLAIM = 'legacy_claim';

    public function __construct(
        private readonly DeviceRegistry $devices,
        private readonly FaceReferenceService $faces,
        private readonly AuthorizationCodeService $codes,
    ) {}

    /**
     * @param  array{embedding?:mixed, fallback_code?:?string, face_verified?:?bool}  $data
     * @return array{method:string, face_verified:bool, device_id:?int, declared_device:?string}
     */
    public function verify(Request $request, Trainer $trainer, array $data): array
    {
        [$device, $declared] = $this->device($request);
        $identity = $trainer->identity_id ? Identity::find($trainer->identity_id) : null;

        if (array_key_exists('embedding', $data) && $data['embedding'] !== null) {
            $ref = $this->faces->referenceForTrainer($trainer);
            // Contador de esta vía (exige la sesión del propio entrenador),
            // separado del kiosko y del login público.
            $key = 'class:'.($identity ? 'identity:'.$identity->getKey() : 'trainer:'.$trainer->getKey());
            $this->faces->verify($ref, $data['embedding'], $key, $request);

            return ['method' => self::METHOD_FACE, 'face_verified' => true, 'device_id' => $device?->getKey(), 'declared_device' => $declared];
        }

        if (! empty($data['fallback_code'])) {
            if ($identity === null) {
                throw WorkforceException::authorizationCodeInvalid();
            }
            $this->codes->consume($identity, WorkforceAuthorizationCode::PURPOSE_FALLBACK_CLASS, (string) $data['fallback_code'], $device?->getKey());

            return ['method' => self::METHOD_ADMIN_CODE, 'face_verified' => false, 'device_id' => $device?->getKey(), 'declared_device' => $declared];
        }

        if (($data['face_verified'] ?? false) === true && (bool) config('workforce.legacy.face_claim', true)) {
            return ['method' => self::METHOD_LEGACY_CLAIM, 'face_verified' => true, 'device_id' => $device?->getKey(), 'declared_device' => $declared];
        }

        throw new WorkforceException('Debes verificar tu rostro para esta operación.', 'face_required', 422);
    }

    /** @return array{0: ?AuthorizedDevice, 1: ?string} */
    private function device(Request $request): array
    {
        if ($this->devices->hasSignatureHeaders($request)) {
            return [$this->devices->verifyRequest($request), null];
        }

        if (config('workforce.devices.classes_policy') === 'enforce') {
            $this->devices->securityEvent(DeviceSecurityEvent::SIGNATURE_REQUIRED, null, null, $request);

            throw WorkforceException::deviceNotAuthorized();
        }

        /** @var TrainerDeviceSession|null $session */
        $session = $request->attributes->get('auth_trainer_session');
        $declared = $session ? trim((string) ($session->device_name ?: $session->platform)) : '';

        return [null, $declared !== '' ? mb_substr($declared, 0, 120) : null];
    }
}

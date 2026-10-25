<?php

namespace App\Services\Workforce;

use App\Exceptions\WorkforceException;
use App\Models\AuthorizedDevice;
use App\Models\DeviceSecurityEvent;
use App\Models\Identity;
use App\Models\Trainer;
use App\Models\TrainerDeviceSession;
use App\Models\WorkforceAuthorizationCode;
use App\Services\Classes\ClassBookingService;
use Illuminate\Http\Request;

/**
 * Prueba de identidad y de dispositivo al iniciar o finalizar una clase.
 *
 * Hasta ahora la app mandaba `face_verified: true` y el servidor se lo creía:
 * la app solo comprobaba que la cámara había devuelto una foto. Ahora:
 *
 *  · `embedding` → el rostro se compara aquí contra la plantilla de la persona,
 *    y SOLO desde un equipo autorizado que firma la petición (la tablet): un
 *    teléfono no puede presentarse como tablet mandando un vector.
 *  · `fallback_code` → código de respaldo de un administrador. Apagado por
 *    defecto (`workforce.classes.fallback_code`): una clase se opera con rostro.
 *  · solo `face_verified: true` (app publicada) → transición, registrado como
 *    `legacy_claim` (declarado, no verificado). Nunca para quien ya tiene un
 *    rostro que verifica, y nunca pasado `legacy.face_claim_until`.
 *
 * Dispositivo: si la petición viene firmada se verifica (y un fallo es error).
 * Sin firma, según la política: `monitor` guarda lo que DECLARA la sesión y
 * deja pasar (solo el rostro declarado, en transición); `enforce` lo rechaza.
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
            if ($device === null) {
                $this->devices->securityEvent(DeviceSecurityEvent::SIGNATURE_REQUIRED, null, null, $request);

                throw WorkforceException::deviceNotAuthorized();
            }
            $ref = $this->faces->referenceForTrainer($trainer);
            // Contador de esta vía (exige la sesión del propio entrenador),
            // separado del kiosko y del login público.
            $key = 'class:'.($identity ? 'identity:'.$identity->getKey() : 'trainer:'.$trainer->getKey());
            $this->faces->verify($ref, $data['embedding'], $key, $request);

            return ['method' => self::METHOD_FACE, 'face_verified' => true, 'device_id' => $device?->getKey(), 'declared_device' => $declared];
        }

        if (! empty($data['fallback_code'])) {
            if (! (bool) config('workforce.classes.fallback_code', false)) {
                throw WorkforceException::classFallbackDisabled();
            }
            if ($identity === null) {
                throw WorkforceException::authorizationCodeInvalid();
            }
            $this->codes->consume($identity, WorkforceAuthorizationCode::PURPOSE_FALLBACK_CLASS, (string) $data['fallback_code'], $device?->getKey());

            return ['method' => self::METHOD_ADMIN_CODE, 'face_verified' => false, 'device_id' => $device?->getKey(), 'declared_device' => $declared];
        }

        if (($data['face_verified'] ?? false) === true && $this->legacyClaimAllowed($trainer)) {
            return ['method' => self::METHOD_LEGACY_CLAIM, 'face_verified' => true, 'device_id' => $device?->getKey(), 'declared_device' => $declared];
        }

        throw new WorkforceException('Las clases se inician y finalizan con tu rostro en la tablet del gimnasio.', 'face_required', 422);
    }

    /**
     * El rostro declarado (app publicada, sin comparar nada) solo vale en la
     * transición: con el interruptor encendido, antes de la fecha límite y
     * para quien todavía NO tiene un rostro que verifique. Quien ya lo tiene
     * opera en la tablet: su cédula en un teléfono no basta para fingirlo.
     */
    private function legacyClaimAllowed(Trainer $trainer): bool
    {
        if (! (bool) config('workforce.legacy.face_claim', true)) {
            return false;
        }
        $until = trim((string) config('workforce.legacy.face_claim_until', ''));
        if ($until !== '' && $this->today() > $until) {
            return false;
        }

        return ! $this->faces->verifiable($this->faces->referenceForTrainer($trainer));
    }

    private function today(): string
    {
        return now()->setTimezone(ClassBookingService::TZ)->toDateString();
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

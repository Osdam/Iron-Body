<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Exceptions\WorkforceException;
use App\Http\Controllers\Controller;
use App\Models\AuthorizedDevice;
use App\Services\Workforce\EmployeeDirectory;
use App\Services\Workforce\EmployeeProfile;
use App\Services\Workforce\FaceReferenceService;
use App\Services\Workforce\KioskTokens;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Identificar a la persona en el kiosko (contrato §2). Identificar NO es
 * verificar: con el documento solo se sabe a quién hay que verificar después,
 * por eso aquí no sale el estado de su jornada ni nada más que su nombre corto,
 * sus cargos y si tiene rostro registrado.
 */
class KioskEmployeeController extends Controller
{
    public function __construct(
        private readonly EmployeeDirectory $directory,
        private readonly FaceReferenceService $faces,
        private readonly KioskTokens $tokens,
    ) {}

    public function identify(Request $request): JsonResponse
    {
        $data = $request->validate(['document' => ['required', 'string', 'max:50']]);
        $profile = $this->directory->findByDocument($data['document']);
        if ($profile === null || (! $profile->registersWorkday() && ! $profile->operatesClasses() && $profile->trainer === null)) {
            throw WorkforceException::employeeNotFound();
        }

        return response()->json(['ok' => true, 'employee' => $this->present($profile, $request->attributes->get('auth_device'))]);
    }

    public function roster(Request $request): JsonResponse
    {
        $device = $request->attributes->get('auth_device');

        return response()->json([
            'ok' => true,
            'data' => $this->directory->roster()->map(fn (EmployeeProfile $p) => $this->present($p, $device))->values(),
        ]);
    }

    private function present(EmployeeProfile $profile, AuthorizedDevice $device): array
    {
        return [
            'ref' => $this->tokens->employeeRef($profile->identity, $device),
            'display_name' => $profile->shortName(),
            'positions' => array_map(fn (array $p) => ['key' => $p['key'], 'label' => $p['label']], $profile->describedPositions()),
            'capabilities' => $profile->capabilities(),
            'biometric' => ['status' => $this->faces->statusForIdentity($profile->identity)],
        ];
    }
}

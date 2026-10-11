<?php

namespace App\Http\Controllers\Api\Kiosk;

use App\Http\Controllers\Controller;
use App\Services\Workforce\WorkdayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Entrada y salida de jornada (contrato §4). La hora la pone el servidor; la
 * tablet solo manda la verificación de UNA operación y su `operation_id`.
 */
class KioskWorkdayController extends Controller
{
    public function __construct(private readonly WorkdayService $workdays) {}

    public function clockIn(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $result = $this->workdays->clockIn($data['verification_token'], $data['operation_id'], $request->attributes->get('auth_device'), $request);

        return response()->json(['ok' => true] + $result, $result['result'] === 'replayed' ? 200 : 201);
    }

    public function clockOut(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $result = $this->workdays->clockOut($data['verification_token'], $data['operation_id'], $request->attributes->get('auth_device'), $request);
        unset($result['flagged_previous']);

        return response()->json(['ok' => true] + $result);
    }

    /** @return array{verification_token:string, operation_id:string} */
    private function validated(Request $request): array
    {
        return $request->validate([
            'verification_token' => ['required', 'string', 'max:200'],
            'operation_id' => ['required', 'uuid'],
        ]);
    }
}

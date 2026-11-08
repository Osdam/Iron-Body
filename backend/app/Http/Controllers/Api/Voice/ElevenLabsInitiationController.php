<?php

namespace App\Http\Controllers\Api\Voice;

use App\Http\Controllers\Controller;
use App\Services\Telephony\InboundGate;
use App\Services\Telephony\VoiceInitiationAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * `POST /api/webhooks/voice/elevenlabs/initiation` (contrato H8, D8-11 y
 * D8-16): el webhook de iniciación de las ENTRANTES. Pública, sin sesión: la
 * autentica el secreto de iniciación en la cabecera fija
 * `X-Voice-Initiation-Secret` (`VoiceInitiationAuth`, en tiempo constante).
 * Tope de 8 KB en la ruta; p95 < 300 ms y ninguna petición saliente.
 *
 * Sin el secreto bueno → 401 sin cuerpo útil ni fila: el agente se queda con
 * su valor por defecto `caller_authorized = no` (C-A2). Con él, la puerta de
 * entrantes decide y responde 200 con las variables dinámicas.
 */
class ElevenLabsInitiationController extends Controller
{
    public function __invoke(Request $request, InboundGate $puerta): JsonResponse
    {
        if (! VoiceInitiationAuth::check($request->header(VoiceInitiationAuth::HEADER))) {
            $conCabecera = $request->headers->has(VoiceInitiationAuth::HEADER);
            Log::channel('voice')->warning('voice.inbound.unauthorized', ['has_header' => $conCabecera]);

            return response()->json(['ok' => false, 'code' => 'unauthorized'], 401);
        }

        return response()->json($puerta->initiate((string) $request->getContent()));
    }
}

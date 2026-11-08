<?php

namespace App\Http\Controllers\Api\Voice;

use App\Http\Controllers\Controller;
use App\Services\Telephony\TwilioUsageAlert;
use App\Services\Twilio\TwilioRequestSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * `POST /api/webhooks/voice/twilio/usage-trigger` (contrato H8, D8-16): el
 * aviso de gasto de la SUBCUENTA de voz. Pública, sin sesión: la autentica
 * `X-Twilio-Signature` con `TwilioRequestSignature` y el token de la SUBCUENTA
 * (el del OTP no vale, OTP-2), sobre la base canónica configurada + la URI tal
 * como llega (nunca `fullUrl()`, OTP-3).
 *
 * Firma mala o sin configurar → 401, nada persistido (DF-W2; el aviso del OTP
 * da 403 y no se toca). Bien firmado → 200: se persiste y abre el freno.
 */
class TwilioVoiceUsageTriggerController extends Controller
{
    public const SIGNATURE_HEADER = 'X-Twilio-Signature';

    public function __invoke(Request $request, TwilioUsageAlert $aviso): JsonResponse
    {
        $cuerpo = (string) $request->getContent();
        $firma = TwilioRequestSignature::verifyRequest($request->getRequestUri(), $cuerpo, $request->header(self::SIGNATURE_HEADER));
        if ($firma !== TwilioRequestSignature::OK) {
            Log::channel('voice')->warning('voice.twilio.usage_rejected', ['result' => $firma]);

            return response()->json(['ok' => false, 'code' => 'invalid_signature'], 401);
        }

        return response()->json($aviso->ingest($cuerpo));
    }
}

<?php

namespace App\Http\Controllers\Api\Voice;

use App\Http\Controllers\Controller;
use App\Services\Telephony\TwilioUsageAlert;
use App\Services\Telephony\VoiceRateLimits;
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
 *
 * Límites (Enmienda 5, E5-4; {@see VoiceRateLimits}): nada por IP antes de
 * verificar la firma; después, por aviso (`IdempotencyToken`); las firmas
 * malas, en su propio cubo.
 */
class TwilioVoiceUsageTriggerController extends Controller
{
    public const SIGNATURE_HEADER = 'X-Twilio-Signature';

    public function __invoke(Request $request, TwilioUsageAlert $aviso): JsonResponse
    {
        $cuerpo = (string) $request->getContent();
        $firma = TwilioRequestSignature::verifyRequest($request->getRequestUri(), $cuerpo, $request->header(self::SIGNATURE_HEADER));
        if ($firma !== TwilioRequestSignature::OK) {
            // El fallo, en su propio cubo (ruta|IP|fallo): pasado el cupo, 429 sin registrar nada.
            $espera = VoiceRateLimits::failure(VoiceRateLimits::ROUTE_USAGE_TRIGGER, $request->ip());
            if ($espera !== null) {
                return VoiceRateLimits::tooMany($espera);
            }
            Log::channel('voice')->warning('voice.twilio.usage_rejected', ['result' => $firma]);

            return response()->json(['ok' => false, 'code' => 'invalid_signature'], 401);
        }

        // El límite va DESPUÉS de verificar la firma, por aviso; nunca por IP.
        $espera = VoiceRateLimits::authenticated(VoiceRateLimits::ROUTE_USAGE_TRIGGER, VoiceRateLimits::usageTriggerKey($cuerpo));
        if ($espera !== null) {
            Log::channel('voice')->warning('voice.twilio.usage_rate_limited', ['route' => VoiceRateLimits::ROUTE_USAGE_TRIGGER]);

            return VoiceRateLimits::tooMany($espera);
        }

        return response()->json($aviso->ingest($cuerpo));
    }
}

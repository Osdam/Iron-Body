<?php

namespace App\Http\Controllers\Api\Voice;

use App\Http\Controllers\Controller;
use App\Services\Telephony\VoicePostCall;
use App\Services\Telephony\VoiceRateLimits;
use App\Services\Voice\Dto\VoiceWebhookVerdict;
use App\Services\Voice\VoiceAdapters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * `POST /api/webhooks/voice/elevenlabs/post-call` (contrato H8, D8-16).
 * Pública, sin sesión: la autentica la FIRMA (`ElevenLabs-Signature`) sobre el
 * CUERPO CRUDO, con `VoiceEventVerifier` (1 800 s hacia atrás y 300 hacia
 * delante). Tope de 1 MB en la ruta.
 *
 * Firma mala → 401, sin leer el cuerpo ni escribir nada (DF-E1). Sin secreto
 * configurado → 401 también (ninguna firma vale; una entrada pública nunca
 * pasa sin credencial). Un sobre válido recibe 200 (duplicado, tipo
 * desconocido o audio descartado incluidos: nunca un 4xx permanente); un fallo
 * propio de la base sube como 5xx para que ElevenLabs reintente.
 *
 * Límites (Enmienda 5, E5-4; {@see VoiceRateLimits}): nada por IP antes de
 * verificar la firma; después, por EVENTO (tipo + conversación): pasado el
 * cupo por minuto del mismo evento (un reenvío en bucle o una repetición de
 * un cuerpo firmado), 429 con `Retry-After`, temporal. Las firmas malas, en
 * su propio cubo.
 */
class ElevenLabsPostCallController extends Controller
{
    public const SIGNATURE_HEADER = 'ElevenLabs-Signature';

    public function __invoke(Request $request, VoicePostCall $ingesta): JsonResponse
    {
        $veredicto = VoiceAdapters::eventVerifier()->verifyPostCall((string) $request->getContent(), $request->header(self::SIGNATURE_HEADER), now()->getTimestamp());
        if ($veredicto->outcome !== VoiceWebhookVerdict::OUTCOME_ACCEPTED) {
            // El fallo, en su propio cubo (ruta|IP|fallo): pasado el cupo, 429 sin registrar nada.
            $espera = VoiceRateLimits::failure(VoiceRateLimits::ROUTE_POST_CALL, $request->ip());
            if ($espera !== null) {
                return VoiceRateLimits::tooMany($espera);
            }
            Log::channel('voice')->warning('voice.post_call.rejected', ['outcome' => $veredicto->outcome, 'reason' => $veredicto->reason]);

            return response()->json(['ok' => false, 'code' => 'invalid_signature'], 401);
        }

        // El límite va DESPUÉS de verificar la firma, por evento; nunca por IP.
        $espera = VoiceRateLimits::authenticated(VoiceRateLimits::ROUTE_POST_CALL, VoiceRateLimits::postCallKey($veredicto));
        if ($espera !== null) {
            Log::channel('voice')->warning('voice.post_call.rate_limited', ['route' => VoiceRateLimits::ROUTE_POST_CALL, 'kind' => $veredicto->kind]);

            return VoiceRateLimits::tooMany($espera);
        }

        return response()->json($ingesta->ingest($veredicto));
    }
}

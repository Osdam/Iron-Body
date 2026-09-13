<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Aviso de gasto de Twilio (Usage Trigger).
 *
 * Twilio exige una URL de callback para crear un trigger, así que este endpoint
 * existe para recibirla. NO es un mecanismo de control: los Usage Triggers avisan
 * cuando el gasto ya ocurrió. Quien de verdad frena es el techo propio de
 * {@see \App\Services\Otp\OtpCostGuard}.
 *
 * Es público por necesidad —lo llama Twilio—, así que se defiende de dos formas:
 *   · firma X-Twilio-Signature verificada contra el Auth Token, de modo que
 *     nadie pueda inventarse una alerta de gasto;
 *   · idempotencia por `IdempotencyToken`, porque Twilio reintenta y una alerta
 *     repetida no debe multiplicar el ruido.
 *
 * Nunca responde con error a Twilio salvo firma inválida: un 500 aquí sólo
 * provocaría más reintentos sin arreglar nada.
 */
class TwilioUsageTriggerController extends Controller
{
    /** Ventana de deduplicación de un aviso repetido. */
    private const IDEMPOTENCIA_TTL = 86400;

    public function handle(Request $request): JsonResponse
    {
        if (! $this->firmaValida($request)) {
            Log::warning('twilio.usage_trigger.firma_invalida', ['ip' => $request->ip()]);

            return response()->json(['ok' => false], 403);
        }

        $token = (string) $request->input('IdempotencyToken', '');
        if ($token !== '' && ! Cache::add('twilio:usage-trigger:'.sha1($token), 1, self::IDEMPOTENCIA_TTL)) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }

        Log::warning('twilio.usage_trigger.alerta', [
            'trigger' => $request->input('FriendlyName'),
            'category' => $request->input('UsageCategory'),
            'trigger_by' => $request->input('TriggerBy'),
            'trigger_value' => $request->input('TriggerValue'),
            'current_value' => $request->input('CurrentValue'),
            'recurring' => $request->input('Recurring'),
            'fired_at' => $request->input('DateFired'),
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Firma de Twilio: HMAC-SHA1 del Auth Token sobre la URL completa más los
     * parámetros POST concatenados en orden alfabético de clave.
     *
     * Se compara en tiempo constante. Sin Auth Token configurado se rechaza:
     * fallar cerrado es preferible a aceptar avisos de cualquiera.
     */
    private function firmaValida(Request $request): bool
    {
        $token = (string) config('otp.twilio.token');
        $firma = (string) $request->header('X-Twilio-Signature', '');

        if ($token === '' || $firma === '') {
            return false;
        }

        $datos = $request->post();
        ksort($datos);

        $base = $request->fullUrl();
        foreach ($datos as $clave => $valor) {
            $base .= $clave.(is_array($valor) ? implode('', $valor) : (string) $valor);
        }

        $esperada = base64_encode(hash_hmac('sha1', $base, $token, true));

        return hash_equals($esperada, $firma);
    }
}

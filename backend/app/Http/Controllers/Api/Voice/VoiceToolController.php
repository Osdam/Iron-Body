<?php

namespace App\Http\Controllers\Api\Voice;

use App\Http\Controllers\Controller;
use App\Services\Telephony\VoiceRateLimits;
use App\Services\Telephony\VoiceToolAuth;
use App\Services\Telephony\VoiceTools;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * `POST /api/voice/tools/{tool}` (contrato H8, D8-10 y D8-16): las server
 * tools del catálogo CERRADO. Públicas, sin sesión: las autentica el Bearer
 * con el secreto de las herramientas (`VoiceToolAuth`, en tiempo constante).
 * Tope de 8 KB en la ruta.
 *
 * Primero la autenticación (401 para todo lo que no la trae, también una
 * herramienta que no existe: quien no se autentica no aprende el catálogo);
 * después, una herramienta fuera de la lista → 404 y el esquema → 422; los
 * rechazos de la comprobación de la llamada → 200 con un mensaje neutro.
 *
 * Límites (Enmienda 5, E5-4; {@see VoiceRateLimits}): nada por IP antes de
 * autenticar; después, por LLAMADA (además del tope de 20 herramientas por
 * llamada de la base); los fallos, en su propio cubo.
 */
class VoiceToolController extends Controller
{
    public function __invoke(Request $request, string $tool, VoiceTools $herramientas): JsonResponse
    {
        if (! VoiceToolAuth::check($request->header(VoiceToolAuth::HEADER))) {
            // El fallo, en su propio cubo (ruta|IP|fallo): pasado el cupo, 429 sin registrar nada.
            $espera = VoiceRateLimits::failure(VoiceRateLimits::ROUTE_TOOLS, $request->ip());
            if ($espera !== null) {
                return VoiceRateLimits::tooMany($espera);
            }
            Log::channel('voice')->warning('voice.tool.unauthorized', ['tool_known' => in_array($tool, VoiceTools::TOOLS, true)]);

            return response()->json(['ok' => false, 'verdict' => 'unauthorized'], 401);
        }

        // El límite va DESPUÉS de autenticar, por llamada; nunca por IP.
        $cuerpo = (string) $request->getContent();
        $espera = VoiceRateLimits::authenticated(VoiceRateLimits::ROUTE_TOOLS, VoiceRateLimits::toolKey($cuerpo));
        if ($espera !== null) {
            Log::channel('voice')->warning('voice.tool.rate_limited', ['route' => VoiceRateLimits::ROUTE_TOOLS, 'tool_known' => in_array($tool, VoiceTools::TOOLS, true)]);

            return VoiceRateLimits::tooMany($espera);
        }
        $r = $herramientas->handle($tool, $cuerpo);

        return response()->json($r['body'], $r['http']);
    }
}

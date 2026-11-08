<?php

namespace App\Http\Controllers\Api\Voice;

use App\Http\Controllers\Controller;
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
 */
class VoiceToolController extends Controller
{
    public function __invoke(Request $request, string $tool, VoiceTools $herramientas): JsonResponse
    {
        if (! VoiceToolAuth::check($request->header(VoiceToolAuth::HEADER))) {
            Log::channel('voice')->warning('voice.tool.unauthorized', ['tool_known' => in_array($tool, VoiceTools::TOOLS, true)]);

            return response()->json(['ok' => false, 'verdict' => 'unauthorized'], 401);
        }
        $r = $herramientas->handle($tool, (string) $request->getContent());

        return response()->json($r['body'], $r['http']);
    }
}

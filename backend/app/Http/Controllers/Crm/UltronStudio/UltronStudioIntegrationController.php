<?php

namespace App\Http\Controllers\Crm\UltronStudio;

use App\Http\Controllers\Concerns\ResolvesPagination;
use App\Http\Controllers\Controller;
use App\Models\UltronStudio\StudioIntegrationSnapshot;
use App\Rules\PlainText;
use App\Services\UltronStudio\StudioAccess;
use App\Services\UltronStudio\StudioIntegrationConfig;
use App\Services\UltronStudio\StudioIntegrations;
use App\Services\UltronStudio\StudioTwilioPanel;
use App\Services\UltronStudio\StudioTwilioProbe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ULTRON STUDIO: las integraciones comerciales (contrato H6 §I5–§I7, rutas 39,
 * 43–46, 55 y 56): el estado de un vistazo, Twilio y sus llamadas, la voz, la
 * configuración autorizada y su reconocimiento, y la sonda de solo lectura de
 * Twilio. Ver {@see UltronStudioController} para las dos puertas comunes a los
 * controladores del Studio.
 *
 * CERO RED POR DEFECTO: ninguna de estas rutas sale a la red salvo la sonda, y
 * solo con su bandera, su permiso y su cupo. Reconocer la configuración no
 * depende de la bandera de escrituras (decisión 11). Ninguna respuesta lleva
 * secretos ni teléfonos: solo huellas cortas, referencias enmascaradas y
 * recuentos. Los servicios se resuelven en cada acción.
 */
class UltronStudioIntegrationController extends Controller
{
    use ResolvesPagination;

    /** `fingerprint` de un reconocimiento: sha256 en hexadecimal en minúsculas, como lo calcula el servidor. */
    private const SHA256 = '/^[0-9a-f]{64}$/D';

    /** GET /api/admin/ultron-studio/integrations/status (sin red) */
    public function status(Request $request, StudioIntegrations $integrations): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);

        return response()->json(['ok' => true, 'data' => $integrations->status($access)]);
    }

    /** GET /api/admin/ultron-studio/integrations/twilio (sin red) */
    public function twilio(Request $request, StudioTwilioPanel $twilio): JsonResponse
    {
        StudioAccess::requirePerson($request);

        return response()->json(['ok' => true, 'data' => $twilio->panel()]);
    }

    /** GET /api/admin/ultron-studio/integrations/twilio/calls?page&per_page (≤ 50): solo recuentos y fechas, sin datos del prospecto */
    public function calls(Request $request, StudioTwilioPanel $twilio): JsonResponse
    {
        StudioAccess::requirePerson($request);
        $request->validate(self::pagination());

        return response()->json(['ok' => true, 'data' => $twilio->calls($this->resolvePerPage($request, 20, StudioTwilioPanel::PER_PAGE_MAX))]);
    }

    /** GET /api/admin/ultron-studio/integrations/voice (sin red): en este hito no se hace ninguna llamada */
    public function voice(Request $request, StudioIntegrations $integrations): JsonResponse
    {
        StudioAccess::requirePerson($request);

        return response()->json(['ok' => true, 'data' => $integrations->voicePanel()]);
    }

    /** GET /api/admin/ultron-studio/integrations/config — un GET nunca escribe */
    public function config(Request $request, StudioIntegrationConfig $config): JsonResponse
    {
        StudioAccess::requirePerson($request);

        return response()->json(['ok' => true, 'data' => $config->overview()]);
    }

    /**
     * POST /api/admin/ultron-studio/integrations/config/acknowledge
     *   {clientRequestId, provider, fingerprint, note?≤500}
     *
     * 201 con la instantánea; 200 `replayed` (dentro de `data`) si se repite.
     */
    public function acknowledge(Request $request, StudioIntegrationConfig $config): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $request->validate([
            'clientRequestId' => ['required', 'uuid'],
            'provider' => ['required', 'string', Rule::in(StudioIntegrationSnapshot::PROVIDERS)],
            'fingerprint' => ['required', 'string', 'regex:'.self::SHA256],
            'note' => ['sometimes', 'nullable', 'string', 'max:'.StudioIntegrationConfig::NOTE_MAX, new PlainText(multiline: true)],
        ]);

        $resultado = $config->acknowledge($access, $request, strtolower($data['clientRequestId']), $data['provider'], $data['fingerprint'], $data['note'] ?? null);

        return response()->json(['ok' => true, 'data' => ['replayed' => $resultado['replayed'], 'snapshot' => $resultado['snapshot']]], $resultado['replayed'] ? 200 : 201);
    }

    /**
     * POST /api/admin/ultron-studio/integrations/twilio/probe   {clientRequestId}
     *
     * 201 con la sonda nueva; 200 `replayed` (la misma petición) o `reused`
     * (dentro del enfriamiento: la última, sin red). Un fallo de Twilio nunca
     * es un 5xx: es el `outcome` de la instantánea.
     */
    public function probe(Request $request, StudioTwilioProbe $probe): JsonResponse
    {
        $access = StudioAccess::requirePerson($request);
        $data = $request->validate(['clientRequestId' => ['required', 'uuid']]);

        $resultado = $probe->probe($access, $request, strtolower($data['clientRequestId']));
        $nueva = ! $resultado['replayed'] && ! $resultado['reused'];

        return response()->json(['ok' => true, 'data' => [
            'replayed' => $resultado['replayed'],
            'reused' => $resultado['reused'],
            'snapshot' => $resultado['snapshot'],
        ]], $nueva ? 201 : 200);
    }

    /** @return array<string, list<string>> */
    private static function pagination(): array
    {
        return [
            'page' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}

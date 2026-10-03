<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Marketing\MarketingAgentSwitch;
use App\Support\Access\AdminActor;
use App\Support\Access\AuthorizationMap;
use App\Support\SseStream;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * El interruptor del agente IA desde el CRM: leerlo, cambiarlo y escucharlo.
 *
 * Leer y escuchar piden `marketing.view` —lo ve quien trabaja en Mercadeo—;
 * cambiarlo pide `marketing.agent.manage`, una llave aparte: `marketing.manage`
 * la tiene cualquiera que conteste en el Inbox, y pausar el agente es una
 * decisión sobre todo el negocio. Ambos los resuelve el mapa central
 * ({@see AuthorizationMap}).
 */
class MarketingAgentController extends Controller
{
    public function __construct(private readonly MarketingAgentSwitch $switch) {}

    /** GET /api/admin/marketing/agent */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['ok' => true, 'data' => $this->switch->present($request)]);
    }

    /**
     * PUT /api/admin/marketing/agent   { paused: bool }
     *
     * Idempotente: pedir el estado en el que ya está devuelve `changed: false`
     * sin traza ni versión nueva.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate(['paused' => ['required', 'boolean']]);

        // Cambiarlo deja traza con autor, así que hace falta una persona: el
        // token compartido de automatizaciones no firma decisiones de negocio.
        if (AdminActor::from($request) === null) {
            return response()->json([
                'ok' => false,
                'code' => 'requires_admin_session',
                'message' => 'Pausar o reactivar el agente exige iniciar sesión en el CRM.',
            ], 403);
        }

        $resultado = $this->switch->set((bool) $data['paused'], $request);

        return response()->json([
            'ok' => true,
            'changed' => $resultado['changed'],
            'data' => $this->switch->present($request),
        ]);
    }

    /**
     * GET /api/admin/marketing/agent/stream   (SSE, `?ticket=` como los demás canales)
     *
     * Emite `agent` con el estado AL ABRIR —quien reconecta se pone al día sin
     * esperar a un cambio— y después solo cuando sube `version`. Se compara la
     * versión de la fila y no ids de auditoría, que en PostgreSQL pueden
     * confirmarse fuera de orden. El payload solo dice qué cambió; quién y
     * cuándo lo relee el CRM por el GET.
     *
     * Cada conexión dura ~25 s y el cliente reconecta solo.
     */
    public function stream(): SymfonyResponse
    {
        $ultima = null;
        $emitirSiCambio = function () use (&$ultima): void {
            $evento = $this->switch->streamEvent();

            if ($ultima !== $evento['version']) {
                $ultima = $evento['version'];
                SseStream::emit('agent', $evento, $evento['version']);
            }
        };

        return SseStream::response($emitirSiCambio, 25, 2000, $emitirSiCambio);
    }
}

<?php

namespace App\Http\Controllers\Crm\UltronStudio;

use App\Http\Controllers\Controller;
use App\Services\Outreach\Run\AssistedQueue;
use App\Services\Outreach\Run\OutreachAccess;
use App\Services\Outreach\Run\OutreachConfig;
use App\Services\Outreach\Run\OutreachException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * La BANDEJA ASISTIDA (contrato H9, §7 E11–E13, D9-9.5; §13.1 #22; T6): los
 * trabajos asistidos que salieron en vivo, reclamarlos (una vez) y registrar
 * el resultado del personal (fuente `staff`, idempotente por
 * `clientRequestId`). Solo quien reclamó registra el resultado. Sin
 * teléfonos: la ficha de la persona se abre por su enlace del CRM, con los
 * permisos que ya existen.
 */
class UltronStudioOutreachAssistedController extends Controller
{
    /** GET outreach/assisted/queue?filter=all|mine|unclaimed&limit (≤ 50) */
    /** L9-47 (H-15 de OPS9-b; L9-68): con la maestra apagada, toda ruta de la ejecución salvo `status` responde 409 `outreach_disabled`. */
    private static function requireEnabled(): void
    {
        if (! OutreachConfig::enabled()) {
            throw OutreachException::disabled();
        }
    }

    public function queue(Request $request, AssistedQueue $queue): JsonResponse
    {
        $access = OutreachAccess::require($request, OutreachAccess::ASSISTED_WORK);
        self::requireEnabled();
        $data = $request->validate([
            'filter' => ['sometimes', 'nullable', 'string', Rule::in(['all', 'mine', 'unclaimed'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.AssistedQueue::MAX_PAGE],
        ]);
        $pagina = $queue->page($access->actor, (string) ($data['filter'] ?? 'all'), (int) ($data['limit'] ?? 20));

        // I-4 (aditivo): `meta.withheld` = cuántos trabajos sin reclamar no se ofrecen ahora (freno o queja), sin decir de quién.
        return response()->json(['ok' => true, 'data' => $pagina['items'], 'meta' => ['withheld' => $pagina['withheld']]]);
    }

    /** POST outreach/assisted/{uuid}/claim   {clientRequestId}: una vez; 200 `replayed` para quien ya lo reclamó */
    public function claim(Request $request, AssistedQueue $queue, string $uuid): JsonResponse
    {
        $access = OutreachAccess::require($request, OutreachAccess::ASSISTED_WORK);
        self::requireEnabled();
        $data = $request->validate(['clientRequestId' => ['required', 'uuid']]);
        $r = $queue->claim($access->actor, $uuid, (string) $data['clientRequestId']);

        return response()->json(['ok' => true, 'data' => $r['job'], 'meta' => ['replayed' => $r['replayed']]], $r['replayed'] ? 200 : 201);
    }

    /** POST outreach/assisted/{uuid}/outcome   {clientRequestId, outcome, callbackAt?}: del conjunto cerrado del asistido; solo quien reclamó */
    public function outcome(Request $request, AssistedQueue $queue, string $uuid): JsonResponse
    {
        $access = OutreachAccess::require($request, OutreachAccess::ASSISTED_WORK);
        self::requireEnabled();
        $data = $request->validate([
            'clientRequestId' => ['required', 'uuid'],
            'outcome' => ['required', 'string', Rule::in(AssistedQueue::STAFF_OUTCOMES)],
            'callbackAt' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);
        $r = $queue->recordOutcome($access->actor, $uuid, (string) $data['outcome'], (string) $data['clientRequestId'], $data['callbackAt'] ?? null);

        return response()->json(['ok' => true, 'data' => $r], $r['replayed'] ? 200 : 201);
    }
}

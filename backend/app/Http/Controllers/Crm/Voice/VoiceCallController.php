<?php

namespace App\Http\Controllers\Crm\Voice;

use App\Http\Controllers\Controller;
use App\Models\Voice\VoiceCall;
use App\Services\Telephony\OutboundGate;
use App\Services\Telephony\TelephonyException;
use App\Services\Telephony\VoiceActor;
use App\Services\Telephony\VoiceCallsQuery;
use App\Services\Telephony\VoiceHangup;
use App\Services\Telephony\VoiceTelephonyStatus;
use App\Services\Voice\VoiceAdapters;
use App\Support\Access\CrmPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Las llamadas en el CRM (contrato H8, «API», `/api/admin/voice/*`). Cada ruta
 * exige su llave en `AuthorizationMap` (denegar por defecto) y, aquí, una
 * PERSONA y otra vez la llave.
 *
 *  - `GET status`, `GET calls`, `GET calls/{uuid}`, `GET metrics`:
 *    `telephony.view`, sin datos personales.
 *  - `POST calls` (la puerta de salientes) y `POST calls/{uuid}/hangup` (el
 *    corte): `telephony.call_place`, solo Super Admin por defecto.
 */
class VoiceCallController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        VoiceActor::require($request, CrmPermission::TELEPHONY_VIEW);

        return response()->json(['ok' => true] + VoiceTelephonyStatus::snapshot());
    }

    public function index(Request $request): JsonResponse
    {
        VoiceActor::require($request, CrmPermission::TELEPHONY_VIEW);
        $v = Validator::make($request->query(), [
            'status' => ['nullable', 'string', 'in:'.implode(',', VoiceCallsQuery::STATUS_FILTERS)],
            'direction' => ['nullable', 'string', 'in:inbound,outbound'],
            'page' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        if ($v->fails()) {
            throw TelephonyException::invalid(array_map(static fn (array $m): array => ['invalid'], $v->errors()->toArray()));
        }
        $d = $v->validated();

        return response()->json(['ok' => true] + VoiceCallsQuery::list(['status' => $d['status'] ?? null, 'direction' => $d['direction'] ?? null], (int) ($d['page'] ?? 1), (int) ($d['perPage'] ?? 25)));
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        VoiceActor::require($request, CrmPermission::TELEPHONY_VIEW);
        $llamada = VoiceCallsQuery::show($uuid);
        if ($llamada === null) {
            throw TelephonyException::notFound('call');
        }

        return response()->json(['ok' => true, 'call' => $llamada]);
    }

    public function metrics(Request $request): JsonResponse
    {
        VoiceActor::require($request, CrmPermission::TELEPHONY_VIEW);

        return response()->json(['ok' => true] + VoiceCallsQuery::metrics());
    }

    public function store(Request $request, OutboundGate $puerta): JsonResponse
    {
        $actor = VoiceActor::require($request, CrmPermission::TELEPHONY_CALL_PLACE);
        $v = Validator::make($request->all(), [
            'clientRequestId' => ['required', 'string', 'uuid'],
            'target' => ['required', 'array:kind,uuid'],
            'target.kind' => ['required', 'string', 'in:'.implode(',', OutboundGate::TARGET_KINDS)],
            'target.uuid' => ['required', 'string', 'uuid'],
        ]);
        if ($v->fails()) {
            throw TelephonyException::invalid(array_map(static fn (array $m): array => ['invalid'], $v->errors()->toArray()));
        }
        $d = $v->validated();
        $r = $puerta->request($actor, $d['target']['kind'], $d['target']['uuid'], $d['clientRequestId']);

        return response()->json($r['body'], $r['http']);
    }

    public function hangup(Request $request, string $uuid): JsonResponse
    {
        $actor = VoiceActor::require($request, CrmPermission::TELEPHONY_CALL_PLACE);
        $llamada = VoiceCall::query()->where('uuid', strtolower($uuid))->first();
        if ($llamada === null) {
            throw TelephonyException::notFound('call');
        }
        $r = VoiceHangup::hangup($llamada, VoiceAdapters::carrier(), $actor);

        return response()->json(['ok' => in_array($r['result'], [VoiceHangup::RESULT_HUNG_UP, VoiceHangup::RESULT_CANCELED], true)] + $r);
    }
}

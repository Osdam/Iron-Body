<?php

namespace App\Http\Controllers\Crm\Voice;

use App\Http\Controllers\Controller;
use App\Models\Voice\VoiceContact;
use App\Models\Voice\VoiceRneCheck;
use App\Services\Telephony\TelephonyException;
use App\Services\Telephony\VoiceActor;
use App\Services\Telephony\VoiceContacts;
use App\Support\Access\CrmPermission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Los contactos de la fase 4b en el CRM (contrato H8, D8-18, «API»): todas con
 * `telephony.contacts_manage` (solo Super Admin por defecto), una PERSONA y
 * su `clientRequestId`. El número entra en la petición y no vuelve a salir:
 * en las respuestas, solo sus 4 últimos dígitos.
 *
 * Consentimiento a CUATRO OJOS (E3-4): `POST contacts/{uuid}/consents` lo
 * carga una persona y `POST contacts/{uuid}/consents/confirm` lo confirma
 * OTRA; `POST contacts/{uuid}/revoke` lo revoca sin esperar a nadie.
 *
 * Auditoría B-T4: dar de alta, cargar y confirmar exigen además
 * `VOICE_CONTACTS_ENABLED` (apagada hasta la fase 4b). Se comprueba tras el
 * permiso y ANTES de validar el cuerpo: apagada, 409 `voice_contacts_disabled`
 * sea cual sea el cuerpo y sin escribir. El servicio lo vuelve a comprobar.
 */
class VoiceContactController extends Controller
{
    public function index(Request $request, VoiceContacts $contactos): JsonResponse
    {
        VoiceActor::require($request, CrmPermission::TELEPHONY_CONTACTS_MANAGE);
        $d = $this->validated($request->query(), ['page' => ['nullable', 'integer', 'min:1', 'max:100000'], 'perPage' => ['nullable', 'integer', 'min:1', 'max:100']]);

        return response()->json(['ok' => true] + $contactos->list((int) ($d['page'] ?? 1), (int) ($d['perPage'] ?? 25)));
    }

    public function store(Request $request, VoiceContacts $contactos): JsonResponse
    {
        $actor = VoiceActor::require($request, CrmPermission::TELEPHONY_CONTACTS_MANAGE);
        VoiceContacts::assertEnabled();
        $d = $this->validated($request->all(), [
            'clientRequestId' => ['required', 'string', 'uuid'],
            'number' => ['required', 'string', 'max:32'],
            'source' => ['required', 'array:type,id'],
            'source.type' => ['required', 'string', 'in:'.implode(',', VoiceContact::SOURCES)],
            'source.id' => ['nullable', 'integer', 'min:1'],
        ]);
        $r = $contactos->register($actor, ['clientRequestId' => $d['clientRequestId'], 'number' => $d['number'], 'sourceType' => $d['source']['type'], 'sourceId' => $d['source']['id'] ?? null]);

        return response()->json(['ok' => true, 'contact' => $r['contact']], $r['http']);
    }

    public function requestConsent(Request $request, string $uuid, VoiceContacts $contactos): JsonResponse
    {
        $actor = VoiceActor::require($request, CrmPermission::TELEPHONY_CONTACTS_MANAGE);
        VoiceContacts::assertEnabled();
        $d = $this->validated($request->all(), [
            'source' => ['required', 'string', 'in:'.implode(',', VoiceContacts::PERSON_SOURCES)],
            'evidence' => ['required', 'string', 'min:3', 'max:'.VoiceContacts::EVIDENCE_MAX],
        ]);

        return response()->json($contactos->requestConsent($actor, $uuid, $d), 202);
    }

    public function confirmConsent(Request $request, string $uuid, VoiceContacts $contactos): JsonResponse
    {
        $actor = VoiceActor::require($request, CrmPermission::TELEPHONY_CONTACTS_MANAGE);
        VoiceContacts::assertEnabled();
        $d = $this->validated($request->all(), ['clientRequestId' => ['required', 'string', 'uuid']]);

        return response()->json($contactos->confirmConsent($actor, $uuid, $d['clientRequestId']), 201);
    }

    public function revoke(Request $request, string $uuid, VoiceContacts $contactos): JsonResponse
    {
        $actor = VoiceActor::require($request, CrmPermission::TELEPHONY_CONTACTS_MANAGE);
        $d = $this->validated($request->all(), [
            'clientRequestId' => ['required', 'string', 'uuid'],
            'source' => ['required', 'string', 'in:'.implode(',', VoiceContacts::PERSON_SOURCES)],
            'evidence' => ['required', 'string', 'min:3', 'max:'.VoiceContacts::EVIDENCE_MAX],
        ]);

        return response()->json($contactos->revoke($actor, $uuid, $d), 201);
    }

    public function exclude(Request $request, VoiceContacts $contactos): JsonResponse
    {
        $actor = VoiceActor::require($request, CrmPermission::TELEPHONY_CONTACTS_MANAGE);
        $d = $this->validated($request->all(), [
            'clientRequestId' => ['required', 'string', 'uuid'],
            'contactUuid' => ['nullable', 'string', 'uuid', 'required_without:number'],
            'number' => ['nullable', 'string', 'max:32', 'required_without:contactUuid', 'prohibits:contactUuid'],
            'note' => ['nullable', 'string', 'max:'.VoiceContacts::EVIDENCE_MAX],
        ]);

        return response()->json($contactos->exclude($actor, [
            'clientRequestId' => $d['clientRequestId'], 'contactUuid' => $d['contactUuid'] ?? null, 'number' => $d['number'] ?? null, 'note' => $d['note'] ?? null,
        ]), 201);
    }

    public function rneCheck(Request $request, VoiceContacts $contactos): JsonResponse
    {
        $actor = VoiceActor::require($request, CrmPermission::TELEPHONY_CONTACTS_MANAGE);
        $d = $this->validated($request->all(), [
            'clientRequestId' => ['required', 'string', 'uuid'],
            'contactUuid' => ['required', 'string', 'uuid'],
            'checkedAt' => ['required', 'date'],
            'result' => ['required', 'string', 'in:'.implode(',', VoiceRneCheck::RESULTS)],
            'evidenceRef' => ['required', 'string', 'min:3', 'max:'.VoiceContacts::REF_MAX],
        ]);

        return response()->json($contactos->rneCheck($actor, $d), 201);
    }

    /**
     * @param  array<string, mixed>  $datos
     * @param  array<string, mixed>  $reglas
     * @return array<string, mixed>
     *
     * @throws TelephonyException 422 con los campos, sin sus valores
     */
    private function validated(array $datos, array $reglas): array
    {
        $v = Validator::make($datos, $reglas);
        if ($v->fails()) {
            throw TelephonyException::invalid(array_map(static fn (array $m): array => ['invalid'], $v->errors()->toArray()));
        }

        return $v->validated();
    }
}

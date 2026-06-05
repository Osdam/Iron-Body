<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Member;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Perfil editable del miembro autenticado. Fuente de verdad de los datos de
 * perfil que la app muestra/edita y de la foto (subida a Firebase Storage por
 * el cliente; aquí solo se guarda la URL/ruta tras validar ownership).
 *
 * Datos legalmente sensibles (documento) NO se editan desde aquí.
 */
class MemberProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');
        $member->loadMissing('user');

        return response()->json(['ok' => true, 'data' => $this->payload($member)]);
    }

    public function update(Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');

        $data = $request->validate([
            'full_name'      => ['sometimes', 'string', 'min:3', 'max:120'],
            'phone'          => ['sometimes', 'nullable', 'string', 'max:30'],
            'gender'         => ['sometimes', 'nullable', 'string', 'max:40'],
            'goal'           => ['sometimes', 'nullable', 'string', 'max:120'],
            'training_level' => ['sometimes', 'nullable', 'string', 'max:80'],
            'injuries'       => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        // El teléfono es dato VERIFICADO (OTP/2FA): NO se cambia desde la edición
        // normal de perfil, solo por el flujo seguro "Cambiar número". Si llega un
        // teléfono DISTINTO al actual, se bloquea (422); si es igual o vacío se
        // ignora para no romper un guardado normal.
        if (array_key_exists('phone', $data)) {
            $incoming = $data['phone'] !== null ? trim((string) $data['phone']) : null;
            $current  = $member->phone !== null ? trim((string) $member->phone) : null;
            if ($incoming !== null && $incoming !== '' && $incoming !== $current) {
                Log::warning('profile.phone_change_blocked', ['member_id' => $member->id]);

                return response()->json([
                    'ok'      => false,
                    'message' => 'El teléfono solo puede cambiarse desde el flujo de cambio de número verificado.',
                ], 422);
            }
            unset($data['phone']); // nunca se aplica por esta vía
        }

        $member->fill($data);
        $member->save();

        // Sincroniza nombre/teléfono al User vinculado (lo lee el CRM).
        if ($member->user) {
            $userPatch = [];
            if (array_key_exists('full_name', $data)) {
                $userPatch['name'] = $data['full_name'];
            }
            if (array_key_exists('phone', $data)) {
                $userPatch['phone'] = $data['phone'];
            }
            if ($userPatch) {
                $member->user->fill($userPatch)->save();
            }
        }

        Log::info('member:profile:updated', ['member_id' => $member->id, 'fields' => array_keys($data)]);

        return response()->json([
            'ok'   => true,
            'data' => $this->payload($member->fresh('user')),
        ]);
    }

    /**
     * Registra la foto ya subida a Firebase Storage por el cliente. El backend
     * NO sube el binario; valida ownership (auth_member) y guarda URL/ruta.
     */
    public function updatePhoto(Request $request): JsonResponse
    {
        /** @var Member $member */
        $member = $request->attributes->get('auth_member');

        $data = $request->validate([
            'url'  => ['required', 'url', 'max:2000'],
            'path' => ['nullable', 'string', 'max:512'],
        ]);

        $member->forceFill([
            'profile_photo_url'        => $data['url'],
            'profile_photo_path'       => $data['path'] ?? null,
            'profile_photo_updated_at' => now(),
        ])->save();

        Log::info('member:profile:photo-updated', ['member_id' => $member->id]);

        return response()->json(['ok' => true, 'data' => $this->payload($member)]);
    }

    private function payload(Member $member): array
    {
        $user = $member->user;

        return [
            'id'                => $member->id,
            'full_name'         => $member->full_name,
            'email'             => $member->email ?: $user?->email,
            'document_number_masked' => $this->mask($member->document_number),
            'phone'             => $member->phone,
            'gender'            => $member->gender,
            'goal'              => $member->goal,
            'training_level'    => $member->training_level,
            'injuries'          => $member->injuries,
            'birth_date'        => optional($member->birth_date)->toDateString(),
            'profile_photo_url' => $member->profile_photo_url,
            'profile_photo_updated_at' => optional($member->profile_photo_updated_at)->toIso8601String(),
            'biometric_status'  => $member->biometric_status,
        ];
    }

    private function mask(?string $doc): ?string
    {
        $doc = (string) $doc;
        if ($doc === '') {
            return null;
        }

        return strlen($doc) <= 4
            ? str_repeat('•', strlen($doc))
            : str_repeat('•', strlen($doc) - 4).substr($doc, -4);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginMemberRequest;
use App\Http\Requests\RegisterMemberRequest;
use App\Http\Requests\StoreMemberBiometricRequest;
use App\Http\Requests\StoreMemberIdentityRequest;
use App\Http\Requests\StoreMemberLegalConsentRequest;
use App\Http\Requests\StoreMemberSignatureRequest;
use App\Models\Member;
use App\Models\MemberBiometric;
use App\Models\Plan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MemberRegistrationController extends Controller
{
    public function login(LoginMemberRequest $request): JsonResponse
    {
        $member = Member::query()
            ->with('user')
            ->where('document_number', $request->validated('document_number'))
            ->first();

        if (! $member) {
            return response()->json([
                'message' => 'Documento no encontrado.',
            ], 404);
        }

        $user = $member->user;

        return response()->json([
            'ok' => true,
            'data' => [
                'token' => null,
                'member' => [
                    'id'               => $member->id,
                    'member_uuid'      => $member->member_uuid,
                    'full_name'        => $member->full_name,
                    'email'            => $member->email ?: $user?->email,
                    'document_number'  => $member->document_number,
                    'phone'            => $member->phone ?: $user?->phone,
                    'goal'             => $member->goal,
                    'plan_name'        => $user?->plan,
                    'membership_expiry' => $user?->membershipEndDate,
                    'access_hash'      => $member->access_hash,
                    'status'           => $member->status,
                    'features'         => $this->featuresFor($user),
                ],
            ],
        ]);
    }

    public function incomplete(): JsonResponse
    {
        $members = Member::query()
            ->whereIn('status', [
                Member::STATUS_PENDING_REGISTRATION,
                Member::STATUS_INCOMPLETE,
                Member::STATUS_FAILED,
                'created',
                'identity_verified',
                'identity_review',
                'legal_accepted',
                'signed',
            ])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->through(fn (Member $member): array => [
                'id' => $member->id,
                'member_id' => $member->id,
                'member_uuid' => $member->member_uuid,
                'name' => $member->full_name,
                'email' => $member->email,
                'document' => $member->document_number,
                'phone' => $member->phone,
                'status' => $member->status,
                'registration_status' => $member->status,
                'created_at' => $member->created_at,
                'updated_at' => $member->updated_at,
            ]);

        return response()->json($members);
    }

    public function register(RegisterMemberRequest $request): JsonResponse
    {
        try {
            $validated = $request->validated();
            $response = DB::transaction(function () use ($validated): JsonResponse {
                $existingMember = Member::query()
                    ->where('document_number', $validated['document_number'])
                    ->first();

                if ($existingMember) {
                    if (! $existingMember->isRegistrationResumable()) {
                        return response()->json([
                            'ok' => false,
                            'message' => 'Ya existe un miembro activo con este documento.',
                            'status' => 'duplicate_document',
                            'member_id' => $existingMember->id,
                            'member_uuid' => $existingMember->member_uuid,
                            'user_id' => $existingMember->user_id,
                            'registration_status' => $existingMember->status,
                        ], 409);
                    }

                    $existingMember->fill($validated);

                    if ($existingMember->status === 'created') {
                        $existingMember->status = Member::STATUS_PENDING_REGISTRATION;
                    }

                    $existingMember->user_id = $this->syncCrmUser($existingMember, $validated)->id;
                    $existingMember->save();

                    return response()->json($this->memberResponse($existingMember->fresh(), 'Ya existe un registro pendiente o incompleto con este documento. Continua el registro con este member_id.', [
                        'status' => 'duplicate_document',
                        'registration_status' => $existingMember->status,
                    ]));
                }

                $member = new Member(array_merge($validated, [
                    'status' => Member::STATUS_PENDING_REGISTRATION,
                ]));
                $member->user_id = $this->syncCrmUser($member, $validated)->id;
                $member->save();

                // Aviso operativo al CRM de nuevo registro (ADITIVO; idempotente).
                app(\App\Services\NotificationService::class)->notifyNewMemberRegistered($member);

                return response()->json($this->memberResponse($member, 'Miembro creado correctamente.', [
                    'status' => Member::STATUS_PENDING_REGISTRATION,
                    'registration_status' => Member::STATUS_PENDING_REGISTRATION,
                ]), 201);
            });

            return $response;
        } catch (Throwable) {
            return $this->serverError();
        }
    }

    public function identity(StoreMemberIdentityRequest $request, Member $member): JsonResponse
    {
        try {
            $validated = $request->validated();
            $old = $member->identityDocument;
            $front = $this->storePrivateFile($request->file('front'), $member, 'identity/front');
            $back = $this->storePrivateFile($request->file('back'), $member, 'identity/back');

            $member->identityDocument()->updateOrCreate(
                ['member_id' => $member->id],
                [
                    'document_type' => $validated['document_type'] ?? null,
                    'document_number' => $validated['document_number'],
                    'birth_date' => $validated['birth_date'] ?? null,
                    'ocr_full_name' => $validated['ocr_full_name'] ?? null,
                    'ocr_confidence' => $validated['ocr_confidence'] ?? null,
                    'identity_status' => $validated['identity_status'],
                    'front_path' => $front['path'],
                    'front_mime' => $front['mime'],
                    'front_size' => $front['size'],
                    'back_path' => $back['path'],
                    'back_mime' => $back['mime'],
                    'back_size' => $back['size'],
                ]
            );

            $this->updateRegistrationStatus($member, Member::STATUS_INCOMPLETE);
            $this->deleteOldFiles($old?->front_path, $old?->back_path);

            return response()->json($this->memberResponse($member->fresh(), 'Documento de identidad guardado.'));
        } catch (Throwable) {
            return $this->serverError();
        }
    }

    public function legalConsent(StoreMemberLegalConsentRequest $request, Member $member): JsonResponse
    {
        try {
            $validated = $request->validated();

            DB::transaction(function () use ($validated, $member): void {
                $member->legalConsent()->updateOrCreate(
                    ['member_id' => $member->id],
                    [
                        'accepted_at' => $validated['accepted_at'] ?? now(),
                        'contract_version' => $validated['contract_version'] ?? null,
                        'terms_and_conditions' => $this->toBoolean($validated['terms_and_conditions']),
                        'data_processing' => $this->toBoolean($validated['data_processing']),
                        'truthfulness' => $this->toBoolean($validated['truthfulness']),
                        'service_contract' => $this->toBoolean($validated['service_contract']),
                        'physical_risk_waiver' => $this->toBoolean($validated['physical_risk_waiver']),
                        'guardian_authorization' => $this->toBoolean($validated['guardian_authorization'] ?? false),
                    ]
                );

                if ($member->is_minor || filled($validated['guardian_full_name'] ?? null)) {
                    $member->guardian()->updateOrCreate(
                        ['member_id' => $member->id],
                        [
                            'guardian_full_name' => $validated['guardian_full_name'],
                            'guardian_document_number' => $validated['guardian_document_number'],
                            'guardian_phone' => $validated['guardian_phone'] ?? null,
                            'guardian_email' => $validated['guardian_email'] ?? null,
                            'guardian_relationship' => $validated['guardian_relationship'] ?? null,
                            'guardian_accepts_responsibility' => $this->toBoolean($validated['guardian_accepts_responsibility'] ?? false),
                        ]
                    );
                }

                $this->updateRegistrationStatus($member, Member::STATUS_INCOMPLETE);
            });

            return response()->json($this->memberResponse($member->fresh(), 'Consentimientos legales guardados.'));
        } catch (Throwable) {
            return $this->serverError();
        }
    }

    public function signature(StoreMemberSignatureRequest $request, Member $member): JsonResponse
    {
        try {
            $validated = $request->validated();
            $old = $member->signature;
            $signature = $this->storePrivateFile($request->file('signature'), $member, 'legal/signatures');

            $member->signature()->updateOrCreate(
                ['member_id' => $member->id],
                [
                    'kind' => $validated['kind'],
                    'signature_path' => $signature['path'],
                    'signature_mime' => $signature['mime'],
                    'signature_size' => $signature['size'],
                ]
            );

            $this->updateRegistrationStatus($member, Member::STATUS_INCOMPLETE);
            $this->deleteOldFiles($old?->signature_path);

            return response()->json($this->memberResponse($member->fresh(), 'Firma guardada.'));
        } catch (Throwable) {
            return $this->serverError();
        }
    }

    public function biometric(StoreMemberBiometricRequest $request, Member $member): JsonResponse
    {
        try {
            $validated = $request->validated();
            $old = $member->biometric;
            $face = $this->storePrivateFile($request->file('face'), $member, 'biometrics/faces');

            $member->biometric()->updateOrCreate(
                ['member_id' => $member->id],
                [
                    'face_path' => $face['path'],
                    'face_mime' => $face['mime'],
                    'face_size' => $face['size'],
                    'captured_at' => $validated['captured_at'] ?? now(),
                    'bytes_length' => $validated['bytes_length'] ?? $face['size'],
                    // Versionado cross-platform: si el cliente envía la metadata
                    // del normalizador, la referencia nace "active" y no legacy.
                    'normalizer_version' => $validated['normalizer_version'] ?? null,
                    'enrolled_platform' => $validated['platform'] ?? null,
                    'biometric_reference_status' => MemberBiometric::STATUS_ACTIVE,
                    'last_biometric_enrolled_at' => now(),
                ]
            );

            // La biometría quedó registrada (Apple: estado explícito, opcional).
            $member->biometric_status = Member::BIOMETRIC_REGISTERED;
            $member->save();

            $this->updateRegistrationStatus($member, Member::STATUS_ACTIVE);
            $this->deleteOldFiles($old?->face_path);

            return response()->json($this->memberResponse($member->fresh(), 'Biometria facial guardada.'));
        } catch (Throwable) {
            return $this->serverError();
        }
    }

    /**
     * Marca la biometría como OMITIDA en la creación de cuenta (Apple: el
     * usuario puede crear cuenta sin facial; se verifica luego o presencialmente
     * en el gimnasio). No guarda ningún dato biométrico.
     */
    public function skipBiometric(Member $member): JsonResponse
    {
        if ($member->biometric_status !== Member::BIOMETRIC_REGISTERED) {
            $member->biometric_status = Member::BIOMETRIC_SKIPPED;
            $member->save();
        }
        $this->updateRegistrationStatus($member, Member::STATUS_ACTIVE);

        return response()->json($this->memberResponse($member->fresh(), 'Biometria pendiente.'));
    }

    public function destroy(Member $member): JsonResponse
    {
        try {
            DB::transaction(function () use ($member): void {
                $member->deleteStoredFiles();
                $user = $member->user;
                $member->delete();
                $user?->delete();
            });

            return response()->json(null, 204);
        } catch (Throwable) {
            return $this->serverError();
        }
    }

    private function syncCrmUser(Member $member, array $data): User
    {
        $user = $member->user;

        if (! $user) {
            $user = User::query()
                ->where('document', $data['document_number'])
                ->first();
        }

        if (! $user && filled($data['email'] ?? null)) {
            $user = User::query()
                ->where('email', $data['email'])
                ->first();
        }

        if (! $user) {
            $user = new User([
                'password' => Hash::make('default-password'),
            ]);
        }

        $email = $data['email'] ?? $user->email ?? null;

        if (! $email || User::query()->where('email', $email)->whereKeyNot($user->id)->exists()) {
            $email = 'member-' . ($member->id ?: time()) . '-' . substr($data['document_number'], -6) . '@ironbody.local';
        }

        $user->fill([
            'name' => $data['full_name'],
            'email' => $email,
            'document' => $data['document_number'],
            'phone' => $data['phone'] ?? $user->phone,
            'status' => $this->crmStatusFor($member->status ?: Member::STATUS_PENDING_REGISTRATION),
        ]);

        $user->save();

        return $user;
    }

    private function updateRegistrationStatus(Member $member, string $status): void
    {
        $member->forceFill(['status' => $status])->save();

        if ($member->user) {
            $member->user->forceFill([
                'status' => $this->crmStatusFor($status),
            ])->save();
        }
    }

    private function crmStatusFor(string $registrationStatus): string
    {
        return $registrationStatus === Member::STATUS_ACTIVE ? 'active' : 'pending';
    }

    private function storePrivateFile(UploadedFile $file, Member $member, string $folder): array
    {
        $path = $file->store("members/{$member->member_uuid}/{$folder}", 'local');

        return [
            'path' => $path,
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
        ];
    }

    private function deleteOldFiles(?string ...$paths): void
    {
        foreach ($paths as $path) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    private function memberResponse(Member $member, string $message, array $extra = []): array
    {
        return array_merge([
            'ok' => true,
            'member_id' => $member->id,
            'member_uuid' => $member->member_uuid,
            'user_id' => $member->user_id,
            'access_hash' => $member->access_hash,
            'message' => $message,
            'registration_status' => $member->status,
        ], $extra);
    }

    private function toBoolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function serverError(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => 'No fue posible procesar la solicitud.',
        ], 500);
    }

    private function featuresFor(?User $user): array
    {
        if (! $user) {
            return array_merge(array_map(fn () => false, Plan::defaultFeatures()), ['workouts' => true]);
        }

        $plan = $user->plan ? Plan::where('name', $user->plan)->first() : null;
        $expiresAt = $user->membershipEndDate
            ? Carbon::parse($user->membershipEndDate)->endOfDay()
            : null;
        $isExpired = $expiresAt && $expiresAt->isPast();

        return ($isExpired || ! $plan)
            ? array_merge(array_map(fn () => false, Plan::defaultFeatures()), ['workouts' => true])
            : $plan->resolvedFeatures();
    }
}

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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
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
        Log::info('member:register:start', ['has_document' => filled($request->input('document_number'))]);
        try {
            $validated = $request->validated();
            // Intención de biometría capturada en el registro (Apple: opcional).
            // Si el usuario eligió omitirla, no se requiere captura facial.
            $biometricStatus = $validated['biometric_status'] ?? null;
            unset($validated['biometric_status']);

            $response = DB::transaction(function () use ($validated, $biometricStatus): JsonResponse {
                // (1) ¿Ya hay un miembro con este documento?
                $member = Member::query()
                    ->where('document_number', $validated['document_number'])
                    ->first();

                // (2) Resolver/crear el usuario CRM. Si no hubo match por
                //     documento, el usuario podría existir ya (por email o
                //     documento) y TENER un miembro: en ese caso se reanuda ese
                //     miembro en vez de insertar otro (lo que violaba
                //     members_user_id_unique y devolvía 500).
                $user = $this->syncCrmUser($member ?? new Member($validated), $validated);

                if (! $member) {
                    $member = Member::query()->where('user_id', $user->id)->first();
                }

                // (3) Resumir un registro existente (idempotente) o rechazar
                //     claramente si la cuenta ya está activa.
                if ($member) {
                    if (! $member->isRegistrationResumable()) {
                        Log::info('member:register:duplicate-document', ['member_id' => $member->id]);

                        return $this->duplicateResponse($member);
                    }

                    Log::info('member:register:existing-member', [
                        'user_id' => $user->id,
                        'member_id' => $member->id,
                    ]);

                    return $this->resumeRegistration($member, $validated, $biometricStatus, $user);
                }

                // (4) Miembro nuevo.
                $member = new Member(array_merge($validated, [
                    'status' => Member::STATUS_PENDING_REGISTRATION,
                ]));
                if ($biometricStatus !== null) {
                    $member->biometric_status = $biometricStatus;
                }
                $member->user_id = $user->id;
                $member->save();

                // Aviso operativo al CRM de nuevo registro (ADITIVO; idempotente).
                app(\App\Services\NotificationService::class)->notifyNewMemberRegistered($member);

                Log::info('member:register:success', ['member_id' => $member->id]);

                return response()->json($this->memberResponse($member, 'Miembro creado correctamente.', [
                    'status' => Member::STATUS_PENDING_REGISTRATION,
                    'registration_status' => Member::STATUS_PENDING_REGISTRATION,
                ]), 201);
            });

            return $response;
        } catch (UniqueConstraintViolationException $e) {
            // Duplicado esperable (documento/correo/usuario ya registrados):
            // 409 claro, nunca 500. Se loguea solo el nombre de la restricción.
            Log::warning('member:register:duplicate', ['constraint' => $this->constraintNameFrom($e)]);

            return response()->json([
                'ok' => false,
                'message' => 'Ya existe una cuenta registrada con este documento o correo.',
                'status' => 'duplicate_account',
            ], 409);
        } catch (Throwable $e) {
            return $this->serverError($e, 'member:register');
        }
    }

    /**
     * Reanuda un registro pendiente/incompleto con el miembro existente, sin
     * insertar otro. Idempotente: actualiza datos permitidos y asegura el
     * estado biométrico si llega skipped/manual_required.
     */
    private function resumeRegistration(Member $member, array $validated, ?string $biometricStatus, User $user): JsonResponse
    {
        // No reasignar un documento que pertenece a OTRO miembro (evita 23505).
        $incomingDoc = $validated['document_number'] ?? null;
        if ($incomingDoc
            && $incomingDoc !== $member->document_number
            && Member::query()->where('document_number', $incomingDoc)->whereKeyNot($member->id)->exists()) {
            return $this->duplicateResponse($member);
        }

        $member->fill($validated);
        if ($biometricStatus !== null && $member->biometric_status !== Member::BIOMETRIC_REGISTERED) {
            $member->biometric_status = $biometricStatus;
        }
        if ($member->status === 'created') {
            $member->status = Member::STATUS_PENDING_REGISTRATION;
        }
        $member->user_id = $user->id;
        $member->save();

        Log::info('member:register:success', ['member_id' => $member->id, 'resumed' => true]);

        return response()->json($this->memberResponse($member->fresh(), 'Registro reanudado con el miembro existente. Continua con este member_id.', [
            'status' => 'resumed',
            'registration_status' => $member->status,
        ]));
    }

    /** Respuesta 409 para cuenta ya registrada (no resumible). */
    private function duplicateResponse(Member $member): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => 'Ya existe una cuenta registrada con este documento o correo.',
            'status' => 'duplicate_document',
            'member_id' => $member->id,
            'member_uuid' => $member->member_uuid,
            'user_id' => $member->user_id,
            'registration_status' => $member->status,
        ], 409);
    }

    /** Nombre de la restricción única violada (para log seguro, sin datos). */
    private function constraintNameFrom(UniqueConstraintViolationException $e): string
    {
        if (preg_match('/constraint "([^"]+)"/', $e->getMessage(), $m)) {
            return $m[1];
        }

        return 'unique';
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
        Log::info('biometric:skip:start', ['member_id' => $member->id]);
        try {
            if ($member->biometric_status !== Member::BIOMETRIC_REGISTERED) {
                $member->biometric_status = Member::BIOMETRIC_SKIPPED;
                $member->save();
            }
            $this->updateRegistrationStatus($member, Member::STATUS_ACTIVE);
            Log::info('biometric:skip:success', ['member_id' => $member->id]);

            return response()->json($this->memberResponse($member->fresh(), 'Biometria pendiente.'));
        } catch (Throwable $e) {
            return $this->serverError($e, 'biometric:skip');
        }
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

    /**
     * Respuesta 500 genérica para el cliente, pero registra la EXCEPCIÓN REAL
     * en logs (tipo/mensaje/código + ubicación) para diagnóstico. Nunca expone
     * el detalle al cliente ni loguea datos sensibles (firma/tokens/documentos).
     */
    private function serverError(?Throwable $e = null, string $context = 'member:register'): JsonResponse
    {
        if ($e !== null) {
            Log::error("{$context}:error", [
                'type'    => $e::class,
                'message' => $e->getMessage(),
                'code'    => $e->getCode(),
                'file'    => basename($e->getFile()).':'.$e->getLine(),
            ]);
        }

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

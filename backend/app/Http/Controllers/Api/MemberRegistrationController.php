<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginMemberRequest;
use App\Http\Requests\RegisterMemberRequest;
use App\Http\Requests\StoreMemberBiometricRequest;
use App\Http\Requests\StoreMemberIdentityRequest;
use App\Http\Requests\StoreMemberLegalConsentRequest;
use App\Http\Requests\StoreMemberSignatureRequest;
use App\Jobs\Marketing\ProposePaymentClaim;
use App\Models\Attendance;
use App\Models\EmployeeAccess;
use App\Models\Member;
use App\Models\MemberBiometric;
use App\Models\MembershipAdjustment;
use App\Models\Plan;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\Network\ClientNetwork;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class MemberRegistrationController extends Controller
{
    /**
     * Ventana de los pasos por {member}: la abre cada register que termina en
     * alta nueva o reanudada, y queda atada a la RED desde la que se hizo
     * register (ver ClientNetwork). La APK encadena identidad, contrato y
     * rostro justo después de register, todo tras el mismo «Finalizar»: le
     * sobran 15 minutos. Si la ventana caduca a mitad, o la persona cambia de
     * red (wifi → datos), su reintento vuelve a empezar por register y la
     * reabre en la red nueva. Con el id secuencial y el token de la APK, desde
     * otra red o pasados 15 minutos, no se alcanza a ningún alta.
     */
    private const REGISTRATION_WINDOW_MINUTES = 15;

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
                    'id' => $member->id,
                    'member_uuid' => $member->member_uuid,
                    'full_name' => $member->full_name,
                    'email' => $member->email ?: $user?->email,
                    'document_number' => $member->document_number,
                    'phone' => $member->phone ?: $user?->phone,
                    'goal' => $member->goal,
                    'plan_name' => $user?->plan,
                    'membership_expiry' => $user?->membershipEndDate,
                    'access_hash' => $member->access_hash,
                    'status' => $member->status,
                    'features' => $this->featuresFor($user),
                ],
            ],
        ]);
    }

    public function incomplete(): JsonResponse
    {
        $members = Member::query()
            ->whereIn('status', Member::REGISTRATION_IN_PROGRESS_STATUSES)
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

            // Quién se acaba de dar de alta (o de reanudar). Se anota dentro de
            // la transacción y se usa DESPUÉS del commit: ver
            // proposeApprovedPaymentClaim().
            $claimant = null;

            $response = DB::transaction(function () use ($validated, $biometricStatus, &$claimant): JsonResponse {
                $document = $validated['document_number'];

                // (1) ¿Ya hay un miembro con este documento? Su fila queda
                //     bloqueada hasta el commit: comprobar que el alta se puede
                //     reanudar y escribirla no pueden separarse (otra petición
                //     podría terminarla o activarla entre medias).
                $member = Member::query()
                    ->where('document_number', $document)
                    ->lockForUpdate()
                    ->first();

                // (2) Usuario del CRM al que se enlazaría. SOLO LECTURA: hasta
                //     descartar todos los rechazos de (3) no se escribe nada.
                //     Antes se reescribía aquí mismo y el 409 posterior salía
                //     del closure sin excepción, así que la transacción
                //     CONFIRMABA el nombre, correo y teléfono del atacante sobre
                //     el usuario de un socio activo.
                $user = $member?->user ?? $this->findLinkableCrmUser($validated);

                //     Si ese usuario ya tiene ficha de socio, se reanuda ESA
                //     ficha en vez de insertar otra (lo que violaba
                //     members_user_id_unique y devolvía 500)...
                $userMember = $user
                    ? Member::query()->where('user_id', $user->id)->lockForUpdate()->first()
                    : null;

                if ($member === null) {
                    $member = $userMember;
                } elseif ($userMember !== null && $userMember->id !== $member->id) {
                    // ...pero si el documento es de una ficha y el usuario del
                    // CRM con ese documento es de OTRA, no hay a quién enlazar.
                    return $this->rejectRegistration('crm_user_owned_by_other_member', $document, $member);
                }

                // (3) Rechazos. TODOS antes de cualquier escritura: un 409 deja
                //     la base exactamente como estaba.
                //
                //     El teléfono: el token de la APK es público, así que el
                //     documento —o el correo— es lo único que llega, y no es
                //     secreto. Para entregar el access_hash de una ficha que ya
                //     existe, o enlazar un usuario del CRM con historia, se
                //     exige además el teléfono que ya tenemos guardado. NO es
                //     prueba de posesión (quien sepa la cédula y el celular de
                //     alguien pasa): sube el coste del ataque sin cambiar la
                //     APK. El cierre de verdad es un OTP en el registro.
                if ($member) {
                    if (! $member->isRegistrationResumable()) {
                        return $this->rejectRegistration('not_resumable', $document, $member);
                    }

                    // No reasignar un documento que pertenece a OTRO miembro
                    // (evita 23505). Solo puede pasar cuando la ficha llegó por
                    // el usuario del CRM, con un documento distinto.
                    if ($document !== $member->document_number
                        && Member::query()->where('document_number', $document)->whereKeyNot($member->id)->exists()) {
                        return $this->rejectRegistration('document_owned_by_other_member', $document, $member);
                    }

                    // Reanudar entrega el access_hash y reescribe la ficha. La
                    // APK reintenta con el mismo formulario —el mismo número—;
                    // quien cambió de número entra por el login con OTP, que
                    // admite altas incompletas. Sin número guardado no hay con
                    // qué comparar: tampoco se entrega.
                    if (! $this->phoneMatches($validated['phone'] ?? null, $member->phone, $user?->phone)) {
                        return $this->rejectRegistration('resume_phone_mismatch', $document, $member);
                    }
                } elseif ($user && ($history = $this->crmUserHistory($user, now()))
                    && ! $this->phoneMatches($validated['phone'] ?? null, $user->phone)) {
                    // Usuario del CRM SIN ficha de socio pero con historia: el
                    // cliente del mostrador, con su plan y sus pagos. Enlazarlo
                    // le daba al atacante ese plan, el OTP y el torniquete.
                    return $this->rejectRegistration('link_phone_mismatch:'.$history, $document);
                }

                // (4) Escrituras.
                $user = $this->syncCrmUser($user, $member ?? new Member($validated), $validated);

                if ($member) {
                    Log::info('member:register:existing-member', [
                        'user_id' => $user->id,
                        'member_id' => $member->id,
                    ]);

                    // Reanudar es un alta que continúa: también propone.
                    $claimant = $member;

                    return $this->resumeRegistration($member, $validated, $biometricStatus, $user);
                }

                // (5) Miembro nuevo.
                $member = new Member(array_merge($validated, [
                    'status' => Member::STATUS_PENDING_REGISTRATION,
                ]));

                // `is_minor` lo decide SIEMPRE el servidor a partir de la fecha
                // de nacimiento; la app ya no puede declararlo (se retiró de
                // RegisterMemberRequest). Si la fecha no es fiable —el OCR no
                // la leyó— NO se afirma nada: la columna conserva su valor por
                // defecto y la verificación de identidad queda en revisión
                // manual, que es el control que ya existía para ese caso.
                $registrationAge = Member::ageFromBirthDate($validated['birth_date'] ?? null);
                if ($registrationAge !== null) {
                    $member->is_minor = $registrationAge < Member::legalAdultAge();
                }

                if ($biometricStatus !== null) {
                    $member->biometric_status = $biometricStatus;
                }
                $member->user_id = $user->id;
                $member->save();
                $claimant = $member;

                // Aviso operativo al CRM de nuevo registro (ADITIVO; idempotente).
                app(NotificationService::class)->notifyNewMemberRegistered($member);

                Log::info('member:register:success', ['member_id' => $member->id]);

                return response()->json($this->memberResponse($member, 'Miembro creado correctamente.', [
                    'status' => Member::STATUS_PENDING_REGISTRATION,
                    'registration_status' => Member::STATUS_PENDING_REGISTRATION,
                ]), 201);
            });

            // Ya hay commit: solo ahora puede salir nada hacia fuera.
            if ($claimant !== null) {
                $this->openRegistrationWindow($claimant, $biometricStatus);
            }
            $this->proposeApprovedPaymentClaim($claimant);

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
     * Si alguien pagó por WhatsApp antes de tener cuenta, su pago aprobado se
     * PROPONE al equipo cuando aparece un registro con ese mismo número. No se
     * enlaza ni se activa membresía: este endpoint no verifica el teléfono —no
     * hay OTP, el token es compartido y `members.phone` no es único—, así que
     * el número es una pista, no una prueba de quién pagó. Decide una persona.
     *
     * Se llama FUERA de la transacción a propósito: sus efectos salen del
     * proceso (aviso por WhatsApp, notificaciones) y las colas de este proyecto
     * despachan sin esperar al commit (`after_commit => false`), así que dentro
     * de la transacción se anunciaba un alta que aún podía deshacerse.
     *
     * Nunca lanza: proponer es accesorio; registrarse no puede fallar por esto.
     */
    private function proposeApprovedPaymentClaim(?Member $member): void
    {
        if ($member === null) {
            return;
        }

        try {
            // En cola: la propuesta acaba en una salida por WhatsApp hacia Meta
            // y no puede alargar la respuesta que espera la app.
            ProposePaymentClaim::dispatch((int) $member->id);
        } catch (Throwable $e) {
            Log::warning('member:register:payment-claim-proposal-failed', [
                'member_id' => $member->id,
                'exception' => class_basename($e),
            ]);
        }
    }

    /**
     * Reanuda un registro pendiente/incompleto con el miembro existente, sin
     * insertar otro. Idempotente: actualiza datos permitidos y asegura el
     * estado biométrico si llega skipped/manual_required.
     */
    private function resumeRegistration(Member $member, array $validated, ?string $biometricStatus, User $user): JsonResponse
    {
        // El choque de documento con OTRO miembro ya se descartó en register(),
        // antes de escribir nada.
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

    /**
     * Respuesta 409 de register cuando no se crea ni se reanuda nada. Es la
     * MISMA en todos los casos (ficha activa, alta ajena, teléfono que no
     * coincide, cliente del mostrador): no dice qué hay detrás.
     *
     * Ya no lleva member_id, member_uuid, user_id ni registration_status: con
     * el token público eran un oráculo cédula → socio (y su estado). La APK
     * no los lee: ante un error solo muestra `message` (ApiClient._decode).
     */
    private function duplicateResponse(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => 'Ya existe una cuenta registrada con este documento o correo.',
            'status' => 'duplicate_document',
        ], 409);
    }

    /** Rechazo de register: deja rastro y responde el 409 de siempre. */
    private function rejectRegistration(string $reason, string $document, ?Member $member = null): JsonResponse
    {
        $this->logRejected('register', $reason, $member, $document);

        return $this->duplicateResponse();
    }

    /**
     * ¿El teléfono del registro es alguno de los que ya tenemos? Comparados
     * normalizados (el CRM guarda «+57 300 …» y la app manda 10 dígitos). Sin
     * ningún teléfono guardado, no hay coincidencia posible.
     */
    private function phoneMatches(?string $given, ?string ...$stored): bool
    {
        $given = Member::normalizePhone($given);
        if ($given === null) {
            return false;
        }

        foreach ($stored as $phone) {
            if (Member::normalizePhone($phone) === $given) {
                return true;
            }
        }

        return false;
    }

    /**
     * Respuesta ÚNICA de las rutas por {member} cuando la ficha no es un alta
     * en curso de quien llama: activa, suspendida, eliminada, ya en uso, fuera
     * de su ventana o con historia. Es la misma en todos los casos para no
     * revelar cuál aplica. 409 y no 401: la APK cierra la sesión ante un 401;
     * ante esto muestra el mensaje y sigue.
     */
    private function registrationClosedResponse(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => 'registration_closed',
            'message' => 'Este registro ya no se puede modificar desde la app. Si ya tienes cuenta, inicia sesión.',
        ], 409);
    }

    /**
     * Guarda de las rutas públicas por {member}. Null = adelante.
     *
     * La APK publicada sube identidad, firma y rostro por `members/{id}/…` sin
     * ninguna credencial del socio: solo el token compartido de la APK. Con
     * eso y un id secuencial se podía escribir —y activar— cualquier ficha.
     * Ahora solo se acepta un alta en curso (ver Member::isRegistrationResumable)
     * que haya pasado por register hace menos de REGISTRATION_WINDOW_MINUTES,
     * desde la misma red.
     *
     * Y el rostro, solo si ese register no declaró que lo omitía: la APK, con
     * el rostro omitido, nunca sube uno. Sin esto, cualquiera en el mismo wifi
     * (el del gimnasio) ponía su cara en el alta recién hecha de otro y la
     * activaba.
     */
    private function rejectUnlessRegistrationEditable(Member $member, string $action): ?JsonResponse
    {
        $window = $this->registrationWindow($member);

        $reason = match (true) {
            ! $member->isRegistrationResumable() => 'not_resumable',
            $window === null => 'no_registration_window',
            ! hash_equals((string) $window['network'], (string) $this->clientNetworkFingerprint()) => 'window_network_mismatch',
            $action === 'biometric' && $window['biometric'] === Member::BIOMETRIC_SKIPPED => 'biometric_declared_skipped',
            default => null,
        };

        if ($reason === null) {
            return null;
        }

        $this->logRejected($action, $reason, $member);

        return $this->registrationClosedResponse();
    }

    /**
     * Rastro de cada rechazo de las rutas públicas de registro, para detectar
     * a quien usa el token de la APK contra fichas ajenas. Sin datos personales
     * en claro: documento e IP van como huella HMAC (con APP_KEY), que sirve
     * para ver que alguien insiste en uno o recorre muchos y no para
     * recuperarlos.
     */
    private function logRejected(string $action, string $reason, ?Member $member = null, ?string $document = null): void
    {
        Log::warning('member:registration:rejected', [
            'action' => $action,
            'reason' => $reason,
            'member_id' => $member?->id,
            'member_status' => $member?->status,
            'document_hash' => $document !== null ? $this->fingerprint($document) : null,
            'ip_hash' => $this->fingerprint((string) request()->ip()),
        ]);
    }

    private function fingerprint(string $value): ?string
    {
        return $value === ''
            ? null
            : substr(hash_hmac('sha256', $value, (string) config('app.key')), 0, 16);
    }

    /**
     * Huella de la red de quien llama (IPv4, o el /64 de IPv6). Supone que
     * request()->ip() es la IP del cliente: ver ClientNetwork.
     */
    private function clientNetworkFingerprint(): ?string
    {
        return $this->fingerprint(ClientNetwork::of(request()->ip()));
    }

    /**
     * «v2»: las ventanas abiertas antes de este cambio guardaban solo el uuid
     * y no saben de qué red venían. No se leen: quien estuviera a mitad de un
     * alta al desplegar recibe un registration_closed y su reintento, que
     * empieza por register, abre una nueva.
     */
    private function registrationWindowKey(Member $member): string
    {
        return 'member-registration:window:v2:'.$member->id;
    }

    private function openRegistrationWindow(Member $member, ?string $biometricStatus): void
    {
        // El uuid, y no un `true`: si un id se reutilizara (SQLite lo hace al
        // borrar la última fila), la ventana no vale para otra ficha.
        Cache::put(
            $this->registrationWindowKey($member),
            [
                'uuid' => $member->member_uuid,
                'network' => $this->clientNetworkFingerprint(),
                // Lo que declaró ESTE register, no lo que quedó en la ficha:
                // quien omitió el rostro y en el reintento se lo toma, puede.
                'biometric' => $biometricStatus,
            ],
            now()->addMinutes(self::REGISTRATION_WINDOW_MINUTES),
        );
    }

    /** @return array{uuid: string, network: string|null, biometric: string|null}|null */
    private function registrationWindow(Member $member): ?array
    {
        $window = Cache::get($this->registrationWindowKey($member));

        return is_array($window) && ($window['uuid'] ?? null) === $member->member_uuid
            ? $window + ['network' => null, 'biometric' => null]
            : null;
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
        if ($rejected = $this->rejectUnlessRegistrationEditable($member, 'identity')) {
            return $rejected;
        }

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
        if ($rejected = $this->rejectUnlessRegistrationEditable($member, 'legal-consent')) {
            return $rejected;
        }

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
        if ($rejected = $this->rejectUnlessRegistrationEditable($member, 'signature')) {
            return $rejected;
        }

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
        // También la usa «Registrar rostro» del perfil de la APK, sin credencial
        // del socio: con solo el token de la APK se podía poner un rostro
        // cualquiera —el del torniquete— en la ficha de un socio activo.
        if ($rejected = $this->rejectUnlessRegistrationEditable($member, 'biometric')) {
            return $rejected;
        }

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

            // Aviso real-time: CRM (re-index del terminal facial) + miembro (app).
            app(NotificationService::class)->notifyFaceEnrolled($member->fresh());

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
        // Marca la ficha como ACTIVA: sin esta guarda revivía cuentas
        // eliminadas o suspendidas.
        if ($rejected = $this->rejectUnlessRegistrationEditable($member, 'biometric-skip')) {
            return $rejected;
        }

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

    /**
     * DELETE admin/members/{member} — CRM («Registros incompletos»), con sesión
     * admin. Borra la ficha y su usuario como siempre.
     */
    public function destroy(Member $member): JsonResponse
    {
        return $this->deleteMemberAndUser($member);
    }

    /**
     * DELETE members/{member} — ruta PÚBLICA (token de la APK).
     *
     * La APK nunca la ha llamado (no hay ninguna llamada a DELETE members/{id}
     * en su código). Con el token extraído, primero borraba cualquier ficha;
     * después, cualquier alta en curso de la ventana —la de otra persona en
     * el mismo wifi—. Ya no borra nada: responde siempre el 409 de las rutas
     * cerradas y deja rastro. Descartar un alta es del CRM
     * (DELETE admin/members/{member}, con sesión).
     */
    public function discardRegistration(Member $member): JsonResponse
    {
        $this->logRejected('discard', 'public_discard_disabled', $member);

        return $this->registrationClosedResponse();
    }

    /**
     * ¿El usuario del CRM tiene historia que perder si se lo queda otro?
     * Rastro de dinero o de uso, plan o vigencia, importado del sistema
     * anterior, o simplemente que existía antes de `$reference` (un cliente
     * del mostrador; sin fecha de alta se supone que también). Null = es un
     * usuario recién creado y vacío.
     */
    private function crmUserHistory(User $user, Carbon $reference): ?string
    {
        if ($user->payments()->exists()
            || Attendance::query()->where('user_id', $user->id)->exists()
            || MembershipAdjustment::query()->where('user_id', $user->id)->exists()
            || EmployeeAccess::query()->where('user_id', $user->id)->exists()) {
            return 'history_crm_user_activity';
        }

        $preexisting = $user->created_at === null
            || $user->created_at->lt($reference->copy()->subMinute());
        if ($preexisting || $user->imported_at !== null || filled($user->plan) || $user->membership_end_date !== null) {
            return 'history_crm_user_preexisting';
        }

        return null;
    }

    private function deleteMemberAndUser(Member $member): JsonResponse
    {
        try {
            // Las rutas de los ficheros se leen ANTES: sus filas caen en cascada.
            $member->loadMissing(['identityDocument', 'signature', 'biometric']);

            DB::transaction(function () use ($member): void {
                $user = $member->user;
                $member->delete();
                $user?->delete();
            });
        } catch (Throwable $e) {
            return $this->serverError($e, 'member:destroy');
        }

        // Solo con el commit hecho se tocan los ficheros. Antes se borraban
        // primero y un DELETE fallido (p. ej. una cuenta por cobrar, RESTRICT)
        // dejaba la ficha viva sin su documento, su firma ni su rostro.
        try {
            $member->deleteStoredFiles();
        } catch (Throwable $e) {
            // La ficha ya no existe: un fichero que no se pudo borrar no
            // convierte el borrado en un 500, pero queda rastro para limpiarlo.
            Log::warning('member:destroy:files-not-deleted', [
                'member_id' => $member->id,
                'exception' => class_basename($e),
            ]);
        }

        return response()->json(null, 204);
    }

    /**
     * Usuario del CRM al que puede enlazarse un alta. SOLO LECTURA.
     *
     *   - Por DOCUMENTO: la llave con la que el socio entra; así se enlaza el
     *     cliente del mostrador que se da de alta en la app.
     *   - Por CORREO, solo si ese usuario no tiene ficha de socio y su documento
     *     está vacío o es este mismo. Antes valía cualquiera, y el alta le
     *     reescribía documento y teléfono: con el correo de otra persona se
     *     le cambiaba el documento a su usuario y su OTP salía al atacante.
     *     Si no cumple, no se reutiliza: el alta crea su propio usuario.
     */
    private function findLinkableCrmUser(array $data): ?User
    {
        $byDocument = User::query()->where('document', $data['document_number'])->first();
        if ($byDocument) {
            return $byDocument;
        }

        if (blank($data['email'] ?? null)) {
            return null;
        }

        $byEmail = User::query()->where('email', $data['email'])->first();
        if ($byEmail === null) {
            return null;
        }

        $documentMatches = blank($byEmail->document)
            || Member::normalizeDocumentNumber($byEmail->document) === $data['document_number'];
        $hasMember = Member::query()->where('user_id', $byEmail->id)->exists();

        if ($documentMatches && ! $hasMember) {
            return $byEmail;
        }

        $this->logRejected('register', 'email_owned_by_other_user', null, $data['document_number']);

        return null;
    }

    /**
     * Crea el usuario del CRM (null = uno nuevo) o completa el ya resuelto.
     *
     * Un usuario que YA existe solo se completa: lo que tenga valor no se
     * pisa y su estado no se toca. Antes register reescribía nombre, correo,
     * teléfono, sexo y fecha del cliente del mostrador y le ponía `pending`
     * (lo sacaba de los activos del CRM) con solo conocer su cédula. Lo que
     * la persona escribe en la app queda en su ficha de socio (`members`).
     */
    private function syncCrmUser(?User $user, Member $member, array $data): User
    {
        if ($user === null) {
            $user = new User([
                'password' => Hash::make('default-password'),
            ]);

            $email = $data['email'] ?? null;
            if (! $email || User::query()->where('email', $email)->exists()) {
                $email = 'member-'.($member->id ?: time()).'-'.substr($data['document_number'], -6).'@ironbody.local';
            }

            $user->fill([
                'name' => $data['full_name'],
                'email' => $email,
                'document' => $data['document_number'],
                'phone' => $data['phone'] ?? null,
                // El CRM lee género y fecha de nacimiento del User, no del
                // Member (ver UserController::serialize): sin replicarlos aquí
                // la ficha del CRM aparecía sin sexo ni fecha.
                'gender' => $data['gender'] ?? null,
                'birth_date' => $data['birth_date'] ?? null,
                'status' => $this->crmStatusFor($member->status ?: Member::STATUS_PENDING_REGISTRATION),
            ]);
            $user->save();

            return $user;
        }

        $missing = array_filter([
            'name' => $data['full_name'] ?? null,
            'document' => $data['document_number'] ?? null,
            'phone' => $data['phone'] ?? null,
            'gender' => $data['gender'] ?? null,
            'birth_date' => $data['birth_date'] ?? null,
            'status' => $this->crmStatusFor($member->status ?: Member::STATUS_PENDING_REGISTRATION),
        ], fn (mixed $value, string $field): bool => filled($value) && blank($user->getAttribute($field)), ARRAY_FILTER_USE_BOTH);

        if ($missing !== []) {
            $user->fill($missing)->save();
        }

        return $user;
    }

    private function updateRegistrationStatus(Member $member, string $status): void
    {
        $member->forceFill(['status' => $status])->save();

        // El estado del usuario del CRM solo sube con el alta (pending →
        // active); nunca baja a `pending` a un usuario que ya era otra cosa:
        // el cliente del mostrador enlazado sigue activo mientras completa
        // los pasos de la app.
        $user = $member->user;
        $crmStatus = $this->crmStatusFor($status);
        if ($user && ($crmStatus === 'active' || blank($user->status) || $user->status === 'pending')) {
            $user->forceFill(['status' => $crmStatus])->save();
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
                'type' => $e::class,
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => basename($e->getFile()).':'.$e->getLine(),
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

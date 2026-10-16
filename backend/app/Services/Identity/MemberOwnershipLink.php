<?php

namespace App\Services\Identity;

use App\Exceptions\OtpException;
use App\Exceptions\WorkforceException;
use App\Models\AuditLog;
use App\Models\Identity;
use App\Models\Member;
use App\Models\MemberAuthChallenge;
use App\Models\MemberSecurityEvent;
use App\Models\Trainer;
use App\Services\Audit\AuditTrail;
use App\Services\Otp\OtpPolicy;
use App\Services\OtpService;
use App\Services\Workforce\WorkforceEvents;
use App\Support\DocumentNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * El vínculo, PROBADO, entre una persona del personal y su ficha de socio.
 *
 * Para qué sirve: ver y administrar desde la ficha laboral el acceso gratuito,
 * que cuelga del usuario del socio (Identity → Member → User). El perfil
 * laboral no lo necesita: cuelga de la identidad del documento.
 *
 * Qué prueba la titularidad: un código por SMS al teléfono REGISTRADO en la
 * ficha de socio, que la persona dicta a quien la atiende. Conocer el
 * documento no prueba nada —lo tiene recepción, la factura y cualquiera que
 * haya visto la cédula—, así que el destino es siempre el teléfono PROPIO de la
 * ficha de socio (`members.phone`): nunca el del perfil laboral, ni uno que
 * llegue en la petición, ni el del usuario del CRM. Este último lo reescribe el
 * registro público de la app sin dejar rastro (fallo previo, fuera de este
 * cambio), y {@see OtpService::resolvePhone()} lo usaría cuando la ficha de
 * socio no tiene teléfono; por eso sin `members.phone` no se verifica. La escritura es {@see IdentityLinkService::attachMember()}
 * con la propiedad verificada, y es lo ÚNICO que se escribe: ni `is_staff`, ni
 * el usuario, ni pagos, ni el acceso gratuito, ni las comisiones.
 *
 * Esta fase deja fuera a quien tiene perfil de entrenador: comisiones y perfil
 * fiscal ya leen su identidad, y colgarle una ficha de socio cambiaría lo que
 * esas lecturas encuentran (decisión del 2026-10-15).
 *
 * Un teléfono recién cambiado NO prueba nada: quien puede editar la ficha del
 * socio (`members.edit`) podría poner el suyo y recibir el código. Por eso,
 * durante {@see PHONE_CHANGE_QUARANTINE_DAYS} días después de cualquier cambio
 * de teléfono registrado, no se verifica (revisión de seguridad 2026-10-15, M1).
 *
 * Y solo socios con el registro TERMINADO (`status` activo): un registro que
 * se puede reanudar lo reescribe —teléfono incluido— cualquiera que tenga el
 * token público de la app (revisión 2026-10-15).
 */
class MemberOwnershipLink
{
    public const LINKED = 'linked';

    public const UNLINKED = 'unlinked';

    public const CONFLICT = 'conflict';

    public const NONE = 'none';

    public const BLOCKED_TRAINER = 'trainer_profile';

    public const BLOCKED_NO_PHONE = 'no_phone';

    public const BLOCKED_LINKED_ELSEWHERE = 'linked_elsewhere';

    public const BLOCKED_IDENTITY_HAS_MEMBER = 'identity_has_member';

    public const BLOCKED_PHONE_CHANGED = 'phone_recently_changed';

    public const BLOCKED_REGISTRATION_INCOMPLETE = 'registration_incomplete';

    public const BLOCKED_DISABLED = 'link_disabled';

    /** Días en que un teléfono recién cambiado no sirve para probar titularidad. */
    public const PHONE_CHANGE_QUARANTINE_DAYS = 7;

    public const RESULT_CHALLENGE = 'challenge';

    public const RESULT_LINKED = 'linked';

    public const RESULT_ALREADY_LINKED = 'already_linked';

    public function __construct(
        private readonly IdentityLinkService $identities,
        private readonly OtpService $otp,
        private readonly WorkforceEvents $events,
        private readonly AuditTrail $auditTrail,
        private readonly OtpPolicy $policy,
    ) {}

    /**
     * En qué punto está el vínculo de esta persona con una ficha de socio. Solo
     * lectura. `reason` explica por qué no se puede verificar, si no se puede.
     *
     * @return array{link:string, reason:?string, member:?Member}
     */
    public function situation(Identity $identity): array
    {
        $linked = Member::query()->where('identity_id', $identity->getKey())->orderBy('id')->first();
        if ($linked !== null) {
            return ['link' => self::LINKED, 'reason' => null, 'member' => $linked];
        }

        $candidate = $this->identities->memberCandidate($identity);
        if ($candidate === null) {
            return ['link' => self::NONE, 'reason' => null, 'member' => null];
        }
        if ($candidate->identity_id !== null) {
            return ['link' => self::CONFLICT, 'reason' => self::BLOCKED_LINKED_ELSEWHERE, 'member' => $candidate];
        }

        $reason = match (true) {
            ! $this->enabled() => self::BLOCKED_DISABLED,
            $this->hasTrainerProfile($identity) => self::BLOCKED_TRAINER,
            $candidate->status !== Member::STATUS_ACTIVE => self::BLOCKED_REGISTRATION_INCOMPLETE,
            $this->verifiablePhone($candidate) === null => self::BLOCKED_NO_PHONE,
            $this->phoneRecentlyChanged($candidate) => self::BLOCKED_PHONE_CHANGED,
            default => null,
        };

        return ['link' => self::UNLINKED, 'reason' => $reason, 'member' => $candidate];
    }

    /**
     * Paso 1: manda el código al teléfono registrado del socio.
     *
     * @return array{result:string, challenge:?MemberAuthChallenge, code:string, sent:bool, reused:bool}
     *
     * @throws WorkforceException
     */
    public function start(Identity $identity, int $memberId, Request $request): array
    {
        $this->assertEnabled();
        $member = $this->eligibleMember($identity, $memberId);
        if ($this->isLinkedTo($member, $identity)) {
            return ['result' => self::RESULT_ALREADY_LINKED, 'challenge' => null, 'code' => '', 'sent' => false, 'reused' => false];
        }
        $this->assertFree($identity, $member);
        // ANTES de enviar: registro terminado, teléfono propio de la ficha y
        // que no lo acabe de poner nadie.
        $this->assertVerifiable($member);

        try {
            // Sin `destinationOverride`: el código va al teléfono de la ficha
            // de socio y a ningún otro.
            $sent = $this->otp->startChallenge($member, $this->context($request), MemberAuthChallenge::PURPOSE_IDENTITY_LINK);
        } catch (OtpException $e) {
            throw $this->startRejected($e);
        }

        // Defensa en profundidad: hoy `resolvePhone` prefiere `members.phone`,
        // así que el destino ES ese. Si un cambio futuro lo desviara (al del
        // usuario del CRM, p. ej.), el reto queda inservible y no se devuelve.
        if (! $this->sameNumber($sent['challenge']->destination, $this->verifiablePhone($member))) {
            $sent['challenge']->forceFill(['status' => MemberAuthChallenge::STATUS_BLOCKED])->save();
            Log::error('workforce.member_link.destination_mismatch', [
                'member_id' => $member->id,
                'challenge' => $sent['challenge']->uuid,
                'destination_hash' => $this->policy->phoneHash($sent['challenge']->destination),
                'member_phone_hash' => $this->policy->phoneHash($this->verifiablePhone($member)),
            ]);
            throw WorkforceException::memberLinkDestinationMismatch();
        }

        // Cada envío cuesta dinero y le escribe a un socio: queda quién lo pidió.
        $this->auditTrail->record($request, [
            'action' => 'create',
            'module' => 'workforce',
            'entity' => 'member_link',
            'entity_id' => $identity->uuid,
            'target_name' => $member->full_name,
            'summary' => 'Pidió un código SMS para vincular la ficha de socio de una persona del personal',
            'metadata' => [
                'member_id' => $member->id,
                'challenge' => $sent['challenge']->uuid,
                'reused' => $sent['reused'],
            ] + $this->destination($sent['challenge']),
        ]);

        return [
            'result' => self::RESULT_CHALLENGE,
            'challenge' => $sent['challenge'],
            'code' => $sent['code'],
            'sent' => $sent['sent'],
            'reused' => $sent['reused'],
        ];
    }

    /**
     * Paso 2: con el código correcto, vincula. Devuelve `linked` o
     * `already_linked` (repetir la confirmación no hace nada).
     *
     * @throws WorkforceException
     */
    public function confirm(Identity $identity, int $memberId, string $challengeId, string $code, Request $request): string
    {
        $this->assertEnabled();
        $member = $this->eligibleMember($identity, $memberId);
        if ($this->isLinkedTo($member, $identity)) {
            return self::RESULT_ALREADY_LINKED;
        }
        // Antes de gastar el código: si el vínculo ya es imposible, que no se
        // queme un código ni un intento en él.
        $this->assertFree($identity, $member);
        $this->assertVerifiable($member);

        // FUERA de la transacción, a propósito. Un código equivocado sube el
        // contador de intentos del reto (y lo bloquea al quinto); dentro de una
        // transacción, la excepción desharía ese incremento y el reto
        // admitiría intentos sin fin. Mismo motivo que en AuthorizationCodeService.
        try {
            $challenge = $this->otp->verifyAction(
                $member,
                MemberAuthChallenge::PURPOSE_IDENTITY_LINK,
                $challengeId,
                $code,
                $this->context($request),
            );
        } catch (OtpException $e) {
            throw $this->confirmRejected($e);
        }

        return DB::transaction(function () use ($identity, $member, $challenge, $request): string {
            // Lo comprobado arriba pudo cambiar mientras la persona dictaba el
            // código. Se relee BLOQUEADO y se decide sobre eso. Siempre identidad
            // y después socio, el mismo orden en cada llamada: dos vínculos a la
            // vez se esperan en vez de cruzarse. `members.identity_id` solo tiene
            // índice, no unicidad: esta comprobación con bloqueo es la garantía.
            $person = Identity::query()->whereKey($identity->getKey())->lockForUpdate()->firstOrFail();
            $socio = Member::query()->whereKey($member->getKey())->lockForUpdate()->firstOrFail();

            if (! $this->sameDocument($socio, $person)) {
                throw WorkforceException::memberNotFound();
            }
            if ($this->hasTrainerProfile($person)) {
                throw WorkforceException::memberLinkTrainerUnsupported();
            }
            if ($this->isLinkedTo($socio, $person)) {
                return self::RESULT_ALREADY_LINKED;
            }
            $this->assertFree($person, $socio);
            // El registro o el teléfono pudieron cambiar mientras la persona
            // dictaba el código.
            $this->assertVerifiable($socio);
            // Y el código tuvo que ir al teléfono que la ficha tiene AHORA.
            if (! $this->sameNumber($challenge->destination, $this->verifiablePhone($socio))) {
                throw WorkforceException::memberLinkDestinationMismatch();
            }

            $this->identities->attachMember($socio, $person, ownershipVerified: true);

            $this->events->publish(WorkforceEvents::STAFF_UPDATED, 'updated', (string) $person->uuid, WorkforceEvents::SOURCE_CRM);
            $this->auditTrail->record($request, [
                'action' => 'update',
                'module' => 'workforce',
                'entity' => 'member_link',
                'entity_id' => $person->uuid,
                'target_name' => $socio->full_name,
                'summary' => 'Vinculó la ficha de socio de una persona del personal (verificada por SMS)',
                // Sin el código: la traza dice qué reto se usó, no lo que decía.
                'metadata' => [
                    'member_id' => $socio->id,
                    'challenge' => $challenge->uuid,
                    'verified_by' => 'sms_otp',
                ] + $this->destination($challenge),
            ]);

            return self::RESULT_LINKED;
        });
    }

    /** «•••• 1234»: lo justo para que quien atiende confirme el número con la persona. */
    public static function maskPhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        return $digits === '' ? null : '•••• '.substr($digits, -4);
    }

    /**
     * ¿Está encendido el vínculo? Apagado por defecto: hasta que el código del
     * vínculo viaje por un canal ligado al reto, en producción (Twilio Verify,
     * que valida teléfono + código) serviría también para un login del socio.
     */
    public function enabled(): bool
    {
        return (bool) config('workforce.member_link_enabled', false);
    }

    /** Apagado: ni reto, ni SMS, ni lecturas del socio. */
    private function assertEnabled(): void
    {
        if (! $this->enabled()) {
            throw WorkforceException::memberLinkDisabled();
        }
    }

    /**
     * El único destino que prueba titularidad: el teléfono PROPIO de la ficha
     * de socio, o null. El del usuario del CRM no cuenta (ver la clase).
     */
    public function verifiablePhone(Member $member): ?string
    {
        $phone = trim((string) $member->phone);

        return $phone === '' ? null : $phone;
    }

    /**
     * Lo que tiene que cumplir el socio para verificar, en este orden: registro
     * terminado, teléfono propio y sin cambios recientes.
     */
    private function assertVerifiable(Member $member): void
    {
        if ($member->status !== Member::STATUS_ACTIVE) {
            throw WorkforceException::memberRegistrationIncomplete();
        }
        if ($this->verifiablePhone($member) === null) {
            throw WorkforceException::memberWithoutPhone();
        }
        if ($this->phoneRecentlyChanged($member)) {
            throw WorkforceException::memberPhoneRecentlyChanged();
        }
    }

    /** ¿Dos teléfonos son el mismo número, ignorando el formato? */
    private function sameNumber(?string $a, ?string $b): bool
    {
        $digits = static fn (?string $v): string => ltrim(preg_replace('/\D+/', '', (string) $v) ?? '', '0');

        return $digits($a) !== '' && $digits($a) === $digits($b);
    }

    /**
     * ¿El teléfono de esta ficha de socio cambió hace menos de
     * {@see PHONE_CHANGE_QUARANTINE_DAYS} días?
     *
     * Dos fuentes, porque los caminos que escriben el teléfono dejan rastros
     * distintos y ninguna los cubre todos:
     *   · la ficha del CRM (`PATCH api/users/{user}`, `members.edit`) deja su
     *     traza en `audit_logs` —módulo «Miembros», entidad «cliente», el id del
     *     USUARIO— con el nombre del campo cambiado y sin el valor;
     *   · el cambio desde la app, la recuperación del número y el cambio desde
     *     soporte dejan `phone_changed` en los eventos de seguridad del socio.
     * Cualquiera de las dos bloquea: ante la duda, la ficha se queda en
     * «coincide por documento, sin verificar», que no vincula nada.
     */
    public function phoneRecentlyChanged(Member $member): bool
    {
        $since = now()->subDays(self::PHONE_CHANGE_QUARANTINE_DAYS);

        $porElSocioOSoporte = MemberSecurityEvent::query()
            ->where('member_id', $member->getKey())
            ->where('type', MemberSecurityEvent::TYPE_PHONE_CHANGED)
            ->where('created_at', '>=', $since)
            ->exists();
        if ($porElSocioOSoporte || $member->user_id === null) {
            return $porElSocioOSoporte;
        }

        // `changes` es JSON y su forma de consultarlo no es la misma en
        // PostgreSQL y en SQLite: se traen las trazas de la ficha de los
        // últimos días (pocas, de UNA persona) y se mira el campo aquí.
        return AuditLog::query()
            ->where('module', 'Miembros')
            ->where('entity', 'cliente')
            ->where('entity_id', (string) $member->user_id)
            ->where('created_at', '>=', $since)
            ->whereNotNull('changes')
            ->get(['changes'])
            ->contains(fn (AuditLog $log): bool => collect((array) $log->changes)
                ->contains(fn ($c): bool => is_array($c) && ($c['field'] ?? null) === 'phone'));
    }

    public function hasTrainerProfile(Identity $identity): bool
    {
        // En cualquier estado: un entrenador inactivo conserva sus comisiones.
        return Trainer::query()->where('identity_id', $identity->getKey())->exists();
    }

    /**
     * El socio pedido, si puede vincularse con esta persona. Inexistente o con
     * otro documento responden igual: no se confirma qué ids de socio existen.
     */
    private function eligibleMember(Identity $identity, int $memberId): Member
    {
        if ($this->hasTrainerProfile($identity)) {
            throw WorkforceException::memberLinkTrainerUnsupported();
        }

        $member = Member::query()->find($memberId);
        if ($member === null || ! $this->sameDocument($member, $identity)) {
            throw WorkforceException::memberNotFound();
        }

        return $member;
    }

    /** Ni el socio cuelga de otra persona ni otra ficha de socio cuelga de esta. */
    private function assertFree(Identity $identity, Member $member): void
    {
        if ($member->identity_id !== null && ! $this->isLinkedTo($member, $identity)) {
            throw WorkforceException::memberLinkedElsewhere();
        }

        $other = Member::query()
            ->where('identity_id', $identity->getKey())
            ->whereKeyNot($member->getKey())
            ->exists();
        if ($other) {
            throw WorkforceException::identityHasMember();
        }
    }

    private function isLinkedTo(Member $member, Identity $identity): bool
    {
        return $member->identity_id !== null && (int) $member->identity_id === (int) $identity->getKey();
    }

    private function sameDocument(Member $member, Identity $identity): bool
    {
        $document = $identity->document_normalized;

        return $document !== null && $document !== ''
            && DocumentNormalizer::normalize($member->document_number) === $document;
    }

    /**
     * A qué número fue el código, para la traza: enmascarado para leerlo y su
     * huella (la misma de la política de OTP) para cruzarlo sin guardarlo.
     *
     * @return array{destination_masked:?string, destination_hash:string}
     */
    private function destination(MemberAuthChallenge $challenge): array
    {
        return [
            'destination_masked' => self::maskPhone($challenge->destination),
            'destination_hash' => $this->policy->phoneHash($challenge->destination),
        ];
    }

    /** @return array<string, mixed> */
    private function context(Request $request): array
    {
        return [
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];
    }

    /** Un límite o el techo de gasto: el código no salió. */
    private function startRejected(OtpException $e): RuntimeException
    {
        $retry = array_intersect_key($e->extra, ['retry_after' => true]);

        return match ($e->status) {
            429 => WorkforceException::otp('otp_rate_limited', 429, $e->getMessage(), $retry),
            503 => WorkforceException::otp('otp_unavailable', 503, $e->getMessage(), $retry),
            // Lo que el motor no anuncia sigue su camino tal cual: mejor un
            // error que un código inventado.
            default => $e,
        };
    }

    private function confirmRejected(OtpException $e): RuntimeException
    {
        return match ($e->status) {
            404 => WorkforceException::otp('otp_not_found', 404, $e->getMessage()),
            409 => WorkforceException::otp('otp_used', 409, $e->getMessage()),
            410 => WorkforceException::otp('otp_expired', 410, $e->getMessage()),
            422 => WorkforceException::otp('otp_invalid', 422, $e->getMessage(), array_intersect_key($e->extra, ['remaining' => true])),
            423 => WorkforceException::otp('otp_blocked', 423, $e->getMessage()),
            default => $e,
        };
    }
}

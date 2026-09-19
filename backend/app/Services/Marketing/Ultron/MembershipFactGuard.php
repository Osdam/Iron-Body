<?php

namespace App\Services\Marketing\Ultron;

use App\Services\Marketing\SalesAgentDecisionSchema;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * La membresía se afirma con los hechos del CRM o no se afirma.
 *
 * Es un cerrojo determinista y estrecho: solo mira las afirmaciones que un
 * cliente tomaría como dato duro sobre SU plan (fecha en que vence, días que
 * le quedan, si está activa o vencida) y las contrasta con `context.membership`.
 * Lo ambiguo no se toca: el Critic juzga `membership_factuality` con criterio.
 *
 * Una contradicción es 422 en commit (`machine_reply_membership_fact`): el
 * turno se pierde antes que decirle a una persona una fecha equivocada de su
 * propia membresía.
 */
final class MembershipFactGuard
{
    public const CODE = 'machine_reply_membership_fact';

    public const REASON_EXPIRY_UNKNOWN = 'expiry_unknown';

    public const REASON_EXPIRY_MISMATCH = 'expiry_mismatch';

    public const REASON_DAYS_UNKNOWN = 'days_unknown';

    public const REASON_DAYS_MISMATCH = 'days_mismatch';

    public const REASON_STATUS_MISMATCH = 'status_mismatch';

    /** Tolerancia en días para «te quedan N días» (el turno puede cruzar la medianoche). */
    private const DIAS_TOLERANCIA = 1;

    private const NOMBRE = '(?:plan|membresia|mensualidad|suscripcion|afiliacion)';

    private const VERBO = '(?:vence|termina|expira|acaba|finaliza|caduca)';

    private const MES = '(?:enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|setiembre|octubre|noviembre|diciembre)';

    private const MESES = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7,
        'agosto' => 8, 'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12,
    ];

    /**
     * «tu plan vence el 15 de octubre», «la membresia se te termina el 3»,
     * «tu mensualidad esta vigente hasta el 20 de mayo». Anclado al sustantivo
     * de membresía para no confundirlo con el vencimiento de un link de pago.
     */
    private const FECHA = [
        '~\b'.self::NOMBRE.'\b[^.!?\d]{0,40}?\b(?:'.self::VERBO.'|vigente hasta|activa? hasta|valida? hasta|hasta)\b[^.!?\d]{0,20}?\bel\s+(\d{1,2})(?:\s+de\s+('.self::MES.'))?\b~u',
        '~\b(?:se\s+)?'.self::VERBO.'\s+(?:tu|su|la|el)\s+'.self::NOMBRE.'\b[^.!?\d]{0,20}?\bel\s+(\d{1,2})(?:\s+de\s+('.self::MES.'))?\b~u',
    ];

    /** «te quedan 12 dias de plan», «a tu membresia le faltan 3 dias». */
    private const DIAS = [
        '~\b(?:quedan|faltan|restan)\s+(\d{1,3})\s+dias?\b[^.!?]{0,30}?\b'.self::NOMBRE.'\b~u',
        '~\b'.self::NOMBRE.'\b[^.!?\d]{0,40}?\b(?:quedan|faltan|restan)\s+(\d{1,3})\s+dias?\b~u',
    ];

    /** «tu plan esta activo», «tu membresia sigue vigente», «estas al dia». */
    private const ACTIVA = '~\b(?:tu|su)\s+'.self::NOMBRE.'\s+(?:esta|sigue|se encuentra|continua|aun esta|todavia esta)\s+(?:activ[ao]|vigente|al dia)\b~u';

    /** «tu plan esta vencido», «tu membresia ya expiro», «tu mensualidad esta inactiva». */
    private const VENCIDA = '~\b(?:tu|su)\s+'.self::NOMBRE.'\s+(?:esta|ya esta|ya|se|quedo)\s+(?:vencid[ao]|expirad[ao]|inactiv[ao]|caducad[ao]|expiro|vencio|termino)\b~u';

    /**
     * «quedas activo», «tu acceso queda habilitado», «ya puedes entrar».
     *
     * Decirle a alguien que ya puede entrar es afirmar que su membresía está
     * viva, aunque la frase no nombre el plan. Llegó aquí desde el laboratorio
     * de tortura, donde salía con la caja vacía pegado a un «ya recibimos tu
     * pago»: el pago lo para {@see PaymentFactGuard}, y la activación, que es
     * otro hecho y de otro dueño, la para esto.
     */
    private const ACTIVACION = [
        '~\b(?:ya\s+)?(?:quedas|quedaste|estas|ya\s+estas)\s+(?:activ[oa]|inscrit[oa]|matriculad[oa]|habilitad[oa])\b~u',
        '~\b(?:tu\s+)?(?:acceso|entrada|ingreso)\b[^.!?]{0,20}\b(?:queda|quedo|esta|ya\s+esta)\s+(?:activ[oa]|habilitad[oa]|list[oa])\b~u',
        '~\bya\s+puedes\s+(?:entrar|ingresar|venir|usar)\b~u',
    ];

    /**
     * @param  array<string,mixed>  $membership  Lo que devuelve MembershipFactsProvider::forPrompt
     * @return ?array{code:string, reason:string, detail:string}
     */
    public function contradiction(string $reply, array $membership): ?array
    {
        $texto = SalesAgentDecisionSchema::normalize($reply);
        $status = (string) ($membership['status'] ?? MembershipFactsProvider::STATUS_UNKNOWN);
        $vigente = in_array($status, [MembershipFactsProvider::STATUS_ACTIVE, MembershipFactsProvider::STATUS_EXPIRING], true);

        if (($fecha = $this->fechaAfirmada($texto)) !== null) {
            $endsOn = $this->fecha($membership['ends_on'] ?? null);
            if ($endsOn === null) {
                return $this->hallazgo(self::REASON_EXPIRY_UNKNOWN, 'reply asserts an expiry date the CRM does not have');
            }
            if ((int) $fecha['day'] !== $endsOn->day || ($fecha['month'] !== null && $fecha['month'] !== $endsOn->month)) {
                return $this->hallazgo(self::REASON_EXPIRY_MISMATCH, sprintf('reply says day %d%s, CRM says %s', $fecha['day'], $fecha['month'] !== null ? ' month '.$fecha['month'] : '', $endsOn->toDateString()));
            }
        }

        if (($dias = $this->diasAfirmados($texto)) !== null) {
            $real = $membership['days_to_expiry'] ?? null;
            if ($real === null || ! $vigente) {
                return $this->hallazgo(self::REASON_DAYS_UNKNOWN, 'reply asserts remaining days the CRM does not have');
            }
            if (abs($dias - (int) $real) > self::DIAS_TOLERANCIA) {
                return $this->hallazgo(self::REASON_DAYS_MISMATCH, sprintf('reply says %d days, CRM says %d', $dias, (int) $real));
            }
        }

        if (preg_match(self::ACTIVA, $texto) === 1 && ! $vigente) {
            return $this->hallazgo(self::REASON_STATUS_MISMATCH, 'reply says the membership is active, CRM status is '.$status);
        }

        if (preg_match(self::VENCIDA, $texto) === 1 && $status !== MembershipFactsProvider::STATUS_EXPIRED) {
            return $this->hallazgo(self::REASON_STATUS_MISMATCH, 'reply says the membership is expired, CRM status is '.$status);
        }

        if (! $vigente) {
            foreach (self::ACTIVACION as $regex) {
                if (preg_match($regex, $texto) === 1) {
                    return $this->hallazgo(self::REASON_STATUS_MISMATCH, 'reply says the person is already active, CRM status is '.$status);
                }
            }
        }

        return null;
    }

    /** @return ?array{day:int, month:?int} */
    private function fechaAfirmada(string $texto): ?array
    {
        foreach (self::FECHA as $regex) {
            if (preg_match($regex, $texto, $m) === 1) {
                return ['day' => (int) $m[1], 'month' => isset($m[2]) && $m[2] !== '' ? self::MESES[$m[2]] : null];
            }
        }

        return null;
    }

    private function diasAfirmados(string $texto): ?int
    {
        foreach (self::DIAS as $regex) {
            if (preg_match($regex, $texto, $m) === 1) {
                return (int) $m[1];
            }
        }

        return null;
    }

    private function fecha(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array{code:string, reason:string, detail:string} */
    private function hallazgo(string $reason, string $detail): array
    {
        return ['code' => self::CODE, 'reason' => $reason, 'detail' => $detail];
    }
}

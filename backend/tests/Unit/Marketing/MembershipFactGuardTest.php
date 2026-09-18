<?php

namespace Tests\Unit\Marketing;

use App\Services\Marketing\Ultron\MembershipFactGuard;
use App\Services\Marketing\Ultron\MembershipFactsProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * La membresía se afirma con los hechos del CRM o no se afirma: fecha de
 * vencimiento, días que quedan y estado. Lo ambiguo (o lo que no habla de la
 * membresía) no se toca.
 */
class MembershipFactGuardTest extends TestCase
{
    private MembershipFactGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new MembershipFactGuard;
    }

    /** @return array<string,mixed> */
    private static function activa(string $endsOn = '2026-10-15', int $days = 27): array
    {
        return ['status' => MembershipFactsProvider::STATUS_ACTIVE, 'ends_on' => $endsOn, 'days_to_expiry' => $days];
    }

    /** @return array<string,mixed> */
    private static function vencida(): array
    {
        return ['status' => MembershipFactsProvider::STATUS_EXPIRED, 'ends_on' => '2026-08-01', 'days_to_expiry' => null, 'days_since_expiry' => 48];
    }

    /** @return array<string,mixed> */
    private static function desconocida(): array
    {
        return ['status' => MembershipFactsProvider::STATUS_UNKNOWN, 'ends_on' => null, 'days_to_expiry' => null];
    }

    /** @return iterable<string, array{string, array<string,mixed>}> */
    public static function coherentes(): iterable
    {
        yield 'fecha exacta' => ['Tu plan vence el 15 de octubre, así que vas bien.', self::activa()];
        yield 'fecha sin mes' => ['Tu membresía se te termina el 15; si quieres la renovamos antes.', self::activa()];
        yield 'vigente hasta' => ['Tu mensualidad está vigente hasta el 15 de octubre.', self::activa()];
        yield 'dias exactos' => ['Te quedan 27 días de plan.', self::activa()];
        yield 'dias con tolerancia' => ['A tu membresía le faltan 26 días.', self::activa()];
        yield 'activa y lo esta' => ['Tu plan está activo y al día.', self::activa()];
        yield 'vencida y lo esta' => ['Tu membresía está vencida desde hace unas semanas; si quieres, la retomamos.', self::vencida()];
        yield 'link de pago, no membresia' => ['El link de pago vence el 3 de noviembre.', self::activa()];
        yield 'sin afirmar nada' => ['Claro, te explico cómo funcionan las clases grupales.', self::desconocida()];
        yield 'quedan cupos, no dias de plan' => ['Quedan 5 días para el evento del sábado.', self::activa()];
        yield 'habla de otro tema con la palabra plan' => ['El plan trimestral incluye acceso a todas las clases.', self::desconocida()];
    }

    #[DataProvider('coherentes')]
    public function test_statements_that_match_the_crm_or_do_not_assert_membership_facts_pass(string $reply, array $membership): void
    {
        $this->assertNull($this->guard->contradiction($reply, $membership), $reply);
    }

    /** @return iterable<string, array{string, array<string,mixed>, string}> */
    public static function contradicciones(): iterable
    {
        yield 'dia distinto' => ['Tu plan vence el 20 de octubre.', self::activa(), MembershipFactGuard::REASON_EXPIRY_MISMATCH];
        yield 'mes distinto' => ['Tu membresía termina el 15 de noviembre.', self::activa(), MembershipFactGuard::REASON_EXPIRY_MISMATCH];
        yield 'fecha sin hecho' => ['Tu plan vence el 15 de octubre.', self::desconocida(), MembershipFactGuard::REASON_EXPIRY_UNKNOWN];
        yield 'dias distintos' => ['Te quedan 10 días de membresía.', self::activa(), MembershipFactGuard::REASON_DAYS_MISMATCH];
        yield 'dias sin hecho' => ['A tu plan le quedan 12 días.', self::desconocida(), MembershipFactGuard::REASON_DAYS_UNKNOWN];
        yield 'dias a una vencida' => ['Te quedan 5 días de plan.', self::vencida(), MembershipFactGuard::REASON_DAYS_UNKNOWN];
        yield 'activa sin serlo' => ['Tu membresía está activa, puedes entrar cuando quieras.', self::vencida(), MembershipFactGuard::REASON_STATUS_MISMATCH];
        yield 'activa sin identidad' => ['Tu plan sigue vigente.', self::desconocida(), MembershipFactGuard::REASON_STATUS_MISMATCH];
        yield 'vencida sin serlo' => ['Tu plan está vencido, por eso no te deja entrar.', self::activa(), MembershipFactGuard::REASON_STATUS_MISMATCH];
    }

    #[DataProvider('contradicciones')]
    public function test_statements_that_contradict_or_invent_membership_facts_are_caught(string $reply, array $membership, string $reason): void
    {
        $hallazgo = $this->guard->contradiction($reply, $membership);

        $this->assertNotNull($hallazgo, $reply);
        $this->assertSame(MembershipFactGuard::CODE, $hallazgo['code']);
        $this->assertSame($reason, $hallazgo['reason'], $reply);
    }

    public function test_the_finding_never_carries_the_reply_text(): void
    {
        $hallazgo = $this->guard->contradiction('Hola Carla, tu plan vence el 20 de octubre.', self::activa());

        $this->assertNotNull($hallazgo);
        $this->assertStringNotContainsString('Carla', json_encode($hallazgo));
    }
}

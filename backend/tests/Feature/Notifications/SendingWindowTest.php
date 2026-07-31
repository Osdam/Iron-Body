<?php

namespace Tests\Feature\Notifications;

use App\Models\MemberNotificationPreference;
use App\Models\NotificationDispatch;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\Notifications\NotificationCategory;
use App\Support\Notifications\SendingWindow;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * La ventana 07:00–22:00 en hora de Bogotá.
 *
 * Todas las horas se expresan en UTC a propósito, que es como corre el
 * servidor: si el cálculo se hiciera con la hora del servidor, «las diez de la
 * noche» serían en realidad las cinco de la tarde en Neiva. Cada caso lleva
 * anotada su hora local para que la intención se lea sin hacer restas.
 */
class SendingWindowTest extends NotificationTestCase
{
    /** Bogotá es UTC-5 todo el año (sin horario de verano). */
    private function utcFor(string $bogotaTime): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-07-30 '.$bogotaTime, 'America/Bogota')
            ->setTimezone('UTC');
    }

    /** @return array<string,array{0:string,1:bool}> */
    public static function horas(): array
    {
        return [
            'medianoche (00:00) cerrado' => ['00:00', false],
            '03:00 cerrado' => ['03:00', false],
            '06:59 cerrado' => ['06:59', false],
            '07:00 ABIERTO (inicio inclusivo)' => ['07:00', true],
            '07:01 abierto' => ['07:01', true],
            'mediodía abierto' => ['12:00', true],
            '17:00 abierto' => ['17:00', true],
            '21:00 abierto' => ['21:00', true],
            '21:59 ABIERTO (último minuto)' => ['21:59', true],
            '22:00 CERRADO (cierre exclusivo)' => ['22:00', false],
            '22:01 cerrado' => ['22:01', false],
            '23:30 cerrado' => ['23:30', false],
        ];
    }

    #[DataProvider('horas')]
    public function test_la_ventana_abre_y_cierra_en_hora_de_bogota(string $horaLocal, bool $abierta): void
    {
        $this->assertSame(
            $abierta,
            SendingWindow::isOpen($this->utcFor($horaLocal)),
            "A las {$horaLocal} de Bogotá la ventana debería estar ".($abierta ? 'abierta' : 'cerrada'),
        );
    }

    public function test_no_depende_de_la_hora_del_servidor(): void
    {
        // 02:00 UTC = 21:00 en Bogotá → abierto, aunque en UTC sea de noche.
        $this->assertTrue(SendingWindow::isOpen(CarbonImmutable::parse('2026-07-31 02:00', 'UTC')));

        // 08:00 UTC = 03:00 en Bogotá → cerrado, aunque en UTC sea de mañana.
        $this->assertFalse(SendingWindow::isOpen(CarbonImmutable::parse('2026-07-31 08:00', 'UTC')));
    }

    public function test_la_zona_horaria_es_la_de_colombia(): void
    {
        $this->assertSame('America/Bogota', SendingWindow::timezone());
        $this->assertSame(7, SendingWindow::startHour());
        $this->assertSame(22, SendingWindow::endHour());
    }

    // ── El motor la respeta ──────────────────────────────────────────────

    private function enviar(CarbonImmutable $cuando, string $categoria = NotificationCategory::MOTIVATION): NotificationDispatch
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);

        // Sin horas de silencio propias, para aislar el efecto de la ventana.
        MemberNotificationPreference::create([
            'member_id' => $member->id,
            'quiet_hours_enabled' => false,
        ]);

        return app(NotificationDispatcher::class)->dispatch(
            memberId: $member->id,
            category: $categoria,
            title: 'Título',
            body: 'Cuerpo.',
            now: $cuando,
        );
    }

    public function test_a_las_0659_no_sale_nada(): void
    {
        $d = $this->enviar($this->utcFor('06:59'));

        $this->assertSame(NotificationDispatch::STATUS_SUPPRESSED, $d->status);
        $this->assertSame(NotificationDispatch::REASON_OUTSIDE_WINDOW, $d->reason);
    }

    public function test_a_las_0700_ya_sale(): void
    {
        $this->assertSame(NotificationDispatch::STATUS_SENT, $this->enviar($this->utcFor('07:00'))->status);
    }

    public function test_a_las_2159_todavia_sale(): void
    {
        $this->assertSame(NotificationDispatch::STATUS_SENT, $this->enviar($this->utcFor('21:59'))->status);
    }

    public function test_a_las_2200_ya_no_sale(): void
    {
        $d = $this->enviar($this->utcFor('22:00'));

        $this->assertSame(NotificationDispatch::REASON_OUTSIDE_WINDOW, $d->reason);
    }

    public function test_despues_de_las_2200_no_sale_nada(): void
    {
        $this->assertSame(
            NotificationDispatch::REASON_OUTSIDE_WINDOW,
            $this->enviar($this->utcFor('23:30'))->reason,
        );
    }

    public function test_la_ventana_pesa_mas_que_apagar_las_horas_de_silencio(): void
    {
        // El socio desactivó su silencio; la ventana del gimnasio sigue mandando.
        $this->assertSame(
            NotificationDispatch::REASON_OUTSIDE_WINDOW,
            $this->enviar($this->utcFor('03:00'))->reason,
        );
    }

    public function test_la_seguridad_de_la_cuenta_atraviesa_la_ventana(): void
    {
        $d = $this->enviar($this->utcFor('03:00'), NotificationCategory::ACCOUNT_SECURITY);

        $this->assertSame(
            NotificationDispatch::STATUS_SENT,
            $d->status,
            'Enterarte a las siete de que entraron en tu cuenta a las tres no sirve de nada.',
        );
    }

    public function test_la_proxima_apertura_es_manana_a_las_siete(): void
    {
        $siguiente = SendingWindow::nextOpening($this->utcFor('23:30'))
            ->setTimezone('America/Bogota');

        $this->assertSame('07:00', $siguiente->format('H:i'));
        $this->assertSame('2026-07-31', $siguiente->format('Y-m-d'), 'Debe ser el día siguiente.');
    }

    public function test_dentro_de_la_ventana_la_proxima_apertura_es_ahora(): void
    {
        $ahora = $this->utcFor('12:00');

        $this->assertTrue(SendingWindow::nextOpening($ahora)->equalTo($ahora));
    }
}

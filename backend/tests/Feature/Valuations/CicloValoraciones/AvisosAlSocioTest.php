<?php

namespace Tests\Feature\Valuations\CicloValoraciones;

use App\Models\Member;
use App\Models\MemberNotificationPreference;
use App\Models\Notification;
use App\Models\Trainer;
use App\Models\ValuationReminder;
use App\Services\Fcm\FcmService;
use App\Services\Valuations\ValuationReminderProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

/**
 * Los avisos al socio de su cita de valoración:
 *
 *  · CONTRATO_SOLICITUDES_VALORACION.md §4: confirmación, reprogramación y
 *    cancelación son PUSH (como los recordatorios de 24 h y 1 h), la
 *    confirmación dice fecha, hora y entrenador (nombre corto), y todos abren
 *    `/evaluation` (`action_type=route`, `action_url=/evaluation`);
 *  · CONTRATO_CICLO_VALORACIONES.md §6: en horas de silencio del socio o con
 *    la ventana del gimnasio cerrada (7–22 en Bogotá) se posponen a la
 *    siguiente hora permitida si aún sirven antes de la cita; si no, salen en
 *    la app SIN push. Los avisos internos del CRM no cambian.
 *
 * Todo pasa por el procesador real y por NotificationService real. Solo se
 * finge la frontera externa: `FcmService` (el envío a Google), que anota qué
 * notificaciones se empujaron. El push sale `afterResponse`: se vacía con
 * `app->terminate()`.
 */
class AvisosAlSocioTest extends TestCase
{
    use CicloFixtures;
    use RefreshDatabase;

    private array $rec;

    private Trainer $andres;

    private Member $laura;

    /** @var array<string, true> event_key de lo que llegó a FCM */
    private array $empujadas = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-10-19 08:00');
        $this->settings(['member_app_enabled' => true]);
        $this->andres = $this->trainer('Andrés Pérez');
        $this->everyDay($this->andres);
        $this->laura = $this->member('Laura Gómez Pérez');
        $this->rec = $this->reception();

        $fcm = Mockery::mock(FcmService::class);
        $fcm->shouldNotReceive('sendToAllMembers');
        $fcm->shouldReceive('sendToMember')->andReturnUsing(function ($m, Notification $n): void {
            $this->assertSame($this->laura->id, $m?->id, 'el push va al socio de la cita');
            $this->empujadas[(string) $n->event_key] = true;
        });
        $this->app->instance(FcmService::class, $fcm);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    private function correr(): array
    {
        return app(ValuationReminderProcessor::class)->run();
    }

    private function aviso(string $uuid, string $kind): ValuationReminder
    {
        return ValuationReminder::query()
            ->where('appointment_id', $this->appointment($uuid)->id)
            ->where('kind', $kind)
            ->orderByDesc('id')
            ->firstOrFail();
    }

    private function alSocio(string $eventKey): ?Notification
    {
        return Notification::where('audience', 'member')->where('event_key', $eventKey)->first();
    }

    private function empujada(string $eventKey): bool
    {
        $this->app->terminate();

        return isset($this->empujadas[$eventKey]);
    }

    private function assertPushConRuta(Notification $n): void
    {
        $this->assertTrue((bool) $n->should_popup, $n->event_key.' es push');
        $this->assertSame('route', $n->action_type);
        $this->assertSame('/evaluation', $n->action_url);
        $this->assertSame($this->laura->id, $n->member_id);
        $this->assertTrue($this->empujada($n->event_key), $n->event_key.' llegó a FCM');
    }

    // ── Push y ruta (contrato de solicitudes §4) ────────────────────────────

    public function test_la_confirmacion_es_push_con_fecha_hora_y_entrenador(): void
    {
        $u = $this->schedule($this->rec, $this->laura, $this->andres, '2026-10-24T09:00:00-05:00');
        $this->correr();

        $n = $this->alSocio("valuation:{$u}:confirmation:v1");
        $this->assertSame('Valoración programada', $n->title);
        $this->assertSame('Tu valoración en Iron Body quedó programada para el 24 de octubre a las 9:00 a. m. con Andrés P.', $n->message);
        $this->assertPushConRuta($n);
        $this->assertSame('accepted', $this->aviso($u, 'confirmation')->status);
        $this->assertNull($this->aviso($u, 'confirmation')->last_error);
    }

    public function test_la_reprogramacion_es_push_con_ruta(): void
    {
        $u = $this->schedule($this->rec, $this->laura, $this->andres, '2026-10-24T09:00:00-05:00');
        $this->postJson("/api/admin/valuations/{$u}/reschedule", ['expected_version' => 1, 'starts_at' => '2026-10-26T10:00:00-05:00'], $this->rec)->assertOk();
        $this->correr();

        $n = $this->alSocio("valuation:{$u}:rescheduled:v2");
        $this->assertSame('Tu valoración fue reprogramada. Nueva fecha: 26 de octubre, 10:00 a. m., con Andrés.', $n->message);
        $this->assertPushConRuta($n);
    }

    public function test_la_cancelacion_es_push_con_ruta(): void
    {
        $u = $this->schedule($this->rec, $this->laura, $this->andres, '2026-10-24T09:00:00-05:00');
        $this->postJson("/api/admin/valuations/{$u}/cancel", ['expected_version' => 1, 'reason' => 'Viaje imprevisto'], $this->rec)->assertOk();
        $this->correr();

        $this->assertPushConRuta($this->alSocio("valuation:{$u}:cancellation:v2"));
    }

    public function test_los_recordatorios_siguen_siendo_push_y_llevan_la_ruta(): void
    {
        $u = $this->schedule($this->rec, $this->laura, $this->andres, '2026-10-24T09:00:00-05:00');
        $this->clock('2026-10-23 09:00');
        $this->correr();
        $this->assertPushConRuta($this->alSocio("valuation:{$u}:reminder_long:v1"));
        $this->clock('2026-10-24 08:00');
        $this->correr();
        $this->assertPushConRuta($this->alSocio("valuation:{$u}:reminder_short:v1"));
    }

    // ── Horario de silencio (contrato del ciclo §6) ─────────────────────────

    public function test_en_silencio_se_pospone_a_la_hora_permitida_y_entonces_sale_con_push(): void
    {
        // Cita a las 07:30: el recordatorio de 1 h toca a las 06:30 (ventana cerrada).
        $u = $this->schedule($this->rec, $this->laura, $this->andres, '2026-10-21T07:30:00-05:00');
        $this->correr(); // la confirmación, a las 08:00
        $this->clock('2026-10-20 07:30');
        $this->correr(); // el de 24 h, a las 07:30
        $this->clock('2026-10-21 06:30');
        $r = $this->correr();

        $corto = $this->aviso($u, 'reminder_short');
        $this->assertSame(1, $r['deferred']);
        $this->assertSame(['pending', '2026-10-21 12:00:00', 0, 'quiet_hours'], [$corto->status, $corto->getRawOriginal('due_at'), $corto->attempts, $corto->last_error], 'pospuesto a las 07:00 de Bogotá sin gastar intento');
        $this->assertNull($this->alSocio("valuation:{$u}:reminder_short:v1"));

        $this->clock('2026-10-21 06:59');
        $this->correr();
        $this->assertNull($this->alSocio("valuation:{$u}:reminder_short:v1"), 'no antes de la hora');

        $this->clock('2026-10-21 07:00');
        $this->correr();
        $this->assertSame(['accepted', null], [$this->aviso($u, 'reminder_short')->status, $this->aviso($u, 'reminder_short')->last_error]);
        $this->assertPushConRuta($this->alSocio("valuation:{$u}:reminder_short:v1"));
    }

    public function test_en_silencio_sin_tiempo_antes_de_la_cita_sale_en_la_app_sin_push(): void
    {
        // Cita a las 06:30: el de 1 h toca a las 05:30 y la siguiente hora
        // permitida (07:00) ya es tarde.
        $u = $this->schedule($this->rec, $this->laura, $this->andres, '2026-10-21T06:30:00-05:00');

        // El de 24 h (06:30 del día anterior) sí se pospone: a las 07:00 aún sirve.
        $this->clock('2026-10-20 06:30');
        $this->correr();
        $this->assertSame(['pending', '2026-10-20 12:00:00'], [$this->aviso($u, 'reminder_long')->status, $this->aviso($u, 'reminder_long')->getRawOriginal('due_at')]);

        $this->clock('2026-10-21 05:30');
        $this->correr();
        $corto = $this->aviso($u, 'reminder_short');
        $this->assertSame(['accepted', 'quiet_hours_no_push'], [$corto->status, $corto->last_error]);
        $n = $this->alSocio("valuation:{$u}:reminder_short:v1");
        $this->assertNotNull($n, 'queda en la app');
        $this->assertFalse((bool) $n->should_popup);
        $this->assertSame('/evaluation', $n->action_url);
        $this->assertFalse($this->empujada($n->event_key), 'sin push');

        // El aviso interno del CRM no cambia: sale a su hora aunque sea de madrugada.
        $this->clock('2026-10-21 06:15');
        $this->correr();
        $this->assertSame('accepted', $this->aviso($u, 'internal_upcoming')->status);
        $this->assertNotNull(Notification::where('audience', 'admin')->where('event_key', "valuation:{$u}:internal_upcoming:v1")->first());
    }

    public function test_respeta_las_horas_de_silencio_propias_del_socio(): void
    {
        MemberNotificationPreference::create([
            'member_id' => $this->laura->id, 'quiet_hours_enabled' => true, 'quiet_hours_start' => 21, 'quiet_hours_end' => 10,
        ]);
        $u = $this->schedule($this->rec, $this->laura, $this->andres, '2026-10-21T10:30:00-05:00');

        // 09:30: la ventana del gimnasio está abierta, pero el socio duerme hasta las 10.
        $this->clock('2026-10-21 09:30');
        $this->correr();
        $this->assertSame(['pending', '2026-10-21 15:00:00'], [$this->aviso($u, 'reminder_short')->status, $this->aviso($u, 'reminder_short')->getRawOriginal('due_at')]);

        $this->clock('2026-10-21 10:00');
        $this->correr();
        $this->assertPushConRuta($this->alSocio("valuation:{$u}:reminder_short:v1"));
    }

    public function test_la_confirmacion_de_noche_se_pospone_a_la_manana(): void
    {
        // A esa hora agenda un Super Admin (la cuenta de recepción tiene horario).
        $this->clock('2026-10-19 22:30');
        $u = $this->schedule($this->h($this->superAdmin()), $this->laura, $this->andres, '2026-10-20T09:00:00-05:00');
        $this->correr();
        $this->assertSame(['pending', '2026-10-20 12:00:00'], [$this->aviso($u, 'confirmation')->status, $this->aviso($u, 'confirmation')->getRawOriginal('due_at')]);
        $this->assertNull($this->alSocio("valuation:{$u}:confirmation:v1"));

        $this->clock('2026-10-20 07:00');
        $this->correr();
        $this->assertPushConRuta($this->alSocio("valuation:{$u}:confirmation:v1"));
    }

    public function test_si_el_silencio_del_socio_y_la_ventana_no_coinciden_nunca_sale_sin_push(): void
    {
        // Silencio de 06 a 23 y ventana de 07 a 22: no hay hora permitida.
        MemberNotificationPreference::create([
            'member_id' => $this->laura->id, 'quiet_hours_enabled' => true, 'quiet_hours_start' => 6, 'quiet_hours_end' => 23,
        ]);
        $u = $this->schedule($this->rec, $this->laura, $this->andres, '2026-10-24T09:00:00-05:00');
        $this->correr();

        $n = $this->alSocio("valuation:{$u}:confirmation:v1");
        $this->assertFalse((bool) $n->should_popup);
        $this->assertSame('quiet_hours_no_push', $this->aviso($u, 'confirmation')->last_error);
        $this->assertFalse($this->empujada($n->event_key));
    }
}

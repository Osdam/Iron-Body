<?php

namespace Tests\Feature\Valuations;

use App\Models\Member;
use App\Models\Notification;
use App\Models\Trainer;
use App\Models\ValuationAppointment;
use App\Models\ValuationReminder;
use App\Models\ValuationSetting;
use App\Services\Fcm\FcmService;
use App\Services\NotificationService;
use App\Services\Valuations\ValuationMessages;
use App\Services\Valuations\ValuationNotifier;
use App\Services\Valuations\ValuationReminderProcessor;
use App\Services\Valuations\ValuationTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * El outbox de avisos y su procesador (contrato §5), con las guardas críticas:
 *
 *  · jamás `createMemberNotification` con socio nulo (sería un push a todos);
 *  · canal del socio apagado ⇒ ninguna notificación al socio (`skipped`);
 *  · un aviso no se duplica aunque el comando corra dos veces o en paralelo;
 *  · reprogramar invalida los avisos viejos; cancelar, todos los posteriores;
 *  · caducado ⇒ `skipped`; `processing` huérfano ⇒ vuelve a `pending`;
 *  · reintentos acotados: `failed` al tercero.
 */
class ValuationRemindersTest extends TestCase
{
    use RefreshDatabase;
    use ValuationFixtures;

    private array $rec;

    private Trainer $andres;

    private Member $laura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-10-19 08:00');
        $this->andres = $this->trainer('Andrés Pérez');
        $this->everyDay($this->andres);
        $this->laura = $this->member('Laura Gómez Pérez');
        $this->rec = $this->reception();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    private function crear(string $cuando = '2026-10-24T09:00:00-05:00'): string
    {
        return $this->book($this->rec, $this->laura, $this->andres, $cuando)->assertCreated()->json('data.uuid');
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

    private function alSocio(): Collection
    {
        return Notification::where('audience', 'member')->where('type', 'valuation')->get();
    }

    // ── Planificación ──────────────────────────────────────────────────────

    public function test_crear_planifica_los_cinco_avisos_con_sus_instantes_utc(): void
    {
        $u = $this->crear('2026-10-24T09:00:00-05:00'); // 14:00 UTC
        $filas = ValuationReminder::where('appointment_id', $this->appointment($u)->id)->orderBy('due_at')->get();

        $this->assertSame([
            ['confirmation', 'member_app', '2026-10-19 13:00:00'],
            ['reminder_long', 'member_app', '2026-10-23 14:00:00'],
            ['reminder_short', 'member_app', '2026-10-24 13:00:00'],
            ['internal_upcoming', 'crm', '2026-10-24 13:45:00'],
            ['internal_unattended', 'crm', '2026-10-24 14:15:00'],
        ], $filas->map(fn ($r) => [$r->kind, $r->channel, $r->getRawOriginal('due_at')])->all());
        $this->assertSame([1], $filas->pluck('appointment_version')->unique()->values()->all());
    }

    public function test_los_que_ya_no_caben_en_el_futuro_no_se_planifican_y_los_desactivados_tampoco(): void
    {
        $this->settings(['reminder_short_enabled' => false, 'internal_unattended_enabled' => false, 'confirmation_enabled' => false]);
        // Hoy a las 10:00: el largo (24 h antes) ya pasó.
        $u = $this->crear('2026-10-19T10:00:00-05:00');
        $this->assertSame(['internal_upcoming'], ValuationReminder::where('appointment_id', $this->appointment($u)->id)->pluck('kind')->all());
    }

    // ── Canal del socio apagado (por defecto) ──────────────────────────────

    public function test_con_el_canal_del_socio_apagado_no_sale_nada_al_socio(): void
    {
        $u = $this->crear();
        $this->assertFalse((bool) ValuationSetting::first()->member_app_enabled, 'apagado por defecto');

        $r = $this->correr();
        $conf = $this->aviso($u, 'confirmation');
        $this->assertSame('skipped', $conf->status);
        $this->assertSame('channel_disabled', $conf->last_error);
        $this->assertCount(0, $this->alSocio());
        $this->assertSame(1, $r['skipped']);
    }

    // ── Envío correcto, textos y no duplicar ───────────────────────────────

    public function test_con_el_canal_activo_envia_con_el_texto_y_la_hora_de_bogota_una_sola_vez(): void
    {
        $this->settings(['member_app_enabled' => true]);
        $this->assign($this->laura, $this->andres);
        $u = $this->crear('2026-10-24T09:00:00-05:00');

        $this->correr();
        $this->correr(); // segunda corrida: nada nuevo
        $conf = $this->aviso($u, 'confirmation');
        $this->assertSame('accepted', $conf->status);
        $this->assertSame(1, $conf->attempts);
        $n = $this->alSocio()->sole();
        $this->assertSame($conf->provider_ref, $n->uuid);
        $this->assertSame($this->laura->id, $n->member_id);
        $this->assertSame('Tu valoración en Iron Body quedó programada para el 24 de octubre a las 9:00 a. m. con Andrés.', $n->message);
        $this->assertFalse((bool) $n->should_popup);
        $this->assertSame("valuation:{$u}:confirmation:v1", $n->event_key);

        // El largo: el viernes 23 a las 09:00 Bogotá (14:00 UTC).
        $this->clock('2026-10-23 08:59');
        $this->correr();
        $this->assertSame('pending', $this->aviso($u, 'reminder_long')->status);
        $this->clock('2026-10-23 09:00');
        $this->correr();
        $largo = Notification::where('event_key', "valuation:{$u}:reminder_long:v1")->sole();
        $this->assertSame('Te recordamos que mañana tienes una valoración en Iron Body a las 9:00 a. m. con Andrés.', $largo->message);
        $this->assertTrue((bool) $largo->should_popup);

        // El corto y los internos el mismo día.
        $this->clock('2026-10-24 08:00');
        $this->correr();
        $this->assertSame('Tu valoración en Iron Body es hoy a las 9:00 a. m. con Andrés.', Notification::where('event_key', "valuation:{$u}:reminder_short:v1")->value('message'));
        $this->clock('2026-10-24 08:45');
        $this->correr();
        $interna = Notification::where('event_key', "valuation:{$u}:internal_upcoming:v1")->sole();
        $this->assertSame('admin', $interna->audience);
        $this->assertSame('high', $interna->priority);
        $this->assertSame('valuations.view_all', $interna->required_permission);
        $this->assertSame('valuation_detail', $interna->action_type);
        $this->assertSame(['appointment_uuid' => $u], $interna->action_payload);
        $this->assertSame('Valoración en 15 min — Laura G. con Andrés a las 9:00 a. m.', $interna->message);

        $this->clock('2026-10-24 09:15');
        $this->correr();
        $this->assertSame('Valoración sin iniciar — Laura G. con Andrés (9:00 a. m.)', Notification::where('event_key', "valuation:{$u}:internal_unattended:v1")->value('message'));

        $this->assertSame(3, $this->alSocio()->count(), 'confirmación, largo y corto: ni uno más');
    }

    public function test_dos_corridas_que_reclaman_a_la_vez_no_duplican(): void
    {
        $this->settings(['member_app_enabled' => true]);
        $u = $this->crear();
        $p = app(ValuationReminderProcessor::class);

        // Las dos «corridas» reclaman sobre el mismo estado: solo una se queda la fila.
        $primera = $p->claim(100);
        $segunda = $p->claim(100);
        $this->assertCount(1, $primera);
        $this->assertSame([], $segunda);
        foreach ($primera as $id) {
            $this->assertSame('accepted', $p->process($id));
        }
        // Reprocesar el mismo id (otra corrida que llegara tarde) no hace nada.
        $this->assertSame('gone', $p->process($primera[0]));

        // Y aunque la fila volviera a la cola (proceso muerto tras enviar), la
        // notificación no se duplica: event_key.
        $this->aviso($u, 'confirmation')->forceFill(['status' => 'pending'])->save();
        $this->correr();
        $this->assertCount(1, $this->alSocio());
    }

    public function test_el_comando_corre_con_su_resumen(): void
    {
        $this->crear();
        $this->artisan('valuations:process-reminders')->expectsOutputToContain('reclamados 1')->assertSuccessful();
        $this->artisan('valuations:process-reminders')->expectsOutputToContain('reclamados 0')->assertSuccessful();
    }

    // ── Socio nulo: nunca una difusión ─────────────────────────────────────

    public function test_jamas_create_member_notification_con_socio_nulo(): void
    {
        $this->settings(['member_app_enabled' => true]);
        $u = $this->crear();

        // Espía sobre el servicio REAL: con socio nulo no puede llamarse nunca;
        // con socio, pasa al código de verdad.
        $espia = Mockery::mock(NotificationService::class)->makePartial();
        $espia->shouldReceive('createMemberNotification')->withArgs(fn ($m) => $m === null)->never();
        $espia->shouldReceive('createMemberNotification')->withArgs(fn ($m) => $m !== null)->passthru();
        $this->app->instance(NotificationService::class, $espia);
        $fcm = Mockery::mock(FcmService::class);
        $fcm->shouldNotReceive('sendToAllMembers');
        $fcm->shouldReceive('sendToMember')->andReturnNull();
        $this->app->instance(FcmService::class, $fcm);

        // El notificador se niega en seco.
        try {
            app(ValuationNotifier::class)->toMember(null, $this->appointment($u), 'confirmation', now());
            $this->fail('sin socio no se envía');
        } catch (LogicException) {
        }

        // Una cita cuyo socio ya no se resuelve: el procesador la OMITE, no
        // difunde. Con la FK (CASCADE) es imposible en la base, así que se
        // fabrica DIFIRIENDO la comprobación de la FK hasta el COMMIT, que en
        // la prueba nunca llega (RefreshDatabase deshace la transacción): en
        // SQLite con `defer_foreign_keys`; en PostgreSQL volviendo DEFERRABLE
        // la FK dentro de la transacción, que basta con ser dueño de la tabla
        // (el rol de producción, NO superusuario). Borrar al socio ya no sirve:
        // la cascada se llevaría la cita.
        $pg = DB::connection()->getDriverName() === 'pgsql';
        DB::statement($pg
            ? 'ALTER TABLE valuation_appointments ALTER CONSTRAINT valuation_appointments_member_id_foreign DEFERRABLE INITIALLY DEFERRED'
            : 'PRAGMA defer_foreign_keys = ON');
        $inexistente = (int) DB::table('members')->max('id') + 1000;
        DB::table('valuation_appointments')->where('uuid', $u)->update(['member_id' => $inexistente]);
        $this->assertNull(DB::table('members')->where('id', $inexistente)->first());

        $this->correr();
        $conf = $this->aviso($u, 'confirmation');
        $this->assertSame(['skipped', 'member_missing'], [$conf->status, $conf->last_error]);
        $this->assertSame(0, Notification::where('audience', 'member')->count());
    }

    // ── Reprogramar, cancelar, cerrar ──────────────────────────────────────

    public function test_reprogramar_invalida_los_avisos_viejos_aunque_esten_reclamados(): void
    {
        $this->settings(['member_app_enabled' => true]);
        $u = $this->crear('2026-10-24T09:00:00-05:00');
        $this->correr(); // confirmación enviada

        // Un aviso de la v1 ya reclamado cuando llega la reprogramación.
        $largo = $this->aviso($u, 'reminder_long');
        $largo->forceFill(['status' => 'processing', 'attempts' => 1])->save();
        $this->postJson("/api/admin/valuations/{$u}/reschedule", ['expected_version' => 1, 'starts_at' => '2026-10-26T10:00:00-05:00'], $this->rec)->assertOk();

        $this->assertSame('skipped', app(ValuationReminderProcessor::class)->process($largo->id));
        $this->assertSame('stale_version', $largo->fresh()->last_error);
        $this->assertSame(0, ValuationReminder::where('appointment_version', 1)->whereIn('status', ['pending', 'error'])->count());

        $this->correr();
        $nuevo = Notification::where('event_key', "valuation:{$u}:rescheduled:v2")->sole();
        $this->assertSame('Tu valoración fue reprogramada. Nueva fecha: 26 de octubre, 10:00 a. m., con Andrés.', $nuevo->message);

        // El largo nuevo sale para la hora NUEVA.
        $this->clock('2026-10-25 10:00');
        $this->correr();
        $this->assertSame('Te recordamos que mañana tienes una valoración en Iron Body a las 10:00 a. m. con Andrés.', Notification::where('event_key', "valuation:{$u}:reminder_long:v2")->value('message'));
        $this->assertNull(Notification::where('event_key', "valuation:{$u}:reminder_long:v1")->first(), 'el de la hora vieja nunca salió');
    }

    public function test_cancelar_avisa_al_socio_y_ningun_recordatorio_posterior(): void
    {
        $this->settings(['member_app_enabled' => true]);
        $u = $this->crear('2026-10-24T09:00:00-05:00');
        $this->postJson("/api/admin/valuations/{$u}/cancel", ['expected_version' => 1, 'reason' => 'Viaje imprevisto'], $this->rec)->assertOk();
        $this->correr();

        $this->assertSame('Tu valoración del 24 de octubre a las 9:00 a. m. fue cancelada.', Notification::where('event_key', "valuation:{$u}:cancellation:v2")->value('message'));
        $this->assertTrue((bool) Notification::where('event_key', "valuation:{$u}:cancellation:v2")->value('should_popup'));

        foreach (['2026-10-23 09:00', '2026-10-24 08:00', '2026-10-24 08:45', '2026-10-24 09:15'] as $hora) {
            $this->clock($hora);
            $this->correr();
        }
        $this->assertSame(['cancellation'], $this->alSocio()->map(fn ($n) => explode(':', $n->event_key)[2])->values()->all(), 'ni confirmación (cancelada antes de salir) ni recordatorios');
        $this->assertSame(0, Notification::where('event_key', 'like', "valuation:{$u}:internal_%")->count());
    }

    public function test_confirmar_conserva_los_recordatorios_y_completar_los_anula(): void
    {
        $this->settings(['member_app_enabled' => true]);
        $u = $this->crear('2026-10-24T09:00:00-05:00');
        $entrenador = $this->h($this->staff('Entrenador', $this->andres));
        $this->postJson("/api/admin/valuations/{$u}/confirm", ['expected_version' => 1], $entrenador)->assertOk();

        $this->assertSame(2, $this->aviso($u, 'reminder_long')->appointment_version, 'la hora no cambió: siguen valiendo');
        $this->clock('2026-10-23 09:00');
        $this->correr();
        $this->assertNotNull(Notification::where('event_key', "valuation:{$u}:reminder_long:v2")->first());

        $this->clock('2026-10-24 09:05');
        $root = $this->h($this->superAdmin());
        $this->postJson("/api/admin/valuations/{$u}/complete", ['expected_version' => 2], $root)->assertOk();
        $this->assertSame(0, ValuationReminder::where('appointment_id', $this->appointment($u)->id)->whereIn('status', ['pending', 'error'])->count());
        $this->correr();
        $this->assertNull(Notification::where('event_key', 'like', "valuation:{$u}:internal_unattended%")->first(), 'completada: no hay «sin iniciar»');
    }

    // ── Caducados, huérfanos y reintentos ──────────────────────────────────

    public function test_un_aviso_que_llega_tarde_se_omite_por_caducado(): void
    {
        $this->settings(['member_app_enabled' => true]);
        $u = $this->crear('2026-10-24T09:00:00-05:00');
        // El servidor estuvo caído y la primera corrida es a las 09:30 del día de la cita.
        $this->clock('2026-10-24 09:30');
        $this->correr();
        foreach (['reminder_long', 'reminder_short', 'internal_upcoming'] as $k) {
            $this->assertSame(['skipped', 'expired'], [$this->aviso($u, $k)->status, $this->aviso($u, $k)->last_error], $k);
        }
        $this->assertSame('accepted', $this->aviso($u, 'internal_unattended')->status, 'sin iniciar sí aplica');
        $this->assertSame(['skipped', 'expired'], [$this->aviso($u, 'confirmation')->status, $this->aviso($u, 'confirmation')->last_error], 'confirmar una cita que ya empezó no sirve');
    }

    public function test_tras_una_caida_larga_ni_sin_atender_ni_confirmacion_salen_de_golpe(): void
    {
        $this->settings(['member_app_enabled' => true]);
        $u = $this->crear('2026-10-24T09:00:00-05:00');
        // Caída del scheduler hasta las 11:01: más de 2 h después de la hora.
        $this->clock('2026-10-24 11:01');
        $this->correr();
        foreach (['confirmation', 'reminder_long', 'reminder_short', 'internal_upcoming', 'internal_unattended'] as $k) {
            $this->assertSame(['skipped', 'expired'], [$this->aviso($u, $k)->status, $this->aviso($u, $k)->last_error], $k);
        }
        $this->assertCount(0, $this->alSocio());
        $this->assertNull(Notification::where('event_key', 'like', "valuation:{$u}:internal_unattended%")->first());
    }

    public function test_sin_atender_justo_dentro_del_margen_de_dos_horas_si_sale(): void
    {
        $u = $this->crear('2026-10-24T09:00:00-05:00');
        $this->clock('2026-10-24 10:59');
        $this->correr();
        $this->assertSame('accepted', $this->aviso($u, 'internal_unattended')->status);
    }

    public function test_processing_huerfano_vuelve_a_pending_y_se_envia(): void
    {
        $this->settings(['member_app_enabled' => true]);
        $u = $this->crear();
        $conf = $this->aviso($u, 'confirmation');
        $conf->forceFill(['status' => 'processing', 'attempts' => 1])->save();
        ValuationReminder::whereKey($conf->id)->update(['updated_at' => Carbon::now()->subMinutes(9)]);

        $this->correr();
        $this->assertSame('processing', $conf->fresh()->status, 'a los 9 min no se toca: puede seguir en vuelo');

        ValuationReminder::whereKey($conf->id)->update(['updated_at' => Carbon::now()->subMinutes(11)]);
        $r = $this->correr();
        $this->assertSame(1, $r['recovered']);
        $this->assertSame('accepted', $conf->fresh()->status);
        $this->assertSame(2, $conf->fresh()->attempts);
    }

    public function test_reintentos_a_los_5_y_15_minutos_y_failed_al_tercero(): void
    {
        $this->settings(['member_app_enabled' => true]);
        $u = $this->crear();

        $roto = Mockery::mock(NotificationService::class);
        $roto->shouldReceive('createMemberNotification')->andThrow(new RuntimeException('FCM caído: Tu valoración en Iron Body…'));
        $roto->shouldReceive('createAdminNotification')->andReturnUsing(fn (array $a) => Notification::create($a + ['audience' => 'admin']));
        $this->app->instance(NotificationService::class, $roto);
        $this->app->forgetInstance(ValuationReminderProcessor::class);
        $this->app->forgetInstance(ValuationNotifier::class);

        $this->correr();
        $conf = $this->aviso($u, 'confirmation')->fresh();
        $this->assertSame(['error', 1], [$conf->status, $conf->attempts]);
        $this->assertSame('2026-10-19 13:05:00', $conf->getRawOriginal('due_at'), 'a los 5 min');
        $this->assertStringNotContainsString('Tu valoración', (string) $conf->last_error, 'el contenido no se guarda');

        $this->correr();
        $this->assertSame('error', $conf->fresh()->status, 'antes de su hora no se reintenta');

        $this->clock('2026-10-19 08:05');
        $this->correr();
        $conf = $conf->fresh();
        $this->assertSame(['error', 2], [$conf->status, $conf->attempts]);
        $this->assertSame('2026-10-19 13:20:00', $conf->getRawOriginal('due_at'), 'a los 15 min');

        $this->clock('2026-10-19 08:20');
        $this->correr();
        $conf = $conf->fresh();
        $this->assertSame(['failed', 3], [$conf->status, $conf->attempts]);
        $this->assertNotNull($conf->processed_at);

        $this->clock('2026-10-19 09:00');
        $this->correr();
        $this->assertSame(3, $conf->fresh()->attempts, 'nunca sin fin');
    }

    // ── Textos ─────────────────────────────────────────────────────────────

    public function test_textos_en_espanol_sin_intl(): void
    {
        $cita = new ValuationAppointment(['starts_at' => Carbon::parse('2026-12-31 12:30', 'America/Bogota')->utc()]);
        $cita->setRelation('trainer', $this->trainer('María José Vélez'));
        $cita->setRelation('member', $this->member('ana'));
        $ahora = Carbon::parse('2026-12-31 07:00', 'America/Bogota')->utc();

        $this->assertSame('31 de diciembre', ValuationTime::dayMonth($cita->starts_at));
        $this->assertSame('12:30 p. m.', ValuationTime::clock($cita->starts_at));
        $this->assertSame('12:05 a. m.', ValuationTime::clock(Carbon::parse('2026-01-01 00:05', 'America/Bogota')->utc()));
        $this->assertSame('11:59 p. m.', ValuationTime::clock(Carbon::parse('2026-01-01 23:59', 'America/Bogota')->utc()));
        $this->assertSame('Te recordamos que hoy tienes una valoración en Iron Body a las 12:30 p. m. con María.',
            ValuationMessages::forReminder('reminder_long', $cita, $ahora)['message']);
        $this->assertSame('Te recordamos que el 31 de diciembre tienes una valoración en Iron Body a las 12:30 p. m. con María.',
            ValuationMessages::forReminder('reminder_long', $cita, $ahora->copy()->subDays(3))['message']);
        $this->assertSame('ana', ValuationMessages::shortName('ana'));
        $this->assertSame('Laura G.', ValuationMessages::shortName('  Laura   gómez  '));
        $this->assertSame('tu entrenador', ValuationMessages::firstName(''));
    }
}

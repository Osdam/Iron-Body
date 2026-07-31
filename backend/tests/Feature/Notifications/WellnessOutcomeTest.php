<?php

namespace Tests\Feature\Notifications;

use App\Models\Member;
use App\Models\MemberDeviceToken;
use App\Models\MemberNotificationPreference;
use App\Models\NotificationDispatch;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\WellnessPlanner;
use App\Support\Notifications\NotificationCategory as Cat;
use App\Support\Notifications\NotificationSlot as Slot;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Qué dice el parte de una tanda, y por qué cada número significa lo que dice.
 *
 * El motivo de que esto tenga fichero propio es que el parte se mira desde n8n
 * sin acceso a la base de datos. Si `sent` incluyera reintentos absorbidos o
 * tokens caducados, nadie se enteraría desde allí de que no llegó nada.
 */
class WellnessOutcomeTest extends NotificationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('notifications:seed-templates')->assertSuccessful();
    }

    private function planner(): WellnessPlanner
    {
        return app(WellnessPlanner::class);
    }

    private function correr(string $slot = Slot::MIDMORNING): array
    {
        $instante = CarbonImmutable::parse('2026-07-30 11:00', 'America/Bogota')->setTimezone('UTC');
        if ($slot !== Slot::MIDMORNING) {
            $instante = CarbonImmutable::parse('2026-07-30 15:00', 'America/Bogota')->setTimezone('UTC');
        }

        CarbonImmutable::setTestNow($instante);
        Carbon::setTestNow($instante);

        return $this->planner()->planDaily($instante);
    }

    public function test_un_token_caducado_no_cuenta_como_enviado(): void
    {
        $this->fakeFcmUnregistered();
        $member = $this->makeMember();
        $token = $this->giveDevice($member);

        $stats = $this->correr();

        $this->assertSame(1, $stats['considered']);
        $this->assertSame(0, $stats['sent'], 'Nadie recibió nada: el token ya no existe.');
        $this->assertSame(1, $stats['invalid_token']);
        $this->assertSame(0, $stats['provider_failed'], 'Un token caducado no es un fallo del proveedor.');

        $this->assertFalse(
            (bool) $token->fresh()->is_active,
            'El token caducado debe quedar desactivado para no reintentarlo eternamente.',
        );
    }

    public function test_un_fallo_del_proveedor_se_distingue_y_tendra_otra_franja(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'INTERNAL']], 500),
        ]);

        $member = $this->makeMember();
        $this->giveDevice($member);

        $stats = $this->correr();

        $this->assertSame(0, $stats['sent']);
        $this->assertSame(1, $stats['provider_failed']);
        $this->assertSame(0, $stats['invalid_token']);
        $this->assertSame(
            1,
            $stats['retry_scheduled'],
            'Quedan franjas por delante hoy, así que habrá otro intento.',
        );
    }

    public function test_en_la_ultima_franja_no_se_promete_un_reintento_que_no_existe(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'INTERNAL']], 500),
        ]);

        $member = $this->makeMember();
        $this->giveDevice($member);

        $instante = CarbonImmutable::parse('2026-07-30 21:45', 'America/Bogota')->setTimezone('UTC');
        CarbonImmutable::setTestNow($instante);
        Carbon::setTestNow($instante);

        $stats = $this->planner()->planDaily($instante);

        $this->assertSame(1, $stats['provider_failed']);
        $this->assertSame(0, $stats['retry_scheduled'], 'Ya no quedan franjas hoy.');
    }

    public function test_un_socio_con_dos_dispositivos_recibe_un_solo_aviso(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);
        MemberDeviceToken::create([
            'member_id' => $member->id,
            'token' => 'fcm-segundo-'.uniqid(),
            'platform' => 'ios',
            'is_active' => true,
            'notification_permission' => 'authorized',
        ]);

        $stats = $this->correr();

        $this->assertSame(1, $stats['considered'], 'Dos teléfonos son un socio, no dos.');
        $this->assertSame(1, $stats['sent']);

        $fila = NotificationDispatch::query()->where('member_id', $member->id)->sole();
        $this->assertSame(2, $fila->tokens_targeted, 'Los dos dispositivos debían recibirlo.');
        $this->assertSame(2, $fila->tokens_delivered);
    }

    public function test_las_preferencias_apagadas_se_cuentan_aparte(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);
        MemberNotificationPreference::create([
            'member_id' => $member->id,
            'opted_out_at' => now(),
        ]);

        $stats = $this->correr();

        $this->assertSame(0, $stats['sent']);
        $this->assertSame(1, $stats['skipped_preferences']);
        $this->assertSame(1, $stats['suppressed']);
    }

    public function test_el_cupo_diario_se_cuenta_aparte_del_resto(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);
        MemberNotificationPreference::create([
            'member_id' => $member->id,
            'max_wellness_per_day' => 1,
        ]);

        $this->correr(Slot::MIDMORNING);
        $stats = $this->correr(Slot::AFTERNOON);

        $this->assertSame(0, $stats['sent']);
        $this->assertSame(1, $stats['skipped_daily_limit']);
    }

    public function test_el_parte_declara_todos_los_contadores_aunque_valgan_cero(): void
    {
        $stats = $this->correr();

        foreach ([
            'slot', 'considered', 'sent', 'already_handled', 'suppressed',
            'skipped_preferences', 'skipped_quiet_hours', 'skipped_daily_limit',
            'skipped_min_interval', 'skipped_recent_template', 'skipped_no_token',
            'skipped_not_eligible', 'skipped_outside_window',
            'invalid_token', 'provider_failed', 'retry_scheduled',
        ] as $clave) {
            $this->assertArrayHasKey($clave, $stats, "Falta el contador «{$clave}».");
        }
    }

    public function test_fuera_de_horario_no_hay_franja_y_no_se_considera_a_nadie(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);

        $instante = CarbonImmutable::parse('2026-07-30 23:00', 'America/Bogota')->setTimezone('UTC');
        $stats = $this->planner()->planDaily($instante);

        $this->assertNull($stats['slot']);
        $this->assertSame(0, $stats['considered']);
        $this->assertSame(0, $stats['sent']);
        $this->assertSame(1, $stats['skipped_outside_window']);
        $this->assertSame(0, NotificationDispatch::count(), 'Ni siquiera debe intentarse.');
    }

    /**
     * Lo transaccional va por su cuenta.
     *
     * Una alerta de seguridad no puede quedarse fuera porque el socio ya haya
     * recibido sus cinco avisos de acompañamiento: son dos sistemas distintos y
     * el cupo de uno no gobierna al otro.
     */
    public function test_una_alerta_de_seguridad_pasa_con_el_cupo_de_bienestar_agotado(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);

        foreach (Slot::ALL as $slot) {
            $instante = CarbonImmutable::parse('2026-07-30 '.match ($slot) {
                Slot::MORNING => '07:00',
                Slot::MIDMORNING => '11:00',
                Slot::AFTERNOON => '15:00',
                Slot::EVENING => '19:00',
                Slot::NIGHT => '21:45',
            }, 'America/Bogota')->setTimezone('UTC');
            CarbonImmutable::setTestNow($instante);
            Carbon::setTestNow($instante);
            $this->planner()->planDaily($instante);
        }

        $this->assertSame(
            5,
            NotificationDispatch::query()->where('member_id', $member->id)->sent()->count(),
        );

        $alerta = app(NotificationDispatcher::class)->dispatch(
            memberId: $member->id,
            category: Cat::ACCOUNT_SECURITY,
            title: 'Nuevo inicio de sesión',
            body: 'Se abrió tu cuenta en otro dispositivo.',
        );

        $this->assertSame(
            NotificationDispatch::STATUS_SENT,
            $alerta->status,
            'El cupo de bienestar no puede callar una alerta de seguridad.',
        );
    }

    public function test_un_socio_suspendido_no_entra_en_la_tanda(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);
        $member->update(['status' => Member::STATUS_SUSPENDED]);

        $stats = $this->correr();

        $this->assertSame(0, $stats['considered'], 'Ni se le evalúa.');
        $this->assertSame(0, $stats['sent']);
    }
}

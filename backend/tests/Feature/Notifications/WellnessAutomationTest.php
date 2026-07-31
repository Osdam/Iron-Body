<?php

namespace Tests\Feature\Notifications;

use App\Models\AppNotification;
use App\Models\MemberNotificationPreference;
use App\Models\NotificationDispatch;
use App\Services\AppNotificationService;
use App\Support\Notifications\NotificationCategory;
use Illuminate\Support\Facades\Config;

/**
 * La frontera con n8n.
 *
 * La regla que se comprueba aquí es una sola: n8n decide CUÁNDO, Laravel decide
 * QUÉ y A QUIÉN. Si esa frontera se mueve, las preferencias del socio dejan de
 * significar nada, porque bastaría con disparar desde fuera para saltárselas.
 */
class WellnessAutomationTest extends NotificationTestCase
{
    /**
     * Cabeceras como las que manda n8n: Bearer obligatorio + firma HMAC del
     * cuerpo crudo. Se firma aunque el middleware la acepte opcional, porque es
     * lo que hará el workflow real.
     */
    private function signed(array $body): array
    {
        $secret = (string) config('automation.internal_secret');

        return [
            'Authorization' => 'Bearer '.$secret,
            'X-IronBody-Signature' => hash_hmac('sha256', json_encode($body), $secret),
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('automation.internal_secret', 'secreto-de-prueba');
    }

    public function test_el_endpoint_exige_firma(): void
    {
        $this->postJson('/api/internal/automation/wellness-run', [])
            ->assertStatus(401);
    }

    public function test_n8n_puede_comprobar_la_conexion_sin_enviar(): void
    {
        $this->fakeFcmSuccess();
        $this->giveDevice($this->makeMember());
        $body = ['dry_run' => true];

        $this->postJson('/api/internal/automation/wellness-run', $body, $this->signed($body))
            ->assertOk()
            ->assertJsonPath('dry_run', true);

        $this->assertSame(0, NotificationDispatch::count(), 'Una comprobación no debe enviar.');
    }

    public function test_n8n_dispara_la_tanda(): void
    {
        $this->fakeFcmSuccess();
        $this->artisan('notifications:seed-templates')->assertSuccessful();
        $member = $this->makeMember();
        $this->giveDevice($member);

        $res = $this->postJson('/api/internal/automation/wellness-run', [], $this->signed([]));

        $res->assertOk()->assertJsonPath('ok', true);
        $this->assertSame(1, $res->json('sent'));
    }

    public function test_no_dispara_si_el_modulo_esta_inerte(): void
    {
        config(['notifications.wellness.enabled' => false]);

        $this->postJson('/api/internal/automation/wellness-run', [], $this->signed([]))
            ->assertStatus(409)
            ->assertJsonPath('reason', 'wellness_disabled');
    }

    public function test_disparar_dos_veces_el_mismo_dia_no_duplica(): void
    {
        $this->fakeFcmSuccess();
        $this->artisan('notifications:seed-templates')->assertSuccessful();
        $member = $this->makeMember();
        $this->giveDevice($member);

        $this->postJson('/api/internal/automation/wellness-run', [], $this->signed([]))->assertOk();
        $this->postJson('/api/internal/automation/wellness-run', [], $this->signed([]))->assertOk();

        $this->assertSame(
            1,
            NotificationDispatch::where('member_id', $member->id)->count(),
            'La llave diaria debe absorber el segundo disparo.',
        );
    }

    public function test_n8n_no_puede_saltarse_las_preferencias(): void
    {
        $this->fakeFcmSuccess();
        $this->artisan('notifications:seed-templates')->assertSuccessful();
        $member = $this->makeMember();
        $this->giveDevice($member);

        MemberNotificationPreference::create([
            'member_id' => $member->id,
            'categories' => [
                NotificationCategory::MOTIVATION => false,
                NotificationCategory::HYDRATION => false,
                NotificationCategory::RECOVERY => false,
                NotificationCategory::NUTRITION => false,
            ],
        ]);

        $res = $this->postJson('/api/internal/automation/wellness-run', [], $this->signed([]));

        $res->assertOk();
        $this->assertSame(0, $res->json('sent'), 'Disparar desde fuera no anula el «no quiero».');
    }

    // ── La otra puerta de n8n: notify-member (coach proactivo) ───────────────

    public function test_el_coach_respeta_la_categoria_apagada(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);

        MemberNotificationPreference::create([
            'member_id' => $member->id,
            'categories' => [NotificationCategory::WORKOUTS => false],
        ]);

        $result = app(AppNotificationService::class)->createForMember(
            memberId: $member->id,
            type: 'routine',
            title: 'Tu rutina te espera',
            body: 'Cuerpo.',
        );

        $this->assertSame('skipped_opted_out', $result['status']);
        $this->assertSame(0, AppNotification::where('member_id', $member->id)->count());
    }

    public function test_el_coach_si_llega_cuando_la_categoria_esta_encendida(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);

        $result = app(AppNotificationService::class)->createForMember(
            memberId: $member->id,
            type: 'routine',
            title: 'Tu rutina te espera',
            body: 'Cuerpo.',
        );

        $this->assertSame('created', $result['status']);
    }

    public function test_en_horas_de_silencio_se_guarda_pero_no_se_empuja(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);

        // Silencio todo el día: el aviso debe existir, pero sin push.
        MemberNotificationPreference::create([
            'member_id' => $member->id,
            'quiet_hours_enabled' => true,
            'quiet_hours_start' => 0,
            'quiet_hours_end' => 23,
        ]);

        $result = app(AppNotificationService::class)->createForMember(
            memberId: $member->id,
            type: 'routine',
            title: 'Tu rutina te espera',
            body: 'Cuerpo.',
        );

        $this->assertSame('created', $result['status'], 'El aviso debe quedar en el centro de la app.');
        $this->assertNull(
            $result['notification']->delivered_at,
            'No debe haberse empujado durante las horas de silencio.',
        );
    }
}

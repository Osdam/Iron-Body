<?php

namespace Tests\Feature\Notifications;

use App\Models\Admin;
use App\Models\MemberNotificationPreference;
use App\Models\NotificationCampaign;
use App\Models\NotificationDispatch;
use App\Models\NotificationTemplate;
use App\Services\Admin\AdminSessionService;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\Notifications\NotificationCategory;

/**
 * Campañas manuales. Lo que se prueba aquí, sobre todo, es lo que NO ocurre:
 * que crear no envía, que confirmar mal no envía y que una campaña no puede
 * saltarse el "no quiero" de nadie.
 */
class NotificationCampaignTest extends NotificationTestCase
{
    private function asAdmin(): array
    {
        $admin = Admin::create([
            'name' => 'Admin Notificaciones',
            'email' => 'admin'.uniqid().'@ironbody.test',
            'password' => 'super-secret',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);

        return ['Authorization' => 'Bearer '.app(AdminSessionService::class)->issueSession($admin)['token']];
    }

    private function draft(array $overrides = []): NotificationCampaign
    {
        return NotificationCampaign::create(array_merge([
            'name' => 'Campaña de prueba',
            'category' => NotificationCategory::MEMBERSHIP,
            'title' => 'Novedad en el gimnasio',
            'body' => 'Hemos ampliado el horario de los sábados.',
            'status' => NotificationCampaign::STATUS_DRAFT,
            'created_by' => 'tester',
        ], $overrides));
    }

    public function test_crear_una_campana_no_envia_nada(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member);

        $res = $this->postJson('/api/admin/push/campaigns', [
            'name' => 'Campaña',
            'category' => NotificationCategory::MEMBERSHIP,
            'title' => 'Novedad',
            'body' => 'Cuerpo del mensaje.',
        ], $this->asAdmin());

        $res->assertCreated()->assertJsonPath('data.status', NotificationCampaign::STATUS_DRAFT);
        $this->assertSame(0, NotificationDispatch::query()->count(), 'Crear no debe enviar.');
    }

    public function test_rechaza_una_categoria_desconocida(): void
    {
        $this->postJson('/api/admin/push/campaigns', [
            'name' => 'Campaña',
            'category' => 'lo_que_sea',
            'title' => 'Novedad',
            'body' => 'Cuerpo.',
        ], $this->asAdmin())
            ->assertStatus(422)
            ->assertJsonPath('code', 'invalid_category');
    }

    public function test_una_audiencia_grande_exige_confirmar_el_numero_exacto(): void
    {
        $this->fakeFcmSuccess();
        config(['notifications.campaigns.large_audience_threshold' => 3]);

        for ($i = 0; $i < 4; $i++) {
            $this->giveDevice($this->makeMember("Socio {$i}"));
        }

        $campaign = $this->draft();

        // Sin confirmar: no sale.
        $this->postJson("/api/admin/push/campaigns/{$campaign->id}/send", [], $this->asAdmin())
            ->assertStatus(422)
            ->assertJsonPath('code', 'confirmation_required');

        // Con un número equivocado: tampoco.
        $this->postJson("/api/admin/push/campaigns/{$campaign->id}/send", [
            'confirm_recipients' => 2,
        ], $this->asAdmin())->assertStatus(422);

        $this->assertSame(0, NotificationDispatch::query()->count());

        // Con el número exacto: sale.
        $this->postJson("/api/admin/push/campaigns/{$campaign->id}/send", [
            'confirm_recipients' => 4,
        ], $this->asAdmin())->assertOk();

        $this->assertSame(4, NotificationDispatch::query()->sent()->count());
    }

    public function test_una_campana_no_se_envia_dos_veces(): void
    {
        $this->fakeFcmSuccess();
        config(['notifications.campaigns.large_audience_threshold' => 999]);
        $this->giveDevice($this->makeMember());

        $campaign = $this->draft();

        $this->postJson("/api/admin/push/campaigns/{$campaign->id}/send", [], $this->asAdmin())
            ->assertOk();

        $this->postJson("/api/admin/push/campaigns/{$campaign->id}/send", [], $this->asAdmin())
            ->assertStatus(422)
            ->assertJsonPath('code', 'campaign_not_sendable');

        $this->assertSame(1, NotificationDispatch::query()->count());
    }

    public function test_una_campana_no_ignora_las_preferencias_del_socio(): void
    {
        $this->fakeFcmSuccess();
        config(['notifications.campaigns.large_audience_threshold' => 999]);

        $quiere = $this->makeMember('Quiere');
        $this->giveDevice($quiere);

        $noQuiere = $this->makeMember('No quiere');
        $this->giveDevice($noQuiere);
        MemberNotificationPreference::create([
            'member_id' => $noQuiere->id,
            'categories' => [NotificationCategory::PROMOTIONS => false],
        ]);

        $campaign = $this->draft(['category' => NotificationCategory::PROMOTIONS]);

        // El otro socio tampoco las tiene: promociones nacen apagadas.
        MemberNotificationPreference::create([
            'member_id' => $quiere->id,
            'categories' => [NotificationCategory::PROMOTIONS => true],
        ]);

        $res = $this->postJson("/api/admin/push/campaigns/{$campaign->id}/send", [], $this->asAdmin());

        $res->assertOk();
        $this->assertSame(1, $res->json('stats.sent'));
        $this->assertSame(1, $res->json('stats.suppressed'));

        $suprimido = NotificationDispatch::query()->where('member_id', $noQuiere->id)->first();
        $this->assertSame(NotificationDispatch::REASON_OPTED_OUT, $suprimido->reason);
    }

    public function test_no_envia_a_una_audiencia_vacia(): void
    {
        $campaign = $this->draft();

        $this->postJson("/api/admin/push/campaigns/{$campaign->id}/send", [], $this->asAdmin())
            ->assertStatus(422)
            ->assertJsonPath('code', 'empty_audience');
    }

    public function test_se_puede_cancelar_un_borrador(): void
    {
        $campaign = $this->draft();

        $this->postJson("/api/admin/push/campaigns/{$campaign->id}/cancel", [], $this->asAdmin())
            ->assertOk()
            ->assertJsonPath('data.status', NotificationCampaign::STATUS_CANCELLED);

        $this->postJson("/api/admin/push/campaigns/{$campaign->id}/send", [], $this->asAdmin())
            ->assertStatus(422);
    }

    public function test_una_campana_enviada_ya_no_se_edita(): void
    {
        $this->fakeFcmSuccess();
        config(['notifications.campaigns.large_audience_threshold' => 999]);
        $this->giveDevice($this->makeMember());
        $campaign = $this->draft();

        $this->postJson("/api/admin/push/campaigns/{$campaign->id}/send", [], $this->asAdmin())->assertOk();

        $this->putJson("/api/admin/push/campaigns/{$campaign->id}", [
            'title' => 'Otro título',
        ], $this->asAdmin())
            ->assertStatus(422)
            ->assertJsonPath('code', 'campaign_not_editable');
    }

    public function test_las_rutas_de_notificaciones_exigen_admin(): void
    {
        $this->getJson('/api/admin/push/templates')->assertUnauthorized();
        $this->getJson('/api/admin/push/campaigns')->assertUnauthorized();
        $this->getJson('/api/admin/push/metrics')->assertUnauthorized();
    }

    public function test_editar_una_plantilla_sube_su_version(): void
    {
        $this->artisan('notifications:seed-templates')->assertSuccessful();
        $t = NotificationTemplate::query()->firstWhere('key', 'mot_constancia');

        $this->putJson("/api/admin/push/templates/{$t->id}", [
            'title' => 'Sigue así',
        ], $this->asAdmin())->assertOk();

        $this->assertSame($t->version + 1, $t->fresh()->version);
    }

    public function test_las_metricas_explican_por_que_no_se_envio(): void
    {
        $this->fakeFcmSuccess();
        $member = $this->makeMember();
        $this->giveDevice($member, active: false);

        app(NotificationDispatcher::class)->dispatch(
            memberId: $member->id,
            category: NotificationCategory::MOTIVATION,
            title: 'Título',
            body: 'Cuerpo',
        );

        $res = $this->getJson('/api/admin/push/metrics', $this->asAdmin());

        $res->assertOk();
        $this->assertSame(1, $res->json('data.suppression_reasons.'.NotificationDispatch::REASON_NO_TOKEN));
    }
}

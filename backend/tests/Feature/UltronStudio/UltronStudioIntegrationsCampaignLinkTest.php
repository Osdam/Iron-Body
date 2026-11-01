<?php

namespace Tests\Feature\UltronStudio;

use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioCampaignLink;
use App\Services\UltronStudio\StudioCampaignLinks;
use App\Services\UltronStudio\StudioCanaryPhone;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * CAMPAÑAS DE META Y SU ASOCIACIÓN CON ULTRON por HTTP (contrato H6 §I3): el
 * espejo local leído sin red (rutas 40 y 41) y asociar, sustituir y desactivar
 * (47 y 48) con sus puertas en el orden de §I3.4. Es REGISTRO del Studio: no
 * cambia nada en Meta ni en el motor, y el id de Meta no sale del servidor.
 */
class UltronStudioIntegrationsCampaignLinkTest extends TestCase
{
    use IntegrationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    private const C1 = '120000000000000387';

    private const C2 = '120000000000000512';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->integrationsFlags(writes: true);
    }

    public function test_sin_meta_configurado_no_hay_cuentas_ni_campanas_y_nunca_datos_de_demostracion(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $this->metaCampaign(self::ACCOUNTS[0], self::C1, 'Pauta octubre');

        $data = $this->integrations('GET', 'meta/campaigns', $ana)->assertOk()->json('data');
        $this->assertSame('not_configured', $data['connection']['state']);
        $this->assertSame('missing_access_token', $data['connection']['reason']);
        $this->assertSame([], $data['connection']['accounts']);
        $this->assertSame([], $data['campaigns']['data']);
        $this->assertSame(0, $data['campaigns']['total']);
    }

    public function test_las_campanas_del_espejo_con_su_estado_de_meta_y_su_asociacion_sin_mezclarse(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $this->metaConfigured();
        $this->metaCampaign(self::ACCOUNTS[0], self::C1, 'Pauta octubre 3001234821', 'ACTIVE');
        $this->metaCampaign(self::ACCOUNTS[1], self::C2, 'alfa retargeting', 'PAUSED');
        $this->metaCampaign(self::ACCOUNTS[1], '120000000000000600', 'Beta', null, CarbonImmutable::now()->subHours(3));
        // Una campaña de una cuenta que NO está conectada no aparece.
        $this->metaCampaign('9999999999990001', '120000000000000700', 'De otra cuenta');

        $data = $this->integrations('GET', 'meta/campaigns', $ana)->assertOk()->json('data');
        $this->assertSame('ok', $data['connection']['state']);
        $this->assertNull($data['connection']['reason']);
        $this->assertCount(3, $data['connection']['accounts']);
        $this->assertSame(['ok', 'ok', 'ok'], array_column($data['connection']['accounts'], 'state'));
        $this->assertSame(3, $data['campaigns']['total']);
        $campanas = $data['campaigns']['data'];
        // ACTIVE primero; después por nombre sin mayúsculas.
        $this->assertSame([self::campaignId(self::C1), self::campaignId(self::C2), self::campaignId('120000000000000600')], array_column($campanas, 'id'));
        $this->assertSame('***0387', $campanas[0]['ref']);
        $this->assertSame('Pauta octubre ***4821', $campanas[0]['name'], 'el nombre va con las series de dígitos enmascaradas');
        $this->assertSame(['id' => 'account:'.substr(hash_hmac('sha256', 'account|'.self::ACCOUNTS[0], (string) config('app.key')), 0, 16), 'ref' => '***4714', 'name' => 'Cuenta 1'], $campanas[0]['account']);
        $this->assertSame('ACTIVE', $campanas[0]['meta']['effectiveStatus']);
        $this->assertFalse($campanas[0]['meta']['stale']);
        $this->assertFalse($campanas[0]['meta']['possiblyDeleted']);
        $this->assertSame(10, $campanas[0]['meta']['ageMinutes']);
        $this->assertNull($campanas[0]['ultron']['link']);
        // Sincronizada antes de la última pasada de su cuenta: posiblemente eliminada.
        $this->assertTrue($campanas[2]['meta']['possiblyDeleted']);
        $this->assertNull($campanas[2]['meta']['effectiveStatus']);

        $cuerpo = $this->integrations('GET', 'meta/campaigns', $ana)->getContent();
        foreach ([self::C1, self::C2, self::ACCOUNTS[0], '3001234821'] as $id) {
            $this->assertStringNotContainsString($id, $cuerpo, 'los ids de Meta no salen del servidor');
        }

        // Filtros.
        $this->assertSame(1, $this->integrations('GET', 'meta/campaigns?status=ACTIVE', $ana)->json('data.campaigns.total'));
        $this->assertSame(1, $this->integrations('GET', 'meta/campaigns?status=unknown', $ana)->json('data.campaigns.total'));
        $cuenta2 = $campanas[1]['account']['id'];
        $this->assertSame(2, $this->integrations('GET', 'meta/campaigns?account='.$cuenta2, $ana)->json('data.campaigns.total'));
        $this->assertSame(0, $this->integrations('GET', 'meta/campaigns?account=account:0000000000000000', $ana)->json('data.campaigns.total'), 'una cuenta que no está conectada da una lista vacía');
        $this->integrations('GET', 'meta/campaigns?account=act_1000', $ana)->assertStatus(422);
        $this->integrations('GET', 'meta/campaigns?status=PENDING', $ana)->assertStatus(422);
        $this->assertSame(2, $this->integrations('GET', 'meta/campaigns?per_page=2', $ana)->json('data.campaigns.per_page'));
        $this->assertCount(2, $this->integrations('GET', 'meta/campaigns?per_page=2', $ana)->json('data.campaigns.data'));
        $this->assertSame(50, $this->integrations('GET', 'meta/campaigns?per_page=500', $ana)->json('data.campaigns.per_page'));

        $this->integrations('POST', 'meta/links', $ana, ['clientRequestId' => (string) Str::uuid(), 'campaign' => self::campaignId(self::C1), 'target' => ['kind' => 'baseline']])->assertCreated();
        $this->assertSame(1, $this->integrations('GET', 'meta/campaigns?linked=yes', $ana)->json('data.campaigns.total'));
        $this->assertSame(2, $this->integrations('GET', 'meta/campaigns?linked=no', $ana)->json('data.campaigns.total'));
        $con = $this->integrations('GET', 'meta/campaigns?linked=yes', $ana)->json('data.campaigns.data.0');
        $this->assertSame('ACTIVE', $con['meta']['effectiveStatus'], 'lo que dice Meta sigue siendo lo de Meta');
        $this->assertSame('active', $con['ultron']['link']['status']);
    }

    public function test_la_sincronizacion_apagada_degrada_con_sync_disabled_y_una_cuenta_vieja_marca_stale(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $this->metaConfigured(syncEnabled: false);
        $this->metaCampaign(self::ACCOUNTS[2], self::C1, 'Pauta');
        $data = $this->integrations('GET', 'meta/campaigns', $ana)->assertOk()->json('data');
        $this->assertSame(['ok', 'sync_disabled'], [$data['connection']['state'], $data['connection']['reason']]);
        $meta = collect($this->integrations('GET', 'status', $ana)->json('data.providers'))->firstWhere('id', 'meta_ads');
        $this->assertSame(['degraded', 'ok', 'sync_disabled'], [$meta['classification'], $meta['state'], $meta['reason']]);

        // La tercera cuenta sin pasadas recientes: dato viejo en sus campañas.
        \DB::table('meta_sync_runs')->where('ad_account_id', self::ACCOUNTS[2])->update(['started_at' => now()->subHours(6), 'finished_at' => now()->subHours(6)]);
        config(['meta.ads.sync_enabled' => true]);
        $data = $this->integrations('GET', 'meta/campaigns', $ana)->assertOk()->json('data');
        $this->assertSame('stale', $data['connection']['state']);
        $this->assertTrue($data['campaigns']['data'][0]['meta']['stale']);
    }

    public function test_asociar_sustituir_y_desactivar_con_el_libro_en_la_misma_transaccion(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $root = $this->personWith(self::SUPER_KEYS, 'Root');
        $this->metaConfigured();
        $this->metaCampaign(self::ACCOUNTS[0], self::C1, 'Pauta octubre 3001234821');
        $canario = $this->versionIn($root, 'CANARY');

        $r = $this->integrations('POST', 'meta/links', $ana, ['clientRequestId' => (string) Str::uuid(), 'campaign' => self::campaignId(self::C1), 'target' => ['kind' => 'baseline']])->assertCreated();
        $r->assertJsonPath('data.replayed', false)->assertJsonPath('data.replaced', null);
        $linea = $r->json('data.link');
        $this->assertSame('active', $linea['status']);
        $this->assertSame(['kind' => 'baseline', 'label' => 'LEGACY_BASELINE_V1'], $linea['target']);
        $this->assertSame(['id' => self::campaignId(self::C1), 'ref' => '***0387', 'nameAtLink' => 'Pauta octubre ***4821', 'connected' => true, 'currentName' => 'Pauta octubre ***4821'], $linea['campaign']);
        $this->assertNull($linea['deactivated']);

        // Sin `replaces`: ya está asociada.
        $this->integrations('POST', 'meta/links', $ana, ['clientRequestId' => (string) Str::uuid(), 'campaign' => self::campaignId(self::C1), 'target' => ['kind' => 'version', 'versionUuid' => $canario->uuid]])
            ->assertStatus(409)->assertJsonPath('code', 'campaign_already_linked')->assertJsonPath('current.uuid', $linea['uuid']);
        // Con un `replaces` que no es la activa: movida.
        $this->integrations('POST', 'meta/links', $ana, ['clientRequestId' => (string) Str::uuid(), 'campaign' => self::campaignId(self::C1), 'target' => ['kind' => 'baseline'], 'replaces' => (string) Str::uuid()])
            ->assertStatus(409)->assertJsonPath('code', 'campaign_link_moved')->assertJsonPath('current.uuid', $linea['uuid']);

        $s = $this->integrations('POST', 'meta/links', $root, ['clientRequestId' => (string) Str::uuid(), 'campaign' => self::campaignId(self::C1),
            'target' => ['kind' => 'version', 'versionUuid' => $canario->uuid], 'replaces' => $linea['uuid']])->assertCreated()->json('data');
        $this->assertSame(['kind' => 'version', 'versionUuid' => $canario->uuid, 'label' => $canario->label, 'status' => 'CANARY', 'statusLabel' => 'Canario', 'integrityOk' => true], $s['link']['target']);
        $this->assertSame('inactive', $s['replaced']['status']);
        $this->assertSame('Sustituida por otra asociación', $s['replaced']['deactivated']['reason']);
        $this->assertSame($s['link']['uuid'], $s['replaced']['deactivated']['replacedBy']);
        $this->assertSame('Root', $s['replaced']['deactivated']['by']['name']);

        $d = $this->integrations('POST', "meta/links/{$s['link']['uuid']}/deactivate", $ana, ['reason' => 'Campaña terminada'])->assertOk()->json('data.link');
        $this->assertSame('inactive', $d['status']);
        $this->assertSame(['at' => $d['deactivated']['at'], 'by' => ['name' => 'Ana', 'role' => $ana->role], 'reason' => 'Campaña terminada', 'replacedBy' => null], $d['deactivated']);
        $this->integrations('POST', "meta/links/{$s['link']['uuid']}/deactivate", $ana, ['reason' => 'Otra vez'])->assertStatus(409)
            ->assertJsonPath('code', 'invalid_transition')->assertJsonPath('currentStatus', 'inactive')->assertJsonPath('allowedFrom', ['active']);
        $this->integrations('POST', 'meta/links/'.Str::uuid().'/deactivate', $ana, ['reason' => 'No existe'])->assertStatus(404)->assertJsonPath('code', 'link_not_found');

        $eventos = StudioAuditEvent::query()->where('subject_type', 'campaign_link')->orderBy('id')->get();
        $this->assertSame(['campaign_link.created', 'campaign_link.created', 'campaign_link.deactivated', 'campaign_link.deactivated'], $eventos->pluck('event')->all());
        $this->assertSame(['campaign' => self::campaignId(self::C1), 'accountRef' => '***4714', 'target' => ['kind' => 'baseline'], 'replaces' => null], $eventos[0]->diff);
        $this->assertSame(['campaign' => self::campaignId(self::C1), 'accountRef' => '***4714', 'target' => ['kind' => 'version', 'versionUuid' => $canario->uuid], 'replaces' => $linea['uuid']], $eventos[1]->diff);
        $this->assertSame(['reason' => 'Sustituida por otra asociación', 'replacedBy' => $s['link']['uuid']], $eventos[2]->diff);
        $this->assertSame(['reason' => 'Campaña terminada', 'replacedBy' => null], $eventos[3]->diff);
        foreach ($eventos as $e) {
            $plano = json_encode([$e->summary, $e->diff]);
            $this->assertStringNotContainsString(self::C1, $plano, 'el id de Meta no va al libro');
            $this->assertStringNotContainsString('Pauta', $plano, 'ni el nombre de la campaña');
        }

        // El historial: por id descendente, y filtrado por campaña o estado.
        $lista = $this->integrations('GET', 'meta/links', $ana)->assertOk()->json('data');
        $this->assertSame(2, $lista['total']);
        $this->assertSame([$s['link']['uuid'], $linea['uuid']], array_column($lista['data'], 'uuid'));
        $this->assertSame(2, $this->integrations('GET', 'meta/links?campaign='.self::campaignId(self::C1), $ana)->json('data.total'));
        $this->assertSame(0, $this->integrations('GET', 'meta/links?campaign=campaign:0000000000000000', $ana)->json('data.total'));
        $this->assertSame(0, $this->integrations('GET', 'meta/links?status=active', $ana)->json('data.total'));
    }

    public function test_las_puertas_de_crear_en_su_orden(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $root = $this->personWith(self::SUPER_KEYS, 'Root');
        $crear = fn (array $extra = []) => $this->integrations('POST', 'meta/links', $ana, $extra + ['clientRequestId' => (string) Str::uuid(), 'campaign' => self::campaignId(self::C1), 'target' => ['kind' => 'baseline']]);

        // Forma: 422 (también con la bandera apagada).
        $this->integrationsFlags(writes: false);
        $crear(['campaign' => self::C1])->assertStatus(422);
        $crear(['target' => ['kind' => 'version']])->assertStatus(422)->assertJsonValidationErrors('target.versionUuid');
        $crear(['target' => ['kind' => 'baseline', 'versionUuid' => (string) Str::uuid()]])->assertStatus(422)->assertJsonValidationErrors('target.versionUuid');
        $crear()->assertStatus(409)->assertJsonPath('code', 'integrations_writes_disabled');
        $this->integrationsFlags(writes: true);

        $crear()->assertStatus(409)->assertJsonPath('code', 'meta_not_configured')->assertJsonPath('reason', 'missing_access_token');
        $this->metaConfigured();
        $crear()->assertStatus(404)->assertJsonPath('code', 'campaign_not_found');
        $this->metaCampaign(self::ACCOUNTS[0], self::C1, 'Pauta');
        $crear(['target' => ['kind' => 'version', 'versionUuid' => (string) Str::uuid()]])->assertStatus(404)->assertJsonPath('code', 'version_not_found');
        $crear(['target' => ['kind' => 'version', 'versionUuid' => $this->versionIn($root, 'REJECTED')->uuid]])->assertStatus(409)
            ->assertJsonPath('code', 'version_not_linkable')->assertJsonPath('currentStatus', 'REJECTED');
        $crear(['target' => ['kind' => 'version', 'versionUuid' => $this->versionIn($root, 'PUBLISHED', integrity: false)->uuid]])->assertStatus(409)->assertJsonPath('code', 'integrity_mismatch');

        config(['ultron_studio.integrations.campaign_links.max_active' => 1]);
        $this->metaCampaign(self::ACCOUNTS[0], self::C2, 'Otra');
        $this->integrations('POST', 'meta/links', $ana, ['clientRequestId' => (string) Str::uuid(), 'campaign' => self::campaignId(self::C2), 'target' => ['kind' => 'baseline']])->assertCreated();
        $crear()->assertStatus(409)->assertJsonPath('code', 'campaign_links_limit')->assertJsonPath('max', 1);
        $this->assertSame(1, StudioCampaignLink::query()->count());
        $this->assertSame(0, StudioAuditEvent::query()->where('event', 'like', 'campaign_link.%')->where('subject_uuid', '<>', StudioCampaignLink::query()->value('uuid'))->count(), 'los rechazos no se auditan');
    }

    public function test_el_reintento_devuelve_la_de_la_primera_vez_aunque_meta_ya_no_este_configurado(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $bea = $this->personWith(self::ADMIN_KEYS, 'Bea');
        $this->metaConfigured();
        $this->metaCampaign(self::ACCOUNTS[0], self::C1, 'Pauta');
        $this->metaCampaign(self::ACCOUNTS[0], self::C2, 'Otra');
        $cuerpo = ['clientRequestId' => (string) Str::uuid(), 'campaign' => self::campaignId(self::C1), 'target' => ['kind' => 'baseline']];

        $uuid = $this->integrations('POST', 'meta/links', $ana, $cuerpo)->assertCreated()->json('data.link.uuid');
        config(['meta.ads.access_token' => null]);
        $this->integrations('POST', 'meta/links', $ana, $cuerpo)->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.link.uuid', $uuid);
        $this->integrations('POST', 'meta/links', $ana, ['campaign' => self::campaignId(self::C2)] + $cuerpo)->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        $this->integrations('POST', 'meta/links', $bea, $cuerpo)->assertStatus(409)->assertJsonPath('code', 'client_request_reused');
        $this->assertSame(1, StudioCampaignLink::query()->count());
    }

    public function test_una_asociacion_de_una_campana_que_dejo_de_estar_conectada_se_sigue_viendo(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $this->metaConfigured();
        $this->metaCampaign(self::ACCOUNTS[0], self::C1, 'Pauta');
        $this->integrations('POST', 'meta/links', $ana, ['clientRequestId' => (string) Str::uuid(), 'campaign' => self::campaignId(self::C1), 'target' => ['kind' => 'baseline']])->assertCreated();

        config(['meta.ads.ad_account_ids' => [self::ACCOUNTS[1]]]);
        $enlace = $this->integrations('GET', 'meta/links', $ana)->assertOk()->json('data.data.0');
        $this->assertFalse($enlace['campaign']['connected']);
        $this->assertNull($enlace['campaign']['currentName']);
        $this->assertSame('Pauta', $enlace['campaign']['nameAtLink']);
    }

    /** AS-1 (Enmienda 3): el motivo de desactivar no admite un teléfono ni un correo; una fecha, sí. */
    public function test_el_motivo_de_desactivar_rechaza_telefonos_y_correos(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $this->metaConfigured();
        $this->metaCampaign(self::ACCOUNTS[0], self::C1, 'Pauta');
        $uuid = (string) $this->integrations('POST', 'meta/links', $ana, ['clientRequestId' => (string) Str::uuid(), 'campaign' => self::campaignId(self::C1), 'target' => ['kind' => 'baseline']])
            ->assertCreated()->json('data.link.uuid');
        $eventos = StudioAuditEvent::query()->count();

        foreach (['+57 300 000 0280', '300-000-0280', '3000000280', 'juan@correo.co'] as $dato) {
            $r = $this->integrations('POST', "meta/links/{$uuid}/deactivate", $ana, ['reason' => "Lo pidió {$dato}"])->assertStatus(422);
            $this->assertSame([StudioCanaryPhone::CONTACT_DATA_MESSAGE], $r->json('errors.reason'), $dato);
            $this->assertStringNotContainsString($dato, $r->getContent());
        }
        $this->assertSame($eventos, StudioAuditEvent::query()->count(), 'sin evento');
        $this->assertSame('active', StudioCampaignLink::query()->where('uuid', $uuid)->value('status'));
        $this->integrations('POST', "meta/links/{$uuid}/deactivate", $ana, ['reason' => 'Pauta 2026-11-01 terminada'])->assertOk();
    }

    /** AS-2 (Enmienda 3): la ruta 40 se pagina en PHP; `page` lleva tope y nunca da 500. */
    public function test_la_pagina_de_campanas_tiene_tope_y_nunca_da_500(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $this->metaConfigured();
        $this->metaCampaign(self::ACCOUNTS[0], self::C1, 'Pauta');
        Log::spy();

        foreach (['9223372036854775807', '99999999999999999999', (string) (StudioCampaignLinks::CAMPAIGNS_PAGE_MAX + 1)] as $pagina) {
            $this->integrations('GET', "meta/campaigns?page={$pagina}", $ana)->assertStatus(422)->assertJsonValidationErrors('page');
        }
        $ultima = $this->integrations('GET', 'meta/campaigns?page='.StudioCampaignLinks::CAMPAIGNS_PAGE_MAX.'&per_page=50', $ana)->assertOk()->json('data.campaigns');
        $this->assertSame([], $ultima['data']);
        $this->assertSame(1, $ultima['total']);
        // Y el servicio por su cuenta, sin el controlador: el desplazamiento no desborda.
        $this->assertSame([], app(StudioCampaignLinks::class)->campaignsPage([], PHP_INT_MAX, 50)['campaigns']->items());
        $this->assertCount(1, app(StudioCampaignLinks::class)->campaignsPage([], 1, 50)['campaigns']->items());
        // Sin `production.ERROR`: un 422 no se informa como fallo.
        Log::shouldNotHaveReceived('error');
    }
}

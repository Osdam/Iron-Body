<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioCampaignLink;
use App\Models\UltronStudio\StudioVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * F-2 del Hito 7 (PERF7) en las campañas de Meta: la huella de cada versión se
 * calcula una vez por versión dentro de un listado (no una por asociación) y la
 * DTO de cada campaña del espejo se arma al paginar (no para las 500 filas). La
 * respuesta no cambia: cada asociación lleva la integridad de SU versión aunque
 * en el mismo listado haya versiones íntegras y adulteradas, y la lista recorrida
 * página a página es exactamente la lista entera.
 */
final class UltronStudioCampaignsPageH7Test extends TestCase
{
    use IntegrationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->integrationsFlags(writes: true);
        // `ageMinutes` se cuenta desde ahora: el reloj quieto entre petición y petición.
        $this->freezeTime();
    }

    public function test_each_link_keeps_the_integrity_of_its_own_version_within_one_listing(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $this->metaConfigured();
        $integra = $this->versionIn($ana, 'APPROVED');
        $adulterada = $this->versionIn($ana, 'APPROVED', integrity: false);
        $canario = $this->versionIn($ana, 'CANARY');

        // Intercaladas, para que una memoria con la clave equivocada mezcle versiones.
        $destinos = [$integra, $adulterada, $integra, null, $adulterada, $canario, $integra, $adulterada];
        $esperado = [];
        foreach ($destinos as $i => $v) {
            $campana = (string) (120000000000000100 + $i);
            $this->metaCampaign(self::ACCOUNTS[$i % 3], $campana, 'Campaña '.$i, $i % 2 === 0 ? 'ACTIVE' : 'PAUSED');
            $this->asociar($ana, self::ACCOUNTS[$i % 3], $campana, $v);
            $esperado[self::campaignId($campana)] = $v === null ? null : [(string) $v->uuid, $v->is($adulterada) ? false : true];
        }

        $campanas = $this->integrations('GET', 'meta/campaigns?linked=yes&per_page=50', $ana)->assertOk()->json('data.campaigns.data');
        $this->assertCount(count($destinos), $campanas);
        foreach ($campanas as $c) {
            $this->assertSame($esperado[$c['id']], self::destino($c['ultron']['link']['target']), 'campaña '.$c['name']);
        }

        $asociaciones = $this->integrations('GET', 'meta/links?per_page=50', $ana)->assertOk()->json('data.data');
        $this->assertCount(count($destinos), $asociaciones);
        foreach ($asociaciones as $l) {
            $this->assertSame($esperado[$l['campaign']['id']], self::destino($l['target']), 'asociación '.$l['uuid']);
        }
    }

    public function test_the_mirror_walked_page_by_page_is_exactly_the_whole_list(): void
    {
        $ana = $this->personWith(self::ADMIN_KEYS, 'Ana');
        $this->metaConfigured();
        $v = $this->versionIn($ana, 'APPROVED');
        foreach (range(0, 6) as $i) {
            $campana = (string) (120000000000000200 + $i);
            // Nombres con mayúsculas y dígitos (orden sin mayúsculas, enmascarado) y estados mezclados.
            $this->metaCampaign(self::ACCOUNTS[$i % 3], $campana, ['zeta 3001234821', 'Alfa', 'beta', null, 'Gamma 2', 'alfa', 'Delta'][$i], ['ACTIVE', 'PAUSED', null, 'ACTIVE', 'ARCHIVED', 'ACTIVE', 'PAUSED'][$i]);
            if ($i % 2 === 0) {
                $this->asociar($ana, self::ACCOUNTS[$i % 3], $campana, $i === 4 ? null : $v);
            }
        }

        $entera = $this->integrations('GET', 'meta/campaigns?per_page=50', $ana)->assertOk()->json('data.campaigns');
        $this->assertSame(7, $entera['total']);
        $porPaginas = [];
        foreach (range(1, 4) as $pagina) {
            $trozo = $this->integrations('GET', "meta/campaigns?per_page=2&page={$pagina}", $ana)->assertOk()->json('data.campaigns');
            $this->assertSame(7, $trozo['total']);
            $porPaginas = [...$porPaginas, ...$trozo['data']];
        }
        $this->assertSame($entera['data'], $porPaginas);
        $this->assertSame([], $this->integrations('GET', 'meta/campaigns?per_page=2&page=5', $ana)->assertOk()->json('data.campaigns.data'));

        // Y con filtros, que se aplican antes de paginar.
        $activas = $this->integrations('GET', 'meta/campaigns?status=ACTIVE&per_page=50', $ana)->json('data.campaigns.data');
        $this->assertSame(array_values(array_filter($entera['data'], fn (array $c): bool => $c['meta']['effectiveStatus'] === 'ACTIVE')), $activas);
        $asociadas = $this->integrations('GET', 'meta/campaigns?linked=yes&per_page=50', $ana)->json('data.campaigns.data');
        $this->assertSame(array_values(array_filter($entera['data'], fn (array $c): bool => $c['ultron']['link'] !== null)), $asociadas);
    }

    /** Una asociación activa, escrita como la deja el Studio (la API no deja asociar una versión adulterada). */
    private function asociar(Admin $quien, string $cuenta, string $campana, ?StudioVersion $v): void
    {
        StudioCampaignLink::query()->forceCreate([
            'uuid' => (string) Str::uuid(), 'client_request_id' => (string) Str::uuid(),
            'ad_account_id' => $cuenta, 'campaign_id' => $campana, 'campaign_name_at_link' => 'Campaña *** ',
            'target_kind' => $v === null ? StudioCampaignLink::TARGET_BASELINE : StudioCampaignLink::TARGET_VERSION,
            'version_id' => $v?->id, 'version_content_hash' => $v?->content_hash, 'status' => StudioCampaignLink::STATUS_ACTIVE,
            'created_by_admin_id' => $quien->id, 'created_by_name' => $quien->name, 'created_by_role' => $quien->role, 'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $target
     * @return array{0:string,1:bool}|null
     */
    private static function destino(array $target): ?array
    {
        return $target['kind'] === StudioCampaignLink::TARGET_BASELINE ? null : [$target['versionUuid'], $target['integrityOk']];
    }
}

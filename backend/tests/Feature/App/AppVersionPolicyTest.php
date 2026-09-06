<?php

namespace Tests\Feature\App;

use App\Models\AppVersionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Política de versión mínima de la app.
 *
 * LO QUE SE VIGILA
 * ----------------
 * Este endpoint puede dejar sin app a toda la base de socios de una vez. Los
 * dos errores que lo consiguen son exigir un build que la tienda todavía no
 * ofrece —el socio queda bloqueado y el botón de actualizar no lleva a
 * ninguna parte— y responder "actualiza" cuando en realidad falta la
 * configuración. Ambos se fijan aquí.
 */
class AppVersionPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function policy(string $platform, int $latest, int $minimum): AppVersionPolicy
    {
        return AppVersionPolicy::updateOrCreate(
            ['platform' => $platform],
            [
                'latest_build' => $latest,
                'minimum_build' => $minimum,
                'latest_version' => '9.9.9',
                'store_url' => "https://store.example/{$platform}",
            ]
        );
    }

    private function ask(string $platform, int $build)
    {
        return $this->getJson("/api/app/version-policy?platform={$platform}&build={$build}");
    }

    // ── Semántica ───────────────────────────────────────────────────────────

    /**
     * El ejemplo del contrato: latest=15, minimum=12.
     * 15 y 16 al día · 14 y 12 opcional · 11 obligatorio.
     */
    public function test_status_depends_on_the_build_numbers(): void
    {
        $p = $this->policy(AppVersionPolicy::ANDROID, latest: 15, minimum: 12);

        $this->assertSame(AppVersionPolicy::CURRENT, $p->statusFor(16));
        $this->assertSame(AppVersionPolicy::CURRENT, $p->statusFor(15));
        $this->assertSame(AppVersionPolicy::OPTIONAL, $p->statusFor(14));
        $this->assertSame(AppVersionPolicy::OPTIONAL, $p->statusFor(12));
        $this->assertSame(AppVersionPolicy::REQUIRED, $p->statusFor(11));
    }

    public function test_android_current_optional_and_required_over_http(): void
    {
        $this->policy(AppVersionPolicy::ANDROID, latest: 15, minimum: 12);

        $this->ask('android', 15)->assertOk()->assertJsonPath('data.status', 'current');
        $this->ask('android', 13)->assertOk()->assertJsonPath('data.status', 'optional_update');
        $this->ask('android', 11)->assertOk()->assertJsonPath('data.status', 'required_update');
    }

    public function test_ios_current_optional_and_required_over_http(): void
    {
        $this->policy(AppVersionPolicy::IOS, latest: 20, minimum: 18);

        $this->ask('ios', 20)->assertOk()->assertJsonPath('data.status', 'current');
        $this->ask('ios', 19)->assertOk()->assertJsonPath('data.status', 'optional_update');
        $this->ask('ios', 17)->assertOk()->assertJsonPath('data.status', 'required_update');
    }

    /**
     * Las tiendas aprueban cuando quieren. Atar ambas al mismo número obligaría
     * a bloquear a los usuarios de una por los tiempos de la otra.
     */
    public function test_platforms_are_independent(): void
    {
        $this->policy(AppVersionPolicy::ANDROID, latest: 30, minimum: 30);
        $this->policy(AppVersionPolicy::IOS, latest: 10, minimum: 1);

        $this->ask('android', 20)->assertJsonPath('data.status', 'required_update');
        $this->ask('ios', 20)->assertJsonPath('data.status', 'current');
    }

    // ── El error caro ───────────────────────────────────────────────────────

    /**
     * Exigir un build que la tienda no ofrece deja al socio sin salida: app
     * bloqueada y botón de actualizar hacia una versión inexistente. En vez de
     * confiar en que nadie se equivoque tecleando, el mínimo se limita al
     * último publicado.
     */
    public function test_an_impossible_minimum_forces_nobody(): void
    {
        // minimum 99 con latest 10: nadie puede instalar el 99, así que exigirlo
        // dejaría a toda la base bloqueada con un botón que no lleva a ninguna
        // parte. Un dedazo no puede tener ese alcance.
        $p = $this->policy(AppVersionPolicy::ANDROID, latest: 10, minimum: 99);

        $this->assertTrue($p->isMisconfigured());
        $this->assertSame(0, $p->effectiveMinimumBuild());
        $this->assertSame(AppVersionPolicy::OPTIONAL, $p->statusFor(9));
        $this->assertSame(AppVersionPolicy::OPTIONAL, $p->statusFor(1));
        $this->assertSame(AppVersionPolicy::CURRENT, $p->statusFor(10));

        $this->ask('android', 1)
            ->assertOk()
            ->assertJsonPath('data.status', 'optional_update')
            ->assertJsonPath('data.minimum_build', 0);
    }

    /**
     * Sin configuración NO se inventa un veredicto: un despiste no puede
     * convertirse en una app inutilizable para todo el mundo.
     */
    public function test_missing_policy_does_not_force_an_update(): void
    {
        // La migración deja sembradas ambas plataformas; aquí se prueba el caso
        // de que alguien las borre o de una plataforma nueva sin configurar.
        AppVersionPolicy::query()->delete();

        $this->ask('android', 5)
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'VERSION_POLICY_UNAVAILABLE')
            ->assertJsonMissingPath('data.status');
    }

    // ── El cliente no decide ────────────────────────────────────────────────

    /** Mandar `minimum_build` por query no cambia nada: manda el servidor. */
    public function test_client_cannot_override_the_policy(): void
    {
        $this->policy(AppVersionPolicy::ANDROID, latest: 15, minimum: 12);

        $this->getJson('/api/app/version-policy?platform=android&build=5'
            .'&minimum_build=1&latest_build=5&store_url=https://evil.example')
            ->assertOk()
            ->assertJsonPath('data.status', 'required_update')
            ->assertJsonPath('data.minimum_build', 12)
            ->assertJsonPath('data.store_url', 'https://store.example/android');
    }

    public function test_platform_is_validated(): void
    {
        $this->ask('windows', 10)->assertStatus(422);
        $this->getJson('/api/app/version-policy?platform=android')->assertStatus(422);
        $this->getJson('/api/app/version-policy?platform=android&build=abc')->assertStatus(422);
    }

    /** Público a propósito: hace falta antes de iniciar sesión. */
    public function test_endpoint_is_public(): void
    {
        $this->policy(AppVersionPolicy::ANDROID, latest: 10, minimum: 1);

        $this->ask('android', 10)->assertOk();
    }

    public function test_response_carries_the_store_url_and_no_internal_ids(): void
    {
        $this->policy(AppVersionPolicy::IOS, latest: 12, minimum: 11);

        $r = $this->ask('ios', 11)->assertOk();
        $r->assertJsonPath('data.store_url', 'https://store.example/ios');
        $r->assertJsonPath('data.latest_version', '9.9.9');
        $r->assertJsonMissingPath('data.id');
        $r->assertJsonMissingPath('data.updated_by_admin_id');
    }

    /** El estado que se despliega hoy no debe molestar a nadie. */
    public function test_shipped_defaults_are_inert(): void
    {
        foreach (AppVersionPolicy::platforms() as $platform) {
            $p = AppVersionPolicy::forPlatform($platform);
            $this->assertNotNull($p, "falta la política de {$platform}");
            $this->assertSame(
                AppVersionPolicy::CURRENT,
                $p->statusFor(10),
                "el build 10 publicado no puede recibir avisos en {$platform}"
            );
            $this->assertSame(1, $p->effectiveMinimumBuild());
        }
    }
}

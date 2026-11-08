<?php

namespace Tests\Feature\UltronStudio;

use App\Http\Controllers\Crm\UltronStudio\UltronStudioVoiceController as C;
use App\Http\Middleware\EnforceAdminAuthorization;
use App\Models\Admin;
use App\Models\UltronStudio\StudioVoiceAgent;
use App\Models\UltronStudio\StudioVoicePublication;
use App\Services\Voice\VoiceProviders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * CERO RED EN EL GOBIERNO DE LA VOZ (contrato H8, protección 3, E2-4 y puerta
 * 2; como `UltronStudioIntegrationsNoNetworkTest`): con las banderas por
 * defecto, recorrer las 15 rutas de voz del Studio y la 45 no saca ni una
 * petición, ni siquiera con la configuración de PRODUCCIÓN de hoy (E1-12:
 * ElevenLabs encendido y con credenciales, la fábrica devuelve los adaptadores
 * reales). Lo que cierra es la bandera de publicar (apagada) y que revertir y
 * apagar no tienen nada que revertir o apagar.
 */
class UltronStudioVoiceNoNetworkTest extends TestCase
{
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;
    use StudioVoiceFixtures;

    /** @return array<string, array{0:array<string,mixed>}> */
    public static function configuraciones(): array
    {
        return [
            'las banderas por defecto' => [[]],
            'la configuración de producción de E1-12' => [[
                'voice.elevenlabs.enabled' => true,
                'voice.elevenlabs.api_key' => 'sk_h8s_produccion_CENTINELA',
                'voice.elevenlabs.agent_id' => 'agent_h8s_verificacion',
                'voice.elevenlabs.webhook_secret' => 'wsec_h8s_produccion_CENTINELA',
                'voice.elevenlabs.tool_secret' => 'tsec_h8s_produccion_CENTINELA',
                'voice.elevenlabs.region' => 'default',
                'voice.elevenlabs.agent_phone_number_id' => 'phnum_h8s_produccion',
            ]],
        ];
    }

    #[DataProvider('configuraciones')]
    public function test_recorrer_las_rutas_de_voz_no_sale_a_la_red(array $config): void
    {
        $this->withoutMiddleware([ThrottleRequests::class, EnforceAdminAuthorization::class]);
        Http::preventStrayRequests();
        $this->importBaseline();
        config($config);
        $this->assertFalse(config('ultron_studio.voice.publishing_enabled'), 'la bandera de publicar nace apagada');
        if ($config !== []) {
            $this->assertSame('ELEVENLABS_UNVERIFIED', VoiceProviders::status()->state, 'la fábrica daría los adaptadores reales');
        }

        $todo = $this->personWith(['ultron.view', 'ultron.voice_manage', 'ultron.voice_publish', 'ultron.approve', 'ultron.edit_draft'], 'Root Todo');
        $otra = $this->personWith(['ultron.view', 'ultron.voice_manage', 'ultron.approve'], 'Otra Revisa');
        $v = $this->approvedVersion($todo, $otra, 'Versión');
        $p = $this->approvedProfile($otra, $todo);
        $uuid = (string) Str::uuid();

        $vistas = [];
        $peticiones = [
            ['GET', 'profiles', [], 200],
            ['GET', "profiles/{$p->uuid}", [], 200],
            ['POST', 'profiles', ['clientRequestId' => (string) Str::uuid(), 'label' => 'Otra voz', 'content' => self::profileContent()], 201],
            ['POST', "profiles/{$uuid}/submit", [], 404],
            ['POST', "profiles/{$uuid}/approve", [], 404],
            ['POST', "profiles/{$uuid}/reject", ['note' => 'No sirve para nada'], 404],
            ['POST', 'compile-preview', ['versionUuid' => $v->uuid, 'profileUuid' => $p->uuid], 200],
            ['GET', 'publications', [], 200],
            ['POST', 'publish', ['clientRequestId' => (string) Str::uuid(), 'versionUuid' => $v->uuid, 'profileUuid' => $p->uuid, 'expectedSpecHash' => self::specHashOf($v, $p)], 409],
            ['POST', 'promote', ['clientRequestId' => (string) Str::uuid(), 'evidenceHash' => str_repeat('a', 64), 'expectedSpecHash' => str_repeat('b', 64)], 409],
            ['POST', 'rollback', ['clientRequestId' => (string) Str::uuid(), 'scope' => 'canary', 'reason' => 'Revertir sin nada'], 409],
            ['POST', 'off', ['clientRequestId' => (string) Str::uuid(), 'reason' => 'Apagar sin nada'], 409],
            ['GET', 'canary-evidence', [], 200],
            ['GET', 'brakes', [], 200],
            ['POST', "brakes/{$uuid}/release", ['clientRequestId' => (string) Str::uuid(), 'note' => 'Soltar un freno que no existe'], null],
        ];
        foreach ($peticiones as [$metodo, $ruta, $cuerpo, $estado]) {
            $r = $this->voice($metodo, $ruta, $todo, $cuerpo);
            if ($estado !== null) {
                $this->assertSame($estado, $r->status(), "{$metodo} voice/{$ruta}: ".$r->getContent());
            }
            $vistas[] = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', '{uuid}', "{$metodo} voice/{$ruta}");
            $this->assertStringNotContainsString('CENTINELA', $r->getContent());
        }
        $this->assertSame('voice_publishing_disabled', $this->voice('POST', 'publish', $todo, ['clientRequestId' => (string) Str::uuid(), 'versionUuid' => $v->uuid, 'profileUuid' => $p->uuid, 'expectedSpecHash' => str_repeat('c', 64)])->json('code'));
        $this->integrations('GET', 'voice', $todo)->assertOk();

        // Se recorrieron TODAS las rutas de voz del Studio (la lista de la constante).
        $this->assertSame(array_map(static fn (array $r): string => "{$r[0]} {$r[1]}", C::ROUTES), $vistas);
        $this->assertCount(0, Http::recorded(), 'una ruta de voz salió a la red');
        $this->assertSame(0, StudioVoiceAgent::query()->where('purpose', 'publication')->count());
        $this->assertSame(0, StudioVoicePublication::query()->count());
    }

    /** @param  array<string, mixed>  $body */
    private function integrations(string $method, string $path, Admin $as, array $body = []): TestResponse
    {
        return $this->studio($method, 'integrations/'.ltrim($path, '/'), $as, $body);
    }
}

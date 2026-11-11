<?php

namespace Tests\Unit\Voice;

use App\Services\Voice\Dto\VoiceSecretList;
use App\Services\Voice\Dto\VoiceWebhookList;
use App\Services\Voice\ElevenLabs\ElevenLabsAgentDeployer;
use App\Services\Voice\NullVoiceWebhookObserver;
use App\Services\Voice\VoiceAdapters;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;
use Tests\Unit\Voice\Support\FreshHttpFake;

/**
 * La lectura del webhook post-llamada y de los secretos del workspace
 * (auditoría B-T5; contrato H8, D8-21, decisión (f)): `describeWebhooks` y
 * `describeSecrets`, siempre contra `Http::fake` y con
 * `Http::preventStrayRequests()`.
 *
 *  - Sin la bandera o sin todas las piezas: «desconocido» SIN RED (también el nulo).
 *  - UN GET cada una a su ruta (las que comprobó OPS8-c en la referencia
 *    oficial el 09-11-2026), la clave solo en `xi-api-key`, host oficial en
 *    `production`, sin redirecciones.
 *  - De los webhooks, solo id, URL, desactivado y autenticación (la URL, en
 *    memoria); de los secretos, solo sus ids. Nunca un valor de un secreto.
 *  - Cada fallo es un resultado (nunca lanza por la red).
 *  - Solo las llama la salud del webhook de la telefonía.
 */
class ElevenLabsWebhookObserverTest extends TestCase
{
    use FreshHttpFake;

    private const CLAVE = 'xi-centinela-WEBHOOK-0123456789';

    private const SIM = 'http://127.0.0.1:8096';

    private const URL_PROPIA = 'https://api.ironbody.test/api/webhooks/voice/elevenlabs/post-call';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->configurar();
    }

    /** @param  array<string, mixed>  $cambios */
    private function configurar(array $cambios = []): void
    {
        config(['voice.elevenlabs' => $cambios + [
            'enabled' => true, 'api_key' => self::CLAVE, 'agent_id' => 'agent_verify_0001', 'webhook_secret' => 'whsec-centinela',
            'region' => 'default', 'agent_phone_number_id' => 'phnum_0001', 'tool_secret' => 'tool-centinela', 'initiation_secret' => 'init-centinela',
            'base_url' => self::SIM, 'timeout_seconds' => 7,
        ]]);
    }

    /** @return array<string, mixed> la lista de webhooks como la da el proveedor */
    private static function webhooks(): array
    {
        return ['webhooks' => [
            ['name' => 'ironbody-voz-post-llamada', 'webhook_id' => 'wh_propio_0001', 'webhook_url' => self::URL_PROPIA, 'is_disabled' => false,
                'is_auto_disabled' => false, 'auth_type' => 'hmac', 'created_at_unix' => 1762800000, 'usage' => [['usage_type' => 'ConvAI Settings']]],
            ['name' => 'otro', 'webhook_id' => 'wh_ajeno_0002', 'webhook_url' => 'https://ajeno.example/hook', 'is_disabled' => true,
                'is_auto_disabled' => true, 'auth_type' => 'oauth2', 'most_recent_failure_error_code' => 500],
        ]];
    }

    public function test_sin_la_bandera_o_sin_una_pieza_es_desconocido_sin_red(): void
    {
        self::freshFake();
        foreach ([['enabled' => false], ['api_key' => null], ['agent_id' => null], ['webhook_secret' => null]] as $cambio) {
            $this->configurar($cambio);
            $this->assertSame('not_configured', (new ElevenLabsAgentDeployer)->describeWebhooks()->outcome);
            $this->assertSame('not_configured', (new ElevenLabsAgentDeployer)->describeSecrets()->outcome);
            $this->assertInstanceOf(NullVoiceWebhookObserver::class, VoiceAdapters::webhookObserver());
        }
        $this->assertSame(VoiceWebhookList::unknown()->toArray(), (new NullVoiceWebhookObserver)->describeWebhooks()->toArray());
        $this->assertSame(VoiceSecretList::unknown()->toArray(), (new NullVoiceWebhookObserver)->describeSecrets()->toArray());
        Http::assertNothingSent();
    }

    public function test_un_get_a_los_webhooks_y_de_cada_uno_solo_lo_que_se_compara(): void
    {
        $this->app['env'] = 'production';
        $this->assertInstanceOf(ElevenLabsAgentDeployer::class, VoiceAdapters::webhookObserver());
        self::freshFake(['https://api.elevenlabs.io/v1/workspace/webhooks' => Http::response(self::webhooks(), 200)]);

        $l = (new ElevenLabsAgentDeployer)->describeWebhooks();
        $this->assertSame(['observed', 200, null], [$l->outcome, $l->httpStatus, $l->reason]);
        $this->assertSame([
            ['id' => 'wh_propio_0001', 'url' => self::URL_PROPIA, 'disabled' => false, 'autoDisabled' => false, 'authType' => 'hmac'],
            ['id' => 'wh_ajeno_0002', 'url' => 'https://ajeno.example/hook', 'disabled' => true, 'autoDisabled' => true, 'authType' => 'oauth2'],
        ], $l->webhooks);
        $this->assertSame('wh_propio_0001', $l->find('wh_propio_0001')['id'] ?? null);
        $this->assertNull($l->find('wh_otro'));
        Http::assertSentCount(1);
        [$peticion] = Http::recorded()[0];
        $this->assertSame(['GET', 'https://api.elevenlabs.io/v1/workspace/webhooks', [self::CLAVE]], [$peticion->method(), $peticion->url(), $peticion->header('xi-api-key')]);
        // Lo que se vuelca no lleva URL ni la clave.
        $volcado = (string) json_encode($l->toArray());
        foreach ([self::URL_PROPIA, 'ajeno.example', self::CLAVE] as $s) {
            $this->assertStringNotContainsString($s, $volcado);
        }
    }

    public function test_un_get_a_los_secretos_y_solo_salen_sus_ids(): void
    {
        $this->app['env'] = 'production';
        self::freshFake(['https://api.elevenlabs.io/v1/convai/secrets*' => Http::response(['secrets' => [
            ['type' => 'stored', 'secret_id' => 'sec_herramientas_01', 'name' => 'ironbody-voz-herramientas', 'used_by' => ['tools' => []]],
            ['type' => 'stored', 'secret_id' => 'sec_iniciacion_02', 'name' => 'ironbody-voz-iniciacion', 'value' => 'NUNCA-DEBERIA-VENIR'],
        ], 'next_cursor' => null], 200)]);

        $s = (new ElevenLabsAgentDeployer)->describeSecrets();
        $this->assertSame(['observed', ['sec_herramientas_01', 'sec_iniciacion_02'], false, 200], [$s->outcome, $s->ids, $s->truncated, $s->httpStatus]);
        $this->assertTrue($s->has('sec_iniciacion_02'));
        $this->assertFalse($s->has('sec_iniciacion_0'));
        Http::assertSentCount(1);
        [$peticion] = Http::recorded()[0];
        $this->assertSame(['GET', 'https://api.elevenlabs.io/v1/convai/secrets?page_size=100', [self::CLAVE]], [$peticion->method(), $peticion->url(), $peticion->header('xi-api-key')]);
        $volcado = (string) json_encode($s->toArray()).json_encode($s).var_export($s, true);
        foreach (['NUNCA-DEBERIA-VENIR', 'ironbody-voz', self::CLAVE] as $x) {
            $this->assertStringNotContainsString($x, $volcado, 'ni valores ni nombres');
        }

        // Con más páginas, lo dice.
        self::freshFake(['*' => Http::response(['secrets' => [['type' => 'stored', 'secret_id' => 'sec_1', 'name' => 'x']], 'next_cursor' => 'cur_2'], 200)]);
        $this->assertTrue((new ElevenLabsAgentDeployer)->describeSecrets()->truncated);
    }

    public function test_cada_fallo_es_un_resultado(): void
    {
        $d = new ElevenLabsAgentDeployer;
        foreach ([[404, 'not_found'], [401, 'auth_failed'], [403, 'auth_failed'], [500, 'error'], [302, 'error'], [429, 'error']] as [$http, $resultado]) {
            self::freshFake(['*' => Http::response(['detail' => 'x'], $http)]);
            $this->assertSame([$resultado, $http, []], [($w = $d->describeWebhooks())->outcome, $w->httpStatus, $w->webhooks], "webhooks HTTP {$http}");
            $this->assertSame([$resultado, $http, []], [($s = $d->describeSecrets())->outcome, $s->httpStatus, $s->ids], "secretos HTTP {$http}");
        }
        // Ilegibles: no es JSON, falta la lista, la lista no es una lista o un elemento sin id legible.
        foreach (['no es json', ['otra' => []], ['webhooks' => ['a' => 1], 'secrets' => ['a' => 1]], ['webhooks' => [['webhook_url' => 'x']], 'secrets' => [['name' => 'x']]],
            ['webhooks' => [['webhook_id' => '../x']], 'secrets' => [['secret_id' => '../x']]], ['webhooks' => ['wh_1'], 'secrets' => ['sec_1']]] as $cuerpo) {
            self::freshFake(['*' => Http::response($cuerpo, 200)]);
            $this->assertSame(['error', 'invalid_response'], [$d->describeWebhooks()->outcome, $d->describeWebhooks()->reason], (string) json_encode($cuerpo));
            $this->assertSame(['error', 'invalid_response'], [$d->describeSecrets()->outcome, $d->describeSecrets()->reason], (string) json_encode($cuerpo));
        }
        // Campos sin forma: no se inventan (null), el webhook sigue contando.
        self::freshFake(['*' => Http::response(['webhooks' => [['webhook_id' => 'wh_1', 'webhook_url' => 5, 'is_disabled' => 'no', 'auth_type' => 'HMAC con espacios']]], 200)]);
        $this->assertSame([['id' => 'wh_1', 'url' => null, 'disabled' => null, 'autoDisabled' => null, 'authType' => null]], $d->describeWebhooks()->webhooks);

        self::freshFake(static fn () => throw new ConnectionException('sin conexión'));
        $this->assertSame(['unreachable', 'unreachable'], [$d->describeWebhooks()->outcome, $d->describeSecrets()->outcome]);
    }

    public function test_los_dto_rechazan_lo_que_no_tiene_forma(): void
    {
        $casos = [
            fn () => new VoiceWebhookList('raro', [], null, null),
            fn () => new VoiceWebhookList('error', [['id' => 'wh_1', 'url' => null, 'disabled' => null, 'autoDisabled' => null, 'authType' => null]], 500, null),
            fn () => new VoiceWebhookList('observed', [['id' => '../x', 'url' => null, 'disabled' => null, 'autoDisabled' => null, 'authType' => null]], 200, null),
            fn () => new VoiceWebhookList('observed', [['id' => 'wh_1']], 200, null),
            fn () => new VoiceWebhookList('not_configured', [], 200, null),
            fn () => new VoiceSecretList('observed', ['../x'], false, 200, null),
            fn () => new VoiceSecretList('error', ['sec_1'], false, 500, null),
            fn () => new VoiceSecretList('error', [], true, 500, null),
            fn () => new VoiceSecretList('observed', [], false, 200, 'invalid_response'),
        ];
        foreach ($casos as $i => $caso) {
            try {
                $caso();
                $this->fail("caso {$i}: aceptó algo sin forma");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Como el número (E5-2), la lectura del webhook tiene un solo llamador: la
     * salud del webhook de la telefonía. Y cada método pide su ruta, la de la
     * constante.
     */
    public function test_solo_la_salud_del_webhook_las_llama_y_cada_una_pide_su_ruta(): void
    {
        $llaman = [];
        $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS));
        foreach ($iterador as $f) {
            if ($f->getExtension() !== 'php') {
                continue;
            }
            $codigo = (string) file_get_contents($f->getPathname());
            if (preg_match('/->\s*describe(Webhooks|Secrets)\s*\(/', $codigo) === 1) {
                $llaman[] = substr($f->getPathname(), strlen(base_path()) + 1);
            }
        }
        $this->assertSame(['app/Services/Telephony/VoiceWebhookHealth.php'], $llaman);

        $desplegador = (string) file_get_contents(base_path('app/Services/Voice/ElevenLabs/ElevenLabsAgentDeployer.php'));
        $this->assertStringContainsString("public const WEBHOOKS_PATH = '/v1/workspace/webhooks';", $desplegador);
        $this->assertStringContainsString("public const SECRETS_PATH = '/v1/convai/secrets';", $desplegador);
        $this->assertSame(1, preg_match_all('/->get\(\$base\.self::WEBHOOKS_PATH\)/', $desplegador));
        $this->assertSame(1, preg_match_all('/->get\(\$base\.self::SECRETS_PATH, \[\'page_size\' => self::SECRETS_PAGE_SIZE\]\)/', $desplegador));
    }
}

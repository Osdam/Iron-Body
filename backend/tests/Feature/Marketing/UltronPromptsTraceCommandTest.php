<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Services\Marketing\Ultron\Prompts\UltronPromptBase;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tests\Unit\Marketing\Ultron\Prompts\PromptPublications;

/**
 * `php artisan ultron:prompt-trace` (Enmienda M-1 del H5; plan de motores §D).
 *
 * Lo que se fija: la forma de la traza (la de `status.engine.prompts` más
 * `studioConfig`); la oferta de ahora es la de `decide`; solo turnos de la
 * conversación canaria y de entrantes, del más antiguo al más reciente, como
 * mucho `--last`; sin ids de conversación ni de mensaje, sin textos y con
 * versiones y huellas a 8 caracteres; y SOLO LECTURA (ninguna escritura).
 */
class UltronPromptsTraceCommandTest extends TestCase
{
    use PromptPublications;
    use RefreshDatabase;

    private const VERSION_B = 'b0000000-0000-4000-8000-00000000000b';

    private MarketingConversation $canaria;

    private MarketingConversation $otra;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('marketing.ultron.studio_config', 'canary');
        config()->set('marketing.ultron.studio_prompts', 'canary');
        $this->canaria = $this->conversacion('3150536011');
        $this->otra = $this->conversacion('3150536012');
        config()->set('marketing.ultron.canary_conversation_id', $this->canaria->id);
    }

    public function test_la_traza_en_json(): void
    {
        $gobernados = array_replace(UltronPromptCompiler::BASELINE, ['persona.tone' => 'profesional', 'persona.maxChars' => 200]);
        $this->publicaEntrada('ultron-engine-config/2', self::parteV2(self::VERSION_B, $gobernados), null);
        $huella = UltronPromptCompiler::compile($gobernados)['bundleHash'];

        $this->accion($this->canaria, 1, '2026-11-14 15:00:10', null);
        $this->accion($this->canaria, 2, '2026-11-14 15:05:59', [
            'v' => 1, 'state' => 'legacy',
            'offered' => ['mode' => 'legacy', 'reason' => 'no_publication', 'version' => null, 'contentHash' => null, 'entry' => null, 'seq' => null, 'bundleHash' => null],
            'observed' => ['mode' => 'legacy', 'bundleHash' => UltronPromptBase::LEGACY_BUNDLE_HASH, 'reason' => 'offered_no_publication', 'flowRev' => 'S1', 'execution' => '901'],
        ]);
        $this->accion($this->canaria, 3, '2026-11-15 13:20:00', [
            'v' => 1, 'state' => 'applied',
            'offered' => ['mode' => 'studio', 'reason' => null, 'version' => self::VERSION_B, 'contentHash' => str_repeat('c', 64), 'entry' => 'e0000000-0000-4000-8000-00000000000e', 'seq' => 1, 'bundleHash' => $huella],
            'observed' => ['mode' => 'studio', 'bundleHash' => $huella, 'reason' => null, 'flowRev' => 'S1', 'execution' => '902'],
        ], ['v' => 1, 'env' => 'canary', 'state' => 'applied', 'entry' => 'e0000000-0000-4000-8000-00000000000e', 'seq' => 1, 'part' => 'canary', 'version' => self::VERSION_B, 'contentHash' => str_repeat('c', 64), 'reason' => null]);
        $this->accion($this->otra, 4, '2026-11-15 13:21:00', ['v' => 1, 'state' => 'applied', 'offered' => null, 'observed' => null]);
        $this->accion($this->canaria, 5, '2026-11-15 13:22:00', ['v' => 1, 'state' => 'applied', 'offered' => null, 'observed' => null], null, 'followup');

        [$traza, $salida, $escrituras] = $this->traza(['--json' => true]);

        $this->assertSame([], $escrituras, 'solo lectura');
        $this->assertSame(['key', 'studioConfig', 'canaryConfigured', 'base', 'compiler', 'legacyBundleHash', 'current', 'lastTurns'], array_keys($traza));
        $this->assertSame(['canary', 'canary', true, 'n8n-29de5119', 2, UltronPromptBase::LEGACY_BUNDLE_HASH], [$traza['key'], $traza['studioConfig'], $traza['canaryConfigured'], $traza['base'], $traza['compiler'], $traza['legacyBundleHash']]);
        $this->assertSame(['mode' => 'studio', 'version' => self::VERSION_B, 'bundleHash' => $huella, 'reason' => null], $traza['current'], 'la oferta de decide, ahora');

        $this->assertCount(3, $traza['lastTurns'], 'solo entrantes de la canaria');
        $this->assertSame(['2026-11-14T15:00Z', '2026-11-14T15:05Z', '2026-11-15T13:20Z'], array_column($traza['lastTurns'], 'at'), 'del más antiguo al más reciente, al minuto');
        $this->assertSame(['at' => '2026-11-14T15:00Z', 'declared' => null, 'offered' => null, 'observed' => null, 'state' => null], $traza['lastTurns'][0], 'un turno de antes: sin sello');
        $this->assertSame([
            'at' => '2026-11-15T13:20Z',
            'declared' => ['state' => 'applied', 'version8' => 'b0000000'],
            'offered' => ['mode' => 'studio', 'reason' => null, 'version8' => 'b0000000', 'bundle8' => substr($huella, 0, 8)],
            'observed' => ['mode' => 'studio', 'bundle8' => substr($huella, 0, 8), 'flowRev' => 'S1'],
            'state' => 'applied',
        ], $traza['lastTurns'][2]);

        // Sin ids, sin textos y sin huellas completas de los turnos.
        $this->assertStringNotContainsString('"conversation', $salida);
        $this->assertStringNotContainsString('wamid', $salida);
        $this->assertStringNotContainsString('REGISTRO PUBLICADO', $salida);
        $this->assertStringNotContainsString('3150536011', $salida);
        $this->assertStringNotContainsString('"execution', $salida);
        $this->assertSame(1, substr_count($salida, $huella), 'la huella completa solo en la oferta de ahora');
    }

    public function test_last_acota_y_se_queda_con_los_mas_recientes(): void
    {
        foreach (range(1, 5) as $i) {
            $this->accion($this->canaria, $i, '2026-11-15 13:0'.$i.':00', null);
        }

        [$traza] = $this->traza(['--json' => true, '--last' => 2]);
        $this->assertSame(['2026-11-15T13:04Z', '2026-11-15T13:05Z'], array_column($traza['lastTurns'], 'at'));

        [$traza] = $this->traza(['--json' => true, '--last' => 0]);
        $this->assertCount(1, $traza['lastTurns'], 'como mínimo uno');
    }

    public function test_sin_canario_nadie_recibe_prompts_y_no_hay_turnos(): void
    {
        config()->set('marketing.ultron.canary_conversation_id', null);
        $this->accion($this->canaria, 1, '2026-11-15 13:00:00', null);

        [$traza, , $escrituras] = $this->traza(['--json' => true]);

        $this->assertSame([], $escrituras);
        $this->assertFalse($traza['canaryConfigured']);
        $this->assertSame(['mode' => 'legacy', 'version' => null, 'bundleHash' => null, 'reason' => 'out_of_scope'], $traza['current']);
        $this->assertSame([], $traza['lastTurns']);
    }

    public function test_con_las_llaves_apagadas_no_lee_la_cadena(): void
    {
        config()->set('marketing.ultron.studio_prompts', 'off');
        $this->publicaEntrada('ultron-engine-config/2', self::parteV2(self::VERSION_B), null);
        $cadena = 0;
        DB::listen(function (QueryExecuted $q) use (&$cadena): void {
            $cadena += str_contains($q->sql, 'ultron_studio_') ? 1 : 0;
        });

        [$traza] = $this->traza(['--json' => true]);

        $this->assertSame('prompts_off', $traza['current']['reason']);
        $this->assertSame(0, $cadena);
    }

    public function test_la_salida_de_consola(): void
    {
        $this->accion($this->canaria, 1, '2026-11-15 13:00:00', [
            'v' => 1, 'state' => 'engine_fallback',
            'offered' => ['mode' => 'studio', 'reason' => null, 'version' => self::VERSION_B, 'contentHash' => null, 'entry' => null, 'seq' => 1, 'bundleHash' => str_repeat('d', 64)],
            'observed' => ['mode' => 'legacy', 'bundleHash' => UltronPromptBase::LEGACY_BUNDLE_HASH, 'reason' => 'hash_mismatch', 'flowRev' => 'S1', 'execution' => '9'],
        ]);

        $this->artisan('ultron:prompt-trace')
            ->expectsOutputToContain('huella Legacy '.UltronPromptBase::LEGACY_BUNDLE_HASH)
            ->expectsOutputToContain('decide ofrecería ahora a la canaria: legacy · motivo no_publication')
            ->expectsOutputToContain('engine_fallback')
            ->assertExitCode(0);
    }

    // ── Andamio ───────────────────────────────────────────────────────────────

    /**
     * @param  array<string,mixed>  $opciones
     * @return array{0:array<string,mixed>,1:string,2:list<string>} [traza, salida cruda, escrituras vistas]
     */
    private function traza(array $opciones): array
    {
        $escrituras = [];
        DB::listen(function (QueryExecuted $q) use (&$escrituras): void {
            if (preg_match('/^\s*(insert|update|delete|truncate|alter|create|drop)\b/i', $q->sql) === 1) {
                $escrituras[] = $q->sql;
            }
        });
        $codigo = Artisan::call('ultron:prompt-trace', $opciones);
        $salida = Artisan::output();
        $this->assertSame(0, $codigo);

        return [json_decode($salida, true, 32, JSON_THROW_ON_ERROR), $salida, $escrituras];
    }

    private function conversacion(string $telefono): MarketingConversation
    {
        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound', 'phone' => $telefono, 'meta_user_id' => '57'.$telefono,
            'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);

        return MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open', 'ai_enabled' => true, 'human_takeover' => false,
        ]);
    }

    /**
     * @param  array<string,mixed>|null  $sello  metadata.studio_prompts
     * @param  array<string,mixed>|null  $declarada  metadata.studio
     */
    private function accion(MarketingConversation $c, int $n, string $cuando, ?array $sello, ?array $declarada = null, string $fuente = 'inbound_message'): void
    {
        Carbon::setTestNow(Carbon::parse($cuando, 'UTC'));
        try {
            MarketingAiAction::create([
                'lead_id' => $c->lead_id, 'conversation_id' => $c->id, 'action_type' => 'reply', 'reason' => 'general_info',
                'confidence' => 0.9, 'status' => 'executed', 'source_type' => $fuente, 'source_event_id' => 1000 + $n,
                'idempotency_key' => 'wamid.T.'.$c->id.'.'.$n,
                'metadata' => array_filter(['message_id' => 1000 + $n, 'origin' => 'ultron', 'studio' => $declarada, 'studio_prompts' => $sello], fn ($v) => $v !== null),
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }
}

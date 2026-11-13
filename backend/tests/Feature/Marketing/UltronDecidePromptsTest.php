<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\Ultron\Prompts\UltronPromptBase;
use App\Services\Marketing\Ultron\Prompts\UltronPromptCompiler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Tests\Unit\Marketing\Ultron\Prompts\PromptPublications;

/**
 * E5 y E6 · `decide` Y LOS PROMPTS DE LA VERSIÓN PUBLICADA (Enmienda M-1 del
 * H5; plan de motores §B.5).
 *
 * E5 · SIN NEGOCIAR, NADA CAMBIA: con el reloj fijado, la respuesta entera de
 * `decide` (y por tanto el token, byte a byte) es la misma con las llaves
 * apagadas que encendidas y con una versión `/2` publicada; las claves de la
 * respuesta y de la carga del token son las de siempre; un `prompt_contract`
 * desconocido o de otro tipo es «no negociado» y nunca un 422; y no hay ni
 * una consulta al Studio.
 *
 * E6 · NEGOCIADO: la matriz `studio_config {off, canary, all}` ×
 * `studio_prompts {off, canary}` × conversación {canaria, otra, canario sin
 * configurar} × cabeza {ninguna, `/1`, `/2` válida, firma mala, base cambiada,
 * compilador cambiado, parte nula}, con el motivo esperado escrito como tabla
 * (§B.5); el modo `studio` entrega exactamente lo compilado y firmado; nunca
 * lanza (tabla inexistente, error de lectura, valores que no compilan, huella
 * que no cuadra); una sentencia sobre la cadena cuando se llega a leerla y
 * ninguna si no; el token con `sp` por debajo de 1 500 caracteres; y la
 * respuesta sin ids de conversación.
 */
class UltronDecidePromptsTest extends TestCase
{
    use PromptPublications;
    use RefreshDatabase;

    private const SECRET = 'test-internal-secret';

    private const VERSION_A = 'a0000000-0000-4000-8000-00000000000a';

    private const VERSION_B = 'b0000000-0000-4000-8000-00000000000b';

    /** Las claves de la respuesta de `decide` de hoy, en su orden. */
    private const CLAVES_DE_HOY = [
        'ok', 'source', 'decision', 'context', 'commercial_phase', 'allowed_transitions', 'allowed_tools',
        'allowed_staff_review_reasons', 'knowledge_version', 'decide_token', 'expires_at', 'superseded',
    ];

    /** Las claves de `studio_prompts`, en su orden (§B.5). */
    private const CLAVES_DE_LA_OFERTA = [
        'contract', 'mode', 'reason', 'base', 'compiler', 'bundle_hash', 'version', 'content_hash', 'entry', 'seq', 'roles',
    ];

    private MarketingConversation $conversation;

    private MarketingConversation $otra;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('marketing.ultron.enabled', true);
        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.ultron.payment_links_enabled', false);
        config()->set('marketing.ultron.studio_config', 'off');
        config()->set('marketing.ultron.studio_prompts', 'off');
        Http::fake();

        Plan::create([
            'name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true,
            'benefits' => json_encode(['Acceso completo']),
        ]);
        $this->conversation = $this->nuevaConversacion('01');
        $this->otra = $this->nuevaConversacion('02');
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);
    }

    // ── E5 · Sin negociar, la respuesta y el token son los de hoy ─────────────

    public function test_sin_negociar_la_respuesta_y_el_token_son_identicos_con_las_llaves_apagadas_y_encendidas(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-11 15:00:00', 'UTC'));
        try {
            $m = $this->inbound($this->conversation, 'hola, cuánto vale el mes?', 'wamid.E5');
            $hoy = $this->decide($m)->assertOk()->json();

            $this->assertSame(self::CLAVES_DE_HOY, array_keys($hoy));
            $carga = self::cargaDelToken($hoy['decide_token']);
            $this->assertSame(['c', 'm', 'p', 't', 'k', 'i', 'e', 'x'], array_keys($carga));
            $this->assertSame(['hints', 'payment_state'], array_keys($carga['x']));

            // Las dos llaves encendidas y una versión /2 publicada: sin negociar, nada cambia.
            config()->set('marketing.ultron.studio_config', 'all');
            config()->set('marketing.ultron.studio_prompts', 'canary');
            $this->publicaEntrada('ultron-engine-config/2', self::parteV2(self::VERSION_B, ['persona.tone' => 'profesional']), null);

            $consultas = $this->escuchaLaCadena();
            $this->assertSame($hoy, $this->decide($m)->assertOk()->json(), 'respuesta idéntica, token incluido');
            // (Con espacios alrededor sí negocia: el middleware TrimStrings de Laravel los quita antes del controlador.)
            foreach ([null, 'ultron-prompts/2', 'ULTRON-PROMPTS/1', 'ultron-prompts/1x', '', 1, ['ultron-prompts/1'], ['contract' => 'ultron-prompts/1'], true] as $valor) {
                $this->assertSame($hoy, $this->decide($m, ['prompt_contract' => $valor])->assertOk()->json(), 'prompt_contract = '.json_encode($valor).' no negocia');
            }
            $this->assertSame([], $consultas(), 'sin negociar, ni una consulta al Studio');
        } finally {
            Carbon::setTestNow();
        }
    }

    // ── E6 · La matriz ────────────────────────────────────────────────────────

    /** @return array<string, array{string, string, string, string}> */
    public static function matriz(): array
    {
        $casos = [];
        foreach (['off', 'canary', 'all'] as $config) {
            foreach (['off', 'canary'] as $prompts) {
                foreach (['canaria', 'otra', 'sin canario'] as $conversacion) {
                    foreach (['ninguna', '/1', '/2 válida', 'firma mala', 'base cambiada', 'compilador cambiado', 'parte nula'] as $cabeza) {
                        $casos["config={$config} · prompts={$prompts} · {$conversacion} · {$cabeza}"] = [$config, $prompts, $conversacion, $cabeza];
                    }
                }
            }
        }

        return $casos;
    }

    /**
     * Lo esperado, como tabla (§B.5): las llaves y el alcance primero, sin
     * consultar; después, la cabeza.
     */
    private static function esperado(string $config, string $prompts, string $conversacion, string $cabeza): string
    {
        return match (true) {
            $prompts !== 'canary' => 'prompts_off',
            $config === 'off' => 'studio_config_off',
            $conversacion !== 'canaria' => 'out_of_scope',
            default => [
                'ninguna' => 'no_publication',
                '/1' => 'declaration_v1',
                '/2 válida' => 'studio',
                'firma mala' => 'publication_signature_invalid',
                'base cambiada' => 'base_changed',
                'compilador cambiado' => 'compiler_changed',
                'parte nula' => 'part_legacy',
            ][$cabeza],
        };
    }

    #[DataProvider('matriz')]
    public function test_la_matriz_de_la_oferta(string $config, string $prompts, string $conversacion, string $cabeza): void
    {
        config()->set('marketing.ultron.studio_config', $config);
        config()->set('marketing.ultron.studio_prompts', $prompts);
        if ($conversacion === 'sin canario') {
            config()->set('marketing.ultron.canary_conversation_id', null);
        }
        $gobernados = ['persona.tone' => 'profesional', 'persona.maxChars' => 200];
        match ($cabeza) {
            'ninguna' => null,
            '/1' => $this->publicaEntrada('ultron-engine-config/1', self::parteV1(self::VERSION_B), self::parteV1(self::VERSION_A)),
            '/2 válida' => $this->publicaEntrada('ultron-engine-config/2', self::parteV2(self::VERSION_B, $gobernados), self::parteV2(self::VERSION_A)),
            'firma mala' => $this->publicaEntrada('ultron-engine-config/2', self::parteV2(self::VERSION_B, $gobernados), null, ['signature' => str_repeat('0', 64)]),
            'base cambiada' => $this->publicaEntrada('ultron-engine-config/2', self::parteV2(self::VERSION_B, $gobernados, ['base' => 'n8n-00000000']), null),
            // Lo firmado con el compilador 1 (cuatro gobernados, la misma huella) tras subir al 2 (requisito 6 de las reglas S-NNN).
            'compilador cambiado' => $this->publicaEntrada('ultron-engine-config/2', self::parteV2Compilador1(self::VERSION_B, $gobernados), null),
            'parte nula' => $this->publicaEntrada('ultron-engine-config/1', null, null),
        };
        $motivo = self::esperado($config, $prompts, $conversacion, $cabeza);

        $conv = $conversacion === 'otra' ? $this->otra : $this->conversation;
        $m = $this->inbound($conv, 'hola', 'wamid.M.'.$conv->id);
        $consultas = $this->escuchaLaCadena();
        $res = $this->decide($m, ['prompt_contract' => 'ultron-prompts/1'])->assertOk();

        $oferta = $res->json('studio_prompts');
        $this->assertIsArray($oferta);
        $this->assertSame(self::CLAVES_DE_LA_OFERTA, array_keys($oferta));
        $this->assertSame([...array_slice(self::CLAVES_DE_HOY, 0, -1), 'studio_prompts', 'superseded'], array_keys($res->json()));
        $this->assertSame(['ultron-prompts/1', 'n8n-29de5119', 3], [$oferta['contract'], $oferta['base'], $oferta['compiler']], 'el compilador 3 (Enmienda A4-1)');

        if ($motivo === 'studio') {
            $paquete = UltronPromptCompiler::compile(array_replace(UltronPromptCompiler::BASELINE, $gobernados));
            $this->assertSame('studio', $oferta['mode']);
            $this->assertNull($oferta['reason']);
            $this->assertSame($paquete['roles'], $oferta['roles'], 'exactamente lo compilado');
            $this->assertSame($paquete['bundleHash'], $oferta['bundle_hash']);
            $this->assertSame(UltronPromptBase::bundleHash($oferta['roles']), $oferta['bundle_hash'], 'la huella es la de lo que viaja');
            $this->assertSame([self::VERSION_B, hash('sha256', self::VERSION_B), 1], [$oferta['version'], $oferta['content_hash'], $oferta['seq']]);
            $this->assertIsString($oferta['entry']);
            $this->assertStringContainsString('REGISTRO PUBLICADO: profesional', $oferta['roles']['composer']);
        } else {
            $this->assertSame('legacy', $oferta['mode']);
            $this->assertSame($motivo, $oferta['reason']);
            foreach (['bundle_hash', 'version', 'content_hash', 'entry', 'seq', 'roles'] as $clave) {
                $this->assertNull($oferta[$clave], "{$clave} es null en legacy");
            }
        }

        // Lo ofrecido viaja firmado en el token, y el token cabe de sobra.
        $token = $res->json('decide_token');
        $this->assertLessThan(1500, strlen($token));
        $sp = self::cargaDelToken($token)['x']['sp'] ?? null;
        // Apartado 4 del cierre V2 (D-2): en `studio` con un tope distinto de la línea base, `n` (aquí 200); Legacy, sin la clave.
        $this->assertSame(['m' => $oferta['mode'], 'r' => $oferta['reason'], 'h' => $oferta['bundle_hash'], 'v' => $oferta['version'], 'c' => $oferta['content_hash'], 'e' => $oferta['entry'], 's' => $oferta['seq']] + ($oferta['mode'] === 'studio' ? ['n' => 200] : []), $sp);

        // Una sentencia sobre la cadena solo si se llegó a leerla (paso 4), y de lectura.
        $leeLaCadena = $prompts === 'canary' && $config !== 'off' && $conversacion === 'canaria';
        $sentencias = $consultas();
        $this->assertCount($leeLaCadena ? 1 : 0, $sentencias, 'sentencias sobre ultron_studio_publications');
        foreach ($sentencias as $sql) {
            $this->assertMatchesRegularExpression('/^\s*select\b/i', $sql);
        }

        // Sin ids de conversación ni de mensaje en la oferta: sus claves son las del contrato y nada más.
        $this->assertStringNotContainsString('"conversation', json_encode($oferta));
        $this->assertStringNotContainsString('"message', json_encode($oferta));
    }

    /** @return array<string, array{\Closure, string}> */
    public static function averias(): array
    {
        return [
            'tabla inexistente' => [fn (self $t) => Schema::drop('ultron_studio_publications'), 'publication_table_missing'],
            'error de consulta' => [fn (self $t) => Schema::table('ultron_studio_publications', fn (Blueprint $b) => $b->renameColumn('effective_json', 'effective_text')), 'publication_read_failed'],
            'huella de la fila alterada' => [fn (self $t) => $t->publicaEntrada('ultron-engine-config/2', self::parteV2(self::VERSION_B), null, ['effective_hash' => hash('sha256', 'otros')]), 'publication_hash_mismatch'],
            'contrato desconocido' => [fn (self $t) => $t->publicaEntrada('ultron-engine-config/3', self::parteV1(self::VERSION_B), null), 'publication_contract_unknown'],
            'parte /2 sin governed' => [fn (self $t) => $t->publicaEntrada('ultron-engine-config/2', self::parteV1(self::VERSION_B), null), 'publication_malformed'],
            'huella del paquete que no cuadra' => [fn (self $t) => $t->publicaEntrada('ultron-engine-config/2', self::parteV2(self::VERSION_B, ['persona.tone' => 'profesional'], ['bundleHash' => UltronPromptBase::LEGACY_BUNDLE_HASH]), null), 'bundle_mismatch'],
            'gobernados que no compilan' => [fn (self $t) => $t->publicaEntrada('ultron-engine-config/2', self::parteV2(self::VERSION_B, ['persona.maxChars' => 170], ['bundleHash' => str_repeat('a', 64)]), null), 'error'],
            'tono fuera del catálogo' => [fn (self $t) => $t->publicaEntrada('ultron-engine-config/2', self::parteV2(self::VERSION_B, ['persona.tone' => 'formal'], ['bundleHash' => str_repeat('a', 64)]), null), 'error'],
        ];
    }

    #[DataProvider('averias')]
    public function test_nunca_lanza_y_cae_a_legacy_con_su_motivo(\Closure $averia, string $motivo): void
    {
        config()->set('marketing.ultron.studio_config', 'canary');
        config()->set('marketing.ultron.studio_prompts', 'canary');
        $averia($this);

        $m = $this->inbound($this->conversation, 'hola', 'wamid.A');
        $res = $this->decide($m, ['prompt_contract' => 'ultron-prompts/1'])->assertOk();

        $this->assertSame(['legacy', $motivo, null], [$res->json('studio_prompts.mode'), $res->json('studio_prompts.reason'), $res->json('studio_prompts.roles')]);
        $this->assertSame('legacy', self::cargaDelToken($res->json('decide_token'))['x']['sp']['m']);
        // La conexión sigue sirviendo (en PostgreSQL, sin transacción abortada).
        $this->assertSame(1, MarketingMessage::query()->where('id', $m->id)->count());
    }

    public function test_la_canaria_recibe_su_parte_y_si_es_nula_la_del_resto(): void
    {
        config()->set('marketing.ultron.studio_config', 'canary');
        config()->set('marketing.ultron.studio_prompts', 'canary');
        $resto = self::parteV2(self::VERSION_A, ['persona.emojis' => false]);
        $this->publicaEntrada('ultron-engine-config/2', null, $resto);

        $m = $this->inbound($this->conversation, 'hola', 'wamid.R');
        $oferta = $this->decide($m, ['prompt_contract' => 'ultron-prompts/1'])->assertOk()->json('studio_prompts');

        $this->assertSame(['studio', self::VERSION_A, $resto['prompts']['bundleHash']], [$oferta['mode'], $oferta['version'], $oferta['bundle_hash']]);
        $this->assertStringContainsString('Sin emojis: ninguno, en ningun mensaje.', $oferta['roles']['composer']);
    }

    public function test_una_version_con_la_linea_base_ofrece_la_huella_legacy(): void
    {
        config()->set('marketing.ultron.studio_config', 'canary');
        config()->set('marketing.ultron.studio_prompts', 'canary');
        $this->publicaEntrada('ultron-engine-config/2', self::parteV2(self::VERSION_B), null);

        $m = $this->inbound($this->conversation, 'hola', 'wamid.L');
        $oferta = $this->decide($m, ['prompt_contract' => 'ultron-prompts/1'])->assertOk()->json('studio_prompts');

        $this->assertSame('studio', $oferta['mode']);
        $this->assertSame(UltronPromptBase::LEGACY_BUNDLE_HASH, $oferta['bundle_hash']);
        $this->assertSame(UltronPromptBase::roles(), $oferta['roles'], 'la etapa E3 del plan: todo el camino del Studio, cero cambio');
    }

    public function test_la_llave_solo_enciende_con_canary(): void
    {
        config()->set('marketing.ultron.studio_config', 'canary');
        $this->publicaEntrada('ultron-engine-config/2', self::parteV2(self::VERSION_B), null);
        $m = $this->inbound($this->conversation, 'hola', 'wamid.K');

        foreach (['all', 'on', 'true', '1', 'CANARY ', 'Canary'] as $valor) {
            config()->set('marketing.ultron.studio_prompts', $valor);
            $esperado = strtolower(trim($valor)) === 'canary' ? null : 'prompts_off';
            $this->assertSame($esperado, $this->decide($m, ['prompt_contract' => 'ultron-prompts/1'])->assertOk()->json('studio_prompts.reason'), "llave {$valor}");
        }
    }

    // ── Andamio ───────────────────────────────────────────────────────────────

    private function nuevaConversacion(string $n): MarketingConversation
    {
        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound',
            'phone' => '31505360'.$n, 'meta_user_id' => '57315053'.$n,
            'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);

        return MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
    }

    private function inbound(MarketingConversation $conv, string $body, string $wamid): MarketingMessage
    {
        return MarketingMessage::create([
            'conversation_id' => $conv->id,
            'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => $body, 'meta_message_id' => $wamid, 'status' => 'received',
        ]);
    }

    /** @param array<string,mixed> $extra */
    private function decide(MarketingMessage $m, array $extra = []): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $m->conversation_id, 'message_id' => $m->id,
        ] + $extra, ['Authorization' => 'Bearer '.self::SECRET]);
    }

    /** @return \Closure(): list<string> las sentencias que nombran la cadena, desde ahora */
    private function escuchaLaCadena(): \Closure
    {
        $vistas = [];
        DB::listen(function (QueryExecuted $q) use (&$vistas): void {
            if (str_contains(strtolower($q->sql), 'ultron_studio_')) {
                $vistas[] = $q->sql;
            }
        });

        return function () use (&$vistas): array {
            return $vistas;
        };
    }
}

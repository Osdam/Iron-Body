<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingAiAction;
use App\Models\MarketingAppointment;
use App\Models\MarketingConversation;
use App\Models\MarketingKnowledgeItem;
use App\Models\MarketingLead;
use App\Models\MarketingMessage;
use App\Models\Plan;
use App\Services\Marketing\CommercialPhaseMachine as P;
use App\Services\Marketing\SalesIntents;
use App\Services\Marketing\Ultron\Prompts\UltronPromptBase;
use App\Services\Marketing\Ultron\UltronCommitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\UltronTurnEvents;
use Tests\TestCase;
use Tests\Unit\Marketing\Ultron\Prompts\PromptPublications;

/**
 * E7 y E8 · `commit` Y EL SELLO DE LOS PROMPTS (Enmienda M-1 del H5; plan de
 * motores §B.6), sobre commits completos por HTTP (decide → commit), como los
 * hace n8n.
 *
 * E7 · SIN BLOQUE Y SIN NEGOCIAR, NADA CAMBIA: con las llaves encendidas y una
 * versión `/2` publicada, la respuesta a n8n es idéntica a la de las llaves
 * apagadas y ninguna fila lleva `metadata.studio_prompts`. El sello del H5
 * (`metadata.studio`) no cambia de forma (lo fija `UltronCommitStudioStampTest`).
 *
 * E8 · CON BLOQUE: se acepta; una clave desconocida dentro de `prompts` es 422
 * `unexpected_fields` (`prompts.x`) y un modo inválido, 422 de validación; los
 * siete estados de §B.6; el sello en TODAS las filas posteriores al token
 * (ejecutada, crítico fallido, rendición, hora de la visita) y en ninguna de
 * las bloqueadas antes; dos commits no lo comparten; el bloque no cambia la
 * respuesta; y el log no lleva textos.
 */
class UltronCommitPromptsTest extends TestCase
{
    use PromptPublications;
    use RefreshDatabase;
    use UltronTurnEvents;

    private const SECRET = 'test-internal-secret';

    private const BORRADOR_ENVENENADO = 'Listo, ya recibimos tu pago y quedas activo.';

    private const VERSION_B = 'b0000000-0000-4000-8000-00000000000b';

    private const OTRA_HUELLA = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private MarketingConversation $conversation;

    private int $mundo = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('marketing.ultron.enabled', true);
        config()->set('automation.internal_secret', self::SECRET);
        config()->set('meta.enabled', false);
        config()->set('marketing.ai.enabled', true);
        config()->set('marketing.ai.driver', 'fake');
        config()->set('marketing.ultron.payment_links_enabled', false);
        config()->set('marketing.ultron.studio_config', 'canary');
        config()->set('marketing.ultron.studio_prompts', 'canary');
        Http::fake();

        Plan::create([
            'name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true,
            'benefits' => json_encode(['Acceso completo']),
        ]);
        $this->nuevaConversacion();
        $this->publicaEntrada('ultron-engine-config/2', self::parteV2(self::VERSION_B, ['persona.tone' => 'profesional', 'persona.maxChars' => 200]), null);
    }

    // ── E7 · Sin bloque y sin negociar, nada cambia ───────────────────────────

    /** @return array<string, array{string}> */
    public static function caminos(): array
    {
        return [
            'ejecutada' => ['ejecutada'],
            'bloqueada por inelegible' => ['inelegible'],
            'bloqueada por relevo' => ['relevo'],
            'crítico fallido' => ['critico'],
            'rendición' => ['rendicion'],
        ];
    }

    #[DataProvider('caminos')]
    public function test_sin_bloque_ni_negociacion_la_respuesta_es_la_de_hoy_y_ninguna_fila_lleva_sello(string $camino): void
    {
        $respuestas = [];
        foreach (['off', 'canary'] as $llave) {
            $this->nuevaConversacion();
            config()->set('marketing.ultron.studio_prompts', $llave);

            $respuestas[$llave] = self::sinIds($this->recorre($camino, negocia: false)->assertOk()->json());
            $fila = $this->ultimaAccion();
            $this->assertArrayNotHasKey('studio_prompts', $fila->metadata, "{$camino} · {$llave}: la fila queda como hoy");
            $this->assertSame('applied', $fila->metadata['studio']['state'] ?? null, 'el sello del H5 sigue igual');
        }

        $this->assertSame($respuestas['off'], $respuestas['canary']);
        $this->assertStringNotContainsString('prompts', json_encode($respuestas['canary']));
    }

    // ── E8 · El bloque y los siete estados ────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function estados(): array
    {
        return [
            'applied' => ['applied'],
            'mismatch' => ['mismatch'],
            'engine_fallback' => ['engine_fallback'],
            'legacy' => ['legacy'],
            'legacy_drift' => ['legacy_drift'],
            'not_reported' => ['not_reported'],
            'unverified' => ['unverified'],
        ];
    }

    #[DataProvider('estados')]
    public function test_los_siete_estados_de_b6(string $estado): void
    {
        if ($estado === 'legacy') {
            config()->set('marketing.ultron.studio_prompts', 'off');
        }
        $m = $this->inbound('hola', 'wamid.ST');
        [$payload, $oferta] = $this->payload($m, negocia: true);
        $L = UltronPromptBase::LEGACY_BUNDLE_HASH;

        $bloque = match ($estado) {
            'applied' => self::bloque('studio', $oferta['bundle_hash']),
            'mismatch' => self::bloque('studio', self::OTRA_HUELLA),
            'engine_fallback' => self::bloque('legacy', $L, 'hash_mismatch'),
            'legacy' => self::bloque('legacy', $L, 'offered_prompts_off'),
            'legacy_drift' => self::bloque('legacy', self::OTRA_HUELLA, 'offered_part_legacy'),
            'not_reported' => null,
            'unverified' => self::bloque('legacy', null, 'selector_error'),
        };
        if ($bloque !== null) {
            $payload['prompts'] = $bloque;
        }

        $res = $this->commit($payload)->assertOk()->assertJsonPath('ok', true);
        $this->assertStringNotContainsString('prompts', json_encode($res->json()), 'el sello no viaja a n8n');

        $sello = $this->ultimaAccion()->metadata['studio_prompts'] ?? null;
        $this->assertIsArray($sello);
        $this->assertSame($estado, $sello['state']);
        $this->assertSello([
            'v' => 1,
            'state' => $estado,
            'offered' => [
                'mode' => $oferta['mode'], 'reason' => $oferta['reason'], 'version' => $oferta['version'], 'contentHash' => $oferta['content_hash'],
                'entry' => $oferta['entry'], 'seq' => $oferta['seq'], 'bundleHash' => $oferta['bundle_hash'],
            ],
            'observed' => $bloque === null ? null : [
                'mode' => $bloque['mode'], 'bundleHash' => $bloque['bundle_hash'], 'reason' => $bloque['reason'], 'flowRev' => 'S1', 'execution' => '4242',
            ],
        ], $sello);
        $this->assertStringNotContainsString('REGISTRO PUBLICADO', json_encode($sello), 'el sello no lleva textos');
    }

    public function test_el_bloque_no_cambia_lo_que_hace_el_commit(): void
    {
        $respuestas = [];
        foreach (['sin bloque', 'applied', 'mismatch', 'legacy_drift'] as $caso) {
            $this->nuevaConversacion();
            $m = $this->inbound('hola', 'wamid.R.'.$this->mundo);
            [$payload, $oferta] = $this->payload($m, negocia: true);
            if ($caso !== 'sin bloque') {
                $payload['prompts'] = self::bloque($caso === 'legacy_drift' ? 'legacy' : 'studio', $caso === 'applied' ? $oferta['bundle_hash'] : self::OTRA_HUELLA);
            }
            $respuestas[$caso] = self::sinIds($this->commit($payload)->assertOk()->json());
        }

        foreach ($respuestas as $caso => $r) {
            $this->assertSame($respuestas['sin bloque'], $r, $caso);
        }
    }

    #[DataProvider('caminos')]
    public function test_el_sello_va_en_toda_fila_posterior_al_token_y_en_ninguna_anterior(string $camino): void
    {
        $this->recorre($camino, negocia: true, bloque: true)->assertOk();

        $this->assertSame(1, MarketingAiAction::query()->where('conversation_id', $this->conversation->id)->count(), 'un commit, una fila');
        $sello = $this->ultimaAccion()->metadata['studio_prompts'] ?? null;
        if (in_array($camino, ['inelegible', 'relevo'], true)) {
            $this->assertNull($sello, "{$camino}: bloqueada antes del token, sin sello de prompts");
        } else {
            $this->assertSame('applied', $sello['state'] ?? null, $camino);
        }
    }

    /** El quinto camino que crea filas después del token: la hora de la visita (UltronCommitService §7.ter). */
    public function test_la_fila_de_la_hora_de_la_visita_tambien_lleva_el_sello(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-21 14:00', 'UTC'));   // un lunes a las 9 en Neiva
        try {
            config()->set('marketing.inbound.auto_analyze', false);
            MarketingKnowledgeItem::create([
                'category' => 'schedule', 'key' => 'schedule.opening_hours', 'title' => 'Horario',
                'content' => 'Lunes a viernes de 5:00 a. m. a 10:00 p. m. Sabados y domingos de 8:00 a. m. a 2:00 p. m.',
                'is_active' => true, 'origin' => MarketingKnowledgeItem::ORIGIN_HUMAN_PANEL,
                'review_status' => MarketingKnowledgeItem::REVIEW_APPROVED,
                'metadata' => ['windows' => [
                    'lunes' => ['05:00', '22:00'], 'martes' => ['05:00', '22:00'], 'miercoles' => ['05:00', '22:00'],
                    'jueves' => ['05:00', '22:00'], 'viernes' => ['05:00', '22:00'],
                    'sabado' => ['08:00', '14:00'], 'domingo' => ['08:00', '14:00'],
                ]],
            ]);
            $this->conversation->forceFill(['commercial_phase' => P::DISCOVERY])->save();

            $m1 = $this->inbound('quiero ir mañana a las 6 de la tarde', 'w.visita.1');
            [$p1, $o1] = $this->payload($m1, ['proposal' => [
                'strategy_goal' => 'book_visit', 'next_state' => P::DISCOVERY, 'intent' => SalesIntents::GENERAL_INFO,
                'reply_draft' => 'Listo, queda registrada tu solicitud de día de cortesía. El equipo la revisará.',
                'next_best_action' => 'offer_appointment', 'confidence' => 0.9,
                'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST],
                'courtesy_action' => 'request', 'courtesy_date' => '2026-09-22', 'courtesy_time' => '18:00',
            ]], negocia: true);
            $p1['prompts'] = self::bloque('studio', $o1['bundle_hash']);
            $this->commit($p1)->assertOk();
            $this->assertSame(1, MarketingAppointment::query()->count(), 'la visita quedó pedida');

            $m2 = $this->inbound('Mejor mañana a las 7', 'w.visita.2');
            [$p2, $o2] = $this->payload($m2, [
                'proposal' => [
                    'strategy_goal' => 'collect_data', 'next_state' => P::DISCOVERY, 'intent' => SalesIntents::GOAL_FAT_LOSS, 'next_best_action' => 'ask_discovery',
                    'main_barrier' => 'other', 'confidence' => 0.85, 'tools_requested' => [],
                    'courtesy_action' => null, 'courtesy_date' => null, 'courtesy_time' => null,
                    'reply_draft' => 'Perfecto, entonces te dejo registrada la visita de cortesía para mañana a las 7:00 a. m.',
                ],
                'critic' => ['verdict' => 'fail', 'attempt' => 2, 'score' => 0.3, 'issues' => ['context_use'], 'hard_fail' => null],
            ], negocia: true);
            $p2['prompts'] = self::bloque('legacy', UltronPromptBase::LEGACY_BUNDLE_HASH, 'hash_mismatch');
            $this->commit($p2)->assertOk();

            $filas = MarketingAiAction::query()->orderBy('id')->get();
            $this->assertCount(2, $filas);
            $this->assertSame('ask', data_get($filas[1]->metadata, 'visit_hour.mode'), 'el segundo turno fue el de la hora de la visita');
            $this->assertSame('applied', data_get($filas[0]->metadata, 'studio_prompts.state'));
            $this->assertSame('engine_fallback', data_get($filas[1]->metadata, 'studio_prompts.state'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_dos_commits_seguidos_con_la_misma_instancia_no_comparten_sello(): void
    {
        $servicio = app(UltronCommitService::class);

        $m1 = $this->inbound('hola', 'wamid.D1');
        [$p1, $o1] = $this->payload($m1, negocia: true);
        $p1['prompts'] = self::bloque('studio', $o1['bundle_hash']);
        $r1 = $servicio->commit($p1);

        $m2 = $this->inbound('y qué horarios tienen?', 'wamid.D2');
        [$p2] = $this->payload($m2, ['proposal' => ['reply_draft' => 'Te cuento los horarios. ¿Qué día te queda mejor?']], negocia: false);
        $r2 = $servicio->commit($p2);

        $this->assertSame('applied', MarketingAiAction::findOrFail($r1['ai_action_id'])->metadata['studio_prompts']['state']);
        $this->assertArrayNotHasKey('studio_prompts', MarketingAiAction::findOrFail($r2['ai_action_id'])->metadata, 'el segundo no hereda nada');
    }

    // ── E8 · La forma del bloque (fail-closed) ────────────────────────────────

    /** @return array<string, array{array<string,mixed>, string, string}> */
    public static function bloquesMalos(): array
    {
        $bien = self::bloque('studio', str_repeat('a', 64));

        return [
            'clave desconocida' => [$bien + ['x' => 1], 'unexpected_fields', 'prompts.x'],
            'el texto de un rol' => [$bien + ['roles' => ['composer' => 'texto']], 'unexpected_fields', 'prompts.roles'],
            'modo inválido' => [array_replace($bien, ['mode' => 'otro']), 'validation', 'prompts.mode'],
            'sin modo' => [array_diff_key($bien, ['mode' => 1]), 'validation', 'prompts.mode'],
            'sin flow_rev' => [array_diff_key($bien, ['flow_rev' => 1]), 'validation', 'prompts.flow_rev'],
            'huella en mayúsculas' => [array_replace($bien, ['bundle_hash' => str_repeat('A', 64)]), 'validation', 'prompts.bundle_hash'],
            'huella corta' => [array_replace($bien, ['bundle_hash' => 'abc']), 'validation', 'prompts.bundle_hash'],
            'motivo con espacios' => [array_replace($bien, ['reason' => 'texto libre del modelo']), 'validation', 'prompts.reason'],
            'motivo largo' => [array_replace($bien, ['reason' => str_repeat('a', 65)]), 'validation', 'prompts.reason'],
            'flow_rev largo' => [array_replace($bien, ['flow_rev' => str_repeat('S', 33)]), 'validation', 'prompts.flow_rev'],
            'ejecución con barra' => [array_replace($bien, ['execution_id' => '12/34']), 'validation', 'prompts.execution_id'],
            'ejecución larga' => [array_replace($bien, ['execution_id' => str_repeat('1', 65)]), 'validation', 'prompts.execution_id'],
            'bloque como texto' => ['studio', 'validation', 'prompts'],
        ];
    }

    /** @param array<string,mixed>|string $bloque */
    #[DataProvider('bloquesMalos')]
    public function test_un_bloque_fuera_de_contrato_es_un_422_y_no_escribe(array|string $bloque, string $tipo, string $campo): void
    {
        $m = $this->inbound('hola', 'wamid.F');
        [$payload] = $this->payload($m, negocia: true);
        $payload['prompts'] = $bloque;

        $res = $this->commit($payload)->assertStatus(422);
        if ($tipo === 'unexpected_fields') {
            $res->assertJsonPath('code', 'unexpected_fields')->assertJsonPath('fields', [$campo]);
        } else {
            $res->assertJsonValidationErrors($campo);
        }
        $this->assertSame(0, MarketingAiAction::query()->count(), 'un 422 de forma no deja fila');
    }

    public function test_un_bloque_null_es_como_no_mandarlo(): void
    {
        $m = $this->inbound('hola', 'wamid.N');
        [$payload] = $this->payload($m, negocia: true);
        $payload['prompts'] = null;

        $this->commit($payload)->assertOk();
        $this->assertSame('not_reported', $this->ultimaAccion()->metadata['studio_prompts']['state']);
    }

    // ── E8 · El log, sin textos ───────────────────────────────────────────────

    public function test_mismatch_y_legacy_drift_avisan_en_el_log_sin_textos_ni_huellas(): void
    {
        $log = $this->espiaElLog();
        foreach (['studio' => 'mismatch', 'legacy' => 'legacy_drift'] as $modo => $estado) {
            $this->nuevaConversacion();
            $m = $this->inbound('hola', 'wamid.L.'.$this->mundo);
            [$payload] = $this->payload($m, negocia: true);
            $payload['prompts'] = self::bloque($modo, self::OTRA_HUELLA);
            $this->commit($payload)->assertOk();

            $log->shouldHaveReceived('warning')->withArgs(function (string $evento, array $contexto) use ($estado): bool {
                $json = json_encode($contexto);

                return $evento === 'ultron.commit.studio_prompts'
                    && ($contexto['state'] ?? null) === $estado
                    && ($contexto['flow_rev'] ?? null) === 'S1'
                    && ! str_contains($json, self::OTRA_HUELLA)
                    && ! str_contains($json, 'REGISTRO')
                    && ! str_contains($json, 'Eres el');
            })->once();
        }
    }

    // ── Andamio ───────────────────────────────────────────────────────────────

    /** Un lead y una conversación nuevos, que además son el canario del motor. */
    private function nuevaConversacion(): void
    {
        $n = str_pad((string) ++$this->mundo, 2, '0', STR_PAD_LEFT);
        $lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound',
            'phone' => '31505360'.$n, 'meta_user_id' => '5731505360'.$n,
            'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);
    }

    /** Un commit de cada camino que crea fila. */
    private function recorre(string $camino, bool $negocia, bool $bloque = false): TestResponse
    {
        $w = 'wamid.'.$this->mundo.'.';
        $conBloque = function (array $par) use ($bloque): array {
            [$payload, $oferta] = $par;
            if ($bloque) {
                $payload['prompts'] = self::bloque('studio', $oferta['bundle_hash']);
            }

            return $payload;
        };

        switch ($camino) {
            case 'ejecutada':
                return $this->commit($conBloque($this->payload($this->inbound('hola', $w.'E'), negocia: $negocia)));

            case 'inelegible':
                $payload = $conBloque($this->payload($this->inbound('hola', $w.'I'), negocia: $negocia));
                $this->conversation->forceFill(['ai_enabled' => false])->save();

                return $this->commit($payload);

            case 'relevo':
                $payload = $conBloque($this->payload($this->inbound('hola', $w.'R1'), negocia: $negocia));
                $this->abreTurno($this->inbound('cuánto vale?', $w.'R2'));

                return $this->commit($payload);

            case 'critico':
                return $this->commit($conBloque($this->payload($this->inbound('hola', $w.'C'), [
                    'critic' => ['verdict' => 'fail', 'attempt' => 2, 'score' => 0.3, 'issues' => ['context_use'], 'hard_fail' => null],
                ], negocia: $negocia)));

            case 'rendicion':
                return $this->commit($conBloque($this->payload($this->inbound('me interesa, cómo hago para empezar?', $w.'S'), [
                    'proposal' => ['reply_draft' => self::BORRADOR_ENVENENADO],
                    'recovery' => ['reason' => 'machine_reply_payment_fact', 'attempt' => 2],
                ], negocia: $negocia)));
        }

        $this->fail("camino desconocido: {$camino}");
    }

    private function inbound(string $body, string $wamid): MarketingMessage
    {
        return MarketingMessage::create([
            'conversation_id' => $this->conversation->id,
            'direction' => MarketingMessage::DIRECTION_INBOUND,
            'sender_type' => MarketingMessage::SENDER_LEAD,
            'body' => $body, 'meta_message_id' => $wamid, 'status' => 'received',
        ]);
    }

    /**
     * El token sale del propio decide, como en producción; negociando o no.
     *
     * @param  array<string,mixed>  $overrides
     * @return array{0:array<string,mixed>,1:array<string,mixed>|null} [cuerpo del commit, oferta de decide]
     */
    private function payload(MarketingMessage $m, array $overrides = [], bool $negocia = false): array
    {
        $decide = $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $this->conversation->id, 'message_id' => $m->id,
        ] + ($negocia ? ['prompt_contract' => 'ultron-prompts/1'] : []), $this->headers())->assertOk();

        $payload = array_replace_recursive([
            'source_type' => 'inbound_message',
            'source_event_id' => $m->id,
            'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id,
            'decide_token' => $decide->json('decide_token'),
            'proposal' => [
                'strategy_goal' => 'collect_data',
                'next_state' => P::GOAL_DISCOVERY,
                'intent' => SalesIntents::GENERAL_INFO,
                'reply_draft' => 'Claro, te cuento. ¿Qué te gustaría lograr?',
                'recommended_plan_id' => null,
                'main_barrier' => null,
                'next_best_action' => 'ask_discovery',
                'confidence' => 0.82,
                'tools_requested' => [],
            ],
            'critic' => ['verdict' => 'pass', 'attempt' => 1, 'score' => 0.9],
        ], $overrides);

        return [$payload, $decide->json('studio_prompts')];
    }

    /** El bloque que manda el n8n S1 («Preparar Commit»). */
    private static function bloque(string $modo, ?string $huella, ?string $motivo = null): array
    {
        return ['mode' => $modo, 'bundle_hash' => $huella, 'reason' => $motivo, 'flow_rev' => 'S1', 'execution_id' => '4242'];
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
    }

    private function commit(array $payload): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/commit', $payload, $this->headers());
    }

    private function ultimaAccion(): MarketingAiAction
    {
        return MarketingAiAction::query()->where('conversation_id', $this->conversation->id)->latest('id')->firstOrFail();
    }

    /** En PostgreSQL `metadata` es `jsonb` (reordena claves): se compara sin el orden, con los tipos exactos. */
    private function assertSello(array $esperado, mixed $real): void
    {
        $ordena = function (mixed $v) use (&$ordena): mixed {
            if (! is_array($v)) {
                return $v;
            }
            ksort($v);

            return array_map($ordena, $v);
        };
        $this->assertIsArray($real);
        $this->assertSame($ordena($esperado), $ordena($real));
    }

    /** Los ids cambian de un mundo a otro; todo lo demás tiene que ser igual. */
    private static function sinIds(array $respuesta): array
    {
        foreach ($respuesta as $clave => $valor) {
            if (is_array($valor)) {
                $respuesta[$clave] = self::sinIds($valor);
            } elseif (in_array($clave, ['ai_action_id', 'message_id', 'provider_message_id', 'superseded_by'], true) && $valor !== null) {
                $respuesta[$clave] = '<id>';
            }
        }

        return $respuesta;
    }

    private function espiaElLog(): MockInterface
    {
        $spy = Log::spy();
        Log::shouldReceive('channel')->andReturn($spy);
        Log::shouldReceive('getFacadeRoot')->andReturn($spy);

        return $spy;
    }
}

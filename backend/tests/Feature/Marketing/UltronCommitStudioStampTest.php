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
use App\Services\Marketing\Ultron\UltronCommitService;
use App\Services\Marketing\Ultron\UltronPublishedConfig as Lector;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\UltronTurnEvents;
use Tests\TestCase;

/**
 * EL SELLO DE LA PUBLICACIÓN EN EL COMMIT DE ULTRON (contrato H5 §A4.5, §A4.6 y
 * §A9, prueba 2).
 *
 * Lo que se fija, sobre commits completos por HTTP (decide → commit), como los
 * hace n8n:
 *
 *  1. Con la llave en `off` ninguna fila lleva `metadata.studio` y la consulta
 *     no existe. Con `canary`/`all`, TODA fila que crea un commit lo lleva: la
 *     ejecutada, las bloqueadas (inelegible y relevo), la del crítico fallido,
 *     la rendición y la de la hora de la visita.
 *  2. Dos commits seguidos en el mismo proceso —y con la MISMA instancia del
 *     servicio— no comparten sello: cada uno lee la cabeza de su momento.
 *  3. La respuesta a n8n es idéntica con la llave en `off`, `canary` y `all`.
 *  4. Nunca lanza: con la tabla inexistente, una firma mala, un JSON roto, una
 *     huella alterada, un contrato desconocido o un error de consulta, el turno
 *     sale igual y la fila lleva `fallback` con su motivo. En PostgreSQL esto
 *     corre dentro de la transacción de la prueba: si la lectura fallida
 *     dejara abortada la transacción, el resto del commit moriría con 25P02.
 *  5. La frontera, escuchando cada sentencia del commit: con `off`, ninguna
 *     nombra `ultron_studio_`; con `canary`/`all`, exactamente una, que es un
 *     SELECT de `ultron_studio_publications`, y ninguna escritura del Studio.
 *
 * Las entradas van a la tabla REAL de la cadena (migraciones 2026_10_25_10000{1,2},
 * de solo inserción): cada prueba inserta las suyas en su transacción.
 */
class UltronCommitStudioStampTest extends TestCase
{
    use RefreshDatabase;
    use UltronTurnEvents;

    private const SECRET = 'test-internal-secret';

    private const TABLA = 'ultron_studio_publications';

    /** Un borrador que los guards del commit rechazan siempre: afirma un pago que nadie vio. */
    private const BORRADOR_ENVENENADO = 'Listo, ya recibimos tu pago y quedas activo.';

    private const VERSION_A = 'a0000000-0000-4000-8000-00000000000a';

    private const VERSION_B = 'b0000000-0000-4000-8000-00000000000b';

    private Plan $plan;

    private MarketingLead $lead;

    private MarketingConversation $conversation;

    private int $ultimoSeq = 0;

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
        config()->set('marketing.ultron.studio_config', Lector::ALL);
        Http::fake();

        $this->plan = Plan::create([
            'name' => 'Mensual', 'price' => 80000, 'duration_days' => 30, 'active' => true,
            'benefits' => json_encode(['Acceso completo']),
        ]);
        $this->nuevaConversacion();
    }

    // ── 1 · Presente o ausente según la llave, en TODAS las filas ─────────────

    #[DataProvider('caminos')]
    public function test_con_la_llave_en_off_ninguna_fila_lleva_sello(string $camino): void
    {
        config()->set('marketing.ultron.studio_config', Lector::OFF);
        $this->publica($this->parte(self::VERSION_B), $this->parte(self::VERSION_A));

        $this->recorre($camino)->assertOk();

        $accion = $this->ultimaAccion();
        $this->assertArrayNotHasKey('studio', $accion->metadata, "{$camino}: con la llave en off la fila queda como antes del Hito 5");
        $this->assertArrayHasKey('knowledge_version', $accion->metadata);
    }

    #[DataProvider('caminos')]
    public function test_toda_fila_que_crea_un_commit_lleva_el_sello(string $camino): void
    {
        $cabeza = $this->publica($this->parte(self::VERSION_B), $this->parte(self::VERSION_A));

        $this->recorre($camino)->assertOk();

        $this->assertSame(1, MarketingAiAction::query()->count(), 'un commit, una fila');
        $this->assertSello($this->aplicada($cabeza, Lector::ALL, 'canary', self::VERSION_B), $this->ultimaAccion()->metadata['studio'], $camino);
    }

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

    /** El quinto camino que crea filas: la hora de la visita la decide Laravel (UltronCommitService §7.ter). */
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
            $cabeza = $this->publica($this->parte(self::VERSION_B), null);

            // La visita: martes 22 a las 18:00.
            $m1 = $this->inbound('quiero ir mañana a las 6 de la tarde', 'w.visita.1');
            $this->commit($this->payload($m1, ['proposal' => [
                'strategy_goal' => 'book_visit', 'next_state' => P::DISCOVERY, 'intent' => SalesIntents::GENERAL_INFO,
                'reply_draft' => 'Listo, queda registrada tu solicitud de día de cortesía. El equipo la revisará.',
                'next_best_action' => 'offer_appointment', 'confidence' => 0.9,
                'tools_requested' => [SalesIntents::TOOL_COURTESY_REQUEST],
                'courtesy_action' => 'request', 'courtesy_date' => '2026-09-22', 'courtesy_time' => '18:00',
            ]]))->assertOk();
            $this->assertSame(1, MarketingAppointment::query()->count(), 'la visita quedó pedida');

            // «Mejor mañana a las 7»: dos lecturas dentro del horario → pregunta Laravel.
            $m2 = $this->inbound('Mejor mañana a las 7', 'w.visita.2');
            $this->commit($this->payload($m2, [
                'proposal' => [
                    'strategy_goal' => 'collect_data', 'next_state' => P::DISCOVERY, 'intent' => SalesIntents::GOAL_FAT_LOSS, 'next_best_action' => 'ask_discovery',
                    'main_barrier' => 'other', 'confidence' => 0.85, 'tools_requested' => [],
                    'courtesy_action' => null, 'courtesy_date' => null, 'courtesy_time' => null,
                    'reply_draft' => 'Perfecto, entonces te dejo registrada la visita de cortesía para mañana a las 7:00 a. m.',
                ],
                'critic' => ['verdict' => 'fail', 'attempt' => 2, 'score' => 0.3, 'issues' => ['context_use'], 'hard_fail' => null],
            ]))->assertOk();

            $filas = MarketingAiAction::query()->orderBy('id')->get();
            $this->assertCount(2, $filas);
            $this->assertSame('ask', data_get($filas[1]->metadata, 'visit_hour.mode'), 'el segundo turno fue el de la hora de la visita');
            foreach ($filas as $fila) {
                $this->assertSello($this->aplicada($cabeza, Lector::ALL, 'canary', self::VERSION_B), $fila->metadata['studio']);
            }
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_fuera_del_alcance_del_canario_el_sello_lo_dice(): void
    {
        // Sin canario del motor el commit atiende cualquier conversación; con la
        // llave en `canary`, ninguna es «la canaria».
        config()->set('marketing.ultron.canary_conversation_id', null);
        config()->set('marketing.ultron.studio_config', Lector::CANARY);
        $cabeza = $this->publica($this->parte(self::VERSION_B), $this->parte(self::VERSION_A));

        $this->recorre('ejecutada')->assertOk();

        $this->assertSello(
            ['v' => 1, 'env' => 'canary', 'state' => Lector::OUT_OF_SCOPE, 'entry' => $cabeza['uuid'], 'seq' => $cabeza['seq'], 'part' => null, 'version' => null, 'contentHash' => null, 'reason' => null],
            $this->ultimaAccion()->metadata['studio'],
        );
    }

    // ── 2 · Dos commits seguidos no comparten sello ───────────────────────────

    public function test_dos_commits_seguidos_con_la_misma_instancia_no_comparten_sello(): void
    {
        $servicio = app(UltronCommitService::class);

        $publicada = $this->publica($this->parte(self::VERSION_B), $this->parte(self::VERSION_A));
        $m1 = $this->inbound('hola', 'wamid.S1');
        $r1 = $servicio->commit($this->payload($m1));

        $legacy = $this->publica(null, null);   // alguien vuelve a Legacy entre los dos turnos
        $m2 = $this->inbound('y qué horarios tienen?', 'wamid.S2');
        $r2 = $servicio->commit($this->payload($m2, ['proposal' => ['reply_draft' => 'Te cuento los horarios. ¿Qué día te queda mejor?']]));

        config()->set('marketing.ultron.studio_config', Lector::OFF);
        $m3 = $this->inbound('gracias', 'wamid.S3');
        $r3 = $servicio->commit($this->payload($m3, ['proposal' => ['reply_draft' => 'Con gusto. ¿Te ayudo con algo más?']]));

        $s1 = MarketingAiAction::findOrFail($r1['ai_action_id'])->metadata['studio'];
        $s2 = MarketingAiAction::findOrFail($r2['ai_action_id'])->metadata['studio'];
        $this->assertSello($this->aplicada($publicada, Lector::ALL, 'canary', self::VERSION_B), $s1);
        $this->assertSello(
            ['v' => 1, 'env' => 'all', 'state' => Lector::LEGACY, 'entry' => $legacy['uuid'], 'seq' => $legacy['seq'], 'part' => null, 'version' => null, 'contentHash' => null, 'reason' => null],
            $s2,
            'el segundo commit leyó la cabeza de SU momento',
        );
        $this->assertArrayNotHasKey('studio', MarketingAiAction::findOrFail($r3['ai_action_id'])->metadata, 'y con la llave en off no hereda nada');
    }

    // ── 3 · La respuesta a n8n no cambia ──────────────────────────────────────

    #[DataProvider('caminos')]
    public function test_la_respuesta_del_commit_es_identica_con_la_llave_en_off_canary_y_all(string $camino): void
    {
        $respuestas = [];
        foreach ([Lector::OFF, Lector::CANARY, Lector::ALL] as $env) {
            $this->nuevaConversacion();
            config()->set('marketing.ultron.studio_config', $env);
            $this->publica($this->parte(self::VERSION_B), $this->parte(self::VERSION_A));

            $respuesta = $this->recorre($camino)->assertOk()->json();
            $this->assertStringNotContainsString('studio', json_encode($respuesta), 'el sello no viaja a n8n');
            $respuestas[$env] = self::sinIds($respuesta);

            $sello = $this->ultimaAccion()->metadata['studio'] ?? null;
            $this->assertSame($env === Lector::OFF ? null : Lector::APPLIED, $sello['state'] ?? null, "la fila de {$env} se selló como tocaba");
        }

        $this->assertSame($respuestas[Lector::OFF], $respuestas[Lector::CANARY]);
        $this->assertSame($respuestas[Lector::OFF], $respuestas[Lector::ALL]);
    }

    // ── 4 · Nunca lanza ───────────────────────────────────────────────────────

    /**
     * Con una fila que no se pudo verificar, el sello la nombra (sus columnas
     * `uuid` y `seq`, fixture del contrato R-3); si la lectura misma falló, no
     * nombra ninguna.
     */
    #[DataProvider('averias')]
    public function test_una_lectura_averiada_no_tumba_el_turno(string $averia, string $motivo): void
    {
        $fila = $this->averia($averia);

        $res = $this->recorre('ejecutada')->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('outcome', 'dry_run');

        $this->assertSame(1, MarketingMessage::query()->where('direction', MarketingMessage::DIRECTION_OUTBOUND)->count(), 'la persona recibe su respuesta');
        $this->assertSello(
            ['v' => 1, 'env' => 'all', 'state' => Lector::FALLBACK, 'entry' => $fila['uuid'] ?? null, 'seq' => $fila['seq'] ?? null, 'part' => null, 'version' => null, 'contentHash' => null, 'reason' => $motivo],
            MarketingAiAction::findOrFail($res->json('ai_action_id'))->metadata['studio'],
        );
        // La conexión sigue sirviendo después (en PostgreSQL: no quedó abortada).
        $this->assertSame(P::GOAL_DISCOVERY, $this->conversation->fresh()->commercial_phase);
    }

    /** @return array<string, array{string, string}> */
    public static function averias(): array
    {
        return [
            'tabla inexistente' => ['tabla inexistente', Lector::TABLE_MISSING],
            'error de consulta' => ['error de consulta', Lector::READ_FAILED],
            'firma mala' => ['firma mala', Lector::SIGNATURE_INVALID],
            'JSON roto' => ['JSON roto', Lector::MALFORMED],
            'huella alterada' => ['huella alterada', Lector::HASH_MISMATCH],
            'contrato desconocido' => ['contrato desconocido', Lector::CONTRACT_UNKNOWN],
        ];
    }

    // ── 5 · La frontera, sentencia a sentencia (contrato §A9, prueba 2) ───────

    #[DataProvider('fronteras')]
    public function test_el_commit_lee_una_sola_vez_el_studio_y_solo_con_la_llave(string $env, string $camino): void
    {
        config()->set('marketing.ultron.studio_config', $env);
        $this->publica($this->parte(self::VERSION_B), $this->parte(self::VERSION_A));
        $m = $this->inbound('hola', 'wamid.F1');
        $payload = $this->payload($m);
        if ($camino === 'inelegible') {
            $this->conversation->forceFill(['ai_enabled' => false])->save();
        }

        $sentencias = [];
        $niveles = [];
        DB::listen(function (QueryExecuted $q) use (&$sentencias, &$niveles): void {
            $sentencias[] = $q->sql;
            if (str_contains(strtolower($q->sql), 'ultron_studio_')) {
                $niveles[] = $q->connection->transactionLevel();
            }
        });
        $nivelDeLaPrueba = DB::transactionLevel();
        $this->commit($payload)->assertOk();

        $this->assertNotEmpty($sentencias, 'la escucha ve el commit');
        $delStudio = array_values(array_filter($sentencias, fn (string $sql) => str_contains(strtolower($sql), 'ultron_studio_')));

        if ($env === Lector::OFF) {
            $this->assertSame([], $delStudio, 'con la llave en off ninguna sentencia nombra el Studio');

            return;
        }
        $this->assertCount(1, $delStudio, 'una sola lectura del Studio por commit');
        $this->assertMatchesRegularExpression('/^\s*select\b/i', $delStudio[0]);
        $this->assertMatchesRegularExpression('/\bfrom\s+"?ultron_studio_publications"?\s/i', $delStudio[0]);
        $this->assertDoesNotMatchRegularExpression('/\b(insert|update|delete|truncate|alter|create|drop)\b/i', $delStudio[0]);

        // FUERA DE TODA TRANSACCIÓN DEL COMMIT (§A4.6): la lectura corre con la
        // transacción de la prueba (RefreshDatabase) más el punto de guardado del
        // propio lector, y nada más. Si el commit abriera una transacción antes de
        // leer, aquí saldría un nivel más.
        $this->assertSame([$nivelDeLaPrueba + 1], $niveles, 'el commit no abre ninguna transacción antes de leer el Studio');
    }

    /** @return array<string, array{string, string}> */
    public static function fronteras(): array
    {
        $casos = [];
        foreach ([Lector::OFF, Lector::CANARY, Lector::ALL] as $env) {
            foreach (['ejecutada', 'inelegible'] as $camino) {
                $casos["{$env} · {$camino}"] = [$env, $camino];
            }
        }

        return $casos;
    }

    // ── Andamio ───────────────────────────────────────────────────────────────

    /** Un lead y una conversación nuevos, que además son el canario del motor. */
    private function nuevaConversacion(): void
    {
        $n = ++$this->mundo;
        $this->lead = MarketingLead::create([
            'channel' => 'whatsapp', 'source' => 'inbound',
            'phone' => '31505360'.str_pad((string) $n, 2, '0', STR_PAD_LEFT), 'meta_user_id' => '5731505360'.str_pad((string) $n, 2, '0', STR_PAD_LEFT),
            'name' => 'Prospecto', 'status' => MarketingLead::STATUS_NEW,
        ]);
        $this->conversation = MarketingConversation::create([
            'lead_id' => $this->lead->id, 'channel' => 'whatsapp', 'status' => 'open',
            'ai_enabled' => true, 'human_takeover' => false,
        ]);
        config()->set('marketing.ultron.canary_conversation_id', $this->conversation->id);
    }

    /** Un commit de cada camino que crea fila. */
    private function recorre(string $camino): TestResponse
    {
        $w = 'wamid.'.$this->mundo.'.';

        switch ($camino) {
            case 'ejecutada':
                return $this->commit($this->payload($this->inbound('hola', $w.'E')));

            case 'inelegible':
                $payload = $this->payload($this->inbound('hola', $w.'I'));
                $this->conversation->forceFill(['ai_enabled' => false])->save();

                return $this->commit($payload);

            case 'relevo':
                $payload = $this->payload($this->inbound('hola', $w.'R1'));
                $this->abreTurno($this->inbound('cuánto vale?', $w.'R2'));   // el relevo tiene turno propio

                return $this->commit($payload);

            case 'critico':
                return $this->commit($this->payload($this->inbound('hola', $w.'C'), [
                    'critic' => ['verdict' => 'fail', 'attempt' => 2, 'score' => 0.3, 'issues' => ['context_use'], 'hard_fail' => null],
                ]));

            case 'rendicion':
                $m = $this->inbound('me interesa, cómo hago para empezar?', $w.'S');

                return $this->commit($this->payload($m, [
                    'proposal' => ['reply_draft' => self::BORRADOR_ENVENENADO],
                    'recovery' => ['reason' => 'machine_reply_payment_fact', 'attempt' => 2],
                ]));
        }

        $this->fail("camino desconocido: {$camino}");
    }

    /**
     * Prepara la avería y devuelve la fila insertada, o null si la avería es
     * de la lectura misma (tabla o columna que faltan).
     *
     * @return array<string,mixed>|null
     */
    private function averia(string $averia): ?array
    {
        $uuid = (string) Str::uuid();
        $seq = ++$this->ultimoSeq;
        $json = self::canonico($this->documento($uuid, $seq, $this->parte(self::VERSION_B), null));

        if ($averia === 'tabla inexistente') {
            Schema::drop(self::TABLA);

            return null;
        }
        if ($averia === 'error de consulta') {
            Schema::table(self::TABLA, fn (Blueprint $t) => $t->renameColumn('effective_json', 'effective_text'));

            return null;
        }

        return match ($averia) {
            'firma mala' => $this->inserta($json, ['uuid' => $uuid, 'seq' => $seq, 'signature' => str_repeat('0', 64)]),
            'JSON roto' => $this->inserta('{"canary":', ['uuid' => $uuid, 'seq' => $seq]),
            'huella alterada' => $this->inserta($json, ['uuid' => $uuid, 'seq' => $seq, 'effective_hash' => hash('sha256', 'otros bytes')]),
            'contrato desconocido' => (function () use ($json, $uuid, $seq): array {
                if (DB::connection()->getDriverName() === 'pgsql') {
                    DB::statement('ALTER TABLE '.self::TABLA.' DROP CONSTRAINT uspub_engine_contract_chk');
                }

                return $this->inserta($json, ['uuid' => $uuid, 'seq' => $seq, 'engine_contract' => 'ultron-engine-config/3']);
            })(),
        };
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.self::SECRET];
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

    /** El token sale del propio decide, como en producción. */
    private function payload(MarketingMessage $m, array $overrides = []): array
    {
        $token = $this->postJson('/api/internal/marketing/ai/decide', [
            'conversation_id' => $this->conversation->id, 'message_id' => $m->id,
        ], $this->headers())->assertOk()->json('decide_token');

        return array_replace_recursive([
            'source_type' => 'inbound_message',
            'source_event_id' => $m->id,
            'idempotency_key' => $m->meta_message_id,
            'conversation_id' => $this->conversation->id,
            'decide_token' => $token,
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
    }

    private function commit(array $payload): TestResponse
    {
        return $this->postJson('/api/internal/marketing/ai/commit', $payload, $this->headers());
    }

    private function ultimaAccion(): MarketingAiAction
    {
        return MarketingAiAction::query()->where('conversation_id', $this->conversation->id)->latest('id')->firstOrFail();
    }

    /** El sello esperado de una parte aplicada. */
    private function aplicada(array $fila, string $env, string $parte, string $version): array
    {
        return [
            'v' => 1, 'env' => $env, 'state' => Lector::APPLIED, 'entry' => $fila['uuid'], 'seq' => $fila['seq'],
            'part' => $parte, 'version' => $version, 'contentHash' => hash('sha256', $version), 'reason' => null,
        ];
    }

    /**
     * El sello leído de la fila. En PostgreSQL `metadata` es `jsonb`, que guarda
     * las claves en su propio orden (primero las cortas): se compara sin el orden
     * pero con los tipos exactos.
     */
    private function assertSello(array $esperado, mixed $real, string $mensaje = ''): void
    {
        $this->assertIsArray($real, $mensaje ?: 'la fila no lleva sello');
        ksort($esperado);
        ksort($real);
        $this->assertSame($esperado, $real, $mensaje);
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

    /** @return array<string,mixed> */
    private function parte(string $version): array
    {
        return [
            'approvedBy' => 12,
            'catalogVersion' => '2026-10-19.1',
            'contentHash' => hash('sha256', $version),
            'declared' => [
                'governance.canaryOnly' => true,
                'governance.enabled' => true,
                'intent.driver' => 'entorno',
                'rules.paymentLinksEnabled' => false,
            ],
            'publishedBy' => 7,
            'version' => $version,
        ];
    }

    /** @return array<string,mixed> */
    private function documento(string $uuid, int $seq, ?array $canary, ?array $rest): array
    {
        return ['canary' => $canary, 'contract' => Lector::CONTRACT, 'entry' => $uuid, 'issuedAt' => '2026-11-03T15:04:05Z', 'rest' => $rest, 'seq' => $seq];
    }

    /** El JSON como lo emite el Studio: claves ordenadas, sin escapar barras ni unicode. */
    private static function canonico(array $valor): string
    {
        $ordena = function (mixed $v) use (&$ordena): mixed {
            if (! is_array($v)) {
                return $v;
            }
            if (! array_is_list($v)) {
                ksort($v, SORT_STRING);
            }

            return array_map($ordena, $v);
        };

        return json_encode($ordena($valor), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private function publica(?array $canary, ?array $rest): array
    {
        $uuid = (string) Str::uuid();
        $seq = ++$this->ultimoSeq;

        return $this->inserta(self::canonico($this->documento($uuid, $seq, $canary, $rest)), ['uuid' => $uuid, 'seq' => $seq]);
    }

    /**
     * @param  array<string,mixed>  $sobre
     * @return array<string,mixed>
     */
    private function inserta(string $json, array $sobre): array
    {
        $legacy = ! str_contains($json, '"version"');
        $fila = array_merge([
            'client_request_id' => (string) Str::uuid(),
            'action' => $legacy ? 'legacy' : 'publish',
            'version_id' => null,
            'restores_publication_id' => null,
            // Hito 7 (`uspub_chain_next`): la entrada nueva apunta a la cabeza.
            'previous_publication_id' => DB::table(self::TABLA)->orderByDesc('seq')->value('id'),
            'engine_contract' => Lector::CONTRACT,
            'effective_json' => $json,
            'effective_hash' => hash('sha256', $json),
            'signature' => Lector::signatureFor($json),
            'catalog_version' => '2026-10-19.1',
            'lab_gate' => json_encode(['mode' => 'waived']),
            'acknowledged' => null,
            'evidence' => null,
            'reason' => $legacy ? 'Prueba del sello del commit' : null,
            'actor_admin_id' => 7,
            'actor_name' => 'Persona de pruebas',
            'actor_role' => 'Super Admin',
            'created_at' => now(),
        ], $sobre);

        DB::table(self::TABLA)->insert($fila);

        return $fila;
    }
}

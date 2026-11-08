<?php

namespace Tests\PerfH8;

require_once __DIR__.'/PerfH8Seeding.php';

use App\Jobs\Voice\PlaceOutboundVoiceCall;
use App\Models\Admin;
use App\Models\Voice\VoiceCall;
use App\Services\Telephony\OutboundGate;
use App\Services\Telephony\VoiceBrake;
use App\Services\Telephony\VoicePhone;
use App\Services\Telephony\VoicePublishedConfig;
use App\Services\Telephony\VoiceReconciler;
use App\Services\Twilio\TwilioRequestSignature;
use App\Services\UltronStudio\StudioAudit;
use App\Services\UltronStudio\StudioVersions;
use App\Services\UltronStudio\StudioVoiceEvidence;
use App\Services\UltronStudio\StudioVoicePublishing;
use App\Services\Voice\Dto\CarrierCall;
use App\Services\Voice\Dto\CarrierCallsPage;
use App\Services\Voice\Dto\CarrierResult;
use App\Services\Voice\Dto\OutboundCallResult;
use App\Services\Voice\Dto\VoiceAgentObservation;
use App\Services\Voice\Dto\VoiceAgentPayload;
use App\Services\Voice\Dto\VoiceDeployResult;
use App\Services\Voice\NullVoiceCallStarter;
use App\Services\Voice\TelephonyCarrier;
use App\Services\Voice\VoiceAgentDeployer;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\Event;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use ReflectionMethod;
use Tests\Feature\UltronStudio\IntegrationFixtures;
use Tests\Feature\UltronStudio\PublicationFixtures;
use Tests\Feature\UltronStudio\StudioFixtures;
use Tests\Feature\UltronStudio\StudioVoiceFixtures;
use Tests\TestCase;
use Throwable;

/**
 * PERF8 (Hito 8): arnés DETERMINISTA de consultas, filas, bytes, planes y
 * tiempo EN PROCESO de la voz y la telefonía, contra un PostgreSQL desechable
 * sembrado a escala (`PerfH8Seeding`). No es una prueba de la suite: vive fuera
 * de `tests/`, no afirma nada del producto y escribe JSON en `resultados/`.
 *
 * Se lanza con `run-queries.sh`: `seed` siembra `ultron_studio_h8_perf_seed`
 * una vez (migrate:fresh + siembra); cada familia corre en una COPIA nueva
 * (`CREATE DATABASE … TEMPLATE`), así que lo que escribe una medida no se ve
 * en la siguiente familia. Caché y cola `database` (como producción).
 *
 * Método por ruta (el del H7, PERF7):
 *  1. una petición de cebado y UNA perfilada con `DB::listen` (sentencias, por
 *     tabla, la normalizada más repetida) y, en modo consultas (PERF_N=0),
 *     `EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON)` de cada SELECT distinto con sus
 *     valores (y `EXPLAIN` sin ejecutar de cada UPDATE);
 *  2. si PERF_N > 0: PERF_WARM de calentamiento y PERF_REPS tandas de PERF_N
 *     cronometradas (hrtime) → p50, p95, máx por tanda y de todas.
 * Los LIMITADORES: en las rutas del CRM y del Studio, estado estable (el
 * contador vuelve a 5 antes de cada petición, como dentro de una ventana). En
 * las CUATRO PÚBLICAS la clave es por llamada (E6-4): cada iniciación y cada
 * post-llamada estrena cubo, así que se miden como llegan en producción, en su
 * «primer golpe» (sin tocar el contador).
 *
 * CERO RED: `Http::preventStrayRequests()`; el desplegador real solo contra
 * `Http::fake`; ElevenLabs y Twilio nunca.
 */
final class PerfH8QueriesTest extends TestCase
{
    use IntegrationFixtures, PerfH8Seeding, PublicationFixtures, StudioFixtures, StudioVoiceFixtures {
        PublicationFixtures::personWith insteadof IntegrationFixtures;
    }

    private bool $capturando = false;

    /** @var list<array{0:string,1:float,2:array}> */
    private array $sentencias = [];

    /** @var array{begin:int, commit:int, rollback:int} */
    private array $transacciones = ['begin' => 0, 'commit' => 0, 'rollback' => 0];

    /** @var list<array<string,mixed>> */
    private array $resultados = [];

    private string $familia = '';

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $db = (string) DB::connection()->getDatabaseName();
        if (DB::connection()->getDriverName() !== 'pgsql' || ! str_starts_with($db, 'ultron_studio_h8_perf')) {
            $this->fail("Base no permitida para el arnés: {$db}. Usa run-queries.sh (ultron_studio_h8_perf*, pgsql).");
        }
        if (config('cache.default') !== 'database') {
            $this->fail('El arnés exige CACHE_STORE=database (como producción): lanza con run-queries.sh.');
        }
        $this->mundoPerf();
        DB::listen(function (QueryExecuted $q): void {
            if ($this->capturando) {
                $this->sentencias[] = [$q->sql, (float) $q->time, $q->bindings];
            }
        });
        // BEGIN/SAVEPOINT y COMMIT/ROLLBACK no pasan por QueryExecuted (Laravel los manda por el PDO): se cuentan aparte,
        // porque cada uno es un viaje a la base (y el COMMIT, un fsync).
        Event::listen(TransactionBeginning::class, function (): void {
            if ($this->capturando) {
                $this->transacciones['begin']++;
            }
        });
        Event::listen(TransactionCommitted::class, function (TransactionCommitted $e): void {
            if ($this->capturando && $e->connection->transactionLevel() === 0) {
                $this->transacciones['commit']++;
            }
        });
        Event::listen(TransactionRolledBack::class, function (): void {
            if ($this->capturando) {
                $this->transacciones['rollback']++;
            }
        });
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        if ($this->resultados !== []) {
            $dir = (string) (getenv('PERF_OUT_DIR') ?: base_path('docs/ultron-studio/ops/perf-h8/resultados'));
            if (! is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $sufijo = $this->modoTiempos() ? 'tiempos' : 'consultas';
            $extra = $this->modoTiempos() ? [] : ['indexUsage' => $this->usoDeIndices()];
            file_put_contents(
                "{$dir}/{$this->familia}-{$sufijo}.json",
                json_encode(['family' => $this->familia, 'env' => self::entorno(), 'results' => $this->resultados] + $extra,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n",
            );
        }
        parent::tearDown();
    }

    // ── 0 · la siembra (una vez, en ultron_studio_h8_perf_seed) ─────────────

    public function test_00_siembra(): void
    {
        if (DB::connection()->getDatabaseName() !== 'ultron_studio_h8_perf_seed') {
            $this->markTestSkipped('La siembra solo corre en ultron_studio_h8_perf_seed (run-queries.sh seed).');
        }
        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]));
        $this->withoutMiddleware(ThrottleRequests::class);
        $recuentos = $this->sembrarTodo();

        // La siembra es coherente: la cadena se lee entera, el freno está suelto y la guarda acepta el cuerpo base.
        $partes = VoicePublishedConfig::read();
        $this->assertTrue($partes['canary']->ok(), 'la parte canary de la cadena sembrada no se lee: '.$partes['canary']->reason);
        $this->assertTrue($partes['rest']->ok(), 'la parte rest de la cadena sembrada no se lee: '.$partes['rest']->reason);
        $this->assertFalse(VoiceBrake::isOpen(), 'el freno quedó echado tras la siembra');

        $dir = (string) (getenv('PERF_OUT_DIR') ?: base_path('docs/ultron-studio/ops/perf-h8/resultados'));
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tamanos = DB::select("select relname as tabla, n_live_tup as filas, pg_total_relation_size(relid) as bytes from pg_stat_user_tables where relname like 'voice_%' or relname like 'ultron_studio_voice_%' or relname in ('cache', 'incidents') order by relname");
        file_put_contents("{$dir}/siembra.json", json_encode(['env' => self::entorno(), 'counts' => $recuentos, 'tables' => $tamanos,
            'canary' => ['seq' => $partes['canary']->seq, 'agent' => $partes['canary']->providerAgentId], 'rest' => ['seq' => $partes['rest']->seq, 'agent' => $partes['rest']->providerAgentId]],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
    }

    // ── A · iniciación (entrantes) ──────────────────────────────────────────

    public function test_a_iniciacion(): void
    {
        $this->familia = 'a-iniciacion';
        $sellado = (string) VoicePublishedConfig::read()['canary']->providerAgentId;
        $cuerpo = fn (string $llamante, ?string $sid = null, ?string $conv = null): string => json_encode([
            'caller_id' => $llamante, 'agent_id' => $sellado, 'called_number' => self::VOICE_FROM,
            'call_sid' => $sid ?? 'CA'.md5('perf-init-'.(++$this->n).'-'.Str::random(6)), 'conversation_id' => $conv ?? 'conv_perf_init_'.$this->n.'_'.Str::lower(Str::random(6)),
        ]);
        $cerrar = static function (string $body, TestResponse $r): void {
            $sid = json_decode($body, true)['call_sid'];
            $id = (int) VoiceCall::query()->where('provider_call_sid', $sid)->where('status', 'active')->value('id');
            if ($id > 0) {
                VoiceCall::transition($id, ['active'], 'completed', ['ended_at' => now(), 'duration_seconds' => 60]);
            }
        };
        $post = fn (string $body, ?string $secreto = self::INIT_SECRET): TestResponse => $this->crudo('/api/webhooks/voice/elevenlabs/initiation', $body,
            array_filter(['X-Voice-Initiation-Secret' => $secreto], static fn ($v) => $v !== null));

        // Con `CALLS_ENABLED_IN_CODE = false` (árbol compartido; nunca se gira aquí) la puerta deniega con ESE único motivo:
        // calcula todos los hechos (cadena, canario, freno, concurrencia, cupo) antes de decidir, así que las sentencias son las
        // del camino autorizado salvo el estado de la fila (`denied` en vez de `active`). Se exige que sea el ÚNICO motivo.
        $this->medir('POST initiation · canario (todo abierto salvo la constante)', 'canario', fn (string $b) => $post($b), [
            'preparar' => fn () => $cuerpo(self::CANARY_PHONES[0]), 'despues' => $cerrar, 'limpiar' => false,
            'comprobar' => function (TestResponse $r): void {
                $codigos = json_decode((string) DB::table('voice_call_events')->where('kind', 'initiation_received')->orderByDesc('id')->value('codes'), true);
                $this->assertSame(['calls_disabled_in_code'], $codigos['reasons'] ?? null, 'la iniciación del canario tiene otro motivo: '.json_encode($codigos));
                $this->assertSame(['active_voice', 'ok'], [$codigos['caller'] ?? null, $codigos['publication'] ?? null]);
            },
        ]);
        $this->medir('POST initiation · desconocido denegado', 'desconocido', fn (string $b) => $post($b), [
            'preparar' => fn () => $cuerpo('+573109990'.str_pad((string) (++$this->n % 1000), 3, '0', STR_PAD_LEFT)), 'limpiar' => false,
            'comprobar' => fn (TestResponse $r) => $this->assertSame('no', $r->json('dynamic_variables.caller_authorized')),
        ]);
        // La misma iniciación otra vez (reentrega de ElevenLabs): idempotente por call_sid. El cubo es el de esa llamada (10/min): se limpia.
        $repetida = $cuerpo(self::CANARY_PHONES[1]);
        $post($repetida)->assertOk();
        $this->medir('POST initiation · repetida (idempotente)', 'reentrega', fn (string $b) => $post($b), ['preparar' => fn () => $repetida, 'limpiar' => true]);
        $this->medir('POST initiation · secreto malo', '401', fn (string $b) => $post($b, 'secreto-que-no-vale-000000000000000000'), [
            'preparar' => fn () => $cuerpo(self::CANARY_PHONES[0]), 'estado' => 401, 'limpiar' => true,
        ]);
    }

    // ── B · post-llamada (ingesta del webhook firmado) ──────────────────────

    public function test_b_post_llamada(): void
    {
        $this->familia = 'b-post-llamada';
        $plantilla = $this->plantillaPostLlamada();
        $parte = VoicePublishedConfig::read()['canary'];
        // Típica (5 min, ~40 turnos), larga (200 turnos), ~100 KB y el PEOR CASO dentro del tope: ~1 MB con ~1 000 turnos
        // (el verificador corta en 1 000) y los del agente alargados a ~1,9 KB (lo que más trabajo da a la guarda).
        foreach ([['9 KB', 40, 0], ['40 KB', 200, 0], ['~100 KB', 200, 900], ['~1 MB', 1000, 1900]] as [$etiqueta, $turnos, $largo]) {
            $this->medir('POST post-call · transcripción', "{$etiqueta} · {$turnos} turnos", fn (array $p) => $this->postFirmado($p['body']), [
                'preparar' => function () use ($plantilla, $parte, $turnos, $largo): array {
                    $c = $this->entranteActiva($parte);

                    return ['call' => $c, 'body' => $this->cuerpoTranscripcion($plantilla, $c, $parte->providerAgentId, $turnos, $largo)];
                },
                'limpiar' => false,
                'comprobar' => function (TestResponse $r): void {
                    $this->assertSame('completed', $r->json('status'), 'la transcripción no completó la llamada: '.substr((string) $r->getContent(), 0, 300));
                    $this->assertSame(0, $r->json('violations'), 'la guarda vio violaciones: el cuerpo sembrado no es limpio');
                    $this->assertTrue($r->json('sealVerified'));
                },
                'bytesPeticion' => true,
            ]);
        }
        $this->assertFalse(VoiceBrake::isOpen(), 'una medida de la post-llamada echó el freno');

        // Fallo de iniciación de una saliente en `dialing` (comunicando).
        $this->medir('POST post-call · call_initiation_failure (busy)', 'saliente dialing', fn (array $p) => $this->postFirmado($p['body']), [
            'preparar' => function () use ($parte): array {
                $c = $this->salienteMarcando($parte);

                return ['call' => $c, 'body' => json_encode(['type' => 'call_initiation_failure', 'event_timestamp' => now()->getTimestamp(),
                    'data' => ['agent_id' => $parte->providerAgentId, 'conversation_id' => $c['conv'], 'failure_reason' => 'busy', 'user_id' => $c['ref'],
                        'metadata' => ['type' => 'twilio', 'body' => ['CallSid' => $c['sid'], 'CallStatus' => 'busy']]]])];
            },
            'limpiar' => false,
            'comprobar' => fn (TestResponse $r) => $this->assertSame('busy', $r->json('status'), substr((string) $r->getContent(), 0, 200)),
        ]);
        // Audio (se descarta) y duplicado (la misma transcripción otra vez; su cubo es el del evento: se limpia).
        $hecha = $this->entranteActiva($parte);
        $dup = $this->cuerpoTranscripcion($plantilla, $hecha, $parte->providerAgentId, 40, 0);
        $this->postFirmado($dup)->assertOk();
        $this->medir('POST post-call · duplicada', '8 KB repetida', fn (string $b) => $this->postFirmado($b), [
            'preparar' => fn () => $dup, 'limpiar' => true,
            'comprobar' => fn (TestResponse $r) => $this->assertTrue((bool) $r->json('duplicate')),
        ]);
        $this->medir('POST post-call · post_call_audio', 'descartado', fn (string $b) => $this->postFirmado($b), [
            'preparar' => fn () => json_encode(['type' => 'post_call_audio', 'event_timestamp' => now()->getTimestamp(),
                'data' => ['agent_id' => $parte->providerAgentId, 'conversation_id' => 'conv_perf_audio_'.(++$this->n), 'full_audio' => base64_encode(random_bytes(2048))]]),
            'limpiar' => false,
        ]);
        $this->medir('POST post-call · firma mala', '401', fn (string $b) => $this->crudo('/api/webhooks/voice/elevenlabs/post-call', $b,
            ['ElevenLabs-Signature' => 't='.now()->getTimestamp().',v0='.str_repeat('0', 64)]), [
                'preparar' => fn () => $dup, 'estado' => 401, 'limpiar' => true,
            ]);
    }

    // ── C · herramientas ────────────────────────────────────────────────────

    public function test_c_herramientas(): void
    {
        $this->familia = 'c-herramientas';
        $parte = VoicePublishedConfig::read()['canary'];
        foreach (['consultar_planes', 'consultar_horarios', 'solicitar_humano', 'no_volver_a_llamar'] as $herramienta) {
            // La PRIMERA de una llamada: cubo nuevo (primer golpe) y cupo 0 → 1; para no_volver_a_llamar, crea la exclusión.
            $this->medir("POST tools/{$herramienta}", 'primera de la llamada', fn (array $p) => $this->herramienta($herramienta, $p), [
                'preparar' => fn () => $this->entranteActiva($parte), 'limpiar' => false,
                'comprobar' => fn (TestResponse $r) => $this->assertTrue((bool) $r->json('ok'), substr((string) $r->getContent(), 0, 300)),
                'despues' => fn (array $c) => VoiceCall::transition($c['id'], ['active'], 'completed', ['ended_at' => now(), 'duration_seconds' => 90]),
            ]);
            // Las SIGUIENTES: misma llamada, cubo vivo; una llamada nueva cada 10 (el cupo de la base es 20).
            $actual = null;
            $usadas = 0;
            $this->medir("POST tools/{$herramienta}", 'siguiente (cupo 2–11 de 20)', fn (array $p) => $this->herramienta($herramienta, $p), [
                'preparar' => function () use (&$actual, &$usadas, $parte, $herramienta): array {
                    if ($actual === null || $usadas >= 10) {
                        if ($actual !== null) {
                            VoiceCall::transition($actual['id'], ['active'], 'completed', ['ended_at' => now(), 'duration_seconds' => 90]);
                        }
                        $actual = $this->entranteActiva($parte);
                        $this->herramienta($herramienta, $actual)->assertOk();
                        $usadas = 0;
                    }
                    $usadas++;

                    return $actual;
                },
                'limpiar' => true,
                'comprobar' => fn (TestResponse $r) => $this->assertTrue((bool) $r->json('ok'), substr((string) $r->getContent(), 0, 300)),
            ]);
        }
        $this->medir('POST tools/consultar_planes · Bearer malo', '401', fn (array $p) => $this->herramienta('consultar_planes', $p, 'Bearer otro-que-no-vale'), [
            'preparar' => fn () => ['conv' => 'conv_perf_x', 'sid' => 'CA'.md5('x'), 'ref' => (string) Str::uuid()], 'estado' => 401, 'limpiar' => true,
        ]);
    }

    // ── D · aviso de gasto de la subcuenta (abre el freno: familia aparte) ──

    public function test_d_aviso_twilio(): void
    {
        $this->familia = 'd-aviso-twilio';
        $plantilla = json_decode((string) file_get_contents(base_path('tests/fixtures/voice/twilio-webhook-bodies.json')), true)['usageTrigger'][0]['params'];
        $uri = '/api/webhooks/voice/twilio/usage-trigger';
        $firmar = static function (array $pares) use ($uri): array {
            $cuerpo = implode('&', array_map(static fn (array $p): string => rawurlencode($p[0]).'='.rawurlencode($p[1]), $pares));
            $firma = TwilioRequestSignature::compute(rtrim(self::WEBHOOK_BASE, '/').$uri, $pares, self::TWILIO_SIGNING);

            return [$cuerpo, $firma];
        };
        $nuevo = function () use ($plantilla, $firmar): array {
            $pares = array_map(fn (array $p): array => $p[0] === 'IdempotencyToken' ? [$p[0], 'AC8f-FIRES-PERF-'.(++$this->n).'-'.Str::random(8)] : $p, $plantilla);

            return $firmar($pares);
        };
        $post = fn (array $c): TestResponse => $this->crudo($uri, $c[0], ['Content-Type' => 'application/x-www-form-urlencoded', 'X-Twilio-Signature' => $c[1]]);
        $this->medir('POST twilio/usage-trigger', 'aviso nuevo (abre el freno)', $post, ['preparar' => $nuevo, 'limpiar' => false,
            'comprobar' => fn (TestResponse $r) => $this->assertTrue((bool) $r->json('persisted'), (string) $r->getContent())]);
        $repetido = $nuevo();
        $post($repetido)->assertOk();
        $this->medir('POST twilio/usage-trigger', 'repetido (idempotente)', $post, ['preparar' => fn () => $repetido, 'limpiar' => true,
            'comprobar' => fn (TestResponse $r) => $this->assertTrue((bool) $r->json('duplicate'))]);
        $this->medir('POST twilio/usage-trigger', 'firma mala', $post, ['preparar' => fn () => [$repetido[0], base64_encode(str_repeat("\0", 20))], 'estado' => 401, 'limpiar' => true]);
    }

    // ── E · CRM de telefonía ────────────────────────────────────────────────

    public function test_e_crm(): void
    {
        $this->familia = 'e-crm';
        $ana = $this->persona(self::P_ANA);
        $get = fn (string $ruta): TestResponse => $this->json('GET', '/api/admin/voice/'.$ruta, [], $this->as($ana));
        $conMasEventos = (string) DB::table('voice_calls as c')->join('voice_call_events as e', 'e.call_id', '=', 'c.id')
            ->groupBy('c.uuid')->orderByRaw('count(*) desc')->limit(1)->value('c.uuid');

        $this->medir('GET admin/voice/status', '5 000 llamadas', fn () => $get('status'));
        $this->medir('GET admin/voice/calls', 'por defecto (25)', fn () => $get('calls'));
        $this->medir('GET admin/voice/calls', 'perPage=100', fn () => $get('calls?perPage=100'));
        $this->medir('GET admin/voice/calls', 'status=completed', fn () => $get('calls?status=completed'));
        $this->medir('GET admin/voice/calls', 'status=hung_up (raro)', fn () => $get('calls?status=hung_up'));
        $this->medir('GET admin/voice/calls', 'direction=inbound', fn () => $get('calls?direction=inbound'));
        $this->medir('GET admin/voice/calls', 'page=150 (profunda)', fn () => $get('calls?page=150'));
        $this->medir('GET admin/voice/calls/{uuid}', 'la de más eventos', fn () => $get("calls/{$conMasEventos}"));
        $this->medir('GET admin/voice/metrics', 'hoy + 2 partes', fn () => $get('metrics'));
        $this->medir('GET admin/voice/contacts', 'por defecto (25)', fn () => $get('contacts'));
        $this->medir('GET admin/voice/contacts', 'perPage=100', fn () => $get('contacts?perPage=100'));

        // La PUERTA DE SALIENTES (POST calls): con la constante cerrada responde con sus motivos, pero calcula TODOS los hechos.
        $canario = (string) DB::table('ultron_studio_canary_numbers')->where('channel', 'voice')->where('status', 'active')->orderBy('id')->value('uuid');
        $contacto = (string) DB::table('voice_contacts')->where('source_type', 'lead')->orderBy('id')->value('uuid');
        $primero = null;
        foreach ([['canary', $canario, 'a un canario'], ['contact', $contacto, 'a un contacto con lead']] as [$tipo, $uuid, $etiqueta]) {
            $this->medir('POST admin/voice/calls (puerta de salientes)', $etiqueta, fn (string $crid) => $this->json('POST', '/api/admin/voice/calls',
                ['clientRequestId' => $crid, 'target' => ['kind' => $tipo, 'uuid' => $uuid]], $this->as($ana)), [
                    'preparar' => fn () => (string) Str::uuid(), 'estado' => 'igual',
                ]);
        }
    }

    // ── F · Studio de voz ───────────────────────────────────────────────────

    public function test_f_studio(): void
    {
        $this->familia = 'f-studio';
        // Las lecturas del laboratorio hablado exigen su bandera (409 `voice_lab_disabled` apagada); con el conductor
        // `fake` no hay ElevenLabs. Solo se leen sesiones: no se abre ninguna ni se gasta.
        config(['voice.lab.enabled' => true, 'voice.lab.driver' => 'fake']);
        $pablo = $this->persona(self::P_PABLO);
        $root = $this->persona(self::P_ROOT);
        $autora = $this->persona(self::P_AUTORA);
        [$v, $p] = $this->versionYPerfilReales(0);
        $perfilCualquiera = (string) DB::table('ultron_studio_voice_profiles')->where('label', 'Perfil perf real 1')->value('uuid');
        $sesion = (string) DB::table('ultron_studio_voice_lab_sessions')->orderByDesc('id')->value('uuid');

        $this->medir('GET integrations/voice (ruta 45)', 'Super Admin', fn () => $this->studio('GET', 'integrations/voice', $root));
        $this->medir('GET integrations/voice (ruta 45)', 'publicador', fn () => $this->studio('GET', 'integrations/voice', $pablo));
        $this->medir('GET voice/publications', '20 de 50', fn () => $this->studio('GET', 'voice/publications', $pablo),
            ['comprobar' => fn (TestResponse $r) => $this->todasVerificadas($r)]);
        $this->medir('GET voice/publications', 'per_page=50', fn () => $this->studio('GET', 'voice/publications?per_page=50', $pablo),
            ['comprobar' => fn (TestResponse $r) => $this->todasVerificadas($r)]);
        $this->medir('GET voice/canary-evidence', 'canario vigente', fn () => $this->studio('GET', 'voice/canary-evidence', $pablo));
        $this->medir('GET voice/brakes', '8 sueltos', fn () => $this->studio('GET', 'voice/brakes', $pablo));
        $this->medir('GET voice/profiles', '20', fn () => $this->studio('GET', 'voice/profiles', $autora));
        $this->medir('GET voice/profiles/{uuid}', 'real', fn () => $this->studio('GET', "voice/profiles/{$perfilCualquiera}", $autora));
        $this->medir('GET voice-lab/status', '300 sesiones', fn () => $this->studio('GET', 'voice-lab/status', $root));
        $this->medir('GET voice-lab/sessions', '20 de 300', fn () => $this->studio('GET', 'voice-lab/sessions', $root));
        $this->medir('GET voice-lab/sessions', 'per_page=50', fn () => $this->studio('GET', 'voice-lab/sessions?per_page=50', $root));
        $this->medir('GET voice-lab/sessions/{uuid}', 'una', fn () => $this->studio('GET', "voice-lab/sessions/{$sesion}", $root));

        $this->medir('POST voice/compile-preview', 'versión y perfil reales', fn () => $this->studio('POST', 'voice/compile-preview', $autora,
            ['versionUuid' => $v->uuid, 'profileUuid' => $p->uuid]));
        $this->tramosCompilacion($v, $p);

        // PUBLICAR con el desplegador FALSO (camino entero: crear → describir → asignar → firmar), una huella por vuelta.
        $fake = $this->bindFake();
        $i = 0;
        $this->medir('POST voice/publish', 'desplegador falso (camino entero)', fn (array $vp) => $this->publishVoice($pablo, $vp[0], $vp[1]), [
            'preparar' => function () use (&$i): array {
                return $this->versionYPerfilReales($i++ % self::REAL_PROFILES);
            },
            'estado' => 201, 'n' => min(20, (int) (getenv('PERF_N') ?: 0)), 'reps' => 1, 'warm' => 2,
        ]);

        // PUBLICAR con el ADAPTADOR REAL contra Http::fake: con la constante cerrada despliega y verifica, pero no asigna ni publica (409).
        $this->app->forgetInstance(StudioVoicePublishing::class);
        $this->app->forgetInstance(StudioVoiceEvidence::class);
        config(['voice.elevenlabs.enabled' => true, 'voice.elevenlabs.api_key' => 'sk_perf_h8_centinela_0001', 'voice.elevenlabs.agent_id' => 'agent_perf_verificacion',
            'voice.elevenlabs.region' => 'default', 'voice.elevenlabs.base_url' => 'https://el.perf-h8.test']);
        $herramientas = [];
        $agentes = [];
        Http::fake(function (ClientRequest $req) use (&$herramientas, &$agentes) {
            $ruta = (string) parse_url($req->url(), PHP_URL_PATH);
            if ($req->method() === 'POST' && $ruta === '/v1/convai/tools') {
                $id = 'tool_perf_'.(count($herramientas) + 1);
                $herramientas[$id] = $req->data()['tool_config'];

                return Http::response(['id' => $id]);
            }
            if ($req->method() === 'POST' && $ruta === '/v1/convai/agents/create') {
                $id = 'agent_perf_real_'.(count($agentes) + 1);
                $agentes[$id] = $req->data();

                return Http::response(['agent_id' => $id]);
            }
            if ($req->method() === 'GET' && str_starts_with($ruta, '/v1/convai/agents/')) {
                return Http::response($agentes[substr($ruta, strlen('/v1/convai/agents/'))] + ['version_id' => 'ver_1', 'branch_id' => 'br_1']);
            }
            if ($req->method() === 'GET' && str_starts_with($ruta, '/v1/convai/tools/')) {
                return Http::response(['id' => substr($ruta, strlen('/v1/convai/tools/')), 'tool_config' => $herramientas[substr($ruta, strlen('/v1/convai/tools/'))]]);
            }

            return Http::response(['detail' => 'ruta no esperada'], 599);
        });
        $antes = count(Http::recorded());
        $j = 0;
        $this->medir('POST voice/publish', 'adaptador real + Http::fake (constante cerrada → 409)', fn (array $vp) => $this->publishVoice($pablo, $vp[0], $vp[1]), [
            'preparar' => function () use (&$j): array {
                return $this->versionYPerfilReales($j++ % self::REAL_PROFILES);
            },
            'estado' => 409, 'n' => min(20, (int) (getenv('PERF_N') ?: 0)), 'reps' => 1, 'warm' => 2,
            'comprobar' => fn (TestResponse $r) => $this->assertSame('voice_number_not_assigned', $r->json('code'), (string) $r->getContent()),
        ]);
        $this->resultados[] = ['route' => 'POST voice/publish · peticiones HTTP salientes (Http::fake)', 'size' => 'todas las vueltas de esta familia',
            'outboundRequests' => count(Http::recorded()) - $antes, 'publishes' => $j, 'perPublish' => $j > 0 ? round((count(Http::recorded()) - $antes) / $j, 2) : null,
            'byPath' => array_count_values(array_map(static fn (array $par): string => $par[0]->method().' '.preg_replace('#/(agent|tool)_perf[_a-z]*_\d+#', '/{id}', (string) parse_url($par[0]->url(), PHP_URL_PATH)), array_slice(Http::recorded()->all(), $antes)))];
    }

    // ── G · los lectores calientes, a pelo ──────────────────────────────────

    public function test_g_lectores(): void
    {
        $this->familia = 'g-lectores';
        $beto = $this->persona(self::P_BETO);
        $this->lector('VoicePublishedConfig::read()', 'cadena de 50', static fn () => VoicePublishedConfig::read());
        $this->lector('VoiceBrake::isOpen()', '8 abiertos y sueltos', static fn () => VoiceBrake::isOpen());

        $canario = VoiceCall::query()->where('direction', 'outbound')->where('target_source', 'canary_registry')->where('status', 'completed')->orderByDesc('id')->firstOrFail();
        $contacto = VoiceCall::query()->where('direction', 'outbound')->where('target_source', 'voice_contacts')->where('status', 'completed')
            ->whereIn('voice_contact_id', DB::table('voice_contacts')->select('id')->where('source_type', 'lead'))->orderByDesc('id')->firstOrFail();
        $this->lector('OutboundGate::targetFacts(targetOf(...))', 'canario (descifra)', static fn () => OutboundGate::targetFacts(OutboundGate::targetOf($canario), OutboundGate::currentScope(), now(), $canario->remote_hash));
        $this->lector('OutboundGate::targetFacts(targetOf(...))', 'contacto con lead (descifra, consentimiento, RNE, lead)', static fn () => OutboundGate::targetFacts(OutboundGate::targetOf($contacto), 'contacts', now(), $contacto->remote_hash));

        // La cadena crece a 200 entradas (vueltas atrás al último agente) y el freno a 200 aperturas sueltas: ¿cambia algo?
        $ultimo = DB::table('ultron_studio_voice_agents')->where('purpose', 'publication')->orderByDesc('id')->first();
        $version = (string) DB::table('ultron_studio_versions')->where('id', $ultimo->studio_version_id)->value('uuid');
        $perfil = (string) DB::table('ultron_studio_voice_profiles')->where('id', $ultimo->voice_profile_id)->value('uuid');
        for ($k = 0; $k < 150; $k++) {
            $this->entradaDeVoz($beto, $k % 2 === 0 ? 'canary' : 'rest', (int) $ultimo->id, (string) $ultimo->provider_agent_id, $version, $perfil,
                (string) $ultimo->spec_hash, (string) $ultimo->payload_hash, CarbonImmutable::now()->subMinutes(150 - $k));
        }
        $this->assertTrue(VoicePublishedConfig::read()['canary']->ok());
        $this->lector('VoicePublishedConfig::read()', 'cadena de 200', static fn () => VoicePublishedConfig::read());
        for ($k = 0; $k < 192; $k++) {
            $a = VoiceBrake::open('guard_violation', 'guard:perf-g-'.$k, ['where' => 'perf_g']);
            VoiceBrake::release($beto, (string) $a->uuid, 'Revisado en la medida de rendimiento', (string) Str::uuid());
        }
        $this->assertFalse(VoiceBrake::isOpen());
        $this->lector('VoiceBrake::isOpen()', '200 abiertos y sueltos', static fn () => VoiceBrake::isOpen());
    }

    // ── H · el job de salientes y la conciliación ───────────────────────────

    public function test_h_job_y_conciliacion(): void
    {
        $this->familia = 'h-job-conciliacion';
        $ana = $this->persona(self::P_ANA);
        $parte = VoicePublishedConfig::read()['canary'];
        $canario = DB::table('ultron_studio_canary_numbers')->where('channel', 'voice')->where('status', 'active')->orderBy('id')->first();

        // 1. El job tal cual corre hoy (constante cerrada): vuelve a leer la llamada y la cancela con `calls_disabled_in_code`.
        $this->medirLlamable('PlaceOutboundVoiceCall::run() (constante cerrada)', 'cancela sin red', fn (int $id) => (new PlaceOutboundVoiceCall($id))->run(new NullVoiceCallStarter), [
            'preparar' => fn () => $this->salientePedida($ana, $parte, $canario),
            'comprobar' => fn ($r) => $this->assertSame('canceled', $r),
        ]);

        // 2. Lo que el job hace DETRÁS de la constante, sin girarla (reflexión, como las pruebas del árbol compartido):
        //    la revalidación (freno, cadena, deriva en caché, destino y calendario) y el registro del resultado.
        config(['voice.outbound.enabled' => true, 'voice.twilio.enabled' => true]);
        Cache::put(OutboundGate::DRIFT_CACHE_PREFIX.$parte->providerAgentId, false, OutboundGate::DRIFT_CACHE_SECONDS);
        $recheck = new ReflectionMethod(PlaceOutboundVoiceCall::class, 'recheckAfterCode');
        $this->medirLlamable('PlaceOutboundVoiceCall::recheckAfterCode() (reflexión)', 'deriva en caché', function (int $id) use ($recheck) {
            return $recheck->invoke(new PlaceOutboundVoiceCall($id), VoiceCall::query()->findOrFail($id));
        }, [
            'preparar' => fn () => $this->salientePedida($ana, $parte, $canario),
            'despues' => fn (int $id) => VoiceCall::transition($id, ['requested'], 'canceled', ['policy_reason' => 'outbound_disabled', 'ended_at' => now()]),
        ]);
        $registrar = new ReflectionMethod(PlaceOutboundVoiceCall::class, 'record');
        $this->medirLlamable('PlaceOutboundVoiceCall::record() (reflexión)', 'started → dialing', function (int $id) use ($registrar) {
            $r = new OutboundCallResult(OutboundCallResult::OUTCOME_STARTED, null, 'conv_perf_job_'.$id, 'CA'.md5('perf-job-'.$id), 200);

            return $registrar->invoke(new PlaceOutboundVoiceCall($id), VoiceCall::query()->findOrFail($id), $r);
        }, [
            'preparar' => fn () => $this->salientePedida($ana, $parte, $canario),
            'comprobar' => fn ($r) => $this->assertSame('dialing', $r),
            'despues' => fn (int $id) => VoiceCall::transition($id, ['dialing'], 'completed', ['ended_at' => now(), 'duration_seconds' => 30]),
        ]);

        // 3. voice:reconcile con 5 000 llamadas: el operador falso lista llamadas sembradas (cada vuelta, SIDs que no se han conciliado).
        $sids = DB::table('voice_calls')->whereNotNull('provider_call_sid')->whereNull('transfer_of_call_id')->orderBy('id')->pluck('provider_call_sid')->all();
        $cursor = 0;
        $deployer = new class implements VoiceAgentDeployer
        {
            public function createAgent(VoiceAgentPayload $payload): VoiceDeployResult
            {
                throw new \LogicException('sin uso');
            }

            public function describeAgent(string $agentId): VoiceAgentObservation
            {
                return new VoiceAgentObservation(VoiceAgentObservation::OUTCOME_UNREACHABLE, $agentId, null, null, null, null, null);
            }

            public function assignNumber(string $providerNumberId, ?string $agentId): VoiceDeployResult
            {
                throw new \LogicException('sin uso');
            }
        };
        // Cada vuelta lista SIDs que aún no se han conciliado; el 2 % son TRAMOS de transferencia (sin fila propia: su madre es
        // una llamada sembrada), que es el camino que recorre `ambiguousFor()` antes de encontrar a la madre.
        foreach ([[100, 1, 'primera pasada · 100 llamadas (1 página)'], [1000, 10, 'primera pasada · 1 000 llamadas (10 páginas)']] as [$cuantas, $paginas, $etiqueta]) {
            $vueltas = $cuantas === 100 ? max(1, min(20, (int) (getenv('PERF_N') ?: 1))) : 1;
            $this->medirLlamable('voice:reconcile (VoiceReconciler::run)', $etiqueta, fn (TelephonyCarrier $op) => (new VoiceReconciler)->run($op, $deployer, now()->subHours(48)), [
                'preparar' => function () use (&$cursor, $sids, $cuantas, $paginas): TelephonyCarrier {
                    $lote = [];
                    for ($i = 0; $i < $cuantas; $i++) {
                        $k = $cursor++;
                        if ($k % 50 === 49) {
                            $lote[] = new CarrierCall('CA'.md5('perf-tramo-nuevo-'.$k), $sids[($k * 7) % count($sids)], 'completed', 'outbound-dial', null, null, 45, '-0.0300', 'USD', '0099', '0090');
                        } else {
                            $lote[] = new CarrierCall($sids[$k % count($sids)], null, 'completed', 'outbound-api', null, null, 120, '-0.0520', 'USD', '1234', '0090');
                        }
                    }

                    return self::operadorFalso($lote, $paginas);
                },
                'n' => $vueltas, 'reps' => 1, 'warm' => 0,
                'comprobar' => fn (array $r) => $this->assertSame([0, false], [$r['unknown'], $r['costCap']], json_encode($r)),
            ]);
        }
        // Una pasada ya conciliada (todo duplicado), que es el caso de cada 15 min en régimen.
        $yaVistas = array_slice($sids, 0, 100);
        $this->medirLlamable('voice:reconcile (VoiceReconciler::run)', 'régimen · 100 ya conciliadas', fn (TelephonyCarrier $op) => (new VoiceReconciler)->run($op, $deployer, now()->subHours(48)), [
            'preparar' => fn () => self::operadorFalso(array_map(static fn (string $s): CarrierCall => new CarrierCall($s, null, 'completed', 'outbound-api', null, null, 120, '-0.0520', 'USD', '1234', '0090'), $yaVistas), 1),
            'n' => max(1, min(20, (int) (getenv('PERF_N') ?: 1))), 'reps' => 1, 'warm' => 1,
        ]);
    }

    // ── Medida ───────────────────────────────────────────────────────────────

    /**
     * @param  callable(mixed):TestResponse  $peticion
     * @param  array{preparar?: callable, despues?: callable, comprobar?: callable, estado?: int|string, limpiar?: bool, n?: int, reps?: int, warm?: int, bytesPeticion?: bool}  $o
     */
    private function medir(string $ruta, string $tamano, callable $peticion, array $o = []): void
    {
        $preparar = $o['preparar'] ?? static fn () => null;
        $despues = $o['despues'] ?? static fn () => null;
        $limpiar = $o['limpiar'] ?? true;
        $estado = $o['estado'] ?? 200;

        // Cebado: las filas de los limitadores y las cachés existen, como dentro de una ventana real.
        $p = $preparar();
        if ($limpiar) {
            $this->limpiarLimitadores();
        }
        $r0 = $peticion($p);
        $despues($p, $r0);
        if ($estado === 'igual') {
            $estado = $r0->getStatusCode();
        }

        $p = $preparar();
        if ($limpiar) {
            $this->limpiarLimitadores();
        }
        [$r, $sentencias] = $this->perfilada(fn () => $peticion($p));
        $this->assertSame($estado, $r->getStatusCode(), "{$ruta} [{$tamano}] → {$r->getStatusCode()}: ".substr((string) $r->getContent(), 0, 400));
        if (isset($o['comprobar'])) {
            ($o['comprobar'])($r);
        }
        $bytesPeticion = ($o['bytesPeticion'] ?? false) && is_array($p) && isset($p['body']) ? strlen((string) $p['body']) : (is_string($p) ? strlen($p) : null);
        $despues($p, $r);

        $fila = ['route' => $ruta, 'size' => $tamano, 'status' => $r->getStatusCode(), 'bytes' => strlen((string) $r->getContent()), 'requestBytes' => $bytesPeticion]
            + self::resumenSentencias($sentencias);
        $fila['transactions'] = $this->transacciones;
        if (! $this->modoTiempos()) {
            $fila['plans'] = $this->planes($sentencias);
        }
        $fila['timing'] = $this->cronometrar(function () use ($preparar, $peticion, $despues, $limpiar, $estado, $ruta): float {
            $p = $preparar();
            if ($limpiar) {
                $this->limpiarLimitadores();
            }
            $t = hrtime(true);
            $r = $peticion($p);
            $ms = (hrtime(true) - $t) / 1e6;
            if ($r->getStatusCode() !== $estado) {
                $this->fail("{$ruta}: respuesta {$r->getStatusCode()} durante el cronometraje: ".substr((string) $r->getContent(), 0, 300));
            }
            $despues($p, $r);

            return $ms;
        }, $o);
        $this->resultados[] = $fila;
    }

    /**
     * Una llamada PHP (job, conciliación, lectores) en vez de una petición.
     *
     * @param  array{preparar?: callable, despues?: callable, comprobar?: callable, n?: int, reps?: int, warm?: int}  $o
     */
    private function medirLlamable(string $ruta, string $tamano, callable $llamada, array $o = []): void
    {
        $preparar = $o['preparar'] ?? static fn () => null;
        $despues = $o['despues'] ?? static fn () => null;
        if (($o['warm'] ?? 1) > 0) {
            $p = $preparar();
            $despues($p, $llamada($p));
        }
        $p = $preparar();
        $this->sentencias = [];
        $this->transacciones = ['begin' => 0, 'commit' => 0, 'rollback' => 0];
        $this->capturando = true;
        $t = hrtime(true);
        try {
            $r = $llamada($p);
        } finally {
            $this->capturando = false;
        }
        $msPerfilada = (hrtime(true) - $t) / 1e6;
        $sentencias = $this->sentencias;
        if (isset($o['comprobar'])) {
            ($o['comprobar'])($r);
        }
        $despues($p, $r);
        $fila = ['route' => $ruta, 'size' => $tamano, 'result' => is_scalar($r) || $r === null ? $r : (is_array($r) ? array_intersect_key($r, array_flip(['known', 'legs', 'unknown', 'pages', 'truncated', 'drift', 'stuckDialing', 'staleRequested', 'costCap'])) : get_debug_type($r)),
            'profiledMs' => round($msPerfilada, 3)] + self::resumenSentencias($sentencias);
        if (! $this->modoTiempos()) {
            $fila['plans'] = $this->planes($sentencias);
        }
        $fila['timing'] = $this->cronometrar(function () use ($preparar, $llamada, $despues): float {
            $p = $preparar();
            $t = hrtime(true);
            $r = $llamada($p);
            $ms = (hrtime(true) - $t) / 1e6;
            $despues($p, $r);

            return $ms;
        }, $o);
        $this->resultados[] = $fila;
    }

    /** Un lector a pelo: sentencias por llamada y 2 000 llamadas cronometradas (200 de calentamiento). */
    private function lector(string $nombre, string $tamano, callable $llamada): void
    {
        $llamada();
        $this->sentencias = [];
        $this->capturando = true;
        try {
            $llamada();
        } finally {
            $this->capturando = false;
        }
        $fila = ['route' => $nombre, 'size' => $tamano] + self::resumenSentencias($this->sentencias);
        if (! $this->modoTiempos()) {
            $fila['plans'] = $this->planes($this->sentencias);
        }
        if ($this->modoTiempos()) {
            for ($i = 0; $i < 200; $i++) {
                $llamada();
            }
            $tandas = [];
            $todas = [];
            for ($k = 0; $k < 3; $k++) {
                $t = [];
                for ($i = 0; $i < 2000; $i++) {
                    $a = hrtime(true);
                    $llamada();
                    $t[] = (hrtime(true) - $a) / 1e6;
                }
                $tandas[] = self::percentiles($t);
                $todas = [...$todas, ...$t];
            }
            $fila['timing'] = ['reps' => $tandas, 'all' => self::percentiles($todas), 'load1' => sys_getloadavg()[0]];
        }
        $this->resultados[] = $fila;
    }

    /** @param  callable():float  $una */
    private function cronometrar(callable $una, array $o): ?array
    {
        $n = (int) ($o['n'] ?? (int) (getenv('PERF_N') ?: 0));
        if (! $this->modoTiempos() || $n <= 0) {
            return null;
        }
        $calentar = (int) ($o['warm'] ?? (int) (getenv('PERF_WARM') ?: 5));
        $tandas = (int) ($o['reps'] ?? (int) (getenv('PERF_REPS') ?: 3));
        for ($i = 0; $i < $calentar; $i++) {
            $una();
        }
        $todas = [];
        $porTanda = [];
        $cargas = [sys_getloadavg()[0]];
        for ($k = 0; $k < $tandas; $k++) {
            $t = [];
            for ($i = 0; $i < $n; $i++) {
                $t[] = $una();
            }
            $porTanda[] = self::percentiles($t);
            $todas = [...$todas, ...$t];
            $cargas[] = sys_getloadavg()[0];
        }

        return ['reps' => $porTanda, 'all' => self::percentiles($todas), 'load1' => $cargas];
    }

    private function modoTiempos(): bool
    {
        return (int) (getenv('PERF_N') ?: 0) > 0;
    }

    /**
     * @param  callable():TestResponse  $peticion
     * @return array{0:TestResponse,1:list<array{0:string,1:float,2:array}>}
     */
    private function perfilada(callable $peticion): array
    {
        $this->sentencias = [];
        $this->transacciones = ['begin' => 0, 'commit' => 0, 'rollback' => 0];
        $this->capturando = true;
        try {
            $r = $peticion();
        } finally {
            $this->capturando = false;
        }

        return [$r, $this->sentencias];
    }

    /**
     * Estado ESTABLE de los limitadores: sus filas se conservan y el contador
     * vuelve a 5, con la ventana viva (lo del H7). Solo para las rutas con
     * clave por persona o por red; las públicas estrenan su cubo por llamada.
     */
    private function limpiarLimitadores(): void
    {
        $tabla = (string) config('cache.stores.database.table', 'cache');
        $vence = now()->getTimestamp() + 600;
        DB::table($tabla)->whereRaw("key ~ '[0-9a-f]{32}$'")->update(['value' => serialize(5), 'expiration' => $vence]);
        DB::table($tabla)->whereRaw("key ~ '[0-9a-f]{32}:timer$'")->update(['expiration' => $vence]);
        // Los cubos de FALLO de las públicas (`voice-rl:ruta|IP|fallo`, 30/min): para medir el 401 repetido sin llegar al 429.
        DB::table($tabla)->where('key', 'like', '%|fallo')->update(['value' => serialize(5), 'expiration' => $vence]);
        DB::table($tabla)->where('key', 'like', '%|fallo:timer')->update(['expiration' => $vence]);
    }

    /**
     * EXPLAIN de lo que ejecutó UNA petición: ANALYZE + BUFFERS de cada SELECT
     * distinto (con sus valores, dentro de una transacción que se deshace) y
     * EXPLAIN sin ejecutar de cada UPDATE/DELETE. Resume nodos, índices y
     * lecturas secuenciales.
     *
     * @param  list<array{0:string,1:float,2:array}>  $sentencias
     * @return list<array<string,mixed>>
     */
    private function planes(array $sentencias): array
    {
        $vistos = [];
        $out = [];
        foreach ($sentencias as [$sql, $ms, $valores]) {
            $tipo = strtolower((string) strtok(ltrim($sql), " \n("));
            if (! in_array($tipo, ['select', 'update', 'delete'], true) || str_contains($sql, 'pg_advisory') || str_contains($sql, 'version()')) {
                continue;
            }
            $norm = self::normalizar($sql);
            if (isset($vistos[$norm])) {
                $out[$vistos[$norm]]['calls']++;

                continue;
            }
            $prefijo = $tipo === 'select' ? 'EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) ' : 'EXPLAIN (FORMAT JSON) ';
            $plan = null;
            $error = null;
            DB::beginTransaction();
            try {
                $plan = DB::select($prefijo.$sql, $valores);
            } catch (Throwable $e) {
                $error = substr($e->getMessage(), 0, 200);
            } finally {
                DB::rollBack();
            }
            if ($plan === null) {
                DB::beginTransaction();
                try {
                    $plan = DB::select($prefijo.self::interpolar($sql, $valores));
                    $error = null;
                } catch (Throwable $e) {
                    $error ??= substr($e->getMessage(), 0, 200);
                } finally {
                    DB::rollBack();
                }
            }
            $fila = ['sql' => mb_substr($norm, 0, 300), 'calls' => 1, 'ms' => round($ms, 3)];
            if ($plan !== null) {
                $json = json_decode((string) (array_values((array) $plan[0])[0]), true)[0] ?? [];
                $nodos = [];
                self::recorrer($json['Plan'] ?? [], $nodos);
                $fila += [
                    'execMs' => $json['Execution Time'] ?? null,
                    'nodes' => array_map(static fn (array $n): string => trim($n['type'].' '.($n['index'] ?? '').' '.($n['relation'] !== null ? 'on '.$n['relation'] : '')).(isset($n['rows']) ? " rows={$n['rows']}" : ''), $nodos),
                    'seqScans' => array_values(array_map(static fn (array $n): string => $n['relation'].(isset($n['rows']) ? " (rows={$n['rows']}, removed={$n['removed']})" : ''),
                        array_filter($nodos, static fn (array $n): bool => $n['type'] === 'Seq Scan'))),
                    'indexes' => array_values(array_unique(array_filter(array_map(static fn (array $n): ?string => $n['index'], $nodos)))),
                    'sharedHit' => $json['Plan']['Shared Hit Blocks'] ?? null,
                    'sharedRead' => $json['Plan']['Shared Read Blocks'] ?? null,
                ];
            } else {
                $fila['explainError'] = $error;
            }
            $vistos[$norm] = count($out);
            $out[] = $fila;
        }

        return $out;
    }

    /** @param  list<array<string,mixed>>  $out */
    private static function recorrer(array $nodo, array &$out): void
    {
        if ($nodo === []) {
            return;
        }
        $out[] = ['type' => (string) ($nodo['Node Type'] ?? '?'), 'relation' => $nodo['Relation Name'] ?? null, 'index' => $nodo['Index Name'] ?? null,
            'rows' => isset($nodo['Actual Rows']) ? (int) round($nodo['Actual Rows'] * max(1, (float) ($nodo['Actual Loops'] ?? 1))) : null,
            'removed' => (int) ($nodo['Rows Removed by Filter'] ?? 0)];
        foreach ($nodo['Plans'] ?? [] as $hijo) {
            self::recorrer($hijo, $out);
        }
    }

    /** Respaldo de EXPLAIN con los valores escritos en la sentencia (si el protocolo no admite parámetros en EXPLAIN). */
    private static function interpolar(string $sql, array $valores): string
    {
        $pdo = DB::connection()->getPdo();
        $i = 0;

        return (string) preg_replace_callback('/\?/', static function () use (&$i, $valores, $pdo): string {
            $v = $valores[$i++] ?? null;

            return match (true) {
                $v === null => 'NULL',
                is_bool($v) => $v ? 'true' : 'false',
                is_int($v), is_float($v) => (string) $v,
                $v instanceof DateTimeInterface => $pdo->quote($v->format('Y-m-d H:i:s')),
                default => $pdo->quote((string) $v),
            };
        }, $sql);
    }

    /**
     * El uso de índices de la familia (`pg_stat_user_indexes` de las tablas de
     * la voz; la copia de la base empieza a cero). Las comprobaciones de
     * unicidad al insertar NO cuentan como `idx_scan`.
     *
     * @return list<array<string,mixed>>
     */
    private function usoDeIndices(): array
    {
        usleep(1_200_000);
        DB::select('select pg_stat_clear_snapshot()');

        return array_map(static fn ($f): array => (array) $f, DB::select(
            "select s.relname as tabla, s.indexrelname as indice, s.idx_scan as scans, s.idx_tup_read as tuplas, pg_get_expr(i.indpred, i.indrelid) as parcial, i.indisunique as unico
             from pg_stat_user_indexes s join pg_index i on i.indexrelid = s.indexrelid
             where s.relname like 'voice_%' or s.relname like 'ultron_studio_voice_%'
             order by s.relname, s.indexrelname"
        ));
    }

    // ── Piezas de la telefonía ──────────────────────────────────────────────

    /** Un POST con el cuerpo CRUDO tal cual (los webhooks firman los bytes). @param  array<string,string>  $cabeceras */
    private function crudo(string $uri, string $cuerpo, array $cabeceras = []): TestResponse
    {
        $servidor = ['CONTENT_TYPE' => $cabeceras['Content-Type'] ?? 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        foreach ($cabeceras as $nombre => $valor) {
            if (in_array($nombre, ['Content-Type', 'Accept'], true)) {
                continue;
            }
            $servidor['HTTP_'.strtoupper(str_replace('-', '_', $nombre))] = $valor;
        }

        return $this->call('POST', $uri, [], [], [], $servidor, $cuerpo);
    }

    private function postFirmado(string $cuerpo): TestResponse
    {
        $t = now()->getTimestamp();

        return $this->crudo('/api/webhooks/voice/elevenlabs/post-call', $cuerpo, ['ElevenLabs-Signature' => 't='.$t.',v0='.hash_hmac('sha256', $t.'.'.$cuerpo, self::WEBHOOK_SIG)]);
    }

    /** @param  array{conv:string, sid:string, ref:string}  $c */
    private function herramienta(string $herramienta, array $c, string $autorizacion = 'Bearer '.self::TOOL_BEARER): TestResponse
    {
        return $this->crudo('/api/voice/tools/'.$herramienta, json_encode(['conversation_id' => $c['conv'], 'call_sid' => $c['sid'], 'call_ref' => $c['ref']]),
            ['Authorization' => $autorizacion]);
    }

    /**
     * Una entrante AUTORIZADA y `active` de un canario, sellada con la parte
     * vigente (lo que deja la iniciación), sin pasar por la ruta.
     *
     * @return array{id:int, conv:string, sid:string, ref:string}
     */
    private function entranteActiva($parte): array
    {
        $k = ++$this->n;
        $conv = 'conv_perf_m_'.$k.'_'.Str::lower(Str::random(5));
        $sid = 'CA'.md5('perf-m-'.$k.'-'.Str::random(8));
        $ref = (string) Str::uuid();
        $tel = self::CANARY_PHONES[$k % 3];
        $id = (int) DB::table('voice_calls')->insertGetId([
            'uuid' => (string) Str::uuid(), 'call_ref' => $ref, 'direction' => 'inbound', 'status' => 'active', 'target_source' => null,
            'canary_number_uuid' => DB::table('ultron_studio_canary_numbers')->where('channel', 'voice')->where('status', 'active')->orderBy('id')->value('uuid'),
            'voice_contact_id' => null, 'remote_hash' => VoicePhone::hash($tel), 'remote_last4' => substr($tel, -4), 'caller_authorized' => true, 'policy_reason' => null,
            'attempt' => 1, ...$parte->stampColumns(), 'provider_conversation_id' => $conv, 'provider_call_sid' => $sid,
            'budget_day' => now()->setTimezone('America/Bogota')->toDateString(), 'reserved_micros' => 610500, 'tool_calls' => 0,
            'started_at' => now(), 'status_changed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $id, 'conv' => $conv, 'sid' => $sid, 'ref' => $ref];
    }

    /** Una saliente a un canario ya en `dialing` (requested → dialing con sus ids). @return array{id:int, conv:string, sid:string, ref:string} */
    private function salienteMarcando($parte): array
    {
        $ana = $this->persona(self::P_ANA);
        $canario = DB::table('ultron_studio_canary_numbers')->where('channel', 'voice')->where('status', 'active')->orderBy('id')->first();
        $id = $this->salientePedida($ana, $parte, $canario, '+5730000'.str_pad((string) (90000 + (++$this->n % 9000)), 5, '0', STR_PAD_LEFT));
        $conv = 'conv_perf_d_'.$id;
        $sid = 'CA'.md5('perf-d-'.$id);
        DB::table('voice_calls')->where('id', $id)->update(['status' => 'dialing', 'provider_conversation_id' => $conv, 'provider_call_sid' => $sid, 'started_at' => now(), 'status_changed_at' => now()]);

        return ['id' => $id, 'conv' => $conv, 'sid' => $sid, 'ref' => (string) DB::table('voice_calls')->where('id', $id)->value('call_ref')];
    }

    /** Una saliente `requested` a un canario (lo que deja la puerta antes del job). Devuelve su id. */
    private function salientePedida(Admin $ana, $parte, object $canario, ?string $huellaDe = null): int
    {
        $tel = $huellaDe ?? self::CANARY_PHONES[0];

        return (int) DB::table('voice_calls')->insertGetId([
            'uuid' => (string) Str::uuid(), 'call_ref' => (string) Str::uuid(), 'direction' => 'outbound', 'status' => 'requested',
            'target_source' => 'canary_registry', 'canary_number_uuid' => $canario->uuid, 'voice_contact_id' => null,
            'remote_hash' => VoicePhone::hash($tel), 'remote_last4' => substr($tel, -4), 'caller_authorized' => null, 'policy_reason' => null,
            'client_request_id' => (string) Str::uuid(), 'requested_by_type' => 'admin', 'requested_by_admin_id' => $ana->id, 'requested_by_name' => (string) $ana->name,
            'requested_by_role' => $ana->role, 'attempt' => 1, ...$parte->stampColumns(), 'budget_day' => now()->setTimezone('America/Bogota')->toDateString(),
            'reserved_micros' => 742000, 'tool_calls' => 0, 'status_changed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, mixed> el cuerpo `pc_completed` del fixture de TEST8, decodificado */
    private function plantillaPostLlamada(): array
    {
        $fx = json_decode((string) file_get_contents(base_path('tests/fixtures/voice/elevenlabs-webhook-bodies.json')), true);
        foreach ($fx['postCall'] as $caso) {
            if (($caso['bodyKey'] ?? null) === 'pc_completed') {
                return json_decode($caso['rawBody'], true);
            }
        }
        $this->fail('falta pc_completed en el fixture');
    }

    /**
     * Un `post_call_transcription` con la forma del fixture y `$turnos` turnos
     * LIMPIOS: el primero del agente lleva el aviso; los demás alternan persona
     * y agente con frases del caso limpio del fixture; con `$largo`, cada turno
     * del agente se alarga a ~`$largo` bytes (el peor caso de la guarda dentro de 1 MB).
     *
     * @param  array<string, mixed>  $plantilla
     * @param  array{conv:string, sid:string, ref:string}  $c
     */
    private function cuerpoTranscripcion(array $plantilla, array $c, ?string $agente, int $turnos, int $largo): string
    {
        $base = $plantilla['data']['transcript'];
        $aviso = $base[0];
        $frasesAgente = ['La Sede Centro abre de lunes a viernes de 5:00 a 22:00 y los sábados de 7:00 a 14:00.', 'Con gusto te cuento cómo funcionan nuestros planes y qué incluye cada uno.',
            'Puedes entrenar en la zona de máquinas y en las clases grupales del horario de la sede.', 'Si quieres, te explico qué necesitas traer el primer día para empezar.'];
        $frasesPersona = ['¿Y los fines de semana también abren?', 'Me interesa entrenar en la mañana.', 'Vale, gracias por la información.', '¿Qué clases grupales tienen?'];
        $transcripcion = [$aviso];
        for ($t = 1; $t < $turnos; $t++) {
            if ($t % 2 === 1) {
                $transcripcion[] = ['role' => 'user', 'message' => $frasesPersona[$t % 4], 'time_in_call_secs' => 5 * $t, 'tool_calls' => [], 'tool_results' => [], 'feedback' => null, 'conversation_turn_metrics' => null];

                continue;
            }
            $texto = $frasesAgente[$t % 4];
            if ($largo > 0) {
                while (strlen($texto) < $largo) {
                    $texto .= ' '.$frasesAgente[(strlen($texto) + $t) % 4];
                }
            }
            $transcripcion[] = ['role' => 'agent', 'message' => $texto, 'time_in_call_secs' => 5 * $t, 'tool_calls' => [], 'tool_results' => [], 'feedback' => null, 'conversation_turn_metrics' => null];
        }
        $cuerpo = $plantilla;
        $cuerpo['event_timestamp'] = now()->getTimestamp();
        $cuerpo['data']['agent_id'] = $agente;
        $cuerpo['data']['conversation_id'] = $c['conv'];
        $cuerpo['data']['user_id'] = $c['ref'];
        $cuerpo['data']['conversation_initiation_client_data']['dynamic_variables']['call_ref'] = $c['ref'];
        $cuerpo['data']['transcript'] = $transcripcion;
        $cuerpo['data']['metadata']['phone_call'] = ['call_sid' => $c['sid'], 'type' => 'twilio'];
        $cuerpo['data']['metadata']['call_duration_secs'] = 5 * $turnos;
        $json = json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        // Dentro del tope de 1 MB de la ruta (LimitRequestBodySize) y del verificador.
        while (strlen($json) > 1048576 && count($cuerpo['data']['transcript']) > 2) {
            array_pop($cuerpo['data']['transcript']);
            $json = json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return $json;
    }

    /** Un operador falso: las llamadas en `$paginas` páginas. */
    private static function operadorFalso(array $llamadas, int $paginas): TelephonyCarrier
    {
        return new class($llamadas, $paginas) implements TelephonyCarrier
        {
            public function __construct(private array $llamadas, private int $paginas) {}

            public function hangup(string $callSid): CarrierResult
            {
                throw new \LogicException('sin uso');
            }

            public function listCalls(\DateTimeInterface $since, ?string $pageToken): CarrierCallsPage
            {
                $pagina = $pageToken === null ? 0 : (int) explode(':', $pageToken)[0];
                $tamano = (int) ceil(count($this->llamadas) / $this->paginas);
                $siguiente = $pagina + 1 < $this->paginas ? ($pagina + 1).':PN'.str_repeat('a', 8) : null;

                return new CarrierCallsPage(CarrierCallsPage::OUTCOME_LISTED, array_slice($this->llamadas, $pagina * $tamano, $tamano), $siguiente, false, 200);
            }
        };
    }

    /** Desglose del compilador sin la petición: compilar, mapear la carga y su huella (D8-6, «el tiempo de compilación»). */
    private function tramosCompilacion($v, $p): void
    {
        $n = $this->modoTiempos() ? 200 : 20;
        $med = [];
        foreach (['compilar (spec)' => fn () => \App\Services\UltronStudio\StudioVoiceCompiler::compile(StudioVoicePublishing::versionInput($v), \App\Services\UltronStudio\StudioVoiceProfiles::compilerInput($p), StudioVoicePublishing::maxCallSeconds())] as $tramo => $f) {
            $f();
            $t = [];
            for ($i = 0; $i < $n; $i++) {
                $a = hrtime(true);
                $f();
                $t[] = (hrtime(true) - $a) / 1e6;
            }
            $med[$tramo] = self::percentiles($t);
        }
        $this->resultados[] = ['route' => 'StudioVoiceCompiler::compile (a pelo)', 'size' => 'versión y perfil reales', 'samples' => $n, 'stages' => $med];
    }

    /** @param  TestResponse  $r */
    private function todasVerificadas($r): void
    {
        $historial = $r->json('data.history.data');
        $this->assertIsArray($historial);
        $this->assertNotEmpty($historial);
        foreach ($historial as $e) {
            $this->assertSame('ok', $e['verified'] ?? null, 'una entrada sembrada no verifica: se mediría el camino corto ('.json_encode($e['verified'] ?? null).')');
        }
    }

    /**
     * @param  list<array{0:string,1:float,2:array}>  $sentencias
     * @return array<string,mixed>
     */
    private static function resumenSentencias(array $sentencias): array
    {
        $porTabla = [];
        $msPorTabla = [];
        $normalizadas = [];
        foreach ($sentencias as [$sql, $ms]) {
            $tabla = preg_match('/\b(?:from|into|update|join)\s+"?([a-z0-9_]+)"?/i', $sql, $m) ? $m[1] : (preg_match('/^\s*(savepoint|release|rollback|begin|commit|set|select pg_advisory)/i', $sql, $m2) ? strtoupper($m2[1]) : 'otra');
            $porTabla[$tabla] = ($porTabla[$tabla] ?? 0) + 1;
            $msPorTabla[$tabla] = round(($msPorTabla[$tabla] ?? 0) + $ms, 3);
            $norm = self::normalizar($sql);
            $normalizadas[$norm] = ($normalizadas[$norm] ?? 0) + 1;
        }
        arsort($porTabla);
        arsort($normalizadas);
        $top = array_key_first($normalizadas);

        return [
            'queries' => count($sentencias),
            'dbMs' => round(array_sum(array_column($sentencias, 1)), 3),
            'perTable' => $porTabla,
            'perTableMs' => $msPorTabla,
            'mostRepeated' => $top === null ? null : ['count' => $normalizadas[$top], 'sql' => mb_substr($top, 0, 240)],
            'statements' => array_map(static fn (array $s): string => mb_substr($s[0], 0, 400), $sentencias),
        ];
    }

    private static function normalizar(string $sql): string
    {
        return (string) preg_replace(['/\(\s*\?(?:\s*,\s*\?)*\s*\)/', '/\b\d+\b/', '/\s+/'], ['(?…)', 'N', ' '], $sql);
    }

    /**
     * @param  list<float>  $t
     * @return array{n:int,p50:float,p95:float,max:float,min:float}
     */
    private static function percentiles(array $t): array
    {
        sort($t);
        $n = count($t);
        $p = static fn (float $q): float => $n === 0 ? 0.0 : round($t[max(0, (int) ceil($q * $n) - 1)], 3);

        return ['n' => $n, 'p50' => $p(0.50), 'p95' => $p(0.95), 'max' => $n === 0 ? 0.0 : round($t[$n - 1], 3), 'min' => $n === 0 ? 0.0 : round($t[0], 3)];
    }

    /** @return array<string, mixed> */
    private static function entorno(): array
    {
        return [
            'at' => gmdate('c'),
            'php' => PHP_VERSION,
            'opcacheCli' => (bool) ini_get('opcache.enable_cli'),
            'db' => DB::connection()->getDatabaseName(),
            'pg' => DB::selectOne('select version() as v')->v ?? null,
            'cache' => config('cache.default'),
            'queue' => config('queue.default'),
            'n' => (int) (getenv('PERF_N') ?: 0),
            'warm' => (int) (getenv('PERF_WARM') ?: 5),
            'reps' => (int) (getenv('PERF_REPS') ?: 3),
            'load' => sys_getloadavg(),
            'host' => php_uname('n').' '.php_uname('m'),
            'head' => trim((string) @shell_exec('git -C '.escapeshellarg(base_path()).' rev-parse --short HEAD 2>/dev/null')),
        ];
    }
}

<?php

namespace Tests\Feature\UltronStudio;

use App\Console\Commands\UltronStudio\MonitorCommand;
use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\MarketingConversation;
use App\Models\MarketingLead;
use App\Models\UltronAbort;
use App\Services\Marketing\Ultron\Prompts\UltronPromptStamp;
use App\Services\UltronStudio\StudioCanaryMeasures;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * La alerta M1-A (H5 M1-13; auditoría de cierre, INC-2): un turno de la
 * conversación canaria cuyo sello de prompts es `mismatch` (n8n aplicó otra
 * cosa que lo ofrecido) o `legacy_drift` (los prompts Legacy de n8n ya no son
 * los de la base) abre o suma al incidente `prompts_drift` de IRON GUARD desde
 * la comprobación `prompts` del monitor del H7.
 *
 *  - los dos estados alertan; `applied`, `legacy` y los demás, no;
 *  - cada pasada programada mira la media hora anterior (con dos minutos de
 *    gracia): ningún turno lo cuentan dos pasadas ni cae entre dos;
 *  - la misma deriva es UN incidente con más ocurrencias;
 *  - el incidente lleva estados, recuentos, la ventana y huellas de 8, nunca
 *    ids de conversación, de filas o de ejecuciones, teléfonos ni textos;
 *  - con la llave de prompts en `off` no vigila y lo dice;
 *  - en seco no anota nada; y nunca frena ni toca el motor.
 *
 * Sobre las clases reales: el comando, `StudioCanaryMeasures` y el
 * `IncidentRecorder`, con turnos sembrados en `marketing_ai_actions`.
 */
class UltronStudioMonitorPromptsTest extends TestCase
{
    use PublicationFixtures;
    use RefreshDatabase;
    use StudioFixtures;

    private const VERSION = 'b0000000-0000-4000-8000-00000000000b';

    /** La huella del paquete «profesional + 200» (G8), la que ofrece la versión publicada. */
    private const OFFERED = '702890f7d86a27fef027abd672a99903e64b6661c2d8f8a72a45ddea3d6d311c';

    /** Lo que n8n dice haber aplicado en un `mismatch` (un paquete alterado en tránsito). */
    private const FORGED = 'deadbeef1111111111111111111111111111111111111111111111111111aaaa';

    /** La huella Legacy de la base. */
    private const LEGACY = '3f6a5674ad37037f7456d9f84dc8920a0b9f37a9c5e7e90b7d07c3685a86c902';

    /** Lo que n8n dice haber aplicado en un `legacy_drift` (constantes Legacy editadas a mano). */
    private const DRIFTED = 'cafebabe2222222222222222222222222222222222222222222222222222bbbb';

    /** Un mensaje de error con forma de fuga: no puede salir de la excepción. */
    private const CENTINELA = 'SQLSTATE[08006] host=10.20.30.40 password=centinela-m1a';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** El motor como en producción hoy: motor y prompts en `canary`, con su conversación canaria. */
    private function promptsOn(): void
    {
        $this->openKeys(['marketing.ultron.studio_prompts' => 'canary']);
    }

    private function instante(string $instante): CarbonImmutable
    {
        return CarbonImmutable::parse($instante);
    }

    private function reloj(string $instante): void
    {
        Carbon::setTestNow(Carbon::parse($instante));
        CarbonImmutable::setTestNow(CarbonImmutable::parse($instante));
    }

    /**
     * El sello de prompts de un turno, con todo lo que NO puede salir del
     * monitor: el motivo libre, la ejecución de n8n, la entrada de la cadena y
     * la huella del contenido.
     *
     * @return array<string,mixed>
     */
    private static function sello(string $estado, string $marca, ?string $observada, bool $ofrecidaStudio = true): array
    {
        return ['studio_prompts' => [
            'v' => 1, 'state' => $estado,
            'offered' => $ofrecidaStudio
                ? ['mode' => 'studio', 'reason' => null, 'version' => self::VERSION, 'contentHash' => str_repeat('c', 64), 'entry' => (string) Str::uuid(), 'seq' => 7, 'bundleHash' => self::OFFERED]
                : ['mode' => 'legacy', 'reason' => 'no_publication', 'version' => null, 'contentHash' => null, 'entry' => null, 'seq' => null, 'bundleHash' => null],
            'observed' => ['mode' => $ofrecidaStudio && $estado !== 'legacy_drift' ? 'studio' : 'legacy', 'bundleHash' => $observada, 'reason' => 'TEXTO LIBRE '.$marca, 'flowRev' => 'S1', 'execution' => 'EJEC-'.$marca],
        ], 'text_marker' => 'no-sale-'.$marca];
    }

    /** @return array{0:int,1:array<string,mixed>,2:string} el código, el JSON y la salida en bruto */
    private function monitor(array $opciones = []): array
    {
        $codigo = Artisan::call('ultron-studio:monitor', $opciones + ['--check' => ['prompts'], '--json' => true]);
        $salida = Artisan::output();
        $json = json_decode($salida, true);
        $this->assertIsArray($json, 'el monitor tiene que devolver JSON: '.$salida);

        return [$codigo, $json, $salida];
    }

    /** @return list<string> las sentencias que escriben mientras corre `$hacer` */
    private function writesDuring(callable $hacer): array
    {
        $escrituras = [];
        DB::listen(function (QueryExecuted $q) use (&$escrituras): void {
            if (preg_match('/^\s*(insert|update|delete|replace|truncate)\b/i', $q->sql) === 1) {
                $escrituras[] = $q->sql;
            }
        });
        $hacer();

        return $escrituras;
    }

    /**
     * Las claves de cada objeto ordenadas, a cualquier profundidad (en
     * PostgreSQL la evidencia es jsonb, que reordena las claves).
     *
     * @param  array<mixed>  $valor
     * @return array<mixed>
     */
    private static function clavesOrdenadas(array $valor): array
    {
        foreach ($valor as $k => $v) {
            if (is_array($v)) {
                $valor[$k] = self::clavesOrdenadas($v);
            }
        }
        if (! array_is_list($valor)) {
            ksort($valor, SORT_STRING);
        }

        return $valor;
    }

    // ── Los dos estados ──────────────────────────────────────────────────────

    public function test_un_mismatch_de_la_canaria_abre_prompts_drift_alto_sin_ids_ni_textos_y_no_frena_nada(): void
    {
        $this->promptsOn();
        $this->reloj('2026-11-11T15:00:04Z'); // ventana: de 14:28 a 14:58
        $this->stampTurns(2, self::VERSION, $this->instante('2026-11-11T14:40:00Z'), [], self::sello('mismatch', 'QK1', self::FORGED));
        $this->stampTurns(1, self::VERSION, $this->instante('2026-11-11T14:45:00Z'), [], self::sello('applied', 'QK2', self::OFFERED));

        $escrituras = $this->writesDuring(function () use (&$codigo, &$r, &$salida): void {
            [$codigo, $r, $salida] = $this->monitor();
        });

        $this->assertSame(1, $codigo);
        $p = $r['checks']['prompts'];
        $this->assertSame(['ok', 'status', 'problems', 'reason', 'key', 'window', 'turns', 'byState', 'fingerprints'], array_keys($p));
        $this->assertSame([false, 'drift', ['mismatch'], null, 'canary'], [$p['ok'], $p['status'], $p['problems'], $p['reason'], $p['key']]);
        $this->assertSame(['from' => '2026-11-11T14:28:00Z', 'to' => '2026-11-11T14:58:00Z'], $p['window']);
        $this->assertSame([3, ['legacy_drift' => 0, 'mismatch' => 2]], [$p['turns'], $p['byState']]);
        $this->assertSame([['state' => 'mismatch', 'version8' => 'b0000000', 'offered8' => '702890f7', 'observed8' => 'deadbeef']], $p['fingerprints']);

        $incidente = Incident::query()->sole();
        $this->assertSame([MonitorCommand::SOURCE, MonitorCommand::KIND_PROMPTS, Incident::SEVERITY_HIGH, Incident::STATUS_OPEN, 1],
            [$incidente->source, $incidente->kind, $incidente->severity, $incidente->status, $incidente->occurrences]);
        $this->assertSame('Los prompts que n8n aplicó en la conversación canaria no son los esperados (M1-A): mismatch', $incidente->title);
        $this->assertSame([0, 0], [$incidente->affected_conversations, $incidente->affected_messages]);
        $this->assertSame([], (array) $incidente->correlation_ids);
        $this->assertSame(self::clavesOrdenadas([
            'check' => 'prompts',
            'problems' => ['mismatch'],
            'check_command' => 'php artisan ultron:prompt-trace --last=20',
            'runbook' => 'backend/docs/ultron-studio/ops/RUNBOOK_M1.md',
            'window' => ['from' => '2026-11-11T14:28:00Z', 'to' => '2026-11-11T14:58:00Z'],
            'turns' => 3,
            'by_state' => ['legacy_drift' => 0, 'mismatch' => 2],
            'fingerprints' => [['state' => 'mismatch', 'version8' => 'b0000000', 'offered8' => '702890f7', 'observed8' => 'deadbeef']],
        ]), self::clavesOrdenadas($incidente->evidence));
        $this->assertSame([['check' => 'prompts', 'id' => $incidente->id, 'kind' => 'prompts_drift', 'severity' => 'high', 'occurrences' => 1]], $r['incidents']);

        // Ni la conversación, ni su lead ni su teléfono, ni la ejecución de n8n, ni el
        // motivo libre, ni las huellas o la versión enteras, ni la entrada, ni los textos.
        $leadId = (string) $this->canary()->lead_id;
        $todo = $salida.json_encode($incidente->evidence, JSON_UNESCAPED_UNICODE).$incidente->title;
        foreach (['987654321', '3009998877', 'TEXTO LIBRE', 'EJEC-', 'QK1', 'QK2', 'no-sale', self::VERSION, self::OFFERED, self::FORGED, str_repeat('c', 64), '"lead', '"conversation', '"execution', '"entry"'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $todo, $prohibido);
        }
        $this->assertDoesNotMatchRegularExpression('/"(lead_id|conversation_id|id)":\s*'.preg_quote($leadId, '/').'\b/', json_encode($incidente->evidence));

        // Solo una alerta (M1-A; E15): escribe en IRON GUARD y en nada más; ni freno, ni motor, ni configuración.
        foreach ($escrituras as $sql) {
            $this->assertMatchesRegularExpression('/^\s*(insert\s+into|update)\s+"?(incidents|incident_events)"?\s/i', $sql, $sql);
        }
        $this->assertSame(0, UltronAbort::query()->count());
        $this->assertSame('canary', config('marketing.ultron.studio_prompts'));

        // En texto, lo mismo.
        $this->artisan('ultron-studio:monitor', ['--check' => ['prompts'], '--dry-run' => true])
            ->expectsOutputToContain('prompts: NO CUADRA (mismatch) · llave de prompts canary · ventana 2026-11-11T14:28:00Z a 2026-11-11T14:58:00Z · 3 turnos de la canaria · legacy_drift 0 · mismatch 2')
            ->doesntExpectOutputToContain('987654321')
            ->assertFailed();
    }

    public function test_un_legacy_drift_tambien_abre_el_incidente_sin_version_ofrecida(): void
    {
        $this->promptsOn();
        $this->reloj('2026-11-11T10:30:02Z'); // ventana: de 09:58 a 10:28
        $this->stampTurns(1, null, $this->instante('2026-11-11T10:10:00Z'), ['state' => 'legacy', 'version' => null], self::sello('legacy_drift', 'LD1', self::DRIFTED, ofrecidaStudio: false));

        [$codigo, $r] = $this->monitor();

        $this->assertSame(1, $codigo);
        $p = $r['checks']['prompts'];
        $this->assertSame([['legacy_drift'], 1, ['legacy_drift' => 1, 'mismatch' => 0]], [$p['problems'], $p['turns'], $p['byState']]);
        $this->assertSame([['state' => 'legacy_drift', 'version8' => null, 'offered8' => null, 'observed8' => 'cafebabe']], $p['fingerprints']);

        $incidente = Incident::query()->sole();
        $this->assertSame([MonitorCommand::KIND_PROMPTS, Incident::SEVERITY_HIGH, ['legacy_drift']], [$incidente->kind, $incidente->severity, $incidente->evidence['problems']]);
        $this->assertStringEndsWith('(M1-A): legacy_drift', $incidente->title);
        $this->assertStringNotContainsString(self::DRIFTED, json_encode($incidente->evidence));
    }

    public function test_los_turnos_correctos_y_los_de_fuera_de_la_canaria_no_alertan(): void
    {
        $this->promptsOn();
        $this->reloj('2026-11-11T15:00:04Z');
        $dentro = $this->instante('2026-11-11T14:40:00Z');
        foreach (['applied', 'legacy', 'engine_fallback', 'unverified', 'not_reported', 'stamp_error', 'MISMATCH', 'mismatch '] as $i => $estado) {
            $this->stampTurns(1, self::VERSION, $dentro, [], self::sello($estado, "OK{$i}", self::OFFERED));
        }
        $this->stampTurns(1, self::VERSION, $dentro); // sin sello de prompts (n8n viejo)
        // Un `mismatch` en otra conversación y uno de un seguimiento saliente de la canaria: no son turnos de la canaria.
        $otra = MarketingConversation::query()->forceCreate([
            'id' => 123456789, 'lead_id' => MarketingLead::create(['channel' => 'whatsapp', 'source' => 'inbound', 'phone' => '3001112233', 'name' => 'Otra'])->id,
            'channel' => 'whatsapp', 'status' => 'open',
        ]);
        $this->stampTurns(1, self::VERSION, $dentro, [], self::sello('mismatch', 'FUERA1', self::FORGED), conversationId: $otra->id);
        $this->stampTurns(1, self::VERSION, $dentro, [], self::sello('legacy_drift', 'FUERA2', self::DRIFTED), 'outbound_followup');

        [$codigo, $r] = $this->monitor();

        $this->assertSame(0, $codigo);
        $p = $r['checks']['prompts'];
        $this->assertSame([true, 'ok', [], null], [$p['ok'], $p['status'], $p['problems'], $p['reason']]);
        $this->assertSame([9, ['legacy_drift' => 0, 'mismatch' => 0], []], [$p['turns'], $p['byState'], $p['fingerprints']]);
        $this->assertSame([], $r['incidents']);
        $this->assertSame(0, Incident::query()->count());
    }

    // ── La ventana ───────────────────────────────────────────────────────────

    public function test_cada_pasada_programada_mira_la_media_hora_anterior_y_un_turno_no_alerta_dos_veces(): void
    {
        // La función pura: alineada con la media hora, con dos minutos de gracia y sin depender de los segundos de retraso.
        foreach ([
            '2026-11-11T10:00:04Z' => ['2026-11-11T09:28:00Z', '2026-11-11T09:58:00Z'],
            '2026-11-11T10:29:59Z' => ['2026-11-11T09:28:00Z', '2026-11-11T09:58:00Z'],
            '2026-11-11T10:30:00Z' => ['2026-11-11T09:58:00Z', '2026-11-11T10:28:00Z'],
            '2026-11-12T00:00:01Z' => ['2026-11-11T23:28:00Z', '2026-11-11T23:58:00Z'],
        ] as $ahora => [$desde, $hasta]) {
            [$d, $h] = MonitorCommand::promptsWindow(CarbonImmutable::parse($ahora));
            $this->assertSame([$desde, $hasta], [$d->utc()->format('Y-m-d\TH:i:s\Z'), $h->utc()->format('Y-m-d\TH:i:s\Z')], $ahora);
        }

        $this->promptsOn();
        $sello = fn (string $marca): array => self::sello('mismatch', $marca, self::FORGED);
        $this->stampTurns(1, self::VERSION, $this->instante('2026-11-11T14:27:59Z'), [], $sello('A')); // pasada de las 14:30
        $this->stampTurns(1, self::VERSION, $this->instante('2026-11-11T14:28:00Z'), [], $sello('B')); // de las 15:00 (el inicio entra)
        $this->stampTurns(1, self::VERSION, $this->instante('2026-11-11T14:57:59Z'), [], self::sello('applied', 'C', self::OFFERED)); // de las 15:00
        $this->stampTurns(1, self::VERSION, $this->instante('2026-11-11T14:58:00Z'), [], $sello('D')); // de las 15:30 (el final no entra: la gracia)

        $pasada = function (string $ahora, array $opciones = []): array {
            $this->reloj($ahora);
            [, $r] = $this->monitor($opciones);

            return [$r['checks']['prompts']['turns'], $r['checks']['prompts']['byState']['mismatch']];
        };

        $this->assertSame([1, 1], $pasada('2026-11-11T14:30:03Z'));
        $this->assertSame(1, Incident::query()->sole()->occurrences);
        $this->assertSame([2, 1], $pasada('2026-11-11T15:00:04Z'), 'A no vuelve a contar');
        $this->assertSame([2, 1], $pasada('2026-11-11T15:00:50Z', ['--dry-run' => true]), 'unos segundos más tarde, la misma ventana');
        $this->assertSame([1, 1], $pasada('2026-11-11T15:30:02Z'), 'D, que la gracia dejó fuera de la de las 15:00');
        $this->assertSame([0, 0], $pasada('2026-11-11T16:00:01Z'), 'nada nuevo: nada que contar');

        // Tres turnos malos, tres ocurrencias: cada uno contado una vez, en un solo incidente.
        $incidente = Incident::query()->sole();
        $this->assertSame([3, Incident::STATUS_OPEN], [$incidente->occurrences, $incidente->status]);
    }

    public function test_la_ventana_es_la_cadencia_del_programador_y_no_hay_tarea_nueva(): void
    {
        config(['ultron_studio.monitor.enabled' => true]);
        $this->app->instance(Schedule::class, new Schedule);
        Facade::clearResolvedInstances();
        require base_path('routes/console.php');
        $delStudio = array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            static fn (Event $e): bool => str_contains((string) $e->command, 'ultron-studio:'),
        ));

        $this->assertCount(2, $delStudio, 'el monitor y la instantánea, nada más: `prompts` va dentro del monitor');
        $monitor = array_values(array_filter($delStudio, static fn (Event $e): bool => str_contains((string) $e->command, 'ultron-studio:monitor')));
        $this->assertCount(1, $monitor);
        $this->assertStringEndsWith("'artisan' ultron-studio:monitor", (string) $monitor[0]->command, 'sin --check: corre las cuatro');
        $this->assertSame('*/'.MonitorCommand::PROMPTS_WINDOW_MINUTES.' * * * *', $monitor[0]->expression, 'la ventana de `prompts` es la cadencia del monitor');
        $this->assertSame(0, 60 % MonitorCommand::PROMPTS_WINDOW_MINUTES);
        $this->assertLessThan(MonitorCommand::PROMPTS_WINDOW_MINUTES, MonitorCommand::PROMPTS_GRACE_MINUTES);
        $this->assertContains('prompts', MonitorCommand::CHECKS);
    }

    // ── Deduplicación ────────────────────────────────────────────────────────

    public function test_la_misma_deriva_en_pasadas_seguidas_es_un_solo_incidente_con_mas_ocurrencias(): void
    {
        $this->promptsOn();
        $this->stampTurns(1, self::VERSION, $this->instante('2026-11-11T14:40:00Z'), [], self::sello('mismatch', 'P1', self::FORGED));
        $this->stampTurns(1, self::VERSION, $this->instante('2026-11-11T15:10:00Z'), [], self::sello('mismatch', 'P2', 'feedface'.str_repeat('3', 56)));

        $this->reloj('2026-11-11T15:00:04Z');
        $this->monitor();
        $incidente = Incident::query()->sole();
        $this->assertSame(1, $incidente->occurrences);

        // Una mirada a mano, en seco, no suma.
        $this->monitor(['--dry-run' => true]);
        $this->assertSame(1, $incidente->fresh()->occurrences);

        // La pasada siguiente, con otro turno `mismatch` (otra huella): el MISMO incidente, una ocurrencia más, sin reabrir.
        $this->reloj('2026-11-11T15:30:02Z');
        $this->monitor();
        $this->assertSame(1, Incident::query()->count());
        $incidente = $incidente->fresh();
        $this->assertSame([2, Incident::STATUS_OPEN], [$incidente->occurrences, $incidente->status]);
        $this->assertSame(1, IncidentEvent::query()->where('incident_id', $incidente->id)->count(), 'sumar no reabre');
        // La evidencia es la de ahora (IRON GUARD la refresca): la huella nueva.
        $this->assertSame('feedface', $incidente->evidence['fingerprints'][0]['observed8']);

        // La clase son los estados (como `publication`): con los dos a la vez, su propio incidente.
        $this->stampTurns(1, self::VERSION, $this->instante('2026-11-11T15:40:00Z'), [], self::sello('mismatch', 'P3', self::FORGED));
        $this->stampTurns(1, null, $this->instante('2026-11-11T15:41:00Z'), ['state' => 'legacy', 'version' => null], self::sello('legacy_drift', 'P4', self::DRIFTED, ofrecidaStudio: false));
        $this->reloj('2026-11-11T16:00:03Z');
        $this->monitor();
        $this->assertSame(2, Incident::query()->count());
        $ambos = Incident::query()->whereKeyNot($incidente->id)->sole();
        $this->assertSame([MonitorCommand::KIND_PROMPTS, ['legacy_drift', 'mismatch'], 1], [$ambos->kind, $ambos->evidence['problems'], $ambos->occurrences]);
        $this->assertSame(2, $incidente->fresh()->occurrences, 'el de solo `mismatch` no se toca');
    }

    // ── La llave, el modo en seco y los fallos ───────────────────────────────

    public function test_con_la_llave_de_prompts_en_off_o_sin_canario_no_vigila_y_dice_por_que(): void
    {
        $this->promptsOn();
        $this->reloj('2026-11-11T15:00:04Z');
        $this->stampTurns(1, self::VERSION, $this->instante('2026-11-11T14:40:00Z'), [], self::sello('mismatch', 'K1', self::FORGED));
        $this->stampTurns(1, null, $this->instante('2026-11-11T14:41:00Z'), [], self::sello('legacy_drift', 'K2', self::DRIFTED, ofrecidaStudio: false));

        $lecturas = 0;
        DB::listen(function (QueryExecuted $q) use (&$lecturas): void {
            $lecturas += str_contains($q->sql, 'marketing_ai_actions') ? 1 : 0;
        });
        foreach (['off', 'all', ' ', 'Canario'] as $llave) {
            config(['marketing.ultron.studio_prompts' => $llave]);
            $lecturas = 0;

            [$codigo, $r] = $this->monitor();

            $this->assertSame(0, $codigo, $llave);
            $p = $r['checks']['prompts'];
            $this->assertSame([true, 'not_watched', [], 'prompts_off', 'off', null, null, []],
                [$p['ok'], $p['status'], $p['problems'], $p['reason'], $p['key'], $p['turns'], $p['byState'], $p['fingerprints']], $llave);
            $this->assertSame(0, $lecturas, "con la llave en «{$llave}» no lee los turnos");
        }
        $this->assertSame(0, Incident::query()->count());
        $this->artisan('ultron-studio:monitor', ['--check' => ['prompts']])
            ->expectsOutputToContain('prompts: OK · llave de prompts off · ventana 2026-11-11T14:28:00Z a 2026-11-11T14:58:00Z · no se vigila (prompts_off)')
            ->assertSuccessful();

        // Con la llave en `canary` pero sin conversación canaria: ningún turno que mirar, y lo dice.
        config(['marketing.ultron.studio_prompts' => 'canary', 'marketing.ultron.canary_conversation_id' => null]);
        [$codigo, $r] = $this->monitor();
        $this->assertSame([0, 'not_watched', 'no_canary', 'canary'], [$codigo, $r['checks']['prompts']['status'], $r['checks']['prompts']['reason'], $r['checks']['prompts']['key']]);
        $this->assertSame(0, Incident::query()->count());

        // Y con las dos, alerta: lo de arriba no era que no hubiera nada.
        config(['marketing.ultron.canary_conversation_id' => self::CANARY_ID]);
        [$codigo, $r] = $this->monitor();
        $this->assertSame([1, ['legacy_drift', 'mismatch']], [$codigo, $r['checks']['prompts']['problems']]);
    }

    public function test_en_seco_lo_dice_y_no_escribe_nada(): void
    {
        $this->promptsOn();
        $this->reloj('2026-11-11T15:00:04Z');
        // Siete paquetes alterados distintos: el recuento es exacto y las huellas, como mucho cinco.
        for ($i = 1; $i <= 7; $i++) {
            $this->stampTurns(1, self::VERSION, $this->instante('2026-11-11T14:3'.$i.':00Z'), [], self::sello('mismatch', "S{$i}", sprintf('%08x', $i).str_repeat('e', 56)));
        }

        $escrituras = $this->writesDuring(function () use (&$codigo, &$r): void {
            [$codigo, $r] = $this->monitor(['--dry-run' => true]);
        });

        $this->assertSame(1, $codigo);
        $this->assertTrue($r['dryRun']);
        $p = $r['checks']['prompts'];
        $this->assertSame([['mismatch'], 7, 7], [$p['problems'], $p['turns'], $p['byState']['mismatch']]);
        $this->assertCount(StudioCanaryMeasures::PROMPT_ALERT_FINGERPRINTS, $p['fingerprints']);
        $this->assertSame(['00000003', '00000004', '00000005', '00000006', '00000007'], array_column($p['fingerprints'], 'observed8'), 'ordenadas, de las más recientes');
        $this->assertSame([], $r['incidents']);
        $this->assertSame(0, Incident::query()->count());
        $this->assertSame([], $escrituras, 'en seco no escribe nada');
    }

    public function test_si_la_lectura_de_la_canaria_revienta_es_check_failed_sin_el_mensaje(): void
    {
        $this->promptsOn();
        $this->app->bind(StudioCanaryMeasures::class, static function (): never {
            throw new RuntimeException(self::CENTINELA);
        });

        [$codigo, $r, $salida] = $this->monitor();

        $this->assertSame(1, $codigo);
        $this->assertSame([false, 'error', ['check_failed'], 'RuntimeException'],
            [$r['checks']['prompts']['ok'], $r['checks']['prompts']['status'], $r['checks']['prompts']['problems'], $r['checks']['prompts']['error']]);
        $incidente = Incident::query()->sole();
        $this->assertSame([MonitorCommand::KIND_CHECK_FAILED, Incident::SEVERITY_MEDIUM, 'php artisan ultron-studio:monitor --check=prompts --dry-run'],
            [$incidente->kind, $incidente->severity, $incidente->evidence['check_command']]);
        foreach (['centinela-m1a', 'SQLSTATE', '10.20.30.40'] as $fuga) {
            $this->assertStringNotContainsString($fuga, $salida.json_encode($incidente->evidence).$incidente->title);
        }
    }

    /** Los estados que alertan son los del sello del motor (el Studio no puede importarlo: lo ata esta prueba). */
    public function test_los_estados_de_alerta_son_los_del_sello_del_motor(): void
    {
        $alerta = [UltronPromptStamp::LEGACY_DRIFT, UltronPromptStamp::MISMATCH];
        sort($alerta, SORT_STRING);
        $this->assertSame($alerta, StudioCanaryMeasures::PROMPT_ALERT_STATES);
        foreach ([UltronPromptStamp::APPLIED, UltronPromptStamp::ENGINE_FALLBACK, UltronPromptStamp::LEGACY, UltronPromptStamp::NOT_REPORTED, UltronPromptStamp::UNVERIFIED, UltronPromptStamp::STAMP_ERROR] as $correcto) {
            $this->assertNotContains($correcto, StudioCanaryMeasures::PROMPT_ALERT_STATES);
        }
    }
}

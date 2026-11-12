<?php

namespace Tests\Feature\Outreach\Run;

use App\Models\MarketingAiAction;
use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Core\RelativeTime;
use App\Services\Outreach\Run\LadderInputBuilder;
use App\Services\Outreach\Run\OutreachConfig;
use App\Services\Outreach\Run\OutreachDispatcher;
use App\Services\Outreach\Run\OutreachPlanner;
use App\Services\Outreach\Run\OutreachReplay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * Q1-3 del plan de quejas (enmienda aditiva de D9-7, autorizada por el lead):
 * N6 `complaint_recent`, sobre las clases REALES de la ejecución y con el
 * emisor real de la queja (`MarketingAiActionCommercialObserver`).
 *
 *  - Ninguna estrategia lo quita: la audiencia de estas estrategias no mira
 *    las quejas, y la queja frena igual.
 *  - Efecto de salida: la inscripción sale (`exited`, `complaint_recent`) al
 *    planificar o, si la queja llega después, al despachar (el trabajo se cancela).
 *  - En SOMBRA niega igual que en vivo: no es un aviso.
 *  - La fuente de eventos sin datos falla cerrado; con 0 días el freno se
 *    apaga; la ventana cuenta días de Bogotá; aplica a lo comercial y a la
 *    cobranza, no al servicio (D2 y D3, hasta que decida el propietario).
 *  - N1–N5 siguen decidiendo antes.
 *  - `outreach:replay` reproduce cada decisión.
 *
 * El martes 17-11-2026 a las 10:00 de Bogotá (15:00Z), con la fuente de
 * eventos encendida (L9-26), como el resto de B9-run.
 */
class H9ComplaintRecentTest extends TestCase
{
    use RefreshDatabase;
    use RunWorld;

    private const NOW = '2026-11-17 15:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
        config(['commercial.events_enabled' => true, 'commercial.enabled' => false]);
        $this->threePeople();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** La queja que marca la IA en la conversación del prospecto, por el emisor REAL. */
    private function complaintFromAi(int $leadId): void
    {
        MarketingAiAction::create([
            'lead_id' => $leadId,
            'conversation_id' => (int) DB::table('marketing_conversations')->where('lead_id', $leadId)->value('id'),
            'action_type' => 'staff_review', 'reason' => 'complaint', 'status' => 'created',
            'metadata' => ['needs_staff_review' => true, 'staff_review_reason' => 'complaint', 'intent' => 'complaint'],
        ]);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} la salida y la entrada de la escalera del canal asistido */
    private static function ladderOf(object $decision): array
    {
        $p = json_decode((string) $decision->trace_json, true)[0]['policy']['assisted:whatsapp'];

        return [$p['output'], $p['input']];
    }

    private function assertReplaySame(object $decision): void
    {
        $r = (new OutreachReplay)->replay((string) $decision->uuid);
        $this->assertSame([OutreachReplay::SAME, (string) $decision->trace_hash], [$r['result'], $r['replayedHash']], json_encode($r));
    }

    public function test_en_sombra_la_queja_del_socio_frena_el_plan_y_la_inscripcion_sale_aunque_la_estrategia_no_la_excluya(): void
    {
        $this->runFlags('shadow');
        $this->liveStrategy($this->validContent($this->assistedTemplate(), ['exclusions' => null]));
        $socio = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $this->complaintFromAi($socio['lead']);
        $evento = DB::table('commercial_events')->where('event', 'complaint_created')->first();
        $this->assertSame([$socio['lead'], $socio['member']], [(int) $evento->marketing_lead_id, (int) $evento->member_id], 'el emisor pone el socio del prospecto');

        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $this->assertSame(1, DB::table('outreach_enrollments')->count(), 'la puerta de inscripción no mira la queja: se inscribe');
        $r = $planner->planDue(now());
        $this->assertSame([1, 0, 1], [$r['due'], $r['planned'] ?? 0, $r['exited'] ?? 0], json_encode($r));

        $e = DB::table('outreach_enrollments')->first();
        $this->assertSame([$socio['key'], 'exited', 'complaint_recent'], [$e->subject_key, $e->status, $e->exit_reason]);
        $this->assertSame(0, DB::table('outreach_jobs')->count(), 'ni un contacto, tampoco simulado');
        $salida = DB::table('outreach_decisions')->where('kind', 'exit')->first();
        $this->assertNotNull($salida);
        $this->assertSame(['N6', ['complaint_recent']], [$salida->level, json_decode((string) $salida->reasons, true)]);
        [$escalera, $entrada] = self::ladderOf($salida);
        $this->assertSame(['deny', 'N6', ['complaint_recent'], 'exit_complaint', []], [$escalera['verdict'], $escalera['level'], $escalera['reasons'], $escalera['effect'], $escalera['warnings']]);
        $this->assertSame([true, false], [$entrada['context']['complaintRecently'], $entrada['context']['humanRequestedRecently']]);
        $this->assertSame('shadow', $entrada['flags']['mode']);
        $this->assertStringNotContainsString('3001234567', (string) $salida->trace_json.(string) $salida->facts_json, 'sin teléfono en la traza');

        // `outreach:replay` da la misma huella para la inscripción y para la salida.
        foreach (DB::table('outreach_decisions')->orderBy('id')->get() as $d) {
            $this->assertReplaySame($d);
        }
    }

    public function test_la_queja_que_llega_entre_planificar_y_despachar_cancela_el_trabajo_y_la_inscripcion_sale(): void
    {
        $p = $this->plannedJobWorld(self::utcAt(self::NOW));
        $this->assertSame('planned', $p['job']->status);
        $this->complaintFromAi($p['member']['lead']);

        $d = (new OutreachDispatcher)->dispatch(now(), null, true);
        $this->assertSame([1, ['canceled' => 1]], [$d['claimed'], $d['results']], json_encode($d));
        $trabajo = DB::table('outreach_jobs')->first();
        $this->assertSame(['canceled', 'complaint_recent'], [$trabajo->status, $trabajo->cancel_reason]);
        $e = DB::table('outreach_enrollments')->first();
        $this->assertSame(['exited', 'complaint_recent'], [$e->status, $e->exit_reason]);
        $cancelacion = DB::table('outreach_decisions')->where('kind', 'cancel')->first();
        $this->assertSame(['N6', ['complaint_recent']], [$cancelacion->level, json_decode((string) $cancelacion->reasons, true)]);
        $this->assertSame('exit_complaint', self::ladderOf($cancelacion)[0]['effect']);
        foreach (DB::table('outreach_decisions')->orderBy('id')->get() as $decision) {
            $this->assertReplaySame($decision);
        }

        // La inscripción ya salió: la pasada siguiente no la vuelve a planificar.
        $this->assertSame(0, (new OutreachPlanner)->planDue(now())['due']);
    }

    public function test_la_ventana_cuenta_dias_de_bogota_y_el_dia_30_esta_dentro(): void
    {
        $this->runFlags('shadow');
        $this->liveStrategy($this->validContent($this->assistedTemplate(), ['exclusions' => null]));
        // Hoy, 17-11 de Bogotá. El 18-10 de Bogotá es hace 30 días (dentro); el 17-10, hace 31 (fuera).
        $dentro = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234561');
        $this->commercialEvent('complaint_created', '2026-10-18 05:00:00', $dentro['member']);       // 18-10 00:00:00 de Bogotá
        $fuera = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234562');
        $this->commercialEvent('complaint_created', '2026-10-18 04:59:59', $fuera['member']);        // 17-10 23:59:59 de Bogotá
        $hoy = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234563');
        $this->commercialEvent('complaint_created', '2026-11-17 14:59:59', null, $hoy['lead']);       // hace un segundo, por el prospecto
        $futura = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234564');
        $this->commercialEvent('complaint_created', '2026-11-17 15:00:01', $futura['member']);        // posterior a «ahora»: no cuenta

        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $planner->planDue(now());
        $estado = DB::table('outreach_enrollments')->pluck('exit_reason', 'subject_key')->all();
        $this->assertSame('complaint_recent', $estado[$dentro['key']]);
        $this->assertSame('complaint_recent', $estado[$hoy['key']], 'la queja del prospecto frena a su socio');
        $this->assertNull($estado[$fuera['key']], 'hace 31 días de Bogotá: fuera de la ventana, se planifica');
        $this->assertNull($estado[$futura['key']]);
        $this->assertSame(2, DB::table('outreach_jobs')->count());

        // La función que arma la entrada, en el mismo borde.
        $ahora = self::utcAt(self::NOW);
        $evento = static fn (string $utc): array => [['key' => 'complaint_created', 'at' => RelativeTime::format(self::utcAt($utc))]];
        $this->assertTrue(LadderInputBuilder::complaintRecently($evento('2026-10-18 05:00:00'), $ahora, 'commercial'));
        $this->assertFalse(LadderInputBuilder::complaintRecently($evento('2026-10-18 04:59:59'), $ahora, 'commercial'));
        $this->assertFalse(LadderInputBuilder::complaintRecently([['key' => 'human_requested', 'at' => '2026-11-17T14:00:00Z']], $ahora, 'commercial'), 'otro evento no es una queja');
    }

    public function test_aplica_a_lo_comercial_y_a_la_cobranza_no_al_servicio_y_sin_datos_falla_cerrado(): void
    {
        $ahora = self::utcAt(self::NOW);
        $queja = [['key' => 'complaint_created', 'at' => '2026-11-16T15:00:00Z']];
        $this->assertSame(
            ['commercial' => true, 'collections' => true, 'service' => false],
            array_map(static fn (string $f): ?bool => LadderInputBuilder::complaintRecently($queja, $ahora, $f), ['commercial' => 'commercial', 'collections' => 'collections', 'service' => 'service']),
        );
        $this->assertSame(
            ['commercial' => null, 'collections' => null, 'service' => false],
            array_map(static fn (string $f): ?bool => LadderInputBuilder::complaintRecently(null, $ahora, $f), ['commercial' => 'commercial', 'collections' => 'collections', 'service' => 'service']),
            'fuente sin datos: desconocido (N6 niega) donde aplica; el servicio no se toca',
        );
        $this->assertFalse(LadderInputBuilder::complaintRecently([], $ahora, 'commercial'), 'sin quejas');
    }

    public function test_con_la_fuente_de_eventos_apagada_niega_tambien_por_la_queja(): void
    {
        config(['commercial.events_enabled' => false]);
        $this->runFlags('shadow');
        $this->liveStrategy($this->validContent($this->assistedTemplate(), ['exclusions' => null]));
        $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $planner->planDue(now());

        $d = DB::table('outreach_decisions')->where('level', 'N6')->first();
        [$escalera, $entrada] = self::ladderOf($d);
        $this->assertSame([null, null], [$entrada['context']['humanRequestedRecently'], $entrada['context']['complaintRecently']]);
        $this->assertSame(['human_requested_recently', 'complaint_recent'], $escalera['reasons']);
        $this->assertSame('handoff', $escalera['effect'], 'manda el efecto del primero en el orden de N6');
        $this->assertSame(0, DB::table('outreach_jobs')->count());
    }

    public function test_con_cero_dias_la_queja_no_frena_y_la_configuracion_se_lee_sin_clave_nueva(): void
    {
        // Sin clave en config/outreach.php: 30 días propios y las finalidades, todas las del catálogo menos el servicio.
        $this->assertNull(config('outreach.complaint_days'));
        $this->assertNull(config('outreach.complaint_purposes'));
        $this->assertSame([30, 30], [OutreachConfig::humanRequestedDays(), OutreachConfig::complaintDays()]);
        $this->assertSame(['collections', 'commercial'], OutreachConfig::complaintPurposes());
        $this->assertSame(array_values(array_diff(OutreachCatalog::PURPOSES, ['service'])), OutreachConfig::complaintPurposes(), 'una finalidad nueva nacería frenada');

        // M-1 de security. La ventana de la queja es propia: acortar la de human_requested no la acorta…
        config(['outreach.human_requested_days' => 1]);
        $this->assertSame([1, 30], [OutreachConfig::humanRequestedDays(), OutreachConfig::complaintDays()]);
        // …y SOLO 0 la apaga: un negativo, un texto, un decimal o nada dejan los 30 (el lado seguro); como mucho 365.
        foreach ([[7, 7], [0, 0], [400, 365], [-3, 30], ['7', 30], [null, 30], [1.5, 30], [true, 30]] as [$valor, $esperado]) {
            config(['outreach.complaint_days' => $valor]);
            $this->assertSame($esperado, OutreachConfig::complaintDays(), json_encode($valor));
        }
        // Las finalidades: una lista de textos del catálogo que incluya la comercial. Cualquier otra cosa (vacía, sin la
        // comercial, un nombre mal escrito, un texto, un mapa, una lista anidada o con números) deja el valor por
        // defecto, sin lanzar: el freno no se apaga vaciando la lista, sino con 0 días.
        $porDefecto = ['collections', 'commercial'];
        foreach ([
            [['commercial'], ['commercial']], [['collections', 'commercial'], $porDefecto], [['service', 'commercial'], ['commercial', 'service']],
            [[], $porDefecto], [['service'], $porDefecto], [['collections'], $porDefecto], [['comercial'], $porDefecto], [['commercial '], $porDefecto],
            ['commercial', $porDefecto], [['a' => 'commercial'], $porDefecto], [[['commercial']], $porDefecto], [[1, 'commercial'], $porDefecto],
        ] as [$valor, $esperado]) {
            config(['outreach.complaint_purposes' => $valor]);
            $this->assertSame($esperado, OutreachConfig::complaintPurposes(), json_encode($valor));
        }
        config(['outreach.human_requested_days' => 30, 'outreach.complaint_purposes' => null]);

        // De extremo a extremo: con 0 días la queja no frena.
        config(['outreach.complaint_days' => 0]);
        $this->runFlags('shadow');
        $this->liveStrategy($this->validContent($this->assistedTemplate(), ['exclusions' => null]));
        $socio = $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $this->complaintFromAi($socio['lead']);
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $this->assertSame(1, $planner->planDue(now())['planned']);
        [$escalera, $entrada] = self::ladderOf(DB::table('outreach_decisions')->where('kind', 'plan')->first());
        $this->assertSame([false, 'allow'], [$entrada['context']['complaintRecently'], $escalera['verdict']]);
    }

    public function test_replay_de_una_decision_anterior_al_cambio_dice_ladder_input_outdated_sin_caerse(): void
    {
        $this->runFlags('shadow');
        $this->liveStrategy($this->validContent($this->assistedTemplate(), ['exclusions' => null]));
        $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $planner->planDue(now());
        $plan = DB::table('outreach_decisions')->where('kind', 'plan')->first();
        $this->assertReplaySame($plan);

        // La misma decisión tal como la habría guardado la escalera de antes de Q1-3: sin `context.complaintRecently`.
        // La tabla es de solo inserción, así que va como una fila nueva (otro uuid) con su propia huella.
        $traza = json_decode((string) $plan->trace_json, true);
        foreach (array_keys($traza[0]['policy']) as $canal) {
            unset($traza[0]['policy'][$canal]['input']['context']['complaintRecently']);
        }
        $json = json_encode($traza, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $antigua = (array) $plan;
        unset($antigua['id']);
        $antigua = ['uuid' => '0b5a1f2e-6c4d-4e8f-9a1b-2c3d4e5f6a7b', 'trace_json' => $json, 'trace_hash' => hash('sha256', $json)] + $antigua;
        DB::table('outreach_decisions')->insert($antigua);

        $r = (new OutreachReplay)->replay('0b5a1f2e-6c4d-4e8f-9a1b-2c3d4e5f6a7b');
        $this->assertSame([OutreachReplay::NOT_REPRODUCIBLE, 'ladder_input_outdated', null], [$r['result'], $r['reason'], $r['replayedHash']]);

        // La orden lo dice y cierra su pasada (antes la excepción se escapaba y la pasada quedaba `running`).
        $this->artisan('outreach:replay', ['decision' => '0b5a1f2e-6c4d-4e8f-9a1b-2c3d4e5f6a7b'])
            ->expectsOutputToContain('no reproducible: ladder_input_outdated')->assertExitCode(2);
        $this->assertSame(['completed'], DB::table('outreach_runs')->where('kind', 'replay')->pluck('status')->all());

        // La decisión nueva sigue saliendo igual.
        $this->assertReplaySame($plan);
    }

    public function test_replay_de_una_entrada_con_un_tipo_mal_dice_ladder_input_invalid_y_no_la_da_por_antigua(): void
    {
        $this->runFlags('shadow');
        $this->liveStrategy($this->validContent($this->assistedTemplate(), ['exclusions' => null]));
        $this->qualifyingMember(self::utcAt(self::NOW), 5, '3001234567');
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $planner->planDue(now());
        $plan = DB::table('outreach_decisions')->where('kind', 'plan')->first();

        // Están todas las claves que la escalera exige, pero una trae un tipo que rechaza: no es una decisión antigua
        // (revisión de la ola 5, menor 3: «anterior al cambio» sería una explicación benigna de un defecto real).
        $traza = json_decode((string) $plan->trace_json, true);
        foreach (array_keys($traza[0]['policy']) as $canal) {
            $traza[0]['policy'][$canal]['input']['context']['complaintRecently'] = 'no';
        }
        $json = json_encode($traza, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $mala = (array) $plan;
        unset($mala['id']);
        $mala = ['uuid' => '1c6b2a3f-7d5e-4f9a-8b2c-3d4e5f6a7b8c', 'trace_json' => $json, 'trace_hash' => hash('sha256', $json)] + $mala;
        DB::table('outreach_decisions')->insert($mala);

        $r = (new OutreachReplay)->replay('1c6b2a3f-7d5e-4f9a-8b2c-3d4e5f6a7b8c');
        $this->assertSame([OutreachReplay::NOT_REPRODUCIBLE, 'ladder_input_invalid', null], [$r['result'], $r['reason'], $r['replayedHash']]);
    }

    public function test_n1_decide_antes_que_la_queja(): void
    {
        $this->runFlags('shadow');
        $this->liveStrategy($this->validContent($this->assistedTemplate(), ['exclusions' => null]));
        $r = $this->expiringMember(self::utcAt(self::NOW), 5);
        $lead = $this->lead(['member_id' => $r['member'], 'phone' => '3001234567', 'consent_status' => 'pending']);
        $this->conversation($lead);
        $this->canary('+573001234567', 'whatsapp');
        $this->complaintFromAi($lead);
        $planner = new OutreachPlanner;
        $planner->sweep(now(), true);
        $planner->planDue(now());

        $d = DB::table('outreach_decisions')->where('kind', 'deny')->first();
        $this->assertSame(['N1', ['no_consent_for_purpose_channel']], [$d->level, json_decode((string) $d->reasons, true)]);
        [$escalera, $entrada] = self::ladderOf($d);
        $this->assertTrue($entrada['context']['complaintRecently'], 'la queja está en la entrada');
        $this->assertSame('not_evaluated', array_column($escalera['levels'], 'status', 'level')['N6'], 'pero N6 ni se evalúa');
        $this->assertNull($escalera['effect']);
        $this->assertNotSame('complaint_recent', DB::table('outreach_enrollments')->value('exit_reason'));
        $this->assertReplaySame($d);
    }
}

<?php

namespace Tests\Feature\Outreach\Run;

use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Run\OutreachConfig;
use App\Services\Outreach\Run\OutreachException;
use App\Services\Outreach\Run\OutreachMonitor;
use App\Services\Outreach\Run\OutreachSendGate;
use App\Services\Outreach\Run\OutreachStop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * Parte 1 de B9-run (contrato H9, §3 protecciones 1 y 2, D9-14, §9.1,
 * L9-7, L9-44; T15): la configuración nace apagada, la constante cerrada,
 * la PARADA GLOBAL por consola y la API, soltar a cuatro ojos de verdad y el
 * monitor que solo apaga.
 */
class OutreachStopAndGateTest extends TestCase
{
    use RefreshDatabase;
    use RunWorld;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Carbon::setTestNow(Carbon::parse('2026-11-17 15:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Protecciones 1 y 2 ──────────────────────────────────────────────────

    public function test_todo_nace_apagado_y_la_constante_de_envio_cerrada(): void
    {
        $this->assertFalse(OutreachConfig::enabled());
        $this->assertSame('off', OutreachConfig::mode());
        $this->assertFalse(OutreachConfig::active());
        $this->assertFalse(OutreachConfig::schedulerEnabled());
        $this->assertFalse(OutreachConfig::monitorEnabled());
        $this->assertFalse(OutreachConfig::assistedEnabled());
        $this->assertSame(0, OutreachConfig::assistedDailyCap());
        $this->assertFalse(OutreachConfig::whatsappEnabled());
        $this->assertSame(0, OutreachConfig::whatsappDailyCap());
        $this->assertSame(0, OutreachConfig::whatsappDailyBudgetMicros());
        $this->assertFalse(OutreachConfig::voiceEnabled());
        $this->assertFalse(OutreachConfig::classifierEnabled());
        $this->assertNull(OutreachConfig::ownHours());
        $this->assertSame(['perDay' => 1, 'perWeek' => 2, 'minHoursBetween' => 48], OutreachConfig::effectiveLimits());

        // La constante: un literal `false`, una sola vez en el árbol, y la mantiene cerrada aunque todo lo demás se encienda.
        $this->assertFalse(OutreachSendGate::SENDS_ENABLED_IN_CODE);
        $fuente = (string) file_get_contents(app_path('Services/Outreach/Run/OutreachSendGate.php'));
        $this->assertSame(1, preg_match_all('/public const SENDS_ENABLED_IN_CODE = false;/', $fuente));
        $this->assertSame(0, preg_match_all('/SENDS_ENABLED_IN_CODE\s*=\s*true/', $fuente));
        $this->runFlags('live', ['outreach.whatsapp.enabled' => true, 'outreach.voice.enabled' => true]);
        foreach (OutreachCatalog::AUTOMATIC_CHANNELS as $canal) {
            $this->assertFalse(OutreachSendGate::automaticSendAllowed($canal), $canal);
            $this->assertFalse(OutreachSendGate::channelFlags($canal)['sendsEnabledInCode']);
            $this->assertFalse(OutreachSendGate::channelFlags($canal)['channelLive'], 'W9/V9: la puerta no existe el 15-11');
        }
        $this->assertFalse(OutreachSendGate::automaticSendAllowed('assisted'), 'el asistido no es un envío automático');
        $this->assertTrue(OutreachSendGate::channelFlags('assisted:whatsapp')['channelLive']);
        $this->assertTrue(OutreachSendGate::channelFlags('assisted:voice')['channelEnabled']);
    }

    public function test_un_modo_desconocido_es_off_y_una_cadena_no_enciende_nada(): void
    {
        config(['outreach.enabled' => 'true', 'outreach.mode' => 'LIVE']);
        $this->assertFalse(OutreachConfig::enabled(), 'una cadena no enciende');
        $this->assertSame('off', OutreachConfig::mode());
        config(['outreach.enabled' => true, 'outreach.mode' => 'canary']);
        $this->assertSame('off', OutreachConfig::mode());
        $this->assertFalse(OutreachConfig::active());
    }

    public function test_el_horario_propio_solo_estrecha_y_mal_escrito_cierra(): void
    {
        config(['outreach.own_hours' => '09:00-18:00']);
        $this->assertSame(['weekdays' => [540, 1080], 'saturday' => [540, 1080]], OutreachConfig::ownHours());
        config(['outreach.own_hours' => '09:00-18:00;09:00-13:00']);
        $this->assertSame(['weekdays' => [540, 1080], 'saturday' => [540, 780]], OutreachConfig::ownHours());
        config(['outreach.own_hours' => '09:00-18:00;-']);
        $this->assertSame(['weekdays' => [540, 1080], 'saturday' => null], OutreachConfig::ownHours());
        config(['outreach.own_hours' => 'legal']);
        $this->assertNull(OutreachConfig::ownHours());
        foreach (['9-18', '25:00-26:00', 'lunes', '-;-'] as $malo) {
            config(['outreach.own_hours' => $malo]);
            $this->assertSame('invalid', OutreachConfig::ownHours(), $malo);
        }
    }

    // ── PARADA GLOBAL ───────────────────────────────────────────────────────

    public function test_outreach_stop_echa_la_parada_global_desde_la_consola_y_es_idempotente(): void
    {
        $this->threePeople();
        $vivo = $this->liveStrategy($this->validContent($this->assistedTemplate()));
        $inscripcion = $this->insertEnrollment($vivo);
        $this->insertJob($inscripcion);

        $this->artisan('outreach:stop', ['--reason' => 'Parada por revisión'])->assertExitCode(2);
        $this->artisan('outreach:stop', ['--all' => true])->assertExitCode(2);
        $this->artisan('outreach:stop', ['--all' => true, '--channel' => 'assisted', '--reason' => 'Dos alcances'])->assertExitCode(2);
        $this->assertSame(0, DB::table('outreach_brakes')->count());

        $this->artisan('outreach:stop', ['--all' => true, '--reason' => 'Parada por revisión del propietario'])
            ->expectsOutputToContain('ECHADO')
            ->expectsOutputToContain('Retenidos   : 1 trabajo(s)')
            ->assertExitCode(0);
        $freno = DB::table('outreach_brakes')->first();
        $this->assertSame('global', $freno->scope);
        $this->assertNull($freno->scope_ref);
        $this->assertSame('manual', $freno->reason);
        $this->assertSame('person', $freno->opened_by_type);
        $this->assertSame(OutreachStop::CONSOLE_ADMIN_ID, (int) $freno->opened_by_admin_id);
        $this->assertSame(OutreachStop::CONSOLE_NAME, $freno->opened_by_name);
        $this->assertNull($freno->released_at);
        $this->assertTrue((new OutreachStop)->isGlobalOpen());

        $this->artisan('outreach:stop', ['--all' => true, '--reason' => 'Otra vez'])->expectsOutputToContain('Ya estaba echado')->assertExitCode(0);
        $this->assertSame(1, DB::table('outreach_brakes')->count(), 'uno abierto por alcance');

        // El trabajo planificado no se toca: espera (D9-14).
        $this->assertSame('planned', DB::table('outreach_jobs')->value('status'));
    }

    public function test_un_freno_por_estrategia_y_por_canal_con_su_alcance_y_con_una_cuenta(): void
    {
        $gente = $this->threePeople();
        $vivo = $this->liveStrategy($this->validContent($this->assistedTemplate()));
        $this->artisan('outreach:stop', ['--strategy' => $vivo['strategyUuid'], '--reason' => 'Pausa operativa', '--by' => $gente['ana']->email])->assertExitCode(0);
        $this->artisan('outreach:stop', ['--channel' => 'assisted', '--reason' => 'Sin personal hoy', '--by' => (string) $gente['beto']->id])->assertExitCode(0);
        $this->artisan('outreach:stop', ['--channel' => 'simulated', '--reason' => 'No se frena'])->assertExitCode(2);
        $this->artisan('outreach:stop', ['--strategy' => 'no-es-uuid', '--reason' => 'Mal escrito'])->assertExitCode(2);
        $this->artisan('outreach:stop', ['--all' => true, '--reason' => 'x', '--by' => 'nadie@run.test'])->assertExitCode(2);

        $abiertos = (new OutreachStop)->openScopes();
        $this->assertFalse($abiertos['global']);
        $this->assertSame(['assisted'], $abiertos['channels']);
        $this->assertSame([$vivo['strategyUuid']], $abiertos['strategies']);
        $porEstrategia = DB::table('outreach_brakes')->where('scope', 'strategy')->first();
        $this->assertSame((int) $gente['ana']->id, (int) $porEstrategia->opened_by_admin_id);
        $this->assertSame($this->people()[1], (int) $porEstrategia->opened_by_person_id, 'la persona verificada de la cuenta queda en el freno');
        $this->assertSame(['global' => false, 'channel' => true, 'strategy' => true], OutreachStop::ladderBrakes($abiertos, $vivo['strategyUuid'], 'assisted'));
        $this->assertSame(['global' => false, 'channel' => false, 'strategy' => false], OutreachStop::ladderBrakes($abiertos, (string) Str::uuid(), 'simulated'));
    }

    // ── Soltar a cuatro ojos (L9-7, P9-20) ──────────────────────────────────

    public function test_soltar_exige_otra_cuenta_y_otra_persona_una_nota_y_una_sola_vez(): void
    {
        $gente = $this->threePeople();
        $stop = new OutreachStop;
        $abierto = $stop->open('global', null, 'manual', 'Parada de la prueba', $gente['ana'], (string) Str::uuid())['brake'];

        // Ana no suelta lo que abrió.
        try {
            $stop->release($gente['ana'], $abierto['uuid'], 'Revisado y corregido', (string) Str::uuid());
            $this->fail('La misma cuenta soltó su propio freno.');
        } catch (OutreachException $e) {
            $this->assertSame('four_eyes_required', $e->errorCode);
            $this->assertSame(403, $e->status);
        }
        // Dani es otra cuenta de la MISMA persona: tampoco.
        $dani = $this->secondAccountOfAna();
        try {
            $stop->release($dani, $abierto['uuid'], 'Revisado y corregido', (string) Str::uuid());
            $this->fail('Otra cuenta de la misma persona soltó el freno.');
        } catch (OutreachException $e) {
            $this->assertSame('four_eyes_required', $e->errorCode);
        }
        // La nota: obligatoria, sin teléfonos ni correos.
        foreach (['', 'ok', 'Llamar al 300 123 4567', 'escribe a alguien@correo.com'] as $mala) {
            try {
                $stop->release($gente['caro'], $abierto['uuid'], $mala, (string) Str::uuid());
                $this->fail("Aceptó la nota «{$mala}».");
            } catch (OutreachException $e) {
                $this->assertSame(422, $e->status, $mala);
            }
        }
        $this->assertTrue($stop->isGlobalOpen(), 'sigue echado');

        // Caro (otra cuenta, otra persona) lo suelta, y una sola vez.
        $peticion = (string) Str::uuid();
        $suelto = $stop->release($gente['caro'], $abierto['uuid'], 'Revisado: la causa se corrigió', $peticion);
        $this->assertTrue($suelto['released']);
        $this->assertSame('Caro', $suelto['releasedBy']);
        $this->assertFalse($stop->isGlobalOpen());
        $this->assertSame($suelto, $stop->release($gente['caro'], $abierto['uuid'], 'Revisado: la causa se corrigió', $peticion), 'la misma petición devuelve lo mismo');
        try {
            $stop->release($gente['beto'], $abierto['uuid'], 'Otra nota', (string) Str::uuid());
            $this->fail('Se soltó dos veces.');
        } catch (OutreachException $e) {
            $this->assertSame('brake_already_released', $e->errorCode);
        }
        try {
            $stop->release($gente['beto'], (string) Str::uuid(), 'Nota', (string) Str::uuid());
            $this->fail('Soltó un freno que no existe.');
        } catch (OutreachException $e) {
            $this->assertSame(404, $e->status);
        }
    }

    public function test_un_freno_de_la_consola_lo_suelta_una_persona_y_la_lista_no_lleva_datos_personales(): void
    {
        $gente = $this->threePeople();
        $stop = new OutreachStop;
        $r = $stop->stopAll('Parada desde la consola', null);
        $this->assertFalse($r['replayed']);
        $this->assertSame(0, $r['retainedJobs']);
        $otra = $stop->stopAll('Repetida', null);
        $this->assertTrue($otra['replayed']);
        $this->assertSame($r['brake']['uuid'], $otra['brake']['uuid']);

        $suelto = $stop->release($gente['beto'], $r['brake']['uuid'], 'La consola la echó por una prueba; revisado', (string) Str::uuid());
        $this->assertTrue($suelto['released']);
        $lista = $stop->list();
        $this->assertCount(1, $lista);
        $this->assertSame(['uuid', 'scope', 'scopeRef', 'reason', 'note', 'openedBy', 'openedAt', 'released', 'releasedAt', 'releasedBy', 'releaseNote'], array_keys($lista[0]));
        $this->assertSame(['type' => 'person', 'name' => OutreachStop::CONSOLE_NAME], $lista[0]['openedBy']);
    }

    // ── El monitor solo apaga (D9-14) ───────────────────────────────────────

    public function test_el_monitor_apagado_no_escribe_y_encendido_echa_el_freno_global_con_la_cadena_ilegible(): void
    {
        $this->threePeople();
        $r = (new OutreachMonitor)->run();
        $this->assertTrue($r['skipped']);
        $this->assertSame(0, DB::table('outreach_runs')->count());

        config(['outreach.monitor.enabled' => true]);
        $this->assertTrue((new OutreachMonitor)->run()['skipped'], 'con la maestra apagada no vigila nada');
        $this->runFlags('shadow', ['outreach.monitor.enabled' => true]);

        // Una cadena sana (firmada de verdad): nada que frenar.
        $vivo = $this->liveStrategy($this->validContent($this->assistedTemplate()));
        $r = (new OutreachMonitor)->run();
        $this->assertFalse($r['skipped']);
        $this->assertSame([], $r['opened']);
        $this->assertSame('ok', $r['checks']['chain']['head']);
        $this->assertSame('completed', DB::table('outreach_runs')->where('kind', 'monitor')->value('status'));

        // Una entrada con la firma de relleno en la cabeza: ilegible → PARADA GLOBAL automática con su incidente, una sola vez.
        $this->appendPublication(['strategy_id' => $vivo['strategy'], 'version_id' => null, 'action' => 'pause', 'reason' => 'Firma rota']);
        $this->artisan('outreach:monitor')->expectsOutputToContain('global por chain_unreadable')->assertExitCode(1);
        $freno = DB::table('outreach_brakes')->where('scope', 'global')->first();
        $this->assertNotNull($freno);
        $this->assertSame('chain_unreadable', $freno->reason);
        $this->assertSame('system', $freno->opened_by_type);
        $this->assertNull($freno->opened_by_admin_id);
        $incidente = DB::table('incidents')->where('source', 'outreach')->first();
        $this->assertNotNull($incidente);
        $this->assertSame('outreach_brake', $incidente->kind);
        $this->assertSame('high', $incidente->severity);
        $this->artisan('outreach:monitor')->assertExitCode(0);
        $this->assertSame(1, DB::table('outreach_brakes')->count(), 'idempotente: uno abierto por alcance');
        $this->assertSame(3, DB::table('outreach_runs')->where('kind', 'monitor')->where('status', 'completed')->count());
        // El monitor nunca suelta.
        $this->assertNull(DB::table('outreach_brakes')->value('released_at'));
    }

    public function test_el_monitor_echa_el_freno_de_una_estrategia_con_trabajos_vivos_que_ya_no_esta_activa(): void
    {
        $this->threePeople();
        $this->runFlags('shadow', ['outreach.monitor.enabled' => true]);
        $vivo = $this->liveStrategy($this->validContent($this->assistedTemplate()));
        $inscripcion = $this->insertEnrollment($vivo);
        $this->insertJob($inscripcion);
        $this->assertSame([], (new OutreachMonitor)->run()['opened']);

        $this->pauseStrategy($vivo);
        $r = (new OutreachMonitor)->run();
        $this->assertSame([['scope' => 'strategy', 'scopeRef' => $vivo['strategyUuid'], 'reason' => 'drift']], $r['opened']);
        $this->assertSame([$vivo['strategyUuid']], (new OutreachStop)->openScopes()['strategies']);
    }
}

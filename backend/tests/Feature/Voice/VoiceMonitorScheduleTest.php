<?php

namespace Tests\Feature\Voice;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El monitor de la voz en el programador (contrato H8, D8-21, E2-3, E2-6 y
 * E6-5; OPS8-b): un bloque PROPIO de `routes/console.php` bajo
 * `voice.monitor.enabled`, nunca dentro del del monitor del Studio (m-3 del
 * H7). Con la bandera apagada no se programa ninguna tarea de voz; encendida,
 * exactamente tres: la conciliación cada 10 minutos (cinco pasadas sin
 * `--numbers` y la sexta, en el minuto 0, con él), la deriva del número cada
 * hora y la verificación semanal de ElevenLabs. Ninguna toca el bloque del
 * Studio ni depende de su bandera.
 */
class VoiceMonitorScheduleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /** @return list<Event> todo lo que programa routes/console.php con la configuración de ahora */
    private function scheduled(): array
    {
        $this->app->instance(Schedule::class, new Schedule);
        Facade::clearResolvedInstances();
        require base_path('routes/console.php');

        return array_values($this->app->make(Schedule::class)->events());
    }

    /** Las del bloque de voz: las órdenes `voice:` y la verificación de la voz. */
    private static function isVoiceTask(Event $e): bool
    {
        $orden = (string) $e->command;

        return preg_match('/(?<![a-z])voice:/', $orden) === 1 || str_contains($orden, '--verify-voice');
    }

    /** Las del monitor del Studio (H7): sus dos órdenes, sin las de la voz. */
    private static function isStudioMonitorTask(Event $e): bool
    {
        return str_contains((string) $e->command, 'ultron-studio:') && ! self::isVoiceTask($e);
    }

    /** @return list<Event> */
    private function voiceTasks(): array
    {
        return array_values(array_filter($this->scheduled(), self::isVoiceTask(...)));
    }

    public function test_con_la_bandera_apagada_no_se_programa_ninguna_tarea_de_voz(): void
    {
        foreach ([false, true] as $studio) {
            config(['voice.monitor.enabled' => false, 'ultron_studio.monitor.enabled' => $studio]);
            $this->assertSame([], $this->voiceTasks(), 'monitor del Studio '.($studio ? 'encendido' : 'apagado'));
        }
    }

    public function test_con_la_bandera_encendida_exactamente_las_cuatro_tareas_de_voz(): void
    {
        config(['voice.monitor.enabled' => true, 'ultron_studio.monitor.enabled' => false]);
        $porOrden = [];
        foreach ($this->voiceTasks() as $e) {
            $orden = (string) preg_replace('/^.*\'artisan\' /', '', (string) $e->command);
            $this->assertArrayNotHasKey($orden, $porOrden, "{$orden} programada dos veces");
            $porOrden[$orden] = $e;
        }
        $ordenes = array_keys($porOrden);
        sort($ordenes, SORT_STRING);
        $this->assertSame(
            ['ultron-studio:integrations-snapshot --verify-voice', 'voice:prune-rate-limits', 'voice:reconcile', 'voice:reconcile --numbers'],
            $ordenes,
            'la conciliación, la del número, la purga diaria de los límites (E8-6) y la verificación semanal; nada más',
        );

        // Cada 10 minutos en total, sin dos a la vez en el mismo minuto: cinco sin --numbers y la del minuto 0 con él.
        $this->assertSame('10,20,30,40,50 * * * *', $porOrden['voice:reconcile']->expression);
        $this->assertSame('0 * * * *', $porOrden['voice:reconcile --numbers']->expression);
        // La purga de los límites caducados (E8-6): a las 04:40 de Bogotá.
        $this->assertSame('40 4 * * *', $porOrden['voice:prune-rate-limits']->expression);
        $this->assertSame('America/Bogota', $porOrden['voice:prune-rate-limits']->timezone);
        // Los lunes a las 07:20 de Bogotá.
        $this->assertSame('20 7 * * 1', $porOrden['ultron-studio:integrations-snapshot --verify-voice']->expression);
        $this->assertSame('America/Bogota', $porOrden['ultron-studio:integrations-snapshot --verify-voice']->timezone);

        foreach ($porOrden as $orden => $e) {
            $this->assertTrue($e->withoutOverlapping, "{$orden}: sin solapes");
            $this->assertSame(10, $e->expiresAt, "{$orden}: diez minutos de cerrojo, no las 24 h por defecto");
            $this->assertTrue($e->onOneServer, "{$orden}: un solo servidor");
            $this->assertStringEndsWith("'artisan' {$orden}", (string) $e->command, "{$orden}: una orden de artisan, sin clausuras ni trabajos");
        }
    }

    public function test_el_bloque_de_voz_no_depende_del_monitor_del_studio_ni_lo_cambia(): void
    {
        config(['voice.monitor.enabled' => false, 'ultron_studio.monitor.enabled' => false]);
        $base = count($this->scheduled());

        foreach ([[false, false], [false, true], [true, false], [true, true]] as [$voz, $studio]) {
            config(['voice.monitor.enabled' => $voz, 'ultron_studio.monitor.enabled' => $studio]);
            $eventos = $this->scheduled();
            $caso = 'voz '.($voz ? 'on' : 'off').' · Studio '.($studio ? 'on' : 'off');
            // Cuatro tareas de voz (E8-6 añadió la purga diaria de los límites).
            $this->assertCount($voz ? 4 : 0, array_filter($eventos, self::isVoiceTask(...)), $caso);
            $this->assertCount($studio ? 2 : 0, array_filter($eventos, self::isStudioMonitorTask(...)), $caso.': el monitor del Studio sigue con sus 2');
            $this->assertCount($base + ($voz ? 4 : 0) + ($studio ? 2 : 0), $eventos, $caso.': nada más cambia en el programador');
        }
    }

    public function test_la_bandera_solo_se_enciende_con_true_1_on_o_yes(): void
    {
        $leer = function (?string $valor): bool {
            $antes = getenv('VOICE_MONITOR_ENABLED');
            unset($_SERVER['VOICE_MONITOR_ENABLED'], $_ENV['VOICE_MONITOR_ENABLED']);
            putenv('VOICE_MONITOR_ENABLED');
            if ($valor !== null) {
                $_SERVER['VOICE_MONITOR_ENABLED'] = $_ENV['VOICE_MONITOR_ENABLED'] = $valor;
                putenv("VOICE_MONITOR_ENABLED={$valor}");
            }
            try {
                return (require base_path('config/voice.php'))['monitor']['enabled'];
            } finally {
                unset($_SERVER['VOICE_MONITOR_ENABLED'], $_ENV['VOICE_MONITOR_ENABLED']);
                putenv('VOICE_MONITOR_ENABLED');
                if ($antes !== false) {
                    putenv("VOICE_MONITOR_ENABLED={$antes}");
                }
            }
        };

        $this->assertFalse($leer(null), 'sin la variable: apagada');
        foreach (['off', 'no', 'false', '0', '', 'si', 'enabled', ' activa'] as $valor) {
            $this->assertFalse($leer($valor), json_encode($valor));
        }
        foreach (['true', 'TRUE', '1', 'on', 'yes'] as $valor) {
            $this->assertTrue($leer($valor), $valor);
        }
    }
}

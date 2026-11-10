<?php

namespace Tests\Unit\Outreach\Core;

use App\Services\Commercial\ContactPolicy;
use App\Services\Outreach\Core\CanonicalCondition;
use App\Services\Outreach\Core\ContactBudget;
use App\Services\Outreach\Core\ContactCalendar;
use App\Services\Outreach\Core\RelativeTime;
use App\Services\Outreach\Core\StrategyContent;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Lo que necesita la aplicación de Laravel, SIN base de datos ni red:
 *
 *  - el 422 «con todas las claves a la vez» (T11) que lanzan
 *    `CanonicalCondition::parse()` y `StrategyContent::parse()` (el validador
 *    de Laravel construye la excepción);
 *  - T9 contra el `ContactPolicy` REAL en su mitad de horario: para cada media
 *    hora de dos semanas (con el lunes festivo 16-11-2026), si sus horas de
 *    silencio niegan, el calendario del H9 también. Su mitad de frecuencia
 *    consulta `marketing_messages` (base de datos): la cubre `parity` de
 *    `contact-budget-cases.json` contra su modelo, y la prueba contra la clase
 *    con base queda para B9-run/TEST9 (informe, Q-14).
 *  - los suelos del presupuesto atados a la configuración real de hoy
 *    (`commercial.contact_limits` y `voice.outbound.frequency.per_week`).
 */
class CoreLaravelBoundaryTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_el_422_lleva_todas_las_claves(): void
    {
        try {
            CanonicalCondition::parse(['all' => [['fact' => 'membership.active', 'op' => 'gt', 'value' => 1], ['fact' => 'no.existe', 'op' => 'eq', 'value' => 1], ['sql' => 'SELECT 1']]]);
            $this->fail('No lanzó.');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame(['conditions.all.0.op', 'conditions.all.1.fact', 'conditions.all.2'], array_keys($e->errors()));
            $this->assertStringNotContainsString('SELECT', json_encode($e->errors()));
        }
        try {
            StrategyContent::parse(['format' => 'strategy-content/2', 'goal' => 'vender']);
            $this->fail('No lanzó.');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->status);
            $this->assertContains('content.format', array_keys($e->errors()));
            $this->assertContains('content.goal', array_keys($e->errors()));
            $this->assertContains('content.sequence', array_keys($e->errors()));
        }
        $this->assertSame('{"fact":"membership.active","op":"eq","value":true}', CanonicalCondition::parse(['value' => true, 'op' => 'eq', 'fact' => 'membership.active'])->json);
    }

    public function test_lo_que_contactpolicy_niega_por_horario_el_h9_tambien(): void
    {
        config(['logging.default' => 'null', 'commercial.contact_limits.timezone' => 'America/Bogota']);
        $silencio = new ReflectionMethod(ContactPolicy::class, 'quietHoursDenial');
        $politica = app(ContactPolicy::class);
        $inicio = RelativeTime::parseInstant('2026-11-09T05:00:00Z'); // lunes 00:00 de Bogotá
        $niegaContactPolicy = 0;
        for ($i = 0; $i < 14 * 48; $i++) {
            $t = $inicio->modify('+'.($i * 30).' minutes');
            Carbon::setTestNow(Carbon::createFromImmutable($t));
            $negada = $silencio->invoke($politica) !== null;
            if ($negada) {
                $niegaContactPolicy++;
                $this->assertFalse(ContactCalendar::window($t, null)['inside'], RelativeTime::local($t)->format('c'));
                $this->assertFalse(ContactBudget::check([], $t, 'commercial', 'whatsapp', ContactBudget::effectiveLimits())->allowed());
            }
        }
        $this->assertGreaterThan(300, $niegaContactPolicy, 'el recorrido tiene que tocar sus horas de silencio');
    }

    public function test_los_suelos_son_los_de_la_configuracion_de_hoy(): void
    {
        $this->assertSame(ContactBudget::DEFAULT_PER_WEEK, config('commercial.contact_limits.max_proactive_per_week'));
        $this->assertSame(ContactBudget::DEFAULT_MIN_HOURS, config('commercial.contact_limits.min_hours_between'));
        $this->assertSame(['perDay' => 1, 'perWeek' => 2, 'minHoursBetween' => 48], ContactBudget::effectiveLimits([
            'commercial_per_week' => config('commercial.contact_limits.max_proactive_per_week'),
            'commercial_min_hours' => config('commercial.contact_limits.min_hours_between'),
            'voice_per_week' => config('voice.outbound.frequency.per_week'),
        ]));
    }
}

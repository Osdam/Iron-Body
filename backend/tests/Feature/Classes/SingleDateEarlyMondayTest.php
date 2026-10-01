<?php

namespace Tests\Feature\Classes;

use App\Models\ClassReservation;
use App\Models\Member;
use App\Models\MyClass;
use App\Services\Classes\ClassBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Una clase de fecha única el lunes de madrugada (04:30).
 *
 * `date_time` es hora de pared sin zona y el modelo la lee como UTC; la semana
 * se calcula en hora de Bogotá. El lunes a las 04:30 «UTC» es aún domingo en
 * Bogotá, así que la clase caía en la semana anterior: ninguna fecha la
 * reconocía como suya, no se podía reservar y no salía en su lunes del plan.
 */
class SingleDateEarlyMondayTest extends TestCase
{
    use RefreshDatabase;

    private const LUNES = '2026-10-05';

    private MyClass $clase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00', 'America/Bogota')->utc());
        $this->clase = MyClass::create([
            'name' => 'Madrugada única', 'type' => 'Funcional', 'day_of_week' => 'Lunes', 'start_time' => '04:30',
            'end_time' => '05:30', 'status' => 'active', 'max_capacity' => 10, 'allow_online_booking' => true,
            'is_recurring' => false, 'date_time' => self::LUNES.' 04:30:00',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_su_lunes_es_una_ocurrencia_y_el_domingo_no(): void
    {
        $booking = app(ClassBookingService::class);

        $this->assertTrue($booking->isOccurrence($this->clase, self::LUNES));
        $this->assertFalse($booking->isOccurrence($this->clase, '2026-10-04'));
        $this->assertSame(self::LUNES, $booking->operationalDate($this->clase));
    }

    public function test_se_reserva_y_sale_en_su_lunes_del_plan_semanal(): void
    {
        $socio = $this->givePlanWithClasses(Member::create([
            'full_name' => 'Socio Madrugador', 'document_number' => '4430001', 'phone' => '+573004430001',
            'access_hash' => 'tok-4430001', 'status' => Member::STATUS_ACTIVE,
        ]));
        $auth = ['Authorization' => 'Bearer tok-4430001'];

        $this->postJson('/api/app/classes/weekly/reserve', ['items' => [['class_id' => $this->clase->id, 'session_date' => self::LUNES]]], $auth)
            ->assertOk()
            ->assertJsonPath('summary.reserved', 1);
        $this->assertSame(self::LUNES, ClassReservation::where('member_id', $socio->id)->sole()->session_date->toDateString());

        $lunes = collect($this->getJson('/api/app/classes/weekly?week_start='.self::LUNES, $auth)->assertOk()->json('days'))->firstWhere('date', self::LUNES);
        $this->assertSame([$this->clase->id], array_column($lunes['classes'], 'class_id'));

        $semanaAnterior = collect($this->getJson('/api/app/classes/weekly?week_start=2026-09-28', $auth)->assertOk()->json('days'))
            ->flatMap(fn ($d) => $d['classes']);
        $this->assertTrue($semanaAnterior->isEmpty(), 'No aparece en la semana anterior.');
    }
}

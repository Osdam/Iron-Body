<?php

namespace Tests\Feature\Classes;

use App\Models\CatalogEvent;
use App\Models\ClassEvent;
use App\Models\MemberRealtimeEvent;
use App\Models\TrainerRealtimeEvent;
use App\Services\Classes\ClassEventsFeed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * La purga de las señales de tiempo real: se va lo viejo, se queda lo reciente,
 * y el CRM que traía un cursor podado pide el estado completo en vez de perder
 * cambios.
 */
class PruneRealtimeEventsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sembrar(Carbon $cuando): void
    {
        $fila = ['type' => 'class.updated', 'changed' => ['classes'], 'version' => 1, 'created_at' => $cuando];
        MemberRealtimeEvent::create($fila + ['member_id' => 1]);
        TrainerRealtimeEvent::create($fila + ['trainer_id' => 1]);
        CatalogEvent::create($fila);
        ClassEvent::create(['type' => 'booking.created', 'class_id' => 1, 'source' => 'app', 'created_at' => $cuando]);
    }

    public function test_borra_lo_viejo_de_cada_tabla_y_deja_lo_reciente(): void
    {
        Carbon::setTestNow('2026-10-05 12:00:00');
        $this->sembrar(now()->subDays(8));   // viejo para todas
        $this->sembrar(now()->subHours(2));  // viejo solo para socio y entrenador
        $this->sembrar(now()->subMinutes(10)); // reciente para todas

        $this->artisan('realtime:prune', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(3, CatalogEvent::count(), 'El simulacro no borra.');

        $this->artisan('realtime:prune')->assertSuccessful();

        $this->assertSame(1, MemberRealtimeEvent::count());
        $this->assertSame(1, TrainerRealtimeEvent::count());
        $this->assertSame(2, CatalogEvent::count());
        $this->assertSame(2, ClassEvent::count());
    }

    public function test_el_crm_con_un_cursor_podado_pide_el_estado_completo(): void
    {
        Carbon::setTestNow('2026-10-05 12:00:00');
        $this->sembrar(now()->subDays(8));
        $viejo = (int) ClassEvent::max('id');
        $this->sembrar(now()->subDays(8));
        $this->sembrar(now()->subMinutes(10));

        $this->artisan('realtime:prune')->assertSuccessful();

        $apertura = ClassEventsFeed::open($viejo);
        $this->assertTrue($apertura['resync'], 'Lo que siguió a su cursor ya no existe.');
        $this->assertSame((int) ClassEvent::max('id'), $apertura['cursor']);
    }

    public function test_esta_programada(): void
    {
        $eventos = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'realtime:prune'));

        $this->assertCount(1, $eventos);
    }
}

<?php

namespace Tests\Feature\Valuations;

use App\Models\Notification;
use App\Models\Trainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Borrar un entrenador con valoraciones: la FK `restrict` lo impide, y antes de
 * esta guarda la respuesta era un 500 DESPUÉS de haber avisado «entrenador
 * eliminado». Ahora es un 409 claro, sin aviso y sin tocar nada.
 */
class ValuationTrainerDeletionTest extends TestCase
{
    use RefreshDatabase;
    use ValuationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-10-19 08:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_un_entrenador_con_valoraciones_no_se_borra_y_no_se_avisa(): void
    {
        $andres = $this->trainer('Andrés Pérez');
        $this->everyDay($andres);
        $laura = $this->member('Laura Gómez');
        $this->book($this->reception(), $laura, $andres, '2026-10-20T09:00')->assertStatus(201);
        $avisosAntes = Notification::query()->count();

        $this->deleteJson("/api/trainers/{$andres->id}", [], $this->h($this->superAdmin()))
            ->assertStatus(409)
            ->assertJsonPath('code', 'trainer_has_valuations');

        $this->assertNotNull(Trainer::query()->find($andres->id));
        $this->assertSame($avisosAntes, Notification::query()->count(), 'no se avisa «eliminado» de algo que sigue');
    }

    public function test_un_entrenador_sin_valoraciones_se_sigue_borrando_como_antes(): void
    {
        $sinCitas = $this->trainer('Sin Citas');

        $this->deleteJson("/api/trainers/{$sinCitas->id}", [], $this->h($this->superAdmin()))
            ->assertStatus(204);

        $this->assertNull(Trainer::query()->find($sinCitas->id));
    }
}

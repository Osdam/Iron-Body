<?php

namespace Tests\Feature\Workforce;

use App\Models\Identity;
use App\Models\StaffProfile;
use App\Models\Workday;
use App\Services\Identity\IdentityLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Workforce\Concerns\WorkforceKit;
use Tests\TestCase;

/**
 * Los cargos de personal que no son recepción: administrativo y aseo y
 * mantenimiento (casos 6 y 10 del plan). No tienen horario fijo: la jornada es
 * la que marcan, con la hora del servidor, y no se calcula puntualidad.
 *
 * Relojes y fixtures en UTC, como corre producción: el día del gimnasio lo
 * calcula el código, no la zona del reloj de la prueba.
 */
class WorkforcePositionsTest extends TestCase
{
    use RefreshDatabase;
    use WorkforceKit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reloj('2026-10-15 03:07');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reloj(string $bogota): void
    {
        Carbon::setTestNow(Carbon::parse($bogota, 'America/Bogota')->utc());
    }

    /** @param list<string> $positions */
    private function personal(string $document, string $name, array $positions): Identity
    {
        $identity = app(IdentityLinkService::class)->ensureIdentity($document);
        StaffProfile::create([
            'identity_id' => $identity->id,
            'full_name' => $name,
            'positions' => $positions,
            'status' => StaffProfile::STATUS_ACTIVE,
        ]);

        return $identity;
    }

    public function test_un_administrativo_sin_horario_fijo_registra_su_jornada_a_la_hora_que_llegue(): void
    {
        $marta = $this->personal('1075000301', 'Marta Administrativa', ['administrative']);
        $tablet = $this->tablet();

        // 03:07 en Bogotá: ninguna franja horaria lo impide ni lo marca.
        $entrada = $this->clock('in', $this->enroll($marta, '1075000301', $this->face(11), $tablet)->json('verification.token'), $tablet)
            ->assertCreated()
            ->assertJsonPath('workday.position.key', 'administrative')
            ->assertJsonPath('workday.position.label', 'Administrativo')
            ->assertJsonPath('workday.business_date', '2026-10-15')
            ->assertJsonPath('workday.clock_in_at', '2026-10-15T08:07:00+00:00');
        $this->assertArrayNotHasKey('punctuality', $entrada->json('workday'));

        $this->reloj('2026-10-15 11:52:30');
        $salida = $this->clock('out', $this->verifyFace('1075000301', $this->face(11), $tablet)->json('verification.token'), $tablet)
            ->assertOk()
            ->assertJsonPath('workday.clock_out_at', '2026-10-15T16:52:30+00:00')
            ->assertJsonPath('workday.duration_seconds', 8 * 3600 + 45 * 60 + 30);

        $admin = $this->adminWithPermissions(['workforce.view']);
        $this->getJson('/api/admin/workforce/workdays/summary?from=2026-10-15&to=2026-10-15', $admin)
            ->assertOk()
            ->assertJsonPath('data.punctuality', null)
            ->assertJsonPath('data.finished', 1)
            ->assertJsonPath('data.eligible_employees', 1);
        $detalle = $this->getJson('/api/admin/workforce/workdays/'.$salida->json('workday.id'), $admin)->assertOk()->json('data');
        $this->assertArrayNotHasKey('punctuality', $detalle);

        $this->getJson("/api/admin/workforce/staff/{$marta->uuid}", $admin)
            ->assertOk()
            ->assertJsonPath('data.capabilities.workday', true)
            ->assertJsonPath('data.workday_state.state', 'none')
            ->assertJsonPath('data.workday_state.last.position.key', 'administrative')
            ->assertJsonPath('data.workday_state.last.duration_seconds', 8 * 3600 + 45 * 60 + 30);
    }

    public function test_aseo_y_mantenimiento_registra_jornada_con_su_etiqueta_aunque_cruce_la_medianoche(): void
    {
        $luis = $this->personal('1075000302', 'Luis Mantenimiento', ['maintenance']);
        $tablet = $this->tablet();

        // 22:40 en Bogotá ya es el 16 en UTC: la jornada es del día del
        // gimnasio en que empezó.
        $this->reloj('2026-10-15 22:40');
        $this->clock('in', $this->enroll($luis, '1075000302', $this->face(12), $tablet)->json('verification.token'), $tablet)
            ->assertCreated()
            ->assertJsonPath('workday.position.key', 'maintenance')
            ->assertJsonPath('workday.position.label', 'Aseo y mantenimiento')
            ->assertJsonPath('workday.clock_in_at', '2026-10-16T03:40:00+00:00')
            ->assertJsonPath('workday.business_date', '2026-10-15');

        $this->reloj('2026-10-16 06:10');
        $this->clock('out', $this->verifyFace('1075000302', $this->face(12), $tablet)->json('verification.token'), $tablet)
            ->assertOk()
            ->assertJsonPath('workday.business_date', '2026-10-15')
            ->assertJsonPath('workday.duration_seconds', 7 * 3600 + 30 * 60);

        $this->getJson('/api/admin/workforce/workdays?from=2026-10-15&to=2026-10-15&position=maintenance', $this->adminWithPermissions(['workforce.view']))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.position.label', 'Aseo y mantenimiento')
            ->assertJsonPath('data.0.employee.full_name', 'Luis Mantenimiento');
        $this->assertSame(Workday::STATUS_CLOSED, Workday::query()->sole()->status);
    }
}

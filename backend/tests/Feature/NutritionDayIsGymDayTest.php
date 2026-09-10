<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\User;
use App\Services\NutritionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * El día de Nutrición es el del GIMNASIO, no el de Greenwich.
 *
 * POR QUÉ ESTA PRUEBA EXISTE AUNQUE NO HUBO QUE ARREGLAR NADA
 * ----------------------------------------------------------
 * En «Ver evolución» las fechas iban un día adelantadas porque el servidor
 * sacaba el día de un timestamp UTC. Al revisar Nutrición se comprobó que aquí
 * NO pasa: `NutritionService` construye su día con `America/Bogota` desde el
 * origen. Pero eso no estaba fijado por ninguna prueba, así que un `now()` sin
 * zona en cualquier refactor futuro reintroduciría exactamente el mismo fallo
 * sin que nadie se enterase hasta verlo en un teléfono.
 *
 * Las horas elegidas son las que rompen: entre las 19:00 de Bogotá y la
 * medianoche UTC, los dos calendarios discrepan.
 *
 * La app NO puede corregir esto: `date` viaja como fecha suelta —«2026-09-09»,
 * sin hora ni zona—, así que el día equivocado iría dentro.
 */
class NutritionDayIsGymDayTest extends TestCase
{
    use RefreshDatabase;

    private function member(): Member
    {
        $user = User::create([
            'name' => 'Socio', 'email' => 'dia@example.com', 'password' => 'secret',
            'document' => '9001', 'phone' => '3009001', 'status' => 'active',
            'plan' => 'PLAN TOTAL', 'membership_end_date' => now()->addDays(30)->toDateString(),
        ]);

        return Member::create([
            'user_id' => $user->id, 'full_name' => 'Socio', 'email' => 'dia@example.com',
            'document_number' => '9001', 'phone' => '3009001',
            'access_hash' => 'tok-dia', 'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function auth(Member $m): array
    {
        return ['Authorization' => 'Bearer '.$m->access_hash];
    }

    public function test_a_las_nueve_de_la_noche_el_dia_sigue_siendo_hoy(): void
    {
        // 9 sep 21:16 en Bogotá  ==  10 sep 02:16 UTC. Es el instante real en
        // que se auditó esto en producción.
        Carbon::setTestNow(Carbon::parse('2026-09-10 02:16:23', 'UTC'));

        $data = $this->getJson('/api/app/nutrition/today', $this->auth($this->member()))
            ->assertOk()->json('data');

        $this->assertSame('2026-09-09', $data['date'], 'el día del gimnasio, no el de UTC');

        Carbon::setTestNow();
    }

    public function test_la_semana_no_se_desplaza_por_la_medianoche_de_greenwich(): void
    {
        $member = $this->member();

        foreach ([
            // Justo antes de medianoche local: sigue siendo el 9.
            ['2026-09-10 04:59:00', '2026-09-09'],
            // Justo después: ya es el 10.
            ['2026-09-10 05:01:00', '2026-09-10'],
            // Media tarde: los dos calendarios coinciden y nada cambia.
            ['2026-09-09 18:00:00', '2026-09-09'],
        ] as [$utc, $esperado]) {
            Carbon::setTestNow(Carbon::parse($utc, 'UTC'));

            $data = $this->getJson('/api/app/nutrition/today', $this->auth($member))
                ->assertOk()->json('data');

            $this->assertSame($esperado, $data['date'], "para {$utc} UTC");

            // El historial semanal se mueve con el mismo día, y su «hoy»
            // señala esa fecha: si discreparan, la app pintaría marcado un día
            // distinto del que está mostrando.
            $historial = $data['weekly_history'];
            $this->assertCount(7, $historial);

            $hoy = array_values(array_filter($historial, fn ($d) => $d['is_today'] === true));
            $this->assertCount(1, $hoy, "un solo «hoy» para {$utc}");
            $this->assertSame($esperado, $hoy[0]['date']);

            // Y son fechas de calendario, sin hora ni zona: la app las muestra
            // tal cual y no puede desplazarlas.
            foreach ($historial as $d) {
                $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $d['date']);
            }
        }

        Carbon::setTestNow();
    }

    public function test_la_zona_esta_declarada_y_no_se_hereda_del_servidor(): void
    {
        // El servidor corre en UTC; el día de nutrición NO puede depender de eso.
        $this->assertSame('America/Bogota', NutritionService::TZ);
        $this->assertSame('UTC', config('app.timezone'));
    }
}

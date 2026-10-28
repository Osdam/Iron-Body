<?php

namespace Tests\Feature\Trainer;

use App\Models\Exercise;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeIronIaOpenAi;
use Tests\TestCase;

/**
 * `php artisan iron-ia:evaluate`: mide las potencias con perfiles FICTICIOS.
 *
 * Lo que se vigila: que sin `--run` no salga ni una petición, que sin clave no
 * haga nada, y que con clave genere el informe SIN escribir una sola fila en
 * la base (ni socios, ni guías, ni rutinas, ni corridas de IA).
 *
 * OpenAI es el doble de siempre: esta prueba nunca sale a la red.
 */
class EvaluateIronIaPlansCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $out;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'services.openai.enabled' => true,
            'services.openai.api_key' => 'sk-test',
            'services.openai.model' => 'gpt-4.1-mini',
            'services.openai.base_url' => 'https://api.openai.com',
            'iron_ai.plan_powers.model' => 'gpt-4.1-mini',
        ]);
        $nombres = ['Sentadilla goblet', 'Prensa', 'Peso muerto rumano', 'Zancadas', 'Hip thrust', 'Press banca', 'Press inclinado',
            'Remo con barra', 'Jalón al pecho', 'Press militar', 'Elevaciones laterales', 'Curl de bíceps', 'Tríceps en polea', 'Plancha'];
        foreach ($nombres as $i => $n) {
            Exercise::create([
                'name' => $n, 'local_name' => $n, 'muscle_group' => 'General', 'body_part' => 'General',
                'equipment' => 'mancuernas', 'difficulty' => 'intermedio', 'target' => 'General',
                'video_path' => "videos/e{$i}.mp4", 'provider' => 'local', 'external_id' => "ev-{$i}",
            ]);
        }
        $this->out = storage_path('framework/testing/iron-ia-eval-'.uniqid());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->out);
        parent::tearDown();
    }

    public function test_sin_run_solo_simula_y_no_llama_a_nadie(): void
    {
        Http::fake();

        $this->artisan('iron-ia:evaluate', ['--out' => $this->out])
            ->expectsOutputToContain('Simulación: no se llama a nadie')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertDirectoryDoesNotExist($this->out);
    }

    public function test_con_run_y_sin_clave_no_hace_nada(): void
    {
        config(['services.openai.api_key' => null]);
        Http::fake();

        $this->artisan('iron-ia:evaluate', ['--run' => true, '--out' => $this->out])
            ->expectsOutputToContain('No hay clave de OpenAI configurada')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_con_clave_genera_los_niveles_1_3_y_5_de_tres_perfiles_sin_escribir_en_la_base(): void
    {
        FakeIronIaOpenAi::install();
        $antes = $this->rowCounts();

        $this->artisan('iron-ia:evaluate', ['--run' => true, '--out' => $this->out])->assertSuccessful();

        $this->assertSame($antes, $this->rowCounts(), 'el comando escribió en la base');

        $informe = json_decode(File::get($this->out.'/report.json'), true);
        $this->assertSame(['ana', 'carlos', 'luisa'], array_keys($informe['results']));
        $this->assertSame([1, 3, 5], $informe['levels']);

        foreach ($informe['results'] as $perfil => $porNivel) {
            $this->assertSame([1, 3, 5], array_keys($porNivel));
            foreach ($porNivel as $n => $r) {
                $this->assertSame('ok', $r['status'], "{$perfil} nivel {$n}: ".($r['message'] ?? ''));
                $this->assertIsInt($r['score']);
                $this->assertGreaterThan(0, $r['tokens']['total']);
                $this->assertIsInt($r['latency_ms']);
            }
            $this->assertSame(1, $porNivel[1]['calls'], 'el nivel 1 es una llamada');
            $this->assertGreaterThan($porNivel[3]['calls'], $porNivel[5]['calls']);
            $this->assertSame(35, $porNivel[5]['meals']);
            $this->assertSame(28, $porNivel[3]['meals']);
            $this->assertGreaterThan(0, $informe['differences'][$perfil]['1->5']['tokens']);
        }

        // Los perfiles hacen lo que dicen: Luisa entra en modo seguro, Carlos
        // no tiene meta (datos parciales) y Ana sí.
        $this->assertStringStartsWith('AVISO', $informe['results']['luisa'][5]['plan']['nutrition']['recommendations']);
        $this->assertStringContainsString('Sin meta de calorías registrada', $informe['results']['carlos'][5]['plan']['nutrition']['recommendations']);
        $this->assertStringContainsString('1.650 kcal', $informe['results']['ana'][5]['plan']['nutrition']['recommendations']);
        $this->assertStringContainsString('Sin lactosa', $informe['results']['ana'][5]['plan']['nutrition']['restrictions']);

        $md = File::get($this->out.'/report.md');
        $this->assertStringContainsString('# Evaluación de las potencias de Iron IA', $md);
        $this->assertStringContainsString('| 5 | ok |', $md);
        $this->assertStringContainsString('1->5: puntuación', $md);
    }

    /** @return array<string,int> */
    private function rowCounts(): array
    {
        $tablas = ['members', 'users', 'nutrition_guides', 'routines', 'routine_exercises', 'nutrition_ai_runs', 'nutrition_goals', 'iron_ai_user_profiles', 'trainer_audit_logs'];

        return array_combine($tablas, array_map(fn ($t) => DB::table($t)->count(), $tablas));
    }
}

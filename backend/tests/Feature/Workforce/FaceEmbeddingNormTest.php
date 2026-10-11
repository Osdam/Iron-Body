<?php

namespace Tests\Feature\Workforce;

use App\Models\Trainer;
use App\Models\TrainerFaceReference;
use App\Models\TrainerRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un embedding que no es un rostro no puede abrir la sesión de nadie.
 *
 * MobileFaceNet entrega vectores de norma 1 y el umbral de coincidencia es una
 * distancia euclídea de 1.0. Contra una referencia unitaria, un vector casi
 * nulo queda SIEMPRE a distancia ≈ 1: basta con que apunte medio grado hacia
 * el lado de la referencia para quedar por debajo del umbral. Dos intentos
 * —un vector diminuto y su opuesto— abrían la sesión de cualquier entrenador
 * con rostro registrado, sin conocer su cara. El endpoint es público.
 */
class FaceEmbeddingNormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'trainer.flags.trainer_auth_enabled' => true,
            'trainer.face.login_enabled' => true,
        ]);
    }

    public function test_un_vector_casi_nulo_no_abre_la_sesion_de_un_entrenador(): void
    {
        $trainer = Trainer::create(['full_name' => 'Coach Rostro', 'document' => '7001', 'status' => 'active']);
        $trainer->syncRoles([TrainerRole::FLOOR]);

        mt_srand(42);
        $referencia = $this->unitario(array_map(fn () => mt_rand(-1000, 1000) / 1000, range(1, 192)));
        // Plantilla VALIDADA (con consentimiento): la única defensa en juego
        // tiene que ser la norma del vector.
        $ref = new TrainerFaceReference(['trainer_id' => $trainer->id, 'status' => TrainerFaceReference::STATUS_ACTIVE, 'enrolled_at' => now(), 'consent_version' => '2026-10-v1']);
        $ref->storeVector($referencia);
        $ref->save();

        // El atacante no conoce la referencia: un vector diminuto cualquiera y
        // su opuesto. Uno de los dos apunta hacia el lado de la referencia.
        $dir = $this->unitario(array_map(fn () => mt_rand(-1000, 1000) / 1000, range(1, 192)));
        $abiertas = 0;
        foreach ([1e-3, -1e-3] as $escala) {
            $res = $this->postJson('/api/trainer/auth/face/login', [
                'trainer_id' => $trainer->id,
                'embedding' => array_map(fn ($v) => $v * $escala, $dir),
            ]);
            if ($res->json('ok') === true) {
                $abiertas++;
            }
        }

        $this->assertSame(0, $abiertas, 'Un vector que no es un rostro abrió la sesión del entrenador.');
    }

    /** @param list<float> $v */
    private function unitario(array $v): array
    {
        $n = sqrt(array_sum(array_map(fn ($x) => $x * $x, $v)));

        return array_map(fn ($x) => $x / $n, $v);
    }
}

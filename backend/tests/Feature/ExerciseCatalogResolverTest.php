<?php

namespace Tests\Feature;

use App\Models\Exercise;
use App\Models\ExerciseAlias;
use App\Services\Exercises\ExerciseCatalogResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Resolver central del catálogo: match seguro (id/external/alias/nombre exacto/
 * local_name) y auditoría (tokens, sin autoaplicar ambiguos).
 */
class ExerciseCatalogResolverTest extends TestCase
{
    use RefreshDatabase;

    private const VID = 'https://api.ironbodyneiva.cloud/storage/exercises/videos';

    private function ex(string $name, string $video, array $extra = []): Exercise
    {
        return Exercise::create(array_merge([
            'external_id' => 'local-' . uniqid(),
            'name'        => $name,
            'provider'    => 'local',
            'source'      => 'manual',
            'video_path'  => $video,
            'media_type'  => 'video',
        ], $extra));
    }

    private function resolver(): ExerciseCatalogResolver
    {
        // Instancia fresca para evitar memo del singleton entre fases del test.
        return new ExerciseCatalogResolver();
    }

    public function test_normalize_quita_tildes_case_y_espacios(): void
    {
        $r = $this->resolver();
        $this->assertSame('press en maquina hammer', $r->normalize('  Press  en MÁQUINA Hammer! '));
        $this->assertSame('biceps', $r->normalize('Bíceps'));
    }

    public function test_match_por_nombre_exacto_normalizado(): void
    {
        $this->ex('Press de pecho en máquina', self::VID . '/pecho.mp4');
        $m = $this->resolver()->resolveSafe(null, 'PRESS DE PECHO EN MAQUINA');
        $this->assertNotNull($m);
        $this->assertStringContainsString('pecho.mp4', $m->video_path);
    }

    public function test_match_por_local_name(): void
    {
        $this->ex('Bench press', self::VID . '/bench.mp4', ['local_name' => 'Press de banca plano']);
        $m = $this->resolver()->resolveSafe(null, 'press de banca plano');
        $this->assertNotNull($m);
        $this->assertStringContainsString('bench.mp4', $m->video_path);
    }

    public function test_match_por_exercise_id(): void
    {
        $ex = $this->ex('Remo con barra', self::VID . '/remo.mp4');
        $m = $this->resolver()->resolveSafe($ex->id, 'nombre que no existe');
        $this->assertSame($ex->id, $m?->id);
    }

    public function test_alias_verificado_resuelve_video(): void
    {
        $ex = $this->ex('Press de pecho en máquina', self::VID . '/pecho.mp4');
        ExerciseAlias::create([
            'alias_name'       => 'Press plano en máquina Hammer',
            'normalized_alias' => $this->resolver()->normalize('Press plano en máquina Hammer'),
            'exercise_id'      => $ex->id,
            'source'           => 'seed',
            'confidence'       => 1.0,
            'is_verified'      => true,
        ]);

        $m = $this->resolver()->resolveSafe(null, 'Press plano en máquina Hammer');
        $this->assertSame($ex->id, $m?->id);
        $this->assertSame('matched', $this->resolver()->audit(null, 'Press plano en máquina Hammer')['status']);
    }

    public function test_alias_no_verificado_no_se_aplica(): void
    {
        $ex = $this->ex('Press de pecho en máquina', self::VID . '/pecho.mp4');
        ExerciseAlias::create([
            'alias_name'       => 'Press plano en máquina Hammer',
            'normalized_alias' => $this->resolver()->normalize('Press plano en máquina Hammer'),
            'exercise_id'      => $ex->id,
            'source'           => 'audit',
            'confidence'       => 0.7,
            'is_verified'      => false, // NO verificado
        ]);

        $this->assertNull($this->resolver()->resolveSafe(null, 'Press plano en máquina Hammer'));
    }

    public function test_fuzzy_no_se_autoaplica_es_needs_review(): void
    {
        // Catálogo con un candidato parecido pero no exacto.
        $this->ex('Press de pecho en máquina', self::VID . '/pecho.mp4');

        $r = $this->resolver();
        // resolveSafe NO debe inventar match.
        $this->assertNull($r->resolveSafe(null, 'Press plano en máquina Hammer'));
        // audit lo reporta como sugerencia, nunca matched.
        $audit = $r->audit(null, 'Press plano en máquina Hammer');
        $this->assertContains($audit['status'], ['needs_review', 'ambiguous']);
        $this->assertNull($audit['exercise']);
        $this->assertNotEmpty($audit['candidates']);
    }

    public function test_fuzzy_ambiguo_no_resuelve(): void
    {
        // Dos candidatos igual de parecidos → ambiguo.
        $this->ex('Curl de bíceps con barra', self::VID . '/a.mp4');
        $this->ex('Curl de bíceps con mancuerna', self::VID . '/b.mp4');

        $r = $this->resolver();
        $this->assertNull($r->resolveSafe(null, 'Curl de bíceps'));
        $audit = $r->audit(null, 'Curl de bíceps');
        $this->assertNotSame('matched', $audit['status']);
    }

    public function test_sin_candidato(): void
    {
        $this->ex('Press de pecho en máquina', self::VID . '/pecho.mp4');
        $audit = $this->resolver()->audit(null, 'Saltar la cuerda en interestelar');
        $this->assertSame('no_candidate', $audit['status']);
    }
}

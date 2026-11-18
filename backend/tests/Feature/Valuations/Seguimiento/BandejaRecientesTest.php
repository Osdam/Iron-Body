<?php

namespace Tests\Feature\Valuations\Seguimiento;

use App\Models\Member;
use App\Models\Trainer;
use App\Models\ValuationAppointment;
use App\Services\Valuations\ValuationAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Bandeja de valoraciones recibidas (CONTRATO_BANDEJA_SEGUIMIENTO.md): sin
 * buscar, las más recientes primero, de cinco en cinco, con «Nueva/Revisada»
 * persistente, búsqueda sin tildes, alcance y sin medidas en la respuesta.
 */
class BandejaRecientesTest extends TestCase
{
    use RefreshDatabase;
    use SeguimientoFixtures;

    private const URL = '/api/admin/valuations/follow-ups/recent';

    private Trainer $andres;

    private Trainer $sofia;

    /** @var list<Member> */
    private array $socios = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock('2026-11-18 16:00');
        $this->andres = $this->trainer('Andrés Pérez');
        $this->sofia = $this->trainer('Sofía Ríos');
        // Siete valoraciones en días distintos: la del día 7 es la más reciente.
        $nombres = ['María González', 'Pedro Ruiz', 'Ana Núñez', 'Luis Mora', 'Eva Díaz', 'Juan Pardo', 'Rosa Vela'];
        foreach ($nombres as $i => $nombre) {
            $m = $this->member($nombre, '2027-01-31', ['document_number' => '1075'.str_pad((string) $i, 6, '0', STR_PAD_LEFT)]);
            $entrenador = $i % 2 === 0 ? $this->andres : $this->sofia;
            $this->assign($m, $entrenador);
            $this->valoracion($m, $entrenador, sprintf('2026-11-%02d 10:00', 10 + $i));
            $this->socios[] = $m;
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sin_buscar_cinco_por_pagina_de_la_mas_reciente_a_la_mas_antigua(): void
    {
        $h = $this->reception();
        $p1 = $this->getJson(self::URL, $h)->assertOk();
        $this->assertSame(['Rosa Vela', 'Juan Pardo', 'Eva Díaz', 'Luis Mora', 'Ana Núñez'], array_column(array_column($p1->json('data'), 'member'), 'name'));
        $p1->assertJsonPath('meta.per_page', 5)->assertJsonPath('meta.total', 7)
            ->assertJsonPath('meta.last_page', 2)->assertJsonPath('meta.unreviewed', 7);

        // El sexto registro pasa a la página 2.
        $p2 = $this->getJson(self::URL.'?page=2', $h)->assertOk();
        $this->assertSame(['Pedro Ruiz', 'María González'], array_column(array_column($p2->json('data'), 'member'), 'name'));

        // Una nueva valoración aparece la primera y empuja la quinta a la página 2.
        $this->clock('2026-11-18 17:00');
        $this->valoracion($this->socios[0], $this->andres, '2026-11-18 16:30');
        $nueva = $this->getJson(self::URL, $h)->assertOk();
        $nueva->assertJsonPath('data.0.member.name', 'María González')->assertJsonPath('data.0.is_new', true)
            ->assertJsonPath('meta.total', 8);
    }

    public function test_la_fila_es_compacta_sin_medidas_y_dice_si_hubo_cita(): void
    {
        $socio = $this->socios[6];
        $cita = $this->cita($socio, $this->andres, '2026-11-16 10:00', ValuationAppointment::STATUS_COMPLETED,
            \App\Models\ProfessionalAssessment::query()->where('member_id', $socio->id)->first(), 'trainer_assessment', '2026-11-16 10:00');

        $fila = $this->getJson(self::URL, $this->reception())->assertOk()->json('data.0');
        $this->assertSame(['assessment_uuid', 'member', 'trainer', 'completed_at', 'appointment', 'is_new', 'reviewed_at', 'reviewed_by_name', 'follow_up'], array_keys($fila));
        $this->assertSame(['uuid' => $cita->uuid, 'status' => 'completed'], $fila['appointment']);
        $this->assertSame('2026-11-16T10:00:00-05:00', $fila['completed_at']);
        $this->assertNull($this->getJson(self::URL, $this->reception())->json('data.1.appointment'), 'sin cita también sale');
        $json = json_encode($fila);
        foreach (['weight', 'height', 'body_fat', 'injur', 'measure'] as $sensible) {
            $this->assertStringNotContainsString($sensible, $json);
        }
    }

    public function test_revisada_persiste_es_idempotente_y_baja_el_contador(): void
    {
        $h = $this->reception();
        $uuid = $this->getJson(self::URL, $h)->json('data.0.assessment_uuid');

        $r = $this->postJson('/api/admin/valuations/follow-ups/'.$uuid.'/reviewed', [], $h)->assertOk();
        $r->assertJsonPath('data.is_new', false);
        $primera = $r->json('data.reviewed_at');
        $this->assertNotNull($r->json('data.reviewed_by_name'));

        $this->clock('2026-11-18 18:00');
        $this->postJson('/api/admin/valuations/follow-ups/'.$uuid.'/reviewed', [], $this->reception())->assertOk()
            ->assertJsonPath('data.reviewed_at', $primera);
        $this->getJson(self::URL, $h)->assertJsonPath('meta.unreviewed', 6)->assertJsonPath('data.0.is_new', false);
        // Marcar revisada no toca la valoración.
        $this->assertSame(1, DB::table('professional_assessments')->where('uuid', $uuid)->value('version') ?? 1);
    }

    public function test_buscar_sin_tildes_ni_mayusculas_y_paginado(): void
    {
        $h = $this->reception();
        $this->getJson(self::URL.'?q=nunez', $h)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.member.name', 'Ana Núñez');
        $this->getJson(self::URL.'?q=MARI', $h)->assertOk()->assertJsonPath('data.0.member.name', 'María González');
        $this->getJson(self::URL.'?q=1075', $h)->assertOk()->assertJsonPath('meta.total', 7)->assertJsonCount(5, 'data');
        $this->getJson(self::URL.'?q=x', $h)->assertStatus(422);
    }

    public function test_alcance_y_permisos(): void
    {
        $andres = $this->h($this->staffWith([ValuationAccess::VIEW], $this->andres));
        $nombres = array_column(array_column($this->getJson(self::URL.'?per_page=50', $andres)->assertOk()->json('data'), 'member'), 'name');
        $this->assertSame(['Rosa Vela', 'Eva Díaz', 'Ana Núñez', 'María González'], $nombres, 'solo sus socios asignados');

        // Un entrenador vinculado no marca «Revisada» ni las suyas: es el acuse de administración.
        $suya = \App\Models\ProfessionalAssessment::query()->where('member_id', $this->socios[0]->id)->value('uuid');
        $this->postJson('/api/admin/valuations/follow-ups/'.$suya.'/reviewed', [], $andres)->assertForbidden();
        $this->assertNull(DB::table('professional_assessments')->where('uuid', $suya)->value('admin_reviewed_at'));
        // Ni con permiso de editar: sigue siendo una cuenta con alcance (otra entrenadora, su socio).
        $deSofia = \App\Models\ProfessionalAssessment::query()->where('member_id', $this->socios[1]->id)->value('uuid');
        $conUpdate = $this->h($this->staffWith([ValuationAccess::VIEW, ValuationAccess::UPDATE], $this->sofia));
        $this->postJson('/api/admin/valuations/follow-ups/'.$deSofia.'/reviewed', [], $conUpdate)->assertForbidden();

        $this->getJson(self::URL, $this->sharedTokenHeaders())->assertForbidden();
        $this->getJson(self::URL, $this->h($this->staffWith([])))->assertForbidden();
    }

    public function test_numero_de_consultas_fijo_por_pagina(): void
    {
        $h = $this->reception();
        $contar = function (string $url) use ($h): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($url, $h)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };
        $contar(self::URL); // calienta permisos
        $this->assertSame($contar(self::URL.'?per_page=1'), $contar(self::URL.'?per_page=5'), 'sin N+1');
    }
}

<?php

namespace Tests\Feature\UltronStudio;

use App\Models\Admin;
use App\Models\UltronStudio\StudioAuditEvent;
use App\Models\UltronStudio\StudioDraft;
use App\Services\UltronStudio\StudioAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * El libro del Studio (contrato §A6 y §A8): qué se audita y qué no, quién ve
 * qué (los eventos de borrador, solo su dueño o `audit.view`), y que la API no
 * devuelve IP ni user agent.
 */
class UltronStudioAuditTest extends TestCase
{
    use RefreshDatabase;
    use StudioFixtures;

    private Admin $ana;

    private Admin $bea;

    private Admin $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ana = $this->person(Admin::ROLE_ADMINISTRADOR, 'Ana Pérez');
        $this->bea = $this->person(Admin::ROLE_ADMINISTRADOR, 'Bea Ruiz');
        // Super Admin tiene `audit.view`; el Administrador, no.
        $this->root = $this->person(Admin::ROLE_SUPER_ADMIN, 'Root');
    }

    /** @return list<string> */
    private function events(Admin $as, string $query = ''): array
    {
        return array_column($this->studio('GET', 'audit'.($query !== '' ? '?'.$query : ''), $as)->assertOk()->json('data.data'), 'event');
    }

    public function test_los_eventos_de_borrador_solo_los_ve_su_dueno_o_quien_tiene_audit_view(): void
    {
        $this->saveDraft($this->ana)->assertOk();
        $uuid = (string) $this->createVersion($this->ana)->assertCreated()->json('data.uuid');

        $this->assertSame(['version.created', 'draft.saved'], $this->events($this->ana));
        $this->assertSame(['version.created'], $this->events($this->bea));
        $this->assertSame(['version.created', 'draft.saved'], $this->events($this->root));

        // Filtrar por el borrador ajeno no lo destapa.
        $borrador = StudioDraft::query()->sole()->uuid;
        $this->assertSame([], $this->events($this->bea, "subjectType=draft&subjectUuid={$borrador}"));
        $this->assertSame(['draft.saved'], $this->events($this->root, "subjectType=draft&subjectUuid={$borrador}"));
        $this->assertSame(['version.created'], $this->events($this->bea, "subjectUuid={$uuid}"));
    }

    public function test_ni_se_guardan_ni_salen_la_ip_y_el_user_agent(): void
    {
        // Decisión de la fase F (hallazgo S2): un libro de solo inserción sin
        // política de retención no guarda datos personales del personal.
        $this->withHeaders(['User-Agent' => 'Navegador de prueba/1.0'])->saveDraft($this->ana)->assertOk();

        $fila = StudioAuditEvent::query()->sole();
        $this->assertNull($fila->ip_address);
        $this->assertNull($fila->user_agent);

        $r = $this->studio('GET', 'audit', $this->ana)->assertOk();
        $evento = $r->json('data.data.0');
        $this->assertSame(
            ['id', 'event', 'summary', 'actor', 'subjectType', 'subjectUuid', 'fromStatus', 'toStatus', 'contentHash', 'diff', 'createdAt'],
            array_keys($evento),
        );
        $this->assertSame(['type' => 'admin', 'name' => 'Ana Pérez', 'role' => 'Administrador'], $evento['actor']);
        $this->assertStringNotContainsString('Navegador de prueba', $r->getContent());
    }

    public function test_un_user_agent_con_bytes_invalidos_no_rompe_la_operacion(): void
    {
        $this->withHeaders(['User-Agent' => "Roto \xC3\x28 ".str_repeat('x', 400)])->saveDraft($this->ana)->assertOk();

        // No se persiste (fase F), y la cabecera rota tampoco rompe el guardado.
        $this->assertNull(StudioAuditEvent::query()->sole()->user_agent);
    }

    public function test_lo_que_no_cambia_nada_no_se_audita(): void
    {
        $this->saveDraft($this->ana)->assertOk();
        $uuid = (string) $this->createVersion($this->ana)->assertCreated()->json('data.uuid');
        $antes = StudioAuditEvent::query()->count();

        // Lecturas, simulación, 4xx de formato y conflictos.
        $this->studio('GET', 'status', $this->ana)->assertOk();
        $this->studio('GET', 'catalog', $this->ana)->assertOk();
        $this->studio('GET', 'draft', $this->ana)->assertOk();
        $this->studio('GET', 'versions', $this->ana)->assertOk();
        $this->studio('GET', "versions/{$uuid}", $this->ana)->assertOk();
        $this->studio('POST', "versions/{$uuid}/validate", $this->ana)->assertOk();
        $this->studio('GET', 'rules', $this->ana)->assertOk();
        $this->studio('GET', 'audit', $this->ana)->assertOk();
        $this->saveDraft($this->ana, null, 0)->assertStatus(409);
        $this->studio('PUT', 'draft', $this->ana, ['baseRevision' => 1, 'content' => 'no'])->assertStatus(422);
        $this->studio('POST', "versions/{$uuid}/reject", $this->root, ['reason' => 'x'])->assertStatus(422);

        $this->assertSame($antes, StudioAuditEvent::query()->count());
    }

    public function test_filtros_validados_y_pagina(): void
    {
        $this->saveDraft($this->ana)->assertOk();
        foreach (range(1, 3) as $i) {
            $this->saveDraft($this->ana, $this->contentWith(['persona' => ['maxChars' => 200 + 20 * $i]]), $i)->assertOk();
        }

        $pagina = $this->studio('GET', 'audit?event=draft.saved&per_page=2', $this->ana)->assertOk();
        $this->assertSame(4, $pagina->json('data.total'));
        $this->assertSame(2, $pagina->json('data.per_page'));
        $ids = array_column($pagina->json('data.data'), 'id');
        $this->assertSame($ids, array_values(array_reverse(array_values(collect($ids)->sort()->all()))), 'Del más reciente al más antiguo.');
        $this->assertSame(100, $this->studio('GET', 'audit?per_page=1000', $this->ana)->assertOk()->json('data.per_page'));

        $this->studio('GET', 'audit?subjectUuid=no-es-un-uuid', $this->ana)->assertStatus(422)->assertJsonValidationErrors(['subjectUuid']);
        $this->studio('GET', 'audit?subjectType=socio', $this->ana)->assertStatus(422)->assertJsonValidationErrors(['subjectType']);
        $this->studio('GET', 'audit?event=DROP%20TABLE', $this->ana)->assertStatus(422)->assertJsonValidationErrors(['event']);
        $this->assertSame([], $this->events($this->ana, 'subjectUuid='.Str::uuid()));
    }

    public function test_los_valores_del_diff_se_recortan_a_120_caracteres(): void
    {
        $larga = str_repeat('Regla muy larga. ', 25);
        $this->studio('POST', 'rules', $this->ana, [
            'description' => mb_substr($larga, 0, 500), 'type' => 'style', 'priority' => 'normal',
            'layers' => ['persona'], 'clientRequestId' => (string) Str::uuid(),
        ])->assertCreated();

        $diff = StudioAuditEvent::query()->where('event', 'rule.created')->sole()->diff;
        $this->assertSame(StudioAudit::MAX_VALUE_LENGTH, mb_strlen($diff['description']));
        $this->assertStringEndsWith('…', $diff['description']);
    }

    public function test_el_resumen_y_el_actor_quedan_como_instantanea(): void
    {
        $this->saveDraft($this->ana)->assertOk();
        $this->ana->forceFill(['name' => 'Ana Renombrada'])->save();

        $this->assertSame('Ana Pérez', StudioAuditEvent::query()->sole()->actor_name);
        $this->assertSame('Ana Pérez', $this->studio('GET', 'audit', $this->root)->assertOk()->json('data.data.0.actor.name'));
    }
}

<?php

namespace Tests\Feature\Moderation;

use App\Models\Admin;
use App\Models\ContentReport;

/**
 * Contrato de los filtros del listado de moderación.
 *
 * Regresión del 422 en producción: el CRM enviaba `open_only=true` (Angular
 * serializa un booleano con `String(value)`), y la regla `boolean` de Laravel
 * —que acepta `1/0/"1"/"0"` pero NO `"true"`/`"false"`— devolvía
 * `validation.boolean`. Como el filtro por defecto es `open_only: true`, la
 * bandeja fallaba en la PRIMERA carga y el CRM mostraba
 * «No pudimos cargar los casos».
 *
 * Estas pruebas fijan el contrato por ambos lados: el backend acepta las
 * representaciones habituales de un booleano en una URL, y sigue rechazando
 * con 422 los valores que de verdad no lo son.
 */
class ModerationFilterContractTest extends ModerationTestCase
{
    private function makeReport(): ContentReport
    {
        $author = $this->makeMember('Autor');
        $reporter = $this->makeMember('Reportante');
        $story = $this->makeStory($author);

        $this->postJson("/api/app/stories/{$story->id}/report",
            ['reason_code' => 'harassment_or_bullying'], $this->asMember($reporter))
            ->assertCreated();

        return ContentReport::firstOrFail();
    }

    protected function superAdminHeaders(): array
    {
        return $this->asAdmin($this->makeAdmin(Admin::ROLE_SUPER_ADMIN));
    }

    // ── open_only: todas las representaciones ─────────────────────────────

    public function test_open_only_acepta_uno(): void
    {
        $this->makeReport();

        $this->getJson('/api/admin/moderation/reports?open_only=1', $this->superAdminHeaders())
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_open_only_acepta_cero(): void
    {
        $this->makeReport();

        $this->getJson('/api/admin/moderation/reports?open_only=0', $this->superAdminHeaders())
            ->assertOk();
    }

    public function test_open_only_acepta_la_cadena_true(): void
    {
        // ESTA es la petición exacta que devolvía 422 en producción.
        $this->makeReport();

        $this->getJson('/api/admin/moderation/reports?open_only=true&page=1', $this->superAdminHeaders())
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_open_only_acepta_la_cadena_false(): void
    {
        $this->makeReport();

        $this->getJson('/api/admin/moderation/reports?open_only=false', $this->superAdminHeaders())
            ->assertOk();
    }

    public function test_open_only_omitido_devuelve_todo(): void
    {
        $this->makeReport();

        $this->getJson('/api/admin/moderation/reports', $this->superAdminHeaders())
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_open_only_vacio_equivale_a_omitido(): void
    {
        $this->makeReport();

        $this->getJson('/api/admin/moderation/reports?open_only=', $this->superAdminHeaders())
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_open_only_invalido_devuelve_422_claro(): void
    {
        // No se adivina: un valor desconocido es un error del cliente y se
        // reporta como tal, no se interpreta como false en silencio.
        $this->getJson('/api/admin/moderation/reports?open_only=quizas', $this->superAdminHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors('open_only');
    }

    public function test_open_only_filtra_de_verdad(): void
    {
        // Que acepte el valor no basta: debe seguir filtrando.
        $report = $this->makeReport();
        $report->forceFill(['status' => 'closed'])->save();

        $this->getJson('/api/admin/moderation/reports?open_only=true', $this->superAdminHeaders())
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson('/api/admin/moderation/reports?open_only=false', $this->superAdminHeaders())
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ── Los otros booleanos ───────────────────────────────────────────────

    public function test_with_evidence_y_with_appeal_aceptan_ambas_formas(): void
    {
        $this->makeReport();
        $h = $this->superAdminHeaders();

        foreach (['with_evidence', 'with_appeal'] as $filter) {
            foreach (['1', '0', 'true', 'false'] as $value) {
                $this->getJson("/api/admin/moderation/reports?{$filter}={$value}", $h)
                    ->assertOk("Falló {$filter}={$value}");
            }
        }
    }

    public function test_with_evidence_invalido_devuelve_422(): void
    {
        $this->getJson('/api/admin/moderation/reports?with_evidence=2', $this->superAdminHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors('with_evidence');
    }

    // ── Paginación y filtros no booleanos ─────────────────────────────────

    public function test_page_uno_es_valido(): void
    {
        $this->makeReport();

        $this->getJson('/api/admin/moderation/reports?page=1', $this->superAdminHeaders())
            ->assertOk()
            ->assertJsonPath('meta.current_page', 1);
    }

    public function test_page_invalida_devuelve_422(): void
    {
        $this->getJson('/api/admin/moderation/reports?page=0', $this->superAdminHeaders())
            ->assertStatus(422)
            ->assertJsonValidationErrors('page');
    }

    public function test_filtros_no_booleanos_vacios_no_rompen(): void
    {
        $this->makeReport();
        $h = $this->superAdminHeaders();

        $this->getJson('/api/admin/moderation/reports?status=&severity=&reason_code=', $h)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_combinacion_completa_de_filtros(): void
    {
        // La petición más compleja que puede construir el CRM.
        $this->makeReport();

        $query = http_build_query([
            'open_only' => 'true',
            'with_evidence' => '1',
            'with_appeal' => '0',
            'status' => 'submitted',
            'severity' => 'high',
            'reason_code' => 'harassment_or_bullying',
            'sort' => 'submitted_at',
            'direction' => 'desc',
            'page' => 1,
            'per_page' => 25,
        ]);

        $this->getJson("/api/admin/moderation/reports?{$query}", $this->superAdminHeaders())
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_valor_invalido_en_lista_blanca_sigue_devolviendo_422(): void
    {
        // La corrección de booleanos no relajó el resto del contrato.
        $h = $this->superAdminHeaders();

        $this->getJson('/api/admin/moderation/reports?status=inventado', $h)
            ->assertStatus(422)->assertJsonValidationErrors('status');
        $this->getJson('/api/admin/moderation/reports?sort=reporter_member_id', $h)
            ->assertStatus(422)->assertJsonValidationErrors('sort');
    }

    // ── La bandeja de apelaciones tenía el mismo defecto ──────────────────

    public function test_apelaciones_aceptan_open_only_en_ambas_formas(): void
    {
        $h = $this->superAdminHeaders();

        foreach (['1', '0', 'true', 'false', ''] as $value) {
            $this->getJson("/api/admin/moderation/appeals?open_only={$value}", $h)
                ->assertOk("Falló appeals?open_only={$value}");
        }

        $this->getJson('/api/admin/moderation/appeals?open_only=quizas', $h)
            ->assertStatus(422)
            ->assertJsonValidationErrors('open_only');
    }

    // ── La respuesta no cambió ────────────────────────────────────────────

    public function test_la_forma_de_la_respuesta_se_mantiene_intacta(): void
    {
        $this->makeReport();

        $res = $this->getJson('/api/admin/moderation/reports?open_only=true', $this->superAdminHeaders())
            ->assertOk()
            ->assertJsonStructure([
                'ok',
                'data' => [['id', 'status', 'reason_code', 'severity', 'unique_reporters', 'lock_version']],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
                'permissions',
            ]);

        // Y el reportante sigue sin aparecer.
        $this->assertStringNotContainsString('reporter_member_id', json_encode($res->json()));
    }
}

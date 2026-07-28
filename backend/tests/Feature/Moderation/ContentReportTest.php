<?php

namespace Tests\Feature\Moderation;

use App\Models\ContentReport;
use App\Models\ReportContentSnapshot;
use App\Models\Story;
use App\Support\Moderation\ReportStatus;

/**
 * Reportes de contenido desde la app: autoridad del servidor, dedup, límites,
 * evidencia y anonimato del reportante.
 */
class ContentReportTest extends ModerationTestCase
{
    public function test_miembro_autenticado_reporta_story_ajena(): void
    {
        $author = $this->makeMember('Autor');
        $reporter = $this->makeMember('Reportante');
        $story = $this->makeStory($author);

        $res = $this->postJson("/api/app/stories/{$story->id}/report", [
            'reason_code' => 'harassment_or_bullying',
            'reason_detail' => 'Me está insultando en el video.',
        ], $this->asMember($reporter));

        $res->assertCreated()->assertJsonPath('ok', true);

        $this->assertDatabaseHas('content_reports', [
            'reporter_member_id' => $reporter->id,
            'reported_member_id' => $author->id,
            'content_id' => $story->id,
            'reason_code' => 'harassment_or_bullying',
            'status' => ReportStatus::SUBMITTED,
        ]);

        // El mensaje NO afirma que el contenido fue eliminado.
        $this->assertStringNotContainsString('elimin', strtolower($res->json('data.message')));
    }

    public function test_no_puede_reportar_su_propia_story(): void
    {
        $author = $this->makeMember('Autor');
        $story = $this->makeStory($author);

        $this->postJson("/api/app/stories/{$story->id}/report", [
            'reason_code' => 'spam_or_scam',
        ], $this->asMember($author))
            ->assertStatus(422)
            ->assertJsonPath('code', 'cannot_report_own_content');

        $this->assertDatabaseCount('content_reports', 0);
    }

    public function test_reporter_id_enviado_por_el_cliente_se_ignora(): void
    {
        $author = $this->makeMember('Autor');
        $reporter = $this->makeMember('Reportante');
        $impostor = $this->makeMember('Suplantado');
        $story = $this->makeStory($author);

        $this->postJson("/api/app/stories/{$story->id}/report", [
            'reason_code' => 'spam_or_scam',
            // Intento de falsear el reportante.
            'reporter_member_id' => $impostor->id,
            'reporter_id' => $impostor->id,
        ], $this->asMember($reporter))->assertCreated();

        // Manda el bearer, no el body.
        $this->assertDatabaseHas('content_reports', [
            'reporter_member_id' => $reporter->id,
        ]);
        $this->assertDatabaseMissing('content_reports', [
            'reporter_member_id' => $impostor->id,
        ]);
    }

    public function test_reported_member_id_enviado_por_el_cliente_se_ignora(): void
    {
        $author = $this->makeMember('Autor real');
        $victim = $this->makeMember('Víctima del intento');
        $reporter = $this->makeMember('Reportante');
        $story = $this->makeStory($author);

        $this->postJson("/api/app/stories/{$story->id}/report", [
            'reason_code' => 'spam_or_scam',
            // Intento de dirigir el reporte contra otra persona.
            'reported_member_id' => $victim->id,
            'author_id' => $victim->id,
        ], $this->asMember($reporter))->assertCreated();

        // El autor se resuelve de la Story REAL.
        $this->assertDatabaseHas('content_reports', [
            'reported_member_id' => $author->id,
        ]);
        $this->assertDatabaseMissing('content_reports', [
            'reported_member_id' => $victim->id,
        ]);
    }

    public function test_story_inexistente_devuelve_404(): void
    {
        $reporter = $this->makeMember('Reportante');

        $this->postJson('/api/app/stories/999999/report', [
            'reason_code' => 'spam_or_scam',
        ], $this->asMember($reporter))
            ->assertStatus(404)
            ->assertJsonPath('code', 'content_not_found');
    }

    public function test_motivo_invalido_devuelve_422(): void
    {
        $author = $this->makeMember('Autor');
        $reporter = $this->makeMember('Reportante');
        $story = $this->makeStory($author);

        $this->postJson("/api/app/stories/{$story->id}/report", [
            'reason_code' => 'no_me_gusta_su_cara',
        ], $this->asMember($reporter))
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason_code');
    }

    public function test_detalle_demasiado_largo_devuelve_422(): void
    {
        $author = $this->makeMember('Autor');
        $reporter = $this->makeMember('Reportante');
        $story = $this->makeStory($author);

        $this->postJson("/api/app/stories/{$story->id}/report", [
            'reason_code' => 'spam_or_scam',
            'reason_detail' => str_repeat('a', 5000),
        ], $this->asMember($reporter))
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason_detail');
    }

    public function test_reporte_duplicado_activo_es_idempotente(): void
    {
        $author = $this->makeMember('Autor');
        $reporter = $this->makeMember('Reportante');
        $story = $this->makeStory($author);

        $this->postJson("/api/app/stories/{$story->id}/report", [
            'reason_code' => 'spam_or_scam',
        ], $this->asMember($reporter))->assertCreated();

        $second = $this->postJson("/api/app/stories/{$story->id}/report", [
            'reason_code' => 'hate_speech',
        ], $this->asMember($reporter))->assertOk();

        $this->assertFalse($second->json('data.created'));
        $this->assertDatabaseCount('content_reports', 1);
    }

    public function test_rate_limit_bloquea_campana_de_reportes(): void
    {
        config(['ugc.report_rate_limit_per_hour' => 3]);

        $reporter = $this->makeMember('Reportante');
        $headers = $this->asMember($reporter);

        for ($i = 0; $i < 3; $i++) {
            $story = $this->makeStory($this->makeMember("Autor {$i}"));
            $this->postJson("/api/app/stories/{$story->id}/report", [
                'reason_code' => 'spam_or_scam',
            ], $headers)->assertCreated();
        }

        $extra = $this->makeStory($this->makeMember('Autor extra'));
        $this->postJson("/api/app/stories/{$extra->id}/report", [
            'reason_code' => 'spam_or_scam',
        ], $headers)
            ->assertStatus(429)
            ->assertJsonPath('code', 'rate_limited');
    }

    public function test_se_captura_snapshot_de_evidencia(): void
    {
        $author = $this->makeMember('Autor');
        $reporter = $this->makeMember('Reportante');
        $story = $this->makeStory($author, ['caption' => 'Un texto <script>malo</script>']);

        $this->postJson("/api/app/stories/{$story->id}/report", [
            'reason_code' => 'nudity_or_sexual_content',
        ], $this->asMember($reporter))->assertCreated();

        $snapshot = ReportContentSnapshot::first();

        $this->assertNotNull($snapshot);
        $this->assertSame($story->id, (int) $snapshot->original_story_id);
        $this->assertSame($story->file_path, $snapshot->media_storage_path);
        // El caption se archiva SANEADO (el CRM lo renderiza).
        $this->assertStringNotContainsString('<script>', (string) $snapshot->caption_snapshot);
        // Para Firebase NO se archiva ninguna URL pública permanente.
        $this->assertNull($snapshot->media_url_snapshot);
    }

    public function test_story_expirada_conserva_la_evidencia(): void
    {
        $author = $this->makeMember('Autor');
        $reporter = $this->makeMember('Reportante');
        $story = $this->makeStory($author);

        $this->postJson("/api/app/stories/{$story->id}/report", [
            'reason_code' => 'violence_or_graphic_content',
        ], $this->asMember($reporter))->assertCreated();

        // La story expira y su autor la borra.
        $story->forceFill(['expires_at' => now()->subHour()])->save();
        $this->deleteJson("/api/app/stories/{$story->id}", [], $this->asMember($author))
            ->assertOk();

        // La fila sobrevive (soft delete) y la evidencia sigue ahí.
        $this->assertSoftDeleted('stories', ['id' => $story->id]);
        $this->assertDatabaseHas('report_content_snapshots', [
            'original_story_id' => $story->id,
            'media_purged_at' => null,
        ]);
    }

    public function test_se_puede_reportar_contenido_que_el_autor_acaba_de_borrar(): void
    {
        $author = $this->makeMember('Autor');
        $reporter = $this->makeMember('Reportante');
        $story = $this->makeStory($author);

        $story->delete(); // soft delete

        $this->postJson("/api/app/stories/{$story->id}/report", [
            'reason_code' => 'child_safety',
        ], $this->asMember($reporter))->assertCreated();

        $this->assertDatabaseCount('content_reports', 1);
    }

    public function test_conteo_de_reportantes_unicos_no_cuenta_dos_veces_a_la_misma_persona(): void
    {
        $author = $this->makeMember('Autor');
        $story = $this->makeStory($author);

        $a = $this->makeMember('A');
        $b = $this->makeMember('B');

        $this->postJson("/api/app/stories/{$story->id}/report",
            ['reason_code' => 'spam_or_scam'], $this->asMember($a))->assertCreated();
        // Segundo intento del mismo: no suma.
        $this->postJson("/api/app/stories/{$story->id}/report",
            ['reason_code' => 'spam_or_scam'], $this->asMember($a))->assertOk();
        $this->postJson("/api/app/stories/{$story->id}/report",
            ['reason_code' => 'spam_or_scam'], $this->asMember($b))->assertCreated();

        $this->assertSame(2, (int) Story::withTrashed()->find($story->id)->reports_count);
    }

    public function test_cuarentena_automatica_oculta_pero_no_sanciona(): void
    {
        config([
            'ugc.auto_quarantine_enabled' => true,
            'ugc.auto_quarantine_unique_reporters' => 2,
        ]);

        $author = $this->makeMember('Autor');
        $story = $this->makeStory($author);

        foreach (['R1', 'R2'] as $name) {
            $reporter = $this->makeMember($name);
            $this->postJson("/api/app/stories/{$story->id}/report",
                ['reason_code' => 'spam_or_scam'], $this->asMember($reporter))->assertCreated();
        }

        $story->refresh();
        $this->assertSame(Story::MODERATION_QUARANTINED, $story->moderation_state);

        // Nada de sanciones automáticas al autor.
        $this->assertDatabaseCount('member_suspensions', 0);
        $this->assertDatabaseCount('moderation_actions', 0);
    }

    public function test_la_respuesta_nunca_expone_al_reportante(): void
    {
        $author = $this->makeMember('Autor');
        $reporter = $this->makeMember('Reportante Secreto');
        $story = $this->makeStory($author);

        $res = $this->postJson("/api/app/stories/{$story->id}/report", [
            'reason_code' => 'spam_or_scam',
        ], $this->asMember($reporter))->assertCreated();

        $body = json_encode($res->json());
        $this->assertStringNotContainsString('Reportante Secreto', $body);
        $this->assertStringNotContainsString('reporter_member_id', $body);
        // El identificador que se devuelve es un UUID público, no el id interno
        // del caso ni nada derivado del reportante.
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            (string) $res->json('data.report_id'),
        );
    }

    public function test_reportes_desactivados_responden_503_sin_fingir_exito(): void
    {
        config(['ugc.reports_enabled' => false]);

        $author = $this->makeMember('Autor');
        $reporter = $this->makeMember('Reportante');
        $story = $this->makeStory($author);

        $this->postJson("/api/app/stories/{$story->id}/report", [
            'reason_code' => 'spam_or_scam',
        ], $this->asMember($reporter))
            ->assertStatus(503)
            ->assertJsonPath('code', 'reports_disabled');

        $this->assertDatabaseCount('content_reports', 0);
    }

    public function test_reporte_sin_autenticacion_es_401(): void
    {
        $story = $this->makeStory($this->makeMember('Autor'));

        $this->postJson("/api/app/stories/{$story->id}/report", [
            'reason_code' => 'spam_or_scam',
        ])->assertStatus(401);
    }

    public function test_catalogo_de_motivos_es_publico_para_la_app(): void
    {
        $member = $this->makeMember('Curioso');

        $res = $this->getJson('/api/app/moderation/report-reasons', $this->asMember($member))
            ->assertOk();

        $codes = collect($res->json('data.reasons'))->pluck('code');

        $this->assertContains('child_safety', $codes);
        $this->assertContains('nudity_or_sexual_content', $codes);
        $this->assertContains('other', $codes);
    }

    public function test_estado_severidad_y_prioridad_los_fija_el_servidor(): void
    {
        $author = $this->makeMember('Autor');
        $reporter = $this->makeMember('Reportante');
        $story = $this->makeStory($author);

        $this->postJson("/api/app/stories/{$story->id}/report", [
            'reason_code' => 'child_safety',
            // Intentos de manipular la cola de moderación.
            'severity' => 'low',
            'priority' => 1,
            'status' => ReportStatus::CLOSED,
        ], $this->asMember($reporter))->assertCreated();

        $report = ContentReport::first();

        $this->assertSame('critical', $report->severity);
        $this->assertSame(100, (int) $report->priority);
        $this->assertSame(ReportStatus::SUBMITTED, $report->status);
    }
}

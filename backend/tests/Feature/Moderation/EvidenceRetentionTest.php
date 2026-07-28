<?php

namespace Tests\Feature\Moderation;

use App\Models\ContentReport;
use App\Models\ModerationAuditLog;
use App\Models\ReportContentSnapshot;
use App\Models\Story;
use App\Services\FirebaseStorageService;
use App\Services\Moderation\EvidenceService;
use App\Support\Moderation\ReportStatus;
use Illuminate\Support\Facades\Storage;

/**
 * Ciclo de vida de la evidencia: nunca se destruye mientras el caso viva, se
 * purga al vencer la retención, y el job de limpieza es idempotente.
 *
 * Firebase Storage se sustituye por un doble: la suite NO hace red real.
 */
class EvidenceRetentionTest extends ModerationTestCase
{
    /** Doble de Firebase Storage que cuenta borrados sin salir a la red. */
    private function fakeFirebase(): object
    {
        $fake = new class extends FirebaseStorageService
        {
            public array $deleted = [];

            public function deleteObject(string $pathOrGsUrl): bool
            {
                $this->deleted[] = $pathOrGsUrl;

                return true;
            }

            public function signedUrl(string $pathOrGsUrl, int $minutes = 10): ?string
            {
                return 'https://signed.example/'.md5($pathOrGsUrl).'?exp='.$minutes;
            }
        };

        $this->app->instance(FirebaseStorageService::class, $fake);

        return $fake;
    }

    private function reportedStory(): array
    {
        $author = $this->makeMember('Autor');
        $reporter = $this->makeMember('Reportante');
        $story = $this->makeStory($author);

        $this->postJson("/api/app/stories/{$story->id}/report",
            ['reason_code' => 'violence_or_graphic_content'], $this->asMember($reporter))
            ->assertCreated();

        return [$author, $story->fresh(), ContentReport::firstOrFail()];
    }

    public function test_el_autor_no_puede_destruir_evidencia_de_un_caso_abierto(): void
    {
        $firebase = $this->fakeFirebase();
        [$author, $story] = $this->reportedStory();

        $this->deleteJson("/api/app/stories/{$story->id}", [], $this->asMember($author))
            ->assertOk();

        // La story desaparece del feed (soft delete)…
        $this->assertSoftDeleted('stories', ['id' => $story->id]);
        // …pero el binario NO se borró: hay un caso abierto.
        $this->assertSame([], $firebase->deleted);
        $this->assertDatabaseHas('report_content_snapshots', [
            'original_story_id' => $story->id,
            'media_purged_at' => null,
        ]);
    }

    public function test_sin_reportes_el_borrado_si_libera_el_binario(): void
    {
        $firebase = $this->fakeFirebase();
        $author = $this->makeMember('Autor');
        $story = $this->makeStory($author);

        $this->deleteJson("/api/app/stories/{$story->id}", [], $this->asMember($author))
            ->assertOk();

        // Comportamiento previo intacto para contenido sin moderación de por medio.
        $this->assertSame([$story->file_path], $firebase->deleted);
    }

    public function test_la_evidencia_sigue_revisable_tras_expirar_y_borrarse(): void
    {
        $this->fakeFirebase();
        [$author, $story, $report] = $this->reportedStory();

        $story->forceFill(['expires_at' => now()->subHours(2)])->save();
        $this->deleteJson("/api/app/stories/{$story->id}", [], $this->asMember($author));

        $res = $this->getJson("/api/admin/moderation/reports/{$report->public_id}/evidence",
            $this->asAdmin($this->makeAdmin()))->assertOk();

        // URL FIRMADA y temporal, no una URL pública permanente.
        $this->assertStringStartsWith('https://signed.example/', $res->json('data.url'));
        $this->assertSame(
            (int) config('ugc.evidence_signed_url_minutes'),
            $res->json('data.expires_in_minutes'),
        );
    }

    public function test_el_acceso_a_evidencia_queda_auditado(): void
    {
        $this->fakeFirebase();
        [, , $report] = $this->reportedStory();
        $admin = $this->makeAdmin();

        $this->getJson("/api/admin/moderation/reports/{$report->public_id}/evidence",
            $this->asAdmin($admin))->assertOk();

        $this->assertDatabaseHas('moderation_audit_logs', [
            'actor_type' => 'admin',
            'actor_id' => $admin->id,
            'action' => 'evidence_viewed',
        ]);
    }

    public function test_la_url_firmada_no_se_persiste_en_ningun_sitio(): void
    {
        $this->fakeFirebase();
        [, , $report] = $this->reportedStory();

        $url = $this->getJson("/api/admin/moderation/reports/{$report->public_id}/evidence",
            $this->asAdmin($this->makeAdmin()))->json('data.url');

        $snapshot = ReportContentSnapshot::firstOrFail();
        $this->assertNotSame($url, $snapshot->media_url_snapshot);
        $this->assertNull($snapshot->media_url_snapshot);

        foreach (ModerationAuditLog::all() as $log) {
            $this->assertStringNotContainsString(
                'signed.example',
                json_encode([$log->before_data, $log->after_data]),
            );
        }
    }

    public function test_el_job_de_limpieza_respeta_los_casos_abiertos(): void
    {
        $firebase = $this->fakeFirebase();
        [, , $report] = $this->reportedStory();

        // La retención "vence" pero el caso sigue abierto.
        $report->snapshot->forceFill(['purge_after' => now()->subDay()])->save();

        $this->artisan('moderation:purge-evidence')->assertSuccessful();

        $this->assertSame([], $firebase->deleted);
        $this->assertNull($report->snapshot->fresh()->media_purged_at);
    }

    public function test_el_job_purga_cuando_el_caso_esta_cerrado_y_vencio_la_retencion(): void
    {
        $firebase = $this->fakeFirebase();
        [, $story, $report] = $this->reportedStory();

        $report->forceFill([
            'status' => ReportStatus::CLOSED,
            'resolved_at' => now(),
        ])->save();
        $report->snapshot->forceFill(['purge_after' => now()->subDay()])->save();

        $this->artisan('moderation:purge-evidence')->assertSuccessful();

        $this->assertSame([$story->file_path], $firebase->deleted);

        $snapshot = $report->snapshot->fresh();
        $this->assertNotNull($snapshot->media_purged_at);
        // La referencia al objeto se limpia: ya no apunta a nada.
        $this->assertNull($snapshot->media_storage_path);
    }

    public function test_el_job_de_limpieza_es_idempotente(): void
    {
        $firebase = $this->fakeFirebase();
        [, , $report] = $this->reportedStory();

        $report->forceFill(['status' => ReportStatus::CLOSED, 'resolved_at' => now()])->save();
        $report->snapshot->forceFill(['purge_after' => now()->subDay()])->save();

        $this->artisan('moderation:purge-evidence')->assertSuccessful();
        $this->artisan('moderation:purge-evidence')->assertSuccessful();
        $this->artisan('moderation:purge-evidence')->assertSuccessful();

        // Una sola llamada de borrado, pase lo que pase.
        $this->assertCount(1, $firebase->deleted);
        $this->assertDatabaseCount('report_content_snapshots', 1);
    }

    public function test_dry_run_no_borra_nada(): void
    {
        $firebase = $this->fakeFirebase();
        [, , $report] = $this->reportedStory();

        $report->forceFill(['status' => ReportStatus::CLOSED, 'resolved_at' => now()])->save();
        $report->snapshot->forceFill(['purge_after' => now()->subDay()])->save();

        $this->artisan('moderation:purge-evidence --dry-run')->assertSuccessful();

        $this->assertSame([], $firebase->deleted);
        $this->assertNull($report->snapshot->fresh()->media_purged_at);
    }

    public function test_cerrar_el_caso_programa_la_retencion(): void
    {
        $this->fakeFirebase();
        [, , $report] = $this->reportedStory();
        $headers = $this->asAdmin($this->makeAdmin());

        $this->postJson("/api/admin/moderation/reports/{$report->public_id}/decision",
            ['action_type' => 'dismiss', 'public_reason' => 'Sin infracción'], $headers)
            ->assertCreated();
        $this->postJson("/api/admin/moderation/reports/{$report->public_id}/transition",
            ['status' => ReportStatus::CLOSED], $headers)->assertOk();

        $snapshot = $report->snapshot->fresh();
        $this->assertNotNull($snapshot->purge_after);
        $this->assertTrue($snapshot->purge_after->isFuture());
    }

    public function test_evidencia_legacy_en_disco_publico_se_maneja_igual(): void
    {
        Storage::disk('public')->put('stories/legacy.jpg', 'contenido');

        $author = $this->makeMember('Autor');
        $reporter = $this->makeMember('Reportante');
        $story = $this->makeStory($author, [
            'disk' => 'public',
            'file_path' => 'stories/legacy.jpg',
            'download_url' => null,
        ]);

        $this->postJson("/api/app/stories/{$story->id}/report",
            ['reason_code' => 'spam_or_scam'], $this->asMember($reporter))->assertCreated();

        $report = ContentReport::firstOrFail();
        $report->forceFill(['status' => ReportStatus::CLOSED, 'resolved_at' => now()])->save();
        $report->snapshot->forceFill(['purge_after' => now()->subDay()])->save();

        $this->artisan('moderation:purge-evidence')->assertSuccessful();

        Storage::disk('public')->assertMissing('stories/legacy.jpg');
        $this->assertNotNull($report->snapshot->fresh()->media_purged_at);
    }

    public function test_can_purge_media_es_la_unica_puerta(): void
    {
        $this->fakeFirebase();
        [, $story, $report] = $this->reportedStory();

        /** @var EvidenceService $evidence */
        $evidence = app(EvidenceService::class);

        // Caso abierto → no se puede purgar.
        $this->assertFalse($evidence->canPurgeMedia((int) $story->id));

        // Caso cerrado pero retención vigente → tampoco.
        $report->forceFill(['status' => ReportStatus::CLOSED])->save();
        $this->assertFalse($evidence->canPurgeMedia((int) $story->id));

        // Retención vencida → sí.
        $report->snapshot->forceFill(['purge_after' => now()->subDay()])->save();
        $this->assertTrue($evidence->canPurgeMedia((int) $story->id));
    }

    public function test_la_story_borrada_sigue_consultable_para_el_moderador(): void
    {
        $this->fakeFirebase();
        [$author, $story, $report] = $this->reportedStory();

        $this->deleteJson("/api/app/stories/{$story->id}", [], $this->asMember($author));

        $detail = $this->getJson("/api/admin/moderation/reports/{$report->public_id}",
            $this->asAdmin($this->makeAdmin()))->assertOk();

        $this->assertTrue($detail->json('data.content.is_deleted'));
        $this->assertTrue($detail->json('data.evidence.media_available'));
        $this->assertSame($story->id, $detail->json('data.content.story_id'));
    }

    public function test_story_en_cuarentena_no_se_borra_fisicamente(): void
    {
        $firebase = $this->fakeFirebase();
        [, $story, $report] = $this->reportedStory();

        $this->postJson("/api/admin/moderation/reports/{$report->public_id}/decision",
            ['action_type' => 'remove_content', 'public_reason' => 'Infringe lineamientos'],
            $this->asAdmin($this->makeAdmin()))->assertCreated();

        // "Eliminar" desde el CRM marca el estado; no destruye la evidencia.
        $this->assertSame(
            Story::MODERATION_REMOVED,
            Story::withTrashed()->find($story->id)->moderation_state,
        );
        $this->assertSame([], $firebase->deleted);
    }
}

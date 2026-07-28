<?php

namespace Tests\Feature\Moderation;

use App\Models\Member;
use App\Models\MemberSuspension;
use App\Support\Moderation\ModerationScope;

/**
 * Sanciones sociales: alcance, caducidad, aislamiento respecto al gimnasio y
 * validación en el SERVIDOR (no solo en la UI).
 */
class SuspensionTest extends ModerationTestCase
{
    private function suspend(Member $member, string $scope, ?int $minutes = 60): MemberSuspension
    {
        return MemberSuspension::create([
            'member_id' => $member->id,
            'scope' => $scope,
            'status' => MemberSuspension::STATUS_ACTIVE,
            'starts_at' => now()->subMinute(),
            'ends_at' => $minutes ? now()->addMinutes($minutes) : null,
            'public_reason' => 'Incumplimiento de los lineamientos.',
        ]);
    }

    public function test_suspension_de_publicacion_bloquea_crear_story(): void
    {
        $member = $this->makeMember('Sancionado');
        $this->acceptGuidelines($member);
        $this->suspend($member, ModerationScope::STORY_POSTING);

        $this->postJson('/api/app/stories/firebase', [
            'type' => 'image',
            'firebase_path' => 'stories/1/x.jpg',
            'download_url' => 'https://firebasestorage.example/o/x?token=1',
        ], $this->asMember($member))
            ->assertStatus(403)
            ->assertJsonPath('code', 'posting_restricted');

        $this->assertDatabaseCount('stories', 0);
    }

    public function test_suspension_social_no_bloquea_rutinas_ni_membresia(): void
    {
        $member = $this->makeMember('Sancionado');
        $this->suspend($member, ModerationScope::SOCIAL_FEATURES);

        // La membresía y el estado de la cuenta siguen intactos.
        $member->refresh();
        $this->assertSame(Member::STATUS_ACTIVE, $member->status);
        $this->assertNotSame(Member::STATUS_SUSPENDED, $member->status);

        // El resto de la app responde con normalidad (app-state no es social).
        $this->getJson('/api/member/app-state', $this->asMember($member))
            ->assertOk()
            ->assertJsonPath('membership.is_active', true);

        // Y no se creó ninguna cancelación de membresía ni pago.
        $this->assertDatabaseCount('member_suspensions', 1);
        $this->assertSame(
            $member->user->membership_end_date,
            $member->user->fresh()->membership_end_date,
        );
    }

    public function test_suspension_social_bloquea_el_feed_de_stories(): void
    {
        $member = $this->makeMember('Sancionado');
        $this->makeStory($this->makeMember('Otro'));

        $this->suspend($member, ModerationScope::SOCIAL_FEATURES);

        $this->getJson('/api/app/stories', $this->asMember($member))
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_suspension_de_interaccion_bloquea_reaccionar_y_reportar(): void
    {
        $member = $this->makeMember('Sancionado');
        $story = $this->makeStory($this->makeMember('Autor'));

        $this->suspend($member, ModerationScope::STORY_INTERACTION);

        $this->postJson("/api/app/stories/{$story->id}/react",
            ['type' => 'heart'], $this->asMember($member))
            ->assertStatus(403)
            ->assertJsonPath('code', 'interaction_restricted');

        $this->postJson("/api/app/stories/{$story->id}/report",
            ['reason_code' => 'spam_or_scam'], $this->asMember($member))
            ->assertStatus(403)
            ->assertJsonPath('code', 'interaction_restricted');
    }

    public function test_full_app_access_implica_todo_lo_demas(): void
    {
        $member = $this->makeMember('Sancionado');
        $this->acceptGuidelines($member);
        $this->suspend($member, ModerationScope::FULL_APP_ACCESS);

        $status = $this->getJson('/api/app/moderation/status', $this->asMember($member))
            ->assertOk();

        $this->assertTrue($status->json('data.app_access_blocked'));
        $this->assertFalse($status->json('data.can_post_stories'));
        $this->assertFalse($status->json('data.can_interact'));
        $this->assertFalse($status->json('data.can_use_social'));
    }

    public function test_suspension_temporal_expira_sola(): void
    {
        $member = $this->makeMember('Sancionado');
        $this->acceptGuidelines($member);

        $suspension = $this->suspend($member, ModerationScope::STORY_POSTING, 30);

        $this->getJson('/api/app/moderation/status', $this->asMember($member))
            ->assertJsonPath('data.can_post_stories', false);

        // Pasa la fecha de fin. No corre ningún job: el estado se calcula.
        $suspension->forceFill(['ends_at' => now()->subMinute()])->save();

        $this->getJson('/api/app/moderation/status', $this->asMember($member))
            ->assertJsonPath('data.can_post_stories', true);
    }

    public function test_suspension_revocada_deja_de_aplicar(): void
    {
        $member = $this->makeMember('Sancionado');
        $suspension = $this->suspend($member, ModerationScope::STORY_POSTING);

        $suspension->forceFill([
            'status' => MemberSuspension::STATUS_REVOKED,
            'revoked_at' => now(),
        ])->save();

        $this->getJson('/api/app/moderation/status', $this->asMember($member))
            ->assertJsonPath('data.can_post_stories', true);
    }

    public function test_el_estado_no_expone_notas_internas_ni_reportante(): void
    {
        $member = $this->makeMember('Sancionado');
        MemberSuspension::create([
            'member_id' => $member->id,
            'scope' => ModerationScope::STORY_POSTING,
            'status' => MemberSuspension::STATUS_ACTIVE,
            'starts_at' => now()->subMinute(),
            'ends_at' => now()->addHour(),
            'public_reason' => 'Contenido contrario a los lineamientos.',
            'internal_reason' => 'SECRETO INTERNO: reportado por Juan Pérez',
        ]);

        $res = $this->getJson('/api/app/moderation/status', $this->asMember($member))
            ->assertOk();

        $body = json_encode($res->json());
        $this->assertStringNotContainsString('SECRETO INTERNO', $body);
        $this->assertStringNotContainsString('Juan Pérez', $body);
        $this->assertStringNotContainsString('internal_reason', $body);
        $this->assertStringContainsString('Contenido contrario', $body);
    }

    // ── Lineamientos de comunidad ─────────────────────────────────────────

    public function test_publicar_exige_aceptar_los_lineamientos(): void
    {
        $member = $this->makeMember('Nuevo');

        $this->postJson('/api/app/stories/firebase', [
            'type' => 'image',
            'firebase_path' => 'stories/1/x.jpg',
            'download_url' => 'https://firebasestorage.example/o/x?token=1',
        ], $this->asMember($member))
            ->assertStatus(403)
            ->assertJsonPath('code', 'guidelines_acceptance_required');

        $this->postJson('/api/app/moderation/guidelines/accept',
            ['platform' => 'android'], $this->asMember($member))->assertOk();

        $this->postJson('/api/app/stories/firebase', [
            'type' => 'image',
            'firebase_path' => 'stories/1/x.jpg',
            'download_url' => 'https://firebasestorage.example/o/x?token=1',
        ], $this->asMember($member))->assertCreated();
    }

    public function test_no_aceptar_lineamientos_no_bloquea_modulos_no_sociales(): void
    {
        $member = $this->makeMember('Solo rutinas');

        // Sin aceptar nada, el resto de la app funciona.
        $this->getJson('/api/member/app-state', $this->asMember($member))
            ->assertOk()
            ->assertJsonPath('membership.is_active', true);
    }

    public function test_no_se_puede_aceptar_una_version_arbitraria(): void
    {
        $member = $this->makeMember('Listo');

        $this->postJson('/api/app/moderation/guidelines/accept',
            ['version' => '99.0'], $this->asMember($member))
            ->assertStatus(409)
            ->assertJsonPath('code', 'version_mismatch');

        $this->assertDatabaseCount('member_ugc_consents', 0);
    }

    public function test_aceptacion_de_lineamientos_es_idempotente(): void
    {
        $member = $this->makeMember('Listo');

        $this->postJson('/api/app/moderation/guidelines/accept', [], $this->asMember($member))
            ->assertOk();
        $this->postJson('/api/app/moderation/guidelines/accept', [], $this->asMember($member))
            ->assertOk();

        $this->assertDatabaseCount('member_ugc_consents', 1);
    }

    // ── Edad ──────────────────────────────────────────────────────────────

    public function test_verificacion_de_edad_apagada_no_bloquea_a_nadie(): void
    {
        // Estado REAL hoy: `birth_date` es nullable y la verificación viene
        // desactivada. Nadie queda bloqueado por falta de dato.
        $member = $this->makeMember('Sin fecha', ['birth_date' => null]);
        $this->acceptGuidelines($member);

        $this->postJson('/api/app/stories/firebase', [
            'type' => 'image',
            'firebase_path' => 'stories/1/x.jpg',
            'download_url' => 'https://firebasestorage.example/o/x?token=1',
        ], $this->asMember($member))->assertCreated();
    }

    public function test_verificacion_de_edad_activa_bloquea_a_menores_de_la_edad_de_publicacion(): void
    {
        config(['ugc.posting_age_enforced' => true, 'ugc.posting_min_age' => 13]);

        $member = $this->makeMember('Menor', [
            'birth_date' => now()->subYears(11)->toDateString(),
        ]);
        $this->acceptGuidelines($member);

        $this->postJson('/api/app/stories/firebase', [
            'type' => 'image',
            'firebase_path' => 'stories/1/x.jpg',
            'download_url' => 'https://firebasestorage.example/o/x?token=1',
        ], $this->asMember($member))
            ->assertStatus(403)
            ->assertJsonPath('code', 'posting_age_restricted');
    }

    public function test_sin_fecha_de_nacimiento_no_se_asume_una_edad(): void
    {
        // Con la verificación ACTIVA y política 'allow', no se inventa una edad
        // ni se marca a nadie como adulto: simplemente no se bloquea.
        config([
            'ugc.posting_age_enforced' => true,
            'ugc.posting_age_unknown_policy' => 'allow',
        ]);

        $member = $this->makeMember('Sin fecha', ['birth_date' => null]);
        $this->acceptGuidelines($member);

        $this->postJson('/api/app/stories/firebase', [
            'type' => 'image',
            'firebase_path' => 'stories/1/x.jpg',
            'download_url' => 'https://firebasestorage.example/o/x?token=1',
        ], $this->asMember($member))->assertCreated();

        // Con política 'block' sí se bloquea — el sistema está listo para
        // activarse cuando el dato sea fiable.
        config(['ugc.posting_age_unknown_policy' => 'block']);

        $this->postJson('/api/app/stories/firebase', [
            'type' => 'image',
            'firebase_path' => 'stories/1/y.jpg',
            'download_url' => 'https://firebasestorage.example/o/y?token=1',
        ], $this->asMember($member))->assertStatus(403);
    }
}

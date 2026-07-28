<?php

namespace Tests\Feature\Moderation;

use App\Models\Member;
use App\Models\Story;
use App\Models\StoryReaction;

/**
 * Bloqueos entre miembros: simetría, idempotencia, aplicación en el SERVIDOR y
 * aislamiento respecto a membresías y pagos.
 */
class UserBlockTest extends ModerationTestCase
{
    public function test_miembro_bloquea_a_otro(): void
    {
        $a = $this->makeMember('A');
        $b = $this->makeMember('B');

        $this->postJson("/api/app/members/{$b->id}/block", [], $this->asMember($a))
            ->assertCreated()
            ->assertJsonPath('data.created', true);

        $this->assertDatabaseHas('user_blocks', [
            'blocker_member_id' => $a->id,
            'blocked_member_id' => $b->id,
        ]);
    }

    public function test_no_puede_bloquearse_a_si_mismo(): void
    {
        $a = $this->makeMember('A');

        $this->postJson("/api/app/members/{$a->id}/block", [], $this->asMember($a))
            ->assertStatus(422)
            ->assertJsonPath('code', 'self_block_not_allowed');

        $this->assertDatabaseCount('user_blocks', 0);
    }

    public function test_bloqueo_duplicado_es_idempotente(): void
    {
        $a = $this->makeMember('A');
        $b = $this->makeMember('B');

        $this->postJson("/api/app/members/{$b->id}/block", [], $this->asMember($a))
            ->assertCreated();
        $this->postJson("/api/app/members/{$b->id}/block", [], $this->asMember($a))
            ->assertOk()
            ->assertJsonPath('data.created', false);

        $this->assertDatabaseCount('user_blocks', 1);
    }

    public function test_bloquear_a_alguien_inexistente_devuelve_404(): void
    {
        $a = $this->makeMember('A');

        $this->postJson('/api/app/members/999999/block', [], $this->asMember($a))
            ->assertStatus(404)
            ->assertJsonPath('code', 'member_not_found');
    }

    public function test_desbloqueo_funciona_y_es_idempotente(): void
    {
        $a = $this->makeMember('A');
        $b = $this->makeMember('B');

        $this->postJson("/api/app/members/{$b->id}/block", [], $this->asMember($a));

        $this->deleteJson("/api/app/members/{$b->id}/block", [], $this->asMember($a))
            ->assertOk()
            ->assertJsonPath('data.removed', true);

        // Repetir no es un error.
        $this->deleteJson("/api/app/members/{$b->id}/block", [], $this->asMember($a))
            ->assertOk()
            ->assertJsonPath('data.removed', false);

        $this->assertDatabaseCount('user_blocks', 0);
    }

    public function test_el_feed_excluye_bloqueados_en_ambos_sentidos(): void
    {
        $a = $this->makeMember('A');
        $b = $this->makeMember('B');
        $c = $this->makeMember('C');

        $this->makeStory($b, ['caption' => 'story de B']);
        $this->makeStory($c, ['caption' => 'story de C']);

        // A bloquea a B.
        $this->postJson("/api/app/members/{$b->id}/block", [], $this->asMember($a))
            ->assertCreated();

        // A ya no ve a B.
        $feedA = $this->getJson('/api/app/stories', $this->asMember($a))->assertOk();
        $authorsA = collect($feedA->json('data'))->pluck('author_id');
        $this->assertNotContains($b->id, $authorsA);
        $this->assertContains($c->id, $authorsA);

        // Simetría: B tampoco ve a A (aunque el bloqueo lo hizo A).
        $this->makeStory($a, ['caption' => 'story de A']);
        $feedB = $this->getJson('/api/app/stories', $this->asMember($b))->assertOk();
        $authorsB = collect($feedB->json('data'))->pluck('author_id');
        $this->assertNotContains($a->id, $authorsB);
    }

    public function test_el_bloqueo_se_aplica_en_el_servidor_no_solo_en_la_ui(): void
    {
        $a = $this->makeMember('A');
        $b = $this->makeMember('B');
        $story = $this->makeStory($b);

        $this->postJson("/api/app/members/{$b->id}/block", [], $this->asMember($a));

        // Aunque A conozca el id, la API no le entrega la story.
        $this->postJson("/api/app/stories/{$story->id}/view", [], $this->asMember($a))
            ->assertStatus(404);

        $this->postJson("/api/app/stories/{$story->id}/react",
            ['type' => 'heart'], $this->asMember($a))->assertStatus(404);

        $this->assertDatabaseCount('story_reactions', 0);
    }

    public function test_contenido_en_cuarentena_no_llega_al_feed(): void
    {
        $author = $this->makeMember('Autor');
        $viewer = $this->makeMember('Viewer');

        $visible = $this->makeStory($author, ['caption' => 'ok']);
        $hidden = $this->makeStory($author, ['caption' => 'oculta']);
        $hidden->forceFill(['moderation_state' => Story::MODERATION_QUARANTINED])->save();

        $feed = $this->getJson('/api/app/stories', $this->asMember($viewer))->assertOk();

        $ids = collect($feed->json('data'))->flatMap(fn ($g) => collect($g['stories'])->pluck('id'));
        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($hidden->id, $ids);
    }

    public function test_el_feed_agrupa_correctamente_tras_filtrar(): void
    {
        $viewer = $this->makeMember('Viewer');
        $blocked = $this->makeMember('Bloqueado');

        // El bloqueado publica varias; otros dos autores publican una cada uno.
        $this->makeStory($blocked);
        $this->makeStory($blocked);
        $visibleAuthors = [];
        foreach (['X', 'Y'] as $name) {
            $author = $this->makeMember($name);
            $visibleAuthors[] = $author->id;
            $this->makeStory($author);
        }

        $this->postJson("/api/app/members/{$blocked->id}/block", [], $this->asMember($viewer));

        $feed = $this->getJson('/api/app/stories', $this->asMember($viewer))->assertOk();

        // El filtro se aplica ANTES de agrupar: no quedan grupos vacíos ni
        // huecos, y el total refleja solo a los autores visibles.
        $groups = collect($feed->json('data'));
        $this->assertCount(2, $groups);
        $this->assertEqualsCanonicalizing($visibleAuthors, $groups->pluck('author_id')->all());
        $groups->each(fn ($g) => $this->assertNotEmpty($g['stories']));
    }

    public function test_lista_de_bloqueados_solo_muestra_a_quien_yo_bloquee(): void
    {
        $a = $this->makeMember('A');
        $b = $this->makeMember('B');
        $c = $this->makeMember('C');

        // A bloquea a B. C bloquea a A.
        $this->postJson("/api/app/members/{$b->id}/block", [], $this->asMember($a));
        $this->postJson("/api/app/members/{$a->id}/block", [], $this->asMember($c));

        $res = $this->getJson('/api/app/moderation/blocked-members', $this->asMember($a))
            ->assertOk();

        $ids = collect($res->json('data.items'))->pluck('member_id');

        $this->assertContains($b->id, $ids);
        // Nunca se revela que C me bloqueó: sería una vía de acoso.
        $this->assertNotContains($c->id, $ids);
    }

    public function test_bloquear_no_toca_membresia_ni_pagos(): void
    {
        $a = $this->makeMember('A');
        $b = $this->makeMember('B');

        $planBefore = $b->user->plan;
        $endBefore = $b->user->membership_end_date;
        $statusBefore = $b->status;

        $this->postJson("/api/app/members/{$b->id}/block", [], $this->asMember($a))
            ->assertCreated();

        $b->refresh();
        $b->user->refresh();

        $this->assertSame($planBefore, $b->user->plan);
        $this->assertEquals($endBefore, $b->user->membership_end_date);
        $this->assertSame($statusBefore, $b->status);
        $this->assertSame(Member::STATUS_ACTIVE, $b->status);
    }

    public function test_bloqueo_desactivado_responde_503(): void
    {
        config(['ugc.blocking_enabled' => false]);

        $a = $this->makeMember('A');
        $b = $this->makeMember('B');

        $this->postJson("/api/app/members/{$b->id}/block", [], $this->asMember($a))
            ->assertStatus(503)
            ->assertJsonPath('code', 'blocking_disabled');
    }

    public function test_bloqueo_queda_en_auditoria(): void
    {
        $a = $this->makeMember('A');
        $b = $this->makeMember('B');

        $this->postJson("/api/app/members/{$b->id}/block", [], $this->asMember($a));
        $this->deleteJson("/api/app/members/{$b->id}/block", [], $this->asMember($a));

        $this->assertDatabaseHas('moderation_audit_logs', [
            'actor_type' => 'member',
            'actor_id' => $a->id,
            'action' => 'member_blocked',
        ]);
        $this->assertDatabaseHas('moderation_audit_logs', [
            'action' => 'member_unblocked',
        ]);
    }

    public function test_reaccion_previa_no_reaparece_tras_bloquear(): void
    {
        $a = $this->makeMember('A');
        $b = $this->makeMember('B');
        $story = $this->makeStory($b);

        $this->postJson("/api/app/stories/{$story->id}/react",
            ['type' => 'fire'], $this->asMember($a))->assertOk();
        $this->assertSame(1, StoryReaction::count());

        $this->postJson("/api/app/members/{$b->id}/block", [], $this->asMember($a));

        // Ya no puede consultar ni cambiar esa reacción.
        $this->getJson("/api/app/stories/{$story->id}/reactions", $this->asMember($a))
            ->assertStatus(404);
    }
}

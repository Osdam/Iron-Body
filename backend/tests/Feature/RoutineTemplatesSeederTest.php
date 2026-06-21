<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Routine;
use App\Models\User;
use Database\Seeders\RoutineTemplatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Las rutinas semi-personalizadas vienen del Seeder (PDFs del gimnasio):
 *  - se cargan como plantillas multi-día,
 *  - son idempotentes,
 *  - clasifican como semi_personalized,
 *  - aparecen en /app/routines/templates (Semi/Explorar), NO en /assigned.
 */
class RoutineTemplatesSeederTest extends TestCase
{
    use RefreshDatabase;

    private function member(): Member
    {
        $user = User::create([
            'name' => 'S', 'email' => 's@example.com', 'password' => 'secret',
            'document' => '777', 'phone' => '300777', 'status' => 'active',
        ]);
        return Member::create([
            'user_id' => $user->id, 'full_name' => 'S', 'email' => 's@example.com',
            'document_number' => '777', 'phone' => '300777',
            'access_hash' => 'tok-' . uniqid(), 'status' => Member::STATUS_ACTIVE,
        ]);
    }

    private function auth(Member $m): array
    {
        return ['Authorization' => 'Bearer ' . $m->access_hash];
    }

    public function test_seeder_loads_six_multiday_semi_templates(): void
    {
        $this->seed(RoutineTemplatesSeeder::class);

        $templates = Routine::where('is_template', true)->get();
        $this->assertCount(6, $templates);

        foreach ($templates as $r) {
            $this->assertTrue($r->is_template);
            $this->assertSame('semi_personalized', $r->classifyType());
            $this->assertNotEmpty($r->days);          // programa por días (PDF)
            $this->assertCount(5, $r->days);          // Lun–Vie
        }
    }

    public function test_seeder_is_idempotent(): void
    {
        $this->seed(RoutineTemplatesSeeder::class);
        $this->seed(RoutineTemplatesSeeder::class);

        $this->assertSame(6, Routine::where('is_template', true)->count());
    }

    public function test_seeded_templates_appear_in_semi_not_in_my_routines(): void
    {
        $this->seed(RoutineTemplatesSeeder::class);
        $m = $this->member();

        // Semi-personalizadas (templates): las 6.
        $this->getJson('/api/app/routines/templates', $this->auth($m))
            ->assertOk()->assertJsonCount(6, 'data');

        // Explorar (include_hidden): también las 6.
        $this->getJson('/api/app/routines/templates?include_hidden=true', $this->auth($m))
            ->assertOk()->assertJsonCount(6, 'data');

        // "Mis rutinas": ninguna plantilla.
        $this->getJson('/api/app/routines/assigned', $this->auth($m))
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_hide_one_template_keeps_it_in_explore_only(): void
    {
        $this->seed(RoutineTemplatesSeeder::class);
        $m = $this->member();
        $r = Routine::where('is_template', true)->first();

        $this->postJson("/api/app/routines/{$r->id}/hide", [], $this->auth($m))->assertOk();

        // Lista principal: 5 (excluye la oculta).
        $this->getJson('/api/app/routines/templates', $this->auth($m))
            ->assertOk()->assertJsonCount(5, 'data');

        // Explorar: las 6, y la oculta marcada.
        $res = $this->getJson('/api/app/routines/templates?include_hidden=true', $this->auth($m))
            ->assertOk()->assertJsonCount(6, 'data');

        $hidden = collect($res->json('data'))->firstWhere('id', (string) $r->id);
        $this->assertTrue($hidden['is_hidden']);
        $this->assertTrue($hidden['can_restore']);

        // La rutina global sigue existiendo.
        $this->assertDatabaseHas('routines', ['id' => $r->id]);
    }
}

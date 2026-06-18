<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\AutomationEvent;
use App\Models\Member;
use App\Models\User;
use App\Services\ProactiveCoachService;
use App\Support\ProactiveCoach\ProactiveCoachCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Iron Body Proactive Coach (Fase 2). Cubre: construcción del mensaje premium,
 * idempotencia por día/semana, presupuesto anti-spam (fuertes/total), creación
 * de app_notifications vía notify-member sin token FCM (no falla), y que los
 * eventos base siguen mapeados. NO toca producción ni envía nada real:
 * automation.enabled=false ⇒ emit() crea la fila pero NO despacha el job.
 */
class ProactiveCoachTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // No despachar a n8n durante el test (solo crear automation_events).
        config([
            'automation.enabled' => false,
            'proactive_coach.quiet_hours.start_hour' => 0,
            'proactive_coach.quiet_hours.end_hour' => 0, // ventana nula → nunca silencia
            'proactive_coach.budget.max_strong_per_day' => 1,
            'proactive_coach.budget.max_total_per_day' => 2,
        ]);
    }

    private function member(array $over = []): Member
    {
        $user = User::create([
            'name' => 'Carlos', 'email' => 'c+' . uniqid() . '@example.com', 'password' => 'secret',
            'document' => (string) random_int(1000, 99999999), 'phone' => '3001112233', 'status' => 'active',
            'plan' => 'PLAN TOTAL', 'membership_end_date' => now()->addDays(30)->toDateString(),
        ]);
        return Member::create(array_merge([
            'user_id' => $user->id, 'full_name' => 'Carlos Pérez', 'email' => $user->email,
            'document_number' => $user->document, 'phone' => '3001112233',
            'access_hash' => 'tok-' . uniqid(), 'status' => Member::STATUS_ACTIVE,
            'goal' => 'Hipertrofia', 'training_level' => 'Intermedio',
            'birth_date' => now()->subYears(28)->toDateString(),
        ], $over));
    }

    private function coach(): ProactiveCoachService
    {
        return app(ProactiveCoachService::class);
    }

    public function test_catalog_builds_personalized_premium_message(): void
    {
        $n = ProactiveCoachCatalog::buildNotification('streak.at_risk', 'Carlos Pérez', 0);
        $this->assertNotNull($n);
        $this->assertSame('streak.at_risk', $n['type']); // type = event_type
        $this->assertSame('/iron-ai?focus=streak', $n['action_route']);
        $this->assertSame('high', $n['priority']);
        $this->assertStringContainsString('Carlos', $n['body']); // personalizado

        // Sin nombre: no deja placeholder ni coma colgando.
        $n2 = ProactiveCoachCatalog::buildNotification('coach.reactivation', null, 0);
        $this->assertStringNotContainsString('{name}', $n2['body']);
        $this->assertStringNotContainsString(' ,', $n2['body']);
    }

    public function test_emits_event_and_is_idempotent_same_day(): void
    {
        $m = $this->member();

        $r1 = $this->coach()->consider($m, 'streak.at_risk', ['streak' => ['current_streak_days' => 3]]);
        $this->assertSame('emitted', $r1['status']);
        $this->assertDatabaseHas('automation_events', [
            'event_type' => 'streak.at_risk',
            'member_id' => $m->id,
            'idempotency_key' => $r1['idempotency_key'],
        ]);
        $this->assertSame(1, AutomationEvent::where('member_id', $m->id)->count());

        // Segundo intento mismo día → duplicate, no crea otra fila.
        $r2 = $this->coach()->consider($m, 'streak.at_risk', []);
        $this->assertSame('duplicate', $r2['status']);
        $this->assertSame(1, AutomationEvent::where('member_id', $m->id)->count());
    }

    public function test_dry_run_does_not_write(): void
    {
        $m = $this->member();
        $r = $this->coach()->consider($m, 'coach.nudge', [], true);
        $this->assertSame('would_emit', $r['status']);
        $this->assertSame(0, AutomationEvent::where('member_id', $m->id)->count());
    }

    public function test_budget_blocks_second_strong_and_third_total(): void
    {
        $m = $this->member();

        // 1ª fuerte: pasa.
        $this->assertSame('emitted', $this->coach()->consider($m, 'streak.at_risk', [])['status']);
        // 2ª fuerte (otro tipo): bloqueada por presupuesto de fuertes.
        $this->assertSame('skipped', ($r = $this->coach()->consider($m, 'daily.compliance_missing', []))['status']);
        $this->assertSame('budget_strong', $r['reason']);
        // 1 suave: pasa (total = 2).
        $this->assertSame('emitted', $this->coach()->consider($m, 'coach.nudge', [])['status']);
        // Otra suave distinta: bloqueada por total diario.
        $this->assertSame('budget_total', $this->coach()->consider($m, 'iron_ai.chat_invite', [])['reason']);
    }

    public function test_notify_member_creates_app_notification_without_fcm_token(): void
    {
        config(['automation.internal_secret' => 'test-internal-secret-123456']);
        // FCM apagado por defecto en tests → push es no-op, no debe fallar.
        $m = $this->member();

        $payload = ProactiveCoachCatalog::buildNotification('workout.not_started_today', $m->full_name, 0);
        $resp = $this->withHeaders(['Authorization' => 'Bearer test-internal-secret-123456'])
            ->postJson('/api/internal/automation/notify-member', [
                'member_id' => $m->id,
                'type' => $payload['type'],
                'title' => $payload['title'],
                'body' => $payload['body'],
                'action_route' => $payload['action_route'],
                'priority' => $payload['priority'],
                'payload' => ['event_type' => 'workout.not_started_today', 'source' => 'test'],
            ]);

        $resp->assertOk()->assertJson(['ok' => true, 'status' => 'created']);
        $this->assertDatabaseHas('app_notifications', [
            'member_id' => $m->id,
            'type' => 'workout.not_started_today',
            'action_route' => '/iron-ai?focus=workout',
            'source' => 'automation',
        ]);
        // Sin token FCM activo: no se marcó entrega push, pero la notif existe.
        $this->assertNull(AppNotification::where('member_id', $m->id)->first()->delivered_at);
    }

    public function test_notify_member_rejects_without_bearer(): void
    {
        config(['automation.internal_secret' => 'test-internal-secret-123456']);
        $m = $this->member();
        $this->postJson('/api/internal/automation/notify-member', [
            'member_id' => $m->id, 'type' => 'coach.nudge', 'title' => 'x', 'body' => 'y',
        ])->assertStatus(401);
    }

    public function test_base_events_still_mapped_in_catalog_or_router(): void
    {
        // Los eventos base no son de la capa proactiva (los mapea n8n), pero los
        // nuevos sí deben existir en el catálogo premium de Laravel.
        foreach ([
            'workout.not_started_today', 'streak.at_risk', 'streak.not_started',
            'daily.compliance_missing', 'coach.nudge', 'iron_ai.chat_invite',
            'iron_ai.nutrition_invite', 'iron_ai.progress_invite', 'iron_ai.streak_invite',
            'coach.reactivation', 'weekly.coach_plan', 'module.discovery',
        ] as $ev) {
            $this->assertTrue(ProactiveCoachCatalog::isProactive($ev), "Falta en catálogo: {$ev}");
            $this->assertNotNull(ProactiveCoachCatalog::buildNotification($ev, 'Ana', 0));
        }
    }
}

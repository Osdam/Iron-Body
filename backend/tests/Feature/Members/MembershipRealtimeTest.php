<?php

namespace Tests\Feature\Members;

use App\Enums\CashShiftType;
use App\Http\Controllers\Api\Admin\MembershipRealtimeController;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\MemberTrainerAssignment;
use App\Models\Plan;
use App\Models\Trainer;
use App\Models\User;
use App\Services\Audit\AuditTrail;
use App\Services\Payments\MembershipPeriod;
use App\Support\Access\CrmPermission;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

class MembershipRealtimeTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/admin/memberships/stream';

    private ImmediateMembershipRealtimeController $stream;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('m', 32))]);
        // Solo se acorta el transporte. Se ejercitan ruta, autenticación,
        // permisos, consultas y SseStream::emit reales mediante HTTP.
        $this->stream = new ImmediateMembershipRealtimeController;
        $this->app->instance(MembershipRealtimeController::class, $this->stream);
    }

    private function user(): User
    {
        return User::create(['name' => 'Socio privado', 'email' => uniqid().'@ironbody.test', 'password' => 'secret']);
    }

    private function upgraded(User $user, array $metadata = []): AuditLog
    {
        return AuditLog::create([
            'action' => 'create', 'module' => 'Pagos', 'entity' => 'mejora de plan',
            'entity_id' => '700', 'target_name' => 'Nombre privado', 'summary' => 'Texto privado',
            'metadata' => array_merge([
                'event' => 'membership.plan.upgraded', 'user_id' => $user->id, 'plan_id' => 3,
                'changed' => ['membership', 'payments', 'history', 'features', 'plans'],
                'amount' => 130000, 'token' => 'dato-secreto',
            ], $metadata),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function events(string $body, string $name = 'memberships'): array
    {
        preg_match_all('/event: '.preg_quote($name, '/').'\ndata: ([^\n]+)/', $body, $matches);

        return array_map(fn (string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR), $matches[1]);
    }

    public function test_route_requires_session_and_a_permission_of_an_affected_screen(): void
    {
        $this->get(self::URL)->assertStatus(401);
        $this->get(self::URL, $this->adminWithPermissions(['classes.view']))->assertForbidden();

        foreach (['members.view', 'plans.view', 'payments.view'] as $permission) {
            $response = $this->get(self::URL, $this->adminWithPermissions([$permission]))->assertOk();
            $this->assertStringStartsWith('text/event-stream', $response->headers->get('Content-Type'));
        }
    }

    public function test_ticket_opens_the_same_authenticated_stream(): void
    {
        $headers = $this->adminWithPermissions(['members.view']);
        $ticket = $this->getJson('/api/admin/stream-ticket', $headers)->assertOk()->json('data.ticket');

        $response = $this->get(self::URL.'?ticket='.urlencode($ticket))->assertOk();
        $this->assertSame([['cursor' => 0, 'resync' => true]], $this->events($response->streamedContent(), 'ready'));
    }

    public function test_first_connection_announces_its_cut_and_only_delivers_new_changes(): void
    {
        $user = $this->user();
        $old = $this->upgraded($user);
        $this->stream->betweenTicks = fn () => $this->upgraded($user);

        $response = $this->get(self::URL, $this->adminHeaders())->assertOk();
        $body = $response->streamedContent();

        $this->assertSame([['cursor' => $old->id, 'resync' => true]], $this->events($body, 'ready'));
        $events = $this->events($body);
        $this->assertCount(1, $events);
        $this->assertGreaterThan($old->id, $events[0]['event_id']);
        $this->assertStringContainsString("id: {$old->id}\nevent: ready", $body);
    }

    public function test_replay_delivers_real_sse_with_only_whitelisted_fields(): void
    {
        $user = $this->user();
        $log = $this->upgraded($user, ['changed' => ['membership', 'history', 'token', ['private' => true]]]);
        $response = $this->get(self::URL.'?after_id=0', $this->adminHeaders())->assertOk();
        $body = $response->streamedContent();

        $this->assertSame([[
            'type' => 'membership.plan.upgraded', 'user_id' => $user->id, 'plan_id' => 3,
            'changed' => ['membership', 'history'], 'event_id' => $log->id,
            'timestamp' => $log->created_at->toIso8601String(),
        ]], $this->events($body));
        $this->assertStringNotContainsString('privado', $body);
        $this->assertStringNotContainsString('130000', $body);
        $this->assertStringNotContainsString('dato-secreto', $body);
        $this->assertStringContainsString("id: {$log->id}\nevent: memberships", $body);
        $this->assertSame([['cursor' => 0, 'resync' => true]], $this->events($body, 'ready'));
    }

    public function test_last_event_id_replays_the_gap_and_query_cursor_takes_precedence(): void
    {
        $user = $this->user();
        $first = $this->upgraded($user);
        $second = $this->upgraded($user);
        $headers = $this->adminHeaders(['Last-Event-ID' => (string) $first->id]);

        $events = $this->events($this->get(self::URL, $headers)->assertOk()->streamedContent());
        $this->assertSame([$second->id], array_column($events, 'event_id'));
        $this->assertSame([], $this->events(
            $this->get(self::URL.'?after_id='.$second->id, $headers)->assertOk()->streamedContent(),
        ));
    }

    public function test_a_commit_below_the_cursor_requests_a_canonical_refresh(): void
    {
        $user = $this->user();
        $this->upgraded($user)->delete();
        $later = $this->upgraded($user);
        $this->stream->betweenTicks = function () use ($user, $later): void {
            $earlier = $this->upgraded($user);
            DB::table('audit_logs')->where('id', $earlier->id)->update(['id' => $later->id - 1]);
            $this->upgraded($user);
        };

        $body = $this->get(self::URL.'?after_id='.$later->id, $this->adminHeaders())
            ->assertOk()->streamedContent();

        $this->assertSame([
            ['cursor' => $later->id, 'resync' => true],
            ['cursor' => $later->id, 'resync' => true],
        ], $this->events($body, 'ready'));
        $this->assertCount(1, $this->events($body));
        $this->assertGreaterThan($later->id, $this->events($body)[0]['event_id']);
    }

    public function test_reconnection_resynchronizes_even_when_the_last_event_id_has_no_gap(): void
    {
        $latest = $this->upgraded($this->user());
        $body = $this->get(self::URL.'?after_id='.$latest->id, $this->adminHeaders())
            ->assertOk()->streamedContent();

        $this->assertSame([['cursor' => $latest->id, 'resync' => true]], $this->events($body, 'ready'));
        $this->assertSame([], $this->events($body));
    }

    public function test_payment_cancellation_restoration_and_update_events_are_delivered(): void
    {
        $user = $this->user();
        $types = ['membership.plan.upgrade.cancelled', 'membership.plan.upgrade.restored', 'membership.plan.upgrade.updated'];
        foreach ($types as $type) {
            $this->upgraded($user, ['event' => $type]);
        }

        $events = $this->events($this->get(self::URL.'?after_id=0', $this->adminHeaders())->assertOk()->streamedContent());
        $this->assertSame($types, array_column($events, 'type'));
        $this->assertSame(array_fill(0, 3, $user->id), array_column($events, 'user_id'));
    }

    public function test_invalid_cursor_is_rejected_and_a_future_cursor_requests_resynchronization(): void
    {
        $headers = $this->adminHeaders();
        $this->getJson(self::URL.'?after_id=-1', $headers)->assertUnprocessable();

        $response = $this->get(self::URL.'?after_id=999', $headers)->assertOk();
        $this->assertSame([['cursor' => 0, 'resync' => true]], $this->events($response->streamedContent(), 'ready'));
    }

    public function test_rolled_back_or_unrelated_audits_do_not_publish_changes(): void
    {
        $user = $this->user();
        try {
            DB::transaction(function () use ($user): void {
                $this->upgraded($user);
                throw new RuntimeException('deshacer');
            });
        } catch (RuntimeException) {
        }
        AuditLog::create(['action' => 'update', 'module' => 'Usuarios', 'entity' => 'usuario',
            'metadata' => ['event' => 'membership.plan.upgraded', 'user_id' => $user->id, 'plan_id' => 3]]);

        $this->assertSame([], $this->events(
            $this->get(self::URL.'?after_id=0', $this->adminHeaders())->assertOk()->streamedContent(),
        ));
    }

    public function test_plans_only_session_gets_an_invalidation_without_member_identity(): void
    {
        $log = $this->upgraded($this->user());
        $events = $this->events($this->get(self::URL.'?after_id=0', $this->adminWithPermissions(['plans.view']))
            ->assertOk()->streamedContent());

        $this->assertCount(1, $events);
        $this->assertNull($events[0]['user_id']);
        $this->assertSame(['plans'], $events[0]['changed']);
        $this->assertSame($log->id, $events[0]['event_id']);
    }

    public function test_trainer_stream_never_reveals_an_unassigned_member(): void
    {
        $trainer = Trainer::create(['full_name' => 'Entrenadora', 'status' => 'active']);
        $assigned = $this->user();
        $other = $this->user();
        $member = Member::create(['full_name' => $assigned->name, 'email' => $assigned->email,
            'document_number' => '100000001', 'phone' => '3000000001', 'status' => 'active', 'user_id' => $assigned->id]);
        MemberTrainerAssignment::create(['member_id' => $member->id, 'trainer_id' => $trainer->id,
            'status' => 'active', 'assigned_at' => now()]);
        $ownLog = $this->upgraded($assigned);
        $this->upgraded($other);
        $admin = Admin::create(['name' => 'Cuenta Entrenadora', 'email' => 'coach@ironbody.test',
            'password' => 'secret', 'role' => CrmPermission::ROLE_ENTRENADOR, 'trainer_id' => $trainer->id, 'status' => 'active']);

        $events = $this->events($this->get(self::URL.'?after_id=0', $this->actingAsAdmin($admin))
            ->assertOk()->streamedContent());

        $this->assertSame([$ownLog->id], array_column($events, 'event_id'));
        $this->assertSame([$assigned->id], array_column($events, 'user_id'));
    }

    public function test_plan_endpoints_publish_catalog_and_benefit_invalidations(): void
    {
        $headers = $this->adminHeaders();
        $planId = $this->postJson('/api/plans', ['name' => 'Trimestre SSE', 'price' => 210000,
            'duration_days' => 90, 'active' => true], $headers)->assertCreated()->json('id');
        $this->patchJson('/api/plans/'.$planId, ['price' => 220000, 'duration_days' => 92], $headers)->assertOk();
        $this->putJson('/api/plans/'.$planId.'/features', ['features' => ['classes' => true]], $headers)->assertOk();
        $this->putJson('/api/plans/'.$planId.'/ai-capabilities', ['ai_chat_enabled' => true], $headers)->assertOk();
        $this->deleteJson('/api/plans/'.$planId, [], $headers)->assertNoContent();

        $events = $this->events($this->get(self::URL.'?after_id=0', $headers)->assertOk()->streamedContent());
        $this->assertSame(['plan.created', 'plan.updated', 'plan.updated', 'plan.updated', 'plan.deleted'], array_column($events, 'type'));
        $this->assertSame(array_fill(0, 5, $planId), array_column($events, 'plan_id'));
        foreach ($events as $event) {
            $this->assertNull($event['user_id']);
            $this->assertSame(['plans', 'features'], $event['changed']);
        }
    }

    public function test_a_real_upgrade_request_reaches_the_already_open_stream(): void
    {
        $monthly = Plan::create(['name' => 'Mensual SSE', 'price' => 80000, 'duration_days' => 30, 'active' => true]);
        $quarterly = Plan::create(['name' => 'Trimestre SSE', 'price' => 210000, 'duration_days' => 90, 'active' => true]);
        $user = $this->user();
        $start = MembershipPeriod::today()->subDays(7);
        $user->forceFill(['plan' => $monthly->name, 'status' => 'active',
            'membership_start_date' => $start->toDateString(), 'membership_end_date' => $start->addDays(30)->toDateString()])->save();
        $this->openCashShift(type: CashShiftType::GYM);
        $headers = $this->adminHeaders();
        $this->stream->betweenTicks = function () use ($user, $quarterly, $headers): void {
            $this->postJson('/api/users/'.$user->id.'/plan-upgrade', ['plan_id' => $quarterly->id, 'method' => 'cash'], $headers)
                ->assertCreated();
        };

        $events = $this->events($this->get(self::URL, $headers)->assertOk()->streamedContent());

        $this->assertCount(1, $events);
        $this->assertSame('membership.plan.upgraded', $events[0]['type']);
        $this->assertSame($user->id, $events[0]['user_id']);
        $this->assertSame($quarterly->id, $events[0]['plan_id']);
        $this->assertSame(['membership', 'payments', 'history', 'features', 'plans'], $events[0]['changed']);
        $this->assertSame($start->addDays(90)->toDateString(), $user->refresh()->membershipEndDate);
    }

    public function test_failed_plan_audit_rolls_back_the_plan_and_the_stream_has_no_event(): void
    {
        $audit = Mockery::mock(AuditTrail::class);
        $audit->shouldReceive('record')->once()->andThrow(new RuntimeException('auditoría fallida'));
        $this->app->instance(AuditTrail::class, $audit);
        $headers = $this->adminHeaders();

        $this->postJson('/api/plans', ['name' => 'No confirmado', 'price' => 210000, 'duration_days' => 90, 'active' => true], $headers)
            ->assertStatus(500);

        $this->assertFalse(Plan::where('name', 'No confirmado')->exists());
        $this->assertSame([], $this->events($this->get(self::URL.'?after_id=0', $headers)->assertOk()->streamedContent()));
    }
}

/** El HTTP de prueba entrega los mismos eventos sin dormir entre ticks. */
class ImmediateMembershipRealtimeController extends MembershipRealtimeController
{
    public ?Closure $betweenTicks = null;

    protected function response(callable $tick, callable $onOpen): StreamedResponse
    {
        return new StreamedResponse(function () use ($tick, $onOpen): void {
            $onOpen();
            $tick();
            if ($this->betweenTicks) {
                ($this->betweenTicks)();
            }
            $tick();
        }, 200, ['Content-Type' => 'text/event-stream']);
    }
}

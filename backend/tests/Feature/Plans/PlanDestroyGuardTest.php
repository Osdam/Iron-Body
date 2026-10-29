<?php

namespace Tests\Feature\Plans;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlanTransferBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * DELETE /api/plans/{plan}: un plan que algo referencia NO se borra (409
 * `plan_has_history`, con qué lo usa y la alternativa: archivarlo). Sin
 * dependencias se borra como siempre. Es lo que dejó 31 cobros «sin plan» el
 * 16-10-2026.
 */
class PlanDestroyGuardTest extends TestCase
{
    use RefreshDatabase;

    private Plan $plan;

    private array $h;

    protected function setUp(): void
    {
        parent::setUp();
        $this->h = $this->adminAs(Admin::ROLE_ADMINISTRADOR);
        $this->plan = Plan::create(['name' => 'Trimestral Élite', 'price' => 210000, 'duration_days' => 90, 'active' => true]);
    }

    public function test_sin_dependencias_se_borra_como_siempre_y_deja_su_traza(): void
    {
        $this->borrar()->assertNoContent();

        $this->assertNull(Plan::find($this->plan->id));
        $traza = AuditLog::where('action', 'delete')->where('entity', 'plan')->sole();
        $this->assertSame((string) $this->plan->id, $traza->entity_id);
        $this->assertSame('Trimestral Élite', $traza->target_name);
        $this->assertSame('plan.deleted', $traza->metadata['event']);
    }

    public function test_con_cobros_responde_409_y_no_borra_nada(): void
    {
        $socio = $this->socio(null);
        $this->pago($socio, ['status' => 'paid']);
        $this->pago($socio, ['status' => 'cancelled']);

        $r = $this->borrar()->assertStatus(409)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'plan_has_history')
            ->assertJsonPath('can_archive', true)
            ->assertJsonPath('is_archived', false)
            ->assertJsonPath('archive.method', 'POST')
            ->assertJsonPath('archive.path', '/api/plans/'.$this->plan->id.'/archive');
        $this->assertSame([
            'payments' => 2, 'members' => 0, 'plan_transfers' => 0,
            'membership_transfers' => 0, 'gateway_transactions' => 0, 'subscriptions' => 0,
        ], $r->json('dependencies'), 'Todas las claves, cero incluido.');
        $this->assertStringContainsString('2 cobros', $r->json('message'));
        $this->assertStringContainsString('Archívalo', $r->json('message'));

        $this->assertNotNull(Plan::find($this->plan->id));
        $this->assertSame(2, Payment::where('plan_id', $this->plan->id)->count(), 'Los cobros conservan su plan.');
        $this->assertSame(0, AuditLog::where('action', 'delete')->count());
    }

    public function test_un_cobro_que_salio_de_este_plan_por_mejora_tambien_cuenta(): void
    {
        $otro = Plan::create(['name' => 'Anual', 'price' => 600000, 'duration_days' => 365, 'active' => true]);
        $this->pago($this->socio(null), ['plan_id' => $otro->id, 'upgraded_from_plan_id' => $this->plan->id]);

        $this->borrar()->assertStatus(409)->assertJsonPath('dependencies.payments', 1);
    }

    public function test_con_socios_que_lo_tienen_por_nombre_responde_409(): void
    {
        // `users.plan` guarda el nombre; se compara como la puerta: sin
        // mayúsculas ni tildes.
        $this->socio('Trimestral Élite');
        $this->socio('trimestral elite');

        $r = $this->borrar()->assertStatus(409)->assertJsonPath('dependencies.members', 2);
        $this->assertStringContainsString('2 socios con este plan', $r->json('message'));
        $this->assertNotNull(Plan::find($this->plan->id));
    }

    public function test_con_traslados_de_plan_que_lo_referencian_responde_409(): void
    {
        $destino = Plan::create(['name' => 'Élite', 'price' => 180000, 'duration_days' => 30, 'active' => true]);
        PlanTransferBatch::create([
            'kind' => PlanTransferBatch::KIND_TRANSFER, 'status' => PlanTransferBatch::STATUS_APPLIED,
            'from_plan_id' => $this->plan->id, 'to_plan_id' => $destino->id,
            'from_plan_name' => $this->plan->name, 'to_plan_name' => $destino->name,
            'from_conditions' => [], 'to_conditions' => [], 'reason' => 'Unificar',
            'fingerprint' => str_repeat('a', 64), 'client_request_id' => 'lote-guarda',
            'members_count' => 0, 'business_day' => '2026-10-16', 'executed_at' => now(),
        ]);

        $r = $this->borrar()->assertStatus(409)->assertJsonPath('dependencies.plan_transfers', 1);
        $this->assertStringContainsString('1 traslado de plan', $r->json('message'));
        // También el destino.
        $this->deleteJson('/api/plans/'.$destino->id, [], $this->h)->assertStatus(409)->assertJsonPath('dependencies.plan_transfers', 1);
    }

    public function test_varias_dependencias_se_dicen_todas(): void
    {
        $socio = $this->socio('Trimestral Élite');
        $this->pago($socio);

        $r = $this->borrar()->assertStatus(409);
        $this->assertSame([1, 1, 0], [$r->json('dependencies.payments'), $r->json('dependencies.members'), $r->json('dependencies.plan_transfers')]);
        $this->assertStringContainsString('lo usan 1 cobro y 1 socio con este plan', $r->json('message'));
    }

    public function test_la_alternativa_que_ofrece_funciona_archivar_lo_saca_de_la_venta_y_de_la_app(): void
    {
        $this->pago($this->socio(null));
        $alternativa = $this->borrar()->assertStatus(409)->json('archive');

        $this->postJson($alternativa['path'], ['reason' => 'Ya no se vende; tiene cobros'], $this->h)->assertOk()
            ->assertJsonPath('plan.is_archived', true);

        $plan = $this->plan->fresh();
        $this->assertFalse($plan->isSellable());
        $this->assertFalse($plan->isVisibleInApp());
        // Archivado sigue sin poder borrarse, y lo dice.
        $this->borrar()->assertStatus(409)->assertJsonPath('is_archived', true)->assertJsonPath('dependencies.payments', 1);
    }

    // ────────────────────────────────────────────────────────────────────────

    private function borrar(): TestResponse
    {
        return $this->deleteJson('/api/plans/'.$this->plan->id, [], $this->h);
    }

    private function socio(?string $plan): User
    {
        static $n = 0;
        $n++;

        return User::create([
            'name' => 'Socio guarda '.$n, 'email' => 'guarda-'.$n.'-'.uniqid().'@ironbody.test',
            'password' => 'secret', 'status' => 'active', 'plan' => $plan,
        ]);
    }

    /** @param  array<string, mixed>  $extra */
    private function pago(User $socio, array $extra = []): Payment
    {
        return Payment::create(array_merge([
            'user_id' => $socio->id, 'plan_id' => $this->plan->id, 'amount' => 210000,
            'method' => 'cash', 'status' => 'paid', 'paid_at' => now(),
        ], $extra));
    }
}

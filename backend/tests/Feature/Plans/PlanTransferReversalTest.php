<?php

namespace Tests\Feature\Plans;

use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\PlanTransferBatch;
use App\Models\PlanTransferItem;
use App\Models\User;
use App\Services\Membership\MembershipAccess;
use App\Services\Membership\MembershipAdjustments;
use App\Services\Membership\PlanAccessRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * DESHACER UN TRASLADO: otro lote, nunca un borrado.
 *
 * Lo que se fija: que cada socio vuelve al TEXTO que tenía —variante de
 * escritura incluida—; que quien cambió después (un cobro, un ajuste, otro
 * plan) se queda fuera y se dice por qué; que el lote original pasa a
 * `reverted` o `partially_reverted` sin perder nada; y que el mismo socio de
 * un lote no se puede revertir dos veces, ni por la API ni por la base.
 */
class PlanTransferReversalTest extends TestCase
{
    use PlanTransferFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararTraslado();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  list<User>  $socios */
    private function lote(array $socios): PlanTransferBatch
    {
        $r = $this->trasladar(array_map(fn (User $u): int => $u->id, $socios))->assertCreated();

        return PlanTransferBatch::findOrFail($r->json('batch.id'));
    }

    public function test_revertir_devuelve_el_texto_original_incluida_la_variante(): void
    {
        $exacto = $this->socio();
        $variante = $this->socio('Total Access - Élite');
        $lote = $this->lote([$exacto, $variante]);
        $this->assertSame('Élite', $variante->refresh()->plan);
        $this->avanzarReloj(30);

        $simulacion = $this->simularReversion($lote->id)->assertOk()
            ->assertJsonPath('batch_id', $lote->id)
            ->assertJsonPath('summary.revertible', 2)
            ->assertJsonPath('summary.blocked', 0)
            ->json();
        $this->assertNotEmpty($simulacion['irreversible']);
        $porSocio = collect($simulacion['items'])->keyBy('user_id');
        $this->assertSame('Total Access - Élite', $porSocio[$variante->id]['restore_to']['plan']);
        $this->assertSame($this->origen->id, $porSocio[$variante->id]['restore_to']['plan_id']);

        $r = $this->revertir($lote->id)->assertCreated()
            ->assertJsonPath('batch.kind', 'reversal')
            ->assertJsonPath('batch.reverts_batch_id', $lote->id)
            ->assertJsonPath('batch.members_count', 2)
            ->assertJsonPath('batch.from_plan.name', 'Élite')
            ->assertJsonPath('batch.to_plan.name', 'TOTAL ACCESS - ELITE');

        $this->assertSame('TOTAL ACCESS - ELITE', $exacto->refresh()->plan);
        $this->assertSame('Total Access - Élite', $variante->refresh()->plan, 'La reversión normalizó el texto en vez de devolverlo.');
        $this->assertSame(PlanTransferBatch::STATUS_REVERTED, $lote->refresh()->status);
        $this->assertSame(2, PlanTransferItem::where('batch_id', $lote->id)->where('status', 'reverted')->whereNotNull('reverted_at')->count());

        // Las dos trazas: la del traslado sigue, y la reversión se suma.
        $this->assertSame(1, AuditLog::where('metadata->event', 'plan.transfer.executed')->count());
        $this->assertSame(2, AuditLog::where('metadata->event', 'membership.plan.transferred')->count());
        $this->assertSame(1, AuditLog::where('metadata->event', 'plan.transfer.reverted')->count());
        $this->assertSame(2, AuditLog::where('metadata->event', 'membership.plan.transfer.reverted')->count());

        // El detalle del original apunta a la reversión.
        $detalle = $this->getJson("/api/admin/plan-transfers/{$lote->id}", $this->h)->assertOk()
            ->assertJsonPath('status', 'reverted')
            ->assertJsonPath('reversals.0.id', $r->json('batch.id'));
        $this->assertSame([$r->json('batch.id'), $r->json('batch.id')], array_column($detalle->json('items'), 'reverted_in_batch_id'));
        $this->assertTrue($detalle->json('items.0.current.changed_since'));
    }

    public function test_quien_cambio_despues_se_queda_fuera_y_el_resto_se_revierte(): void
    {
        $conPago = $this->socio();
        $conAjuste = $this->socio();
        $limpio = $this->socio();
        $lote = $this->lote([$conPago, $conAjuste, $limpio]);

        Payment::create(['user_id' => $conPago->id, 'plan_id' => $this->destino->id, 'amount' => 180000,
            'method' => 'transfer', 'status' => 'pending']);
        app(MembershipAdjustments::class)->addDays($conAjuste->refresh(), 2, 'Compensación');

        $simulacion = $this->simularReversion($lote->id)->assertOk()
            ->assertJsonPath('summary.revertible', 1)
            ->assertJsonPath('summary.blocked', 2)
            ->json();
        $porSocio = collect($simulacion['items'])->keyBy('user_id');
        $this->assertSame('paid_after', $porSocio[$conPago->id]['blockers'][0]['code']);
        $this->assertSame('membership_changed_after', $porSocio[$conAjuste->id]['blockers'][0]['code']);
        $this->assertTrue($porSocio[$conAjuste->id]['current']['changed_since']);
        $this->assertTrue($porSocio[$limpio->id]['revertible']);

        $r = $this->revertir($lote->id)->assertCreated()->assertJsonPath('batch.members_count', 1);

        $this->assertEqualsCanonicalizing(
            [['user_id' => $conPago->id, 'code' => 'paid_after'], ['user_id' => $conAjuste->id, 'code' => 'membership_changed_after']],
            $r->json('batch.excluded'),
        );
        $this->assertSame('TOTAL ACCESS - ELITE', $limpio->refresh()->plan);
        $this->assertSame('Élite', $conPago->refresh()->plan);
        $this->assertSame('Élite', $conAjuste->refresh()->plan);
        $this->assertSame(PlanTransferBatch::STATUS_PARTIALLY_REVERTED, $lote->refresh()->status);

        // El mismo socio no se revierte dos veces: ya no está aplicado.
        $this->simularReversion($lote->id, [$limpio->id])->assertOk()
            ->assertJsonPath('items.0.blockers.0.code', 'item_not_applied')
            ->assertJsonPath('summary.revertible', 0);
        $this->revertir($lote->id, [$limpio->id])->assertStatus(422)->assertJsonPath('code', 'nothing_to_transfer');
        $this->assertSame(1, PlanTransferBatch::where('kind', 'reversal')->count());

        // Y aunque algo se saltara esa comprobación, la base lo impide.
        $original = PlanTransferItem::where('batch_id', $lote->id)->where('user_id', $limpio->id)->firstOrFail();
        $this->expectException(UniqueConstraintViolationException::class);
        PlanTransferItem::create(['batch_id' => $r->json('batch.id'), 'user_id' => null, 'user_name' => 'Duplicado',
            'before' => [], 'after' => [], 'status' => 'applied', 'reverts_item_id' => $original->id]);
    }

    public function test_al_revertir_un_valera_vuelven_a_contar_sus_siete_entradas(): void
    {
        $valera = $this->plan('Valera', ['access_mode' => PlanAccessRules::MODE_ENTRIES, 'entry_credits' => 15,
            'sellable' => false, 'visible_in_app' => false]);
        $socio = $this->socio($valera);
        $this->entradas($socio, 7);

        $r = $this->trasladar([$socio->id], [], $valera, $this->destino)->assertCreated();
        $this->assertNull(app(MembershipAccess::class)->state($socio->refresh())['entries'], 'En ilimitado no se cuentan.');

        $simulacion = $this->simularReversion($r->json('batch.id'))->assertOk()->json();
        $this->assertContains('entries_counted', array_column($simulacion['items'][0]['warnings'], 'code'));
        $this->assertTrue($simulacion['requires_confirmation']);

        $this->revertir($r->json('batch.id'))->assertCreated();

        $acceso = app(MembershipAccess::class)->state($socio->refresh());
        $this->assertSame('Valera', $socio->plan);
        $this->assertSame(7, $acceso['entries']['used']);
        $this->assertSame(15, $acceso['entries']['total']);
    }

    public function test_una_reversion_no_se_revierte_y_los_socios_ajenos_se_rechazan(): void
    {
        $socio = $this->socio();
        $lote = $this->lote([$socio]);
        $reversion = $this->revertir($lote->id)->assertCreated()->json('batch.id');

        $this->simularReversion($reversion)->assertStatus(422)->assertJsonPath('code', 'reversal_not_revertible');

        $otro = $this->socio();
        $lote2 = $this->lote([$otro]);
        $this->simularReversion($lote2->id, [$socio->id])->assertStatus(422)->assertJsonPath('code', 'not_in_batch');
    }

    public function test_la_reversion_tambien_es_idempotente_y_detecta_simulacion_vieja(): void
    {
        $socio = $this->socio();
        $lote = $this->lote([$socio]);
        $vieja = $this->simularReversion($lote->id)->assertOk()->json();

        // Alguien edita el plan origen: al volver, el socio ya no recuperaría
        // lo que se le enseñó al confirmar.
        $this->origen->update(['features' => array_merge($this->origen->features, ['nutrition' => false])]);
        $this->postJson("/api/admin/plan-transfers/{$lote->id}/revert", [
            'fingerprint' => $vieja['fingerprint'], 'reason' => 'Se trasladaron por error',
            'client_request_id' => 'rev-vieja-1', 'confirm_warnings' => true,
        ], $this->h)->assertStatus(409)->assertJsonPath('code', 'simulation_stale');
        $this->assertSame('Élite', $socio->refresh()->plan);

        $nueva = $this->simularReversion($lote->id)->assertOk()->json();
        $cuerpo = ['fingerprint' => $nueva['fingerprint'], 'reason' => 'Se trasladaron por error',
            'client_request_id' => 'rev-doble-1', 'confirm_warnings' => true];
        $primera = $this->postJson("/api/admin/plan-transfers/{$lote->id}/revert", $cuerpo, $this->h)->assertCreated();
        $this->postJson("/api/admin/plan-transfers/{$lote->id}/revert", $cuerpo, $this->h)
            ->assertOk()->assertJsonPath('replayed', true)->assertJsonPath('batch.id', $primera->json('batch.id'));
        $this->assertSame(1, PlanTransferBatch::where('kind', 'reversal')->count());
    }

    public function test_el_historial_lista_traslados_y_reversiones(): void
    {
        $a = $this->socio();
        $b = $this->socio();
        $lote = $this->lote([$a, $b]);
        $this->revertir($lote->id, [$a->id])->assertCreated();

        $this->getJson('/api/admin/plan-transfers', $this->h)->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.kind', 'reversal')
            ->assertJsonPath('data.1.id', $lote->id)
            ->assertJsonPath('data.1.status', 'partially_reverted')
            ->assertJsonPath('data.1.reverted_count', 1)
            ->assertJsonPath('data.1.members_count', 2)
            ->assertJsonPath('data.1.from_plan.name', 'TOTAL ACCESS - ELITE');

        $this->getJson('/api/admin/plan-transfers?kind=transfer', $this->h)->assertOk()->assertJsonPath('total', 1);
        $this->getJson('/api/admin/plan-transfers?plan_id='.$this->destino->id, $this->h)->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/admin/plan-transfers?plan_id=999999', $this->h)->assertOk()->assertJsonPath('total', 0);
    }
}

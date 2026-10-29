<?php

namespace Tests\Feature\Members;

use App\Models\AuditLog;
use App\Models\MembershipTransfer;
use App\Models\Payment;
use App\Models\PlanTransferBatch;
use App\Models\PlanTransferItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Plans\DeletedPlanFixtures;
use Tests\TestCase;

/**
 * TRANSFERIR MEMBRESÍA cuando el plan del cobro se borró del catálogo y la
 * socia ya está en otro plan por un traslado: el caso de Yurany (28-10-2026).
 *
 *   · Antes de restaurar el plan: `source_plan_deleted`, no el engañoso
 *     `no_source_payment` («ajuste manual, cortesía, importación…»).
 *   · Después: el traslado vivo explica el cambio de plan (`plan_mismatch` ya
 *     no aplica) y la beneficiaria recibe «Élite», el plan actual de la socia.
 *   · Todo lo demás sigue bloqueando igual (deuda, congelación…), y sin un
 *     traslado vivo, posterior al cobro, el plan distinto sigue siendo
 *     `plan_mismatch`.
 */
class MembershipTransferDeletedPlanTest extends TestCase
{
    use DeletedPlanFixtures;
    use MembershipTransferFixtures;
    use RefreshDatabase;

    private User $beneficiaria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planesDelCaso();
        $this->prepararTraspasos();
        $this->beneficiaria = $this->socio('Beatriz Beneficiaria');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_antes_de_restaurar_bloquea_con_source_plan_deleted_y_no_con_no_source_payment(): void
    {
        [$yurany] = $this->casoYurany();

        $previa = $this->previa($yurany, $this->beneficiaria)->assertOk()->assertJsonPath('allowed', false);

        $this->assertContains('source_plan_deleted', $this->codigos($previa));
        $this->assertNotContains('no_source_payment', $this->codigos($previa));
        $this->assertSame(
            'El plan de su cobro se eliminó del catálogo; hay que restaurarlo como histórico antes de ceder.',
            collect($previa->json('blockers'))->firstWhere('code', 'source_plan_deleted')['message'],
        );
        $this->transferir($yurany, $this->beneficiaria)->assertStatus(409)->assertJsonPath('code', 'transfer_blocked')
            ->assertJsonPath('blockers.0.code', 'source_plan_deleted');
        $this->assertSame(0, MembershipTransfer::count());
    }

    public function test_sin_cobro_de_verdad_sigue_siendo_no_source_payment(): void
    {
        // Vigencia puesta a mano, sin cobro: el bloqueo de siempre.
        $sinCobro = $this->socio('Sin Cobro');
        $sinCobro->forceFill(['plan' => 'Élite', 'membership_start_date' => '2026-10-20', 'membership_end_date' => '2026-11-19'])->save();
        $this->reloj('2026-10-28 10:00');

        $this->assertSame(['no_source_payment'], $this->codigos($this->previa($sinCobro->fresh(), $this->beneficiaria)->assertOk()));
    }

    public function test_despues_de_restaurar_cede_y_la_beneficiaria_recibe_el_plan_actual_elite(): void
    {
        [$yurany, $cobro, $lote] = $this->casoYurany();
        $this->restaurar();
        $item = PlanTransferItem::where('batch_id', $lote->id)->where('user_id', $yurany->id)->sole();

        $this->previa($yurany, $this->beneficiaria)->assertOk()
            ->assertJsonPath('allowed', true)
            ->assertJsonPath('blockers', [])
            ->assertJsonPath('effects.source_payment.id', $cobro->id)
            ->assertJsonPath('effects.source_payment.plan_id', 22)
            ->assertJsonPath('effects.source_payment.plan_name', 'TOTAL ACCESS - ELITE')
            ->assertJsonPath('effects.source_payment.plan_active', false)
            ->assertJsonPath('effects.source_payment.plan_transfer_item_id', $item->id)
            ->assertJsonPath('effects.beneficiary.plan_after', 'Élite')
            ->assertJsonPath('effects.end_date', '2026-11-18');

        $antes = $this->fotoIntocable();
        $r = $this->transferir($yurany, $this->beneficiaria)->assertCreated()
            ->assertJsonPath('transfer.plan.id', $this->elite->id)
            ->assertJsonPath('transfer.plan.name', 'Élite')
            ->assertJsonPath('transfer.source_payment_id', $cobro->id)
            ->assertJsonPath('transfer.effective_date', '2026-10-28')
            ->assertJsonPath('transfer.end_date', '2026-11-18');

        $b = $this->beneficiaria->fresh();
        $this->assertSame(['Élite', '2026-10-28', '2026-11-18', 'active'], [$b->plan, $b->membershipStartDate, $b->membershipEndDate, $b->status]);
        $this->assertSame(['Élite', '2026-10-27'], [$yurany->fresh()->plan, $yurany->fresh()->membershipEndDate]);
        $this->assertSame($antes, $this->fotoIntocable(), 'El traspaso tocó dinero, documentos o el catálogo.');

        // El traspaso guarda lo transferido, el cobro, el plan COMPRADO y el
        // ítem de traslado que lo justifica.
        $fila = MembershipTransfer::findOrFail($r->json('transfer.id'));
        $this->assertSame([$this->elite->id, 'Élite'], [$fila->plan_id, $fila->plan_name]);
        $this->assertSame([22, 'TOTAL ACCESS - ELITE', $item->id], [$fila->source_plan_id, $fila->source_plan_name, $fila->plan_transfer_item_id]);
        $this->assertSame($cobro->id, $fila->source_payment_id);
        $r->assertJsonPath('transfer.source_plan', ['id' => 22, 'name' => 'TOTAL ACCESS - ELITE'])
            ->assertJsonPath('transfer.plan_transfer_item_id', $item->id);
        foreach ([$yurany, $this->beneficiaria] as $socio) {
            $this->getJson("/api/users/{$socio->id}/membership-transfers", $this->h())->assertOk()
                ->assertJsonPath('data.0.plan', ['id' => $this->elite->id, 'name' => 'Élite'])
                ->assertJsonPath('data.0.source_payment_id', $cobro->id)
                ->assertJsonPath('data.0.source_plan', ['id' => 22, 'name' => 'TOTAL ACCESS - ELITE'])
                ->assertJsonPath('data.0.plan_transfer_item_id', $item->id);
        }
        $trazas = AuditLog::where('metadata->event', 'membership.transferred')->get();
        $this->assertCount(2, $trazas);
        foreach ($trazas as $t) {
            $this->assertSame($item->id, $t->metadata['plan_transfer_item_id']);
            $this->assertSame(22, $t->metadata['source_plan_id']);
            $this->assertSame('TOTAL ACCESS - ELITE', $t->metadata['source_plan_name']);
            $this->assertSame($this->elite->id, $t->metadata['plan_id']);
            $this->assertSame($cobro->id, $t->metadata['payment_id']);
        }
    }

    public function test_una_deuda_viva_sigue_bloqueando_y_solo_ella(): void
    {
        $deudora = $this->socio('Débora Deuda');
        $this->cobroAPlazos($deudora, saldar: false);
        $this->trasladoDePlan([$deudora->id]);
        $this->borrarPlanComoEl16DeOctubre($this->totalAccess);
        $this->restaurar();

        $this->assertSame(['source_receivable_unpaid'], $this->codigos($this->previa($deudora->fresh(), $this->beneficiaria)->assertOk()));
    }

    public function test_una_congelacion_sigue_bloqueando(): void
    {
        [$yurany] = $this->casoYurany();
        $this->restaurar();
        $this->postJson("/api/users/{$yurany->id}/freeze", ['days' => 10, 'reason' => 'Viaje'], $this->h())->assertSuccessful();

        $this->assertContains('holder_frozen', $this->codigos($this->previa($yurany->fresh(), $this->beneficiaria)->assertOk()));
    }

    public function test_un_plan_archivado_no_bloquea_cuando_es_el_suyo(): void
    {
        // Sin traslado: la socia sigue en «TOTAL ACCESS - ELITE» y el plan del
        // cobro, restaurado, está archivado. Inactivo no es motivo de bloqueo.
        $fiel = $this->socio('Fidelia Fiel');
        $this->cobroAPlazos($fiel);
        $this->borrarPlanComoEl16DeOctubre($this->totalAccess);
        $this->restaurar();

        $this->previa($fiel->fresh(), $this->beneficiaria)->assertOk()
            ->assertJsonPath('allowed', true)
            ->assertJsonPath('effects.source_payment.plan_transfer_item_id', null)
            ->assertJsonPath('effects.beneficiary.plan_after', 'TOTAL ACCESS - ELITE');

        // Sin traslado, el plan comprado y el transferido son el mismo.
        $this->transferir($fiel->fresh(), $this->beneficiaria)->assertCreated()
            ->assertJsonPath('transfer.plan', ['id' => 22, 'name' => 'TOTAL ACCESS - ELITE'])
            ->assertJsonPath('transfer.source_plan', ['id' => 22, 'name' => 'TOTAL ACCESS - ELITE'])
            ->assertJsonPath('transfer.plan_transfer_item_id', null);
    }

    public function test_sin_traslado_vivo_el_plan_distinto_es_plan_mismatch(): void
    {
        // El plan se cambió a mano en la ficha: ningún traslado lo explica.
        $manual = $this->socio('Manuela Manual');
        $this->cobroAPlazos($manual);
        DB::table('users')->where('id', $manual->id)->update(['plan' => 'Élite']);
        $this->borrarPlanComoEl16DeOctubre($this->totalAccess);
        $this->restaurar();

        $this->assertSame(['plan_mismatch'], $this->codigos($this->previa($manual->fresh(), $this->beneficiaria)->assertOk()));
    }

    public function test_con_el_traslado_revertido_el_plan_distinto_es_plan_mismatch(): void
    {
        $revertida = $this->socio('Rebeca Revertida');
        $this->cobroAPlazos($revertida);
        $lote = $this->trasladoDePlan([$revertida->id]);
        $this->revertirTraslado($lote);
        $this->assertSame(PlanTransferItem::STATUS_REVERTED, PlanTransferItem::where('batch_id', $lote->id)->sole()->status);
        // Vuelta a «Élite» a mano, después: el traslado revertido ya no la explica.
        DB::table('users')->where('id', $revertida->id)->update(['plan' => 'Élite']);
        $this->borrarPlanComoEl16DeOctubre($this->totalAccess);
        $this->restaurar();

        $this->assertSame(['plan_mismatch'], $this->codigos($this->previa($revertida->fresh(), $this->beneficiaria)->assertOk()));
    }

    public function test_un_traslado_anterior_al_cobro_no_lo_explica(): void
    {
        // Vigente por un periodo heredado (sin cobro), se trasladó el 14-10,
        // compró el plan 22 el 15-10 y se le puso «Élite» a mano: el traslado
        // es de antes del cobro y no habla de él.
        $previa = $this->socio('Paula Previa');
        DB::table('users')->where('id', $previa->id)->update([
            'plan' => 'TOTAL ACCESS - ELITE', 'membership_start_date' => '2026-10-01', 'membership_end_date' => '2026-10-14',
        ]);
        $this->trasladoDePlan([$previa->id], cuando: '2026-10-14 09:00');
        $this->cobroAPlazos($previa);
        $this->assertSame('TOTAL ACCESS - ELITE', $previa->fresh()->plan, 'Cobrar el plan vuelve a escribirlo.');
        DB::table('users')->where('id', $previa->id)->update(['plan' => 'Élite']);
        $this->borrarPlanComoEl16DeOctubre($this->totalAccess);
        $this->restaurar();

        $this->assertSame(['plan_mismatch'], $this->codigos($this->previa($previa->fresh(), $this->beneficiaria)->assertOk()));
    }

    // ────────────────────────────────────────────────────────────────────────

    /**
     * Plan a plazos del 22 saldado (15-10), traslado 22 → 8 (16-10 12:23),
     * borrado del 22 (16-10 13:02) y reloj en el 28-10.
     *
     * @return array{0: User, 1: Payment, 2: PlanTransferBatch}
     */
    private function casoYurany(): array
    {
        $yurany = $this->socio('Yurany Pabón');
        $cobro = $this->cobroAPlazos($yurany);
        $lote = $this->trasladoDePlan([$yurany->id]);
        $this->assertSame('Élite', $yurany->fresh()->plan);
        $this->borrarPlanComoEl16DeOctubre($this->totalAccess);
        $this->assertNull($cobro->fresh()->plan_id);
        $this->reloj('2026-10-28 10:00');

        return [$yurany->fresh(), $cobro->fresh(), $lote];
    }

    private function restaurar(): void
    {
        $this->reloj('2026-10-28 09:00');
        [$codigo, $salida] = $this->restaurarPlan(['--commit' => true]);
        $this->assertSame(0, $codigo, $salida);
        $this->reloj('2026-10-28 10:00');
    }
}

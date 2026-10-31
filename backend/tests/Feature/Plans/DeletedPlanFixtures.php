<?php

namespace Tests\Feature\Plans;

use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\PlanTransferBatch;
use App\Models\Receivable;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * El caso real del plan borrado, para las pruebas (se usa junto a
 * MembershipTransferFixtures, que pone el administrador, la caja y los socios):
 *
 *   · plan 22 «TOTAL ACCESS - ELITE» (heredado) y plan 8 «Élite» (el de hoy),
 *     con sus ids de producción (llamar ANTES de crear otros planes);
 *   · 15-10: una socia compra el plan 22 A PLAZOS (abono de 100.000 y saldo de
 *     80.000), con inicio el 19-10;
 *   · 16-10 12:23: traslado de plan 22 → 8 (solo cambia `users.plan`);
 *   · 16-10 13:02: se borra el plan 22 como se borró entonces: la llave SET
 *     NULL deja sus cobros sin plan y queda la traza `plan / delete`.
 *
 * El borrado se reproduce A MANO (anular las referencias y borrar la fila):
 * hoy ni el controlador (409) ni PostgreSQL (RESTRICT) lo permitirían, y
 * producción ya está en ese estado. Es exactamente lo que hizo SET NULL.
 *
 * Relojes en UTC (instantes de Neiva convertidos), como el resto de la suite.
 */
trait DeletedPlanFixtures
{
    protected Plan $elite;

    protected Plan $totalAccess;

    /** La fila del plan 22 tal como la trae el snapshot del respaldo. */
    protected array $snapshotRow = [];

    protected function planesDelCaso(): void
    {
        // Las columnas y los tipos del snapshot real (respaldo del 16-10).
        $this->snapshotRow = [
            'id' => 22,
            'name' => 'TOTAL ACCESS - ELITE',
            'price' => '180000.00',
            'duration_days' => 30,
            'benefits' => ['Acceso al gimnasio durante la vigencia del plan', 'Plan heredado del sistema anterior'],
            'access_classes' => true,
            'reservations_limit' => null,
            'access_locations' => null,
            'restrictions' => null,
            'active' => true,
            'created_at' => '2026-09-01 04:56:25',
            'updated_at' => '2026-10-08 23:05:39',
            'original_price' => null,
            'is_recommended' => true,
            'badge' => null,
            'sort_order' => 108,
            'features' => ['iron_ia' => true, 'workouts' => true, 'custom_routines' => true, 'ranking' => true,
                'classes' => true, 'progress' => true, 'nutrition' => true],
            'tier' => 'lite',
            'tax_rate_id' => null,
            'price_includes_tax' => true,
            'unspsc_code' => null,
            'pricing_mode' => 'legacy_inclusive',
            'billing_enabled' => true,
            'sellable' => true,
            'access_mode' => 'unlimited',
            'entry_credits' => null,
            'access_days' => null,
            'access_windows' => null,
            'visible_in_app' => true,
        ];
        DB::table('plans')->insert(array_map(
            fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $v,
            $this->snapshotRow,
        ));
        $this->totalAccess = Plan::findOrFail(22);

        $elite = new Plan;
        $elite->forceFill([
            'id' => 8, 'name' => 'Élite', 'price' => 180000, 'duration_days' => 30,
            'active' => true, 'sellable' => true, 'visible_in_app' => true,
            'features' => $this->snapshotRow['features'],
        ])->save();
        $this->elite = $elite->refresh();

        // Los ids van explícitos y en PostgreSQL eso no mueve la secuencia (que
        // además no retrocede con el ROLLBACK de cada prueba): sin esto, un
        // `Plan::create` posterior acabaría cayendo en el 8 o en el 22. En
        // producción la secuencia ya pasó de ellos. SQLite lo hace solo.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::select("SELECT setval(pg_get_serial_sequence('plans', 'id'), GREATEST((SELECT MAX(id) FROM plans), (SELECT last_value FROM plans_id_seq)))");
        }
    }

    /**
     * Plan a plazos por la caja real, el 15-10, con inicio el 19-10. Saldado
     * con un segundo abono, salvo que se pida dejar la deuda viva.
     */
    protected function cobroAPlazos(User $socio, bool $saldar = true, ?Plan $plan = null): Payment
    {
        $this->reloj('2026-10-15 10:00');
        $r = $this->postJson('/api/admin/receivables/plan', [
            'user_id' => $socio->id, 'plan_id' => ($plan ?? $this->totalAccess)->id,
            'first_payment' => 100000, 'method' => 'transfer', 'starts_on' => '2026-10-19',
        ], $this->h())->assertStatus(201);
        if ($saldar) {
            $deuda = Receivable::findOrFail($r->json('data.id'));
            $this->postJson("/api/admin/receivables/{$deuda->id}/payments", ['amount' => 80000, 'method' => 'cash'], $this->h())
                ->assertStatus(201);
            $this->assertSame(Receivable::STATUS_PAID, $deuda->fresh()->status);
        }

        return Payment::findOrFail($r->json('payment_id'));
    }

    /** Traslado de plan real (simular + ejecutar), el 16-10 a las 12:23 de Neiva. */
    protected function trasladoDePlan(array $userIds, ?Plan $de = null, ?Plan $a = null, string $cuando = '2026-10-16 12:23'): PlanTransferBatch
    {
        $this->reloj($cuando);
        $de ??= $this->totalAccess;
        $a ??= $this->elite;
        $sim = $this->postJson('/api/admin/plan-transfers/dry-run', [
            'from_plan_id' => $de->id, 'to_plan_id' => $a->id, 'user_ids' => $userIds,
        ], $this->h())->assertOk()->json();
        $id = $this->postJson('/api/admin/plan-transfers', [
            'from_plan_id' => $de->id, 'to_plan_id' => $a->id, 'user_ids' => $userIds,
            'fingerprint' => $sim['fingerprint'], 'reason' => 'Unificar el plan heredado con Élite',
            'client_request_id' => 'op-'.uniqid('', true), 'confirm_warnings' => true,
        ], $this->h());
        $this->assertTrue($id->isSuccessful(), 'Traslado rechazado: '.$id->getContent());
        $id = $id->json('batch.id');

        return PlanTransferBatch::findOrFail($id);
    }

    /**
     * Revierte el traslado, por la API: entero o solo para esos socios.
     *
     * @param  list<int>|null  $userIds
     */
    protected function revertirTraslado(PlanTransferBatch $lote, ?array $userIds = null): void
    {
        $ids = $userIds === null ? [] : ['user_ids' => $userIds];
        $sim = $this->postJson("/api/admin/plan-transfers/{$lote->id}/revert/dry-run", $ids, $this->h())->assertOk()->json();
        $this->postJson("/api/admin/plan-transfers/{$lote->id}/revert", $ids + [
            'fingerprint' => $sim['fingerprint'], 'reason' => 'Se trasladaron por error',
            'client_request_id' => 'rev-'.uniqid('', true), 'confirm_warnings' => true,
        ], $this->h())->assertSuccessful();
    }

    /**
     * El borrado del 16-10 a las 13:02 de Neiva: la traza que escribió
     * PlanController::destroy y lo que hizo ON DELETE SET NULL en cada tabla
     * que apunta a `plans`.
     */
    protected function borrarPlanComoEl16DeOctubre(Plan $plan, ?string $cuando = '2026-10-16 13:02', bool $conTraza = true): void
    {
        $this->reloj($cuando);
        if ($conTraza) {
            AuditLog::create([
                'action' => 'delete', 'module' => 'Planes', 'entity' => 'plan',
                'entity_id' => (string) $plan->id, 'target_name' => $plan->name,
                'summary' => "Eliminó el plan {$plan->name}",
                'metadata' => ['event' => 'plan.deleted', 'plan_id' => (int) $plan->id, 'changed' => ['plans', 'features']],
                'actor_id' => (string) $this->super->id, 'actor_name' => $this->super->name, 'actor_role' => $this->super->role,
                'created_at' => now(),
            ]);
        }
        DB::table('payments')->where('plan_id', $plan->id)->update(['plan_id' => null]);
        DB::table('plan_transfer_batches')->where('from_plan_id', $plan->id)->update(['from_plan_id' => null]);
        DB::table('plan_transfer_batches')->where('to_plan_id', $plan->id)->update(['to_plan_id' => null]);
        DB::table('membership_transfers')->where('plan_id', $plan->id)->update(['plan_id' => null]);
        DB::table('membership_ai_capabilities')->where('membership_plan_id', $plan->id)->update(['membership_plan_id' => null]);
        DB::table('marketing_conversations')->where('recommended_plan_id', $plan->id)->update(['recommended_plan_id' => null]);
        DB::table('plans')->where('id', $plan->id)->delete();
    }

    /** Escribe el snapshot como el del respaldo y devuelve su ruta. */
    protected function snapshotFile(?array $row = null, string $table = 'plans'): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'plan-snapshot-');
        file_put_contents($ruta, json_encode([
            'source' => '/var/backups/ironbody/pre-endurecimiento-2-20261016T155026Z/ironbody.dump',
            'dump_sha256' => '29628f7ffae928eee829252c30f0e28859d0e68e2bfe58e9ff668f625ef7a290',
            'dump_sha256_matches_SHA256SUMS' => true,
            'table' => $table,
            'row' => $row ?? $this->snapshotRow,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $ruta;
    }

    /**
     * Ejecuta el comando y devuelve [código de salida, salida].
     *
     * @param  array<string, mixed>  $opciones
     * @return array{0: int, 1: string}
     */
    protected function restaurarPlan(array $opciones = []): array
    {
        $codigo = Artisan::call('plans:restore-deleted', array_merge([
            '--plan' => 22,
            '--snapshot' => $this->snapshotFile(),
            '--actor' => $this->super->id,
        ], $opciones));

        return [$codigo, Artisan::output()];
    }
}

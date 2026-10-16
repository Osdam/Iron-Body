<?php

namespace Tests\Feature\Plans;

use App\Models\Attendance;
use App\Models\Member;
use App\Models\MembershipAdjustment;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Membership\PlanAccessRules;
use App\Services\Membership\PlanTransferEvaluator;
use Illuminate\Support\Carbon;

/**
 * Un escenario FIJO —ids, fechas, reloj— cuya huella tiene que salir igual en
 * SQLite y en PostgreSQL.
 *
 * La huella se compara entre la simulación que vio el operador y la que se
 * repite bajo bloqueo, y las dos corren en el mismo motor. Pero si un dato
 * viajara distinto según el motor —un importe que PostgreSQL devuelve como
 * «180000.00» y SQLite como 180000, un JSON con las claves en otro orden—, la
 * suite en SQLite no lo vería nunca. Por eso se fija aquí un valor constante y
 * se exige en los dos motores (PlanTransferFingerprintTest y
 * PlanTransferPostgresTest).
 *
 * Si cambia el contrato de la simulación, EXPECTED cambia a propósito y en los
 * dos sitios a la vez. Si solo falla en un motor, hay un dato que no viaja
 * igual, y eso es justo lo que esta prueba existe para encontrar.
 */
final class PlanTransferFingerprintScenario
{
    public const EXPECTED = 'f44a6b3e897f2933c9543c3e5ee4eb970064063b7ddcbe00c3e6b93e65987352';

    public static function build(): string
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 10:00', 'America/Bogota')->utc());

        $origen = self::plan(9001, 'TOTAL ACCESS - ELITE', ['sellable' => false, 'visible_in_app' => false]);
        $destino = self::plan(9002, 'Élite', [
            'price' => 195000.5,
            'access_mode' => PlanAccessRules::MODE_ENTRIES,
            'entry_credits' => 20,
            'access_days' => [1, 2, 3, 4, 5],
            'access_windows' => [['from' => '05:00', 'to' => '22:00', 'label' => 'Todo el día']],
            'features' => ['classes' => false, 'nutrition' => true, 'iron_ia' => true],
            'benefits' => "Acceso total\nIRON IA",
        ]);

        // Vigente con entradas, variante de escritura, congelado y uno de
        // otro plan (bloqueado).
        $vigente = self::user(9101, 'TOTAL ACCESS - ELITE', '2026-10-05', '2026-11-04');
        $variante = self::user(9102, 'Total Access - Élite', '2026-10-01', '2026-10-31');
        $congelado = self::user(9103, 'TOTAL ACCESS - ELITE', '2026-09-20', '2026-10-14', [
            'status' => 'inactive',
            'membership_frozen_at' => '2026-10-15',
            'membership_frozen_until' => '2026-10-30',
            'membership_frozen_days_left' => 6,
            'membership_status_before_freeze' => 'active',
        ]);
        self::user(9104, 'Mensual', '2026-10-01', '2026-10-31');

        self::payment(9201, $vigente->id, $origen->id, '2026-10-05', '2026-11-04');
        self::payment(9202, $variante->id, $origen->id, '2026-10-01', '2026-10-31');

        $member = new Member;
        $member->forceFill([
            'id' => 9301, 'full_name' => 'Huella Uno', 'email' => 'huella-9101@ironbody.test',
            'document_number' => '9101', 'phone' => '3009101', 'status' => 'active', 'user_id' => $vigente->id,
        ])->save();

        foreach (['2026-10-13', '2026-10-14'] as $dia) {
            Attendance::create(['user_id' => $vigente->id, 'action' => 'entry', 'source' => 'facial',
                'captured_at' => Carbon::parse($dia.' 09:00', 'America/Bogota')->utc()]);
        }

        $ajuste = new MembershipAdjustment;
        $ajuste->forceFill(['id' => 9401, 'user_id' => $variante->id, 'kind' => MembershipAdjustment::KIND_ENTRIES,
            'amount' => 2, 'window_start' => '2026-10-01', 'window_end' => '2026-10-31', 'reason' => 'Huella'])->save();

        return app(PlanTransferEvaluator::class)->simulate(
            Plan::findOrFail(9001),
            Plan::findOrFail(9002),
            [9104, 9103, 9102, 9101],
        )['fingerprint'];
    }

    /** @param  array<string, mixed>  $extra */
    private static function plan(int $id, string $nombre, array $extra = []): Plan
    {
        $plan = new Plan;
        $plan->forceFill(array_merge([
            'id' => $id, 'name' => $nombre, 'price' => 180000, 'duration_days' => 30, 'active' => true,
            'sellable' => true, 'visible_in_app' => true,
            'features' => ['classes' => true, 'nutrition' => true, 'iron_ia' => true],
        ], $extra))->save();

        return $plan->refresh();
    }

    /** @param  array<string, mixed>  $extra */
    private static function user(int $id, string $plan, string $inicio, string $fin, array $extra = []): User
    {
        $user = new User;
        $user->forceFill(array_merge([
            'id' => $id, 'name' => 'Huella '.$id, 'email' => 'huella-'.$id.'@ironbody.test', 'password' => 'secret',
            'document' => (string) $id, 'status' => 'active', 'plan' => $plan,
            'membership_start_date' => $inicio, 'membership_end_date' => $fin,
        ], $extra))->save();

        return $user->refresh();
    }

    private static function payment(int $id, int $userId, int $planId, string $inicio, string $fin): void
    {
        $pago = new Payment;
        $pago->forceFill([
            'id' => $id, 'user_id' => $userId, 'plan_id' => $planId, 'amount' => 180000, 'method' => 'cash',
            'status' => 'paid', 'paid_at' => Carbon::parse($inicio.' 09:00', 'America/Bogota')->utc(),
            'starts_on' => $inicio, 'period_start' => $inicio, 'period_end' => $fin,
        ])->save();
    }
}

<?php

namespace Tests\Feature\Workforce\Concerns;

use App\Models\EmployeeAccess;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;

/**
 * Socios para las pruebas de la ficha laboral: la forma de Yuri en producción
 * (ficha de socio SIN identidad, usuario con plan de empleados, un pago de 1 y
 * acceso gratuito concedido a mano).
 */
trait MemberKit
{
    /**
     * @param  array<string, mixed>  $member
     * @param  array<string, mixed>  $user
     */
    protected function socio(string $document, array $member = [], array $user = []): Member
    {
        $u = User::create(array_merge([
            'name' => 'Yuri Andrea Socia',
            'email' => 'socio-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'document' => $document,
            'status' => 'active',
            'plan' => 'INGRESO EMPLEADOS',
            'membership_start_date' => '2026-09-23',
            'membership_end_date' => '2027-09-18',
        ], $user));

        return Member::create(array_merge([
            'user_id' => $u->id,
            'full_name' => 'Yuri Andrea Socia',
            'email' => $u->email,
            'document_number' => $document,
            'phone' => '+57 300 555 1234',
            'status' => Member::STATUS_ACTIVE,
            'is_staff' => false,
        ], $member));
    }

    /** Lo que Yuri tiene además de la ficha: el pago de 1 y el acceso gratuito manual. */
    protected function conPagoYAccesoGratuito(Member $member): EmployeeAccess
    {
        $plan = Plan::firstOrCreate(['name' => 'INGRESO EMPLEADOS'], [
            'price' => 1, 'duration_days' => 360, 'active' => true, 'features' => Plan::defaultFeatures(),
        ]);
        Payment::create([
            'user_id' => $member->user_id, 'plan_id' => $plan->id, 'amount' => 1,
            'method' => 'cash', 'status' => 'paid', 'paid_at' => now(),
            'period_start' => '2026-09-23', 'period_end' => '2027-09-18',
        ]);

        return EmployeeAccess::create([
            'user_id' => $member->user_id, 'position' => 'Administrativa', 'source' => 'manual',
            'active' => true, 'granted_by_name' => 'Dueño Iron Body',
        ]);
    }

    /**
     * Foto de todo lo que la ficha laboral NO debe tocar de un socio.
     *
     * @return array<string, mixed>
     */
    protected function intocable(Member $member): array
    {
        $member->refresh();
        $user = User::query()->findOrFail($member->user_id);

        return [
            'member' => [
                'is_staff' => (bool) $member->is_staff,
                'phone' => $member->phone,
                'status' => $member->status,
                'user_id' => $member->user_id,
                'document_number' => $member->document_number,
            ],
            'user' => [
                'plan' => $user->plan,
                'start' => $user->membership_start_date,
                'end' => $user->membership_end_date,
                'updated_at' => $user->updated_at?->toIso8601String(),
            ],
            'payments' => Payment::query()->where('user_id', $member->user_id)->orderBy('id')->get(['id', 'amount', 'status'])->toArray(),
            'employee_access' => EmployeeAccess::query()->where('user_id', $member->user_id)->get()
                ->map(fn (EmployeeAccess $e) => $e->only(['id', 'source', 'active', 'position', 'starts_on', 'ends_on', 'revoked_at']))
                ->all(),
        ];
    }
}

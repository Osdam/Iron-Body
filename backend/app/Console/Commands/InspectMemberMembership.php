<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\MembershipPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Qué membresía tiene un socio y de qué pagos salió.
 *
 *   php artisan memberships:inspect 1234
 *   php artisan memberships:inspect elizacalde20@gmail.com
 *
 * SOLO LEE. No escribe nada, ni en seco ni de otra forma.
 *
 * Existe porque la pantalla del CRM muestra la fecha del cobro ya formateada
 * —y una fecha sin hora, al pintarla, se corre de día— así que mirando el CRM
 * no se puede distinguir un cobro del día 12 de uno del 11. Aquí sale el valor
 * crudo tal como está guardado, la hora en la zona del negocio y el día con el
 * que MembershipPeriod hizo la cuenta, que es el que de verdad decide.
 */
class InspectMemberMembership extends Command
{
    protected $signature = 'memberships:inspect {socio : users.id, documento o correo}';

    protected $description = 'Muestra la membresía de un socio y los periodos que le dio cada pago (solo lectura)';

    public function handle(): int
    {
        $clave = (string) $this->argument('socio');

        $user = User::query()
            ->when(ctype_digit($clave), fn ($q) => $q->where('id', (int) $clave))
            ->when(! ctype_digit($clave), fn ($q) => $q->where('email', $clave)->orWhere('document', $clave))
            ->first();

        if (! $user) {
            $this->error("No hay ningún socio con «{$clave}».");

            return self::FAILURE;
        }

        $hoy = MembershipPeriod::today();

        $this->newLine();
        $this->line("<options=bold>#{$user->id} {$user->name}</> — {$user->email}");
        $this->line('  plan            : '.($user->plan ?: '—'));
        $this->line('  inicio          : '.($user->membership_start_date ?: '—'));
        $this->line('  vence           : '.($user->membership_end_date ?: '—'));
        $this->line('  hoy (negocio)   : '.$hoy->toDateString().' ('.MembershipPeriod::TZ.')');

        if ($user->membership_end_date) {
            $dias = (int) $hoy->diffInDays(CarbonImmutable::parse($user->membership_end_date, MembershipPeriod::TZ), false);
            $this->line('  le quedan       : '.$dias.' días');
        }

        $pagos = Payment::where('user_id', $user->id)
            ->orderBy('id')
            ->get(['id', 'status', 'plan_id', 'amount', 'origin', 'paid_at', 'starts_on', 'period_start', 'period_end', 'reference']);

        $this->newLine();
        $this->line('<options=bold>PAGOS</> ('.$pagos->count().')');

        if ($pagos->isEmpty()) {
            $this->line('  —');

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'estado', 'plan', 'paid_at (crudo)', 'paid_at (Neiva)', 'día que contó', 'starts_on', 'periodo'],
            $pagos->map(fn (Payment $p) => [
                $p->id,
                $p->status,
                $p->plan_id ?: '—',
                // Crudo: lo que hay en la columna, sin interpretar. Una hora
                // 00:00:00 aquí delata un cobro guardado como fecha suelta.
                $p->getRawOriginal('paid_at') ?: '—',
                $p->paid_at ? $p->paid_at->copy()->setTimezone(MembershipPeriod::TZ)->format('Y-m-d H:i') : '—',
                $p->paid_at
                    ? $p->paid_at->copy()->setTimezone(MembershipPeriod::TZ)->toDateString()
                    : $hoy->toDateString().' (sin fecha: se usa hoy)',
                $p->starts_on?->toDateString() ?: '—',
                $p->period_start && $p->period_end
                    ? $p->period_start->toDateString().' → '.$p->period_end->toDateString()
                    : '— (sin periodo)',
            ])->all()
        );

        $this->line('Un pago NO cobrado que todavía tiene periodo es uno que sumó días y nunca los devolvió:');
        $this->line('lo corrige <options=bold>php artisan memberships:repair-cancelled --user='.$user->id.' --apply</>');
        $this->newLine();

        return self::SUCCESS;
    }
}

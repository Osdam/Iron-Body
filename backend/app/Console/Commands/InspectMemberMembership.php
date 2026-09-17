<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Plan;
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

        // Una cédula también es solo dígitos: probar primero el id y, si no
        // existe, buscarla como documento. Antes un número se tomaba siempre
        // por id y ninguna cédula aparecía.
        $user = (ctype_digit($clave) ? User::find((int) $clave) : null)
            ?? User::query()
                ->where('document', Member::normalizeDocumentNumber($clave))
                ->orWhere('email', $clave)
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

        $pagos = Payment::where('user_id', $user->id)->orderBy('id')->get();

        $this->newLine();
        $this->line('<options=bold>PAGOS</> ('.$pagos->count().')');

        if ($pagos->isEmpty()) {
            $this->line('  —');

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'estado', 'plan', 'paid_at (crudo)', 'día que contó', 'starts_on', 'periodo'],
            $pagos->map(fn (Payment $p) => [
                $p->id,
                $p->status,
                $p->plan_id ?: '—',
                // Crudo: lo que hay en la columna, sin interpretar. Una hora
                // 00:00:00 aquí delata un cobro guardado como fecha suelta.
                $p->getRawOriginal('paid_at') ?: '—',
                $p->paid_at
                    ? $p->paid_at->copy()->setTimezone(MembershipPeriod::TZ)->toDateString()
                    : $hoy->toDateString().' (sin fecha: se usa hoy)',
                $p->starts_on?->toDateString() ?: '—',
                $p->period_start && $p->period_end
                    ? $p->period_start->toDateString().' → '.$p->period_end->toDateString()
                    : '— (sin periodo)',
            ])->all()
        );

        // De dónde salió cada fila. Un pago sin periodo pero con membresía
        // extendida no lo escribió MembershipPeriod: el origen dice quién fue.
        $this->newLine();
        $this->line('<options=bold>ORIGEN DE CADA PAGO</>');
        $this->table(
            ['#', 'referencia', 'origin', 'método', 'registrado por', 'creado', 'modificado'],
            $pagos->map(fn (Payment $p) => [
                $p->id,
                $p->reference ?: '—',
                $p->origin ?: '—',
                $p->method ?: '—',
                $p->registered_by_name ?: '—',
                (string) $p->created_at,
                (string) $p->updated_at,
            ])->all()
        );

        // El catálogo. Si el plan dura otra cosa de la que se cree, la cuenta
        // nunca estuvo mal: estaba bien hecha sobre un número equivocado.
        $planes = Plan::whereIn('id', $pagos->pluck('plan_id')->filter()->unique())->get();
        if ($planes->isNotEmpty()) {
            $this->newLine();
            $this->line('<options=bold>PLANES QUE PAGÓ</>');
            $this->table(
                ['id', 'nombre', 'duration_days', 'precio', 'activo'],
                $planes->map(fn (Plan $p) => [
                    $p->id, $p->name, $p->duration_days, $p->price, $p->active ? 'sí' : 'no',
                ])->all()
            );
        }

        $trazas = AuditLog::query()
            ->where(fn ($q) => $q->where('entity', 'membership')->where('entity_id', (string) $user->id))
            // FinancialAudit registra los cobros como `pago`; otras trazas usan `payment`.
            ->orWhere(fn ($q) => $q->whereIn('entity', ['payment', 'pago'])->whereIn('entity_id', $pagos->pluck('id')->map(fn ($i) => (string) $i)))
            ->orderBy('id')
            ->get(['id', 'created_at', 'action', 'entity', 'entity_id', 'actor_name', 'summary']);

        $this->newLine();
        $this->line('<options=bold>RASTRO DE AUDITORÍA</> ('.$trazas->count().')');
        if ($trazas->isEmpty()) {
            $this->line('  — sin trazas');
        } else {
            $this->table(
                ['cuándo', 'acción', 'sobre', 'quién', 'qué'],
                $trazas->map(fn (AuditLog $a) => [
                    (string) $a->created_at, $a->action, $a->entity.' '.$a->entity_id,
                    $a->actor_name ?: '—', $a->summary,
                ])->all()
            );
        }

        $this->newLine();

        return self::SUCCESS;
    }
}

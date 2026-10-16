<?php

namespace Tests\Feature\Plans;

use App\Models\Admin;
use App\Models\Attendance;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Payments\MembershipPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * El escenario real del traslado, para las pruebas: el plan heredado
 * «TOTAL ACCESS - ELITE» y el plan que se vende hoy, «Élite» CON TILDE.
 *
 * El reloj va SIEMPRE en UTC: con el reloj en zona Bogotá las columnas
 * datetime se leerían en Bogotá y ocultarían fallos que en producción (UTC) sí
 * ocurren. El día del gimnasio lo calcula el código.
 *
 * El plan origen nace oculto y sin vender —lo que el dueño hará antes de
 * ejecutar— y con las mismas condiciones que el destino, para que el caso
 * base no tenga avisos de plan y cada prueba añada solo el que estudia.
 */
trait PlanTransferFixtures
{
    protected Plan $origen;

    protected Plan $destino;

    /** Cabeceras del Administrador que opera, salvo que la prueba diga otra cosa. */
    protected array $h = [];

    protected function prepararTraslado(): void
    {
        $this->reloj();
        $this->origen = $this->plan('TOTAL ACCESS - ELITE', ['sellable' => false, 'visible_in_app' => false]);
        $this->destino = $this->plan('Élite');
        $this->h = $this->adminAs(Admin::ROLE_ADMINISTRADOR);
    }

    /** Jueves 15 de octubre de 2026, 10:00 en Neiva, como instante UTC. */
    protected function reloj(string $local = '2026-10-15 10:00'): void
    {
        Carbon::setTestNow(Carbon::parse($local, 'America/Bogota')->utc());
    }

    protected function avanzarReloj(int $minutos): void
    {
        Carbon::setTestNow(Carbon::now()->addMinutes($minutos));
    }

    protected function hoy(): CarbonImmutable
    {
        return MembershipPeriod::today();
    }

    /** @param  array<string, mixed>  $extra */
    protected function plan(string $nombre, array $extra = []): Plan
    {
        return Plan::create(array_merge([
            'name' => $nombre,
            'price' => 180000,
            'duration_days' => 30,
            'active' => true,
            'sellable' => true,
            'visible_in_app' => true,
            'features' => [
                'iron_ia' => true, 'workouts' => true, 'custom_routines' => true, 'ranking' => true,
                'classes' => true, 'progress' => true, 'nutrition' => true,
            ],
        ], $extra))->refresh();
    }

    /**
     * Un socio vigente con su periodo pagado: empezó hace diez días y le
     * quedan veinte. `$plan` puede ser un texto para escribir variantes.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function socio(Plan|string|null $plan = null, array $extra = [], bool $conPago = true, bool $conApp = true): User
    {
        static $n = 0;
        $n++;
        $plan ??= $this->origen;
        $inicio = $this->hoy()->subDays(10);
        $fin = $inicio->addDays(30);

        $user = User::create(array_merge([
            'name' => 'Socio '.$n,
            'email' => 'socio-'.$n.'-'.uniqid().'@ironbody.test',
            'password' => 'secret',
            'document' => (string) (1000000000 + $n),
            'status' => 'active',
            'plan' => $plan instanceof Plan ? $plan->name : $plan,
            'membership_start_date' => $inicio->toDateString(),
            'membership_end_date' => $fin->toDateString(),
        ], $extra));

        if ($conPago) {
            $planDelPago = $plan instanceof Plan ? $plan : $this->origen;
            Payment::create([
                'user_id' => $user->id,
                'plan_id' => $planDelPago->id,
                'amount' => $planDelPago->price,
                'method' => 'cash',
                'status' => 'paid',
                'paid_at' => $inicio->setTime(9, 0)->utc(),
                'starts_on' => $inicio->toDateString(),
                'period_start' => $inicio->toDateString(),
                'period_end' => $fin->toDateString(),
            ]);
        }

        if ($conApp) {
            Member::create([
                'full_name' => $user->name,
                'email' => $user->email,
                'document_number' => (string) $user->document,
                'phone' => '300'.str_pad((string) $n, 7, '0', STR_PAD_LEFT),
                'status' => 'active',
                'user_id' => $user->id,
            ]);
        }

        return $user->refresh();
    }

    /** Marca N días distintos de entrada, hacia atrás desde ayer. */
    protected function entradas(User $user, int $dias): void
    {
        for ($i = 1; $i <= $dias; $i++) {
            Attendance::create([
                'user_id' => $user->id,
                'action' => 'entry',
                'source' => 'facial',
                'captured_at' => $this->hoy()->subDays($i)->setTime(9, 0)->utc(),
            ]);
        }
    }

    // ── HTTP ────────────────────────────────────────────────────────────────

    /** @param  list<int>  $ids */
    protected function simular(array $ids, ?Plan $from = null, ?Plan $to = null, ?array $h = null): TestResponse
    {
        return $this->postJson('/api/admin/plan-transfers/dry-run', [
            'from_plan_id' => ($from ?? $this->origen)->id,
            'to_plan_id' => ($to ?? $this->destino)->id,
            'user_ids' => $ids,
        ], $h ?? $this->h);
    }

    /**
     * Simula y ejecuta lo simulado, como el CRM.
     *
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $extra
     */
    protected function trasladar(array $ids, array $extra = [], ?Plan $from = null, ?Plan $to = null, ?array $h = null): TestResponse
    {
        $simulacion = $this->simular($ids, $from, $to, $h)->assertOk()->json();

        return $this->ejecutar($simulacion, $ids, $extra, $from, $to, $h);
    }

    /**
     * @param  array<string, mixed>  $simulacion
     * @param  list<int>  $ids
     * @param  array<string, mixed>  $extra
     */
    protected function ejecutar(array $simulacion, array $ids, array $extra = [], ?Plan $from = null, ?Plan $to = null, ?array $h = null): TestResponse
    {
        return $this->postJson('/api/admin/plan-transfers', array_merge([
            'from_plan_id' => ($from ?? $this->origen)->id,
            'to_plan_id' => ($to ?? $this->destino)->id,
            'user_ids' => $ids,
            'fingerprint' => $simulacion['fingerprint'],
            'reason' => 'Unificar el plan heredado con Élite',
            'client_request_id' => 'op-'.uniqid('', true),
            'confirm_warnings' => true,
        ], $extra), $h ?? $this->h);
    }

    /** @param  list<int>|null  $ids */
    protected function simularReversion(int $lote, ?array $ids = null, ?array $h = null): TestResponse
    {
        return $this->postJson("/api/admin/plan-transfers/{$lote}/revert/dry-run",
            $ids === null ? [] : ['user_ids' => $ids], $h ?? $this->h);
    }

    /**
     * @param  list<int>|null  $ids
     * @param  array<string, mixed>  $extra
     */
    protected function revertir(int $lote, ?array $ids = null, array $extra = [], ?array $h = null): TestResponse
    {
        $simulacion = $this->simularReversion($lote, $ids, $h)->assertOk()->json();

        return $this->postJson("/api/admin/plan-transfers/{$lote}/revert", array_merge(
            $ids === null ? [] : ['user_ids' => $ids],
            [
                'fingerprint' => $simulacion['fingerprint'],
                'reason' => 'Se trasladaron por error',
                'client_request_id' => 'rev-'.uniqid('', true),
                'confirm_warnings' => true,
            ],
            $extra,
        ), $h ?? $this->h);
    }

    // ── Fotos de la base ────────────────────────────────────────────────────

    /**
     * Todas las filas de una tabla, con todas sus columnas. Para afirmar que
     * algo NO cambió hay que mirarlo entero, no las columnas que uno espera.
     *
     * @return list<array<string, mixed>>
     */
    protected function tabla(string $tabla): array
    {
        return DB::table($tabla)->orderBy('id')->get()->map(fn ($fila): array => (array) $fila)->all();
    }

    /** @return array<string, mixed> */
    protected function fila(string $tabla, int $id): array
    {
        return (array) DB::table($tabla)->where('id', $id)->first();
    }

    /** @return array<string, list<array<string, mixed>>> */
    protected function fotoExterna(): array
    {
        return [
            'payments' => $this->tabla('payments'),
            'payment_transactions' => $this->tabla('payment_transactions'),
            'membership_subscriptions' => $this->tabla('membership_subscriptions'),
            'receivables' => $this->tabla('receivables'),
            'receivable_payments' => $this->tabla('receivable_payments'),
            'electronic_invoices' => $this->tabla('electronic_invoices'),
            'member_contracts' => $this->tabla('member_contracts'),
            'membership_adjustments' => $this->tabla('membership_adjustments'),
            'attendances' => $this->tabla('attendances'),
            'class_reservations' => $this->tabla('class_reservations'),
            'employee_accesses' => $this->tabla('employee_accesses'),
            'members' => $this->tabla('members'),
            'plans' => $this->tabla('plans'),
            'membership_ai_capabilities' => $this->tabla('membership_ai_capabilities'),
        ];
    }
}

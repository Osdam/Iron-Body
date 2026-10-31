<?php

namespace Tests\Feature\Members;

use App\Enums\CashShiftType;
use App\Models\Admin;
use App\Models\Member;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\User;
use App\Services\Caja\CashShiftService;
use App\Services\Payments\MembershipPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * El escenario de las pruebas de TRANSFERIR MEMBRESÍA
 * (docs/ops/TRANSFERENCIA-DE-MEMBRESIAS.md): la titular compra su anualidad el
 * 1 de septiembre de 2026 por la caja REAL (POST /api/payments, turno abierto),
 * y el traspaso se hace el jueves 15 de octubre.
 *
 * Relojes SIEMPRE como instante UTC: con el reloj en zona Bogotá las columnas
 * datetime se leerían en Bogotá y ocultarían fallos que en producción (UTC) sí
 * ocurren. El día del gimnasio lo calcula el código.
 */
trait MembershipTransferFixtures
{
    protected Admin $super;

    protected Plan $anualidad;

    protected Plan $mensual;

    protected function prepararTraspasos(): void
    {
        $this->reloj('2026-09-01 09:00');
        $this->super = Admin::create([
            'name' => 'Fredy Supervisión',
            'email' => 'traspasos-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'role' => Admin::ROLE_SUPER_ADMIN,
            'status' => 'active',
        ]);
        app(CashShiftService::class)->open($this->super, CashShiftType::GYM);
        $this->anualidad = $this->plan('Anualidad Élite', 365, 624000);
        $this->mensual = $this->plan('Mensual', 30, 80000);
    }

    protected function reloj(string $local): void
    {
        Carbon::setTestNow(Carbon::parse($local, 'America/Bogota')->utc());
    }

    /** El día del traspaso: jueves 15 de octubre de 2026, 10:00 en Neiva. */
    protected function diaDelTraspaso(): void
    {
        $this->reloj('2026-10-15 10:00');
    }

    protected function hoy(): CarbonImmutable
    {
        return MembershipPeriod::today();
    }

    protected function h(): array
    {
        return $this->actingAsAdmin($this->super);
    }

    /** @param  array<string, mixed>  $extra */
    protected function plan(string $nombre, int $dias, int $precio, array $extra = []): Plan
    {
        return Plan::create(array_merge([
            'name' => $nombre,
            'price' => $precio,
            'duration_days' => $dias,
            'active' => true,
            'sellable' => true,
            'visible_in_app' => true,
            'features' => [
                'iron_ia' => true, 'workouts' => true, 'custom_routines' => true, 'ranking' => true,
                'classes' => true, 'progress' => true, 'nutrition' => true,
            ],
        ], $extra))->refresh();
    }

    /** Un socio con su ficha de la app (o sin ella), sin membresía. */
    protected function socio(string $nombre, bool $conFicha = true, array $ficha = []): User
    {
        static $n = 0;
        $n++;
        $user = User::create([
            'name' => $nombre,
            'email' => 'traspaso-'.$n.'-'.uniqid().'@ironbody.test',
            'password' => 'secret-password',
            'document' => (string) (52000000 + $n),
            'status' => 'active',
        ]);
        if ($conFicha) {
            Member::create(array_merge([
                'user_id' => $user->id,
                'full_name' => $nombre,
                'document_number' => (string) (52000000 + $n),
                'access_hash' => 'tok-traspaso-'.$n.'-'.uniqid(),
                'status' => Member::STATUS_ACTIVE,
            ], $ficha));
        }

        return $user->refresh();
    }

    /** Cobra un plan por la caja real, como el mostrador. */
    protected function cobrar(User $socio, Plan $plan, array $extra = []): Payment
    {
        $id = $this->postJson('/api/payments', array_merge([
            'user_id' => $socio->id, 'plan_id' => $plan->id, 'amount' => $plan->price,
            'method' => 'cash', 'status' => 'paid',
        ], $extra), $this->h())->assertStatus(201)->json('id');

        return Payment::findOrFail($id);
    }

    /** La titular de siempre: anualidad cobrada el 1 de septiembre y reloj en el día del traspaso. */
    protected function titularConAnualidad(string $nombre = 'Ana Titular'): array
    {
        $a = $this->socio($nombre);
        $cobro = $this->cobrar($a, $this->anualidad);
        $this->diaDelTraspaso();

        return [$a->refresh(), $cobro->refresh()];
    }

    // ── HTTP ────────────────────────────────────────────────────────────────

    protected function previa(User $a, ?User $b = null, ?array $h = null): TestResponse
    {
        $q = $b === null ? '' : '?beneficiary_id='.$b->id;

        return $this->getJson("/api/users/{$a->id}/membership-transfer/preview{$q}", $h ?? $this->h());
    }

    /** @param  array<string, mixed>  $extra */
    protected function transferir(User $a, User $b, array $extra = [], ?array $h = null): TestResponse
    {
        return $this->postJson("/api/users/{$a->id}/membership-transfer", array_merge([
            'beneficiary_id' => $b->id,
            'reason' => 'Se muda de ciudad y cede su anualidad a su hermana',
            'holder_consent' => true,
            'beneficiary_acceptance' => true,
            'evidence' => 'Formato firmado n.º 123',
            'client_request_id' => 'tr-'.uniqid('', true),
        ], $extra), $h ?? $this->h());
    }

    protected function revertir(int $id, string $motivo = 'Se eligió al beneficiario equivocado', ?array $h = null): TestResponse
    {
        return $this->postJson("/api/membership-transfers/{$id}/revert", ['reason' => $motivo], $h ?? $this->h());
    }

    /** @return list<string> */
    protected function codigos(TestResponse $r, string $clave = 'blockers'): array
    {
        return array_column($r->json($clave) ?? [], 'code');
    }

    // ── Fotos de la base ────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    protected function tabla(string $tabla): array
    {
        return DB::table($tabla)->orderBy('id')->get()->map(fn ($fila): array => (array) $fila)->all();
    }

    /**
     * Todo lo que el traspaso NO puede tocar: dinero, documentos, asistencia,
     * rostro. Se compara entero, no por las columnas que uno espera.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    protected function fotoIntocable(): array
    {
        $tablas = [
            'payments', 'payment_splits', 'receivables', 'receivable_payments', 'electronic_invoices',
            'receipts', 'receipt_sources', 'cash_refunds', 'cash_shifts', 'attendances', 'class_reservations',
            'member_biometrics', 'members', 'membership_adjustments', 'plans',
        ];

        return array_combine($tablas, array_map(fn (string $t): array => $this->tabla($t), $tablas));
    }
}

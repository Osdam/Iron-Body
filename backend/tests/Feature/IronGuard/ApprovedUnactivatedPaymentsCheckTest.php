<?php

namespace Tests\Feature\IronGuard;

use App\Models\Incident;
use App\Models\IncidentEvent;
use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Services\IronGuard\UnactivatedApprovedPayments;
use App\Services\Payments\PaymentMembershipActivator;
use App\Support\Caja\PaymentOrigin;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * El aviso de «cobrado sin servicio» (`payments:approved-unactivated-check`):
 * pagos de pasarela aprobados que se quedaron sin su membresía.
 *
 * Todo sobre las clases reales (la consulta, la orden, IncidentRecorder y el
 * programador de routes/console.php). Los casos son las clases de
 * `docs/ultron-studio/ops/pagos-aprobados-sin-activar.sql`: avisan las
 * `FALLO_*`; no avisan las `NORMAL_*`, `REVISAR_*` ni `OK_ACTIVADO`. Y el
 * criterio se ata al escritor real: lo que deja el activador cuando acaba bien
 * no avisa.
 *
 * Corre igual en SQLite y en PostgreSQL 14 (docs/ultron-studio/ops/pgunit.sh).
 * Relojes y fechas en UTC, como en producción.
 */
class ApprovedUnactivatedPaymentsCheckTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-22 15:00:00';

    /** Datos personales sembrados en las fichas: ninguno puede salir en el aviso. */
    private const PII = [
        'Valentina Ospina Rojas', 'valentina.ospina@example.com', '3157654321',
        '1075123456', 'Carrera 5 # 10-20', 'Pagadora Ospina', 'pagadora@example.com', '3009998877', '55667788',
    ];

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();                  // ninguna llamada real sale de aquí
        Http::preventStrayRequests();  // y si alguien lo intenta, revienta

        config([
            'billing.enabled' => false,
            'observability.approved_unactivated.enabled' => true,
            'observability.approved_unactivated.window_days' => 7,
            'observability.approved_unactivated.grace_minutes' => 5,
            'observability.incidents.grouping_window_minutes' => 60,
        ]);

        $this->travelTo(CarbonImmutable::parse(self::NOW, 'UTC'));
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function plan(int $days = 30): Plan
    {
        return Plan::create([
            'name' => 'Mensual '.(++$this->seq), 'price' => 80000, 'duration_days' => $days,
            'benefits' => '', 'active' => true,
        ]);
    }

    private function socio(): User
    {
        return User::create([
            'name' => 'Valentina Ospina Rojas',
            'email' => 'valentina.ospina+'.(++$this->seq).'@example.com',
            'password' => bcrypt('secret-password'),
            'document' => '1075123456'.(++$this->seq),
            'phone' => '3157654321',
            'address' => 'Carrera 5 # 10-20',
            'status' => 'active',
        ]);
    }

    private function ficha(?User $u): Member
    {
        return Member::create([
            'user_id' => $u?->id,
            'full_name' => 'Valentina Ospina Rojas',
            'email' => 'valentina.ospina@example.com',
            'phone' => '3157654321',
            'document_number' => '1075123456'.(++$this->seq),
            'is_staff' => false,
        ]);
    }

    /** Una transacción de pasarela aprobada hace `$minutesAgo` minutos (fechas en UTC, como las escribe `now()`). */
    private function aprobada(?User $u, ?Plan $p, int $minutesAgo = 30, array $extra = []): PaymentTransaction
    {
        $ref = 'IRON-20261122-AVISO'.(++$this->seq);
        $tx = PaymentTransaction::create(array_merge([
            'reference' => $ref,
            'idempotency_key' => 'idem-'.$ref,
            'user_id' => $u?->id,
            'plan_id' => $p?->id,
            'amount' => 80000,
            'currency' => 'COP',
            'status' => 'approved',
            'provider' => 'wompi',
            'customer_email' => 'pagadora@example.com',
            'customer_phone' => '3009998877',
            'customer_legal_id' => '55667788',
            'customer' => ['name' => 'Pagadora Ospina', 'email' => 'pagadora@example.com'],
        ], $extra));
        $at = now()->subMinutes($minutesAgo);
        $tx->forceFill(['approved_at' => $at, 'paid_at' => $at])->save();

        return $tx;
    }

    /** La fila que escribe el activador (origen `gateway`), con su periodo salvo que se diga otra cosa. */
    private function fila(PaymentTransaction $tx, array $extra = []): Payment
    {
        $p = Payment::create(array_merge([
            'user_id' => $tx->user_id,
            'member_id' => $tx->member_id,
            'plan_id' => $tx->plan_id,
            'amount' => 80000,
            'method' => 'wompi',
            'status' => 'paid',
            'paid_at' => $tx->paid_at,
            'reference' => $tx->reference,
            'origin' => PaymentOrigin::GATEWAY->value,
            'period_start' => '2026-11-22',
            'period_end' => '2026-12-21',
        ], $extra));

        return $p;
    }

    /** @return list<array<string,mixed>> */
    private function hallados(): array
    {
        return app(UnactivatedApprovedPayments::class)->find()['payments'];
    }

    /** @return array<int,string> id de transacción => clase */
    private function clases(): array
    {
        return array_column($this->hallados(), 'class', 'transaction_id');
    }

    // ── Lo que SÍ avisa (FALLO_*) ────────────────────────────────────────────

    public function test_aprobado_con_plan_y_usuario_y_sin_fila_es_cobrado_sin_servicio(): void
    {
        $tx = $this->aprobada($this->socio(), $this->plan());

        $this->assertSame([$tx->id => UnactivatedApprovedPayments::CLASS_NO_PAYMENT_WITH_PLAN], $this->clases());
    }

    public function test_aprobado_sin_plan_y_sin_fila_tambien_avisa_como_en_la_consulta(): void
    {
        // FALLO_SIN_REGISTRO_DE_PAGO: con usuario el activador SIEMPRE crea la fila.
        $tx = $this->aprobada($this->socio(), null);

        $this->assertSame([$tx->id => UnactivatedApprovedPayments::CLASS_NO_PAYMENT], $this->clases());
    }

    public function test_fila_pagada_con_plan_y_sin_periodo_avisa(): void
    {
        // Un cobro anterior con periodo fija el corte antes de estas filas.
        $previo = $this->aprobada($this->socio(), $this->plan(), minutesAgo: 60 * 24 * 30);
        $this->fila($previo)->forceFill(['created_at' => now()->subDays(30)])->save();

        $sinNada = $this->aprobada($this->socio(), $this->plan());
        $this->fila($sinNada, ['period_start' => null, 'period_end' => null]);
        $sinFin = $this->aprobada($this->socio(), $this->plan());
        $this->fila($sinFin, ['period_end' => null]);

        $this->assertSame([
            $sinNada->id => UnactivatedApprovedPayments::CLASS_NO_PERIOD,
            $sinFin->id => UnactivatedApprovedPayments::CLASS_NO_PERIOD,
        ], $this->clases());
    }

    public function test_el_usuario_se_resuelve_por_la_ficha_del_socio(): void
    {
        // Sin user_id en la transacción: el activador usa el de la ficha.
        $u = $this->socio();
        $tx = $this->aprobada(null, $this->plan(), extra: ['member_id' => $this->ficha($u)->id]);

        $this->assertSame([$tx->id => UnactivatedApprovedPayments::CLASS_NO_PAYMENT_WITH_PLAN], $this->clases());
    }

    public function test_una_fila_de_caja_con_la_misma_referencia_no_cuenta_como_activacion(): void
    {
        $tx = $this->aprobada($this->socio(), $this->plan());
        // Cobro de MOSTRADOR con la misma referencia, pagado y con periodo: no es la activación.
        $this->fila($tx, ['origin' => PaymentOrigin::COUNTER->value, 'method' => 'cash']);

        $this->assertSame([$tx->id => UnactivatedApprovedPayments::CLASS_NO_PAYMENT_WITH_PLAN], $this->clases());
    }

    // ── Lo que NO avisa ──────────────────────────────────────────────────────

    public function test_con_fila_y_periodo_no_avisa(): void
    {
        $this->fila($this->aprobada($this->socio(), $this->plan()));

        $this->assertSame([], $this->hallados());
    }

    public function test_lo_que_deja_el_activador_real_no_avisa(): void
    {
        // El escritor de verdad: si el criterio no reconociera lo que escribe
        // el activador cuando acaba bien, avisaría de cada pago sano.
        $u = $this->socio();
        $tx = $this->aprobada($u, $this->plan(), extra: ['member_id' => $this->ficha($u)->id]);
        app(PaymentMembershipActivator::class)->activate($tx->fresh(), 'wompi');

        $this->assertSame(1, Payment::where('reference', $tx->reference)->where('origin', PaymentOrigin::GATEWAY->value)->count(), 'el activador dejó su fila');
        $this->assertSame([], $this->hallados());
    }

    public function test_sin_usuario_resuelto_no_avisa(): void
    {
        // NORMAL_SIN_USUARIO: ni en la transacción ni en la ficha (el flujo de enlace y reclamo).
        $this->aprobada(null, $this->plan());
        $this->aprobada(null, $this->plan(), extra: ['member_id' => $this->ficha(null)->id]);

        $this->assertSame([], $this->hallados());
    }

    public function test_fuera_de_la_ventana_no_avisa(): void
    {
        $this->aprobada($this->socio(), $this->plan(), minutesAgo: 60 * 24 * 7 + 1);

        $this->assertSame([], $this->hallados());
    }

    public function test_aprobado_hace_menos_de_la_gracia_no_avisa_todavia(): void
    {
        $tx = $this->aprobada($this->socio(), $this->plan(), minutesAgo: 2);
        $this->assertSame([], $this->hallados(), 'puede estar activándose');

        $this->travel(3)->minutes(); // ya lleva 5: la gracia se cumplió
        $this->assertSame([$tx->id => UnactivatedApprovedPayments::CLASS_NO_PAYMENT_WITH_PLAN], $this->clases());
    }

    public function test_los_casos_normales_y_a_revisar_de_la_consulta_no_avisan(): void
    {
        $this->fila($this->aprobada($this->socio(), $this->plan()), ['status' => 'cancelled', 'period_start' => null, 'period_end' => null]); // NORMAL_PAGO_REVERTIDO
        $this->fila($this->aprobada($this->socio(), null), ['period_start' => null, 'period_end' => null]);                                  // NORMAL_SIN_PLAN
        $this->fila($this->aprobada($this->socio(), $this->plan(0)), ['period_start' => null, 'period_end' => null]);                       // REVISAR_PLAN_SIN_DIAS
        foreach (['pending', 'declined', 'voided', 'error'] as $estado) {                                                                     // no aprobadas
            $this->aprobada($this->socio(), $this->plan(), extra: ['status' => $estado]);
        }

        $this->assertSame([], $this->hallados());
    }

    public function test_una_fila_sin_periodo_anterior_al_corte_no_avisa(): void
    {
        // NORMAL_ANTERIOR_AL_PERIODO, solo alcanzable ensanchando la ventana:
        // el corte es la primera fila con periodo, y lo de antes no podía tenerlo.
        config(['observability.approved_unactivated.window_days' => 120]);

        $antigua = $this->aprobada($this->socio(), $this->plan(), minutesAgo: 60 * 24 * 100);
        $this->fila($antigua, ['period_start' => null, 'period_end' => null])->forceFill(['created_at' => now()->subDays(100)])->save();
        $conPeriodo = $this->aprobada($this->socio(), $this->plan(), minutesAgo: 60 * 24 * 60);
        $this->fila($conPeriodo)->forceFill(['created_at' => now()->subDays(60)])->save();
        $posterior = $this->aprobada($this->socio(), $this->plan(), minutesAgo: 60 * 24 * 20);
        $this->fila($posterior, ['period_start' => null, 'period_end' => null])->forceFill(['created_at' => now()->subDays(20)])->save();

        $this->assertSame([$posterior->id => UnactivatedApprovedPayments::CLASS_NO_PERIOD], $this->clases());
    }

    // ── El incidente y el log ────────────────────────────────────────────────

    public function test_avisa_con_un_incidente_critico_y_un_log_error(): void
    {
        Log::spy();
        $tx = $this->aprobada($this->socio(), $this->plan());

        $this->artisan('payments:approved-unactivated-check')->assertExitCode(1);

        $i = Incident::sole();
        $this->assertSame(UnactivatedApprovedPayments::SOURCE, $i->source);
        $this->assertSame(UnactivatedApprovedPayments::KIND, $i->kind);
        $this->assertSame(Incident::SEVERITY_CRITICAL, $i->severity);
        $this->assertSame(1, (int) $i->occurrences);
        $this->assertSame(1, data_get($i->evidence, 'count'));
        $this->assertSame([$tx->id], data_get($i->evidence, 'transaction_ids'));
        $this->assertSame([$tx->reference], data_get($i->evidence, 'references'));

        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $msg, array $ctx): bool => $msg === 'payments.approved_unactivated'
                && $ctx['count'] === 1 && $ctx['transaction_ids'] === [$tx->id]
        );
    }

    public function test_un_log_que_no_se_puede_escribir_no_impide_el_incidente(): void
    {
        // Como el fichero del día en propiedad de otro usuario (ChannelLog::write()).
        $this->aprobada($this->socio(), $this->plan());
        Log::spy();
        Log::shouldReceive('error')->andThrow(new \UnexpectedValueException('The stream or file could not be opened'));

        $this->artisan('payments:approved-unactivated-check')
            ->expectsOutputToContain('NO se pudo escribir el Log::error')
            ->assertExitCode(1);

        $this->assertSame(Incident::SEVERITY_CRITICAL, Incident::where('kind', UnactivatedApprovedPayments::KIND)->sole()->severity);
    }

    public function test_dos_pasadas_sobre_los_mismos_pagos_son_un_incidente_con_dos_ocurrencias(): void
    {
        $this->aprobada($this->socio(), $this->plan());

        $this->artisan('payments:approved-unactivated-check')->assertExitCode(1);
        $this->travel(30)->minutes(); // la cadencia del programador
        $this->artisan('payments:approved-unactivated-check')->assertExitCode(1);

        $i = Incident::where('kind', UnactivatedApprovedPayments::KIND)->sole();
        $this->assertSame(2, (int) $i->occurrences);
        $this->assertTrue($i->isOpen());
    }

    public function test_la_evidencia_no_lleva_datos_personales(): void
    {
        Log::spy();
        $u = $this->socio();
        $this->aprobada($u, $this->plan(), extra: ['member_id' => $this->ficha($u)->id, 'description' => 'Plan de Valentina Ospina Rojas']);
        $this->aprobada(null, $this->plan(), extra: ['member_id' => $this->ficha($this->socio())->id]);

        $this->artisan('payments:approved-unactivated-check')->assertExitCode(1);

        $i = Incident::sole();
        $this->assertSame(
            ['by_class', 'check_command', 'count', 'diagnostic_query', 'grace_minutes', 'oldest_at', 'references', 'runbook', 'transaction_ids', 'window_days'],
            collect(array_keys($i->evidence))->sort()->values()->all(),
            'la evidencia solo lleva recuentos, ids, referencias y fechas',
        );
        $this->assertSame(2, $i->evidence['count']);
        $this->assertSame(now()->subMinutes(30)->toIso8601String(), $i->evidence['oldest_at']);

        $logs = [];
        Log::shouldHaveReceived('error')->withArgs(function (string $msg, array $ctx = []) use (&$logs): bool {
            $logs[] = [$msg, $ctx];

            return true;
        });
        $volcado = json_encode([$i->title, $i->evidence, $i->correlation_ids, $logs, IncidentEvent::all()->toArray()], JSON_UNESCAPED_UNICODE);
        foreach (self::PII as $dato) {
            $this->assertStringNotContainsString($dato, (string) $volcado, 'dato personal en el aviso');
        }
        $this->assertStringNotContainsString('80000', (string) $volcado, 'ni importes');
    }

    // ── La bandera, --dry-run y el programador ───────────────────────────────

    public function test_con_la_bandera_apagada_la_orden_no_lee_ni_escribe(): void
    {
        config(['observability.approved_unactivated.enabled' => false]);
        $this->aprobada($this->socio(), $this->plan());
        Log::spy();

        $consultas = [];
        DB::listen(function ($q) use (&$consultas): void {
            $consultas[] = $q->sql;
        });
        $this->artisan('payments:approved-unactivated-check')
            ->expectsOutputToContain('PAYMENTS_UNACTIVATED_CHECK_ENABLED=false')
            ->assertExitCode(0);

        $this->assertSame([], $consultas, 'apagada no consulta nada');
        $this->assertSame(0, Incident::count());
        Log::shouldNotHaveReceived('error');
    }

    public function test_dry_run_informa_y_no_escribe_nada_con_la_bandera_apagada_o_encendida(): void
    {
        $tx = $this->aprobada($this->socio(), $this->plan());
        $antes = [PaymentTransaction::count(), Payment::count(), User::count(), Member::count()];
        $txAntes = $tx->fresh()->toArray();
        Log::spy();

        foreach ([false, true] as $bandera) {
            config(['observability.approved_unactivated.enabled' => $bandera]);
            $escrituras = [];
            DB::listen(function ($q) use (&$escrituras): void {
                if (! preg_match('/^\s*select\b/i', $q->sql)) {
                    $escrituras[] = $q->sql;
                }
            });

            $this->artisan('payments:approved-unactivated-check --dry-run')
                ->expectsOutputToContain('en seco')
                ->expectsOutputToContain($tx->reference)
                ->assertExitCode(1);

            $this->assertSame([], $escrituras, 'bandera '.($bandera ? 'on' : 'off').': --dry-run solo hace SELECT');
        }

        $this->assertSame(0, Incident::count());
        $this->assertSame(0, IncidentEvent::count());
        $this->assertSame($antes, [PaymentTransaction::count(), Payment::count(), User::count(), Member::count()]);
        $this->assertSame($txAntes, $tx->fresh()->toArray());
        Log::shouldNotHaveReceived('error');
    }

    public function test_json_en_seco(): void
    {
        $tx = $this->aprobada($this->socio(), $this->plan());

        $this->assertSame(1, Artisan::call('payments:approved-unactivated-check', ['--dry-run' => true, '--json' => true]));
        $salida = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($salida['dry_run']);
        $this->assertTrue($salida['ran']);
        $this->assertSame(1, $salida['count']);
        $this->assertSame($tx->id, $salida['payments'][0]['transaction_id']);
        $this->assertSame(UnactivatedApprovedPayments::CLASS_NO_PAYMENT_WITH_PLAN, $salida['payments'][0]['class']);
        $this->assertNull($salida['incident']);
    }

    public function test_sin_nada_que_avisar_sale_cero_y_no_abre_incidente(): void
    {
        $this->fila($this->aprobada($this->socio(), $this->plan()));
        Log::spy();

        $this->artisan('payments:approved-unactivated-check')
            ->expectsOutputToContain('Ninguno sin su membresía')
            ->assertExitCode(0);

        $this->assertSame(0, Incident::count());
        Log::shouldNotHaveReceived('error');
    }

    /** @return list<Event> lo que programa routes/console.php con la configuración de ahora */
    private function programadas(): array
    {
        $this->app->instance(Schedule::class, new Schedule);
        Facade::clearResolvedInstances();
        require base_path('routes/console.php');

        return array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            static fn (Event $e): bool => str_contains((string) $e->command, 'payments:approved-unactivated-check'),
        ));
    }

    public function test_con_la_bandera_apagada_el_programador_no_la_agenda(): void
    {
        config(['observability.approved_unactivated.enabled' => false]);
        $this->assertSame([], $this->programadas());

        // Y no depende de IRON GUARD: encenderlo no la agenda.
        config(['observability.enabled' => true]);
        $this->assertSame([], $this->programadas());
    }

    public function test_con_la_bandera_encendida_se_agenda_cada_media_hora_sin_solaparse(): void
    {
        config(['observability.approved_unactivated.enabled' => true, 'observability.enabled' => false]);
        $eventos = $this->programadas();

        $this->assertCount(1, $eventos, 'una sola entrada, aunque IRON GUARD esté apagado');
        $e = $eventos[0];
        $this->assertStringEndsWith("'artisan' payments:approved-unactivated-check", (string) $e->command, 'sin argumentos: ni --dry-run ni otros');
        // Dentro de la ventana de agrupación de 60 minutos: cada pasada suma al mismo incidente.
        $this->assertSame('*/30 * * * *', $e->expression);
        $this->assertTrue($e->withoutOverlapping);
        $this->assertSame(10, $e->expiresAt, 'diez minutos de cerrojo, no las 24 h por defecto');
        $this->assertTrue($e->onOneServer);
    }
}

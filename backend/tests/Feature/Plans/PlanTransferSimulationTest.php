<?php

namespace Tests\Feature\Plans;

use App\Models\AuditLog;
use App\Models\ClassReservation;
use App\Models\MemberRealtimeEvent;
use App\Models\MembershipSubscription;
use App\Models\MyClass;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\PlanTransferBatch;
use App\Models\PlanTransferItem;
use App\Models\User;
use App\Services\Membership\PlanAccessRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LA SIMULACIÓN DEL TRASLADO: qué pasaría, sin escribir nada.
 *
 * Lo que se fija aquí es que la simulación es de verdad de solo lectura, que
 * da la misma huella si nada cambia, que cada bloqueo y cada aviso salen de
 * las clases que deciden en producción (sobre un clon del socio), y que las
 * peticiones imposibles se rechazan antes de mirar a nadie.
 */
class PlanTransferSimulationTest extends TestCase
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

    // ── Solo lectura ────────────────────────────────────────────────────────

    public function test_la_simulacion_no_escribe_nada_y_da_la_misma_huella_dos_veces(): void
    {
        $socios = [$this->socio(), $this->socio(), $this->socio()];
        $ids = array_map(fn (User $u): int => $u->id, $socios);
        $usuarios = $this->tabla('users');
        $externos = $this->fotoExterna();

        $sql = [];
        DB::listen(function ($consulta) use (&$sql): void {
            $sql[] = $consulta->sql;
        });

        $primera = $this->simular($ids)->assertOk()->json();
        $segunda = $this->simular($ids)->assertOk()->json();

        // Ni una escritura, tampoco de la puerta: todo es lectura.
        $escrituras = array_values(array_filter($sql, fn (string $s): bool => preg_match('/^\s*(insert|update|delete)\b/i', $s) === 1));
        $this->assertNotEmpty($sql, 'El listener no vio ninguna consulta: la prueba no estaría probando nada.');
        $this->assertSame([], $escrituras, 'La simulación escribió en la base.');

        $this->assertSame($primera['fingerprint'], $segunda['fingerprint']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $primera['fingerprint']);
        $this->assertSame($usuarios, $this->tabla('users'));
        $this->assertSame($externos, $this->fotoExterna());
        $this->assertSame(0, PlanTransferBatch::count());
        $this->assertSame(0, PlanTransferItem::count());
        $this->assertSame(0, MemberRealtimeEvent::count());
    }

    public function test_la_simulacion_tiene_el_contrato_publicado(): void
    {
        $socio = $this->socio();

        $r = $this->simular([$socio->id])->assertOk();

        $r->assertJsonStructure([
            'fingerprint', 'today',
            'from_plan' => ['id', 'name', 'active', 'sellable', 'visible_in_app', 'billable', 'price', 'duration_days',
                'tier', 'pricing_mode', 'tax_rate_id', 'access', 'features', 'classes' => ['feature', 'access_classes', 'reservations_limit'],
                'benefits', 'ai'],
            'to_plan',
            'differences', 'plan_warnings',
            'summary' => ['requested', 'eligible', 'blocked', 'with_warnings'],
            'requires_confirmation',
            'items' => [['user_id', 'name', 'document', 'eligible', 'situation',
                'before' => ['plan', 'plan_id', 'membership_start_date', 'membership_end_date', 'days_left', 'status', 'frozen',
                    'access' => ['mode', 'entries', 'used', 'left', 'days_label', 'hours_label', 'can_enter_now', 'reason'],
                    'features', 'ai_active'],
                'after', 'blockers', 'warnings']],
        ]);
        $r->assertJsonPath('today', '2026-10-15')
            ->assertJsonPath('from_plan.price', '180000.00')
            ->assertJsonPath('to_plan.name', 'Élite')
            ->assertJsonPath('items.0.eligible', true)
            ->assertJsonPath('items.0.situation', 'active')
            ->assertJsonPath('items.0.before.plan', 'TOTAL ACCESS - ELITE')
            ->assertJsonPath('items.0.after.plan', 'Élite')
            ->assertJsonPath('items.0.after.plan_id', $this->destino->id)
            ->assertJsonPath('items.0.before.plan_id', $this->origen->id)
            // Las fechas NO cambian: es la promesa del traslado.
            ->assertJsonPath('items.0.after.membership_start_date', $socio->membershipStartDate)
            ->assertJsonPath('items.0.after.membership_end_date', $socio->membershipEndDate)
            ->assertJsonPath('items.0.after.days_left', 20)
            ->assertJsonPath('requires_confirmation', false);
    }

    // ── Candidatos ──────────────────────────────────────────────────────────

    public function test_los_candidatos_son_los_del_plan_origen_con_sus_variantes_y_sus_bloqueos(): void
    {
        $vigente = $this->socio();
        $variante = $this->socio('Total Access - Élite');
        $vencido = $this->socio(null, [
            'membership_start_date' => $this->hoy()->subDays(40)->toDateString(),
            'membership_end_date' => $this->hoy()->subDays(10)->toDateString(),
        ]);
        $sinFechas = $this->socio(null, ['membership_start_date' => null, 'membership_end_date' => null], false);
        $this->socio($this->destino);
        $this->socio('Otro plan');

        $r = $this->getJson('/api/admin/plan-transfers/candidates?from_plan_id='.$this->origen->id.'&to_plan_id='.$this->destino->id, $this->h)
            ->assertOk()
            ->assertJsonPath('from_plan.name', 'TOTAL ACCESS - ELITE')
            ->assertJsonPath('to_plan.name', 'Élite')
            ->assertJsonPath('today', '2026-10-15')
            ->assertJsonPath('total', 4)
            ->assertJsonPath('truncated', false);

        $porId = collect($r->json('members'))->keyBy('user_id');
        $this->assertEqualsCanonicalizing([$vigente->id, $variante->id, $vencido->id, $sinFechas->id], $porId->keys()->all());

        $this->assertTrue($porId[$vigente->id]['eligible']);
        $this->assertTrue($porId[$vigente->id]['preselect']);
        $this->assertSame('active', $porId[$vigente->id]['situation']);

        // Una variante de escritura del mismo plan es del origen, y se avisa.
        $this->assertTrue($porId[$variante->id]['eligible']);
        $this->assertContains('plan_name_variant', $porId[$variante->id]['warning_codes']);

        // Lo vencido se puede trasladar, pero no se marca por defecto.
        $this->assertTrue($porId[$vencido->id]['eligible']);
        $this->assertFalse($porId[$vencido->id]['preselect']);
        $this->assertSame('expired', $porId[$vencido->id]['situation']);

        $this->assertFalse($porId[$sinFechas->id]['eligible']);
        $this->assertSame('no_membership', $porId[$sinFechas->id]['situation']);
        $this->assertSame('no_membership', $porId[$sinFechas->id]['blockers'][0]['code']);
    }

    // ── Bloqueos ────────────────────────────────────────────────────────────

    public function test_quien_ya_esta_en_el_destino_queda_bloqueado(): void
    {
        $ya = $this->socio($this->destino);

        $this->simular([$ya->id])->assertOk()
            ->assertJsonPath('items.0.eligible', false)
            ->assertJsonPath('items.0.blockers.0.code', 'already_in_destination')
            ->assertJsonPath('summary.eligible', 0)
            ->assertJsonPath('requires_confirmation', false);
    }

    public function test_un_socio_de_otro_plan_o_inexistente_queda_bloqueado(): void
    {
        $otro = $this->socio('Mensual');

        $r = $this->simular([$otro->id, 999999])->assertOk();
        $r->assertJsonPath('items.0.blockers.0.code', 'plan_mismatch')
            ->assertJsonPath('items.1.blockers.0.code', 'member_not_found')
            ->assertJsonPath('items.1.before', null)
            ->assertJsonPath('summary.blocked', 2);
    }

    public function test_cobro_pendiente_o_suscripcion_viva_del_origen_bloquean(): void
    {
        $pendiente = $this->socio();
        Payment::create(['user_id' => $pendiente->id, 'plan_id' => $this->origen->id, 'amount' => 180000,
            'method' => 'transfer', 'status' => 'pending']);
        $pasarela = $this->socio();
        PaymentTransaction::create(['reference' => 'IB-'.uniqid(), 'idempotency_key' => uniqid('k', true), 'user_id' => $pasarela->id,
            'plan_id' => $this->origen->id, 'amount' => 180000, 'currency' => 'COP', 'status' => 'pending', 'provider' => 'wompi']);
        $suscrito = $this->socio();
        MembershipSubscription::create(['uuid' => (string) Str::uuid(),
            'user_id' => $suscrito->id, 'member_id' => $suscrito->appMember->id, 'plan_id' => $this->origen->id,
            'status' => 'active', 'price_snapshot' => 180000]);

        $r = $this->simular([$pendiente->id, $pasarela->id, $suscrito->id])->assertOk();

        $r->assertJsonPath('items.0.blockers.0.code', 'pending_origin_payment')
            ->assertJsonPath('items.1.blockers.0.code', 'pending_origin_payment')
            ->assertJsonPath('items.2.blockers.0.code', 'live_subscription_origin')
            ->assertJsonPath('summary.eligible', 0);
    }

    // ── Entradas: la puerta decide, sobre el clon ───────────────────────────

    public function test_de_ilimitado_a_cinco_entradas_con_siete_usadas_queda_bloqueado(): void
    {
        $cinco = $this->plan('Valera 5', ['access_mode' => PlanAccessRules::MODE_ENTRIES, 'entry_credits' => 5]);
        $socio = $this->socio();
        $this->entradas($socio, 7);

        $this->simular([$socio->id], null, $cinco)->assertOk()
            ->assertJsonPath('items.0.eligible', false)
            ->assertJsonPath('items.0.blockers.0.code', 'entries_exhausted')
            ->assertJsonPath('items.0.after.access.used', 7)
            ->assertJsonPath('items.0.after.access.left', 0)
            ->assertJsonPath('items.0.after.access.can_enter_now', false)
            ->assertJsonPath('items.0.after.access.reason', 'no_entries')
            // Antes entraba: el bloqueo lo produce el plan destino.
            ->assertJsonPath('items.0.before.access.can_enter_now', true);
    }

    public function test_de_ilimitado_a_quince_entradas_le_quedan_ocho(): void
    {
        $quince = $this->plan('Valera 15', ['access_mode' => PlanAccessRules::MODE_ENTRIES, 'entry_credits' => 15]);
        $socio = $this->socio();
        $this->entradas($socio, 7);

        $r = $this->simular([$socio->id], null, $quince)->assertOk()
            ->assertJsonPath('items.0.eligible', true)
            ->assertJsonPath('items.0.after.access.entries', 15)
            ->assertJsonPath('items.0.after.access.left', 8)
            ->assertJsonPath('requires_confirmation', true);

        $aviso = collect($r->json('items.0.warnings'))->firstWhere('code', 'entries_counted');
        $this->assertNotNull($aviso);
        $this->assertSame('warning', $aviso['severity']);
        $this->assertStringContainsString('8 de 15', $aviso['message']);
    }

    public function test_de_valera_a_ilimitado_las_entradas_dejan_de_contar(): void
    {
        $valera = $this->plan('Valera', ['access_mode' => PlanAccessRules::MODE_ENTRIES, 'entry_credits' => 15,
            'sellable' => false, 'visible_in_app' => false]);
        $socio = $this->socio($valera);
        $this->entradas($socio, 7);

        $r = $this->simular([$socio->id], $valera, $this->destino)->assertOk()
            ->assertJsonPath('items.0.before.access.used', 7)
            ->assertJsonPath('items.0.before.access.entries', 15)
            ->assertJsonPath('items.0.after.access.mode', 'unlimited')
            ->assertJsonPath('items.0.after.access.used', null);

        $this->assertContains('stops_counting_entries', array_column($r->json('items.0.warnings'), 'code'));
    }

    // ── Avisos por socio ────────────────────────────────────────────────────

    public function test_restriccion_horaria_del_destino_se_avisa_y_obliga_a_confirmar(): void
    {
        $valle = $this->plan('Horas valle', ['access_windows' => [['from' => '14:00', 'to' => '17:00']]]);
        $socio = $this->socio();

        $r = $this->simular([$socio->id], null, $valle)->assertOk()
            ->assertJsonPath('items.0.after.access.hours_label', '14:00–17:00')
            ->assertJsonPath('items.0.after.access.can_enter_now', false)
            ->assertJsonPath('requires_confirmation', true);

        $this->assertContains('dest_restricts_hours', array_column($r->json('items.0.warnings'), 'code'));
        $this->assertContains('access_rules_differ', array_column($r->json('plan_warnings'), 'code'));
    }

    public function test_avisos_de_situacion_del_socio(): void
    {
        $futuro = $this->socio(null, [
            'membership_start_date' => $this->hoy()->addDays(5)->toDateString(),
            'membership_end_date' => $this->hoy()->addDays(35)->toDateString(),
        ], false);
        Payment::create(['user_id' => $futuro->id, 'plan_id' => $this->origen->id, 'amount' => 180000, 'method' => 'cash',
            'status' => 'paid', 'paid_at' => now(), 'starts_on' => $this->hoy()->addDays(5)->toDateString(),
            'period_start' => $this->hoy()->addDays(5)->toDateString(), 'period_end' => $this->hoy()->addDays(35)->toDateString()]);
        $inactivo = $this->socio(null, ['status' => 'inactive']);
        $cancela = $this->socio(null, ['membership_cancellation_requested_at' => now()]);
        $sinApp = $this->socio(null, [], true, false);

        $r = $this->simular([$futuro->id, $inactivo->id, $cancela->id, $sinApp->id])->assertOk();
        $codigos = fn (int $i): array => array_column($r->json("items.{$i}.warnings"), 'code');

        $this->assertSame('future', $r->json('items.0.situation'));
        $this->assertContains('future_start', $codigos(0));
        $this->assertContains('prepaid_origin_period', $codigos(0));
        $this->assertContains('account_inactive', $codigos(1));
        $this->assertContains('cancellation_requested', $codigos(2));
        $this->assertContains('no_app_account', $codigos(3));

        // Avisar de que no tiene app no obliga a confirmar nada.
        $info = collect($r->json('items.3.warnings'))->firstWhere('code', 'no_app_account');
        $this->assertSame('info', $info['severity']);
    }

    // ── Avisos de plan ──────────────────────────────────────────────────────

    public function test_diferencias_y_avisos_de_plan(): void
    {
        $vendible = $this->plan('Heredado vendible', ['price' => 150000, 'features' => ['classes' => true, 'nutrition' => true]]);
        $sinClases = $this->plan('Sin clases', ['price' => 180000, 'sellable' => false, 'visible_in_app' => false,
            'features' => ['classes' => false, 'nutrition' => true]]);
        $socio = $this->socio($vendible);

        $r = $this->simular([$socio->id], $vendible, $sinClases)->assertOk();
        $avisos = array_column($r->json('plan_warnings'), 'code');
        $diferencias = collect($r->json('differences'))->keyBy('key');

        foreach (['price_differs', 'features_lost', 'to_plan_not_sellable', 'to_plan_hidden_in_app', 'from_plan_still_sellable'] as $codigo) {
            $this->assertContains($codigo, $avisos);
        }
        $this->assertSame('loss', $diferencias['features.classes']['kind']);
        $this->assertSame(['150000.00', '180000.00'], [$diferencias['price']['from'], $diferencias['price']['to']]);
        $this->assertTrue($r->json('requires_confirmation'));
    }

    public function test_quitar_clases_con_reservas_por_delante_se_avisa(): void
    {
        $sinClases = $this->plan('Sin clases', ['features' => ['classes' => false]]);
        $socio = $this->socio();
        $clase = MyClass::create(['name' => 'Spinning', 'type' => 'Spinning', 'day_of_week' => 'Viernes',
            'start_time' => '07:00', 'end_time' => '08:00', 'status' => 'active', 'max_capacity' => 10]);
        ClassReservation::create(['class_id' => $clase->id, 'member_id' => $socio->appMember->id,
            'session_date' => $this->hoy()->addDay()->toDateString(), 'reserved_at' => now()]);

        $r = $this->simular([$socio->id], null, $sinClases)->assertOk();

        $this->assertContains('classes_lost_with_reservations', array_column($r->json('items.0.warnings'), 'code'));
    }

    // ── Rechazos de la petición ─────────────────────────────────────────────

    public function test_peticiones_imposibles_se_rechazan_antes_de_mirar_a_nadie(): void
    {
        $socio = $this->socio();
        $inactivo = $this->plan('Inactivo', ['active' => false]);
        $sinDuracion = $this->plan('Sin duración', ['duration_days' => 0]);

        $this->simular([$socio->id], $this->origen, $this->origen)->assertStatus(422)->assertJsonPath('code', 'same_plan');
        $this->simular([$socio->id], null, $inactivo)->assertStatus(422)->assertJsonPath('code', 'to_plan_inactive');
        $this->simular([$socio->id], null, $sinDuracion)->assertStatus(422)->assertJsonPath('code', 'to_plan_invalid_duration');

        $this->postJson('/api/admin/plan-transfers/dry-run', ['from_plan_id' => $this->origen->id, 'to_plan_id' => 999999,
            'user_ids' => [$socio->id]], $this->h)->assertStatus(422)->assertJsonValidationErrors('to_plan_id');
        $this->simular([$socio->id, $socio->id])->assertStatus(422)->assertJsonValidationErrors('user_ids.0');
        $this->simular(range(1, 301))->assertStatus(422)->assertJsonValidationErrors('user_ids');
        $this->simular([])->assertStatus(422)->assertJsonValidationErrors('user_ids');
    }

    public function test_dos_planes_que_solo_se_distinguen_por_tildes_no_admiten_traslado(): void
    {
        $socio = $this->socio();
        // «Elite» sin tilde normaliza igual que «Élite»: la puerta se quedaría
        // con uno de los dos al azar.
        $this->plan('Elite');

        $this->simular([$socio->id])->assertStatus(422)->assertJsonPath('code', 'ambiguous_plan_name');
        $this->getJson('/api/admin/plan-transfers/candidates?from_plan_id='.$this->origen->id.'&to_plan_id='.$this->destino->id, $this->h)
            ->assertStatus(422)->assertJsonPath('code', 'ambiguous_plan_name');
    }

    // ── La huella ───────────────────────────────────────────────────────────

    public function test_la_huella_ignora_el_orden_de_la_peticion(): void
    {
        $a = $this->socio();
        $b = $this->socio();

        $this->assertSame(
            $this->simular([$a->id, $b->id])->json('fingerprint'),
            $this->simular([$b->id, $a->id])->json('fingerprint'),
        );
    }

    public function test_el_evaluador_no_bloquea_con_agregados(): void
    {
        // PostgreSQL rechaza `FOR UPDATE` con agregados (500). Los máximos de
        // la huella se leen en consultas aparte, sin bloqueo.
        foreach (['Services/Membership/PlanTransfers.php', 'Services/Membership/PlanTransferEvaluator.php'] as $fichero) {
            $this->assertDoesNotMatchRegularExpression(
                '/lockForUpdate\(\)[^;]*->(count|max|min|sum|avg)\(/',
                file_get_contents(app_path($fichero)),
                $fichero.' combina lockForUpdate() con un agregado.',
            );
        }
    }

    public function test_simular_no_deja_traza_de_auditoria(): void
    {
        $socio = $this->socio();
        $this->simular([$socio->id])->assertOk();

        $this->assertSame(0, AuditLog::count());
        $this->assertSame('TOTAL ACCESS - ELITE', $socio->refresh()->plan);
    }
}

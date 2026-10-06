<?php

namespace Tests\Feature\Marketing\Attribution;

use App\Models\MarketingLead;
use App\Models\Payment;
use App\Services\Marketing\Attribution\ConversionLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El dinero que cuenta como conversión: solo el cobrado (CashReceipts y los
 * estados pagados), solo de planes y membresías, dentro de la ventana, y una
 * sola vez por persona.
 *
 * Reloj: 5 de octubre, 10:00 en Bogotá. Ventana por defecto, 30 días.
 */
class ConversionLedgerTest extends TestCase
{
    use RefreshDatabase, SeedsAttribution;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 15:00:00');
        config()->set('marketing.attribution.window_days', 30);
        // Las pruebas dan de alta socios a mitad de prueba: sin el índice de
        // teléfonos en caché (tiene su propia prueba en MetaDashboardCacheTest).
        config()->set('marketing.attribution.phone_index_cache_seconds', 0);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function ledger(MarketingLead ...$leads): Collection
    {
        return app(ConversionLedger::class)->forLeads(collect($leads));
    }

    /** Contrato 9: un cobro válido después del primer contacto es un cliente nuevo. */
    public function test_conversion_por_cobro_valido(): void
    {
        [$user] = $this->customer('3001112233');
        $lead = $this->lead('2026-09-20 15:00:00', ['phone' => '573001112233']);
        $pago = $this->payment($user, 120000, '2026-09-22 20:00:00');

        $r = $this->ledger($lead)->get($lead->id);

        $this->assertSame('new', $r['kind']);
        $this->assertSame($user->id, $r['user_id']);
        $this->assertSame(120000.0, $r['revenue']);
        $this->assertSame('2026-09-20T15:00:00+00:00', $r['first_contact_at']);
        $this->assertSame('2026-10-05T15:00:00+00:00', $r['window_end'], 'la ventana no pasa de ahora');
        $this->assertSame([[
            'type' => 'payment', 'id' => $pago->id, 'amount' => 120000.0, 'at' => '2026-09-22T20:00:00+00:00',
        ]], $r['payments']);
        $this->assertNull($r['duplicate_of']);
    }

    /**
     * Contrato 10: el plan a plazos crea un pago por el total con
     * `method = receivable`. Eso es el contrato, no dinero: solo cuentan sus
     * abonos aplicados.
     */
    public function test_la_deuda_sin_cobrar_no_es_ingreso(): void
    {
        [$user, $member] = $this->customer('3001112233');
        $lead = $this->lead('2026-09-20 15:00:00', ['phone' => '573001112233']);

        // Venta a plazos de 300.000: asiento de devengo + primer abono de 100.000.
        $this->payment($user, 300000, '2026-09-21 15:00:00', ['method' => 'receivable']);
        $abono = $this->installment($member, 100000, '2026-09-21 15:05:00');

        $r = $this->ledger($lead)->get($lead->id);

        $this->assertSame('new', $r['kind']);
        $this->assertSame(100000.0, $r['revenue']);
        $this->assertSame([['type' => 'installment', 'id' => $abono->id, 'amount' => 100000.0, 'at' => '2026-09-21T15:05:00+00:00']], $r['payments']);

        // Solo la deuda, sin ningún abono: no hay conversión.
        [$user2] = $this->customer('3004445566');
        $soloDeuda = $this->lead('2026-09-20 16:00:00', ['phone' => '573004445566']);
        $this->payment($user2, 300000, '2026-09-21 15:00:00', ['method' => 'Receivable']);

        $r = $this->ledger($soloDeuda)->get($soloDeuda->id);
        $this->assertSame('none', $r['kind']);
        $this->assertSame(0.0, $r['revenue']);
    }

    /** Contrato 11: anular un pago o revertir un abono saca el ingreso. */
    public function test_la_reversion_saca_el_ingreso(): void
    {
        [$user, $member] = $this->customer('3001112233');
        $lead = $this->lead('2026-09-20 15:00:00', ['phone' => '573001112233']);
        $pago = $this->payment($user, 120000, '2026-09-22 15:00:00');
        $abono = $this->installment($member, 50000, '2026-09-23 15:00:00');

        $this->assertSame(170000.0, $this->ledger($lead)->get($lead->id)['revenue']);

        $pago->forceFill(['status' => 'cancelled'])->save();
        $this->assertSame(50000.0, $this->ledger($lead)->get($lead->id)['revenue']);

        $abono->forceFill(['status' => 'reversed', 'reversed_at' => '2026-09-24 15:00:00'])->save();
        $r = $this->ledger($lead)->get($lead->id);
        $this->assertSame('none', $r['kind']);
        $this->assertSame(0.0, $r['revenue']);
        $this->assertSame([], $r['payments']);
    }

    /** Los estados pagados del proyecto, en cualquier grafía; pendientes y fallidos no. */
    public function test_solo_cuentan_los_estados_pagados(): void
    {
        [$user] = $this->customer('3001112233');
        $lead = $this->lead('2026-09-20 15:00:00', ['phone' => '573001112233']);
        $this->payment($user, 10000, '2026-09-21 15:00:00', ['status' => 'Pagado']);
        $this->payment($user, 20000, '2026-09-21 16:00:00', ['status' => 'APPROVED']);
        $this->payment($user, 40000, '2026-09-21 17:00:00', ['status' => 'pending']);
        $this->payment($user, 80000, '2026-09-21 18:00:00', ['status' => 'failed']);
        // Sin paid_at, vale la fecha de creación.
        $this->payment($user, 5000, '2026-09-22 15:00:00', ['paid_at' => null]);

        $this->assertSame(35000.0, $this->ledger($lead)->get($lead->id)['revenue']);
    }

    /**
     * Un «cobro» de $0 en estado pagado (plan de cortesía, ingreso de empleado,
     * pago heredado sin importe) no es dinero: dentro de la ventana no convierte,
     * y antes del contacto no hace «cliente previo» a quien paga por primera vez.
     */
    public function test_un_cobro_de_cero_no_convierte_ni_hace_cliente_previo(): void
    {
        // Dentro de la ventana, solo un cobro de 0: nadie pagó.
        [$cortesia] = $this->customer('3001112233');
        $l1 = $this->lead('2026-09-20 15:00:00', ['phone' => '573001112233']);
        $this->payment($cortesia, 0, '2026-09-21 15:00:00');

        // Una cortesía heredada de 0 ANTES de escribir, y su primer pago real después.
        [$nuevo] = $this->customer('3004445566');
        $this->payment($nuevo, 0, '2026-06-01 15:00:00');
        $l2 = $this->lead('2026-09-20 16:00:00', ['phone' => '573004445566']);
        $pago = $this->payment($nuevo, 80000, '2026-09-22 15:00:00');

        $ledger = $this->ledger($l1, $l2);

        $this->assertSame('none', $ledger->get($l1->id)['kind'], 'un cobro de $0 no es una conversión');
        $this->assertSame(0.0, $ledger->get($l1->id)['revenue']);
        $this->assertSame([], $ledger->get($l1->id)['payments']);

        $this->assertSame('new', $ledger->get($l2->id)['kind'], 'una cortesía de $0 no lo hace cliente previo');
        $this->assertSame(80000.0, $ledger->get($l2->id)['revenue']);
        $this->assertSame([$pago->id], array_column($ledger->get($l2->id)['payments'], 'id'));
    }

    /** La tienda y la cafetería quedan fuera, también sus fiados (D2). */
    public function test_los_abonos_de_cafeteria_no_cuentan(): void
    {
        [, $member] = $this->customer('3001112233');
        $lead = $this->lead('2026-09-20 15:00:00', ['phone' => '573001112233']);
        $this->installment($member, 15000, '2026-09-21 15:00:00', type: 'products');

        $r = $this->ledger($lead)->get($lead->id);
        $this->assertSame('none', $r['kind']);
        $this->assertSame(0.0, $r['revenue']);
    }

    /** D1: solo dentro de [primer contacto, primer contacto + ventana]. */
    public function test_la_ventana_de_atribucion(): void
    {
        config()->set('marketing.attribution.window_days', 10);
        [$user] = $this->customer('3001112233');
        $lead = $this->lead('2026-09-01 15:00:00', ['phone' => '573001112233']);

        $this->payment($user, 100, '2026-09-01 15:00:00');   // en el instante del contacto: dentro
        $this->payment($user, 200, '2026-09-11 15:00:00');   // justo al cerrar la ventana: dentro
        $this->payment($user, 400, '2026-09-11 15:00:01');   // un segundo después: fuera

        $r = $this->ledger($lead)->get($lead->id);
        $this->assertSame('2026-09-11T15:00:00+00:00', $r['window_end']);
        $this->assertSame(300.0, $r['revenue']);
        $this->assertSame('new', $r['kind']);
    }

    /**
     * D1 con varias personas: la ventana es la de cada lead. La consulta en lote
     * trae los cobros del rango de TODOS los leads; sin el corte por lead, un
     * cobro de Ana posterior a su ventana entraría por caer dentro de la de Beto.
     */
    public function test_la_ventana_es_de_cada_lead_aunque_se_consulten_juntos(): void
    {
        config()->set('marketing.attribution.window_days', 10);
        [$ana] = $this->customer('3001112233');
        [$beto] = $this->customer('3002223344');
        $leadAna = $this->lead('2026-09-01 15:00:00', ['phone' => '573001112233']);
        $leadBeto = $this->lead('2026-09-20 15:00:00', ['phone' => '573002223344']);

        $this->payment($ana, 100, '2026-09-05 15:00:00');   // dentro de la ventana de Ana
        $this->payment($ana, 400, '2026-09-25 15:00:00');   // fuera de la de Ana, dentro de la de Beto
        $this->payment($beto, 50, '2026-09-21 15:00:00');

        $r = $this->ledger($leadAna, $leadBeto);
        $this->assertSame(100.0, $r->get($leadAna->id)['revenue']);
        $this->assertSame(50.0, $r->get($leadBeto->id)['revenue']);
    }

    /** D4: quien ya había pagado antes de escribir es una renovación, no un cliente nuevo. */
    public function test_renovacion_frente_a_cliente_nuevo(): void
    {
        [$user] = $this->customer('3001112233');
        $this->payment($user, 90000, '2026-06-10 15:00:00');
        $lead = $this->lead('2026-09-20 15:00:00', ['phone' => '573001112233']);
        $this->payment($user, 95000, '2026-09-25 15:00:00');

        $r = $this->ledger($lead)->get($lead->id);
        $this->assertSame('renewal', $r['kind']);
        $this->assertSame(95000.0, $r['revenue'], 'el pago anterior al contacto no se atribuye');

        // Un abono aplicado antes del contacto también lo hace cliente previo.
        [, $member] = $this->customer('3004445566');
        $this->installment($member, 50000, '2026-08-01 15:00:00');
        $otro = $this->lead('2026-09-20 16:00:00', ['phone' => '573004445566']);
        $this->installment($member, 50000, '2026-09-21 15:00:00');

        $this->assertSame('renewal', $this->ledger($otro)->get($otro->id)['kind']);
    }

    public function test_sin_vinculo_o_ambiguo_no_se_atribuye_nada(): void
    {
        // Nadie con ese número.
        $suelto = $this->lead('2026-09-20 15:00:00', ['phone' => '573009990000']);
        // Dos personas con el mismo número, y las dos pagaron.
        [$a] = $this->customer('3001112233');
        [$b] = $this->customer('3001112233');
        $this->payment($a, 100000, '2026-09-22 15:00:00');
        $this->payment($b, 100000, '2026-09-22 15:00:00');
        $ambiguo = $this->lead('2026-09-20 16:00:00', ['phone' => '573001112233']);

        $ledger = $this->ledger($suelto, $ambiguo);

        $this->assertSame('unlinked', $ledger->get($suelto->id)['kind']);
        $this->assertSame('ambiguous', $ledger->get($ambiguo->id)['kind']);
        $this->assertSame(0.0, $ledger->get($ambiguo->id)['revenue']);
        $this->assertNull($ledger->get($ambiguo->id)['user_id']);
    }

    /** Contrato 8: una persona con dos leads es un cliente y sus pagos cuentan una vez. */
    public function test_la_misma_persona_no_se_duplica(): void
    {
        [$user, $member] = $this->customer('3001112233');
        // El mismo número guardado con y sin el 57, como ya pasa en producción.
        $primero = $this->lead('2026-09-20 15:00:00', ['phone' => '573001112233']);
        $segundo = $this->lead('2026-09-21 15:00:00', ['phone' => '3001112233', 'meta_user_id' => 'otro']);
        // Y un tercero enlazado a mano a la misma ficha.
        $tercero = $this->lead('2026-09-22 15:00:00', ['phone' => null, 'channel' => 'instagram', 'member_id' => $member->id]);
        $this->payment($user, 120000, '2026-09-23 15:00:00');

        $ledger = $this->ledger($tercero, $segundo, $primero);

        $this->assertSame([$tercero->id, $segundo->id, $primero->id], $ledger->keys()->all(), 'conserva el orden recibido');
        $this->assertSame('new', $ledger->get($primero->id)['kind']);
        $this->assertSame(120000.0, $ledger->get($primero->id)['revenue']);
        foreach ([$segundo, $tercero] as $duplicado) {
            $this->assertSame('duplicate', $ledger->get($duplicado->id)['kind']);
            $this->assertSame(0.0, $ledger->get($duplicado->id)['revenue']);
            $this->assertSame($primero->id, $ledger->get($duplicado->id)['duplicate_of']);
        }
        $this->assertSame(120000.0, $ledger->sum('revenue'));
    }

    /** Una consulta por tipo de dato, no una por lead. */
    public function test_no_hace_una_consulta_por_lead(): void
    {
        $consultas = 0;
        DB::listen(function () use (&$consultas): void {
            $consultas++;
        });

        $medir = function (int $cuantos) use (&$consultas): int {
            $leads = [];
            foreach (range(1, $cuantos) as $i) {
                $telefono = '30'.str_pad((string) ($cuantos * 1000 + $i), 8, '0', STR_PAD_LEFT);
                [$user, $member] = $this->customer($telefono);
                $this->payment($user, 1000, '2026-09-22 15:00:00');
                $this->installment($member, 500, '2026-09-22 16:00:00');
                $leads[] = $this->lead('2026-09-20 15:00:00', ['phone' => '57'.$telefono]);
            }

            $consultas = 0;
            $ledger = $this->ledger(...$leads);
            $this->assertSame($cuantos, $ledger->where('kind', 'new')->count());

            return $consultas;
        };

        $pocos = $medir(2);
        $muchos = $medir(20);

        $this->assertSame($pocos, $muchos, 'las consultas crecen con el número de leads');
        $this->assertSame(22, Payment::query()->count());
    }
}

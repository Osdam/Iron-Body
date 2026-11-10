<?php

namespace Tests\Feature\Outreach\Facts;

use App\Services\Outreach\Core\OutreachCatalog;
use App\Services\Outreach\Facts\AttendanceFacts;
use App\Services\Outreach\Facts\PaymentFacts;
use App\Services\Outreach\Facts\ReceivableFacts;
use App\Services\Outreach\Facts\SubjectKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Outreach\Facts\Support\FactsWorld;
use Tests\TestCase;

/**
 * B9-facts · ASISTENCIA, DINERO EN VUELO y CARTERA (contrato H9, D9-3
 * `member.inactive`, `payment_link.abandoned`, `receivable.overdue`; D9-4).
 * Quien nunca vino es DESCONOCIDO; el día pactado de una deuda todavía no
 * vence; los extremos de cada ventana; ni un importe en la foto.
 */
class MoneyAndAttendanceFactsTest extends TestCase
{
    use FactsWorld;
    use RefreshDatabase;

    /** 10:00 de Bogotá del lunes 09-11-2026. */
    private const NOW = '2026-11-09 15:00:00';

    public function test_asistencia_quien_nunca_vino_es_desconocido_y_los_dias_son_de_bogota(): void
    {
        $nunca = $this->socio();
        $hace14 = $this->socio();
        $tarde = $this->socio();
        // Última entrada el 26-10 a las 15:00 UTC → hace 14 días de Bogotá.
        $this->entry($hace14['user'], '2026-10-26 15:00:00', $hace14['member']);
        $this->entry($hace14['user'], '2026-10-26 16:00:00', $hace14['member'], 'exit'); // una salida no es una visita
        // 04:30 UTC del 09-11 = 23:30 del 08-11 en Bogotá: fue AYER, no hoy.
        $this->entry($tarde['user'], '2026-11-09 04:30:00', $tarde['member']);
        // Ventana de 30 días (RelativeTime: desde las 00:00 de Bogotá del 10-10, 05:00 UTC): el borde cuenta y lo anterior no.
        $this->entry($tarde['user'], '2026-10-10 05:00:00', $tarde['member']);
        $this->entry($tarde['user'], '2026-10-10 04:59:59', $tarde['member']);
        // Una entrada «futura» (reloj del torniquete adelantado) no es la última.
        $this->entry($tarde['user'], '2026-11-09 16:00:00', $tarde['member']);

        $claves = array_map(fn ($s) => SubjectKey::member($s['member']), [$nunca, $hace14, $tarde]);
        $foto = $this->snap([...$claves, SubjectKey::lead($this->lead())], self::NOW, self::only(AttendanceFacts::FACTS));

        $n = $foto->subject($claves[0]);
        $this->assertNull($n->fact('attendance.days_since_last'));
        $this->assertSame(0, $n->fact('attendance.count_last_30d'));
        $this->assertSame(14, $foto->subject($claves[1])->fact('attendance.days_since_last'));
        $this->assertSame(1, $foto->subject($claves[1])->fact('attendance.count_last_30d'));
        $t = $foto->subject($claves[2]);
        $this->assertSame(1, $t->fact('attendance.days_since_last'));
        $this->assertSame(2, $t->fact('attendance.count_last_30d'));
        // 2 / 4,3 = 0,47 → en centésimas (decimal de escala 2 del catálogo).
        $this->assertSame(47, $t->fact('attendance.weekly_rate'));
        // Un prospecto sin usuario: todo desconocido.
        $this->assertNull(array_values($foto->subjects)[3]->fact('attendance.count_last_30d'));
    }

    public function test_prefiltro_de_inactividad_excluye_a_quien_nunca_vino_y_respeta_el_borde(): void
    {
        $nunca = $this->socio();
        $justo = $this->socio();    // última entrada hace 14 días
        $casi = $this->socio();     // hace 13 días
        $vencido = $this->socio(['membership_end_date' => '2026-11-01']);
        $this->entry($justo['user'], '2026-10-26 23:00:00');
        $this->entry($casi['user'], '2026-10-27 05:00:00'); // 00:00 del 27 en Bogotá
        $this->entry($vencido['user'], '2026-09-01 15:00:00');

        $r = (new AttendanceFacts)->inactiveSince(self::at(self::NOW), 14);
        $this->assertSame([SubjectKey::member($justo['member'])], array_map(fn ($c) => $c->subjectKey, $r['candidates']));
        // La semana la pone el núcleo con `now`: inact:2026-W46.
        $this->assertSame('inact:2026-W46', OutreachCatalog::cycleKey('member.inactive', $r['candidates'][0]->cycleParts, self::at(self::NOW)));
        $this->assertNotContains(SubjectKey::member($nunca['member']), array_map(fn ($c) => $c->subjectKey, $r['candidates']));
    }

    public function test_enlace_de_pago_abierto_caducado_y_sin_url(): void
    {
        $abierto = $this->socio();
        $caducado = $this->socio();
        $sinUrl = $this->socio();
        $porUsuario = $this->socio();
        $this->tx(['member_id' => $abierto['member'], 'status' => 'pending', 'created_at' => self::utc('2026-11-09 05:30:00')]);
        $this->tx(['member_id' => $caducado['member'], 'status' => 'pending', 'expires_at' => self::utc('2026-11-09 14:59:59')]);
        $this->tx(['member_id' => $sinUrl['member'], 'status' => 'pending', 'checkout_url' => null]);
        $this->tx(['user_id' => $porUsuario['user'], 'status' => 'REQUIRES_ACTION', 'created_at' => self::utc('2026-11-09 14:00:00')]);
        // Dinero: dos aprobadas (una en mayúsculas), un rechazo hace 6 días y otro hace 8.
        $this->tx(['member_id' => $abierto['member'], 'status' => 'approved', 'checkout_url' => null, 'amount' => 777777]);
        $this->tx(['member_id' => $abierto['member'], 'status' => 'APPROVED', 'checkout_url' => null]);
        $this->tx(['member_id' => $abierto['member'], 'status' => 'declined', 'checkout_url' => null, 'created_at' => self::utc('2026-11-03 06:00:00')]);
        $this->tx(['member_id' => $caducado['member'], 'status' => 'error', 'checkout_url' => null, 'created_at' => self::utc('2026-11-01 15:00:00')]);

        $claves = array_map(fn ($s) => SubjectKey::member($s['member']), [$abierto, $caducado, $sinUrl, $porUsuario]);
        $foto = $this->snap($claves, self::NOW, self::only(PaymentFacts::FACTS));
        $a = $foto->subject($claves[0]);
        $this->assertTrue($a->fact('payment.has_pending_link'));
        $this->assertSame(9, $a->fact('payment.pending_link_age_hours'));
        $this->assertSame(2, $a->fact('payment.approved_count'));
        $this->assertSame(1, $a->fact('payment.declined_last_7d'));
        $this->assertFalse($a->fact('subscription.active'));
        $this->assertNull($a->fact('subscription.failed_attempts'));
        $c = $foto->subject($claves[1]);
        $this->assertFalse($c->fact('payment.has_pending_link'));
        $this->assertNull($c->fact('payment.pending_link_age_hours'));
        $this->assertSame(0, $c->fact('payment.declined_last_7d'));
        $this->assertFalse($foto->subject($claves[2])->fact('payment.has_pending_link'));
        $this->assertTrue($foto->subject($claves[3])->fact('payment.has_pending_link'));
        $this->assertSame(1, $foto->subject($claves[3])->fact('payment.pending_link_age_hours'));
        $this->assertStringNotContainsString('777777', $foto->canonicalJson());
    }

    public function test_prefiltro_de_enlace_abandonado_por_el_plazo_de_cortesia(): void
    {
        $s = $this->socio();
        $dentro = $this->tx(['member_id' => $s['member'], 'created_at' => self::utc('2026-11-09 09:00:00')]);   // 6 h justas
        $this->tx(['member_id' => $s['member'], 'created_at' => self::utc('2026-11-09 09:00:01')]);            // 5 h 59 m 59 s
        $this->tx(['member_id' => $s['member'], 'created_at' => self::utc('2026-11-02 08:59:59')]);            // pasado el plazo + 7 d
        $this->tx(['member_id' => $s['member'], 'status' => 'approved', 'created_at' => self::utc('2026-11-09 08:00:00')]);
        $this->tx(['member_id' => null, 'user_id' => null, 'created_at' => self::utc('2026-11-09 08:00:00')]); // sin persona

        $r = (new PaymentFacts)->abandonedLinks(self::at(self::NOW), 6);
        $this->assertSame([['payment_transaction_id' => $dentro]], array_map(fn ($c) => $c->cycleParts, $r['candidates']));
        $this->assertSame('tx:'.$dentro, OutreachCatalog::cycleKey('payment_link.abandoned', $r['candidates'][0]->cycleParts, self::at(self::NOW)));
        $this->assertSame(SubjectKey::member($s['member']), $r['candidates'][0]->subjectKey);
    }

    public function test_cartera_vencida_el_dia_pactado_todavia_no_vence(): void
    {
        $s = $this->socio();
        $limpio = $this->socio();
        $this->receivable($s['member'], '2026-11-09');                       // vence hoy: no está vencida
        $this->receivable($s['member'], '2026-11-08');                       // 1 día
        $this->receivable($s['member'], '2026-10-01', 'partially_paid');     // 39 días
        $this->receivable($s['member'], '2026-09-01', 'paid');               // pagada
        $this->receivable($s['member'], '2026-09-01', 'cancelled');          // anulada
        $this->receivable($s['member'], null);                               // sin plazo pactado
        $lead = $this->lead();

        $claves = [SubjectKey::member($s['member']), SubjectKey::member($limpio['member']), SubjectKey::lead($lead)];
        $foto = $this->snap($claves, self::NOW, self::only(ReceivableFacts::FACTS));
        $f = $foto->subject($claves[0]);
        $this->assertTrue($f->fact('receivable.has_overdue'));
        $this->assertSame(2, $f->fact('receivable.count_overdue'));
        $this->assertSame(39, $f->fact('receivable.overdue_days_max'));
        $this->assertFalse($foto->subject($claves[1])->fact('receivable.has_overdue'));
        $this->assertNull($foto->subject($claves[1])->fact('receivable.overdue_days_max'));
        // Un prospecto no puede deber: se sabe que no tiene cartera vencida.
        $this->assertFalse($foto->subject($claves[2])->fact('receivable.has_overdue'));
        $this->assertStringNotContainsString('80000', $foto->canonicalJson());
    }

    public function test_prefiltro_de_cartera_en_intervalo_cerrado_y_su_generacion(): void
    {
        $s = $this->socio();
        $hace7 = $this->receivable($s['member'], '2026-11-02');
        $hace8 = $this->receivable($s['member'], '2026-11-01');
        $hace30 = $this->receivable($s['member'], '2026-10-10');
        $this->receivable($s['member'], '2026-10-09'); // 31: fuera
        $this->receivable($s['member'], '2026-11-03'); // 6: fuera
        $this->receivablePayment($hace8);
        $this->receivablePayment($hace8, 'reversed');

        $r = (new ReceivableFacts)->overdueBetween(self::at(self::NOW), 7, 30);
        $this->assertSame(
            [['generation' => 0, 'receivable_id' => $hace7], ['generation' => 2, 'receivable_id' => $hace8], ['generation' => 0, 'receivable_id' => $hace30]],
            array_map(fn ($c) => $c->toArray()['cycleParts'], $r['candidates']),
        );
        $this->assertSame('rcv:'.$hace8.':2', OutreachCatalog::cycleKey('receivable.overdue', $r['candidates'][1]->cycleParts, self::at(self::NOW)));
        // Un abono nuevo sube la generación (otro ciclo); repetir la pasada no la cambia.
        $this->receivablePayment($hace7);
        $otra = (new ReceivableFacts)->overdueBetween(self::at(self::NOW), 7, 30);
        $this->assertSame(1, $otra['candidates'][0]->cycleParts['generation']);
        $this->assertSame(1, (new ReceivableFacts)->overdueBetween(self::at(self::NOW), 7, 30)['candidates'][0]->cycleParts['generation']);
    }

    public function test_suscripcion_mas_reciente(): void
    {
        $s = $this->socio();
        foreach ([['status' => 'cancelled', 'failed_attempts' => 9], ['status' => 'active', 'failed_attempts' => 2]] as $fila) {
            DB::table('membership_subscriptions')->insert($fila + ['uuid' => (string) Str::uuid(), 'user_id' => $s['user'], 'member_id' => $s['member'],
                'interval_days' => 30, 'created_at' => self::utc('2026-11-01 12:00'), 'updated_at' => self::utc('2026-11-01 12:00')]);
        }
        $foto = $this->snap([SubjectKey::member($s['member'])], self::NOW, self::only(['subscription.active', 'subscription.failed_attempts']));
        $this->assertTrue($foto->subject(SubjectKey::member($s['member']))->fact('subscription.active'));
        $this->assertSame(2, $foto->subject(SubjectKey::member($s['member']))->fact('subscription.failed_attempts'));
    }
}

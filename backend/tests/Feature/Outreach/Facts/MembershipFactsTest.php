<?php

namespace Tests\Feature\Outreach\Facts;

use App\Models\User;
use App\Services\MembershipService;
use App\Services\Outreach\Facts\MembershipFacts;
use App\Services\Outreach\Facts\SubjectKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Outreach\Facts\Support\FactsWorld;
use Tests\TestCase;

/**
 * B9-facts · MEMBRESÍA (contrato H9, D9-3 `membership.expiring_window` /
 * `expired_window`, D9-4; pruebas 1–3 del propietario en su parte de hechos).
 * Días de Bogotá, nunca de UTC; los dos extremos de los intervalos; congelados
 * que no parecen vencidos; plan por nombre; menor de edad sin exponer la fecha.
 */
class MembershipFactsTest extends TestCase
{
    use FactsWorld;
    use RefreshDatabase;

    /** 10:00 de Bogotá del lunes 09-11-2026. */
    private const NOW = '2026-11-09 15:00:00';

    public function test_vence_en_n_dias_entra_y_en_n_mas_1_no(): void
    {
        $siete = $this->socio(['membership_end_date' => '2026-11-16']);
        $ocho = $this->socio(['membership_end_date' => '2026-11-17']);
        $this->socio(['membership_end_date' => '2026-11-15']);

        $r = (new MembershipFacts)->endingBetween(self::at(self::NOW), 7, 7);
        $this->assertSame([SubjectKey::member($siete['member'])], array_map(fn ($c) => $c->subjectKey, $r['candidates']));
        $this->assertSame(['membership_end_date' => '2026-11-16'], $r['candidates'][0]->cycleParts);
        $this->assertSame('membership.expiring_window', $r['candidates'][0]->event);

        $foto = $this->snap([SubjectKey::member($siete['member']), SubjectKey::member($ocho['member'])], self::NOW, self::only(MembershipFacts::FACTS));
        $this->assertSame(7, $foto->subject(SubjectKey::member($siete['member']))->fact('membership.days_to_expiry'));
        $this->assertSame(-7, $foto->subject(SubjectKey::member($siete['member']))->fact('membership.days_since_expiry'));
        $this->assertSame(7, $foto->subject(SubjectKey::member($siete['member']))->fact('membership.end_date'));
        $this->assertSame(8, $foto->subject(SubjectKey::member($ocho['member']))->fact('membership.days_to_expiry'));
        $this->assertTrue($foto->subject(SubjectKey::member($siete['member']))->fact('membership.active'));

        // Otra ventana (otra versión de la estrategia) cambia el alcance sin tocar nada más.
        $r8 = (new MembershipFacts)->endingBetween(self::at(self::NOW), 0, 8);
        $this->assertCount(3, $r8['candidates']);
    }

    public function test_vence_hoy_es_el_dia_de_bogota_a_las_0450_utc(): void
    {
        // 04:50 UTC del 10-11 son las 23:50 del 09-11 en Bogotá: quien vence el 09 sigue activo y vence HOY.
        $ahora = '2026-11-10 04:50:00';
        $hoy = $this->socio(['membership_end_date' => '2026-11-09']);
        $ayer = $this->socio(['membership_end_date' => '2026-11-08']);
        $manana = $this->socio(['membership_end_date' => '2026-11-10']);

        $foto = $this->snap(array_map(fn ($s) => SubjectKey::member($s['member']), [$hoy, $ayer, $manana]), $ahora, self::only(MembershipFacts::FACTS));
        $h = $foto->subject(SubjectKey::member($hoy['member']));
        $this->assertTrue($h->fact('membership.active'));
        $this->assertSame(0, $h->fact('membership.days_to_expiry'));
        $this->assertSame(0, $h->fact('membership.days_since_expiry'));
        $a = $foto->subject(SubjectKey::member($ayer['member']));
        $this->assertFalse($a->fact('membership.active'));
        $this->assertSame(1, $a->fact('membership.days_since_expiry'));
        $this->assertSame(1, $foto->subject(SubjectKey::member($manana['member']))->fact('membership.days_to_expiry'));

        $r = (new MembershipFacts)->endingBetween(self::at($ahora), 0, 0);
        $this->assertSame([SubjectKey::member($hoy['member'])], array_map(fn ($c) => $c->subjectKey, $r['candidates']));

        // Paridad con la autoridad del negocio (`MembershipService::isActive`) en ese mismo instante.
        Carbon::setTestNow(Carbon::parse($ahora, 'UTC'));
        $servicio = app(MembershipService::class);
        foreach ([$hoy, $ayer, $manana] as $s) {
            $this->assertSame(
                $servicio->isActive(User::query()->findOrFail($s['user'])),
                $foto->subject(SubjectKey::member($s['member']))->fact('membership.active'),
            );
        }
        // 05:00 UTC: ya es el 10 en Bogotá y el del 09 venció.
        Carbon::setTestNow(Carbon::parse('2026-11-10 05:00:00', 'UTC'));
        $this->assertFalse($servicio->isActive(User::query()->findOrFail($hoy['user'])));
        $this->assertFalse($this->snap([SubjectKey::member($hoy['member'])], '2026-11-10 05:00:00', self::only(['membership.active']))
            ->subject(SubjectKey::member($hoy['member']))->fact('membership.active'));
        Carbon::setTestNow();
    }

    public function test_vencida_hace_entre_min_y_max_dias_respeta_los_dos_extremos(): void
    {
        $fechas = ['2026-11-07' => 2, '2026-11-06' => 3, '2026-11-04' => 5, '2026-11-03' => 6];
        $ids = [];
        foreach (array_keys($fechas) as $fin) {
            $ids[$fin] = $this->socio(['membership_end_date' => $fin])['member'];
        }
        $r = (new MembershipFacts)->endedBetween(self::at(self::NOW), 3, 5);
        $this->assertSame(
            [SubjectKey::member($ids['2026-11-06']), SubjectKey::member($ids['2026-11-04'])],
            array_map(fn ($c) => $c->subjectKey, $r['candidates']),
        );
        // `min` nunca baja de 1: el día de la fecha todavía es activo.
        $hoy = $this->socio(['membership_end_date' => '2026-11-09'])['member'];
        $this->assertNotContains(SubjectKey::member($hoy), array_map(fn ($c) => $c->subjectKey, (new MembershipFacts)->endedBetween(self::at(self::NOW), 0, 1)['candidates']));
    }

    public function test_una_membresia_congelada_no_parece_vencida_ayer(): void
    {
        // Congelar pone la fecha AYER (MembershipFreeze::freeze): es artificial.
        $c = $this->socio(['membership_end_date' => '2026-11-08', 'membership_frozen_at' => '2026-11-09', 'membership_frozen_until' => '2026-11-30', 'status' => 'inactive']);
        $foto = $this->snap([SubjectKey::member($c['member'])], self::NOW, self::only(MembershipFacts::FACTS));
        $s = $foto->subject(SubjectKey::member($c['member']));
        $this->assertTrue($s->fact('membership.frozen'));
        $this->assertFalse($s->fact('membership.active'));
        $this->assertNull($s->fact('membership.days_since_expiry'));
        $this->assertNull($s->fact('membership.days_to_expiry'));
        $this->assertNull($s->fact('membership.end_date'));
        $this->assertSame([], (new MembershipFacts)->endedBetween(self::at(self::NOW), 1, 1)['candidates']);
    }

    public function test_el_plan_se_casa_por_nombre_y_un_nombre_ambiguo_queda_desconocido(): void
    {
        $archivado = $this->plan(['name' => 'Mensual', 'duration_days' => 31, 'archived_at' => self::utc('2026-10-01 12:00')]);
        $vigente = $this->plan(['name' => 'Mensual', 'duration_days' => 30]);
        $this->plan(['name' => 'Trimestral']);
        $this->plan(['name' => 'TRIMESTRAL']);
        $mensual = $this->socio(['plan' => ' mensual ']);
        $ambiguo = $this->socio(['plan' => 'Trimestral']);
        $raro = $this->socio(['plan' => 'Plan que ya no existe']);

        $foto = $this->snap(array_map(fn ($s) => SubjectKey::member($s['member']), [$mensual, $ambiguo, $raro]), self::NOW, self::only(MembershipFacts::FACTS));
        $this->assertNotSame($archivado, $vigente);
        $this->assertSame($vigente, $foto->subject(SubjectKey::member($mensual['member']))->fact('membership.plan_id'));
        $this->assertSame(30, $foto->subject(SubjectKey::member($mensual['member']))->fact('membership.plan_duration_days'));
        $this->assertNull($foto->subject(SubjectKey::member($ambiguo['member']))->fact('membership.plan_id'));
        $this->assertNull($foto->subject(SubjectKey::member($raro['member']))->fact('membership.plan_id'));
        $this->assertTrue($foto->subject(SubjectKey::member($raro['member']))->fact('membership.active'));
    }

    public function test_menor_de_edad_por_fecha_de_nacimiento_sin_exponerla(): void
    {
        $cumple18 = $this->socio([], ['birth_date' => '2008-11-09', 'is_minor' => true]); // la marca vieja no gana a la fecha
        $tiene17 = $this->socio([], ['birth_date' => '2008-11-10']);
        $sinFechaMarcado = $this->socio(['birth_date' => null], ['birth_date' => null, 'is_minor' => true]);
        $sinNada = $this->socio(['birth_date' => null], ['birth_date' => null, 'is_minor' => false]);
        $fechaEnUsuario = $this->socio(['birth_date' => '2012-01-01'], ['birth_date' => null]);
        $bisiesto = $this->socio([], ['birth_date' => '2008-02-29']);
        $lead = $this->lead();

        $claves = [...array_map(fn ($s) => SubjectKey::member($s['member']), [$cumple18, $tiene17, $sinFechaMarcado, $sinNada, $fechaEnUsuario]), SubjectKey::lead($lead)];
        $foto = $this->snap($claves, self::NOW, self::only(['subject.is_minor']));
        $this->assertFalse($foto->subject($claves[0])->fact('subject.is_minor'));
        $this->assertTrue($foto->subject($claves[1])->fact('subject.is_minor'));
        $this->assertTrue($foto->subject($claves[2])->fact('subject.is_minor'));
        $this->assertNull($foto->subject($claves[3])->fact('subject.is_minor'));
        $this->assertTrue($foto->subject($claves[4])->fact('subject.is_minor'));
        $this->assertNull($foto->subject($claves[5])->fact('subject.is_minor'));

        // 29-02: en un año no bisiesto cumple el 01-03 (la lectura más estricta).
        $k = SubjectKey::member($bisiesto['member']);
        $this->assertTrue($this->snap([$k], '2026-02-28 15:00:00', self::only(['subject.is_minor']))->subject($k)->fact('subject.is_minor'));
        $this->assertFalse($this->snap([$k], '2026-03-01 15:00:00', self::only(['subject.is_minor']))->subject($k)->fact('subject.is_minor'));

        // Solo el booleano: la fecha no aparece en la foto.
        $this->assertStringNotContainsString('2008-11-09', $foto->canonicalJson());
        $this->assertStringNotContainsString('2012-01-01', $foto->canonicalJson());
    }

    public function test_socio_sin_usuario_y_prospecto_sin_ficha(): void
    {
        $sinUsuario = $this->socio(['__none__' => true]);
        $lead = $this->lead();
        $foto = $this->snap([SubjectKey::member($sinUsuario['member']), SubjectKey::lead($lead)], self::NOW, self::only(MembershipFacts::FACTS));
        $s = $foto->subject(SubjectKey::member($sinUsuario['member']));
        $this->assertTrue($s->fact('subject.is_member'));
        $this->assertFalse($s->fact('membership.active'));
        $this->assertNull($s->fact('membership.days_to_expiry'));
        $l = $foto->subject(SubjectKey::lead($lead));
        $this->assertFalse($l->fact('subject.is_member'));
        $this->assertFalse($l->fact('membership.active'));
        $this->assertNull($l->fact('membership.plan_id'));
    }

    public function test_renovo_desde_un_instante_por_un_cobro_de_membresia(): void
    {
        $plan = $this->plan();
        $renovo = $this->socio();
        $anulado = $this->socio();
        $antes = $this->socio();
        $sinUsuario = $this->socio(['__none__' => true]);
        DB::table('payments')->insert([
            ['user_id' => $renovo['user'], 'plan_id' => $plan, 'amount' => 1, 'status' => 'paid', 'paid_at' => self::utc('2026-11-08 20:00'), 'created_at' => self::utc('2026-11-08 20:00'), 'updated_at' => self::utc('2026-11-08 20:00')],
            ['user_id' => $anulado['user'], 'plan_id' => $plan, 'amount' => 1, 'status' => 'voided', 'paid_at' => self::utc('2026-11-08 20:00'), 'created_at' => self::utc('2026-11-08 20:00'), 'updated_at' => self::utc('2026-11-08 20:00')],
            ['user_id' => $antes['user'], 'plan_id' => $plan, 'amount' => 1, 'status' => 'PAGADO', 'paid_at' => self::utc('2026-11-01 20:00'), 'created_at' => self::utc('2026-11-01 20:00'), 'updated_at' => self::utc('2026-11-01 20:00')],
        ]);
        $claves = array_map(fn ($s) => SubjectKey::member($s['member']), [$renovo, $anulado, $antes, $sinUsuario]);
        $r = (new MembershipFacts)->renewedSince($this->pass($claves, self::NOW), self::at('2026-11-05 00:00:00'));
        $this->assertSame([true, false, false, null], array_values($r));
    }

    public function test_dias_como_socio_desde_la_suscripcion_o_el_inicio(): void
    {
        $conSuscripcion = $this->socio(['membership_start_date' => '2026-01-01']);
        DB::table('membership_subscriptions')->insert([
            'uuid' => (string) Str::uuid(), 'user_id' => $conSuscripcion['user'], 'member_id' => $conSuscripcion['member'],
            'status' => 'active', 'interval_days' => 30, 'current_period_start' => '2026-11-01', 'created_at' => self::utc('2026-11-01 12:00'), 'updated_at' => self::utc('2026-11-01 12:00'),
        ]);
        $sinSuscripcion = $this->socio(['membership_start_date' => '2026-11-02']);
        $futuro = $this->socio(['membership_start_date' => '2026-11-10']);
        $claves = array_map(fn ($s) => SubjectKey::member($s['member']), [$conSuscripcion, $sinSuscripcion, $futuro]);
        $foto = $this->snap($claves, self::NOW, self::only(['membership.days_as_member']));
        $this->assertSame(8, $foto->subject($claves[0])->fact('membership.days_as_member'));
        $this->assertSame(7, $foto->subject($claves[1])->fact('membership.days_as_member'));
        // `duration` (≥ 0): un periodo que empieza mañana todavía no cuenta.
        $this->assertNull($foto->subject($claves[2])->fact('membership.days_as_member'));
    }
}

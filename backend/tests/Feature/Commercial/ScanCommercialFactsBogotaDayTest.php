<?php

namespace Tests\Feature\Commercial;

use App\Jobs\Commercial\EvaluateCommercialSubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `commercial:scan` cuenta los días con el día del GIMNASIO (Bogotá), no con
 * el del servidor (UTC). Informe del tester I-6, D-1 y A-1.
 *
 * La orden corre cada hora en punto UTC (`routes/console.php`). Con
 * `Carbon::today()` / `yesterday()` en UTC, la pasada de las 00:00Z —las 19:00
 * de Bogotá— cambiaba de día cinco horas antes que el gimnasio: abría la
 * ventana de renovación con un día de más, daba por vencida una membresía que
 * aún valía, movía la semana de la inactividad el domingo a las 19:00 y
 * repetía el enlace abandonado del día. Estas pruebas fijan las cuatro
 * familias en sus horas límite de Bogotá —18:59:59 y 19:00:00 (cambio de día
 * UTC), 23:59:59 y 00:00:00 (cambio de día del gimnasio)— con el reloj en UTC,
 * y que las claves de deduplicación conservan su forma.
 *
 * Filas con INSERT directos: un observer del motor registraría sus propios
 * hechos y la prueba no sabría de quién es cada evento.
 */
class ScanCommercialFactsBogotaDayTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('commercial.events_enabled', true);
        config()->set('commercial.enabled', false);
        config()->set('commercial.thresholds.renewal_window_days', 10);
        config()->set('commercial.thresholds.at_risk_days', 14);
        config()->set('commercial.thresholds.payment_link_grace_hours', 6);
        Queue::fake([EvaluateCommercialSubject::class]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_la_ventana_de_renovacion_se_abre_a_la_medianoche_de_bogota_con_los_dias_de_bogota(): void
    {
        // Vence el sábado 28-11: el 17-11 de Bogotá le quedan 11 días; el 18-11, 10 (el borde de la ventana).
        $socio = $this->socio('2026-11-28');

        $this->scanAt('2026-11-17 23:59:59');   // 17-11 18:59:59 Bogotá
        $this->scanAt('2026-11-18 00:00:00');   // 17-11 19:00:00 Bogotá: aquí llegaba con 11 días (D-1)
        $this->scanAt('2026-11-18 04:59:59');   // 17-11 23:59:59 Bogotá
        $this->assertSame([], $this->keys('renewal_window_opened'), 'la ventana no se abre el 17-11 de Bogotá (quedan 11 días)');

        $this->scanAt('2026-11-18 05:00:00');   // 18-11 00:00:00 Bogotá
        $this->scanAt('2026-11-18 15:00:00');   // 18-11 10:00 Bogotá: no se repite
        $this->assertSame(["renewal_window_opened:renewal:{$socio['user']}:2026-11-28"], $this->keys('renewal_window_opened'));
        $evento = $this->event('renewal_window_opened');
        $this->assertSame('2026-11-18 05:00:00', $evento['occurred_at']);
        $this->assertSame('2026-11-28', $evento['payload']['ends_at']);
        $this->assertEquals(10, $evento['payload']['days_to_expiry'], 'días enteros de Bogotá, no 10 menos cinco horas');
    }

    public function test_membership_expired_no_llega_hasta_la_medianoche_de_bogota_que_cierra_el_ultimo_dia(): void
    {
        $socio = $this->socio('2026-11-17');   // último día válido: el martes 17-11 de Bogotá

        $this->scanAt('2026-11-17 23:59:59');   // 17-11 18:59:59 Bogotá
        $this->scanAt('2026-11-18 00:00:00');   // 17-11 19:00:00 Bogotá: aquí llegaba (A-1)
        $this->scanAt('2026-11-18 04:59:59');   // 17-11 23:59:59 Bogotá: aún tiene acceso
        $this->assertSame([], $this->keys('membership_expired'));

        $this->scanAt('2026-11-18 05:00:00');   // 18-11 00:00:00 Bogotá: el primer día sin acceso
        $this->scanAt('2026-11-19 04:59:59');   // 18-11 23:59:59 Bogotá: no se repite
        $this->scanAt('2026-11-19 05:00:00');   // 19-11 00:00:00 Bogotá: «ayer» ya es el 18-11
        $this->assertSame(["membership_expired:expired:{$socio['user']}:2026-11-17"], $this->keys('membership_expired'), 'la clave conserva su forma: expired:<usuario>:<fecha de fin>');
        $evento = $this->event('membership_expired');
        $this->assertSame('2026-11-18 05:00:00', $evento['occurred_at']);
        $carga = $evento['payload'];
        ksort($carga);   // PostgreSQL (jsonb) no conserva el orden de las claves
        $this->assertSame(['expired_at' => '2026-11-17', 'plan' => 'Mensual'], $carga);
    }

    public function test_la_semana_de_la_inactividad_cambia_el_lunes_a_las_00_de_bogota_y_no_el_domingo_a_las_19(): void
    {
        $socio = $this->socio('2026-12-31');
        $this->entry($socio, '2026-10-20 15:00:00');   // lleva semanas sin venir

        $this->scanAt('2026-11-22 23:59:59');   // domingo 22-11 18:59:59 Bogotá (semana ISO 47)
        $this->assertSame(["inactivity_detected:inactive:{$socio['member']}:2026-W47"], $this->keys('inactivity_detected'));

        $this->scanAt('2026-11-23 00:00:00');   // domingo 22-11 19:00:00 Bogotá: ya era lunes en UTC (W48)
        $this->scanAt('2026-11-23 04:59:59');   // domingo 22-11 23:59:59 Bogotá
        $this->assertSame(["inactivity_detected:inactive:{$socio['member']}:2026-W47"], $this->keys('inactivity_detected'), 'el domingo de Bogotá sigue siendo la semana 47');

        $this->scanAt('2026-11-23 05:00:00');   // lunes 23-11 00:00:00 Bogotá
        $this->assertSame([
            "inactivity_detected:inactive:{$socio['member']}:2026-W47",
            "inactivity_detected:inactive:{$socio['member']}:2026-W48",
        ], $this->keys('inactivity_detected'));
    }

    public function test_el_corte_de_la_inactividad_se_mueve_a_la_medianoche_de_bogota(): void
    {
        $socio = $this->socio('2026-12-31');
        // Última visita el sábado 07-11 a las 21:00 de Bogotá (02:00Z del domingo 08-11).
        $this->entry($socio, '2026-11-08 02:00:00');

        // El sábado 21-11 de Bogotá el corte (14 días) es el 07-11 a las 00:00 de Bogotá: vino después.
        $this->scanAt('2026-11-21 23:59:59');   // 21-11 18:59:59 Bogotá
        $this->scanAt('2026-11-22 00:00:00');   // 21-11 19:00:00 Bogotá
        $this->scanAt('2026-11-22 04:59:59');   // 21-11 23:59:59 Bogotá
        $this->assertSame([], $this->keys('inactivity_detected'));

        // El domingo 22-11 de Bogotá el corte es el 08-11 a las 00:00 de Bogotá (05:00Z): la visita queda antes.
        // Con el corte en UTC no llegaba hasta las 19:00 de Bogotá de ese domingo.
        $this->scanAt('2026-11-22 05:00:00');   // 22-11 00:00:00 Bogotá
        $this->assertSame(["inactivity_detected:inactive:{$socio['member']}:2026-W47"], $this->keys('inactivity_detected'));
        $evento = $this->event('inactivity_detected');
        $this->assertSame('2026-11-08T02:00:00+00:00', $evento['payload']['last_attendance_at']);
        $this->assertEqualsWithDelta(14.125, $evento['payload']['days_inactive'], 0.0001, 'desde la última visita hasta la medianoche de Bogotá de hoy');
    }

    public function test_la_inactividad_cuenta_la_membresia_vigente_hasta_el_final_de_su_ultimo_dia_de_bogota(): void
    {
        $socio = $this->socio('2026-11-17');   // su último día válido es el 17-11 de Bogotá
        $this->entry($socio, '2026-10-20 15:00:00');

        // 17-11 a las 19:00 de Bogotá: en UTC ya era el 18-11 y la membresía se daba por vencida.
        $this->scanAt('2026-11-18 00:00:00');
        $this->assertSame(["inactivity_detected:inactive:{$socio['member']}:2026-W47"], $this->keys('inactivity_detected'), 'membresía vigente hasta las 23:59:59 de Bogotá');
    }

    public function test_el_enlace_abandonado_se_recuerda_una_vez_por_dia_de_bogota(): void
    {
        $socio = $this->socio('2026-12-31');
        // Creado el 17-11 a las 07:00 de Bogotá: pasado el plazo de cortesía (6 h) desde las 13:00.
        $tx = $this->tx($socio, '2026-11-17 12:00:00');

        $this->scanAt('2026-11-17 23:59:59');   // 17-11 18:59:59 Bogotá
        $this->scanAt('2026-11-18 00:00:00');   // 17-11 19:00:00 Bogotá: con el día UTC salía otro recordatorio
        $this->scanAt('2026-11-18 04:59:59');   // 17-11 23:59:59 Bogotá
        $this->assertSame(["payment_pending:tx:{$tx}:abandoned:2026-11-17"], $this->keys('payment_pending'));

        $this->scanAt('2026-11-18 05:00:00');   // 18-11 00:00:00 Bogotá
        $this->assertSame([
            "payment_pending:tx:{$tx}:abandoned:2026-11-17",
            "payment_pending:tx:{$tx}:abandoned:2026-11-18",
        ], $this->keys('payment_pending'));
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    private function scanAt(string $utc): void
    {
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
        $this->artisan('commercial:scan')->assertExitCode(0);
    }

    /** @return list<string> las claves de deduplicación de un evento, en orden de registro */
    private function keys(string $event): array
    {
        return DB::table('commercial_events')->where('event', $event)->orderBy('id')->pluck('dedupe_key')->all();
    }

    /** @return array{occurred_at: string, payload: array<string, mixed>} */
    private function event(string $event): array
    {
        $e = DB::table('commercial_events')->where('event', $event)->orderBy('id')->first();

        return [
            'occurred_at' => Carbon::parse($e->occurred_at, 'UTC')->format('Y-m-d H:i:s'),
            'payload' => json_decode((string) $e->payload, true),
        ];
    }

    /** @return array{user: int, member: int} */
    private function socio(string $endDate): array
    {
        $n = ++$this->n;
        $userId = (int) DB::table('users')->insertGetId([
            'name' => "Socio {$n}", 'email' => "scan{$n}-".Str::random(6).'@iron.test', 'password' => 'x',
            'plan' => 'Mensual', 'membership_end_date' => $endDate, 'created_at' => '2026-01-01 12:00:00', 'updated_at' => '2026-01-01 12:00:00',
        ]);
        $memberId = (int) DB::table('members')->insertGetId([
            'member_uuid' => (string) Str::uuid(), 'access_hash' => hash('sha256', 'scan'.$n.Str::random(8)), 'full_name' => "Socio {$n}",
            'document_number' => 'SCAN'.$n.Str::random(6), 'user_id' => $userId, 'is_minor' => false,
            'status' => 'active', 'created_at' => '2026-01-01 12:00:00', 'updated_at' => '2026-01-01 12:00:00',
        ]);

        return ['user' => $userId, 'member' => $memberId];
    }

    /** @param  array{user: int, member: int}  $socio */
    private function entry(array $socio, string $utc): void
    {
        DB::table('attendances')->insert([
            'user_id' => $socio['user'], 'member_id' => $socio['member'], 'action' => 'entry', 'source' => 'manual',
            'captured_at' => $utc, 'created_at' => $utc, 'updated_at' => $utc,
        ]);
    }

    /** @param  array{user: int, member: int}  $socio */
    private function tx(array $socio, string $createdUtc): int
    {
        $n = ++$this->n;

        return (int) DB::table('payment_transactions')->insertGetId([
            'reference' => "SCAN-{$n}-".Str::random(6), 'idempotency_key' => "SCAN-IK-{$n}-".Str::random(6), 'member_id' => $socio['member'],
            'amount' => 100000, 'currency' => 'COP', 'status' => 'pending', 'provider' => 'wompi',
            'checkout_url' => 'https://checkout.wompi.co/l/scan'.$n, 'created_at' => $createdUtc, 'updated_at' => $createdUtc,
        ]);
    }
}

<?php

namespace Tests\Feature\Commercial;

use App\Jobs\Commercial\EvaluateCommercialSubject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `commercial:scan` mira a TODOS los candidatos de cada familia (informe-db-comercial §2).
 *
 * Antes cada familia pedía `limit(500)` sin orden y sin excluir lo ya
 * registrado: el techo se aplicaba a los sujetos MIRADOS, no a los hechos.
 * En producción hay 591 socios con membresía vigente: la inactividad miraba
 * siempre los mismos 500 y los otros 91 no tenían nunca `inactivity_detected`.
 * Ahora el techo (`--limit`) es de hechos NUEVOS por familia y pasada, cada
 * familia recorre a sus candidatos por id y lo que no cabe se registra en la
 * pasada siguiente, sin repetir a nadie.
 *
 * Reloj fijo: miércoles 18-11-2026, 10:00 de Bogotá (15:00Z), semana ISO 47.
 * Filas con INSERT directos, como en {@see ScanCommercialFactsBogotaDayTest}.
 */
class ScanCommercialFactsCeilingTest extends TestCase
{
    use RefreshDatabase;

    private int $n = 0;

    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('commercial.events_enabled', true);
        config()->set('commercial.enabled', false);
        config()->set('commercial.thresholds.renewal_window_days', 10);
        config()->set('commercial.thresholds.at_risk_days', 14);
        config()->set('commercial.thresholds.payment_link_grace_hours', 6);
        Queue::fake([EvaluateCommercialSubject::class]);
        Carbon::setTestNow(Carbon::parse('2026-11-18 15:00:00', 'UTC'));
        Event::listen(MessageLogged::class, function (MessageLogged $m): void {
            $this->logs[] = ['level' => $m->level, 'message' => $m->message, 'context' => $m->context];
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * El caso de producción: más socios vigentes que el techo y los inactivos
     * AL FINAL. Antes el techo cortaba la lista de socios antes de mirar su
     * última entrada y los inactivos no tenían nunca su hecho.
     */
    public function test_con_mas_socios_vigentes_que_el_techo_los_inactivos_del_final_tienen_su_hecho(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->entry($this->socio('2026-12-31'), '2026-11-17 12:00:00');   // vienen: no son inactivos
        }
        $inactivos = [];
        for ($i = 0; $i < 3; $i++) {
            $inactivos[] = $socio = $this->socio('2026-12-31');
            $this->entry($socio, '2026-10-01 12:00:00');                     // hace 48 días
        }

        // Dos pasadas con el techo en 5 (menos que los 8 vigentes).
        $this->scan(5);
        $this->scan(5);

        $esperadas = array_map(fn (array $s): string => "inactivity_detected:inactive:{$s['member']}:2026-W47", $inactivos);
        $this->assertSame($esperadas, $this->keys('inactivity_detected'));
        $this->assertSame([], $this->logged('commercial.scan.truncated'), 'tres hechos nuevos caben en un techo de cinco');
    }

    public function test_con_mas_inactivos_que_el_techo_todos_acaban_con_su_hecho_y_ninguno_dos_veces(): void
    {
        $inactivos = [];
        for ($i = 0; $i < 7; $i++) {
            $inactivos[] = $socio = $this->socio('2026-12-31');
            $this->entry($socio, '2026-10-01 12:00:00');
        }

        $this->artisan('commercial:scan', ['--limit' => 3])
            ->expectsOutputToContain('Techo de 3 hechos nuevos alcanzado en: inactivity')
            ->assertExitCode(0);
        $this->assertCount(3, $this->keys('inactivity_detected'), 'primera pasada: el techo');
        $this->scan(3);
        $this->assertCount(6, $this->keys('inactivity_detected'));
        $this->artisan('commercial:scan', ['--limit' => 3])
            ->doesntExpectOutputToContain('Techo de')
            ->assertExitCode(0);
        $this->scan(3);   // una pasada más: nada nuevo, nada repetido

        $esperadas = array_map(fn (array $s): string => "inactivity_detected:inactive:{$s['member']}:2026-W47", $inactivos);
        $this->assertSame($esperadas, $this->keys('inactivity_detected'), 'los siete, en orden de id, una vez cada uno');
        $truncados = $this->logged('commercial.scan.truncated');
        $this->assertCount(2, $truncados, 'avisa en las dos pasadas que recortan, no en las otras');
        $this->assertSame(['families' => ['inactivity'], 'limit' => 3, 'dry_run' => false], array_intersect_key($truncados[0]['context'], array_flip(['families', 'limit', 'dry_run'])));
    }

    public function test_las_otras_tres_familias_tambien_llegan_a_todos_por_encima_del_techo(): void
    {
        $renovacion = $vencidas = $enlaces = [];
        for ($i = 0; $i < 4; $i++) {
            $renovacion[] = $this->socio('2026-11-25');                         // en la ventana (7 días)
            $vencidas[] = $this->socio('2026-11-17');                           // venció ayer (Bogotá)
            $enlaces[] = $this->tx($this->socio('2026-12-31'), '2026-11-18 05:00:00');   // hace 10 h
        }

        for ($pasada = 0; $pasada < 3; $pasada++) {
            $this->scan(3);
        }

        $this->assertSame(array_map(fn (array $s): string => "renewal_window_opened:renewal:{$s['user']}:2026-11-25", $renovacion), $this->keys('renewal_window_opened'));
        $this->assertSame(array_map(fn (array $s): string => "membership_expired:expired:{$s['user']}:2026-11-17", $vencidas), $this->keys('membership_expired'));
        $this->assertSame(array_map(fn (int $tx): string => "payment_pending:tx:{$tx}:abandoned:2026-11-18", $enlaces), $this->keys('payment_pending'));
        $this->assertSame(
            ['abandoned_links', 'expired', 'renewal_window'],
            collect($this->logged('commercial.scan.truncated'))->flatMap(fn (array $l): array => $l['context']['families'])->unique()->sort()->values()->all(),
            'las tres recortaron en la primera pasada',
        );
    }

    /** La última entrada de los socios se pide por trozo, no una consulta por socio. */
    public function test_la_inactividad_consulta_las_asistencias_una_vez_por_trozo_y_no_una_por_socio(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->entry($this->socio('2026-12-31'), $i % 2 === 0 ? '2026-10-01 12:00:00' : '2026-11-17 12:00:00');
        }
        $sql = [];
        DB::listen(function ($q) use (&$sql): void {
            $sql[] = $q->sql;
        });

        $this->scan(500);

        $this->assertCount(1, preg_grep('/from\s+"?attendances"?/i', $sql), 'seis socios vigentes, una sola consulta a asistencias');
        $this->assertCount(3, $this->keys('inactivity_detected'));
    }

    /**
     * Solo cuentan las ENTRADAS (revisión de la tanda 2, menor 1 del escáner).
     * Una salida posterior al corte no convierte a nadie en «viene», y quien
     * solo tiene salidas no ha empezado: no es inactividad. Si la consulta por
     * trozo dejara de filtrar `action = 'entry'`, el primero dejaría de salir
     * y el segundo saldría, sin que ninguna otra prueba lo viera.
     */
    public function test_solo_las_entradas_cuentan_una_salida_reciente_no_es_una_visita(): void
    {
        $dejoDeVenir = $this->socio('2026-12-31');
        $this->entry($dejoDeVenir, '2026-10-01 12:00:00');            // su última ENTRADA: hace 48 días
        $this->exitRow($dejoDeVenir, '2026-11-17 12:00:00');          // una salida de ayer
        $nuncaEntro = $this->socio('2026-12-31');
        $this->exitRow($nuncaEntro, '2026-10-01 12:00:00');           // solo una salida, antigua

        $this->scan(500);

        $this->assertSame(["inactivity_detected:inactive:{$dejoDeVenir['member']}:2026-W47"], $this->keys('inactivity_detected'));
    }

    public function test_en_seco_el_techo_tambien_corta_y_no_registra_nada(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->entry($this->socio('2026-12-31'), '2026-10-01 12:00:00');
        }

        $this->artisan('commercial:scan', ['--limit' => 2, '--dry-run' => true])
            ->expectsOutputToContain('Techo de 2 hechos nuevos alcanzado en: inactivity')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('commercial_events')->count());
    }

    // ── Piezas ──────────────────────────────────────────────────────────────

    private function scan(int $limit): void
    {
        $this->artisan('commercial:scan', ['--limit' => $limit])->assertExitCode(0);
    }

    /** @return list<string> las claves de deduplicación de un evento, en orden de registro */
    private function keys(string $event): array
    {
        return DB::table('commercial_events')->where('event', $event)->orderBy('id')->pluck('dedupe_key')->all();
    }

    /** @return list<array{level: string, message: string, context: array<string, mixed>}> */
    private function logged(string $message): array
    {
        return array_values(array_filter($this->logs, fn (array $l): bool => $l['message'] === $message));
    }

    /** @return array{user: int, member: int} */
    private function socio(string $endDate): array
    {
        $n = ++$this->n;
        $userId = (int) DB::table('users')->insertGetId([
            'name' => "Socio {$n}", 'email' => "techo{$n}-".Str::random(6).'@iron.test', 'password' => 'x',
            'plan' => 'Mensual', 'membership_end_date' => $endDate, 'created_at' => '2026-01-01 12:00:00', 'updated_at' => '2026-01-01 12:00:00',
        ]);
        $memberId = (int) DB::table('members')->insertGetId([
            'member_uuid' => (string) Str::uuid(), 'access_hash' => hash('sha256', 'techo'.$n.Str::random(8)), 'full_name' => "Socio {$n}",
            'document_number' => 'TECHO'.$n.Str::random(6), 'user_id' => $userId, 'is_minor' => false,
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
    private function exitRow(array $socio, string $utc): void
    {
        DB::table('attendances')->insert([
            'user_id' => $socio['user'], 'member_id' => $socio['member'], 'action' => 'exit', 'source' => 'manual',
            'captured_at' => $utc, 'created_at' => $utc, 'updated_at' => $utc,
        ]);
    }

    /** @param  array{user: int, member: int}  $socio */
    private function tx(array $socio, string $createdUtc): int
    {
        $n = ++$this->n;

        return (int) DB::table('payment_transactions')->insertGetId([
            'reference' => "TECHO-{$n}-".Str::random(6), 'idempotency_key' => "TECHO-IK-{$n}-".Str::random(6), 'member_id' => $socio['member'],
            'amount' => 100000, 'currency' => 'COP', 'status' => 'pending', 'provider' => 'wompi',
            'checkout_url' => 'https://checkout.wompi.co/l/techo'.$n, 'created_at' => $createdUtc, 'updated_at' => $createdUtc,
        ]);
    }
}

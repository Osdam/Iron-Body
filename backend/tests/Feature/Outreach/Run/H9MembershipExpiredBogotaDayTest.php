<?php

namespace Tests\Feature\Outreach\Run;

use App\Models\User;
use App\Services\MembershipService;
use App\Services\Outreach\Run\OutreachPlanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Outreach\Run\Support\RunWorld;
use Tests\TestCase;

/**
 * Gemela de {@see H9RenewalWindowBogotaDayTest} para `membership_expired`
 * (informe-tester-i6.md, A-1).
 *
 * La membresía vale hasta el FINAL de su último día de Bogotá
 * (`MembershipService::endsAt()`), y el H9 la ve activa hasta entonces
 * (`MembershipFacts`: «hoy de Bogotá ≤ fecha»). `commercial:scan` corre cada
 * hora en punto UTC; con `Carbon::yesterday()` en UTC el hecho llegaba a las
 * 19:00 de Bogotá del ÚLTIMO DÍA VÁLIDO, una estrategia con audiencia
 * `membership.active = false` lo dejaba `not_in_audience` y el emisor no lo
 * repetía (una vez por vencimiento). Comportamiento esperado: el hecho llega a
 * la medianoche de Bogotá que cierra ese día y la persona se inscribe una vez.
 *
 * Reloj en UTC; las horas límite son las de Bogotá: 18:59:59 y 19:00:00 (el
 * cambio de día UTC) y 23:59:59 y 00:00:00 (el cambio de día del gimnasio).
 */
class H9MembershipExpiredBogotaDayTest extends TestCase
{
    use RefreshDatabase;
    use RunWorld;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_membership_expired_llega_tras_la_medianoche_de_bogota_del_ultimo_dia_valido_e_inscribe_una_vez(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-17 23:00:00', 'UTC'));   // martes 17-11, 18:00 de Bogotá
        config(['commercial.events_enabled' => true]);
        Http::preventStrayRequests();
        $this->threePeople();
        $this->runFlags('shadow');
        $vivo = $this->liveStrategy($this->validContent($this->assistedTemplate(), [
            'goal' => 'renew',
            'trigger' => ['event' => 'membership_expired'],
            'audience' => ['all' => [
                ['fact' => 'membership.active', 'op' => 'eq', 'value' => false],
                ['fact' => 'membership.frozen', 'op' => 'eq', 'value' => false],
            ]],
            'success' => ['fact' => 'membership.days_to_expiry', 'op' => 'gt', 'value' => 30],
        ]));
        // Último día válido: el martes 17-11 de Bogotá (acceso hasta las 23:59:59 locales = 04:59:59Z del 18-11).
        $socio = $this->socio(['membership_end_date' => '2026-11-17', 'plan' => 'Mensual']);
        $this->conversation($this->lead(['member_id' => $socio['member'], 'phone' => '3001234562', 'consent_status' => 'granted']));
        $usuario = User::query()->findOrFail($socio['user']);
        $membresia = app(MembershipService::class);

        $planner = new OutreachPlanner;
        $pasadas = [
            // [instante UTC, hora de Bogotá, ¿la membresía sigue vigente?, eventos esperados, inscripciones esperadas]
            ['2026-11-17 23:59:59', '17-11 18:59:59', true, 0, 0],
            ['2026-11-18 00:00:00', '17-11 19:00:00', true, 0, 0],   // aquí llegaba antes (A-1)
            ['2026-11-18 04:59:59', '17-11 23:59:59', true, 0, 0],
            ['2026-11-18 05:00:00', '18-11 00:00:00', false, 1, 1],  // primer instante sin acceso
            ['2026-11-18 15:00:00', '18-11 10:00:00', false, 1, 1],  // el escaneo de la mañana no lo repite
            ['2026-11-19 05:00:00', '19-11 00:00:00', false, 1, 1],  // ni el del día siguiente («ayer» ya es el 18-11)
        ];
        $diario = [];
        foreach ($pasadas as [$utc, $bogota, $vigente, $eventos, $inscripciones]) {
            Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
            $this->assertSame($vigente, $membresia->isActive($usuario), "{$bogota} Bogotá: MembershipService::isActive()");
            $this->artisan('commercial:scan')->assertExitCode(0);
            $r = $planner->ingest(now());
            $c = $r['strategies'][$vivo['strategyUuid']] ?? null;
            $diario[] = "{$bogota} Bogotá → ".json_encode($c === null ? null : array_intersect_key($c, array_flip(['candidates', 'enrolled', 'not_in_audience'])));

            $this->assertSame($eventos, DB::table('commercial_events')->where('event', 'membership_expired')->count(), "{$bogota} Bogotá: eventos membership_expired. Pasadas: ".json_encode($diario, JSON_UNESCAPED_UNICODE));
            $this->assertSame($inscripciones, DB::table('outreach_enrollments')->where('subject_key', 'm:'.$socio['member'])->count(), "{$bogota} Bogotá: inscripciones. Pasadas: ".json_encode($diario, JSON_UNESCAPED_UNICODE));
        }

        $evento = DB::table('commercial_events')->where('event', 'membership_expired')->first();
        // La clave conserva su forma (expired:<usuario>:<fecha de fin>) y el hecho lleva la hora a la que se vio.
        $this->assertSame("membership_expired:expired:{$socio['user']}:2026-11-17", $evento->dedupe_key);
        $this->assertSame('2026-11-18 05:00:00', Carbon::parse($evento->occurred_at, 'UTC')->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-17', json_decode((string) $evento->payload, true)['expired_at']);
        $this->assertSame(
            ['18-11 00:00:00 Bogotá → {"candidates":1,"enrolled":1,"not_in_audience":0}'],
            array_values(array_filter($diario, static fn (string $l): bool => ! str_ends_with($l, '→ null'))),
            'una sola pasada con candidato: la de la medianoche de Bogotá, y entra a la primera',
        );
    }
}

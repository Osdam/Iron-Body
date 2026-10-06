<?php

namespace Tests\Unit\Marketing\Attribution;

use App\Models\MarketingLead;
use App\Models\MarketingLeadIdentity;
use App\Services\Marketing\Attribution\LeadIdentityResolver;
use App\Services\Marketing\Attribution\MetaDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Marketing\Attribution\SeedsAttribution;
use Tests\TestCase;

/**
 * Quién es, en el CRM, la persona de cada lead (regla D7): la ficha explícita
 * manda; si no, un teléfono que señale a UNA sola persona; con varias, nadie.
 */
class LeadIdentityResolverTest extends TestCase
{
    use RefreshDatabase, SeedsAttribution;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 15:00:00');
        // Las pruebas dan de alta socios a mitad de prueba: sin el índice de
        // teléfonos en caché (tiene su propia prueba en MetaDashboardCacheTest).
        config()->set('marketing.attribution.phone_index_cache_seconds', 0);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function resolver(): LeadIdentityResolver
    {
        return app(LeadIdentityResolver::class);
    }

    public function test_la_ficha_explicita_del_lead_manda_sobre_el_telefono(): void
    {
        [$userA, $memberA] = $this->customer('3001112233');
        [, $memberB] = $this->customer('3209998877');

        // Teléfono de A, pero alguien confirmó a mano que es B.
        $lead = $this->lead('2026-10-02 15:00:00', ['phone' => '573001112233', 'member_id' => $memberB->id]);

        $r = $this->resolver()->resolve($lead);

        $this->assertSame('explicit', $r['method']);
        $this->assertSame($memberB->id, $r['member_id']);
        $this->assertSame($memberB->user_id, $r['user_id']);
        $this->assertSame(1, $r['candidates']);
        $this->assertNotSame($userA->id, $r['user_id']);
    }

    public function test_telefono_de_socio_con_otro_formato_casa_por_los_ultimos_diez_digitos(): void
    {
        // El lead llega con 57 delante; la ficha se escribió a mano en el mostrador.
        [$user, $member] = $this->customer('300 111 2233', memberPhone: '(300) 111-2233');
        $lead = $this->lead('2026-10-02 15:00:00', ['phone' => '573001112233']);

        $r = $this->resolver()->resolve($lead);

        $this->assertSame('phone_member', $r['method']);
        $this->assertSame($member->id, $r['member_id']);
        $this->assertSame($user->id, $r['user_id']);
        $this->assertSame(1, $r['candidates']);
    }

    public function test_sin_ficha_que_case_vale_un_usuario_unico(): void
    {
        [$user] = $this->customer('+57 310 555 4433', withMember: false);
        $lead = $this->lead('2026-10-02 15:00:00', ['phone' => '573105554433']);

        $r = $this->resolver()->resolve($lead);

        $this->assertSame('phone_user', $r['method']);
        $this->assertSame($user->id, $r['user_id']);
        $this->assertNull($r['member_id']);
    }

    public function test_el_usuario_que_casa_trae_su_ficha_aunque_la_ficha_tenga_otro_numero(): void
    {
        [$user, $member] = $this->customer('3105554433', memberPhone: '3150000000');
        $lead = $this->lead('2026-10-02 15:00:00', ['phone' => '573105554433']);

        $r = $this->resolver()->resolve($lead);

        $this->assertSame('phone_user', $r['method']);
        $this->assertSame($user->id, $r['user_id']);
        // Por la ficha van los abonos: se busca aunque su teléfono sea otro.
        $this->assertSame($member->id, $r['member_id']);
    }

    public function test_un_numero_de_varias_personas_queda_ambiguo_y_sin_vinculo(): void
    {
        // Madre e hijo con el mismo teléfono.
        $this->customer('3001112233');
        $this->customer('300-111-2233');
        $lead = $this->lead('2026-10-02 15:00:00', ['phone' => '573001112233']);

        $r = $this->resolver()->resolve($lead);
        $this->assertSame('ambiguous', $r['method']);
        $this->assertSame(2, $r['candidates']);
        $this->assertNull($r['user_id']);
        $this->assertNull($r['member_id']);

        // Una ficha y OTRO usuario sin ficha con el mismo número: también son dos.
        // Es una desviación DECLARADA de D7 (que solo miraría usuarios si no hay
        // socio): más prudente, y el panel lo explica en sus definiciones.
        $this->customer('3204445566');
        $this->customer('3204445566', withMember: false);
        $otro = $this->lead('2026-10-02 16:00:00', ['phone' => '573204445566']);
        $this->assertSame('ambiguous', $this->resolver()->resolve($otro)['method']);
        $this->assertStringContainsString('una ficha de socio y otra cuenta de usuario distinta', MetaDashboardService::definitions()['identity']);
    }

    public function test_la_ficha_y_su_propio_usuario_son_una_sola_persona(): void
    {
        [$user, $member] = $this->customer('3001112233');
        $lead = $this->lead('2026-10-02 15:00:00', ['phone' => '573001112233']);

        $r = $this->resolver()->resolve($lead);

        $this->assertSame('phone_member', $r['method']);
        $this->assertSame(1, $r['candidates']);
        $this->assertSame([$user->id, $member->id], [$r['user_id'], $r['member_id']]);
    }

    public function test_sin_telefono_o_sin_coincidencias_no_hay_vinculo(): void
    {
        $this->customer('3001112233');

        $instagram = $this->lead('2026-10-02 15:00:00', ['channel' => 'instagram', 'phone' => null, 'meta_user_id' => 'IGSID-1']);
        $otro = $this->lead('2026-10-02 16:00:00', ['phone' => '573009990000']);
        $corto = $this->lead('2026-10-02 17:00:00', ['phone' => '12345']);

        foreach ([$instagram, $otro, $corto] as $lead) {
            $r = $this->resolver()->resolve($lead);
            $this->assertSame('none', $r['method']);
            $this->assertSame(0, $r['candidates']);
            $this->assertNull($r['user_id']);
        }
    }

    /** Contrato 8 (identidad): resolver dos veces no duplica, y nunca escribe el lead. */
    public function test_sincronizar_es_idempotente_y_no_toca_el_lead(): void
    {
        [, $member] = $this->customer('3001112233');
        $enlazado = $this->lead('2026-10-02 15:00:00', ['phone' => '573001112233']);
        $suelto = $this->lead('2026-10-02 16:00:00', ['phone' => '573004445566']);
        $this->lead('2026-10-02 17:00:00', ['source' => 'manual_test', 'phone' => '573001112233']);

        $this->assertSame(2, $this->resolver()->syncAll());
        $this->assertSame(0, $this->resolver()->syncAll(), 'una segunda pasada sin cambios no escribe');

        $this->assertSame(2, MarketingLeadIdentity::query()->count(), 'los leads de prueba no se resuelven');
        $this->assertSame('phone_member', MarketingLeadIdentity::query()->where('marketing_lead_id', $enlazado->id)->value('method'));
        $this->assertNull($enlazado->fresh()->member_id, 'el vínculo por teléfono NO se escribe en marketing_leads');

        // Días después, el lead suelto se da de alta en el mostrador: se actualiza.
        $this->customer('3004445566');
        Carbon::setTestNow('2026-10-06 15:00:00');
        $this->assertSame(1, $this->resolver()->syncAll());

        $fila = MarketingLeadIdentity::query()->where('marketing_lead_id', $suelto->id)->first();
        $this->assertSame('phone_member', $fila->method);
        $this->assertSame('2026-10-06 15:00:00', $fila->resolved_at->toDateTimeString());
        $this->assertSame(2, MarketingLeadIdentity::query()->count());
        $this->assertNull($suelto->fresh()->member_id);
        $this->assertSame($member->id, MarketingLeadIdentity::query()->where('marketing_lead_id', $enlazado->id)->value('member_id'));
    }

    /**
     * Quien escribe la tabla lo hace siempre en orden de lead, reciba los leads
     * como los reciba: dos escritores en orden distinto se pueden bloquear entre
     * sí en PostgreSQL (40P01).
     */
    public function test_escribe_las_identidades_en_orden_de_lead(): void
    {
        $leads = [];
        foreach (['3001110001', '3001110002', '3001110003'] as $telefono) {
            $this->customer($telefono);
            $leads[] = $this->lead('2026-10-02 15:00:00', ['phone' => '57'.$telefono]);
        }
        [$a, $b, $c] = $leads;

        $escrituras = [];
        DB::listen(function ($query) use (&$escrituras): void {
            if (preg_match('/^insert into "marketing_lead_identities" \(([^)]*)\)/', $query->sql, $m) === 1) {
                $columnas = array_map(fn (string $c): string => trim($c, ' "'), explode(',', $m[1]));
                $posicion = array_search('marketing_lead_id', $columnas, true);
                foreach (array_chunk($query->bindings, count($columnas)) as $fila) {
                    $escrituras[] = (int) $fila[$posicion];
                }
            }
        });

        $this->assertSame(3, $this->resolver()->syncAll(collect([$c, $a, $b])));
        $this->assertSame([$a->id, $b->id, $c->id], $escrituras);
    }

    public function test_simular_no_escribe(): void
    {
        $this->customer('3001112233');
        $this->lead('2026-10-02 15:00:00', ['phone' => '573001112233']);

        $this->assertSame(1, $this->resolver()->syncAll(null, dryRun: true));
        $this->assertSame(0, MarketingLeadIdentity::query()->count());
    }

    /** En lote: las consultas no crecen con el número de leads. */
    public function test_resolver_muchos_no_hace_una_consulta_por_lead(): void
    {
        $consultas = 0;
        DB::listen(function () use (&$consultas): void {
            $consultas++;
        });

        $medir = function (int $cuantos) use (&$consultas): int {
            $leads = collect();
            $member = null;
            foreach (range(1, $cuantos) as $i) {
                $telefono = '31'.str_pad((string) ($cuantos * 1000 + $i), 8, '0', STR_PAD_LEFT);
                [, $member] = $this->customer($telefono);
                $leads->push($this->lead('2026-10-02 15:00:00', ['phone' => '57'.$telefono]));
            }
            // Uno con su ficha explícita y otro que solo casa con un usuario.
            $leads->push($this->lead('2026-10-02 15:00:00', ['member_id' => $member->id]));
            $this->customer('35'.str_pad((string) $cuantos, 8, '0', STR_PAD_LEFT), withMember: false);
            $leads->push($this->lead('2026-10-02 15:00:00', ['phone' => '5735'.str_pad((string) $cuantos, 8, '0', STR_PAD_LEFT)]));

            $consultas = 0;
            $resueltos = $this->resolver()->resolveMany($leads);
            $this->assertCount($cuantos + 2, $resueltos);

            return $consultas;
        };

        $pocos = $medir(2);
        $muchos = $medir(25);

        // Ficha explícita, barrido de socios, barrido de usuarios y fichas de los
        // usuarios: cuatro, se resuelvan 4 leads o 27.
        $this->assertLessThanOrEqual(4, $pocos);
        $this->assertSame($pocos, $muchos, 'las consultas crecen con el número de leads');
        $this->assertGreaterThan(0, MarketingLead::query()->count());
    }
}

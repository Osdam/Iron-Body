<?php

namespace Tests\Feature\Outreach\Facts;

use App\Services\Outreach\Core\CanonicalCondition;
use App\Services\Outreach\Core\ConditionEvaluator;
use App\Services\Outreach\Core\PolicyLadder;
use App\Services\Outreach\Facts\BatchSnapshot;
use App\Services\Outreach\Facts\BatchSnapshotBuilder;
use App\Services\Outreach\Facts\ContactHistoryFacts;
use App\Services\Outreach\Facts\CoreProjection;
use App\Services\Outreach\Facts\FactSelection;
use App\Services\Outreach\Facts\MembershipFacts;
use App\Services\Outreach\Facts\SubjectKey;
use App\Services\Outreach\Facts\SubjectRef;
use App\Services\Outreach\Facts\SubjectResolver;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Feature\Outreach\Facts\Support\FactsWorld;
use Tests\TestCase;

/**
 * B9-facts · LA PERSONA, SU SEUDÓNIMO Y LA FOTO DEL LOTE (contrato H9, D9-2,
 * D9-8, D9-12, D9-16, L9-4). El socio manda; las fichas con la misma identidad
 * o usuario son una persona; la foto es reproducible y serializable con huella
 * estable; una consulta por fuente para N personas; y la foto alimenta el
 * evaluador y la escalera REALES de B9-core.
 */
class SubjectAndSnapshotTest extends TestCase
{
    use FactsWorld;
    use RefreshDatabase;

    private const NOW = '2026-11-09 15:00:00';

    public function test_el_socio_manda_y_las_fichas_de_una_misma_persona_se_unen(): void
    {
        $identidad = (int) DB::table('identities')->insertGetId(['uuid' => (string) Str::uuid(), 'created_at' => self::utc('2026-01-01 12:00'), 'updated_at' => self::utc('2026-01-01 12:00')]);
        $a = $this->socio([], ['identity_id' => $identidad]);
        $b = $this->socio([], ['identity_id' => $identidad]);   // el mismo documento escrito de otra forma
        $deB = $this->lead(['member_id' => $b['member']]);
        $suelto = $this->lead();
        $this->entry($a['user'], '2026-11-01 15:00:00');
        $this->entry($b['user'], '2026-11-08 15:00:00');

        $claves = [SubjectKey::member($b['member']), SubjectKey::lead($deB), SubjectKey::lead($suelto), 'm:999999', SubjectKey::member($a['member'])];
        $sujetos = (new SubjectResolver)->forKeys($claves);
        $this->assertSame(SubjectKey::member($a['member']), $sujetos[$claves[0]]->key);    // la canónica es la de menor id
        $this->assertSame([$a['member'], $b['member']], $sujetos[$claves[0]]->memberIds);
        $this->assertSame([$a['user'], $b['user']], $sujetos[$claves[0]]->userIds);
        $this->assertSame([$deB], $sujetos[$claves[0]]->leadIds);
        $this->assertSame(SubjectKey::member($a['member']), $sujetos[$claves[1]]->key);    // el prospecto convertido es la persona socia
        $this->assertSame(SubjectKey::lead($suelto), $sujetos[$claves[2]]->key);
        $this->assertNull($sujetos['m:999999']->key);                                        // nunca se adivina

        $foto = $this->snap($claves, self::NOW, self::only(['subject.is_member', 'subject.is_lead', 'attendance.days_since_last']));
        $this->assertSame(1, $foto->subject($claves[0])->fact('attendance.days_since_last'));  // la entrada de la otra ficha cuenta
        $this->assertSame($foto->subject($claves[4])->ref, $foto->subject($claves[1])->ref);  // misma persona, mismo seudónimo
        $this->assertFalse($foto->subject('m:999999')->fact('subject.is_member'));            // no admite nulo
        $this->assertFalse($foto->subject('m:999999')->resolved());

        // Los resolutores de los prefiltros llegan a la misma persona.
        $this->assertSame(SubjectKey::member($a['member']), (new SubjectResolver)->forUserIds([$b['user']])[$b['user']]->key);
        $this->assertSame(SubjectKey::member($a['member']), (new SubjectResolver)->forLeadIds([$deB])[$deB]->key);
    }

    public function test_el_seudonimo_es_el_hmac_de_la_subclave_outreach_subject(): void
    {
        $clave = random_bytes(32);
        $esperado = hash_hmac('sha256', 'm:42', hash_hmac('sha256', 'outreach-subject/1', $clave, true));
        $this->assertSame($esperado, SubjectRef::withKey('m:42', $clave));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', SubjectRef::of('m:42'));
        $this->assertNotSame(SubjectRef::withKey('m:42', $clave), SubjectRef::withKey('l:42', $clave));
        $this->assertNotSame(SubjectRef::withKey('m:42', $clave), SubjectRef::withKey('m:42', random_bytes(32)));
        // Otra etiqueta (la de los canarios) da otro valor: no se cruzan dominios.
        $this->assertNotSame(SubjectRef::withKey('m:42', $clave), hash_hmac('sha256', 'm:42', hash_hmac('sha256', 'ultron-canary-phone/1', $clave, true)));
        foreach (['m:0', 'm:042', 'x:1', "m:1\n", '', 'm:'] as $mala) {
            try {
                SubjectRef::withKey($mala, $clave);
                $this->fail('Una clave sin forma no puede tener seudónimo.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_la_foto_es_reproducible_serializable_y_sin_datos_personales(): void
    {
        config(['commercial.events_enabled' => true]);
        [$claves] = $this->mundo(4);
        $una = $this->snap($claves, self::NOW);
        $otra = $this->snap($claves, self::NOW);
        $this->assertSame($una->canonicalJson(), $otra->canonicalJson());
        $this->assertSame($una->hash(), $otra->hash());
        $this->assertNotSame($una->hash(), $this->snap($claves, '2026-11-10 15:00:00')->hash());

        $vuelta = BatchSnapshot::fromArray(json_decode($una->canonicalJson(), true));
        $this->assertSame($una->hash(), $vuelta->hash());

        foreach (['Socio 1', '@facts.test', 'DOC', '30010', '+57', 'TEXTO-PRIVADO', '1990-05-05'] as $privado) {
            $this->assertStringNotContainsString($privado, $una->canonicalJson());
        }

        $datos = json_decode($una->canonicalJson(), true);
        $malas = [
            'un hecho fuera del catálogo' => fn (array $d) => $this->with($d, $claves[0], 'facts', ['subject.phone' => '3001234567']),
            'un teléfono en un enumerado' => fn (array $d) => $this->with($d, $claves[0], 'facts', ['lead.temperature' => '300 123 4567']),
            'un decimal en un entero' => fn (array $d) => $this->with($d, $claves[0], 'facts', ['attendance.count_last_30d' => 1.5]),
            'un nulo donde no cabe' => fn (array $d) => $this->with($d, $claves[0], 'facts', ['subject.is_member' => null]),
            'una política inventada' => fn (array $d) => $this->with($d, $claves[0], 'policy', ['consent.sms.commercial' => true]),
            'el día que no es el de Bogotá' => fn (array $d) => ['day' => '2026-11-10'] + $d,
        ];
        foreach ($malas as $caso => $mutar) {
            try {
                BatchSnapshot::fromArray($mutar($datos));
                $this->fail("Admitió {$caso}.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_una_consulta_por_fuente_para_n_personas(): void
    {
        config(['commercial.events_enabled' => true]);
        // Dos lotes con la misma FORMA (socios y prospectos sueltos) y distinto tamaño: 2 y 30 personas.
        [$una] = $this->mundo(2);
        [$veinticinco] = $this->mundo(30);

        $contar = function (array $claves, ?FactSelection $sel = null): int {
            $n = 0;
            DB::listen(function () use (&$n): void {
                $n++;
            });
            (new BatchSnapshotBuilder)->build($claves, self::at(self::NOW), $sel);
            DB::getEventDispatcher()->forget(QueryExecuted::class);

            return $n;
        };
        // Saber si existen las tablas del H9 (`Schema::hasTable`) se paga UNA VEZ POR PROCESO, no por lote: la primera foto del
        // proceso lo detecta y las demás no vuelven al catálogo.
        ContactHistoryFacts::forgetOutreachSource();
        $fria = $contar($una);
        $total1 = $contar($una);
        $total25 = $contar($veinticinco);
        $this->assertGreaterThan($total1, $fria, 'La detección de la fuente del H9 no se memoriza entre lotes.');
        $this->assertSame($total1, $total25, "La foto completa hace {$total1} consultas con 3 claves y {$total25} con 45: hay un N+1.");
        // 38 consultas de la foto más UNA por los resultados del H9 (`outreach_outcomes`, DB9): 39 en los dos motores, ninguna por
        // persona (antes de DB9 también eran 39: una era el `hasTable` que cortaba en la primera tabla ausente). El tope queda en 40.
        $this->assertLessThanOrEqual(40, $total25, "La foto completa de un lote hace {$total25} consultas.");
        // Solo membresía: los demás adaptadores no consultan nada.
        $soloMembresia = $contar($veinticinco, self::only(MembershipFacts::FACTS));
        $this->assertLessThan($total25, $soloMembresia);
        $this->assertLessThanOrEqual(8, $soloMembresia, "Solo membresía hace {$soloMembresia} consultas.");
        fwrite(STDERR, "\n[B9-facts] consultas por lote: completa={$total25} (3 y 45 claves; la primera del proceso, {$fria}), solo membresía={$soloMembresia}\n");
    }

    public function test_la_foto_alimenta_el_evaluador_y_la_escalera_reales_del_nucleo(): void
    {
        $vence5 = $this->socio(['membership_end_date' => '2026-11-14']);
        $vence9 = $this->socio(['membership_end_date' => '2026-11-18']);
        $sinFecha = $this->socio(['membership_end_date' => null]);
        $condicion = CanonicalCondition::parse(['all' => [
            ['fact' => 'membership.active', 'op' => 'eq', 'value' => true],
            ['fact' => 'membership.days_to_expiry', 'op' => 'between', 'value' => ['min' => 0, 'max' => 7, 'minInclusive' => true, 'maxInclusive' => true]],
            ['fact' => 'contact.count', 'args' => ['within' => ['n' => 7, 'unit' => 'days']], 'op' => 'lte', 'value' => 0],
        ]]);
        $sel = CoreProjection::selectionFor($condicion->uses());
        $this->assertTrue($sel->contacts);
        $claves = array_map(fn ($s) => SubjectKey::member($s['member']), [$vence5, $vence9, $sinFecha]);
        $foto = $this->snap($claves, self::NOW, $sel);

        $valores = array_map(fn (string $k) => ConditionEvaluator::evaluate($condicion, CoreProjection::snapshot($foto->subject($k)), self::at(self::NOW))->value, $claves);
        $this->assertSame([true, false, null], $valores);   // 5 días: sí · 9: no · sin fecha: desconocido
        $this->assertSame(
            ConditionEvaluator::evaluate($condicion, CoreProjection::snapshot($foto->subject($claves[0])), self::at(self::NOW))->trace,
            ConditionEvaluator::evaluate($condicion, CoreProjection::snapshot($this->snap($claves, self::NOW, $sel)->subject($claves[0])), self::at(self::NOW))->trace,
        );

        // La escalera: la base del fixture de TEST9-a con las entradas de persona de la foto.
        $base = json_decode((string) file_get_contents(base_path('tests/fixtures/outreach/policy-ladder-cases.json')), true)['baseInput'];
        $hermano = $this->lead(['phone' => '3007770001', 'do_not_contact' => true]);
        $yo = $this->lead(['phone' => '573007770001']);
        $this->conversation($yo, ['last_inbound_at' => self::utc('2026-11-09 14:00:00')]);
        $limpio = $this->lead(['phone' => '3007770002']);
        $this->conversation($limpio, ['last_inbound_at' => self::utc('2026-11-09 14:00:00')]);
        $pol = $this->snap([SubjectKey::lead($yo), SubjectKey::lead($limpio)], self::NOW, CoreProjection::selectionFor([]));
        $decidir = fn (string $k) => PolicyLadder::decide(array_replace_recursive($base, CoreProjection::policyInput($pol->subject($k), 'commercial', $pol->global)));
        $this->assertContains('do_not_contact_sibling', $decidir(SubjectKey::lead($yo))['reasons']);
        $limpia = $decidir(SubjectKey::lead($limpio));
        $this->assertNotContains('do_not_contact_sibling', $limpia['reasons']);
        $this->assertNotContains('no_consent_for_purpose_channel', $limpia['reasons']);
        $this->assertNotContains('ultron_abort_latch', $limpia['reasons']);
        $this->assertSame([], array_values(array_intersect(['unknown_subject', 'do_not_contact', 'consent_denied', 'commercial_opt_out'], $limpia['reasons'])));
        $this->assertNotNull($hermano);

        // Sin la lista de contactos la política no se calcula «a ojo».
        $this->expectException(InvalidArgumentException::class);
        CoreProjection::policyInput($this->snap([SubjectKey::lead($limpio)], self::NOW, new FactSelection([], [], false, true, true))->subject(SubjectKey::lead($limpio)), 'commercial', null);
    }

    /**
     * Un mundo variado de N personas (socio, prospecto, socio con prospecto).
     *
     * @return array{0: list<string>}
     */
    private function mundo(int $n): array
    {
        $claves = [];
        $t = $this->trainer();
        $clase = $this->klass();
        for ($i = 0; $i < $n; $i++) {
            $s = $this->socio(['membership_end_date' => '2026-11-'.str_pad((string) (10 + $i % 15), 2, '0', STR_PAD_LEFT)]);
            $lead = $this->lead(['member_id' => $i % 2 === 0 ? $s['member'] : null]);
            $conv = $this->conversation($lead, ['last_inbound_at' => self::utc('2026-11-08 15:00:00')]);
            $this->aiMessage($conv, '2026-11-07 15:00:00');
            $this->entry($s['user'], '2026-11-0'.(1 + $i % 8).' 15:00:00');
            $this->receivable($s['member'], '2026-10-0'.(1 + $i % 9));
            $this->tx(['member_id' => $s['member']]);
            $this->reservation($clase, $s['member'], '2026-11-11');
            $this->valuation($s['member'], $t, 'completed', '2026-10-01 15:00:00');
            $this->commercialEvent('human_requested', '2026-11-01 15:00:00', $s['member']);
            $this->opportunity(['member_id' => $s['member']]);
            $this->voiceContact('member', $s['member'], '+5730088'.str_pad((string) ($this->semillaVoz++), 5, '0', STR_PAD_LEFT));
            $claves[] = SubjectKey::member($s['member']);
            if ($i % 2 === 1) {
                $claves[] = SubjectKey::lead($lead);
            }
        }

        return [$claves];
    }

    private int $semillaVoz = 1;

    /**
     * @param  array<string, mixed>  $d
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function with(array $d, string $clave, string $parte, array $extra): array
    {
        $d['subjects'][$clave][$parte] = $extra + ($d['subjects'][$clave][$parte] ?? []);

        return $d;
    }
}

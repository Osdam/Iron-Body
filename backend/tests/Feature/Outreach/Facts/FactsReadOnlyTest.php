<?php

namespace Tests\Feature\Outreach\Facts;

use App\Services\Outreach\Facts\AttendanceFacts;
use App\Services\Outreach\Facts\BatchSnapshotBuilder;
use App\Services\Outreach\Facts\CanaryFacts;
use App\Services\Outreach\Facts\CatalogDomains;
use App\Services\Outreach\Facts\ClassFacts;
use App\Services\Outreach\Facts\ContactHistoryFacts;
use App\Services\Outreach\Facts\EngineFacts;
use App\Services\Outreach\Facts\LeadFacts;
use App\Services\Outreach\Facts\MembershipFacts;
use App\Services\Outreach\Facts\OperationalBrakes;
use App\Services\Outreach\Facts\PaymentFacts;
use App\Services\Outreach\Facts\ReceivableFacts;
use App\Services\Outreach\Facts\SubjectKey;
use App\Services\Outreach\Facts\SubjectResolver;
use App\Services\Outreach\Facts\SubjectTimeline;
use App\Services\Outreach\Facts\ValuationFacts;
use App\Services\Outreach\Facts\VoiceConsentFacts;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Outreach\Facts\Support\FactsWorld;
use Tests\TestCase;

/**
 * B9-facts · SOLO LECTURA (contrato H9, D9-2 «cada fichero solo hace select»,
 * §6.3, §9.3 «ninguna escritura del H9 llega a `commercial_*`», T3). Un
 * escuchador de consultas ve TODO lo que ejecuta cada adaptador, cada
 * prefiltro, la foto completa, el historial y los frenos: ni una sentencia que
 * no sea de lectura (tampoco `FOR UPDATE`), ninguna fila cambia en las tablas
 * que se leen, y cada fichero toca SOLO sus tablas enumeradas.
 */
class FactsReadOnlyTest extends TestCase
{
    use FactsWorld;
    use RefreshDatabase;

    private const NOW = '2026-11-09 15:00:00';

    /** Lo que se puede leer del catálogo de la base al preguntar si una tabla existe. */
    private const SCHEMA_TABLES = ['sqlite_master', 'sqlite_schema', 'information_schema', 'pg_catalog', 'pg_class', 'pg_namespace', 'pg_tables'];

    public function test_ningun_adaptador_ni_prefiltro_escribe_y_cada_uno_toca_solo_sus_tablas(): void
    {
        config(['commercial.events_enabled' => true]);
        $claves = $this->sembrar();
        $antes = $this->conteos();

        $resolutor = SubjectResolver::TABLES;
        $compartidas = ['marketing_leads', 'members', 'membership_subscriptions'];
        $casos = [
            'SubjectResolver' => [fn () => (new SubjectResolver)->forKeys($claves), $resolutor],
            'MembershipFacts' => [fn () => (new MembershipFacts)->facts($this->pass($claves, self::NOW)), [...MembershipFacts::TABLES, ...$resolutor]],
            'MembershipFacts::renewedSince' => [fn () => (new MembershipFacts)->renewedSince($this->pass($claves, self::NOW), self::at('2026-11-01 00:00:00')), [...MembershipFacts::TABLES, ...$resolutor]],
            'MembershipFacts::prefiltros' => [fn () => [(new MembershipFacts)->endingBetween(self::at(self::NOW), 0, 30), (new MembershipFacts)->endedBetween(self::at(self::NOW), 1, 30)], [...MembershipFacts::TABLES, ...$resolutor]],
            'AttendanceFacts' => [fn () => [(new AttendanceFacts)->facts($this->pass($claves, self::NOW)), (new AttendanceFacts)->inactiveSince(self::at(self::NOW), 7)], [...AttendanceFacts::TABLES, ...$resolutor]],
            'PaymentFacts' => [fn () => [(new PaymentFacts)->facts($this->pass($claves, self::NOW)), (new PaymentFacts)->abandonedLinks(self::at(self::NOW), 1)], [...PaymentFacts::TABLES, ...$resolutor]],
            'ReceivableFacts' => [fn () => [(new ReceivableFacts)->facts($this->pass($claves, self::NOW)), (new ReceivableFacts)->overdueBetween(self::at(self::NOW), 1, 365)], [...ReceivableFacts::TABLES, ...$resolutor]],
            'LeadFacts' => [fn () => [(new LeadFacts)->facts($this->pass($claves, self::NOW)), (new LeadFacts)->followupsDue(self::at(self::NOW))], [...LeadFacts::TABLES, ...$resolutor]],
            'ClassFacts' => [fn () => [(new ClassFacts)->facts($this->pass($claves, self::NOW)), (new ClassFacts)->upcomingWithCapacity(self::at(self::NOW), 14),
                (new ClassFacts)->candidatesForOccurrence(self::at(self::NOW), 1, '2026-11-11', null)], [...ClassFacts::TABLES, ...$resolutor]],
            'ValuationFacts' => [fn () => [(new ValuationFacts)->facts($this->pass($claves, self::NOW)), (new ValuationFacts)->outdated(self::at(self::NOW), 1)], [...ValuationFacts::TABLES, ...$resolutor]],
            'VoiceConsentFacts' => [fn () => (new VoiceConsentFacts)->facts($this->pass($claves, self::NOW)), [...VoiceConsentFacts::TABLES, ...$resolutor]],
            'EngineFacts' => [fn () => [(new EngineFacts)->facts($this->pass($claves, self::NOW)), (new EngineFacts)->events($this->pass($claves, self::NOW), ['human_requested', 'payment_approved'])], [...EngineFacts::TABLES, ...$resolutor]],
            'ContactHistoryFacts' => [fn () => (new ContactHistoryFacts)->contacts($this->pass($claves, self::NOW)), [...ContactHistoryFacts::TABLES, ...$resolutor]],
            'CanaryFacts' => [fn () => (new CanaryFacts)->facts($this->pass($claves, self::NOW)), [...CanaryFacts::TABLES, ...$resolutor, ...$compartidas]],
            'OperationalBrakes' => [fn () => (new OperationalBrakes)->read(), OperationalBrakes::TABLES],
            'CatalogDomains' => [fn () => (new CatalogDomains)->read(), CatalogDomains::TABLES],
            'SubjectTimeline' => [fn () => (new SubjectTimeline)->forSubject($claves[0]), SubjectTimeline::TABLES],
            'BatchSnapshotBuilder' => [fn () => (new BatchSnapshotBuilder)->build($claves, self::at(self::NOW)), null],
        ];

        $todas = [];
        foreach ($casos as $nombre => [$correr, $permitidas]) {
            $sql = $this->capture($correr);
            $this->assertNotSame([], $sql, "{$nombre}: el escuchador no vio ninguna consulta (la prueba no probaría nada).");
            foreach ($sql as $s) {
                $this->assertMatchesRegularExpression('/^\s*(select|pragma)\b/i', $s, "{$nombre} ejecutó algo que no es una lectura: {$s}");
                $this->assertDoesNotMatchRegularExpression('/\bfor\s+(update|share|no\s+key\s+update|key\s+share)\b/i', $s, "{$nombre} bloquea filas: {$s}");
                $todas[] = $s;
            }
            if ($permitidas !== null) {
                $tocadas = array_diff($this->tables($sql), self::SCHEMA_TABLES);
                $this->assertSame([], array_values(array_diff($tocadas, $permitidas)), "{$nombre} tocó tablas fuera de su lista cerrada.");
            }
        }

        $this->assertSame($antes, $this->conteos(), 'Alguna tabla leída cambió de filas.');
        // Ni el motor comercial ni el mercadeo: nada que se pueda leer como una escritura en commercial_* o marketing_*.
        foreach ($todas as $s) {
            $this->assertDoesNotMatchRegularExpression('/\b(insert\s+into|update|delete\s+from)\s+"?(commercial_|marketing_|users|payments|members)/i', $s);
        }
        fwrite(STDERR, "\n[B9-facts] solo lectura: ".count($todas)." sentencias revisadas, 0 escrituras\n");
    }

    /** @return list<string> */
    private function capture(Closure $correr): array
    {
        $sql = [];
        DB::listen(function (QueryExecuted $q) use (&$sql): void {
            $sql[] = $q->sql;
        });
        $correr();
        DB::getEventDispatcher()->forget(QueryExecuted::class);

        return $sql;
    }

    /**
     * Las tablas que nombra cada sentencia (`from`/`join` con comillas, las de los dos motores).
     *
     * @param  list<string>  $sql
     * @return list<string>
     */
    private function tables(array $sql): array
    {
        $tablas = [];
        foreach ($sql as $s) {
            // Con el esquema delante (`"main"."sqlite_master"`), la tabla es el último tramo.
            preg_match_all('/\b(?:from|join)\s+(?:"?[a-z_][a-z0-9_]*"?\.)?"?([a-z_][a-z0-9_]*)"?/i', $s, $m);
            foreach ($m[1] as $t) {
                $tablas[strtolower($t)] = true;
            }
        }
        $lista = array_keys($tablas);
        sort($lista);

        return $lista;
    }

    /** @return array<string, int> */
    private function conteos(): array
    {
        $c = [];
        foreach (['users', 'members', 'plans', 'payments', 'payment_transactions', 'membership_subscriptions', 'attendances', 'receivables',
            'receivable_payments', 'marketing_leads', 'marketing_conversations', 'marketing_messages', 'marketing_followups', 'marketing_ai_actions',
            'commercial_events', 'commercial_opportunities', 'commercial_alerts', 'commercial_segments', 'classes', 'class_reservations', 'class_attendances',
            'class_sessions', 'valuation_appointments', 'physical_evaluations', 'voice_contacts', 'voice_consents', 'voice_exclusions', 'voice_rne_checks',
            'voice_calls', 'voice_brakes', 'ultron_studio_canary_numbers', 'ultron_aborts', 'marketing_agent_settings', 'automation_events', 'identities'] as $t) {
            $c[$t] = Schema::hasTable($t) ? DB::table($t)->count() : -1;
        }

        return $c;
    }

    /** @return list<string> */
    private function sembrar(): array
    {
        $t = $this->trainer();
        $clase = $this->klass();
        $plan = $this->plan();
        $claves = [];
        foreach ([0, 1, 2] as $i) {
            $s = $this->socio(['membership_end_date' => '2026-11-1'.$i]);
            $lead = $this->lead(['member_id' => $i === 1 ? null : $s['member'], 'phone' => '30066600'.$i.$i, 'do_not_contact' => $i === 2]);
            $conv = $this->conversation($lead, ['last_inbound_at' => self::utc('2026-11-08 15:00:00')]);
            $this->aiMessage($conv, '2026-11-07 15:00:00');
            $this->entry($s['user'], '2026-10-0'.(1 + $i).' 15:00:00');
            $this->receivable($s['member'], '2026-10-0'.(1 + $i));
            $this->tx(['member_id' => $s['member'], 'created_at' => self::utc('2026-11-09 10:00:00')]);
            $this->reservation($clase, $s['member'], '2026-11-11');
            $this->valuation($s['member'], $t, 'completed', '2026-10-01 15:00:00');
            $this->commercialEvent('payment_approved', '2026-11-01 15:00:00', $s['member']);
            $this->opportunity(['member_id' => $s['member']]);
            $v = $this->voiceContact('member', $s['member'], '+57300666000'.$i);
            $this->voiceConsent($v['id'], 'granted', 2, '2026-11-01 12:00:00');
            DB::table('payments')->insert(['user_id' => $s['user'], 'plan_id' => $plan, 'amount' => 1, 'status' => 'paid', 'paid_at' => self::utc('2026-11-05 12:00'),
                'created_at' => self::utc('2026-11-05 12:00'), 'updated_at' => self::utc('2026-11-05 12:00')]);
            DB::table('marketing_followups')->insert(['lead_id' => $lead, 'type' => 'message', 'status' => 'pending', 'due_at' => self::utc('2026-11-09 10:00'),
                'created_at' => self::utc('2026-11-01 12:00'), 'updated_at' => self::utc('2026-11-01 12:00')]);
            $claves[] = SubjectKey::member($s['member']);
            if ($i === 1) {
                $claves[] = SubjectKey::lead($lead);
            }
        }
        $this->canary('+573006660000');
        $claves[] = 'm:999999';

        return $claves;
    }
}
